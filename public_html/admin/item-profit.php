<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/item_profit_report.php';
require_staff_admin_or_client_books();
require_company_context();
require_permission('inventory', 'view');
$company = current_company(); $year = current_fiscal_year();
$companyId = (int)$company['id'];
if (!$year || (int)$year['company_id'] !== $companyId) { flash('error', 'Select a fiscal year.'); redirect('admin/accounting-dashboard.php'); }
$from = (string)($_GET['from'] ?? $year['start_date']);
$to = (string)($_GET['to'] ?? $year['end_date']);
if (inventory_valid_date($from) !== $from || inventory_valid_date($to) !== $to || $from > $to
    || $from < $year['start_date'] || $to > $year['end_date']) {
    flash('error', 'Choose a valid date range within the active fiscal year.'); redirect('admin/item-profit.php');
}
$report = item_profit_report($companyId, $from, $to);
$periodic = inv_accounting_method($companyId) === 'periodic';
$trading = $periodic ? inv_periodic_trading_figures($companyId, $from, $to) : null;
$pageTitle = 'Item Profitability'; $pageHero = false;
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
$money = static fn($value) => $value === null ? 'Not available' : number_format((float)$value, 2);
?>
<section class="mbw-card" style="padding:20px">
<h2>Item profitability</h2>
<form method="get" class="actions">
    <label>From date<input type="date" name="from" value="<?= e($from) ?>" required></label>
    <label>To date<input type="date" name="to" value="<?= e($to) ?>" required></label>
    <button class="button" type="submit">Apply</button><a class="button secondary" href="<?= e(url('admin/item-profit.php')) ?>">Clear</a>
</form>
<p>Posted item sales vouchers (including credit notes) and jewellery sales. Revenue excludes tax; jewellery discounts and charges are allocated to items. Costs come from the stock records, not per-sale GL entries. Other invoice/POS sources and unallocated ledger adjustments are outside this report.</p>
<?php if (!$periodic): ?><div class="notice error">These existing books still use perpetual accounting. Historical conversion must be reviewed before switching to periodic; this read-only report does not change the books.</div><?php endif; ?>
<div style="overflow-x:auto"><table class="mbw-grid-table">
<thead><tr><th>Code</th><th>Item</th><th>Unit</th><th>Net quantity sold</th><th>Net sales</th><th>Cost of items sold</th><th>Gross profit</th><th>Margin %</th></tr></thead><tbody>
<?php foreach ($report['rows'] as $row): ?><tr>
<td><?= e($row['code']) ?></td><td><a href="<?= e(url('admin/stock-ledger.php?item_id=' . $row['id'])) ?>"><?= e($row['name']) ?></a><?= $row['missing'] ? ' — stock cost incomplete' : '' ?></td><td><?= e($row['unit']) ?></td><td><?= number_format($row['quantity'], 3) ?></td>
<td><?= $money($row['revenue']) ?></td><td><?= $row['missing'] ? 'Not available' : $money($row['cost']) ?></td><td><?= $money($row['profit']) ?></td><td><?= $money($row['margin']) ?></td></tr><?php endforeach; ?>
<?php if (!$report['rows']): ?><tr><td colspan="8">No posted item sales in this period.</td></tr><?php endif; ?>
</tbody><tfoot><tr><th colspan="4">Total (quantities are not combined across units)</th><th><?= $money($report['totals']['revenue']) ?></th><th><?= $report['totals']['missing'] ? 'Not available' : $money($report['totals']['cost']) ?></th><th><?= $money($report['totals']['profit']) ?></th><th><?= $money($report['totals']['margin']) ?></th></tr></tfoot></table></div>
<?php if ($trading && $trading['available']): ?>
<h3>Reconciliation to periodic trading account</h3>
<p>Periodic cost of sales: <?= $money($trading['cogs']) ?>. Item-report cost: <?= $report['totals']['missing'] ? 'Not available' : $money($report['totals']['cost']) ?>.
Unreconciled difference: <?= $report['totals']['missing'] ? 'Not available' : $money($trading['cogs'] - $report['totals']['cost']) ?>.</p>
<p>Review closing-stock adjustments, consumption, damage, direct costs, sales outside this report and unallocated postings. The difference is not allocated to items automatically. Before closing stock is posted, the ledger-based trading figure is provisional.</p>
<?php endif; ?>
<p>Gross profit excludes overhead allocation and is not net profit. Generic inventory uses FIFO or moving-average cost replay; specific-identification costs use FIFO fallback where no unit identity exists. Jewellery uses its recorded item cost.</p>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
