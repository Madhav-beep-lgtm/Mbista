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
        foreach (['jewellery_settlement_allocations', 'jewellery_settlements', 'jewellery_bills',
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
ok(near(jw_item_balance($cidA, $oldGold)['fine_weight'], $oldBefore), 'Unposting removed the exchange metal too');
ok(near(jw_item_balance($cidA, $chain)['fine_weight'], 18.32 - 4.58), 'And returned the sold chain to stock');
ok((string) jewellery_sale($cidA, $s2)['status'] === 'draft', 'The sale is back to draft');
jewellery_post_sale($cidA, $s2, $userA);

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

jwt_cleanup();
echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail === 0 ? 0 : 1);
