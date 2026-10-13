<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/inventory_pages.php';
foreach (inventory_pages() as $task => $page) {
    $file = __DIR__ . '/../public_html/admin/' . $page['file'];
    if (!is_file($file)) throw new RuntimeException('Missing inventory page: ' . $task);
    $source = file_get_contents($file);
    if (!str_contains($source, "define('INVENTORY_PAGE', '" . $task . "')")) throw new RuntimeException('Incorrect page task: ' . $task);
}
$source = file_get_contents(__DIR__ . '/../public_html/admin/accounting-inventory.php');
foreach (['id="inventory-method"', '<details class="feature-disclosure" id="warehouses">', 'Warehouses / Locations</strong>'] as $removedPanel) {
    if (str_contains($source, $removedPanel)) throw new RuntimeException('Removed inventory chrome remains: ' . $removedPanel);
}
foreach (['id="warehouses"', 'value="save_warehouse"', 'value="toggle_warehouse"', 'id="valuation-nrv"', 'value="post_nrv_assessment"'] as $retainedFeature) {
    if (!str_contains($source, $retainedFeature)) throw new RuntimeException('Required inventory feature missing: ' . $retainedFeature);
}
foreach (['<nav class="inventory-action-tabs"', '<nav class="mbw-tabbar inventory-module-tabs"', '<nav class="mbw-tabbar inventory-workspace-tabs"'] as $tabs) {
    if (str_contains($source, $tabs)) throw new RuntimeException('Duplicate in-page navigation remains');
}
if (!str_contains($source, "inventory_pages()[INVENTORY_PAGE]['file']")) throw new RuntimeException('Save must return to dedicated page');
echo "Dedicated inventory routes and duplicate-navigation checks passed.\n";
