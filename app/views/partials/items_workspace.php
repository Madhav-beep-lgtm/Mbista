<?php
$bodyClass = 'admin-layout accounting-module-page items-creation-page';
include __DIR__ . '/admin_header.php';
$unitRows = inv_units_list($companyId, false);
$showUnits = ($_GET['section'] ?? '') === 'units';
$showTypes = ($_GET['section'] ?? '') === 'types';
$stockTypes = inv_stock_types($companyId);
?>
<section class="mbw-card items-creation-card">
    <div class="actions" style="margin-bottom:20px">
        <label>View<select id="items-view"><option value="item" <?= !$showUnits && !$showTypes ? 'selected' : '' ?>>Create item</option><option value="units" <?= $showUnits ? 'selected' : '' ?>>Units of measurement</option><option value="types" <?= $showTypes ? 'selected' : '' ?>>Stock types</option></select></label>
    </div>
    <div id="items-panel-item" <?= $showUnits || $showTypes ? 'hidden' : '' ?>>
        <h2><?= $editItem ? 'Edit item' : 'Create item' ?></h2>
        <?php include __DIR__ . '/item_creation_form.php'; ?>
    </div>
    <div id="items-panel-units" <?= !$showUnits ? 'hidden' : '' ?>>
        <h2>Create unit of measurement</h2>
        <form method="post" class="item-creation-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_item_unit">
            <div class="workspace-form-grid">
                <label>Unit name<input name="unit_name" maxlength="40" placeholder="e.g. Piece, kg, litre" required></label>
                <label>Base unit<select name="base_unit_id" id="unit-base"><option value="0">Standalone unit</option><?php foreach ($unitRows as $unit): ?><?php if ($unit['active'] && $unit['base_unit_id'] === $unit['id']): ?><option value="<?= $unit['id'] ?>"><?= e($unit['name']) ?></option><?php endif; ?><?php endforeach; ?></select></label>
                <label>Quantity in base unit<input name="factor" type="number" min="0.000001" max="999999999999" step="0.000001" value="1" id="unit-factor"></label>
            </div>
            <p class="muted">For a box containing 12 pieces, select Piece as the base unit and enter 12. Standalone units use 1.</p>
            <button class="button" type="submit">Create unit</button>
        </form>
        <h3>Existing units</h3>
        <table><thead><tr><th>Name</th><th>Base unit</th><th>Quantity in base unit</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($unitRows as $unit): ?><tr><td><?= e($unit['name']) ?></td><td><?= e($unitRows[$unit['base_unit_id']]['name'] ?? $unit['name']) ?></td><td><?= e((string) $unit['factor']) ?></td><td><?= $unit['active'] ? 'Active' : 'Inactive' ?></td></tr><?php endforeach; ?>
        <?php if (!$unitRows): ?><tr><td colspan="4">No units created yet.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
    <div id="items-panel-types" <?= !$showTypes ? 'hidden' : '' ?>>
        <form method="post" class="actions">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_stock_type">
            <label>Stock type name<input name="stock_type_name" maxlength="100" required></label>
            <button class="button" type="submit">Create stock type</button>
        </form>
        <table><thead><tr><th>Stock type</th></tr></thead><tbody>
            <?php foreach ($stockTypes as $typeName): ?><tr><td><?= e($typeName) ?></td></tr><?php endforeach; ?>
            <?php if (!$stockTypes): ?><tr><td>No stock types created yet.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const view = document.getElementById('items-view');
    view.addEventListener('change', function () {
        ['item', 'units', 'types'].forEach(key => document.getElementById('items-panel-' + key).hidden = key !== view.value);
    });
    const base = document.getElementById('unit-base'), factor = document.getElementById('unit-factor');
    function syncUnit() { factor.readOnly = base.value === '0'; if (factor.readOnly) factor.value = '1'; }
    base.addEventListener('change', syncUnit); syncUnit();
    const source = document.getElementById('inv-ledger-options');
    if (source) document.querySelectorAll('[data-fill-from="inv-ledger-options"]').forEach(select => {
        const selected = select.value;
        const placeholder = select.options[0].cloneNode(true);
        select.replaceChildren(placeholder, source.content.cloneNode(true));
        select.value = selected;
        select.removeAttribute('data-fill-from');
        select.dispatchEvent(new Event('change', {bubbles: true}));
    });
});
</script>
<?php include __DIR__ . '/admin_footer.php'; ?>
