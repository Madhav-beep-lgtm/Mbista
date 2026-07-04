<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_or_admin();
require_company_context();

$company = current_company();
$fiscalYear = current_fiscal_year();

if (!$company || !$fiscalYear) {
    flash('error', 'Company and fiscal year context required.');
    redirect('admin/accounting.php');
}

$companyId = (int) $company['id'];
$fiscalYearId = (int) $fiscalYear['id'];
$companyCode = (string) ($company['code'] ?? '');
$isAltiora = $companyCode === 'AGHPL';
$investeeCompanies = [];

if ($isAltiora) {
    foreach (accounting_companies_for_group($companyId) as $groupCompany) {
        if ((int) $groupCompany['id'] !== $companyId) {
            $investeeCompanies[] = $groupCompany;
        }
    }
}

$currentSummary = get_financial_summary($companyId, $fiscalYearId);
$balanceSheet = get_balance_sheet_summary($companyId, $fiscalYearId);
$consolidatedSummary = $isAltiora ? get_consolidated_financial_summary($companyId, $fiscalYearId, $investeeCompanies) : null;

$maxMetric = max(
    1,
    (float) $currentSummary['total_income'],
    (float) $currentSummary['total_expenses'],
    abs((float) $currentSummary['net_profit'])
);

$pageTitle = 'Accounting Dashboard';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<style>
    .accounting-dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .accounting-kpi {
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 1.25rem;
        background: var(--surface);
    }

    .accounting-kpi span {
        display: block;
        color: var(--muted);
        font-size: .86rem;
        margin-bottom: .45rem;
    }

    .accounting-kpi strong {
        display: block;
        color: var(--heading);
        font-size: 1.6rem;
    }

    .accounting-chart {
        display: grid;
        gap: .9rem;
        margin-top: 1rem;
    }

    .accounting-bar {
        display: grid;
        grid-template-columns: 150px 1fr 130px;
        align-items: center;
        gap: .75rem;
    }

    .accounting-bar-track {
        height: 18px;
        border-radius: 999px;
        background: color-mix(in srgb, var(--text-muted) 22%, transparent);
        overflow: hidden;
    }

    .accounting-bar-fill {
        height: 100%;
        border-radius: inherit;
        background: var(--accent);
    }

    .accounting-bar-fill.income { background: var(--success); }
    .accounting-bar-fill.expense { background: var(--danger); }
    .accounting-bar-fill.profit { background: var(--secondary); }

    .accounting-standard-note {
        border: 1px solid color-mix(in srgb, var(--info) 35%, transparent);
        border-left: 4px solid var(--info);
        border-radius: var(--radius-sm);
        padding: 1rem;
        background: color-mix(in srgb, var(--info) 8%, transparent);
        margin-bottom: 1rem;
    }

    .amount-cell {
        text-align: right;
        white-space: nowrap;
    }

    .amount-positive { color: var(--success); }
    .amount-negative { color: var(--danger); }

    @media (max-width: 760px) {
        .accounting-bar {
            grid-template-columns: 1fr;
            gap: .4rem;
        }

        .amount-cell {
            text-align: left;
        }
    }
</style>

<div class="admin-hero">
    <div>
        <div class="badge"><?= icon('accounting') ?>Accounting dashboard</div>
        <h2>Financial report center</h2>
        <p><?= e($company['name']) ?> / <?= e($fiscalYear['label']) ?> (<?= e($fiscalYear['start_date']) ?> to <?= e($fiscalYear['end_date']) ?>)</p>
    </div>
    <div class="actions">
        <a class="button secondary" href="<?= e(url('admin/accounting.php')) ?>"><?= icon('accounting') ?>Accounting entries</a>
        <a class="button secondary" href="<?= e(url('admin/export-ledger.php?format=excel')) ?>"><?= icon('reports') ?>Export ledger</a>
    </div>
</div>

<div class="accounting-standard-note">
    Reports are prepared from posted vouchers for the selected fiscal year. Consolidation classification follows the configured relationship rules: full consolidation for control, equity method for associates, and non-controlling interest where ownership is below 100%.
</div>

<div class="accounting-dashboard-grid">
    <div class="accounting-kpi">
        <span>Total income</span>
        <strong class="amount-positive"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $currentSummary['total_income'], 2)) ?></strong>
    </div>
    <div class="accounting-kpi">
        <span>Total expenses</span>
        <strong class="amount-negative"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $currentSummary['total_expenses'], 2)) ?></strong>
    </div>
    <div class="accounting-kpi">
        <span>Profit / loss</span>
        <strong class="<?= (float) $currentSummary['net_profit'] >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $currentSummary['net_profit'], 2)) ?></strong>
    </div>
    <div class="accounting-kpi">
        <span>Profit margin</span>
        <strong><?= e(number_format((float) $currentSummary['profit_margin'], 2)) ?>%</strong>
    </div>
</div>

<div class="table-card">
    <h2>Graphical summary</h2>
    <div class="accounting-chart">
        <?php foreach ([
            ['label' => 'Income', 'class' => 'income', 'amount' => (float) $currentSummary['total_income']],
            ['label' => 'Expenses', 'class' => 'expense', 'amount' => (float) $currentSummary['total_expenses']],
            ['label' => 'Profit / loss', 'class' => 'profit', 'amount' => abs((float) $currentSummary['net_profit'])],
        ] as $metric): ?>
            <?php $width = max(2, min(100, ($metric['amount'] / $maxMetric) * 100)); ?>
            <div class="accounting-bar">
                <strong><?= e($metric['label']) ?></strong>
                <div class="accounting-bar-track"><div class="accounting-bar-fill <?= e($metric['class']) ?>" style="width: <?= e(number_format($width, 2, '.', '')) ?>%"></div></div>
                <span class="amount-cell"><?= e(site_currency_symbol()) ?><?= e(number_format($metric['amount'], 2)) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="table-card">
    <h2>Statement of profit or loss</h2>
    <table>
        <thead>
            <tr><th>Account</th><th class="amount-cell">Amount</th></tr>
        </thead>
        <tbody>
            <?php foreach ($currentSummary['income_breakdown'] as $row): ?>
                <tr><td><?= e($row['name']) ?></td><td class="amount-cell amount-positive"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $row['amount'], 2)) ?></td></tr>
            <?php endforeach; ?>
            <tr><th>Total income</th><th class="amount-cell amount-positive"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $currentSummary['total_income'], 2)) ?></th></tr>
            <?php foreach ($currentSummary['expense_breakdown'] as $row): ?>
                <tr><td><?= e($row['name']) ?></td><td class="amount-cell amount-negative"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $row['amount'], 2)) ?></td></tr>
            <?php endforeach; ?>
            <tr><th>Total expenses</th><th class="amount-cell amount-negative"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $currentSummary['total_expenses'], 2)) ?></th></tr>
            <tr><th>Net profit / loss</th><th class="amount-cell <?= (float) $currentSummary['net_profit'] >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $currentSummary['net_profit'], 2)) ?></th></tr>
        </tbody>
    </table>
</div>

<div class="table-card">
    <h2>Statement of financial position</h2>
    <table>
        <thead>
            <tr><th>Section</th><th>Account</th><th class="amount-cell">Amount</th></tr>
        </thead>
        <tbody>
            <?php foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'] as $key => $label): ?>
                <?php foreach ($balanceSheet[$key] as $row): ?>
                    <tr><td><?= e($label) ?></td><td><?= e($row['name']) ?></td><td class="amount-cell"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $row['amount'], 2)) ?></td></tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <tr><th colspan="2">Total assets</th><th class="amount-cell"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $balanceSheet['total_assets'], 2)) ?></th></tr>
            <tr><th colspan="2">Total liabilities + equity</th><th class="amount-cell"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $balanceSheet['total_liabilities'] + (float) $balanceSheet['total_equity'], 2)) ?></th></tr>
        </tbody>
    </table>
</div>

<?php if ($consolidatedSummary): ?>
    <div class="table-card">
        <h2>Altiora consolidated report</h2>
        <table>
            <thead>
                <tr><th>Entity</th><th>Ownership</th><th>Method</th><th class="amount-cell">Income</th><th class="amount-cell">Expenses</th><th class="amount-cell">Profit / loss</th><th class="amount-cell">NCI / equity share</th></tr>
            </thead>
            <tbody>
                <?php foreach ($consolidatedSummary['entities'] as $entity): ?>
                    <tr>
                        <td><?= e($entity['company_name']) ?></td>
                        <td><?= e(number_format((float) $entity['ownership_percent'], 2)) ?>%</td>
                        <td><?= e(str_replace('_', ' ', $entity['consolidation_method'])) ?></td>
                        <td class="amount-cell"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $entity['income'], 2)) ?></td>
                        <td class="amount-cell"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $entity['expenses'], 2)) ?></td>
                        <td class="amount-cell"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $entity['profit'], 2)) ?></td>
                        <td class="amount-cell"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $entity['nci_profit'] + (float) $entity['equity_method_share'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr><th colspan="3">Consolidated total income</th><th class="amount-cell"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $consolidatedSummary['total_income'], 2)) ?></th><th colspan="3"></th></tr>
                <tr><th colspan="3">Consolidated total expenses</th><th class="amount-cell"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $consolidatedSummary['total_expenses'], 2)) ?></th><th colspan="3"></th></tr>
                <tr><th colspan="3">Profit attributable to parent</th><th class="amount-cell"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $consolidatedSummary['profit_attributable_to_parent'], 2)) ?></th><th colspan="3"></th></tr>
                <tr><th colspan="3">Non-controlling interest</th><th class="amount-cell"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $consolidatedSummary['nci_profit'], 2)) ?></th><th colspan="3"></th></tr>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
