<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_once __DIR__ . '/../../app/reports_engine.php';
require_once __DIR__ . '/../../app/mailer.php';
require_staff_or_admin();
require_company_context();
$repairErrors = accounting_module_repair_database();

$pageTitle = 'Reports Center';
$pageSubtitle = 'Create, view and export your financial and operational reports.';
$company = current_company();
$companyId = (int) ($company['id'] ?? 0);
$companyBusinessType = company_accounting_business_type($companyId);
$companyBusinessProfile = accounting_business_profile($companyBusinessType);
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$currencySymbol = site_currency_symbol();

// ---------------------------------------------------------------------------
// Report registry.
// ---------------------------------------------------------------------------
$reportRegistry = rc_report_registry();
$allowedReportKeys = array_keys($reportRegistry);
if (!($companyBusinessProfile['show_inventory'] ?? false)) {
    $allowedReportKeys = array_values(array_diff($allowedReportKeys, ['inventory-summary', 'stock-ledger', 'stock-movement', 'stock-valuation']));
}
if (!($companyBusinessProfile['show_manufacturing'] ?? false)) {
    $allowedReportKeys = array_values(array_diff($allowedReportKeys, ['manufacturing-statement', 'manufacturing-cost', 'manufacturing-wip']));
}
$allowedReportRegistry = array_intersect_key($reportRegistry, array_flip($allowedReportKeys));

// ---------------------------------------------------------------------------
// Filters.
// ---------------------------------------------------------------------------
$reportId = (string) ($_GET['report'] ?? 'trial-balance');
if (!isset($allowedReportRegistry[$reportId])) {
    $reportId = array_key_first($allowedReportRegistry) ?: 'trial-balance';
}

$fiscalYears = fiscal_years_for_company($companyId, false);
$fiscalYearId = (int) ($_GET['fy'] ?? current_fiscal_year_id());
$selectedFiscalYear = null;
foreach ($fiscalYears as $fiscalYearRow) {
    if ((int) $fiscalYearRow['id'] === $fiscalYearId) {
        $selectedFiscalYear = $fiscalYearRow;
        break;
    }
}
if (!$selectedFiscalYear && $fiscalYears !== []) {
    $selectedFiscalYear = $fiscalYears[0];
    $fiscalYearId = (int) $selectedFiscalYear['id'];
}

$isValidDate = static fn (string $value): bool => $value !== '' && DateTimeImmutable::createFromFormat('Y-m-d', $value) !== false;
$fromDate = trim((string) ($_GET['from'] ?? ''));
$toDate = trim((string) ($_GET['to'] ?? ''));
if (!$isValidDate($fromDate)) {
    $fromDate = (string) ($selectedFiscalYear['start_date'] ?? date('Y') . '-01-01');
}
if (!$isValidDate($toDate)) {
    $toDate = (string) ($selectedFiscalYear['end_date'] ?? date('Y') . '-12-31');
}

$voucherTypes = ['payment', 'receipt', 'journal', 'sales', 'purchase', 'contra', 'debit_note', 'credit_note'];
$voucherType = (string) ($_GET['vtype'] ?? '');
if (!in_array($voucherType, $voucherTypes, true)) {
    $voucherType = '';
}

$ledgerFilterId = (int) ($_GET['ledger_id'] ?? 0);
$groupFilterId = (int) ($_GET['group_id'] ?? 0);
$itemFilterId = (int) ($_GET['item_id'] ?? 0);
$businessType = (string) ($_GET['biz'] ?? 'all');
$businessTypeOptions = ['all' => 'All', 'service' => 'Service'];
if ($companyBusinessProfile['show_inventory'] ?? false) {
    $businessTypeOptions['trading'] = 'Trading';
}
if ($companyBusinessProfile['show_manufacturing'] ?? false) {
    $businessTypeOptions['manufacturing'] = 'Manufacturing';
}
if (!array_key_exists($businessType, $businessTypeOptions)) {
    $businessType = 'all';
}

$subsidiaries = child_companies_for_company($companyId);
$scopeCompanyId = (int) ($_GET['scope_company'] ?? $companyId);
$allowedScopeIds = array_merge([$companyId], array_map(static fn (array $row): int => (int) $row['id'], $subsidiaries));
if (!in_array($scopeCompanyId, $allowedScopeIds, true)) {
    $scopeCompanyId = $companyId;
}

$compareEnabled = (string) ($_GET['compare'] ?? '') === '1';
$compareFrom = trim((string) ($_GET['cfrom'] ?? ''));
$compareTo = trim((string) ($_GET['cto'] ?? ''));
if (!$isValidDate($compareFrom) || !$isValidDate($compareTo)) {
    $periodDays = max(1, (int) (new DateTimeImmutable($fromDate))->diff(new DateTimeImmutable($toDate))->days + 1);
    $compareTo = (new DateTimeImmutable($fromDate))->modify('-1 day')->format('Y-m-d');
    $compareFrom = (new DateTimeImmutable($compareTo))->modify('-' . ($periodDays - 1) . ' days')->format('Y-m-d');
}

function rc_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        }
    }
    $query = http_build_query($params);
    return url('admin/reports-center.php' . ($query !== '' ? '?' . $query : ''));
}

$generatorContext = [
    'currency' => $currencySymbol,
    'org_default' => $companyBusinessType,
    'vtype' => $voucherType,
    'group_id' => $groupFilterId,
    'ledger_id' => $ledgerFilterId,
    'item_id' => $itemFilterId,
    'biz' => $businessType,
    'company_id' => $scopeCompanyId,
    'company_name' => $scopeCompanyId === $companyId ? (string) ($company['name'] ?? '') : '',
    'subsidiaries' => $scopeCompanyId === $companyId ? array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']], $subsidiaries) : [],
];
if ($generatorContext['company_name'] === '') {
    foreach ($subsidiaries as $subsidiary) {
        if ((int) $subsidiary['id'] === $scopeCompanyId) {
            $generatorContext['company_name'] = (string) $subsidiary['name'];
        }
    }
}

$report = rc_generate($reportId, $scopeCompanyId, $fromDate, $toDate, $generatorContext);
$compareReport = $compareEnabled ? rc_generate($reportId, $scopeCompanyId, $compareFrom, $compareTo, $generatorContext) : null;
[$reportLabel, $reportDescription, $reportIcon] = $reportRegistry[$reportId];

// ---------------------------------------------------------------------------
// CSV export.
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $reportId . '-' . $fromDate . '-to-' . $toDate . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [$reportLabel . ' — ' . ($company['name'] ?? '') . ' — ' . app_date_range($fromDate, $toDate)]);
    fputcsv($out, array_map(static fn (array $col): string => ($col[2] !== '' ? $col[2] . ' ' : '') . $col[0], $report['columns']));
    foreach ($report['rows'] as $row) {
        fputcsv($out, rc_row_cells($row));
    }
    if ($report['totals'] !== null) {
        fputcsv($out, $report['totals']);
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Print / PDF view (standalone, browser print dialog handles PDF).
// ---------------------------------------------------------------------------
$hasGroups = rc_has_group_columns($report);

$reportMeta = [
    'report_label' => $reportLabel,
    'company_name' => (string) ($generatorContext['company_name'] ?: ($company['name'] ?? app_name())),
    'from' => $fromDate,
    'to' => $toDate,
    'fiscal_label' => (string) ($selectedFiscalYear['label'] ?? ''),
    'branch' => 'Head Office',
    'currency_code' => trim($currencySymbol, ' .') !== '' ? rtrim(trim($currencySymbol), '. ') : 'NPR',
    'generated_by' => (string) ($currentUser['name'] ?? 'System'),
    'pdf_url' => rc_url(['view' => 'print']),
    'excel_url' => rc_url(['export' => 'csv']),
];
$reportNumberedTitle = (isset($report['number']) ? $report['number'] . '. ' : '') . ($report['title'] ?? $reportLabel);

if (isset($_GET['view']) && $_GET['view'] === 'print') {
    ?><!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title><?= e($reportNumberedTitle) ?> | <?= e($reportMeta['company_name']) ?></title>
        <style>
            body { font-family: "Inter", "Segoe UI", system-ui, sans-serif; margin: 30px; color: #16263e; }
            .rpt-bar { background: #16325d; color: #fff; padding: 9px 14px; font-weight: 700; font-size: 14px; border-radius: 4px 4px 0 0; }
            .rpt-letterhead { display: flex; justify-content: space-between; gap: 16px; padding: 14px; border: 1px solid #d7dfeb; border-top: 0; }
            .rpt-company { color: #16325d; font-weight: 800; font-size: 13px; letter-spacing: 0.02em; }
            .rpt-title { font-weight: 700; font-size: 15px; margin-top: 2px; }
            .rpt-entity { font-weight: 600; font-size: 12.5px; margin-top: 2px; }
            .rpt-period { color: #55657e; font-size: 12px; margin-top: 3px; }
            .rpt-meta { text-align: right; font-size: 11.5px; color: #55657e; display: grid; gap: 3px; align-content: start; }
            .rpt-meta em { font-style: normal; color: #8494ab; }
            .rc-table { width: 100%; border-collapse: collapse; font-size: 12px; border: 1px solid #d7dfeb; border-top: 0; }
            .rc-table th, .rc-table td { border: 1px solid #e3e9f2; padding: 6px 9px; }
            .rc-table thead th { background: #f1f5fb; color: #3f4c61; font-size: 10.5px; letter-spacing: 0.03em; }
            .align-right { text-align: right; }
            .rc-total-row td, .rpt-row-total td { font-weight: 700; background: #eef3fa; }
            .rpt-row-bold td { font-weight: 700; }
            .rpt-row-section td { font-weight: 700; background: #f6f8fc; letter-spacing: 0.04em; }
            .rpt-foot { display: flex; gap: 18px; padding: 9px 12px; border: 1px solid #d7dfeb; border-top: 0; color: #55657e; font-size: 11px; }
            .print-button { margin-top: 20px; padding: 10px 18px; background: #16325d; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
            @media print { .print-button { display: none; } body { margin: 8mm; } }
        </style>
    </head>
    <body>
        <div class="rpt-bar"><?= e($reportNumberedTitle) ?></div>
        <?php rc_render_letterhead($report, $reportMeta); ?>
        <?php rc_render_table($report, $hasGroups); ?>
        <?php rc_render_report_foot(['generated_by' => $reportMeta['generated_by']]); ?>
        <button class="print-button" onclick="window.print()">Print / Save as PDF</button>
    </body>
    </html><?php
    exit;
}

// ---------------------------------------------------------------------------
// Page shell.
// ---------------------------------------------------------------------------
$allLedgersStmt = db()->prepare("SELECT id, code, name FROM ledgers WHERE company_id = :company_id AND status = 'active' ORDER BY code ASC");
$allLedgersStmt->execute(['company_id' => $scopeCompanyId]);
$allLedgers = $allLedgersStmt->fetchAll();
$allGroupsStmt = db()->prepare('SELECT id, code, name FROM ledger_groups WHERE company_id = :company_id AND is_active = 1 ORDER BY name ASC');
$allGroupsStmt->execute(['company_id' => $scopeCompanyId]);
$allGroups = $allGroupsStmt->fetchAll();
$allItems = [];
if (table_exists('inventory_items')) {
    $itemsStmt = db()->prepare('SELECT id, sku, name FROM inventory_items WHERE company_id = :company_id ORDER BY sku ASC');
    $itemsStmt->execute(['company_id' => $scopeCompanyId]);
    $allItems = $itemsStmt->fetchAll();
}

$bodyClass = 'admin-layout accounting-module-page reports-center-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="rc2-toolbar mbw-card">
    <label class="rc2-report-view" title="Choose which report to view">
        <?= icon('reports') ?>
        <span class="sr-only">Report view</span>
        <select onchange="if (this.value) { window.location = this.value; }" aria-label="Choose report">
            <?php foreach ($allowedReportRegistry as $key => [$label, $description, $iconName]): ?>
                <option value="<?= e(rc_url(['report' => $key])) ?>" <?= $key === $reportId ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <span class="rc2-caret"><?= icon('chevron') ?></span>
    </label>
    <label class="rc2-quick" title="Apply a preset date range">
        <?= icon('filter') ?>
        <span class="sr-only">Quick filter</span>
        <select id="rc2QuickFilter" aria-label="Quick date range">
            <option value="">Quick Filter</option>
            <option value="this-month">This Month</option>
            <option value="last-month">Last Month</option>
            <option value="this-quarter">This Quarter</option>
            <option value="fy">This Fiscal Year</option>
            <option value="last-30">Last 30 Days</option>
            <option value="ytd">Year to Date</option>
        </select>
        <span class="rc2-caret"><?= icon('chevron') ?></span>
    </label>
    <label class="rc2-saved">
        <span class="sr-only">Saved filters</span>
        <select id="rc2SavedSelect" aria-label="Saved filters"></select>
        <span class="rc2-caret"><?= icon('chevron') ?></span>
    </label>
    <button type="button" class="rc2-save-btn" id="rc2SaveFilter" title="Save the current filters in this browser">
        <?= icon('star') ?>Save Filter
    </button>
</div>

<form method="get" action="<?= e(url('admin/reports-center.php')) ?>" class="rc-filter-card rc2-filters" id="rc2FilterForm" data-fy-start="<?= e((string) ($selectedFiscalYear['start_date'] ?? '')) ?>" data-fy-end="<?= e((string) ($selectedFiscalYear['end_date'] ?? '')) ?>">
    <input type="hidden" name="report" value="<?= e($reportId) ?>">
    <?php if ($compareEnabled): ?><input type="hidden" name="compare" value="1"><?php endif; ?>
    <div class="rc2-filters-head">
        <span class="rc2-filters-title">
            <?= icon('filter') ?>
            <strong>Filters</strong>
            <em>Refine your report results</em>
        </span>
        <button type="button" class="rc2-collapse" data-rc2-collapse aria-expanded="true">
            <?= icon('chevron') ?><span>Collapse</span>
        </button>
    </div>
    <div class="rc2-filters-body" data-rc2-body>
    <div class="rc-filter-grid">
        <label>Fiscal Year
            <select name="fy">
                <?php foreach ($fiscalYears as $fiscalYearRow): ?>
                    <option value="<?= e((int) $fiscalYearRow['id']) ?>" <?= (int) $fiscalYearRow['id'] === $fiscalYearId ? 'selected' : '' ?>><?= e($fiscalYearRow['label']) ?> (<?= e(date('d M Y', strtotime((string) $fiscalYearRow['start_date']))) ?> - <?= e(date('d M Y', strtotime((string) $fiscalYearRow['end_date']))) ?>)</option>
                <?php endforeach; ?>
                <?php if ($fiscalYears === []): ?><option value="0">No fiscal year</option><?php endif; ?>
            </select>
        </label>
        <label>From Date<input type="date" name="from" value="<?= e($fromDate) ?>"></label>
        <label>To Date<input type="date" name="to" value="<?= e($toDate) ?>"></label>
        <label>Branch<select name="branch" title="Branch accounting is not enabled; all data is head office."><option>All Branches</option></select></label>
        <label>Company
            <select name="scope_company">
                <option value="<?= e($companyId) ?>" <?= $scopeCompanyId === $companyId ? 'selected' : '' ?>><?= e($company['name'] ?? 'Current company') ?></option>
                <?php foreach ($subsidiaries as $subsidiary): ?>
                    <option value="<?= e((int) $subsidiary['id']) ?>" <?= $scopeCompanyId === (int) $subsidiary['id'] ? 'selected' : '' ?>><?= e($subsidiary['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Project<select name="project" title="Project dimension is not enabled yet."><option>All Projects</option></select></label>
        <label>Cost Center<select name="cost_center" title="Cost centers are not enabled yet."><option>All Cost Centers</option></select></label>
        <label>Voucher Type
            <select name="vtype">
                <option value="">All Voucher Types</option>
                <?php foreach ($voucherTypes as $type): ?>
                    <option value="<?= e($type) ?>" <?= $voucherType === $type ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $type))) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Ledger
            <select name="ledger_id">
                <option value="0">All Ledgers</option>
                <?php foreach ($allLedgers as $ledger): ?>
                    <option value="<?= e((int) $ledger['id']) ?>" <?= $ledgerFilterId === (int) $ledger['id'] ? 'selected' : '' ?>><?= e($ledger['code'] . ' - ' . $ledger['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Group
            <select name="group_id">
                <option value="0">All Groups</option>
                <?php foreach ($allGroups as $group): ?>
                    <option value="<?= e((int) $group['id']) ?>" <?= $groupFilterId === (int) $group['id'] ? 'selected' : '' ?>><?= e($group['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if (($companyBusinessProfile['show_inventory'] ?? false) && $allItems !== []): ?>
            <label>Inventory Item
                <select name="item_id">
                    <option value="0">First item</option>
                    <?php foreach ($allItems as $item): ?>
                        <option value="<?= e((int) $item['id']) ?>" <?= $itemFilterId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['sku'] . ' - ' . $item['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <label>Organization Type
            <select name="biz">
                <?php foreach ($businessTypeOptions as $bizValue => $bizLabel): ?>
                    <option value="<?= e($bizValue) ?>" <?= $businessType === $bizValue ? 'selected' : '' ?>><?= e($bizLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($compareEnabled): ?>
            <label>Compare From<input type="date" name="cfrom" value="<?= e($compareFrom) ?>"></label>
            <label>Compare To<input type="date" name="cto" value="<?= e($compareTo) ?>"></label>
        <?php endif; ?>
    </div>
    <div class="rc2-filter-actions">
        <button type="submit" class="rc2-apply"><?= icon('filter') ?>Apply Filters</button>
        <a class="rc2-reset" href="<?= e(url('admin/reports-center.php?report=' . $reportId)) ?>"><?= icon('reconcile') ?>Reset</a>
        <div class="rc2-export" data-rc2-export>
            <button type="button" class="rc2-export-btn"><?= icon('download') ?>Export<span class="rc2-caret"><?= icon('chevron') ?></span></button>
            <div class="rc2-export-menu" role="menu">
                <a role="menuitem" target="_blank" href="<?= e(rc_url(['view' => 'print'])) ?>"><?= icon('documents') ?>Export PDF</a>
                <a role="menuitem" href="<?= e(rc_url(['export' => 'csv'])) ?>"><?= icon('analytics') ?>Export Excel (CSV)</a>
                <a role="menuitem" target="_blank" href="<?= e(rc_url(['view' => 'print'])) ?>"><?= icon('receipt-voucher') ?>Print Report</a>
                <a role="menuitem" href="<?= e(url('admin/report-schedules.php?report_key=' . urlencode($reportId))) ?>"><?= icon('calendar') ?>Schedule Reports</a>
                <a role="menuitem" href="<?= e(rc_url($compareEnabled ? ['compare' => null, 'cfrom' => null, 'cto' => null] : ['compare' => '1'])) ?>"><?= icon('reconcile') ?>Compare Period: <?= $compareEnabled ? 'On' : 'Off' ?></a>
            </div>
        </div>
    </div>
    </div>
</form>

<section class="rc2-preview mbw-card" id="rc2Preview" aria-label="Report preview">
    <div class="rc2-preview-head">
        <span class="rc2-preview-title">
            <?= icon('documents') ?>
            <span>
                <strong>Report Preview</strong>
                <small>Generated from the applied filters above.</small>
            </span>
        </span>
        <div class="rc2-preview-tools">
            <span>View</span>
            <label class="rc2-view-select">
                <select id="rc2ViewMode" aria-label="Preview density">
                    <option value="table">Table View</option>
                    <option value="compact">Compact View</option>
                </select>
                <span class="rc2-caret"><?= icon('chevron') ?></span>
            </label>
            <button type="button" id="rc2Expand" aria-label="Expand preview" title="Expand preview"><?= icon('maximize') ?></button>
        </div>
    </div>
<div class="rpt-fullwidth">
    <main class="rc-report-view rpt-statement" id="rc2Statement">
        <div class="rpt-bar"><?= e($reportNumberedTitle) ?></div>
        <?php rc_render_letterhead($report, $reportMeta); ?>
        <div class="rc-table-scroll">
            <?php rc_render_table($report, $hasGroups); ?>
        </div>
        <?php if ($compareEnabled && $compareReport !== null): ?>
            <div class="rpt-bar rpt-bar-compare">Comparison Period — <?= e(date('d M Y', strtotime($compareFrom))) ?> to <?= e(date('d M Y', strtotime($compareTo))) ?></div>
            <div class="rc-table-scroll">
                <?php rc_render_table($compareReport, $hasGroups); ?>
            </div>
        <?php endif; ?>
        <?php rc_render_report_foot(['generated_by' => $reportMeta['generated_by']]); ?>

        <div class="rc-report-foot">
            <span><?= e($report['subtitle']) ?></span>
            <span>All amounts are in <?= e($reportMeta['currency_code']) ?>, rounded to 2 decimal places</span>
        </div>
    </main>
</div>
</section>

<script>
(function () {
    var form = document.getElementById('rc2FilterForm');

    // Collapse / expand the filter card body.
    var collapseBtn = document.querySelector('[data-rc2-collapse]');
    var filtersBody = document.querySelector('[data-rc2-body]');
    if (collapseBtn && filtersBody) {
        collapseBtn.addEventListener('click', function () {
            var wasHidden = filtersBody.hasAttribute('hidden');
            if (wasHidden) { filtersBody.removeAttribute('hidden'); } else { filtersBody.setAttribute('hidden', ''); }
            collapseBtn.classList.toggle('is-collapsed', !wasHidden);
            collapseBtn.querySelector('span').textContent = wasHidden ? 'Collapse' : 'Expand';
            collapseBtn.setAttribute('aria-expanded', wasHidden ? 'true' : 'false');
        });
    }

    // Quick Filter: preset date ranges applied to the form.
    var quick = document.getElementById('rc2QuickFilter');
    function iso(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    if (quick && form) {
        quick.addEventListener('change', function () {
            if (!quick.value) { return; }
            var now = new Date();
            var from = null;
            var to = null;
            if (quick.value === 'this-month') { from = new Date(now.getFullYear(), now.getMonth(), 1); to = new Date(now.getFullYear(), now.getMonth() + 1, 0); }
            if (quick.value === 'last-month') { from = new Date(now.getFullYear(), now.getMonth() - 1, 1); to = new Date(now.getFullYear(), now.getMonth(), 0); }
            if (quick.value === 'this-quarter') { var q = Math.floor(now.getMonth() / 3); from = new Date(now.getFullYear(), q * 3, 1); to = new Date(now.getFullYear(), q * 3 + 3, 0); }
            if (quick.value === 'last-30') { to = now; from = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 29); }
            if (quick.value === 'ytd') { from = new Date(now.getFullYear(), 0, 1); to = now; }
            if (quick.value === 'fy' && form.dataset.fyStart && form.dataset.fyEnd) {
                form.elements.from.value = form.dataset.fyStart;
                form.elements.to.value = form.dataset.fyEnd;
                form.submit();
                return;
            }
            if (from && to) {
                form.elements.from.value = iso(from);
                form.elements.to.value = iso(to);
                form.submit();
            }
        });
    }

    // Saved filters (stored in this browser via localStorage).
    var savedSelect = document.getElementById('rc2SavedSelect');
    var saveBtn = document.getElementById('rc2SaveFilter');
    var STORE_KEY = 'rc2SavedFilters';
    function readSaved() {
        try { return JSON.parse(window.localStorage.getItem(STORE_KEY)) || []; } catch (e) { return []; }
    }
    function fillSaved() {
        if (!savedSelect) { return; }
        var items = readSaved();
        savedSelect.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = items.length ? 'Select a saved filter...' : 'No saved filters yet';
        savedSelect.appendChild(placeholder);
        items.forEach(function (item, index) {
            var option = document.createElement('option');
            option.value = String(index);
            option.textContent = item.n;
            savedSelect.appendChild(option);
        });
    }
    fillSaved();
    if (savedSelect) {
        savedSelect.addEventListener('change', function () {
            var item = readSaved()[Number(savedSelect.value)];
            if (item) { window.location = window.location.pathname + item.q; }
        });
    }
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var name = window.prompt('Name this filter set:');
            if (!name) { return; }
            var items = readSaved();
            items.push({ n: name, q: window.location.search });
            window.localStorage.setItem(STORE_KEY, JSON.stringify(items));
            fillSaved();
        });
    }

    // Export dropdown menu.
    var exportWrap = document.querySelector('[data-rc2-export]');
    if (exportWrap) {
        exportWrap.querySelector('.rc2-export-btn').addEventListener('click', function (event) {
            event.stopPropagation();
            exportWrap.classList.toggle('is-open');
        });
        document.addEventListener('click', function () { exportWrap.classList.remove('is-open'); });
    }

    // Preview density + fullscreen expand.
    var viewMode = document.getElementById('rc2ViewMode');
    var statement = document.getElementById('rc2Statement');
    if (viewMode && statement) {
        viewMode.addEventListener('change', function () {
            statement.classList.toggle('is-compact', viewMode.value === 'compact');
        });
    }
    var expandBtn = document.getElementById('rc2Expand');
    var preview = document.getElementById('rc2Preview');
    if (expandBtn && preview) {
        expandBtn.addEventListener('click', function () {
            preview.classList.toggle('rc2-preview-full');
            document.body.classList.toggle('rc2-noscroll', preview.classList.contains('rc2-preview-full'));
        });
    }
})();
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
