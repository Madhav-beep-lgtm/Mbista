<?php
declare(strict_types=1);
require_once __DIR__ . '/voucher_import.php';
require_once __DIR__ . '/export_engine.php';

/** Import templates are data-entry sheets: headings stay on row one. */
function purchase_line_columns(): array
{
    return ['Item', 'Quantity', 'Rate', 'Cost Centre', 'Tax Mode', 'Tax Rate'];
}

function purchase_line_template_download(array $columns, string $name): void
{
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
    echo xlsx_build([$columns], 'Item lines', array_fill(0, count($columns), 22), ['styled_table' => true, 'freeze_header' => true]);
    exit;
}

/** Pure, all-or-nothing parsing against the active company's item directory. */
function purchase_line_parse(array $rows, array $items, array $costCentres): array
{
    $header = array_shift($rows);
    $columns = array_map(static fn($v) => strtolower(trim((string) $v)), $header['cells'] ?? []);
    if ($columns !== array_map('strtolower', purchase_line_columns())) {
        throw new RuntimeException('Use the current purchase item template, with its headings unchanged. Ledger-only purchases are not supported.');
    }
    $lookup = [];
    foreach ($items as $item) {
        foreach ([$item['sku'], $item['name']] as $key) {
            $key = mb_strtolower(trim((string) $key));
            if ($key === '') continue;
            $lookup[$key][(int) $item['id']] = $item;
        }
    }
    $input = ['trade_mode' => 'goods'];
    foreach ($rows as $row) {
        $cells = array_map(static fn($v) => trim((string) $v), $row['cells']);
        if (trim(implode('', $cells)) === '') continue;
        [$itemKey, $qty, $rate, $centre, $mode, $tax] = array_pad($cells, 6, '');
        $matches = $lookup[mb_strtolower($itemKey)] ?? [];
        $mode = strtolower($mode);
        if (count($matches) !== 1 || !is_numeric($qty) || (float) $qty <= 0
            || !is_finite((float) $qty) || !is_numeric($rate) || !is_finite((float) $rate) || (float) $rate <= 0 || !in_array($centre, $costCentres, true)
            || !in_array($mode, ['exclusive', 'inclusive', 'none'], true)
            || !is_numeric($tax) || !is_finite((float) $tax) || (float) $tax < 0 || (float) $tax > 100
            || ($mode !== 'none' && (float) $tax <= 0)) {
            throw new RuntimeException('Row ' . $row['n'] . ': use a unique active item SKU/name, positive quantity and rate, valid cost centre, tax mode (exclusive/inclusive/none) and tax rate (0–100; positive for taxed lines).');
        }
        $item = reset($matches);
        foreach (['item' => $item['id'], 'description' => $item['name'], 'qty' => $qty,
            'rate' => $rate, 'cost_centre' => $centre, 'tax_mode' => $mode, 'tax_rate' => $tax,
            'amount' => round((float) $qty * (float) $rate, 2), 'ledger' => ''] as $field => $value) {
            $input['value_' . $field][] = $value;
        }
    }
    if (empty($input['value_item'])) throw new RuntimeException('Add at least one item row beneath the template headings.');
    return $input;
}
