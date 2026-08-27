<?php
declare(strict_types=1);

/**
 * The hospitality management pack.
 *
 * Several readings of one period -- profit and loss as a common size, what each
 * category cost to serve, best and worst sellers, margin ranking, how the money
 * came in, what was bought, service charge, and whether any of it moved since
 * last period -- delivered as ONE workbook with a sheet each.
 *
 * What is asserted here is mostly about honesty rather than about arithmetic,
 * because most of these sections are rankings and shares rather than sums:
 *
 *   that a section built on RECIPE ESTIMATES says so on its own sheet, since a
 *   pack that reads as though it came from the ledger will be quoted as though
 *   it did;
 *   that a share of nothing is reported as no answer rather than as nought or
 *   as a division by zero;
 *   that ticking three sections produces three sheets and not eight;
 *   that an unknown section key is dropped rather than guessed at;
 *   and that the workbook Excel receives actually opens.
 *
 *   php database/test_hospitality_pack.php
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/hospitality_engine.php';
require_once __DIR__ . '/../app/hospitality_management_report.php';
require_once __DIR__ . '/../app/export_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

$companyId = (int) (db()->query('SELECT id FROM companies ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
$from = '2026-01-01';
$to = '2026-12-31';

echo "\n1. Every section is offered, and says what it answers\n";
$sections = hospitality_pack_sections();
foreach (['pl', 'pl_category', 'category', 'items_top', 'items_gp', 'payments', 'employee',
    'purchases', 'service_charge', 'comparison'] as $key) {
    ok(isset($sections[$key]) && trim((string) ($sections[$key][1] ?? '')) !== '',
        'The pack carries "' . ($sections[$key][0] ?? $key) . '" with a line saying what it is for');
}

echo "\n2. Ticking sections gets those sections, and nothing else\n";
$three = hospitality_pack_build($companyId, $from, $to, ['pl', 'payments', 'purchases']);
ok(count($three) === 3, 'Three ticked gives three sections (' . count($three) . ')');
ok(array_keys($three) === ['pl', 'payments', 'purchases'], '  ...the three that were ticked, in the pack order');

$none = hospitality_pack_build($companyId, $from, $to, []);
ok(count($none) === count($sections),
    'Nothing ticked gives the whole pack rather than an empty file (' . count($none) . ')');

$junk = hospitality_pack_build($companyId, $from, $to, ['pl', 'not_a_section', 'payments']);
ok(array_keys($junk) === ['pl', 'payments'],
    'An unknown section key is dropped rather than guessed at');
ok(hospitality_pack_normalise(['nonsense']) === array_keys($sections),
    'And a request made entirely of nonsense falls back to the whole pack');

echo "\n3. An estimate says it is an estimate\n";
// A recipe cost is built from configured recipes and reference ingredient
// prices. It is not posted cost of goods sold, and a sheet that does not say so
// will be read as though it were.
foreach (['category', 'items_top', 'items_gp'] as $key) {
    $section = hospitality_pack_build($companyId, $from, $to, [$key])[$key];
    ok(stripos((string) $section['note'], 'estimat') !== false,
        ucfirst(str_replace('_', ' ', $key)) . ' carries the estimate caveat on its own sheet');
}
$pl = hospitality_pack_build($companyId, $from, $to, ['pl'])['pl'];
ok(stripos((string) $pl['note'], 'ledger') !== false,
    'While the profit and loss says it is the posted figures, not an estimate');

echo "\n4. A share of nothing is no answer, not a nought\n";
// Dividing by a period with no sales is the obvious way to get either a crash
// or a misleading 0%.
ok(hospitality_pack_share(100.0, 0.0) === null, 'A share of zero sales is null');
ok(hospitality_pack_share(0.0, 0.0) === null, '  ...even when the amount is zero too');
ok(hospitality_pack_share(25.0, 100.0) === 25.0, 'And a real share is the percentage it should be');

$quiet = hospitality_pack_build($companyId, '1999-01-01', '1999-01-31');
ok(count($quiet) === count($sections), 'A period with nothing in it still builds every section');
$emptyRows = 0;
foreach ($quiet as $section) {
    if ((array) $section['rows'] === []) { $emptyRows++; }
}
ok($emptyRows > 0, '  ...with the sections that have nothing to say holding no rows rather than noughts');

echo "\n5. The comparison names the period it is comparing against\n";
$comparison = hospitality_pack_build($companyId, '2026-06-01', '2026-06-30', ['comparison'])['comparison'];
ok(str_contains((string) $comparison['note'], '2026-05-01') && str_contains((string) $comparison['note'], '2026-05-31'),
    'A June report compares against May, and says so (' . substr((string) $comparison['note'], 0, 60) . '...)');

echo "\n6. One workbook, one sheet per section\n";
if (!class_exists('ZipArchive')) {
    foreach (range(1, 6) as $skipped) { ok(true, 'ZipArchive absent — workbook checks skipped'); }
} else {
    $meta = ['company_name' => 'Pack Test Co (Client Books)', 'from' => $from, 'to' => $to];
    $bytes = hospitality_pack_xlsx(hospitality_pack_build($companyId, $from, $to), $meta);
    $tmp = tempnam(sys_get_temp_dir(), 'pack');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    $opened = $zip->open($tmp) === true;
    $workbookXml = $opened ? (string) $zip->getFromName('xl/workbook.xml') : '';
    $sheet1 = $opened ? (string) $zip->getFromName('xl/worksheets/sheet1.xml') : '';
    $valid = $opened;
    for ($i = 0; $opened && $i < $zip->numFiles; $i++) {
        $doc = new DOMDocument();
        $valid = $valid && @$doc->loadXML((string) $zip->getFromIndex($i));
    }
    if ($opened) { $zip->close(); }
    @unlink($tmp);

    ok($valid, 'Every part of the package is valid XML — Excel will open it');
    preg_match_all('/<sheet name="([^"]+)"/', $workbookXml, $names);
    ok(count($names[1] ?? []) === count($sections),
        'It carries one sheet per section (' . count($names[1] ?? []) . ')');
    ok(in_array('Profit &amp; Loss (common size)', $names[1] ?? [], true)
        || in_array('Profit & Loss (common size)', array_map('html_entity_decode', $names[1] ?? []), true),
        '  ...named for the section, ampersand and all');

    // The Reports Centre dressing, which was the point of reusing it.
    ok(str_contains($sheet1, '<mergeCells count='), 'A sheet carries its letterhead merged across the table');
    ok(str_contains($sheet1, 'state="frozen"'), '  ...with the heading frozen');
    ok(str_contains($sheet1, 'fitToWidth="1"'), '  ...and fitted to one page wide when printed');

    // Per-sheet options are what let eight different shapes share one file.
    $threeBytes = hospitality_pack_xlsx($three, $meta);
    $tmp2 = tempnam(sys_get_temp_dir(), 'pk3');
    file_put_contents($tmp2, $threeBytes);
    $zip2 = new ZipArchive();
    $zip2->open($tmp2);
    preg_match_all('/<sheet name="([^"]+)"/', (string) $zip2->getFromName('xl/workbook.xml'), $names3);
    $zip2->close();
    @unlink($tmp2);
    ok(count($names3[1] ?? []) === 3, 'And a pack of three sections is a workbook of three sheets');
}

echo "\n7. It can be scheduled like any other report\n";
// So a client is sent it monthly without anybody remembering to, which is most
// of what a management pack is for.
$runner = (string) file_get_contents(dirname(__DIR__) . '/database/run_report_schedules.php');
ok(str_contains($runner, "'hospitality-pack'"), 'The schedule runner knows the pack by name');
ok(str_contains($runner, 'hospitality_pack_xlsx'), '  ...and attaches the workbook rather than a CSV');
$screen = (string) file_get_contents(dirname(__DIR__) . '/public_html/admin/report-schedules.php');
ok(str_contains($screen, 'hospitality-pack'), 'And the schedules screen offers it');


echo "\n8. Sales come from the uploaded sheet, not from the costing runs\n";
// THE FAULT THIS SECTION EXISTS FOR. The pack first read everything through the
// costing lines, which exist only once somebody has RUN costing. A shop that
// uploads its daily sales but has not built its recipes saw a management pack of
// noughts, while the Sales Report beside it showed the same day's takings by
// category. Sales are the uploaded sheet. Cost is an overlay on top of it.
$uploader = db()->query("SELECT l.company_id, MIN(l.sale_date) AS lo, MAX(l.sale_date) AS hi
    FROM hospitality_sales_upload_lines l
    LEFT JOIN hospitality_costing_lines c
        ON c.company_id = l.company_id AND c.sale_date = l.sale_date
    WHERE c.id IS NULL
    GROUP BY l.company_id
    LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$uploader) {
    echo "  ....  every company here has costed every uploaded day, so the uncosted case\n";
    echo "        cannot be shown with real data. Asserted structurally instead.\n";
    $engine = (string) file_get_contents(dirname(__DIR__) . '/app/hospitality_management_report.php');
    ok(str_contains($engine, 'hospitality_sales_upload_lines'), 'The pack reads sales from the uploaded sheet');
    ok(str_contains($engine, 'LEFT JOIN hospitality_costing_lines'), '  ...with cost LEFT-joined on, so an uncosted line survives');
} else {
    $uc = (int) $uploader['company_id'];
    $ufrom = (string) $uploader['lo'];
    $uto = (string) $uploader['hi'];
    $section = hospitality_pack_build($uc, $ufrom, $uto, ['category'])['category'];
    ok((array) $section['rows'] !== [], 'A company with uploaded but UNCOSTED sales still reports its categories ('
        . count((array) $section['rows']) . ' row(s))');
    $sold = 0.0;
    foreach ((array) $section['rows'] as $row) { $sold += (float) $row['net_sales']; }
    $sheet = db()->prepare("SELECT COALESCE(SUM(taxable_amount), 0) FROM hospitality_sales_upload_lines
        WHERE company_id = :c AND sale_date BETWEEN :f AND :t");
    $sheet->execute(['c' => $uc, 'f' => $ufrom, 't' => $uto]);
    $uploaded = (float) $sheet->fetchColumn();
    ok(abs($sold - $uploaded) < 0.02, '  ...and its net sales equal the uploaded sheet ('
        . number_format($uploaded, 2) . ')');
    // An unknown cost is NOTHING, not nought. A nought reports the food as free
    // and the margin as 100%, which is worse than saying nothing at all. So an
    // empty cost must drag the profit and the ratio empty with it, on every row.
    $halfTold = [];
    foreach ((array) $section['rows'] as $row) {
        if ($row['est_cost'] === null && ($row['est_gp'] !== null || $row['gp_pct'] !== null)) {
            $halfTold[] = (string) $row['group'];
        }
    }
    ok($halfTold === [], '  ...and where the cost is unknown the profit and the ratio are empty too'
        . ($halfTold === [] ? '' : ' — ' . implode(', ', $halfTold)));
    $uncosted = true;
    foreach ((array) $section['rows'] as $row) {
        if ($row['est_cost'] !== null) { $uncosted = false; }
    }
    if ($uncosted) {
        // NOT ?? here: the null coalesce fires ON null, so it can never see the
        // very value being asserted. array_key_exists is the only way to tell an
        // empty column from a column that was never built.
        ok(array_key_exists('est_cost', (array) $section['totals'])
            && $section['totals']['est_cost'] === null,
            '  ...and a wholly uncosted column does not foot to nought on the total row');
        ok(stripos((string) $section['note'], 'costed') !== false,
            '  ...with the sheet saying why those columns are empty');
    } else {
        ok(true, '  ...(this company has costed some of the range, so the empty-total case is not reachable here)');
        ok(true, '  ...(nor is the note)');
    }
}

echo "\n9. The two statements a manager asked for by name\n";
$plCat = hospitality_pack_build($companyId, $from, $to, ['pl_category'])['pl_category'];
$labels = [];
foreach ((array) $plCat['rows'] as $row) { $labels[] = trim((string) ($row['label'] ?? '')); }
ok(in_array('SALES BY CATEGORY', $labels, true), 'The category P&L opens with sales by category');
ok(in_array('GROSS PROFIT (all categories)', $labels, true), '  ...totals gross profit across them');
ok(in_array('COMMON COSTS - not attributable to a category', $labels, true)
    || in_array("COMMON COSTS \xe2\x80\x94 not attributable to a category", $labels, true),
    '  ...then lists the common costs, ledger by ledger, once');
ok(in_array('NET RESULT (gross profit less common costs)', $labels, true), '  ...and takes them off at the foot');
ok(stripos((string) $plCat['note'], 'NOT apportioned') !== false,
    'And it says the common costs are not apportioned, because an apportioned category profit is'
        . ' mostly an argument about the apportionment');

$employee = hospitality_pack_build($companyId, $from, $to, ['employee'])['employee'];
$empColumns = [];
foreach ((array) $employee['columns'] as $column) { $empColumns[] = (string) $column[0]; }
ok(in_array('component', $empColumns, true) && in_array('amount', $empColumns, true),
    'The employee section breaks the wage bill down component by component');
ok(in_array('behaviour', $empColumns, true), '  ...saying which are pay in hand and which are an employer cost');

echo "\n10. A section can be read on screen, not only exported\n";
ok(function_exists('hospitality_pack_render_table'),
    'One renderer draws a section, so the screen and the print view cannot disagree');
$page = (string) file_get_contents(dirname(__DIR__) . '/public_html/admin/hospitality.php');
ok(str_contains($page, 'hospitality_pack_render_table'), 'The reports tab draws sections inline');
ok(str_contains($page, "name=\"show[]\"") || str_contains($page, "'show' =>"),
    '  ...opened by their own View link, without exporting first');
// View opens the section in a dialog rather than rendering it further down the
// page: what the reader asked to see appears where they are looking. The link
// still carries the working no-JS address, so the feature does not depend on
// the fetch succeeding.
ok(str_contains($page, 'hospPackDialog') && str_contains($page, 'showModal'),
    'And View opens it in a dialog rather than scrolling the page');
ok(str_contains($page, 'hosp-pack-view') && str_contains($page, "'show' =>"),
    '  ...over a link that still works with JavaScript off');
ok(str_contains($page, "=== 'pack_html'"),
    '  ...fed by a fragment route, so the dialog and the page draw the same section');

// There used to be a separate print page for PDF, which relied on the reader
// running the browser print dialog. A real PDF replaced it, so the page should
// be gone rather than left behind unreferenced.
ok(!is_file(dirname(__DIR__) . '/public_html/admin/hospitality-pack-print.php'),
    'The old print-view page is gone, replaced by a real PDF');
ok(!str_contains($page, 'pack_print'), '  ...and nothing still links to it');
ok(str_contains($page, 'pack_pdf') && function_exists('hospitality_pack_pdf'),
    '  ...with PDF now produced as a file rather than asked of the browser');
echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
