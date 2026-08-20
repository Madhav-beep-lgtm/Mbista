<?php
declare(strict_types=1);

/**
 * Recording several purchases in one entry: validation as a set before anything
 * is written, the same stock layers and GL vouchers the single-line form makes,
 * all-or-nothing posting, VAT treatments including exempt and custom, optional
 * TDS, marking a line as a kitchen ingredient, and a fixed query cost for the
 * lookups however many lines the grid holds.
 *   php database/test_inventory_purchase_batch.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/inventory_purchase_batch.php';
require_once __DIR__ . '/../app/hospitality_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.011; }
function questions(): int { return (int) db()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value']; }

function ipb_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'IPBAT'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE e FROM voucher_entries e JOIN vouchers v ON v.id = e.voucher_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['inventory_ledger_mappings', 'inventory_cost_layers', 'inventory_transactions', 'inventory_items',
                  'hospitality_ingredients', 'hospitality_settings', 'accounting_parties', 'warehouses'] as $t) {
            if (table_exists($t) && column_exists($t, 'company_id')) { db()->exec("DELETE FROM `$t` WHERE company_id=$s"); }
        }
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email = 'ipbat@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
ipb_cleanup();

// ---------------------------------------------------------------- the fixture
db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n, :c, 1, 1)')
    ->execute(['n' => 'Batch Purchase Co (Books)', 'c' => 'IPBAT']);
$cid = (int) db()->lastInsertId();
$uid = create_user(['name' => 'Batch Owner', 'email' => 'ipbat@test.local', 'password' => 'Secret#12345', 'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, hospitality_accounting_enabled)
        VALUES (:uid, :cid, :books, :org, :code, 1, 1)')
    ->execute(['uid' => $uid, 'cid' => $cid, 'books' => $cid, 'org' => 'Batch Purchase Co', 'code' => 'IPBAT-C']);
$fy = create_fiscal_year($cid, 'IPBAT 2026/27', '2026-04-01', '2027-03-31', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId = (int) $fy['id'];
$_SESSION['company_id'] = $cid;
$_SESSION['user_id'] = (int) db()->query("SELECT id FROM users WHERE role IN ('admin','super_admin') ORDER BY id LIMIT 1")->fetchColumn();
set_context($cid, $fyId);

$mkLedger = static function (string $code, string $name, string $type) use ($cid): int {
    db()->prepare("INSERT INTO ledgers (company_id, code, name, type, status) VALUES (:cid,:code,:name,:type,'active')")
        ->execute(['cid' => $cid, 'code' => $code, 'name' => $name, 'type' => $type]);
    return (int) db()->lastInsertId();
};
$lStock = $mkLedger('IPB-1400', 'Inventory Asset', 'asset');
$lClearing = $mkLedger('IPB-2100', 'Purchase Clearing', 'liability');
$lCogs = $mkLedger('IPB-5000', 'Cost of Goods Sold', 'expense');
$lVat = $mkLedger('IPB-1500', 'VAT Receivable', 'asset');
$lTds = $mkLedger('IPB-2200', 'TDS Payable', 'liability');
$lEquity = $mkLedger('IPB-3000', 'Opening Equity', 'equity');
foreach ([['inventory_asset', $lStock], ['purchase_clearing', $lClearing], ['cogs', $lCogs], ['opening_equity', $lEquity]] as [$purpose, $ledgerId]) {
    db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, purpose, ledger_id) VALUES (:cid,'global',:p,:l)")
        ->execute(['cid' => $cid, 'p' => $purpose, 'l' => $ledgerId]);
}

db()->prepare("INSERT INTO accounting_parties (company_id, name, party_type, status) VALUES (:cid, 'ABC Pvt. Ltd.', 'supplier', 'active')")
    ->execute(['cid' => $cid]);
$supplierId = (int) db()->lastInsertId();

$mkItem = static function (string $sku, string $name, string $unit) use ($cid): int {
    db()->prepare("INSERT INTO inventory_items (company_id, sku, name, category, item_type, valuation_method, unit, purchase_rate, status)
        VALUES (:c,:s,:n,'Dairy','raw_material','weighted_average',:u,0,'active')")
        ->execute(['c' => $cid, 's' => $sku, 'n' => $name, 'u' => $unit]);
    return (int) db()->lastInsertId();
};
$milk = $mkItem('MILK', 'Milk', 'Litre');
$flour = $mkItem('FLOUR', 'Flour', 'KG');
$sugar = $mkItem('SUGAR', 'Sugar', 'KG');

echo "\n== Validation happens before anything is written ==\n";
$grid = [
    ['item_id' => $milk, 'movement' => 'purchase', 'transaction_date' => '2026-08-20', 'supplier_invoice_date' => '2026-08-17',
     'quantity' => 100, 'rate' => 20, 'vat_mode' => 'exempt', 'supplier_party_id' => $supplierId, 'ref_no' => 'ABC-9001'],
    ['item_id' => $flour, 'movement' => 'purchase', 'transaction_date' => '2026-08-20',
     'quantity' => 50, 'rate' => 60, 'vat_mode' => 'standard', 'supplier_party_id' => $supplierId,
     'vat_ledger_id' => $lVat, 'ref_no' => 'ABC-9001'],
    // blank spare line, which the grid always has
    ['item_id' => 0, 'quantity' => 0, 'rate' => 0],
];
$checked = inv_purchase_batch_validate($cid, $fyId, $grid);
ok($checked['valid'] === 2, 'Two filled lines are read, the blank spare is ignored');
ok($checked['errors'] === [], 'Nothing stops the batch');
ok(count(array_filter($checked['rows'], static fn ($r) => $r['errors'] !== [])) === 0, 'No line carries an error');
$milkRow = $checked['rows'][0];
ok(near((float) $milkRow['amount'], 2000.00), 'Amount is quantity times rate (100 x 20)');
ok(near((float) $milkRow['vat_amount'], 0.00), 'An exempted line carries no VAT');
ok((string) $milkRow['supplier_invoice_date'] === '2026-08-17', "The supplier's own invoice date is kept beside the posting date");
$flourRow = $checked['rows'][1];
ok(near((float) $flourRow['amount'], 3000.00), 'The second line is 50 x 60');
ok(near((float) $flourRow['vat_amount'], 390.00), '  ...and 13% of it is 390.00');

echo "\n== A bad line stops the whole grid ==\n";
$badGrid = $grid;
$badGrid[] = ['item_id' => $sugar, 'movement' => 'purchase', 'transaction_date' => '2026-08-20', 'quantity' => 0, 'rate' => 45];
$badChecked = inv_purchase_batch_validate($cid, $fyId, $badGrid);
ok(count(array_filter($badChecked['rows'], static fn ($r) => $r['errors'] !== [])) === 1, 'A line with no quantity is flagged');
$txnsBefore = (int) db()->query("SELECT COUNT(*) FROM inventory_transactions WHERE company_id=$cid")->fetchColumn();
$vouchersBefore = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid")->fetchColumn();
$refused = inv_purchase_batch_post($cid, $fyId, $badChecked, $uid);
ok($refused['ok'] === false, 'Posting is refused while any line is wrong');
// The blank spare still occupies a row of the grid, so the appended bad line
// is the fourth one the person is looking at.
ok(str_contains((string) $refused['error'], 'line 4'), '  ...naming the line as the grid numbers it');
ok((int) db()->query("SELECT COUNT(*) FROM inventory_transactions WHERE company_id=$cid")->fetchColumn() === $txnsBefore, '  ...and no stock moved');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid")->fetchColumn() === $vouchersBefore, '  ...and no voucher was posted');

echo "\n== Posting the grid ==\n";
$result = inv_purchase_batch_post($cid, $fyId, $checked, $uid);
ok($result['ok'] === true, 'A clean grid posts' . ($result['ok'] ? '' : ': ' . $result['error']));
ok((int) $result['posted'] === 2, 'Both lines are recorded');
$txns = db()->query("SELECT * FROM inventory_transactions WHERE company_id=$cid ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
ok(count($txns) === 2, 'Two stock movements exist');
ok(near((float) $txns[0]['qty_in'], 100.0) && near((float) $txns[0]['rate'], 20.0), 'The first carries 100 in at 20');
ok((string) $txns[0]['ref_no'] === 'ABC-9001', 'The reference is kept, so the bill can be found when it is paid');
ok((int) $txns[0]['voucher_id'] > 0 && (int) $txns[1]['voucher_id'] > 0, 'Each line got its own voucher');
ok((int) $txns[0]['voucher_id'] !== (int) $txns[1]['voucher_id'], '  ...a separate one per line');

echo "\n== The accounting is the same as entering them one at a time ==\n";
$allBalanced = true;
foreach ($txns as $txn) {
    $sums = db()->query("SELECT SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END) dr,
        SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END) cr
        FROM voucher_entries WHERE voucher_id=" . (int) $txn['voucher_id'])->fetch(PDO::FETCH_ASSOC);
    if (!near((float) $sums['dr'], (float) $sums['cr'])) { $allBalanced = false; }
}
ok($allBalanced, 'Every voucher balances');
$flourLegs = [];
foreach (db()->query("SELECT e.entry_type, e.amount, l.name FROM voucher_entries e JOIN ledgers l ON l.id=e.ledger_id
    WHERE e.voucher_id=" . (int) $txns[1]['voucher_id'])->fetchAll(PDO::FETCH_ASSOC) as $leg) {
    $flourLegs[$leg['entry_type'] . '|' . $leg['name']] = (float) $leg['amount'];
}
ok(near($flourLegs['debit|Inventory Asset'] ?? 0, 3000.00), 'Stock is debited at cost, VAT kept out of it');
ok(near($flourLegs['debit|VAT Receivable'] ?? 0, 390.00), 'VAT is debited to its own ledger');

echo "\n== Cost layers were built, so valuation is real ==\n";
$layerQty = (float) db()->query("SELECT COALESCE(SUM(qty_remaining),0) FROM inventory_cost_layers WHERE company_id=$cid AND item_id=$flour")->fetchColumn();
ok(near($layerQty, 50.0), 'The flour line laid down a cost layer of 50');
$flourItem = db()->query("SELECT * FROM inventory_items WHERE id=$flour")->fetch(PDO::FETCH_ASSOC);
$valuation = inv_item_valuation($cid, $flourItem);
ok(near((float) ($valuation['cost_value'] ?? 0), 3000.00), '  ...valued at 3,000.00');

echo "\n== Custom and typed VAT ==\n";
$vatGrid = [
    ['item_id' => $sugar, 'movement' => 'purchase', 'transaction_date' => '2026-08-21', 'quantity' => 10, 'rate' => 100,
     'vat_mode' => 'custom', 'vat_rate' => 5, 'vat_ledger_id' => $lVat],
    ['item_id' => $sugar, 'movement' => 'purchase', 'transaction_date' => '2026-08-21', 'quantity' => 10, 'rate' => 100,
     'vat_mode' => 'standard', 'vat_amount' => '129.99', 'vat_ledger_id' => $lVat],
    ['item_id' => $sugar, 'movement' => 'purchase', 'transaction_date' => '2026-08-21', 'quantity' => 10, 'rate' => 100,
     'vat_mode' => 'zero', 'vat_ledger_id' => $lVat],
];
$vatChecked = inv_purchase_batch_validate($cid, $fyId, $vatGrid);
ok(near((float) $vatChecked['rows'][0]['vat_amount'], 50.00), 'A custom rate of 5% on 1,000 gives 50.00');
ok(near((float) $vatChecked['rows'][1]['vat_amount'], 129.99), 'A VAT figure typed in wins over the rate');
ok(near((float) $vatChecked['rows'][2]['vat_amount'], 0.00), 'A zero-rated line carries no VAT');
$exemptWithVat = inv_purchase_batch_validate($cid, $fyId, [
    ['item_id' => $sugar, 'movement' => 'purchase', 'transaction_date' => '2026-08-21', 'quantity' => 1, 'rate' => 100,
     'vat_mode' => 'exempt', 'vat_amount' => '13'],
]);
ok($exemptWithVat['rows'][0]['errors'] !== [], 'An exempted line carrying VAT is refused');

echo "\n== TDS ==\n";
$tdsChecked = inv_purchase_batch_validate($cid, $fyId, [
    ['item_id' => $flour, 'movement' => 'purchase', 'transaction_date' => '2026-08-22', 'quantity' => 10, 'rate' => 100,
     'vat_mode' => 'zero', 'tds_rate' => 1.5, 'tds_ledger_id' => $lTds, 'supplier_party_id' => $supplierId],
]);
ok(near((float) $tdsChecked['rows'][0]['tds_base'], 1000.00), 'An omitted TDS base defaults to the line amount');
$tdsResult = inv_purchase_batch_post($cid, $fyId, $tdsChecked, $uid);
ok($tdsResult['ok'] === true, 'A line with withholding posts' . ($tdsResult['ok'] ? '' : ': ' . $tdsResult['error']));
$tdsLegs = [];
foreach (db()->query("SELECT e.entry_type, e.amount, l.name FROM voucher_entries e JOIN ledgers l ON l.id=e.ledger_id
    WHERE e.voucher_id=" . (int) $tdsResult['lines'][0]['voucher_id'])->fetchAll(PDO::FETCH_ASSOC) as $leg) {
    $tdsLegs[$leg['entry_type'] . '|' . $leg['name']] = (float) $leg['amount'];
}
ok(near($tdsLegs['credit|TDS Payable'] ?? 0, 15.00), 'Tax withheld is credited to its own ledger (1.5% of 1,000)');

echo "\n== A dated line outside any fiscal year is refused ==\n";
$outside = inv_purchase_batch_validate($cid, $fyId, [
    ['item_id' => $milk, 'movement' => 'purchase', 'transaction_date' => '2019-01-01', 'quantity' => 1, 'rate' => 1],
]);
ok($outside['rows'][0]['errors'] !== [], 'A date with no fiscal year is caught before posting');

echo "\n== Marking a line as a kitchen ingredient ==\n";
ok((int) db()->query("SELECT is_ingredient FROM inventory_items WHERE id=$milk")->fetchColumn() === 0, 'Milk starts as an ordinary item');
$ingGrid = inv_purchase_batch_validate($cid, $fyId, [
    ['item_id' => $milk, 'movement' => 'purchase', 'transaction_date' => '2026-08-23', 'quantity' => 20, 'rate' => 21,
     'vat_mode' => 'exempt', 'mark_ingredient' => '1'],
]);
$ingResult = inv_purchase_batch_post($cid, $fyId, $ingGrid, $uid);
ok($ingResult['ok'] === true, 'The line posts' . ($ingResult['ok'] ? '' : ': ' . $ingResult['error']));
ok((int) db()->query("SELECT is_ingredient FROM inventory_items WHERE id=$milk")->fetchColumn() === 1, 'Ticking the box marks the item as an ingredient');
ok((int) db()->query("SELECT COUNT(*) FROM hospitality_ingredients WHERE company_id=$cid AND inventory_item_id=$milk")->fetchColumn() === 1,
    '  ...and it reaches the kitchen list in the same go');

echo "\n== Shape: a long bill costs a fixed number of lookups ==\n";
$bigGrid = [];
for ($i = 0; $i < 60; $i++) {
    $bigGrid[] = ['item_id' => [$milk, $flour, $sugar][$i % 3], 'movement' => 'purchase',
        'transaction_date' => '2026-09-01', 'quantity' => 1 + $i, 'rate' => 10 + $i,
        'vat_mode' => 'zero', 'supplier_party_id' => $supplierId];
}
$q0 = questions();
$bigChecked = inv_purchase_batch_validate($cid, $fyId, $bigGrid);
$validateCost = questions() - $q0 - 2;
ok($bigChecked['valid'] === 60, '60 lines all validate');
ok($validateCost < 70, "  ...in $validateCost queries — items and suppliers are read once, not once per line");
$q0 = questions();
$bigResult = inv_purchase_batch_post($cid, $fyId, $bigChecked, $uid);
$postCost = questions() - $q0 - 2;
ok($bigResult['ok'] === true, 'The whole bill posts' . ($bigResult['ok'] ? '' : ': ' . $bigResult['error']));
ok((int) $bigResult['posted'] === 60, '  ...all 60 lines');
echo "        (posting 60 lines took $postCost queries)\n";

ipb_cleanup();

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass   FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
