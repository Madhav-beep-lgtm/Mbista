<?php
declare(strict_types=1);

/**
 * Jewellery Accounting — Phase 2: item master, dual-unit stock ledger and
 * opening stock.
 *
 * Proves item validation and tenant binding, the derivation of net and fine
 * weight, the single movement choke point with its negative-stock guard,
 * holder-aware balances (own stock vs metal with a karigar), the metal
 * position report, running stock ledgers, and opening stock posting/unposting
 * with a real balanced voucher on the mapped ledgers.
 *   php database/test_jewellery_stock.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/jewellery_stock.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $tol = 0.011): bool { return abs($a - $b) < $tol; }
function threw(callable $fn): bool { try { $fn(); return false; } catch (Throwable $e) { return true; } }

function jws_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('JWSA','JWSB','JWSTRACE')")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$s)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['jewellery_stock_unit_events', 'jewellery_stock_units', 'jewellery_stock_txns', 'jewellery_item_profiles', 'inventory_items', 'jewellery_daily_rates',
                  'inventory_ledger_mappings', 'jewellery_item_categories', 'jewellery_settings',
                  'jewellery_purities', 'jewellery_metals', 'jewellery_units'] as $t) {
            db()->exec("DELETE FROM `$t` WHERE company_id=$s");
        }
        db()->exec("DELETE FROM accounting_parties WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email LIKE 'jwstock-%@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
jws_cleanup();

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------
$mkClient = static function (string $code, string $org, string $email): array {
    db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n, :c, 1, 1)')
        ->execute(['n' => $org . ' (Books)', 'c' => $code]);
    $companyId = (int) db()->lastInsertId();
    $uid = create_user(['name' => $org . ' Owner', 'email' => $email, 'password' => 'Secret#12345', 'role' => 'customer', 'status' => 'active', 'company_id' => $companyId]);
    db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, jewellery_accounting_enabled)
            VALUES (:uid, :cid, :books, :org, :code, 1, 1)')
        ->execute(['uid' => $uid, 'cid' => $companyId, 'books' => $companyId, 'org' => $org, 'code' => $code . '-C']);
    $fy = create_fiscal_year($companyId, $code . ' 2026/27', '2026-07-16', '2027-07-15', true);
    db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);

    return [$companyId, (int) $fy['id'], $uid];
};
[$cidA, $fyA, $userA] = $mkClient('JWSA', 'Kantipur Jewellers', 'jwstock-a@test.local');
[$cidB, $fyB, $userB] = $mkClient('JWSB', 'Himalaya Gold House', 'jwstock-b@test.local');
// The app always has a company + fiscal year in context; the shared opening
// poster dates its voucher from it, so the test must have one too.
set_context($cidA, $fyA);

$settingsA = jewellery_settings($cidA);
$settingsB = jewellery_settings($cidB);

$masterId = static function (int $cid, string $table, string $where): int {
    return (int) db()->query("SELECT id FROM $table WHERE company_id=$cid AND $where LIMIT 1")->fetchColumn();
};
$goldA = $masterId($cidA, 'jewellery_metals', "code='GOLD'");
$silverA = $masterId($cidA, 'jewellery_metals', "code='SILVER'");
$diamondA = $masterId($cidA, 'jewellery_metals', "code='DIAMOND'");
$p24A = $masterId($cidA, 'jewellery_purities', "metal_id=$goldA AND code='24K'");
$p22A = $masterId($cidA, 'jewellery_purities', "metal_id=$goldA AND code='22K'");
$pSilverA = $masterId($cidA, 'jewellery_purities', "metal_id=$silverA AND code='FINE'");
$pDiaA = $masterId($cidA, 'jewellery_purities', "metal_id=$diamondA AND code='STD'");
$tolaA = $masterId($cidA, 'jewellery_units', "code='TOLA'");
$gramA = $masterId($cidA, 'jewellery_units', "code='GM'");
$ctA = $masterId($cidA, 'jewellery_units', "code='CT'");
$goldB = $masterId($cidB, 'jewellery_metals', "code='GOLD'");
$p24B = $masterId($cidB, 'jewellery_purities', "metal_id=$goldB AND code='24K'");
$tolaB = $masterId($cidB, 'jewellery_units', "code='TOLA'");

// Ledgers, created the way the app creates them.
$mkLedger = static function (int $companyId, string $code, string $name, string $master): int {
    db()->prepare('INSERT INTO ledger_groups (company_id, name, code, master_key) VALUES (:cid,:n,:c,:m)')
        ->execute(['cid' => $companyId, 'n' => 'JW ' . $name, 'c' => 'JWG' . $code, 'm' => $master]);
    $gid = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO ledgers (company_id, group_id, name, code) VALUES (:cid,:g,:n,:c)')
        ->execute(['cid' => $companyId, 'g' => $gid, 'n' => $name, 'c' => $code]);

    return (int) db()->lastInsertId();
};
$ldgStockFin = $mkLedger($cidA, 'JWFIN', 'Finished Ornament Stock', 'assets');
$ldgStockMetal = $mkLedger($cidA, 'JWMET', 'Metal Stock', 'assets');
$ldgEquity = $mkLedger($cidA, 'JWEQ', 'Opening Balance Equity', 'equity');
$ldgStockB = $mkLedger($cidB, 'JWFIN', 'Finished Ornament Stock B', 'assets');

echo "1. Item master validation and tenant binding\n";
$ringId = jewellery_save_item($cidA, [
    'code' => 'RING22', 'name' => '22K Gold Ring', 'category' => 'Rings', 'item_type' => 'ornament',
    'metal_id' => $goldA, 'purity_id' => $p22A, 'unit_id' => $tolaA,
    'gross_weight' => 1.5, 'stone_weight' => 0.2, 'making_charge_rate' => 800,
], $userA);
ok($ringId > 0, 'Item saved');
$ring = jewellery_item($cidA, $ringId);
ok(near((float) $ring['net_weight'], 1.3), 'Net weight is DERIVED as gross - stone (1.5 - 0.2 = 1.3)');
ok((int) $ring['vat_applicable'] === 0, 'Items default to VAT not applicable');
ok(threw(static fn () => jewellery_save_item($cidA, ['code' => 'X', 'name' => 'X', 'metal_id' => $goldA, 'purity_id' => $pSilverA, 'unit_id' => $tolaA, 'gross_weight' => 1])),
    'A purity from a DIFFERENT metal is rejected');
ok(threw(static fn () => jewellery_save_item($cidA, ['code' => 'X', 'name' => 'X', 'metal_id' => $goldB, 'purity_id' => $p22A, 'unit_id' => $tolaA, 'gross_weight' => 1])),
    "Another tenant's metal is rejected");
ok(threw(static fn () => jewellery_save_item($cidA, ['code' => 'X', 'name' => 'X', 'metal_id' => $goldA, 'purity_id' => $p24B, 'unit_id' => $tolaA, 'gross_weight' => 1])),
    "Another tenant's purity is rejected");
ok(threw(static fn () => jewellery_save_item($cidA, ['code' => 'X', 'name' => 'X', 'metal_id' => $goldA, 'purity_id' => $p22A, 'unit_id' => $tolaB, 'gross_weight' => 1])),
    "Another tenant's unit is rejected");
ok(threw(static fn () => jewellery_save_item($cidA, ['code' => 'X', 'name' => 'X', 'metal_id' => $goldA, 'purity_id' => $p22A, 'unit_id' => $tolaA, 'gross_weight' => 1, 'stone_weight' => 2])),
    'Stone weight above gross weight is rejected');
ok(threw(static fn () => jewellery_save_item($cidA, ['code' => '', 'name' => 'X', 'metal_id' => $goldA, 'purity_id' => $p22A, 'unit_id' => $tolaA])),
    'A blank code is rejected');
ok(jewellery_item($cidB, $ringId) === null, "Company B cannot read company A's item");

echo "\n2. Per-item VAT resolution\n";
$diaId = jewellery_save_item($cidA, [
    'code' => 'DIARING', 'name' => 'Diamond Ring', 'item_type' => 'stone',
    'metal_id' => $diamondA, 'purity_id' => $pDiaA, 'unit_id' => $ctA,
    'gross_weight' => 0.5, 'vat_applicable' => 1, 'vat_base' => 'full_value', 'stone_value' => 250000,
], $userA);
$dia = jewellery_item($cidA, $diaId);
ok((int) $dia['vat_applicable'] === 1, 'Diamond item is VAT applicable');
ok(jw_item_vat_base($dia, $settingsA) === 'full_value', 'Diamond VAT base is full value');
$chainId = jewellery_save_item($cidA, [
    'code' => 'CHAIN22', 'name' => '22K Chain', 'category' => 'Chains',
    'metal_id' => $goldA, 'purity_id' => $p22A, 'unit_id' => $tolaA,
    'gross_weight' => 5, 'vat_applicable' => 1, 'vat_base' => 'making_only', 'making_charge_rate' => 1200,
], $userA);
$chain = jewellery_item($cidA, $chainId);
ok(jw_item_vat_base($chain, $settingsA) === 'making_only', 'A gold chain can be taxed on the making charge only');
ok(jw_item_vat_base($ring, $settingsA) === 'full_value', "An item on 'default' inherits the company VAT base");
jewellery_save_settings($cidA, ['default_vat_base' => 'making_only'], $userA);
ok(jw_item_vat_base($ring, jewellery_settings($cidA)) === 'making_only', 'Changing the company default re-bases every default item at once');
jewellery_save_settings($cidA, ['default_vat_base' => 'full_value'], $userA);
ok(jw_item_vat_base($dia, jewellery_settings($cidA)) === 'full_value', "An item's explicit base is NOT affected by the company default");

echo "\n3. Stock movement choke point\n";
ok(threw(static fn () => jw_record_stock_txn($cidA, ['item_id' => $ringId, 'txn_type' => 'purchase', 'direction' => 'sideways', 'txn_date' => '2026-08-01', 'gross_weight' => 1])),
    'An invalid direction is rejected');
ok(threw(static fn () => jw_record_stock_txn($cidA, ['item_id' => $ringId, 'txn_type' => 'teleport', 'direction' => 'in', 'txn_date' => '2026-08-01', 'gross_weight' => 1])),
    'An unknown movement type is rejected');
ok(threw(static fn () => jw_record_stock_txn($cidB, ['item_id' => $ringId, 'txn_type' => 'purchase', 'direction' => 'in', 'txn_date' => '2026-08-01', 'gross_weight' => 1])),
    "Company B cannot move company A's item");
ok(threw(static fn () => jw_record_stock_txn($cidA, ['item_id' => $ringId, 'txn_type' => 'purchase', 'direction' => 'in', 'txn_date' => '2026-08-01', 'gross_weight' => 0, 'qty_pieces' => 0])),
    'A movement with neither weight nor pieces is rejected');
ok(threw(static fn () => jw_record_stock_txn($cidA, ['item_id' => $ringId, 'txn_type' => 'purchase', 'direction' => 'in', 'txn_date' => '2026-08-01', 'gross_weight' => -1])),
    'A negative weight is rejected');
ok(threw(static fn () => jw_record_stock_txn($cidA, ['item_id' => $ringId, 'txn_type' => 'purchase', 'direction' => 'in', 'txn_date' => '2026-08-01', 'gross_weight' => 1, 'purity_id' => $pSilverA])),
    "A purity from another metal is rejected on a movement");

$txn1 = jw_record_stock_txn($cidA, [
    'item_id' => $ringId, 'txn_type' => 'purchase', 'direction' => 'in', 'txn_date' => '2026-08-01',
    'qty_pieces' => 10, 'gross_weight' => 15.0, 'amount' => 2100000, 'created_by' => $userA,
]);
ok($txn1 > 0, 'Purchase movement recorded');
$txnRow = db()->query("SELECT * FROM jewellery_stock_txns WHERE id=$txn1")->fetch(PDO::FETCH_ASSOC);
ok(near((float) $txnRow['fine_weight'], 13.74), 'Fine weight is DERIVED: 15 tola at 916 = 13.74 fine');
ok((int) $txnRow['fiscal_year_id'] === $fyA, 'The fiscal year is derived from the movement DATE, not passed in');
ok((int) $txnRow['metal_id'] === $goldA, "The movement carries the item's metal for position reporting");

echo "\n4. Balances and weighted average cost\n";
$bal = jw_item_balance($cidA, $ringId);
ok(near($bal['gross_weight'], 15.0), 'Gross balance is 15 tola');
ok(near($bal['fine_weight'], 13.74), 'Fine balance is 13.74 tola');
ok(near($bal['value'], 2100000.0), 'Value balance is 2,100,000');
ok(near($bal['qty_pieces'], 10.0), 'Piece balance is 10');
ok(near($bal['avg_fine_rate'], 152838.43, 0.5), 'Weighted average cost is 2,100,000 / 13.74 = 152,838.43 per fine tola');
// A second purchase at a different rate must move the average, not replace it.
jw_record_stock_txn($cidA, [
    'item_id' => $ringId, 'txn_type' => 'purchase', 'direction' => 'in', 'txn_date' => '2026-08-05',
    'qty_pieces' => 5, 'gross_weight' => 5.0, 'amount' => 800000, 'created_by' => $userA,
]);
$bal2 = jw_item_balance($cidA, $ringId);
ok(near($bal2['fine_weight'], 18.32), 'Fine balance accumulates to 18.32');
ok(near($bal2['avg_fine_rate'], 2900000.0 / 18.32, 0.5), 'The weighted average blends both purchases');
$balAsOf = jw_item_balance($cidA, $ringId, '2026-08-03');
ok(near($balAsOf['fine_weight'], 13.74), 'An as-at date excludes later movements');

echo "\n4b. Weighted average is MOVING, not an average of all inflows ever\n";
// The distinction only shows once a sale sits BETWEEN two purchases at
// different rates — which is exactly why it went unnoticed at first.
// Deliberately SILVER, not gold: the metal-position assertions further down
// pin exact 24K/22K figures, and a gold probe item would pollute them. Every
// movement below passes fine_weight explicitly, so the metal's fineness plays
// no part in the arithmetic being tested.
$movId = jewellery_save_item($cidA, ['code' => 'MOVAVG', 'name' => 'Moving average probe',
    'metal_id' => $silverA, 'purity_id' => $pSilverA, 'unit_id' => $tolaA, 'gross_weight' => 0], $userA);
jw_record_stock_txn($cidA, ['item_id' => $movId, 'txn_type' => 'purchase', 'direction' => 'in',
    'txn_date' => '2026-08-01', 'gross_weight' => 10.0, 'fine_weight' => 10.0, 'amount' => 1000.0]);
ok(near(jw_item_balance($cidA, $movId)['avg_fine_rate'], 100.0), 'After one purchase the average is 100');
jw_record_stock_txn($cidA, ['item_id' => $movId, 'txn_type' => 'sale', 'direction' => 'out',
    'txn_date' => '2026-08-02', 'gross_weight' => 5.0, 'fine_weight' => 5.0, 'amount' => 500.0]);
ok(near(jw_item_balance($cidA, $movId)['avg_fine_rate'], 100.0), 'Relieving 5 fine at cost leaves the average at 100');
jw_record_stock_txn($cidA, ['item_id' => $movId, 'txn_type' => 'purchase', 'direction' => 'in',
    'txn_date' => '2026-08-03', 'gross_weight' => 10.0, 'fine_weight' => 10.0, 'amount' => 2000.0]);
$movBal = jw_item_balance($cidA, $movId);
ok(near($movBal['fine_weight'], 15.0) && near($movBal['value'], 2500.0), 'Balance is now 15 fine worth 2,500');
// value_in / fine_in would be 3000/20 = 150 — the WRONG answer.
ok(near($movBal['avg_fine_rate'], 2500.0 / 15.0, 0.02),
    'The average is 2,500 / 15 = 166.67 (stock on hand), NOT 3,000 / 20 = 150 (all inflows ever)');
// Relieving everything at the moving average must empty the value ledger too;
// the all-inflow average would strand 250 of phantom value behind.
jw_record_stock_txn($cidA, ['item_id' => $movId, 'txn_type' => 'sale', 'direction' => 'out',
    'txn_date' => '2026-08-04', 'gross_weight' => 15.0, 'fine_weight' => 15.0,
    'amount' => jw_round_money(15.0 * $movBal['avg_fine_rate'])]);
$emptied = jw_item_balance($cidA, $movId);
ok(near($emptied['fine_weight'], 0.0), 'Selling the lot leaves zero fine weight');
ok(abs($emptied['value']) < 0.05, 'And zero value — no phantom cost stranded in the stock ledger, got ' . number_format($emptied['value'], 2));

echo "\n5. Negative stock guard\n";
ok(threw(static fn () => jw_record_stock_txn($cidA, [
    'item_id' => $ringId, 'txn_type' => 'sale', 'direction' => 'out', 'txn_date' => '2026-08-10',
    'gross_weight' => 100.0,
])), 'Selling more than is held is REFUSED while negative stock is off');
jewellery_save_settings($cidA, ['allow_negative_stock' => 1], $userA);
$oversell = jw_record_stock_txn($cidA, [
    'item_id' => $ringId, 'txn_type' => 'sale', 'direction' => 'out', 'txn_date' => '2026-08-10', 'gross_weight' => 100.0,
]);
ok($oversell > 0, 'With the company opt-in, an over-issue is allowed');
jw_delete_stock_txns($cidA, [$oversell]);
jewellery_save_settings($cidA, ['allow_negative_stock' => 0], $userA);
ok(near(jw_item_balance($cidA, $ringId)['fine_weight'], 18.32), 'Balance restored after removing the test over-issue');

// The piece check must be INDEPENDENT of the weight check. A movement that
// carries plenty of weight but far too many pieces has to be caught too.
ok(threw(static fn () => jw_record_stock_txn($cidA, [
    'item_id' => $ringId, 'txn_type' => 'sale', 'direction' => 'out', 'txn_date' => '2026-08-10',
    'gross_weight' => 1.0, 'qty_pieces' => 9999,
])), 'A movement with valid weight but impossible PIECES is refused');
ok(near(jw_item_balance($cidA, $ringId)['qty_pieces'], 15.0), 'The piece balance is untouched by the refused movement');

// The guard judges a movement on its OWN date, not on today's balance: stock
// that only arrives next week cannot cover a backdated sale.
$backdateId = jewellery_save_item($cidA, ['code' => 'BACKDT', 'name' => 'Backdating probe',
    'metal_id' => $silverA, 'purity_id' => $pSilverA, 'unit_id' => $tolaA, 'gross_weight' => 0], $userA);
jw_record_stock_txn($cidA, ['item_id' => $backdateId, 'txn_type' => 'purchase', 'direction' => 'in',
    'txn_date' => '2026-09-20', 'gross_weight' => 10.0, 'fine_weight' => 10.0, 'amount' => 1000.0]);
ok(threw(static fn () => jw_record_stock_txn($cidA, ['item_id' => $backdateId, 'txn_type' => 'sale',
    'direction' => 'out', 'txn_date' => '2026-09-01', 'gross_weight' => 5.0, 'fine_weight' => 5.0])),
    'A sale BACKDATED before the purchase that covers it is refused');
$laterSale = jw_record_stock_txn($cidA, ['item_id' => $backdateId, 'txn_type' => 'sale',
    'direction' => 'out', 'txn_date' => '2026-09-25', 'gross_weight' => 5.0, 'fine_weight' => 5.0, 'amount' => 500.0]);
ok($laterSale > 0, 'The same sale dated AFTER the purchase is allowed');

echo "\n6. Holder tracking — metal with a karigar\n";
// Issuing to a karigar takes metal OUT of own stock and puts it IN to that
// karigar's holding: the total position is unchanged, the location is not.
$karigarId = 77;
jw_record_stock_txn($cidA, ['item_id' => $ringId, 'txn_type' => 'issue_karigar', 'direction' => 'out',
    'txn_date' => '2026-08-12', 'gross_weight' => 5.0, 'holder_type' => 'stock', 'created_by' => $userA]);
jw_record_stock_txn($cidA, ['item_id' => $ringId, 'txn_type' => 'issue_karigar', 'direction' => 'in',
    'txn_date' => '2026-08-12', 'gross_weight' => 5.0, 'holder_type' => 'karigar', 'holder_id' => $karigarId, 'created_by' => $userA]);
$own = jw_item_balance($cidA, $ringId, null, 'stock');
$withKarigar = jw_item_balance($cidA, $ringId, null, 'karigar', $karigarId);
$everywhere = jw_item_balance($cidA, $ringId, null, '');
ok(near($own['fine_weight'], 13.74), 'Own stock drops by the issued 4.58 fine (18.32 - 4.58 = 13.74)');
ok(near($withKarigar['fine_weight'], 4.58), 'The karigar now holds 4.58 fine');
ok(near($everywhere['fine_weight'], 18.32), 'The TOTAL position is unchanged — the metal moved, it did not vanish');
ok(threw(static fn () => jw_record_stock_txn($cidA, ['item_id' => $ringId, 'txn_type' => 'receive_karigar',
    'direction' => 'out', 'txn_date' => '2026-08-13', 'gross_weight' => 50.0, 'holder_type' => 'karigar', 'holder_id' => $karigarId])),
    'A karigar cannot return more than that karigar holds');
$holdings = jewellery_item_holdings($cidA, $ringId);
ok(count($holdings) === 2, 'Holdings split into own stock and the karigar');

echo "\n7. Metal position report\n";
jewellery_save_item($cidA, ['code' => 'BAR24', 'name' => '24K Bullion Bar', 'item_type' => 'bullion',
    'metal_id' => $goldA, 'purity_id' => $p24A, 'unit_id' => $tolaA, 'gross_weight' => 10], $userA);
$barId = (int) db()->query("SELECT id FROM inventory_items WHERE company_id=$cidA AND sku='BAR24'")->fetchColumn();
jw_record_stock_txn($cidA, ['item_id' => $barId, 'txn_type' => 'purchase', 'direction' => 'in',
    'txn_date' => '2026-08-14', 'gross_weight' => 10.0, 'amount' => 1520000, 'created_by' => $userA]);
$position = jewellery_metal_position($cidA);
$byKey = [];
foreach ($position as $p) { $byKey[$p['purity_code'] . '|' . $p['holder_type']] = $p; }
ok(isset($byKey['24K|stock']) && near((float) $byKey['24K|stock']['fine'], 9.999), '24K position is 9.999 fine in own stock');
ok(isset($byKey['22K|stock']) && near((float) $byKey['22K|stock']['fine'], 13.74), '22K position is 13.74 fine in own stock');
ok(isset($byKey['22K|karigar']) && near((float) $byKey['22K|karigar']['fine'], 4.58), '22K position shows 4.58 fine with a karigar');
$ownOnly = jewellery_metal_position($cidA, null, ['holder_type' => 'stock']);
$ownHolders = array_unique(array_column($ownOnly, 'holder_type'));
ok($ownHolders === ['stock'], 'Filtering to own stock drops the karigar line');
ok(jewellery_metal_position($cidB) === [], "Company B's position is empty — no bleed from company A");

echo "\n8. Stock ledger running balance\n";
$ledger = jewellery_stock_ledger($cidA, $ringId, '2026-08-01', '2026-08-31');
ok(near($ledger['opening']['fine_weight'], 0.0), 'Opening before the first purchase is zero');
ok(count($ledger['rows']) === 4, 'Four movements in the window, got ' . count($ledger['rows']));
ok(near((float) $ledger['rows'][0]['balance_fine'], 13.74), 'Running balance after the first purchase is 13.74');
ok(near($ledger['closing']['fine_weight'], 18.32), 'Closing balance across ALL holders is 18.32');
$midLedger = jewellery_stock_ledger($cidA, $ringId, '2026-08-05', '2026-08-31');
ok(near($midLedger['opening']['fine_weight'], 13.74), 'A later window opens with the prior balance carried in');

echo "\n9. Opening stock lives on the SHARED item master\n";
// There is no jewellery opening table any more: an opening is the item's own
// opening_qty / opening_amount, posted through the shared inv poster.
ok(!table_exists('jewellery_opening_stock'), 'The separate jewellery opening table is gone');
$refused = jewellery_save_opening($cidA, $fyA, ['item_id' => $chainId, 'gross_weight' => 20.0, 'amount' => 2800000], $userA);
ok($refused['ok'], 'An opening saves' . ($refused['ok'] ? '' : ' — ' . $refused['error']));
ok(($refused['note'] ?? '') !== '' && $refused['voucher_id'] === 0,
    'But with no stock ledger mapped it reports the gap and posts NO voucher');
$masterRow = db()->query("SELECT opening_qty, opening_amount FROM inventory_items WHERE id=$chainId")->fetch(PDO::FETCH_ASSOC);
ok(near((float) $masterRow['opening_qty'], 20.0) && near((float) $masterRow['opening_amount'], 2800000.0),
    'The numbers are stored on inventory_items, where the core Inventory page and Opening Balances read them');
ok(near(jw_item_balance($cidA, $chainId)['fine_weight'], 18.32),
    'The metal leg still lands: 20 tola at 916 = 18.32 fine');

// Now map the ledgers and re-save: the money leg completes.
jewellery_save_mapping($cidA, 'stock_finished', $ldgStockFin, $userA);
jewellery_save_mapping($cidA, 'stock_metal', $ldgStockMetal, $userA);
jewellery_save_mapping($cidA, 'opening_equity', $ldgEquity, $userA);
$vouchersBefore = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cidA")->fetchColumn();
$posted = jewellery_save_opening($cidA, $fyA, ['item_id' => $chainId, 'gross_weight' => 20.0, 'qty_pieces' => 4, 'amount' => 2900000], $userA);
ok($posted['ok'] && $posted['voucher_id'] > 0, 'Once the ledgers are mapped it posts' . ($posted['ok'] ? '' : ' — ' . $posted['error']));
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cidA")->fetchColumn() === $vouchersBefore + 1,
    'Exactly ONE voucher was created');
$voucher = db()->query("SELECT * FROM vouchers WHERE id={$posted['voucher_id']}")->fetch(PDO::FETCH_ASSOC);
ok((string) $voucher['source_type'] === 'inventory_opening',
    'It is the SHARED inventory_opening voucher — not a jewellery-only one');
ok((string) $voucher['voucher_date'] === '2026-07-16', 'Dated on the first day of the fiscal year');
ok(near((float) $voucher['total_amount'], 2900000.0), 'And carries the revised opening value');

$entries = db()->query("SELECT * FROM voucher_entries WHERE voucher_id={$posted['voucher_id']}")->fetchAll(PDO::FETCH_ASSOC);
$dr = 0.0; $cr = 0.0; $drLedger = 0; $crLedger = 0;
foreach ($entries as $entry) {
    if ((string) $entry['entry_type'] === 'debit') { $dr += (float) $entry['amount']; $drLedger = (int) $entry['ledger_id']; }
    else { $cr += (float) $entry['amount']; $crLedger = (int) $entry['ledger_id']; }
}
ok(near($dr, $cr) && near($dr, 2900000.0), 'The voucher BALANCES at 2,900,000 on both sides');
ok($drLedger === $ldgStockFin, 'Stock is DEBITED to the finished-ornament ledger (an ornament item)');
ok($crLedger === $ldgEquity, 'Opening equity is CREDITED');
ok(near(jw_item_balance($cidA, $chainId)['value'], 2900000.0), 'And the metal ledger carries the same value');

echo "\n10. Re-saving CORRECTS an opening instead of stacking a second one\n";
// This is the whole reason for merging: two opening paths meant two vouchers.
$again = jewellery_save_opening($cidA, $fyA, ['item_id' => $chainId, 'gross_weight' => 20.0, 'qty_pieces' => 4, 'amount' => 3000000], $userA);
ok($again['ok'], 'The opening can be corrected by saving it again');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cidA AND source_type='inventory_opening' AND source_id=$chainId")->fetchColumn() === 1,
    'There is still exactly ONE opening voucher for the item, not two');
ok((int) db()->query("SELECT COUNT(*) FROM jewellery_stock_txns WHERE company_id=$cidA AND item_id=$chainId AND txn_type='opening'")->fetchColumn() === 1,
    'And exactly ONE opening metal movement');
ok(near(jw_item_balance($cidA, $chainId)['value'], 3000000.0), 'The corrected value replaced the old one');
// The register must still refuse to delete it behind the module's back.
$vCheck = db()->query("SELECT * FROM vouchers WHERE company_id=$cidA AND source_type='inventory_opening' AND source_id=$chainId")->fetch(PDO::FETCH_ASSOC);
$registerDelete = delete_voucher_with_entries((int) $vCheck['id'], $cidA, $userA);
ok(!$registerDelete['ok'], 'The Voucher Register still refuses to delete an opening voucher directly');

echo "\n11. Clearing an opening removes both legs together\n";
$cleared = jewellery_clear_opening($cidA, $chainId, $userA, $fyA);
ok($cleared['ok'], 'An opening can be cleared' . ($cleared['ok'] ? '' : ' — ' . $cleared['error']));
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cidA AND source_type='inventory_opening' AND source_id=$chainId")->fetchColumn() === 0,
    'Its voucher is gone');
ok((int) db()->query("SELECT COUNT(*) FROM jewellery_stock_txns WHERE company_id=$cidA AND item_id=$chainId AND txn_type='opening'")->fetchColumn() === 0,
    'Its metal movement is gone');
$clearedMaster = db()->query("SELECT opening_qty, opening_amount FROM inventory_items WHERE id=$chainId")->fetch(PDO::FETCH_ASSOC);
ok(near((float) $clearedMaster['opening_qty'], 0.0) && near((float) $clearedMaster['opening_amount'], 0.0),
    'And the shared master is zeroed, so Opening Balances agrees');
// A weight-only opening still moves metal without cluttering the register.
$vBefore = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cidA")->fetchColumn();
$zero = jewellery_save_opening($cidA, $fyA, ['item_id' => $barId, 'gross_weight' => 3.0, 'amount' => 0], $userA);
ok($zero['ok'] && $zero['voucher_id'] === 0, 'A weight-only opening posts no voucher');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cidA")->fetchColumn() === $vBefore, 'The register is untouched');
ok(near(jw_item_balance($cidA, $barId)['fine_weight'], 9.999 + 2.9997, 0.01), 'But the metal ledger records the 3 tola');
ok(!jewellery_save_opening($cidA, $fyA, ['item_id' => $barId, 'gross_weight' => -1], $userA)['ok'],
    'A negative opening is rejected');
ok(!jewellery_save_opening($cidB, $fyB, ['item_id' => $chainId, 'gross_weight' => 1], $userB)['ok'],
    "Company B cannot open stock for company A's item");


echo "\n12. Item master protects valued history\n";
ok(threw(static fn () => jewellery_save_item($cidA, ['id' => $ringId, 'code' => 'RING22', 'name' => '22K Gold Ring',
    'metal_id' => $goldA, 'purity_id' => $p24A, 'unit_id' => $tolaA, 'gross_weight' => 1.5])),
    'The purity of an item with movements can no longer be changed');
$renamed = jewellery_save_item($cidA, ['id' => $ringId, 'code' => 'RING22', 'name' => '22K Gold Ring (Classic)',
    'metal_id' => $goldA, 'purity_id' => $p22A, 'unit_id' => $tolaA, 'gross_weight' => 1.5, 'stone_weight' => 0.2], $userA);
ok($renamed === $ringId && jewellery_item($cidA, $ringId)['name'] === '22K Gold Ring (Classic)',
    'But descriptive fields remain editable');

echo "\n12b. The CORE Inventory form can complete a jewellery item\n";
// The item master is shared, so an item created on the Inventory page must not
// be invisible to Jewellery — otherwise the merge just moved the duplication.
db()->prepare("INSERT INTO inventory_items (company_id, sku, name, item_type, unit, status)
    VALUES (:cid, 'COREMADE', 'Made on the Inventory page', 'finished_good', 'TOLA', 'active')")
    ->execute(['cid' => $cidA]);
$coreItemId = (int) db()->lastInsertId();
ok(jewellery_item($cidA, $coreItemId) === null, 'A plain inventory item is NOT a jewellery item until given a profile');
jw_save_item_profile($cidA, $coreItemId, [
    'metal_id' => $goldA, 'purity_id' => $p22A, 'unit_id' => $tolaA,
    'jewellery_type' => 'ornament', 'gross_weight' => 4, 'stone_weight' => 1,
    'making_charge_rate' => 900, 'vat_applicable' => 1, 'vat_base' => 'making_only',
]);
$coreItem = jewellery_item($cidA, $coreItemId);
ok($coreItem !== null, 'Adding the jewellery half makes it a full jewellery item');
ok((string) ($coreItem['code'] ?? '') === 'COREMADE', 'It keeps the SKU it was created with');
ok(near((float) $coreItem['net_weight'], 3.0), 'Net weight is derived on this path too (4 - 1)');
ok(jw_item_vat_base($coreItem, jewellery_settings($cidA)) === 'making_only', 'And its per-item VAT base is honoured');
// It must be genuinely usable, not merely readable.
$coreTxn = jw_record_stock_txn($cidA, ['item_id' => $coreItemId, 'txn_type' => 'purchase', 'direction' => 'in',
    'txn_date' => '2026-08-01', 'gross_weight' => 4.0, 'amount' => 500000, 'created_by' => $userA]);
ok($coreTxn > 0, 'Metal can move against an item created on the core form');
ok(threw(static fn () => jw_save_item_profile($cidA, $coreItemId, ['metal_id' => 0])),
    'Its metal cannot be cleared once it has movements');
ok(threw(static fn () => jw_save_item_profile($cidB, $coreItemId, ['metal_id' => $goldB])),
    "Company B cannot attach a jewellery profile to company A's item");

echo "\n13. Cross-tenant isolation of stock\n";
ok(jewellery_items_list($cidB) === [], 'Company B has no items');
ok(jw_item_balance($cidB, $ringId)['movements'] === 0, "Company B sees no movements for company A's item");
ok(threw(static fn () => jewellery_save_mapping($cidB, 'stock_finished', $ldgStockFin, $userB)),
    "Company B cannot map company A's ledger");
$vA = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cidB")->fetchColumn();
ok($vA === 0, 'No voucher ever reached company B');


echo "\n14. Item categories are a master, not free text\n";
$catRings = jewellery_save_category($cidA, ['name' => 'Rings', 'sort_order' => 1, 'active' => 1]);
$catChains = jewellery_save_category($cidA, ['name' => 'Bangles', 'sort_order' => 2, 'active' => 1]);
ok($catRings > 0 && $catChains > 0, 'Two categories are set up');
ok(count(jewellery_categories_list($cidA)) === 2, 'Both come back on the list');
ok(threw(static fn () => jewellery_save_category($cidA, ['name' => 'Rings'])),
    'The same name cannot be added twice — that is how one heading becomes three');
ok(threw(static fn () => jewellery_save_category($cidA, ['name' => '   '])),
    'And a category needs an actual name');
ok(jewellery_categories_list($cidB) === [], "Company B sees none of company A's categories");
ok(threw(static fn () => jewellery_save_category($cidB, ['id' => $catRings, 'name' => 'Hijack'])),
    "And company B cannot rename company A's category");

$catItem = jewellery_save_item($cidA, ['code' => 'CAT-1', 'name' => 'Filed Ring', 'item_type' => 'ornament',
    'metal_id' => $goldA, 'purity_id' => $p22A, 'unit_id' => $gramA, 'category' => 'Rings'], $userA);
ok((string) jewellery_item($cidA, $catItem)['category'] === 'Rings', 'An item is filed under it');

$deleted = jewellery_delete_category($cidA, $catRings);
ok(!$deleted['ok'] && stripos($deleted['error'], 'filed under') !== false,
    'A category holding items refuses to be deleted rather than orphaning them');
ok(jewellery_delete_category($cidA, $catChains)['ok'], 'An empty one goes without complaint');

// Renaming has to carry the items across. Leaving them behind would empty a
// heading the stock and sales reports are read by, silently.
jewellery_save_category($cidA, ['id' => $catRings, 'name' => 'Gold Rings', 'active' => 1]);
ok((string) jewellery_item($cidA, $catItem)['category'] === 'Gold Rings',
    'Renaming a category carries every item filed under it across');
ok(in_array('Gold Rings', jewellery_item_categories($cidA), true),
    'And the in-use list follows, so report filters keep working');

echo "\n15. Saving an item never wipes what it was not told about\n";
// The item form no longer sends the per-piece figures. If an absent key meant
// "set me to zero", opening an old item and pressing Update would quietly
// destroy its stored weights.
jewellery_save_item($cidA, ['id' => $catItem, 'code' => 'CAT-1', 'name' => 'Filed Ring',
    'item_type' => 'ornament', 'metal_id' => $goldA, 'purity_id' => $p22A, 'unit_id' => $gramA,
    'gross_weight' => 9.5, 'stone_weight' => 0.5, 'wastage_pct' => 4.25,
    'making_charge_rate' => 250, 'stone_value' => 1200, 'hallmark' => 'BIS916',
    'design_no' => 'D-77', 'reorder_weight' => 20], $userA);
$before = jewellery_item($cidA, $catItem);
ok(abs((float) $before['gross_weight'] - 9.5) < 0.001 && abs((float) $before['wastage_pct'] - 4.25) < 0.001,
    'The figures are stored when a caller does send them');

// Exactly what the item form posts now: no weights, no charges, no hallmark.
jewellery_save_item($cidA, ['id' => $catItem, 'code' => 'CAT-1', 'name' => 'Filed Ring Renamed',
    'item_type' => 'ornament', 'metal_id' => $goldA, 'purity_id' => $p22A, 'unit_id' => $gramA,
    'category' => 'Gold Rings', 'hs_code' => '7113.19.00', 'vat_applicable' => 1,
    'vat_base' => 'stone_only', 'status' => 'active'], $userA);
$after = jewellery_item($cidA, $catItem);
ok((string) $after['name'] === 'Filed Ring Renamed', 'What the form DOES send is written');
ok(abs((float) $after['gross_weight'] - 9.5) < 0.001, 'Gross weight survives a save that never mentioned it');
ok(abs((float) $after['stone_weight'] - 0.5) < 0.001, 'So does the stone weight');
ok(abs((float) $after['wastage_pct'] - 4.25) < 0.001, 'And the wastage');
ok(abs((float) $after['making_charge_rate'] - 250) < 0.001 && abs((float) $after['stone_value'] - 1200) < 0.001,
    'And the making charge and stone value');
ok((string) $after['hallmark'] === 'BIS916' && (string) $after['design_no'] === 'D-77'
    && abs((float) $after['reorder_weight'] - 20) < 0.001, 'And the hallmark, design no. and reorder weight');
ok((string) $after['hs_code'] === '7113.19.00' && (int) $after['vat_applicable'] === 1
    && (string) $after['vat_base'] === 'stone_only',
    'The three fields the form kept are all written through');

// A caller that DOES send a zero still means zero — this is "unmentioned is
// untouched", not "zero is ignored".
jewellery_save_item($cidA, ['id' => $catItem, 'code' => 'CAT-1', 'name' => 'Filed Ring Renamed',
    'item_type' => 'ornament', 'metal_id' => $goldA, 'purity_id' => $p22A, 'unit_id' => $gramA,
    'gross_weight' => 0, 'stone_weight' => 0], $userA);
ok(abs((float) jewellery_item($cidA, $catItem)['gross_weight']) < 0.001,
    'An explicit zero is still an instruction, not a no-op');

echo "\nMIXED UNITS — one item transacted in tola AND grams\n";
// The unit is chosen per document LINE, not per item, so a shop that buys
// bullion in tola and sells scrap in grams has both on one item. Summing the
// stored weight columns straight in SQL used to make 1 tola in and 1 gram out
// cancel to nothing: the module reported zero stock while 10.66 g of gold sat
// on the shelf. Every balance now sums the canonical gram figure.
$mixItem = jewellery_save_item($cidA, ['code' => 'MIX-1', 'name' => 'Mixed Unit Bar', 'item_type' => 'bullion',
    'metal_id' => $goldA, 'purity_id' => $p24A, 'unit_id' => $tolaA], $userA);

jw_record_stock_txn($cidA, ['item_id' => $mixItem, 'txn_type' => 'opening', 'direction' => 'in',
    'txn_date' => '2026-08-01', 'holder_type' => 'stock', 'purity_id' => $p24A, 'unit_id' => $tolaA,
    'gross_weight' => 1, 'fine_weight' => 1, 'amount' => 139000, 'qty_pieces' => 1]);
jw_record_stock_txn($cidA, ['item_id' => $mixItem, 'txn_type' => 'adjustment', 'direction' => 'out',
    'txn_date' => '2026-08-02', 'holder_type' => 'stock', 'purity_id' => $p24A, 'unit_id' => $gramA,
    'gross_weight' => 1, 'fine_weight' => 1, 'amount' => 11918, 'qty_pieces' => 0]);

$mixBal = jw_item_balance($cidA, $mixItem, null, 'stock');
$expected = 1 - (1 / 11.6638);   // one tola in, one gram out, in tola
ok(near($mixBal['gross_weight'], $expected, 0.0002),
    'A tola in and a gram out leaves ' . number_format($expected, 4) . ' tola — not zero');
ok(near($mixBal['fine_weight'], $expected, 0.0002), 'And the fine weight agrees');
ok($mixBal['gross_weight'] > 0.9, 'The 10.66 g that used to vanish is still there');

// The stored row keeps the unit that was typed, so the document still reads
// the way it was written.
$typed = db()->query("SELECT gross_weight, gross_grams, unit_id FROM jewellery_stock_txns
    WHERE company_id=$cidA AND item_id=$mixItem ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok(near((float) $typed['gross_weight'], 1.0), 'The row still shows the 1 that was typed');
ok(near((float) $typed['gross_grams'], 11.6638, 0.001), 'And carries 11.6638 g alongside it');

// A guard built on the balance must agree: selling more than is left is still
// refused, and selling what IS left is still allowed.
$overdraw = false;
try {
    jw_record_stock_txn($cidA, ['item_id' => $mixItem, 'txn_type' => 'adjustment', 'direction' => 'out',
        'txn_date' => '2026-08-03', 'holder_type' => 'stock', 'purity_id' => $p24A, 'unit_id' => $tolaA,
        'gross_weight' => 5, 'fine_weight' => 5, 'amount' => 100, 'qty_pieces' => 0]);
} catch (Throwable $e) { $overdraw = true; }
ok($overdraw, 'The negative-stock guard still refuses an over-issue, now on the right balance');

$withinReach = true;
try {
    jw_record_stock_txn($cidA, ['item_id' => $mixItem, 'txn_type' => 'adjustment', 'direction' => 'out',
        'txn_date' => '2026-08-03', 'holder_type' => 'stock', 'purity_id' => $p24A, 'unit_id' => $gramA,
        'gross_weight' => 5, 'fine_weight' => 5, 'amount' => 100, 'qty_pieces' => 0]);
} catch (Throwable $e) { $withinReach = false; }
ok($withinReach, 'And ALLOWS a 5 g issue that the old naive sum would have blocked as an overdraw');

echo "\n16. The opening list costs the same whether a shop has ten items or a thousand\n";
// The opening screen used to ask the database for one item's opening movement
// at a time. At two thousand items that was two thousand round trips for one
// page, and on a shared host it was the whole of why the page would not open.
// The property worth guarding is not a number of milliseconds but the SHAPE:
// the cost must not grow with the shop.
$jwsBulkItem = db()->prepare("INSERT INTO inventory_items (company_id, sku, name, category, unit, status, opening_qty, opening_amount)
    VALUES (:cid,:sku,:name,'Bangles','GM','active',:oq,:oa)");
$jwsBulkProfile = db()->prepare("INSERT INTO jewellery_item_profiles (company_id, inventory_item_id, jewellery_type, metal_id, purity_id, unit_id, stock_kind, gross_weight)
    VALUES (:cid,:iid,'ornament',:m,:p,:u,'showroom',0)");
$jwsBulkTxn = db()->prepare("INSERT INTO jewellery_stock_txns (company_id, fiscal_year_id, item_id, txn_type, direction, txn_date,
        metal_id, purity_id, unit_id, qty_pieces, gross_weight, fine_weight, rate, amount)
    VALUES (:cid,:fy,:iid,'opening','in','2026-07-16',:m,:p,:u,1,:gw,:fw,1000,:amt)");
/** $count more items carrying opening stock, named from $from upwards. */
$jwsSeedOpenings = static function (int $from, int $count) use ($jwsBulkItem, $jwsBulkProfile, $jwsBulkTxn, $cidA, $fyA, $goldA, $p22A, $gramA): void {
    for ($n = $from; $n < $from + $count; $n++) {
        $jwsBulkItem->execute(['cid' => $cidA, 'sku' => 'PERF-' . $n, 'name' => 'Perf Bangle ' . $n,
            'oq' => 10.0, 'oa' => 10000.0]);
        $iid = (int) db()->lastInsertId();
        $jwsBulkProfile->execute(['cid' => $cidA, 'iid' => $iid, 'm' => $goldA, 'p' => $p22A, 'u' => $gramA]);
        $jwsBulkTxn->execute(['cid' => $cidA, 'fy' => $fyA, 'iid' => $iid, 'm' => $goldA, 'p' => $p22A,
            'u' => $gramA, 'gw' => 10.0, 'fw' => 9.16, 'amt' => 10000.0]);
    }
};
/** Statements this connection has answered, so a call's cost can be counted. */
$jwsQueries = static function (): int {
    return (int) (db()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'] ?? 0);
};
/** The queries one opening-list build costs, net of the two counting it. */
$jwsCostOfOpeningList = static function () use ($cidA, $fyA, $jwsQueries): array {
    $before = $jwsQueries();
    $rows = jewellery_opening_rows($cidA, $fyA);

    return ['queries' => $jwsQueries() - $before - 2, 'rows' => count($rows)];
};

$jwsSeedOpenings(1, 10);
$jwsSmall = $jwsCostOfOpeningList();
$jwsSeedOpenings(11, 90);
$jwsLarge = $jwsCostOfOpeningList();

ok($jwsLarge['rows'] - $jwsSmall['rows'] === 90,
    'Ninety more items with opening stock show up as ninety more rows');
ok($jwsSmall['queries'] === $jwsLarge['queries'],
    'And cost the SAME number of queries — ' . $jwsSmall['queries'] . ' at ' . $jwsSmall['rows']
    . ' rows, ' . $jwsLarge['queries'] . ' at ' . $jwsLarge['rows'] . ' rows');
ok($jwsLarge['queries'] <= 5, 'Which is a handful, not one per item (' . $jwsLarge['queries'] . ')');

$jwsRows = jewellery_opening_rows($cidA, $fyA);
$jwsHanded = jewellery_opening_rows($cidA, $fyA, jewellery_items_list($cidA));
ok(count($jwsHanded) === count($jwsRows),
    'Handing in the item master the caller already read gives the same list back');

echo "\n17. The opening filters answer on the server, over the whole list\n";
$jwsSample = [
    ['item_code' => 'BG-1', 'item_name' => 'Bangle One', 'category' => 'Bangles', 'purity_code' => '22K',
        'stock_kind' => 'showroom', 'posted' => true, 'voucher_id' => 41],
    ['item_code' => 'CH-9', 'item_name' => 'Chain Nine', 'category' => '', 'purity_code' => '24K',
        'stock_kind' => 'customer_ordered', 'posted' => true, 'voucher_id' => 0],
    ['item_code' => 'RG-3', 'item_name' => 'Ring Three', 'category' => 'Rings', 'purity_code' => '22K',
        'stock_kind' => 'showroom', 'posted' => false, 'voucher_id' => 0],
    ['item_code' => 'BG-2', 'item_name' => 'Bangle Two', 'category' => 'Bangles', 'purity_code' => '22K',
        'stock_kind' => 'showroom', 'posted' => false, 'voucher_id' => 0],
];
$jwsCodes = static function (array $rows): string {
    return implode(',', array_column($rows, 'item_code'));
};
ok($jwsCodes(jewellery_opening_filter($jwsSample, [])) === 'BG-1,CH-9,RG-3,BG-2', 'No filter keeps every row, in order');
ok($jwsCodes(jewellery_opening_filter($jwsSample, ['search' => 'ring'])) === 'RG-3', 'The search reads the item name');
ok($jwsCodes(jewellery_opening_filter($jwsSample, ['search' => 'ch-9'])) === 'CH-9', 'And the code, without minding the case');
ok($jwsCodes(jewellery_opening_filter($jwsSample, ['group' => 'Bangles'])) === 'BG-1,BG-2', 'The group filter narrows to one group');
ok($jwsCodes(jewellery_opening_filter($jwsSample, ['group' => 'uncategorised'])) === 'CH-9',
    'An item under no group answers to the name the screen prints for it');
ok($jwsCodes(jewellery_opening_filter($jwsSample, ['purity' => '22'])) === 'BG-1,RG-3,BG-2', 'Purity matches on part of the code');
ok($jwsCodes(jewellery_opening_filter($jwsSample, ['kind' => 'customer_ordered'])) === 'CH-9', 'Stock type is an exact match');
ok(jewellery_opening_status($jwsSample[0]) === 'posted'
    && jewellery_opening_status($jwsSample[1]) === 'weight'
    && jewellery_opening_status($jwsSample[2]) === 'none',
    'Posted, weight-only and not-in-stock are told apart');
ok($jwsCodes(jewellery_opening_filter($jwsSample, ['status' => 'none'])) === 'RG-3,BG-2',
    'Two openings the books have never seen are found together');
ok($jwsCodes(jewellery_opening_filter($jwsSample, ['status' => 'weight'])) === 'CH-9',
    'And a status filter finds the opening that moved metal but never reached the ledger');
// Group alone gives two rows and status alone gives two others; together they
// give the one row both are true of.
ok($jwsCodes(jewellery_opening_filter($jwsSample, ['group' => 'Bangles', 'status' => 'none'])) === 'BG-2',
    'Filters combine rather than replacing one another');
ok(jewellery_opening_filter($jwsSample, ['group' => 'Chains']) === [], 'A filter that matches nothing returns nothing');

echo "\n18. An opening held for a customer names them off the master\n";
// Customer-ordered stock used to record whoever it was for as free text. Two
// spellings of one person were two customers, and neither of them was a ledger.
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status)
    VALUES (:c,'CUS-JWS','Sita Shrestha','customer','active')")->execute(['c' => $cidA]);
$jwsCustomer = (int) db()->lastInsertId();
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status)
    VALUES (:c,'CUS-OTHER','Another Firm Customer','customer','active')")->execute(['c' => $cidB]);
$jwsForeignCustomer = (int) db()->lastInsertId();

$jwsHeld = jewellery_save_opening($cidA, $fyA, ['item_id' => $chainId, 'stock_kind' => 'customer_ordered',
    'gross_weight' => 20.0, 'qty_pieces' => 4, 'amount' => 3000000,
    'customer_party_id' => $jwsCustomer, 'customer_name' => 'sita s.', 'customer_order_no' => 'ORD-7'], $userA);
ok($jwsHeld['ok'], 'An opening held for a customer saves' . ($jwsHeld['ok'] ? '' : ' — ' . $jwsHeld['error']));
$jwsUnit = db()->query("SELECT customer_party_id, customer_name, customer_order_no FROM jewellery_stock_units
    WHERE company_id=$cidA AND item_id=$chainId AND status <> 'cancelled' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok((int) $jwsUnit['customer_party_id'] === $jwsCustomer, 'The traced piece points at the customer on the master');
ok((string) $jwsUnit['customer_name'] === 'Sita Shrestha',
    'And carries the name the master holds, not the one that was typed over it');
ok((string) $jwsUnit['customer_order_no'] === 'ORD-7', 'The order number is kept beside it');

$jwsReopened = null;
foreach (jewellery_opening_rows($cidA, $fyA) as $jwsRow) {
    if ((int) $jwsRow['id'] === $chainId) { $jwsReopened = $jwsRow; }
}
ok($jwsReopened !== null && (int) $jwsReopened['customer_party_id'] === $jwsCustomer,
    'The opening list hands the customer back, so editing a row does not wipe them');
ok($jwsReopened !== null && (string) $jwsReopened['customer_order_no'] === 'ORD-7',
    'Along with the order number, which the list used to show as blank');

$jwsForeign = jewellery_save_opening($cidA, $fyA, ['item_id' => $chainId, 'gross_weight' => 20.0,
    'amount' => 3000000, 'customer_party_id' => $jwsForeignCustomer], $userA);
ok(!$jwsForeign['ok'], "Another company's customer cannot be named on this company's opening");

$jwsWalkIn = jewellery_save_opening($cidA, $fyA, ['item_id' => $chainId, 'stock_kind' => 'customer_ordered',
    'gross_weight' => 20.0, 'qty_pieces' => 4, 'amount' => 3000000, 'customer_name' => 'Walk-in Ram'], $userA);
ok($jwsWalkIn['ok'], 'A walk-in who is not on the master can still be named in words');
$jwsUnit = db()->query("SELECT customer_party_id, customer_name FROM jewellery_stock_units
    WHERE company_id=$cidA AND item_id=$chainId AND status <> 'cancelled' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok($jwsUnit['customer_party_id'] === null && (string) $jwsUnit['customer_name'] === 'Walk-in Ram',
    'And is stored as a name against no party, exactly as before');

// ---------------------------------------------------------------------------
echo "\n18. Adopting legacy stock costs the same whatever the shop holds\n";
// ---------------------------------------------------------------------------
// jewellery_trace_backfill_legacy_balance() gives a shop that predates piece
// tracing one honest starting point. It runs from the SALES screen, on every
// load, and it used to ask the database for one item's traced total at a time
// — two thousand round trips on a two-thousand-item shop, paid again on every
// page view, almost always to find no gap at all. Its own company is built
// here because the function remembers which companies it has already done, so
// it can only be measured once per shop per process.
db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,1)')
    ->execute(['n' => 'Trace Cost Jewellers (Books)', 'c' => 'JWSTRACE']);
$jwtCid = (int) db()->lastInsertId();
$jwtFy = create_fiscal_year($jwtCid, 'JWSTRACE 2026/27', '2026-07-16', '2027-07-15', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$jwtFy['id']]);
jewellery_settings($jwtCid);
$jwtOne = static function (string $sql) use ($jwtCid): int {
    return (int) db()->query("SELECT id FROM $sql AND company_id=$jwtCid LIMIT 1")->fetchColumn();
};
$jwtGold = $jwtOne("jewellery_metals WHERE code='GOLD'");
$jwtPurity = (int) db()->query("SELECT id FROM jewellery_purities WHERE company_id=$jwtCid AND metal_id=$jwtGold AND code='22K' LIMIT 1")->fetchColumn();
$jwtUnit = (int) db()->query("SELECT id FROM jewellery_units WHERE company_id=$jwtCid AND code='GM' LIMIT 1")->fetchColumn();

$jwtItem = db()->prepare("INSERT INTO inventory_items (company_id, sku, name, unit, status) VALUES (:cid,:sku,:n,'GM','active')");
$jwtProfile = db()->prepare("INSERT INTO jewellery_item_profiles (company_id, inventory_item_id, jewellery_type, metal_id, purity_id, unit_id, stock_kind, gross_weight)
    VALUES (:cid,:iid,'ornament',:m,:p,:u,'showroom',0)");
$jwtTxn = db()->prepare("INSERT INTO jewellery_stock_txns (company_id, fiscal_year_id, item_id, txn_type, direction, txn_date, metal_id, purity_id, unit_id,
        qty_pieces, gross_weight, gross_grams, fine_weight, fine_grams, rate, amount)
    VALUES (:cid,:fy,:iid,'opening','in','2026-07-16',:m,:p,:u,1,10,10,9.16,9.16,10000,100000)");
for ($jwtN = 1; $jwtN <= 60; $jwtN++) {
    $jwtItem->execute(['cid' => $jwtCid, 'sku' => 'TRC-' . $jwtN, 'n' => 'Traced ' . $jwtN]);
    $jwtIid = (int) db()->lastInsertId();
    $jwtProfile->execute(['cid' => $jwtCid, 'iid' => $jwtIid, 'm' => $jwtGold, 'p' => $jwtPurity, 'u' => $jwtUnit]);
    $jwtTxn->execute(['cid' => $jwtCid, 'fy' => (int) $jwtFy['id'], 'iid' => $jwtIid, 'm' => $jwtGold, 'p' => $jwtPurity, 'u' => $jwtUnit]);
}

$jwtQueries = static function (): int {
    return (int) (db()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'] ?? 0);
};
// Every item is traced ALREADY, which is the state a shop is in on the second
// and every later page load. That is the case that was costing a query per
// item to discover — creating the pieces is real work and only happens once.
$jwtUnitIns = db()->prepare("INSERT INTO jewellery_stock_units
        (company_id, fiscal_year_id, trace_code, item_id, purity_id, unit_id, stock_kind, status,
         current_holder_type, qty_pieces, gross_weight, stone_weight, net_weight, fine_weight, cost_amount, origin_type)
    VALUES (:cid,:fy,:trace,:iid,:p,:u,'showroom','in_stock','stock',1,10,0,10,9.16,100000,'legacy_balance')");
$jwtItems = db()->query("SELECT id FROM inventory_items WHERE company_id=$jwtCid ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
foreach ($jwtItems as $jwtIndex => $jwtItemId) {
    $jwtUnitIns->execute(['cid' => $jwtCid, 'fy' => (int) $jwtFy['id'], 'trace' => 'TRC-SEED-' . $jwtIndex,
        'iid' => (int) $jwtItemId, 'p' => $jwtPurity, 'u' => $jwtUnit]);
}

$jwtBefore = $jwtQueries();
jewellery_trace_backfill_legacy_balance($jwtCid, 0);
$jwtCost = $jwtQueries() - $jwtBefore - 2;
$jwtCreated = (int) db()->query("SELECT COUNT(*) FROM jewellery_stock_units WHERE company_id=$jwtCid AND trace_code NOT LIKE 'TRC-SEED-%'")->fetchColumn();

ok($jwtCreated === 0, 'A shop already traced in full has no gap to adopt, got ' . $jwtCreated);
ok($jwtCost <= 6, 'And finding that out costs a handful of queries, not one per item (' . $jwtCost . ' for 60 items)');

$jwtSecond = $jwtQueries();
jewellery_trace_backfill_legacy_balance($jwtCid, 0);
ok($jwtQueries() - $jwtSecond - 2 <= 1, 'A second call in the same request costs nothing — it remembers');


jws_cleanup();
echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail === 0 ? 0 : 1);
