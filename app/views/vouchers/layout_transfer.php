<?php
declare(strict_types=1);
// Preserve existing transfer prefills while presenting one Dr/Cr register.
$rows = [];
foreach (['contra_in' => 'debit', 'contra_out' => 'credit'] as $key => $side) {
    foreach ($prefill[$key] ?? [] as $line) {
        $rows[] = ['ledger_id' => $line['ledger_id'], $side => $line['amount'], 'cost_centre' => $line['cost_centre'] ?? '', 'entry_type' => $side, 'amount' => $line['amount']];
    }
}
?>
<section class="mbw-card frm-section vch-entry-panel">
    <div class="vch-grid" data-grid data-min-rows="2" data-prefill="<?= e(json_encode($rows, JSON_UNESCAPED_SLASHES)) ?>">
        <div class="mbw-tablewrap"><table class="frm-entries vch-table">
            <thead><tr><th>SN</th><th>Cash / bank account</th><th>Cost centre <em>*</em></th><th>Debit (<?= e(trim($currency)) ?>)</th><th>Credit (<?= e(trim($currency)) ?>)</th><th></th></tr></thead>
            <tbody data-rows></tbody>
        </table></div>
        <div class="frm-entries-foot">
            <button type="button" class="button soft" data-add-row>＋ Add line</button>
            <div class="frm-entry-totals">
                <span>Total debit <strong id="vch-total-debit">0.00</strong></span>
                <span>Total credit <strong id="vch-total-credit">0.00</strong></span>
                <span class="mbw-pill tone-gray" id="vch-balance-pill">Enter lines</span>
            </div>
        </div>
        <template data-row-template><tr>
            <td data-sn></td>
            <td><select name="ledger_id[]" data-field="ledger_id" class="vch-ledger vch-journal-ledger"><option value="">Select cash / bank account</option><?= $renderLedgerOptions($optionsCashBank, 0) ?></select></td>
            <td><select name="line_cost_centre[]" data-field="cost_centre" required aria-label="Cost centre"><option value="">Select cost centre</option><?php foreach ($costCentres as $centre): ?><option value="<?= e($centre) ?>"><?= e($centre) ?></option><?php endforeach; ?></select></td>
            <td><input type="number" data-field="debit" class="frm-num vch-dr" min="0" step="0.01" aria-label="Debit amount"></td>
            <td><input type="number" data-field="credit" class="frm-num vch-cr" min="0" step="0.01" aria-label="Credit amount">
                <input type="hidden" name="entry_type[]" data-field="entry_type" value="debit">
                <input type="hidden" name="amount[]" data-field="amount" value="0">
            </td>
            <td><button type="button" class="frm-del" data-remove-row aria-label="Remove line">&#128465;</button></td>
        </tr></template>
    </div>
    <div class="frm-grid frm-grid-4 vch-transfer-meta">
        <label>Mode
            <select name="instrument_type">
                <option value="">Not recorded</option>
                <?php foreach (voucher_instrument_modes() as $modeKey => $modeLabel): ?>
                    <option value="<?= e($modeKey) ?>" <?= (string) ($prefill['instrument_type'] ?? '') === $modeKey ? 'selected' : '' ?>><?= e($modeLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Cheque / transaction no.
            <input type="text" name="instrument_no" maxlength="80" value="<?= e((string) ($prefill['instrument_no'] ?? '')) ?>">
        </label>
        <label>Instrument date
            <input type="date" name="instrument_date" value="<?= e((string) ($prefill['instrument_date'] ?? '')) ?>">
        </label>
    </div>


</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('voucher-form');
    var cashBankIds = <?= json_encode(array_values(array_map(static fn (array $ledger): int => (int) $ledger['id'], $optionsCashBank))) ?>;
    var warning = { hidden: true };

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
