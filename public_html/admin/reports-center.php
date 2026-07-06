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
$pageSubtitle = 'Smart, reliable and actionable reports for better financial insights.';
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
    $allowedReportKeys = array_values(array_diff($allowedReportKeys, ['inventory-summary', 'stock-ledger']));
}
if (!($companyBusinessProfile['show_manufacturing'] ?? false)) {
    $allowedReportKeys = array_values(array_diff($allowedReportKeys, ['manufacturing-statement']));
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
    fputcsv($out, [$reportLabel . ' — ' . ($company['name'] ?? '') . ' — ' . $fromDate . ' to ' . $toDate]);
    fputcsv($out, array_map(static fn (array $col): string => ($col[2] !== '' ? $col[2] . ' ' : '') . $col[0], $report['columns']));
    foreach ($report['rows'] as $row) {
        fputcsv($out, $row);
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

if (isset($_GET['view']) && $_GET['view'] === 'print') {
    ?><!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title><?= e($reportLabel) ?> | <?= e($company['name'] ?? app_name()) ?></title>
        <style>
            body { font-family: Georgia, 'Times New Roman', serif; margin: 36px; color: #1c2434; }
            h1 { margin: 0; font-size: 22px; }
            .muted { color: #64748b; font-size: 12px; }
            .rc-table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 12.5px; }
            .rc-table th, .rc-table td { border: 1px solid #d7dfeb; padding: 6px 9px; }
            .rc-table thead th { background: #f4f7fc; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.04em; }
            .align-right { text-align: right; }
            .rc-total-row td { font-weight: bold; background: #f8fafc; }
            .print-button { margin-top: 20px; padding: 10px 18px; background: #05295f; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
            @media print { .print-button { display: none; } body { margin: 10mm; } }
        </style>
    </head>
    <body>
        <h1><?= e($reportLabel) ?></h1>
        <div class="muted"><?= e($company['name'] ?? '') ?> · <?= e(date('d M Y', strtotime($fromDate))) ?> to <?= e(date('d M Y', strtotime($toDate))) ?> · Generated <?= e(date('d M Y H:i')) ?></div>
        <?php rc_render_table($report, $hasGroups); ?>
        <div class="muted" style="margin-top:10px;">All amounts are in <?= e(trim($currencySymbol, ' ')) ?: 'NPR' ?>. Figures are rounded off to 2 decimal places.</div>
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
<form method="get" action="<?= e(url('admin/reports-center.php')) ?>" class="rc-filter-card">
    <input type="hidden" name="report" value="<?= e($reportId) ?>">
    <?php if ($compareEnabled): ?><input type="hidden" name="compare" value="1"><?php endif; ?>
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
        <div class="rc-biz-toggle">
            <span>Business Type</span>
            <div>
                <?php foreach ($businessTypeOptions as $bizValue => $bizLabel): ?>
                    <button type="submit" name="biz" value="<?= e($bizValue) ?>" class="<?= $businessType === $bizValue ? 'is-active' : '' ?>"><?= e($bizLabel) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="rc-filter-apply"><button type="submit" class="button"><?= icon('settings') ?>Apply Filters</button></div>
    </div>
</form>

<section class="coa-module-grid" aria-label="Report modules">
    <?php foreach ($allowedReportRegistry as $key => [$label, $description, $iconName]): ?>
        <a class="coa-module-card <?= $key === $reportId ? 'is-active' : '' ?>" href="<?= e(rc_url(['report' => $key])) ?>">
            <div class="coa-module-icon"><?= icon($iconName) ?></div>
            <div>
                <strong><?= e($label) ?></strong>
                <span><?= e($description) ?></span>
            </div>
        </a>
    <?php endforeach; ?>
    <a class="coa-module-card" href="<?= e(url('admin/report-schedules.php')) ?>">
        <div class="coa-module-icon"><?= icon('compliance') ?></div>
        <div>
            <strong>Schedule Reports</strong>
            <span>Manage recurring deliveries</span>
        </div>
    </a>
</section>

<div class="rc-workspace">
    <aside class="rc-report-list">
        <h3><?= icon('documents') ?>All Reports</h3>
        <div class="rc-report-grid">
            <?php foreach ($allowedReportRegistry as $key => [$label, $description, $iconName]): ?>
                <a class="rc-report-card <?= $key === $reportId ? 'is-active' : '' ?>" href="<?= e(rc_url(['report' => $key])) ?>">
                    <span><?= icon($iconName) ?></span>
                    <strong><?= e($label) ?></strong>
                    <small><?= e($description) ?></small>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <main class="rc-report-view">
        <div class="rc-report-head">
            <div>
                <h3><?= icon($reportIcon) ?><?= e($reportLabel) ?></h3>
                <p><?= e($report['subtitle']) ?></p>
            </div>
            <span class="rc-asof">As on <?= e(date('d M Y', strtotime($toDate))) ?></span>
        </div>
        <div class="rc-table-scroll">
            <?php rc_render_table($report, $hasGroups); ?>
        </div>
        <?php if ($compareEnabled && $compareReport !== null): ?>
            <div class="rc-report-head rc-compare-head">
                <div>
                    <h3><?= icon('compliance') ?>Comparison Period</h3>
                    <p><?= e(date('d M Y', strtotime($compareFrom))) ?> to <?= e(date('d M Y', strtotime($compareTo))) ?></p>
                </div>
            </div>
            <div class="rc-table-scroll">
                <?php rc_render_table($compareReport, $hasGroups); ?>
            </div>
        <?php endif; ?>
        <div class="rc-report-foot">
            <span>All amounts are in <?= e(trim($currencySymbol, ' ')) ?: 'NPR' ?></span>
            <span>Figures are rounded off to 2 decimal places</span>
        </div>
    </main>

    <aside class="rc-actions-col">
        <section class="rc-actions-card">
            <h4>Actions</h4>
            <a class="button rc-action-primary" target="_blank" href="<?= e(rc_url(['view' => 'print'])) ?>"><?= icon('documents') ?>Export PDF</a>
            <a class="button secondary" href="<?= e(rc_url(['export' => 'csv'])) ?>"><?= icon('documents') ?>Export Excel</a>
            <a class="button secondary" target="_blank" href="<?= e(rc_url(['view' => 'print'])) ?>"><?= icon('documents') ?>Print Report</a>
            <a class="button secondary" href="<?= e(url('admin/report-schedules.php')) ?>"><?= icon('compliance') ?>Schedule Reports</a>
        </section>

        <section class="rc-actions-card">
            <div class="rc-compare-toggle">
                <h4>Compare Period</h4>
                <a class="rc-switch <?= $compareEnabled ? 'is-on' : '' ?>" href="<?= e(rc_url(['compare' => $compareEnabled ? null : '1'])) ?>" aria-label="Toggle comparison"><span></span></a>
            </div>
            <?php if ($compareEnabled): ?>
                <form method="get" action="<?= e(url('admin/reports-center.php')) ?>" class="rc-compare-form">
                    <?php foreach (array_diff_key($_GET, ['cfrom' => 1, 'cto' => 1]) as $keepKey => $keepValue): ?>
                        <?php if (is_scalar($keepValue)): ?><input type="hidden" name="<?= e((string) $keepKey) ?>" value="<?= e((string) $keepValue) ?>"><?php endif; ?>
                    <?php endforeach; ?>
                    <input type="hidden" name="compare" value="1">
                    <label>Compare With
                        <select disabled><option>Previous Period</option></select>
                    </label>
                    <div class="rc-compare-dates">
                        <label>From Date<input type="date" name="cfrom" value="<?= e($compareFrom) ?>"></label>
                        <label>To Date<input type="date" name="cto" value="<?= e($compareTo) ?>"></label>
                    </div>
                    <button class="button rc-apply-comparison" type="submit"><?= icon('dashboard') ?>Apply Comparison</button>
                </form>
            <?php else: ?>
                <p class="muted">Turn on to view this report for a second period side by side.</p>
            <?php endif; ?>
        </section>

    </aside>
</div>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
