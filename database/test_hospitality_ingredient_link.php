<?php
declare(strict_types=1);

/**
 * Ingredients come from the item master: ticking "use as a recipe ingredient"
 * on an inventory item puts it in the kitchen's list, the item stays the record
 * for name/code/unit/cost, the recipe-only fields survive a refresh, un-ticking
 * retires rather than deletes, and the whole sync is a fixed handful of queries
 * however many items there are.
 *   php database/test_hospitality_ingredient_link.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/hospitality_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.0001; }
function questions(): int { return (int) db()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value']; }

function hil_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('HING1','HING2')")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        if (table_exists('hospitality_recipe_lines')) {
            db()->exec("DELETE l FROM hospitality_recipe_lines l JOIN hospitality_recipes r ON r.id = l.recipe_id WHERE r.company_id=$s");
        }
        foreach (['hospitality_recipes', 'hospitality_menu_items', 'hospitality_ingredients', 'hospitality_settings',
                  'inventory_cost_layers', 'inventory_transactions', 'inventory_items'] as $t) {
            if (table_exists($t) && column_exists($t, 'company_id')) { db()->exec("DELETE FROM `$t` WHERE company_id=$s"); }
        }
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email IN ('hing1@test.local','hing2@test.local')")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
hil_cleanup();

function hil_company(string $code, string $email): array
{
    db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n, :c, 1, 1)')
        ->execute(['n' => $code . ' Kitchen (Books)', 'c' => $code]);
    $cid = (int) db()->lastInsertId();
    $uid = create_user(['name' => $code . ' Owner', 'email' => $email, 'password' => 'Secret#12345', 'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
    db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, hospitality_accounting_enabled)
            VALUES (:uid, :cid, :books, :org, :code, 1, 1)')
        ->execute(['uid' => $uid, 'cid' => $cid, 'books' => $cid, 'org' => $code . ' Kitchen', 'code' => $code . '-C']);
    $fy = create_fiscal_year($cid, $code . ' 2026/27', '2026-04-01', '2027-03-31', true);

    return ['cid' => $cid, 'uid' => $uid, 'fy' => (int) $fy['id']];
}

function hil_item(int $cid, string $sku, string $name, string $category, string $unit, float $rate, int $isIngredient, string $status = 'active'): int
{
    db()->prepare("INSERT INTO inventory_items (company_id, sku, name, category, item_type, is_ingredient, valuation_method, unit, purchase_rate, status)
        VALUES (:c,:s,:n,:cat,'raw_material',:i,'weighted_average',:u,:r,:st)")
        ->execute(['c' => $cid, 's' => $sku, 'n' => $name, 'cat' => $category, 'i' => $isIngredient, 'u' => $unit, 'r' => $rate, 'st' => $status]);
    return (int) db()->lastInsertId();
}

$A = hil_company('HING1', 'hing1@test.local');
$B = hil_company('HING2', 'hing2@test.local');
$_SESSION['company_id'] = $A['cid'];
set_context($A['cid'], $A['fy']);

echo "\n== The link exists ==\n";
ok(column_exists('inventory_items', 'is_ingredient'), 'An inventory item can be marked as an ingredient');
ok(column_exists('hospitality_ingredients', 'inventory_item_id'), 'An ingredient can point back at its item');
ok(hospitality_ingredients_linked(), 'The ingredient master reports itself as fed from inventory');

echo "\n== Ticking an item puts it in the kitchen list ==\n";
$rice = hil_item($A['cid'], 'RICE', 'Basmati Rice', 'Grains', 'KG', 120.50, 1);
$oil = hil_item($A['cid'], 'OIL', 'Sunflower Oil', 'Oils', 'Litre', 210.00, 1);
$plate = hil_item($A['cid'], 'PLATE', 'Paper Plate', 'Packing', 'Pcs', 3.00, 0);
$sync = hospitality_sync_ingredients_from_inventory($A['cid'], $A['uid']);
ok($sync['created'] === 2, 'Both marked items become ingredients');
$ings = db()->query('SELECT * FROM hospitality_ingredients WHERE company_id=' . $A['cid'] . ' ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
ok(count($ings) === 2, 'The unmarked item is not one of them');
$byCode = [];
foreach ($ings as $row) { $byCode[$row['code']] = $row; }
ok(isset($byCode['RICE'], $byCode['OIL']), '  ...they carry the item codes');
ok((string) $byCode['RICE']['name'] === 'Basmati Rice', 'The name comes from the item');
ok((string) $byCode['RICE']['category'] === 'Grains', 'The category comes from the item');
ok((string) $byCode['RICE']['purchase_unit'] === 'KG', 'The purchase unit comes from the item');
ok(near((float) $byCode['RICE']['purchase_cost'], 120.50), 'The cost comes from the item');
ok((int) $byCode['RICE']['inventory_item_id'] === $rice, 'The ingredient points back at its item');
ok(near((float) $byCode['RICE']['conversion_factor'], 1.0) && (string) $byCode['RICE']['recipe_unit'] === 'KG',
    'The recipe unit starts as the purchase unit, one to one');

echo "\n== Running it again changes nothing ==\n";
$q0 = questions();
$again = hospitality_sync_ingredients_from_inventory($A['cid'], $A['uid']);
$idleCost = questions() - $q0 - 2;
ok($again === ['created' => 0, 'updated' => 0, 'retired' => 0, 'restored' => 0], 'A second run writes nothing');
ok($idleCost <= 4, "  ...and costs $idleCost queries, so a page can sync on every load");

echo "\n== The recipe-only fields belong to the kitchen ==\n";
db()->exec("UPDATE hospitality_ingredients SET recipe_unit='Gram', conversion_factor=1000, wastage_pct=5, yield_pct=90
    WHERE company_id=" . $A['cid'] . " AND code='RICE'");
db()->exec('UPDATE inventory_items SET purchase_rate=135.00 WHERE id=' . $rice);
$sync = hospitality_sync_ingredients_from_inventory($A['cid'], $A['uid']);
ok($sync['updated'] === 1, 'A cost change on the item flows through');
$riceRow = db()->query("SELECT * FROM hospitality_ingredients WHERE company_id=" . $A['cid'] . " AND code='RICE'")->fetch(PDO::FETCH_ASSOC);
ok(near((float) $riceRow['purchase_cost'], 135.00), '  ...the new cost is here');
ok((string) $riceRow['recipe_unit'] === 'Gram', '  ...and the recipe unit the cook set survives it');
ok(near((float) $riceRow['conversion_factor'], 1000.0), '  ...as does the conversion');
ok(near((float) $riceRow['wastage_pct'], 5.0) && near((float) $riceRow['yield_pct'], 90.0), '  ...as do wastage and yield');

$unitCost = hospitality_ingredient_unit_cost($riceRow);
ok($unitCost['ok'] === true, 'The cost per recipe unit still computes');
// 135 per KG over 1000 grams, then 5% wasted and 90% yield.
$expected = 135.00 / 1000 / (1 - 0.05) / 0.90;
ok(abs($unitCost['unit_cost'] - $expected) < 0.000001, '  ...as cost over conversion, adjusted for wastage and yield');

echo "\n== Renaming the item renames the ingredient ==\n";
db()->exec("UPDATE inventory_items SET name='Premium Basmati Rice', category='Staples', unit='Sack' WHERE id=" . $rice);
hospitality_sync_ingredients_from_inventory($A['cid'], $A['uid']);
$riceRow = db()->query("SELECT * FROM hospitality_ingredients WHERE company_id=" . $A['cid'] . " AND code='RICE'")->fetch(PDO::FETCH_ASSOC);
ok((string) $riceRow['name'] === 'Premium Basmati Rice', 'The name follows the item');
ok((string) $riceRow['category'] === 'Staples', 'The category follows the item');
ok((string) $riceRow['purchase_unit'] === 'Sack', 'The purchase unit follows the item');
ok((string) $riceRow['recipe_unit'] === 'Gram', '  ...and the recipe unit still does not');

echo "\n== Un-ticking retires, it never deletes ==\n";
db()->exec('UPDATE inventory_items SET is_ingredient=0 WHERE id=' . $oil);
$sync = hospitality_sync_ingredients_from_inventory($A['cid'], $A['uid']);
ok($sync['retired'] === 1, 'Un-ticking an item retires its ingredient');
$oilRow = db()->query("SELECT * FROM hospitality_ingredients WHERE company_id=" . $A['cid'] . " AND code='OIL'")->fetch(PDO::FETCH_ASSOC);
ok($oilRow !== false, '  ...the row is still there');
ok((int) $oilRow['active'] === 0, '  ...marked inactive, so recipes that quote it keep their costed history');

db()->exec('UPDATE inventory_items SET is_ingredient=1 WHERE id=' . $oil);
$sync = hospitality_sync_ingredients_from_inventory($A['cid'], $A['uid']);
ok($sync['restored'] === 1, 'Re-ticking brings it back');
$oilRow = db()->query("SELECT * FROM hospitality_ingredients WHERE company_id=" . $A['cid'] . " AND code='OIL'")->fetch(PDO::FETCH_ASSOC);
ok((int) $oilRow['active'] === 1, '  ...active again');
ok((int) db()->query('SELECT COUNT(*) FROM hospitality_ingredients WHERE company_id=' . $A['cid'])->fetchColumn() === 2,
    '  ...without creating a duplicate');

echo "\n== An inactive item makes an inactive ingredient ==\n";
db()->exec("UPDATE inventory_items SET status='inactive' WHERE id=" . $oil);
hospitality_sync_ingredients_from_inventory($A['cid'], $A['uid']);
$oilRow = db()->query("SELECT active FROM hospitality_ingredients WHERE company_id=" . $A['cid'] . " AND code='OIL'")->fetch(PDO::FETCH_ASSOC);
ok((int) $oilRow['active'] === 0, 'Deactivating the item deactivates the ingredient');
db()->exec("UPDATE inventory_items SET status='active' WHERE id=" . $oil);
hospitality_sync_ingredients_from_inventory($A['cid'], $A['uid']);

echo "\n== Ingredients typed in before the link keep working ==\n";
db()->prepare("INSERT INTO hospitality_ingredients (company_id, inventory_item_id, code, name, purchase_unit, recipe_unit, conversion_factor, purchase_cost, cost_source, effective_date, wastage_pct, yield_pct, active)
    VALUES (:c, NULL, 'LEGACY', 'Hand-typed Salt', 'KG', 'Gram', 1000, 80, 'manual', '2026-04-01', 0, 100, 1)")
    ->execute(['c' => $A['cid']]);
$sync = hospitality_sync_ingredients_from_inventory($A['cid'], $A['uid']);
ok($sync['retired'] === 0, 'A hand-typed ingredient is not retired by the sync');
$legacy = db()->query("SELECT * FROM hospitality_ingredients WHERE company_id=" . $A['cid'] . " AND code='LEGACY'")->fetch(PDO::FETCH_ASSOC);
ok($legacy !== false && (int) $legacy['active'] === 1, '  ...it stays active and untouched');
db()->prepare("INSERT INTO hospitality_ingredients (company_id, inventory_item_id, code, name, purchase_unit, recipe_unit, conversion_factor, purchase_cost, cost_source, effective_date, wastage_pct, yield_pct, active)
    VALUES (:c, NULL, 'LEGACY2', 'Another Hand-typed', 'KG', 'Gram', 1000, 80, 'manual', '2026-04-01', 0, 100, 1)")
    ->execute(['c' => $A['cid']]);
ok(true, '  ...and a second one is allowed (the unique key ignores NULL links)');

echo "\n== Tenant isolation ==\n";
hil_item($B['cid'], 'RICE', 'Their Rice', 'Grains', 'KG', 999.00, 1);
hospitality_sync_ingredients_from_inventory($B['cid'], $B['uid']);
$mine = db()->query("SELECT purchase_cost FROM hospitality_ingredients WHERE company_id=" . $A['cid'] . " AND code='RICE'")->fetchColumn();
ok(near((float) $mine, 135.00), "Another tenant's item of the same code does not touch this one");
ok((int) db()->query('SELECT COUNT(*) FROM hospitality_ingredients WHERE company_id=' . $B['cid'])->fetchColumn() === 1,
    '  ...and it gets exactly its own');

echo "\n== Shape: many items, still a fixed cost ==\n";
for ($i = 0; $i < 120; $i++) {
    hil_item($A['cid'], 'BULK-' . $i, 'Bulk Item ' . $i, 'Bulk', 'KG', 10.0 + $i, 1);
}
$q0 = questions();
$sync = hospitality_sync_ingredients_from_inventory($A['cid'], $A['uid']);
$createCost = questions() - $q0 - 2;
ok($sync['created'] === 120, '120 newly ticked items all arrive');
ok($createCost < 130, "  ...in $createCost queries — the reads are batched, only the writes are per item");
$q0 = questions();
hospitality_sync_ingredients_from_inventory($A['cid'], $A['uid']);
$steadyCost = questions() - $q0 - 2;
ok($steadyCost <= 4, "  ...and with 122 ingredients settled, a further sync costs $steadyCost queries");

hil_cleanup();

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass   FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
