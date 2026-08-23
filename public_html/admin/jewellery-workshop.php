<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/jewellery_reports.php';
// Ready to Sale and the output register both read the assignment kind, which
// the assign engine owns.
require_once __DIR__ . '/../../app/jewellery_assign.php';
require_once __DIR__ . '/../../app/jewellery_stock.php';
// The order form punches items on the SAME grid the sale does, so a quote and
// the bill it becomes can never carry different columns.
require_once __DIR__ . '/../../app/views/partials/jewellery_line_grid.php';
require_once __DIR__ . '/../../app/views/partials/jewellery_filter_bar.php';

accounting_module_repair_database();
require_jewellery();

$company = current_company();
$fiscalYear = current_fiscal_year();
if (!$company || !$fiscalYear) {
    flash('error', 'Company and fiscal year context required.');
    redirect('admin/accounting-dashboard.php');
}
$companyId = (int) $company['id'];
$fiscalYearId = (int) $fiscalYear['id'];
$fyStart = (string) $fiscalYear['start_date'];
$fyEnd = (string) $fiscalYear['end_date'];
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$sym = site_currency_symbol();

$settings = jewellery_settings($companyId);
$canEdit = user_can_do('jewellery', 'edit');
$canPost = user_can_do('jewellery', 'post');
$canExport = user_can_do('jewellery', 'export');

// The list's own export links, carrying the CURRENT filters — the file holds
// exactly the rows the screen shows, search and all.
$exportLinks = static function () use (&$view): string {
    $query = $_GET;
    $query['view'] = $view;
    $links = '';
    // PDF is the print view — it opens in its own tab with a Print / Save as
    // PDF button, the same road every jewellery document takes to paper.
    foreach (['csv' => 'CSV', 'xlsx' => 'Excel', 'print' => 'PDF'] as $format => $label) {
        $query['export'] = $format;
        $links .= '<a class="mbw-view-all" style="margin-left:10px"' . ($format === 'print' ? ' target="_blank" rel="noopener"' : '') . ' href="'
            . e(url('admin/jewellery-workshop.php?' . http_build_query($query))) . '">' . $label . '</a>';
    }

    return $links;
};

$allowedViews = ['orders', 'karigars', 'assignments', 'delivery', 'ready-to-sale', 'output', 'refinery'];
$view = jw_enum($_GET['view'] ?? null, $allowedViews, 'orders');

$clampDate = static function (string $date) use ($fyStart, $fyEnd): string {
    if ($date === '' || strtotime($date) === false) {
        $date = date('Y-m-d');
    }
    return $date < $fyStart ? $fyStart : ($date > $fyEnd ? $fyEnd : $date);
};
$todayInFy = $clampDate(date('Y-m-d'));

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $back = 'admin/jewellery-workshop.php?view=' . urlencode((string) ($_POST['back_view'] ?? $view));

    // Record ONE advance on an order from the tender rows the form posted —
    // 20,000 cash, 15,000 on Fonepay and an old ring is one advance with
    // three rows, not three advances. Shared by the take/refund forms AND
    // the new-order form, which takes the advance while the order is being
    // written instead of on a second visit in edit mode. Returns the flash
    // message; throws when the engine refuses.
    $recordOrderAdvance = static function (array $order, string $direction) use ($companyId, $fiscalYearId, $clampDate, $userId, $canPost): string {
        if ((int) ($order['party_id'] ?? 0) <= 0) {
            throw new RuntimeException('Give this order a customer first — an advance has to be held against somebody.');
        }
        $tenders = [];
        foreach ((array) ($_POST['tender_amount'] ?? []) as $index => $tenderAmount) {
            $tenders[] = [
                'mode' => (string) ($_POST['tender_mode'][$index] ?? 'cash'),
                'mode_label' => (string) ($_POST['tender_label'][$index] ?? ''),
                'reference' => (string) ($_POST['tender_reference'][$index] ?? ''),
                'amount' => (float) $tenderAmount,
                'ledger_id' => (int) ($_POST['tender_ledger_id'][$index] ?? 0),
                'item_id' => (int) ($_POST['tender_item_id'][$index] ?? 0),
                'purity_id' => (int) ($_POST['tender_purity_id'][$index] ?? 0),
                'unit_id' => (int) ($_POST['tender_unit_id'][$index] ?? 0),
                'gross_weight' => (float) ($_POST['tender_gross_weight'][$index] ?? 0),
            ];
        }
        // The total is the sum of what was actually handed over, so the
        // counter can never type a figure the parts disagree with.
        $amount = 0.0;
        foreach ($tenders as $tenderRow) {
            if ((float) $tenderRow['amount'] > 0) {
                $amount += (float) $tenderRow['amount'];
            }
        }
        $id = jewellery_save_settlement($companyId, $fiscalYearId, [
            'settlement_date' => $clampDate((string) ($_POST['advance_date'] ?? '')),
            'party_id' => (int) $order['party_id'],
            'order_id' => (int) $order['id'],
            'is_advance' => 1,
            'direction' => $direction,
            'amount' => jw_round_money($amount),
            'tenders' => $tenders,
            'notes' => ($direction === 'paid' ? 'Advance refunded on order ' : 'Advance on order ')
                . (string) $order['order_no'],
        ], [], $userId);
        if (!$canPost) {
            return 'Advance saved as a draft — someone with posting rights must post it.';
        }
        $posted = jewellery_post_settlement($companyId, $id, $userId);
        if (!$posted['ok']) {
            throw new RuntimeException($posted['error']);
        }
        $tookMetal = in_array('metal', array_column($tenders, 'mode'), true);

        return $direction === 'paid'
            ? 'Advance refunded and posted.'
            : ($tookMetal ? 'Advance received and posted — the old gold\'s weight is in stock and its value is held for the customer.'
                : 'Advance received and posted.');
    };

    if ($action === 'save_karigar') {
        require_permission('jewellery', 'edit');
        try {
            jewellery_save_karigar($companyId, [
                'id' => (int) ($_POST['karigar_id'] ?? 0),
                'code' => (string) ($_POST['code'] ?? ''),
                'name' => (string) ($_POST['name'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? ''),
                'address' => (string) ($_POST['address'] ?? ''),
                'engagement_type' => (string) ($_POST['engagement_type'] ?? 'contractor'),
                'payroll_employee_id' => (int) ($_POST['payroll_employee_id'] ?? 0),
                'default_making_basis' => (string) ($_POST['default_making_basis'] ?? 'per_unit_weight'),
                'default_making_rate' => (float) ($_POST['default_making_rate'] ?? 0),
                'wastage_allowed_pct' => (float) ($_POST['wastage_allowed_pct'] ?? 0),
                'status' => isset($_POST['active']) ? 'active' : 'inactive',
                'notes' => (string) ($_POST['notes'] ?? ''),
            ], $userId);
            flash('success', 'Karigar saved.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($back);
    }

    if ($action === 'save_order') {
        require_permission('jewellery', 'edit');
        $isCreate = (int) ($_POST['order_id'] ?? 0) === 0;
        try {
            $savedOrderId = jewellery_save_order($companyId, $fiscalYearId, [
                'id' => (int) ($_POST['order_id'] ?? 0),
                // Blank means "number it for me" on a new order and "keep the
                // number it already has" on an existing one; typed means the
                // shop's own reference, on a correction just as much as on a
                // new order, refused with a sentence if it is already taken.
                'order_no' => (string) ($_POST['order_no'] ?? ''),
                'order_date' => $clampDate((string) ($_POST['order_date'] ?? '')),
                'delivery_date' => (string) ($_POST['delivery_date'] ?? ''),
                'party_id' => (int) ($_POST['party_id'] ?? 0),
                'customer_name' => (string) ($_POST['customer_name'] ?? ''),
                'customer_phone' => (string) ($_POST['customer_phone'] ?? ''),
                'sales_employee_id' => (int) ($_POST['sales_employee_id'] ?? 0),
                'sales_person' => (string) ($_POST['sales_person'] ?? ''),
                'item_id' => (int) ($_POST['item_id'] ?? 0),
                'metal_id' => (int) ($_POST['metal_id'] ?? 0),
                'purity_id' => (int) ($_POST['purity_id'] ?? 0),
                'unit_id' => (int) ($_POST['unit_id'] ?? 0),
                'expected_gross_weight' => (float) ($_POST['expected_gross_weight'] ?? 0),
                'design_no' => (string) ($_POST['design_no'] ?? ''),
                'expected_item' => (string) ($_POST['expected_item'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                // making_basis / making_rate are deliberately NOT sent. What the
                // customer is charged for labour is the making amount on each
                // line of the grid; what the KALIGAD is paid is set on the issue
                // screen, which carries its own basis and rate. A third copy on
                // the order header fed neither.
                'other_charges' => (float) ($_POST['other_charges'] ?? 0),
                'discount' => (float) ($_POST['discount'] ?? 0),
                'manual_tax_amount' => ($_POST['manual_tax_amount'] ?? '') === '' ? null : (float) $_POST['manual_tax_amount'],
                'advance_amount' => (float) ($_POST['advance_amount'] ?? 0),
                'status' => (string) ($_POST['status'] ?? 'confirmed'),
                'notes' => (string) ($_POST['notes'] ?? ''),
                // The items the customer is ordering, punched on the same grid
                // the sale uses, so the quote and the bill cannot disagree.
            ], jw_posted_lines($_POST, 'l'), $userId);

            // The advance the customer is putting down RIGHT NOW, tendered on
            // the new-order form itself. Only on create — the edit page has
            // its own take/refund forms — and only when something was typed.
            $tenderTotal = 0.0;
            foreach ((array) ($_POST['tender_amount'] ?? []) as $tenderAmount) {
                $tenderTotal += max(0.0, (float) $tenderAmount);
            }
            if ($isCreate && $tenderTotal > 0.005) {
                // The ORDER IS ALREADY SAVED. An advance the engine refuses
                // must not unsave it — the counter fixes the advance on the
                // edit page it is sent to, not by retyping the whole order.
                try {
                    flash('success', 'Order saved. ' . $recordOrderAdvance(jewellery_order($companyId, $savedOrderId) ?? [], 'received'));
                } catch (Throwable $advanceError) {
                    flash('error', 'The order is saved, but the advance was refused: ' . $advanceError->getMessage()
                        . ' Record it from this page.');
                }
                redirect($back . '&edit=' . $savedOrderId);
            }
            flash('success', 'Order saved.');
        } catch (Throwable $e) {
            // NOTHING TYPED IS THROWN AWAY. The refusal and every field come
            // back to the form together — a clerk sent back to a blank form
            // retypes an entire order, and the second try earns new mistakes.
            $_SESSION['jw_order_form_stash'] = $_POST;
            flash('error', $e->getMessage());
        }
        redirect($back);
    }

    if ($action === 'delete_order') {
        require_permission('jewellery', 'edit');
        $removed = jewellery_delete_order($companyId, (int) ($_POST['order_id'] ?? 0));
        flash($removed ? 'success' : 'error', $removed
            ? 'Order removed.' : 'Only an order with no karigar assignment can be removed.');
        redirect($back);
    }

    if ($action === 'delete_assignment') {
        require_permission('jewellery', 'edit');
        $result = jewellery_delete_assignment($companyId, (int) ($_POST['assignment_id'] ?? 0));
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Cancelled issue deleted — its cancellation had already unwound the metal movements.'
            : $result['error']);
        redirect($back);
    }

    if ($action === 'delete_karigar') {
        require_permission('jewellery', 'edit');
        $result = jewellery_delete_karigar($companyId, (int) ($_POST['karigar_id'] ?? 0));
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Kaligad deleted.' : $result['error']);
        redirect($back);
    }

    if ($action === 'delete_refinery_job') {
        require_permission('jewellery', 'edit');
        $result = jewellery_delete_refinery_job($companyId, (int) ($_POST['job_id'] ?? 0));
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Cancelled refinery job deleted — its cancellation had already unwound the metal movements.'
            : $result['error']);
        redirect($back);
    }

    if ($action === 'cancel_order') {
        require_permission('jewellery', 'edit');
        $cancelled = jewellery_cancel_order($companyId, (int) ($_POST['order_id'] ?? 0),
            (string) ($_POST['reason'] ?? ''), $userId);
        $advanceNote = '';
        if ($cancelled['ok'] && (float) $cancelled['advance_held'] > 0.005) {
            $advanceNote = ' ' . site_currency_symbol() . ' ' . number_format((float) $cancelled['advance_held'], 2)
                . ' of advance is still held — refund it from the order screen.';
        }
        flash($cancelled['ok'] ? 'success' : 'error',
            $cancelled['ok'] ? 'Order cancelled.' . $advanceNote : $cancelled['error']);
        redirect($back);
    }

    if ($action === 'postpone_order') {
        require_permission('jewellery', 'edit');
        $moved = jewellery_postpone_order($companyId, (int) ($_POST['order_id'] ?? 0),
            (string) ($_POST['delivery_date'] ?? ''), (string) ($_POST['reason'] ?? ''), $userId);
        flash($moved['ok'] ? 'success' : 'error',
            $moved['ok'] ? 'Order rescheduled.' : $moved['error']);
        redirect($back);
    }

    // An advance is an ordinary settlement flagged against the order, so it
    // reuses the numbering, posting, metal movement and unpost machinery that
    // already exists rather than growing a parallel one.
    if ($action === 'save_advance' || $action === 'refund_advance') {
        require_permission('jewellery', 'edit');
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $order = jewellery_order($companyId, $orderId);
        try {
            if (!$order) {
                throw new RuntimeException('Order not found for this company.');
            }
            flash('success', $recordOrderAdvance($order, $action === 'refund_advance' ? 'paid' : 'received'));
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($back . '&edit=' . $orderId);
    }

    if ($action === 'issue_metal_later') {
        require_permission('jewellery', 'post');
        $result = jewellery_issue_metal_to_assignment($companyId, $fiscalYearId,
            (int) ($_POST['assignment_id'] ?? 0), [
                'issued_gross_weight' => (float) ($_POST['issued_gross_weight'] ?? 0),
                'issue_date' => $clampDate((string) ($_POST['issue_date'] ?? '')),
            ], $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ('Metal handed over: ' . number_format((float) $result['issued_fine_weight'], 4) . ' fine added to this issue.')
            : $result['error']);
        redirect($back);
    }

    // Hand over one thing on an issue — a bar of gold, or a packet of stones.
    // A superset of issue_metal_later, which stays for the callers that only
    // ever mean the assignment's own metal.
    if ($action === 'issue_component') {
        require_permission('jewellery', 'post');
        $result = jewellery_issue_component($companyId, $fiscalYearId,
            (int) ($_POST['assignment_id'] ?? 0), [
                'item_id' => (int) ($_POST['item_id'] ?? 0),
                'gross_weight' => (float) ($_POST['gross_weight'] ?? 0),
                'issue_date' => $clampDate((string) ($_POST['issue_date'] ?? '')),
                'notes' => (string) ($_POST['notes'] ?? ''),
            ], $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ((string) $result['kind'] === 'stone'
                ? 'Stones handed over: ' . number_format((float) $result['qty_carat'], 3) . ' ct added to this issue.'
                : 'Metal handed over: ' . number_format((float) $result['fine_weight'], 4) . ' fine added to this issue.')
            : $result['error']);
        redirect($back);
    }

    if ($action === 'cancel_assignment') {
        require_permission('jewellery', 'post');
        $result = jewellery_cancel_assignment($companyId, (int) ($_POST['assignment_id'] ?? 0), $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Assignment cancelled; the metal is back in stock.' : $result['error']);
        redirect($back);
    }

    // A receipt entered at the wrong weight has to be correctable — see
    // jewellery_unpost_receipt(). Refused if the wage bill is part paid.
    if ($action === 'unpost_receipt') {
        require_permission('jewellery', 'post');
        $result = jewellery_unpost_receipt($companyId, (int) ($_POST['receipt_id'] ?? 0), $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Receipt reversed. The metal is with the kaligad again — receive it at the corrected weight.'
            : $result['error']);
        redirect($back);
    }

    if ($action === 'cancel_refinery_job') {
        require_permission('jewellery', 'post');
        $result = jewellery_cancel_refinery_job($companyId, (int) ($_POST['job_id'] ?? 0), $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Refinery job cancelled; the metal is back in own stock.' : $result['error']);
        redirect($back);
    }

    // There is no "deliver_order" action here any more. Delivery is something a
    // SALE does — jewellery-trade.php records it when the bill posts — and an
    // action on this page that closed an order without one is exactly how goods
    // used to leave the shop unbilled. The engine would refuse it now, but a
    // dead endpoint named after a forbidden operation is an invitation to wire
    // it back up.

    if ($action === 'issue_refinery') {
        require_permission('jewellery', 'post');
        $result = jewellery_issue_to_refinery($companyId, $fiscalYearId, [
            'party_id' => (int) ($_POST['party_id'] ?? 0),
            'item_id' => (int) ($_POST['item_id'] ?? 0),
            'purity_id' => (int) ($_POST['purity_id'] ?? 0),
            'unit_id' => (int) ($_POST['unit_id'] ?? 0),
            'issued_gross_weight' => (float) ($_POST['issued_gross_weight'] ?? 0),
            'issue_date' => $clampDate((string) ($_POST['issue_date'] ?? '')),
            'notes' => (string) ($_POST['notes'] ?? ''),
        ], $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Metal sent for refining.' : $result['error']);
        redirect($back);
    }

    if ($action === 'receive_refinery') {
        require_permission('jewellery', 'post');
        $result = jewellery_receive_from_refinery($companyId, $fiscalYearId, [
            'job_id' => (int) ($_POST['job_id'] ?? 0),
            'received_item_id' => (int) ($_POST['received_item_id'] ?? 0),
            'received_purity_id' => (int) ($_POST['received_purity_id'] ?? 0),
            'received_gross_weight' => (float) ($_POST['received_gross_weight'] ?? 0),
            'receive_date' => $clampDate((string) ($_POST['receive_date'] ?? '')),
            'charges_amount' => (float) ($_POST['charges_amount'] ?? 0),
            'charges_settle_mode' => (string) ($_POST['charges_settle_mode'] ?? 'credit'),
            'charges_ledger_id' => (int) ($_POST['charges_ledger_id'] ?? 0),
        ], $userId);
        // Say which way the metal actually went. A refiner who put some of his
        // own in is owed for it, and the shop should be told so rather than
        // reading a loss of 0.0000 and assuming nothing of note happened.
        $surplusFine = (float) ($result['surplus_fine'] ?? 0);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ($surplusFine > 0
                ? ('Refined metal received. The refiner supplied ' . number_format($surplusFine, 4)
                   . ' fine of his own (' . $sym . number_format((float) $result['surplus_amount'], 2)
                   . '), credited to him.')
                : ('Refined metal received. Loss ' . number_format((float) $result['loss_fine'], 4)
                   . ' fine (' . $sym . number_format((float) $result['loss_amount'], 2) . ').'))
            : $result['error']);
        redirect($back);
    }

    redirect($back);
}

// ---------------------------------------------------------------------------
// Page data
// ---------------------------------------------------------------------------
$items = jewellery_items_list($companyId, ['active_only' => true]);
$units = jewellery_units_list($companyId);
$metals = jewellery_metals_list($companyId);
$purities = jewellery_purities_list($companyId);
$baseUnit = jewellery_base_unit($companyId);
// Who is on the counter. Employees come from Payroll, which a shop may not
// have filled in yet — hence the typed fallback beside the list on the form.
$orderEmployees = [];
if (table_exists('payroll_employees')) {
    $employeeStmt = db()->prepare("SELECT id, employee_code, full_name FROM payroll_employees
        WHERE company_id = :cid AND status = 'active'
        ORDER BY COALESCE(NULLIF(full_name, ''), employee_code) ASC");
    $employeeStmt->execute(['cid' => $companyId]);
    $orderEmployees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);
}
$karigars = jewellery_karigars_list($companyId);
$activeKarigars = array_values(array_filter($karigars, static fn (array $k): bool => (string) $k['status'] === 'active'));

$partyStmt = db()->prepare("SELECT id, code, name, party_type FROM accounting_parties WHERE company_id = :cid AND status = 'active' ORDER BY name ASC");
$partyStmt->execute(['cid' => $companyId]);
$parties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);
// The counter is looking for a person, but this one list also carries the
// suppliers and refiners the shop deals with. party_type already records which
// is which, so the names are gathered under it instead of running together in
// one alphabetical column — customers first, because that is who an order is
// normally written for. $parties itself stays flat: two other dropdowns and an
// array_column() read it as it was.
$partyGroupLabels = [
    'customer' => 'Customers',
    'both'     => 'Customer and supplier',
    'supplier' => 'Suppliers',
    'other'    => 'Other',
];
$partyGroups = [];
foreach ($partyGroupLabels as $partyGroupType => $partyGroupLabel) {
    $inGroup = array_values(array_filter(
        $parties,
        static fn (array $p): bool => (string) ($p['party_type'] ?? 'other') === $partyGroupType
    ));
    if ($inGroup !== []) {
        $partyGroups[$partyGroupLabel] = $inGroup;
    }
}
// A value the enum gains later still has to reach the dropdown, so anything
// that matched no heading above is swept into Other rather than disappearing.
$partyGroupStrays = array_values(array_filter(
    $parties,
    static fn (array $p): bool => !isset($partyGroupLabels[(string) ($p['party_type'] ?? 'other')])
));
if ($partyGroupStrays !== []) {
    $partyGroups['Other'] = array_merge($partyGroups['Other'] ?? [], $partyGroupStrays);
}
// One heading over the whole list says nothing, so a shop whose parties are
// all of one kind gets the plain list it had before.
if (count($partyGroups) < 2) {
    $partyGroups = ['' => $parties];
}
$ledgerStmt = db()->prepare('SELECT id, code, name FROM ledgers WHERE company_id = :cid ORDER BY code ASC');
$ledgerStmt->execute(['cid' => $companyId]);
$ledgers = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

$employees = [];
if (table_exists('payroll_employees')) {
    $empStmt = db()->prepare('SELECT id, employee_code FROM payroll_employees WHERE company_id = :cid ORDER BY employee_code ASC');
    $empStmt->execute(['cid' => $companyId]);
    $employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Everything a list is filtered by travels in the query string, so a filtered
// list can be bookmarked or sent to somebody and come back the same.
$filterSearch = trim((string) ($_GET['q'] ?? ''));
$filterFrom = (string) ($_GET['from'] ?? '');
$filterTo = (string) ($_GET['to'] ?? '');
$filterStatus = (string) ($_GET['status'] ?? '');
$filterParty = (int) ($_GET['party'] ?? 0);
$filterKarigar = (int) ($_GET['karigar'] ?? 0);
$filterOverdue = !empty($_GET['overdue']);
$listFilters = [
    'limit' => 300,
    'search' => $filterSearch,
    'status' => $filterStatus,
    'party_id' => $filterParty,
    'karigar_id' => $filterKarigar,
    'overdue_only' => $filterOverdue,
    // Made to order, off the shelf, or the shop's own replenishment.
    'source' => jw_enum($_GET['source'] ?? null, array_keys(jewellery_order_sources()), ''),
];
if ($filterFrom !== '' && $filterTo !== '') {
    $listFilters['from'] = $filterFrom;
    $listFilters['to'] = $filterTo;
}
$advancedInUse = $filterStatus !== '' || $filterParty > 0 || $filterKarigar > 0 || $filterOverdue
    || (string) ($listFilters['source'] ?? '') !== '';

// Add sorting support
$sortParam = (string) ($_GET['sort'] ?? 'order_date_desc'); // Default sort by date descending
$allowedSorts = ['order_no_asc', 'order_no_desc', 'order_date_asc', 'order_date_desc', 'party_asc', 'party_desc', 'metal_asc', 'metal_desc', 'weight_asc', 'weight_desc', 'delivery_asc', 'delivery_desc', 'status_asc', 'status_desc'];
if (!in_array($sortParam, $allowedSorts, true)) {
    $sortParam = 'order_date_desc';
}
$listFilters['sort'] = $sortParam;

$orders = $view === 'orders' ? jewellery_orders_list($companyId, $listFilters) : [];
$orderNumberOptions = [];
if ($view === 'orders') {
    // This is intentionally independent of the active filters: the Order No.
    // picker must still let the clerk jump straight to any order in the company.
    $orderNoStmt = db()->prepare('SELECT order_no FROM jewellery_orders
        WHERE company_id = :cid AND order_no <> \'\'
        ORDER BY order_date DESC, id DESC LIMIT 500');
    $orderNoStmt->execute(['cid' => $companyId]);
    $orderNumberOptions = $orderNoStmt->fetchAll(PDO::FETCH_COLUMN);
}
$editKarigar = $view === 'karigars' ? jewellery_karigar($companyId, (int) ($_GET['edit'] ?? 0)) : null;
$editOrder = $view === 'orders' ? jewellery_order($companyId, (int) ($_GET['edit'] ?? 0)) : null;
$orderAdvances = $editOrder ? jewellery_order_advances($companyId, (int) $editOrder['id'])
    : ['rows' => [], 'cash_total' => 0.0, 'metal_total' => 0.0, 'total' => 0.0];
$advanceAvailable = $editOrder ? jewellery_order_advance_available($companyId, (int) $editOrder['id']) : 0.0;
$orderLines = $editOrder ? jewellery_order_line_rows($companyId, (int) $editOrder['id']) : [];

// A save the engine refused comes back WITH EVERYTHING THE CLERK TYPED. The
// handler stashed the POST; the form is refilled from it once, then the
// stash is burned — a refresh after that is a deliberate fresh start, not a
// replay of the failure. Editing an existing order wins over the stash: its
// stored rows are the truth being revised.
$orderStash = $_SESSION['jw_order_form_stash'] ?? null;
unset($_SESSION['jw_order_form_stash']);
if ($view === 'orders' && $editOrder === null && is_array($orderStash)) {
    $editOrder = null; // stays a NEW order — the stash only refills fields
    $orderFormPrefill = $orderStash;
    $orderLines = jw_posted_lines($orderStash, 'l');
} else {
    $orderFormPrefill = null;
}
// The form reads header values through this: the stashed value when a refused
// save is being corrected, else the stored order being edited, else blank.
$orderField = static function (string $key, $fallback = '') use (&$editOrder, &$orderFormPrefill) {
    if (is_array($orderFormPrefill) && array_key_exists($key, $orderFormPrefill)) {
        return $orderFormPrefill[$key];
    }

    return $editOrder[$key] ?? $fallback;
};
// What is on the shelf, shown on the item options the same way the sale form
// shows it — an order for a piece the shop already has is filled off the tray.
$orderOnHand = [];
// The Ready to Sale shelf, offered on the order form itself: a customer who
// points at a ring in the case is placing an order for THAT piece, and there is
// nothing for a kaligad to make. Pieces another live order is already holding
// are not on the list; the ones this order holds stay on it, or revising the
// order would strike out its own items.
$orderStockPieces = [];

// Asked for the whole item list at once. One aggregate per item meant a
// hundred round trips to caption a dropdown.
$orderOnHand = jw_item_balances($companyId, array_column($items, 'id'), date('Y-m-d'), 'stock');

// The Ready to Sale shelf itself — jewellery_stock_units, not the product
// master. A piece the customer points at in the case is one traced unit with
// its own weight and trace code, and that unit's id is what the line stores in
// stock_unit_id. Showroom pieces only: anything already spoken for by a
// customer order is another customer's, so the engine's own list is used, which
// also keeps back pieces a different live order is holding while leaving this
// order's own holds on the list.
$orderStockPieces = jewellery_trace_ready_to_sale_options(
    $companyId,
    $editOrder ? (int) $editOrder['id'] : 0
);
$cashBankLedgers = [];
if ($view === 'orders' && table_exists('ledgers')) {
    $cashStmt = db()->prepare('SELECT l.id, l.code, l.name FROM ledgers l
        LEFT JOIN ledger_groups g ON g.id = l.group_id
        WHERE l.company_id = :cid AND l.status = \'active\' AND l.type = \'asset\'
        ORDER BY g.is_cash_or_bank DESC, l.code ASC, l.name ASC');
    $cashStmt->execute(['cid' => $companyId]);
    $cashBankLedgers = $cashStmt->fetchAll(PDO::FETCH_ASSOC);
}
$assignments = $view === 'assignments' ? jewellery_assignments_list($companyId, $listFilters) : [];
// Assigning and receiving moved to their own pages; this view is the register
// of what is OUT, so it no longer loads a receive preview.
$deliveryOrigin = jw_enum($_GET['origin'] ?? null, array_keys(jewellery_order_sources()), '');
$deliverySort = jw_enum($_GET['sort'] ?? null, ['order', 'customer', 'origin', 'received', 'weight', 'waiting', 'promised'], 'received');
$deliveryDir = jw_enum($_GET['dir'] ?? null, ['asc', 'desc'], 'asc');
$deliveryFilters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'received_from' => trim((string) ($_GET['received_from'] ?? '')),
    'received_to' => trim((string) ($_GET['received_to'] ?? '')),
    'weight_min' => trim((string) ($_GET['weight_min'] ?? '')),
    'weight_max' => trim((string) ($_GET['weight_max'] ?? '')),
    'waiting_min' => trim((string) ($_GET['waiting_min'] ?? '')),
    'waiting_max' => trim((string) ($_GET['waiting_max'] ?? '')),
    'promised_from' => trim((string) ($_GET['promised_from'] ?? '')),
    'promised_to' => trim((string) ($_GET['promised_to'] ?? '')),
];
$pending = $view === 'delivery'
    ? jewellery_pending_delivery($companyId, $deliveryFilters + ['origin' => $deliveryOrigin, 'sort' => $deliverySort, 'dir' => $deliveryDir])
    : [];
$deliveryCounts = $view === 'delivery' ? jewellery_pending_delivery_counts($companyId) : [];
// A showroom piece comes back to the shelf, not to a collection queue, and the
// output register is the two flows in one list — the only place they belong
// together, because it asks about the shop's output as a whole.
$readyToSale = $view === 'ready-to-sale' ? jewellery_ready_to_sale($companyId, $listFilters) : [];
$output = $view === 'output'
    ? jewellery_workshop_output($companyId, $listFilters + ['kind' => jw_enum($_GET['kind'] ?? null, ['customer', 'self'], '')])
    : [];
$jobs = $view === 'refinery' ? jewellery_refinery_jobs_list($companyId) : [];

// ---------------------------------------------------------------------------
// Export — the LIST the screen is showing, exactly as filtered
// ---------------------------------------------------------------------------
// Driven by the same arrays the tables below render, so the file on disk and
// the rows on screen can never disagree. Spreadsheets leave the building, so
// this takes the export right, exactly as the reports page does.
$exportFormat = jw_enum($_GET['export'] ?? null, ['csv', 'xlsx', 'print'], '');
if (in_array($exportFormat, ['csv', 'xlsx', 'print'], true) && ($_GET['export'] ?? '') !== '') {
    require_permission('jewellery', 'export');
    require_once __DIR__ . '/../../app/export_engine.php';
    $stamp = date('Ymd');
    $exportMeta = ['Company' => (string) ($company['name'] ?? ''), 'Currency' => $sym];
    if ($view === 'orders') {
        $data = [['Order', 'Date', 'Customer', 'Expected item', 'Metal', 'Purity', 'Expected wt', 'Fine wt', 'Unit',
            'Quoted total', 'Advance quoted', 'Promised', 'Status']];
        foreach ($orders as $r) {
            $data[] = [$r['order_no'], $r['order_date'],
                (string) ($r['party_name'] ?? $r['customer_name'] ?? 'Walk-in'),
                (string) ($r['expected_item'] ?? ''), $r['metal_name'], $r['purity_code'],
                $r['expected_gross_weight'], $r['expected_fine_weight'], $r['unit_code'],
                $r['total_amount'] ?? 0, $r['advance_amount'] ?? 0, $r['delivery_date'], $r['status']];
        }
        export_dispatch($exportFormat, 'jewellery-orders-' . $stamp, $data, 'Orders', $exportMeta);
    }
    if ($view === 'assignments') {
        $data = [['Issue', 'Date', 'Kaligad', 'Order', 'Item', 'Purity', 'Issued gross', 'Issued fine',
            'Expected return', 'Status']];
        foreach ($assignments as $r) {
            $data[] = [$r['issue_no'], $r['issue_date'], (string) ($r['karigar_name'] ?? $r['karigar_code'] ?? ''),
                (string) ($r['order_no'] ?? ''), (string) ($r['item_code'] ?? ''), (string) ($r['purity_code'] ?? ''),
                $r['issued_gross_weight'], $r['issued_fine_weight'],
                (string) ($r['expected_return_date'] ?? ''), $r['status']];
        }
        export_dispatch($exportFormat, 'jewellery-issues-' . $stamp, $data, 'Kaligad Issues', $exportMeta);
    }
    if ($view === 'delivery') {
        // The order number leads, because that is what the customer quotes and
        // what the counter looks up. Everything the screen shows follows, plus
        // the phone number, which is the point of a list of people to chase.
        $data = [['Order No', 'Order date', 'Customer', 'Phone', 'Ordered as', 'Item', 'Design',
            'Received on', 'Weight back', 'Fine wt', 'Unit', 'Days waiting', 'Promised', 'Kaligads', 'Order status']];
        $sourceLabels = jewellery_order_sources();
        foreach ($pending as $r) {
            $data[] = [
                (string) $r['order_no'],
                (string) $r['order_date'],
                (string) ($r['party_name'] ?? '') !== '' ? (string) $r['party_name'] : (string) ($r['customer_name'] ?? ''),
                (string) ($r['customer_phone'] ?? ''),
                (string) ($sourceLabels[(string) ($r['origin'] ?? '')] ?? ''),
                (string) ($r['expected_item'] ?? ''),
                (string) ($r['design_no'] ?? ''),
                (string) ($r['receive_date'] ?? ''),
                $r['received_gross_weight'] ?? 0,
                $r['received_fine_weight'] ?? 0,
                (string) ($r['unit_code'] ?? ''),
                (int) ($r['days_waiting'] ?? 0),
                (string) ($r['delivery_date'] ?? ''),
                (int) ($r['assignment_count'] ?? 0),
                (string) $r['status'],
            ];
        }
        export_dispatch($exportFormat, 'jewellery-awaiting-collection-' . $stamp, $data, 'Awaiting Collection', $exportMeta);
    }
    if ($view === 'ready-to-sale') {
        $data = [['Assignment', 'Kaligad', 'Ornament', 'Size/Design', 'Received on', 'Gross', 'Stone',
            'Net', 'Fine', 'Purity', 'Making charge', 'Held for', 'Order']];
        foreach ($readyToSale as $r) {
            $data[] = [$r['assignment_no'], (string) ($r['karigar_name'] ?? ''),
                (string) ($r['expected_ornament'] ?: $r['item_name'] ?? ''), (string) ($r['size_design'] ?? ''),
                (string) $r['receive_date'], $r['received_gross_weight'], $r['stone_weight'],
                $r['net_gold_weight'], $r['received_fine_weight'], (string) ($r['purity_code'] ?? ''),
                $r['making_amount'] ?? 0,
                (string) ($r['reserved_for'] ?? '') !== '' ? (string) $r['reserved_for'] : 'On the shelf',
                (string) ($r['reserved_order_no'] ?? '')];
        }
        export_dispatch($exportFormat, 'jewellery-ready-to-sale-' . $stamp, $data, 'Ready to Sale', $exportMeta);
    }
    if ($view === 'output') {
        // Through the shared flattener, so the register, its spreadsheet and
        // its printed sheet carry the same columns in the same order.
        export_dispatch($exportFormat, 'jewellery-workshop-output-' . $stamp,
            jewellery_output_export_rows($output, $sym), 'Workshop Output', $exportMeta);
    }
    if ($view === 'karigars') {
        $data = [['Code', 'Name', 'Phone', 'Engagement', 'Making basis', 'Making rate',
            'Wastage allowed %', 'Status']];
        foreach ($karigars as $r) {
            $data[] = [$r['code'], $r['name'], (string) ($r['phone'] ?? ''), $r['engagement_type'],
                (string) ($r['default_making_basis'] ?? ''), $r['default_making_rate'] ?? 0,
                $r['wastage_allowed_pct'] ?? 0, $r['status']];
        }
        export_dispatch($exportFormat, 'jewellery-kaligads-' . $stamp, $data, 'Kaligads', $exportMeta);
    }
    if ($view === 'refinery') {
        $data = [['Job', 'Refiner', 'Issued', 'Out (fine)', 'Back (fine)', 'Loss (fine)', 'Surplus (fine)',
            'Charges', 'Status']];
        foreach ($jobs as $r) {
            $data[] = [$r['job_no'], (string) ($r['party_name'] ?? ''), $r['issue_date'],
                $r['issued_fine_weight'], $r['received_fine_weight'] ?? 0, $r['loss_fine_weight'] ?? 0,
                $r['surplus_fine_weight'] ?? 0, $r['charges_amount'] ?? 0, $r['status']];
        }
        export_dispatch($exportFormat, 'jewellery-refinery-' . $stamp, $data, 'Refinery Jobs', $exportMeta);
    }
    // A view without an export (delivery) simply falls through to the screen.
}

$pageTitle = 'Jewellery — Orders, Kaligad & Refinery';
$pageSubtitle = 'Daily order management, metal issued to kaligads, wage and wastage settlement, and refinery jobs.';
$pageHero = ['icon' => 'coins'];
$bodyClass = 'admin-layout accounting-module-page';
$pageBreadcrumb = [['Home', 'admin/index.php'], ['Jewellery', 'admin/jewellery.php'], ['Workshop', 'admin/jewellery-workshop.php']];
include __DIR__ . '/../../app/views/partials/admin_header.php';

$fmt = static fn (?float $n, int $p = 2): string => $n === null ? 'N/A' : number_format($n, $p);
$statusTone = ['draft' => 'tone-gray', 'confirmed' => 'tone-blue', 'assigned' => 'tone-amber',
    'partially_received' => 'tone-amber', 'received' => 'tone-teal', 'invoiced' => 'tone-blue',
    'delivered' => 'tone-green', 'closed' => 'tone-green', 'cancelled' => 'tone-red'];
// ucfirst('partially_received') is not a label a person should read.
$statusLabel = static fn (string $s): string => ucwords(str_replace('_', ' ', $s));
jw_line_grid_styles();
jw_filter_bar_styles();
?>

<nav class="mbw-tabbar" aria-label="Jewellery workshop sections" style="flex-wrap:wrap">
    <a class="mbw-tab" href="<?= e(url('admin/jewellery.php')) ?>"><?= icon('dashboard') ?> Jewellery Home</a>
    <?php foreach ([
        'orders' => ['Orders', 'journal'], 'assignments' => ['Metal Issued', 'scale'],
        'delivery' => ['Ready to Deliver', 'box'], 'ready-to-sale' => ['Ready to Sale', 'cart'],
        'output' => ['Workshop Output', 'reports'], 'karigars' => ['Kaligads', 'teams'],
        'refinery' => ['Refinery', 'layers'],
    ] as $tabView => [$tabLabel, $tabIcon]): ?>
        <a class="mbw-tab <?= $view === $tabView ? 'is-active' : '' ?>" href="<?= e(url('admin/jewellery-workshop.php?view=' . $tabView)) ?>"><?= icon($tabIcon) ?> <?= $tabLabel ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($view === 'orders'): ?>
    <?php
        // One advance can be paid several ways at once — 20,000 cash, 15,000
        // on Fonepay and an old ring for the rest is ONE advance, not three.
        // Defined HERE, above the form, because the same grid now serves the
        // NEW order form (the advance is taken while the order is written,
        // not on a second visit) and the edit page's take/refund forms.
        // Built at most once, however many times the grid below is drawn.
        $tenderItemOptions = static function () use ($items): string {
            $html = '';
            foreach ($items as $it) {
                $html .= '<option value="' . (int) $it['id'] . '" data-metal="' . (int) $it['metal_id'] . '">'
                    . e($it['code'] . ' — ' . $it['name'] . ' · ' . $it['metal_name']) . '</option>';
            }

            return $html;
        };
        $tenderGrid = static function (string $goldWord) use ($cashBankLedgers, $items, $purities, $units, $editOrder, $sym, $tenderItemOptions): void { ?>
            <fieldset class="jw-lines-box" style="border:1px solid var(--mbw-border,#d9e2ec);border-radius:10px;padding:10px;margin:6px 0;min-width:0;grid-column:1/-1">
                <legend style="padding:0 6px;font-weight:600">How it is paid — several ways at once is fine</legend>
                <div class="jw-lines-scroll"><table class="jw-lines">
                    <thead><tr>
                        <th style="min-width:130px">Paid by</th>
                        <th style="min-width:150px">Ledger</th>
                        <th style="min-width:110px">Reference</th>
                        <th style="min-width:150px"><?= e($goldWord) ?> item</th>
                        <th style="min-width:90px">Purity</th>
                        <th style="min-width:70px">Unit</th>
                        <th style="min-width:90px">Weight</th>
                        <th style="min-width:100px">Amount (<?= e($sym) ?>)</th>
                        <th style="width:38px"></th>
                    </tr></thead>
                    <tbody>
                        <tr>
                            <td>
                                <select name="tender_mode[]" class="jw-tender-mode">
                                    <option value="cash">Cash</option>
                                    <option value="bank">Bank</option>
                                    <option value="card">Card</option>
                                    <option value="cheque">Cheque</option>
                                    <option value="qr">QR / Fonepay</option>
                                    <option value="wallet">Mobile wallet</option>
                                    <option value="metal"><?= e($goldWord) ?></option>
                                    <option value="other">Other…</option>
                                </select>
                                <input type="text" name="tender_label[]" class="jw-tender-label" placeholder="name it" style="display:none;margin-top:4px" maxlength="60">
                            </td>
                            <td>
                                <select name="tender_ledger_id[]">
                                    <option value="0">— mapped / not money —</option>
                                    <?php foreach ($cashBankLedgers as $l): ?>
                                        <option value="<?= (int) $l['id'] ?>"><?= e(($l['code'] ? $l['code'] . ' — ' : '') . $l['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" name="tender_reference[]" placeholder="chq / txn no." maxlength="60"></td>
                            <td>
                                <?php // This grid is drawn three times on the page. One list,
                                      // shared between them. See shared_options(). ?>
                                <?php $tenderOpts = shared_options('jw-tender-items', $tenderItemOptions); ?>
                                <select name="tender_item_id[]" class="jw-tender-item"<?= $tenderOpts['fill'] ? ' data-fill-from="jw-tender-items"' : '' ?>>
                                    <option value="0" data-metal="0">— money, not metal —</option>
                                    <?= $tenderOpts['html'] ?>
                                </select>
                            </td>
                            <td>
                                <select name="tender_purity_id[]" class="jw-tender-purity">
                                    <?php foreach ($purities as $p): ?>
                                        <option value="<?= (int) $p['id'] ?>" data-metal="<?= (int) $p['metal_id'] ?>" <?= (int) ($editOrder['purity_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['metal_code'] . '·' . $p['code']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="tender_unit_id[]">
                                    <?php foreach ($units as $u): ?>
                                        <option value="<?= (int) $u['id'] ?>" <?= (int) ($editOrder['unit_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['code']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="number" name="tender_gross_weight[]" step="0.0001" min="0" value="0"></td>
                            <td><input type="number" name="tender_amount[]" class="jw-tender-amount" step="0.01" min="0" value="0"></td>
                            <td><button type="button" class="button secondary jw-line-remove mbw-delete-action" title="Delete this payment row" aria-label="Delete this payment row"><?= icon('trash') ?></button></td>
                        </tr>
                    </tbody>
                </table></div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;gap:10px;flex-wrap:wrap">
                    <button type="button" class="button secondary jw-line-add" style="min-height:30px;padding:4px 12px">+ Another way of paying</button>
                    <strong>Total: <?= e($sym) ?> <span class="jw-tender-total">0.00</span></strong>
                </div>
            </fieldset>
        <?php };
    ?>
    <?php if ($canEdit): ?>
    <?php if (!$editOrder): ?>
    <div class="jw-new-order-launch" style="margin:0 0 14px">
        <button type="button" class="button" id="jw-new-order-open"><?= icon('plus') ?> New Order</button>
    </div>
    <?php endif; ?>
    <section class="mbw-card" id="jw-order-editor" data-collapsible>
        <div class="mbw-card-head">
            <h2><?= $editOrder ? 'Edit Order — ' . e((string) $editOrder['order_no']) : 'New Order' ?></h2>
            <?php if ($editOrder): ?><a class="mbw-view-all" href="<?= e(url('admin/jewellery-workshop.php?view=orders')) ?>">New order</a><?php endif; ?>
            <button type="button" class="button secondary" id="jw-order-close">Close</button>
        </div>
        <form method="post" data-jw-order-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_order">
            <input type="hidden" name="back_view" value="orders">
            <input type="hidden" name="order_id" value="<?= (int) ($editOrder['id'] ?? 0) ?>">
            <div class="workspace-form-grid">
                <?php // Every header input reads through $orderField(): the value the
                      // clerk typed when a refused save is being corrected, else the
                      // stored order being revised, else blank. ?>
                <label>Order date<input type="date" name="order_date" data-jw-required="Order date" value="<?= e((string) ($orderField('order_date', $todayInFy) ?: $todayInFy)) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>" required>
                </label>
                <label>Promised delivery<input type="date" name="delivery_date" value="<?= e((string) $orderField('delivery_date')) ?>"></label>
                <label>Existing customer
                    <select name="party_id" data-jw-customer-select>
                        <option value="0">— new customer →</option>
                        <?php foreach ($partyGroups as $partyGroupLabel => $groupedParties): ?>
                            <?php if ($partyGroupLabel !== ''): ?><optgroup label="<?= e($partyGroupLabel) ?>"><?php endif; ?>
                            <?php foreach ($groupedParties as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" <?= (int) $orderField('party_id', 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                            <?php endforeach; ?>
                            <?php if ($partyGroupLabel !== ''): ?></optgroup><?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label hidden style="display:none !important">Customer name<input type="text" name="customer_name" data-jw-customer-name maxlength="190" value="<?= e((string) $orderField('customer_name')) ?>" placeholder="Creates the customer and their ledger"></label>
                <label>Phone<input type="text" name="customer_phone" maxlength="60" value="<?= e((string) $orderField('customer_phone')) ?>"></label>
                <?php // Order taken by. The list is the payroll employees; the box
                      // beside it is for whoever is not on that list yet, so the
                      // counter is never stopped by an employee record that has
                      // not been created. Choosing from the list wins.
                      $takenById = (int) $orderField('sales_employee_id', 0);
                      $takenByName = (string) $orderField('sales_person', ''); ?>
                <label>Order taken by
                    <select name="sales_employee_id" id="jw-order-taker">
                        <option value="0"><?= $orderEmployees ? '— not on the list —' : '— no employees on file —' ?></option>
                        <?php foreach ($orderEmployees as $employeeRow): ?>
                            <?php $employeeLabel = trim((string) ($employeeRow['full_name'] ?? '')) ?: (string) $employeeRow['employee_code']; ?>
                            <option value="<?= (int) $employeeRow['id'] ?>" <?= $takenById === (int) $employeeRow['id'] ? 'selected' : '' ?>><?= e($employeeLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>… or type the name
                    <input type="text" name="sales_person" id="jw-order-taker-name" maxlength="120"
                            placeholder="who served the customer"
                            value="<?= e($takenById > 0 ? '' : $takenByName) ?>"
                            <?= $takenById > 0 ? 'disabled' : '' ?>>
                </label>
                <script>
                (function () {
                    // One answer, not two. Picking an employee greys the typed box
                    // out so an order cannot carry a name that disagrees with the
                    // employee it is filed against.
                    var picker = document.getElementById('jw-order-taker');
                    var typed = document.getElementById('jw-order-taker-name');
                    if (!picker || !typed) { return; }
                    function sync() {
                        var chosen = parseInt(picker.value, 10) > 0;
                        typed.disabled = chosen;
                        if (chosen) {
                            typed.value = '';
                            typed.placeholder = picker.options[picker.selectedIndex].textContent.trim();
                        } else {
                            typed.placeholder = 'who served the customer';
                        }
                    }
                    picker.addEventListener('change', sync);
                    sync();
                })();
                </script>
                <label>Address<input type="text" name="customer_address" maxlength="255"></label>
                <?php // The reference is usually minted by the engine (JO-2083-000001);
                      // typing one keeps a shop's own numbering. Uniqueness is enforced
                      // on save with a sentence, not a stack trace. It stays editable on
                      // edit too, so a number typed wrong — or one the shop numbered by
                      // hand before the sequence was set — can still be corrected.
                      // Clearing the box keeps the number it already has.
                      //
                      // Once the order has been billed the number is settled — the
                      // customer is holding a copy with it printed on it. The engine
                      // refuses that change anyway; showing the box locked saves them
                      // typing a correction that was never going to be accepted.
                      $numberSettled = $editOrder
                          && in_array((string) ($editOrder['status'] ?? ''), ['invoiced', 'delivered', 'closed'], true);
                      $orderNoAttrs = $numberSettled
                          ? 'readonly title="Already billed — the customer\'s copy carries this number"'
                          : ($editOrder ? 'placeholder="blank keeps the current number"'
                                        : 'placeholder="auto — e.g. JO-2083-000001"'); ?>
                <label>Order no.<input type="text" name="order_no" maxlength="60"
                        value="<?= e((string) $orderField('order_no')) ?>" <?= $orderNoAttrs ?>></label>
                <?php // What the customer actually said — "bridal set", "ring like my
                      // mother's" — searchable and printed on the slip. ?>
                <label>Expected item<input type="text" name="expected_item" maxlength="190"
                        placeholder="e.g. Gold ring, bridal set, diamond pendant"
                        value="<?= e((string) $orderField('expected_item')) ?>"></label>
                <label>Design no.<input type="text" name="design_no" maxlength="60" value="<?= e((string) $orderField('design_no')) ?>"></label>
                <label>Other charges (<?= e($sym) ?>)<input type="number" name="other_charges" step="0.01" min="0" value="<?= e((string) $orderField('other_charges', '0')) ?>"></label>
                <label>Discount (<?= e($sym) ?>)<input type="number" name="discount" step="0.01" min="0" value="<?= e((string) $orderField('discount', '0')) ?>"></label>
                <label>Skills Promotion Tax (<?= e($sym) ?>)<input type="number" name="manual_tax_amount" step="0.01" min="0" placeholder="auto" value="<?= e((string) $orderField('manual_tax_amount', '')) ?>">
                </label>
                <label>Advance taken (<?= e($sym) ?>)<input type="number" name="advance_amount" step="0.01" min="0" value="<?= e((string) $orderField('advance_amount', '0')) ?>"></label>
                <label>Status
                    <select name="status">
                        <?php foreach (['draft' => 'Draft', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled'] as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= (string) $orderField('status', 'confirmed') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="grid-column:1/-1">Description<input type="text" name="description" maxlength="255" value="<?= e((string) $orderField('description')) ?>"></label>
            </div>

            <?php
                // The SAME grid the sale uses. One customer can order a ring and
                // a chain and a pair of bangles on one order; each is a line,
                // and each is priced by the engine that will bill it.
                jw_render_line_grid('l', $orderLines, max(3, count($orderLines) + 2), 'Items ordered', [
                    'items' => $items, 'purities' => $purities, 'units' => $units,
                    'base_unit' => $baseUnit, 'fmt' => $fmt, 'on_hand' => $orderOnHand,
                    // Handing over a kaligad list is what turns on the two
                    // workshop columns: who makes each piece, and when it is due.
                    'karigars' => $karigars,
                    // And handing over the shelf turns on the third: not every
                    // item ordered has to be made.
                    'stock_pieces' => $orderStockPieces,
                ]);
            ?>


            <?php if ($editOrder && (float) $editOrder['total_amount'] > 0): ?>
                <?php
                    $orderTotal = (float) $editOrder['total_amount'];
                    $orderAdvanceHeld = (float) $orderAdvances['total'];
                    $stillPayable = round($orderTotal - $orderAdvanceHeld, 2);
                ?>
                <div style="margin-top:12px;border:1px solid var(--mbw-border,#d9e2ec);border-radius:10px;padding:12px">
                    <h3 style="margin:0 0 8px;font-size:1rem">What the customer pays</h3>
                    <div class="mbw-tablewrap"><table style="max-width:520px">
                        <tbody>
                            <?php foreach ([
                                ['Metal', (float) $editOrder['metal_amount']],
                                ['Making', (float) $editOrder['making_amount']],
                                ['Stone / diamond', (float) $editOrder['stone_amount'] + (float) $editOrder['diamond_amount']],
                                ['Other charges', (float) $editOrder['other_charges']],
                                ['Discount', -(float) $editOrder['discount']],
                                ['Non taxable amt', (float) $editOrder['non_taxable_amount']],
                                ['SD taxable amt', (float) $editOrder['sd_taxable_amount']],
                                ['Skills Promotion Tax', (float) $editOrder['tax_amount']],
                                ['Vatable amt', (float) $editOrder['vatable_amount']],
                                ['VAT', (float) $editOrder['vat_amount']],
                            ] as [$quoteLabel, $quoteValue]): ?>
                                <?php if (abs($quoteValue) < 0.005) { continue; } ?>
                                <tr><td><?= e($quoteLabel) ?></td><td class="is-numeric"><?= $fmt($quoteValue) ?></td></tr>
                            <?php endforeach; ?>
                            <tr style="border-top:2px solid var(--mbw-border,#d9e2ec)">
                                <td><strong>Total payable</strong></td>
                                <td class="is-numeric"><strong><?= e($sym) ?> <?= $fmt($orderTotal) ?></strong></td>
                            </tr>
                            <?php if ($orderAdvanceHeld > 0.005): ?>
                                <tr><td>Less advance already taken</td><td class="is-numeric">(<?= $fmt($orderAdvanceHeld) ?>)</td></tr>
                                <tr><td><strong>Due on delivery</strong></td><td class="is-numeric"><strong><?= e($sym) ?> <?= $fmt($stillPayable) ?></strong></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table></div>
                </div>
            <?php endif; ?>

            <?php if (!$editOrder): ?>
                <?php // The advance is taken WHILE the order is written — the customer
                      // is standing at the counter with the money in hand, not coming
                      // back after somebody re-opens the order in edit mode. Rows left
                      // at zero mean no advance; anything entered is recorded and
                      // posted together with the order, split across as many ways of
                      // paying as the customer actually used. ?>
                <div style="margin-top:12px;border:1px solid var(--mbw-border,#d9e2ec);border-radius:10px;padding:12px">
                    <h3 style="margin:0 0 4px;font-size:1rem">Advance taken now — optional</h3>
                    <p style="margin:0 0 8px;color:var(--mbw-muted,#64748b)">
                        Leave the amounts at zero if nothing is being put down.
                        The advance posts with the order and is held for this customer.
                    </p>
                    <div class="workspace-form-grid">
                        <label>Advance date<input type="date" name="advance_date" value="<?= e($todayInFy) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>"></label>
                        <?php $tenderGrid('Old gold'); ?>
                    </div>
                </div>
            <?php endif; ?>

            <div style="margin-top:12px"><button type="submit" class="button"><?= $editOrder ? 'Update Order' : 'Save Order' ?></button></div>
        </form>
    </section>
    <style>
        .jw-field-invalid { border-color: var(--mbw-red, #e5484d) !important; box-shadow: 0 0 0 2px rgba(229, 72, 77, 0.15); }
        /* The text takes the red TOKEN, not a darker shade of it. A fixed dark
           red reads well on the light theme's pale panel and disappears into
           the dark theme's translucent one, which is the whole reason the
           tokens exist. */
        .jw-form-errors { border: 1px solid var(--mbw-red, #e5484d); background: var(--mbw-red-soft, #fdeaea);
            color: var(--mbw-red, #e5484d); border-radius: 8px; padding: 10px 12px; margin: 0 0 10px; }
    </style>
    <script>
    (function () {
        var editor = document.getElementById('jw-order-editor');
        var open = document.getElementById('jw-new-order-open');
        var close = document.getElementById('jw-order-close');
        if (!editor || !close || !window.HTMLDialogElement) { return; }
        var dialog = document.createElement('dialog');
        dialog.className = 'jw-order-dialog';
        dialog.setAttribute('aria-label', <?= $editOrder ? "'Edit jewellery order'" : "'New jewellery order'" ?>);
        editor.parentNode.insertBefore(dialog, editor);
        dialog.appendChild(editor);
        if (open) {
            open.addEventListener('click', function () { dialog.showModal(); });
        } else {
            dialog.showModal();
        }
        close.addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) { dialog.close(); }
        });
        <?php if ($editOrder): ?>
        // Advance records belong to the order being edited. Put their summary and
        // actions in the same modal instead of leaving a second form behind it.
        window.setTimeout(function () {
            var advanceManager = document.getElementById('jw-order-advance-manager');
            if (!advanceManager) { return; }
            dialog.appendChild(advanceManager);
            advanceManager.querySelectorAll('[data-jw-advance-toggle]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var target = button.getAttribute('data-jw-advance-toggle');
                    advanceManager.querySelectorAll('[data-jw-advance-form]').forEach(function (form) {
                        form.hidden = form.getAttribute('data-jw-advance-form') !== target;
                    });
                    var form = advanceManager.querySelector('[data-jw-advance-form="' + target + '"]');
                    if (form) { form.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                });
            });
        }, 0);
        <?php endif; ?>
    })();

    // The save button never clears a half-finished order. What is missing is
    // NAMED, the field is marked and focused, and nothing leaves the page
    // until it is whole — the server's own checks remain behind this, and a
    // server-side refusal comes back with every typed value intact.
    (function () {
        var form = document.querySelector("[data-jw-order-form]");
        if (!form) { return; }
        form.addEventListener("submit", function (event) {
            var problems = [];
            form.querySelectorAll(".jw-field-invalid").forEach(function (field) {
                field.classList.remove("jw-field-invalid");
            });
            var existingBox = form.querySelector(".jw-form-errors");
            if (existingBox) { existingBox.remove(); }

            var orderDate = form.querySelector('[name="order_date"]');
            if (orderDate && !orderDate.value) {
                problems.push({ field: orderDate, message: "Order date is required." });
            }
            var partySelect = form.querySelector("[data-jw-customer-select]");
            var nameInput = form.querySelector("[data-jw-customer-name]");
            var hasParty = partySelect && parseInt(partySelect.value, 10) > 0;
            var hasName = nameInput && nameInput.value.trim() !== "";
            if (!hasParty && !hasName) {
                problems.push({ field: nameInput || partySelect,
                    message: "Choose an existing customer or type the new customer's name." });
            }

            if (problems.length === 0) { return; }
            event.preventDefault();
            var box = document.createElement("div");
            box.className = "jw-form-errors";
            box.innerHTML = "<strong>The order is not saved yet:</strong><ul style=\"margin:6px 0 0 18px\">"
                + problems.map(function (p) { return "<li>" + p.message + "</li>"; }).join("")
                + "</ul>";
            form.insertBefore(box, form.firstChild);
            problems.forEach(function (p) { if (p.field) { p.field.classList.add("jw-field-invalid"); } });
            if (problems[0].field) { problems[0].field.focus(); }
            box.scrollIntoView({ behavior: "smooth", block: "center" });
        });
    })();
    </script>
    <?php endif; ?>

    <?php if ($editOrder && $canEdit): ?>
    <section class="mbw-card" id="jw-order-advance-manager" style="margin-top:14px">
        <div class="mbw-card-head">
            <h2>Advance Management — <?= e((string) $editOrder['order_no']) ?></h2>
        </div>

        <div class="mbw-stat-row" style="margin-bottom:14px">
            <div class="mbw-stat"><span>Cash / bank held</span><strong><?= e($sym) ?> <?= $fmt((float) $orderAdvances['cash_total']) ?></strong></div>
            <div class="mbw-stat"><span>Old gold held</span><strong><?= e($sym) ?> <?= $fmt((float) $orderAdvances['metal_total']) ?></strong></div>
            <div class="mbw-stat"><span>Total advance</span><strong><?= e($sym) ?> <?= $fmt((float) $orderAdvances['total']) ?></strong></div>
            <div class="mbw-stat"><span>Still unapplied</span><strong><?= e($sym) ?> <?= $fmt((float) $advanceAvailable) ?></strong></div>
        </div>

        <?php if ($orderAdvances['rows'] !== []): ?>
        <div class="mbw-tablewrap"><table>
            <thead><tr><th>Date</th><th>Ref</th><th>What</th><th class="is-numeric">Weight</th><th class="is-numeric">Value</th></tr></thead>
            <tbody>
                <?php foreach ($orderAdvances['rows'] as $adv): ?>
                    <tr>
                        <td><?= e(app_date((string) $adv['settlement_date'])) ?></td>
                        <td><?= e((string) $adv['settlement_no']) ?></td>
                        <td>
                            <?= (string) $adv['direction'] === 'paid' ? 'Refunded' : 'Received' ?> —
                            <?php if ((string) $adv['mode'] === 'mixed'): ?>
                                <?php
                                    // One payment, several ways at once: name each part,
                                    // because 'mixed' alone tells the counter nothing.
                                    $advParts = [];
                                    foreach (jewellery_settlement_tenders($companyId, (int) $adv['id']) as $advTender) {
                                        $advParts[] = jw_tender_mode_label((string) $advTender['mode'], $advTender['mode_label'] ?? null)
                                            . ' ' . number_format((float) $advTender['amount'], 2);
                                    }
                                ?>
                                <small><?= e(implode(' + ', $advParts)) ?></small>
                            <?php else: ?>
                                <?= e(jw_tender_mode_label((string) $adv['mode'])) ?>
                                <?php if ((string) $adv['mode'] === 'metal'): ?>
                                    <small><?= e((string) ($adv['item_code'] ?? '')) ?> <?= e((string) ($adv['purity_code'] ?? '')) ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="is-numeric"><?= (float) $adv['gross_weight'] > 0
                            ? $fmt((float) $adv['gross_weight'], 4) . ' ' . e((string) ($adv['unit_code'] ?? '')) : '—' ?></td>
                        <td class="is-numeric"><?= (string) $adv['direction'] === 'paid' ? '(' . $fmt((float) $adv['amount']) . ')' : $fmt((float) $adv['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>


        <div class="jw-advance-actions" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px">
            <button type="button" class="button" data-jw-advance-toggle="record">Record New Advance</button>
            <?php if ((float) $advanceAvailable > 0.005): ?>
                <button type="button" class="button secondary" data-jw-advance-toggle="refund">Refund Advance</button>
            <?php endif; ?>
        </div>

        <form method="post" class="workspace-form-grid jw-advance-form" data-jw-advance-form="record" hidden>
            <h3 style="grid-column:1/-1;margin:16px 0 0">Record New Advance</h3>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_advance">
            <input type="hidden" name="back_view" value="orders">
            <input type="hidden" name="order_id" value="<?= (int) $editOrder['id'] ?>">
            <label>Date<input type="date" name="advance_date" value="<?= e($todayInFy) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>"></label>
            <?php $tenderGrid('Old gold'); ?>
            <div style="grid-column:1/-1">
                <button type="submit" class="button">Record Advance</button>
            </div>
        </form>

        <?php if ((float) $advanceAvailable > 0.005): ?>
        <form method="post" class="workspace-form-grid jw-advance-form" data-jw-advance-form="refund" hidden>
            <h3 style="grid-column:1/-1;margin:16px 0 0">Refund Advance</h3>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="refund_advance">
            <input type="hidden" name="back_view" value="orders">
            <input type="hidden" name="order_id" value="<?= (int) $editOrder['id'] ?>">
            <label>Date<input type="date" name="advance_date" value="<?= e($todayInFy) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>"></label>
            <div style="grid-column:1/-1;color:var(--mbw-muted,#64748b)">
                <?= e($sym) ?> <?= $fmt((float) $advanceAvailable) ?> is still held. A refund can also be split —
                part cash back, part gold back — and it must not exceed what is held.
            </div>
            <?php $tenderGrid('Gold back'); ?>
            <div style="grid-column:1/-1">
                <button type="submit" class="button secondary">Refund Advance</button>
            </div>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Orders (<?= count($orders) ?>)</h2><span><?= $canExport ? $exportLinks() : '' ?></span></div>
        <?php
        $orderSourceLabels = jewellery_order_sources();
        $orderSourceTones = jewellery_order_source_tones();
        $orderSourceFilter = jw_enum($_GET['source'] ?? null, array_keys($orderSourceLabels), '');
        ?>
        <form method="get" style="margin-bottom:8px;display:flex;gap:10px;flex-wrap:nowrap;align-items:end;overflow-x:auto;padding:2px 0 8px">
            <input type="hidden" name="view" value="orders">
            <?php if ($sortParam !== ''): ?><input type="hidden" name="sort" value="<?= e($sortParam) ?>"><?php endif; ?>
            <label style="display:grid;gap:4px;flex:1.3 0 190px;margin:0"><span style="font-size:12.5px">Order no.</span><select name="q" class="js-searchable" aria-label="Search or select an order number"><option value="">— all orders —</option><?php foreach ($orderNumberOptions as $orderNumber): ?><option value="<?= e((string) $orderNumber) ?>" <?= $filterSearch === (string) $orderNumber ? 'selected' : '' ?>><?= e((string) $orderNumber) ?></option><?php endforeach; ?></select></label>
            <input type="hidden" name="from" id="jw-orders-date-from" value="<?= e($filterFrom) ?>">
            <input type="hidden" name="to" id="jw-orders-date-to" value="<?= e($filterTo) ?>">
            <label style="display:grid;gap:4px;flex:1 0 230px;margin:0"><span style="font-size:12.5px">Date range</span><button type="button" class="field-compact" id="jw-orders-date-range-open" style="text-align:left;white-space:nowrap" aria-haspopup="dialog"><span id="jw-orders-date-range-label"><?= e($filterFrom !== '' || $filterTo !== '' ? ($filterFrom !== '' ? app_date($filterFrom) : 'Start') . ' – ' . ($filterTo !== '' ? app_date($filterTo) : 'End') : 'All dates') ?></span></button></label>
            <label style="display:grid;gap:4px;flex:1 0 150px;margin:0"><span style="font-size:12.5px">Status</span><?= jw_filter_select('status', $filterStatus, ['draft' => 'Draft', 'confirmed' => 'Confirmed', 'assigned' => 'Assigned', 'partially_received' => 'Partially Received', 'received' => 'Received', 'invoiced' => 'Invoiced', 'delivered' => 'Delivered', 'closed' => 'Closed', 'cancelled' => 'Cancelled']) ?></label>
            <label style="display:grid;gap:4px;flex:1 0 180px;margin:0"><span style="font-size:12.5px">Customer</span><?= jw_filter_select('party', (string) $filterParty, array_column($parties, 'name', 'id')) ?></label>
            <label style="display:grid;gap:4px;flex:1 0 150px;margin:0"><span style="font-size:12.5px">Kaligad</span><?= jw_filter_select('karigar', (string) $filterKarigar, array_column($karigars, 'code', 'id')) ?></label>
            <label style="display:grid;gap:4px;flex:1 0 170px;margin:0"><span style="font-size:12.5px">Past due, uncollected</span><?= jw_filter_select('overdue', $filterOverdue ? '1' : '', ['1' => 'Only these'], '— all —') ?></label>
            <label style="display:grid;gap:4px;flex:1 0 160px;margin:0">
                <span style="font-size:12.5px">Order type</span>
                <select name="source" class="field-compact" aria-label="How the order is being fulfilled">
                    <option value="">All types</option>
                    <?php foreach ($orderSourceLabels as $sourceKey => $sourceLabel): ?>
                        <option value="<?= e($sourceKey) ?>" <?= $orderSourceFilter === $sourceKey ? 'selected' : '' ?>><?= e($sourceLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="button secondary"><?= icon('filter') ?> Filter</button>
            <a class="button secondary" href="<?= e(url('admin/jewellery-workshop.php?view=orders')) ?>">Clear</a>
        </form>
        <dialog id="jw-orders-date-range-dialog" aria-label="Choose order date range" style="width:min(430px,calc(100vw - 32px));border:0;border-radius:14px;padding:20px;box-shadow:0 18px 48px rgba(0,0,0,.28)">
            <form method="dialog">
                <h2 style="margin:0 0 16px">Date range</h2>
                <div class="workspace-form-grid">
                    <label>From<input type="date" id="jw-orders-date-from-picker" value="<?= e($filterFrom) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>"></label>
                    <label>To<input type="date" id="jw-orders-date-to-picker" value="<?= e($filterTo) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>"></label>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:18px">
                    <button type="submit" class="button secondary">Cancel</button>
                    <button type="button" class="button" id="jw-orders-date-range-apply">Apply date range</button>
                </div>
            </form>
        </dialog>
        <script>
        (function () {
            var open = document.getElementById('jw-orders-date-range-open');
            var dialog = document.getElementById('jw-orders-date-range-dialog');
            var from = document.getElementById('jw-orders-date-from');
            var to = document.getElementById('jw-orders-date-to');
            var fromPicker = document.getElementById('jw-orders-date-from-picker');
            var toPicker = document.getElementById('jw-orders-date-to-picker');
            var apply = document.getElementById('jw-orders-date-range-apply');
            var label = document.getElementById('jw-orders-date-range-label');
            if (!open || !dialog || !from || !to || !fromPicker || !toPicker || !apply || !label || !window.HTMLDialogElement) { return; }
            open.addEventListener('click', function () { dialog.showModal(); });
            apply.addEventListener('click', function () {
                from.value = fromPicker.value;
                to.value = toPicker.value;
                label.textContent = from.value || to.value ? (from.value || 'Start') + ' – ' + (to.value || 'End') : 'All dates';
                dialog.close();
            });
            dialog.addEventListener('click', function (event) { if (event.target === dialog) { dialog.close(); } });
        })();
        </script>
        <p style="color:var(--mbw-muted);font-size:12px;margin:0 0 12px"><strong>Made to order</strong> went to a kaligad · <strong>From showroom stock</strong> was set aside off the shelf.</p>
        <div class="mbw-tablewrap"><table>
            <thead><tr>
                <th><a href="?view=orders&sort=<?= strpos($sortParam, 'order_no') === 0 && strpos($sortParam, '_asc') ? 'order_no_desc' : 'order_no_asc' ?>" style="cursor:pointer;text-decoration:none">No. <?= strpos($sortParam, 'order_no') === 0 ? (strpos($sortParam, '_asc') ? '▲' : '▼') : '▼' ?></a></th>
                <th><a href="?view=orders&sort=<?= strpos($sortParam, 'order_date') === 0 && strpos($sortParam, '_asc') ? 'order_date_desc' : 'order_date_asc' ?>" style="cursor:pointer;text-decoration:none">Date <?= strpos($sortParam, 'order_date') === 0 ? (strpos($sortParam, '_asc') ? '▲' : '▼') : '▼' ?></a></th>
                <th><a href="?view=orders&sort=<?= strpos($sortParam, 'party') === 0 && strpos($sortParam, '_asc') ? 'party_desc' : 'party_asc' ?>" style="cursor:pointer;text-decoration:none">Customer <?= strpos($sortParam, 'party') === 0 ? (strpos($sortParam, '_asc') ? '▲' : '▼') : '▼' ?></a></th>
                <th><a href="?view=orders&sort=<?= strpos($sortParam, 'source') === 0 && strpos($sortParam, '_asc') ? 'source_desc' : 'source_asc' ?>" style="cursor:pointer;text-decoration:none">Type <?= strpos($sortParam, 'source') === 0 ? (strpos($sortParam, '_asc') ? '▲' : '▼') : '▼' ?></a></th>
                <th><a href="?view=orders&sort=<?= strpos($sortParam, 'metal') === 0 && strpos($sortParam, '_asc') ? 'metal_desc' : 'metal_asc' ?>" style="cursor:pointer;text-decoration:none">Metal <?= strpos($sortParam, 'metal') === 0 ? (strpos($sortParam, '_asc') ? '▲' : '▼') : '▼' ?></a></th>
                <th class="is-numeric"><a href="?view=orders&sort=<?= strpos($sortParam, 'weight') === 0 && strpos($sortParam, '_asc') ? 'weight_desc' : 'weight_asc' ?>" style="cursor:pointer;text-decoration:none">Expected wt <?= strpos($sortParam, 'weight') === 0 ? (strpos($sortParam, '_asc') ? '▲' : '▼') : '▼' ?></a></th>
                <th><a href="?view=orders&sort=<?= strpos($sortParam, 'delivery') === 0 && strpos($sortParam, '_asc') ? 'delivery_desc' : 'delivery_asc' ?>" style="cursor:pointer;text-decoration:none">Delivery <?= strpos($sortParam, 'delivery') === 0 ? (strpos($sortParam, '_asc') ? '▲' : '▼') : '▼' ?></a></th>
                <th><a href="?view=orders&sort=<?= strpos($sortParam, 'status') === 0 && strpos($sortParam, '_asc') ? 'status_desc' : 'status_asc' ?>" style="cursor:pointer;text-decoration:none">Status <?= strpos($sortParam, 'status') === 0 ? (strpos($sortParam, '_asc') ? '▲' : '▼') : '▼' ?></a></th>
                <th></th>
            </tr></thead>
            <tbody>
                <?php if ($orders === []): ?><tr><td colspan="9">No orders yet.</td></tr><?php endif; ?>
                <?php foreach ($orders as $row): ?>
                    <tr>
                        <td><?= e($row['order_no']) ?><?= ($row['design_no'] ?? '') !== '' ? '<br><small>' . e((string) $row['design_no']) . '</small>' : '' ?></td>
                        <td><?= e(app_date((string) $row['order_date'])) ?></td>
                        <td><?= e((string) ($row['party_name'] ?? $row['customer_name'] ?? 'Walk-in')) ?></td>
                        <?php
                        // How the customer is getting it: made for them by a
                        // kaligad, or set aside off the shelf. Neither is
                        // recorded as such — see jewellery_order_source_sql().
                        $rowSource = (string) ($row['order_source'] ?? 'pending');
                        ?>
                        <td><span class="mbw-pill <?= e($orderSourceTones[$rowSource] ?? 'tone-gray') ?>"><?= e((string) ($orderSourceLabels[$rowSource] ?? $rowSource)) ?></span></td>
                        <td><?= e($row['metal_name'] . ' · ' . $row['purity_code']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['expected_gross_weight'], 4) ?> <small><?= e($row['unit_code']) ?></small>
                            <?php // Actual weight and pure-metal content together — the pair a jewellery figure is read as. ?>
                            <?php if ((float) $row['expected_fine_weight'] > 0.00005): ?>
                                <br><small><?= $fmt((float) $row['expected_fine_weight'], 4) ?> fine</small>
                            <?php endif; ?>
                        </td>
                        <td><?= ($row['delivery_date'] ?? null) ? e(app_date((string) $row['delivery_date'])) : '—' ?></td>
                        <td><span class="mbw-pill <?= e($statusTone[$row['status']] ?? 'tone-gray') ?>"><?= e($statusLabel((string) $row['status'])) ?></span></td>
                        <td class="jw-order-action-cell">
                            <div class="jw-order-action-row">
                                <select class="jw-order-action-select"
                                        aria-label="Actions for order <?= e((string) $row['order_no']) ?>">
                                    <option value="">Actions</option>
                                    <?php if ($canEdit): ?>
                                        <option value="edit"
                                                data-url="<?= e(url('admin/jewellery-workshop.php?view=orders&edit=' . (int) $row['id'])) ?>">Edit order</option>
                                        <option value="preview"
                                                data-url="<?= e(url('admin/jewellery-print.php?doc=order&id=' . (int) $row['id'])) ?>">Preview / Print</option>
                                    <?php endif; ?>
                                    <?php if (in_array((string) $row['status'], ['draft', 'confirmed'], true) && $canPost): ?>
                                        <option value="assign"
                                                data-url="<?= e(url('admin/jewellery-assign.php?kind=customer&order=' . (int) $row['id'])) ?>">Assign to Kaligad</option>
                                    <?php endif; ?>
                                    <?php if ($canEdit && !in_array((string) $row['status'], ['invoiced', 'delivered', 'closed', 'cancelled'], true)): ?>
                                        <option value="postpone">Postpone delivery</option>
                                        <option value="cancel">Cancel order</option>
                                    <?php endif; ?>
                                    <?php if ($canEdit && in_array((string) $row['status'], ['draft', 'confirmed', 'cancelled'], true)): ?>
                                        <option value="delete">Delete order</option>
                                    <?php elseif ($canEdit): ?>
                                        <option value="" disabled>Delete unavailable</option>
                                    <?php endif; ?>
                                </select>

                                <?php if ($canEdit && !in_array((string) $row['status'], ['invoiced', 'delivered', 'closed', 'cancelled'], true)): ?>
                                    <form method="post" class="jw-order-postpone-form" hidden>
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="postpone_order">
                                        <input type="hidden" name="back_view" value="orders">
                                        <input type="hidden" name="order_id" value="<?= (int) $row['id'] ?>">
                                        <input type="date" name="delivery_date" required
                                               aria-label="New delivery date"
                                               value="<?= e((string) ($row['delivery_date'] ?? '')) ?>">
                                        <button type="submit" class="button soft">Save date</button>
                                        <button type="button" class="button soft jw-order-postpone-close"
                                                aria-label="Close postpone date">Close</button>
                                    </form>
                                    <form method="post" class="jw-order-cancel-form" hidden
                                          onsubmit="return confirm('Cancel order <?= e((string) $row['order_no']) ?>?')">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="cancel_order">
                                        <input type="hidden" name="back_view" value="orders">
                                        <input type="hidden" name="order_id" value="<?= (int) $row['id'] ?>">
                                    </form>
                                <?php endif; ?>

                                <?php if ($canEdit && in_array((string) $row['status'], ['draft', 'confirmed', 'cancelled'], true)): ?>
                                    <form method="post" class="jw-order-delete-form" hidden
                                          onsubmit="return confirm('Delete order <?= e((string) $row['order_no']) ?> for good?')">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_order">
                                        <input type="hidden" name="back_view" value="orders">
                                        <input type="hidden" name="order_id" value="<?= (int) $row['id'] ?>">
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <script data-jw-order-actions-script>
    (() => {
        const reset = (select) => {
            select.value = '';
        };

        document.addEventListener('change', (event) => {
            const select = event.target.closest('.jw-order-action-select');
            if (!select) return;

            const row = select.closest('.jw-order-action-row');
            const option = select.selectedOptions[0];
            const action = select.value;
            const url = option ? option.dataset.url : '';
            const postponeForm = row.querySelector('.jw-order-postpone-form');

            document.querySelectorAll('.jw-order-postpone-form').forEach((form) => {
                if (form !== postponeForm) form.hidden = true;
            });

            if (action === 'postpone' && postponeForm) {
                postponeForm.hidden = false;
                const firstDateField = postponeForm.querySelector('input:not([type="hidden"]), select');
                if (firstDateField) firstDateField.focus();
                reset(select);
                return;
            }

            if (postponeForm) postponeForm.hidden = true;

            if (action === 'edit' || action === 'assign') {
                window.location.href = url;
                return;
            }

            if (action === 'preview') {
                window.open(url, '_blank', 'noopener');
                reset(select);
                return;
            }

            if (action === 'cancel') {
                const form = row.querySelector('.jw-order-cancel-form');
                reset(select);
                if (form) form.requestSubmit();
                return;
            }

            if (action === 'delete') {
                const form = row.querySelector('.jw-order-delete-form');
                reset(select);
                if (form) form.requestSubmit();
                return;
            }

            reset(select);
        });

        document.addEventListener('click', (event) => {
            const close = event.target.closest('.jw-order-postpone-close');
            if (!close) return;
            const form = close.closest('.jw-order-postpone-form');
            if (form) form.hidden = true;
        });
    })();
    </script>

<?php elseif ($view === 'karigars'): ?>
    <?php if ($canEdit): ?>
    <section class="mbw-card" data-collapsible data-draggable>
        <div class="mbw-card-head">
            <h2><?= $editKarigar ? 'Edit Kaligad — ' . e((string) $editKarigar['code']) : 'Add Kaligad' ?></h2>
            <?php if ($editKarigar): ?><a class="mbw-view-all" href="<?= e(url('admin/jewellery-workshop.php?view=karigars')) ?>">Add new</a><?php endif; ?>
        </div>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_karigar">
            <input type="hidden" name="back_view" value="karigars">
            <input type="hidden" name="karigar_id" value="<?= (int) ($editKarigar['id'] ?? 0) ?>">
            <label>Code<input type="text" name="code" maxlength="40" value="<?= e((string) ($editKarigar['code'] ?? '')) ?>" required></label>
            <label>Name<input type="text" name="name" maxlength="190" value="<?= e((string) ($editKarigar['name'] ?? '')) ?>" required></label>
            <label>Phone<input type="text" name="phone" maxlength="60" value="<?= e((string) ($editKarigar['phone'] ?? '')) ?>"></label>
            <label>Engagement
                <select name="engagement_type">
                    <option value="contractor" <?= (string) ($editKarigar['engagement_type'] ?? 'contractor') === 'contractor' ? 'selected' : '' ?>>Contractor (bill-wise payable)</option>
                    <option value="employee" <?= (string) ($editKarigar['engagement_type'] ?? '') === 'employee' ? 'selected' : '' ?>>Employee (through payroll)</option>
                </select>
            </label>
            <label>Payroll employee (if employee)
                <select name="payroll_employee_id">
                    <option value="0">— none —</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= (int) $emp['id'] ?>" <?= (int) ($editKarigar['payroll_employee_id'] ?? 0) === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['employee_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Making basis
                <select name="default_making_basis">
                    <?php foreach (['per_unit_weight' => 'Per unit of weight', 'percent_of_metal' => '% of metal value', 'flat' => 'Flat'] as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= (string) ($editKarigar['default_making_basis'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Making rate<input type="number" name="default_making_rate" step="0.0001" min="0" value="<?= e((string) ($editKarigar['default_making_rate'] ?? '0')) ?>"></label>
            <?php // No standing wastage allowance. Metal is the shop's and the kaligad
                 // is paid a percentage for the work, so nothing is written off in
                 // advance. An allowance is granted on the RECEIPT, once somebody has
                 // seen what actually came back. ?>
            <label class="frm-check"><input type="checkbox" name="active" <?= $editKarigar === null || (string) $editKarigar['status'] === 'active' ? 'checked' : '' ?>> Active</label>
            <label style="grid-column:1/-1">Address<input type="text" name="address" maxlength="255" value="<?= e((string) ($editKarigar['address'] ?? '')) ?>"></label>
            <div style="grid-column:1/-1"><button type="submit" class="button"><?= $editKarigar ? 'Update Kaligad' : 'Add Kaligad' ?></button></div>
        </form>
    </section>
    <?php endif; ?>

    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Kaligads (<?= count($karigars) ?>)</h2><span><?= $canExport ? $exportLinks() : '' ?></span></div>
        <div class="mbw-tablewrap"><table>
            <thead><tr><th>Code</th><th>Name</th><th>Engagement</th><th>Making</th><th class="is-numeric">Metal held (fine)</th><th class="is-numeric">Work needs</th><th class="is-numeric">Excess / shortfall</th><th class="is-numeric">Wages payable</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if ($karigars === []): ?><tr><td colspan="11">No kaligads yet.</td></tr><?php endif; ?>
                <?php foreach ($karigars as $row): ?>
                    <?php
                        $pos = jewellery_karigar_position($companyId, (int) $row['id']);
                        // Metal goes out in weights a shop can hand over, not in the
                        // exact sum of the pieces it will become, so held and needed
                        // are not meant to agree. The DIFFERENCE is what is watched.
                        $bal = jewellery_karigar_metal_balance($companyId, (int) $row['id']);
                    ?>
                    <tr>
                        <td><?= e($row['code']) ?></td>
                        <td><?= e($row['name']) ?><?= ($row['phone'] ?? '') !== '' ? '<br><small>' . e((string) $row['phone']) . '</small>' : '' ?></td>
                        <td><span class="mbw-pill <?= (string) $row['engagement_type'] === 'contractor' ? 'tone-blue' : 'tone-teal' ?>"><?= e(ucfirst((string) $row['engagement_type'])) ?></span></td>
                        <td><?= $fmt((float) $row['default_making_rate'], 2) ?> <small><?= e(str_replace('_', ' ', (string) $row['default_making_basis'])) ?></small></td>
                        <td class="is-numeric"><?= $pos['fine_weight'] > 0 ? '<span class="mbw-pill tone-amber">' . $fmt($pos['fine_weight'], 4) . '</span>' : '—' ?></td>
                        <td class="is-numeric"><?= $bal['committed_fine'] > 0 ? $fmt($bal['committed_fine'], 4) : '—' ?></td>
                        <td class="is-numeric">
                            <?php if (abs($bal['difference_fine']) < 0.00005): ?>—
                            <?php elseif ($bal['difference_fine'] > 0): ?>
                                <span class="mbw-pill tone-blue" title="Holding more than the outstanding work needs">+<?= $fmt($bal['excess_fine'], 4) ?></span>
                            <?php else: ?>
                                <span class="mbw-pill tone-red" title="Not enough metal issued for the outstanding work">−<?= $fmt($bal['shortfall_fine'], 4) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="is-numeric"><?= $pos['wages_payable'] > 0 ? $fmt($pos['wages_payable']) : '—' ?></td>
                        <td><span class="mbw-pill <?= (string) $row['status'] === 'active' ? 'tone-green' : 'tone-gray' ?>"><?= e(ucfirst((string) $row['status'])) ?></span></td>
                        <td>
                            <?php if ($canEdit): ?>
                                <a class="mbw-view-all" href="<?= e(url('admin/jewellery-workshop.php?view=karigars&edit=' . (int) $row['id'])) ?>">Edit</a>
                                <?php // Deletable only while untouched — a kaligad with
                                      // issues or wage bills keeps their row and is marked
                                      // inactive instead; the engine says which. ?>
                                <form method="post" style="display:inline" data-confirm="Delete this kaligad? Only one with no issues and no wage bills can go.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_karigar">
                                    <input type="hidden" name="back_view" value="karigars">
                                    <input type="hidden" name="karigar_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button soft" style="min-height:28px;padding:2px 10px">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'assignments'): ?>
    <?php // Assigning the work and receiving it back each have their own page
          // now, because each is its own act with its own questions. What stays
          // here is the register of what is OUT: the metal actually handed over,
          // in as many instalments as the shop likes, and the cancel and reverse
          // that only make sense against a live issue. ?>
    <nav class="mbw-tabbar" style="margin-bottom:14px" aria-label="Where these moved">
        <a class="mbw-tab" href="<?= e(url('admin/jewellery-assign.php')) ?>"><?= icon('handshake') ?>Assign work to a kaligad</a>
        <a class="mbw-tab" href="<?= e(url('admin/jewellery-receive.php')) ?>"><?= icon('box') ?>Receive a finished piece</a>
    </nav>


    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Assignments (<?= count($assignments) ?>)</h2><span><?= $canExport ? $exportLinks() : '' ?></span></div>
        <?php jw_render_filter_bar([
            'hidden' => ['view' => 'assignments'],
            'search' => $filterSearch, 'from' => $filterFrom, 'to' => $filterTo,
            'min_date' => $fyStart, 'max_date' => $fyEnd,
            'reset' => url('admin/jewellery-workshop.php?view=assignments'),
            'advanced_in_use' => $advancedInUse,
            'advanced' => [
                ['label' => 'Status', 'html' => jw_filter_select('status', $filterStatus, [
                    'issued' => 'Issued', 'received' => 'Received', 'cancelled' => 'Cancelled',
                ])],
                ['label' => 'Kaligad', 'html' => jw_filter_select('karigar', (string) $filterKarigar,
                    array_column($karigars, 'code', 'id'))],
            ],
        ]); ?>
        <div class="mbw-tablewrap"><table>
            <thead><tr><th>Issue no.</th><th>Date</th><th>Kaligad</th><th>Order</th><th>Item</th><th class="is-numeric">Issued (fine)</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if ($assignments === []): ?><tr><td colspan="9">Nothing issued yet.</td></tr><?php endif; ?>
                <?php foreach ($assignments as $row): ?>
                    <tr>
                        <td><?= e($row['issue_no']) ?></td>
                        <td><?= e(app_date((string) $row['issue_date'])) ?></td>
                        <td><?= e($row['karigar_name']) ?></td>
                        <td><?= e((string) ($row['order_no'] ?? '—')) ?></td>
                        <td><?= e($row['item_code'] . ' · ' . $row['purity_code']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['issued_fine_weight'], 4) ?></td>
                        <td><span class="mbw-pill <?= (string) $row['status'] === 'issued' ? 'tone-amber' : ((string) $row['status'] === 'received' ? 'tone-green' : 'tone-gray') ?>"><?= e(ucfirst((string) $row['status'])) ?></span></td>
                        <td style="white-space:nowrap">
                            <?php if ((string) $row['status'] === 'issued' && $canPost): ?>
                                <?php // Receiving is its own page now, on the tab for the
                                      // kind of work this is: the customer one asks about
                                      // an order, the showroom one has none to ask about. ?>
                                <a class="button secondary" style="min-height:30px;padding:3px 10px"
                                   href="<?= e(url('admin/jewellery-receive.php?kind=' . ((string) ($row['assign_kind'] ?? 'customer') === 'self' ? 'self' : 'customer') . '&receive=' . (int) $row['id'])) ?>">Receive</a>
                                <form method="post" style="display:inline" data-confirm="Cancel this assignment? The issued metal returns to own stock.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="cancel_assignment">
                                    <input type="hidden" name="back_view" value="assignments">
                                    <input type="hidden" name="assignment_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button soft" style="min-height:30px;padding:3px 10px">Cancel</button>
                                </form>
                            <?php endif; ?>
                            <?php if ((string) $row['status'] === 'issued' && $canPost): ?>
                                <?php // Hand metal over against work already assigned — the
                                      // second half of the work order. Shown on every open
                                      // issue, because metal goes out in instalments as
                                      // often as it goes out all at once. ?>
                                <details style="display:inline-block;vertical-align:middle">
                                    <summary class="button soft" style="min-height:30px;padding:3px 10px;list-style:none;cursor:pointer">
                                        <?= (float) $row['issued_gross_weight'] > 0 ? 'Issue more' : 'Issue metal' ?>
                                    </summary>
                                    <?php // Gold, or the stones set into it. One issue carries
                                          // both, each weighed in its own unit — the item says
                                          // which it is, so nobody has to declare it twice. ?>
                                    <form method="post" style="display:flex;gap:6px;align-items:end;margin-top:6px;flex-wrap:wrap">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="issue_component">
                                        <input type="hidden" name="back_view" value="assignments">
                                        <input type="hidden" name="assignment_id" value="<?= (int) $row['id'] ?>">
                                        <label style="font-size:11px">What
                                            <select name="item_id" style="max-width:190px">
                                                <?php foreach ($items as $it): ?>
                                                    <option value="<?= (int) $it['id'] ?>" <?= (int) $it['id'] === (int) $row['item_id'] ? 'selected' : '' ?>>
                                                        <?= e((string) $it['code']) ?> — <?= e((string) $it['name']) ?><?= (string) ($it['metal_kind'] ?? '') === 'stone' ? ' (ct)' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select></label>
                                        <label style="font-size:11px">Weight
                                            <input type="number" name="gross_weight" step="0.0001" min="0.0001"
                                                   required style="max-width:110px" placeholder="<?= e((string) $row['unit_code']) ?>"></label>
                                        <label style="font-size:11px">Date
                                            <input type="date" name="issue_date" value="<?= e($todayInFy) ?>"
                                                   min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>" style="max-width:150px"></label>
                                        <button type="submit" class="button secondary" style="min-height:30px;padding:3px 10px">Hand over</button>
                                    </form>
                                    <?php
                                    // What is already in his hands on this issue, so a second
                                    // hand-over is made knowing the first.
                                    $handedOver = jewellery_assignment_components($companyId, (int) $row['id']);
                                    ?>
                                    <?php if ($handedOver !== []): ?>
                                        <?php $handedTotals = jewellery_component_totals($handedOver); ?>
                                        <div style="margin-top:6px;font-size:11px;color:var(--mbw-muted)">
                                            Handed over so far:
                                            <?php if ($handedTotals['metal_lines'] > 0): ?>
                                                <strong><?= $fmt($handedTotals['metal_fine'], 4) ?></strong> fine metal
                                            <?php endif; ?>
                                            <?php if ($handedTotals['stone_lines'] > 0): ?>
                                                <?= $handedTotals['metal_lines'] > 0 ? ' · ' : '' ?>
                                                <strong><?= $fmt($handedTotals['stone_carat'], 3) ?></strong> ct stones
                                            <?php endif; ?>
                                            <?= ' (' . count($handedOver) . ' hand-over' . (count($handedOver) === 1 ? '' : 's') . ')' ?>
                                        </div>
                                    <?php endif; ?>
                                </details>
                            <?php endif; ?>
                            <?php if ((string) $row['status'] === 'received' && $canPost): ?>
                                <?php $rcptId = (int) db()->query("SELECT id FROM jewellery_order_receipts
                                    WHERE company_id={$companyId} AND assignment_id=" . (int) $row['id'] . "
                                      AND status='posted' ORDER BY id DESC LIMIT 1")->fetchColumn(); ?>
                                <?php if ($rcptId > 0): ?>
                                <form method="post" style="display:inline" data-confirm="Reverse this receipt? The metal goes back to the kaligad so you can receive it at the corrected weight.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="unpost_receipt">
                                    <input type="hidden" name="back_view" value="assignments">
                                    <input type="hidden" name="receipt_id" value="<?= $rcptId ?>">
                                    <button type="submit" class="button soft" style="min-height:30px;padding:3px 10px">Reverse receipt</button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ((string) $row['status'] === 'cancelled' && $canEdit): ?>
                                <?php // A cancelled issue's row may leave the register; its
                                      // paired metal movements stay on the books as the
                                      // net-zero record that it happened. ?>
                                <form method="post" style="display:inline" data-confirm="Delete this cancelled issue from the register?">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_assignment">
                                    <input type="hidden" name="back_view" value="assignments">
                                    <input type="hidden" name="assignment_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button soft" style="min-height:30px;padding:3px 10px">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'delivery'): ?>
    <?php
    // Three different things end up in this queue and want handling
    // differently: somebody is waiting for a customer's piece, nobody is
    // waiting for a showroom one, and an assignment that came back before a
    // customer was attached is neither. They are told apart by the assignment's
    // kind and whether the order names anybody.
    $deliveryOrigins = jewellery_order_sources();
    $originTone = jewellery_order_source_tones();
    /** A column heading that sorts, and flips direction when it is the one already sorted on. */
    $sortHead = static function (string $key, string $label, bool $numeric = false) use ($deliverySort, $deliveryDir, $deliveryOrigin, $deliveryFilters): string {
        $active = $deliverySort === $key;
        $next = ($active && $deliveryDir === 'asc') ? 'desc' : 'asc';
        $arrow = $active ? ($deliveryDir === 'asc' ? ' ▲' : ' ▼') : '';
        $query = array_filter($deliveryFilters, static fn (string $value): bool => $value !== '') + [
            'view' => 'delivery', 'sort' => $key, 'dir' => $next,
        ];
        if ($deliveryOrigin !== '') { $query['origin'] = $deliveryOrigin; }
        $href = url('admin/jewellery-workshop.php?' . http_build_query($query));

        return '<th' . ($numeric ? ' class="is-numeric"' : '') . '>'
            . '<a href="' . e($href) . '" style="color:inherit;text-decoration:none;white-space:nowrap"'
            . ' title="Sort by ' . e($label) . ' (' . ($next === 'asc' ? 'ascending' : 'descending') . ')">'
            . e($label) . '<span style="opacity:' . ($active ? '1' : '.35') . '">' . ($arrow ?: ' ⇅') . '</span></a></th>';
    };
    $deliveryHasFilters = $deliveryOrigin !== '' || array_filter($deliveryFilters, static fn (string $value): bool => $value !== '') !== [];
    ?>
    <div class="notice" style="margin-bottom:14px">
        These orders have come back from the kaligad and are finished, but the customer has not collected them yet.
    </div>

    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head">
            <h2>Received but Not Delivered (<?= count($pending) ?><?= $deliveryOrigin !== '' ? ' of ' . (int) ($deliveryCounts['all'] ?? 0) : '' ?>)</h2>
            <?php // The file holds exactly the rows on screen — the filter and
                  // the sort travel with the link. ?>
            <span><?= $canExport && $pending !== [] ? $exportLinks() : '' ?></span>
        </div>
        <form method="get" class="mbw-filterbar" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="view" value="delivery">
            <input type="hidden" name="sort" value="<?= e($deliverySort) ?>">
            <input type="hidden" name="dir" value="<?= e($deliveryDir) ?>">
            <label style="display:flex;gap:6px;align-items:center;margin:0">
                <span style="color:var(--mbw-muted);font-size:12.5px">Ready to deliver from</span>
                <select name="origin" class="field-compact" aria-label="Where the piece came from" onchange="this.form.submit()">
                    <option value="">All (<?= (int) ($deliveryCounts['all'] ?? 0) ?>)</option>
                    <?php foreach ($deliveryOrigins as $originKey => $originLabel): ?>
                        <option value="<?= e($originKey) ?>" <?= $deliveryOrigin === $originKey ? 'selected' : '' ?>>
                            <?= e($originLabel) ?> (<?= (int) ($deliveryCounts[$originKey] ?? 0) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <input type="search" name="q" class="field-compact" value="<?= e($deliveryFilters['q']) ?>" placeholder="Order or customer" aria-label="Filter by order number or customer">
            <label style="display:flex;gap:4px;align-items:center;margin:0"><small>Received</small>
                <input type="date" name="received_from" class="field-compact" value="<?= e($deliveryFilters['received_from']) ?>" aria-label="Received on or after">
                <span>–</span><input type="date" name="received_to" class="field-compact" value="<?= e($deliveryFilters['received_to']) ?>" aria-label="Received on or before">
            </label>
            <label style="display:flex;gap:4px;align-items:center;margin:0"><small>Weight</small>
                <input type="number" name="weight_min" class="field-compact" step="0.0001" min="0" value="<?= e($deliveryFilters['weight_min']) ?>" placeholder="Min" aria-label="Minimum returned weight">
                <span>–</span><input type="number" name="weight_max" class="field-compact" step="0.0001" min="0" value="<?= e($deliveryFilters['weight_max']) ?>" placeholder="Max" aria-label="Maximum returned weight">
            </label>
            <label style="display:flex;gap:4px;align-items:center;margin:0"><small>Days waiting</small>
                <input type="number" name="waiting_min" class="field-compact" step="1" min="0" value="<?= e($deliveryFilters['waiting_min']) ?>" placeholder="Min" aria-label="Minimum days waiting">
                <span>–</span><input type="number" name="waiting_max" class="field-compact" step="1" min="0" value="<?= e($deliveryFilters['waiting_max']) ?>" placeholder="Max" aria-label="Maximum days waiting">
            </label>
            <label style="display:flex;gap:4px;align-items:center;margin:0"><small>Promised</small>
                <input type="date" name="promised_from" class="field-compact" value="<?= e($deliveryFilters['promised_from']) ?>" aria-label="Promised on or after">
                <span>–</span><input type="date" name="promised_to" class="field-compact" value="<?= e($deliveryFilters['promised_to']) ?>" aria-label="Promised on or before">
            </label>
            <button type="submit" class="button secondary"><?= icon('filter') ?> Filter</button>
            <?php if ($deliveryHasFilters): ?>
                <a class="button secondary" href="<?= e(url('admin/jewellery-workshop.php?view=delivery&sort=' . $deliverySort . '&dir=' . $deliveryDir)) ?>">Show all</a>
            <?php endif; ?>
            <span style="margin-left:auto;color:var(--mbw-muted);font-size:12.5px">
                <?php foreach ($deliveryOrigins as $originKey => $originLabel): ?>
                    <span class="mbw-pill <?= e($originTone[$originKey] ?? 'tone-gray') ?>" style="margin-left:6px"><?= e($originLabel) ?>: <?= (int) ($deliveryCounts[$originKey] ?? 0) ?></span>
                <?php endforeach; ?>
            </span>
        </form>
        <div class="mbw-tablewrap"><table>
            <thead><tr>
                <?= $sortHead('order', 'Order') ?>
                <?= $sortHead('customer', 'Customer') ?>
                <?= $sortHead('origin', 'Ordered as') ?>
                <?= $sortHead('received', 'Received on') ?>
                <?= $sortHead('weight', 'Weight back', true) ?>
                <?= $sortHead('waiting', 'Days waiting', true) ?>
                <?= $sortHead('promised', 'Promised') ?>
                <th></th>
            </tr></thead>
            <tbody>
                <?php if ($pending === []): ?>
                    <tr><td colspan="8"><?= $deliveryOrigin !== ''
                        ? 'Nothing of that kind is waiting for collection.'
                        : 'Nothing is waiting for collection.' ?></td></tr>
                <?php endif; ?>
                <?php foreach ($pending as $row): ?>
                    <?php
                    $rowOrigin = (string) ($row['origin'] ?? 'customer');
                    $rowCustomer = trim((string) ($row['party_name'] ?? '')) ?: trim((string) ($row['customer_name'] ?? ''));
                    ?>
                    <tr>
                        <td><?= e($row['order_no']) ?></td>
                        <td><?= $rowCustomer !== ''
                                ? e($rowCustomer)
                                : '<span style="color:var(--mbw-muted)">' . ($rowOrigin === 'replenishment' ? 'Shelf stock' : 'Nobody yet') . '</span>' ?><?= ($row['customer_phone'] ?? '') !== '' ? '<br><small>' . e((string) $row['customer_phone']) . '</small>' : '' ?></td>
                        <td><span class="mbw-pill <?= e($originTone[$rowOrigin] ?? 'tone-gray') ?>"><?= e((string) ($deliveryOrigins[$rowOrigin] ?? $rowOrigin)) ?></span></td>
                        <td><?= ($row['receive_date'] ?? null) ? e(app_date((string) $row['receive_date'])) : '—' ?></td>
                        <td class="is-numeric"><?= $fmt((float) ($row['received_gross_weight'] ?? 0), 4) ?> <small><?= e((string) $row['unit_code']) ?></small>
                            <?php if ((float) ($row['received_fine_weight'] ?? 0) > 0.00005): ?>
                                <br><small><?= $fmt((float) $row['received_fine_weight'], 4) ?> fine</small>
                            <?php endif; ?>
                        </td>
                        <td class="is-numeric"><?= (int) ($row['days_waiting'] ?? 0) > 7 ? '<span class="mbw-pill tone-red">' . (int) $row['days_waiting'] . '</span>' : (int) ($row['days_waiting'] ?? 0) ?></td>
                        <td><?= ($row['delivery_date'] ?? null) ? e(app_date((string) $row['delivery_date'])) : '—' ?></td>
                        <td>
                            <?php if ($canPost): ?>
                                <?php
                                    // Handing the goods over IS the sale. This used to be a
                                    // button that closed the order and billed nobody, so the
                                    // customer walked out with gold the books still had in
                                    // stock. It now opens the bill for this order, filled in
                                    // from it, and the delivery is recorded when that posts.
                                ?>
                                <a class="button secondary" style="min-height:30px;padding:3px 10px"
                                   href="<?= e(url('admin/jewellery-trade.php?view=sales&sell_order=' . (int) $row['id'])) ?>">
                                    <?= icon('invoice') ?> Bill &amp; deliver
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12px">
            <strong>Made to order</strong> — the piece went out to a kaligad for this customer and has come back; they are waiting for it.
            <strong>From showroom stock</strong> — the customer chose a piece already on the shelf, so it was set aside rather than made.
            <strong>Showroom replenishment</strong> — the shop's own work to restock the shelf; nobody is waiting for it.
            Any column heading sorts; click it again to reverse.
        </p>
    </section>


<?php elseif ($view === 'ready-to-sale'): ?>
    <?php // Sold is the stock ledger's business, but PROMISED is this board's:
          // a customer can order one of these pieces off the shelf, and the
          // counter has to know at a glance which ones are still free. ?>
    <?php $heldCount = count(array_filter($readyToSale, static fn (array $r): bool => (int) ($r['reserved_order_id'] ?? 0) > 0)); ?>
    <div class="notice" style="margin-bottom:14px">
        Pieces made for the showroom, back from the kaligad and on the shelf. Nobody ordered these — they replace
        minimum stock, so they wait for whoever walks in rather than for a name. A customer who does want one is
        given an order against that exact piece on the <a href="<?= e(url('admin/jewellery-workshop.php?view=orders')) ?>">order form</a>,
        and no kaligad is ever assigned to it.
    </div>
    <section class="mbw-card">
        <div class="mbw-card-head">
            <h2>Ready to Sale (<?= count($readyToSale) ?><?= $heldCount > 0 ? ' — ' . $heldCount . ' promised' : '' ?>)</h2>
            <div class="mbw-card-tools"><?= $canExport && $readyToSale !== [] ? 'Export' . $exportLinks() : '' ?></div>
        </div>
        <div class="mbw-tablewrap"><table>
            <thead><tr><th>Assignment</th><th>Ornament</th><th>Size / design</th><th>Kaligad</th>
                <th>Received on</th><th class="is-numeric">Gross</th><th class="is-numeric">Stone</th>
                <th class="is-numeric">Net</th><th class="is-numeric">Fine</th><th>Purity</th>
                <th class="is-numeric">Making charge</th><th class="is-numeric">Days on shelf</th>
                <th>Held for</th></tr></thead>
            <tbody>
                <?php if ($readyToSale === []): ?>
                    <tr><td colspan="13" style="text-align:center;color:var(--mbw-muted);padding:18px">
                        Nothing has come back for the showroom yet. Assign showroom work under
                        <a href="<?= e(url('admin/jewellery-assign.php?kind=self')) ?>">Kaligad Assign</a>.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($readyToSale as $row): ?>
                    <?php $heldOrder = (string) ($row['reserved_order_no'] ?? ''); ?>
                    <tr>
                        <td><strong><?= e((string) $row['assignment_no']) ?></strong><br><small><?= e((string) $row['receipt_no']) ?></small></td>
                        <td><?= e((string) ($row['expected_ornament'] ?: $row['item_name'] ?? '')) ?></td>
                        <td><?= e((string) ($row['size_design'] ?? '')) ?: '—' ?></td>
                        <td><?= e((string) ($row['karigar_name'] ?? '')) ?></td>
                        <td><?= e(app_date((string) $row['receive_date'])) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['received_gross_weight'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) ($row['stone_weight'] ?? 0), 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) ($row['net_gold_weight'] ?? 0), 4) ?></td>
                        <td class="is-numeric"><strong><?= $fmt((float) $row['received_fine_weight'], 4) ?></strong></td>
                        <td><?= e((string) ($row['purity_code'] ?? '')) ?></td>
                        <td class="is-numeric"><?= e($sym) ?><?= $fmt((float) ($row['making_amount'] ?? 0)) ?></td>
                        <td class="is-numeric"><?= (int) ($row['days_on_shelf'] ?? 0) ?></td>
                        <td>
                            <?php if ($heldOrder === ''): ?>
                                <span style="color:var(--mbw-muted)">On the shelf</span>
                            <?php else: ?>
                                <strong><?= e((string) ($row['reserved_for'] ?? '')) ?></strong><br>
                                <small><a href="<?= e(url('admin/jewellery-workshop.php?view=orders&edit=' . (int) $row['reserved_order_id'])) ?>"><?= e($heldOrder) ?></a>
                                    · <?= e((string) ($row['reserved_order_status'] ?? '')) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'output'): ?>
    <?php $outputKind = jw_enum($_GET['kind'] ?? null, ['customer', 'self'], ''); ?>
    <div class="notice" style="margin-bottom:14px">
        Everything the kaligads have finished, both kinds in one list, each with a remark saying where it went.
    </div>
    <section class="mbw-card">
        <div class="mbw-card-head">
            <h2>Workshop Output (<?= count($output) ?>)</h2>
            <div class="mbw-card-tools"><?= $canExport && $output !== [] ? 'Export' . $exportLinks() : '' ?></div>
        </div>
        <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
            <input type="hidden" name="view" value="output">
            <select name="kind" class="field-compact" aria-label="Kind">
                <option value="">Both kinds</option>
                <option value="customer" <?= $outputKind === 'customer' ? 'selected' : '' ?>>Customer ordered</option>
                <option value="self" <?= $outputKind === 'self' ? 'selected' : '' ?>>Self ordered</option>
            </select>
            <input type="date" name="from" value="<?= e((string) ($_GET['from'] ?? '')) ?>" class="field-compact" aria-label="Received from">
            <input type="date" name="to" value="<?= e((string) ($_GET['to'] ?? '')) ?>" class="field-compact" aria-label="Received to">
            <button type="submit" class="button secondary"><?= icon('filter') ?> Filter</button>
        </form>
        <div class="mbw-tablewrap"><table>
            <thead><tr><th style="width:44px">SN</th><th>Assignment</th><th>Kaligad</th><th>Ornament</th>
                <th>Received on</th><th class="is-numeric">Gross</th><th class="is-numeric">Net</th>
                <th class="is-numeric">Fine</th><th class="is-numeric">Wastage</th>
                <th class="is-numeric">Making charge</th><th>Remarks</th></tr></thead>
            <tbody>
                <?php if ($output === []): ?>
                    <tr><td colspan="11" style="text-align:center;color:var(--mbw-muted);padding:18px">Nothing has been received back yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($output as $index => $row): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><strong><?= e((string) $row['assignment_no']) ?></strong></td>
                        <td><?= e((string) ($row['karigar_name'] ?? '')) ?></td>
                        <td><?= e((string) ($row['expected_ornament'] ?: $row['item_name'] ?? '')) ?><?= ($row['size_design'] ?? '') !== '' ? '<br><small>' . e((string) $row['size_design']) . '</small>' : '' ?></td>
                        <td><?= e(app_date((string) $row['receive_date'])) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['received_gross_weight'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) ($row['net_gold_weight'] ?? 0), 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['received_fine_weight'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) ($row['wastage_fine_weight'] ?? 0), 4) ?></td>
                        <td class="is-numeric"><?= e($sym) ?><?= $fmt((float) ($row['making_amount'] ?? 0)) ?></td>
                        <td>
                            <span class="mbw-pill tone-<?= (string) $row['assign_kind'] === 'self' ? 'purple' : 'blue' ?>">
                                <?= (string) $row['assign_kind'] === 'self' ? 'Self' : 'Customer' ?>
                            </span>
                            <br><small><?= e((string) $row['remark']) ?></small>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'refinery'): ?>
    <?php if ($canPost): ?>
    <section class="mbw-card" data-collapsible data-draggable>
        <div class="mbw-card-head"><h2>Send Metal for Refining</h2></div>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="issue_refinery">
            <input type="hidden" name="back_view" value="refinery">
            <label>Refiner
                <select name="party_id">
                    <option value="0">— none —</option>
                    <?php foreach ($parties as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Item
                <select name="item_id" required>
                    <?php foreach ($items as $it): ?>
                        <option value="<?= (int) $it['id'] ?>"><?= e($it['code'] . ' — ' . $it['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Purity
                <select name="purity_id">
                    <?php foreach ($purities as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"><?= e($p['metal_code'] . '·' . $p['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Unit
                <select name="unit_id">
                    <?php foreach ($units as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) ($baseUnit['id'] ?? 0) ? 'selected' : '' ?>><?= e($u['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Gross weight<input type="number" name="issued_gross_weight" step="0.0001" min="0.0001" required></label>
            <label>Issue date<input type="date" name="issue_date" value="<?= e($todayInFy) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>" required></label>
            <div style="grid-column:1/-1"><button type="submit" class="button" <?= $items === [] ? 'disabled' : '' ?>>Send for Refining</button></div>
        </form>
    </section>
    <?php endif; ?>

    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Refinery Jobs (<?= count($jobs) ?>)</h2><span><?= $canExport ? $exportLinks() : '' ?></span></div>
        <div class="mbw-tablewrap"><table>
            <thead><tr><th>Job</th><th>Refiner</th><th>Issued</th><th class="is-numeric">Out (fine)</th><th class="is-numeric">Back (fine)</th><th class="is-numeric">Loss / extra (fine)</th><th class="is-numeric">Charges</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if ($jobs === []): ?><tr><td colspan="9">No refinery jobs yet.</td></tr><?php endif; ?>
                <?php foreach ($jobs as $row): ?>
                    <?php $isOut = (string) $row['status'] === 'issued'; ?>
                    <tr>
                        <td><?= e($row['job_no']) ?></td>
                        <td><?= e((string) ($row['party_name'] ?? '—')) ?></td>
                        <td><?= e(app_date((string) $row['issue_date'])) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['issued_fine_weight'], 4) ?></td>
                        <td class="is-numeric"><?= $isOut ? '—' : $fmt((float) $row['received_fine_weight'], 4) ?></td>
                        <?php
                            // The column runs both ways: amber for metal the
                            // furnace ate, green for metal the refiner put in.
                            // Only one of the two is ever non-zero on a job.
                            $jobSurplus = (float) ($row['surplus_fine_weight'] ?? 0);
                            $jobLossCell = $jobSurplus > 0
                                ? '<span class="mbw-pill tone-green">+' . $fmt($jobSurplus, 4) . '</span>'
                                : '<span class="mbw-pill tone-amber">' . $fmt((float) $row['loss_fine_weight'], 4) . '</span>';
                        ?>
                        <td class="is-numeric"><?= $isOut ? '—' : $jobLossCell ?></td>
                        <td class="is-numeric"><?= $isOut ? '—' : $fmt((float) $row['charges_amount']) ?></td>
                        <td><span class="mbw-pill <?= $isOut ? 'tone-amber' : 'tone-green' ?>"><?= e(ucfirst((string) $row['status'])) ?></span></td>
                        <td>
                            <?php if ($isOut && $canPost): ?>
                                <a class="button secondary" style="min-height:30px;padding:3px 10px" href="<?= e(url('admin/jewellery-workshop.php?view=refinery&receive=' . (int) $row['id'])) ?>">Receive</a>
                                <form method="post" style="display:inline" data-confirm="Cancel this refinery job? The metal returns to own stock.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="cancel_refinery_job">
                                    <input type="hidden" name="back_view" value="refinery">
                                    <input type="hidden" name="job_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button soft" style="min-height:30px;padding:3px 10px">Cancel</button>
                                </form>
                            <?php endif; ?>
                            <?php if ((string) $row['status'] === 'cancelled' && $canEdit): ?>
                                <form method="post" style="display:inline" data-confirm="Delete this cancelled job from the register?">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_refinery_job">
                                    <input type="hidden" name="back_view" value="refinery">
                                    <input type="hidden" name="job_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button soft" style="min-height:30px;padding:3px 10px">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <?php $receiveJob = jewellery_refinery_job($companyId, (int) ($_GET['receive'] ?? 0)); ?>
    <?php if ($receiveJob && (string) $receiveJob['status'] === 'issued' && $canPost): ?>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head">
            <h2>Receive Refined Metal — <?= e((string) $receiveJob['job_no']) ?></h2>
            <a class="mbw-view-all" href="<?= e(url('admin/jewellery-workshop.php?view=refinery')) ?>">Cancel</a>
        </div>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="receive_refinery">
            <input type="hidden" name="back_view" value="refinery">
            <input type="hidden" name="job_id" value="<?= (int) $receiveJob['id'] ?>">
            <label>Refined item
                <select name="received_item_id">
                    <?php foreach ($items as $it): ?>
                        <option value="<?= (int) $it['id'] ?>" <?= (int) $receiveJob['item_id'] === (int) $it['id'] ? 'selected' : '' ?>><?= e($it['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Refined purity
                <select name="received_purity_id">
                    <?php foreach ($purities as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) $receiveJob['purity_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['metal_code'] . '·' . $p['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Gross weight back<input type="number" name="received_gross_weight" step="0.0001" min="0.0001" required></label>
            <label>Receive date<input type="date" name="receive_date" value="<?= e($todayInFy) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>" required></label>
            <label>Refinery charges (<?= e($sym) ?>)<input type="number" name="charges_amount" step="0.01" min="0" value="0"></label>
            <label>Charges settled
                <select name="charges_settle_mode">
                    <option value="credit">On credit (opens a bill)</option>
                    <option value="cash">Cash</option>
                    <option value="bank">Bank</option>
                </select>
            </label>
            <label>Paid from ledger
                <select name="charges_ledger_id">
                    <option value="0">— not applicable —</option>
                    <?php foreach ($ledgers as $l): ?>
                        <option value="<?= (int) $l['id'] ?>"><?= e(($l['code'] ? $l['code'] . ' — ' : '') . $l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div style="grid-column:1/-1"><button type="submit" class="button">Receive &amp; Post</button></div>
        </form>
    </section>
    <?php endif; ?>
<?php endif; ?>

<script>
// The tender grid: one payment, several ways of paying at once. Everything is
// DELEGATED to the document because "+ Another way of paying" clones the last
// row — a listener bound to the original would not follow it to the clone.
(function () {
    // A long ledger list may be enhanced into a searchable dropdown inside the
    // horizontally scrolling tender grid. Lift that grid above its neighbours
    // while a select is active so the options are never clipped behind a row.
    function liftDropdown(control) {
        var scroll = control && control.closest(".jw-lines-scroll");
        if (scroll) { scroll.classList.add("jw-dropdown-open"); }
    }
    function lowerDropdown(control) {
        var scroll = control && control.closest(".jw-lines-scroll");
        if (!scroll) { return; }
        setTimeout(function () {
            var active = document.activeElement;
            if (!active || !scroll.contains(active)
                || !(active.matches("select") || active.classList.contains("ss-input"))) {
                scroll.classList.remove("jw-dropdown-open");
            }
        }, 0);
    }
    document.addEventListener("focusin", function (event) {
        var control = event.target.closest && event.target.closest("select, .ss-input");
        if (control) { liftDropdown(control); }
    });
    document.addEventListener("focusout", function (event) {
        var control = event.target.closest && event.target.closest("select, .ss-input");
        if (control) { lowerDropdown(control); }
    });

    // The purity list follows the chosen item, because the engine refuses a
    // purity from a different metal. No item means a money row: the purity is
    // not used, so the full list is left alone rather than emptied.
    function syncPurity(itemSelect) {
        var row = itemSelect.closest("tr");
        var purity = row && row.querySelector(".jw-tender-purity");
        if (!purity) { return; }
        if (!purity.jwOptions) {
            purity.jwOptions = Array.prototype.slice.call(purity.options);
        }
        var chosen = itemSelect.options[itemSelect.selectedIndex];
        var metal = chosen ? chosen.getAttribute("data-metal") : "0";
        purity.innerHTML = "";
        purity.jwOptions.forEach(function (option) {
            if (!metal || metal === "0" || option.getAttribute("data-metal") === metal) {
                purity.appendChild(option.cloneNode(true));
            }
        });
    }
    // An "Other…" way of paying carries its own name — the box appears only
    // when it is needed.
    function syncLabel(modeSelect) {
        var row = modeSelect.closest("tr");
        var label = row && row.querySelector(".jw-tender-label");
        if (label) { label.style.display = modeSelect.value === "other" ? "" : "none"; }
    }
    // The running total, so the counter watches the parts meet the figure the
    // customer was told before pressing anything.
    function syncTotal(anyField) {
        var box = anyField.closest(".jw-lines-box");
        var out = box && box.querySelector(".jw-tender-total");
        if (!out) { return; }
        var total = 0;
        Array.prototype.forEach.call(box.querySelectorAll(".jw-tender-amount"), function (amount) {
            total += parseFloat(amount.value) || 0;
        });
        out.textContent = total.toFixed(2);
    }
    document.addEventListener("change", function (event) {
        if (event.target.classList.contains("jw-tender-item")) { syncPurity(event.target); }
        if (event.target.classList.contains("jw-tender-mode")) { syncLabel(event.target); }
    });
    document.addEventListener("input", function (event) {
        if (event.target.classList.contains("jw-tender-amount")) { syncTotal(event.target); }
    });
    // Removing a row changes the total without firing input on any amount box.
    document.addEventListener("click", function (event) {
        var button = event.target.closest(".jw-line-remove, .jw-line-add");
        if (!button) { return; }
        var box = button.closest(".jw-lines-box");
        var amount = box && box.querySelector(".jw-tender-amount");
        if (amount) { setTimeout(function () { syncTotal(amount); }, 0); }
    });
    Array.prototype.forEach.call(document.querySelectorAll(".jw-tender-item"), syncPurity);
})();
</script>
<?php
// The grid buttons: add a row, remove a row.
jw_line_grid_scripts(['metals' => $metals, 'purities' => $purities,
    'units' => $units, 'base_unit' => $baseUnit]);
?>
<?php // Before the footer: it loads searchable-select.js, which counts a
      // dropdown's options to decide whether to take it over, so it must find
      // the real list rather than the stub. Guarded because the tender grid is
      // only drawn on the views that take money. ?>
<?php if (isset($tenderItemOptions)): ?>
<?= shared_options_template('jw-tender-items', $tenderItemOptions) ?>
<?php endif; ?>
<?= shared_options_script() ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php';
?>
