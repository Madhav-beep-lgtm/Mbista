<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/voucher_types.php';
$ledgers = [
    1 => ['id' => 1, 'name' => 'Bank', 'roles' => ['cash_bank' => true]],
    2 => ['id' => 2, 'name' => 'Cash', 'roles' => ['cash_bank' => true]],
    3 => ['id' => 3, 'name' => 'Expense', 'roles' => ['cash_bank' => false]],
];
foreach (['journal', 'contra', 'payment', 'receipt'] as $type) {
    $input = in_array($type, ['journal', 'contra'], true)
        ? ['ledger_id' => [1, 2], 'entry_type' => ['debit', 'credit'], 'amount' => [100, 100], 'line_cost_centre' => ['General', 'Administration']]
        : ['tender_ledger' => [1], 'tender_amount' => [100], 'tender_cost_centre' => ['General'], 'line_ledger' => [3], 'line_amount' => [100], 'line_cost_centre' => ['Administration']];
    $result = voucher_compose($type, $input, $ledgers);
    if ($result['errors'] !== []) { throw new RuntimeException(implode(' ', $result['errors'])); }
    $centres = array_column($result['entries'], 'cost_centre');
    sort($centres);
    if ($centres !== ['Administration', 'General']) { throw new RuntimeException($type . ': cost centres lost on save'); }
    foreach ([voucher_prefill_from_input($type, $input), voucher_decompose($type, [], $result['entries'], $ledgers)] as $prefill) {
        $json = json_encode($prefill);
        if (!str_contains($json, 'General') || !str_contains($json, 'Administration')) { throw new RuntimeException($type . ': cost centres lost on edit/retry'); }
    }
}
echo "Journal, contra, payment and receipt cost-centre save/edit/retry tests passed.\n";
