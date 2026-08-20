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
 * @param array $options    styled_table, freeze_header and auto_filter
 */
function xlsx_build(array $rows, string $sheetName = 'Sheet1', array $colWidths = [], array $options = []): string
{
    return xlsx_build_sheets([$sheetName => $rows], $colWidths, $options);
}

/**
 * A workbook of one or more sheets, as name => rows.
 *
 * The single-sheet writer above is the common case and delegates here. The
 * sales upload template needs two sheets in one file — item-wise sales and
 * invoice-wise settlement — because the two are reconciled against each other,
 * and handing somebody two separate files invites uploading a mismatched pair.
 */
function xlsx_build_sheets(array $sheets, array $colWidths = [], array $options = []): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The server is missing the PHP zip extension needed to write .xlsx files. Export as CSV instead.');
    }
    if ($sheets === []) {
        $sheets = ['Sheet1' => []];
    }
    $xml = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    // Widths may be given per sheet as [sheetName => [i => width]]; a flat
    // array applies to every sheet, which is what a single sheet wants.
    $widthsFor = static function (string $name, int $index) use ($colWidths): array {
        if ($colWidths === []) {
            return [];
        }
        if (isset($colWidths[$name]) && is_array($colWidths[$name])) {
            return $colWidths[$name];
        }
        if (isset($colWidths[$index]) && is_array($colWidths[$index])) {
            return $colWidths[$index];
        }

        return is_array(reset($colWidths)) ? [] : $colWidths;
    };

    $usedNames = [];
    $sheetXmls = [];
    $sheetEntries = [];
    $index = 0;
    foreach ($sheets as $rawName => $rows) {
        $index++;
        // Excel refuses a workbook whose sheet name carries any of : \ / ? * [ ]
        // or runs past 31 characters, so it is cleaned rather than trusted.
        $safeSheet = trim(preg_replace('/[:\\\\\/?*\[\]]/', ' ', (string) $rawName) ?: 'Sheet' . $index);
        $safeSheet = mb_substr($safeSheet === '' ? 'Sheet' . $index : $safeSheet, 0, 31);
        // Two sheets cannot share a name, and Excel reports the whole file as
        // corrupt rather than saying which one is the problem.
        $candidate = $safeSheet;
        $suffix = 1;
        while (isset($usedNames[mb_strtolower($candidate)])) {
            $suffix++;
            $candidate = mb_substr($safeSheet, 0, 28) . ' ' . $suffix;
        }
        $safeSheet = $candidate;
        $usedNames[mb_strtolower($safeSheet)] = true;

        $sheetXmls['xl/worksheets/sheet' . $index . '.xml'] = xlsx_worksheet_xml(
            (array) $rows,
            $widthsFor((string) $rawName, $index - 1),
            $options
        );
        $sheetEntries[] = ['name' => $safeSheet, 'id' => $index];
    }

    $sheetsXml = '';
    $sheetRelsXml = '';
    $contentTypesXml = '';
    foreach ($sheetEntries as $entry) {
        $sheetsXml .= '<sheet name="' . $xml($entry['name']) . '" sheetId="' . $entry['id'] . '" r:id="rId' . $entry['id'] . '"/>';
        $sheetRelsXml .= '<Relationship Id="rId' . $entry['id'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $entry['id'] . '.xml"/>';
        $contentTypesXml .= '<Override PartName="/xl/worksheets/sheet' . $entry['id'] . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    // The styles relationship id follows the sheets, so it cannot collide with
    // a worksheet the way a hard-coded rId2 would once there are two of them.
    $stylesRid = 'rId' . (count($sheetEntries) + 1);

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $contentTypesXml
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetsXml . '</sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $sheetRelsXml
            . '<Relationship Id="' . $stylesRid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF5E7C7"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border/><border><left style="thin"><color rgb="FFD0D5DD"/></left><right style="thin"><color rgb="FFD0D5DD"/></right>'
            . '<top style="thin"><color rgb="FFD0D5DD"/></top><bottom style="thin"><color rgb="FFD0D5DD"/></bottom></border></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="3"><xf xfId="0"/><xf xfId="0" fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '<xf xfId="0" borderId="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf></cellXfs>'
            . '</styleSheet>',
    ] + $sheetXmls;

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

/** One worksheet's XML from its rows. */
function xlsx_worksheet_xml(array $rows, array $colWidths, array $options): string
{
    $xml = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $rowsXml = '';
    $maxColumns = 0;
    $styledTable = !empty($options['styled_table']);
    foreach (array_values($rows) as $rowIndex => $row) {
        $cellsXml = '';
        foreach (array_values((array) $row) as $columnIndex => $value) {
            $maxColumns = max($maxColumns, $columnIndex + 1);
            $ref = xlsx_column_letters($columnIndex) . ($rowIndex + 1);
            $style = $styledTable ? ' s="' . ($rowIndex === 0 ? '1' : '2') . '"' : '';
            if (is_int($value) || (is_float($value) && is_finite($value))) {
                $cellsXml .= '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>';
                continue;
            }
            $text = (string) ($value ?? '');
            if ($text === '') {
                if ($styledTable) {
                    $cellsXml .= '<c r="' . $ref . '"' . $style . '/>';
                }
                continue;
            }
            // A numeric string is written as a number so Excel can total it;
            // anything else is an inline string, which also neutralises the
            // formula-injection that a leading = would otherwise cause.
            if (preg_match('/^-?\d+(\.\d+)?$/', $text) === 1) {
                $cellsXml .= '<c r="' . $ref . '"' . $style . '><v>' . $text . '</v></c>';
            } else {
                $cellsXml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . $xml($text) . '</t></is></c>';
            }
        }
        $rowsXml .= '<row r="' . ($rowIndex + 1) . '"' . ($styledTable && $rowIndex === 0 ? ' ht="24" customHeight="1"' : '')
            . '>' . $cellsXml . '</row>';
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

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . (!empty($options['freeze_header']) ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' : '')
        . $colsXml . '<sheetData>' . $rowsXml . '</sheetData>'
        . (!empty($options['auto_filter']) && $maxColumns > 0 ? '<autoFilter ref="A1:' . xlsx_column_letters($maxColumns - 1) . max(1, count($rows)) . '"/>' : '')
        . '</worksheet>';
}

/** Stream a table of rows as a .xlsx download and stop. */
function export_xlsx(string $filename, array $rows, string $sheetName = 'Export', array $colWidths = [], array $options = []): void
{
    $bytes = xlsx_build($rows, $sheetName, $colWidths, $options);
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
    $compact = !empty($options['compact']);
    $landscape = !empty($options['landscape']);
    if (function_exists('current_company')) {
        $company = current_company();
        $companyName = (string) ($company['name'] ?? '');
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . $esc($title) . '</title><style>';
    echo 'body{font:' . ($compact ? '10px/1.3' : '13px/1.45') . ' -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111;margin:24px;background:#fff}';
    echo 'h1{font-size:18px;margin:0 0 4px}.org{font-size:14px;font-weight:600;margin:0 0 2px}';
    echo '.meta{color:#555;font-size:12px;margin:0 0 14px}.meta span{margin-right:16px;white-space:nowrap}';
    echo 'table{border-collapse:collapse;width:100%;' . ($compact ? 'table-layout:auto;font-size:9px;line-height:1.25' : '') . '}';
    echo 'th,td{border:1px solid #bbb;padding:' . ($compact ? '3px 4px' : '5px 7px') . ';text-align:left;vertical-align:top;' . ($compact ? 'overflow-wrap:anywhere;word-break:normal' : '') . '}';
    echo 'th{background:#eee;font-weight:600}td.n,th.n{text-align:right;white-space:nowrap}';
    echo 'tbody tr:nth-child(even){background:#fafafa}';
    echo '.bar{margin:0 0 16px}.bar button{font:inherit;padding:7px 14px;margin-right:8px;cursor:pointer}';
    echo ($landscape ? '@page{size:A3 landscape;margin:8mm}' : '');
    echo '@media print{.bar{display:none}body{margin:0}thead{display:table-header-group}tr{break-inside:avoid}th{background:#eee!important;-webkit-print-color-adjust:exact}}';
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
 * ---------------------------------------------------------------------------
 * Reading spreadsheets back IN.
 * ---------------------------------------------------------------------------
 * The same argument that put the .xlsx writer here applies to the reader: an
 * importer that parses .xlsx its own way will drift from the one next to it,
 * and the bugs that drift produces are the quiet kind — a date column read as
 * a serial number on one screen and as text on another. One parser, one set of
 * behaviours, one place to fix.
 *
 * Every reader returns the same shape:
 *   [ ['n' => <row number in the FILE>, 'cells' => [<string>, ...]], ... ]
 * The file's own row number travels with the row because every error message
 * an importer shows is only useful if it points at the row the user can see.
 */

/** Rows from a .csv, cells trimmed, BOM stripped from the first cell. */
function spreadsheet_read_csv(string $path, int $maxRows = 5000): array
{
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException('The uploaded file could not be opened.');
    }
    $rows = [];
    $rowNo = 0;
    while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $rowNo++;
        if ($rowNo > $maxRows) {
            break;
        }
        if ($rowNo === 1 && isset($cells[0])) {
            $cells[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
        }
        $rows[] = ['n' => $rowNo, 'cells' => array_map(static fn ($c): string => trim((string) $c), $cells)];
    }
    fclose($handle);

    return $rows;
}

/**
 * Rows from the FIRST worksheet of a .xlsx, without any external library.
 *
 * A .xlsx is a zip of XML: the workbook names its sheets, a relationship file
 * says which part each one lives in, and most text sits in a shared-strings
 * table rather than in the cells themselves — a cell of type "s" holds an index
 * into it, not a word. Blank cells are simply absent from the XML, so the row
 * is padded back out to keep column positions meaningful.
 */
/**
 * Rows from a workbook. By default the FIRST sheet, which is what every
 * importer that predates the two-sheet sales upload expects; pass $only as
 * 'all' (via spreadsheet_read_xlsx_all) to get every sheet keyed by name.
 */
function spreadsheet_read_xlsx(string $path, int $maxRows = 5000, ?string $only = null): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The server is missing the PHP zip extension needed to read .xlsx files. Upload a .csv instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('The file is not a valid Excel (.xlsx) workbook.');
    }

    $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) {
        $zip->close();
        throw new RuntimeException('The workbook structure could not be read. Save the file as .xlsx (not .xls) and retry.');
    }

    $workbook = simplexml_load_string($workbookXml);
    $rels = simplexml_load_string($relsXml);
    if ($workbook === false || $rels === false) {
        $zip->close();
        throw new RuntimeException('The workbook XML could not be parsed.');
    }

    $relTargets = [];
    foreach ($rels->Relationship as $relationship) {
        $relTargets[(string) $relationship['Id']] = (string) $relationship['Target'];
    }
    // Every sheet in workbook order, as name => path inside the zip. The
    // caller asking for one sheet takes the first; the sales upload reads a
    // workbook carrying an item-wise sheet and an invoice-wise sheet together.
    $sheetPaths = [];
    foreach ($workbook->sheets->sheet as $sheet) {
        $rid = (string) ($sheet->attributes($relNs)['id'] ?? '');
        $target = $relTargets[$rid] ?? '';
        if ($target === '') {
            continue;
        }
        $name = trim((string) ($sheet['name'] ?? ''));
        if ($name === '') {
            $name = 'Sheet' . (count($sheetPaths) + 1);
        }
        // Two sheets sharing a name cannot happen in Excel, but a hand-built
        // workbook could; the later one is kept under a suffixed key rather
        // than silently replacing the earlier.
        while (isset($sheetPaths[$name])) {
            $name .= ' ';
        }
        $sheetPaths[$name] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
    }
    if ($sheetPaths === []) {
        $zip->close();
        throw new RuntimeException('No worksheet found in the workbook.');
    }
    // Only the first sheet is unzipped and parsed unless every sheet was
    // asked for, so the common single-sheet import does no extra work.
    if ($only === null) {
        $sheetPaths = array_slice($sheetPaths, 0, 1, true);
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sst = simplexml_load_string($sharedXml);
        if ($sst !== false) {
            foreach ($sst->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string) $si->t;
                }
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetXmls = [];
    foreach ($sheetPaths as $sheetName => $sheetPath) {
        $sheetXmls[$sheetName] = $zip->getFromName($sheetPath);
    }
    $zip->close();

    $sheets = [];
    foreach ($sheetXmls as $sheetName => $sheetXml) {
        if ($sheetXml === false) {
            throw new RuntimeException('The worksheet could not be read from the workbook.');
        }
        $sheets[$sheetName] = spreadsheet_xlsx_sheet_rows($sheetXml, $sharedStrings, $maxRows);
    }

    return $only === null ? reset($sheets) : $sheets;
}

/** The rows of ONE worksheet's XML, in the shape every importer here expects. */
function spreadsheet_xlsx_sheet_rows(string $sheetXml, array $sharedStrings, int $maxRows): array
{
    $worksheet = simplexml_load_string($sheetXml);
    if ($worksheet === false) {
        throw new RuntimeException('The worksheet XML could not be parsed.');
    }

    $rows = [];
    foreach ($worksheet->sheetData->row as $row) {
        if (count($rows) >= $maxRows) {
            break;
        }
        $rowNo = (int) $row['r'];
        $cells = [];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            if (!preg_match('/^([A-Z]+)\d+$/', $ref, $m)) {
                continue;
            }
            $columnIndex = 0;
            foreach (str_split($m[1]) as $letter) {
                $columnIndex = $columnIndex * 26 + (ord($letter) - 64);
            }
            $columnIndex--;
            if ($columnIndex > 63) {
                continue;
            }
            $type = (string) $cell['t'];
            $value = '';
            if ($type === 's') {
                $value = $sharedStrings[(int) $cell->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
                foreach ($cell->is->r ?? [] as $run) {
                    $value .= (string) $run->t;
                }
            } else {
                $value = (string) $cell->v;
            }
            $cells[$columnIndex] = trim($value);
        }
        if ($cells === []) {
            continue;
        }
        $padded = array_fill(0, max(array_keys($cells)) + 1, '');
        foreach ($cells as $index => $value) {
            $padded[$index] = $value;
        }
        $rows[] = ['n' => $rowNo > 0 ? $rowNo : count($rows) + 1, 'cells' => $padded];
    }

    return $rows;
}

/**
 * Every sheet in a workbook, as name => rows, in the order Excel holds them.
 *
 * The single-sheet reader above takes the first and is what every existing
 * importer wants; this is for the sales upload, which needs the item-wise and
 * the invoice-wise sheet out of one file.
 */
function spreadsheet_read_xlsx_all(string $path, int $maxRows = 5000): array
{
    return spreadsheet_read_xlsx($path, $maxRows, 'all');
}

/**
 * Rows from whichever of the two formats the user uploaded, chosen by
 * extension. .xls is named explicitly because Excel will happily offer it and
 * the resulting error is otherwise baffling — it is a different, binary format
 * that nothing here can read.
 */
function spreadsheet_read_rows(string $path, string $originalName, int $maxRows = 5000): array
{
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === 'xls') {
        throw new RuntimeException('Legacy .xls files cannot be read — open the file in Excel and save it as .xlsx or .csv.');
    }
    if ($extension === 'xlsx') {
        return spreadsheet_read_xlsx($path, $maxRows);
    }
    if ($extension === 'csv' || $extension === 'txt') {
        return spreadsheet_read_csv($path, $maxRows);
    }

    throw new RuntimeException('Upload a .xlsx or .csv file.');
}

/**
 * One entry point for every export format, so a screen wires up all three at
 * once and they can never drift apart.
 *
 * @param string $format csv | xlsx | print
 */
function export_dispatch(string $format, string $basename, array $rows, string $title, array $meta = [], array $options = []): void
{
    switch ($format) {
        case 'xlsx':
            export_xlsx($basename . '.xlsx', $rows, $title);
            // no break — export_xlsx exits
        case 'print':
        case 'pdf':
            export_print($title, $rows, $meta, $options + ['auto_print' => true]);
            // no break — export_print exits
        default:
            export_csv($basename . '.csv', $rows);
    }
}
