<?php
declare(strict_types=1);

/**
 * Physical jewellery traceability.
 *
 * inventory_items is the product/style master. jewellery_stock_units is the
 * exact bangle, ring, chain or explicitly identified lot. The unit carries a
 * permanent trace code; the event table records every change without erasing
 * the earlier state.
 */

function jewellery_trace_ready(): bool
{
    return table_exists('jewellery_stock_units') && table_exists('jewellery_stock_unit_events');
}

function jewellery_trace_statuses(): array
{
    return ['planned', 'in_production', 'in_stock', 'reserved', 'sold', 'delivered', 'returned', 'cancelled'];
}

function jewellery_trace_next_code(int $companyId): string
{
    $stmt = db()->prepare("SELECT trace_code FROM jewellery_stock_units
        WHERE company_id = :cid AND trace_code LIKE 'TRC-%'
        ORDER BY LENGTH(trace_code) DESC, trace_code DESC LIMIT 1");
    $stmt->execute(['cid' => $companyId]);
    $last = (string) ($stmt->fetchColumn() ?: '');
    $next = 1;
    if (preg_match('/(\d+)$/', $last, $match) === 1) {
        $next = (int) $match[1] + 1;
    }

    return 'TRC-' . str_pad((string) $next, 8, '0', STR_PAD_LEFT);
}

function jewellery_trace_unit(int $companyId, int $stockUnitId, bool $lock = false): ?array
{
    if (!jewellery_trace_ready() || $stockUnitId <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT su.*, i.sku AS item_code, i.name AS item_name, i.category,
            p.code AS purity_code, p.fineness, u.code AS unit_code,
            COALESCE(op.name, su.customer_name) AS reserved_customer,
            oo.order_no AS reserved_order_no, ss.sale_no AS sold_sale_no
        FROM jewellery_stock_units su
        INNER JOIN inventory_items i ON i.id = su.item_id AND i.company_id = su.company_id
        INNER JOIN jewellery_purities p ON p.id = su.purity_id AND p.company_id = su.company_id
        INNER JOIN jewellery_units u ON u.id = su.unit_id AND u.company_id = su.company_id
        LEFT JOIN jewellery_orders oo ON oo.id = su.reserved_order_id AND oo.company_id = su.company_id
        LEFT JOIN accounting_parties op ON op.id = oo.party_id
        LEFT JOIN jewellery_sales ss ON ss.id = su.sold_sale_id AND ss.company_id = su.company_id
        WHERE su.id = :id AND su.company_id = :cid LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute(['id' => $stockUnitId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function jewellery_trace_event(int $companyId, int $stockUnitId, string $eventType, array $data = [], int $userId = 0): void
{
    if (!jewellery_trace_ready() || $stockUnitId <= 0) {
        return;
    }
    $eventDate = (string) ($data['event_date'] ?? date('Y-m-d'));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate) !== 1) {
        $eventDate = date('Y-m-d');
    }
    db()->prepare('INSERT INTO jewellery_stock_unit_events
            (company_id, stock_unit_id, event_type, event_date, from_status, to_status,
             from_holder_type, from_holder_id, to_holder_type, to_holder_id,
             source_type, source_id, source_line_id, reference_no, notes, created_by)
        VALUES (:cid, :uid, :etype, :edate, :from_status, :to_status,
                :from_holder, :from_holder_id, :to_holder, :to_holder_id,
                :source_type, :source_id, :source_line, :ref, :notes, :created_by)')
        ->execute([
            'cid' => $companyId,
            'uid' => $stockUnitId,
            'etype' => mb_substr(trim($eventType), 0, 40),
            'edate' => $eventDate,
            'from_status' => ($data['from_status'] ?? '') !== '' ? mb_substr((string) $data['from_status'], 0, 30) : null,
            'to_status' => ($data['to_status'] ?? '') !== '' ? mb_substr((string) $data['to_status'], 0, 30) : null,
            'from_holder' => ($data['from_holder_type'] ?? '') !== '' ? mb_substr((string) $data['from_holder_type'], 0, 30) : null,
            'from_holder_id' => (int) ($data['from_holder_id'] ?? 0) ?: null,
            'to_holder' => ($data['to_holder_type'] ?? '') !== '' ? mb_substr((string) $data['to_holder_type'], 0, 30) : null,
            'to_holder_id' => (int) ($data['to_holder_id'] ?? 0) ?: null,
            'source_type' => ($data['source_type'] ?? '') !== '' ? mb_substr((string) $data['source_type'], 0, 40) : null,
            'source_id' => (int) ($data['source_id'] ?? 0) ?: null,
            'source_line' => (int) ($data['source_line_id'] ?? 0) ?: null,
            'ref' => ($data['reference_no'] ?? '') !== '' ? mb_substr((string) $data['reference_no'], 0, 120) : null,
            'notes' => ($data['notes'] ?? '') !== '' ? mb_substr((string) $data['notes'], 0, 255) : null,
            'created_by' => $userId ?: null,
        ]);
}

/** Create one or more trace units. Whole piece quantities are split. */
function jewellery_trace_create_units(int $companyId, array $input, int $userId = 0): array
{
    if (!jewellery_trace_ready()) {
        return [];
    }
    $itemId = (int) ($input['item_id'] ?? 0);
    $item = jewellery_item($companyId, $itemId);
    if (!$item) {
        throw new RuntimeException('Cannot trace an item that does not belong to this company.');
    }
    $purityId = (int) ($input['purity_id'] ?? $item['purity_id']);
    $purity = jewellery_purity($companyId, $purityId);
    $unitId = (int) ($input['unit_id'] ?? $item['unit_id']);
    if (!$purity || (int) $purity['metal_id'] !== (int) $item['metal_id'] || !jewellery_unit($companyId, $unitId)) {
        throw new RuntimeException('The traced item needs a valid purity and unit from this company.');
    }

    $pieces = round((float) ($input['qty_pieces'] ?? 0), 3);
    $gross = jw_round_weight((float) ($input['gross_weight'] ?? 0));
    $stone = jw_round_weight((float) ($input['stone_weight'] ?? 0));
    $cost = jw_round_money((float) ($input['cost_amount'] ?? 0));
    if ($pieces <= 0 && (string) ($item['track_mode'] ?? '') === 'piece') {
        $pieces = 1.0;
    }
    if ($gross < 0 || $stone < 0 || $stone > $gross || $pieces < 0 || $cost < 0) {
        throw new RuntimeException('Trace quantities, weights and cost must be valid non-negative figures.');
    }

    $splitCount = 1;
    $roundedPieces = (int) round($pieces);
    if ($roundedPieces >= 1 && $roundedPieces <= 500 && abs($pieces - $roundedPieces) < 0.0005) {
        $splitCount = $roundedPieces;
    }
    $pieceQty = $splitCount > 1 ? 1.0 : $pieces;
    $pieceGross = $splitCount > 1 ? jw_round_weight($gross / $splitCount) : $gross;
    $pieceStone = $splitCount > 1 ? jw_round_weight($stone / $splitCount) : $stone;
    $pieceCost = $splitCount > 1 ? jw_round_money($cost / $splitCount) : $cost;
    $status = in_array((string) ($input['status'] ?? ''), jewellery_trace_statuses(), true)
        ? (string) $input['status'] : 'in_stock';
    $holderType = in_array((string) ($input['current_holder_type'] ?? ''), ['stock', 'karigar', 'refinery', 'customer'], true)
        ? (string) $input['current_holder_type'] : 'stock';
    $stockKind = (string) ($input['stock_kind'] ?? ($item['stock_kind'] ?? 'showroom')) === 'customer_ordered'
        ? 'customer_ordered' : 'showroom';
    $originType = trim((string) ($input['origin_type'] ?? 'manual')) ?: 'manual';

    $ids = [];
    $allocatedGross = 0.0;
    $allocatedStone = 0.0;
    $allocatedCost = 0.0;
    for ($part = 1; $part <= $splitCount; $part++) {
        $currentGross = $splitCount > 1 && $part === $splitCount
            ? jw_round_weight($gross - $allocatedGross) : $pieceGross;
        $currentStone = $splitCount > 1 && $part === $splitCount
            ? jw_round_weight($stone - $allocatedStone) : $pieceStone;
        $currentCost = $splitCount > 1 && $part === $splitCount
            ? jw_round_money($cost - $allocatedCost) : $pieceCost;
        $currentNet = jw_round_weight($currentGross - $currentStone);
        $currentFine = jw_fine_weight($currentNet, (float) $purity['fineness']);
        $id = 0;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $traceCode = jewellery_trace_next_code($companyId);
            try {
                db()->prepare('INSERT INTO jewellery_stock_units
                        (company_id, fiscal_year_id, trace_code, item_id, purity_id, unit_id,
                         stock_kind, status, current_holder_type, current_holder_id,
                         qty_pieces, gross_weight, stone_weight, net_weight, fine_weight, cost_amount,
                         origin_type, origin_id, origin_line_id, assignment_id, receipt_id, stock_order_no,
                         customer_party_id, customer_name, customer_order_no,
                         reserved_order_id, reserved_order_line_id, tag_no, notes, created_by)
                    VALUES (:cid, :fy, :trace, :item, :purity, :unit,
                            :kind, :status, :holder, :holder_id,
                            :pieces, :gross, :stone, :net, :fine, :cost,
                            :origin_type, :origin_id, :origin_line, :assignment, :receipt, :stock_order,
                            :party, :customer, :customer_order,
                            :reserved_order, :reserved_line, :tag, :notes, :created_by)')
                    ->execute([
                        'cid' => $companyId,
                        'fy' => (int) ($input['fiscal_year_id'] ?? 0) ?: null,
                        'trace' => $traceCode,
                        'item' => $itemId,
                        'purity' => $purityId,
                        'unit' => $unitId,
                        'kind' => $stockKind,
                        'status' => $status,
                        'holder' => $holderType,
                        'holder_id' => (int) ($input['current_holder_id'] ?? 0) ?: null,
                        'pieces' => $pieceQty,
                        'gross' => $currentGross,
                        'stone' => $currentStone,
                        'net' => $currentNet,
                        'fine' => $currentFine,
                        'cost' => $currentCost,
                        'origin_type' => mb_substr($originType, 0, 40),
                        'origin_id' => (int) ($input['origin_id'] ?? 0) ?: null,
                        'origin_line' => (int) ($input['origin_line_id'] ?? 0) ?: null,
                        'assignment' => (int) ($input['assignment_id'] ?? 0) ?: null,
                        'receipt' => (int) ($input['receipt_id'] ?? 0) ?: null,
                        'stock_order' => ($input['stock_order_no'] ?? '') !== '' ? mb_substr((string) $input['stock_order_no'], 0, 60) : null,
                        'party' => (int) ($input['customer_party_id'] ?? 0) ?: null,
                        'customer' => ($input['customer_name'] ?? '') !== '' ? mb_substr((string) $input['customer_name'], 0, 190) : null,
                        'customer_order' => ($input['customer_order_no'] ?? '') !== '' ? mb_substr((string) $input['customer_order_no'], 0, 120) : null,
                        'reserved_order' => (int) ($input['reserved_order_id'] ?? 0) ?: null,
                        'reserved_line' => (int) ($input['reserved_order_line_id'] ?? 0) ?: null,
                        'tag' => ($input['tag_no'] ?? '') !== '' ? mb_substr((string) $input['tag_no'], 0, 80) : null,
                        'notes' => ($input['notes'] ?? '') !== '' ? mb_substr((string) $input['notes'], 0, 255) : null,
                        'created_by' => $userId ?: null,
                    ]);
                $id = (int) db()->lastInsertId();
                break;
            } catch (PDOException $duplicate) {
                if ((string) $duplicate->getCode() !== '23000' || $attempt === 4) {
                    throw $duplicate;
                }
            }
        }
        $allocatedGross = jw_round_weight($allocatedGross + $currentGross);
        $allocatedStone = jw_round_weight($allocatedStone + $currentStone);
        $allocatedCost = jw_round_money($allocatedCost + $currentCost);
        $ids[] = $id;
        jewellery_trace_event($companyId, $id, (string) ($input['event_type'] ?? 'created'), [
            'event_date' => (string) ($input['event_date'] ?? date('Y-m-d')),
            'to_status' => $status,
            'to_holder_type' => $holderType,
            'to_holder_id' => (int) ($input['current_holder_id'] ?? 0),
            'source_type' => $originType,
            'source_id' => (int) ($input['origin_id'] ?? 0),
            'source_line_id' => (int) ($input['origin_line_id'] ?? 0),
            'reference_no' => (string) ($input['reference_no'] ?? ''),
            'notes' => $splitCount > 1 ? 'Piece ' . $part . ' of ' . $splitCount : (string) ($input['event_notes'] ?? ''),
        ], $userId);
    }

    return $ids;
}

function jewellery_trace_transition(int $companyId, int $stockUnitId, string $eventType, array $changes, array $event = [], int $userId = 0): array
{
    if (!jewellery_trace_ready()) {
        return ['ok' => false, 'error' => 'Traceability tables are not ready.'];
    }
    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        $before = jewellery_trace_unit($companyId, $stockUnitId, true);
        if (!$before) {
            throw new RuntimeException('Trace item not found for this company.');
        }
        $allowed = [
            'item_id', 'purity_id', 'unit_id', 'stock_kind', 'status', 'current_holder_type', 'current_holder_id',
            'qty_pieces', 'gross_weight', 'stone_weight', 'net_weight', 'fine_weight', 'cost_amount',
            'receipt_id', 'stock_order_no', 'customer_party_id', 'customer_name', 'customer_order_no',
            'reserved_order_id', 'reserved_order_line_id', 'reserved_sale_id', 'reserved_sale_line_id',
            'sold_sale_id', 'sold_sale_line_id', 'tag_no', 'notes',
        ];
        $sets = [];
        $params = ['id' => $stockUnitId, 'cid' => $companyId];
        foreach ($changes as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }
            $sets[] = '`' . $column . '` = :' . $column;
            $params[$column] = $value;
        }
        if ($sets !== []) {
            db()->prepare('UPDATE jewellery_stock_units SET ' . implode(', ', $sets)
                . ' WHERE id = :id AND company_id = :cid')->execute($params);
        }
        $after = jewellery_trace_unit($companyId, $stockUnitId);
        jewellery_trace_event($companyId, $stockUnitId, $eventType, $event + [
            'from_status' => (string) $before['status'],
            'to_status' => (string) ($after['status'] ?? $before['status']),
            'from_holder_type' => (string) $before['current_holder_type'],
            'from_holder_id' => (int) ($before['current_holder_id'] ?? 0),
            'to_holder_type' => (string) ($after['current_holder_type'] ?? $before['current_holder_type']),
            'to_holder_id' => (int) ($after['current_holder_id'] ?? 0),
        ], $userId);
        if ($ownsTransaction) {
            db()->commit();
        }

        return ['ok' => true, 'error' => '', 'unit' => $after];
    } catch (Throwable $exception) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }
        if (!$ownsTransaction) {
            throw $exception;
        }

        return ['ok' => false, 'error' => $exception->getMessage()];
    }
}

function jewellery_trace_units_list(int $companyId, array $filters = []): array
{
    if (!jewellery_trace_ready()) {
        return [];
    }
    $sql = 'SELECT su.*, i.sku AS item_code, i.name AS item_name, i.category,
            p.code AS purity_code, u.code AS unit_code,
            o.order_no AS reserved_order_no, COALESCE(ap.name, o.customer_name, su.customer_name) AS reserved_customer,
            s.sale_no AS sold_sale_no,
            (SELECT MAX(e.id) FROM jewellery_stock_unit_events e WHERE e.stock_unit_id = su.id AND e.company_id = su.company_id) AS last_event_id
        FROM jewellery_stock_units su
        INNER JOIN inventory_items i ON i.id = su.item_id AND i.company_id = su.company_id
        INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = su.item_id AND jp.company_id = su.company_id
        INNER JOIN jewellery_purities p ON p.id = su.purity_id AND p.company_id = su.company_id
        INNER JOIN jewellery_units u ON u.id = su.unit_id AND u.company_id = su.company_id
        LEFT JOIN jewellery_orders o ON o.id = su.reserved_order_id AND o.company_id = su.company_id
        LEFT JOIN accounting_parties ap ON ap.id = o.party_id
        LEFT JOIN jewellery_sales s ON s.id = su.sold_sale_id AND s.company_id = su.company_id
        WHERE su.company_id = :cid';
    $params = ['cid' => $companyId];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $sql .= ' AND (su.trace_code LIKE :q OR i.sku LIKE :q OR i.name LIKE :q OR su.stock_order_no LIKE :q
                       OR su.customer_order_no LIKE :q OR su.customer_name LIKE :q)';
        $params['q'] = '%' . $q . '%';
    }
    $status = (string) ($filters['status'] ?? '');
    if (in_array($status, jewellery_trace_statuses(), true)) {
        $sql .= ' AND su.status = :status';
        $params['status'] = $status;
    }
    $kind = (string) ($filters['stock_kind'] ?? '');
    if (in_array($kind, ['showroom', 'customer_ordered'], true)) {
        $sql .= ' AND su.stock_kind = :kind';
        $params['kind'] = $kind;
    }
    $sql .= ' ORDER BY su.updated_at DESC, su.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jewellery_trace_lifecycle(int $companyId, int $stockUnitId): array
{
    if (!jewellery_trace_ready()) {
        return [];
    }
    $stmt = db()->prepare('SELECT e.*, u.name AS user_name
        FROM jewellery_stock_unit_events e
        LEFT JOIN users u ON u.id = e.created_by
        WHERE e.company_id = :cid AND e.stock_unit_id = :uid
        ORDER BY e.id ASC');
    $stmt->execute(['cid' => $companyId, 'uid' => $stockUnitId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Showroom pieces that can be selected, plus a current order's own hold. */
function jewellery_trace_ready_to_sale_options(int $companyId, int $forOrderId = 0): array
{
    if (!jewellery_trace_ready()) {
        return [];
    }
    jewellery_trace_backfill_legacy_balance($companyId);
    // metal_id and purity_metal_id ride along because the order form validates a
    // chosen purity against the item's metal before it will accept the line —
    // without them every pick off the shelf failed that check.
    $sql = "SELECT su.*, i.sku AS item_code, i.name AS item_name, i.category,
            jp.metal_id, jp.design_no,
            p.code AS purity_code, p.metal_id AS purity_metal_id, u.code AS unit_code,
            o.order_no AS reserved_order_no, COALESCE(ap.name, o.customer_name, su.customer_name) AS reserved_for
        FROM jewellery_stock_units su
        INNER JOIN inventory_items i ON i.id = su.item_id AND i.company_id = su.company_id
        INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = su.item_id AND jp.company_id = su.company_id
        INNER JOIN jewellery_purities p ON p.id = su.purity_id AND p.company_id = su.company_id
        INNER JOIN jewellery_units u ON u.id = su.unit_id AND u.company_id = su.company_id
        LEFT JOIN jewellery_orders o ON o.id = su.reserved_order_id AND o.company_id = su.company_id
        LEFT JOIN accounting_parties ap ON ap.id = o.party_id
        WHERE su.company_id = :cid AND su.stock_kind = 'showroom' AND jp.jewellery_type NOT IN ('bullion','stone')
          AND (su.status = 'in_stock'";
    $params = ['cid' => $companyId];
    if ($forOrderId > 0) {
        $sql .= " OR (su.status = 'reserved' AND su.reserved_order_id = :oid)";
        $params['oid'] = $forOrderId;
    }
    $sql .= ') ORDER BY i.category, i.name, su.trace_code';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jewellery_trace_reserve_for_order(int $companyId, int $stockUnitId, int $orderId, int $orderLineId, int $userId = 0): array
{
    $unit = jewellery_trace_unit($companyId, $stockUnitId, true);
    $order = jewellery_order($companyId, $orderId);
    if (!$order) {
        return ['ok' => false, 'error' => 'The order for this reservation was not found.'];
    }
    if (!$unit || (string) $unit['stock_kind'] !== 'showroom') {
        return ['ok' => false, 'error' => 'Choose an item that is on this company\'s showroom shelf.'];
    }
    if ((string) $unit['status'] === 'reserved' && (int) ($unit['reserved_order_id'] ?? 0) !== $orderId) {
        return ['ok' => false, 'error' => 'Trace ' . $unit['trace_code'] . ' is already promised to '
            . (($unit['reserved_customer'] ?? '') ?: 'another customer') . '.'];
    }
    if (!in_array((string) $unit['status'], ['in_stock', 'reserved'], true)) {
        return ['ok' => false, 'error' => 'Trace ' . $unit['trace_code'] . ' is ' . str_replace('_', ' ', (string) $unit['status']) . ' and cannot be reserved.'];
    }

    return jewellery_trace_transition($companyId, $stockUnitId, 'reserved_for_order', [
        'status' => 'reserved', 'reserved_order_id' => $orderId, 'reserved_order_line_id' => $orderLineId,
    ], ['source_type' => 'jewellery_order', 'source_id' => $orderId, 'source_line_id' => $orderLineId,
        'reference_no' => (string) $order['order_no']], $userId);
}

function jewellery_trace_release_order(int $companyId, int $orderId, array $keepUnitIds = [], int $userId = 0): void
{
    if (!jewellery_trace_ready() || $orderId <= 0) {
        return;
    }
    $stmt = db()->prepare("SELECT id, reserved_sale_id FROM jewellery_stock_units
        WHERE company_id = :cid AND reserved_order_id = :oid AND status = 'reserved'");
    $stmt->execute(['cid' => $companyId, 'oid' => $orderId]);
    $keep = array_fill_keys(array_map('intval', $keepUnitIds), true);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $unitId = (int) $row['id'];
        if (isset($keep[$unitId])) {
            continue;
        }
        $released = jewellery_trace_transition($companyId, $unitId, 'order_reservation_released', [
            'status' => (int) ($row['reserved_sale_id'] ?? 0) > 0 ? 'reserved' : 'in_stock',
            'reserved_order_id' => null, 'reserved_order_line_id' => null,
        ], ['source_type' => 'jewellery_order', 'source_id' => $orderId], $userId);
        if (!$released['ok']) {
            throw new RuntimeException((string) $released['error']);
        }
    }
}

function jewellery_trace_reserve_for_sale(int $companyId, int $stockUnitId, int $saleId, int $saleLineId,
    array $deliverOrderIds = [], int $userId = 0): array
{
    $unit = jewellery_trace_unit($companyId, $stockUnitId, true);
    if (!$unit) {
        return ['ok' => false, 'error' => 'The selected trace item was not found.'];
    }
    if ((string) $unit['status'] === 'reserved' && (int) ($unit['reserved_sale_id'] ?? 0) > 0
        && (int) $unit['reserved_sale_id'] !== $saleId) {
        return ['ok' => false, 'error' => 'Trace ' . $unit['trace_code'] . ' is already on another draft sale.'];
    }
    if ((string) $unit['status'] === 'reserved' && (int) ($unit['reserved_order_id'] ?? 0) > 0
        && !in_array((int) $unit['reserved_order_id'], array_map('intval', $deliverOrderIds), true)) {
        return ['ok' => false, 'error' => 'Trace ' . $unit['trace_code'] . ' is promised to order '
            . (($unit['reserved_order_no'] ?? '') ?: ('#' . (int) $unit['reserved_order_id'])) . '.'];
    }
    if (!in_array((string) $unit['status'], ['in_stock', 'reserved'], true)) {
        return ['ok' => false, 'error' => 'Trace ' . $unit['trace_code'] . ' is not available for sale.'];
    }

    return jewellery_trace_transition($companyId, $stockUnitId, 'reserved_for_sale', [
        'status' => 'reserved', 'reserved_sale_id' => $saleId, 'reserved_sale_line_id' => $saleLineId,
    ], ['source_type' => 'jewellery_sale', 'source_id' => $saleId, 'source_line_id' => $saleLineId], $userId);
}

/** Release only draft-sale holds; posted/sold traces are reversed separately. */
function jewellery_trace_release_sale_reservations(int $companyId, int $saleId, array $keepUnitIds = [], int $userId = 0): void
{
    if (!jewellery_trace_ready() || $saleId <= 0) {
        return;
    }
    $stmt = db()->prepare("SELECT id, reserved_order_id FROM jewellery_stock_units
        WHERE company_id = :cid AND reserved_sale_id = :sid AND status = 'reserved'");
    $stmt->execute(['cid' => $companyId, 'sid' => $saleId]);
    $keep = array_fill_keys(array_map('intval', $keepUnitIds), true);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($keep[(int) $row['id']])) {
            continue;
        }
        jewellery_trace_transition($companyId, (int) $row['id'], 'sale_reservation_released', [
            'status' => (int) ($row['reserved_order_id'] ?? 0) > 0 ? 'reserved' : 'in_stock',
            'reserved_sale_id' => null, 'reserved_sale_line_id' => null,
        ], ['source_type' => 'jewellery_sale', 'source_id' => $saleId], $userId);
    }
}

function jewellery_trace_mark_sold(int $companyId, int $stockUnitId, int $saleId, int $saleLineId, int $customerId, string $saleNo, string $saleDate, int $userId = 0): array
{
    $unit = jewellery_trace_unit($companyId, $stockUnitId, true);
    if (!$unit || !in_array((string) $unit['status'], ['in_stock', 'reserved'], true)) {
        return ['ok' => false, 'error' => 'The selected trace item is no longer available.'];
    }
    if ((int) ($unit['reserved_sale_id'] ?? 0) > 0 && (int) $unit['reserved_sale_id'] !== $saleId) {
        return ['ok' => false, 'error' => 'Trace ' . $unit['trace_code'] . ' belongs to another draft sale.'];
    }
    return jewellery_trace_transition($companyId, $stockUnitId, 'sold', [
        'status' => 'sold', 'current_holder_type' => 'customer', 'current_holder_id' => $customerId ?: null,
        'sold_sale_id' => $saleId, 'sold_sale_line_id' => $saleLineId,
        'reserved_sale_id' => null, 'reserved_sale_line_id' => null,
    ], ['event_date' => $saleDate, 'source_type' => 'jewellery_sale', 'source_id' => $saleId,
        'source_line_id' => $saleLineId, 'reference_no' => $saleNo], $userId);
}

function jewellery_trace_release_sale(int $companyId, int $saleId, int $userId = 0): void
{
    if (!jewellery_trace_ready() || $saleId <= 0) {
        return;
    }
    $stmt = db()->prepare("SELECT id, status, reserved_order_id FROM jewellery_stock_units
        WHERE company_id = ? AND (reserved_sale_id = ? OR sold_sale_id = ?)");
    $stmt->execute([$companyId, $saleId, $saleId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        jewellery_trace_transition($companyId, (int) $row['id'], 'sale_reversed', [
            'status' => (int) ($row['reserved_order_id'] ?? 0) > 0 ? 'reserved' : 'in_stock',
            'current_holder_type' => 'stock', 'current_holder_id' => null,
            'reserved_sale_id' => null, 'reserved_sale_line_id' => null,
            'sold_sale_id' => null, 'sold_sale_line_id' => null,
        ], ['source_type' => 'jewellery_sale', 'source_id' => $saleId], $userId);
    }
}

/** Planned piece begins at assignment/stock order, before metal moves. */
function jewellery_trace_plan_assignment(int $companyId, int $fiscalYearId, int $assignmentId, array $row, string $assignmentNo, string $stockOrderNo, int $userId = 0): int
{
    if (!jewellery_trace_ready()) {
        return 0;
    }
    $existing = db()->prepare('SELECT id FROM jewellery_stock_units WHERE company_id = :cid AND assignment_id = :aid LIMIT 1');
    $existing->execute(['cid' => $companyId, 'aid' => $assignmentId]);
    $id = (int) ($existing->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }
    $order = null;
    if ((int) ($row['order_id'] ?? 0) > 0) {
        $order = jewellery_order($companyId, (int) $row['order_id']);
    }
    $ids = jewellery_trace_create_units($companyId, [
        'fiscal_year_id' => $fiscalYearId,
        'item_id' => (int) $row['item_id'], 'purity_id' => (int) $row['purity_id'], 'unit_id' => (int) $row['unit_id'],
        'stock_kind' => (string) ($row['assign_kind'] ?? '') === 'self' ? 'showroom' : 'customer_ordered',
        'status' => 'in_production', 'current_holder_type' => 'karigar',
        'current_holder_id' => (int) ($row['karigar_id'] ?? 0),
        'qty_pieces' => 1, 'gross_weight' => (float) ($row['expected_gross_weight'] ?? 0),
        'stone_weight' => (float) ($row['expected_stone_weight'] ?? 0),
        'origin_type' => (string) ($row['assign_kind'] ?? '') === 'self' ? 'stock_order' : 'customer_order',
        'origin_id' => $assignmentId, 'assignment_id' => $assignmentId,
        'stock_order_no' => $stockOrderNo, 'customer_party_id' => (int) ($order['party_id'] ?? 0),
        'customer_name' => (string) ($order['customer_name'] ?? ''), 'customer_order_no' => (string) ($order['order_no'] ?? ''),
        'reserved_order_id' => (int) ($row['order_id'] ?? 0), 'reserved_order_line_id' => (int) ($row['order_line_id'] ?? 0),
        'event_type' => (string) ($row['assign_kind'] ?? '') === 'self' ? 'stock_order_created' : 'customer_item_assigned',
        'event_date' => (string) ($row['assigned_date'] ?? date('Y-m-d')), 'reference_no' => $stockOrderNo !== '' ? $stockOrderNo : $assignmentNo,
        'notes' => (string) ($row['description'] ?? ''),
    ], $userId);
    $id = (int) ($ids[0] ?? 0);
    if ($id > 0) {
        db()->prepare('UPDATE jewellery_order_assignments SET stock_unit_id = :uid WHERE id = :aid AND company_id = :cid')
            ->execute(['uid' => $id, 'aid' => $assignmentId, 'cid' => $companyId]);
        if ((int) ($row['order_line_id'] ?? 0) > 0) {
            db()->prepare('UPDATE jewellery_order_lines SET stock_unit_id = :uid
                WHERE id = :lid AND company_id = :cid')
                ->execute(['uid' => $id, 'lid' => (int) $row['order_line_id'], 'cid' => $companyId]);
        }
    }

    return $id;
}

function jewellery_trace_receive_assignment(int $companyId, int $assignmentId, int $receiptId, array $actual, int $userId = 0): int
{
    if (!jewellery_trace_ready()) {
        return 0;
    }
    $assignment = jewellery_assignment($companyId, $assignmentId);
    if (!$assignment) {
        return 0;
    }
    $stockUnitId = (int) ($assignment['stock_unit_id'] ?? 0);
    $targetStatus = (string) ($assignment['assign_kind'] ?? 'customer') === 'self' ? 'in_stock' : 'reserved';
    if ($stockUnitId <= 0) {
        $stockUnitId = jewellery_trace_plan_assignment($companyId, (int) ($assignment['fiscal_year_id'] ?? 0),
            $assignmentId, $assignment, (string) ($assignment['assignment_no'] ?? ''), (string) ($assignment['stock_order_no'] ?? ''), $userId);
    }
    $purity = jewellery_purity($companyId, (int) $actual['purity_id']);
    $gross = jw_round_weight((float) $actual['gross_weight']);
    $stone = jw_round_weight((float) ($actual['stone_weight'] ?? 0));
    $net = jw_round_weight($gross - $stone);
    $fine = $purity ? jw_fine_weight($net, (float) $purity['fineness']) : 0.0;
    $result = jewellery_trace_transition($companyId, $stockUnitId, 'received_from_karigar', [
        'item_id' => (int) $actual['item_id'], 'purity_id' => (int) $actual['purity_id'],
        'unit_id' => (int) $actual['unit_id'], 'qty_pieces' => (float) ($actual['qty_pieces'] ?? 1),
        'gross_weight' => $gross, 'stone_weight' => $stone, 'net_weight' => $net, 'fine_weight' => $fine,
        'cost_amount' => (float) ($actual['cost_amount'] ?? 0), 'receipt_id' => $receiptId,
        'status' => $targetStatus, 'current_holder_type' => 'stock', 'current_holder_id' => null,
        'reserved_order_id' => (int) ($assignment['order_id'] ?? 0) ?: null,
        'reserved_order_line_id' => (int) ($assignment['order_line_id'] ?? 0) ?: null,
    ], ['event_date' => (string) ($actual['event_date'] ?? date('Y-m-d')),
        'source_type' => 'jewellery_order_receipt', 'source_id' => $receiptId,
        'reference_no' => (string) ($actual['reference_no'] ?? '')], $userId);
    if ($result['ok']) {
        db()->prepare('UPDATE jewellery_order_receipts SET stock_unit_id = :uid WHERE id = :rid AND company_id = :cid')
            ->execute(['uid' => $stockUnitId, 'rid' => $receiptId, 'cid' => $companyId]);
    }

    return $result['ok'] ? $stockUnitId : 0;
}

/** Adopt a receipt written before physical trace IDs were introduced. */
function jewellery_trace_from_receipt(int $companyId, int $receiptId, int $userId = 0): int
{
    if (!jewellery_trace_ready() || $receiptId <= 0) {
        return 0;
    }
    $stmt = db()->prepare('SELECT r.*, a.stock_unit_id AS assignment_stock_unit_id,
            a.assignment_no, a.stock_order_no, a.assign_kind, a.order_id, a.order_line_id,
            a.item_id AS assigned_item_id, a.purity_id AS assigned_purity_id, a.unit_id AS assigned_unit_id,
            a.karigar_id, a.issue_date, a.expected_gross_weight, a.expected_stone_weight,
            o.order_no, o.customer_name, o.party_id,
            (SELECT st.amount FROM jewellery_stock_txns st
                WHERE st.company_id = r.company_id AND st.source_type = \'jewellery_order_receipt\'
                  AND st.source_id = r.id AND st.direction = \'in\' AND st.holder_type = \'stock\'
                ORDER BY st.id DESC LIMIT 1) AS traced_cost_amount
        FROM jewellery_order_receipts r
        INNER JOIN jewellery_order_assignments a ON a.id = r.assignment_id AND a.company_id = r.company_id
        LEFT JOIN jewellery_orders o ON o.id = a.order_id AND o.company_id = a.company_id
        WHERE r.id = :rid AND r.company_id = :cid AND r.status <> \'cancelled\' LIMIT 1');
    $stmt->execute(['rid' => $receiptId, 'cid' => $companyId]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$receipt) {
        return 0;
    }
    $stockUnitId = (int) ($receipt['stock_unit_id'] ?? $receipt['assignment_stock_unit_id'] ?? 0);
    if ($stockUnitId > 0 && jewellery_trace_unit($companyId, $stockUnitId)) {
        return $stockUnitId;
    }
    $stockUnitId = jewellery_trace_plan_assignment($companyId, (int) ($receipt['fiscal_year_id'] ?? 0),
        (int) $receipt['assignment_id'], [
            'assign_kind' => (string) $receipt['assign_kind'], 'karigar_id' => (int) $receipt['karigar_id'],
            'order_id' => (int) ($receipt['order_id'] ?? 0), 'order_line_id' => (int) ($receipt['order_line_id'] ?? 0),
            'item_id' => (int) ($receipt['assigned_item_id'] ?? 0),
            'purity_id' => (int) ($receipt['assigned_purity_id'] ?? 0),
            'unit_id' => (int) ($receipt['assigned_unit_id'] ?? 0),
            'expected_gross_weight' => (float) ($receipt['expected_gross_weight'] ?? 0),
            'expected_stone_weight' => (float) ($receipt['expected_stone_weight'] ?? 0),
            'assigned_date' => (string) ($receipt['issue_date'] ?? date('Y-m-d')),
        ], (string) $receipt['assignment_no'], (string) ($receipt['stock_order_no'] ?? ''), $userId);
    if ($stockUnitId <= 0) {
        return 0;
    }
    return jewellery_trace_receive_assignment($companyId, (int) $receipt['assignment_id'], $receiptId, [
        'item_id' => (int) $receipt['received_item_id'], 'purity_id' => (int) $receipt['received_purity_id'],
        'unit_id' => (int) $receipt['unit_id'], 'qty_pieces' => (float) ($receipt['qty_pieces'] ?? 1),
        'gross_weight' => (float) $receipt['received_gross_weight'],
        'stone_weight' => (float) ($receipt['stone_weight'] ?? 0),
        'cost_amount' => (float) ($receipt['traced_cost_amount'] ?? $receipt['net_payable'] ?? 0),
        'event_date' => (string) $receipt['receive_date'], 'reference_no' => (string) $receipt['receipt_no'],
    ], $userId);
}

function jewellery_trace_cancel_assignment(int $companyId, int $assignmentId, string $reason, int $userId = 0): void
{
    if (!jewellery_trace_ready()) {
        return;
    }
    $stmt = db()->prepare('SELECT stock_unit_id FROM jewellery_order_assignments WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $assignmentId, 'cid' => $companyId]);
    $stockUnitId = (int) ($stmt->fetchColumn() ?: 0);
    if ($stockUnitId > 0) {
        jewellery_trace_transition($companyId, $stockUnitId, 'assignment_cancelled', [
            'status' => 'cancelled', 'current_holder_type' => 'stock', 'current_holder_id' => null,
        ], ['source_type' => 'jewellery_assignment', 'source_id' => $assignmentId, 'notes' => $reason], $userId);
    }
}

function jewellery_trace_create_purchase_line(int $companyId, array $purchase, array $line, int $userId = 0): array
{
    if (!jewellery_trace_ready()) {
        return [];
    }
    $existing = db()->prepare("SELECT id, status FROM jewellery_stock_units
        WHERE company_id = :cid AND origin_type = 'purchase' AND origin_id = :pid AND origin_line_id = :lid
        ORDER BY id");
    $existing->execute(['cid' => $companyId, 'pid' => (int) $purchase['id'], 'lid' => (int) $line['id']]);
    $ids = [];
    foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $existingUnit) {
        $ids[] = (int) $existingUnit['id'];
        if ((string) $existingUnit['status'] === 'cancelled') {
            jewellery_trace_transition($companyId, (int) $existingUnit['id'], 'purchase_reposted', [
                'status' => 'in_stock', 'current_holder_type' => 'stock', 'current_holder_id' => null,
            ], ['event_date' => (string) $purchase['purchase_date'], 'source_type' => 'jewellery_purchase',
                'source_id' => (int) $purchase['id'], 'source_line_id' => (int) $line['id'],
                'reference_no' => (string) $purchase['purchase_no']], $userId);
        }
    }
    if ($ids !== []) {
        return $ids;
    }
    $ids = jewellery_trace_create_units($companyId, [
        'fiscal_year_id' => (int) ($purchase['fiscal_year_id'] ?? 0),
        'item_id' => (int) $line['item_id'], 'purity_id' => (int) $line['purity_id'], 'unit_id' => (int) $line['unit_id'],
        'stock_kind' => 'showroom', 'status' => 'in_stock', 'qty_pieces' => (float) $line['qty_pieces'],
        'gross_weight' => (float) $line['gross_weight'], 'stone_weight' => (float) ($line['stone_weight'] ?? 0),
        'cost_amount' => (float) ($line['stock_amount'] ?? 0), 'origin_type' => 'purchase',
        'origin_id' => (int) $purchase['id'], 'origin_line_id' => (int) $line['id'],
        'event_type' => 'purchased', 'event_date' => (string) $purchase['purchase_date'],
        'reference_no' => (string) $purchase['purchase_no'],
    ], $userId);
    if ($ids !== []) {
        db()->prepare('UPDATE jewellery_purchase_lines SET stock_unit_id = :uid WHERE id = :id AND company_id = :cid')
            ->execute(['uid' => $ids[0], 'id' => (int) $line['id'], 'cid' => $companyId]);
    }

    return $ids;
}

/** Old jewellery accepted against a sale is a new physical stock acquisition. */
function jewellery_trace_create_sale_exchange(int $companyId, array $sale, array $exchange, int $userId = 0): array
{
    if (!jewellery_trace_ready()) {
        return [];
    }
    $existing = db()->prepare("SELECT id, status FROM jewellery_stock_units
        WHERE company_id = :cid AND origin_type = 'sale_exchange' AND origin_id = :sid AND origin_line_id = :lid
        ORDER BY id");
    $existing->execute(['cid' => $companyId, 'sid' => (int) $sale['id'], 'lid' => (int) $exchange['id']]);
    $ids = [];
    foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $existingUnit) {
        $ids[] = (int) $existingUnit['id'];
        if ((string) $existingUnit['status'] === 'cancelled') {
            jewellery_trace_transition($companyId, (int) $existingUnit['id'], 'sale_exchange_reposted', [
                'status' => 'in_stock', 'current_holder_type' => 'stock', 'current_holder_id' => null,
            ], ['event_date' => (string) $sale['sale_date'], 'source_type' => 'jewellery_sale_exchange',
                'source_id' => (int) $sale['id'], 'source_line_id' => (int) $exchange['id'],
                'reference_no' => (string) $sale['sale_no']], $userId);
        }
    }
    if ($ids === []) {
        $ids = jewellery_trace_create_units($companyId, [
            'fiscal_year_id' => (int) ($sale['fiscal_year_id'] ?? 0),
            'item_id' => (int) $exchange['item_id'], 'purity_id' => (int) $exchange['purity_id'],
            'unit_id' => (int) $exchange['unit_id'], 'stock_kind' => 'showroom', 'status' => 'in_stock',
            'qty_pieces' => (float) $exchange['qty_pieces'], 'gross_weight' => (float) $exchange['gross_weight'],
            'cost_amount' => (float) $exchange['amount'], 'origin_type' => 'sale_exchange',
            'origin_id' => (int) $sale['id'], 'origin_line_id' => (int) $exchange['id'],
            'event_type' => 'old_jewellery_received', 'event_date' => (string) $sale['sale_date'],
            'reference_no' => (string) $sale['sale_no'],
        ], $userId);
    }
    if ($ids !== []) {
        db()->prepare('UPDATE jewellery_sale_exchanges SET stock_unit_id = :uid WHERE id = :id AND company_id = :cid')
            ->execute(['uid' => $ids[0], 'id' => (int) $exchange['id'], 'cid' => $companyId]);
    }

    return $ids;
}

function jewellery_trace_replace_opening(int $companyId, int $fiscalYearId, array $input, int $userId = 0): array
{
    if (!jewellery_trace_ready()) {
        return [];
    }
    $itemId = (int) $input['item_id'];
    $old = db()->prepare("SELECT id, status FROM jewellery_stock_units
        WHERE company_id = :cid AND item_id = :iid AND origin_type IN ('opening','opening_import','manual_opening')");
    $old->execute(['cid' => $companyId, 'iid' => $itemId]);
    foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $unit) {
        if (in_array((string) $unit['status'], ['reserved', 'sold', 'delivered'], true)) {
            throw new RuntimeException('Opening stock cannot be replaced because trace item #'
                . (int) $unit['id'] . ' has already been reserved or sold. Correct it through a controlled stock adjustment.');
        }
        jewellery_trace_transition($companyId, (int) $unit['id'], 'opening_replaced', ['status' => 'cancelled'], [
            'source_type' => (string) ($input['origin_type'] ?? 'manual_opening'),
            'source_id' => (int) ($input['origin_id'] ?? $itemId),
        ], $userId);
    }
    if ((float) ($input['gross_weight'] ?? 0) <= 0 && (float) ($input['qty_pieces'] ?? 0) <= 0) {
        return [];
    }

    return jewellery_trace_create_units($companyId, [
        'fiscal_year_id' => $fiscalYearId, 'item_id' => $itemId,
        'purity_id' => (int) $input['purity_id'], 'unit_id' => (int) $input['unit_id'],
        'stock_kind' => (string) ($input['stock_kind'] ?? 'showroom'), 'status' => 'in_stock',
        'qty_pieces' => (float) ($input['qty_pieces'] ?? 0), 'gross_weight' => (float) ($input['gross_weight'] ?? 0),
        'stone_weight' => (float) ($input['stone_weight'] ?? 0),
        'cost_amount' => (float) ($input['cost_amount'] ?? 0),
        'origin_type' => (string) ($input['origin_type'] ?? 'manual_opening'),
        'origin_id' => (int) ($input['origin_id'] ?? $itemId), 'origin_line_id' => (int) ($input['origin_line_id'] ?? 0),
        'customer_name' => (string) ($input['customer_name'] ?? ''), 'customer_order_no' => (string) ($input['customer_order_no'] ?? ''),
        'event_type' => 'opening_recorded', 'event_date' => (string) ($input['event_date'] ?? date('Y-m-d')),
        'reference_no' => (string) ($input['reference_no'] ?? 'OPENING'),
    ], $userId);
}

/**
 * Give pre-upgrade on-hand stock one honest starting point. It is labelled as
 * a legacy balance, never invented as a historical purchase or receipt.
 */
function jewellery_trace_backfill_legacy_balance(int $companyId, int $userId = 0): void
{
    static $done = [];
    if (!jewellery_trace_ready() || isset($done[$companyId])) {
        return;
    }
    $done[$companyId] = true;
    $fy = current_fiscal_year();
    foreach (jewellery_items_list($companyId, ['active_only' => true]) as $item) {
        $balance = jw_item_balance($companyId, (int) $item['id'], null, 'stock');
        if ((float) $balance['fine_weight'] <= 0.00005 && (float) $balance['qty_pieces'] <= 0.0005) {
            continue;
        }
        $sum = db()->prepare("SELECT COALESCE(SUM(fine_weight),0) AS fine, COALESCE(SUM(qty_pieces),0) AS pieces
            FROM jewellery_stock_units WHERE company_id = :cid AND item_id = :iid
              AND status IN ('in_stock','reserved')");
        $sum->execute(['cid' => $companyId, 'iid' => (int) $item['id']]);
        $traced = $sum->fetch(PDO::FETCH_ASSOC) ?: ['fine' => 0, 'pieces' => 0];
        $fineGap = jw_round_weight((float) $balance['fine_weight'] - (float) $traced['fine']);
        $pieceGap = round((float) $balance['qty_pieces'] - (float) $traced['pieces'], 3);
        if ($fineGap <= 0.00005 && $pieceGap <= 0.0005) {
            continue;
        }
        $grossGap = (float) $item['fineness'] > 0 ? jw_round_weight($fineGap * 1000 / (float) $item['fineness']) : 0.0;
        jewellery_trace_create_units($companyId, [
            'fiscal_year_id' => (int) ($fy['id'] ?? 0), 'item_id' => (int) $item['id'],
            'purity_id' => (int) $item['purity_id'], 'unit_id' => (int) $item['unit_id'],
            'stock_kind' => (string) ($item['stock_kind'] ?? 'showroom'), 'status' => 'in_stock',
            'qty_pieces' => max(0.0, $pieceGap), 'gross_weight' => max(0.0, $grossGap),
            'cost_amount' => jw_round_money(max(0.0, $fineGap) * (float) $balance['avg_fine_rate']),
            'origin_type' => 'legacy_balance', 'origin_id' => (int) $item['id'],
            'event_type' => 'legacy_balance_adopted', 'event_date' => date('Y-m-d'),
            'reference_no' => 'UPGRADE',
            'event_notes' => 'On-hand balance adopted at traceability upgrade. Earlier physical history was not fabricated.',
        ], $userId);
    }
}
