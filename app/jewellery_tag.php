<?php
declare(strict_types=1);

/**
 * Jewellery tags for a Zebra ZD230 — the ZPL behind the barcode strip that is
 * tied to every piece in the showcase.
 *
 * The tag is the dumbbell strip a jeweller wraps round a ring: a body carrying
 * the weights, the shop name and what the piece is, and a barcode at each end so
 * it scans whichever way it ends up folded. It reads like this:
 *
 *     G.WT. : 3.620 GM
 *     Stone :
 *     N.WT  : 3.620 GM
 *     GANDAKI JEWELLERS
 *     Gents Authi
 *     [|||||||] GEA240        <- item code, scans into billing
 *     [|||||||] 24K           <- purity
 *
 * NOTHING about the geometry is hard-coded. Tag stock is bought by the roll and
 * comes in whatever size the supplier had; the measurements live in
 * jewellery_settings and the print screen prints a calibration tag so they can
 * be dialled in against the real thing. See migration 105.
 *
 * Sent to the printer as raw ZPL, so the ZD230 draws the barcode itself at its
 * native 203 dpi. That matters more than it sounds: a barcode rasterised by a
 * browser at the same nominal size has bar edges landing on fractional dots, and
 * the scanner is the one that finds out.
 */

// jewellery_settings() lives here. Required rather than assumed, so a screen
// that wants tags does not have to know what else to include first.
require_once __DIR__ . '/jewellery_engine.php';

/** ZPL is a byte protocol; ^ and ~ are control characters inside field data. */
function jw_tag_escape(string $text): string
{
    // _ is the hex-escape lead-in once ^FH is in play, so it goes first.
    $clean = str_replace(['\\', '^', '~'], ['\\\\', '\\5E', '\\7E'], $text);

    // A tag is 12mm of thermal paper: control characters and newlines have no
    // meaning on it and only corrupt the field.
    return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $clean));
}

/** Millimetres to printer dots, which is the only unit ZPL understands. */
function jw_tag_dots(float $mm, int $dpi): int
{
    return (int) round($mm * $dpi / 25.4);
}

/**
 * Tag settings for a company, defaults filled in.
 *
 * The shop name falls back to the company name, because that is right for most
 * shops and a blank line on a printed tag is worse than a slightly long one.
 */
function jewellery_tag_settings(int $companyId): array
{
    $settings = jewellery_settings($companyId);
    $company = db()->prepare('SELECT name FROM companies WHERE id = :cid LIMIT 1');
    $company->execute(['cid' => $companyId]);
    $companyName = (string) ($company->fetchColumn() ?: '');

    $shopName = trim((string) ($settings['tag_shop_name'] ?? ''));

    return [
        'shop_name' => $shopName !== '' ? $shopName : $companyName,
        'width_mm' => (float) ($settings['tag_width_mm'] ?? 12.0),
        'height_mm' => (float) ($settings['tag_height_mm'] ?? 75.0),
        'gap_mm' => (float) ($settings['tag_gap_mm'] ?? 3.0),
        'wing_mm' => (float) ($settings['tag_wing_mm'] ?? 22.0),
        'dpi' => max(150, (int) ($settings['tag_dpi'] ?? 203)),
        'darkness' => min(30, max(0, (int) ($settings['tag_darkness'] ?? 15))),
        'speed' => min(14, max(1, (int) ($settings['tag_speed'] ?? 3))),
        'rotation' => (string) ($settings['tag_rotation'] ?? '0'),
        'offset_x_mm' => (float) ($settings['tag_offset_x_mm'] ?? 0.0),
        'offset_y_mm' => (float) ($settings['tag_offset_y_mm'] ?? 0.0),
        'media' => (string) ($settings['tag_media'] ?? 'gap'),
        'hide_empty_stone' => (int) ($settings['tag_hide_empty_stone'] ?? 1) === 1,
        'weight_precision' => min(4, max(2, (int) ($settings['weight_precision'] ?? 3))),
    ];
}

/** ZPL's field orientation letter for a rotation in degrees. */
function jw_tag_orientation(string $rotation): string
{
    return ['0' => 'N', '90' => 'R', '180' => 'I', '270' => 'B'][$rotation] ?? 'N';
}

/**
 * The lines and barcodes a tag carries, decided from the item row.
 *
 * Kept apart from the ZPL so that what goes ON a tag can be reasoned about (and
 * tested) without reading printer control codes.
 */
function jewellery_tag_fields(array $item, array $cfg): array
{
    $decimals = (int) $cfg['weight_precision'];
    $unit = strtoupper(trim((string) ($item['unit_code'] ?? 'GM')));
    $weight = static fn (string $key): float => round((float) ($item[$key] ?? 0), 4);

    $gross = $weight('gross_weight');
    $stone = $weight('stone_weight');
    $net = $weight('net_weight');
    // A piece entered with only a gross weight and no stone has a net equal to
    // its gross. Printing 0.000 there would be read as "weighs nothing".
    if ($net <= 0.0 && $gross > 0.0) {
        $net = round($gross - $stone, 4);
    }

    $fmt = static fn (float $v): string => number_format($v, $decimals, '.', '') . ' ' . $unit;

    $lines = ['G.WT. : ' . $fmt($gross)];
    if ($stone > 0.0 || !$cfg['hide_empty_stone']) {
        $lines[] = 'Stone : ' . ($stone > 0.0 ? $fmt($stone) : '');
    }
    $lines[] = 'N.WT. : ' . $fmt($net);
    if (trim((string) $cfg['shop_name']) !== '') {
        $lines[] = (string) $cfg['shop_name'];
    }
    if (trim((string) ($item['name'] ?? '')) !== '') {
        $lines[] = (string) $item['name'];
    }

    return [
        'lines' => $lines,
        // Wing one is the item code — this is the barcode that matters, because
        // it is what the billing screen scans to find the piece.
        'barcode_1' => trim((string) ($item['sku'] ?? '')),
        // Wing two is the purity, scannable in its own right.
        'barcode_2' => trim((string) ($item['purity_code'] ?? '')),
    ];
}

/**
 * One tag as ZPL.
 *
 * Laid out down the length of the strip: the text block first, then the two
 * barcode wings. Positions are computed from the stock measurements rather than
 * fixed, so changing the tag size in settings moves everything with it.
 */
function jewellery_tag_zpl(array $item, array $cfg, int $copies = 1): string
{
    $dpi = (int) $cfg['dpi'];
    $printWidth = jw_tag_dots((float) $cfg['width_mm'], $dpi);
    $labelLength = jw_tag_dots((float) $cfg['height_mm'], $dpi);
    $wing = jw_tag_dots((float) $cfg['wing_mm'], $dpi);
    $originX = jw_tag_dots((float) $cfg['offset_x_mm'], $dpi);
    $originY = jw_tag_dots((float) $cfg['offset_y_mm'], $dpi);
    $orientation = jw_tag_orientation((string) $cfg['rotation']);
    $fields = jewellery_tag_fields($item, $cfg);
    $copies = max(1, min(999, $copies));

    // Text is sized to the space it has rather than fixed, so the same layout
    // survives a shop moving from 12mm stock to 10mm.
    $lineCount = max(1, count($fields['lines']));
    $bodyLength = max(1, $labelLength - (2 * $wing));
    $lineHeight = max(14, (int) floor($bodyLength / ($lineCount + 0.5)));
    $fontHeight = max(12, (int) floor($lineHeight * 0.78));
    $fontWidth = max(10, (int) floor($fontHeight * 0.62));
    $barHeight = max(20, (int) floor($wing * 0.55));
    $margin = jw_tag_dots(1.0, $dpi);

    $zpl = "^XA\n";
    $zpl .= "^CI28\n";                                  // UTF-8 in, so ° and ₹ survive
    $zpl .= '^PW' . $printWidth . "\n";
    $zpl .= '^LL' . $labelLength . "\n";
    $zpl .= '^LH' . $originX . ',' . $originY . "\n";
    $zpl .= $cfg['media'] === 'continuous' ? "^MNN\n" : ($cfg['media'] === 'mark' ? "^MNM\n" : "^MNY\n");
    $zpl .= '^MD' . (int) $cfg['darkness'] . "\n";
    $zpl .= '^PR' . (int) $cfg['speed'] . "\n";

    $y = $margin;
    foreach ($fields['lines'] as $line) {
        $zpl .= '^FO' . $margin . ',' . $y
            . '^A0' . $orientation . ',' . $fontHeight . ',' . $fontWidth
            . '^FD' . jw_tag_escape((string) $line) . "^FS\n";
        $y += $lineHeight;
    }

    // ^BY sets module width; 2 is the smallest that still scans reliably off
    // thermal tag stock at 203 dpi. Human-readable text is printed by the
    // printer (the Y flag) so it can never drift from the encoded value.
    foreach ([$fields['barcode_1'], $fields['barcode_2']] as $value) {
        if ($value === '') {
            continue;
        }
        $y += $margin;
        $zpl .= '^FO' . $margin . ',' . $y
            . '^BY2,3,' . $barHeight
            . '^BC' . $orientation . ',' . $barHeight . ',Y,N,N'
            . '^FD' . jw_tag_escape($value) . "^FS\n";
        $y += $barHeight + $fontHeight;
    }

    $zpl .= '^PQ' . $copies . "\n";
    $zpl .= "^XZ\n";

    return $zpl;
}

/** Several tags in one stream, so a lot is tagged in a single run. */
function jewellery_tag_batch_zpl(array $items, array $cfg, int $copies = 1): string
{
    $out = '';
    foreach ($items as $item) {
        $out .= jewellery_tag_zpl($item, $cfg, $copies);
    }

    return $out;
}

/**
 * A calibration tag: the outline of the label, a centre cross and the measured
 * size printed on it.
 *
 * This is how tag stock nobody has measured for you gets dialled in — print one,
 * hold it against the real tag, and if the border is off the edge or short of it
 * you can see by how much and in which direction. Guessing from a table of
 * standard sizes does not work, because the stock is rarely standard.
 */
function jewellery_tag_calibration_zpl(array $cfg): string
{
    $dpi = (int) $cfg['dpi'];
    $printWidth = jw_tag_dots((float) $cfg['width_mm'], $dpi);
    $labelLength = jw_tag_dots((float) $cfg['height_mm'], $dpi);
    $orientation = jw_tag_orientation((string) $cfg['rotation']);
    $thickness = max(1, (int) round($dpi / 203));

    $zpl = "^XA\n^CI28\n";
    $zpl .= '^PW' . $printWidth . "\n";
    $zpl .= '^LL' . $labelLength . "\n";
    $zpl .= '^LH' . jw_tag_dots((float) $cfg['offset_x_mm'], $dpi)
        . ',' . jw_tag_dots((float) $cfg['offset_y_mm'], $dpi) . "\n";
    $zpl .= $cfg['media'] === 'continuous' ? "^MNN\n" : ($cfg['media'] === 'mark' ? "^MNM\n" : "^MNY\n");
    $zpl .= '^MD' . (int) $cfg['darkness'] . "\n^PR" . (int) $cfg['speed'] . "\n";
    // Border: a hollow box the exact size of the label as configured.
    $zpl .= '^FO0,0^GB' . max(1, $printWidth - 1) . ',' . max(1, $labelLength - 1)
        . ',' . $thickness . "^FS\n";
    $zpl .= '^FO' . (int) ($printWidth / 2) . ',0^GB' . $thickness . ',' . $labelLength
        . ',' . $thickness . "^FS\n";
    $zpl .= '^FO4,4^A0' . $orientation . ',18,12^FD'
        . jw_tag_escape(rtrim(rtrim(number_format((float) $cfg['width_mm'], 1), '0'), '.') . ' x '
            . rtrim(rtrim(number_format((float) $cfg['height_mm'], 1), '0'), '.') . ' mm @ ' . $dpi . 'dpi')
        . "^FS\n";
    $zpl .= "^PQ1\n^XZ\n";

    return $zpl;
}
