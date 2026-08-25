<?php
declare(strict_types=1);

/**
 * The summary rail: what the document currently comes to, and the buttons that
 * commit it.
 *
 * The figures are worked out in the BROWSER as the grid is typed into, so the
 * counter sees the total before saving rather than after. That is the only
 * arithmetic in this file, and it is deliberately the same shape as the
 * engine's: net = gross - less, charged weight = net + wastage, amount =
 * charged weight x rate, plus making and the three stone columns.
 *
 * It is a PREVIEW and is labelled as one. The figure that goes in the books is
 * always the one jw_compute_document() produces on save — the server never
 * trusts a number the page sent it, and this panel is not an exception to that.
 */

function jw_summary_rail(array $ctx): void
{
    $sym = (string) ($ctx['currency'] ?? 'NPR');
    $actions = (string) ($ctx['actions'] ?? '');
    $shortcuts = (array) ($ctx['shortcuts'] ?? []);
    $withOldGold = !empty($ctx['with_old_gold']);
    ?>
    <aside class="jw-rail" data-jw-summary>
        <section class="jw-card">
            <div class="jw-card-head"><h2><?= icon('analytics') ?>Summary</h2></div>
            <div class="jw-summary-rows">
                <div class="jw-summary-row"><span>Total items</span><strong data-jw-sum="items">0</strong></div>
                <div class="jw-summary-row"><span>Gross weight</span><strong data-jw-sum="gross">0.000</strong></div>
                <div class="jw-summary-row"><span>Net weight</span><strong data-jw-sum="net">0.000</strong></div>
                <?php // The pure-metal content of what is on the document, shown WITH the
                      // actual weights — a 22K figure and a 24K figure only compare in
                      // fine, and only GRAMS sum honestly across rows in mixed units. ?>
                <div class="jw-summary-row"><span>Fine equivalent (g)</span><strong data-jw-sum="fine">0.000</strong></div>
                <div class="jw-summary-row"><span>Charged weight</span><strong data-jw-sum="charged">0.000</strong></div>
                <div class="jw-summary-row"><span>Metal</span><strong data-jw-sum="metal">0.00</strong></div>
                <div class="jw-summary-row"><span>Making</span><strong data-jw-sum="making">0.00</strong></div>
                <div class="jw-summary-row"><span>Stone / diamond</span><strong data-jw-sum="stone">0.00</strong></div>
                <?php if ($withOldGold): ?>
                    <div class="jw-summary-row"><span>Old gold taken in</span><strong data-jw-sum="oldgold">0.00</strong></div>
                <?php endif; ?>
            </div>
            <div class="jw-summary-total">
                <span>Invoice total (<?= e($sym) ?>)</span>
                <strong data-jw-sum="total">0.00</strong>
            </div>
            <?php if ($withOldGold): ?>
                <?php // What is left to collect, and — when the gold is worth
                      // more than the bill — what the shop owes instead. Both
                      // live under the total because they are the answer to
                      // "and now what changes hands", which is the next thing
                      // the counter needs and the last thing it used to be
                      // told. Each is hidden while it reads zero, so a plain
                      // bill shows a plain total. ?>
                <div class="jw-summary-settle" data-jw-settle hidden>
                    <div class="jw-summary-row" data-jw-row="due" hidden>
                        <span>Customer still to pay</span><strong data-jw-sum="due">0.00</strong>
                    </div>
                    <div class="jw-summary-row is-owed" data-jw-row="excess" hidden>
                        <span data-jw-excess-label>Shop owes (old gold over the bill)</span>
                        <strong data-jw-sum="excess-rail">0.00</strong>
                    </div>
                </div>
            <?php endif; ?>
            <?php
                // Anything that belongs with the money rather than with the
                // items — how it was tendered, for instance. It renders INSIDE
                // the document form, so its fields post with everything else.
                $extra = (string) ($ctx['extra'] ?? '');
            ?>
            <?php if ($extra !== ''): ?>
                <div class="jw-rail-extra"><?= $extra ?></div>
            <?php endif; ?>
            <?php if ($actions !== ''): ?>
                <div class="jw-rail-actions"><?= $actions ?></div>
            <?php endif; ?>
        </section>

        <?php if ($shortcuts !== []): ?>
            <section class="jw-card">
                <div class="jw-card-head"><h2><?= icon('link') ?>Shortcuts</h2></div>
                <nav class="jw-shortcuts">
                    <?php foreach ($shortcuts as $label => $href): ?>
                        <a href="<?= e((string) $href) ?>"><span><?= e((string) $label) ?></span><?= icon('external') ?></a>
                    <?php endforeach; ?>
                </nav>
            </section>
        <?php endif; ?>
    </aside>
    <?php
}

/**
 * The script behind it. Emitted once per request however many rails there are.
 */
function jw_summary_rail_script(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<script>
(function () {
    var rail = document.querySelector('[data-jw-summary]');
    if (!rail) { return; }
    // The rail belongs to the form it is beside. Scoping to that form is what
    // stops a purchase page with two grids on it summing both.
    var form = rail.closest('form') || document.querySelector('form:has(table.jw-lines)') || document;

    function num(row, name) {
        var field = row.querySelector('[name="' + name + '[]"]');
        var value = field ? parseFloat(field.value) : 0;
        return isFinite(value) ? value : 0;
    }

    function money(value) {
        return value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function weight(value) {
        return value.toLocaleString(undefined, { minimumFractionDigits: 3, maximumFractionDigits: 3 });
    }

    function recalc() {
        var totals = { items: 0, gross: 0, net: 0, fine: 0, charged: 0, metal: 0, making: 0, stone: 0, oldgold: 0 };

        form.querySelectorAll('table.jw-lines tbody tr').forEach(function (row) {
            var itemSelect = row.querySelector('select[name$="_item_id[]"]');
            if (!itemSelect || parseInt(itemSelect.value, 10) <= 0) { return; }
            var isExchange = itemSelect.name.indexOf('x_') === 0;
            var prefix = isExchange ? 'x' : 'l';

            var gross = num(row, prefix + '_gross_weight');
            var less = num(row, prefix + '_stone_weight');
            var rate = num(row, prefix + '_rate');
            var net = Math.max(0, gross - less);

            if (isExchange) {
                // Old gold is bought in at gross weight; it REDUCES what the
                // customer pays rather than adding to the document.
                totals.oldgold += gross * rate;
                return;
            }

            var wastageWt = num(row, prefix + '_wastage_weight');
            if (wastageWt <= 0) {
                var wastagePct = num(row, prefix + '_wastage_pct');
                if (wastagePct > 0) { wastageWt = net * wastagePct / 100; }
            }
            var charged = net + wastageWt;
            var stoneSide = num(row, prefix + '_stone_amount')
                + num(row, prefix + '_diamond_amount')
                + num(row, prefix + '_other_diamond_amount');

            totals.items += 1;
            totals.gross += gross;
            totals.net += net;
            // The pure-metal content, reduced to GRAMS: net × fineness ÷ 1000
            // × the row's own unit factor. Rows can carry different units — a
            // 10 g chain beside a 1 tola ring — and their fine weights cannot
            // be added in their own units; grams is the one figure that sums
            // honestly, and it is the same figure the printed bill totals.
            var puritySelect = row.querySelector('select[name="' + prefix + '_purity_id[]"]');
            var chosenPurity = puritySelect && puritySelect.options[puritySelect.selectedIndex];
            var fineness = chosenPurity ? parseFloat(chosenPurity.getAttribute('data-fineness')) : 0;
            var unitSelect = row.querySelector('select[name="' + prefix + '_unit_id[]"]');
            var chosenUnit = unitSelect && unitSelect.options[unitSelect.selectedIndex];
            var unitGrams = chosenUnit ? parseFloat(chosenUnit.getAttribute('data-grams')) : 1;
            if (!isFinite(unitGrams) || unitGrams <= 0) { unitGrams = 1; }
            if (isFinite(fineness) && fineness > 0) { totals.fine += net * fineness / 1000 * unitGrams; }
            totals.charged += charged;
            totals.metal += charged * rate;
            totals.making += num(row, prefix + '_making_amount');
            totals.stone += stoneSide;

            var derived = row.querySelector('[data-jw-derived]');
            if (derived) { derived.textContent = weight(net); }
        });

        var other = form.querySelector('[name="other_charges"]');
        var discount = form.querySelector('[name="discount"]');
        var total = totals.metal + totals.making + totals.stone
            + (other ? (parseFloat(other.value) || 0) : 0)
            - (discount ? (parseFloat(discount.value) || 0) : 0);

        // What actually changes hands. Old gold and cash are both value handed
        // over; when they come to more than the bill, the difference is owed
        // BACK, and the sale cannot be saved until somebody says whether it is
        // kept as the customer's advance or refunded over the counter.
        var receivedField = form.querySelector('[name="received_amount"]');
        var received = receivedField ? (parseFloat(receivedField.value) || 0) : 0;
        var advanceField = form.querySelector('[name="advance_amount"]');
        var advance = advanceField ? (parseFloat(advanceField.value) || 0) : 0;
        var handedOver = totals.oldgold + received;
        var excess = Math.max(0, handedOver - total);
        var due = Math.max(0, total - handedOver - advance);

        var out = {
            items: String(totals.items),
            gross: weight(totals.gross),
            net: weight(totals.net),
            fine: weight(totals.fine),
            charged: weight(totals.charged),
            metal: money(totals.metal),
            making: money(totals.making),
            stone: money(totals.stone),
            oldgold: money(totals.oldgold),
            total: money(total),
            due: money(due),
            excess: money(excess),
            'excess-rail': money(excess)
        };
        Object.keys(out).forEach(function (key) {
            rail.querySelectorAll('[data-jw-sum="' + key + '"]').forEach(function (cell) {
                cell.textContent = out[key];
            });
            // The excess figure is printed twice — in the rail and on the
            // panel that asks what to do with it — and they are the same
            // number, so they are written from the same place.
            document.querySelectorAll('[data-jw-sum="' + key + '"]').forEach(function (cell) {
                cell.textContent = out[key];
            });
        });

        var show = function (node, on) {
            if (!node) { return; }
            if (on) { node.removeAttribute('hidden'); } else { node.setAttribute('hidden', 'hidden'); }
        };
        var hasExcess = excess > 0.004;
        show(rail.querySelector('[data-jw-row="due"]'), due > 0.004);
        show(rail.querySelector('[data-jw-row="excess"]'), hasExcess);
        show(rail.querySelector('[data-jw-settle]'), due > 0.004 || hasExcess);
        show(document.querySelector('[data-jw-excess-panel]'), hasExcess);

        // The label follows the choice, so the rail says what will actually
        // happen rather than the general case.
        var chosen = form.querySelector('[name="excess_mode"]:checked');
        var label = document.querySelector('[data-jw-excess-label]');
        if (label) {
            label.textContent = chosen && chosen.value === 'refund'
                ? 'Refund to customer (old gold over the bill)'
                : 'Held as advance (old gold over the bill)';
        }
        show(document.querySelector('[data-jw-excess-ledger]'), hasExcess && chosen && chosen.value === 'refund');
    }

    form.addEventListener('input', recalc);
    form.addEventListener('change', recalc);
    // A row added or removed changes the total without anyone typing.
    form.addEventListener('click', function () { window.setTimeout(recalc, 0); });
    recalc();
})();
</script>
    <?php
}
