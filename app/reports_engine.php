<?php
declare(strict_types=1);

// Shared report engine used by admin/reports-center.php and the schedule runner.

function rc_report_registry(): array
{
    return [
        'trial-balance' => ['Trial Balance', 'Summary of all ledger balances', 'accounting'],
        'profit-loss' => ['Profit or Loss Statement', 'Income and expense summary', 'invoices'],
        'balance-sheet' => ['Balance Sheet', 'Statement of financial position', 'documents'],
        'ledger-report' => ['Ledger Report', 'Detailed ledger balances', 'accounting'],
        'group-report' => ['Group Report', 'Summary by ledger groups', 'teams'],
        'consolidated' => ['Consolidated Report', 'Consolidated financials', 'companies'],
        'ledger-wise' => ['Ledger-wise Report', 'Report by specific ledger', 'documents'],
        'party-wise' => ['Party-wise Report', 'Report by parties', 'users'],
        'cash-book' => ['Cash Book', 'Cash account transactions', 'documents'],
        'bank-book' => ['Bank Book', 'Bank account transactions', 'accounting'],
        'daybook' => ['Daybook', 'All day transactions', 'compliance'],
        'journal-register' => ['Journal Register', 'Journal entries listing', 'documents'],
        'sales-register' => ['Sales Register', 'Sales transaction details', 'invoices'],
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
               v.voucher_no, v.voucher_type, v.narration,
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
                    (string) $sn, $b['name'], $b['code'],
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
                'subtitle' => 'Summary of all ledger balances as on selected date range.',
                'columns' => [
                    ['SN', 'left', ''], ['Account Name', 'left', ''], ['Ledger Code', 'left', ''],
                    ['Dr. (' . $sym . ')', 'right', 'Opening Balance'], ['Cr. (' . $sym . ')', 'right', 'Opening Balance'],
                    ['Dr. (' . $sym . ')', 'right', 'Transactions'], ['Cr. (' . $sym . ')', 'right', 'Transactions'],
                    ['Dr. (' . $sym . ')', 'right', 'Closing Balance'], ['Cr. (' . $sym . ')', 'right', 'Closing Balance'],
                ],
                'rows' => $rows,
                'totals' => ['', 'Total', '', rc_fmt($totals['op_dr']), rc_fmt($totals['op_cr']), rc_fmt($totals['tx_dr']), rc_fmt($totals['tx_cr']), rc_fmt($totals['cl_dr']), rc_fmt($totals['cl_cr'])],
            ];
        }

        case 'profit-loss': {
            $balances = rc_ledger_balances($scopeCompanyId, $from, $to, '', $ctx['group_id'], 0);
            $income = 0.0;
            $expense = 0.0;
            $rows = [];
            foreach ($balances as $b) {
                $nature = rc_ledger_nature($b);
                $movement = (float) $b['tx_cr'] - (float) $b['tx_dr'];
                if ($nature === 'revenue' && abs($movement) > 0.004) {
                    $rows[] = ['Income', $b['name'], $b['code'], rc_fmt($movement)];
                    $income += $movement;
                } elseif ($nature === 'expense' && abs($movement) > 0.004) {
                    $rows[] = ['Expense', $b['name'], $b['code'], rc_fmt(-$movement)];
                    $expense += -$movement;
                }
            }
            $net = $income - $expense;
            $rows[] = ['', 'Total Income', '', rc_fmt($income)];
            $rows[] = ['', 'Total Expenses', '', rc_fmt($expense)];
            return [
                'subtitle' => 'Income and expense summary for the selected period.',
                'columns' => [['Section', 'left', ''], ['Account', 'left', ''], ['Code', 'left', ''], ['Amount (' . $sym . ')', 'right', '']],
                'rows' => $rows,
                'totals' => ['', $net >= 0 ? 'Net Profit' : 'Net Loss', '', rc_fmt(abs($net))],
            ];
        }

        case 'balance-sheet': {
            $balances = rc_ledger_balances($scopeCompanyId, $from, $to, '', 0, 0);
            $sections = ['asset' => 0.0, 'liability' => 0.0, 'equity' => 0.0];
            $profit = 0.0;
            $rows = [];
            foreach ($balances as $b) {
                $nature = rc_ledger_nature($b);
                $closing = (float) $b['closing_net'];
                if (abs($closing) < 0.005) {
                    continue;
                }
                if ($nature === 'asset') {
                    $rows[] = ['Assets', $b['name'], $b['code'], rc_fmt($closing)];
                    $sections['asset'] += $closing;
                } elseif ($nature === 'liability') {
                    $rows[] = ['Liabilities', $b['name'], $b['code'], rc_fmt(-$closing)];
                    $sections['liability'] += -$closing;
                } elseif ($nature === 'equity') {
                    $rows[] = ['Equity', $b['name'], $b['code'], rc_fmt(-$closing)];
                    $sections['equity'] += -$closing;
                } elseif ($nature === 'revenue') {
                    $profit += -$closing;
                } elseif ($nature === 'expense') {
                    $profit -= $closing;
                }
            }
            $rows[] = ['Equity', 'Profit for the period', '', rc_fmt($profit)];
            $rows[] = ['', 'Total Assets', '', rc_fmt($sections['asset'])];
            return [
                'subtitle' => 'Statement of financial position as on ' . date('d M Y', strtotime($to)) . '.',
                'columns' => [['Section', 'left', ''], ['Account', 'left', ''], ['Code', 'left', ''], ['Amount (' . $sym . ')', 'right', '']],
                'rows' => $rows,
                'totals' => ['', 'Total Liabilities + Equity', '', rc_fmt($sections['liability'] + $sections['equity'] + $profit)],
            ];
        }

        case 'ledger-report': {
            $balances = rc_ledger_balances($scopeCompanyId, $from, $to, $ctx['vtype'], $ctx['group_id'], $ctx['ledger_id']);
            $rows = [];
            foreach ($balances as $b) {
                $rows[] = [$b['code'], $b['name'], $b['group_name'] ?? '-', ucfirst(rc_ledger_nature($b)), rc_fmt((float) $b['tx_dr']), rc_fmt((float) $b['tx_cr']), rc_fmt($b['cl_side_dr']), rc_fmt($b['cl_side_cr'])];
            }
            return [
                'subtitle' => 'Detailed balances for every ledger.',
                'columns' => [['Code', 'left', ''], ['Ledger', 'left', ''], ['Group', 'left', ''], ['Nature', 'left', ''], ['Period Dr.', 'right', ''], ['Period Cr.', 'right', ''], ['Closing Dr.', 'right', ''], ['Closing Cr.', 'right', '']],
                'rows' => $rows,
                'totals' => null,
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

        case 'ledger-wise': {
            $targetLedger = $ctx['ledger_id'];
            if ($targetLedger <= 0) {
                $first = db()->prepare("SELECT id FROM ledgers WHERE company_id = ? AND status = 'active' ORDER BY code ASC LIMIT 1");
                $first->execute([$scopeCompanyId]);
                $targetLedger = (int) $first->fetchColumn();
            }
            $lines = rc_entry_lines($scopeCompanyId, [$targetLedger], $from, $to);
            $running = 0.0;
            $rows = [];
            foreach ($lines as $line) {
                $dr = $line['entry_type'] === 'debit' ? (float) $line['amount'] : 0.0;
                $cr = $line['entry_type'] === 'credit' ? (float) $line['amount'] : 0.0;
                $running += $dr - $cr;
                $rows[] = [date('d M Y', strtotime((string) $line['vdate'])), $line['voucher_no'], ucfirst((string) $line['voucher_type']), $line['memo'] ?: ($line['narration'] ?? ''), rc_fmt($dr), rc_fmt($cr), rc_fmt(abs($running)) . ($running >= 0 ? ' Dr' : ' Cr')];
            }
            $ledgerLabel = $lines[0]['ledger_code'] ?? '';
            return [
                'subtitle' => 'Transactions for the selected ledger' . ($ledgerLabel !== '' ? ' (' . $ledgerLabel . ')' : '') . '. Pick a ledger in the filter bar.',
                'columns' => [['Date', 'left', ''], ['Voucher', 'left', ''], ['Type', 'left', ''], ['Particulars', 'left', ''], ['Dr. (' . $sym . ')', 'right', ''], ['Cr. (' . $sym . ')', 'right', ''], ['Balance', 'right', '']],
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
            $rows = [];
            foreach ($stmt->fetchAll() as $p) {
                $receivable = (float) $p['sales_total'] - (float) $p['received_total'];
                $payable = (float) $p['purchase_total'] - (float) $p['paid_total'];
                $rows[] = [$p['code'], $p['name'], ucfirst((string) $p['party_type']), rc_fmt((float) $p['sales_total']), rc_fmt((float) $p['received_total']), rc_fmt((float) $p['purchase_total']), rc_fmt((float) $p['paid_total']), rc_fmt($receivable), rc_fmt($payable)];
            }
            return [
                'subtitle' => 'Sales, collections, purchases, and payments by party.',
                'columns' => [['Code', 'left', ''], ['Party', 'left', ''], ['Type', 'left', ''], ['Sales', 'right', ''], ['Received', 'right', ''], ['Purchases', 'right', ''], ['Paid', 'right', ''], ['Receivable', 'right', ''], ['Payable', 'right', '']],
                'rows' => $rows,
                'totals' => null,
            ];
        }

        case 'cash-book':
        case 'bank-book': {
            $isCash = $reportId === 'cash-book';
            $sql = $isCash
                ? "SELECT id FROM ledgers WHERE company_id = ? AND code = 'CASH'"
                : "SELECT l.id FROM ledgers l LEFT JOIN ledger_groups lg ON lg.id = l.group_id WHERE l.company_id = ? AND ((lg.is_cash_or_bank = 1 AND l.code <> 'CASH') OR l.name LIKE '%bank%')";
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
                'subtitle' => ($isCash ? 'Cash' : 'Bank') . ' account transactions with running balance.',
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

        case 'journal-register': {
            $stmt = db()->prepare("
                SELECT e.entry_type, e.amount, e.memo, v.voucher_no, v.narration,
                       COALESCE(v.voucher_date, DATE(v.created_at)) AS vdate, l.code AS ledger_code, l.name AS ledger_name
                FROM voucher_entries e
                INNER JOIN vouchers v ON v.id = e.voucher_id AND v.status = 'posted' AND v.voucher_type = 'journal' AND v.company_id = :company_id
                INNER JOIN ledgers l ON l.id = e.ledger_id
                WHERE COALESCE(v.voucher_date, DATE(v.created_at)) BETWEEN :f AND :t
                ORDER BY vdate ASC, v.id ASC, e.entry_type ASC
                LIMIT 500
            ");
            $stmt->execute(['company_id' => $scopeCompanyId, 'f' => $from, 't' => $to]);
            $rows = [];
            foreach ($stmt->fetchAll() as $line) {
                $rows[] = [date('d M Y', strtotime((string) $line['vdate'])), $line['voucher_no'], $line['ledger_code'] . ' - ' . $line['ledger_name'], $line['memo'] ?: ($line['narration'] ?? ''), $line['entry_type'] === 'debit' ? rc_fmt((float) $line['amount']) : '–', $line['entry_type'] === 'credit' ? rc_fmt((float) $line['amount']) : '–'];
            }
            return [
                'subtitle' => 'Journal voucher entries listing.',
                'columns' => [['Date', 'left', ''], ['Voucher', 'left', ''], ['Ledger', 'left', ''], ['Particulars', 'left', ''], ['Dr. (' . $sym . ')', 'right', ''], ['Cr. (' . $sym . ')', 'right', '']],
                'rows' => $rows,
                'totals' => null,
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
            $stmt = db()->prepare('
                SELECT i.sku, i.name, i.unit, i.item_type, i.opening_qty, i.reorder_level,
                       COALESCE(SUM(CASE WHEN t.transaction_date BETWEEN :f1 AND :t1 THEN t.qty_in ELSE 0 END), 0) AS qty_in,
                       COALESCE(SUM(CASE WHEN t.transaction_date BETWEEN :f2 AND :t2 THEN t.qty_out ELSE 0 END), 0) AS qty_out,
                       COALESCE(SUM(t.qty_in - t.qty_out), 0) AS lifetime_net
                FROM inventory_items i
                LEFT JOIN inventory_transactions t ON t.item_id = i.id
                WHERE i.company_id = :company_id
                GROUP BY i.id
                ORDER BY i.sku ASC
            ');
            $stmt->execute(['f1' => $from, 't1' => $to, 'f2' => $from, 't2' => $to, 'company_id' => $scopeCompanyId]);
            $rows = [];
            foreach ($stmt->fetchAll() as $item) {
                $closing = (float) $item['opening_qty'] + (float) $item['lifetime_net'];
                $rows[] = [$item['sku'], $item['name'], ucfirst(str_replace('_', ' ', (string) $item['item_type'])), $item['unit'], number_format((float) $item['opening_qty'], 2), number_format((float) $item['qty_in'], 2), number_format((float) $item['qty_out'], 2), number_format($closing, 2), $closing <= (float) $item['reorder_level'] ? 'Reorder' : 'OK'];
            }
            return [
                'subtitle' => 'Stock summary by item (period in/out, lifetime closing).',
                'columns' => [['SKU', 'left', ''], ['Item', 'left', ''], ['Type', 'left', ''], ['Unit', 'left', ''], ['Opening', 'right', ''], ['In', 'right', ''], ['Out', 'right', ''], ['Closing', 'right', ''], ['Level', 'left', '']],
                'rows' => $rows,
                'totals' => null,
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
                <tr>
                    <?php foreach ($row as $cellIndex => $cell): ?>
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
