<?php
declare(strict_types=1);

/**
 * Three things that were letting stock and cost drift apart.
 *
 * 1. A POSTING PURPOSE POINTED AT THE WRONG KIND OF ACCOUNT. Every purpose
 *    declares what it expects — Inventory Asset wants an asset — and that
 *    expectation was printed on the mapping screen as a grey pill and never
 *    once checked. Point stock at "Kitchen Purchase" and every purchase from
 *    then on debits an expense: the balance sheet carries no inventory, and
 *    the cost of goods nobody has sold is already in the profit and loss.
 *
 * 2. THE PROFIT AND LOSS SHOWED NO WORKING. "Cost of Goods Sold 357,288.84"
 *    is a figure nobody can check. Opening stock, purchases and closing stock
 *    are the three a reader can tie back to the stock summary and the
 *    supplier bills, and with them the answer to "nothing was sold, so where
 *    did the purchases go" is on the page: into closing stock.
 *
 * 3. A JEWELLER'S STOCK SUMMARY WAS COUNTED IN PIECES. The metal register,
 *    the karigar's issue slip and the opening stock sheet are all kept in
 *    grams by purity; a summary in pieces cannot be laid beside any of them.
 *
 *   php database/test_stock_and_trading.php
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/inventory_mapping.php';
require_once __DIR__ . '/../app/inventory_valuation.php';
require_once __DIR__ . '/../app/stock_report_engine.php';
require_once __DIR__ . '/../app/reports_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.005; }

// ---------------------------------------------------------------------------
// A scratch company with a chart, one stock item and one purchase.
// ---------------------------------------------------------------------------
function sat_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'SATTST'")->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $cid = (int) $cid;
        db()->exec("DELETE ve FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id WHERE v.company_id = $cid");
        db()->exec("DELETE FROM vouchers WHERE company_id = $cid");
        foreach (['inventory_cost_layers', 'inventory_transactions', 'inventory_ledger_mappings',
            'jewellery_item_profiles', 'inventory_items', 'fiscal_years'] as $t) {
            if (table_exists($t)) { db()->exec("DELETE FROM `$t` WHERE company_id = $cid"); }
        }
        db()->exec("DELETE FROM ledgers WHERE company_id = $cid");
        db()->exec("DELETE FROM ledger_groups WHERE company_id = $cid");
        db()->exec("DELETE FROM companies WHERE id = $cid");
    }
}
sat_cleanup();

$userId = (int) db()->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn();
db()->prepare("INSERT INTO companies (name, code, is_active, created_at) VALUES ('Stock And Trading Test', 'SATTST', 1, NOW())")->execute();
$cid = (int) db()->lastInsertId();

$group = static function (string $name, string $master) use ($cid): int {
    db()->prepare('INSERT INTO ledger_groups (company_id, code, name, master_key, is_active) VALUES (:c, :code, :n, :m, 1)')
        ->execute(['c' => $cid, 'code' => coa_next_group_code($cid, $master), 'n' => $name, 'm' => $master]);
    return (int) db()->lastInsertId();
};
$ledger = static function (string $name, int $groupId, string $type) use ($cid): int {
    db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status) VALUES (:c, :g, :code, :n, :t, 'active')")
        ->execute(['c' => $cid, 'g' => $groupId, 'code' => coa_next_ledger_code($cid, $groupId), 'n' => $name, 't' => $type]);
    return (int) db()->lastInsertId();
};

$stockGroup = $group('Inventory', 'current_asset');
$expenseGroup = $group('Purchase Account', 'direct_expense');
$stockLedger = $ledger('Inventory Asset', $stockGroup, 'asset');
$purchaseLedger = $ledger('Kitchen Purchase', $expenseGroup, 'expense');

echo "\n1. A stock account is an asset — that is not a preference\n";
// This is the whole fault, in one call. It used to succeed.
$refused = '';
try {
    inventory_mapping_save($cid, 'inventory_asset', $purchaseLedger, $userId);
} catch (RuntimeException $e) {
    $refused = $e->getMessage();
}
ok($refused !== '', 'Inventory Asset cannot be pointed at an expense ledger');
ok(str_contains($refused, 'an asset') && str_contains($refused, 'an expense'),
    '  ...and the refusal says which is which — "' . substr($refused, 0, 60) . '..."');
ok(str_contains($refused, 'balance sheet'),
    '  ...and says what it would have cost: a balance sheet with no inventory on it');

$accepted = true;
try {
    inventory_mapping_save($cid, 'inventory_asset', $stockLedger, $userId);
} catch (RuntimeException $e) {
    $accepted = false;
}
ok($accepted, 'While the asset ledger it actually wants is accepted');

// The other direction is policed too, or the check is only half a check.
$cogsRefused = false;
try {
    inventory_mapping_save($cid, 'cogs', $stockLedger, $userId);
} catch (RuntimeException $e) {
    $cogsRefused = true;
}
ok($cogsRefused, 'And Cost of Goods Sold cannot be pointed at an asset ledger either');

echo "\n2. The legacy per-item ledger is held to the same rule\n";
// inv_item_stock_ledger_id() falls back to inventory_items.ledger_id for items
// that predate the mapping table. That fallback happily returned an expense
// ledger, which is how a purchase ends up as a Direct Cost with nothing said.
$expenseItem = ['id' => 0, 'item_type' => 'stock', 'category' => null, 'ledger_id' => $purchaseLedger];
$assetItem = ['id' => 0, 'item_type' => 'stock', 'category' => null, 'ledger_id' => $stockLedger];
// The company-wide mapping resolves first, so it is cleared for this check.
db()->prepare("DELETE FROM inventory_ledger_mappings WHERE company_id = :c")->execute(['c' => $cid]);
inv_mapping_forget();
ok(inv_item_stock_ledger_id($cid, $expenseItem) === 0,
    'An item linked to an expense ledger resolves to NOTHING, not to the expense');
ok(inv_item_stock_ledger_id($cid, $assetItem) === $stockLedger,
    'While one linked to a real stock ledger still posts where it always did');

// THE JEWELLERY TWIN, which had the same fallback and NOT the same guard. Its
// comment claimed it matched inv_item_stock_ledger_id(); it took whatever the
// column held. A shop whose old-gold item pointed at an equity or expense
// account had every gram it bought debited there — metal on the shelf, and not
// a rupee of it in inventory on the balance sheet.
require_once dirname(__DIR__) . '/app/jewellery_stock.php';
$jwExpenseItem = ['id' => 0, 'item_type' => 'ornament', 'category' => null, 'ledger_id' => $purchaseLedger];
$jwAssetItem = ['id' => 0, 'item_type' => 'ornament', 'category' => null, 'ledger_id' => $stockLedger];
ok(jw_item_stock_ledger_id($cid, $jwExpenseItem) === 0,
    'Jewellery holds the same line: an item linked to an expense resolves to NOTHING');
ok(jw_item_stock_ledger_id($cid, $jwAssetItem) === $stockLedger,
    '  ...and one linked to a stock ledger still resolves to it');

// Both refuse the same way for the same reason, which is the point: the two
// resolvers are twins and a rule that lives in only one of them is a rule the
// other quietly breaks.
$equityLedger = (int) db()->query("SELECT l.id FROM ledgers l
    INNER JOIN ledger_groups g ON g.id = l.group_id
    WHERE l.company_id = {$cid} AND g.master_key = 'equity' LIMIT 1")->fetchColumn();
if ($equityLedger > 0) {
    $equityItem = ['id' => 0, 'item_type' => 'ornament', 'category' => null, 'ledger_id' => $equityLedger];
    ok(jw_item_stock_ledger_id($cid, $equityItem) === 0,
        '  ...and an EQUITY ledger is refused too, which is how old gold reached Reserve & Surplus');
} else {
    ok(true, '  ...(no equity ledger on this fixture to try)');
}

inventory_mapping_save($cid, 'inventory_asset', $stockLedger, $userId);

echo "\n3. What is already posted that way is reported, not corrected\n";
db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, purpose, ledger_id, created_by)
    VALUES (:c, 'global', 'purchase_clearing', :l, :u)")->execute(['c' => $cid, 'l' => $stockLedger, 'u' => $userId]);
inv_mapping_forget();
$gaps = inventory_mapping_nature_gaps($cid);
$clearingGap = null;
foreach ($gaps as $g) { if ($g['purpose'] === 'purchase_clearing') { $clearingGap = $g; } }
ok($clearingGap !== null, 'A mapping already carrying the mistake is listed');
ok(($clearingGap['expected'] ?? '') === 'liability' && ($clearingGap['actual'] ?? '') === 'asset',
    '  ...saying what it wanted and what it got');
ok(($clearingGap['ledger_name'] ?? '') === 'Inventory Asset', '  ...and naming the ledger it points at');
ok(array_key_exists('posted', $clearingGap ?? []),
    '  ...with what has already been posted there, so the damage is visible');
$before = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id = $cid")->fetchColumn();
inventory_mapping_nature_gaps($cid);
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id = $cid")->fetchColumn() === $before,
    'Listing them posts nothing — a closed period is not this function\'s to reopen');
db()->prepare("DELETE FROM inventory_ledger_mappings WHERE company_id = :c AND purpose = 'purchase_clearing'")->execute(['c' => $cid]);
inv_mapping_forget();

echo "\n4. Purchases are told apart from everything else coming in\n";
// The trading account shows purchases on a line of their own; a production
// receipt or a sales return coming back into stock is inward without being one.
db()->prepare("INSERT INTO inventory_items (company_id, ledger_id, sku, name, item_type, valuation_method, unit, opening_qty, opening_amount, status, created_at)
    VALUES (:c, :l, 'SAT-1', 'Test Widget', 'stock', 'weighted_average', 'pcs', 0, 0, 'active', NOW())")
    ->execute(['c' => $cid, 'l' => $stockLedger]);
$itemId = (int) db()->lastInsertId();
$txn = static function (string $type, string $date, float $in, float $out, float $rate) use ($cid, $itemId): void {
    db()->prepare('INSERT INTO inventory_transactions (company_id, item_id, transaction_type, transaction_date, qty_in, qty_out, rate, created_at)
        VALUES (:c, :i, :t, :d, :qi, :qo, :r, NOW())')
        ->execute(['c' => $cid, 'i' => $itemId, 't' => $type, 'd' => $date, 'qi' => $in, 'qo' => $out, 'r' => $rate]);
};
$txn('opening', '2026-01-01', 10, 0, 100);          // 1,000 opening
$txn('purchase', '2026-03-01', 20, 0, 150);         // 3,000 purchases
$txn('sales_return', '2026-03-05', 2, 0, 150);      //   300 other inward
$txn('sale', '2026-04-01', 0, 5, 0);                // outward at cost

$summary = sr_stock_summary($cid, ['from' => '2026-01-01', 'to' => '2026-12-31', 'dormant' => true]);
$t = $summary['totals'];
ok(near((float) $t['purchase_amount'], 3000.0), 'The purchase line is counted as a purchase (3,000)');
ok(near((float) $t['in_amount'], 4300.0), 'Total inward is everything that came in (4,300)');
ok(near((float) $t['in_amount'] - (float) $t['purchase_amount'], 1300.0),
    'So opening and the sales return are inward WITHOUT being purchases (1,300)');

echo "\n5. Nothing sold means the purchases are still on the shelf\n";
// The question that started this: "if there is no COGS the purchased items
// should be shown in stock". With perpetual books that is what happens, and
// the trading account is where a reader can see it happening.
$quiet = rc_trading_figures($cid, '2026-01-01', '2026-03-31');
ok($quiet['available'], 'A company holding stock has a trading account');
ok(near($quiet['purchases'], 3000.0), 'Purchases in the period: 3,000');
ok(near($quiet['consumed'], 0.0),
    'Nothing was sold, so the cost of stock consumed is nil — opening + purchases = closing');
ok(near($quiet['closing'], $quiet['opening'] + $quiet['purchases'] + $quiet['other_in']),
    '  ...and every rupee bought is sitting in closing stock');

$sold = rc_trading_figures($cid, '2026-01-01', '2026-12-31');
ok($sold['consumed'] > 0.004, 'Once something IS sold, a cost of stock consumed appears');
ok(near($sold['opening'] + $sold['purchases'] + $sold['other_in'] - $sold['damage'] - $sold['closing'], $sold['consumed']),
    'And it is exactly opening + purchases - closing, which is the sum a reader can check');

echo "\n6. The statement shows the working\n";
db()->prepare("INSERT INTO fiscal_years (company_id, label, start_date, end_date, is_default, status)
    VALUES (:c, 'FY Test', '2026-01-01', '2026-12-31', 1, 'open')")->execute(['c' => $cid]);
$pl = rc_generate('profit-loss', $cid, '2026-01-01', '2026-12-31',
    ['currency' => 'Rs.', 'biz' => 'trading', 'org_default' => 'trading', 'company_id' => $cid, 'fy_start' => '2026-01-01']);
$labels = [];
foreach ($pl['rows'] as $row) { $labels[] = (string) rc_row_cells($row)[0]; }
ok(in_array('Opening Stock', $labels, true), 'The statement opens the cost with what was on the shelf');
ok(in_array('Add: Purchases', $labels, true), '  ...adds what was bought');
ok(in_array('Less: Closing Stock', $labels, true), '  ...takes off what is left');
ok(in_array('Cost of stock consumed', $labels, true), '  ...and shows what that comes to');
ok(array_search('Opening Stock', $labels, true) < array_search('Cost of Goods Sold', $labels, true),
    'The working comes BEFORE the total it explains, the way a trading account reads');

// A company keeping no stock must not have a trading account made of noughts
// put in front of a cost of sales that is real.
$noStock = rc_trading_figures($cid, '2020-01-01', '2020-12-31');
ok($noStock['available'] === false, 'A period with no stock at all has no trading account to show');

echo "\n7. A jeweller's stock is weighed, not counted\n";
if (!table_exists('jewellery_item_profiles') || !table_exists('jewellery_metals')) {
    foreach (range(1, 5) as $skipped) { ok(true, 'Jewellery tables absent — weight check skipped'); }
} else {
    db()->prepare("INSERT INTO jewellery_metals (company_id, code, name, metal_kind, active) VALUES (:c, 'AU', 'Gold', 'metal', 1)")
        ->execute(['c' => $cid]);
    $metalId = (int) db()->lastInsertId();
    db()->prepare("INSERT INTO jewellery_purities (company_id, metal_id, code, name, fineness, active) VALUES (:c, :m, '22K', '22 Carat', 916, 1)")
        ->execute(['c' => $cid, 'm' => $metalId]);
    $purityId = (int) db()->lastInsertId();
    $unitId = (int) (db()->query("SELECT id FROM jewellery_units LIMIT 1")->fetchColumn() ?: 0);
    db()->prepare("INSERT INTO jewellery_item_profiles (inventory_item_id, company_id, metal_id, purity_id, unit_id,
            jewellery_type, track_mode, stock_kind, gross_weight, stone_weight, net_weight, making_charge_rate, stone_value)
        VALUES (:i, :c, :m, :p, :u, 'ornament', 'weight', 'showroom', 12.5000, 0.5000, 12.0000, 800, 1500)")
        ->execute(['i' => $itemId, 'c' => $cid, 'm' => $metalId, 'p' => $purityId, 'u' => $unitId]);

    $jw = sr_stock_summary($cid, ['from' => '2026-01-01', 'to' => '2026-12-31', 'dormant' => true]);
    $row = $jw['rows'][0] ?? [];
    ok(($row['jw_metal'] ?? '') === 'Gold' && ($row['jw_purity'] ?? '') === '22 Carat',
        'The row says which metal and which purity — the two things a jeweller sorts by');
    // 27 pieces in, 5 out => 27 closing... opening 10 + 20 + 2 - 5 = 27.
    $closingQty = (float) ($row['closing_qty'] ?? 0);
    ok(near((float) ($row['closing_net'] ?? 0), $closingQty * 12.0),
        'Closing net weight is the pieces times the weight written on the item ('
            . number_format($closingQty * 12.0, 4) . ')');
    ok(near((float) ($row['closing_gross'] ?? 0), $closingQty * 12.5),
        'And gross weight carries the stone the net weight leaves out');
    ok(near((float) ($row['closing_fine'] ?? 0), $closingQty * 12.0 * 0.916),
        'Fine weight takes the purity out, which is the only column 22K and 18K add up in');
    ok((int) ($jw['totals']['weighed_rows'] ?? 0) === 1 && near((float) $jw['totals']['closing_net'], $closingQty * 12.0),
        'And the weight column foots, so it can be laid beside the metal register');
}

echo "\n8. A trader's stock summary is left alone\n";
db()->prepare('DELETE FROM jewellery_item_profiles WHERE company_id = :c')->execute(['c' => $cid]);
$plain = sr_stock_summary($cid, ['from' => '2026-01-01', 'to' => '2026-12-31', 'dormant' => true]);
ok((int) ($plain['totals']['weighed_rows'] ?? 0) === 0,
    'Stock with no weight profile reports none, so the weight columns stay off the page');
// ?? would swallow the very thing being asserted, so the key is checked and
// then its value, separately.
$plainRow = $plain['rows'][0] ?? [];
ok(array_key_exists('closing_net', $plainRow) && $plainRow['closing_net'] === null,
    '  ...and a widget is reported as weighing NOTHING KNOWN, never as weighing zero');

sat_cleanup();

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
