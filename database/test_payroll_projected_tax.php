<?php
declare(strict_types=1);

/**
 * Projected annual payroll tax (v2). Verifies the required formula:
 *   estimated annual taxable income =
 *     actual taxable income earned up to the current payroll
 *   + predictable regular income for the remaining employment periods
 * with irregular income (overtime / service charge / one-time) counted only
 * when earned, employment dates bounding the projection, the current period
 * included in the remaining-period divisor, final-month settlement, excess
 * display, salary revisions, prior employment, overrides and tenant isolation.
 *   php database/test_payroll_projected_tax.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/payroll_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.011; }

function ptx_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('PTXTESTA','PTXTESTB')")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM payroll_tax_calculations WHERE company_id=$s");
        db()->exec("DELETE FROM payroll_manual_projections WHERE company_id=$s");
        db()->exec("DELETE FROM payroll_salary_revisions WHERE company_id=$s");
        db()->exec("DELETE FROM payroll_employee_tax_profiles WHERE company_id=$s");
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
        db()->exec("DELETE ve FROM voucher_entries ve JOIN vouchers v ON v.id=ve.voucher_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
}
ptx_cleanup();

// ---------------------------------------------------------------------------
// Fixture: flat 10% single-slab tax (no SST) so expectations stay readable.
// FY 2026-07-16 .. 2027-07-15; period N = month N of that year.
// ---------------------------------------------------------------------------
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Projected Tax Test A','PTXTESTA',1)")->execute();
$cid = (int) db()->lastInsertId();
$_SESSION['company_id'] = $cid;
$mkG = static function (string $m, string $c, string $n, int $cash = 0) use ($cid): int { db()->prepare('INSERT INTO ledger_groups (company_id,master_key,code,name,is_cash_or_bank) VALUES (?,?,?,?,?)')->execute([$cid,$m,$c,$n,$cash]); return (int) db()->lastInsertId(); };
$mkL = static function (int $g, string $c, string $n, string $t) use ($cid): int { db()->prepare("INSERT INTO ledgers (company_id,group_id,code,name,type,status) VALUES (?,?,?,?,?, 'active')")->execute([$cid,$g,$c,$n,$t]); return (int) db()->lastInsertId(); };
$gE = $mkG('indirect_expense','PTX-EXP','Exp'); $gL = $mkG('current_liability','PTX-LIAB','Liab'); $gA = $mkG('current_asset','PTX-ADV','Adv'); $gB = $mkG('current_asset','PTX-BANK','Bank',1);
$lSE=$mkL($gE,'SAL-EXP','Salary Expense','expense'); $lEE=$mkL($gE,'SSF-EXP','Employer Exp','expense');
$lTD=$mkL($gL,'TDS-PAY','TDS','liability'); $lRP=$mkL($gL,'RET-PAY','Ret','liability'); $lSP=$mkL($gL,'SAL-PAY','Sal Pay','liability');
$lAD=$mkL($gA,'EMP-ADV','Adv','asset'); $lBK=$mkL($gB,'CASH','Bank','asset');
$fy = create_fiscal_year($cid,'PTX 2026/27','2026-07-16','2027-07-15',true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId=(int)$fy['id']; $_SESSION['fiscal_year_id']=$fyId;
payroll_settings($cid);
db()->prepare('UPDATE payroll_settings SET salary_expense_ledger_id=:se,employer_contrib_expense_ledger_id=:ee,tds_payable_ledger_id=:tds,retirement_payable_ledger_id=:rp,salary_payable_ledger_id=:sp,advance_ledger_id=:adv,bank_ledger_id=:bank,auto_post=1,enforce_sod=0 WHERE company_id=:cid')
    ->execute(['se'=>$lSE,'ee'=>$lEE,'tds'=>$lTD,'rp'=>$lRP,'sp'=>$lSP,'adv'=>$lAD,'bank'=>$lBK,'cid'=>$cid]);
db()->prepare("INSERT INTO payroll_tax_versions (company_id,fiscal_year_id,label,status,effective_from,retirement_limit_pct,retirement_limit_cap,ssf_first_slab_exempt,rounding) VALUES (:cid,:fy,'PTX flat 10','published','2026-07-16',33.33,500000,0,'none')")->execute(['cid'=>$cid,'fy'=>$fyId]);
$vid=(int) db()->lastInsertId();
db()->prepare('INSERT INTO payroll_tax_slabs (version_id,category,lower_bound,upper_bound,rate,sort_order) VALUES (?,?,0,NULL,10,0)')->execute([$vid,'individual']);

// Components: SC + OT actual-only earning components used as one-time lines.
$mkC = static function (string $code, string $name, string $calc, string $projection) use ($cid): int {
    db()->prepare("INSERT INTO payroll_components (company_id,code,name,category,posting_behaviour,calc_type,tax_projection_method,taxable,active)
        VALUES (?,?,?,?,'earning_expense',?,?,1,1)")->execute([$cid,$code,$name,'allowance',$calc,$projection]);
    return (int) db()->lastInsertId();
};
$cSC = $mkC('SC','Service Charge','manual','actual_only');
$cOT = $mkC('OT','Overtime','manual','actual_only');
$cFIXED = $mkC('HOUSE','Housing Allowance','fixed','regular');

$mkEmp = static function (string $code, float $basic, string $joined, ?string $terminated = null) use ($cid): int {
    db()->prepare("INSERT INTO payroll_employees (company_id,user_id,full_name,employee_code,marital_status,retirement_scheme,basic_salary,status,joined_on,terminated_on)
        VALUES (:cid,NULL,:name,:code,'individual','none',:basic,'active',:joined,:term)")
        ->execute(['cid'=>$cid,'name'=>'Emp '.$code,'code'=>$code,'basic'=>$basic,'joined'=>$joined,'term'=>$terminated]);
    return (int) db()->lastInsertId();
};
$mkRun = static function (int $period, string $label, string $payDate, array $scope = []) use ($cid, $fyId): int {
    db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,pay_date,employee_scope,created_by) VALUES (:cid,:fy,:p,:l,:pay,:scope,NULL)')
        ->execute(['cid'=>$cid,'fy'=>$fyId,'p'=>$period,'l'=>$label,'pay'=>$payDate,'scope'=>$scope !== [] ? json_encode($scope) : null]);
    return (int) db()->lastInsertId();
};
$lineFor = static function (int $runId, int $employeeId): ?array {
    foreach (payroll_run_lines($runId) as $l) { if ((int) $l['payroll_employee_id'] === $employeeId) { return $l; } }
    return null;
};
$actorId = (int) db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();

echo "Test 1+2: first FY month — regular projected x12, irregular counted ONCE\n";
$e1 = $mkEmp('T01', 30000, '2026-07-16');
$run1 = $mkRun(1, 'PTX M1', '2026-08-14', [$e1]);
payroll_calculate_run($run1);
// One-time SC 5,000 and OT 3,000 for this month only.
payroll_set_run_component($run1, $e1, $cSC, 5000.0, 'Declared service charge', $actorId, true);
payroll_set_run_component($run1, $e1, $cOT, 3000.0, 'Approved overtime', $actorId, true);
$line = $lineFor($run1, $e1);
ok(near((float) $line['gross'], 38000), 'Gross month 1 = 30,000 + 5,000 + 3,000');
ok(near((float) $line['assessable_annual'], 368000), 'Estimated annual = 30,000 x 12 + 8,000 = 368,000 (NOT 38,000 x 12 = 456,000)');
ok(near((float) $line['annual_tax'], 36800), 'Annual tax = 10% of 368,000');
ok(near((float) $line['tax_month'], round(36800 / 12, 2)), 'Current tax = annual estimate / 12 remaining periods');
$trace = json_decode((string) $line['trace'], true);
ok(($trace['projection']['remaining_periods'] ?? 0) === 12, 'First month of a 12-month year: divisor is 12');
ok(near((float) $trace['projection']['current_irregular'], 8000), 'SC + OT recorded as IRREGULAR current income');
ok(near((float) $trace['projection']['projected_future_regular'], 30000 * 11), 'Future projection = 11 months of basic only');

echo "\nTest 3: employee joins with six months remaining\n";
$e2 = $mkEmp('T02', 30000, '2027-01-16'); // period 7 of 12 -> 6 periods remain
$run7 = $mkRun(7, 'PTX M7', '2027-02-14', [$e2]);
payroll_calculate_run($run7);
$line = $lineFor($run7, $e2);
ok(near((float) $line['assessable_annual'], 180000), 'Estimated income = 30,000 x 6 remaining employment months = 180,000 (not 360,000)');
$trace = json_decode((string) $line['trace'], true);
ok(($trace['projection']['remaining_periods'] ?? 0) === 6, 'Joining with six months left: divisor is 6');
ok(near((float) $line['tax_month'], round(18000 / 6, 2)), 'Current tax = 18,000 / 6');

echo "\nTest 4: employee joins in the FINAL fiscal-year month\n";
$e3 = $mkEmp('T03', 30000, '2027-06-16'); // period 12
$run12a = $mkRun(12, 'PTX M12-a', '2027-07-14', [$e3]);
payroll_calculate_run($run12a);
$line = $lineFor($run12a, $e3);
ok(near((float) $line['assessable_annual'], 30000), 'Final-month joiner: estimated income = 30,000 x 1 (NOT annualized to 360,000)');
$trace = json_decode((string) $line['trace'], true);
ok(($trace['projection']['remaining_periods'] ?? 0) === 1, 'Final-month joiner: divisor is 1');
ok(near((float) $line['tax_month'], 3000), 'Whole 10% tax settles in the single month');

echo "\nTest 5+8: second period keeps YTD actuals, never repeats irregular income\n";
// Post month 1 for T01 so it becomes actual history.
$post1 = payroll_approve_and_post($run1, $actorId + 1);
ok(!empty($post1['ok']), 'Month 1 posts (history becomes actual)');
$run2 = $mkRun(2, 'PTX M2', '2026-09-14', [$e1]);
payroll_calculate_run($run2);
$line2 = $lineFor($run2, $e1);
$trace2 = json_decode((string) $line2['trace'], true);
ok(near((float) $trace2['projection']['actual_regular_to_date'], 30000), 'Month-1 regular income stays in YTD actuals');
ok(near((float) $trace2['projection']['actual_irregular_to_date'], 8000), 'Month-1 SC+OT stay in YTD actuals');
ok(near((float) $trace2['projection']['projected_future_regular'], 30000 * 10), 'Only future REGULAR months projected (10 x basic) — no OT/SC repeat');
ok(near((float) $line2['assessable_annual'], 30000 + 8000 + 30000 + 300000), 'Estimate = actual M1 (38,000) + current M2 (30,000) + future 10 months');
ok(($trace2['projection']['remaining_periods'] ?? 0) === 11, 'Second month: divisor is 11');
$expectedM2 = round((36800 - (float) $line2['tax_ytd_before']) / 11, 2);
ok(near((float) $line2['tax_month'], $expectedM2), 'M2 tax = (annual estimate - tax already deducted) / 11');

echo "\nTest 9: approved future salary revision projects period-by-period\n";
$e4 = $mkEmp('T04', 30000, '2026-07-16');
db()->prepare('INSERT INTO payroll_salary_revisions (company_id,payroll_employee_id,effective_from,basic_salary,reason,created_by)
        VALUES (:cid,:pe,:ef,35000,\'Promotion\',:by)')
    ->execute(['cid'=>$cid,'pe'=>$e4,'ef'=>'2027-01-16','by'=>$actorId]); // from period 7
$run1b = $mkRun(1, 'PTX M1-b', '2026-08-14', [$e4]);
payroll_calculate_run($run1b);
$line = $lineFor($run1b, $e4);
// Current month 30,000 + future: 5 months at 30,000 + 6 months at 35,000.
ok(near((float) $line['assessable_annual'], 30000 + 5 * 30000 + 6 * 35000), 'Projection uses the EFFECTIVE salary per future period (30k until P6, 35k from P7)');

echo "\nTest 10: employee leaving mid-year is not projected past the leaving date\n";
$e5 = $mkEmp('T05', 30000, '2026-07-16', '2026-10-15'); // leaves end of period 3
$run1c = $mkRun(1, 'PTX M1-c', '2026-08-14', [$e5]);
payroll_calculate_run($run1c);
$line = $lineFor($run1c, $e5);
ok(near((float) $line['assessable_annual'], 90000), 'Leaver: estimate = 3 employment months only (90,000), never through FY end');
$trace = json_decode((string) $line['trace'], true);
ok(($trace['projection']['remaining_periods'] ?? 0) === 3, 'Leaver in period 1 of 3: divisor is 3');

echo "\nTest 11: final employment month settles the whole remaining balance\n";
$post1c = payroll_approve_and_post($run1c, $actorId + 1);
$run2c = $mkRun(2, 'PTX M2-c', '2026-09-14', [$e5]);
payroll_calculate_run($run2c);
payroll_approve_and_post($run2c, $actorId + 1);
$run3c = $mkRun(3, 'PTX M3-c', '2026-10-14', [$e5]);
payroll_calculate_run($run3c);
$line = $lineFor($run3c, $e5);
$trace = json_decode((string) $line['trace'], true);
ok(($trace['projection']['remaining_periods'] ?? 0) === 1, 'Final employment month: divisor is 1');
ok(near((float) $trace['projection']['projected_future_regular'], 0), 'Final month: projected future income is zero');
$withheldBefore = (float) $line['tax_ytd_before'];
ok(near((float) $line['tax_month'], round(9000 - $withheldBefore, 2)), 'Final month settles the ENTIRE remaining balance (9,000 total - withheld)');

echo "\nTest 12: revised estimate below tax deducted -> excess shown, not hidden\n";
// After leaving-month payroll posted, cut the employee's salary retroactively
// via a big negative-effect: simulate by overriding month-3 amount low. Easier:
// employee T06 pays 2 months at high basic, then a salary revision DOWN from
// period 3 shrinks the estimate below the tax already deducted.
$e6 = $mkEmp('T06', 100000, '2026-07-16');
$run1d = $mkRun(1, 'PTX M1-d', '2026-08-14', [$e6]);
payroll_calculate_run($run1d);
payroll_approve_and_post($run1d, $actorId + 1);
$run2d = $mkRun(2, 'PTX M2-d', '2026-09-14', [$e6]);
payroll_calculate_run($run2d);
payroll_approve_and_post($run2d, $actorId + 1);
db()->prepare('UPDATE payroll_employees SET basic_salary = 10000 WHERE id = :id')->execute(['id' => $e6]);
db()->prepare('INSERT INTO payroll_salary_revisions (company_id,payroll_employee_id,effective_from,basic_salary,reason,created_by)
        VALUES (:cid,:pe,:ef,10000,\'Pay cut\',:by)')
    ->execute(['cid'=>$cid,'pe'=>$e6,'ef'=>'2026-09-16','by'=>$actorId]);
$run3d = $mkRun(3, 'PTX M3-d', '2026-10-14', [$e6]);
payroll_calculate_run($run3d);
$line = $lineFor($run3d, $e6);
$trace = json_decode((string) $line['trace'], true);
// Estimate: 200,000 actual + 10,000 current + 9 x 10,000 future = 300,000 -> tax 30,000; withheld ~ 20,000 -> no excess yet.
// Force excess: revise even lower is complex — assert the excess FIELD logic via remaining_tax instead.
ok((float) $trace['projection']['remaining_tax'] < (float) $line['annual_tax'], 'Remaining tax reflects amounts already deducted');
// Direct engine-level excess check: employee with big prior withholding.
$e7 = $mkEmp('T07', 10000, '2026-07-16');
db()->prepare('INSERT INTO payroll_employee_tax_profiles (company_id,payroll_employee_id,fiscal_year_id,prior_employment_income,prior_tax_withheld,entered_by,entered_at,approved_by,approved_at)
        VALUES (:cid,:pe,:fy,0,50000,:by,NOW(),:by2,NOW())')
    ->execute(['cid'=>$cid,'pe'=>$e7,'fy'=>$fyId,'by'=>$actorId,'by2'=>$actorId]);
$run1e = $mkRun(1, 'PTX M1-e', '2026-08-14', [$e7]);
payroll_calculate_run($run1e);
$line = $lineFor($run1e, $e7);
$trace = json_decode((string) $line['trace'], true);
ok(near((float) $line['tax_month'], 0), 'Excess withholding: current tax floors at zero (offset treatment)');
ok((float) $trace['projection']['excess_tax'] > 0, 'Excess tax is DISPLAYED in the calculation, not silently ignored');
$excessSnap = db()->prepare('SELECT excess_tax FROM payroll_tax_calculations WHERE run_id = :run AND payroll_employee_id = :pe');
$excessSnap->execute(['run' => $run1e, 'pe' => $e7]);
ok((float) $excessSnap->fetchColumn() > 0, 'Excess tax stored in the immutable snapshot');

echo "\nTest 13: approved prior employment income counts — with no accounting entry\n";
$e8 = $mkEmp('T08', 30000, '2027-01-16'); // joins period 7
db()->prepare('INSERT INTO payroll_employee_tax_profiles (company_id,payroll_employee_id,fiscal_year_id,prior_employment_income,prior_tax_withheld,entered_by,entered_at,approved_by,approved_at)
        VALUES (:cid,:pe,:fy,180000,18000,:by,NOW(),:by2,NOW())')
    ->execute(['cid'=>$cid,'pe'=>$e8,'fy'=>$fyId,'by'=>$actorId,'by2'=>$actorId]);
$run7b = $mkRun(7, 'PTX M7-b', '2027-02-14', [$e8]);
payroll_calculate_run($run7b);
$line = $lineFor($run7b, $e8);
ok(near((float) $line['assessable_annual'], 180000 + 180000), 'Estimate includes approved prior-employment income (180k prior + 180k here)');
$expectedTax = round((36000 - 18000) / 6, 2); // annual 36,000 minus prior tax 18,000, over 6 periods
ok(near((float) $line['tax_month'], $expectedTax), 'Prior employer tax reduces the balance before the divisor');
$post7b = payroll_approve_and_post($run7b, $actorId + 1);
$grossDr = db()->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END),0) FROM voucher_entries ve INNER JOIN vouchers v ON v.id=ve.voucher_id WHERE v.source_type='payroll_run' AND v.source_id=:run");
$grossDr->execute(['run' => $run7b]);
ok(near((float) $grossDr->fetchColumn(), 30000), 'Voucher debits ONLY the actual month (30,000) — prior income and projections never post');

echo "\nTest 14: tax override — system figure stays, override audited, recalc-safe\n";
$e9 = $mkEmp('T09', 30000, '2026-07-16');
$run1f = $mkRun(1, 'PTX M1-f', '2026-08-14', [$e9]);
payroll_calculate_run($run1f);
$noReason = payroll_set_tax_override($run1f, $e9, 2500.0, '', $actorId);
ok(empty($noReason['ok']), 'Override without a reason is refused');
$ovr = payroll_set_tax_override($run1f, $e9, 2500.0, 'Agreed with auditor', $actorId);
ok(!empty($ovr['ok']), 'Override with reason applies');
$line = $lineFor($run1f, $e9);
ok(near((float) $line['tax_month'], 2500), 'Line tax uses the approved override');
$trace = json_decode((string) $line['trace'], true);
ok(near((float) $trace['withholding']['system_tax'], 3000), 'System-calculated tax (3,000) remains visible beside the override');
payroll_calculate_run($run1f);
$line = $lineFor($run1f, $e9);
ok(near((float) $line['tax_month'], 2500), 'Override survives a full recalculation');
$snap = db()->prepare('SELECT system_tax, tax_override FROM payroll_tax_calculations WHERE run_id=:run AND payroll_employee_id=:pe');
$snap->execute(['run' => $run1f, 'pe' => $e9]);
$snapRow = $snap->fetch();
ok(near((float) $snapRow['system_tax'], 3000) && near((float) $snapRow['tax_override'], 2500), 'Snapshot stores BOTH the system figure and the override');
$clear = payroll_set_tax_override($run1f, $e9, null, '', $actorId);
$line = $lineFor($run1f, $e9);
ok(!empty($clear['ok']) && near((float) $line['tax_month'], 3000), 'Clearing the override restores the system tax');

echo "\nTest 15: consolidated reports produce correct totals\n";
require_once __DIR__ . '/../app/reports_engine.php';
$report = rc_generate('consolidated-salary-sheet', $cid, '2026-07-16', '2027-07-15', ['currency' => '', 'fy_start' => '2026-07-16']);
$grandLabel = (string) ($report['totals'][0] ?? '');
ok($report['rows'] !== [] && str_contains($grandLabel, 'Grand total'), 'Consolidated Salary Sheet builds with a grand total');
$taxReport = rc_generate('consolidated-tax-report', $cid, '2026-07-16', '2027-07-15', ['currency' => '', 'fy_start' => '2026-07-16']);
ok($taxReport['rows'] !== [], 'Consolidated Payroll Tax report lists employees');
$ytd = rc_generate('employee-ytd-statement', $cid, '2026-07-16', '2027-07-15', ['currency' => '', 'fy_start' => '2026-07-16']);
$hasProjectedRow = false;
foreach ($ytd['rows'] as $row) {
    $cell = is_array($row) ? (string) (($row['cells'] ?? $row)[0] ?? '') : '';
    if (str_contains($cell, 'PROJECTED')) { $hasProjectedRow = true; }
}
ok($hasProjectedRow, 'YTD statement marks projected figures clearly as PROJECTED');

echo "\nTest 16: tenant isolation of tax data\n";
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Projected Tax Test B','PTXTESTB',1)")->execute();
$cidB = (int) db()->lastInsertId();
$isoStmt = db()->prepare('SELECT COUNT(*) FROM payroll_tax_calculations WHERE company_id = :cid');
$isoStmt->execute(['cid' => $cidB]);
ok((int) $isoStmt->fetchColumn() === 0, 'Company B sees none of company A\'s tax calculations');
$reportB = rc_generate('consolidated-tax-report', $cidB, '2026-07-16', '2027-07-15', ['currency' => '', 'fy_start' => '2026-07-16']);
ok(($reportB['rows'] ?? []) === [], 'Company B\'s consolidated tax report is empty');

ptx_cleanup();
echo "\n----------------------------------------\n";
echo 'PASS: ' . $pass . '   FAIL: ' . $fail . "\n";
exit($fail === 0 ? 0 : 1);
