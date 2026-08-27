<?php
declare(strict_types=1);

/**
 * The periodic inventory system, and the rules that identify it.
 *
 * A purchase is not an expense and it is not inventory. It is a purchase: a
 * trading-account debit which, with opening stock and less closing stock, gives
 * the cost of what was actually sold. That is the whole of the periodic system,
 * and it is recognised by three facts that can be asserted:
 *
 *   the TRIAL BALANCE carries opening stock and purchases, and carries neither
 *   closing stock nor cost of sales -- because neither is a ledger balance;
 *
 *   the PROFIT AND LOSS shows cost of sales, worked out as Opening + Purchases
 *   - Closing rather than read off an account;
 *
 *   the BALANCE SHEET shows closing inventory, put there by the single
 *   year-end journal.
 *
 * Both systems are accepted practice. IAS 2 governs how inventory is MEASURED;
 * it does not prescribe which of them records it.
 *
 *   php database/test_periodic_inventory.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require_once $root . '/app/inventory_valuation.php';
require_once $root . '/app/inventory_mapping.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

/** Run something with the books on one method, then put the setting back. */
function with_method(string $method, callable $body)
{
    $previous = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'inventory_accounting'")
        ->fetchColumn();
    db()->prepare('REPLACE INTO settings (setting_key, setting_value) VALUES (:k, :v)')
        ->execute(['k' => 'inventory_accounting', 'v' => $method]);
    setting('inventory_accounting', '', true);
    try {
        return $body();
    } finally {
        if ($previous === false) {
            db()->exec("DELETE FROM settings WHERE setting_key = 'inventory_accounting'");
        } else {
            db()->prepare('REPLACE INTO settings (setting_key, setting_value) VALUES (:k, :v)')
                ->execute(['k' => 'inventory_accounting', 'v' => (string) $previous]);
        }
        setting('inventory_accounting', '', true);
    }
}

echo "1. Purchases is a head of the chart of accounts, not a kind of expense\n";
// Filed under Direct Expenses a purchase reads as money spent and gone; filed
// under Inventory it never passes through the profit and loss at all. Neither
// is what a purchase is.
$masters = ledger_masters();
ok(isset($masters['purchases']), 'The chart of accounts offers a Purchases head');
ok(($masters['purchases']['nature'] ?? '') === 'expense',
    '  ...whose nature is expense, so every report that asks "is this an expense?" still works');
ok(($masters['purchases']['sort_order'] ?? 0) < ($masters['direct_expense']['sort_order'] ?? 0),
    '  ...and which reads before Direct Expenses, the order a trading account is read in');
ok(($masters['direct_income']['sort_order'] ?? 0) < ($masters['purchases']['sort_order'] ?? 0),
    '  ...and after income, so Sales less Purchases is the shape of the statement');

$type = (string) db()->query("SELECT COLUMN_TYPE FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ledger_groups' AND column_name = 'master_key'")
    ->fetchColumn();
ok(str_contains($type, "'purchases'"), 'And the database accepts it as a group head');

$purposes = inventory_mapping_purposes();
foreach (['purchases' => 'expense', 'purchase_returns' => 'revenue', 'inventory_change' => 'revenue'] as $purpose => $expect) {
    ok(($purposes[$purpose]['expect'] ?? '') === $expect,
        "The '{$purpose}' account can be mapped, and must be a {$expect}");
}
$plan = inventory_mapping_plan();
ok(($plan['purchases'][1] ?? '') === 'purchases', 'A new chart puts Purchases under the Purchases head');

echo "\n2. Under PERIODIC, only money changing hands touches the ledger\n";
with_method('periodic', static function (): void {
    ok(inv_accounting_method() === 'periodic', 'The books report themselves as periodic');

    $purchase = inv_movement_posting_plan('purchase', 'in');
    ok(($purchase['debit'] ?? '') === 'purchases',
        'A purchase DEBITS Purchases — not an expense account, and not inventory');
    ok(($purchase['credit'] ?? '') === 'purchase_clearing', '  ...against the supplier');

    $return = inv_movement_posting_plan('purchase_return', 'out');
    ok(($return['credit'] ?? '') === 'purchase_returns', 'A purchase going back credits Purchase Returns');

    // THE ONE THAT LOOKS WRONG AND IS RIGHT. Cost of sales is not a ledger
    // under this system, so a sale cannot post to it.
    ok(inv_movement_posting_plan('sale', 'out') === null,
        'A sale posts NO cost entry, because cost of sales is not a ledger here');
    ok(inv_movement_posting_plan('sales_return', 'in') === null, '  ...nor does a sales return');

    foreach (['write_off', 'damage', 'expiry', 'adjustment', 'stock_count', 'material_issue',
        'production_receipt'] as $movement) {
        ok(inv_movement_posting_plan($movement, 'out') === null,
            "  ...nor does {$movement}: quantity moves, the ledger does not");
    }

    // Opening stock is the exception, and has to be: it IS a balance sheet
    // asset at the start of the year, brought forward from last year's close.
    $opening = inv_movement_posting_plan('opening', 'in');
    ok(($opening['debit'] ?? '') === 'inventory_asset',
        'Opening stock is still an asset — it is last year\'s closing, brought forward');
});

echo "\n3. The trial balance is where the two systems tell themselves apart\n";
// A periodic trial balance carries opening stock and purchases, and carries
// neither closing stock nor cost of sales. Not because they are hidden, but
// because neither is a ledger balance until the year-end journal is passed.
with_method('periodic', static function (): void {
    $posted = [];
    foreach (['opening', 'purchase', 'purchase_return', 'sale', 'sales_return', 'write_off',
        'damage', 'adjustment', 'stock_count', 'material_issue', 'material_return',
        'production_receipt', 'scrap_receipt'] as $movement) {
        $plan = inv_movement_posting_plan($movement, 'out');
        if ($plan !== null) {
            $posted[] = $plan['debit'];
            $posted[] = $plan['credit'];
        }
    }
    $posted = array_values(array_unique($posted));
    ok(!in_array('cogs', $posted, true),
        'NOTHING posts to cost of sales, so it cannot appear in a trial balance');
    ok(!in_array('inventory_change', $posted, true),
        'And nothing posts closing stock during the year — that is the year-end journal alone');
    ok(in_array('purchases', $posted, true), 'Purchases does appear, which is the point');
    ok(in_array('inventory_asset', $posted, true),
        'And so does stock in hand, carrying the opening figure');
    sort($posted);
    echo '        the whole periodic ledger footprint: ' . implode(', ', $posted) . "\n";
});

echo "\n4. Under PERPETUAL nothing has changed\n";
// The default. A company mid-year must not have its method moved underneath it
// by an upgrade, so the setting has to default to what the books already do.
with_method('perpetual', static function (): void {
    ok(inv_accounting_method() === 'perpetual', 'Perpetual is still perpetual');
    $purchase = inv_movement_posting_plan('purchase', 'in');
    ok(($purchase['debit'] ?? '') === 'inventory_asset', 'A purchase still debits Inventory');
    $sale = inv_movement_posting_plan('sale', 'out');
    ok(($sale['debit'] ?? '') === 'cogs' && ($sale['credit'] ?? '') === 'inventory_asset',
        'And a sale still posts its own cost of sales');
});

db()->exec("DELETE FROM settings WHERE setting_key = 'inventory_accounting'");
setting('inventory_accounting', '', true);
ok(inv_accounting_method() === 'perpetual',
    'With no setting saved at all, the books stay on the method they were already keeping');

echo "\n5. The purposes a movement needs mapped follow the method\n";
with_method('periodic', static function (): void {
    ok(inv_transaction_purposes('purchase') === ['purchases', 'purchase_clearing'],
        'A periodic purchase needs Purchases mapped, and asks for nothing else');
    ok(inv_transaction_purposes('sale') === [],
        'A periodic sale needs no ledger mapped at all');
});
with_method('perpetual', static function (): void {
    ok(inv_transaction_purposes('purchase') === ['inventory_asset', 'purchase_clearing'],
        'A perpetual purchase still needs Inventory mapped');
});

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
