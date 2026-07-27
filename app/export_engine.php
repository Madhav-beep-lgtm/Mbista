<?php
declare(strict_types=1);

/**
 * Shared export layer — CSV, Excel and print/PDF from ONE table of rows.
 *
 * Every report in the app already knows how to produce `array $rows` for
 * export_csv(). This file takes that same array and renders it as a real .xlsx
 * workbook or a print-ready page, so a screen gains Excel and PDF without
 * building its data a second time. A file that disagrees with the screen it
 * came from is worse than no file at all, and the only way to guarantee they
 * agree is to give them one source.
 *
 * NO EXTERNAL LIBRARIES. The .xlsx writer here is the one already proven in
 * voucher_import.php — a minimal SpreadsheetML package zipped with ZipArchive —
 * lifted out so both callers share it instead of keeping a copy each.
 *
 * On PDF: there is no PDF library in this project, and quietly adding one is
 * not a decision to make on a report's behalf. What is offered instead is a
 * clean print view that every browser turns into a real PDF through "Save as
 * PDF" — the same file, no dependency, and it honours the print stylesheet the
 * app already ships.
 */

/** Spreadsheet column letters for a zero-based index: 0 => A, 26 => AA. */
function xlsx_column_letters(int $columnIndex): string
{
    $letters = '';
    $n = $columnIndex + 1;
    while ($n > 0) {
        $letters = chr(65 + (($n - 1) % 26)) . $letters;
        $n = intdiv($n - 1, 26);
    }

    return $letters;
}

/**
 * A single-sheet .xlsx workbook as a binary string.
 *
 * @param array $rows       list of rows; each row a list of scalars
 * @param string $sheetName worksheet tab name
 * @param array $colWidths  optional [columnIndex => width]
 */
function xlsx_build(array $rows, string $sheetName = 'Sheet1', array $colWidths = []): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The server is missing the PHP zip extension needed to write .xlsx files. Export as CSV instead.');
    }
    $xml = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    // Excel refuses a workbook whose sheet name carries any of : \ / ? * [ ]
    // or runs past 31 characters, so it is cleaned rather than trusted.
    $safeSheet = trim(preg_replace('/[:\\\\\\/?*\[\]]/', ' ', $sheetName) ?: 'Sheet1');
    $safeSheet = mb_substr($safeSheet === '' ? 'Sheet1' : $safeSheet, 0, 31);

    $rowsXml = '';
    $maxColumns = 0;
    foreach (array_values($rows) as $rowIndex => $row) {
        $cellsXml = '';
        foreach (array_values((array) $row) as $columnIndex => $value) {
            $maxColumns = max($maxColumns, $columnIndex + 1);
            $ref = xlsx_column_letters($columnIndex) . ($rowIndex + 1);
            if (is_int($value) || (is_float($value) && is_finite($value))) {
                $cellsXml .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
                continue;
            }
            $text = (string) ($value ?? '');
            if ($text === '') {
                continue;
            }
            // A numeric string is written as a number so Excel can total it;
            // anything else is an inline string, which also neutralises the
            // formula-injection that a leading = would otherwise cause.
            if (preg_match('/^-?\d+(\.\d+)?$/', $text) === 1) {
                $cellsXml .= '<c r="' . $ref . '"><v>' . $text . '</v></c>';
            } else {
                $cellsXml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $xml($text) . '</t></is></c>';
            }
        }
        $rowsXml .= '<row r="' . ($rowIndex + 1) . '">' . $cellsXml . '</row>';
    }

    $colsXml = '';
    if ($colWidths !== []) {
        $colsXml = '<cols>';
        foreach ($colWidths as $columnIndex => $width) {
            $colsXml .= '<col min="' . ((int) $columnIndex + 1) . '" max="' . ((int) $columnIndex + 1)
                . '" width="' . (float) $width . '" customWidth="1"/>';
        }
        $colsXml .= '</cols>';
    } elseif ($maxColumns > 0) {
        $colsXml = '<cols><col min="1" max="' . $maxColumns . '" width="18" customWidth="1"/></cols>';
    }

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $xml($safeSheet) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="1"><xf xfId="0"/></cellXfs>'
            . '</styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $colsXml . '<sheetData>' . $rowsXml . '</sheetData></worksheet>',
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($tmp === false) {
        throw new RuntimeException('Could not open a temporary file to build the workbook.');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Could not create the .xlsx package.');
    }
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
    $bytes = (string) file_get_contents($tmp);
    @unlink($tmp);

    return $bytes;
}

/** Stream a table of rows as a .xlsx download and stop. */
function export_xlsx(string $filename, array $rows, string $sheetName = 'Export', array $colWidths = []): void
{
    $bytes = xlsx_build($rows, $sheetName, $colWidths);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $bytes;
    exit;
}

/**
 * A print-ready page for the same rows, which the browser turns into a PDF.
 *
 * First row is treated as the header. `$meta` is printed under the title as
 * label/value pairs — the period, the company, whatever the report is scoped
 * by — because a printout that does not say what it covers is not evidence of
 * anything.
 */
function export_print(string $title, array $rows, array $meta = [], array $options = []): void
{
    $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $header = $rows === [] ? [] : array_values((array) array_shift($rows));
    $numeric = static fn ($v): bool => is_int($v) || is_float($v)
        || (is_string($v) && $v !== '' && preg_match('/^-?[\d,]+(\.\d+)?$/', $v) === 1);

    $companyName = '';
    if (function_exists('current_company')) {
        $company = current_company();
        $companyName = (string) ($company['name'] ?? '');
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . $esc($title) . '</title><style>';
    echo 'body{font:13px/1.45 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111;margin:24px;background:#fff}';
    echo 'h1{font-size:18px;margin:0 0 4px}.org{font-size:14px;font-weight:600;margin:0 0 2px}';
    echo '.meta{color:#555;font-size:12px;margin:0 0 14px}.meta span{margin-right:16px;white-space:nowrap}';
    echo 'table{border-collapse:collapse;width:100%}';
    echo 'th,td{border:1px solid #bbb;padding:5px 7px;text-align:left;vertical-align:top}';
    echo 'th{background:#eee;font-weight:600}td.n,th.n{text-align:right;white-space:nowrap}';
    echo 'tbody tr:nth-child(even){background:#fafafa}';
    echo '.bar{margin:0 0 16px}.bar button{font:inherit;padding:7px 14px;margin-right:8px;cursor:pointer}';
    echo '@media print{.bar{display:none}body{margin:0}th{background:#eee!important;-webkit-print-color-adjust:exact}}';
    echo '</style></head><body>';

    echo '<div class="bar"><button onclick="window.print()">Print / Save as PDF</button>';
    echo '<button onclick="window.close()">Close</button></div>';
    if ($companyName !== '') {
        echo '<p class="org">' . $esc($companyName) . '</p>';
    }
    echo '<h1>' . $esc($title) . '</h1>';
    if ($meta !== []) {
        echo '<p class="meta">';
        foreach ($meta as $label => $value) {
            echo '<span><strong>' . $esc($label) . ':</strong> ' . $esc($value) . '</span>';
        }
        echo '</p>';
    }

    echo '<table><thead><tr>';
    foreach ($header as $cell) {
        echo '<th>' . $esc($cell) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if ($rows === []) {
        echo '<tr><td colspan="' . max(1, count($header)) . '">Nothing to show for this selection.</td></tr>';
    }
    foreach ($rows as $row) {
        echo '<tr>';
        foreach (array_values((array) $row) as $cell) {
            echo '<td' . ($numeric($cell) ? ' class="n"' : '') . '>' . $esc($cell) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';

    if (!empty($options['footnote'])) {
        echo '<p class="meta" style="margin-top:14px">' . $esc((string) $options['footnote']) . '</p>';
    }
    echo '<p class="meta" style="margin-top:14px">Printed ' . $esc(date('Y-m-d H:i')) . '</p>';
    // Opened deliberately for printing, so offer the dialog straight away.
    if (!empty($options['auto_print'])) {
        echo '<script>window.addEventListener("load",function(){window.print();});</script>';
    }
    echo '</body></html>';
    exit;
}

/**
 * One entry point for every export format, so a screen wires up all three at
 * once and they can never drift apart.
 *
 * @param string $format csv | xlsx | print
 */
function export_dispatch(string $format, string $basename, array $rows, string $title, array $meta = []): void
{
    switch ($format) {
        case 'xlsx':
            export_xlsx($basename . '.xlsx', $rows, $title);
            // no break — export_xlsx exits
        case 'print':
        case 'pdf':
            export_print($title, $rows, $meta, ['auto_print' => true]);
            // no break — export_print exits
        default:
            export_csv($basename . '.csv', $rows);
    }
}
