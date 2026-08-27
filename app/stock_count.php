<?php
declare(strict_types=1);

/**
 * Counted closing stock — the figure a person punches in, and what it costs.
 *
 * The Stock Summary Report derives closing stock by replaying every recorded
 * movement, which is exactly right for a shop that records both sides. A
 * kitchen does not: it records the milk it BUYS and rings up the coffee it
 * sells, and nothing anywhere says the coffee drank the milk. The replay
 * therefore reports every litre still on the shelf, and the cost of the litres
 * that were drunk never reaches the books at all.
 *
 * So somebody counts the shelf. The counted quantity is punched in against a
 * date, and the difference from the replay IS the consumption:
 *
 *     variance = counted − system
 *     variance < 0  shortfall  →  outward movement at inventory cost
 *     variance > 0  surplus    →  inward movement at carrying cost
 *     variance = 0             →  counted and agreed; nothing to post
 *
 * The override is not a display trick. Posting the difference as a real
 * movement is what makes the replay agree with the shelf from that date on,
 * and what charges the cost to COGS — one figure, in the subledger and in the
 * books, instead of a number on a report the ledger has never heard of.
 *
 * WHERE THE SHORTFALL IS CHARGED is the count's own choice, because the same
 * missing litre means two different things:
 *   cogs           it was sold  (kitchen, cafe, counter — the default)
 *   inventory_loss it was lost  (breakage, spoilage, theft)
 * 'cogs' posts the purpose-built `stock_count` movement type; 'inventory_loss'
 * posts a plain `adjustment`, which is what that type has always meant.
 *
 * SCOPE. A count is taken where the stock is. Company-wide is warehouse_id 0;
 * one selected location is that warehouse id. "Several locations at once" is
 * not a shelf anybody can walk, so the report withholds the column rather than
 * guessing which one was meant.
 */

require_once __DIR__ . '/inventory_valuation.php';
require_once __DIR__ . '/stock_report_engine.php';

/** warehouse_id for a count taken over the whole company (never NULL — see migration 126). */
const SC_COMPANY_WIDE = 0;

function sc_table_ready(): bool
{
    return table_exists('inventory_stock_counts');
}

/**
 * The single location a set of report filters is looking at: 0 for the whole
 * company, the warehouse id for exactly one, and null when several are
 * selected — an ambiguous shelf, which no count may be filed against.
 */
function sc_scope_warehouse(array $warehouseIds): ?int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $warehouseIds))));
    if ($ids === []) {
        return SC_COMPANY_WIDE;
    }

    return count($ids) === 1 ? $ids[0] : null;
}

/** "All locations", or the warehouse's own name. */
function sc_scope_label(int $companyId, int $warehouseId): string
{
    if ($warehouseId === SC_COMPANY_WIDE) {
        return 'All locations';
    }
    $stmt = db()->prepare('SELECT name FROM warehouses WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $warehouseId, 'cid' => $companyId]);

    return (string) ($stmt->fetchColumn() ?: ('Location #' . $warehouseId));
}

/** Every count for one date and scope, keyed by item id. */
function sc_counts(int $companyId, string $countDate, int $warehouseId): array
{
    if (!sc_table_ready()) {
        return [];
    }
    $stmt = db()->prepare('SELECT * FROM inventory_stock_counts
        WHERE company_id = :cid AND count_date = :d AND warehouse_id = :wh');
    $stmt->execute(['cid' => $companyId, 'd' => $countDate, 'wh' => $warehouseId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['item_id']] = $row;
    }

    return $out;
}

/** True once the count has been posted — `posted_at` is the only place that fact lives. */
function sc_is_posted(array $count): bool
{
    return ($count['posted_at'] ?? null) !== null;
}

/**
 * Punch in (or clear) counted quantities for one date and scope.
 *
 * $counted is [item_id => raw string]. An empty box clears an unposted count —
 * punching nothing is how a row is taken back out of the sheet, and is not the
 * same as counting zero. A posted count is never silently overwritten; it must
 * be unposted first, and says so.
 *
 * @return array{saved:int, cleared:int, unchanged:int, skipped:array<int,string>}
 */
function sc_save_many(int $companyId, string $countDate, int $warehouseId, array $counted, array $notes, int $userId): array
{
    $result = ['saved' => 0, 'cleared' => 0, 'unchanged' => 0, 'skipped' => []];
    if (!sc_table_ready() || $counted === []) {
        return $result;
    }
    $existing = sc_counts($companyId, $countDate, $warehouseId);
    $owned = sc_owned_items($companyId, array_keys($counted));

    foreach ($counted as $rawItemId => $rawQty) {
        $itemId = (int) $rawItemId;
        $item = $owned[$itemId] ?? null;
        if (!$item) {
            continue; // not this company's stock item — a posted id is never trusted
        }
        $raw = trim((string) $rawQty);
        $note = mb_substr(trim((string) ($notes[$rawItemId] ?? '')), 0, 255);
        $current = $existing[$itemId] ?? null;

        if ($current !== null && sc_is_posted($current)) {
            // Only complain when the punch would actually have changed
            // something; re-submitting the same sheet must not shout.
            if ($raw !== '' && abs((float) $raw - (float) $current['counted_qty']) > INV_EPSILON) {
                $result['skipped'][] = $item['sku'] . ': already posted — unpost that count before changing it.';
            }
            continue;
        }

        if ($raw === '') {
            if ($current !== null) {
                db()->prepare('DELETE FROM inventory_stock_counts WHERE id = :id AND company_id = :cid AND posted_at IS NULL')
                    ->execute(['id' => (int) $current['id'], 'cid' => $companyId]);
                $result['cleared']++;
            }
            continue;
        }
        if (!is_numeric($raw)) {
            $result['skipped'][] = $item['sku'] . ': "' . $raw . '" is not a quantity.';
            continue;
        }
        $qty = inv_round_qty((float) $raw);
        if ($qty < 0) {
            $result['skipped'][] = $item['sku'] . ': a shelf cannot hold less than nothing — count 0 if it is empty.';
            continue;
        }
        if ($current !== null
            && abs((float) $current['counted_qty'] - $qty) <= INV_EPSILON
            && (string) ($current['notes'] ?? '') === $note) {
            $result['unchanged']++;
            continue;
        }
        db()->prepare('INSERT INTO inventory_stock_counts
                (company_id, item_id, warehouse_id, count_date, counted_qty, notes, counted_by)
            VALUES (:cid, :iid, :wh, :d, :q, :n, :by)
            ON DUPLICATE KEY UPDATE counted_qty = VALUES(counted_qty), notes = VALUES(notes), counted_by = VALUES(counted_by)')
            ->execute([
                'cid' => $companyId, 'iid' => $itemId, 'wh' => $warehouseId, 'd' => $countDate,
                'q' => $qty, 'n' => $note !== '' ? $note : null, 'by' => $userId ?: null,
            ]);
        $result['saved']++;
    }

    return $result;
}

/** The company's own stockable items, keyed by id, each with its on-hand tally. */
function sc_owned_items(int $companyId, array $itemIds): array
{
    $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
    if ($itemIds === []) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = db()->prepare("SELECT i.*, i.opening_qty + COALESCE((SELECT SUM(t.qty_in - t.qty_out)
                FROM inventory_transactions t WHERE t.item_id = i.id), 0) AS on_hand
        FROM inventory_items i
        WHERE i.company_id = ? AND i.item_type <> 'service' AND i.id IN ($ph)");
    $stmt->execute(array_merge([$companyId], $itemIds));
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['id']] = $row;
    }

    return $out;
}

/**
 * What the replay says each item's closing quantity is on $asOf, in the count's
 * own scope — the same figure the Stock Summary prints, out of the same engine,
 * so a variance can never be struck against a different number than the one on
 * the screen the counter was reading.
 *
 * @return array<int,array{qty:float, amount:float}>
 */
function sc_system_closing(int $companyId, string $asOf, int $warehouseId): array
{
    $report = sr_stock_summary($companyId, [
        'from' => $asOf,
        'to' => $asOf,
        'warehouse_ids' => $warehouseId === SC_COMPANY_WIDE ? [] : [$warehouseId],
        'zero_movement' => true,
        'zero_closing' => true,
        'dormant' => true,
    ]);
    $out = [];
    foreach ($report['rows'] as $row) {
        $out[(int) $row['item_id']] = ['qty' => (float) $row['closing_qty'], 'amount' => (float) $row['closing_amount']];
    }

    return $out;
}

/**
 * What one more unit of this item is carried at right now: the cost layers
 * first (that is the real IAS 2 figure), the item's purchase rate next, its
 * last inward rate last. Only ever used to value a SURPLUS — a shortfall is
 * valued by the layers it actually draws down.
 */
function sc_carrying_unit_cost(int $companyId, array $item): float
{
    $balance = inv_layer_balance($companyId, (int) $item['id']);
    if ((float) ($balance['qty'] ?? 0) > INV_EPSILON) {
        return round(((float) $balance['value']) / (float) $balance['qty'], 6);
    }
    if ((float) ($item['purchase_rate'] ?? 0) > 0) {
        return round((float) $item['purchase_rate'], 6);
    }
    $stmt = db()->prepare('SELECT rate FROM inventory_transactions
        WHERE company_id = :cid AND item_id = :iid AND qty_in > 0 AND rate > 0
        ORDER BY transaction_date DESC, id DESC LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'iid' => (int) $item['id']]);

    return round((float) ($stmt->fetchColumn() ?: 0), 6);
}

/**
 * Post every unposted count for one date and scope.
 *
 * Each count is its own transaction, so one item with an unmapped COGS ledger
 * does not cost the other forty their posting; every failure comes back with
 * its reason and the item it belongs to.
 *
 * A count whose ledgers are not mapped is SKIPPED WHOLE — it is not recorded
 * as stock-only the way a hand-typed movement is. The entire point of a count
 * is to charge the cost somewhere; moving the quantity while the cost goes
 * nowhere would leave the report and the books further apart than not posting
 * at all.
 *
 * @return array{posted:int, agreed:int, charged:float, credited:float, skipped:array<int,string>}
 * @throws RuntimeException when the whole date cannot be posted into (no fiscal year, closed period).
 */
function sc_post(int $companyId, ?int $fiscalYearId, string $countDate, int $warehouseId, string $chargeTo, int $userId): array
{
    $result = ['posted' => 0, 'agreed' => 0, 'charged' => 0.0, 'credited' => 0.0, 'skipped' => []];
    if (!sc_table_ready()) {
        return $result;
    }
    $chargeTo = $chargeTo === 'inventory_loss' ? 'inventory_loss' : 'cogs';

    $stmt = db()->prepare('SELECT * FROM inventory_stock_counts
        WHERE company_id = :cid AND count_date = :d AND warehouse_id = :wh AND posted_at IS NULL
        ORDER BY item_id ASC');
    $stmt->execute(['cid' => $companyId, 'd' => $countDate, 'wh' => $warehouseId]);
    $open = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($open === []) {
        return $result;
    }

    // The period lock is checked ONCE, before any stock row is written: every
    // movement here carries the same date, so it either opens for all of them
    // or for none. Checking it per item would post half a count into a period
    // and refuse the other half.
    $fiscalYearId = sc_assert_postable($companyId, $countDate, $fiscalYearId);

    $system = sc_system_closing($companyId, $countDate, $warehouseId);
    $items = sc_owned_items($companyId, array_map(static fn (array $c): int => (int) $c['item_id'], $open));

    foreach ($open as $count) {
        $itemId = (int) $count['item_id'];
        $item = $items[$itemId] ?? null;
        if ($item === null) {
            $result['skipped'][] = 'Item #' . $itemId . ': no longer a stock item of this company.';
            continue;
        }
        $systemQty = inv_round_qty((float) ($system[$itemId]['qty'] ?? 0.0));
        $variance = inv_round_qty((float) $count['counted_qty'] - $systemQty);
        try {
            db()->beginTransaction();
            if (abs($variance) <= INV_EPSILON) {
                // Counted, and the books were already right. Worth recording —
                // it is the difference between "verified" and "never looked at".
                sc_mark_posted((int) $count['id'], $systemQty, 0.0, 0.0, $chargeTo, null, null, $userId);
                db()->commit();
                $result['agreed']++;
                continue;
            }
            $outcome = sc_post_one($companyId, $fiscalYearId, $count, $item, $systemQty, $variance, $chargeTo, $countDate, $warehouseId, $userId);
            db()->commit();
            $result['posted']++;
            if ($variance < 0) {
                $result['charged'] += $outcome['value'];
            } else {
                $result['credited'] += $outcome['value'];
            }
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $reason = str_starts_with($e->getMessage(), 'MAP_MISSING:')
                ? 'map ' . str_replace(',', ' and ', substr($e->getMessage(), 12)) . ' before this can charge anywhere'
                : $e->getMessage();
            $result['skipped'][] = (string) $item['sku'] . ': ' . $reason;
        }
    }
    $result['charged'] = inv_round_money($result['charged']);
    $result['credited'] = inv_round_money($result['credited']);

    return $result;
}

/**
 * One count's difference, as a stock movement and the voucher that pays for
 * it. Runs inside the caller's transaction, and throws so the caller can roll
 * that one item back and skip it with a reason.
 *
 * @return array{txn_id:int, voucher_id:int, value:float}
 */
function sc_post_one(int $companyId, ?int $fiscalYearId, array $count, array $item, float $systemQty, float $variance, string $chargeTo, string $countDate, int $warehouseId, int $userId): array
{
    $itemId = (int) $item['id'];
    // 'stock_count' charges a shortfall to cost of sales; 'adjustment' charges
    // it to the inventory loss account, which is what that type has always meant.
    $type = $chargeTo === 'cogs' ? 'stock_count' : 'adjustment';
    $qtyIn = $variance > 0 ? $variance : 0.0;
    $qtyOut = $variance < 0 ? -$variance : 0.0;
    $direction = $qtyIn > 0 ? 'in' : 'out';
    // A surplus is valued at what a unit is carried at now; a shortfall is
    // valued by the layers it actually draws down, which is not known until
    // they have been drawn, so its row is stamped afterwards.
    $unitCost = $qtyIn > 0 ? sc_carrying_unit_cost($companyId, $item) : 0.0;
    if ($qtyIn > 0 && $unitCost <= 0) {
        throw new RuntimeException('the count found ' . number_format($qtyIn, 3)
            . ' more than the books hold, but the item has no cost to value them at — set its purchase rate first');
    }

    $notes = 'Physical count ' . $countDate . ' (' . sc_scope_label($companyId, $warehouseId) . '): counted '
        . number_format((float) $count['counted_qty'], 3) . ' against ' . number_format($systemQty, 3) . ' on the books.';
    db()->prepare('INSERT INTO inventory_transactions
            (company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date,
             warehouse_id, qty_in, qty_out, rate, amount, notes)
        VALUES (:cid, :fy, :iid, :type, :ref, :d, :wh, :qin, :qout, :rate, :amt, :notes)')
        ->execute([
            'cid' => $companyId, 'fy' => $fiscalYearId ?: null, 'iid' => $itemId, 'type' => $type,
            'ref' => 'COUNT-' . (int) $count['id'], 'd' => $countDate,
            'wh' => $warehouseId === SC_COMPANY_WIDE ? null : $warehouseId,
            'qin' => $qtyIn, 'qout' => $qtyOut, 'rate' => $unitCost,
            'amt' => inv_round_money(($qtyIn + $qtyOut) * $unitCost), 'notes' => $notes,
        ]);
    $txnId = (int) db()->lastInsertId();

    $issueValue = inv_apply_movement($companyId, $itemId, $qtyIn, $qtyOut, $unitCost, $countDate,
        (string) ($item['valuation_method'] ?? 'weighted_average'), $txnId,
        $warehouseId === SC_COMPANY_WIDE ? null : $warehouseId);
    $value = $qtyIn > 0 ? inv_round_money($qtyIn * $unitCost) : $issueValue;
    if ($qtyOut > 0) {
        // Now the layers have been drawn, the row can carry the cost that came
        // out of them instead of the placeholder it was written with.
        db()->prepare('UPDATE inventory_transactions SET rate = :r, amount = :a WHERE id = :id AND company_id = :cid')
            ->execute(['r' => round($value / $qtyOut, 6), 'a' => $value, 'id' => $txnId, 'cid' => $companyId]);
    }
    if ($value <= 0.004) {
        throw new RuntimeException('the difference values at nothing — there is no cost in the layers to charge');
    }

    // Throws MAP_MISSING when the ledgers are not set; the caller rolls the
    // whole item back rather than moving stock whose cost lands nowhere.
    $voucherId = inv_post_movement_voucher($companyId, $fiscalYearId, $txnId, $type, $item, $direction, $value, $countDate, $userId);
    // NO VOUCHER IS THE RIGHT ANSWER UNDER THE PERIODIC SYSTEM. There, a stock
    // count moves quantity and nothing else: the ledger carries no running stock
    // figure to correct, and the difference is caught at the year end when what
    // is on the shelf is counted and valued. Treating that as a failure refused
    // the count altogether and left the shelf figure wrong -- which is the one
    // thing a stock count exists to put right.
    //
    // Under perpetual it still is a failure: there the ledger DOES carry stock,
    // so a movement that reached no account has left the books and the shelf
    // disagreeing with nothing said.
    require_once __DIR__ . '/inventory_valuation.php';
    if ($voucherId <= 0 && inv_accounting_method() !== 'periodic') {
        throw new RuntimeException('no accounting entry could be raised for this difference');
    }
    if ($voucherId > 0) {
        db()->prepare('UPDATE inventory_transactions SET voucher_id = :vid WHERE id = :id AND company_id = :cid')
            ->execute(['vid' => $voucherId, 'id' => $txnId, 'cid' => $companyId]);
    }

    // Written-down stock leaving must carry its share of the allowance out with
    // it, exactly as it does on a sale (IAS 2.34).
    inv_post_allowance_release($companyId, $fiscalYearId, $txnId, $item, $type, $direction,
        $qtyOut, (float) ($item['on_hand'] ?? 0), $countDate, $userId, $voucherId, $issueValue);

    sc_mark_posted((int) $count['id'], $systemQty, $variance, $value, $chargeTo, $txnId, $voucherId, $userId);

    return ['txn_id' => $txnId, 'voucher_id' => $voucherId, 'value' => $value];
}

/** Stamp the count with what the books said, what the difference was, and what carried it. */
function sc_mark_posted(int $countId, float $systemQty, float $variance, float $value, string $chargeTo, ?int $txnId, ?int $voucherId, int $userId): void
{
    db()->prepare('UPDATE inventory_stock_counts
            SET system_qty = :sq, variance_qty = :vq, variance_value = :vv, charge_to = :ct,
                txn_id = :txn, voucher_id = :vid, posted_by = :by, posted_at = NOW()
            WHERE id = :id')
        ->execute([
            'sq' => $systemQty, 'vq' => $variance, 'vv' => $value, 'ct' => $chargeTo,
            'txn' => $txnId, 'vid' => $voucherId, 'by' => $userId ?: null, 'id' => $countId,
        ]);
}

/**
 * Take one posted count back: reverse its movement and its voucher, and return
 * the count to the sheet with the punched quantity still in it, so a mistyped
 * figure can be corrected instead of standing forever.
 *
 * The reversal is dated on the COUNT's date, not today. The count exists to
 * make the closing stock as at that date right; undoing it on a later date
 * would leave that date's closing wrong and bury the correction in a period
 * nobody is looking at.
 *
 * @return array{reversed:bool, txn_id:int, voucher_id:int}
 */
function sc_unpost(int $companyId, int $countId, int $userId, ?int $fiscalYearId = null): array
{
    if (!sc_table_ready()) {
        throw new RuntimeException('Stock counts are not available on this database yet.');
    }
    $stmt = db()->prepare('SELECT * FROM inventory_stock_counts WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $countId, 'cid' => $companyId]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$count) {
        throw new RuntimeException('That count belongs to another company, or no longer exists.');
    }
    if (!sc_is_posted($count)) {
        throw new RuntimeException('That count has not been posted, so there is nothing to take back.');
    }
    $countDate = (string) $count['count_date'];
    $fiscalYearId = sc_assert_postable($companyId, $countDate, $fiscalYearId);

    $txnId = (int) ($count['txn_id'] ?? 0);
    if ($txnId <= 0) {
        // The count agreed with the books, so it moved nothing. Reopening it is
        // the whole of the undo.
        sc_clear_posting($countId);

        return ['reversed' => false, 'txn_id' => 0, 'voucher_id' => 0];
    }

    $mvStmt = db()->prepare('SELECT * FROM inventory_transactions WHERE id = :id AND company_id = :cid LIMIT 1');
    $mvStmt->execute(['id' => $txnId, 'cid' => $companyId]);
    $movement = $mvStmt->fetch(PDO::FETCH_ASSOC);
    if (!$movement) {
        sc_clear_posting($countId);

        return ['reversed' => false, 'txn_id' => 0, 'voucher_id' => 0];
    }
    // One reversal per movement, keyed by its ref, so a double click cannot
    // give the stock back twice.
    $dupe = db()->prepare('SELECT id FROM inventory_transactions WHERE company_id = :cid AND ref_no = :ref LIMIT 1');
    $dupe->execute(['cid' => $companyId, 'ref' => 'COUNT-REV-' . $txnId]);
    if ($dupe->fetchColumn()) {
        throw new RuntimeException('That count movement has already been reversed.');
    }

    $itemId = (int) $movement['item_id'];
    $origVoucherId = (int) ($movement['voucher_id'] ?? 0);
    $origEntries = [];
    $origDebitTotal = 0.0;
    if ($origVoucherId > 0) {
        $entries = db()->prepare('SELECT ledger_id, entry_type, amount FROM voucher_entries WHERE voucher_id = :vid');
        $entries->execute(['vid' => $origVoucherId]);
        $origEntries = $entries->fetchAll(PDO::FETCH_ASSOC);
        foreach ($origEntries as $entry) {
            if ($entry['entry_type'] === 'debit') {
                $origDebitTotal += (float) $entry['amount'];
            }
        }
    }

    $revIn = (float) $movement['qty_out'];
    $revOut = (float) $movement['qty_in'];
    // Putting stock back must put back exactly the cost that was taken out, or
    // the subledger and the GL part company: the voucher swap restores the
    // inventory ledger by the original debit total, so the layer has to be
    // re-added at that same cost per unit.
    $revRate = (float) $movement['rate'];
    if ($revIn > INV_EPSILON && $origDebitTotal > 0) {
        $revRate = round($origDebitTotal / $revIn, 6);
    }

    db()->prepare('INSERT INTO inventory_transactions
            (company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date,
             warehouse_id, qty_in, qty_out, rate, amount, notes)
        VALUES (:cid, :fy, :iid, :type, :ref, :d, :wh, :qin, :qout, :rate, :amt, :notes)')
        ->execute([
            'cid' => $companyId, 'fy' => $fiscalYearId ?: null, 'iid' => $itemId,
            'type' => (string) $movement['transaction_type'], 'ref' => 'COUNT-REV-' . $txnId,
            'd' => $countDate, 'wh' => $movement['warehouse_id'],
            'qin' => $revIn, 'qout' => $revOut, 'rate' => $revRate,
            'amt' => inv_round_money(($revIn + $revOut) * $revRate),
            'notes' => 'Reversal of physical count movement #' . $txnId . '.',
        ]);
    $revTxnId = (int) db()->lastInsertId();
    inv_rebuild_item($companyId, $itemId); // net the layers back out

    $reversalVoucherId = 0;
    if ($origEntries !== []) {
        $swapped = [];
        $total = 0.0;
        foreach ($origEntries as $entry) {
            $swapped[] = [
                'ledger_id' => (int) $entry['ledger_id'],
                'entry_type' => $entry['entry_type'] === 'debit' ? 'credit' : 'debit',
                'amount' => (float) $entry['amount'],
            ];
            if ($entry['entry_type'] === 'debit') {
                $total += (float) $entry['amount'];
            }
        }
        $reversalVoucherId = (int) create_voucher_with_entries([
            'company_id' => $companyId,
            'fiscal_year_id' => $fiscalYearId ?: null,
            'voucher_no' => 'INV-COUNTREV-' . str_pad((string) $revTxnId, 6, '0', STR_PAD_LEFT),
            'voucher_type' => 'journal',
            'voucher_date' => $countDate,
            'source_type' => 'inventory_movement',
            'source_id' => $revTxnId,
            'total_amount' => inv_round_money($total),
            'narration' => 'Reversal of physical stock count voucher #' . $origVoucherId . ' (movement #' . $txnId . ').',
            'status' => 'posted',
            'posted_by' => $userId,
        ], $swapped);
        if ($reversalVoucherId > 0) {
            db()->prepare('UPDATE inventory_transactions SET voucher_id = :vid WHERE id = :id')
                ->execute(['vid' => $reversalVoucherId, 'id' => $revTxnId]);
        }
    }
    sc_clear_posting($countId);

    return ['reversed' => true, 'txn_id' => $revTxnId, 'voucher_id' => $reversalVoucherId];
}

/** Return a count to the sheet — the punched quantity stays, the posting does not. */
function sc_clear_posting(int $countId): void
{
    db()->prepare('UPDATE inventory_stock_counts
            SET posted_at = NULL, posted_by = NULL, txn_id = NULL, voucher_id = NULL,
                variance_qty = 0, variance_value = 0, system_qty = 0
            WHERE id = :id')
        ->execute(['id' => $countId]);
}

/**
 * The fiscal year a count date may post into, or a refusal that says why not.
 *
 * The year is the one that COVERS the date, not the one the screen happens to
 * have open — a movement filed under a year its own date falls outside of is a
 * row two reports will disagree about forever. $fiscalYearId is only the
 * fallback for a database with no fiscal years at all.
 */
function sc_assert_postable(int $companyId, string $countDate, ?int $fiscalYearId): ?int
{
    if (!table_exists('fiscal_years')) {
        return $fiscalYearId;
    }
    $fiscalYear = fiscal_year_for_date($companyId, $countDate);
    if (!$fiscalYear) {
        throw new RuntimeException('No fiscal year covers ' . $countDate . '. Open one for that period before posting a count into it.');
    }
    $blocker = fiscal_year_posting_blocker($fiscalYear, $countDate);
    if ($blocker !== null) {
        throw new RuntimeException($blocker);
    }

    return (int) $fiscalYear['id'];
}

/**
 * The state of one count sheet, for the panel above the report: how many rows
 * are punched but unposted, how many are posted, and what the unposted ones
 * are worth right now — valued from the report's own closing rate, so the
 * panel and the grid never quote two different numbers.
 *
 * @return array{open:int, posted:int, agreed:int, shortfall_qty:float, surplus_qty:float,
 *               shortfall_value:float, surplus_value:float, posted_charged:float, posted_credited:float}
 */
function sc_sheet_summary(int $companyId, string $countDate, int $warehouseId, array $reportRows = []): array
{
    $summary = ['open' => 0, 'posted' => 0, 'agreed' => 0, 'shortfall_qty' => 0.0, 'surplus_qty' => 0.0,
        'shortfall_value' => 0.0, 'surplus_value' => 0.0, 'posted_charged' => 0.0, 'posted_credited' => 0.0];
    if (!sc_table_ready()) {
        return $summary;
    }
    $counts = sc_counts($companyId, $countDate, $warehouseId);
    if ($counts === []) {
        return $summary;
    }
    $closing = [];
    foreach ($reportRows as $row) {
        $closing[(int) $row['item_id']] = ['qty' => (float) $row['closing_qty'], 'rate' => (float) $row['closing_rate']];
    }
    foreach ($counts as $itemId => $count) {
        if (sc_is_posted($count)) {
            $summary['posted']++;
            if (abs((float) $count['variance_qty']) <= INV_EPSILON) {
                $summary['agreed']++;
            } elseif ((float) $count['variance_qty'] < 0) {
                $summary['posted_charged'] += (float) $count['variance_value'];
            } else {
                $summary['posted_credited'] += (float) $count['variance_value'];
            }
            continue;
        }
        $summary['open']++;
        if (!isset($closing[$itemId])) {
            continue;
        }
        $variance = inv_round_qty((float) $count['counted_qty'] - $closing[$itemId]['qty']);
        $value = inv_round_money(abs($variance) * $closing[$itemId]['rate']);
        if ($variance < -INV_EPSILON) {
            $summary['shortfall_qty'] += -$variance;
            $summary['shortfall_value'] += $value;
        } elseif ($variance > INV_EPSILON) {
            $summary['surplus_qty'] += $variance;
            $summary['surplus_value'] += $value;
        }
    }
    foreach (['shortfall_value', 'surplus_value', 'posted_charged', 'posted_credited'] as $key) {
        $summary[$key] = inv_round_money($summary[$key]);
    }

    return $summary;
}
