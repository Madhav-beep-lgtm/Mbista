<?php
declare(strict_types=1);

/** Bill details are account-scoped within this voucher, including repeated lines. */
function voucher_validate_bill_details(array $entries, array $directory, mixed $input): array
{
    $totals = [];
    foreach ($entries as $entry) {
        $id = (int) $entry['ledger_id'];
        if (!empty($directory[$id]['maintain_bill_by_bill'])) {
            $totals[$id] = ($totals[$id] ?? 0) + (float) $entry['amount'];
        }
    }
    $details = [];
    $errors = [];
    foreach ($totals as $id => $total) {
        $bill = is_array($input) && is_array($input[$id] ?? null) ? $input[$id] : [];
        $amount = is_scalar($bill['amount'] ?? null) && is_numeric($bill['amount']) ? (float) $bill['amount'] : 0;
        if (!is_finite($amount)) {
            $amount = 0;
        }
        $due = is_string($bill['due_date'] ?? null) ? $bill['due_date'] : '';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due) ? DateTimeImmutable::createFromFormat('!Y-m-d', $due) : false;
        $name = (string) $directory[$id]['name'];
        if (!is_finite($amount) || $amount <= 0 || abs(round($amount, 2) - round($total, 2)) > 0.005) {
            $errors[] = 'Bill amount for ' . $name . ' must equal its voucher lines (' . number_format($total, 2) . ').';
        }
        if (!$date || $date->format('Y-m-d') !== $due) {
            $errors[] = 'Enter a valid bill due date for ' . $name . '.';
        }
        $details[$id] = ['amount' => round($amount, 2), 'due_date' => $date && $date->format('Y-m-d') === $due ? $due : ''];
    }
    return ['details' => $details, 'errors' => $errors];
}
