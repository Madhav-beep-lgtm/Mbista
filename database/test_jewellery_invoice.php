<?php
declare(strict_types=1);

/**
 * Jewellery Accounting — reproduce a REAL Nepali jewellery invoice, to the paisa.
 *
 * Modelled on Akshara Jewellery Pvt Ltd bill S8384/51 (26 Jul 2026). Every
 * figure asserted below is a figure printed on that bill, so this suite is the
 * specification: if the arithmetic ever drifts, it drifts against a document a
 * shop actually handed to a customer, not against my reading of it.
 *
 *     Grs Wt 2.550   Less 0.0400   Net Wt 2.510   Wast 0.4660   Total Wt 2.976
 *     Rate/gm 22,645.062                          Amount        67,391.70
 *     Making                                                     1,700.00
 *     Stone 0.2500 crt                                             232.60
 *                                              Total Amount    69,324.30
 *
 *     SD Taxable Amt (metal + making)                          69,091.70
 *     SD Tax 0.5%                                                 345.46
 *     Vatable Amt (stone side only)                               232.60
 *     VAT 13%                                                      30.24
 *                                              NET TOTAL       69,700.00
 *
 *   php database/test_jewellery_invoice.php
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
        $sign = (string) $e['entry_type'] === 'debit' ? 1 : -1;
        $byLedger[(int) $e['ledger_id']] = ($byLedger[(int) $e['ledger_id']] ?? 0) + $sign * (float) $e['amount'];
    }

    return $byLedger;
}

function jwinv_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code='JWINV'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$s)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['jewellery_line_taxes', 'jewellery_item_taxes', 'jewellery_taxes',
                  'jewellery_settlement_allocations', 'jewellery_settlements', 'jewellery_bills',
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
    foreach (db()->query("SELECT id FROM users WHERE email='jwinv@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
jwinv_cleanup();

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------
db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,1)')
    ->execute(['n' => 'Akshara Test Jewellery (Books)', 'c' => 'JWINV']);
$cid = (int) db()->lastInsertId();
$uid = create_user(['name' => 'Invoice Owner', 'email' => 'jwinv@test.local', 'password' => 'Secret#12345',
    'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, jewellery_accounting_enabled)
        VALUES (:uid,:cid,:books,:org,:code,1,1)')
    ->execute(['uid' => $uid, 'cid' => $cid, 'books' => $cid, 'org' => 'Akshara Test Jewellery', 'code' => 'JWINV-C']);
$fyRow = create_fiscal_year($cid, 'JWINV 2026/27', '2026-07-16', '2027-07-15', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fyRow['id']]);
$fy = (int) $fyRow['id'];
$_SESSION['company_id'] = $cid;
jewellery_settings($cid);

$q = static fn (string $sql): int => (int) db()->query($sql)->fetchColumn();
$gold = $q("SELECT id FROM jewellery_metals WHERE company_id=$cid AND code='GOLD'");
$p22 = $q("SELECT id FROM jewellery_purities WHERE company_id=$cid AND metal_id=$gold AND code='22K'");
$gram = $q("SELECT id FROM jewellery_units WHERE company_id=$cid AND code='GM'");

$mkLedger = static function (int $companyId, string $code, string $name, string $master, string $type): int {
    db()->prepare('INSERT INTO ledger_groups (company_id,name,code,master_key) VALUES (:cid,:n,:c,:m)')
        ->execute(['cid' => $companyId, 'n' => 'IV ' . $code, 'c' => 'G' . $code, 'm' => $master]);
    $gid = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO ledgers (company_id,group_id,name,code,type) VALUES (:cid,:g,:n,:c,:t)')
        ->execute(['cid' => $companyId, 'g' => $gid, 'n' => $name, 'c' => $code, 't' => $type]);

    return (int) db()->lastInsertId();
};
$L = [];
foreach ([
    ['stock_metal', 'ISTKM', 'Metal Stock', 'assets', 'asset'],
    ['stock_finished', 'ISTKF', 'Finished Stock', 'assets', 'asset'],
    ['stock_stone', 'ISTKS', 'Stone Stock', 'assets', 'asset'],
    ['sales_metal', 'ISALM', 'Sales Metal', 'income', 'revenue'],
    ['sales_making', 'ISALK', 'Sales Making', 'income', 'revenue'],
    ['sales_stone', 'ISALS', 'Sales Stone', 'income', 'revenue'],
    ['cogs', 'ICOGS', 'COGS', 'expenses', 'expense'],
    ['vat_input', 'IVATI', 'VAT Input', 'assets', 'asset'],
    ['vat_output', 'IVATO', 'VAT Payable', 'current_liability', 'liability'],
    ['spt_input', 'ISPTI', 'SD Tax Input', 'assets', 'asset'],
    ['spt_output', 'ISPTO', 'SD Tax Payable', 'current_liability', 'liability'],
    ['opening_equity', 'IOPEQ', 'Opening Equity', 'equity', 'equity'],
    ['rounding', 'IROUN', 'Rounding', 'expenses', 'expense'],
] as [$purpose, $code, $name, $master, $type]) {
    $L[$purpose] = $mkLedger($cid, $code, $name, $master, $type);
    jewellery_save_mapping($cid, $purpose, $L[$purpose], $uid);
}
$cash = $mkLedger($cid, 'ICASH', 'Cash', 'assets', 'asset');

$ring = jewellery_save_item($cid, ['code' => 'RG-148', 'name' => 'RING STOCK', 'item_type' => 'ornament',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $gram], $uid);

db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status, phone)
    VALUES (:c,'PARAG','Parag Ojha','customer','active','984921133')")->execute(['c' => $cid]);
$customer = (int) db()->lastInsertId();
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'ISUP','Bullion','supplier','active')")
    ->execute(['c' => $cid]);
$supplier = (int) db()->lastInsertId();

// Stock to sell from, bought below the selling rate so COGS is meaningful.
$pu = jewellery_save_purchase($cid, $fy, ['purchase_date' => '2026-07-20', 'party_id' => $supplier,
    'settle_mode' => 'cash', 'settle_ledger_id' => $cash],
    [['item_id' => $ring, 'gross_weight' => 100, 'qty_pieces' => 20, 'rate' => 20000]], $uid);
ok(jewellery_post_purchase($cid, $pu, $uid)['ok'], 'Opening stock is in');

echo "\nAkshara bill S8384/51 — line arithmetic\n";
$sale = jewellery_save_sale($cid, $fy, [
    'sale_date' => '2026-07-26', 'party_id' => $customer, 'sales_person' => 'Sangita',
    'settle_mode' => 'credit', 'received_amount' => 0,
], [[
    'item_id' => $ring, 'purity_id' => $p22, 'unit_id' => $gram,
    'qty_pieces' => 1,
    'gross_weight' => 2.550,     // Grs Wt
    'stone_weight' => 0.0400,    // Less
    'wastage_weight' => 0.4660,  // Wast
    'rate' => 22645.062,         // Rate/gm
    'making_amount' => 1700.00,  // Making
    'stone_amount' => 232.60,    // Stone
    'stone_carat' => 0.2500,
]], [], $uid);

$line = db()->query("SELECT * FROM jewellery_sale_lines WHERE sale_id=$sale")->fetch(PDO::FETCH_ASSOC);
ok(near((float) $line['net_weight'], 2.510), 'Net Wt   = 2.550 − 0.040 = 2.510');
ok(near((float) $line['total_weight'], 2.976), 'Total Wt = 2.510 + 0.466 = 2.976');
ok(near((float) $line['metal_amount'], 67391.70), 'Amount   = 2.976 × 22,645.062 = 67,391.70');
ok(near((float) $line['making_amount'], 1700.00), 'Making   = 1,700.00');
ok(near((float) $line['stone_amount'], 232.60), 'Stone    = 232.60');
ok(near((float) $line['wastage_amount'], 10552.60, 1.0),
    'The wastage is worth 0.466 × 22,645.062 — reported, but already inside the metal amount');

echo "\nThe totals block, exactly as printed\n";
$row = jewellery_sale($cid, $sale);
ok(near((float) $row['metal_amount'], 67391.70), 'Metal   67,391.70');
ok(near((float) $row['sd_taxable_amount'], 69091.70), 'SD Taxable Amt  = metal + making = 69,091.70');
ok(near((float) $row['tax_amount'], 345.46), 'SD Tax 0.5%     = 345.46');
ok(near((float) $row['vatable_amount'], 232.60), 'Vatable Amt     = stone side only = 232.60');
ok(near((float) $row['vat_amount'], 30.24), 'VAT 13%         = 30.24');
ok(near((float) $row['non_taxable_amount'], 0.00), 'Non Taxable Amt = 0.00');
ok(near((float) $row['total_amount'], 69700.00), 'NET TOTAL       = 69,700.00');

// The three bases must account for the whole document, or the block is a lie.
ok(near((float) $row['sd_taxable_amount'] + (float) $row['vatable_amount'] + (float) $row['non_taxable_amount'],
    (float) $row['metal_amount'] + (float) $row['making_amount'] + (float) $row['stone_amount']),
    'The three bases add up to the document value — nothing falls between them');

echo "\nVAT never touches gold or making; SD never touches stones\n";
$taxRows = db()->query("SELECT tax_code, base_amount, amount FROM jewellery_line_taxes
    WHERE company_id=$cid AND doc_type='sale' AND doc_id=$sale ORDER BY sequence")->fetchAll(PDO::FETCH_ASSOC);
$byCode = [];
foreach ($taxRows as $t) { $byCode[(string) $t['tax_code']] = $t; }
ok(isset($byCode['SD']) && near((float) $byCode['SD']['base_amount'], 69091.70),
    'SD is charged on 69,091.70 — the gold and the labour');
ok(isset($byCode['VAT']) && near((float) $byCode['VAT']['base_amount'], 232.60),
    'VAT is charged on 232.60 — the stone alone');
ok(isset($byCode['VAT']) && (float) $byCode['VAT']['base_amount'] < (float) $byCode['SD']['base_amount'],
    'And the two bases are disjoint: VAT is NOT levied on top of the SD tax');

echo "\nPosting\n";
$r = jewellery_post_sale($cid, $sale, $uid);
ok($r['ok'], 'The bill posts' . ($r['ok'] ? '' : ' — ' . $r['error']));
$v = voucher_ledgers((int) $r['voucher_id']);
ok(near($v[$L['sales_metal']] ?? 0, -67391.70), 'Metal revenue credited 67,391.70 — wastage included, as billed');
ok(near($v[$L['sales_making']] ?? 0, -1700.00), 'Making revenue credited 1,700.00');
ok(near($v[$L['sales_stone']] ?? 0, -232.60), 'Stone revenue credited 232.60');
ok(near($v[$L['spt_output']] ?? 0, -345.46), 'SD Tax payable credited 345.46 — its OWN account');
ok(near($v[$L['vat_output']] ?? 0, -30.24), 'VAT payable credited 30.24');
$recv = ensure_party_ledger($cid, $customer, 'receivable');
ok(near($v[$recv] ?? 0, 69700.00), 'The customer is debited the full 69,700.00');

$dr = 0.0; $cr = 0.0;
foreach ($v as $amount) { if ($amount > 0) { $dr += $amount; } else { $cr -= $amount; } }
ok(near($dr, $cr), 'And the voucher balances');

echo "\nOnly the NET metal leaves the shop — wastage is a charge, not gold\n";
$stockOut = db()->query("SELECT gross_grams, fine_grams FROM jewellery_stock_txns
    WHERE company_id=$cid AND direction='out' AND source_type='jewellery_sale' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
// The PHYSICAL piece that left is 2.550 g — gold plus the stone set in it.
// The customer paid for 2.976 g, but 0.466 of that is a wastage charge, not
// metal handed over, so it must NEVER leave stock.
ok(near((float) $stockOut['gross_grams'], 2.550, 0.002),
    'Stock is relieved by the 2.550 g piece that physically left');
ok((float) $stockOut['gross_grams'] < 2.9,
    'And NOT by the 2.976 the customer was billed for — wastage is a charge, not gold');
ok(near((float) $stockOut['fine_grams'], 2.510 * 0.916, 0.002),
    'Fine content comes from the NET metal: 2.510 x 0.916');

jwinv_cleanup();
echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
