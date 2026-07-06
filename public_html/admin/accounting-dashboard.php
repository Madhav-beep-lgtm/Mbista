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

// ---------------------------------------------------------------------------
// KPI deltas: same balance math re-run as of the end of the previous month.
// ---------------------------------------------------------------------------
$prevMonthEnd = date('Y-m-d', strtotime(date('Y-m-01', strtotime($asOfDate)) . ' -1 day'));
$prevTotals = ['cash' => 0.0, 'receivables' => 0.0, 'payables' => 0.0, 'income' => 0.0, 'expenses' => 0.0];
$hasPrevPeriod = $prevMonthEnd >= $fromDate;
if ($hasPrevPeriod) {
    $prevStmt = db()->prepare('
        SELECT l.id, l.type, COALESCE(g.is_cash_or_bank, 0) AS is_cash_or_bank,
               COALESCE(SUM(CASE WHEN ve.entry_type = \'debit\' THEN ve.amount ELSE 0 END), 0) AS debit_total,
               COALESCE(SUM(CASE WHEN ve.entry_type = \'credit\' THEN ve.amount ELSE 0 END), 0) AS credit_total
        FROM ledgers l
        LEFT JOIN ledger_groups g ON g.id = l.group_id
        INNER JOIN voucher_entries ve ON ve.ledger_id = l.id
        INNER JOIN vouchers v ON v.id = ve.voucher_id
            AND v.company_id = l.company_id
            AND v.fiscal_year_id = :fiscal_year_id
            AND v.status = \'posted\'
            AND COALESCE(v.voucher_date, DATE(v.created_at)) <= :cutoff
        WHERE l.company_id = :company_id
        GROUP BY l.id, l.type, g.is_cash_or_bank
    ');
    $prevStmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId, 'cutoff' => $prevMonthEnd]);
    $receivableLedgerId = (int) ($receivableLedger['id'] ?? 0);
    $payableLedgerId = (int) ($payableLedger['id'] ?? 0);
    foreach ($prevStmt->fetchAll() as $row) {
        $debit = (float) $row['debit_total'];
        $credit = (float) $row['credit_total'];
        $type = (string) $row['type'];
        $natural = in_array($type, ['liability', 'equity', 'revenue'], true) ? $credit - $debit : $debit - $credit;
        if ((int) $row['is_cash_or_bank'] === 1) {
            $prevTotals['cash'] += $debit - $credit;
        }
        if ($type === 'revenue') {
            $prevTotals['income'] += $natural;
        } elseif ($type === 'expense') {
            $prevTotals['expenses'] += $natural;
        }
        if ((int) $row['id'] === $receivableLedgerId) {
            $prevTotals['receivables'] = max(0, $natural);
        } elseif ((int) $row['id'] === $payableLedgerId) {
            $prevTotals['payables'] = max(0, $natural);
        }
    }
}

$kpiDelta = static function (float $current, float $previous) use ($hasPrevPeriod): ?float {
    if (!$hasPrevPeriod || abs($previous) < 0.005) {
        return null;
    }
    return (($current - $previous) / abs($previous)) * 100;
};

// ---------------------------------------------------------------------------
// Cash flow by activity: each posted voucher's net cash movement classified by
// its dominant counterpart ledger group (indirect method approximation).
// ---------------------------------------------------------------------------
$cashFlowStmt = db()->prepare('
    SELECT v.id,
           SUM(CASE WHEN COALESCE(g.is_cash_or_bank, 0) = 1 THEN (CASE WHEN ve.entry_type = \'debit\' THEN ve.amount ELSE -ve.amount END) ELSE 0 END) AS cash_net,
           SUM(CASE WHEN COALESCE(g.is_cash_or_bank, 0) = 0 AND g.master_key = \'non_current_asset\' THEN ve.amount ELSE 0 END) AS w_investing,
           SUM(CASE WHEN COALESCE(g.is_cash_or_bank, 0) = 0 AND g.master_key = \'equity\' THEN ve.amount ELSE 0 END) AS w_financing,
           SUM(CASE WHEN COALESCE(g.is_cash_or_bank, 0) = 0 AND (g.master_key NOT IN (\'non_current_asset\', \'equity\') OR g.master_key IS NULL) THEN ve.amount ELSE 0 END) AS w_operating
    FROM vouchers v
    INNER JOIN voucher_entries ve ON ve.voucher_id = v.id
    INNER JOIN ledgers l ON l.id = ve.ledger_id
    LEFT JOIN ledger_groups g ON g.id = l.group_id
    WHERE v.company_id = :company_id AND v.fiscal_year_id = :fiscal_year_id AND v.status = \'posted\'
    GROUP BY v.id
    HAVING cash_net <> 0
');
$cashFlowStmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);
$cashFlow = ['operating' => 0.0, 'investing' => 0.0, 'financing' => 0.0];
foreach ($cashFlowStmt->fetchAll() as $row) {
    $weights = ['operating' => (float) $row['w_operating'], 'investing' => (float) $row['w_investing'], 'financing' => (float) $row['w_financing']];
    arsort($weights);
    $cashFlow[(string) array_key_first($weights)] += (float) $row['cash_net'];
}
$netCashChange = $cashFlow['operating'] + $cashFlow['investing'] + $cashFlow['financing'];
$cashFlowSegments = [
    ['label' => 'Operating Activities', 'amount' => $cashFlow['operating'], 'color' => 'green'],
    ['label' => 'Investing Activities', 'amount' => $cashFlow['investing'], 'color' => 'primary'],
    ['label' => 'Financing Activities', 'amount' => $cashFlow['financing'], 'color' => 'red'],
    ['label' => 'Net Change in Cash', 'amount' => $netCashChange, 'color' => 'amber'],
];
$cashFlowTotalAbs = array_sum(array_map(static fn (array $seg): float => abs((float) $seg['amount']), $cashFlowSegments));

// ---------------------------------------------------------------------------
// Upcoming due: invoice due dates (receivable side + purchase bills).
// ---------------------------------------------------------------------------
$dueSummary = [
    'overdue_receivable' => ['amount' => 0.0, 'count' => 0],
    'overdue_payable' => ['amount' => 0.0, 'count' => 0],
    'next7' => ['amount' => 0.0, 'count' => 0],
];
if (table_exists('task_invoices') && column_exists('task_invoices', 'due_on')) {
    $dueStmt = db()->prepare('
        SELECT
            SUM(CASE WHEN due_on < :today1 AND COALESCE(invoice_category, \'sales\') <> \'purchase\' THEN total_amount ELSE 0 END) AS overdue_recv_amount,
            SUM(CASE WHEN due_on < :today2 AND COALESCE(invoice_category, \'sales\') <> \'purchase\' THEN 1 ELSE 0 END) AS overdue_recv_count,
            SUM(CASE WHEN due_on < :today3 AND COALESCE(invoice_category, \'sales\') = \'purchase\' THEN total_amount ELSE 0 END) AS overdue_pay_amount,
            SUM(CASE WHEN due_on < :today4 AND COALESCE(invoice_category, \'sales\') = \'purchase\' THEN 1 ELSE 0 END) AS overdue_pay_count,
            SUM(CASE WHEN due_on >= :today5 AND due_on < DATE_ADD(:today6, INTERVAL 7 DAY) THEN total_amount ELSE 0 END) AS next7_amount,
            SUM(CASE WHEN due_on >= :today7 AND due_on < DATE_ADD(:today8, INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS next7_count
        FROM task_invoices
        WHERE company_id = :company_id
          AND due_on IS NOT NULL
          AND LOWER(COALESCE(status, \'\')) NOT IN (\'paid\', \'cancelled\', \'draft\')
    ');
    $dueStmt->execute([
        'company_id' => $companyId,
        'today1' => $today, 'today2' => $today, 'today3' => $today, 'today4' => $today,
        'today5' => $today, 'today6' => $today, 'today7' => $today, 'today8' => $today,
    ]);
    if ($dueRow = $dueStmt->fetch()) {
        $dueSummary['overdue_receivable'] = ['amount' => (float) $dueRow['overdue_recv_amount'], 'count' => (int) $dueRow['overdue_recv_count']];
        $dueSummary['overdue_payable'] = ['amount' => (float) $dueRow['overdue_pay_amount'], 'count' => (int) $dueRow['overdue_pay_count']];
        $dueSummary['next7'] = ['amount' => (float) $dueRow['next7_amount'], 'count' => (int) $dueRow['next7_count']];
    }
}

// ---------------------------------------------------------------------------
// Bank accounts: cash/bank ledgers with optional account metadata + last move.
// ---------------------------------------------------------------------------
$hasBankMeta = column_exists('ledgers', 'bank_account_no');
$lastMovementByLedger = [];
if ($cashLedgers !== []) {
    $movementStmt = db()->prepare('
        SELECT ve.ledger_id, MAX(COALESCE(v.voucher_date, DATE(v.created_at))) AS last_move
        FROM voucher_entries ve
        INNER JOIN vouchers v ON v.id = ve.voucher_id
        WHERE v.company_id = :company_id AND v.fiscal_year_id = :fiscal_year_id AND v.status = \'posted\'
        GROUP BY ve.ledger_id
    ');
    $movementStmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);
    foreach ($movementStmt->fetchAll() as $row) {
        $lastMovementByLedger[(int) $row['ledger_id']] = (string) $row['last_move'];
    }
}
$bankMetaById = [];
if ($hasBankMeta && $cashLedgers !== []) {
    $bankMetaStmt = db()->prepare('SELECT id, bank_name, bank_account_no FROM ledgers WHERE company_id = :company_id');
    $bankMetaStmt->execute(['company_id' => $companyId]);
    foreach ($bankMetaStmt->fetchAll() as $row) {
        $bankMetaById[(int) $row['id']] = $row;
    }
}

$fmtMoney = static fn (float $amount): string => $currency . number_format($amount, 2);

// Primary KPI row — mirrors the approved reference dashboard.
$primaryKpis = [
    ['label' => 'Total Receivables', 'amount' => $receivablesTotal, 'delta' => $kpiDelta($receivablesTotal, $prevTotals['receivables']), 'vs' => 'vs Last Month', 'tone' => 'green', 'icon' => 'clients', 'href' => url('admin/accounting-parties.php?tab=sales')],
    ['label' => 'Total Payables', 'amount' => $payablesTotal, 'delta' => $kpiDelta($payablesTotal, $prevTotals['payables']), 'vs' => 'vs Last Month', 'tone' => 'red', 'icon' => 'card', 'href' => url('admin/accounting-parties.php?tab=purchases')],
    ['label' => 'Cash & Bank Balance', 'amount' => $cashBankTotal, 'delta' => $kpiDelta($cashBankTotal, $prevTotals['cash']), 'vs' => 'vs Last Month', 'tone' => 'blue', 'icon' => 'bank', 'href' => url('admin/banking.php')],
    ['label' => 'Net Profit (YTD)', 'amount' => $netProfit, 'delta' => $kpiDelta($netProfit, $prevTotals['income'] - $prevTotals['expenses']), 'vs' => 'vs Last Month', 'tone' => 'green', 'icon' => 'trend-up', 'href' => accounting_dashboard_report_url('profit-loss', $fiscalYearId, $fromDate, $toDate, $businessType)],
    ['label' => 'Total Income (YTD)', 'amount' => $totalIncome, 'delta' => $kpiDelta($totalIncome, $prevTotals['income']), 'vs' => 'vs Last Month', 'tone' => 'purple', 'icon' => 'analytics', 'href' => accounting_dashboard_report_url('profit-loss', $fiscalYearId, $fromDate, $toDate, $businessType)],
];

// Secondary KPIs stay one click away behind the "more" toggle.
$secondaryKpis = [
    ['label' => 'Purchases (This FY)', 'amount' => $purchasesTotal, 'tone' => 'teal', 'icon' => 'cart', 'note' => 'Purchase vouchers'],
    ['label' => 'Expenses (This FY)', 'amount' => $totalExpenses, 'tone' => 'amber', 'icon' => 'wallet', 'note' => 'Posted expenses'],
    ['label' => 'Gross Profit', 'amount' => $grossProfit, 'tone' => 'green', 'icon' => 'insights', 'note' => number_format($grossMargin, 1) . '% of sales'],
];
if ($showInventoryFeatures) {
    $secondaryKpis[] = ['label' => 'Inventory Value', 'amount' => $inventoryValue, 'tone' => 'purple', 'icon' => 'layers', 'note' => count($inventoryRows) . ' inventory items'];
}

$voucherTypeTones = [
    'receipt' => 'green', 'payment' => 'amber', 'journal' => 'blue', 'contra' => 'teal',
    'sales' => 'green', 'purchase' => 'red', 'debit_note' => 'purple', 'credit_note' => 'purple',
];

// Normalized rows for the Bank Accounts widget (single-company default).
$bankRows = [];
foreach ($cashLedgers as $ledger) {
    $ledgerId = (int) $ledger['id'];
    $meta = $bankMetaById[$ledgerId] ?? [];
    $bankRows[] = [
        'name' => (string) ((($meta['bank_name'] ?? '') !== '' && $meta['bank_name'] !== null) ? $meta['bank_name'] . ' - ' . $ledger['name'] : $ledger['name']),
        'account_no' => (string) ($meta['bank_account_no'] ?? ''),
        'balance' => (float) $ledger['debit_total'] - (float) $ledger['credit_total'],
        'last_move' => $lastMovementByLedger[$ledgerId] ?? null,
        'company' => null,
    ];
}
foreach ($recentVouchers as $recentIndex => $recentVoucher) {
    $recentVouchers[$recentIndex]['company_name'] = null;
}

// ---------------------------------------------------------------------------
// Group dashboard: the M.Bista superadmin portal shows every group company.
// All headline widgets aggregate across the firm + Altiora and its group.
// ---------------------------------------------------------------------------
$isGroupDashboard = (string) ($company['code'] ?? '') === 'MBAACA';
$groupRows = [];
$groupTotals = ['cash' => 0.0, 'receivables' => 0.0, 'payables' => 0.0, 'income' => 0.0, 'expenses' => 0.0, 'net' => 0.0];
if ($isGroupDashboard) {
    $groupEntries = group_dashboard_companies($fiscalYearId);
    $pairs = [];
    $groupCompanyIds = [];
    foreach ($groupEntries as $entry) {
        $entryCompanyId = (int) $entry['company']['id'];
        $entryFiscalYearId = (int) $entry['fiscal_year_id'];
        $snapshot = $entryFiscalYearId > 0
            ? company_financials_snapshot($entryCompanyId, $entryFiscalYearId)
            : ['cash' => 0.0, 'receivables' => 0.0, 'payables' => 0.0, 'income' => 0.0, 'expenses' => 0.0, 'net' => 0.0];
        $groupRows[] = ['company' => $entry['company'], 'fiscal_year_id' => $entryFiscalYearId, 'data' => $snapshot];
        foreach ($groupTotals as $key => $value) {
            $groupTotals[$key] += $snapshot[$key];
        }
        $groupCompanyIds[] = $entryCompanyId;
        if ($entryFiscalYearId > 0) {
            $pairs[] = 'SELECT ' . $entryCompanyId . ' AS cid, ' . $entryFiscalYearId . ' AS fyid';
        }
    }

    if ($pairs !== []) {
        $pairsDerived = '(' . implode(' UNION ALL ', $pairs) . ')';

        // Group monthly income vs expense over this portal's FY calendar.
        $groupMonthlyStmt = db()->query('
            SELECT DATE_FORMAT(COALESCE(v.voucher_date, DATE(v.created_at)), \'%Y-%m\') AS month_key,
                   SUM(CASE WHEN l.type = \'revenue\' AND ve.entry_type = \'credit\' THEN ve.amount WHEN l.type = \'revenue\' THEN -ve.amount ELSE 0 END) AS income,
                   SUM(CASE WHEN l.type = \'expense\' AND ve.entry_type = \'debit\' THEN ve.amount WHEN l.type = \'expense\' THEN -ve.amount ELSE 0 END) AS expense
            FROM vouchers v
            INNER JOIN ' . $pairsDerived . ' p ON p.cid = v.company_id AND p.fyid = v.fiscal_year_id
            INNER JOIN voucher_entries ve ON ve.voucher_id = v.id
            INNER JOIN ledgers l ON l.id = ve.ledger_id
            WHERE v.status = \'posted\'
            GROUP BY month_key
            ORDER BY month_key ASC
        ');
        $monthlyLookup = [];
        foreach ($groupMonthlyStmt->fetchAll() as $row) {
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

        // Group cash flow by activity.
        $groupCashFlowStmt = db()->query('
            SELECT v.id,
                   SUM(CASE WHEN COALESCE(g.is_cash_or_bank, 0) = 1 THEN (CASE WHEN ve.entry_type = \'debit\' THEN ve.amount ELSE -ve.amount END) ELSE 0 END) AS cash_net,
                   SUM(CASE WHEN COALESCE(g.is_cash_or_bank, 0) = 0 AND g.master_key = \'non_current_asset\' THEN ve.amount ELSE 0 END) AS w_investing,
                   SUM(CASE WHEN COALESCE(g.is_cash_or_bank, 0) = 0 AND g.master_key = \'equity\' THEN ve.amount ELSE 0 END) AS w_financing,
                   SUM(CASE WHEN COALESCE(g.is_cash_or_bank, 0) = 0 AND (g.master_key NOT IN (\'non_current_asset\', \'equity\') OR g.master_key IS NULL) THEN ve.amount ELSE 0 END) AS w_operating
            FROM vouchers v
            INNER JOIN ' . $pairsDerived . ' p ON p.cid = v.company_id AND p.fyid = v.fiscal_year_id
            INNER JOIN voucher_entries ve ON ve.voucher_id = v.id
            INNER JOIN ledgers l ON l.id = ve.ledger_id
            LEFT JOIN ledger_groups g ON g.id = l.group_id
            WHERE v.status = \'posted\'
            GROUP BY v.id
            HAVING cash_net <> 0
        ');
        $cashFlow = ['operating' => 0.0, 'investing' => 0.0, 'financing' => 0.0];
        foreach ($groupCashFlowStmt->fetchAll() as $row) {
            $weights = ['operating' => (float) $row['w_operating'], 'investing' => (float) $row['w_investing'], 'financing' => (float) $row['w_financing']];
            arsort($weights);
            $cashFlow[(string) array_key_first($weights)] += (float) $row['cash_net'];
        }
        $netCashChange = $cashFlow['operating'] + $cashFlow['investing'] + $cashFlow['financing'];
        $cashFlowSegments = [
            ['label' => 'Operating Activities', 'amount' => $cashFlow['operating'], 'color' => 'green'],
            ['label' => 'Investing Activities', 'amount' => $cashFlow['investing'], 'color' => 'primary'],
            ['label' => 'Financing Activities', 'amount' => $cashFlow['financing'], 'color' => 'red'],
            ['label' => 'Net Change in Cash', 'amount' => $netCashChange, 'color' => 'amber'],
        ];
        $cashFlowTotalAbs = array_sum(array_map(static fn (array $seg): float => abs((float) $seg['amount']), $cashFlowSegments));

        // Group recent transactions with the originating company.
        $groupRecentStmt = db()->query('
            SELECT v.id, v.voucher_no, v.voucher_type, v.narration, v.total_amount,
                   COALESCE(v.voucher_date, DATE(v.created_at)) AS voucher_date,
                   ap.name AS party_name, c.name AS company_name
            FROM vouchers v
            INNER JOIN ' . $pairsDerived . ' p ON p.cid = v.company_id AND p.fyid = v.fiscal_year_id
            INNER JOIN companies c ON c.id = v.company_id
            LEFT JOIN accounting_parties ap ON ap.id = v.party_id
            WHERE v.status = \'posted\'
            ORDER BY COALESCE(v.voucher_date, DATE(v.created_at)) DESC, v.id DESC
            LIMIT 6
        ');
        $recentVouchers = $groupRecentStmt->fetchAll();

        // Group bank accounts (top balances across all companies).
        $groupBankStmt = db()->query('
            SELECT l.id, l.name, c.name AS company_name,
                   ' . ($hasBankMeta ? 'l.bank_name, l.bank_account_no,' : 'NULL AS bank_name, NULL AS bank_account_no,') . '
                   COALESCE(SUM(CASE WHEN v.id IS NOT NULL AND ve.entry_type = \'debit\' THEN ve.amount ELSE 0 END), 0) AS debit_total,
                   COALESCE(SUM(CASE WHEN v.id IS NOT NULL AND ve.entry_type = \'credit\' THEN ve.amount ELSE 0 END), 0) AS credit_total,
                   MAX(COALESCE(v.voucher_date, DATE(v.created_at))) AS last_move
            FROM ledgers l
            INNER JOIN ledger_groups g ON g.id = l.group_id AND COALESCE(g.is_cash_or_bank, 0) = 1
            INNER JOIN companies c ON c.id = l.company_id
            INNER JOIN ' . $pairsDerived . ' p2 ON p2.cid = l.company_id
            LEFT JOIN voucher_entries ve ON ve.ledger_id = l.id
            LEFT JOIN vouchers v ON v.id = ve.voucher_id AND v.status = \'posted\'
                AND v.company_id = p2.cid AND v.fiscal_year_id = p2.fyid
            GROUP BY l.id, l.name, c.name' . ($hasBankMeta ? ', l.bank_name, l.bank_account_no' : '') . '
            ORDER BY (COALESCE(SUM(CASE WHEN v.id IS NOT NULL AND ve.entry_type = \'debit\' THEN ve.amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN v.id IS NOT NULL AND ve.entry_type = \'credit\' THEN ve.amount ELSE 0 END), 0)) DESC
            LIMIT 6
        ');
        $bankRows = [];
        foreach ($groupBankStmt->fetchAll() as $row) {
            $bankRows[] = [
                'name' => (string) ((($row['bank_name'] ?? '') !== '' && $row['bank_name'] !== null) ? $row['bank_name'] . ' - ' . $row['name'] : $row['name']),
                'account_no' => (string) ($row['bank_account_no'] ?? ''),
                'balance' => (float) $row['debit_total'] - (float) $row['credit_total'],
                'last_move' => $row['last_move'] ?? null,
                'company' => (string) $row['company_name'],
            ];
        }

        // Group upcoming due across every company.
        if (table_exists('task_invoices') && column_exists('task_invoices', 'due_on') && $groupCompanyIds !== []) {
            $idList = implode(',', array_map('intval', $groupCompanyIds));
            $groupDueStmt = db()->prepare('
                SELECT
                    SUM(CASE WHEN due_on < :today1 AND COALESCE(invoice_category, \'sales\') <> \'purchase\' THEN total_amount ELSE 0 END) AS overdue_recv_amount,
                    SUM(CASE WHEN due_on < :today2 AND COALESCE(invoice_category, \'sales\') <> \'purchase\' THEN 1 ELSE 0 END) AS overdue_recv_count,
                    SUM(CASE WHEN due_on < :today3 AND COALESCE(invoice_category, \'sales\') = \'purchase\' THEN total_amount ELSE 0 END) AS overdue_pay_amount,
                    SUM(CASE WHEN due_on < :today4 AND COALESCE(invoice_category, \'sales\') = \'purchase\' THEN 1 ELSE 0 END) AS overdue_pay_count,
                    SUM(CASE WHEN due_on >= :today5 AND due_on < DATE_ADD(:today6, INTERVAL 7 DAY) THEN total_amount ELSE 0 END) AS next7_amount,
                    SUM(CASE WHEN due_on >= :today7 AND due_on < DATE_ADD(:today8, INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS next7_count
                FROM task_invoices
                WHERE company_id IN (' . $idList . ')
                  AND due_on IS NOT NULL
                  AND LOWER(COALESCE(status, \'\')) NOT IN (\'paid\', \'cancelled\', \'draft\')
            ');
            $groupDueStmt->execute([
                'today1' => $today, 'today2' => $today, 'today3' => $today, 'today4' => $today,
                'today5' => $today, 'today6' => $today, 'today7' => $today, 'today8' => $today,
            ]);
            if ($dueRow = $groupDueStmt->fetch()) {
                $dueSummary['overdue_receivable'] = ['amount' => (float) $dueRow['overdue_recv_amount'], 'count' => (int) $dueRow['overdue_recv_count']];
                $dueSummary['overdue_payable'] = ['amount' => (float) $dueRow['overdue_pay_amount'], 'count' => (int) $dueRow['overdue_pay_count']];
                $dueSummary['next7'] = ['amount' => (float) $dueRow['next7_amount'], 'count' => (int) $dueRow['next7_count']];
            }
        }
    }

    // Group-wide KPI row; drill-downs land on the consolidated report.
    $groupNote = 'Across ' . count($groupRows) . ' group companies';
    $consolidatedUrl = url('admin/consolidated-report.php');
    $primaryKpis = [
        ['label' => 'Total Receivables', 'amount' => $groupTotals['receivables'], 'delta' => null, 'vs' => $groupNote, 'tone' => 'green', 'icon' => 'clients', 'href' => $consolidatedUrl],
        ['label' => 'Total Payables', 'amount' => $groupTotals['payables'], 'delta' => null, 'vs' => $groupNote, 'tone' => 'red', 'icon' => 'card', 'href' => $consolidatedUrl],
        ['label' => 'Cash & Bank Balance', 'amount' => $groupTotals['cash'], 'delta' => null, 'vs' => $groupNote, 'tone' => 'blue', 'icon' => 'bank', 'href' => url('admin/banking.php')],
        ['label' => 'Net Profit (YTD)', 'amount' => $groupTotals['net'], 'delta' => null, 'vs' => $groupNote, 'tone' => 'green', 'icon' => 'trend-up', 'href' => $consolidatedUrl],
        ['label' => 'Total Income (YTD)', 'amount' => $groupTotals['income'], 'delta' => null, 'vs' => $groupNote, 'tone' => 'purple', 'icon' => 'analytics', 'href' => $consolidatedUrl],
    ];
}

$pageTitle = $isGroupDashboard ? 'Group Dashboard' : 'Accounting Dashboard';
$pageSubtitle = $isGroupDashboard
    ? 'Group-wide financial health across all companies'
    : 'Monitor your financial health and key accounting activities';
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
        <?php if (!$isGroupDashboard): ?>
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
        <?php endif; ?>
    </div>
</div>

<?php if ($repairErrors !== []): ?><div class="notice error">Accounting module repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div><?php endif; ?>

<section class="mbw-kpi-grid" aria-label="Financial overview">
    <?php foreach ($primaryKpis as $kpi): ?>
        <a class="mbw-kpi" href="<?= e($kpi['href']) ?>">
            <div>
                <span class="mbw-kpi-label"><?= e($kpi['label']) ?></span>
                <div class="mbw-kpi-value"><?= e($fmtMoney((float) $kpi['amount'])) ?></div>
                <?php if ($kpi['delta'] !== null): ?>
                    <span class="mbw-kpi-delta <?= (float) $kpi['delta'] >= 0 ? 'is-up' : 'is-down' ?>">
                        <?= icon((float) $kpi['delta'] >= 0 ? 'trend-up' : 'trend-down') ?>
                        <?= e(number_format(abs((float) $kpi['delta']), 1)) ?>%
                        <span class="mbw-kpi-vs"><?= e($kpi['vs']) ?></span>
                    </span>
                <?php else: ?>
                    <span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= $isGroupDashboard ? e($kpi['vs']) : 'No prior month yet' ?></span></span>
                <?php endif; ?>
            </div>
            <span class="mbw-chip tone-<?= e($kpi['tone']) ?>"><?= icon($kpi['icon']) ?></span>
        </a>
    <?php endforeach; ?>
</section>

<?php if ($isGroupDashboard): ?>
<section class="mbw-card" aria-label="Group companies snapshot">
    <div class="mbw-card-head">
        <h2>Group Companies Snapshot</h2>
        <span class="mbw-info"><?= icon('about') ?></span>
        <div class="mbw-card-tools">
            <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                <a class="mbw-view-all" href="<?= e(url('admin/consolidated-report.php')) ?>">Consolidated Report</a>
            <?php endif; ?>
        </div>
    </div>
    <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>Company</th><th>Fiscal Year</th>
                    <th class="is-numeric">Cash &amp; Bank</th><th class="is-numeric">Receivables</th>
                    <th class="is-numeric">Payables</th><th class="is-numeric">Income</th>
                    <th class="is-numeric">Expenses</th><th class="is-numeric">Net Profit</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($groupRows as $groupRow): ?>
                <?php $rowData = $groupRow['data']; $rowCompany = $groupRow['company']; ?>
                <tr>
                    <td>
                        <strong style="color:var(--mbw-heading)"><?= e($rowCompany['name']) ?></strong>
                        <span class="mbw-pill tone-<?= (string) ($rowCompany['code'] ?? '') === 'MBAACA' ? 'purple' : ((string) ($rowCompany['code'] ?? '') === 'AGHPL' ? 'blue' : 'gray') ?>"><?= e((string) ($rowCompany['code'] ?? '')) ?></span>
                    </td>
                    <td><?= $groupRow['fiscal_year_id'] > 0 ? '<span class="mbw-pill tone-green">Mapped</span>' : '<span class="mbw-pill tone-amber">No matching FY</span>' ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($rowData['cash'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($rowData['receivables'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($rowData['payables'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($rowData['income'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($rowData['expenses'])) ?></td>
                    <td class="is-numeric <?= $rowData['net'] < 0 ? 'amount-negative' : 'amount-positive' ?>"><?= e($fmtMoney($rowData['net'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">Group Total</td>
                    <td class="is-numeric"><?= e($fmtMoney($groupTotals['cash'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($groupTotals['receivables'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($groupTotals['payables'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($groupTotals['income'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($groupTotals['expenses'])) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney($groupTotals['net'])) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>
<?php else: ?>
<details class="mbw-card mbw-more-kpis">
    <summary class="mbw-view-all" style="cursor:pointer"><?= icon('more-v') ?> More financial indicators</summary>
    <div class="mbw-kpi-grid" style="margin-top:12px">
        <?php foreach ($secondaryKpis as $kpi): ?>
            <article class="mbw-kpi">
                <div>
                    <span class="mbw-kpi-label"><?= e($kpi['label']) ?></span>
                    <div class="mbw-kpi-value"><?= e($fmtMoney((float) $kpi['amount'])) ?></div>
                    <span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= e($kpi['note']) ?></span></span>
                </div>
                <span class="mbw-chip tone-<?= e($kpi['tone']) ?>"><?= icon($kpi['icon']) ?></span>
            </article>
        <?php endforeach; ?>
    </div>
</details>
<?php endif; ?>

<div class="mbw-row-charts">
    <section class="mbw-card" aria-label="Profit and loss overview">
        <div class="mbw-card-head">
            <h2>Profit &amp; Loss Overview</h2>
            <span class="mbw-info"><?= icon('about') ?></span>
            <div class="mbw-card-tools">
                <form method="get" class="mbw-inline-form">
                    <label class="sr-only" for="dashboard-fy-chart">Fiscal Year</label>
                    <select id="dashboard-fy-chart" class="mbw-select" name="fiscal_year_id" onchange="this.form.submit()">
                        <?php foreach ($fiscalYears as $year): ?>
                            <option value="<?= (int) $year['id'] ?>" <?= (int) $year['id'] === $fiscalYearId ? 'selected' : '' ?>><?= e($year['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <a class="mbw-view-all" href="<?= e(accounting_dashboard_report_url('profit-loss', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>">Report</a>
            </div>
        </div>
        <div class="mbw-chart-legend" aria-hidden="true">
            <span><i class="mbw-legend-dot" style="background:var(--mbw-green)"></i> Income</span>
            <span><i class="mbw-legend-dot" style="background:var(--mbw-red)"></i> Expense</span>
            <span><i class="mbw-legend-dot is-line" style="background:var(--mbw-primary)"></i> Net Profit</span>
        </div>
        <div class="mbw-chart-wrap">
            <canvas id="mbw-pl-chart" role="img" aria-label="Monthly income, expense and net profit chart"></canvas>
        </div>
    </section>

    <section class="mbw-card" aria-label="Cash flow summary">
        <div class="mbw-card-head">
            <h2>Cash Flow Summary</h2>
            <span class="mbw-info"><?= icon('about') ?></span>
            <div class="mbw-card-tools">
                <a class="mbw-view-all" href="<?= e(accounting_dashboard_report_url('cash-book', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>">Cash Book</a>
            </div>
        </div>
        <?php if ($cashFlowTotalAbs <= 0): ?>
            <p class="mbw-kpi-vs" style="color:var(--mbw-muted)">No cash movements posted in this fiscal year.</p>
        <?php else: ?>
            <div class="mbw-donut-row">
                <div class="mbw-donut-box">
                    <canvas id="mbw-cashflow-donut" role="img" aria-label="Cash flow by activity donut chart"></canvas>
                </div>
                <div class="mbw-legend">
                    <?php foreach ($cashFlowSegments as $segment): ?>
                        <?php $pct = $cashFlowTotalAbs > 0 ? (abs((float) $segment['amount']) / $cashFlowTotalAbs) * 100 : 0; ?>
                        <div class="mbw-legend-item">
                            <i class="mbw-legend-dot" style="background:var(--mbw-<?= e($segment['color'] === 'primary' ? 'primary' : $segment['color']) ?>)"></i>
                            <div>
                                <span class="mbw-legend-label"><?= e($segment['label']) ?></span>
                                <span class="mbw-legend-value"><?= e($fmtMoney((float) $segment['amount'])) ?></span>
                            </div>
                            <span class="mbw-legend-pct"><?= e(number_format($pct, 0)) ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <section class="mbw-card" aria-label="Upcoming due">
        <div class="mbw-card-head">
            <h2>Upcoming Due</h2>
            <span class="mbw-info"><?= icon('about') ?></span>
        </div>
        <div class="mbw-due-list">
            <a class="mbw-due" href="<?= e(url('admin/accounting-parties.php?tab=sales')) ?>">
                <span class="mbw-chip tone-red"><?= icon('card') ?></span>
                <div>
                    <div class="mbw-due-label">Overdue Receivables</div>
                    <div class="mbw-due-value"><?= e($fmtMoney($dueSummary['overdue_receivable']['amount'])) ?></div>
                    <div class="mbw-due-sub tone-red"><?= (int) $dueSummary['overdue_receivable']['count'] ?> Invoices</div>
                </div>
            </a>
            <a class="mbw-due" href="<?= e(url('admin/accounting-parties.php?tab=purchases')) ?>">
                <span class="mbw-chip tone-amber"><?= icon('receipt-voucher') ?></span>
                <div>
                    <div class="mbw-due-label">Overdue Payables</div>
                    <div class="mbw-due-value"><?= e($fmtMoney($dueSummary['overdue_payable']['amount'])) ?></div>
                    <div class="mbw-due-sub tone-amber"><?= (int) $dueSummary['overdue_payable']['count'] ?> Bills</div>
                </div>
            </a>
            <a class="mbw-due" href="<?= e(accounting_dashboard_report_url('party-wise', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>">
                <span class="mbw-chip tone-blue"><?= icon('calendar') ?></span>
                <div>
                    <div class="mbw-due-label">Due in Next 7 Days</div>
                    <div class="mbw-due-value"><?= e($fmtMoney($dueSummary['next7']['amount'])) ?></div>
                    <div class="mbw-due-sub tone-blue"><?= (int) $dueSummary['next7']['count'] ?> Invoices / Bills</div>
                </div>
            </a>
        </div>
    </section>
</div>

<section class="mbw-card" aria-label="Quick actions">
    <div class="mbw-card-head"><h2>Quick Actions</h2></div>
    <div class="mbw-qa-grid">
        <a class="mbw-qa" href="<?= e(url('admin/voucher-form.php?type=journal')) ?>">
            <span class="mbw-chip is-square tone-green"><?= icon('journal') ?></span>
            <div><strong>Journal Voucher</strong><span>Create new journal entry</span></div>
        </a>
        <a class="mbw-qa" href="<?= e(url('admin/voucher-form.php?type=receipt')) ?>">
            <span class="mbw-chip is-square tone-blue"><?= icon('receipt-voucher') ?></span>
            <div><strong>Receipt Voucher</strong><span>Record customer receipt</span></div>
        </a>
        <a class="mbw-qa" href="<?= e(url('admin/voucher-form.php?type=payment')) ?>">
            <span class="mbw-chip is-square tone-amber"><?= icon('card') ?></span>
            <div><strong>Payment Voucher</strong><span>Make vendor payment</span></div>
        </a>
        <a class="mbw-qa" href="<?= e(url('admin/reconciliation.php')) ?>">
            <span class="mbw-chip is-square tone-purple"><?= icon('reconcile') ?></span>
            <div><strong>Bank Reconciliation</strong><span>Reconcile bank statements</span></div>
        </a>
        <a class="mbw-qa" href="<?= e(url('admin/chart-of-accounts.php')) ?>">
            <span class="mbw-chip is-square tone-blue"><?= icon('tree') ?></span>
            <div><strong>Chart of Accounts</strong><span>View account structure</span></div>
        </a>
        <a class="mbw-qa" href="<?= e(url('admin/reports-center.php')) ?>">
            <span class="mbw-chip is-square tone-green"><?= icon('reports') ?></span>
            <div><strong>Financial Reports</strong><span>View reports &amp; statements</span></div>
        </a>
        <a class="mbw-qa" href="<?= e(url('admin/chart-ledgers.php')) ?>">
            <span class="mbw-chip is-square tone-teal"><?= icon('documents') ?></span>
            <div><strong>Add Ledger</strong><span>Create a posting account</span></div>
        </a>
        <a class="mbw-qa" href="<?= e(accounting_dashboard_report_url('trial-balance', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>">
            <span class="mbw-chip is-square tone-gray"><?= icon('accounting') ?></span>
            <div><strong>Trial Balance</strong><span>As of <?= e(date('d M', strtotime($asOfDate))) ?></span></div>
        </a>
        <?php if ($showInventoryFeatures): ?>
            <a class="mbw-qa" href="<?= e(url('admin/accounting-inventory.php')) ?>">
                <span class="mbw-chip is-square tone-purple"><?= icon('layers') ?></span>
                <div><strong>Stock Movement</strong><span>Record inventory transaction</span></div>
            </a>
        <?php endif; ?>
        <?php if ($showManufacturingFeatures): ?>
            <a class="mbw-qa" href="<?= e(url('admin/accounting-inventory.php')) ?>">
                <span class="mbw-chip is-square tone-amber"><?= icon('settings') ?></span>
                <div><strong>Production Order</strong><span>Create manufacturing order</span></div>
            </a>
        <?php endif; ?>
    </div>
</section>

<div class="mbw-row-tables">
    <section class="mbw-card" aria-label="Recent transactions">
        <div class="mbw-card-head">
            <h2>Recent Transactions</h2>
            <span class="mbw-info"><?= icon('about') ?></span>
            <div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('admin/accounting.php')) ?>">View All</a></div>
        </div>
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Date</th><?php if ($isGroupDashboard): ?><th>Company</th><?php endif; ?><th>Type</th><th>Voucher No.</th><th>Party / Account</th><th class="is-numeric">Amount</th><th>Status</th></tr></thead>
                <tbody>
                <?php if ($recentVouchers === []): ?><tr><td colspan="<?= $isGroupDashboard ? 7 : 6 ?>" style="color:var(--mbw-muted)">No posted vouchers in this fiscal year.</td></tr><?php endif; ?>
                <?php foreach ($recentVouchers as $voucher): ?>
                    <?php $tone = $voucherTypeTones[(string) $voucher['voucher_type']] ?? 'blue'; ?>
                    <tr>
                        <td><?= e(date('d M Y', strtotime((string) $voucher['voucher_date']))) ?></td>
                        <?php if ($isGroupDashboard): ?><td><?= e((string) ($voucher['company_name'] ?? '—')) ?></td><?php endif; ?>
                        <td><span class="mbw-pill tone-<?= e($tone) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $voucher['voucher_type']))) ?></span></td>
                        <td><a href="<?= e(url('admin/accounting.php')) ?>" style="color:var(--mbw-primary);text-decoration:none"><?= e($voucher['voucher_no']) ?></a></td>
                        <td><?= e($voucher['party_name'] ?: ($voucher['narration'] ?: '—')) ?></td>
                        <td class="is-numeric"><?= e($fmtMoney((float) $voucher['total_amount'])) ?></td>
                        <td><span class="mbw-pill tone-green">Completed</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="mbw-card" aria-label="Bank accounts">
        <div class="mbw-card-head">
            <h2>Bank Accounts</h2>
            <span class="mbw-info"><?= icon('about') ?></span>
            <div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('admin/banking.php')) ?>">View All</a></div>
        </div>
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Bank Account</th><?php if ($isGroupDashboard): ?><th>Company</th><?php endif; ?><th>Account No.</th><th class="is-numeric">Balance</th><th>Updated On</th></tr></thead>
                <tbody>
                <?php if ($bankRows === []): ?><tr><td colspan="<?= $isGroupDashboard ? 5 : 4 ?>" style="color:var(--mbw-muted)">No cash or bank ledgers configured.</td></tr><?php endif; ?>
                <?php foreach ($bankRows as $bankRow): ?>
                    <tr>
                        <td><?= e($bankRow['name']) ?></td>
                        <?php if ($isGroupDashboard): ?><td><?= e((string) ($bankRow['company'] ?? '—')) ?></td><?php endif; ?>
                        <td><?= $bankRow['account_no'] !== '' ? '**** **** ' . e(substr($bankRow['account_no'], -4)) : '<span style="color:var(--mbw-muted)">—</span>' ?></td>
                        <td class="is-numeric"><?= e($fmtMoney((float) $bankRow['balance'])) ?></td>
                        <td><?= $bankRow['last_move'] ? e(date('d M Y', strtotime((string) $bankRow['last_move']))) : '<span style="color:var(--mbw-muted)">No activity</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="margin:12px 0 0">
            <a class="button soft" href="<?= e(url('admin/reconciliation.php')) ?>"><?= icon('reconcile') ?>Reconcile Bank Accounts</a>
        </p>
    </section>
</div>

<?php if (!$isGroupDashboard): ?>
<div class="mbw-row-tables">
    <?php foreach ([['title' => 'Receivables Aging', 'totalLabel' => 'Total Outstanding', 'total' => $receivableAgingTotal, 'buckets' => $receivableAging, 'report' => 'party-wise'], ['title' => 'Payables Aging', 'totalLabel' => 'Total Payable', 'total' => $payableAgingTotal, 'buckets' => $payableAging, 'report' => 'party-wise']] as $aging): ?>
        <section class="mbw-card" aria-label="<?= e($aging['title']) ?>">
            <div class="mbw-card-head">
                <h2><?= e($aging['title']) ?></h2>
                <div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(accounting_dashboard_report_url($aging['report'], $fiscalYearId, $fromDate, $toDate, $businessType)) ?>">Details</a></div>
            </div>
            <div class="mbw-donut-row">
                <div class="ad-donut" style="<?= e(accounting_dashboard_donut_style($aging['buckets'])) ?>"><span></span></div>
                <div class="mbw-legend">
                    <div class="mbw-legend-item">
                        <div>
                            <span class="mbw-legend-label"><?= e($aging['totalLabel']) ?></span>
                            <span class="mbw-legend-value"><?= e($fmtMoney((float) $aging['total'])) ?></span>
                        </div>
                    </div>
                    <?php foreach ($aging['buckets'] as $bucket): ?>
                        <?php $percent = (float) $aging['total'] > 0 ? ((float) $bucket['amount'] / (float) $aging['total']) * 100 : 0; ?>
                        <div class="mbw-legend-item">
                            <i class="mbw-legend-dot" style="background:<?= e($bucket['color']) ?>"></i>
                            <div>
                                <span class="mbw-legend-label"><?= e($bucket['label']) ?></span>
                                <span class="mbw-legend-value"><?= e($fmtMoney((float) $bucket['amount'])) ?></span>
                            </div>
                            <span class="mbw-legend-pct"><?= e(number_format($percent, 1)) ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<div class="mbw-row-tables">
    <section class="mbw-card" aria-label="Top expense groups">
        <div class="mbw-card-head">
            <h2>Top Expense Groups</h2>
            <div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(accounting_dashboard_report_url('group-report', $fiscalYearId, $fromDate, $toDate, $businessType)) ?>">View Report</a></div>
        </div>
        <div class="ad-expense-list">
            <?php if ($expenseGroups === []): ?><p style="color:var(--mbw-muted);font-size:13px">No expense postings in this fiscal year.</p><?php endif; ?>
            <?php foreach ($expenseGroups as $expense): ?>
                <?php $percent = ((float) $expense['amount'] / $topExpenseAmount) * 100; ?>
                <div><span><b><?= e($expense['group_name']) ?></b><em><?= e($fmtMoney((float) $expense['amount'])) ?></em></span><progress max="100" value="<?= e(number_format($percent, 2, '.', '')) ?>"><?= e(number_format($percent, 1)) ?>%</progress></div>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($showInventoryFeatures): ?>
        <section class="mbw-card" aria-label="Inventory alerts">
            <div class="mbw-card-head">
                <h2>Inventory Alerts</h2>
                <div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('admin/accounting-inventory.php')) ?>">View All</a></div>
            </div>
            <div class="mbw-due-list">
                <a class="mbw-due" href="<?= e(url('admin/accounting-inventory.php')) ?>">
                    <span class="mbw-chip tone-amber"><?= icon('layers') ?></span>
                    <div><div class="mbw-due-label">Low Stock Items</div><div class="mbw-due-value"><?= (int) $inventoryAlerts['low'] ?> items</div></div>
                </a>
                <a class="mbw-due" href="<?= e(url('admin/accounting-inventory.php')) ?>">
                    <span class="mbw-chip tone-red"><?= icon('close') ?></span>
                    <div><div class="mbw-due-label">Out of Stock Items</div><div class="mbw-due-value"><?= (int) $inventoryAlerts['out'] ?> items</div></div>
                </a>
                <a class="mbw-due" href="<?= e(url('admin/accounting-inventory.php')) ?>">
                    <span class="mbw-chip tone-red"><?= icon('trend-down') ?></span>
                    <div><div class="mbw-due-label">Negative Stock Items</div><div class="mbw-due-value"><?= (int) $inventoryAlerts['negative'] ?> items</div></div>
                </a>
                <a class="mbw-due" href="<?= e(url('admin/accounting-inventory.php')) ?>">
                    <span class="mbw-chip tone-gray"><?= icon('layers') ?></span>
                    <div><div class="mbw-due-label">Inactive Items</div><div class="mbw-due-value"><?= (int) $inventoryAlerts['inactive'] ?> items</div></div>
                </a>
            </div>
        </section>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.MBWCharts) { return; }
    var fmt = function (v) { return <?= json_encode($currency) ?> + ' ' + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 }); };
    var pl = document.getElementById('mbw-pl-chart');
    if (pl) {
        MBWCharts.barLine(pl, {
            labels: <?= json_encode(array_column($monthlyData, 'label'), JSON_UNESCAPED_SLASHES) ?>,
            bars: [
                { label: 'Income', color: 'green', values: <?= json_encode(array_map(static fn (array $m): float => round((float) $m['income'], 2), $monthlyData)) ?> },
                { label: 'Expense', color: 'red', values: <?= json_encode(array_map(static fn (array $m): float => round((float) $m['expense'], 2), $monthlyData)) ?> }
            ],
            line: { label: 'Net Profit', color: 'primary', values: <?= json_encode(array_map(static fn (array $m): float => round((float) $m['net'], 2), $monthlyData)) ?> },
            format: fmt
        });
    }
    var donut = document.getElementById('mbw-cashflow-donut');
    if (donut) {
        MBWCharts.donut(donut, {
            segments: <?= json_encode(array_map(static fn (array $seg): array => ['label' => $seg['label'], 'value' => round(abs((float) $seg['amount']), 2), 'color' => $seg['color']], $cashFlowSegments)) ?>,
            thickness: 0.34
        });
    }
});
</script>


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
