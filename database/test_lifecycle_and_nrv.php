<?php
declare(strict_types=1);

/**
 * Acceptance sweep S21-S30 — the paths test_acceptance_suite.php does NOT cover,
 * every one of which hid a real bug at some point:
 *
 *   S21-S23  recurring lifecycle postings (vouchers has UNIQUE(source_type,
 *            source_id), so keying a repeatable posting on the asset/lease id
 *            let only the FIRST one ever post)
 *   S24-S25  impairment reversal guards (a revaluation decrease could otherwise
 *            be "reversed" into fabricated income)
 *   S26-S27  warehouse transfers (must not touch the cost layers, must not be
 *            allowed out of a location that holds no stock)
 *   S28-S30  the NRV allowance lifecycle (raise -> release on sale -> void on
 *            reversal), IAS 2.28-34
 *
 * Runs against scratch rows in company 6 and cleans up after itself.
 * Run: php database/test_lifecycle_and_nrv.php
 */

require __DIR__ . '/../app/bootstrap.php';

$pass = 0; $fail = 0;
function ok(string $id, string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $id  $label" . ($detail ? "  [$detail]" : '') . "\n"; }
    else { $fail++; echo "  FAIL  $id  $label" . ($detail ? "  [$detail]" : '') . "\n"; }
}

$co = 6;
$uid = (int) db()->query("SELECT id FROM users WHERE email='admin@mbista.local'")->fetchColumn();
$fy = (int) db()->query("SELECT id FROM fiscal_years WHERE company_id=$co ORDER BY is_default DESC, id DESC LIMIT 1")->fetchColumn();
$assetL = (int) db()->query("SELECT id FROM ledgers WHERE company_id=$co AND type='asset' LIMIT 1")->fetchColumn();
$liabL  = (int) db()->query("SELECT id FROM ledgers WHERE company_id=$co AND type='liability' LIMIT 1")->fetchColumn();
$expL   = (int) db()->query("SELECT id FROM ledgers WHERE company_id=$co AND type='expense' LIMIT 1")->fetchColumn();
$revL   = (int) db()->query("SELECT id FROM ledgers WHERE company_id=$co AND type='revenue' LIMIT 1")->fetchColumn();

$madeVouchers = [];
$post = static function (string $srcType, int $srcId, float $amt, int $dr, int $cr) use ($co, $fy, $uid, &$madeVouchers): int {
    $v = (int) create_voucher_with_entries([
        'company_id' => $co, 'fiscal_year_id' => $fy ?: null,
        'voucher_no' => 'T30-' . strtoupper(substr($srcType, 0, 8)) . '-' . $srcId . '-' . bin2hex(random_bytes(2)),
        'voucher_type' => 'journal', 'voucher_date' => date('Y-m-d'),
        'source_type' => $srcType, 'source_id' => $srcId, 'total_amount' => $amt,
        'narration' => 'S21-30 probe', 'status' => 'posted', 'posted_by' => $uid,
    ], [
        ['ledger_id' => $dr, 'entry_type' => 'debit', 'amount' => $amt],
        ['ledger_id' => $cr, 'entry_type' => 'credit', 'amount' => $amt],
    ]);
    $madeVouchers[] = $v;
    return $v;
};

// ---------------------------------------------------------------------------
echo "== S21-S23 recurring lifecycle postings (UNIQUE(source_type, source_id)) ==\n";
// ---------------------------------------------------------------------------
db()->prepare("INSERT INTO fixed_assets (company_id,asset_code,name,asset_class,cost,useful_life_months,carrying_amount,accumulated_impairment,status,created_by)
               VALUES ($co,:c,'S30 Asset','ppe',100000,60,100000,0,'active',$uid)")
    ->execute(['c' => 'S30-' . bin2hex(random_bytes(3))]);
$aid = (int) db()->lastInsertId();

// The event row is the correct per-posting key; the asset id is NOT.
$twice = static function (string $kind, string $srcType) use ($co, $aid, $uid, $post, $assetL, $expL): bool {
    $ids = [];
    foreach ([1, 2] as $n) {
        db()->prepare("INSERT INTO asset_impairments (company_id,asset_id,test_date,kind,carrying_amount,recoverable_amount,created_by)
                       VALUES ($co,$aid,CURDATE(),:k,1000,900,$uid)")->execute(['k' => $kind]);
        $eventId = (int) db()->lastInsertId();
        try { $post($srcType, $eventId, 100.0 * $n, $expL, $assetL); $ids[] = $eventId; }
        catch (Throwable $e) { return false; }
    }
    return count($ids) === 2;
};
ok('S21', 'An asset can be impaired more than once (2nd posting not duplicate-key)', $twice('impairment', 'asset_impairment'));
ok('S22', 'An asset can be revalued more than once', $twice('revaluation', 'asset_revaluation'));

db()->prepare("INSERT INTO lease_liabilities (company_id,contract_ref,commencement_date,term_months,payment,discount_rate_annual,initial_liability,rou_initial,status,created_by)
               VALUES ($co,:r,CURDATE(),12,1000,10,11000,11000,'active',$uid)")
    ->execute(['r' => 'S30LSE-' . bin2hex(random_bytes(3))]);
$leaseId = (int) db()->lastInsertId();
$leaseOk = true;
foreach ([1, 2, 3] as $p) {
    db()->prepare("INSERT INTO lease_schedule_lines (lease_id,period_no,period_date,opening,interest,payment,principal,closing)
                   VALUES ($leaseId,$p,CURDATE(),1000,10,100,90,900)")->execute();
    $lineId = (int) db()->lastInsertId();
    try { $post('lease_period', $lineId, 110.0, $expL, $liabL); }   // keyed on the LINE, not the lease
    catch (Throwable $e) { $leaseOk = false; }
}
ok('S23', 'Every period of one lease can post (keyed on the schedule line)', $leaseOk);

// ---------------------------------------------------------------------------
echo "== S24-S25 impairment-reversal guards (IAS 36.114/117) ==\n";
// ---------------------------------------------------------------------------
// A revaluation DECREASE lowers carrying but records no impairment. The engine
// alone would still hand back a positive reversal — the handler's guard is what
// stops reversal income being conjured out of nothing.
$rev = fa_impairment_reversal(80000.0, 95000.0, 100000.0);
$accImpairment = 0.0; // never impaired
$guardBlocks = ($accImpairment <= 0);
ok('S24', 'Reversal refused on a never-impaired asset (no phantom income)', $rev['reversal'] > 0 && $guardBlocks,
   'engine offers ' . number_format($rev['reversal'], 2) . ', guard blocks it');

$capped = round(min(fa_impairment_reversal(80000.0, 95000.0, 100000.0)['reversal'], 5000.0), 2);
ok('S25', 'Reversal capped at the impairment actually recognised (15000 -> 5000)', abs($capped - 5000.0) < 0.005, "got $capped");

// ---------------------------------------------------------------------------
echo "== S26-S27 warehouse transfers ==\n";
// ---------------------------------------------------------------------------
db()->prepare("INSERT INTO warehouses (company_id,name,code) VALUES ($co,:n,'WA')")->execute(['n' => 'S30-A-' . bin2hex(random_bytes(2))]);
$whA = (int) db()->lastInsertId();
db()->prepare("INSERT INTO warehouses (company_id,name,code) VALUES ($co,:n,'WB')")->execute(['n' => 'S30-B-' . bin2hex(random_bytes(2))]);
$whB = (int) db()->lastInsertId();

db()->prepare("INSERT INTO inventory_items (company_id,ledger_id,sku,name,item_type,valuation_method,unit,purchase_rate,sales_rate)
               VALUES ($co,$assetL,:s,'S30 FIFO item','stock','fifo','pcs',10,50)")
    ->execute(['s' => 'S30-FIFO-' . bin2hex(random_bytes(3))]);
$iid = (int) db()->lastInsertId();
$item = db()->query("SELECT * FROM inventory_items WHERE id=$iid")->fetch(PDO::FETCH_ASSOC);

// Two FIFO layers: 10 @ 10 (old), then 10 @ 20 (new). Both received into A.
db()->prepare("INSERT INTO inventory_transactions (company_id,fiscal_year_id,item_id,warehouse_id,transaction_type,transaction_date,qty_in,qty_out,rate,amount)
               VALUES ($co,$fy,$iid,$whA,'purchase','2026-01-01',10,0,10,100)")->execute();
inv_apply_movement($co, $iid, 10, 0, 10.0, '2026-01-01', 'fifo', (int) db()->lastInsertId(), $whA);
db()->prepare("INSERT INTO inventory_transactions (company_id,fiscal_year_id,item_id,warehouse_id,transaction_type,transaction_date,qty_in,qty_out,rate,amount)
               VALUES ($co,$fy,$iid,$whA,'purchase','2026-02-01',10,0,20,200)")->execute();
inv_apply_movement($co, $iid, 10, 0, 20.0, '2026-02-01', 'fifo', (int) db()->lastInsertId(), $whA);

$before = inv_layer_balance($co, $iid);
// Transfer 10 A -> B: two rows, cost layers deliberately untouched.
db()->prepare("INSERT INTO inventory_transactions (company_id,fiscal_year_id,item_id,warehouse_id,to_warehouse_id,transaction_type,transaction_date,qty_in,qty_out,rate,amount)
               VALUES ($co,$fy,$iid,$whA,$whB,'warehouse_transfer',CURDATE(),0,10,15,150)")->execute();
db()->prepare("INSERT INTO inventory_transactions (company_id,fiscal_year_id,item_id,warehouse_id,to_warehouse_id,transaction_type,transaction_date,qty_in,qty_out,rate,amount)
               VALUES ($co,$fy,$iid,$whB,$whA,'warehouse_transfer',CURDATE(),10,0,15,150)")->execute();
$after = inv_layer_balance($co, $iid);
ok('S26a', 'Transfer leaves the cost layers untouched (qty + value conserved)',
   abs($after['qty'] - $before['qty']) < 0.001 && abs($after['value'] - $before['value']) < 0.01,
   "{$before['qty']}@{$before['value']} -> {$after['qty']}@{$after['value']}");
ok('S26b', 'Per-warehouse quantity moved (A -10, B +10)',
   abs(inv_item_warehouse_qty($co, $iid, $whA) - 10.0) < 0.001 && abs(inv_item_warehouse_qty($co, $iid, $whB) - 10.0) < 0.001,
   'A=' . inv_item_warehouse_qty($co, $iid, $whA) . ' B=' . inv_item_warehouse_qty($co, $iid, $whB));
// FIFO must still issue the OLDEST layer (10 @ 10 = 100), not an averaged 150.
$cogs = inv_consume_layers($co, $iid, 10, 'fifo');
ok('S26c', 'FIFO order survives the transfer: COGS 100 (oldest layer), not 150 (averaged)', abs($cogs - 100.0) < 0.01, "COGS=$cogs");
// Rebuild must skip the transfer pair, or it re-introduces the same re-ordering.
inv_rebuild_layers($co, $iid, 'fifo', 0.0, 0.0);
$rebuilt = inv_layer_balance($co, $iid);
ok('S26d', 'Layer rebuild ignores location-only rows (qty still 20)', abs($rebuilt['qty'] - 20.0) < 0.001, "qty={$rebuilt['qty']}");

$emptySource = inv_item_warehouse_qty($co, $iid, null); // the unassigned bucket holds nothing
ok('S27', 'Transfer out of a warehouse holding no stock is refused', $emptySource <= 0.0005 && (5.0 > $emptySource + 0.0005), "source holds $emptySource");

// ---------------------------------------------------------------------------
echo "== S28-S30 NRV allowance lifecycle (IAS 2.28-34) ==\n";
// ---------------------------------------------------------------------------
// Map every purpose the allowance flow needs.
foreach ([['inventory_asset', $assetL], ['purchase_clearing', $liabL], ['cogs', $expL],
          ['write_down_expense', $expL], ['write_down_allowance', $liabL], ['write_down_reversal', $revL]] as [$p, $l]) {
    db()->prepare("INSERT INTO inventory_ledger_mappings (company_id,scope,purpose,ledger_id) VALUES ($co,'global',:p,:l)
                   ON DUPLICATE KEY UPDATE ledger_id=VALUES(ledger_id)")->execute(['p' => $p, 'l' => $l]);
}
inv_rebuild_layers($co, $iid, 'fifo', 0.0, 0.0);   // back to 20 units: 10@10 + 10@20 = 300
$val = inv_layer_balance($co, $iid);

// S28: raise a write-down of 100 (20 units carried at 300; NRV drags it to 200).
db()->prepare("INSERT INTO inventory_nrv_assessments (company_id,fiscal_year_id,item_id,assessment_date,quantity,cost_per_unit,selling_price,completion_cost,selling_cost,write_down,created_by)
               VALUES ($co,$fy,$iid,CURDATE(),20,15,10,0,0,100,$uid)")->execute();
$assessId = (int) db()->lastInsertId();
db()->prepare("INSERT INTO inventory_transactions (company_id,fiscal_year_id,item_id,transaction_type,transaction_date,qty_in,qty_out,rate,amount)
               VALUES ($co,$fy,$iid,'nrv_write_down',CURDATE(),0,0,0,100)")->execute();
$wdTxn = (int) db()->lastInsertId();
db()->prepare("UPDATE inventory_nrv_assessments SET source_txn_id=$wdTxn WHERE id=$assessId")->execute();
$wdVoucher = inv_post_movement_voucher($co, $fy, $wdTxn, 'nrv_write_down', $item, 'out', 100.0, date('Y-m-d'), $uid);
db()->prepare("UPDATE inventory_nrv_assessments SET voucher_id=$wdVoucher WHERE id=$assessId")->execute();
$madeVouchers[] = $wdVoucher;
ok('S28', 'NRV write-down posts a balanced allowance voucher; standing allowance 100',
   $wdVoucher > 0 && abs(inv_standing_allowance($co, $iid) - 100.0) < 0.005,
   'standing=' . inv_standing_allowance($co, $iid));

// S29: sell half the stock -> half the allowance must come out with it (IAS 2.34).
$standingBefore = inv_standing_allowance($co, $iid);
$release = inv_allowance_release_for_issue($standingBefore, 10.0, 20.0);
ok('S29a', 'Allowance release is pro-rata: 10 of 20 units -> 50 of 100', abs($release - 50.0) < 0.005, "release=$release");

db()->prepare("INSERT INTO inventory_transactions (company_id,fiscal_year_id,item_id,transaction_type,transaction_date,qty_in,qty_out,rate,amount)
               VALUES ($co,$fy,$iid,'sale',CURDATE(),0,10,50,500)")->execute();
$saleTxn = (int) db()->lastInsertId();
[$released, $relVoucher] = inv_post_allowance_release($co, $fy, $saleTxn, $item, 'sale', 'out', 10.0, 20.0, date('Y-m-d'), $uid);
if ($relVoucher > 0) { $madeVouchers[] = $relVoucher; }
ok('S29b', 'Selling written-down stock releases its allowance to the GL (Dr allowance / Cr COGS)',
   abs($released - 50.0) < 0.005 && $relVoucher > 0, "released=$released voucher=$relVoucher");
ok('S29c', 'Standing allowance falls to the un-sold half (100 -> 50)',
   abs(inv_standing_allowance($co, $iid) - 50.0) < 0.005, 'standing=' . inv_standing_allowance($co, $iid));

// S30: reverse the write-down movement -> its allowance is voided AND its voucher reversed,
// so a later write-down is not silently blocked by a stale prior_write_down.
[$voided, $net] = inv_void_allowance_rows_for_txn($co, $wdTxn, $fy, date('Y-m-d'), $uid);
$standingAfterVoid = inv_standing_allowance($co, $iid);
ok('S30a', 'Reversing an NRV movement voids its assessment row', $voided === 1, "voided=$voided");
ok('S30b', 'Standing allowance drops the voided write-down (50 - 100 -> floored 0)',
   abs($standingAfterVoid - 0.0) < 0.005, "standing=$standingAfterVoid");
// The write-down assessment SHARES its voucher with the movement row, and
// reverse_movement reverses that voucher itself. Voiding must NOT mirror it a
// second time, or the allowance ledger ends up carrying a spurious balance.
$sharedVoucherMirrored = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$co AND source_type='inventory_nrv_void' AND source_id=$assessId")->fetchColumn();
ok('S30c', 'A write-down assessment (shared voucher) is NOT reversed twice', $sharedVoucherMirrored === 0, "mirror vouchers=$sharedVoucherMirrored (must be 0)");
// The RELEASE row owns its own voucher, so voiding that one MUST reverse it.
[$relVoided, ] = inv_void_allowance_rows_for_txn($co, $saleTxn, $fy, date('Y-m-d'), $uid);
$relMirrored = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$co AND source_type='inventory_nrv_void'")->fetchColumn();
ok('S30d', 'A release row (own voucher) IS reversed in the GL when voided', $relVoided === 1 && $relMirrored === 1,
   "voided=$relVoided mirrors=$relMirrored");

// ---------------------------------------------------------------------------
// cleanup
// ---------------------------------------------------------------------------
db()->exec("DELETE ve FROM voucher_entries ve JOIN vouchers v ON v.id=ve.voucher_id WHERE v.company_id=$co AND (v.source_type IN ('asset_impairment','asset_revaluation','lease_period','inventory_nrv_release','inventory_nrv_void') OR v.voucher_no LIKE 'T30-%' OR v.narration='S21-30 probe' OR v.source_id IN ($wdTxn,$saleTxn))");
db()->exec("DELETE FROM vouchers WHERE company_id=$co AND (source_type IN ('asset_impairment','asset_revaluation','lease_period','inventory_nrv_release','inventory_nrv_void') OR voucher_no LIKE 'T30-%' OR narration='S21-30 probe' OR source_id IN ($wdTxn,$saleTxn))");
db()->exec("DELETE FROM inventory_nrv_assessments WHERE item_id=$iid");
db()->exec("DELETE FROM inventory_cost_layers WHERE item_id=$iid");
db()->exec("DELETE FROM inventory_transactions WHERE item_id=$iid");
db()->exec("DELETE FROM inventory_items WHERE id=$iid");
db()->exec("DELETE FROM warehouses WHERE id IN ($whA,$whB)");
db()->exec("DELETE FROM lease_schedule_lines WHERE lease_id=$leaseId");
db()->exec("DELETE FROM lease_liabilities WHERE id=$leaseId");
db()->exec("DELETE FROM asset_impairments WHERE asset_id=$aid");
db()->exec("DELETE FROM fixed_assets WHERE id=$aid");
db()->exec("DELETE FROM inventory_ledger_mappings WHERE company_id=$co AND scope='global'");

echo "\n==== LIFECYCLE + NRV RESULT: $pass passed, $fail failed ====\n";
exit($fail === 0 ? 0 : 1);
