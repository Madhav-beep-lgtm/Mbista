<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_once __DIR__ . '/../../app/reports_engine.php';
require_once __DIR__ . '/../../app/jewellery_engine.php';
require_once __DIR__ . '/../../app/jewellery_aml.php';
require_once __DIR__ . '/../../app/mailer.php';
require_staff_admin_or_client_books();
require_company_context();
$repairErrors = accounting_module_repair_database();

$pageTitle = 'Reports Center';
$pageSubtitle = '';
$pageHero = false;
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
$allowedReportKeys = array_values(array_filter($allowedReportKeys, static fn(string $key): bool => company_module_enabled(company_report_module($key) ?? '', $companyId)));
if (!($companyBusinessProfile['show_inventory'] ?? false)) {
    $allowedReportKeys = array_values(array_diff($allowedReportKeys, ['inventory-summary', 'stock-ledger', 'stock-movement', 'stock-valuation', 'nrv-report', 'inventory-gl-reconciliation']));
}
if (!($companyBusinessProfile['show_manufacturing'] ?? false)) {
    $allowedReportKeys = array_values(array_diff($allowedReportKeys, ['manufacturing-statement', 'manufacturing-cost', 'manufacturing-wip', 'production-variance']));
}
// Fixed-asset reports (register / depreciation / asset-GL reconciliation) apply
// to every company, so they are not gated by the trading/manufacturing profile.
$allowedReportRegistry = array_intersect_key($reportRegistry, array_flip($allowedReportKeys));
if (!jewellery_enabled_for_company($companyId) || !user_can_do('jewellery', 'post')) {
    unset($allowedReportRegistry['jewellery-aml-register']);
}

// ---------------------------------------------------------------------------
// Filters.
// ---------------------------------------------------------------------------
$reportId = (string) ($_GET['report'] ?? 'trial-balance');
if (!isset($allowedReportRegistry[$reportId])) {
    $reportId = array_key_first($allowedReportRegistry) ?: 'trial-balance';
}

$fiscalYears = fiscal_years_for_company($companyId, false);
$fiscalYearId = (int) current_fiscal_year_id();
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

// STRICT AD date check: the value must round-trip exactly (rejects rolled-over
// impossibilities like 2084-03-32) and sit in a plausible AD range — a BS
// string such as 2084-03-15 must never be compared against AD periods.
$isValidDate = static function (string $value): bool {
    if ($value === '') {
        return false;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $dt instanceof DateTimeImmutable
        && $dt->format('Y-m-d') === $value
        && $value >= '1990-01-01' && $value <= '2060-12-31';
};
$fromRequested = trim((string) ($_GET['from'] ?? ''));
$toRequested = trim((string) ($_GET['to'] ?? ''));
$dateFilterNotice = '';
$fyClampStart = (string) ($selectedFiscalYear['start_date'] ?? '');
$fyClampEnd = (string) ($selectedFiscalYear['end_date'] ?? '');
$fromDate = $fromRequested;
$toDate = $toRequested;
if (($fromRequested !== '' && !$isValidDate($fromRequested)) || ($toRequested !== '' && !$isValidDate($toRequested))) {
    $dateFilterNotice = 'The supplied dates could not be read as valid calendar dates — showing the full fiscal year instead.';
    $fromDate = '';
    $toDate = '';
}
if (!$isValidDate($fromDate)) {
    $fromDate = $fyClampStart !== '' ? $fyClampStart : date('Y') . '-01-01';
}
if (!$isValidDate($toDate)) {
    $toDate = $fyClampEnd !== '' ? $fyClampEnd : date('Y') . '-12-31';
}
if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}
// Date filters can never leave the selected fiscal year. A range that lies
// entirely outside it (stale filters from a previously selected year, an old
// bookmark) RESETS to the full year; a partially-outside range is clamped.
// Either way the user is told what happened instead of silently seeing
// different dates than requested.
if ($fyClampStart !== '' && $fyClampEnd !== '') {
    if ($toDate < $fyClampStart || $fromDate > $fyClampEnd) {
        $dateFilterNotice = 'The requested period ' . $fromDate . ' to ' . $toDate . ' lies outside fiscal year ' . ($selectedFiscalYear['label'] ?? '') . ' (' . $fyClampStart . ' to ' . $fyClampEnd . ') — showing the full fiscal year instead.';
        $fromDate = $fyClampStart;
        $toDate = $fyClampEnd;
    } else {
        $fromClamped = max($fyClampStart, min($fromDate, $fyClampEnd));
        $toClamped = max($fyClampStart, min($toDate, $fyClampEnd));
        if ($fromClamped !== $fromDate || $toClamped !== $toDate) {
            $dateFilterNotice = 'The requested period was adjusted to stay inside fiscal year ' . ($selectedFiscalYear['label'] ?? '') . ': showing ' . $fromClamped . ' to ' . $toClamped . '.';
        }
        $fromDate = $fromClamped;
        $toDate = $toClamped;
    }
}

$voucherType = '';
$ledgerFilterId = $reportId === 'ledger-report' ? (int) ($_GET['ledger_id'] ?? 0) : 0;
$groupFilterId = $reportId === 'group-report' ? (int) ($_GET['group_id'] ?? 0) : 0;
$itemFilterId = $reportId === 'stock-ledger' ? (int) ($_GET['item_id'] ?? 0) : 0;
$businessType = $companyBusinessType;
$subsidiaries = child_companies_for_company($companyId);
$scopeCompanyId = $companyId;

// Reporting dimensions (Branch / Department / Cost centre) captured on
// vouchers. Options are the distinct values actually used, so the filters
// only appear once dimension data exists for the scoped company.
$dimensionLabels = ['location' => 'Branch / Location', 'department' => 'Department / Project', 'cost_centre' => 'Cost Centre'];
$dimensionDefaults = [
    'location' => ['Head Office', 'Branch Office', 'Client Site'],
    'department' => ['Accounts & Finance', 'Administration', 'Operations', 'Consulting', 'Training', 'Sales & Marketing'],
    'cost_centre' => ['General', 'Accounting Services', 'Advisory', 'Training', 'Administration'],
];
$dimensionOptions = [];
$dimensionFilters = [];
$supportsDimensions = in_array($reportId, ['trial-balance', 'profit-loss', 'balance-sheet', 'ledger-report', 'group-report'], true);
foreach ($dimensionLabels as $dimColumn => $dimLabel) {
    try {
        $dimStmt = db()->prepare("SELECT DISTINCT {$dimColumn} FROM vouchers WHERE company_id = :cid AND {$dimColumn} IS NOT NULL AND {$dimColumn} <> '' ORDER BY {$dimColumn} ASC");
        $dimStmt->execute(['cid' => $scopeCompanyId]);
        $values = array_map('strval', $dimStmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $exception) {
        $values = [];
    }
    if ($dimColumn === 'cost_centre' && column_exists('voucher_entries', 'cost_centre')) {
        $lineDims = db()->prepare("SELECT DISTINCT e.cost_centre FROM voucher_entries e INNER JOIN vouchers v ON v.id = e.voucher_id WHERE v.company_id = ? AND e.cost_centre IS NOT NULL AND e.cost_centre <> ''");
        $lineDims->execute([$companyId]);
        $values = array_merge($values, $lineDims->fetchAll(PDO::FETCH_COLUMN));
    }
    $values = array_values(array_unique(array_merge($dimensionDefaults[$dimColumn], $values)));
    sort($values);
    $dimensionOptions[$dimColumn] = $values;
    $selected = trim((string) ($_GET['dim_' . $dimColumn] ?? ''));
    if ($supportsDimensions && $selected !== '' && in_array($selected, $values, true)) {
        $dimensionFilters[$dimColumn] = $selected;
    }
}

// Salary Sheet: pick a specific payroll run (dropdown shows only on that report).
$payrollRunOptions = [];
$payrollRunFilter = 0;
if ($reportId === 'salary-sheet' && table_exists('payroll_runs')) {
    $payrollRunsStmt = db()->prepare("SELECT id, period_label, status, pay_date FROM payroll_runs
        WHERE company_id = :cid AND status <> 'cancelled' ORDER BY created_at DESC LIMIT 40");
    $payrollRunsStmt->execute(['cid' => $scopeCompanyId]);
    $payrollRunOptions = $payrollRunsStmt->fetchAll();
    $requestedPayrollRun = (int) ($_GET['payroll_run'] ?? 0);
    foreach ($payrollRunOptions as $payrollRunOption) {
        if ((int) $payrollRunOption['id'] === $requestedPayrollRun) {
            $payrollRunFilter = $requestedPayrollRun;
        }
    }
}

// Notes to Accounts: numbered narrative notes stored per company + fiscal
// year + report, shown under the statement on screen and in print.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_report_notes') {
    verify_csrf();
    $noteNos = array_values((array) ($_POST['note_no'] ?? []));
    $noteBodies = array_values((array) ($_POST['note_body'] ?? []));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM report_notes WHERE company_id = :cid AND fiscal_year_id = :fy AND report_key = :rk')
            ->execute(['cid' => $scopeCompanyId, 'fy' => $fiscalYearId, 'rk' => $reportId]);
        $insertNote = $pdo->prepare('INSERT INTO report_notes (company_id, fiscal_year_id, report_key, note_no, body, updated_by)
            VALUES (:cid, :fy, :rk, :no, :body, :by)
            ON DUPLICATE KEY UPDATE body = VALUES(body), updated_by = VALUES(updated_by)');
        $savedNotes = 0;
        foreach ($noteBodies as $index => $rawBody) {
            $body = trim((string) $rawBody);
            if ($body === '') {
                continue;
            }
            $noteNo = trim((string) ($noteNos[$index] ?? ''));
            if ($noteNo === '') {
                $noteNo = (string) ($savedNotes + 1);
            }
            $insertNote->execute([
                'cid' => $scopeCompanyId,
                'fy' => $fiscalYearId,
                'rk' => $reportId,
                'no' => mb_substr($noteNo, 0, 10),
                'body' => $body,
                'by' => $userId,
            ]);
            $savedNotes++;
        }
        $pdo->commit();
        flash('success', $savedNotes > 0 ? 'Notes to Accounts saved (' . $savedNotes . ').' : 'Notes to Accounts cleared.');
    } catch (Throwable $exception) {
        $pdo->rollBack();
        flash('error', 'Could not save notes: ' . $exception->getMessage());
    }
    $queryString = http_build_query($_GET);
    redirect('admin/reports-center.php' . ($queryString !== '' ? '?' . $queryString : ''));
}

$reportNotes = [];
if (table_exists('report_notes')) {
    $notesStmt = db()->prepare('SELECT note_no, body FROM report_notes WHERE company_id = :cid AND fiscal_year_id = :fy AND report_key = :rk ORDER BY LENGTH(note_no) ASC, note_no ASC');
    $notesStmt->execute(['cid' => $scopeCompanyId, 'fy' => $fiscalYearId, 'rk' => $reportId]);
    $reportNotes = $notesStmt->fetchAll();
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
    'dims' => $dimensionFilters,
    'hide_zero' => ($_GET['hide_zero'] ?? '') === '1',
    'payroll_run' => $payrollRunFilter,
    // Temporary (income/expense) accounts reset at this boundary; prior
    // years' P&L reports as Retained Earnings b/f — see rc_ledger_balances.
    'fy_start' => (string) ($selectedFiscalYear['start_date'] ?? ''),
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
// Comparative reports are the ONE sanctioned way to look at another period:
// the comparison year is chosen separately, labelled separately, and resolves
// its own fiscal-year boundary so its P&L accounts reset at the right date.
$compareContext = $generatorContext;
if ($compareEnabled) {
    $compareFy = fiscal_year_for_date($scopeCompanyId, $compareFrom);
    $compareContext['fy_start'] = (string) ($compareFy['start_date'] ?? '');
}
$compareReport = $compareEnabled ? rc_generate($reportId, $scopeCompanyId, $compareFrom, $compareTo, $compareContext) : null;
[$reportLabel, $reportDescription, $reportIcon] = $reportRegistry[$reportId];

// ---------------------------------------------------------------------------
// CSV export.
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_permission('reports', 'export');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $reportId . '-' . $fromDate . '-to-' . $toDate . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [$reportLabel . ' — ' . ($company['name'] ?? '') . ' — ' . app_date_range($fromDate, $toDate)], ',', '"', '\\');
    fputcsv($out, array_map(static fn (array $col): string => ($col[2] !== '' ? $col[2] . ' ' : '') . $col[0], $report['columns']), ',', '"', '\\');
    foreach ($report['rows'] as $row) {
        fputcsv($out, rc_row_cells($row), ',', '"', '\\');
    }
    if ($report['totals'] !== null) {
        fputcsv($out, $report['totals'], ',', '"', '\\');
    }
    security_event('report_exported', 'success', 'Report exported: ' . $reportLabel . ' (CSV).', $companyId, $userId);
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Print / PDF view (standalone, browser print dialog handles PDF).
// ---------------------------------------------------------------------------
$hasGroups = rc_has_group_columns($report);
$hasHierarchy = (bool) array_filter($report['rows'] ?? [], static fn (array $row): bool => isset($row['meta']['level']));

$reportMeta = [
    'report_label' => $reportLabel,
    'company_name' => (string) ($generatorContext['company_name'] ?: ($company['name'] ?? app_name())),
    'from' => $fromDate,
    'to' => $toDate,
    'fiscal_label' => (string) ($selectedFiscalYear['label'] ?? ''),
    'branch' => (string) ($dimensionFilters['location'] ?? 'Head Office')
        . (isset($dimensionFilters['department']) ? ' / ' . $dimensionFilters['department'] : '')
        . (isset($dimensionFilters['cost_centre']) ? ' / ' . $dimensionFilters['cost_centre'] : ''),
    'currency_code' => trim($currencySymbol, ' .') !== '' ? rtrim(trim($currencySymbol), '. ') : 'NPR',
    'generated_by' => (string) ($currentUser['name'] ?? 'System'),
    'pdf_url' => rc_url(['view' => 'print']),
    'excel_url' => rc_url(['export' => 'xlsx']),
];
// The section number ("5." on the Ledger Report) belongs to a BOUND SET of
// financial statements, where the reader flips between numbered sections. A
// report exported on its own is not section five of anything — the number
// only made people ask what the other four were. The bar keeps the report's
// name, which a printed page does need; the number stays off it.
$reportNumberedTitle = (string) ($report['title'] ?? $reportLabel);

// ---------------------------------------------------------------------------
// Excel export.
// ---------------------------------------------------------------------------
// "Export Excel" used to hand over the CSV above, which opens in Excel and is
// not a spreadsheet in any sense that matters: no heading, no borders, columns
// too narrow to read, and every figure stored as text so the one thing an
// accountant opens a report to do — select a column and read its total — does
// nothing. The workbook below is the same report the print view renders, with
// the same letterhead, the same emphasis on the same rows, and real numbers
// underneath. The CSV stays for anything that needs to be re-imported.
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    require_permission('reports', 'export');
    require_once __DIR__ . '/../../app/export_engine.php';
    $workbook = rc_workbook($report, $reportMeta + ['notes' => $reportNotes]);
    security_event('report_exported', 'success', 'Report exported: ' . $reportLabel . ' (Excel).', $companyId, $userId);
    export_xlsx(
        $reportId . '-' . $fromDate . '-to-' . $toDate . '.xlsx',
        $workbook['rows'],
        $workbook['sheet'],
        $workbook['widths'],
        $workbook['options']
    );
}

if (isset($_GET['view']) && $_GET['view'] === 'print') {
    // The print view carries the full report — it must not bypass the export
    // permission the CSV path enforces.
    require_permission('reports', 'export');
    ?><!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title><?= e($reportNumberedTitle) ?> | <?= e(statement_company_name((string) $reportMeta['company_name'])) ?></title>
        <style>
            body { font-family: "Inter", "Segoe UI", system-ui, sans-serif; margin: 30px; color: #16263e; }
            /* Full border now that nothing sits on top of it. */
            .rpt-letterhead { display: flex; justify-content: space-between; gap: 16px; padding: 14px; border: 1px solid #d7dfeb; border-radius: 4px 4px 0 0; }
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
            tr[data-level="1"] td.rpt-cell-main { padding-left: 24px; }
            tr[data-level="2"] td.rpt-cell-main { padding-left: 44px; }
            .rpt-notes { border: 1px solid #d7dfeb; border-top: 0; padding: 12px 14px; }
            .rpt-notes-title { font-weight: 700; font-size: 12.5px; color: #16325d; letter-spacing: 0.03em; margin-bottom: 7px; }
            .rpt-note { display: flex; gap: 8px; font-size: 12px; margin-bottom: 6px; }
            .rpt-note-no { font-weight: 700; color: #16325d; min-width: 26px; }
            .rpt-foot { display: flex; gap: 18px; padding: 9px 12px; border: 1px solid #d7dfeb; border-top: 0; color: #55657e; font-size: 11px; }
            .print-button { margin-top: 20px; padding: 10px 18px; background: #16325d; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
            @media print { .print-button { display: none; } body { margin: 8mm; } }
        </style>
    <?= report_print_styles() ?>
</head>
    <body>
        <?php // No title bar. The letterhead below prints the statement title
              // already, and a heavy coloured band repeating it is the first thing
              // on a page that a reader has no use for. ?>
        <?php rc_render_letterhead($report, $reportMeta); ?>
        <?php rc_render_table($report, $hasGroups); ?>
        <?php rc_render_notes($reportNotes); ?>
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
$showItemFilter = $reportId === 'stock-ledger' && $allItems !== [] && ($companyBusinessProfile['show_inventory'] ?? false);
// Balance the visible report-specific controls across two desktop rows.
$filterControlCount = 2 + ($supportsDimensions ? 3 : 0)
    + (in_array($reportId, ['ledger-report', 'group-report', 'salary-sheet'], true) ? 1 : 0)
    + ($showItemFilter ? 1 : 0) + ($compareEnabled ? 2 : 0)
    + 3 + ($reportId === 'trial-balance' ? 1 : 0) + ($hasHierarchy ? 1 : 0);
$filterColumns = (int) ceil($filterControlCount / 2);
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="rc2-toolbar mbw-card">
    <label class="rc2-report-view" title="Choose which report to view">
        <?= icon('reports') ?>
        <span class="sr-only">Report view</span>
        <select onchange="if (this.value) { window.location = this.value; }" aria-label="Choose report">
            <?php foreach ($allowedReportRegistry as $key => [$label, $description, $iconName]): ?>
                <option value="<?= e(rc_url(['report' => $key, 'ledger_id' => null, 'group_id' => null, 'item_id' => null, 'payroll_run' => null, 'hide_zero' => null])) ?>" <?= $key === $reportId ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
</div>

<?php if ($dateFilterNotice !== ''): ?>
    <div class="notice" style="margin-bottom:12px" role="status"><?= e($dateFilterNotice) ?></div>
<?php endif; ?>
<form method="get" action="<?= e(url('admin/reports-center.php')) ?>" class="rc-filter-card rc2-filters" id="rc2FilterForm" data-fy-start="<?= e((string) ($selectedFiscalYear['start_date'] ?? '')) ?>" data-fy-end="<?= e((string) ($selectedFiscalYear['end_date'] ?? '')) ?>">
    <input type="hidden" name="report" value="<?= e($reportId) ?>">
    <?php if ($compareEnabled): ?><input type="hidden" name="compare" value="1"><?php endif; ?>
    <div class="rc2-filters-body" style="--rc-filter-columns:<?= $filterColumns ?>">
    <div class="rc-filter-grid">
        <label>From Date<input type="date" name="from" value="<?= e($fromDate) ?>"></label>
        <label>To Date<input type="date" name="to" value="<?= e($toDate) ?>"></label>
        <?php if ($supportsDimensions): ?>
        <label>Branch
            <?php if (isset($dimensionOptions['location'])): ?>
                <select name="dim_location">
                    <option value="">All Branches</option>
                    <?php foreach ($dimensionOptions['location'] as $dimValue): ?>
                        <option value="<?= e($dimValue) ?>" <?= ($dimensionFilters['location'] ?? '') === $dimValue ? 'selected' : '' ?>><?= e($dimValue) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <select disabled title="No branch/location has been recorded on vouchers yet. Set it in the voucher form's Location field."><option>All Branches</option></select>
            <?php endif; ?>
        </label>

        <label>Department / Project
            <?php if (isset($dimensionOptions['department'])): ?>
                <select name="dim_department">
                    <option value="">All Departments</option>
                    <?php foreach ($dimensionOptions['department'] as $dimValue): ?>
                        <option value="<?= e($dimValue) ?>" <?= ($dimensionFilters['department'] ?? '') === $dimValue ? 'selected' : '' ?>><?= e($dimValue) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <select disabled title="No department/project has been recorded on vouchers yet. Set it in the voucher form's Department field."><option>All Departments</option></select>
            <?php endif; ?>
        </label>
        <label>Cost Center
            <?php if (isset($dimensionOptions['cost_centre'])): ?>
                <select name="dim_cost_centre">
                    <option value="">All Cost Centers</option>
                    <?php foreach ($dimensionOptions['cost_centre'] as $dimValue): ?>
                        <option value="<?= e($dimValue) ?>" <?= ($dimensionFilters['cost_centre'] ?? '') === $dimValue ? 'selected' : '' ?>><?= e($dimValue) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <select disabled title="No cost centre has been recorded on vouchers yet. Set it in the voucher form's Cost Centre field."><option>All Cost Centers</option></select>
            <?php endif; ?>
        </label>
        <?php endif; ?>
        <?php if ($reportId === 'salary-sheet'): ?>
            <label>Payroll Run
                <select name="payroll_run">
                    <option value="0">Latest run in period</option>
                    <?php foreach ($payrollRunOptions as $payrollRunOption): ?>
                        <option value="<?= e((int) $payrollRunOption['id']) ?>" <?= $payrollRunFilter === (int) $payrollRunOption['id'] ? 'selected' : '' ?>>
                            <?= e($payrollRunOption['period_label'] . ' — ' . ucfirst((string) $payrollRunOption['status'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <?php if ($reportId === 'ledger-report'): ?>
        <label>Ledger
            <select name="ledger_id" class="js-searchable" aria-label="Search ledger by name or code">
                <option value="0">Default cash ledger</option>
                <?php foreach ($allLedgers as $ledger): ?>
                    <option value="<?= e((int) $ledger['id']) ?>" <?= $ledgerFilterId === (int) $ledger['id'] ? 'selected' : '' ?>><?= e($ledger['code'] . ' - ' . $ledger['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <?php if ($reportId === 'group-report'): ?>
        <label>Group
            <select name="group_id">
                <option value="0">All Groups</option>
                <?php foreach ($allGroups as $group): ?>
                    <option value="<?= e((int) $group['id']) ?>" <?= $groupFilterId === (int) $group['id'] ? 'selected' : '' ?>><?= e($group['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <?php if ($showItemFilter): ?>
            <label>Inventory Item
                <select name="item_id">
                    <option value="0">First item by SKU (default)</option>
                    <?php foreach ($allItems as $item): ?>
                        <option value="<?= e((int) $item['id']) ?>" <?= $itemFilterId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['sku'] . ' - ' . $item['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <?php if ($compareEnabled): ?>
            <label>Compare From<input type="date" name="cfrom" value="<?= e($compareFrom) ?>"></label>
            <label>Compare To<input type="date" name="cto" value="<?= e($compareTo) ?>"></label>
        <?php endif; ?>
    </div>
    <div class="rc2-filter-actions">
        <button type="submit" class="button secondary"><?= icon('filter') ?>Apply</button>
        <a class="button secondary" href="<?= e(url('admin/reports-center.php?report=' . $reportId)) ?>">Clear</a>
        <?php if ($reportId === 'trial-balance'): ?>
            <label class="rc2-zero-toggle"><input type="checkbox" name="hide_zero" value="1" <?= ($_GET['hide_zero'] ?? '') === '1' ? 'checked' : '' ?>>Hide zero balances</label>
        <?php endif; ?>
        <?php if ($hasHierarchy): ?>
        <label class="rc2-view-select">Display Level
            <select id="rc2Level" aria-label="Display level">
                <option value="master">Summary — Masters</option>
                <option value="group" selected>Group Summary</option>
                <option value="ledger">Full Ledger Detail</option>
                <option value="compact">Compact Detail</option>
            </select>
        </label>
        <?php endif; ?>
        <div class="rc2-export" data-rc2-export>
            <button type="button" class="rc2-export-btn" aria-haspopup="true" aria-expanded="false"><?= icon('download') ?>Export<span class="rc2-caret"><?= icon('chevron') ?></span></button>
            <div class="rc2-export-menu" role="menu">
                <?php // Named in words. Three unlabelled icons in a row left people
                      // guessing which one was the spreadsheet, and the one they
                      // guessed was the CSV. ?>
                <a role="menuitem" target="_blank" href="<?= e(rc_url(['view' => 'print'])) ?>" title="Opens a print view your browser saves as PDF"><?= icon('documents') ?>Export PDF</a>
                <a role="menuitem" href="<?= e(rc_url(['export' => 'xlsx'])) ?>" title="Formatted workbook with headings, borders and real figures"><?= icon('analytics') ?>Export Excel (.xlsx)</a>
                <a role="menuitem" href="<?= e(rc_url(['export' => 'csv'])) ?>" title="Plain comma-separated data, for re-importing elsewhere"><?= icon('download') ?>Export CSV</a>
                <a role="menuitem" target="_blank" href="<?= e(rc_url(['view' => 'print'])) ?>"><?= icon('receipt-voucher') ?>Print Report</a>
                <a role="menuitem" href="<?= e(url('admin/report-schedules.php?report_key=' . urlencode($reportId))) ?>"><?= icon('calendar') ?>Schedule Reports</a>
                <a role="menuitem" href="<?= e(rc_url($compareEnabled ? ['compare' => null, 'cfrom' => null, 'cto' => null] : ['compare' => '1'])) ?>"><?= icon('reconcile') ?>Compare Period: <?= $compareEnabled ? 'On' : 'Off' ?></a>
            </div>
        </div>
    </div>
    </div>
</form>

<section class="rc2-preview mbw-card" id="rc2Preview" aria-label="Report preview">
<div class="rpt-fullwidth">
    <main class="rc-report-view rpt-statement" id="rc2Statement">
        <div class="rc-table-scroll">
            <?php rc_render_table($report, $hasGroups, url('admin/ledgers.php?from=' . $fromDate . '&to=' . $toDate . '&ledger_id=')); ?>
        </div>
        <?php if ($compareEnabled && $compareReport !== null): ?>
            <div class="rpt-bar rpt-bar-compare">Comparison Period — <?= e(date('d M Y', strtotime($compareFrom))) ?> to <?= e(date('d M Y', strtotime($compareTo))) ?></div>
            <div class="rc-table-scroll">
                <?php rc_render_table($compareReport, $hasGroups, url('admin/ledgers.php?from=' . $compareFrom . '&to=' . $compareTo . '&ledger_id=')); ?>
            </div>
        <?php endif; ?>
        <?php rc_render_notes($reportNotes); ?>
    </main>
</div>
</section>

<?php if ($reportNotes !== []): ?>
<details class="mbw-card rc2-notes-editor" open>
    <summary class="rc2-notes-summary">
        <?= icon('documents') ?>
        <span>
            <strong>Notes to Accounts</strong>
            
        </span>
        <span class="mbw-pill <?= $reportNotes !== [] ? 'tone-green' : '' ?>"><?= count($reportNotes) ?> note<?= count($reportNotes) === 1 ? '' : 's' ?></span>
    </summary>
    <form method="post" action="<?= e(rc_url()) ?>" id="rc2NotesForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_report_notes">
        <div id="rc2NotesRows">
            <?php foreach ($reportNotes as $note): ?>
                <div class="rc2-note-row">
                    <input type="text" name="note_no[]" value="<?= e((string) $note['note_no']) ?>" maxlength="10" aria-label="Note number" placeholder="No.">
                    <textarea name="note_body[]" rows="2" aria-label="Note text" placeholder="Note text..."><?= e((string) $note['body']) ?></textarea>
                    <button type="button" class="rc2-note-remove mbw-delete-action" data-note-remove title="Delete this note" aria-label="Delete this note"><?= icon('trash') ?></button>
                </div>
            <?php endforeach; ?>
        </div>
        <template id="rc2NoteTemplate">
            <div class="rc2-note-row">
                <input type="text" name="note_no[]" value="" maxlength="10" aria-label="Note number" placeholder="No.">
                <textarea name="note_body[]" rows="2" aria-label="Note text" placeholder="Note text..."></textarea>
                <button type="button" class="rc2-note-remove mbw-delete-action" data-note-remove title="Delete this note" aria-label="Delete this note"><?= icon('trash') ?></button>
            </div>
        </template>
        <div class="rc2-notes-actions">
            <button type="button" class="mbw-btn-ghost" id="rc2NoteAdd"><?= icon('plus') ?>Add Note</button>
            <button type="submit"><?= icon('tasks') ?>Save Notes</button>
        </div>
    </form>
</details>
<?php endif; ?>

<script>
(function () {
    var rowsHost = document.getElementById('rc2NotesRows');
    var template = document.getElementById('rc2NoteTemplate');
    var addBtn = document.getElementById('rc2NoteAdd');
    if (!rowsHost || !template || !addBtn) { return; }
    addBtn.addEventListener('click', function () {
        rowsHost.appendChild(template.content.cloneNode(true));
        var added = rowsHost.querySelector('.rc2-note-row:last-child input');
        if (added) { added.focus(); }
    });
    rowsHost.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-note-remove]');
        if (btn) { btn.closest('.rc2-note-row').remove(); }
    });
    if (!rowsHost.children.length) {
        rowsHost.appendChild(template.content.cloneNode(true));
    }
})();
</script>

<script>
(function () {
    var form = document.getElementById('rc2FilterForm');

    // Export dropdown menu.
    var exportWrap = document.querySelector('[data-rc2-export]');
    if (exportWrap) {
        exportWrap.querySelector('.rc2-export-btn').addEventListener('click', function (event) {
            event.stopPropagation();
            exportWrap.classList.toggle('is-open');
            this.setAttribute('aria-expanded', exportWrap.classList.contains('is-open') ? 'true' : 'false');
        });
        function closeExport() {
            exportWrap.classList.remove('is-open');
            exportWrap.querySelector('button').setAttribute('aria-expanded', 'false');
        }
        document.addEventListener('click', closeExport);
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeExport(); });
    }

    // --- Unified statement hierarchy: display level, expand/collapse,
    //     account search, and ledger drill-down -----------------------------
    var statement = document.getElementById('rc2Statement');
    var hierRows = statement ? Array.prototype.slice.call(statement.querySelectorAll('tr[data-level]')) : [];
    var levelSelect = document.getElementById('rc2Level');
    var ledgerDrillBase = <?= json_encode(url('admin/reports-center.php?report=ledger-report&fy=' . $fiscalYearId . '&from=' . $fromDate . '&to=' . $toDate . '&ledger_id=')) ?>;

    var parentOf = {};
    hierRows.forEach(function (tr) {
        if (tr.dataset.node) { parentOf[tr.dataset.node] = tr.dataset.parent || ''; }
    });
    var collapsedNodes = {};

    function hierRefresh() {
        hierRows.forEach(function (tr) {
            var hidden = false;
            var p = tr.dataset.parent || '';
            while (p) {
                if (collapsedNodes[p]) { hidden = true; break; }
                p = parentOf[p] || '';
            }
            tr.classList.toggle('rpt-hidden', hidden);
            if (tr.dataset.node) { tr.classList.toggle('is-collapsed', !!collapsedNodes[tr.dataset.node]); }
        });
    }

    function setDisplayLevel(level) {
        collapsedNodes = {};
        if (level === 'master') {
            hierRows.forEach(function (tr) { if (tr.dataset.node && tr.dataset.level === '0') { collapsedNodes[tr.dataset.node] = true; } });
        } else if (level === 'group') {
            hierRows.forEach(function (tr) { if (tr.dataset.node && tr.dataset.level === '1') { collapsedNodes[tr.dataset.node] = true; } });
        }
        if (statement) { statement.classList.toggle('is-compact', level === 'compact'); }
        hierRefresh();
    }

    if (hierRows.length && statement) {
        setDisplayLevel(levelSelect ? levelSelect.value : 'group');
        if (levelSelect) {
            levelSelect.addEventListener('change', function () { setDisplayLevel(levelSelect.value); });
        }
        statement.addEventListener('click', function (event) {
            if (event.target.closest('a')) return;
            var tr = event.target.closest('tr');
            if (!tr) { return; }
            if (tr.dataset.node) {
                collapsedNodes[tr.dataset.node] = !collapsedNodes[tr.dataset.node];
                hierRefresh();
            } else if (tr.dataset.ledgerId) {
                window.location = ledgerDrillBase + tr.dataset.ledgerId;
            }
        });

    } else {
        document.querySelectorAll('.rc2-hier-only').forEach(function (el) { el.style.display = 'none'; });
        if (levelSelect && statement) {
            levelSelect.addEventListener('change', function () {
                statement.classList.toggle('is-compact', levelSelect.value === 'compact');
            });
        }
    }

})();
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
