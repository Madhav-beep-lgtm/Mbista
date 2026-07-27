<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/jewellery_reports.php';
// The order form punches items on the SAME grid the sale does, so a quote and
// the bill it becomes can never carry different columns.
require_once __DIR__ . '/../../app/views/partials/jewellery_line_grid.php';

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

$allowedViews = ['orders', 'karigars', 'assignments', 'delivery', 'refinery'];
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
        try {
            jewellery_save_order($companyId, $fiscalYearId, [
                'id' => (int) ($_POST['order_id'] ?? 0),
                'order_date' => $clampDate((string) ($_POST['order_date'] ?? '')),
                'delivery_date' => (string) ($_POST['delivery_date'] ?? ''),
                'party_id' => (int) ($_POST['party_id'] ?? 0),
                'customer_name' => (string) ($_POST['customer_name'] ?? ''),
                'customer_phone' => (string) ($_POST['customer_phone'] ?? ''),
                'item_id' => (int) ($_POST['item_id'] ?? 0),
                'metal_id' => (int) ($_POST['metal_id'] ?? 0),
                'purity_id' => (int) ($_POST['purity_id'] ?? 0),
                'unit_id' => (int) ($_POST['unit_id'] ?? 0),
                'expected_gross_weight' => (float) ($_POST['expected_gross_weight'] ?? 0),
                'design_no' => (string) ($_POST['design_no'] ?? ''),
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
            flash('success', 'Order saved.');
        } catch (Throwable $e) {
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
            if ((int) ($order['party_id'] ?? 0) <= 0) {
                throw new RuntimeException('Give this order a customer first — an advance has to be held against somebody.');
            }
            $mode = jw_enum($_POST['advance_mode'] ?? null, ['cash', 'bank', 'metal'], 'cash');
            $id = jewellery_save_settlement($companyId, $fiscalYearId, [
                'settlement_date' => $clampDate((string) ($_POST['advance_date'] ?? '')),
                'party_id' => (int) $order['party_id'],
                'order_id' => $orderId,
                'is_advance' => 1,
                'direction' => $action === 'refund_advance' ? 'paid' : 'received',
                'mode' => $mode,
                'amount' => (float) ($_POST['advance_value'] ?? 0),
                'ledger_id' => (int) ($_POST['advance_ledger_id'] ?? 0),
                'item_id' => (int) ($_POST['advance_item_id'] ?? 0),
                'purity_id' => (int) ($_POST['advance_purity_id'] ?? 0),
                'unit_id' => (int) ($_POST['advance_unit_id'] ?? 0),
                'gross_weight' => (float) ($_POST['advance_gross_weight'] ?? 0),
                'notes' => ($action === 'refund_advance' ? 'Advance refunded on order ' : 'Advance on order ')
                    . (string) $order['order_no'],
            ], [], $userId);
            if ($canPost) {
                $posted = jewellery_post_settlement($companyId, $id, $userId);
                if (!$posted['ok']) {
                    throw new RuntimeException($posted['error']);
                }
                flash('success', $action === 'refund_advance'
                    ? 'Advance refunded and posted.'
                    : ($mode === 'metal' ? 'Old gold taken in as an advance — the weight is in stock and the value is held for the customer.'
                        : 'Advance received and posted.'));
            } else {
                flash('success', 'Advance saved as a draft — someone with posting rights must post it.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($back . '&edit=' . $orderId);
    }

    if ($action === 'issue_karigar') {
        require_permission('jewellery', 'post');
        $result = jewellery_issue_to_karigar($companyId, $fiscalYearId, [
            'karigar_id' => (int) ($_POST['karigar_id'] ?? 0),
            'order_id' => (int) ($_POST['order_id'] ?? 0),
            'item_id' => (int) ($_POST['item_id'] ?? 0),
            'purity_id' => (int) ($_POST['purity_id'] ?? 0),
            'unit_id' => (int) ($_POST['unit_id'] ?? 0),
            'issued_gross_weight' => (float) ($_POST['issued_gross_weight'] ?? 0),
            'issue_date' => $clampDate((string) ($_POST['issue_date'] ?? '')),
            'expected_return_date' => (string) ($_POST['expected_return_date'] ?? ''),
            'wastage_allowed_pct' => (float) ($_POST['wastage_allowed_pct'] ?? 0),
            'making_basis' => (string) ($_POST['making_basis'] ?? 'per_unit_weight'),
            'making_rate' => (float) ($_POST['making_rate'] ?? 0),
            'notes' => (string) ($_POST['notes'] ?? ''),
        ], $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Metal issued to the karigar.' : $result['error']);
        redirect($back);
    }

    if ($action === 'receive_karigar') {
        require_permission('jewellery', 'post');
        $result = jewellery_receive_from_karigar($companyId, $fiscalYearId, [
            'assignment_id' => (int) ($_POST['assignment_id'] ?? 0),
            'received_item_id' => (int) ($_POST['received_item_id'] ?? 0),
            'received_purity_id' => (int) ($_POST['received_purity_id'] ?? 0),
            'received_gross_weight' => (float) ($_POST['received_gross_weight'] ?? 0),
            'qty_pieces' => (float) ($_POST['qty_pieces'] ?? 0),
            'receive_date' => $clampDate((string) ($_POST['receive_date'] ?? '')),
            'notes' => (string) ($_POST['notes'] ?? ''),
        ], $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ('Received. Wages ' . $sym . number_format((float) $result['making_amount'], 2)
               . ', wastage recovery ' . $sym . number_format((float) $result['recovery_amount'], 2)
               . ', net payable ' . $sym . number_format((float) $result['net_payable'], 2) . '.')
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

    if ($action === 'deliver_order') {
        require_permission('jewellery', 'post');
        $result = jewellery_deliver_order($companyId, (int) ($_POST['order_id'] ?? 0), (int) ($_POST['sale_id'] ?? 0), $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Order marked delivered.' : $result['error']);
        redirect($back);
    }

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
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ('Refined metal received. Loss ' . number_format((float) $result['loss_fine'], 4)
               . ' fine (' . $sym . number_format((float) $result['loss_amount'], 2) . ').')
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
$karigars = jewellery_karigars_list($companyId);
$activeKarigars = array_values(array_filter($karigars, static fn (array $k): bool => (string) $k['status'] === 'active'));

$partyStmt = db()->prepare("SELECT id, code, name FROM accounting_parties WHERE company_id = :cid AND status = 'active' ORDER BY name ASC");
$partyStmt->execute(['cid' => $companyId]);
$parties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);
$ledgerStmt = db()->prepare('SELECT id, code, name FROM ledgers WHERE company_id = :cid ORDER BY code ASC');
$ledgerStmt->execute(['cid' => $companyId]);
$ledgers = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

$employees = [];
if (table_exists('payroll_employees')) {
    $empStmt = db()->prepare('SELECT id, employee_code FROM payroll_employees WHERE company_id = :cid ORDER BY employee_code ASC');
    $empStmt->execute(['cid' => $companyId]);
    $employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);
}

$orders = $view === 'orders' ? jewellery_orders_list($companyId) : [];
$editKarigar = $view === 'karigars' ? jewellery_karigar($companyId, (int) ($_GET['edit'] ?? 0)) : null;
$editOrder = $view === 'orders' ? jewellery_order($companyId, (int) ($_GET['edit'] ?? 0)) : null;
$orderAdvances = $editOrder ? jewellery_order_advances($companyId, (int) $editOrder['id'])
    : ['rows' => [], 'cash_total' => 0.0, 'metal_total' => 0.0, 'total' => 0.0];
$advanceAvailable = $editOrder ? jewellery_order_advance_available($companyId, (int) $editOrder['id']) : 0.0;
$orderLines = $editOrder ? jewellery_order_line_rows($companyId, (int) $editOrder['id']) : [];
// What is on the shelf, shown on the item options the same way the sale form
// shows it — an order for a piece the shop already has is filled off the tray.
$orderOnHand = [];
if ($view === 'orders') {
    foreach ($items as $itemRow) {
        $orderOnHand[(int) $itemRow['id']] = jw_item_balance($companyId, (int) $itemRow['id'], date('Y-m-d'), 'stock');
    }
}
$cashBankLedgers = [];
if ($view === 'orders' && table_exists('ledgers')) {
    $cashStmt = db()->prepare('SELECT l.id, l.code, l.name FROM ledgers l
        LEFT JOIN ledger_groups g ON g.id = l.group_id
        WHERE l.company_id = :cid AND l.status = \'active\' AND l.type = \'asset\'
        ORDER BY g.is_cash_or_bank DESC, l.code ASC, l.name ASC');
    $cashStmt->execute(['cid' => $companyId]);
    $cashBankLedgers = $cashStmt->fetchAll(PDO::FETCH_ASSOC);
}
$assignments = $view === 'assignments' ? jewellery_assignments_list($companyId) : [];
$receiveTarget = $view === 'assignments' ? jewellery_assignment($companyId, (int) ($_GET['receive'] ?? 0)) : null;
$receivePreview = null;
if ($receiveTarget && (string) $receiveTarget['status'] === 'issued') {
    $receivePreview = jewellery_preview_receipt($companyId, (int) $receiveTarget['id'], (float) ($_GET['wt'] ?? $receiveTarget['issued_gross_weight']));
}
$pending = $view === 'delivery' ? jewellery_pending_delivery($companyId) : [];
$jobs = $view === 'refinery' ? jewellery_refinery_jobs_list($companyId) : [];

$pageTitle = 'Jewellery — Orders, Kaligad & Refinery';
$pageSubtitle = 'Daily order management, metal issued to kaligads, wage and wastage settlement, and refinery jobs.';
$pageHero = ['icon' => 'coins'];
$bodyClass = 'admin-layout accounting-module-page';
$pageBreadcrumb = [['Home', 'admin/index.php'], ['Jewellery', 'admin/jewellery.php'], ['Workshop', 'admin/jewellery-workshop.php']];
include __DIR__ . '/../../app/views/partials/admin_header.php';

$fmt = static fn (?float $n, int $p = 2): string => $n === null ? 'N/A' : number_format($n, $p);
$statusTone = ['draft' => 'tone-gray', 'confirmed' => 'tone-blue', 'assigned' => 'tone-amber',
    'received' => 'tone-teal', 'delivered' => 'tone-green', 'cancelled' => 'tone-red'];
jw_line_grid_styles();
?>

<nav class="mbw-tabbar" aria-label="Jewellery workshop sections" style="flex-wrap:wrap">
    <a class="mbw-tab" href="<?= e(url('admin/jewellery.php')) ?>"><?= icon('dashboard') ?> Jewellery Home</a>
    <?php foreach ([
        'orders' => ['Orders', 'journal'], 'assignments' => ['Kaligad Issue &amp; Receive', 'handshake'],
        'delivery' => ['Ready to Deliver', 'box'], 'karigars' => ['Kaligads', 'teams'],
        'refinery' => ['Refinery', 'layers'],
    ] as $tabView => [$tabLabel, $tabIcon]): ?>
        <a class="mbw-tab <?= $view === $tabView ? 'is-active' : '' ?>" href="<?= e(url('admin/jewellery-workshop.php?view=' . $tabView)) ?>"><?= icon($tabIcon) ?> <?= $tabLabel ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($view === 'orders'): ?>
    <?php if ($canEdit): ?>
    <section class="mbw-card" data-draggable>
        <div class="mbw-card-head">
            <h2><?= $editOrder ? 'Edit Order — ' . e((string) $editOrder['order_no']) : 'New Order' ?></h2>
            <?php if ($editOrder): ?><a class="mbw-view-all" href="<?= e(url('admin/jewellery-workshop.php?view=orders')) ?>">New order</a><?php endif; ?>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_order">
            <input type="hidden" name="back_view" value="orders">
            <input type="hidden" name="order_id" value="<?= (int) ($editOrder['id'] ?? 0) ?>">
            <div class="workspace-form-grid">
                <label>Order date<input type="date" name="order_date" value="<?= e((string) ($editOrder['order_date'] ?? $todayInFy)) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>" required>
                    <span class="frm-optional">The metal rate is taken from this day and honoured on delivery.</span>
                </label>
                <label>Promised delivery<input type="date" name="delivery_date" value="<?= e((string) ($editOrder['delivery_date'] ?? '')) ?>"></label>
                <label>Existing customer
                    <select name="party_id">
                        <option value="0">— new customer, type the name →</option>
                        <?php foreach ($parties as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" <?= (int) ($editOrder['party_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Customer name<input type="text" name="customer_name" maxlength="190" value="<?= e((string) ($editOrder['customer_name'] ?? '')) ?>" placeholder="Creates the customer and their ledger"></label>
                <label>Phone<input type="text" name="customer_phone" maxlength="60" value="<?= e((string) ($editOrder['customer_phone'] ?? '')) ?>"></label>
                <label>Address<input type="text" name="customer_address" maxlength="255"></label>
                <label>Design no.<input type="text" name="design_no" maxlength="60" value="<?= e((string) ($editOrder['design_no'] ?? '')) ?>"></label>
                <label>Other charges (<?= e($sym) ?>)<input type="number" name="other_charges" step="0.01" min="0" value="<?= e((string) ($editOrder['other_charges'] ?? '0')) ?>"></label>
                <label>Discount (<?= e($sym) ?>)<input type="number" name="discount" step="0.01" min="0" value="<?= e((string) ($editOrder['discount'] ?? '0')) ?>"></label>
                <label>Skills Promotion Tax (<?= e($sym) ?>)<input type="number" name="manual_tax_amount" step="0.01" min="0" placeholder="auto" value="<?= e((string) ($editOrder['manual_tax_amount'] ?? '')) ?>">
                    <span class="frm-optional">Left blank it is worked out at the rate on the tax register.</span>
                </label>
                <label>Advance taken (<?= e($sym) ?>)<input type="number" name="advance_amount" step="0.01" min="0" value="<?= e((string) ($editOrder['advance_amount'] ?? '0')) ?>"></label>
                <label>Status
                    <select name="status">
                        <?php foreach (['draft' => 'Draft', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled'] as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= (string) ($editOrder['status'] ?? 'confirmed') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="grid-column:1/-1">Description<input type="text" name="description" maxlength="255" value="<?= e((string) ($editOrder['description'] ?? '')) ?>"></label>
            </div>

            <?php
                // The SAME grid the sale uses. One customer can order a ring and
                // a chain and a pair of bangles on one order; each is a line,
                // and each is priced by the engine that will bill it.
                jw_render_line_grid('l', $orderLines, max(3, count($orderLines) + 2), 'Items ordered', [
                    'items' => $items, 'purities' => $purities, 'units' => $units,
                    'base_unit' => $baseUnit, 'fmt' => $fmt, 'on_hand' => $orderOnHand,
                ]);
            ?>

            <details<?= $editOrder && (int) ($editOrder['item_id'] ?? 0) === 0 ? ' open' : '' ?>>
                <summary>Bespoke order — nothing chosen off the tray yet</summary>
                <div class="workspace-form-grid">
                    <label>Metal
                        <select name="metal_id">
                            <?php foreach ($metals as $m): ?>
                                <option value="<?= (int) $m['id'] ?>" <?= (int) ($editOrder['metal_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Purity
                        <select name="purity_id">
                            <?php foreach ($purities as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" <?= (int) ($editOrder['purity_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['metal_code'] . '·' . $p['code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Unit
                        <select name="unit_id">
                            <?php foreach ($units as $u): ?>
                                <option value="<?= (int) $u['id'] ?>" <?= (int) ($editOrder['unit_id'] ?? (int) ($baseUnit['id'] ?? 0)) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Expected gross weight<input type="number" name="expected_gross_weight" step="0.0001" min="0" value="<?= e((string) ($editOrder['expected_gross_weight'] ?? '0')) ?>"></label>
                </div>
                <p class="frm-optional" style="margin:6px 0 0">Use this when the customer has described the piece but no item has been
                    picked yet — "a ten-tola 22K chain". The order is recorded and the kaligad can be issued metal against it, but it
                    quotes nothing until items are put on the grid above. Nothing is invented: an unquoted order shows no total
                    rather than a wrong one.</p>
            </details>

            <?php if ($editOrder && (float) $editOrder['total_amount'] > 0): ?>
                <?php
                    $orderTotal = (float) $editOrder['total_amount'];
                    $orderAdvanceHeld = (float) $orderAdvances['total'];
                    $stillPayable = round($orderTotal - $orderAdvanceHeld, 2);
                ?>
                <div style="margin-top:12px;border:1px solid var(--mbw-border,#d9e2ec);border-radius:10px;padding:12px">
                    <h3 style="margin:0 0 8px;font-size:1rem">What the customer pays</h3>
                    <div style="overflow-x:auto"><table style="max-width:520px">
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
                    <p class="frm-optional" style="margin:8px 0 0">Worked out by the same engine that will raise the bill — the Skills
                        Promotion Tax on metal and making, VAT on the stone and diamond side, on their own separate bases. The metal
                        rate of <?= e(app_date((string) $editOrder['order_date'])) ?> is honoured on delivery; the tax rates are
                        restated at the sale date, because a statutory rate follows the day of supply.</p>
                </div>
            <?php endif; ?>

            <div style="margin-top:12px"><button type="submit" class="button"><?= $editOrder ? 'Update Order' : 'Create Order' ?></button></div>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($editOrder && $canEdit): ?>
    <section class="mbw-card" style="margin-top:14px">
        <div class="mbw-card-head">
            <h2>Advance on <?= e((string) $editOrder['order_no']) ?></h2>
            <span class="frm-optional">Cash, bank or old gold taken before the work is done.</span>
        </div>
        <p class="frm-optional" style="margin:0 0 12px">Held as a liability in the customer's own advance account until delivery. Old gold goes into stock at the value entered.</p>

        <div class="mbw-stat-row" style="margin-bottom:14px">
            <div class="mbw-stat"><span>Cash / bank held</span><strong><?= e($sym) ?> <?= $fmt((float) $orderAdvances['cash_total']) ?></strong></div>
            <div class="mbw-stat"><span>Old gold held</span><strong><?= e($sym) ?> <?= $fmt((float) $orderAdvances['metal_total']) ?></strong></div>
            <div class="mbw-stat"><span>Total advance</span><strong><?= e($sym) ?> <?= $fmt((float) $orderAdvances['total']) ?></strong></div>
            <div class="mbw-stat"><span>Still unapplied</span><strong><?= e($sym) ?> <?= $fmt((float) $advanceAvailable) ?></strong></div>
        </div>

        <?php if ($orderAdvances['rows'] !== []): ?>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Date</th><th>Ref</th><th>What</th><th class="is-numeric">Weight</th><th class="is-numeric">Value</th></tr></thead>
            <tbody>
                <?php foreach ($orderAdvances['rows'] as $adv): ?>
                    <tr>
                        <td><?= e(app_date((string) $adv['settlement_date'])) ?></td>
                        <td><?= e((string) $adv['settlement_no']) ?></td>
                        <td>
                            <?= (string) $adv['direction'] === 'paid' ? 'Refunded' : 'Received' ?> —
                            <?= e((string) $adv['mode']) ?>
                            <?php if ((string) $adv['mode'] === 'metal'): ?>
                                <small><?= e((string) ($adv['item_code'] ?? '')) ?> <?= e((string) ($adv['purity_code'] ?? '')) ?></small>
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

        <h3 style="margin:16px 0 8px">Take an advance</h3>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_advance">
            <input type="hidden" name="back_view" value="orders">
            <input type="hidden" name="order_id" value="<?= (int) $editOrder['id'] ?>">
            <label>Date<input type="date" name="advance_date" value="<?= e($todayInFy) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>"></label>
            <label>Taken as
                <select name="advance_mode">
                    <option value="cash">Cash</option>
                    <option value="bank">Bank</option>
                    <option value="metal">Old gold</option>
                </select>
            </label>
            <label>Into / from ledger
                <select name="advance_ledger_id">
                    <option value="0">— cash and bank only —</option>
                    <?php foreach ($cashBankLedgers as $l): ?>
                        <option value="<?= (int) $l['id'] ?>"><?= e(($l['code'] ? $l['code'] . ' — ' : '') . $l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Value (<?= e($sym) ?>)<input type="number" name="advance_value" step="0.01" min="0" value="0" required></label>
            <label>Old gold item
                <select name="advance_item_id">
                    <option value="0">— cash advance —</option>
                    <?php foreach ($items as $it): ?>
                        <option value="<?= (int) $it['id'] ?>"><?= e($it['code'] . ' — ' . $it['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Purity
                <select name="advance_purity_id">
                    <?php foreach ($purities as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) ($editOrder['purity_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['metal_code'] . '·' . $p['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Unit
                <select name="advance_unit_id">
                    <?php foreach ($units as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) ($editOrder['unit_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Gross weight<input type="number" name="advance_gross_weight" step="0.0001" min="0" value="0"></label>
            <div style="grid-column:1/-1">
                <button type="submit" class="button">Record Advance</button>
            </div>
        </form>

        <?php if ((float) $advanceAvailable > 0.005): ?>
        <h3 style="margin:16px 0 8px">Refund what is left</h3>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="refund_advance">
            <input type="hidden" name="back_view" value="orders">
            <input type="hidden" name="order_id" value="<?= (int) $editOrder['id'] ?>">
            <label>Date<input type="date" name="advance_date" value="<?= e($todayInFy) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>"></label>
            <label>Refunded as
                <select name="advance_mode">
                    <option value="cash">Cash</option>
                    <option value="bank">Bank</option>
                    <option value="metal">Gold back</option>
                </select>
            </label>
            <label>From ledger
                <select name="advance_ledger_id">
                    <option value="0">— cash and bank only —</option>
                    <?php foreach ($cashBankLedgers as $l): ?>
                        <option value="<?= (int) $l['id'] ?>"><?= e(($l['code'] ? $l['code'] . ' — ' : '') . $l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Value (<?= e($sym) ?>)<input type="number" name="advance_value" step="0.01" min="0" max="<?= e((string) $advanceAvailable) ?>" value="<?= e((string) $advanceAvailable) ?>" required></label>
            <label>Gold item
                <select name="advance_item_id">
                    <option value="0">— cash refund —</option>
                    <?php foreach ($items as $it): ?>
                        <option value="<?= (int) $it['id'] ?>"><?= e($it['code'] . ' — ' . $it['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Purity
                <select name="advance_purity_id">
                    <?php foreach ($purities as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) ($editOrder['purity_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['metal_code'] . '·' . $p['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Unit
                <select name="advance_unit_id">
                    <?php foreach ($units as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) ($editOrder['unit_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Gross weight<input type="number" name="advance_gross_weight" step="0.0001" min="0" value="0"></label>
            <div style="grid-column:1/-1">
                <button type="submit" class="button secondary">Refund Advance</button>
            </div>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="mbw-card" style="margin-top:14px">
        <div class="mbw-card-head"><h2>Orders (<?= count($orders) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>No.</th><th>Date</th><th>Customer</th><th>Metal</th><th class="is-numeric">Expected wt</th><th>Delivery</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if ($orders === []): ?><tr><td colspan="8">No orders yet.</td></tr><?php endif; ?>
                <?php foreach ($orders as $row): ?>
                    <tr>
                        <td><?= e($row['order_no']) ?><?= ($row['design_no'] ?? '') !== '' ? '<br><small>' . e((string) $row['design_no']) . '</small>' : '' ?></td>
                        <td><?= e(app_date((string) $row['order_date'])) ?></td>
                        <td><?= e((string) ($row['party_name'] ?? $row['customer_name'] ?? 'Walk-in')) ?></td>
                        <td><?= e($row['metal_name'] . ' · ' . $row['purity_code']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['expected_gross_weight'], 4) ?> <small><?= e($row['unit_code']) ?></small></td>
                        <td><?= ($row['delivery_date'] ?? null) ? e(app_date((string) $row['delivery_date'])) : '—' ?></td>
                        <td><span class="mbw-pill <?= e($statusTone[$row['status']] ?? 'tone-gray') ?>"><?= e(ucfirst((string) $row['status'])) ?></span></td>
                        <td style="white-space:nowrap">
                            <?php if ($canEdit): ?>
                                <a class="button soft" style="min-height:30px;padding:3px 10px" href="<?= e(url('admin/jewellery-workshop.php?view=orders&edit=' . (int) $row['id'])) ?>">Edit</a>
                                <a class="button soft" style="min-height:30px;padding:3px 10px" target="_blank" rel="noopener" href="<?= e(url('admin/jewellery-print.php?doc=order&id=' . (int) $row['id'])) ?>">Preview</a>
                            <?php endif; ?>
                            <?php if (in_array((string) $row['status'], ['draft', 'confirmed'], true) && $canPost): ?>
                                <a class="button secondary" style="min-height:30px;padding:3px 10px" href="<?= e(url('admin/jewellery-workshop.php?view=assignments&order=' . (int) $row['id'])) ?>">Assign</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'karigars'): ?>
    <?php if ($canEdit): ?>
    <section class="mbw-card" data-draggable>
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
            <label>Allowed wastage (%)<input type="number" name="wastage_allowed_pct" step="0.001" min="0" max="99.999" value="<?= e((string) ($editKarigar['wastage_allowed_pct'] ?? '0')) ?>"></label>
            <label class="frm-check"><input type="checkbox" name="active" <?= $editKarigar === null || (string) $editKarigar['status'] === 'active' ? 'checked' : '' ?>> Active</label>
            <label style="grid-column:1/-1">Address<input type="text" name="address" maxlength="255" value="<?= e((string) ($editKarigar['address'] ?? '')) ?>"></label>
            <div style="grid-column:1/-1"><button type="submit" class="button"><?= $editKarigar ? 'Update Kaligad' : 'Add Kaligad' ?></button></div>
        </form>
        <p class="frm-optional" style="margin:10px 0 0">
            A <strong>contractor</strong> automatically gets a party ledger, so wages accrue as a bill-wise trade payable you settle like any supplier.
            An <strong>employee</strong> is linked to payroll instead and no party ledger is opened.
        </p>
    </section>
    <?php endif; ?>

    <section class="mbw-card" style="margin-top:14px">
        <div class="mbw-card-head"><h2>Kaligads (<?= count($karigars) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Code</th><th>Name</th><th>Engagement</th><th>Making</th><th class="is-numeric">Allowed wastage</th><th class="is-numeric">Metal held (fine)</th><th class="is-numeric">Wages payable</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if ($karigars === []): ?><tr><td colspan="9">No kaligads yet.</td></tr><?php endif; ?>
                <?php foreach ($karigars as $row): ?>
                    <?php $pos = jewellery_karigar_position($companyId, (int) $row['id']); ?>
                    <tr>
                        <td><?= e($row['code']) ?></td>
                        <td><?= e($row['name']) ?><?= ($row['phone'] ?? '') !== '' ? '<br><small>' . e((string) $row['phone']) . '</small>' : '' ?></td>
                        <td><span class="mbw-pill <?= (string) $row['engagement_type'] === 'contractor' ? 'tone-blue' : 'tone-teal' ?>"><?= e(ucfirst((string) $row['engagement_type'])) ?></span></td>
                        <td><?= $fmt((float) $row['default_making_rate'], 2) ?> <small><?= e(str_replace('_', ' ', (string) $row['default_making_basis'])) ?></small></td>
                        <td class="is-numeric"><?= $fmt((float) $row['wastage_allowed_pct'], 3) ?>%</td>
                        <td class="is-numeric"><?= $pos['fine_weight'] > 0 ? '<span class="mbw-pill tone-amber">' . $fmt($pos['fine_weight'], 4) . '</span>' : '—' ?></td>
                        <td class="is-numeric"><?= $pos['wages_payable'] > 0 ? $fmt($pos['wages_payable']) : '—' ?></td>
                        <td><span class="mbw-pill <?= (string) $row['status'] === 'active' ? 'tone-green' : 'tone-gray' ?>"><?= e(ucfirst((string) $row['status'])) ?></span></td>
                        <td>
                            <?php if ($canEdit): ?><a class="mbw-view-all" href="<?= e(url('admin/jewellery-workshop.php?view=karigars&edit=' . (int) $row['id'])) ?>">Edit</a><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'assignments'): ?>
    <?php if ($canPost): ?>
    <section class="mbw-card" data-draggable>
        <div class="mbw-card-head"><h2>Issue Metal to a Kaligad</h2></div>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="issue_karigar">
            <input type="hidden" name="back_view" value="assignments">
            <label>Kaligad
                <select name="karigar_id" required>
                    <?php foreach ($activeKarigars as $k): ?>
                        <option value="<?= (int) $k['id'] ?>"><?= e($k['code'] . ' — ' . $k['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Against order
                <select name="order_id">
                    <option value="0">— stock work, no order —</option>
                    <?php foreach (jewellery_orders_list($companyId, ['status' => 'confirmed']) as $o): ?>
                        <option value="<?= (int) $o['id'] ?>" <?= (int) ($_GET['order'] ?? 0) === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['order_no']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php
            // Metal can only be issued FROM own stock, so the dropdown says what
            // is actually on hand. Without this the only feedback was a rejection
            // after the fact, with no clue where the metal was meant to come from.
            $itemOnHand = [];
            $issuableFine = 0.0;
            foreach ($items as $it) {
                $itemOnHand[(int) $it['id']] = jw_item_balance($companyId, (int) $it['id'], null, 'stock');
                $issuableFine += $itemOnHand[(int) $it['id']]['fine_weight'];
            }
            ?>
            <label>Item <span class="frm-optional">from own stock — this is what can be issued</span>
                <select name="item_id" required>
                    <?php foreach ($items as $it): ?>
                        <?php $onHand = $itemOnHand[(int) $it['id']]; ?>
                        <option value="<?= (int) $it['id'] ?>" <?= $onHand['fine_weight'] <= 0 ? 'disabled' : '' ?>><?= e($it['code'] . ' — ' . $it['name']) ?> (<?= $onHand['fine_weight'] > 0 ? $fmt($onHand['fine_weight'], 4) . ' fine on hand' : 'no stock' ?>)</option>
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
            <label>Gross weight issued<input type="number" name="issued_gross_weight" step="0.0001" min="0.0001" required></label>
            <label>Issue date<input type="date" name="issue_date" value="<?= e($todayInFy) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>" required></label>
            <label>Expected return<input type="date" name="expected_return_date"></label>
            <label>Allowed wastage (%)<input type="number" name="wastage_allowed_pct" step="0.001" min="0" max="99.999" value="0"></label>
            <label>Making basis
                <select name="making_basis">
                    <?php foreach (['per_unit_weight' => 'Per unit of weight', 'percent_of_metal' => '% of metal value', 'flat' => 'Flat'] as $k => $v): ?>
                        <option value="<?= e($k) ?>"><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Making rate<input type="number" name="making_rate" step="0.0001" min="0" value="0"></label>
            <div style="grid-column:1/-1"><button type="submit" class="button" <?= $activeKarigars === [] || $items === [] || $issuableFine <= 0 ? 'disabled' : '' ?>>Issue Metal</button></div>
        </form>
        <?php if ($issuableFine <= 0): ?>
            <div class="notice" style="margin-top:12px">
                <strong>There is no metal in stock to issue.</strong>
                Metal has to arrive before it can go out to a kaligad — record it as
                <a href="<?= e(url('admin/jewellery.php?view=opening')) ?>">Opening Stock</a> if you already held it when the books started,
                or as a <a href="<?= e(url('admin/jewellery-trade.php?view=purchases')) ?>">Purchase</a> if you bought it.
                Issuing does not create metal; it only moves it from your own stock into the kaligad's hands.
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($receiveTarget && (string) $receiveTarget['status'] === 'issued' && $canPost): ?>
    <section class="mbw-card" style="margin-top:14px">
        <div class="mbw-card-head">
            <h2>Receive Back — <?= e((string) $receiveTarget['issue_no']) ?> (<?= e((string) $receiveTarget['karigar_name']) ?>)</h2>
            <a class="mbw-view-all" href="<?= e(url('admin/jewellery-workshop.php?view=assignments')) ?>">Cancel</a>
        </div>
        <form method="get" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:12px">
            <input type="hidden" name="view" value="assignments">
            <input type="hidden" name="receive" value="<?= (int) $receiveTarget['id'] ?>">
            <label>Weight received back<input type="number" name="wt" step="0.0001" min="0" value="<?= e((string) ($_GET['wt'] ?? $receiveTarget['issued_gross_weight'])) ?>"></label>
            <button type="submit" class="button secondary" style="min-height:34px">Recalculate</button>
        </form>
        <?php if ($receivePreview && $receivePreview['ok']): ?>
            <div style="overflow-x:auto"><table>
                <tbody>
                    <tr><td>Issued (fine)</td><td class="is-numeric"><?= $fmt($receivePreview['issued_fine'], 4) ?></td></tr>
                    <tr><td>Received (fine)</td><td class="is-numeric"><?= $fmt($receivePreview['received_fine'], 4) ?></td></tr>
                    <tr><td>Wastage (fine)</td><td class="is-numeric"><?= $fmt($receivePreview['wastage_fine'], 4) ?></td></tr>
                    <tr><td>Allowed at <?= $fmt((float) $receiveTarget['wastage_allowed_pct'], 3) ?>%</td><td class="is-numeric"><?= $fmt($receivePreview['allowed_fine'], 4) ?></td></tr>
                    <tr><td><strong>Excess wastage (fine)</strong></td><td class="is-numeric"><strong><?= $fmt($receivePreview['excess_fine'], 4) ?></strong></td></tr>
                    <tr><td>Making charge</td><td class="is-numeric"><?= e($sym) ?><?= $fmt($receivePreview['making_amount']) ?></td></tr>
                    <tr><td>Recovered from wages</td><td class="is-numeric">− <?= e($sym) ?><?= $fmt($receivePreview['recovery_amount']) ?></td></tr>
                    <tr><td><strong>Net payable to the kaligad</strong></td><td class="is-numeric"><strong><?= e($sym) ?><?= $fmt($receivePreview['net_payable']) ?></strong><?= $receivePreview['net_payable'] < 0 ? ' <span class="mbw-pill tone-red">Kaligad owes the shop</span>' : '' ?></td></tr>
                </tbody>
            </table></div>
            <form method="post" class="workspace-form-grid" style="margin-top:12px">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="receive_karigar">
                <input type="hidden" name="back_view" value="assignments">
                <input type="hidden" name="assignment_id" value="<?= (int) $receiveTarget['id'] ?>">
                <input type="hidden" name="received_gross_weight" value="<?= e((string) ($_GET['wt'] ?? $receiveTarget['issued_gross_weight'])) ?>">
                <label>Finished item
                    <select name="received_item_id">
                        <?php foreach ($items as $it): ?>
                            <option value="<?= (int) $it['id'] ?>" <?= (int) $receiveTarget['item_id'] === (int) $it['id'] ? 'selected' : '' ?>><?= e($it['code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Received purity
                    <select name="received_purity_id">
                        <?php foreach ($purities as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" <?= (int) $receiveTarget['purity_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['metal_code'] . '·' . $p['code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Pieces<input type="number" name="qty_pieces" step="0.001" min="0" value="1"></label>
                <label>Receive date<input type="date" name="receive_date" value="<?= e($todayInFy) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>" required></label>
                <div style="grid-column:1/-1"><button type="submit" class="button">Confirm Receipt &amp; Post</button></div>
            </form>
        <?php else: ?>
            <div class="notice"><?= e((string) ($receivePreview['error'] ?? 'Enter the weight received back.')) ?></div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="mbw-card" style="margin-top:14px">
        <div class="mbw-card-head"><h2>Assignments (<?= count($assignments) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Issue no.</th><th>Date</th><th>Kaligad</th><th>Order</th><th>Item</th><th class="is-numeric">Issued (fine)</th><th class="is-numeric">Allowed wastage</th><th>Status</th><th></th></tr></thead>
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
                        <td class="is-numeric"><?= $fmt((float) $row['wastage_allowed_pct'], 3) ?>%</td>
                        <td><span class="mbw-pill <?= (string) $row['status'] === 'issued' ? 'tone-amber' : ((string) $row['status'] === 'received' ? 'tone-green' : 'tone-gray') ?>"><?= e(ucfirst((string) $row['status'])) ?></span></td>
                        <td style="white-space:nowrap">
                            <?php if ((string) $row['status'] === 'issued' && $canPost): ?>
                                <a class="button secondary" style="min-height:30px;padding:3px 10px" href="<?= e(url('admin/jewellery-workshop.php?view=assignments&receive=' . (int) $row['id'])) ?>">Receive</a>
                                <form method="post" style="display:inline" data-confirm="Cancel this assignment? The issued metal returns to own stock.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="cancel_assignment">
                                    <input type="hidden" name="back_view" value="assignments">
                                    <input type="hidden" name="assignment_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button soft" style="min-height:30px;padding:3px 10px">Cancel</button>
                                </form>
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
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'delivery'): ?>
    <div class="notice" style="margin-bottom:14px">
        These orders have come back from the kaligad and are finished, but the customer has not collected them yet.
    </div>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Received but Not Delivered (<?= count($pending) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Order</th><th>Customer</th><th>Received on</th><th class="is-numeric">Weight back</th><th class="is-numeric">Days waiting</th><th>Promised</th><th></th></tr></thead>
            <tbody>
                <?php if ($pending === []): ?><tr><td colspan="7">Nothing is waiting for collection.</td></tr><?php endif; ?>
                <?php foreach ($pending as $row): ?>
                    <tr>
                        <td><?= e($row['order_no']) ?></td>
                        <td><?= e((string) ($row['party_name'] ?? $row['customer_name'] ?? 'Walk-in')) ?><?= ($row['customer_phone'] ?? '') !== '' ? '<br><small>' . e((string) $row['customer_phone']) . '</small>' : '' ?></td>
                        <td><?= ($row['receive_date'] ?? null) ? e(app_date((string) $row['receive_date'])) : '—' ?></td>
                        <td class="is-numeric"><?= $fmt((float) ($row['received_gross_weight'] ?? 0), 4) ?> <small><?= e((string) $row['unit_code']) ?></small></td>
                        <td class="is-numeric"><?= (int) ($row['days_waiting'] ?? 0) > 7 ? '<span class="mbw-pill tone-red">' . (int) $row['days_waiting'] . '</span>' : (int) ($row['days_waiting'] ?? 0) ?></td>
                        <td><?= ($row['delivery_date'] ?? null) ? e(app_date((string) $row['delivery_date'])) : '—' ?></td>
                        <td>
                            <?php if ($canPost): ?>
                                <form method="post" data-confirm="Mark this order delivered to the customer?">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="deliver_order">
                                    <input type="hidden" name="back_view" value="delivery">
                                    <input type="hidden" name="order_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button secondary" style="min-height:30px;padding:3px 10px">Mark delivered</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'refinery'): ?>
    <?php if ($canPost): ?>
    <section class="mbw-card" data-draggable>
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

    <section class="mbw-card" style="margin-top:14px">
        <div class="mbw-card-head"><h2>Refinery Jobs (<?= count($jobs) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Job</th><th>Refiner</th><th>Issued</th><th class="is-numeric">Out (fine)</th><th class="is-numeric">Back (fine)</th><th class="is-numeric">Loss (fine)</th><th class="is-numeric">Charges</th><th>Status</th><th></th></tr></thead>
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
                        <td class="is-numeric"><?= $isOut ? '—' : '<span class="mbw-pill tone-amber">' . $fmt((float) $row['loss_fine_weight'], 4) . '</span>' ?></td>
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
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <?php $receiveJob = jewellery_refinery_job($companyId, (int) ($_GET['receive'] ?? 0)); ?>
    <?php if ($receiveJob && (string) $receiveJob['status'] === 'issued' && $canPost): ?>
    <section class="mbw-card" style="margin-top:14px">
        <div class="mbw-card-head">
            <h2>Receive Refined Metal — <?= e((string) $receiveJob['job_no']) ?></h2>
            <a class="mbw-view-all" href="<?= e(url('admin/jewellery-workshop.php?view=refinery')) ?>">Cancel</a>
        </div>
        <p class="frm-optional" style="margin:0 0 12px">
            <?= $fmt((float) $receiveJob['issued_fine_weight'], 4) ?> fine went out.
            Anything not returned is booked as a refining loss at the cost it was issued at.
        </p>
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

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
