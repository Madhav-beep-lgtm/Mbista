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
    // THE HEADING HAS TO REPRINT ON PAGE TWO. A statement that runs to four
    // pages is read on all four, and pages two to four of a bare grid of
    // figures cannot be read at all — nobody can say which column is Opening
    // Dr. and which is Closing Cr. Print_Titles is how a workbook says "these
    // rows are the heading", and Excel repeats them at the top of every page.
    $repeatRows = max(0, (int) ($options['print']['repeat_rows'] ?? 0));
    $definedNamesXml = '';
    foreach ($sheetEntries as $entry) {
        $sheetsXml .= '<sheet name="' . $xml($entry['name']) . '" sheetId="' . $entry['id'] . '" r:id="rId' . $entry['id'] . '"/>';
        $sheetRelsXml .= '<Relationship Id="rId' . $entry['id'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $entry['id'] . '.xml"/>';
        $contentTypesXml .= '<Override PartName="/xl/worksheets/sheet' . $entry['id'] . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        if ($repeatRows > 0) {
            // A sheet name is quoted in a formula, and an apostrophe inside it
            // is doubled — "Ram's Books" otherwise closes the quote early and
            // Excel calls the whole workbook corrupt.
            $quoted = "'" . str_replace("'", "''", $entry['name']) . "'";
            $definedNamesXml .= '<definedName name="_xlnm.Print_Titles" localSheetId="' . ($entry['id'] - 1) . '">'
                . $xml($quoted) . '!$1:$' . $repeatRows . '</definedName>';
        }
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
            . '<sheets>' . $sheetsXml . '</sheets>'
            . ($definedNamesXml !== '' ? '<definedNames>' . $definedNamesXml . '</definedNames>' : '')
            . '</workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $sheetRelsXml
            . '<Relationship Id="' . $stylesRid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => xlsx_styles_xml(),
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

/**
 * THE WORKBOOK'S OWN FORMATS. A spreadsheet of bare digits is not a report:
 * 310649022.2 in a narrow column tells a reader nothing they can check, and
 * the same figure as 310,649,022.20 tells them everything. Money to two
 * places, weights to four — the places the trade actually keeps them in —
 * counts with a thousands separator and none after the point, and every
 * figure right-aligned so the columns line up on the decimal the way a
 * ledger does.
 *
 * Style indexes are positional and the worksheet writer knows them by number,
 * so they are only ever APPENDED to. Inserting one in the middle silently
 * restyles every cell after it.
 *
 * The flat block, 0-12, is what a register export uses:
 *   0 plain          1 header         2 body text
 *   3 body money     4 body weight    5 body count
 *   6 total text     7 total money    8 total weight   9 total count
 *   10 report name   11 organisation  12 meta line
 *
 * The statement block, 13 and up, is what a financial statement uses, where a
 * row is not just "body" or "total": a master group heading, a group subtotal
 * and a ledger line all sit in the same table and have to be told apart at a
 * glance. Its indexes are laid out by xlsx_statement_style() rather than
 * written down here, so the two never drift apart.
 */
function xlsx_styles_xml(): string
{
    $attr = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $numFmts = [
        164 => '#,##0.00',
        165 => '#,##0.0000',
        166 => '#,##0',
        // The statement formats print a zero as the dash the statement itself
        // shows and a negative in the brackets an accountant reads as one,
        // while the cell underneath stays a number Excel can still add up.
        167 => '#,##0.00;(#,##0.00);"–"',
        168 => '#,##0.0000;(#,##0.0000);"–"',
        169 => '#,##0;(#,##0);"–"',
    ];
    $numFmtsXml = '';
    foreach ($numFmts as $id => $code) {
        $numFmtsXml .= '<numFmt numFmtId="' . $id . '" formatCode="' . $attr($code) . '"/>';
    }

    // 0 body, 1 bold, 2 bold 14, 3 grey caption, 4 column heading,
    // 5 statement emphasis, 6 organisation, 7 statement body.
    $fonts = [
        '<font><sz val="11"/><name val="Calibri"/></font>',
        '<font><b/><sz val="11"/><name val="Calibri"/></font>',
        '<font><b/><sz val="14"/><name val="Calibri"/></font>',
        '<font><sz val="10"/><color rgb="FF667085"/><name val="Calibri"/></font>',
        '<font><b/><sz val="10.5"/><color rgb="FF16325D"/><name val="Calibri"/></font>',
        '<font><b/><sz val="11"/><color rgb="FF16325D"/><name val="Calibri"/></font>',
        '<font><b/><sz val="13"/><color rgb="FF16325D"/><name val="Calibri"/></font>',
        '<font><sz val="11"/><color rgb="FF1F2937"/><name val="Calibri"/></font>',
    ];

    // 4, 5 and 6 are the three tints the printed statement already uses, so a
    // report opened in Excel and the same report on paper are recognisably the
    // same document.
    $fills = [
        '<fill><patternFill patternType="none"/></fill>',
        '<fill><patternFill patternType="gray125"/></fill>',
        '<fill><patternFill patternType="solid"><fgColor rgb="FFF5E7C7"/><bgColor indexed="64"/></patternFill></fill>',
        '<fill><patternFill patternType="solid"><fgColor rgb="FFEFF3F0"/><bgColor indexed="64"/></patternFill></fill>',
        '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5FB"/><bgColor indexed="64"/></patternFill></fill>',
        '<fill><patternFill patternType="solid"><fgColor rgb="FFF6F8FC"/><bgColor indexed="64"/></patternFill></fill>',
        '<fill><patternFill patternType="solid"><fgColor rgb="FFEEF3FA"/><bgColor indexed="64"/></patternFill></fill>',
    ];

    // EVERY CELL IS BOXED, in a line dark enough to see. The grid was drawn in
    // FFD0D5DD, which is a hairline on a screen and nothing at all on paper —
    // the sheet read as loose columns of figures with no visible rows, which is
    // exactly what makes a printed register hard to follow across. Black thin
    // is what Excel's own All Borders does, and it is what a ledger has always
    // looked like.
    //
    // The totals row keeps a double rule above it. That is the one line on the
    // sheet that has to be seen without being looked for. The heading keeps a
    // medium rule under it, which is the other.
    $box = '<left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right>';
    $borders = [
        '<border/>',
        '<border>' . $box . '<top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom></border>',
        '<border>' . $box . '<top style="double"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom></border>',
        '<border>' . $box . '<top style="thin"><color rgb="FF000000"/></top><bottom style="medium"><color rgb="FF000000"/></bottom></border>',
    ];

    $xfs = [
        '<xf xfId="0"/>',
        '<xf xfId="0" fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>',
        '<xf xfId="0" borderId="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>',
        '<xf xfId="0" numFmtId="164" borderId="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="right"/></xf>',
        '<xf xfId="0" numFmtId="165" borderId="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="right"/></xf>',
        '<xf xfId="0" numFmtId="166" borderId="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="right"/></xf>',
        '<xf xfId="0" fontId="1" fillId="3" borderId="2" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>',
        '<xf xfId="0" fontId="1" fillId="3" numFmtId="164" borderId="2" applyFont="1" applyFill="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="right"/></xf>',
        '<xf xfId="0" fontId="1" fillId="3" numFmtId="165" borderId="2" applyFont="1" applyFill="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="right"/></xf>',
        '<xf xfId="0" fontId="1" fillId="3" numFmtId="166" borderId="2" applyFont="1" applyFill="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="right"/></xf>',
        // 10 the report's own name, 11 the organisation, 12 a meta line. No
        // borders on any of them: a title is not a table cell, and boxing it
        // makes the sheet look like the header row belongs to the data.
        '<xf xfId="0" fontId="2" applyFont="1"/>',
        '<xf xfId="0" fontId="1" applyFont="1"/>',
        '<xf xfId="0" fontId="3" applyFont="1"/>',
    ];

    // 13-16: the letterhead, centred across the width of the table because a
    // statement's heading belongs over the whole statement and not over column A.
    $centred = '<alignment horizontal="center" vertical="center"/>';
    $xfs[] = '<xf xfId="0" fontId="6" applyFont="1" applyAlignment="1">' . $centred . '</xf>';
    $xfs[] = '<xf xfId="0" fontId="2" applyFont="1" applyAlignment="1">' . $centred . '</xf>';
    $xfs[] = '<xf xfId="0" fontId="5" applyFont="1" applyAlignment="1">' . $centred . '</xf>';
    $xfs[] = '<xf xfId="0" fontId="3" applyFont="1" applyAlignment="1">' . $centred . '</xf>';

    // 17-19: the column heading, which wraps rather than truncating — "Opening
    // Balance Dr." has to be readable without widening the column to fit it.
    foreach (['left', 'right', 'center'] as $align) {
        $xfs[] = '<xf xfId="0" fontId="4" fillId="4" borderId="3" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="' . $align . '" vertical="center" wrapText="1"/></xf>';
    }

    // 20 and up: four bands of nine. See xlsx_statement_style() for the order —
    // it is the only thing that should ever compute one of these indexes.
    foreach (XLSX_STATEMENT_BANDS as $band) {
        $common = ' fontId="' . $band[0] . '" applyFont="1"'
            . ($band[1] > 0 ? ' fillId="' . $band[1] . '" applyFill="1"' : '')
            . ' borderId="' . $band[2] . '" applyBorder="1" applyAlignment="1"';
        for ($indent = 0; $indent <= 3; $indent++) {
            $xfs[] = '<xf xfId="0"' . $common . '><alignment horizontal="left" vertical="center"'
                . ($indent > 0 ? ' indent="' . $indent . '"' : '') . '/></xf>';
        }
        $xfs[] = '<xf xfId="0"' . $common . '><alignment horizontal="right" vertical="center"/></xf>';
        $xfs[] = '<xf xfId="0"' . $common . '>' . $centred . '</xf>';
        foreach ([167, 168, 169] as $numFmtId) {
            $xfs[] = '<xf xfId="0" numFmtId="' . $numFmtId . '" applyNumberFormat="1"' . $common
                . '><alignment horizontal="right" vertical="center"/></xf>';
        }
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="' . count($numFmts) . '">' . $numFmtsXml . '</numFmts>'
        . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
        . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
        . '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
        . '</styleSheet>';
}

/** [fontId, fillId, borderId] for body, bold, section and total rows. */
const XLSX_STATEMENT_BANDS = [
    'body' => [7, 0, 1],
    'bold' => [5, 0, 1],
    'section' => [5, 5, 1],
    'total' => [5, 6, 2],
];

/** Where the statement block starts, and how wide one band of it is. */
const XLSX_STATEMENT_FIRST = 20;
const XLSX_STATEMENT_BAND_SIZE = 9;

/**
 * The style index for one cell of a statement.
 *
 * $kind is what the ROW is — 'company', 'title', 'entity' and 'meta' for the
 * letterhead, 'header' for the column heading, and 'body', 'bold', 'section'
 * or 'total' for a line of the table. $format is what the COLUMN holds, and
 * $indent is how far down the chart of accounts the line sits, which is the
 * only thing that tells a reader a ledger belongs to the group above it.
 */
function xlsx_statement_style(string $kind, string $format = 'text', string $align = 'left', int $indent = 0): int
{
    switch ($kind) {
        case 'company': return 13;
        case 'title': return 14;
        case 'entity': return 15;
        case 'meta': return 16;
        case 'header': return $align === 'right' ? 18 : ($align === 'center' ? 19 : 17);
    }

    $band = array_search($kind, array_keys(XLSX_STATEMENT_BANDS), true);
    if ($band === false) {
        $band = 0;
    }
    $offset = match ($format) {
        'money' => 6,
        'weight' => 7,
        'count' => 8,
        default => $align === 'right' ? 4 : ($align === 'center' ? 5 : max(0, min(3, $indent))),
    };

    return XLSX_STATEMENT_FIRST + ((int) $band * XLSX_STATEMENT_BAND_SIZE) + $offset;
}

/** One worksheet's XML from its rows. */
function xlsx_worksheet_xml(array $rows, array $colWidths, array $options): string
{
    $xml = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $rowsXml = '';
    $maxColumns = 0;
    $styledTable = !empty($options['styled_table']);
    $columnFormats = (array) ($options['column_formats'] ?? []);
    // -1 means "no totals row", which is not a row index anything can equal.
    $totalRowIndex = array_key_exists('total_row', $options) ? (int) $options['total_row'] : -1;
    // WHICH ROW IS THE TABLE'S HEADING. Zero unless a title block sits above it,
    // and everything that used to assume row 1 — the freeze, the filter, the
    // header styling — now asks this instead.
    $headerRowIndex = max(0, (int) ($options['header_row'] ?? 0));

    // A STATEMENT SAYS WHAT EACH ROW IS. A register can work out a row's style
    // from its position — first row heading, last row total, everything else
    // body — but a trial balance cannot: a master heading, a group subtotal and
    // a ledger line are interleaved all the way down and only the caller knows
    // which is which. When row_kinds is given it decides, and the positional
    // rules above are left alone for every caller that does not pass it.
    $rowKinds = (array) ($options['row_kinds'] ?? []);
    $statement = $rowKinds !== [];
    $columnAligns = (array) ($options['column_aligns'] ?? []);
    $rowAligns = (array) ($options['row_aligns'] ?? []);
    $rowIndents = (array) ($options['row_indents'] ?? []);
    foreach (array_values($rows) as $rowIndex => $row) {
        $cellsXml = '';
        foreach (array_values((array) $row) as $columnIndex => $value) {
            $maxColumns = max($maxColumns, $columnIndex + 1);
            $ref = xlsx_column_letters($columnIndex) . ($rowIndex + 1);
            // Header, totals band, or ordinary body — and within the last two,
            // the column's own kind decides how the figure is written.
            $styleId = 2;
            $forceText = false;
            if ($statement) {
                $rowKind = (string) ($rowKinds[$rowIndex] ?? 'body');
                if ($rowKind === 'blank') {
                    // Nothing at all, not even an empty boxed cell: the gap
                    // between the letterhead and the table is what makes the
                    // table read as a table.
                    continue;
                }
                $align = (string) ($rowAligns[$rowIndex][$columnIndex] ?? $columnAligns[$columnIndex] ?? 'left');
                $format = (string) ($columnFormats[$columnIndex] ?? 'text');
                $indent = isset($rowIndents[$rowIndex]) && (int) ($rowIndents[$rowIndex][0] ?? -1) === $columnIndex
                    ? (int) ($rowIndents[$rowIndex][1] ?? 0)
                    : 0;
                $styleId = xlsx_statement_style($rowKind, $format, $align, $indent);
                // A LEDGER CODE IS NOT A QUANTITY. Written as a number, 0101
                // opens as 101 and the account can no longer be found by the
                // code printed beside it on every other copy of the report.
                $forceText = $format === 'text';
            } elseif ($rowIndex < $headerRowIndex) {
                // The title block. Row 0 is the report's name, row 1 the
                // organisation, and everything after is a label/value line.
                $styleId = $rowIndex === 0 ? 10 : ($rowIndex === 1 ? 11 : 12);
            } elseif ($rowIndex === $headerRowIndex) {
                $styleId = 1;
            } else {
                $kind = $columnFormats[$columnIndex] ?? 'text';
                $base = $rowIndex === $totalRowIndex ? 6 : 2;
                $styleId = match ($kind) {
                    'money' => $base === 6 ? 7 : 3,
                    'weight' => $base === 6 ? 8 : 4,
                    'count' => $base === 6 ? 9 : 5,
                    default => $base,
                };
            }
            $style = $styledTable ? ' s="' . $styleId . '"' : '';
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
            if (!$forceText && preg_match('/^-?\d+(\.\d+)?$/', $text) === 1) {
                $cellsXml .= '<c r="' . $ref . '"' . $style . '><v>' . $text . '</v></c>';
            } else {
                $cellsXml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . $xml($text) . '</t></is></c>';
            }
        }
        $tallRow = $styledTable
            && ($statement ? (string) ($rowKinds[$rowIndex] ?? '') === 'header' : $rowIndex === $headerRowIndex);
        $rowsXml .= '<row r="' . ($rowIndex + 1) . '"' . ($tallRow ? ' ht="24" customHeight="1"' : '')
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

    // The letterhead is one heading spread over the width of the table, not a
    // word in column A and seven empty cells beside it.
    $mergesXml = '';
    $merges = array_values(array_filter((array) ($options['merges'] ?? [])));
    foreach ($merges as $ref) {
        $mergesXml .= '<mergeCell ref="' . $xml((string) $ref) . '"/>';
    }

    // A REPORT IS PRINTED. Left to itself Excel breaks a wide statement down
    // the middle and prints the last three columns on their own sheets of
    // paper, which is how a balance sheet ends up unreadable in a folder.
    // Fitting to one page WIDE and as many pages long as it takes is what a
    // person does by hand every time before they press print.
    $print = (array) ($options['print'] ?? []);
    $pageXml = '';
    $sheetPrXml = '';
    if ($print !== []) {
        $sheetPrXml = '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
        $pageXml = '<printOptions horizontalCentered="1"/>'
            . '<pageMargins left="0.35" right="0.35" top="0.5" bottom="0.5" header="0.25" footer="0.25"/>'
            . '<pageSetup paperSize="9" orientation="' . (!empty($print['landscape']) ? 'landscape' : 'portrait')
            . '" fitToWidth="1" fitToHeight="0"/>'
            // Page 3 of 7 in the footer, because a dropped page in a printed
            // set is otherwise found only by someone re-adding the totals.
            . '<headerFooter><oddFooter>&amp;LPage &amp;P of &amp;N&amp;RPrinted &amp;D</oddFooter></headerFooter>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . $sheetPrXml
        . (!empty($options['freeze_header'])
            ? '<sheetViews><sheetView showGridLines="' . ($statement ? '0' : '1') . '" workbookViewId="0"><pane ySplit="' . ($headerRowIndex + 1)
                . '" topLeftCell="A' . ($headerRowIndex + 2) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            : ($statement ? '<sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews>' : ''))
        . $colsXml . '<sheetData>' . $rowsXml . '</sheetData>'
        . (!empty($options['auto_filter']) && $maxColumns > 0
            ? '<autoFilter ref="A' . ($headerRowIndex + 1) . ':' . xlsx_column_letters($maxColumns - 1) . max(1, count($rows)) . '"/>'
            : '')
        . ($mergesXml !== '' ? '<mergeCells count="' . count($merges) . '">' . $mergesXml . '</mergeCells>' : '')
        . $pageXml
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
/**
 * Append a TOTAL row to export rows, once, for every format.
 *
 * A register printed without its total is a list, not a report: the reader adds
 * it up by hand, gets a different answer from the person beside them, and
 * neither can tell which of them is wrong. It goes in here rather than in each
 * page so the CSV, the Excel and the PDF all carry the same footing.
 *
 * WHICH COLUMNS ARE SUMMED. Pass them and they are used. Left out, a column is
 * summed when every data cell in it is a number or blank AND at least one is a
 * number — with a skip-list over the header, because plenty of columns hold
 * numbers that must never be added up:
 *
 *   a RATE or a PERCENTAGE — adding two rates gives a number that is not a rate
 *   a DATE, a document NUMBER, a CODE, an ID — sequence, not quantity
 *   DAYS LATE — the sum of two ages is not an age
 *
 * The skip-list errs towards leaving a column alone. A total nobody asked for
 * is worse than a total that is missing: the missing one is noticed, and the
 * wrong one is quoted.
 *
 * @param  array<int, array<int, mixed>> $rows        header row first, then data
 * @param  array<int, int>               $sumColumns  0-based; empty means detect
 * @return array<int, array<int, mixed>> the same rows with a total appended
 */
function export_totals_row(array $rows, array $sumColumns = [], string $label = 'Total'): array
{
    if (count($rows) < 2) {
        return $rows;
    }
    $header = array_values((array) $rows[0]);
    $body = array_slice($rows, 1);
    $width = count($header);
    if ($width === 0) {
        return $rows;
    }

    $isNumber = static fn ($v): bool => is_int($v) || is_float($v)
        || (is_string($v) && trim($v) !== '' && preg_match('/^-?[\d,]+(\.\d+)?$/', trim($v)) === 1);
    $toNumber = static fn ($v): float => (float) str_replace(',', '', (string) $v);

    if ($sumColumns === []) {
        // Never summed, whatever the cells look like.
        $never = ['rate', '%', 'pct', 'percent', 'date', 'days', 'no.', 'no', 'code', 'id',
            // A running balance already holds the answer on its last row.
            'balance',
            'status', 'purity', 'unit', 'kind', 'basis', 'section', 'phase', 'ref', 'reference',
            'party', 'customer', 'supplier', 'item', 'type', 'source', 'label', 'figure'];
        for ($column = 0; $column < $width; $column++) {
            $heading = strtolower(trim((string) ($header[$column] ?? '')));
            $skip = false;
            foreach ($never as $word) {
                // Whole word, so "Rate" is skipped and "Corporate" is not.
                if ($heading === $word || preg_match('/\b' . preg_quote($word, '/') . '\b/', $heading) === 1) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $sawNumber = false;
            $allNumericOrBlank = true;
            foreach ($body as $row) {
                $cell = array_values((array) $row)[$column] ?? '';
                if ($cell === null || (is_string($cell) && trim($cell) === '')) {
                    continue;
                }
                if ($isNumber($cell)) {
                    $sawNumber = true;
                    continue;
                }
                $allNumericOrBlank = false;
                break;
            }
            if ($sawNumber && $allNumericOrBlank) {
                $sumColumns[] = $column;
            }
        }
    }
    if ($sumColumns === []) {
        return $rows;
    }

    $totals = array_fill(0, $width, '');
    $totals[0] = $label;
    foreach ($sumColumns as $column) {
        if ($column < 0 || $column >= $width) {
            continue;
        }
        $sum = 0.0;
        $decimals = 0;
        foreach ($body as $row) {
            $cell = array_values((array) $row)[$column] ?? '';
            if (!$isNumber($cell)) {
                continue;
            }
            $sum += $toNumber($cell);
            // Foot the column to the SAME number of places its rows are
            // written in, so a total of four-decimal weights does not print
            // beside them as a rounded two.
            $dot = strrpos(rtrim((string) $cell), '.');
            if ($dot !== false) {
                $decimals = max($decimals, strlen(rtrim((string) $cell)) - $dot - 1);
            }
        }
        $totals[$column] = number_format($sum, min(6, $decimals), '.', '');
    }
    // The label needs somewhere to live. If column 0 is itself being totalled,
    // the label would overwrite that figure, so it steps aside to column 1.
    if (in_array(0, $sumColumns, true) && $width > 1) {
        $totals[1] = $totals[1] === '' ? $label : $totals[1];
    }
    $rows[] = $totals;

    return $rows;
}
/**
 * What kind of figure each column holds, read off the rows themselves.
 *
 * The workbook needs this to choose a number format, and the rows are the only
 * honest source: a column of money in one report is a column of carats in
 * another, and headers are written for people rather than for parsers.
 *
 *   money   every value numeric, at most two decimal places anywhere
 *   weight  every value numeric, more than two places somewhere — the trade
 *           keeps fine weights to four, and rounding them to two in the file
 *           loses metal that the shop is accountable for
 *   count   every value a whole number
 *   text    anything else, including a column with one stray word in it
 *
 * A column is only ever typed when EVERY cell in it agrees. One name in a
 * column of amounts makes the whole column text, which is right: better a
 * plain column than a formatted one hiding a value it could not parse.
 *
 * @param  array<int, array<int, mixed>> $rows header row first, then data
 * @return array<int, string> column index => kind
 */
function export_column_formats(array $rows): array
{
    if (count($rows) < 2) {
        return [];
    }
    $body = array_slice($rows, 1);
    $header = array_values((array) $rows[0]);
    $width = count($header);
    // AN IDENTIFIER IS NOT A QUANTITY, however much it looks like one. An order
    // reference of 1234 formatted as a number prints "1,234", and a reader who
    // goes looking for order 1,234 will not find it. These columns are held as
    // text before their contents are even examined.
    $identifier = ['no', 'no.', 'ref', 'reference', 'code', 'id', 'invoice', 'sku',
        'barcode', 'phone', 'mobile', 'year', 'pan', 'vat no', 'account'];

    $formats = [];
    for ($column = 0; $column < $width; $column++) {
        $headingText = strtolower(trim((string) ($header[$column] ?? '')));
        foreach ($identifier as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $headingText) === 1) {
                $formats[$column] = 'text';
                continue 2;
            }
        }
        $sawValue = false;
        $allNumeric = true;
        $decimals = 0;
        foreach ($body as $row) {
            $cell = array_values((array) $row)[$column] ?? '';
            if ($cell === null || (is_string($cell) && trim($cell) === '')) {
                continue;
            }
            $text = trim((string) $cell);
            if (preg_match('/^-?[\d,]+(\.\d+)?$/', $text) !== 1) {
                $allNumeric = false;
                break;
            }
            $sawValue = true;
            $dot = strrpos($text, '.');
            if ($dot !== false) {
                $decimals = max($decimals, strlen($text) - $dot - 1);
            }
        }
        if (!$sawValue || !$allNumeric) {
            $formats[$column] = 'text';
            continue;
        }
        if ($decimals > 0) {
            $formats[$column] = $decimals <= 2 ? 'money' : 'weight';
            continue;
        }

        // NO DECIMAL POINT ANYWHERE, which on its own says "whole numbers" and
        // usually means a count. But a quiet month of round figures — or a shop
        // whose amounts all happen to end in .00 — would print a column of
        // rupees as bare integers, and a money column that sometimes shows
        // paisa and sometimes does not is the kind of inconsistency a reader
        // blames on the data.
        //
        // The heading is only consulted HERE, on a column already proved to
        // hold nothing but numbers, so it can never drag text into a format.
        $heading = strtolower(trim((string) ($header[$column] ?? '')));
        $saysMoney = ['amount', 'value', 'total', 'revenue', 'profit', 'cost', 'price',
            'billed', 'settled', 'outstanding', 'payable', 'receivable', 'advance',
            'making', 'wages', 'wastage', 'charge', 'balance', 'vat', 'tax', 'discount'];
        $saysWeight = ['weight', 'wt', 'fine', 'gross', 'net', 'carat', 'purity'];
        foreach ($saysWeight as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $heading) === 1) {
                $formats[$column] = 'weight';
                continue 2;
            }
        }
        foreach ($saysMoney as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $heading) === 1) {
                $formats[$column] = 'money';
                continue 2;
            }
        }
        $formats[$column] = 'count';
    }

    return $formats;
}

/**
 * Column widths wide enough to read, narrow enough to print.
 *
 * A default 18 across the board is why "Total sales and pro…" was clipped in
 * one column while a column of dates wasted half its space. Measured from the
 * longest cell, with a floor so a one-character column is still clickable and a
 * ceiling so one long note cannot push the last column off the page.
 *
 * @param  array<int, array<int, mixed>> $rows
 * @return array<int, float>
 */
function export_column_widths(array $rows, int $min = 10, int $max = 46): array
{
    $widths = [];
    foreach ($rows as $row) {
        foreach (array_values((array) $row) as $columnIndex => $value) {
            $length = mb_strlen(trim((string) ($value ?? '')));
            // Money gains separators when Excel formats it, so a raw 310649022.2
            // needs the room its 310,649,022.20 will actually take.
            $widths[$columnIndex] = max($widths[$columnIndex] ?? 0, $length + 3);
        }
    }
    foreach ($widths as $columnIndex => $width) {
        $widths[$columnIndex] = (float) max($min, min($max, $width));
    }
    ksort($widths);

    return $widths;
}
/**
 * The block that says WHAT this file is, above the table.
 *
 * A spreadsheet that opens on a bare grid of figures cannot be filed, checked
 * or argued from: three months later nobody can tell which report it was, whose
 * books it came out of, what period it covers, or whether it predates the
 * correction they are looking for. The PDF has carried this since it was
 * written. The workbook and the CSV went out naked.
 *
 * Four things, in the order somebody reads them:
 *   the REPORT, because that is what they went looking for
 *   the ORGANISATION, because one accountant keeps several sets of books
 *   the PERIOD and whatever else the caller passed as meta
 *   the DATE IT WAS PRINTED, which is the one a stale copy is caught by
 *
 * A blank row closes it, so the table below reads as a table rather than as
 * four more rows of data.
 *
 * @return array<int, array<int, string>>
 */
function export_title_rows(string $title, array $meta = []): array
{
    $companyName = '';
    if (function_exists('current_company')) {
        $company = current_company();
        $companyName = trim((string) ($company['name'] ?? ''));
    }

    // Several callers already pass the organisation as meta. Taken from there
    // when they do, so the block does not print the same name twice under two
    // different labels.
    foreach (['Company', 'Organisation', 'Organization'] as $metaKey) {
        if (trim((string) ($meta[$metaKey] ?? '')) !== '') {
            $companyName = trim((string) $meta[$metaKey]);
            unset($meta[$metaKey]);
            break;
        }
    }

    $rows = [[$title]];
    // Always present, even when there is no company in context, so the row
    // numbering below the block never shifts between one export and the next.
    $rows[] = [$companyName !== '' ? $companyName : '—'];
    foreach ($meta as $label => $value) {
        $rows[] = [(string) $label, (string) $value];
    }
    $rows[] = ['Printed', date('Y-m-d H:i')];
    $rows[] = [];

    return $rows;
}
function export_dispatch(string $format, string $basename, array $rows, string $title, array $meta = [], array $options = []): void
{
    // THE WRITER COULD ALWAYS DO THIS; nothing ever asked it to. Every workbook
    // went out as a bare grid — no widths, no number formats, no frozen head —
    // because export_dispatch() handed over the rows and none of the options.
    // Set here rather than at each of the call sites so one report cannot come
    // out looking like a different application from the next.
    $sheetOptions = $options + [
        'styled_table' => true,
        'freeze_header' => true,
        'auto_filter' => true,
    ];
    if (!isset($sheetOptions['column_formats'])) {
        $sheetOptions['column_formats'] = export_column_formats($rows);
    }
    // A totals row footed by export_totals_row() is the last row and says
    // "Total" in one of its first two cells. Found rather than passed, so a
    // caller that foots its own rows still gets the band.
    if (!array_key_exists('total_row', $sheetOptions) && count($rows) > 1) {
        $last = array_values((array) end($rows));
        $firstTwo = array_map(static fn ($v): string => strtolower(trim((string) $v)), array_slice($last, 0, 2));
        $sheetOptions['total_row'] = in_array('total', $firstTwo, true) ? count($rows) - 1 : -1;
    }
    // Widths and formats are measured from the DATA, before the title block is
    // put on top: a report called "Jewellery — Purchases, Sales & Bills" would
    // otherwise stretch column A to fit its own name.
    $sheetWidths = $options['col_widths'] ?? export_column_widths($rows);

    // The PDF builds its own heading, so only the two file formats need this.
    $titleRows = empty($options['no_title_block']) ? export_title_rows($title, $meta) : [];
    if ($titleRows !== []) {
        $sheetOptions['header_row'] = count($titleRows);
        if (($sheetOptions['total_row'] ?? -1) >= 0) {
            $sheetOptions['total_row'] += count($titleRows);
        }
    }
    $fileRows = array_merge($titleRows, $rows);

    switch ($format) {
        case 'xlsx':
            export_xlsx($basename . '.xlsx', $fileRows, $title, $sheetWidths, $sheetOptions);
            // no break — export_xlsx exits
        case 'print':
        case 'pdf':
            export_print($title, $rows, $meta, $options + ['auto_print' => true]);
            // no break — export_print exits
        default:
            export_csv($basename . '.csv', $fileRows);
    }
}
