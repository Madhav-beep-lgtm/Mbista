<?php
declare(strict_types=1);

/**
 * The jewellery line-entry grid, shared by the purchase form, the sale form
 * and the order form.
 *
 * It lives here rather than inside one of those pages because the three have
 * to agree: an order is quoted by the same engine that bills the sale, so a
 * column added on one and missed on another is a quote that cannot become an
 * invoice. One grid, one set of field names, one place to change them.
 *
 * $prefix decides which columns appear and what the fields are called:
 *     l   full line — every column (purchases, sales, orders)
 *     x   exchange line — old gold taken in, so no wastage and no charges
 *
 * $ctx carries what the grid needs from the page: items, purities, units,
 * base_unit, on_hand (item id => balance row) and fmt (a number formatter).
 */

/** Emit the grid stylesheet. Safe to call more than once per request. */
function jw_line_grid_styles(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<style>
/* The line grid carries up to eighteen columns. Squeezing all of them into the
   viewport is what turned "GOLD·24K" into "GOL" and a date box into three grey
   slivers: `width: 100%` with `table-layout: fixed` treats the column widths
   below as PROPORTIONS and scales them down to whatever room is left.

   So the table takes its natural width instead — the sum of the widths below,
   each wide enough to read the control inside it — and anything past the edge
   of the screen is reached with the scrollbar. min-width keeps it filling the
   card on a wide screen rather than sitting stubby on one side.

   The scroller is what keeps that inside the card instead of pushing the page
   sideways: the table may be wider than the screen, but the SCROLLER never is,
   so the slider belongs to the grid and the page itself never moves. */
.jw-lines-scroll {
    overflow-x: auto;
    max-width: 100%;
    /* On a trackpad the bar only appears mid-gesture, which is how a column
       past the edge goes unnoticed. Keeping the gutter reserved means the grid
       always looks scrollable. */
    scrollbar-gutter: stable;
    padding-bottom: 2px;
}
/* Design tokens, not fixed colours: these flip with the theme, and a pale grey
   bar painted on a dark page is the sort of thing nobody notices until a user
   reports the grid "looks broken at night". */
.jw-lines-scroll::-webkit-scrollbar { height: 12px; }
.jw-lines-scroll::-webkit-scrollbar-track { background: var(--mbw-border-soft, #eef2f6); border-radius: 6px; }
.jw-lines-scroll::-webkit-scrollbar-thumb { background: var(--mbw-muted, #9fb3c4); border-radius: 6px; }
.jw-lines-scroll::-webkit-scrollbar-thumb:hover { background: var(--mbw-primary, #7d95a9); }
table.jw-lines { font-size: .85rem; table-layout: fixed; width: auto; min-width: 100%; }
table.jw-lines th,
table.jw-lines td { padding: 3px 4px; }
table.jw-lines thead th { font-size: .74rem; line-height: 1.2; text-align: center; white-space: nowrap; }
table.jw-lines input,
table.jw-lines select {
    width: 100%;
    min-width: 0;
    min-height: 32px;
    padding: 3px 6px;
    font-size: .85rem;
}
table.jw-lines input[type="number"] { text-align: right; }
/* Number spinners eat about 16px of every cell and are useless for a weight
   typed to four decimals. */
table.jw-lines input[type="number"] { -moz-appearance: textfield; }
table.jw-lines input[type="number"]::-webkit-outer-spin-button,
table.jw-lines input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
/* Each width is what the WIDEST thing that column holds needs to be read:
   "GOLD·24K" for a purity, four decimal places for a weight, a whole date for
   the promise. Nothing here is a guess about how much room is left over.

   They are applied through the table's <colgroup>, which is the only place
   table-layout:fixed reads them from when the header has colspans in its first
   row — as this one does. */
table.jw-lines .c-item { width: 230px; }
table.jw-lines .c-sel  { width: 110px; }
table.jw-lines .c-unit { width: 88px; }
table.jw-lines .c-pcs  { width: 68px; }
table.jw-lines .c-wt   { width: 92px; }
table.jw-lines .c-rate { width: 118px; }
table.jw-lines .c-crt  { width: 80px; }
table.jw-lines .c-amt  { width: 108px; }
/* Order grids only: which kaligad makes this piece and when it is promised. */
table.jw-lines .c-krg  { width: 104px; }
table.jw-lines .c-date { width: 152px; }
table.jw-lines .c-del  { width: 38px; text-align: center; }
table.jw-lines td.c-del button {
    width: 24px; min-height: 24px; padding: 0; line-height: 1;
    border: 1px solid var(--mbw-border, #d9e2ec); border-radius: 4px;
    background: transparent; cursor: pointer; color: var(--mbw-red, #e5484d);
}
table.jw-lines td.c-del button:hover { background: var(--mbw-red-soft, #fdeaea); }
.jw-lines-actions { display: flex; gap: 8px; align-items: center; margin-top: 8px; }

/* Fields sit in a row and wrap to the next, and every box lines up with its
   neighbours whatever sits under it. Without the label being a flex column with
   the control pushed to the bottom, one field carrying a note under it drags
   its own box upward and the row reads as a staircase. */
.workspace-form-grid > label {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.workspace-form-grid > label > input,
.workspace-form-grid > label > select,
.workspace-form-grid > label > textarea { margin-top: auto; }
.workspace-form-grid > label > .frm-optional { order: 3; margin-top: 4px; }
</style>
    <?php
}

function jw_render_line_grid(string $prefix, array $existing, int $slots, string $legend, array $ctx): void
{
    $items = $ctx['items'] ?? [];
    $purities = $ctx['purities'] ?? [];
    $units = $ctx['units'] ?? [];
    $baseUnit = $ctx['base_unit'] ?? null;
    $onHand = $ctx['on_hand'] ?? [];
    $fmt = $ctx['fmt'] ?? static fn (?float $n, int $p = 2): string => $n === null ? 'N/A' : number_format($n, $p);
    // Orders only. Kaligads specialise — the one who makes chains does not set
    // stones — so each item on an order goes to its own craftsman and carries
    // its own promised date. A sale or a purchase has neither, so the two
    // columns appear only when the page hands over a kaligad list.
    $karigars = $ctx['karigars'] ?? null;
    $withWorkshop = is_array($karigars);
    ?>
    <?php $full = $prefix === 'l'; ?>
    <fieldset class="jw-lines-box" style="border:1px solid var(--mbw-border,#d9e2ec);border-radius:10px;padding:10px;margin:12px 0;min-width:0">
        <legend style="padding:0 6px;font-weight:600"><?= $legend ?></legend>
        <div class="jw-lines-scroll"><table class="jw-lines">
            <?php
                // The widths live here rather than on the header cells. Under
                // table-layout:fixed only the FIRST row sets column widths, and
                // the first row of this header is full of colspans — Weight,
                // Diamond, Workshop — so the widths written on the SECOND row
                // were being ignored and those columns collapsed to whatever
                // was left. A colgroup addresses the real columns directly.
                $cols = ['c-item', 'c-sel', 'c-unit', 'c-pcs', 'c-wt', 'c-wt'];
                if ($prefix !== 'x') {
                    $cols[] = 'c-wt';
                    $cols[] = 'c-wt';
                }
                $cols[] = 'c-rate';
                if ($full) {
                    $cols[] = 'c-amt';
                    foreach ([1, 2, 3] as $stoneColumn) {
                        $cols[] = 'c-crt';
                        $cols[] = 'c-amt';
                    }
                }
                if ($withWorkshop) {
                    $cols[] = 'c-krg';
                    $cols[] = 'c-date';
                }
                $cols[] = 'c-del';
            ?>
            <colgroup>
                <?php foreach ($cols as $colClass): ?><col class="<?= e($colClass) ?>"><?php endforeach; ?>
            </colgroup>
            <thead>
                <tr>
                    <th rowspan="2" class="c-item">Item</th>
                    <th rowspan="2" class="c-sel">Purity</th>
                    <th rowspan="2" class="c-unit">Unit</th>
                    <th rowspan="2" class="c-pcs">Pcs</th>
                    <th colspan="<?= $prefix === 'x' ? 2 : 4 ?>">Weight</th>
                    <th rowspan="2" class="c-rate">Rate</th>
                    <?php if ($full): ?>
                        <th rowspan="2" class="c-amt">Making</th>
                        <th colspan="2">Diamond</th>
                        <th colspan="2">Other diamond</th>
                        <th colspan="2">Stone</th>
                    <?php endif; ?>
                    <?php if ($withWorkshop): ?>
                        <th colspan="2">Workshop</th>
                    <?php endif; ?>
                    <th rowspan="2" class="c-del"></th>
                </tr>
                <tr>
                    <th class="c-wt">Gross</th>
                    <th class="c-wt">Less</th>
                    <?php if ($prefix !== 'x'): ?><th class="c-wt">Wast %</th><th class="c-wt">Wast wt</th><?php endif; ?>
                    <?php if ($full): ?>
                        <th class="c-crt">Crt</th><th class="c-amt">Amt</th>
                        <th class="c-crt">Crt</th><th class="c-amt">Amt</th>
                        <th class="c-crt">Crt</th><th class="c-amt">Amt</th>
                    <?php endif; ?>
                    <?php if ($withWorkshop): ?>
                        <th class="c-krg">Kaligad</th><th class="c-date">Promised</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php for ($i = 0; $i < $slots; $i++): $row = $existing[$i] ?? null; ?>
                <tr>
                    <td>
                        <?php
                            // Which stored line this row IS. Position is not
                            // identity — two rows can hold the same item, and
                            // rows get reordered — so a revision says so
                            // explicitly. It sits INSIDE the cell because a bare
                            // input between <tr> and <td> is hoisted out of the
                            // table by every browser, and would then post out of
                            // step with the rest of the row.
                        ?>
                        <input type="hidden" name="<?= $prefix ?>_line_id[]" value="<?= (int) ($row['id'] ?? 0) ?>">
                        <select name="<?= $prefix ?>_item_id[]" class="c-item">
                            <option value="0">—</option>
                            <?php foreach ($items as $it): ?>
                                <?php
                                    // What is actually left, shown on the option itself: the
                                    // shop needs to know before it commits the line, not after
                                    // the negative-stock guard refuses it. It rides in the
                                    // title too, because the closed select is narrow now and
                                    // the tail of a long option would be cut off.
                                    $stock = $onHand[(int) $it['id']] ?? null;
                                    $left = $stock
                                        ? ' · ' . $fmt((float) $stock['qty_pieces'], 0) . 'pc '
                                            . $fmt((float) $stock['fine_weight'], 3) . ' fine'
                                        : '';
                                ?>
                                <option value="<?= (int) $it['id'] ?>" title="<?= e($it['code'] . ' — ' . $it['name'] . $left) ?>" <?= (int) ($row['item_id'] ?? 0) === (int) $it['id'] ? 'selected' : '' ?>><?= e($it['code'] . ' — ' . $it['name'] . $left) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="<?= $prefix ?>_purity_id[]">
                            <?php foreach ($purities as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" <?= (int) ($row['purity_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['metal_code'] . '·' . $p['code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="<?= $prefix ?>_unit_id[]">
                            <?php foreach ($units as $u): ?>
                                <option value="<?= (int) $u['id'] ?>" <?= (int) ($row['unit_id'] ?? (int) ($baseUnit['id'] ?? 0)) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="number" name="<?= $prefix ?>_qty_pieces[]" step="0.001" min="0" value="<?= e((string) ($row['qty_pieces'] ?? '0')) ?>"></td>
                    <td><input type="number" name="<?= $prefix ?>_gross_weight[]" step="0.0001" min="0" value="<?= e((string) ($row['gross_weight'] ?? '0')) ?>"></td>
                    <td><input type="number" name="<?= $prefix ?>_stone_weight[]" class="jw-stone-wt" step="0.0001" min="0" value="<?= e((string) ($row['stone_weight'] ?? '0')) ?>"></td>
                    <?php if ($prefix !== 'x'): ?>
                        <td><input type="number" name="<?= $prefix ?>_wastage_pct[]" class="jw-wastage-pct" step="0.001" min="0" value="<?= e((string) ($row['wastage_pct'] ?? '0')) ?>"></td>
                        <td><input type="number" name="<?= $prefix ?>_wastage_weight[]" class="jw-wastage-wt" step="0.0001" min="0" value="<?= e((string) ($row['wastage_weight'] ?? '0')) ?>"></td>
                    <?php endif; ?>
                    <td><input type="number" name="<?= $prefix ?>_rate[]" step="0.0001" min="0" value="<?= e((string) ($row['rate'] ?? '0')) ?>"></td>
                    <?php if ($full): ?>
                        <td><input type="number" name="<?= $prefix ?>_making_amount[]" step="0.01" min="0" value="<?= e((string) ($row['making_amount'] ?? '0')) ?>"></td>
                        <td><input type="number" name="<?= $prefix ?>_diamond_carat[]" step="0.001" min="0" value="<?= e((string) ($row['diamond_carat'] ?? '0')) ?>"></td>
                        <td><input type="number" name="<?= $prefix ?>_diamond_amount[]" step="0.01" min="0" value="<?= e((string) ($row['diamond_amount'] ?? '0')) ?>"></td>
                        <td><input type="number" name="<?= $prefix ?>_other_diamond_carat[]" step="0.001" min="0" value="<?= e((string) ($row['other_diamond_carat'] ?? '0')) ?>"></td>
                        <td><input type="number" name="<?= $prefix ?>_other_diamond_amount[]" step="0.01" min="0" value="<?= e((string) ($row['other_diamond_amount'] ?? '0')) ?>"></td>
                        <td><input type="number" name="<?= $prefix ?>_stone_carat[]" step="0.0001" min="0" value="<?= e((string) ($row['stone_carat'] ?? '0')) ?>"></td>
                        <td><input type="number" name="<?= $prefix ?>_stone_amount[]" step="0.01" min="0" value="<?= e((string) ($row['stone_amount'] ?? '0')) ?>"></td>
                    <?php endif; ?>
                    <?php if ($withWorkshop): ?>
                        <?php
                            // Once metal is out with a kaligad the pair is fixed:
                            // the issue was measured against THIS craftsman and
                            // THIS date, and the receipt settles his wage and his
                            // wastage against it.
                            $issued = (int) ($row['assignment_id'] ?? 0) > 0;
                        ?>
                        <td>
                            <select name="<?= $prefix ?>_karigar_id[]"<?= $issued ? ' disabled' : '' ?>
                                title="<?= $issued ? e('Metal is already out on issue ' . (string) ($row['issue_no'] ?? '')) : 'Who is to make this piece' ?>">
                                <option value="0">—</option>
                                <?php foreach ($karigars as $k): ?>
                                    <option value="<?= (int) $k['id'] ?>" <?= (int) ($row['karigar_id'] ?? 0) === (int) $k['id'] ? 'selected' : '' ?>><?= e($k['code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($issued): ?>
                                <input type="hidden" name="<?= $prefix ?>_karigar_id[]" value="<?= (int) ($row['karigar_id'] ?? 0) ?>">
                            <?php endif; ?>
                        </td>
                        <td><input type="date" name="<?= $prefix ?>_delivery_date[]" value="<?= e((string) ($row['delivery_date'] ?? '')) ?>"></td>
                    <?php endif; ?>
                    <td class="c-del">
                        <?php // Clearing the item empties the row, and an empty row is ignored on save. ?>
                        <button type="button" class="jw-line-remove" aria-label="Remove this row">&times;</button>
                    </td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table></div>
        <div class="jw-lines-actions">
            <button type="button" class="button secondary jw-line-add" style="min-height:30px;padding:4px 12px">+ Add item</button>
        </div>
    </fieldset>
<?php
}

/**
 * The behaviour behind the grid's own two buttons: add a row, remove a row.
 *
 * A new row is a CLONE of the last one with its values reset, so a column added
 * to the grid appears on added rows too, without a second copy of the markup
 * here to keep in step.
 *
 * Removing takes the whole <tr> out. Every field posts as a parallel array, and
 * pulling the row removes one element from each of them at the same position,
 * so the arrays stay the same length as each other and nothing shifts onto the
 * wrong line. A row whose metal is already out with a kaligad cannot be removed
 * at all — its issue points back at it — and the last row is emptied rather
 * than removed, because a grid with no rows cannot be typed into.
 */
function jw_line_grid_scripts(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<script>
(function () {
    function resetRow(row) {
        Array.prototype.forEach.call(row.querySelectorAll("input, select"), function (field) {
            if (field.disabled) { return; }
            if (field.type === "hidden") { field.value = "0"; return; }
            if (field.tagName === "SELECT") { field.selectedIndex = 0; return; }
            field.value = field.type === "number" ? "0" : "";
        });
    }
    document.addEventListener("click", function (event) {
        var addButton = event.target.closest(".jw-line-add");
        if (addButton) {
            var box = addButton.closest(".jw-lines-box");
            var body = box && box.querySelector("table.jw-lines tbody");
            if (!body || !body.lastElementChild) { return; }
            var clone = body.lastElementChild.cloneNode(true);
            Array.prototype.forEach.call(clone.querySelectorAll("[disabled]"), function (field) {
                field.disabled = false;
            });
            resetRow(clone);
            body.appendChild(clone);
            var firstSelect = clone.querySelector("select");
            if (firstSelect) { firstSelect.focus(); }
            return;
        }
        var removeButton = event.target.closest(".jw-line-remove");
        if (!removeButton) { return; }
        var target = removeButton.closest("tr");
        if (!target) { return; }
        if (target.querySelector("select[disabled]")) { return; }
        var body = target.parentNode;
        if (body && body.children.length > 1) {
            body.removeChild(target);
        } else {
            resetRow(target);
        }
    });
})();
</script>
    <?php
}
