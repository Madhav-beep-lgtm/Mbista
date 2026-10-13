<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/inventory_unit_creation.php';
function assert_items(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$units = [2 => ['id'=>2,'name'=>'Piece','base_unit_id'=>2,'factor'=>1,'active'=>1],
    3 => ['id'=>3,'name'=>'Packet','base_unit_id'=>2,'factor'=>10,'active'=>1]];
$simple = inventory_unit_creation_values(['unit_name'=>'kg'], $units);
assert_items($simple['base_unit_id'] === null && $simple['factor'] === 1.0, 'Standalone units');
$box = inventory_unit_creation_values(['unit_name'=>'Box','base_unit_id'=>2,'factor'=>12], $units);
assert_items($box['base_unit_id'] === 2 && $box['factor'] === 12.0, 'Conversion unit');
foreach ([['unit_name'=>''], ['unit_name'=>'piece'], ['unit_name'=>str_repeat('x',41)],
    ['unit_name'=>'Box','base_unit_id'=>999,'factor'=>12], ['unit_name'=>'Box','base_unit_id'=>3,'factor'=>12],
    ['unit_name'=>'Box','base_unit_id'=>2,'factor'=>0], ['unit_name'=>'Box','base_unit_id'=>2,'factor'=>'bad']] as $invalid) {
    $rejected = false;
    try { inventory_unit_creation_values($invalid, $units); } catch (RuntimeException $expected) { $rejected = true; }
    assert_items($rejected, 'Invalid unit accepted');
}
$form = file_get_contents(__DIR__ . '/../app/views/partials/item_creation_form.php');
assert_items(strpos($form, 'name="is_ingredient"') > strrpos($form, '</fieldset>'), 'Recipe ingredient must be the final item option');
assert_items(strpos($form, 'name="is_ingredient"') < strpos($form, '>Save item'), 'Recipe ingredient must remain before Save');
$inventoryPage = file_get_contents(__DIR__ . '/../public_html/admin/accounting-inventory.php');
assert_items(!str_contains($inventoryPage, '>New item</button>'), 'Redundant New item button returned');
foreach (['name="tax_rate"', 'name="default_warehouse_id"', 'name="notes"'] as $removed) {
    assert_items(!str_contains($form, $removed), 'Removed item field returned: ' . $removed);
}
assert_items(str_contains($form, 'Default selling rate'), 'Selling rate must be labelled as a default');
$tradeForm = file_get_contents(__DIR__ . '/../app/views/vouchers/layout_trade.php');
assert_items(str_contains($tradeForm, 'name="value_rate[]"') && str_contains($tradeForm, "option.getAttribute('data-rate')"), 'Sales rate must default into editable transaction field');
$voucherForm = file_get_contents(__DIR__ . '/../public_html/admin/voucher-form.php');
assert_items(str_contains($voucherForm, 'Select an active warehouse for this purchase entry.'), 'Purchase warehouse validation missing');
foreach (['item_map[', 'purchases', 'sales_revenue', 'unit_id', 'inventory_asset', 'opening_equity'] as $required) {
    assert_items(str_contains($form, $required), 'Required item setting missing: ' . $required);
}
$workspace = file_get_contents(__DIR__ . '/../app/views/partials/items_workspace.php');
foreach (['manufacturing-orders', 'movement-purchase', 'item-stock-summary', '<dialog'] as $unwanted) {
    assert_items(!str_contains($workspace, $unwanted), 'Unrelated panel in Items: ' . $unwanted);
}
echo "Items workspace and unit validation tests passed.\n";
