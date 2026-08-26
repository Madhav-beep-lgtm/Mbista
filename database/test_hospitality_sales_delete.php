<?php
declare(strict_types=1);

/**
 * Taking a day of uploaded sales back out of the books.
 *
 * The Sales Reports on the upload screen could show a wrong figure and offer
 * nothing to do about it: no voucher number, so no way to reach the entry it
 * produced, and no way to remove it. The linkage was there all along —
 * hospitality_sales_upload_lines.voucher_id — and simply never shown.
 *
 * What is asserted here is the part that has to be right rather than merely
 * present: that a delete takes the WHOLE day (its sales, party and VAT
 * entries, and the uploaded lines behind them), that it refuses everything it
 * does not own, and that it says so instead of half-doing the job.
 *
 *   php database/test_hospitality_sales_delete.php
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/hospitality_sales_posting.php';
require_once __DIR__ . '/../app/hospitality_sales_workbook.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.005; }

function hsd_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'HSDTST'")->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $cid = (int) $cid;
        db()->exec("DELETE ve FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id WHERE v.company_id = $cid");
        db()->exec("DELETE FROM vouchers WHERE company_id = $cid");
        foreach (['hospitality_sales_upload_lines', 'hospitality_sales_invoice_lines',
            'hospitality_sales_uploads', 'fiscal_years'] as $t) {
            if (table_exists($t)) { db()->exec("DELETE FROM `$t` WHERE company_id = $cid"); }
        }
        db()->exec("DELETE FROM ledgers WHERE company_id = $cid");
        db()->exec("DELETE FROM ledger_groups WHERE company_id = $cid");
        db()->exec("DELETE FROM companies WHERE id = $cid");
    }
}
hsd_cleanup();

$userId = (int) db()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
db()->prepare("INSERT INTO companies (name, code, is_active, created_at) VALUES ('Hospitality Delete Test', 'HSDTST', 1, NOW())")->execute();
$cid = (int) db()->lastInsertId();
db()->prepare("INSERT INTO fiscal_years (company_id, label, start_date, end_date, is_default, status)
    VALUES (:c, 'FY Test', '2026-01-01', '2026-12-31', 1, 'open')")->execute(['c' => $cid]);
$fyId = (int) db()->lastInsertId();

$group = static function (string $name, string $master) use ($cid): int {
    db()->prepare('INSERT INTO ledger_groups (company_id, code, name, master_key, is_active) VALUES (:c, :code, :n, :m, 1)')
        ->execute(['c' => $cid, 'code' => coa_next_group_code($cid, $master), 'n' => $name, 'm' => $master]);
    return (int) db()->lastInsertId();
};
$ledger = static function (string $name, int $groupId, string $type) use ($cid): int {
    db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status) VALUES (:c, :g, :code, :n, :t, 'active')")
        ->execute(['c' => $cid, 'g' => $groupId, 'code' => coa_next_ledger_code($cid, $groupId), 'n' => $name, 't' => $type]);
    return (int) db()->lastInsertId();
};
$salesLedger = $ledger('Food Sales', $group('Sales', 'direct_income'), 'revenue');
$vatLedger = $ledger('VAT Payable', $group('Duties & Taxes', 'current_liability'), 'liability');
$cashLedger = $ledger('Cash Counter', $group('Cash', 'current_asset'), 'asset');

// Two days of sales, posted the way an upload posts them: one voucher per
// date, carrying the item lines, the receivable leg and the VAT leg.
db()->prepare("INSERT INTO hospitality_sales_uploads (company_id, fiscal_year_id, file_name, date_from, date_to,
        row_count, invoice_count, voucher_count, gross_amount, discount_amount, vat_amount, taxable_amount, receivable_amount, status)
    VALUES (:c, :f, 'sales.xlsx', '2026-03-01', '2026-03-02', 3, 2, 2, 3000, 0, 390, 3000, 3390, 'posted')")
    ->execute(['c' => $cid, 'f' => $fyId]);
$uploadId = (int) db()->lastInsertId();

$makeDay = static function (string $date, float $taxable, float $vat, int $lines) use ($cid, $fyId, $uploadId, $userId, $salesLedger, $vatLedger, $cashLedger): int {
    $voucherId = create_voucher_with_entries([
        'company_id' => $cid, 'fiscal_year_id' => $fyId,
        'voucher_no' => 'HS-' . str_replace('-', '', $date) . '-TEST',
        'voucher_type' => 'sales', 'source_type' => 'hospitality_sales_upload', 'source_id' => null,
        'voucher_date' => $date, 'narration' => 'Test day ' . $date,
        'total_amount' => $taxable + $vat, 'status' => 'posted', 'approval_state' => 'approved',
        'posted_by' => $userId, 'posted_at' => date('Y-m-d H:i:s'),
    ], [
        ['ledger_id' => $cashLedger, 'entry_type' => 'debit', 'amount' => $taxable + $vat],
        ['ledger_id' => $salesLedger, 'entry_type' => 'credit', 'amount' => $taxable],
        ['ledger_id' => $vatLedger, 'entry_type' => 'credit', 'amount' => $vat],
    ]);
    for ($i = 1; $i <= $lines; $i++) {
        db()->prepare("INSERT INTO hospitality_sales_upload_lines (upload_id, company_id, sale_date, category, item_name,
                qty, gross_amount, discount, vat_amount, taxable_amount, sales_ledger_id, ledger_source, voucher_id)
            VALUES (:up, :c, :d, 'Bakery', :item, 1, :g, 0, :v, :t, :sl, 'map', :vid)")
            ->execute(['up' => $uploadId, 'c' => $cid, 'd' => $date, 'item' => 'Croissant ' . $i,
                'g' => $taxable / $lines, 'v' => $vat / $lines, 't' => $taxable / $lines,
                'sl' => $salesLedger, 'vid' => $voucherId]);
    }
    db()->prepare("INSERT INTO hospitality_sales_invoice_lines (upload_id, company_id, sale_date, invoice_no, payment_type,
            ledger_code, ledger_id, gross_amount, discount, taxable_amount, vat_amount, total_amount, voucher_id)
        VALUES (:up, :c, :d, :inv, 'Cash', 'CASH', :led, :g, 0, :t, :v, :tot, :vid)")
        ->execute(['up' => $uploadId, 'c' => $cid, 'd' => $date, 'inv' => 'INV-' . $date,
            'led' => $cashLedger, 'g' => $taxable, 't' => $taxable, 'v' => $vat, 'tot' => $taxable + $vat, 'vid' => $voucherId]);

    return $voucherId;
};
$day1 = $makeDay('2026-03-01', 2000.0, 260.0, 2);
$day2 = $makeDay('2026-03-02', 1000.0, 130.0, 1);

echo "\n1. Every report says which voucher its figures were posted in\n";
// The linkage existed on the line all along and was never surfaced, so a wrong
// figure on this screen could not be traced to the entry it produced.
foreach (['sheet', 'item', 'date', 'category', 'invoice', 'party'] as $reportKey) {
    $report = hospitality_sales_report($cid, '2026-03-01', '2026-03-31', $reportKey);
    $hasColumn = false;
    foreach ($report['columns'] as [$columnKey]) {
        if ($columnKey === 'voucher_ids') { $hasColumn = true; }
    }
    $everyRowCarriesIds = $report['rows'] !== [];
    foreach ($report['rows'] as $row) {
        if (($row['voucher_id_list'] ?? []) === []) { $everyRowCarriesIds = false; }
    }
    ok($hasColumn && $everyRowCarriesIds, ucfirst($reportKey) . '-wise sales carries its voucher(s)');
}

$dateReport = hospitality_sales_report($cid, '2026-03-01', '2026-03-31', 'date');
$firstDay = $dateReport['rows'][0] ?? [];
ok(count((array) ($firstDay['voucher_id_list'] ?? [])) === 1
    && str_starts_with((string) ($firstDay['voucher_ids'] ?? ''), 'HS-'),
    'A single-voucher row shows the voucher NUMBER, not an id');
$categoryReport = hospitality_sales_report($cid, '2026-03-01', '2026-03-31', 'category');
$bakery = $categoryReport['rows'][0] ?? [];
ok(count((array) ($bakery['voucher_id_list'] ?? [])) === 2 && (string) ($bakery['voucher_ids'] ?? '') === '2 vouchers',
    'A grouped row spanning two days says so — it would delete both');

echo "\n2. Deleting takes the whole day, books and sheet together\n";
$before = [
    'vouchers' => (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id = $cid")->fetchColumn(),
    'entries' => (int) db()->query("SELECT COUNT(*) FROM voucher_entries e INNER JOIN vouchers v ON v.id = e.voucher_id WHERE v.company_id = $cid")->fetchColumn(),
    'lines' => (int) db()->query("SELECT COUNT(*) FROM hospitality_sales_upload_lines WHERE company_id = $cid")->fetchColumn(),
    'invoices' => (int) db()->query("SELECT COUNT(*) FROM hospitality_sales_invoice_lines WHERE company_id = $cid")->fetchColumn(),
];
ok($before['vouchers'] === 2 && $before['entries'] === 6 && $before['lines'] === 3 && $before['invoices'] === 2,
    'Two days are posted: 2 vouchers, 6 entries, 3 item lines, 2 invoice lines');

$deleted = hospitality_sales_delete_vouchers($cid, [$day1], $userId);
ok($deleted['deleted'] === 1, 'One day is deleted');
ok($deleted['lines'] === 2 && $deleted['invoices'] === 1, '  ...with its 2 item lines and 1 invoice line');
ok((int) db()->query("SELECT COUNT(*) FROM voucher_entries WHERE voucher_id = $day1")->fetchColumn() === 0,
    'Its sales, party and VAT entries go with it — no orphaned half-entry');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE id = $day1")->fetchColumn() === 0, 'And the voucher itself is gone');
ok((int) db()->query("SELECT COUNT(*) FROM hospitality_sales_upload_lines WHERE company_id = $cid AND voucher_id = $day1")->fetchColumn() === 0,
    'No uploaded line is left pointing at a voucher that no longer exists');

echo "\n3. The other day is untouched\n";
// A delete that quietly took a neighbouring day with it would be far worse
// than one that refused.
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE id = $day2")->fetchColumn() === 1, 'Day two is still posted');
ok((int) db()->query("SELECT COUNT(*) FROM hospitality_sales_upload_lines WHERE company_id = $cid")->fetchColumn() === 1,
    'And its single item line is still there');
$after = hospitality_sales_report($cid, '2026-03-01', '2026-03-31', 'date');
ok(count($after['rows']) === 1 && near((float) $after['totals']['taxable'], 1000.0),
    'The report now reads one day worth 1,000.00 — the screen and the books agree');

echo "\n4. The batch header is re-added, not left claiming what it lost\n";
$upload = db()->query("SELECT * FROM hospitality_sales_uploads WHERE id = $uploadId")->fetch();
ok($upload !== false && (int) $upload['row_count'] === 1 && (int) $upload['voucher_count'] === 1,
    'The upload now counts 1 row and 1 voucher, not the 3 and 2 it started with');
ok(near((float) $upload['taxable_amount'], 1000.0), '  ...and its value is what is actually left (1,000.00)');

echo "\n5. It refuses everything it does not own\n";
// A hand-written sales voucher that happens to be ticked is not an upload's to
// remove, and neither is another tenant's anything.
$foreign = create_voucher_with_entries([
    'company_id' => $cid, 'fiscal_year_id' => $fyId, 'voucher_no' => 'JV-MANUAL-1',
    'voucher_type' => 'journal', 'source_type' => 'manual', 'source_id' => null,
    'voucher_date' => '2026-03-05', 'narration' => 'Typed by hand', 'total_amount' => 100,
    'status' => 'posted', 'approval_state' => 'approved', 'posted_by' => $userId, 'posted_at' => date('Y-m-d H:i:s'),
], [
    ['ledger_id' => $cashLedger, 'entry_type' => 'debit', 'amount' => 100],
    ['ledger_id' => $salesLedger, 'entry_type' => 'credit', 'amount' => 100],
]);
$refused = hospitality_sales_delete_vouchers($cid, [$foreign, 999000001], $userId);
ok($refused['deleted'] === 0, 'Neither a hand-written voucher nor a stranger id is deleted');
ok(($refused['skipped'][$foreign] ?? '') === 'not a sales-upload voucher',
    '  ...and the hand-written one is named, with the reason');
ok(str_contains((string) ($refused['skipped'][999000001] ?? ''), 'not found'),
    '  ...as is the one that does not exist — a silent no-op would hide it');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE id = $foreign")->fetchColumn() === 1,
    'The hand-written voucher is still there');
ok(hospitality_sales_delete_vouchers($cid, [], $userId)['deleted'] === 0, 'An empty selection deletes nothing');

echo "\n6. A closed period still says no\n";
db()->prepare("UPDATE fiscal_years SET status = 'closed' WHERE id = :id")->execute(['id' => $fyId]);
$locked = hospitality_sales_delete_vouchers($cid, [$day2], $userId);
ok($locked['deleted'] === 0, 'A voucher in a closed year is not deleted');
ok(str_contains(strtolower((string) ($locked['skipped'][$day2] ?? '')), 'closed'),
    '  ...and the reason given is the closed year, not a shrug');
db()->prepare("UPDATE fiscal_years SET status = 'open' WHERE id = :id")->execute(['id' => $fyId]);

echo "\n7. The register cannot delete it behind this screen's back\n";
// Every other module-owned voucher is guarded this way. Without it, deleting
// the entry from the Voucher Register would leave the Sales Reports listing
// sales the ledger no longer has.
$dayVoucher = db()->query("SELECT * FROM vouchers WHERE id = $day2")->fetch();
$blocked = voucher_mutation_blocker((array) $dayVoucher);
ok($blocked !== null && str_contains($blocked, 'Hospitality sales upload'),
    'The Voucher Register refuses it and says which screen owns it');
ok(voucher_mutation_blocker((array) $dayVoucher, ['hospitality_sales_upload']) === null,
    'While this module, which owns it, is let through');

hsd_cleanup();

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
