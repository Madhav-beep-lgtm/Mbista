<?php
declare(strict_types=1);

/**
 * Payroll engine: Nepal-style monthly payroll on top of the existing
 * double-entry voucher engine.
 *
 * Design rules:
 * - No tax amounts live in code. Slabs, retirement-deduction limits and
 *   rounding come from the published payroll_tax_versions / payroll_tax_slabs
 *   row selected for the run's fiscal year (effective-dated, versioned,
 *   immutable once published).
 * - Every calculated line stores a full JSON trace (components, annualization,
 *   slab-by-slab tax, limits applied, version id) so historic payroll does not
 *   change when configuration changes later.
 * - Accrual and payment vouchers post through create_voucher_with_entries()
 *   with source_type payroll_run / payroll_payment, so the vouchers UNIQUE
 *   (source_type, source_id) key keeps posting idempotent.
 */

function payroll_settings(int $companyId): array
{
    $stmt = db()->prepare('SELECT * FROM payroll_settings WHERE company_id = :cid LIMIT 1');
    $stmt->execute(['cid' => $companyId]);
    $row = $stmt->fetch();
    if (!$row) {
        db()->prepare('INSERT INTO payroll_settings (company_id) VALUES (:cid)')->execute(['cid' => $companyId]);
        $stmt->execute(['cid' => $companyId]);
        $row = $stmt->fetch();
    }
    return (array) $row;
}

/**
 * Put the auto-created payroll ledgers (by code) under classification-correct
 * groups, so they land on the right statement: expenses under an
 * indirect-expense group, statutory withholdings under Duties & Taxes, net
 * salary under Employee Payables, advances under a NON-cash current-asset
 * group. A misgrouped expense sits on the balance sheet and unbalances it; an
 * advance inside a cash/bank group corrupts the cash flow statement.
 * Idempotent — safe from both the seeder and the self-repair.
 */
function payroll_fix_ledger_groups(int $companyId): void
{
    $findGroup = static function (string $masterKey, array $nameLikes, bool $allowCashBank = true) use ($companyId): int {
        foreach ($nameLikes as $like) {
            $stmt = db()->prepare('SELECT id FROM ledger_groups WHERE company_id = :cid AND master_key = :mk AND is_active = 1'
                . ($allowCashBank ? '' : ' AND is_cash_or_bank = 0')
                . ' AND LOWER(name) LIKE :like ORDER BY id ASC LIMIT 1');
            $stmt->execute(['cid' => $companyId, 'mk' => $masterKey, 'like' => $like]);
            $id = (int) ($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }
        return 0;
    };
    $ensureGroup = static function (string $masterKey, string $code, string $name, array $nameLikes, bool $allowCashBank = true) use ($companyId, $findGroup): int {
        $id = $findGroup($masterKey, $nameLikes, $allowCashBank);
        if ($id > 0) {
            return $id;
        }
        db()->prepare('INSERT INTO ledger_groups (company_id, master_key, code, name, is_cash_or_bank, is_system, is_active)
                VALUES (:cid, :mk, :code, :name, 0, 0, 1)')
            ->execute(['cid' => $companyId, 'mk' => $masterKey, 'code' => $code, 'name' => $name]);
        return (int) db()->lastInsertId();
    };

    $targets = [
        // code => [target group resolver, list of group masters considered already-correct]
        'SSF-EXP' => [
            static fn (): int => $ensureGroup('indirect_expense', 'EMP_BENEFIT_EXP', 'Operating/Employee Benefit Expenses', ['%employee%', '%benefit%', '%operating%', '%admin%']),
            ['direct_expense', 'indirect_expense'],
        ],
        'SAL-EXP' => [
            static fn (): int => $ensureGroup('indirect_expense', 'EMP_BENEFIT_EXP', 'Operating/Employee Benefit Expenses', ['%employee%', '%benefit%', '%operating%', '%admin%']),
            ['direct_expense', 'indirect_expense'],
        ],
        'TDS-PAY' => [
            static fn (): int => $ensureGroup('current_liability', 'DUTIES_TAXES', 'Duties and Taxes', ['%duties%', '%tax%']),
            [],
        ],
        'RET-PAY' => [
            static fn (): int => $ensureGroup('current_liability', 'DUTIES_TAXES', 'Duties and Taxes', ['%duties%', '%tax%']),
            [],
        ],
        'SAL-PAY' => [
            static fn (): int => $ensureGroup('current_liability', 'EMP_PAYABLE', 'Employee Payables', ['%employee%payable%', '%payroll%', '%salary%payable%']),
            [],
        ],
        'EMP-ADV' => [
            static fn (): int => $ensureGroup('current_asset', 'LOANS_ADV', 'Loans and Advances', ['%loan%', '%advance%'], false),
            [],
        ],
    ];

    foreach ($targets as $ledgerCode => [$resolveTarget, $okMasters]) {
        $stmt = db()->prepare('SELECT l.id, lg.master_key, COALESCE(lg.is_cash_or_bank, 0) AS is_cash_or_bank, lg.code AS group_code
            FROM ledgers l LEFT JOIN ledger_groups lg ON lg.id = l.group_id
            WHERE l.company_id = :cid AND l.code = :code LIMIT 1');
        $stmt->execute(['cid' => $companyId, 'code' => $ledgerCode]);
        $ledger = $stmt->fetch();
        if (!$ledger) {
            continue;
        }
        // Leave a deliberate, sane placement alone: expenses already under an
        // expense master stay; liabilities/advances move only out of known-bad
        // spots (Trade Payables, cash/bank groups, wrong master, no group).
        $master = (string) ($ledger['master_key'] ?? '');
        $isBad = match ($ledgerCode) {
            'SSF-EXP', 'SAL-EXP' => !in_array($master, $okMasters, true),
            'TDS-PAY', 'RET-PAY', 'SAL-PAY' => $master !== 'current_liability' && $master !== 'non_current_liability'
                || (string) $ledger['group_code'] === 'PAYABLE',
            'EMP-ADV' => !in_array($master, ['current_asset', 'non_current_asset'], true) || (int) $ledger['is_cash_or_bank'] === 1,
            default => false,
        };
        if ($isBad) {
            db()->prepare('UPDATE ledgers SET group_id = :gid WHERE id = :id')
                ->execute(['gid' => $resolveTarget(), 'id' => (int) $ledger['id']]);
        }
    }
}

/**
 * Published tax version for the fiscal year (latest effective first).
 * Income tax slabs are national statute, so a company without its own
 * published version inherits any company's published version whose fiscal
 * year covers the same period; a company-specific version always wins.
 */
function payroll_active_tax_version(int $companyId, int $fiscalYearId): ?array
{
    $stmt = db()->prepare("SELECT * FROM payroll_tax_versions
        WHERE company_id = :cid AND fiscal_year_id = :fy AND status = 'published'
        ORDER BY effective_from DESC, id DESC LIMIT 1");
    $stmt->execute(['cid' => $companyId, 'fy' => $fiscalYearId]);
    $own = $stmt->fetch();
    if ($own) {
        return (array) $own;
    }
    $stmt = db()->prepare("SELECT tv.* FROM payroll_tax_versions tv
        INNER JOIN fiscal_years own_fy ON own_fy.id = :fy
        INNER JOIN fiscal_years their_fy ON their_fy.id = tv.fiscal_year_id
        WHERE tv.status = 'published'
          AND their_fy.start_date = own_fy.start_date AND their_fy.end_date = own_fy.end_date
        ORDER BY tv.effective_from DESC, tv.id DESC LIMIT 1");
    $stmt->execute(['fy' => $fiscalYearId]);
    return $stmt->fetch() ?: null;
}

function payroll_tax_slabs(int $versionId, string $category): array
{
    $stmt = db()->prepare('SELECT lower_bound, upper_bound, rate FROM payroll_tax_slabs
        WHERE version_id = :vid AND category = :cat ORDER BY sort_order ASC, lower_bound ASC');
    $stmt->execute(['vid' => $versionId, 'cat' => $category]);
    return $stmt->fetchAll();
}

/**
 * Progressive slab tax on an annual taxable amount.
 * Returns [totalTax, [['band' => '0 - 500,000', 'rate' => 1.0, 'amount' => x, 'tax' => y], ...]].
 * $firstSlabExempt zeroes the first band's rate (SSF contributors' social
 * security tax exemption, when the version enables it).
 */
function payroll_slab_tax(float $taxableAnnual, array $slabs, bool $firstSlabExempt = false): array
{
    $tax = 0.0;
    $breakdown = [];
    foreach (array_values($slabs) as $index => $slab) {
        $lower = (float) $slab['lower_bound'];
        $upper = $slab['upper_bound'] !== null ? (float) $slab['upper_bound'] : null;
        if ($taxableAnnual <= $lower) {
            break;
        }
        $portion = ($upper === null ? $taxableAnnual : min($taxableAnnual, $upper)) - $lower;
        if ($portion <= 0) {
            continue;
        }
        $rate = (float) $slab['rate'];
        if ($index === 0 && $firstSlabExempt) {
            $rate = 0.0;
        }
        $slabTax = $portion * $rate / 100;
        $tax += $slabTax;
        $breakdown[] = [
            'band' => number_format($lower) . ' - ' . ($upper === null ? 'above' : number_format($upper)),
            'rate' => $rate,
            'amount' => round($portion, 2),
            'tax' => round($slabTax, 2),
        ];
    }
    return [round($tax, 2), $breakdown];
}

function payroll_round(float $amount, string $mode): float
{
    return match ($mode) {
        'down' => floor($amount),
        'nearest' => round($amount),
        default => round($amount, 2),
    };
}

/** Active payroll employees with user names for a company. */
function payroll_company_employees(int $companyId, bool $activeOnly = true): array
{
    $sql = 'SELECT pe.*, u.name AS person_name, u.email AS person_email, u.role AS person_role
        FROM payroll_employees pe INNER JOIN users u ON u.id = pe.user_id
        WHERE pe.company_id = :cid' . ($activeOnly ? " AND pe.status = 'active'" : '') . '
        ORDER BY pe.employee_code ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute(['cid' => $companyId]);
    return $stmt->fetchAll();
}

/** Component amounts for one employee: assigned overrides, else defaults. */
function payroll_employee_component_lines(array $employee): array
{
    $stmt = db()->prepare("SELECT c.*, pec.amount AS override_amount
        FROM payroll_components c
        LEFT JOIN payroll_employee_components pec
               ON pec.component_id = c.id AND pec.payroll_employee_id = :pe
        WHERE c.company_id = :cid AND c.active = 1
        ORDER BY c.sort_order ASC, c.code ASC");
    $stmt->execute(['pe' => (int) $employee['id'], 'cid' => (int) $employee['company_id']]);
    $lines = [];
    foreach ($stmt->fetchAll() as $component) {
        $amount = $component['override_amount'] !== null
            ? (float) $component['override_amount']
            : ((string) $component['calc_type'] === 'percent_basic'
                ? round((float) $employee['basic_salary'] * (float) $component['default_value'] / 100, 2)
                : (float) $component['default_value']);
        if ($component['override_amount'] === null && (float) $component['default_value'] == 0.0) {
            continue; // no default and no assignment -> not part of this employee's pay
        }
        if ($amount == 0.0) {
            continue;
        }
        $lines[] = ['component' => $component, 'amount' => $amount];
    }
    return $lines;
}

/** Tax already withheld for this employee in earlier posted/paid runs of the FY. */
function payroll_tax_withheld_ytd(int $employeeId, int $fiscalYearId, int $excludeRunId = 0): float
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(l.tax_month), 0)
        FROM payroll_run_lines l INNER JOIN payroll_runs r ON r.id = l.run_id
        WHERE l.payroll_employee_id = :pe AND r.fiscal_year_id = :fy
          AND r.status IN ('approved', 'posted', 'paid') AND r.id <> :run");
    $stmt->execute(['pe' => $employeeId, 'fy' => $fiscalYearId, 'run' => $excludeRunId]);
    return (float) $stmt->fetchColumn();
}

/**
 * Calculate one employee's line for a run. Returns the values plus the full
 * trace; does not write anything.
 */
function payroll_calculate_line(array $employee, array $run, array $taxVersion): array
{
    $basic = round((float) $employee['basic_salary'], 2);
    $componentLines = payroll_employee_component_lines($employee);

    $allowances = 0.0;
    $overtime = 0.0;
    $benefits = 0.0;
    $otherDeduction = 0.0;
    $taxableMonthly = $basic; // basic salary is always assessable
    $traceComponents = [['label' => 'Basic Salary', 'category' => 'basic', 'amount' => $basic, 'taxable' => true]];

    foreach ($componentLines as $line) {
        $component = $line['component'];
        $amount = $line['amount'];
        $category = (string) $component['category'];
        if ($category === 'deduction' && !(int) $component['employer_paid']) {
            $otherDeduction += $amount;
        } elseif ($category === 'overtime') {
            $overtime += $amount;
        } elseif ($category === 'benefit') {
            $benefits += $amount;
        } else {
            $allowances += $amount;
        }
        if ((int) $component['taxable'] && $category !== 'deduction') {
            $taxableMonthly += $amount;
        }
        $traceComponents[] = [
            'label' => (string) $component['name'],
            'code' => (string) $component['code'],
            'category' => $category,
            'amount' => $amount,
            'taxable' => (bool) $component['taxable'],
            'employer_paid' => (bool) $component['employer_paid'],
        ];
    }

    $gross = round($basic + $allowances + $overtime + $benefits, 2);

    // Retirement contributions (monthly, % of basic per employee profile).
    $retEmployeeMonth = round($basic * (float) $employee['retirement_employee_rate'] / 100, 2);
    $retEmployerMonth = round($basic * (float) $employee['retirement_employer_rate'] / 100, 2);

    // Annualization: recurring monthly amounts x 12. Employer retirement
    // contribution is assessable under Nepali practice (added to remuneration),
    // then deductible within the configured retirement limit.
    $assessableAnnual = round(($taxableMonthly + $retEmployerMonth) * 12, 2);

    // Eligible retirement deduction: employee + employer contribution, capped
    // by the version's percent-of-assessable and absolute cap.
    $retirementAnnualContribution = round(($retEmployeeMonth + $retEmployerMonth) * 12, 2);
    $limitPct = round($assessableAnnual * (float) $taxVersion['retirement_limit_pct'] / 100, 2);
    $limitCap = (float) $taxVersion['retirement_limit_cap'];
    $retirementDeductionAnnual = (string) $employee['retirement_scheme'] === 'none'
        ? 0.0
        : min($retirementAnnualContribution, $limitPct, $limitCap);

    $taxableAnnual = max(0.0, round($assessableAnnual - $retirementDeductionAnnual, 2));

    $category = in_array((string) $employee['marital_status'], ['individual', 'couple'], true)
        ? (string) $employee['marital_status'] : 'individual';
    $slabs = payroll_tax_slabs((int) $taxVersion['id'], $category);
    $firstSlabExempt = (bool) $taxVersion['ssf_first_slab_exempt'] && (string) $employee['retirement_scheme'] === 'ssf';
    [$annualTax, $slabBreakdown] = payroll_slab_tax($taxableAnnual, $slabs, $firstSlabExempt);

    // Monthly withholding, regular method: remaining annual liability spread
    // over the remaining periods; the final period absorbs rounding drift.
    $periodNo = max(1, min(12, (int) $run['period_no']));
    $monthsRemaining = 12 - $periodNo + 1;
    $taxYtdBefore = payroll_tax_withheld_ytd((int) $employee['id'], (int) $run['fiscal_year_id'], (int) ($run['id'] ?? 0));
    $rounding = (string) $taxVersion['rounding'];
    $taxMonth = $periodNo >= 12
        ? max(0.0, round($annualTax - $taxYtdBefore, 2))
        : max(0.0, payroll_round(($annualTax - $taxYtdBefore) / $monthsRemaining, $rounding));

    // Advance/loan recovery: active loans, installment capped by balance.
    $advanceDeduction = 0.0;
    $loanPlan = [];
    $loanStmt = db()->prepare("SELECT * FROM payroll_loans WHERE payroll_employee_id = :pe AND status = 'active' AND balance > 0");
    $loanStmt->execute(['pe' => (int) $employee['id']]);
    foreach ($loanStmt->fetchAll() as $loan) {
        $recover = min((float) $loan['monthly_installment'], (float) $loan['balance']);
        if ($recover <= 0) {
            continue;
        }
        $advanceDeduction += $recover;
        $loanPlan[] = ['loan_id' => (int) $loan['id'], 'title' => (string) $loan['title'], 'amount' => round($recover, 2), 'balance_before' => (float) $loan['balance']];
    }
    $advanceDeduction = round($advanceDeduction, 2);

    $netPay = round($gross - $retEmployeeMonth - $taxMonth - $advanceDeduction - $otherDeduction, 2);

    $warnings = [];
    if ($netPay < 0) {
        $warnings[] = 'Negative net pay - reduce deductions or review salary.';
    }
    if ((string) ($employee['pan_no'] ?? '') === '') {
        $warnings[] = 'PAN missing.';
    }
    if ((string) ($employee['bank_account'] ?? '') === '') {
        $warnings[] = 'Bank account missing.';
    }

    return [
        'basic' => $basic,
        'allowances' => round($allowances, 2),
        'overtime' => round($overtime, 2),
        'benefits' => round($benefits, 2),
        'gross' => $gross,
        'assessable_annual' => $assessableAnnual,
        'retirement_deduction_annual' => round($retirementDeductionAnnual, 2),
        'taxable_annual' => $taxableAnnual,
        'annual_tax' => $annualTax,
        'tax_ytd_before' => round($taxYtdBefore, 2),
        'tax_month' => $taxMonth,
        'retirement_employee_month' => $retEmployeeMonth,
        'retirement_employer_month' => $retEmployerMonth,
        'advance_deduction' => $advanceDeduction,
        'other_deduction' => round($otherDeduction, 2),
        'net_pay' => $netPay,
        'line_status' => $netPay < 0 ? 'error' : ($warnings !== [] ? 'warning' : 'ok'),
        'warnings' => $warnings,
        'trace' => [
            'tax_version_id' => (int) $taxVersion['id'],
            'tax_version_label' => (string) $taxVersion['label'],
            'category' => $category,
            'components' => $traceComponents,
            'annualization' => 'monthly taxable ' . number_format($taxableMonthly, 2) . ' x 12 + employer retirement ' . number_format($retEmployerMonth, 2) . ' x 12',
            'retirement' => [
                'scheme' => (string) $employee['retirement_scheme'],
                'annual_contribution' => $retirementAnnualContribution,
                'limit_pct_amount' => $limitPct,
                'limit_cap' => $limitCap,
                'allowed' => round($retirementDeductionAnnual, 2),
            ],
            'slabs' => $slabBreakdown,
            'first_slab_exempt' => $firstSlabExempt,
            'withholding' => [
                'method' => $periodNo >= 12 ? 'final-period adjustment' : 'regular (remaining months)',
                'period_no' => $periodNo,
                'months_remaining' => $monthsRemaining,
                'ytd_withheld' => round($taxYtdBefore, 2),
            ],
            'loans' => $loanPlan,
        ],
    ];
}

/** (Re)calculate every line of a draft/calculated run inside a transaction. */
function payroll_calculate_run(int $runId): array
{
    $run = payroll_run($runId);
    if (!$run || !in_array((string) $run['status'], ['draft', 'calculated'], true)) {
        return ['ok' => false, 'error' => 'Run not found or already approved.'];
    }
    $taxVersion = payroll_active_tax_version((int) $run['company_id'], (int) $run['fiscal_year_id']);
    if (!$taxVersion) {
        return ['ok' => false, 'error' => 'No published tax configuration for this fiscal year. Publish one in Payroll Settings first.'];
    }
    $employees = payroll_company_employees((int) $run['company_id']);
    if ($employees === []) {
        return ['ok' => false, 'error' => 'No active payroll employees. Enrol staff/clients in Payroll Employees first.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM payroll_run_lines WHERE run_id = :run')->execute(['run' => $runId]);
        $insert = $pdo->prepare('INSERT INTO payroll_run_lines (
                run_id, payroll_employee_id, basic, allowances, overtime, benefits, gross,
                assessable_annual, retirement_deduction_annual, taxable_annual, annual_tax,
                tax_ytd_before, tax_month, retirement_employee_month, retirement_employer_month,
                advance_deduction, other_deduction, net_pay, trace, line_status, warnings
            ) VALUES (
                :run_id, :pe, :basic, :allowances, :overtime, :benefits, :gross,
                :assessable, :ret_ded, :taxable, :annual_tax,
                :ytd, :tax_month, :ret_emp, :ret_er,
                :advance, :other_ded, :net, :trace, :line_status, :warnings
            )');
        $totals = ['gross' => 0.0, 'tax' => 0.0, 'deductions' => 0.0, 'employer' => 0.0, 'net' => 0.0];
        foreach ($employees as $employee) {
            $calc = payroll_calculate_line($employee, $run, $taxVersion);
            $insert->execute([
                'run_id' => $runId,
                'pe' => (int) $employee['id'],
                'basic' => $calc['basic'],
                'allowances' => $calc['allowances'],
                'overtime' => $calc['overtime'],
                'benefits' => $calc['benefits'],
                'gross' => $calc['gross'],
                'assessable' => $calc['assessable_annual'],
                'ret_ded' => $calc['retirement_deduction_annual'],
                'taxable' => $calc['taxable_annual'],
                'annual_tax' => $calc['annual_tax'],
                'ytd' => $calc['tax_ytd_before'],
                'tax_month' => $calc['tax_month'],
                'ret_emp' => $calc['retirement_employee_month'],
                'ret_er' => $calc['retirement_employer_month'],
                'advance' => $calc['advance_deduction'],
                'other_ded' => $calc['other_deduction'],
                'net' => $calc['net_pay'],
                'trace' => json_encode($calc['trace'], JSON_UNESCAPED_UNICODE),
                'line_status' => $calc['line_status'],
                'warnings' => $calc['warnings'] === [] ? null : implode(' | ', $calc['warnings']),
            ]);
            $totals['gross'] += $calc['gross'];
            $totals['tax'] += $calc['tax_month'];
            $totals['deductions'] += $calc['retirement_employee_month'] + $calc['advance_deduction'] + $calc['other_deduction'];
            $totals['employer'] += $calc['retirement_employer_month'];
            $totals['net'] += $calc['net_pay'];
        }
        $pdo->prepare("UPDATE payroll_runs SET status = 'calculated', tax_version_id = :tv,
                total_gross = :g, total_tax = :t, total_deductions = :d,
                total_employer_contrib = :e, total_net = :n
            WHERE id = :id")
            ->execute([
                'tv' => (int) $taxVersion['id'],
                'g' => round($totals['gross'], 2), 't' => round($totals['tax'], 2),
                'd' => round($totals['deductions'], 2), 'e' => round($totals['employer'], 2),
                'n' => round($totals['net'], 2), 'id' => $runId,
            ]);
        $pdo->commit();
        return ['ok' => true, 'employees' => count($employees)];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $exception->getMessage()];
    }
}

function payroll_run(int $runId): ?array
{
    $stmt = db()->prepare('SELECT * FROM payroll_runs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $runId]);
    return $stmt->fetch() ?: null;
}

function payroll_run_lines(int $runId): array
{
    $stmt = db()->prepare('SELECT l.*, pe.employee_code, pe.department, pe.pan_no, pe.bank_account,
            pe.marital_status, pe.retirement_scheme, u.name AS person_name
        FROM payroll_run_lines l
        INNER JOIN payroll_employees pe ON pe.id = l.payroll_employee_id
        INNER JOIN users u ON u.id = pe.user_id
        WHERE l.run_id = :run ORDER BY pe.employee_code ASC');
    $stmt->execute(['run' => $runId]);
    return $stmt->fetchAll();
}

/**
 * Validation gate before approval. Returns a list of blocking errors
 * (empty = approvable) and non-blocking warnings.
 */
function payroll_validate_run(array $run, array $settings, int $approverId): array
{
    $errors = [];
    $warnings = [];
    if ((string) $run['status'] !== 'calculated') {
        $errors[] = 'Run must be calculated before approval.';
    }
    foreach ([
        'salary_expense_ledger_id' => 'Salary expense ledger',
        'salary_payable_ledger_id' => 'Salary payable ledger',
        'tds_payable_ledger_id' => 'Income tax (TDS) payable ledger',
    ] as $key => $label) {
        if ((int) ($settings[$key] ?? 0) <= 0) {
            $errors[] = $label . ' is not mapped in Payroll Settings.';
        }
    }
    $lines = payroll_run_lines((int) $run['id']);
    if ($lines === []) {
        $errors[] = 'Run has no calculated employee lines.';
    }
    $needRetLedger = false;
    $needAdvanceLedger = false;
    foreach ($lines as $line) {
        if ((float) $line['net_pay'] < 0) {
            $errors[] = $line['employee_code'] . ': negative net pay.';
        }
        if ((float) $line['retirement_employee_month'] > 0 || (float) $line['retirement_employer_month'] > 0) {
            $needRetLedger = true;
        }
        if ((float) $line['advance_deduction'] > 0) {
            $needAdvanceLedger = true;
        }
        if ((string) ($line['warnings'] ?? '') !== '') {
            $warnings[] = $line['employee_code'] . ': ' . $line['warnings'];
        }
    }
    if ($needRetLedger && (int) ($settings['retirement_payable_ledger_id'] ?? 0) <= 0) {
        $errors[] = 'Retirement fund payable ledger is not mapped (employees have retirement contributions).';
    }
    if ($needRetLedger && (int) ($settings['employer_contrib_expense_ledger_id'] ?? 0) <= 0) {
        $errors[] = 'Employer contribution expense ledger is not mapped.';
    }
    if ($needAdvanceLedger && (int) ($settings['advance_ledger_id'] ?? 0) <= 0) {
        $errors[] = 'Employee advance ledger is not mapped (advance recoveries in this run).';
    }
    if ((int) ($settings['enforce_sod'] ?? 0) === 1 && (int) $run['created_by'] === $approverId) {
        $errors[] = 'Segregation of duties: the preparer of this run cannot approve it.';
    }
    return ['errors' => $errors, 'warnings' => $warnings];
}

/** The accrual journal entries for a calculated run (preview + posting). */
function payroll_accrual_entries(array $run, array $settings): array
{
    $lines = payroll_run_lines((int) $run['id']);
    $sum = static fn (string $col): float => round(array_sum(array_map(static fn (array $l): float => (float) $l[$col], $lines)), 2);
    $gross = $sum('gross');
    $tax = $sum('tax_month');
    $retEmployee = $sum('retirement_employee_month');
    $retEmployer = $sum('retirement_employer_month');
    $advance = $sum('advance_deduction');
    $otherDeduction = $sum('other_deduction');
    $net = $sum('net_pay');

    $entries = [];
    $entries[] = ['ledger_id' => (int) $settings['salary_expense_ledger_id'], 'entry_type' => 'debit', 'amount' => $gross, 'memo' => 'Gross earnings'];
    if ($retEmployer > 0) {
        $entries[] = ['ledger_id' => (int) $settings['employer_contrib_expense_ledger_id'], 'entry_type' => 'debit', 'amount' => $retEmployer, 'memo' => 'Employer retirement contribution'];
    }
    if ($tax > 0) {
        $entries[] = ['ledger_id' => (int) $settings['tds_payable_ledger_id'], 'entry_type' => 'credit', 'amount' => $tax, 'memo' => 'Income tax (TDS) withheld'];
    }
    if ($retEmployee + $retEmployer > 0) {
        $entries[] = ['ledger_id' => (int) $settings['retirement_payable_ledger_id'], 'entry_type' => 'credit', 'amount' => round($retEmployee + $retEmployer, 2), 'memo' => 'Retirement fund payable (employee + employer)'];
    }
    if ($advance > 0) {
        $entries[] = ['ledger_id' => (int) $settings['advance_ledger_id'], 'entry_type' => 'credit', 'amount' => $advance, 'memo' => 'Employee advance recovered'];
    }
    if ($otherDeduction > 0) {
        // Other deductions stay in salary payable until remitted separately.
        $net = round($net + $otherDeduction, 2);
    }
    $entries[] = ['ledger_id' => (int) $settings['salary_payable_ledger_id'], 'entry_type' => 'credit', 'amount' => $net, 'memo' => 'Net salary payable'];
    return array_values(array_filter($entries, static fn (array $entry): bool => $entry['amount'] > 0.004));
}

/**
 * Approve a calculated run and (auto_post) post the balanced accrual voucher.
 * Returns ['ok' => bool, 'error' => ?, 'voucher_id' => ?].
 */
function payroll_approve_and_post(int $runId, int $approverId): array
{
    $run = payroll_run($runId);
    if (!$run) {
        return ['ok' => false, 'error' => 'Run not found.'];
    }
    $settings = payroll_settings((int) $run['company_id']);
    $validation = payroll_validate_run($run, $settings, $approverId);
    if ($validation['errors'] !== []) {
        return ['ok' => false, 'error' => implode(' ', $validation['errors'])];
    }

    $entries = payroll_accrual_entries($run, $settings);
    $debits = round(array_sum(array_map(static fn (array $entry): float => $entry['entry_type'] === 'debit' ? $entry['amount'] : 0, $entries)), 2);
    $credits = round(array_sum(array_map(static fn (array $entry): float => $entry['entry_type'] === 'credit' ? $entry['amount'] : 0, $entries)), 2);
    if (abs($debits - $credits) > 0.011) {
        return ['ok' => false, 'error' => 'Accrual journal does not balance (Dr ' . number_format($debits, 2) . ' vs Cr ' . number_format($credits, 2) . ').'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $voucherId = 0;
        if ((int) $settings['auto_post'] === 1) {
            $voucherId = create_voucher_with_entries([
                'company_id' => (int) $run['company_id'],
                'fiscal_year_id' => (int) $run['fiscal_year_id'],
                'voucher_no' => 'PAY-' . $run['period_label'] . '-' . $runId,
                'voucher_type' => 'journal',
                'voucher_date' => (string) ($run['pay_date'] ?? date('Y-m-d')),
                'source_type' => 'payroll_run',
                'source_id' => $runId,
                'total_amount' => $debits,
                'narration' => 'Payroll accrual for ' . $run['period_label'] . ' (' . count(payroll_run_lines($runId)) . ' employees).',
                'status' => 'posted',
                'posted_by' => $approverId,
            ], $entries);
        }

        // Advance recoveries reduce the loan balances now that the run is final.
        $recoveryLines = payroll_run_lines($runId);
        foreach ($recoveryLines as $line) {
            if ((float) $line['advance_deduction'] <= 0) {
                continue;
            }
            $trace = json_decode((string) $line['trace'], true) ?: [];
            foreach ((array) ($trace['loans'] ?? []) as $loanPlan) {
                $loanId = (int) ($loanPlan['loan_id'] ?? 0);
                $amount = (float) ($loanPlan['amount'] ?? 0);
                if ($loanId <= 0 || $amount <= 0) {
                    continue;
                }
                // SET clauses apply left to right, so the status check below
                // sees the already-reduced balance.
                $pdo->prepare("UPDATE payroll_loans SET balance = GREATEST(0, balance - :amt),
                        status = IF(balance <= 0.004, 'settled', status) WHERE id = :id")
                    ->execute(['amt' => $amount, 'id' => $loanId]);
                $pdo->prepare('INSERT INTO payroll_loan_txns (loan_id, run_id, txn_type, amount, txn_date, voucher_id, notes)
                        VALUES (:loan, :run, \'recovery\', :amt, :dt, :vid, :notes)')
                    ->execute([
                        'loan' => $loanId, 'run' => $runId, 'amt' => $amount,
                        'dt' => (string) ($run['pay_date'] ?? date('Y-m-d')),
                        'vid' => $voucherId ?: null,
                        'notes' => 'Recovered in payroll ' . $run['period_label'],
                    ]);
            }
        }

        $pdo->prepare("UPDATE payroll_runs SET status = :status, approved_by = :by, approved_at = NOW(),
                accrual_voucher_id = :vid, posted_at = IF(:vid2 > 0, NOW(), posted_at) WHERE id = :id")
            ->execute([
                'status' => $voucherId > 0 ? 'posted' : 'approved',
                'by' => $approverId, 'vid' => $voucherId ?: null, 'vid2' => $voucherId, 'id' => $runId,
            ]);
        $pdo->commit();
        return ['ok' => true, 'voucher_id' => $voucherId, 'warnings' => $validation['warnings']];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $exception->getMessage()];
    }
}

/** Salary payment voucher: Dr Salary Payable / Cr Bank. Marks the run paid. */
function payroll_record_payment(int $runId, int $userId, string $paymentDate = ''): array
{
    $run = payroll_run($runId);
    if (!$run || !in_array((string) $run['status'], ['approved', 'posted'], true)) {
        return ['ok' => false, 'error' => 'Only an approved/posted run can be paid.'];
    }
    $settings = payroll_settings((int) $run['company_id']);
    if ((int) ($settings['bank_ledger_id'] ?? 0) <= 0 || (int) ($settings['salary_payable_ledger_id'] ?? 0) <= 0) {
        return ['ok' => false, 'error' => 'Map the bank and salary payable ledgers in Payroll Settings first.'];
    }
    // Pay employees their net only; other-deduction balances remain in the
    // salary payable ledger until remitted to the third party separately.
    $lines = payroll_run_lines($runId);
    $amount = round(array_sum(array_map(static fn (array $l): float => (float) $l['net_pay'], $lines)), 2);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Nothing payable on this run.'];
    }
    $paymentDate = $paymentDate !== '' ? $paymentDate : date('Y-m-d');
    try {
        $voucherId = create_voucher_with_entries([
            'company_id' => (int) $run['company_id'],
            'fiscal_year_id' => (int) $run['fiscal_year_id'],
            'voucher_no' => 'PAYOUT-' . $run['period_label'] . '-' . $runId,
            'voucher_type' => 'payment',
            'voucher_date' => $paymentDate,
            'source_type' => 'payroll_payment',
            'source_id' => $runId,
            'total_amount' => $amount,
            'narration' => 'Salary payment for ' . $run['period_label'] . '.',
            'status' => 'posted',
            'posted_by' => $userId,
        ], [
            ['ledger_id' => (int) $settings['salary_payable_ledger_id'], 'entry_type' => 'debit', 'amount' => $amount, 'memo' => 'Salary payable settled'],
            ['ledger_id' => (int) $settings['bank_ledger_id'], 'entry_type' => 'credit', 'amount' => $amount, 'memo' => 'Bank payment'],
        ]);
        db()->prepare("UPDATE payroll_runs SET status = 'paid', payment_voucher_id = :vid, paid_at = NOW() WHERE id = :id")
            ->execute(['vid' => $voucherId, 'id' => $runId]);
        return ['ok' => true, 'voucher_id' => $voucherId, 'amount' => $amount];
    } catch (Throwable $exception) {
        return ['ok' => false, 'error' => (string) $exception->getCode() === '23000'
            ? 'This run already has a payment voucher.' : $exception->getMessage()];
    }
}
