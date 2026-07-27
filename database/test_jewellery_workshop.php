<?php
declare(strict_types=1);

/**
 * Jewellery Accounting — Phases 5 & 6: kaligad (karigar) masters, order
 * management, assignment, receipt with wage/wastage settlement, the
 * received-but-not-delivered board, and refinery jobs.
 *
 * Proves that issuing metal moves it without creating or destroying any, that
 * a receipt clears the karigar's holding to EXACTLY zero, that allowed and
 * excess wastage are split correctly and the excess is recovered from wages,
 * that a recovery larger than the wages flips the payable into a receivable
 * with no special case, and that refining loss and charges post correctly.
 *   php database/test_jewellery_workshop.php
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

function jww_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('JWWKA','JWWKB')")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$s)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['jewellery_refinery_jobs', 'jewellery_order_receipts', 'jewellery_order_assignments',
                  'jewellery_orders', 'jewellery_karigars', 'jewellery_settlement_allocations',
                  'jewellery_settlements', 'jewellery_bills', 'jewellery_sale_exchanges', 'jewellery_sale_lines',
                  'jewellery_sales', 'jewellery_purchase_lines', 'jewellery_purchases',                   'jewellery_stock_txns', 'jewellery_item_profiles', 'inventory_items', 'jewellery_daily_rates', 'inventory_ledger_mappings',
                  'jewellery_settings', 'jewellery_purities', 'jewellery_metals', 'jewellery_units'] as $t) {
            db()->exec("DELETE FROM `$t` WHERE company_id=$s");
        }
        db()->exec("DELETE FROM accounting_parties WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email LIKE 'jwwork-%@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
jww_cleanup();

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
[$cidA, $fyA, $userA] = $mkClient('JWWKA', 'Kantipur Workshop', 'jwwork-a@test.local');
[$cidB, $fyB, $userB] = $mkClient('JWWKB', 'Rival Workshop', 'jwwork-b@test.local');
$_SESSION['company_id'] = $cidA;
jewellery_settings($cidA);
jewellery_settings($cidB);

$q = static fn (string $sql): int => (int) db()->query($sql)->fetchColumn();
$gold = $q("SELECT id FROM jewellery_metals WHERE company_id=$cidA AND code='GOLD'");
$p24 = $q("SELECT id FROM jewellery_purities WHERE company_id=$cidA AND metal_id=$gold AND code='24K'");
$p22 = $q("SELECT id FROM jewellery_purities WHERE company_id=$cidA AND metal_id=$gold AND code='22K'");
$tola = $q("SELECT id FROM jewellery_units WHERE company_id=$cidA AND code='TOLA'");

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
    ['stock_karigar', 'STKK', 'Metal with Karigar', 'assets'],
    ['stock_refinery', 'STKR', 'Metal with Refinery', 'assets'],
    ['making_expense', 'MAKE', 'Making Charges', 'expenses'],
    ['wastage_loss', 'WAST', 'Wastage Loss', 'expenses'],
    ['karigar_payable', 'KARP', 'Karigar Payable', 'liabilities'],
    ['refinery_loss', 'RFLS', 'Refining Loss', 'expenses'],
    ['refinery_charges', 'RFCH', 'Refinery Charges', 'expenses'],
    ['cogs', 'COGS', 'Cost of Goods Sold', 'expenses'],
    ['sales_metal', 'SALM', 'Sales Metal', 'income'],
    ['sales_making', 'SALK', 'Sales Making', 'income'],
    ['sales_stone', 'SALS', 'Sales Stone', 'income'],
    ['vat_output', 'VATO', 'VAT Output', 'liabilities'],
    ['vat_input', 'VATI', 'VAT Input', 'assets'],
] as [$purpose, $code, $name, $master]) {
    $L[$purpose] = $mkLedger($cidA, $code, $name, $master);
    jewellery_save_mapping($cidA, $purpose, $L[$purpose], $userA);
}
$cash = $mkLedger($cidA, 'CASHJ', 'Cash', 'assets');

db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'REF1','City Refinery','supplier','active')")->execute(['c' => $cidA]);
$refiner = (int) db()->lastInsertId();
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'CUS1','Order Customer','customer','active')")->execute(['c' => $cidA]);
$customer = (int) db()->lastInsertId();

$chain = jewellery_save_item($cidA, ['code' => 'CHAIN22', 'name' => '22K Chain', 'item_type' => 'ornament',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'gross_weight' => 20], $userA);
$oldGold = jewellery_save_item($cidA, ['code' => 'OLD22', 'name' => 'Old Gold 22K', 'item_type' => 'bullion',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'gross_weight' => 0], $userA);
$fine24 = jewellery_save_item($cidA, ['code' => 'FINE24', 'name' => 'Refined 24K', 'item_type' => 'bullion',
    'metal_id' => $gold, 'purity_id' => $p24, 'unit_id' => $tola, 'gross_weight' => 0], $userA);

// Stock in at a clean cost basis: 20 tola of 22K = 18.32 fine for 2,748,000
// gives an average of exactly 150,000 per fine tola.
$pStock = jewellery_save_purchase($cidA, $fyA, ['purchase_date' => '2026-08-01', 'settle_mode' => 'cash',
    'settle_ledger_id' => $cash, 'party_id' => $refiner],
    [['item_id' => $chain, 'gross_weight' => 20, 'rate' => 137400]], $userA);
jewellery_post_purchase($cidA, $pStock, $userA);
$pOld = jewellery_save_purchase($cidA, $fyA, ['purchase_date' => '2026-08-01', 'settle_mode' => 'cash',
    'settle_ledger_id' => $cash, 'party_id' => $refiner],
    [['item_id' => $oldGold, 'gross_weight' => 10, 'rate' => 137400]], $userA);
jewellery_post_purchase($cidA, $pOld, $userA);
ok(near(jw_item_balance($cidA, $chain)['avg_fine_rate'], 150000.0), 'Fixture: the chain carries a 150,000 per fine tola cost basis');

echo "\n1. Karigar master: contractor vs employee\n";
$kContractor = jewellery_save_karigar($cidA, ['code' => 'RAM', 'name' => 'Ram Shakya',
    'engagement_type' => 'contractor', 'default_making_basis' => 'per_unit_weight',
    'default_making_rate' => 1000, 'wastage_allowed_pct' => 0.5], $userA);
$ram = jewellery_karigar($cidA, $kContractor);
ok((int) ($ram['party_id'] ?? 0) > 0, 'A CONTRACTOR karigar automatically gets a party so wages are bill-wise');
ok((string) $ram['engagement_type'] === 'contractor', 'It is recorded as a contractor');
$ramParty = (int) $ram['party_id'];

db()->prepare("INSERT INTO payroll_employees (company_id, employee_code) VALUES (:c,'EMP-K1')")->execute(['c' => $cidA]);
$empId = (int) db()->lastInsertId();
$kEmployee = jewellery_save_karigar($cidA, ['code' => 'SITA', 'name' => 'Sita Shakya',
    'engagement_type' => 'employee', 'payroll_employee_id' => $empId,
    'default_making_basis' => 'flat', 'default_making_rate' => 1000, 'wastage_allowed_pct' => 0.5], $userA);
$sita = jewellery_karigar($cidA, $kEmployee);
ok((string) $sita['engagement_type'] === 'employee', 'An EMPLOYEE karigar is recorded as such');
ok((int) ($sita['party_id'] ?? 0) === 0, 'An employee gets NO trade-payable party — their wages go through payroll');
ok(threw(static fn () => jewellery_save_karigar($cidA, ['code' => 'X', 'name' => 'X', 'engagement_type' => 'employee'], $userA)),
    'An employee karigar without a payroll link is rejected');
ok(threw(static fn () => jewellery_save_karigar($cidA, ['code' => 'Y', 'name' => 'Y', 'wastage_allowed_pct' => 150], $userA)),
    'Allowed wastage of 150% is rejected');

echo "\n2. Order lifecycle\n";
$order = jewellery_save_order($cidA, $fyA, [
    'order_date' => '2026-08-05', 'delivery_date' => '2026-08-25', 'party_id' => $customer,
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'expected_gross_weight' => 10,
    'design_no' => 'D-100', 'making_basis' => 'per_unit_weight', 'making_rate' => 1000, 'status' => 'confirmed',
], $userA);
$orderRow = jewellery_order($cidA, $order);
ok(near((float) $orderRow['expected_fine_weight'], 9.16), 'The expected fine weight is derived (10 tola at 916)');
ok((string) $orderRow['status'] === 'confirmed', 'The order is confirmed');
ok(threw(static fn () => jewellery_save_order($cidA, $fyA, ['order_date' => '2026-08-05',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola], $userA)),
    'An order with neither party nor customer name is rejected');

echo "\n3. Issue metal to a karigar\n";
$ownBefore = jw_item_balance($cidA, $chain, null, 'stock')['fine_weight'];
$r = jewellery_issue_to_karigar($cidA, $fyA, [
    'karigar_id' => $kContractor, 'order_id' => $order, 'item_id' => $chain, 'purity_id' => $p22,
    'unit_id' => $tola, 'issued_gross_weight' => 10, 'issue_date' => '2026-08-06',
    'wastage_allowed_pct' => 0.5, 'making_basis' => 'per_unit_weight', 'making_rate' => 1000,
], $userA);
ok($r['ok'], 'The issue succeeds' . ($r['ok'] ? '' : ' — ' . $r['error']));
$assignment = jewellery_assignment($cidA, (int) $r['assignment_id']);
ok(near((float) $assignment['issued_fine_weight'], 9.16), '9.16 fine went out');
ok(near((float) $assignment['issued_amount'], 1374000.0), 'It is valued at cost: 9.16 x 150,000 = 1,374,000');
ok(near(jw_item_balance($cidA, $chain, null, 'stock')['fine_weight'], $ownBefore - 9.16), 'Own stock falls by 9.16 fine');
ok(near(jw_item_balance($cidA, $chain, null, 'karigar', $kContractor)['fine_weight'], 9.16), 'The karigar now holds 9.16 fine');
ok(near(jw_item_balance($cidA, $chain, null, '')['fine_weight'], $ownBefore), 'The TOTAL position is unchanged — metal moved, none was created');
$v = voucher_shape((int) $r['voucher_id']);
ok(near($v['dr'], $v['cr']) && near($v['dr'], 1374000.0), 'A transfer voucher balances at the issued cost');
// The debit goes to a ledger belonging to THIS kaligad, not one shared
// "metal out" account — that is what makes a per-kaligad holding visible in
// the trial balance, and it is why the two legs can never cancel out.
$ramMetalLedger = jw_karigar_metal_ledger_id($cidA, jewellery_karigar($cidA, $kContractor));
ok($ramMetalLedger > 0 && $ramMetalLedger !== $L['stock_finished'],
    'The kaligad has their OWN metal ledger, distinct from the item stock ledger');
$ramLedgerRow = db()->query("SELECT name, type, group_id FROM ledgers WHERE id=$ramMetalLedger")->fetch(PDO::FETCH_ASSOC);
ok((string) $ramLedgerRow['name'] === 'Metal with Ram Shakya', 'It is named after the kaligad — got ' . $ramLedgerRow['name']);
ok((string) $ramLedgerRow['type'] === 'asset', 'It is an ASSET — metal with a kaligad is still the shop\'s');
ok((int) $ramLedgerRow['group_id'] === (int) db()->query("SELECT group_id FROM ledgers WHERE id={$L['stock_karigar']}")->fetchColumn(),
    'And it sits under the mapped "Metal with Karigar" group');
ok(near($v['ledgers'][$ramMetalLedger] ?? 0, 1374000.0), "The kaligad's own ledger is DEBITED the issued value");
ok(near($v['ledgers'][$L['stock_finished']] ?? 0, -1374000.0), "And the ITEM's own stock ledger is credited");
ok((string) jewellery_order($cidA, $order)['status'] === 'assigned', 'The order moves to assigned');
ok(!jewellery_issue_to_karigar($cidA, $fyA, ['karigar_id' => $kContractor, 'item_id' => $chain,
    'issued_gross_weight' => 9999], $userA)['ok'], 'Issuing more than is in stock is refused');

echo "\n4. Wastage arithmetic\n";
$split = jw_wastage_split(9.16, 9.0684, 0.5);
ok(near($split['wastage_fine'], 0.0916), 'Wastage is 9.16 - 9.0684 = 0.0916 fine');
ok(near($split['allowed_fine'], 0.0458), 'Allowed wastage is 0.5% of 9.16 = 0.0458');
ok(near($split['excess_fine'], 0.0458), 'The excess over the allowance is 0.0458');
$none = jw_wastage_split(10.0, 10.5, 1.0);
ok(near($none['wastage_fine'], 0.0) && near($none['excess_fine'], 0.0),
    'Returning MORE than issued is never charged as wastage');
ok(near(jw_making_charge('per_unit_weight', 1000, 9.9, 0), 9900.0), 'Per-unit-weight making: 1,000 x 9.9 = 9,900');
ok(near(jw_making_charge('percent_of_metal', 5, 0, 200000), 10000.0), 'Percent-of-metal making: 5% of 200,000');
ok(near(jw_making_charge('flat', 7500, 99, 999999), 7500.0), 'Flat making ignores weight and value');

echo "\n5. Receive back, with wages and excess wastage recovered\n";
$preview = jewellery_preview_receipt($cidA, (int) $r['assignment_id'], 9.9);
ok(near($preview['making_amount'], 9900.0), 'Preview: wages are 9,900');
ok(near($preview['recovery_amount'], 6870.0), 'Preview: excess wastage recovery is 0.0458 x 150,000 = 6,870');
ok(near($preview['net_payable'], 3030.0), 'Preview: net payable is 9,900 - 6,870 = 3,030');

$rec = jewellery_receive_from_karigar($cidA, $fyA, [
    'assignment_id' => (int) $r['assignment_id'], 'received_item_id' => $chain, 'received_purity_id' => $p22,
    'received_gross_weight' => 9.9, 'qty_pieces' => 1, 'receive_date' => '2026-08-20',
], $userA);
ok($rec['ok'], 'The receipt posts' . ($rec['ok'] ? '' : ' — ' . $rec['error']));
ok(near(jw_item_balance($cidA, $chain, null, 'karigar', $kContractor)['fine_weight'], 0.0),
    "The karigar's holding is cleared to EXACTLY zero");
ok(near(jw_item_balance($cidA, $chain, null, 'stock')['fine_weight'], $ownBefore - 0.0916),
    'Own stock is back, less only the wastage');
$v = voucher_shape((int) $rec['voucher_id']);
ok(near($v['dr'], $v['cr']), 'The receipt voucher balances');
ok(near($v['ledgers'][$L['making_expense']] ?? 0, 9900.0), 'Making expense is debited 9,900');
ok(near($v['ledgers'][$L['wastage_loss']] ?? 0, 6870.0), 'Wastage loss is debited NET of the recovery (13,740 - 6,870)');
$ramPayable = ensure_party_ledger($cidA, $ramParty, 'payable');
ok(near($v['ledgers'][$ramPayable] ?? 0, -3030.0), "The karigar's party ledger is credited the net 3,030");
// The FULL issued value must leave "metal with karigar" — crediting only the
// wastage would strand 1,360,260 there forever while the metal register shows
// the karigar holding nothing.
ok(near($v['ledgers'][$ramMetalLedger] ?? 0, -1374000.0),
    "The FULL issued value leaves THIS kaligad's ledger, not just the wastage");
ok(near($v['ledgers'][$L['stock_finished']] ?? 0, 1374000.0 - 13740.0),
    'The finished piece lands back in own stock at issued cost less wastage');
// The GL and the metal register must agree that the karigar now holds nothing.
$karigarLedgerBalance = 0.0;
foreach (db()->query("SELECT ve.entry_type, ve.amount FROM voucher_entries ve
    INNER JOIN vouchers v ON v.id = ve.voucher_id
    WHERE v.company_id=$cidA AND ve.ledger_id=$ramMetalLedger")->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $karigarLedgerBalance += ((string) $e['entry_type'] === 'debit' ? 1 : -1) * (float) $e['amount'];
}
ok(near($karigarLedgerBalance, 0.0),
    'GL "metal with karigar" nets to zero, matching the metal register — got ' . number_format($karigarLedgerBalance, 2));
$karigarBill = db()->query("SELECT * FROM jewellery_bills WHERE company_id=$cidA AND bill_type='karigar'")->fetch(PDO::FETCH_ASSOC);
ok($karigarBill && near((float) $karigarBill['bill_amount'], 3030.0), 'A karigar wage BILL was opened for 3,030');
ok((string) jewellery_order($cidA, $order)['status'] === 'received', 'The order moves to received');

echo "\n6. Recovery larger than the wages flips the payable\n";
$r2 = jewellery_issue_to_karigar($cidA, $fyA, [
    'karigar_id' => $kContractor, 'item_id' => $chain, 'unit_id' => $tola, 'issued_gross_weight' => 5,
    'issue_date' => '2026-08-21', 'wastage_allowed_pct' => 0.0, 'making_basis' => 'flat', 'making_rate' => 500,
], $userA);
ok($r2['ok'], 'A second issue of 5 tola succeeds');
// 4.58 fine out, 4.4 tola back = 4.0304 fine -> wastage 0.5496 fine, none allowed.
$preview2 = jewellery_preview_receipt($cidA, (int) $r2['assignment_id'], 4.4);
ok(near($preview2['excess_fine'], 0.5496), 'With no allowance the whole 0.5496 fine shortfall is excess');
ok($preview2['recovery_amount'] > $preview2['making_amount'], 'The recovery exceeds the flat 500 wage');
ok($preview2['net_payable'] < 0, 'So the net payable is NEGATIVE — the karigar owes the shop');
$rec2 = jewellery_receive_from_karigar($cidA, $fyA, [
    'assignment_id' => (int) $r2['assignment_id'], 'received_gross_weight' => 4.4, 'receive_date' => '2026-08-22',
], $userA);
ok($rec2['ok'], 'It still posts' . ($rec2['ok'] ? '' : ' — ' . $rec2['error']));
$v = voucher_shape((int) $rec2['voucher_id']);
ok(near($v['dr'], $v['cr']), 'And the voucher balances');
ok(($v['ledgers'][$ramPayable] ?? 0) > 0, 'The karigar ledger is DEBITED — a receivable, with no special case in the caller');
ok($q("SELECT COUNT(*) FROM jewellery_bills WHERE company_id=$cidA AND bill_type='karigar'") === 1,
    'No wage bill is opened when nothing is owed to the karigar');

echo "\n7. Karigar position and ledger\n";
$position = jewellery_karigar_position($cidA, $kContractor);
ok(near($position['fine_weight'], 0.0), 'The karigar holds no metal once everything is back');
ok(near($position['wages_payable'], 3030.0), 'Unsettled wages stand at 3,030');
$ledger = jw_report_karigar_ledger($cidA, $kContractor, '2026-07-16', '2027-07-15');
ok(count($ledger['rows']) === 4, 'The karigar ledger shows all four metal movements, got ' . count($ledger['rows']));
ok(near($ledger['closing_fine'], 0.0), 'And closes at zero fine');
ok(count($ledger['bills']) === 1, 'With one wage bill in the period');
$wages = jw_report_karigar_wages($cidA, '2026-07-16', '2027-07-15');
ok(count($wages) === 1 && (int) $wages[0]['jobs'] === 2, 'The wage report shows two completed jobs for this karigar');
ok($wages[0]['wastage_pct'] !== null, 'And computes a wastage percentage for comparison');

echo "\n8. Received but not delivered\n";
$pending = jewellery_pending_delivery($cidA);
ok(count($pending) === 1, 'One order is finished but still in the shop');
ok((int) $pending[0]['id'] === $order, 'It is the order we made');
$delivered = jewellery_deliver_order($cidA, $order, 0, $userA);
ok($delivered['ok'], 'It can be marked delivered');
ok((string) jewellery_order($cidA, $order)['status'] === 'delivered', 'The order is now delivered');
ok(jewellery_pending_delivery($cidA) === [], 'And drops off the pending board');
ok(!jewellery_deliver_order($cidA, $order, 0, $userA)['ok'], 'It cannot be delivered twice');

echo "\n9. Assignment cancellation\n";
$r3 = jewellery_issue_to_karigar($cidA, $fyA, ['karigar_id' => $kContractor, 'item_id' => $chain,
    'unit_id' => $tola, 'issued_gross_weight' => 2, 'issue_date' => '2026-08-23'], $userA);
$beforeCancel = jw_item_balance($cidA, $chain, null, 'stock')['fine_weight'];
ok(near(jw_item_balance($cidA, $chain, null, 'karigar', $kContractor)['fine_weight'], 1.832), 'The karigar holds 1.832 fine');
$cancelled = jewellery_cancel_assignment($cidA, (int) $r3['assignment_id'], $userA);
ok($cancelled['ok'], 'The assignment can be cancelled' . ($cancelled['ok'] ? '' : ' — ' . $cancelled['error']));
ok(near(jw_item_balance($cidA, $chain, null, 'karigar', $kContractor)['fine_weight'], 0.0), 'The metal comes back off the karigar');
ok(near(jw_item_balance($cidA, $chain, null, 'stock')['fine_weight'], $beforeCancel + 1.832), 'And returns to own stock');
ok($q("SELECT COUNT(*) FROM vouchers WHERE id=" . (int) $r3['voucher_id']) === 0, 'Its transfer voucher is gone');

echo "\n10. Refinery: issue, loss and charges\n";
$oldOwn = jw_item_balance($cidA, $oldGold, null, 'stock')['fine_weight'];
$job = jewellery_issue_to_refinery($cidA, $fyA, [
    'party_id' => $refiner, 'item_id' => $oldGold, 'purity_id' => $p22, 'unit_id' => $tola,
    'issued_gross_weight' => 10, 'issue_date' => '2026-09-01',
], $userA);
ok($job['ok'], 'Metal goes out for refining' . ($job['ok'] ? '' : ' — ' . $job['error']));
ok(near(jw_item_balance($cidA, $oldGold, null, 'refinery', $refiner)['fine_weight'], 9.16), 'The refinery holds 9.16 fine');
ok(near(jw_item_balance($cidA, $oldGold, null, 'stock')['fine_weight'], $oldOwn - 9.16), 'Own stock falls accordingly');

$recv = jewellery_receive_from_refinery($cidA, $fyA, [
    'job_id' => (int) $job['job_id'], 'received_item_id' => $fine24, 'received_purity_id' => $p24,
    'received_gross_weight' => 9.0, 'receive_date' => '2026-09-10', 'charges_amount' => 5000,
    'charges_settle_mode' => 'credit',
], $userA);
ok($recv['ok'], 'The refined metal comes back' . ($recv['ok'] ? '' : ' — ' . $recv['error']));
ok(near($recv['loss_fine'], 9.16 - 8.9991), 'Refining loss is 9.16 - 8.9991 = 0.1609 fine');
ok(near($recv['loss_amount'], 24135.0), 'Valued at 0.1609 x 150,000 = 24,135');
$v = voucher_shape((int) $recv['voucher_id']);
ok(near($v['dr'], $v['cr']), 'The refinery receipt voucher balances');
ok(near($v['ledgers'][$L['refinery_loss']] ?? 0, 24135.0), 'Refining loss is debited 24,135');
ok(near($v['ledgers'][$L['refinery_charges']] ?? 0, 5000.0), 'Refinery charges are debited 5,000');
$refinerLedger = jw_refiner_metal_ledger_id($cidA, $refiner);
ok($refinerLedger > 0 && $refinerLedger !== $L['stock_metal'],
    'The refiner has their OWN metal ledger too');
ok(near($v['ledgers'][$refinerLedger] ?? 0, -1374000.0), "The full issued value leaves THAT refiner's ledger");
ok(near($v['ledgers'][$L['stock_metal']] ?? 0, 1374000.0 - 24135.0), 'The refined bar enters stock at cost less the loss');
$refPayable = ensure_party_ledger($cidA, $refiner, 'payable');
ok(near($v['ledgers'][$refPayable] ?? 0, -5000.0), 'The refiner is credited the 5,000 fee');
ok(near(jw_item_balance($cidA, $oldGold, null, 'refinery', $refiner)['fine_weight'], 0.0), 'The refinery holding clears to zero');
ok(near(jw_item_balance($cidA, $fine24)['fine_weight'], 8.9991), '8.9991 fine of 24K is now in stock');
$refBill = db()->query("SELECT * FROM jewellery_bills WHERE company_id=$cidA AND source_type='jewellery_refinery_receive'")->fetch(PDO::FETCH_ASSOC);
ok($refBill && near((float) $refBill['bill_amount'], 5000.0), 'The refinery fee opened a bill');
ok(!jewellery_receive_from_refinery($cidA, $fyA, ['job_id' => (int) $job['job_id'], 'received_gross_weight' => 1], $userA)['ok'],
    'A job cannot be received twice');
ok(!jewellery_receive_from_refinery($cidA, $fyA, ['job_id' => (int) $job['job_id'], 'received_gross_weight' => 999], $userA)['ok'],
    'Receiving more fine metal than went out is refused');

echo "\n11. Reversal protection\n";
$vCheck = db()->query("SELECT * FROM vouchers WHERE company_id=$cidA AND source_type='jewellery_order_receipt' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$registerDelete = delete_voucher_with_entries((int) $vCheck['id'], $cidA, $userA);
ok(!$registerDelete['ok'] && str_contains($registerDelete['error'], 'Jewellery'),
    'The Voucher Register REFUSES to delete a karigar receipt voucher');

echo "\n12. Cross-tenant isolation\n";
ok(threw(static fn () => jewellery_save_karigar($cidB, ['code' => 'X', 'name' => 'X', 'party_id' => $ramParty], $userB)),
    "Company B cannot attach company A's party to its karigar");
ok(!jewellery_issue_to_karigar($cidB, $fyB, ['karigar_id' => $kContractor, 'item_id' => $chain,
    'unit_id' => $tola, 'issued_gross_weight' => 1], $userB)['ok'],
    "Company B cannot issue company A's metal to company A's karigar");
ok(jewellery_karigars_list($cidB) === [], 'Company B has no karigars');
ok($q("SELECT COUNT(*) FROM vouchers WHERE company_id=$cidB") === 0, 'No voucher ever reached company B');

jww_cleanup();
echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail === 0 ? 0 : 1);
