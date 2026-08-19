<?php
declare(strict_types=1);

/**
 * Contra — money moving between the firm's own pockets.
 *
 * Nothing is earned or spent here, so there is no party, no tax and no
 * particulars grid. There are accounts on each side and the amounts moving
 * between them, with the resulting double entry spelled out underneath so the
 * person can see the direction, and the two totals, before they post it.
 *
 * Each side is a list rather than a single field because a real sweep is often
 * one-to-many: a day's takings out of the till and into three bank accounts is
 * one movement of money, and splitting it across three vouchers stops the day
 * book from showing it as the one thing it was.
 */
$contraOut = $prefill['contra_out'] ?? [];
$contraIn = $prefill['contra_in'] ?? [];
// A voucher submitted before this screen had grids, handed back after a
// rejection: put its one account on each side rather than lose it.
if ($contraOut === [] && (int) ($prefill['contra_from_ledger'] ?? 0) > 0) {
    $contraOut = [['ledger_id' => (string) (int) $prefill['contra_from_ledger'], 'amount' => (float) ($prefill['contra_amount'] ?? 0)]];
}
if ($contraIn === [] && (int) ($prefill['contra_to_ledger'] ?? 0) > 0) {
    $contraIn = [['ledger_id' => (string) (int) $prefill['contra_to_ledger'], 'amount' => (float) ($prefill['contra_amount'] ?? 0)]];
}

/** One side of the transfer, as a grid of accounts and amounts. */
$renderContraLegs = static function (string $side, array $rows) use ($optionsCashBank, $renderLedgerOptions, $currency): void {
    $isOut = $side === 'out';
    ?>
    <div class="vch-leg">
        <div class="vch-leg-head">
            <strong><?= $isOut ? 'Money out of' : 'Money into' ?></strong>
            <span><?= $isOut ? 'These accounts are credited' : 'These accounts are debited' ?></span>
        </div>
        <div class="vch-grid" data-grid data-min-rows="1" id="vch-<?= e($side) ?>-grid"
             data-prefill="<?= e(json_encode($rows, JSON_UNESCAPED_SLASHES)) ?>">
            <div class="mbw-tablewrap">
                <table class="frm-entries vch-table">
                    <thead>
                        <tr>
                            <th style="width:36px">SN</th>
                            <th>Cash / bank account <em>*</em></th>
                            <th class="is-numeric" style="width:150px">Amount (<?= e(trim($currency)) ?>) <em>*</em></th>
                            <th style="width:40px"></th>
                        </tr>
                    </thead>
                    <tbody data-rows></tbody>
                </table>
            </div>
            <div class="frm-entries-foot">
                <button type="button" class="button soft" data-add-row>＋ Add another account</button>
                <div class="frm-entry-totals">
                    <span><?= $isOut ? 'Out' : 'In' ?> (<?= e(trim($currency)) ?>) <strong id="vch-<?= e($side) ?>-total">0.00</strong></span>
                </div>
            </div>
            <template data-row-template>
                <tr>
                    <td data-sn>1</td>
                    <td>
                        <select name="contra_<?= e($side) ?>_ledger[]" data-field="ledger_id" class="vch-leg-ledger">
                            <option value="">Select cash / bank account</option>
                            <?= $renderLedgerOptions($optionsCashBank, 0) ?>
                        </select>
                    </td>
                    <td class="is-numeric"><input type="number" name="contra_<?= e($side) ?>_amount[]" data-field="amount" class="frm-num vch-leg-amount" step="0.01" min="0" placeholder="0.00"></td>
                    <td><button type="button" class="frm-del" data-remove-row aria-label="Remove this account">&#128465;</button></td>
                </tr>
            </template>
        </div>
    </div>
    <?php
};
?>
<section class="mbw-card frm-section vch-focus">
    <div class="frm-section-head">
        <span class="mbw-chip is-square tone-teal"><?= icon('reconcile') ?></span>
        <h2>The transfer</h2>
        <span class="frm-optional">Every account on both sides must be a cash or bank account, and the two sides must agree</span>
    </div>

    <div class="vch-legs">
        <?php $renderContraLegs('out', $contraOut); ?>
        <span class="vch-transfer-arrow" aria-hidden="true"><?= icon('chevron-right') ?></span>
        <?php $renderContraLegs('in', $contraIn); ?>
    </div>

    <?php if (count($optionsCashBank) < 2): ?>
        <div class="notice">This company has fewer than two cash or bank accounts, so there is nothing to transfer between yet. Open one under <a href="<?= e(url('admin/chart-ledgers.php')) ?>">Ledgers</a> first.</div>
    <?php endif; ?>

    <div class="vch-preview" id="vch-preview" hidden>
        <span class="vch-preview-label">This will post</span>
        <span class="vch-preview-line"><em>Dr</em> <strong data-preview-debit>—</strong> <b data-preview-in>0.00</b></span>
        <span class="vch-preview-line"><em>Cr</em> <strong data-preview-credit>—</strong> <b data-preview-out>0.00</b></span>
    </div>
</section>

<section class="mbw-card frm-section">
    <div class="frm-section-head">
        <span class="mbw-chip is-square tone-blue"><?= icon('bank') ?></span>
        <h2>How it moved</h2>
        <span class="frm-optional">Optional — the cheque or transfer that carried it</span>
    </div>
    <div class="frm-grid frm-grid-4">
        <label>Mode
            <select name="instrument_type">
                <option value="">Not recorded</option>
                <?php foreach (voucher_instrument_modes() as $modeKey => $modeLabel): ?>
                    <option value="<?= e($modeKey) ?>" <?= (string) ($prefill['instrument_type'] ?? '') === $modeKey ? 'selected' : '' ?>><?= e($modeLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Cheque / transaction no.
            <input type="text" name="instrument_no" maxlength="80" placeholder="e.g. 004312" value="<?= e((string) ($prefill['instrument_no'] ?? '')) ?>">
        </label>
        <label>Instrument date
            <input type="date" name="instrument_date" value="<?= e((string) ($prefill['instrument_date'] ?? '')) ?>">
        </label>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var preview = document.getElementById('vch-preview');
    var form = document.getElementById('voucher-form');

    // One side of the transfer, as the rows a person has actually filled in.
    // A row with neither an account nor an amount is nothing yet, not an error.
    function legsOf(side) {
        var legs = [];
        form.querySelectorAll('#vch-' + side + '-grid [data-rows] tr').forEach(function (row) {
            var select = row.querySelector('.vch-leg-ledger');
            var amountField = row.querySelector('.vch-leg-amount');
            var amount = amountField ? (Number(amountField.value) || 0) : 0;
            var id = select ? select.value : '';
            if (id === '' && amount <= 0) { return; }
            legs.push({
                id: id,
                name: (select && select.value && select.selectedIndex >= 0)
                    ? select.options[select.selectedIndex].textContent.trim() : '',
                amount: amount
            });
        });
        return legs;
    }

    function totalOf(legs) {
        var total = 0;
        legs.forEach(function (leg) { total += leg.amount; });
        return Math.round(total * 100) / 100;
    }

    // Named when there is one of it; counted when there are several, because
    // naming one of three banks implies the other two were not involved.
    function nameOf(legs) {
        if (legs.length === 0) { return '—'; }
        if (legs.length === 1) { return legs[0].name || '—'; }
        return legs.length + ' accounts';
    }

    function refresh() {
        var outLegs = legsOf('out');
        var inLegs = legsOf('in');
        var outTotal = totalOf(outLegs);
        var inTotal = totalOf(inLegs);

        document.getElementById('vch-out-total').textContent = window.vchMoney(outTotal);
        document.getElementById('vch-in-total').textContent = window.vchMoney(inTotal);

        var overlap = false;
        outLegs.forEach(function (leg) {
            if (!leg.id) { return; }
            inLegs.forEach(function (other) { if (other.id === leg.id) { overlap = true; } });
        });
        function complete(legs) {
            return legs.length > 0 && legs.every(function (leg) { return leg.id !== '' && leg.amount > 0; });
        }
        var agrees = outTotal > 0 && Math.abs(outTotal - inTotal) < 0.005;
        var ready = complete(outLegs) && complete(inLegs);

        // The submit guard is set on EVERY pass, including the ones that
        // return early — a contra emptied back out has to take the guard down
        // with it, or a half-filled voucher stays postable.
        form.setAttribute('data-balanced', (ready && agrees && !overlap) ? '1' : '0');

        preview.hidden = outLegs.length === 0 && inLegs.length === 0;
        if (preview.hidden) { return; }
        preview.querySelector('[data-preview-debit]').textContent = nameOf(inLegs);
        preview.querySelector('[data-preview-credit]').textContent = nameOf(outLegs);
        preview.querySelector('[data-preview-in]').textContent = window.vchMoney(inTotal);
        preview.querySelector('[data-preview-out]').textContent = window.vchMoney(outTotal);
        preview.classList.toggle('is-invalid', overlap || (ready && !agrees));
        preview.querySelector('.vch-preview-label').textContent = overlap
            ? 'An account is on both sides — money cannot move to where it already is'
            : (ready && !agrees
                ? 'The two sides are ' + window.vchMoney(Math.abs(outTotal - inTotal)) + ' apart — what leaves has to equal what arrives'
                : 'This will post');
    }

    form.addEventListener('vch:change', refresh);
    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    refresh();
});
</script>
