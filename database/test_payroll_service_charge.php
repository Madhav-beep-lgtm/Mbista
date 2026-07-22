<?php
declare(strict_types=1);

/**
 * Service charge: employer-declared total split 68% employees / 32% employer;
 * equal, days-worked and manual allocation; rounding lands on the last
 * employee; the allocation is taxable remuneration; the employer share never
 * touches employee pay or Salary Payable; eligibility is enforced.
 *   php database/test_payroll_service_charge.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/payroll_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.011; }

function psc_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code='PSCTEST'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM payroll_run_components WHERE run_id IN (SELECT id FROM payroll_runs WHERE company_id=$s)");
        db()->exec("DELETE FROM payroll_service_charge_runs WHERE company_id=$s");
        db()->exec("DELETE FROM payroll_overtime_entries WHERE company_id=$s");
        db()->exec("DELETE FROM payroll_overtime_weeks WHERE company_id=$s");
        db()->exec("DELETE rl FROM payroll_run_lines rl JOIN payroll_runs r ON r.id=rl.run_id WHERE r.company_id=$s");
        db()->exec("DELETE FROM payroll_runs WHERE company_id=$s");
        db()->exec("DELETE pec FROM payroll_employee_components pec JOIN payroll_employees pe ON pe.id=pec.payroll_employee_id WHERE pe.company_id=$s");
        db()->exec("DELETE FROM payroll_employees WHERE company_id=$s");
        db()->exec("DELETE FROM payroll_settings WHERE company_id=$s");
        db()->exec("DELETE FROM payroll_components WHERE company_id=$s");
        db()->exec("DELETE ts FROM payroll_tax_slabs ts JOIN payroll_tax_versions v ON v.id=ts.version_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM payroll_tax_versions WHERE company_id=$s");
        db()->exec("DELETE FROM attendance WHERE company_id=$s");
        db()->exec("DELETE ve FROM voucher_entries ve JOIN vouchers v ON v.id=ve.voucher_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email LIKE 'psctest-%@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
psc_cleanup();

// ---------------------------------------------------------------------------
// Fixture: three eligible employees + one NOT eligible; SC component mapped to
// its own expense ledger.
// ---------------------------------------------------------------------------
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Service Charge Test Co','PSCTEST',1)")->execute();
$cid = (int) db()->lastInsertId();
$_SESSION['company_id'] = $cid;
$mkG = static function (string $m, string $c, string $n, int $cash = 0) use ($cid): int { db()->prepare('INSERT INTO ledger_groups (company_id,master_key,code,name,is_cash_or_bank) VALUES (?,?,?,?,?)')->execute([$cid,$m,$c,$n,$cash]); return (int) db()->lastInsertId(); };
$mkL = static function (int $g, string $c, string $n, string $t) use ($cid): int { db()->prepare("INSERT INTO ledgers (company_id,group_id,code,name,type,status) VALUES (?,?,?,?,?, 'active')")->execute([$cid,$g,$c,$n,$t]); return (int) db()->lastInsertId(); };
$gE = $mkG('indirect_expense','PSC-EXP','Exp'); $gL = $mkG('current_liability','PSC-LIAB','Liab'); $gA = $mkG('current_asset','PSC-ADV','Adv'); $gB = $mkG('current_asset','PSC-BANK','Bank',1);
$lSE=$mkL($gE,'SAL-EXP','Salary Expense','expense'); $lSC=$mkL($gE,'SC-EXP','Service Charge Expense','expense'); $lEE=$mkL($gE,'SSF-EXP','Employer Exp','expense');
$lTD=$mkL($gL,'TDS-PAY','TDS','liability'); $lRP=$mkL($gL,'RET-PAY','Ret','liability'); $lSP=$mkL($gL,'SAL-PAY','Sal Pay','liability');
$lAD=$mkL($gA,'EMP-ADV','Adv','asset'); $lBK=$mkL($gB,'CASH','Bank','asset');
$fy = create_fiscal_year($cid,'PSC 2026/27','2026-07-16','2027-07-15',true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId=(int)$fy['id']; $_SESSION['fiscal_year_id']=$fyId;
payroll_settings($cid);
db()->prepare("INSERT INTO payroll_tax_versions (company_id,fiscal_year_id,label,status,effective_from,retirement_limit_pct,retirement_limit_cap,ssf_first_slab_exempt,rounding) VALUES (:cid,:fy,'PSC','published','2026-07-16',33.33,500000,1,'nearest')")->execute(['cid'=>$cid,'fy'=>$fyId]);
$vid=(int) db()->lastInsertId();
$slab=db()->prepare('INSERT INTO payroll_tax_slabs (version_id,category,lower_bound,upper_bound,rate,sort_order) VALUES (?,?,?,?,?,?)');
foreach ([[0,500000,1],[500000,null,10]] as $i=>[$lo,$hi,$r]) { $slab->execute([$vid,'individual',$lo,$hi,$r,$i]); }
db()->prepare('INSERT INTO payroll_components (company_id,code,name,category,posting_behaviour,calc_type,debit_ledger_id,taxable,active) VALUES (?,?,?,?,?,?,?,1,1)')
    ->execute([$cid,'SC','Service Charge','allowance','earning_expense','service_charge',$lSC]);
$cSC = (int) db()->lastInsertId();
db()->prepare('UPDATE payroll_settings SET salary_expense_ledger_id=:se,employer_contrib_expense_ledger_id=:ee,tds_payable_ledger_id=:tds,retirement_payable_ledger_id=:rp,salary_payable_ledger_id=:sp,advance_ledger_id=:adv,bank_ledger_id=:bank,auto_post=1,enforce_sod=0,
        sc_component_id=:scc, sc_employee_pct=68, sc_employer_pct=32 WHERE company_id=:cid')
    ->execute(['se'=>$lSE,'ee'=>$lEE,'tds'=>$lTD,'rp'=>$lRP,'sp'=>$lSP,'adv'=>$lAD,'bank'=>$lBK,'scc'=>$cSC,'cid'=>$cid]);

$uD = create_user(['name'=>'SC Days Worker','email'=>'psctest-days@test.local','password'=>'Secret#12345','role'=>'staff','status'=>'active','company_id'=>$cid]);
db()->prepare("INSERT INTO payroll_employees (company_id,user_id,full_name,employee_code,marital_status,retirement_scheme,basic_salary,sc_eligible,status,joined_on) VALUES (:cid,NULL,'SC One','S001','individual','none',30000,1,'active','2026-07-16')")->execute(['cid'=>$cid]);
$s1 = (int) db()->lastInsertId();
db()->prepare("INSERT INTO payroll_employees (company_id,user_id,full_name,employee_code,marital_status,retirement_scheme,basic_salary,sc_eligible,status,joined_on) VALUES (:cid,NULL,'SC Two','S002','individual','none',30000,1,'active','2026-07-16')")->execute(['cid'=>$cid]);
$s2 = (int) db()->lastInsertId();
db()->prepare("INSERT INTO payroll_employees (company_id,user_id,full_name,employee_code,marital_status,retirement_scheme,basic_salary,sc_eligible,status,joined_on) VALUES (:cid,:uid,'SC Three','S003','individual','none',30000,1,'active','2026-07-16')")->execute(['cid'=>$cid,'uid'=>$uD]);
$s3 = (int) db()->lastInsertId();
db()->prepare("INSERT INTO payroll_employees (company_id,user_id,full_name,employee_code,marital_status,retirement_scheme,basic_salary,sc_eligible,status,joined_on) VALUES (:cid,NULL,'Not Eligible','S004','individual','none',30000,0,'active','2026-07-16')")->execute(['cid'=>$cid]);
$s4 = (int) db()->lastInsertId();
$actorId = (int) db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();

db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,pay_date,created_by) VALUES (:cid,:fy,1,:l,:pay,:by)')
    ->execute(['cid'=>$cid,'fy'=>$fyId,'l'=>'PSC M1','pay'=>'2026-08-14','by'=>$actorId]);
$run = (int) db()->lastInsertId();
payroll_calculate_run($run);
$runRow = payroll_run($run);
$netBefore = (float) $runRow['total_net'];
$grossBefore = (float) $runRow['total_gross'];

echo "68/32 split and equal allocation with rounding\n";
// Declared 1,000.01 -> pool 680.01 (68%), employer 320.00; 680.01 / 3 leaves a
// remainder the LAST employee must absorb.
$prep = payroll_sc_prepare($runRow, 1000.01, 'equal', [$s1, $s2, $s3], [], $actorId);
ok(!empty($prep['ok']), 'Equal allocation prepares');
$sc = payroll_sc_get($run);
ok(near((float) $sc['employee_pool'], 680.01) && near((float) $sc['employer_share'], 320.00), 'Pool 68% = 680.01, employer 32% = 320.00');
$alloc = payroll_sc_allocations((int) $sc['id']);
$amounts = array_map(static fn (array $a): float => (float) $a['amount'], $alloc);
ok(near($amounts[0], 226.67) && near($amounts[1], 226.67) && near($amounts[2], 226.67), 'Equal shares floor to 226.67 each');
ok(near(array_sum($amounts), 680.01), 'Rounding lands on the last employee: total EXACTLY equals the pool');

echo "\nEligibility enforcement\n";
$bad = payroll_sc_prepare($runRow, 1000.0, 'equal', [$s1, $s4], [], $actorId);
ok(empty($bad['ok']), 'A non-eligible employee cannot receive service charge');

echo "\nManual allocation must equal the pool exactly\n";
$badManual = payroll_sc_prepare($runRow, 1000.0, 'manual', [$s1, $s2], [$s1 => 400.0, $s2 => 200.0], $actorId);
ok(empty($badManual['ok']) && str_contains((string) $badManual['error'], '680.00'), 'Manual totals not equal to the 68% pool are refused');
$goodManual = payroll_sc_prepare($runRow, 1000.0, 'manual', [$s1, $s2], [$s1 => 400.0, $s2 => 280.0], $actorId);
ok(!empty($goodManual['ok']), 'Manual allocation equal to the pool is accepted');

echo "\nDays-worked allocation from attendance\n";
// Only S003 has a login/attendance identity: give S003 attendance, then the
// days method must refuse a mix with zero-day employees ONLY when total = 0.
foreach (['2026-07-20', '2026-07-21', '2026-07-22', '2026-07-23'] as $d) {
    db()->prepare('INSERT INTO attendance (company_id,staff_user_id,attendance_date,check_in_time,check_out_time) VALUES (:cid,:uid,:d,:in,:out)')
        ->execute(['cid'=>$cid,'uid'=>$uD,'d'=>$d,'in'=>$d.' 09:00:00','out'=>$d.' 17:00:00']);
}
$days = payroll_sc_prepare($runRow, 1000.0, 'days_worked', [$s3], [], $actorId);
ok(!empty($days['ok']), 'Days-worked allocation prepares from attendance');
$sc = payroll_sc_get($run);
$alloc = payroll_sc_allocations((int) $sc['id']);
ok(count($alloc) === 1 && near((float) $alloc[0]['amount'], 680.00) && near((float) $alloc[0]['eligible_days'], 4),
    'Sole worked employee takes the whole pool; eligible days stored for the report');

echo "\nApproval: taxable remuneration through payroll, employer share excluded\n";
$approve = payroll_sc_approve($run, $actorId);
ok(!empty($approve['ok']), 'Allocation approves and the run recalculates');
$scRowS3 = null;
foreach (payroll_run_component_rows($run, $s3) as $r) { if ((string) $r['source'] === 'service_charge') { $scRowS3 = $r; } }
ok($scRowS3 !== null && near((float) $scRowS3['amount'], 680.00) && (int) $scRowS3['taxable'] === 1,
    'Service charge lands as a TAXABLE run component');
$runAfter = payroll_run($run);
$lineS3 = null;
foreach (payroll_run_lines($run) as $l) { if ((int) $l['payroll_employee_id'] === $s3) { $lineS3 = $l; } }
ok(near((float) $lineS3['gross'], 30000 + 680.00), 'Employee gross grew by exactly the 68% allocation');
// Projected-tax rule: service charge is ACTUAL-ONLY income. The estimate is
// basic projected for the year PLUS the allocation counted once — never
// (basic + service charge) x 12.
ok(near((float) $lineS3['assessable_annual'], 30000 * 12 + 680.00), 'Assessable income = projected basic + service charge counted ONCE (not annualized)');
ok(near((float) $runAfter['total_gross'], $grossBefore + 680.00), 'Run gross grew by the employee pool ONLY — never the employer 32%');
$entries = payroll_accrual_entries($runAfter, payroll_settings($cid));
$scDr = 0.0; $spCr = 0.0; $dr = 0.0; $cr = 0.0;
foreach ($entries as $e) {
    if ($e['entry_type'] === 'debit') { $dr += $e['amount']; } else { $cr += $e['amount']; }
    if ((int) $e['ledger_id'] === $lSC && $e['entry_type'] === 'debit') { $scDr += $e['amount']; }
    if ((int) $e['ledger_id'] === $lSP && $e['entry_type'] === 'credit') { $spCr += $e['amount']; }
}
ok(near($scDr, 680.00), 'Journal debits the mapped Service Charge expense ledger with the employee pool');
ok(near($dr, $cr), 'Compound journal still balances with the service charge in');
ok($spCr < $grossBefore + 680.01 + 1 && near($spCr, (float) $runAfter['total_net']), 'Salary Payable credit contains net pay only — the employer 320 share is absent');
ok(near((float) $sc['employer_share'], 320.00), 'Employer share is stored for reporting');

echo "\nRe-prepare lock after approval + removal path\n";
$reprep = payroll_sc_prepare(payroll_run($run), 900.0, 'equal', [$s1], [], $actorId);
ok(empty($reprep['ok']), 'An approved allocation cannot be silently re-prepared');
$remove = payroll_sc_remove($run, $actorId);
ok(!empty($remove['ok']), 'Removal deletes the allocation and recalculates');
$runFinal = payroll_run($run);
ok(near((float) $runFinal['total_gross'], $grossBefore) && near((float) $runFinal['total_net'], $netBefore),
    'Run returns to its pre-service-charge totals after removal');

psc_cleanup();
echo "\n----------------------------------------\n";
echo 'PASS: ' . $pass . '   FAIL: ' . $fail . "\n";
exit($fail === 0 ? 0 : 1);
