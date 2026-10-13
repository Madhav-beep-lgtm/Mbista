<?php
declare(strict_types=1);
require_once __DIR__ . '/../../voucher_types.php';
$pageTitle = 'Stock Transfer Voucher';
$pageHero = false;
$bodyClass = 'admin-layout accounting-module-page voucher-entry-page voucher-type-stock-transfer';
$spec = ['key'=>'stock_transfer'];
$number = 'STF-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
$transferRetry = $_SESSION['stock_transfer_retry'][$companyId . ':' . $fiscalYearId] ?? [];
unset($_SESSION['stock_transfer_retry'][$companyId . ':' . $fiscalYearId]);
$transferDate = max((string)$fiscalYear['start_date'], min((string)$fiscalYear['end_date'], date('Y-m-d')));
include __DIR__ . '/../partials/admin_header.php';
?>
<nav class="mbw-tabbar"><a class="mbw-tab" href="<?= e(url('admin/inventory-transfers.php')) ?>"><?= icon('journal') ?>Stock Transfer Register</a></nav>
<form method="post" action="<?= e(url('admin/voucher-form.php?type=stock_transfer')) ?>" class="voucher-entry-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="record_movement">
    <input type="hidden" name="context_fiscal_year_id" value="<?= $fiscalYearId ?>">
    <div class="frm-main">
        <section class="mbw-card frm-section vch-head-card"><div class="frm-grid vch-header-grid">
            <?php include __DIR__ . '/type_strip.php'; ?>
            <label>Voucher number<input name="ref_no" value="<?= e($number) ?>" readonly required></label>
            <label>Date<input type="date" name="transaction_date" value="<?= e($transferDate) ?>" min="<?= e($fiscalYear['start_date']) ?>" max="<?= e($fiscalYear['end_date']) ?>" required></label>
        </div></section>
        <section class="mbw-card frm-section"><div class="frm-grid">
            <label>Transfer type<select name="transaction_type"><option value="warehouse_transfer">Warehouse transfer</option><option value="departmental_transfer">Departmental transfer</option></select></label>
            <label>From warehouse / location<select class="js-searchable" name="warehouse_id" required><option value="">Select source</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int)$warehouse['id'] ?>"><?= e($warehouse['name']) ?></option><?php endforeach; ?></select></label>
            <label>To warehouse / location<select class="js-searchable" name="to_warehouse_id" required><option value="">Select destination</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int)$warehouse['id'] ?>"><?= e($warehouse['name']) ?></option><?php endforeach; ?></select></label>
        </div></section>
        <section class="mbw-card frm-section"><div class="mbw-tablewrap"><table class="frm-entries vch-table"><thead><tr><th>Item</th><th>Quantity</th></tr></thead><tbody><tr>
            <td><select name="item_id" class="js-searchable" aria-label="Transfer item" required><option value="">Select item</option><?php foreach ($items as $item): if ($item['status'] !== 'active') continue; ?><option value="<?= (int)$item['id'] ?>"><?= e($item['sku'] . ' - ' . $item['name'] . ' (' . $item['unit'] . ')') ?></option><?php endforeach; ?></select></td>
            <td><input type="number" name="quantity" aria-label="Transfer quantity" min="0.001" step="0.001" required></td>
        </tr></tbody></table></div></section>
        <section class="mbw-card frm-section vch-support-card"><label>Narration<textarea name="notes" rows="2" required></textarea></label></section>
        <div class="frm-footer mbw-card"><a class="button secondary" href="<?= e(url('admin/inventory-transfers.php')) ?>">Cancel</a><button class="button frm-submit" type="submit"><?= icon('save') ?>Post Stock Transfer</button></div>
    </div>
</form>
<script>
(() => {
    const retry = <?= json_encode($transferRetry, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const form = document.querySelector('.voucher-entry-form');
    Object.keys(retry).forEach(name => {
        const field = form.elements.namedItem(name);
        if (field && typeof retry[name] !== 'object') { field.value = retry[name]; field.dispatchEvent(new Event('change', {bubbles:true})); }
    });
})();
</script>
<?php include __DIR__ . '/../partials/admin_footer.php'; ?>
