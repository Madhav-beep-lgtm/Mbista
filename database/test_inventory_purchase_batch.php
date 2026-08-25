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
ok((int) $txns[0]['voucher_id'] > 0 && (int) $txns[1]['voucher_id'] > 0, 'Both lines reached the books');
// ONE invoice is ONE entry. Both lines carry bill ABC-9001 from the same
// supplier, so the supplier is owed once, in one place, not twice in two.
ok((int) $txns[0]['voucher_id'] === (int) $txns[1]['voucher_id'], '  ...through a single entry for the whole bill');
ok((int) $result['bills'] === 1, 'The grid reports one bill, not one per item');
$billVoucherId = (int) $txns[0]['voucher_id'];
$billVoucher = db()->query("SELECT * FROM vouchers WHERE id=$billVoucherId")->fetch(PDO::FETCH_ASSOC);
ok((string) $billVoucher['reference_no'] === 'ABC-9001', "The entry carries the supplier's bill number");
ok((int) $billVoucher['party_id'] === $supplierId, '  ...and the supplier it is owed to');
ok((string) $billVoucher['status'] === 'draft', 'Bought-in stock is prepared as a draft, to be read before it counts');
ok(near((float) $billVoucher['total_amount'], 5390.00), 'The entry totals the whole bill (2,000 + 3,000 + 390 VAT)');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid AND reference_no='ABC-9001'")->fetchColumn() === 1,
    'and there is exactly one voucher against that bill number');

echo "\n== The bill reads the way the invoice does ==\n";
$sums = db()->query("SELECT SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END) dr,
    SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END) cr
    FROM voucher_entries WHERE voucher_id=$billVoucherId")->fetch(PDO::FETCH_ASSOC);
ok(near((float) $sums['dr'], (float) $sums['cr']), 'The entry balances');
$billLegs = db()->query("SELECT e.entry_type, e.amount, e.memo, l.name FROM voucher_entries e JOIN ledgers l ON l.id=e.ledger_id
    WHERE e.voucher_id=$billVoucherId ORDER BY e.id")->fetchAll(PDO::FETCH_ASSOC);
$byLeg = [];
foreach ($billLegs as $leg) { $byLeg[$leg['entry_type'] . '|' . $leg['name']][] = (float) $leg['amount']; }
$stockDebits = $byLeg['debit|Inventory Asset'] ?? [];
ok(count($stockDebits) === 2, 'Stock is debited once per ITEM, so the entry still shows what was bought');
ok(near(array_sum($stockDebits), 5000.00), '  ...2,000 of milk and 3,000 of flour, VAT kept out of both');
$memos = implode(' | ', array_map(static fn (array $l): string => (string) $l['memo'], $billLegs));
ok(str_contains($memos, 'MILK') && str_contains($memos, 'FLOUR'), '  ...each naming its item on the line');
ok(str_contains($memos, '100.000 @ 20.00'), '  ...with the quantity and the rate that made the figure');
ok(count($byLeg['debit|VAT Receivable'] ?? []) === 1 && near(array_sum($byLeg['debit|VAT Receivable'] ?? []), 390.00),
    'VAT is debited once for the bill, to its own ledger');
$payableLegs = [];
foreach ($billLegs as $leg) {
    if ((string) $leg['entry_type'] === 'credit') { $payableLegs[] = $leg; }
}
ok(count($payableLegs) === 1, 'The supplier is credited exactly once');
ok(near((float) $payableLegs[0]['amount'], 5390.00), '  ...with the whole bill, VAT included');

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

echo "\n== VAT and TDS as tick marks ==\n";
// The grid asks the question the way a bill reads it: nearly every line carries
// VAT, so the box is ticked and the exempt ones are un-ticked. TDS is the other
// way round -- off unless somebody says otherwise.
$tickGrid = inv_purchase_batch_validate($cid, $fyId, [
    // ticked, no rate typed => the standard rate
    ['item_id' => $sugar, 'movement' => 'purchase', 'transaction_date' => '2026-08-24', 'quantity' => 10, 'rate' => 100,
     'vat_applicable' => '1', 'vat_ledger_id' => $lVat],
    // un-ticked => exempt, whatever else is on the row
    ['item_id' => $sugar, 'movement' => 'purchase', 'transaction_date' => '2026-08-24', 'quantity' => 10, 'rate' => 100,
     'vat_applicable' => '0', 'vat_rate' => '13', 'vat_ledger_id' => $lVat],
    // ticked WITH a rate => that rate, for a partial exemption
    ['item_id' => $sugar, 'movement' => 'purchase', 'transaction_date' => '2026-08-24', 'quantity' => 10, 'rate' => 100,
     'vat_applicable' => '1', 'vat_rate' => '5', 'vat_ledger_id' => $lVat],
]);
ok(near((float) $tickGrid['rows'][0]['vat_amount'], 130.00), 'A ticked line carries VAT at the standard rate');
ok((string) $tickGrid['rows'][0]['vat_mode'] === 'standard', '  ...as a standard-rated line');
ok(near((float) $tickGrid['rows'][1]['vat_amount'], 0.00), 'An un-ticked line is exempt');
ok((string) $tickGrid['rows'][1]['vat_mode'] === 'exempt', '  ...even with a rate left in the box beside it');
ok($tickGrid['rows'][1]['errors'] === [], '  ...and that is not an error, it is the point of the tick');
ok(near((float) $tickGrid['rows'][2]['vat_amount'], 50.00), 'A rate typed beside a ticked box wins (5% of 1,000)');

$tdsTicks = inv_purchase_batch_validate($cid, $fyId, [
    ['item_id' => $sugar, 'movement' => 'purchase', 'transaction_date' => '2026-08-24', 'quantity' => 10, 'rate' => 100,
     'vat_applicable' => '0', 'tds_applicable' => '1', 'tds_rate' => '1.5', 'tds_ledger_id' => $lTds, 'supplier_party_id' => $supplierId],
    ['item_id' => $sugar, 'movement' => 'purchase', 'transaction_date' => '2026-08-24', 'quantity' => 10, 'rate' => 100,
     'vat_applicable' => '0', 'tds_applicable' => '0', 'tds_rate' => '1.5', 'tds_ledger_id' => $lTds, 'supplier_party_id' => $supplierId],
]);
ok(near((float) $tdsTicks['rows'][0]['tds_rate'], 1.5), 'A ticked TDS line withholds');
ok(near((float) $tdsTicks['rows'][0]['tds_base'], 1000.00), '  ...on the whole line by default');
ok(near((float) $tdsTicks['rows'][1]['tds_rate'], 0.00), 'An un-ticked TDS line withholds nothing');
ok(near((float) $tdsTicks['rows'][1]['tds_base'], 0.00), '  ...whatever rate was left in the box');

// The old dropdown still works, so nothing that posted before stops posting.
$legacy = inv_purchase_batch_validate($cid, $fyId, [
    ['item_id' => $sugar, 'movement' => 'purchase', 'transaction_date' => '2026-08-24', 'quantity' => 10, 'rate' => 100,
     'vat_mode' => 'exempt'],
]);
ok((string) $legacy['rows'][0]['vat_mode'] === 'exempt', 'A row sent the old way, naming its vat_mode, still works');

$tickPost = inv_purchase_batch_post($cid, $fyId, $tickGrid, $uid);
ok($tickPost['ok'] === true, 'A ticked grid posts' . ($tickPost['ok'] ? '' : ': ' . $tickPost['error']));

// Ticking VAT without saying where it posts is refused rather than silently
// dropped -- the tick is a claim that the line carries tax.
$noLedger = inv_purchase_batch_validate($cid, $fyId, [
    ['item_id' => $sugar, 'movement' => 'purchase', 'transaction_date' => '2026-08-25', 'quantity' => 1, 'rate' => 100,
     'vat_applicable' => '1'],
]);
$noLedgerPost = inv_purchase_batch_post($cid, $fyId, $noLedger, $uid);
ok($noLedgerPost['ok'] === false, 'Ticking VAT with no VAT ledger is refused, not quietly dropped');


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

echo "\n== A bill is one header and many items ==\n";
// A supplier's invoice is one date, one movement, one bill number and one
// supplier, with several items under it. The form is shaped that way and folded
// out here, so the header is copied onto each line by code rather than re-typed
// by a person -- which is how a bill ends up split across two accounts.
$oneBill = [[
    'transaction_date' => '2026-08-26', 'supplier_invoice_date' => '2026-08-22',
    'movement' => 'purchase', 'ref_no' => 'ABC-7700', 'supplier_party_id' => $supplierId,
    'vat_ledger_id' => $lVat, 'tds_ledger_id' => $lTds, 'notes' => 'whole bill',
    'items' => [
        // milk: exempt
        ['item_id' => $milk, 'quantity' => 100, 'rate' => 20, 'vat_applicable' => '0'],
        // flour: standard rated
        ['item_id' => $flour, 'quantity' => 50, 'rate' => 60, 'vat_applicable' => '1'],
        // sugar: standard AND withheld, with its own note
        ['item_id' => $sugar, 'quantity' => 10, 'rate' => 100, 'vat_applicable' => '1',
         'tds_applicable' => '1', 'tds_rate' => '1.5', 'notes' => 'this line only'],
        // the spare the form always carries
        ['item_id' => 0, 'quantity' => 0, 'rate' => 0],
    ],
]];
$foldedRows = inv_purchase_bills_to_rows($oneBill);
ok(count($foldedRows) === 3, 'Three items become three lines, the spare dropped');
ok(count(array_unique(array_column($foldedRows, 'ref_no'))) === 1, 'The bill number is on every line');
ok(count(array_unique(array_column($foldedRows, 'supplier_party_id'))) === 1, 'So is the supplier');
ok(count(array_unique(array_column($foldedRows, 'transaction_date'))) === 1, 'So is the date');
ok((string) $foldedRows[2]['notes'] === 'this line only', "A line's own note wins over the bill's");
ok((string) $foldedRows[0]['notes'] === 'whole bill', "  ...and a line without one takes the bill's");

$foldedChecked = inv_purchase_batch_validate($cid, $fyId, $foldedRows);
ok($foldedChecked['valid'] === 3, 'All three validate');
ok(near((float) $foldedChecked['rows'][0]['vat_amount'], 0.00), 'The exempt item carries no VAT');
ok(near((float) $foldedChecked['rows'][1]['vat_amount'], 390.00), 'The standard one carries 13% (390.00 on 3,000)');
ok(near((float) $foldedChecked['rows'][2]['tds_rate'], 1.5), 'Only the item marked for it withholds');
ok(near((float) $foldedChecked['rows'][0]['tds_rate'], 0.00), '  ...and the others do not');

$foldedResult = inv_purchase_batch_post($cid, $fyId, $foldedChecked, $uid);
ok($foldedResult['ok'] === true, 'The bill posts' . ($foldedResult['ok'] ? '' : ': ' . $foldedResult['error']));
ok((int) $foldedResult['posted'] === 3, '  ...as three movements, one per item');
$foldedRefs = db()->query("SELECT DISTINCT ref_no FROM inventory_transactions
    WHERE company_id=$cid AND ref_no='ABC-7700'")->fetchAll(PDO::FETCH_COLUMN);
ok(count($foldedRefs) === 1, '  ...all tied together by the one bill number');

echo "\n== Several bills at once ==\n";
$twoBills = [
    ['transaction_date' => '2026-08-27', 'movement' => 'purchase', 'ref_no' => 'BILL-A', 'supplier_party_id' => $supplierId,
     'vat_ledger_id' => $lVat, 'items' => [['item_id' => $milk, 'quantity' => 5, 'rate' => 10, 'vat_applicable' => '0']]],
    ['transaction_date' => '2026-08-27', 'movement' => 'purchase', 'ref_no' => 'BILL-B', 'supplier_party_id' => $supplierId,
     'vat_ledger_id' => $lVat, 'items' => [
        ['item_id' => $flour, 'quantity' => 2, 'rate' => 50, 'vat_applicable' => '1'],
        ['item_id' => $sugar, 'quantity' => 3, 'rate' => 40, 'vat_applicable' => '1'],
     ]],
];
$twoRows = inv_purchase_bills_to_rows($twoBills);
ok(count($twoRows) === 3, 'Two bills of one and two items make three lines');
ok((string) $twoRows[0]['ref_no'] === 'BILL-A' && (string) $twoRows[2]['ref_no'] === 'BILL-B',
    '  ...each keeping its own bill number');
$twoChecked = inv_purchase_batch_validate($cid, $fyId, $twoRows);
ok($twoChecked['valid'] === 3, '  ...and all of them validate');

// A bill with no items at all is not an error; the form always shows a spare.
ok(inv_purchase_bills_to_rows([['transaction_date' => '2026-08-27', 'items' => []]]) === [],
    'A bill nobody filled in contributes nothing');
ok(inv_purchase_bills_to_rows([]) === [], 'And no bills at all is simply no rows');


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


// ===========================================================================
// A bill after it is entered
// ===========================================================================
echo "\n== The register reads one row per bill ==\n";
$listed = inv_purchase_bill_list($cid, 25);
ok($listed !== [], 'Purchase entries are listed');
$abc = null;
foreach ($listed as $listedBill) {
    if ((string) $listedBill['reference_no'] === 'ABC-9001') { $abc = $listedBill; }
}
ok($abc !== null, 'Bill ABC-9001 is one row');
ok((int) $abc['item_count'] === 2, '  ...carrying both its items');
ok(count($abc['lines']) === 4, '  ...and the whole entry: 2 stock lines, VAT, and the supplier');
ok((string) $abc['party_name'] === 'ABC Pvt. Ltd.', '  ...named with the supplier it is owed to');

echo "\n== Deleting a bill takes the stock and the value with it ==\n";
$delItem = $mkItem('DELME', 'Deletable', 'KG');
$delChecked = inv_purchase_batch_validate($cid, $fyId, [
    ['item_id' => $delItem, 'movement' => 'purchase', 'transaction_date' => '2026-09-10', 'quantity' => 5, 'rate' => 100,
     'vat_mode' => 'zero', 'supplier_party_id' => $supplierId, 'ref_no' => 'DEL-1'],
    ['item_id' => $delItem, 'movement' => 'purchase', 'transaction_date' => '2026-09-10', 'quantity' => 3, 'rate' => 100,
     'vat_mode' => 'zero', 'supplier_party_id' => $supplierId, 'ref_no' => 'DEL-1'],
]);
$delPosted = inv_purchase_batch_post($cid, $fyId, $delChecked, $uid);
ok($delPosted['ok'] === true && (int) $delPosted['bills'] === 1, 'A two-item bill is recorded as one entry');
$delVoucherId = (int) $delPosted['lines'][0]['voucher_id'];
ok((float) db()->query("SELECT COALESCE(SUM(qty_remaining),0) FROM inventory_cost_layers WHERE company_id=$cid AND item_id=$delItem")->fetchColumn() == 8.0,
    '  ...laying down 8 units of cost layer');
$removed = inv_purchase_bill_delete($cid, $delVoucherId, $uid);
ok($removed['ok'] === true, 'The bill deletes' . ($removed['ok'] ? '' : ': ' . $removed['error']));
ok((int) $removed['items'] === 2, '  ...reporting both items');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE id=$delVoucherId")->fetchColumn() === 0, '  ...the entry is gone');
ok((int) db()->query("SELECT COUNT(*) FROM voucher_entries WHERE voucher_id=$delVoucherId")->fetchColumn() === 0, '  ...its lines with it');
ok((int) db()->query("SELECT COUNT(*) FROM inventory_transactions WHERE company_id=$cid AND item_id=$delItem")->fetchColumn() === 0,
    '  ...and both stock movements');
ok((float) db()->query("SELECT COALESCE(SUM(qty_remaining),0) FROM inventory_cost_layers WHERE company_id=$cid AND item_id=$delItem")->fetchColumn() == 0.0,
    '  ...leaving no value behind in the cost layers');
ok(inv_purchase_bill_delete($cid, $delVoucherId, $uid)['ok'] === false, 'Deleting it twice is refused');

echo "\n== Stock already issued cannot be un-bought ==\n";
$issuedItem = $mkItem('ISSUED', 'Issued Out', 'KG');
$issuedChecked = inv_purchase_batch_validate($cid, $fyId, [
    ['item_id' => $issuedItem, 'movement' => 'purchase', 'transaction_date' => '2026-09-11', 'quantity' => 10, 'rate' => 50,
     'vat_mode' => 'zero', 'ref_no' => 'ISS-1'],
]);
$issuedPosted = inv_purchase_batch_post($cid, $fyId, $issuedChecked, $uid);
$issuedVoucherId = (int) $issuedPosted['lines'][0]['voucher_id'];
db()->prepare('INSERT INTO inventory_transactions (company_id, fiscal_year_id, item_id, transaction_type, transaction_date, qty_in, qty_out, rate, amount)
        VALUES (?,?,?,?,?,0,?,?,?)')
    ->execute([$cid, $fyId, $issuedItem, 'sale', '2026-09-20', 9, 50, 450]);
inv_apply_movement($cid, $issuedItem, 0.0, 9.0, 50.0, '2026-09-20', 'weighted_average');
$blocked = inv_purchase_bill_delete($cid, $issuedVoucherId, $uid);
ok($blocked['ok'] === false, 'A bill whose stock has been sold on is not deleted');
ok(str_contains((string) $blocked['error'], 'already been issued'), '  ...and says why, naming the item');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE id=$issuedVoucherId")->fetchColumn() === 1, '  ...leaving the entry standing');

echo "\n== Bills already entered one voucher per item are gathered back up ==\n";
// Exactly the shape the old code left behind: one movement, one voucher, one
// item, all carrying the same bill number and supplier.
$splitItems = [$mkItem('SPL1', 'Split One', 'KG'), $mkItem('SPL2', 'Split Two', 'KG'), $mkItem('SPL3', 'Split Three', 'KG')];
$splitVoucherIds = [];
foreach ($splitItems as $index => $splitItemId) {
    $qty = 2 + $index;
    $rate = 100;
    db()->prepare('INSERT INTO inventory_transactions (company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date, qty_in, qty_out, rate, amount)
            VALUES (?,?,?,?,?,?,?,0,?,?)')
        ->execute([$cid, $fyId, $splitItemId, 'purchase', 'SPLIT-77', '2026-09-15', $qty, $rate, $qty * $rate]);
    $splitTxnId = (int) db()->lastInsertId();
    inv_apply_movement($cid, $splitItemId, (float) $qty, 0.0, (float) $rate, '2026-09-15', 'weighted_average', $splitTxnId);
    $splitItem = db()->query("SELECT * FROM inventory_items WHERE id=$splitItemId")->fetch(PDO::FETCH_ASSOC);
    $splitVoucherId = inv_post_movement_voucher($cid, $fyId, $splitTxnId, 'purchase', $splitItem, 'in',
        (float) ($qty * $rate), '2026-09-15', $uid, $supplierId,
        ['draft' => true, 'vat' => 0.0, 'tds' => 0.0, 'posting_date' => '2026-09-15', 'reference_no' => 'SPLIT-77']);
    db()->prepare('UPDATE inventory_transactions SET voucher_id=? WHERE id=?')->execute([$splitVoucherId, $splitTxnId]);
    $splitVoucherIds[] = (int) $splitVoucherId;
}
$glBefore = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN e.entry_type='debit' THEN e.amount ELSE -e.amount END),0)
    FROM voucher_entries e JOIN vouchers v ON v.id=e.voucher_id
    WHERE v.company_id=$cid AND v.reference_no='SPLIT-77'")->fetchColumn();
$totalBefore = (float) db()->query("SELECT COALESCE(SUM(total_amount),0) FROM vouchers WHERE company_id=$cid AND reference_no='SPLIT-77'")->fetchColumn();
ok(count($splitVoucherIds) === 3, 'Three separate vouchers carry one bill, as the old code left them');
ok(near($totalBefore, 900.00), '  ...worth 900 between them (200 + 300 + 400)');

$plan = inv_purchase_bill_merge_plan($cid);
$splitPlan = null;
foreach ($plan as $planRow) {
    if ((string) $planRow['ref_no'] === 'SPLIT-77') { $splitPlan = $planRow; }
}
ok($splitPlan !== null, 'The merge preview finds that bill');
ok((int) $splitPlan['vouchers'] === 3 && (int) $splitPlan['items'] === 3, '  ...as 3 vouchers holding 3 items');
ok(near((float) $splitPlan['total'], 900.00), '  ...and says what they come to before anything moves');
ok(count($splitPlan['absorb']) === 2, '  ...naming the two it would absorb into the first');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid AND reference_no='SPLIT-77'")->fetchColumn() === 3,
    '  ...and the preview itself writes nothing');

$mergeResult = inv_purchase_bill_merge($cid, (int) $splitPlan['keep'], $splitPlan['absorb'], $uid);
ok($mergeResult['ok'] === true, 'The merge runs' . ($mergeResult['ok'] ? '' : ': ' . $mergeResult['error']));
ok((int) $mergeResult['absorbed'] === 2, '  ...absorbing two vouchers');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid AND reference_no='SPLIT-77'")->fetchColumn() === 1,
    'Bill SPLIT-77 is now one entry');
$mergedVoucherId = (int) $splitPlan['keep'];
ok((int) db()->query("SELECT COUNT(*) FROM inventory_transactions WHERE company_id=$cid AND voucher_id=$mergedVoucherId")->fetchColumn() === 3,
    '  ...with all three stock movements pointing at it');
$glAfter = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN e.entry_type='debit' THEN e.amount ELSE -e.amount END),0)
    FROM voucher_entries e JOIN vouchers v ON v.id=e.voucher_id
    WHERE v.company_id=$cid AND v.reference_no='SPLIT-77'")->fetchColumn();
ok(near($glAfter, $glBefore), 'The ledger carries exactly what it carried before (nothing was recalculated)');
ok(near((float) db()->query("SELECT total_amount FROM vouchers WHERE id=$mergedVoucherId")->fetchColumn(), 900.00),
    '  ...and the surviving entry totals the whole bill');
$mergedLines = db()->query("SELECT e.entry_type, e.amount, e.memo, l.name FROM voucher_entries e JOIN ledgers l ON l.id=e.ledger_id
    WHERE e.voucher_id=$mergedVoucherId ORDER BY e.id")->fetchAll(PDO::FETCH_ASSOC);
$mergedDebits = array_filter($mergedLines, static fn (array $l): bool => (string) $l['entry_type'] === 'debit');
$mergedCredits = array_filter($mergedLines, static fn (array $l): bool => (string) $l['entry_type'] === 'credit');
ok(count($mergedDebits) === 3, 'The stock stays one line per item, so the entry still shows what was bought');
ok(count($mergedCredits) === 1, 'The supplier is credited once, not three times');
ok(near(array_sum(array_map(static fn (array $l): float => (float) $l['amount'], $mergedCredits)), 900.00),
    '  ...for the whole bill');
$mergedMemos = implode(' | ', array_map(static fn (array $l): string => (string) $l['memo'], $mergedLines));
ok(str_contains($mergedMemos, 'SPL1') && str_contains($mergedMemos, 'SPL3'), '  ...each debit still naming its item');
ok(str_contains((string) db()->query("SELECT narration FROM vouchers WHERE id=$mergedVoucherId")->fetchColumn(), 'Merged from'),
    'The absorbed voucher numbers are written into the narration, so the gap in the series has a reason');
ok(inv_purchase_bill_merge_plan($cid) === array_values(array_filter(inv_purchase_bill_merge_plan($cid),
    static fn (array $g): bool => (string) $g['ref_no'] !== 'SPLIT-77')), 'And it no longer appears in the plan');

echo "\n== A bill that is already one entry is left alone ==\n";
$singlePlan = inv_purchase_bill_merge_plan($cid);
$abcInPlan = false;
foreach ($singlePlan as $planRow) {
    if ((string) $planRow['ref_no'] === 'ABC-9001') { $abcInPlan = true; }
}
ok(!$abcInPlan, 'A bill entered as one entry is never offered for merging');
$crossBill = inv_purchase_bill_merge($cid, $mergedVoucherId, [$issuedVoucherId], $uid);
ok($crossBill['ok'] === false, 'Two different bills are refused');
ok(str_contains((string) $crossBill['error'], 'not the same bill'), '  ...saying the reference, supplier, date and state must match');

echo "\n== The register itself ==\n";
// The engine tests prove the figures. This proves the PAGE, which is where the
// three new buttons live and where a <form> inside a <form> would silently
// stop the counted lines being sent at all.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin/accounting-inventory.php';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SESSION['user_id'] = (int) db()->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
$_SESSION['company_id'] = $cid;
$_SESSION['fiscal_year_id'] = $fyId;
mark_company_pin_verified($cid);
// The page declares functions, so it can only be included ONCE in a process.
// It is rendered in its edit view, which draws the register AND the form
// filled back in from a recorded bill — every piece of the new markup at once.
$editTarget = (int) db()->query("SELECT id FROM vouchers WHERE company_id=$cid AND reference_no='ABC-9001' LIMIT 1")->fetchColumn();
$_GET = ['view' => 'inventory', 'task' => 'purchase', 'edit_bill' => $editTarget];
$_POST = [];
ob_start();
$renderError = null;
try {
    include dirname(__DIR__) . '/public_html/admin/accounting-inventory.php';
} catch (Throwable $e) {
    $renderError = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
}
$html = (string) ob_get_clean();
ok($renderError === null, 'The Inventory page renders' . ($renderError === null ? ' (' . strlen($html) . ' bytes)' : ' — ' . $renderError));
$noise = [];
foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $needle) {
    $at = stripos($html, $needle);
    if ($at !== false) { $noise[] = trim(substr($html, $at, 160)); }
}
ok($noise === [], 'with no PHP notice, warning or deprecation' . ($noise === [] ? '' : ' — ' . implode(' | ', $noise)));

// A <form> inside a <form>: HTML has no such thing. The parser throws the
// inner start tag away and lets the inner </form> close the OUTER one, so
// every field after it leaves the form it was written in. The bill actions are
// three forms in one table cell, which is exactly where this goes wrong.
$depth = 0; $nested = false;
preg_match_all('~<form\b|</form\s*>~i', $html, $formTags);
foreach ($formTags[0] as $tag) {
    if (stripos($tag, '</form') === 0) { $depth = max(0, $depth - 1); continue; }
    if (++$depth > 1) { $nested = true; }
}
ok(!$nested, 'No <form> is nested inside another');

ok(str_contains($html, 'value="delete_purchase_bill"'), 'A posted bill offers Delete');
ok(str_contains($html, 'edit_bill='), '  ...and Edit');
ok(str_contains($html, 'inv-entry-preview'), '  ...and the entry can be previewed without leaving the page');
ok(str_contains($html, 'value="post_movement_draft"'), 'A draft still offers Post it');
ok(substr_count($html, 'value="merge_purchase_bills"') <= 1, 'The merge button appears at most once');

// Editing a bill is the same form, filled in from what was recorded.
ok(str_contains($html, 'name="replace_bill_id" value="' . $editTarget . '"'),
    'Editing a bill carries which entry it will replace');
ok(str_contains($html, 'ABC-9001'), '  ...with the bill number filled back in');
ok(substr_count($html, 'name="bills[0][items][0][item_id]"') === 1, '  ...into the item grid, one line per item bought');
ok(str_contains($html, 'Editing'), '  ...and says plainly that recording it replaces the old entry');

ipb_cleanup();

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass   FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
