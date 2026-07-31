<?php
declare(strict_types=1);

require_once __DIR__ . '/jewellery_workshop.php';

/**
 * Putting the work in a kaligad's hands.
 *
 * Assigning is not issuing. Issuing is about metal: which bar leaves the safe,
 * what it weighs, what it is worth. Assigning is about the PIECE — who is
 * making what, to what size, by when — and it happens first, often days before
 * any gold moves, and sometimes for work the kaligad will supply the metal for
 * himself.
 *
 * Two kinds, and every rule on this page forks on them:
 *
 *   customer  against an order. The customer, the size, the piece's weights
 *             and its purity are read off the order line — nobody retypes what
 *             the order already says — and the dates are fenced by the order's
 *             own: not before it was taken, not after it was promised.
 *
 *   self      the showroom ordering its own stock. No customer, no order, so
 *             every field is typed and the piece is chosen from finished stock
 *             items rather than from an order that does not exist.
 */

/** The two kinds of assignment, and what each is called on screen. */
function jewellery_assign_kinds(): array
{
    return [
        'customer' => 'Customer ordered item',
        'self' => 'Self ordered item for showroom',
    ];
}

/** Gold, diamond, or something else. */
function jewellery_assign_categories(): array
{
    return ['gold' => 'Gold', 'diamond' => 'Diamond', 'other' => 'Other'];
}

/** The making-charge bases, in the words the counter uses. */
function jewellery_assign_making_bases(): array
{
    return [
        'flat' => 'Flat amount',
        'percent_of_metal' => 'Percentage of metal value',
        'per_unit_weight' => 'Per unit of weight',
    ];
}

/**
 * The next assignment number: KA-2083-00001.
 *
 * Its own series, not the issue number's, because an assignment can exist with
 * no metal issued against it at all — which is the ordinary case on the day the
 * work is handed out.
 */
function jewellery_assign_next_no(int $companyId, ?int $fiscalYearId, string $assignDate): string
{
    $settings = jewellery_settings($companyId);
    $prefix = trim((string) ($settings['assign_no_prefix'] ?? 'KA')) ?: 'KA';
    // The same year stamp the order series uses, so a shop reading KA-2083-…
    // beside JO-2083-… sees one year and not two conventions. Numbered through
    // the one helper every jewellery document goes through.
    $series = jewellery_order_series($companyId, $fiscalYearId, $assignDate);

    return jw_next_no($companyId, 'jewellery_order_assignments', 'assignment_no', $prefix, $series);
}

/**
 * Every open order with its items, in the shape the screen needs to fill
 * itself in when an order number is picked.
 *
 * One query for the orders and one for their lines, then stitched — a lookup
 * per order would be a query per row of a dropdown.
 */
function jewellery_assign_order_payload(int $companyId): array
{
    $orderStmt = db()->prepare("SELECT o.id, o.order_no, o.order_date, o.delivery_date, o.design_no,
            o.expected_item, COALESCE(ap.name, o.customer_name, 'Walk-in customer') AS customer_name
        FROM jewellery_orders o
        LEFT JOIN accounting_parties ap ON ap.id = o.party_id
        WHERE o.company_id = :cid
          AND o.status IN ('draft', 'confirmed', 'assigned', 'partially_received')
        ORDER BY o.order_date DESC, o.order_no DESC");
    $orderStmt->execute(['cid' => $companyId]);
    $orders = [];
    foreach ($orderStmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
        $order['lines'] = [];
        $orders[(int) $order['id']] = $order;
    }
    if ($orders === []) {
        return [];
    }

    // Only items not already out with somebody: an ornament assigned once must
    // not be quietly assigned again to a second kaligad.
    $lineStmt = db()->prepare("SELECT l.id, l.order_id, l.item_id, l.purity_id, l.unit_id, l.size,
            l.gross_weight, l.stone_weight, l.net_weight, l.delivery_date,
            i.sku AS item_code, i.name AS item_name,
            p.code AS purity_code, p.fineness, u.code AS unit_code
        FROM jewellery_order_lines l
        INNER JOIN inventory_items i ON i.id = l.item_id
        INNER JOIN jewellery_purities p ON p.id = l.purity_id
        INNER JOIN jewellery_units u ON u.id = l.unit_id
        WHERE l.company_id = :cid AND l.assignment_id IS NULL
        ORDER BY l.id ASC");
    $lineStmt->execute(['cid' => $companyId]);
    foreach ($lineStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $orderId = (int) $line['order_id'];
        if (!isset($orders[$orderId])) {
            continue;
        }
        // A line saved before stone weights existed has net = 0; the gross is
        // then the whole of the metal, which is what it meant at the time.
        if ((float) $line['net_weight'] <= 0) {
            $line['net_weight'] = jw_round_weight((float) $line['gross_weight'] - (float) $line['stone_weight']);
        }
        $orders[$orderId]['lines'][] = $line;
    }

    // An order whose every item is already assigned has nothing left to offer.
    return array_values(array_filter($orders, static fn (array $o): bool => $o['lines'] !== []));
}

/** Finished stock items a self-ordered piece can be made as. */
function jewellery_assign_stock_items(int $companyId): array
{
    $stmt = db()->prepare("SELECT id, sku, name, unit, sales_rate
        FROM inventory_items
        WHERE company_id = :cid AND status = 'active'
        ORDER BY name ASC");
    $stmt->execute(['cid' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Check one submitted assignment and normalise it, without touching the
 * database. Returns ['ok' => bool, 'errors' => [...], 'row' => [...]].
 *
 * Pure apart from the two lookups it is handed, so the rules can be read in
 * one place and tested without a company.
 */
function jewellery_assign_validate(string $kind, array $input, ?array $order, ?array $orderLine): array
{
    $errors = [];
    $kind = isset(jewellery_assign_kinds()[$kind]) ? $kind : 'customer';
    $category = isset(jewellery_assign_categories()[(string) ($input['category'] ?? '')])
        ? (string) $input['category'] : 'gold';
    $basis = isset(jewellery_assign_making_bases()[(string) ($input['making_basis'] ?? '')])
        ? (string) $input['making_basis'] : 'flat';

    $karigarId = (int) ($input['karigar_id'] ?? 0);
    if ($karigarId <= 0) {
        $errors[] = 'Choose the kaligad this work goes to.';
    }

    $assignedDate = (string) ($input['assigned_date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $assignedDate) !== 1) {
        $errors[] = 'Give the date this work was assigned.';
    }
    $expectedDelivery = (string) ($input['expected_delivery'] ?? '');
    if ($expectedDelivery !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedDelivery) !== 1) {
        $expectedDelivery = '';
    }

    $gross = jw_round_weight((float) ($input['expected_gross_weight'] ?? 0));
    $stone = jw_round_weight((float) ($input['expected_stone_weight'] ?? 0));
    $purityId = (int) ($input['purity_id'] ?? 0);
    $itemId = (int) ($input['item_id'] ?? 0);
    $unitId = (int) ($input['unit_id'] ?? 0);
    $sizeDesign = trim((string) ($input['size_design'] ?? ''));
    $ornament = trim((string) ($input['expected_ornament'] ?? ''));
    $orderId = 0;
    $orderLineId = 0;

    if ($kind === 'customer') {
        if (!$order) {
            $errors[] = 'Choose the order number this work belongs to.';
        }
        if (!$orderLine) {
            $errors[] = 'Choose which of that order\'s items is being made.';
        }
        if ($order && $orderLine && (int) $orderLine['order_id'] !== (int) $order['id']) {
            $errors[] = 'That item does not belong to the order chosen.';
        }
        if ($order && $orderLine && $errors === []) {
            $orderId = (int) $order['id'];
            $orderLineId = (int) $orderLine['id'];
            // Read off the order, never retyped: the browser sent these only so
            // the person could see them, and a tampered field must not be able
            // to make an assignment disagree with the order it is against.
            $itemId = (int) $orderLine['item_id'];
            $purityId = (int) $orderLine['purity_id'];
            $unitId = (int) $orderLine['unit_id'];
            $gross = jw_round_weight((float) $orderLine['gross_weight']);
            $stone = jw_round_weight((float) $orderLine['stone_weight']);
            $sizeDesign = trim((string) ($orderLine['size'] ?? '')) !== ''
                ? trim((string) $orderLine['size'])
                : trim((string) ($order['design_no'] ?? ''));
            if ($ornament === '') {
                $ornament = trim((string) ($orderLine['item_name'] ?? ''));
            }

            $orderDate = (string) $order['order_date'];
            if ($assignedDate !== '' && $orderDate !== '' && $assignedDate < $orderDate) {
                $errors[] = 'The work cannot be assigned on ' . app_date($assignedDate)
                    . ', before the order was taken on ' . app_date($orderDate) . '.';
            }
            $promised = (string) ($order['delivery_date'] ?? '');
            if ($expectedDelivery !== '' && $promised !== '' && $expectedDelivery > $promised) {
                $errors[] = 'The kaligad cannot be given until ' . app_date($expectedDelivery)
                    . ' when the customer was promised ' . app_date($promised) . '.';
            }
        }
    } else {
        if ($itemId <= 0) {
            $errors[] = 'Choose the finished stock item this piece will become.';
        }
        if ($purityId <= 0) {
            $errors[] = 'Choose the purity from the purity list.';
        }
        if ($gross <= 0) {
            $errors[] = 'Give the gross weight of the piece to be made.';
        }
        if ($expectedDelivery !== '' && $assignedDate !== '' && $expectedDelivery < $assignedDate) {
            $errors[] = 'The piece cannot be due back before it was assigned.';
        }
    }

    if ($stone < 0) {
        $errors[] = 'A stone weight cannot be negative.';
    }
    if ($gross > 0 && $stone >= $gross) {
        $errors[] = 'The stones cannot weigh as much as the whole piece — check the two weights.';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'row' => [
            'assign_kind' => $kind,
            'category' => $category,
            'karigar_id' => $karigarId,
            'order_id' => $orderId,
            'order_line_id' => $orderLineId,
            'item_id' => $itemId,
            'purity_id' => $purityId,
            'unit_id' => $unitId,
            'size_design' => substr($sizeDesign, 0, 120),
            'expected_ornament' => substr($ornament, 0, 190),
            'expected_gross_weight' => $gross,
            'expected_stone_weight' => $stone,
            // Never typed, on either flow: the one figure that must always be
            // the other two, so it is worked out and not asked for.
            'expected_net_weight' => jw_round_weight($gross - $stone),
            'purity_fineness' => (float) ($orderLine['fineness'] ?? 0),
            'making_basis' => $basis,
            'making_rate' => round((float) ($input['making_rate'] ?? 0), 4),
            'assigned_date' => $assignedDate,
            'expected_delivery' => $expectedDelivery,
            'description' => substr(trim((string) ($input['description'] ?? '')), 0, 255),
        ],
    ];
}

/**
 * Save one assignment. No metal moves here and no voucher posts — handing the
 * gold over is its own act, on the issue screen, in as many instalments as the
 * shop likes.
 */
function jewellery_save_assignment(int $companyId, int $fiscalYearId, array $input, int $userId = 0): array
{
    $kind = (string) ($input['assign_kind'] ?? 'customer');
    $order = null;
    $orderLine = null;
    if ($kind === 'customer') {
        $orderId = (int) ($input['order_id'] ?? 0);
        if ($orderId > 0) {
            $orderStmt = db()->prepare("SELECT o.*, COALESCE(ap.name, o.customer_name, 'Walk-in customer') AS customer_label
                FROM jewellery_orders o
                LEFT JOIN accounting_parties ap ON ap.id = o.party_id
                WHERE o.id = :id AND o.company_id = :cid LIMIT 1");
            $orderStmt->execute(['id' => $orderId, 'cid' => $companyId]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $lineId = (int) ($input['order_line_id'] ?? 0);
        if ($lineId > 0) {
            $lineStmt = db()->prepare('SELECT l.*, i.name AS item_name, p.fineness
                FROM jewellery_order_lines l
                INNER JOIN inventory_items i ON i.id = l.item_id
                INNER JOIN jewellery_purities p ON p.id = l.purity_id
                WHERE l.id = :id AND l.company_id = :cid LIMIT 1');
            $lineStmt->execute(['id' => $lineId, 'cid' => $companyId]);
            $orderLine = $lineStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($orderLine && (int) ($orderLine['assignment_id'] ?? 0) > 0) {
                return ['ok' => false, 'errors' => ['That item is already out with a kaligad.'], 'id' => 0];
            }
        }
    }

    $checked = jewellery_assign_validate($kind, $input, $order, $orderLine);
    if (!$checked['ok']) {
        return ['ok' => false, 'errors' => $checked['errors'], 'id' => 0];
    }
    $row = $checked['row'];

    $karigar = jewellery_karigar($companyId, (int) $row['karigar_id']);
    if (!$karigar) {
        return ['ok' => false, 'errors' => ['That kaligad does not belong to this company.'], 'id' => 0];
    }
    if ($row['unit_id'] <= 0) {
        $settings = jewellery_settings($companyId);
        $row['unit_id'] = (int) ($settings['base_unit_id'] ?? 0);
    }
    if ($row['item_id'] <= 0 || $row['purity_id'] <= 0 || $row['unit_id'] <= 0) {
        return ['ok' => false, 'errors' => ['The piece needs an item, a purity and a unit before it can be assigned.'], 'id' => 0];
    }

    try {
        db()->beginTransaction();
        // voucher_no-style retry: assignment_no is UNIQUE per company, so two
        // counters saving in the same second means one loses and takes the next.
        $assignmentId = 0;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $assignmentNo = jewellery_assign_next_no($companyId, $fiscalYearId, (string) $row['assigned_date']);
            // The issue number is claimed at the same time so the metal, when
            // it is handed over, already has a document to belong to.
            $settings = jewellery_settings($companyId);
            $issueNo = jw_next_no($companyId, 'jewellery_order_assignments', 'issue_no',
                (string) ($settings['issue_no_prefix'] ?? 'JI'));
            try {
                $stmt = db()->prepare('INSERT INTO jewellery_order_assignments
                    (company_id, fiscal_year_id, order_id, order_line_id, karigar_id, issue_no, assignment_no,
                     assign_kind, category, size_design, expected_ornament,
                     expected_gross_weight, expected_stone_weight, expected_net_weight,
                     issue_date, expected_return_date, item_id, purity_id, unit_id,
                     issued_gross_weight, issued_fine_weight, issued_amount,
                     wastage_allowed_pct, making_basis, making_rate, status, notes, created_by)
                    VALUES
                    (:cid, :fy, :order_id, :order_line_id, :karigar_id, :issue_no, :assignment_no,
                     :assign_kind, :category, :size_design, :ornament,
                     :gross, :stone, :net,
                     :issue_date, :expected_return, :item_id, :purity_id, :unit_id,
                     0, 0, 0,
                     :wastage_pct, :making_basis, :making_rate, :status, :notes, :uid)');
                $stmt->execute([
                    'cid' => $companyId,
                    'fy' => $fiscalYearId ?: null,
                    'order_id' => $row['order_id'] > 0 ? $row['order_id'] : null,
                    'order_line_id' => $row['order_line_id'] > 0 ? $row['order_line_id'] : null,
                    'karigar_id' => $row['karigar_id'],
                    'issue_no' => $issueNo,
                    'assignment_no' => $assignmentNo,
                    'assign_kind' => $row['assign_kind'],
                    'category' => $row['category'],
                    'size_design' => $row['size_design'] !== '' ? $row['size_design'] : null,
                    'ornament' => $row['expected_ornament'] !== '' ? $row['expected_ornament'] : null,
                    'gross' => $row['expected_gross_weight'],
                    'stone' => $row['expected_stone_weight'],
                    'net' => $row['expected_net_weight'],
                    'issue_date' => $row['assigned_date'],
                    'expected_return' => $row['expected_delivery'] !== '' ? $row['expected_delivery'] : null,
                    'item_id' => $row['item_id'],
                    'purity_id' => $row['purity_id'],
                    'unit_id' => $row['unit_id'],
                    'wastage_pct' => (float) ($karigar['wastage_allowed_pct'] ?? 0),
                    'making_basis' => $row['making_basis'],
                    'making_rate' => $row['making_rate'],
                    'status' => 'issued',
                    'notes' => $row['description'] !== '' ? $row['description'] : null,
                    'uid' => $userId ?: null,
                ]);
                $assignmentId = (int) db()->lastInsertId();
                break;
            } catch (PDOException $duplicate) {
                if ((string) $duplicate->getCode() !== '23000' || $attempt === 4) {
                    throw $duplicate;
                }
            }
        }

        // The order line now knows which assignment covers it — the link the
        // workshop reads to tell an item that is out from one still waiting.
        if ($assignmentId > 0 && $row['order_line_id'] > 0) {
            db()->prepare('UPDATE jewellery_order_lines SET assignment_id = :aid, karigar_id = :kid
                WHERE id = :id AND company_id = :cid')
                ->execute([
                    'aid' => $assignmentId,
                    'kid' => $row['karigar_id'],
                    'id' => $row['order_line_id'],
                    'cid' => $companyId,
                ]);
        }
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'errors' => ['Could not save the assignment: ' . $exception->getMessage()], 'id' => 0];
    }

    // Outside the transaction: the order's own status follows from its lines,
    // and it reads them back.
    if ($row['order_id'] > 0) {
        jewellery_sync_order_status($companyId, (int) $row['order_id']);
    }
    log_activity('jewellery_assignment', $assignmentId, 'assigned',
        'Work assigned to kaligad (' . $row['assign_kind'] . ').', $userId ?: null);

    return ['ok' => true, 'errors' => [], 'id' => $assignmentId];
}

/**
 * The rows of one kind, in the order the sheet lists them, with everything the
 * grid shows already joined on.
 */
function jewellery_assign_rows(int $companyId, string $kind, array $filters = []): array
{
    $sql = "SELECT a.*, k.code AS karigar_code, k.name AS karigar_name,
            o.order_no, o.order_date, o.delivery_date AS order_promise_date,
            COALESCE(ap.name, o.customer_name, 'Walk-in customer') AS customer_name,
            i.sku AS item_code, i.name AS item_name,
            p.code AS purity_code, p.fineness, u.code AS unit_code,
            r.id AS receipt_id, r.receipt_no, r.status AS receipt_status
        FROM jewellery_order_assignments a
        INNER JOIN jewellery_karigars k ON k.id = a.karigar_id
        LEFT JOIN jewellery_orders o ON o.id = a.order_id
        LEFT JOIN accounting_parties ap ON ap.id = o.party_id
        LEFT JOIN inventory_items i ON i.id = a.item_id
        LEFT JOIN jewellery_purities p ON p.id = a.purity_id
        LEFT JOIN jewellery_units u ON u.id = a.unit_id
        LEFT JOIN jewellery_order_receipts r ON r.assignment_id = a.id
        WHERE a.company_id = :cid AND a.assign_kind = :kind";
    $params = ['cid' => $companyId, 'kind' => $kind === 'self' ? 'self' : 'customer'];

    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $sql .= ' AND (a.assignment_no LIKE :q OR a.issue_no LIKE :q OR k.name LIKE :q
                       OR o.order_no LIKE :q OR a.expected_ornament LIKE :q
                       OR ap.name LIKE :q OR o.customer_name LIKE :q)';
        $params['q'] = '%' . $search . '%';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['from'] ?? '')) === 1) {
        $sql .= ' AND a.issue_date >= :from';
        $params['from'] = $filters['from'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['to'] ?? '')) === 1) {
        $sql .= ' AND a.issue_date <= :to';
        $params['to'] = $filters['to'];
    }
    $sql .= ' ORDER BY a.issue_date DESC, a.id DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * One assignment row flattened into the columns of the sheet, for CSV, Excel
 * and PDF. The screen and the file carry the same columns in the same order,
 * so a printed sheet is the page.
 */
function jewellery_assign_export_rows(array $rows, string $kind, string $currency = ''): array
{
    $out = [];
    $serial = 0;
    foreach ($rows as $row) {
        $serial++;
        $line = ['SN' => $serial, 'Assignment Number' => (string) $row['assignment_no'], 'Kaligadh Name' => trim((string) $row['karigar_code'] . ' — ' . (string) $row['karigar_name'])];
        if ($kind === 'customer') {
            $line['Order Number'] = (string) ($row['order_no'] ?? '');
            $line['Customer Name'] = (string) ($row['customer_name'] ?? '');
        }
        $line['Size/Design'] = (string) ($row['size_design'] ?? '');
        $line['Expected Ornament'] = (string) ($row['expected_ornament'] ?? $row['item_name'] ?? '');
        $line['Category'] = jewellery_assign_categories()[(string) $row['category']] ?? (string) $row['category'];
        $line['Gross Weight'] = number_format((float) $row['expected_gross_weight'], 4, '.', '');
        $line['Stone / Diamond'] = number_format((float) $row['expected_stone_weight'], 4, '.', '');
        $line['Net Weight'] = number_format((float) $row['expected_net_weight'], 4, '.', '');
        $line['Purity'] = (string) ($row['purity_code'] ?? '');
        $line['Making Charge'] = jewellery_assign_making_charge_label($row, $currency);
        $line['Assigned Date'] = app_date((string) $row['issue_date']);
        $line['Expected Delivery'] = $row['expected_return_date'] ? app_date((string) $row['expected_return_date']) : '';
        $line['Description'] = (string) ($row['notes'] ?? '');
        $out[] = $line;
    }

    return $out;
}

/**
 * Pieces made for the showroom that have come back and are now sellable.
 *
 * The mirror of Ready to Deliver. A customer's piece comes back and waits for
 * the person who ordered it; a showroom piece comes back and waits for whoever
 * walks in — so it belongs on the shelf, not in a collection queue, and this is
 * the list the counter checks to know what is newly there.
 *
 * There is no "sold" flag to filter on, and there should not be: once the metal
 * is in stock it is stock, and which physical chain leaves the case is the
 * stock ledger's business, not this board's. The date filter is what keeps the
 * list to the recent work.
 */
function jewellery_ready_to_sale(int $companyId, array $filters = []): array
{
    $sql = "SELECT a.id, a.assignment_no, a.issue_no, a.category, a.size_design, a.expected_ornament,
            a.expected_gross_weight, a.expected_net_weight,
            k.code AS karigar_code, k.name AS karigar_name,
            r.id AS receipt_id, r.receipt_no, r.receive_date, r.received_gross_weight,
            r.stone_weight, r.net_gold_weight, r.received_fine_weight, r.making_amount,
            r.net_payable, r.status AS receipt_status,
            i.sku AS item_code, i.name AS item_name,
            p.code AS purity_code, u.code AS unit_code,
            DATEDIFF(CURDATE(), r.receive_date) AS days_on_shelf
        FROM jewellery_order_assignments a
        INNER JOIN jewellery_karigars k ON k.id = a.karigar_id
        INNER JOIN jewellery_order_receipts r ON r.assignment_id = a.id
        LEFT JOIN inventory_items i ON i.id = r.received_item_id
        LEFT JOIN jewellery_purities p ON p.id = r.received_purity_id
        LEFT JOIN jewellery_units u ON u.id = r.unit_id
        WHERE a.company_id = :cid AND a.assign_kind = 'self'
          AND a.status = 'received' AND r.status <> 'cancelled'";
    $params = ['cid' => $companyId];

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['from'] ?? '')) === 1) {
        $sql .= ' AND r.receive_date >= :from';
        $params['from'] = $filters['from'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['to'] ?? '')) === 1) {
        $sql .= ' AND r.receive_date <= :to';
        $params['to'] = $filters['to'];
    }
    $sql .= ' ORDER BY r.receive_date DESC, r.id DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Every piece the workshop has produced, both kinds together, each carrying a
 * remark that says where it is going.
 *
 * The two flows part company everywhere else on purpose — different screens,
 * different fields, different rules. This is the one place they belong in one
 * list, because the question it answers is about the shop's output as a whole:
 * what did the kaligads make this month, and what happened to it.
 */
function jewellery_workshop_output(int $companyId, array $filters = []): array
{
    $sql = "SELECT a.assignment_no, a.assign_kind, a.category, a.size_design, a.expected_ornament,
            k.code AS karigar_code, k.name AS karigar_name,
            o.order_no, o.status AS order_status,
            COALESCE(ap.name, o.customer_name, '') AS customer_name,
            r.receipt_no, r.receive_date, r.received_gross_weight, r.stone_weight,
            r.net_gold_weight, r.received_fine_weight, r.wastage_fine_weight,
            r.excess_wastage_fine, r.making_amount, r.net_payable, r.status AS receipt_status,
            i.sku AS item_code, i.name AS item_name,
            p.code AS purity_code, u.code AS unit_code
        FROM jewellery_order_assignments a
        INNER JOIN jewellery_karigars k ON k.id = a.karigar_id
        INNER JOIN jewellery_order_receipts r ON r.assignment_id = a.id
        LEFT JOIN jewellery_orders o ON o.id = a.order_id
        LEFT JOIN accounting_parties ap ON ap.id = o.party_id
        LEFT JOIN inventory_items i ON i.id = r.received_item_id
        LEFT JOIN jewellery_purities p ON p.id = r.received_purity_id
        LEFT JOIN jewellery_units u ON u.id = r.unit_id
        WHERE a.company_id = :cid AND r.status <> 'cancelled'";
    $params = ['cid' => $companyId];

    $kind = (string) ($filters['kind'] ?? '');
    if (in_array($kind, ['customer', 'self'], true)) {
        $sql .= ' AND a.assign_kind = :kind';
        $params['kind'] = $kind;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['from'] ?? '')) === 1) {
        $sql .= ' AND r.receive_date >= :from';
        $params['from'] = $filters['from'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['to'] ?? '')) === 1) {
        $sql .= ' AND r.receive_date <= :to';
        $params['to'] = $filters['to'];
    }
    $sql .= ' ORDER BY r.receive_date DESC, r.id DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $index => $row) {
        $rows[$index]['remark'] = jewellery_output_remark($row);
    }

    return $rows;
}

/**
 * Where a finished piece is going, in one line a person can read.
 *
 * A customer's piece is going to the customer, and the order's own status says
 * whether it still is: delivered means it has gone, anything else means it is
 * waiting to be collected. A showroom piece is going on the shelf.
 */
function jewellery_output_remark(array $row): string
{
    if ((string) ($row['assign_kind'] ?? '') !== 'self') {
        $orderNo = (string) ($row['order_no'] ?? '');
        $customer = trim((string) ($row['customer_name'] ?? ''));
        $who = $customer !== '' ? ' for ' . $customer : '';
        $status = (string) ($row['order_status'] ?? '');
        if (in_array($status, ['delivered', 'invoiced', 'closed'], true)) {
            return 'Customer order ' . $orderNo . $who . ' — delivered';
        }

        return 'Customer order ' . $orderNo . $who . ' — ready to deliver';
    }

    return 'Self order — ready to sale, showroom stock replenishment';
}

/** The workshop-output register flattened for CSV, Excel and PDF. */
function jewellery_output_export_rows(array $rows, string $currency = ''): array
{
    $out = [];
    $serial = 0;
    foreach ($rows as $row) {
        $serial++;
        $out[] = [
            'SN' => $serial,
            'Assignment Number' => (string) $row['assignment_no'],
            'Kind' => (string) $row['assign_kind'] === 'self' ? 'Self ordered' : 'Customer ordered',
            'Kaligadh Name' => trim((string) $row['karigar_code'] . ' — ' . (string) $row['karigar_name']),
            'Receipt No' => (string) ($row['receipt_no'] ?? ''),
            'Received On' => app_date((string) ($row['receive_date'] ?? '')),
            'Ornament' => (string) ($row['expected_ornament'] ?: $row['item_name'] ?? ''),
            'Size/Design' => (string) ($row['size_design'] ?? ''),
            'Category' => jewellery_assign_categories()[(string) $row['category']] ?? (string) $row['category'],
            'Gross Weight' => number_format((float) ($row['received_gross_weight'] ?? 0), 4, '.', ''),
            'Stone / Diamond' => number_format((float) ($row['stone_weight'] ?? 0), 4, '.', ''),
            'Net Weight' => number_format((float) ($row['net_gold_weight'] ?? 0), 4, '.', ''),
            'Fine Weight' => number_format((float) ($row['received_fine_weight'] ?? 0), 4, '.', ''),
            'Purity' => (string) ($row['purity_code'] ?? ''),
            'Wastage (fine)' => number_format((float) ($row['wastage_fine_weight'] ?? 0), 4, '.', ''),
            'Making Charge' => $currency . number_format((float) ($row['making_amount'] ?? 0), 2),
            'Remarks' => (string) ($row['remark'] ?? ''),
        ];
    }

    return $out;
}

/** "Flat Rs 2,500" / "8% of metal" / "Rs 250 per gram" — the charge in words. */
function jewellery_assign_making_charge_label(array $row, string $currency = ''): string
{
    $rate = (float) ($row['making_rate'] ?? 0);
    if ($rate <= 0) {
        return '—';
    }

    return match ((string) ($row['making_basis'] ?? 'flat')) {
        'percent_of_metal' => rtrim(rtrim(number_format($rate, 2), '0'), '.') . '% of metal',
        'per_unit_weight' => $currency . number_format($rate, 2) . ' per ' . (string) ($row['unit_code'] ?? 'unit'),
        default => 'Flat ' . $currency . number_format($rate, 2),
    };
}
