<?php
declare(strict_types=1);

/**
 * The filter bar shared by every jewellery list.
 *
 * Two tiers on purpose. The top row is what a counter reaches for twenty times
 * a day — a search box and a date range — and it is always visible. Everything
 * else lives behind Advanced filter, collapsed, because a bar carrying eight
 * controls is one nobody reads. The panel opens by itself when one of its
 * fields is already in use, so a filtered list never hides the reason it is
 * short.
 *
 * It is a GET form: the filters end up in the URL, so a filtered list can be
 * bookmarked, sent to somebody, or reloaded without being rebuilt by hand.
 *
 * $ctx:
 *     hidden    fixed query values to carry through (view, etc.)
 *     dates     false to leave the from/to pair out
 *     advanced  extra controls, each ['label' => ?, 'html' => ?]
 *     reset     URL the Clear button returns to
 */
function jw_render_filter_bar(array $ctx): void
{
    $hidden = (array) ($ctx['hidden'] ?? []);
    $advanced = (array) ($ctx['advanced'] ?? []);
    $withDates = ($ctx['dates'] ?? true) !== false;
    $resetUrl = (string) ($ctx['reset'] ?? '');
    $searchValue = (string) ($ctx['search'] ?? '');
    $from = (string) ($ctx['from'] ?? '');
    $to = (string) ($ctx['to'] ?? '');
    $minDate = (string) ($ctx['min_date'] ?? '');
    $maxDate = (string) ($ctx['max_date'] ?? '');
    $advancedInUse = (bool) ($ctx['advanced_in_use'] ?? false);
    ?>
    <form method="get" class="jw-filter">
        <?php foreach ($hidden as $hiddenName => $hiddenValue): ?>
            <input type="hidden" name="<?= e((string) $hiddenName) ?>" value="<?= e((string) $hiddenValue) ?>">
        <?php endforeach; ?>
        <div class="jw-filter-row">
            <label>Search<input type="search" name="q" value="<?= e($searchValue) ?>"></label>
            <?php if ($withDates): ?>
                <label>From<input type="date" name="from" value="<?= e($from) ?>"
                    <?= $minDate !== '' ? 'min="' . e($minDate) . '"' : '' ?>
                    <?= $maxDate !== '' ? 'max="' . e($maxDate) . '"' : '' ?>></label>
                <label>To<input type="date" name="to" value="<?= e($to) ?>"
                    <?= $minDate !== '' ? 'min="' . e($minDate) . '"' : '' ?>
                    <?= $maxDate !== '' ? 'max="' . e($maxDate) . '"' : '' ?>></label>
            <?php endif; ?>
            <div class="jw-filter-buttons">
                <button type="submit" class="button secondary">Filter</button>
                <?php if ($resetUrl !== ''): ?>
                    <a class="button soft" href="<?= e($resetUrl) ?>">Clear</a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($advanced !== []): ?>
            <details class="jw-filter-advanced"<?= $advancedInUse ? ' open' : '' ?>>
                <summary>Advanced filter</summary>
                <div class="jw-filter-row">
                    <?php foreach ($advanced as $control): ?>
                        <label><?= e((string) ($control['label'] ?? '')) ?><?= $control['html'] ?? '' ?></label>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    </form>
    <?php
}

/**
 * A dropdown for the filter bar, with "any" as its empty choice.
 *
 * $options is value => label, which is what array_column($rows, 'name', 'id')
 * already gives — so a list of parties or kaligads becomes a filter without a
 * loop at the call site.
 */
function jw_filter_select(string $name, string $selected, array $options, string $anyLabel = '— any —'): string
{
    $html = '<select name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">'
        . '<option value="">' . htmlspecialchars($anyLabel, ENT_QUOTES, 'UTF-8') . '</option>';
    foreach ($options as $value => $label) {
        $value = (string) $value;
        $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"'
            . ($selected !== '' && $selected === $value ? ' selected' : '') . '>'
            . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</option>';
    }

    return $html . '</select>';
}

/** The filter bar's styling. Safe to call more than once per request. */
function jw_filter_bar_styles(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<style>
.jw-filter { margin: 0 0 12px; }
.jw-filter-row {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 10px;
}
.jw-filter-row > label {
    display: flex;
    flex-direction: column;
    gap: 3px;
    font-size: .82rem;
    min-width: 0;
    flex: 1 1 170px;
    max-width: 260px;
}
.jw-filter-row > label > input,
.jw-filter-row > label > select { min-height: 34px; width: 100%; }
.jw-filter-buttons { display: flex; gap: 6px; align-items: flex-end; }
.jw-filter-buttons .button { min-height: 34px; padding: 5px 14px; }
.jw-filter-advanced { margin-top: 10px; }
.jw-filter-advanced > summary { cursor: pointer; font-size: .82rem; font-weight: 600; padding: 2px 0; }
.jw-filter-advanced[open] > summary { margin-bottom: 8px; }
</style>
    <?php
}
