<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/item_profit_report.php';
function assert_profit(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
$txns = [
    ['id'=>1,'transaction_type'=>'purchase','qty_in'=>5,'qty_out'=>0,'rate'=>20],
    ['id'=>2,'transaction_type'=>'sale','qty_in'=>0,'qty_out'=>6,'rate'=>99],
    ['id'=>3,'transaction_type'=>'sales_return','qty_in'=>1,'qty_out'=>0,'rate'=>12],
    ['id'=>4,'transaction_type'=>'sale','qty_in'=>0,'qty_out'=>100,'rate'=>99],
];
$item = ['opening_qty'=>5,'opening_amount'=>50,'valuation_method'=>'fifo'];
$cost = item_profit_replay($item, $txns);
assert_profit($cost[2]['value'] === 70.0 && !$cost[2]['missing'], 'FIFO must use cost, not selling rate');
assert_profit($cost[3]['value'] === 12.0, 'Returns restore recorded cost');
assert_profit($cost[4]['missing'], 'Negative stock must not invent profit');
$item['valuation_method'] = 'weighted_average';
assert_profit(item_profit_replay($item, $txns)[2]['value'] === 90.0, 'Moving average cost');
$total = item_profit_totals([['revenue'=>120.0,'cost'=>70.0,'missing'=>0], ['revenue'=>-20.0,'cost'=>-12.0,'missing'=>0]]);
assert_profit($total['profit'] === 42.0 && $total['margin'] === 42.0, 'Return and margin aggregation');
assert_profit(item_profit_totals([['revenue'=>100,'cost'=>0,'missing'=>1]])['profit'] === null, 'Missing costs must suppress margin');
assert_profit(inv_periodic_posting_plan('sale') === null, 'Periodic sale must not post a cost journal');
assert_profit(inv_periodic_posting_plan('purchase')['debit'] === 'purchases', 'Periodic purchase goes to purchases');
echo "Item profit: FIFO, average, returns, missing costs and periodic posting tests passed.\n";
if (in_array('--database', $argv, true)) {
    if (!in_array(DB_HOST, ['localhost','127.0.0.1'], true)) throw new RuntimeException('Local test database only');
    $fy = db()->query('SELECT * FROM fiscal_years ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $cid = (int)$fy['company_id'];
    $ledgerStmt = db()->prepare('SELECT id FROM ledgers WHERE company_id = ? LIMIT 1');
    $ledgerStmt->execute([$cid]); $lid = (int)$ledgerStmt->fetchColumn();
    db()->beginTransaction();
    try {
        $suffix = bin2hex(random_bytes(4));
        db()->prepare("INSERT INTO inventory_items (company_id, sku, name, item_type, unit, status, opening_qty, opening_amount, valuation_method) VALUES (?, ?, 'Profit test item', 'stock', 'pcs', 'active', 10, 100, 'fifo')")->execute([$cid, 'IP-'.$suffix]);
        $iid = (int)db()->lastInsertId();
        db()->prepare("INSERT INTO vouchers (company_id, fiscal_year_id, voucher_no, voucher_type, voucher_date, narration, total_amount, status) VALUES (?, ?, ?, 'sales', ?, 'Profit test', 150, 'posted')")->execute([$cid,$fy['id'],'IP-'.$suffix,$fy['start_date']]);
        $vid = (int)db()->lastInsertId();
        db()->prepare("INSERT INTO voucher_entries (voucher_id, ledger_id, entry_type, amount, item_id, quantity) VALUES (?, ?, 'credit', 150, ?, 5)")->execute([$vid,$lid,$iid]);
        db()->prepare("INSERT INTO inventory_transactions (company_id, fiscal_year_id, item_id, transaction_type, transaction_date, qty_in, qty_out, rate, amount, source_voucher_id) VALUES (?, ?, ?, 'sale', ?, 0, 5, 10, 50, ?)")->execute([$cid,$fy['id'],$iid,$fy['start_date'],$vid]);
        $before = (int)db()->query('SELECT COUNT(*) FROM vouchers')->fetchColumn();
        $report = item_profit_report($cid, $fy['start_date'], $fy['end_date']);
        $match = array_values(array_filter($report['rows'], static fn($row) => $row['id'] === $iid));
        assert_profit(count($match) === 1 && $match[0]['profit'] === 100.0, 'Database item margin');
        assert_profit((int)db()->query('SELECT COUNT(*) FROM vouchers')->fetchColumn() === $before, 'Report must not post vouchers');
        db()->prepare("UPDATE vouchers SET status = 'draft' WHERE id = ?")->execute([$vid]);
        $report = item_profit_report($cid, $fy['start_date'], $fy['end_date']);
        assert_profit(!array_filter($report['rows'], static fn($row) => $row['id'] === $iid), 'Draft excluded');
        db()->prepare("UPDATE companies SET inventory_accounting = 'periodic' WHERE id = ?")->execute([$cid]);
        inv_accounting_method_forget();
        db()->prepare("INSERT INTO ledgers (company_id, code, name, type, status) VALUES (?, ?, 'Item purchase test', 'expense', 'active')")->execute([$cid,'IP-'.$suffix]);
        $purchaseLedger = (int)db()->lastInsertId();
        db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, item_id, purpose, ledger_id) VALUES (?, 'item', ?, 'purchases', ?)")->execute([$cid,$iid,$purchaseLedger]);
        $baseline = inv_periodic_trading_figures($cid, $fy['start_date'], $fy['end_date']);
        db()->prepare("UPDATE voucher_entries SET ledger_id = ?, entry_type = 'debit' WHERE voucher_id = ?")->execute([$purchaseLedger,$vid]);
        db()->prepare("UPDATE vouchers SET status = 'posted', voucher_type = 'purchase' WHERE id = ?")->execute([$vid]);
        $after = inv_periodic_trading_figures($cid, $fy['start_date'], $fy['end_date']);
        assert_profit(abs($after['purchases'] - $baseline['purchases'] - 150) < 0.001, 'Periodic statements must include per-item purchase mappings');
        echo "Database item profit queries, posted-only scope and read-only behavior passed.\n";
    } finally { db()->rollBack(); inv_accounting_method_forget(); }
}
