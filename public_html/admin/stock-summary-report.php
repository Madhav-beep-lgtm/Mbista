<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/stock_report_engine.php';

require_staff_admin_or_client_books();
require_company_context();
require_permission('inventory', 'view');
accounting_module_repair_database();

$company = current_company();
$fiscalYear = current_fiscal_year();
if (!$company || !$fiscalYear) {
    flash('error', 'Company and fiscal year context required.');
    redirect('admin/accounting-dashboard.php');
}
$companyId = (int) $company['id'];
$userId = (int) (current_user()['id'] ?? 0);
$sym = site_currency_symbol();
$fyStart = (string) $fiscalYear['start_date'];
$fyEnd = (string) $fiscalYear['end_date'];

// ---------------------------------------------------------------------------
// Location-specific item type mapping (FG here, RM there).
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'set_location_type') {
        require_permission('inventory', 'edit');
        $mapItemId = (int) ($_POST['map_item_id'] ?? 0);
        $mapWarehouseId = (int) ($_POST['map_warehouse_id'] ?? 0);
        $mapType = (string) ($_POST['map_item_type'] ?? '');
        $own = db()->prepare('SELECT COUNT(*) FROM inventory_items WHERE id = :id AND company_id = :cid');
        $own->execute(['id' => $mapItemId, 'cid' => $companyId]);
        $ownWh = db()->prepare('SELECT COUNT(*) FROM warehouses WHERE id = :id AND company_id = :cid');
        $ownWh->execute(['id' => $mapWarehouseId, 'cid' => $companyId]);
        if ((int) $own->fetchColumn() === 0 || (int) $ownWh->fetchColumn() === 0 || !array_key_exists($mapType, sr_item_type_labels() + ['' => ''])) {
            flash('error', 'Pick a valid item, location, and type.');
        } elseif ($mapType === '') {
            db()->prepare('DELETE FROM inventory_item_location_types WHERE item_id = :iid AND warehouse_id = :wid AND company_id = :cid')
                ->execute(['iid' => $mapItemId, 'wid' => $mapWarehouseId, 'cid' => $companyId]);
            flash('success', 'Location-specific type cleared — the item master type applies again.');
        } else {
            db()->prepare('INSERT INTO inventory_item_location_types (company_id, item_id, warehouse_id, item_type, is_active, created_by)
                    VALUES (:cid, :iid, :wid, :t, 1, :by)
                    ON DUPLICATE KEY UPDATE item_type = VALUES(item_type), is_active = 1')
                ->execute(['cid' => $companyId, 'iid' => $mapItemId, 'wid' => $mapWarehouseId, 't' => $mapType, 'by' => $userId]);
            flash('success', 'Item type set for that location (e.g. FG at the producing department, RM at the consuming one).');
        }
        redirect('admin/stock-summary-report.php?' . http_build_query(array_diff_key($_GET, ['lang' => ''])));
    }
}

// ---------------------------------------------------------------------------
// Filters — dates are clamped INSIDE the selected fiscal year.
// ---------------------------------------------------------------------------
$clampDate = static function (string $value, string $fallback) use ($fyStart, $fyEnd): string {
    $value = trim($value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $fallback;
    }
    return max($fyStart, min($fyEnd, $value));
};
$from = $clampDate((string) ($_GET['from'] ?? ''), $fyStart);
$to = $clampDate((string) ($_GET['to'] ?? ''), $fyEnd);
if ($to < $from) {
    $to = $from;
}
$filters = [
    'from' => $from,
    'to' => $to,
    'warehouse_ids' => array_values(array_filter(array_map('intval', (array) ($_GET['warehouses'] ?? [])))),
    'types' => array_values(array_filter((array) ($_GET['types'] ?? []))),
    'valuation' => in_array((string) ($_GET['valuation'] ?? ''), ['fifo', 'weighted_average', 'specific'], true) ? (string) $_GET['valuation'] : '',
    'search' => trim((string) ($_GET['q'] ?? '')),
    'stock_status' => in_array((string) ($_GET['status'] ?? ''), ['positive', 'zero', 'negative'], true) ? (string) $_GET['status'] : '',
    'zero_movement' => !isset($_GET['applied']) || isset($_GET['zero_movement']),
    'zero_closing' => !isset($_GET['applied']) || isset($_GET['zero_closing']),
    'group_by' => in_array((string) ($_GET['group_by'] ?? ''), ['type', 'valuation'], true) ? (string) $_GET['group_by'] : '',
];

$report = sr_stock_summary($companyId, $filters);
$rows = $report['rows'];
$totals = $report['totals'];

// Server-side pagination over the computed rows (totals stay whole-report).
$perPageRaw = (int) ($_GET['per_page'] ?? 25);
$perPage = in_array($perPageRaw, [25, 50, 100, 200], true) ? $perPageRaw : 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageCount = max(1, (int) ceil(count($rows) / $perPage));
$page = min($page, $pageCount);
$pageRows = array_slice($rows, ($page - 1) * $perPage, $perPage);

// The exact filter query string, reused by exports and the drill-down return.
$qs = http_build_query(array_filter([
    'from' => $from, 'to' => $to,
    'warehouses' => $filters['warehouse_ids'] ?: null,
    'types' => $filters['types'] ?: null,
    'valuation' => $filters['valuation'] ?: null,
    'q' => $filters['search'] ?: null,
    'status' => $filters['stock_status'] ?: null,
    'zero_movement' => $filters['zero_movement'] ? 1 : null,
    'zero_closing' => $filters['zero_closing'] ? 1 : null,
    'group_by' => $filters['group_by'] ?: null,
    'applied' => 1,
], static fn ($v): bool => $v !== null));

// ---------------------------------------------------------------------------
// Exports: CSV/Excel via export_csv (numeric cells raw), PDF/print via HTML.
// ---------------------------------------------------------------------------
$export = (string) ($_GET['export'] ?? '');
if ($export !== '') {
    require_permission('reports', 'export');
    $header = ['S.N.', 'Item Code', 'Item Name', 'Item Type', 'Department / Location', 'UOM',
        'Opening Qty', 'Opening Rate', 'Opening Amount',
        'Inward Qty', 'Inward Rate', 'Inward Amount',
        'Outward Qty', 'Outward Rate', 'Outward Amount',
        'Damage Qty', 'Damage Rate', 'Damage Amount',
        'Closing Qty', 'Closing Rate', 'Closing Amount', 'Valuation Method'];
    $data = [
        [(string) $company['name']],
        ['Stock Summary Report'],
        ['Fiscal year', (string) $fiscalYear['label'], 'From', $from, 'To', $to],
        ['Locations', $filters['warehouse_ids'] === [] ? 'All' : implode(',', $filters['warehouse_ids']),
            'Types', $filters['types'] === [] ? 'All' : implode(',', $filters['types']),
            'Valuation', $filters['valuation'] ?: 'All'],
        ['Generated', date('Y-m-d H:i'), 'By', (string) (current_user()['name'] ?? '')],
        [],
        $header,
    ];
    foreach ($rows as $i => $r) {
        $data[] = [$i + 1, $r['sku'], $r['name'], $r['item_type_label'], $r['location'], $r['unit'],
            $r['opening_qty'], $r['opening_rate'], $r['opening_amount'],
            $r['in_qty'], $r['in_rate'], $r['in_amount'],
            $r['out_qty'], $r['out_rate'], $r['out_amount'],
            $r['damage_qty'], $r['damage_rate'], $r['damage_amount'],
            $r['closing_qty'], $r['closing_rate'], $r['closing_amount'],
            strtoupper(str_replace('_', ' ', $r['valuation_method']))];
    }
    $data[] = [];
    $data[] = ['TOTALS', '', '', '', '', '', '', '', $totals['opening_amount'], '', '', $totals['in_amount'],
        '', '', $totals['out_amount'], '', '', $totals['damage_amount'], '', '', $totals['closing_amount'], ''];
    security_event('report_exported', 'success', 'Stock summary export (' . $export . ').', $companyId, $userId);
    if ($export === 'csv' || $export === 'excel') {
        export_csv('stock-summary-' . $company['code'] . '-' . $from . '-to-' . $to . ($export === 'excel' ? '.xls.csv' : '.csv'), $data);
        exit;
    }
    // print / pdf: landscape printable HTML with the same columns and totals.
    header('Content-Type: text/html; charset=utf-8');
    $fmt = static fn (float $n): string => number_format($n, 2);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Stock Summary — ' . e((string) $company['name']) . '</title><style>
        @page { size: A4 landscape; margin: 10mm; }
        body { font-family: "Segoe UI", system-ui, sans-serif; font-size: 10.5px; color: #16263e; margin: 16px; }
        h1 { font-size: 15px; margin: 0 0 2px; } .meta { color: #55657e; font-size: 10px; margin-bottom: 8px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #cdd6e4; padding: 3px 5px; }
        th { background: #eef2f9; } td.n, th.n { text-align: right; }
        tr.tot td { font-weight: 700; background: #eef2f9; }
        .neg { color: #b3261e; }
        button { margin-bottom: 8px; padding: 6px 12px; } @media print { button { display: none; } }
    </style></head><body>';
    echo '<button onclick="window.print()">Print / Save as PDF</button>';
    echo '<h1>' . e((string) $company['name']) . ' — Stock Summary Report</h1>';
    echo '<div class="meta">Fiscal year ' . e((string) $fiscalYear['label']) . ' · ' . e($from) . ' to ' . e($to)
        . ' · Locations: ' . e($filters['warehouse_ids'] === [] ? 'All' : implode(', ', $filters['warehouse_ids']))
        . ' · Valuation filter: ' . e($filters['valuation'] ?: 'All')
        . ' · Generated ' . e(date('Y-m-d H:i')) . '</div>';
    echo '<table><thead><tr><th colspan="6">Basic Item Information</th><th colspan="3" class="n">Opening</th><th colspan="3" class="n">Purchase / Inward</th><th colspan="3" class="n">Sold / Consumed / Outward</th><th colspan="3" class="n">Damage / Write-off</th><th colspan="3" class="n">Closing</th><th>Val.</th></tr><tr>';
    foreach (['S.N.', 'Code', 'Item', 'Type', 'Location', 'UOM'] as $h) { echo '<th>' . $h . '</th>'; }
    for ($i = 0; $i < 5; $i++) { echo '<th class="n">Qty</th><th class="n">Rate</th><th class="n">Amount</th>'; }
    echo '<th>Method</th></tr></thead><tbody>';
    foreach ($rows as $i => $r) {
        echo '<tr><td>' . ($i + 1) . '</td><td>' . e($r['sku']) . '</td><td>' . e($r['name']) . '</td><td>' . e($r['item_type_label']) . '</td><td>' . e($r['location']) . '</td><td>' . e($r['unit']) . '</td>';
        foreach ([['opening_qty', 'opening_rate', 'opening_amount'], ['in_qty', 'in_rate', 'in_amount'], ['out_qty', 'out_rate', 'out_amount'], ['damage_qty', 'damage_rate', 'damage_amount'], ['closing_qty', 'closing_rate', 'closing_amount']] as [$q, $ra, $am]) {
            $neg = $r[$q] < 0 ? ' neg' : '';
            echo '<td class="n' . $neg . '">' . number_format($r[$q], 3) . '</td><td class="n">' . $fmt($r[$ra]) . '</td><td class="n' . $neg . '">' . $fmt($r[$am]) . '</td>';
        }
        echo '<td>' . e(strtoupper(str_replace('_', ' ', $r['valuation_method']))) . '</td></tr>';
    }
    echo '<tr class="tot"><td colspan="6">Totals (amounts)</td>'
        . '<td colspan="2"></td><td class="n">' . $fmt($totals['opening_amount']) . '</td>'
        . '<td colspan="2"></td><td class="n">' . $fmt($totals['in_amount']) . '</td>'
        . '<td colspan="2"></td><td class="n">' . $fmt($totals['out_amount']) . '</td>'
        . '<td colspan="2"></td><td class="n">' . $fmt($totals['damage_amount']) . '</td>'
        . '<td colspan="2"></td><td class="n">' . $fmt($totals['closing_amount']) . '</td><td></td></tr>';
    echo '</tbody></table><div class="meta" style="margin-top:6px">Quantity totals are deliberately not combined across units. Outward and damage amounts are inventory cost, never selling price.</div></body></html>';
    exit;
}

$warehouses = inv_company_warehouses($companyId);
$allItemsStmt = db()->prepare("SELECT id, sku, name FROM inventory_items WHERE company_id = :cid AND item_type <> 'service' ORDER BY sku ASC");
$allItemsStmt->execute(['cid' => $companyId]);
$allItems = $allItemsStmt->fetchAll();

$pageTitle = 'Stock Summary Report';
$pageSubtitle = 'Item-wise stock movement and valuation — opening, inward, outward, damage, and closing for the selected period and locations.';
$pageHero = ['icon' => 'inventory'];
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card" aria-label="Stock summary filters">
    <div class="mbw-card-head">
        <h2><?= icon('inventory') ?>Stock Summary Report</h2>
        <div class="mbw-card-tools">
            <span class="mbw-pill tone-blue"><?= e((string) $fiscalYear['label']) ?> · <?= e($from) ?> → <?= e($to) ?></span>
            <a class="mbw-view-all" href="<?= e(url('admin/stock-summary-report.php?' . $qs)) ?>" title="Refresh with the same filters">&#8635; Refresh</a>
        </div>
    </div>
    <form method="get" class="workspace-form-grid">
        <input type="hidden" name="applied" value="1">
        <label>From (inside <?= e((string) $fiscalYear['label']) ?>)<input type="date" name="from" value="<?= e($from) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>"></label>
        <label>To<input type="date" name="to" value="<?= e($to) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>"></label>
        <label>Search (code / name)<input type="search" name="q" value="<?= e($filters['search']) ?>" placeholder="ITM- or name"></label>
        <label>Item types
            <details class="ssr-msel">
                <summary><?= $filters['types'] === [] ? 'All types' : count($filters['types']) . ' selected' ?></summary>
                <div class="ssr-msel-list" data-msel-all="All types">
                    <?php foreach (sr_item_type_labels() as $typeKey => $typeLabel): if ($typeKey === 'service') { continue; } ?>
                        <label><input type="checkbox" name="types[]" value="<?= e($typeKey) ?>" <?= in_array($typeKey, $filters['types'], true) ? 'checked' : '' ?>> <?= e($typeLabel) ?></label>
                    <?php endforeach; ?>
                </div>
            </details>
        </label>
        <label>Locations / warehouses
            <details class="ssr-msel">
                <summary><?= $filters['warehouse_ids'] === [] ? 'All locations' : count($filters['warehouse_ids']) . ' selected' ?></summary>
                <div class="ssr-msel-list" data-msel-all="All locations">
                    <?php if ($warehouses === []): ?><small style="color:var(--mbw-muted)">No warehouses defined — everything reports company-wide.</small><?php endif; ?>
                    <?php foreach ($warehouses as $wh): ?>
                        <label><input type="checkbox" name="warehouses[]" value="<?= (int) $wh['id'] ?>" <?= in_array((int) $wh['id'], $filters['warehouse_ids'], true) ? 'checked' : '' ?>> <?= e((string) $wh['name']) ?></label>
                    <?php endforeach; ?>
                </div>
            </details>
        </label>
        <label>Valuation method
            <select name="valuation">
                <option value="">All methods</option>
                <option value="fifo" <?= $filters['valuation'] === 'fifo' ? 'selected' : '' ?>>FIFO</option>
                <option value="weighted_average" <?= $filters['valuation'] === 'weighted_average' ? 'selected' : '' ?>>Weighted Average</option>
                <option value="specific" <?= $filters['valuation'] === 'specific' ? 'selected' : '' ?>>Specific Identification</option>
            </select>
        </label>
        <label>Stock status
            <select name="status">
                <option value="">All</option>
                <option value="positive" <?= $filters['stock_status'] === 'positive' ? 'selected' : '' ?>>Positive stock</option>
                <option value="zero" <?= $filters['stock_status'] === 'zero' ? 'selected' : '' ?>>Zero stock</option>
                <option value="negative" <?= $filters['stock_status'] === 'negative' ? 'selected' : '' ?>>Negative stock</option>
            </select>
        </label>
        <label>Group by
            <select name="group_by">
                <option value="">Item (code order)</option>
                <option value="type" <?= $filters['group_by'] === 'type' ? 'selected' : '' ?>>Item type</option>
                <option value="valuation" <?= $filters['group_by'] === 'valuation' ? 'selected' : '' ?>>Valuation method</option>
            </select>
        </label>
        <label style="flex-direction:row;display:flex;align-items:center;gap:8px"><input type="checkbox" name="zero_movement" <?= $filters['zero_movement'] ? 'checked' : '' ?> style="width:auto;min-height:auto"> Include zero-movement items</label>
        <label style="flex-direction:row;display:flex;align-items:center;gap:8px"><input type="checkbox" name="zero_closing" <?= $filters['zero_closing'] ? 'checked' : '' ?> style="width:auto;min-height:auto"> Include zero closing balance</label>
        <label>Rows per page
            <select name="per_page">
                <?php foreach ([25, 50, 100, 200] as $pp): ?><option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option><?php endforeach; ?>
            </select>
        </label>
        <div style="display:flex;gap:8px;align-items:flex-end">
            <button type="submit"><?= icon('search') ?>Apply Filters</button>
            <a class="button secondary" href="<?= e(url('admin/stock-summary-report.php')) ?>">Reset</a>
        </div>
    </form>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
        <?php if (user_can_do('reports', 'export')): ?>
            <a class="button secondary" href="?<?= e($qs) ?>&amp;export=csv"><?= icon('reports') ?>CSV</a>
            <a class="button secondary" href="?<?= e($qs) ?>&amp;export=excel"><?= icon('reports') ?>Excel</a>
            <a class="button secondary" target="_blank" href="?<?= e($qs) ?>&amp;export=print"><?= icon('reports') ?>Print / PDF</a>
        <?php endif; ?>
        <details class="pr-adjust" style="margin-left:auto">
            <summary class="button secondary">Columns…</summary>
            <div class="pr-adjust-form" style="width:230px" id="ssrColumnChooser">
                <?php foreach ([
                    'grp-open' => 'Opening Balance', 'grp-in' => 'Purchase / Inward',
                    'grp-out' => 'Sold / Consumed / Outward', 'grp-dmg' => 'Damage / Write-off',
                    'grp-close' => 'Closing Balance', 'grp-other' => 'Valuation / Action',
                ] as $groupClass => $groupLabel): ?>
                    <label style="display:flex;gap:8px;align-items:center;font-weight:500">
                        <input type="checkbox" checked data-col-group="<?= e($groupClass) ?>"> <?= e($groupLabel) ?>
                    </label>
                <?php endforeach; ?>
                <small>Hide column groups you don't need; the choice is remembered on this device.</small>
            </div>
        </details>
    </div>
</section>

<section class="mbw-card" aria-label="Stock summary table">
    <div class="mbw-card-head">
        <h2>Items (<?= count($rows) ?>)</h2>
        <div class="mbw-card-tools"><span class="mbw-pill tone-blue">Page <?= $page ?> / <?= $pageCount ?></span></div>
    </div>
    <div class="ssr-scroll" style="overflow-x:auto;max-height:70vh;overflow-y:auto">
    <table class="ssr-table">
        <thead>
            <tr class="ssr-h1">
                <th colspan="6">Basic Item Information</th>
                <th colspan="3" class="grp-open">Opening Balance</th>
                <th colspan="3" class="grp-in">Purchase / Inward</th>
                <th colspan="3" class="grp-out">Sold / Consumed / Outward</th>
                <th colspan="3" class="grp-dmg">Damage / Write-off</th>
                <th colspan="3" class="grp-close">Closing Balance</th>
                <th colspan="2" class="grp-other">Other</th>
            </tr>
            <tr class="ssr-h2">
                <th>S.N.</th><th class="ssr-sticky-1">Item Code</th><th class="ssr-sticky-2">Item Name</th><th>Type</th><th>Location</th><th>UOM</th>
                <th class="is-numeric grp-open">Qty</th><th class="is-numeric grp-open">Rate</th><th class="is-numeric grp-open">Amount</th>
                <th class="is-numeric grp-in">Qty</th><th class="is-numeric grp-in">Rate</th><th class="is-numeric grp-in">Amount</th>
                <th class="is-numeric grp-out">Qty</th><th class="is-numeric grp-out">Rate</th><th class="is-numeric grp-out">Amount</th>
                <th class="is-numeric grp-dmg">Qty</th><th class="is-numeric grp-dmg">Rate</th><th class="is-numeric grp-dmg">Amount</th>
                <th class="is-numeric grp-close">Qty</th><th class="is-numeric grp-close">Rate</th><th class="is-numeric grp-close">Amount</th>
                <th class="grp-other">Valuation</th><th class="grp-other">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($pageRows === []): ?>
                <tr><td colspan="23" style="text-align:center;color:var(--mbw-muted);padding:24px">No items match the filters for <?= e($from) ?> → <?= e($to) ?>.</td></tr>
            <?php endif; ?>
            <?php $sn = ($page - 1) * $perPage; foreach ($pageRows as $r): $sn++;
                $ledgerUrl = url('admin/stock-ledger.php?item=' . $r['item_id'] . '&return=' . urlencode($qs . '&page=' . $page . '&per_page=' . $perPage) . '&' . $qs);
            ?>
                <tr>
                    <td><?= $sn ?></td>
                    <td class="ssr-sticky-1"><a href="<?= e($ledgerUrl) ?>"><strong><?= e($r['sku']) ?></strong></a></td>
                    <td class="ssr-sticky-2"><a href="<?= e($ledgerUrl) ?>"><?= e($r['name']) ?></a></td>
                    <td><span class="mbw-pill tone-blue"><?= e($r['item_type_label']) ?></span></td>
                    <td><?= e($r['location']) ?></td>
                    <td><?= e($r['unit']) ?></td>
                    <?php foreach ([['opening_qty', 'opening_rate', 'opening_amount', 'grp-open'], ['in_qty', 'in_rate', 'in_amount', 'grp-in'], ['out_qty', 'out_rate', 'out_amount', 'grp-out'], ['damage_qty', 'damage_rate', 'damage_amount', 'grp-dmg'], ['closing_qty', 'closing_rate', 'closing_amount', 'grp-close']] as [$qk, $rk, $ak, $grp]): ?>
                        <td class="is-numeric <?= $grp ?><?= $r[$qk] < 0 ? ' ssr-neg' : '' ?>"><?= e(number_format($r[$qk], 3)) ?></td>
                        <td class="is-numeric <?= $grp ?>"><?= $r[$qk] > 0.0005 || $r[$rk] > 0 ? e(number_format($r[$rk], 2)) : '–' ?></td>
                        <td class="is-numeric <?= $grp ?><?= $r[$ak] < 0 ? ' ssr-neg' : '' ?>"><?= e(number_format($r[$ak], 2)) ?></td>
                    <?php endforeach; ?>
                    <td class="grp-other"><?= e(strtoupper(str_replace('_', ' ', $r['valuation_method']))) ?></td>
                    <td class="grp-other"><a class="button secondary" style="min-height:28px;padding:2px 8px;white-space:nowrap" href="<?= e($ledgerUrl) ?>">View Stock Ledger</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows !== []): ?>
            <tr class="ssr-totals">
                <td colspan="6">Totals — amounts only (quantities of different units are never combined)</td>
                <td colspan="2" class="grp-open"></td><td class="is-numeric grp-open"><?= e($sym . number_format($totals['opening_amount'], 2)) ?></td>
                <td colspan="2" class="grp-in"></td><td class="is-numeric grp-in"><?= e($sym . number_format($totals['in_amount'], 2)) ?></td>
                <td colspan="2" class="grp-out"></td><td class="is-numeric grp-out"><?= e($sym . number_format($totals['out_amount'], 2)) ?></td>
                <td colspan="2" class="grp-dmg"></td><td class="is-numeric grp-dmg"><?= e($sym . number_format($totals['damage_amount'], 2)) ?></td>
                <td colspan="2" class="grp-close"></td><td class="is-numeric grp-close"><?= e($sym . number_format($totals['closing_amount'], 2)) ?></td>
                <td colspan="2" class="grp-other"></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php if ($pageCount > 1): ?>
        <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
            <?php for ($p = 1; $p <= $pageCount; $p++): ?>
                <a class="button secondary" style="min-height:30px;padding:3px 10px<?= $p === $page ? ';font-weight:700;border-color:var(--mbw-accent,#2f7fb8)' : '' ?>" href="?<?= e($qs) ?>&amp;per_page=<?= $perPage ?>&amp;page=<?= $p ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
    <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12px">
        Outward and damage amounts are inventory <strong>cost</strong> (FIFO / weighted average replay), never selling price. Damage rows (damage, expiry, write-off) are excluded from normal outward — no double counting.
        Closing = opening + inward − outward − damage, and its amount comes from the remaining valuation layers as of <?= e($to) ?>.
        Warehouse-scoped amounts value exact per-location quantities at company-level carrying cost (transfers never re-cost stock).
    </p>
</section>

<section class="mbw-card" aria-label="Location-specific item types">
    <div class="mbw-card-head"><h2><?= icon('sliders') ?>Item type by department / location</h2></div>
    <p style="margin:0 0 10px;color:var(--mbw-muted);font-size:12.5px">The same item can be <strong>FG</strong> for the department that produces it and <strong>RM</strong> for the department that consumes it. Set the type per location here; the item master type stays the fallback. The report resolves the type for the selected location.</p>
    <?php if (user_can_do('inventory', 'edit')): ?>
    <form method="post" class="workspace-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="set_location_type">
        <label>Item
            <select name="map_item_id" class="js-searchable" required>
                <option value="">— pick item —</option>
                <?php foreach ($allItems as $ai): ?><option value="<?= (int) $ai['id'] ?>"><?= e($ai['sku'] . ' — ' . $ai['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Location / department
            <select name="map_warehouse_id" required>
                <option value="">— pick location —</option>
                <?php foreach ($warehouses as $wh): ?><option value="<?= (int) $wh['id'] ?>"><?= e((string) $wh['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Type at that location
            <select name="map_item_type">
                <option value="">(clear — use master type)</option>
                <?php foreach (sr_item_type_labels() as $typeKey => $typeLabel): if ($typeKey === 'service') { continue; } ?>
                    <option value="<?= e($typeKey) ?>"><?= e($typeLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div style="display:flex;align-items:flex-end"><button type="submit"><?= icon('badge-check') ?>Save mapping</button></div>
    </form>
    <?php endif; ?>
</section>

<style>
/* Compact multi-select popovers: uniform 38px fields like every other input,
   options in an anchored checklist instead of a tall scrolling list box. */
.ssr-msel { position: relative; }
.ssr-msel > summary {
    list-style: none; cursor: pointer; min-height: 38px; display: flex; align-items: center;
    padding: 8px 32px 8px 12px; border: 1px solid var(--mbw-line, rgba(0,0,0,.2)); border-radius: 8px;
    background: var(--mbw-card, #fff); color: var(--mbw-ink, #12261f); font: inherit; font-weight: 500;
}
.ssr-msel > summary::-webkit-details-marker { display: none; }
.ssr-msel > summary::after { content: '▾'; position: absolute; right: 12px; color: var(--mbw-muted, #5b6b64); }
.ssr-msel[open] > summary { outline: 2px solid var(--mbw-accent, #2f7fb8); outline-offset: 1px; }
.ssr-msel-list {
    position: absolute; left: 0; right: 0; top: 100%; z-index: 60; margin-top: 4px; max-height: 240px; overflow: auto;
    display: grid; gap: 2px; padding: 10px 12px; background: var(--mbw-card, #fff); color: var(--mbw-ink, #12261f);
    border: 1px solid var(--mbw-line, rgba(0,0,0,.16)); border-radius: 10px; box-shadow: 0 14px 34px rgba(0,0,0,.22);
}
.ssr-msel-list label { display: flex; gap: 8px; align-items: center; font-size: 13px; font-weight: 500; }
.ssr-msel-list input[type="checkbox"] { width: auto; min-height: auto; }
/* Columns… popover (same look, was missing these styles on this page) */
.pr-adjust { position: relative; display: inline-block; }
.pr-adjust > summary { list-style: none; cursor: pointer; }
.pr-adjust > summary::-webkit-details-marker { display: none; }
.pr-adjust-form {
    position: absolute; right: 0; z-index: 60; margin-top: 6px; display: grid; gap: 8px; padding: 12px; text-align: left;
    background: var(--mbw-card, #fff); color: var(--mbw-ink, #12261f); border: 1px solid var(--mbw-line, rgba(0,0,0,.16));
    border-radius: 10px; box-shadow: 0 14px 34px rgba(0,0,0,.22);
}
.pr-adjust-form label { display: flex; gap: 8px; align-items: center; font-size: 12.5px; font-weight: 500; }
.pr-adjust-form input[type="checkbox"] { width: auto; min-height: auto; }
.pr-adjust-form small { color: var(--mbw-muted, #5b6b64); font-weight: 400; font-size: 11px; }
.ssr-table { border-collapse: separate; border-spacing: 0; min-width: 1700px; font-size: 12.5px; }
.ssr-table th, .ssr-table td { padding: 6px 8px; border-bottom: 1px solid var(--mbw-line, rgba(0,0,0,.1)); background: var(--mbw-card, #fff); }
.ssr-table thead th { position: sticky; z-index: 5; background: var(--mbw-soft, #eef5f0); color: var(--mbw-ink, #12261f); }
.ssr-h1 th { top: 0; border-bottom: 2px solid var(--mbw-line, rgba(0,0,0,.16)); text-align: center; font-size: 12px; }
.ssr-h2 th { top: 31px; }
.ssr-sticky-1, .ssr-sticky-2 { position: sticky; z-index: 3; }
.ssr-sticky-1 { left: 0; min-width: 90px; }
.ssr-sticky-2 { left: 90px; min-width: 160px; box-shadow: 2px 0 0 var(--mbw-line, rgba(0,0,0,.1)); }
.ssr-h2 .ssr-sticky-1, .ssr-h2 .ssr-sticky-2 { z-index: 6; }
.ssr-totals td { font-weight: 700; background: var(--mbw-soft, #eef5f0); position: sticky; bottom: 0; }
.ssr-neg { color: #c62828; font-weight: 600; }
.ssr-table .is-numeric { text-align: right; font-variant-numeric: tabular-nums; }
</style>
<script>
(function () {
    // Multi-select popovers: live "N selected" summaries + close on outside click.
    document.querySelectorAll('.ssr-msel').forEach(function (msel) {
        var summary = msel.querySelector('summary');
        var list = msel.querySelector('.ssr-msel-list');
        function sync() {
            var n = list.querySelectorAll('input:checked').length;
            summary.textContent = n === 0 ? (list.dataset.mselAll || 'All') : n + ' selected';
        }
        list.addEventListener('change', sync);
        sync();
    });
    document.addEventListener('click', function (ev) {
        document.querySelectorAll('.ssr-msel[open]').forEach(function (msel) {
            if (!msel.contains(ev.target)) { msel.removeAttribute('open'); }
        });
    });
})();
(function () {
    var KEY = 'ssr-columns';
    var boxes = document.querySelectorAll('#ssrColumnChooser input[data-col-group]');
    var saved = {};
    try { saved = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) {}
    function apply() {
        var state = {};
        boxes.forEach(function (b) {
            state[b.dataset.colGroup] = b.checked;
            document.querySelectorAll('.' + b.dataset.colGroup).forEach(function (cell) {
                cell.style.display = b.checked ? '' : 'none';
            });
        });
        localStorage.setItem(KEY, JSON.stringify(state));
    }
    boxes.forEach(function (b) {
        if (saved.hasOwnProperty(b.dataset.colGroup)) { b.checked = !!saved[b.dataset.colGroup]; }
        b.addEventListener('change', apply);
    });
    apply();
})();
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
