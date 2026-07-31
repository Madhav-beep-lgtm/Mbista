<?php
declare(strict_types=1);

require_once __DIR__ . '/voucher_types.php';

/**
 * Making the stock follow the voucher.
 *
 * A purchase voucher used to raise the payable and leave the shelf alone, so
 * somebody reconciled the ledger against the godown by hand at the year end.
 * When a sales or purchase voucher names its goods, the movement is recorded
 * here — quantities, cost layers, and, on the sales side, the cost of what
 * went out.
 *
 * The division of labour matters, and it is the whole reason this file is
 * careful rather than short:
 *
 *   Purchase and debit note   the voucher ALREADY carries both legs (stock
 *                             ledger against the supplier), so only the
 *                             quantity and its cost layer are recorded here.
 *                             Posting a second voucher would credit the
 *                             supplier twice.
 *
 *   Sale and credit note      the voucher carries only the revenue leg. What
 *                             the goods COST is a separate fact, and it posts
 *                             as its own journal — Dr Cost of goods sold, Cr
 *                             stock — through the same engine the invoice
 *                             module uses, so there is one posting matrix in
 *                             this system and not two.
 *
 * Movements are tied to the voucher that caused them by source_voucher_id, so
 * a voucher can find, replace and release its own stock without guessing.
 */

/** The inventory movement a voucher type causes, or null for the other four. */
function voucher_stock_movement_type(string $voucherType): ?string
{
    return match ($voucherType) {
        'purchase' => 'purchase',
        'sales' => 'sale',
        'debit_note' => 'purchase_return',
        'credit_note' => 'sales_return',
        default => null,
    };
}

/** Which way the goods travel. */
function voucher_stock_direction(string $movementType): string
{
    return in_array($movementType, ['purchase', 'sales_return'], true) ? 'in' : 'out';
}

/**
 * Whether the movement needs a voucher of its own for its value.
 *
 * Only the sales side. See the note at the top of this file: posting one for a
 * purchase would credit the supplier a second time.
 */
function voucher_stock_posts_cost_voucher(string $movementType): bool
{
    return in_array($movementType, ['sale', 'sales_return'], true);
}

/** True when this installation can move stock from a voucher at all. */
function voucher_stock_ready(): bool
{
    return table_exists('inventory_items')
        && table_exists('inventory_transactions')
        && column_exists('voucher_entries', 'item_id')
        && column_exists('inventory_transactions', 'source_voucher_id');
}

/** The item lines of a saved voucher, in the order they were entered. */
function voucher_stock_item_lines(int $voucherId): array
{
    if ($voucherId <= 0 || !voucher_stock_ready()) {
        return [];
    }
    $stmt = db()->prepare('SELECT id, item_id, quantity, amount, entry_type, memo
        FROM voucher_entries
        WHERE voucher_id = :vid AND item_id IS NOT NULL AND item_id > 0 AND quantity > 0
        ORDER BY id ASC');
    $stmt->execute(['vid' => $voucherId]);

    return $stmt->fetchAll();
}

/**
 * What one item's stock is worth per unit right now — used to bring a sales
 * return back in at cost rather than at the price it was sold for.
 */
function voucher_stock_unit_cost(int $companyId, array $item): float
{
    $balance = function_exists('inv_layer_balance') ? inv_layer_balance($companyId, (int) $item['id']) : ['qty' => 0, 'value' => 0];
    $qty = (float) ($balance['qty'] ?? 0);
    $value = (float) ($balance['value'] ?? 0);
    if ($qty > 0.0001 && $value > 0) {
        return round($value / $qty, 2);
    }
    $purchaseRate = (float) ($item['purchase_rate'] ?? 0);
    if ($purchaseRate > 0) {
        return $purchaseRate;
    }

    return function_exists('inv_item_opening_unit_cost') ? inv_item_opening_unit_cost($item) : 0.0;
}

/** An item of this company, with what it currently has on hand. */
function voucher_stock_item(int $companyId, int $itemId): ?array
{
    if ($itemId <= 0 || !table_exists('inventory_items')) {
        return null;
    }
    $stmt = db()->prepare('SELECT i.*, i.opening_qty + COALESCE((
                SELECT SUM(t.qty_in - t.qty_out) FROM inventory_transactions t WHERE t.item_id = i.id
            ), 0) AS on_hand
        FROM inventory_items i WHERE i.id = :id AND i.company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $itemId, 'cid' => $companyId]);

    return $stmt->fetch() ?: null;
}

/**
 * Everything wrong with the stock side of a voucher, said before it posts.
 *
 * Checked here rather than during posting because the alternative is a voucher
 * whose ledger entries are in the books and whose stock quietly is not. $lines
 * are the composed entries; $excludeVoucherId lets an edit ignore the stock its
 * own earlier version is still holding.
 */
function voucher_stock_preflight(int $companyId, string $voucherType, array $lines, int $excludeVoucherId = 0): array
{
    $movementType = voucher_stock_movement_type($voucherType);
    if ($movementType === null || !voucher_stock_ready()) {
        return [];
    }
    $direction = voucher_stock_direction($movementType);

    // Several lines can name the same item; the shelf sees their total.
    $wanted = [];
    foreach ($lines as $line) {
        $itemId = (int) ($line['item_id'] ?? 0);
        $quantity = (float) ($line['quantity'] ?? 0);
        if ($itemId > 0 && $quantity > 0) {
            $wanted[$itemId] = ($wanted[$itemId] ?? 0) + $quantity;
        }
    }
    if ($wanted === []) {
        return [];
    }

    $problems = [];
    foreach ($wanted as $itemId => $quantity) {
        $item = voucher_stock_item($companyId, $itemId);
        if (!$item) {
            $problems[] = 'Item #' . $itemId . ' does not belong to this company.';
            continue;
        }
        if ($direction !== 'out' || (int) ($item['allow_negative_stock'] ?? 0) === 1) {
            continue;
        }
        $onHand = (float) ($item['on_hand'] ?? 0);
        // An edit is replacing its own earlier movement, so what that movement
        // took out is available to it again.
        if ($excludeVoucherId > 0) {
            $heldStmt = db()->prepare('SELECT COALESCE(SUM(qty_out - qty_in), 0) FROM inventory_transactions
                WHERE company_id = :cid AND item_id = :iid AND source_voucher_id = :vid');
            $heldStmt->execute(['cid' => $companyId, 'iid' => $itemId, 'vid' => $excludeVoucherId]);
            $onHand += (float) $heldStmt->fetchColumn();
        }
        if ($quantity - $onHand > 0.0001) {
            $problems[] = sprintf(
                '%s has %s %s on hand but this voucher moves out %s. Record the purchase first, or allow negative stock on the item.',
                (string) $item['name'],
                rtrim(rtrim(number_format($onHand, 3, '.', ''), '0'), '.'),
                (string) ($item['unit'] ?? 'units'),
                rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.')
            );
        }
    }

    return $problems;
}

/**
 * Release the stock a voucher is holding: delete its movements, and with them
 * the cost journals those movements posted.
 *
 * Returns the item ids that were touched, so the caller can rebuild their cost
 * layers — the layers still remember consuming stock for movements that no
 * longer exist until they are replayed.
 */
function voucher_stock_clear(int $companyId, int $voucherId): array
{
    if ($voucherId <= 0 || !voucher_stock_ready()) {
        return [];
    }
    $stmt = db()->prepare('SELECT id, item_id, voucher_id FROM inventory_transactions
        WHERE company_id = :cid AND source_voucher_id = :vid');
    $stmt->execute(['cid' => $companyId, 'vid' => $voucherId]);
    $existing = $stmt->fetchAll();
    if ($existing === []) {
        return [];
    }

    $items = [];
    $costVoucherIds = [];
    $transactionIds = [];
    foreach ($existing as $row) {
        $items[(int) $row['item_id']] = true;
        $transactionIds[] = (int) $row['id'];
        if ((int) ($row['voucher_id'] ?? 0) > 0) {
            $costVoucherIds[(int) $row['voucher_id']] = true;
        }
    }

    // The cost journal and any NRV allowance release belong to the movement,
    // not to the books at large: they go when it goes. voucher_mutation_blocker
    // rightly refuses these by hand, which is why this is direct SQL — the
    // caller is the module that owns them.
    $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
    $ownedStmt = db()->prepare("SELECT id FROM vouchers
        WHERE company_id = ? AND source_type IN ('inventory_movement', 'inventory_nrv_release')
          AND source_id IN ($placeholders)");
    $ownedStmt->execute(array_merge([$companyId], $transactionIds));
    foreach ($ownedStmt->fetchAll(PDO::FETCH_COLUMN) as $ownedId) {
        $costVoucherIds[(int) $ownedId] = true;
    }

    db()->prepare('DELETE FROM inventory_transactions WHERE company_id = :cid AND source_voucher_id = :vid')
        ->execute(['cid' => $companyId, 'vid' => $voucherId]);

    if ($costVoucherIds !== []) {
        $ids = array_keys($costVoucherIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("DELETE FROM voucher_entries WHERE voucher_id IN ($placeholders)")->execute($ids);
        db()->prepare("DELETE FROM vouchers WHERE company_id = ? AND id IN ($placeholders)")
            ->execute(array_merge([$companyId], $ids));
    }

    return array_keys($items);
}

/**
 * Make the stock match the voucher as it now stands.
 *
 * Idempotent by construction: whatever the voucher was holding is released
 * first, the cost layers of every item involved are replayed from the
 * transactions that remain, and the voucher's current item lines are recorded
 * afresh. A voucher that is no longer posted — a draft, or one sent back for
 * approval — ends up holding nothing, which is correct: undelivered goods are
 * still on the shelf.
 *
 * Returns human-readable notes. A note is never a failure: the ledger entries
 * are already made, so a stock problem is reported, not thrown.
 */
function voucher_stock_sync(int $companyId, ?int $fiscalYearId, array $voucher, int $actorId = 0): array
{
    $voucherId = (int) ($voucher['id'] ?? 0);
    $movementType = voucher_stock_movement_type((string) ($voucher['voucher_type'] ?? ''));
    if ($voucherId <= 0 || $movementType === null || !voucher_stock_ready()) {
        return [];
    }

    $notes = [];
    $affectedItems = voucher_stock_clear($companyId, $voucherId);

    $isPosted = (string) ($voucher['status'] ?? '') === 'posted';
    $lines = $isPosted ? voucher_stock_item_lines($voucherId) : [];
    foreach ($lines as $line) {
        $affectedItems[] = (int) $line['item_id'];
    }
    // Replay every touched item BEFORE recording anything new, so the layers
    // reflect a world in which this voucher's old movements never happened.
    foreach (array_unique($affectedItems) as $itemId) {
        if (function_exists('inv_rebuild_item')) {
            inv_rebuild_item($companyId, (int) $itemId);
        }
    }
    if ($lines === []) {
        return $notes;
    }

    $direction = voucher_stock_direction($movementType);
    $movementDate = (string) ($voucher['voucher_date'] ?? date('Y-m-d'));
    $voucherNo = (string) ($voucher['voucher_no'] ?? '');
    $partyId = (int) ($voucher['party_id'] ?? 0);
    $warehouseOverride = (int) ($voucher['warehouse_id'] ?? 0);
    $hasWarehouseColumn = column_exists('inventory_transactions', 'warehouse_id');

    $insertStmt = db()->prepare('INSERT INTO inventory_transactions (
            company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date,
            qty_in, qty_out, rate, amount, notes, source_voucher_id' . ($hasWarehouseColumn ? ', warehouse_id' : '') . '
        ) VALUES (
            :company_id, :fiscal_year_id, :item_id, :transaction_type, :ref_no, :transaction_date,
            :qty_in, :qty_out, :rate, :amount, :notes, :source_voucher_id' . ($hasWarehouseColumn ? ', :warehouse_id' : '') . '
        )');

    foreach ($lines as $line) {
        $itemId = (int) $line['item_id'];
        $quantity = round((float) $line['quantity'], 3);
        $lineValue = round((float) $line['amount'], 2);
        $item = voucher_stock_item($companyId, $itemId);
        if (!$item || $quantity <= 0) {
            continue;
        }
        $onHandBefore = (float) ($item['on_hand'] ?? 0);

        // Goods coming in are worth what this voucher paid for them. Goods
        // going out are worth what they cost us, which the layers decide — and
        // a sales return comes back at cost, never at the price it was sold at.
        $unitCost = $movementType === 'sales_return'
            ? voucher_stock_unit_cost($companyId, $item)
            : ($quantity > 0 ? round($lineValue / $quantity, 2) : 0.0);

        try {
            $warehouseId = $warehouseOverride > 0 ? $warehouseOverride : ((int) ($item['default_warehouse_id'] ?? 0) ?: null);
            $params = [
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId ?: null,
                'item_id' => $itemId,
                'transaction_type' => $movementType,
                'ref_no' => $voucherNo,
                'transaction_date' => $movementDate,
                'qty_in' => $direction === 'in' ? $quantity : 0,
                'qty_out' => $direction === 'out' ? $quantity : 0,
                'rate' => $unitCost,
                'amount' => round($unitCost * $quantity, 2),
                'notes' => voucher_type_label((string) $voucher['voucher_type']) . ' ' . $voucherNo,
                'source_voucher_id' => $voucherId,
            ];
            if ($hasWarehouseColumn) {
                $params['warehouse_id'] = $warehouseId;
            }
            $insertStmt->execute($params);
            $transactionId = (int) db()->lastInsertId();

            $movedValue = inv_apply_movement(
                $companyId,
                $itemId,
                $direction === 'in' ? $quantity : 0.0,
                $direction === 'out' ? $quantity : 0.0,
                $unitCost,
                $movementDate,
                (string) ($item['valuation_method'] ?? 'weighted_average'),
                $transactionId,
                $warehouseId
            );

            // What the layers actually gave up can differ from what the voucher
            // credited the stock ledger — a return priced at the agreed figure
            // rather than at what the goods cost. Say so rather than let the
            // ledger and the godown drift apart in silence.
            if (!voucher_stock_posts_cost_voucher($movementType) && $direction === 'out' && abs($movedValue - $lineValue) > 1.0) {
                $notes[] = sprintf(
                    '%s left the shelf at a cost of %s but the voucher credits %s — check the return price.',
                    (string) $item['name'],
                    site_currency_symbol() . number_format($movedValue, 2),
                    site_currency_symbol() . number_format($lineValue, 2)
                );
            }

            $costVoucherId = 0;
            if (voucher_stock_posts_cost_voucher($movementType)) {
                $costValue = $direction === 'out' ? $movedValue : round($unitCost * $quantity, 2);
                try {
                    $costVoucherId = inv_post_movement_voucher(
                        $companyId,
                        $fiscalYearId,
                        $transactionId,
                        $movementType,
                        $item,
                        $direction,
                        $costValue,
                        $movementDate,
                        $actorId,
                        $partyId > 0 ? $partyId : null
                    );
                } catch (RuntimeException $mapException) {
                    if (!str_starts_with($mapException->getMessage(), 'MAP_MISSING:')) {
                        throw $mapException;
                    }
                    $notes[] = $item['sku'] . ' moved on the stock register, but its inventory ledgers are not mapped, so the cost of the sale is not in the books yet.';
                }
                if ($costVoucherId > 0) {
                    db()->prepare('UPDATE inventory_transactions SET voucher_id = :vid WHERE id = :id AND company_id = :cid')
                        ->execute(['vid' => $costVoucherId, 'id' => $transactionId, 'cid' => $companyId]);
                }
            }

            // Stock written down to net realisable value releases its share of
            // the allowance when it leaves, exactly as it does from an invoice.
            if ($direction === 'out' && function_exists('inv_post_allowance_release')) {
                [$released, ] = inv_post_allowance_release(
                    $companyId,
                    $fiscalYearId,
                    $transactionId,
                    $item,
                    $movementType,
                    $direction,
                    $quantity,
                    $onHandBefore,
                    $movementDate,
                    $actorId,
                    $costVoucherId,
                    $movedValue
                );
                if ($released > 0) {
                    $notes[] = $item['sku'] . ' NRV allowance released: ' . site_currency_symbol() . number_format($released, 2) . '.';
                }
            }
        } catch (Throwable $stockException) {
            $notes[] = ($item['sku'] ?? ('Item #' . $itemId)) . ' stock movement failed: ' . $stockException->getMessage();
        }
    }

    return $notes;
}

/**
 * The stock a voucher moved, for showing on the voucher itself.
 * Empty when it moved none.
 */
function voucher_stock_summary(int $companyId, int $voucherId): array
{
    if ($voucherId <= 0 || !voucher_stock_ready()) {
        return [];
    }
    $stmt = db()->prepare('SELECT t.qty_in, t.qty_out, t.rate, t.amount, t.transaction_type,
            i.name AS item_name, i.sku, i.unit
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id
        WHERE t.company_id = :cid AND t.source_voucher_id = :vid
        ORDER BY t.id ASC');
    $stmt->execute(['cid' => $companyId, 'vid' => $voucherId]);

    return $stmt->fetchAll();
}
