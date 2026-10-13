<?php
declare(strict_types=1);

/**
 * Payment and receipt — the two screens where cash actually moves.
 *
 * Tally calls this single-entry mode: name the bank once, then list what the
 * money was for. The one departure here is that the bank side is a grid rather
 * than a single field, because a real settlement is often mixed — part cash,
 * part transfer, part adjustment — and squeezing that into one row would make
 * the day book lie about how the money arrived.
 *
 * Expects $spec (the type), $optionsCashBank, $optionsAll, $partyOptions.
 */
$bankSide = (string) $spec['bank_side'];
$isMoneyIn = $bankSide === 'debit';
?>
<section class="mbw-card frm-section vch-focus vch-work-section vch-money-section vch-entry-panel">
    <div class="frm-section-head">
        <span class="mbw-chip is-square tone-<?= e((string) $spec['tone']) ?>"><?= icon($isMoneyIn ? 'trend-up' : 'trend-down') ?></span>
        <h2><?= e((string) $spec['bank_label']) ?></h2>
    </div>

    <div class="vch-grid" data-grid data-min-rows="1" data-prefill="<?= e(json_encode($prefill['tender'] ?? [], JSON_UNESCAPED_SLASHES)) ?>">
        <div class="mbw-tablewrap">
            <table class="frm-entries vch-table">
                <thead>
                    <tr>
                        <th style="width:36px">SN</th>
                        <th style="width:200px">Cash / bank account <em>*</em></th>
                        <th>Cheque / transaction no.</th>
                        <th class="is-numeric" style="width:150px"><?= $isMoneyIn ? 'Received' : 'Paid' ?> (<?= e(trim($currency)) ?>) <em>*</em></th>
                        <th style="width:40px"></th>
                    </tr>
                </thead>
                <tbody data-rows></tbody>
            </table>
        </div>
        <div class="frm-entries-foot">
            <button type="button" class="button soft" data-add-row>＋ Add row</button>
            <div class="frm-entry-totals">
                <span><?= e((string) $spec['bank_label']) ?> (<?= e(trim($currency)) ?>) <strong id="vch-tender-total">0.00</strong></span>
            </div>
        </div>
        <template data-row-template>
            <tr>
                <td data-sn>1</td>
                <td>
                    <select name="tender_ledger[]" data-field="ledger_id" class="vch-ledger">
                        <option value="">Select cash / bank</option>
                        <?= $renderLedgerOptions($optionsCashBank, 0) ?>
                    </select>
                </td>
                <td><input type="text" name="tender_instrument_no[]" data-field="instrument_no" maxlength="80" placeholder="Cheque / txn reference"></td>
                <td class="is-numeric"><input type="number" name="tender_amount[]" data-field="amount" class="frm-num vch-amount" step="0.01" min="0" placeholder="0.00"></td>
                <td><button type="button" class="frm-del" data-remove-row aria-label="Remove this line">&#128465;</button></td>
            </tr>
        </template>
    </div>
</section>

<section class="mbw-card frm-section vch-work-section vch-against-section vch-entry-panel">
    <div class="frm-section-head">
        <span class="mbw-chip is-square tone-blue"><?= icon('layers') ?></span>
        <h2><?= e((string) $spec['lines_label']) ?></h2>
    </div>

    <div class="vch-grid" data-grid data-min-rows="1" data-prefill="<?= e(json_encode($prefill['lines'] ?? [], JSON_UNESCAPED_SLASHES)) ?>">
        <div class="mbw-tablewrap">
            <table class="frm-entries vch-table" id="vch-lines-table">
                <thead>
                    <tr>
                        <th style="width:36px">SN</th>
                        <th style="width:220px">Ledger <em>*</em></th>
                        <th>Description</th>
                        <th style="width:140px">Bill / reference</th>
                        <th class="is-numeric" style="width:150px">Amount (<?= e(trim($currency)) ?>) <em>*</em></th>
                        <th style="width:40px"></th>
                    </tr>
                </thead>
                <tbody data-rows></tbody>
            </table>
        </div>
        <div class="frm-entries-foot">
            <button type="button" class="button soft" data-add-row>＋ Add line</button>
            <div class="frm-entry-totals">
                <span><?= e((string) $spec['lines_label']) ?> (<?= e(trim($currency)) ?>) <strong id="vch-lines-total">0.00</strong></span>
                <span class="mbw-pill tone-gray" id="vch-match-pill">Enter lines</span>
            </div>
        </div>
        <template data-row-template>
            <tr>
                <td data-sn>1</td>
                <td>
                    <select name="line_ledger[]" data-field="ledger_id" class="vch-ledger vch-line-ledger">
                        <option value="">Select ledger</option>
                        <?= $renderLedgerOptions($optionsAll, 0) ?>
                    </select>
                </td>
                <td><input type="text" name="line_memo[]" data-field="memo" maxlength="255" placeholder="What this line is for"></td>
                <td><input type="text" name="line_reference[]" data-field="reference" maxlength="120" placeholder="Invoice / bill"></td>
                <td class="is-numeric"><input type="number" name="line_amount[]" data-field="amount" class="frm-num vch-amount" step="0.01" min="0" placeholder="0.00"></td>
                <td><button type="button" class="frm-del" data-remove-row aria-label="Remove this line">&#128465;</button></td>
            </tr>
        </template>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('voucher-form');
    var tenderTotalNode = document.getElementById('vch-tender-total');
    var linesTotalNode = document.getElementById('vch-lines-total');
    var pill = document.getElementById('vch-match-pill');

    function sum(selector) {
        var total = 0;
        document.querySelectorAll(selector).forEach(function (input) {
            total += Number(input.value) || 0;
        });
        return total;
    }

    function recalc() {
        var tender = sum('input[name="tender_amount[]"]');
        var lines = sum('input[name="line_amount[]"]');
        tenderTotalNode.textContent = window.vchMoney(tender);
        linesTotalNode.textContent = window.vchMoney(lines);

        if (tender === 0 && lines === 0) {
            pill.textContent = 'Enter lines';
            pill.className = 'mbw-pill tone-gray';
        } else if (Math.abs(tender - lines) < 0.005) {
            pill.textContent = '✓ Both sides agree';
            pill.className = 'mbw-pill tone-green';
        } else {
            pill.textContent = 'Off by ' + window.vchMoney(Math.abs(tender - lines));
            pill.className = 'mbw-pill tone-red';
        }
        form.setAttribute('data-balanced', (tender > 0 && Math.abs(tender - lines) < 0.005) ? '1' : '0');
    }

    form.addEventListener('vch:change', recalc);
    form.addEventListener('input', recalc);
    recalc();

    // The visible party selector was redundant: choosing a bill already tells
    // us both the party and its posting ledger. Keep that relationship in the
    // submitted metadata and fill the first free "Paid towards" line without
    // asking for the same party twice.
    var partyField = document.getElementById('vch-party');
    var billSelect = document.getElementById('vch-bill');
    var partyLedgers = <?= json_encode($vchPartyLedgers ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var partyByLedger = {};
    Object.keys(partyLedgers).forEach(function (partyId) {
        partyLedgers[partyId].forEach(function (ledgerId) {
            if (!partyByLedger[String(ledgerId)]) { partyByLedger[String(ledgerId)] = String(partyId); }
        });
    });

    function fillPartyLedger(ledgerId) {
        if (!ledgerId || ledgerId === '0') { return; }
        var empty = null;
        document.querySelectorAll('.vch-line-ledger').forEach(function (select) {
            if (empty === null && select.value === '') { empty = select; }
        });
        if (empty && empty.querySelector('option[value="' + ledgerId + '"]')) {
            empty.value = ledgerId;
            empty.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function applyBill(fillLine) {
        if (!billSelect || !partyField) { return; }
        var option = billSelect.options[billSelect.selectedIndex];
        var partyId = option ? (option.getAttribute('data-party') || '0') : '0';
        var ledgerId = option ? (option.getAttribute('data-ledger') || '') : '';
        partyField.value = partyId;
        if (fillLine) { fillPartyLedger(ledgerId); }
    }

    function derivePartyFromLines() {
        if (!partyField || (billSelect && billSelect.value !== '')) { return; }
        var derived = '0';
        document.querySelectorAll('.vch-line-ledger').forEach(function (select) {
            if (derived === '0' && partyByLedger[String(select.value)]) {
                derived = partyByLedger[String(select.value)];
            }
        });
        partyField.value = derived;
    }

    if (billSelect) {
        billSelect.addEventListener('change', function () { applyBill(true); });
        applyBill(false);
    }
    form.addEventListener('change', function (event) {
        if (event.target && event.target.classList.contains('vch-line-ledger')) {
            derivePartyFromLines();
        }
    });
});
</script>
