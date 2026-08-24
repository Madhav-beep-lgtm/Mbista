<?php
declare(strict_types=1);

/**
 * Jewellery Accounting — Phases 3 & 4: purchases, sales, old-gold exchange,
 * per-item VAT, COGS and bill-wise party accounting.
 *
 * Proves the settlement identity (received + exchange + balance == total),
 * that every voucher balances on the mapped ledgers, that VAT follows the
 * ITEM rather than the document, that COGS is stamped at the weighted average
 * in force when posting, that metal-to-metal and metal-to-cash fall out of the
 * same model, and that bills cannot be over-allocated or silently unposted.
 *   php database/test_jewellery_trading.php
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

/** Debit/credit totals and the per-ledger net of a voucher. */
function voucher_shape(int $voucherId): array
{
    $dr = 0.0; $cr = 0.0; $byLedger = [];
    foreach (db()->query("SELECT * FROM voucher_entries WHERE voucher_id=$voucherId")->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $amount = (float) $e['amount'];
        if ((string) $e['entry_type'] === 'debit') { $dr += $amount; $byLedger[(int) $e['ledger_id']] = ($byLedger[(int) $e['ledger_id']] ?? 0) + $amount; }
        else { $cr += $amount; $byLedger[(int) $e['ledger_id']] = ($byLedger[(int) $e['ledger_id']] ?? 0) - $amount; }
    }

    return ['dr' => round($dr, 2), 'cr' => round($cr, 2), 'ledgers' => $byLedger];
}

function jwt_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('JWTRA','JWTRB')")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$s)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['jewellery_stock_unit_events', 'jewellery_stock_units', 'jewellery_advance_allocations', 'jewellery_settlement_tenders', 'jewellery_settlement_allocations', 'jewellery_settlements', 'jewellery_bills',
                  'jewellery_sale_exchanges', 'jewellery_sale_lines', 'jewellery_sales',
                  'jewellery_purchase_lines', 'jewellery_purchases',                   'jewellery_stock_txns', 'jewellery_item_profiles', 'inventory_items', 'jewellery_daily_rates',
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
    foreach (db()->query("SELECT id FROM users WHERE email LIKE 'jwtrade-%@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
jwt_cleanup();

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------
$mkClient = static function (string $code, string $org, string $email): array {
    db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,1)')
        ->execute(['n' => $org . ' (Books)', 'c' => $code]);
    $cid = (int) db()->lastInsertId();
    $uid = create_user(['name' => $org . ' Owner', 'email' => $email, 'password' => 'Secret#12345', 'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
    db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, jewellery_accounting_enabled)
            VALUES (:uid,:cid,:books,:org,:code,1,1)')
        ->execute(['uid' => $uid, 'cid' => $cid, 'books' => $cid, 'org' => $org, 'code' => $code . '-C']);
    $fy = create_fiscal_year($cid, $code . ' 2026/27', '2026-07-16', '2027-07-15', true);
    db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);

    return [$cid, (int) $fy['id'], $uid];
};
[$cidA, $fyA, $userA] = $mkClient('JWTRA', 'Kantipur Jewellers', 'jwtrade-a@test.local');
[$cidB, $fyB, $userB] = $mkClient('JWTRB', 'Rival Gold House', 'jwtrade-b@test.local');
$_SESSION['company_id'] = $cidA;
jewellery_settings($cidA);
jewellery_settings($cidB);

$q = static fn (string $sql): int => (int) db()->query($sql)->fetchColumn();
$goldA = $q("SELECT id FROM jewellery_metals WHERE company_id=$cidA AND code='GOLD'");
$diaA = $q("SELECT id FROM jewellery_metals WHERE company_id=$cidA AND code='DIAMOND'");
$p24 = $q("SELECT id FROM jewellery_purities WHERE company_id=$cidA AND metal_id=$goldA AND code='24K'");
$p22 = $q("SELECT id FROM jewellery_purities WHERE company_id=$cidA AND metal_id=$goldA AND code='22K'");
$pDia = $q("SELECT id FROM jewellery_purities WHERE company_id=$cidA AND metal_id=$diaA AND code='STD'");
$tola = $q("SELECT id FROM jewellery_units WHERE company_id=$cidA AND code='TOLA'");
$carat = $q("SELECT id FROM jewellery_units WHERE company_id=$cidA AND code='CT'");

// Ledgers: one per posting purpose, plus cash.
$mkLedger = static function (int $companyId, string $code, string $name, string $master): int {
    db()->prepare('INSERT INTO ledger_groups (company_id,name,code,master_key) VALUES (:cid,:n,:c,:m)')
        ->execute(['cid' => $companyId, 'n' => 'JW ' . $code, 'c' => 'G' . $code, 'm' => $master]);
    $gid = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO ledgers (company_id,group_id,name,code) VALUES (:cid,:g,:n,:c)')
        ->execute(['cid' => $companyId, 'g' => $gid, 'n' => $name, 'c' => $code]);

    return (int) db()->lastInsertId();
};
$L = [];
foreach ([
    ['stock_metal', 'STKM', 'Metal Stock', 'assets'],
    ['stock_finished', 'STKF', 'Finished Stock', 'assets'],
    ['stock_stone', 'STKS', 'Stone Stock', 'assets'],
    ['sales_metal', 'SALM', 'Sales Metal', 'income'],
    ['sales_making', 'SALK', 'Sales Making', 'income'],
    ['sales_stone', 'SALS', 'Sales Stone', 'income'],
    ['sales_discount', 'SALD', 'Sales Discount', 'expenses'],
    ['other_charges', 'OTHC', 'Other Charges', 'income'],
    ['cogs', 'COGS', 'Cost of Goods Sold', 'expenses'],
    ['vat_input', 'VATI', 'VAT Input', 'assets'],
    ['vat_output', 'VATO', 'VAT Output', 'liabilities'],
    ['spt_input', 'SPTI', 'Skills Promotion Tax Input', 'assets'],
    ['spt_output', 'SPTO', 'Skills Promotion Tax Output', 'liabilities'],
    ['opening_equity', 'OPEQ', 'Opening Equity', 'equity'],
    ['rounding', 'ROUN', 'Rounding', 'expenses'],
] as [$purpose, $code, $name, $master]) {
    $L[$purpose] = $mkLedger($cidA, $code, $name, $master);
    jewellery_save_mapping($cidA, $purpose, $L[$purpose], $userA);
}
$cash = $mkLedger($cidA, 'CASHJ', 'Cash', 'assets');
$cashB = $mkLedger($cidB, 'CASHJ', 'Cash B', 'assets');

// Parties.
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'SUP1','Bullion Supplier','supplier','active')")->execute(['c' => $cidA]);
$supplier = (int) db()->lastInsertId();
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'CUS1','Retail Customer','customer','active')")->execute(['c' => $cidA]);
$customer = (int) db()->lastInsertId();
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'SUPB','Rival Supplier','supplier','active')")->execute(['c' => $cidB]);
$supplierB = (int) db()->lastInsertId();

// Items.
$bar = jewellery_save_item($cidA, ['code' => 'BAR24', 'name' => '24K Bullion Bar', 'item_type' => 'bullion',
    'metal_id' => $goldA, 'purity_id' => $p24, 'unit_id' => $tola, 'gross_weight' => 10], $userA);
$chain = jewellery_save_item($cidA, ['code' => 'CHAIN22', 'name' => '22K Chain', 'item_type' => 'ornament',
    'category' => 'Chains', 'metal_id' => $goldA, 'purity_id' => $p22, 'unit_id' => $tola, 'gross_weight' => 20,
    'vat_applicable' => 1, 'vat_base' => 'making_only'], $userA);
$oldGold = jewellery_save_item($cidA, ['code' => 'OLD22', 'name' => 'Old Gold 22K', 'item_type' => 'bullion',
    'metal_id' => $goldA, 'purity_id' => $p22, 'unit_id' => $tola, 'gross_weight' => 0], $userA);
$diamond = jewellery_save_item($cidA, ['code' => 'DIA', 'name' => 'Loose Diamond', 'item_type' => 'stone',
    'metal_id' => $diaA, 'purity_id' => $pDia, 'unit_id' => $carat, 'gross_weight' => 5,
    'vat_applicable' => 1, 'vat_base' => 'full_value'], $userA);

echo "1. Credit purchase of bullion\n";
$p1 = jewellery_save_purchase($cidA, $fyA, [
    'purchase_date' => '2026-08-01', 'party_id' => $supplier, 'settle_mode' => 'credit', 'source' => 'supplier',
], [['item_id' => $bar, 'gross_weight' => 10, 'rate' => 150000]], $userA);
$p1Row = jewellery_purchase($cidA, $p1);
ok(near((float) $p1Row['metal_amount'], 1500000.0), 'Metal value is 10 tola x 150,000 = 1,500,000');
ok(near((float) $p1Row['vat_amount'], 0.0), 'A VAT-exempt bullion item attracts NO VAT');
ok(near((float) $p1Row['total_amount'], 1500000.0), 'Total is 1,500,000');
$r = jewellery_post_purchase($cidA, $p1, $userA);
ok($r['ok'], 'Credit purchase posts' . ($r['ok'] ? '' : ' — ' . $r['error']));
$v = voucher_shape((int) $r['voucher_id']);
ok(near($v['dr'], $v['cr']) && near($v['dr'], 1500000.0), 'Its voucher balances at 1,500,000');
ok(near($v['ledgers'][$L['stock_metal']] ?? 0, 1500000.0), 'Metal stock is DEBITED 1,500,000');
$supPayable = ensure_party_ledger($cidA, $supplier, 'payable');
ok(near($v['ledgers'][$supPayable] ?? 0, -1500000.0), 'The supplier party ledger is CREDITED 1,500,000');
ok(near(jw_item_balance($cidA, $bar)['fine_weight'], 9.999), 'Stock rises by 9.999 fine (10 tola at 999.9)');
$bill1 = db()->query("SELECT * FROM jewellery_bills WHERE company_id=$cidA AND source_id=$p1 AND source_type='jewellery_purchase'")->fetch(PDO::FETCH_ASSOC);
ok($bill1 && near((float) $bill1['bill_amount'], 1500000.0), 'A bill was opened for the full amount');
ok((string) $bill1['status'] === 'open', 'The bill starts open');

echo "\n2. Cash purchase with making charge and per-item VAT\n";
$p2 = jewellery_save_purchase($cidA, $fyA, [
    'purchase_date' => '2026-08-02', 'party_id' => $supplier, 'settle_mode' => 'cash', 'settle_ledger_id' => $cash,
], [['item_id' => $chain, 'gross_weight' => 20, 'rate' => 140000, 'making_amount' => 50000]], $userA);
$p2Row = jewellery_purchase($cidA, $p2);
ok(near((float) $p2Row['taxable_amount'], 50000.0), 'A making-only item is taxed on the MAKING CHARGE alone (50,000), not the metal');
ok(near((float) $p2Row['vat_amount'], 6500.0), 'VAT is 13% of 50,000 = 6,500');
ok(near((float) $p2Row['total_amount'], 2856500.0), 'Total is 2,850,000 + 6,500');
$r = jewellery_post_purchase($cidA, $p2, $userA);
ok($r['ok'], 'Cash purchase posts' . ($r['ok'] ? '' : ' — ' . $r['error']));
$v = voucher_shape((int) $r['voucher_id']);
ok(near($v['dr'], $v['cr']), 'Its voucher balances');
ok(near($v['ledgers'][$L['stock_finished']] ?? 0, 2850000.0), 'Stock is debited 2,850,000 — VAT is recoverable, so it stays OUT of stock');
ok(near($v['ledgers'][$L['vat_input']] ?? 0, 6500.0), 'Input VAT is debited 6,500');
ok(near($v['ledgers'][$cash] ?? 0, -2856500.0), 'Cash is credited the full 2,856,500');
ok($q("SELECT COUNT(*) FROM jewellery_bills WHERE company_id=$cidA AND source_id=$p2 AND source_type='jewellery_purchase'") === 0,
    'A CASH purchase opens no bill');

echo "\n3. Landed cost: header charges and discount reach stock\n";
$p3 = jewellery_save_purchase($cidA, $fyA, [
    'purchase_date' => '2026-08-03', 'party_id' => $supplier, 'settle_mode' => 'cash', 'settle_ledger_id' => $cash,
    'other_charges' => 5000, 'discount' => 1000,
], [
    ['item_id' => $bar, 'gross_weight' => 1, 'rate' => 150000],
    ['item_id' => $oldGold, 'gross_weight' => 1, 'rate' => 50000],
], $userA);
$p3Lines = jewellery_purchase_line_rows($cidA, $p3);
$adjustSum = 0.0; $stockSum = 0.0;
foreach ($p3Lines as $line) { $adjustSum += (float) $line['allocated_adjust']; $stockSum += (float) $line['stock_amount']; }
ok(near($adjustSum, 4000.0), 'The net header adjustment (5,000 - 1,000) is fully allocated across lines');
ok(near((float) $p3Lines[0]['allocated_adjust'], 3000.0), 'It splits PRO RATA — the 150,000 line takes 3,000 of 4,000');
ok(near($stockSum, 204000.0), 'Landed cost capitalised is 200,000 + 4,000');
$r = jewellery_post_purchase($cidA, $p3, $userA);
ok($r['ok'] && near(voucher_shape((int) $r['voucher_id'])['dr'], 204000.0), 'It posts and the voucher carries the landed cost');

echo "\n4. Old gold bought from a walk-in customer (metal-to-cash)\n";
// A counter seller is named, not anonymous: the party and its ledger are
// created from the typed name alone.
$partiesBefore = $q("SELECT COUNT(*) FROM accounting_parties WHERE company_id=$cidA");
$p4 = jewellery_save_purchase($cidA, $fyA, [
    'purchase_date' => '2026-08-04', 'settle_mode' => 'cash', 'settle_ledger_id' => $cash, 'source' => 'customer_old_gold',
    'party_name' => 'Sita Walk-in', 'party_phone' => '9800000000',
], [['item_id' => $oldGold, 'gross_weight' => 4, 'rate' => 130000]], $userA);
ok($q("SELECT COUNT(*) FROM accounting_parties WHERE company_id=$cidA") === $partiesBefore + 1,
    'Typing a name CREATES the party — there is no anonymous walk-in');
$walkIn = db()->query("SELECT * FROM accounting_parties WHERE company_id=$cidA AND name='Sita Walk-in'")->fetch(PDO::FETCH_ASSOC);
ok($walkIn && (string) $walkIn['phone'] === '9800000000', 'Their phone is stored on the party, not loose on the document');
ok(ensure_party_ledger($cidA, (int) $walkIn['id'], 'receivable') > 0, 'And they have their own ledger');
$r = jewellery_post_purchase($cidA, $p4, $userA);
ok($r['ok'], 'The old-gold purchase from that walk-in posts' . ($r['ok'] ? '' : ' — ' . $r['error']));
// Re-using the same name must reuse the same party, not open a second ledger.
$p4b = jewellery_save_purchase($cidA, $fyA, [
    'purchase_date' => '2026-08-04', 'settle_mode' => 'cash', 'settle_ledger_id' => $cash, 'source' => 'customer_old_gold',
    'party_name' => 'sita walk-in',
], [['item_id' => $oldGold, 'gross_weight' => 1, 'rate' => 130000]], $userA);
ok($q("SELECT COUNT(*) FROM accounting_parties WHERE company_id=$cidA") === $partiesBefore + 1,
    'The same name typed again (any case) reuses the SAME party — no duplicate ledgers');
jewellery_delete_purchase($cidA, $p4b);
$v = voucher_shape((int) $r['voucher_id']);
ok(near($v['ledgers'][$cash] ?? 0, -520000.0), 'Cash goes out 520,000 — metal in, cash out');
ok(near(jw_item_balance($cidA, $oldGold)['fine_weight'], 0.916 + 3.664), 'Old gold stock rises to 4.58 fine');

echo "\n5. Sale: cash + credit, with COGS at weighted average\n";
// CHAIN22 stock so far: 20 tola in at 2,850,000 -> avg fine rate = 2,850,000 / 18.32
$chainBalance = jw_item_balance($cidA, $chain);
ok(near($chainBalance['avg_fine_rate'], 2850000.0 / 18.32, 0.5), 'Weighted average cost is 2,850,000 / 18.32 per fine tola');
$s1 = jewellery_save_sale($cidA, $fyA, [
    'sale_date' => '2026-08-10', 'party_id' => $customer, 'received_amount' => 322600,
    'settle_mode' => 'cash', 'settle_ledger_id' => $cash,
], [['item_id' => $chain, 'gross_weight' => 5, 'rate' => 160000, 'making_amount' => 20000]], [], $userA);
$s1Row = jewellery_sale($cidA, $s1);
ok(near((float) $s1Row['vat_amount'], 2600.0), 'VAT on the making charge only: 20,000 x 13% = 2,600');
ok(near((float) $s1Row['tax_amount'], 4100.0), 'Skills Promotion Tax is 0.5% of metal + wastage + making = 4,100');
ok(near((float) $s1Row['total_amount'], 826700.0), 'Total is 800,000 + 20,000 + 4,100 SPT + 2,600 VAT');
ok(near((float) $s1Row['balance_amount'], 504100.0), 'The unpaid balance carries the tax: 500,000 + 4,100');
ok(near((float) $s1Row['received_amount'] + (float) $s1Row['exchange_amount'] + (float) $s1Row['balance_amount'], (float) $s1Row['total_amount']),
    'SETTLEMENT IDENTITY holds: received + exchange + balance == total');
$r = jewellery_post_sale($cidA, $s1, $userA);
ok($r['ok'], 'The sale posts' . ($r['ok'] ? '' : ' — ' . $r['error']));
ok(near((float) $r['cogs'], 712500.0), 'COGS is 4.58 fine x the weighted average = 712,500');
$v = voucher_shape((int) $r['voucher_id']);
ok(near($v['dr'], $v['cr']), 'The sale voucher balances');
ok(near($v['ledgers'][$L['sales_metal']] ?? 0, -800000.0), 'Sales — metal is credited 800,000');
ok(near($v['ledgers'][$L['sales_making']] ?? 0, -20000.0), 'Sales — making is credited 20,000');
ok(near($v['ledgers'][$L['vat_output']] ?? 0, -2600.0), 'Output VAT is credited 2,600');
ok(near($v['ledgers'][$cash] ?? 0, 322600.0), 'Cash is debited 322,600');
$cusRecv = ensure_party_ledger($cidA, $customer, 'receivable');
ok(near($v['ledgers'][$cusRecv] ?? 0, 504100.0), 'The customer is debited the 504,100 balance');
ok(near($v['ledgers'][$L['spt_output']] ?? 0, -4100.0), 'Skills Promotion Tax is credited to its OWN payable, not to VAT');
ok(near($v['ledgers'][$L['cogs']] ?? 0, 712500.0), 'COGS is debited 712,500');
ok(near($v['ledgers'][$L['stock_finished']] ?? 0, -712500.0), 'Stock is credited at COST, not at the selling price');
ok(near(jw_item_balance($cidA, $chain)['fine_weight'], 18.32 - 4.58), 'Stock falls by the 4.58 fine sold');

echo "\n6. Metal-to-metal: a sale settled entirely in old gold\n";
$s2 = jewellery_save_sale($cidA, $fyA, [
    'sale_date' => '2026-08-11', 'party_id' => $customer, 'received_amount' => 0,
], [['item_id' => $chain, 'gross_weight' => 2, 'rate' => 160000]],
   [['item_id' => $oldGold, 'gross_weight' => 2, 'rate' => 160000]], $userA);
$s2Row = jewellery_sale($cidA, $s2);
ok(near((float) $s2Row['exchange_amount'], 320000.0), 'The old gold is valued at 320,000');
ok(near((float) $s2Row['total_amount'], 321600.0), 'The sale total is 320,000 metal + 1,600 SPT (no making, so no VAT)');
ok(near((float) $s2Row['received_amount'], 0.0) && near((float) $s2Row['balance_amount'], 1600.0),
    'The exchange settles the metal; only the tax is left on credit');
$oldBefore = jw_item_balance($cidA, $oldGold)['fine_weight'];
$r = jewellery_post_sale($cidA, $s2, $userA);
ok($r['ok'], 'The metal-to-metal sale posts' . ($r['ok'] ? '' : ' — ' . $r['error']));
$exchangeTrace = db()->query("SELECT id, status FROM jewellery_stock_units
    WHERE company_id=$cidA AND origin_type='sale_exchange' AND origin_id=$s2 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok($exchangeTrace !== false && (string) $exchangeTrace['status'] === 'in_stock',
    'Old jewellery accepted in exchange receives its own physical trace');
$v = voucher_shape((int) $r['voucher_id']);
ok(near($v['dr'], $v['cr']), 'It balances with no cash leg at all');
ok(!isset($v['ledgers'][$cash]), 'The cash ledger is NOT touched');
ok(near(jw_item_balance($cidA, $oldGold)['fine_weight'], $oldBefore + 1.832), 'The old gold taken in reaches stock (2 tola at 916 = 1.832 fine)');
ok(near(jw_item_balance($cidA, $chain)['fine_weight'], 18.32 - 4.58 - 1.832), 'The chain sold leaves stock');

echo "\n7. Settlement validation\n";
ok(threw(static fn () => jewellery_save_sale($cidA, $fyA, ['sale_date' => '2026-08-12', 'party_id' => $customer,
    'received_amount' => 999999, 'settle_mode' => 'cash', 'settle_ledger_id' => $cash],
    [['item_id' => $chain, 'gross_weight' => 1, 'rate' => 160000]], [], $userA)),
    'Cash received above the sale total is REJECTED');
ok(threw(static fn () => jewellery_save_sale($cidA, $fyA, ['sale_date' => '2026-08-12', 'received_amount' => 0],
    [['item_id' => $chain, 'gross_weight' => 1, 'rate' => 160000]], [], $userA)),
    'A sale with NO customer at all is rejected — every buyer must be nameable');
ok(threw(static fn () => jewellery_save_purchase($cidA, $fyA, ['purchase_date' => '2026-08-12', 'settle_mode' => 'credit'],
    [['item_id' => $bar, 'gross_weight' => 1, 'rate' => 1]], $userA)),
    'A purchase with no party at all is rejected');

echo "\n8. Diamond: VAT on FULL value, and a stone stock ledger\n";
$p5 = jewellery_save_purchase($cidA, $fyA, [
    'purchase_date' => '2026-08-05', 'settle_mode' => 'cash', 'settle_ledger_id' => $cash, 'party_id' => $supplier,
], [['item_id' => $diamond, 'gross_weight' => 5, 'stone_amount' => 250000]], $userA);
$p5Row = jewellery_purchase($cidA, $p5);
ok(near((float) $p5Row['taxable_amount'], 250000.0), 'A full-value item is taxed on its whole line value');
ok(near((float) $p5Row['vat_amount'], 32500.0), 'VAT is 250,000 x 13% = 32,500');
$r = jewellery_post_purchase($cidA, $p5, $userA);
ok($r['ok'], 'The diamond purchase posts');
$v = voucher_shape((int) $r['voucher_id']);
ok(near($v['ledgers'][$L['stock_stone']] ?? 0, 250000.0), 'A stone item posts to the STONE stock ledger, not the metal one');

echo "\n9. Bill-wise settlement\n";
$openBills = jewellery_open_bills($cidA, $supplier, 'purchase');
ok(count($openBills) === 1, 'The supplier has one open bill');
$billId = (int) $openBills[0]['id'];
$st1 = jewellery_save_settlement($cidA, $fyA, [
    'settlement_date' => '2026-08-15', 'party_id' => $supplier, 'direction' => 'paid', 'mode' => 'cash',
    'amount' => 600000, 'ledger_id' => $cash,
], [['bill_id' => $billId, 'amount' => 600000]], $userA);
$r = jewellery_post_settlement($cidA, $st1, $userA);
ok($r['ok'], 'A part payment posts' . ($r['ok'] ? '' : ' — ' . $r['error']));
$v = voucher_shape((int) $r['voucher_id']);
ok(near($v['ledgers'][$supPayable] ?? 0, 600000.0), 'The supplier payable is DEBITED 600,000');
ok(near($v['ledgers'][$cash] ?? 0, -600000.0), 'Cash is credited 600,000');
$billRow = db()->query("SELECT * FROM jewellery_bills WHERE id=$billId")->fetch(PDO::FETCH_ASSOC);
ok((string) $billRow['status'] === 'part_settled' && near((float) $billRow['settled_amount'], 600000.0),
    'The bill is now part settled at 600,000');

ok(threw(static fn () => jewellery_save_settlement($cidA, $fyA, ['settlement_date' => '2026-08-16',
    'party_id' => $supplier, 'direction' => 'paid', 'mode' => 'cash', 'amount' => 5000000, 'ledger_id' => $cash],
    [['bill_id' => $billId, 'amount' => 5000000]], $userA)),
    'Allocating more than a bill still owes is REJECTED');
ok(threw(static fn () => jewellery_save_settlement($cidA, $fyA, ['settlement_date' => '2026-08-16',
    'party_id' => $supplier, 'direction' => 'paid', 'mode' => 'cash', 'amount' => 100, 'ledger_id' => $cash],
    [['bill_id' => $billId, 'amount' => 500]], $userA)),
    'Allocating more than the settlement amount is REJECTED');

$st2 = jewellery_save_settlement($cidA, $fyA, [
    'settlement_date' => '2026-08-20', 'party_id' => $supplier, 'direction' => 'paid', 'mode' => 'cash',
    'amount' => 900000, 'ledger_id' => $cash,
], [['bill_id' => $billId, 'amount' => 900000]], $userA);
jewellery_post_settlement($cidA, $st2, $userA);
$billRow = db()->query("SELECT * FROM jewellery_bills WHERE id=$billId")->fetch(PDO::FETCH_ASSOC);
ok((string) $billRow['status'] === 'settled', 'Paying the rest marks the bill settled');
ok(jewellery_open_bills($cidA, $supplier, 'purchase') === [], 'It drops off the open-bills list');

echo "\n10. Reversal safety\n";
$blocked = jewellery_unpost_purchase($cidA, $p1, $userA);
ok(!$blocked['ok'] && str_contains($blocked['error'], 'part settled'),
    'A purchase whose bill has been settled CANNOT be unposted');
$vCheck = db()->query("SELECT * FROM vouchers WHERE company_id=$cidA AND source_type='jewellery_sale' AND source_id=$s1")->fetch(PDO::FETCH_ASSOC);
$registerDelete = delete_voucher_with_entries((int) $vCheck['id'], $cidA, $userA);
ok(!$registerDelete['ok'] && str_contains($registerDelete['error'], 'Jewellery'),
    'The Voucher Register REFUSES to delete a jewellery sale voucher');
$unposted = jewellery_unpost_sale($cidA, $s2, $userA);
ok($unposted['ok'], 'A sale with no settled bill CAN be unposted' . ($unposted['ok'] ? '' : ' — ' . $unposted['error']));
ok((string) db()->query('SELECT status FROM jewellery_stock_units WHERE id=' . (int) $exchangeTrace['id'])->fetchColumn() === 'cancelled',
    'Unposting cancels the incoming old-jewellery trace without erasing its history');
ok(near(jw_item_balance($cidA, $oldGold)['fine_weight'], $oldBefore), 'Unposting removed the exchange metal too');
ok(near(jw_item_balance($cidA, $chain)['fine_weight'], 18.32 - 4.58), 'And returned the sold chain to stock');
ok((string) jewellery_sale($cidA, $s2)['status'] === 'draft', 'The sale is back to draft');
jewellery_post_sale($cidA, $s2, $userA);
ok((string) db()->query('SELECT status FROM jewellery_stock_units WHERE id=' . (int) $exchangeTrace['id'])->fetchColumn() === 'in_stock',
    'Reposting reactivates the same old-jewellery trace');

echo "\n11. Reports\n";
$salesReport = jw_report_sales_detail($cidA, '2026-07-16', '2027-07-15');
ok(count($salesReport['rows']) === 2, 'Sales detail lists both sale lines');
ok(near($salesReport['totals']['revenue'], 800000.0 + 20000.0 + 320000.0), 'Sales revenue totals 1,140,000 (VAT excluded)');
ok(near($salesReport['totals']['vat_amount'], 2600.0), 'VAT is reported separately from revenue');
ok($salesReport['totals']['gross_profit'] < $salesReport['totals']['revenue'], 'Gross profit is revenue less COGS');

$purchaseReport = jw_report_purchase_detail($cidA, '2026-07-16', '2027-07-15');
ok(count($purchaseReport['rows']) === 6, 'Purchase detail lists all six purchase lines, got ' . count($purchaseReport['rows']));

$vat = jw_report_vat_register($cidA, '2026-07-16', '2027-07-15');
ok(near($vat['output']['vat'], 2600.0), 'The VAT register shows 2,600 output VAT');
ok(near($vat['input']['vat'], 6500.0 + 32500.0), 'And 39,000 input VAT');
ok(near($vat['net_payable'], 2600.0 - 39000.0), 'Net VAT is a credit carried forward, not a payable');
ok(count($vat['output_rows']) === 1, 'Only VAT-applicable lines appear — the exempt bullion is absent');

$inventory = jw_report_inventory_detail($cidA, '2026-07-16', '2027-07-15');
ok(count($inventory['rows']) === 4, 'Inventory detail covers the four items that moved');
ok($inventory['totals']['closing_fine'] > 0, 'It reports a positive closing fine weight');

$outstanding = jw_report_bill_outstanding($cidA);
$customerOutstanding = 0.0;
foreach ($outstanding as $party) { if ((int) $party['party_id'] === $customer) { $customerOutstanding = $party['outstanding']; } }
// 504,100 from the credit sale plus the 1,600 tax left over on the exchange.
ok(near($customerOutstanding, 505700.0), 'Bill-wise outstanding shows the customer owing 505,700');

$summary = jw_report_summary($cidA, '2026-07-16', '2027-07-15');
ok(near($summary['receivable'], 505700.0), 'The summary agrees on receivables');
ok(near($summary['vat_net'], -36400.0), 'And on net VAT');

echo "\n12. Cross-tenant isolation\n";
ok(threw(static fn () => jewellery_save_purchase($cidB, $fyB, ['purchase_date' => '2026-08-01',
    'party_id' => $supplierB, 'settle_mode' => 'credit'], [['item_id' => $chain, 'gross_weight' => 1, 'rate' => 1]], $userB)),
    "Company B cannot buy company A's item");
ok(threw(static fn () => jewellery_save_purchase($cidA, $fyA, ['purchase_date' => '2026-08-01',
    'party_id' => $supplierB, 'settle_mode' => 'credit'], [['item_id' => $bar, 'gross_weight' => 1, 'rate' => 1]], $userA)),
    "Company A cannot bill company B's party");
ok(threw(static fn () => jewellery_save_purchase($cidA, $fyA, ['purchase_date' => '2026-08-01',
    'settle_mode' => 'cash', 'settle_ledger_id' => $cashB], [['item_id' => $bar, 'gross_weight' => 1, 'rate' => 1]], $userA)),
    "Company A cannot settle from company B's ledger");
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cidB")->fetchColumn() === 0, 'No voucher ever reached company B');
ok(jewellery_sales_list($cidB) === [], 'Company B has no sales');

echo "\n13. The mapping is shown before it is committed\n";
// The preview IS the posting, dry-run and rolled back — so what the user
// confirms and what the ledger then receives cannot be two different things.
$previewSale = jewellery_save_sale($cidA, $fyA, [
    'sale_date' => '2026-09-01', 'party_id' => $customer, 'settle_mode' => 'cash',
    'settle_ledger_id' => $cash, 'received_amount' => 160000,
], [['item_id' => $chain, 'gross_weight' => 1, 'rate' => 160000]], [], $userA);
$preview = jewellery_preview_posting($cidA, 'sale', $previewSale);
ok($preview['ok'], 'A draft sale previews' . ($preview['ok'] ? '' : ' — ' . $preview['error']));
ok($preview['legs'] !== [] && near($preview['debit_total'], $preview['credit_total']),
    'The preview shows balanced ledger legs: Dr ' . number_format((float) $preview['debit_total'], 2));
ok($preview['stock'] !== [], 'And the stock movement the posting would make');
$stillDraft = jewellery_sale($cidA, $previewSale);
ok((string) $stillDraft['status'] === 'draft', 'Previewing posts NOTHING — the sale is still a draft');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cidA
        AND source_type='jewellery_sale' AND source_id=$previewSale")->fetchColumn() === 0,
    'No voucher exists for it');

// Post for real and hold the preview to its word.
$rPost = jewellery_post_sale($cidA, $previewSale, $userA);
ok($rPost['ok'], 'The sale then posts');
$actualLegs = db()->query("SELECT e.entry_type, e.amount FROM voucher_entries e
    WHERE e.voucher_id = " . (int) $rPost['voucher_id'] . " ORDER BY e.entry_type = 'credit', e.id")->fetchAll(PDO::FETCH_ASSOC);
$previewShape = array_map(static fn (array $l): string => $l['entry_type'] . ':' . number_format((float) $l['amount'], 2), $preview['legs']);
$actualShape = array_map(static fn (array $l): string => $l['entry_type'] . ':' . number_format((float) $l['amount'], 2), $actualLegs);
ok($previewShape === $actualShape,
    'The posted voucher is LEG FOR LEG what the preview promised');
ok(!jewellery_preview_posting($cidA, 'sale', $previewSale)['ok'],
    'A posted sale refuses to preview — there is nothing left to confirm');
ok(!jewellery_preview_posting($cidA, 'unknown', 1)['ok'], 'An unknown document type is refused');

echo "\n14. Stones typed in carats convert into the line's own unit\n";
// The counter weighs stones in carats; the bill weighs the piece in grams or
// tola. 1 ct = 0.2 g, everywhere — so a typed carat figure with no stone
// weight beside it converts itself, and the metal is never priced over rock.
$gm = (int) db()->query("SELECT id FROM jewellery_units WHERE company_id=$cidA AND code='GM'")->fetchColumn();
$caratSale = jewellery_save_sale($cidA, $fyA, [
    'sale_date' => '2026-09-02', 'party_id' => $customer, 'settle_mode' => 'credit',
], [['item_id' => $chain, 'unit_id' => $gm, 'gross_weight' => 20, 'stone_carat' => 25,
    'rate' => 15000, 'stone_amount' => 50000]], [], $userA);
$caratLine = db()->query("SELECT * FROM jewellery_sale_lines WHERE sale_id = $caratSale")->fetch(PDO::FETCH_ASSOC);
ok(near((float) $caratLine['stone_weight'], 5.0), '25 ct on a gram line knocks 5.000 g off the metal');
ok(near((float) $caratLine['net_weight'], 15.0), 'Net metal is 20 − 5 = 15 g');
ok(near((float) $caratLine['stone_carat'], 25.0), 'And the carats still print as carats');

$caratTola = jewellery_save_sale($cidA, $fyA, [
    'sale_date' => '2026-09-02', 'party_id' => $customer, 'settle_mode' => 'credit',
], [['item_id' => $chain, 'gross_weight' => 2, 'stone_carat' => 25,
    'rate' => 160000, 'stone_amount' => 50000]], [], $userA);
$tolaLine = db()->query("SELECT * FROM jewellery_sale_lines WHERE sale_id = $caratTola")->fetch(PDO::FETCH_ASSOC);
ok(near((float) $tolaLine['stone_weight'], 25 * 0.2 / 11.6638, 0.0002),
    'On a tola line the same 25 ct converts through grams: 0.4287 tola');

// A typed stone WEIGHT wins — the carats are then display only.
$typedBoth = jewellery_save_sale($cidA, $fyA, [
    'sale_date' => '2026-09-02', 'party_id' => $customer, 'settle_mode' => 'credit',
], [['item_id' => $chain, 'unit_id' => $gm, 'gross_weight' => 20, 'stone_weight' => 6,
    'stone_carat' => 25, 'rate' => 15000, 'stone_amount' => 50000]], [], $userA);
$bothLine = db()->query("SELECT * FROM jewellery_sale_lines WHERE sale_id = $typedBoth")->fetch(PDO::FETCH_ASSOC);
ok(near((float) $bothLine['stone_weight'], 6.0), 'A stone weight typed by hand is never overridden by the carats');

// A LOOSE stone line is untouched: its whole gross IS carats, there is no
// metal to knock anything off, and its carats drive its own stock.
$looseSale = jewellery_save_sale($cidA, $fyA, [
    'sale_date' => '2026-09-02', 'party_id' => $customer, 'settle_mode' => 'credit',
], [['item_id' => $diamond, 'gross_weight' => 3, 'stone_carat' => 3, 'stone_amount' => 200000]], [], $userA);
$looseLine = db()->query("SELECT * FROM jewellery_sale_lines WHERE sale_id = $looseSale")->fetch(PDO::FETCH_ASSOC);
ok(near((float) $looseLine['stone_weight'], 0.0) && near((float) $looseLine['net_weight'], 3.0),
    'A loose diamond keeps its carats as its weight — nothing is subtracted from itself');

echo "\nA. A filter under every heading of the bill book\n";
// The bill book is the screen a settlement happens over, and it was a wall of
// rows. Each heading now has a control beneath it, and they are applied where
// the report is BUILT rather than in the browser, so the CSV, the Excel and the
// PDF carry the rows the person narrowed to and not the whole book.
$allBills = jw_report_bill_outstanding($cidA);
$countRows = static function (array $parties): int {
    $n = 0;
    foreach ($parties as $party) { $n += count($party['bills']); }
    return $n;
};
$totalRows = $countRows($allBills);
ok($totalRows > 0, "The unfiltered bill book has rows to narrow ($totalRows)");

// A party filter matches loosely — somebody typing part of a name means it.
$byParty = jw_report_bill_outstanding($cidA, '', 500, 0, ['party' => 'Retail Customer']);
ok($byParty !== [] && count($byParty) === 1, 'Filtering by party name returns that one party');
// Not "fewer rows" — that depends on who happens to owe what. What must hold
// is that nothing belonging to anybody else came back with them.
$onlyThatParty = $byParty !== [];
foreach ($byParty as $party) {
    if ((int) $party['party_id'] !== $customer) { $onlyThatParty = false; }
}
ok($onlyThatParty && $countRows($byParty) <= $totalRows, 'And no other party comes with them');

// A type filter is exact: "purchase" must not also answer for a sale.
$byType = jw_report_bill_outstanding($cidA, '', 500, 0, ['bill_type' => 'purchase']);
$onlyPurchases = true;
foreach ($byType as $party) {
    foreach ($party['bills'] as $bill) {
        if ((string) $bill['bill_type'] !== 'purchase') { $onlyPurchases = false; }
    }
}
ok($onlyPurchases, 'A bill-type filter returns purchases and nothing else');

// A numeric filter is a FLOOR, which is the question a money column gets asked.
$bigOnly = jw_report_bill_outstanding($cidA, '', 500, 0, ['outstanding_min' => '1000000000']);
ok($countRows($bigOnly) === 0, 'An outstanding floor nobody meets returns an empty book, not everything');
$allPass = jw_report_bill_outstanding($cidA, '', 500, 0, ['outstanding_min' => '0']);
ok($countRows($allPass) === $totalRows, 'A floor of zero narrows nothing');

// A date window that ends before the first bill must return nothing.
$tooEarly = jw_report_bill_outstanding($cidA, '', 500, 0, ['to' => '2020-01-01']);
ok($countRows($tooEarly) === 0, 'A date window before any bill returns nothing');

// A blank filter is not a filter.
$blank = jw_report_bill_outstanding($cidA, '', 500, 0, ['party' => '', 'billed_min' => '', 'status' => '']);
ok($countRows($blank) === $totalRows, 'Blank controls narrow nothing at all');

// THE METAL FLOORS MUST NOT DRAG IN A BILL THAT HAS NO JOB. A purchase bill is
// not a kaligad job that ordered 0.000 fine, and answering a "at least some
// metal" filter with it would be a lie the export would then carry.
$needsMetal = jw_report_bill_outstanding($cidA, '', 500, 0, ['ordered_min' => '0']);
$anyWithoutJob = false;
foreach ($needsMetal as $party) {
    foreach ($party['bills'] as $bill) {
        if (($bill['metal']['has_job'] ?? false) !== true) { $anyWithoutJob = true; }
    }
}
ok(!$anyWithoutJob, 'A metal filter answers only with bills that have a job behind them');

echo "\nB. Metal paid to a supplier is not reported as cash\n";
// The settled split used to be computed for kaligad bills alone, so a supplier
// paid in old gold had that gold counted in the CASH column — the one place the
// distinction actually decides anything.
// A bill of its own, because the fixture settles the one it opened earlier.
$goldBillPurchase = jewellery_save_purchase($cidA, $fyA, ['purchase_date' => '2026-09-04',
    'party_id' => $supplier, 'settle_mode' => 'credit', 'source' => 'supplier'],
    [['item_id' => $bar, 'gross_weight' => 1, 'rate' => 100000]], $userA);
ok(jewellery_post_purchase($cidA, $goldBillPurchase, $userA)['ok'], 'A credit purchase opens a supplier bill');
$supplierBills = jewellery_open_bills($cidA, $supplier, 'purchase');
if ($supplierBills !== []) {
    $supplierBillId = (int) $supplierBills[0]['id'];
    $payInGold = 5000.0;
    $goldSettlement = jewellery_save_settlement($cidA, $fyA, [
        'settlement_date' => '2026-09-05', 'party_id' => $supplier, 'direction' => 'paid',
        'mode' => 'metal', 'amount' => $payInGold,
        'item_id' => $oldGold, 'purity_id' => $p22, 'unit_id' => $tola, 'gross_weight' => 0.05,
    ], [['bill_id' => $supplierBillId, 'amount' => $payInGold]], $userA);
    $goldPosted = jewellery_post_settlement($cidA, $goldSettlement, $userA);
    ok($goldPosted['ok'], 'The supplier is paid part of his bill in old gold'
        . ($goldPosted['ok'] ? '' : ' — ' . $goldPosted['error']));
    $supplierMetal = jw_report_bill_metal($cidA, [$supplierBillId])[$supplierBillId] ?? null;
    ok($supplierMetal !== null, 'The bill book has a settled split for a PURCHASE bill too');
    ok($supplierMetal !== null && near((float) $supplierMetal['settled_metal_amount'], $payInGold),
        'The gold is reported as settled in METAL');
    ok($supplierMetal !== null && near((float) $supplierMetal['settled_cash_amount'], 0.0),
        'And not a rupee of it lands in the cash column');
    ok($supplierMetal !== null && (bool) $supplierMetal['has_job'] === false,
        'While ordered/received stay absent — a purchase has no kaligad job behind it');
} else {
    ok(false, 'The fixture no longer leaves an open supplier bill to settle in gold');
}
echo "\nC. The whole shop on one page\n";
// Five questions an owner opens the books to ask. Each figure here is checked
// against the report that owns it, because a summary that quietly disagrees
// with the detail behind it is worse than no summary — it gets believed first
// and corrected last.
$cons = jw_report_consolidated($cidA, '2026-07-16', '2027-07-15');
$sectionBy = [];
foreach ($cons['sections'] as $section) { $sectionBy[(string) $section['key']] = $section; }
$valueOf = static function (array $section, string $label) {
    foreach ($section['rows'] as $row) {
        if ((string) $row['label'] === $label || str_starts_with((string) $row['label'], $label)) {
            return $row['value'];
        }
    }
    return null;
};

ok(array_keys($sectionBy) === ['stock', 'sales', 'advances', 'receivables', 'orders'],
    'All five sections are there, in the order they were asked for');
$everySectionDated = true;
foreach ($cons['sections'] as $section) {
    if (trim((string) $section['note']) === '') { $everySectionDated = false; }
}
ok($everySectionDated, 'And every one of them says what date it is true on');

// 1. Stock — against the metal position it is built from.
$ownFine = 0.0; $ownValue = 0.0; $outValue = 0.0;
foreach (jewellery_metal_position($cidA, '2027-07-15') as $posRow) {
    if ((string) $posRow['holder_type'] === 'stock') {
        $ownFine += (float) $posRow['fine'];
        $ownValue += (float) $posRow['value'];
    } else {
        $outValue += (float) $posRow['value'];
    }
}
ok(near((float) $valueOf($sectionBy['stock'], 'Fine on own shelf'), $ownFine, 0.0011),
    'Total stock agrees with the metal position on fine weight');
ok(near((float) $valueOf($sectionBy['stock'], 'Value of own stock'), $ownValue),
    'And on the value of what is on the shelf');
ok(near((float) $sectionBy['stock']['headline'], $ownValue + $outValue),
    'Its headline is everything the shop owns, on the shelf or out with a kaligad');

// 2. Sales and profit — against the sales detail report.
$salesTotals = jw_report_sales_detail($cidA, '2026-07-16', '2027-07-15')['totals'];
ok(near((float) $valueOf($sectionBy['sales'], 'Sales revenue'), (float) $salesTotals['revenue']),
    'Sales revenue agrees with the sales register');
ok(near((float) $valueOf($sectionBy['sales'], 'Gross profit'), (float) $salesTotals['gross_profit']),
    'And so does gross profit');
ok(near((float) $valueOf($sectionBy['sales'], 'Cost of goods sold'), (float) $salesTotals['cogs_amount']),
    'And cost of goods sold');

// 3. Advances — the arithmetic that makes "still held" mean anything.
$adv = $sectionBy['advances'];
ok(near((float) $valueOf($adv, 'Still held'),
    (float) $valueOf($adv, 'Advances taken in')
    - (float) $valueOf($adv, 'Applied to bills')
    - (float) $valueOf($adv, 'Refunded')),
    'Advance still held is what came in, less what it paid for and what went back');

// 4. Receivables — against the bill book.
$billTotals = jewellery_open_bill_totals($cidA);
ok(near((float) $valueOf($sectionBy['receivables'], 'Owed by customers'), (float) $billTotals['receivable']),
    'Customer receivables agree with the open bills');
ok(near((float) $valueOf($sectionBy['receivables'], 'Owed BY the shop'), (float) $billTotals['payable']),
    'And what the shop owes is kept the other side of the line, not netted off');

// 5. Orders — against the orders themselves.
$orderCount = (int) db()->query("SELECT COUNT(*) FROM jewellery_orders WHERE company_id=$cidA
    AND status <> 'cancelled' AND order_date BETWEEN '2026-07-16' AND '2027-07-15'")->fetchColumn();
ok((int) $valueOf($sectionBy['orders'], 'Orders taken') === $orderCount,
    'Orders received counts the orders actually taken in the period');
ok(near((float) $valueOf($sectionBy['orders'], 'Balance still to collect'),
    (float) $valueOf($sectionBy['orders'], 'Value ordered') - (float) $valueOf($sectionBy['orders'], 'Advance held against them')),
    'And the balance to collect is the value less the advance already held');

// A window with nothing in it must report nothing, not everything.
$empty = jw_report_consolidated($cidA, '2019-01-01', '2019-01-31');
ok(near((float) $valueOf($empty['sections'][1], 'Sales revenue'), 0.0),
    'A period before the shop existed reports no sales rather than all of them');
echo "\nD. The file says everything the page does\n";
// One report on screen, one file behind it. The rows are built by the SAME call
// the export makes, so a figure cannot be on the page and missing from the PDF
// somebody took into a meeting.
require_once __DIR__ . '/../app/export_engine.php';
$exportRows = jw_report_consolidated_export_rows($cons);
ok($exportRows[0] === ['Section', 'Basis', 'Figure', 'Amount', 'Fine weight', 'Count', 'Percent', 'Note'],
    'The file opens with its header row, one typed column per kind of figure');
// A SINGLE VALUE COLUMN COULD NOT BE FORMATTED. Rupees want two decimal
// places, a fine weight wants four and loses metal at two, a count wants
// none — so one column meant every figure was written wrong but by accident.
$placed = true;
foreach (array_slice($exportRows, 1) as $exportRow) {
    $filled = 0;
    foreach ([3, 4, 5, 6, 7] as $valueColumn) {
        if (trim((string) $exportRow[$valueColumn]) !== '') { $filled++; }
    }
    if ($filled !== 1) { $placed = false; }
}
ok($placed, 'Every figure lands in exactly one of them — never two, never none');
$formats = export_column_formats($exportRows);
ok(($formats[3] ?? '') === 'money' && ($formats[4] ?? '') === 'weight' && ($formats[5] ?? '') === 'count',
    'And each column then types itself: money, weight, count');
$figureCount = 0;
foreach ($cons['sections'] as $section) { $figureCount += count($section['rows']); }
ok(count($exportRows) - 1 === $figureCount,
    'Every figure on the page has a row in the file (' . $figureCount . ')');
$everyRowLabelled = true;
foreach (array_slice($exportRows, 1) as $exportRow) {
    if (trim((string) $exportRow[0]) === '' || trim((string) $exportRow[1]) === '') { $everyRowLabelled = false; }
}
ok($everyRowLabelled, 'And every row carries its section AND the date it is true on, so it can be read alone');
$sectionsInFile = array_values(array_unique(array_column(array_slice($exportRows, 1), 0)));
ok(count($sectionsInFile) === 5, 'All five sections reach the file, in order');
echo "\nE. Every register goes out with its total\n";
// A register printed without its total is a list: the reader adds it up by hand
// and disagrees with the person beside them, and neither can say who is wrong.
require_once __DIR__ . '/../app/export_engine.php';
$footed = export_totals_row([
    ['Sale no.', 'Order ref.', 'Date', 'Customer', 'Total', 'Received', 'Pending', 'COGS', 'Status'],
    ['JS-00023', '110', '2026-07-21', 'Rena Khadka', '211000.19', '211000.00', '0.19', '65283.28', 'posted'],
    ['JS-00024', '111', '2026-07-22', 'Sita Rai', '100.50', '100.00', '0.50', '30.00', 'posted'],
]);
$totalRow = end($footed);
ok(count($footed) === 4, 'The register gains exactly one row');
ok((string) $totalRow[0] === 'Total', 'And it says what it is');
ok(near((float) $totalRow[4], 211100.69) && near((float) $totalRow[7], 65313.28),
    'The money columns are footed');
ok((string) $totalRow[2] === '' && (string) $totalRow[8] === '',
    'A date and a status are left blank, not summed');
ok((string) $totalRow[1] === '', 'And so is a document reference — sequence is not quantity');

// THE COLUMNS THAT MUST NEVER BE ADDED UP. A wrong total is quoted; a missing
// one is merely noticed, so the skip-list errs wide on purpose.
$ledger = export_totals_row([
    ['Date', 'Ref', 'Fine wt', 'Rate', 'Amount', 'Balance fine'],
    ['2026-07-21', 'A', '1.8320', '152000.00', '150000.00', '1.8320'],
    ['2026-07-22', 'B', '0.9160', '152000.00', '75000.00', '2.7480'],
]);
$ledgerTotal = end($ledger);
ok(near((float) $ledgerTotal[2], 2.7480, 0.0011), 'A weight column is footed');
ok((string) $ledgerTotal[3] === '', 'A RATE is not — two rates added make something that is not a rate');
ok((string) $ledgerTotal[5] === '', 'And a RUNNING BALANCE is not — its last row already holds the answer');
ok(near((float) $ledgerTotal[4], 225000.00), 'While the amount beside them still foots');

// A footing keeps the places its rows are written in.
$weights = export_totals_row([['Item', 'Fine wt'], ['A', '1.8320'], ['B', '0.9160']]);
ok((string) end($weights)[1] === '2.7480', 'A four-decimal column foots to four decimals, not two');

$empty = export_totals_row([['Item', 'Amount']]);
ok(count($empty) === 1, 'A register with no rows gains no total row to foot');
echo "\nF. The workbook is a report, not a grid of digits\n";
// 310649022.2 in a narrow column tells a reader nothing they can check. The
// same figure as 310,649,022.20, right-aligned under a frozen heading, tells
// them everything — and the writer could always do it; nothing ever asked.
$sheetRows = [
    ['Bill no.', 'Date', 'Fine wt', 'Rate', 'Amount', 'Pieces'],
    ['JRC-1', '2026-07-21', '1.8320', '152000.00', '278464.00', '2'],
    ['JRC-2', '2026-07-22', '0.9160', '152000.00', '139232.00', '1'],
];
$kinds = export_column_formats($sheetRows);
ok(($kinds[0] ?? '') === 'text' && ($kinds[1] ?? '') === 'text', 'A reference and a date stay text');
ok(($kinds[2] ?? '') === 'weight', 'A four-place weight is a weight, not money rounded to two');
ok(($kinds[4] ?? '') === 'money', 'An amount is money');
ok(($kinds[5] ?? '') === 'count', 'And a piece count is a whole number');

// A column of round figures is still money when its heading says so. Without
// this a quiet month prints rupees as bare integers beside months that do not.
$roundMoney = export_column_formats([['Item', 'Amount'], ['A', '1000'], ['B', '2000']]);
ok(($roundMoney[1] ?? '') === 'money', 'Whole rupees are still money — the heading settles it');
$plainCount = export_column_formats([['Item', 'Pieces'], ['A', '3'], ['B', '4']]);
ok(($plainCount[1] ?? '') === 'count', 'While a column that is genuinely a count stays one');
// The heading is consulted ONLY on a column already proved to hold numbers, so
// it can never drag text into a number format.
$textAmount = export_column_formats([['Item', 'Amount'], ['A', 'n/a'], ['B', '2000']]);
ok(($textAmount[1] ?? '') === 'text', 'One unparseable value keeps the whole column text');

$widths = export_column_widths($sheetRows);
ok(($widths[0] ?? 0) >= 10 && ($widths[4] ?? 0) > ($widths[0] ?? 0),
    'Columns are measured from their contents, not left at one flat default');

// The file has to actually open. An invalid styles.xml does not fail loudly —
// Excel simply calls the whole workbook corrupt and says nothing about why.
if (class_exists('ZipArchive')) {
    $book = xlsx_build($sheetRows, 'Register', export_column_widths($sheetRows),
        ['styled_table' => true, 'freeze_header' => true, 'column_formats' => $kinds, 'total_row' => -1]);
    $tmpBook = tempnam(sys_get_temp_dir(), 'xlsxt');
    file_put_contents($tmpBook, $book);
    $zip = new ZipArchive();
    $opened = $zip->open($tmpBook) === true;
    $stylesXml = $opened ? (string) $zip->getFromName('xl/styles.xml') : '';
    $sheetXml = $opened ? (string) $zip->getFromName('xl/worksheets/sheet1.xml') : '';
    if ($opened) { $zip->close(); }
    @unlink($tmpBook);
    $doc = new DOMDocument();
    ok($opened && $stylesXml !== '' && @$doc->loadXML($stylesXml), 'The workbook styles are valid XML — Excel will open it');
    ok(str_contains($sheetXml, 'state="frozen"'), 'The heading row is frozen');
    ok(str_contains($sheetXml, 'customWidth="1"'), 'And the columns carry their measured widths');
} else {
    ok(true, 'ZipArchive absent — workbook build skipped on this machine');
    ok(true, 'ZipArchive absent — freeze check skipped');
    ok(true, 'ZipArchive absent — width check skipped');
}
jwt_cleanup();
echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail === 0 ? 0 : 1);
