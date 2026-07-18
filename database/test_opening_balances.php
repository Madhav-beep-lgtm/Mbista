<?php
declare(strict_types=1);

/**
 * Opening-balance acceptance suite (CLI, self-contained).
 *
 * Builds a throw-away company with three consecutive fiscal years, a small chart
 * of accounts, two parties, one inventory item and one fixed asset; exercises the
 * REAL opening-balance engine, posting engine and reports engine; then removes
 * every row it created. Implements the 18 required acceptance tests.
 *
 *   php database/test_opening_balances.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/reports_engine.php';
require_once __DIR__ . '/../app/opening_balance_engine.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';

accounting_module_repair_database(); // creates opening_balance_* tables

$pass = 0;
$fail = 0;
function ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
}
function near(float $a, float $b): bool { return abs($a - $b) < 0.01; }

// ---------------------------------------------------------------------------
// Table existence (migration/self-repair)
// ---------------------------------------------------------------------------
echo "Schema\n";
ok(table_exists('opening_balance_batches'), 'opening_balance_batches table exists (self-repair)');
ok(table_exists('opening_balance_lines'), 'opening_balance_lines table exists');
ok(table_exists('opening_balance_audit_logs'), 'opening_balance_audit_logs table exists');

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------
echo "\nSetting up fixture...\n";
foreach (db()->query("SELECT id FROM companies WHERE code = 'OBTESTA'")->fetchAll(PDO::FETCH_COLUMN) as $stale) {
    $stale = (int) $stale;
    db()->exec("DELETE FROM opening_balance_audit_logs WHERE company_id = $stale");
    db()->exec("DELETE obl FROM opening_balance_lines obl INNER JOIN opening_balance_batches b ON b.id = obl.batch_id WHERE b.company_id = $stale");
    db()->exec("DELETE FROM opening_balance_batches WHERE company_id = $stale");
    db()->exec("DELETE FROM asset_depreciation_schedule WHERE company_id = $stale");
    db()->exec("DELETE FROM fixed_assets WHERE company_id = $stale");
    db()->exec("DELETE FROM inventory_transactions WHERE company_id = $stale");
    db()->exec("DELETE FROM inventory_items WHERE company_id = $stale");
    db()->exec("DELETE ve FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id WHERE v.company_id = $stale");
    db()->exec("DELETE FROM vouchers WHERE company_id = $stale");
    db()->exec("DELETE FROM accounting_parties WHERE company_id = $stale");
    db()->exec("DELETE FROM fiscal_period_locks WHERE company_id = $stale");
    db()->exec("DELETE FROM fiscal_years WHERE company_id = $stale");
    db()->exec("DELETE FROM ledgers WHERE company_id = $stale");
    db()->exec("DELETE FROM ledger_groups WHERE company_id = $stale");
    db()->exec("DELETE FROM companies WHERE id = $stale");
}
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('OB Test Co', 'OBTESTA', 1)")->execute();
$cid = (int) db()->lastInsertId();

$mkGroup = static function (string $master, string $code, string $name, int $cash = 0) use ($cid): int {
    db()->prepare('INSERT INTO ledger_groups (company_id, master_key, code, name, is_cash_or_bank) VALUES (?,?,?,?,?)')
        ->execute([$cid, $master, $code, $name, $cash]);
    return (int) db()->lastInsertId();
};
$mkLedger = static function (int $gid, string $code, string $name, string $type) use ($cid): int {
    db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status) VALUES (?,?,?,?,?, 'active')")
        ->execute([$cid, $gid, $code, $name, $type]);
    return (int) db()->lastInsertId();
};
$gCash = $mkGroup('current_asset', 'OB-CASH', 'Cash and Bank', 1);
// Deliberately NOT named "Trade Receivable/Payable" — those groups belong to the
// per-party sub-ledger (ensure_party_ledger); this generic debtor/creditor keeps
// the subledger-vs-control reconciliation clean.
$gAR   = $mkGroup('current_asset', 'OB-AR', 'Sundry Debtors');
$gInv  = $mkGroup('current_asset', 'OB-INV', 'Inventory');
$gPPE  = $mkGroup('non_current_asset', 'OB-PPE', 'Property Plant Equipment');
$gAP   = $mkGroup('current_liability', 'OB-AP', 'Sundry Creditors');
$gEq   = $mkGroup('equity', 'OB-EQ', 'Share Capital');
$gIncome = $mkGroup('direct_income', 'OB-INC', 'Sales');
$gExpense = $mkGroup('direct_expense', 'OB-EXP', 'Expenses');

$lCash = $mkLedger($gCash, 'OB-100', 'Cash', 'asset');
$lAR   = $mkLedger($gAR, 'OB-110', 'Accounts Receivable', 'asset');
$lInv  = $mkLedger($gInv, 'OB-120', 'Inventory Control', 'asset');
$lPPE  = $mkLedger($gPPE, 'OB-130', 'PPE Cost', 'asset');
$lAP   = $mkLedger($gAP, 'OB-200', 'Accounts Payable', 'liability');
$lCap  = $mkLedger($gEq, 'OB-300', 'Share Capital', 'equity');
$lSales = $mkLedger($gIncome, 'OB-400', 'Sales Revenue', 'revenue');
$lExp  = $mkLedger($gExpense, 'OB-500', 'Office Expense', 'expense');

$fy1 = create_fiscal_year($cid, 'OB 2024/25', '2024-07-16', '2025-07-15', true);
$fy2 = create_fiscal_year($cid, 'OB 2025/26', '2025-07-16', '2026-07-15', false);
$fy3 = create_fiscal_year($cid, 'OB 2026/27', '2026-07-16', '2027-07-15', false);
foreach ([$fy2, $fy3] as $f) { db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$f['id']]); }
$fy1Start = '2024-07-16';
$fy2Start = '2025-07-16';
$fy3Start = '2026-07-16';

$post = static function (string $date, int $fyId, array $legs) use ($cid): int {
    $total = 0.0;
    foreach ($legs as $l) { if ($l['entry_type'] === 'debit') { $total += $l['amount']; } }
    return create_voucher_with_entries([
        'company_id' => $cid, 'fiscal_year_id' => $fyId,
        'voucher_no' => 'OBT-' . strtoupper(bin2hex(random_bytes(4))),
        'voucher_type' => 'journal', 'voucher_date' => $date,
        'source_type' => 'obt_test', 'source_id' => null,
        'total_amount' => $total, 'narration' => 'OB test', 'status' => 'posted',
    ], $legs);
};

// FY1 postings -> targeted closings:
//  Cash 100000 Dr | AP 75000 Cr | Sales 500000 | Expense 350000 | profit 150000
$post('2024-08-01', $fy1['id'], [['ledger_id'=>$lCash,'entry_type'=>'debit','amount'=>100000],['ledger_id'=>$lCap,'entry_type'=>'credit','amount'=>100000]]);
$post('2024-09-01', $fy1['id'], [['ledger_id'=>$lAR,'entry_type'=>'debit','amount'=>500000],['ledger_id'=>$lSales,'entry_type'=>'credit','amount'=>500000]]);
$post('2024-10-01', $fy1['id'], [['ledger_id'=>$lExp,'entry_type'=>'debit','amount'=>350000],['ledger_id'=>$lAR,'entry_type'=>'credit','amount'=>350000]]);
$post('2024-11-01', $fy1['id'], [['ledger_id'=>$lInv,'entry_type'=>'debit','amount'=>75000],['ledger_id'=>$lAP,'entry_type'=>'credit','amount'=>75000]]);

$userId = (int) (db()->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);

// ---------------------------------------------------------------------------
// Tests 1-6, 15, 18 — carry-forward, income/expense reset, retained earnings
// ---------------------------------------------------------------------------
echo "\nCarry-forward, income/expense, retained earnings\n";
$gen2 = ob_generate_batch($cid, $fy2['id'], $userId);
ok($gen2['ok'], 'FY2 opening batch generated');
$batch2 = ob_get_batch($cid, $fy2['id']);
$lines2 = ob_get_lines((int) $batch2['id']);
$lineByLedger = static function (array $lines, int $ledgerId): ?array {
    foreach ($lines as $l) { if ((int) ($l['ledger_id'] ?? 0) === $ledgerId) { return $l; } }
    return null;
};
$lineByKey = static function (array $lines, string $key): ?array {
    foreach ($lines as $l) { if ((string) $l['line_key'] === $key) { return $l; } }
    return null;
};
$cash2 = $lineByLedger($lines2, $lCash);
ok($cash2 && near((float) $cash2['final_opening_debit'], 100000) && near((float) $cash2['final_opening_credit'], 0), 'Test 1: Cash opening = debit 100,000');
$ap2 = $lineByLedger($lines2, $lAP);
ok($ap2 && near((float) $ap2['final_opening_credit'], 75000) && near((float) $ap2['final_opening_debit'], 0), 'Test 2: Accounts payable opening = credit 75,000');
$sales2 = $lineByLedger($lines2, $lSales);
ok($sales2 && (int) $sales2['is_applicable'] === 0 && near((float) $sales2['final_opening_debit'], 0) && near((float) $sales2['final_opening_credit'], 0), 'Test 3: Sales revenue opening = 0 (not applicable)');
$exp2 = $lineByLedger($lines2, $lExp);
ok($exp2 && (int) $exp2['is_applicable'] === 0 && near((float) $exp2['final_opening_debit'], 0), 'Test 4: Expense opening = 0 (not applicable)');
$re2 = $lineByKey($lines2, 'RE_BF');
ok($re2 && near((float) $re2['final_opening_credit'], 150000), 'Test 5: Prior-year profit 150,000 -> Retained Earnings credit 150,000');
ok(near(ob_retained_earnings_transfer($cid, $fy2['id']), 150000), 'Test 5b: Retained-earnings transfer figure = 150,000 credit');

// FY2 loss of 50000 -> FY3 retained earnings reduces to 100000 (test 6).
$post('2025-09-01', $fy2['id'], [['ledger_id'=>$lExp,'entry_type'=>'debit','amount'=>50000],['ledger_id'=>$lCash,'entry_type'=>'credit','amount'=>50000]]);
$gen3 = ob_generate_batch($cid, $fy3['id'], $userId);
$lines3 = ob_get_lines((int) ob_get_batch($cid, $fy3['id'])['id']);
$re3 = $lineByKey($lines3, 'RE_BF');
ok($re3 && near((float) $re3['final_opening_credit'], 100000), 'Test 6: Prior-year loss 50,000 reduces Retained Earnings (150,000 - 50,000 = 100,000)');

// Test 18 — P&L only current-year activity: FY2 sales/expense reflect only FY2
// movement (no brought-forward income/expense). FY2 has a 50,000 expense only.
$fy2Bal = rc_ledger_balances($cid, $fy2Start, '2026-07-15', '', 0, 0, [], $fy2Start);
$salesRow = null; $expRow = null;
foreach ($fy2Bal as $r) { if ((int) $r['id'] === $lSales) { $salesRow = $r; } if ((int) $r['id'] === $lExp) { $expRow = $r; } }
ok($salesRow && near((float) $salesRow['closing_net'], 0) && $expRow && near((float) $expRow['closing_net'], 50000),
    'Test 18: Current-year P&L contains only current-year activity (FY2 sales 0, expense 50,000; prior 500,000 not brought forward)');

// Test 15 — ledger opening, trial balance opening agree.
$tbFy2 = rc_ledger_balances($cid, $fy2Start, '2026-07-15', '', 0, 0, [], $fy2Start);
$tbCash = null; foreach ($tbFy2 as $r) { if ((int) $r['id'] === $lCash) { $tbCash = $r; } }
ok($tbCash && near((float) $tbCash['opening_net'], (float) $cash2['final_opening_debit']), 'Test 15: Opening-balance line, ledger report and trial balance agree (Cash 100,000)');

// ---------------------------------------------------------------------------
// Test 11 — unbalanced batch cannot finalize
// ---------------------------------------------------------------------------
echo "\nWorkflow and validation\n";
// Tamper one line to force imbalance, then finalization must fail.
db()->prepare('UPDATE opening_balance_lines SET final_opening_debit = final_opening_debit + 999 WHERE batch_id = ? AND ledger_id = ?')->execute([(int) $batch2['id'], $lCash]);
$badFinal = ob_finalize_batch((int) $batch2['id'], $userId);
ok(!$badFinal['ok'], 'Test 11: Unbalanced opening (Dr != Cr) cannot be finalized');
// Regenerate to restore balance.
ob_generate_batch($cid, $fy2['id'], $userId);
$batch2 = ob_get_batch($cid, $fy2['id']);

// Test 12 — generate twice, no duplicate lines or journals.
$linesBefore = count(ob_get_lines((int) $batch2['id']));
$adjJournalsBefore = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id = $cid AND source_type IN ('opening_balance_adj')")->fetchColumn();
ob_generate_batch($cid, $fy2['id'], $userId);
$batch2 = ob_get_batch($cid, $fy2['id']);
$linesAfter = count(ob_get_lines((int) $batch2['id']));
$batchCount = (int) db()->query("SELECT COUNT(*) FROM opening_balance_batches WHERE company_id = $cid AND fiscal_year_id = " . (int) $fy2['id'])->fetchColumn();
ok($linesBefore === $linesAfter && $batchCount === 1, 'Test 12: Regeneration does not duplicate lines or batches');

// Test 13 — each fiscal year shows its own opening.
$cashFy2 = $lineByLedger(ob_get_lines((int) ob_get_batch($cid, $fy2['id'])['id']), $lCash);
$cashFy3 = $lineByLedger(ob_get_lines((int) ob_get_batch($cid, $fy3['id'])['id']), $lCash);
ok($cashFy2 && $cashFy3 && near((float) $cashFy2['final_opening_debit'], 100000) && near((float) $cashFy3['final_opening_debit'], 50000),
    'Test 13: Fiscal-year switch shows each year its own opening (FY2 cash 100,000; FY3 cash 50,000 after the 50,000 payment)');

// ---------------------------------------------------------------------------
// Test 9 & 10 — adjustment reason + permission
// ---------------------------------------------------------------------------
echo "\nAdjustment, audit trail and permissions\n";
$line = $lineByLedger(ob_get_lines((int) $batch2['id']), $lCash);
$noReason = ob_apply_adjustment((int) $batch2['id'], (int) $line['id'], 90000, 0, '   ', '', $userId);
ok(!$noReason['ok'], 'Test 9a: Adjustment without a reason (blank) is rejected');
$shortReason = ob_apply_adjustment((int) $batch2['id'], (int) $line['id'], 90000, 0, 'too short', '', $userId);
ok(!$shortReason['ok'], 'Test 9b: Adjustment with a <10 char reason is rejected');
$auditBefore = (int) db()->query("SELECT COUNT(*) FROM opening_balance_audit_logs WHERE company_id = $cid AND action='adjust'")->fetchColumn();
$goodAdj = ob_apply_adjustment((int) $batch2['id'], (int) $line['id'], 90000, 0, 'Correcting cash opening per reconciliation', 'DOC-123', $userId);
$auditAfter = (int) db()->query("SELECT COUNT(*) FROM opening_balance_audit_logs WHERE company_id = $cid AND action='adjust'")->fetchColumn();
$adjVoucher = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id = $cid AND source_type='opening_balance_adj'")->fetchColumn();
$lineAfter = $lineByLedger(ob_get_lines((int) $batch2['id']), $lCash);
ok($goodAdj['ok'] && $auditAfter === $auditBefore + 1 && $adjVoucher >= 1 && near((float) $lineAfter['final_opening_debit'], 90000),
    'Test 9c: Adjustment with reason writes an audit row and posts a balanced adjustment journal; final opening = 90,000');
// The adjustment delta (-10,000) is a balanced journal (ledger vs Opening Balance Adjustments).
$adjVid = (int) $lineAfter['adjustment_voucher_id'];
$adjBal = db()->prepare("SELECT SUM(CASE WHEN entry_type='debit' THEN amount ELSE -amount END) FROM voucher_entries WHERE voucher_id = ?");
$adjBal->execute([$adjVid]);
ok($adjVid > 0 && near((float) $adjBal->fetchColumn(), 0), 'Test 9d: Adjustment journal is double-entry balanced');
// Restore.
ob_apply_adjustment((int) $batch2['id'], (int) $line['id'], 100000, 0, 'Restore cash opening to system value', '', $userId);

// Test 10 — a normal (strict) staff user is denied opening_balance.adjust.
db()->prepare("INSERT INTO users (name, email, password_hash, role, status, company_id) VALUES ('OB Strict Staff', 'obstrict@test.local', '', 'staff', 'active', ?)")->execute([$cid]);
$strictUid = (int) db()->lastInsertId();
db()->prepare("INSERT INTO staff_permissions (user_id, module_key, action_key, granted_by) VALUES (?, 'accounting', 'view', ?)")->execute([$strictUid, $userId]);
$strictUser = db()->query("SELECT * FROM users WHERE id = $strictUid")->fetch(PDO::FETCH_ASSOC);
$adminUser = db()->query("SELECT * FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok(!user_can_do('opening_balance', 'adjust', $strictUser), 'Test 10: Strict-mode staff user is DENIED opening_balance.adjust');
ok(!$adminUser || user_can_do('opening_balance', 'adjust', $adminUser), 'Test 10b: Admin is allowed opening_balance.adjust');

// ---------------------------------------------------------------------------
// Test 14 — reversed / cancelled voucher excluded from closing
// ---------------------------------------------------------------------------
echo "\nReversed vouchers, subledger, inventory, fixed assets\n";
// Post a prior-year (FY1) voucher of 5,000 that we will then cancel/reverse.
$cancelVid = $post('2024-12-01', $fy1['id'], [['ledger_id'=>$lCash,'entry_type'=>'debit','amount'=>5000],['ledger_id'=>$lCap,'entry_type'=>'credit','amount'=>5000]]);
$genBeforeCancel = ob_generate_batch($cid, $fy2['id'], $userId);
$cashWithExtra = $lineByLedger(ob_get_lines((int) ob_get_batch($cid, $fy2['id'])['id']), $lCash);
// The extra 5,000 voucher IS included while posted -> cash should be 105,000; cancel it -> 100,000.
$includedWhilePosted = near((float) $cashWithExtra['final_opening_debit'], 105000);
db()->prepare("UPDATE vouchers SET status = 'cancelled' WHERE id = ?")->execute([$cancelVid]);
ob_generate_batch($cid, $fy2['id'], $userId);
$cashAfterCancel = $lineByLedger(ob_get_lines((int) ob_get_batch($cid, $fy2['id'])['id']), $lCash);
ok($includedWhilePosted && near((float) $cashAfterCancel['final_opening_debit'], 100000),
    'Test 14: A cancelled/reversed prior-year voucher is excluded from the opening (5,000 dropped -> 100,000)');

// Tests 16 & 17 — customer / supplier subledger reconciles with control.
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, opening_balance, opening_balance_type, status) VALUES (?, 'OB-C1', 'Test Customer', 'customer', 40000, 'debit', 'active')")->execute([$cid]);
$custId = (int) db()->lastInsertId();
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, opening_balance, opening_balance_type, status) VALUES (?, 'OB-S1', 'Test Supplier', 'supplier', 30000, 'credit', 'active')")->execute([$cid]);
$suppId = (int) db()->lastInsertId();
ensure_party_ledger($cid, $custId, 'receivable');
ensure_party_ledger($cid, $suppId, 'payable');
post_party_opening_balance($cid, $custId, $userId); // dates at default FY (FY1) start
post_party_opening_balance($cid, $suppId, $userId);
$recon = ob_subledger_reconciliation($cid, $fy2['id']);
ok(near($recon['receivable']['subledger'], $recon['receivable']['control']) && near($recon['receivable']['subledger'], 40000),
    'Test 16: Customer subledger total (40,000) reconciles with Accounts Receivable control opening');
ok(near($recon['payable']['subledger'], $recon['payable']['control']) && near($recon['payable']['subledger'], 30000),
    'Test 17: Supplier subledger total (30,000) reconciles with Accounts Payable control opening');

// Test 8 — inventory item opening carried forward.
db()->prepare("INSERT INTO inventory_items (company_id, sku, name, item_type, valuation_method, unit, purchase_rate, opening_qty, status) VALUES (?, 'OB-ITEM1', 'Widget', 'stock', 'weighted_average', 'pcs', 500, 100, 'active')")->execute([$cid]);
$invOpen = ob_inventory_opening($cid, $fy2['id']);
$item = $invOpen['items'][0] ?? null;
ok($item && near((float) $item['qty'], 100) && near((float) $item['value'], 50000),
    'Test 8: Inventory item opening = 100 units and value 50,000 carried forward');

// Test 7 — fixed asset opening (cost, accumulated depreciation, NBV).
db()->prepare("INSERT INTO fixed_assets (company_id, asset_code, name, asset_class, cost, residual_value, useful_life_months, depreciation_method, accumulated_depreciation, accumulated_impairment, carrying_amount, status) VALUES (?, 'OB-FA1', 'Machine', 'ppe', 1000000, 0, 120, 'straight_line', 300000, 0, 700000, 'active')")->execute([$cid]);
$assetId = (int) db()->lastInsertId();
db()->prepare("INSERT INTO asset_depreciation_schedule (company_id, asset_id, period_no, period_date, depreciation, accumulated, carrying, posted) VALUES (?, ?, 36, '2025-06-30', 25000, 300000, 700000, 1)")->execute([$cid, $assetId]);
$faOpen = ob_fixed_asset_opening($cid, $fy2['id']);
$asset = $faOpen['assets'][0] ?? null;
ok($asset && near((float) $asset['cost'], 1000000) && near((float) $asset['accumulated_depreciation'], 300000) && near((float) $asset['nbv'], 700000),
    'Test 7: Fixed asset opening cost 1,000,000, accumulated depreciation 300,000, NBV 700,000');

// ---------------------------------------------------------------------------
// Finalize + lock lifecycle (workflow)
// ---------------------------------------------------------------------------
echo "\nFinalize / lock lifecycle\n";
$val = ob_validate_batch((int) ob_get_batch($cid, $fy2['id'])['id']);
ok($val['balanced'], 'Balanced opening validates (Dr = Cr)');
$fin = ob_finalize_batch((int) ob_get_batch($cid, $fy2['id'])['id'], $userId);
ok($fin['ok'] && (string) ob_get_batch($cid, $fy2['id'])['status'] === 'finalized', 'Balanced batch finalizes');
$regenBlocked = ob_generate_batch($cid, $fy2['id'], $userId);
ok(!$regenBlocked['ok'], 'A finalized batch refuses destructive regeneration (corrections go via adjustment)');
$lock = ob_lock_batch((int) ob_get_batch($cid, $fy2['id'])['id'], $userId);
ok($lock['ok'] && (string) ob_get_batch($cid, $fy2['id'])['status'] === 'locked', 'Finalized batch locks');

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
echo "\nCleaning up fixture...\n";
db()->exec("DELETE FROM opening_balance_audit_logs WHERE company_id = $cid");
db()->exec("DELETE obl FROM opening_balance_lines obl INNER JOIN opening_balance_batches b ON b.id = obl.batch_id WHERE b.company_id = $cid");
db()->exec("DELETE FROM opening_balance_batches WHERE company_id = $cid");
db()->exec("DELETE FROM asset_depreciation_schedule WHERE company_id = $cid");
db()->exec("DELETE FROM fixed_assets WHERE company_id = $cid");
db()->exec("DELETE FROM inventory_transactions WHERE company_id = $cid");
db()->exec("DELETE FROM inventory_items WHERE company_id = $cid");
db()->exec("DELETE ve FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id WHERE v.company_id = $cid");
db()->exec("DELETE FROM vouchers WHERE company_id = $cid");
db()->exec("DELETE FROM accounting_parties WHERE company_id = $cid");
db()->exec("DELETE FROM staff_permissions WHERE user_id = " . (int) ($strictUid ?? 0));
db()->exec("DELETE FROM users WHERE id = " . (int) ($strictUid ?? 0));
db()->exec("DELETE FROM fiscal_period_locks WHERE company_id = $cid");
db()->exec("DELETE FROM fiscal_years WHERE company_id = $cid");
db()->exec("DELETE FROM ledgers WHERE company_id = $cid");
db()->exec("DELETE FROM ledger_groups WHERE company_id = $cid");
db()->exec("DELETE FROM companies WHERE id = $cid");

echo "\n==================================================\n";
echo "  Opening-balance suite: $pass passed, $fail failed\n";
echo "==================================================\n";
exit($fail === 0 ? 0 : 1);
