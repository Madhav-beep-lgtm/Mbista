<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_or_admin();
require_company_context();
$repairErrors = accounting_module_repair_database();

function accounting_dashboard_aging(int $companyId, int $fiscalYearId, int $ledgerId, string $chargeType, string $asOfDate): array
{
    $buckets = [
        ['label' => '0 - 30 Days', 'amount' => 0.0, 'color' => '#22a861'],
        ['label' => '31 - 60 Days', 'amount' => 0.0, 'color' => '#f2b705'],
        ['label' => '61 - 90 Days', 'amount' => 0.0, 'color' => '#f57520'],
        ['label' => '90+ Days', 'amount' => 0.0, 'color' => '#e83d3d'],
    ];
    if ($ledgerId <= 0) {
        return $buckets;
    }

    $stmt = db()->prepare('
        SELECT COALESCE(v.voucher_date, DATE(v.created_at)) AS entry_date, ve.entry_type, ve.amount
        FROM voucher_entries ve
        INNER JOIN vouchers v ON v.id = ve.voucher_id
        WHERE v.company_id = :company_id
          AND v.fiscal_year_id = :fiscal_year_id
          AND v.status = \'posted\'
          AND ve.ledger_id = :ledger_id
          AND COALESCE(v.voucher_date, DATE(v.created_at)) <= :as_of_date
        ORDER BY entry_date ASC, ve.id ASC
    ');
    $stmt->execute([
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId,
        'ledger_id' => $ledgerId,
        'as_of_date' => $asOfDate,
    ]);

    $charges = [];
    $settlements = 0.0;
    foreach ($stmt->fetchAll() as $row) {
        $amount = (float) $row['amount'];
        if ((string) $row['entry_type'] === $chargeType) {
            $charges[] = ['date' => (string) $row['entry_date'], 'amount' => $amount];
        } else {
            $settlements += $amount;
        }
    }

    $asOf = new DateTimeImmutable($asOfDate);
    foreach ($charges as $charge) {
        $remaining = (float) $charge['amount'];
        if ($settlements > 0) {
            $applied = min($remaining, $settlements);
            $remaining -= $applied;
            $settlements -= $applied;
        }
        if ($remaining <= 0) {
            continue;
        }

        $entryDate = new DateTimeImmutable((string) $charge['date']);
        $days = max(0, (int) $entryDate->diff($asOf)->format('%r%a'));
        $index = $days <= 30 ? 0 : ($days <= 60 ? 1 : ($days <= 90 ? 2 : 3));
        $buckets[$index]['amount'] += $remaining;
    }

    return $buckets;
}

function accounting_dashboard_donut_style(array $buckets): string
{
    $total = array_sum(array_column($buckets, 'amount'));
    if ($total <= 0) {
        return 'background:#e8eef6';
    }

    $segments = [];
    $start = 0.0;
    foreach ($buckets as $bucket) {
        $end = $start + (((float) $bucket['amount'] / $total) * 100);
        $segments[] = $bucket['color'] . ' ' . number_format($start, 3, '.', '') . '% ' . number_format($end, 3, '.', '') . '%';
        $start = $end;
    }
    return 'background:conic-gradient(' . implode(',', $segments) . ')';
}

function accounting_dashboard_report_url(string $report, int $fiscalYearId, string $fromDate, string $toDate, string $businessType): string
{
    return url('admin/reports-center.php?' . http_build_query([
        'report' => $report,
        'fy' => $fiscalYearId,
        'from' => $fromDate,
        'to' => $toDate,
        'biz' => $businessType,
    ]));
}

$company = current_company();
$fiscalYear = current_fiscal_year();
if (!$company || !$fiscalYear) {
    flash('error', 'Company and fiscal year context required.');
    redirect('admin/accounting.php');
}

$companyId = (int) $company['id'];
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$fiscalYears = fiscal_years_for_company($companyId, false);
$requestedFiscalYearId = (int) ($_GET['fiscal_year_id'] ?? 0);
if ($requestedFiscalYearId > 0 && $requestedFiscalYearId !== (int) $fiscalYear['id']) {
    foreach ($fiscalYears as $candidateFiscalYear) {
        if ((int) $candidateFiscalYear['id'] === $requestedFiscalYearId) {
            set_context($companyId, $requestedFiscalYearId);
            $fiscalYear = $candidateFiscalYear;
            break;
        }
    }
}

$fiscalYearId = (int) $fiscalYear['id'];
$fromDate = (string) $fiscalYear['start_date'];
$toDate = (string) $fiscalYear['end_date'];
$today = date('Y-m-d');
$asOfDate = $today < $toDate ? $today : $toDate;
$allowedBusinessTypes = ['service', 'trading', 'manufacturing'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'set_business_type') {
        $businessType = (string) ($_POST['business_type'] ?? 'service');
        if (!in_array($businessType, $allowedBusinessTypes, true) || !table_exists('company_accounting_preferences')) {
            flash('error', 'Could not save the selected business type.');
            redirect('admin/accounting-dashboard.php');
        }
        db()->prepare('
            INSERT INTO company_accounting_preferences (company_id, business_type, updated_by)
            VALUES (:company_id, :business_type, :updated_by)
            ON DUPLICATE KEY UPDATE business_type = VALUES(business_type), updated_by = VALUES(updated_by)
        ')->execute(['company_id' => $companyId, 'business_type' => $businessType, 'updated_by' => $userId ?: null]);
        log_activity('company', $companyId, 'accounting_business_type_updated', 'Accounting business type changed to ' . $businessType . '.', $userId ?: null);
        flash('success', 'Business type updated.');
        redirect('admin/accounting-dashboard.php?fiscal_year_id=' . $fiscalYearId);
    }
    if ($action === 'set_excise_rate') {
        if (!table_exists('company_accounting_preferences')) {
            flash('error', 'Could not save the excise rate.');
            redirect('admin/accounting-dashboard.php?fiscal_year_id=' . $fiscalYearId);
        }
        $exciseRate = round(max(0.0, min(100.0, (float) ($_POST['default_excise_rate'] ?? 0))), 2);
        $existingBusinessType = company_accounting_business_type($companyId);
        db()->prepare('
            INSERT INTO company_accounting_preferences (company_id, business_type, default_excise_rate, updated_by)
            VALUES (:company_id, :business_type, :default_excise_rate, :updated_by)
            ON DUPLICATE KEY UPDATE business_type = VALUES(business_type), default_excise_rate = VALUES(default_excise_rate), updated_by = VALUES(updated_by)
        ')->execute([
            'company_id' => $companyId,
            'business_type' => $existingBusinessType,
            'default_excise_rate' => $exciseRate,
            'updated_by' => $userId ?: null,
        ]);
        log_activity('company', $companyId, 'accounting_excise_rate_updated', 'Default excise rate changed to ' . number_format($exciseRate, 2) . '%.', $userId ?: null);
        flash('success', 'Excise rate updated.');
        redirect('admin/accounting-dashboard.php?fiscal_year_id=' . $fiscalYearId);
    }
}

$businessType = 'service';
if (table_exists('company_accounting_preferences')) {
    $preferenceStmt = db()->prepare('SELECT business_type FROM company_accounting_preferences WHERE company_id = :company_id LIMIT 1');
    $preferenceStmt->execute(['company_id' => $companyId]);
    $savedBusinessType = (string) ($preferenceStmt->fetchColumn() ?: 'service');
    if (in_array($savedBusinessType, $allowedBusinessTypes, true)) {
        $businessType = $savedBusinessType;
    }
}
$businessProfile = accounting_business_profile($businessType);
$showInventoryFeatures = (bool) ($businessProfile['show_inventory'] ?? false);
$showManufacturingFeatures = (bool) ($businessProfile['show_manufacturing'] ?? false);

$ledgerStmt = db()->prepare('
    SELECT l.id, l.code, l.name, l.type, g.name AS group_name, g.master_key,
           COALESCE(g.is_cash_or_bank, 0) AS is_cash_or_bank,
           COALESCE(SUM(CASE WHEN v.id IS NOT NULL AND ve.entry_type = \'debit\' THEN ve.amount ELSE 0 END), 0) AS debit_total,
           COALESCE(SUM(CASE WHEN v.id IS NOT NULL AND ve.entry_type = \'credit\' THEN ve.amount ELSE 0 END), 0) AS credit_total
    FROM ledgers l
    LEFT JOIN ledger_groups g ON g.id = l.group_id
    LEFT JOIN voucher_entries ve ON ve.ledger_id = l.id
    LEFT JOIN vouchers v ON v.id = ve.voucher_id
        AND v.company_id = l.company_id
        AND v.fiscal_year_id = :fiscal_year_id
        AND v.status = \'posted\'
    WHERE l.company_id = :company_id
    GROUP BY l.id, l.code, l.name, l.type, g.name, g.master_key, g.is_cash_or_bank
    ORDER BY l.code ASC
');
$ledgerStmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);
$ledgerRows = $ledgerStmt->fetchAll();

$balancesById = [];
$cashBankTotal = 0.0;
$totalIncome = 0.0;
$totalExpenses = 0.0;
$directExpenses = 0.0;
$totalAssets = 0.0;
$totalLiabilities = 0.0;
$totalEquity = 0.0;
foreach ($ledgerRows as $ledger) {
    $debit = (float) $ledger['debit_total'];
    $credit = (float) $ledger['credit_total'];
    $type = (string) $ledger['type'];
    $naturalBalance = in_array($type, ['liability', 'equity', 'revenue'], true) ? $credit - $debit : $debit - $credit;
    $balancesById[(int) $ledger['id']] = $naturalBalance;

    if ((int) $ledger['is_cash_or_bank'] === 1) {
        $cashBankTotal += $debit - $credit;
    }
    if ($type === 'revenue') {
        $totalIncome += $naturalBalance;
    } elseif ($type === 'expense') {
        $totalExpenses += $naturalBalance;
        if ((string) $ledger['master_key'] === 'direct_expense') {
            $directExpenses += $naturalBalance;
        }
    } elseif ($type === 'asset') {
        $totalAssets += $naturalBalance;
    } elseif ($type === 'liability') {
        $totalLiabilities += $naturalBalance;
    } elseif ($type === 'equity') {
        $totalEquity += $naturalBalance;
    }
}

$receivableLedger = get_mapped_ledger($companyId, 'default_accounts_receivable');
$payableLedger = get_mapped_ledger($companyId, 'default_accounts_payable');
$receivablesTotal = max(0, (float) ($balancesById[(int) ($receivableLedger['id'] ?? 0)] ?? 0));
$payablesTotal = max(0, (float) ($balancesById[(int) ($payableLedger['id'] ?? 0)] ?? 0));

$purchaseStmt = db()->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE company_id = :company_id AND fiscal_year_id = :fiscal_year_id AND status = 'posted' AND voucher_type = 'purchase'");
$purchaseStmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);
$purchasesTotal = (float) $purchaseStmt->fetchColumn();

$inventoryRows = [];
$inventoryValue = 0.0;
$inventoryAlerts = ['low' => 0, 'out' => 0, 'negative' => 0, 'inactive' => 0];
if (table_exists('inventory_items') && table_exists('inventory_transactions')) {
    $inventoryStmt = db()->prepare('
        SELECT i.id, i.sku, i.name, i.status, i.purchase_rate, i.reorder_level,
               i.opening_qty + COALESCE(SUM(t.qty_in - t.qty_out), 0) AS on_hand
        FROM inventory_items i
        LEFT JOIN inventory_transactions t ON t.item_id = i.id AND t.transaction_date <= :as_of_date
        WHERE i.company_id = :company_id
        GROUP BY i.id, i.sku, i.name, i.status, i.purchase_rate, i.reorder_level, i.opening_qty
        ORDER BY i.name ASC
    ');
    $inventoryStmt->execute(['company_id' => $companyId, 'as_of_date' => $asOfDate]);
    $inventoryRows = $inventoryStmt->fetchAll();
    foreach ($inventoryRows as $item) {
        $onHand = (float) $item['on_hand'];
        $reorder = (float) $item['reorder_level'];
        $inventoryValue += $onHand * (float) $item['purchase_rate'];
        if ((string) $item['status'] !== 'active') {
            $inventoryAlerts['inactive']++;
        }
        if ($onHand < 0) {
            $inventoryAlerts['negative']++;
        } elseif (abs($onHand) < 0.0005) {
            $inventoryAlerts['out']++;
        } elseif ($reorder > 0 && $onHand <= $reorder) {
            $inventoryAlerts['low']++;
        }
    }
}

$grossProfit = $totalIncome - $directExpenses;
$netProfit = $totalIncome - $totalExpenses;
$grossMargin = $totalIncome > 0 ? ($grossProfit / $totalIncome) * 100 : 0.0;
$netMargin = $totalIncome > 0 ? ($netProfit / $totalIncome) * 100 : 0.0;

$recentVoucherStmt = db()->prepare('
    SELECT v.id, v.voucher_no, v.voucher_type, v.narration, v.total_amount,
           COALESCE(v.voucher_date, DATE(v.created_at)) AS voucher_date, p.name AS party_name
    FROM vouchers v
    LEFT JOIN accounting_parties p ON p.id = v.party_id
    WHERE v.company_id = :company_id AND v.fiscal_year_id = :fiscal_year_id AND v.status = \'posted\'
    ORDER BY COALESCE(v.voucher_date, DATE(v.created_at)) DESC, v.id DESC
    LIMIT 6
');
$recentVoucherStmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);
$recentVouchers = $recentVoucherStmt->fetchAll();

$cashLedgers = array_values(array_filter($ledgerRows, static fn (array $ledger): bool => (int) $ledger['is_cash_or_bank'] === 1));
usort($cashLedgers, static fn (array $a, array $b): int => ((float) $b['debit_total'] - (float) $b['credit_total']) <=> ((float) $a['debit_total'] - (float) $a['credit_total']));
$cashLedgers = array_slice($cashLedgers, 0, 5);

$receivableAging = accounting_dashboard_aging($companyId, $fiscalYearId, (int) ($receivableLedger['id'] ?? 0), 'debit', $asOfDate);
$payableAging = accounting_dashboard_aging($companyId, $fiscalYearId, (int) ($payableLedger['id'] ?? 0), 'credit', $asOfDate);
$receivableAgingTotal = array_sum(array_column($receivableAging, 'amount'));
$payableAgingTotal = array_sum(array_column($payableAging, 'amount'));

$monthlyStmt = db()->prepare('
    SELECT DATE_FORMAT(COALESCE(v.voucher_date, DATE(v.created_at)), \'%Y-%m\') AS month_key,
           SUM(CASE WHEN l.type = \'revenue\' AND ve.entry_type = \'credit\' THEN ve.amount WHEN l.type = \'revenue\' THEN -ve.amount ELSE 0 END) AS income,
           SUM(CASE WHEN l.type = \'expense\' AND ve.entry_type = \'debit\' THEN ve.amount WHEN l.type = \'expense\' THEN -ve.amount ELSE 0 END) AS expense
    FROM vouchers v
    INNER JOIN voucher_entries ve ON ve.voucher_id = v.id
    INNER JOIN ledgers l ON l.id = ve.ledger_id
    WHERE v.company_id = :company_id
      AND v.fiscal_year_id = :fiscal_year_id
      AND v.status = \'posted\'
    GROUP BY month_key
    ORDER BY month_key ASC
');
$monthlyStmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);
$monthlyLookup = [];
foreach ($monthlyStmt->fetchAll() as $row) {
    $monthlyLookup[(string) $row['month_key']] = ['income' => (float) $row['income'], 'expense' => (float) $row['expense']];
}
$monthlyData = [];
$monthCursor = new DateTimeImmutable(date('Y-m-01', strtotime($fromDate)));
$monthEnd = new DateTimeImmutable(date('Y-m-01', strtotime($toDate)));
while ($monthCursor <= $monthEnd && count($monthlyData) < 18) {
    $key = $monthCursor->format('Y-m');
    $amounts = $monthlyLookup[$key] ?? ['income' => 0.0, 'expense' => 0.0];
    $monthlyData[] = [
        'key' => $key,
        'label' => $monthCursor->format('M'),
        'income' => (float) $amounts['income'],
        'expense' => (float) $amounts['expense'],
        'net' => (float) $amounts['income'] - (float) $amounts['expense'],
    ];
    $monthCursor = $monthCursor->modify('+1 month');
}
$monthlyMax = max(1.0, ...array_map(static fn (array $row): float => max(abs($row['income']), abs($row['expense']), abs($row['net'])), $monthlyData));

$expenseGroupStmt = db()->prepare('
    SELECT COALESCE(g.name, \'Ungrouped Expenses\') AS group_name,
           SUM(CASE WHEN ve.entry_type = \'debit\' THEN ve.amount ELSE -ve.amount END) AS amount
    FROM vouchers v
    INNER JOIN voucher_entries ve ON ve.voucher_id = v.id
    INNER JOIN ledgers l ON l.id = ve.ledger_id AND l.type = \'expense\'
    LEFT JOIN ledger_groups g ON g.id = l.group_id
    WHERE v.company_id = :company_id AND v.fiscal_year_id = :fiscal_year_id AND v.status = \'posted\'
    GROUP BY g.id, g.name
    HAVING amount > 0
    ORDER BY amount DESC
    LIMIT 5
');
$expenseGroupStmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);
$expenseGroups = $expenseGroupStmt->fetchAll();
$topExpenseAmount = $expenseGroups === []
    ? 1.0
    : max(1.0, max(array_map(static fn (array $row): float => (float) $row['amount'], $expenseGroups)));

$currency = site_currency_symbol();
$kpis = [
    ['label' => 'Cash & Bank', 'amount' => $cashBankTotal, 'tone' => 'blue', 'icon' => 'companies', 'note' => 'Posted cash balances'],
    ['label' => 'Receivables', 'amount' => $receivablesTotal, 'tone' => 'green', 'icon' => 'users', 'note' => 'Accounts receivable'],
    ['label' => 'Payables', 'amount' => $payablesTotal, 'tone' => 'orange', 'icon' => 'documents', 'note' => 'Accounts payable'],
    ['label' => 'Sales (This FY)', 'amount' => $totalIncome, 'tone' => 'purple', 'icon' => 'reports', 'note' => 'Posted revenue'],
    ['label' => 'Purchases (This FY)', 'amount' => $purchasesTotal, 'tone' => 'cyan', 'icon' => 'services', 'note' => 'Purchase vouchers'],
    ['label' => 'Expenses (This FY)', 'amount' => $totalExpenses, 'tone' => 'blue', 'icon' => 'accounting', 'note' => 'Posted expenses'],
    ['label' => 'Inventory Value', 'amount' => $inventoryValue, 'tone' => 'amber', 'icon' => 'services', 'note' => count($inventoryRows) . ' inventory items'],
    ['label' => 'Gross Profit', 'amount' => $grossProfit, 'tone' => 'green', 'icon' => 'insights', 'note' => number_format($grossMargin, 1) . '% of sales'],
    ['label' => 'Net Profit', 'amount' => $netProfit, 'tone' => 'green', 'icon' => 'reports', 'note' => number_format($netMargin, 1) . '% of sales'],
];
$kpis = array_values(array_filter($kpis, static fn (array $kpi): bool => $showInventoryFeatures || $kpi['label'] !== 'Inventory Value'));

$pageTitle = 'Accounting Dashboard';
$bodyClass = 'admin-layout accounting-module-page accounting-dashboard-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="ad-head">
    <div class="ad-company-title">
        <span class="ad-company-icon"><?= icon('companies') ?></span>
        <div>
            <h2><?= e($company['name']) ?></h2>
            <p><?= e($fiscalYear['label']) ?> / As of <?= e(date('d M Y', strtotime($asOfDate))) ?></p>
        </div>
    </div>
    <div class="ad-context-controls">
        <form method="get" class="ad-fiscal-form">
            <label for="dashboard-fiscal-year">Fiscal Year</label>
            <select id="dashboard-fiscal-year" name="fiscal_year_id" onchange="this.form.submit()">
                <?php foreach ($fiscalYears as $year): ?>
                    <option value="<?= (int) $year['id'] ?>" <?= (int) $year['id'] === $fiscalYearId ? 'selected' : '' ?>>
                        <?= e($year['label']) ?> (<?= e(date('d M Y', strtotime((string) $year['start_date']))) ?> - <?= e(date('d M Y', strtotime((string) $year['end_date']))) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <form method="post" class="ad-business-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="set_business_type">
            <span>Business Type</span>
            <div role="group" aria-label="Business type">
                <?php foreach (['service' => 'Service', 'trading' => 'Trading', 'manufacturing' => 'Manufacturing'] as $value => $label): ?>
                    <button type="submit" name="business_type" value="<?= e($value) ?>" class="<?= $businessType === $value ? 'is-active' : '' ?>"><?= e($label) ?></button>
                <?php endforeach; ?>
            </div>
        </form>
        <?php if ($showManufacturingFeatures): ?>
            <form method="post" class="ad-excise-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="set_excise_rate">
                <span>Excise Duty</span>
                <div>
                    <label for="default_excise_rate">Default excise rate (%)</label>
                    <input id="default_excise_rate" type="number" name="default_excise_rate" min="0" max="100" step="0.01" value="<?= e(number_format(company_accounting_default_excise_rate($companyId), 2, '.', '')) ?>">
                    <button type="submit">Save</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($repairErrors !== []): ?><div class="notice error">Accounting module repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div><?php endif; ?>

<section class="ad-kpi-grid" aria-label="Financial overview">
    <?php foreach ($kpis as $kpi): ?>
        <article class="ad-kpi">
            <span class="ad-kpi-icon tone-<?= e($kpi['tone']) ?>"><?= icon($kpi['icon']) ?></span>
            <div>
                <small><?= e($kpi['label']) ?></small>
                <strong class="<?= (float) $kpi['amount'] < 0 ? 'is-negative' : '' ?>"><?= e($currency) ?><?= e(number_format((float) $kpi['amount'], 2)) ?></strong>
                <em><?= e($kpi['note']) ?></em>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<nav class="ad-quick-actions" aria-label="Accounting quick actions">
    <strong>Quick Actions</strong>
    <a href="<?= e(url('admin/accounting.php#post-voucher')) ?>"><?= icon('accounting') ?><span>Create Voucher</span></a>
    <a href="<?= e(url('admin/chart-of-accounts.php#create-ledger')) ?>"><?= icon('documents') ?><span>Add Ledger</span></a>
    <a href="<?= e(url('admin/chart-of-accounts.php#create-group')) ?>"><?= icon('teams') ?><span>Create Group</span></a>
    <?php if ($showInventoryFeatures): ?>
        <a href="<?= e(url('admin/accounting-inventory.php#create-item')) ?>"><?= icon('services') ?><span>Create Item</span></a>
        <a href="<?= e(url('admin/accounting-inventory.php#stock-movement')) ?>"><?= icon('insights') ?><span>Add Stock Movement</span></a>
    <?php endif; ?>
    <?php if ($showManufacturingFeatures): ?>
        <a href="<?= e(url('admin/accounting-inventory.php#manufacturing')) ?>"><?= icon('settings') ?><span>Create Manufacturing Order</span></a>
    <?php endif; ?>
    <a href="<?= e(accounting_dashboard_report_url('trial-balance', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>"><?= icon('accounting') ?><span>Trial Balance</span></a>
    <a href="<?= e(accounting_dashboard_report_url('profit-loss', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>"><?= icon('reports') ?><span>Profit & Loss</span></a>
    <a href="<?= e(accounting_dashboard_report_url('balance-sheet', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>"><?= icon('documents') ?><span>Balance Sheet</span></a>
</nav>

<div class="ad-grid ad-grid-top">
    <section class="ad-panel ad-recent-vouchers">
        <header><h3>Recent Vouchers</h3><a href="<?= e(url('admin/accounting.php')) ?>">View All</a></header>
        <div class="ad-table-wrap">
            <table>
                <thead><tr><th>Date</th><th>Voucher No.</th><th>Type</th><th>Narration</th><th class="amount-cell">Amount</th></tr></thead>
                <tbody>
                <?php if ($recentVouchers === []): ?><tr><td colspan="5" class="ad-empty">No posted vouchers in this fiscal year.</td></tr><?php endif; ?>
                <?php foreach ($recentVouchers as $voucher): ?>
                    <tr>
                        <td><?= e(date('d M Y', strtotime((string) $voucher['voucher_date']))) ?></td>
                        <td><a href="<?= e(url('admin/accounting.php')) ?>"><?= e($voucher['voucher_no']) ?></a></td>
                        <td><span class="ad-type type-<?= e($voucher['voucher_type']) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $voucher['voucher_type']))) ?></span></td>
                        <td><?= e($voucher['narration'] ?: ($voucher['party_name'] ?: '-')) ?></td>
                        <td class="amount-cell"><?= e($currency) ?><?= e(number_format((float) $voucher['total_amount'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="ad-panel ad-cash-summary">
        <header><h3>Cash & Bank Summary</h3><a href="<?= e(accounting_dashboard_report_url('cash-book', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>">View All</a></header>
        <div class="ad-table-wrap">
            <table>
                <thead><tr><th>Account</th><th>Type</th><th class="amount-cell">Balance</th></tr></thead>
                <tbody>
                <?php if ($cashLedgers === []): ?><tr><td colspan="3" class="ad-empty">No cash or bank ledgers configured.</td></tr><?php endif; ?>
                <?php foreach ($cashLedgers as $ledger): ?>
                    <tr><td><?= e($ledger['name']) ?> <small><?= e($ledger['code']) ?></small></td><td><?= e(str_contains(strtolower((string) $ledger['name']), 'cash') ? 'Cash' : 'Bank') ?></td><td class="amount-cell"><?= e($currency) ?><?= e(number_format((float) $ledger['debit_total'] - (float) $ledger['credit_total'], 2)) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th colspan="2">Total</th><th class="amount-cell"><?= e($currency) ?><?= e(number_format($cashBankTotal, 2)) ?></th></tr></tfoot>
            </table>
        </div>
    </section>

    <?php foreach ([['title' => 'Receivables Aging', 'totalLabel' => 'Total Outstanding', 'total' => $receivableAgingTotal, 'buckets' => $receivableAging, 'report' => 'party-wise'], ['title' => 'Payables Aging', 'totalLabel' => 'Total Payable', 'total' => $payableAgingTotal, 'buckets' => $payableAging, 'report' => 'party-wise']] as $aging): ?>
        <section class="ad-panel ad-aging-panel">
            <header><h3><?= e($aging['title']) ?></h3><a href="<?= e(accounting_dashboard_report_url($aging['report'], $fiscalYearId, $fromDate, $toDate, $businessType)) ?>">Details</a></header>
            <div class="ad-aging-content">
                <div class="ad-donut" style="<?= e(accounting_dashboard_donut_style($aging['buckets'])) ?>"><span></span></div>
                <div class="ad-aging-legend">
                    <small><?= e($aging['totalLabel']) ?></small>
                    <strong><?= e($currency) ?><?= e(number_format((float) $aging['total'], 2)) ?></strong>
                    <?php foreach ($aging['buckets'] as $bucket): ?>
                        <?php $percent = (float) $aging['total'] > 0 ? ((float) $bucket['amount'] / (float) $aging['total']) * 100 : 0; ?>
                        <div><i style="background:<?= e($bucket['color']) ?>"></i><span><?= e($bucket['label']) ?></span><b><?= e($currency) ?><?= e(number_format((float) $bucket['amount'], 2)) ?> (<?= e(number_format($percent, 1)) ?>%)</b></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<div class="ad-grid ad-grid-analytics">
    <section class="ad-panel ad-monthly-panel">
        <header><h3>Monthly Income vs Expense</h3><span><?= e($fiscalYear['label']) ?></span></header>
        <div class="ad-chart-legend"><span class="income">Income</span><span class="expense">Expense</span><span class="net">Net Profit</span></div>
        <div class="ad-monthly-chart">
            <svg viewBox="0 0 720 210" role="img" aria-label="Monthly income, expense and net profit chart">
                <?php for ($line = 0; $line <= 4; $line++): ?>
                    <?php $y = 18 + ($line * 36); ?>
                    <line x1="28" y1="<?= $y ?>" x2="710" y2="<?= $y ?>" class="grid-line"></line>
                <?php endfor; ?>
                <?php $pointList = []; $slot = 676 / max(1, count($monthlyData)); ?>
                <?php foreach ($monthlyData as $index => $month): ?>
                    <?php
                    $x = 34 + ($index * $slot);
                    $incomeHeight = max(0, ((float) $month['income'] / $monthlyMax) * 130);
                    $expenseHeight = max(0, ((float) $month['expense'] / $monthlyMax) * 130);
                    $netY = 148 - max(-130, min(130, ((float) $month['net'] / $monthlyMax) * 130));
                    $pointList[] = number_format($x + ($slot * 0.37), 2, '.', '') . ',' . number_format($netY, 2, '.', '');
                    ?>
                    <rect x="<?= e(number_format($x, 2, '.', '')) ?>" y="<?= e(number_format(148 - $incomeHeight, 2, '.', '')) ?>" width="<?= e(number_format(max(4, $slot * 0.25), 2, '.', '')) ?>" height="<?= e(number_format($incomeHeight, 2, '.', '')) ?>" class="bar-income"><title><?= e($month['label']) ?> income: <?= e($currency) ?><?= e(number_format((float) $month['income'], 2)) ?></title></rect>
                    <rect x="<?= e(number_format($x + ($slot * 0.29), 2, '.', '')) ?>" y="<?= e(number_format(148 - $expenseHeight, 2, '.', '')) ?>" width="<?= e(number_format(max(4, $slot * 0.25), 2, '.', '')) ?>" height="<?= e(number_format($expenseHeight, 2, '.', '')) ?>" class="bar-expense"><title><?= e($month['label']) ?> expense: <?= e($currency) ?><?= e(number_format((float) $month['expense'], 2)) ?></title></rect>
                    <text x="<?= e(number_format($x + ($slot * 0.26), 2, '.', '')) ?>" y="174" text-anchor="middle"><?= e($month['label']) ?></text>
                <?php endforeach; ?>
                <?php if (count($pointList) > 1): ?><polyline points="<?= e(implode(' ', $pointList)) ?>" class="net-line"></polyline><?php endif; ?>
            </svg>
        </div>
    </section>

    <section class="ad-panel ad-expense-panel">
        <header><h3>Top Expense Groups</h3><a href="<?= e(accounting_dashboard_report_url('group-report', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>">View Report</a></header>
        <div class="ad-expense-list">
            <?php if ($expenseGroups === []): ?><p class="ad-empty">No expense postings in this fiscal year.</p><?php endif; ?>
            <?php foreach ($expenseGroups as $expense): ?>
                <?php $percent = ((float) $expense['amount'] / $topExpenseAmount) * 100; ?>
                <div><span><b><?= e($expense['group_name']) ?></b><em><?= e($currency) ?><?= e(number_format((float) $expense['amount'], 2)) ?></em></span><progress max="100" value="<?= e(number_format($percent, 2, '.', '')) ?>"><?= e(number_format($percent, 1)) ?>%</progress></div>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($showInventoryFeatures): ?>
        <section class="ad-panel ad-alert-panel">
            <header><h3>Inventory Alerts</h3><a href="<?= e(url('admin/accounting-inventory.php')) ?>">View All</a></header>
            <div class="ad-alert-list">
                <a href="<?= e(url('admin/accounting-inventory.php')) ?>"><i class="warning"><?= icon('about') ?></i><span>Low Stock Items</span><strong><?= (int) $inventoryAlerts['low'] ?> items</strong></a>
                <a href="<?= e(url('admin/accounting-inventory.php')) ?>"><i class="danger"><?= icon('about') ?></i><span>Out of Stock Items</span><strong><?= (int) $inventoryAlerts['out'] ?> items</strong></a>
                <a href="<?= e(url('admin/accounting-inventory.php')) ?>"><i class="danger"><?= icon('about') ?></i><span>Negative Stock Items</span><strong><?= (int) $inventoryAlerts['negative'] ?> items</strong></a>
                <a href="<?= e(url('admin/accounting-inventory.php')) ?>"><i class="muted"><?= icon('services') ?></i><span>Inactive Items</span><strong><?= (int) $inventoryAlerts['inactive'] ?> items</strong></a>
            </div>
        </section>
    <?php endif; ?>
</div>

<div class="ad-bottom-grid">
    <section class="ad-panel ad-shortcuts">
        <header><h3>Shortcuts</h3></header>
        <div>
            <a href="<?= e(url('admin/chart-of-accounts.php')) ?>"><?= icon('accounting') ?><span><strong>Chart of Accounts</strong><small>Browse all accounts</small></span></a>
            <a href="<?= e(accounting_dashboard_report_url('ledger-report', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>"><?= icon('documents') ?><span><strong>Ledger Directory</strong><small>Search ledger reports</small></span></a>
            <?php if ($showInventoryFeatures): ?>
                <a href="<?= e(url('admin/accounting-inventory.php')) ?>"><?= icon('services') ?><span><strong>Item Directory</strong><small>View inventory items</small></span></a>
            <?php endif; ?>
            <a href="<?= e(url('admin/accounting.php')) ?>"><?= icon('documents') ?><span><strong>Voucher List</strong><small>All accounting vouchers</small></span></a>
            <a href="<?= e(accounting_dashboard_report_url('bank-book', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>"><?= icon('companies') ?><span><strong>Bank Book</strong><small>Review bank activity</small></span></a>
            <a href="<?= e(url('admin/reports-center.php?report=journal-register')) ?>"><?= icon('compliance') ?><span><strong>Audit Trail</strong><small>Review journal activity</small></span></a>
        </div>
    </section>
    <section class="ad-panel ad-report-links">
        <header><h3>Reports</h3><a href="<?= e(url('admin/reports-center.php')) ?>">View All</a></header>
        <div>
            <a href="<?= e(accounting_dashboard_report_url('trial-balance', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>"><?= icon('accounting') ?><span>Trial Balance</span></a>
            <a href="<?= e(accounting_dashboard_report_url('profit-loss', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>"><?= icon('reports') ?><span>Profit & Loss</span></a>
            <a href="<?= e(accounting_dashboard_report_url('balance-sheet', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>"><?= icon('documents') ?><span>Balance Sheet</span></a>
            <?php if ($showInventoryFeatures): ?>
                <a href="<?= e(accounting_dashboard_report_url('inventory-summary', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>"><?= icon('services') ?><span>Inventory Summary</span></a>
                <a href="<?= e(accounting_dashboard_report_url('stock-ledger', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>"><?= icon('tasks') ?><span>Stock Ledger</span></a>
            <?php endif; ?>
            <?php if ($showManufacturingFeatures): ?>
                <a href="<?= e(accounting_dashboard_report_url('manufacturing-statement', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>"><?= icon('settings') ?><span>Manufacturing Statement</span></a>
            <?php endif; ?>
            <a href="<?= e(accounting_dashboard_report_url('cash-book', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>"><?= icon('companies') ?><span>Cash Flow</span></a>
            <a href="<?= e(accounting_dashboard_report_url('ledger-report', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>"><?= icon('documents') ?><span>Ledger Report</span></a>
            <a href="<?= e(accounting_dashboard_report_url('financial-ratios', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>"><?= icon('insights') ?><span>More Reports</span></a>
        </div>
    </section>
</div>

<?php if ((string) ($company['code'] ?? '') === 'AGHPL'): ?>
    <?php $investeeCompanies = array_values(array_filter(accounting_companies_for_group($companyId), static fn (array $row): bool => (int) $row['id'] !== $companyId)); ?>
    <?php $consolidatedSummary = get_consolidated_financial_summary($companyId, $fiscalYearId, $investeeCompanies); ?>
    <section class="ad-panel ad-consolidation-panel">
        <header><h3>Group Consolidation Snapshot</h3><a href="<?= e(accounting_dashboard_report_url('consolidated', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>">Full Consolidated Report</a></header>
        <div>
            <span><small>Consolidated income</small><strong><?= e($currency) ?><?= e(number_format((float) $consolidatedSummary['total_income'], 2)) ?></strong></span>
            <span><small>Consolidated expenses</small><strong><?= e($currency) ?><?= e(number_format((float) $consolidatedSummary['total_expenses'], 2)) ?></strong></span>
            <span><small>Parent attributable profit</small><strong><?= e($currency) ?><?= e(number_format((float) $consolidatedSummary['profit_attributable_to_parent'], 2)) ?></strong></span>
            <span><small>Non-controlling interest</small><strong><?= e($currency) ?><?= e(number_format((float) $consolidatedSummary['nci_profit'], 2)) ?></strong></span>
        </div>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
