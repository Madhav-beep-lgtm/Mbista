<?php
declare(strict_types=1);

/**
 * Regression test: with auto-post OFF, a run can reach "paid" with only the
 * payment voucher and NO accrual — payroll_post_accrual() must let it be posted.
 *   php database/test_payroll_post_accrual.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/payroll_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.01; }

function pa_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code='PATESTA'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
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
pa_cleanup();

db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Post Accrual Test Co','PATESTA',1)")->execute();
$cid = (int) db()->lastInsertId();
$_SESSION['company_id'] = $cid;
$mkG = static function (string $m, string $c, string $n, int $cash = 0) use ($cid): int { db()->prepare('INSERT INTO ledger_groups (company_id,master_key,code,name,is_cash_or_bank) VALUES (?,?,?,?,?)')->execute([$cid,$m,$c,$n,$cash]); return (int) db()->lastInsertId(); };
$mkL = static function (int $g, string $c, string $n, string $t) use ($cid): int { db()->prepare("INSERT INTO ledgers (company_id,group_id,code,name,type,status) VALUES (?,?,?,?,?, 'active')")->execute([$cid,$g,$c,$n,$t]); return (int) db()->lastInsertId(); };
$gE = $mkG('indirect_expense','PA-EXP','Emp Exp'); $gL = $mkG('current_liability','PA-LIAB','Emp Pay'); $gA = $mkG('current_asset','PA-ADV','Adv'); $gB = $mkG('current_asset','PA-BANK','Bank',1);
$lSE=$mkL($gE,'SAL-EXP','Salary Expense','expense'); $lEE=$mkL($gE,'SSF-EXP','Employer Exp','expense'); $lTD=$mkL($gL,'TDS-PAY','TDS Pay','liability');
$lRP=$mkL($gL,'RET-PAY','Ret Pay','liability'); $lSP=$mkL($gL,'SAL-PAY','Salary Payable','liability'); $lAD=$mkL($gA,'EMP-ADV','Adv','asset'); $lBK=$mkL($gB,'CASH','Bank','asset');
$fy = create_fiscal_year($cid,'PA 2026/27','2026-07-16','2027-07-15',true); db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]); $fyId=(int)$fy['id']; $_SESSION['fiscal_year_id']=$fyId;
payroll_settings($cid);
// auto_post = 0  -> the scenario that leaves the accrual unposted
db()->prepare('UPDATE payroll_settings SET salary_expense_ledger_id=:se,employer_contrib_expense_ledger_id=:ee,tds_payable_ledger_id=:tds,retirement_payable_ledger_id=:rp,salary_payable_ledger_id=:sp,advance_ledger_id=:adv,bank_ledger_id=:bank,auto_post=0,enforce_sod=0 WHERE company_id=:cid')
    ->execute(['se'=>$lSE,'ee'=>$lEE,'tds'=>$lTD,'rp'=>$lRP,'sp'=>$lSP,'adv'=>$lAD,'bank'=>$lBK,'cid'=>$cid]);
db()->prepare("INSERT INTO payroll_tax_versions (company_id,fiscal_year_id,label,legal_reference,status,effective_from,retirement_limit_pct,retirement_limit_cap,ssf_first_slab_exempt,rounding) VALUES (:cid,:fy,'PA','DEMO','published','2026-07-16',33.33,500000,1,'nearest')")->execute(['cid'=>$cid,'fy'=>$fyId]);
$vid=(int) db()->lastInsertId(); $slab=db()->prepare('INSERT INTO payroll_tax_slabs (version_id,category,lower_bound,upper_bound,rate,sort_order) VALUES (?,?,?,?,?,?)');
foreach ([[0,500000,1],[500000,null,10]] as $i=>[$lo,$hi,$r]) { $slab->execute([$vid,'individual',$lo,$hi,$r,$i]); }
$uid=(int) db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
db()->prepare("INSERT INTO payroll_employees (company_id,user_id,employee_code,department,designation,pan_no,bank_name,bank_account,marital_status,retirement_scheme,retirement_employee_rate,retirement_employer_rate,basic_salary,status,joined_on) VALUES (:cid,:uid,'E001','Ops','Officer','601','NIC','012','individual','none',0,0,50000,'active','2026-07-16')")->execute(['cid'=>$cid,'uid'=>$uid]);

echo "Auto-post OFF: run reaches paid without an accrual\n";
db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,pay_date,created_by) VALUES (:cid,:fy,1,:l,:pay,:by)')->execute(['cid'=>$cid,'fy'=>$fyId,'l'=>'PA M1','pay'=>'2026-07-30','by'=>$uid]);
$runId=(int) db()->lastInsertId();
payroll_calculate_run($runId);
$ap = payroll_approve_and_post($runId, $uid + 1);
ok($ap['ok'] && (int)($ap['voucher_id'] ?? 0) === 0, 'Approve with auto-post off returns no accrual voucher');
ok((string) payroll_run($runId)['status'] === 'approved', 'Status is approved (not posted)');
ok((int) (payroll_run($runId)['accrual_voucher_id'] ?? 0) === 0, 'accrual_voucher_id is NULL (the bug: nothing accrued)');
payroll_record_payment($runId, $uid, '2026-07-30');
ok((string) payroll_run($runId)['status'] === 'paid', 'Run reaches PAID with only a payment voucher');
ok((int) (payroll_run($runId)['accrual_voucher_id'] ?? 0) === 0, 'Still NO accrual voucher on a paid run');

echo "\nRecovery: payroll_post_accrual posts the missing accrual\n";
$res = payroll_post_accrual($runId, $uid);
ok(!empty($res['ok']) && (int)$res['voucher_id'] > 0, 'payroll_post_accrual posts the accrual voucher');
$av = (int) (payroll_run($runId)['accrual_voucher_id'] ?? 0);
ok($av > 0, 'Run now records its accrual_voucher_id');
$dr = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END),0) FROM voucher_entries WHERE voucher_id=$av")->fetchColumn();
$cr = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END),0) FROM voucher_entries WHERE voucher_id=$av")->fetchColumn();
ok(near($dr,$cr) && $dr > 0, 'Accrual voucher balances (Dr = Cr)');
$salExpDr = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END),0) FROM voucher_entries WHERE voucher_id=$av AND ledger_id=$lSE")->fetchColumn();
ok(near($salExpDr, 50000), 'Salary expense debited with gross 50,000');
$dup = payroll_post_accrual($runId, $uid);
ok(empty($dup['ok']) && str_contains((string)($dup['error'] ?? ''), 'already'), 'Second post_accrual is rejected (idempotent)');

pa_cleanup();
echo "\n----------------------------------------\nPASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
