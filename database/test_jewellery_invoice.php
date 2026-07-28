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

echo "\nThe tender row: a breakdown that has to agree with itself\n";
$mixed = jewellery_save_sale($cid, $fy, [
    'sale_date' => '2026-07-26', 'party_id' => $customer, 'sales_person' => 'Sangita',
    'customer_ref' => 'C-8384', 'tran_date_bs' => '2083-04-11', 'remarks' => 'Handed over at the counter.',
    'settle_mode' => 'cash', 'settle_ledger_id' => $cash, 'received_amount' => 10000,
    'paid_cash' => 4000, 'paid_card' => 3500, 'paid_cheque' => 1500, 'paid_qr' => 1000,
], [[
    'item_id' => $ring, 'purity_id' => $p22, 'unit_id' => $gram,
    'qty_pieces' => 1, 'gross_weight' => 2.550, 'stone_weight' => 0.0400,
    'wastage_weight' => 0.4660, 'rate' => 22645.062, 'making_amount' => 1700.00,
    'stone_amount' => 232.60, 'stone_carat' => 0.2500,
]], [], $uid);
$mixedRow = jewellery_sale($cid, $mixed);
ok(near((float) $mixedRow['paid_cash'], 4000) && near((float) $mixedRow['paid_card'], 3500)
    && near((float) $mixedRow['paid_cheque'], 1500) && near((float) $mixedRow['paid_qr'], 1000),
    'Cash / card / cheque / QR are stored as punched');
ok(near((float) $mixedRow['paid_cash'] + (float) $mixedRow['paid_card']
    + (float) $mixedRow['paid_cheque'] + (float) $mixedRow['paid_qr'], (float) $mixedRow['received_amount']),
    'And they add up to the amount received');
ok((string) $mixedRow['customer_ref'] === 'C-8384' && (string) $mixedRow['tran_date_bs'] === '2083-04-11'
    && (string) $mixedRow['remarks'] === 'Handed over at the counter.',
    'Customer id, the B.S. tran date and the remarks all survive the round trip');

$refused = '';
try {
    jewellery_save_sale($cid, $fy, [
        'sale_date' => '2026-07-26', 'party_id' => $customer,
        'settle_mode' => 'cash', 'settle_ledger_id' => $cash, 'received_amount' => 10000,
        'paid_cash' => 4000, 'paid_card' => 3500,
    ], [[
        'item_id' => $ring, 'purity_id' => $p22, 'unit_id' => $gram,
        'qty_pieces' => 1, 'gross_weight' => 2.550, 'rate' => 22645.062,
    ]], [], $uid);
} catch (Throwable $e) {
    $refused = $e->getMessage();
}
ok(stripos($refused, 'tender split') !== false,
    'A split that does not add up to the receipt is refused, not quietly printed');

echo "\nThe counter can punch this bill from the form, not only from the API\n";
// Exactly what the sale form posts for the Akshara line. If jw_posted_lines
// drops a field the whole invoice model is unreachable from the screen, which
// is how it stood before these columns were put on the grid.
$formLines = jw_posted_lines([
    'l_item_id' => [$ring], 'l_purity_id' => [$p22], 'l_unit_id' => [$gram],
    'l_qty_pieces' => ['1'], 'l_gross_weight' => ['2.550'], 'l_stone_weight' => ['0.0400'],
    'l_rate' => ['22645.062'], 'l_wastage_pct' => ['0'], 'l_wastage_weight' => ['0.4660'],
    'l_making_amount' => ['1700.00'],
    'l_diamond_carat' => ['0'], 'l_diamond_amount' => ['0'],
    'l_other_diamond_carat' => ['0'], 'l_other_diamond_amount' => ['0'],
    'l_stone_carat' => ['0.2500'], 'l_stone_amount' => ['232.60'],
], 'l');
ok(count($formLines) === 1 && near((float) $formLines[0]['wastage_weight'], 0.4660)
    && near((float) $formLines[0]['stone_carat'], 0.2500),
    'The form carries the wastage weight and the stone carat through to the engine');
ok(array_key_exists('diamond_amount', $formLines[0]) && array_key_exists('other_diamond_carat', $formLines[0]),
    'And both diamond columns as well');

$formSale = jewellery_save_sale($cid, $fy, [
    'sale_date' => '2026-07-26', 'party_id' => $customer, 'settle_mode' => 'credit',
], $formLines, [], $uid);
$formRow = jewellery_sale($cid, $formSale);
ok(near((float) $formRow['total_amount'], 69700.00),
    'A bill punched through the form comes to the same 69,700.00 as the paper');

// The diamond columns, which the Akshara bill left empty, have to price and be
// vatable the moment a shop uses them.
$diaSale = jewellery_save_sale($cid, $fy, [
    'sale_date' => '2026-07-26', 'party_id' => $customer, 'settle_mode' => 'credit',
], jw_posted_lines([
    'l_item_id' => [$ring], 'l_purity_id' => [$p22], 'l_unit_id' => [$gram],
    'l_qty_pieces' => ['1'], 'l_gross_weight' => ['2.000'], 'l_rate' => ['22645.062'],
    'l_making_amount' => ['1000.00'],
    'l_diamond_carat' => ['0.750'], 'l_diamond_amount' => ['40000.00'],
    'l_other_diamond_carat' => ['0.250'], 'l_other_diamond_amount' => ['5000.00'],
    'l_stone_carat' => ['0.100'], 'l_stone_amount' => ['500.00'],
], 'l'), [], $uid);
$diaRow = jewellery_sale($cid, $diaSale);
ok(near((float) $diaRow['vatable_amount'], 45500.00),
    'Vatable Amt gathers diamond + other diamond + stone = 45,500.00');
ok(near((float) $diaRow['sd_taxable_amount'], jw_round_money(2.0 * 22645.062) + 1000.00),
    'SD Taxable Amt stays metal + making — the diamonds are not in it');
ok(near((float) $diaRow['vat_amount'], 5915.00), 'VAT 13% of 45,500.00 = 5,915.00');
$diaPost = jewellery_post_sale($cid, $diaSale, $uid);
ok($diaPost['ok'], 'A bill with diamonds posts' . ($diaPost['ok'] ? '' : ' — ' . $diaPost['error']));
$dv = voucher_ledgers((int) $diaPost['voucher_id']);
ok(near($dv[$L['sales_stone']] ?? 0, -45500.00),
    'And all three stone columns land in the stone revenue account, 45,500.00');

echo "\nThe tax register files what was actually charged\n";
// The Akshara bill and the diamond bill are both posted by now.
$register = jw_report_vat_register($cid, '2026-07-16', '2027-07-15');
$byCode2 = [];
foreach ($register['by_tax'] as $t) { $byCode2[(string) $t['tax_code']] = $t; }
ok(isset($byCode2['VAT']) && isset($byCode2['SD']),
    'Both taxes appear — a register that knew only about VAT could not be filed');
ok(isset($byCode2['SD']) && near((float) $byCode2['SD']['output_amount'], 345.46 + jw_round_money((jw_round_money(2.0 * 22645.062) + 1000.00) * 0.005)),
    'The SD levy is registered on its own base, separately from VAT');
ok(isset($byCode2['VAT']) && near((float) $byCode2['VAT']['output_base'], 232.60 + 45500.00),
    'The VAT base is the stone side of both bills — 45,732.60, NOT the whole bill value');
ok((float) $register['output']['taxable'] < 46000.0,
    'And the VAT register does not declare the gold as taxable: a stone_diamond base '
    . 'must never fall through to the whole line');
ok(near((float) $register['output']['vat'], (float) $byCode2['VAT']['output_amount']),
    'The line-level VAT total and the per-tax total agree');

echo "\nA bill raised before the tax bases existed still reprints properly\n";
// Beat the header back into its pre-083 shape: the three printed bases empty,
// with only the single taxable_amount the old engine knew about.
db()->exec("UPDATE jewellery_sales SET non_taxable_amount = 0, sd_taxable_amount = 0, vatable_amount = 0,
        taxable_amount = 232.60, wastage_amount = 0
    WHERE id = $sale");
$legacy = jewellery_sale($cid, $sale);
ok(near((float) $legacy['sd_taxable_amount'], 0) && near((float) $legacy['vatable_amount'], 0),
    'The document now looks exactly like one raised before the split');
accounting_module_repair_database();
$repaired = jewellery_sale($cid, $sale);
ok(near((float) $repaired['vatable_amount'], 232.60),
    'The repair puts the old single base where VAT was actually charged');
ok((float) $repaired['non_taxable_amount'] > 0,
    'And the rest of the document value lands in Non Taxable Amt rather than vanishing');
ok(near((float) $repaired['non_taxable_amount'] + (float) $repaired['vatable_amount'],
    (float) $repaired['metal_amount'] + (float) $repaired['making_amount'] + (float) $repaired['stone_amount']),
    'The two bases still account for the whole document — the totals block adds up');
// Put the row back the way the engine computed it, so what follows measures the
// real document and not the reconstruction.
db()->exec("UPDATE jewellery_sales SET non_taxable_amount = 0, sd_taxable_amount = 69091.70,
        vatable_amount = 232.60, taxable_amount = 232.60 WHERE id = $sale");

echo "\nThe sales report agrees with the ledger it posted to\n";
$salesReport = jw_report_sales_detail($cid, '2026-07-16', '2027-07-15');
$reportedRevenue = (float) $salesReport['totals']['revenue'];
// What the books say: everything credited to the three revenue accounts.
$bookedRevenue = (float) db()->query('SELECT COALESCE(SUM(CASE WHEN e.entry_type = \'credit\' THEN e.amount ELSE -e.amount END), 0)
    FROM voucher_entries e INNER JOIN vouchers v ON v.id = e.voucher_id
    WHERE v.company_id=' . $cid . ' AND e.ledger_id IN (' . $L['sales_metal'] . ',' . $L['sales_making'] . ',' . $L['sales_stone'] . ')')->fetchColumn();
ok(near($reportedRevenue, $bookedRevenue, 0.02),
    'Reported revenue ' . number_format($reportedRevenue, 2) . ' equals what was credited to sales, '
    . number_format($bookedRevenue, 2));
ok(near((float) $salesReport['totals']['stone_side'], 232.60 + 45500.00),
    'The stone side gathers all three columns — a diamond bill is not understated by 45,000');

echo "\nThe printed bill carries the same figures the books do\n";
// Rendering has to happen in a child process: the page is a full document that
// ends the request, and require_jewellery() needs a real session context.
$runner = __DIR__ . '/jwinv_render_probe.php';
file_put_contents($runner, <<<'PROBE'
<?php
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../app/bootstrap.php';
$_SESSION['user_id'] = (int) $argv[3];
set_context((int) $argv[1], (int) $argv[2]);
mark_company_pin_verified((int) $argv[1]);
set_selected_company((int) $argv[1]);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin/jewellery-invoice.php';
$_GET = ['id' => (int) $argv[4]];
$_POST = [];
register_shutdown_function(static function (): void {
    $html = '';
    while (ob_get_level() > 0) { $html = ob_get_clean() . $html; }
    fwrite(STDOUT, $html);
});
ob_start();
include __DIR__ . '/../public_html/admin/jewellery-invoice.php';
PROBE);
$html = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' '
    . $cid . ' ' . $fy . ' ' . $uid . ' ' . $sale . ' 2>&1');
@unlink($runner);
$text = html_entity_decode(strip_tags(preg_replace('~<(script|style)[^>]*>.*?</\1>~is', ' ', $html) ?? ''), ENT_QUOTES, 'UTF-8');
$has = static fn (string $needle): bool => strpos($text, $needle) !== false;

ok(stripos($html, 'Fatal error') === false && stripos($html, 'Warning:') === false
    && stripos($html, 'Uncaught') === false, 'The invoice renders without a single notice');
ok($has('2.550') && $has('0.0400') && $has('2.510') && $has('0.4660') && $has('2.976'),
    'The five weight columns print 2.550 / 0.0400 / 2.510 / 0.4660 / 2.976');
ok($has('22,645.062'), 'Rate/Gm prints 22,645.062');
ok($has(number_format(22645.062 * 11.6638, 2)), 'And the per-tola figure beside it is the gram rate x 11.6638');
ok($has('67,391.70') && $has('1,700.00') && $has('232.60'),
    'Amount, Making and Stone print 67,391.70 / 1,700.00 / 232.60');
ok($has('69,091.70') && $has('345.46'), 'SD Taxable Amt 69,091.70 and SD Tax 345.46 print');
ok($has('30.24'), 'VAT 30.24 prints');
ok($has('69,700.00'), 'Net Total 69,700.00 prints');
ok($has(npr_amount_in_words(69700.00)), 'And the amount in words agrees with it');
ok($has('SD Taxable Amt') && $has('Vatable Amt') && $has('Non Taxable Amt'),
    'The totals block is named the way the law names it');
ok($has('Cash') && $has('Card') && $has('Advance') && $has('Cheque')
    && $has('Credit') && $has('QR/Transfer') && $has('Purchase'),
    'The seven tender columns are all on the paper');
ok($has('69,700.00') && $has('Credit'),
    'This bill was sold on credit, so the whole 69,700.00 sits in the Credit column');
ok($has('Sangita'), 'The sales person is named');
ok(substr_count($html, '<tr>') >= 8, 'And the line table is padded out with blank rows to a full sheet');

echo "\nAnd the tender split POSTS the way it prints\n";
/*
 * The breakdown used to be for the paper only: every rupee went to the single
 * settlement ledger, so a bill paid half in cash and half by card posted as
 * though it were all cash. The cash book then disagreed with the till by the
 * card takings, every day, with nothing in the books to explain the gap.
 */
$tenderCash = $mkLedger($cid, 'TCASH', 'Cash in hand', 'assets', 'asset');
$tenderCard = $mkLedger($cid, 'TCARD', 'Card settlement', 'assets', 'asset');
$tenderQr = $mkLedger($cid, 'TQR', 'QR wallet', 'assets', 'asset');
jewellery_save_mapping($cid, 'tender_cash', $tenderCash, $uid);
jewellery_save_mapping($cid, 'tender_card', $tenderCard, $uid);
jewellery_save_mapping($cid, 'tender_qr', $tenderQr, $uid);
// tender_cheque is deliberately left unmapped, to prove a shop can adopt this
// one mode at a time without the unmapped ones going astray.

$mixedPost = jewellery_post_sale($cid, $mixed, $uid);
ok($mixedPost['ok'], 'The mixed-tender bill posts' . ($mixedPost['ok'] ? '' : ' — ' . $mixedPost['error']));
$mv = voucher_ledgers((int) $mixedPost['voucher_id']);
ok(near($mv[$tenderCash] ?? 0, 4000.00), 'The 4,000 in cash is debited to cash in hand');
ok(near($mv[$tenderCard] ?? 0, 3500.00), 'The 3,500 on card goes to the card account, not to cash');
ok(near($mv[$tenderQr] ?? 0, 1000.00), 'And the 1,000 by QR to the wallet account');
ok(near($mv[$cash] ?? 0, 1500.00),
    'The cheque, whose mode nobody mapped, falls back to the settlement ledger — 1,500');
$mixedDr = 0.0; $mixedCr = 0.0;
foreach ($mv as $amount) { if ($amount > 0) { $mixedDr += $amount; } else { $mixedCr -= $amount; } }
ok(near($mixedDr, $mixedCr), 'And the voucher still balances across four settlement ledgers');
ok(near(($mv[$tenderCash] ?? 0) + ($mv[$tenderCard] ?? 0) + ($mv[$tenderQr] ?? 0) + ($mv[$cash] ?? 0),
    (float) $mixedRow['received_amount']),
    'The four together come to exactly what the customer handed over');

// A bill with no breakdown at all must behave exactly as it always did.
$plain = jewellery_save_sale($cid, $fy, [
    'sale_date' => '2026-07-27', 'party_id' => $customer,
    'settle_mode' => 'cash', 'settle_ledger_id' => $cash, 'received_amount' => 5000,
], [[
    'item_id' => $ring, 'purity_id' => $p22, 'unit_id' => $gram,
    'qty_pieces' => 1, 'gross_weight' => 2.550, 'rate' => 22645.062,
]], [], $uid);
$plainPost = jewellery_post_sale($cid, $plain, $uid);
ok($plainPost['ok'], 'A bill with no tender breakdown still posts'
    . ($plainPost['ok'] ? '' : ' — ' . $plainPost['error']));
$pv = voucher_ledgers((int) $plainPost['voucher_id']);
ok(near($pv[$cash] ?? 0, 5000.00),
    'Its whole receipt goes to the settlement ledger, exactly as before — a shop ignoring this sees no change');
ok(near($pv[$tenderCash] ?? 0, 0.0), 'And nothing is invented in the tender accounts');


jwinv_cleanup();
echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
