<?php
declare(strict_types=1);

/**
 * Flexible pay components: per-component ledger mapping, suggestions vs actual
 * amounts, one-time components, missing-mapping blocks, component-mapped
 * compound journal, employer contributions, posted-run immutability, reversal,
 * tenant isolation and permission enforcement.
 *   php database/test_payroll_flexible_components.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/payroll_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.011; }

function pfc_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('PFCTESTA','PFCTESTB')")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM payroll_run_components WHERE run_id IN (SELECT id FROM payroll_runs WHERE company_id=$s)");
        db()->exec("DELETE FROM payroll_service_charge_runs WHERE company_id=$s");
        db()->exec("DELETE FROM payroll_overtime_entries WHERE company_id=$s");
        db()->exec("DELETE FROM payroll_overtime_weeks WHERE company_id=$s");
        db()->exec("DELETE rl FROM payroll_run_lines rl JOIN payroll_runs r ON r.id=rl.run_id WHERE r.company_id=$s");
        db()->exec("DELETE FROM payroll_runs WHERE company_id=$s");
        db()->exec("DELETE t FROM payroll_loan_txns t JOIN payroll_loans l ON l.id=t.loan_id JOIN payroll_employees pe ON pe.id=l.payroll_employee_id WHERE pe.company_id=$s");
        db()->exec("DELETE l FROM payroll_loans l JOIN payroll_employees pe ON pe.id=l.payroll_employee_id WHERE pe.company_id=$s");
        db()->exec("DELETE pec FROM payroll_employee_components pec JOIN payroll_employees pe ON pe.id=pec.payroll_employee_id WHERE pe.company_id=$s");
        db()->exec("DELETE FROM payroll_employees WHERE company_id=$s");
        db()->exec("DELETE FROM payroll_settings WHERE company_id=$s");
        db()->exec("DELETE FROM payroll_components WHERE company_id=$s");
        db()->exec("DELETE ts FROM payroll_tax_slabs ts JOIN payroll_tax_versions v ON v.id=ts.version_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM payroll_tax_versions WHERE company_id=$s");
        db()->exec("DELETE ve FROM voucher_entries ve JOIN vouchers v ON v.id=ve.voucher_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email LIKE 'pfctest-%@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM staff_permissions WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
pfc_cleanup();

// ---------------------------------------------------------------------------
// Fixture: company A with control + per-component ledgers, tax slabs, and two
// employees (one login-less, one linked).
// ---------------------------------------------------------------------------
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Flexible Comp Test A','PFCTESTA',1)")->execute();
$cid = (int) db()->lastInsertId();
$_SESSION['company_id'] = $cid;
$mkG = static function (string $m, string $c, string $n, int $cash = 0) use ($cid): int { db()->prepare('INSERT INTO ledger_groups (company_id,master_key,code,name,is_cash_or_bank) VALUES (?,?,?,?,?)')->execute([$cid,$m,$c,$n,$cash]); return (int) db()->lastInsertId(); };
$mkL = static function (int $g, string $c, string $n, string $t) use ($cid): int { db()->prepare("INSERT INTO ledgers (company_id,group_id,code,name,type,status) VALUES (?,?,?,?,?, 'active')")->execute([$cid,$g,$c,$n,$t]); return (int) db()->lastInsertId(); };
$gE = $mkG('indirect_expense','PFC-EXP','Emp Exp'); $gL = $mkG('current_liability','PFC-LIAB','Emp Pay'); $gA = $mkG('current_asset','PFC-ADV','Adv'); $gB = $mkG('current_asset','PFC-BANK','Bank',1);
$lSE=$mkL($gE,'SAL-EXP','Salary Expense','expense'); $lEE=$mkL($gE,'SSF-EXP','Employer Contrib Exp','expense'); $lTD=$mkL($gL,'TDS-PAY','TDS Pay','liability');
$lRP=$mkL($gL,'RET-PAY','Ret Pay','liability'); $lSP=$mkL($gL,'SAL-PAY','Salary Payable','liability'); $lAD=$mkL($gA,'EMP-ADV','Emp Advance','asset'); $lBK=$mkL($gB,'CASH','Bank','asset');
// Component-specific ledgers (test: different components -> different ledgers).
$lHOUS=$mkL($gE,'HOUS-EXP','Housing Allowance Expense','expense');
$lFEST=$mkL($gE,'FEST-EXP','Festival Allowance Expense','expense');
$lCANT=$mkL($gL,'CANT-PAY','Canteen Recovery Payable','liability');
$lGRAT=$mkL($gE,'GRAT-EXP','Gratuity Expense','expense');
$lGRPY=$mkL($gL,'GRAT-PAY','Gratuity Payable','liability');

$fy = create_fiscal_year($cid,'PFC 2026/27','2026-07-16','2027-07-15',true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId=(int)$fy['id']; $_SESSION['fiscal_year_id']=$fyId;
payroll_settings($cid);
db()->prepare('UPDATE payroll_settings SET salary_expense_ledger_id=:se,employer_contrib_expense_ledger_id=:ee,tds_payable_ledger_id=:tds,retirement_payable_ledger_id=:rp,salary_payable_ledger_id=:sp,advance_ledger_id=:adv,bank_ledger_id=:bank,auto_post=1,enforce_sod=0 WHERE company_id=:cid')
    ->execute(['se'=>$lSE,'ee'=>$lEE,'tds'=>$lTD,'rp'=>$lRP,'sp'=>$lSP,'adv'=>$lAD,'bank'=>$lBK,'cid'=>$cid]);
db()->prepare("INSERT INTO payroll_tax_versions (company_id,fiscal_year_id,label,legal_reference,status,effective_from,retirement_limit_pct,retirement_limit_cap,ssf_first_slab_exempt,rounding) VALUES (:cid,:fy,'PFC','DEMO','published','2026-07-16',33.33,500000,1,'nearest')")->execute(['cid'=>$cid,'fy'=>$fyId]);
$vid=(int) db()->lastInsertId(); $slab=db()->prepare('INSERT INTO payroll_tax_slabs (version_id,category,lower_bound,upper_bound,rate,sort_order) VALUES (?,?,?,?,?,?)');
foreach ([[0,500000,1],[500000,null,10]] as $i=>[$lo,$hi,$r]) { $slab->execute([$vid,'individual',$lo,$hi,$r,$i]); }

db()->prepare("INSERT INTO payroll_employees (company_id,user_id,full_name,employee_code,marital_status,retirement_scheme,basic_salary,status,joined_on) VALUES (:cid,NULL,'Worker One','E001','individual','none',50000,'active','2026-07-16')")->execute(['cid'=>$cid]);
$e1 = (int) db()->lastInsertId();
db()->prepare("INSERT INTO payroll_employees (company_id,user_id,full_name,employee_code,marital_status,retirement_scheme,basic_salary,status,joined_on) VALUES (:cid,NULL,'Worker Two','E002','individual','none',40000,'active','2026-07-16')")->execute(['cid'=>$cid]);
$e2 = (int) db()->lastInsertId();
$actorId = (int) db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();

// Components with their own accounting treatment.
$mkC = static function (array $f) use ($cid): int {
    $defaults = ['company_id'=>$cid,'code'=>'','name'=>'','category'=>'allowance','posting_behaviour'=>'category_default','calc_type'=>'manual',
        'default_value'=>0,'percentage'=>null,'debit_ledger_id'=>null,'credit_ledger_id'=>null,'employer_expense_ledger_id'=>null,
        'contribution_payable_ledger_id'=>null,'taxable'=>1,'include_in_gross'=>1,'include_in_net'=>1,'allow_zero'=>1,'active'=>1,'sort_order'=>0];
    $f += $defaults;
    db()->prepare('INSERT INTO payroll_components (company_id,code,name,category,posting_behaviour,calc_type,default_value,percentage,debit_ledger_id,credit_ledger_id,employer_expense_ledger_id,contribution_payable_ledger_id,taxable,include_in_gross,include_in_net,allow_zero,active,sort_order)
        VALUES (:company_id,:code,:name,:category,:posting_behaviour,:calc_type,:default_value,:percentage,:debit_ledger_id,:credit_ledger_id,:employer_expense_ledger_id,:contribution_payable_ledger_id,:taxable,:include_in_gross,:include_in_net,:allow_zero,:active,:sort_order)')
        ->execute(array_intersect_key($f, $defaults));
    return (int) db()->lastInsertId();
};
$cHOUS = $mkC(['code'=>'HOUS','name'=>'Housing Allowance','category'=>'allowance','posting_behaviour'=>'earning_expense','debit_ledger_id'=>$lHOUS,'calc_type'=>'fixed','default_value'=>5000]);
$cGRADE = $mkC(['code'=>'GRADE','name'=>'Grade Allowance','category'=>'allowance','calc_type'=>'percent_basic','percentage'=>10]); // no own ledger -> control fallback
$cFEST = $mkC(['code'=>'FEST','name'=>'Festival Allowance','category'=>'allowance','posting_behaviour'=>'earning_expense','debit_ledger_id'=>$lFEST,'calc_type'=>'manual']);
$cCANT = $mkC(['code'=>'CANT','name'=>'Canteen Recovery','category'=>'deduction','posting_behaviour'=>'deduction_liability','credit_ledger_id'=>$lCANT,'calc_type'=>'fixed','default_value'=>1000,'taxable'=>0]);
$cGRAT = $mkC(['code'=>'GRAT','name'=>'Gratuity (employer)','category'=>'employer_contribution','posting_behaviour'=>'employer_contribution','employer_expense_ledger_id'=>$lGRAT,'contribution_payable_ledger_id'=>$lGRPY,'calc_type'=>'fixed','default_value'=>2000,'taxable'=>0]);
$cINFO = $mkC(['code'=>'INFO','name'=>'Meal Card (info only)','category'=>'info','posting_behaviour'=>'non_posting','calc_type'=>'fixed','default_value'=>750,'taxable'=>0]);

// Employee-specific suggestions: SAME component, DIFFERENT amounts.
db()->prepare('INSERT INTO payroll_employee_components (payroll_employee_id,component_id,amount) VALUES (?,?,?)')->execute([$e1,$cHOUS,8000]);
db()->prepare('INSERT INTO payroll_employee_components (payroll_employee_id,component_id,amount) VALUES (?,?,?)')->execute([$e2,$cHOUS,3000]);

echo "Component resolution and suggestions\n";
db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,pay_date,created_by) VALUES (:cid,:fy,1,:l,:pay,:by)')
    ->execute(['cid'=>$cid,'fy'=>$fyId,'l'=>'PFC M1','pay'=>'2026-07-30','by'=>$actorId]);
$run1 = (int) db()->lastInsertId();
$calc = payroll_calculate_run($run1);
ok(!empty($calc['ok']), 'Run 1 calculates');
$rows1 = payroll_run_component_rows($run1, $e1);
$byCode = static function (array $rows, string $code): ?array { foreach ($rows as $r) { if ((string) $r['component_code'] === $code) { return $r; } } return null; };
ok(near((float) ($byCode($rows1, 'HOUS')['amount'] ?? 0), 8000), 'E001 housing suggestion 8,000 (employee-specific)');
ok(near((float) ($byCode(payroll_run_component_rows($run1, $e2), 'HOUS')['amount'] ?? 0), 3000), 'E002 housing 3,000 — same component, different amount per employee');
ok(near((float) ($byCode($rows1, 'GRADE')['amount'] ?? 0), 5000), 'Grade allowance suggested at 10% of basic (5,000)');
ok(near((float) ($byCode($rows1, 'CANT')['amount'] ?? 0), 1000), 'Canteen deduction suggested at 1,000');
ok($byCode($rows1, 'FEST') === null, 'Manual-only component has NO automatic suggestion');
$line1 = payroll_run_lines($run1)[0];
ok(near((float) $line1['gross'], 50000 + 8000 + 5000), 'Gross = basic + housing + grade (info & employer components excluded)');

echo "\nPeriod overrides: change, zero, one-time — with audit\n";
$noReason = payroll_set_run_component($run1, $e1, $cHOUS, 9500.0, '', $actorId);
ok(empty($noReason['ok']), 'Changing a suggested amount WITHOUT a reason is refused');
$ovr = payroll_set_run_component($run1, $e1, $cHOUS, 9500.0, 'Rent revision', $actorId);
ok(!empty($ovr['ok']), 'Override with reason accepted');
$housRow = $byCode(payroll_run_component_rows($run1, $e1), 'HOUS');
ok(near((float) $housRow['amount'], 9500) && near((float) $housRow['suggested_amount'], 8000)
    && (string) $housRow['override_reason'] === 'Rent revision' && (int) $housRow['updated_by'] === $actorId,
    'Row stores suggested 8,000 vs actual 9,500 with reason and actor');
$zero = payroll_set_run_component($run1, $e1, $cCANT, 0.0, 'Waived this month', $actorId);
ok(!empty($zero['ok']) && near((float) $byCode(payroll_run_component_rows($run1, $e1), 'CANT')['amount'], 0), 'Zero amount accepted (allow_zero)');
$oneTime = payroll_set_run_component($run1, $e1, $cFEST, 25000.0, 'Dashain festival', $actorId, true);
ok(!empty($oneTime['ok']), 'One-time festival allowance added for this month only');
$recalc = payroll_calculate_run($run1);
$festRow = $byCode(payroll_run_component_rows($run1, $e1), 'FEST');
$housRow = $byCode(payroll_run_component_rows($run1, $e1), 'HOUS');
ok(!empty($recalc['ok']) && $festRow !== null && near((float) $festRow['amount'], 25000)
    && near((float) $housRow['amount'], 9500), 'Recalculate PRESERVES the one-time line and the override');
$line1 = payroll_run_lines($run1)[0];
ok(near((float) $line1['gross'], 50000 + 9500 + 5000 + 25000), 'Gross uses ACTUAL amounts (zeroed canteen, one-time festival)');

echo "\nComponent-mapped compound journal\n";
$settings = payroll_settings($cid);
$entries = payroll_accrual_entries(payroll_run($run1), $settings);
$sumFor = static function (array $entries, int $ledger, string $type): float {
    $t = 0.0; foreach ($entries as $e) { if ((int) $e['ledger_id'] === $ledger && $e['entry_type'] === $type) { $t += $e['amount']; } } return $t;
};
$dr = 0.0; $cr = 0.0;
foreach ($entries as $e) { if ($e['entry_type'] === 'debit') { $dr += $e['amount']; } else { $cr += $e['amount']; } }
ok(near($dr, $cr) && $dr > 0, "Compound journal balances (Dr $dr = Cr $cr)");
ok(near($sumFor($entries, $lHOUS, 'debit'), 9500 + 3000), 'Housing posts to ITS OWN expense ledger (both employees)');
ok(near($sumFor($entries, $lFEST, 'debit'), 25000), 'Festival allowance posts to its own ledger');
ok(near($sumFor($entries, $lCANT, 'credit'), 1000), 'Canteen recovery credits its own liability (E002 only; E001 zeroed)');
ok(near($sumFor($entries, $lGRAT, 'debit'), 4000) && near($sumFor($entries, $lGRPY, 'credit'), 4000), 'Employer contribution: Dr mapped expense / Cr mapped payable (2 employees x 2,000)');
$grossAll = (float) payroll_run($run1)['total_gross'];
ok(near($sumFor($entries, $lSE, 'debit'), $grossAll - 9500 - 3000 - 25000), 'Salary-expense control keeps only the UNMAPPED earnings (basic + grade)');
$zeroLines = array_filter($entries, static fn (array $e): bool => $e['amount'] <= 0.004);
ok($zeroLines === [], 'No zero-value journal lines');
$infoPosted = false;
foreach (payroll_run_component_rows($run1) as $r) { if ($r['component_code'] === 'INFO' && (float) $r['amount'] > 0) { $infoPosted = true; } }
ok(near($sumFor($entries, $lSE, 'debit') + $sumFor($entries, $lHOUS, 'debit') + $sumFor($entries, $lFEST, 'debit'), $grossAll), 'Info/non-posting components never create accounting lines');

echo "\nMissing component mapping blocks posting, not preparation\n";
$cBROKEN = $mkC(['code'=>'BROKEN','name'=>'Custom Broken','category'=>'allowance','posting_behaviour'=>'custom','calc_type'=>'manual']);
$add = payroll_set_run_component($run1, $e2, $cBROKEN, 1234.0, 'Testing missing mapping', $actorId, true);
ok(!empty($add['ok']), 'Preparation accepts the amount even though the mapping is missing');
$validation = payroll_validate_run(payroll_run($run1), $settings, $actorId);
$hasMappingError = false;
foreach ($validation['errors'] as $err) { if (str_contains($err, 'Custom Broken')) { $hasMappingError = true; } }
ok($hasMappingError, 'Approval/posting is blocked with the component named in the error');
$blocked = payroll_approve_and_post($run1, $actorId);
ok(empty($blocked['ok']) && str_contains((string) $blocked['error'], 'Custom Broken'), 'Posting refuses while the mapping is missing');
$fix = payroll_set_run_component($run1, $e2, $cBROKEN, 0.0, 'Removed pending mapping', $actorId, false, true);
ok(!empty($fix['ok']), 'Removing the unmapped line clears the block');

echo "\nAdvance recovery + posting + payment + immutability + reversal\n";
db()->prepare("INSERT INTO payroll_loans (company_id,payroll_employee_id,title,principal,balance,monthly_installment,status,issued_on) VALUES (:cid,:pe,'Advance',9000,9000,3000,'active','2026-07-20')")
    ->execute(['cid'=>$cid,'pe'=>$e1]);
payroll_calculate_run($run1);
$posted = payroll_approve_and_post($run1, $actorId);
ok(!empty($posted['ok']) && (int) $posted['voucher_id'] > 0, 'Run approves and posts the compound accrual voucher');
$voucherId = (int) $posted['voucher_id'];
$vDr = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END),0) FROM voucher_entries WHERE voucher_id=$voucherId")->fetchColumn();
$vCr = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END),0) FROM voucher_entries WHERE voucher_id=$voucherId")->fetchColumn();
ok(near($vDr, $vCr), 'Posted voucher balances in the books');
$advCr = (float) db()->query("SELECT COALESCE(SUM(amount),0) FROM voucher_entries WHERE voucher_id=$voucherId AND ledger_id=$lAD AND entry_type='credit'")->fetchColumn();
ok(near($advCr, 3000), 'Employee advance recovery credits the advance ledger (3,000 installment)');
$lockedEdit = payroll_set_run_component($run1, $e1, $cHOUS, 111.0, 'must fail', $actorId);
ok(empty($lockedEdit['ok']), 'Posted payroll is immutable — component edits are refused');
$lockedCalc = payroll_calculate_run($run1);
ok(empty($lockedCalc['ok']), 'Posted payroll cannot be recalculated');
$dupPost = payroll_approve_and_post($run1, $actorId);
ok(empty($dupPost['ok']), 'Payroll cannot be posted twice');
$pay = payroll_record_payment($run1, $actorId, '2026-07-31');
ok(!empty($pay['ok']), 'Salary payment voucher posts (Dr Salary Payable / Cr Bank)');
$pvId = (int) payroll_run($run1)['payment_voucher_id'];
$pvBank = (float) db()->query("SELECT COALESCE(SUM(amount),0) FROM voucher_entries WHERE voucher_id=$pvId AND ledger_id=$lBK AND entry_type='credit'")->fetchColumn();
ok(near($pvBank, (float) payroll_run($run1)['total_net']), 'Bank credited with exactly the net pay total');
$badDate = payroll_record_payment($run1, $actorId, '2031-01-01');
ok(empty($badDate['ok']), 'A payment date outside every fiscal year is refused');

$reopen = payroll_reopen_run($run1, $actorId, 'Correction needed for component test');
ok(!empty($reopen['ok']) && (int) $reopen['reversed_vouchers'] === 2, 'Reversal (reopen) reverses BOTH vouchers of the run');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid AND source_type IN ('payroll_run','payroll_payment') AND source_id=$run1")->fetchColumn() === 0,
    'Every original ledger line of the run is gone from the books');
$loanBal = (float) db()->query("SELECT balance FROM payroll_loans WHERE payroll_employee_id=$e1")->fetchColumn();
ok(near($loanBal, 9000), 'Advance balance restored by the reversal');
$rows1 = payroll_run_component_rows($run1, $e1);
ok(near((float) ($byCode($rows1, 'HOUS')['amount'] ?? 0), 9500), 'Component snapshot survives the reversal untouched');

echo "\nSnapshot isolation: master edits never change stored payroll\n";
db()->prepare('UPDATE payroll_components SET debit_ledger_id=:l, default_value=99999 WHERE id=:id')->execute(['l'=>$lFEST,'id'=>$cHOUS]);
$housAfter = $byCode(payroll_run_component_rows($run1, $e1), 'HOUS');
ok((int) $housAfter['debit_ledger_id'] === $lHOUS && near((float) $housAfter['amount'], 9500),
    'Stored snapshot keeps the ORIGINAL ledger and amount after the master changed');
db()->prepare('UPDATE payroll_components SET debit_ledger_id=:l, default_value=5000 WHERE id=:id')->execute(['l'=>$lHOUS,'id'=>$cHOUS]);

echo "\nMonth-to-month difference for the same employee\n";
db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,pay_date,created_by) VALUES (:cid,:fy,2,:l,:pay,:by)')
    ->execute(['cid'=>$cid,'fy'=>$fyId,'l'=>'PFC M2','pay'=>'2026-08-30','by'=>$actorId]);
$run2 = (int) db()->lastInsertId();
payroll_calculate_run($run2);
$housM2 = $byCode(payroll_run_component_rows($run2, $e1), 'HOUS');
ok(near((float) $housM2['amount'], 8000), 'Month 2 returns to the standing suggestion (8,000) — month 1 kept its own 9,500');
$festM2 = $byCode(payroll_run_component_rows($run2, $e1), 'FEST');
ok($festM2 === null, 'The one-time festival allowance does NOT repeat next month');

echo "\nTenant isolation\n";
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Flexible Comp Test B','PFCTESTB',1)")->execute();
$cidB = (int) db()->lastInsertId();
$fyB = create_fiscal_year($cidB,'PFC-B 2026/27','2026-07-16','2027-07-15',true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fyB['id']]);
ok(payroll_company_employees($cidB) === [], 'Company B sees NO employees of company A');
ok(payroll_component_catalog($cidB) === [], 'Company B sees NO components of company A');
db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,created_by) VALUES (:cid,:fy,1,:l,:by)')
    ->execute(['cid'=>$cidB,'fy'=>(int)$fyB['id'],'l'=>'B M1','by'=>$actorId]);
$runB = (int) db()->lastInsertId();
$cross = payroll_set_run_component($runB, $e1, $cHOUS, 5000.0, 'cross-tenant attempt', $actorId, true);
ok(empty($cross['ok']), 'Company B\'s run refuses company A\'s employee/component');

echo "\nPermission enforcement (staff grants)\n";
$staffId = create_user(['name'=>'PFC Staff','email'=>'pfctest-staff@test.local','password'=>'Secret#12345','role'=>'staff','status'=>'active','company_id'=>$cid]);
$staffUser = ['id'=>$staffId,'role'=>'staff','company_id'=>$cid];
ok(user_can_do('payroll', 'approve', $staffUser), 'Unconfigured staff keeps legacy full access');
set_staff_permissions($staffId, ['payroll.view', 'payroll.create'], $actorId);
ok(user_can_do('payroll', 'view', $staffUser) && user_can_do('payroll', 'create', $staffUser), 'Granted payroll view/create pass');
ok(!user_can_do('payroll', 'approve', $staffUser) && !user_can_do('payroll', 'post', $staffUser), 'Ungranted approve/post are DENIED in strict mode');
set_staff_permissions($staffId, ['payroll.view', 'payroll.approve', 'payroll.adjust'], $actorId);
ok(user_can_do('payroll', 'approve', $staffUser) && user_can_do('payroll', 'adjust', $staffUser), 'New adjust/approve payroll actions are grantable');

echo "\nRegister / payslip component detail\n";
$trace1 = json_decode((string) payroll_run_lines($run1)[0]['trace'], true) ?: [];
$traceLabels = array_map(static fn (array $c): string => (string) ($c['label'] ?? ''), (array) ($trace1['components'] ?? []));
ok(in_array('Housing Allowance', $traceLabels, true) && in_array('Festival Allowance', $traceLabels, true),
    'Line trace itemizes each component for the payslip');
require_once __DIR__ . '/../app/reports_engine.php';
$report = rc_generate('salary-sheet', $cid, '2026-07-16', '2026-08-15', ['currency' => '', 'payroll_run' => $run1, 'fy_start' => '2026-07-16']);
$columnLabels = array_map(static fn (array $c): string => (string) $c[0], (array) ($report['columns'] ?? []));
$hasHousingColumn = false;
foreach ($columnLabels as $columnLabel) { if (str_contains($columnLabel, 'Housing')) { $hasHousingColumn = true; } }
ok($hasHousingColumn, 'Salary Sheet register grows a dynamic column per used component');

pfc_cleanup();
echo "\n----------------------------------------\n";
echo 'PASS: ' . $pass . '   FAIL: ' . $fail . "\n";
exit($fail === 0 ? 0 : 1);
