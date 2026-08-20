<?php
declare(strict_types=1);

/**
 * Recording several purchases at once.
 *
 * A supplier's bill is rarely one line. Entering it one item at a time meant
 * re-choosing the supplier, the date and the VAT treatment for every row of the
 * same invoice, and there was nothing tying those rows together afterwards.
 *
 * This takes the whole grid and posts it in ONE transaction: every row becomes
 * the same stock movement and the same GL voucher the single-row form has
 * always made — the engine calls below are the identical ones — but either all
 * of them land or none of them do. A bill half-entered is worse than a bill not
 * entered, because the half that got in looks like a complete record.
 *
 * Rows are validated as a set BEFORE anything is written, so a mistake on the
 * last line is reported while the first is still on the screen rather than
 * after eleven of them have already posted.
 */

require_once __DIR__ . '/inventory_valuation.php';

/** The movement types this grid records — stock coming IN, and what it cost. */
function inv_purchase_batch_types(): array
{
    return [
        'purchase' => 'Purchase',
        'opening' => 'Opening stock',
        'purchase_return' => 'Purchase return',
    ];
}

/**
 * How VAT is treated on a purchase line.
 *
 * "Custom" is the escape hatch for the rates that turn up on a real bill
 * without being anybody's standard — a partial exemption, a rounded figure the
 * supplier actually charged.
 */
function inv_purchase_vat_modes(): array
{
    return [
        'standard' => ['label' => '13% (standard)', 'rate' => 13.0],
        'zero' => ['label' => '0% (zero-rated)', 'rate' => 0.0],
        'exempt' => ['label' => 'Exempted', 'rate' => 0.0],
        'custom' => ['label' => 'Custom rate…', 'rate' => null],
    ];
}

/**
 * Check every row of the grid, resolving items and suppliers in two queries
 * rather than two per row.
 *
 * Returns ['rows' => [...], 'errors' => [...], 'valid' => int]. A row carries
 * its own 'errors' list; the top-level one holds what stops the whole batch.
 */
function inv_purchase_batch_validate(int $companyId, int $fiscalYearId, array $rawRows): array
{
    $errors = [];
    $rows = [];

    // Everything the grid refers to, read once.
    $itemIds = [];
    $partyIds = [];
    foreach ($rawRows as $raw) {
        $itemId = (int) ($raw['item_id'] ?? 0);
        if ($itemId > 0) {
            $itemIds[$itemId] = true;
        }
        $partyId = (int) ($raw['supplier_party_id'] ?? 0);
        if ($partyId > 0) {
            $partyIds[$partyId] = true;
        }
    }
    $items = [];
    if ($itemIds !== []) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = db()->prepare("SELECT * FROM inventory_items WHERE company_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$companyId], array_keys($itemIds)));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $items[(int) $item['id']] = $item;
        }
    }
    $parties = [];
    if ($partyIds !== []) {
        $placeholders = implode(',', array_fill(0, count($partyIds), '?'));
        $stmt = db()->prepare("SELECT id, name FROM accounting_parties WHERE company_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$companyId], array_keys($partyIds)));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $party) {
            $parties[(int) $party['id']] = $party;
        }
    }

    $types = inv_purchase_batch_types();
    $vatModes = inv_purchase_vat_modes();
    $valid = 0;
    // A bill is nearly always one date across every line, so the fiscal year
    // and its posting lock are resolved per DATE rather than per row. Asking
    // per row cost a query a line — a sixty-line bill spent sixty round trips
    // establishing the same answer sixty times.
    $periodByDate = [];

    foreach (array_values($rawRows) as $index => $raw) {
        $rowErrors = [];
        $lineNo = $index + 1;

        $itemId = (int) ($raw['item_id'] ?? 0);
        $qty = round(abs((float) ($raw['quantity'] ?? 0)), 3);
        $rate = round((float) ($raw['rate'] ?? 0), 2);

        // A row nobody filled in is not an error — the grid ships with spare
        // lines and most bills do not use all of them.
        if ($itemId <= 0 && $qty <= 0 && $rate <= 0) {
            continue;
        }

        $item = $items[$itemId] ?? null;
        if ($item === null) {
            $rowErrors[] = 'Choose an item.';
        }
        $type = (string) ($raw['movement'] ?? 'purchase');
        if (!isset($types[$type])) {
            $rowErrors[] = 'Choose a movement type.';
            $type = 'purchase';
        }
        if ($qty <= 0) {
            $rowErrors[] = 'Quantity must be greater than zero.';
        }
        if ($rate < 0) {
            $rowErrors[] = 'Rate cannot be negative.';
        }

        $date = inventory_valid_date((string) ($raw['transaction_date'] ?? '')) ?? '';
        if ($date === '') {
            $rowErrors[] = 'Give a posting date (YYYY-MM-DD).';
        } elseif (table_exists('fiscal_years')) {
            if (!array_key_exists($date, $periodByDate)) {
                $rowFiscalYear = fiscal_year_for_date($companyId, $date);
                $periodByDate[$date] = $rowFiscalYear
                    ? fiscal_year_posting_blocker($rowFiscalYear, $date)
                    : 'No fiscal year covers ' . $date . '.';
            }
            if ($periodByDate[$date] !== null) {
                $rowErrors[] = (string) $periodByDate[$date];
            }
        }
        // The supplier's own invoice date is a record of THEIR paperwork; it is
        // not what the entry posts on, so it is allowed to sit outside the
        // period without stopping anything.
        $supplierDate = inventory_valid_date((string) ($raw['supplier_invoice_date'] ?? '')) ?? null;

        $amount = round($qty * $rate, 2);

        // The grid asks the question as a tick, because that is how a bill
        // reads: nearly every line carries VAT, and the exempt ones are the
        // exception worth un-ticking. A rate typed beside the tick overrides
        // the standard one, which is what a partial exemption needs.
        if (array_key_exists('vat_applicable', $raw)) {
            $typedRate = trim((string) ($raw['vat_rate'] ?? ''));
            if (empty($raw['vat_applicable'])) {
                $vatMode = 'exempt';
            } elseif ($typedRate !== '') {
                $vatMode = 'custom';
            } else {
                $vatMode = 'standard';
            }
        } else {
            $vatMode = (string) ($raw['vat_mode'] ?? 'standard');
        }
        if (!isset($vatModes[$vatMode])) {
            $vatMode = 'standard';
        }
        if ($vatMode === 'custom') {
            $vatRate = max(0.0, min(100.0, (float) ($raw['vat_rate'] ?? 0)));
        } else {
            $vatRate = (float) $vatModes[$vatMode]['rate'];
        }
        // A figure typed straight into the VAT column wins over the rate: it is
        // what the supplier actually charged, rounding and all.
        $vatTyped = trim((string) ($raw['vat_amount'] ?? ''));
        $vat = $vatTyped !== ''
            ? max(0.0, round((float) $vatTyped, 2))
            : round($amount * $vatRate / 100, 2);
        if ($vatMode === 'exempt' && $vat > 0) {
            $rowErrors[] = 'An exempted line cannot carry VAT.';
        }

        $partyId = (int) ($raw['supplier_party_id'] ?? 0);
        if ($partyId > 0 && !isset($parties[$partyId])) {
            $rowErrors[] = 'That supplier is not in this company.';
            $partyId = 0;
        }
        if ($partyId > 0 && !in_array($type, ['purchase', 'purchase_return'], true)) {
            // Opening stock is not owed to anybody; it is the position the
            // company started from.
            $partyId = 0;
        }

        $tdsBase = max(0.0, round((float) ($raw['tds_base'] ?? 0), 2));
        $tdsRate = max(0.0, min(100.0, (float) ($raw['tds_rate'] ?? 0)));
        // TDS is a tick too, but the other way round: most lines have none, so
        // it is off unless somebody says otherwise. Un-ticked means no
        // withholding whatever rate is sitting in the box.
        if (array_key_exists('tds_applicable', $raw) && empty($raw['tds_applicable'])) {
            $tdsRate = 0.0;
            $tdsBase = 0.0;
        }
        if ($tdsRate > 0 && $tdsBase <= 0) {
            // The whole line is the usual base, so an omitted one is filled in
            // rather than refused.
            $tdsBase = $amount;
        }

        if ($rowErrors !== []) {
            $rows[] = ['line' => $lineNo, 'errors' => $rowErrors] + $raw;
            continue;
        }

        $valid++;
        $rows[] = [
            'line' => $lineNo,
            'errors' => [],
            'item_id' => $itemId,
            'item' => $item,
            'movement' => $type,
            'transaction_date' => $date,
            'supplier_invoice_date' => $supplierDate,
            'warehouse_id' => inventory_company_warehouse_id((int) ($raw['warehouse_id'] ?? 0), $companyId),
            'ref_no' => mb_substr(trim((string) ($raw['ref_no'] ?? '')), 0, 80) ?: null,
            'quantity' => $qty,
            'rate' => $rate,
            'amount' => $amount,
            'vat_mode' => $vatMode,
            'vat_rate' => $vatRate,
            'vat_amount' => $vat,
            'supplier_party_id' => $partyId,
            'vat_ledger_id' => (int) ($raw['vat_ledger_id'] ?? 0),
            'tds_base' => $tdsBase,
            'tds_rate' => $tdsRate,
            'tds_ledger_id' => (int) ($raw['tds_ledger_id'] ?? 0),
            'notes' => mb_substr(trim((string) ($raw['notes'] ?? '')), 0, 255) ?: null,
            'mark_ingredient' => !empty($raw['mark_ingredient']),
        ];
    }

    if ($valid === 0 && $errors === []) {
        $errors[] = 'Nothing to record — fill in at least one line.';
    }

    return ['rows' => $rows, 'errors' => $errors, 'valid' => $valid];
}

/**
 * Post a validated grid.
 *
 * Every row goes through the same inv_apply_movement() and
 * inv_post_movement_voucher() the single-row form uses, so the stock layers and
 * the accounting are identical to entering them one at a time — the only
 * difference is that they succeed or fail together.
 *
 * Returns ['ok' => bool, 'error' => ?string, 'posted' => int, 'lines' => [...]].
 */
function inv_purchase_batch_post(int $companyId, int $fiscalYearId, array $validated, int $userId): array
{
    if ($validated['errors'] !== []) {
        return ['ok' => false, 'error' => implode(' ', $validated['errors']), 'posted' => 0, 'lines' => []];
    }
    $bad = array_filter($validated['rows'], static fn (array $r): bool => $r['errors'] !== []);
    if ($bad !== []) {
        $first = reset($bad);

        return [
            'ok' => false,
            'posted' => 0,
            'lines' => [],
            'error' => count($bad) . ' line(s) still have errors — line ' . $first['line'] . ': ' . implode(' ', $first['errors']),
        ];
    }
    $rows = array_values(array_filter($validated['rows'], static fn (array $r): bool => $r['errors'] === []));
    if ($rows === []) {
        return ['ok' => false, 'error' => 'Nothing to record.', 'posted' => 0, 'lines' => []];
    }

    $lines = [];
    $ingredientItems = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $insertTxn = $pdo->prepare('
            INSERT INTO inventory_transactions (
                company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date,
                warehouse_id, qty_in, qty_out, rate, amount, notes
            ) VALUES (
                :company_id, :fiscal_year_id, :item_id, :transaction_type, :ref_no, :transaction_date,
                :warehouse_id, :qty_in, :qty_out, :rate, :amount, :notes
            )
        ');
        $linkVoucher = $pdo->prepare('UPDATE inventory_transactions SET voucher_id = :vid WHERE id = :id AND company_id = :cid');

        foreach ($rows as $row) {
            $item = $row['item'];
            $type = (string) $row['movement'];
            $direction = inventory_direction($type);
            $qtyIn = $direction === 'in' ? $row['quantity'] : 0.0;
            $qtyOut = $direction === 'in' ? 0.0 : $row['quantity'];
            $method = (string) ($item['valuation_method'] ?? 'weighted_average');

            $insertTxn->execute([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                'item_id' => (int) $row['item_id'],
                'transaction_type' => $type,
                'ref_no' => $row['ref_no'],
                'transaction_date' => $row['transaction_date'],
                'warehouse_id' => $row['warehouse_id'],
                'qty_in' => $qtyIn,
                'qty_out' => $qtyOut,
                'rate' => $row['rate'],
                'amount' => $row['amount'],
                'notes' => $row['notes'],
            ]);
            $txnId = (int) $pdo->lastInsertId();

            $issueValue = inv_apply_movement($companyId, (int) $row['item_id'], $qtyIn, $qtyOut, $row['rate'],
                $row['transaction_date'], $method, $txnId, $row['warehouse_id']);
            $postingValue = $direction === 'in' ? $row['amount'] : $issueValue;

            $extra = [];
            if (in_array($type, ['purchase', 'opening'], true) && $direction === 'in') {
                $extra = [
                    'draft' => true,
                    'vat' => $row['vat_amount'],
                    'tds' => tds_from_rate($row['tds_base'] > 0 ? $row['tds_base'] : $postingValue, $row['tds_rate']),
                    'vat_ledger_id' => $row['vat_ledger_id'],
                    'tds_ledger_id' => $row['tds_ledger_id'],
                    // The supplier's own invoice date is what the bill is dated;
                    // the posting date is when it enters the books.
                    'posting_date' => $row['transaction_date'],
                    'reference_no' => (string) ($row['ref_no'] ?? ''),
                ];
            }

            $voucherId = 0;
            $mapMissing = [];
            try {
                $voucherId = inv_post_movement_voucher($companyId, $fiscalYearId, $txnId, $type, $item, $direction,
                    $postingValue, $row['transaction_date'], $userId, $row['supplier_party_id'] ?: null, $extra);
            } catch (RuntimeException $mapEx) {
                if (str_starts_with($mapEx->getMessage(), 'MAP_MISSING:')) {
                    $mapMissing = explode(',', substr($mapEx->getMessage(), 12));
                } else {
                    throw $mapEx;
                }
            }
            if ($voucherId > 0) {
                $linkVoucher->execute(['vid' => $voucherId, 'id' => $txnId, 'cid' => $companyId]);
            }

            if ($row['mark_ingredient'] && column_exists('inventory_items', 'is_ingredient')) {
                $ingredientItems[] = (int) $row['item_id'];
            }

            $lines[] = [
                'line' => $row['line'],
                'txn_id' => $txnId,
                'voucher_id' => $voucherId,
                'item_name' => (string) $item['name'],
                'amount' => $row['amount'],
                'vat' => $row['vat_amount'],
                'map_missing' => $mapMissing,
            ];
        }

        // One statement for the whole grid, not one per ticked row.
        if ($ingredientItems !== []) {
            $placeholders = implode(',', array_fill(0, count($ingredientItems), '?'));
            $pdo->prepare("UPDATE inventory_items SET is_ingredient = 1 WHERE company_id = ? AND id IN ($placeholders)")
                ->execute(array_merge([$companyId], $ingredientItems));
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => 'Nothing was recorded: ' . $exception->getMessage(), 'posted' => 0, 'lines' => []];
    }

    // The kitchen list is refreshed after the commit — failing to do so must
    // not undo a bill that is already correctly in the books.
    $ingredientsAdded = 0;
    if ($ingredientItems !== [] && function_exists('hospitality_sync_ingredients_from_inventory')) {
        try {
            $sync = hospitality_sync_ingredients_from_inventory($companyId, $userId);
            $ingredientsAdded = (int) $sync['created'];
        } catch (Throwable $ignored) {
            $ingredientsAdded = 0;
        }
    }

    log_activity('inventory_item', $companyId, 'purchase_batch',
        count($lines) . ' purchase line(s) recorded in one entry.', $userId);
    if (function_exists('security_event')) {
        security_event('inventory_movement_posted', 'success',
            count($lines) . ' inventory movement(s) posted from a multi-line purchase entry.', $companyId, $userId);
    }

    return [
        'ok' => true,
        'error' => null,
        'posted' => count($lines),
        'lines' => $lines,
        'ingredients_added' => $ingredientsAdded,
    ];
}
