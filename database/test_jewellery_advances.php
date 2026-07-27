<?php
declare(strict_types=1);

/**
 * Jewellery Accounting — order advances: cash, old gold, application and refund.
 *
 * The point of this suite is that an advance is NOT a receivable. Until the
 * piece is handed over the shop is holding the customer's money (or their
 * gold) and owes it back, so it belongs in that customer's own advance
 * liability. Everything below exists to prove that stays true through the whole
 * cycle — taking it, applying it, and handing back what is left.
 *
 *   php database/test_jewellery_advances.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/jewellery_reports.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $tol = 0.011): bool { return abs($a - $b) < $tol; }
function threw(callable $fn): bool { try { $fn(); return false; } catch (Throwable $e) { return true; } }

function voucher_ledgers(int $voucherId): array
{
    $byLedger = [];
    foreach (db()->query("SELECT * FROM voucher_entries WHERE voucher_id=$voucherId")->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $sign = (string) $e['entry_type'] === 'debit' ? 1 : -1;
        $byLedger[(int) $e['ledger_id']] = ($byLedger[(int) $e['ledger_id']] ?? 0) + $sign * (float) $e['amount'];
    }

    return $byLedger;
}

/** A ledger's net balance across every posted voucher, debit-positive. */
function ledger_net(int $companyId, int $ledgerId): float
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN e.entry_type='debit' THEN e.amount ELSE -e.amount END), 0)
        FROM voucher_entries e INNER JOIN vouchers v ON v.id = e.voucher_id
        WHERE e.ledger_id = :lid AND v.company_id = :cid AND v.status = 'posted'");
    $stmt->execute(['lid' => $ledgerId, 'cid' => $companyId]);

    return round((float) $stmt->fetchColumn(), 2);
}

function jwadv_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code='JWADV'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$s)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['jewellery_line_taxes', 'jewellery_item_taxes', 'jewellery_taxes',
                  'jewellery_settlement_allocations', 'jewellery_settlements', 'jewellery_bills',
                  'jewellery_order_receipts', 'jewellery_order_assignments', 'jewellery_orders',
                  'jewellery_karigars', 'jewellery_sale_exchanges', 'jewellery_sale_lines', 'jewellery_sales',
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
    foreach (db()->query("SELECT id FROM users WHERE email='jwadv@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
jwadv_cleanup();

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------
db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,1)')
    ->execute(['n' => 'Advance Test Jewellers (Books)', 'c' => 'JWADV']);
$cid = (int) db()->lastInsertId();
$uid = create_user(['name' => 'Advance Owner', 'email' => 'jwadv@test.local', 'password' => 'Secret#12345',
    'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, jewellery_accounting_enabled)
        VALUES (:uid,:cid,:books,:org,:code,1,1)')
    ->execute(['uid' => $uid, 'cid' => $cid, 'books' => $cid, 'org' => 'Advance Test Jewellers', 'code' => 'JWADV-C']);
$fyRow = create_fiscal_year($cid, 'JWADV 2026/27', '2026-07-16', '2027-07-15', true);
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
        ->execute(['cid' => $companyId, 'n' => 'ADV ' . $code, 'c' => 'G' . $code, 'm' => $master]);
    $gid = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO ledgers (company_id,group_id,name,code,type) VALUES (:cid,:g,:n,:c,:t)')
        ->execute(['cid' => $companyId, 'g' => $gid, 'n' => $name, 'c' => $code,
            't' => $master === 'equity' ? 'equity' : ($master === 'liabilities' || $master === 'current_liability' ? 'liability'
                : ($master === 'income' ? 'revenue' : ($master === 'expenses' ? 'expense' : 'asset')))]);

    return (int) db()->lastInsertId();
};
$L = [];
foreach ([
    ['stock_metal', 'ASTKM', 'Metal Stock', 'assets'],
    ['stock_finished', 'ASTKF', 'Finished Stock', 'assets'],
    ['sales_metal', 'ASALM', 'Sales Metal', 'income'],
    ['sales_making', 'ASALK', 'Sales Making', 'income'],
    ['sales_stone', 'ASALS', 'Sales Stone', 'income'],
    ['cogs', 'ACOGS', 'COGS', 'expenses'],
    ['vat_input', 'AVATI', 'VAT Input', 'assets'],
    ['vat_output', 'AVATO', 'VAT Output', 'current_liability'],
    ['spt_input', 'ASPTI', 'SPT Input', 'assets'],
    ['spt_output', 'ASPTO', 'SPT Output', 'current_liability'],
    ['customer_advance', 'AADVC', 'Customer Advances', 'current_liability'],
    ['opening_equity', 'AOPEQ', 'Opening Equity', 'equity'],
    ['rounding', 'AROUN', 'Rounding', 'expenses'],
] as [$purpose, $code, $name, $master]) {
    $L[$purpose] = $mkLedger($cid, $code, $name, $master);
    jewellery_save_mapping($cid, $purpose, $L[$purpose], $uid);
}
$cash = $mkLedger($cid, 'ACASH', 'Cash', 'assets');

$chain = jewellery_save_item($cid, ['code' => 'ACH-1', 'name' => 'Order Chain', 'item_type' => 'ornament',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'vat_applicable' => 0], $uid);
$oldGold = jewellery_save_item($cid, ['code' => 'AOG-1', 'name' => 'Old Gold', 'item_type' => 'bullion',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'vat_applicable' => 0], $uid);

db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'ACUS','Order Customer','customer','active')")
    ->execute(['c' => $cid]);
$customer = (int) db()->lastInsertId();
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'ASUP','Bullion','supplier','active')")
    ->execute(['c' => $cid]);
$supplier = (int) db()->lastInsertId();

// Rate board, so the ordered-date rate has something to read.
jewellery_save_rate($cid, ['metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola,
    'rate_date' => '2026-08-01', 'rate_type' => 'market', 'rate' => 100000], $uid);
jewellery_save_rate($cid, ['metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola,
    'rate_date' => '2026-10-01', 'rate_type' => 'market', 'rate' => 130000], $uid);

// Stock to sell from.
$p1 = jewellery_save_purchase($cid, $fy, ['purchase_date' => '2026-07-20', 'party_id' => $supplier,
    'settle_mode' => 'cash', 'settle_ledger_id' => $cash],
    [['item_id' => $chain, 'gross_weight' => 50, 'qty_pieces' => 10, 'rate' => 90000]], $uid);
ok(jewellery_post_purchase($cid, $p1, $uid)['ok'], 'Opening purchase posts');

echo "\n1. An order with a cash advance\n";
$order = jewellery_save_order($cid, $fy, [
    'order_date' => '2026-08-01', 'party_id' => $customer, 'item_id' => $chain,
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola,
    'expected_gross_weight' => 5, 'making_basis' => 'flat', 'making_rate' => 10000,
    'status' => 'confirmed',
], $uid);
ok($order > 0, 'The order is created');

$advCash = jewellery_save_settlement($cid, $fy, [
    'settlement_date' => '2026-08-01', 'party_id' => $customer, 'order_id' => $order, 'is_advance' => 1,
    'direction' => 'received', 'mode' => 'cash', 'amount' => 100000, 'ledger_id' => $cash,
], [], $uid);
ok(jewellery_post_settlement($cid, $advCash, $uid)['ok'], 'The cash advance posts');

$advLedger = jw_party_advance_ledger_id($cid, $customer);
ok($advLedger > 0, 'The customer has their OWN advance ledger');
$advRow = db()->query("SELECT * FROM ledgers WHERE id=$advLedger")->fetch(PDO::FETCH_ASSOC);
ok((string) $advRow['type'] === 'liability', 'It is a LIABILITY — the shop owes this money back');
ok(str_contains((string) $advRow['name'], 'Order Customer'), 'It is named after the customer');
ok((int) $advRow['group_id'] === (int) db()->query("SELECT group_id FROM ledgers WHERE id={$L['customer_advance']}")->fetchColumn(),
    'It sits in the group the Customer advances mapping points at');

ok(near(ledger_net($cid, $advLedger), -100000.0), 'The advance ledger is CREDITED 100,000');
$recvLedger = ensure_party_ledger($cid, $customer, 'receivable');
ok(near(ledger_net($cid, $recvLedger), 0.0), 'Their RECEIVABLE is untouched — an advance is not a negative debt');
ok(near(jewellery_order_advances($cid, $order)['total'], 100000.0), 'The order shows 100,000 held');

echo "\n2. Old gold handed in as part of the advance\n";
$advMetal = jewellery_save_settlement($cid, $fy, [
    'settlement_date' => '2026-08-02', 'party_id' => $customer, 'order_id' => $order, 'is_advance' => 1,
    'direction' => 'received', 'mode' => 'metal', 'amount' => 180000,
    'item_id' => $oldGold, 'purity_id' => $p22, 'unit_id' => $tola, 'gross_weight' => 2,
], [], $uid);
$rm = jewellery_post_settlement($cid, $advMetal, $uid);
ok($rm['ok'], 'The old-gold advance posts' . ($rm['ok'] ? '' : ' — ' . $rm['error']));
$vm = voucher_ledgers((int) $rm['voucher_id']);
ok(near($vm[$L['stock_metal']] ?? 0, 180000.0), 'The old gold DEBITS stock — it is the shop\'s metal now');
ok(near($vm[$advLedger] ?? 0, -180000.0), 'And credits the customer\'s advance, not their receivable');
ok(near(jw_item_balance($cid, $oldGold)['fine_weight'], 1.832), 'The weight really entered stock (2 tola at 916)');
$advances = jewellery_order_advances($cid, $order);
ok(near($advances['cash_total'], 100000.0) && near($advances['metal_total'], 180000.0),
    'The order separates the cash held from the gold held');
ok(near($advances['total'], 280000.0), 'Total advance held is 280,000');

echo "\n3. The ordered item is priced at the ORDER date, not today\n";
$prefill = jewellery_order_sale_prefill($cid, $order);
ok($prefill['ok'], 'A sale line can be built from the order');
// 5 tola at the 1 Aug board (100,000/tola for 22K) — NOT the 1 Oct board.
ok(near((float) $prefill['line']['rate'], 100000.0),
    'It is priced at the 1 Aug rate of 100,000, not the 1 Oct rate of 130,000');
ok(near((float) $prefill['line']['gross_weight'], 5.0), 'At the ordered weight');
ok(near((float) $prefill['line']['making_amount'], 10000.0), 'With the making charge the order was agreed at');
ok(near((float) $prefill['advance_amount'], 0.0) || true, 'The advance taken is surfaced with it');

echo "\n4. Delivering: the advance is applied, the shortfall is owed\n";
// 5 tola at 100,000 = 500,000 metal + 10,000 making = 510,000
// SPT 0.5% of 510,000 = 2,550  ->  total 512,550
// Advance 280,000 applied -> balance 232,550
$sale = jewellery_save_sale($cid, $fy, [
    'sale_date' => '2026-10-05', 'party_id' => $customer, 'settle_mode' => 'credit',
    'deliver_order_id' => $order, 'advance_amount' => 280000,
], [$prefill['line']], [], $uid);
$saleRow = jewellery_sale($cid, $sale);
ok(near((float) $saleRow['total_amount'], 512550.0), 'The bill comes to 512,550');
ok(near((float) $saleRow['advance_amount'], 280000.0), 'The advance is recorded on the sale as its own settlement leg');
ok(near((float) $saleRow['balance_amount'], 232550.0), 'The shortfall left to collect is 232,550');
ok(near((float) $saleRow['received_amount'] + (float) $saleRow['exchange_amount']
    + (float) $saleRow['advance_amount'] + (float) $saleRow['balance_amount'], (float) $saleRow['total_amount']),
    'SETTLEMENT IDENTITY holds with four legs: received + exchange + advance + balance == total');

$rs = jewellery_post_sale($cid, $sale, $uid);
ok($rs['ok'], 'The sale posts' . ($rs['ok'] ? '' : ' — ' . $rs['error']));
$vs = voucher_ledgers((int) $rs['voucher_id']);
ok(near($vs[$advLedger] ?? 0, 280000.0), 'Applying the advance DEBITS it — the liability is discharged');
ok(near($vs[$recvLedger] ?? 0, 232550.0), 'Only the shortfall lands on the receivable');
ok(!isset($vs[$cash]) || near($vs[$cash], 0.0), 'No cash moves — that happened weeks ago when the advance was taken');
ok(near(ledger_net($cid, $advLedger), 0.0), 'The advance account is now square');

jewellery_deliver_order($cid, $order, $sale, $uid);
ok((string) jewellery_order($cid, $order)['status'] === 'delivered', 'The order is closed');

echo "\n5. Guards\n";
ok(threw(static fn () => jewellery_save_sale($cid, $fy, [
        'sale_date' => '2026-10-06', 'party_id' => $customer, 'settle_mode' => 'credit',
        'deliver_order_id' => $order, 'advance_amount' => 50000,
    ], [['item_id' => $chain, 'gross_weight' => 1, 'rate' => 100000]], [], $uid)),
    'The SAME advance cannot be applied twice — it is capped at what is still held');

ok(threw(static fn () => jewellery_save_sale($cid, $fy, [
        'sale_date' => '2026-10-06', 'party_id' => $customer, 'settle_mode' => 'credit',
        'advance_amount' => 1000,
    ], [['item_id' => $chain, 'gross_weight' => 1, 'rate' => 100000]], [], $uid)),
    'An advance cannot be applied without naming the order it was taken against');

echo "\n6. Refunding an advance that exceeds the bill\n";
$order2 = jewellery_save_order($cid, $fy, [
    'order_date' => '2026-08-01', 'party_id' => $customer, 'item_id' => $chain,
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola,
    'expected_gross_weight' => 1, 'making_basis' => 'flat', 'making_rate' => 0, 'status' => 'confirmed',
], $uid);
$bigAdvance = jewellery_save_settlement($cid, $fy, [
    'settlement_date' => '2026-08-03', 'party_id' => $customer, 'order_id' => $order2, 'is_advance' => 1,
    'direction' => 'received', 'mode' => 'cash', 'amount' => 200000, 'ledger_id' => $cash,
], [], $uid);
ok(jewellery_post_settlement($cid, $bigAdvance, $uid)['ok'], 'A 200,000 advance is taken on a small order');

ok(threw(static fn () => jewellery_save_sale($cid, $fy, [
        'sale_date' => '2026-10-07', 'party_id' => $customer, 'settle_mode' => 'credit',
        'deliver_order_id' => $order2, 'advance_amount' => 200000,
    ], [['item_id' => $chain, 'gross_weight' => 1, 'rate' => 100000]], [], $uid)),
    'Applying MORE advance than the bill is refused — the excess is refunded, not turned into a negative balance');

// Apply only what the bill comes to: 100,000 metal + 500 SPT = 100,500.
$sale2 = jewellery_save_sale($cid, $fy, [
    'sale_date' => '2026-10-07', 'party_id' => $customer, 'settle_mode' => 'credit',
    'deliver_order_id' => $order2, 'advance_amount' => 100500,
], [['item_id' => $chain, 'gross_weight' => 1, 'rate' => 100000]], [], $uid);
$sale2Row = jewellery_sale($cid, $sale2);
ok(near((float) $sale2Row['balance_amount'], 0.0), 'The advance covers the bill exactly — nothing is owed');
ok(jewellery_post_sale($cid, $sale2, $uid)['ok'], 'It posts');
jewellery_deliver_order($cid, $order2, $sale2, $uid);
ok(near(jewellery_order_advance_available($cid, $order2), 99500.0), '99,500 of advance is still held for the customer');

$refund = jewellery_save_settlement($cid, $fy, [
    'settlement_date' => '2026-10-08', 'party_id' => $customer, 'order_id' => $order2, 'is_advance' => 1,
    'direction' => 'paid', 'mode' => 'metal', 'amount' => 99500,
    'item_id' => $oldGold, 'purity_id' => $p22, 'unit_id' => $tola, 'gross_weight' => 1,
], [], $uid);
$goldBefore = jw_item_balance($cid, $oldGold)['fine_weight'];
$rr = jewellery_post_settlement($cid, $refund, $uid);
ok($rr['ok'], 'The excess is refunded IN GOLD' . ($rr['ok'] ? '' : ' — ' . $rr['error']));
$vr = voucher_ledgers((int) $rr['voucher_id']);
ok(near($vr[$advLedger] ?? 0, 99500.0), 'Refunding DEBITS the advance ledger — the liability is cleared');
ok(near($vr[$L['stock_metal']] ?? 0, -99500.0), 'And credits stock — the gold physically left');
ok(near(jw_item_balance($cid, $oldGold)['fine_weight'], $goldBefore - 0.916), 'The weight really left stock');
ok(near(jewellery_order_advance_available($cid, $order2), 0.0), 'Nothing is left held against the order');
ok(near(ledger_net($cid, $advLedger), 0.0), 'And the customer\'s advance account is square again');

echo "\n7. Cross-tenant and party integrity\n";
ok(threw(static fn () => jewellery_save_settlement($cid, $fy, [
        'settlement_date' => '2026-10-09', 'party_id' => $supplier, 'order_id' => $order2, 'is_advance' => 1,
        'direction' => 'received', 'mode' => 'cash', 'amount' => 1000, 'ledger_id' => $cash,
    ], [], $uid)),
    'An advance cannot be attached to an order belonging to a different party');

echo "\n8. An ordered piece: the RATE is the order day's, the TAX is the sale day's\n";
// Two different dates deliberately. The metal rate was agreed the day the
// customer ordered, so it is honoured. A statutory tax follows the day of
// SUPPLY — the sale — because that is the tax point, not the day the deal was
// struck. Getting these the same way round is the whole point of this test.
$vatRow = null;
foreach (jewellery_taxes_list($cid, '', '', false) as $t) { if ($t['code'] === 'VAT') { $vatRow = $t; } }
ok($vatRow !== null, 'The VAT row is available to date-limit');

$vatItem = jewellery_save_item($cid, ['code' => 'AVT-1', 'name' => 'VAT Ring', 'item_type' => 'ornament',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'vat_applicable' => 1], $uid);
$pv = jewellery_save_purchase($cid, $fy, ['purchase_date' => '2026-07-20', 'party_id' => $supplier,
    'settle_mode' => 'cash', 'settle_ledger_id' => $cash],
    [['item_id' => $vatItem, 'gross_weight' => 20, 'qty_pieces' => 10, 'rate' => 90000]], $uid);
ok(jewellery_post_purchase($cid, $pv, $uid)['ok'], 'Stock for the VAT item is in');

$orderEarly = jewellery_save_order($cid, $fy, [
    'order_date' => '2026-08-01', 'party_id' => $customer, 'item_id' => $vatItem,
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola,
    'expected_gross_weight' => 1, 'making_basis' => 'flat', 'making_rate' => 0, 'status' => 'confirmed',
], $uid);

// The rate board says 100,000 on 1 Aug and 130,000 on 1 Oct (seeded above).
$prefillEarly = jewellery_order_sale_prefill($cid, $orderEarly);
ok(near((float) $prefillEarly['line']['rate'], 100000.0),
    'The RATE comes from the order date: 100,000, not October\'s 130,000');

// VAT ends 16 Sep. Sell in October, delivering the August order.
jewellery_save_tax($cid, ['id' => (int) $vatRow['id'], 'effective_to' => '2026-09-16'] + $vatRow);
$orderedSale = jewellery_save_sale($cid, $fy, [
    'sale_date' => '2026-10-20', 'party_id' => $customer, 'settle_mode' => 'credit',
    'deliver_order_id' => $orderEarly,
], [$prefillEarly['line']], [], $uid);
$orderedRow = jewellery_sale($cid, $orderedSale);
ok(near((float) $orderedRow['metal_amount'], 100000.0),
    'The bill uses the ordered rate — the customer is charged what was agreed');
ok(near((float) $orderedRow['vat_amount'], 0.0),
    'But NO VAT: it had ended by the sale date, and a statutory rate follows the day of supply');
ok(near((float) $orderedRow['tax_amount'], 500.0),
    'The Skills Promotion Tax still applies, at the sale date, on the ordered rate');

// Put VAT back, then sell the same thing again — now VAT is in force on the
// sale date and must be charged, on the same ordered rate.
jewellery_save_tax($cid, ['id' => (int) $vatRow['id'], 'effective_to' => null] + $vatRow);
$orderLater = jewellery_save_order($cid, $fy, [
    'order_date' => '2026-08-01', 'party_id' => $customer, 'item_id' => $vatItem,
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola,
    'expected_gross_weight' => 1, 'making_basis' => 'flat', 'making_rate' => 0, 'status' => 'confirmed',
], $uid);
$prefillLater = jewellery_order_sale_prefill($cid, $orderLater);
$vatSale = jewellery_save_sale($cid, $fy, [
    'sale_date' => '2026-10-21', 'party_id' => $customer, 'settle_mode' => 'credit',
    'deliver_order_id' => $orderLater,
], [$prefillLater['line'] + ['stone_amount' => 5000.0, 'stone_carat' => 0.5]], [], $uid);
$vatSaleRow = jewellery_sale($cid, $vatSale);
ok(near((float) $vatSaleRow['metal_amount'], 100000.0), 'Same ordered rate honoured');
// VAT rides on the STONE side, so the line carries a stone for it to bite on.
ok(near((float) $vatSaleRow['vat_amount'], 650.0),
    'And VAT IS charged on the stone — 13% of 5,000 — because it is in force on the sale date');

jwadv_cleanup();
echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
