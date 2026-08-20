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
/* A shelf piece that does not weigh what the line asked for says so under the
   Gross box. Warning colours, not error colours: selling a heavier piece than
   the customer first described is ordinary, and the counter is being told, not
   stopped. */
.jw-weight-gap {
    margin-top: 3px;
    font-size: 11px;
    line-height: 1.35;
    color: var(--mbw-warn-ink, #8a5a00);
    background: var(--mbw-warn-soft, #fff6e0);
    border-left: 2px solid var(--mbw-warn, #d79a00);
    padding: 2px 5px;
    border-radius: 3px;
}
.workspace-form-grid > label {
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    gap: 5px;
    min-width: 0;
    /* portal.css lays this label out as a GRID, at a specificity this rule
       cannot beat, so display:flex above never takes effect and
       justify-content:flex-start stops meaning "pack to the top" and starts
       meaning "do not stretch the column". The track then shrank to the
       browser default width of an input, every field came out 206px wide
       inside a 295px cell, and width:100% resolved against the shrunken
       track rather than the cell — so nothing lined up and no width rule
       could fix it. One full-width track leaves no free space for either
       meaning to act on. */
    grid-template-columns: minmax(0, 1fr);
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
                // Column order: FROM STOCK first, then ITEM, then rest
                $cols = [];
                if ($withWorkshop && $withStock) {
                    $cols[] = 'c-src';
                }
                $cols[] = 'c-item';
                $cols[] = 'c-sel';
                $cols[] = 'c-unit';
                $cols[] = 'c-pcs';
                $cols[] = 'c-wt';
                $cols[] = 'c-wt';
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
                    <?php if ($withWorkshop && $withStock): ?>
                        <th rowspan="2" class="c-src">Order type</th>
                    <?php endif; ?>
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
                    <?php if ($withWorkshop && !$withStock): ?>
                        <th colspan="4">Workshop</th>
                    <?php elseif ($withWorkshop && $withStock): ?>
                        <th colspan="4">Workshop</th>
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
                        <th class="c-size">Size</th><th class="c-note">Item note</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <?php
                // ONE copy of the item list per page, not one per line.
                //
                // Every line row used to render the whole item master. A sale form
                // has seven of these selects, so a shop with a few thousand styles
                // was sent the entire list seven times — most of a megabyte of
                // <option> for a form showing a dozen rows. The list is built once
                // here, parked in a <template>, and the script at the foot of the
                // page copies it into the rows left empty.
                //
                // The FIRST select on the page keeps its options inline, so the
                // form is still usable if that script never runs.
                static $stockTemplateEmitted = false;
                static $sharedItemOptions = null;
                static $itemTemplateEmitted = false;
                static $inlineListUsed = false;
                if ($sharedItemOptions === null) {
                    $sharedItemOptions = '<option value="0">—</option>';
                    foreach ($items as $it) {
                        $stock = $onHand[(int) $it['id']] ?? null;
                        $left = $stock
                            ? ' · ' . $fmt((float) $stock['qty_pieces'], 0) . 'pc '
                                . $fmt((float) $stock['fine_weight'], 3) . ' fine'
                            : '';
                        $label = $it['code'] . ' — ' . $it['name'] . $left;
                        $sharedItemOptions .= '<option value="' . (int) $it['id'] . '" data-type="'
                            . e((string) ($it['item_type'] ?? '')) . '" title="' . e($label) . '">'
                            . e($label) . '</option>';
                    }
                }
            ?>
            <?php if (!$itemTemplateEmitted): $itemTemplateEmitted = true; ?>
                <template id="jw-item-options"><?= $sharedItemOptions ?></template>
            <?php endif; ?>
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
                    <input type="hidden" name="<?= $prefix ?>_line_id[]" value="<?= (int) ($row['id'] ?? 0) ?>">
                    <?php if ($withWorkshop && $withStock): ?>
                        <td data-label="Order type">
                            <select name="<?= $prefix ?>_order_type[]" class="jw-order-type" style="width:100%">
                                <option value="showroom" <?= ((int) ($row['stock_unit_id'] ?? 0) > 0) ? 'selected' : '' ?>>Showroom Stock</option>
                                <option value="kaligadh" <?= ((int) ($row['stock_unit_id'] ?? 0) === 0) ? 'selected' : '' ?>>New Assignment</option>
                            </select>
                        </td>
                    <?php endif; ?>
                    <td data-label="Item" style="display:flex;gap:4px;align-items:center">
                        <?php if ($withWorkshop && $withStock): ?>
                            <!-- Stock picker for Showroom Stock -->
                            <?php
                            // Built ONCE for the page and filled into each row by
                            // script, exactly as the item list beside it is. Drawn per
                            // row this was the whole traced shelf — two thousand
                            // options carrying nine data attributes each — repeated
                            // for every line on the form. An order screen for a
                            // two-thousand-piece shop came to seven and a half
                            // megabytes, nearly all of it the same list over again.
                            static $sharedStockOptions = null;
                            static $stockOptionById = [];
                            if ($sharedStockOptions === null) {
                                $sharedStockOptions = '';
                                foreach ($stockPieces ?? [] as $piece) {
                                    $pieceId = (int) ($piece['id'] ?? 0);
                                    if ($pieceId <= 0) {
                                        continue;
                                    }
                                    // The counter reads this list looking for a ring, not for
                                    // a barcode, so the piece is named first and identified
                                    // after: name, its own code, what it is made of, what it
                                    // weighs, and only then the trace that tells two
                                    // identical bracelets apart.
                                    $pieceName = trim((string) ($piece['item_name'] ?? ''));
                                    $pieceCode = trim((string) ($piece['item_code'] ?? ''));
                                    $purityCode = trim((string) ($piece['purity_code'] ?? ''));
                                    // The shop's own tag, when the piece carries one, is what
                                    // the person at the counter is holding — the trace code is
                                    // the fallback for stock that was never tagged.
                                    $pieceTag = trim((string) ($piece['tag_no'] ?? ''));
                                    $traceCode = $pieceTag !== ''
                                        ? $pieceTag
                                        : trim((string) ($piece['trace_code'] ?? ''));
                                    // Pieces tracked by weight carry no piece count — an
                                    // opening balance of 4.36gm is one object, not zero of
                                    // them, so "Qty: 0" is only shown when it is a real count.
                                    $qtyAvailable = (float) ($piece['qty_pieces'] ?? 0);
                                    $labelParts = [$pieceName !== '' ? $pieceName : ($pieceCode !== '' ? $pieceCode : 'Stock #' . $pieceId)];
                                    if ($pieceCode !== '' && $pieceName !== '') {
                                        $labelParts[0] .= ' (' . $pieceCode . ')';
                                    }
                                    if ($purityCode !== '') {
                                        $labelParts[] = $purityCode;
                                    }
                                    $labelParts[] = $fmt((float) ($piece['gross_weight'] ?? 0), 4) . ' ' . (string) ($piece['unit_code'] ?? '');
                                    if ($qtyAvailable > 0) {
                                        $labelParts[] = $fmt($qtyAvailable, 0) . ' pc';
                                    }
                                    if ($traceCode !== '') {
                                        $labelParts[] = $traceCode;
                                    }
                                    $pieceLabel = implode(' | ', $labelParts);
                                    $option = '<option value="' . $pieceId . '"'
                                        . ' data-item="' . (int) ($piece['item_id'] ?? 0) . '"'
                                        . ' data-metal="' . (int) ($piece['metal_id'] ?? 0) . '"'
                                        . ' data-purity="' . (int) ($piece['purity_id'] ?? 0) . '"'
                                        . ' data-unit="' . (int) ($piece['unit_id'] ?? 0) . '"'
                                        . ' data-pcs="' . e((string) ((float) ($piece['qty_pieces'] ?? 0) ?: 1)) . '"'
                                        . ' data-gross="' . e((string) (float) ($piece['gross_weight'] ?? 0)) . '"'
                                        . ' data-stone="' . e((string) (float) ($piece['stone_weight'] ?? 0)) . '"'
                                        . ' data-making="' . e((string) (float) ($piece['making_amount'] ?? 0)) . '"'
                                        . ' data-size="' . e((string) ($piece['size_design'] ?? $piece['design_no'] ?? '')) . '"'
                                        . ' title="' . e($pieceLabel) . '">' . e($pieceLabel) . '</option>';
                                    $sharedStockOptions .= $option;
                                    $stockOptionById[$pieceId] = $option;
                                }
                            }
                            $rowStockUnitId = (int) ($row['stock_unit_id'] ?? 0);
                            ?>
                            <?php if (!$stockTemplateEmitted): ?>
                                <template id="jw-stock-options"><?= $sharedStockOptions ?></template>
                                <?php $stockTemplateEmitted = true; ?>
                            <?php endif; ?>
                            <?php // Only the row's OWN piece is drawn; the rest arrive from
                                  // the template above the moment somebody opens the list. ?>
                            <select name="<?= $prefix ?>_stock_unit_id[]" class="jw-stock-pick" data-jw-stock-fill="1" style="flex:1;min-width:0;display:<?= $rowStockUnitId > 0 ? 'block' : 'none' ?>">
                                <option value="0">— Select stock —</option>
                                <?php if ($rowStockUnitId > 0 && isset($stockOptionById[$rowStockUnitId])): ?>
                                    <?= str_replace('<option ', '<option selected ', $stockOptionById[$rowStockUnitId]) ?>
                                <?php endif; ?>
                            </select>
                        <?php endif; ?>

                        <!-- Manual item selection for New Assignment -->
                        <?php // Classed, not just "the first div in this cell": searchable-select.js
                              // inserts its own div for the dropdown panel ahead of this one, and
                              // the row then hid that instead — leaving the item box and + Add on
                              // screen for a piece coming off the shelf. ?>
                        <div class="jw-item-manual" style="display:<?= ((int) ($row['stock_unit_id'] ?? 0) === 0) ? 'flex' : 'none' ?>;gap:4px;align-items:center;width:100%">
                            <?php
                                // The first is written out in full; the rest carry only
                                // the value they already hold, so the form shows the right
                                // item before any script runs, and are marked for filling
                                // from the template above.
                                $rowItemId = (int) ($row['item_id'] ?? 0);
                                // EVERY row fills from the template, including the first.
                                // It used to carry the whole list inline so that the
                                // searchable-dropdown script at the foot of the page would
                                // count real options and enhance it — but the fill below
                                // runs in this partial, which is emitted first, so by the
                                // time that script looks every select already holds the
                                // real list. The inline copy was a second two-thousand-item
                                // list on the page saying exactly what the template said.
                                $fillFromTemplate = true;
                                $inlineListUsed = true;
                            ?>
                            <select name="<?= $prefix ?>_item_id[]" class="c-item" style="flex:1;min-width:0"<?= $fillFromTemplate ? ' data-jw-item-fill="1"' : '' ?>>
                                <?php if (!$fillFromTemplate): ?>
                                    <?= $rowItemId > 0
                                        ? str_replace('<option value="' . $rowItemId . '"', '<option selected value="' . $rowItemId . '"', $sharedItemOptions)
                                        : $sharedItemOptions ?>
                                <?php else: ?>
                                    <option value="0">—</option>
                                    <?php foreach ($items as $it): if ((int) $it['id'] !== $rowItemId) { continue; } ?>
                                        <option value="<?= (int) $it['id'] ?>" data-type="<?= e((string) ($it['item_type'] ?? '')) ?>" selected><?= e($it['code'] . ' — ' . $it['name']) ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <button type="button" class="jw-add-item-btn" data-row-index="<?= $i ?>" title="Create a new item">+ Add</button>
                        </div>
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
                            $fromStock = $fromStock || (int) ($row['stock_unit_id'] ?? 0) > 0;
                            $lockKarigar = $issued || $fromStock;
                        ?>
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
function jw_line_grid_scripts(array $ctx = []): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    // The Create New Item dialog is the item master's own form in a box, so it
    // is handed the same lists that form is built from. It used to copy its
    // options out of the line grid instead, which is why Metal came up empty —
    // the grid has no metal column to copy — and why Weight Unit offered
    // "Select stock": that copy searched by name tail and found the stock
    // picker, whose name ends in _stock_unit_id[].
    $metals = $ctx['metals'] ?? [];
    $purities = $ctx['purities'] ?? [];
    $units = $ctx['units'] ?? [];
    $baseUnit = $ctx['base_unit'] ?? null;
    ?>
<!-- Modal for Creating New Items in Kaligadh Orders -->
<div id="jw-item-modal" class="jw-modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="jw-modal-title">
    <div class="jw-modal-panel">
        <div class="jw-modal-head">
            <h2 id="jw-modal-title">Create New Item</h2>
            <?php // A close control captioned with a glyph announces "button" and
                  // nothing else; the label is what a screen reader reads. ?>
            <button type="button" id="jw-modal-close" class="jw-modal-close" aria-label="Close without creating an item"><?= icon('close') ?></button>
        </div>
        <form id="jw-item-create-form" style="padding:20px">
            <input type="hidden" name="action" value="create_item_ajax">
            <input type="hidden" name="csrf_token" id="jw-csrf-token" value="">

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:15px;margin-bottom:20px">
                <!-- Row 1: Code, Name, Category, Type -->
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Code<span style="color:red;margin-left:2px">*</span></label>
                    <input type="text" name="code" maxlength="60" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Name<span style="color:red;margin-left:2px">*</span></label>
                    <input type="text" name="name" maxlength="190" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Category</label>
                    <input type="text" name="category" maxlength="60" placeholder="Ring, Necklace, etc." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Type</label>
                    <select name="item_type" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                        <option value="ornament" selected>Ornament</option>
                        <option value="bullion">Bullion / Raw metal</option>
                        <option value="stone">Stone</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <!-- Row 2: Default Stock Type, Metal, Purity, Weight Unit -->
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Default Stock Type<span style="color:red;margin-left:2px">*</span></label>
                    <select name="stock_kind" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                        <option value="customer_ordered" selected>Customer Ordered Stock</option>
                        <option value="showroom">Showroom Stock</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Metal<span style="color:red;margin-left:2px">*</span></label>
                    <select name="metal_id" id="jw-modal-metal" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                        <?php foreach ($metals as $m): ?>
                            <?php if ((int) ($m['active'] ?? 1) !== 1) { continue; } ?>
                            <option value="<?= (int) $m['id'] ?>"><?= e((string) $m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Purity<span style="color:red;margin-left:2px">*</span></label>
                    <select name="purity_id" id="jw-modal-purity" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                        <?php // data-metal is what the script below filters on, so the list only
                              // ever offers purities that belong to the metal chosen. ?>
                        <?php foreach ($purities as $p): ?>
                            <?php if ((int) ($p['active'] ?? 1) !== 1) { continue; } ?>
                            <option value="<?= (int) $p['id'] ?>" data-metal="<?= (int) $p['metal_id'] ?>"><?= e(((string) ($p['metal_code'] ?? '')) . ' · ' . (string) $p['code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Weight Unit<span style="color:red;margin-left:2px">*</span></label>
                    <select name="unit_id" id="jw-modal-unit" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                        <?php foreach ($units as $u): ?>
                            <?php if ((int) ($u['active'] ?? 1) !== 1) { continue; } ?>
                            <option value="<?= (int) $u['id'] ?>" <?= (int) ($baseUnit['id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e((string) $u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Row 3: Track by, Design/ref no., Hallmark, Reference gross weight -->
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Track by</label>
                    <select name="track_mode" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                        <option value="weight" selected>Weight</option>
                        <option value="piece">Piece</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Design / Ref No.</label>
                    <input type="text" name="design_no" maxlength="60" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Hallmark</label>
                    <input type="text" name="hallmark" maxlength="60" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Ref Gross Weight</label>
                    <input type="number" name="reference_gross_weight" step="0.0001" min="0" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                </div>

                <!-- Row 4: Reference stone weight, Default wastage %, Making basis, Default making rate -->
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Ref Stone Weight</label>
                    <input type="number" name="reference_stone_weight" step="0.0001" min="0" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Default Wastage %</label>
                    <input type="number" name="default_wastage_pct" step="0.001" min="0" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Making Basis</label>
                    <select name="making_basis" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                        <option value="">Company default</option>
                        <option value="weight">Weight</option>
                        <option value="piece">Piece</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Default Making Rate</label>
                    <input type="number" name="default_making_rate" step="0.01" min="0" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                </div>

                <!-- Row 5: Default stone value, Reorder weight, VAT base, HS code -->
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Default Stone Value</label>
                    <input type="number" name="default_stone_value" step="0.01" min="0" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Reorder Weight</label>
                    <input type="number" name="reorder_weight" step="0.0001" min="0" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">VAT Base</label>
                    <select name="vat_base" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                        <option value="">Use company default</option>
                        <option value="weight">Weight</option>
                        <option value="value">Value</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">HS Code</label>
                    <input type="text" name="hs_code" maxlength="20" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px">
                </div>

                <!-- Row 6: Checkboxes and Notes -->
                <div>
                    <label style="display:flex;align-items:center;margin-bottom:6px;font-weight:600;font-size:13px">
                        <input type="checkbox" name="vat_applicable" style="margin-right:8px">VAT Applicable
                    </label>
                </div>
                <div>
                    <label style="display:flex;align-items:center;margin-bottom:6px;font-weight:600;font-size:13px">
                        <input type="checkbox" name="is_active" checked style="margin-right:8px">Active
                    </label>
                </div>
                <div style="grid-column:3/-1">
                    <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Notes</label>
                    <textarea name="notes" maxlength="500" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:13px;resize:vertical;min-height:32px"></textarea>
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:25px">
                <button type="button" id="jw-modal-cancel" class="jw-modal-cancel">Cancel</button>
                <button type="submit" id="jw-modal-submit" class="jw-modal-submit">Create Item</button>
            </div>

            <div id="jw-modal-error" class="jw-modal-note is-error" style="display:none" role="alert"></div>
            <div id="jw-modal-success" class="jw-modal-note is-success" style="display:none" role="status"></div>
        </form>
    </div>
</div>
<script>
(function () {
    // Give every line its item list back.
    //
    // Only the first select on the page was written out in full; the rest
    // arrived holding just the value they already had, marked for filling.
    // Runs before anything below touches a row, and before the searchable
    // dropdown script loads at the foot of the page — that one decides whether
    // to enhance a select by counting its options, so it has to see the real
    // list, not the stub.
    function fillFromTemplate(scope, templateId, selector, marker, keepFirst) {
        var template = document.getElementById(templateId);
        if (!template) { return; }
        var pending = (scope || document).querySelectorAll(selector);
        Array.prototype.forEach.call(pending, function (select) {
            var chosen = select.value;
            // The stock picker keeps its "— Select stock —" line; the item list
            // carries its own placeholder inside the template.
            var head = keepFirst && select.options.length ? select.options[0].outerHTML : '';
            select.innerHTML = head + template.innerHTML;
            // A value the list no longer offers must not silently become the
            // first item, so it is only restored when it is really there.
            if (chosen && select.querySelector('option[value="' + chosen + '"]')) {
                select.value = chosen;
            }
            select.removeAttribute(marker);
        });
    }

    function fillItemSelects(scope) {
        fillFromTemplate(scope, 'jw-item-options', 'select.c-item[data-jw-item-fill]', 'data-jw-item-fill', false);
        fillFromTemplate(scope, 'jw-stock-options', 'select.jw-stock-pick[data-jw-stock-fill]', 'data-jw-stock-fill', true);
    }
    fillItemSelects(document);

    function resetRow(row) {
        Array.prototype.forEach.call(row.querySelectorAll("input, select"), function (field) {
            if (field.disabled) { return; }
            if (field.type === "hidden") { field.value = "0"; return; }
            if (field.tagName === "SELECT") { field.selectedIndex = 0; return; }
            field.value = field.type === "number" ? "0" : "";
        });
        setFieldShown(row.querySelector(".jw-stock-pick"), false);
        clearWeightGap(row);
        // A blanked row is a kaligad row again, so the item box comes back.
        var manualItem = row.querySelector(".jw-item-manual");
        if (manualItem) { manualItem.style.display = "flex"; }
    }
    // A row is off the shelf, or it is work for a kaligad. Never both, and the
    // two halves below are exact opposites so a row can be switched back.
    function releaseRow(row) {
        // Re-enable ITEM and PURITY when switching back to custom order
        var itemField = row.querySelector('select[name$="_item_id[]"]');
        if (itemField) {
            itemField.disabled = false;
            itemField.removeAttribute("title");
            itemField.selectedIndex = 0;
            // Remove the hidden lock input
            Array.prototype.forEach.call(row.querySelectorAll(".jw-item-lock:not([data-issued])"), function (lock) {
                lock.parentNode.removeChild(lock);
            });
        }

        var purityField = row.querySelector('select[name$="_purity_id[]"]');
        if (purityField) {
            purityField.disabled = false;
            purityField.removeAttribute("title");
            purityField.selectedIndex = 0;
            // Remove the hidden lock input
            Array.prototype.forEach.call(row.querySelectorAll(".jw-purity-lock:not([data-issued])"), function (lock) {
                lock.parentNode.removeChild(lock);
            });
        }

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
            var field = rowField(row, suffix);
            if (field) { field.readOnly = false; field.removeAttribute("title"); }
        });
        // The weights belonged to the piece that was on this row, not to the
        // order. Leaving them behind would price a kaligad's new work at the
        // measurements of a bracelet the customer is no longer buying.
        ["_gross_weight[]", "_stone_weight[]"].forEach(function (suffix) {
            var field = rowField(row, suffix);
            if (field) { field.value = "0"; }
        });
        // The piece has been handed back, so there is no longer a gap between
        // it and anything.
        clearWeightGap(row);
    }

    // Every field in a row is named <prefix>_<what>[], so the prefix is read off
    // the line's own hidden id and the fields are then addressed by their whole
    // name. Matching on the tail alone is what put the unit id into the stock
    // picker and blanked it: "l_stock_unit_id[]" ends with "_unit_id[]".
    function rowPrefix(row) {
        var lineId = row.querySelector('input[name$="_line_id[]"]');
        var name = lineId ? lineId.name : "";
        return name ? name.slice(0, name.length - "_line_id[]".length) : "";
    }

    // A long dropdown is replaced on screen by searchable-select.js, which
    // wraps it and hides the original. Toggling the select then changes
    // nothing a user can see, so the wrapper is what has to be shown or
    // hidden once it exists.
    function setFieldShown(field, shown) {
        if (!field) { return; }
        var onScreen = field.closest('.ss-wrap') || field;
        onScreen.style.display = shown ? 'block' : 'none';
    }

    function rowField(row, suffix) {
        var prefix = rowPrefix(row);
        return prefix
            ? row.querySelector('[name="' + prefix + suffix + '"]')
            : row.querySelector('[name$="' + suffix + '"]');
    }

    // How far a shelf piece may sit from the weight that was asked for before
    // it is worth mentioning: half a percent, and never under 0.01 of the
    // line's unit, so the fourth decimal place cannot raise a warning about
    // nothing.
    function weightGapTolerance(asked) {
        return Math.max(asked * 0.005, 0.01);
    }

    function clearWeightGap(row) {
        var shown = row.querySelector(".jw-weight-gap");
        if (shown) { shown.parentNode.removeChild(shown); }
    }

    // Advisory, never a block. A counter may sell a heavier piece than the one
    // the customer first described, and often does; what it must not do is
    // happen silently, because the asked-for figure is overwritten and locked
    // the moment the piece is chosen.
    function noteWeightGap(row, asked, actual) {
        clearWeightGap(row);
        if (!(asked > 0) || !(actual > 0)) { return; }
        var gap = actual - asked;
        if (Math.abs(gap) <= weightGapTolerance(asked)) { return; }
        var field = rowField(row, "_gross_weight[]");
        var cell = field && field.parentNode;
        if (!cell) { return; }
        var note = document.createElement("div");
        note.className = "jw-weight-gap";
        note.textContent = "Asked " + asked.toFixed(4) + " — this piece is " +
            actual.toFixed(4) + " (" + (gap > 0 ? "+" : "") + gap.toFixed(4) + ")";
        note.title = "The piece coming off the shelf does not weigh what this line asked for.";
        cell.appendChild(note);
    }

    function claimRow(row, option) {
        var read = function (key) { return option.getAttribute("data-" + key) || ""; };
        var put = function (suffix, value) {
            var field = rowField(row, suffix);
            if (field && value !== "") { field.value = value; }
            return field;
        };
        // Auto-fill item and purity, then DISABLE them
        var itemField = put("_item_id[]", read("item"));
        if (itemField) {
            itemField.disabled = true;
            itemField.title = "Locked - from showroom stock";
            // Add hidden input so disabled field value posts to server
            Array.prototype.forEach.call(row.querySelectorAll(".jw-item-lock:not([data-issued])"), function (lock) {
                lock.parentNode.removeChild(lock);
            });
            var itemLock = document.createElement("input");
            itemLock.type = "hidden";
            itemLock.className = "jw-item-lock";
            itemLock.name = itemField.name;
            itemLock.value = itemField.value;
            itemField.parentNode.appendChild(itemLock);
        }

        var purityField = put("_purity_id[]", read("purity"));
        if (purityField) {
            purityField.disabled = true;
            purityField.title = "Locked - from showroom stock";
            // Add hidden input so disabled field value posts to server
            Array.prototype.forEach.call(row.querySelectorAll(".jw-purity-lock:not([data-issued])"), function (lock) {
                lock.parentNode.removeChild(lock);
            });
            var purityLock = document.createElement("input");
            purityLock.type = "hidden";
            purityLock.className = "jw-purity-lock";
            purityLock.name = purityField.name;
            purityLock.value = purityField.value;
            purityField.parentNode.appendChild(purityLock);
        }

        put("_unit_id[]", read("unit"));
        // The piece's own measurements, shown rather than asked for. The engine
        // reads them off the piece again on save, so a browser that got this
        // wrong cannot put one ring's weight on another ring's bill.
        // NOTE: PCS is always editable (customer may order different quantity)
        put("_qty_pieces[]", read("pcs")); // Auto-fill but NOT read-only

        // Read before the piece's own measurements land on top of it: the
        // field is filled and then locked, so the weight the order was written
        // for leaves no trace once a piece is picked. A 10 gm ring quietly
        // becoming the 45 gm bracelet that happened to be on the shelf is the
        // mismatch this catches.
        var askedGross = parseFloat((rowField(row, "_gross_weight[]") || {}).value || "0") || 0;

        ["_gross_weight[]:gross", "_stone_weight[]:stone"].forEach(function (pair) {
            var parts = pair.split(":");
            var field = put(parts[0], read(parts[1]));
            if (field) { field.readOnly = true; field.title = "The piece's own weight, measured when it came back"; }
        });
        noteWeightGap(row, askedGross, parseFloat(read("gross")) || 0);
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
        var orderTypeSelect = event.target.closest(".jw-order-type");
        if (!orderTypeSelect) { return; }
        var row = orderTypeSelect.closest("tr");
        if (!row) { return; }
        var stockPicker = row.querySelector(".jw-stock-pick");
        var manualItemDiv = row.querySelector("div[style*='display']");
        var isShowroom = orderTypeSelect.value === "showroom";

        // Toggle stock picker vs manual item selection
        setFieldShown(stockPicker, isShowroom);

        // The item box and its + Add button belong to work a kaligad will make.
        // A piece chosen off the shelf already exists, so there is nothing to
        // pick and nothing to create.
        var manualItem = row.querySelector(".jw-item-manual");
        if (manualItem) { manualItem.style.display = isShowroom ? "none" : "flex"; }

        // Lock/unlock GROSS and LESS fields based on order type
        var grossField = row.querySelector('input[name$="_gross_weight[]"]');
        var lessField = row.querySelector('input[name$="_stone_weight[]"]');

        if (isShowroom) {
            // Showroom stock: lock these fields (they come from stock piece)
            if (grossField) { grossField.readOnly = true; grossField.title = "Auto-filled from stock piece"; }
            if (lessField) { lessField.readOnly = true; lessField.title = "Auto-filled from stock piece"; }
        } else {
            // New assignment: unlock these fields (user enters custom values)
            if (grossField) { grossField.readOnly = false; grossField.removeAttribute("title"); }
            if (lessField) { lessField.readOnly = false; lessField.removeAttribute("title"); }
            releaseRow(row);
            if (stockPicker) { stockPicker.value = "0"; }
        }
    });

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
        // By whole name, not by tail: the stock picker is "<prefix>_stock_unit_id[]"
        // and would match a tail search for the unit, costing this sum the row's
        // real gram factor.
        var unitSelect = rowField(row, "_unit_id[]");
        var chosenUnit = unitSelect && unitSelect.options[unitSelect.selectedIndex];
        var grams = chosenUnit ? parseFloat(chosenUnit.getAttribute("data-grams")) : 1;
        if (!isFinite(grams) || grams <= 0) { grams = 1; }
        // Every kind of set stone is rock: stones, diamonds and other
        // diamonds sum into the one Less figure, exactly as the engine does.
        var carats = 0;
        // By whole name. "l_other_diamond_carat[]" also ends with "_diamond_carat[]",
        // so a tail search finds the right box here only because of the order the
        // columns happen to sit in — move them and the other-diamond carats get
        // counted twice while the diamond ones are never counted at all.
        ["_stone_carat[]", "_diamond_carat[]", "_other_diamond_carat[]"].forEach(function (suffix) {
            var caratField = rowField(row, suffix);
            var v = caratField ? parseFloat(caratField.value) : 0;
            if (isFinite(v) && v > 0) { carats += v; }
        });
        less.dataset.jwDerived = "1";
        less.dataset.jwAuto = "1";
        less.value = carats > 0 ? (carats * 0.2 / grams).toFixed(4) : "0";
        less.dispatchEvent(new Event("input", { bubbles: true }));
        delete less.dataset.jwAuto;
    });

    // Modal for creating new items during kaligadh assignment
    var itemModal = document.getElementById("jw-item-modal");
    var itemForm = document.getElementById("jw-item-create-form");
    var closeBtn = document.getElementById("jw-modal-close");
    var cancelBtn = document.getElementById("jw-modal-cancel");
    var submitBtn = document.getElementById("jw-modal-submit");
    var errorDiv = document.getElementById("jw-modal-error");
    var successDiv = document.getElementById("jw-modal-success");
    var metalSelect = document.getElementById("jw-modal-metal");
    var puritySelect = document.getElementById("jw-modal-purity");
    var unitSelect = document.getElementById("jw-modal-unit");
    var currentItemSelect = null;
    var modalDropdownsPopulated = false;

    function closeModal() {
        itemModal.style.display = "none";
        itemForm.reset();
        errorDiv.style.display = "none";
        successDiv.style.display = "none";
        currentItemSelect = null;
    }

    // Purity follows metal, exactly as it does on the item master's own form:
    // a 22K gold purity has no business on a silver item, and offering it is
    // how an item ends up saved against a purity of the wrong metal.
    var allPurityOptions = puritySelect ? Array.prototype.slice.call(puritySelect.options) : [];

    function syncModalPurities() {
        if (!metalSelect || !puritySelect) { return; }
        var keep = puritySelect.value;
        puritySelect.innerHTML = "";
        allPurityOptions.forEach(function (opt) {
            if (opt.getAttribute("data-metal") === metalSelect.value) { puritySelect.appendChild(opt); }
        });
        if (keep) { puritySelect.value = keep; }
        // Nothing survived the filter, so the metal has none defined yet. Say so
        // rather than showing an empty box the user cannot act on.
        if (!puritySelect.options.length) {
            var none = document.createElement("option");
            none.value = "";
            none.textContent = "— no purity set for this metal —";
            puritySelect.appendChild(none);
        }
    }

    function populateModalDropdowns() {
        if (modalDropdownsPopulated) { return; }
        syncModalPurities();
        modalDropdownsPopulated = true;
    }

    if (metalSelect) { metalSelect.addEventListener("change", syncModalPurities); }

    function openModal(itemSelect) {

        currentItemSelect = itemSelect;
        populateModalDropdowns();

        // Get CSRF token from page if not already set
        var csrfInput = document.getElementById("jw-csrf-token");
        if (!csrfInput.value) {
            var pageCSRFToken = document.querySelector("input[name='csrf_token']");
            if (pageCSRFToken) {
                csrfInput.value = pageCSRFToken.value;
            }
        }

        itemModal.style.display = "block";
        errorDiv.style.display = "none";
        successDiv.style.display = "none";
        // Focus on first input
        itemForm.querySelector('input[name="code"]').focus();
    }

    closeBtn.addEventListener("click", closeModal);
    cancelBtn.addEventListener("click", closeModal);

    // Close modal when clicking outside the dialog
    itemModal.addEventListener("click", function(e) {
        if (e.target === itemModal) { closeModal(); }
    });

    // Handle "+ Add item" button click
    document.addEventListener("click", function(event) {
        var addBtn = event.target.closest(".jw-add-item-btn");
        if (!addBtn) { return; }

        event.preventDefault();
        event.stopPropagation();

        var row = addBtn.closest("tr");
        if (!row) { return; }

        var itemSelect = row.querySelector("select[name$='_item_id[]']");
        if (!itemSelect) { return; }

        openModal(itemSelect);
    });

    // Handle form submission via AJAX
    itemForm.addEventListener("submit", function(e) {
        e.preventDefault();
        errorDiv.style.display = "none";
        successDiv.style.display = "none";

        var formData = new FormData(itemForm);
        var submitOriginalText = submitBtn.textContent;
        submitBtn.textContent = "Creating...";
        submitBtn.disabled = true;

        fetch('/admin/jewellery.php', {
            method: "POST",
            body: formData
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error("Network response was not ok");
            }
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                // Add new item to the ITEM dropdown
                if (currentItemSelect && data.item_id && data.item_name && data.item_code) {
                    var newOption = document.createElement("option");
                    newOption.value = data.item_id;
                    newOption.textContent = data.item_code + " — " + data.item_name;
                    currentItemSelect.appendChild(newOption);
                    // And into the shared list, so every other row — and any row
                    // added after this — offers the item that was just created.
                    var sharedTemplate = document.getElementById("jw-item-options");
                    if (sharedTemplate) { sharedTemplate.appendChild(newOption.cloneNode(true)); }
                    Array.prototype.forEach.call(document.querySelectorAll("select.c-item"), function (other) {
                        if (other !== currentItemSelect && !other.querySelector('option[value="' + data.item_id + '"]')) {
                            other.appendChild(newOption.cloneNode(true));
                        }
                    });

                    // Explicitly set the select value to display the newly created item
                    currentItemSelect.value = data.item_id;

                    // Trigger change event to notify any listeners
                    currentItemSelect.dispatchEvent(new Event("change", { bubbles: true }));

                    successDiv.textContent = "Item created successfully!";
                    successDiv.style.display = "block";

                    // Close modal after 1 second
                    setTimeout(closeModal, 1000);
                } else {
                    throw new Error("Invalid response data");
                }
            } else {
                errorDiv.textContent = data.message || "An error occurred";
                errorDiv.style.display = "block";
            }
        })
        .catch(function(error) {
            console.error("Error:", error);
            errorDiv.textContent = "Failed to create item: " + error.message;
            errorDiv.style.display = "block";
        })
        .finally(function() {
            submitBtn.textContent = submitOriginalText;
            submitBtn.disabled = false;
        });
    });
})();
</script>
    <?php
}
