<?php
declare(strict_types=1);

/**
 * What the total on a bill is made of, and the cuts a shop asks to see it in.
 *
 * The sales report showed one figure called Total with no way to see inside
 * it, which is a figure nobody can check against the bill in their hand. It
 * is, and now says it is:
 *
 *     metal + wastage + making + stone and diamond
 *       + other charges - discount        (allocated across the lines)
 *       + Skills Promotion Tax
 *       + VAT
 *     = TOTAL
 *
 * Every one of those is stored per LINE, which is why one grouping can serve
 * all of them -- bill, day, category, purity, item, metal, customer -- and why
 * each must foot to the same money. THAT is what is asserted here: not that
 * the columns exist, but that they add up, that every cut of the same sales
 * comes to the same total, and that the total is the one in the books.
 *
 * Weight and purity are context, not components: they are what the metal was
 * measured in. They stay beside the money rather than inside it.
 *
 *   php database/test_jewellery_sales_report.php
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/jewellery_reports.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.02; }

echo "\n1. Every cut is offered, and named for what it does\n";
$options = jw_sales_group_options();
foreach (['invoice', 'day', 'category', 'purity', 'item', 'metal', 'party'] as $mode) {
    ok(array_key_exists($mode, $options), 'The report can be grouped ' . $mode . '-wise');
}

// A company with posted sales to measure against. The invariants below are
// about arithmetic rather than about any particular shop, so whichever has the
// most sales is the most informative one to run them on.
$companyId = (int) (db()->query("SELECT company_id FROM jewellery_sales WHERE status = 'posted'
    GROUP BY company_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn() ?: 0);
if ($companyId <= 0) {
    echo "\n  (no posted jewellery sales on this database — the arithmetic checks are skipped)\n";
    echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
    exit($fail > 0 ? 1 : 0);
}
$span = db()->prepare("SELECT MIN(sale_date) AS lo, MAX(sale_date) AS hi FROM jewellery_sales
    WHERE company_id = :c AND status = 'posted'");
$span->execute(['c' => $companyId]);
$span = $span->fetch();
[$from, $to] = [(string) $span['lo'], (string) $span['hi']];

echo "\n2. The components add up to the total\n";
// The whole point of bifurcating it. If these do not sum, the report is
// describing a total that is not the one it prints.
$bill = jw_report_sales_bifurcated($companyId, $from, $to, 'invoice');
$t = $bill['totals'];
$sum = $t['metal_amount'] + $t['wastage_amount'] + $t['making_amount'] + $t['stone_side']
    + $t['allocated_adjust'] + $t['tax_amount'] + $t['vat_amount'];
ok(near($sum, $t['line_total']),
    'Metal + Wastage + Making + Stone/diamond + (Charges − Disc.) + SPT + VAT = TOTAL ('
        . number_format($sum, 2) . ' vs ' . number_format($t['line_total'], 2) . ')');

$rowsChecked = 0;
$rowsAgreeing = 0;
foreach ($bill['rows'] as $row) {
    $rowsChecked++;
    $rowSum = $row['metal_amount'] + $row['wastage_amount'] + $row['making_amount'] + $row['stone_side']
        + $row['allocated_adjust'] + $row['tax_amount'] + $row['vat_amount'];
    if (near($rowSum, (float) $row['line_total'])) {
        $rowsAgreeing++;
    }
}
ok($rowsChecked > 0 && $rowsAgreeing === $rowsChecked,
    '  ...and on every individual bill, not only in the foot (' . $rowsAgreeing . '/' . $rowsChecked . ')');

echo "\n3. The total is the one in the books\n";
// A report that foots to itself but not to the ledger is self-consistent and
// wrong, which is the harder kind of wrong to notice.
$posted = (float) db()->query("SELECT COALESCE(SUM(total_amount), 0) FROM jewellery_sales
    WHERE company_id = {$companyId} AND status = 'posted'
      AND sale_date BETWEEN '{$from}' AND '{$to}'")->fetchColumn();
ok(near($posted, $t['line_total']),
    'The report total equals the posted bills it covers (' . number_format($posted, 2) . ')');

echo "\n4. Every cut of the same sales comes to the same money\n";
// Group by day or by purity and the rows are different; the total is not.
$reference = $t['line_total'];
foreach (array_keys(jw_sales_group_options()) as $mode) {
    $cut = jw_report_sales_bifurcated($companyId, $from, $to, $mode);
    ok(near((float) $cut['totals']['line_total'], $reference),
        ucfirst($mode) . '-wise totals ' . number_format((float) $cut['totals']['line_total'], 2)
            . ' across ' . count($cut['rows']) . ' row(s)');
}

echo "\n5. A bill row says who it was billed to, and against what reference\n";
ok($bill['per_bill'] === true, 'Bill-wise knows it is per bill, so those columns are drawn');
$first = $bill['rows'][0] ?? [];
ok(array_key_exists('bill_name', $first) && array_key_exists('ref_no', $first),
    '  ...and carries the customer it was billed to and the invoice reference');
ok((string) ($first['group'] ?? '') !== '', '  ...keyed by the bill number');
$byDay = jw_report_sales_bifurcated($companyId, $from, $to, 'day');
ok($byDay['per_bill'] === false, 'While a day-wise row does not pretend to have a bill number');
ok($byDay['group_label'] === 'Date' && $bill['group_label'] === 'Bill no.',
    'Each cut labels its first column for what it actually holds');

echo "\n6. An unknown grouping falls back rather than failing\n";
// A stale bookmark or a hand-typed URL must not produce an error page.
$junk = jw_report_sales_bifurcated($companyId, $from, $to, 'nonsense');
ok($junk['group_by'] === 'invoice', 'An unrecognised grouping reads as bill-wise');
ok(near((float) $junk['totals']['line_total'], $reference), '  ...and still totals correctly');

echo "\n7. The file says what the screen says\n";
// Built from the same rows, so the two cannot disagree about what was sold.
$exportRows = jw_report_sales_bifurcated_rows($bill);
ok(count($exportRows) === count($bill['rows']) + 2,
    'The export is a heading, every row, and a foot (' . count($exportRows) . ' lines)');
$head = $exportRows[0];
foreach (['Skills Promotion Tax', 'VAT', 'TOTAL', 'Invoice ref.', 'Customer (billed as)'] as $wanted) {
    ok(in_array($wanted, $head, true), '  ...and its heading carries "' . $wanted . '"');
}
$footRow = $exportRows[count($exportRows) - 1];
ok((string) $footRow[0] === 'TOTAL' && near((float) $footRow[array_search('TOTAL', $head, true)], $reference),
    'Its foot carries the same total the screen shows');

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
