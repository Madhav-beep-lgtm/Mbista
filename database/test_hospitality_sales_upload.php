<?php
declare(strict_types=1);

/**
 * Hospitality daily sales upload: sheet parsing (VAT extraction, discount,
 * BS/AD dates), category/item/default ledger resolution priority, one balanced
 * voucher per sale date (Dr receivable + discount, Cr per-category sales +
 * VAT), audit batch + lines, duplicate-date guard, and tenant isolation of the
 * ledger mapping.
 *   php database/test_hospitality_sales_upload.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/hospitality_sales_posting.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.011; }

function hsu_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'HOSPUP'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE e FROM voucher_entries e JOIN vouchers v ON v.id = e.voucher_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['hospitality_sales_upload_lines', 'hospitality_sales_uploads', 'hospitality_sales_ledger_maps', 'hospitality_settings'] as $t) {
            db()->exec("DELETE FROM `$t` WHERE company_id=$s");
        }
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email = 'hosptest-up@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
hsu_cleanup();

// ---------------------------------------------------------------------------
// Fixture: hospitality client with books company, open FY 2026/27, ledgers.
// ---------------------------------------------------------------------------
db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n, :c, 1, 1)')
    ->execute(['n' => 'Upload House (Books)', 'c' => 'HOSPUP']);
$cid = (int) db()->lastInsertId();
$uid = create_user(['name' => 'Upload Owner', 'email' => 'hosptest-up@test.local', 'password' => 'Secret#12345', 'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, hospitality_accounting_enabled)
        VALUES (:uid, :cid, :books, :org, :code, 1, 1)')
    ->execute(['uid' => $uid, 'cid' => $cid, 'books' => $cid, 'org' => 'Upload House', 'code' => 'HOSPUP-C']);
$fy = create_fiscal_year($cid, 'HOSPUP 2026/27', '2026-07-16', '2027-07-15', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId = (int) $fy['id'];
$_SESSION['company_id'] = $cid;
$actorId = (int) db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();

$mkLedger = static function (string $code, string $name, string $type) use ($cid): int {
    db()->prepare('INSERT INTO ledgers (company_id, code, name, type, status) VALUES (:cid, :code, :name, :type, \'active\')')
        ->execute(['cid' => $cid, 'code' => $code, 'name' => $name, 'type' => $type]);
    return (int) db()->lastInsertId();
};
$lSalesDefault = $mkLedger('HSU-S0', 'Sales — General', 'revenue');
$lSalesFood = $mkLedger('HSU-S1', 'Sales — Food', 'revenue');
$lSalesBev = $mkLedger('HSU-S2', 'Sales — Beverage', 'revenue');
$lSalesMomo = $mkLedger('HSU-S3', 'Sales — Momo Counter', 'revenue');
$lVat = $mkLedger('HSU-V1', 'VAT Payable', 'liability');
$lDisc = $mkLedger('HSU-D1', 'Discount Allowed', 'expense');
$lRecv = $mkLedger('HSU-R1', 'Daily Sales Receivable', 'asset');

echo "1. Posting configuration guard\n";
$settings = hospitality_settings($cid);
ok(hospitality_posting_config_errors($cid, $settings) !== [], 'Posting blocked while the sales/receivable ledgers are unmapped');
db()->prepare('UPDATE hospitality_settings SET post_sales_ledger_id=:s, post_vat_ledger_id=:v, post_discount_ledger_id=:d,
        post_receivable_ledger_id=:r, post_vat_rate=13.00, post_amount_includes_vat=1 WHERE company_id=:cid')
    ->execute(['s' => $lSalesDefault, 'v' => $lVat, 'd' => $lDisc, 'r' => $lRecv, 'cid' => $cid]);
$settings = hospitality_settings($cid);
ok(hospitality_posting_config_errors($cid, $settings) === [], 'Configuration complete once the ledgers are set');

echo "\n2. Category / item ledger mapping priority\n";
$mkMap = static function (string $type, string $value, int $ledgerId) use ($cid): void {
    db()->prepare("INSERT INTO hospitality_sales_ledger_maps (company_id, map_type, match_value, display_value, sales_ledger_id, active)
            VALUES (:cid, :mt, :norm, :disp, :ledger, 1)")
        ->execute(['cid' => $cid, 'mt' => $type, 'norm' => hospitality_sales_norm($value), 'disp' => $value, 'ledger' => $ledgerId]);
};
$mkMap('category', 'Food', $lSalesFood);
$mkMap('category', 'Beverage', $lSalesBev);
$mkMap('item', 'Chicken Momo (10 pcs)', $lSalesMomo);
$maps = hospitality_sales_ledger_maps($cid);
$defaultLedger = hospitality_posting_ledger($cid, $lSalesDefault);
ok((int) hospitality_resolve_sales_ledger($maps, $defaultLedger, 'Food', 'Chicken Momo (10 pcs)')['ledger_id'] === $lSalesMomo,
    'ITEM mapping wins over the category ledger');
ok((int) hospitality_resolve_sales_ledger($maps, $defaultLedger, ' food ', 'Veg Chowmein')['ledger_id'] === $lSalesFood,
    'Category match is case/space-insensitive');
$fallback = hospitality_resolve_sales_ledger($maps, $defaultLedger, 'Bakery', 'Croissant');
ok((int) $fallback['ledger_id'] === $lSalesDefault && $fallback['source'] === 'default',
    'Unmapped category falls back to the default sales ledger');

echo "\n3. Sheet parsing (CSV): totals, VAT extraction, per-row errors\n";
$csv = "Date,Category,Item,Qty,Total Sales Amount,Discount\n"
    . "2026-08-01,Food,Chicken Momo (10 pcs),10,2260,0\n"          // taxable 2000, VAT 260 (13% incl) -> momo ledger
    . "2026-08-01,Food,Veg Chowmein,5,1130,113\n"                  // taxable 1000, VAT 130, discount 113 -> food ledger
    . "2026-08-01,Beverage,Masala Tea,20,1130,0\n"                 // taxable 1000, VAT 130 -> beverage ledger
    . "2026-08-02,Bakery,Croissant,4,565,0\n"                      // taxable 500, VAT 65 -> default ledger
    . "2026-08-02,Food,Bad Row,-2,-100,0\n"                        // error: negative qty and amount
    . "not-a-date,Food,Bad Date,1,113,0\n";                        // error: date
$tmp = tempnam(sys_get_temp_dir(), 'hsu') . '.csv';
file_put_contents($tmp, $csv);
$parsed = hospitality_sales_upload_parse($tmp, 'csv', $cid, $fyId, $settings);
ok(!isset($parsed['error']), 'Sheet parses without a fatal error');
ok((int) $parsed['valid_count'] === 4 && (int) $parsed['error_count'] === 2, '4 valid rows, 2 rows carry errors');
ok(near((float) $parsed['totals']['taxable'], 4500.0) && near((float) $parsed['totals']['vat'], 585.0),
    'VAT extracted at 13% from inclusive amounts (taxable 4500, VAT 585)');
ok(near((float) $parsed['totals']['discount'], 113.0) && near((float) $parsed['totals']['receivable'], 4972.0),
    'Discount reduces the receivable (5085 gross - 113)');
ok(count($parsed['days']) === 2, 'Two sale dates -> two planned vouchers');
ok($parsed['duplicate_dates'] === [], 'No duplicate dates before the first post');
$badRows = array_values(array_filter($parsed['rows'], static fn (array $r): bool => $r['errors'] !== []));
ok(count($badRows) === 2, 'Both bad rows are flagged with row-level errors');

echo "\n4. Posting: one balanced voucher per day, batch + lines recorded\n";
$result = hospitality_post_sales_upload($cid, $fyId, $parsed, 'aug-day-sheet.csv', $actorId);
ok($result['ok'] === true, 'Posting succeeds: ' . ($result['error'] ?? ''));
ok((int) ($result['vouchers'] ?? 0) === 2 && (int) ($result['rows'] ?? 0) === 4, 'Two vouchers cover the four valid rows');
$uploadId = (int) ($result['upload_id'] ?? 0);

$vouchers = db()->query("SELECT * FROM vouchers WHERE company_id=$cid AND source_type='hospitality_sales_upload' ORDER BY voucher_date ASC")->fetchAll();
ok(count($vouchers) === 2, 'Vouchers stored with source hospitality_sales_upload');
$balanced = true; $day1 = null;
foreach ($vouchers as $v) {
    $sums = db()->query('SELECT entry_type, SUM(amount) s FROM voucher_entries WHERE voucher_id=' . (int) $v['id'] . ' GROUP BY entry_type')->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!near((float) ($sums['debit'] ?? 0), (float) ($sums['credit'] ?? 0))) { $balanced = false; }
    if ((string) $v['voucher_date'] === '2026-08-01') { $day1 = $v; }
}
ok($balanced, 'Every posted voucher balances (Dr total = Cr total)');
ok($day1 !== null && near((float) $day1['total_amount'], 4520.0), 'Day 1 voucher totals the day\'s gross (4520)');

$day1Entries = db()->query('SELECT ledger_id, entry_type, amount FROM voucher_entries WHERE voucher_id=' . (int) $day1['id'])->fetchAll();
$byLedger = [];
foreach ($day1Entries as $e) { $byLedger[(int) $e['ledger_id']] = [$e['entry_type'], (float) $e['amount']]; }
ok(isset($byLedger[$lSalesMomo]) && $byLedger[$lSalesMomo][0] === 'credit' && near($byLedger[$lSalesMomo][1], 2000.0),
    'Momo item override credited its own ledger (2000)');
ok(isset($byLedger[$lSalesFood]) && near($byLedger[$lSalesFood][1], 1000.0), 'Food category ledger credited 1000');
ok(isset($byLedger[$lSalesBev]) && near($byLedger[$lSalesBev][1], 1000.0), 'Beverage category ledger credited 1000');
ok(isset($byLedger[$lVat]) && $byLedger[$lVat][0] === 'credit' && near($byLedger[$lVat][1], 520.0), 'VAT payable credited 520');
ok(isset($byLedger[$lDisc]) && $byLedger[$lDisc][0] === 'debit' && near($byLedger[$lDisc][1], 113.0), 'Discount ledger debited 113');
ok(isset($byLedger[$lRecv]) && $byLedger[$lRecv][0] === 'debit' && near($byLedger[$lRecv][1], 4407.0), 'Receivable debited gross - discount (4407)');

$batch = db()->query("SELECT * FROM hospitality_sales_uploads WHERE id=$uploadId")->fetch();
ok($batch && (int) $batch['row_count'] === 4 && (int) $batch['voucher_count'] === 2 && (string) $batch['status'] === 'posted',
    'Audit batch stores rows, voucher count and status');
$lineCount = (int) db()->query("SELECT COUNT(*) FROM hospitality_sales_upload_lines WHERE upload_id=$uploadId AND voucher_id IS NOT NULL")->fetchColumn();
ok($lineCount === 4, 'All four rows stored and linked to their day voucher');

echo "\n5. Duplicate-date guard\n";
$parsedAgain = hospitality_sales_upload_parse($tmp, 'csv', $cid, $fyId, $settings);
ok($parsedAgain['duplicate_dates'] === ['2026-08-01', '2026-08-02'], 'Re-parsing the same sheet flags both dates as already posted');
$blocked = hospitality_post_sales_upload($cid, $fyId, $parsedAgain, 'aug-day-sheet.csv', $actorId);
ok($blocked['ok'] === false, 'Second post of the same dates is blocked by default');
$forced = hospitality_post_sales_upload($cid, $fyId, $parsedAgain, 'aug-day-sheet.csv', $actorId, true);
ok($forced['ok'] === true, '"Post anyway" override posts the additional sheet');

echo "\n6. Fiscal-year rejection\n";
$csvOut = "Date,Category,Item,Qty,Total Sales Amount\n2025-01-01,Food,Old Sale,1,113\n";
file_put_contents($tmp, $csvOut);
$parsedOut = hospitality_sales_upload_parse($tmp, 'csv', $cid, $fyId, $settings);
ok((int) $parsedOut['valid_count'] === 0 && str_contains((string) $parsedOut['rows'][0]['errors'][0], 'fiscal year'),
    'A date outside the fiscal year is rejected at row level');

@unlink($tmp);
hsu_cleanup();

echo "\n============================================\n";
echo "PASS: $pass   FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);
