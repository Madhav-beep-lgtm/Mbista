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

echo "\nJournal display: a posted accrual renders from the books, not the preview\n";
// The page's journal query for a posted run — must return the real entries even
// though payroll_validate_run() reports "Run must be calculated" on paid runs
// (the approval-readiness false-positive that used to blank the journal).
$disp = db()->prepare("SELECT ledger_id, entry_type, amount, memo FROM voucher_entries
    WHERE voucher_id = :vid ORDER BY entry_type = 'credit', id");
$disp->execute(['vid' => $av]);
$dispRows = $disp->fetchAll(PDO::FETCH_ASSOC);
$dispDr = 0.0; $dispCr = 0.0;
foreach ($dispRows as $r) { if ($r['entry_type'] === 'debit') { $dispDr += (float) $r['amount']; } else { $dispCr += (float) $r['amount']; } }
ok($dispRows !== [] && near($dispDr, $dispCr) && $dispDr > 0, 'Posted-run journal query returns balanced, non-empty entries');
$val = payroll_validate_run(payroll_run($runId), payroll_settings($cid), $uid);
ok($val['errors'] !== [], 'Approval validation DOES error on a paid run (why the journal must not depend on it)');
$pageSrc = (string) file_get_contents(__DIR__ . '/../public_html/admin/payroll.php');
ok(strpos($pageSrc, "accrual_voucher_id'] ?? 0) > 0") !== false && strpos($pageSrc, 'FROM voucher_entries') !== false,
    'UI: journal section reads voucher_entries when the accrual voucher exists');
ok(strpos($pageSrc, "\$validation['errors'] === [])\n    ? payroll_accrual_entries") === false,
    'UI: journal is no longer gated on the approval-readiness validation');

echo "\nOrphan accrual voucher: post_accrual adopts it instead of dying on the unique key\n";
// Simulate the historical partial failure: voucher exists, run row unstamped.
db()->prepare('UPDATE payroll_runs SET accrual_voucher_id = NULL, posted_at = NULL WHERE id = :id')->execute(['id' => $runId]);
$adopt = payroll_post_accrual($runId, $uid);
ok(!empty($adopt['ok']) && (int) $adopt['voucher_id'] === $av, 'Orphan accrual is ADOPTED (same voucher, no duplicate insert)');
ok((int) (payroll_run($runId)['accrual_voucher_id'] ?? 0) === $av, 'Run is re-stamped with the orphan voucher id');
$vCount = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE source_type='payroll_run' AND source_id=$runId")->fetchColumn();
ok($vCount === 1, 'Exactly one accrual voucher exists after adoption');

echo "\nAdjustment 'Extra deduction' keeps the accrual balanced\n";
// A second run with a manual Extra deduction on its line: previously the
// journal lost the adj_deduction from the Salary Payable credit and the run
// could NEVER post ('Accrual journal does not balance').
db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,pay_date,created_by) VALUES (:cid,:fy,2,:l,:pay,:by)')
    ->execute(['cid'=>$cid,'fy'=>$fyId,'l'=>'PA M2','pay'=>'2026-08-30','by'=>$uid]);
$runId2 = (int) db()->lastInsertId();
payroll_calculate_run($runId2);
$line2 = payroll_run_lines($runId2)[0];
$adj = payroll_set_line_adjustment($runId2, (int) $line2['id'], 0.0, 5000.0, 'Canteen recovery');
ok(!empty($adj['ok']), 'Extra deduction of 5,000 saved on the line');
$run2 = payroll_run($runId2);
$settings2 = payroll_settings($cid);
$entries2 = payroll_accrual_entries($run2, $settings2);
$dr2 = 0.0; $cr2 = 0.0;
foreach ($entries2 as $e2) { if ($e2['entry_type'] === 'debit') { $dr2 += $e2['amount']; } else { $cr2 += $e2['amount']; } }
ok(near($dr2, $cr2) && $dr2 > 0, "Accrual entries balance WITH an Extra deduction (Dr $dr2 vs Cr $cr2)");
$ap2 = payroll_approve_and_post($runId2, $uid);
ok(!empty($ap2['ok']), 'Run with an Extra deduction now approves (was: does not balance)');
$pa2 = payroll_post_accrual($runId2, $uid);
ok(!empty($pa2['ok']) && (int) $pa2['voucher_id'] > 0, 'Accrual with an Extra deduction posts to the books');
// The 5,000 stays in Salary Payable until remitted: payable credit = net + other + adj.
$sp2 = 0.0;
foreach ($entries2 as $e2) { if ($e2['entry_type'] === 'credit' && (int) $e2['ledger_id'] === $lSP) { $sp2 = $e2['amount']; } }
$netSum2 = (float) db()->query("SELECT COALESCE(SUM(net_pay),0) FROM payroll_run_lines WHERE run_id=$runId2")->fetchColumn();
ok(near($sp2, $netSum2 + 5000), 'Salary Payable credit includes the withheld 5,000 (remitted separately)');

echo "\nPayment date in a fiscal year that does not exist yet -> actionable error\n";
$noFy = payroll_record_payment($runId2, $uid, '2028-01-15');
ok(empty($noFy['ok']) && str_contains((string) ($noFy['error'] ?? ''), 'fiscal year'), 'record_payment explains the missing fiscal year instead of a cryptic engine error');
ok((string) payroll_run($runId2)['status'] !== 'paid', 'Run is NOT marked paid when the payment voucher cannot post');

pa_cleanup();
echo "\n----------------------------------------\nPASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
