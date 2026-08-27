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
/**
 * The unit cost of an item's MASTER opening stock. Openings are qty + AMOUNT
 * (frozen, like the accounting opening balances) — never qty x the CURRENT
 * purchase rate, which would silently re-value history when the rate changes.
 * Legacy rows without an amount fall back to the purchase rate once.
 */
function inv_item_opening_unit_cost(array $item): float
{
    $qty = (float) ($item['opening_qty'] ?? 0);
    if ($qty <= INV_EPSILON) {
        return 0.0;
    }
    $amount = (float) ($item['opening_amount'] ?? 0);
    if ($amount > 0.004) {
        return inv_round_cost($amount / $qty);
    }
    return (float) ($item['purchase_rate'] ?? 0);
}

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

    // Moving average: an issue leaves every REMAINING unit valued at the same
    // average cost. The layers previously kept their historical unit costs, so
    // remaining subledger value != (balance value - issue value) and cumulative
    // COGS could exceed everything ever debited to inventory (GL going negative
    // with stock at zero). Re-costing the survivors keeps subledger == GL.
    if ($issueUnitCost !== null) {
        db()->prepare(
            'UPDATE inventory_cost_layers SET unit_cost = :cost
             WHERE company_id = :cid AND item_id = :iid AND qty_remaining > 0.00005'
        )->execute([
            'cost' => inv_round_cost($issueUnitCost),
            'cid' => $companyId,
            'iid' => $itemId,
        ]);
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
/**
 * Every ledger mapping a company has, read once per request.
 *
 * A company holds a few dozen of these at most, and inv_resolve_mapping() is
 * asked about them once per item per purpose — so reading them per question
 * meant thousands of round trips on a page that priced a whole shop. The Stock
 * Summary report alone was spending 13,200 statements here, three for every
 * one of 4,400 questions, to read the same handful of rows over and over.
 *
 * Keyed by scope so the resolution order below is a lookup rather than a query.
 */
function inv_mapping_table(int $companyId): array
{
    static $cache = [];
    // -1 means no hold is open, so nothing is remembered and every read is
    // fresh — the behaviour this function had before the cache existed.
    $generation = inv_mapping_generation();
    $key = $generation . ':' . $companyId;
    if ($generation >= 0 && isset($cache[$key])) {
        return $cache[$key];
    }
    $table = ['item' => [], 'category' => [], 'global' => []];
    if (!table_exists('inventory_ledger_mappings')) {
        $cache[$key] = $table;

        return $table;
    }
    $stmt = db()->prepare('SELECT scope, purpose, item_id, category, ledger_id
        FROM inventory_ledger_mappings WHERE company_id = :cid');
    $stmt->execute(['cid' => $companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ledgerId = (int) $row['ledger_id'];
        $purpose = (string) $row['purpose'];
        switch ((string) $row['scope']) {
            case 'item':
                $table['item'][(int) $row['item_id'] . '|' . $purpose] = $ledgerId;
                break;
            case 'category':
                $table['category'][(string) $row['category'] . '|' . $purpose] = $ledgerId;
                break;
            default:
                $table['global'][$purpose] = $ledgerId;
        }
    }
    $cache[$key] = $table;

    return $table;
}

/**
 * One of this company's ledgers by id, or null when it is not this company's.
 *
 * Cached for the same reason as the mappings above: the resolver checks that
 * the mapped ledger really exists before it will hand it back, and that check
 * was another query per question.
 */
function inv_mapping_ledger(int $companyId, int $ledgerId): ?array
{
    static $cache = [];
    if ($ledgerId <= 0) {
        return null;
    }
    $generation = inv_mapping_generation();
    $key = $generation . ':' . $companyId . ':' . $ledgerId;
    if ($generation >= 0 && array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = db()->prepare('SELECT * FROM ledgers WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $ledgerId, 'cid' => $companyId]);
    $cache[$key] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    return $cache[$key];
}

/**
 * Hold the mappings still for the length of one bulk read.
 *
 * Caching these for a whole request would be quietly dangerous: this resolves
 * the ledger money posts to, and a remembered answer that outlived the mapping
 * it describes would post to an account somebody had just stopped using. The
 * table is written from a dozen places, several of them raw SQL, and a cache
 * that depends on every one of them remembering to invalidate it is a trap
 * waiting for the next writer.
 *
 * So the cache is OPT-IN and bounded. A caller that is about to resolve the
 * same handful of mappings thousands of times — pricing a whole shop, say —
 * opens a window it promises not to write inside, and closes it after. Outside
 * that window every resolution reads the database exactly as it always did.
 *
 *     inv_mapping_hold(true);
 *     try { ...thousands of resolutions... } finally { inv_mapping_hold(false); }
 */
function inv_mapping_hold(bool $open): void
{
    inv_mapping_generation($open ? 'open' : 'close');
}

/** Whether a hold is open, and the generation the caches key on. */
function inv_mapping_generation(string $action = 'read'): int
{
    static $generation = 0;
    static $held = false;
    if ($action === 'open') {
        $held = true;
    } elseif ($action === 'bump') {
        $generation++;
    } elseif ($action === 'close') {
        $held = false;
        // Bumped on the way out, so nothing survives the window it was read in.
        $generation++;
    }

    return $held ? $generation : -1;
}

/**
 * Invalidate immediately, for a writer that runs inside a hold.
 *
 * Bumps the generation WITHOUT touching whether a hold is open — a writer must
 * never accidentally open one, or the rest of the request would start caching
 * something nobody asked it to.
 */
function inv_mapping_forget(): void
{
    inv_mapping_generation('bump');
}

function inv_resolve_mapping(int $companyId, string $purpose, ?int $itemId = null, ?string $category = null): ?array
{
    if (!table_exists('inventory_ledger_mappings')) {
        return null;
    }
    $table = inv_mapping_table($companyId);

    // Item, then category, then global — and a scope whose mapped ledger has
    // since been deleted falls THROUGH to the next one rather than resolving to
    // nothing, exactly as it did when each scope was its own query.
    $candidates = [];
    if ($itemId) {
        $candidates[] = ['item', $table['item'][$itemId . '|' . $purpose] ?? 0];
    }
    if ($category !== null && $category !== '') {
        $candidates[] = ['category', $table['category'][$category . '|' . $purpose] ?? 0];
    }
    $candidates[] = ['global', $table['global'][$purpose] ?? 0];

    foreach ($candidates as [$source, $ledgerId]) {
        $row = inv_mapping_ledger($companyId, (int) $ledgerId);
        if ($row) {
            $row['mapping_source'] = $source;

            return $row;
        }
    }

    return null;
}

/**
 * The stock (balance-sheet) ledger id an item's inventory value posts to.
 *
 * Resolution order: the type-specific mapped purpose (finished_goods /
 * raw_material / scrap_inventory), then the generic inventory_asset mapping —
 * each walking item -> category -> global — and finally the legacy
 * inventory_items.ledger_id column so pre-mapping items keep posting where
 * they always did. Returns 0 when nothing resolves (caller records stock-only
 * and surfaces the gap; it must never guess a ledger).
 */
function inv_item_stock_ledger_id(int $companyId, array $item): int
{
    $typePurpose = match ((string) ($item['item_type'] ?? 'stock')) {
        'finished_good' => 'finished_goods',
        'raw_material' => 'raw_material',
        'scrap', 'by_product' => 'scrap_inventory',
        default => null,
    };
    $itemId = (int) ($item['id'] ?? 0);
    $category = ($item['category'] ?? null) !== '' ? ($item['category'] ?? null) : null;
    foreach (array_filter([$typePurpose, 'inventory_asset']) as $purpose) {
        $resolved = inv_resolve_mapping($companyId, $purpose, $itemId ?: null, $category);
        if ($resolved) {
            return (int) $resolved['id'];
        }
    }

    // THE LEGACY COLUMN IS NOT A LICENCE TO POST STOCK TO AN EXPENSE. It is
    // here so items that predate the mapping table keep posting where they
    // always did, and for that it has to be an asset — a stock account is.
    // Pointed at "Kitchen Purchase" instead, every purchase debits an expense,
    // the balance sheet shows no inventory, and the cost of goods nobody has
    // sold yet is already in the profit and loss. Returning 0 makes the caller
    // record the movement stock-only and SAY the ledger is missing, which is a
    // gap somebody can see and fix; posting to the wrong account is not.
    $legacy = (int) ($item['ledger_id'] ?? 0);
    if ($legacy <= 0) {
        return 0;
    }
    require_once __DIR__ . '/inventory_mapping.php';
    $nature = inv_ledger_nature($companyId, $legacy);

    return ($nature === '' || $nature === 'asset') ? $legacy : 0;
}

/**
 * Post (or replace, or clear) the GL voucher for an item's MASTER opening
 * stock — Dr the item's stock ledger / Cr Opening Balance Equity — so the
 * balance sheet carries the same opening value as the cost layers instead of
 * the subledger silently diverging from day one. One voucher per item
 * (source inventory_opening/<item id>), dated at the default fiscal year's
 * start, replaced when the opening qty/rate or ledger changes and deleted
 * when the opening is cleared; the usual mutation guards apply. Runs inside
 * the caller's transaction. Returns ['voucher_id' => int, 'note' => string]
 * — a non-empty note explains why nothing (or a stale voucher) is in the GL.
 */
function inv_post_item_opening_voucher(int $companyId, array $item, ?int $userId = null): array
{
    if (!table_exists('vouchers') || !table_exists('voucher_entries')) {
        return ['voucher_id' => 0, 'note' => ''];
    }
    $itemId = (int) ($item['id'] ?? 0);
    if ($itemId <= 0) {
        return ['voucher_id' => 0, 'note' => ''];
    }
    $value = inv_round_money((float) ($item['opening_qty'] ?? 0) * inv_item_opening_unit_cost($item));

    $existingStmt = db()->prepare("SELECT * FROM vouchers WHERE source_type = 'inventory_opening' AND source_id = :iid AND company_id = :cid LIMIT 1");
    $existingStmt->execute(['iid' => $itemId, 'cid' => $companyId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $debitLedgerId = inv_item_stock_ledger_id($companyId, $item);
    $creditRow = inv_resolve_mapping($companyId, 'opening_equity', $itemId, ($item['category'] ?? null) ?: null);
    $creditLedgerId = $creditRow ? (int) $creditRow['id'] : (function_exists('opening_balance_ledger_id') ? opening_balance_ledger_id($companyId) : 0);

    // Unchanged voucher: keep it (no id churn on every unrelated item save).
    if ($existing && $value > INV_EPSILON && abs((float) $existing['total_amount'] - $value) < 0.005) {
        $legStmt = db()->prepare("SELECT ledger_id FROM voucher_entries WHERE voucher_id = :vid AND entry_type = 'debit' LIMIT 1");
        $legStmt->execute(['vid' => (int) $existing['id']]);
        if ((int) $legStmt->fetchColumn() === $debitLedgerId) {
            return ['voucher_id' => (int) $existing['id'], 'note' => ''];
        }
    }
    if ($existing) {
        $blocker = voucher_mutation_blocker($existing, ['inventory_opening']);
        if ($blocker !== null) {
            return ['voucher_id' => (int) $existing['id'], 'note' => 'Opening-stock voucher NOT updated: ' . $blocker];
        }
        db()->prepare('DELETE FROM vouchers WHERE id = :id AND company_id = :cid')
            ->execute(['id' => (int) $existing['id'], 'cid' => $companyId]);
    }
    if ($value <= INV_EPSILON) {
        return ['voucher_id' => 0, 'note' => ''];
    }
    if ($debitLedgerId <= 0 || $creditLedgerId <= 0) {
        // The mapping store is shared with the Jewellery module, so name both
        // routes: a jewellery user never opens the core item panel.
        $missingLabel = $debitLedgerId <= 0 ? 'Inventory Asset' : 'Opening Balance Equity';

        return ['voucher_id' => 0, 'note' => 'Opening stock recorded WITHOUT a GL entry — the weight is saved but nothing reached the books. Map '
            . $missingLabel . ' first, either in the item\'s "This item posts to" panel on the Inventory page'
            . ' or under Jewellery → Settings → Posting Ledgers (they are the same setting), then save the opening again.'];
    }
    $defaultFy = function_exists('current_fiscal_year') ? current_fiscal_year() : null;
    $openingDate = (string) ($defaultFy['start_date'] ?? date('Y-m-d'));
    $voucherId = (int) create_voucher_with_entries([
        'company_id' => $companyId,
        'fiscal_year_id' => (int) ($defaultFy['id'] ?? 0) ?: null,
        'voucher_no' => 'INV-OPEN-' . $itemId,
        'voucher_type' => 'journal',
        'voucher_date' => $openingDate,
        'source_type' => 'inventory_opening',
        'source_id' => $itemId,
        'total_amount' => $value,
        'narration' => 'Opening stock — ' . ($item['sku'] ?? '') . ' ' . ($item['name'] ?? '') . ' (' . number_format((float) ($item['opening_qty'] ?? 0), 3) . ' @ ' . number_format(inv_item_opening_unit_cost($item), 2) . ')',
        'status' => 'posted',
        'posted_by' => $userId,
    ], [
        ['ledger_id' => $debitLedgerId, 'entry_type' => 'debit', 'amount' => $value, 'memo' => 'Opening stock'],
        ['ledger_id' => $creditLedgerId, 'entry_type' => 'credit', 'amount' => $value, 'memo' => 'Opening stock contra'],
    ]);
    return ['voucher_id' => $voucherId, 'note' => ''];
}


/**
 * The one journal the periodic system needs: closing stock.
 *
 * Through the year the ledger's stock account sits perfectly still. Purchases
 * go to Purchases, sales post no cost at all, and the stock account still holds
 * the figure it was brought forward with — last year's close, this year's open.
 * That is why a periodic trial balance shows opening stock and no closing
 * stock: the closing figure has not been written anywhere yet.
 *
 * This writes it. Stock is counted (here, valued off the stock subledger at the
 * period end) and the account is moved from the opening figure to the closing
 * one. The other side goes to Change in Inventory, which lands in the trading
 * account and completes the arithmetic:
 *
 *     Change in Inventory   = Closing - Opening
 *     Cost of sales         = Purchases - Change in Inventory
 *                           = Purchases - Closing + Opening
 *                           = Opening + Purchases - Closing
 *
 * So the derivation the profit and loss performs and the entry the balance
 * sheet needs are the same fact, entered once.
 *
 * Idempotent and keyed to the fiscal year, like the opening voucher it mirrors:
 * running it again after more stock movements replaces the entry rather than
 * adding a second one. Runs only under the periodic system — under perpetual
 * the stock account already equals the closing figure and this journal would be
 * a nought, which is worth refusing rather than posting.
 *
 * @return array{voucher_id:int, note:string, opening:float, closing:float, change:float}
 */
function inv_post_closing_stock_voucher(int $companyId, int $fiscalYearId, ?int $userId = null): array
{
    $none = ['voucher_id' => 0, 'note' => '', 'opening' => 0.0, 'closing' => 0.0, 'change' => 0.0];
    if (!table_exists('vouchers') || !table_exists('voucher_entries')) {
        return $none;
    }
    if (inv_accounting_method() !== 'periodic') {
        return array_replace($none, ['note' => 'These books are kept on the perpetual system, where the stock account is'
            . ' already at its closing figure. A closing-stock journal would post a nought.']);
    }

    $year = function_exists('fiscal_year_by_id') ? fiscal_year_by_id($fiscalYearId) : null;
    if (!$year || (int) ($year['company_id'] ?? 0) !== $companyId) {
        return array_replace($none, ['note' => 'That fiscal year does not belong to this company.']);
    }
    $periodEnd = (string) ($year['end_date'] ?? '');
    $periodStart = (string) ($year['start_date'] ?? '');
    if ($periodEnd === '') {
        return array_replace($none, ['note' => 'That fiscal year has no end date.']);
    }

    // What the stock is actually worth on the last day, from the subledger that
    // has been tracking quantity and cost all along.
    require_once __DIR__ . '/stock_report_engine.php';
    try {
        $summary = sr_stock_summary($companyId, [
            'from' => $periodStart, 'to' => $periodEnd,
            'dormant' => true, 'zero_movement' => true, 'zero_closing' => true,
        ]);
    } catch (Throwable $exception) {
        return array_replace($none, ['note' => 'Stock could not be valued for this period: ' . $exception->getMessage()]);
    }
    $closing = inv_round_money((float) ($summary['totals']['closing_amount'] ?? 0));

    $stockRow = inv_resolve_mapping($companyId, 'inventory_asset');
    $changeRow = inv_resolve_mapping($companyId, 'inventory_change');
    if (!$stockRow || !$changeRow) {
        $missing = !$stockRow ? 'Inventory Asset' : 'Change in Inventory (closing stock)';
        return array_replace($none, ['closing' => $closing, 'note' => 'No closing-stock journal was posted: map '
            . $missing . ' first, under Inventory → Ledger Mapping.']);
    }
    $stockLedgerId = (int) $stockRow['id'];
    $changeLedgerId = (int) $changeRow['id'];

    // What the stock account is carrying, ignoring any closing-stock journal
    // already posted for this year -- otherwise the second run would measure
    // the difference against its own previous answer and post nothing.
    $carriedStmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN ve.entry_type = 'debit' THEN ve.amount ELSE -ve.amount END), 0)
        FROM voucher_entries ve
        INNER JOIN vouchers v ON v.id = ve.voucher_id
        WHERE v.company_id = :cid AND ve.ledger_id = :lid
          AND v.voucher_date <= :end AND v.status = 'posted'
          -- COALESCE, not a bare comparison. NULL = 'x' is NULL, NULL AND y is
          -- NULL, and NOT NULL is still NULL -- which is not true, so every
          -- voucher with no source_type would be thrown out of the carried
          -- figure. Most journals somebody typed by hand have none, and the
          -- closing entry would then be measured against almost nothing.
          AND NOT (COALESCE(v.source_type, '') = 'inventory_closing' AND v.source_id = :fy)");
    $carriedStmt->execute(['cid' => $companyId, 'lid' => $stockLedgerId, 'end' => $periodEnd, 'fy' => $fiscalYearId]);
    $opening = inv_round_money((float) $carriedStmt->fetchColumn());
    $change = inv_round_money($closing - $opening);

    $existingStmt = db()->prepare("SELECT * FROM vouchers
        WHERE source_type = 'inventory_closing' AND source_id = :fy AND company_id = :cid LIMIT 1");
    $existingStmt->execute(['fy' => $fiscalYearId, 'cid' => $companyId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($existing) {
        $blocker = voucher_mutation_blocker($existing, ['inventory_closing']);
        if ($blocker !== null) {
            return ['voucher_id' => (int) $existing['id'], 'opening' => $opening, 'closing' => $closing,
                'change' => $change, 'note' => 'Closing-stock journal NOT updated: ' . $blocker];
        }
        db()->prepare('DELETE FROM vouchers WHERE id = :id AND company_id = :cid')
            ->execute(['id' => (int) $existing['id'], 'cid' => $companyId]);
    }

    // Opening and closing genuinely equal is a real answer, not a missing one:
    // a shop that bought exactly what it sold needs no entry.
    if (abs($change) < 0.005) {
        return ['voucher_id' => 0, 'opening' => $opening, 'closing' => $closing, 'change' => 0.0,
            'note' => 'Closing stock equals opening stock, so there is nothing to adjust.'];
    }

    $amount = abs($change);
    $stockIsDebit = $change > 0;   // stock grew: Dr Stock in Hand, Cr Change in Inventory
    $voucherId = (int) create_voucher_with_entries([
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId,
        'voucher_no' => 'INV-CLOSE-' . $fiscalYearId,
        'voucher_type' => 'journal',
        'voucher_date' => $periodEnd,
        'source_type' => 'inventory_closing',
        'source_id' => $fiscalYearId,
        'total_amount' => $amount,
        'narration' => 'Closing stock ' . number_format($closing, 2) . ' against opening '
            . number_format($opening, 2) . ' — ' . (string) ($year['label'] ?? $periodEnd),
        'status' => 'posted',
        'posted_by' => $userId,
    ], [
        ['ledger_id' => $stockIsDebit ? $stockLedgerId : $changeLedgerId, 'entry_type' => 'debit',
            'amount' => $amount, 'memo' => $stockIsDebit ? 'Closing stock' : 'Stock consumed'],
        ['ledger_id' => $stockIsDebit ? $changeLedgerId : $stockLedgerId, 'entry_type' => 'credit',
            'amount' => $amount, 'memo' => $stockIsDebit ? 'Closing stock' : 'Stock consumed'],
    ]);

    return ['voucher_id' => $voucherId, 'opening' => $opening, 'closing' => $closing,
        'change' => $change, 'note' => ''];
}

/**
 * The trading account read off the LEDGER, which is where it lives under the
 * periodic system.
 *
 * rc_trading_figures() answers the same question from the stock subledger, and
 * is what the perpetual books use because their ledger holds no purchases
 * figure to read. Here the ledger holds all of it, and reading the statement
 * off the accounts rather than off the stock records is the point of keeping
 * the books this way: the profit and loss and the trial balance cannot
 * disagree, because they are the same numbers.
 *
 * @return array{available:bool, opening:float, purchases:float, returns:float,
 *               closing:float, cogs:float}
 */
function inv_periodic_trading_figures(int $companyId, string $from, string $to): array
{
    $none = ['available' => false, 'opening' => 0.0, 'purchases' => 0.0, 'returns' => 0.0,
        'closing' => 0.0, 'cogs' => 0.0];
    if (inv_accounting_method() !== 'periodic' || !table_exists('voucher_entries')) {
        return $none;
    }

    /** Net movement on a mapped purpose over a window, debits positive. */
    $movement = static function (string $purpose, ?string $since, string $until) use ($companyId): float {
        $row = inv_resolve_mapping($companyId, $purpose);
        if (!$row) {
            return 0.0;
        }
        $sql = "SELECT COALESCE(SUM(CASE WHEN ve.entry_type = 'debit' THEN ve.amount ELSE -ve.amount END), 0)
            FROM voucher_entries ve
            INNER JOIN vouchers v ON v.id = ve.voucher_id
            WHERE v.company_id = :cid AND ve.ledger_id = :lid AND v.status = 'posted'
              AND v.voucher_date <= :until";
        $params = ['cid' => $companyId, 'lid' => (int) $row['id'], 'until' => $until];
        if ($since !== null) {
            $sql .= ' AND v.voucher_date >= :since';
            $params['since'] = $since;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return inv_round_money((float) $stmt->fetchColumn());
    };

    $purchases = $movement('purchases', $from, $to);
    $returns = -$movement('purchase_returns', $from, $to);   // a credit balance, shown positive
    $closing = $movement('inventory_asset', null, $to);

    // Opening is derived from the change, NOT read off the day before the
    // period starts. An opening-balance voucher in this app is dated at the
    // fiscal year's first day, not its eve, so "the balance the day before"
    // finds nothing at all and reports every opening stock as nought.
    //
    // The closing journal already states the relationship:
    //     Change in Inventory = Closing - Opening
    // so Opening = Closing - Change, using only movements inside the window and
    // caring nothing for which day the opening entry happens to carry.
    $change = -$movement('inventory_change', $from, $to);    // credit balance, shown positive
    $opening = inv_round_money($closing - $change);

    // Opening + Purchases - Returns - Closing, which reduces to the same thing:
    // Purchases - Returns - Change.
    $cogs = inv_round_money($purchases - $returns - $change);
    $touched = abs($opening) > 0.004 || abs($purchases) > 0.004
        || abs($returns) > 0.004 || abs($closing) > 0.004 || abs($change) > 0.004;

    return ['available' => $touched, 'opening' => $opening, 'purchases' => $purchases,
        'returns' => $returns, 'closing' => $closing, 'cogs' => $cogs];
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
    $stmt = db()->prepare('SELECT valuation_method, opening_qty, opening_amount, purchase_rate FROM inventory_items WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $itemId, 'cid' => $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    inv_rebuild_layers($companyId, $itemId, (string) ($row['valuation_method'] ?? 'weighted_average'), (float) ($row['opening_qty'] ?? 0), inv_item_opening_unit_cost($row));
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
        inv_rebuild_layers($companyId, $itemId, (string) ($item['valuation_method'] ?? 'weighted_average'), (float) ($item['opening_qty'] ?? 0), inv_item_opening_unit_cost($item));
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
 * The same valuation as inv_item_valuation(), for many items in three sweeps.
 *
 * Item by item that function costs four statements — two proving there is no
 * backfill to do, one for the cost layers, one for the NRV assessment — so a
 * page of fifty rows spent two hundred round trips to price them. The bulk
 * helpers it needs already existed for the KPI totals; this returns the per-row
 * figures a table needs instead of three sums.
 *
 * The arithmetic below is inv_item_valuation()'s, line for line, including the
 * legacy fallback to purchase_rate for an item that has no layers yet. If one
 * changes the other has to.
 *
 * @return array<int, array{qty: float, cost_value: float, unit_cost: float,
 *                          nrv_per_unit: float, lower_value: float, write_down: float}>
 */
function inv_item_valuations(int $companyId, array $items): array
{
    if ($items === []) {
        return [];
    }
    inv_ensure_layers_bulk($companyId, $items);
    $ids = array_map(static fn (array $item): int => (int) $item['id'], $items);
    $balances = inv_layer_balances($companyId, $ids);
    $assessments = inv_nrv_latest($companyId, $ids);

    $out = [];
    foreach ($items as $item) {
        $itemId = (int) $item['id'];
        $qty = $balances[$itemId]['qty'] ?? 0.0;
        $costValue = $balances[$itemId]['value'] ?? 0.0;
        if ($qty <= INV_EPSILON && isset($item['on_hand'])) {
            $qty = inv_round_qty((float) $item['on_hand']);
            $costValue = inv_round_money($qty * (float) ($item['purchase_rate'] ?? 0));
        }
        $unitCost = $qty > INV_EPSILON ? $costValue / $qty : 0.0;

        $sellingPrice = (float) ($item['sales_rate'] ?? 0);
        $completion = 0.0;
        $selling = 0.0;
        if (isset($assessments[$itemId])) {
            $sellingPrice = $assessments[$itemId]['selling_price'];
            $completion = $assessments[$itemId]['completion_cost'];
            $selling = $assessments[$itemId]['selling_cost'];
        }
        $nrvPerUnit = $sellingPrice > 0 ? ($sellingPrice - $completion - $selling) : $unitCost;
        $lowerValue = inv_round_money($qty * min($unitCost, $nrvPerUnit));

        $out[$itemId] = [
            'qty' => $qty,
            'cost_value' => inv_round_money($costValue),
            'unit_cost' => inv_round_cost($unitCost),
            'nrv_per_unit' => inv_round_cost($nrvPerUnit),
            'lower_value' => $lowerValue,
            'write_down' => inv_round_money(max(0.0, inv_round_money($costValue) - $lowerValue)),
        ];
    }

    return $out;
}

/**
 * Company-wide valuation totals across all active items (drives the KPI cards).
 * @return array{cost: float, lower: float, write_down: float}
 */
/**
 * Open cost layers for many items at once, summed per item.
 *
 * The same arithmetic inv_layer_balance() does one item at a time, and the same
 * "open" test (qty_remaining > 0.00005). Summed in SQL because the per-item
 * version reads every layer row into PHP only to add two columns up.
 *
 * @return array<int, array{qty: float, value: float}>
 */
function inv_layer_balances(int $companyId, array $itemIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
    if ($ids === [] || !table_exists('inventory_cost_layers')) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT item_id, SUM(qty_remaining) AS qty, SUM(qty_remaining * unit_cost) AS value
         FROM inventory_cost_layers
         WHERE company_id = :cid AND qty_remaining > 0.00005
           AND item_id IN (' . implode(',', $ids) . ')
         GROUP BY item_id'
    );
    $stmt->execute(['cid' => $companyId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['item_id']] = [
            'qty' => inv_round_qty((float) $row['qty']),
            'value' => inv_round_money((float) $row['value']),
        ];
    }

    return $out;
}

/**
 * The NRV assessment that stands for each of many items.
 *
 * Same rule as inv_item_valuation(): the newest by date then id, and only a
 * real assessment — an allowance RELEASE row carries no selling price, and a
 * reversed one no longer holds. Ordered so the first row seen per item is the
 * one that wins, which avoids a correlated subquery per item.
 *
 * @return array<int, array{selling_price: float, completion_cost: float, selling_cost: float}>
 */
function inv_nrv_latest(int $companyId, array $itemIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
    if ($ids === [] || !table_exists('inventory_nrv_assessments')) {
        return [];
    }
    $where = inv_nrv_assessment_columns_ready() ? " AND status = 'active' AND release_amount = 0" : '';
    $stmt = db()->prepare(
        'SELECT item_id, selling_price, completion_cost, selling_cost
         FROM inventory_nrv_assessments
         WHERE company_id = :cid AND item_id IN (' . implode(',', $ids) . ')' . $where . '
         ORDER BY item_id ASC, assessment_date DESC, id DESC'
    );
    $stmt->execute(['cid' => $companyId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $itemId = (int) $row['item_id'];
        if (isset($out[$itemId])) {
            continue; // the first row for an item is the newest
        }
        $out[$itemId] = [
            'selling_price' => (float) $row['selling_price'],
            'completion_cost' => (float) $row['completion_cost'],
            'selling_cost' => (float) $row['selling_cost'],
        ];
    }

    return $out;
}

/**
 * Build the cost layers of any item that has never had them, for a whole list.
 *
 * inv_ensure_layers() spends two queries per item establishing that there is
 * nothing to do, which for a settled book is every item every time. Both
 * questions are asked once here for the whole list, and the rebuild itself —
 * which really is per item, and really does write — runs only for the few that
 * need it, once in their life.
 */
function inv_ensure_layers_bulk(int $companyId, array $items): void
{
    if ($items === [] || !table_exists('inventory_cost_layers')) {
        return;
    }
    $byId = [];
    foreach ($items as $item) {
        $id = (int) ($item['id'] ?? 0);
        if ($id > 0) {
            $byId[$id] = $item;
        }
    }
    if ($byId === []) {
        return;
    }
    $in = implode(',', array_keys($byId));

    $have = db()->prepare('SELECT DISTINCT item_id FROM inventory_cost_layers
        WHERE company_id = :cid AND item_id IN (' . $in . ')');
    $have->execute(['cid' => $companyId]);
    foreach ($have->fetchAll(PDO::FETCH_COLUMN) as $id) {
        unset($byId[(int) $id]);
    }
    if ($byId === []) {
        return;
    }

    $moved = [];
    $txn = db()->prepare('SELECT DISTINCT item_id FROM inventory_transactions
        WHERE company_id = :cid AND item_id IN (' . implode(',', array_keys($byId)) . ')');
    $txn->execute(['cid' => $companyId]);
    foreach ($txn->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $moved[(int) $id] = true;
    }

    foreach ($byId as $id => $item) {
        $hasOpening = (float) ($item['opening_qty'] ?? 0) > INV_EPSILON;
        if (!$hasOpening && !isset($moved[$id])) {
            continue;
        }
        inv_rebuild_layers(
            $companyId,
            $id,
            (string) ($item['valuation_method'] ?? 'weighted_average'),
            (float) ($item['opening_qty'] ?? 0),
            inv_item_opening_unit_cost($item)
        );
    }
}

function inv_company_valuation(int $companyId, array $items): array
{
    // Three numbers for a card, and it used to cost four queries per item to
    // get them: two proving there was no backfill to do, one reading the cost
    // layers, one fetching the NRV assessment. A few thousand items is tens of
    // thousands of round trips before the page draws anything.
    //
    // Same arithmetic as inv_item_valuation(), item for item — including the
    // legacy fallback to purchase_rate when an item has no layers — read in
    // three sweeps instead of four per item.
    if ($items === []) {
        return ['cost' => 0.0, 'lower' => 0.0, 'write_down' => 0.0];
    }
    inv_ensure_layers_bulk($companyId, $items);
    $ids = array_map(static fn (array $item): int => (int) $item['id'], $items);
    $balances = inv_layer_balances($companyId, $ids);
    $assessments = inv_nrv_latest($companyId, $ids);

    $cost = 0.0;
    $lower = 0.0;
    $writeDown = 0.0;
    foreach ($items as $item) {
        $itemId = (int) $item['id'];
        $qty = $balances[$itemId]['qty'] ?? 0.0;
        $costValue = $balances[$itemId]['value'] ?? 0.0;
        if ($qty <= INV_EPSILON && isset($item['on_hand'])) {
            $qty = inv_round_qty((float) $item['on_hand']);
            $costValue = inv_round_money($qty * (float) ($item['purchase_rate'] ?? 0));
        }
        $unitCost = $qty > INV_EPSILON ? $costValue / $qty : 0.0;

        $sellingPrice = (float) ($item['sales_rate'] ?? 0);
        $completion = 0.0;
        $selling = 0.0;
        if (isset($assessments[$itemId])) {
            $sellingPrice = $assessments[$itemId]['selling_price'];
            $completion = $assessments[$itemId]['completion_cost'];
            $selling = $assessments[$itemId]['selling_cost'];
        }
        $nrvPerUnit = $sellingPrice > 0 ? ($sellingPrice - $completion - $selling) : $unitCost;
        $lowerValue = inv_round_money($qty * min($unitCost, $nrvPerUnit));

        $cost += inv_round_money($costValue);
        $lower += $lowerValue;
        $writeDown += inv_round_money(max(0.0, inv_round_money($costValue) - $lowerValue));
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
 * Which of the two recognised inventory systems the books are kept on.
 *
 *   perpetual  every movement hits the general ledger as it happens. Inventory
 *              is an asset that rises on purchase and falls on sale, and cost
 *              of sales is posted sale by sale, so the ledger always knows what
 *              stock is worth.
 *
 *   periodic   purchases go to a Purchases account in the trading account and
 *              stock is left alone until the year end, when it is counted and
 *              one closing-stock journal is passed. Cost of sales is not a
 *              ledger at all: it is Opening + Purchases - Closing, worked out
 *              when the statements are drawn.
 *
 * Both are accepted practice -- IAS 2 governs how inventory is MEASURED, not
 * which system records it -- and the difference shows on the face of the trial
 * balance. Under periodic a trial balance carries opening stock and purchases,
 * and carries neither closing stock nor cost of sales, because neither is a
 * ledger balance. That is the test of whether this is working.
 *
 * Global rather than per company: a group whose books are kept two different
 * ways cannot be consolidated without restating one of them first.
 */
function inv_accounting_method(): string
{
    return setting('inventory_accounting', 'perpetual') === 'periodic' ? 'periodic' : 'perpetual';
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
    if (inv_accounting_method() === 'periodic') {
        return inv_periodic_posting_plan($type);
    }

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
        case 'stock_count':
            // The difference between the shelf and the replay, when the shelf
            // is right because the outflow was never recorded item by item --
            // a kitchen's milk, a cafe's beans. That shortfall is what was
            // SOLD, so it is cost of sales, not shrinkage; a surplus is cost
            // of sales that never happened, so it comes back off it. Breakage
            // and theft still belong to 'adjustment' and its loss account.
            return $direction === 'in'
                ? ['debit' => 'inventory_asset', 'credit' => 'cogs']
                : ['debit' => 'cogs', 'credit' => 'inventory_asset'];
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
function inv_post_movement_voucher(int $companyId, ?int $fiscalYearId, int $txnId, string $type, array $item, string $direction, float $value, string $date, int $userId, ?int $partyId = null, array $extra = []): int
{
    // $extra carries what only a taxed purchase needs — vat, tds, the two
    // ledgers they post to, whether to prepare the voucher as a draft, and the
    // date it is meant to be posted on. Every existing caller passes nothing
    // and gets exactly the two-line posted voucher it always got.

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

    // Purchases with a supplier chosen post to THAT party's payable ledger,
    // not the shared clearing account — every purchase can owe a different
    // supplier. Whichever leg the plan routes through purchase_clearing is
    // the counterparty leg (credit on receipt, debit on return).
    if ($partyId && $partyId > 0) {
        $partyLedgerId = ensure_party_ledger($companyId, $partyId, 'payable');
        if ($partyLedgerId > 0) {
            $partyLedgerStmt = db()->prepare('SELECT * FROM ledgers WHERE id = :id AND company_id = :cid LIMIT 1');
            $partyLedgerStmt->execute(['id' => $partyLedgerId, 'cid' => $companyId]);
            $partyLedger = $partyLedgerStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($partyLedger) {
                if ($plan['credit'] === 'purchase_clearing') {
                    $credit = $partyLedger;
                } elseif ($plan['debit'] === 'purchase_clearing') {
                    $debit = $partyLedger;
                }
            }
        }
    }

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

    $vat = round(max(0.0, (float) ($extra['vat'] ?? 0)), 2);
    $tds = round(max(0.0, (float) ($extra['tds'] ?? 0)), 2);
    $isDraft = !empty($extra['draft']);

    // The stock leg is always the value put in, never the value plus tax: VAT
    // is recoverable, so capitalising it into stock would overstate the balance
    // sheet and every cost of sale struck off it afterwards.
    if ($vat > 0 || $tds > 0) {
        $built = purchase_entry_lines(
            $value, $vat, $tds,
            (int) $debit['id'], (int) $credit['id'],
            (int) ($extra['vat_ledger_id'] ?? 0), (int) ($extra['tds_ledger_id'] ?? 0)
        );
        $lines = $built['lines'];
        $total = $built['total'];
    } else {
        $lines = [
            ['ledger_id' => (int) $debit['id'], 'entry_type' => 'debit', 'amount' => $value],
            ['ledger_id' => (int) $credit['id'], 'entry_type' => 'credit', 'amount' => $value],
        ];
        $total = $value;
    }

    // A draft holds no number from the INV series — posting hands that out, so
    // a draft that is never posted leaves no gap in it.
    $voucherNo = $isDraft
        ? 'INV-DRAFT-' . str_pad((string) $txnId, 6, '0', STR_PAD_LEFT)
        : 'INV-' . strtoupper($type) . '-' . str_pad((string) $txnId, 6, '0', STR_PAD_LEFT);

    $voucherId = (int) create_voucher_with_entries([
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId ?: null,
        'voucher_no' => $voucherNo,
        'voucher_type' => $isDraft ? 'purchase' : 'journal',
        'voucher_date' => $date,
        'reference_no' => ($extra['reference_no'] ?? '') !== '' ? (string) $extra['reference_no'] : null,
        'source_type' => 'inventory_movement',
        'source_id' => $txnId,
        'party_id' => $partyId,
        'total_amount' => $total,
        'narration' => ucfirst(str_replace('_', ' ', $type)) . ' — ' . ($item['sku'] ?? '') . ' ' . ($item['name'] ?? ''),
        'status' => $isDraft ? 'draft' : 'posted',
        'posted_by' => $isDraft ? null : $userId,
    ], $lines);

    // posting_date is not one of the columns the shared writer knows, and a
    // draft has not been posted by anybody yet.
    if ($voucherId > 0 && (($extra['posting_date'] ?? '') !== '' || $isDraft)) {
        db()->prepare('UPDATE vouchers SET posting_date = :d' . ($isDraft ? ', posted_by = NULL, posted_at = NULL' : '') . ' WHERE id = :id')
            ->execute(['d' => ($extra['posting_date'] ?? '') !== '' ? (string) $extra['posting_date'] : $date, 'id' => $voucherId]);
    }

    return $voucherId;
}

/**
 * The periodic posting matrix: what a movement does to the general ledger when
 * the books are kept the trading-account way.
 *
 * The striking thing is how little there is. Only a purchase, and a purchase
 * going back, touch the ledger. Every other movement -- a sale, an issue to
 * production, a breakage, a transfer -- moves QUANTITY and leaves the ledger
 * alone, because under this system the ledger carries no running stock figure
 * to move. All of it is caught in one stroke at the year end, when stock is
 * counted and revalued and the difference lands in Change in Inventory.
 *
 * A sale posting no cost entry looks alarming the first time. It is correct:
 * cost of sales does not exist as a ledger here. It is Opening + Purchases -
 * Closing, worked out when the profit and loss is drawn -- which is exactly
 * why a periodic trial balance shows no cost of sales line. There is nothing
 * to show.
 *
 * Losses are not posted separately either. A breakage makes the closing count
 * smaller, which makes cost of sales bigger, which is where the loss lands.
 * Reporting it as its own line is the stock reports' job; giving it a ledger
 * entry as well would take it out of the trading account twice.
 */
function inv_periodic_posting_plan(string $type): ?array
{
    return match ($type) {
        // Opening stock IS a balance sheet asset at the start of the year --
        // last year's closing, brought forward. It is the one stock figure the
        // ledger carries, and it sits still until the year end.
        'opening' => ['debit' => 'inventory_asset', 'credit' => 'opening_equity'],
        'purchase', 'purchase_receipt' => ['debit' => 'purchases', 'credit' => 'purchase_clearing'],
        'purchase_return' => ['debit' => 'purchase_clearing', 'credit' => 'purchase_returns'],
        default => null,
    };
}

/** The purposes a movement needs mapped, under whichever system is in force. */
function inv_periodic_transaction_purposes(string $type): array
{
    return match ($type) {
        'opening' => ['inventory_asset', 'opening_equity'],
        'purchase', 'purchase_receipt' => ['purchases', 'purchase_clearing'],
        'purchase_return' => ['purchase_clearing', 'purchase_returns'],
        default => [],
    };
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
    if (inv_accounting_method() === 'periodic') {
        return inv_periodic_transaction_purposes($transactionType);
    }

    return match ($transactionType) {
        'opening'                 => ['inventory_asset', 'opening_equity'],
        'purchase', 'purchase_receipt' => ['inventory_asset', 'purchase_clearing'],
        'purchase_return'         => ['inventory_asset', 'purchase_clearing'],
        'sale', 'sales_delivery'  => ['inventory_asset', 'cogs'],
        'sales_return'            => ['inventory_asset', 'cogs'],
        'adjustment'              => ['inventory_asset', 'inventory_gain', 'inventory_loss'],
        'stock_count'             => ['inventory_asset', 'cogs'],
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

// --------------------------------------------------- shared movement helpers
//
// These sat on the Inventory & Manufacturing page until the multi-line purchase
// entry needed them as well. Nothing about them is page-specific.

/** Does this movement bring stock IN or send it OUT? */
function inventory_direction(string $type): string
{
    return in_array($type, ['opening', 'purchase', 'sales_return', 'produce'], true) ? 'in' : 'out';
}

/** A date typed as YYYY-MM-DD, or null when it is not one. */
function inventory_valid_date(string $value): ?string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    return ($parsed && $parsed->format('Y-m-d') === $value) ? $value : null;
}

/**
 * A warehouse id, but only if it belongs to this company.
 *
 * Returning null rather than the id is what stops a hand-edited form filing
 * this company's stock into somebody else's location.
 */
function inventory_company_warehouse_id(int $warehouseId, int $companyId): ?int
{
    if ($warehouseId <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT id FROM warehouses WHERE id = :id AND company_id = :company_id LIMIT 1');
    $stmt->execute(['id' => $warehouseId, 'company_id' => $companyId]);

    return ($stmt->fetchColumn() !== false) ? $warehouseId : null;
}
