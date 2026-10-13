<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/voucher_bills.php';

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
$directory = [1 => ['name' => 'Customer', 'maintain_bill_by_bill' => 1], 2 => ['name' => 'Sales', 'maintain_bill_by_bill' => 0]];
$entries = [['ledger_id' => 1, 'amount' => 60], ['ledger_id' => 1, 'amount' => 40], ['ledger_id' => 2, 'amount' => 100]];
$valid = [1 => ['amount' => '100.00', 'due_date' => '2026-11-30']];
$result = voucher_validate_bill_details($entries, $directory, $valid);
check($result['errors'] === [], 'Valid bill rejected');
check(count($result['details']) === 1 && $result['details'][1]['amount'] === 100.0, 'Repeated account lines not aggregated');
check(voucher_validate_bill_details($entries, $directory, [])['errors'] !== [], 'Missing bill accepted');
foreach (['2026-02-30', 'invalid', "2026-11-30\0"] as $date) {
    check(voucher_validate_bill_details($entries, $directory, [1 => ['amount' => 100, 'due_date' => $date]])['errors'] !== [], 'Invalid date accepted');
}
foreach (['99.99', '-100', 'INF', '1e999', [], '0'] as $amount) {
    $result = voucher_validate_bill_details($entries, $directory, [1 => ['amount' => $amount, 'due_date' => '2026-11-30']]);
    check($result['errors'] !== [], 'Invalid amount accepted');
    json_encode($result['details'], JSON_THROW_ON_ERROR);
}
check(voucher_validate_bill_details($entries, $directory, 'tampered')['errors'] !== [], 'Malformed input accepted');
$valid[999] = ['amount' => 500, 'due_date' => '2026-11-30'];
check(!isset(voucher_validate_bill_details($entries, $directory, $valid)['details'][999]), 'Unrelated account retained');
check(voucher_validate_bill_details([['ledger_id' => 2, 'amount' => 100]], $directory, [])['errors'] === [], 'Non-bill account requires details');
echo "Voucher bill validation checks passed.\n";
