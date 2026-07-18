<?php
declare(strict_types=1);

/**
 * Verifies the payroll-run improvements:
 *   - payroll_bs_month_name() maps periods 1..12 to Nepali BS month names
 *   - a run's custom voucher_date drives the accrual posting date (not pay_date)
 *   - with no voucher_date, posting falls back to pay_date
 *   php database/test_payroll_voucher_date.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/payroll_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

echo "Month naming\n";
ok(payroll_bs_month_name(1) === 'Shrawan', 'Period 1 = Shrawan');
ok(payroll_bs_month_name(6) === 'Poush', 'Period 6 = Poush');
ok(payroll_bs_month_name(12) === 'Ashadh', 'Period 12 = Ashadh');
ok(payroll_bs_month_name(0) === 'Shrawan' && payroll_bs_month_name(99) === 'Ashadh', 'Out-of-range clamps into 1..12');

// The column must exist after self-repair.
$cols = db()->query("SHOW COLUMNS FROM payroll_runs LIKE 'voucher_date'")->fetchAll();
ok($cols !== [], 'payroll_runs.voucher_date column exists after self-repair');

function vd_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code='VDTESTA'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE rl FROM payroll_run_lines rl JOIN payroll_runs r ON r.id=rl.run_id WHERE r.company_id=$s");
        db()->exec("DELETE FROM payroll_runs WHERE company_id=$s");
        db()->exec("DELETE FROM payroll_employees WHERE company_id=$s");
        db()->exec("DELETE ts FROM payroll_tax_slabs ts JOIN payroll_tax_versions v ON v.id=ts.version_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM payroll_tax_versions WHERE company_id=$s");
        db()->exec("DELETE FROM payroll_settings WHERE company_id=$s");
        db()->exec("DELETE ve FROM voucher_entries ve JOIN vouchers v ON v.id=ve.voucher_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
}
vd_cleanup();

db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Voucher Date Test Co','VDTESTA',1)")->execute();
$cid = (int) db()->lastInsertId();
$_SESSION['company_id'] = $cid;
$mkG = static function (string $m, string $c, string $n, int $cash = 0) use ($cid): int { db()->prepare('INSERT INTO ledger_groups (company_id,master_key,code,name,is_cash_or_bank) VALUES (?,?,?,?,?)')->execute([$cid,$m,$c,$n,$cash]); return (int) db()->lastInsertId(); };
$mkL = static function (int $g, string $c, string $n, string $t) use ($cid): int { db()->prepare("INSERT INTO ledgers (company_id,group_id,code,name,type,status) VALUES (?,?,?,?,?, 'active')")->execute([$cid,$g,$c,$n,$t]); return (int) db()->lastInsertId(); };
$gE = $mkG('indirect_expense','VD-EXP','Emp Exp'); $gL = $mkG('current_liability','VD-LIAB','Emp Pay'); $gA = $mkG('current_asset','VD-ADV','Adv'); $gB = $mkG('current_asset','VD-BANK','Bank',1);
$lSE=$mkL($gE,'SAL-EXP','Salary Expense','expense'); $lEE=$mkL($gE,'SSF-EXP','Employer Exp','expense'); $lTD=$mkL($gL,'TDS-PAY','TDS Pay','liability');
$lRP=$mkL($gL,'RET-PAY','Ret Pay','liability'); $lSP=$mkL($gL,'SAL-PAY','Salary Payable','liability'); $lAD=$mkL($gA,'EMP-ADV','Adv','asset'); $lBK=$mkL($gB,'CASH','Bank','asset');
$fy = create_fiscal_year($cid,'VD 2026/27','2026-07-16','2027-07-15',true); db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]); $fyId=(int)$fy['id']; $_SESSION['fiscal_year_id']=$fyId;
payroll_settings($cid);
db()->prepare('UPDATE payroll_settings SET salary_expense_ledger_id=:se,employer_contrib_expense_ledger_id=:ee,tds_payable_ledger_id=:tds,retirement_payable_ledger_id=:rp,salary_payable_ledger_id=:sp,advance_ledger_id=:adv,bank_ledger_id=:bank,auto_post=1,enforce_sod=0 WHERE company_id=:cid')
    ->execute(['se'=>$lSE,'ee'=>$lEE,'tds'=>$lTD,'rp'=>$lRP,'sp'=>$lSP,'adv'=>$lAD,'bank'=>$lBK,'cid'=>$cid]);
db()->prepare("INSERT INTO payroll_tax_versions (company_id,fiscal_year_id,label,legal_reference,status,effective_from,retirement_limit_pct,retirement_limit_cap,ssf_first_slab_exempt,rounding) VALUES (:cid,:fy,'VD','DEMO','published','2026-07-16',33.33,500000,1,'nearest')")->execute(['cid'=>$cid,'fy'=>$fyId]);
$vid=(int) db()->lastInsertId(); $slab=db()->prepare('INSERT INTO payroll_tax_slabs (version_id,category,lower_bound,upper_bound,rate,sort_order) VALUES (?,?,?,?,?,?)');
foreach ([[0,500000,1],[500000,null,10]] as $i=>[$lo,$hi,$r]) { $slab->execute([$vid,'individual',$lo,$hi,$r,$i]); }
$uid=(int) db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
db()->prepare("INSERT INTO payroll_employees (company_id,user_id,employee_code,department,designation,pan_no,bank_name,bank_account,marital_status,retirement_scheme,retirement_employee_rate,retirement_employer_rate,basic_salary,status,joined_on) VALUES (:cid,:uid,'E001','Ops','Officer','601','NIC','012','individual','none',0,0,50000,'active','2026-07-16')")->execute(['cid'=>$cid,'uid'=>$uid]);

echo "\nCustom voucher_date drives the accrual posting date\n";
// pay_date is 30 Bhadra-ish; voucher_date is deliberately different (Shrawan month-end).
db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,pay_date,voucher_date,created_by) VALUES (:cid,:fy,1,:l,:pay,:vd,:by)')
    ->execute(['cid'=>$cid,'fy'=>$fyId,'l'=>payroll_bs_month_name(1),'pay'=>'2026-08-30','vd'=>'2026-08-15','by'=>$uid]);
$runId=(int) db()->lastInsertId();
$run = payroll_run($runId);
ok((string) $run['period_label'] === 'Shrawan', 'New run label defaults to the month name (Shrawan)');
ok(payroll_run_voucher_date($run) === '2026-08-15', 'payroll_run_voucher_date uses the explicit voucher_date');
payroll_calculate_run($runId);
$ap = payroll_approve_and_post($runId, $uid + 1);
$av = (int) ($ap['voucher_id'] ?? 0);
ok($av > 0, 'Accrual voucher posted');
$avDate = (string) db()->query("SELECT voucher_date FROM vouchers WHERE id=$av")->fetchColumn();
ok($avDate === '2026-08-15', "Accrual posted on the custom voucher_date (got $avDate)");

echo "\nNo voucher_date -> falls back to pay_date\n";
db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,pay_date,voucher_date,created_by) VALUES (:cid,:fy,2,:l,:pay,NULL,:by)')
    ->execute(['cid'=>$cid,'fy'=>$fyId,'l'=>payroll_bs_month_name(2),'pay'=>'2026-09-28','by'=>$uid]);
$runId2=(int) db()->lastInsertId();
$run2 = payroll_run($runId2);
ok(payroll_run_voucher_date($run2) === '2026-09-28', 'Fallback to pay_date when voucher_date is NULL');
payroll_calculate_run($runId2);
$ap2 = payroll_approve_and_post($runId2, $uid + 1);
$av2 = (int) ($ap2['voucher_id'] ?? 0);
$avDate2 = (string) db()->query("SELECT voucher_date FROM vouchers WHERE id=$av2")->fetchColumn();
ok($avDate2 === '2026-09-28', "Accrual posted on the pay_date (got $avDate2)");

vd_cleanup();
echo "\n----------------------------------------\nPASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
