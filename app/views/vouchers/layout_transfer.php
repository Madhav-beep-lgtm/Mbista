<?php
declare(strict_types=1);

/**
 * Contra — money moving between the firm's own pockets.
 *
 * Nothing is earned or spent here, so there is no party, no tax and no
 * particulars grid. There are two accounts and one amount, and the screen shows
 * exactly that, with the resulting double entry spelled out underneath so the
 * person can see the direction before they post it.
 */
?>
<section class="mbw-card frm-section vch-focus">
    <div class="frm-section-head">
        <span class="mbw-chip is-square tone-teal"><?= icon('reconcile') ?></span>
        <h2>The transfer</h2>
        <span class="frm-optional">Both accounts must be cash or bank accounts</span>
    </div>

    <div class="vch-transfer">
        <label class="vch-transfer-side">
            <small>Money out of</small>
            <select name="contra_from_ledger" id="vch-from" required>
                <option value="">Select cash / bank account</option>
                <?= $renderLedgerOptions($optionsCashBank, (int) ($prefill['contra_from_ledger'] ?? 0)) ?>
            </select>
            <span class="vch-transfer-note" data-from-note>This account is credited</span>
        </label>

        <span class="vch-transfer-arrow" aria-hidden="true"><?= icon('chevron-right') ?></span>

        <label class="vch-transfer-side">
            <small>Money into</small>
            <select name="contra_to_ledger" id="vch-to" required>
                <option value="">Select cash / bank account</option>
                <?= $renderLedgerOptions($optionsCashBank, (int) ($prefill['contra_to_ledger'] ?? 0)) ?>
            </select>
            <span class="vch-transfer-note" data-to-note>This account is debited</span>
        </label>

        <label class="vch-transfer-amount">
            <small>Amount (<?= e(trim($currency)) ?>)</small>
            <input type="number" name="contra_amount" id="vch-amount" step="0.01" min="0.01" placeholder="0.00"
                   value="<?= (float) ($prefill['contra_amount'] ?? 0) > 0 ? e(number_format((float) $prefill['contra_amount'], 2, '.', '')) : '' ?>" required>
        </label>
    </div>

    <?php if (count($optionsCashBank) < 2): ?>
        <div class="notice">This company has fewer than two cash or bank accounts, so there is nothing to transfer between yet. Open one under <a href="<?= e(url('admin/chart-ledgers.php')) ?>">Ledgers</a> first.</div>
    <?php endif; ?>

    <div class="vch-preview" id="vch-preview" hidden>
        <span class="vch-preview-label">This will post</span>
        <span class="vch-preview-line"><em>Dr</em> <strong data-preview-debit>—</strong> <b data-preview-amount>0.00</b></span>
        <span class="vch-preview-line"><em>Cr</em> <strong data-preview-credit>—</strong> <b data-preview-amount>0.00</b></span>
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
    var from = document.getElementById('vch-from');
    var to = document.getElementById('vch-to');
    var amount = document.getElementById('vch-amount');
    var preview = document.getElementById('vch-preview');
    var form = document.getElementById('voucher-form');

    function label(select) {
        var option = select.options[select.selectedIndex];
        return option && option.value ? option.textContent.trim() : '';
    }

    function refresh() {
        var fromName = label(from);
        var toName = label(to);
        var value = Number(amount.value) || 0;
        var same = from.value !== '' && from.value === to.value;
        var ready = fromName !== '' && toName !== '' && value > 0;

        // The submit guard is set on EVERY pass, including the ones that
        // return early — a contra emptied back out has to take the guard down
        // with it, or a half-filled voucher stays postable.
        form.setAttribute('data-balanced', (ready && !same) ? '1' : '0');

        preview.hidden = !ready;
        if (!ready) { return; }
        preview.querySelector('[data-preview-debit]').textContent = toName;
        preview.querySelector('[data-preview-credit]').textContent = fromName;
        preview.querySelectorAll('[data-preview-amount]').forEach(function (node) {
            node.textContent = window.vchMoney(value);
        });
        preview.classList.toggle('is-invalid', same);
        preview.querySelector('.vch-preview-label').textContent = same
            ? 'Both sides name the same account — money cannot move to where it already is'
            : 'This will post';
    }

    [from, to, amount].forEach(function (field) {
        field.addEventListener('change', refresh);
        field.addEventListener('input', refresh);
    });
    refresh();
});
</script>
