<?php
declare(strict_types=1);

/**
 * Counted closing stock acceptance suite.
 *
 * The case this exists for: a cafe records the milk it BUYS and never records
 * the milk the coffee drank. The Stock Summary replays movements, so it says
 * every litre is still on the shelf, and no cost of sales was ever recognised.
 * Somebody counts the shelf, punches the figure in, and posting the difference
 * is what charges COGS and makes the derived closing stock equal the counted
 * one.
 *
 * Covers: punching and clearing counts, the variance shown before posting, a
 * shortfall charged to COGS, a surplus credited back, a count that agreed,
 * charging to inventory loss instead, warehouse-scoped counts, idempotence,
 * unposting, the guards, and subledger == GL after every posting.
 *   php database/test_stock_count.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/stock_report_engine.php';
require_once __DIR__ . '/../app/stock_count.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $eps = 0.011): bool { return abs($a - $b) < $eps; }

function stc_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code='STCOUNT'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE ve FROM voucher_entries ve JOIN vouchers v ON v.id=ve.voucher_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['inventory_stock_counts', 'inventory_ledger_mappings', 'inventory_cost_layers',
                  'inventory_transactions', 'inventory_items', 'warehouses'] as $t) {
            if (table_exists($t) && column_exists($t, 'company_id')) { db()->exec("DELETE FROM `$t` WHERE company_id=$s"); }
        }
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
}
stc_cleanup();

echo "Schema (self-repair)\n";
ok(sc_table_ready(), 'inventory_stock_counts table exists (migration 126)');
$enum = (string) db()->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_transactions'
      AND COLUMN_NAME = 'transaction_type'")->fetchColumn();
ok(str_contains($enum, "'stock_count'"), 'inventory_transactions accepts the stock_count movement type');
$plan = inv_movement_posting_plan('stock_count', 'out');
ok($plan === ['debit' => 'cogs', 'credit' => 'inventory_asset'], 'a counted shortfall posts Dr COGS / Cr Inventory');
$planIn = inv_movement_posting_plan('stock_count', 'in');
ok($planIn === ['debit' => 'inventory_asset', 'credit' => 'cogs'], 'stock found over posts Dr Inventory / Cr COGS');

// --------------------------------------------------------------- the fixture
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Counted Stock Cafe','STCOUNT',1)")->execute();
$cid = (int) db()->lastInsertId();
$fy = create_fiscal_year($cid, 'STC FY', '2026-07-17', '2027-07-16', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId = (int) $fy['id'];
$_SESSION['company_id'] = $cid;
$_SESSION['fiscal_year_id'] = $fyId;
// An admin, specifically: the render check at the end of this suite walks the
// page's own auth guard, which only lets staff and admins through.
$uid = (int) db()->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();

$mkLedger = static function (string $code, string $name, string $type) use ($cid): int {
    db()->prepare("INSERT INTO ledgers (company_id, code, name, type, status) VALUES (:cid,:code,:name,:type,'active')")
        ->execute(['cid' => $cid, 'code' => $code, 'name' => $name, 'type' => $type]);
    return (int) db()->lastInsertId();
};
$lStock = $mkLedger('STC-1400', 'Inventory Asset', 'asset');
$lCogs = $mkLedger('STC-5000', 'Cost of Goods Sold', 'expense');
$lLoss = $mkLedger('STC-5100', 'Inventory Loss', 'expense');
$lGain = $mkLedger('STC-4100', 'Inventory Gain', 'income');
$lClearing = $mkLedger('STC-2100', 'Purchase Clearing', 'liability');
$lEquity = $mkLedger('STC-3000', 'Opening Equity', 'equity');
foreach ([['inventory_asset', $lStock], ['cogs', $lCogs], ['inventory_loss', $lLoss],
          ['inventory_gain', $lGain], ['purchase_clearing', $lClearing], ['opening_equity', $lEquity]] as [$purpose, $ledgerId]) {
    db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, purpose, ledger_id) VALUES (:cid,'global',:p,:l)")
        ->execute(['cid' => $cid, 'p' => $purpose, 'l' => $ledgerId]);
}
db()->prepare("INSERT INTO warehouses (company_id, name, is_active) VALUES (?, 'Main store', 1)")->execute([$cid]);
$whMain = (int) db()->lastInsertId();

$mkItem = static function (string $sku, float $purchaseRate, ?int $wh = null) use ($cid): int {
    db()->prepare("INSERT INTO inventory_items (company_id, sku, name, item_type, valuation_method, unit, purchase_rate, opening_qty, default_warehouse_id, status)
            VALUES (:cid, :sku, :n, 'stock', 'weighted_average', 'ltr', :r, 0, :wh, 'active')")
        ->execute(['cid' => $cid, 'sku' => $sku, 'n' => $sku . ' item', 'r' => $purchaseRate, 'wh' => $wh]);
    return (int) db()->lastInsertId();
};
// A purchase the way the app records one: stock in, layers in, and the GL
// voucher that puts the same rupees on the inventory ledger. Posting the
// voucher matters here -- half these tests are about the subledger and the GL
// still agreeing after a count has been through them.
$buy = static function (int $itemId, string $date, float $qty, float $rate, ?int $wh = null) use ($cid, $fyId, $uid): int {
    db()->prepare('INSERT INTO inventory_transactions (company_id, fiscal_year_id, item_id, transaction_type, transaction_date, warehouse_id, qty_in, qty_out, rate, amount)
            VALUES (?,?,?,?,?,?,?,0,?,?)')
        ->execute([$cid, $fyId, $itemId, 'purchase', $date, $wh, $qty, $rate, round($qty * $rate, 2)]);
    $txnId = (int) db()->lastInsertId();
    inv_apply_movement($cid, $itemId, $qty, 0.0, $rate, $date, 'weighted_average', $txnId, $wh);
    $itemStmt = db()->prepare('SELECT * FROM inventory_items WHERE id = ?');
    $itemStmt->execute([$itemId]);
    $voucherId = inv_post_movement_voucher($cid, $fyId, $txnId, 'purchase', $itemStmt->fetch(PDO::FETCH_ASSOC),
        'in', round($qty * $rate, 2), $date, $uid);
    db()->prepare('UPDATE inventory_transactions SET voucher_id = ? WHERE id = ?')->execute([$voucherId, $txnId]);
    return $txnId;
};

$COUNT_DATE = '2027-01-31';
$ledgerBalance = static function (int $ledgerId) use ($cid): float {
    $q = db()->prepare("SELECT COALESCE(SUM(CASE WHEN ve.entry_type='debit' THEN ve.amount ELSE -ve.amount END),0)
        FROM voucher_entries ve JOIN vouchers v ON v.id = ve.voucher_id
        WHERE ve.ledger_id = :lid AND v.company_id = :cid AND v.status='posted'");
    $q->execute(['lid' => $ledgerId, 'cid' => $cid]);
    return round((float) $q->fetchColumn(), 2);
};
$closingOn = static function (int $itemId, string $date, array $warehouseIds = []) use ($cid): array {
    $report = sr_stock_summary($cid, ['from' => $date, 'to' => $date, 'dormant' => true, 'warehouse_ids' => $warehouseIds]);
    foreach ($report['rows'] as $row) {
        if ((int) $row['item_id'] === $itemId) { return $row; }
    }
    return [];
};
$closingOf = static fn (int $itemId, array $warehouseIds = []): array => $closingOn($itemId, $COUNT_DATE, $warehouseIds);

// Milk: bought 10 litres at 100, none of it ever recorded as consumed.
$milk = $mkItem('STC-MILK', 100.0, $whMain);
$buy($milk, '2026-08-01', 10, 100.0, $whMain);
// Beans: bought 5 kg at 50; the count will find MORE than the books hold.
$beans = $mkItem('STC-BEANS', 50.0, $whMain);
$buy($beans, '2026-08-01', 5, 50.0, $whMain);
// Sugar: the count will agree with the books to the decimal.
$sugar = $mkItem('STC-SUGAR', 20.0, $whMain);
$buy($sugar, '2026-08-01', 4, 20.0, $whMain);
// Cups: counted short, but charged to inventory LOSS rather than COGS.
$cups = $mkItem('STC-CUPS', 10.0, $whMain);
$buy($cups, '2026-08-01', 100, 10.0, $whMain);

echo "\nBefore anything is counted\n";
$milkRow = $closingOf($milk);
ok(near((float) $milkRow['closing_qty'], 10.0), 'the replay says all 10 litres of milk are still on the shelf');
ok(near((float) $milkRow['closing_amount'], 1000.0), 'and values them at 1,000');
ok($milkRow['counted_qty'] === null, 'an uncounted row carries no counted quantity');
ok(near($ledgerBalance($lCogs), 0.0), 'no cost of sales has been recognised');

// --------------------------------------------------------------- punching in
echo "\nPunching the counted quantity in\n";
$saved = sc_save_many($cid, $COUNT_DATE, SC_COMPANY_WIDE, [
    $milk => '6',
    $beans => '8',
    $sugar => '4',
    $cups => '90',
], [$milk => 'Counted by the morning shift'], $uid);
ok($saved['saved'] === 4 && $saved['skipped'] === [], 'four counted quantities are punched in');

$counts = sc_counts($cid, $COUNT_DATE, SC_COMPANY_WIDE);
ok(near((float) $counts[$milk]['counted_qty'], 6.0), 'the milk count is stored as 6');
ok((string) $counts[$milk]['notes'] === 'Counted by the morning shift', 'the counter note is kept with the count');
ok(!sc_is_posted($counts[$milk]), 'a punched count is not a posted one');

$report = sr_stock_summary($cid, ['from' => $COUNT_DATE, 'to' => $COUNT_DATE, 'dormant' => true, 'counts' => $counts]);
$milkRow = [];
foreach ($report['rows'] as $row) { if ((int) $row['item_id'] === $milk) { $milkRow = $row; } }
ok(near((float) $milkRow['counted_qty'], 6.0), 'the report shows the counted quantity beside the replayed one');
ok(near((float) $milkRow['count_variance_qty'], -4.0), 'and a difference of -4 litres');
ok(near((float) $milkRow['count_variance_amount'], -400.0), 'worth -400 at inventory cost');
ok(near((float) $milkRow['closing_qty'], 10.0), 'closing stock is untouched until the count is posted');
ok(near($ledgerBalance($lCogs), 0.0), 'and so are the books');

$summary = sc_sheet_summary($cid, $COUNT_DATE, SC_COMPANY_WIDE, $report['rows']);
ok($summary['open'] === 4 && $summary['posted'] === 0, 'the sheet reports four counts waiting to be posted');
ok(near($summary['shortfall_value'], 500.0), 'the shortfall waiting to be charged is 400 milk + 100 cups');
ok(near($summary['surplus_value'], 150.0), 'and 150 of beans was found over');

// A row nobody would otherwise see must not be hidden once it is counted.
$hidden = sr_stock_summary($cid, ['from' => $COUNT_DATE, 'to' => $COUNT_DATE,
    'zero_movement' => false, 'zero_closing' => false, 'stock_status' => 'negative', 'counts' => $counts]);
$hiddenIds = array_map(static fn (array $r): int => (int) $r['item_id'], $hidden['rows']);
ok(in_array($milk, $hiddenIds, true), 'a counted row survives the filters that hide quiet rows');

// --------------------------------------------------------------- the posting
echo "\nPosting the count (shortfall charged to COGS)\n";
$posted = sc_post($cid, $fyId, $COUNT_DATE, SC_COMPANY_WIDE, 'cogs', $uid);
ok($posted['skipped'] === [], 'every count posts: ' . implode(' | ', $posted['skipped']));
ok($posted['posted'] === 3, 'three differences are recorded as movements');
ok($posted['agreed'] === 1, 'and the one that agreed with the books is marked counted, not moved');
ok(near($posted['charged'], 500.0), 'cost charged out is 500 (400 milk + 100 cups)');
ok(near($posted['credited'], 150.0), 'and 150 comes back for the beans found over');

$milkRow = $closingOf($milk);
ok(near((float) $milkRow['closing_qty'], 6.0), 'closing stock now equals the counted quantity');
ok(near((float) $milkRow['closing_amount'], 600.0), 'valued at 600');
ok(near((float) $milkRow['out_qty'], 4.0), 'the 4 litres appear as outward movement in the period');
ok(near($ledgerBalance($lCogs), 500.0 - 150.0), 'COGS carries the shortfall net of the stock found over');
ok(near($ledgerBalance($lStock), 2330.0 - 500.0 + 150.0), 'inventory on the GL falls by the shortfall and rises by what was found');

$mvStmt = db()->prepare("SELECT * FROM inventory_transactions WHERE company_id = :cid AND item_id = :iid AND transaction_type = 'stock_count'");
$mvStmt->execute(['cid' => $cid, 'iid' => $milk]);
$movement = $mvStmt->fetch(PDO::FETCH_ASSOC);
ok($movement !== false, 'a stock_count movement was written for the milk');
ok(near((float) $movement['qty_out'], 4.0), 'for the 4 litres the shelf did not have');
ok((string) $movement['transaction_date'] === $COUNT_DATE, 'dated on the count date, not today');
ok(near((float) $movement['amount'], 400.0), 'stamped with the cost that actually came out of the layers');
ok((int) $movement['voucher_id'] > 0, 'and carrying its accounting voucher');

$beansRow = $closingOf($beans);
ok(near((float) $beansRow['closing_qty'], 8.0), 'the beans found over are now on the books');
ok(near((float) $beansRow['closing_amount'], 400.0), 'at carrying cost');

$counts = sc_counts($cid, $COUNT_DATE, SC_COMPANY_WIDE);
ok(sc_is_posted($counts[$sugar]), 'the count that agreed is posted');
ok((int) ($counts[$sugar]['txn_id'] ?? 0) === 0, 'and moved nothing, because there was nothing to move');
ok(near((float) $counts[$milk]['system_qty'], 10.0), 'the milk count records what the books said at the time');

echo "\nSubledger and GL still agree\n";
$whole = sr_stock_summary($cid, ['from' => '2999-12-31', 'to' => '2999-12-31']);
ok(near((float) $whole['totals']['closing_amount'], sr_inventory_gl_total($cid)),
    'stock subledger equals the inventory GL after posting a count ('
    . number_format((float) $whole['totals']['closing_amount'], 2) . ' vs ' . number_format(sr_inventory_gl_total($cid), 2) . ')');

echo "\nPosting again changes nothing\n";
$again = sc_post($cid, $fyId, $COUNT_DATE, SC_COMPANY_WIDE, 'cogs', $uid);
ok($again['posted'] === 0 && $again['agreed'] === 0, 'a second run finds nothing left to post');
ok(near($ledgerBalance($lCogs), 350.0), 'and does not charge the cost twice');

echo "\nA posted count is not silently overwritten\n";
$retry = sc_save_many($cid, $COUNT_DATE, SC_COMPANY_WIDE, [$milk => '3'], [], $uid);
ok($retry['saved'] === 0 && count($retry['skipped']) === 1, 'changing a posted count is refused, with a reason');
$counts = sc_counts($cid, $COUNT_DATE, SC_COMPANY_WIDE);
ok(near((float) $counts[$milk]['counted_qty'], 6.0), 'the posted figure stands');
$resubmit = sc_save_many($cid, $COUNT_DATE, SC_COMPANY_WIDE, [$milk => '6'], [], $uid);
ok($resubmit['skipped'] === [], 're-submitting the same sheet unchanged does not complain');

// ------------------------------------------------------------------ unposting
echo "\nTaking a count back\n";
$countId = (int) $counts[$milk]['id'];
$undone = sc_unpost($cid, $countId, $uid, $fyId);
ok($undone['reversed'] === true && $undone['txn_id'] > 0, 'the movement is reversed');
$milkRow = $closingOf($milk);
ok(near((float) $milkRow['closing_qty'], 10.0), 'closing stock goes back to what the movements say');
ok(near($ledgerBalance($lCogs), 350.0 - 400.0), 'and the cost of sales is taken back off');
$counts = sc_counts($cid, $COUNT_DATE, SC_COMPANY_WIDE);
ok(!sc_is_posted($counts[$milk]), 'the count is back on the sheet');
ok(near((float) $counts[$milk]['counted_qty'], 6.0), 'still holding the punched quantity, so it can be corrected');
$whole = sr_stock_summary($cid, ['from' => '2999-12-31', 'to' => '2999-12-31']);
ok(near((float) $whole['totals']['closing_amount'], sr_inventory_gl_total($cid)), 'subledger and GL still agree after unposting');

try {
    sc_unpost($cid, $countId, $uid, $fyId);
    ok(false, 'unposting an unposted count is refused');
} catch (RuntimeException $e) {
    ok(true, 'unposting an unposted count is refused');
}

echo "\nA correction can be punched and posted again\n";
$fix = sc_save_many($cid, $COUNT_DATE, SC_COMPANY_WIDE, [$milk => '7'], [], $uid);
ok($fix['saved'] === 1, 'the corrected quantity is accepted');
$posted = sc_post($cid, $fyId, $COUNT_DATE, SC_COMPANY_WIDE, 'cogs', $uid);
ok($posted['posted'] === 1 && near($posted['charged'], 300.0), 'the corrected difference of 3 litres is charged');
$milkRow = $closingOf($milk);
ok(near((float) $milkRow['closing_qty'], 7.0), 'closing stock equals the corrected count');

// ------------------------------------------------- charging to inventory loss
echo "\nA shortfall that was breakage, not sales\n";
$lossBefore = $ledgerBalance($lLoss);
$paper = $mkItem('STC-PAPER', 4.0, $whMain);
$buy($paper, '2026-08-01', 50, 4.0, $whMain);
sc_save_many($cid, '2027-02-15', SC_COMPANY_WIDE, [$paper => '40'], [], $uid);
$lossPost = sc_post($cid, $fyId, '2027-02-15', SC_COMPANY_WIDE, 'inventory_loss', $uid);
ok($lossPost['posted'] === 1 && near($lossPost['charged'], 40.0), 'the 10 lost units are charged at cost');
ok(near($ledgerBalance($lLoss), $lossBefore + 40.0), 'to the inventory loss account');
$lossMv = db()->prepare("SELECT transaction_type FROM inventory_transactions WHERE company_id=:cid AND item_id=:iid AND qty_out > 0");
$lossMv->execute(['cid' => $cid, 'iid' => $paper]);
ok((string) $lossMv->fetchColumn() === 'adjustment', 'as a plain adjustment, which is what that type has always meant');

// ---------------------------------------------------------- warehouse scoping
echo "\nCounting one location\n";
db()->prepare("INSERT INTO warehouses (company_id, name, is_active) VALUES (?, 'Kitchen', 1)")->execute([$cid]);
$whKitchen = (int) db()->lastInsertId();
$oil = $mkItem('STC-OIL', 200.0, $whMain);
$buy($oil, '2026-08-01', 6, 200.0, $whMain);
$buy($oil, '2026-08-01', 4, 200.0, $whKitchen);
ok(sc_scope_warehouse([]) === SC_COMPANY_WIDE, 'no location filter means the whole company');
ok(sc_scope_warehouse([$whMain]) === $whMain, 'one location filter means that shelf');
ok(sc_scope_warehouse([$whMain, $whKitchen]) === null, 'several locations at once is not a shelf anybody can walk');

sc_save_many($cid, '2027-03-10', $whKitchen, [$oil => '1'], [], $uid);
$kitchenPost = sc_post($cid, $fyId, '2027-03-10', $whKitchen, 'cogs', $uid);
ok($kitchenPost['posted'] === 1 && near($kitchenPost['charged'], 600.0), 'the kitchen is 3 litres short, charged at 200 each');
$oilKitchen = $closingOn($oil, '2027-03-10', [$whKitchen]);
ok(near((float) $oilKitchen['closing_qty'], 1.0), 'the kitchen closing quantity is the counted one');
$oilAll = $closingOn($oil, '2027-03-10');
ok(near((float) $oilAll['closing_qty'], 7.0), 'and the company total falls by exactly that difference');
$scopedMv = db()->prepare("SELECT warehouse_id FROM inventory_transactions WHERE company_id=:cid AND item_id=:iid AND transaction_type='stock_count'");
$scopedMv->execute(['cid' => $cid, 'iid' => $oil]);
ok((int) $scopedMv->fetchColumn() === $whKitchen, 'the movement is stamped with the location that was counted');

// ------------------------------------------------------------------- guards
echo "\nGuards\n";
$bad = sc_save_many($cid, '2027-04-01', SC_COMPANY_WIDE, [$milk => '-3', $beans => 'three'], [], $uid);
ok($bad['saved'] === 0 && count($bad['skipped']) === 2, 'a negative count and a word are both refused');
$cleared = sc_save_many($cid, '2027-04-01', SC_COMPANY_WIDE, [$milk => '2'], [], $uid);
ok($cleared['saved'] === 1, 'a fresh count for another date is its own sheet');
$clear = sc_save_many($cid, '2027-04-01', SC_COMPANY_WIDE, [$milk => ''], [], $uid);
ok($clear['cleared'] === 1, 'an empty box takes the row back off the sheet');
ok(sc_counts($cid, '2027-04-01', SC_COMPANY_WIDE) === [], 'and nothing is left behind');

$zero = sc_save_many($cid, '2027-04-02', SC_COMPANY_WIDE, [$beans => '0'], [], $uid);
ok($zero['saved'] === 1, 'counting zero is a count, not a blank');
$zeroPost = sc_post($cid, $fyId, '2027-04-02', SC_COMPANY_WIDE, 'cogs', $uid);
ok($zeroPost['posted'] === 1, 'an empty shelf posts the whole balance out');
ok(near((float) $closingOn($beans, '2027-04-02')['closing_qty'], 0.0), 'and the item closes at nothing');

$foreign = sc_save_many($cid, '2027-04-03', SC_COMPANY_WIDE, [999999 => '5'], [], $uid);
ok($foreign['saved'] === 0, 'an item id from another company is ignored');

db()->prepare("UPDATE fiscal_years SET status='closed' WHERE id=?")->execute([$fyId]);
sc_save_many($cid, '2027-05-01', SC_COMPANY_WIDE, [$sugar => '1'], [], $uid);
try {
    sc_post($cid, $fyId, '2027-05-01', SC_COMPANY_WIDE, 'cogs', $uid);
    ok(false, 'a closed period refuses the whole posting');
} catch (RuntimeException $e) {
    ok(true, 'a closed period refuses the whole posting');
}
$stray = db()->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE company_id=:cid AND transaction_date='2027-05-01'");
$stray->execute(['cid' => $cid]);
ok((int) $stray->fetchColumn() === 0, 'and leaves no stock behind it');
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fyId]);

echo "\nSubledger and GL agree at the end of all of it\n";
$whole = sr_stock_summary($cid, ['from' => '2999-12-31', 'to' => '2999-12-31']);
ok(near((float) $whole['totals']['closing_amount'], sr_inventory_gl_total($cid)),
    'stock ' . number_format((float) $whole['totals']['closing_amount'], 2) . ' = GL ' . number_format(sr_inventory_gl_total($cid), 2));

// ------------------------------------------------------- the page it lives on
//
// The arithmetic above is proved against the engine. This is the sheet itself:
// a column group was added to a table that already had six, and a form was
// wrapped around it. Both are the kind of change that is only wrong in a
// browser -- a row one cell short of its header, or a <form> inside a <form>,
// which HTML does not have and which silently drops every field after it.
echo "\nThe sheet renders\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin/stock-summary-report.php';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SESSION['user_id'] = $uid;
$_SESSION['company_id'] = $cid;
$_SESSION['fiscal_year_id'] = $fyId;
mark_company_pin_verified($cid); // the page's own company gate, same as a browser session
$_GET = ['applied' => 1, 'from' => '2026-07-17', 'to' => $COUNT_DATE, 'zero_movement' => 1, 'zero_closing' => 1];
$_POST = [];
ob_start();
$renderError = null;
try {
    include dirname(__DIR__) . '/public_html/admin/stock-summary-report.php';
} catch (Throwable $e) {
    $renderError = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
}
$html = (string) ob_get_clean();
ok($renderError === null, 'the Stock Summary page renders' . ($renderError === null ? ' (' . strlen($html) . ' bytes)' : ' — ' . $renderError));
$noise = [];
foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $needle) {
    $at = stripos($html, $needle);
    if ($at !== false) { $noise[] = trim(substr($html, $at, 160)); }
}
ok($noise === [], 'with no PHP notice, warning or deprecation' . ($noise === [] ? '' : ' — ' . implode(' | ', $noise)));

$sheetWidths = static function (string $markup): array {
    $widths = [];
    if (!preg_match('~<table class="ssr-table">(.*?)</table>~s', $markup, $tableMatch)) {
        return $widths;
    }
    preg_match_all('~<tr\b[^>]*>(.*?)</tr>~s', $tableMatch[1], $rowMatches);
    foreach ($rowMatches[1] as $rowHtml) {
        $width = 0;
        preg_match_all('~<t[dh]\b([^>]*)>~i', $rowHtml, $cellMatches);
        foreach ($cellMatches[1] as $attrs) {
            $width += preg_match('~colspan\s*=\s*"?(\d+)~i', $attrs, $cs) ? (int) $cs[1] : 1;
        }
        $widths[] = $width;
    }

    return $widths;
};

// A <form> inside a <form>: the parser throws the inner start tag away and
// lets the inner </form> close the OUTER one, so every field after it leaves
// the form it was written in — the counted quantities would simply not be sent.
$depth = 0; $nested = false;
preg_match_all('~<form\b|</form\s*>~i', $html, $formTags);
foreach ($formTags[0] as $tag) {
    if (stripos($tag, '</form') === 0) { $depth = max(0, $depth - 1); continue; }
    if (++$depth > 1) { $nested = true; }
}
ok(!$nested, 'no <form> is nested inside another');
ok(str_contains($html, 'name="counted['), 'the sheet carries a counted-quantity box per row');
ok(str_contains($html, 'Physical Count'), 'and a Physical Count column group');

// Every row of the sheet must be exactly as wide as its header, colspans
// counted. A row one cell short does not fail anywhere — it just slides every
// figure after it one column to the left.
$widths = $sheetWidths($html);
ok($widths !== [], 'the sheet was found in the rendered page (' . count($widths) . ' rows)');
ok(count(array_unique($widths)) === 1,
    'every row is exactly as wide as the header, colspans counted (widths: ' . implode(',', array_unique($widths)) . ')');

// Grouping inserts subtotal rows of its own, which are the rows most likely to
// be a cell short — they are written by hand rather than by the column loop.
foreach (['ledger', 'stock_kind', 'type'] as $groupBy) {
    $_GET = ['applied' => 1, 'from' => '2026-07-17', 'to' => $COUNT_DATE, 'group_by' => $groupBy,
        'zero_movement' => 1, 'zero_closing' => 1];
    ob_start();
    $groupError = null;
    try {
        include dirname(__DIR__) . '/public_html/admin/stock-summary-report.php';
    } catch (Throwable $e) {
        $groupError = get_class($e) . ': ' . $e->getMessage();
    }
    $groupHtml = (string) ob_get_clean();
    $groupWidths = $sheetWidths($groupHtml);
    ok($groupError === null && count(array_unique($groupWidths)) === 1 && ($groupWidths[0] ?? 0) === 27,
        'grouped by ' . $groupBy . ': every row is 27 columns wide (' . implode(',', array_unique($groupWidths)) . ')');
}

// The printable sheet is a second, hand-written table carrying the same
// columns, and the export path ends in exit() — so this goes LAST, and the
// assertions on it plus the suite's own tidy-up run from the shutdown handler
// that exit() still fires.
register_shutdown_function(static function (): void {
    $printHtml = (string) ob_get_clean();
    $printWidths = [];
    preg_match_all('~<tr\b[^>]*>(.*?)</tr>~s', $printHtml, $printRows);
    foreach ($printRows[1] as $rowHtml) {
        $width = 0;
        preg_match_all('~<t[dh]\b([^>]*)>~i', $rowHtml, $cellMatches);
        foreach ($cellMatches[1] as $attrs) {
            $width += preg_match('~colspan\s*=\s*"?(\d+)~i', $attrs, $cs) ? (int) $cs[1] : 1;
        }
        $printWidths[] = $width;
    }
    // Enough rows to have printed the items, not just the two header rows: a
    // page that dies mid-table still leaves a header that measures 27 wide.
    ok(count($printWidths) > 3 && count(array_unique($printWidths)) === 1 && ($printWidths[0] ?? 0) === 27,
        'the printable sheet is square too (' . count($printWidths) . ' rows of '
        . implode(',', array_unique($printWidths)) . ' columns)');
    ok(!str_contains($printHtml, 'Fatal error') && !str_contains($printHtml, 'Uncaught'),
        'and printed without dying halfway through it');
    ok(str_contains($printHtml, 'Physical Count'), 'and prints the counted closing stock');

    stc_cleanup();
    echo "\nPASS: {$GLOBALS['pass']} FAIL: {$GLOBALS['fail']}\n";
    exit($GLOBALS['fail'] > 0 ? 1 : 0);
});
$_GET = ['applied' => 1, 'from' => '2026-07-17', 'to' => $COUNT_DATE, 'export' => 'print'];
// The export sets a Content-Type on a CLI run that has already printed a
// hundred PASS lines. That warning is the harness's, not the page's.
$displayErrors = ini_get('display_errors');
$errorLevel = error_reporting();
ini_set('display_errors', '0');
error_reporting($errorLevel & ~E_WARNING);
ob_start();
include dirname(__DIR__) . '/public_html/admin/stock-summary-report.php';
ini_set('display_errors', (string) $displayErrors);
error_reporting($errorLevel);
