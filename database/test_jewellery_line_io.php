<?php
declare(strict_types=1);

/**
 * Filling the grid without typing: saved templates, and a spreadsheet.
 *
 * The point of both is that they only FILL A FORM. Neither writes a document,
 * so neither can put anything in the books that the ordinary save would have
 * refused — and the tests below check that boundary as much as the parsing.
 *
 *   php database/test_jewellery_line_io.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/jewellery_reports.php';
require_once __DIR__ . '/../app/jewellery_line_io.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $tol = 0.011): bool { return abs($a - $b) < $tol; }

function jwio_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('JWIOA','JWIOB')")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        foreach (['jewellery_line_templates', 'jewellery_item_profiles', 'inventory_items',
                  'jewellery_settings', 'jewellery_purities', 'jewellery_metals', 'jewellery_units'] as $t) {
            db()->exec("DELETE FROM `$t` WHERE company_id=$s");
        }
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email LIKE 'jwio-%@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
jwio_cleanup();

$mk = static function (string $code, string $email): array {
    db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,1)')
        ->execute(['n' => $code . ' Books', 'c' => $code]);
    $cid = (int) db()->lastInsertId();
    $uid = create_user(['name' => $code, 'email' => $email, 'password' => 'Secret#12345',
        'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
    db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, jewellery_accounting_enabled)
        VALUES (:uid,:cid,:cid2,:org,:code,1,1)')
        ->execute(['uid' => $uid, 'cid' => $cid, 'cid2' => $cid, 'org' => $code, 'code' => $code . '-C']);
    $fy = create_fiscal_year($cid, $code . ' 2026/27', '2026-07-16', '2027-07-15', true);
    $_SESSION['company_id'] = $cid;
    jewellery_settings($cid);

    return [$cid, (int) $fy['id'], $uid];
};
[$cidA, $fyA, $userA] = $mk('JWIOA', 'jwio-a@test.local');
[$cidB, $fyB, $userB] = $mk('JWIOB', 'jwio-b@test.local');
$_SESSION['company_id'] = $cidA;

$q = static fn (int $cid, string $sql): int => (int) db()->query($sql)->fetchColumn();
$gold = $q($cidA, "SELECT id FROM jewellery_metals WHERE company_id=$cidA AND code='GOLD'");
$p22 = $q($cidA, "SELECT id FROM jewellery_purities WHERE company_id=$cidA AND metal_id=$gold AND code='22K'");
$tola = $q($cidA, "SELECT id FROM jewellery_units WHERE company_id=$cidA AND code='TOLA'");
$gram = $q($cidA, "SELECT id FROM jewellery_units WHERE company_id=$cidA AND code='GM'");
$chain = jewellery_save_item($cidA, ['code' => 'CHN-1', 'name' => 'Gold Chain', 'item_type' => 'ornament',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola], $userA);
$ring = jewellery_save_item($cidA, ['code' => 'RNG-1', 'name' => 'Gold Ring', 'item_type' => 'ornament',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $gram], $userA);

echo "1. Saving a set of lines as a template\n";
$lines = [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 2.5, 'wastage_pct' => 8, 'rate' => 150000, 'making_amount' => 4000],
    ['item_id' => $ring, 'purity_id' => $p22, 'unit_id' => $gram, 'qty_pieces' => 1,
     'gross_weight' => 9, 'stone_weight' => 0.4, 'rate' => 13000, 'making_amount' => 1500,
     'stone_amount' => 900, 'stone_carat' => 0.35],
    ['item_id' => 0, 'gross_weight' => 0],      // the grid's blank spare
];
$saved = jw_template_save($cidA, 'sale', 'Wedding set', $lines, $userA);
ok($saved['ok'] && $saved['count'] === 2, 'Two lines are saved and the blank spare is dropped');
ok(!jw_template_save($cidA, 'sale', '', $lines, $userA)['ok'], 'A template needs a name');
ok(!jw_template_save($cidA, 'sale', 'Empty', [['item_id' => 0]], $userA)['ok'],
    'And at least one real line — a template of blanks is worse than none');

echo "\n2. Loading it back\n";
$list = jw_templates_list($cidA, 'sale');
ok(count($list) === 1 && (string) $list[0]['name'] === 'Wedding set', 'It appears in the list');
ok((int) $list[0]['line_count'] === 2, 'With its line count');
ok(jw_templates_list($cidA, 'purchase') === [],
    'A sale template does not show on the purchase form — the two grids differ');

$loaded = jw_template_lines($cidA, (int) $list[0]['id']);
ok(count($loaded) === 2, 'Both lines come back');
ok((int) $loaded[0]['item_id'] === $chain && near((float) $loaded[0]['gross_weight'], 2.5)
    && near((float) $loaded[0]['wastage_pct'], 8.0), 'The first line is exactly as it was saved');
ok(near((float) $loaded[1]['stone_amount'], 900.0) && near((float) $loaded[1]['stone_carat'], 0.35),
    'And the second keeps its stone columns');

echo "\n3. Templates belong to one company\n";
ok(jw_templates_list($cidB, 'sale') === [], "Company B sees none of company A's templates");
ok(jw_template_lines($cidB, (int) $list[0]['id']) === [],
    "And cannot load one by its id");
ok(!jw_template_delete($cidB, (int) $list[0]['id']), 'Nor delete it');
ok(jw_template_delete($cidA, (int) $list[0]['id']), 'Its owner can');
ok(jw_templates_list($cidA, 'sale') === [], 'And then it is gone');

echo "\n4. Reading lines out of a spreadsheet\n";
$csv = sys_get_temp_dir() . '/jwio_lines_' . getmypid() . '.csv';
file_put_contents($csv, implode("\n", [
    'Item,Purity,Unit,Pcs,Gross Wt,Less,Wastage %,Rate,Making,Stone Amt',
    'CHN-1,22K,TOLA,1,2.500,0,8,150000,4000,0',
    'RNG-1,22K,GM,2,9.000,0.400,0,13000,1500,900',
]));
$imported = jw_import_lines($cidA, $csv, 'csv');
ok($imported['ok'] && $imported['matched'] === 2, 'Both rows are read');
ok($imported['errors'] === [], 'With nothing skipped');
ok((int) $imported['rows'][0]['item_id'] === $chain, 'The item code resolves to the item');
ok((int) $imported['rows'][0]['unit_id'] === $tola && (int) $imported['rows'][1]['unit_id'] === $gram,
    'Each row keeps its own unit');
ok(near((float) $imported['rows'][0]['gross_weight'], 2.5)
    && near((float) $imported['rows'][0]['wastage_pct'], 8.0), 'The numbers come through');
ok(near((float) $imported['rows'][1]['stone_amount'], 900.0), 'So does the stone column');

echo "\n5. Headings do not have to be typed the app's way\n";
file_put_contents($csv, implode("\n", [
    'ITEM CODE,purity,UNIT,Gross Weight (gm),Rate/gm,Making Charge',
    'Gold Chain,GOLD-22K,Tola,3.000,"1,50,000",5000',
]));
$loose = jw_import_lines($cidA, $csv, 'csv');
ok($loose['ok'] && $loose['matched'] === 1, 'Different capitalisation and spacing still match');
ok((int) $loose['rows'][0]['item_id'] === $chain, 'And an item can be named instead of coded');
ok(near((float) $loose['rows'][0]['rate'], 150000.0),
    'A rate typed with thousands separators is read as a number');

echo "\n6. What it refuses, and says so\n";
file_put_contents($csv, implode("\n", [
    'Item,Purity,Gross Wt',
    'CHN-1,22K,1.000',
    'NO-SUCH-ITEM,22K,2.000',
    'CHN-1,NOT-A-PURITY,3.000',
]));
$partial = jw_import_lines($cidA, $csv, 'csv');
ok($partial['matched'] === 1, 'The good row is kept');
ok(count($partial['errors']) === 2, 'And the two bad ones are reported, not silently dropped');
ok(str_contains($partial['errors'][0], 'Row 3'), 'By row number, so the sheet can be corrected');
ok(str_contains($partial['errors'][1], 'NOT-A-PURITY'), 'Naming what could not be matched');

file_put_contents($csv, "Something,Else\n1,2");
$noItem = jw_import_lines($cidA, $csv, 'csv');
ok(!$noItem['ok'] && str_contains($noItem['errors'][0], 'Item'),
    'A sheet with no Item column is refused with a reason');

// The important boundary: a spreadsheet cannot reach another tenant's items.
file_put_contents($csv, "Item,Gross Wt\nCHN-1,5.000");
ok(jw_import_lines($cidB, $csv, 'csv')['matched'] === 0,
    "Company B importing company A's item code matches nothing — names resolve per company");

@unlink($csv);

echo "\n7. Nothing here writes a document\n";
$before = (int) db()->query("SELECT COUNT(*) FROM jewellery_sales WHERE company_id=$cidA")->fetchColumn();
jw_template_save($cidA, 'sale', 'Another', $lines, $userA);
file_put_contents($csv = sys_get_temp_dir() . '/jwio2_' . getmypid() . '.csv', "Item,Gross Wt\nCHN-1,5.000");
jw_import_lines($cidA, $csv, 'csv');
@unlink($csv);
$after = (int) db()->query("SELECT COUNT(*) FROM jewellery_sales WHERE company_id=$cidA")->fetchColumn();
ok($before === $after, 'Saving a template and importing a sheet create no sale');
ok((int) db()->query("SELECT COUNT(*) FROM jewellery_stock_txns WHERE company_id=$cidA")->fetchColumn() === 0,
    'And move no stock — they only fill a form');

jwio_cleanup();
echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
