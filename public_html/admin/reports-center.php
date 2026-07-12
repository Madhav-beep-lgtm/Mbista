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

// Reporting dimensions (Branch / Department / Cost centre) captured on
// vouchers. Options are the distinct values actually used, so the filters
// only appear once dimension data exists for the scoped company.
$dimensionLabels = ['location' => 'Branch / Location', 'department' => 'Department / Project', 'cost_centre' => 'Cost Centre'];
$dimensionOptions = [];
$dimensionFilters = [];
foreach ($dimensionLabels as $dimColumn => $dimLabel) {
    try {
        $dimStmt = db()->prepare("SELECT DISTINCT {$dimColumn} FROM vouchers WHERE company_id = :cid AND {$dimColumn} IS NOT NULL AND {$dimColumn} <> '' ORDER BY {$dimColumn} ASC");
        $dimStmt->execute(['cid' => $scopeCompanyId]);
        $values = array_map('strval', $dimStmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $exception) {
        $values = [];
    }
    if ($values === []) {
        continue;
    }
    $dimensionOptions[$dimColumn] = $values;
    $selected = trim((string) ($_GET['dim_' . $dimColumn] ?? ''));
    if ($selected !== '' && in_array($selected, $values, true)) {
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
    'payroll_run' => $payrollRunFilter,
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
    'branch' => (string) ($dimensionFilters['location'] ?? 'Head Office')
        . (isset($dimensionFilters['department']) ? ' / ' . $dimensionFilters['department'] : '')
        . (isset($dimensionFilters['cost_centre']) ? ' / ' . $dimensionFilters['cost_centre'] : ''),
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
    </head>
    <body>
        <div class="rpt-bar"><?= e($reportNumberedTitle) ?></div>
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
        <label>Company
            <select name="scope_company">
                <option value="<?= e($companyId) ?>" <?= $scopeCompanyId === $companyId ? 'selected' : '' ?>><?= e($company['name'] ?? 'Current company') ?></option>
                <?php foreach ($subsidiaries as $subsidiary): ?>
                    <option value="<?= e((int) $subsidiary['id']) ?>" <?= $scopeCompanyId === (int) $subsidiary['id'] ? 'selected' : '' ?>><?= e($subsidiary['name']) ?></option>
                <?php endforeach; ?>
            </select>
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
                    <option value="0">First item by SKU (default)</option>
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
            <input type="search" id="rc2AcctSearch" class="rc2-acct-search rc2-hier-only" placeholder="Search account..." aria-label="Search accounts in this report">
            <button type="button" class="rc2-mini rc2-hier-only" id="rc2ExpandAll">Expand all</button>
            <button type="button" class="rc2-mini rc2-hier-only" id="rc2CollapseAll">Collapse all</button>
            <span>Display Level</span>
            <label class="rc2-view-select">
                <select id="rc2Level" aria-label="Display level">
                    <option value="master">Summary — Masters</option>
                    <option value="group" selected>Group Summary</option>
                    <option value="ledger">Full Ledger Detail</option>
                    <option value="compact">Compact Detail</option>
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
        <?php rc_render_notes($reportNotes); ?>
        <?php rc_render_report_foot(['generated_by' => $reportMeta['generated_by']]); ?>

        <div class="rc-report-foot">
            <span><?= e($report['subtitle']) ?></span>
            <span>All amounts are in <?= e($reportMeta['currency_code']) ?>, rounded to 2 decimal places</span>
        </div>
    </main>
</div>
</section>

<details class="mbw-card rc2-notes-editor" <?= $reportNotes !== [] ? 'open' : '' ?>>
    <summary class="rc2-notes-summary">
        <?= icon('documents') ?>
        <span>
            <strong>Notes to Accounts</strong>
            <small>Numbered notes for this statement — match them to the NOTE column and they print below the report.</small>
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
                    <button type="button" class="rc2-note-remove" data-note-remove title="Remove this note" aria-label="Remove note">&times;</button>
                </div>
            <?php endforeach; ?>
        </div>
        <template id="rc2NoteTemplate">
            <div class="rc2-note-row">
                <input type="text" name="note_no[]" value="" maxlength="10" aria-label="Note number" placeholder="No.">
                <textarea name="note_body[]" rows="2" aria-label="Note text" placeholder="Note text..."></textarea>
                <button type="button" class="rc2-note-remove" data-note-remove title="Remove this note" aria-label="Remove note">&times;</button>
            </div>
        </template>
        <div class="rc2-notes-actions">
            <button type="button" class="mbw-btn-ghost" id="rc2NoteAdd"><?= icon('plus') ?>Add Note</button>
            <button type="submit"><?= icon('tasks') ?>Save Notes</button>
        </div>
    </form>
</details>

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
            var tr = event.target.closest('tr');
            if (!tr) { return; }
            if (tr.dataset.node) {
                collapsedNodes[tr.dataset.node] = !collapsedNodes[tr.dataset.node];
                hierRefresh();
            } else if (tr.dataset.ledgerId) {
                window.location = ledgerDrillBase + tr.dataset.ledgerId;
            }
        });
        var expandAll = document.getElementById('rc2ExpandAll');
        var collapseAll = document.getElementById('rc2CollapseAll');
        if (expandAll) { expandAll.addEventListener('click', function () { collapsedNodes = {}; hierRefresh(); }); }
        if (collapseAll) { collapseAll.addEventListener('click', function () { setDisplayLevel('master'); if (levelSelect) { levelSelect.value = 'master'; } }); }
        var acctSearch = document.getElementById('rc2AcctSearch');
        if (acctSearch) {
            acctSearch.addEventListener('input', function () {
                var q = acctSearch.value.trim().toLowerCase();
                if (!q) { hierRefresh(); return; }
                var keepNodes = {};
                hierRows.forEach(function (tr) {
                    if (tr.textContent.toLowerCase().indexOf(q) !== -1) {
                        var p = tr.dataset.parent || '';
                        while (p) { keepNodes[p] = true; p = parentOf[p] || ''; }
                        if (tr.dataset.node) { keepNodes[tr.dataset.node] = true; }
                        tr._rc2Match = true;
                    } else {
                        tr._rc2Match = false;
                    }
                });
                hierRows.forEach(function (tr) {
                    var show = tr._rc2Match || (tr.dataset.node && keepNodes[tr.dataset.node]);
                    tr.classList.toggle('rpt-hidden', !show);
                });
            });
        }
    } else {
        document.querySelectorAll('.rc2-hier-only').forEach(function (el) { el.style.display = 'none'; });
        if (levelSelect && statement) {
            levelSelect.addEventListener('change', function () {
                statement.classList.toggle('is-compact', levelSelect.value === 'compact');
            });
        }
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
