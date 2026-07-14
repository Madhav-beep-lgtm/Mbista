<?php
declare(strict_types=1);

/**
 * IAS 2 inventory valuation engine (migration 036).
 *
 * Two layers:
 *   1. PURE cost-flow functions (FIFO, moving weighted average, specific
 *      identification) + NRV (lower of cost and net realisable value). These
 *      are deterministic and side-effect free so the IAS 2 worked examples in
 *      the specification can be asserted to the exact rupee (see the test
 *      script database/test_inventory_valuation.php).
 *   2. DB-backed helpers that persist/consume cost layers and resolve the
 *      scoped ledger mappings (item -> category -> global precedence).
 *
 * LIFO is intentionally NOT implemented — IAS 2 prohibits it.
 *
 * Rounding: internal maths keeps 6-dp cost precision to avoid drift; callers
 * round to money scale (2 dp) only at the boundary / on posting.
 */

const INV_QTY_SCALE = 4;
const INV_MONEY_SCALE = 2;
const INV_COST_SCALE = 6;
const INV_EPSILON = 0.00005;

function inv_round_money(float $n): float { return round($n, INV_MONEY_SCALE); }
function inv_round_qty(float $n): float { return round($n, INV_QTY_SCALE); }
function inv_round_cost(float $n): float { return round($n, INV_COST_SCALE); }

// ---------------------------------------------------------------------------
// FIFO
// ---------------------------------------------------------------------------

/**
 * Issue $qty out of ordered FIFO layers (oldest first).
 *
 * @param array $layers ordered [ ['qty'=>float, 'unit_cost'=>float, ...meta], ... ]
 * @param float $qty    quantity to issue
 * @return array{cogs: float, consumed: array, remaining: array}
 * @throws RuntimeException when $qty exceeds available (no negative layers).
 */
function inv_fifo_issue(array $layers, float $qty): array
{
    $qty = inv_round_qty($qty);
    if ($qty < 0) {
        throw new RuntimeException('FIFO issue quantity cannot be negative.');
    }

    $available = 0.0;
    foreach ($layers as $layer) {
        $available += (float) $layer['qty'];
    }
    if ($qty - $available > INV_EPSILON) {
        throw new RuntimeException(sprintf('FIFO issue of %s exceeds available %s.', $qty, inv_round_qty($available)));
    }

    $cogs = 0.0;
    $consumed = [];
    $remaining = [];
    $toIssue = $qty;

    foreach ($layers as $layer) {
        $layerQty = (float) $layer['qty'];
        $unitCost = (float) $layer['unit_cost'];
        if ($toIssue <= INV_EPSILON) {
            $remaining[] = $layer;
            continue;
        }
        $take = min($layerQty, $toIssue);
        $cogs += $take * $unitCost;
        $consumed[] = ['qty' => inv_round_qty($take), 'unit_cost' => $unitCost, 'value' => inv_round_money($take * $unitCost), 'meta' => $layer];
        $left = $layerQty - $take;
        $toIssue -= $take;
        if ($left > INV_EPSILON) {
            $kept = $layer;
            $kept['qty'] = inv_round_qty($left);
            $remaining[] = $kept;
        }
    }

    return [
        'cogs' => inv_round_money($cogs),
        'consumed' => $consumed,
        'remaining' => array_values($remaining),
    ];
}

/**
 * Closing value of a set of FIFO layers.
 */
function inv_layers_value(array $layers): float
{
    $value = 0.0;
    foreach ($layers as $layer) {
        $value += (float) $layer['qty'] * (float) $layer['unit_cost'];
    }
    return inv_round_money($value);
}

function inv_layers_qty(array $layers): float
{
    $qty = 0.0;
    foreach ($layers as $layer) {
        $qty += (float) $layer['qty'];
    }
    return inv_round_qty($qty);
}

// ---------------------------------------------------------------------------
// Moving weighted average (perpetual)
// ---------------------------------------------------------------------------

/**
 * Replay a movement stream, recomputing the average cost after every inward
 * movement (perpetual moving weighted average).
 *
 * @param array $movements ordered list of
 *   ['type'=>'in'|'out', 'qty'=>float, 'unit_cost'=>float (in only)]
 * @return array{avg: float, balance_qty: float, balance_value: float,
 *               cogs_total: float, steps: array}
 * @throws RuntimeException on an issue that exceeds the balance (no negative stock).
 */
function inv_weighted_average_run(array $movements): array
{
    $balQty = 0.0;
    $balValue = 0.0;
    $cogsTotal = 0.0;
    $steps = [];

    foreach ($movements as $i => $m) {
        $type = (string) ($m['type'] ?? 'in');
        $qty = inv_round_qty((float) ($m['qty'] ?? 0));

        if ($type === 'in') {
            $unitCost = (float) ($m['unit_cost'] ?? 0);
            $balQty += $qty;
            $balValue += $qty * $unitCost;
        } else {
            if ($qty - $balQty > INV_EPSILON) {
                throw new RuntimeException(sprintf('Weighted-average issue #%d of %s exceeds balance %s.', $i, $qty, inv_round_qty($balQty)));
            }
            $avg = $balQty > INV_EPSILON ? $balValue / $balQty : 0.0;
            $issueValue = $qty * $avg;
            $balQty -= $qty;
            $balValue -= $issueValue;
            $cogsTotal += $issueValue;
        }

        $avgNow = $balQty > INV_EPSILON ? $balValue / $balQty : 0.0;
        $steps[] = [
            'type' => $type,
            'qty' => $qty,
            'balance_qty' => inv_round_qty($balQty),
            'balance_value' => inv_round_money($balValue),
            'avg' => inv_round_cost($avgNow),
        ];
    }

    $avg = $balQty > INV_EPSILON ? $balValue / $balQty : 0.0;
    return [
        'avg' => inv_round_cost($avg),
        'balance_qty' => inv_round_qty($balQty),
        'balance_value' => inv_round_money($balValue),
        'cogs_total' => inv_round_money($cogsTotal),
        'steps' => $steps,
    ];
}

/**
 * The moving-average cost of a single issue given the current balance
 * (used by the perpetual posting path).
 */
function inv_weighted_average_issue_cost(float $balanceQty, float $balanceValue, float $issueQty): float
{
    if ($balanceQty <= INV_EPSILON) {
        return 0.0;
    }
    $avg = $balanceValue / $balanceQty;
    return inv_round_money($issueQty * $avg);
}

// ---------------------------------------------------------------------------
// Specific identification
// ---------------------------------------------------------------------------

/**
 * Specific identification: each identifiable unit/batch keeps its actual cost.
 *
 * @param array $units          identity => unit_cost (e.g. ['MACH-A'=>120000,'MACH-B'=>135000])
 * @param array $soldIdentities list of identities issued/sold
 * @return array{cogs: float, closing_value: float, closing: array, consumed: array}
 * @throws RuntimeException when an identity is unknown or already consumed.
 */
function inv_specific_valuation(array $units, array $soldIdentities): array
{
    $closing = $units;
    $cogs = 0.0;
    $consumed = [];

    foreach ($soldIdentities as $identity) {
        if (!array_key_exists($identity, $closing)) {
            throw new RuntimeException('Specific-identification unit not available: ' . (string) $identity);
        }
        $cost = (float) $closing[$identity];
        $cogs += $cost;
        $consumed[$identity] = inv_round_money($cost);
        unset($closing[$identity]);
    }

    $closingValue = 0.0;
    foreach ($closing as $cost) {
        $closingValue += (float) $cost;
    }

    return [
        'cogs' => inv_round_money($cogs),
        'closing_value' => inv_round_money($closingValue),
        'closing' => $closing,
        'consumed' => $consumed,
    ];
}

// ---------------------------------------------------------------------------
// NRV — lower of cost and net realisable value (IAS 2.28-33)
// ---------------------------------------------------------------------------

/**
 * Assess one inventory line for write-down / reversal.
 *
 * NRV per unit = selling price - completion cost - selling cost.
 * Compares cost and NRV item-by-item; a reversal is capped at the cumulative
 * prior write-down so carrying amount never exceeds original cost.
 *
 * @return array{
 *   nrv_per_unit: float, lower_per_unit: float, carrying_cost: float,
 *   required_write_down: float, prior_write_down: float,
 *   write_down: float, reversal: float, final_carrying: float
 * }
 */
function inv_nrv(
    float $qty,
    float $costPerUnit,
    float $sellingPrice,
    float $completionCost,
    float $sellingCost,
    float $priorWriteDown = 0.0
): array {
    $nrvPerUnit = $sellingPrice - $completionCost - $sellingCost;
    $lowerPerUnit = min($costPerUnit, $nrvPerUnit);

    $carryingCost = inv_round_money($qty * $costPerUnit);
    $carryingLower = inv_round_money($qty * $lowerPerUnit);

    // Cumulative write-down needed to bring cost down to the lower value.
    $requiredWriteDown = max(0.0, inv_round_money($carryingCost - $carryingLower));
    $priorWriteDown = max(0.0, inv_round_money($priorWriteDown));

    $delta = $requiredWriteDown - $priorWriteDown;
    $writeDown = $delta > 0 ? inv_round_money($delta) : 0.0;
    // A reversal can never exceed the cumulative prior write-down (IAS 2.33).
    $reversal = $delta < 0 ? inv_round_money(min($priorWriteDown, -$delta)) : 0.0;

    $finalCarrying = inv_round_money($carryingCost - ($priorWriteDown + $writeDown - $reversal));

    return [
        'nrv_per_unit' => inv_round_cost($nrvPerUnit),
        'lower_per_unit' => inv_round_cost($lowerPerUnit),
        'carrying_cost' => $carryingCost,
        'required_write_down' => $requiredWriteDown,
        'prior_write_down' => $priorWriteDown,
        'write_down' => $writeDown,
        'reversal' => $reversal,
        'final_carrying' => $finalCarrying,
    ];
}

// ---------------------------------------------------------------------------
// DB-backed cost-layer persistence
// ---------------------------------------------------------------------------

/**
 * Load a company item's open FIFO/specific layers, oldest first.
 */
function inv_load_open_layers(int $companyId, int $itemId): array
{
    if (!table_exists('inventory_cost_layers')) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT * FROM inventory_cost_layers
         WHERE company_id = :cid AND item_id = :iid AND qty_remaining > 0.00005
         ORDER BY layer_seq ASC, id ASC'
    );
    $stmt->execute(['cid' => $companyId, 'iid' => $itemId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Current on-hand qty and cost value of an item from its open layers.
 */
function inv_layer_balance(int $companyId, int $itemId): array
{
    $qty = 0.0;
    $value = 0.0;
    foreach (inv_load_open_layers($companyId, $itemId) as $layer) {
        $qty += (float) $layer['qty_remaining'];
        $value += (float) $layer['qty_remaining'] * (float) $layer['unit_cost'];
    }
    return ['qty' => inv_round_qty($qty), 'value' => inv_round_money($value)];
}

/**
 * Record an inward cost layer (receipt/opening/production/return-in).
 */
function inv_add_layer(int $companyId, int $itemId, float $qty, float $unitCost, string $date, ?int $sourceTxnId = null, ?string $batchNo = null, ?string $identity = null, ?int $warehouseId = null): int
{
    if (!table_exists('inventory_cost_layers') || $qty <= INV_EPSILON) {
        return 0;
    }
    $seqStmt = db()->prepare('SELECT COALESCE(MAX(layer_seq), 0) + 1 FROM inventory_cost_layers WHERE company_id = :cid AND item_id = :iid');
    $seqStmt->execute(['cid' => $companyId, 'iid' => $itemId]);
    $seq = (int) $seqStmt->fetchColumn();

    $stmt = db()->prepare(
        'INSERT INTO inventory_cost_layers
            (company_id, item_id, warehouse_id, batch_no, identity, is_specific, layer_date, layer_seq, source_txn_id, unit_cost, qty_in, qty_remaining)
         VALUES (:cid, :iid, :wid, :batch, :identity, :is_specific, :ldate, :seq, :src, :cost, :qin, :qrem)'
    );
    $stmt->execute([
        'cid' => $companyId, 'iid' => $itemId, 'wid' => $warehouseId, 'batch' => $batchNo, 'identity' => $identity,
        'is_specific' => $identity !== null ? 1 : 0, 'ldate' => $date, 'seq' => $seq,
        'src' => $sourceTxnId, 'cost' => inv_round_cost($unitCost), 'qin' => inv_round_qty($qty), 'qrem' => inv_round_qty($qty),
    ]);
    return (int) db()->lastInsertId();
}

/**
 * Consume $qty from an item's open layers using its valuation method, updating
 * qty_remaining. Returns the COGS (issue value). Runs inside the caller's
 * transaction. For weighted average the layers are drawn oldest-first but every
 * open layer is valued at the current moving-average cost so the issue value
 * equals qty * moving-average — matching inv_weighted_average_run.
 */
function inv_consume_layers(int $companyId, int $itemId, float $qty, string $method): float
{
    $qty = inv_round_qty($qty);
    if ($qty <= INV_EPSILON) {
        return 0.0;
    }
    $layers = inv_load_open_layers($companyId, $itemId);
    $balanceQty = 0.0;
    $balanceValue = 0.0;
    foreach ($layers as $layer) {
        $balanceQty += (float) $layer['qty_remaining'];
        $balanceValue += (float) $layer['qty_remaining'] * (float) $layer['unit_cost'];
    }
    if ($qty - $balanceQty > INV_EPSILON) {
        throw new RuntimeException('Issue quantity exceeds available stock for item #' . $itemId . '.');
    }

    $issueUnitCost = ($method === 'weighted_average' && $balanceQty > INV_EPSILON)
        ? $balanceValue / $balanceQty
        : null;

    $cogs = 0.0;
    $toIssue = $qty;
    $upd = db()->prepare('UPDATE inventory_cost_layers SET qty_remaining = :qrem WHERE id = :id');
    foreach ($layers as $layer) {
        if ($toIssue <= INV_EPSILON) {
            break;
        }
        $layerQty = (float) $layer['qty_remaining'];
        $take = min($layerQty, $toIssue);
        $unit = $issueUnitCost ?? (float) $layer['unit_cost']; // WAvg vs FIFO/specific
        $cogs += $take * $unit;
        $upd->execute(['qrem' => inv_round_qty($layerQty - $take), 'id' => (int) $layer['id']]);
        $toIssue -= $take;
    }

    return inv_round_money($cogs);
}

// ---------------------------------------------------------------------------
// Scoped ledger mapping resolution (item -> category -> global)
// ---------------------------------------------------------------------------

/**
 * Resolve the ledger mapped to a purpose for an item, honouring precedence:
 * item-level, then its category, then the company global default. Returns the
 * ledgers row or null when unmapped (posting must then be blocked).
 */
function inv_resolve_mapping(int $companyId, string $purpose, ?int $itemId = null, ?string $category = null): ?array
{
    if (!table_exists('inventory_ledger_mappings')) {
        return null;
    }

    $tryLedger = static function (?int $ledgerId) use ($companyId): ?array {
        if (!$ledgerId) {
            return null;
        }
        $stmt = db()->prepare('SELECT * FROM ledgers WHERE id = :id AND company_id = :cid LIMIT 1');
        $stmt->execute(['id' => $ledgerId, 'cid' => $companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    // Item-level
    if ($itemId) {
        $stmt = db()->prepare('SELECT ledger_id FROM inventory_ledger_mappings WHERE company_id = :cid AND scope = \'item\' AND item_id = :iid AND purpose = :p LIMIT 1');
        $stmt->execute(['cid' => $companyId, 'iid' => $itemId, 'p' => $purpose]);
        $row = $tryLedger((int) ($stmt->fetchColumn() ?: 0));
        if ($row) {
            $row['mapping_source'] = 'item';
            return $row;
        }
    }
    // Category-level
    if ($category !== null && $category !== '') {
        $stmt = db()->prepare('SELECT ledger_id FROM inventory_ledger_mappings WHERE company_id = :cid AND scope = \'category\' AND category = :cat AND purpose = :p LIMIT 1');
        $stmt->execute(['cid' => $companyId, 'cat' => $category, 'p' => $purpose]);
        $row = $tryLedger((int) ($stmt->fetchColumn() ?: 0));
        if ($row) {
            $row['mapping_source'] = 'category';
            return $row;
        }
    }
    // Global default
    $stmt = db()->prepare('SELECT ledger_id FROM inventory_ledger_mappings WHERE company_id = :cid AND scope = \'global\' AND purpose = :p LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'p' => $purpose]);
    $row = $tryLedger((int) ($stmt->fetchColumn() ?: 0));
    if ($row) {
        $row['mapping_source'] = 'global';
        return $row;
    }

    return null;
}

/**
 * Validate that every purpose in $purposes resolves to a ledger for the item.
 * Returns the list of MISSING purposes (empty = ready to post). Posting engines
 * must call this and refuse to post when it is non-empty.
 */
function inv_missing_mappings(int $companyId, array $purposes, ?int $itemId = null, ?string $category = null): array
{
    $missing = [];
    foreach ($purposes as $purpose) {
        if (inv_resolve_mapping($companyId, $purpose, $itemId, $category) === null) {
            $missing[] = $purpose;
        }
    }
    return $missing;
}

// ---------------------------------------------------------------------------
// Movement application + backfill (bridges the legacy inventory_transactions
// table to the perpetual cost-layer store so on-hand VALUE is real IAS 2 cost)
// ---------------------------------------------------------------------------

/**
 * Apply one stock movement to the cost layers. Inward movements add a layer at
 * the given unit cost; outward movements consume layers by the item's method
 * and return the issue value (COGS). Runs inside the caller's transaction.
 */
function inv_apply_movement(int $companyId, int $itemId, float $qtyIn, float $qtyOut, float $unitCost, string $date, string $method, ?int $sourceTxnId = null, ?int $warehouseId = null): float
{
    if ($qtyIn > INV_EPSILON) {
        inv_add_layer($companyId, $itemId, $qtyIn, $unitCost, $date, $sourceTxnId, null, null, $warehouseId);
        return inv_round_money($qtyIn * $unitCost);
    }
    if ($qtyOut > INV_EPSILON) {
        return inv_consume_layers($companyId, $itemId, $qtyOut, $method);
    }
    return 0.0;
}

/**
 * Rebuild an item's cost layers from scratch by replaying its
 * inventory_transactions in chronological order (opening first). Idempotent —
 * deletes existing layers then re-derives them. Lets legacy items and any
 * backdated edits recompute a correct perpetual valuation.
 */
function inv_rebuild_layers(int $companyId, int $itemId, string $method, float $openingQty, float $openingRate): void
{
    if (!table_exists('inventory_cost_layers')) {
        return;
    }
    db()->prepare('DELETE FROM inventory_cost_layers WHERE company_id = :cid AND item_id = :iid')
        ->execute(['cid' => $companyId, 'iid' => $itemId]);

    if ($openingQty > INV_EPSILON) {
        inv_add_layer($companyId, $itemId, $openingQty, $openingRate, '2000-01-01');
    }

    // Location-only movements are excluded: the stock never left the entity, so
    // the company-level cost layers must not be touched. Replaying them would
    // consume the oldest layers on the out leg and re-add the stock as the
    // NEWEST layer on the in leg, silently re-ordering the FIFO queue and
    // mis-stating every subsequent COGS. See inv_movement_is_location_only().
    $stmt = db()->prepare(
        'SELECT id, transaction_type, qty_in, qty_out, rate, transaction_date
         FROM inventory_transactions
         WHERE company_id = :cid AND item_id = :iid AND transaction_type <> :opening
           AND transaction_type NOT IN (\'warehouse_transfer\', \'departmental_transfer\')
         ORDER BY transaction_date ASC, id ASC'
    );
    $stmt->execute(['cid' => $companyId, 'iid' => $itemId, 'opening' => 'opening']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $qin = (float) $t['qty_in'];
        $qout = (float) $t['qty_out'];
        $rate = (float) $t['rate'];
        try {
            inv_apply_movement($companyId, $itemId, $qin, $qout, $rate, (string) $t['transaction_date'], $method, (int) $t['id']);
        } catch (Throwable $e) {
            // Skip a movement that would drive negative (legacy data); the
            // remaining layers still give the best available valuation.
        }
    }
    // Replay the opening-type transaction rows too (some items record opening
    // as a transaction rather than on the master).
    $openStmt = db()->prepare('SELECT id, qty_in, rate, transaction_date FROM inventory_transactions WHERE company_id = :cid AND item_id = :iid AND transaction_type = :opening ORDER BY id ASC');
    $openStmt->execute(['cid' => $companyId, 'iid' => $itemId, 'opening' => 'opening']);
    foreach ($openStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if ((float) $t['qty_in'] > INV_EPSILON) {
            inv_add_layer($companyId, $itemId, (float) $t['qty_in'], (float) $t['rate'], (string) $t['transaction_date'], (int) $t['id']);
        }
    }
}

/**
 * Rebuild one item's layers by id (loads its method/opening then replays).
 * Used after manufacturing operations and as a lazy backfill for legacy items.
 */
function inv_rebuild_item(int $companyId, int $itemId): void
{
    $stmt = db()->prepare('SELECT valuation_method, opening_qty, purchase_rate FROM inventory_items WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $itemId, 'cid' => $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    inv_rebuild_layers($companyId, $itemId, (string) ($row['valuation_method'] ?? 'weighted_average'), (float) ($row['opening_qty'] ?? 0), (float) ($row['purchase_rate'] ?? 0));
}

/**
 * Lazily backfill an item's cost layers the first time it is valued: if it has
 * inventory_transactions but no cost layers yet (legacy data), rebuild once.
 */
function inv_ensure_layers(int $companyId, array $item): void
{
    if (!table_exists('inventory_cost_layers')) {
        return;
    }
    $itemId = (int) $item['id'];
    $cnt = db()->prepare('SELECT COUNT(*) FROM inventory_cost_layers WHERE company_id = :cid AND item_id = :iid');
    $cnt->execute(['cid' => $companyId, 'iid' => $itemId]);
    if ((int) $cnt->fetchColumn() > 0) {
        return; // already has layers
    }
    $hasOpening = (float) ($item['opening_qty'] ?? 0) > INV_EPSILON;
    $txn = db()->prepare('SELECT COUNT(*) FROM inventory_transactions WHERE company_id = :cid AND item_id = :iid');
    $txn->execute(['cid' => $companyId, 'iid' => $itemId]);
    if ($hasOpening || (int) $txn->fetchColumn() > 0) {
        inv_rebuild_layers($companyId, $itemId, (string) ($item['valuation_method'] ?? 'weighted_average'), (float) ($item['opening_qty'] ?? 0), (float) ($item['purchase_rate'] ?? 0));
    }
}

/**
 * Valuation snapshot for one item using its cost layers + an NRV proxy.
 * Cost value comes from the perpetual layers; NRV per unit uses an explicit
 * assessment if present, otherwise the item's sales_rate as selling price.
 *
 * @return array{qty: float, cost_value: float, unit_cost: float,
 *               nrv_per_unit: float, lower_value: float, write_down: float}
 */
function inv_item_valuation(int $companyId, array $item): array
{
    $itemId = (int) $item['id'];
    inv_ensure_layers($companyId, $item); // backfill legacy items once
    $bal = inv_layer_balance($companyId, $itemId);
    $qty = $bal['qty'];
    $costValue = $bal['value'];

    // Fallback: if no layers exist yet (legacy), value at purchase_rate * on_hand.
    if ($qty <= INV_EPSILON && isset($item['on_hand'])) {
        $qty = inv_round_qty((float) $item['on_hand']);
        $costValue = inv_round_money($qty * (float) ($item['purchase_rate'] ?? 0));
    }

    $unitCost = $qty > INV_EPSILON ? $costValue / $qty : 0.0;

    // Latest NRV assessment overrides; else use sales_rate as selling price.
    $sellingPrice = (float) ($item['sales_rate'] ?? 0);
    $completion = 0.0;
    $selling = 0.0;
    if (table_exists('inventory_nrv_assessments')) {
        // Only real assessments carry NRV inputs. Allowance-RELEASE rows (written
        // when stock leaves, release_amount > 0) have no selling price, and a
        // reversed assessment no longer holds — either one, picked up as "the
        // latest assessment", would silently reset the item's NRV to cost.
        $a = db()->prepare(inv_nrv_assessment_columns_ready()
            ? 'SELECT selling_price, completion_cost, selling_cost FROM inventory_nrv_assessments
               WHERE company_id = :cid AND item_id = :iid AND status = \'active\' AND release_amount = 0
               ORDER BY assessment_date DESC, id DESC LIMIT 1'
            : 'SELECT selling_price, completion_cost, selling_cost FROM inventory_nrv_assessments
               WHERE company_id = :cid AND item_id = :iid ORDER BY assessment_date DESC, id DESC LIMIT 1');
        $a->execute(['cid' => $companyId, 'iid' => $itemId]);
        $row = $a->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $sellingPrice = (float) $row['selling_price'];
            $completion = (float) $row['completion_cost'];
            $selling = (float) $row['selling_cost'];
        }
    }
    $nrvPerUnit = $sellingPrice > 0 ? ($sellingPrice - $completion - $selling) : $unitCost;
    $lowerPerUnit = min($unitCost, $nrvPerUnit);
    $lowerValue = inv_round_money($qty * $lowerPerUnit);
    $writeDown = inv_round_money(max(0.0, $costValue - $lowerValue));

    return [
        'qty' => $qty,
        'cost_value' => inv_round_money($costValue),
        'unit_cost' => inv_round_cost($unitCost),
        'nrv_per_unit' => inv_round_cost($nrvPerUnit),
        'lower_value' => $lowerValue,
        'write_down' => $writeDown,
    ];
}

/**
 * Company-wide valuation totals across all active items (drives the KPI cards).
 * @return array{cost: float, lower: float, write_down: float}
 */
function inv_company_valuation(int $companyId, array $items): array
{
    $cost = 0.0;
    $lower = 0.0;
    $writeDown = 0.0;
    foreach ($items as $item) {
        $v = inv_item_valuation($companyId, $item);
        $cost += $v['cost_value'];
        $lower += $v['lower_value'];
        $writeDown += $v['write_down'];
    }
    return ['cost' => inv_round_money($cost), 'lower' => inv_round_money($lower), 'write_down' => inv_round_money($writeDown)];
}

// ---------------------------------------------------------------------------
// Warehouse dimension (migration 039). Quantity is tracked per warehouse via
// inventory_transactions.warehouse_id; cost layers stay valued at company+item
// level (not split per warehouse) — a deliberate scope limit so the tested
// FIFO/weighted-average/specific engine above is untouched by this dimension.
// ---------------------------------------------------------------------------

/**
 * True for movements that only relocate stock inside the entity. These change
 * WHERE stock sits, never how much the company owns or what it cost, so they
 * must not touch the (company+item level) cost layers and never post to the GL.
 */
function inv_movement_is_location_only(string $type): bool
{
    return in_array($type, ['warehouse_transfer', 'departmental_transfer'], true);
}

/**
 * On-hand quantity of one item at ONE warehouse (null = the unassigned bucket).
 * Quantity only — cost stays company+item level.
 */
function inv_item_warehouse_qty(int $companyId, int $itemId, ?int $warehouseId): float
{
    if (!table_exists('inventory_transactions')) {
        return 0.0;
    }
    $sql = 'SELECT COALESCE(SUM(qty_in - qty_out), 0) FROM inventory_transactions
            WHERE company_id = :cid AND item_id = :iid AND ' . ($warehouseId === null ? 'warehouse_id IS NULL' : 'warehouse_id = :wid');
    $stmt = db()->prepare($sql);
    $params = ['cid' => $companyId, 'iid' => $itemId];
    if ($warehouseId !== null) {
        $params['wid'] = $warehouseId;
    }
    $stmt->execute($params);

    return inv_round_qty((float) $stmt->fetchColumn());
}

/**
 * Active warehouses for a company, for select dropdowns.
 */
function inv_company_warehouses(int $companyId): array
{
    if (!table_exists('warehouses')) {
        return [];
    }
    $stmt = db()->prepare('SELECT * FROM warehouses WHERE company_id = :cid AND is_active = 1 ORDER BY name ASC');
    $stmt->execute(['cid' => $companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * On-hand quantity for one item, grouped by warehouse (plus an "unassigned"
 * bucket for movements with no warehouse_id). Quantity-only — not a cost
 * split, since cost layers remain company+item level.
 */
function inv_item_warehouse_stock(int $companyId, int $itemId): array
{
    if (!table_exists('inventory_transactions') || !table_exists('warehouses')) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT t.warehouse_id, w.name AS warehouse_name,
                SUM(t.qty_in - t.qty_out) AS on_hand
         FROM inventory_transactions t
         LEFT JOIN warehouses w ON w.id = t.warehouse_id
         WHERE t.company_id = :cid AND t.item_id = :iid
         GROUP BY t.warehouse_id, w.name
         ORDER BY (t.warehouse_id IS NULL) ASC, w.name ASC'
    );
    $stmt->execute(['cid' => $companyId, 'iid' => $itemId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'warehouse_id' => $row['warehouse_id'] !== null ? (int) $row['warehouse_id'] : null,
            'warehouse_name' => $row['warehouse_name'] ?? 'Unassigned',
            'on_hand' => inv_round_qty((float) $row['on_hand']),
        ];
    }
    return $rows;
}

// ---------------------------------------------------------------------------
// NRV allowance lifecycle (IAS 2.28-34), migration 041.
//
// A write-down raises an ALLOWANCE (a contra-asset) and never touches the cost
// layers. inventory_nrv_assessments is therefore the ledger of allowance
// movements for an item:
//     write_down     (+) raised because NRV fell below cost
//     reversal       (-) released because NRV recovered      (IAS 2.33)
//     release_amount (-) consumed because the stock left     (IAS 2.34)
// The standing allowance is the net of those over status = 'active' rows.
// ---------------------------------------------------------------------------

/** True once migration 041's allowance columns exist (deploy-safe guard). */
function inv_nrv_assessment_columns_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = table_exists('inventory_nrv_assessments')
            && column_exists('inventory_nrv_assessments', 'release_amount')
            && column_exists('inventory_nrv_assessments', 'status');
    }

    return $ready;
}

/**
 * The allowance currently standing against an item: everything written down,
 * less what has been reversed (NRV recovered) and released (stock left).
 * Floored at zero — a negative standing allowance is meaningless.
 *
 * $postedOnly restricts the sum to rows that actually reached the GL (a
 * voucher_id). A write-down whose ledgers were unmapped is still recorded in
 * the subledger with voucher_id NULL, so it counts toward the SUBLEDGER
 * allowance (and so toward prior_write_down on the next assessment) but there
 * is no credit balance in the allowance ledger to draw on. Anything that POSTS
 * against the allowance must therefore ask for the posted-only figure, or it
 * would debit an allowance the GL never had.
 */
function inv_standing_allowance(int $companyId, int $itemId, bool $postedOnly = false): float
{
    if (!table_exists('inventory_nrv_assessments')) {
        return 0.0;
    }
    if (!inv_nrv_assessment_columns_ready()) {
        $legacy = db()->prepare(
            'SELECT COALESCE(SUM(write_down), 0) - COALESCE(SUM(reversal), 0)
             FROM inventory_nrv_assessments WHERE company_id = :cid AND item_id = :iid'
        );
        $legacy->execute(['cid' => $companyId, 'iid' => $itemId]);

        return max(0.0, inv_round_money((float) $legacy->fetchColumn()));
    }
    $sql = 'SELECT COALESCE(SUM(write_down), 0) - COALESCE(SUM(reversal), 0) - COALESCE(SUM(release_amount), 0)
            FROM inventory_nrv_assessments
            WHERE company_id = :cid AND item_id = :iid AND status = \'active\''
        . ($postedOnly ? ' AND voucher_id IS NOT NULL' : '');
    $stmt = db()->prepare($sql);
    $stmt->execute(['cid' => $companyId, 'iid' => $itemId]);

    return max(0.0, inv_round_money((float) $stmt->fetchColumn()));
}

/**
 * The slice of the standing allowance that belongs to units leaving stock.
 *
 * The allowance was raised against the whole on-hand quantity, so when part of
 * that quantity is issued its share of the allowance goes with it, pro rata:
 *     release = standing_allowance * (qty_issued / qty_on_hand_before_issue)
 * Capped at the standing allowance, and the whole of it once the last unit
 * leaves (so nothing can be stranded by rounding).
 *
 * $issueValue is the cost ACTUALLY drawn from the cost layers for those units,
 * and hard-caps the release. The pro-rata share above is a quantity average,
 * but FIFO/specific issue real layers: selling the cheapest layer draws little
 * cost while a quantity-proportional release would give back an average-priced
 * slice of the allowance — crediting the expense by MORE than the movement
 * debited it and turning the sale's net expense negative. IAS 2.34 makes the
 * expense the written-down carrying amount of the units sold, which is floored
 * at zero, so the release can never exceed the cost drawn. Pass 0.0/negative to
 * skip the cap (callers that have no cost figure).
 */
function inv_allowance_release_for_issue(
    float $standingAllowance,
    float $qtyIssued,
    float $qtyOnHandBefore,
    float $issueValue = -1.0
): float {
    if ($standingAllowance <= INV_EPSILON || $qtyIssued <= INV_EPSILON || $qtyOnHandBefore <= INV_EPSILON) {
        return 0.0;
    }
    $release = ($qtyIssued >= $qtyOnHandBefore - INV_EPSILON)
        ? $standingAllowance // last of the stock: release it all, strand nothing
        : min($standingAllowance, $standingAllowance * ($qtyIssued / $qtyOnHandBefore));

    if ($issueValue >= 0.0) {
        $release = min($release, $issueValue);
    }

    return max(0.0, inv_round_money($release));
}

/**
 * Post the allowance release for an outward movement and record it against the
 * item's allowance ledger, so the expense recognised for the sold stock is its
 * WRITTEN-DOWN carrying amount rather than full cost (IAS 2.34).
 *
 * Dr write-down allowance / Cr <whatever expense the movement itself debited>
 * — for a sale that credits COGS back down; for a write-off, the loss account.
 * Idempotent via vouchers UNIQUE(source_type, source_id) on the release row id.
 * Runs inside the caller's transaction. Returns [releaseAmount, voucherId];
 * a missing mapping releases nothing rather than blocking the movement.
 */
function inv_post_allowance_release(
    int $companyId,
    ?int $fiscalYearId,
    int $txnId,
    array $item,
    string $type,
    string $direction,
    float $qtyIssued,
    float $qtyOnHandBefore,
    string $date,
    int $userId,
    int $movementVoucherId = 0,
    float $issueValue = -1.0
): array {
    if (!inv_nrv_assessment_columns_ready() || inv_movement_is_location_only($type)) {
        return [0.0, 0];
    }
    // NRV postings are themselves allowance movements — they must not trigger one.
    if (in_array($type, ['nrv_write_down', 'nrv_reversal'], true) || $direction !== 'out') {
        return [0.0, 0];
    }
    // The release exists to credit back the expense the MOVEMENT debited. If the
    // movement posted no voucher (unmapped ledgers), there is no expense to credit
    // back: releasing anyway would credit COGS for stock whose cost was never
    // debited to it, leaving that expense with a credit balance.
    if ($movementVoucherId <= 0) {
        return [0.0, 0];
    }
    $plan = inv_movement_posting_plan($type, $direction);
    if ($plan === null) {
        return [0.0, 0];
    }
    // Only ever release into a genuine EXPENSE. Other outward movements debit a
    // balance-sheet account — purchase_return debits purchase_clearing (a
    // liability), material_issue debits WIP (an asset, where the cost carries
    // forward rather than being expensed) — and crediting those back would
    // understate the payable / the WIP cost instead of the expense. The allowance
    // simply stays standing for those, which is conservative and self-correcting.
    if (!in_array($plan['debit'], ['cogs', 'inventory_loss'], true)) {
        return [0.0, 0];
    }

    $itemId = (int) $item['id'];
    // Posted-only: we are about to DEBIT the allowance ledger, so we may only draw
    // on allowance that was actually CREDITED to it (see inv_standing_allowance).
    $standing = inv_standing_allowance($companyId, $itemId, true);
    $release = inv_allowance_release_for_issue($standing, $qtyIssued, $qtyOnHandBefore, $issueValue);
    if ($release <= 0) {
        return [0.0, 0];
    }

    $category = $item['category'] ?? null;
    $allowance = inv_resolve_mapping($companyId, 'write_down_allowance', $itemId, $category);
    $expense = inv_resolve_mapping($companyId, $plan['debit'], $itemId, $category);
    if (!$allowance || !$expense) {
        // Nothing mapped to release into — leave the allowance standing rather
        // than blocking the sale. The Valuation tab still shows it.
        return [0.0, 0];
    }

    $releaseRow = db()->prepare(
        'INSERT INTO inventory_nrv_assessments
            (company_id, fiscal_year_id, item_id, assessment_date, quantity, release_amount, source_txn_id, evidence, created_by)
         VALUES (:cid, :fy, :iid, :d, :qty, :rel, :txn, :ev, :uid)'
    );
    $releaseRow->execute([
        'cid' => $companyId,
        'fy' => $fiscalYearId ?: null,
        'iid' => $itemId,
        'd' => $date,
        'qty' => inv_round_qty($qtyIssued),
        'rel' => $release,
        'txn' => $txnId,
        'ev' => 'Allowance released on ' . str_replace('_', ' ', $type) . ' of ' . inv_round_qty($qtyIssued) . ' unit(s) (IAS 2.34).',
        'uid' => $userId,
    ]);
    $releaseId = (int) db()->lastInsertId();

    $voucherId = (int) create_voucher_with_entries([
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId ?: null,
        'voucher_no' => 'INV-NRVREL-' . str_pad((string) $releaseId, 6, '0', STR_PAD_LEFT),
        'voucher_type' => 'journal',
        'voucher_date' => $date,
        'source_type' => 'inventory_nrv_release',
        'source_id' => $releaseId,
        'total_amount' => $release,
        'narration' => 'Release of NRV write-down allowance on ' . ($item['sku'] ?? '') . ' (IAS 2.34).',
        'status' => 'posted',
        'posted_by' => $userId,
    ], [
        ['ledger_id' => (int) $allowance['id'], 'entry_type' => 'debit', 'amount' => $release],
        ['ledger_id' => (int) $expense['id'], 'entry_type' => 'credit', 'amount' => $release],
    ]);

    db()->prepare('UPDATE inventory_nrv_assessments SET voucher_id = :vid WHERE id = :id')
        ->execute(['vid' => $voucherId ?: null, 'id' => $releaseId]);

    return [$release, $voucherId];
}

/**
 * Void the allowance rows a movement created, so a reversed/deleted movement
 * stops counting toward the standing allowance. Without this a reversed
 * write-down would leave its allowance standing forever, overstating
 * prior_write_down and silently blocking every later write-down on the item.
 *
 * Any GL voucher those rows posted is reversed too (mirror entries, original
 * preserved) — marking the row dead while leaving its voucher on the books
 * would put the allowance ledger permanently out of step with the subledger.
 * Runs inside the caller's transaction. Returns [rowsVoided, netAllowanceUndone].
 */
function inv_void_allowance_rows_for_txn(int $companyId, int $txnId, ?int $fiscalYearId = null, ?string $date = null, int $userId = 0): array
{
    if (!inv_nrv_assessment_columns_ready() || $txnId <= 0) {
        return [0, 0.0];
    }
    $date = $date ?? date('Y-m-d');

    $rows = db()->prepare(
        'SELECT id, item_id, voucher_id, write_down, reversal, release_amount
         FROM inventory_nrv_assessments
         WHERE company_id = :cid AND source_txn_id = :txn AND status = \'active\''
    );
    $rows->execute(['cid' => $companyId, 'txn' => $txnId]);
    $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return [0, 0.0];
    }

    // An allowance that has already been (partly) RELEASED cannot be unwound.
    // Release rows hang off the SALE that consumed them, not off this write-down,
    // so voiding this row would leave them standing while the caller reverses the
    // write-down voucher in FULL — handing back allowance that was already spent.
    // The contra-asset would end up with a net DEBIT balance (inventory carried
    // ABOVE cost) and the expense credited by the release would never be
    // re-recognised. Refuse instead, and let the user reverse the sale first.
    foreach ($rows as $row) {
        $raised = (float) $row['write_down'] - (float) $row['reversal'];
        if ($raised <= INV_EPSILON) {
            continue;
        }
        $stillStanding = inv_standing_allowance($companyId, (int) $row['item_id']);
        if ($raised > $stillStanding + INV_EPSILON) {
            throw new RuntimeException(
                'ALLOWANCE_CONSUMED:' . inv_round_money($raised - $stillStanding)
            );
        }
    }

    $net = 0.0;
    foreach ($rows as $row) {
        $rowId = (int) $row['id'];
        $net += (float) $row['write_down'] - (float) $row['reversal'] - (float) $row['release_amount'];

        // Only a RELEASE row owns a voucher of its own. A write-down / NRV-reversal
        // assessment shares the voucher_id of the movement that posted it, and the
        // caller (reverse_movement) already reverses that one — mirroring it here
        // too would reverse the same voucher twice and leave the allowance ledger
        // carrying a spurious balance.
        $ownsVoucher = (float) $row['release_amount'] > INV_EPSILON;
        $voucherId = (int) ($row['voucher_id'] ?? 0);
        if ($ownsVoucher && $voucherId > 0) {
            $entries = db()->prepare('SELECT ledger_id, entry_type, amount FROM voucher_entries WHERE voucher_id = :vid');
            $entries->execute(['vid' => $voucherId]);
            $mirror = [];
            $total = 0.0;
            foreach ($entries->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                $amount = (float) $entry['amount'];
                $mirror[] = [
                    'ledger_id' => (int) $entry['ledger_id'],
                    'entry_type' => $entry['entry_type'] === 'debit' ? 'credit' : 'debit',
                    'amount' => $amount,
                ];
                if ($entry['entry_type'] === 'debit') {
                    $total += $amount;
                }
            }
            if ($mirror !== [] && $total > 0) {
                create_voucher_with_entries([
                    'company_id' => $companyId,
                    'fiscal_year_id' => $fiscalYearId ?: null,
                    'voucher_no' => 'INV-NRVREV-' . str_pad((string) $rowId, 6, '0', STR_PAD_LEFT),
                    'voucher_type' => 'journal',
                    'voucher_date' => $date,
                    'source_type' => 'inventory_nrv_void',
                    'source_id' => $rowId,
                    'total_amount' => inv_round_money($total),
                    'narration' => 'Reversal of NRV allowance entry #' . $rowId . ' (source movement reversed).',
                    'status' => 'posted',
                    'posted_by' => $userId,
                ], $mirror);
            }
        }
    }

    db()->prepare(
        'UPDATE inventory_nrv_assessments SET status = \'reversed\'
         WHERE company_id = :cid AND source_txn_id = :txn AND status = \'active\''
    )->execute(['cid' => $companyId, 'txn' => $txnId]);

    return [count($rows), inv_round_money($net)];
}

/**
 * The Dr/Cr posting plan for a stock movement (section E posting matrix).
 * Direction is system-derived; $direction 'in'|'out' only matters for
 * adjustments (which can go either way). Returns
 *   ['debit' => purpose, 'credit' => purpose]
 * or null when the movement has NO general-ledger impact (departmental /
 * warehouse transfer within the same entity and ownership).
 */
function inv_movement_posting_plan(string $type, string $direction): ?array
{
    switch ($type) {
        case 'opening':
            return ['debit' => 'inventory_asset', 'credit' => 'opening_equity'];
        case 'purchase':
        case 'purchase_receipt':
            return ['debit' => 'inventory_asset', 'credit' => 'purchase_clearing'];
        case 'purchase_return':
            return ['debit' => 'purchase_clearing', 'credit' => 'inventory_asset'];
        case 'sale':
        case 'sales_delivery':
            // COGS/inventory leg only; the revenue leg is the invoice module's.
            return ['debit' => 'cogs', 'credit' => 'inventory_asset'];
        case 'sales_return':
            return ['debit' => 'inventory_asset', 'credit' => 'cogs'];
        case 'write_off':
        case 'damage':
        case 'expiry':
            return ['debit' => 'inventory_loss', 'credit' => 'inventory_asset'];
        case 'material_issue':
            return ['debit' => 'wip', 'credit' => 'raw_material'];
        case 'material_return':
            return ['debit' => 'raw_material', 'credit' => 'wip'];
        case 'production_receipt':
            return ['debit' => 'finished_goods', 'credit' => 'wip'];
        case 'scrap_receipt':
            return ['debit' => 'scrap_inventory', 'credit' => 'wip'];
        case 'adjustment':
            return $direction === 'in'
                ? ['debit' => 'inventory_asset', 'credit' => 'inventory_gain']
                : ['debit' => 'inventory_loss', 'credit' => 'inventory_asset'];
        case 'nrv_write_down':
            return ['debit' => 'write_down_expense', 'credit' => 'write_down_allowance'];
        case 'nrv_reversal':
            return ['debit' => 'write_down_allowance', 'credit' => 'write_down_reversal'];
        case 'warehouse_transfer':
        case 'departmental_transfer':
            return null; // no GL voucher (spec section E exception)
        default:
            return null;
    }
}

/**
 * Post the balanced GL voucher for one stock movement, per the posting matrix.
 * Returns the voucher id, 0 when the movement has no GL impact, or throws when a
 * required mapping is missing (so the caller can record stock-only + surface the
 * gap, satisfying "no voucher posts without complete mapping").
 *
 * Idempotent via vouchers UNIQUE(source_type, source_id) = ('inventory_movement',
 * $txnId). Runs inside the caller's DB transaction.
 *
 * @throws RuntimeException listing the missing purposes.
 */
function inv_post_movement_voucher(int $companyId, ?int $fiscalYearId, int $txnId, string $type, array $item, string $direction, float $value, string $date, int $userId, ?int $partyId = null): int
{
    $plan = inv_movement_posting_plan($type, $direction);
    if ($plan === null) {
        return 0; // departmental/warehouse transfer — stock only
    }
    $value = inv_round_money($value);
    if ($value <= 0) {
        return 0;
    }

    $itemId = (int) $item['id'];
    $category = $item['category'] ?? null;
    $debit = inv_resolve_mapping($companyId, $plan['debit'], $itemId, $category);
    $credit = inv_resolve_mapping($companyId, $plan['credit'], $itemId, $category);

    $missing = [];
    if (!$debit) {
        $missing[] = $plan['debit'];
    }
    if (!$credit) {
        $missing[] = $plan['credit'];
    }
    if ($missing !== []) {
        throw new RuntimeException('MAP_MISSING:' . implode(',', $missing));
    }

    $voucherNo = 'INV-' . strtoupper($type) . '-' . str_pad((string) $txnId, 6, '0', STR_PAD_LEFT);
    return (int) create_voucher_with_entries([
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId ?: null,
        'voucher_no' => $voucherNo,
        'voucher_type' => 'journal',
        'voucher_date' => $date,
        'source_type' => 'inventory_movement',
        'source_id' => $txnId,
        'party_id' => $partyId,
        'total_amount' => $value,
        'narration' => ucfirst(str_replace('_', ' ', $type)) . ' — ' . ($item['sku'] ?? '') . ' ' . ($item['name'] ?? ''),
        'status' => 'posted',
        'posted_by' => $userId,
    ], [
        ['ledger_id' => (int) $debit['id'], 'entry_type' => 'debit', 'amount' => $value],
        ['ledger_id' => (int) $credit['id'], 'entry_type' => 'credit', 'amount' => $value],
    ]);
}

/**
 * The purposes each transaction type needs mapped before it can post.
 * Direction is derived by the engine; this table also documents the posting
 * matrix (Dr/Cr) used by the posting layer. Kept in exact sync with
 * inv_movement_posting_plan() — 'adjustment' is a single type with a
 * caller-supplied direction (matching the real form/handler), not separate
 * adjustment_increase/adjustment_decrease types.
 */
function inv_transaction_purposes(string $transactionType): array
{
    return match ($transactionType) {
        'opening'                 => ['inventory_asset', 'opening_equity'],
        'purchase', 'purchase_receipt' => ['inventory_asset', 'purchase_clearing'],
        'purchase_return'         => ['inventory_asset', 'purchase_clearing'],
        'sale', 'sales_delivery'  => ['inventory_asset', 'cogs'],
        'sales_return'            => ['inventory_asset', 'cogs'],
        'adjustment'              => ['inventory_asset', 'inventory_gain', 'inventory_loss'],
        'write_off', 'damage', 'expiry' => ['inventory_asset', 'inventory_loss'],
        'material_issue'          => ['wip', 'raw_material'],
        'material_return'         => ['raw_material', 'wip'],
        'production_receipt'      => ['finished_goods', 'wip'],
        'scrap_receipt'           => ['scrap_inventory', 'wip'],
        'nrv_write_down'          => ['write_down_expense', 'write_down_allowance'],
        'nrv_reversal'            => ['write_down_allowance', 'write_down_reversal'],
        'warehouse_transfer', 'departmental_transfer' => [], // no GL impact
        default                   => ['inventory_asset'],
    };
}
