<?php
declare(strict_types=1);

/**
 * What the total on a bill is made of, and the cuts a shop asks to see it in.
 *
 * The sales report showed one figure called Total with no way to see inside
 * it, which is a figure nobody can check against the bill in their hand. It
 * is, and now says it is:
 *
 *     metal + making + stone and diamond
 *       + other charges - discount        (allocated across the lines)
 *     = NET BEFORE SPT AND VAT -- the figure that reaches the profit and loss
 *       + Skills Promotion Tax
 *       + VAT
 *     = TOTAL -- what the customer hands over
 *
 * Wastage is not a term of that sum: the metal is priced on a weight that
 * already includes the wastage weight, so its value is inside the metal
 * amount. It is reported as a memo beside metal, never added.
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
//
// WASTAGE IS NOT ONE OF THE TERMS. The metal is priced on a weight that
// already includes the wastage weight, so its value sits inside the metal
// amount; a first cut of this report added the wastage column as well and
// double-counted it. That went unnoticed because the company it was measured
// on charges no wastage anywhere -- which is exactly why section 8 below
// refuses to let this suite look conclusive on such a company.
$bill = jw_report_sales_bifurcated($companyId, $from, $to, 'invoice');
$t = $bill['totals'];
$net = $t['metal_amount'] + $t['making_amount'] + $t['stone_side'] + $t['allocated_adjust'];
ok(near($net, $t['net_before_tax']),
    'Metal + Making + Stone/diamond + (Charges - Disc.) = Net before SPT / VAT ('
        . number_format($net, 2) . ')');
ok(near($net + $t['tax_amount'] + $t['vat_amount'], $t['line_total']),
    '  ...and Net + SPT + VAT = TOTAL (' . number_format($t['line_total'], 2) . ')');

$rowsChecked = 0;
$rowsAgreeing = 0;
foreach ($bill['rows'] as $row) {
    $rowsChecked++;
    $rowNet = $row['metal_amount'] + $row['making_amount'] + $row['stone_side'] + $row['allocated_adjust'];
    if (near($rowNet, (float) $row['net_before_tax'])
        && near($rowNet + (float) $row['tax_amount'] + (float) $row['vat_amount'], (float) $row['line_total'])) {
        $rowsAgreeing++;
    }
}
ok($rowsChecked > 0 && $rowsAgreeing === $rowsChecked,
    '  ...on every individual bill, not only in the foot (' . $rowsAgreeing . '/' . $rowsChecked . ')');

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

echo "\n8. And the same arithmetic where wastage is actually charged\n";
// The double-count above could not show itself on a shop that charges no
// wastage, so it is looked for wherever one does -- and where none does
// anywhere on this database, that is SAID rather than passed over in silence.
$wastageSale = db()->query("SELECT s.company_id, s.sale_date
    FROM jewellery_sales s
    INNER JOIN jewellery_sale_lines l ON l.sale_id = s.id
    WHERE s.status = 'posted' AND l.wastage_amount > 0
    ORDER BY s.id DESC LIMIT 1")->fetch();
if (!$wastageSale) {
    echo "  ....  no posted sale on this database charges wastage, so the\n";
    echo "        double-count this section exists to catch cannot be\n";
    echo "        demonstrated here. The arithmetic is asserted structurally\n";
    echo "        instead: wastage must not be one of the additive terms.\n";
    $engine = (string) file_get_contents(dirname(__DIR__) . '/app/jewellery_reports.php');
    ok(preg_match('/\$additive\s*=\s*\[[^\]]*\]/', $engine, $m) === 1, 'The engine names its additive terms explicitly');
    ok(isset($m[0]) && !str_contains($m[0], 'wastage_amount'),
        '  ...and wastage is NOT among them');
} else {
    $wc = (int) $wastageSale['company_id'];
    $wd = (string) $wastageSale['sale_date'];
    $wr = jw_report_sales_bifurcated($wc, $wd, $wd, 'invoice');
    $wt = $wr['totals'];
    ok((float) $wt['wastage_amount'] > 0, 'Found a posted sale that charges wastage, on ' . $wd);
    $wnet = $wt['metal_amount'] + $wt['making_amount'] + $wt['stone_side'] + $wt['allocated_adjust'];
    ok(near($wnet + $wt['tax_amount'] + $wt['vat_amount'], $wt['line_total']),
        '  ...and it STILL adds up, because wastage is inside the metal and not added again');
    $booked = (float) db()->query("SELECT COALESCE(SUM(total_amount), 0) FROM jewellery_sales
        WHERE company_id = {$wc} AND status = 'posted' AND sale_date = '{$wd}'")->fetchColumn();
    ok(near($booked, (float) $wt['line_total']),
        '  ...and matches the bills for that day (' . number_format($booked, 2) . ')');
}

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
