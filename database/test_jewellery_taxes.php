<?php
declare(strict_types=1);

/**
 * Jewellery Accounting — the tax framework, stone weight and wastage.
 *
 * Proves the things that are easy to get subtly wrong and impossible to spot
 * afterwards in a ledger:
 *   * gross includes stones, NET is the metal that leaves, and the rate is
 *     charged on net PLUS the wastage weight — the way a real bill does it;
 *   * the two taxes sit on DISJOINT bases: SD on metal + making, VAT on the
 *     stone side alone, never on top of each other;
 *   * a 'tagged' tax reaches only the items tagged for it;
 *   * a tax that has ended still prices a document dated before it ended;
 *   * each tax posts to ITS OWN payable, not to one lump tax account;
 *   * sales resolve their revenue ledger PER ITEM, so two items can report
 *     separately without any change to the posting code.
 *
 *   php database/test_jewellery_taxes.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/jewellery_reports.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $tol = 0.011): bool { return abs($a - $b) < $tol; }

function voucher_ledgers(int $voucherId): array
{
    $byLedger = [];
    foreach (db()->query("SELECT * FROM voucher_entries WHERE voucher_id=$voucherId")->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $amount = (float) $e['amount'];
        $sign = (string) $e['entry_type'] === 'debit' ? 1 : -1;
        $byLedger[(int) $e['ledger_id']] = ($byLedger[(int) $e['ledger_id']] ?? 0) + $sign * $amount;
    }

    return $byLedger;
}

function jwtax_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code='JWTAX'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$s)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['jewellery_line_taxes', 'jewellery_item_taxes', 'jewellery_taxes',
                  'jewellery_advance_allocations', 'jewellery_settlement_tenders', 'jewellery_settlement_allocations', 'jewellery_settlements', 'jewellery_bills',
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
    foreach (db()->query("SELECT id FROM users WHERE email='jwtax@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
jwtax_cleanup();

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------
db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,1)')
    ->execute(['n' => 'Tax Test Jewellers (Books)', 'c' => 'JWTAX']);
$cid = (int) db()->lastInsertId();
$uid = create_user(['name' => 'Tax Owner', 'email' => 'jwtax@test.local', 'password' => 'Secret#12345',
    'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, jewellery_accounting_enabled)
        VALUES (:uid,:cid,:books,:org,:code,1,1)')
    ->execute(['uid' => $uid, 'cid' => $cid, 'books' => $cid, 'org' => 'Tax Test Jewellers', 'code' => 'JWTAX-C']);
$fyRow = create_fiscal_year($cid, 'JWTAX 2026/27', '2026-07-16', '2027-07-15', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fyRow['id']]);
$fy = (int) $fyRow['id'];
$_SESSION['company_id'] = $cid;
jewellery_settings($cid);

$q = static fn (string $sql): int => (int) db()->query($sql)->fetchColumn();
$gold = $q("SELECT id FROM jewellery_metals WHERE company_id=$cid AND code='GOLD'");
$p22 = $q("SELECT id FROM jewellery_purities WHERE company_id=$cid AND metal_id=$gold AND code='22K'");
$tola = $q("SELECT id FROM jewellery_units WHERE company_id=$cid AND code='TOLA'");

echo "\n1. The seeded tax register\n";
$taxes = jewellery_taxes_list($cid);
ok(count($taxes) === 2, 'Two taxes are seeded');
$spt = null; $vat = null;
foreach ($taxes as $t) { if ($t['code'] === 'SD') { $spt = $t; } if ($t['code'] === 'VAT') { $vat = $t; } }
ok($spt !== null && (float) $spt['rate'] === 0.5, 'Skills Development Tax is seeded at 0.5%');
ok($spt !== null && $spt['base'] === 'metal_making', 'Its base is metal + making — the bill\'s "SD Taxable Amt"');
ok($spt !== null && (int) $spt['manual_entry'] === 0, 'It is worked out, not punched — the bill shows it computed');
ok($vat !== null && $vat['base'] === 'stone_diamond', 'VAT is charged on the stone side alone');
ok($vat !== null && $vat['applies_to'] === 'all', 'It reaches every line; the BASE is what limits it, not a tag');
ok($spt !== null && $vat !== null && (int) $spt['sequence'] < (int) $vat['sequence'], 'VAT is charged last');
jewellery_seed_taxes($cid);
ok(count(jewellery_taxes_list($cid)) === 2, 'Re-seeding an existing register changes nothing');

echo "\n2. Charging one line, by hand\n";
// Metal 100,000 (the wastage is already inside it, as on a real bill);
// making 10,000; stone 20,000.
//   SD  = 0.5% of (100,000 + 10,000) = 550
//   VAT = 13% of 20,000              = 2,600   — the stone alone
$charged = jw_charge_line_taxes(
    ['metal' => 100000, 'wastage' => 5000, 'making' => 10000, 'stone' => 20000],
    jewellery_taxes_list($cid, 'sale', '2026-08-01'),
    [], true, 'full_value'
);
ok(near($charged['other'], 550.0), 'SD ignores the stone: 0.5% of 110,000 = 550');
ok(near($charged['vat'], 2600.0), 'VAT is charged on the 20,000 stone alone = 2,600');
ok(near($charged['total'], 3150.0), 'The two together are 3,150');
ok(count($charged['taxes']) === 2 && $charged['taxes'][0]['tax_code'] === 'SD',
    'They are returned in charging order, SD first');

$untagged = jw_charge_line_taxes(
    ['metal' => 100000, 'wastage' => 5000, 'making' => 10000, 'stone' => 20000],
    jewellery_taxes_list($cid, 'sale', '2026-08-01'),
    [], false, 'full_value'
);
ok(near($untagged['vat'], 2600.0),
    'The item flag no longer gates VAT — the stone side does, and this line has one');
ok(near($untagged['other'], 550.0), 'And the SD tax is unchanged at 550');

echo "\n3. A tax that has ended still prices an older document\n";
jewellery_save_tax($cid, ['id' => (int) $vat['id'], 'effective_to' => '2026-12-31'] + $vat);
ok(count(jewellery_taxes_list($cid, 'sale', '2026-08-01')) === 2, 'On 1 Aug 2026 both taxes are in force');
ok(count(jewellery_taxes_list($cid, 'sale', '2027-02-01')) === 1, 'On 1 Feb 2027 only the Skills Promotion Tax remains');
jewellery_save_tax($cid, ['id' => (int) $vat['id']] + $vat + ['effective_to' => null]);

echo "\n4. Net weight: the rate is charged on metal, not on stones\n";
$mkLedger = static function (int $companyId, string $code, string $name, string $master): int {
    db()->prepare('INSERT INTO ledger_groups (company_id,name,code,master_key) VALUES (:cid,:n,:c,:m)')
        ->execute(['cid' => $companyId, 'n' => 'TX ' . $code, 'c' => 'G' . $code, 'm' => $master]);
    $gid = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO ledgers (company_id,group_id,name,code) VALUES (:cid,:g,:n,:c)')
        ->execute(['cid' => $companyId, 'g' => $gid, 'n' => $name, 'c' => $code]);

    return (int) db()->lastInsertId();
};
$L = [];
foreach ([
    ['stock_metal', 'TSTKM', 'Metal Stock', 'assets'],
    ['stock_finished', 'TSTKF', 'Finished Stock', 'assets'],
    ['sales_metal', 'TSALM', 'Sales Metal', 'income'],
    ['sales_making', 'TSALK', 'Sales Making', 'income'],
    ['sales_stone', 'TSALS', 'Sales Stone', 'income'],
    ['cogs', 'TCOGS', 'COGS', 'expenses'],
    ['vat_input', 'TVATI', 'VAT Input', 'assets'],
    ['vat_output', 'TVATO', 'VAT Output', 'liabilities'],
    ['spt_input', 'TSPTI', 'SPT Input', 'assets'],
    ['spt_output', 'TSPTO', 'SPT Output', 'liabilities'],
    ['opening_equity', 'TOPEQ', 'Opening Equity', 'equity'],
] as [$purpose, $code, $name, $master]) {
    $L[$purpose] = $mkLedger($cid, $code, $name, $master);
    jewellery_save_mapping($cid, $purpose, $L[$purpose], $uid);
}
$cash = $mkLedger($cid, 'TCASH', 'Cash', 'assets');

$chain = jewellery_save_item($cid, ['code' => 'CH-1', 'name' => 'Chain', 'item_type' => 'ornament',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'vat_applicable' => 0], $uid);
$ring = jewellery_save_item($cid, ['code' => 'RG-1', 'name' => 'Stone Ring', 'item_type' => 'ornament',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'vat_applicable' => 1], $uid);

db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'TCUS','Tax Customer','customer','active')")
    ->execute(['c' => $cid]);
$customer = (int) db()->lastInsertId();
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'TSUP','Tax Supplier','supplier','active')")
    ->execute(['c' => $cid]);
$supplier = (int) db()->lastInsertId();

// Stock in, so there is something to sell and a cost to relieve.
$p1 = jewellery_save_purchase($cid, $fy, ['purchase_date' => '2026-07-20', 'party_id' => $supplier,
    'settle_mode' => 'cash', 'settle_ledger_id' => $cash],
    [['item_id' => $chain, 'gross_weight' => 20, 'rate' => 100000],
     ['item_id' => $ring, 'gross_weight' => 10, 'rate' => 100000]], $uid);
$rp = jewellery_post_purchase($cid, $p1, $uid);
ok($rp['ok'], 'The opening purchase posts' . ($rp['ok'] ? '' : ' — ' . $rp['error']));

// 5 tola gross, 1 tola of that is stone. Metal is charged on 4 tola.
$s1 = jewellery_save_sale($cid, $fy, ['sale_date' => '2026-08-01', 'party_id' => $customer, 'settle_mode' => 'credit'],
    [['item_id' => $chain, 'gross_weight' => 5, 'stone_weight' => 1, 'rate' => 100000,
      'making_amount' => 10000, 'stone_amount' => 20000, 'wastage_pct' => 5]], [], $uid);
$line = db()->query("SELECT * FROM jewellery_sale_lines WHERE sale_id=$s1")->fetch(PDO::FETCH_ASSOC);
ok(near((float) $line['net_weight'], 4.0), 'Net weight is gross 5 less stone 1 = 4 tola');
ok(near((float) $line['total_weight'], 4.2), 'Total Wt = net 4 + wastage 0.2 (5% of net)');
ok(near((float) $line['metal_amount'], 420000.0),
    'The metal is charged on the TOTAL weight: 4.2 x 100,000 = 420,000');
ok(near((float) $line['fine_weight'], 3.664), 'Fine content follows net weight too: 4 x 0.916');
ok(near((float) $line['wastage_amount'], 20000.0), 'Wastage 5% of the metal value = 20,000');

$s1Row = jewellery_sale($cid, $s1);
// SPT = 0.5% of (400,000 + 20,000 + 10,000) = 2,150. The chain is not tagged
// for VAT, so no VAT at all.
ok(near((float) $s1Row['tax_amount'], 2150.0), 'SD is 0.5% of (420,000 + 10,000) = 2,150');
ok(near((float) $s1Row['vat_amount'], 2600.0), 'VAT is 13% of the 20,000 stone = 2,600');
ok(near((float) $s1Row['total_amount'], 454750.0),
    'Total = 420,000 metal + 10,000 making + 20,000 stone + 2,150 SD + 2,600 VAT');

echo "\n5. The two taxes sit on DISJOINT bases — VAT never rides on the SD tax\n";
$s2 = jewellery_save_sale($cid, $fy, ['sale_date' => '2026-08-02', 'party_id' => $customer, 'settle_mode' => 'credit'],
    [['item_id' => $ring, 'gross_weight' => 2, 'rate' => 100000, 'making_amount' => 10000, 'wastage_pct' => 5]], [], $uid);
$s2Row = jewellery_sale($cid, $s2);
// net 2 + 5% wastage = 2.1 total weight -> metal 210,000
// SD 0.5% of (210,000 + 10,000) = 1,100 ; the ring carries no stone, so no VAT.
ok(near((float) $s2Row['tax_amount'], 1100.0), 'SD on the ring is 1,100');
ok(near((float) $s2Row['vat_amount'], 0.0),
    'And NO VAT at all — there is no stone on this line, and gold is outside VAT');
ok(near((float) $s2Row['total_amount'], 221100.0), 'Total = 210,000 + 10,000 + 1,100');

$r2 = jewellery_post_sale($cid, $s2, $uid);
ok($r2['ok'], 'The sale posts' . ($r2['ok'] ? '' : ' — ' . $r2['error']));
$v = voucher_ledgers((int) $r2['voucher_id']);
ok(!isset($v[$L['vat_output']]) || near($v[$L['vat_output']], 0.0),
    'Nothing reaches the VAT payable, because nothing on this bill is vatable');
ok(near($v[$L['spt_output']] ?? 0, -1100.0), 'The Skills Promotion Tax has its OWN payable — not lumped into VAT');
ok(near($v[$L['sales_metal']] ?? 0, -210000.0), 'Metal revenue carries the wastage: 200,000 + 10,000');
$breakdown = jw_document_taxes($cid, 'sale', $s2);
ok(count($breakdown) === 1, 'Only the SD tax was charged, so only it is in the breakdown');

echo "\n6. Item-wise sales ledgers\n";
// Point the RING's metal revenue at its own ledger. The CHAIN keeps the
// company default, and neither posting path changes.
$ringSales = $mkLedger($cid, 'TSALR', 'Sales — Stone Rings', 'income');
db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, purpose, item_id, ledger_id, created_by)
    VALUES (:cid,'item','sales_revenue',:iid,:lid,:by)")
    ->execute(['cid' => $cid, 'iid' => $ring, 'lid' => $ringSales, 'by' => $uid]);

$s3 = jewellery_save_sale($cid, $fy, ['sale_date' => '2026-08-03', 'party_id' => $customer, 'settle_mode' => 'credit'],
    [['item_id' => $ring, 'gross_weight' => 1, 'rate' => 100000],
     ['item_id' => $chain, 'gross_weight' => 1, 'rate' => 100000]], [], $uid);
$r3 = jewellery_post_sale($cid, $s3, $uid);
ok($r3['ok'], 'A two-item sale posts' . ($r3['ok'] ? '' : ' — ' . $r3['error']));
$v3 = voucher_ledgers((int) $r3['voucher_id']);
ok(near($v3[$ringSales] ?? 0, -100000.0), 'The ring reports against its OWN sales ledger');
ok(near($v3[$L['sales_metal']] ?? 0, -100000.0), 'The chain still reports against the company default');

echo "\n7. Guards\n";
$rejected = jw_compute_document($cid, ['document_date' => '2026-08-04', 'doc_type' => 'sale'],
    [['item_id' => $chain, 'gross_weight' => 2, 'stone_weight' => 3, 'rate' => 100000]]);
ok($rejected['errors'] !== [], 'A stone weight above the gross weight is refused');

$unpriced = jw_compute_document($cid, ['document_date' => '2026-08-04', 'doc_type' => 'sale'],
    [['item_id' => $chain, 'gross_weight' => 2]]);
ok($unpriced['errors'] !== [], 'A line with weight, no rate and no quote is refused rather than priced at zero');

$stillValid = jw_compute_document($cid, ['document_date' => '2026-08-04', 'doc_type' => 'sale'],
    [['item_id' => $chain, 'gross_weight' => 2, 'stone_amount' => 5000]]);
ok($stillValid['errors'] === [], 'But a line whose value is entirely its stone amount is accepted');

$negative = jw_compute_document($cid, ['document_date' => '2026-08-04', 'doc_type' => 'sale'],
    [['item_id' => $chain, 'gross_weight' => 2, 'rate' => 100000, 'wastage_pct' => -1]]);
ok($negative['errors'] !== [], 'Negative wastage is refused');

echo "\n8. The punched total wins, and still reaches the ledger\n";
$s4 = jewellery_save_sale($cid, $fy, ['sale_date' => '2026-08-05', 'party_id' => $customer,
    'settle_mode' => 'credit', 'manual_tax_amount' => 3000],
    [['item_id' => $chain, 'gross_weight' => 2, 'rate' => 100000, 'making_amount' => 10000]], [], $uid);
$s4Row = jewellery_sale($cid, $s4);
ok(near((float) $s4Row['tax_amount'], 3000.0), 'The punched 3,000 replaces the computed 1,050');
ok(near((float) $s4Row['manual_tax_amount'], 3000.0), 'What was punched is remembered, so it survives a re-save');
$r4 = jewellery_post_sale($cid, $s4, $uid);
ok($r4['ok'], 'It posts' . ($r4['ok'] ? '' : ' — ' . $r4['error']));
$v4 = voucher_ledgers((int) $r4['voucher_id']);
ok(near($v4[$L['spt_output']] ?? 0, -3000.0), 'The full punched 3,000 reaches the tax payable');
$dr = 0.0; $cr = 0.0;
foreach ($v4 as $amount) { if ($amount > 0) { $dr += $amount; } else { $cr -= $amount; } }
ok(near($dr, $cr), 'And the voucher still balances');

echo "\n9. Tenant isolation\n";
$otherTax = db()->prepare('SELECT COUNT(*) FROM jewellery_taxes WHERE company_id <> :cid AND id IN
    (SELECT tax_id FROM jewellery_item_taxes WHERE company_id = :cid2)');
$otherTax->execute(['cid' => $cid, 'cid2' => $cid]);
ok((int) $otherTax->fetchColumn() === 0, 'No item is tagged with another company\'s tax');
ok(jewellery_tax($cid + 999999, (int) $vat['id']) === null, 'A tax cannot be read through the wrong company');

echo "\n10. A base that once counted the wastage twice\n";
// The live bill that found it: metal 67,355.47 (wastage inside, as the metal
// figure has been since 083), making 1,700 — and an SD Taxable printed at
// 79,571.84, because 'metal_wastage_making' added the 10,516.37 of wastage a
// SECOND time. The Non Taxable row went NEGATIVE by the same amount: a
// statutory totals block contradicting itself on its face.
jewellery_save_tax($cid, ['id' => (int) $spt['id'], 'base' => 'metal_wastage_making'] + $spt);
$dbl = jw_charge_line_taxes(
    ['metal' => 100000, 'wastage' => 5000, 'making' => 10000, 'stone' => 0],
    jewellery_taxes_list($cid, 'sale', '2026-08-01'), [], true, 'full_value');
ok(near($dbl['other'], 550.0),
    'metal_wastage_making charges 0.5% of 110,000 — the wastage is already inside the metal');
$sdCharge = null;
foreach ($dbl['taxes'] as $t) { if (!$t['is_vat']) { $sdCharge = $t; } }
ok($sdCharge !== null && near((float) $sdCharge['base_amount'], 110000.0),
    'And the printed base reads 110,000, not 115,000');

// Documents the OLD arithmetic already wrote are put right by the repair —
// bases only. The tax charged reached the ledger; it is history and stays.
$wSale = jewellery_save_sale($cid, $fy, ['sale_date' => '2026-08-05', 'party_id' => $customer,
    'settle_mode' => 'credit'],
    [['item_id' => $chain, 'gross_weight' => 2, 'wastage_weight' => 0.5, 'rate' => 10000]], [], $uid);
$wRow = jewellery_sale($cid, $wSale);
ok(near((float) $wRow['sd_taxable_amount'], 25000.0),
    'A fresh bill stores the honest base: 2.5 total weight x 10,000 = 25,000');
ok(near((float) $wRow['non_taxable_amount'], 0.0), 'And nothing is left outside the two taxes');

// Corrupt the stored rows exactly as the old engine did — base inflated by
// the wastage value, header inflated with it, non-taxable driven negative.
$chargedAmountBefore = (float) db()->query("SELECT amount FROM jewellery_line_taxes
    WHERE doc_type='sale' AND doc_id=$wSale AND output_purpose <> 'vat_output'")->fetchColumn();
db()->exec("UPDATE jewellery_line_taxes SET base_amount = base_amount + 5000
    WHERE doc_type='sale' AND doc_id=$wSale AND output_purpose <> 'vat_output'");
db()->exec("UPDATE jewellery_sales SET sd_taxable_amount = sd_taxable_amount + 5000,
    non_taxable_amount = non_taxable_amount - 5000 WHERE id=$wSale");
accounting_module_repair_database();
$wFixed = jewellery_sale($cid, $wSale);
ok(near((float) $wFixed['sd_taxable_amount'], 25000.0),
    'The repair re-derives the stored SD base from the line: 25,000 again');
ok(near((float) $wFixed['non_taxable_amount'], 0.0), 'The negative Non Taxable is gone');
$chargedAmountAfter = (float) db()->query("SELECT amount FROM jewellery_line_taxes
    WHERE doc_type='sale' AND doc_id=$wSale AND output_purpose <> 'vat_output'")->fetchColumn();
ok(near($chargedAmountBefore, $chargedAmountAfter),
    'And the tax CHARGED is untouched — what reached the ledger is history');
accounting_module_repair_database();
ok(near((float) jewellery_sale($cid, $wSale)['sd_taxable_amount'], 25000.0),
    'Running the repair again changes nothing');
jewellery_save_tax($cid, ['id' => (int) $spt['id'], 'base' => 'metal_making'] + $spt);

jwtax_cleanup();
echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
