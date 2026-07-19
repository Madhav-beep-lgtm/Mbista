<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/stock_report_engine.php';

require_staff_admin_or_client_books();
require_company_context();
require_permission('inventory', 'view');
accounting_module_repair_database();

$company = current_company();
$fiscalYear = current_fiscal_year();
if (!$company || !$fiscalYear) {
    flash('error', 'Company and fiscal year context required.');
    redirect('admin/accounting-dashboard.php');
}
$companyId = (int) $company['id'];
$sym = site_currency_symbol();
$fyStart = (string) $fiscalYear['start_date'];
$fyEnd = (string) $fiscalYear['end_date'];

$itemId = (int) ($_GET['item'] ?? 0);
$clampDate = static function (string $value, string $fallback) use ($fyStart, $fyEnd): string {
    $value = trim($value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $fallback;
    }
    return max($fyStart, min($fyEnd, $value));
};
$from = $clampDate((string) ($_GET['from'] ?? ''), $fyStart);
$to = $clampDate((string) ($_GET['to'] ?? ''), $fyEnd);
if ($to < $from) {
    $to = $from;
}
$warehouseIds = array_values(array_filter(array_map('intval', (array) ($_GET['warehouses'] ?? []))));
$returnQs = (string) ($_GET['return'] ?? '');
$backUrl = url('admin/stock-summary-report.php' . ($returnQs !== '' ? '?' . $returnQs : ''));

$ledger = sr_stock_ledger($companyId, $itemId, $from, $to, $warehouseIds);
if (!$ledger['item']) {
    flash('error', 'Item not found for this company.');
    redirect('admin/stock-summary-report.php');
}
$item = $ledger['item'];
$opening = $ledger['opening'];
$rows = $ledger['rows'];
$locationMap = sr_location_type_map($companyId);
$displayType = sr_resolve_item_type($locationMap, $itemId, count($warehouseIds) === 1 ? $warehouseIds[0] : null, (string) $item['item_type']);

$pageTitle = 'Stock Ledger — ' . (string) $item['sku'];
$pageSubtitle = 'Every movement with running quantity, rate and value at inventory cost (' . strtoupper(str_replace('_', ' ', (string) $item['valuation_method'])) . ').';
$pageHero = ['icon' => 'inventory'];
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card" aria-label="Stock ledger">
    <div class="mbw-card-head">
        <h2><?= icon('inventory') ?>Stock Ledger — <?= e($item['sku'] . ' ' . $item['name']) ?>
            <span class="mbw-pill tone-blue"><?= e(sr_item_type_labels()[$displayType] ?? ucfirst($displayType)) ?></span></h2>
        <div class="mbw-card-tools">
            <span class="mbw-pill tone-blue"><?= e((string) $fiscalYear['label']) ?> · <?= e($from) ?> → <?= e($to) ?></span>
            <a class="mbw-view-all" href="<?= e($backUrl) ?>">&#8592; Back to Stock Summary (filters kept)</a>
        </div>
    </div>
    <p style="margin:0 0 10px;color:var(--mbw-muted);font-size:12.5px">
        Unit <?= e((string) $item['unit']) ?> · Valuation <?= e(strtoupper(str_replace('_', ' ', (string) $item['valuation_method']))) ?>
        <?= $warehouseIds !== [] ? ' · Location-filtered (' . count($warehouseIds) . ')' : ' · All locations' ?>
        · Running value is company-level inventory cost.
    </p>
    <div style="overflow-x:auto;max-height:72vh;overflow-y:auto">
    <table class="ssr-table" style="min-width:1250px">
        <thead>
            <tr class="ssr-h2">
                <th>Date</th><th>Voucher</th><th>Ref</th><th>Type</th><th>Location</th>
                <th class="is-numeric">In Qty</th><th class="is-numeric">In Rate</th><th class="is-numeric">In Amount</th>
                <th class="is-numeric">Out Qty</th><th class="is-numeric">Out Rate (cost)</th><th class="is-numeric">Out Amount (cost)</th>
                <th class="is-numeric">Running Qty</th><th class="is-numeric">Running Rate</th><th class="is-numeric">Running Value</th>
                <th>Posted</th>
            </tr>
        </thead>
        <tbody>
            <tr style="font-weight:600;background:var(--mbw-soft,#eef5f0)">
                <td colspan="11">Opening balance as at <?= e($from) ?> (from all movements before this date)</td>
                <td class="is-numeric"><?= e(number_format((float) $opening['qty'], 3)) ?></td>
                <td class="is-numeric"><?= e(number_format((float) $opening['rate'], 2)) ?></td>
                <td class="is-numeric"><?= e(number_format((float) $opening['value'], 2)) ?></td>
                <td></td>
            </tr>
            <?php if ($rows === []): ?>
                <tr><td colspan="15" style="text-align:center;color:var(--mbw-muted);padding:20px">No movements between <?= e($from) ?> and <?= e($to) ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr<?= $r['is_location_only'] ? ' style="opacity:.75"' : '' ?>>
                    <td><?= e(app_date($r['date'])) ?></td>
                    <td><?= $r['voucher_no'] !== '' ? e($r['voucher_no']) : '<span style="color:var(--mbw-muted)">stock-only</span>' ?></td>
                    <td><?= e($r['ref_no'] ?: '–') ?></td>
                    <td><?= e(ucfirst(str_replace('_', ' ', $r['type']))) ?><?= $r['is_location_only'] ? ' <small style="color:var(--mbw-muted)">(no GL)</small>' : '' ?></td>
                    <td><?= e($r['warehouse'] ?: '–') ?><?= $r['to_warehouse'] !== '' ? ' → ' . e($r['to_warehouse']) : '' ?></td>
                    <td class="is-numeric"><?= $r['in_qty'] > 0 ? e(number_format($r['in_qty'], 3)) : '–' ?></td>
                    <td class="is-numeric"><?= $r['in_qty'] > 0 ? e(number_format($r['in_rate'], 2)) : '–' ?></td>
                    <td class="is-numeric"><?= $r['in_qty'] > 0 && !$r['is_location_only'] ? e(number_format($r['in_amount'], 2)) : '–' ?></td>
                    <td class="is-numeric"><?= $r['out_qty'] > 0 ? e(number_format($r['out_qty'], 3)) : '–' ?></td>
                    <td class="is-numeric"><?= $r['out_qty'] > 0 && !$r['is_location_only'] ? e(number_format($r['out_rate'], 2)) : '–' ?></td>
                    <td class="is-numeric"><?= $r['out_qty'] > 0 && !$r['is_location_only'] ? e(number_format($r['out_amount'], 2)) : '–' ?></td>
                    <td class="is-numeric<?= $r['running_qty'] < 0 ? ' ssr-neg' : '' ?>"><?= e(number_format($r['running_qty'], 3)) ?></td>
                    <td class="is-numeric"><?= e(number_format($r['running_rate'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format($r['running_value'], 2)) ?></td>
                    <td><?= $r['voucher_no'] !== ''
                        ? '<span class="mbw-pill ' . ($r['voucher_status'] === 'posted' ? 'tone-green' : 'tone-amber') . '">' . e(ucfirst($r['voucher_status'] ?: 'draft')) . '</span>'
                        : '<span class="mbw-pill tone-amber" title="Stock recorded without a GL voucher (mapping was missing at entry time)">stock-only</span>' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12px">Out rate/amount is the replayed inventory cost (never selling price). Location-only transfers relocate quantity without touching cost layers or the GL.</p>
</section>
<style>
.ssr-table { border-collapse: separate; border-spacing: 0; font-size: 12.5px; }
.ssr-table th, .ssr-table td { padding: 6px 8px; border-bottom: 1px solid var(--mbw-line, rgba(0,0,0,.1)); background: var(--mbw-card, #fff); }
.ssr-table thead th { position: sticky; top: 0; z-index: 5; background: var(--mbw-soft, #eef5f0); color: var(--mbw-ink, #12261f); }
.ssr-table .is-numeric { text-align: right; font-variant-numeric: tabular-nums; }
.ssr-neg { color: #c62828; font-weight: 600; }
</style>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
