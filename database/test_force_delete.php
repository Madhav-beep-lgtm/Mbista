<?php
declare(strict_types=1);

/**
 * Force-delete suite: admin-only override that deletes module-posted /
 * bank-reconciled vouchers WITH register rollback, mandatory reason, and
 * calendar guards (locked period / closed year still refuse).
 *   php database/test_force_delete.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/payroll_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.01; }

function fd_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code='FDTESTA'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE rl FROM payroll_run_lines rl JOIN payroll_runs r ON r.id=rl.run_id WHERE r.company_id=$s");
        db()->exec("DELETE FROM payroll_runs WHERE company_id=$s");
        db()->exec("DELETE FROM payroll_employees WHERE company_id=$s");
        db()->exec("DELETE ts FROM payroll_tax_slabs ts JOIN payroll_tax_versions v ON v.id=ts.version_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM payroll_tax_versions WHERE company_id=$s");
        db()->exec("DELETE FROM payroll_settings WHERE company_id=$s");
        db()->exec("DELETE FROM inventory_cost_layers WHERE company_id=$s");
        db()->exec("DELETE FROM inventory_transactions WHERE company_id=$s");
        db()->exec("DELETE FROM inventory_ledger_mappings WHERE company_id=$s");
        db()->exec("DELETE FROM inventory_items WHERE company_id=$s");
        db()->exec("DELETE FROM fiscal_period_locks WHERE company_id=$s");
        db()->exec("DELETE ve FROM voucher_entries ve JOIN vouchers v ON v.id=ve.voucher_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
}
fd_cleanup();

db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Force Delete Co','FDTESTA',1)")->execute();
$cid = (int) db()->lastInsertId();
$adminId = (int) db()->query("SELECT id FROM users WHERE role='admin' AND status='active' ORDER BY id LIMIT 1")->fetchColumn();
$_SESSION['company_id'] = $cid;
$_SESSION['user_id'] = $adminId; // current_user() -> admin (force_delete checks role)
$mkG = static function (string $m, string $c, string $n, int $cash = 0) use ($cid): int { db()->prepare('INSERT INTO ledger_groups (company_id,master_key,code,name,is_cash_or_bank) VALUES (?,?,?,?,?)')->execute([$cid,$m,$c,$n,$cash]); return (int) db()->lastInsertId(); };
$mkL = static function (int $g, string $c, string $n, string $t) use ($cid): int { db()->prepare("INSERT INTO ledgers (company_id,group_id,code,name,type,status) VALUES (?,?,?,?,?, 'active')")->execute([$cid,$g,$c,$n,$t]); return (int) db()->lastInsertId(); };
$gE=$mkG('indirect_expense','FD-EXP','Exp'); $gL=$mkG('current_liability','FD-LIAB','Pay'); $gA=$mkG('current_asset','FD-AST','Assets'); $gB=$mkG('current_asset','FD-BANK','Bank',1);
$lSE=$mkL($gE,'SAL-EXP','Salary Expense','expense'); $lEE=$mkL($gE,'SSF-EXP','Employer Exp','expense'); $lTD=$mkL($gL,'TDS-PAY','TDS Pay','liability');
$lRP=$mkL($gL,'RET-PAY','Ret Pay','liability'); $lSP=$mkL($gL,'SAL-PAY','Salary Payable','liability'); $lAD=$mkL($gA,'EMP-ADV','Adv','asset'); $lBK=$mkL($gB,'CASH','Bank','asset');
$lINV=$mkL($gA,'INV-CTL','Inventory Control','asset'); $lGAIN=$mkL($gE,'INV-GAIN','Inventory Gain','revenue');
$fy = create_fiscal_year($cid,'FD 2026/27','2026-07-16','2027-07-15',true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId=(int)$fy['id']; $_SESSION['fiscal_year_id']=$fyId;
payroll_settings($cid);
db()->prepare('UPDATE payroll_settings SET salary_expense_ledger_id=:se,employer_contrib_expense_ledger_id=:ee,tds_payable_ledger_id=:tds,retirement_payable_ledger_id=:rp,salary_payable_ledger_id=:sp,advance_ledger_id=:adv,bank_ledger_id=:bank,auto_post=1,enforce_sod=0 WHERE company_id=:cid')
    ->execute(['se'=>$lSE,'ee'=>$lEE,'tds'=>$lTD,'rp'=>$lRP,'sp'=>$lSP,'adv'=>$lAD,'bank'=>$lBK,'cid'=>$cid]);
db()->prepare("INSERT INTO payroll_tax_versions (company_id,fiscal_year_id,label,legal_reference,status,effective_from,retirement_limit_pct,retirement_limit_cap,ssf_first_slab_exempt,rounding) VALUES (:cid,:fy,'FD','DEMO','published','2026-07-16',33.33,500000,1,'nearest')")->execute(['cid'=>$cid,'fy'=>$fyId]);
$vid=(int) db()->lastInsertId(); $slab=db()->prepare('INSERT INTO payroll_tax_slabs (version_id,category,lower_bound,upper_bound,rate,sort_order) VALUES (?,?,?,?,?,?)');
foreach ([[0,500000,1],[500000,null,10]] as $i=>[$lo,$hi,$r]) { $slab->execute([$vid,'individual',$lo,$hi,$r,$i]); }
db()->prepare("INSERT INTO payroll_employees (company_id,user_id,employee_code,department,designation,pan_no,bank_name,bank_account,marital_status,retirement_scheme,retirement_employee_rate,retirement_employer_rate,basic_salary,status,joined_on) VALUES (:cid,:uid,'E001','Ops','Officer','601','NIC','012','individual','none',0,0,50000,'active','2026-07-16')")->execute(['cid'=>$cid,'uid'=>$adminId]);

echo "Guards\n";
db()->prepare('INSERT INTO payroll_runs (company_id,fiscal_year_id,period_no,period_label,pay_date,created_by) VALUES (:cid,:fy,1,:l,:pay,:by)')
    ->execute(['cid'=>$cid,'fy'=>$fyId,'l'=>'Shrawan','pay'=>'2026-08-15','by'=>$adminId]);
$runId=(int) db()->lastInsertId();
payroll_calculate_run($runId);
$ap = payroll_approve_and_post($runId, $adminId);
$accrualId = (int) ($ap['voucher_id'] ?? 0);
ok($accrualId > 0, 'Fixture: payroll accrual voucher posted');
$blocked = voucher_mutation_blocker((array) db()->query("SELECT * FROM vouchers WHERE id=$accrualId")->fetch(PDO::FETCH_ASSOC) ?: []);
ok($blocked !== null && str_contains((string) $blocked, 'Payroll'), 'payroll_run voucher is module-guarded against normal register delete');
$short = force_delete_voucher($accrualId, $cid, 'too short', $adminId);
ok(empty($short['ok']) && str_contains((string) $short['error'], 'reason'), 'Short reason refused');

// Locked period refuses.
db()->prepare('INSERT INTO fiscal_period_locks (company_id, fiscal_year_id, locked_through, locked_by) VALUES (:cid,:fy,:thru,:by)
    ON DUPLICATE KEY UPDATE locked_through=VALUES(locked_through)')->execute(['cid'=>$cid,'fy'=>$fyId,'thru'=>'2026-09-01','by'=>$adminId]);
$lockedTry = force_delete_voucher($accrualId, $cid, 'Trying inside a locked period', $adminId);
ok(empty($lockedTry['ok']) && str_contains((string) $lockedTry['error'], 'locked'), 'Locked period still refuses force delete');
db()->prepare('DELETE FROM fiscal_period_locks WHERE company_id=:cid')->execute(['cid'=>$cid]);

echo "\nPayroll accrual force delete rolls the run back\n";
// Simulate bank reconciliation on one entry to prove un-reconcile.
db()->prepare('UPDATE voucher_entries SET reconciled_at = NOW() WHERE voucher_id = :vid LIMIT 1')->execute(['vid' => $accrualId]);
$res = force_delete_voucher($accrualId, $cid, 'Duplicate accrual posted during testing', $adminId);
ok(!empty($res['ok']), 'Force delete succeeds with reason (reconciled entry included)');
ok(str_contains((string) $res['summary'], 'un-reconciled'), 'Summary reports the un-reconciliation');
$runAfter = payroll_run($runId);
ok((int) ($runAfter['accrual_voucher_id'] ?? 0) === 0 && (string) $runAfter['status'] === 'approved', 'Run rolled back to approved / accrual-unposted');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE id=$accrualId")->fetchColumn() === 0, 'Voucher gone from the books');
$repost = payroll_post_accrual($runId, $adminId);
ok(!empty($repost['ok']) && (int) $repost['voucher_id'] > 0, 'Accrual can be re-posted cleanly afterwards');

echo "\nInventory movement force delete unlinks the stock row\n";
db()->prepare("INSERT INTO inventory_items (company_id, ledger_id, sku, name, item_type, valuation_method, unit, purchase_rate, opening_qty, status) VALUES (:cid,:led,'FD-ITM','FD Item','stock','weighted_average','pcs',100,0,'active')")
    ->execute(['cid'=>$cid,'led'=>$lINV]);
$itemId = (int) db()->lastInsertId();
db()->prepare('INSERT INTO inventory_transactions (company_id, fiscal_year_id, item_id, transaction_type, transaction_date, qty_in, qty_out, rate, amount) VALUES (:cid,:fy,:iid,\'adjustment\',:d,10,0,100,1000)')
    ->execute(['cid'=>$cid,'fy'=>$fyId,'iid'=>$itemId,'d'=>'2026-08-01']);
$txnId = (int) db()->lastInsertId();
$mvVoucher = create_voucher_with_entries([
    'company_id'=>$cid,'fiscal_year_id'=>$fyId,'voucher_no'=>'INV-ADJUSTMENT-FD1','voucher_type'=>'journal','voucher_date'=>'2026-08-01',
    'source_type'=>'inventory_movement','source_id'=>$txnId,'total_amount'=>1000,'narration'=>'FD test','status'=>'posted','posted_by'=>$adminId,
], [
    ['ledger_id'=>$lINV,'entry_type'=>'debit','amount'=>1000],
    ['ledger_id'=>$lGAIN,'entry_type'=>'credit','amount'=>1000],
]);
db()->prepare('UPDATE inventory_transactions SET voucher_id=:vid WHERE id=:id')->execute(['vid'=>$mvVoucher,'id'=>$txnId]);
$mvRow = db()->query("SELECT * FROM vouchers WHERE id=$mvVoucher")->fetch(PDO::FETCH_ASSOC);
ok(voucher_mutation_blocker((array) $mvRow) !== null, 'inventory_movement voucher IS module-blocked for normal delete');
$normal = delete_voucher_with_entries($mvVoucher, $cid, $adminId);
ok(empty($normal['ok']), 'Normal delete refuses the module voucher');
$forced = force_delete_voucher($mvVoucher, $cid, 'Wrong adjustment amount, re-entering', $adminId);
ok(!empty($forced['ok']), 'Force delete overrides the module lock');
$txnAfter = db()->query("SELECT voucher_id FROM inventory_transactions WHERE id=$txnId")->fetch(PDO::FETCH_ASSOC);
ok($txnAfter !== false && $txnAfter['voucher_id'] === null, 'Stock movement kept but unlinked from the deleted voucher');

echo "\nNon-admin cannot force delete\n";
db()->prepare("INSERT INTO users (name, email, password_hash, role, status, company_id) VALUES ('FD Staff','fdstaff@test.local','','staff','active',:cid)")->execute(['cid'=>$cid]);
$staffId = (int) db()->lastInsertId();
$_SESSION['user_id'] = $staffId;
$denied = force_delete_voucher((int) $repost['voucher_id'], $cid, 'Staff attempting a force delete', $staffId);
ok(empty($denied['ok']) && str_contains((string) $denied['error'], 'admin'), 'Staff force delete refused');
$_SESSION['user_id'] = $adminId;
db()->exec("DELETE FROM users WHERE id=$staffId");

fd_cleanup();
echo "\n----------------------------------------\nPASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
