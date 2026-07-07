<?php
declare(strict_types=1);

// Shared report engine used by admin/reports-center.php and the schedule runner.

function rc_report_registry(): array
{
    return [
        'trial-balance' => ['Trial Balance', 'Summary of all ledger balances', 'accounting'],
        'profit-loss' => ['Profit or Loss Statement', 'Income and expense summary', 'invoices'],
        'balance-sheet' => ['Balance Sheet', 'Statement of financial position', 'documents'],
        'cash-flow' => ['Cash Flow Statement', 'Operating, investing and financing activities', 'bank'],
        'ledger-report' => ['Ledger Report', 'Account statement with running balance', 'accounting'],
        'group-report' => ['Group Report', 'Summary by ledger groups', 'teams'],
        'consolidated' => ['Consolidated Report', 'Consolidated financials', 'companies'],
        'party-wise' => ['Receivables Aging Report', 'Customer dues by age bucket', 'users'],
        'cash-book' => ['Cash & Bank Book', 'Cash and bank transactions with running balance', 'bank'],
        'daybook' => ['Daybook', 'All day transactions', 'compliance'],
        'sales-register' => ['Sales Register', 'Sales transaction details', 'invoices'],
        'collections-register' => ['Collections Register', 'Customer payments received', 'wallet'],
        'payments-register' => ['Payments Register', 'Supplier and expense payments', 'card'],
        'purchase-register' => ['Purchase Register', 'Purchase transaction details', 'services'],
        'inventory-summary' => ['Inventory Summary', 'Stock summary by item', 'services'],
        'stock-ledger' => ['Stock Ledger', 'Item-wise stock ledger', 'tasks'],
        'manufacturing-statement' => ['Manufacturing Statement', 'Manufacturing performance', 'settings'],
        'financial-ratios' => ['Financial Ratios', 'Key financial ratios analysis', 'dashboard'],
    ];
}


// ---------------------------------------------------------------------------
// Shared formatting + balance engine.
// ---------------------------------------------------------------------------
function rc_fmt(float $amount): string
{
    return abs($amount) < 0.005 ? '–' : number_format($amount, 2);
}

/**
 * Statement row with an optional emphasis style for the template renderer:
 * '' (plain), 'bold' (computed line), 'section' (section heading), 'total'.
 */
function rc_row(array $cells, string $style = ''): array
{
    return ['cells' => $cells, 'style' => $style];
}

function rc_row_cells(array $row): array
{
    return $row['cells'] ?? $row;
}

function rc_row_style(array $row): string
{
    return (string) ($row['style'] ?? '');
}

/** Immediately preceding period of the same length. */
function rc_previous_period(string $from, string $to): array
{
    $days = max(1, (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1);
    $prevTo = (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
    $prevFrom = (new DateTimeImmutable($prevTo))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    return [$prevFrom, $prevTo];
}

/** Variance helpers for the two-period statement templates. */
function rc_variance_cells(float $current, float $previous): array
{
    $variance = $current - $previous;
    $pct = abs($previous) > 0.004 ? ($variance / abs($previous)) * 100 : null;
    return [rc_fmt($variance), $pct === null ? '–' : number_format($pct, 2) . '%'];
}

/**
 * Period movements aggregated for the P&L / cash-flow templates. Returns
 * per-ledger movement rows plus bucket totals keyed by statement line.
 */
function rc_pl_figures(int $scopeCompanyId, string $from, string $to): array
{
    $balances = rc_ledger_balances($scopeCompanyId, $from, $to);
    $f = [
        'gross_sales' => 0.0, 'sales_returns' => 0.0, 'other_income' => 0.0,
        'cogs' => 0.0, 'operating_expenses' => 0.0, 'finance_cost' => 0.0,
        'income_tax' => 0.0, 'employee_cost' => 0.0, 'depreciation' => 0.0,
        'revenue_lines' => [],
    ];
    foreach ($balances as $b) {
        $nature = rc_ledger_nature($b);
        $master = (string) ($b['master_key'] ?? '');
        $txDr = (float) $b['tx_dr'];
        $txCr = (float) $b['tx_cr'];
        $name = (string) $b['name'];
        if ($nature === 'revenue') {
            if ($master === 'indirect_income') {
                $f['other_income'] += $txCr - $txDr;
            } else {
                $f['gross_sales'] += $txCr;
                $f['sales_returns'] += $txDr;
            }
            if (abs($txCr - $txDr) > 0.004) {
                $f['revenue_lines'][] = ['name' => $name, 'amount' => $txCr - $txDr];
            }
        } elseif ($nature === 'expense') {
            $movement = $txDr - $txCr;
            if (abs($movement) < 0.005) {
                continue;
            }
            $haystack = strtolower($name . ' ' . (string) ($b['group_name'] ?? ''));
            if (preg_match('/income tax|tax expense/', $haystack)) {
                $f['income_tax'] += $movement;
            } elseif (preg_match('/interest|finance (cost|charge)|bank charge/', $haystack)) {
                $f['finance_cost'] += $movement;
            } elseif ($master === 'direct_expense') {
                $f['cogs'] += $movement;
            } else {
                if (preg_match('/salar|wage|staff|employee|payroll/', $haystack)) {
                    $f['employee_cost'] += $movement;
                } elseif (preg_match('/depreciat|amortis|amortiz/', $haystack)) {
                    $f['depreciation'] += $movement;
                }
                $f['operating_expenses'] += $movement;
            }
        }
    }
    $f['net_sales'] = $f['gross_sales'] - $f['sales_returns'];
    $f['gross_profit'] = $f['net_sales'] - $f['cogs'];
    $f['operating_profit'] = $f['gross_profit'] + $f['other_income'] - $f['operating_expenses'];
    $f['pbt'] = $f['operating_profit'] - $f['finance_cost'];
    $f['pat'] = $f['pbt'] - $f['income_tax'];
    $f['total_revenue'] = $f['net_sales'] + $f['other_income'];
    return $f;
}

/**
 * Cash movements classified by activity (indirect approximation): each
 * posted voucher touching cash/bank is assigned to the activity of its
 * dominant counterpart ledger group.
 */
function rc_cash_flow_figures(int $scopeCompanyId, string $from, string $to): array
{
    $stmt = db()->prepare("
        SELECT v.id,
               SUM(CASE WHEN COALESCE(g.is_cash_or_bank, 0) = 1 THEN (CASE WHEN e.entry_type = 'debit' THEN e.amount ELSE -e.amount END) ELSE 0 END) AS cash_net,
               SUM(CASE WHEN COALESCE(g.is_cash_or_bank, 0) = 0 AND g.master_key IN ('non_current_asset') THEN e.amount ELSE 0 END) AS w_investing,
               SUM(CASE WHEN COALESCE(g.is_cash_or_bank, 0) = 0 AND g.master_key IN ('equity', 'non_current_liability') THEN e.amount ELSE 0 END) AS w_financing,
               SUM(CASE WHEN COALESCE(g.is_cash_or_bank, 0) = 0 AND (g.master_key NOT IN ('non_current_asset', 'equity', 'non_current_liability') OR g.master_key IS NULL) THEN e.amount ELSE 0 END) AS w_operating
        FROM vouchers v
        INNER JOIN voucher_entries e ON e.voucher_id = v.id
        INNER JOIN ledgers l ON l.id = e.ledger_id
        LEFT JOIN ledger_groups g ON g.id = l.group_id
        WHERE v.company_id = :company_id AND v.status = 'posted'
          AND COALESCE(v.voucher_date, DATE(v.created_at)) BETWEEN :from AND :to
        GROUP BY v.id
        HAVING cash_net <> 0
    ");
    $stmt->execute(['company_id' => $scopeCompanyId, 'from' => $from, 'to' => $to]);
    $flows = ['operating' => 0.0, 'investing' => 0.0, 'financing' => 0.0];
    foreach ($stmt->fetchAll() as $row) {
        $weights = ['operating' => (float) $row['w_operating'], 'investing' => (float) $row['w_investing'], 'financing' => (float) $row['w_financing']];
        arsort($weights);
        $flows[(string) array_key_first($weights)] += (float) $row['cash_net'];
    }
    $flows['net'] = $flows['operating'] + $flows['investing'] + $flows['financing'];

    $openStmt = db()->prepare("
        SELECT COALESCE(SUM(CASE WHEN e.entry_type = 'debit' THEN e.amount ELSE -e.amount END), 0)
        FROM voucher_entries e
        INNER JOIN vouchers v ON v.id = e.voucher_id AND v.company_id = :company_id AND v.status = 'posted'
        INNER JOIN ledgers l ON l.id = e.ledger_id
        INNER JOIN ledger_groups g ON g.id = l.group_id AND COALESCE(g.is_cash_or_bank, 0) = 1
        WHERE COALESCE(v.voucher_date, DATE(v.created_at)) < :from
    ");
    $openStmt->execute(['company_id' => $scopeCompanyId, 'from' => $from]);
    $flows['opening'] = (float) $openStmt->fetchColumn();
    $flows['closing'] = $flows['opening'] + $flows['net'];
    return $flows;
}

/**
 * Per-ledger opening/transaction/closing figures for a company and period.
 */
function rc_ledger_balances(int $scopeCompanyId, string $from, string $to, string $voucherType = '', int $groupId = 0, int $ledgerId = 0): array
{
    $voucherWhere = "company_id = :company_id AND status = 'posted'";
    $subParams = ['company_id' => $scopeCompanyId];
    if ($voucherType !== '') {
        $voucherWhere .= ' AND voucher_type = :voucher_type';
        $subParams['voucher_type'] = $voucherType;
    }

    $sql = "
        SELECT l.id, l.code, l.name, l.type, lg.name AS group_name, lg.master_key, COALESCE(lg.is_cash_or_bank, 0) AS is_cash_or_bank,
               COALESCE(b.op_dr, 0) AS op_dr, COALESCE(b.op_cr, 0) AS op_cr,
               COALESCE(b.tx_dr, 0) AS tx_dr, COALESCE(b.tx_cr, 0) AS tx_cr
        FROM ledgers l
        LEFT JOIN ledger_groups lg ON lg.id = l.group_id
        LEFT JOIN (
            SELECT e.ledger_id,
                   SUM(CASE WHEN d.vdate < :from1 AND e.entry_type = 'debit' THEN e.amount ELSE 0 END) AS op_dr,
                   SUM(CASE WHEN d.vdate < :from2 AND e.entry_type = 'credit' THEN e.amount ELSE 0 END) AS op_cr,
                   SUM(CASE WHEN d.vdate BETWEEN :from3 AND :to1 AND e.entry_type = 'debit' THEN e.amount ELSE 0 END) AS tx_dr,
                   SUM(CASE WHEN d.vdate BETWEEN :from4 AND :to2 AND e.entry_type = 'credit' THEN e.amount ELSE 0 END) AS tx_cr
            FROM voucher_entries e
            INNER JOIN (
                SELECT id, COALESCE(voucher_date, DATE(created_at)) AS vdate
                FROM vouchers
                WHERE {$voucherWhere}
            ) d ON d.id = e.voucher_id
            GROUP BY e.ledger_id
        ) b ON b.ledger_id = l.id
        WHERE l.company_id = :company_id2
    ";
    $params = $subParams + [
        'from1' => $from, 'from2' => $from, 'from3' => $from, 'from4' => $from,
        'to1' => $to, 'to2' => $to,
        'company_id2' => $scopeCompanyId,
    ];
    if ($groupId > 0) {
        $sql .= ' AND l.group_id = :group_id';
        $params['group_id'] = $groupId;
    }
    if ($ledgerId > 0) {
        $sql .= ' AND l.id = :ledger_id';
        $params['ledger_id'] = $ledgerId;
    }
    $sql .= ' ORDER BY l.code ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $opening = (float) $row['op_dr'] - (float) $row['op_cr'];
        $closing = $opening + (float) $row['tx_dr'] - (float) $row['tx_cr'];
        $row['opening_net'] = $opening;
        $row['closing_net'] = $closing;
        $row['op_side_dr'] = max(0.0, $opening);
        $row['op_side_cr'] = max(0.0, -$opening);
        $row['cl_side_dr'] = max(0.0, $closing);
        $row['cl_side_cr'] = max(0.0, -$closing);
    }
    unset($row);

    return $rows;
}

function rc_ledger_nature(array $row): string
{
    $type = (string) ($row['type'] ?? '');
    if (in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
        return $type;
    }
    return ledger_master_nature((string) ($row['master_key'] ?? '')) ?? 'asset';
}

/**
 * Voucher entry lines for one or more ledgers, with running balance.
 */
function rc_entry_lines(int $scopeCompanyId, array $ledgerIds, string $from, string $to): array
{
    if ($ledgerIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ledgerIds), '?'));
    $sql = "
        SELECT e.ledger_id, e.entry_type, e.amount, e.memo,
               v.voucher_no, v.voucher_type, v.narration, v.reference_no,
               COALESCE(v.voucher_date, DATE(v.created_at)) AS vdate,
               l.code AS ledger_code, l.name AS ledger_name
        FROM voucher_entries e
        INNER JOIN vouchers v ON v.id = e.voucher_id AND v.status = 'posted' AND v.company_id = ?
        INNER JOIN ledgers l ON l.id = e.ledger_id
        WHERE e.ledger_id IN ({$placeholders})
          AND COALESCE(v.voucher_date, DATE(v.created_at)) BETWEEN ? AND ?
        ORDER BY vdate ASC, v.id ASC
        LIMIT 500
    ";
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([$scopeCompanyId], $ledgerIds, [$from, $to]));
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Report generators. Each returns columns/rows/totals for the generic renderer.
// ---------------------------------------------------------------------------
function rc_generate(string $reportId, int $scopeCompanyId, string $from, string $to, array $ctx): array
{
    $sym = $ctx['currency'];
    switch ($reportId) {
        case 'trial-balance': {
            $balances = rc_ledger_balances($scopeCompanyId, $from, $to, $ctx['vtype'], $ctx['group_id'], $ctx['ledger_id']);
            $rows = [];
            $totals = ['op_dr' => 0.0, 'op_cr' => 0.0, 'tx_dr' => 0.0, 'tx_cr' => 0.0, 'cl_dr' => 0.0, 'cl_cr' => 0.0];
            $sn = 0;
            foreach ($balances as $b) {
                if ((float) $b['op_dr'] == 0.0 && (float) $b['op_cr'] == 0.0 && (float) $b['tx_dr'] == 0.0 && (float) $b['tx_cr'] == 0.0) {
                    continue;
                }
                $sn++;
                $rows[] = [
                    (string) $sn, $b['code'], $b['name'], $b['group_name'] ?? '–',
                    rc_fmt($b['op_side_dr']), rc_fmt($b['op_side_cr']),
                    rc_fmt((float) $b['tx_dr']), rc_fmt((float) $b['tx_cr']),
                    rc_fmt($b['cl_side_dr']), rc_fmt($b['cl_side_cr']),
                ];
                $totals['op_dr'] += $b['op_side_dr'];
                $totals['op_cr'] += $b['op_side_cr'];
                $totals['tx_dr'] += (float) $b['tx_dr'];
                $totals['tx_cr'] += (float) $b['tx_cr'];
                $totals['cl_dr'] += $b['cl_side_dr'];
                $totals['cl_cr'] += $b['cl_side_cr'];
            }
            return [
                'number' => '1',
                'title' => 'Trial Balance',
                'subtitle' => 'Summary of all ledger balances as on selected date range.',
                'columns' => [
                    ['SN', 'left', ''], ['Ledger Code', 'left', ''], ['Account Name', 'left', ''], ['Group', 'left', ''],
                    ['Dr.', 'right', 'Opening Balance'], ['Cr.', 'right', 'Opening Balance'],
                    ['Dr.', 'right', 'Period Activity'], ['Cr.', 'right', 'Period Activity'],
                    ['Dr.', 'right', 'Closing Balance'], ['Cr.', 'right', 'Closing Balance'],
                ],
                'rows' => $rows,
                'totals' => ['', '', 'Total', '', rc_fmt($totals['op_dr']), rc_fmt($totals['op_cr']), rc_fmt($totals['tx_dr']), rc_fmt($totals['tx_cr']), rc_fmt($totals['cl_dr']), rc_fmt($totals['cl_cr'])],
            ];
        }

        case 'profit-loss': {
            $orgType = in_array((string) $ctx['biz'], ['manufacturing', 'trading', 'service'], true)
                ? (string) $ctx['biz']
                : (string) ($ctx['org_default'] ?? 'service');
            [$prevFrom, $prevTo] = rc_previous_period($from, $to);
            $cur = rc_pl_figures($scopeCompanyId, $from, $to);
            $prev = rc_pl_figures($scopeCompanyId, $prevFrom, $prevTo);

            $columns = [
                ['Particulars', 'left', ''], ['Note', 'left', ''],
                ['Current Period (' . $sym . ')', 'right', ''], ['Previous Period (' . $sym . ')', 'right', ''],
                ['Variance (' . $sym . ')', 'right', ''], ['Variance (%)', 'right', ''],
            ];
            $note = 0;
            $line = static function (string $label, float $c, float $p, string $style = '', bool $numbered = true, bool $negative = false) use (&$note): array {
                if ($numbered) {
                    $note++;
                }
                $cs = $negative ? -$c : $c;
                $ps = $negative ? -$p : $p;
                [$var, $pct] = rc_variance_cells($c, $p);
                return rc_row([$label, $numbered ? (string) $note : '', rc_fmt($cs), rc_fmt($ps), $var, $pct], $style);
            };

            $rows = [];
            if ($orgType === 'service') {
                $number = '2C';
                $titleSuffix = 'Service';
                $revCur = $cur['revenue_lines'];
                $revPrevByName = [];
                foreach ($prev['revenue_lines'] as $rl) {
                    $revPrevByName[$rl['name']] = (float) $rl['amount'];
                }
                foreach ($revCur as $rl) {
                    $rows[] = $line((string) $rl['name'], (float) $rl['amount'], $revPrevByName[$rl['name']] ?? 0.0);
                }
                $rows[] = $line('Total Operating Revenue', $cur['total_revenue'], $prev['total_revenue'], 'bold', false);
                $adminCur = $cur['operating_expenses'] - $cur['employee_cost'] - $cur['depreciation'];
                $adminPrev = $prev['operating_expenses'] - $prev['employee_cost'] - $prev['depreciation'];
                $rows[] = $line('Direct Cost', $cur['cogs'], $prev['cogs']);
                $rows[] = $line('Employee Cost', $cur['employee_cost'], $prev['employee_cost']);
                $rows[] = $line('Administrative Expenses', $adminCur, $adminPrev);
                $rows[] = $line('Depreciation', $cur['depreciation'], $prev['depreciation']);
                $opCur = $cur['total_revenue'] - $cur['cogs'] - $cur['operating_expenses'];
                $opPrev = $prev['total_revenue'] - $prev['cogs'] - $prev['operating_expenses'];
                $rows[] = $line('Operating Profit', $opCur, $opPrev, 'bold', false);
                $rows[] = $line('Finance Cost', $cur['finance_cost'], $prev['finance_cost']);
                $rows[] = $line('Profit Before Tax', $opCur - $cur['finance_cost'], $opPrev - $prev['finance_cost'], 'bold', false);
                $rows[] = $line('Income Tax Expense', $cur['income_tax'], $prev['income_tax']);
                $rows[] = $line('Profit After Tax', $opCur - $cur['finance_cost'] - $cur['income_tax'], $opPrev - $prev['finance_cost'] - $prev['income_tax'], 'total', false);
            } else {
                $number = $orgType === 'manufacturing' ? '2A' : '2B';
                $titleSuffix = ucfirst($orgType);
                $rows[] = $line('Gross Sales', $cur['gross_sales'], $prev['gross_sales']);
                $rows[] = $line('Less: Sales Returns', $cur['sales_returns'], $prev['sales_returns'], '', true, true);
                $rows[] = $line('Net Sales', $cur['net_sales'], $prev['net_sales'], 'bold', false);
                $rows[] = $line('Cost of Goods Sold', $cur['cogs'], $prev['cogs']);
                $rows[] = $line('Gross Profit', $cur['gross_profit'], $prev['gross_profit'], 'bold', false);
                if (abs($cur['other_income']) > 0.004 || abs($prev['other_income']) > 0.004) {
                    $rows[] = $line('Other Operating Income', $cur['other_income'], $prev['other_income']);
                }
                $rows[] = $line('Operating Expenses', $cur['operating_expenses'], $prev['operating_expenses']);
                $rows[] = $line('Operating Profit', $cur['operating_profit'], $prev['operating_profit'], 'bold', false);
                $rows[] = $line('Finance Cost', $cur['finance_cost'], $prev['finance_cost']);
                $rows[] = $line('Profit Before Tax', $cur['pbt'], $prev['pbt'], 'bold', false);
                $rows[] = $line('Income Tax Expense', $cur['income_tax'], $prev['income_tax']);
                $rows[] = $line('Profit After Tax', $cur['pat'], $prev['pat'], 'total', false);
            }

            return [
                'number' => $number,
                'title' => 'Profit or Loss Statement (' . $titleSuffix . ')',
                'org_label' => $titleSuffix . ' Organization',
                'subtitle' => 'Profit or Loss Statement — ' . $titleSuffix . ' Organization, with previous-period comparison.',
                'columns' => $columns,
                'rows' => $rows,
                'totals' => null,
            ];
        }

        case 'balance-sheet': {
            $balances = rc_ledger_balances($scopeCompanyId, $from, $to, '', 0, 0);
            $buckets = [
                'nca' => ['cur' => 0.0, 'prev' => 0.0], 'ca' => ['cur' => 0.0, 'prev' => 0.0],
                'equity' => ['cur' => 0.0, 'prev' => 0.0], 'ncl' => ['cur' => 0.0, 'prev' => 0.0],
                'cl' => ['cur' => 0.0, 'prev' => 0.0], 'profit' => ['cur' => 0.0, 'prev' => 0.0],
            ];
            foreach ($balances as $b) {
                $nature = rc_ledger_nature($b);
                $master = (string) ($b['master_key'] ?? '');
                $cur = (float) $b['closing_net'];
                $prev = (float) $b['opening_net'];
                if ($nature === 'asset') {
                    $key = $master === 'non_current_asset' ? 'nca' : 'ca';
                    $buckets[$key]['cur'] += $cur;
                    $buckets[$key]['prev'] += $prev;
                } elseif ($nature === 'liability') {
                    $key = $master === 'non_current_liability' ? 'ncl' : 'cl';
                    $buckets[$key]['cur'] += -$cur;
                    $buckets[$key]['prev'] += -$prev;
                } elseif ($nature === 'equity') {
                    $buckets['equity']['cur'] += -$cur;
                    $buckets['equity']['prev'] += -$prev;
                } elseif ($nature === 'revenue') {
                    $buckets['profit']['cur'] += -$cur;
                    $buckets['profit']['prev'] += -$prev;
                } elseif ($nature === 'expense') {
                    $buckets['profit']['cur'] -= $cur;
                    $buckets['profit']['prev'] -= $prev;
                }
            }
            $equityCur = $buckets['equity']['cur'] + $buckets['profit']['cur'];
            $equityPrev = $buckets['equity']['prev'] + $buckets['profit']['prev'];
            $totalAssetsCur = $buckets['nca']['cur'] + $buckets['ca']['cur'];
            $totalAssetsPrev = $buckets['nca']['prev'] + $buckets['ca']['prev'];
            $totalEqLiabCur = $equityCur + $buckets['ncl']['cur'] + $buckets['cl']['cur'];
            $totalEqLiabPrev = $equityPrev + $buckets['ncl']['prev'] + $buckets['cl']['prev'];

            $asAtCur = date('d M Y', strtotime($to));
            $asAtPrev = date('d M Y', strtotime($from . ' -1 day'));
            $note = 0;
            $bsLine = static function (string $label, float $c, float $p, string $style = '', bool $numbered = true) use (&$note): array {
                if ($numbered) {
                    $note++;
                }
                return rc_row([$label, $numbered ? (string) $note : '', rc_fmt($c), rc_fmt($p), rc_fmt($c - $p)], $style);
            };
            $rows = [
                rc_row(['ASSETS', '', '', '', ''], 'section'),
                $bsLine('Non-Current Assets', $buckets['nca']['cur'], $buckets['nca']['prev']),
                $bsLine('Current Assets', $buckets['ca']['cur'], $buckets['ca']['prev']),
                $bsLine('Total Assets', $totalAssetsCur, $totalAssetsPrev, 'bold', false),
                rc_row(['EQUITY AND LIABILITIES', '', '', '', ''], 'section'),
                $bsLine('Equity (incl. profit for the period)', $equityCur, $equityPrev),
                $bsLine('Non-Current Liabilities', $buckets['ncl']['cur'], $buckets['ncl']['prev']),
                $bsLine('Current Liabilities', $buckets['cl']['cur'], $buckets['cl']['prev']),
                $bsLine('Total Equity and Liabilities', $totalEqLiabCur, $totalEqLiabPrev, 'total', false),
            ];
            return [
                'number' => '3',
                'title' => 'Statement of Financial Position',
                'as_at' => true,
                'subtitle' => 'Statement of financial position as at ' . $asAtCur . '.',
                'columns' => [
                    ['Particulars', 'left', ''], ['Note', 'left', ''],
                    ['As at ' . $asAtCur . ' (' . $sym . ')', 'right', ''],
                    ['As at ' . $asAtPrev . ' (' . $sym . ')', 'right', ''],
                    ['Change (' . $sym . ')', 'right', ''],
                ],
                'rows' => $rows,
                'totals' => null,
            ];
        }

        case 'cash-flow': {
            [$prevFrom, $prevTo] = rc_previous_period($from, $to);
            $cur = rc_cash_flow_figures($scopeCompanyId, $from, $to);
            $prev = rc_cash_flow_figures($scopeCompanyId, $prevFrom, $prevTo);
            $rows = [
                rc_row(['Cash Flow from Operating Activities', '1', rc_fmt($cur['operating']), rc_fmt($prev['operating'])]),
                rc_row(['Cash Flow from Investing Activities', '2', rc_fmt($cur['investing']), rc_fmt($prev['investing'])]),
                rc_row(['Cash Flow from Financing Activities', '3', rc_fmt($cur['financing']), rc_fmt($prev['financing'])]),
                rc_row(['Net Increase / (Decrease) in Cash', '', rc_fmt($cur['net']), rc_fmt($prev['net'])], 'bold'),
                rc_row(['Opening Cash and Bank Balance', '', rc_fmt($cur['opening']), rc_fmt($prev['opening'])], 'bold'),
                rc_row(['Closing Cash and Bank Balance', '', rc_fmt($cur['closing']), rc_fmt($prev['closing'])], 'total'),
            ];
            return [
                'number' => '4',
                'title' => 'Cash Flow Statement',
                'subtitle' => 'Cash flows by activity with previous-period comparison (indirect classification).',
                'columns' => [
                    ['Particulars', 'left', ''], ['Note', 'left', ''],
                    ['Current Period (' . $sym . ')', 'right', ''], ['Previous Period (' . $sym . ')', 'right', ''],
                ],
                'rows' => $rows,
                'totals' => null,
            ];
        }

        case 'ledger-report': {
            // Account statement for one ledger (defaults to the cash ledger).
            $ledgerId = (int) $ctx['ledger_id'];
            $allBalances = rc_ledger_balances($scopeCompanyId, $from, $to);
            $target = null;
            foreach ($allBalances as $b) {
                if ($ledgerId > 0 && (int) $b['id'] === $ledgerId) {
                    $target = $b;
                    break;
                }
                if ($ledgerId <= 0 && $target === null && (int) $b['is_cash_or_bank'] === 1) {
                    $target = $b;
                }
            }
            $target = $target ?? ($allBalances[0] ?? null);
            if ($target === null) {
                return ['number' => '5', 'title' => 'Ledger Report', 'subtitle' => 'No ledgers exist for this company.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }

            $running = (float) $target['opening_net'];
            $drCr = static fn (float $bal): string => $bal >= 0 ? 'Dr' : 'Cr';
            $rows = [
                rc_row([date('d M Y', strtotime($from)), 'Opening', '–', 'Opening Balance', '–', rc_fmt(max(0, $running)), rc_fmt(max(0, -$running)), number_format(abs($running), 2), $drCr($running)], 'bold'),
            ];
            $totalDr = 0.0;
            $totalCr = 0.0;
            foreach (rc_entry_lines($scopeCompanyId, [(int) $target['id']], $from, $to) as $lineRow) {
                $amount = (float) $lineRow['amount'];
                $isDebit = (string) $lineRow['entry_type'] === 'debit';
                $running += $isDebit ? $amount : -$amount;
                $totalDr += $isDebit ? $amount : 0;
                $totalCr += $isDebit ? 0 : $amount;
                $rows[] = [
                    date('d M Y', strtotime((string) $lineRow['vdate'])),
                    ucwords(str_replace('_', ' ', (string) $lineRow['voucher_type'])),
                    (string) $lineRow['voucher_no'],
                    (string) ($lineRow['memo'] ?: ($lineRow['narration'] ?: '–')),
                    (string) ($lineRow['reference_no'] ?: '–'),
                    $isDebit ? rc_fmt($amount) : '–',
                    $isDebit ? '–' : rc_fmt($amount),
                    number_format(abs($running), 2),
                    $drCr($running),
                ];
            }
            return [
                'number' => '5',
                'title' => 'Ledger Report',
                'entity_line' => 'Account: ' . $target['name'] . ' (' . $target['code'] . ')',
                'subtitle' => 'Account statement with running balance for ' . $target['name'] . '.',
                'columns' => [
                    ['Date', 'left', ''], ['Voucher Type', 'left', ''], ['Voucher No.', 'left', ''],
                    ['Particulars', 'left', ''], ['Reference', 'left', ''],
                    ['Debit (' . $sym . ')', 'right', ''], ['Credit (' . $sym . ')', 'right', ''],
                    ['Balance (' . $sym . ')', 'right', ''], ['Dr/Cr', 'left', ''],
                ],
                'rows' => $rows,
                'totals' => ['', '', '', 'Total', '', rc_fmt($totalDr), rc_fmt($totalCr), number_format(abs($running), 2), $drCr($running)],
            ];
        }

        case 'group-report': {
            $balances = rc_ledger_balances($scopeCompanyId, $from, $to, $ctx['vtype'], 0, 0);
            $groups = [];
            foreach ($balances as $b) {
                $groupName = (string) ($b['group_name'] ?? 'Ungrouped');
                $groups[$groupName] ??= ['dr' => 0.0, 'cr' => 0.0, 'cl' => 0.0, 'master' => (string) ($b['master_key'] ?? '')];
                $groups[$groupName]['dr'] += (float) $b['tx_dr'];
                $groups[$groupName]['cr'] += (float) $b['tx_cr'];
                $groups[$groupName]['cl'] += (float) $b['closing_net'];
            }
            ksort($groups);
            $rows = [];
            foreach ($groups as $name => $g) {
                if ($g['dr'] == 0.0 && $g['cr'] == 0.0 && abs($g['cl']) < 0.005) {
                    continue;
                }
                $rows[] = [$name, ledger_masters()[$g['master']]['label'] ?? '-', rc_fmt($g['dr']), rc_fmt($g['cr']), rc_fmt(abs($g['cl'])) . ($g['cl'] >= 0 ? ' Dr' : ' Cr')];
            }
            return [
                'subtitle' => 'Summary by ledger groups.',
                'columns' => [['Group', 'left', ''], ['Master', 'left', ''], ['Period Dr.', 'right', ''], ['Period Cr.', 'right', ''], ['Closing', 'right', '']],
                'rows' => $rows,
                'totals' => null,
            ];
        }

        case 'consolidated': {
            $companies = array_merge([['id' => $ctx['company_id'], 'name' => $ctx['company_name']]], $ctx['subsidiaries']);
            $matrix = [];
            foreach ($companies as $entity) {
                foreach (rc_ledger_balances((int) $entity['id'], $from, $to) as $b) {
                    $key = $b['code'] . '|' . $b['name'];
                    $matrix[$key][(int) $entity['id']] = (float) $b['closing_net'];
                }
            }
            ksort($matrix);
            $rows = [];
            foreach ($matrix as $key => $byCompany) {
                [$code, $name] = explode('|', $key, 2);
                $row = [$code, $name];
                $sum = 0.0;
                foreach ($companies as $entity) {
                    $value = $byCompany[(int) $entity['id']] ?? 0.0;
                    $sum += $value;
                    $row[] = rc_fmt($value);
                }
                if (abs($sum) < 0.005) {
                    continue;
                }
                $row[] = rc_fmt($sum);
                $rows[] = $row;
            }
            $columns = [['Code', 'left', ''], ['Account', 'left', '']];
            foreach ($companies as $entity) {
                $columns[] = [$entity['name'], 'right', ''];
            }
            $columns[] = ['Consolidated', 'right', ''];
            return [
                'subtitle' => count($companies) > 1 ? 'Closing balances across the group (net Dr/Cr).' : 'No subsidiaries found — showing the current company only.',
                'columns' => $columns,
                'rows' => $rows,
                'totals' => null,
            ];
        }


        case 'party-wise': {
            if (!table_exists('accounting_parties')) {
                return ['subtitle' => 'Party master not available yet.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            $stmt = db()->prepare('
                SELECT ap.code, ap.name, ap.party_type,
                       COALESCE((SELECT SUM(ti.total_amount) FROM task_invoices ti WHERE ti.party_id = ap.id AND ti.status <> "cancelled" AND COALESCE(ti.issued_on, DATE(ti.created_at)) BETWEEN :f1 AND :t1), 0) AS sales_total,
                       COALESCE((SELECT SUM(pr.payment_amount) FROM invoice_payment_requests pr INNER JOIN task_invoices ti2 ON ti2.id = pr.invoice_id WHERE ti2.party_id = ap.id AND pr.status IN ("paid", "partial") AND COALESCE(pr.payment_received_on, DATE(pr.requested_on)) BETWEEN :f2 AND :t2), 0) AS received_total,
                       COALESCE((SELECT SUM(v.total_amount) FROM vouchers v WHERE v.party_id = ap.id AND v.voucher_type = "purchase" AND v.status = "posted" AND COALESCE(v.voucher_date, DATE(v.created_at)) BETWEEN :f3 AND :t3), 0) AS purchase_total,
                       COALESCE((SELECT SUM(v2.total_amount) FROM vouchers v2 WHERE v2.party_id = ap.id AND v2.voucher_type = "payment" AND v2.status = "posted" AND COALESCE(v2.voucher_date, DATE(v2.created_at)) BETWEEN :f4 AND :t4), 0) AS paid_total
                FROM accounting_parties ap
                WHERE ap.company_id = :company_id
                ORDER BY ap.name ASC
            ');
            $stmt->execute(['f1' => $from, 't1' => $to, 'f2' => $from, 't2' => $to, 'f3' => $from, 't3' => $to, 'f4' => $from, 't4' => $to, 'company_id' => $scopeCompanyId]);
            $parties = $stmt->fetchAll();

            // Open sales invoices per party, bucketed by days overdue as at the report date.
            $agingByParty = [];
            if (table_exists('task_invoices') && column_exists('task_invoices', 'due_on')) {
                $invStmt = db()->prepare("
                    SELECT ti.party_id, ti.total_amount, ti.due_on,
                           COALESCE(ti.issued_on, DATE(ti.created_at)) AS issued_on
                    FROM task_invoices ti
                    WHERE ti.company_id = :company_id
                      AND COALESCE(ti.invoice_category, 'sales') <> 'purchase'
                      AND LOWER(COALESCE(ti.status, '')) NOT IN ('paid', 'cancelled', 'draft')
                      AND COALESCE(ti.issued_on, DATE(ti.created_at)) <= :to
                ");
                $invStmt->execute(['company_id' => $scopeCompanyId, 'to' => $to]);
                foreach ($invStmt->fetchAll() as $inv) {
                    $partyId = (int) ($inv['party_id'] ?? 0);
                    $amount = (float) $inv['total_amount'];
                    $due = (string) ($inv['due_on'] ?? '');
                    $days = $due !== '' ? (int) floor((strtotime($to) - strtotime($due)) / 86400) : 0;
                    $bucket = $days <= 0 ? 'current' : ($days <= 30 ? 'b30' : ($days <= 60 ? 'b60' : ($days <= 90 ? 'b90' : 'b90plus')));
                    if (!isset($agingByParty[$partyId])) {
                        $agingByParty[$partyId] = ['current' => 0.0, 'b30' => 0.0, 'b60' => 0.0, 'b90' => 0.0, 'b90plus' => 0.0];
                    }
                    $agingByParty[$partyId][$bucket] += $amount;
                }
            }

            $idsByCode = [];
            $idStmt = db()->prepare('SELECT id, code FROM accounting_parties WHERE company_id = :company_id');
            $idStmt->execute(['company_id' => $scopeCompanyId]);
            foreach ($idStmt->fetchAll() as $idRow) {
                $idsByCode[(string) $idRow['code']] = (int) $idRow['id'];
            }

            $rows = [];
            $totals = ['out' => 0.0, 'current' => 0.0, 'b30' => 0.0, 'b60' => 0.0, 'b90' => 0.0, 'b90plus' => 0.0];
            $creditLimits = [];
            $limitStmt = db()->prepare('SELECT id, credit_limit FROM accounting_parties WHERE company_id = :company_id');
            $limitStmt->execute(['company_id' => $scopeCompanyId]);
            foreach ($limitStmt->fetchAll() as $limitRow) {
                $creditLimits[(int) $limitRow['id']] = (float) ($limitRow['credit_limit'] ?? 0);
            }
            foreach ($parties as $p) {
                if (!in_array((string) $p['party_type'], ['customer', 'both'], true)) {
                    continue;
                }
                $partyId = $idsByCode[(string) $p['code']] ?? 0;
                $aging = $agingByParty[$partyId] ?? ['current' => 0.0, 'b30' => 0.0, 'b60' => 0.0, 'b90' => 0.0, 'b90plus' => 0.0];
                $outstanding = array_sum($aging);
                $ledgerDue = max(0.0, (float) $p['sales_total'] - (float) $p['received_total']);
                if ($outstanding < 0.005 && $ledgerDue >= 0.005) {
                    // No due-dated invoices: treat the open balance as current.
                    $aging['current'] = $ledgerDue;
                    $outstanding = $ledgerDue;
                }
                if ($outstanding < 0.005) {
                    continue;
                }
                $overdue = $outstanding - $aging['current'];
                $limit = $creditLimits[$partyId] ?? 0.0;
                $rows[] = [
                    (string) $p['code'], (string) $p['name'],
                    rc_fmt($outstanding), rc_fmt($aging['current']),
                    rc_fmt($aging['b30']), rc_fmt($aging['b60']), rc_fmt($aging['b90']), rc_fmt($aging['b90plus']),
                    $limit > 0 ? rc_fmt($limit) : '–',
                    $outstanding > 0.004 ? number_format(($overdue / $outstanding) * 100, 2) . '%' : '–',
                ];
                $totals['out'] += $outstanding;
                $totals['current'] += $aging['current'];
                $totals['b30'] += $aging['b30'];
                $totals['b60'] += $aging['b60'];
                $totals['b90'] += $aging['b90'];
                $totals['b90plus'] += $aging['b90plus'];
            }
            $totalOverdue = $totals['out'] - $totals['current'];
            return [
                'number' => '6',
                'title' => 'Receivables Aging Report',
                'as_at' => true,
                'subtitle' => 'Customer dues bucketed by days overdue as at ' . date('d M Y', strtotime($to)) . '.',
                'columns' => [
                    ['Code', 'left', ''], ['Customer', 'left', ''],
                    ['Total Outstanding', 'right', ''], ['Current', 'right', ''],
                    ['1 - 30 Days', 'right', ''], ['31 - 60 Days', 'right', ''],
                    ['61 - 90 Days', 'right', ''], ['Above 90 Days', 'right', ''],
                    ['Credit Limit', 'right', ''], ['Overdue %', 'right', ''],
                ],
                'rows' => $rows,
                'totals' => ['', 'Total', rc_fmt($totals['out']), rc_fmt($totals['current']), rc_fmt($totals['b30']), rc_fmt($totals['b60']), rc_fmt($totals['b90']), rc_fmt($totals['b90plus']), '', $totals['out'] > 0.004 ? number_format(($totalOverdue / $totals['out']) * 100, 2) . '%' : '–'],
            ];
        }

        case 'collections-register': {
            if (!table_exists('invoice_payment_requests') || !table_exists('task_invoices')) {
                return ['title' => 'Collections Register', 'subtitle' => 'Invoice payment module not available.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            $stmt = db()->prepare("
                SELECT COALESCE(pr.payment_received_on, DATE(pr.requested_on)) AS received_on,
                       ti.invoice_no, COALESCE(ap.name, 'Direct') AS party_name,
                       pr.payment_method, pr.payment_amount, pr.status
                FROM invoice_payment_requests pr
                INNER JOIN task_invoices ti ON ti.id = pr.invoice_id AND ti.company_id = :company_id
                LEFT JOIN accounting_parties ap ON ap.id = ti.party_id
                WHERE pr.status IN ('paid', 'partial')
                  AND COALESCE(pr.payment_received_on, DATE(pr.requested_on)) BETWEEN :from AND :to
                ORDER BY received_on ASC
                LIMIT 500
            ");
            $stmt->execute(['company_id' => $scopeCompanyId, 'from' => $from, 'to' => $to]);
            $rows = [];
            $total = 0.0;
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [date('d M Y', strtotime((string) $r['received_on'])), $r['invoice_no'], $r['party_name'], ucfirst((string) ($r['payment_method'] ?: '-')), rc_fmt((float) $r['payment_amount']), (string) $r['status'] === 'partial' ? 'Partially Paid' : ucfirst((string) $r['status'])];
                $total += (float) $r['payment_amount'];
            }
            return [
                'title' => 'Collections Register',
                'subtitle' => 'Customer payments received during the period.',
                'columns' => [['Received On', 'left', ''], ['Invoice', 'left', ''], ['Party', 'left', ''], ['Method', 'left', ''], ['Amount (' . $sym . ')', 'right', ''], ['Status', 'left', '']],
                'rows' => $rows,
                'totals' => ['', '', '', 'Total', rc_fmt($total), ''],
            ];
        }

        case 'payments-register': {
            $stmt = db()->prepare("
                SELECT COALESCE(v.voucher_date, DATE(v.created_at)) AS paid_on,
                       v.voucher_no, COALESCE(ap.name, 'Direct entry') AS party_name,
                       v.reference_no, v.total_amount, v.narration
                FROM vouchers v
                LEFT JOIN accounting_parties ap ON ap.id = v.party_id
                WHERE v.company_id = :company_id AND v.status = 'posted' AND v.voucher_type = 'payment'
                  AND COALESCE(v.voucher_date, DATE(v.created_at)) BETWEEN :from AND :to
                ORDER BY paid_on ASC
                LIMIT 500
            ");
            $stmt->execute(['company_id' => $scopeCompanyId, 'from' => $from, 'to' => $to]);
            $rows = [];
            $total = 0.0;
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [date('d M Y', strtotime((string) $r['paid_on'])), $r['voucher_no'], $r['party_name'], (string) ($r['reference_no'] ?: '-'), rc_fmt((float) $r['total_amount']), (string) ($r['narration'] ?: '-')];
                $total += (float) $r['total_amount'];
            }
            return [
                'title' => 'Payments Register',
                'subtitle' => 'Supplier and expense payments during the period.',
                'columns' => [['Paid On', 'left', ''], ['Voucher No.', 'left', ''], ['Party', 'left', ''], ['Reference', 'left', ''], ['Amount (' . $sym . ')', 'right', ''], ['Narration', 'left', '']],
                'rows' => $rows,
                'totals' => ['', '', '', 'Total', rc_fmt($total), ''],
            ];
        }

        case 'cash-book':
        case 'bank-book': {
            $sql = "SELECT l.id FROM ledgers l LEFT JOIN ledger_groups lg ON lg.id = l.group_id WHERE l.company_id = ? AND COALESCE(lg.is_cash_or_bank, 0) = 1";
            $stmt = db()->prepare($sql);
            $stmt->execute([$scopeCompanyId]);
            $ledgerIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $lines = rc_entry_lines($scopeCompanyId, $ledgerIds, $from, $to);
            $running = 0.0;
            $rows = [];
            foreach ($lines as $line) {
                $dr = $line['entry_type'] === 'debit' ? (float) $line['amount'] : 0.0;
                $cr = $line['entry_type'] === 'credit' ? (float) $line['amount'] : 0.0;
                $running += $dr - $cr;
                $rows[] = [date('d M Y', strtotime((string) $line['vdate'])), $line['voucher_no'], $line['ledger_code'], $line['memo'] ?: ($line['narration'] ?? ''), rc_fmt($dr), rc_fmt($cr), rc_fmt(abs($running)) . ($running >= 0 ? ' Dr' : ' Cr')];
            }
            return [
                'subtitle' => 'Cash and bank account transactions with running balance.',
                'columns' => [['Date', 'left', ''], ['Voucher', 'left', ''], ['Account', 'left', ''], ['Particulars', 'left', ''], ['Receipts (Dr.)', 'right', ''], ['Payments (Cr.)', 'right', ''], ['Balance', 'right', '']],
                'rows' => $rows,
                'totals' => null,
            ];
        }

        case 'daybook': {
            $sql = "
                SELECT v.voucher_no, v.voucher_type, v.narration, v.total_amount, v.reference_no,
                       COALESCE(v.voucher_date, DATE(v.created_at)) AS vdate,
                       (SELECT COUNT(*) FROM voucher_entries e WHERE e.voucher_id = v.id) AS line_count
                FROM vouchers v
                WHERE v.company_id = :company_id AND v.status = 'posted'
                  AND COALESCE(v.voucher_date, DATE(v.created_at)) BETWEEN :f AND :t
            ";
            $params = ['company_id' => $scopeCompanyId, 'f' => $from, 't' => $to];
            if ($ctx['vtype'] !== '') {
                $sql .= ' AND v.voucher_type = :vtype';
                $params['vtype'] = $ctx['vtype'];
            }
            $sql .= ' ORDER BY vdate ASC, v.id ASC LIMIT 500';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $rows = [];
            $total = 0.0;
            foreach ($stmt->fetchAll() as $v) {
                $rows[] = [date('d M Y', strtotime((string) $v['vdate'])), $v['voucher_no'], ucfirst((string) $v['voucher_type']), $v['reference_no'] ?? '-', $v['narration'] ?? '', (string) (int) $v['line_count'], rc_fmt((float) $v['total_amount'])];
                $total += (float) $v['total_amount'];
            }
            return [
                'subtitle' => 'Every posted voucher in the period, in order.',
                'columns' => [['Date', 'left', ''], ['Voucher', 'left', ''], ['Type', 'left', ''], ['Reference', 'left', ''], ['Narration', 'left', ''], ['Lines', 'right', ''], ['Amount (' . $sym . ')', 'right', '']],
                'rows' => $rows,
                'totals' => ['', '', '', '', 'Total', '', rc_fmt($total)],
            ];
        }


        case 'sales-register': {
            if (!table_exists('task_invoices')) {
                return ['subtitle' => 'Invoice module not available.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            $hasExciseColumns = column_exists('task_invoices', 'excise_rate') && column_exists('task_invoices', 'excise_amount');
            $exciseSelect = $hasExciseColumns
                ? 'COALESCE(ti.excise_rate, 0) AS excise_rate, COALESCE(ti.excise_amount, 0) AS excise_amount,'
                : '0 AS excise_rate, 0 AS excise_amount,';
            $sql = '
                SELECT ti.invoice_no, ti.invoice_category, ti.invoice_source_type, ti.status, ti.total_amount, ti.vat_amount, ti.taxable_amount,
                       ' . $exciseSelect . '
                       COALESCE(ti.issued_on, DATE(ti.created_at)) AS idate,
                       COALESCE(ap.name, cp.organization_name, "Direct") AS party_name
                FROM task_invoices ti
                LEFT JOIN accounting_parties ap ON ap.id = ti.party_id
                LEFT JOIN client_tasks ct ON ct.id = ti.task_id
                LEFT JOIN client_profiles cp ON cp.id = ct.client_id
                WHERE ti.company_id = :company_id AND ti.status <> "cancelled"
                  AND COALESCE(ti.issued_on, DATE(ti.created_at)) BETWEEN :f AND :t
            ';
            $params = ['company_id' => $scopeCompanyId, 'f' => $from, 't' => $to];
            $bizMap = ['service' => 'task', 'trading' => 'inventory', 'manufacturing' => 'manufacturing'];
            if (isset($bizMap[$ctx['biz']])) {
                $sql .= ' AND ti.invoice_source_type = :src';
                $params['src'] = $bizMap[$ctx['biz']];
            }
            $sql .= ' ORDER BY idate ASC, ti.id ASC LIMIT 500';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $salesRows = $stmt->fetchAll();
            $showExcise = $hasExciseColumns && (($ctx['biz'] ?? '') === 'manufacturing');
            $rows = [];
            $totals = ['taxable' => 0.0, 'excise' => 0.0, 'vat' => 0.0, 'total' => 0.0];
            foreach ($salesRows as $candidateRow) {
                if ($hasExciseColumns && ((string) ($candidateRow['invoice_source_type'] ?? 'task') === 'manufacturing' || (float) ($candidateRow['excise_amount'] ?? 0) > 0)) {
                    $showExcise = true;
                    break;
                }
            }
            foreach ($salesRows as $inv) {
                $row = [date('d M Y', strtotime((string) $inv['idate'])), $inv['invoice_no'], $inv['party_name'], ucfirst((string) $inv['invoice_category']), ucfirst((string) ($inv['invoice_source_type'] ?? 'task')), rc_fmt((float) $inv['taxable_amount'])];
                if ($showExcise) {
                    $row[] = rc_fmt((float) $inv['excise_amount']);
                }
                $row[] = rc_fmt((float) $inv['vat_amount']);
                $row[] = rc_fmt((float) $inv['total_amount']);
                $rows[] = $row;
                $totals['taxable'] += (float) $inv['taxable_amount'];
                $totals['excise'] += (float) $inv['excise_amount'];
                $totals['vat'] += (float) $inv['vat_amount'];
                $totals['total'] += (float) $inv['total_amount'];
            }
            return [
                'subtitle' => 'Sales transaction details' . ($ctx['biz'] !== 'all' ? ' — ' . ucfirst($ctx['biz']) . ' only' : '') . '.',
                'columns' => $showExcise
                    ? [['Date', 'left', ''], ['Invoice No.', 'left', ''], ['Party', 'left', ''], ['Category', 'left', ''], ['Source', 'left', ''], ['Taxable', 'right', ''], ['Excise', 'right', ''], ['VAT', 'right', ''], ['Total (' . $sym . ')', 'right', '']]
                    : [['Date', 'left', ''], ['Invoice No.', 'left', ''], ['Party', 'left', ''], ['Category', 'left', ''], ['Source', 'left', ''], ['Taxable', 'right', ''], ['VAT', 'right', ''], ['Total (' . $sym . ')', 'right', '']],
                'rows' => $rows,
                'totals' => $showExcise
                    ? ['', '', '', '', 'Total', rc_fmt($totals['taxable']), rc_fmt($totals['excise']), rc_fmt($totals['vat']), rc_fmt($totals['total'])]
                    : ['', '', '', '', 'Total', rc_fmt($totals['taxable']), rc_fmt($totals['vat']), rc_fmt($totals['total'])],
            ];
        }

        case 'purchase-register': {
            $stmt = db()->prepare('
                SELECT v.voucher_no, v.reference_no, v.narration, v.total_amount,
                       COALESCE(v.voucher_date, DATE(v.created_at)) AS vdate,
                       COALESCE(ap.name, "Direct entry") AS party_name
                FROM vouchers v
                LEFT JOIN accounting_parties ap ON ap.id = v.party_id
                WHERE v.company_id = :company_id AND v.status = "posted" AND v.voucher_type = "purchase"
                  AND COALESCE(v.voucher_date, DATE(v.created_at)) BETWEEN :f AND :t
                ORDER BY vdate ASC, v.id ASC LIMIT 500
            ');
            $stmt->execute(['company_id' => $scopeCompanyId, 'f' => $from, 't' => $to]);
            $rows = [];
            $total = 0.0;
            foreach ($stmt->fetchAll() as $v) {
                $rows[] = [date('d M Y', strtotime((string) $v['vdate'])), $v['voucher_no'], $v['party_name'], $v['reference_no'] ?? '-', $v['narration'] ?? '', rc_fmt((float) $v['total_amount'])];
                $total += (float) $v['total_amount'];
            }
            return [
                'subtitle' => 'Purchase transaction details.',
                'columns' => [['Date', 'left', ''], ['Voucher', 'left', ''], ['Supplier', 'left', ''], ['Bill Ref.', 'left', ''], ['Narration', 'left', ''], ['Amount (' . $sym . ')', 'right', '']],
                'rows' => $rows,
                'totals' => ['', '', '', '', 'Total', rc_fmt($total)],
            ];
        }

        case 'inventory-summary': {
            if (!table_exists('inventory_items')) {
                return ['subtitle' => 'Inventory module not available.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            $stmt = db()->prepare("
                SELECT i.sku, i.name, i.unit, i.item_type, i.opening_qty, i.purchase_rate,
                       COALESCE(SUM(CASE WHEN t.transaction_date BETWEEN :f1 AND :t1 AND COALESCE(t.transaction_type, '') NOT LIKE '%adjust%' THEN t.qty_in ELSE 0 END), 0) AS qty_in,
                       COALESCE(SUM(CASE WHEN t.transaction_date BETWEEN :f2 AND :t2 AND COALESCE(t.transaction_type, '') NOT LIKE '%adjust%' THEN t.qty_out ELSE 0 END), 0) AS qty_out,
                       COALESCE(SUM(CASE WHEN t.transaction_date BETWEEN :f3 AND :t3 AND COALESCE(t.transaction_type, '') LIKE '%adjust%' THEN t.qty_in - t.qty_out ELSE 0 END), 0) AS qty_adjust,
                       COALESCE(SUM(CASE WHEN t.transaction_date <= :t4 THEN t.qty_in - t.qty_out ELSE 0 END), 0) AS net_to_date
                FROM inventory_items i
                LEFT JOIN inventory_transactions t ON t.item_id = i.id
                WHERE i.company_id = :company_id
                GROUP BY i.id
                ORDER BY i.sku ASC
            ");
            $stmt->execute(['f1' => $from, 't1' => $to, 'f2' => $from, 't2' => $to, 'f3' => $from, 't3' => $to, 't4' => $to, 'company_id' => $scopeCompanyId]);
            $rows = [];
            $totals = ['opening' => 0.0, 'in' => 0.0, 'out' => 0.0, 'adjust' => 0.0, 'closing' => 0.0, 'value' => 0.0];
            foreach ($stmt->fetchAll() as $item) {
                $closing = (float) $item['opening_qty'] + (float) $item['net_to_date'];
                $rate = (float) $item['purchase_rate'];
                $value = $closing * $rate;
                $rows[] = [
                    (string) $item['sku'], (string) $item['name'],
                    ucfirst(str_replace('_', ' ', (string) $item['item_type'])), (string) $item['unit'],
                    number_format((float) $item['opening_qty'], 0), number_format((float) $item['qty_in'], 0),
                    number_format((float) $item['qty_out'], 0),
                    (float) $item['qty_adjust'] == 0.0 ? '–' : '(' . number_format(abs((float) $item['qty_adjust']), 0) . ')',
                    number_format($closing, 0), rc_fmt($rate), rc_fmt($value),
                ];
                $totals['opening'] += (float) $item['opening_qty'];
                $totals['in'] += (float) $item['qty_in'];
                $totals['out'] += (float) $item['qty_out'];
                $totals['adjust'] += (float) $item['qty_adjust'];
                $totals['closing'] += $closing;
                $totals['value'] += $value;
            }
            return [
                'number' => '7',
                'title' => 'Inventory Summary Report',
                'as_at' => true,
                'subtitle' => 'Stock summary by item as at ' . date('d M Y', strtotime($to)) . '.',
                'columns' => [
                    ['Item Code', 'left', ''], ['Item Name', 'left', ''], ['Category', 'left', ''], ['Unit', 'left', ''],
                    ['Opening Qty', 'right', ''], ['In Qty', 'right', ''], ['Out Qty', 'right', ''], ['Adjust Qty', 'right', ''],
                    ['Closing Qty', 'right', ''], ['Rate (' . $sym . ')', 'right', ''], ['Value (' . $sym . ')', 'right', ''],
                ],
                'rows' => $rows,
                'totals' => ['', 'Total', '', '', number_format($totals['opening'], 0), number_format($totals['in'], 0), number_format($totals['out'], 0), $totals['adjust'] == 0.0 ? '–' : '(' . number_format(abs($totals['adjust']), 0) . ')', number_format($totals['closing'], 0), '', rc_fmt($totals['value'])],
            ];
        }

        case 'stock-ledger': {
            if (!table_exists('inventory_transactions')) {
                return ['subtitle' => 'Inventory module not available.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            $itemId = $ctx['item_id'];
            if ($itemId <= 0) {
                $first = db()->prepare('SELECT id FROM inventory_items WHERE company_id = ? ORDER BY sku ASC LIMIT 1');
                $first->execute([$scopeCompanyId]);
                $itemId = (int) $first->fetchColumn();
            }
            $stmt = db()->prepare('
                SELECT t.transaction_date, t.transaction_type, t.ref_no, t.qty_in, t.qty_out, t.rate, t.amount, i.sku, i.name, i.unit, i.opening_qty
                FROM inventory_transactions t
                INNER JOIN inventory_items i ON i.id = t.item_id
                WHERE t.company_id = :company_id AND t.item_id = :item_id AND t.transaction_date BETWEEN :f AND :t
                ORDER BY t.transaction_date ASC, t.id ASC LIMIT 500
            ');
            $stmt->execute(['company_id' => $scopeCompanyId, 'item_id' => $itemId, 'f' => $from, 't' => $to]);
            $lines = $stmt->fetchAll();
            $running = (float) ($lines[0]['opening_qty'] ?? 0);
            $rows = [];
            foreach ($lines as $line) {
                $running += (float) $line['qty_in'] - (float) $line['qty_out'];
                $rows[] = [date('d M Y', strtotime((string) $line['transaction_date'])), ucfirst(str_replace('_', ' ', (string) $line['transaction_type'])), $line['ref_no'] ?? '-', number_format((float) $line['qty_in'], 2), number_format((float) $line['qty_out'], 2), rc_fmt((float) $line['rate']), rc_fmt((float) $line['amount']), number_format($running, 2)];
            }
            $itemLabel = $lines[0]['sku'] ?? '';
            return [
                'subtitle' => 'Item-wise stock movement' . ($itemLabel !== '' ? ' (' . $itemLabel . ' - ' . $lines[0]['name'] . ')' : '') . '. Pick an item in the filter bar.',
                'columns' => [['Date', 'left', ''], ['Type', 'left', ''], ['Ref.', 'left', ''], ['Qty In', 'right', ''], ['Qty Out', 'right', ''], ['Rate', 'right', ''], ['Amount', 'right', ''], ['Balance Qty', 'right', '']],
                'rows' => $rows,
                'totals' => null,
            ];
        }

        case 'manufacturing-statement': {
            if (!table_exists('manufacturing_orders')) {
                return ['subtitle' => 'Manufacturing module not available.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            $stmt = db()->prepare('
                SELECT mo.order_no, mo.status, mo.quantity, mo.started_on, mo.completed_on, fi.sku, fi.name AS item_name,
                       COALESCE((SELECT SUM(mi.quantity * mi.rate) FROM manufacturing_order_inputs mi WHERE mi.manufacturing_order_id = mo.id), 0) AS input_cost
                FROM manufacturing_orders mo
                INNER JOIN inventory_items fi ON fi.id = mo.finished_item_id
                WHERE mo.company_id = :company_id
                  AND COALESCE(mo.started_on, DATE(mo.created_at)) BETWEEN :f AND :t
                ORDER BY mo.id DESC LIMIT 300
            ');
            $stmt->execute(['company_id' => $scopeCompanyId, 'f' => $from, 't' => $to]);
            $rows = [];
            foreach ($stmt->fetchAll() as $mo) {
                $unitCost = (float) $mo['quantity'] > 0 ? (float) $mo['input_cost'] / (float) $mo['quantity'] : 0.0;
                $rows[] = [$mo['order_no'], $mo['sku'] . ' - ' . $mo['item_name'], ucfirst(str_replace('_', ' ', (string) $mo['status'])), number_format((float) $mo['quantity'], 2), rc_fmt((float) $mo['input_cost']), rc_fmt($unitCost), $mo['started_on'] ? date('d M Y', strtotime((string) $mo['started_on'])) : '-', $mo['completed_on'] ? date('d M Y', strtotime((string) $mo['completed_on'])) : '-'];
            }
            return [
                'subtitle' => 'Manufacturing orders with input costs.',
                'columns' => [['Order', 'left', ''], ['Finished Item', 'left', ''], ['Status', 'left', ''], ['Qty', 'right', ''], ['Input Cost', 'right', ''], ['Unit Cost', 'right', ''], ['Started', 'left', ''], ['Completed', 'left', '']],
                'rows' => $rows,
                'totals' => null,
            ];
        }

        case 'financial-ratios': {
            $balances = rc_ledger_balances($scopeCompanyId, $from, $to);
            $agg = ['current_asset' => 0.0, 'asset' => 0.0, 'current_liability' => 0.0, 'liability' => 0.0, 'equity' => 0.0, 'revenue' => 0.0, 'expense' => 0.0, 'inventory' => 0.0, 'cash' => 0.0];
            foreach ($balances as $b) {
                $closing = (float) $b['closing_net'];
                $nature = rc_ledger_nature($b);
                $master = (string) ($b['master_key'] ?? '');
                if ($nature === 'asset') {
                    $agg['asset'] += $closing;
                    if ($master === 'current_asset') {
                        $agg['current_asset'] += $closing;
                    }
                    if (stripos((string) $b['name'], 'inventor') !== false || stripos((string) $b['code'], 'INV') === 0) {
                        $agg['inventory'] += $closing;
                    }
                    if ((int) $b['is_cash_or_bank'] === 1 || $b['code'] === 'CASH') {
                        $agg['cash'] += $closing;
                    }
                } elseif ($nature === 'liability') {
                    $agg['liability'] += -$closing;
                    if ($master === 'current_liability') {
                        $agg['current_liability'] += -$closing;
                    }
                } elseif ($nature === 'equity') {
                    $agg['equity'] += -$closing;
                } elseif ($nature === 'revenue') {
                    $agg['revenue'] += (float) $b['tx_cr'] - (float) $b['tx_dr'];
                } elseif ($nature === 'expense') {
                    $agg['expense'] += (float) $b['tx_dr'] - (float) $b['tx_cr'];
                }
            }
            $net = $agg['revenue'] - $agg['expense'];
            $ratio = static fn (float $a, float $b): string => $b == 0.0 ? 'n/a' : number_format($a / $b, 2);
            $pct = static fn (float $a, float $b): string => $b == 0.0 ? 'n/a' : number_format(($a / $b) * 100, 1) . '%';
            $rows = [
                ['Current Ratio', $ratio($agg['current_asset'], $agg['current_liability']), 'Current assets / current liabilities'],
                ['Quick Ratio', $ratio($agg['current_asset'] - $agg['inventory'], $agg['current_liability']), '(Current assets - inventory) / current liabilities'],
                ['Cash Ratio', $ratio($agg['cash'], $agg['current_liability']), 'Cash and bank / current liabilities'],
                ['Debt-to-Equity', $ratio($agg['liability'], $agg['equity'] + $net), 'Total liabilities / (equity + period profit)'],
                ['Net Profit Margin', $pct($net, $agg['revenue']), 'Net profit / revenue'],
                ['Return on Equity', $pct($net, $agg['equity'] + $net), 'Net profit / (equity + period profit)'],
                ['Asset Turnover', $ratio($agg['revenue'], $agg['asset']), 'Revenue / total assets'],
            ];
            return [
                'subtitle' => 'Key financial ratios computed from live balances.',
                'columns' => [['Ratio', 'left', ''], ['Value', 'right', ''], ['Basis', 'left', '']],
                'rows' => $rows,
                'totals' => null,
            ];
        }
    }

    return ['subtitle' => '', 'columns' => [], 'rows' => [], 'totals' => null];
}
function rc_has_group_columns(array $report): bool
{
    return array_reduce($report['columns'], static fn (bool $carry, array $col): bool => $carry || $col[2] !== '', false);
}

function rc_render_table(array $report, bool $hasGroups): void
{
    ?>
    <table class="rc-table">
        <thead>
            <?php if ($hasGroups): ?>
                <tr>
                    <?php
                    $index = 0;
                    $columnCount = count($report['columns']);
                    while ($index < $columnCount) {
                        $group = $report['columns'][$index][2];
                        $span = 1;
                        while ($index + $span < $columnCount && $report['columns'][$index + $span][2] === $group && $group !== '') {
                            $span++;
                        }
                        if ($group === '') {
                            echo '<th rowspan="2" class="align-' . e($report['columns'][$index][1]) . '">' . e($report['columns'][$index][0]) . '</th>';
                            $index++;
                        } else {
                            echo '<th colspan="' . $span . '" class="rc-group-head">' . e($group) . '</th>';
                            $index += $span;
                        }
                    }
                    ?>
                </tr>
                <tr>
                    <?php foreach ($report['columns'] as $col): ?>
                        <?php if ($col[2] !== ''): ?><th class="align-<?= e($col[1]) ?>"><?= e($col[0]) ?></th><?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php else: ?>
                <tr>
                    <?php foreach ($report['columns'] as $col): ?>
                        <th class="align-<?= e($col[1]) ?>"><?= e($col[0]) ?></th>
                    <?php endforeach; ?>
                </tr>
            <?php endif; ?>
        </thead>
        <tbody>
            <?php if ($report['rows'] === []): ?>
                <tr><td colspan="<?= count($report['columns']) ?>">No data for the selected filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($report['rows'] as $row): ?>
                <?php $cells = rc_row_cells($row); $style = rc_row_style($row); ?>
                <tr class="<?= $style !== '' ? 'rpt-row-' . e($style) : '' ?>">
                    <?php foreach ($cells as $cellIndex => $cell): ?>
                        <td class="align-<?= e($report['columns'][$cellIndex][1] ?? 'left') ?>"><?= e((string) $cell) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if ($report['totals'] !== null): ?>
                <tr class="rc-total-row">
                    <?php foreach ($report['totals'] as $cellIndex => $cell): ?>
                        <td class="align-<?= e($report['columns'][$cellIndex][1] ?? 'left') ?>"><?= e((string) $cell) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Statement letterhead per the approved report templates: company name,
 * statement title, fiscal-year / period line, branch and currency.
 */
function rc_render_letterhead(array $report, array $meta): void
{
    $title = (string) ($report['title'] ?? $meta['report_label'] ?? 'Report');
    $asAt = (bool) ($report['as_at'] ?? false);
    $dateFn = function_exists('app_date');
    $periodLine = $asAt
        ? 'As at ' . ($dateFn ? app_date((string) $meta['to']) : date('d M Y', strtotime((string) $meta['to'])))
        : ($dateFn ? app_date_range((string) $meta['from'], (string) $meta['to']) : date('d M Y', strtotime((string) $meta['from'])) . ' - ' . date('d M Y', strtotime((string) $meta['to'])));
    if (!empty($meta['fiscal_label'])) {
        $periodLine = $meta['fiscal_label'] . '  |  ' . $periodLine;
    }
    ?>
    <div class="rpt-letterhead">
        <div>
            <div class="rpt-company"><?= e(mb_strtoupper((string) $meta['company_name'])) ?></div>
            <div class="rpt-title"><?= e($title) ?></div>
            <?php if (!empty($report['entity_line'])): ?>
                <div class="rpt-entity"><?= e((string) $report['entity_line']) ?></div>
            <?php endif; ?>
            <div class="rpt-period"><?= e($periodLine) ?></div>
        </div>
        <div class="rpt-meta">
            <?php if (!empty($report['org_label'])): ?>
                <span><em>Organization Type</em> : <?= e((string) $report['org_label']) ?></span>
            <?php endif; ?>
            <span><em>Branch</em> : <?= e((string) ($meta['branch'] ?? 'Head Office')) ?></span>
            <span><em>Currency</em> : <?= e((string) ($meta['currency_code'] ?? 'NPR')) ?></span>
        </div>
    </div>
    <?php
}

/** Generated-on/by strip with export actions, shown under every statement. */
function rc_render_report_foot(array $meta): void
{
    ?>
    <div class="rpt-foot">
        <span>Generated On: <?= e(date('d M Y, h:i A')) ?></span>
        <span>Generated By: <?= e((string) ($meta['generated_by'] ?? 'System')) ?></span>
        <?php if (!empty($meta['pdf_url'])): ?>
            <span class="rpt-foot-actions">
                <a href="<?= e((string) $meta['pdf_url']) ?>" target="_blank"><?= icon('documents') ?> Export PDF</a>
                <a href="<?= e((string) $meta['excel_url']) ?>"><?= icon('reports') ?> Export Excel</a>
                <a href="<?= e((string) $meta['pdf_url']) ?>" target="_blank"><?= icon('documents') ?> Print</a>
            </span>
        <?php endif; ?>
    </div>
    <?php
}
