<?php
declare(strict_types=1);

/**
 * The whole job, end to end: order → work order → receive → bill → settle.
 *
 * Every part of this chain has its own suite. This one exists because the parts
 * passing separately has never been the question — the question is what the
 * BOOKS look like when the customer has walked out and the file is closed, and
 * that is a property of the chain, not of any link in it.
 *
 * The piece is made by a kaligad FROM HIS OWN GOLD against a customer order, so
 * it exercises the two things a job order really is at once: a purchase from
 * the kaligad and a sale to the customer, priced off the same day's board so
 * the margin is the making charge and nothing else.
 *
 * What it insists on at the end:
 *
 *   the item is GONE from stock — no weight, no value, nothing stranded
 *   the kaligad is still owed, because nobody has paid him yet
 *   the customer owes nothing, because they settled
 *   every rupee that moved did so through a posted voucher, and the books
 *     balance to zero when you add them all up
 *
 *   php database/test_jewellery_order_to_cash.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/jewellery_reports.php';
require_once __DIR__ . '/../app/jewellery_assign.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $tol = 0.011): bool { return abs($a - $b) < $tol; }

/** A ledger's net across every posted voucher of a company, debit-positive. */
function o2c_ledger(int $companyId, int $ledgerId): float
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN e.entry_type='debit' THEN e.amount ELSE -e.amount END), 0)
        FROM voucher_entries e INNER JOIN vouchers v ON v.id = e.voucher_id
        WHERE e.ledger_id = :lid AND v.company_id = :cid AND v.status = 'posted'");
    $stmt->execute(['lid' => $ledgerId, 'cid' => $companyId]);

    return round((float) $stmt->fetchColumn(), 2);
}

function o2c_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code='JO2C'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$s)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['jewellery_line_taxes', 'jewellery_item_taxes', 'jewellery_taxes',
                  'jewellery_advance_allocations', 'jewellery_settlement_tenders',
                  'jewellery_settlement_allocations', 'jewellery_settlements', 'jewellery_bills',
                  'jewellery_assignment_components', 'jewellery_order_receipts', 'jewellery_order_assignments',
                  'jewellery_order_lines', 'jewellery_orders', 'jewellery_karigars',
                  'jewellery_sale_exchanges', 'jewellery_sale_lines', 'jewellery_sales',
                  'jewellery_purchase_lines', 'jewellery_purchases', 'jewellery_stock_txns',
                  'jewellery_item_profiles', 'inventory_items', 'jewellery_daily_rates',
                  'inventory_ledger_mappings', 'jewellery_settings', 'jewellery_purities',
                  'jewellery_metals', 'jewellery_units'] as $t) {
            db()->exec("DELETE FROM `$t` WHERE company_id=$s");
        }
        db()->exec("DELETE FROM accounting_parties WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email='jo2c@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
o2c_cleanup();

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------
db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,1)')
    ->execute(['n' => 'Order To Cash Jewellers (Books)', 'c' => 'JO2C']);
$cid = (int) db()->lastInsertId();
$uid = create_user(['name' => 'O2C Owner', 'email' => 'jo2c@test.local', 'password' => 'Secret#12345',
    'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, jewellery_accounting_enabled)
        VALUES (:uid,:cid,:books,:org,:code,1,1)')
    ->execute(['uid' => $uid, 'cid' => $cid, 'books' => $cid, 'org' => 'Order To Cash Jewellers', 'code' => 'JO2C-C']);
$fyRow = create_fiscal_year($cid, 'JO2C 2026/27', '2026-07-16', '2027-07-15', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fyRow['id']]);
$fy = (int) $fyRow['id'];
$_SESSION['company_id'] = $cid;
jewellery_settings($cid);

$q = static fn (string $sql): int => (int) db()->query($sql)->fetchColumn();
$gold = $q("SELECT id FROM jewellery_metals WHERE company_id=$cid AND code='GOLD'");
$p22 = $q("SELECT id FROM jewellery_purities WHERE company_id=$cid AND metal_id=$gold AND code='22K'");
$tola = $q("SELECT id FROM jewellery_units WHERE company_id=$cid AND code='TOLA'");

$mkLedger = static function (int $companyId, string $code, string $name, string $master): int {
    db()->prepare('INSERT INTO ledger_groups (company_id,name,code,master_key) VALUES (:cid,:n,:c,:m)')
        ->execute(['cid' => $companyId, 'n' => 'O2C ' . $code, 'c' => 'G' . $code, 'm' => $master]);
    $gid = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO ledgers (company_id,group_id,name,code,type) VALUES (:cid,:g,:n,:c,:t)')
        ->execute(['cid' => $companyId, 'g' => $gid, 'n' => $name, 'c' => $code,
            't' => $master === 'equity' ? 'equity' : ($master === 'liabilities' || $master === 'current_liability' ? 'liability'
                : ($master === 'income' ? 'revenue' : ($master === 'expenses' ? 'expense' : 'asset')))]);

    return (int) db()->lastInsertId();
};
$L = [];
foreach ([
    ['stock_metal', 'OSTKM', 'Metal Stock', 'assets'],
    ['stock_finished', 'OSTKF', 'Finished Stock', 'assets'],
    ['stock_karigar', 'OSTKK', 'Metal with Karigar', 'assets'],
    ['making_expense', 'OMAKE', 'Making Charges', 'expenses'],
    ['wastage_loss', 'OWAST', 'Wastage Loss', 'expenses'],
    ['karigar_payable', 'OKARP', 'Karigar Payable', 'liabilities'],
    ['sales_metal', 'OSALM', 'Sales Metal', 'income'],
    ['sales_making', 'OSALK', 'Sales Making', 'income'],
    ['sales_stone', 'OSALS', 'Sales Stone', 'income'],
    ['cogs', 'OCOGS', 'COGS', 'expenses'],
    ['vat_input', 'OVATI', 'VAT Input', 'assets'],
    ['vat_output', 'OVATO', 'VAT Output', 'current_liability'],
    ['spt_input', 'OSPTI', 'SPT Input', 'assets'],
    ['spt_output', 'OSPTO', 'SPT Output', 'current_liability'],
    ['customer_advance', 'OADVC', 'Customer Advances', 'current_liability'],
    ['opening_equity', 'OOPEQ', 'Opening Equity', 'equity'],
    ['rounding', 'OROUN', 'Rounding', 'expenses'],
] as [$purpose, $code, $name, $master]) {
    $L[$purpose] = $mkLedger($cid, $code, $name, $master);
    jewellery_save_mapping($cid, $purpose, $L[$purpose], $uid);
}
$cash = $mkLedger($cid, 'OCASH', 'Cash', 'assets');

$locket = jewellery_save_item($cid, ['code' => 'LKT22', 'name' => 'Locket', 'item_type' => 'ornament',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'vat_applicable' => 0], $uid);
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'OCUS','Sita Sharma','customer','active')")->execute(['c' => $cid]);
$customer = (int) db()->lastInsertId();

// THE BOARD IS THE WHOLE THING. Every zero-valued receipt and un-priced bill in
// this module traces back to a day with no quote on it.
jewellery_save_rate($cid, ['metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola,
    'rate_date' => '2026-08-01', 'rate_type' => 'purchase', 'rate' => 91600], $uid);   // 100,000 per fine
jewellery_save_rate($cid, ['metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola,
    'rate_date' => '2026-08-01', 'rate_type' => 'sale', 'rate' => 109920], $uid);      // 120,000 per fine
// A later, higher board, to prove nothing downstream drifts onto it.
jewellery_save_rate($cid, ['metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola,
    'rate_date' => '2026-09-01', 'rate_type' => 'purchase', 'rate' => 137400], $uid);  // 150,000 per fine
jewellery_save_rate($cid, ['metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola,
    'rate_date' => '2026-09-01', 'rate_type' => 'sale', 'rate' => 146560], $uid);      // 160,000 per fine

$karigarId = jewellery_save_karigar($cid, ['code' => 'K01', 'name' => 'Ram Shakya',
    'engagement_type' => 'contractor', 'default_making_basis' => 'flat', 'default_making_rate' => 4000], $uid);
$karigar = jewellery_karigar($cid, $karigarId);
$karigarParty = (int) $karigar['party_id'];

echo "\n1. The order, with an advance taken at the counter\n";
$order = jewellery_save_order($cid, $fy, [
    'order_date' => '2026-08-01', 'delivery_date' => '2026-09-10', 'party_id' => $customer,
    'item_id' => $locket, 'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola,
    'expected_gross_weight' => 2, 'making_basis' => 'flat', 'making_rate' => 6000, 'status' => 'confirmed',
], [], $uid);
ok($order > 0, 'The order is taken on 1 Bhadra at the 120,000 sale board');

$adv = jewellery_save_settlement($cid, $fy, [
    'settlement_date' => '2026-08-01', 'party_id' => $customer, 'order_id' => $order, 'is_advance' => 1,
    'direction' => 'received', 'mode' => 'cash', 'amount' => 50000, 'ledger_id' => $cash,
], [], $uid);
ok(jewellery_post_settlement($cid, $adv, $uid)['ok'], 'A 50,000 cash advance posts');
$advLedger = jw_party_advance_ledger_id($cid, $customer);
ok(near(o2c_ledger($cid, $advLedger), -50000.0),
    'It is a LIABILITY of 50,000 — the shop is holding the customer\'s money, not earning it');
ok(near(o2c_ledger($cid, $cash), 50000.0), 'And the till is up by the same');

echo "\n2. Out to the kaligad as a WORK ORDER — no metal issued\n";
$assign = jewellery_issue_to_karigar($cid, $fy, [
    'karigar_id' => $karigarId, 'order_id' => $order, 'item_id' => $locket,
    'purity_id' => $p22, 'unit_id' => $tola, 'issued_gross_weight' => 0,
    'issue_date' => '2026-08-05', 'making_basis' => 'flat', 'making_rate' => 4000,
], $uid);
ok($assign['ok'], 'It goes out as an instruction, not a metal movement' . ($assign['ok'] ? '' : ' — ' . $assign['error']));
$aid = (int) $assign['assignment_id'];
ok(near(jw_item_balance($cid, $locket, null, '')['fine_weight'], 0.0),
    'Nothing has moved anywhere — there is no stock of this item yet');

echo "\n3. Received on 1 Ashwin, priced at the ORDER date\n";
// 2 tola of 22K = 1.832 fine. At the ORDER date's purchase board (100,000 per
// fine) that is 183,200 — NOT the 150,000 the board says on the day it came
// back, which would invent a 91,600 gain on gold the shop never held.
$rec = jewellery_receive_from_karigar($cid, $fy, [
    'assignment_id' => $aid, 'received_item_id' => $locket, 'received_purity_id' => $p22,
    'received_gross_weight' => 2, 'qty_pieces' => 1, 'receive_date' => '2026-09-01',
], $uid);
ok($rec['ok'], 'The finished locket comes back' . ($rec['ok'] ? '' : ' — ' . $rec['error']));
ok(near((float) $rec['avg_fine_rate'], 100000.0),
    'His gold is bought at the ORDER date rate, not the day it arrived'
    . ' (got ' . number_format((float) ($rec['avg_fine_rate'] ?? 0), 2) . ')');
$metalCost = jw_round_money(1.832 * 100000);
ok(near((float) $rec['net_payable'], jw_round_money($metalCost + 4000)),
    'He is owed his gold AND his making: 183,200 + 4,000');

$stockLedger = jw_item_stock_ledger_id($cid, jewellery_item($cid, $locket));
$karigarPayable = jw_party_ledger($cid, $karigarParty, 'payable');
ok(near(o2c_ledger($cid, $stockLedger), $metalCost), 'INVENTORY is debited with the metal — 183,200');
ok(near(o2c_ledger($cid, $karigarPayable), -jw_round_money($metalCost + 4000)),
    'KALIGAD PAYABLE is credited with the whole 187,200');
$onHand = jw_item_balance($cid, $locket, null, 'stock');
ok(near((float) $onHand['fine_weight'], 1.832) && near((float) $onHand['value'], $metalCost),
    'And the register agrees: 1.832 fine on the shelf, worth 183,200');

echo "\n4. Billed to the customer, advance applied\n";
$prefill = jewellery_orders_sale_prefill($cid, [$order]);
ok($prefill['ok'], 'The bill fills itself in from the order' . ($prefill['ok'] ? '' : ' — ' . $prefill['error']));
// A sale line carries money per GROSS unit, not per fine — that is what the
// grid takes and what the customer reads on the bill. 2 tola of 22K at 120,000
// per FINE tola is 219,840, which is 109,920 the gross tola. Asserting the fine
// figure here would have been asserting the wrong contract.
ok(near((float) $prefill['lines'][0]['rate'] * 2.0, 219840.0),
    'Priced at the ORDER date sale board — 120,000 per fine, i.e. 109,920 the gross tola,'
    . ' not the 160,000 board of today (got ' . number_format((float) $prefill['lines'][0]['rate'], 2) . ' per gross)');
ok(near((float) $prefill['lines'][0]['gross_weight'], 2.0),
    'At the weight that actually came back from the kaligad');

// 1.832 fine at 120,000 = 219,840 metal + 6,000 making = 225,840
// SPT 0.5% = 1,129.20  ->  226,969.20 ; advance 50,000, cash 100,000
$sale = jewellery_save_sale($cid, $fy, [
    'sale_date' => '2026-09-02', 'party_id' => $customer, 'settle_mode' => 'cash',
    'settle_ledger_id' => $cash, 'deliver_order_ids' => [$order],
    'received_amount' => 100000, 'advance_amount' => 50000,
], $prefill['lines'], [], $uid);
$saleRow = jewellery_sale($cid, $sale);
$total = (float) $saleRow['total_amount'];
ok($total > 0, 'The bill totals ' . number_format($total, 2));
ok(near((float) $saleRow['received_amount'] + (float) $saleRow['exchange_amount']
    + (float) $saleRow['advance_amount'] + (float) $saleRow['balance_amount'], $total),
    'SETTLEMENT IDENTITY: received + exchange + advance + balance == total');

$posted = jewellery_post_sale($cid, $sale, $uid);
ok($posted['ok'], 'The bill posts' . ($posted['ok'] ? '' : ' — ' . $posted['error']));
ok((string) db()->query("SELECT status FROM jewellery_orders WHERE id=$order")->fetchColumn() === 'invoiced',
    'Posting makes the order INVOICED — billed, but the goods have not moved yet');

// GOODS LEAVE THE SHOP AS A SEPARATE ACT, and the sale screen performs it on
// the posted bill. Doing it here is what makes this an END-TO-END test rather
// than an engine test with the last step assumed: an order that is billed but
// never handed over never closes, however much the customer pays.
$handOver = jewellery_deliver_order($cid, $order, $sale, $uid);
ok($handOver['ok'], 'The locket is handed over against the posted bill'
    . ($handOver['ok'] ? '' : ' — ' . $handOver['error']));
ok((string) db()->query("SELECT status FROM jewellery_orders WHERE id=$order")->fetchColumn() === 'delivered',
    'Money still owed, so it is DELIVERED and not yet closed');

echo "\n5. THE POINT: the locket has left the shop\n";
$after = jw_item_balance($cid, $locket, null, '');
ok(near((float) $after['fine_weight'], 0.0),
    'No weight of it left anywhere — it is on the customer\'s neck'
    . ' (got ' . number_format((float) $after['fine_weight'], 4) . ')');
ok(near((float) $after['value'], 0.0),
    'And no VALUE left stranded in stock — what went in at 183,200 came out at 183,200'
    . ' (got ' . number_format((float) $after['value'], 2) . ')');
ok(near(o2c_ledger($cid, $stockLedger), 0.0),
    'The stock LEDGER is square too, not just the register'
    . ' (got ' . number_format(o2c_ledger($cid, $stockLedger), 2) . ')');
ok(near(o2c_ledger($cid, $L['cogs']), $metalCost),
    'The 183,200 became cost of sales, which is where it belongs once it is sold');
ok(near(o2c_ledger($cid, $advLedger), 0.0),
    'The advance liability is discharged — the shop no longer owes that 50,000 back');
ok(near(o2c_ledger($cid, $karigarPayable), -jw_round_money($metalCost + 4000)),
    'AND THE KALIGAD IS STILL OWED HIS 187,200 — selling the piece does not pay him');

echo "\n6. The pending amount comes in\n";
$balanceDue = jw_round_money($total - 150000.0);
ok($balanceDue > 0, 'There is ' . number_format($balanceDue, 2) . ' still to collect');
$recvLedger = jw_party_ledger($cid, $customer, 'receivable');
ok(near(o2c_ledger($cid, $recvLedger), $balanceDue), 'It sits on the customer\'s receivable');

$bill = db()->query("SELECT id FROM jewellery_bills WHERE company_id=$cid
    AND source_type='jewellery_sale' AND source_id=$sale LIMIT 1")->fetchColumn();
ok((int) $bill > 0, 'A bill was opened for it, so it can be chased');
$pay = jewellery_save_settlement($cid, $fy, [
    'settlement_date' => '2026-09-20', 'party_id' => $customer, 'direction' => 'received',
    'mode' => 'cash', 'amount' => $balanceDue, 'ledger_id' => $cash,
], [['bill_id' => (int) $bill, 'amount' => $balanceDue]], $uid);
ok(jewellery_post_settlement($cid, $pay, $uid)['ok'], 'The balance is received and posted');
ok(near(o2c_ledger($cid, $recvLedger), 0.0), 'The customer owes nothing now');
ok((string) db()->query("SELECT status FROM jewellery_orders WHERE id=$order")->fetchColumn() === 'closed',
    'And the order CLOSES itself — delivered, and paid for');

echo "\n7. The books, added up\n";
// Every posted voucher of this company, together. A chain that gets each step
// right and the whole wrong is exactly what a suite of separate tests misses.
$trial = (float) db()->query("SELECT ROUND(COALESCE(SUM(CASE WHEN e.entry_type='debit' THEN e.amount ELSE -e.amount END),0),2)
    FROM voucher_entries e INNER JOIN vouchers v ON v.id=e.voucher_id
    WHERE v.company_id=$cid AND v.status='posted'")->fetchColumn();
ok(near($trial, 0.0), 'The trial balance is zero — every voucher in the chain balances (' . number_format($trial, 2) . ')');
$cashIn = o2c_ledger($cid, $cash);
ok(near($cashIn, $total), 'The till holds exactly what the customer paid, ' . number_format($total, 2));
ok(near(o2c_ledger($cid, $L['making_expense']), 4000.0), 'The kaligad\'s making sits in expense');
$grossProfit = jw_round_money(-o2c_ledger($cid, $L['sales_metal']) - o2c_ledger($cid, $L['sales_making'])
    - o2c_ledger($cid, $L['cogs']) - o2c_ledger($cid, $L['making_expense']));
ok(near($grossProfit, jw_round_money(219840.0 + 6000.0 - $metalCost - 4000.0)),
    'Gross profit is the metal spread plus the making margin, and nothing invented by a rate that moved'
    . ' (got ' . number_format($grossProfit, 2) . ')');

o2c_cleanup();
echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
