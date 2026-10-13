<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/purchase_line_import.php';
require_once __DIR__ . '/../app/jewellery_line_io.php';
require_once __DIR__ . '/../app/voucher_types.php';
function check_purchase(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$items = [['id' => 9, 'sku' => '001', 'name' => 'Rice']];
$sheet = [['n' => 1, 'cells' => purchase_line_columns()], ['n' => 2, 'cells' => ['001', '2', '100', 'General', 'exclusive', '13']]];
$input = purchase_line_parse($sheet, $items, ['General']);
check_purchase($input['value_item'] === [9] && $input['value_amount'] === [200.0], 'Item/amount mapping');
$prefill = voucher_prefill_from_input('purchase', $input);
check_purchase(count($prefill['values']) === 1 && (int)$prefill['values'][0]['item_id'] === 9, 'Voucher preview must retain item');
foreach ([['FOREIGN',2,100,'General','exclusive',13], ['001',0,100,'General','exclusive',13], ['001',2,'bad','General','none',0], ['001',2,100,'Unknown','none',0], ['001',2,100,'General','wrong',13], ['001',2,100,'General','exclusive',0]] as $bad) {
    $candidate = $sheet;
    $candidate[] = ['n' => 3, 'cells' => $bad];
    try { purchase_line_parse($candidate, $items, ['General']); throw new LogicException('Bad row accepted'); }
    catch (RuntimeException $expected) { check_purchase(str_contains($expected->getMessage(), 'Row 3'), 'Row-specific error'); }
}
foreach (jw_line_template_columns() as $heading) {
    check_purchase(isset(jw_import_header_map()[jw_import_squash($heading)]), 'Jewellery template heading: ' . $heading);
}
$bytes = xlsx_build([purchase_line_columns()], 'Item lines');
check_purchase(str_starts_with($bytes, 'PK'), 'Workbook generated');
$temp = tempnam(sys_get_temp_dir(), 'purchase-test-');
try {
    file_put_contents($temp, xlsx_build(array_column($sheet, 'cells'), 'Item lines'));
    $parsed = purchase_line_parse(voucher_import_read_rows($temp, 'xlsx'), $items, ['General']);
    check_purchase($parsed === $input, 'XLSX reader round trip');
    $handle = fopen($temp, 'wb');
    foreach ($sheet as $row) fputcsv($handle, $row['cells'], ',', '"', '\\');
    fclose($handle);
    check_purchase(purchase_line_parse(voucher_import_read_rows($temp, 'csv'), $items, ['General']) === $input, 'CSV reader round trip');
} finally { unlink($temp); }
echo "Purchase item import, preview and jewellery template tests passed.\n";
