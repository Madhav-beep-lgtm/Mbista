<?php
declare(strict_types=1);

/**
 * The PDF writer.
 *
 * A PDF fails differently from the rest of this codebase. Nothing throws: the
 * file is written, the download works, and the reader says "damaged" — or worse,
 * opens it and shows a figure in the wrong column. So these assertions go at the
 * bytes and at the geometry rather than at a return value.
 *
 * Where a real parser is available (pdftotext, from poppler) the text is read
 * back out of the finished file and compared with what went in. Where it is not,
 * the drawing operators are parsed instead, which is weaker but still catches a
 * figure that never made it onto the page.
 *
 *   php database/test_pdf_engine.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require_once $root . '/app/pdf_engine.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

/** Every content stream in a PDF, in page order. */
function pdf_streams(string $bytes): array
{
    preg_match_all('/<< \/Length \d+ >>\nstream\n(.*?)endstream/s', $bytes, $m, PREG_SET_ORDER);

    return array_map(static fn (array $set): string => $set[1], $m);
}

/** Every text-drawing operator on one page: [x, y, text]. */
function pdf_text_ops(string $stream): array
{
    preg_match_all('/([\d.-]+) ([\d.-]+) Td \((.*?)\) Tj/', $stream, $m, PREG_SET_ORDER);

    return array_map(static fn (array $set): array => [(float) $set[1], (float) $set[2], $set[3]], $m);
}

$sample = [
    'title' => 'Category performance',
    'note' => 'A note that travels with the sheet.',
    'columns' => [
        ['group', 'Category', 'left'],
        ['qty', 'Qty', 'right'],
        ['net_sales', 'Net sales', 'right'],
        ['gp_pct', 'GP %', 'right'],
    ],
    'rows' => [
        ['group' => 'Food', 'qty' => 10.0, 'net_sales' => 2500.0, 'gp_pct' => 92.0, 'emphasis' => ''],
        ['group' => '   Curry', 'qty' => 4.0, 'net_sales' => 900.0, 'gp_pct' => null, 'emphasis' => ''],
    ],
    'totals' => ['qty' => 14.0, 'net_sales' => 3400.0, 'gp_pct' => null],
];

echo "1. The file is a PDF, and every offset in it is true\n";
// The cross-reference table is a list of byte positions. A reader seeks by them,
// so one wrong number is a file that will not open — and nothing in PHP notices.
$bytes = pdf_document([$sample], ['company_name' => 'Test Co', 'period' => '2026', 'generated' => 'CI']);
ok(str_starts_with($bytes, '%PDF-'), 'It starts with the PDF header');
ok(str_contains($bytes, '%%EOF'), 'And ends with the end marker');

preg_match('/startxref\s+(\d+)/', $bytes, $start);
ok(isset($start[1]) && substr($bytes, (int) $start[1], 4) === 'xref',
    'startxref points at the cross-reference table itself');

preg_match('/\nxref\n0 (\d+)\n(.*?)trailer/s', $bytes, $table);
$entries = explode("\n", rtrim($table[2] ?? '', "\n"));
$wrong = [];
foreach ($entries as $index => $entry) {
    if ($index === 0) {
        continue;   // the free head entry
    }
    $offset = (int) substr($entry, 0, 10);
    $expect = $index . ' 0 obj';
    if (substr($bytes, $offset, strlen($expect)) !== $expect) {
        $wrong[] = $index;
    }
}
ok((int) ($table[1] ?? 0) === count($entries), 'The table declares as many entries as it lists');
ok($wrong === [], 'Every offset lands exactly on the object it claims'
    . ($wrong === [] ? '' : ' — wrong for object(s) ' . implode(', ', $wrong)));

$streams = pdf_streams($bytes);
$lengths = [];
preg_match_all('/<< \/Length (\d+) >>\nstream\n(.*?)endstream/s', $bytes, $pairs, PREG_SET_ORDER);
foreach ($pairs as $pair) {
    if ((int) $pair[1] !== strlen($pair[2])) {
        $lengths[] = (int) $pair[1];
    }
}
ok($lengths === [], 'Every stream declares its true length'
    . ($lengths === [] ? '' : ' — wrong: ' . implode(', ', $lengths)));

preg_match('/\/Count (\d+)/', $bytes, $count);
ok((int) ($count[1] ?? 0) === substr_count($bytes, '/Type /Page '),
    'The page tree counts the pages that exist');

echo "\n2. What went in comes out\n";
// Asking the tool to identify itself, rather than asking the shell where it is:
// shell_exec on Windows runs through cmd, where "command -v" means nothing and
// a present pdftotext would be reported as missing.
$probe = (string) @shell_exec('pdftotext -v 2>&1');
$tool = stripos($probe, 'pdftotext') !== false ? 'pdftotext' : '';
if ($tool === '') {
    echo "  ....  pdftotext is not on this machine, so the file is read through its own\n";
    echo "        drawing operators instead. Install poppler for the stronger check.\n";
    $text = '';
    foreach ($streams as $stream) {
        foreach (pdf_text_ops($stream) as [, , $drawn]) {
            $text .= $drawn . ' ';
        }
    }
} else {
    $tmp = sys_get_temp_dir() . '/mbw_pdf_' . getmypid() . '.pdf';
    file_put_contents($tmp, $bytes);
    $text = (string) shell_exec('pdftotext ' . escapeshellarg($tmp) . ' - 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'));
    @unlink($tmp);
    ok(trim($text) !== '', 'A real PDF reader (pdftotext) opens the file and finds text in it');
}
ok(str_contains($text, 'TEST CO'), 'The company is on the page');
ok(str_contains($text, 'Category performance'), 'So is the section title');
ok(str_contains($text, 'A note that travels with the sheet.'), 'And the note, which is the caveat nobody may drop');
ok(str_contains($text, '2,500.00'), 'A figure survives with its thousands separator');
ok(str_contains($text, '92.00%'), 'A ratio keeps its per-cent sign');
ok(str_contains($text, 'TOTAL'), 'The totals row is there');

echo "\n3. An unknown is a dash, not a nought\n";
// The whole reason the pack has null-vs-zero discipline. If the PDF printed an
// empty cost as 0.00 it would report the food as free and the margin as 100%.
ok(!preg_match('/Curry[^\n]*0\.00%/', $text), 'An uncosted line does NOT print a nought per cent');
$dashes = substr_count($text, "\u{2014}") + substr_count($text, '--') + substr_count($text, "\x97");
ok($dashes > 0, 'It prints a dash instead');
ok(pdf_default_cell(null, 'gp_pct') === "\xe2\x80\x94", 'A null formats as an em dash');
ok(pdf_default_cell(0.0, 'gp_pct') === '0.00%', 'A real nought still formats as a nought');
ok(pdf_default_cell(0.0, 'net_sales') === '0.00', '  ...in a money column too');

echo "\n4. Nothing is drawn off the paper\n";
$outside = 0;
$total = 0;
preg_match_all('/\/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]/', $bytes, $boxes, PREG_SET_ORDER);
foreach ($streams as $index => $stream) {
    $width = (float) ($boxes[$index][1] ?? 595);
    $height = (float) ($boxes[$index][2] ?? 842);
    foreach (pdf_text_ops($stream) as [$x, $y, $drawn]) {
        $total++;
        if ($x < 0 || $y < 0 || $x > $width || $y > $height) {
            $outside++;
        }
        // Right-aligned text is placed by subtracting its own width, so a wrong
        // metric shows up as text running off the right-hand edge.
        if ($x + pdf_text_width($drawn, 9) > $width + 1) {
            $outside++;
        }
    }
}
ok($total > 0, 'The page carries text (' . $total . ' draw operations)');
ok($outside === 0, 'None of it falls outside the sheet' . ($outside === 0 ? '' : " — {$outside} did"));

echo "\n5. Columns are sized from the content, and headings are not cut\n";
// A column measured for exactly its heading once wrapped "Discount" to "Discoun"
// and "t", because the two widths were computed by different sums and compared
// for equality. Wrapping is fine; wrapping mid-word in a heading is not.
$wide = [
    'title' => 'Wide',
    'note' => '',
    'columns' => [],
    'rows' => [[]],
    'totals' => [],
];
foreach (['Category', 'Lines', 'Qty', 'Gross sales', 'Discount', 'Net sales', '% of sales', 'VAT',
    'Est. recipe cost', 'Est. gross profit', 'GP %'] as $index => $label) {
    $wide['columns'][] = ['c' . $index, $label, $index === 0 ? 'left' : 'right'];
    $wide['rows'][0]['c' . $index] = $index === 0 ? 'Alternatives' : 1234.56;
}
$wide['rows'][0]['emphasis'] = '';
$wideBytes = pdf_document([$wide], ['company_name' => 'Test Co', 'period' => '2026']);
$drawnWords = [];
foreach (pdf_streams($wideBytes) as $stream) {
    foreach (pdf_text_ops($stream) as [, , $drawn]) {
        $drawnWords[] = $drawn;
    }
}
$cut = [];
foreach (['Category', 'Discount', 'VAT', 'Lines'] as $heading) {
    if (!in_array($heading, $drawnWords, true)) {
        $cut[] = $heading;
    }
}
ok($cut === [], 'An eleven-column table still draws each heading whole'
    . ($cut === [] ? '' : ' — broken: ' . implode(', ', $cut)));
preg_match('/\/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]/', $wideBytes, $wideBox);
ok((float) $wideBox[1] > (float) $wideBox[2], '  ...on a landscape page, because eleven columns need one');

echo "\n6. An indent survives into the PDF\n";
// A statement says what a line IS by how far it is indented. Wrapping trims
// whitespace, so without deliberate handling every line ends up flush left and
// a category reads like a heading.
$indented = [];
foreach (pdf_streams($bytes) as $stream) {
    foreach (pdf_text_ops($stream) as [$x, , $drawn]) {
        $indented[$drawn] = $x;
    }
}
ok(isset($indented['Food'], $indented['Curry']), 'Both the heading line and the line under it are drawn');
ok(($indented['Curry'] ?? 0) > ($indented['Food'] ?? 0),
    '  ...with the indented one further in (' . round($indented['Curry'] ?? 0, 1)
        . ' vs ' . round($indented['Food'] ?? 0, 1) . ')');

echo "\n7. Text the standard fonts cannot draw is folded, not mangled\n";
ok(pdf_winansi('plain ASCII') === 'plain ASCII', 'ASCII passes through untouched');
ok(pdf_winansi("caf\xc3\xa9") === "caf\xe9", 'A Latin-1 accent becomes its single byte');
ok(pdf_winansi("a \xe2\x80\x94 b") === "a \x97 b", 'An em dash stays an em dash (WinAnsi has one)');
ok(pdf_winansi("\xe0\xa4\xa8") === '?', 'Devanagari, which no standard font holds, becomes a visible replacement');
ok(!str_contains(pdf_escape('a (b) c'), '(b)') || str_contains(pdf_escape('a (b) c'), '\\('),
    'Brackets are escaped, since they delimit a PDF string');

echo "\n8. Edge cases that would otherwise reach a client\n";
$empty = pdf_document([], []);
ok(str_starts_with($empty, '%PDF-') && str_contains($empty, '%%EOF'),
    'A pack of no sections is still a valid file, not a zero-byte download');
$noRows = pdf_document([['title' => 'Empty', 'note' => '', 'columns' => [['a', 'A', 'left']],
    'rows' => [], 'totals' => []]], []);
$emptyText = '';
foreach (pdf_streams($noRows) as $stream) {
    foreach (pdf_text_ops($stream) as [, , $drawn]) {
        $emptyText .= $drawn . ' ';
    }
}
ok(str_contains($emptyText, 'Nothing recorded for this period.'),
    'A section with no rows says so, rather than printing an empty grid');

// A section long enough to break over pages must repeat its heading, or page
// two is a wall of figures with nothing saying what the columns are.
$long = ['title' => 'Long', 'note' => '', 'columns' => [['a', 'Particulars', 'left'], ['b', 'Amount', 'right']],
    'rows' => [], 'totals' => []];
for ($i = 0; $i < 120; $i++) {
    $long['rows'][] = ['a' => 'Row number ' . $i, 'b' => 100.0 + $i, 'emphasis' => ''];
}
$longBytes = pdf_document([$long], ['company_name' => 'Test Co']);
$pageCount = substr_count($longBytes, '/Type /Page ');
$headings = 0;
foreach (pdf_streams($longBytes) as $stream) {
    foreach (pdf_text_ops($stream) as [, , $drawn]) {
        if ($drawn === 'Particulars') {
            $headings++;
        }
    }
}
ok($pageCount > 1, 'A hundred and twenty rows run to more than one page (' . $pageCount . ')');
ok($headings === $pageCount, '  ...and the column heading repeats on every one of them');
// Counted off the drawing operators, not the raw bytes: a bracket is escaped
// inside a PDF string, so the file says "Long \(continued\)".
$continued = 0;
foreach (pdf_streams($longBytes) as $stream) {
    foreach (pdf_text_ops($stream) as [, , $drawn]) {
        if (str_contains($drawn, 'continued')) {
            $continued++;
        }
    }
}
ok($continued === $pageCount - 1, '  ...with the later pages marked as continuations ('
    . $continued . ' of ' . $pageCount . ')');

echo "\n9. The pack builds through it, with its own formatter\n";
require_once $root . '/app/hospitality_management_report.php';
ok(function_exists('hospitality_pack_pdf'), 'The pack has a PDF builder');
ok(function_exists('hospitality_pack_cell_text'), 'And one formatter the screen and both files share');
$company = (int) db()->query("SELECT company_id FROM hospitality_sales_upload_lines
    GROUP BY company_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
if ($company > 0) {
    $range = db()->prepare('SELECT MIN(sale_date) lo, MAX(sale_date) hi FROM hospitality_sales_upload_lines WHERE company_id = ?');
    $range->execute([$company]);
    $period = $range->fetch(PDO::FETCH_ASSOC);
    $pack = hospitality_pack_build($company, (string) $period['lo'], (string) $period['hi'], []);
    $packBytes = hospitality_pack_pdf($pack, ['company_name' => 'Test', 'from' => $period['lo'], 'to' => $period['hi']]);
    ok(str_starts_with($packBytes, '%PDF-'), 'A real management pack renders (' . count($pack) . ' sections, '
        . number_format(strlen($packBytes)) . ' bytes)');
    ok(substr_count($packBytes, '/Type /Page ') >= count($pack),
        '  ...over at least one page per section');
    // The same figure, formatted the same way, in both containers.
    $sales = 0.0;
    foreach ((array) ($pack['category']['rows'] ?? []) as $row) {
        $sales += (float) $row['net_sales'];
    }
    if ($sales > 0) {
        $printed = number_format($sales, 2);
        $found = false;
        foreach (pdf_streams($packBytes) as $stream) {
            foreach (pdf_text_ops($stream) as [, , $drawn]) {
                if ($drawn === $printed) {
                    $found = true;
                }
            }
        }
        ok($found, '  ...and the period total is printed exactly as the screen formats it (' . $printed . ')');
    } else {
        ok(true, '  ...(this company sold nothing in its own range, so there is no total to match)');
    }
} else {
    echo "  ....  no company on this database has uploaded sales, so the pack cannot be\n";
    echo "        rendered from real data here.\n";
    ok(true, 'Skipped: no uploaded sales on this database');
    ok(true, 'Skipped');
    ok(true, 'Skipped');
}

echo "\n10. The schedule can deliver one\n";
$runner = (string) file_get_contents($root . '/database/run_report_schedules.php');
ok(str_contains($runner, "'pdf'"), 'The runner knows the PDF format');
ok(str_contains($runner, 'hospitality_pack_pdf'), '  ...and sends the pack as one when asked');
ok(str_contains($runner, 'rc_pdf_section'), '  ...and an ordinary report too');
$screen = (string) file_get_contents($root . '/public_html/admin/report-schedules.php');
ok(str_contains($screen, 'value="pdf"'), 'The schedules screen offers it');
ok(!str_contains($screen, 'value="html"') && !str_contains($screen, 'value="both"'),
    'And no longer offers HTML, which was never a choice — the table is in the body of every one');
$format = (string) db()->query("SELECT COLUMN_TYPE FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'report_schedules' AND column_name = 'export_format'")
    ->fetchColumn();
ok(str_contains($format, "'pdf'"), 'The column accepts it');
$stranded = (int) db()->query("SELECT COUNT(*) FROM report_schedules WHERE export_format = ''")->fetchColumn();
ok($stranded === 0, 'And no existing schedule was stranded on an empty format by the narrowing');

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
