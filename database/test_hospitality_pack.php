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
foreach (['pl', 'category', 'items_top', 'items_gp', 'payments', 'purchases', 'service_charge', 'comparison'] as $key) {
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

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
