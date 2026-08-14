<?php
declare(strict_types=1);

/**
 * Opening stock import — upload, preview, edit, delete, commit.
 *
 * The invariant under test is that AN UPLOAD POSTS NOTHING. Everything else
 * follows from it: a bad row is kept with its reason rather than dropped, a row
 * can be corrected here instead of back in Excel, and only a deliberate commit
 * reaches the books — where it goes through the SAME opening routine the item
 * screen uses, so an imported opening and a typed one are the same thing.
 *
 *   php database/test_opening_stock_import.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/jewellery_reports.php';
require_once __DIR__ . '/../app/opening_stock_import.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $tol = 0.011): bool { return abs($a - $b) < $tol; }
function threw(callable $fn): bool { try { $fn(); return false; } catch (Throwable $e) { return true; } }

function osi_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code='OSIMP'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$s)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['jewellery_stock_unit_events', 'jewellery_stock_units', 'inventory_opening_import_rows', 'inventory_opening_imports',
                  'jewellery_line_taxes', 'jewellery_item_taxes', 'jewellery_taxes',
                  'jewellery_stock_txns', 'jewellery_item_profiles', 'inventory_items',
                  'jewellery_item_categories',
                  'jewellery_daily_rates', 'inventory_ledger_mappings', 'jewellery_settings',
                  'jewellery_purities', 'jewellery_metals', 'jewellery_units'] as $t) {
            db()->exec("DELETE FROM `$t` WHERE company_id=$s");
        }
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email='osimp@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
osi_cleanup();

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------
db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,1)')
    ->execute(['n' => 'Import Test Jewellers (Books)', 'c' => 'OSIMP']);
$cid = (int) db()->lastInsertId();
$uid = create_user(['name' => 'Import Owner', 'email' => 'osimp@test.local', 'password' => 'Secret#12345',
    'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, jewellery_accounting_enabled)
        VALUES (:uid,:cid,:books,:org,:code,1,1)')
    ->execute(['uid' => $uid, 'cid' => $cid, 'books' => $cid, 'org' => 'Import Test Jewellers', 'code' => 'OSIMP-C']);
$fyRow = create_fiscal_year($cid, 'OSIMP 2026/27', '2026-07-16', '2027-07-15', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fyRow['id']]);
$fy = (int) $fyRow['id'];
$_SESSION['company_id'] = $cid;
jewellery_settings($cid);

$q = static fn (string $sql): int => (int) db()->query($sql)->fetchColumn();
$gold = $q("SELECT id FROM jewellery_metals WHERE company_id=$cid AND code='GOLD'");
$p22 = $q("SELECT id FROM jewellery_purities WHERE company_id=$cid AND metal_id=$gold AND code='22K'");
$tola = $q("SELECT id FROM jewellery_units WHERE company_id=$cid AND code='TOLA'");

$mkLedger = static function (int $companyId, string $code, string $name, string $master, string $type): int {
    db()->prepare('INSERT INTO ledger_groups (company_id,name,code,master_key) VALUES (:cid,:n,:c,:m)')
        ->execute(['cid' => $companyId, 'n' => 'OSI ' . $code, 'c' => 'G' . $code, 'm' => $master]);
    $gid = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO ledgers (company_id,group_id,name,code,type) VALUES (:cid,:g,:n,:c,:t)')
        ->execute(['cid' => $companyId, 'g' => $gid, 'n' => $name, 'c' => $code, 't' => $type]);

    return (int) db()->lastInsertId();
};
foreach ([
    ['stock_metal', 'OSTKM', 'Metal Stock', 'assets', 'asset'],
    ['stock_finished', 'OSTKF', 'Finished Stock', 'assets', 'asset'],
    ['opening_equity', 'OOPEQ', 'Opening Equity', 'equity', 'equity'],
] as [$purpose, $code, $name, $master, $type]) {
    jewellery_save_mapping($cid, $purpose, $mkLedger($cid, $code, $name, $master, $type), $uid);
}

$chain = jewellery_save_item($cid, ['code' => 'IMP-CH', 'name' => 'Import Chain', 'item_type' => 'ornament',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola], $uid);
$ring = jewellery_save_item($cid, ['code' => 'IMP-RG', 'name' => 'Import Ring', 'item_type' => 'ornament',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola], $uid);

// ---------------------------------------------------------------------------
// A sheet exactly as a shop would hand one over: good rows, a typo, a total
// line at the bottom, a blank line in the middle, and a row priced by amount
// rather than by rate.
// ---------------------------------------------------------------------------
$csv = "Item code,Item name,Purity,Unit,Pieces,Gross weight,Rate,Amount\n"
    . "IMP-CH,Import Chain,22K,TOLA,2,10,139000,\n"          // row 2 — priced by rate
    . "IMP-RG,Import Ring,22K,TOLA,5,4,,600000\n"            // row 3 — priced by amount
    . ",,,,,,,\n"                                             // row 4 — blank, ignored
    . "IMP-XX,Nonexistent Item,22K,TOLA,1,1,100000,\n"       // row 5 — unknown item
    . "IMP-CH,Import Chain,99K,TOLA,1,1,100000,\n"           // row 6 — unknown purity
    . "IMP-RG,Import Ring,22K,TOLA,1,1,,\n"                  // row 7 — no value at all
    . ",TOTAL,,,8,15,,739000\n";                              // row 8 — a total line, not an item
$path = tempnam(sys_get_temp_dir(), 'osi') . '.csv';
file_put_contents($path, $csv);

echo "\n1. Uploading stages rows and posts NOTHING\n";
$vouchersBefore = $q("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid");
$staged = opening_import_stage($cid, $fy, $path, 'csv', 'shop-openings.csv', 'jewellery', $uid);
$importId = (int) $staged['import_id'];
ok($importId > 0, 'The upload creates a staged batch');
ok($q("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid") === $vouchersBefore,
    'NOT ONE VOUCHER was posted by the upload');
ok($q("SELECT COUNT(*) FROM jewellery_stock_txns WHERE company_id=$cid") === 0,
    'And not one gram of stock moved either');

$rows = opening_import_rows($cid, $importId);
ok(count($rows) === 6, 'The blank line is ignored; the other six rows are staged');
ok($staged['row_count'] === 6, 'The batch counts what it staged');
ok($staged['valid_count'] === 2, 'Two rows are ready — the rest have problems');

echo "\n2. Bad rows are KEPT with the reason, not dropped\n";
$byRow = [];
foreach ($rows as $r) { $byRow[(int) $r['source_row_no']] = $r; }
ok(isset($byRow[5]) && (string) $byRow[5]['status'] === 'error', 'The unknown item is kept as an error row');
ok(str_contains((string) $byRow[5]['error_text'], 'IMP-XX'), 'And the message names the code that could not be found');
ok(isset($byRow[6]) && str_contains((string) $byRow[6]['error_text'], '99K'), 'The unknown purity is named too');
ok(isset($byRow[7]) && str_contains(strtolower((string) $byRow[7]['error_text']), 'rate or an amount'),
    'A row with no value says so plainly');
ok(isset($byRow[8]) && (string) $byRow[8]['status'] === 'error',
    'The TOTAL line at the bottom is flagged rather than imported as an item');
ok((int) $byRow[2]['source_row_no'] === 2, 'The sheet\'s own row number travels with each row');

echo "\n3. Pricing works from either direction\n";
ok(near((float) $byRow[2]['amount'], 1390000.0), 'Rate x weight fills in the amount: 10 x 139,000');
ok(near((float) $byRow[3]['rate'], 150000.0), 'Amount / weight fills in the rate: 600,000 / 4');

echo "\n4. Duplicate corrections protect physical trace identity\n";
$fix = opening_import_update_row($cid, (int) $byRow[5]['id'], [
    'item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola,
    'qty_pieces' => 1, 'gross_weight' => 1, 'rate' => 100000, 'amount' => 0,
]);
ok($fix['ok'] && $fix['status'] === 'error', 'A duplicate physical item row is correctly refused');
ok(count(opening_import_rows($cid, $importId)) === 6,
    'The refused duplicate remains available for correction instead of being dropped');
ok((int) opening_import_batch($cid, $importId)['valid_count'] === 2, 'The refused duplicate does not increase the ready-row count');

$bad = opening_import_update_row($cid, (int) $byRow[6]['id'], ['item_id' => 999999]);
ok(!$bad['ok'], 'A row cannot be pointed at an item from another company');

echo "\n5. Rows that should not be there can be deleted\n";
ok(opening_import_delete_row($cid, (int) $byRow[8]['id'])['ok'], 'The TOTAL line is deleted');
ok(count(opening_import_rows($cid, $importId)) === 5, 'Five rows remain');
ok(opening_import_delete_row($cid, (int) $byRow[7]['id'])['ok'], 'So is the valueless row');
ok(opening_import_delete_row($cid, (int) $byRow[6]['id'])['ok'], 'And the unfixable purity row');

echo "\n6. Committing posts ONLY the ready rows, through the normal opening routine\n";
$result = opening_import_commit($cid, $importId, $fy, $uid);
ok($result['ok'], 'The commit runs' . ($result['ok'] ? '' : ' — ' . $result['error']));
ok($result['committed'] === 2, 'Only the two valid unique-item rows are committed');

$openings = jewellery_opening_rows($cid, $fy);
ok(count($openings) === 2, 'Each unique item carries exactly one opening balance');
$openingValue = 0.0;
foreach ($openings as $o) { $openingValue += (float) $o['amount']; }
ok($openingValue > 0, 'And the openings carry value: ' . number_format($openingValue, 2));

ok($q("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid AND source_type='inventory_opening'") > 0,
    'Opening vouchers reached the books — the same source_type the item screen posts');
ok($q("SELECT COUNT(*) FROM jewellery_stock_txns WHERE company_id=$cid AND txn_type='opening'") > 0,
    'And the metal register carries the weight');

$after = opening_import_rows($cid, $importId);
$committedRows = 0;
foreach ($after as $r) { if ((string) $r['status'] === 'committed') { $committedRows++; } }
ok($committedRows === 2, 'Committed rows are marked, so a second commit cannot double-post them');
$again = opening_import_commit($cid, $importId, $fy, $uid);
ok(!$again['ok'] || $again['committed'] === 0, 'Committing the same batch again posts nothing more');

echo "\n7. Guards\n";
ok(threw(static fn () => opening_import_stage($cid, $fy, $path, 'csv', 'x.csv', 'jewellery', $uid)) === false,
    'A second upload is allowed — batches do not collide');
$headerless = tempnam(sys_get_temp_dir(), 'osi') . '.csv';
file_put_contents($headerless, "1,2,3\n4,5,6\n");
ok(threw(static fn () => opening_import_stage($cid, $fy, $headerless, 'csv', 'bad.csv', 'jewellery', $uid)),
    'A sheet with no recognisable header row is refused with an explanation');
@unlink($headerless);

$otherCompany = $cid + 999999;
ok(opening_import_batch($otherCompany, $importId) === null, 'A batch cannot be read through the wrong company');
ok(!opening_import_delete_row($otherCompany, (int) $byRow[2]['id'])['ok'],
    'Nor can its rows be deleted through the wrong company');


echo "\n8. Typed and uploaded openings are the SAME thing, in the same list\n";
// The screen shows one "Opening Stock" table. Whether a line got there by
// being typed into the form or by being committed from a spreadsheet must make
// no difference at all — same row on the item, same voucher, same list.
$typedItem = jewellery_save_item($cid, ['code' => 'IMP-TY', 'name' => 'Typed Item', 'item_type' => 'ornament',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola], $uid);
$typed = jewellery_save_opening($cid, $fy, [
    'item_id' => $typedItem, 'purity_id' => $p22, 'unit_id' => $tola,
    'qty_pieces' => 3, 'gross_weight' => 6, 'amount' => 540000,
], $uid);
ok(($typed['ok'] ?? false), 'An opening typed into the form saves');

$listed = jewellery_opening_rows($cid, $fy);
$byCode = [];
foreach ($listed as $row) { $byCode[(string) $row['code']] = $row; }

ok(isset($byCode['IMP-TY']), 'The TYPED opening appears in the list');
ok(isset($byCode['IMP-CH']) && isset($byCode['IMP-RG']), 'The UPLOADED openings appear in the SAME list');
ok(count($listed) === 3, 'One list, three items — no separate "imported" table');

// Same shape, so the screen cannot tell them apart.
foreach (['IMP-TY' => 'typed', 'IMP-CH' => 'uploaded'] as $code => $how) {
    $row = $byCode[$code];
    ok((float) $row['amount'] > 0 && (float) $row['gross_weight'] > 0,
        "The $how row carries weight and value like any other");
    ok((int) ($row['voucher_id'] ?? 0) > 0, "The $how row is backed by a posted voucher");
}

// Both routes post the SAME source_type, so the Opening Balances screen and
// the Voucher Register treat them identically.
$sourceTypes = db()->query("SELECT DISTINCT source_type FROM vouchers
    WHERE company_id=$cid AND source_type LIKE '%opening%'")->fetchAll(PDO::FETCH_COLUMN);
ok($sourceTypes === ['inventory_opening'],
    'Both routes post inventory_opening — one source type, not one per route');

echo "\n9. Committing again later tops the same batch up\n";
// A shop corrects the rows it could not match and commits a second time; the
// rows already in the books must not post twice.
$vouchersBefore = $q("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid AND source_type='inventory_opening'");
$second = opening_import_commit($cid, $importId, $fy, $uid);
ok($second['ok'] === false || $second['committed'] === 0, 'A second commit of the same rows posts nothing more');
ok($q("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid AND source_type='inventory_opening'") === $vouchersBefore,
    'And no extra opening voucher appears');

echo "\n10. Jewellery spreadsheet can create groups and coded items after editable review\n";
$newCsv = "Stock Type *,Stock Group *,Item Code *,Item Name *,Metal *,Purity % *,Purity Code,Unit *,Pieces *,Gross Weight (GM) *,Opening Amount *,Customer Name,Order Number\n"
    . "Showroom Stock,Bangles,BG-1,Bangle 1,Gold,92,22K,TOLA,1,2,250000,,\n"
    . "Customer Ordered Stock,Rings,RG-1,Ring 1,Gold,92,22K,TOLA,1,1,125000,Test Customer,JO-TEST-1\n";
$newPath = tempnam(sys_get_temp_dir(), 'osi-new') . '.csv';
file_put_contents($newPath, $newCsv);
$newStage = opening_import_stage($cid, $fy, $newPath, 'csv', 'segregated-openings.csv', 'jewellery', $uid);
ok($newStage['row_count'] === 2 && $newStage['valid_count'] === 2,
    'Two classified, previously unknown coded items stage as ready-to-create rows');
$newRows = opening_import_rows($cid, (int) $newStage['import_id']);
ok((int) $newRows[0]['create_item'] === 1 && (string) $newRows[0]['raw_group'] === 'Bangles',
    'The staged row keeps its create-item decision and stock group');
$editedRow = opening_import_update_row($cid, (int) $newRows[0]['id'], [
    'stock_kind' => 'customer_ordered', 'customer_name' => 'Review Customer', 'order_number' => 'JO-REVIEW-1',
    'proposed_name' => 'Bangle 1 Edited',
]);
ok($editedRow['ok'] && $editedRow['status'] === 'ready',
    'Stock type, customer link and item name remain editable in the staged preview');
$editedBack = opening_import_update_row($cid, (int) $newRows[0]['id'], [
    'stock_kind' => 'showroom', 'customer_name' => '', 'order_number' => '',
]);
ok($editedBack['ok'] && $editedBack['status'] === 'ready',
    'A reviewed row can be returned to Showroom Stock before anything is posted');
$newCommit = opening_import_commit($cid, (int) $newStage['import_id'], $fy, $uid);
ok($newCommit['ok'] && $newCommit['committed'] === 2, 'Commit creates and opens both reviewed items atomically');
$created = db()->query("SELECT i.sku, i.name, i.category, jp.stock_kind
    FROM inventory_items i INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id=i.id
    WHERE i.company_id=$cid AND i.sku IN ('BG-1','RG-1') ORDER BY i.sku")->fetchAll(PDO::FETCH_ASSOC);
ok(count($created) === 2 && $created[0]['name'] === 'Bangle 1 Edited' && $created[0]['category'] === 'Bangles',
    'BG-1 is created with its reviewed name under the Bangles stock group');
ok($created[0]['stock_kind'] === 'showroom' && $created[1]['stock_kind'] === 'customer_ordered',
    'Showroom and customer-ordered classifications remain separate on the item masters');
ok($q("SELECT COUNT(*) FROM jewellery_item_categories WHERE company_id=$cid AND name IN ('Bangles','Rings')") === 2,
    'Missing stock groups are created once in the Jewellery category master');
$templateHeader = opening_import_template_rows(true)[0];
ok($templateHeader === [
    'SN', 'Stock type', 'Stock group', 'Item code', 'Item name', 'Metal', 'Purity',
    'Unit', 'Pieces', 'Gross weight', 'Stone weight (ct)', 'Stone amount', 'Diamond weight (ct)', 'Diamond amount', 'Net weight (auto)', 'Making charge', 'Rate', 'Amount', 'Customer name', 'Order number',
], 'The downloadable template exactly matches the supplied stock workbook columns');
$openingScreen = (string) file_get_contents(__DIR__ . '/../public_html/admin/jewellery.php');
ok(str_contains($openingScreen, 'Source Excel Row')
    && str_contains($openingScreen, 'Existing Item / Create')
    && str_contains($openingScreen, 'Validation Status')
    && stripos($openingScreen, 'from the sheet') === false,
    'The staged preview mirrors the template and keeps matching/validation in separate review columns');
@unlink($newPath);

@unlink($path);
osi_cleanup();
echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
