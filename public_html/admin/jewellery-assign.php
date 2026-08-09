<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/jewellery_assign.php';
require_once __DIR__ . '/../../app/views/partials/jewellery_page_head.php';

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

$canEdit = user_can_do('jewellery', 'edit');
$canExport = user_can_do('jewellery', 'export');

// The two tables of the sheet, each on its own tab: the customer one reads
// itself off an order, the showroom one is typed. Nothing is shared but the
// engine underneath.
$kind = jw_enum($_GET['kind'] ?? null, ['customer', 'self'], 'customer');
$isCustomer = $kind === 'customer';

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'from' => (string) ($_GET['from'] ?? ''),
    'to' => (string) ($_GET['to'] ?? ''),
];

$clampDate = static function (string $date) use ($fyStart, $fyEnd): string {
    if ($date === '' || strtotime($date) === false) {
        $date = date('Y-m-d');
    }

    return $date < $fyStart ? $fyStart : ($date > $fyEnd ? $fyEnd : $date);
};
$todayInFy = $clampDate(date('Y-m-d'));

// ---------------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_permission('jewellery', 'edit');
    $postKind = jw_enum($_POST['assign_kind'] ?? null, ['customer', 'self'], 'customer');
    $postAction = (string) ($_POST['action'] ?? 'create');
    if ($postAction === 'update_assignment') {
        $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
        $result = jewellery_update_assignment($companyId, $assignmentId, $_POST, $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ($result['changed'] === [] ? 'No assignment details changed.' : 'Assignment updated safely.')
            : $result['error']);
        redirect('admin/jewellery-assign.php?kind=' . $postKind . '&edit=' . $assignmentId . '#assignment-edit');
    }
    if ($postAction === 'remove_assignment') {
        $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
        $result = jewellery_unassign_assignment($companyId, $assignmentId, (string) ($_POST['reason'] ?? ''), $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Assignment cancelled and its order item released.' : $result['error']);
        redirect('admin/jewellery-assign.php?kind=' . $postKind);
    }
    $result = jewellery_save_assignments($companyId, $fiscalYearId, $postKind, $_POST, $userId);
    if ($result['ok']) {
        flash('success', $result['saved'] === 1
            ? 'Work assigned. The kaligad has the job; hand the metal over from Metal Issued when it goes.'
            : $result['saved'] . ' assignments saved. Hand the metal over from Metal Issued as each one goes.');
    } else {
        // Typed rows come back typed, not blank — a grid of fifteen columns is
        // not something anybody should have to enter twice.
        $_SESSION['jw_assign_retry'] = $_POST;
        flash('error', implode(' ', $result['errors']));
    }
    redirect('admin/jewellery-assign.php?kind=' . $postKind);
}

$retry = $_SESSION['jw_assign_retry'] ?? null;
unset($_SESSION['jw_assign_retry']);
$retry = is_array($retry) && jw_enum($retry['assign_kind'] ?? null, ['customer', 'self'], '') === $kind ? $retry : null;

$rows = jewellery_assign_rows($companyId, $kind, $filters);

// ---------------------------------------------------------------------------
// Export — the file carries the columns the screen shows, filters and all.
// ---------------------------------------------------------------------------
$exportFormat = jw_enum($_GET['export'] ?? null, ['csv', 'xlsx', 'print'], '');
if ($exportFormat !== '' && ($_GET['export'] ?? '') !== '') {
    require_permission('jewellery', 'export');
    require_once __DIR__ . '/../../app/export_engine.php';
    $title = $isCustomer ? 'Kaligad assignments — customer ordered' : 'Kaligad assignments — self ordered';
    export_dispatch(
        $exportFormat,
        'kaligad-assignments-' . $kind . '-' . date('Ymd-His'),
        jewellery_assign_export_rows($rows, $kind, $sym),
        $title,
        ['Company' => (string) ($company['name'] ?? ''), 'Fiscal year' => (string) ($fiscalYear['label'] ?? '')]
    );
    exit;
}

$exportLinks = static function () use ($kind, $filters): string {
    $query = ['kind' => $kind] + array_filter($filters, static fn ($v): bool => (string) $v !== '');
    $links = '';
    foreach (['csv' => 'CSV', 'xlsx' => 'Excel', 'print' => 'PDF'] as $format => $label) {
        $query['export'] = $format;
        $links .= '<a class="mbw-view-all" style="margin-left:10px"'
            . ($format === 'print' ? ' target="_blank" rel="noopener"' : '')
            . ' href="' . e(url('admin/jewellery-assign.php?' . http_build_query($query))) . '">' . $label . '</a>';
    }

    return $links;
};

$karigars = jewellery_karigars_list($companyId, true);
$purities = jewellery_purities_list($companyId);
$orderPayload = $isCustomer ? jewellery_assign_order_payload($companyId) : [];
$stockItems = $isCustomer ? [] : jewellery_assign_stock_items($companyId);
$fmt = static fn (float $n, int $dp = 2): string => number_format($n, $dp);

// Arriving from an order's own Assign button: that order opens already picked
// on the first row, because it is the reason the button was pressed. Only if it
// is still on the list — an order whose every item is already out with a
// kaligad is not there, and the grid must not open on one with nothing to give.
$presetOrderId = 0;
if ($isCustomer && (int) ($_GET['order'] ?? 0) > 0) {
    foreach ($orderPayload as $candidate) {
        if ((int) $candidate['id'] === (int) $_GET['order']) {
            $presetOrderId = (int) $candidate['id'];
            break;
        }
    }
}
$gridPrefill = $presetOrderId > 0 ? [['order_id' => $presetOrderId]] : [];
if ($gridPrefill !== [] && (int) ($_GET['karigar'] ?? 0) > 0) {
    $gridPrefill[0]['karigar_id'] = (int) $_GET['karigar'];
}

$editId = $canEdit ? (int) ($_GET['edit'] ?? 0) : 0;
$editState = $editId > 0 ? jewellery_assignment_edit_state($companyId, $editId) : null;
$editAssignment = $editState && $editState['found'] ? $editState['assignment'] : null;
if ($editAssignment && (string) $editAssignment['assign_kind'] !== $kind) {
    redirect('admin/jewellery-assign.php?kind=' . (string) $editAssignment['assign_kind'] . '&edit=' . $editId . '#assignment-edit');
}
$editOrderLines = $editAssignment && (string) $editAssignment['assign_kind'] === 'customer'
    ? jewellery_order_line_rows($companyId, (int) $editAssignment['order_id']) : [];
$hasAnotherOrderLine = false;
foreach ($editOrderLines as $candidateLine) {
    if ((int) ($candidateLine['assignment_id'] ?? 0) === 0) {
        $hasAnotherOrderLine = true;
        break;
    }
}

$pageTitle = 'Kaligad Assign';
$pageSubtitle = 'Who is making what, to what size, by when — before any metal moves.';
$bodyClass = 'admin-layout jewellery-module-page jw-assign-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<?php jw_page_head('Kaligad Assign', 'Assign one item at a time, before any metal, stock or accounting movement.', 'handshake',
    '<span class="mbw-pill tone-green">' . e(jewellery_assign_kinds()[$kind]) . '</span>'); ?>
<nav class="mbw-tabbar" aria-label="Jewellery workshop">
    <a class="mbw-tab" href="<?= e(url('admin/jewellery-workshop.php?view=orders')) ?>"><?= icon('journal') ?>Orders</a>
    <a class="mbw-tab is-active" href="<?= e(url('admin/jewellery-assign.php')) ?>"><?= icon('handshake') ?>Kaligad Assign</a>
    <a class="mbw-tab" href="<?= e(url('admin/jewellery-workshop.php?view=assignments')) ?>"><?= icon('scale') ?>Metal Issued</a>
    <a class="mbw-tab" href="<?= e(url('admin/jewellery-workshop.php?view=delivery')) ?>"><?= icon('box') ?>Ready to Deliver</a>
    <a class="mbw-tab" href="<?= e(url('admin/jewellery-workshop.php?view=karigars')) ?>"><?= icon('teams') ?>Kaligads</a>
</nav>

<nav class="vch-typebar" style="grid-template-columns:repeat(2,minmax(0,1fr))" aria-label="Assignment kind">
    <?php foreach (jewellery_assign_kinds() as $kindKey => $kindLabel): ?>
        <a class="vch-type<?= $kindKey === $kind ? ' is-active' : '' ?> tone-<?= $kindKey === 'customer' ? 'blue' : 'purple' ?>"
           href="<?= e(url('admin/jewellery-assign.php?kind=' . $kindKey)) ?>"
           <?= $kindKey === $kind ? 'aria-current="page"' : '' ?>>
            <span class="vch-type-icon"><?= icon($kindKey === 'customer' ? 'clients' : 'inventory') ?></span>
            <span class="vch-type-name"><?= e($kindLabel) ?></span>
            <span class="vch-type-key"><?= $kindKey === 'customer' ? 'Against an order' : 'Showroom stock' ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($karigars === []): ?>
    <div class="notice">No kaligads on file yet. Add one under <a href="<?= e(url('admin/jewellery-workshop.php?view=karigars')) ?>">Kaligads</a> before assigning work.</div>
<?php endif; ?>
<?php if ($isCustomer && $orderPayload === []): ?>
    <div class="notice">Every item on every open order is already out with a kaligad. Take a new order under <a href="<?= e(url('admin/jewellery-workshop.php?view=orders')) ?>">Orders</a>, or assign showroom stock on the other tab.</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<section class="mbw-card">
    <div class="mbw-card-head">
        <h2>Add New — <?= e(jewellery_assign_kinds()[$kind]) ?></h2>
        <div class="mbw-card-tools"><span class="mbw-view-all">Every row is checked before any is saved</span></div>
    </div>
    <?php // A grid, because the sheet this came from is a grid: five pieces
          // handed out on a Sunday morning are five rows and one save. ?>
    <form method="post" id="jw-assign-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="assign_kind" value="<?= e($kind) ?>">
        <?php include __DIR__ . '/../../app/views/jewellery/assign_grid.php'; ?>
        <div style="margin-top:12px">
            <button type="submit" class="button" <?= $karigars === [] || ($isCustomer && $orderPayload === []) ? 'disabled' : '' ?>><?= icon('plus') ?>Save all rows</button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php include __DIR__ . '/../../app/views/jewellery/assignment_edit.php'; ?>

<section class="mbw-card">
    <div class="mbw-card-head">
        <h2><?= $isCustomer ? 'For customer ordered item' : 'For self ordered item for showroom' ?></h2>
        <div class="mbw-card-tools"><span class="mbw-pill tone-gray"><?= count($rows) ?> assignments</span></div>
    </div>

    <div class="jw-register-tools">
    <form method="get" class="jw-filter-bar">
        <input type="hidden" name="kind" value="<?= e($kind) ?>">
        <label class="jw-filter-search"><span>Search</span><span class="jw-search-control"><?= icon('search') ?><input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Assignment, kaligad, order or ornament"></span></label>
        <label><span>Assigned from</span><input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
        <label><span>Assigned to</span><input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
        <div class="jw-filter-actions"><button type="submit" class="button secondary"><?= icon('search') ?>Apply Filters</button><a class="button secondary" href="<?= e(url('admin/jewellery-assign.php?kind=' . $kind)) ?>"><?= icon('close') ?>Clear Filters</a></div>
    </form>
    <?php if ($canExport && $rows !== []): ?><div class="jw-export-group"><span>Export</span><?= $exportLinks() ?></div><?php endif; ?>
    </div>

    <div class="mbw-tablewrap">
        <table class="jw-assignment-register">
            <thead>
                <tr>
                    <th style="width:44px">SN</th>
                    <th>Assignment number</th>
                    <th>Kaligadh name</th>
                    <?php if ($isCustomer): ?>
                        <th>Order number</th>
                        <th>Customer name</th>
                    <?php endif; ?>
                    <th>Size / design</th>
                    <th>Expected ornament</th>
                    <th>Category</th>
                    <th class="is-numeric">Gross weight</th>
                    <th class="is-numeric">Stone / diamond</th>
                    <th class="is-numeric">Net weight</th>
                    <th>Purity</th>
                    <th>Making charge</th>
                    <th>Assigned date</th>
                    <th>Expected delivery</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th class="jw-action-column">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="<?= $isCustomer ? 18 : 16 ?>" style="text-align:center;color:var(--mbw-muted);padding:18px">
                        Nothing assigned yet<?= $filters['q'] !== '' || $filters['from'] !== '' || $filters['to'] !== '' ? ' for this search' : '' ?>.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $index => $row): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><strong><?= e((string) $row['assignment_no']) ?></strong></td>
                        <td><?= e(trim((string) $row['karigar_code'] . ' — ' . (string) $row['karigar_name'])) ?></td>
                        <?php if ($isCustomer): ?>
                            <td><?= $row['order_no'] ? '<a class="reference-link" href="' . e(url('admin/jewellery-workshop.php?view=orders&edit=' . (int) $row['order_id'])) . '">' . e((string) $row['order_no']) . '</a>' : '—' ?></td>
                            <td><?= e((string) ($row['customer_name'] ?? '')) ?></td>
                        <?php endif; ?>
                        <td><?= e((string) ($row['size_design'] ?? '')) ?: '—' ?></td>
                        <td><?= e((string) ($row['expected_ornament'] ?: $row['item_name'] ?? '')) ?></td>
                        <td><?= e(jewellery_assign_categories()[(string) $row['category']] ?? (string) $row['category']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['expected_gross_weight'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['expected_stone_weight'], 4) ?></td>
                        <td class="is-numeric"><strong><?= $fmt((float) $row['expected_net_weight'], 4) ?></strong></td>
                        <td><?= e((string) ($row['purity_code'] ?? '')) ?></td>
                        <td><?= e(jewellery_assign_making_charge_label($row, $sym)) ?></td>
                        <td><?= e(app_date((string) $row['issue_date'])) ?></td>
                        <td><?= $row['expected_return_date'] ? e(app_date((string) $row['expected_return_date'])) : '—' ?></td>
                        <td><?= e((string) ($row['notes'] ?? '')) ?></td>
                        <td>
                            <?php
                            $status = (string) $row['status'];
                            $tone = ['issued' => 'amber', 'received' => 'green', 'cancelled' => 'red'][$status] ?? 'gray';
                            $label = $status === 'issued'
                                ? ((float) $row['issued_gross_weight'] > 0 ? 'Metal out' : 'Assigned')
                                : ucfirst($status);
                            ?>
                            <span class="mbw-pill tone-<?= e($tone) ?>"><?= icon($status === 'cancelled' ? 'close' : ($status === 'received' ? 'badge-check' : 'calendar')) ?><?= e($label) ?></span>
                        </td>
                        <td class="jw-action-column"><?php if ($canEdit): ?><a class="jw-edit-action" href="<?= e(url('admin/jewellery-assign.php?kind=' . $kind . '&edit=' . (int) $row['id'] . '#assignment-edit')) ?>" title="Edit assignment <?= e((string) $row['assignment_no']) ?>"><?= icon('edit') ?><span>Edit</span></a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../../app/views/jewellery/assign_grid_script.php'; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
