<?php
declare(strict_types=1);
if (!$editAssignment || !$editState) { return; }
$fullEdit = $editState['level'] === 'full';
$readOnly = $editState['level'] === 'readonly';
?>
<section class="mbw-card jw-assignment-edit" id="assignment-edit">
    <div class="mbw-card-head">
        <div><span class="jw-assignment-kicker">Assignment <?= e((string) $editAssignment['assignment_no']) ?></span><h2>Edit Assignment</h2></div>
        <span class="mbw-pill tone-<?= $fullEdit ? 'green' : ($readOnly ? 'gray' : 'amber') ?>"><?= icon($readOnly ? 'lock' : 'edit') ?><?= e(ucfirst((string) $editState['level'])) ?> edit</span>
    </div>
    <?php if ($editState['level'] === 'limited'): ?>
        <div class="notice warning">Metal has already been issued. Kaligad and item changes are locked because stock movements and accounting entries are linked to this assignment.</div>
    <?php elseif ($readOnly): ?><div class="notice">This received or cancelled assignment is retained as read-only history.</div><?php endif; ?>
    <div class="jw-assignment-context">
        <span><strong>Status</strong><?= e(ucfirst((string) $editAssignment['status'])) ?></span>
        <?php if ($isCustomer): ?><span><strong>Order</strong><?= e((string) $editAssignment['order_no']) ?></span><span><strong>Customer</strong><?= e((string) $editAssignment['customer_name']) ?></span><?php endif; ?>
        <span><strong>Current kaligad</strong><?= e($editAssignment['karigar_code'] . ' — ' . $editAssignment['karigar_name']) ?></span>
        <span><strong>Assigned item</strong><?= e((string) ($editAssignment['expected_ornament'] ?: $editAssignment['item_name'])) ?></span>
    </div>
    <form method="post" class="jw-assignment-edit-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_assignment">
        <input type="hidden" name="assignment_id" value="<?= (int) $editAssignment['id'] ?>"><input type="hidden" name="assign_kind" value="<?= e($kind) ?>">
        <label>Kaligad<select name="karigar_id" <?= !$fullEdit ? 'disabled' : '' ?>><?php foreach ($karigars as $karigar): ?><option value="<?= (int) $karigar['id'] ?>" <?= (int) $karigar['id'] === (int) $editAssignment['karigar_id'] ? 'selected' : '' ?>><?= e($karigar['code'] . ' — ' . $karigar['name']) ?></option><?php endforeach; ?></select></label>
        <?php if ($isCustomer): ?>
            <label class="jw-span-2">Assigned item<select name="order_line_id" <?= !$fullEdit ? 'disabled' : '' ?>>
                <?php foreach ($editOrderLines as $line): $linked = (int) ($line['assignment_id'] ?? 0); if ($linked > 0 && $linked !== $editId) { continue; } ?>
                    <option value="<?= (int) $line['id'] ?>" <?= (int) $line['id'] === (int) $editAssignment['order_line_id'] ? 'selected' : '' ?>><?= e($line['item_name'] . ' (' . $line['item_code'] . ') — ' . number_format((float) $line['gross_weight'], 4) . ' ' . $line['unit_code']) ?></option>
                <?php endforeach; ?>
            </select></label>
        <?php else: ?>
            <label class="jw-span-2">Finished-stock item<select name="item_id" <?= !$fullEdit ? 'disabled' : '' ?>><?php foreach ($stockItems as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) $item['id'] === (int) $editAssignment['item_id'] ? 'selected' : '' ?>><?= e($item['name'] . ' (' . $item['sku'] . ')') ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <label>Category<select name="category" <?= !$fullEdit ? 'disabled' : '' ?>><?php foreach (jewellery_assign_categories() as $value => $label): ?><option value="<?= e($value) ?>" <?= $value === $editAssignment['category'] ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Size / design<input name="size_design" value="<?= e((string) $editAssignment['size_design']) ?>" <?= !$fullEdit || $isCustomer ? 'readonly' : '' ?>></label>
        <label class="jw-span-2">Expected ornament<input name="expected_ornament" value="<?= e((string) $editAssignment['expected_ornament']) ?>" <?= !$fullEdit || $isCustomer ? 'readonly' : '' ?>></label>
        <label>Expected gross weight<input type="number" step="0.0001" min="0" name="expected_gross_weight" value="<?= e((string) $editAssignment['expected_gross_weight']) ?>" <?= !$fullEdit || $isCustomer ? 'readonly' : '' ?>></label>
        <label>Stone / diamond weight<input type="number" step="0.0001" min="0" name="expected_stone_weight" value="<?= e((string) $editAssignment['expected_stone_weight']) ?>" <?= !$fullEdit || $isCustomer ? 'readonly' : '' ?>></label>
        <label>Purity<select name="purity_id" <?= !$fullEdit || $isCustomer ? 'disabled' : '' ?>><?php foreach ($purities as $purity): ?><option value="<?= (int) $purity['id'] ?>" <?= (int) $purity['id'] === (int) $editAssignment['purity_id'] ? 'selected' : '' ?>><?= e($purity['metal_code'] . ' · ' . $purity['code']) ?></option><?php endforeach; ?></select></label>
        <input type="hidden" name="unit_id" value="<?= (int) $editAssignment['unit_id'] ?>">
        <label>Making basis<select name="making_basis" <?= !$fullEdit ? 'disabled' : '' ?>><?php foreach (jewellery_assign_making_bases() as $value => $label): ?><option value="<?= e($value) ?>" <?= $value === $editAssignment['making_basis'] ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Making rate<input type="number" step="0.0001" min="0" name="making_rate" value="<?= e((string) $editAssignment['making_rate']) ?>" <?= !$fullEdit ? 'readonly' : '' ?>></label>
        <label>Assigned date<input type="date" name="assigned_date" value="<?= e((string) $editAssignment['issue_date']) ?>" <?= !$fullEdit ? 'readonly' : '' ?>></label>
        <label>Expected delivery<input type="date" name="expected_delivery" value="<?= e((string) $editAssignment['expected_return_date']) ?>" <?= $readOnly ? 'readonly' : '' ?>></label>
        <label class="jw-span-all">Description<textarea name="description" rows="3" <?= $readOnly ? 'readonly' : '' ?>><?= e((string) $editAssignment['notes']) ?></textarea></label>
        <div class="jw-assignment-edit-actions jw-span-all"><?php if (!$readOnly): ?><button class="button" type="submit"><?= icon('save') ?>Save Changes</button><?php endif; ?><a class="button secondary" href="<?= e(url('admin/jewellery-assign.php?kind=' . $kind)) ?>"><?= icon('close') ?>Cancel</a><?php if ($isCustomer && $hasAnotherOrderLine): ?><a class="button soft" href="<?= e(url('admin/jewellery-assign.php?kind=customer&order=' . (int) $editAssignment['order_id'] . '&karigar=' . (int) $editAssignment['karigar_id'] . '#jw-assign-form')) ?>"><?= icon('plus') ?>Add another item from this order</a><?php endif; ?></div>
    </form>
    <?php if ($fullEdit): ?><form method="post" class="jw-remove-assignment" data-confirm="Remove assignment <?= e((string) $editAssignment['assignment_no']) ?>? It will be cancelled and its order item released.">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove_assignment"><input type="hidden" name="assignment_id" value="<?= (int) $editAssignment['id'] ?>"><input type="hidden" name="assign_kind" value="<?= e($kind) ?>">
        <label>Removal reason<input name="reason" required maxlength="180" placeholder="Why is this assignment being removed?"></label><button class="button danger" type="submit"><?= icon('trash') ?>Remove assignment</button>
    </form><?php endif; ?>
</section>
