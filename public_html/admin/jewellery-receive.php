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

$canPost = user_can_do('jewellery', 'post');
$canExport = user_can_do('jewellery', 'export');

$kind = jw_enum($_GET['kind'] ?? null, ['customer', 'self'], 'customer');
$isCustomer = $kind === 'customer';

$clampDate = static function (string $date) use ($fyStart, $fyEnd): string {
    if ($date === '' || strtotime($date) === false) {
        $date = date('Y-m-d');
    }

    return $date < $fyStart ? $fyStart : ($date > $fyEnd ? $fyEnd : $date);
};
$todayInFy = $clampDate(date('Y-m-d'));

// ---------------------------------------------------------------------------
// Receive
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_permission('jewellery', 'post');
    $postKind = jw_enum($_POST['kind'] ?? null, ['customer', 'self'], 'customer');
    $result = jewellery_receive_from_karigar($companyId, $fiscalYearId, [
        'assignment_id' => (int) ($_POST['assignment_id'] ?? 0),
        'received_item_id' => (int) ($_POST['received_item_id'] ?? 0),
        'received_purity_id' => (int) ($_POST['received_purity_id'] ?? 0),
        'received_gross_weight' => (float) ($_POST['received_gross_weight'] ?? 0),
        'stone_weight' => (float) ($_POST['stone_weight'] ?? 0),
        'qty_pieces' => (float) ($_POST['qty_pieces'] ?? 0),
        'receive_date' => $clampDate((string) ($_POST['receive_date'] ?? '')),
        // Passed through as typed, blank included: blank means no allowance,
        // which is the rule. Casting it here would turn "nothing granted" into
        // "0.0000 granted" — the same answer, but the engine could no longer
        // tell the two apart.
        'allow_wastage_fine' => (string) ($_POST['allow_wastage_fine'] ?? ''),
        'notes' => (string) ($_POST['notes'] ?? ''),
    ], $userId);

    flash($result['ok'] ? 'success' : 'error', $result['ok']
        ? ('Received. Wages ' . $sym . number_format((float) $result['making_amount'], 2)
           . ', wastage recovery ' . $sym . number_format((float) $result['recovery_amount'], 2)
           . ', net payable ' . $sym . number_format((float) $result['net_payable'], 2) . '.')
        : $result['error']);
    redirect('admin/jewellery-receive.php?kind=' . $postKind);
}

$openAssignments = jewellery_open_assignments($companyId, $kind);

// The one being received against, and the arithmetic for what has been typed so
// far. Both come back through the querystring so the figures can be looked at
// before anything is committed.
$targetId = (int) ($_GET['receive'] ?? 0);
$target = null;
foreach ($openAssignments as $row) {
    if ((int) $row['id'] === $targetId) {
        $target = $row;
        break;
    }
}
$typedGross = (float) ($_GET['wt'] ?? 0);
$typedCarat = (float) ($_GET['ct'] ?? 0);
$typedAllow = (string) ($_GET['allow'] ?? '');
$unitGrams = (float) ($target['unit_grams'] ?? 0);
// The trade quotes stones in carats; the receipt records them in the same unit
// as the metal they were set into. Both are shown, one is typed.
$stoneInUnit = $typedCarat > 0 ? jewellery_carat_to_unit($typedCarat, $unitGrams) : 0.0;

$preview = null;
if ($target !== null && $typedGross > 0) {
    $preview = jewellery_preview_receipt(
        $companyId,
        (int) $target['id'],
        $typedGross,
        (int) $target['purity_id'],
        $typedAllow !== '' ? (float) $typedAllow : null,
        $stoneInUnit
    );
}
$wageStatement = $preview && $preview['ok'] ? jewellery_wage_statement([
    'making_amount' => $preview['making_amount'],
    'recovery_amount' => $preview['recovery_amount'],
    'net_payable' => $preview['net_payable'],
    'avg_fine_rate' => $preview['avg_fine_rate'],
]) : null;

// ---------------------------------------------------------------------------
// What has already come back, and its export
// ---------------------------------------------------------------------------
$filters = ['kind' => $kind, 'from' => (string) ($_GET['from'] ?? ''), 'to' => (string) ($_GET['to'] ?? '')];
$received = jewellery_workshop_output($companyId, $filters);

$exportFormat = jw_enum($_GET['export'] ?? null, ['csv', 'xlsx', 'print'], '');
if ($exportFormat !== '' && ($_GET['export'] ?? '') !== '') {
    require_permission('jewellery', 'export');
    require_once __DIR__ . '/../../app/export_engine.php';
    export_dispatch(
        $exportFormat,
        'kaligad-received-' . $kind . '-' . date('Ymd-His'),
        jewellery_output_export_rows($received, $sym),
        $isCustomer ? 'Received — customer ordered' : 'Received — self ordered',
        ['Company' => (string) ($company['name'] ?? ''), 'Fiscal year' => (string) ($fiscalYear['label'] ?? '')]
    );
    exit;
}

$exportLinks = static function () use ($kind, $filters): string {
    $query = ['kind' => $kind] + array_filter($filters, static fn ($v): bool => (string) $v !== '' && $v !== $kind);
    $links = '';
    foreach (['csv' => 'CSV', 'xlsx' => 'Excel', 'print' => 'PDF'] as $format => $label) {
        $query['export'] = $format;
        $links .= '<a class="mbw-view-all" style="margin-left:10px"'
            . ($format === 'print' ? ' target="_blank" rel="noopener"' : '')
            . ' href="' . e(url('admin/jewellery-receive.php?' . http_build_query($query))) . '">' . $label . '</a>';
    }

    return $links;
};

$fmt = static fn (float $n, int $dp = 2): string => number_format($n, $dp);
$pageTitle = 'Kaligad Receive';
$pageSubtitle = 'What actually came back, weighed — everything else the assignment already knows.';
$bodyClass = 'admin-layout jewellery-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<nav class="mbw-tabbar" aria-label="Jewellery workshop">
    <a class="mbw-tab" href="<?= e(url('admin/jewellery-workshop.php?view=orders')) ?>"><?= icon('journal') ?>Orders</a>
    <a class="mbw-tab" href="<?= e(url('admin/jewellery-assign.php')) ?>"><?= icon('handshake') ?>Kaligad Assign</a>
    <a class="mbw-tab is-active" href="<?= e(url('admin/jewellery-receive.php')) ?>"><?= icon('box') ?>Kaligad Receive</a>
    <a class="mbw-tab" href="<?= e(url('admin/jewellery-workshop.php?view=delivery')) ?>"><?= icon('box') ?>Ready to Deliver</a>
    <a class="mbw-tab" href="<?= e(url('admin/jewellery-workshop.php?view=ready-to-sale')) ?>"><?= icon('cart') ?>Ready to Sale</a>
</nav>

<nav class="vch-typebar" style="grid-template-columns:repeat(2,minmax(0,1fr))" aria-label="Receive kind">
    <?php foreach (jewellery_assign_kinds() as $kindKey => $kindLabel): ?>
        <a class="vch-type<?= $kindKey === $kind ? ' is-active' : '' ?> tone-<?= $kindKey === 'customer' ? 'blue' : 'purple' ?>"
           href="<?= e(url('admin/jewellery-receive.php?kind=' . $kindKey)) ?>"
           <?= $kindKey === $kind ? 'aria-current="page"' : '' ?>>
            <span class="vch-type-icon"><?= icon($kindKey === 'customer' ? 'clients' : 'inventory') ?></span>
            <span class="vch-type-name"><?= e($kindLabel) ?></span>
            <span class="vch-type-key"><?= $kindKey === 'customer' ? 'Back to the customer' : 'Back to the shelf' ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<section class="mbw-card">
    <div class="mbw-card-head">
        <h2>Out with a kaligad (<?= count($openAssignments) ?>)</h2>
        <div class="mbw-card-tools"><span class="mbw-view-all">Pick the assignment being received against</span></div>
    </div>
    <div style="overflow-x:auto"><table>
        <thead><tr><th style="width:44px">SN</th><th>Assignment</th><th>Kaligadh</th>
            <?php if ($isCustomer): ?><th>Order</th><th>Customer</th><?php endif; ?>
            <th>Expected ornament</th><th>Size / design</th>
            <th class="is-numeric">Expected net</th><th class="is-numeric">Issued fine</th>
            <th>Assigned</th><th>Due back</th><th></th></tr></thead>
        <tbody>
            <?php if ($openAssignments === []): ?>
                <tr><td colspan="<?= $isCustomer ? 12 : 10 ?>" style="text-align:center;color:var(--mbw-muted);padding:18px">
                    Nothing is out with a kaligad. Assign work under
                    <a href="<?= e(url('admin/jewellery-assign.php?kind=' . $kind)) ?>">Kaligad Assign</a>.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($openAssignments as $index => $row): ?>
                <tr<?= (int) $row['id'] === $targetId ? ' style="background:var(--mbw-primary-soft)"' : '' ?>>
                    <td><?= $index + 1 ?></td>
                    <td><strong><?= e((string) $row['assignment_no']) ?></strong></td>
                    <td><?= e(trim((string) $row['karigar_code'] . ' — ' . (string) $row['karigar_name'])) ?></td>
                    <?php if ($isCustomer): ?>
                        <td><?= e((string) ($row['order_no'] ?? '')) ?: '—' ?></td>
                        <td><?= e((string) ($row['customer_name'] ?? '')) ?: '—' ?></td>
                    <?php endif; ?>
                    <td><?= e((string) ($row['expected_ornament'] ?: $row['item_name'] ?? '')) ?></td>
                    <td><?= e((string) ($row['size_design'] ?? '')) ?: '—' ?></td>
                    <td class="is-numeric"><?= $fmt((float) $row['expected_net_weight'], 4) ?></td>
                    <td class="is-numeric"><?= $fmt((float) $row['issued_fine_weight'], 4) ?>
                        <?php if ((float) $row['issued_stone_carat'] > 0.0005): ?>
                            <br><small><?= $fmt((float) $row['issued_stone_carat'], 3) ?> ct stones</small>
                        <?php endif; ?>
                    </td>
                    <td><?= e(app_date((string) $row['issue_date'])) ?></td>
                    <td><?= $row['expected_return_date'] ? e(app_date((string) $row['expected_return_date'])) : '—' ?></td>
                    <td>
                        <?php if ($canPost): ?>
                            <a class="button secondary" style="min-height:30px;padding:3px 10px"
                               href="<?= e(url('admin/jewellery-receive.php?kind=' . $kind . '&receive=' . (int) $row['id'])) ?>">Receive</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<?php if ($target !== null && $canPost): ?>
<section class="mbw-card frm-section vch-focus">
    <div class="frm-section-head">
        <span class="mbw-chip is-square tone-green"><?= icon('scale') ?></span>
        <h2>Receiving <?= e((string) $target['assignment_no']) ?> — <?= e((string) $target['karigar_name']) ?></h2>
        <span class="frm-optional">Weigh what came back; the rest the assignment already knows</span>
    </div>

    <?php // Everything read off the assignment, shown but not asked for. ?>
    <div class="frm-grid frm-grid-4">
        <?php if ($isCustomer): ?>
            <label>Order number<input type="text" value="<?= e((string) ($target['order_no'] ?? '')) ?>" disabled></label>
            <label>Customer name<input type="text" value="<?= e((string) ($target['customer_name'] ?? '')) ?>" disabled></label>
        <?php endif; ?>
        <label>Kaligadh name<input type="text" value="<?= e(trim((string) $target['karigar_code'] . ' — ' . (string) $target['karigar_name'])) ?>" disabled></label>
        <label>Expected ornament<input type="text" value="<?= e((string) ($target['expected_ornament'] ?: $target['item_name'] ?? '')) ?>" disabled></label>
        <label>Size / design<input type="text" value="<?= e((string) ($target['size_design'] ?? '')) ?>" disabled></label>
        <label>Category<input type="text" value="<?= e(jewellery_assign_categories()[(string) $target['category']] ?? '') ?>" disabled></label>
        <label>Purity<input type="text" value="<?= e((string) ($target['purity_code'] ?? '')) ?>" disabled></label>
        <label>Making charge<input type="text" value="<?= e(jewellery_assign_making_charge_label($target, $sym)) ?>" disabled></label>
        <label>Assigned date<input type="text" value="<?= e(app_date((string) $target['issue_date'])) ?>" disabled></label>
        <label>Expected delivery<input type="text" value="<?= $target['expected_return_date'] ? e(app_date((string) $target['expected_return_date'])) : '—' ?>" disabled></label>
    </div>

    <?php // The three figures that are actually typed, and nothing else. ?>
    <form method="get" class="frm-grid frm-grid-4" style="margin-top:14px">
        <input type="hidden" name="kind" value="<?= e($kind) ?>">
        <input type="hidden" name="receive" value="<?= (int) $target['id'] ?>">
        <label>Gross weight back (with stone) <em>*</em>
            <input type="number" name="wt" step="0.0001" min="0" value="<?= e($typedGross > 0 ? (string) $typedGross : '') ?>"
                   placeholder="<?= e((string) $target['unit_code']) ?>" autofocus>
        </label>
        <label>Stone / diamond (carats)
            <input type="number" name="ct" step="0.001" min="0" value="<?= e($typedCarat > 0 ? (string) $typedCarat : '') ?>" placeholder="ct">
            <small class="frm-optional"><?= $typedCarat > 0
                ? '= ' . $fmt($stoneInUnit, 4) . ' ' . e((string) $target['unit_code']) . ' on the scale'
                : '1 ct = 0.2 g' ?></small>
        </label>
        <label>Wastage to allow (fine)
            <input type="number" name="allow" step="0.0001" min="0" value="<?= e($typedAllow) ?>" placeholder="0.0000">
            <small class="frm-optional">Blank means none is forgiven</small>
        </label>
        <label style="align-self:end"><button type="submit" class="button secondary"><?= icon('scale') ?>Work it out</button></label>
    </form>

    <?php if ($preview !== null && !$preview['ok']): ?>
        <div class="notice error" style="margin-top:12px"><?= e((string) $preview['error']) ?></div>
    <?php endif; ?>

    <?php if ($preview !== null && $preview['ok']): ?>
        <div style="overflow-x:auto;margin-top:14px"><table>
            <tbody>
                <tr><td>Issued (fine)</td><td class="is-numeric"><?= $fmt((float) $preview['issued_fine'], 4) ?></td></tr>
                <?php if ((float) $preview['stone_weight'] > 0.00005): ?>
                    <tr><td>On the scale (gross)</td><td class="is-numeric"><?= $fmt($typedGross, 4) ?></td></tr>
                    <tr><td>Stones — not gold<?= $typedCarat > 0 ? ' (' . $fmt($typedCarat, 3) . ' ct)' : '' ?></td>
                        <td class="is-numeric">− <?= $fmt((float) $preview['stone_weight'], 4) ?></td></tr>
                    <tr><td><strong>Net weight (metal returned)</strong></td>
                        <td class="is-numeric"><strong><?= $fmt((float) $preview['net_gold_weight'], 4) ?></strong></td></tr>
                <?php endif; ?>
                <tr><td>Received (fine)</td><td class="is-numeric"><?= $fmt((float) $preview['received_fine'], 4) ?></td></tr>
                <tr><td>Wastage (fine)</td><td class="is-numeric"><?= $fmt((float) $preview['wastage_fine'], 4) ?></td></tr>
                <tr><td>Wastage allowed<?= (float) $preview['allowed_fine'] > 0.00005 ? ' — written off by the shop' : '' ?></td>
                    <td class="is-numeric"><?= $fmt((float) $preview['allowed_fine'], 4) ?></td></tr>
                <tr><td><strong>Wastage the kaligad bears (fine)</strong></td>
                    <td class="is-numeric"><strong><?= $fmt((float) $preview['excess_fine'], 4) ?></strong></td></tr>
                <?php if ((float) ($preview['surplus_fine'] ?? 0) > 0.00005): ?>
                    <tr><td>Metal the kaligad added (fine)</td><td class="is-numeric"><?= $fmt((float) $preview['surplus_fine'], 4) ?></td></tr>
                <?php endif; ?>
                <tr><td>Making charge</td><td class="is-numeric"><?= e($sym) ?><?= $fmt((float) $preview['making_amount']) ?></td></tr>
                <tr><td>Recovered from wages</td><td class="is-numeric">− <?= e($sym) ?><?= $fmt((float) $preview['recovery_amount']) ?></td></tr>
                <tr><td><strong>Net payable to the kaligad</strong></td>
                    <td class="is-numeric"><strong><?= e($sym) ?><?= $fmt((float) $preview['net_payable']) ?></strong>
                        <?= (float) $preview['net_payable'] < 0 ? ' <span class="mbw-pill tone-red">Kaligad owes the shop</span>' : '' ?></td></tr>
                <?php if ($wageStatement && $wageStatement['convertible']): ?>
                    <tr><td>The same wage in gold<br><small><?= e(jewellery_wage_rate_note($wageStatement, $sym)) ?></small></td>
                        <td class="is-numeric"><strong><?= $fmt((float) $wageStatement['net_payable_fine'], 4) ?></strong> fine</td></tr>
                <?php endif; ?>
            </tbody>
        </table></div>

        <form method="post" style="margin-top:14px">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="kind" value="<?= e($kind) ?>">
            <input type="hidden" name="assignment_id" value="<?= (int) $target['id'] ?>">
            <input type="hidden" name="received_item_id" value="<?= (int) $target['item_id'] ?>">
            <input type="hidden" name="received_purity_id" value="<?= (int) $target['purity_id'] ?>">
            <input type="hidden" name="received_gross_weight" value="<?= e((string) $typedGross) ?>">
            <input type="hidden" name="stone_weight" value="<?= e((string) $stoneInUnit) ?>">
            <input type="hidden" name="allow_wastage_fine" value="<?= e($typedAllow) ?>">
            <div class="frm-grid frm-grid-4">
                <label>Receive date <em>*</em>
                    <input type="date" name="receive_date" value="<?= e($todayInFy) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>" required>
                </label>
                <label class="frm-span-3">Description
                    <input type="text" name="notes" maxlength="255" placeholder="Anything about this piece worth recording"
                           value="<?= e((string) ($target['notes'] ?? '')) ?>">
                </label>
            </div>
            <div style="margin-top:12px">
                <button type="submit" class="button"><?= icon('badge-check') ?>Receive &amp; post</button>
                <a class="button secondary" href="<?= e(url('admin/jewellery-receive.php?kind=' . $kind)) ?>">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="mbw-card">
    <div class="mbw-card-head">
        <h2><?= $isCustomer ? 'Received — customer ordered' : 'Received — self ordered' ?> (<?= count($received) ?>)</h2>
        <div class="mbw-card-tools"><?= $canExport && $received !== [] ? 'Export' . $exportLinks() : '' ?></div>
    </div>
    <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <input type="hidden" name="kind" value="<?= e($kind) ?>">
        <input type="date" name="from" value="<?= e($filters['from']) ?>" class="field-compact" aria-label="Received from">
        <input type="date" name="to" value="<?= e($filters['to']) ?>" class="field-compact" aria-label="Received to">
        <button type="submit" class="button secondary"><?= icon('filter') ?>Filter</button>
    </form>
    <div style="overflow-x:auto"><table>
        <thead><tr><th style="width:44px">SN</th><th>Assignment</th><th>Kaligadh</th>
            <?php if ($isCustomer): ?><th>Order</th><th>Customer</th><?php endif; ?>
            <th>Ornament</th><th>Received on</th><th class="is-numeric">Gross</th>
            <th class="is-numeric">Stone</th><th class="is-numeric">Net</th><th class="is-numeric">Fine</th>
            <th class="is-numeric">Wastage</th><th class="is-numeric">Making</th><th>Remarks</th></tr></thead>
        <tbody>
            <?php if ($received === []): ?>
                <tr><td colspan="<?= $isCustomer ? 14 : 12 ?>" style="text-align:center;color:var(--mbw-muted);padding:18px">Nothing received back yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($received as $index => $row): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><strong><?= e((string) $row['assignment_no']) ?></strong><br><small><?= e((string) ($row['receipt_no'] ?? '')) ?></small></td>
                    <td><?= e((string) ($row['karigar_name'] ?? '')) ?></td>
                    <?php if ($isCustomer): ?>
                        <td><?= e((string) ($row['order_no'] ?? '')) ?: '—' ?></td>
                        <td><?= e((string) ($row['customer_name'] ?? '')) ?: '—' ?></td>
                    <?php endif; ?>
                    <td><?= e((string) ($row['expected_ornament'] ?: $row['item_name'] ?? '')) ?></td>
                    <td><?= e(app_date((string) $row['receive_date'])) ?></td>
                    <td class="is-numeric"><?= $fmt((float) $row['received_gross_weight'], 4) ?></td>
                    <td class="is-numeric"><?= $fmt((float) ($row['stone_weight'] ?? 0), 4) ?></td>
                    <td class="is-numeric"><?= $fmt((float) ($row['net_gold_weight'] ?? 0), 4) ?></td>
                    <td class="is-numeric"><strong><?= $fmt((float) $row['received_fine_weight'], 4) ?></strong></td>
                    <td class="is-numeric"><?= $fmt((float) ($row['wastage_fine_weight'] ?? 0), 4) ?></td>
                    <td class="is-numeric"><?= e($sym) ?><?= $fmt((float) ($row['making_amount'] ?? 0)) ?></td>
                    <td><small><?= e((string) $row['remark']) ?></small></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
