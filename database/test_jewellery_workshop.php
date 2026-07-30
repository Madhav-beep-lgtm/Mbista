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
                  'jewellery_order_lines', 'jewellery_orders', 'jewellery_karigars', 'jewellery_settlement_allocations',
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
    // Needed now this suite raises a real bill: delivery goes through a sale,
    // and a sale levies the Skills Development tax on its own account.
    ['spt_output', 'SPTO', 'SD Tax Output', 'liabilities'],
    ['spt_input', 'SPTI', 'SD Tax Input', 'assets'],
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
], [], $userA);
$orderRow = jewellery_order($cidA, $order);
ok(near((float) $orderRow['expected_fine_weight'], 9.16), 'The expected fine weight is derived (10 tola at 916)');
ok((string) $orderRow['status'] === 'confirmed', 'The order is confirmed');
ok(threw(static fn () => jewellery_save_order($cidA, $fyA, ['order_date' => '2026-08-05',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola], [], $userA)),
    'An order with neither party nor customer name is rejected');

// The reference the customer is given and every later document hangs off.
// Company A's fiscal year opens 2026-07-16, which is Ashadh 2083, so the
// segment is 2083 — the year the shop calls it, not the year the order was
// written in.
ok(jewellery_order_series($cidA, $fyA, '2026-08-05') === '2083',
    'The order series is the BS year the FISCAL YEAR opens in (2083)');
ok(jewellery_order_series($cidA, 0, '2027-03-10') === '2083',
    'With no fiscal year to go on it falls back to the order date');
ok(preg_match('/^JO-2083-\d{6}$/', (string) $orderRow['order_no']) === 1,
    'A new order numbers PREFIX-<fiscal year>-<6 digits>: ' . $orderRow['order_no']);
$seriesFirst = jw_next_no($cidA, 'jewellery_orders', 'order_no', 'ORD', '2083');
ok($seriesFirst === 'ORD-2083-000001', 'A fresh series starts at 000001, not at the flat count');
ok(jw_next_no($cidA, 'jewellery_orders', 'order_no', 'JO', '2084') === 'JO-2084-000001',
    'Each fiscal year restarts its own count');

// A shop that already numbers JO-00001 must keep issuing flat numbers from
// where it left off, and the series numbers sitting beside them must not drag
// that count forward into numbers it has already given out.
db()->prepare('INSERT INTO jewellery_orders (company_id, fiscal_year_id, order_no, order_date, party_id,
        metal_id, purity_id, unit_id, status) VALUES (:cid, :fy, :no, :d, :p, :m, :pu, :u, :s)')
    ->execute(['cid' => $cidA, 'fy' => $fyA, 'no' => 'JO-00007', 'd' => '2026-08-05',
        'p' => $customer, 'm' => $gold, 'pu' => $p22, 'u' => $tola, 's' => 'draft']);
ok(jw_next_no($cidA, 'jewellery_orders', 'order_no', 'JO') === 'JO-00008',
    'The flat sequence carries on from JO-00007, ignoring the year-series numbers');
db()->prepare('DELETE FROM jewellery_orders WHERE company_id = :cid AND order_no = :no')
    ->execute(['cid' => $cidA, 'no' => 'JO-00007']);

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

echo "\n4b. Nobody is allowed to lose gold\n";
/*
 * The kaligad is paid a percentage for the WORK; the metal stays the shop's the
 * whole time. So a piece that comes back light is short by his doing, and he
 * bears it. The default is no allowance at all.
 *
 * An allowance is a concession somebody grants after seeing the actual loss —
 * never a standing rate that writes metal off before anyone knows there was a
 * shortfall. Which is why an issue no longer inherits anything from the
 * kaligad's own record.
 */
$strict = jw_wastage_split(10.0, 9.9, 0.0);
ok(near($strict['allowed_fine'], 0.0), 'With no allowance, nothing is written off');
ok(near($strict['excess_fine'], 0.1), 'And the whole 0.1 fine shortfall is his to bear');

// Granted on the day, in fine weight, by somebody looking at the actual loss.
$granted = jw_wastage_split(10.0, 9.9, 0.0, 0.04);
ok(near($granted['allowed_fine'], 0.04), 'A grant of 0.04 fine is allowed');
ok(near($granted['excess_fine'], 0.06), 'And he bears only the remaining 0.06');
ok(near($granted['wastage_fine'], 0.1), 'The shortfall itself is unchanged — only who pays for it moves');

// A grant beyond the actual loss must not manufacture a credit.
$over = jw_wastage_split(10.0, 9.9, 0.0, 5.0);
ok(near($over['allowed_fine'], 0.1) && near($over['excess_fine'], 0.0),
    'Allowing more than was lost forgives the loss and no more — it cannot pay him for metal he kept');

// A grant overrides any percentage, so an old stored rate cannot creep back in.
$overrides = jw_wastage_split(10.0, 9.9, 90.0, 0.0);
ok(near($overrides['allowed_fine'], 0.0) && near($overrides['excess_fine'], 0.1),
    'Granting nothing beats a percentage left on a record — the person on the day decides');

// And the issue itself must not pick up a standing rate. $kContractor carries
// 0.5% on his record; an issue that says nothing about wastage must ignore it.
$standing = jewellery_issue_to_karigar($cidA, $fyA, [
    'karigar_id' => $kContractor, 'item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola,
    'issued_gross_weight' => 1, 'issue_date' => '2026-08-16',
], $userA);
ok($standing['ok'], 'An issue that names no allowance still goes out'
    . ($standing['ok'] ? '' : ' — ' . $standing['error']));
ok(near((float) jewellery_assignment($cidA, (int) $standing['assignment_id'])['wastage_allowed_pct'], 0.0),
    "It carries NO allowance, though the kaligad's record says 0.5% — nothing is forgiven in advance");
jewellery_cancel_assignment($cidA, (int) $standing['assignment_id'], $userA);

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
/*
 * GOODS LEAVE THE SHOP ONLY AGAINST A BILL.
 *
 * "Mark delivered" used to take no sale at all, and the board's button sent
 * none — so it closed the order and billed nobody: no revenue, no VAT, no
 * receivable, and the ornament still in stock as far as the books knew. The
 * customer walked out with gold the accounts believed was in the safe.
 */
$noBill = jewellery_deliver_order($cidA, $order, 0, $userA);
ok(!$noBill['ok'] && stripos($noBill['error'], 'billing') !== false,
    'It cannot be delivered without a bill — billing it is the only way goods leave');
ok((string) jewellery_order($cidA, $order)['status'] !== 'delivered',
    'And the order is untouched by the attempt');

// A draft bill is not evidence of anything: it can still be edited or thrown
// away, and nothing has moved.
$draftSale = jewellery_save_sale($cidA, $fyA, [
    'sale_date' => '2026-08-28', 'party_id' => $customer,
    'settle_mode' => 'cash', 'settle_ledger_id' => $cash, 'received_amount' => 0,
], [[
    'item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola,
    'qty_pieces' => 1, 'gross_weight' => 1, 'rate' => 150000,
]], [], $userA);
$onDraft = jewellery_deliver_order($cidA, $order, $draftSale, $userA);
ok(!$onDraft['ok'] && stripos($onDraft['error'], 'posted') !== false,
    'Nor against a bill nobody has posted — nothing has actually left the shop yet');

// Posted, and now it is real.
$postRes = jewellery_post_sale($cidA, $draftSale, $userA);
ok($postRes['ok'], 'The bill posts' . ($postRes['ok'] ? '' : ' — ' . $postRes['error']));
$delivered = jewellery_deliver_order($cidA, $order, $draftSale, $userA);
ok($delivered['ok'], 'And THEN the order can be delivered' . ($delivered['ok'] ? '' : ' — ' . $delivered['error']));
ok((string) jewellery_order($cidA, $order)['status'] === 'delivered', 'The order is now delivered');
ok((int) jewellery_order($cidA, $order)['delivered_sale_id'] === $draftSale,
    'And it records WHICH bill the goods went out on, so the two cannot drift apart');
ok(jewellery_pending_delivery($cidA) === [], 'It drops off the pending board');
ok(!jewellery_deliver_order($cidA, $order, $draftSale, $userA)['ok'], 'It cannot be delivered twice');

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

echo "\n10b. A refiner who puts in metal of his own is paid for it, not refused\n";
/*
 * A furnace cannot make gold, so fine weight normally comes back lower than it
 * went out. More coming back means the refiner added some of his own — usually
 * so the shop gets a round bar instead of an awkward fraction.
 *
 * That used to be refused outright, with the advice to "record the extra as a
 * separate purchase". It IS a purchase, and the karigar side of the workshop
 * already knew that; the refinery side sent the shop away to key it in by hand,
 * where it would be forgotten or valued at the wrong day's rate.
 */
// The 22K lot is spent, so this one re-refines part of the 24K bar it produced.
$stockBeforeJob = jw_item_balance($cidA, $fine24)['fine_weight'];
$surplusJob = jewellery_issue_to_refinery($cidA, $fyA, [
    'party_id' => $refiner, 'item_id' => $fine24, 'purity_id' => $p24, 'unit_id' => $tola,
    'issued_gross_weight' => 5, 'issue_date' => '2026-09-11',
], $userA);
ok($surplusJob['ok'], 'A second lot goes out' . ($surplusJob['ok'] ? '' : ' — ' . $surplusJob['error']));

// Read the rate off the JOB rather than hardcoding one. The property under test
// is that the surplus is valued at what the issue was worth — so the test has
// to ask the issue, not a number typed into the test.
$issuedJob = jewellery_refinery_job($cidA, (int) $surplusJob['job_id']);
$issuedFineWt = (float) $issuedJob['issued_fine_weight'];
$issuedValue = (float) $issuedJob['issued_amount'];
$jobRate = jw_round_rate($issuedFineWt > 0 ? $issuedValue / $issuedFineWt : 0.0);

// He hands back a bar 0.22 heavier than the metal he was given. What that is
// worth in FINE weight is NOT 0.22 — even 24K is 0.9999 here, not pure — so the
// expectation is converted the same way the engine converts it, rather than
// assuming a gram of bar is a gram of gold.
$backGross = jw_round_weight($issuedFineWt + 0.22);
$backFine = jw_round_weight(jw_fine_weight($backGross, (float) jewellery_purity($cidA, $p24)['fineness']));
$expectedSurplusFine = jw_round_weight($backFine - $issuedFineWt);
$expectedSurplus = jw_round_money($expectedSurplusFine * $jobRate);
$stockBefore = jw_item_balance($cidA, $fine24)['fine_weight'];

$surplusRecv = jewellery_receive_from_refinery($cidA, $fyA, [
    'job_id' => (int) $surplusJob['job_id'], 'received_item_id' => $fine24, 'received_purity_id' => $p24,
    'received_gross_weight' => $backGross, 'receive_date' => '2026-09-20', 'charges_amount' => 2000,
    'charges_settle_mode' => 'credit',
], $userA);
ok($surplusRecv['ok'], 'And comes back HEAVIER, which is now accepted'
    . ($surplusRecv['ok'] ? '' : ' — ' . $surplusRecv['error']));
ok(near((float) $surplusRecv['surplus_fine'], $expectedSurplusFine, 0.00011),
    'The extra fine weight is his own metal, measured to the last decimal');
ok(near((float) $surplusRecv['loss_amount'], 0.0) && near((float) $surplusRecv['loss_fine'], 0.0),
    'And no refining loss: the two are mutually exclusive');
ok(near((float) $surplusRecv['surplus_amount'], $expectedSurplus),
    'Valued at the rate the ISSUE was worth, not at some other day\'s rate');

$sv = voucher_shape((int) $surplusRecv['voucher_id']);
ok(near($sv['dr'], $sv['cr']), 'The voucher balances with metal flowing the other way');
ok(near($sv['ledgers'][$L['stock_metal']] ?? 0, $issuedValue + $expectedSurplus),
    'Stock rises by the issued cost PLUS the metal he supplied');
ok(near($sv['ledgers'][$refinerLedger] ?? 0, -$issuedValue),
    "Only the issued value leaves the refiner's metal ledger, so it still closes to nil");
ok(near($sv['ledgers'][$refPayable] ?? 0, -(2000.0 + $expectedSurplus)),
    'And he is credited his fee and his metal together, in one place');
ok(near($sv['ledgers'][$L['refinery_loss']] ?? 0, 0.0),
    'Nothing is written off as a refining loss');
// The issue took the metal out of stock and the receipt brings the whole bar
// back, so across the two legs the shop is up by exactly what he supplied.
ok(near(jw_item_balance($cidA, $fine24)['fine_weight'], $stockBefore + $backFine, 0.00011),
    'The whole bar lands in stock, his share included');
ok(near(jw_item_balance($cidA, $fine24)['fine_weight'], $stockBeforeJob + $expectedSurplusFine, 0.00011),
    'So across issue and receipt together, the shop is up by exactly his 0.2195 fine');
ok(near(jw_item_balance($cidA, $fine24, null, 'refinery', $refiner)['fine_weight'], 0.0),
    'And the refinery holding still clears to zero');

$surplusBill = db()->query("SELECT * FROM jewellery_bills WHERE company_id=$cidA
    AND source_type='jewellery_refinery_receive' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok($surplusBill && near((float) $surplusBill['bill_amount'], 2000.0 + $expectedSurplus),
    'One bill covers both, so the refiner is not paid twice for one job');

$storedJob = jewellery_refinery_job($cidA, (int) $surplusJob['job_id']);
ok(near((float) $storedJob['surplus_fine_weight'], $expectedSurplusFine, 0.00011)
    && near((float) $storedJob['surplus_amount'], $expectedSurplus),
    'The job keeps the figures, the way it already keeps the loss');

// Metal of his own still needs somebody to owe it to.
$noParty = jewellery_issue_to_refinery($cidA, $fyA, [
    'item_id' => $fine24, 'purity_id' => $p24, 'unit_id' => $tola,
    'issued_gross_weight' => 1, 'issue_date' => '2026-09-21',
], $userA);
ok($noParty['ok'], 'A lot can go out without naming a refiner' . ($noParty['ok'] ? '' : ' — ' . $noParty['error']));
$refused = jewellery_receive_from_refinery($cidA, $fyA, [
    'job_id' => (int) ($noParty['job_id'] ?? 0), 'received_item_id' => $fine24, 'received_purity_id' => $p24,
    'received_gross_weight' => 2, 'receive_date' => '2026-09-22', 'charges_settle_mode' => 'credit',
], $userA);
ok(!$refused['ok'] && stripos($refused['error'], 'party') !== false,
    'But metal on credit from a refiner nobody named is refused — there is no one to owe it to');

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

echo "\n13. One customer, several items, one order — quoted exactly\n";
// A customer orders a chain AND a diamond-set ring in the same breath. Before
// order lines existed that was two orders, two numbers and two advances for
// one conversation, and neither of them could say what it came to.
$multi = jewellery_save_order($cidA, $fyA, [
    'order_date' => '2026-08-05', 'party_id' => $customer, 'status' => 'confirmed',
    'delivery_date' => '2026-08-25', 'design_no' => 'D-200',
], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 2, 'rate' => 150000, 'making_amount' => 4000],
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1, 'rate' => 150000, 'making_amount' => 2500,
     'diamond_amount' => 60000, 'diamond_carat' => 1.25, 'stone_amount' => 800, 'stone_carat' => 0.4],
], $userA);
$multiRow = jewellery_order($cidA, $multi);
$multiLines = jewellery_order_line_rows($cidA, $multi);
ok(count($multiLines) === 2, 'Both items are on the ONE order');
// The second line carries 1.65 ct of set stones (1.25 diamond + 0.4 stone)
// and no typed stone weight, so the carats convert themselves: 1.65 × 0.2 g
// = 0.33 g = 0.0283 tola of the piece is ROCK, and the gold rate is not
// charged on it. Metal line 2 = (1 − 0.0283) × 150,000 = 145,755.
ok(near((float) $multiRow['metal_amount'], 445755.0),
    'Metal is 445,755 — the set stones\' 0.0283 tola is not billed at the gold rate');
ok(near((float) $multiRow['making_amount'], 6500.0), 'Making is 4,000 + 2,500');
ok(near((float) $multiRow['expected_gross_weight'], 3.0), 'And the header weight is the whole order, not just its first piece');

// The two taxes, on their own bases, exactly as the bill will charge them.
ok(near((float) $multiRow['sd_taxable_amount'], 452255.0),
    'SD taxable amt = metal + making = 452,255 — the diamond is NOT in it');
ok(near((float) $multiRow['tax_amount'], 2261.28), 'Skills Promotion Tax at 0.5% = 2,261.28');
ok(near((float) $multiRow['vatable_amount'], 60800.0),
    'Vatable amt = diamond + stone = 60,800 — the gold and the labour are NOT in it');
ok(near((float) $multiRow['vat_amount'], 7904.0), 'VAT at 13% of 60,800 = 7,904.00');
ok(near((float) $multiRow['total_amount'], 445755.0 + 6500.0 + 60800.0 + 2261.28 + 7904.0),
    'And the total payable quoted to the customer is 523,220.28');

// The quote has to survive into the bill, or it was never a quote.
$multiPrefill = jewellery_order_sale_prefill($cidA, $multi);
ok(count($multiPrefill['lines']) === 2, 'Both ordered items come back for the bill — neither is dropped');
ok(near((float) $multiPrefill['lines'][1]['diamond_amount'], 60000.0),
    'The second line keeps its diamond all the way through to the sale form');
ok(near((float) $multiPrefill['order_total'], (float) $multiRow['total_amount']),
    'And the prefill carries the quoted total for the counter to check against');

// Editing replaces the lines rather than piling more on.
jewellery_save_order($cidA, $fyA, [
    'id' => $multi, 'order_date' => '2026-08-05', 'party_id' => $customer, 'status' => 'confirmed',
], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 2, 'rate' => 150000, 'making_amount' => 4000],
], $userA);
ok(count(jewellery_order_line_rows($cidA, $multi)) === 1, 'Revising an order replaces its lines, it does not add to them');
ok(near((float) jewellery_order($cidA, $multi)['total_amount'], 300000.0 + 4000.0 + jw_round_money(304000.0 * 0.005)),
    'And the quote is re-worked from what is left');

// An advance cannot exceed the quote — the shop would be holding money against
// nothing, and it would net to a negative balance on delivery.
ok(threw(static fn () => jewellery_save_order($cidA, $fyA, [
    'id' => $multi, 'order_date' => '2026-08-05', 'party_id' => $customer, 'advance_amount' => 9999999,
], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 2, 'rate' => 150000, 'making_amount' => 4000],
], $userA)), 'An advance larger than the order itself is refused');

// A bespoke order — no item picked yet — is still recordable, and quotes zero
// rather than a guess.
$bespoke = jewellery_save_order($cidA, $fyA, [
    'order_date' => '2026-08-06', 'party_id' => $customer, 'status' => 'confirmed',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'expected_gross_weight' => 10,
    'description' => 'A ten-tola 22K chain, pattern to be chosen',
], [], $userA);
$bespokeRow = jewellery_order($cidA, $bespoke);
ok($bespoke > 0 && (int) ($bespokeRow['item_id'] ?? 0) === 0, 'An order with no item chosen yet is still taken');
ok(near((float) $bespokeRow['expected_fine_weight'], 9.16), 'Its metal spec still derives a fine weight for the kaligad');
ok(near((float) $bespokeRow['total_amount'], 0.0), 'And it quotes nothing rather than inventing a figure');

// The order form no longer sends making_basis / making_rate — the customer's
// labour charge is on the line, the kaligad's is on the issue screen. An
// absent field must leave the stored one alone rather than zeroing it.
$keepOrder = jewellery_save_order($cidA, $fyA, [
    'order_date' => '2026-08-07', 'party_id' => $customer, 'status' => 'confirmed',
    'making_basis' => 'flat', 'making_rate' => 7777,
], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1, 'rate' => 150000],
], $userA);
ok(near((float) jewellery_order($cidA, $keepOrder)['making_rate'], 7777.0), 'A making rate is stored when it is sent');
jewellery_save_order($cidA, $fyA, [
    'id' => $keepOrder, 'order_date' => '2026-08-07', 'party_id' => $customer, 'status' => 'confirmed',
], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1, 'rate' => 150000, 'making_amount' => 3000],
], $userA);
$keptRow = jewellery_order($cidA, $keepOrder);
ok(near((float) $keptRow['making_rate'], 7777.0),
    'And it survives a save that never mentioned it — the form does not decide what the books forget');
ok(near((float) $keptRow['making_amount'], 3000.0),
    "While the customer's own making charge comes from the line, where it belongs");

echo "\n14. Each item on an order goes to its own kaligad, on its own date\n";
// Kaligads specialise: the one who makes chains does not set stones. So an
// order for two different pieces is two craftsmen, two issues and two dates
// back — and it must still be ONE order to the customer.
$splitOrder = jewellery_save_order($cidA, $fyA, [
    'order_date' => '2026-08-10', 'party_id' => $customer, 'status' => 'confirmed',
], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 2, 'rate' => 150000, 'making_amount' => 3000,
     'karigar_id' => $kContractor, 'delivery_date' => '2026-08-20'],
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1, 'rate' => 150000, 'making_amount' => 2000,
     'karigar_id' => $kEmployee, 'delivery_date' => '2026-09-05'],
], $userA);
$splitLines = jewellery_order_line_rows($cidA, $splitOrder);
ok(count($splitLines) === 2, 'Both items are on the one order');
ok((int) $splitLines[0]['karigar_id'] === $kContractor && (int) $splitLines[1]['karigar_id'] === $kEmployee,
    'Each item names its OWN kaligad');
ok((string) $splitLines[0]['delivery_date'] === '2026-08-20'
    && (string) $splitLines[1]['delivery_date'] === '2026-09-05',
    'And each carries its own promised date');
ok((string) jewellery_order($cidA, $splitOrder)['delivery_date'] === '2026-09-05',
    "The order's own promise is the LAST of them — one order, one journey for the customer");

ok(threw(static fn () => jewellery_save_order($cidA, $fyA, [
    'order_date' => '2026-08-10', 'party_id' => $customer,
], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1, 'rate' => 150000, 'delivery_date' => '2026-08-01'],
], $userA)), 'An item cannot be promised before the order was taken');

// Issuing against the ITEM, not the order.
$splitIssue = jewellery_issue_to_karigar($cidA, $fyA, [
    'order_line_id' => (int) $splitLines[0]['id'],
    'issue_date' => '2026-08-11',
], $userA);
ok($splitIssue['ok'], 'Metal issues against the order ITEM' . ($splitIssue['ok'] ? '' : ' — ' . $splitIssue['error']));
$splitAssignment = jewellery_assignment($cidA, (int) $splitIssue['assignment_id']);
ok((int) $splitAssignment['karigar_id'] === $kContractor,
    "It goes to the kaligad the ITEM named, without the issuer retyping it");
ok((int) $splitAssignment['item_id'] === $chain && near((float) $splitAssignment['issued_gross_weight'], 2.0),
    'And it carries the item and weight from the order, not from the form');
ok((string) $splitAssignment['expected_return_date'] === '2026-08-20',
    "The kaligad is due back on THAT item's date, not the order's");
ok((int) $splitAssignment['order_line_id'] === (int) $splitLines[0]['id'],
    'The issue points back at the item it covers');

$splitLinesAfter = jewellery_order_line_rows($cidA, $splitOrder);
ok((int) $splitLinesAfter[0]['assignment_id'] === (int) $splitIssue['assignment_id'],
    'And the item points at the issue — the board can say which piece is with whom');
ok((int) ($splitLinesAfter[1]['assignment_id'] ?? 0) === 0,
    'The second item is untouched, still waiting for its own kaligad');

$reissue = jewellery_issue_to_karigar($cidA, $fyA, [
    'order_line_id' => (int) $splitLines[0]['id'], 'issue_date' => '2026-08-12',
], $userA);
ok(!$reissue['ok'] && stripos($reissue['error'], 'already has metal out') !== false,
    'The same item cannot be issued twice over');

// The remaining item still shows on the board; the issued one does not.
$pendingLines = jewellery_pending_order_lines($cidA);
$pendingIds = array_map('intval', array_column($pendingLines, 'id'));
ok(!in_array((int) $splitLines[0]['id'], $pendingIds, true), 'An issued item leaves the pending board');
ok(in_array((int) $splitLines[1]['id'], $pendingIds, true), 'And the one still to go stays on it');

// Revising the order must not orphan metal already out with a kaligad. Rows are
// identified by their stored id: both these lines carry the SAME item, so
// position could never tell the engine which one was dropped.
ok(threw(static fn () => jewellery_save_order($cidA, $fyA, [
    'id' => $splitOrder, 'order_date' => '2026-08-10', 'party_id' => $customer,
], [
    ['line_id' => (int) $splitLines[1]['id'],
     'item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1, 'rate' => 150000, 'karigar_id' => $kEmployee, 'delivery_date' => '2026-09-05'],
], $userA)), 'An item with metal already out cannot be dropped from the order');

// Dropping the OTHER one — the one with nothing issued against it — is fine.
jewellery_save_order($cidA, $fyA, [
    'id' => $splitOrder, 'order_date' => '2026-08-10', 'party_id' => $customer,
], [
    ['line_id' => (int) $splitLines[0]['id'],
     'item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 2, 'rate' => 150000, 'making_amount' => 3000,
     'karigar_id' => $kContractor, 'delivery_date' => '2026-08-20'],
], $userA);
ok(count(jewellery_order_line_rows($cidA, $splitOrder)) === 1,
    'An item with nothing issued against it CAN be dropped');

// A revision that keeps the assigned line carries the issue across to its new
// row, and re-pointing works even when the row moves position.
jewellery_save_order($cidA, $fyA, [
    'id' => $splitOrder, 'order_date' => '2026-08-10', 'party_id' => $customer,
], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1, 'rate' => 150000, 'making_amount' => 2000,
     'karigar_id' => $kEmployee, 'delivery_date' => '2026-09-05'],
    ['line_id' => (int) jewellery_order_line_rows($cidA, $splitOrder)[0]['id'],
     'item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 2, 'rate' => 150000, 'making_amount' => 3500,
     'karigar_id' => $kContractor, 'delivery_date' => '2026-08-20'],
], $userA);
$revised = jewellery_order_line_rows($cidA, $splitOrder);
$assignedRow = null;
foreach ($revised as $revisedRow) {
    if ((int) ($revisedRow['assignment_id'] ?? 0) === (int) $splitIssue['assignment_id']) {
        $assignedRow = $revisedRow;
    }
}
ok($assignedRow !== null, 'Revising the order keeps the issue attached to its item, even as the row moves position');
$revisedAssignment = jewellery_assignment($cidA, (int) $splitIssue['assignment_id']);
ok($assignedRow !== null && (int) $revisedAssignment['order_line_id'] === (int) $assignedRow['id'],
    'And the issue is re-pointed at the new line row, so the link survives both ways');

// Cancelling frees the item to be issued again.
ok(jewellery_cancel_assignment($cidA, (int) $splitIssue['assignment_id'], $userA)['ok'], 'The issue cancels');
$freedRow = null;
foreach (jewellery_order_line_rows($cidA, $splitOrder) as $candidateRow) {
    if ((int) $candidateRow['id'] === (int) ($assignedRow['id'] ?? 0)) {
        $freedRow = $candidateRow;
    }
}
ok($freedRow !== null && (int) ($freedRow['assignment_id'] ?? 0) === 0, 'Cancelling frees the item');
ok(jewellery_issue_to_karigar($cidA, $fyA, [
    'order_line_id' => (int) $freedRow['id'], 'issue_date' => '2026-08-13',
], $userA)['ok'], 'And it can be issued again — to a different kaligad if the shop chooses');

echo "\n15. One issue, several items, and the balance that is left over\n";
// A kaligad given a bar of gold makes three chains out of it. The metal handed
// over is a round weight the shop can hand, not the exact sum of the pieces, so
// issued and ordered are NOT meant to agree — the difference is a balance to
// watch, not an error to prevent.
$multiOrder = jewellery_save_order($cidA, $fyA, [
    'order_date' => '2026-09-01', 'party_id' => $customer, 'status' => 'confirmed',
], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1.5, 'rate' => 150000, 'karigar_id' => $kContractor, 'delivery_date' => '2026-09-20'],
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1.5, 'rate' => 150000, 'karigar_id' => $kContractor, 'delivery_date' => '2026-09-25'],
], $userA);
$multiOrderLines = jewellery_order_line_rows($cidA, $multiOrder);

// This kaligad has been used earlier in the suite, so the figures below are
// measured as the CHANGE this issue makes rather than as absolutes.
$balanceBefore = jewellery_karigar_metal_balance($cidA, $kContractor);

// Three tola of work; five tola of gold handed over, because that is the bar.
$bulkIssue = jewellery_issue_to_karigar($cidA, $fyA, [
    'order_line_ids' => [(int) $multiOrderLines[0]['id'], (int) $multiOrderLines[1]['id']],
    'issued_gross_weight' => 5, 'issue_date' => '2026-09-02',
], $userA);
ok($bulkIssue['ok'], 'One issue covers several items' . ($bulkIssue['ok'] ? '' : ' — ' . $bulkIssue['error']));
$bulkAssignment = jewellery_assignment($cidA, (int) $bulkIssue['assignment_id']);
ok(near((float) $bulkAssignment['issued_gross_weight'], 5.0),
    'The weight issued is what the shop handed over, NOT the sum of the ordered pieces');
ok((string) $bulkAssignment['expected_return_date'] === '2026-09-20',
    'The kaligad is due back on the SOONEST of the promised dates');
$bulkLines = jewellery_order_line_rows($cidA, $multiOrder);
ok((int) $bulkLines[0]['assignment_id'] === (int) $bulkIssue['assignment_id']
    && (int) $bulkLines[1]['assignment_id'] === (int) $bulkIssue['assignment_id'],
    'Both items point at the one issue');
ok(jewellery_pending_order_lines($cidA) === array_values(array_filter(
    jewellery_pending_order_lines($cidA),
    static fn (array $r): bool => (int) $r['order_id'] !== $multiOrder
)), 'And neither is left on the pending board');

$balance = jewellery_karigar_metal_balance($cidA, $kContractor);
// 5 tola issued at 916 = 4.58 fine out; the two pieces need 1.5 + 1.5 at 916.
ok(near($balance['held_fine'] - $balanceBefore['held_fine'], 4.58, 0.02),
    'The balance knows what this issue put in his hands: 4.58 fine');
ok(near($balance['committed_fine'] - $balanceBefore['committed_fine'], 2.748, 0.02),
    'And what the two new pieces need of it: 2.748 fine');
ok(near($balance['excess_fine'], jw_round_weight($balance['held_fine'] - $balance['committed_fine']), 0.001)
    && $balance['shortfall_fine'] < 0.00005,
    'The difference reads as EXCESS — he holds more than the work needs');

// Issue too little and the same figure reads the other way.
$shortOrder = jewellery_save_order($cidA, $fyA, [
    'order_date' => '2026-09-03', 'party_id' => $customer, 'status' => 'confirmed',
], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 10, 'rate' => 150000, 'karigar_id' => $kEmployee, 'delivery_date' => '2026-09-30'],
], $userA);
jewellery_issue_to_karigar($cidA, $fyA, [
    'order_line_ids' => [(int) jewellery_order_line_rows($cidA, $shortOrder)[0]['id']],
    'issued_gross_weight' => 4, 'issue_date' => '2026-09-04',
], $userA);
$shortBalance = jewellery_karigar_metal_balance($cidA, $kEmployee);
ok($shortBalance['shortfall_fine'] > 0.001 && $shortBalance['excess_fine'] < 0.00005,
    'Issuing less than the work needs reads as a SHORTFALL, not an error');

ok(!jewellery_issue_to_karigar($cidA, $fyA, [
    'order_line_ids' => [(int) $multiOrderLines[0]['id']], 'issue_date' => '2026-09-05',
], $userA)['ok'], 'An item already covered by an issue cannot be put on a second one');

echo "\n16. Cancelling and postponing an order\n";
$laterOrder = jewellery_save_order($cidA, $fyA, [
    'order_date' => '2026-09-10', 'party_id' => $customer, 'status' => 'confirmed',
], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1, 'rate' => 150000, 'delivery_date' => '2026-09-20'],
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1, 'rate' => 150000, 'delivery_date' => '2026-09-22'],
], $userA);
$moved = jewellery_postpone_order($cidA, $laterOrder, '2026-10-15', 'Customer travelling', $userA);
ok($moved['ok'], 'An order can be postponed' . ($moved['ok'] ? '' : ' — ' . $moved['error']));
$movedLines = jewellery_order_line_rows($cidA, $laterOrder);
ok((string) $movedLines[0]['delivery_date'] === '2026-10-15'
    && (string) $movedLines[1]['delivery_date'] === '2026-10-15',
    'Every item still waiting moves to the new date');
ok((string) jewellery_order($cidA, $laterOrder)['delivery_date'] === '2026-10-15',
    "And the order's own promise follows");
ok(!jewellery_postpone_order($cidA, $laterOrder, '2026-09-01', '', $userA)['ok'],
    'It cannot be rescheduled to before the order was taken');

// An item already out with a kaligad keeps the date its issue was measured
// against — the craftsman was told a day and his wastage runs to it.
$fixedDate = (string) jewellery_order_line_rows($cidA, $multiOrder)[0]['delivery_date'];
jewellery_postpone_order($cidA, $multiOrder, '2026-11-30', '', $userA);
ok((string) jewellery_order_line_rows($cidA, $multiOrder)[0]['delivery_date'] === $fixedDate,
    'An item already with a kaligad keeps the date his issue was measured against');

ok(!jewellery_cancel_order($cidA, $multiOrder, '', $userA)['ok'],
    'An order with metal still out with a kaligad refuses to cancel');
$cancelled = jewellery_cancel_order($cidA, $laterOrder, 'Customer changed their mind', $userA);
ok($cancelled['ok'], 'An order with nothing issued cancels');
ok((string) jewellery_order($cidA, $laterOrder)['status'] === 'cancelled', 'And it reads as cancelled');
ok(!jewellery_cancel_order($cidA, $laterOrder, '', $userA)['ok'], 'Cancelling twice is refused');

echo "\n17. Orders nobody came in to collect\n";
// The piece is made, the gold is in the safe, and it is the customer's. The
// shop is insuring metal it cannot sell.
$overdue = jewellery_overdue_orders($cidA, '2026-10-01');
$overdueNos = array_column($overdue['rows'], 'order_no');
$multiNo = (string) jewellery_order($cidA, $multiOrder)['order_no'];
$laterNo = (string) jewellery_order($cidA, $laterOrder)['order_no'];
ok(in_array($multiNo, $overdueNos, true), 'An order promised before today and not delivered shows up');
ok(!in_array($laterNo, $overdueNos, true), 'A cancelled one does not');
$overdueRow = null;
foreach ($overdue['rows'] as $candidate) {
    if ((string) $candidate['order_no'] === $multiNo) { $overdueRow = $candidate; }
}
ok($overdueRow !== null && (int) $overdueRow['days_late'] > 0, 'It says how many days late it is');
ok($overdueRow !== null && near((float) $overdueRow['balance_due'],
    (float) $overdueRow['total_amount'] - (float) $overdueRow['advance_amount']),
    'And what is still to collect, net of the advance already taken');
ok(jewellery_overdue_orders($cidA, '2026-09-01')['rows'] === []
    || count(jewellery_overdue_orders($cidA, '2026-09-01')['rows']) < count($overdue['rows']),
    'Asked as at an earlier date, fewer are late — the report is a date question, not a status flag');

echo "\n18. The list filters actually filter\n";
// A filter that renders but does not narrow the list is worse than none: it
// tells the user the list is complete when it is not.
$filterOrder = jewellery_save_order($cidA, $fyA, [
    'order_date' => '2026-09-15', 'party_id' => $customer, 'status' => 'confirmed',
    'design_no' => 'FIND-ME-77',
], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1, 'rate' => 150000, 'karigar_id' => $kEmployee, 'delivery_date' => '2026-09-28'],
], $userA);
$filterNo = (string) jewellery_order($cidA, $filterOrder)['order_no'];

$bySearch = array_column(jewellery_orders_list($cidA, ['search' => 'FIND-ME-77']), 'order_no');
ok($bySearch === [$filterNo], 'Searching the design number finds exactly that order');
ok(array_column(jewellery_orders_list($cidA, ['search' => $filterNo]), 'order_no') === [$filterNo],
    'And so does searching its number');
ok(jewellery_orders_list($cidA, ['search' => 'NOTHING-MATCHES-THIS']) === [],
    'A search that matches nothing returns nothing, not everything');

$byStatus = jewellery_orders_list($cidA, ['status' => 'cancelled']);
foreach ($byStatus as $statusRow) {
    ok((string) $statusRow['status'] === 'cancelled', 'Status filter returns only that status');
    break;
}
ok(count(jewellery_orders_list($cidA, ['status' => 'confirmed']))
    < count(jewellery_orders_list($cidA, [])), 'A status filter narrows the list');

$byKarigar = jewellery_orders_list($cidA, ['karigar_id' => $kEmployee]);
$byKarigarNos = array_column($byKarigar, 'order_no');
ok(in_array($filterNo, $byKarigarNos, true), "Filtering by kaligad finds the order whose ITEM names him");
ok(!in_array($filterNo, array_column(jewellery_orders_list($cidA, ['karigar_id' => $kContractor]), 'order_no'), true),
    'And not one whose items name somebody else');

$byParty = jewellery_orders_list($cidA, ['party_id' => $customer]);
ok($byParty !== [] && count($byParty) <= count(jewellery_orders_list($cidA, [])),
    'Filtering by customer narrows to that customer');
ok(jewellery_orders_list($cidA, ['party_id' => 999999]) === [], 'A customer with no orders returns none');

$overdueList = jewellery_orders_list($cidA, ['overdue_only' => true]);
foreach ($overdueList as $overdueRow) {
    ok((string) $overdueRow['delivery_date'] < date('Y-m-d')
        && !in_array((string) $overdueRow['status'], ['delivered', 'cancelled'], true),
        'The past-due filter returns only orders promised before today and not closed');
    break;
}

$byDate = jewellery_orders_list($cidA, ['from' => '2026-09-15', 'to' => '2026-09-15']);
ok(array_column($byDate, 'order_no') === [$filterNo], 'A one-day range returns just that day');

// The same on assignments.
ok(jewellery_assignments_list($cidA, ['search' => 'NOTHING-MATCHES-THIS']) === [],
    'Assignments: a search that matches nothing returns nothing');
$issuedOnly = jewellery_assignments_list($cidA, ['status' => 'issued']);
foreach ($issuedOnly as $issuedRow) {
    ok((string) $issuedRow['status'] === 'issued', 'Assignments: the status filter holds');
    break;
}
ok(jewellery_assignments_list($cidA, ['karigar_id' => 999999]) === [],
    'Assignments: a kaligad with no issues returns none');

echo "\n19. A kaligad who adds metal of his own is paid for it, not refused\n";
// A kaligad short of gold tops up from his own and hands back a heavier piece.
// That used to be refused outright — "record the extra as a separate purchase"
// — which is exactly what the receipt now does itself.
$surplusIssue = jewellery_issue_to_karigar($cidA, $fyA, [
    'karigar_id' => $kContractor, 'item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola,
    'issued_gross_weight' => 4, 'issue_date' => '2026-10-01',
    'wastage_allowed_pct' => 0, 'making_basis' => 'flat', 'making_rate' => 500,
], $userA);
ok($surplusIssue['ok'], 'Four tola go out' . ($surplusIssue['ok'] ? '' : ' — ' . $surplusIssue['error']));
$surplusAid = (int) $surplusIssue['assignment_id'];

// Five tola come back at the same purity: one tola of his own gold.
$surplusPreview = jewellery_preview_receipt($cidA, $surplusAid, 5.0, $p22);
ok(near($surplusPreview['surplus_fine'], 0.916, 0.002), 'The surplus is measured in fine: one tola at 916');
ok($surplusPreview['wastage_fine'] < 0.00005, 'And it is not wastage — the two can never both be non-zero');
ok($surplusPreview['surplus_amount'] > 0.005, 'It is valued at the rate the issue was valued at');
ok(near($surplusPreview['net_payable'],
    $surplusPreview['making_amount'] - $surplusPreview['recovery_amount'] + $surplusPreview['surplus_amount']),
    'And it joins his wages — the shop bought that gold from him');

$stockBefore = jw_item_balance($cidA, $chain, null, 'stock')['fine_weight'];
$surplusReceipt = jewellery_receive_from_karigar($cidA, $fyA, [
    'assignment_id' => $surplusAid, 'received_gross_weight' => 5.0, 'received_purity_id' => $p22,
    'qty_pieces' => 1, 'receive_date' => '2026-10-05',
], $userA);
ok($surplusReceipt['ok'], 'The receipt is accepted rather than refused'
    . ($surplusReceipt['ok'] ? '' : ' — ' . $surplusReceipt['error']));

$surplusVoucher = voucher_shape((int) $surplusReceipt['voucher_id']);
ok(near($surplusVoucher['dr'], $surplusVoucher['cr']),
    'Its voucher balances — the surplus is debited to stock and credited to the kaligad');
ok(near(jw_item_balance($cidA, $chain, null, 'stock')['fine_weight'] - $stockBefore, 4.58, 0.02),
    'Five tola of finished metal land in stock, all 4.58 fine of it');
ok(near(jewellery_holder_metal_position($cidA, 'karigar', $kContractor)['fine_weight'],
    jewellery_karigar_metal_balance($cidA, $kContractor)['held_fine']),
    'And the kaligad is cleared of the issue, holding only what other jobs left him');

echo "\n20. An advance can be taken in any metal, not only gold\n";
// A customer pays an advance in whatever they walk in with — old gold, silver,
// a diamond. The engine only asks that the purity belong to the item's metal.
$silver = $q("SELECT id FROM jewellery_metals WHERE company_id=$cidA AND code='SILVER'");
$pSilver = $q("SELECT id FROM jewellery_purities WHERE company_id=$cidA AND metal_id=$silver LIMIT 1");
$silverItem = jewellery_save_item($cidA, ['code' => 'SIL-1', 'name' => 'Old Silver', 'item_type' => 'bullion',
    'metal_id' => $silver, 'purity_id' => $pSilver, 'unit_id' => $tola], $userA);
$advanceOrder = jewellery_save_order($cidA, $fyA, [
    'order_date' => '2026-10-06', 'party_id' => $customer, 'status' => 'confirmed',
], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 2, 'rate' => 150000, 'making_amount' => 2000],
], $userA);

$silverAdvance = jewellery_save_settlement($cidA, $fyA, [
    'settlement_date' => '2026-10-06', 'party_id' => $customer, 'order_id' => $advanceOrder,
    'is_advance' => 1, 'direction' => 'received', 'mode' => 'metal',
    'item_id' => $silverItem, 'purity_id' => $pSilver, 'unit_id' => $tola,
    'gross_weight' => 3, 'amount' => 6000,
], [], $userA);
ok($silverAdvance > 0, 'An advance is taken in SILVER, not gold');
ok(jewellery_post_settlement($cidA, $silverAdvance, $userA)['ok'], 'And it posts');

// The pairing is still enforced: a gold purity on a silver item is refused,
// which is why the form now narrows the purity list to the item's metal.
ok(threw(static fn () => jewellery_save_settlement($cidA, $fyA, [
    'settlement_date' => '2026-10-06', 'party_id' => $customer, 'order_id' => $advanceOrder,
    'is_advance' => 1, 'direction' => 'received', 'mode' => 'metal',
    'item_id' => $silverItem, 'purity_id' => $p22, 'unit_id' => $tola,
    'gross_weight' => 1, 'amount' => 2000,
], [], $userA)), "A gold purity on a silver item is still refused");

$advanceHeld = jewellery_order_advances($cidA, $advanceOrder);
ok(near((float) $advanceHeld['metal_total'], 6000.0),
    'The silver is held against the order the same way gold would be');

echo "\n21. An order is only 'received' when EVERY piece is back\n";
/*
 * Three pieces, three benches, three different days. The status used to move on
 * the FIRST event of each kind, so one ring coming back marked the whole order
 * received and put it on the ready-to-deliver list — with two bangles still at
 * the karigar's. Somebody would have gone to fetch it and it would not be there.
 */
$threeOrder = jewellery_save_order($cidA, $fyA, [
    'order_date' => '2026-08-14', 'party_id' => $customer, 'status' => 'confirmed',
], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1, 'rate' => 150000, 'making_amount' => 1000,
     'karigar_id' => $kContractor, 'delivery_date' => '2026-09-10'],
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1, 'rate' => 150000, 'making_amount' => 1000,
     'karigar_id' => $kEmployee, 'delivery_date' => '2026-09-11'],
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1, 'rate' => 150000, 'making_amount' => 1000,
     'karigar_id' => $kContractor, 'delivery_date' => '2026-09-12'],
], $userA);
$threeLines = jewellery_order_line_rows($cidA, $threeOrder);
ok(count($threeLines) === 3, 'Three pieces on one order');

$statusOf = static fn (): string => (string) jewellery_order($cidA, $threeOrder)['status'];
ok($statusOf() === 'confirmed', 'Nothing is out yet, so it is merely confirmed');

$issues = [];
foreach ($threeLines as $i => $threeLine) {
    $issue = jewellery_issue_to_karigar($cidA, $fyA, [
        'order_line_id' => (int) $threeLine['id'], 'issue_date' => '2026-08-15',
    ], $userA);
    ok($issue['ok'], 'Piece ' . ($i + 1) . ' goes out' . ($issue['ok'] ? '' : ' — ' . $issue['error']));
    $issues[] = (int) $issue['assignment_id'];
    ok($statusOf() === 'assigned', 'With ' . ($i + 1) . ' of 3 out, the order reads assigned');
}

// Now the part that used to be wrong.
$receiveOne = static function (int $assignmentId, string $day) use ($cidA, $fyA, $userA, $p22, $tola): array {
    $assignmentRow = jewellery_assignment($cidA, $assignmentId);

    return jewellery_receive_from_karigar($cidA, $fyA, [
        'assignment_id' => $assignmentId, 'receive_date' => $day,
        'received_item_id' => (int) $assignmentRow['item_id'],
        'received_purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
        'received_gross_weight' => (float) $assignmentRow['issued_gross_weight'],
    ], $userA);
};
$got1 = $receiveOne($issues[0], '2026-08-20');
ok($got1['ok'], 'The first piece comes back' . ($got1['ok'] ? '' : ' — ' . $got1['error']));
// Since migration 093 the in-between state has its own word, so the counter
// can answer "how many still to come?" without opening the order.
ok($statusOf() === 'partially_received',
    'ONE piece back does NOT make the order received — it is PARTIALLY received');
ok(!in_array($threeOrder, array_map('intval', array_column(jewellery_pending_delivery($cidA), 'id')), true),
    'So it stays off the ready-to-deliver list, which is where the old bug did its damage');

$got2 = $receiveOne($issues[1], '2026-08-21');
ok($got2['ok'] && $statusOf() === 'partially_received', 'Two back, one out: still not ready');

$got3 = $receiveOne($issues[2], '2026-08-22');
ok($got3['ok'], 'The last piece comes back' . ($got3['ok'] ? '' : ' — ' . $got3['error']));
ok($statusOf() === 'received', 'NOW the order is received');
ok(in_array($threeOrder, array_map('intval', array_column(jewellery_pending_delivery($cidA), 'id')), true),
    'And only now does it appear as ready to hand over');

// Undoing a receipt has to walk back the same way.
ok(jewellery_unpost_receipt($cidA, (int) $got3['receipt_id'], $userA)['ok'], 'The last receipt is unposted');
ok($statusOf() === 'partially_received', 'Which puts the order back to partly-made, not stuck on received');

// And cancelling ONE issue must not pretend the whole order left the workshop.
$stillOut = jewellery_assignment($cidA, $issues[2]);
ok((string) $stillOut['status'] === 'issued', 'That piece is out with the karigar again');
ok(jewellery_cancel_assignment($cidA, $issues[2], $userA)['ok'], 'Its issue is cancelled');
/*
 * Cancelling that issue sent the metal back to the shop UNMADE, so the third
 * piece does not exist. Two of three are finished and one has not been started:
 * the order is partially received, and must NOT read as received — the customer
 * asked for three. It does not drop to "confirmed" either: work has happened.
 */
ok($statusOf() === 'partially_received',
    'A cancelled issue leaves the order in progress — not ready to deliver, and not back to square one');
ok(!in_array($threeOrder, array_map('intval', array_column(jewellery_pending_delivery($cidA), 'id')), true),
    'So it is off the delivery list again: two pieces made is not three pieces made');

/*
 * The engine can no longer produce a wrong status, but databases that ran the
 * old code still hold them, so migration 090 recomputes what is already stored.
 * Testing it means putting the books back into the broken state by hand — the
 * only way to get there now — and checking the repair walks it out again.
 */
db()->prepare("UPDATE jewellery_orders SET status = 'received' WHERE id = :id AND company_id = :cid")
    ->execute(['id' => $threeOrder, 'cid' => $cidA]);
ok($statusOf() === 'received', 'An order stored the way the old rule left it');
accounting_module_repair_database();
ok($statusOf() === 'partially_received', 'The repair corrects it from the items themselves');
accounting_module_repair_database();
ok($statusOf() === 'partially_received', 'And running the repair twice does not drift it');

// It must correct in the other direction too, not just downgrade.
db()->prepare("UPDATE jewellery_orders SET status = 'assigned' WHERE id = :id AND company_id = :cid")
    ->execute(['id' => $threeOrder, 'cid' => $cidA]);
db()->prepare("UPDATE jewellery_order_assignments SET status = 'received'
    WHERE order_id = :id AND company_id = :cid AND status <> 'cancelled'")
    ->execute(['id' => $threeOrder, 'cid' => $cidA]);
// The third item was freed by the cancellation, so point it at a finished issue
// to make this a genuinely everything-is-back order.
db()->prepare("UPDATE jewellery_order_lines SET assignment_id = (
        SELECT id FROM (SELECT id FROM jewellery_order_assignments
                         WHERE order_id = :o1 AND company_id = :c1 AND status = 'received' LIMIT 1) x)
    WHERE order_id = :o2 AND company_id = :c2 AND (assignment_id IS NULL OR assignment_id = 0)")
    ->execute(['o1' => $threeOrder, 'c1' => $cidA, 'o2' => $threeOrder, 'c2' => $cidA]);
accounting_module_repair_database();
ok($statusOf() === 'received', 'With every item back it raises the order to received');

// And a delivered order is a person's decision the repair may not touch.
db()->prepare("UPDATE jewellery_orders SET status = 'delivered' WHERE id = :id AND company_id = :cid")
    ->execute(['id' => $threeOrder, 'cid' => $cidA]);
db()->prepare("UPDATE jewellery_order_assignments SET status = 'issued'
    WHERE order_id = :id AND company_id = :cid")
    ->execute(['id' => $threeOrder, 'cid' => $cidA]);
accounting_module_repair_database();
ok($statusOf() === 'delivered',
    'A delivered order is left alone whatever the items say — the goods are with the customer');

echo "\n22. Letting a shortfall go is a decision made on the receipt\n";
/*
 * The shop CAN forgive a loss — it simply has to say so, on the day, having
 * seen it. Proving it end to end matters more than proving the arithmetic:
 * a grant that changed the preview but not the posted voucher would quietly
 * pay the kaligad one figure and book another.
 */
$forgiveIssue = jewellery_issue_to_karigar($cidA, $fyA, [
    'karigar_id' => $kContractor, 'item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola,
    'issued_gross_weight' => 2, 'issue_date' => '2026-08-22', 'making_basis' => 'flat', 'making_rate' => 4000,
], $userA);
ok($forgiveIssue['ok'], 'Two tola go out with no allowance'
    . ($forgiveIssue['ok'] ? '' : ' — ' . $forgiveIssue['error']));
$forgiveAid = (int) $forgiveIssue['assignment_id'];
$forgiveIssued = (float) jewellery_assignment($cidA, $forgiveAid)['issued_fine_weight'];

// He returns a piece 0.02 fine light.
$backGrossFine = jw_round_weight($forgiveIssued - 0.02);
$backGross = jw_round_weight($backGrossFine / 0.916);
$strictPreview = jewellery_preview_receipt($cidA, $forgiveAid, $backGross, $p22);
ok($strictPreview['excess_fine'] > 0.019, 'Unforgiven, the whole shortfall is his');
$strictRecovery = (float) $strictPreview['recovery_amount'];
ok($strictRecovery > 0, 'So something is recovered from his wages');

// Now the shop grants exactly that shortfall.
$forgivePreview = jewellery_preview_receipt($cidA, $forgiveAid, $backGross, $p22, $strictPreview['wastage_fine']);
ok(near($forgivePreview['excess_fine'], 0.0), 'Granted the full shortfall, he bears nothing');
ok(near($forgivePreview['recovery_amount'], 0.0), 'And nothing is taken off his wages');
ok($forgivePreview['net_payable'] > $strictPreview['net_payable'],
    'So he is paid more than he would have been — which is the whole point of granting it');

// And it must survive into the books, not just the screen.
$forgiveRec = jewellery_receive_from_karigar($cidA, $fyA, [
    'assignment_id' => $forgiveAid, 'received_item_id' => $chain, 'received_purity_id' => $p22,
    'received_gross_weight' => $backGross, 'qty_pieces' => 1, 'receive_date' => '2026-08-25',
    'allow_wastage_fine' => $strictPreview['wastage_fine'],
], $userA);
ok($forgiveRec['ok'], 'The receipt posts with the grant'
    . ($forgiveRec['ok'] ? '' : ' — ' . $forgiveRec['error']));
ok(near((float) $forgiveRec['recovery_amount'], 0.0),
    'The POSTED receipt recovers nothing — the grant reached the books, not only the preview');
$forgiveRow = db()->query("SELECT * FROM jewellery_order_receipts WHERE id = " . (int) $forgiveRec['receipt_id'])->fetch(PDO::FETCH_ASSOC);
ok($forgiveRow && near((float) $forgiveRow['wastage_allowed_fine'], (float) $strictPreview['wastage_fine'], 0.0002),
    'And the receipt records how much was let go, so the concession is on the record');
ok($forgiveRow && near((float) $forgiveRow['excess_wastage_fine'], 0.0),
    'With nothing left charged against him');

// Blank means none — it must not be read as "allow 0.0000 deliberately" or,
// worse, as some default.
$blankIssue = jewellery_issue_to_karigar($cidA, $fyA, [
    'karigar_id' => $kContractor, 'item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola,
    'issued_gross_weight' => 2, 'issue_date' => '2026-08-26', 'making_basis' => 'flat', 'making_rate' => 4000,
], $userA);
$blankAid = (int) $blankIssue['assignment_id'];
$blankBack = jw_round_weight((jw_round_weight((float) jewellery_assignment($cidA, $blankAid)['issued_fine_weight'] - 0.02)) / 0.916);
$blankRec = jewellery_receive_from_karigar($cidA, $fyA, [
    'assignment_id' => $blankAid, 'received_item_id' => $chain, 'received_purity_id' => $p22,
    'received_gross_weight' => $blankBack, 'qty_pieces' => 1, 'receive_date' => '2026-08-27',
    'allow_wastage_fine' => '',
], $userA);
ok($blankRec['ok'] && (float) $blankRec['recovery_amount'] > 0,
    'A blank allowance forgives nothing — he bears the shortfall, which is the rule');

echo "\n23. Stones are not gold — the receipt weighs them apart\n";
// 2 tola of 22K goes out (1.832 fine). Back comes a stone-set piece: 2.1 on
// the scale, of which 0.6 is stone. The metal returned is 1.5 tola = 1.374
// fine; the missing 0.458 fine is wastage the kaligad bears. Counting the
// stones as gold would have called it 1.9236 fine back — a SURPLUS — and
// paid him for metal that is actually rock.
$stoneIssue = jewellery_issue_to_karigar($cidA, $fyA, [
    'karigar_id' => $kContractor, 'item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola,
    'issued_gross_weight' => 2, 'issue_date' => '2026-08-28', 'making_basis' => 'flat', 'making_rate' => 5000,
], $userA);
ok($stoneIssue['ok'], 'Two tola go out for a stone-set ring');
$stoneAid = (int) $stoneIssue['assignment_id'];

$stonePreview = jewellery_preview_receipt($cidA, $stoneAid, 2.1, null, null, 0.6);
ok($stonePreview['ok'], 'The preview accepts the stone weight');
ok(near((float) $stonePreview['net_gold_weight'], 1.5), 'Net gold is the scale less the stones: 2.1 − 0.6 = 1.5');
ok(near((float) $stonePreview['received_fine'], 1.374), 'The fine equivalent is computed over the METAL only (1.5 × 916 = 1.374)');
ok(near((float) $stonePreview['wastage_fine'], 0.458), 'So the wastage is honest: 1.832 − 1.374 = 0.458 fine');
ok((float) $stonePreview['surplus_fine'] < 0.0001,
    'No phantom surplus — counting stones as gold would have shown one');

$badStone = jewellery_preview_receipt($cidA, $stoneAid, 2.1, null, null, 2.1);
ok(!$badStone['ok'], 'Stones weighing as much as the whole piece are refused — the scale is being misread');
ok(!jewellery_preview_receipt($cidA, $stoneAid, 2.1, null, null, -0.5)['ok'], 'A negative stone weight is refused');

$stoneRec = jewellery_receive_from_karigar($cidA, $fyA, [
    'assignment_id' => $stoneAid, 'received_item_id' => $chain, 'received_purity_id' => $p22,
    'received_gross_weight' => 2.1, 'stone_weight' => 0.6, 'qty_pieces' => 1, 'receive_date' => '2026-08-29',
], $userA);
ok($stoneRec['ok'], 'The stone-set receipt posts' . ($stoneRec['ok'] ? '' : ' — ' . $stoneRec['error']));
$stoneRow = db()->query('SELECT * FROM jewellery_order_receipts WHERE id = ' . (int) $stoneRec['receipt_id'])->fetch(PDO::FETCH_ASSOC);
ok($stoneRow && near((float) $stoneRow['stone_weight'], 0.6) && near((float) $stoneRow['net_gold_weight'], 1.5),
    'The receipt records the stones and the net gold, so both weights can always be shown together');
ok($stoneRow && near((float) $stoneRow['received_fine_weight'], 1.374),
    'And the stored fine weight is metal only');
ok($stoneRow && near((float) $stoneRow['excess_wastage_fine'], 0.458, 0.0002),
    'The kaligad bears the true metal shortfall, not one the stones papered over');

// A karigar receiving with no stones is exactly as before — 095 changes
// nothing for the plain case.
$plainRow = db()->query("SELECT received_gross_weight, net_gold_weight FROM jewellery_order_receipts
    WHERE company_id = $cidA AND stone_weight = 0 AND received_gross_weight > 0 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok($plainRow && near((float) $plainRow['net_gold_weight'], (float) $plainRow['received_gross_weight']),
    'A stoneless receipt has net gold = gross, same meaning as every receipt before 095');

// Clearing the weight while stones are typed used to preview a NEGATIVE net
// gold and a recovery larger than everything issued.
ok(!jewellery_preview_receipt($cidA, $stoneAid, 0, null, null, 0.6)['ok'],
    'Stones typed against a cleared weight are refused, not previewed as negative gold');

// And the stones follow the piece onto the BILL. An order is made, comes back
// stone-set, and the sale prefill must carry the receipt\'s stone weight —
// or the bill prices the whole scale weight as gold and draws more fine out
// of stock than the receipt ever put in.
$stoneOrder = jewellery_save_order($cidA, $fyA, [
    'order_date' => '2026-08-28', 'party_id' => $customer, 'item_id' => $chain,
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola,
    'expected_gross_weight' => 2, 'making_basis' => 'flat', 'making_rate' => 5000, 'status' => 'confirmed',
], [], $userA);
$stoneOrderIssue = jewellery_issue_to_karigar($cidA, $fyA, [
    'karigar_id' => $kContractor, 'order_id' => $stoneOrder, 'item_id' => $chain, 'purity_id' => $p22,
    'unit_id' => $tola, 'issued_gross_weight' => 2, 'issue_date' => '2026-08-28',
    'making_basis' => 'flat', 'making_rate' => 5000,
], $userA);
ok($stoneOrderIssue['ok'], 'Metal goes out against the stone order');
$stoneOrderRec = jewellery_receive_from_karigar($cidA, $fyA, [
    'assignment_id' => (int) $stoneOrderIssue['assignment_id'], 'received_item_id' => $chain,
    'received_purity_id' => $p22, 'received_gross_weight' => 2.2, 'stone_weight' => 0.5,
    'qty_pieces' => 1, 'receive_date' => '2026-08-29',
], $userA);
ok($stoneOrderRec['ok'], 'It comes back stone-set: 2.2 gross, 0.5 stone');
$stonePrefill = jewellery_order_sale_prefill($cidA, $stoneOrder);
ok($stonePrefill['ok'] && near((float) $stonePrefill['line']['gross_weight'], 2.2)
    && near((float) $stonePrefill['line']['stone_weight'], 0.5),
    'The bill prefills the receipt\'s stones with its weight — rock is not billed at the gold rate');

echo "\n24. What the customer asked for, sized per piece, numbered by hand\n";
// expected_item is the customer's own words; size is per ITEM; the order
// number can be the shop's own — and none of it may collide or vanish.
$specOrder = jewellery_save_order($cidA, $fyA, [
    'order_date' => '2026-08-30', 'party_id' => $customer, 'order_no' => 'HAND-0042',
    'expected_item' => 'Bridal set with matching bangles',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'status' => 'confirmed',
], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1, 'rate' => 150000, 'size' => 'ring 7', 'notes' => 'engrave "S+R"'],
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 2, 'rate' => 150000, 'size' => '22 inch', 'notes' => 'matte finish'],
], $userA);
$specRow = jewellery_order($cidA, $specOrder);
ok((string) $specRow['order_no'] === 'HAND-0042', 'A hand-typed order number is honoured');
ok((string) $specRow['expected_item'] === 'Bridal set with matching bangles',
    'The customer\'s own words are stored apart from the description');
$specLines = jewellery_order_line_rows($cidA, $specOrder);
ok(count($specLines) === 2 && (string) $specLines[0]['size'] === 'ring 7' && (string) $specLines[1]['size'] === '22 inch',
    'Each piece keeps ITS OWN size — a ring for her, a chain for him');
ok((string) $specLines[0]['notes'] === 'engrave "S+R"' && (string) $specLines[1]['notes'] === 'matte finish',
    'And its own note — the engraving goes on the right piece');

ok(threw(static fn () => jewellery_save_order($cidA, $fyA, [
    'order_date' => '2026-08-30', 'party_id' => $customer, 'order_no' => 'HAND-0042',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'status' => 'confirmed',
], [], $userA)), 'A duplicate hand-typed number is refused with a sentence, not a stack trace');

ok(in_array('HAND-0042', array_column(jewellery_orders_list($cidA, ['search' => 'Bridal set']), 'order_no'), true),
    'Search finds the order by what the customer asked for');

// Revising the order WITHOUT mentioning expected_item keeps it — the $keep
// rule every header field obeys.
jewellery_save_order($cidA, $fyA, ['id' => $specOrder, 'order_date' => '2026-08-30',
    'party_id' => $customer, 'status' => 'confirmed'], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 1, 'rate' => 150000, 'size' => 'ring 7.5'],
], $userA);
$specAfter = jewellery_order($cidA, $specOrder);
ok((string) $specAfter['expected_item'] === 'Bridal set with matching bangles',
    'A revision that does not mention the expected item leaves it standing');
ok((string) jewellery_order_line_rows($cidA, $specOrder)[0]['size'] === 'ring 7.5',
    'And the revised line carries its corrected size');

echo "\n25. Deleting only what was never part of the record\n";
// A fresh kaligad with no history may go; one with an issue on record stays.
$freshK = jewellery_save_karigar($cidA, ['code' => 'K-DEL', 'name' => 'Never Used'], $userA);
$rDel = jewellery_delete_karigar($cidA, $freshK);
ok($rDel['ok'], 'A kaligad who never did anything deletes cleanly');
$rDel = jewellery_delete_karigar($cidA, $kContractor);
ok(!$rDel['ok'] && str_contains($rDel['error'], 'inactive'),
    'One with issues on record is refused and pointed at inactive instead');

// An issued assignment cannot be deleted; a cancelled one can — and its
// paired metal movements stay on the books.
$delIssue = jewellery_issue_to_karigar($cidA, $fyA, [
    'karigar_id' => $kContractor, 'item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola,
    'issued_gross_weight' => 1, 'issue_date' => '2026-08-30', 'making_basis' => 'flat', 'making_rate' => 1000,
], $userA);
$delAid = (int) $delIssue['assignment_id'];
$rDel = jewellery_delete_assignment($cidA, $delAid);
ok(!$rDel['ok'] && str_contains($rDel['error'], 'Cancel it first'),
    'An issued assignment refuses deletion — the metal is with the kaligad');
jewellery_cancel_assignment($cidA, $delAid, $userA);
// Cancellation already unwound the issue: its voucher and stock movements
// are gone (mutation-guarded), so the register row is the LAST trace and
// deleting it leaves nothing dangling.
$residue = (int) db()->query("SELECT COUNT(*) FROM jewellery_stock_txns
    WHERE company_id=$cidA AND source_type LIKE 'jewellery_karigar%' AND source_id=$delAid")->fetchColumn();
ok($residue === 0, 'Cancellation already unwound the metal movements');
$rDel = jewellery_delete_assignment($cidA, $delAid);
ok($rDel['ok'], 'Cancelled, it deletes from the register');
ok((int) db()->query("SELECT COUNT(*) FROM jewellery_order_assignments WHERE id=$delAid")->fetchColumn() === 0
    && (int) db()->query("SELECT COUNT(*) FROM jewellery_order_lines
        WHERE company_id=$cidA AND assignment_id=$delAid")->fetchColumn() === 0,
    'And nothing is left pointing at it');

jww_cleanup();
echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail === 0 ? 0 : 1);
