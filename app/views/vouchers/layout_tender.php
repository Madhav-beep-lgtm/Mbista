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
<section class="mbw-card frm-section vch-focus">
    <div class="frm-section-head">
        <span class="mbw-chip is-square tone-<?= e((string) $spec['tone']) ?>"><?= icon($isMoneyIn ? 'trend-up' : 'trend-down') ?></span>
        <h2><?= e((string) $spec['bank_label']) ?></h2>
        <span class="frm-optional"><?= e((string) $spec['bank_hint']) ?></span>
    </div>

    <div class="vch-grid" data-grid data-min-rows="1" data-prefill="<?= e(json_encode($prefill['tender'] ?? [], JSON_UNESCAPED_SLASHES)) ?>">
        <div style="overflow-x:auto">
            <table class="frm-entries vch-table">
                <thead>
                    <tr>
                        <th style="width:36px">SN</th>
                        <th style="width:200px">Cash / bank account <em>*</em></th>
                        <th style="width:150px">Mode</th>
                        <th>Cheque / transaction no.</th>
                        <th class="is-numeric" style="width:150px"><?= $isMoneyIn ? 'Received' : 'Paid' ?> (<?= e(trim($currency)) ?>) <em>*</em></th>
                        <th style="width:40px"></th>
                    </tr>
                </thead>
                <tbody data-rows></tbody>
            </table>
        </div>
        <div class="frm-entries-foot">
            <button type="button" class="button soft" data-add-row>＋ Add another <?= $isMoneyIn ? 'receipt' : 'payment' ?> mode</button>
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
                <td>
                    <select name="tender_mode[]" data-field="mode">
                        <?php foreach (voucher_instrument_modes() as $modeKey => $modeLabel): ?>
                            <option value="<?= e($modeKey) ?>"><?= e($modeLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" name="tender_instrument_no[]" data-field="instrument_no" maxlength="80" placeholder="Cheque / txn reference"></td>
                <td class="is-numeric"><input type="number" name="tender_amount[]" data-field="amount" class="frm-num vch-amount" step="0.01" min="0" placeholder="0.00"></td>
                <td><button type="button" class="frm-del" data-remove-row aria-label="Remove this line">&#128465;</button></td>
            </tr>
        </template>
    </div>
</section>

<section class="mbw-card frm-section">
    <div class="frm-section-head">
        <span class="mbw-chip is-square tone-green"><?= icon('clients') ?></span>
        <h2><?= e((string) $spec['party_label']) ?></h2>
        <span class="frm-optional">Optional — pick the party and their ledger drops into the first free line below</span>
    </div>
    <div class="frm-grid frm-grid-4">
        <label><?= e((string) $spec['party_label']) ?>
            <select name="party_id" id="vch-party">
                <option value="0">Not against a party</option>
                <?php foreach ($partyOptions as $party): ?>
                    <option value="<?= (int) $party['id'] ?>"
                            data-ledger="<?= (int) $party['side_ledger_id'] ?>"
                            data-type="<?= e(ucfirst((string) $party['party_type'])) ?>"
                            <?= (int) ($prefill['party_id'] ?? 0) === (int) $party['id'] ? 'selected' : '' ?>><?= e((string) $party['name']) ?> (<?= e((string) $party['code']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Party type<input type="text" id="vch-party-type" value="" placeholder="—" disabled></label>
        <label>Instrument date<input type="date" name="instrument_date" value="<?= e((string) ($prefill['instrument_date'] ?? '')) ?>"></label>
    </div>
</section>

<section class="mbw-card frm-section">
    <div class="frm-section-head">
        <span class="mbw-chip is-square tone-blue"><?= icon('layers') ?></span>
        <h2><?= e((string) $spec['lines_label']) ?></h2>
        <span class="frm-optional"><?= e((string) $spec['lines_hint']) ?></span>
    </div>

    <div class="vch-grid" data-grid data-min-rows="1" data-prefill="<?= e(json_encode($prefill['lines'] ?? [], JSON_UNESCAPED_SLASHES)) ?>">
        <div style="overflow-x:auto">
            <table class="frm-entries vch-table" id="vch-lines-table">
                <thead>
                    <tr>
                        <th style="width:36px">SN</th>
                        <th style="width:220px">Ledger <em>*</em></th>
                        <th>Description</th>
                        <th style="width:150px">Cost centre</th>
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
                <td>
                    <select name="line_cost_centre[]" data-field="cost_centre">
                        <option value="">—</option>
                        <?php foreach ($costCentres as $costCentre): ?><option value="<?= e($costCentre) ?>"><?= e($costCentre) ?></option><?php endforeach; ?>
                    </select>
                </td>
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

        var display = document.getElementById('vch-display-total');
        if (display) { display.value = window.vchMoney(tender); }

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

    // Choosing a party fills the first empty particulars line with their own
    // ledger — the step everybody takes next, done for them. A line already
    // filled in is never overwritten.
    var partySelect = document.getElementById('vch-party');
    if (partySelect) {
        var applyParty = function (fillLine) {
            var option = partySelect.options[partySelect.selectedIndex];
            document.getElementById('vch-party-type').value = (option && option.getAttribute('data-type')) || '';
            var ledgerId = option ? option.getAttribute('data-ledger') : '';
            if (!fillLine || !ledgerId || ledgerId === '0') { return; }
            var empty = null;
            document.querySelectorAll('.vch-line-ledger').forEach(function (select) {
                if (empty === null && select.value === '') { empty = select; }
            });
            if (empty && empty.querySelector('option[value="' + ledgerId + '"]')) {
                empty.value = ledgerId;
                recalc();
            }
        };
        partySelect.addEventListener('change', function () { applyParty(true); });
        applyParty(false);
    }
});
</script>
