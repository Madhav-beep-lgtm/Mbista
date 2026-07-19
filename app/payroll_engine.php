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

/**
 * Tax already withheld for this employee in EARLIER posted/paid runs of the FY.
 * The period bound is a no-op during normal forward processing (later periods
 * are not posted yet), but it is essential once a mid-year run is reopened for
 * correction and recalculated while later runs remain posted: without it, those
 * later runs' tax would pollute this run's year-to-date base and silently
 * under-withhold (or zero) the corrected period's TDS.
 */
function payroll_tax_withheld_ytd(int $employeeId, int $fiscalYearId, int $excludeRunId = 0, int $beforePeriodNo = 13): float
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(l.tax_month), 0)
        FROM payroll_run_lines l INNER JOIN payroll_runs r ON r.id = l.run_id
        WHERE l.payroll_employee_id = :pe AND r.fiscal_year_id = :fy
          AND r.status IN ('approved', 'posted', 'paid') AND r.id <> :run
          AND r.period_no < :period");
    $stmt->execute(['pe' => $employeeId, 'fy' => $fiscalYearId, 'run' => $excludeRunId, 'period' => $beforePeriodNo]);
    return (float) $stmt->fetchColumn();
}

/**
 * Calculate one employee's line for a run. Returns the values plus the full
 * trace; does not write anything.
 */
/**
 * Nepali (Bikram Sambat) month name for a payroll period. The fiscal year starts
 * in Shrawan, so period 1 = Shrawan … period 12 = Ashadh.
 */
function payroll_bs_month_name(int $periodNo): string
{
    $months = [
        1 => 'Shrawan', 2 => 'Bhadra', 3 => 'Ashwin', 4 => 'Kartik', 5 => 'Mangsir', 6 => 'Poush',
        7 => 'Magh', 8 => 'Falgun', 9 => 'Chaitra', 10 => 'Baisakh', 11 => 'Jestha', 12 => 'Ashadh',
    ];
    return $months[max(1, min(12, $periodNo))] ?? ('Month ' . $periodNo);
}

/**
 * The date a run's accrual voucher should post on.
 *
 * Priority: the explicit custom voucher_date, else the pay date, else today.
 * The salary EXPENSE is earned in the run's own fiscal year, but a last-period
 * run (Ashadh) is usually paid a few days into the NEXT year — so a raw pay
 * date can fall outside the run's fiscal year and the posting engine rightly
 * refuses it ("no fiscal year covers this date"), which is exactly why the
 * accrual silently failed to post. We therefore clamp the resolved date into
 * the run's fiscal-year range so the accrual always lands in the period it was
 * earned. (The cash PAYMENT voucher keeps its true pay date and may fall in the
 * next year — that is correct; only the accrual is anchored to its own year.)
 */
function payroll_run_voucher_date(array $run): string
{
    $date = trim((string) ($run['voucher_date'] ?? ''));
    if ($date === '') {
        $date = trim((string) ($run['pay_date'] ?? ''));
    }
    if ($date === '') {
        $date = date('Y-m-d');
    }
    $fyId = (int) ($run['fiscal_year_id'] ?? 0);
    if ($fyId > 0 && function_exists('fiscal_year_by_id')) {
        $fy = fiscal_year_by_id($fyId);
        if ($fy) {
            $start = (string) ($fy['start_date'] ?? '');
            $end = (string) ($fy['end_date'] ?? '');
            if ($start !== '' && $date < $start) {
                $date = $start;
            }
            if ($end !== '' && $date > $end) {
                $date = $end;
            }
        }
    }
    return $date;
}

/**
 * Calendar date range for a run's period: the fiscal-year start plus the period
 * offset (period 1 = FY start month, etc.). Used to count leave inside the run.
 * Returns ['', ''] when the fiscal year is unknown.
 */
function payroll_period_range(int $fiscalYearId, int $periodNo): array
{
    static $starts = [];
    if (!array_key_exists($fiscalYearId, $starts)) {
        $stmt = db()->prepare('SELECT start_date FROM fiscal_years WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $fiscalYearId]);
        $starts[$fiscalYearId] = (string) ($stmt->fetchColumn() ?: '');
    }
    $start = $starts[$fiscalYearId];
    if ($start === '') {
        return ['', ''];
    }
    $periodNo = max(1, min(12, $periodNo));
    $periodStart = date('Y-m-d', strtotime($start . ' +' . ($periodNo - 1) . ' months'));
    $periodEnd = date('Y-m-d', strtotime($start . ' +' . $periodNo . ' months -1 day'));
    return [$periodStart, $periodEnd];
}

function payroll_calculate_line(array $employee, array $run, array $taxVersion, array $adjustments = []): array
{
    $basic = round((float) $employee['basic_salary'], 2);
    $originalBasic = $basic;

    // Unpaid-leave salary cut: reduce basic by (basic / working-days) * approved
    // unpaid-leave days falling in this run's period. Reducing basic makes gross,
    // tax and net all drop like a real absence and keeps the accrual balanced
    // (it posts off gross). Driven by payroll_settings + leave_types.deduct_salary.
    $unpaidLeaveDays = 0.0;
    $unpaidLeaveDeduction = 0.0;
    $payrollSettings = payroll_settings((int) $run['company_id']);
    if ((int) ($payrollSettings['deduct_unpaid_leave'] ?? 1) === 1
        && (int) ($employee['user_id'] ?? 0) > 0
        && table_exists('leave_requests') && table_exists('leave_types')
        && column_exists('leave_types', 'deduct_salary')) {
        [$periodStart, $periodEnd] = payroll_period_range((int) $run['fiscal_year_id'], (int) $run['period_no']);
        if ($periodStart !== '' && $periodEnd !== '') {
            $leaveStmt = db()->prepare("SELECT COALESCE(SUM(DATEDIFF(LEAST(lr.end_date, :pend), GREATEST(lr.start_date, :pstart)) + 1), 0)
                FROM leave_requests lr INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
                WHERE lr.company_id = :cid AND lr.staff_user_id = :uid AND lr.status = 'approved' AND lt.deduct_salary = 1
                  AND lr.start_date <= :pend2 AND lr.end_date >= :pstart2");
            $leaveStmt->execute([
                'cid' => (int) $run['company_id'], 'uid' => (int) $employee['user_id'],
                'pend' => $periodEnd, 'pstart' => $periodStart, 'pend2' => $periodEnd, 'pstart2' => $periodStart,
            ]);
            $unpaidLeaveDays = max(0.0, (float) $leaveStmt->fetchColumn());
            if ($unpaidLeaveDays > 0) {
                $workingDays = max(1.0, (float) ($payrollSettings['standard_working_days'] ?? 30));
                $unpaidLeaveDeduction = min($originalBasic, round($originalBasic / $workingDays * $unpaidLeaveDays, 2));
                $basic = round($originalBasic - $unpaidLeaveDeduction, 2);
            }
        }
    }

    $componentLines = payroll_employee_component_lines($employee);

    // Per-period manual adjustments entered on THIS run's salary sheet. The extra
    // earning is taxable remuneration (into gross + assessable income); the extra
    // deduction is a post-tax employee deduction, like "other deductions".
    $adjEarning = max(0.0, round((float) ($adjustments['earning'] ?? 0), 2));
    $adjDeduction = max(0.0, round((float) ($adjustments['deduction'] ?? 0), 2));
    $adjRemark = trim((string) ($adjustments['remark'] ?? ''));

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
        if ($category === 'deduction') {
            // Employee-borne deductions reduce net pay. Employer-paid deductions
            // are an employer cost, NOT employee earnings — they must fall in this
            // branch (not the allowances "else"), or they would inflate gross and,
            // being excluded from otherDeduction, raise net pay by their full value.
            if (!(int) $component['employer_paid']) {
                $otherDeduction += $amount;
            }
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

    // The adjustment earning is assessable, so fold it into taxable pay too.
    $taxableMonthly += $adjEarning;
    $gross = round($basic + $allowances + $overtime + $benefits + $adjEarning, 2);

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
    // Nepal splits the withholding: the FIRST slab (1%, or 0% under SSF) is
    // Social Security Tax — a separate revenue head from the remuneration tax
    // of the higher slabs. The monthly withholding keeps the same annual mix.
    $sstAnnual = round((float) ($slabBreakdown[0]['tax'] ?? 0), 2);

    // Monthly withholding, regular method: remaining annual liability spread
    // over the remaining periods; the final period absorbs rounding drift.
    $periodNo = max(1, min(12, (int) $run['period_no']));
    $monthsRemaining = 12 - $periodNo + 1;
    $taxYtdBefore = payroll_tax_withheld_ytd((int) $employee['id'], (int) $run['fiscal_year_id'], (int) ($run['id'] ?? 0), $periodNo);
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

    $sstMonth = $annualTax > 0.004 ? round($taxMonth * ($sstAnnual / $annualTax), 2) : 0.0;
    $sstMonth = min($sstMonth, $taxMonth);

    $netPay = round($gross - $retEmployeeMonth - $taxMonth - $advanceDeduction - $otherDeduction - $adjDeduction, 2);

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
        'sst_month' => $sstMonth,
        'retirement_employee_month' => $retEmployeeMonth,
        'retirement_employer_month' => $retEmployerMonth,
        'advance_deduction' => $advanceDeduction,
        'other_deduction' => round($otherDeduction, 2),
        'unpaid_leave_days' => round($unpaidLeaveDays, 2),
        'unpaid_leave_deduction' => round($unpaidLeaveDeduction, 2),
        'adj_earning' => $adjEarning,
        'adj_deduction' => $adjDeduction,
        'adj_remark' => $adjRemark,
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
            'unpaid_leave' => ['days' => round($unpaidLeaveDays, 2), 'deduction' => round($unpaidLeaveDeduction, 2), 'original_basic' => $originalBasic],
            'withholding' => [
                'method' => $periodNo >= 12 ? 'final-period adjustment' : 'regular (remaining months)',
                'period_no' => $periodNo,
                'months_remaining' => $monthsRemaining,
                'ytd_withheld' => round($taxYtdBefore, 2),
            ],
            'loans' => $loanPlan,
            'adjustment' => ['earning' => $adjEarning, 'deduction' => $adjDeduction, 'remark' => $adjRemark],
        ],
    ];
}

/**
 * The payroll_employees ids a run is scoped to — [] means every active
 * employee (a normal full-company run). Stored as a JSON array on the run so
 * a single-staff / subset run keeps its scope through every recalculation.
 */
function payroll_run_scope_ids(array $run): array
{
    $raw = trim((string) ($run['employee_scope'] ?? ''));
    if ($raw === '') {
        return [];
    }
    $ids = json_decode($raw, true);
    if (!is_array($ids)) {
        return [];
    }
    return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
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
    // A scoped run (single staff or a selected subset) calculates ONLY the
    // employees stored on the run — the scope survives every recalculation.
    $scopeIds = payroll_run_scope_ids($run);
    if ($scopeIds !== []) {
        $employees = array_values(array_filter($employees, static fn (array $e): bool => in_array((int) $e['id'], $scopeIds, true)));
        if ($employees === []) {
            return ['ok' => false, 'error' => 'None of this run\'s selected employees are active any more. Edit the roster or delete the run.'];
        }
    }

    $pdo = db();

    // Preserve any per-period manual adjustments already entered on this run's
    // lines, so recalculating from the employee master never wipes them.
    $existingAdj = [];
    $adjStmt = $pdo->prepare('SELECT payroll_employee_id, adj_earning, adj_deduction, adj_remark FROM payroll_run_lines WHERE run_id = :run');
    $adjStmt->execute(['run' => $runId]);
    foreach ($adjStmt->fetchAll(PDO::FETCH_ASSOC) as $adjRow) {
        $existingAdj[(int) $adjRow['payroll_employee_id']] = [
            'earning' => (float) $adjRow['adj_earning'],
            'deduction' => (float) $adjRow['adj_deduction'],
            'remark' => (string) ($adjRow['adj_remark'] ?? ''),
        ];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM payroll_run_lines WHERE run_id = :run')->execute(['run' => $runId]);
        $insert = $pdo->prepare('INSERT INTO payroll_run_lines (
                run_id, payroll_employee_id, basic, allowances, overtime, benefits, gross,
                assessable_annual, retirement_deduction_annual, taxable_annual, annual_tax,
                tax_ytd_before, tax_month, sst_month, retirement_employee_month, retirement_employer_month,
                advance_deduction, other_deduction, unpaid_leave_days, unpaid_leave_deduction, adj_earning, adj_deduction, adj_remark, net_pay, trace, line_status, warnings
            ) VALUES (
                :run_id, :pe, :basic, :allowances, :overtime, :benefits, :gross,
                :assessable, :ret_ded, :taxable, :annual_tax,
                :ytd, :tax_month, :sst_month, :ret_emp, :ret_er,
                :advance, :other_ded, :leave_days, :leave_ded, :adj_earning, :adj_deduction, :adj_remark, :net, :trace, :line_status, :warnings
            )');
        $totals = ['gross' => 0.0, 'tax' => 0.0, 'deductions' => 0.0, 'employer' => 0.0, 'net' => 0.0];
        foreach ($employees as $employee) {
            $calc = payroll_calculate_line($employee, $run, $taxVersion, $existingAdj[(int) $employee['id']] ?? []);
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
                'sst_month' => $calc['sst_month'],
                'ret_emp' => $calc['retirement_employee_month'],
                'ret_er' => $calc['retirement_employer_month'],
                'advance' => $calc['advance_deduction'],
                'other_ded' => $calc['other_deduction'],
                'leave_days' => $calc['unpaid_leave_days'],
                'leave_ded' => $calc['unpaid_leave_deduction'],
                'adj_earning' => $calc['adj_earning'],
                'adj_deduction' => $calc['adj_deduction'],
                'adj_remark' => $calc['adj_remark'] !== '' ? $calc['adj_remark'] : null,
                'net' => $calc['net_pay'],
                'trace' => json_encode($calc['trace'], JSON_UNESCAPED_UNICODE),
                'line_status' => $calc['line_status'],
                'warnings' => $calc['warnings'] === [] ? null : implode(' | ', $calc['warnings']),
            ]);
            $totals['gross'] += $calc['gross'];
            $totals['tax'] += $calc['tax_month'];
            $totals['deductions'] += $calc['retirement_employee_month'] + $calc['advance_deduction'] + $calc['other_deduction'] + $calc['adj_deduction'];
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

/**
 * Set (or clear, with zeros) the per-period manual adjustment on ONE salary-sheet
 * line and recompute that line plus the run totals. Editing is allowed only while
 * the run is still draft/calculated — never after approval/posting. The extra
 * earning is taxable and the extra deduction is post-tax, so tax and net recompute
 * exactly as a full recalculation would. Returns ['ok'=>bool, 'error'=>?string].
 */
function payroll_set_line_adjustment(int $runId, int $lineId, float $adjEarning, float $adjDeduction, string $remark): array
{
    $run = payroll_run($runId);
    if (!$run || !in_array((string) $run['status'], ['draft', 'calculated'], true)) {
        return ['ok' => false, 'error' => 'The salary sheet can only be edited while the run is draft or calculated (not after approval).'];
    }
    $taxVersion = payroll_active_tax_version((int) $run['company_id'], (int) $run['fiscal_year_id']);
    if (!$taxVersion) {
        return ['ok' => false, 'error' => 'No published tax configuration for this fiscal year.'];
    }
    $lineStmt = db()->prepare('SELECT payroll_employee_id FROM payroll_run_lines WHERE id = :id AND run_id = :run LIMIT 1');
    $lineStmt->execute(['id' => $lineId, 'run' => $runId]);
    $employeeId = (int) ($lineStmt->fetchColumn() ?: 0);
    if ($employeeId <= 0) {
        return ['ok' => false, 'error' => 'That salary line is not part of this run.'];
    }
    $empStmt = db()->prepare('SELECT * FROM payroll_employees WHERE id = :id AND company_id = :cid LIMIT 1');
    $empStmt->execute(['id' => $employeeId, 'cid' => (int) $run['company_id']]);
    $employee = $empStmt->fetch();
    if (!$employee) {
        return ['ok' => false, 'error' => 'Employee record not found for this company.'];
    }

    $calc = payroll_calculate_line($employee, $run, $taxVersion, [
        'earning' => max(0.0, round($adjEarning, 2)),
        'deduction' => max(0.0, round($adjDeduction, 2)),
        'remark' => $remark,
    ]);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE payroll_run_lines SET
                basic = :basic, allowances = :allowances, overtime = :overtime, benefits = :benefits, gross = :gross,
                assessable_annual = :assessable, retirement_deduction_annual = :ret_ded, taxable_annual = :taxable,
                annual_tax = :annual_tax, tax_ytd_before = :ytd, tax_month = :tax_month,
                retirement_employee_month = :ret_emp, retirement_employer_month = :ret_er,
                advance_deduction = :advance, other_deduction = :other_ded,
                unpaid_leave_days = :leave_days, unpaid_leave_deduction = :leave_ded,
                sst_month = :sst_month,
                adj_earning = :adj_earning, adj_deduction = :adj_deduction, adj_remark = :adj_remark,
                net_pay = :net, trace = :trace, line_status = :line_status, warnings = :warnings
            WHERE id = :id AND run_id = :run')
            ->execute([
                'basic' => $calc['basic'], 'allowances' => $calc['allowances'], 'overtime' => $calc['overtime'],
                'benefits' => $calc['benefits'], 'gross' => $calc['gross'], 'assessable' => $calc['assessable_annual'],
                'ret_ded' => $calc['retirement_deduction_annual'], 'taxable' => $calc['taxable_annual'],
                'annual_tax' => $calc['annual_tax'], 'ytd' => $calc['tax_ytd_before'], 'tax_month' => $calc['tax_month'],
                'sst_month' => $calc['sst_month'],
                'ret_emp' => $calc['retirement_employee_month'], 'ret_er' => $calc['retirement_employer_month'],
                'advance' => $calc['advance_deduction'], 'other_ded' => $calc['other_deduction'],
                'leave_days' => $calc['unpaid_leave_days'], 'leave_ded' => $calc['unpaid_leave_deduction'],
                'adj_earning' => $calc['adj_earning'], 'adj_deduction' => $calc['adj_deduction'],
                'adj_remark' => $calc['adj_remark'] !== '' ? $calc['adj_remark'] : null,
                'net' => $calc['net_pay'], 'trace' => json_encode($calc['trace'], JSON_UNESCAPED_UNICODE),
                'line_status' => $calc['line_status'], 'warnings' => $calc['warnings'] === [] ? null : implode(' | ', $calc['warnings']),
                'id' => $lineId, 'run' => $runId,
            ]);

        // Re-sync the run header totals from all its lines.
        $sum = $pdo->prepare('SELECT
                COALESCE(SUM(gross),0) AS g,
                COALESCE(SUM(tax_month),0) AS t,
                COALESCE(SUM(retirement_employee_month + advance_deduction + other_deduction + adj_deduction),0) AS d,
                COALESCE(SUM(retirement_employer_month),0) AS e,
                COALESCE(SUM(net_pay),0) AS n
            FROM payroll_run_lines WHERE run_id = :run');
        $sum->execute(['run' => $runId]);
        $t = $sum->fetch(PDO::FETCH_ASSOC) ?: ['g' => 0, 't' => 0, 'd' => 0, 'e' => 0, 'n' => 0];
        $pdo->prepare("UPDATE payroll_runs SET status = 'calculated', total_gross = :g, total_tax = :t,
                total_deductions = :d, total_employer_contrib = :e, total_net = :n WHERE id = :id")
            ->execute([
                'g' => round((float) $t['g'], 2), 't' => round((float) $t['t'], 2),
                'd' => round((float) $t['d'], 2), 'e' => round((float) $t['e'], 2),
                'n' => round((float) $t['n'], 2), 'id' => $runId,
            ]);
        $pdo->commit();
        return ['ok' => true, 'net_pay' => $calc['net_pay']];
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
    $adjDeduction = $sum('adj_deduction');
    $sst = $sum('sst_month');
    $net = $sum('net_pay');

    $entries = [];
    $entries[] = ['ledger_id' => (int) $settings['salary_expense_ledger_id'], 'entry_type' => 'debit', 'amount' => $gross, 'memo' => 'Gross earnings'];
    if ($retEmployer > 0) {
        $entries[] = ['ledger_id' => (int) $settings['employer_contrib_expense_ledger_id'], 'entry_type' => 'debit', 'amount' => $retEmployer, 'memo' => 'Employer retirement contribution'];
    }
    if ($tax > 0) {
        // Nepal's withholding posts as TWO heads: the 1% first-slab Social
        // Security Tax and the remuneration tax of the higher slabs. When no
        // separate SST ledger is mapped both legs credit the same ledger, but
        // the memos still show the statutory split.
        $sst = round(min($sst, $tax), 2);
        $remuneration = round($tax - $sst, 2);
        $sstLedgerId = (int) ($settings['sst_payable_ledger_id'] ?? 0) ?: (int) $settings['tds_payable_ledger_id'];
        if ($sst > 0) {
            $entries[] = ['ledger_id' => $sstLedgerId, 'entry_type' => 'credit', 'amount' => $sst, 'memo' => 'Social Security Tax withheld (1% slab)'];
        }
        if ($remuneration > 0) {
            $entries[] = ['ledger_id' => (int) $settings['tds_payable_ledger_id'], 'entry_type' => 'credit', 'amount' => $remuneration, 'memo' => 'Remuneration tax withheld'];
        }
    }
    if ($retEmployee + $retEmployer > 0) {
        $entries[] = ['ledger_id' => (int) $settings['retirement_payable_ledger_id'], 'entry_type' => 'credit', 'amount' => round($retEmployee + $retEmployer, 2), 'memo' => 'Retirement fund payable (employee + employer)'];
    }
    if ($advance > 0) {
        $entries[] = ['ledger_id' => (int) $settings['advance_ledger_id'], 'entry_type' => 'credit', 'amount' => $advance, 'memo' => 'Employee advance recovered'];
    }
    if ($otherDeduction + $adjDeduction > 0) {
        // Other deductions AND manual adjustment deductions stay in salary
        // payable until remitted separately. Both are post-tax employee
        // deductions subtracted from net_pay; omitting adj_deduction here
        // left the journal unbalanced by exactly that amount, so any run
        // with an "Extra deduction" could never post its accrual.
        $net = round($net + $otherDeduction + $adjDeduction, 2);
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
                'voucher_date' => payroll_run_voucher_date($run),
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
                // Lock the loan row and record the ACTUALLY-APPLIED reduction,
                // not the (possibly stale) planned amount: a plan calculated
                // before an earlier run depleted the balance could exceed what
                // remains. Storing the real delta keeps the recovery txn honest
                // so a later reopen reverses exactly what was taken and never
                // over-restores the balance.
                $balStmt = $pdo->prepare('SELECT balance FROM payroll_loans WHERE id = :id FOR UPDATE');
                $balStmt->execute(['id' => $loanId]);
                $balanceBefore = round((float) ($balStmt->fetchColumn() ?: 0), 2);
                $applied = round(min($amount, max(0.0, $balanceBefore)), 2);
                if ($applied <= 0) {
                    continue;
                }
                $newBalance = round($balanceBefore - $applied, 2);
                $pdo->prepare("UPDATE payroll_loans SET balance = :nb,
                        status = IF(:nb2 <= 0.004, 'settled', status) WHERE id = :id")
                    ->execute(['nb' => $newBalance, 'nb2' => $newBalance, 'id' => $loanId]);
                $pdo->prepare('INSERT INTO payroll_loan_txns (loan_id, run_id, txn_type, amount, txn_date, voucher_id, notes)
                        VALUES (:loan, :run, \'recovery\', :amt, :dt, :vid, :notes)')
                    ->execute([
                        'loan' => $loanId, 'run' => $runId, 'amt' => $applied,
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

/**
 * Post the accrual voucher for a run that reached approved/posted/paid WITHOUT
 * one — the case where auto-post was off at approval time, which otherwise left
 * the salary expense, TDS and payables unposted (only the payment voucher, if
 * any, was in the books). Idempotent: refuses when an accrual already exists.
 * Does NOT touch loan recoveries (those already ran at approval time).
 * Returns ['ok'=>bool, 'error'=>?string, 'voucher_id'=>int].
 */
function payroll_post_accrual(int $runId, int $userId): array
{
    $run = payroll_run($runId);
    if (!$run) {
        return ['ok' => false, 'error' => 'Run not found.', 'voucher_id' => 0];
    }
    if (!in_array((string) $run['status'], ['approved', 'posted', 'paid'], true)) {
        return ['ok' => false, 'error' => 'Only an approved, posted or paid run can post its accrual.', 'voucher_id' => 0];
    }
    if ((int) ($run['accrual_voucher_id'] ?? 0) > 0) {
        return ['ok' => false, 'error' => 'The accrual voucher is already posted for this run.', 'voucher_id' => 0];
    }
    $settings = payroll_settings((int) $run['company_id']);
    foreach ([
        'salary_expense_ledger_id' => 'Salary expense',
        'salary_payable_ledger_id' => 'Salary payable',
        'tds_payable_ledger_id' => 'Income tax (TDS) payable',
    ] as $key => $label) {
        if ((int) ($settings[$key] ?? 0) <= 0) {
            return ['ok' => false, 'error' => $label . ' ledger is not mapped in Payroll Settings.', 'voucher_id' => 0];
        }
    }
    $entries = payroll_accrual_entries($run, $settings);
    $debits = round(array_sum(array_map(static fn (array $e): float => $e['entry_type'] === 'debit' ? $e['amount'] : 0, $entries)), 2);
    $credits = round(array_sum(array_map(static fn (array $e): float => $e['entry_type'] === 'credit' ? $e['amount'] : 0, $entries)), 2);
    if (abs($debits - $credits) > 0.011) {
        return ['ok' => false, 'error' => 'Accrual journal does not balance (Dr ' . number_format($debits, 2) . ' vs Cr ' . number_format($credits, 2) . ').', 'voucher_id' => 0];
    }
    $pdo = db();
    // Self-heal: adopt an orphan accrual voucher left by a previous partial
    // failure (voucher committed, run row never stamped). Without this, the
    // idempotency guard above never fires and every retry dies on the
    // uniq_vouchers_source key with a cryptic SQLSTATE[23000].
    $orphanStmt = $pdo->prepare("SELECT id FROM vouchers
        WHERE source_type = 'payroll_run' AND source_id = :rid AND company_id = :cid LIMIT 1");
    $orphanStmt->execute(['rid' => $runId, 'cid' => (int) $run['company_id']]);
    $orphanId = (int) ($orphanStmt->fetchColumn() ?: 0);
    if ($orphanId > 0) {
        $pdo->prepare("UPDATE payroll_runs SET accrual_voucher_id = :vid, posted_at = COALESCE(posted_at, NOW()),
                status = IF(status = 'approved', 'posted', status) WHERE id = :id")
            ->execute(['vid' => $orphanId, 'id' => $runId]);
        return ['ok' => true, 'error' => null, 'voucher_id' => $orphanId];
    }
    // Voucher + run stamp commit together so a mid-flight failure can never
    // strand a voucher the run does not know about.
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $voucherId = create_voucher_with_entries([
            'company_id' => (int) $run['company_id'],
            'fiscal_year_id' => (int) $run['fiscal_year_id'],
            'voucher_no' => 'PAY-' . $run['period_label'] . '-' . $runId,
            'voucher_type' => 'journal',
            'voucher_date' => payroll_run_voucher_date($run),
            'source_type' => 'payroll_run',
            'source_id' => $runId,
            'total_amount' => $debits,
            'narration' => 'Payroll accrual for ' . $run['period_label'] . ' (' . count(payroll_run_lines($runId)) . ' employees).',
            'status' => 'posted',
            'posted_by' => $userId,
        ], $entries);
        $pdo->prepare("UPDATE payroll_runs SET accrual_voucher_id = :vid, posted_at = COALESCE(posted_at, NOW()),
                status = IF(status = 'approved', 'posted', status) WHERE id = :id")
            ->execute(['vid' => $voucherId, 'id' => $runId]);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return ['ok' => true, 'error' => null, 'voucher_id' => $voucherId];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $exception->getMessage(), 'voucher_id' => 0];
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
    // The cash truly moves on the payment date, so it is NEVER clamped into the
    // run's fiscal year (unlike the accrual) — but a last-period (Ashadh) run is
    // usually paid a few days into the NEXT Nepali year, which may not be set up
    // yet. Catch that here with an actionable message instead of the posting
    // engine's generic refusal.
    if (!fiscal_year_for_date((int) $run['company_id'], $paymentDate)) {
        return ['ok' => false, 'error' => 'The payment date ' . $paymentDate . ' is not inside any fiscal year of this company — a last-month salary is usually paid in the NEXT fiscal year. Create/open that fiscal year first (Accounting → Fiscal Years), or pick a payment date inside an existing year.'];
    }
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

/**
 * Reopen an approved / posted / paid run back to 'calculated' so its salary
 * sheet can be corrected, then re-approved and re-posted. This is the controlled
 * counterpart to posting — the payroll module's own reversal, the very thing the
 * cancel path tells posted runs to use. In one transaction it:
 *   1. reverses the advance/loan recoveries this run made (adds the recovered
 *      amount back, reactivates settled loans) and deletes the recovery ledger
 *      rows, so a later re-post never double-recovers;
 *   2. deletes the run's payment and accrual vouchers — their entries cascade on
 *      the FK, which also frees the uniq_vouchers_source / voucher_no keys so the
 *      corrected re-post reuses the same numbers cleanly;
 *   3. resets the run header to an editable calculated state.
 * Every existing posting guard still applies first: a reconciled bank entry, a
 * locked period or a closed fiscal year blocks the reopen with the voucher
 * engine's own message. A correction reason (>= 10 chars) is required for audit.
 * Returns ['ok'=>bool, 'error'=>?string, 'reversed_vouchers'=>int, 'later_posted'=>int].
 */
function payroll_reopen_run(int $runId, int $userId, string $reason): array
{
    $run = payroll_run($runId);
    if (!$run) {
        return ['ok' => false, 'error' => 'Run not found.'];
    }
    if (!in_array((string) $run['status'], ['approved', 'posted', 'paid'], true)) {
        return ['ok' => false, 'error' => 'Only an approved, posted or paid run can be reopened. Draft and calculated runs are already editable.'];
    }
    $reason = trim($reason);
    if (mb_strlen($reason) < 10) {
        return ['ok' => false, 'error' => 'Give a correction reason of at least 10 characters — it is kept in the audit trail.'];
    }
    $companyId = (int) $run['company_id'];

    // Gather the vouchers this run posted (payment first, then accrual) and run
    // every one through the shared mutation guard BEFORE touching anything, so a
    // reconciled / period-locked / year-closed voucher aborts the whole reopen
    // cleanly rather than half-reversing it.
    $voucherIds = [];
    foreach (['payment_voucher_id', 'accrual_voucher_id'] as $column) {
        $voucherId = (int) ($run[$column] ?? 0);
        if ($voucherId <= 0) {
            continue;
        }
        $vStmt = db()->prepare('SELECT * FROM vouchers WHERE id = :id AND company_id = :cid LIMIT 1');
        $vStmt->execute(['id' => $voucherId, 'cid' => $companyId]);
        $voucher = $vStmt->fetch();
        if (!$voucher) {
            continue; // already gone — tolerate and keep going
        }
        // Payroll sources are module-guarded against ad-hoc register deletes;
        // reopen IS the payroll module's own reversal, so it may pass its own
        // sources while every other guard (reconciled, locked, closed) holds.
        $blocker = voucher_mutation_blocker((array) $voucher, ['payroll_run', 'payroll_payment']);
        if ($blocker !== null) {
            return ['ok' => false, 'error' => $blocker];
        }
        $voucherIds[] = $voucherId;
    }

    // Later posted runs in the same year withheld tax on top of THIS run's figures
    // (year-to-date base), so they should be recalculated after the correction.
    // This is a warning, not a blocker.
    $laterStmt = db()->prepare("SELECT COUNT(*) FROM payroll_runs
        WHERE company_id = :cid AND fiscal_year_id = :fy AND period_no > :p
          AND status IN ('approved', 'posted', 'paid')");
    $laterStmt->execute(['cid' => $companyId, 'fy' => (int) $run['fiscal_year_id'], 'p' => (int) $run['period_no']]);
    $laterPosted = (int) $laterStmt->fetchColumn();

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        // Re-read the run under a row lock so a concurrent double-submit cannot
        // reverse the same recoveries or delete the same vouchers twice: the
        // second caller blocks here, then sees 'calculated' and aborts.
        $lockStmt = $pdo->prepare('SELECT status FROM payroll_runs WHERE id = :id FOR UPDATE');
        $lockStmt->execute(['id' => $runId]);
        $lockedStatus = (string) ($lockStmt->fetchColumn() ?: '');
        if (!in_array($lockedStatus, ['approved', 'posted', 'paid'], true)) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'This run was already reopened or changed by another action. Reload the page and try again.'];
        }

        // 1. Reverse this run's advance/loan recoveries: return each recovered
        //    amount to its loan (capped at the loan principal as a safety net for
        //    legacy over-recorded rows) and reactivate a loan that the recovery
        //    had settled, then delete the recovery rows so re-posting recovers once.
        $recStmt = $pdo->prepare("SELECT loan_id, amount FROM payroll_loan_txns
            WHERE run_id = :run AND txn_type = 'recovery'");
        $recStmt->execute(['run' => $runId]);
        foreach ($recStmt->fetchAll(PDO::FETCH_ASSOC) as $recovery) {
            $pdo->prepare("UPDATE payroll_loans
                    SET balance = LEAST(principal, balance + :amt), status = IF(status = 'settled', 'active', status)
                    WHERE id = :id")
                ->execute(['amt' => (float) $recovery['amount'], 'id' => (int) $recovery['loan_id']]);
        }
        $pdo->prepare("DELETE FROM payroll_loan_txns WHERE run_id = :run AND txn_type = 'recovery'")
            ->execute(['run' => $runId]);

        // 2. Delete the run's vouchers (entries cascade; frees the source-unique
        //    and voucher_no keys for a clean corrected re-post).
        foreach ($voucherIds as $voucherId) {
            $pdo->prepare('DELETE FROM vouchers WHERE id = :id AND company_id = :cid')
                ->execute(['id' => $voucherId, 'cid' => $companyId]);
        }

        // 3. Reset the run header to an editable calculated state.
        $pdo->prepare("UPDATE payroll_runs SET status = 'calculated',
                approved_by = NULL, approved_at = NULL, accrual_voucher_id = NULL, posted_at = NULL,
                payment_voucher_id = NULL, paid_at = NULL
            WHERE id = :id")->execute(['id' => $runId]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return ['ok' => true, 'reversed_vouchers' => count($voucherIds), 'later_posted' => $laterPosted,
            'was_paid' => (string) $run['status'] === 'paid'];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $exception->getMessage()];
    }
}
