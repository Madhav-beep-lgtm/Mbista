<?php
declare(strict_types=1);
$billAccounts = array_filter($ledgerDirectory, static fn (array $ledger): bool => !empty($ledger['maintain_bill_by_bill']));
$savedBills = json_decode((string) ($editVoucher['bill_details_json'] ?? '{}'), true) ?: [];
if (is_array($retry) && (string) ($retry['type'] ?? '') === $type) {
    $savedBills = (array) ($retry['input']['bill_details'] ?? []);
}
?>
<?php if ($billAccounts !== []): ?>
<dialog id="voucher-bill-dialog" class="vch-bill-dialog" aria-labelledby="voucher-bill-title">
    <form id="voucher-bill-editor">
        <h2 id="voucher-bill-title">Bill details</h2>
        <label>Account
            <select id="voucher-bill-account" required>
                <?php foreach ($billAccounts as $billAccount): ?>
                    <option value="<?= (int) $billAccount['id'] ?>"><?= e((string) $billAccount['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="muted">Enter the total bill amount for this account in this voucher. For repeated account lines, use their combined amount.</p>
        <div class="frm-grid">
            <label>Bill amount <em>*</em><input id="voucher-bill-amount" type="number" min="0.01" step="0.01" required></label>
            <label>Due date <em>*</em><input id="voucher-bill-due" type="date" required></label>
        </div>
        <div class="vch-bill-actions">
            <button type="button" class="button soft" id="voucher-bill-cancel">Cancel</button>
            <button type="submit" class="button">Save bill details</button>
        </div>
    </form>
</dialog>
<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var form = document.getElementById('voucher-form');
    var dialog = document.getElementById('voucher-bill-dialog');
    var account = document.getElementById('voucher-bill-account');
    var amount = document.getElementById('voucher-bill-amount');
    var due = document.getElementById('voucher-bill-due');
    var accounts = <?= json_encode(array_map(static fn (array $ledger): string => (string) $ledger['name'], $billAccounts), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var saved = <?= json_encode((object) $savedBills, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var returnFocus;
    var hidden = document.createElement('div');
    hidden.hidden = true;
    form.appendChild(hidden);
    var toolbar = document.createElement('div');
    toolbar.className = 'vch-bill-actions';
    var manage = document.createElement('button');
    manage.type = 'button';
    manage.className = 'button soft';
    manage.textContent = 'Bill details';
    toolbar.appendChild(manage);
    form.querySelector('.vch-support-card').before(toolbar);

    function writeHidden() {
        hidden.replaceChildren();
        Object.keys(saved).forEach(function (id) {
            if (!accounts[id] || !saved[id]) { return; }
            ['amount', 'due_date'].forEach(function (key) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'bill_details[' + id + '][' + key + ']';
                input.value = saved[id][key] || '';
                hidden.appendChild(input);
            });
        });
    }
    function load() {
        var bill = saved[account.value] || {};
        amount.value = bill.amount || '';
        due.value = bill.due_date || '';
        due.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function open(id, source) {
        returnFocus = source || document.activeElement;
        if (id && accounts[id]) { account.value = id; }
        load();
        dialog.showModal();
        amount.focus();
    }
    function ledgerSelect(element) {
        return element.matches('select') && (/ledger/.test(element.name) || element.dataset.field === 'ledger_id');
    }
    function selectedAccount(element) {
        if (!element.matches('select') || element.disabled || element.closest('[hidden]')) { return ''; }
        if (ledgerSelect(element)) { return element.value; }
        if (element.id === 'vch-party' || element.id === 'vch-bill') {
            return (element.selectedOptions[0] || {}).dataset?.ledger || '';
        }
        return '';
    }
    manage.addEventListener('click', function () { open(null, manage); });
    account.addEventListener('change', load);
    form.addEventListener('change', function (event) {
        var id = selectedAccount(event.target);
        if (accounts[id]) {
            open(id, event.target);
        }
    });
    // Saving the voucher must not bypass an account's required bill information.
    form.addEventListener('submit', function (event) {
        if (event.submitter && event.submitter.value === 'import_purchase_lines') { return; }
        var missing = Array.from(form.querySelectorAll('select')).find(function (select) {
            var id = selectedAccount(select);
            var bill = saved[id];
            return accounts[id] && (!bill || !bill.due_date || !(Number(bill.amount) > 0));
        });
        if (missing) {
            event.preventDefault();
            open(selectedAccount(missing), missing);
        }
    });
    document.getElementById('voucher-bill-editor').addEventListener('submit', function (event) {
        event.preventDefault();
        saved[account.value] = {amount: amount.value, due_date: due.value};
        writeHidden();
        dialog.close();
    });
    document.getElementById('voucher-bill-cancel').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('close', function () { if (returnFocus && returnFocus.isConnected) { returnFocus.focus(); } });
    writeHidden();
});
</script>
<?php endif; ?>
