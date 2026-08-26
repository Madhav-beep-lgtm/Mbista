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
 *   stock_count: the difference a physical count found, in whichever
 *             direction the count went (qty_in inward, qty_out outward) —
 *             see app/stock_count.php
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
 * dormant (bool include — an item with no opening, no movement and no
 * closing is off by default; it is a catalogue entry, not stock),
 * group_by (''|type|location|valuation|ledger|stock_kind),
 * counts ([item_id => inventory_stock_counts row] — physically counted
 * closing quantities to show beside the replayed one; a counted row is never
 * hidden by the zero/dormant filters, because the whole reason it was counted
 * is that somebody wants to see it).
 *
 * Returns ['rows' => [...], 'totals' => [...], 'generated' => meta].
 * One query for items + one for transactions — no per-item queries.
 */
function sr_stock_summary(int $companyId, array $f): array
{
    // This prices every item the company has, and asks the same handful of
    // ledger mappings about each one — thirteen thousand statements on a
    // two-thousand-item shop, reading the same few rows over and over. The
    // mappings are held still for the length of the report, which writes none
    // of them, and released on the way out however that happens.
    inv_mapping_hold(true);
    try {
        return sr_stock_summary_build($companyId, $f);
    } finally {
        inv_mapping_hold(false);
    }
}

/** The report itself; sr_stock_summary() wraps it to hold the mappings still. */
function sr_stock_summary_build(int $companyId, array $f): array
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
    // An item with nothing in any column is a name in the catalogue, not
    // stock, and a hundred of them bury the dozen lines that matter. Off
    // unless asked for, because the question this report answers is what
    // the shop HAS and what MOVED.
    $includeDormant = (bool) ($f['dormant'] ?? false);
    // Physically counted closing quantities, already scoped to this report's
    // date and location by the caller. Passed in rather than fetched here so
    // the report engine keeps knowing nothing about the count store — and so
    // sc_system_closing() can ask it for the replayed figure without the two
    // calling each other in a circle.
    $counts = (array) ($f['counts'] ?? []);

    // A JEWELLER'S STOCK IS WEIGHT, NOT PIECES. Two rings are two pieces and
    // tell nobody anything; 27.54g of 22K gold is the figure the metal
    // register, the karigar's issue slip and the opening stock sheet are all
    // written in, and a summary that cannot be reconciled against them is a
    // summary of the wrong thing. The profile beside each item already carries
    // the metal, the purity and the per-piece weights — the same columns the
    // opening-stock template asks for — so the report reads them rather than
    // making the reader look each item up.
    $itemSql = "SELECT i.id, i.sku, i.name, i.item_type, i.valuation_method, i.unit, i.purchase_rate,
            i.opening_qty, i.opening_amount, i.default_warehouse_id, i.category,
            jp.stock_kind AS jewellery_stock_kind,
            jp.jewellery_type, jp.track_mode,
            jp.gross_weight AS jw_gross_each, jp.stone_weight AS jw_stone_each,
            jp.net_weight AS jw_net_each, jp.wastage_pct AS jw_wastage_pct,
            jp.making_charge_basis AS jw_making_basis, jp.making_charge_rate AS jw_making_rate,
            jp.stone_value AS jw_stone_value, jp.hallmark AS jw_hallmark, jp.design_no AS jw_design_no,
            jm.name AS jw_metal, jpu.name AS jw_purity, jpu.fineness AS jw_fineness
        FROM inventory_items i
        LEFT JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id AND jp.company_id = i.company_id
        LEFT JOIN jewellery_metals jm ON jm.id = jp.metal_id
        LEFT JOIN jewellery_purities jpu ON jpu.id = jp.purity_id
        WHERE i.company_id = :cid AND i.item_type <> 'service'";
    $params = ['cid' => $companyId];
    if ($search !== '') {
        $itemSql .= ' AND (i.sku LIKE :q OR i.name LIKE :q2)';
        $params['q'] = '%' . $search . '%';
        $params['q2'] = '%' . $search . '%';
    }
    if ($valuation !== '') {
        $itemSql .= ' AND i.valuation_method = :vm';
        $params['vm'] = $valuation;
    }
    $stockKind = (string) ($f['jewellery_stock_kind'] ?? '');
    if (in_array($stockKind, ['showroom', 'customer_ordered'], true)) {
        $itemSql .= ' AND jp.stock_kind = :stock_kind';
        $params['stock_kind'] = $stockKind;
    }
    $itemSql .= ' ORDER BY i.sku ASC';
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
            sr_replay_in($state, $masterOpeningQty, inv_item_opening_unit_cost($item));
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

        // What somebody physically counted on this date, in this scope. A
        // counted row survives every "hide the quiet rows" filter below: the
        // count IS the reason it is interesting, and hiding it would hide the
        // difference the count exists to show.
        $count = $counts[$itemId] ?? null;
        $counted = $count !== null ? inv_round_qty((float) $count['counted_qty']) : null;
        $countPosted = $count !== null && ($count['posted_at'] ?? null) !== null;
        $countVarianceQty = $counted !== null ? inv_round_qty($counted - $closing['qty']) : 0.0;
        $countVarianceAmount = $counted !== null
            ? inv_round_money($countVarianceQty * sr_rate($closing['amount'], $closing['qty']))
            : 0.0;

        $hasMovement = $in['qty'] > INV_EPSILON || $out['qty'] > INV_EPSILON || $damage['qty'] > INV_EPSILON;
        // Quantity AND value both, so a row carrying a balance worth money at
        // zero quantity — a rounding remnant, a write-down — is still shown.
        $isDormant = !$hasMovement
            && abs($opening['qty']) <= INV_EPSILON && abs($closing['qty']) <= INV_EPSILON
            && abs((float) $opening['amount']) < 0.005 && abs((float) $closing['amount']) < 0.005
            && abs((float) $in['amount']) < 0.005 && abs((float) $out['amount']) < 0.005
            && abs((float) $damage['amount']) < 0.005;
        if ($count === null) {
            if (!$includeDormant && $isDormant) {
                continue;
            }
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

        // Weight per stage is the per-piece profile weight times the pieces
        // that moved. It is the only honest derivation available: the movement
        // rows carry quantity, and for a jeweller a quantity is a count of
        // pieces whose weight is written on the item, not on the movement.
        // An item with no profile yields nothing rather than a nought, so a
        // mixed catalogue does not report a diamond ring as weighing zero.
        $hasProfile = ($item['jw_metal'] ?? null) !== null;
        $grossEach = (float) ($item['jw_gross_each'] ?? 0);
        $netEach = (float) ($item['jw_net_each'] ?? 0);
        $stoneEach = (float) ($item['jw_stone_each'] ?? 0);
        $weigh = static fn (float $qty, float $each): ?float => $hasProfile ? sr_weight($qty * $each) : null;

        $rows[] = [
            'item_id' => $itemId,
            'sku' => (string) $item['sku'],
            'name' => (string) $item['name'],
            // --- Jewellery profile, null on an item that has none ------------
            'jw' => $hasProfile,
            'jw_metal' => (string) ($item['jw_metal'] ?? ''),
            'jw_purity' => (string) ($item['jw_purity'] ?? ''),
            'jw_fineness' => $hasProfile ? (float) ($item['jw_fineness'] ?? 0) : null,
            'jw_type' => (string) ($item['jewellery_type'] ?? ''),
            'jw_track_mode' => (string) ($item['track_mode'] ?? ''),
            'jw_gross_each' => $hasProfile ? sr_weight($grossEach) : null,
            'jw_net_each' => $hasProfile ? sr_weight($netEach) : null,
            'jw_stone_each' => $hasProfile ? sr_weight($stoneEach) : null,
            'jw_making_basis' => (string) ($item['jw_making_basis'] ?? ''),
            'jw_making_rate' => $hasProfile ? (float) ($item['jw_making_rate'] ?? 0) : null,
            'jw_stone_value' => $hasProfile ? (float) ($item['jw_stone_value'] ?? 0) : null,
            'jw_hallmark' => (string) ($item['jw_hallmark'] ?? ''),
            'jw_design_no' => (string) ($item['jw_design_no'] ?? ''),
            'opening_gross' => $weigh((float) $opening['qty'], $grossEach),
            'opening_net' => $weigh((float) $opening['qty'], $netEach),
            'in_gross' => $weigh((float) $in['qty'], $grossEach),
            'in_net' => $weigh((float) $in['qty'], $netEach),
            'out_gross' => $weigh((float) $out['qty'], $grossEach),
            'out_net' => $weigh((float) $out['qty'], $netEach),
            'damage_gross' => $weigh((float) $damage['qty'], $grossEach),
            'damage_net' => $weigh((float) $damage['qty'], $netEach),
            'closing_gross' => $weigh((float) $closing['qty'], $grossEach),
            'closing_net' => $weigh((float) $closing['qty'], $netEach),
            'closing_stone' => $weigh((float) $closing['qty'], $stoneEach),
            // Fine metal: what the closing weight is worth once purity is
            // taken out of it. Two lots of 22K and 18K do not add up as
            // grams, and this is the column they add up in.
            'closing_fine' => $hasProfile && (float) ($item['jw_fineness'] ?? 0) > 0
                ? sr_weight((float) $closing['qty'] * $netEach * ((float) $item['jw_fineness'] / 1000))
                : null,
            'item_type' => $displayType,
            'item_type_label' => sr_item_type_labels()[$displayType] ?? ucfirst($displayType),
            'stock_group' => (string) ($item['category'] ?? ''),
            'jewellery_stock_kind' => (string) ($item['jewellery_stock_kind'] ?? ''),
            'jewellery_stock_kind_label' => match ((string) ($item['jewellery_stock_kind'] ?? '')) {
                'customer_ordered' => 'Customer Ordered',
                'showroom' => 'Showroom',
                default => '—',
            },
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
            // The physical count, beside the replay rather than instead of it.
            // Closing stays the figure the GL can be tied to; these say what
            // the shelf held and what the difference is worth, and once the
            // difference is posted the two are the same number.
            'count_id' => $count !== null ? (int) $count['id'] : 0,
            'counted_qty' => $counted,
            'count_posted' => $countPosted,
            'count_notes' => (string) ($count['notes'] ?? ''),
            'count_charge_to' => (string) ($count['charge_to'] ?? 'cogs'),
            'count_variance_qty' => $countVarianceQty,
            'count_variance_amount' => $countVarianceAmount,
            // Once a count is posted the live difference is zero by
            // construction — closing IS the counted figure. What was actually
            // charged is kept beside it, or the sheet would show a day's work
            // as a column of noughts.
            'count_posted_qty' => $countPosted ? (float) $count['variance_qty'] : 0.0,
            'count_posted_value' => $countPosted ? (float) $count['variance_value'] : 0.0,
        ];
        $totals['opening_amount'] += $opening['amount'];
        $totals['in_amount'] += $in['amount'];
        $totals['out_amount'] += $out['amount'];
        $totals['damage_amount'] += $damage['amount'];
        $totals['closing_amount'] += $closing['amount'];
        $totals['count_variance_amount'] += $countVarianceAmount;
        if ($hasProfile) {
            $totals['weighed_rows']++;
            $totals['opening_net'] += (float) $weigh((float) $opening['qty'], $netEach);
            $totals['in_net'] += (float) $weigh((float) $in['qty'], $netEach);
            $totals['out_net'] += (float) $weigh((float) $out['qty'], $netEach);
            $totals['damage_net'] += (float) $weigh((float) $damage['qty'], $netEach);
            $totals['closing_net'] += (float) $weigh((float) $closing['qty'], $netEach);
            $totals['closing_gross'] += (float) $weigh((float) $closing['qty'], $grossEach);
            $totals['closing_fine'] += (float) (end($rows)['closing_fine'] ?? 0);
        }
    }

    $weightKeys = ['opening_net', 'in_net', 'out_net', 'damage_net', 'closing_net', 'closing_gross', 'closing_fine'];
    foreach ($totals as $k => $v) {
        if ($k === 'weighed_rows') {
            continue;
        }
        $totals[$k] = in_array($k, $weightKeys, true) ? sr_weight((float) $v) : inv_round_money((float) $v);
    }

    $groupBy = (string) ($f['group_by'] ?? '');
    if ($groupBy === 'type') {
        usort($rows, static fn (array $a, array $b): int => [$a['item_type'], $a['sku']] <=> [$b['item_type'], $b['sku']]);
    } elseif ($groupBy === 'valuation') {
        usort($rows, static fn (array $a, array $b): int => [$a['valuation_method'], $a['sku']] <=> [$b['valuation_method'], $b['sku']]);
    } elseif ($groupBy === 'ledger') {
        usort($rows, static fn (array $a, array $b): int => [$a['ledger_code'], $a['sku']] <=> [$b['ledger_code'], $b['sku']]);
    } elseif ($groupBy === 'stock_kind') {
        usort($rows, static fn (array $a, array $b): int => [$a['jewellery_stock_kind'], $a['stock_group'], $a['sku']]
            <=> [$b['jewellery_stock_kind'], $b['stock_group'], $b['sku']]);
    }

    return ['rows' => $rows, 'totals' => $totals, 'item_count' => count($rows)];
}

function sr_zero_totals(): array
{
    return ['opening_amount' => 0.0, 'in_amount' => 0.0, 'out_amount' => 0.0, 'damage_amount' => 0.0,
        'closing_amount' => 0.0, 'count_variance_amount' => 0.0,
        // A jeweller foots the weight column, not only the money one.
        'opening_net' => 0.0, 'in_net' => 0.0, 'out_net' => 0.0, 'damage_net' => 0.0,
        'closing_net' => 0.0, 'closing_gross' => 0.0, 'closing_fine' => 0.0,
        // How many of the rows above actually carried a weight profile. Zero
        // means this is not a jeweller's stock and the weight columns are not
        // worth showing at all.
        'weighed_rows' => 0];
}

function sr_rate(float $amount, float $qty): float
{
    return $qty > INV_EPSILON ? round($amount / $qty, 2) : 0.0;
}

/**
 * A weight, to the four places the trade keeps them in.
 *
 * Four, not two: a 0.2650g stone rounded to 0.27 is off by enough to matter
 * once a hundred of them are added up, and the opening-stock sheet this report
 * is reconciled against carries four.
 */
function sr_weight(float $grams): float
{
    return round($grams, 4);
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
        sr_replay_in($state, (float) $item['opening_qty'], inv_item_opening_unit_cost($item));
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
    $jewelleryExclusion = column_exists('inventory_transactions', 'jewellery_stock_txn_id')
        ? ' AND t.jewellery_stock_txn_id IS NULL'
        : '';
    $stmt = db()->prepare('SELECT t.id, t.item_id, t.transaction_type, t.qty_in, t.qty_out
        FROM inventory_transactions t WHERE t.company_id = :cid AND t.voucher_id IS NULL'
        . $jewelleryExclusion);
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
    $open = db()->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN opening_amount > 0 THEN opening_amount ELSE opening_qty * purchase_rate END), 0) FROM inventory_items i
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
    $jewelleryExclusion = column_exists('inventory_transactions', 'jewellery_stock_txn_id')
        ? ' AND t.jewellery_stock_txn_id IS NULL'
        : '';
    $stmt = db()->prepare('SELECT t.*, i.sku FROM inventory_transactions t
        JOIN inventory_items i ON i.id = t.item_id
        WHERE t.company_id = :cid AND t.voucher_id IS NULL'
        . $jewelleryExclusion
        . ' ORDER BY t.item_id, t.transaction_date ASC, t.id ASC');
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
 * Explain an Inventory-to-GL difference instead of merely reporting it.
 *
 * Every cause carries a SIGNED impact in the same direction as the headline
 * comparison (stock subledger minus inventory GL):
 *   positive = stock is greater than GL; negative = GL is greater than stock.
 *
 * The diagnosis is read-only. It matches item openings and replay-valued stock
 * movements to the posted inventory effect of their vouchers, then identifies
 * every remaining GL-only voucher that touched a designated inventory ledger.
 * The unmatched remainder is reported explicitly; it is never hidden inside a
 * balancing recommendation.
 *
 * @return array{
 *   stock: float,
 *   gl: float,
 *   difference: float,
 *   explained: float,
 *   unexplained: float,
 *   ledger_rows: array<int,array<string,mixed>>,
 *   causes: array<int,array<string,mixed>>,
 *   controls: array<int,array<string,mixed>>,
 *   wip: float
 * }
 */
function sr_inventory_gl_diagnostics(int $companyId, string $asAt): array
{
    $asAt = trim($asAt) !== '' ? $asAt : date('Y-m-d');
    $summary = sr_stock_summary($companyId, ['from' => $asAt, 'to' => $asAt]);
    $stockTotal = round((float) ($summary['totals']['closing_amount'] ?? 0), 2);
    $glTotal = sr_inventory_gl_total($companyId, $asAt);
    $difference = round($stockTotal - $glTotal, 2);
    $ledgerIds = array_values(array_unique(array_filter(array_map('intval', sr_inventory_ledger_ids($companyId)))));

    $result = [
        'stock' => $stockTotal,
        'gl' => $glTotal,
        'difference' => $difference,
        'explained' => 0.0,
        'unexplained' => $difference,
        'ledger_rows' => [],
        'causes' => [],
        'controls' => [],
        'wip' => 0.0,
    ];

    $addCause = static function (
        array &$causes,
        string $type,
        string $reference,
        string $title,
        string $detail,
        string $recommendation,
        float $impact,
        string $severity = 'High'
    ): void {
        $causes[] = [
            'type' => $type,
            'reference' => $reference,
            'title' => $title,
            'detail' => $detail,
            'recommendation' => $recommendation,
            'impact' => round($impact, 2),
            'severity' => $severity,
        ];
    };
    $addControl = static function (
        array &$controls,
        string $reference,
        string $title,
        string $detail,
        string $recommendation,
        float $riskAmount = 0.0,
        string $severity = 'Review'
    ): void {
        $controls[] = [
            'reference' => $reference,
            'title' => $title,
            'detail' => $detail,
            'recommendation' => $recommendation,
            'risk_amount' => round($riskAmount, 2),
            'severity' => $severity,
        ];
    };

    $itemStmt = db()->prepare('SELECT * FROM inventory_items WHERE company_id = :cid ORDER BY id ASC');
    $itemStmt->execute(['cid' => $companyId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    // Allocate the item subledger to the same stock-control ledger resolver
    // used by posting, then compare every ledger separately.
    $subledgerByLedger = [];
    foreach ((array) ($summary['rows'] ?? []) as $row) {
        $ledgerId = (int) ($row['ledger_id'] ?? 0);
        $subledgerByLedger[$ledgerId] = ($subledgerByLedger[$ledgerId] ?? 0.0)
            + (float) ($row['closing_amount'] ?? 0);
        if ($ledgerId <= 0 && abs((float) ($row['closing_amount'] ?? 0)) >= 0.005) {
            $addControl(
                $result['controls'],
                (string) ($row['sku'] ?? ('Item #' . (int) ($row['item_id'] ?? 0))),
                'Item has stock value but no stock-control ledger',
                (string) ($row['name'] ?? 'Item') . ' carries ' . number_format((float) $row['closing_amount'], 2)
                    . ' in the item subledger, but the current mapping resolver returns no inventory ledger.',
                'Set the item/category/global inventory-asset mapping, then re-post only the affected source documents through their normal module.',
                (float) $row['closing_amount'],
                'High'
            );
        }
        if ((float) ($row['closing_qty'] ?? 0) < -INV_EPSILON) {
            $addControl(
                $result['controls'],
                (string) ($row['sku'] ?? ('Item #' . (int) ($row['item_id'] ?? 0))),
                'Negative stock quantity',
                (string) ($row['name'] ?? 'Item') . ' closes at ' . number_format((float) $row['closing_qty'], 3)
                    . ' ' . (string) ($row['unit'] ?? '') . '. Negative stock prevents complete historical cost allocation.',
                'Enter or correct the genuine earlier opening/purchase/receipt, or correct the outward movement date and quantity. Do not create a balancing journal.',
                abs((float) ($row['closing_amount'] ?? 0)),
                'High'
            );
        }
    }

    $voucherMeta = [];
    $voucherBySource = [];
    $voucherStmt = db()->prepare('SELECT id, voucher_no, voucher_date, status, source_type, source_id, narration
        FROM vouchers WHERE company_id = :cid');
    $voucherStmt->execute(['cid' => $companyId]);
    foreach ($voucherStmt->fetchAll(PDO::FETCH_ASSOC) as $voucher) {
        $voucherId = (int) $voucher['id'];
        $voucherMeta[$voucherId] = $voucher;
        $sourceType = (string) ($voucher['source_type'] ?? '');
        $sourceId = (int) ($voucher['source_id'] ?? 0);
        if ($sourceType !== '' && $sourceId > 0) {
            $voucherBySource[$sourceType . ':' . $sourceId] = $voucherId;
        }
    }

    $actualVoucherEffects = [];
    $glByLedger = [];
    $ledgerInfo = [];
    if ($ledgerIds !== []) {
        $placeholders = implode(',', array_fill(0, count($ledgerIds), '?'));
        $ledgerStmt = db()->prepare("SELECT l.id, l.code, l.name,
                COALESCE(SUM(CASE WHEN v.id IS NULL THEN 0 WHEN ve.entry_type = 'debit' THEN ve.amount ELSE -ve.amount END), 0) AS balance
            FROM ledgers l
            LEFT JOIN voucher_entries ve ON ve.ledger_id = l.id
            LEFT JOIN vouchers v ON v.id = ve.voucher_id
                AND v.company_id = l.company_id
                AND v.status = 'posted'
                AND (v.voucher_date IS NULL OR v.voucher_date <= ?)
            WHERE l.company_id = ? AND l.id IN ($placeholders)
            GROUP BY l.id ORDER BY l.code");
        $ledgerStmt->execute(array_merge([$asAt, $companyId], $ledgerIds));
        foreach ($ledgerStmt->fetchAll(PDO::FETCH_ASSOC) as $ledger) {
            $ledgerId = (int) $ledger['id'];
            $ledgerInfo[$ledgerId] = $ledger;
            $glByLedger[$ledgerId] = round((float) $ledger['balance'], 2);
        }

        $effectStmt = db()->prepare("SELECT v.id, v.voucher_no, v.voucher_date, v.source_type, v.source_id, v.narration,
                GROUP_CONCAT(DISTINCT CONCAT(l.code, ' - ', l.name) ORDER BY l.code SEPARATOR ', ') AS ledgers,
                COALESCE(SUM(CASE WHEN ve.entry_type = 'debit' THEN ve.amount ELSE -ve.amount END), 0) AS effect
            FROM vouchers v
            INNER JOIN voucher_entries ve ON ve.voucher_id = v.id
            INNER JOIN ledgers l ON l.id = ve.ledger_id
            WHERE v.company_id = ? AND v.status = 'posted'
              AND (v.voucher_date IS NULL OR v.voucher_date <= ?)
              AND ve.ledger_id IN ($placeholders)
            GROUP BY v.id ORDER BY v.voucher_date, v.id");
        $effectStmt->execute(array_merge([$companyId, $asAt], $ledgerIds));
        foreach ($effectStmt->fetchAll(PDO::FETCH_ASSOC) as $effect) {
            $effect['effect'] = round((float) $effect['effect'], 2);
            $actualVoucherEffects[(int) $effect['id']] = $effect;
        }
    }

    $allLedgerIds = array_values(array_unique(array_merge($ledgerIds, array_keys($subledgerByLedger))));
    sort($allLedgerIds);
    foreach ($allLedgerIds as $ledgerId) {
        $subledger = round((float) ($subledgerByLedger[$ledgerId] ?? 0), 2);
        $gl = round((float) ($glByLedger[$ledgerId] ?? 0), 2);
        $result['ledger_rows'][] = [
            'ledger_id' => $ledgerId,
            'code' => $ledgerId > 0 ? (string) ($ledgerInfo[$ledgerId]['code'] ?? ('#' . $ledgerId)) : 'UNMAPPED',
            'name' => $ledgerId > 0 ? (string) ($ledgerInfo[$ledgerId]['name'] ?? 'Inventory ledger') : 'Items without a resolved stock ledger',
            'subledger' => $subledger,
            'gl' => $gl,
            'difference' => round($subledger - $gl, 2),
        ];
    }

    // WIP remains informational: it belongs to in-process production, while
    // this reconciliation is deliberately the item-on-shelf subledger.
    if (table_exists('inventory_ledger_mappings')) {
        $wipStmt = db()->prepare("SELECT DISTINCT ledger_id FROM inventory_ledger_mappings
            WHERE company_id = :cid AND purpose = 'wip'");
        $wipStmt->execute(['cid' => $companyId]);
        $wipIds = array_values(array_unique(array_filter(array_map('intval', $wipStmt->fetchAll(PDO::FETCH_COLUMN)))));
        if ($wipIds !== []) {
            $wipPlaceholders = implode(',', array_fill(0, count($wipIds), '?'));
            $wipBalanceStmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN ve.entry_type = 'debit' THEN ve.amount ELSE -ve.amount END), 0)
                FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id
                WHERE ve.ledger_id IN ($wipPlaceholders) AND v.company_id = ? AND v.status = 'posted'
                  AND (v.voucher_date IS NULL OR v.voucher_date <= ?)");
            $wipBalanceStmt->execute(array_merge($wipIds, [$companyId, $asAt]));
            $result['wip'] = round((float) $wipBalanceStmt->fetchColumn(), 2);
        }
    }

    $expectedByVoucher = [];
    $matchedVoucherIds = [];
    $appendExpected = static function (
        array &$expectedByVoucher,
        int $voucherId,
        float $effect,
        string $reference,
        float $uncoveredQty = 0.0,
        float $storedAmount = 0.0
    ): void {
        if (!isset($expectedByVoucher[$voucherId])) {
            $expectedByVoucher[$voucherId] = [
                'effect' => 0.0,
                'references' => [],
                'uncovered_qty' => 0.0,
                'stored_amount' => 0.0,
            ];
        }
        $expectedByVoucher[$voucherId]['effect'] += $effect;
        $expectedByVoucher[$voucherId]['references'][] = $reference;
        $expectedByVoucher[$voucherId]['uncovered_qty'] += $uncoveredQty;
        $expectedByVoucher[$voucherId]['stored_amount'] += $storedAmount;
    };

    // Master openings are item-backed stock value. Their normal voucher must
    // debit the resolved stock ledger by the same frozen opening amount.
    foreach ($items as $item) {
        $openingQty = (float) ($item['opening_qty'] ?? 0);
        if ($openingQty <= INV_EPSILON) {
            continue;
        }
        $openingValue = round($openingQty * inv_item_opening_unit_cost($item), 2);
        $reference = (string) ($item['sku'] ?? ('Item #' . (int) $item['id'])) . ' opening';
        if ($openingValue <= 0.004) {
            $addControl(
                $result['controls'],
                (string) ($item['sku'] ?? ('Item #' . (int) $item['id'])),
                'Opening quantity has no opening value',
                number_format($openingQty, 3) . ' ' . (string) ($item['unit'] ?? '')
                    . ' opens with zero cost. Later outward movements cannot be fully valued from this layer.',
                'Enter the genuine frozen opening amount through Opening Balances and retain its supporting valuation schedule.',
                0.0,
                'High'
            );
        }
        $voucherId = (int) ($voucherBySource['inventory_opening:' . (int) $item['id']] ?? 0);
        if ($voucherId > 0) {
            $appendExpected($expectedByVoucher, $voucherId, $openingValue, $reference, 0.0, $openingValue);
            $matchedVoucherIds[$voucherId] = true;
        } elseif ($openingValue > 0.004) {
            $addCause(
                $result['causes'],
                'missing_opening_voucher',
                (string) ($item['sku'] ?? ('Item #' . (int) $item['id'])),
                'Item opening exists without its GL opening voucher',
                (string) ($item['name'] ?? 'Item') . ' carries opening stock of ' . number_format($openingQty, 3)
                    . ' ' . (string) ($item['unit'] ?? '') . ' valued at ' . number_format($openingValue, 2)
                    . ', but no inventory_opening voucher is linked to the item.',
                'Use Opening Balances > Post missing opening-stock vouchers after confirming the quantity and frozen value. Do not enter a separate ledger opening.',
                $openingValue
            );
        }
    }

    $jewelleryBridgeReady = table_exists('jewellery_stock_txns')
        && column_exists('inventory_transactions', 'jewellery_stock_txn_id');
    $jewellerySelect = $jewelleryBridgeReady
        ? ', jt.voucher_id AS jewellery_voucher_id, jt.txn_type AS jewellery_txn_type, jt.ref_no AS jewellery_ref_no'
        : ', NULL AS jewellery_voucher_id, NULL AS jewellery_txn_type, NULL AS jewellery_ref_no';
    $jewelleryJoin = $jewelleryBridgeReady
        ? ' LEFT JOIN jewellery_stock_txns jt ON jt.id = t.jewellery_stock_txn_id AND jt.company_id = t.company_id'
        : '';
    $transactionStmt = db()->prepare('SELECT t.*, i.sku, i.name, i.unit' . $jewellerySelect . '
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id AND i.company_id = t.company_id'
        . $jewelleryJoin . '
        WHERE t.company_id = :cid AND t.transaction_date <= :as_at
        ORDER BY t.item_id, t.transaction_date, t.id');
    $transactionStmt->execute(['cid' => $companyId, 'as_at' => $asAt]);
    $transactionsByItem = [];
    foreach ($transactionStmt->fetchAll(PDO::FETCH_ASSOC) as $transaction) {
        $transactionsByItem[(int) $transaction['item_id']][] = $transaction;
    }

    foreach ($items as $item) {
        $itemId = (int) $item['id'];
        $state = sr_replay_new((string) ($item['valuation_method'] ?? 'weighted_average'));
        if ((float) ($item['opening_qty'] ?? 0) > INV_EPSILON) {
            sr_replay_in($state, (float) $item['opening_qty'], inv_item_opening_unit_cost($item));
        }
        foreach ($transactionsByItem[$itemId] ?? [] as $transaction) {
            $type = (string) ($transaction['transaction_type'] ?? '');
            $qtyIn = (float) ($transaction['qty_in'] ?? 0);
            $qtyOut = (float) ($transaction['qty_out'] ?? 0);
            $expectedEffect = 0.0;
            $uncoveredQty = 0.0;
            if (!in_array($type, SR_LOCATION_TYPES, true)) {
                if ($qtyIn > INV_EPSILON) {
                    $value = round($qtyIn * (float) ($transaction['rate'] ?? 0), 2);
                    sr_replay_in($state, $qtyIn, (float) ($transaction['rate'] ?? 0));
                    $expectedEffect = $value;
                } elseif ($qtyOut > INV_EPSILON) {
                    $before = sr_replay_balance($state);
                    $uncoveredQty = max(0.0, round($qtyOut - (float) $before['qty'], 3));
                    $expectedEffect = -sr_replay_out($state, $qtyOut);
                }
            }

            $voucherId = (int) ($transaction['voucher_id'] ?? 0);
            if ($voucherId <= 0) {
                $voucherId = (int) ($transaction['jewellery_voucher_id'] ?? 0);
            }
            if ($voucherId <= 0) {
                $voucherId = (int) ($voucherBySource['inventory_movement:' . (int) $transaction['id']] ?? 0);
            }
            $reference = (string) ($transaction['sku'] ?? ('Item #' . $itemId))
                . ' txn #' . (int) $transaction['id']
                . ' (' . str_replace('_', ' ', $type) . ', ' . (string) $transaction['transaction_date'] . ')';
            $storedAmount = abs((float) ($transaction['amount'] ?? 0));

            if ($voucherId > 0) {
                $appendExpected(
                    $expectedByVoucher,
                    $voucherId,
                    $expectedEffect,
                    $reference,
                    $uncoveredQty,
                    $storedAmount
                );
                $matchedVoucherIds[$voucherId] = true;
            } elseif (abs($expectedEffect) >= 0.005) {
                $directionLabel = $expectedEffect > 0 ? 'inward' : 'outward';
                $addCause(
                    $result['causes'],
                    'movement_without_voucher',
                    (string) ($transaction['sku'] ?? ('Item #' . $itemId)) . ' / txn #' . (int) $transaction['id'],
                    'Stock movement has no GL voucher',
                    ucfirst($directionLabel) . ' movement dated ' . (string) $transaction['transaction_date']
                        . ' changes the item subledger by ' . number_format($expectedEffect, 2)
                        . ', but no voucher is linked to the movement.',
                    'Correct missing ledger mappings or a locked-period problem, then use Stock Summary > Reconcile to post the movement through the normal inventory source.',
                    $expectedEffect
                );
            }

            if ($uncoveredQty > INV_EPSILON) {
                $addControl(
                    $result['controls'],
                    (string) ($transaction['sku'] ?? ('Item #' . $itemId)) . ' / txn #' . (int) $transaction['id'],
                    'Outward movement exceeded available cost layers',
                    number_format($uncoveredQty, 3) . ' ' . (string) ($transaction['unit'] ?? '')
                        . ' of the movement dated ' . (string) $transaction['transaction_date']
                        . ' had no earlier opening/inward layer from which cost could be drawn.',
                    'Restore the genuine earlier opening/purchase/receipt or correct the movement date/quantity, rebuild the item cost layers, and rerun this report. Do not post the difference as an expense or journal.',
                    $storedAmount,
                    'High'
                );
            }
        }
    }

    // A matched voucher can still be wrong: wrong stock ledger, wrong amount,
    // draft/future status, or a full GL credit for an outward quantity that the
    // replay could only partly cost because stock had already gone negative.
    foreach ($expectedByVoucher as $voucherId => $expected) {
        $expectedEffect = round((float) $expected['effect'], 2);
        $actualEffect = round((float) ($actualVoucherEffects[$voucherId]['effect'] ?? 0), 2);
        $impact = round($expectedEffect - $actualEffect, 2);
        if (abs($impact) < 0.005) {
            continue;
        }
        $meta = $voucherMeta[$voucherId] ?? [];
        $voucherNo = (string) (($meta['voucher_no'] ?? '') ?: ('Voucher #' . $voucherId));
        $references = array_values(array_unique((array) $expected['references']));
        $referenceText = implode('; ', array_slice($references, 0, 4));
        if (count($references) > 4) {
            $referenceText .= '; +' . (count($references) - 4) . ' more';
        }
        $status = (string) ($meta['status'] ?? 'missing');
        $date = (string) ($meta['voucher_date'] ?? '');
        $uncoveredQty = (float) ($expected['uncovered_qty'] ?? 0);
        if ($uncoveredQty > INV_EPSILON) {
            $title = 'Voucher value exceeds the cost available to the stock replay';
            $detail = $voucherNo . ' (' . ($date !== '' ? $date : 'no date') . ') affects inventory GL by '
                . number_format($actualEffect, 2) . ', while the linked movements affect replayed stock by '
                . number_format($expectedEffect, 2) . '. ' . number_format($uncoveredQty, 3)
                . ' unit(s) had no earlier cost layer. Sources: ' . $referenceText . '.';
            $recommendation = 'Find the genuine missing/incorrect earlier opening, purchase or receipt and correct it in its source module. Rebuild cost layers and rerun. Never plug this difference with a manual journal.';
            $type = 'cost_layer_shortage';
        } elseif (abs($actualEffect) < 0.005) {
            $title = 'Linked voucher has no posted effect on the current inventory ledgers';
            $detail = $voucherNo . ' is ' . $status . ' and should represent ' . number_format($expectedEffect, 2)
                . ' of stock value for ' . $referenceText . ', but its posted effect on the currently designated inventory ledgers is zero.';
            $recommendation = 'Check voucher status/date and historical item/category ledger mapping. Correct or re-post through the originating module so the item movement and GL use the same stock-control ledger.';
            $type = 'voucher_without_inventory_effect';
        } else {
            $title = 'Stock movement value and linked voucher value differ';
            $detail = $voucherNo . ' (' . ($date !== '' ? $date : 'no date') . ') posts ' . number_format($actualEffect, 2)
                . ' to inventory GL, while replayed stock expects ' . number_format($expectedEffect, 2)
                . ' for ' . $referenceText . '.';
            $recommendation = 'Compare the source document quantity, historical cost/rate, voucher entries and ledger mapping. Correct the source transaction or reverse and re-post it with an audit trail.';
            $type = 'movement_voucher_value_mismatch';
        }
        $addCause(
            $result['causes'],
            $type,
            $voucherNo,
            $title,
            $detail,
            $recommendation,
            $impact
        );
    }

    // Anything still touching an inventory GL ledger has no matched item
    // opening or movement. That is the common direct-opening/manual-journal
    // cause which the previous report could only describe generically.
    foreach ($actualVoucherEffects as $voucherId => $actual) {
        if (isset($matchedVoucherIds[$voucherId])) {
            continue;
        }
        $actualEffect = round((float) ($actual['effect'] ?? 0), 2);
        if (abs($actualEffect) < 0.005) {
            continue;
        }
        $impact = -$actualEffect;
        $sourceType = (string) ($actual['source_type'] ?? '');
        $voucherNo = (string) (($actual['voucher_no'] ?? '') ?: ('Voucher #' . $voucherId));
        $date = (string) ($actual['voucher_date'] ?? '');
        $ledgerNames = (string) (($actual['ledgers'] ?? '') ?: 'inventory ledger');
        $direction = $actualEffect > 0 ? 'net debit' : 'net credit';
        if ($sourceType === 'ledger_opening') {
            $type = 'direct_ledger_opening';
            $title = 'Direct ledger opening is not backed by item opening stock';
            $recommendation = 'Confirm the physical item-wise opening first. Record it against the items, then remove or correct this direct ledger opening only through Opening Balances so stock is not counted twice.';
        } elseif ($sourceType === 'inventory_opening_adj') {
            $type = 'fiscal_opening_adjustment';
            $title = 'Fiscal-year opening adjustment is present only in the GL comparison';
            $recommendation = 'Compare the adjusted fiscal-year item opening with the carried closing quantity and value. Correct it through the Inventory Opening adjustment workflow, then regenerate/rebuild the carried opening.';
        } elseif (str_starts_with($sourceType, 'inventory_nrv')) {
            $type = 'nrv_posting_on_stock_ledger';
            $title = 'NRV allowance activity touches a cost-control inventory ledger';
            $recommendation = 'Review the NRV assessment and map write-downs to the separate allowance-for-inventory ledger. Do not change the item cost layers for an allowance-only adjustment.';
        } elseif (str_starts_with($sourceType, 'jewellery_')) {
            $type = 'unlinked_jewellery_voucher';
            $title = 'Jewellery stock voucher has no matched shared inventory movement';
            $recommendation = 'Repair the Jewellery-to-core source link for the existing movement. Do not create another stock row or duplicate voucher.';
        } elseif (str_starts_with($sourceType, 'inventory_')) {
            $type = 'orphan_inventory_voucher';
            $title = 'Inventory voucher is no longer linked to an item movement/opening';
            $recommendation = 'Inspect the source record and audit trail. Restore the valid link or reverse the orphan voucher through the Inventory module; do not delete posted history directly.';
        } else {
            $type = 'direct_inventory_journal';
            $title = 'Direct/manual voucher changed an inventory-control ledger';
            $recommendation = 'Verify the supporting item movement. If stock really moved, record it in Inventory and reverse/reclassify this direct entry; otherwise move it off the inventory-control ledger with an approved correcting voucher.';
        }
        $detail = $voucherNo . ' dated ' . ($date !== '' ? $date : 'without a date') . ' ('
            . ($sourceType !== '' ? $sourceType : 'manual/unspecified source') . ') posts a ' . $direction . ' of '
            . number_format(abs($actualEffect), 2) . ' to ' . $ledgerNames
            . ', but no item-backed opening or stock movement in the subledger points to it.';
        $addCause(
            $result['causes'],
            $type,
            $voucherNo,
            $title,
            $detail,
            $recommendation,
            $impact,
            $type === 'fiscal_opening_adjustment' ? 'Review' : 'High'
        );
    }

    $explained = 0.0;
    foreach ($result['causes'] as $cause) {
        $explained += (float) ($cause['impact'] ?? 0);
    }
    $result['explained'] = round($explained, 2);
    $result['unexplained'] = round($difference - $result['explained'], 2);

    return $result;
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
    $jewelleryExclusion = column_exists('inventory_transactions', 'jewellery_stock_txn_id')
        ? ' AND t.jewellery_stock_txn_id IS NULL'
        : '';
    $stmt = db()->prepare("SELECT t.*, i.sku FROM inventory_transactions t
        JOIN inventory_items i ON i.id = t.item_id
        WHERE t.company_id = :cid AND t.voucher_id IS NULL AND t.transaction_type IN ('consume', 'produce')"
        . $jewelleryExclusion
        . ' ORDER BY t.transaction_date ASC, t.id ASC');
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
            $openings['value'] += (float) $item['opening_qty'] * inv_item_opening_unit_cost($item);
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

// ---------------------------------------------------------------------------
// Per-fiscal-year INVENTORY OPENING — exactly like the accounting opening
// balances: qty + AMOUNT only (no rate), generated (carried) from the
// previous year's replayed closing, adjustable with a reason, and governed by
// the SAME opening-balance batch lifecycle (finalize / lock / unlock).
// ---------------------------------------------------------------------------

/** The accounting opening-balance batch status for a year ('' = none yet). */
function inv_ob_batch_status(int $companyId, int $fiscalYearId): string
{
    if (!table_exists('opening_balance_batches')) {
        return '';
    }
    $stmt = db()->prepare('SELECT status FROM opening_balance_batches WHERE company_id = :cid AND fiscal_year_id = :fy LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'fy' => $fiscalYearId]);
    return (string) ($stmt->fetchColumn() ?: '');
}

/**
 * Generate (or refresh) the year's per-item opening rows.
 * Previous year exists -> carry each item's REPLAYED closing qty+amount at the
 * previous year end; first year -> seed from the item master opening. Rows an
 * admin already adjusted are preserved, mirroring ob_generate_batch. Refused
 * while the accounting opening-balance batch is locked.
 */
function inv_ob_generate(int $companyId, int $fiscalYearId, int $userId): array
{
    if (inv_ob_batch_status($companyId, $fiscalYearId) === 'locked') {
        return ['ok' => false, 'error' => 'Opening balances for this year are locked. Unlock them first — the inventory opening follows the same lock.'];
    }
    $fyStmt = db()->prepare('SELECT * FROM fiscal_years WHERE id = :id AND company_id = :cid');
    $fyStmt->execute(['id' => $fiscalYearId, 'cid' => $companyId]);
    $fy = $fyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$fy) {
        return ['ok' => false, 'error' => 'Fiscal year not found for this company.'];
    }
    $prevStmt = db()->prepare('SELECT * FROM fiscal_years WHERE company_id = :cid AND end_date < :start ORDER BY end_date DESC LIMIT 1');
    $prevStmt->execute(['cid' => $companyId, 'start' => (string) $fy['start_date']]);
    $prevFy = $prevStmt->fetch(PDO::FETCH_ASSOC);

    $adjusted = [];
    $adjStmt = db()->prepare("SELECT item_id FROM inventory_opening_balances WHERE company_id = :cid AND fiscal_year_id = :fy AND source = 'adjusted'");
    $adjStmt->execute(['cid' => $companyId, 'fy' => $fiscalYearId]);
    foreach ($adjStmt->fetchAll(PDO::FETCH_COLUMN) as $iid) {
        $adjusted[(int) $iid] = true;
    }

    $upsert = db()->prepare("INSERT INTO inventory_opening_balances (company_id, fiscal_year_id, item_id, qty, amount, source)
        VALUES (:cid, :fy, :iid, :qty, :amt, :src)
        ON DUPLICATE KEY UPDATE qty = VALUES(qty), amount = VALUES(amount), source = VALUES(source), adjust_reason = NULL, adjusted_by = NULL, adjusted_at = NULL");
    $written = 0;
    if ($prevFy) {
        $closing = sr_stock_summary($companyId, ['from' => (string) $prevFy['end_date'], 'to' => (string) $prevFy['end_date']]);
        foreach ($closing['rows'] as $r) {
            if (isset($adjusted[(int) $r['item_id']])) {
                continue; // keep admin adjustments, like the accounting generate
            }
            $upsert->execute(['cid' => $companyId, 'fy' => $fiscalYearId, 'iid' => (int) $r['item_id'],
                'qty' => $r['closing_qty'], 'amt' => $r['closing_amount'], 'src' => 'carried']);
            $written++;
        }
    } else {
        $items = db()->prepare("SELECT id, opening_qty, opening_amount, purchase_rate FROM inventory_items WHERE company_id = :cid AND item_type <> 'service'");
        $items->execute(['cid' => $companyId]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if (isset($adjusted[(int) $item['id']])) {
                continue;
            }
            $qty = (float) $item['opening_qty'];
            $amount = (float) $item['opening_amount'] > 0.004 ? (float) $item['opening_amount'] : round($qty * (float) $item['purchase_rate'], 2);
            $upsert->execute(['cid' => $companyId, 'fy' => $fiscalYearId, 'iid' => (int) $item['id'],
                'qty' => $qty, 'amt' => $amount, 'src' => 'initial']);
            $written++;
        }
    }
    return ['ok' => true, 'error' => null, 'written' => $written, 'carried' => (bool) $prevFy];
}

/** The year's opening rows with item info (qty + amount only — rate is never stored). */
function inv_ob_rows(int $companyId, int $fiscalYearId): array
{
    if (!table_exists('inventory_opening_balances')) {
        return [];
    }
    $stmt = db()->prepare('SELECT ob.*, i.sku, i.name, i.unit, i.item_type
        FROM inventory_opening_balances ob
        INNER JOIN inventory_items i ON i.id = ob.item_id
        WHERE ob.company_id = :cid AND ob.fiscal_year_id = :fy
        ORDER BY i.sku ASC');
    $stmt->execute(['cid' => $companyId, 'fy' => $fiscalYearId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Adjust one item's opening for the year (qty + amount, reason required) —
 * refused while the batch is locked, mirroring ob_apply_adjustment.
 * First year (no previous data): the ITEM MASTER opening is synced, layers
 * rebuilt, and the INV-OPEN voucher replaced, so subledger and GL follow.
 * Later years: the difference vs the carried (replayed) amount posts as a
 * replaceable adjustment journal against Opening Balance Adjustments.
 */
function inv_ob_adjust(int $companyId, int $fiscalYearId, int $itemId, float $qty, float $amount, string $reason, int $userId): array
{
    $reason = trim($reason);
    if (mb_strlen($reason) < 10) {
        return ['ok' => false, 'error' => 'Give a reason of at least 10 characters — it is kept with the opening row.'];
    }
    if (inv_ob_batch_status($companyId, $fiscalYearId) === 'locked') {
        return ['ok' => false, 'error' => 'Opening balances for this year are locked. Unlock them first (Opening Balances page) — the inventory opening follows the same lock.'];
    }
    $qty = max(0.0, round($qty, 3));
    $amount = max(0.0, round($amount, 2));
    $itemStmt = db()->prepare('SELECT * FROM inventory_items WHERE id = :id AND company_id = :cid');
    $itemStmt->execute(['id' => $itemId, 'cid' => $companyId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        return ['ok' => false, 'error' => 'Item not found for this company.'];
    }
    $fyStmt = db()->prepare('SELECT * FROM fiscal_years WHERE id = :id AND company_id = :cid');
    $fyStmt->execute(['id' => $fiscalYearId, 'cid' => $companyId]);
    $fy = $fyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$fy) {
        return ['ok' => false, 'error' => 'Fiscal year not found.'];
    }
    $prevStmt = db()->prepare('SELECT * FROM fiscal_years WHERE company_id = :cid AND end_date < :start ORDER BY end_date DESC LIMIT 1');
    $prevStmt->execute(['cid' => $companyId, 'start' => (string) $fy['start_date']]);
    $prevFy = $prevStmt->fetch(PDO::FETCH_ASSOC);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO inventory_opening_balances (company_id, fiscal_year_id, item_id, qty, amount, source, adjust_reason, adjusted_by, adjusted_at)
            VALUES (:cid, :fy, :iid, :qty, :amt, 'adjusted', :reason, :by, NOW())
            ON DUPLICATE KEY UPDATE qty = VALUES(qty), amount = VALUES(amount), source = 'adjusted', adjust_reason = VALUES(adjust_reason), adjusted_by = VALUES(adjusted_by), adjusted_at = NOW()")
            ->execute(['cid' => $companyId, 'fy' => $fiscalYearId, 'iid' => $itemId, 'qty' => $qty, 'amt' => $amount, 'reason' => $reason, 'by' => $userId]);

        if (!$prevFy) {
            // First year: the master opening IS this opening — sync it so the
            // layers and the INV-OPEN voucher tell the same story.
            $pdo->prepare('UPDATE inventory_items SET opening_qty = :q, opening_amount = :a WHERE id = :id AND company_id = :cid')
                ->execute(['q' => $qty, 'a' => $amount, 'id' => $itemId, 'cid' => $companyId]);
            $item['opening_qty'] = $qty;
            $item['opening_amount'] = $amount;
            inv_rebuild_layers($companyId, $itemId, (string) $item['valuation_method'], $qty, $qty > 0 ? round($amount / $qty, 6) : 0.0);
            $voucherResult = inv_post_item_opening_voucher($companyId, $item, $userId);
            $note = (string) ($voucherResult['note'] ?? '');
        } else {
            // Later year: the books already carry the replayed closing of the
            // previous year — post/replace the DIFFERENCE as an adjustment
            // journal so the GL follows the adjusted opening.
            $carried = sr_stock_summary($companyId, ['from' => (string) $prevFy['end_date'], 'to' => (string) $prevFy['end_date']]);
            $carriedAmount = 0.0;
            foreach ($carried['rows'] as $cr) {
                if ((int) $cr['item_id'] === $itemId) {
                    $carriedAmount = (float) $cr['closing_amount'];
                }
            }
            $delta = round($amount - $carriedAmount, 2);
            $rowId = (int) $pdo->query('SELECT id FROM inventory_opening_balances WHERE fiscal_year_id = ' . $fiscalYearId . ' AND item_id = ' . $itemId)->fetchColumn();
            $existing = $pdo->prepare("SELECT * FROM vouchers WHERE company_id = :cid AND source_type = 'inventory_opening_adj' AND source_id = :sid LIMIT 1");
            $existing->execute(['cid' => $companyId, 'sid' => $rowId]);
            $existingVoucher = $existing->fetch(PDO::FETCH_ASSOC);
            if ($existingVoucher) {
                $blocker = voucher_mutation_blocker($existingVoucher, ['inventory_opening_adj']);
                if ($blocker !== null) {
                    throw new RuntimeException('The previous opening adjustment cannot be replaced: ' . $blocker);
                }
                $pdo->prepare('DELETE FROM vouchers WHERE id = :id AND company_id = :cid')->execute(['id' => (int) $existingVoucher['id'], 'cid' => $companyId]);
            }
            $note = '';
            if (abs($delta) > 0.004) {
                $stockLedgerId = inv_item_stock_ledger_id($companyId, $item);
                $contraId = function_exists('opening_balance_ledger_id') ? opening_balance_ledger_id($companyId) : 0;
                if ($stockLedgerId <= 0 || $contraId <= 0) {
                    throw new RuntimeException('Map the item stock ledger (and Opening Balance Adjustments) before adjusting a carried opening.');
                }
                create_voucher_with_entries([
                    'company_id' => $companyId,
                    'fiscal_year_id' => $fiscalYearId,
                    'voucher_no' => 'INV-OB-ADJ-' . $rowId,
                    'voucher_type' => 'journal',
                    'voucher_date' => (string) $fy['start_date'],
                    'source_type' => 'inventory_opening_adj',
                    'source_id' => $rowId,
                    'total_amount' => abs($delta),
                    'narration' => 'Inventory opening adjustment — ' . $item['sku'] . ' (' . $reason . ')',
                    'status' => 'posted',
                    'posted_by' => $userId,
                ], [
                    ['ledger_id' => $stockLedgerId, 'entry_type' => $delta > 0 ? 'debit' : 'credit', 'amount' => abs($delta), 'memo' => 'Opening stock adjusted'],
                    ['ledger_id' => $contraId, 'entry_type' => $delta > 0 ? 'credit' : 'debit', 'amount' => abs($delta), 'memo' => 'Opening adjustment contra — ' . $item['sku']],
                ]);
                $note = 'Adjustment journal of ' . number_format(abs($delta), 2) . ' posted at the fiscal-year start.';
            }
        }
        $pdo->commit();
        return ['ok' => true, 'error' => null, 'note' => $note];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
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
            // These mappings just changed; forget what was read of them.
            inv_mapping_forget();
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
        sr_replay_in($state, (float) $item['opening_qty'], inv_item_opening_unit_cost($item));
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
