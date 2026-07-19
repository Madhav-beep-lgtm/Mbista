<?php
declare(strict_types=1);

/**
 * Stock Summary Report engine.
 *
 * Read-only, historical, period-scoped valuation built by REPLAYING each
 * item's inventory_transactions through the same cost-flow rules the
 * perpetual engine uses (FIFO / moving weighted average; specific
 * identification falls back to FIFO order because movement rows carry no
 * unit identity). The perpetual inventory_cost_layers store is never
 * touched — "as of" numbers must not depend on today's layer state.
 *
 * Movement classification (company scope):
 *   inward  : opening, purchase, purchase_receipt, sales_return,
 *             production_receipt, produce, material_return, scrap_receipt,
 *             adjustment (qty_in)
 *   outward : sale, sales_delivery, purchase_return, consume,
 *             material_issue, adjustment (qty_out)
 *   damage  : write_off, damage, expiry
 *   location-only (warehouse_transfer, departmental_transfer): ignored at
 *   company level (stock never leaves the entity — mirrors
 *   inv_rebuild_layers); when a warehouse filter is active they become
 *   Transfer receipts / Transfer outward for THAT warehouse.
 *
 * Warehouse-scoped note: cost layers are company-level by design (transfers
 * do not re-cost stock), so warehouse rows value quantities at the item's
 * company-level replay cost — quantities are exact per warehouse, amounts
 * are that quantity at company carrying cost.
 *
 * Outward/damage amounts are always inventory COST from the replay, never
 * the row's (possibly selling) rate.
 */

require_once __DIR__ . '/inventory_valuation.php';

const SR_INWARD_TYPES = ['opening', 'purchase', 'purchase_receipt', 'sales_return', 'production_receipt', 'produce', 'material_return', 'scrap_receipt'];
const SR_OUTWARD_TYPES = ['sale', 'sales_delivery', 'purchase_return', 'consume', 'material_issue'];
const SR_DAMAGE_TYPES = ['write_off', 'damage', 'expiry'];
const SR_LOCATION_TYPES = ['warehouse_transfer', 'departmental_transfer'];

/** Report/UI labels for the item types the app stores. */
function sr_item_type_labels(): array
{
    return [
        'finished_good' => 'FG',
        'raw_material' => 'RM',
        'wip' => 'WIP',
        'consumable' => 'Consumables',
        'stock' => 'Stock',
        'scrap' => 'Scrap',
        'by_product' => 'By-product',
        'service' => 'Service',
    ];
}

/**
 * Location-specific item types for a company: [item_id][warehouse_id] => type.
 * The same item can be FG for the producing location and RM for the consuming
 * one; the master item_type is only the fallback.
 */
function sr_location_type_map(int $companyId): array
{
    if (!table_exists('inventory_item_location_types')) {
        return [];
    }
    $stmt = db()->prepare('SELECT item_id, warehouse_id, item_type FROM inventory_item_location_types
        WHERE company_id = :cid AND is_active = 1');
    $stmt->execute(['cid' => $companyId]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[(int) $r['item_id']][(int) $r['warehouse_id']] = (string) $r['item_type'];
    }
    return $map;
}

/** Resolve the display type for an item in a location (master type fallback). */
function sr_resolve_item_type(array $locationMap, int $itemId, ?int $warehouseId, string $masterType): string
{
    if ($warehouseId !== null && isset($locationMap[$itemId][$warehouseId])) {
        return $locationMap[$itemId][$warehouseId];
    }
    return $masterType;
}

/**
 * Pure replay state: ordered cost layers + method. WAvg keeps one pooled
 * layer; FIFO (and specific) walk oldest-first.
 */
function sr_replay_new(string $method): array
{
    return ['method' => $method === 'weighted_average' ? 'wavg' : 'fifo', 'layers' => []];
}

function sr_replay_in(array &$state, float $qty, float $unitCost): void
{
    if ($qty <= INV_EPSILON) {
        return;
    }
    if ($state['method'] === 'wavg' && $state['layers'] !== []) {
        $pool = &$state['layers'][0];
        $pool['value'] = inv_round_cost($pool['value'] + $qty * $unitCost);
        $pool['qty'] = inv_round_qty($pool['qty'] + $qty);
        unset($pool);
        return;
    }
    $state['layers'][] = ['qty' => inv_round_qty($qty), 'value' => inv_round_cost($qty * $unitCost)];
}

/** Draw $qty out at cost-flow cost; returns the cost (money-rounded). */
function sr_replay_out(array &$state, float $qty): float
{
    $remaining = $qty;
    $cost = 0.0;
    foreach ($state['layers'] as $i => &$layer) {
        if ($remaining <= INV_EPSILON) {
            break;
        }
        $take = min($layer['qty'], $remaining);
        if ($take <= INV_EPSILON) {
            continue;
        }
        $unit = $layer['qty'] > INV_EPSILON ? $layer['value'] / $layer['qty'] : 0.0;
        $cost += $take * $unit;
        $layer['qty'] = inv_round_qty($layer['qty'] - $take);
        $layer['value'] = inv_round_cost($layer['value'] - $take * $unit);
        $remaining -= $take;
    }
    unset($layer);
    $state['layers'] = array_values(array_filter($state['layers'], static fn (array $l): bool => $l['qty'] > INV_EPSILON));
    // Legacy negative stock: cost the uncovered part at the last known unit
    // cost of zero (quantities still reconcile; amounts never invent value).
    return inv_round_money($cost);
}

function sr_replay_balance(array $state): array
{
    $qty = 0.0;
    $value = 0.0;
    foreach ($state['layers'] as $layer) {
        $qty += $layer['qty'];
        $value += $layer['value'];
    }
    return ['qty' => inv_round_qty($qty), 'value' => inv_round_money($value)];
}

/**
 * The full Stock Summary dataset for one company and period.
 *
 * $f keys: from, to (Y-m-d, required), warehouse_ids (int[]), types
 * (string[] master/location types), valuation (''|fifo|weighted_average|
 * specific), search (code/name), stock_status (''|positive|zero|negative),
 * zero_movement (bool include), zero_closing (bool include),
 * group_by (''|type|location|valuation).
 *
 * Returns ['rows' => [...], 'totals' => [...], 'generated' => meta].
 * One query for items + one for transactions — no per-item queries.
 */
function sr_stock_summary(int $companyId, array $f): array
{
    $from = (string) $f['from'];
    $to = (string) $f['to'];
    $warehouseIds = array_values(array_filter(array_map('intval', (array) ($f['warehouse_ids'] ?? []))));
    $search = trim((string) ($f['search'] ?? ''));
    $valuation = (string) ($f['valuation'] ?? '');
    $typeFilter = array_values(array_filter((array) ($f['types'] ?? [])));
    $status = (string) ($f['stock_status'] ?? '');
    $includeZeroMovement = (bool) ($f['zero_movement'] ?? true);
    $includeZeroClosing = (bool) ($f['zero_closing'] ?? true);

    $itemSql = "SELECT id, sku, name, item_type, valuation_method, unit, purchase_rate, opening_qty, default_warehouse_id
        FROM inventory_items WHERE company_id = :cid AND item_type <> 'service'";
    $params = ['cid' => $companyId];
    if ($search !== '') {
        $itemSql .= ' AND (sku LIKE :q OR name LIKE :q2)';
        $params['q'] = '%' . $search . '%';
        $params['q2'] = '%' . $search . '%';
    }
    if ($valuation !== '') {
        $itemSql .= ' AND valuation_method = :vm';
        $params['vm'] = $valuation;
    }
    $itemSql .= ' ORDER BY sku ASC';
    $stmt = db()->prepare($itemSql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($items === []) {
        return ['rows' => [], 'totals' => sr_zero_totals(), 'item_count' => 0];
    }

    $itemIds = array_map(static fn (array $i): int => (int) $i['id'], $items);
    $ph = implode(',', array_fill(0, count($itemIds), '?'));
    $txnStmt = db()->prepare("SELECT item_id, transaction_type, transaction_date, warehouse_id, to_warehouse_id, qty_in, qty_out, rate
        FROM inventory_transactions
        WHERE company_id = ? AND item_id IN ($ph) AND transaction_date <= ?
        ORDER BY item_id, transaction_date ASC, id ASC");
    $txnStmt->execute(array_merge([$companyId], $itemIds, [$to]));
    $txnsByItem = [];
    foreach ($txnStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $txnsByItem[(int) $t['item_id']][] = $t;
    }

    $locationMap = sr_location_type_map($companyId);
    $warehouseFilterOn = $warehouseIds !== [];
    $singleWarehouse = count($warehouseIds) === 1 ? $warehouseIds[0] : null;

    $rows = [];
    $totals = sr_zero_totals();
    foreach ($items as $item) {
        $itemId = (int) $item['id'];
        $method = (string) $item['valuation_method'];
        $state = sr_replay_new($method);
        // Master opening seeds the layers before everything (like the
        // perpetual rebuild). It belongs to the default warehouse when a
        // warehouse lens is applied.
        $masterOpeningQty = (float) $item['opening_qty'];
        $masterInScope = !$warehouseFilterOn || in_array((int) ($item['default_warehouse_id'] ?? 0), $warehouseIds, true);
        if ($masterOpeningQty > INV_EPSILON) {
            sr_replay_in($state, $masterOpeningQty, (float) $item['purchase_rate']);
        }

        $scopedQty = $masterInScope ? $masterOpeningQty : 0.0; // qty in the warehouse lens
        $opening = ['qty' => 0.0, 'amount' => 0.0];
        $in = ['qty' => 0.0, 'amount' => 0.0];
        $out = ['qty' => 0.0, 'amount' => 0.0];
        $damage = ['qty' => 0.0, 'amount' => 0.0];
        $openingTaken = false;
        $avgUnitAt = static function (array $state): float {
            $b = sr_replay_balance($state);
            return $b['qty'] > INV_EPSILON ? $b['value'] / $b['qty'] : 0.0;
        };

        foreach ($txnsByItem[$itemId] ?? [] as $t) {
            $date = (string) $t['transaction_date'];
            if (!$openingTaken && $date >= $from) {
                $opening = sr_snapshot($state, $warehouseFilterOn, $scopedQty, $avgUnitAt($state));
                $openingTaken = true;
            }
            $type = (string) $t['transaction_type'];
            $qtyIn = (float) $t['qty_in'];
            $qtyOut = (float) $t['qty_out'];
            $rate = (float) $t['rate'];
            $inPeriod = $date >= $from && $date <= $to;
            $rowWarehouse = (int) ($t['warehouse_id'] ?? 0);
            $rowInScope = !$warehouseFilterOn || in_array($rowWarehouse, $warehouseIds, true);

            if (in_array($type, SR_LOCATION_TYPES, true)) {
                // Company stock unchanged; per-warehouse lens sees the legs at
                // carrying cost (the row's stamped informational rate).
                if ($warehouseFilterOn && $rowInScope) {
                    if ($qtyIn > INV_EPSILON) {
                        $scopedQty += $qtyIn;
                        if ($inPeriod) {
                            $in['qty'] += $qtyIn;
                            $in['amount'] += $qtyIn * $avgUnitAt($state);
                        }
                    } elseif ($qtyOut > INV_EPSILON) {
                        $scopedQty -= $qtyOut;
                        if ($inPeriod) {
                            $out['qty'] += $qtyOut;
                            $out['amount'] += $qtyOut * $avgUnitAt($state);
                        }
                    }
                }
                continue;
            }

            if ($qtyIn > INV_EPSILON) {
                sr_replay_in($state, $qtyIn, $rate);
                if ($rowInScope) {
                    $scopedQty += $qtyIn;
                    if ($inPeriod) {
                        $in['qty'] += $qtyIn;
                        $in['amount'] += $qtyIn * $rate;
                    }
                }
            } elseif ($qtyOut > INV_EPSILON) {
                $cost = sr_replay_out($state, $qtyOut);
                if ($rowInScope) {
                    $scopedQty -= $qtyOut;
                    if ($inPeriod) {
                        $bucket = in_array($type, SR_DAMAGE_TYPES, true) ? 'damage' : 'out';
                        if ($bucket === 'damage') {
                            $damage['qty'] += $qtyOut;
                            $damage['amount'] += $cost;
                        } else {
                            $out['qty'] += $qtyOut;
                            $out['amount'] += $cost;
                        }
                    }
                }
            }
        }
        if (!$openingTaken) {
            $opening = sr_snapshot($state, $warehouseFilterOn, $scopedQty, $avgUnitAt($state));
        }

        $closing = sr_snapshot($state, $warehouseFilterOn, $scopedQty, $avgUnitAt($state));

        $hasMovement = $in['qty'] > INV_EPSILON || $out['qty'] > INV_EPSILON || $damage['qty'] > INV_EPSILON;
        if (!$includeZeroMovement && !$hasMovement) {
            continue;
        }
        if (!$includeZeroClosing && abs($closing['qty']) <= INV_EPSILON) {
            continue;
        }
        if ($status === 'positive' && $closing['qty'] <= INV_EPSILON) {
            continue;
        }
        if ($status === 'zero' && abs($closing['qty']) > INV_EPSILON) {
            continue;
        }
        if ($status === 'negative' && $closing['qty'] >= -INV_EPSILON) {
            continue;
        }

        $displayType = sr_resolve_item_type($locationMap, $itemId, $singleWarehouse, (string) $item['item_type']);
        if ($typeFilter !== [] && !in_array($displayType, $typeFilter, true)) {
            continue;
        }

        // The GL stock ledger this item's value posts to — the wire between a
        // trial-balance inventory line and the items behind it.
        static $ledgerNameCache = null;
        if ($ledgerNameCache === null) {
            $lnStmt = db()->prepare('SELECT id, code, name FROM ledgers WHERE company_id = :cid');
            $lnStmt->execute(['cid' => $companyId]);
            $ledgerNameCache = [];
            foreach ($lnStmt->fetchAll(PDO::FETCH_ASSOC) as $ln) {
                $ledgerNameCache[(int) $ln['id']] = ['code' => (string) $ln['code'], 'name' => (string) $ln['name']];
            }
        }
        $stockLedgerId = inv_item_stock_ledger_id($companyId, $item);
        if ((int) ($f['ledger_id'] ?? 0) > 0 && $stockLedgerId !== (int) $f['ledger_id']) {
            continue;
        }

        $warehouseLabel = '';
        if ($singleWarehouse !== null) {
            static $whNames = null;
            if ($whNames === null) {
                $whStmt = db()->prepare('SELECT id, name FROM warehouses WHERE company_id = :cid');
                $whStmt->execute(['cid' => $companyId]);
                $whNames = $whStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            }
            $warehouseLabel = (string) ($whNames[$singleWarehouse] ?? '');
        } elseif ($warehouseFilterOn) {
            $warehouseLabel = count($warehouseIds) . ' locations';
        } else {
            $warehouseLabel = 'All locations';
        }

        $rows[] = [
            'item_id' => $itemId,
            'sku' => (string) $item['sku'],
            'name' => (string) $item['name'],
            'item_type' => $displayType,
            'item_type_label' => sr_item_type_labels()[$displayType] ?? ucfirst($displayType),
            'location' => $warehouseLabel,
            'unit' => (string) $item['unit'],
            'valuation_method' => $method,
            'ledger_id' => $stockLedgerId,
            'ledger_code' => $stockLedgerId > 0 ? ($ledgerNameCache[$stockLedgerId]['code'] ?? '') : '',
            'ledger_name' => $stockLedgerId > 0 ? ($ledgerNameCache[$stockLedgerId]['name'] ?? '') : 'not mapped',
            'opening_qty' => $opening['qty'],
            'opening_rate' => sr_rate($opening['amount'], $opening['qty']),
            'opening_amount' => inv_round_money($opening['amount']),
            'in_qty' => inv_round_qty($in['qty']),
            'in_rate' => sr_rate($in['amount'], $in['qty']),
            'in_amount' => inv_round_money($in['amount']),
            'out_qty' => inv_round_qty($out['qty']),
            'out_rate' => sr_rate($out['amount'], $out['qty']),
            'out_amount' => inv_round_money($out['amount']),
            'damage_qty' => inv_round_qty($damage['qty']),
            'damage_rate' => sr_rate($damage['amount'], $damage['qty']),
            'damage_amount' => inv_round_money($damage['amount']),
            'closing_qty' => $closing['qty'],
            'closing_rate' => sr_rate($closing['amount'], $closing['qty']),
            'closing_amount' => inv_round_money($closing['amount']),
        ];
        $totals['opening_amount'] += $opening['amount'];
        $totals['in_amount'] += $in['amount'];
        $totals['out_amount'] += $out['amount'];
        $totals['damage_amount'] += $damage['amount'];
        $totals['closing_amount'] += $closing['amount'];
    }

    foreach ($totals as $k => $v) {
        $totals[$k] = inv_round_money($v);
    }

    $groupBy = (string) ($f['group_by'] ?? '');
    if ($groupBy === 'type') {
        usort($rows, static fn (array $a, array $b): int => [$a['item_type'], $a['sku']] <=> [$b['item_type'], $b['sku']]);
    } elseif ($groupBy === 'valuation') {
        usort($rows, static fn (array $a, array $b): int => [$a['valuation_method'], $a['sku']] <=> [$b['valuation_method'], $b['sku']]);
    } elseif ($groupBy === 'ledger') {
        usort($rows, static fn (array $a, array $b): int => [$a['ledger_code'], $a['sku']] <=> [$b['ledger_code'], $b['sku']]);
    }

    return ['rows' => $rows, 'totals' => $totals, 'item_count' => count($rows)];
}

function sr_zero_totals(): array
{
    return ['opening_amount' => 0.0, 'in_amount' => 0.0, 'out_amount' => 0.0, 'damage_amount' => 0.0, 'closing_amount' => 0.0];
}

function sr_rate(float $amount, float $qty): float
{
    return $qty > INV_EPSILON ? round($amount / $qty, 2) : 0.0;
}

function sr_snapshot(array $state, bool $warehouseFilterOn, float $scopedQty, float $avgUnit): array
{
    if ($warehouseFilterOn) {
        return ['qty' => inv_round_qty($scopedQty), 'amount' => inv_round_money($scopedQty * $avgUnit)];
    }
    // Company scope: QUANTITY is the transactional tally (it goes negative on
    // legacy over-issues, which the report must show, not hide), while the
    // AMOUNT comes from the remaining cost layers (which bottom at zero —
    // valuation never invents negative rupees).
    $b = sr_replay_balance($state);
    return ['qty' => inv_round_qty($scopedQty), 'amount' => $b['value']];
}

/**
 * Per-transaction historical values for one item from a single replay:
 * [txn_id => ['type', 'direction', 'value', 'date']] where inward value is
 * qty x entry rate and outward value is the replayed cost-flow COST at that
 * point in the sequence — exactly what a retro-posted voucher must carry.
 */
function sr_txn_costs(int $companyId, array $item): array
{
    $stmt = db()->prepare('SELECT id, transaction_type, transaction_date, qty_in, qty_out, rate
        FROM inventory_transactions WHERE company_id = :cid AND item_id = :iid
        ORDER BY transaction_date ASC, id ASC');
    $stmt->execute(['cid' => $companyId, 'iid' => (int) $item['id']]);
    $state = sr_replay_new((string) ($item['valuation_method'] ?? 'weighted_average'));
    if ((float) ($item['opening_qty'] ?? 0) > INV_EPSILON) {
        sr_replay_in($state, (float) $item['opening_qty'], (float) ($item['purchase_rate'] ?? 0));
    }
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $type = (string) $t['transaction_type'];
        if (in_array($type, SR_LOCATION_TYPES, true)) {
            continue;
        }
        $qtyIn = (float) $t['qty_in'];
        $qtyOut = (float) $t['qty_out'];
        if ($qtyIn > INV_EPSILON) {
            sr_replay_in($state, $qtyIn, (float) $t['rate']);
            $out[(int) $t['id']] = ['type' => $type, 'direction' => 'in', 'value' => inv_round_money($qtyIn * (float) $t['rate']), 'date' => (string) $t['transaction_date']];
        } elseif ($qtyOut > INV_EPSILON) {
            $cost = sr_replay_out($state, $qtyOut);
            $out[(int) $t['id']] = ['type' => $type, 'direction' => 'out', 'value' => $cost, 'date' => (string) $t['transaction_date']];
        }
    }
    return $out;
}

/**
 * What is missing between the stock subledger and the GL, so the
 * reconciliation report can EXPLAIN its difference instead of just showing it:
 * unposted movements that have a posting plan (with their replayed values),
 * manufacturing rows whose order never posted, and items whose master opening
 * stock has no INV-OPEN voucher yet.
 */
function sr_unposted_summary(int $companyId): array
{
    $result = ['movements' => 0, 'movements_value' => 0.0, 'manufacturing' => 0, 'openings' => 0, 'openings_value' => 0.0];
    $stmt = db()->prepare('SELECT t.id, t.item_id, t.transaction_type, t.qty_in, t.qty_out
        FROM inventory_transactions t WHERE t.company_id = :cid AND t.voucher_id IS NULL');
    $stmt->execute(['cid' => $companyId]);
    $byItem = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $type = (string) $t['transaction_type'];
        if (in_array($type, SR_LOCATION_TYPES, true) || $type === 'opening') {
            continue;
        }
        $direction = (float) $t['qty_in'] > 0 ? 'in' : 'out';
        if (inv_movement_posting_plan($type, $direction) === null) {
            if (in_array($type, ['consume', 'produce'], true)) {
                $result['manufacturing']++;
            }
            continue;
        }
        $byItem[(int) $t['item_id']][] = (int) $t['id'];
    }
    if ($byItem !== []) {
        $ph = implode(',', array_fill(0, count($byItem), '?'));
        $items = db()->prepare("SELECT * FROM inventory_items WHERE company_id = ? AND id IN ($ph)");
        $items->execute(array_merge([$companyId], array_keys($byItem)));
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $costs = sr_txn_costs($companyId, $item);
            foreach ($byItem[(int) $item['id']] as $txnId) {
                if (isset($costs[$txnId])) {
                    $result['movements']++;
                    $result['movements_value'] += $costs[$txnId]['value'];
                }
            }
        }
    }
    $open = db()->prepare("SELECT COUNT(*), COALESCE(SUM(opening_qty * purchase_rate), 0) FROM inventory_items i
        WHERE i.company_id = :cid AND i.opening_qty > 0
          AND NOT EXISTS (SELECT 1 FROM vouchers v WHERE v.source_type = 'inventory_opening' AND v.source_id = i.id AND v.company_id = i.company_id)");
    $open->execute(['cid' => $companyId]);
    [$result['openings'], $result['openings_value']] = array_map('floatval', $open->fetch(PDO::FETCH_NUM) ?: [0, 0]);
    $result['openings'] = (int) $result['openings'];
    $result['movements_value'] = inv_round_money($result['movements_value']);
    $result['openings_value'] = inv_round_money($result['openings_value']);
    return $result;
}

/**
 * Retro-post the GL vouchers for stock movements recorded WITHOUT one
 * (mappings were missing at entry time, or legacy/seeded data). Each voucher
 * follows the normal posting matrix on the movement's own date, with outward
 * legs valued at the REPLAYED historical cost — never today's rate. Idempotent
 * (uniq inventory_movement/txn); rows that still cannot post (unmapped
 * ledgers, no fiscal year covers the date, locked period) are skipped WITH
 * the reason. Manufacturing consume/produce rows belong to their order's
 * production journal and are never posted here.
 * Returns ['posted'=>int, 'posted_value'=>float, 'skipped'=>[[txn,sku,reason]], 'manufacturing'=>int].
 */
function sr_post_missing_movement_vouchers(int $companyId, int $userId): array
{
    $result = ['posted' => 0, 'posted_value' => 0.0, 'skipped' => [], 'manufacturing' => 0];
    $stmt = db()->prepare('SELECT t.*, i.sku FROM inventory_transactions t
        JOIN inventory_items i ON i.id = t.item_id
        WHERE t.company_id = :cid AND t.voucher_id IS NULL
        ORDER BY t.item_id, t.transaction_date ASC, t.id ASC');
    $stmt->execute(['cid' => $companyId]);
    $txns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $itemCache = [];
    $costCache = [];
    foreach ($txns as $t) {
        $type = (string) $t['transaction_type'];
        if (in_array($type, SR_LOCATION_TYPES, true) || $type === 'opening') {
            continue;
        }
        if (in_array($type, ['consume', 'produce'], true)) {
            $result['manufacturing']++;
            continue;
        }
        $direction = (float) $t['qty_in'] > 0 ? 'in' : 'out';
        if (inv_movement_posting_plan($type, $direction) === null) {
            continue;
        }
        $itemId = (int) $t['item_id'];
        if (!isset($itemCache[$itemId])) {
            $itemStmt = db()->prepare('SELECT * FROM inventory_items WHERE id = :id AND company_id = :cid');
            $itemStmt->execute(['id' => $itemId, 'cid' => $companyId]);
            $itemCache[$itemId] = $itemStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $costCache[$itemId] = $itemCache[$itemId] ? sr_txn_costs($companyId, $itemCache[$itemId]) : [];
        }
        $item = $itemCache[$itemId];
        $txnId = (int) $t['id'];
        $value = (float) ($costCache[$itemId][$txnId]['value'] ?? 0);
        if (!$item || $value <= 0.004) {
            continue; // zero-value rows have no GL impact
        }
        try {
            $voucherId = inv_post_movement_voucher($companyId, (int) ($t['fiscal_year_id'] ?? 0) ?: null, $txnId, $type, $item, $direction, $value, (string) $t['transaction_date'], $userId);
            if ($voucherId > 0) {
                db()->prepare('UPDATE inventory_transactions SET voucher_id = :vid WHERE id = :id AND company_id = :cid')
                    ->execute(['vid' => $voucherId, 'id' => $txnId, 'cid' => $companyId]);
                $result['posted']++;
                $result['posted_value'] += $value;
            }
        } catch (RuntimeException $mapEx) {
            $reason = str_starts_with($mapEx->getMessage(), 'MAP_MISSING:')
                ? 'map ' . substr($mapEx->getMessage(), 12) . ' first'
                : $mapEx->getMessage();
            $result['skipped'][] = ['txn' => $txnId, 'sku' => (string) $t['sku'], 'reason' => $reason];
        } catch (Throwable $e) {
            $result['skipped'][] = ['txn' => $txnId, 'sku' => (string) $t['sku'], 'reason' => $e->getMessage()];
        }
    }
    $result['posted_value'] = inv_round_money($result['posted_value']);
    return $result;
}

/**
 * The GL ledgers that carry ITEM stock value (item links + global stock
 * mappings). 'wip' is deliberately excluded — the item subledger can never
 * carry in-process value, so WIP is compared alongside, never inside.
 */
function sr_inventory_ledger_ids(int $companyId): array
{
    $ledgerIds = [];
    $itemLedgers = db()->prepare('SELECT DISTINCT ledger_id FROM inventory_items WHERE company_id = :cid AND ledger_id IS NOT NULL');
    $itemLedgers->execute(['cid' => $companyId]);
    foreach ($itemLedgers->fetchAll(PDO::FETCH_COLUMN) as $lid) {
        $ledgerIds[(int) $lid] = true;
    }
    if (table_exists('inventory_ledger_mappings')) {
        $mp = db()->prepare("SELECT DISTINCT ledger_id FROM inventory_ledger_mappings WHERE company_id = :cid AND purpose IN ('inventory_asset','raw_material','finished_goods','scrap_inventory')");
        $mp->execute(['cid' => $companyId]);
        foreach ($mp->fetchAll(PDO::FETCH_COLUMN) as $lid) {
            $ledgerIds[(int) $lid] = true;
        }
        $wipStmt = db()->prepare("SELECT DISTINCT ledger_id FROM inventory_ledger_mappings WHERE company_id = :cid AND purpose = 'wip'");
        $wipStmt->execute(['cid' => $companyId]);
        foreach ($wipStmt->fetchAll(PDO::FETCH_COLUMN) as $lid) {
            unset($ledgerIds[(int) $lid]);
        }
    }
    return array_keys($ledgerIds);
}

/** Posted-GL balance of every inventory-designated ledger (item links + global stock mappings). */
function sr_inventory_gl_total(int $companyId, ?string $asAt = null): float
{
    $ledgerIds = array_fill_keys(sr_inventory_ledger_ids($companyId), true);
    if ($ledgerIds === []) {
        return 0.0;
    }
    $ph = implode(',', array_fill(0, count($ledgerIds), '?'));
    $q = db()->prepare("SELECT COALESCE(SUM(CASE WHEN ve.entry_type='debit' THEN ve.amount ELSE -ve.amount END),0)
        FROM voucher_entries ve JOIN vouchers v ON v.id = ve.voucher_id
        WHERE ve.ledger_id IN ($ph) AND v.status='posted' AND v.company_id = ?" . ($asAt !== null ? ' AND (v.voucher_date IS NULL OR v.voucher_date <= ?)' : ''));
    $q->execute(array_merge(array_keys($ledgerIds), [$companyId], $asAt !== null ? [$asAt] : []));
    return round((float) $q->fetchColumn(), 2);
}

/**
 * Retro-post production journals for manufacturing consume/produce rows that
 * never got one. Grouped by ref_no (the order number): Dr the finished item's
 * stock ledger at the produce txn value (what the stock subledger carries),
 * Cr each input's stock ledger at REPLAYED consume cost, and any conversion
 * difference goes to the mapped overhead/gain/loss ledger as a memo'd costing
 * variance — so the GL lands exactly where the stock subledger is. Anchored
 * source inventory_movement/<produce txn id> for idempotency.
 */
function sr_post_missing_production_journals(int $companyId, int $userId): array
{
    $result = ['posted' => 0, 'posted_value' => 0.0, 'skipped' => []];
    $stmt = db()->prepare("SELECT t.*, i.sku FROM inventory_transactions t
        JOIN inventory_items i ON i.id = t.item_id
        WHERE t.company_id = :cid AND t.voucher_id IS NULL AND t.transaction_type IN ('consume', 'produce')
        ORDER BY t.transaction_date ASC, t.id ASC");
    $stmt->execute(['cid' => $companyId]);
    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $groups[(string) ($t['ref_no'] ?? '') ?: ('txn-' . $t['id'])][] = $t;
    }
    foreach ($groups as $refNo => $rows) {
        $produceRows = array_values(array_filter($rows, static fn (array $r): bool => $r['transaction_type'] === 'produce'));
        if ($produceRows === []) {
            // Orphan consumption (its produce side was posted separately or the
            // order is still in progress): the materials HAVE left item stock,
            // so credit the stock ledger at replayed cost and debit Work in
            // Progress when mapped (value stays on the balance sheet until the
            // order completes), else Inventory Loss.
            foreach ($rows as $t) {
                $cItem = null;
                $cStmt = db()->prepare('SELECT * FROM inventory_items WHERE id = :id AND company_id = :cid');
                $cStmt->execute(['id' => (int) $t['item_id'], 'cid' => $companyId]);
                $cItem = $cStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                $cLedger = $cItem ? inv_item_stock_ledger_id($companyId, $cItem) : 0;
                $drRow = inv_resolve_mapping($companyId, 'wip') ?: inv_resolve_mapping($companyId, 'inventory_loss');
                if (!$cItem || $cLedger <= 0 || !$drRow) {
                    $result['skipped'][] = ['ref' => $refNo, 'reason' => 'orphan consumption needs the item stock ledger and a WIP or Inventory Loss mapping'];
                    continue;
                }
                $cCosts = sr_txn_costs($companyId, $cItem);
                $cost = round((float) ($cCosts[(int) $t['id']]['value'] ?? 0), 2);
                if ($cost <= 0.004) {
                    continue;
                }
                try {
                    $vid = (int) create_voucher_with_entries([
                        'company_id' => $companyId,
                        'fiscal_year_id' => (int) ($t['fiscal_year_id'] ?? 0) ?: null,
                        'voucher_no' => 'MFG-RETRO-' . (int) $t['id'],
                        'voucher_type' => 'journal',
                        'voucher_date' => (string) $t['transaction_date'],
                        'source_type' => 'inventory_movement',
                        'source_id' => (int) $t['id'],
                        'total_amount' => $cost,
                        'narration' => 'Retro: materials consumed into production (' . ($t['sku'] ?? '') . ') at replayed cost — orphan consume row.',
                        'status' => 'posted',
                        'posted_by' => $userId,
                    ], [
                        ['ledger_id' => (int) $drRow['id'], 'entry_type' => 'debit', 'amount' => $cost, 'memo' => 'Materials into production (retro)'],
                        ['ledger_id' => $cLedger, 'entry_type' => 'credit', 'amount' => $cost, 'memo' => 'Materials consumed at replayed cost (retro)'],
                    ]);
                    db()->prepare('UPDATE inventory_transactions SET voucher_id = :vid WHERE id = :id')->execute(['vid' => $vid, 'id' => (int) $t['id']]);
                    $result['posted']++;
                    $result['posted_value'] += $cost;
                } catch (Throwable $e) {
                    $result['skipped'][] = ['ref' => $refNo, 'reason' => $e->getMessage()];
                }
            }
            continue;
        }
        $itemCache = [];
        $loadItem = static function (int $itemId) use (&$itemCache, $companyId): ?array {
            if (!isset($itemCache[$itemId])) {
                $s = db()->prepare('SELECT * FROM inventory_items WHERE id = :id AND company_id = :cid');
                $s->execute(['id' => $itemId, 'cid' => $companyId]);
                $itemCache[$itemId] = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            return $itemCache[$itemId];
        };
        $entries = [];
        $fgTotal = 0.0;
        $inputTotal = 0.0;
        $bad = null;
        foreach ($produceRows as $p) {
            $fgItem = $loadItem((int) $p['item_id']);
            $fgLedger = $fgItem ? inv_item_stock_ledger_id($companyId, $fgItem) : 0;
            if ($fgLedger <= 0) {
                $bad = 'finished item ' . ($fgItem['sku'] ?? '#' . $p['item_id']) . ' has no stock ledger — map it first';
                break;
            }
            $value = round((float) $p['amount'] ?: ((float) $p['qty_in'] * (float) $p['rate']), 2);
            $entries[] = ['ledger_id' => $fgLedger, 'entry_type' => 'debit', 'amount' => $value, 'memo' => 'Finished goods received (retro)'];
            $fgTotal += $value;
        }
        foreach ($rows as $t) {
            if ($t['transaction_type'] !== 'consume' || $bad !== null) {
                continue;
            }
            $inItem = $loadItem((int) $t['item_id']);
            $inLedger = $inItem ? inv_item_stock_ledger_id($companyId, $inItem) : 0;
            if ($inLedger <= 0) {
                $bad = 'input item ' . ($inItem['sku'] ?? '#' . $t['item_id']) . ' has no stock ledger — map it first';
                break;
            }
            $costs = sr_txn_costs($companyId, $inItem);
            $cost = round((float) ($costs[(int) $t['id']]['value'] ?? 0), 2);
            if ($cost > 0.004) {
                $entries[] = ['ledger_id' => $inLedger, 'entry_type' => 'credit', 'amount' => $cost, 'memo' => 'Materials consumed at replayed cost (retro)'];
                $inputTotal += $cost;
            }
        }
        if ($bad !== null) {
            $result['skipped'][] = ['ref' => $refNo, 'reason' => $bad];
            continue;
        }
        $variance = round($fgTotal - $inputTotal, 2);
        if (abs($variance) > 0.004) {
            $vLedger = inv_resolve_mapping($companyId, 'overhead_absorbed')
                ?: inv_resolve_mapping($companyId, $variance > 0 ? 'inventory_gain' : 'inventory_loss');
            if (!$vLedger) {
                $result['skipped'][] = ['ref' => $refNo, 'reason' => 'conversion variance ' . number_format($variance, 2) . ' needs an Overhead Absorbed or Inventory Gain/Loss mapping'];
                continue;
            }
            $entries[] = ['ledger_id' => (int) $vLedger['id'], 'entry_type' => $variance > 0 ? 'credit' : 'debit', 'amount' => abs($variance), 'memo' => 'Conversion cost / costing variance (retro production journal)'];
        }
        $anchorTxn = (int) $produceRows[0]['id'];
        try {
            $voucherId = (int) create_voucher_with_entries([
                'company_id' => $companyId,
                'fiscal_year_id' => (int) ($produceRows[0]['fiscal_year_id'] ?? 0) ?: null,
                'voucher_no' => 'MFG-RETRO-' . $anchorTxn,
                'voucher_type' => 'journal',
                'voucher_date' => (string) $produceRows[0]['transaction_date'],
                'source_type' => 'inventory_movement',
                'source_id' => $anchorTxn,
                'total_amount' => round($fgTotal, 2),
                'narration' => 'Retro production journal ' . $refNo . ': finished goods at stock value, materials at replayed cost.',
                'status' => 'posted',
                'posted_by' => $userId,
            ], $entries);
            $ids = implode(',', array_map(static fn (array $r): int => (int) $r['id'], $rows));
            db()->exec("UPDATE inventory_transactions SET voucher_id = $voucherId WHERE id IN ($ids)");
            $result['posted']++;
            $result['posted_value'] += $fgTotal;
        } catch (Throwable $e) {
            $result['skipped'][] = ['ref' => $refNo, 'reason' => $e->getMessage()];
        }
    }
    $result['posted_value'] = inv_round_money($result['posted_value']);
    return $result;
}

/**
 * ONE action that makes the stock subledger and the inventory GL equal:
 *   1. post missing opening-stock vouchers (item master openings),
 *   2. post missing movement vouchers at replayed historical cost,
 *   3. post retro production journals for orphan consume/produce groups,
 *   4. optionally zero DIRECT ledger-opening vouchers on inventory ledgers
 *      (they duplicate the item-level openings posted in step 1).
 * Returns a step-by-step log plus before/after subledger-vs-GL totals.
 */
function sr_reconcile_stock_to_gl(int $companyId, int $userId, bool $zeroDirectOpenings = false): array
{
    // Whole-history comparison: total stock value vs total GL, no date cap —
    // equality must hold over everything ever recorded.
    $horizon = '2999-12-31';
    $summaryBefore = sr_stock_summary($companyId, ['from' => $horizon, 'to' => $horizon]);
    $log = ['before' => ['subledger' => (float) $summaryBefore['totals']['closing_amount'], 'gl' => sr_inventory_gl_total($companyId)]];

    // 1. Opening-stock vouchers.
    $openings = ['posted' => 0, 'value' => 0.0, 'notes' => []];
    $items = db()->prepare('SELECT * FROM inventory_items WHERE company_id = :cid AND opening_qty > 0');
    $items->execute(['cid' => $companyId]);
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $before = (int) db()->query('SELECT COALESCE((SELECT id FROM vouchers WHERE source_type=\'inventory_opening\' AND source_id=' . (int) $item['id'] . ' LIMIT 1),0)')->fetchColumn();
        $res = inv_post_item_opening_voucher($companyId, $item, $userId);
        if (($res['note'] ?? '') !== '') {
            $openings['notes'][] = $item['sku'] . ': ' . $res['note'];
        } elseif ((int) $res['voucher_id'] > 0 && (int) $res['voucher_id'] !== $before) {
            $openings['posted']++;
            $openings['value'] += (float) $item['opening_qty'] * (float) $item['purchase_rate'];
        }
    }
    $log['openings'] = $openings;

    // 2 + 3. Movements and production journals.
    $log['movements'] = sr_post_missing_movement_vouchers($companyId, $userId);
    $log['production'] = sr_post_missing_production_journals($companyId, $userId);

    // 4. Duplicate direct openings (explicit opt-in — deletes OB-L vouchers on
    //    inventory ledgers via the audited replace-with-zero path).
    $log['direct_openings'] = ['zeroed' => 0, 'value' => 0.0, 'notes' => []];
    if ($zeroDirectOpenings && function_exists('post_ledger_opening_balance')) {
        $ledgerIds = [];
        $itemLedgers = db()->prepare('SELECT DISTINCT ledger_id FROM inventory_items WHERE company_id = :cid AND ledger_id IS NOT NULL');
        $itemLedgers->execute(['cid' => $companyId]);
        foreach ($itemLedgers->fetchAll(PDO::FETCH_COLUMN) as $lid) {
            $ledgerIds[(int) $lid] = true;
        }
        if (table_exists('inventory_ledger_mappings')) {
            $mp = db()->prepare("SELECT DISTINCT ledger_id FROM inventory_ledger_mappings WHERE company_id = :cid AND purpose IN ('inventory_asset','raw_material','wip','finished_goods','scrap_inventory')");
            $mp->execute(['cid' => $companyId]);
            foreach ($mp->fetchAll(PDO::FETCH_COLUMN) as $lid) {
                $ledgerIds[(int) $lid] = true;
            }
        }
        foreach (array_keys($ledgerIds) as $lid) {
            $ob = db()->prepare("SELECT v.id, v.total_amount FROM vouchers v WHERE v.company_id = :cid AND v.source_type = 'ledger_opening' AND v.source_id = :lid LIMIT 1");
            $ob->execute(['cid' => $companyId, 'lid' => $lid]);
            $obRow = $ob->fetch(PDO::FETCH_ASSOC);
            if (!$obRow) {
                continue;
            }
            $err = post_ledger_opening_balance($companyId, (int) $lid, 0.0, 'debit', $userId);
            if ($err === null) {
                $log['direct_openings']['zeroed']++;
                $log['direct_openings']['value'] += (float) $obRow['total_amount'];
            } else {
                $log['direct_openings']['notes'][] = 'ledger #' . $lid . ': ' . $err;
            }
        }
    }

    $summaryAfter = sr_stock_summary($companyId, ['from' => $horizon, 'to' => $horizon]);
    $log['after'] = ['subledger' => (float) $summaryAfter['totals']['closing_amount'], 'gl' => sr_inventory_gl_total($companyId)];
    $log['difference'] = round($log['after']['subledger'] - $log['after']['gl'], 2);
    $log['reconciled'] = abs($log['difference']) < 0.01;
    return $log;
}

/**
 * Purge SEEDED SAMPLE inventory data (items whose SKU starts with the sample
 * prefix) so the Stock Summary shows only stock genuinely recorded through
 * Inventory & Manufacturing. Everything an item touched goes together —
 * transactions, cost layers, location types, its stock vouchers (movement /
 * opening / retro / production) and sample manufacturing orders — so the GL
 * and the subledger stay consistent (both drop by the same rupees; nothing
 * strands in the books). Real (non-sample) data is never touched.
 * Returns counts of everything removed.
 */
function sr_purge_sample_inventory(int $companyId, int $userId, string $skuPrefix = 'SMP-'): array
{
    $out = ['items' => 0, 'transactions' => 0, 'vouchers' => 0, 'orders' => 0];
    $itemsStmt = db()->prepare('SELECT id, sku FROM inventory_items WHERE company_id = :cid AND sku LIKE :pre');
    $itemsStmt->execute(['cid' => $companyId, 'pre' => $skuPrefix . '%']);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($items === []) {
        return $out;
    }
    $itemIds = array_map(static fn (array $i): int => (int) $i['id'], $items);
    $ph = implode(',', array_fill(0, count($itemIds), '?'));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Vouchers linked to the items' movements, plus their opening vouchers.
        $voucherIds = [];
        $vq = $pdo->prepare("SELECT DISTINCT voucher_id FROM inventory_transactions WHERE company_id = ? AND item_id IN ($ph) AND voucher_id IS NOT NULL");
        $vq->execute(array_merge([$companyId], $itemIds));
        foreach ($vq->fetchAll(PDO::FETCH_COLUMN) as $vid) {
            $voucherIds[(int) $vid] = true;
        }
        $ovq = $pdo->prepare("SELECT id FROM vouchers WHERE company_id = ? AND source_type = 'inventory_opening' AND source_id IN ($ph)");
        $ovq->execute(array_merge([$companyId], $itemIds));
        foreach ($ovq->fetchAll(PDO::FETCH_COLUMN) as $vid) {
            $voucherIds[(int) $vid] = true;
        }
        // Sample manufacturing orders (same prefix) and their vouchers.
        if (table_exists('manufacturing_orders')) {
            $moq = $pdo->prepare('SELECT id, order_no FROM manufacturing_orders WHERE company_id = :cid AND order_no LIKE :pre');
            $moq->execute(['cid' => $companyId, 'pre' => $skuPrefix . '%']);
            foreach ($moq->fetchAll(PDO::FETCH_ASSOC) as $mo) {
                $mvq = $pdo->prepare("SELECT id FROM vouchers WHERE company_id = ? AND source_type IN ('manufacturing_order', 'manufacturing_order_start') AND source_id = ?");
                $mvq->execute([$companyId, (int) $mo['id']]);
                foreach ($mvq->fetchAll(PDO::FETCH_COLUMN) as $vid) {
                    $voucherIds[(int) $vid] = true;
                }
                $pdo->prepare('DELETE FROM manufacturing_order_inputs WHERE manufacturing_order_id = :id')->execute(['id' => (int) $mo['id']]);
                $pdo->prepare('DELETE FROM manufacturing_orders WHERE id = :id')->execute(['id' => (int) $mo['id']]);
                $out['orders']++;
            }
        }
        if ($voucherIds !== []) {
            $vph = implode(',', array_fill(0, count($voucherIds), '?'));
            $del = $pdo->prepare("DELETE FROM vouchers WHERE company_id = ? AND id IN ($vph)");
            $del->execute(array_merge([$companyId], array_keys($voucherIds)));
            $out['vouchers'] = $del->rowCount();
        }
        $txnDel = $pdo->prepare("DELETE FROM inventory_transactions WHERE company_id = ? AND item_id IN ($ph)");
        $txnDel->execute(array_merge([$companyId], $itemIds));
        $out['transactions'] = $txnDel->rowCount();
        $pdo->prepare("DELETE FROM inventory_cost_layers WHERE company_id = ? AND item_id IN ($ph)")->execute(array_merge([$companyId], $itemIds));
        if (table_exists('inventory_item_location_types')) {
            $pdo->prepare("DELETE FROM inventory_item_location_types WHERE company_id = ? AND item_id IN ($ph)")->execute(array_merge([$companyId], $itemIds));
        }
        if (table_exists('inventory_ledger_mappings')) {
            $pdo->prepare("DELETE FROM inventory_ledger_mappings WHERE company_id = ? AND scope = 'item' AND item_id IN ($ph)")->execute(array_merge([$companyId], $itemIds));
        }
        $itemDel = $pdo->prepare("DELETE FROM inventory_items WHERE company_id = ? AND id IN ($ph)");
        $itemDel->execute(array_merge([$companyId], $itemIds));
        $out['items'] = $itemDel->rowCount();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    if (function_exists('security_event')) {
        security_event('inventory_movement_reversed', 'warning',
            'Sample inventory purge (' . $skuPrefix . '*): ' . $out['items'] . ' items, ' . $out['transactions'] . ' movements, '
            . $out['vouchers'] . ' vouchers, ' . $out['orders'] . ' manufacturing orders removed.', $companyId, $userId);
    }
    return $out;
}

/**
 * Stock Ledger drill-down: every movement of one item up to $to with running
 * quantity/value/rate from the same replay. Rows before $from are collapsed
 * into the opening line.
 */
function sr_stock_ledger(int $companyId, int $itemId, string $from, string $to, array $warehouseIds = []): array
{
    $itemStmt = db()->prepare('SELECT * FROM inventory_items WHERE id = :id AND company_id = :cid LIMIT 1');
    $itemStmt->execute(['id' => $itemId, 'cid' => $companyId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        return ['item' => null, 'opening' => null, 'rows' => []];
    }
    $txnStmt = db()->prepare('SELECT t.*, v.voucher_no, v.status AS voucher_status, w.name AS warehouse_name, tw.name AS to_warehouse_name
        FROM inventory_transactions t
        LEFT JOIN vouchers v ON v.id = t.voucher_id
        LEFT JOIN warehouses w ON w.id = t.warehouse_id
        LEFT JOIN warehouses tw ON tw.id = t.to_warehouse_id
        WHERE t.company_id = :cid AND t.item_id = :iid AND t.transaction_date <= :to
        ORDER BY t.transaction_date ASC, t.id ASC');
    $txnStmt->execute(['cid' => $companyId, 'iid' => $itemId, 'to' => $to]);

    $state = sr_replay_new((string) $item['valuation_method']);
    if ((float) $item['opening_qty'] > INV_EPSILON) {
        sr_replay_in($state, (float) $item['opening_qty'], (float) $item['purchase_rate']);
    }
    $warehouseFilterOn = $warehouseIds !== [];
    $rows = [];
    $opening = null;
    foreach ($txnStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $date = (string) $t['transaction_date'];
        $type = (string) $t['transaction_type'];
        if ($opening === null && $date >= $from) {
            $b = sr_replay_balance($state);
            $opening = ['qty' => $b['qty'], 'value' => $b['value'], 'rate' => sr_rate($b['value'], $b['qty'])];
        }
        $isLocation = in_array($type, SR_LOCATION_TYPES, true);
        $qtyIn = (float) $t['qty_in'];
        $qtyOut = (float) $t['qty_out'];
        $cost = 0.0;
        if (!$isLocation) {
            if ($qtyIn > INV_EPSILON) {
                sr_replay_in($state, $qtyIn, (float) $t['rate']);
                $cost = inv_round_money($qtyIn * (float) $t['rate']);
            } elseif ($qtyOut > INV_EPSILON) {
                $cost = sr_replay_out($state, $qtyOut);
            }
        }
        if ($date < $from) {
            continue; // pre-period rows only feed the opening
        }
        if ($warehouseFilterOn && !in_array((int) ($t['warehouse_id'] ?? 0), $warehouseIds, true)) {
            continue;
        }
        $b = sr_replay_balance($state);
        $rows[] = [
            'date' => $date,
            'voucher_no' => (string) ($t['voucher_no'] ?? ''),
            'voucher_status' => (string) ($t['voucher_status'] ?? ''),
            'ref_no' => (string) ($t['ref_no'] ?? ''),
            'type' => $type,
            'warehouse' => (string) ($t['warehouse_name'] ?? ''),
            'to_warehouse' => (string) ($t['to_warehouse_name'] ?? ''),
            'in_qty' => $qtyIn > INV_EPSILON ? inv_round_qty($qtyIn) : 0.0,
            'in_rate' => $qtyIn > INV_EPSILON ? round((float) $t['rate'], 2) : 0.0,
            'in_amount' => $qtyIn > INV_EPSILON && !$isLocation ? $cost : 0.0,
            'out_qty' => $qtyOut > INV_EPSILON ? inv_round_qty($qtyOut) : 0.0,
            'out_rate' => $qtyOut > INV_EPSILON ? sr_rate($cost, $qtyOut) : 0.0,
            'out_amount' => $qtyOut > INV_EPSILON && !$isLocation ? $cost : 0.0,
            'is_location_only' => $isLocation,
            'running_qty' => $b['qty'],
            'running_rate' => sr_rate($b['value'], $b['qty']),
            'running_value' => $b['value'],
            'notes' => (string) ($t['notes'] ?? ''),
        ];
    }
    if ($opening === null) {
        $b = sr_replay_balance($state);
        $opening = ['qty' => $b['qty'], 'value' => $b['value'], 'rate' => sr_rate($b['value'], $b['qty'])];
    }
    return ['item' => $item, 'opening' => $opening, 'rows' => $rows];
}
