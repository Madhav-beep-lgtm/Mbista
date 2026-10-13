<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_staff_admin_or_client_books();
require_company_context();
require_permission('inventory', 'view');
$company = current_company();
$fy = current_fiscal_year();
$stmt = db()->prepare("SELECT t.*, i.sku, i.name AS item_name, i.unit, w.name AS source_name, d.name AS destination_name
    FROM inventory_transactions t JOIN inventory_items i ON i.id=t.item_id AND i.company_id=t.company_id
    LEFT JOIN warehouses w ON w.id=t.warehouse_id AND w.company_id=t.company_id
    LEFT JOIN warehouses d ON d.id=t.to_warehouse_id AND d.company_id=t.company_id
    WHERE t.id=? AND t.company_id=? AND t.fiscal_year_id=? AND t.qty_out>0 AND t.transaction_type IN ('warehouse_transfer','departmental_transfer')");
$stmt->execute([(int)($_GET['id'] ?? 0), (int)($company['id'] ?? 0), (int)($fy['id'] ?? 0)]);
$transfer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$transfer) { http_response_code(404); exit('Stock transfer not found in this company and financial year.'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Stock Transfer Voucher</title><link rel="stylesheet" href="<?= e(asset_url('assets/css/voucher-print.css')) ?>"></head><body>
<nav class="print-toolbar"><button type="button" onclick="window.print()">Print voucher</button></nav>
<main class="voucher-document">
    <header><h1><?= e($company['name']) ?></h1><p><?= nl2br(e((string)($company['address'] ?? ''))) ?></p><h2>Stock Transfer Voucher</h2></header>
    <section class="voucher-meta">
        <div><strong>Voucher no.:</strong> <?= e($transfer['ref_no'] ?: 'Legacy transfer #' . $transfer['id']) ?></div>
        <div><strong>Date:</strong> <?= e(app_date($transfer['transaction_date'])) ?></div>
        <div><strong>Financial year:</strong> <?= e((string)($fy['label'] ?? '')) ?></div>
        <div><strong>From:</strong> <?= e($transfer['source_name'] ?? '') ?></div>
        <div><strong>To:</strong> <?= e($transfer['destination_name'] ?? '') ?></div>
    </section>
    <table><thead><tr><th>Item</th><th>Unit</th><th class="amount">Quantity</th></tr></thead><tbody><tr><td><?= e($transfer['sku'] . ' - ' . $transfer['item_name']) ?></td><td><?= e($transfer['unit']) ?></td><td class="amount"><?= e(number_format((float)$transfer['qty_out'],3)) ?></td></tr></tbody></table>
    <section class="narration"><strong>Narration:</strong> <?= nl2br(e((string)($transfer['notes'] ?? ''))) ?></section>
    <footer class="signatures"><div><div class="signature-line"></div><strong>Maker / Prepared by</strong><p>Date: __________________</p></div><div><div class="signature-line"></div><strong>Checker / Approved by</strong><p>Date: __________________</p></div></footer>
</main></body></html>
