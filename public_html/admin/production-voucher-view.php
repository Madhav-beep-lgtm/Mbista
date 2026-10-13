<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/manufacturing_engine.php';
require_staff_admin_or_client_books();
require_company_context();
require_permission('inventory', 'view');
$company = current_company();
$fiscalYear = current_fiscal_year();
$stmt = db()->prepare('SELECT mo.*, i.name AS item_name, i.sku FROM manufacturing_orders mo JOIN inventory_items i ON i.id = mo.finished_item_id AND i.company_id = mo.company_id WHERE mo.id = ? AND mo.company_id = ? AND mo.fiscal_year_id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0), (int) ($company['id'] ?? 0), (int) ($fiscalYear['id'] ?? 0)]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) { http_response_code(404); exit('Production voucher not found in this company and financial year.'); }
$stmt = db()->prepare('SELECT mi.*, i.name, i.sku, i.unit FROM manufacturing_order_inputs mi JOIN inventory_items i ON i.id = mi.item_id AND i.company_id = ? WHERE mi.manufacturing_order_id = ?');
$stmt->execute([(int) $company['id'], (int) $order['id']]);
$materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
$meta = json_decode((string) ($order['notes'] ?? ''), true);
$costs = is_array($meta) ? ($meta['additional_costs'] ?? []) : [];
$materialCost = array_sum(array_map(static fn(array $row): float => (float) $row['quantity'] * (float) $row['rate'], $materials));
$cost = mfg_order_cost($materialCost, (float) $order['labour_cost'], (float) $order['overhead_absorbed'], (float) $order['byproduct_value'], (float) $order['abnormal_waste_cost'], (float) $order['quantity']);
$pageTitle = 'Production Voucher ' . $order['order_no'];
$bodyClass = 'admin-layout accounting-module-page voucher-entry-page';
$printProduction = ($_GET['print'] ?? '') === '1';
if (!$printProduction) include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<?php if ($printProduction): ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= e($pageTitle) ?></title><link rel="stylesheet" href="<?= e(asset_url('assets/css/voucher-print.css')) ?>"></head><body>
<nav class="print-toolbar"><button type="button" onclick="window.print()">Print voucher</button></nav>
<main class="voucher-document"><header><h1><?= e($company['name']) ?></h1><p><?= nl2br(e((string) ($company['address'] ?? ''))) ?></p><h2>Production Voucher</h2><p>Financial year: <?= e((string) ($fiscalYear['label'] ?? '')) ?></p></header>
<?php endif; ?>
<section class="mbw-card frm-section">
    <?php if (!$printProduction): ?><div class="actions"><a class="button secondary" href="<?= e(url('admin/manufacturing-orders.php')) ?>">Production Register</a><a class="button secondary" target="_blank" rel="noopener" href="<?= e(url('admin/production-voucher-view.php?id=' . (int) $order['id'] . '&print=1')) ?>">Print voucher</a></div><?php endif; ?>
    <p><?= e($order['order_no']) ?> · <?= e(app_date($order['completed_on'] ?? $order['started_on'])) ?> · <?= e(ucfirst($order['status'])) ?></p>
    <h3>Finished output</h3>
    <div class="mbw-tablewrap"><table class="frm-entries vch-table"><thead><tr><th>Item</th><th>Quantity</th><th>Unit cost</th><th>Total cost</th></tr></thead><tbody><tr><td><?= e($order['sku'] . ' - ' . $order['item_name']) ?></td><td><?= e(number_format((float) $order['quantity'], 3)) ?></td><td><?= e(number_format($cost['unit_cost'], 2)) ?></td><td><?= e(number_format($cost['inventoriable'], 2)) ?></td></tr></tbody></table></div>
    <h3>Materials consumed</h3>
    <div class="mbw-tablewrap"><table class="frm-entries vch-table"><thead><tr><th>Item</th><th>Unit</th><th>Quantity</th><th>Rate</th><th>Cost</th></tr></thead><tbody>
        <?php foreach ($materials as $material): ?><tr><td><?= e($material['sku'] . ' - ' . $material['name']) ?></td><td><?= e($material['unit']) ?></td><td><?= e(number_format((float) $material['quantity'], 3)) ?></td><td><?= e(number_format((float) $material['rate'], 2)) ?></td><td><?= e(number_format((float) $material['quantity'] * (float) $material['rate'], 2)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ($costs !== []): ?>
    <h3>Additional costs</h3>
    <div class="mbw-tablewrap"><table class="frm-entries vch-table"><thead><tr><th>Description</th><th>Treatment</th><th>Original reference</th><th>Amount</th></tr></thead><tbody>
        <?php foreach ($costs as $line): ?><tr><td><?= e($line['description']) ?></td><td><?= $line['mode'] === 'new' ? 'New cost' : 'Allocated — already recorded' ?></td><td><?= e($line['reference']) ?></td><td><?= e(number_format((float) $line['amount'], 2)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <p><?= e(is_array($meta) ? (string) ($meta['narration'] ?? '') : (string) ($order['notes'] ?? '')) ?></p>
    <?php if (user_can_do('accounting', 'view')):
        $journal = db()->prepare("SELECT id, voucher_no FROM vouchers WHERE company_id = ? AND source_type = 'manufacturing_order' AND source_id = ?");
        $journal->execute([(int) $company['id'], (int) $order['id']]);
        if ($costJournal = $journal->fetch(PDO::FETCH_ASSOC)): ?>
        <a data-voucher-preview href="<?= e(url('admin/voucher-view.php?id=' . (int) $costJournal['id'])) ?>"><?= e($costJournal['voucher_no']) ?></a>
    <?php endif; endif; ?>
</section>
<?php if ($printProduction): ?>
<footer class="signatures"><div><div class="signature-line"></div><strong>Maker / Prepared by</strong><p>Date: __________________</p></div><div><div class="signature-line"></div><strong>Checker / Approved by</strong><p>Date: __________________</p></div></footer>
</main></body></html>
<?php else: include __DIR__ . '/../../app/views/partials/admin_footer.php'; endif; ?>
