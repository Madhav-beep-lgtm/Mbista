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
/* Order grids only: whether the piece is already on the shelf, which kaligad
   makes it otherwise, when it is promised, what size it is made to, and the
   customer's note for THAT piece. */
table.jw-lines .c-src  { width: 200px; }
table.jw-lines .c-krg  { width: 104px; }
table.jw-lines .c-date { width: 152px; }
table.jw-lines .c-size { width: 96px; }
table.jw-lines .c-note { width: 180px; }
table.jw-lines .c-del  { width: 38px; text-align: center; }
table.jw-lines td.c-del button {
    width: 24px; min-height: 24px; padding: 0; line-height: 1;
    border: 1px solid var(--mbw-border, #d9e2ec); border-radius: 4px;
    background: transparent; cursor: pointer; color: var(--mbw-red, #e5484d);
}
table.jw-lines td.c-del button:hover { background: var(--mbw-red-soft, #fdeaea); }
.jw-lines-actions { display: flex; gap: 8px; align-items: center; margin-top: 8px; }

/* Every control hangs directly under its one-line caption, and anything that
   arrives BELOW a control — above all the Bikram Sambat hint nepali-date.js
   injects under every date input — hangs below it without moving it.

   The previous rule bottom-aligned the controls (margin-top: auto), which
   built the exact staircase it was written to prevent: the injected hint sat
   between a date input and the cell floor and shoved that input UP, an empty
   hint shoved its input up a few pixels, and a plain field sat flush on the
   floor — three different heights across one row. Top-aligned, the hint has
   nothing to shove: it simply occupies the spare depth of the row. */
.workspace-form-grid > label {
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    gap: 5px;
    min-width: 0;
}
.workspace-form-grid > label > input,
.workspace-form-grid > label > select,
.workspace-form-grid > label > textarea { margin-top: 0; }
.workspace-form-grid > label > .bs-date-hint { margin-top: 2px; }
.workspace-form-grid > label > .frm-optional { margin-top: 2px; }

/* ---------------------------------------------------------------------------
   ON A PHONE THE TABLE STOPS BEING A TABLE.
   ---------------------------------------------------------------------------
   Twenty-one columns come to about 1,740px. The scroller above keeps that off
   the page, which is the right answer on a laptop with a narrow window — but on
   a 390px phone it means four and a half screens of sideways dragging PER ROW,
   with the header scrolled out of sight so nothing tells you which box you are
   in. That is not a layout that gets better with smaller type; it is the wrong
   shape.

   So each row becomes a card and every cell carries its own caption, read off
   the data-label the markup sets. Two fields to a line, because a weight box
   does not need the width of a phone, and the genuinely wide things — the item,
   the note, the delete — take the full width.

   The captions come from data-label rather than a second set of <span>s because
   the header already says these words once. Saying them twice in the markup is
   two places to change a column name and one of them to forget. */
@media (max-width: 720px) {
    .jw-lines-scroll { overflow-x: visible; scrollbar-gutter: auto; }
    table.jw-lines,
    table.jw-lines tbody,
    table.jw-lines tr,
    table.jw-lines td { display: block; width: auto; min-width: 0; }
    /* thead and colgroup carry the whole fixed-width layout between them. Both
       have to go, or those widths go on being applied to blocks that no longer
       want them. */
    table.jw-lines thead,
    table.jw-lines colgroup { display: none; }
    table.jw-lines { table-layout: auto; font-size: 1rem; }

    table.jw-lines tr {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px 12px;
        border: 1px solid var(--mbw-border, #d9e2ec);
        border-radius: 12px;
        padding: 12px;
        margin-bottom: 12px;
        background: var(--mbw-card, #fff);
    }
    table.jw-lines td { padding: 0; border: 0; }
    table.jw-lines td::before {
        content: attr(data-label);
        display: block;
        font-size: .7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: var(--mbw-muted, #64748b);
        margin-bottom: 3px;
    }
    /* An empty caption must not leave a gap the height of a line of text. */
    table.jw-lines td[data-label=""]::before { display: none; }

    table.jw-lines td[data-label="Item"],
    table.jw-lines td[data-label="Item note"] { grid-column: 1 / -1; }
    table.jw-lines td.c-del {
        grid-column: 1 / -1;
        display: flex;
        justify-content: flex-end;
        border-top: 1px dashed var(--mbw-border, #d9e2ec);
        padding-top: 8px;
    }
    table.jw-lines td.c-del button { width: auto; padding: 0 12px; min-height: 36px; }
    table.jw-lines td.c-del button::after { content: " Remove"; font-size: .8rem; }

    /* 16px is not a matter of taste. Safari on iOS ZOOMS THE WHOLE PAGE when a
       focused input's text is smaller than 16px, and leaves it zoomed — so
       tapping a weight box threw the layout sideways and put the next box
       off-screen. 44px is the tap target Apple asks for; at .85rem and 32px
       these were neither readable nor reliably hittable with a thumb. */
    table.jw-lines input,
    table.jw-lines select { font-size: 16px; min-height: 44px; padding: 6px 10px; }
    table.jw-lines input[type="number"] { text-align: left; }
    .jw-lines-actions { flex-wrap: wrap; }
    .jw-lines-actions button { min-height: 44px; }
}
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
    // Orders only, again. Not everything a customer orders has to be made: the
    // commonest counter conversation is a customer pointing at a ring in the
    // case. Handing over the Ready to Sale shelf turns on the column that lets
    // them order THAT piece — and a row that names one never goes to a kaligad.
    $stockPieces = $ctx['stock_pieces'] ?? null;
    $withStock = $withWorkshop && is_array($stockPieces);
    ?>
    <?php $full = $prefix === 'l'; ?>
    <fieldset class="jw-lines-box" style="border:1px solid var(--mbw-border,#d9e2ec);border-radius:10px;padding:10px;margin:12px 0;min-width:0">
        <legend style="padding:0 6px;font-weight:600"><?= $legend ?></legend>
        <?php
            // Controls that belong to this grid — load a template, import a
            // sheet — sit on its own header rather than loose on the page, so
            // it is obvious which grid they fill when a sale shows two.
            $headActions = (string) ($ctx['head_actions'] ?? '');
        ?>
        <?php if ($headActions !== ''): ?>
            <div class="jw-grid-toolbar"><?= $headActions ?></div>
        <?php endif; ?>
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
                    if ($withStock) {
                        $cols[] = 'c-src';
                    }
                    $cols[] = 'c-krg';
                    $cols[] = 'c-date';
                    $cols[] = 'c-size';
                    $cols[] = 'c-note';
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
                        <th colspan="<?= $withStock ? 5 : 4 ?>">Workshop</th>
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
                        <?php if ($withStock): ?><th class="c-src">From stock</th><?php endif; ?>
                        <th class="c-krg">Kaligad</th><th class="c-date">Promised</th>
                        <th class="c-size">Size</th><th class="c-note">Item note</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php for ($i = 0; $i < $slots; $i++): $row = $existing[$i] ?? null; ?>
                <?php
                    // A stored line whose piece came off the Ready to Sale shelf.
                    // The measurements below are the object's own, so they are
                    // shown rather than asked for — the engine reads them off the
                    // piece again on save either way.
                    $fromStock = $withStock && (string) ($row['source'] ?? 'workshop') === 'stock';
                    $pieceLock = $fromStock ? ' readonly title="The piece\'s own weight, measured when it came back"' : '';
                ?>
                <tr>
                    <td data-label="Item">
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
                                <option value="<?= (int) $it['id'] ?>" data-type="<?= e((string) ($it['item_type'] ?? '')) ?>" title="<?= e($it['code'] . ' — ' . $it['name'] . $left) ?>" <?= (int) ($row['item_id'] ?? 0) === (int) $it['id'] ? 'selected' : '' ?>><?= e($it['code'] . ' — ' . $it['name'] . $left) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td data-label="Purity">
                        <?php // data-fineness lets the summary rail turn net weight into the
                              // FINE equivalent live — actual weight and pure-metal content
                              // shown together, which is how a jewellery figure is read. ?>
                        <select name="<?= $prefix ?>_purity_id[]">
                            <?php foreach ($purities as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" data-fineness="<?= e((string) (float) ($p['fineness'] ?? 0)) ?>" <?= (int) ($row['purity_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['metal_code'] . '·' . $p['code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td data-label="Unit">
                        <?php // data-grams: the unit's gram factor, so the rail can reduce
                              // every row to grams before summing — lines in different
                              // units cannot be added in their own units. ?>
                        <select name="<?= $prefix ?>_unit_id[]">
                            <?php foreach ($units as $u): ?>
                                <option value="<?= (int) $u['id'] ?>" data-grams="<?= e((string) (float) ($u['grams'] ?? 1)) ?>" <?= (int) ($row['unit_id'] ?? (int) ($baseUnit['id'] ?? 0)) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td data-label="Pcs"><input type="number" name="<?= $prefix ?>_qty_pieces[]" step="0.001" min="0" value="<?= e((string) ($row['qty_pieces'] ?? '0')) ?>"<?= $pieceLock ?>></td>
                    <td data-label="Gross"><input type="number" name="<?= $prefix ?>_gross_weight[]" step="0.0001" min="0" value="<?= e((string) ($row['gross_weight'] ?? '0')) ?>"<?= $pieceLock ?>></td>
                    <td data-label="Less"><input type="number" name="<?= $prefix ?>_stone_weight[]" class="jw-stone-wt" step="0.0001" min="0" value="<?= e((string) ($row['stone_weight'] ?? '0')) ?>"<?= $pieceLock ?>></td>
                    <?php if ($prefix !== 'x'): ?>
                        <td data-label="Wast %"><input type="number" name="<?= $prefix ?>_wastage_pct[]" class="jw-wastage-pct" step="0.001" min="0" value="<?= e((string) ($row['wastage_pct'] ?? '0')) ?>"></td>
                        <td data-label="Wast wt"><input type="number" name="<?= $prefix ?>_wastage_weight[]" class="jw-wastage-wt" step="0.0001" min="0" value="<?= e((string) ($row['wastage_weight'] ?? '0')) ?>"></td>
                    <?php endif; ?>
                    <td data-label="Rate"><input type="number" name="<?= $prefix ?>_rate[]" step="0.0001" min="0" value="<?= e((string) ($row['rate'] ?? '0')) ?>"></td>
                    <?php if ($full): ?>
                        <td data-label="Making"><input type="number" name="<?= $prefix ?>_making_amount[]" step="0.01" min="0" value="<?= e((string) ($row['making_amount'] ?? '0')) ?>"></td>
                        <td data-label="Diamond crt"><input type="number" name="<?= $prefix ?>_diamond_carat[]" step="0.001" min="0" value="<?= e((string) ($row['diamond_carat'] ?? '0')) ?>"></td>
                        <td data-label="Diamond amt"><input type="number" name="<?= $prefix ?>_diamond_amount[]" step="0.01" min="0" value="<?= e((string) ($row['diamond_amount'] ?? '0')) ?>"></td>
                        <td data-label="Other dia crt"><input type="number" name="<?= $prefix ?>_other_diamond_carat[]" step="0.001" min="0" value="<?= e((string) ($row['other_diamond_carat'] ?? '0')) ?>"></td>
                        <td data-label="Other dia amt"><input type="number" name="<?= $prefix ?>_other_diamond_amount[]" step="0.01" min="0" value="<?= e((string) ($row['other_diamond_amount'] ?? '0')) ?>"></td>
                        <td data-label="Stone crt"><input type="number" name="<?= $prefix ?>_stone_carat[]" step="0.0001" min="0" value="<?= e((string) ($row['stone_carat'] ?? '0')) ?>"></td>
                        <td data-label="Stone amt"><input type="number" name="<?= $prefix ?>_stone_amount[]" step="0.01" min="0" value="<?= e((string) ($row['stone_amount'] ?? '0')) ?>"></td>
                    <?php endif; ?>
                    <?php if ($withWorkshop): ?>
                        <?php
                            // Once metal is out with a kaligad the pair is fixed:
                            // the issue was measured against THIS craftsman and
                            // THIS date, and the receipt settles his wage and his
                            // wastage against it.
                            $issued = (int) ($row['assignment_id'] ?? 0) > 0;
                            // $fromStock was settled at the top of the row: a
                            // piece off the shelf has no craftsman and no day to
                            // wait for, because it is finished and in the case.
                            $lockKarigar = $issued || $fromStock;
                        ?>
                        <?php if ($withStock): ?>
                            <td data-label="From stock">
                                <?php // One control, not two: naming a piece IS saying the
                                      // line comes off the shelf, so there is no second
                                      // field that can disagree with it. Every option
                                      // carries the piece's own measurements, and the
                                      // script below writes them across the row — the
                                      // engine reads them off the piece again on save, so
                                      // what is shown and what is stored cannot drift. ?>
                                <select name="<?= $prefix ?>_stock_receipt_id[]" class="jw-stock-pick"
                                        title="Sell a finished piece already on the Ready to Sale shelf">
                                    <option value="0">— to be made —</option>
                                    <?php foreach ($stockPieces as $piece): ?>
                                        <?php
                                            $pieceName = trim((string) ($piece['expected_ornament'] ?? '')) !== ''
                                                ? (string) $piece['expected_ornament']
                                                : (string) ($piece['item_name'] ?? '');
                                            $pieceLabel = (string) $piece['assignment_no'] . ' · ' . $pieceName
                                                . ' · ' . $fmt((float) $piece['received_gross_weight'], 4)
                                                . ' ' . (string) ($piece['unit_code'] ?? '')
                                                . ' ' . (string) ($piece['purity_code'] ?? '');
                                        ?>
                                        <option value="<?= (int) $piece['receipt_id'] ?>"
                                                data-item="<?= (int) $piece['received_item_id'] ?>"
                                                data-purity="<?= (int) $piece['received_purity_id'] ?>"
                                                data-unit="<?= (int) $piece['unit_id'] ?>"
                                                data-pcs="<?= e((string) ((float) $piece['qty_pieces'] ?: 1)) ?>"
                                                data-gross="<?= e((string) (float) $piece['received_gross_weight']) ?>"
                                                data-stone="<?= e((string) (float) ($piece['stone_weight'] ?? 0)) ?>"
                                                data-making="<?= e((string) (float) ($piece['making_amount'] ?? 0)) ?>"
                                                data-size="<?= e((string) ($piece['size_design'] ?? '')) ?>"
                                                title="<?= e($pieceLabel) ?>"
                                                <?= (int) ($row['stock_receipt_id'] ?? 0) === (int) $piece['receipt_id'] ? 'selected' : '' ?>><?= e($pieceLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        <?php endif; ?>
                        <td data-label="Kaligad">
                            <select name="<?= $prefix ?>_karigar_id[]"<?= $lockKarigar ? ' disabled' : '' ?>
                                title="<?= $issued ? e('Metal is already out on issue ' . (string) ($row['issue_no'] ?? ''))
                                    : ($fromStock ? 'Already made — this piece is coming off the shelf' : 'Who is to make this piece') ?>">
                                <option value="0">—</option>
                                <?php foreach ($karigars as $k): ?>
                                    <option value="<?= (int) $k['id'] ?>" <?= (int) ($row['karigar_id'] ?? 0) === (int) $k['id'] ? 'selected' : '' ?>><?= e($k['code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php // A disabled select posts nothing, and every field on this
                                  // grid is a parallel array — one missing value would slide
                                  // every later row's kaligad onto the wrong item. The hidden
                                  // input is what keeps the arrays the same length. ?>
                            <?php if ($lockKarigar): ?>
                                <input type="hidden" class="jw-karigar-lock"<?= $issued ? ' data-issued="1"' : '' ?> name="<?= $prefix ?>_karigar_id[]" value="<?= $fromStock ? 0 : (int) ($row['karigar_id'] ?? 0) ?>">
                            <?php endif; ?>
                        </td>
                        <td data-label="Promised"><input type="date" name="<?= $prefix ?>_delivery_date[]" value="<?= $fromStock ? '' : e((string) ($row['delivery_date'] ?? '')) ?>"<?= $fromStock ? ' readonly title="Already made — there is nothing to wait for"' : '' ?>></td>
                        <?php // Free text both: sizes are written a dozen ways (US 7,
                              // 17 mm, 22"), and the note is the customer's wish for
                              // THIS piece — engraving, finish, stone preference. ?>
                        <td data-label="Size"><input type="text" name="<?= $prefix ?>_size[]" maxlength="60" placeholder="ring 7 / 22&quot;"
                                   value="<?= e((string) ($row['size'] ?? '')) ?>"></td>
                        <td data-label="Item note"><input type="text" name="<?= $prefix ?>_notes[]" maxlength="255" placeholder="engraving, finish…"
                                   value="<?= e((string) ($row['notes'] ?? '')) ?>"></td>
                    <?php endif; ?>
                    <td class="c-del" data-label="">
                        <?php // Clearing the item empties the row, and an empty row is ignored on save. ?>
                        <button type="button" class="jw-line-remove" aria-label="Remove this row"><?= icon('close') ?></button>
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
    // A row is off the shelf, or it is work for a kaligad. Never both, and the
    // two halves below are exact opposites so a row can be switched back.
    function releaseRow(row) {
        var karigar = row.querySelector('select[name$="_karigar_id[]"]');
        if (karigar) {
            karigar.disabled = false;
            karigar.title = "Who is to make this piece";
        }
        // Only the compensator this script added. One marked data-issued
        // belongs to metal that is genuinely out with a craftsman.
        Array.prototype.forEach.call(row.querySelectorAll(".jw-karigar-lock:not([data-issued])"), function (lock) {
            lock.parentNode.removeChild(lock);
        });
        ["_delivery_date[]", "_qty_pieces[]", "_gross_weight[]", "_stone_weight[]"].forEach(function (suffix) {
            var field = row.querySelector('input[name$="' + suffix + '"]');
            if (field) { field.readOnly = false; field.removeAttribute("title"); }
        });
    }

    function claimRow(row, option) {
        var read = function (key) { return option.getAttribute("data-" + key) || ""; };
        var put = function (suffix, value) {
            var field = row.querySelector('[name$="' + suffix + '"]');
            if (field && value !== "") { field.value = value; }
            return field;
        };
        put("_item_id[]", read("item"));
        put("_purity_id[]", read("purity"));
        put("_unit_id[]", read("unit"));
        // The piece's own measurements, shown rather than asked for. The engine
        // reads them off the piece again on save, so a browser that got this
        // wrong cannot put one ring's weight on another ring's bill.
        ["_qty_pieces[]:pcs", "_gross_weight[]:gross", "_stone_weight[]:stone"].forEach(function (pair) {
            var parts = pair.split(":");
            var field = put(parts[0], read(parts[1]));
            if (field) { field.readOnly = true; field.title = "The piece's own weight, measured when it came back"; }
        });
        var making = row.querySelector('input[name$="_making_amount[]"]');
        if (making && parseFloat(making.value || "0") === 0) { making.value = read("making"); }
        var size = row.querySelector('input[name$="_size[]"]');
        if (size && size.value.trim() === "") { size.value = read("size"); }

        // Nobody is making it, so nobody is waiting for it either.
        var karigar = row.querySelector('select[name$="_karigar_id[]"]');
        if (karigar && !karigar.disabled) {
            karigar.value = "0";
            karigar.disabled = true;
            karigar.title = "Already made — this piece is coming off the shelf";
            var lock = document.createElement("input");
            lock.type = "hidden";
            lock.className = "jw-karigar-lock";
            lock.name = karigar.name;
            lock.value = "0";
            karigar.parentNode.appendChild(lock);
        }
        var promised = row.querySelector('input[name$="_delivery_date[]"]');
        if (promised) {
            promised.value = "";
            promised.readOnly = true;
            promised.title = "Already made — there is nothing to wait for";
        }
    }

    document.addEventListener("change", function (event) {
        var picker = event.target.closest(".jw-stock-pick");
        if (!picker) { return; }
        var row = picker.closest("tr");
        if (!row) { return; }
        var option = picker.options[picker.selectedIndex];
        if (!option || parseInt(picker.value, 10) <= 0) { releaseRow(row); return; }
        claimRow(row, option);
    });

    document.addEventListener("click", function (event) {
        var addButton = event.target.closest(".jw-line-add");
        if (addButton) {
            var box = addButton.closest(".jw-lines-box");
            var body = box && box.querySelector("table.jw-lines tbody");
            if (!body || !body.lastElementChild) { return; }
            var clone = body.lastElementChild.cloneNode(true);
            // A cloned lock would post a SECOND kaligad for the new row and
            // slide every later row's value onto the wrong item.
            Array.prototype.forEach.call(clone.querySelectorAll(".jw-karigar-lock"), function (lock) {
                lock.parentNode.removeChild(lock);
            });
            Array.prototype.forEach.call(clone.querySelectorAll("[disabled]"), function (field) {
                field.disabled = false;
            });
            Array.prototype.forEach.call(clone.querySelectorAll("[readonly]"), function (field) {
                field.readOnly = false;
                field.removeAttribute("title");
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
        // Metal already out with a kaligad pins its row; a piece merely
        // reserved off the shelf does not — dropping the row hands it back.
        if (target.querySelector(".jw-karigar-lock[data-issued]")) { return; }
        var body = target.parentNode;
        if (body && body.children.length > 1) {
            body.removeChild(target);
        } else {
            releaseRow(target);
            resetRow(target);
        }
    });

    // Stones are weighed in CARATS at the counter, but the Less column is in
    // the line's own unit. Typing the carats fills the Less box — visibly,
    // and editable, exactly as the engine will derive it (1 ct = 0.2 g) — so
    // 25 ct on a gram line shows 5.0000 coming off the metal before anything
    // is saved. A Less figure typed BY HAND always wins; and a loose stone
    // line (its whole gross IS carats) is left alone.
    document.addEventListener("input", function (event) {
        var field = event.target;
        var name = field.name || "";
        if (/_stone_weight\[\]$/.test(name) && !field.dataset.jwAuto) {
            delete field.dataset.jwDerived;
            return;
        }
        if (!/_(stone|diamond|other_diamond)_carat\[\]$/.test(name)) { return; }
        var row = field.closest("tr");
        if (!row) { return; }
        var itemSelect = row.querySelector('select[name$="_item_id[]"]');
        var chosenItem = itemSelect && itemSelect.options[itemSelect.selectedIndex];
        if (!chosenItem || chosenItem.getAttribute("data-type") !== "ornament") { return; }
        var less = row.querySelector('input[name$="_stone_weight[]"]');
        if (!less) { return; }
        var current = parseFloat(less.value) || 0;
        if (current > 0 && !less.dataset.jwDerived) { return; }
        var unitSelect = row.querySelector('select[name$="_unit_id[]"]');
        var chosenUnit = unitSelect && unitSelect.options[unitSelect.selectedIndex];
        var grams = chosenUnit ? parseFloat(chosenUnit.getAttribute("data-grams")) : 1;
        if (!isFinite(grams) || grams <= 0) { grams = 1; }
        // Every kind of set stone is rock: stones, diamonds and other
        // diamonds sum into the one Less figure, exactly as the engine does.
        var carats = 0;
        ["_stone_carat", "_diamond_carat", "_other_diamond_carat"].forEach(function (suffix) {
            var caratField = row.querySelector('input[name$="' + suffix + '[]"]');
            var v = caratField ? parseFloat(caratField.value) : 0;
            if (isFinite(v) && v > 0) { carats += v; }
        });
        less.dataset.jwDerived = "1";
        less.dataset.jwAuto = "1";
        less.value = carats > 0 ? (carats * 0.2 / grams).toFixed(4) : "0";
        less.dispatchEvent(new Event("input", { bubbles: true }));
        delete less.dataset.jwAuto;
    });
})();
</script>
    <?php
}
