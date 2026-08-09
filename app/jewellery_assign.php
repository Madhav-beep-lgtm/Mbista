<?php
declare(strict_types=1);

require_once __DIR__ . '/jewellery_workshop.php';
// voucher_input_row()/voucher_input_rows() read a grid's parallel arrays. They
// are named for where they were written, not for what they know — there is
// nothing about a voucher in them, and a second copy here would be a second
// thing to keep right.
require_once __DIR__ . '/voucher_types.php';

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

    // A batch of rows saves under ONE transaction, so a bad fifth row cannot
    // leave four assignments behind. When the caller owns the transaction, it
    // owns the rollback too.
    $ownsTransaction = !db()->inTransaction();
    try {
        if ($ownsTransaction) {
            db()->beginTransaction();
        }
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
        if ($ownsTransaction) {
            db()->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'errors' => ['Could not save the assignment: ' . $exception->getMessage()], 'id' => 0, 'order_id' => 0];
    }

    // The order's own status follows from its lines and reads them back, so it
    // waits for the commit. In a batch the caller owns that, and syncs after.
    if ($row['order_id'] > 0 && $ownsTransaction) {
        jewellery_sync_order_status($companyId, (int) $row['order_id']);
    }
    log_activity('jewellery_assignment', $assignmentId, 'assigned',
        'Work assigned to kaligad (' . $row['assign_kind'] . ').', $userId ?: null);

    return ['ok' => true, 'errors' => [], 'id' => $assignmentId, 'order_id' => (int) $row['order_id']];
}

/**
 * Save a whole grid of assignments — all of them, or none.
 *
 * The sheet is a table and the screen is a table, so a counter handing out five
 * pieces on a Sunday morning types five rows and saves once. Which makes the
 * failure mode the important part: a bad fifth row must not leave four
 * assignments behind and a half-corrected grid on screen. So every row is
 * checked first, and only if they all pass does anything get written, inside
 * one transaction.
 *
 * Rows left wholly blank are skipped, not complained about — a grid that opens
 * with three rows should not demand three assignments.
 */
function jewellery_save_assignments(int $companyId, int $fiscalYearId, string $kind, array $input, int $userId = 0): array
{
    $kind = $kind === 'self' ? 'self' : 'customer';
    $rowCount = voucher_input_rows($input, 'karigar_id');
    $rows = [];
    $errors = [];
    $claimedLines = [];

    for ($index = 0; $index < $rowCount; $index++) {
        $row = [
            'assign_kind' => $kind,
            'karigar_id' => (int) voucher_input_row($input, 'karigar_id', $index),
            'order_id' => (int) voucher_input_row($input, 'order_id', $index),
            'order_line_id' => (int) voucher_input_row($input, 'order_line_id', $index),
            'item_id' => (int) voucher_input_row($input, 'item_id', $index),
            'purity_id' => (int) voucher_input_row($input, 'purity_id', $index),
            'unit_id' => (int) voucher_input_row($input, 'unit_id', $index),
            'category' => voucher_input_row($input, 'category', $index),
            'size_design' => voucher_input_row($input, 'size_design', $index),
            'expected_ornament' => voucher_input_row($input, 'expected_ornament', $index),
            'expected_gross_weight' => (float) voucher_input_row($input, 'expected_gross_weight', $index),
            'expected_stone_weight' => (float) voucher_input_row($input, 'expected_stone_weight', $index),
            'making_basis' => voucher_input_row($input, 'making_basis', $index),
            'making_rate' => (float) voucher_input_row($input, 'making_rate', $index),
            'assigned_date' => voucher_input_row($input, 'assigned_date', $index),
            'expected_delivery' => voucher_input_row($input, 'expected_delivery', $index),
            'description' => voucher_input_row($input, 'description', $index),
        ];
        // An untouched row is not an error. Only a row somebody started is.
        $started = $row['karigar_id'] > 0 || $row['order_line_id'] > 0 || $row['item_id'] > 0
            || $row['expected_gross_weight'] > 0 || $row['size_design'] !== '' || $row['expected_ornament'] !== '';
        if (!$started) {
            continue;
        }

        // The same ornament cannot go to two kaligads because it appears twice
        // in the grid. The database would catch it on the second save; the
        // person should hear it before anything is written.
        if ($row['order_line_id'] > 0) {
            if (isset($claimedLines[$row['order_line_id']])) {
                $errors[] = 'Row ' . ($index + 1) . ': that item is already on row ' . $claimedLines[$row['order_line_id']] . ' of this grid.';
                continue;
            }
            $claimedLines[$row['order_line_id']] = $index + 1;
        }
        $rows[] = ['line' => $index + 1, 'row' => $row];
    }

    if ($rows === [] && $errors === []) {
        return ['ok' => false, 'saved' => 0, 'errors' => ['Fill at least one row before saving.']];
    }

    // Pass one: check every row against the database, writing nothing.
    foreach ($rows as $entry) {
        $checked = jewellery_assign_dry_run($companyId, $kind, $entry['row']);
        foreach ($checked as $problem) {
            $errors[] = 'Row ' . $entry['line'] . ': ' . $problem;
        }
    }
    if ($errors !== []) {
        return ['ok' => false, 'saved' => 0, 'errors' => $errors];
    }

    // Pass two: write them, all under one transaction.
    $saved = 0;
    $touchedOrders = [];
    try {
        db()->beginTransaction();
        foreach ($rows as $entry) {
            $result = jewellery_save_assignment($companyId, $fiscalYearId, $entry['row'], $userId);
            if (!$result['ok']) {
                throw new RuntimeException('Row ' . $entry['line'] . ': ' . implode(' ', $result['errors']));
            }
            $saved++;
            if ((int) ($result['order_id'] ?? 0) > 0) {
                $touchedOrders[(int) $result['order_id']] = true;
            }
        }
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'saved' => 0, 'errors' => [$exception->getMessage() . ' Nothing was saved.']];
    }

    // After the commit, because an order's status is read back off its lines.
    foreach (array_keys($touchedOrders) as $orderId) {
        jewellery_sync_order_status($companyId, $orderId);
    }

    return ['ok' => true, 'saved' => $saved, 'errors' => []];
}

/**
 * Everything wrong with one row, without writing anything.
 *
 * The same lookups the save does, so what passes here passes there — the point
 * of checking first is that the answer must not change between the two.
 */
function jewellery_assign_dry_run(int $companyId, string $kind, array $row): array
{
    $order = null;
    $orderLine = null;
    if ($kind === 'customer') {
        $orderId = (int) ($row['order_id'] ?? 0);
        if ($orderId > 0) {
            $stmt = db()->prepare('SELECT * FROM jewellery_orders WHERE id = :id AND company_id = :cid LIMIT 1');
            $stmt->execute(['id' => $orderId, 'cid' => $companyId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $lineId = (int) ($row['order_line_id'] ?? 0);
        if ($lineId > 0) {
            $stmt = db()->prepare('SELECT l.*, i.name AS item_name, p.fineness
                FROM jewellery_order_lines l
                INNER JOIN inventory_items i ON i.id = l.item_id
                INNER JOIN jewellery_purities p ON p.id = l.purity_id
                WHERE l.id = :id AND l.company_id = :cid LIMIT 1');
            $stmt->execute(['id' => $lineId, 'cid' => $companyId]);
            $orderLine = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($orderLine && (int) ($orderLine['assignment_id'] ?? 0) > 0) {
                return ['that item is already out with a kaligad.'];
            }
        }
    }

    $checked = jewellery_assign_validate($kind, $row, $order, $orderLine);
    if (!$checked['ok']) {
        return $checked['errors'];
    }
    if (!jewellery_karigar($companyId, (int) $checked['row']['karigar_id'])) {
        return ['that kaligad does not belong to this company.'];
    }

    return [];
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
 * Server-authoritative edit level for one assignment.
 *
 * The counts deliberately cover both the header links and their underlying
 * records. A stale zero on the header must never unlock an assignment whose
 * stock movement, component, voucher or receipt still exists.
 */
function jewellery_assignment_edit_state(int $companyId, int $assignmentId, bool $lock = false): array
{
    $sql = 'SELECT a.*, k.code AS karigar_code, k.name AS karigar_name,
            o.order_no, o.delivery_date AS order_delivery_date,
            COALESCE(ap.name, o.customer_name, \'Walk-in customer\') AS customer_name,
            i.sku AS item_code, i.name AS item_name, p.code AS purity_code, u.code AS unit_code
        FROM jewellery_order_assignments a
        INNER JOIN jewellery_karigars k ON k.id = a.karigar_id AND k.company_id = a.company_id
        LEFT JOIN jewellery_orders o ON o.id = a.order_id AND o.company_id = a.company_id
        LEFT JOIN accounting_parties ap ON ap.id = o.party_id
        INNER JOIN inventory_items i ON i.id = a.item_id AND i.company_id = a.company_id
        INNER JOIN jewellery_purities p ON p.id = a.purity_id AND p.company_id = a.company_id
        INNER JOIN jewellery_units u ON u.id = a.unit_id AND u.company_id = a.company_id
        WHERE a.id = :id AND a.company_id = :cid LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
    $stmt = db()->prepare($sql);
    $stmt->execute(['id' => $assignmentId, 'cid' => $companyId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$assignment) {
        return ['found' => false, 'level' => 'none', 'assignment' => null, 'has_movement' => false];
    }

    $count = static function (string $sql, array $params): int {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    };
    $params = ['id' => $assignmentId, 'cid' => $companyId];
    $componentCount = table_exists('jewellery_assignment_components')
        ? $count('SELECT COUNT(*) FROM jewellery_assignment_components WHERE assignment_id = :id AND company_id = :cid', $params)
        : 0;
    $stockCount = table_exists('jewellery_stock_txns')
        ? $count("SELECT COUNT(*) FROM jewellery_stock_txns WHERE company_id = :cid AND source_id = :id AND source_type = 'jewellery_karigar_issue'", $params)
        : 0;
    $receiptCount = table_exists('jewellery_order_receipts')
        ? $count('SELECT COUNT(*) FROM jewellery_order_receipts WHERE assignment_id = :id AND company_id = :cid', $params)
        : 0;
    $voucherCount = (int) ($assignment['issue_voucher_id'] ?? 0) > 0 ? 1 : 0;
    if ($voucherCount === 0 && table_exists('vouchers')) {
        $voucherCount = $count("SELECT COUNT(*) FROM vouchers WHERE company_id = :cid AND source_id = :id AND source_type = 'jewellery_karigar_issue'", $params);
    }
    $weightsMoved = abs((float) ($assignment['issued_gross_weight'] ?? 0)) > 0.00005
        || abs((float) ($assignment['issued_fine_weight'] ?? 0)) > 0.00005
        || abs((float) ($assignment['issued_amount'] ?? 0)) > 0.005
        || abs((float) ($assignment['issued_stone_carat'] ?? 0)) > 0.00005
        || abs((float) ($assignment['issued_stone_amount'] ?? 0)) > 0.005
        || (int) ($assignment['issue_stock_txn_out'] ?? 0) > 0
        || (int) ($assignment['issue_stock_txn_in'] ?? 0) > 0;
    $hasMovement = $weightsMoved || $componentCount > 0 || $stockCount > 0 || $voucherCount > 0;
    $status = (string) $assignment['status'];
    $level = $status === 'issued' && $receiptCount === 0
        ? ($hasMovement ? 'limited' : 'full')
        : 'readonly';

    return ['found' => true, 'level' => $level, 'assignment' => $assignment,
        'has_movement' => $hasMovement, 'component_count' => $componentCount,
        'stock_count' => $stockCount, 'voucher_count' => $voucherCount,
        'receipt_count' => $receiptCount];
}

/** Update an assignment without touching any stock, voucher or receipt. */
function jewellery_update_assignment(int $companyId, int $assignmentId, array $input, int $userId = 0): array
{
    $orderIdsToSync = [];
    try {
        db()->beginTransaction();
        $state = jewellery_assignment_edit_state($companyId, $assignmentId, true);
        if (!$state['found']) {
            throw new RuntimeException('Assignment not found for this company.');
        }
        if ($state['level'] === 'readonly') {
            throw new RuntimeException('A received or cancelled assignment is read-only.');
        }
        $old = $state['assignment'];
        $updates = [
            'expected_return_date' => trim((string) ($input['expected_delivery'] ?? '')) ?: null,
            'notes' => substr(trim((string) ($input['description'] ?? '')), 0, 255) ?: null,
        ];
        if ($updates['expected_return_date'] !== null
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $updates['expected_return_date']) !== 1) {
            throw new RuntimeException('Expected delivery must be a valid date.');
        }
        if ($updates['expected_return_date'] !== null
            && $updates['expected_return_date'] < (string) $old['issue_date']) {
            throw new RuntimeException('Expected delivery cannot be before the assigned date.');
        }
        if ((string) $old['assign_kind'] === 'customer' && $updates['expected_return_date'] !== null
            && (string) ($old['order_delivery_date'] ?? '') !== ''
            && $updates['expected_return_date'] > (string) $old['order_delivery_date']) {
            throw new RuntimeException('Expected delivery cannot be after the date promised to the customer.');
        }

        if ($state['level'] === 'full') {
            $kind = (string) $old['assign_kind'];
            $karigar = jewellery_karigar($companyId, (int) ($input['karigar_id'] ?? 0));
            if (!$karigar || (string) ($karigar['status'] ?? '') !== 'active') {
                throw new RuntimeException('Select an active kaligad from this company.');
            }
            $order = null;
            $line = null;
            if ($kind === 'customer') {
                $orderId = (int) $old['order_id'];
                $orderStmt = db()->prepare('SELECT * FROM jewellery_orders WHERE id = :id AND company_id = :cid LIMIT 1');
                $orderStmt->execute(['id' => $orderId, 'cid' => $companyId]);
                $order = $orderStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                $lineStmt = db()->prepare('SELECT l.*, i.name AS item_name, p.fineness
                    FROM jewellery_order_lines l
                    INNER JOIN inventory_items i ON i.id = l.item_id AND i.company_id = l.company_id
                    INNER JOIN jewellery_purities p ON p.id = l.purity_id AND p.company_id = l.company_id
                    WHERE l.id = :id AND l.order_id = :oid AND l.company_id = :cid LIMIT 1 FOR UPDATE');
                $lineStmt->execute(['id' => (int) ($input['order_line_id'] ?? 0), 'oid' => $orderId, 'cid' => $companyId]);
                $line = $lineStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$line) {
                    throw new RuntimeException('The selected item does not belong to this order.');
                }
                if ((int) ($line['assignment_id'] ?? 0) > 0 && (int) $line['assignment_id'] !== $assignmentId) {
                    throw new RuntimeException('That order item is already linked to another assignment.');
                }
            } else {
                $itemStmt = db()->prepare("SELECT i.id FROM inventory_items i
                    INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
                    WHERE i.id = :id AND i.company_id = :cid AND i.status = 'active' LIMIT 1");
                $itemStmt->execute(['id' => (int) ($input['item_id'] ?? 0), 'cid' => $companyId]);
                if (!$itemStmt->fetchColumn()) {
                    throw new RuntimeException('Select an active finished-stock item from this company.');
                }
            }
            $checked = jewellery_assign_validate($kind, ['assign_kind' => $kind] + $input, $order, $line);
            if (!$checked['ok']) {
                throw new RuntimeException(implode(' ', $checked['errors']));
            }
            $row = $checked['row'];
            $updates += [
                'karigar_id' => (int) $row['karigar_id'], 'order_line_id' => $row['order_line_id'] ?: null,
                'item_id' => (int) $row['item_id'], 'purity_id' => (int) $row['purity_id'],
                'unit_id' => (int) $row['unit_id'], 'category' => $row['category'],
                'size_design' => $row['size_design'] ?: null,
                'expected_ornament' => $row['expected_ornament'] ?: null,
                'expected_gross_weight' => $row['expected_gross_weight'],
                'expected_stone_weight' => $row['expected_stone_weight'],
                'expected_net_weight' => $row['expected_net_weight'],
                'making_basis' => $row['making_basis'], 'making_rate' => $row['making_rate'],
                'issue_date' => $row['assigned_date'],
            ];
            if ($kind === 'customer') {
                $oldLineId = (int) ($old['order_line_id'] ?? 0);
                $newLineId = (int) $row['order_line_id'];
                if ($oldLineId > 0 && $oldLineId !== $newLineId) {
                    db()->prepare('UPDATE jewellery_order_lines SET assignment_id = NULL, karigar_id = NULL
                        WHERE id = :id AND company_id = :cid AND assignment_id = :aid')
                        ->execute(['id' => $oldLineId, 'cid' => $companyId, 'aid' => $assignmentId]);
                }
                db()->prepare('UPDATE jewellery_order_lines SET assignment_id = :aid, karigar_id = :kid
                    WHERE id = :id AND order_id = :oid AND company_id = :cid')
                    ->execute(['aid' => $assignmentId, 'kid' => $row['karigar_id'], 'id' => $newLineId,
                        'oid' => (int) $old['order_id'], 'cid' => $companyId]);
                $orderIdsToSync[] = (int) $old['order_id'];
            }
        }

        $changed = [];
        foreach ($updates as $field => $value) {
            if ((string) ($old[$field] ?? '') !== (string) ($value ?? '')) {
                $changed[$field] = $value;
            }
        }
        if ($changed !== []) {
            $sets = [];
            $params = ['id' => $assignmentId, 'cid' => $companyId];
            foreach ($changed as $field => $value) {
                $sets[] = "`$field` = :$field";
                $params[$field] = $value;
            }
            db()->prepare('UPDATE jewellery_order_assignments SET ' . implode(', ', $sets)
                . ' WHERE id = :id AND company_id = :cid')->execute($params);
            log_field_changes('jewellery_assignment', $assignmentId, $old, $changed + $old, $companyId, $userId ?: null);
            $auditPairs = [];
            foreach ($changed as $field => $newValue) {
                $auditPairs[] = $field . ': ' . (string) ($old[$field] ?? '') . ' -> ' . (string) ($newValue ?? '');
            }
            log_activity('jewellery_assignment', $assignmentId, 'updated',
                'Assignment ' . $old['assignment_no'] . ' updated. ' . implode('; ', $auditPairs) . '.', $userId ?: null);
        }
        db()->commit();
        foreach (array_unique($orderIdsToSync) as $orderId) {
            jewellery_sync_order_status($companyId, $orderId);
        }
        return ['ok' => true, 'error' => '', 'changed' => array_keys($changed)];
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return ['ok' => false, 'error' => $exception->getMessage(), 'changed' => []];
    }
}

/** Cancel an untouched assignment and release its order line. */
function jewellery_unassign_assignment(int $companyId, int $assignmentId, string $reason, int $userId = 0): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Enter a reason for removing the assignment.'];
    }
    $orderId = 0;
    try {
        db()->beginTransaction();
        $state = jewellery_assignment_edit_state($companyId, $assignmentId, true);
        if (!$state['found'] || $state['level'] !== 'full') {
            throw new RuntimeException('This assignment can no longer be removed because metal, accounting, or receipt history is linked to it.');
        }
        $assignment = $state['assignment'];
        $orderId = (int) ($assignment['order_id'] ?? 0);
        db()->prepare('UPDATE jewellery_order_lines SET assignment_id = NULL, karigar_id = NULL
            WHERE assignment_id = :aid AND company_id = :cid')
            ->execute(['aid' => $assignmentId, 'cid' => $companyId]);
        db()->prepare("UPDATE jewellery_order_assignments SET status = 'cancelled', notes = :notes
            WHERE id = :id AND company_id = :cid")
            ->execute(['notes' => trim((string) ($assignment['notes'] ?? '') . "\nRemoval reason: " . substr($reason, 0, 180)),
                'id' => $assignmentId, 'cid' => $companyId]);
        log_activity('jewellery_assignment', $assignmentId, 'unassigned',
            'Assignment ' . $assignment['assignment_no'] . ' removed. Reason: ' . substr($reason, 0, 180), $userId ?: null);
        log_field_changes('jewellery_assignment', $assignmentId, ['status' => $assignment['status']], ['status' => 'cancelled'], $companyId, $userId ?: null);
        db()->commit();
        if ($orderId > 0) {
            jewellery_sync_order_status($companyId, $orderId);
        }
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return ['ok' => false, 'error' => $exception->getMessage()];
    }
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

// ---------------------------------------------------------------------------
// Issue components — one issue, several things in the kaligad's hand
// ---------------------------------------------------------------------------

// jewellery_assignment_components() and jewellery_component_totals() used to
// live here, beside the function that WRITES a component. They now sit in
// jewellery_workshop.php beside jewellery_assignment(), because the receipt has
// to read them to hand the kaligad's holding back — and this file requires
// that one, so nothing there can call anything here.

/**
 * Hand one thing over on an issue — a bar of gold, or a packet of diamonds.
 *
 * The metal path is the existing instalment issue, unchanged: the stock moves,
 * a voucher hangs off the movement, and the header's issued fine grows. What is
 * new is that the item, purity and unit come from the COMPONENT rather than
 * from the assignment header, so one issue can carry gold at 22K and diamonds
 * in carats without either pretending to be the other.
 *
 * A stone carries no fine weight, and its carats never touch issued_fine_weight
 * — see migration 103. It totals into the header's own stone columns instead.
 */
function jewellery_issue_component(int $companyId, int $fiscalYearId, int $assignmentId, array $input, int $userId = 0): array
{
    if (!table_exists('jewellery_assignment_components')) {
        return ['ok' => false, 'error' => 'This database has not been upgraded to carry issue components yet. Run the accounting repair first.'];
    }
    $assignment = jewellery_assignment($companyId, $assignmentId);
    if (!$assignment) {
        return ['ok' => false, 'error' => 'Assignment not found for this company.'];
    }
    if ((string) $assignment['status'] !== 'issued') {
        return ['ok' => false, 'error' => 'This assignment has already been received or cancelled — nothing more can be handed over on it.'];
    }

    $item = jewellery_item($companyId, (int) ($input['item_id'] ?? 0));
    if (!$item) {
        return ['ok' => false, 'error' => 'Choose an item that belongs to this company.'];
    }
    $karigar = jewellery_karigar($companyId, (int) $assignment['karigar_id']);
    if (!$karigar) {
        return ['ok' => false, 'error' => 'That assignment points at a kaligad this company does not have.'];
    }

    // The masters already know a diamond from a bar of gold. Asking the person
    // as well would only let the two disagree.
    $isStone = (string) ($item['metal_kind'] ?? 'metal') === 'stone';
    $purityId = (int) ($input['purity_id'] ?? 0) ?: (int) ($item['purity_id'] ?? 0);
    $purity = jewellery_purity($companyId, $purityId);
    if (!$purity) {
        return ['ok' => false, 'error' => 'Choose the purity this is being handed over at.'];
    }
    if ((int) $purity['metal_id'] !== (int) ($item['metal_id'] ?? 0)) {
        return ['ok' => false, 'error' => 'The purity must belong to the item\'s own metal.'];
    }
    $unitId = (int) ($input['unit_id'] ?? 0) ?: (int) ($item['unit_id'] ?? 0);
    $unit = jewellery_unit($companyId, $unitId);
    if (!$unit) {
        return ['ok' => false, 'error' => 'Choose the unit this is weighed in.'];
    }

    $gross = jw_round_weight((float) ($input['gross_weight'] ?? 0));
    if ($gross <= 0) {
        return ['ok' => false, 'error' => 'Enter the weight being handed over.'];
    }
    // Stones are weighed in carats; the carat figure is kept beside the weight
    // so what went out and what comes back can be compared in one unit.
    $carat = $isStone ? jw_round_weight($gross * ((float) $unit['grams'] / 0.2)) : 0.0;

    // TWO fine weights, and the difference is the whole point.
    //
    // The STOCK ledger gets the natural one. A stone's purity is the masters'
    // standard 1000, so its fine equals its weight — and it has to, or issuing
    // a diamond out would leave the diamond item's own balance overstated by
    // exactly what left the safe.
    //
    // The ASSIGNMENT HEADER gets fine gold only. issued_fine_weight is the base
    // every wastage calculation reads, and stones do not waste: counting them
    // would credit the kaligad with pure metal he never held.
    $stockFine = jw_fine_weight($gross, (float) $purity['fineness']);
    $fine = $isStone ? 0.0 : $stockFine;

    $issueDate = (string) ($input['issue_date'] ?? date('Y-m-d'));
    $balance = jw_item_balance($companyId, (int) $item['id'], $issueDate, '');
    // Valued the same way for both, on the stock fine — which for a stone is
    // its carats, so the average rate is a rate per carat.
    $amount = jw_round_money($stockFine * (float) $balance['avg_fine_rate']);

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        $no = (string) $assignment['issue_no'];
        $common = [
            'item_id' => (int) $item['id'], 'txn_type' => 'issue_karigar', 'txn_date' => $issueDate, 'ref_no' => $no,
            'purity_id' => $purityId, 'unit_id' => $unitId,
            'gross_weight' => $gross, 'fine_weight' => $stockFine, 'amount' => $amount,
            'source_type' => 'jewellery_karigar_issue', 'source_id' => $assignmentId,
            'voucher_id' => null, 'created_by' => $userId,
        ];
        $outId = jw_record_stock_txn($companyId, $common + ['direction' => 'out', 'holder_type' => 'stock']);
        $inId = jw_record_stock_txn($companyId, $common + ['direction' => 'in',
            'holder_type' => 'karigar', 'holder_id' => (int) $assignment['karigar_id']]);

        // Same posting as any other hand-over: the value moves from own stock
        // to what the kaligad is holding. Sourced on the OUT movement, which is
        // unique per component — the assignment id is already claimed by the
        // first instalment's voucher.
        $voucherId = null;
        $karigarLedgerId = jw_karigar_metal_ledger_id($companyId, $karigar);
        $ownStockLedgerId = jw_item_stock_ledger_id($companyId, $item);
        if ($amount > 0 && $karigarLedgerId > 0 && $ownStockLedgerId > 0) {
            $entries = jw_build_entries([
                ['ledger_id' => $karigarLedgerId, 'amount' => $amount, 'memo' => ($isStone ? 'Stones' : 'Metal') . ' with ' . $karigar['code']],
                ['ledger_id' => $ownStockLedgerId, 'amount' => -$amount, 'memo' => 'Issued to kaligad ' . $karigar['code']],
            ]);
            if ($entries !== []) {
                $voucherId = create_voucher_with_entries([
                    'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                    'voucher_no' => $no . '/' . $outId,
                    'voucher_type' => 'journal', 'voucher_date' => $issueDate,
                    'source_type' => 'jewellery_karigar_issue_add', 'source_id' => $outId,
                    'party_id' => (int) ($karigar['party_id'] ?? 0) ?: null,
                    'narration' => ($isStone ? 'Stones' : 'Metal') . ' issued to kaligad ' . $karigar['name'] . ' (' . $no . ')',
                    'total_amount' => $amount, 'status' => 'posted', 'posted_by' => $userId ?: null,
                ], $entries);
                jw_link_stock_txn_voucher($companyId, [$outId, $inId], $voucherId);
            }
        }

        db()->prepare('INSERT INTO jewellery_assignment_components
                (company_id, assignment_id, item_id, component_kind, purity_id, unit_id,
                 gross_weight, fine_weight, qty_carat, rate, amount, issue_date,
                 stock_txn_out, stock_txn_in, voucher_id, notes, created_by)
                VALUES (:cid, :aid, :item, :kind, :purity, :unit,
                 :gross, :fine, :carat, :rate, :amount, :idate, :o, :i, :v, :notes, :uid)')
            ->execute([
                'cid' => $companyId, 'aid' => $assignmentId, 'item' => (int) $item['id'],
                'kind' => $isStone ? 'stone' : 'metal', 'purity' => $purityId, 'unit' => $unitId,
                'gross' => $gross, 'fine' => $fine, 'carat' => $carat,
                'rate' => $gross > 0 ? jw_round_rate($amount / $gross) : 0.0, 'amount' => $amount,
                'idate' => $issueDate, 'o' => $outId, 'i' => $inId, 'v' => $voucherId,
                'notes' => trim((string) ($input['notes'] ?? '')) ?: null, 'uid' => $userId ?: null,
            ]);

        // Metal grows the fine the kaligad is answerable for; stones grow their
        // own total and touch no fine weight at all.
        //
        // metal_ledger_id records the ledger this issue ACTUALLY debited, so
        // the receipt credits that one rather than re-deriving it from the
        // kaligad's mappings as they stand months later — migration 078, which
        // every other issue path obeys and this one did not. An issue of stones
        // alone had nothing else on the assignment to set it.
        db()->prepare('UPDATE jewellery_order_assignments
                SET issued_gross_weight = issued_gross_weight + :gross,
                    issued_fine_weight = issued_fine_weight + :fine,
                    issued_stone_carat = issued_stone_carat + :carat,
                    issued_stone_amount = issued_stone_amount + :stone_amount,
                    issued_amount = issued_amount + :metal_amount,
                    issue_stock_txn_out = COALESCE(issue_stock_txn_out, :o),
                    issue_stock_txn_in = COALESCE(issue_stock_txn_in, :i),
                    issue_voucher_id = COALESCE(issue_voucher_id, :v),
                    metal_ledger_id = COALESCE(metal_ledger_id, :ml)
                WHERE id = :id AND company_id = :cid')
            ->execute([
                'gross' => $isStone ? 0 : $gross,
                'fine' => $fine,
                'carat' => $carat,
                'stone_amount' => $isStone ? $amount : 0,
                'metal_amount' => $isStone ? 0 : $amount,
                'o' => $outId, 'i' => $inId, 'v' => $voucherId,
                'ml' => $voucherId ? $karigarLedgerId : null,
                'id' => $assignmentId, 'cid' => $companyId,
            ]);

        log_activity('jewellery_assignment', $assignmentId, 'component_issued',
            ($isStone ? 'Stones' : 'Metal') . ' issued on ' . $no . ': ' . number_format($gross, 4) . ' ' . (string) $unit['code'], $userId ?: null);

        if ($ownsTransaction) {
            db()->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $exception->getMessage()];
    }

    return ['ok' => true, 'error' => '', 'assignment_id' => $assignmentId,
        'kind' => $isStone ? 'stone' : 'metal', 'fine_weight' => $fine,
        'qty_carat' => $carat, 'amount' => $amount];
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

/**
 * Work still out with a kaligad, of one kind, with everything the receive
 * screen fills itself in from.
 *
 * The receive page asks for exactly one thing the assignment cannot tell it —
 * what actually came back on the scale. Everything else is already known, so
 * everything else is read rather than typed.
 */
function jewellery_open_assignments(int $companyId, string $kind): array
{
    $stmt = db()->prepare("SELECT a.id, a.assignment_no, a.issue_no, a.assign_kind, a.category,
            a.size_design, a.expected_ornament, a.expected_gross_weight, a.expected_stone_weight,
            a.expected_net_weight, a.issue_date, a.expected_return_date,
            a.issued_gross_weight, a.issued_fine_weight, a.issued_stone_carat,
            a.making_basis, a.making_rate, a.item_id, a.purity_id, a.unit_id, a.notes,
            k.code AS karigar_code, k.name AS karigar_name,
            o.order_no, o.order_date, o.delivery_date AS order_promise_date,
            COALESCE(ap.name, o.customer_name, '') AS customer_name,
            i.sku AS item_code, i.name AS item_name,
            p.code AS purity_code, p.fineness,
            u.code AS unit_code, u.grams AS unit_grams
        FROM jewellery_order_assignments a
        INNER JOIN jewellery_karigars k ON k.id = a.karigar_id
        LEFT JOIN jewellery_orders o ON o.id = a.order_id
        LEFT JOIN accounting_parties ap ON ap.id = o.party_id
        LEFT JOIN inventory_items i ON i.id = a.item_id
        LEFT JOIN jewellery_purities p ON p.id = a.purity_id
        LEFT JOIN jewellery_units u ON u.id = a.unit_id
        WHERE a.company_id = :cid AND a.assign_kind = :kind AND a.status = 'issued'
        ORDER BY COALESCE(a.expected_return_date, '9999-12-31') ASC, a.id ASC");
    $stmt->execute(['cid' => $companyId, 'kind' => $kind === 'self' ? 'self' : 'customer']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Carats to the weight unit the shop actually weighs in.
 *
 * A carat is a fifth of a gram everywhere on earth, so this is arithmetic and
 * not a setting — but the shop may weigh in tola, and the receipt records the
 * stone in the same unit as the metal it was set into. Both figures go on the
 * screen: the carats the trade quotes, and the weight the scale shows.
 */
function jewellery_carat_to_unit(float $carat, float $unitGrams): float
{
    if ($unitGrams <= 0) {
        return jw_round_weight($carat * 0.2);
    }

    return jw_round_weight($carat * 0.2 / $unitGrams);
}

/** And back, for a screen that was given the weight and wants the carats. */
function jewellery_unit_to_carat(float $weight, float $unitGrams): float
{
    if ($unitGrams <= 0) {
        return jw_round_weight($weight / 0.2);
    }

    return jw_round_weight($weight * $unitGrams / 0.2);
}

// ---------------------------------------------------------------------------
// Wages, in rupees and in metal
// ---------------------------------------------------------------------------

/**
 * A kaligad's wage said both ways.
 *
 * Wages are agreed in rupees — flat, per gram, or a share of the metal — and
 * they are very often PAID in gold: "keep two grams". Both figures have to be
 * on the screen, and only one of them can be the truth or they drift apart.
 *
 * Rupees are the truth. The metal is worked out from them, at the rate the
 * issue itself was valued at, which is the same rate the wastage is charged at
 * — so the wage and the wastage recovery are measured against one gold price
 * and a movement in the day's rate cannot silently change what was earned.
 *
 * When there is no issue rate to divide by, there was no metal issued: a work
 * order, where the kaligad found his own gold. Then the day's board is used
 * instead, and the answer says so, because a wage converted at today's price is
 * a different statement from one converted at the price the metal went out at.
 *
 * Pure: the rate is handed in.
 */
function jewellery_wages_in_metal(float $rupees, float $fineRate): float
{
    if ($fineRate <= 0.00005) {
        return 0.0;
    }

    return jw_round_weight($rupees / $fineRate);
}

/** The reverse, for a shop that agrees the wage in gold and needs the rupees. */
function jewellery_wages_from_metal(float $fineWeight, float $fineRate): float
{
    return jw_round_money($fineWeight * $fineRate);
}

/**
 * The wage on one receipt, in rupees and in fine metal, saying which rate did
 * the converting and why.
 *
 * $dayRate is only consulted when the receipt carries no rate of its own; the
 * caller looks it up, so this stays testable without a rate board.
 */
function jewellery_wage_statement(array $receipt, float $dayRate = 0.0): array
{
    $making = round((float) ($receipt['making_amount'] ?? 0), 2);
    $recovery = round((float) ($receipt['recovery_amount'] ?? 0), 2);
    $netPayable = round((float) ($receipt['net_payable'] ?? 0), 2);
    $issueRate = round((float) ($receipt['avg_fine_rate'] ?? 0), 4);

    $rate = $issueRate > 0.00005 ? $issueRate : round($dayRate, 4);
    $basis = $issueRate > 0.00005 ? 'issue' : ($rate > 0.00005 ? 'board' : 'none');

    return [
        'making_amount' => $making,
        'recovery_amount' => $recovery,
        'net_payable' => $netPayable,
        'fine_rate' => $rate,
        // 'issue' — the rate this metal went out at, and the same one the
        // wastage was charged at. 'board' — the day's rate, because no metal
        // went out. 'none' — neither, so the wage stands in rupees alone.
        'rate_basis' => $basis,
        'making_fine' => jewellery_wages_in_metal($making, $rate),
        'recovery_fine' => jewellery_wages_in_metal($recovery, $rate),
        'net_payable_fine' => jewellery_wages_in_metal($netPayable, $rate),
        'convertible' => $rate > 0.00005,
    ];
}

/** "at the issue rate" / "at today's board rate" — why this many grams. */
function jewellery_wage_rate_note(array $statement, string $currency = ''): string
{
    return match ((string) $statement['rate_basis']) {
        'issue' => 'at ' . $currency . number_format((float) $statement['fine_rate'], 2) . ' per fine — the rate this metal went out at',
        'board' => 'at ' . $currency . number_format((float) $statement['fine_rate'], 2) . ' per fine — today\'s board rate, as no metal was issued',
        default => 'no gold rate available, so the wage stands in ' . ($currency !== '' ? 'rupees' : 'money') . ' alone',
    };
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
