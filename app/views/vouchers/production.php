<?php
declare(strict_types=1);
require_once __DIR__ . '/../../voucher_types.php';
$pageTitle = 'Production Voucher';
$pageHero = false;
$bodyClass = 'admin-layout accounting-module-page voucher-entry-page voucher-type-production';
$spec = ['key'=>'production'];
$productionNumber = 'PRD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
$productionDateDefault = max((string)$fiscalYear['start_date'], min((string)$fiscalYear['end_date'], date('Y-m-d')));
$productionBlocked = inv_accounting_method($companyId) !== 'periodic'
    ? 'Production vouchers require periodic inventory accounting.'
    : (staff_accountant_forces_approval() ? 'Production requires an authorised posting user; approval-only entry is not available here.' : '');
$productionRetry = $_SESSION['production_retry'][$companyId . ':' . $fiscalYearId] ?? [];
unset($_SESSION['production_retry'][$companyId . ':' . $fiscalYearId]);
include __DIR__ . '/../partials/admin_header.php';
?>
<nav class="mbw-tabbar" aria-label="Voucher workspace">
    <a class="mbw-tab" href="<?= e(url('admin/manufacturing-orders.php')) ?>"><?= icon('journal') ?>Production Register</a>
    <a class="mbw-tab" href="<?= e(url('admin/accounting.php')) ?>"><?= icon('journal') ?>Voucher Register</a>
</nav>
<?php if ($productionBlocked !== ''): ?><div class="notice error"><?= e($productionBlocked) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('admin/voucher-form.php?type=production')) ?>" class="voucher-entry-form" id="production-voucher">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_manufacturing_order">
    <input type="hidden" name="context_fiscal_year_id" value="<?= $fiscalYearId ?>">
    <div class="frm-main">
        <section class="mbw-card frm-section vch-head-card">
            <div class="frm-grid vch-header-grid">
                <?php include __DIR__ . '/type_strip.php'; ?>
                <label>Voucher number<input name="order_no" value="<?= e($productionNumber) ?>" maxlength="60" readonly required></label>
                <label>Date<input type="date" name="voucher_date" value="<?= e($productionDateDefault) ?>" min="<?= e($fiscalYear['start_date']) ?>" max="<?= e($fiscalYear['end_date']) ?>" required></label>
            </div>
        </section>
        <section class="mbw-card frm-section">
            <h3>Finished output</h3>
            <div class="frm-grid">
                <label>Item<select name="finished_item_id" class="js-searchable" required><option value="">Select finished item</option><?php foreach ($items as $item): if ($item['status'] !== 'active') continue; ?><option value="<?= (int) $item['id'] ?>"><?= e($item['sku'] . ' - ' . $item['name']) ?></option><?php endforeach; ?></select></label>
                <label>Quantity<input type="number" name="quantity" min="0.001" step="0.001" required></label>
            </div>
        </section>
        <section class="mbw-card frm-section">
            <h3>Materials consumed</h3>
            <div class="mbw-tablewrap"><table class="frm-entries vch-table"><thead><tr><th>Item</th><th>Quantity</th><th></th></tr></thead><tbody id="production-materials"></tbody></table></div>
            <button type="button" class="button secondary" data-add-production="material">+ Add material</button>
        </section>
        <section class="mbw-card frm-section">
            <h3>Additional costs</h3>
            <div id="production-costs"></div>
            <button type="button" class="button secondary" data-add-production="cost">+ Add cost</button>
        </section>
        <section class="mbw-card frm-section vch-support-card">
            <label>Narration<textarea name="notes" rows="2" required></textarea></label>
        </section>
        <div class="frm-footer mbw-card">
            <a class="button secondary" href="<?= e(url('admin/manufacturing-orders.php')) ?>">Cancel</a>
            <button class="button frm-submit" type="submit" <?= $productionBlocked !== '' ? 'disabled' : '' ?>><?= icon('save') ?>Post Production</button>
        </div>
    </div>
</form>
<template id="production-material-template"><tr>
    <td><select name="input_item_id[]" aria-label="Material item" required><option value="">Select material</option><?php foreach ($items as $item): if ($item['status'] !== 'active') continue; ?><option value="<?= (int) $item['id'] ?>"><?= e($item['sku'] . ' - ' . $item['name'] . ' (' . $item['unit'] . ')') ?></option><?php endforeach; ?></select></td>
    <td><input type="number" name="input_quantity[]" aria-label="Material quantity" min="0.001" step="0.001" required></td>
    <td><button type="button" class="button secondary" data-remove-production aria-label="Remove material"><?= icon('trash') ?></button></td>
</tr></template>
<template id="production-cost-template"><fieldset class="production-cost-row">
    <legend>Cost</legend><div class="frm-grid">
        <label>Description<input name="cost_description[]" maxlength="120" required></label>
        <label>Amount<input name="cost_amount[]" type="number" min="0.01" step="0.01" required></label>
        <label>Treatment<select name="cost_mode[]"><option value="new">New cost — post to accounts</option><option value="allocated">Already recorded — allocate only</option></select></label>
        <label data-new-cost>Expense ledger<select class="js-searchable" name="cost_expense[]"><option value="">Select expense</option><?php foreach ($ledgers as $ledger): if ($ledger['type'] !== 'expense') continue; ?><option value="<?= (int) $ledger['id'] ?>"><?= e($ledger['code'] . ' - ' . $ledger['name']) ?></option><?php endforeach; ?></select></label>
        <label data-new-cost>Paid from / Payable ledger<select class="js-searchable" name="cost_credit[]"><option value="">Select account</option><?php foreach ($ledgers as $ledger): if (!in_array($ledger['type'], ['asset','liability'], true)) continue; ?><option value="<?= (int) $ledger['id'] ?>"><?= e($ledger['code'] . ' - ' . $ledger['name']) ?></option><?php endforeach; ?></select></label>
        <label data-new-cost>Cost centre<input name="cost_centre[]" maxlength="80"></label>
        <label data-allocated-cost hidden>Original voucher reference<input name="cost_reference[]" maxlength="120"></label>
    </div><button type="button" class="button secondary" data-remove-production>Remove cost</button>
</fieldset></template>
<script>
(() => {
    const form = document.getElementById('production-voucher');
    function sync(row) {
        const allocated = row.querySelector('[name="cost_mode[]"]').value === 'allocated';
        row.querySelectorAll('[data-new-cost], [data-allocated-cost]').forEach(label => {
            label.hidden = label.hasAttribute('data-new-cost') ? allocated : !allocated;
            label.querySelector('input, select').required = !label.hidden;
        });
    }
    function add(kind) {
        const target = document.getElementById(kind === 'material' ? 'production-materials' : 'production-costs');
        target.append(document.getElementById('production-' + kind + '-template').content.cloneNode(true));
        if (kind === 'cost') sync(target.lastElementChild);
    }
    form.addEventListener('click', event => {
        const addButton = event.target.closest('[data-add-production]');
        if (addButton) add(addButton.dataset.addProduction);
        const removeButton = event.target.closest('[data-remove-production]');
        if (removeButton) removeButton.closest('tr, fieldset').remove();
    });
    form.addEventListener('change', event => { if (event.target.name === 'cost_mode[]') sync(event.target.closest('fieldset')); });
    const retry = <?= json_encode($productionRetry, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    ['order_no', 'voucher_date', 'finished_item_id', 'quantity', 'notes'].forEach(name => {
        if (retry[name] !== undefined) form.elements.namedItem(name).value = retry[name];
    });
    const materialCount = Math.max(1, (retry.input_item_id || []).length);
    for (let i = 0; i < materialCount; i++) add('material');
    for (let i = 0; i < (retry.cost_amount || []).length; i++) add('cost');
    ['input_item_id','input_quantity','cost_description','cost_amount','cost_mode','cost_expense','cost_credit','cost_centre','cost_reference'].forEach(name => {
        form.querySelectorAll('[name="' + name + '[]"]').forEach((field, i) => { if (retry[name] && retry[name][i] !== undefined) field.value = retry[name][i]; });
    });
    form.querySelectorAll('.production-cost-row').forEach(sync);
})();
</script>
<?php include __DIR__ . '/../partials/admin_footer.php'; ?>
