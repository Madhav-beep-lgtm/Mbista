<?php
declare(strict_types=1);

/**
 * Reports Center → Export Excel, as a real workbook.
 *
 * What used to come out of that menu was a CSV under an Excel label: no
 * heading saying what the file was, no borders, columns too narrow to read,
 * and every figure stored as the TEXT "1,234.00" — so the one thing somebody
 * opens a report to do, select a column of amounts and read its total, did
 * nothing at all.
 *
 * This proves the workbook now carries the same things the printed statement
 * has always carried, and that every report in the registry survives being
 * put through it:
 *
 *   - a letterhead, merged across the table, saying whose books / which
 *     statement / what period / which branch and currency,
 *   - a bold column heading on a tint, in two tiers where the report has
 *     grouped columns, frozen on screen and repeated on every printed page,
 *   - bold for a master or group line, plain for a ledger line, indented to
 *     the level it sits at, and a double rule over the total,
 *   - figures written as NUMBERS with a format on them, and codes left as
 *     text so a leading zero survives.
 *
 *   php database/test_report_workbook.php
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/reports_engine.php';
require_once __DIR__ . '/../app/export_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

/** Style ids that carry a box, read out of the workbook's own styles.xml. */
function rwb_bordered_styles(string $stylesXml): array
{
    preg_match('#<cellXfs[^>]*>(.*)</cellXfs>#s', $stylesXml, $block);
    preg_match_all('/<xf\b[^>]*>|<xf\b[^>]*\/>/', $block[1] ?? '', $list);
    $bordered = [];
    foreach ($list[0] as $index => $xf) {
        if (preg_match('/borderId="[123]"/', $xf) === 1) { $bordered[] = $index; }
    }

    return $bordered;
}

// A trial balance in miniature: grouped columns, a master heading, a group
// subtotal, plain ledger lines, a code with a leading zero, a grand total.
$report = [
    'title' => 'Trial Balance',
    'columns' => [
        ['Code', 'left', ''], ['Particulars', 'left', ''],
        ['Dr.', 'right', 'Opening Balance'], ['Cr.', 'right', 'Opening Balance'],
        ['Dr.', 'right', 'Closing Balance'], ['Cr.', 'right', 'Closing Balance'],
    ],
    'rows' => [
        rc_row(['', '1. EQUITY', '1,000.00', '–', '2,500.50', '–'], 'bold', ['level' => 0, 'label_cell' => 1]),
        rc_row(['', '1.1 Share Capital', '1,000.00', '–', '2,500.50', '–'], 'bold', ['level' => 1, 'label_cell' => 1]),
        rc_row(['0101', 'Ram Laxmi Acharya', '1,000.00', '–', '2,500.50', '–'], '', ['level' => 2, 'label_cell' => 1]),
        rc_row(['0102', 'Pushkar Pandit', '–', '400.00', '–', '900.25'], '', ['level' => 2, 'label_cell' => 1]),
    ],
    'totals' => ['', 'Total', '1,000.00', '400.00', '2,500.50', '900.25'],
];
$meta = [
    'report_label' => 'Trial Balance',
    'company_name' => 'Akshara Jewellery Private Limited (Client Books)',
    'from' => '2026-07-17', 'to' => '2027-07-16',
    'fiscal_label' => 'FY 2083/084', 'branch' => 'Head Office',
    'currency_code' => 'NPR', 'generated_by' => 'Test run',
];

$book = rc_workbook($report, $meta);
$rows = $book['rows'];
$options = $book['options'];

echo "\nA. The file says what it is before it says anything else\n";
// A spreadsheet that opens on a bare grid of figures cannot be filed, checked
// or argued from three months later.
ok((string) $rows[0][0] === 'AKSHARA JEWELLERY PRIVATE LIMITED',
    'Whose books, first, and without the (Client Books) suffix a client should never see');
ok((string) $rows[1][0] === 'Trial Balance', 'Then which statement it is');
ok(str_contains((string) $rows[2][0], 'FY 2083/084') && str_contains((string) $rows[2][0], '2026'),
    'Then the period it covers');
ok(str_contains((string) $rows[3][0], 'Branch : Head Office') && str_contains((string) $rows[3][0], 'NPR'),
    'Then the branch it is scoped to and the currency it is in');
ok($options['row_kinds'][4] === 'blank', 'A blank row closes it, so the table below reads as a table');
ok(in_array('A1:F1', $options['merges'], true),
    'The heading is spread across the whole statement, not left sitting in column A');

echo "\nB. Two tiers of column heading, where the report has them\n";
$headerRow = $options['header_row'];
ok($options['row_kinds'][$headerRow - 1] === 'header' && $options['row_kinds'][$headerRow] === 'header',
    'Both heading rows are headings');
ok((string) $rows[$headerRow - 1][2] === 'Opening Balance' && (string) $rows[$headerRow][2] === 'Dr.',
    'The group band sits over its own Dr./Cr. pair');
ok(in_array('C6:D6', $options['merges'], true), 'The band is merged across the two columns it covers');
ok(in_array('A6:A7', $options['merges'], true),
    'And an ungrouped column is merged DOWN through both rows rather than printed twice');
ok(($options['row_aligns'][$headerRow - 1][2] ?? '') === 'center', 'A spanned band is centred over its columns');

echo "\nC. A figure is a number; a code is not\n";
$firstBody = $headerRow + 1;
ok($options['column_formats'][0] === 'text' && $options['column_formats'][2] === 'money',
    'The Code column stays text while the Dr. column is money');
$ledger = $rows[$firstBody + 2];
ok($ledger[0] === '0101', 'A ledger code keeps its leading zero — written as a number, 0101 opens as 101');
ok(is_float($ledger[2]) && abs($ledger[2] - 1000.0) < 0.001,
    'And the figure beside it is a number Excel can total, not the text "1,000.00"');
ok($ledger[3] === '', 'A dash is left empty — the cell format prints the dash back');

echo "\nD. Bold for a heading, plain for a ledger\n";
ok($options['row_kinds'][$firstBody] === 'bold', 'The master line is bold');
ok($options['row_kinds'][$firstBody + 1] === 'bold', 'So is the group line');
ok($options['row_kinds'][$firstBody + 2] === 'body', 'A ledger line is plain');
ok(($options['row_indents'][$firstBody + 2] ?? [null, null])[1] === 2,
    'And indented two levels, which is the only thing saying it belongs to the group above');
ok(($options['row_indents'][$firstBody] ?? [null, null])[1] === 0, 'While the master line sits flush');
$totalRow = null;
foreach ($options['row_kinds'] as $index => $kind) { if ($kind === 'total') { $totalRow = $index; } }
ok($totalRow !== null && (string) $rows[$totalRow][1] === 'Total', 'The grand total is a total row');
ok($options['auto_filter'] === false,
    'A statement gets no filter arrows — hiding one ledger strands the subtotal that counted it');

echo "\nE. Style ids land where they were laid out\n";
// The worksheet writer knows these by number. If the block is ever reordered
// this is what says so, instead of Excel calling the workbook corrupt.
ok(xlsx_statement_style('company') === 13, 'The organisation line is 13');
ok(xlsx_statement_style('header', 'text', 'right') === 18, 'A right-aligned heading is 18');
ok(xlsx_statement_style('body', 'text', 'left', 2) === 22, 'Body text indented twice is 22');
ok(xlsx_statement_style('body', 'money', 'right') === 26, 'Body money is 26');
ok(xlsx_statement_style('bold', 'money', 'right') === 35, 'Bold money is 35');
ok(xlsx_statement_style('section', 'text', 'left') === 38, 'Section text is 38');
ok(xlsx_statement_style('total', 'money', 'right') === 53, 'Total money is 53');

echo "\nF. The workbook actually opens\n";
if (!class_exists('ZipArchive')) {
    foreach (['package', 'styles', 'merges', 'freeze', 'fit', 'landscape', 'print titles', 'rules', 'boxes', 'title'] as $skipped) {
        ok(true, 'ZipArchive absent — ' . $skipped . ' check skipped');
    }
} else {
    $bytes = xlsx_build($rows, $book['sheet'], $book['widths'], $options);
    $tmp = tempnam(sys_get_temp_dir(), 'rwb');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    $opened = $zip->open($tmp) === true;
    $styles = $opened ? (string) $zip->getFromName('xl/styles.xml') : '';
    $sheet = $opened ? (string) $zip->getFromName('xl/worksheets/sheet1.xml') : '';
    $workbook = $opened ? (string) $zip->getFromName('xl/workbook.xml') : '';
    if ($opened) { $zip->close(); }
    @unlink($tmp);

    $valid = $opened;
    foreach ([$styles, $sheet, $workbook] as $part) {
        $doc = new DOMDocument();
        $valid = $valid && $part !== '' && @$doc->loadXML($part);
    }
    ok($valid, 'Every part of the package is valid XML — an invalid one makes Excel call the whole file corrupt');

    preg_match('#<cellXfs count="(\d+)"#', $styles, $count);
    preg_match('#<cellXfs[^>]*>(.*)</cellXfs>#s', $styles, $block);
    ok((int) ($count[1] ?? 0) === substr_count((string) ($block[1] ?? ''), '<xf '),
        'The declared style count matches the styles actually written — Excel trusts the attribute');

    ok(str_contains($sheet, '<mergeCells count='), 'The merges are written');
    ok(str_contains($sheet, 'state="frozen"'), 'The heading is frozen, so it stays put on a long statement');
    ok(str_contains($sheet, 'fitToWidth="1"'),
        'And fitted to one page wide — otherwise the last columns print on their own sheets of paper');
    ok(str_contains($sheet, 'orientation="landscape"'), 'A six-column statement prints landscape');
    ok(str_contains($workbook, '_xlnm.Print_Titles') && str_contains($workbook, '!$1:$' . ($headerRow + 1)),
        'The heading rows repeat at the top of every printed page');
    ok(str_contains($styles, 'style="double"') && str_contains($styles, 'style="medium"'),
        'A double rule over the total and a rule under the heading');

    $bordered = rwb_bordered_styles($styles);
    $unboxed = [];
    for ($r = $headerRow; $r <= (int) $totalRow; $r++) {
        for ($c = 0; $c < 6; $c++) {
            $ref = xlsx_column_letters($c) . ($r + 1);
            if (preg_match('/<c r="' . $ref . '"[^>]*?s="(\d+)"/', $sheet, $cell) !== 1) {
                $unboxed[] = $ref . ' (missing)';
            } elseif (!in_array((int) $cell[1], $bordered, true)) {
                $unboxed[] = $ref . ' (unbordered)';
            }
        }
    }
    // A blank cell left unstyled leaves a hole in the grid exactly where the
    // eye is trying to follow a line across.
    ok($unboxed === [], 'Every cell from the heading to the total is boxed, blanks included'
        . ($unboxed === [] ? '' : ' — ' . implode(', ', $unboxed)));
    ok(preg_match('/<c r="A1"[^>]*?s="13"/', $sheet) === 1,
        'While the letterhead above it stays unboxed — a title is not a table cell');
}

echo "\nG. A flat register is not a statement\n";
$flat = [
    'title' => 'Sales Register',
    'columns' => [['Sale no.', 'left', ''], ['Customer', 'left', ''], ['Pieces', 'right', ''],
        ['Total', 'right', ''], ['Variance (%)', 'right', '']],
    'rows' => [
        rc_row(['JS-1', 'Ram', '3', '80,000.00', '12.34%']),
        rc_row(['JS-2', 'Sita', '4', '20,000.00', '-4.00%']),
    ],
    'totals' => null,
];
$flatBook = rc_workbook($flat, ['report_label' => 'Sales Register'] + $meta);
ok($flatBook['options']['auto_filter'] === true,
    'Nothing but plain rows, so the filter arrows are useful and stay');
ok($flatBook['options']['column_formats'][2] === 'count', 'A whole-number column is a count');
ok($flatBook['options']['column_formats'][3] === 'money', 'A two-decimal column is money');
ok($flatBook['options']['column_formats'][4] === 'text',
    'A percentage column stays text rather than half-parsing into numbers');
ok($flatBook['options']['print']['landscape'] === false, 'A five-column register prints portrait');

echo "\nH. Every report in the registry survives the trip\n";
// One report with an unexpected cell in it would otherwise reach a user as a
// workbook Excel refuses to open, with nothing said about which one.
$companyId = (int) db()->query('SELECT id FROM companies ORDER BY id ASC LIMIT 1')->fetchColumn();
$fiscalYear = db()->prepare('SELECT * FROM fiscal_years WHERE company_id = :c ORDER BY is_default DESC, id DESC LIMIT 1');
$fiscalYear->execute(['c' => $companyId]);
$fiscalYear = $fiscalYear->fetch() ?: [];
if ($companyId === 0 || $fiscalYear === []) {
    ok(true, 'No company on this database — registry sweep skipped');
} else {
    $from = (string) $fiscalYear['start_date'];
    $to = (string) $fiscalYear['end_date'];
    $companyName = (string) db()->query('SELECT name FROM companies WHERE id = ' . $companyId)->fetchColumn();
    $ctx = [
        'currency' => 'Rs.', 'org_default' => 'trading', 'vtype' => '', 'group_id' => 0,
        'ledger_id' => 0, 'item_id' => 0, 'biz' => 'all', 'company_id' => $companyId,
        'dims' => [], 'fy_start' => $from, 'company_name' => $companyName, 'subsidiaries' => [],
    ];
    $sweepMeta = [
        'company_name' => $companyName, 'from' => $from, 'to' => $to,
        'fiscal_label' => (string) ($fiscalYear['label'] ?? ''), 'branch' => 'Head Office',
        'currency_code' => 'NPR', 'generated_by' => 'Test run',
    ];

    $broken = [];
    $swept = 0;
    foreach (rc_report_registry() as $key => [$label]) {
        try {
            $generated = rc_generate($key, $companyId, $from, $to, $ctx);
            if (($generated['columns'] ?? []) === []) { continue; }
            $swept++;
            $sweptBook = rc_workbook($generated, ['report_label' => $label] + $sweepMeta);
            if (!class_exists('ZipArchive')) { continue; }
            $sweptBytes = xlsx_build($sweptBook['rows'], $sweptBook['sheet'], $sweptBook['widths'], $sweptBook['options']);
            $sweptTmp = tempnam(sys_get_temp_dir(), 'rws');
            file_put_contents($sweptTmp, $sweptBytes);
            $sweptZip = new ZipArchive();
            if ($sweptZip->open($sweptTmp) !== true) {
                $broken[] = $key . ' (not a package)';
            } else {
                for ($i = 0; $i < $sweptZip->numFiles; $i++) {
                    $doc = new DOMDocument();
                    if (!@$doc->loadXML((string) $sweptZip->getFromIndex($i))) {
                        $broken[] = $key . ' (' . $sweptZip->getNameIndex($i) . ' invalid)';
                    }
                }
                $sweptZip->close();
            }
            @unlink($sweptTmp);

            // Two merge ranges over the same cell is corruption, and Excel
            // reports it as "unreadable content" without saying where.
            $claimed = [];
            foreach ((array) $sweptBook['options']['merges'] as $ref) {
                [$start, $end] = explode(':', (string) $ref);
                preg_match('/^([A-Z]+)(\d+)$/', $start, $a);
                preg_match('/^([A-Z]+)(\d+)$/', $end, $b);
                $columnOf = static function (string $letters): int {
                    $n = 0;
                    foreach (str_split($letters) as $ch) { $n = $n * 26 + (ord($ch) - 64); }
                    return $n;
                };
                for ($r = (int) $a[2]; $r <= (int) $b[2]; $r++) {
                    for ($c = $columnOf($a[1]); $c <= $columnOf($b[1]); $c++) {
                        if (isset($claimed[$c . ':' . $r])) { $broken[] = $key . ' (merges overlap at ' . $start . ')'; }
                        $claimed[$c . ':' . $r] = true;
                    }
                }
            }
        } catch (Throwable $e) {
            $broken[] = $key . ' (' . get_class($e) . ': ' . $e->getMessage() . ')';
        }
    }
    ok($swept > 0, "The sweep actually ran — {$swept} reports generated on this database");
    ok($broken === [], 'Every one of them builds a workbook Excel can open'
        . ($broken === [] ? '' : ' — ' . implode('; ', array_unique($broken))));
}

echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail > 0 ? 1 : 0);
