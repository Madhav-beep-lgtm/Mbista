<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/voucher_types.php';
$ledgers = [
    1 => ['id' => 1, 'name' => 'Party', 'roles' => ['receivable' => true]],
    2 => ['id' => 2, 'name' => 'Sales', 'roles' => ['income' => true]],
    3 => ['id' => 3, 'name' => 'VAT', 'roles' => ['tax' => true]],
];
$input = [
    'settlement_mode' => 'party', 'settlement_ledger_id' => 1, 'party_id' => 1,
    'value_ledger' => [2, 2, 2], 'value_amount' => [100, 113, 50],
    'value_description' => ['Exclusive', 'Inclusive', 'Exempt'],
    'value_qty' => [1, 1, 1], 'value_rate' => [100, 113, 50],
    'value_tax_mode' => ['exclusive', 'inclusive', 'none'],
    'value_tax_rate' => [13, 13, 0], 'tax_ledger_id' => 3,
    'return_reason' => 'Returned goods',
];
foreach (['sales', 'purchase', 'debit_note', 'credit_note'] as $type) {
    $result = voucher_compose($type, $input, $ledgers);
    if ($result['errors']) throw new RuntimeException(implode('; ', $result['errors']));
    if (abs($result['total'] - 276) > .001) throw new RuntimeException('Incorrect mixed tax total');
    $debit = $credit = 0;
    foreach ($result['entries'] as $entry) {
        if ($entry['entry_type'] === 'debit') $debit += $entry['amount'];
        else $credit += $entry['amount'];
    }
    if (abs($debit - $credit) > .001) throw new RuntimeException('Unbalanced trade');
    $edit = voucher_decompose($type, [], $result['entries'], $ledgers);
    if (!$edit['ok'] || array_column($edit['values'], 'tax_mode') !== ['exclusive', 'inclusive', 'none']) throw new RuntimeException('Tax mode lost on edit');
    if ((float) $edit['values'][1]['amount'] !== 113.0) throw new RuntimeException('Inclusive amount lost');
    $retry = voucher_prefill_from_input($type, $input);
    if ($retry['values'][2]['tax_mode'] !== 'none') throw new RuntimeException('Retry lost exemption');
}
$bad = $input; $bad['tax_ledger_id'] = 1;
if (!voucher_compose('sales', $bad, $ledgers)['errors']) throw new RuntimeException('Non-tax ledger accepted');
$bad = $input; $bad['value_tax_rate'][0] = -13;
if (!voucher_compose('sales', $bad, $ledgers)['errors']) throw new RuntimeException('Negative tax accepted');
if (count(voucher_entry_type_catalog()) !== 9 || !isset(voucher_entry_type_catalog()['production'])) throw new RuntimeException('Missing voucher types');
$services = $input + ['trade_mode' => 'services'];
foreach (['sales', 'credit_note'] as $type) {
    if (voucher_compose($type, $services, $ledgers)['errors']) throw new RuntimeException('Service sales rejected');
}
foreach (['purchase', 'debit_note'] as $type) {
    if (!voucher_compose($type, $services, $ledgers)['errors']) throw new RuntimeException('Service purchase accepted');
}
$goods = $input + ['trade_mode' => 'goods'];
if (!voucher_compose('sales', $goods, $ledgers)['errors']) throw new RuntimeException('Goods without inventory accepted');
$goods['value_item'] = [10, 11, 12];
foreach (['sales', 'purchase', 'debit_note', 'credit_note'] as $type) {
    if (voucher_compose($type, $goods, $ledgers)['errors']) throw new RuntimeException('Goods lines rejected');
}
$services['value_item'] = [10, 0, 0];
if (!voucher_compose('sales', $services, $ledgers)['errors']) throw new RuntimeException('Inventory allowed in services');
echo "Four trade types: mixed taxes, balance, edit/retry, invalid tax and type selector passed.\n";
