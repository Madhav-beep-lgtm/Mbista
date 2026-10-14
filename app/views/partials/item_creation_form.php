        <form method="post" class="item-creation-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
            <input type="hidden" name="action" value="save_item">
            <fieldset><legend>Item details</legend><div class="workspace-form-grid item-details-grid">
            <input type="hidden" name="item_id" value="<?= e((int) ($editItem['id'] ?? 0)) ?>">
            <label>Code<input type="text" name="sku" maxlength="<?= inv_item_code_max() ?>"
                   pattern="[A-Za-z0-9][A-Za-z0-9.\-]*"
                   value="<?= e($editItem['sku'] ?? inv_next_item_code($companyId)) ?>" required></label>
            <label class="item-name-field">Name<input type="text" name="name" maxlength="<?= inv_item_name_max() ?>" value="<?= e($editItem['name'] ?? '') ?>" required></label>
            <label>Stock type<select name="stock_type_id" required><option value="">Select stock type</option><?php foreach (inv_stock_types($companyId) as $typeId => $typeName): ?><option value="<?= (int)$typeId ?>" <?= (int)($editItem['stock_type_id'] ?? 0) === (int)$typeId ? 'selected' : '' ?>><?= e($typeName) ?></option><?php endforeach; ?></select></label>
            <label>Valuation method
                <select name="valuation_method">
                    <?php $vm = (string) ($editItem['valuation_method'] ?? 'weighted_average'); ?>
                    <option value="weighted_average" <?= $vm === 'weighted_average' ? 'selected' : '' ?>>Weighted Average</option>
                    <option value="fifo" <?= $vm === 'fifo' ? 'selected' : '' ?>>FIFO</option>
                    <option value="specific" <?= $vm === 'specific' ? 'selected' : '' ?>>Specific Identification</option>
                </select>
            </label>
            <?php // The categories this company already uses, offered rather than
                  // remembered. They were on the page all along -- the item list
                  // filters by them -- but the one form that CREATES a category
                  // asked for it as free text, so "Raw Materials", "Raw material"
                  // and "raw materials" became three groups nobody meant to have,
                  // and every report that groups by category showed all three.
                  //
                  // A list, not a dropdown, because this is the form where a
                  // genuinely new category is born: it suggests what exists and
                  // still accepts what does not. ?>
            <label>Category
                <select name="category_id" required>
                    <option value="">—</option>
                    <?php foreach (inv_categories_list($companyId) as $categoryRow): ?>
                        <option value="<?= (int) $categoryRow['id'] ?>" <?= (int) ($editItem['category_id'] ?? 0) === $categoryRow['id'] ? 'selected' : '' ?>><?= e($categoryRow['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Status<select name="status"><option value="active" <?= ($editItem['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= ($editItem['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label>
            <label>Unit
                <select name="unit_id" required>
                    <option value="">—</option>
                    <?php foreach (inv_units_list($companyId) as $unitRow): ?>
                        <option value="<?= (int) $unitRow['id'] ?>" <?= (int) ($editItem['unit_id'] ?? 0) === $unitRow['id'] ? 'selected' : '' ?>><?= e($unitRow['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            </div></fieldset><fieldset><legend>Rates and stock settings</legend><div class="workspace-form-grid item-rates-grid">
            <label>HS code<input type="text" name="hs_code" value="<?= e($editItem['hs_code'] ?? '') ?>"></label>
            <label>Default selling rate<input type="number" step="0.01" min="0" name="sales_rate" value="<?= e($editItem['sales_rate'] ?? '0.00') ?>"></label>
            <label>Purchase rate<input type="number" step="0.01" name="purchase_rate" value="<?= e($editItem['purchase_rate'] ?? '0.00') ?>"></label>
            <label>Opening qty<input type="number" step="0.001" name="opening_qty" value="<?= e($editItem['opening_qty'] ?? '0.000') ?>"></label>
            <label>Opening value 
                <input type="number" step="0.01" min="0" name="opening_amount" value="<?= e($editItem['opening_amount'] ?? '0.00') ?>" placeholder="qty × cost at opening"></label>
            <label>Reorder level<input type="number" step="0.001" name="reorder_level" value="<?= e($editItem['reorder_level'] ?? '0.000') ?>"></label>
            </div></fieldset><fieldset><legend>Ledger mapping</legend><div class="workspace-form-grid item-ledgers-grid">
            <?php
            // Item creation exposes Purchase and Sales only. Historical
            // lifecycle mappings remain stored, not editable in this form.
            $itemMapCurrent = $editItem ? inventory_item_ledger_map($companyId, (int) $editItem['id']) : [];
            // The ledger list, built once for the page.
            //
            // Eighteen selects on this screen each carried their own copy of a
            // hundred and eighty ledgers — 335 KB of the same list, repeated.
            // One copy goes into the template below and each select takes it on
            // load; a select that already has a choice keeps that choice inline
            // so its value is right before the script has run.
            if (!isset($invLedgerOptionsHtml)) {
                $invLedgerOptionsHtml = '';
                $invLedgerOptionById = [];
                foreach ($ledgers as $invLedgerRow) {
                    $invLedgerLabel = $invLedgerRow['code'] . ' - ' . $invLedgerRow['name'];
                    $invLedgerOption = '<option value="' . (int) $invLedgerRow['id'] . '">' . e($invLedgerLabel) . '</option>';
                    $invLedgerOptionsHtml .= $invLedgerOption;
                    $invLedgerOptionById[(int) $invLedgerRow['id']] = $invLedgerOption;
                }
                echo '<template id="inv-ledger-options">' . $invLedgerOptionsHtml . '</template>';
            }
            /** The one option a select already holds, so its value survives until the fill. */
            $invLedgerChosen = static function (int $ledgerId) use (&$invLedgerOptionById): string {
                return $ledgerId > 0 && isset($invLedgerOptionById[$ledgerId])
                    ? str_replace('<option ', '<option selected ', $invLedgerOptionById[$ledgerId])
                    : '';
            };
            // Which account a purchase of THIS item debits depends on how the
            // books are kept, so the form asks for the one that will actually
            // be posted to rather than a fixed four.
            //
            // Perpetual debits the item's own stock account. Periodic leaves
            // the stock accounts alone all year and debits Purchases -- so on
            // periodic books the Inventory Asset row alone could be set on
            // every item in the company and every purchase would still land in
            // one shared account, which is not a mapping anybody chose.
            $itemFormPurposes = inv_accounting_method($companyId) === 'periodic'
                ? ['purchases', 'sales_revenue']
                : ['inventory_asset', 'sales_revenue'];
            $itemPurposeMeta = inventory_mapping_purposes();
            ?>
            <?php foreach ($itemFormPurposes as $itemFormPurpose): ?>
                <?php
                $inheritLedger = inv_resolve_mapping($companyId, $itemFormPurpose, null, trim((string) ($editItem['category'] ?? '')) ?: null);
                $ownLedgerId = $itemMapCurrent[$itemFormPurpose] ?? 0;
                ?>
                <label><?= e($itemFormPurpose === 'purchases' || ($itemFormPurpose === 'inventory_asset' && inv_accounting_method($companyId) !== 'periodic') ? 'Purchase' : ($itemFormPurpose === 'sales_revenue' ? 'Sales' : ($itemPurposeMeta[$itemFormPurpose]['label'] ?? $itemFormPurpose))) ?> ledger
                    <select name="item_map[<?= e($itemFormPurpose) ?>]" class="js-searchable" data-fill-from="inv-ledger-options">
                        <option value="0">— inherit default<?= $inheritLedger ? ': ' . e($inheritLedger['name']) : ' (not set)' ?> —</option>
                        <?= $invLedgerChosen((int) $ownLedgerId) ?>
                    </select>
                </label>
            <?php endforeach; ?>
            </div></fieldset>
            <?php
            // Jewellery half of the SHARED item master. Only rendered where the
            // module is on; leaving Metal blank keeps this a plain stock item.
            $jwOn = function_exists('jewellery_enabled_for_company') && jewellery_enabled_for_company($companyId);
            $jwProfile = null;
            if ($jwOn && $editItem) {
                $jwProfile = jewellery_item($companyId, (int) $editItem['id']);
            }
            ?>
            <?php if ($jwOn): ?><fieldset><legend>Jewellery details</legend><div class="workspace-form-grid">
                <input type="hidden" name="jw_enabled" value="1">
                <label>Metal 
                    <select name="jw_metal_id" id="jwMetal">
                        <option value="0">— not a jewellery item —</option>
                        <?php foreach (jewellery_metals_list($companyId) as $jwMetal): ?>
                            <option value="<?= (int) $jwMetal['id'] ?>" <?= (int) ($jwProfile['metal_id'] ?? 0) === (int) $jwMetal['id'] ? 'selected' : '' ?>><?= e($jwMetal['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Purity
                    <select name="jw_purity_id" id="jwPurity">
                        <?php foreach (jewellery_purities_list($companyId) as $jwPurity): ?>
                            <option value="<?= (int) $jwPurity['id'] ?>" data-metal="<?= (int) $jwPurity['metal_id'] ?>" <?= (int) ($jwProfile['purity_id'] ?? 0) === (int) $jwPurity['id'] ? 'selected' : '' ?>><?= e($jwPurity['metal_code'] . ' · ' . $jwPurity['code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Weight unit
                    <select name="jw_unit_id">
                        <?php foreach (jewellery_units_list($companyId) as $jwUnit): ?>
                            <option value="<?= (int) $jwUnit['id'] ?>" <?= (int) ($jwProfile['unit_id'] ?? 0) === (int) $jwUnit['id'] ? 'selected' : '' ?>><?= e($jwUnit['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Jewellery type
                    <select name="jw_type">
                        <?php foreach (['ornament' => 'Ornament', 'bullion' => 'Bullion / raw metal', 'stone' => 'Stone', 'other' => 'Other'] as $jwK => $jwV): ?>
                            <option value="<?= e($jwK) ?>" <?= (string) ($jwProfile['item_type'] ?? 'ornament') === $jwK ? 'selected' : '' ?>><?= e($jwV) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Gross weight<input type="number" step="0.0001" min="0" name="jw_gross_weight" value="<?= e((string) ($jwProfile['gross_weight'] ?? '0')) ?>"></label>
                <label>Stone weight<input type="number" step="0.0001" min="0" name="jw_stone_weight" value="<?= e((string) ($jwProfile['stone_weight'] ?? '0')) ?>"></label>
                <label>Making charge rate<input type="number" step="0.0001" min="0" name="jw_making_rate" value="<?= e((string) ($jwProfile['making_charge_rate'] ?? '0')) ?>"></label>
                <label>VAT base
                    <select name="jw_vat_base">
                        <?php foreach (['default' => 'Company default', 'full_value' => 'Full line value', 'making_only' => 'Making charge only', 'stone_only' => 'Stone value only'] as $jwK => $jwV): ?>
                            <option value="<?= e($jwK) ?>" <?= (string) ($jwProfile['vat_base'] ?? 'default') === $jwK ? 'selected' : '' ?>><?= e($jwV) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="frm-check"><input type="checkbox" name="jw_vat_applicable" <?= (int) ($jwProfile['vat_applicable'] ?? 0) === 1 ? 'checked' : '' ?>> VAT applicable (e.g. diamond)</label>
                <script>
                (function () {
                    var metal = document.getElementById('jwMetal');
                    var purity = document.getElementById('jwPurity');
                    if (!metal || !purity) { return; }
                    var all = Array.prototype.slice.call(purity.options);
                    var preferred = purity.value;
                    function sync() {
                        purity.innerHTML = '';
                        all.forEach(function (opt) {
                            if (opt.getAttribute('data-metal') === metal.value) { purity.appendChild(opt); }
                        });
                        if (preferred) { purity.value = preferred; preferred = ''; }
                    }
                    metal.addEventListener('change', sync);
                    sync();
                })();
                </script>
            </div></fieldset><?php endif; ?>
            <?php if (column_exists('inventory_items', 'is_ingredient') && function_exists('hospitality_enabled_for_company') && hospitality_enabled_for_company($companyId)): ?>
                <label class="checkbox-line">
                    <input type="checkbox" name="is_ingredient" value="1" <?= (int) ($editItem['is_ingredient'] ?? 0) === 1 ? 'checked' : '' ?>
                           title="Lists this item as a recipe ingredient. The recipe unit, wastage and yield are set on the ingredient screen.">
                    Use as a recipe ingredient
                </label>
            <?php endif; ?>
            <div class="actions" style="margin-top:16px"><button class="button" type="submit"><?= icon('services') ?>Save item</button><?php if ($editItem): ?> <a class="button secondary" href="<?= e(url('admin/items.php')) ?>">Cancel edit</a><?php endif; ?></div>
        </form>
