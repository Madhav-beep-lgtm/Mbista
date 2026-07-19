<?php
declare(strict_types=1);

/**
 * Stock Summary Report acceptance suite.
 *
 * Exercises app/stock_report_engine.php against a throwaway company:
 * opening-only items, purchase+sale in period, FIFO/WAvg/specific costing,
 * damage separation, adjustments, returns, transfers (company-neutral and
 * warehouse lens), manufacturing consume/produce, negative and zero stock,
 * location-specific item types (FG here / RM there), boundary dates,
 * ledger drill-down running balances, and the qty/amount reconciliation
 * opening + inward − outward − damage = closing on every row.
 *   php database/test_stock_summary.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/stock_report_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $eps = 0.02): bool { return abs($a - $b) < $eps; }

function ss_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code='STKTESTA'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM inventory_item_location_types WHERE company_id=$s");
        db()->exec("DELETE FROM inventory_cost_layers WHERE company_id=$s");
        db()->exec("DELETE FROM inventory_transactions WHERE company_id=$s");
        db()->exec("DELETE FROM inventory_ledger_mappings WHERE company_id=$s");
        db()->exec("DELETE FROM inventory_items WHERE company_id=$s");
        db()->exec("DELETE FROM warehouses WHERE company_id=$s");
        db()->exec("DELETE ve FROM voucher_entries ve JOIN vouchers v ON v.id=ve.voucher_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
}
ss_cleanup();

echo "Schema (self-repair)\n";
ok(table_exists('inventory_item_location_types'), 'inventory_item_location_types table exists (migration 058)');

db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Stock Report Co','STKTESTA',1)")->execute();
$cid = (int) db()->lastInsertId();
$_SESSION['company_id'] = $cid;
$fy = create_fiscal_year($cid, 'STK FY', '2026-07-17', '2027-07-16', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$_SESSION['fiscal_year_id'] = (int) $fy['id'];
db()->prepare("INSERT INTO warehouses (company_id, name, is_active) VALUES (?, 'Dept A (production)', 1)")->execute([$cid]);
$whA = (int) db()->lastInsertId();
db()->prepare("INSERT INTO warehouses (company_id, name, is_active) VALUES (?, 'Dept B (consuming)', 1)")->execute([$cid]);
$whB = (int) db()->lastInsertId();

$mkItem = static function (string $sku, string $type, string $method, float $openQty, float $rate, ?int $wh = null) use ($cid): int {
    db()->prepare("INSERT INTO inventory_items (company_id, sku, name, item_type, valuation_method, unit, purchase_rate, opening_qty, default_warehouse_id, status)
            VALUES (:cid, :sku, :n, :t, :m, 'pcs', :r, :oq, :wh, 'active')")
        ->execute(['cid' => $cid, 'sku' => $sku, 'n' => $sku . ' item', 't' => $type, 'm' => $method, 'r' => $rate, 'oq' => $openQty, 'wh' => $wh]);
    return (int) db()->lastInsertId();
};
$txn = static function (int $itemId, string $type, string $date, float $in, float $out, float $rate, ?int $wh = null, ?int $toWh = null, ?string $ref = null) use ($cid, $fy): int {
    db()->prepare('INSERT INTO inventory_transactions (company_id, fiscal_year_id, item_id, transaction_type, transaction_date, warehouse_id, to_warehouse_id, qty_in, qty_out, rate, amount, ref_no)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, (int) $fy['id'], $itemId, $type, $date, $wh, $toWh, $in, $out, $rate, round(($in ?: $out) * $rate, 2), $ref]);
    return (int) db()->lastInsertId();
};

// ---------------------------------------------------------------------------
// Fixture items
// ---------------------------------------------------------------------------
$itFifo = $mkItem('STK-FIFO', 'raw_material', 'fifo', 0, 0, $whA);
$txn($itFifo, 'purchase', '2026-07-18', 10, 0, 100, $whA);      // pre-period
$txn($itFifo, 'purchase', '2026-08-05', 10, 0, 140, $whA);      // in period
$txn($itFifo, 'sale', '2026-08-10', 0, 12, 500, $whA);          // rate 500 = SELLING price (must not be used)
$txn($itFifo, 'damage', '2026-08-12', 0, 2, 0, $whA);           // damage separated
$txn($itFifo, 'sales_return', '2026-08-15', 1, 0, 140, $whA);   // inward return at cost
$txn($itFifo, 'adjustment', '2026-08-18', 0, 1, 0, $whA);       // negative adjustment (non-damage outward)

$itWavg = $mkItem('STK-WAVG', 'stock', 'weighted_average', 0, 0, $whA);
$txn($itWavg, 'purchase', '2026-08-01', 10, 0, 100, $whA);
$txn($itWavg, 'purchase', '2026-08-02', 10, 0, 140, $whA);
$txn($itWavg, 'sale', '2026-08-03', 0, 5, 999, $whA);           // WAvg cost 120 -> 600

$itOpen = $mkItem('STK-OPEN', 'consumable', 'fifo', 100, 50, $whA); // opening-only, no movement

$itXfer = $mkItem('STK-XFER', 'finished_good', 'fifo', 0, 0, $whA);
$txn($itXfer, 'purchase', '2026-08-01', 20, 0, 200, $whA);
$txn($itXfer, 'warehouse_transfer', '2026-08-05', 0, 8, 200, $whA, $whB); // out of A
$txn($itXfer, 'warehouse_transfer', '2026-08-05', 8, 0, 200, $whB, $whA); // into B

$itMfg = $mkItem('STK-MFG-RM', 'raw_material', 'fifo', 0, 0, $whA);
$txn($itMfg, 'purchase', '2026-08-01', 30, 0, 10, $whA);
$txn($itMfg, 'consume', '2026-08-06', 0, 12, 10, $whA, null, 'MO-T1');         // manufacturing consumption -> outward
$itFg = $mkItem('STK-MFG-FG', 'finished_good', 'fifo', 0, 0, $whA);
$txn($itFg, 'produce', '2026-08-06', 4, 0, 55, $whA, null, 'MO-T1');           // production receipt -> inward

$itZero = $mkItem('STK-ZERO', 'stock', 'fifo', 0, 0, $whA);
$txn($itZero, 'purchase', '2026-08-01', 5, 0, 20, $whA);
$txn($itZero, 'sale', '2026-08-02', 0, 5, 90, $whA);            // zero closing

$itNeg = $mkItem('STK-NEG', 'stock', 'fifo', 0, 0, $whA);
$txn($itNeg, 'purchase', '2026-08-01', 3, 0, 10, $whA);
$txn($itNeg, 'sale', '2026-08-02', 0, 5, 90, $whA);             // legacy negative stock

$itSpec = $mkItem('STK-SPEC', 'stock', 'specific', 0, 0, $whA);
$txn($itSpec, 'purchase', '2026-08-01', 2, 0, 1000, $whA);
$txn($itSpec, 'purchase', '2026-08-02', 2, 0, 3000, $whA);
$txn($itSpec, 'sale', '2026-08-03', 0, 1, 5000, $whA);          // specific -> FIFO fallback: cost 1000

$itPret = $mkItem('STK-PRET', 'stock', 'fifo', 0, 0, $whA);
$txn($itPret, 'purchase', '2026-08-01', 10, 0, 60, $whA);
$txn($itPret, 'purchase_return', '2026-08-03', 0, 4, 60, $whA); // outward at cost

// FG in Dept A, RM in Dept B (location-specific classification)
db()->prepare('INSERT INTO inventory_item_location_types (company_id, item_id, warehouse_id, item_type, is_active) VALUES (?,?,?,?,1), (?,?,?,?,1)')
    ->execute([$cid, $itXfer, $whA, 'finished_good', $cid, $itXfer, $whB, 'raw_material']);

$find = static function (array $rows, string $sku): ?array {
    foreach ($rows as $r) { if ($r['sku'] === $sku) { return $r; } }
    return null;
};
$baseFilters = ['from' => '2026-08-01', 'to' => '2027-07-16'];

echo "\nFull-period report (from 2026-08-01, company-wide)\n";
$rep = sr_stock_summary($cid, $baseFilters);
$rows = $rep['rows'];

// FIFO: opening 10@100; in: 10@140 + return 1@140 = 1540; out: sale 12 (10x100+2x140=1280) + adj 1 (@140) = 13/1420; damage 2@140=280; closing 10+11-13-2=6 @140=840
$r = $find($rows, 'STK-FIFO');
ok($r !== null, 'FIFO row present');
ok(near($r['opening_qty'], 10) && near($r['opening_amount'], 1000), 'FIFO opening 10 @ 1,000 (pre-period purchase only)');
ok(near($r['in_qty'], 11) && near($r['in_amount'], 1540), 'FIFO inward 11 / 1,540 (purchase + sales return)');
ok(near($r['out_qty'], 13) && near($r['out_amount'], 1420), 'FIFO outward 13 / 1,420 at COST (sale 1,280 + adjustment 140), selling rate 500 ignored');
ok(near($r['damage_qty'], 2) && near($r['damage_amount'], 280), 'FIFO damage 2 / 280 separated from outward (no double count)');
ok(near($r['closing_qty'], 6) && near($r['closing_amount'], 840), 'FIFO closing 6 / 840 from remaining layers');
ok(near($r['opening_qty'] + $r['in_qty'] - $r['out_qty'] - $r['damage_qty'], $r['closing_qty']), 'FIFO reconciliation: opening + in - out - damage = closing');
ok(near($r['opening_amount'] + $r['in_amount'] - $r['out_amount'] - $r['damage_amount'], $r['closing_amount']), 'FIFO amount reconciliation holds');

$r = $find($rows, 'STK-WAVG');
ok($r !== null && near($r['out_amount'], 600), 'WAvg outward 5 @ avg 120 = 600 (not selling 999)');
ok($r !== null && near($r['closing_qty'], 15) && near($r['closing_amount'], 1800), 'WAvg closing 15 @ 1,800');

$r = $find($rows, 'STK-OPEN');
ok($r !== null && near($r['opening_qty'], 100) && near($r['opening_amount'], 5000)
    && near($r['in_qty'], 0) && near($r['closing_qty'], 100) && near($r['closing_amount'], 5000),
    'Opening-only item: opening = closing = 100 @ 5,000, no period movement');

$r = $find($rows, 'STK-XFER');
ok($r !== null && near($r['in_qty'], 20) && near($r['out_qty'], 0) && near($r['closing_qty'], 20),
    'Company-wide: warehouse transfer is NOT inward/outward (stock never left the entity)');

$r = $find($rows, 'STK-MFG-RM');
ok($r !== null && near($r['out_qty'], 12) && near($r['out_amount'], 120), 'Manufacturing consumption is outward at cost (12 @ 10)');
$r = $find($rows, 'STK-MFG-FG');
ok($r !== null && near($r['in_qty'], 4) && near($r['in_amount'], 220), 'Production receipt is inward at absorbed cost (4 @ 55)');

$r = $find($rows, 'STK-ZERO');
ok($r !== null && near($r['closing_qty'], 0) && near($r['closing_amount'], 0) && near($r['closing_rate'], 0),
    'Zero closing: qty 0, amount 0, rate 0 — no division by zero');

$r = $find($rows, 'STK-NEG');
ok($r !== null && near($r['closing_qty'], -2), 'Negative stock closes at -2 (legacy data tolerated)');
ok($r !== null && near($r['out_amount'], 30), 'Negative stock outward costs only the covered 3 @ 10 (never invents value)');

$r = $find($rows, 'STK-SPEC');
ok($r !== null && near($r['out_amount'], 1000) && near($r['closing_amount'], 7000),
    'Specific identification falls back to FIFO order (out 1,000; closing 3 @ 7,000)');

$r = $find($rows, 'STK-PRET');
ok($r !== null && near($r['out_qty'], 4) && near($r['out_amount'], 240), 'Purchase return is outward at cost (4 @ 60)');

echo "\nTotals\n";
$sumClosing = 0.0;
foreach ($rows as $row) { $sumClosing += $row['closing_amount']; }
ok(near($rep['totals']['closing_amount'], $sumClosing), 'Closing amount total equals the sum of rows');
ok(near($rep['totals']['opening_amount'] + $rep['totals']['in_amount'] - $rep['totals']['out_amount'] - $rep['totals']['damage_amount'], $rep['totals']['closing_amount']),
    'Grand totals reconcile: opening + inward - outward - damage = closing');

echo "\nWarehouse lens (Dept B only)\n";
$repB = sr_stock_summary($cid, $baseFilters + ['warehouse_ids' => [$whB]]);
$r = $find($repB['rows'], 'STK-XFER');
ok($r !== null && near($r['in_qty'], 8) && near($r['closing_qty'], 8), 'Dept B sees the transfer receipt of 8 and closing 8');
ok($r !== null && near($r['in_amount'], 1600, 0.5) && near($r['closing_amount'], 1600, 0.5), 'Dept B transfer valued at carrying cost 200/unit');

echo "\nDepartment-specific item type (FG in A, RM in B)\n";
$map = sr_location_type_map($cid);
ok(sr_resolve_item_type($map, $itXfer, $whA, 'stock') === 'finished_good', 'Same item resolves FG for Dept A');
ok(sr_resolve_item_type($map, $itXfer, $whB, 'stock') === 'raw_material', 'Same item resolves RM for Dept B');
ok(sr_resolve_item_type($map, $itXfer, null, 'stock') === 'stock', 'No location lens -> master type fallback');
$repA = sr_stock_summary($cid, $baseFilters + ['warehouse_ids' => [$whA]]);
$rA = $find($repA['rows'], 'STK-XFER');
$rB = $find($repB['rows'], 'STK-XFER');
ok($rA !== null && $rA['item_type'] === 'finished_good' && $rB !== null && $rB['item_type'] === 'raw_material',
    'Report shows FG under Dept A and RM under Dept B for the SAME item');

echo "\nFilters\n";
$repNoZeroClose = sr_stock_summary($cid, $baseFilters + ['zero_closing' => false, 'zero_movement' => true]);
ok($find($repNoZeroClose['rows'], 'STK-ZERO') === null, 'Zero-closing filter hides the zero-closing item');
$repNoMove = sr_stock_summary($cid, $baseFilters + ['zero_movement' => false, 'zero_closing' => true]);
ok($find($repNoMove['rows'], 'STK-OPEN') === null && $find($repNoMove['rows'], 'STK-FIFO') !== null,
    'Zero-movement filter hides the no-movement item, keeps moving ones');
$repNeg = sr_stock_summary($cid, $baseFilters + ['stock_status' => 'negative']);
ok(count($repNeg['rows']) === 1 && $repNeg['rows'][0]['sku'] === 'STK-NEG', 'Negative-stock status filter isolates STK-NEG');
$repSearch = sr_stock_summary($cid, $baseFilters + ['search' => 'WAVG']);
ok(count($repSearch['rows']) === 1 && $repSearch['rows'][0]['sku'] === 'STK-WAVG', 'Search narrows by code/name');
$repVal = sr_stock_summary($cid, $baseFilters + ['valuation' => 'weighted_average']);
ok($find($repVal['rows'], 'STK-WAVG') !== null && $find($repVal['rows'], 'STK-FIFO') === null, 'Valuation filter narrows without overriding item methods');

echo "\nBoundary dates\n";
// From = the exact purchase date: that purchase is IN the period, opening excludes it.
$repEdge = sr_stock_summary($cid, ['from' => '2026-07-18', 'to' => '2026-07-18']);
$r = $find($repEdge['rows'], 'STK-FIFO');
ok($r !== null && near($r['opening_qty'], 0) && near($r['in_qty'], 10) && near($r['closing_qty'], 10),
    'A movement dated ON the From date counts inside the period, not in opening');

echo "\nStock ledger drill-down\n";
$led = sr_stock_ledger($cid, $itFifo, '2026-08-01', '2027-07-16');
ok($led['item'] !== null, 'Ledger loads the item');
ok(near((float) $led['opening']['qty'], 10) && near((float) $led['opening']['value'], 1000), 'Ledger opening line = 10 @ 1,000');
$last = $led['rows'] !== [] ? $led['rows'][count($led['rows']) - 1] : null;
ok($last !== null && near($last['running_qty'], 6) && near($last['running_value'], 840), 'Ledger running balance ends at the summary closing (6 / 840)');
$saleRow = null;
foreach ($led['rows'] as $lr) { if ($lr['type'] === 'sale') { $saleRow = $lr; } }
ok($saleRow !== null && near($saleRow['out_amount'], 1280) && near($saleRow['out_rate'], 106.67),
    'Ledger sale row shows COST 1,280 (106.67/unit), not the 500 selling rate');

echo "\nPage-level pins (permissions, clamps, exports)\n";
$pageSrc = (string) file_get_contents(__DIR__ . '/../public_html/admin/stock-summary-report.php');
ok(str_contains($pageSrc, "require_permission('inventory', 'view')"), 'Report page gated by inventory.view');
ok(str_contains($pageSrc, "require_permission('reports', 'export')"), 'Exports gated by reports.export');
ok(str_contains($pageSrc, 'max($fyStart, min($fyEnd'), 'From/To dates clamped inside the selected fiscal year');
ok(str_contains($pageSrc, "export=csv") && str_contains($pageSrc, 'export=print'), 'CSV and Print/PDF exports wired');
ok(str_contains($pageSrc, 'per_page'), 'Server-side pagination present');
$ledgerSrc = (string) file_get_contents(__DIR__ . '/../public_html/admin/stock-ledger.php');
ok(str_contains($ledgerSrc, "require_permission('inventory', 'view')"), 'Ledger page gated by inventory.view');
ok(str_contains($ledgerSrc, 'Back to Stock Summary'), 'Drill-down returns to the report with filters kept');

echo "\nReports Center agreement\n";
require_once __DIR__ . '/../app/reports_engine.php';
$rcReport = rc_generate('inventory-summary', $cid, '2026-08-01', '2027-07-16', ['currency' => 'Rs.']);
$rcTotal = 0.0;
foreach ($rcReport['rows'] as $rcRow) { $rcTotal += (float) str_replace(',', '', (string) end($rcRow)); }
ok(near($rcTotal, $rep['totals']['closing_amount']),
    'Reports Center Inventory Summary total equals the Stock Summary page total (same replay engine)');
$rcSrc = (string) file_get_contents(__DIR__ . '/../app/reports_engine.php');
ok(str_contains($rcSrc, 'sr_stock_summary($scopeCompanyId'),
    'inventory-summary case is wired to sr_stock_summary, not qty x purchase_rate');

echo "\nGL backfill: subledger ties to the general ledger\n";
// Map GL ledgers, then retro-post the vouchers for STK-FIFO's unposted
// movements and prove GL inventory balance == the report's closing amount.
$mkG = static function (string $m, string $c, string $n) use ($cid): int { db()->prepare('INSERT INTO ledger_groups (company_id,master_key,code,name) VALUES (?,?,?,?)')->execute([$cid,$m,$c,$n]); return (int) db()->lastInsertId(); };
$mkL = static function (int $g, string $c, string $n, string $t) use ($cid): int { db()->prepare("INSERT INTO ledgers (company_id,group_id,code,name,type,status) VALUES (?,?,?,?,?, 'active')")->execute([$cid,$g,$c,$n,$t]); return (int) db()->lastInsertId(); };
$gA2 = $mkG('current_asset', 'STK-A', 'Inventories'); $gL2 = $mkG('current_liability', 'STK-L', 'Clearing'); $gX2 = $mkG('direct_expense', 'STK-X', 'COGS');
$lINV = $mkL($gA2, 'STK-INV', 'Inventory Control', 'asset');
$lCLR = $mkL($gL2, 'STK-CLR', 'Purchase Clearing', 'liability');
$lCOGS = $mkL($gX2, 'STK-COGS', 'Cost of Goods Sold', 'expense');
$lLOSS = $mkL($gX2, 'STK-LOSS', 'Inventory Loss', 'expense');
$lGAIN = $mkL($gX2, 'STK-GAIN', 'Inventory Gain', 'revenue');
$mapIns = db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, category, item_id, purpose, ledger_id) VALUES (?, 'global', NULL, NULL, ?, ?)");
foreach (['inventory_asset' => $lINV, 'purchase_clearing' => $lCLR, 'cogs' => $lCOGS, 'inventory_loss' => $lLOSS, 'inventory_gain' => $lGAIN] as $purpose => $lid) { $mapIns->execute([$cid, $purpose, $lid]); }

$gapBefore = sr_unposted_summary($cid);
ok($gapBefore['movements'] > 0, 'Unposted-summary sees planned movements without vouchers (' . $gapBefore['movements'] . ')');
$bf = sr_post_missing_movement_vouchers($cid, (int) db()->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn());
ok($bf['posted'] > 0 && $bf['skipped'] === [], 'Backfill posts every planned movement (' . $bf['posted'] . ' vouchers), none skipped');
$fifoItemRow = db()->query("SELECT * FROM inventory_items WHERE id=$itFifo")->fetch(PDO::FETCH_ASSOC);
$saleTxnCost = sr_txn_costs($cid, $fifoItemRow);
$saleVoucherAmt = (float) db()->query("SELECT v.total_amount FROM vouchers v JOIN inventory_transactions t ON t.voucher_id=v.id
    WHERE t.item_id=$itFifo AND t.transaction_type='sale' LIMIT 1")->fetchColumn();
ok(near($saleVoucherAmt, 1280), 'Retro-posted sale voucher carries the replayed COST 1,280 (never the 500 selling rate)');
$bf2 = sr_post_missing_movement_vouchers($cid, 1);
ok($bf2['posted'] === 0, 'Second backfill run posts nothing (idempotent via the source unique key)');
ok($bf['manufacturing'] > 0, 'Manufacturing consume/produce rows are skipped for the production journal, not force-posted');
// GL tie-out for STK-FIFO alone: its movements all touch $lINV.
$glFifo = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN ve.entry_type='debit' THEN ve.amount ELSE -ve.amount END),0)
    FROM voucher_entries ve JOIN vouchers v ON v.id=ve.voucher_id
    JOIN inventory_transactions t ON t.voucher_id=v.id AND t.item_id=$itFifo
    WHERE ve.ledger_id=$lINV AND v.status='posted'")->fetchColumn();
$repAfter = sr_stock_summary($cid, $baseFilters);
$fifoAfter = $find($repAfter['rows'], 'STK-FIFO');
ok(near($glFifo, (float) $fifoAfter['closing_amount']),
    'GL inventory ledger for STK-FIFO equals its stock closing value — subledger ties to trial balance (' . number_format($glFifo, 2) . ' == ' . number_format((float) $fifoAfter['closing_amount'], 2) . ')');

echo "\nFull reconcile: ONE action makes stock == GL\n";
// Map opening equity so item-opening vouchers credit a mapped contra.
$lOEQ2 = $mkL($mkG('equity', 'STK-E', 'Equity'), 'STK-OEQ', 'Opening Equity', 'equity');
$mapIns->execute([$cid, 'opening_equity', $lOEQ2]);
$adminUid = (int) db()->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
// Orphan consumption: materials issued to an order whose produce side posted
// elsewhere — must credit stock at replay cost and debit WIP/loss, not skip.
$itOrph = $mkItem('STK-ORPH', 'raw_material', 'fifo', 0, 0, $whA);
$txn($itOrph, 'purchase', '2026-08-01', 8, 0, 25, $whA);
$txn($itOrph, 'consume', '2026-08-04', 0, 3, 25, $whA, null, 'MO-ORPHAN');
$recRun = sr_reconcile_stock_to_gl($cid, $adminUid, true);
ok($recRun['openings']['posted'] > 0, 'Reconcile posted the opening-stock voucher(s) (' . $recRun['openings']['posted'] . ')');
$orphVoucher = (float) db()->query("SELECT v.total_amount FROM vouchers v JOIN inventory_transactions t ON t.voucher_id=v.id
    WHERE t.item_id=$itOrph AND t.transaction_type='consume' LIMIT 1")->fetchColumn();
ok(near($orphVoucher, 75), 'Orphan consume posted at replay cost 75 (3 @ 25), not skipped');
ok($recRun['production']['posted'] === 2 && $recRun['production']['skipped'] === [],
    'Reconcile posted the retro production journal AND the orphan consume (2 posted, none skipped)');
ok($recRun['reconciled'] === true,
    'RECONCILED: stock ' . number_format($recRun['after']['subledger'], 2) . ' == GL ' . number_format($recRun['after']['gl'], 2) . ' (difference ' . number_format($recRun['difference'], 2) . ')');
$recRun2 = sr_reconcile_stock_to_gl($cid, $adminUid, true);
ok($recRun2['openings']['posted'] === 0 && $recRun2['movements']['posted'] === 0 && $recRun2['production']['posted'] === 0 && $recRun2['reconciled'] === true,
    'Second reconcile run posts nothing and stays reconciled (idempotent)');

echo "\nTenant isolation\n";
$otherRep = sr_stock_summary(999999, $baseFilters);
ok($otherRep['rows'] === [], 'Another company id sees none of these items');

ss_cleanup();
echo "\n----------------------------------------\nPASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
