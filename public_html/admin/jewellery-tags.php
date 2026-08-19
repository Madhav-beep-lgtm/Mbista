<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/jewellery_stock.php';
require_once __DIR__ . '/../../app/jewellery_tag.php';

accounting_module_repair_database();
require_jewellery();

$company = current_company();
if (!$company) {
    flash('error', 'Company context required.');
    redirect('admin/accounting-dashboard.php');
}
$companyId = (int) $company['id'];
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$canEdit = user_can_do('jewellery', 'edit');

$cfg = jewellery_tag_settings($companyId);

/**
 * The item ids a request is about, from either the checkbox list or a direct
 * ?items= link, so that "print this one" from the item master and "print these
 * forty" from here run through exactly the same code.
 */
$requestedIds = static function (): array {
    $raw = $_REQUEST['items'] ?? [];
    if (is_string($raw)) {
        $raw = explode(',', $raw);
    }
    $ids = [];
    foreach ((array) $raw as $id) {
        $id = (int) trim((string) $id);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
};

/** Item rows for those ids, this company's only, in the order given. */
$loadItems = static function (array $ids) use ($companyId): array {
    $items = [];
    foreach ($ids as $id) {
        $item = jewellery_item($companyId, (int) $id);
        if ($item !== null) {
            $items[] = $item;
        }
    }

    return $items;
};

// ---------------------------------------------------------------------------
// ZPL out. Browser Print fetches this and hands it to the printer; the download
// link serves the same bytes as a file, which is the way through when the agent
// is not installed on a particular PC.
// ---------------------------------------------------------------------------
$zplMode = (string) ($_GET['zpl'] ?? '');
if ($zplMode !== '') {
    $copies = max(1, min(20, (int) ($_GET['copies'] ?? 1)));
    if ($zplMode === 'calibration') {
        $zpl = jewellery_tag_calibration_zpl($cfg);
        $filename = 'tag-calibration.zpl';
    } else {
        $items = $loadItems($requestedIds());
        $zpl = jewellery_tag_batch_zpl($items, $cfg, $copies);
        $filename = count($items) === 1
            ? 'tag-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($items[0]['code'] ?? $items[0]['sku'] ?? 'item')) . '.zpl'
            : 'tags-' . count($items) . '.zpl';
    }

    if (($_GET['download'] ?? '') !== '') {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    } else {
        header('Content-Type: text/plain; charset=utf-8');
    }
    header('Content-Length: ' . strlen($zpl));
    echo $zpl;
    exit;
}

// ---------------------------------------------------------------------------
// Settings. The geometry of a tag is stock the shop bought, not something the
// software can know, so it is typed here and proved with a calibration print.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_tag_settings') {
    verify_csrf();
    if (!$canEdit) {
        flash('error', 'You do not have permission to change tag settings.');
        redirect('admin/jewellery-tags.php');
    }
    require_permission('jewellery', 'edit');

    $num = static fn (string $key, float $min, float $max, float $fallback): float
        => min($max, max($min, (float) ($_POST[$key] ?? $fallback)));

    db()->prepare('UPDATE jewellery_settings SET tag_shop_name = :shop, tag_width_mm = :w, tag_height_mm = :h,
            tag_gap_mm = :gap, tag_wing_mm = :wing, tag_dpi = :dpi, tag_darkness = :dark, tag_speed = :speed,
            tag_rotation = :rot, tag_offset_x_mm = :ox, tag_offset_y_mm = :oy, tag_media = :media,
            tag_hide_empty_stone = :hidestone, updated_by = :uid
        WHERE company_id = :cid')
        ->execute([
            'shop' => mb_substr(trim((string) ($_POST['tag_shop_name'] ?? '')), 0, 60) ?: null,
            'w' => $num('tag_width_mm', 5, 200, 12),
            'h' => $num('tag_height_mm', 5, 400, 75),
            'gap' => $num('tag_gap_mm', 0, 20, 3),
            'wing' => $num('tag_wing_mm', 0, 120, 22),
            'dpi' => (int) $num('tag_dpi', 150, 600, 203),
            'dark' => (int) $num('tag_darkness', 0, 30, 15),
            'speed' => (int) $num('tag_speed', 1, 14, 3),
            'rot' => in_array((string) ($_POST['tag_rotation'] ?? '0'), ['0', '90', '180', '270'], true)
                ? (string) $_POST['tag_rotation'] : '0',
            'ox' => $num('tag_offset_x_mm', -50, 50, 0),
            'oy' => $num('tag_offset_y_mm', -50, 50, 0),
            'media' => in_array((string) ($_POST['tag_media'] ?? 'gap'), ['gap', 'continuous', 'mark'], true)
                ? (string) $_POST['tag_media'] : 'gap',
            'hidestone' => isset($_POST['tag_hide_empty_stone']) ? 1 : 0,
            'uid' => $userId ?: null,
            'cid' => $companyId,
        ]);
    log_activity('company', $companyId, 'jewellery_tag_settings', 'Tag print settings updated.', $userId ?: null);
    flash('success', 'Tag settings saved. Print a calibration tag to check them against the stock.');
    redirect('admin/jewellery-tags.php');
}

$search = trim((string) ($_GET['search'] ?? ''));
$allItems = jewellery_items_list($companyId, ['active_only' => 1, 'search' => $search]);
// A page at a time. The whole master went into one scrolling box — two MB of it
// on a two-thousand-piece shop — to be scrolled past looking for a few rings.
// Tags are printed a batch at a time anyway, so a page IS the batch; the search
// above is how you get to the pieces you want.
$tagPerPage = (int) ($_GET['per_page'] ?? 50);
if (!in_array($tagPerPage, [25, 50, 100, 200], true)) {
    $tagPerPage = 50;
}
$tagPageCount = max(1, (int) ceil(count($allItems) / $tagPerPage));
$tagPage = max(1, min($tagPageCount, (int) ($_GET['page'] ?? 1)));
$items = array_slice($allItems, ($tagPage - 1) * $tagPerPage, $tagPerPage);
$tagPageUrl = static function (array $overrides) use ($search, $tagPerPage): string {
    return url('admin/jewellery-tags.php?' . http_build_query(array_merge(array_filter([
        'search' => $search,
        'per_page' => (string) $tagPerPage,
    ], static fn ($v): bool => (string) $v !== ''), $overrides)));
};
$selected = $requestedIds();

$pageTitle = 'Print Tags';
$pageSubtitle = 'Barcode tags for the showcase, printed on a Zebra ZD230.';
$pageHero = ['icon' => 'documents'];
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>

<div id="tag-print-status" class="notice" style="display:none"></div>

<section class="mbw-card" aria-label="Print tags">
    <div class="mbw-card-head">
        <h2>Print tags</h2>
        <span class="frm-optional">Tick the pieces, then print. The tag is drawn by the printer itself, so the barcode is as crisp as the ZD230 can make it. Ticks apply to the page you are on — print it, then move to the next.</span>
    </div>

    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Code, name or design no" style="min-width:220px">
        <button type="submit" class="button secondary"><?= icon('analytics') ?>Search</button>
        <?php if ($search !== ''): ?>
            <a class="mbw-view-all" href="<?= e(url('admin/jewellery-tags.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($allItems === []): ?>
        <p class="frm-optional">No items match. Add stock first, or clear the search.</p>
    <?php else: ?>
    <form id="tag-item-form">
        <div style="overflow-x:auto;max-height:460px;overflow-y:auto">
            <table class="mbw-table">
                <thead><tr>
                    <th style="width:34px"><input type="checkbox" id="tag-select-all" aria-label="Select all"></th>
                    <th>Code</th><th>Item</th><th>Purity</th>
                    <th class="num">Gross</th><th class="num">Stone</th><th class="num">Net</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($items as $row): ?>
                    <?php $id = (int) $row['id']; $unit = strtoupper((string) ($row['unit_code'] ?? '')); ?>
                    <tr>
                        <td><input type="checkbox" class="tag-item" name="items[]" value="<?= $id ?>"
                                   <?= in_array($id, $selected, true) ? 'checked' : '' ?>></td>
                        <td><?= e((string) ($row['code'] ?? $row['sku'] ?? '')) ?></td>
                        <td><?= e((string) $row['name']) ?></td>
                        <td><?= e((string) ($row['purity_code'] ?? '')) ?></td>
                        <td class="num"><?= e(number_format((float) $row['gross_weight'], 3)) ?> <?= e($unit) ?></td>
                        <td class="num"><?= (float) $row['stone_weight'] > 0 ? e(number_format((float) $row['stone_weight'], 3)) : '—' ?></td>
                        <td class="num"><?= e(number_format((float) $row['net_weight'], 3)) ?> <?= e($unit) ?></td>
                        <td style="white-space:nowrap">
                            <button type="button" class="button secondary" data-zpl-url="<?= e(url('admin/jewellery-tags.php?zpl=items&items=' . $id)) ?>">Print</button>
                            <a class="mbw-view-all" href="<?= e(url('admin/jewellery-tags.php?zpl=items&download=1&items=' . $id)) ?>">.zpl</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php if ($tagPageCount > 1): ?>
            <nav class="actions" style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap" aria-label="Tag pages">
                <?php if ($tagPage > 1): ?><a class="button secondary" href="<?= e($tagPageUrl(['page' => $tagPage - 1])) ?>">Previous</a><?php endif; ?>
                <span>Page <?= (int) $tagPage ?> of <?= (int) $tagPageCount ?> · <?= count($allItems) ?> item(s)</span>
                <?php if ($tagPage < $tagPageCount): ?><a class="button secondary" href="<?= e($tagPageUrl(['page' => $tagPage + 1])) ?>">Next</a><?php endif; ?>
                <span style="margin-left:auto;display:flex;gap:6px;align-items:center">Rows
                    <?php foreach ([25, 50, 100, 200] as $size): ?>
                        <a class="button soft" style="<?= $size === $tagPerPage ? 'font-weight:700' : '' ?>"
                           href="<?= e($tagPageUrl(['per_page' => (string) $size, 'page' => 1])) ?>"><?= $size ?></a>
                    <?php endforeach; ?>
                </span>
            </nav>
        <?php endif; ?>
        </div>

        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px">
            <label style="margin:0">Copies of each
                <input type="number" id="tag-copies" value="1" min="1" max="20" style="width:80px">
            </label>
            <button type="button" class="button" id="tag-print-selected" data-zpl-url=""><?= icon('documents') ?>Print selected</button>
            <a class="button secondary" id="tag-download-selected" href="#">Download .zpl</a>
            <span class="frm-optional" id="tag-selected-count">0 selected</span>
        </div>
    </form>
    <?php endif; ?>
</section>

<section class="mbw-card" aria-label="Tag stock and printer">
    <div class="mbw-card-head">
        <h2>Tag stock &amp; printer</h2>
        <span class="frm-optional">Measure a real tag with a ruler and type it here</span>
    </div>
    <p class="frm-optional" style="margin:0 0 12px">
        <strong>Width is across the print head; height is along the direction of feed.</strong>
        For a 75&nbsp;mm dumbbell strip that feeds end-first, that is width&nbsp;12 and height&nbsp;75 — not the
        other way round. Save, then print a calibration tag: it draws a border the exact size these numbers claim,
        so holding it against a real tag shows whether it is right and by how much it is out.
    </p>

    <form method="post" class="workspace-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_tag_settings">

        <label>Shop name on the tag<input type="text" name="tag_shop_name" maxlength="60"
                value="<?= e((string) $cfg['shop_name']) ?>" placeholder="Defaults to the company name"></label>
        <label>Width across the head (mm)<input type="number" step="0.1" min="5" max="200" name="tag_width_mm"
                value="<?= e((string) $cfg['width_mm']) ?>"></label>
        <label>Height along the feed (mm)<input type="number" step="0.1" min="5" max="400" name="tag_height_mm"
                value="<?= e((string) $cfg['height_mm']) ?>"></label>
        <label>Gap between tags (mm)<input type="number" step="0.1" min="0" max="20" name="tag_gap_mm"
                value="<?= e((string) $cfg['gap_mm']) ?>"></label>
        <label>Barcode wing at each end (mm)<input type="number" step="0.1" min="0" max="120" name="tag_wing_mm"
                value="<?= e((string) $cfg['wing_mm']) ?>"></label>
        <label>Printer resolution
            <select name="tag_dpi">
                <?php foreach ([203 => '203 dpi (ZD230)', 300 => '300 dpi', 600 => '600 dpi'] as $dpi => $label): ?>
                    <option value="<?= $dpi ?>" <?= (int) $cfg['dpi'] === $dpi ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Darkness (0–30)<input type="number" min="0" max="30" name="tag_darkness"
                value="<?= e((string) $cfg['darkness']) ?>"></label>
        <label>Speed (in/sec)<input type="number" min="1" max="14" name="tag_speed"
                value="<?= e((string) $cfg['speed']) ?>"></label>
        <label>Text direction
            <select name="tag_rotation">
                <?php foreach (['0' => 'Along the tag (0°)', '90' => 'Rotated 90°', '180' => 'Upside down (180°)', '270' => 'Rotated 270°'] as $deg => $label): ?>
                    <option value="<?= e($deg) ?>" <?= (string) $cfg['rotation'] === $deg ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Media sensing
            <select name="tag_media">
                <?php foreach (['gap' => 'Gap / die-cut tags', 'continuous' => 'Continuous strip', 'mark' => 'Black mark'] as $m => $label): ?>
                    <option value="<?= e($m) ?>" <?= (string) $cfg['media'] === $m ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Nudge across (mm)<input type="number" step="0.1" min="-50" max="50" name="tag_offset_x_mm"
                value="<?= e((string) $cfg['offset_x_mm']) ?>"></label>
        <label>Nudge along (mm)<input type="number" step="0.1" min="-50" max="50" name="tag_offset_y_mm"
                value="<?= e((string) $cfg['offset_y_mm']) ?>"></label>
        <label style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="tag_hide_empty_stone" <?= $cfg['hide_empty_stone'] ? 'checked' : '' ?>>
            Hide the Stone line when there is no stone
        </label>

        <div style="grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap">
            <button type="submit" class="button" <?= $canEdit ? '' : 'disabled' ?>><?= icon('layers') ?>Save settings</button>
            <button type="button" class="button secondary" data-zpl-url="<?= e(url('admin/jewellery-tags.php?zpl=calibration')) ?>">Print calibration tag</button>
            <a class="mbw-view-all" href="<?= e(url('admin/jewellery-tags.php?zpl=calibration&download=1')) ?>">Download calibration .zpl</a>
        </div>
    </form>
</section>

<section class="mbw-card" aria-label="Printer setup">
    <div class="mbw-card-head"><h2>One-time setup on each billing PC</h2></div>
    <p class="frm-optional" style="margin:0">
        Printing straight from this screen needs <strong>Zebra Browser Print</strong> installed on the PC doing the
        printing — it is a small free agent from zebra.com that lets the browser reach the ZD230. Install it, make sure
        its icon is in the system tray, and the Print buttons work. Without it, use <strong>Download .zpl</strong> and
        send the file to the printer (drag it onto Zebra Setup Utilities, or copy it to the printer share); the tag
        comes out identical either way, because it is the same ZPL.
    </p>
</section>

<script src="<?= e(asset_url('assets/js/zebra-tag-print.js')) ?>"></script>
<script>
(function () {
    var form = document.getElementById('tag-item-form');
    if (!form) { return; }
    var printButton = document.getElementById('tag-print-selected');
    var downloadLink = document.getElementById('tag-download-selected');
    var counter = document.getElementById('tag-selected-count');
    var copies = document.getElementById('tag-copies');
    var base = <?= json_encode(url('admin/jewellery-tags.php')) ?>;

    // Both the print button and the download link point at the same ZPL, so a PC
    // without Browser Print gets exactly the labels a PC with it would.
    function refresh() {
        var ids = Array.prototype.slice.call(form.querySelectorAll('.tag-item:checked')).map(function (c) { return c.value; });
        var query = '?zpl=items&copies=' + encodeURIComponent(copies.value || '1') + '&items=' + ids.join(',');
        printButton.setAttribute('data-zpl-url', base + query);
        downloadLink.setAttribute('href', base + query + '&download=1');
        printButton.disabled = ids.length === 0;
        counter.textContent = ids.length + ' selected';
    }

    form.addEventListener('change', refresh);
    copies.addEventListener('input', refresh);
    var all = document.getElementById('tag-select-all');
    if (all) {
        all.addEventListener('change', function () {
            form.querySelectorAll('.tag-item').forEach(function (c) { c.checked = all.checked; });
            refresh();
        });
    }
    refresh();
}());
</script>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
