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
/* The line grid carries sixteen columns. At the page's ordinary control size
   that is far wider than any screen, so the shop scrolls sideways to reach the
   stone columns and can never see a line whole. Everything below exists to fit
   the whole line in one view: fixed column widths that add up to roughly
   1120px, inputs that fill their cell instead of carrying their own width, and
   a smaller type size for the grid alone. */
table.jw-lines { font-size: .82rem; table-layout: fixed; width: 100%; min-width: 1120px; }
table.jw-lines th,
table.jw-lines td { padding: 2px 3px; }
table.jw-lines thead th { font-size: .72rem; line-height: 1.15; text-align: center; white-space: nowrap; }
table.jw-lines input,
table.jw-lines select {
    width: 100%;
    min-width: 0;
    min-height: 28px;
    padding: 2px 4px;
    font-size: .82rem;
}
table.jw-lines input[type="number"] { text-align: right; }
/* Number spinners eat about 16px of every cell and are useless for a weight
   typed to four decimals. */
table.jw-lines input[type="number"] { -moz-appearance: textfield; }
table.jw-lines input[type="number"]::-webkit-outer-spin-button,
table.jw-lines input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
table.jw-lines .c-item { width: 178px; }
table.jw-lines .c-sel  { width: 66px; }
table.jw-lines .c-unit { width: 56px; }
table.jw-lines .c-pcs  { width: 50px; }
table.jw-lines .c-wt   { width: 68px; }
table.jw-lines .c-rate { width: 86px; }
table.jw-lines .c-crt  { width: 58px; }
table.jw-lines .c-amt  { width: 78px; }
@media (max-width: 1180px) {
    /* Below this there is no honest way to fit sixteen columns, so the grid
       goes back to scrolling rather than crushing the inputs to nothing. */
    table.jw-lines { font-size: .78rem; }
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
    ?>
    <?php $full = $prefix === 'l'; ?>
    <fieldset style="border:1px solid var(--mbw-border,#d9e2ec);border-radius:10px;padding:10px;margin:12px 0">
        <legend style="padding:0 6px;font-weight:600"><?= $legend ?></legend>
        <div style="overflow-x:auto"><table class="jw-lines">
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
                </tr>
            </thead>
            <tbody>
            <?php for ($i = 0; $i < $slots; $i++): $row = $existing[$i] ?? null; ?>
                <tr>
                    <td>
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
                </tr>
            <?php endfor; ?>
            </tbody>
        </table></div>
        <p class="frm-optional" style="margin:6px 0 0">Net wt = gross − less. The customer is charged on net + wastage, but only the
            net metal leaves stock. Punch the wastage as a % or as a weight — the other follows. Rate 0 prices from the daily board.</p>
    </fieldset>
<?php
}
