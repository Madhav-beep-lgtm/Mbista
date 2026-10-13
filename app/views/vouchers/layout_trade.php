<?php
declare(strict_types=1);

/**
 * Sales, purchase, debit note, credit note — the four screens with a party,
 * a document number from the outside world, and VAT.
 *
 * The person types only the value side: what was sold or bought, and at what
 * rate. The tax line and the party line are worked out and shown before the
 * voucher is posted, because those two are where hand-entered vouchers go wrong
 * — a rounding of one paisa on the tax and the trial balance is off.
 *
 * A purchase quotes the supplier's own bill number and its date. That is the
 * number the tax office matches on, and it is not our voucher number.
 *
 * Settlement is not one thing. A day's counter takings arrive part in cash,
 * part on Fonepay or a QR scan, part on a card, and part left standing on the
 * customer's account — so the third mode below lets the money be split across
 * as many ways as it actually came in, each landing in its own ledger.
 */
$supportsServices = in_array($spec['key'], ['sales', 'credit_note'], true);
$hasExistingValues = !empty($prefill['values']);
$hasInventoryValues = count(array_filter($prefill['values'] ?? [], static fn (array $row): bool => (int) ($row['item_id'] ?? 0) > 0)) > 0;
$tradeMode = $supportsServices ? (string) ($prefill['trade_mode'] ?? ($hasExistingValues && !$hasInventoryValues ? 'services' : 'goods')) : 'goods';
$partySide = (string) $spec['party_side'];
$partyIsDebit = $partySide === 'debit';
$settlementMode = (string) ($prefill['settlement_mode'] ?? 'party');
if (!in_array($settlementMode, ['party', 'cash', 'split'], true)) {
    $settlementMode = 'party';
}
$taxMode = (string) ($prefill['tax_mode'] ?? 'exclusive');
$taxRate = (float) ($prefill['tax_rate'] ?? 13);
// Cash rails first, "on credit" last: a split line is usually money that
// actually moved, and the one that did not belongs at the bottom of the list.
$settlementModeOptions = voucher_instrument_modes();
$settlementModeOptions['credit'] = voucher_settlement_modes()['credit'];
$settledLabel = $partyIsDebit ? 'Received' : 'Paid';
?>
<input type="hidden" name="settlement_mode" value="<?= e($settlementMode) ?>">
<input type="hidden" name="reference_no" value="<?= e((string) ($prefill['reference_no'] ?? '')) ?>">
<input type="hidden" name="reference_date" value="<?= e((string) ($prefill['reference_date'] ?? '')) ?>">
<section class="frm-section vch-focus" <?= $warehouseOptions === [] && empty($spec['needs_reason']) && $settlementMode !== 'cash' ? 'hidden' : '' ?>>
    <div class="frm-grid frm-grid-4">
        <?php // Shown for a credit document and for a split alike: a split can
              // leave part of the bill on the party's account, and that part
              // has to be owed by somebody. ?>
        <label data-settlement-field="cash" hidden>Cash / bank account <em>*</em>
            <select name="settlement_ledger_id" id="vch-settlement-ledger">
                <option value="">Select cash / bank</option>
                <?= $renderLedgerOptions($optionsCashBank, $settlementMode === 'cash' ? (int) ($prefill['settlement_ledger_id'] ?? 0) : 0) ?>
            </select>
        </label>
        <?php if ($warehouseOptions !== []): ?>
            <label>Warehouse
                <select name="warehouse_id" title="Where the goods <?= $stockDirection === 'in' ? 'landed' : 'left from' ?>. Leave blank to use each item's own default.">
                    <option value="0">Each item's own default</option>
                    <?php foreach ($warehouseOptions as $warehouse): ?>
                        <option value="<?= (int) $warehouse['id'] ?>" <?= (int) ($prefill['warehouse_id'] ?? 0) === (int) $warehouse['id'] ? 'selected' : '' ?>><?= e((string) $warehouse['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
    </div>



    <?php if (!empty($spec['needs_reason'])): ?>
        <div class="frm-grid frm-grid-2">
            <label class="frm-span-3"><?= e((string) $spec['reason_label']) ?> <em>*</em>
                <input type="text" name="return_reason" maxlength="255" placeholder="e.g. Goods damaged in transit, returned in full" value="<?= e((string) ($prefill['return_reason'] ?? '')) ?>" required>
            </label>
        </div>
    <?php endif; ?>
</section>

<?php // How the money moved, one line per way. Each becomes its own entry on
      // the party side of the voucher, so the till, the wallet and the
      // receivable each carry exactly what they took — which is the only way
      // the drawer can be counted against the day book at close of business. ?>
<section class="frm-section vch-entry-panel" data-settlement-field="split" id="vch-settle-section" hidden>
    <div class="frm-section-head">
        <span class="mbw-chip is-square tone-green"><?= icon('coins') ?></span>
        <h2>How it was settled</h2>
        <span class="frm-optional">One line per mode. They must add up to the <?= $partyIsDebit ? 'invoice' : 'bill' ?> total.</span>
    </div>

    <div class="vch-grid" data-grid data-min-rows="1" id="vch-settle-grid" data-prefill="<?= e(json_encode($prefill['settlements'] ?? [], JSON_UNESCAPED_SLASHES)) ?>">
        <div class="mbw-tablewrap">
            <table class="frm-entries vch-table">
                <thead>
                    <tr>
                        <th style="width:36px">SN</th>
                        <th style="width:240px">Account <em>*</em></th>
                        <th style="width:160px">Mode</th>
                        <th>Txn / cheque / reference</th>
                        <th class="is-numeric" style="width:150px"><?= e($settledLabel) ?> (<?= e(trim($currency)) ?>) <em>*</em></th>
                        <th style="width:40px"></th>
                    </tr>
                </thead>
                <tbody data-rows></tbody>
            </table>
        </div>
        <div class="frm-entries-foot">
            <button type="button" class="button soft" data-add-row>＋ Add another mode</button>
            <button type="button" class="button soft" id="vch-settle-balance" title="Put whatever is still unallocated onto the first empty line">Put the rest here</button>
            <div class="frm-entry-totals">
                <span><?= e($settledLabel) ?> (<?= e(trim($currency)) ?>) <strong id="vch-settle-total">0.00</strong></span>
            </div>
        </div>
        <template data-row-template>
            <tr>
                <td data-sn>1</td>
                <td>
                    <select name="settle_ledger[]" data-field="ledger_id" class="vch-settle-ledger">
                        <option value="">Select account</option>
                        <option value="party">On credit — the <?= e(strtolower((string) $spec['party_label'])) ?>'s own account</option>
                        <?= $renderLedgerOptions($optionsSettlement, 0) ?>
                    </select>
                </td>
                <td>
                    <select name="settle_mode[]" data-field="mode" class="vch-settle-mode">
                        <?php foreach ($settlementModeOptions as $modeKey => $modeLabel): ?>
                            <option value="<?= e((string) $modeKey) ?>"><?= e((string) $modeLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" name="settle_instrument_no[]" data-field="instrument_no" maxlength="80" placeholder="Fonepay / cheque / txn no."></td>
                <td class="is-numeric"><input type="number" name="settle_amount[]" data-field="amount" class="frm-num vch-settle-amount" step="0.01" min="0" placeholder="0.00"></td>
                <td><button type="button" class="frm-del" data-remove-row aria-label="Remove this settlement line">&#128465;</button></td>
            </tr>
        </template>
    </div>
    <p class="vch-settle-note" id="vch-settle-note" data-state="short"></p>
</section>

<section class="frm-section vch-entry-panel">
    <div class="frm-grid vch-header-grid" style="margin-bottom:16px">
        <label><?= $supportsServices ? 'Sales category' : 'Purchase category' ?>
        <select name="trade_mode" id="vch-trade-mode">
            <option value="goods" <?= $tradeMode === 'goods' ? 'selected' : '' ?>><?= $supportsServices ? 'Goods sales' : 'Goods purchase' ?></option>
            <?php if ($supportsServices): ?><option value="services" <?= $tradeMode === 'services' ? 'selected' : '' ?>>Service sales</option><?php endif; ?>
        </select></label>
    </div>
    <div class="vch-grid" data-grid data-min-rows="1" data-prefill="<?= e(json_encode($prefill['values'] ?? [], JSON_UNESCAPED_SLASHES)) ?>">
        <div class="mbw-tablewrap">
            <table class="frm-entries vch-table">
                <thead>
                    <tr>
                        <th style="width:36px">SN</th>
                        <th style="width:190px" data-goods-field>Inventory item <em>*</em></th>
                        <th style="width:200px"><?= $spec['value_role'] === 'income' ? 'Sales ledger' : 'Purchase ledger' ?> <em>*</em></th>
                        <th data-description-heading>Description / Service</th><th style="width:180px">Cost centre <em>*</em></th>
                        <th class="is-numeric" style="width:100px" data-goods-field>Qty</th>
                        <th class="is-numeric" style="width:120px" data-goods-field>Rate</th>
                        <th style="width:145px">Tax treatment</th><th style="width:85px">Tax %</th><th class="is-numeric" style="width:120px">Line total</th><th class="is-numeric" style="width:150px">Amount (<?= e(trim($currency)) ?>) <em>*</em></th>
                        <th style="width:40px"></th>
                    </tr>
                </thead>
                <tbody data-rows></tbody>
            </table>
        </div>
        <div class="frm-entries-foot">
            <button type="button" class="button soft" data-add-row>＋ Add line</button>
            <div class="frm-entry-totals">
                <span>Lines (<?= e(trim($currency)) ?>) <strong id="vch-value-total">0.00</strong></span>
            </div>
        </div>
        <template data-row-template>
            <tr>
                <td data-sn>1</td>
                <td data-goods-field>
                    <select name="value_item[]" data-field="item_id" class="vch-item">
                        <option value="">Select inventory item</option>
                        <?php foreach ($itemOptions as $item): ?>
                            <option value="<?= (int) $item['id'] ?>"
                                    data-name="<?= e((string) $item['name']) ?>"
                                    data-unit="<?= e((string) $item['unit']) ?>"
                                    data-rate="<?= e(number_format((float) $item[$spec['key'] === 'sales' || $spec['key'] === 'credit_note' ? 'sales_rate' : 'purchase_rate'], 2, '.', '')) ?>"
                                    data-ledger="<?= (int) $item['stock_ledger_id'] ?>"><?= e((string) $item['name']) ?> (<?= e((string) $item['sku']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <select name="value_ledger[]" data-field="ledger_id" class="vch-ledger">
                        <option value="">Select ledger</option>
                        <?= $renderLedgerOptions($optionsValue, 0) ?>
                    </select>
                </td>
                <td><input type="text" name="value_description[]" data-field="description" maxlength="255" placeholder="Item or service"></td><td><select name="value_cost_centre[]" data-field="cost_centre" required aria-label="Cost centre"><option value="">Select cost centre</option><?php foreach ($costCentres as $centre): ?><option value="<?= e($centre) ?>"><?= e($centre) ?></option><?php endforeach; ?></select></td>
                <td class="is-numeric" data-goods-field><input type="number" name="value_qty[]" data-field="qty" class="frm-num vch-qty" step="0.001" min="0" placeholder="0"></td>
                <td class="is-numeric" data-goods-field><input type="number" name="value_rate[]" data-field="rate" class="frm-num vch-rate" step="0.01" min="0" placeholder="0.00"></td>

                <td><select name="value_tax_mode[]" data-field="tax_mode" class="vch-line-tax-mode"><option value="exclusive" <?= $taxMode === 'exclusive' ? 'selected' : '' ?>>Exclusive</option><option value="inclusive" <?= $taxMode === 'inclusive' ? 'selected' : '' ?>>Inclusive</option><option value="none" <?= $taxMode === 'none' ? 'selected' : '' ?>>Exempt / no tax</option></select></td>
                <td><input type="number" name="value_tax_rate[]" data-field="tax_rate" class="vch-line-tax-rate" min="0" max="100" step="0.01" value="<?= e((string) $taxRate) ?>"></td>
                <td class="is-numeric" data-line-total>0.00</td>
                <td class="is-numeric"><input type="number" name="value_amount[]" data-field="amount" class="frm-num vch-value-amount" step="0.01" min="0" placeholder="0.00"></td>
                <td><button type="button" class="frm-del" data-remove-row aria-label="Remove this line">&#128465;</button></td>
            </tr>
        </template>
    </div>
</section>

<div class="frm-grid frm-grid-4">
    <label data-tax-field>Tax ledger
        <select name="tax_ledger_id" id="vch-tax-ledger">
            <option value="">Select configured tax ledger</option>
            <?= $renderLedgerOptions($optionsTax, (int) ($prefill['tax_ledger_id'] ?? 0)) ?>
        </select>
    </label>
</div>

<section class="frm-section vch-summary-card">
    <div class="frm-section-head">
        <span class="mbw-chip is-square tone-green"><?= icon('coins') ?></span>
        <h2>What will be posted</h2>
    </div>
    <div class="vch-summary">
        <div><small>Taxable value</small><strong id="vch-sum-taxable">0.00</strong></div>
        <div><small>Tax</small><strong id="vch-sum-tax">0.00</strong></div>
        <div class="is-total"><small><?= $partyIsDebit ? 'Receivable' : 'Payable' ?> total</small><strong id="vch-sum-total">0.00</strong></div>
        <div class="vch-summary-note"><small><?= $partyIsDebit ? 'Debited to' : 'Credited to' ?></small><strong id="vch-sum-party">—</strong></div>
        <div data-settlement-field="split" hidden><small>Still unallocated</small><strong id="vch-sum-unallocated">0.00</strong></div>
    </div>
    <?php if ($itemOptions !== []): ?>
        <p class="vch-stock-note" id="vch-stock-note" hidden>
            <?= icon('inventory') ?>
            <span data-stock-text></span>
        </p>
    <?php endif; ?>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('voucher-form');
    var partySelect = document.getElementById('vch-party');
    var settlementLedger = document.getElementById('vch-settlement-ledger');


    var taxLedger = document.getElementById('vch-tax-ledger');
    var partyWord = <?= json_encode(strtolower((string) $spec['party_label'])) ?>;
    var lastTotal = 0;

    function settlementMode() {
        var checked = form.querySelector('input[name="settlement_mode"]');
        return checked ? checked.value : 'party';
    }

    // Which settlement fields are asked for follows the radio, so the screen
    // never demands a party for a cash sale. A field can belong to more than
    // one mode: a split may leave part of the bill on the party's account, so
    // the party is asked for there too.
    function applySettlement() {
        var settleWay = settlementMode();
        form.querySelectorAll('[data-settlement-field]').forEach(function (field) {
            var wanted = (field.getAttribute('data-settlement-field') || '').split(' ').indexOf(settleWay) >= 0;
            field.hidden = !wanted;
            var control = field.querySelector('select');
            // Never required while hidden: the browser cannot focus a hidden
            // control to complain about it. In a split the grid answers for
            // itself, in figures the person can see as they type.
            if (control) { control.required = wanted && settleWay !== 'split'; }
        });
        form.querySelectorAll('.vch-radio').forEach(function (radio) {
            radio.classList.toggle('is-on', radio.querySelector('input').checked);
        });
        if (partySelect) { partySelect.required = settleWay === 'party'; }
        recalc();
    }

    function settlementRows() {
        return form.querySelectorAll('#vch-settle-grid [data-rows] tr');
    }

    // A line that names the party is on credit by definition, and one that
    // names a till is not. Keeping the two in step saves saying it twice.
    function syncSettlementRow(row) {
        if (!row) { return; }
        var ledgerField = row.querySelector('.vch-settle-ledger');
        var modeField = row.querySelector('.vch-settle-mode');
        if (!ledgerField || !modeField) { return; }
        if (ledgerField.value === 'party') { modeField.value = 'credit'; }
        else if (ledgerField.value !== '' && modeField.value === 'credit') { modeField.value = 'cash'; }
    }

    // Picking an item fills the row the way the counter would: its name, the
    // rate it usually goes at, and — when goods are coming IN — the stock
    // ledger their value belongs to. Nothing already typed is overwritten.
    var stockDirection = <?= json_encode($stockDirection) ?>;
    function applyItem(row) {
        var itemSelect = row.querySelector('.vch-item');
        if (!itemSelect || !itemSelect.value) { return; }
        var option = itemSelect.options[itemSelect.selectedIndex];
        var description = row.querySelector('[data-field="description"]');
        var rateField = row.querySelector('.vch-rate');
        var qtyField = row.querySelector('.vch-qty');
        var ledgerField = row.querySelector('.vch-ledger');

        if (description && description.value === '') { description.value = option.getAttribute('data-name') || ''; }
        if (rateField && (Number(rateField.value) || 0) === 0) { rateField.value = option.getAttribute('data-rate') || ''; }
        if (qtyField && (Number(qtyField.value) || 0) === 0) { qtyField.value = '1'; }
        var stockLedger = option.getAttribute('data-ledger');
        if (stockDirection === 'in' && ledgerField && ledgerField.value === '' && stockLedger && stockLedger !== '0'
            && ledgerField.querySelector('option[value="' + stockLedger + '"]')) {
            ledgerField.value = stockLedger;
        }
    }

    function applyTradeMode() {
        var goods = document.getElementById('vch-trade-mode').value === 'goods';
        form.querySelectorAll('[data-goods-field]').forEach(function (cell) {
            cell.hidden = !goods;
            cell.querySelectorAll('input, select').forEach(function (field) {
                field.disabled = !goods;
                field.required = goods && (field.classList.contains('vch-item') || field.classList.contains('vch-qty'));
            });
        });
        var title = form.querySelector('[data-description-heading]');
        if (title) title.textContent = goods ? 'Description' : 'Service';
    }
    function recalc() {
        applyTradeMode();

        var taxable = 0;
        var tax = 0;
        var stockLines = 0;
        var stockUnits = 0;

        form.querySelectorAll('[data-rows] tr').forEach(function (row) {
            var isGoods = document.getElementById('vch-trade-mode').value === 'goods';
            var qty = isGoods ? Number((row.querySelector('.vch-qty') || {}).value) || 0 : 0;
            var unitRate = isGoods ? Number((row.querySelector('.vch-rate') || {}).value) || 0 : 0;
            var amountField = row.querySelector('.vch-value-amount');
            var itemSelect = row.querySelector('.vch-item');
            if (itemSelect && itemSelect.value && qty > 0) { stockLines++; stockUnits += qty; }
            if (!amountField) { return; }
            // Quantity times rate wins whenever both are given: it is the figure
            // the person actually reasoned about.
            if (qty > 0 && unitRate > 0 && document.activeElement !== amountField) {
                amountField.value = window.vchMoney(qty * unitRate);
            }
            var mode = row.querySelector('.vch-line-tax-mode').value;
            var rate = mode === 'none' ? 0 : Number(row.querySelector('.vch-line-tax-rate').value) || 0;
            var gross = Number(amountField.value) || 0;
            row.querySelector('[data-line-total]').textContent = '0.00';
            if (gross <= 0) { return; }
            var lineTaxable = (mode === 'inclusive' && rate > 0) ? Math.round((gross / (1 + rate / 100)) * 100) / 100 : gross;
            taxable += lineTaxable;
            var lineTax = mode === 'none' ? 0 : Math.round(lineTaxable * (rate / 100) * 100) / 100;
            tax += lineTax;
            row.querySelector('[data-line-total]').textContent = window.vchMoney(lineTaxable + lineTax);
        });

        taxable = Math.round(taxable * 100) / 100;
        tax = Math.round(tax * 100) / 100;
        var total = Math.round((taxable + tax) * 100) / 100;

        document.getElementById('vch-value-total').textContent = window.vchMoney(taxable);
        document.getElementById('vch-sum-taxable').textContent = window.vchMoney(taxable);
        document.getElementById('vch-sum-tax').textContent = window.vchMoney(tax);
        document.getElementById('vch-sum-total').textContent = window.vchMoney(total);

        var settleWay = settlementMode();
        lastTotal = total;

        // What the settlement lines have accounted for, and what is still
        // hanging. Shown while it is being typed, because discovering after
        // the fact that eleven rupees are missing means retyping the voucher.
        var settled = 0;
        var settledLines = 0;
        var creditLineNeedsParty = false;
        settlementRows().forEach(function (row) {
            var amountField = row.querySelector('.vch-settle-amount');
            var ledgerField = row.querySelector('.vch-settle-ledger');
            var lineAmount = amountField ? (Number(amountField.value) || 0) : 0;
            if (lineAmount > 0) { settled += lineAmount; settledLines++; }
            if (ledgerField && ledgerField.value === 'party'
                && (!partySelect || !partySelect.value || partySelect.value === '0')) {
                creditLineNeedsParty = true;
            }
        });
        settled = Math.round(settled * 100) / 100;
        var unallocated = Math.round((total - settled) * 100) / 100;

        var settledCell = document.getElementById('vch-settle-total');
        if (settledCell) { settledCell.textContent = window.vchMoney(settled); }
        var unallocatedCell = document.getElementById('vch-sum-unallocated');
        if (unallocatedCell) { unallocatedCell.textContent = window.vchMoney(unallocated); }

        var settleNote = document.getElementById('vch-settle-note');
        if (settleNote) {
            if (settledLines === 0) {
                settleNote.setAttribute('data-state', 'short');
                settleNote.textContent = 'Nothing allocated yet — ' + window.vchMoney(total) + ' to account for.';
            } else if (Math.abs(unallocated) < 0.005) {
                settleNote.setAttribute('data-state', 'ok');
                settleNote.textContent = 'Allocated in full, across ' + settledLines + ' line(s).';
            } else if (unallocated > 0) {
                settleNote.setAttribute('data-state', 'short');
                settleNote.textContent = window.vchMoney(unallocated) + ' still unallocated — put the rest on credit, or on another mode.';
            } else {
                settleNote.setAttribute('data-state', 'over');
                settleNote.textContent = window.vchMoney(-unallocated) + ' more has been allocated than was billed.';
            }
            if (creditLineNeedsParty) {
                settleNote.setAttribute('data-state', 'short');
                settleNote.textContent += ' One line is on credit, so choose the ' + partyWord + ' who owes it.';
            }
        }

        var target = settleWay === 'cash' ? settlementLedger : partySelect;
        var option = target && target.selectedIndex >= 0 ? target.options[target.selectedIndex] : null;
        var name = option && option.value && option.value !== '0' ? option.textContent.trim() : '—';
        if (settleWay === 'split') {
            name = settledLines > 0 ? 'Split across ' + settledLines + ' line(s)' : '—';
        }
        document.getElementById('vch-sum-party').textContent = name;

        var taxFields = form.querySelectorAll('[data-tax-field]');
        taxFields.forEach(function (field) { field.hidden = tax <= 0; });

        if (taxLedger) { taxLedger.required = tax > 0; }

        var stockNote = document.getElementById('vch-stock-note');
        if (stockNote) {
            stockNote.hidden = stockLines === 0;
            stockNote.querySelector('[data-stock-text]').textContent = stockLines === 0 ? '' : (
                'Posting this also moves ' + window.vchMoney(stockUnits).replace(/\.00$/, '')
                + ' unit(s) across ' + stockLines + ' item line(s) '
                + (stockDirection === 'in' ? 'into' : 'out of') + ' stock'
                + (stockDirection === 'out' ? ', and posts what they cost against this sale.' : '.')
            );
        }

        // A split is only complete when every rupee billed has been said to
        // have arrived somewhere. Anything else would post an unbalanced
        // voucher, which is the one thing this screen must never do.
        var ready = settleWay === 'split'
            ? (settledLines > 0 && Math.abs(unallocated) < 0.005 && !creditLineNeedsParty)
            : name !== '—';
        form.setAttribute('data-balanced', total > 0 && ready ? '1' : '0');
    }

    form.querySelectorAll('input[name="settlement_mode"]').forEach(function (radio) {
        radio.addEventListener('change', applySettlement);
    });
    form.addEventListener('vch:change', recalc);
    form.addEventListener('input', recalc);
    form.addEventListener('change', function (event) {
        if (event.target && event.target.classList.contains('vch-item')) {
            applyItem(event.target.closest('tr'));
        }
        if (event.target && event.target.classList.contains('vch-settle-ledger')) {
            syncSettlementRow(event.target.closest('tr'));
        }
        recalc();
    });

    // Whatever is left over, dropped onto the first line still empty. Dividing
    // a day's takings by hand is exactly where the paisa go missing.
    var balanceButton = document.getElementById('vch-settle-balance');
    if (balanceButton) {
        balanceButton.addEventListener('click', function () {
            var rows = settlementRows();
            if (!rows.length) { return; }
            var settled = 0;
            rows.forEach(function (row) {
                var field = row.querySelector('.vch-settle-amount');
                settled += field ? (Number(field.value) || 0) : 0;
            });
            var remainder = Math.round((lastTotal - settled) * 100) / 100;
            if (remainder <= 0) { return; }
            var landing = null;
            rows.forEach(function (row) {
                var field = row.querySelector('.vch-settle-amount');
                if (!landing && field && (Number(field.value) || 0) === 0) { landing = field; }
            });
            if (!landing) { landing = rows[rows.length - 1].querySelector('.vch-settle-amount'); }
            if (!landing) { return; }
            landing.value = window.vchMoney((Number(landing.value) || 0) + remainder);
            recalc();
        });
    }

    applySettlement();
});
</script>
