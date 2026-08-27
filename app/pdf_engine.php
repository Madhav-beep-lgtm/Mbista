<?php
declare(strict_types=1);

/**
 * A PDF writer for reports, written here because nothing else in this project
 * writes one.
 *
 * There is no PDF library available and no package manager to fetch one with,
 * so this file does for PDF what export_engine.php already does for xlsx: it
 * emits the format directly. That is less mad than it sounds. A PDF is a text
 * file with a byte-offset table at the end; the only genuinely fiddly parts are
 * the cross-reference offsets and the text metrics, and both are handled below.
 *
 * WHAT IT WILL AND WILL NOT DO
 *
 * It draws tabular reports: a letterhead, a note, a bordered table with a bold
 * heading that repeats on every page, right-aligned numbers, emphasised total
 * rows, and a page footer. That is the whole shape of every report this app
 * produces, so it is the whole shape this writer supports.
 *
 * It uses the two standard fonts every reader has built in (Helvetica and
 * Helvetica-Bold) and embeds nothing. That keeps the files small and means they
 * open anywhere, at the cost of being limited to the WinAnsi character set.
 * Text outside that set is transliterated where there is an obvious equivalent
 * and replaced otherwise -- see pdf_winansi(). A report full of Devanagari
 * would need an embedded font, which is a different and much larger job; the
 * company names, ledger names and numerals this app prints are Latin.
 *
 * Sections come in the same shape the workbook builder already uses:
 *
 *   ['title' => string, 'note' => string,
 *    'columns' => [[key, label, 'left'|'right'], ...],
 *    'rows' => [[key => value, ..., 'emphasis' => ''|'total'|'day'], ...],
 *    'totals' => [key => value, ...]]
 *
 * so one report definition feeds the screen, the workbook and the PDF, and the
 * three cannot drift apart.
 */

/** Standard-14 Helvetica advance widths, per 1000 units of em. */
function pdf_font_widths(bool $bold): array
{
    static $cache = [];
    $key = $bold ? 'b' : 'r';
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    // ASCII 32..126, in order. These are the published AFM values; getting them
    // wrong shows up as ragged right-alignment rather than as an error, which
    // is exactly the kind of fault that survives for years.
    $regular = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,
        667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,
        278,278,278,469,556,333,
        556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,
        334,260,334,584];
    $boldWidths = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,
        722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,
        333,278,333,584,556,333,
        556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,
        389,280,389,584];
    $table = [];
    foreach (($bold ? $boldWidths : $regular) as $offset => $width) {
        $table[32 + $offset] = $width;
    }
    $cache[$key] = $table;

    return $table;
}

/**
 * Fold text down to the WinAnsi range the standard fonts can actually draw.
 *
 * The alternative is a question mark where an en dash was meant, which makes a
 * finished report look broken. Anything with no sensible Latin equivalent is
 * replaced rather than dropped, so the loss is visible instead of silent.
 */
function pdf_winansi(string $text): string
{
    // WinAnsi is NOT Latin-1: it fills 0x80-0x9F with the typographic
    // punctuation Latin-1 leaves empty. So a dash stays a dash and a curly
    // quote stays curly, rather than being flattened to ASCII.
    static $map = [
        "\xe2\x80\x94" => "\x97", "\xe2\x80\x93" => "\x96", "\xe2\x80\x92" => "\x96",
        "\xe2\x80\x98" => "\x91", "\xe2\x80\x99" => "\x92", "\xe2\x80\x9a" => "\x82",
        "\xe2\x80\x9c" => "\x93", "\xe2\x80\x9d" => "\x94", "\xe2\x80\x9e" => "\x84",
        "\xe2\x80\xa6" => "\x85", "\xe2\x80\xa2" => "\x95", "\xe2\x82\xac" => "\x80",
        "\xc2\xa0" => ' ', "\xe2\x86\x92" => '->', "\xe2\x82\xa8" => 'Rs',
        "\xe2\x88\x92" => '-',
    ];
    $text = strtr($text, $map);
    $out = '';
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
        $byte = ord($text[$i]);
        if ($byte < 0x80) {
            $out .= $text[$i];
            continue;
        }
        $extra = $byte >= 0xF0 ? 3 : ($byte >= 0xE0 ? 2 : ($byte >= 0xC0 ? 1 : 0));
        if ($extra === 0) {
            // A high byte that starts no UTF-8 sequence is either already
            // WinAnsi (the punctuation substituted above) or corrupt. Keep the
            // printable ones; anything below 0xA0 that got here is corrupt.
            $out .= $byte >= 0x80 && $byte !== 0x81 && $byte !== 0x8D ? $text[$i] : '?';
            continue;
        }
        // Multi-byte UTF-8: decode it, keep it if Latin-1 can hold it.
        $code = $extra === 1 ? ($byte & 0x1F) : ($extra === 2 ? ($byte & 0x0F) : ($byte & 0x07));
        for ($j = 1; $j <= $extra && $i + $j < $length; $j++) {
            $code = ($code << 6) | (ord($text[$i + $j]) & 0x3F);
        }
        $i += $extra;
        $out .= ($code >= 0xA0 && $code <= 0xFF) ? chr($code) : '?';
    }

    return $out;
}

/** Width of a string at a given size, in points. */
function pdf_text_width(string $text, float $size, bool $bold = false): float
{
    $widths = pdf_font_widths($bold);
    $total = 0;
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
        $total += $widths[ord($text[$i])] ?? 556;
    }

    return $total * $size / 1000;
}

/** Escape the three characters that mean something inside a PDF string. */
function pdf_escape(string $text): string
{
    return strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => ' ', "\n" => ' ']);
}

/**
 * Break text to a width, on word boundaries where it can and mid-word where a
 * single word is longer than the column.
 *
 * @return string[]
 */
function pdf_wrap(string $text, float $width, float $size, bool $bold = false): array
{
    // A hair of tolerance, because a column is often sized to exactly the width
    // of its heading and the two figures are arrived at by different sums. On a
    // strict comparison "Discount" in a column measured for "Discount" wrapped
    // to "Discoun" and "t", which is how this tolerance came to be here.
    $width += 0.05;
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($text === '') {
        return [''];
    }
    if (pdf_text_width($text, $size, $bold) <= $width) {
        return [$text];
    }
    $lines = [];
    $line = '';
    foreach (explode(' ', $text) as $word) {
        $trial = $line === '' ? $word : $line . ' ' . $word;
        if (pdf_text_width($trial, $size, $bold) <= $width) {
            $line = $trial;
            continue;
        }
        if ($line !== '') {
            $lines[] = $line;
            $line = '';
        }
        // A word that cannot fit on a line of its own is cut, because the
        // alternative is a cell that silently overruns its neighbour.
        while (pdf_text_width($word, $size, $bold) > $width && strlen($word) > 1) {
            $cut = strlen($word);
            while ($cut > 1 && pdf_text_width(substr($word, 0, $cut), $size, $bold) > $width) {
                $cut--;
            }
            $lines[] = substr($word, 0, $cut);
            $word = substr($word, $cut);
        }
        $line = $word;
    }
    if ($line !== '') {
        $lines[] = $line;
    }

    return $lines === [] ? [''] : $lines;
}

/** How far in a label sits, given the leading spaces it arrived with. */
function pdf_indent_width(int $spaces, float $size): float
{
    return $spaces > 0 ? pdf_text_width(str_repeat(' ', $spaces), $size) : 0.0;
}

/** The Reports Center colour board, as PDF device-RGB fractions. */
function pdf_palette(): array
{
    return [
        'navy'  => [0.086, 0.196, 0.365],  // #16325D, the heading colour
        'muted' => [0.400, 0.439, 0.522],  // #667085, notes and the footer
        'ink'   => [0.122, 0.161, 0.216],  // #1F2937, body text
        'head'  => [0.933, 0.953, 0.980],  // #EEF3FA, the heading band
        'total' => [0.945, 0.961, 0.984],  // #F1F5FB, a totals row
        'rule'  => [0.796, 0.839, 0.898],  // the grid
    ];
}

const PDF_MARGIN = 34.0;
const PDF_A4_SHORT = 595.28;
const PDF_A4_LONG = 841.89;

function pdf_op_text(float $x, float $y, string $text, float $size, bool $bold, array $rgb): string
{
    return sprintf("BT /%s %.2f Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET\n",
        $bold ? 'F2' : 'F1', $size, $rgb[0], $rgb[1], $rgb[2], $x, $y, pdf_escape($text));
}

function pdf_op_fill(float $x, float $y, float $w, float $h, array $rgb): string
{
    return sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n", $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h);
}

function pdf_op_line(float $x1, float $y1, float $x2, float $y2, array $rgb, float $weight = 0.5): string
{
    return sprintf("%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n",
        $rgb[0], $rgb[1], $rgb[2], $weight, $x1, $y1, $x2, $y2);
}

/**
 * How one value prints.
 *
 * Null is UNKNOWN and prints as a dash; nought is a real zero and prints as
 * one. On an uncosted line that difference is the entire point, so it survives
 * into the PDF exactly as it appears on screen.
 */
function pdf_default_cell($value, string $key): string
{
    if ($value === null || $value === '') {
        return "\xe2\x80\x94";
    }
    if (is_string($value)) {
        return $value;
    }
    if (preg_match('/pct|share/i', $key) === 1) {
        return number_format((float) $value, 2) . '%';
    }
    if (preg_match('/qty|people|lines|invoices|movements|headcount/i', $key) === 1) {
        return number_format((float) $value, 3);
    }

    return number_format((float) $value, 2);
}

/**
 * Lay one section out over as many pages as it needs, appending to $pages.
 *
 * Column widths come from the content: each column asks for what its widest
 * cell needs, and if the total will not fit, the TEXT columns give way and the
 * number columns keep theirs. A wrapped sentence is still readable; a truncated
 * figure is simply wrong.
 */
function pdf_render_section(array $section, array $chrome, array &$pages): void
{
    $palette = pdf_palette();
    $columns = array_values((array) ($section['columns'] ?? []));
    $count = max(1, count($columns));
    $landscape = $count >= 6;
    $pageW = $landscape ? PDF_A4_LONG : PDF_A4_SHORT;
    $pageH = $landscape ? PDF_A4_SHORT : PDF_A4_LONG;
    $size = $count > 9 ? 7.0 : ($count > 6 ? 7.6 : 8.6);
    $lineH = $size * 1.34;
    $padX = 4.0;
    $padY = 3.4;
    $usable = $pageW - 2 * PDF_MARGIN;
    $cell = $chrome['cell'];

    // --- every cell as the string it will actually print ------------------
    $headers = [];
    $aligns = [];
    foreach ($columns as $index => $column) {
        $headers[$index] = pdf_winansi((string) ($column[1] ?? ''));
        $aligns[$index] = (($column[2] ?? 'left') === 'right') ? 'right' : 'left';
    }
    // A statement says what a line IS by how far it is indented, and those
    // indents arrive as leading spaces on the label. Wrapping trims whitespace,
    // so the depth is taken off here and re-applied as a real offset -- without
    // it a category sits flush against the heading it belongs under.
    $body = [];
    foreach ((array) ($section['rows'] ?? []) as $row) {
        $cells = [];
        $depth = [];
        foreach ($columns as $index => $column) {
            $key = (string) ($column[0] ?? '');
            $text = pdf_winansi($cell($row[$key] ?? null, $key));
            $depth[$index] = $aligns[$index] === 'left' ? strlen($text) - strlen(ltrim($text, ' ')) : 0;
            $cells[$index] = ltrim($text, ' ');
        }
        $body[] = ['cells' => $cells, 'depth' => $depth, 'emphasis' => (string) ($row['emphasis'] ?? '')];
    }
    if ($body === []) {
        $blank = array_fill(0, $count, '');
        $blank[0] = 'Nothing recorded for this period.';
        $body[] = ['cells' => $blank, 'depth' => array_fill(0, $count, 0), 'emphasis' => ''];
    }
    if ((array) ($section['totals'] ?? []) !== []) {
        $cells = [];
        foreach ($columns as $index => $column) {
            $key = (string) ($column[0] ?? '');
            $cells[$index] = $index === 0 ? 'TOTAL' : pdf_winansi($cell($section['totals'][$key] ?? null, $key));
        }
        $body[] = ['cells' => $cells, 'depth' => array_fill(0, $count, 0), 'emphasis' => 'total'];
    }

    // --- what each column asks for, and what it gets -----------------------
    //
    // Two numbers per column. PREFERRED is what it takes to print everything on
    // one line. MINIMUM is the widest thing in it that cannot be broken: the
    // longest word of the heading, and — for a number column — the widest
    // figure, because a wrapped figure is not a smaller figure, it is a wrong
    // one. Between the two the columns give way in proportion; below the
    // minimum the type gets smaller instead of the table getting cut.
    $widest = static function (string $text, float $at, bool $bold): float {
        $most = 0.0;
        foreach (explode(' ', $text) as $word) {
            $most = max($most, pdf_text_width($word, $at, $bold));
        }

        return $most;
    };
    $fit = static function (float $at) use ($headers, $body, $aligns, $padX, $usable, $widest): ?array {
        $pref = [];
        $min = [];
        foreach ($headers as $index => $header) {
            $prefWidth = pdf_text_width($header, $at, true);
            $minWidth = $widest($header, $at, true);
            foreach ($body as $row) {
                $text = $row['cells'][$index] ?? '';
                $inset = pdf_indent_width((int) ($row['depth'][$index] ?? 0), $at);
                $prefWidth = max($prefWidth, $inset + pdf_text_width($text, $at, false));
                $minWidth = max($minWidth, $inset + ($aligns[$index] === 'right'
                    ? pdf_text_width($text, $at, false)   // atomic: never wrap a figure
                    : $widest($text, $at, false)));
            }
            $pref[$index] = max(26.0, $prefWidth + 2 * $padX);
            $min[$index] = max(26.0, min($pref[$index], $minWidth + 2 * $padX));
        }
        if (array_sum($pref) <= $usable) {
            return $pref;
        }
        $floor = array_sum($min);
        if ($floor > $usable) {
            return null;   // even unbreakable content does not fit at this size
        }
        // Slide every column from its minimum toward its preferred width by the
        // same fraction, so no one column absorbs the whole squeeze.
        $room = array_sum($pref) - $floor;
        $take = $room > 0 ? ($usable - $floor) / $room : 0.0;
        $out = [];
        foreach ($pref as $index => $width) {
            $out[$index] = $min[$index] + ($width - $min[$index]) * $take;
        }

        return $out;
    };

    $widths = null;
    foreach ([$size, $size - 0.6, $size - 1.2, 6.0] as $attempt) {
        $widths = $fit($attempt);
        if ($widths !== null) {
            $size = $attempt;
            $lineH = $size * 1.34;
            break;
        }
    }
    if ($widths === null) {
        // More columns than a sheet of paper holds. Scale everything down and
        // accept the wrapping — there is no honest layout left at this point.
        $size = 6.0;
        $lineH = $size * 1.34;
        $widths = [];
        foreach ($headers as $index => $header) {
            $widths[$index] = 1.0;
        }
        $share = $usable / max(1, count($widths));
        foreach ($widths as $index => $ignored) {
            $widths[$index] = $share;
        }
    }

    // --- wrap every cell to its column, so row heights are known -----------
    $headerLines = [];
    $headerHeight = 0.0;
    foreach ($headers as $index => $header) {
        $headerLines[$index] = pdf_wrap($header, $widths[$index] - 2 * $padX, $size, true);
        $headerHeight = max($headerHeight, count($headerLines[$index]) * $lineH);
    }
    $headerHeight += 2 * $padY;
    foreach ($body as $rowIndex => $row) {
        $height = 0.0;
        $lines = [];
        foreach ($row['cells'] as $index => $text) {
            $inset = pdf_indent_width((int) ($row['depth'][$index] ?? 0), $size);
            $lines[$index] = pdf_wrap($text, ($widths[$index] ?? 60) - 2 * $padX - $inset, $size, $row['emphasis'] === 'total');
            $height = max($height, count($lines[$index]) * $lineH);
        }
        $body[$rowIndex]['lines'] = $lines;
        $body[$rowIndex]['height'] = $height + 2 * $padY;
    }

    // --- draw ---------------------------------------------------------------
    $bottom = PDF_MARGIN + 22.0;   // room for the footer
    $open = false;
    $y = 0.0;
    $tableTop = 0.0;
    $ops = '';
    $tableWidth = array_sum($widths);

    $closePage = static function () use (&$pages, &$ops, &$open, &$tableTop, &$y, $widths, $tableWidth, $palette, $pageW, $pageH): void {
        if (!$open) {
            return;
        }
        // The verticals go on last, once the page knows how far down its table
        // reached. Drawing them row by row would be the same ink at ten times
        // the instructions.
        $x = PDF_MARGIN;
        $rules = pdf_op_line($x, $tableTop, $x, $y, $palette['rule']);
        foreach ($widths as $width) {
            $x += $width;
            $rules .= pdf_op_line($x, $tableTop, $x, $y, $palette['rule']);
        }
        $rules .= pdf_op_line(PDF_MARGIN, $tableTop, PDF_MARGIN + $tableWidth, $tableTop, $palette['rule']);
        $pages[] = ['w' => $pageW, 'h' => $pageH, 'ops' => $ops . $rules];
        $ops = '';
        $open = false;
    };

    $drawHeader = static function () use (&$ops, &$y, &$tableTop, $headerLines, $headerHeight, $widths, $tableWidth, $aligns, $palette, $size, $lineH, $padX, $padY): void {
        $tableTop = $y;
        $ops .= pdf_op_fill(PDF_MARGIN, $y - $headerHeight, $tableWidth, $headerHeight, $palette['head']);
        $x = PDF_MARGIN;
        foreach ($headerLines as $index => $lines) {
            $lineY = $y - $padY - $size;
            foreach ($lines as $line) {
                $textX = $aligns[$index] === 'right'
                    ? $x + $widths[$index] - $padX - pdf_text_width($line, $size, true)
                    : $x + $padX;
                $ops .= pdf_op_text($textX, $lineY, $line, $size, true, $palette['navy']);
                $lineY -= $lineH;
            }
            $x += $widths[$index];
        }
        $y -= $headerHeight;
        $ops .= pdf_op_line(PDF_MARGIN, $y, PDF_MARGIN + $tableWidth, $y, $palette['rule'], 0.8);
    };

    $startPage = function (bool $first) use (&$ops, &$y, &$open, $chrome, $section, $palette, $pageH, $usable, $drawHeader): void {
        $open = true;
        $y = $pageH - PDF_MARGIN;
        if ($first) {
            if ($chrome['company'] !== '') {
                $ops .= pdf_op_text(PDF_MARGIN, $y - 12, $chrome['company'], 12.5, true, $palette['navy']);
                $y -= 19;
            }
            $ops .= pdf_op_text(PDF_MARGIN, $y - 11, pdf_winansi((string) ($section['title'] ?? '')), 11, true, $palette['ink']);
            $y -= 17;
            if ($chrome['period'] !== '') {
                $ops .= pdf_op_text(PDF_MARGIN, $y - 8, $chrome['period'], 8.4, false, $palette['muted']);
                $y -= 13;
            }
            $note = pdf_winansi((string) ($section['note'] ?? ''));
            if ($note !== '') {
                // The caveat travels with the sheet. Split off into its own
                // file, a recipe estimate reads exactly like a posted cost.
                foreach (pdf_wrap($note, $usable, 7.4) as $line) {
                    $ops .= pdf_op_text(PDF_MARGIN, $y - 7, $line, 7.4, false, $palette['muted']);
                    $y -= 9.6;
                }
            }
            $y -= 6;
        } else {
            $ops .= pdf_op_text(PDF_MARGIN, $y - 10, pdf_winansi((string) ($section['title'] ?? '')) . ' (continued)',
                10, true, $palette['ink']);
            $y -= 20;
        }
        $drawHeader();
    };

    $startPage(true);
    foreach ($body as $row) {
        if ($y - $row['height'] < $bottom) {
            $closePage();
            $startPage(false);
        }
        $rowTop = $y;
        $isTotal = $row['emphasis'] === 'total';
        if ($isTotal) {
            $ops .= pdf_op_fill(PDF_MARGIN, $y - $row['height'], $tableWidth, $row['height'], $palette['total']);
        }
        $x = PDF_MARGIN;
        foreach ($row['lines'] as $index => $lines) {
            $lineY = $rowTop - $padY - $size;
            $inset = pdf_indent_width((int) ($row['depth'][$index] ?? 0), $size);
            foreach ($lines as $line) {
                $textX = ($aligns[$index] ?? 'left') === 'right'
                    ? $x + $widths[$index] - $padX - pdf_text_width($line, $size, $isTotal)
                    : $x + $padX + $inset;
                $ops .= pdf_op_text($textX, $lineY, $line, $size, $isTotal, $isTotal ? $palette['navy'] : $palette['ink']);
                $lineY -= $lineH;
            }
            $x += $widths[$index] ?? 0;
        }
        $y -= $row['height'];
        $ops .= pdf_op_line(PDF_MARGIN, $y, PDF_MARGIN + $tableWidth, $y, $palette['rule']);
    }
    $closePage();
}

/**
 * Build the file.
 *
 * A PDF is a set of numbered objects followed by a table of the byte offset
 * each one starts at. Those offsets are the one part that has to be exact -- a
 * reader seeks by them -- which is why they are measured off the output as it
 * is assembled rather than predicted.
 */
function pdf_assemble(array $pages): string
{
    $objects = [];
    $kids = [];
    $pageObject = 5;
    foreach ($pages as $page) {
        $contentObject = $pageObject + 1;
        $stream = (string) $page['ops'];
        $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '
            . sprintf('%.2f %.2f', $page['w'], $page['h']) . ']'
            . ' /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >>'
            . ' /Contents ' . $contentObject . ' 0 R >>';
        $objects[$contentObject] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
        $kids[] = $pageObject . ' 0 R';
        $pageObject += 2;
    }
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    ksort($objects);

    $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $number => $bodyText) {
        $offsets[$number] = strlen($out);
        $out .= $number . " 0 obj\n" . $bodyText . "\nendobj\n";
    }
    $startXref = strlen($out);
    $highest = max(array_keys($objects));
    $out .= "xref\n0 " . ($highest + 1) . "\n0000000000 65535 f \n";
    for ($number = 1; $number <= $highest; $number++) {
        $out .= sprintf("%010d 00000 n \n", $offsets[$number] ?? 0);
    }
    $out .= "trailer\n<< /Size " . ($highest + 1) . " /Root 1 0 R >>\nstartxref\n" . $startXref . "\n%%EOF\n";

    return $out;
}

/**
 * One or more report sections as a PDF.
 *
 * @param array $sections list of sections in the shape described at the top
 * @param array $meta     company_name, period, generated, and an optional
 *                        'cell' callable that turns a value into its printed
 *                        text. Pass the report's own formatter so the PDF and
 *                        the screen cannot disagree about what a figure says.
 */
function pdf_document(array $sections, array $meta = []): string
{
    $palette = pdf_palette();
    $chrome = [
        'company' => pdf_winansi(strtoupper(trim((string) ($meta['company_name'] ?? '')))),
        'period' => pdf_winansi(trim((string) ($meta['period'] ?? ''))),
        'cell' => is_callable($meta['cell'] ?? null) ? $meta['cell'] : 'pdf_default_cell',
    ];

    $pages = [];
    foreach ($sections as $section) {
        pdf_render_section((array) $section, $chrome, $pages);
    }
    if ($pages === []) {
        $pages[] = ['w' => PDF_A4_SHORT, 'h' => PDF_A4_LONG,
            'ops' => pdf_op_text(PDF_MARGIN, PDF_A4_LONG - PDF_MARGIN - 12, 'Nothing to report.', 11, true, $palette['ink'])];
    }

    // The footer needs the page count, which is only known once every section
    // has been laid out.
    $footer = pdf_winansi(trim((string) ($meta['generated'] ?? '')));
    $count = count($pages);
    foreach ($pages as $index => $page) {
        $y = PDF_MARGIN - 2;
        $ops = pdf_op_line(PDF_MARGIN, $y + 13, $page['w'] - PDF_MARGIN, $y + 13, $palette['rule'], 0.4);
        if ($footer !== '') {
            $ops .= pdf_op_text(PDF_MARGIN, $y, $footer, 7, false, $palette['muted']);
        }
        $label = 'Page ' . ($index + 1) . ' of ' . $count;
        $ops .= pdf_op_text($page['w'] - PDF_MARGIN - pdf_text_width($label, 7), $y, $label, 7, false, $palette['muted']);
        $pages[$index]['ops'] .= $ops;
    }

    return pdf_assemble($pages);
}

/** Send a PDF as a download and stop. */
function pdf_download(string $bytes, string $filename): void
{
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes;
    exit;
}
