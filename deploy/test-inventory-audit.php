<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/inventory_pages.php';
function audit_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
foreach ([
    [['task'=>'purchase','page'=>2], 'admin/inventory-purchases.php?page=2'],
    [['view'=>'valuation','page'=>3], 'admin/inventory-valuation.php?page=3'],
    [['view'=>'manufacturing'], 'admin/manufacturing-orders.php'],
    [['task'=>'adjust','move_item'=>5], 'admin/inventory-adjustments.php?move_item=5'],
    [['edit_id'=>9], 'admin/items.php?edit_id=9'],
    [['opening_template'=>'xlsx'], null],
    [['export'=>'xlsx'], null],
] as [$query,$expected]) audit_check(inventory_legacy_page($query) === $expected, 'Legacy route failed');
$source = file_get_contents(__DIR__ . '/../public_html/admin/accounting-inventory.php');
$heavy = strpos($source, '$itemStmt = db()->prepare(');
$history = strpos($source, '// History pages need only');
$selectors = strpos($source, '$selectorItems = db()->prepare(');
audit_check($history !== false && $selectors !== false && $history < $heavy && $selectors < $heavy, 'History and voucher selectors must exit before the stock aggregate');
foreach (['mo.fiscal_year_id = :fiscal_year_id', '$orderPages', '$orderCounts[\'open_count\']', "INVENTORY_PAGE === 'valuation'"] as $expected) audit_check(str_contains($source,$expected), 'Missing history/performance guard: ' . $expected);
$transfer = file_get_contents(__DIR__ . '/../app/views/vouchers/stock_transfer.php');
audit_check(str_contains($transfer,'stock_transfer_retry') && str_contains($transfer,'min="<?= e($fiscalYear'), 'Transfer retry/date bounds missing');
echo "Inventory audit: legacy routes, fast paths, fiscal-year scope, pagination and transfer retry checks passed.\n";
