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
        'stock-movement' => ['Stock Movement Report', 'All stock transactions item-wise with running balances', 'reconcile'],
        'stock-valuation' => ['Stock Valuation Report', 'Closing stock at weighted average cost with margins', 'pie'],
        'manufacturing-statement' => ['Manufacturing Statement', 'Manufacturing performance', 'settings'],
        'manufacturing-cost' => ['Manufacturing Cost Report', 'Per-order material cost breakdown', 'layers'],
        'manufacturing-wip' => ['Work in Progress (WIP)', 'Open production orders and value locked in WIP', 'attendance'],
        'production-variance' => ['Production Variance Report', 'Material, labour and overhead variances vs BOM standard', 'reconcile'],
        'nrv-report' => ['Lower of Cost & NRV', 'IAS 2 net realisable value assessment per item', 'reports'],
        'inventory-gl-reconciliation' => ['Inventory-to-GL Reconciliation', 'Perpetual stock subledger vs general-ledger inventory balance', 'reconcile'],
        'fixed-asset-register' => ['Fixed Asset Register', 'Detailed register with cost, depreciation and carrying amount', 'companies'],
        'depreciation-schedule' => ['Depreciation Schedule', 'Posted depreciation by asset and period', 'download'],
        'asset-gl-reconciliation' => ['Asset-to-GL Reconciliation', 'Fixed-asset register carrying amount vs general ledger', 'reconcile'],
        'salary-sheet' => ['Salary Sheet', 'Payroll register: earnings, deductions, tax and net pay per employee', 'wallet'],
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
function rc_row(array $cells, string $style = '', array $meta = []): array
{
    return ['cells' => $cells, 'style' => $style, 'meta' => $meta];
}

function rc_row_meta(array $row): array
{
    return (array) ($row['meta'] ?? []);
}

/**
 * Groups ledger balances for the unified Master -> Group -> Ledger
 * statements: only ledgers whose $valueFn returns a non-zero vector are
 * kept (zero-balance hiding). Returns
 * ['groups' => [name => ['ledgers' => [['b' =>, 'vals' =>]], 'sum' => []]], 'sum' => []].
 */
function rc_group_balances(array $balances, array $masters, callable $valueFn): array
{
    $groups = [];
    $sum = [];
    foreach ($balances as $b) {
        if (!in_array((string) ($b['master_key'] ?? ''), $masters, true)) {
            continue;
        }
        $vals = $valueFn($b);
        $nonZero = false;
        foreach ($vals as $v) {
            if (abs((float) $v) > 0.004) {
                $nonZero = true;
                break;
            }
        }
        if (!$nonZero) {
            continue;
        }
        $groupName = (string) (($b['group_name'] ?? '') !== '' ? $b['group_name'] : 'Ungrouped');
        $groups[$groupName]['ledgers'][] = ['b' => $b, 'vals' => $vals];
        foreach ($vals as $i => $v) {
            $groups[$groupName]['sum'][$i] = ($groups[$groupName]['sum'][$i] ?? 0.0) + (float) $v;
            $sum[$i] = ($sum[$i] ?? 0.0) + (float) $v;
        }
    }
    ksort($groups);
    return ['groups' => $groups, 'sum' => $sum];
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
 *
 * When $fyStart is given, temporary accounts (revenue/expense) reset at the
 * fiscal-year boundary: their opening balance counts only activity from
 * $fyStart up to $from, never prior years. The prior years' net P&L is
 * returned instead as ONE synthetic equity row — "Retained Earnings b/f"
 * (id 0, master equity) — so the trial balance still balances and the
 * balance sheet shows brought-forward earnings separately from the current
 * year's profit. Permanent (asset/liability/equity) accounts keep their full
 * cumulative opening, which is how closing balances carry forward across
 * any number of years without snapshots or duplication.
 */
function rc_ledger_balances(int $scopeCompanyId, string $from, string $to, string $voucherType = '', int $groupId = 0, int $ledgerId = 0, array $dims = [], ?string $fyStart = null): array
{
    $voucherWhere = "company_id = :company_id AND status = 'posted'";
    $subParams = ['company_id' => $scopeCompanyId];
    if ($voucherType !== '') {
        $voucherWhere .= ' AND voucher_type = :voucher_type';
        $subParams['voucher_type'] = $voucherType;
    }
    // Optional voucher dimensions: branch = location, project = department,
    // cost centre = cost_centre (captured on the voucher form).
    foreach (['location', 'department', 'cost_centre'] as $dimColumn) {
        $dimValue = trim((string) ($dims[$dimColumn] ?? ''));
        if ($dimValue !== '' && column_exists('vouchers', $dimColumn)) {
            $voucherWhere .= " AND {$dimColumn} = :dim_{$dimColumn}";
            $subParams['dim_' . $dimColumn] = $dimValue;
        }
    }

    $sql = "
        SELECT l.id, l.code, l.name, l.type, lg.name AS group_name, lg.master_key, COALESCE(lg.is_cash_or_bank, 0) AS is_cash_or_bank,
               COALESCE(b.op_dr, 0) AS op_dr, COALESCE(b.op_cr, 0) AS op_cr,
               COALESCE(b.tx_dr, 0) AS tx_dr, COALESCE(b.tx_cr, 0) AS tx_cr,
               COALESCE(b.pre_fy_dr, 0) AS pre_fy_dr, COALESCE(b.pre_fy_cr, 0) AS pre_fy_cr
        FROM ledgers l
        LEFT JOIN ledger_groups lg ON lg.id = l.group_id
        LEFT JOIN (
            SELECT e.ledger_id,
                   SUM(CASE WHEN d.vdate < :from1 AND e.entry_type = 'debit' THEN e.amount ELSE 0 END) AS op_dr,
                   SUM(CASE WHEN d.vdate < :from2 AND e.entry_type = 'credit' THEN e.amount ELSE 0 END) AS op_cr,
                   SUM(CASE WHEN d.vdate BETWEEN :from3 AND :to1 AND e.entry_type = 'debit' THEN e.amount ELSE 0 END) AS tx_dr,
                   SUM(CASE WHEN d.vdate BETWEEN :from4 AND :to2 AND e.entry_type = 'credit' THEN e.amount ELSE 0 END) AS tx_cr,
                   SUM(CASE WHEN d.vdate < :fy_start1 AND e.entry_type = 'debit' THEN e.amount ELSE 0 END) AS pre_fy_dr,
                   SUM(CASE WHEN d.vdate < :fy_start2 AND e.entry_type = 'credit' THEN e.amount ELSE 0 END) AS pre_fy_cr
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
    $fyBoundary = $fyStart !== null && $fyStart !== '' ? $fyStart : $from;
    $params = $subParams + [
        'from1' => $from, 'from2' => $from, 'from3' => $from, 'from4' => $from,
        'to1' => $to, 'to2' => $to,
        'fy_start1' => $fyBoundary, 'fy_start2' => $fyBoundary,
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

    $useFyReset = $fyStart !== null && $fyStart !== '';
    $retainedBroughtForward = 0.0; // net CREDIT = cumulative prior-years profit
    foreach ($rows as &$row) {
        $opDr = (float) $row['op_dr'];
        $opCr = (float) $row['op_cr'];
        if ($useFyReset) {
            $nature = rc_ledger_nature($row);
            if ($nature === 'revenue' || $nature === 'expense') {
                // Temporary account: opening = current-fiscal-year activity
                // before the report window only. Everything earlier belongs
                // to prior years' profit and moves to retained earnings.
                $retainedBroughtForward += (float) $row['pre_fy_cr'] - (float) $row['pre_fy_dr'];
                $opDr -= (float) $row['pre_fy_dr'];
                $opCr -= (float) $row['pre_fy_cr'];
                $row['op_dr'] = $opDr;
                $row['op_cr'] = $opCr;
            }
        }
        $opening = $opDr - $opCr;
        $closing = $opening + (float) $row['tx_dr'] - (float) $row['tx_cr'];
        $row['opening_net'] = $opening;
        $row['closing_net'] = $closing;
        $row['op_side_dr'] = max(0.0, $opening);
        $row['op_side_cr'] = max(0.0, -$opening);
        $row['cl_side_dr'] = max(0.0, $closing);
        $row['cl_side_cr'] = max(0.0, -$closing);
    }
    unset($row);

    if ($useFyReset && abs($retainedBroughtForward) >= 0.005 && $groupId <= 0 && $ledgerId <= 0) {
        // One computed equity line carries the prior years' cumulative
        // result. It is NOT a plug figure: it equals, by construction, the
        // exact amount removed from the temporary accounts' openings above,
        // so total debits still equal total credits. A loss shows as an
        // opening DEBIT (negative retained earnings), never as income.
        $opening = -$retainedBroughtForward; // debit-positive convention
        $rows[] = [
            'id' => 0, 'code' => '', 'name' => 'Retained Earnings b/f',
            'type' => 'equity', 'group_name' => 'Retained Earnings',
            'master_key' => 'equity', 'is_cash_or_bank' => 0,
            'op_dr' => max(0.0, $opening), 'op_cr' => max(0.0, -$opening),
            'tx_dr' => 0.0, 'tx_cr' => 0.0,
            'pre_fy_dr' => 0.0, 'pre_fy_cr' => 0.0,
            'opening_net' => $opening, 'closing_net' => $opening,
            'op_side_dr' => max(0.0, $opening), 'op_side_cr' => max(0.0, -$opening),
            'cl_side_dr' => max(0.0, $opening), 'cl_side_cr' => max(0.0, -$opening),
        ];
    }

    return $rows;
}

/** The selected fiscal year's start date from the report context, or null. */
function rc_ctx_fy_start(array $ctx): ?string
{
    $fyStart = (string) ($ctx['fy_start'] ?? '');
    return $fyStart !== '' ? $fyStart : null;
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
    $ctx += ['vtype' => '', 'group_id' => 0, 'ledger_id' => 0, 'item_id' => 0, 'biz' => 'all', 'dims' => [],
             'company_id' => $scopeCompanyId, 'company_name' => '', 'subsidiaries' => []];
    switch ($reportId) {
        case 'trial-balance': {
            $balances = rc_ledger_balances($scopeCompanyId, $from, $to, (string) $ctx['vtype'], (int) $ctx['group_id'], (int) $ctx['ledger_id'], (array) ($ctx['dims'] ?? []), rc_ctx_fy_start($ctx));
            // Master -> Group -> Ledger hierarchy in natural chart order.
            $tbValue = static fn (array $b): array => [
                (float) $b['op_side_dr'], (float) $b['op_side_cr'],
                (float) $b['tx_dr'], (float) $b['tx_cr'],
                (float) $b['cl_side_dr'], (float) $b['cl_side_cr'],
            ];
            $masterOrder = array_keys(LEDGER_MASTERS);
            $rows = [];
            $grand = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
            $mi = 0;
            foreach ($masterOrder as $masterKey) {
                $section = rc_group_balances($balances, [$masterKey], $tbValue);
                if ($section['groups'] === []) {
                    continue;
                }
                $mi++;
                $node = 'm' . $mi;
                $masterLabel = (string) (LEDGER_MASTERS[$masterKey]['label'] ?? ucwords(str_replace('_', ' ', $masterKey)));
                $sum = $section['sum'] + [0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
                $rows[] = rc_row(array_merge(['', $mi . '. ' . strtoupper($masterLabel)], array_map('rc_fmt', $sum)), 'bold', ['level' => 0, 'node' => $node, 'label_cell' => 1]);
                $gi = 0;
                foreach ($section['groups'] as $groupName => $groupData) {
                    $gi++;
                    $gnode = $node . '.' . $gi;
                    $gsum = $groupData['sum'] + [0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
                    $rows[] = rc_row(array_merge(['', $mi . '.' . $gi . ' ' . $groupName], array_map('rc_fmt', $gsum)), 'bold', ['level' => 1, 'node' => $gnode, 'parent' => $node, 'label_cell' => 1]);
                    foreach ($groupData['ledgers'] as $ledgerLine) {
                        $rows[] = rc_row(array_merge([(string) $ledgerLine['b']['code'], (string) $ledgerLine['b']['name']], array_map('rc_fmt', $ledgerLine['vals'])), '', ['level' => 2, 'parent' => $gnode, 'ledger_id' => (int) $ledgerLine['b']['id'], 'label_cell' => 1]);
                    }
                }
                foreach ($sum as $i => $v) {
                    $grand[$i] += (float) $v;
                }
            }
            return [
                'number' => '1',
                'title' => 'Trial Balance',
                'subtitle' => 'Ledger balances by master and group. Click a row to expand or drill into the ledger.',
                'columns' => [
                    ['Code', 'left', ''], ['Particulars', 'left', ''],
                    ['Dr.', 'right', 'Opening Balance'], ['Cr.', 'right', 'Opening Balance'],
                    ['Dr.', 'right', 'Period Activity'], ['Cr.', 'right', 'Period Activity'],
                    ['Dr.', 'right', 'Closing Balance'], ['Cr.', 'right', 'Closing Balance'],
                ],
                'rows' => $rows,
                'totals' => ['', 'Total', rc_fmt($grand[0]), rc_fmt($grand[1]), rc_fmt($grand[2]), rc_fmt($grand[3]), rc_fmt($grand[4]), rc_fmt($grand[5])],
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

                // Master -> Group -> Ledger hierarchy from period movements,
                // with the classic profit checkpoints between sections.
                $balancesCur = rc_ledger_balances($scopeCompanyId, $from, $to, '', 0, 0, (array) ($ctx['dims'] ?? []));
                $balancesPrev = rc_ledger_balances($scopeCompanyId, $prevFrom, $prevTo, '', 0, 0, (array) ($ctx['dims'] ?? []));
                $prevMovement = [];
                foreach ($balancesPrev as $pb) {
                    $nature = rc_ledger_nature($pb);
                    $prevMovement[(int) $pb['id']] = $nature === 'revenue'
                        ? (float) $pb['tx_cr'] - (float) $pb['tx_dr']
                        : (float) $pb['tx_dr'] - (float) $pb['tx_cr'];
                }
                // Budgets (annual, per ledger) add Budget / Budget Variance
                // columns whenever any budget exists for this period's FY.
                $budgetById = [];
                if (table_exists('budgets')) {
                    try {
                        $budgetFyStmt = db()->prepare('SELECT id FROM fiscal_years WHERE company_id = :cid AND start_date <= :f AND end_date >= :t ORDER BY is_default DESC, id DESC LIMIT 1');
                        $budgetFyStmt->execute(['cid' => $scopeCompanyId, 'f' => $from, 't' => $to]);
                        $budgetFyId = (int) ($budgetFyStmt->fetchColumn() ?: 0);
                        if ($budgetFyId > 0) {
                            $budgetStmt = db()->prepare('SELECT ledger_id, amount FROM budgets WHERE company_id = :cid AND fiscal_year_id = :fy AND amount <> 0');
                            $budgetStmt->execute(['cid' => $scopeCompanyId, 'fy' => $budgetFyId]);
                            $budgetById = array_map('floatval', $budgetStmt->fetchAll(PDO::FETCH_KEY_PAIR));
                        }
                    } catch (Throwable $exception) {
                        $budgetById = [];
                    }
                }
                $hasBudgets = $budgetById !== [];
                if ($hasBudgets) {
                    $columns[] = ['Budget (' . $sym . ')', 'right', ''];
                    $columns[] = ['Budget Var (' . $sym . ')', 'right', ''];
                }

                $plCells = static function (string $label, string $note, float $c, float $p, float $budget = 0.0) use ($hasBudgets): array {
                    [$var, $pct] = rc_variance_cells($c, $p);
                    $cells = [$label, $note, rc_fmt($c), rc_fmt($p), $var, $pct];
                    if ($hasBudgets) {
                        $cells[] = rc_fmt($budget);
                        $cells[] = abs($budget) > 0.004 ? rc_fmt($c - $budget) : '–';
                    }
                    return $cells;
                };
                $plSections = [
                    ['no' => '1', 'label' => 'Revenue', 'masters' => ['direct_income', 'indirect_income'], 'revenue' => true],
                    ['no' => '2', 'label' => 'Direct Costs', 'masters' => ['direct_expense'], 'revenue' => false],
                    ['no' => '3', 'label' => 'Operating and Administrative Expenses', 'masters' => ['indirect_expense'], 'revenue' => false],
                ];
                $sectionTotals = [];
                $sectionRows = [];
                foreach ($plSections as $def) {
                    $isRevenue = (bool) $def['revenue'];
                    $section = rc_group_balances($balancesCur, $def['masters'], static function (array $b) use ($isRevenue, $prevMovement, $budgetById): array {
                        $curMove = $isRevenue
                            ? (float) $b['tx_cr'] - (float) $b['tx_dr']
                            : (float) $b['tx_dr'] - (float) $b['tx_cr'];
                        return [$curMove, (float) ($prevMovement[(int) $b['id']] ?? 0.0), (float) ($budgetById[(int) $b['id']] ?? 0.0)];
                    });
                    $sum = $section['sum'] + [0.0, 0.0, 0.0];
                    $node = 'p' . $def['no'];
                    $secRows = [rc_row($plCells($def['no'] . '. ' . $def['label'], $def['no'], (float) $sum[0], (float) $sum[1], (float) $sum[2]), 'bold', ['level' => 0, 'node' => $node])];
                    $gi = 0;
                    foreach ($section['groups'] as $groupName => $groupData) {
                        $gi++;
                        $gnode = $node . '.' . $gi;
                        $gsum = $groupData['sum'] + [0.0, 0.0, 0.0];
                        $secRows[] = rc_row($plCells($def['no'] . '.' . $gi . ' ' . $groupName, '', (float) $gsum[0], (float) $gsum[1], (float) $gsum[2]), 'bold', ['level' => 1, 'node' => $gnode, 'parent' => $node]);
                        foreach ($groupData['ledgers'] as $ledgerLine) {
                            $ledgerVals = $ledgerLine['vals'] + [0.0, 0.0, 0.0];
                            $secRows[] = rc_row($plCells((string) $ledgerLine['b']['name'], '', (float) $ledgerVals[0], (float) $ledgerVals[1], (float) $ledgerVals[2]), '', ['level' => 2, 'parent' => $gnode, 'ledger_id' => (int) $ledgerLine['b']['id']]);
                        }
                    }
                    $sectionTotals[$def['no']] = [(float) $sum[0], (float) $sum[1], (float) $sum[2]];
                    $sectionRows[$def['no']] = $secRows;
                }

                $rows = array_merge($sectionRows['1'], $sectionRows['2']);
                $grossCur = $sectionTotals['1'][0] - $sectionTotals['2'][0];
                $grossPrev = $sectionTotals['1'][1] - $sectionTotals['2'][1];
                $grossBudget = $sectionTotals['1'][2] - $sectionTotals['2'][2];
                $rows[] = rc_row($plCells('GROSS PROFIT', '', $grossCur, $grossPrev, $grossBudget), 'total');
                $rows = array_merge($rows, $sectionRows['3']);
                $netCur = $grossCur - $sectionTotals['3'][0];
                $netPrev = $grossPrev - $sectionTotals['3'][1];
                $netBudget = $grossBudget - $sectionTotals['3'][2];
                $rows[] = rc_row($plCells('NET PROFIT FOR THE PERIOD', '', $netCur, $netPrev, $netBudget), 'total');
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
            $balances = rc_ledger_balances($scopeCompanyId, $from, $to, '', 0, 0, (array) ($ctx['dims'] ?? []), rc_ctx_fy_start($ctx));
            $asAtCur = date('d M Y', strtotime($to));
            $asAtPrev = date('d M Y', strtotime($from . ' -1 day'));

            // Profit for the period folds into equity.
            $profitCur = 0.0;
            $profitPrev = 0.0;
            foreach ($balances as $b) {
                $nature = rc_ledger_nature($b);
                if ($nature === 'revenue') {
                    $profitCur += -(float) $b['closing_net'];
                    $profitPrev += -(float) $b['opening_net'];
                } elseif ($nature === 'expense') {
                    $profitCur -= (float) $b['closing_net'];
                    $profitPrev -= (float) $b['opening_net'];
                }
            }

            // Master -> Group -> Ledger with current, previous, variance.
            $sections = [
                ['no' => '1', 'label' => 'Non-Current Assets', 'masters' => ['non_current_asset'], 'sign' => 1],
                ['no' => '2', 'label' => 'Current Assets', 'masters' => ['current_asset'], 'sign' => 1],
                ['no' => '3', 'label' => 'Equity', 'masters' => ['equity'], 'sign' => -1],
                ['no' => '4', 'label' => 'Non-Current Liabilities', 'masters' => ['non_current_liability'], 'sign' => -1],
                ['no' => '5', 'label' => 'Current Liabilities', 'masters' => ['current_liability'], 'sign' => -1],
            ];
            $bsCells = static function (string $label, string $note, float $cur, float $prev): array {
                [$var, $pct] = rc_variance_cells($cur, $prev);
                return [$label, $note, rc_fmt($cur), rc_fmt($prev), $var, $pct];
            };
            $sectionTotals = [];
            $sectionRows = [];
            foreach ($sections as $def) {
                $sign = (int) $def['sign'];
                $section = rc_group_balances($balances, $def['masters'], static fn (array $b): array => [
                    $sign * (float) $b['closing_net'],
                    $sign * (float) $b['opening_net'],
                ]);
                $sum = $section['sum'] + [0.0, 0.0];
                $node = 's' . $def['no'];
                $rows = [];
                $rows[] = rc_row($bsCells($def['no'] . '. ' . $def['label'], $def['no'], (float) $sum[0], (float) $sum[1]), 'bold', ['level' => 0, 'node' => $node]);
                $gi = 0;
                foreach ($section['groups'] as $groupName => $groupData) {
                    $gi++;
                    $gnode = $node . '.' . $gi;
                    $gsum = $groupData['sum'] + [0.0, 0.0];
                    $rows[] = rc_row($bsCells($def['no'] . '.' . $gi . ' ' . $groupName, '', (float) $gsum[0], (float) $gsum[1]), 'bold', ['level' => 1, 'node' => $gnode, 'parent' => $node]);
                    foreach ($groupData['ledgers'] as $ledgerLine) {
                        $rows[] = rc_row($bsCells((string) $ledgerLine['b']['name'], '', (float) $ledgerLine['vals'][0], (float) $ledgerLine['vals'][1]), '', ['level' => 2, 'parent' => $gnode, 'ledger_id' => (int) $ledgerLine['b']['id']]);
                    }
                }
                $sectionTotals[$def['no']] = [(float) $sum[0], (float) $sum[1]];
                $sectionRows[$def['no']] = $rows;
            }

            $rows = [rc_row(['ASSETS', '', '', '', '', ''], 'section')];
            $rows = array_merge($rows, $sectionRows['1'], $sectionRows['2']);
            $totalAssetsCur = $sectionTotals['1'][0] + $sectionTotals['2'][0];
            $totalAssetsPrev = $sectionTotals['1'][1] + $sectionTotals['2'][1];
            $rows[] = rc_row($bsCells('TOTAL ASSETS', '', $totalAssetsCur, $totalAssetsPrev), 'total');

            $rows[] = rc_row(['EQUITY AND LIABILITIES', '', '', '', '', ''], 'section');
            $rows = array_merge($rows, $sectionRows['3']);
            // With a fiscal-year context, retained earnings brought forward is
            // its own equity row (from rc_ledger_balances) and this line holds
            // ONLY the selected year's result — the profit is never added twice.
            $rows[] = rc_row($bsCells('Profit / (Loss) for the period', '', $profitCur, $profitPrev), 'bold', ['level' => 1, 'parent' => 's3']);
            $rows = array_merge($rows, $sectionRows['4'], $sectionRows['5']);
            $equityCur = $sectionTotals['3'][0] + $profitCur;
            $equityPrev = $sectionTotals['3'][1] + $profitPrev;
            $totalEqLiabCur = $equityCur + $sectionTotals['4'][0] + $sectionTotals['5'][0];
            $totalEqLiabPrev = $equityPrev + $sectionTotals['4'][1] + $sectionTotals['5'][1];
            $rows[] = rc_row($bsCells('TOTAL EQUITY AND LIABILITIES', '', $totalEqLiabCur, $totalEqLiabPrev), 'total');

            return [
                'number' => '3',
                'title' => 'Statement of Financial Position',
                'as_at' => true,
                'subtitle' => 'As at ' . $asAtCur . ' — master, group, and ledger detail. Click rows to expand or drill down.',
                'columns' => [
                    ['Particulars', 'left', ''], ['Note', 'left', ''],
                    ['As at ' . $asAtCur . ' (' . $sym . ')', 'right', ''],
                    ['As at ' . $asAtPrev . ' (' . $sym . ')', 'right', ''],
                    ['Variance (' . $sym . ')', 'right', ''], ['Variance (%)', 'right', ''],
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
            $allBalances = rc_ledger_balances($scopeCompanyId, $from, $to, '', 0, 0, [], rc_ctx_fy_start($ctx));
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
            $balances = rc_ledger_balances($scopeCompanyId, $from, $to, $ctx['vtype'], 0, 0, [], rc_ctx_fy_start($ctx));
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
                       COALESCE((SELECT SUM(ti.total_amount) FROM task_invoices ti WHERE ti.party_id = ap.id AND LOWER(COALESCE(ti.status, "")) NOT IN ("cancelled", "draft") AND COALESCE(ti.issued_on, DATE(ti.created_at)) BETWEEN :f1 AND :t1), 0) AS sales_total,
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
            // Same replay engine as the Stock Summary Report page, so the two
            // always agree to the paisa. Closing value is the item's ACTUAL
            // cost-flow valuation (FIFO / weighted average history), never
            // closing qty x today's purchase rate — old posted movements keep
            // their historical cost.
            require_once __DIR__ . '/stock_report_engine.php';
            $summary = sr_stock_summary($scopeCompanyId, ['from' => $from, 'to' => $to]);
            $rows = [];
            $totals = ['opening' => 0.0, 'in' => 0.0, 'out' => 0.0, 'damage' => 0.0, 'closing' => 0.0, 'value' => 0.0];
            foreach ($summary['rows'] as $item) {
                $rows[] = [
                    $item['sku'], $item['name'],
                    $item['item_type_label'], $item['unit'],
                    number_format($item['opening_qty'], 0), number_format($item['in_qty'], 0),
                    number_format($item['out_qty'], 0),
                    $item['damage_qty'] == 0.0 ? '–' : '(' . number_format($item['damage_qty'], 0) . ')',
                    number_format($item['closing_qty'], 0), rc_fmt($item['closing_rate']), rc_fmt($item['closing_amount']),
                ];
                $totals['opening'] += $item['opening_qty'];
                $totals['in'] += $item['in_qty'];
                $totals['out'] += $item['out_qty'];
                $totals['damage'] += $item['damage_qty'];
                $totals['closing'] += $item['closing_qty'];
                $totals['value'] += $item['closing_amount'];
            }
            return [
                'number' => '7',
                'title' => 'Inventory Summary Report',
                'as_at' => true,
                'subtitle' => 'Stock summary by item as at ' . date('d M Y', strtotime($to)) . '.',
                'columns' => [
                    ['Item Code', 'left', ''], ['Item Name', 'left', ''], ['Category', 'left', ''], ['Unit', 'left', ''],
                    ['Opening Qty', 'right', ''], ['In Qty', 'right', ''], ['Out Qty', 'right', ''], ['Damage Qty', 'right', ''],
                    ['Closing Qty', 'right', ''], ['Rate (' . $sym . ')', 'right', ''], ['Value (' . $sym . ')', 'right', ''],
                ],
                'rows' => $rows,
                'totals' => ['', 'Total', '', '', number_format($totals['opening'], 0), number_format($totals['in'], 0), number_format($totals['out'], 0), $totals['damage'] == 0.0 ? '–' : '(' . number_format(abs($totals['damage']), 0) . ')', number_format($totals['closing'], 0), '', rc_fmt($totals['value'])],
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

        case 'stock-movement': {
            if (!table_exists('inventory_transactions')) {
                return ['subtitle' => 'Inventory module not available.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            $stmt = db()->prepare('
                SELECT t.item_id, t.transaction_date, t.transaction_type, t.ref_no, t.qty_in, t.qty_out, t.rate, t.amount,
                       i.sku, i.name, i.unit, i.opening_qty,
                       COALESCE((SELECT SUM(pt.qty_in - pt.qty_out) FROM inventory_transactions pt
                                 WHERE pt.item_id = t.item_id AND pt.transaction_date < :before), 0) AS net_before
                FROM inventory_transactions t
                INNER JOIN inventory_items i ON i.id = t.item_id
                WHERE t.company_id = :company_id AND t.transaction_date BETWEEN :f AND :t
                ORDER BY i.sku ASC, t.transaction_date ASC, t.id ASC
                LIMIT 1500
            ');
            $stmt->execute(['before' => $from, 'company_id' => $scopeCompanyId, 'f' => $from, 't' => $to]);
            $rows = [];
            $totals = ['in' => 0.0, 'out' => 0.0, 'amount' => 0.0];
            $currentItem = 0;
            $running = 0.0;
            foreach ($stmt->fetchAll() as $line) {
                if ((int) $line['item_id'] !== $currentItem) {
                    $currentItem = (int) $line['item_id'];
                    $running = (float) $line['opening_qty'] + (float) $line['net_before'];
                    $rows[] = rc_row([(string) $line['sku'] . ' — ' . (string) $line['name'] . ' (' . (string) $line['unit'] . ')', '', '', '', '', '', '', ''], 'section');
                    $rows[] = rc_row(['Opening balance as at ' . date('d M Y', strtotime($from)), '', '', '', '', '', '', number_format($running, 2)], 'bold');
                }
                $running += (float) $line['qty_in'] - (float) $line['qty_out'];
                $totals['in'] += (float) $line['qty_in'];
                $totals['out'] += (float) $line['qty_out'];
                $totals['amount'] += (float) $line['amount'];
                $rows[] = [
                    date('d M Y', strtotime((string) $line['transaction_date'])),
                    ucfirst(str_replace('_', ' ', (string) $line['transaction_type'])),
                    (string) ($line['ref_no'] ?? '-') ?: '-',
                    (float) $line['qty_in'] > 0 ? number_format((float) $line['qty_in'], 2) : '–',
                    (float) $line['qty_out'] > 0 ? number_format((float) $line['qty_out'], 2) : '–',
                    rc_fmt((float) $line['rate']),
                    rc_fmt((float) $line['amount']),
                    number_format($running, 2),
                ];
            }
            return [
                'title' => 'Stock Movement Report',
                'subtitle' => 'Every stock transaction between ' . date('d M Y', strtotime($from)) . ' and ' . date('d M Y', strtotime($to)) . ', item-wise with running balances.',
                'columns' => [
                    ['Date', 'left', ''], ['Type', 'left', ''], ['Ref.', 'left', ''],
                    ['Qty In', 'right', ''], ['Qty Out', 'right', ''], ['Rate (' . $sym . ')', 'right', ''],
                    ['Amount (' . $sym . ')', 'right', ''], ['Balance Qty', 'right', ''],
                ],
                'rows' => $rows,
                'totals' => ['Total', '', '', number_format($totals['in'], 2), number_format($totals['out'], 2), '', rc_fmt($totals['amount']), ''],
            ];
        }

        case 'stock-valuation': {
            if (!table_exists('inventory_items')) {
                return ['subtitle' => 'Inventory module not available.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            $stmt = db()->prepare('
                SELECT i.sku, i.name, i.unit, i.item_type, i.purchase_rate, i.sales_rate, i.opening_qty,
                       COALESCE(SUM(CASE WHEN t.transaction_date <= :t1 THEN t.qty_in - t.qty_out ELSE 0 END), 0) AS net_to_date,
                       COALESCE(SUM(CASE WHEN t.transaction_date <= :t2 AND t.qty_in > 0 AND t.rate > 0 THEN t.qty_in * t.rate ELSE 0 END), 0) AS in_value,
                       COALESCE(SUM(CASE WHEN t.transaction_date <= :t3 AND t.qty_in > 0 AND t.rate > 0 THEN t.qty_in ELSE 0 END), 0) AS in_qty
                FROM inventory_items i
                LEFT JOIN inventory_transactions t ON t.item_id = i.id
                WHERE i.company_id = :company_id
                GROUP BY i.id
                ORDER BY i.sku ASC
            ');
            $stmt->execute(['t1' => $to, 't2' => $to, 't3' => $to, 'company_id' => $scopeCompanyId]);
            $rows = [];
            $totals = ['value' => 0.0, 'potential' => 0.0];
            foreach ($stmt->fetchAll() as $item) {
                $closing = (float) $item['opening_qty'] + (float) $item['net_to_date'];
                // Weighted average of priced inbound movements; falls back to
                // the item master purchase rate when nothing priced exists.
                $avgCost = (float) $item['in_qty'] > 0 ? (float) $item['in_value'] / (float) $item['in_qty'] : (float) $item['purchase_rate'];
                $value = $closing * $avgCost;
                $salesRate = (float) $item['sales_rate'];
                $potential = $closing * $salesRate;
                $marginPct = $value > 0.004 ? (($potential - $value) / $value) * 100 : null;
                $rows[] = [
                    (string) $item['sku'], (string) $item['name'],
                    ucfirst(str_replace('_', ' ', (string) $item['item_type'])), (string) $item['unit'],
                    number_format($closing, 2), rc_fmt($avgCost), rc_fmt($value),
                    rc_fmt($salesRate), rc_fmt($potential),
                    $marginPct === null ? '–' : number_format($marginPct, 1) . '%',
                ];
                $totals['value'] += $value;
                $totals['potential'] += $potential;
            }
            return [
                'title' => 'Stock Valuation Report',
                'as_at' => true,
                'subtitle' => 'Closing stock valued at weighted average cost as at ' . date('d M Y', strtotime($to)) . '.',
                'columns' => [
                    ['Item Code', 'left', ''], ['Item Name', 'left', ''], ['Category', 'left', ''], ['Unit', 'left', ''],
                    ['Closing Qty', 'right', ''], ['Avg Cost (' . $sym . ')', 'right', ''], ['Stock Value (' . $sym . ')', 'right', ''],
                    ['Sales Rate (' . $sym . ')', 'right', ''], ['Potential (' . $sym . ')', 'right', ''], ['Margin %', 'right', ''],
                ],
                'rows' => $rows,
                'totals' => ['', 'Total', '', '', '', '', rc_fmt($totals['value']), '', rc_fmt($totals['potential']), ''],
            ];
        }

        case 'manufacturing-cost': {
            if (!table_exists('manufacturing_orders')) {
                return ['subtitle' => 'Manufacturing module not available.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            $stmt = db()->prepare("
                SELECT mo.id, mo.order_no, mo.quantity, mo.completed_on, fi.sku, fi.name AS item_name, fi.unit
                FROM manufacturing_orders mo
                INNER JOIN inventory_items fi ON fi.id = mo.finished_item_id
                WHERE mo.company_id = :company_id AND mo.status = 'completed'
                  AND COALESCE(mo.completed_on, mo.started_on, DATE(mo.created_at)) BETWEEN :f AND :t
                ORDER BY mo.completed_on DESC, mo.id DESC LIMIT 200
            ");
            $stmt->execute(['company_id' => $scopeCompanyId, 'f' => $from, 't' => $to]);
            $rows = [];
            $grandCost = 0.0;
            $inputStmt = db()->prepare('SELECT mi.quantity, mi.rate, ri.sku, ri.name, ri.unit FROM manufacturing_order_inputs mi INNER JOIN inventory_items ri ON ri.id = mi.item_id WHERE mi.manufacturing_order_id = :mo ORDER BY ri.sku ASC');
            foreach ($stmt->fetchAll() as $mo) {
                $rows[] = rc_row([
                    $mo['order_no'] . ' — ' . $mo['sku'] . ' ' . $mo['item_name']
                        . ' (output ' . number_format((float) $mo['quantity'], 2) . ' ' . $mo['unit'] . ')'
                        . ($mo['completed_on'] ? ', completed ' . date('d M Y', strtotime((string) $mo['completed_on'])) : ''),
                    '', '', '',
                ], 'section');
                $inputStmt->execute(['mo' => (int) $mo['id']]);
                $orderCost = 0.0;
                foreach ($inputStmt->fetchAll() as $input) {
                    $cost = (float) $input['quantity'] * (float) $input['rate'];
                    $orderCost += $cost;
                    $rows[] = [
                        $input['sku'] . ' — ' . $input['name'] . ' (' . $input['unit'] . ')',
                        number_format((float) $input['quantity'], 2), rc_fmt((float) $input['rate']), rc_fmt($cost),
                    ];
                }
                $unitCost = (float) $mo['quantity'] > 0 ? $orderCost / (float) $mo['quantity'] : 0.0;
                $rows[] = rc_row(['Total input cost — unit cost ' . $sym . number_format($unitCost, 2), '', '', rc_fmt($orderCost)], 'bold');
                $grandCost += $orderCost;
            }
            return [
                'title' => 'Manufacturing Cost Report',
                'subtitle' => 'Completed production orders with per-material cost breakdown, ' . date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to)) . '.',
                'columns' => [['Particulars', 'left', ''], ['Qty', 'right', ''], ['Rate (' . $sym . ')', 'right', ''], ['Amount (' . $sym . ')', 'right', '']],
                'rows' => $rows,
                'totals' => ['Total production cost', '', '', rc_fmt($grandCost)],
            ];
        }

        case 'manufacturing-wip': {
            if (!table_exists('manufacturing_orders')) {
                return ['subtitle' => 'Manufacturing module not available.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            $stmt = db()->prepare("
                SELECT mo.id, mo.order_no, mo.status, mo.quantity, mo.started_on, mo.created_at, fi.sku, fi.name AS item_name, fi.unit
                FROM manufacturing_orders mo
                INNER JOIN inventory_items fi ON fi.id = mo.finished_item_id
                WHERE mo.company_id = :company_id AND mo.status IN ('draft', 'in_progress')
                  AND COALESCE(mo.started_on, DATE(mo.created_at)) <= :t
                ORDER BY mo.started_on ASC, mo.id ASC LIMIT 200
            ");
            $stmt->execute(['company_id' => $scopeCompanyId, 't' => $to]);
            $rows = [];
            $wipTotal = 0.0;
            $inputStmt = db()->prepare('SELECT mi.quantity, mi.rate, ri.sku, ri.name, ri.unit FROM manufacturing_order_inputs mi INNER JOIN inventory_items ri ON ri.id = mi.item_id WHERE mi.manufacturing_order_id = :mo ORDER BY ri.sku ASC');
            foreach ($stmt->fetchAll() as $mo) {
                $startedOn = (string) ($mo['started_on'] ?? '') !== '' ? (string) $mo['started_on'] : date('Y-m-d', strtotime((string) $mo['created_at']));
                $daysInWip = max(0, (int) ((min(strtotime($to), time()) - strtotime($startedOn)) / 86400));
                $rows[] = rc_row([
                    $mo['order_no'] . ' — ' . $mo['sku'] . ' ' . $mo['item_name']
                        . ' (planned ' . number_format((float) $mo['quantity'], 2) . ' ' . $mo['unit'] . ') — '
                        . ucfirst(str_replace('_', ' ', (string) $mo['status']))
                        . ', started ' . date('d M Y', strtotime($startedOn)) . ' (' . $daysInWip . ' days in WIP)',
                    '', '', '',
                ], 'section');
                $inputStmt->execute(['mo' => (int) $mo['id']]);
                $orderWip = 0.0;
                $inputRows = $inputStmt->fetchAll();
                if ($inputRows === []) {
                    $rows[] = ['No materials issued yet', '–', '–', '–'];
                }
                foreach ($inputRows as $input) {
                    $cost = (float) $input['quantity'] * (float) $input['rate'];
                    $orderWip += $cost;
                    $rows[] = [
                        $input['sku'] . ' — ' . $input['name'] . ' (' . $input['unit'] . ')',
                        number_format((float) $input['quantity'], 2), rc_fmt((float) $input['rate']), rc_fmt($cost),
                    ];
                }
                $rows[] = rc_row(['Value locked in this order', '', '', rc_fmt($orderWip)], 'bold');
                $wipTotal += $orderWip;
            }
            return [
                'title' => 'Work in Progress (WIP) Report',
                'as_at' => true,
                'subtitle' => 'Open production orders and materials issued, as at ' . date('d M Y', strtotime($to)) . '.',
                'columns' => [['Particulars', 'left', ''], ['Qty', 'right', ''], ['Rate (' . $sym . ')', 'right', ''], ['Amount (' . $sym . ')', 'right', '']],
                'rows' => $rows,
                'totals' => ['Total work in progress', '', '', rc_fmt($wipTotal)],
            ];
        }

        case 'salary-sheet': {
            if (!table_exists('payroll_runs')) {
                return ['subtitle' => 'Payroll module not available.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            // Specific run when the Payroll Run filter picks one; otherwise the
            // latest finalized (or calculated) run whose pay date falls in the
            // report period.
            $payrollRunId = (int) ($ctx['payroll_run'] ?? 0);
            if ($payrollRunId > 0) {
                $runStmt = db()->prepare('SELECT * FROM payroll_runs WHERE id = :id AND company_id = :cid LIMIT 1');
                $runStmt->execute(['id' => $payrollRunId, 'cid' => $scopeCompanyId]);
                $payrollRun = $runStmt->fetch();
            } else {
                $runStmt = db()->prepare("SELECT * FROM payroll_runs
                    WHERE company_id = :cid AND status <> 'cancelled' AND (pay_date BETWEEN :f AND :t OR pay_date IS NULL)
                    ORDER BY FIELD(status, 'paid', 'posted', 'approved', 'calculated', 'draft'), pay_date DESC, id DESC LIMIT 1");
                $runStmt->execute(['cid' => $scopeCompanyId, 'f' => $from, 't' => $to]);
                $payrollRun = $runStmt->fetch();
                // A run whose pay_date falls outside the report period (e.g. a run
                // in a different fiscal year) must still be reachable — fall back to
                // the company's latest run so the sheet is never silently empty.
                if (!$payrollRun) {
                    $fallbackStmt = db()->prepare("SELECT * FROM payroll_runs
                        WHERE company_id = :cid AND status <> 'cancelled'
                        ORDER BY FIELD(status, 'paid', 'posted', 'approved', 'calculated', 'draft'), pay_date DESC, id DESC LIMIT 1");
                    $fallbackStmt->execute(['cid' => $scopeCompanyId]);
                    $payrollRun = $fallbackStmt->fetch();
                }
            }
            if (!$payrollRun) {
                return [
                    'title' => 'Salary Sheet',
                    'subtitle' => 'No payroll run found for this period. Create one under Payroll > Payroll Processing.',
                    'columns' => [['Info', 'left', '']],
                    'rows' => [['No payroll data for the selected period / run.']],
                    'totals' => null,
                ];
            }
            $lineStmt = db()->prepare('SELECT l.*, pe.employee_code, pe.department, pe.pan_no, u.name AS person_name
                FROM payroll_run_lines l
                INNER JOIN payroll_employees pe ON pe.id = l.payroll_employee_id
                INNER JOIN users u ON u.id = pe.user_id
                WHERE l.run_id = :run ORDER BY pe.employee_code ASC');
            $lineStmt->execute(['run' => (int) $payrollRun['id']]);
            $rows = [];
            $totals = array_fill(0, 11, 0.0);
            foreach ($lineStmt->fetchAll() as $payLine) {
                $values = [
                    (float) $payLine['basic'], (float) $payLine['allowances'], (float) $payLine['overtime'],
                    (float) $payLine['benefits'], (float) $payLine['gross'],
                    (float) $payLine['retirement_employee_month'], (float) $payLine['retirement_employer_month'],
                    (float) $payLine['tax_month'], (float) $payLine['advance_deduction'],
                    (float) $payLine['other_deduction'], (float) $payLine['net_pay'],
                ];
                foreach ($values as $index => $value) {
                    $totals[$index] += $value;
                }
                $rows[] = array_merge(
                    [$payLine['employee_code'], $payLine['person_name'] . ((string) ($payLine['department'] ?? '') !== '' ? ' (' . $payLine['department'] . ')' : '')],
                    array_map('rc_fmt', $values)
                );
            }
            return [
                'title' => 'Salary Sheet — ' . $payrollRun['period_label'],
                'subtitle' => 'Payroll register for ' . $payrollRun['period_label'] . ' — status ' . str_replace('_', ' ', ucfirst((string) $payrollRun['status']))
                    . ($payrollRun['pay_date'] ? ', pay date ' . date('d M Y', strtotime((string) $payrollRun['pay_date'])) : '')
                    . '. Employer retirement contribution is an employer expense and does not reduce net pay.',
                'columns' => [
                    ['Code', 'left', ''], ['Employee', 'left', ''],
                    ['Basic (' . $sym . ')', 'right', ''], ['Allowances (' . $sym . ')', 'right', ''], ['Overtime (' . $sym . ')', 'right', ''],
                    ['Benefits (' . $sym . ')', 'right', ''], ['Gross (' . $sym . ')', 'right', ''],
                    ['Retirement Emp. (' . $sym . ')', 'right', ''], ['Retirement Emplr. (' . $sym . ')', 'right', ''],
                    ['Income Tax (' . $sym . ')', 'right', ''], ['Advance (' . $sym . ')', 'right', ''],
                    ['Other Ded. (' . $sym . ')', 'right', ''], ['Net Pay (' . $sym . ')', 'right', ''],
                ],
                'rows' => $rows,
                'totals' => array_merge(['', 'Total (' . count($rows) . ' employees)'], array_map('rc_fmt', $totals)),
            ];
        }

        case 'financial-ratios': {
            $balances = rc_ledger_balances($scopeCompanyId, $from, $to, '', 0, 0, [], rc_ctx_fy_start($ctx));
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

        case 'nrv-report': {
            if (!table_exists('inventory_items') || !function_exists('inv_item_valuation')) {
                return ['subtitle' => 'Inventory module not available.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            $stmt = db()->prepare("SELECT * FROM inventory_items WHERE company_id = :cid AND status = 'active' ORDER BY sku ASC");
            $stmt->execute(['cid' => $scopeCompanyId]);
            $rows = [];
            $tCost = 0.0; $tLower = 0.0; $tWd = 0.0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $item['on_hand'] = (float) $item['opening_qty'];
                $v = inv_item_valuation($scopeCompanyId, $item);
                if ($v['qty'] <= 0.00005 && $v['cost_value'] <= 0.004) { continue; }
                $rows[] = [
                    (string) $item['sku'], (string) $item['name'],
                    strtoupper(str_replace('_', ' ', (string) ($item['valuation_method'] ?? 'weighted_average'))),
                    number_format($v['qty'], 3), rc_fmt($v['unit_cost']), rc_fmt($v['cost_value']),
                    rc_fmt($v['nrv_per_unit']), rc_fmt($v['lower_value']), rc_fmt($v['write_down']),
                ];
                $tCost += $v['cost_value']; $tLower += $v['lower_value']; $tWd += $v['write_down'];
            }
            return [
                'title' => 'Lower of Cost & NRV (IAS 2)', 'as_at' => true,
                'subtitle' => 'Each item measured at the lower of cost and net realisable value as at ' . date('d M Y', strtotime($to)) . '.',
                'columns' => [
                    ['Item Code', 'left', ''], ['Item Name', 'left', ''], ['Method', 'left', ''], ['Qty', 'right', ''],
                    ['Unit Cost (' . $sym . ')', 'right', ''], ['Cost Value (' . $sym . ')', 'right', ''],
                    ['NRV/Unit (' . $sym . ')', 'right', ''], ['Lower of C&NRV (' . $sym . ')', 'right', ''], ['Write-down (' . $sym . ')', 'right', ''],
                ],
                'rows' => $rows,
                'totals' => ['', 'Total', '', '', '', rc_fmt($tCost), '', rc_fmt($tLower), rc_fmt($tWd)],
            ];
        }

        case 'production-variance': {
            if (!table_exists('production_variances')) {
                return ['subtitle' => 'No production variances recorded.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            $stmt = db()->prepare('SELECT pv.*, mo.order_no, i.sku FROM production_variances pv JOIN manufacturing_orders mo ON mo.id = pv.manufacturing_order_id LEFT JOIN inventory_items i ON i.id = mo.finished_item_id WHERE pv.company_id = :cid ORDER BY pv.id DESC');
            $stmt->execute(['cid' => $scopeCompanyId]);
            $rows = []; $tVar = 0.0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $var = (float) $r['variance'];
                $rows[] = [
                    (string) $r['order_no'], (string) ($r['sku'] ?? ''), ucwords(str_replace('_', ' ', (string) $r['variance_type'])),
                    rc_fmt((float) $r['standard_amount']), rc_fmt((float) $r['actual_amount']),
                    rc_fmt($var) . ' ' . ($var > 0 ? '(U)' : ($var < 0 ? '(F)' : '')),
                ];
                $tVar += $var;
            }
            return [
                'title' => 'Production Variance Report',
                'subtitle' => 'Actual vs BOM standard; (U) unfavourable, (F) favourable.',
                'columns' => [['Order', 'left', ''], ['Finished Item', 'left', ''], ['Variance Type', 'left', ''], ['Standard (' . $sym . ')', 'right', ''], ['Actual (' . $sym . ')', 'right', ''], ['Variance (' . $sym . ')', 'right', '']],
                'rows' => $rows,
                'totals' => ['', '', 'Net variance', '', '', rc_fmt($tVar)],
            ];
        }

        case 'inventory-gl-reconciliation': {
            if (!table_exists('inventory_items') || !function_exists('inv_company_valuation')) {
                return ['subtitle' => 'Inventory module not available.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            // Subledger: the SAME historical replay the Stock Summary uses,
            // valued as at $to (not today's layer state).
            require_once __DIR__ . '/stock_report_engine.php';
            $srAsAt = sr_stock_summary($scopeCompanyId, ['from' => $to, 'to' => $to]);
            $subledger = (float) $srAsAt['totals']['closing_amount'];
            $items = db()->prepare('SELECT * FROM inventory_items WHERE company_id = :cid');
            $items->execute(['cid' => $scopeCompanyId]);
            $itemRows = $items->fetchAll(PDO::FETCH_ASSOC);
            // GL: closing balance of every ledger designated as inventory (item
            // links + global inventory mappings) from posted vouchers up to $to.
            $ledgerIds = [];
            foreach ($itemRows as $it) { if ((int) ($it['ledger_id'] ?? 0) > 0) { $ledgerIds[(int) $it['ledger_id']] = true; } }
            // WIP is excluded from the comparison set — the ITEM subledger can
            // never carry in-process value; WIP is reported separately below.
            $wipLedgerIds = [];
            if (table_exists('inventory_ledger_mappings')) {
                $mp = db()->prepare("SELECT DISTINCT ledger_id FROM inventory_ledger_mappings WHERE company_id = :cid AND purpose IN ('inventory_asset','raw_material','finished_goods','scrap_inventory')");
                $mp->execute(['cid' => $scopeCompanyId]);
                foreach ($mp->fetchAll(PDO::FETCH_COLUMN) as $lid) { $ledgerIds[(int) $lid] = true; }
                $wp = db()->prepare("SELECT DISTINCT ledger_id FROM inventory_ledger_mappings WHERE company_id = :cid AND purpose = 'wip'");
                $wp->execute(['cid' => $scopeCompanyId]);
                foreach ($wp->fetchAll(PDO::FETCH_COLUMN) as $lid) { $wipLedgerIds[(int) $lid] = true; unset($ledgerIds[(int) $lid]); }
            }
            $rows = [];
            $glTotal = 0.0;
            if ($ledgerIds !== []) {
                $ph = implode(',', array_fill(0, count($ledgerIds), '?'));
                // The vouchers filter sits in the LEFT JOIN's ON clause, so entries
                // whose voucher is a draft/cancelled/future row still survive the join.
                // Guard the SUM on v.id IS NOT NULL so only posted, in-period entries
                // contribute, while ledgers with no matching entries still show as 0.
                $q = db()->prepare("SELECT l.code, l.name, l.id,
                        COALESCE(SUM(CASE WHEN v.id IS NULL THEN 0 WHEN ve.entry_type='debit' THEN ve.amount ELSE -ve.amount END),0) AS bal
                    FROM ledgers l
                    LEFT JOIN voucher_entries ve ON ve.ledger_id = l.id
                    LEFT JOIN vouchers v ON v.id = ve.voucher_id AND v.status='posted' AND v.company_id = l.company_id AND (v.voucher_date IS NULL OR v.voucher_date <= ?)
                    WHERE l.id IN ($ph) GROUP BY l.id ORDER BY l.code");
                $q->execute(array_merge([$to], array_keys($ledgerIds)));
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                    $rows[] = [(string) $lr['code'], (string) $lr['name'], 'GL ledger balance', rc_fmt((float) $lr['bal'])];
                    $glTotal += (float) $lr['bal'];
                }
            }
            $diff = round($subledger - $glTotal, 2);
            if ($wipLedgerIds !== []) {
                $wph = implode(',', array_fill(0, count($wipLedgerIds), '?'));
                $wq = db()->prepare("SELECT COALESCE(SUM(CASE WHEN ve.entry_type='debit' THEN ve.amount ELSE -ve.amount END),0)
                    FROM voucher_entries ve JOIN vouchers v ON v.id=ve.voucher_id
                    WHERE ve.ledger_id IN ($wph) AND v.status='posted' AND v.company_id = ? AND (v.voucher_date IS NULL OR v.voucher_date <= ?)");
                $wq->execute(array_merge(array_keys($wipLedgerIds), [$scopeCompanyId, $to]));
                $wipBal = round((float) $wq->fetchColumn(), 2);
                if (abs($wipBal) >= 0.005) {
                    $rows[] = ['', 'Work in Progress ledger (in-process value — outside the item subledger by design)', 'Info', rc_fmt($wipBal)];
                }
            }
            $rows[] = ['', 'Stock subledger at replayed cost (as at date)', 'Subledger', rc_fmt($subledger)];
            $rows[] = ['', 'General-ledger inventory balance (excl. WIP)', 'GL', rc_fmt($glTotal)];
            $rows[] = ['', 'DIFFERENCE (subledger − GL)', abs($diff) < 0.005 ? 'Reconciled' : 'Investigate', rc_fmt($diff)];
            // EXPLAIN the difference: what has value in stock but no voucher in
            // the GL, and where the one-click fixes live.
            if (abs($diff) >= 0.005) {
                $gap = sr_unposted_summary($scopeCompanyId);
                if ($gap['openings'] > 0) {
                    $rows[] = ['', $gap['openings'] . ' item(s) with master opening stock but NO opening voucher — post them from Opening Balances → "Post missing opening-stock vouchers"', 'Cause', rc_fmt($gap['openings_value'])];
                }
                if ($gap['movements'] > 0) {
                    $rows[] = ['', $gap['movements'] . ' stock movement(s) recorded without a GL voucher — post them from Stock Summary → "Post missing movement vouchers"', 'Cause', rc_fmt($gap['movements_value'])];
                }
                if ($gap['manufacturing'] > 0) {
                    $rows[] = ['', $gap['manufacturing'] . ' manufacturing consume/produce row(s) whose production journal never posted — complete/re-post the order in Inventory & Manufacturing', 'Cause', '–'];
                }
                $rows[] = ['', 'Any remainder is usually a DIRECT opening/journal typed straight on an inventory GL ledger (not item-backed) — compare and adjust it from Opening Balances', 'Note', '–'];
            }
            return [
                'title' => 'Inventory-to-GL Reconciliation', 'as_at' => true,
                'subtitle' => 'Perpetual cost-layer subledger vs general-ledger inventory as at ' . date('d M Y', strtotime($to)) . '. Any difference is shown, never hidden.',
                'columns' => [['Ledger', 'left', ''], ['Description', 'left', ''], ['Source', 'left', ''], ['Amount (' . $sym . ')', 'right', '']],
                'rows' => $rows,
                'totals' => null,
            ];
        }

        case 'fixed-asset-register': {
            if (!table_exists('fixed_assets')) {
                return ['subtitle' => 'Fixed-asset module not available.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            $stmt = db()->prepare('SELECT * FROM fixed_assets WHERE company_id = :cid ORDER BY asset_class, asset_code');
            $stmt->execute(['cid' => $scopeCompanyId]);
            $rows = []; $tCost = 0.0; $tDep = 0.0; $tImp = 0.0; $tCarry = 0.0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $rows[] = [
                    (string) $a['asset_code'], (string) $a['name'], strtoupper((string) $a['asset_class']),
                    str_replace('_', ' ', (string) $a['depreciation_method']),
                    rc_fmt((float) $a['cost']), rc_fmt((float) $a['accumulated_depreciation']),
                    rc_fmt((float) $a['accumulated_impairment']), rc_fmt((float) $a['carrying_amount']),
                    str_replace('_', ' ', (string) $a['status']),
                ];
                $tCost += (float) $a['cost']; $tDep += (float) $a['accumulated_depreciation'];
                $tImp += (float) $a['accumulated_impairment']; $tCarry += (float) $a['carrying_amount'];
            }
            return [
                'title' => 'Fixed Asset Register', 'as_at' => true,
                'subtitle' => 'Cost, accumulated depreciation/impairment and carrying amount as at ' . date('d M Y', strtotime($to)) . '.',
                'columns' => [
                    ['Code', 'left', ''], ['Asset', 'left', ''], ['Class', 'left', ''], ['Method', 'left', ''],
                    ['Cost (' . $sym . ')', 'right', ''], ['Accum. Dep. (' . $sym . ')', 'right', ''],
                    ['Accum. Imp. (' . $sym . ')', 'right', ''], ['Carrying (' . $sym . ')', 'right', ''], ['Status', 'left', ''],
                ],
                'rows' => $rows,
                'totals' => ['', 'Total', '', '', rc_fmt($tCost), rc_fmt($tDep), rc_fmt($tImp), rc_fmt($tCarry), ''],
            ];
        }

        case 'depreciation-schedule': {
            if (!table_exists('asset_depreciation_schedule')) {
                return ['subtitle' => 'No depreciation posted.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            $stmt = db()->prepare('SELECT ads.*, fa.asset_code, fa.name FROM asset_depreciation_schedule ads JOIN fixed_assets fa ON fa.id = ads.asset_id WHERE ads.company_id = :cid AND ads.period_date BETWEEN :f AND :t ORDER BY fa.asset_code, ads.period_no');
            $stmt->execute(['cid' => $scopeCompanyId, 'f' => $from, 't' => $to]);
            $rows = []; $tDep = 0.0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $rows[] = [
                    (string) $s['asset_code'], (string) $s['name'], (string) $s['period_no'], (string) $s['period_date'],
                    rc_fmt((float) $s['depreciation']), rc_fmt((float) $s['accumulated']), rc_fmt((float) $s['carrying']),
                    $s['voucher_id'] ? '#' . (int) $s['voucher_id'] : '–',
                ];
                $tDep += (float) $s['depreciation'];
            }
            return [
                'title' => 'Depreciation Schedule',
                'subtitle' => 'Depreciation posted between ' . date('d M Y', strtotime($from)) . ' and ' . date('d M Y', strtotime($to)) . '.',
                'columns' => [['Code', 'left', ''], ['Asset', 'left', ''], ['Period', 'right', ''], ['Date', 'left', ''], ['Depreciation (' . $sym . ')', 'right', ''], ['Accumulated (' . $sym . ')', 'right', ''], ['Carrying (' . $sym . ')', 'right', ''], ['Voucher', 'left', '']],
                'rows' => $rows,
                'totals' => ['', '', '', 'Total', rc_fmt($tDep), '', '', ''],
            ];
        }

        case 'asset-gl-reconciliation': {
            if (!table_exists('fixed_assets')) {
                return ['subtitle' => 'Fixed-asset module not available.', 'columns' => [['Info', 'left', '']], 'rows' => [], 'totals' => null];
            }
            // Register side: net carrying amount from the asset register.
            $reg = db()->prepare('SELECT COALESCE(SUM(carrying_amount),0) AS carry, COALESCE(SUM(cost),0) AS cost, COALESCE(SUM(accumulated_depreciation),0) AS dep, COALESCE(SUM(accumulated_impairment),0) AS imp FROM fixed_assets WHERE company_id = :cid AND status <> \'disposed\'');
            $reg->execute(['cid' => $scopeCompanyId]);
            $r = $reg->fetch(PDO::FETCH_ASSOC);
            $registerCarry = round((float) $r['carry'], 2);
            // GL side: net of mapped PPE cost ledgers less accumulated dep/impairment ledgers.
            $glNet = 0.0;
            $rows = [];
            if (table_exists('asset_ledger_mappings')) {
                $costLedgers = []; $contraLedgers = [];
                $mp = db()->prepare("SELECT purpose, ledger_id FROM asset_ledger_mappings WHERE company_id = :cid AND scope='global' AND purpose IN ('ppe_cost','rou_asset','accumulated_depreciation','accumulated_amortization','accumulated_impairment')");
                $mp->execute(['cid' => $scopeCompanyId]);
                foreach ($mp->fetchAll(PDO::FETCH_ASSOC) as $m) {
                    if (in_array($m['purpose'], ['ppe_cost', 'rou_asset'], true)) { $costLedgers[(int) $m['ledger_id']] = true; }
                    else { $contraLedgers[(int) $m['ledger_id']] = true; }
                }
                $balOf = static function (int $lid, int $cid, string $to): float {
                    $q = db()->prepare("SELECT COALESCE(SUM(CASE WHEN ve.entry_type='debit' THEN ve.amount ELSE -ve.amount END),0)
                        FROM voucher_entries ve JOIN vouchers v ON v.id = ve.voucher_id
                        WHERE ve.ledger_id = ? AND v.company_id = ? AND v.status='posted' AND (v.voucher_date IS NULL OR v.voucher_date <= ?)");
                    $q->execute([$lid, $cid, $to]);
                    return (float) $q->fetchColumn();
                };
                foreach (array_keys($costLedgers) as $lid) { $b = $balOf($lid, $scopeCompanyId, $to); $glNet += $b; }
                foreach (array_keys($contraLedgers) as $lid) { $b = $balOf($lid, $scopeCompanyId, $to); $glNet += $b; }
            }
            $glNet = round($glNet, 2);
            $diff = round($registerCarry - $glNet, 2);
            $rows[] = ['Register gross cost', rc_fmt((float) $r['cost'])];
            $rows[] = ['Register accumulated depreciation', rc_fmt(-(float) $r['dep'])];
            $rows[] = ['Register accumulated impairment', rc_fmt(-(float) $r['imp'])];
            $rows[] = ['Register net carrying amount', rc_fmt($registerCarry)];
            $rows[] = ['General-ledger net (cost − accumulated)', rc_fmt($glNet)];
            $rows[] = ['DIFFERENCE (register − GL)', rc_fmt($diff) . (abs($diff) < 0.005 ? '  ✓ reconciled' : '  ⚠ investigate')];
            return [
                'title' => 'Asset-to-GL Reconciliation', 'as_at' => true,
                'subtitle' => 'Fixed-asset register carrying amount vs the general ledger as at ' . date('d M Y', strtotime($to)) . '.',
                'columns' => [['Description', 'left', ''], ['Amount (' . $sym . ')', 'right', '']],
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
                <?php
                $cells = rc_row_cells($row);
                $style = rc_row_style($row);
                $meta = rc_row_meta($row);
                $classes = array_filter([
                    $style !== '' ? 'rpt-row-' . $style : '',
                    isset($meta['level']) ? 'rpt-lvl-' . (int) $meta['level'] : '',
                ]);
                $attrs = '';
                if (isset($meta['level'])) {
                    $attrs .= ' data-level="' . (int) $meta['level'] . '"';
                }
                if (!empty($meta['node'])) {
                    $attrs .= ' data-node="' . e((string) $meta['node']) . '"';
                }
                if (!empty($meta['parent'])) {
                    $attrs .= ' data-parent="' . e((string) $meta['parent']) . '"';
                }
                if (!empty($meta['ledger_id'])) {
                    $attrs .= ' data-ledger-id="' . (int) $meta['ledger_id'] . '"';
                }
                ?>
                <?php $labelCell = isset($meta['level']) ? (int) ($meta['label_cell'] ?? 0) : -1; ?>
                <tr class="<?= e(implode(' ', $classes)) ?>"<?= $attrs ?>>
                    <?php foreach ($cells as $cellIndex => $cell): ?>
                        <td class="align-<?= e($report['columns'][$cellIndex][1] ?? 'left') ?><?= $cellIndex === $labelCell ? ' rpt-cell-main' : '' ?>"><?= e((string) $cell) ?></td>
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
/**
 * Notes to Accounts block rendered below a statement (screen and print).
 * $notes rows carry note_no + body; numbers match the statement's NOTE column.
 */
function rc_render_notes(array $notes): void
{
    if ($notes === []) {
        return;
    }
    ?>
    <div class="rpt-notes" aria-label="Notes to accounts">
        <div class="rpt-notes-title">Notes to Accounts</div>
        <?php foreach ($notes as $note): ?>
            <div class="rpt-note">
                <span class="rpt-note-no"><?= e((string) $note['note_no']) ?>.</span>
                <div class="rpt-note-body"><?= nl2br(e((string) $note['body'])) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

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
