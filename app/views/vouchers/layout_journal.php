<?php
declare(strict_types=1);

/**
 * Journal — the adjustment screen.
 *
 * This is the only type where the person decides both sides themselves, so it
 * keeps the two-column debit/credit grid. What it adds over the old shared form
 * is a running Dr/Cr difference and a quiet word when a cash or bank ledger
 * turns up: Tally refuses those outright in a journal, and while refusing would
 * break entries this system already holds, saying so is worth doing.
 */
?>
<section class="mbw-card frm-section vch-focus">
    <div class="frm-section-head">
        <span class="mbw-chip is-square tone-blue"><?= icon('journal') ?></span>
        <h2>Debits and credits</h2>
        <span class="frm-optional">At least one of each, and the two totals must agree</span>
    </div>

    <div class="vch-grid" data-grid data-min-rows="2" data-prefill="<?= e(json_encode($prefill['journal'] ?? [], JSON_UNESCAPED_SLASHES)) ?>">
        <div class="mbw-tablewrap">
            <table class="frm-entries vch-table">
                <thead>
                    <tr>
                        <th style="width:36px">SN</th>
                        <th style="width:220px">Ledger <em>*</em></th>
                        <th>Description</th>
                        <th style="width:140px">Cost centre</th>
                        <th style="width:120px">Tax code</th>
                        <th class="is-numeric" style="width:140px">Debit (<?= e(trim($currency)) ?>)</th>
                        <th class="is-numeric" style="width:140px">Credit (<?= e(trim($currency)) ?>)</th>
                        <th style="width:130px">Reference</th>
                        <th style="width:40px"></th>
                    </tr>
                </thead>
                <tbody data-rows></tbody>
            </table>
        </div>
        <div class="frm-entries-foot">
            <button type="button" class="button soft" data-add-row>＋ Add line</button>
            <div class="frm-entry-totals">
                <span>Total debit (<?= e(trim($currency)) ?>) <strong id="vch-total-debit">0.00</strong></span>
                <span>Total credit (<?= e(trim($currency)) ?>) <strong id="vch-total-credit">0.00</strong></span>
                <span class="mbw-pill tone-gray" id="vch-balance-pill">Enter lines</span>
            </div>
        </div>
        <template data-row-template>
            <tr>
                <td data-sn>1</td>
                <td>
                    <select name="ledger_id[]" data-field="ledger_id" class="vch-ledger vch-journal-ledger">
                        <option value="">Select ledger</option>
                        <?= $renderLedgerOptions($optionsAll, 0) ?>
                    </select>
                </td>
                <td><input type="text" name="memo[]" data-field="memo" maxlength="255" placeholder="What this line is for"></td>
                <td>
                    <select name="line_cost_centre[]" data-field="cost_centre">
                        <option value="">—</option>
                        <?php foreach ($costCentres as $costCentre): ?><option value="<?= e($costCentre) ?>"><?= e($costCentre) ?></option><?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <select name="line_tax[]" data-field="tax_code">
                        <option value="">—</option>
                        <?php foreach (['VAT 13%', 'Exempt', 'Zero Rated'] as $taxCode): ?><option value="<?= e($taxCode) ?>"><?= e($taxCode) ?></option><?php endforeach; ?>
                    </select>
                </td>
                <td class="is-numeric"><input type="number" data-field="debit" class="frm-num vch-dr" step="0.01" min="0" placeholder="0.00"></td>
                <td class="is-numeric"><input type="number" data-field="credit" class="frm-num vch-cr" step="0.01" min="0" placeholder="0.00"></td>
                <td>
                    <input type="text" name="line_reference[]" data-field="reference" maxlength="120" placeholder="Reference">
                    <input type="hidden" name="entry_type[]" data-field="entry_type" value="debit">
                    <input type="hidden" name="amount[]" data-field="amount" value="0">
                </td>
                <td><button type="button" class="frm-del" data-remove-row aria-label="Remove this line">&#128465;</button></td>
            </tr>
        </template>
    </div>

    <div class="notice vch-warning" id="vch-cash-warning" hidden>
        <strong>Cash or bank on a journal.</strong> Money that really moved is better recorded as a payment, receipt, or contra voucher, where the instrument and the bank side are captured. This will still post.
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('voucher-form');
    var cashBankIds = <?= json_encode(array_values(array_map(static fn (array $ledger): int => (int) $ledger['id'], $optionsCashBank))) ?>;
    var warning = document.getElementById('vch-cash-warning');

    // The two amount boxes are one figure wearing two hats: typing in one
    // clears the other, and the hidden pair the server reads is kept in step.
    function syncRow(row) {
        var debit = row.querySelector('.vch-dr');
        var credit = row.querySelector('.vch-cr');
        var typeField = row.querySelector('[data-field="entry_type"]');
        var amountField = row.querySelector('[data-field="amount"]');
        if (!debit || !credit) { return; }

        var debitValue = Number(debit.value) || 0;
        var creditValue = Number(credit.value) || 0;
        if (debitValue > 0 && document.activeElement === debit) { credit.value = ''; creditValue = 0; }
        if (creditValue > 0 && document.activeElement === credit) { debit.value = ''; debitValue = 0; }
        typeField.value = creditValue > 0 ? 'credit' : 'debit';
        amountField.value = String(creditValue > 0 ? creditValue : debitValue);
    }

    function recalc() {
        var totalDebit = 0;
        var totalCredit = 0;
        var hasCashBank = false;
        form.querySelectorAll('[data-rows] tr').forEach(function (row) {
            syncRow(row);
            totalDebit += Number((row.querySelector('.vch-dr') || {}).value) || 0;
            totalCredit += Number((row.querySelector('.vch-cr') || {}).value) || 0;
            var ledger = row.querySelector('.vch-journal-ledger');
            if (ledger && ledger.value && cashBankIds.indexOf(Number(ledger.value)) !== -1) { hasCashBank = true; }
        });

        document.getElementById('vch-total-debit').textContent = window.vchMoney(totalDebit);
        document.getElementById('vch-total-credit').textContent = window.vchMoney(totalCredit);
        warning.hidden = !hasCashBank;

        var pill = document.getElementById('vch-balance-pill');
        var balanced = totalDebit > 0 && Math.abs(totalDebit - totalCredit) < 0.005;
        if (totalDebit === 0 && totalCredit === 0) {
            pill.textContent = 'Enter lines';
            pill.className = 'mbw-pill tone-gray';
        } else if (balanced) {
            pill.textContent = '✓ Balanced';
            pill.className = 'mbw-pill tone-green';
        } else {
            pill.textContent = 'Off by ' + window.vchMoney(Math.abs(totalDebit - totalCredit));
            pill.className = 'mbw-pill tone-red';
        }

        form.setAttribute('data-balanced', balanced ? '1' : '0');
    }

    form.addEventListener('vch:change', recalc);
    form.addEventListener('input', recalc);
    recalc();
});
</script>
