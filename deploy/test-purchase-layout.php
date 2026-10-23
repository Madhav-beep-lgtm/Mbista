<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$form = file_get_contents($root . '/public_html/admin/voucher-form.php');
$trade = file_get_contents($root . '/app/views/vouchers/layout_trade.php');
$picker = file_get_contents($root . '/app/views/vouchers/type_strip.php');
$css = file_get_contents($root . '/public_html/assets/css/components.css');
$checks = [
    'No separate upload card' => !str_contains($form, 'One supplier bill per upload.'),
    'Only one import input' => substr_count($form . $trade, 'name="purchase_lines"') === 1,
    'Optional import beside purchase details' => str_contains($trade, 'class="purchase-item-import" data-goods-field'),
    'Import endpoint preserved' => str_contains($trade, 'value="import_purchase_lines" formnovalidate'),
    'Instructions retained in disclosure' => str_contains($trade, '<summary>Instructions</summary>'),
    'Hospitality purchase type is not category' => str_contains($picker, "=== 'hospitality' ? 'Purchase'"),
    'Responsive purchase fields' => str_contains($css, '.voucher-type-purchase .frm-grid.vch-trade-header-grid { grid-template-columns:1fr; }'),
];
foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
}
echo "Purchase layout: optional import, labels and responsive controls passed.\n";
