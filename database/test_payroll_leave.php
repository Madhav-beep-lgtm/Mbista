<?php
declare(strict_types=1);

/**
 * Payroll unpaid-leave deduction acceptance suite (CLI, self-contained).
 * Builds a company + one employee, an approved "Unpaid Leave" request inside the
 * run's period, then verifies the salary is cut by basic/working-days * days and
 * that gross/net fall accordingly. Tears down after.
 *
 *   php database/test_payroll_leave.php
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/payroll_engine.php';

accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.01; }

echo "Schema\n";
ok(column_exists('payroll_run_lines', 'unpaid_leave_deduction'), 'payroll_run_lines.unpaid_leave_deduction exists (self-repair)');
ok(column_exists('payroll_settings', 'deduct_unpaid_leave'), 'payroll_settings.deduct_unpaid_leave exists');
ok(column_exists('leave_types', 'deduct_salary'), 'leave_types.deduct_salary exists');

function pl_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'PLTESTA'")->fetchAll(PDO::FETCH_COLUMN) as $stale) {
        $stale = (int) $stale;
        db()->exec("DELETE FROM leave_requests WHERE company_id = $stale");
        db()->exec("DELETE FROM leave_types WHERE company_id = $stale");
        db()->exec("DELETE rl FROM payroll_run_lines rl INNER JOIN payroll_runs r ON r.id = rl.run_id WHERE r.company_id = $stale");
        db()->exec("DELETE FROM payroll_runs WHERE company_id = $stale");
        db()->exec("DELETE FROM payroll_employees WHERE company_id = $stale");
        db()->exec("DELETE s FROM payroll_tax_slabs s INNER JOIN payroll_tax_versions v ON v.id = s.version_id WHERE v.company_id = $stale");
        db()->exec("DELETE FROM payroll_tax_versions WHERE company_id = $stale");
        db()->exec("DELETE FROM payroll_settings WHERE company_id = $stale");
        db()->exec("DELETE ve FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id WHERE v.company_id = $stale");
        db()->exec("DELETE FROM vouchers WHERE company_id = $stale");
        db()->exec("DELETE FROM ledgers WHERE company_id = $stale");
        db()->exec("DELETE FROM ledger_groups WHERE company_id = $stale");
        db()->exec("DELETE FROM fiscal_years WHERE company_id = $stale");
        db()->exec("DELETE FROM companies WHERE id = $stale");
    }
}
pl_cleanup();

echo "\nFixture\n";
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Payroll Leave Test Co', 'PLTESTA', 1)")->execute();
$cid = (int) db()->lastInsertId();
$_SESSION['company_id'] = $cid;

$mkGroup = static function (string $m, string $c, string $n) use ($cid): int {
    db()->prepare('INSERT INTO ledger_groups (company_id, master_key, code, name, is_cash_or_bank) VALUES (?,?,?,?,?)')->execute([$cid, $m, $c, $n, $c === 'PL-BANK' ? 1 : 0]);
    return (int) db()->lastInsertId();
};
$mkLedger = static function (int $g, string $c, string $n, string $t) use ($cid): int {
    db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status) VALUES (?,?,?,?,?,'active')")->execute([$cid, $g, $c, $n, $t]);
    return (int) db()->lastInsertId();
};
$gExp = $mkGroup('indirect_expense', 'PL-EXP', 'Employee Benefit Expenses');
$gLiab = $mkGroup('current_liability', 'PL-LIAB', 'Employee Payables');
$gAsset = $mkGroup('current_asset', 'PL-ADV', 'Loans and Advances');
$gBank = $mkGroup('current_asset', 'PL-BANK', 'Cash and Bank');
$lSalExp = $mkLedger($gExp, 'SAL-EXP', 'Salary Expense', 'expense');
$lErExp = $mkLedger($gExp, 'SSF-EXP', 'Employer Retirement Expense', 'expense');
$lTds = $mkLedger($gLiab, 'TDS-PAY', 'TDS Payable', 'liability');
$lRet = $mkLedger($gLiab, 'RET-PAY', 'Retirement Payable', 'liability');
$lSalPay = $mkLedger($gLiab, 'SAL-PAY', 'Salary Payable', 'liability');
$lAdv = $mkLedger($gAsset, 'EMP-ADV', 'Employee Advances', 'asset');
$lBank = $mkLedger($gBank, 'CASH', 'Bank', 'asset');

$fy = create_fiscal_year($cid, 'PL 2026/27', '2026-07-16', '2027-07-15', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId = (int) $fy['id'];
$_SESSION['fiscal_year_id'] = $fyId;

payroll_settings($cid);
db()->prepare('UPDATE payroll_settings SET salary_expense_ledger_id=:se, employer_contrib_expense_ledger_id=:ee,
        tds_payable_ledger_id=:tds, retirement_payable_ledger_id=:rp, salary_payable_ledger_id=:sp, advance_ledger_id=:adv,
        bank_ledger_id=:bank, auto_post=1, enforce_sod=0, standard_working_days=30, deduct_unpaid_leave=1 WHERE company_id=:cid')
    ->execute(['se'=>$lSalExp,'ee'=>$lErExp,'tds'=>$lTds,'rp'=>$lRet,'sp'=>$lSalPay,'adv'=>$lAdv,'bank'=>$lBank,'cid'=>$cid]);

db()->prepare("INSERT INTO payroll_tax_versions (company_id, fiscal_year_id, label, legal_reference, status, effective_from,
        retirement_limit_pct, retirement_limit_cap, ssf_first_slab_exempt, rounding)
    VALUES (:cid,:fy,'PL slabs','DEMO','published','2026-07-16',33.33,500000,1,'nearest')")->execute(['cid'=>$cid,'fy'=>$fyId]);
$vid = (int) db()->lastInsertId();
$slab = db()->prepare('INSERT INTO payroll_tax_slabs (version_id, category, lower_bound, upper_bound, rate, sort_order) VALUES (?,?,?,?,?,?)');
foreach ([[0,500000,1],[500000,null,10]] as $i => [$lo,$hi,$rate]) { $slab->execute([$vid,'individual',$lo,$hi,$rate,$i]); }

$uid = (int) db()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
db()->prepare("INSERT INTO payroll_employees (company_id, user_id, employee_code, department, designation, pan_no, bank_name, bank_account,
        marital_status, retirement_scheme, retirement_employee_rate, retirement_employer_rate, basic_salary, status, joined_on)
    VALUES (:cid,:uid,'E001','Ops','Officer','601','NIC','012','individual','none',0,0,30000,'active','2026-07-16')")
    ->execute(['cid'=>$cid,'uid'=>$uid]);
$empId = (int) db()->lastInsertId();

// Unpaid leave type + an APPROVED 3-day request inside period 1 (16 Jul - 15 Aug).
db()->prepare("INSERT INTO leave_types (company_id, name, default_days_per_year, is_system, is_active, deduct_salary) VALUES (:cid,'Unpaid Leave',0,0,1,1)")->execute(['cid'=>$cid]);
$ltId = (int) db()->lastInsertId();
$mkLeave = static function (int $cid, int $uid, int $ltId, string $s, string $e, int $days, string $status) {
    db()->prepare("INSERT INTO leave_requests (company_id, staff_user_id, leave_type_id, start_date, end_date, total_days, reason, status)
        VALUES (:cid,:uid,:lt,:s,:e,:d,'test',:st)")->execute(['cid'=>$cid,'uid'=>$uid,'lt'=>$ltId,'s'=>$s,'e'=>$e,'d'=>$days,'st'=>$status]);
};
$mkLeave($cid, $uid, $ltId, '2026-07-20', '2026-07-22', 3, 'approved');

// ---------------------------------------------------------------------------
// Run + assertions
// ---------------------------------------------------------------------------
echo "\nUnpaid leave deduction\n";
$mkRun = static function (int $cid, int $fyId, int $period) {
    db()->prepare('INSERT INTO payroll_runs (company_id, fiscal_year_id, period_no, period_label, pay_date, created_by) VALUES (:cid,:fy,:p,:l,:pay,NULL)')
        ->execute(['cid'=>$cid,'fy'=>$fyId,'p'=>$period,'l'=>"PL M$period",'pay'=>'2026-07-30']);
    return (int) db()->lastInsertId();
};
$runId = $mkRun($cid, $fyId, 1);
$calc = payroll_calculate_run($runId);
ok($calc['ok'], 'Run calculated');
$line = db()->query("SELECT * FROM payroll_run_lines WHERE run_id=$runId AND payroll_employee_id=$empId")->fetch();
$expectedCut = round(30000 / 30 * 3, 2); // 3000
ok($line && near((float) $line['unpaid_leave_days'], 3), 'Counted 3 approved unpaid-leave days in the period');
ok($line && near((float) $line['unpaid_leave_deduction'], $expectedCut), "Leave deduction = basic/30*3 = $expectedCut");
ok($line && near((float) $line['basic'], 30000 - $expectedCut), 'Basic reduced by the leave cut (30,000 -> 27,000)');
ok($line && near((float) $line['gross'], 30000 - $expectedCut), 'Gross reflects the cut (27,000)');
ok($line && (float) $line['net_pay'] < 27000.01 && (float) $line['net_pay'] > 0, 'Net pay dropped and stays positive');

// Leave OUTSIDE the period must not deduct.
echo "\nLeave outside the period\n";
db()->exec("DELETE FROM leave_requests WHERE company_id=$cid");
$mkLeave($cid, $uid, $ltId, '2026-09-01', '2026-09-05', 5, 'approved'); // period 3, not period 1
$runId2 = $mkRun($cid, $fyId, 1);
payroll_calculate_run($runId2);
$line2 = db()->query("SELECT * FROM payroll_run_lines WHERE run_id=$runId2 AND payroll_employee_id=$empId")->fetch();
ok($line2 && near((float) $line2['unpaid_leave_deduction'], 0) && near((float) $line2['basic'], 30000), 'Sept leave does not affect the period-1 run (basic full 30,000)');

// Pending (unapproved) leave must not deduct.
echo "\nUnapproved leave\n";
db()->exec("DELETE FROM leave_requests WHERE company_id=$cid");
$mkLeave($cid, $uid, $ltId, '2026-07-20', '2026-07-24', 5, 'pending');
$runId3 = $mkRun($cid, $fyId, 1);
payroll_calculate_run($runId3);
$line3 = db()->query("SELECT * FROM payroll_run_lines WHERE run_id=$runId3 AND payroll_employee_id=$empId")->fetch();
ok($line3 && near((float) $line3['unpaid_leave_deduction'], 0), 'Pending (unapproved) leave does not deduct');

// Toggle off deduct_unpaid_leave.
echo "\nDeduction disabled\n";
db()->exec("UPDATE leave_requests SET status='approved' WHERE company_id=$cid");
db()->exec("UPDATE payroll_settings SET deduct_unpaid_leave=0 WHERE company_id=$cid");
$runId4 = $mkRun($cid, $fyId, 1);
payroll_calculate_run($runId4);
$line4 = db()->query("SELECT * FROM payroll_run_lines WHERE run_id=$runId4 AND payroll_employee_id=$empId")->fetch();
ok($line4 && near((float) $line4['unpaid_leave_deduction'], 0) && near((float) $line4['basic'], 30000), 'With deduct_unpaid_leave OFF, no cut is applied');

pl_cleanup();
echo "\n----------------------------------------\n";
echo "PASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
