<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/jewellery_stock.php';
require_once __DIR__ . '/../../app/jewellery_trace.php';
require_once __DIR__ . '/../../app/views/partials/jewellery_page_head.php';

accounting_module_repair_database();
require_jewellery();

$company = current_company();
if (!$company) {
    flash('error', 'Company context required.');
    redirect('admin/accounting-dashboard.php');
}
$companyId = (int) $company['id'];
jewellery_trace_backfill_legacy_balance($companyId, (int) (current_user()['id'] ?? 0));

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'status' => (string) ($_GET['status'] ?? ''),
    'stock_kind' => (string) ($_GET['stock_kind'] ?? ''),
];
$rows = jewellery_trace_units_list($companyId, $filters);
$allRows = jewellery_trace_units_list($companyId);
$selectedId = (int) ($_GET['id'] ?? 0);
$selected = $selectedId > 0 ? jewellery_trace_unit($companyId, $selectedId) : null;
$events = $selected ? jewellery_trace_lifecycle($companyId, $selectedId) : [];
$counts = array_fill_keys(jewellery_trace_statuses(), 0);
foreach ($allRows as $unit) {
    $counts[(string) $unit['status']] = ($counts[(string) $unit['status']] ?? 0) + 1;
}

$pageTitle = 'Jewellery Item Traceability';
$pageSubtitle = 'One permanent identity from opening, purchase or order through custody, reservation and final sale.';
$pageHero = ['icon' => 'search'];
$bodyClass = 'admin-layout accounting-module-page';
$pageBreadcrumb = [['Home', 'admin/index.php'], ['Jewellery', 'admin/jewellery.php'], ['Item Traceability', 'admin/jewellery-trace.php']];
include __DIR__ . '/../../app/views/partials/admin_header.php';
$fmt = static fn (float $number, int $places = 4): string => number_format($number, $places);
?>

<?php jw_page_head('Item Traceability', 'Search a trace code and see the exact item’s complete, append-only lifecycle.', 'search',
    '<span class="mbw-pill tone-green">' . count($allRows) . ' traced item(s)</span>'); ?>

<nav class="mbw-tabbar" aria-label="Jewellery stock">
    <a class="mbw-tab" href="<?= e(url('admin/jewellery.php?view=stock')) ?>"><?= icon('layers') ?>Stock position</a>
    <a class="mbw-tab" href="<?= e(url('admin/jewellery-workshop.php?view=ready-to-sale')) ?>"><?= icon('cart') ?>Ready to Sale</a>
    <a class="mbw-tab is-active" href="<?= e(url('admin/jewellery-trace.php')) ?>"><?= icon('search') ?>Traceability</a>
</nav>

<section class="mbw-kpi-grid" style="margin-bottom:14px">
    <?php foreach ([
        ['In stock', $counts['in_stock'], 'green'], ['Reserved', $counts['reserved'], 'amber'],
        ['In production', $counts['in_production'], 'blue'], ['Sold', $counts['sold'], 'purple'],
    ] as [$label, $value, $tone]): ?>
        <article class="mbw-kpi tone-<?= e($tone) ?>"><span><?= e($label) ?></span><strong><?= (int) $value ?></strong></article>
    <?php endforeach; ?>
</section>

<?php if ($selected): ?>
<section class="mbw-card" style="margin-bottom:14px;border-top:3px solid var(--mbw-primary,#0f766e)">
    <div class="mbw-card-head"><h2><?= e((string) $selected['trace_code']) ?> — <?= e((string) $selected['item_name']) ?></h2>
        <a class="mbw-view-all" href="<?= e(url('admin/jewellery-trace.php')) ?>">Close detail</a></div>
    <div class="workspace-form-grid" style="margin-bottom:16px">
        <div><small>Status</small><br><span class="mbw-pill tone-<?= (string) $selected['status'] === 'sold' ? 'purple' : ((string) $selected['status'] === 'reserved' ? 'amber' : 'green') ?>"><?= e(ucwords(str_replace('_', ' ', (string) $selected['status']))) ?></span></div>
        <div><small>Origin</small><br><strong><?= e(ucwords(str_replace('_', ' ', (string) $selected['origin_type']))) ?></strong></div>
        <div><small>Stock type</small><br><strong><?= e((string) $selected['stock_kind'] === 'showroom' ? 'Showroom' : 'Customer ordered') ?></strong></div>
        <div><small>Physical facts</small><br><strong><?= $fmt((float) $selected['gross_weight']) ?> <?= e((string) $selected['unit_code']) ?></strong> · <?= $fmt((float) $selected['qty_pieces'], 3) ?> pc</div>
        <div><small>Cost</small><br><strong><?= e(site_currency_symbol()) ?> <?= number_format((float) $selected['cost_amount'], 2) ?></strong></div>
        <div><small>Reference</small><br><strong><?= e((string) ($selected['stock_order_no'] ?: $selected['customer_order_no'] ?: '—')) ?></strong></div>
    </div>
    <h3 style="font-size:1rem;margin:0 0 8px">Lifecycle</h3>
    <div class="mbw-tablewrap"><table>
        <thead><tr><th>Date</th><th>Event</th><th>Status</th><th>Custody</th><th>Reference</th><th>Notes</th><th>User</th></tr></thead>
        <tbody>
        <?php foreach ($events as $event): ?>
            <tr>
                <td><?= e(app_date((string) $event['event_date'])) ?></td>
                <td><strong><?= e(ucwords(str_replace('_', ' ', (string) $event['event_type']))) ?></strong></td>
                <td><?= e((string) ($event['from_status'] ?: 'Start')) ?> → <?= e((string) ($event['to_status'] ?: '—')) ?></td>
                <td><?= e((string) ($event['from_holder_type'] ?: '—')) ?> → <?= e((string) ($event['to_holder_type'] ?: '—')) ?></td>
                <td><?= e((string) ($event['reference_no'] ?? '')) ?></td>
                <td><?= e((string) ($event['notes'] ?? '')) ?></td>
                <td><?= e((string) ($event['user_name'] ?? 'System')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>

<section class="mbw-card">
    <div class="mbw-card-head"><h2>Physical items (<?= count($rows) ?>)</h2></div>
    <form method="get" class="workspace-form-grid" style="margin-bottom:14px">
        <label>Search<input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Trace, item, stock order, customer order"></label>
        <label>Status<select name="status"><option value="">All statuses</option><?php foreach (jewellery_trace_statuses() as $status): ?><option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select></label>
        <label>Stock type<select name="stock_kind"><option value="">Both</option><option value="showroom" <?= $filters['stock_kind'] === 'showroom' ? 'selected' : '' ?>>Showroom</option><option value="customer_ordered" <?= $filters['stock_kind'] === 'customer_ordered' ? 'selected' : '' ?>>Customer ordered</option></select></label>
        <div style="align-self:end"><button class="button" type="submit"><?= icon('search') ?>Search</button> <a class="button secondary" href="<?= e(url('admin/jewellery-trace.php')) ?>">Clear</a></div>
    </form>
    <div class="mbw-tablewrap"><table>
        <thead><tr><th>Trace</th><th>Item</th><th>Origin</th><th>Stock type</th><th>Status</th><th>Holder</th><th class="is-numeric">Pieces</th><th class="is-numeric">Gross</th><th>Reservation / sale</th></tr></thead>
        <tbody>
        <?php if ($rows === []): ?><tr><td colspan="9" style="text-align:center;padding:18px">No trace items match this search.</td></tr><?php endif; ?>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><a class="reference-link" href="<?= e(url('admin/jewellery-trace.php?id=' . (int) $row['id'])) ?>"><strong><?= e((string) $row['trace_code']) ?></strong></a></td>
                <td><?= e((string) $row['item_code']) ?> — <?= e((string) $row['item_name']) ?><br><small><?= e((string) $row['purity_code']) ?></small></td>
                <td><?= e(ucwords(str_replace('_', ' ', (string) $row['origin_type']))) ?><br><small><?= e((string) ($row['stock_order_no'] ?: $row['customer_order_no'] ?: '')) ?></small></td>
                <td><?= e((string) $row['stock_kind'] === 'showroom' ? 'Showroom' : 'Customer ordered') ?></td>
                <td><span class="mbw-pill tone-<?= (string) $row['status'] === 'sold' ? 'purple' : ((string) $row['status'] === 'reserved' ? 'amber' : 'green') ?>"><?= e(ucwords(str_replace('_', ' ', (string) $row['status']))) ?></span></td>
                <td><?= e(ucfirst((string) $row['current_holder_type'])) ?></td>
                <td class="is-numeric"><?= $fmt((float) $row['qty_pieces'], 3) ?></td>
                <td class="is-numeric"><?= $fmt((float) $row['gross_weight']) ?> <?= e((string) $row['unit_code']) ?></td>
                <td><?= e((string) ($row['reserved_order_no'] ?: $row['sold_sale_no'] ?: '—')) ?><br><small><?= e((string) ($row['reserved_customer'] ?? '')) ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
