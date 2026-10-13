<?php
declare(strict_types=1);

/** Additional costs are allocated to output once; only new costs create GL entries. */
function production_cost_lines(array $data, int $companyId): array
{
    $lines = [];
    foreach ((array) ($data['cost_amount'] ?? []) as $index => $raw) {
        if (!is_numeric($raw) || !is_finite((float) $raw) || (float) $raw < 0) throw new RuntimeException('Enter a valid non-negative additional cost.');
        $amount = round((float) $raw, 2);
        if ($amount === 0.0) continue;
        $mode = (string) ($data['cost_mode'][$index] ?? '');
        $description = trim((string) ($data['cost_description'][$index] ?? ''));
        $expenseId = (int) ($data['cost_expense'][$index] ?? 0);
        $creditId = (int) ($data['cost_credit'][$index] ?? 0);
        $centre = trim((string) ($data['cost_centre'][$index] ?? ''));
        $reference = trim((string) ($data['cost_reference'][$index] ?? ''));
        if (!in_array($mode, ['new', 'allocated'], true) || $description === '') throw new RuntimeException('Choose a cost treatment and description.');
        if ($mode === 'allocated' && $reference === '') throw new RuntimeException('Enter the original voucher reference for an already-recorded cost.');
        if ($mode === 'new') {
            if ($expenseId <= 0 || $creditId <= 0 || $expenseId === $creditId || $centre === '') throw new RuntimeException('New costs require different expense and payment/payable ledgers, and a cost centre.');
            $stmt = db()->prepare("SELECT id, type FROM ledgers WHERE company_id = ? AND status = 'active' AND id IN (?, ?)");
            $stmt->execute([$companyId, $expenseId, $creditId]);
            $ledgerTypes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            if (($ledgerTypes[$expenseId] ?? '') !== 'expense' || !in_array($ledgerTypes[$creditId] ?? '', ['asset', 'liability'], true)) throw new RuntimeException('Use an active company expense ledger and an asset/payment or liability/payable ledger.');
        }
        $lines[] = ['description'=>$description, 'amount'=>$amount, 'mode'=>$mode, 'expense_id'=>$expenseId, 'credit_id'=>$creditId, 'cost_centre'=>$centre, 'reference'=>$reference];
    }
    return $lines;
}

function production_cost_entries(array $costs): array
{
    $entries = [];
    foreach ($costs as $cost) {
        if ($cost['mode'] !== 'new') continue;
        foreach (['debit' => 'expense_id', 'credit' => 'credit_id'] as $side => $key) {
            $entries[] = ['ledger_id'=>$cost[$key], 'entry_type'=>$side, 'amount'=>$cost['amount'], 'memo'=>$cost['description'], 'cost_centre'=>$cost['cost_centre']];
        }
    }
    return $entries;
}

function production_post_costs(int $companyId, int $fiscalYearId, int $orderId, string $number, string $date, int $userId, array $costs): int
{
    $entries = production_cost_entries($costs);
    if ($entries === []) return 0;
    $total = array_sum(array_column(array_filter($entries, static fn(array $e): bool => $e['entry_type'] === 'debit'), 'amount'));
    $id = create_voucher_with_entries([
        'company_id'=>$companyId, 'fiscal_year_id'=>$fiscalYearId, 'voucher_no'=>'COST-' . $number,
        'voucher_type'=>'journal', 'voucher_date'=>$date, 'source_type'=>'manufacturing_order', 'source_id'=>$orderId,
        'total_amount'=>$total, 'narration'=>'Additional production costs: ' . $number, 'status'=>'posted', 'posted_by'=>$userId,
    ], $entries);
    if ($id <= 0) throw new RuntimeException('Production cost journal could not be posted.');
    return $id;
}
