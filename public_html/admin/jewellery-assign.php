<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/jewellery_assign.php';

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
    $result = jewellery_save_assignment($companyId, $fiscalYearId, $_POST + ['assign_kind' => $postKind], $userId);
    if ($result['ok']) {
        flash('success', 'Work assigned. The kaligad has the job; hand the metal over from Kaligad Issue when it goes.');
    } else {
        // Typed rows come back typed, not blank — a fifteen-column row is not
        // something anybody should have to enter twice.
        $_SESSION['jw_assign_retry'] = $_POST;
        flash('error', 'Not assigned. ' . implode(' ', $result['errors']));
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

$pageTitle = 'Kaligad Assign';
$pageSubtitle = 'Who is making what, to what size, by when — before any metal moves.';
$bodyClass = 'admin-layout jewellery-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
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
<section class="mbw-card" data-collapsible>
    <div class="mbw-card-head">
        <h2>Add New — <?= e(jewellery_assign_kinds()[$kind]) ?></h2>
        <div class="mbw-card-tools"><span class="mbw-view-all">Assignment number is issued on save</span></div>
    </div>
    <form method="post" id="jw-assign-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="assign_kind" value="<?= e($kind) ?>">
        <div class="frm-grid frm-grid-4">
            <label>Kaligadh name <em>*</em>
                <select name="karigar_id" required>
                    <option value="">Select kaligad</option>
                    <?php foreach ($karigars as $k): ?>
                        <option value="<?= (int) $k['id'] ?>" <?= (int) ($retry['karigar_id'] ?? 0) === (int) $k['id'] ? 'selected' : '' ?>><?= e($k['code'] . ' — ' . $k['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <?php if ($isCustomer): ?>
                <label>Order number <em>*</em>
                    <select name="order_id" id="jw-order" required>
                        <option value="">Select order</option>
                        <?php foreach ($orderPayload as $o): ?>
                            <option value="<?= (int) $o['id'] ?>" <?= (int) ($retry['order_id'] ?? 0) === (int) $o['id'] ? 'selected' : '' ?>><?= e((string) $o['order_no']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Customer name
                    <input type="text" id="jw-customer" value="" placeholder="From the order" disabled>
                </label>
                <label>Size / design
                    <input type="text" id="jw-size" name="size_design" value="" placeholder="From the order" readonly>
                </label>
                <label class="frm-span-3">Expected ornament <em>*</em>
                    <select name="order_line_id" id="jw-line" required>
                        <option value="">Pick the order first</option>
                    </select>
                </label>
            <?php else: ?>
                <label>Size / design
                    <input type="text" name="size_design" maxlength="120" placeholder="Ring 14, chain 22 in" value="<?= e((string) ($retry['size_design'] ?? '')) ?>">
                </label>
                <label>Expected ornament <em>*</em>
                    <select name="item_id" id="jw-item" required>
                        <option value="">Select finished stock item</option>
                        <?php foreach ($stockItems as $it): ?>
                            <option value="<?= (int) $it['id'] ?>" data-name="<?= e((string) $it['name']) ?>" <?= (int) ($retry['item_id'] ?? 0) === (int) $it['id'] ? 'selected' : '' ?>><?= e($it['name'] . ' (' . $it['sku'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <input type="hidden" name="expected_ornament" id="jw-ornament-name" value="<?= e((string) ($retry['expected_ornament'] ?? '')) ?>">
            <?php endif; ?>

            <label>Category <em>*</em>
                <select name="category">
                    <?php foreach (jewellery_assign_categories() as $catKey => $catLabel): ?>
                        <option value="<?= e($catKey) ?>" <?= (string) ($retry['category'] ?? 'gold') === $catKey ? 'selected' : '' ?>><?= e($catLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Gross weight (with stone / diamond) <em>*</em>
                <input type="number" name="expected_gross_weight" id="jw-gross" step="0.0001" min="0" placeholder="0.0000"
                       value="<?= e((string) ($retry['expected_gross_weight'] ?? '')) ?>" <?= $isCustomer ? 'readonly' : 'required' ?>>
            </label>
            <label>Stone / diamond
                <input type="number" name="expected_stone_weight" id="jw-stone" step="0.0001" min="0" placeholder="0.0000"
                       value="<?= e((string) ($retry['expected_stone_weight'] ?? '')) ?>" <?= $isCustomer ? 'readonly' : '' ?>>
            </label>
            <label>Net weight
                <input type="number" id="jw-net" step="0.0001" value="" placeholder="Gross − stone" disabled title="Always gross minus stone — never typed">
            </label>
            <label>Purity <em>*</em>
                <?php if ($isCustomer): ?>
                    <input type="text" id="jw-purity-label" value="" placeholder="From the order" disabled>
                    <input type="hidden" name="purity_id" id="jw-purity">
                <?php else: ?>
                    <select name="purity_id" required>
                        <option value="">Select purity</option>
                        <?php foreach ($purities as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" <?= (int) ($retry['purity_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['metal_code'] . ' · ' . $p['code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </label>

            <label>Making charge basis
                <select name="making_basis">
                    <?php foreach (jewellery_assign_making_bases() as $basisKey => $basisLabel): ?>
                        <option value="<?= e($basisKey) ?>" <?= (string) ($retry['making_basis'] ?? 'flat') === $basisKey ? 'selected' : '' ?>><?= e($basisLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Making charge
                <input type="number" name="making_rate" step="0.0001" min="0" placeholder="0.0000" value="<?= e((string) ($retry['making_rate'] ?? '')) ?>">
            </label>
            <label>Assigned date <em>*</em>
                <input type="date" name="assigned_date" id="jw-assigned" value="<?= e((string) ($retry['assigned_date'] ?? $todayInFy)) ?>" required>
                <?php if ($isCustomer): ?><small class="frm-optional" id="jw-assigned-hint">Not before the order was taken</small><?php endif; ?>
            </label>
            <label>Expected delivery
                <input type="date" name="expected_delivery" id="jw-delivery" value="<?= e((string) ($retry['expected_delivery'] ?? '')) ?>">
                <?php if ($isCustomer): ?><small class="frm-optional" id="jw-delivery-hint">Not beyond the customer's promise date</small><?php endif; ?>
            </label>
            <label class="frm-span-3">Description
                <input type="text" name="description" maxlength="255" placeholder="Anything the kaligad should know about this piece" value="<?= e((string) ($retry['description'] ?? '')) ?>">
            </label>
        </div>
        <div style="margin-top:12px">
            <button type="submit" class="button" <?= $karigars === [] || ($isCustomer && $orderPayload === []) ? 'disabled' : '' ?>><?= icon('plus') ?>Add New</button>
        </div>
    </form>
</section>
<?php endif; ?>

<section class="mbw-card">
    <div class="mbw-card-head">
        <h2><?= $isCustomer ? 'For customer ordered item' : 'For self ordered item for showroom' ?></h2>
        <div class="mbw-card-tools"><?= $canExport && $rows !== [] ? 'Export' . $exportLinks() : '' ?></div>
    </div>

    <form method="get" class="jw-filter-bar" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <input type="hidden" name="kind" value="<?= e($kind) ?>">
        <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search assignment, kaligad, order, ornament" class="field-compact" style="min-width:260px">
        <input type="date" name="from" value="<?= e($filters['from']) ?>" class="field-compact" aria-label="Assigned from">
        <input type="date" name="to" value="<?= e($filters['to']) ?>" class="field-compact" aria-label="Assigned to">
        <button type="submit" class="button secondary"><?= icon('search') ?>Search</button>
        <?php if ($filters['q'] !== '' || $filters['from'] !== '' || $filters['to'] !== ''): ?>
            <a class="button secondary" href="<?= e(url('admin/jewellery-assign.php?kind=' . $kind)) ?>">Clear</a>
        <?php endif; ?>
    </form>

    <div style="overflow-x:auto">
        <table>
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
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="<?= $isCustomer ? 17 : 15 ?>" style="text-align:center;color:var(--mbw-muted);padding:18px">
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
                            <span class="mbw-pill tone-<?= e($tone) ?>"><?= e($label) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($isCustomer): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Picking an order number fills in everything the order already knows —
    // the customer, the size, the piece and its weights. Nobody retypes what
    // the order says, and the server reads them off the order again on save,
    // so a tampered field cannot make an assignment disagree with its order.
    var orders = <?= json_encode($orderPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var byId = {};
    orders.forEach(function (order) { byId[String(order.id)] = order; });

    var orderSelect = document.getElementById('jw-order');
    var lineSelect = document.getElementById('jw-line');
    var customer = document.getElementById('jw-customer');
    var size = document.getElementById('jw-size');
    var gross = document.getElementById('jw-gross');
    var stone = document.getElementById('jw-stone');
    var net = document.getElementById('jw-net');
    var purity = document.getElementById('jw-purity');
    var purityLabel = document.getElementById('jw-purity-label');
    var assigned = document.getElementById('jw-assigned');
    var delivery = document.getElementById('jw-delivery');

    function money(value) { return (Math.round((Number(value) || 0) * 10000) / 10000).toFixed(4); }

    function recalcNet() {
        var g = Number(gross.value) || 0;
        var s = Number(stone.value) || 0;
        net.value = g > 0 ? money(g - s) : '';
    }

    function applyOrder() {
        var order = byId[orderSelect.value];
        lineSelect.innerHTML = '';
        if (!order) {
            lineSelect.appendChild(new Option('Pick the order first', ''));
            customer.value = '';
            assigned.removeAttribute('min');
            delivery.removeAttribute('max');
            return;
        }
        customer.value = order.customer_name || '';
        // The dates are fenced by the order's own: work cannot be assigned
        // before it was taken, nor promised back after the customer was told.
        if (order.order_date) { assigned.setAttribute('min', order.order_date); }
        if (order.delivery_date) { delivery.setAttribute('max', order.delivery_date); }

        lineSelect.appendChild(new Option('Select the item being made', ''));
        order.lines.forEach(function (line) {
            var label = line.item_name + ' (' + line.item_code + ') — ' + Number(line.gross_weight).toFixed(3) + ' ' + line.unit_code;
            var option = new Option(label, line.id);
            option.dataset.line = JSON.stringify(line);
            lineSelect.appendChild(option);
        });
        if (order.lines.length === 1) { lineSelect.selectedIndex = 1; }
        applyLine();
    }

    function applyLine() {
        var option = lineSelect.options[lineSelect.selectedIndex];
        var line = option && option.dataset.line ? JSON.parse(option.dataset.line) : null;
        if (!line) {
            gross.value = ''; stone.value = ''; net.value = '';
            size.value = ''; purity.value = ''; purityLabel.value = '';
            return;
        }
        gross.value = money(line.gross_weight);
        stone.value = money(line.stone_weight);
        size.value = line.size || '';
        purity.value = line.purity_id;
        purityLabel.value = line.purity_code || '';
        if (line.delivery_date) { delivery.value = line.delivery_date; }
        recalcNet();
    }

    orderSelect.addEventListener('change', applyOrder);
    lineSelect.addEventListener('change', applyLine);
    applyOrder();
});
</script>
<?php else: ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Net is the one figure nobody types, on either flow: it is always the
    // other two, so it is worked out in front of them as they type.
    var gross = document.getElementById('jw-gross');
    var stone = document.getElementById('jw-stone');
    var net = document.getElementById('jw-net');
    var item = document.getElementById('jw-item');
    var ornament = document.getElementById('jw-ornament-name');

    function recalc() {
        var g = Number(gross.value) || 0;
        var s = Number(stone.value) || 0;
        net.value = g > 0 ? (Math.round((g - s) * 10000) / 10000).toFixed(4) : '';
    }
    [gross, stone].forEach(function (field) { field.addEventListener('input', recalc); });

    // The piece's name follows the item chosen, so the printed sheet reads as
    // an ornament and not as a stock code.
    item.addEventListener('change', function () {
        var option = item.options[item.selectedIndex];
        ornament.value = (option && option.getAttribute('data-name')) || '';
    });
    if (item.value) { item.dispatchEvent(new Event('change')); }
    recalc();
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
