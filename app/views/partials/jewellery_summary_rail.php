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
            total: money(total)
        };
        Object.keys(out).forEach(function (key) {
            var cell = rail.querySelector('[data-jw-sum="' + key + '"]');
            if (cell) { cell.textContent = out[key]; }
        });
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
