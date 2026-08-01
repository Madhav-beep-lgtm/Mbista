<?php
declare(strict_types=1);

/**
 * The assign sheet, as a sheet.
 *
 * A counter handing out five pieces on a Sunday morning types five rows and
 * saves once. That is what the spreadsheet this was drawn from does, and a
 * column of single-record forms does not: it makes five saves out of one act.
 *
 * The columns ARE the sheet's columns, in the sheet's order, and the customer
 * grid carries the two the showroom grid has no answer for. It scrolls
 * sideways rather than wrapping, because a row read across is the whole point.
 *
 * Expects: $kind, $isCustomer, $karigars, $purities, $orderPayload, $stockItems,
 *          $costCentres-free — everything else comes off the page.
 */
?>
<?php // Prefilled when somebody pressed Assign on an order: that order is
      // already picked on row one, and the rest of the grid is blank. ?>
<div class="vch-grid jw-assign-grid" data-grid data-min-rows="3"
     data-prefill="<?= e(json_encode($gridPrefill ?? [], JSON_UNESCAPED_SLASHES)) ?>">
    <div style="overflow-x:auto">
        <table class="frm-entries vch-table">
            <thead>
                <tr>
                    <th style="width:36px">SN</th>
                    <th style="min-width:170px">Kaligadh name <em>*</em></th>
                    <?php if ($isCustomer): ?>
                        <th style="min-width:150px">Order number <em>*</em></th>
                        <th style="min-width:150px">Customer name</th>
                    <?php endif; ?>
                    <th style="min-width:130px">Size / design</th>
                    <th style="min-width:200px">Expected ornament <em>*</em></th>
                    <th style="min-width:110px">Category</th>
                    <th class="is-numeric" style="min-width:110px">Gross weight <em>*</em></th>
                    <th class="is-numeric" style="min-width:105px">Stone / diamond</th>
                    <th class="is-numeric" style="min-width:105px">Net weight</th>
                    <th style="min-width:130px">Purity <em>*</em></th>
                    <th style="min-width:150px">Making charge</th>
                    <th style="min-width:145px">Assigned date <em>*</em></th>
                    <th style="min-width:145px">Expected delivery</th>
                    <th style="min-width:170px">Description</th>
                    <th style="width:40px"></th>
                </tr>
            </thead>
            <tbody data-rows></tbody>
        </table>
    </div>
    <div class="frm-entries-foot">
        <button type="button" class="button soft" data-add-row>＋ Add row</button>
        <div class="frm-entry-totals">
            <span class="mbw-pill tone-gray" id="jw-assign-count">No rows filled</span>
        </div>
    </div>

    <template data-row-template>
        <tr>
            <td data-sn>1</td>
            <td>
                <select name="karigar_id[]" data-field="karigar_id">
                    <option value="">Select kaligad</option>
                    <?php foreach ($karigars as $k): ?>
                        <option value="<?= (int) $k['id'] ?>"><?= e($k['code'] . ' — ' . $k['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <?php if ($isCustomer): ?>
                <td>
                    <select name="order_id[]" data-field="order_id" class="jw-order">
                        <option value="">Select order</option>
                        <?php foreach ($orderPayload as $o): ?>
                            <option value="<?= (int) $o['id'] ?>"><?= e((string) $o['order_no']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" class="jw-customer" value="" placeholder="From the order" disabled></td>
            <?php endif; ?>
            <td><input type="text" name="size_design[]" data-field="size_design" class="jw-size" maxlength="120"
                       placeholder="<?= $isCustomer ? 'From the order' : 'Ring 14' ?>" <?= $isCustomer ? 'readonly' : '' ?>></td>
            <td>
                <?php if ($isCustomer): ?>
                    <select name="order_line_id[]" data-field="order_line_id" class="jw-line">
                        <option value="">Pick the order first</option>
                    </select>
                <?php else: ?>
                    <select name="item_id[]" data-field="item_id" class="jw-item">
                        <option value="">Select finished stock item</option>
                        <?php foreach ($stockItems as $it): ?>
                            <option value="<?= (int) $it['id'] ?>" data-name="<?= e((string) $it['name']) ?>"><?= e($it['name'] . ' (' . $it['sku'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="expected_ornament[]" data-field="expected_ornament" class="jw-ornament">
                <?php endif; ?>
            </td>
            <td>
                <select name="category[]" data-field="category">
                    <?php foreach (jewellery_assign_categories() as $catKey => $catLabel): ?>
                        <option value="<?= e($catKey) ?>"><?= e($catLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="is-numeric"><input type="number" name="expected_gross_weight[]" data-field="expected_gross_weight"
                    class="frm-num jw-gross" step="0.0001" min="0" placeholder="0.0000" <?= $isCustomer ? 'readonly' : '' ?>></td>
            <td class="is-numeric"><input type="number" name="expected_stone_weight[]" data-field="expected_stone_weight"
                    class="frm-num jw-stone" step="0.0001" min="0" placeholder="0.0000" <?= $isCustomer ? 'readonly' : '' ?>></td>
            <td class="is-numeric"><input type="number" class="frm-num jw-net" step="0.0001" placeholder="Gross − stone" disabled></td>
            <td>
                <?php if ($isCustomer): ?>
                    <input type="text" class="jw-purity-label" value="" placeholder="From the order" disabled>
                    <input type="hidden" name="purity_id[]" data-field="purity_id" class="jw-purity">
                <?php else: ?>
                    <select name="purity_id[]" data-field="purity_id">
                        <option value="">Select purity</option>
                        <?php foreach ($purities as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"><?= e($p['metal_code'] . ' · ' . $p['code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </td>
            <td>
                <select name="making_basis[]" data-field="making_basis" style="margin-bottom:4px">
                    <?php foreach (jewellery_assign_making_bases() as $basisKey => $basisLabel): ?>
                        <option value="<?= e($basisKey) ?>"><?= e($basisLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="making_rate[]" data-field="making_rate" class="frm-num" step="0.0001" min="0" placeholder="0.0000">
            </td>
            <td><input type="date" name="assigned_date[]" data-field="assigned_date" class="jw-assigned" value="<?= e($todayInFy) ?>"></td>
            <td><input type="date" name="expected_delivery[]" data-field="expected_delivery" class="jw-delivery"></td>
            <td><input type="text" name="description[]" data-field="description" maxlength="255" placeholder="Anything the kaligad should know"></td>
            <td><button type="button" class="frm-del" data-remove-row aria-label="Remove this row">&#128465;</button></td>
        </tr>
    </template>
</div>
