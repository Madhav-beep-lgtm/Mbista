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
