<?php
require_permission('inventory', 'view');
$transferPage = max(1, (int) ($_GET['page'] ?? 1));
$transferWhere = "t.company_id = ? AND t.fiscal_year_id = ? AND t.transaction_type IN ('warehouse_transfer','departmental_transfer') AND t.qty_out > 0";
$countTransfers = db()->prepare('SELECT COUNT(*) FROM inventory_transactions t WHERE ' . $transferWhere);
$countTransfers->execute([$companyId, $fiscalYearId]);
$transferPages = max(1, (int) ceil((int)$countTransfers->fetchColumn() / 50));
$transferPage = min($transferPage, $transferPages);
$transferStmt = db()->prepare('SELECT t.*, i.sku, i.name AS item_name, i.unit, w.name AS source_name, d.name AS destination_name
    FROM inventory_transactions t JOIN inventory_items i ON i.id=t.item_id AND i.company_id=t.company_id
    LEFT JOIN warehouses w ON w.id=t.warehouse_id AND w.company_id=t.company_id
    LEFT JOIN warehouses d ON d.id=t.to_warehouse_id AND d.company_id=t.company_id
    WHERE ' . $transferWhere . ' ORDER BY t.transaction_date DESC, t.id DESC LIMIT 50 OFFSET ' . (($transferPage - 1) * 50));
$transferStmt->execute([$companyId, $fiscalYearId]);
$transferRows = $transferStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="mbw-tablewrap"><table class="frm-entries vch-table"><thead><tr><th>Date</th><th>Transfer voucher</th><th>Item</th><th>From</th><th>To</th><th>Quantity</th><th>Unit</th></tr></thead><tbody>
<?php if ($transferRows === []): ?><tr><td colspan="7">No stock transfers in this financial year.</td></tr><?php endif; ?>
<?php foreach ($transferRows as $transfer): ?><tr>
    <td><?= e(app_date($transfer['transaction_date'])) ?></td>
    <td><a target="_blank" rel="noopener" href="<?= e(url('admin/stock-transfer-view.php?id=' . (int)$transfer['id'])) ?>"><?= e($transfer['ref_no'] ?: 'Legacy transfer #' . $transfer['id']) ?></a></td>
    <td><?= e($transfer['sku'] . ' - ' . $transfer['item_name']) ?></td><td><?= e($transfer['source_name'] ?? '') ?></td><td><?= e($transfer['destination_name'] ?? '') ?></td><td class="is-numeric"><?= e(number_format((float)$transfer['qty_out'],3)) ?></td><td><?= e($transfer['unit']) ?></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php if ($transferPages > 1): ?><div class="actions">
<?php if ($transferPage > 1): ?><a class="button secondary" href="<?= e(url(inv_back_url('page=' . ($transferPage - 1)))) ?>">Previous</a><?php endif; ?>
<span><?= $transferPage ?> / <?= $transferPages ?></span>
<?php if ($transferPage < $transferPages): ?><a class="button secondary" href="<?= e(url(inv_back_url('page=' . ($transferPage + 1)))) ?>">Next</a><?php endif; ?>
</div><?php endif; ?>
