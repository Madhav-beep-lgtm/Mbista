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
<section class="mbw-card frm-section vch-focus vch-work-section vch-money-section">
    <div class="frm-section-head">
        <span class="mbw-chip is-square tone-<?= e((string) $spec['tone']) ?>"><?= icon($isMoneyIn ? 'trend-up' : 'trend-down') ?></span>
        <h2><?= e((string) $spec['bank_label']) ?></h2>
        <span class="frm-optional"><?= e((string) $spec['bank_hint']) ?></span>
    </div>

    <div class="vch-grid" data-grid data-min-rows="1" data-prefill="<?= e(json_encode($prefill['tender'] ?? [], JSON_UNESCAPED_SLASHES)) ?>">
        <div class="mbw-tablewrap">
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

<section class="mbw-card frm-section vch-work-section vch-party-section">
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
        <?php // Which bill this settles. A payment recorded against the party
              // alone can be traced no further than the party: telling which
              // invoice it cleared means reading narrations and guessing. The
              // list is whatever bills that party has on record, and it narrows
              // as soon as a party is chosen. ?>
        <label>Against bill
            <select name="reference_no" id="vch-bill" data-selected="<?= e((string) ($prefill['reference_no'] ?? '')) ?>">
                <option value="">Not against a specific bill</option>
            </select>
            <span class="frm-optional" id="vch-bill-note">Choose a party to see their bills</span>
        </label>
        <label>Instrument date<input type="date" name="instrument_date" value="<?= e((string) ($prefill['instrument_date'] ?? '')) ?>"></label>
    </div>
</section>

<section class="mbw-card frm-section vch-work-section vch-against-section">
    <div class="frm-section-head">
        <span class="mbw-chip is-square tone-blue"><?= icon('layers') ?></span>
        <h2><?= e((string) $spec['lines_label']) ?></h2>
        <span class="frm-optional"><?= e((string) $spec['lines_hint']) ?></span>
    </div>

    <div class="vch-grid" data-grid data-min-rows="1" data-prefill="<?= e(json_encode($prefill['lines'] ?? [], JSON_UNESCAPED_SLASHES)) ?>">
        <div class="mbw-tablewrap">
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
        // The bill list is rebuilt for the chosen party rather than rendered
        // once and filtered: a company with hundreds of bills would otherwise
        // ship every one of them into every payment screen.
        var billsByParty = <?= json_encode($partyBills ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var billSelect = document.getElementById('vch-bill');
        var billNote = document.getElementById('vch-bill-note');
        var applyBills = function () {
            if (!billSelect) { return; }
            var keep = billSelect.value || billSelect.getAttribute('data-selected') || '';
            var bills = billsByParty[partySelect.value] || [];
            billSelect.innerHTML = '';
            var none = document.createElement('option');
            none.value = '';
            none.textContent = 'Not against a specific bill';
            billSelect.appendChild(none);
            bills.forEach(function (bill) {
                var option = document.createElement('option');
                option.value = bill.ref;
                option.textContent = bill.label;
                if (bill.ref === keep) { option.selected = true; }
                billSelect.appendChild(option);
            });
            // A bill already on this voucher stays on it even when it is not in
            // the list any more, so editing an old payment never drops the
            // reference somebody recorded.
            if (keep !== '' && billSelect.value !== keep) {
                var kept = document.createElement('option');
                kept.value = keep;
                kept.textContent = keep + ' (on this voucher)';
                kept.selected = true;
                billSelect.appendChild(kept);
            }
            if (billNote) {
                billNote.textContent = partySelect.value === '0' || partySelect.value === ''
                    ? 'Choose a party to see their bills'
                    : (bills.length === 0 ? 'No bills on record for this party' : bills.length + ' bill(s) on record');
            }
        };

        // With a party chosen, the line ledgers narrow to that party's own
        // ledgers plus the cash and bank accounts money actually moves through -
        // a payment settles a party from a bank, and nothing else belongs on it.
        // With no party chosen the full chart is offered, exactly as before.
        //
        // The list is rebuilt rather than having options hidden: hiding an
        // <option> is honoured by some browsers and quietly ignored by others,
        // which would leave a "filtered" list that could still be used to pick a
        // ledger that was supposed to be out of scope.
        var ledgerCatalog = <?= json_encode($vchLedgerCatalog ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var partyLedgers = <?= json_encode($vchPartyLedgers ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        var applyLedgerScope = function () {
            var own = partyLedgers[partySelect.value] || null;
            var narrowed = partySelect.value !== '' && partySelect.value !== '0';
            document.querySelectorAll('.vch-line-ledger').forEach(function (select) {
                var keep = select.value;
                var group = null;
                var frag = document.createDocumentFragment();
                var blank = document.createElement('option');
                blank.value = '';
                blank.textContent = 'Select ledger';
                frag.appendChild(blank);
                var shown = 0;
                ledgerCatalog.forEach(function (ledger) {
                    if (narrowed) {
                        var isOwn = own && own.indexOf(ledger.id) !== -1;
                        if (!isOwn && ledger.cash_bank !== 1) { return; }
                    }
                    if (group === null || group.label !== ledger.group) {
                        group = document.createElement('optgroup');
                        group.label = ledger.group;
                        frag.appendChild(group);
                    }
                    var option = document.createElement('option');
                    option.value = String(ledger.id);
                    option.textContent = ledger.label;
                    if (String(ledger.id) === keep) { option.selected = true; }
                    group.appendChild(option);
                    shown++;
                });
                // A ledger already on the line stays on it even when the narrowed
                // list would not offer it, so switching party on a part-typed
                // voucher never silently discards a line somebody entered.
                select.innerHTML = '';
                select.appendChild(frag);
                if (keep !== '' && select.value !== keep) {
                    var kept = document.createElement('option');
                    kept.value = keep;
                    kept.textContent = 'Already on this line';
                    kept.selected = true;
                    select.appendChild(kept);
                }
                // searchable-select reads the options each time it opens, but its
                // visible box is only re-synced on change.
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
        };

        partySelect.addEventListener('change', function () {
            applyLedgerScope();
            applyParty(true);
            applyBills();
        });
        applyParty(false);
        applyBills();
        applyLedgerScope();
    }
});
</script>
