<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_admin();
require_company_context();
accounting_module_repair_database();

$company = current_company();
$fiscalYear = current_fiscal_year();
if (!$company || !$fiscalYear) {
    flash('error', 'Company and fiscal year context required.');
    redirect('admin/accounting.php');
}
if ((string) ($company['code'] ?? '') !== 'MBAACA') {
    flash('error', 'The consolidated report is available from the M.Bista superadmin portal only.');
    redirect('admin/accounting-dashboard.php');
}

$companyId = (int) $company['id'];
$fiscalYears = fiscal_years_for_company($companyId, false);
$requestedFiscalYearId = (int) ($_GET['fiscal_year_id'] ?? 0);
if ($requestedFiscalYearId > 0 && $requestedFiscalYearId !== (int) $fiscalYear['id']) {
    foreach ($fiscalYears as $candidateFiscalYear) {
        if ((int) $candidateFiscalYear['id'] === $requestedFiscalYearId) {
            $fiscalYear = $candidateFiscalYear;
            break;
        }
    }
}
$fiscalYearId = (int) $fiscalYear['id'];
$currency = site_currency_symbol();
$fmtMoney = static fn (float $amount): string => $currency . number_format($amount, 2);

// Per-company matrix: the firm + Altiora and every company in its group.
$groupEntries = group_dashboard_companies($fiscalYearId);
$matrixRows = [];
$matrixTotals = ['cash' => 0.0, 'receivables' => 0.0, 'payables' => 0.0, 'income' => 0.0, 'expenses' => 0.0, 'net' => 0.0];
foreach ($groupEntries as $entry) {
    $entryCompanyId = (int) $entry['company']['id'];
    $entryFiscalYearId = (int) $entry['fiscal_year_id'];
    $snapshot = $entryFiscalYearId > 0
        ? company_financials_snapshot($entryCompanyId, $entryFiscalYearId)
        : ['cash' => 0.0, 'receivables' => 0.0, 'payables' => 0.0, 'income' => 0.0, 'expenses' => 0.0, 'net' => 0.0];
    $matrixRows[] = ['company' => $entry['company'], 'fiscal_year_id' => $entryFiscalYearId, 'data' => $snapshot];
    foreach ($matrixTotals as $key => $value) {
        $matrixTotals[$key] += $snapshot[$key];
    }
}

// Altiora group consolidation (IFRS 10) using Altiora's matching fiscal year.
$altiora = company_by_code('AGHPL');
$consolidated = null;
$altioraFiscalYearId = 0;
$investees = [];
if ($altiora) {
    $altioraId = (int) $altiora['id'];
    $altioraFiscalYearId = matching_fiscal_year_id_for_company($altioraId, $fiscalYearId);
    if ($altioraFiscalYearId > 0) {
        $investees = array_values(array_filter(
            accounting_companies_for_group($altioraId),
            static fn (array $row): bool => (int) $row['id'] !== $altioraId
        ));
        $consolidated = get_consolidated_financial_summary($altioraId, $altioraFiscalYearId, $investees);
    }
}

$relationshipTones = ['parent' => 'blue', 'subsidiary' => 'green', 'associate' => 'purple', 'joint_venture' => 'teal'];
$methodLabels = ['full' => 'Full consolidation', 'equity' => 'Equity method', 'proportionate' => 'Proportionate'];

// CSV export of the matrix + consolidation.
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_permission('reports', 'export');
    $rows = [['Consolidated Report — ' . ($fiscalYear['label'] ?? '')]];
    $rows[] = [];
    $rows[] = ['Company Performance Matrix'];
    $rows[] = ['Company', 'Code', 'Cash & Bank', 'Receivables', 'Payables', 'Income', 'Expenses', 'Net Profit'];
    foreach ($matrixRows as $row) {
        $rows[] = [
            (string) $row['company']['name'], (string) ($row['company']['code'] ?? ''),
            $row['data']['cash'], $row['data']['receivables'], $row['data']['payables'],
            $row['data']['income'], $row['data']['expenses'], $row['data']['net'],
        ];
    }
    if ($consolidated !== null) {
        $rows[] = [];
        $rows[] = ['Altiora Group — Consolidated (IFRS 10)'];
        $rows[] = ['Entity', 'Relationship', 'Ownership %', 'Method', 'Income', 'Expenses', 'Profit', 'NCI Share'];
        foreach ($consolidated['entities'] as $entity) {
            $rows[] = [
                (string) $entity['company_name'], (string) $entity['relationship_type'],
                (float) $entity['ownership_percent'], (string) $entity['consolidation_method'],
                (float) $entity['income'], (float) $entity['expenses'],
                (float) $entity['profit'], (float) $entity['nci_profit'],
            ];
        }
        $rows[] = ['Consolidated', '', '', '', (float) $consolidated['total_income'], (float) $consolidated['total_expenses'], (float) $consolidated['net_profit'], (float) $consolidated['nci_profit']];
    }
    security_event('report_exported', 'success', 'Consolidated report exported.', $companyId, (int) (current_user()['id'] ?? 0));
    export_csv('consolidated-report-' . date('Ymd') . '.csv', $rows);
}

$pageTitle = 'Consolidated Report';
$pageSubtitle = 'Group-wide financial view across all companies.';
$pageBreadcrumb = [['Home', 'admin/index.php'], ['Reports', 'admin/reports-center.php']];
$bodyClass = 'admin-layout accounting-module-page admin-dashboard';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card cr-toolbar" aria-label="Report filters">
    <form method="get" class="cr-field">
        <label for="consolidated-fy">Fiscal Year</label>
        <select id="consolidated-fy" name="fiscal_year_id" onchange="this.form.submit()">
            <?php foreach ($fiscalYears as $year): ?>
                <option value="<?= (int) $year['id'] ?>" <?= (int) $year['id'] === $fiscalYearId ? 'selected' : '' ?>><?= e($year['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <div class="cr-field">
        <label for="consolidated-basis">Report Basis</label>
        <select id="consolidated-basis" title="Consolidation basis">
            <option>IFRS 10 - Consolidated</option>
        </select>
    </div>
    <div class="cr-field">
        <label for="consolidated-group">Group</label>
        <select id="consolidated-group" title="Reporting group">
            <option><?= e(($altiora['name'] ?? 'Altiora') === 'Altiora Global Holdings Private Limited' ? 'Altiora Group' : ($altiora['name'] ?? 'Altiora Group')) ?></option>
        </select>
    </div>
    <div class="cr-actions">
        <div class="rc2-export" data-rc2-export>
            <button type="button" class="rc2-export-btn"><?= icon('download') ?>Export<span class="rc2-caret"><?= icon('chevron') ?></span></button>
            <div class="rc2-export-menu" role="menu">
                <a role="menuitem" href="<?= e(url('admin/consolidated-report.php?fiscal_year_id=' . $fiscalYearId . '&export=csv')) ?>"><?= icon('analytics') ?>Export Excel (CSV)</a>
                <a role="menuitem" href="#" onclick="window.print(); return false;"><?= icon('documents') ?>Print / Save as PDF</a>
            </div>
        </div>
        <button type="button" class="rc2-reset" onclick="window.print()"><?= icon('receipt-voucher') ?>Print</button>
        <a class="cr-dark-btn" href="<?= e(url('admin/reports-center.php')) ?>"><?= icon('reports') ?>Reports Center</a>
    </div>
</section>

<?php if ($consolidated !== null): ?>
<section class="cr-kpis" aria-label="Consolidated headline figures">
    <article class="cr-kpi tone-green">
        <span class="mbw-chip tone-green"><?= icon('analytics') ?></span>
        <div>
            <span class="cr-kpi-label">Consolidated Income</span>
            <div class="cr-kpi-value"><?= e($fmtMoney((float) $consolidated['total_income'])) ?></div>
            <span class="cr-kpi-sub">Altiora group (IFRS 10)</span>
        </div>
    </article>
    <article class="cr-kpi tone-red">
        <span class="mbw-chip tone-red"><?= icon('wallet') ?></span>
        <div>
            <span class="cr-kpi-label">Consolidated Expenses</span>
            <div class="cr-kpi-value"><?= e($fmtMoney((float) $consolidated['total_expenses'])) ?></div>
            <span class="cr-kpi-sub">Altiora group (IFRS 10)</span>
        </div>
    </article>
    <article class="cr-kpi tone-blue">
        <span class="mbw-chip tone-blue"><?= icon('trend-up') ?></span>
        <div>
            <span class="cr-kpi-label">Consolidated Net Profit</span>
            <div class="cr-kpi-value"><?= e($fmtMoney((float) $consolidated['net_profit'])) ?></div>
            <span class="cr-kpi-sub <?= (float) $consolidated['net_profit'] >= 0 ? 'is-up' : 'is-down' ?>"><?= e(number_format((float) $consolidated['profit_margin'], 1)) ?>% margin</span>
        </div>
    </article>
    <article class="cr-kpi tone-purple">
        <span class="mbw-chip tone-purple"><?= icon('companies') ?></span>
        <div>
            <span class="cr-kpi-label">Attributable to Parent</span>
            <div class="cr-kpi-value"><?= e($fmtMoney((float) $consolidated['profit_attributable_to_parent'])) ?></div>
            <span class="cr-kpi-sub">NCI share <?= e($fmtMoney((float) $consolidated['nci_profit'])) ?></span>
        </div>
    </article>
</section>
<?php endif; ?>

<section class="mbw-card" aria-label="Company performance matrix">
    <div class="mbw-card-head">
        <h2>Company Performance Matrix — All Group Companies</h2>
        <span class="mbw-info"><?= icon('about') ?></span>
    </div>
    <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>Company</th>
                    <th class="is-numeric">Cash &amp; Bank</th><th class="is-numeric">Receivables</th>
                    <th class="is-numeric">Payables</th><th class="is-numeric">Income</th>
                    <th class="is-numeric">Expenses</th><th class="is-numeric">Net Profit</th>
                    <th class="is-numeric">Margin</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($matrixRows as $row): ?>
                <?php
                $rowData = $row['data'];
                $rowCompany = $row['company'];
                $margin = $rowData['income'] > 0 ? ($rowData['net'] / $rowData['income']) * 100 : 0.0;
                ?>
                <tr>
                    <td>
                        <strong style="color:var(--mbw-heading)"><?= e($rowCompany['name']) ?></strong>
                        <span class="mbw-pill tone-<?= (string) ($rowCompany['code'] ?? '') === 'MBAACA' ? 'purple' : ((string) ($rowCompany['code'] ?? '') === 'AGHPL' ? 'blue' : 'gray') ?>"><?= e((string) ($rowCompany['code'] ?? '')) ?></span>
                        <?php if ($row['fiscal_year_id'] <= 0): ?><span class="mbw-pill tone-amber">No matching FY</span><?php endif; ?>
                    </td>
                    <td class="is-numeric"><?= e($fmtMoney($rowData['cash'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($rowData['receivables'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($rowData['payables'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($rowData['income'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($rowData['expenses'])) ?></td>
                    <td class="is-numeric <?= $rowData['net'] < 0 ? 'amount-negative' : 'amount-positive' ?>"><?= e($fmtMoney($rowData['net'])) ?></td>
                    <td class="is-numeric"><?= e(number_format($margin, 1)) ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>Combined (simple total)</td>
                    <td class="is-numeric"><?= e($fmtMoney($matrixTotals['cash'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($matrixTotals['receivables'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($matrixTotals['payables'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($matrixTotals['income'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($matrixTotals['expenses'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($matrixTotals['net'])) ?></td>
                    <td class="is-numeric"><?= e(number_format($matrixTotals['income'] > 0 ? ($matrixTotals['net'] / $matrixTotals['income']) * 100 : 0, 1)) ?>%</td>
                </tr>
            </tfoot>
        </table>
    </div>
    <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12px">
        Simple totals add all companies without eliminating intercompany balances. The Altiora consolidation below applies ownership percentages and consolidation methods.
    </p>
</section>

<?php if ($consolidated !== null): ?>
<section class="mbw-card" aria-label="Altiora group consolidation">
    <div class="mbw-card-head">
        <h2>Altiora Group — Consolidated (IFRS 10)</h2>
        <span class="mbw-info"><?= icon('about') ?></span>
        <div class="mbw-card-tools">
            <span class="mbw-pill tone-blue"><?= e($altiora['name'] ?? 'Altiora') ?> + <?= count($investees) ?> investees</span>
        </div>
    </div>
    <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>Entity</th><th>Relationship</th><th class="is-numeric">Ownership</th><th>Method</th>
                    <th class="is-numeric">Income</th><th class="is-numeric">Expenses</th>
                    <th class="is-numeric">Profit</th><th class="is-numeric">NCI Share</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($consolidated['entities'] as $entity): ?>
                <tr>
                    <td><strong style="color:var(--mbw-heading)"><?= e($entity['company_name']) ?></strong></td>
                    <td><span class="mbw-pill tone-<?= e($relationshipTones[(string) $entity['relationship_type']] ?? 'gray') ?>"><?= e(ucwords(str_replace('_', ' ', (string) $entity['relationship_type']))) ?></span></td>
                    <td class="is-numeric"><?= e(number_format((float) $entity['ownership_percent'], 1)) ?>%</td>
                    <td><?= e($methodLabels[(string) $entity['consolidation_method']] ?? ucfirst((string) $entity['consolidation_method'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney((float) $entity['income'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney((float) $entity['expenses'])) ?></td>
                    <td class="is-numeric <?= (float) $entity['profit'] < 0 ? 'amount-negative' : 'amount-positive' ?>"><?= e($fmtMoney((float) $entity['profit'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney((float) $entity['nci_profit'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">Consolidated</td>
                    <td class="is-numeric"><?= e($fmtMoney((float) $consolidated['total_income'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney((float) $consolidated['total_expenses'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney((float) $consolidated['net_profit'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney((float) $consolidated['nci_profit'])) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>

<div class="mbw-row-tables">
    <section class="mbw-card" aria-label="Consolidated income components">
        <div class="mbw-card-head">
            <h2>Consolidated Income Components</h2>
        </div>
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Component</th><th class="is-numeric">Amount</th><th class="is-numeric">NCI Portion</th></tr></thead>
                <tbody>
                <?php if (($consolidated['income_breakdown'] ?? []) === []): ?><tr><td colspan="3" style="color:var(--mbw-muted)">No income postings in this period.</td></tr><?php endif; ?>
                <?php foreach ($consolidated['income_breakdown'] as $item): ?>
                    <tr>
                        <td><?= e($item['name']) ?></td>
                        <td class="is-numeric"><?= e($fmtMoney((float) $item['amount'])) ?></td>
                        <td class="is-numeric"><?= e($fmtMoney((float) ($item['nci'] ?? 0))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td>Total Income</td><td class="is-numeric"><?= e($fmtMoney((float) $consolidated['total_income'])) ?></td><td></td></tr></tfoot>
            </table>
        </div>
    </section>

    <section class="mbw-card" aria-label="Consolidated expense components">
        <div class="mbw-card-head">
            <h2>Consolidated Expense Components</h2>
        </div>
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Component</th><th class="is-numeric">Amount</th><th class="is-numeric">NCI Portion</th></tr></thead>
                <tbody>
                <?php if (($consolidated['expense_breakdown'] ?? []) === []): ?><tr><td colspan="3" style="color:var(--mbw-muted)">No expense postings in this period.</td></tr><?php endif; ?>
                <?php foreach ($consolidated['expense_breakdown'] as $item): ?>
                    <tr>
                        <td><?= e($item['name']) ?></td>
                        <td class="is-numeric"><?= e($fmtMoney((float) $item['amount'])) ?></td>
                        <td class="is-numeric"><?= e($fmtMoney((float) ($item['nci'] ?? 0))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td>Total Expenses</td><td class="is-numeric"><?= e($fmtMoney((float) $consolidated['total_expenses'])) ?></td><td></td></tr></tfoot>
            </table>
        </div>
    </section>
</div>
<?php else: ?>
    <div class="notice error">Altiora Global Holdings has no fiscal year matching <?= e($fiscalYear['label']) ?>, so the IFRS consolidation cannot be produced for this period.</div>
<?php endif; ?>

<script>
(function () {
    var wrap = document.querySelector('.cr-toolbar [data-rc2-export]');
    if (!wrap) { return; }
    wrap.querySelector('.rc2-export-btn').addEventListener('click', function (event) {
        event.stopPropagation();
        wrap.classList.toggle('is-open');
    });
    document.addEventListener('click', function () { wrap.classList.remove('is-open'); });
})();
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
