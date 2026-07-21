<?php
declare(strict_types=1);

/**
 * Weekly overtime: Sunday-Saturday accumulation with a 40-hour threshold.
 * Covers the required attendance examples (A: 44h -> 4 OT, B: 38h -> 0 OT,
 * C: 45h -> 5 OT), the exactly-40 case, splitting the day that crosses the
 * threshold, a week crossing two payroll months, duplicate-payment
 * prevention, and employee-specific rates.
 *   php database/test_payroll_overtime_weekly.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/payroll_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.011; }

function pot_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code='POTTEST'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM payroll_run_components WHERE run_id IN (SELECT id FROM payroll_runs WHERE company_id=$s)");
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
    foreach (db()->query("SELECT id FROM users WHERE email LIKE 'pottest-%@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
pot_cleanup();

// ---------------------------------------------------------------------------
// Fixture.
// ---------------------------------------------------------------------------
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Overtime Test Co','POTTEST',1)")->execute();
$cid = (int) db()->lastInsertId();
$_SESSION['company_id'] = $cid;
$mkG = static function (string $m, string $c, string $n, int $cash = 0) use ($cid): int { db()->prepare('INSERT INTO ledger_groups (company_id,master_key,code,name,is_cash_or_bank) VALUES (?,?,?,?,?)')->execute([$cid,$m,$c,$n,$cash]); return (int) db()->lastInsertId(); };
$mkL = static function (int $g, string $c, string $n, string $t) use ($cid): int { db()->prepare("INSERT INTO ledgers (company_id,group_id,code,name,type,status) VALUES (?,?,?,?,?, 'active')")->execute([$cid,$g,$c,$n,$t]); return (int) db()->lastInsertId(); };
$gE = $mkG('indirect_expense','POT-EXP','Exp'); $gL = $mkG('current_liability','POT-LIAB','Liab'); $gA = $mkG('current_asset','POT-ADV','Adv'); $gB = $mkG('current_asset','POT-BANK','Bank',1);
$lSE=$mkL($gE,'SAL-EXP','Salary Expense','expense'); $lOT=$mkL($gE,'OT-EXP','Overtime Expense','expense'); $lEE=$mkL($gE,'SSF-EXP','Employer Exp','expense');
$lTD=$mkL($gL,'TDS-PAY','TDS','liability'); $lRP=$mkL($gL,'RET-PAY','Ret','liability'); $lSP=$mkL($gL,'SAL-PAY','Sal Pay','liability');
$lAD=$mkL($gA,'EMP-ADV','Adv','asset'); $lBK=$mkL($gB,'CASH','Bank','asset');
$fy = create_fiscal_year($cid,'POT 2026/27','2026-07-16','2027-07-15',true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId=(int)$fy['id']; $_SESSION['fiscal_year_id']=$fyId;
payroll_settings($cid);
db()->prepare("INSERT INTO payroll_tax_versions (company_id,fiscal_year_id,label,status,effective_from,retirement_limit_pct,retirement_limit_cap,ssf_first_slab_exempt,rounding) VALUES (:cid,:fy,'POT','published','2026-07-16',33.33,500000,1,'nearest')")->execute(['cid'=>$cid,'fy'=>$fyId]);
$vid=(int) db()->lastInsertId();
$slab=db()->prepare('INSERT INTO payroll_tax_slabs (version_id,category,lower_bound,upper_bound,rate,sort_order) VALUES (?,?,?,?,?,?)');
foreach ([[0,500000,1],[500000,null,10]] as $i=>[$lo,$hi,$r]) { $slab->execute([$vid,'individual',$lo,$hi,$r,$i]); }

db()->prepare('INSERT INTO payroll_components (company_id,code,name,category,posting_behaviour,calc_type,debit_ledger_id,taxable,active) VALUES (?,?,?,?,?,?,?,1,1)')
    ->execute([$cid,'OT','Overtime','overtime','earning_expense','overtime_hours',$lOT]);
$cOT = (int) db()->lastInsertId();
db()->prepare('UPDATE payroll_settings SET salary_expense_ledger_id=:se,employer_contrib_expense_ledger_id=:ee,tds_payable_ledger_id=:tds,retirement_payable_ledger_id=:rp,salary_payable_ledger_id=:sp,advance_ledger_id=:adv,bank_ledger_id=:bank,auto_post=1,enforce_sod=0,
        ot_weekly_threshold=40, ot_week_start=0, ot_component_id=:otc, ot_rate_source=\'salary_derived\', ot_monthly_hours=208, ot_multiplier=1.5, ot_rounding=\'none\', ot_require_approval=1 WHERE company_id=:cid')
    ->execute(['se'=>$lSE,'ee'=>$lEE,'tds'=>$lTD,'rp'=>$lRP,'sp'=>$lSP,'adv'=>$lAD,'bank'=>$lBK,'otc'=>$cOT,'cid'=>$cid]);

// Two employees with logins (attendance is user-based). A: fixed 100/h rate;
// B: salary-derived 41,600 / 208 = 200/h. Multiplier 1.5.
$uA = create_user(['name'=>'OT Worker A','email'=>'pottest-a@test.local','password'=>'Secret#12345','role'=>'staff','status'=>'active','company_id'=>$cid]);
$uB = create_user(['name'=>'OT Worker B','email'=>'pottest-b@test.local','password'=>'Secret#12345','role'=>'staff','status'=>'active','company_id'=>$cid]);
db()->prepare("INSERT INTO payroll_employees (company_id,user_id,employee_code,marital_status,retirement_scheme,basic_salary,ot_hourly_rate,status,joined_on) VALUES (:cid,:uid,'OTA','individual','none',30000,100,'active','2026-07-16')")->execute(['cid'=>$cid,'uid'=>$uA]);
$eA = (int) db()->lastInsertId();
db()->prepare("INSERT INTO payroll_employees (company_id,user_id,employee_code,marital_status,retirement_scheme,basic_salary,status,joined_on) VALUES (:cid,:uid,'OTB','individual','none',41600,'active','2026-07-16')")->execute(['cid'=>$cid,'uid'=>$uB]);
$eB = (int) db()->lastInsertId();
$settings = payroll_settings($cid);
$empA = ['id'=>$eA,'company_id'=>$cid,'user_id'=>$uA,'basic_salary'=>30000,'ot_hourly_rate'=>100];
$empB = ['id'=>$eB,'company_id'=>$cid,'user_id'=>$uB,'basic_salary'=>41600,'ot_hourly_rate'=>null];

$att = static function (int $userId, string $date, float $hours) use ($cid): void {
    db()->prepare('INSERT INTO attendance (company_id,staff_user_id,attendance_date,check_in_time,check_out_time)
        VALUES (:cid,:uid,:d,:in,:out)
        ON DUPLICATE KEY UPDATE check_in_time=VALUES(check_in_time), check_out_time=VALUES(check_out_time)')
        ->execute(['cid'=>$cid,'uid'=>$userId,'d'=>$date,'in'=>$date.' 09:00:00','out'=>date('Y-m-d H:i:s', strtotime($date.' 09:00:00') + (int) round($hours*3600))]);
};
$day = static fn (string $sunday, int $offset): string => date('Y-m-d', strtotime($sunday . ' +' . $offset . ' days'));

// A Sunday fully inside period 1 (2026-07-16 .. 2026-08-15).
$sunday1 = payroll_ot_week_start('2026-07-22'); // week 19-25 July (Sun 19th)
ok((int) date('w', strtotime($sunday1)) === 0, 'Week start resolves to a Sunday');

echo "\nTest A: Sun 8, Mon 10, Tue 8, Wed 8, Thu 6, Fri 4 = 44 -> overtime 4\n";
foreach ([0=>8, 1=>10, 2=>8, 3=>8, 4=>6, 5=>4] as $offset => $hours) { $att($uA, $day($sunday1, $offset), (float) $hours); }
$weekA = payroll_ot_recalc_week($empA, $settings, $sunday1);
ok($weekA !== null && near((float) $weekA['total_hours'], 44), 'Week totals 44 hours');
ok(near((float) $weekA['overtime_hours'], 4), 'Expected overtime: 4 hours');
ok(near((float) $weekA['regular_hours'], 40), 'Regular hours capped at the 40-hour threshold');
$entriesA = db()->prepare('SELECT ot_date, hours FROM payroll_overtime_entries WHERE payroll_employee_id=:pe AND week_start=:ws ORDER BY ot_date');
$entriesA->execute(['pe'=>$eA,'ws'=>$sunday1]);
$entryRows = $entriesA->fetchAll(PDO::FETCH_ASSOC);
ok(count($entryRows) === 1 && $entryRows[0]['ot_date'] === $day($sunday1, 5) && near((float) $entryRows[0]['hours'], 4),
    'All 4 OT hours are dated on FRIDAY (the day worked after the week hit 40)');
ok(near((float) $weekA['hourly_rate'], 150), 'Employee-specific fixed rate: 100 x 1.5 multiplier = 150/h');
ok(near((float) $weekA['calculated_amount'], 600), 'Calculated amount 4 h x 150 = 600');

echo "\nTest B: Sunday 10 but week total 38 -> overtime 0\n";
$sunday2 = date('Y-m-d', strtotime($sunday1 . ' +7 days'));
foreach ([0=>10, 1=>8, 2=>8, 3=>8, 4=>4] as $offset => $hours) { $att($uB, $day($sunday2, $offset), (float) $hours); }
$weekB = payroll_ot_recalc_week($empB, $settings, $sunday2);
ok($weekB === null, 'A 10-hour Sunday alone creates NO overtime when the week stays under 40 (total 38)');
$countB = db()->prepare('SELECT COUNT(*) FROM payroll_overtime_entries WHERE payroll_employee_id=:pe');
$countB->execute(['pe'=>$eB]);
ok((int) $countB->fetchColumn() === 0, 'No overtime entries stored for the under-threshold week');

echo "\nExactly 40 hours -> overtime 0\n";
foreach ([0=>8, 1=>8, 2=>8, 3=>8, 4=>8] as $offset => $hours) { $att($uA, $day($sunday2, $offset), (float) $hours); }
$weekExact = payroll_ot_recalc_week($empA, $settings, $sunday2);
ok($weekExact === null, 'Exactly 40 cumulative hours produce NO overtime');

echo "\nTest C: total 45 -> overtime 5, and the crossing day SPLITS\n";
$sunday3 = date('Y-m-d', strtotime($sunday2 . ' +7 days')); // still inside period 1
foreach ([0=>10, 1=>10, 2=>10, 3=>8, 4=>7] as $offset => $hours) { $att($uB, $day($sunday3, $offset), (float) $hours); }
$weekC = payroll_ot_recalc_week($empB, $settings, $sunday3);
ok($weekC !== null && near((float) $weekC['total_hours'], 45) && near((float) $weekC['overtime_hours'], 5), 'Weekly 45 hours -> exactly 5 OT hours');
$daily = json_decode((string) $weekC['daily_json'], true) ?: [];
$wed = $daily[$day($sunday3, 3)] ?? null; // cumulative 30 + 8 = 38 -> all regular
$thu = $daily[$day($sunday3, 4)] ?? null; // 38 + 7 = 45 -> 2 regular + 5 OT
ok($wed !== null && near((float) $wed['overtime'], 0) && near((float) $wed['regular'], 8), 'Hours BEFORE reaching 40 stay regular');
ok($thu !== null && near((float) $thu['regular'], 2) && near((float) $thu['overtime'], 5), 'The crossing day splits: 2 regular + 5 overtime');
ok(near((float) $weekC['hourly_rate'], 300), 'Salary-derived rate: 41,600 / 208 x 1.5 = 300/h');
ok(near((float) $weekC['calculated_amount'], 1500), 'Calculated 5 h x 300 = 1,500');

echo "\nApproval flow with audited adjustment\n";
$noReason = payroll_ot_approve_week((int) $weekC['id'], 1200.0, '', $uA);
ok(empty($noReason['ok']), 'Adjusting the amount without a reason is refused');
$approveC = payroll_ot_approve_week((int) $weekC['id'], 1200.0, 'Rate agreed with employee', $uA);
ok(!empty($approveC['ok']) && near((float) $approveC['amount'], 1200), 'Adjusted approval accepted with reason');
$approveA = payroll_ot_approve_week((int) $weekA['id'], null, '', $uA);
ok(!empty($approveA['ok']) && near((float) $approveA['amount'], 600), 'Plain approval keeps the calculated amount');

echo "\nPayroll pickup + duplicate prevention\n";
db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,pay_date,created_by) VALUES (:cid,:fy,1,:l,:pay,:by)')
    ->execute(['cid'=>$cid,'fy'=>$fyId,'l'=>'POT M1','pay'=>'2026-08-14','by'=>$uA]);
$run1 = (int) db()->lastInsertId();
$calc1 = payroll_calculate_run($run1);
ok(!empty($calc1['ok']), 'Run 1 calculates with overtime injection');
$otRowA = null; $otRowB = null;
foreach (payroll_run_component_rows($run1) as $r) {
    if ((string) $r['source'] === 'overtime') {
        if ((int) $r['payroll_employee_id'] === $eA) { $otRowA = $r; }
        if ((int) $r['payroll_employee_id'] === $eB) { $otRowB = $r; }
    }
}
ok($otRowA !== null && near((float) $otRowA['amount'], 600), 'Worker A overtime 600 lands as an OT run component');
ok($otRowB !== null && near((float) $otRowB['amount'], 1200), 'Worker B adjusted overtime 1,200 lands too');
$lineA = null;
foreach (payroll_run_lines($run1) as $l) { if ((int) $l['payroll_employee_id'] === $eA) { $lineA = $l; } }
ok($lineA !== null && near((float) $lineA['overtime'], 600), 'Line overtime column carries the approved amount');
$claimed = db()->prepare('SELECT COUNT(*) FROM payroll_overtime_entries WHERE run_id=:r');
$claimed->execute(['r'=>$run1]);
ok((int) $claimed->fetchColumn() === 2, 'Both weeks\' entries are CLAIMED by run 1');

db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,pay_date,employee_scope,created_by) VALUES (:cid,:fy,1,:l,:pay,:scope,:by)')
    ->execute(['cid'=>$cid,'fy'=>$fyId,'l'=>'POT M1-B','pay'=>'2026-08-14','scope'=>json_encode([$eB]),'by'=>$uA]);
// (scoped duplicate-month run is blocked by the page guard; engine-level check:)
$run1b = (int) db()->lastInsertId();
$calc1b = payroll_calculate_run($run1b);
$dupOt = array_filter(payroll_run_component_rows($run1b), static fn (array $r): bool => (string) $r['source'] === 'overtime');
ok(!empty($calc1b['ok']) && $dupOt === [], 'A second run of the SAME period gets NO overtime — hours cannot be paid twice');
db()->prepare('DELETE FROM payroll_run_lines WHERE run_id=:r')->execute(['r'=>$run1b]);
db()->prepare('DELETE FROM payroll_runs WHERE id=:r')->execute(['r'=>$run1b]);

echo "\nWeek crossing two payroll months\n";
// Period 2 = 16 Aug - 15 Sep 2026; 15 Sep is a TUESDAY, so the Sunday-Saturday
// week containing it (13-19 Sep) genuinely straddles periods 2 and 3.
[$p2Start, $p2End] = payroll_period_range($fyId, 2);
[$p3Start, $p3End] = payroll_period_range($fyId, 3);
$crossSunday = payroll_ot_week_start($p2End);
ok($crossSunday <= $p2End && $day($crossSunday, 6) >= $p3Start, 'Chosen week truly crosses the month boundary');
// Sun 14 + Mon 14 + Tue 14 = 42 -> 2 OT on Tuesday (period 2);
// Wed 8 -> all 8 OT on Wednesday (period 3). Total 10 OT for the ONE week.
foreach ([0=>14, 1=>14, 2=>14, 3=>8] as $offset => $hours) { $att($uB, $day($crossSunday, $offset), (float) $hours); }
$crossWeek = payroll_ot_recalc_week($empB, $settings, $crossSunday);
ok($crossWeek !== null && near((float) $crossWeek['overtime_hours'], 10), 'Full-week view finds 10 OT hours across the boundary');
payroll_ot_approve_week((int) $crossWeek['id'], null, '', $uA);
$crossEntries = db()->prepare('SELECT ot_date, amount FROM payroll_overtime_entries WHERE payroll_employee_id=:pe AND week_start=:ws ORDER BY ot_date');
$crossEntries->execute(['pe'=>$eB,'ws'=>$crossSunday]);
$crossRows = $crossEntries->fetchAll(PDO::FETCH_ASSOC);
$inP2 = array_filter($crossRows, static fn (array $r): bool => $r['ot_date'] <= $p2End);
$inP3 = array_filter($crossRows, static fn (array $r): bool => $r['ot_date'] >= $p3Start);
ok(count($crossRows) === 2 && count($inP2) === 1 && count($inP3) === 1, 'Every OT hour is dated to its actual work day (Tue in P2, Wed in P3)');

db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,pay_date,created_by) VALUES (:cid,:fy,2,:l,:pay,:by)')
    ->execute(['cid'=>$cid,'fy'=>$fyId,'l'=>'POT M2','pay'=>'2026-09-14','by'=>$uA]);
$run2 = (int) db()->lastInsertId();
payroll_calculate_run($run2);
$otB2 = 0.0;
foreach (payroll_run_component_rows($run2, $eB) as $r) { if ((string) $r['source'] === 'overtime') { $otB2 = (float) $r['amount']; } }
$expectedP2 = 0.0;
foreach ($inP2 as $r) { $expectedP2 += (float) $r['amount']; }
ok(near($otB2, $expectedP2) && $expectedP2 > 0, 'Period-2 run pays ONLY the boundary-week hours dated inside period 2 (2 h)');

db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,pay_date,created_by) VALUES (:cid,:fy,3,:l,:pay,:by)')
    ->execute(['cid'=>$cid,'fy'=>$fyId,'l'=>'POT M3','pay'=>'2026-10-14','by'=>$uA]);
$run3 = (int) db()->lastInsertId();
payroll_calculate_run($run3);
$otB3 = 0.0;
foreach (payroll_run_component_rows($run3, $eB) as $r) { if ((string) $r['source'] === 'overtime') { $otB3 = (float) $r['amount']; } }
$expectedP3 = 0.0;
foreach ($inP3 as $r) { $expectedP3 += (float) $r['amount']; }
ok(near($otB3, $expectedP3) && $expectedP3 > 0, 'Period-3 run pays exactly the remaining boundary-week hours (8 h) — no overlap');
$weekTotal = 0.0;
foreach ($crossRows as $r) { $weekTotal += (float) $r['amount']; }
ok(near($otB2 + $otB3, $weekTotal), 'Across both months the week is paid exactly once in full');

echo "\nRelease on cancel: freed hours become payable again\n";
payroll_ot_release_run($run3);
$freed = db()->prepare('SELECT COUNT(*) FROM payroll_overtime_entries WHERE payroll_employee_id=:pe AND week_start=:ws AND run_id IS NULL AND ot_date >= :p3');
$freed->execute(['pe'=>$eB,'ws'=>$crossSunday,'p3'=>$p3Start]);
ok((int) $freed->fetchColumn() === count($inP3), 'Cancelling the run releases its overtime claims');

pot_cleanup();
echo "\n----------------------------------------\n";
echo 'PASS: ' . $pass . '   FAIL: ' . $fail . "\n";
exit($fail === 0 ? 0 : 1);
