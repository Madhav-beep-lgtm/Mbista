<?php
declare(strict_types=1);

/**
 * Payroll sample data (idempotent). Runs standalone from the CLI or is
 * required by seed_sample_data.php. Seeds for MBAACA: ledger mappings,
 * pay components, a clearly-labelled SAMPLE tax version with slabs,
 * payroll profiles for the sample staff/client users, one advance, and a
 * calculated (not yet approved) payroll run so the whole approval flow can
 * be demonstrated.
 *
 * The slab amounts are DEMO values only — the admin must replace them with
 * the official IRD / Finance Act figures for the real fiscal year.
 */

if (PHP_SAPI === 'cli' && !function_exists('db')) {
    session_start();
    require_once __DIR__ . '/../app/bootstrap.php';
}
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/payroll_engine.php';

(static function (): void {
    $say = static function (string $message): void {
        echo $message . "\n";
    };

    $company = company_by_code('MBAACA');
    if (!$company || !table_exists('payroll_employees')) {
        $say('Payroll sample skipped (no MBAACA company or payroll tables).');
        return;
    }
    $companyId = (int) $company['id'];
    $_SESSION['company_id'] = $companyId;

    $fyStmt = db()->prepare('SELECT id, label, start_date FROM fiscal_years WHERE company_id = :cid ORDER BY is_default DESC, id DESC LIMIT 1');
    $fyStmt->execute(['cid' => $companyId]);
    $fy = $fyStmt->fetch();
    if (!$fy) {
        $say('Payroll sample skipped (no fiscal year).');
        return;
    }
    $fyId = (int) $fy['id'];
    $_SESSION['fiscal_year_id'] = $fyId;

    $existing = db()->prepare('SELECT COUNT(*) FROM payroll_employees WHERE company_id = :cid');
    $existing->execute(['cid' => $companyId]);
    if ((int) $existing->fetchColumn() > 0) {
        $say('Payroll sample already present.');
        return;
    }

    $adminId = (int) (db()->query("SELECT id FROM users WHERE email = 'sample.admin@mbista.local'")->fetchColumn() ?: 0);

    // --- Ledgers for mappings (find by name, else create) -------------------
    // Master-scoped lookup: the name match must never pull a group from a
    // different master (that once filed an expense under Prepaid Expenses).
    $findGroup = static function (int $companyId, array $masterKeys, string $nameLike): int {
        $stmt = db()->prepare("SELECT id FROM ledger_groups WHERE company_id = :cid AND master_key IN ('" . implode("','", $masterKeys) . "')
            AND is_active = 1 ORDER BY LOWER(name) LIKE :like DESC, is_cash_or_bank ASC, id ASC LIMIT 1");
        $stmt->execute(['cid' => $companyId, 'like' => $nameLike]);
        return (int) ($stmt->fetchColumn() ?: 0);
    };
    $ensureLedger = static function (int $companyId, string $code, string $name, string $type, int $groupId): int {
        $stmt = db()->prepare('SELECT id FROM ledgers WHERE company_id = :cid AND (code = :code OR name = :name) LIMIT 1');
        $stmt->execute(['cid' => $companyId, 'code' => $code, 'name' => $name]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status) VALUES (:cid, :gid, :code, :name, :type, 'active')")
            ->execute(['cid' => $companyId, 'gid' => $groupId ?: null, 'code' => $code, 'name' => $name, 'type' => $type]);
        return (int) db()->lastInsertId();
    };

    $expenseGroup = $findGroup($companyId, ['indirect_expense'], '%expense%');
    $liabilityGroup = $findGroup($companyId, ['current_liability'], '%liabilit%');
    $assetGroup = $findGroup($companyId, ['current_asset'], '%asset%');

    $salaryExpense = $ensureLedger($companyId, 'SAL-EXP', 'Salary Expense', 'expense', $expenseGroup);
    $employerContrib = $ensureLedger($companyId, 'SSF-EXP', 'Employer Retirement Contribution Expense', 'expense', $expenseGroup);
    $tdsPayable = $ensureLedger($companyId, 'TDS-PAY', 'Salary TDS Payable', 'liability', $liabilityGroup);
    $retirementPayable = $ensureLedger($companyId, 'RET-PAY', 'Retirement Fund Payable', 'liability', $liabilityGroup);
    $salaryPayable = $ensureLedger($companyId, 'SAL-PAY', 'Salary Payable', 'liability', $liabilityGroup);
    $advanceLedger = $ensureLedger($companyId, 'EMP-ADV', 'Employee Advances', 'asset', $assetGroup);
    // Whatever the rough matches above picked, re-home to classification-
    // correct groups (creates Employee Payables / Loans and Advances if
    // missing) so the statements stay balanced.
    payroll_fix_ledger_groups($companyId);
    $bankStmt = db()->prepare("SELECT id FROM ledgers WHERE company_id = :cid AND status = 'active' AND (LOWER(name) LIKE '%bank%' OR LOWER(name) LIKE '%cash%') ORDER BY id ASC LIMIT 1");
    $bankStmt->execute(['cid' => $companyId]);
    $bankLedger = (int) ($bankStmt->fetchColumn() ?: 0);

    payroll_settings($companyId); // ensure the row
    db()->prepare('UPDATE payroll_settings SET salary_expense_ledger_id = :se, employer_contrib_expense_ledger_id = :ee,
            tds_payable_ledger_id = :tds, retirement_payable_ledger_id = :rp, salary_payable_ledger_id = :sp,
            advance_ledger_id = :adv, bank_ledger_id = :bank, auto_post = 1 WHERE company_id = :cid')
        ->execute(['se' => $salaryExpense, 'ee' => $employerContrib, 'tds' => $tdsPayable, 'rp' => $retirementPayable,
            'sp' => $salaryPayable, 'adv' => $advanceLedger, 'bank' => $bankLedger ?: null, 'cid' => $companyId]);

    // --- Components ----------------------------------------------------------
    $componentInsert = db()->prepare('INSERT INTO payroll_components (company_id, code, name, name_np, category, calc_type, default_value, taxable, employer_paid, active, sort_order)
        VALUES (:cid, :code, :name, :np, :cat, :calc, :val, :tax, :er, 1, :sort)
        ON DUPLICATE KEY UPDATE name = VALUES(name)');
    foreach ([
        ['DA', 'Dearness Allowance', 'महँगी भत्ता', 'allowance', 'fixed', 0, 1, 0, 1],
        ['COMM', 'Communication Allowance', 'सञ्चार भत्ता', 'allowance', 'percent_basic', 5, 1, 0, 2],
        ['OT', 'Overtime', 'ओभरटाइम', 'overtime', 'fixed', 0, 1, 0, 3],
        ['LUNCH', 'Lunch Facility', 'खाजा सुविधा', 'benefit', 'fixed', 0, 1, 0, 4],
        ['WELF', 'Staff Welfare Deduction', 'कर्मचारी कल्याण कट्टी', 'deduction', 'fixed', 0, 0, 0, 5],
    ] as [$code, $name, $np, $cat, $calc, $val, $tax, $er, $sort]) {
        $componentInsert->execute(['cid' => $companyId, 'code' => $code, 'name' => $name, 'np' => $np, 'cat' => $cat, 'calc' => $calc, 'val' => $val, 'tax' => $tax, 'er' => $er, 'sort' => $sort]);
    }

    // --- SAMPLE tax version (published so payroll can calculate) -------------
    db()->prepare("INSERT INTO payroll_tax_versions (company_id, fiscal_year_id, label, legal_reference, status, effective_from,
            retirement_limit_pct, retirement_limit_cap, ssf_first_slab_exempt, rounding, created_by, approved_by)
        VALUES (:cid, :fy, :label, :ref, 'published', :eff, 33.33, 500000, 1, 'nearest', :by, :by2)")
        ->execute([
            'cid' => $companyId, 'fy' => $fyId,
            'label' => 'SAMPLE slabs ' . ($fy['label'] ?? '') . ' — replace with official IRD figures',
            'ref' => 'DEMO VALUES ONLY. Enter the slabs from the official IRD publication of the Income Tax Act, 2058 as amended by the applicable Finance Act, then publish a new version.',
            'eff' => (string) $fy['start_date'], 'by' => $adminId ?: null, 'by2' => $adminId ?: null,
        ]);
    $versionId = (int) db()->lastInsertId();
    $slabInsert = db()->prepare('INSERT INTO payroll_tax_slabs (version_id, category, lower_bound, upper_bound, rate, sort_order)
        VALUES (:vid, :cat, :lo, :hi, :rate, :sort)');
    $sampleSlabs = [
        'individual' => [[0, 500000, 1], [500000, 700000, 10], [700000, 1000000, 20], [1000000, 2000000, 30], [2000000, 5000000, 36], [5000000, null, 39]],
        'couple' => [[0, 600000, 1], [600000, 800000, 10], [800000, 1100000, 20], [1100000, 2000000, 30], [2000000, 5000000, 36], [5000000, null, 39]],
    ];
    foreach ($sampleSlabs as $category => $slabs) {
        foreach ($slabs as $sort => [$lo, $hi, $rate]) {
            $slabInsert->execute(['vid' => $versionId, 'cat' => $category, 'lo' => $lo, 'hi' => $hi, 'rate' => $rate, 'sort' => $sort]);
        }
    }

    // --- Payroll profiles for the sample people -------------------------------
    $employeeInsert = db()->prepare('INSERT INTO payroll_employees (company_id, user_id, employee_code, department, designation,
            pan_no, bank_name, bank_account, marital_status, retirement_scheme, retirement_id,
            retirement_employee_rate, retirement_employer_rate, basic_salary, status, joined_on)
        VALUES (:cid, :uid, :code, :dept, :desig, :pan, :bname, :bacc, :marital, :scheme, :rid, :rer, :rerr, :basic, \'active\', :joined)');
    $people = [
        ['sample.staff@mbista.local', 'E001', 'Audit & Assurance', 'Senior Accountant', '601234567', 'Sunrise Bank', '0150010012345', 'individual', 'ssf', 'SSF-2081-1122', 11, 20, 55000],
        ['sample.admin@mbista.local', 'E002', 'Management', 'Managing Partner', '609876543', 'Sunrise Bank', '0150010067890', 'couple', 'cit', 'CIT-99887', 10, 10, 90000],
        ['sample.client@mbista.local', 'E003', 'Consulting', 'Retainer Consultant', '', 'Everest Bank', '', 'individual', 'none', null, 0, 0, 30000],
    ];
    $employeeIds = [];
    foreach ($people as [$email, $code, $dept, $desig, $pan, $bname, $bacc, $marital, $scheme, $rid, $rer, $rerr, $basic]) {
        $uid = (int) (db()->query('SELECT id FROM users WHERE email = ' . db()->quote($email))->fetchColumn() ?: 0);
        if ($uid <= 0) {
            continue;
        }
        $employeeInsert->execute(['cid' => $companyId, 'uid' => $uid, 'code' => $code, 'dept' => $dept, 'desig' => $desig,
            'pan' => $pan ?: null, 'bname' => $bname, 'bacc' => $bacc ?: null, 'marital' => $marital, 'scheme' => $scheme,
            'rid' => $rid, 'rer' => $rer, 'rerr' => $rerr, 'basic' => $basic, 'joined' => (string) $fy['start_date']]);
        $employeeIds[$code] = (int) db()->lastInsertId();
    }

    // Component assignments: DA for E001 + E002, welfare deduction for E001.
    $componentId = static function (string $code) use ($companyId): int {
        $stmt = db()->prepare('SELECT id FROM payroll_components WHERE company_id = :cid AND code = :code');
        $stmt->execute(['cid' => $companyId, 'code' => $code]);
        return (int) ($stmt->fetchColumn() ?: 0);
    };
    $assign = db()->prepare('INSERT INTO payroll_employee_components (payroll_employee_id, component_id, amount)
        VALUES (:pe, :c, :amt) ON DUPLICATE KEY UPDATE amount = VALUES(amount)');
    if (isset($employeeIds['E001'])) {
        $assign->execute(['pe' => $employeeIds['E001'], 'c' => $componentId('DA'), 'amt' => 8000]);
        $assign->execute(['pe' => $employeeIds['E001'], 'c' => $componentId('WELF'), 'amt' => 500]);
    }
    if (isset($employeeIds['E002'])) {
        $assign->execute(['pe' => $employeeIds['E002'], 'c' => $componentId('DA'), 'amt' => 12000]);
    }

    // Advance for E001, recovered 5,000/month by payroll.
    if (isset($employeeIds['E001'])) {
        db()->prepare("INSERT INTO payroll_loans (company_id, payroll_employee_id, title, principal, balance, monthly_installment, status, issued_on, notes)
                VALUES (:cid, :pe, 'Festival advance (sample)', 20000, 20000, 5000, 'active', :dt, 'SMP payroll demo advance')")
            ->execute(['cid' => $companyId, 'pe' => $employeeIds['E001'], 'dt' => date('Y-m-d')]);
        db()->prepare("INSERT INTO payroll_loan_txns (loan_id, txn_type, amount, txn_date, notes) VALUES (:l, 'disbursement', 20000, :dt, 'Advance disbursed (sample)')")
            ->execute(['l' => (int) db()->lastInsertId(), 'dt' => date('Y-m-d')]);
    }

    // --- A calculated (not yet approved) run to demo the approval flow -------
    db()->prepare('INSERT INTO payroll_runs (company_id, fiscal_year_id, period_no, period_label, pay_date, created_by)
            VALUES (:cid, :fy, 1, :label, :pay, :by)')
        ->execute(['cid' => $companyId, 'fy' => $fyId, 'label' => 'Month 1 — ' . ($fy['label'] ?? ''), 'pay' => date('Y-m-d'), 'by' => $adminId ?: null]);
    $runId = (int) db()->lastInsertId();
    $calc = payroll_calculate_run($runId);
    $say('Payroll sample: settings mapped, 5 components, SAMPLE tax version v' . $versionId . ', ' . count($employeeIds) . ' employees, 1 advance, run #' . $runId . ' ' . ($calc['ok'] ? 'calculated (approve it in Payroll Processing)' : 'NOT calculated: ' . ($calc['error'] ?? '')));
})();
