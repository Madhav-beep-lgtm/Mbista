<?php
declare(strict_types=1);

/**
 * Suite for the 2026-07-19 payroll/compliance batch:
 *   - SST vs Remuneration tax split (calc, storage, accrual posting)
 *   - employee-scoped (single-staff) runs
 *   - government fines math on the BS calendar (VAT 15%+10%, duties 15%)
 *   - i18n t() fallback behaviour
 *   php database/test_payroll_sst_fines.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/payroll_engine.php';
require_once __DIR__ . '/../app/tax_fines_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $eps = 0.02): bool { return abs($a - $b) < $eps; }

function sf_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code='SSTTESTA'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
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
sf_cleanup();

echo "Schema (self-repair)\n";
ok(db()->query("SHOW COLUMNS FROM payroll_run_lines LIKE 'sst_month'")->fetchAll() !== [], 'payroll_run_lines.sst_month exists');
ok(db()->query("SHOW COLUMNS FROM payroll_settings LIKE 'sst_payable_ledger_id'")->fetchAll() !== [], 'payroll_settings.sst_payable_ledger_id exists');
ok(db()->query("SHOW COLUMNS FROM payroll_runs LIKE 'employee_scope'")->fetchAll() !== [], 'payroll_runs.employee_scope exists');

db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('SST Test Co','SSTTESTA',1)")->execute();
$cid = (int) db()->lastInsertId();
$_SESSION['company_id'] = $cid;
$mkG = static function (string $m, string $c, string $n, int $cash = 0) use ($cid): int { db()->prepare('INSERT INTO ledger_groups (company_id,master_key,code,name,is_cash_or_bank) VALUES (?,?,?,?,?)')->execute([$cid,$m,$c,$n,$cash]); return (int) db()->lastInsertId(); };
$mkL = static function (int $g, string $c, string $n, string $t) use ($cid): int { db()->prepare("INSERT INTO ledgers (company_id,group_id,code,name,type,status) VALUES (?,?,?,?,?, 'active')")->execute([$cid,$g,$c,$n,$t]); return (int) db()->lastInsertId(); };
$gE=$mkG('indirect_expense','SS-EXP','Exp'); $gL=$mkG('current_liability','SS-LIAB','Duties & Taxes'); $gA=$mkG('current_asset','SS-AST','Assets'); $gB=$mkG('current_asset','SS-BANK','Bank',1);
$lSE=$mkL($gE,'SAL-EXP','Salary Expense','expense'); $lEE=$mkL($gE,'SSF-EXP','Employer Exp','expense');
$lTD=$mkL($gL,'REM-TAX','Remuneration Tax Payable','liability'); $lSST=$mkL($gL,'SST-PAY','Social Security Tax Payable','liability');
$lRP=$mkL($gL,'RET-PAY','Ret Pay','liability'); $lSP=$mkL($gL,'SAL-PAY','Salary Payable','liability');
$lAD=$mkL($gA,'EMP-ADV','Adv','asset'); $lBK=$mkL($gB,'CASH','Bank','asset');
$fy = create_fiscal_year($cid,'SST 2026/27','2026-07-16','2027-07-15',true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId=(int)$fy['id']; $_SESSION['fiscal_year_id']=$fyId;
payroll_settings($cid);
db()->prepare('UPDATE payroll_settings SET salary_expense_ledger_id=:se,employer_contrib_expense_ledger_id=:ee,tds_payable_ledger_id=:tds,sst_payable_ledger_id=:sst,retirement_payable_ledger_id=:rp,salary_payable_ledger_id=:sp,advance_ledger_id=:adv,bank_ledger_id=:bank,auto_post=1,enforce_sod=0 WHERE company_id=:cid')
    ->execute(['se'=>$lSE,'ee'=>$lEE,'tds'=>$lTD,'sst'=>$lSST,'rp'=>$lRP,'sp'=>$lSP,'adv'=>$lAD,'bank'=>$lBK,'cid'=>$cid]);
db()->prepare("INSERT INTO payroll_tax_versions (company_id,fiscal_year_id,label,legal_reference,status,effective_from,retirement_limit_pct,retirement_limit_cap,ssf_first_slab_exempt,rounding) VALUES (:cid,:fy,'SST','DEMO','published','2026-07-16',33.33,500000,1,'none')")->execute(['cid'=>$cid,'fy'=>$fyId]);
$vid=(int) db()->lastInsertId(); $slab=db()->prepare('INSERT INTO payroll_tax_slabs (version_id,category,lower_bound,upper_bound,rate,sort_order) VALUES (?,?,?,?,?,?)');
foreach ([[0,500000,1],[500000,null,10]] as $i=>[$lo,$hi,$r]) { $slab->execute([$vid,'individual',$lo,$hi,$r,$i]); }
$uid=(int) db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
db()->exec("DELETE FROM users WHERE email='ssttest2@test.local'");
db()->prepare("INSERT INTO users (name, email, password_hash, role, status, company_id) VALUES ('SST Second', 'ssttest2@test.local', '', 'staff', 'active', :cid)")->execute(['cid' => $cid]);
$uid2 = (int) db()->lastInsertId();
$mkEmp = static function (string $code, float $basic, int $forUser) use ($cid): int {
    db()->prepare("INSERT INTO payroll_employees (company_id,user_id,employee_code,department,designation,pan_no,bank_name,bank_account,marital_status,retirement_scheme,retirement_employee_rate,retirement_employer_rate,basic_salary,status,joined_on) VALUES (:cid,:uid,:code,'Ops','Officer','601','NIC','012','individual','none',0,0,:basic,'active','2026-07-16')")
        ->execute(['cid'=>$cid,'uid'=>$forUser,'code'=>$code,'basic'=>$basic]);
    return (int) db()->lastInsertId();
};
$emp1 = $mkEmp('E001', 50000, $uid); // 600k/yr -> tax 5,000 (SST) + 10,000 (rem) = 15,000
$emp2 = $mkEmp('E002', 30000, $uid2); // 360k/yr -> tax 3,600 all SST (1% slab)

echo "\nSST / Remuneration split\n";
db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,pay_date,created_by) VALUES (:cid,:fy,1,:l,:pay,:by)')
    ->execute(['cid'=>$cid,'fy'=>$fyId,'l'=>'Shrawan','pay'=>'2026-08-15','by'=>$uid]);
$runId = (int) db()->lastInsertId();
$calc = payroll_calculate_run($runId);
ok(!empty($calc['ok']), 'Full run calculates');
$l1 = null; $l2 = null;
foreach (payroll_run_lines($runId) as $l) {
    if ((int) $l['payroll_employee_id'] === $emp1) { $l1 = $l; }
    if ((int) $l['payroll_employee_id'] === $emp2) { $l2 = $l; }
}
// E001: tax_month 15000/12 = 1250; SST share 5000/15000 -> 416.67
ok($l1 && near((float) $l1['tax_month'], 1250.0), 'E001 monthly tax 1,250');
ok($l1 && near((float) $l1['sst_month'], 416.67), 'E001 SST portion 416.67 (1% slab share)');
// E002: whole tax inside the 1% slab -> all SST: 3600/12 = 300
ok($l2 && near((float) $l2['tax_month'], 300.0) && near((float) $l2['sst_month'], 300.0), 'E002 tax 300 is entirely SST');
$ap = payroll_approve_and_post($runId, $uid);
$av = (int) ($ap['voucher_id'] ?? 0);
ok($av > 0, 'Accrual posts');
$sstCr = (float) db()->query("SELECT COALESCE(SUM(amount),0) FROM voucher_entries WHERE voucher_id=$av AND ledger_id=$lSST AND entry_type='credit'")->fetchColumn();
$remCr = (float) db()->query("SELECT COALESCE(SUM(amount),0) FROM voucher_entries WHERE voucher_id=$av AND ledger_id=$lTD AND entry_type='credit'")->fetchColumn();
ok(near($sstCr, 716.67), "SST ledger credited 716.67 (416.67 + 300) — got $sstCr");
ok(near($remCr, 833.33), "Remuneration tax ledger credited 833.33 — got $remCr");
$dr = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE -amount END),0) FROM voucher_entries WHERE voucher_id=$av")->fetchColumn();
ok(near($dr, 0.0), 'Accrual voucher still balances with the split');

echo "\nEmployee-scoped (single-staff) runs\n";
db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,pay_date,employee_scope,created_by) VALUES (:cid,:fy,2,:l,:pay,:scope,:by)')
    ->execute(['cid'=>$cid,'fy'=>$fyId,'l'=>'Bhadra','pay'=>'2026-09-15','scope'=>json_encode([$emp1]),'by'=>$uid]);
$scopedRunId = (int) db()->lastInsertId();
$calc2 = payroll_calculate_run($scopedRunId);
$scopedLines = payroll_run_lines($scopedRunId);
ok(!empty($calc2['ok']) && count($scopedLines) === 1 && (int) $scopedLines[0]['payroll_employee_id'] === $emp1,
    'Scoped run calculates ONLY the selected employee');
$calc2b = payroll_calculate_run($scopedRunId);
ok(!empty($calc2b['ok']) && count(payroll_run_lines($scopedRunId)) === 1, 'Scope survives recalculation');
ok(payroll_run_scope_ids(['employee_scope' => 'not-json']) === [] && payroll_run_scope_ids(['employee_scope' => null]) === [], 'Malformed/empty scope means full company');
$pageSrc = (string) file_get_contents(__DIR__ . '/../public_html/admin/payroll.php');
ok(str_contains($pageSrc, 'employee_ids') && str_contains($pageSrc, 'full-company run would pay those employees twice'),
    'UI: create-run has the employee picker and the double-pay overlap guard');

echo "\nGovernment fines — BS month counting\n";
$due = bs_to_ad(2083, 1, 10);
ok($due !== null, 'BS 2083-01-10 converts to AD (' . (string) $due . ')');
ok(fines_bs_months_between($due, $due) === 0, 'On the due date itself: 0 months');
ok(fines_bs_months_between($due, bs_to_ad(2083, 1, 11)) === 1, 'One day late: 1 month (part of month counts)');
ok(fines_bs_months_between($due, bs_to_ad(2083, 3, 9)) === 2, 'BS 2083-01-10 -> 2083-03-09: 2 months');
ok(fines_bs_months_between($due, bs_to_ad(2083, 3, 11)) === 3, 'BS 2083-01-10 -> 2083-03-11: 3 months (day past due day)');
ok(fines_bs_months_between($due, bs_to_ad(2084, 1, 10)) === 12, 'Exactly one BS year: 12 months');

echo "\nGovernment fines — charges\n";
$vat = fines_vat_charges(100000, $due, bs_to_ad(2083, 3, 9));
ok(near($vat['interest_15'], 2500.0), 'VAT interest: 100,000 x 15% x 2/12 = 2,500');
$expectedAdditional = round(100000 * 0.10 * $vat['days'] / 365, 2);
ok($vat['days'] > 0 && near($vat['additional_10'], $expectedAdditional), 'VAT additional duty 10% p.a. counted daily (' . $vat['days'] . ' days)');
ok(near($vat['total'], $vat['interest_15'] + $vat['additional_10']), 'VAT total = interest + additional');
$dut = fines_duties_interest(48000, $due, bs_to_ad(2083, 1, 11));
ok(near($dut['interest_15'], 600.0) && near($dut['total'], 600.0) && near($dut['additional_10'], 0.0),
    'Duties/SST: 48,000 one day late = 48,000 x 15% x 1/12 = 600, no additional duty');
ok(fines_vat_charges(100000, $due, $due)['total'] === 0.0, 'Paid on the due date: zero charge');

echo "\nFines post only via the explicit admin action\n";
$adminId = (int) db()->query("SELECT id FROM users WHERE role='admin' AND status='active' ORDER BY id LIMIT 1")->fetchColumn();
$_SESSION['user_id'] = $adminId;
$before = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid AND source_type='government_fine'")->fetchColumn();
ok($before === 0, 'Calculator alone posted NOTHING');
$post = fines_post_voucher($cid, $lSST, 600.0, 'Test fine posting', $adminId);
ok(!empty($post['ok']) && (int) $post['voucher_id'] > 0, 'Admin post creates the fine voucher');
$fineLegs = db()->query("SELECT ve.entry_type, ve.amount, l.code FROM voucher_entries ve JOIN ledgers l ON l.id=ve.ledger_id WHERE ve.voucher_id={$post['voucher_id']} ORDER BY ve.entry_type")->fetchAll(PDO::FETCH_ASSOC);
$hasFineDr = false; $hasTaxCr = false;
foreach ($fineLegs as $leg) {
    if ($leg['entry_type'] === 'debit' && str_contains((string) $leg['code'], 'FINES')) { $hasFineDr = true; }
    if ($leg['entry_type'] === 'credit' && (string) $leg['code'] === 'SST-PAY') { $hasTaxCr = true; }
}
ok($hasFineDr && $hasTaxCr, 'Dr Government Fines & Interest / Cr the tax payable ledger');

echo "\ni18n\n";
$_SESSION['app_lang'] = 'ne';
app_lang(true);
ok(t('Dashboard') === 'ड्यासबोर्ड', "t('Dashboard') in Nepali");
ok(t('A totally unknown sentence') === 'A totally unknown sentence', 'Unknown key falls back to English');
$_SESSION['app_lang'] = 'en';
app_lang(true);

sf_cleanup();
db()->exec("DELETE FROM users WHERE email='ssttest2@test.local'");
echo "\n----------------------------------------\nPASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
