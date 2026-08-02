<?php
declare(strict_types=1);

/**
 * The app icons, drawn from the same mark as assets/img/favicon.svg.
 *
 * They are generated rather than hand-drawn so the mark has ONE definition. A
 * set of PNGs pasted in from a design tool drifts the moment the favicon
 * changes, and nobody notices until the home-screen icon is a version behind
 * the one in the browser tab.
 *
 * Committed as files because a phone asks for them before PHP runs, and a
 * shared host should not be resampling an icon on every install.
 *
 *   php database/generate_pwa_icons.php
 *
 * SIZES, and why each one is there:
 *   192, 512  what a web app manifest is required to carry
 *   512 maskable  Android crops every icon to its own shape — a circle, a
 *                 squircle, a rounded square, whatever the launcher uses. A
 *                 normal icon loses its border to that crop, so the maskable
 *                 one keeps everything important inside the middle 80% and
 *                 lets the field bleed to the edge.
 *   180       apple-touch-icon. iOS ignores the manifest's icons for the home
 *             screen and reads this instead; without it the phone screenshots
 *             the page and uses that.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if (!extension_loaded('gd')) {
    exit("GD is not available — cannot draw the icons.\n");
}

$outDir = dirname(__DIR__) . '/public_html/assets/img';
if (!is_dir($outDir)) {
    exit("No such directory: $outDir\n");
}

// Straight off favicon.svg.
$FIELD = [0x0c, 0x4a, 0x6e];
$EDGE = [0xd9, 0xa3, 0x3a];
$INK = [0xff, 0xff, 0xff];
$TEXT = 'MB';

/** A bold serif if the box has one, else anything bold, else GD's own. */
function icon_font(): ?string
{
    foreach ([
        'C:/Windows/Fonts/georgiab.ttf',
        'C:/Windows/Fonts/arialbd.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSerif-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    ] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @param bool $maskable keep the mark inside the middle 80%, and let the field
 *                       run to the edge — see the note on Android's crop above.
 */
function draw_icon(int $size, bool $maskable, string $path, array $field, array $edge, array $ink, string $text): bool
{
    $img = imagecreatetruecolor($size, $size);
    imagesavealpha($img, true);
    $bg = imagecolorallocate($img, $field[0], $field[1], $field[2]);
    $line = imagecolorallocate($img, $edge[0], $edge[1], $edge[2]);
    $fg = imagecolorallocate($img, $ink[0], $ink[1], $ink[2]);
    imagefilledrectangle($img, 0, 0, $size, $size, $bg);

    // The safe zone. Everything that must survive a crop lives inside it.
    $inset = $maskable ? (int) round($size * 0.18) : (int) round($size * 0.08);
    $thickness = max(2, (int) round($size * 0.045));
    for ($i = 0; $i < $thickness; $i++) {
        imagerectangle($img, $inset + $i, $inset + $i, $size - 1 - $inset - $i, $size - 1 - $inset - $i, $line);
    }

    $font = icon_font();
    if ($font !== null) {
        // Grown until it fills the width inside the border, so the letters are
        // the same weight on the 180 as on the 512 rather than a fixed point
        // size that looks cramped at one end and clipped at the other.
        $target = ($size - 2 * $inset) * 0.62;
        $pt = $size * 0.4;
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $box = imagettfbbox($pt, 0, $font, $text);
            $width = abs($box[2] - $box[0]);
            if ($width <= 0) {
                break;
            }
            $pt *= $target / $width;
            if (abs($width - $target) < 1.0) {
                break;
            }
        }
        $box = imagettfbbox($pt, 0, $font, $text);
        $x = (int) round(($size - abs($box[2] - $box[0])) / 2 - $box[0]);
        $y = (int) round(($size + abs($box[5] - $box[1])) / 2 - ($box[1] - $box[7] + $box[7]));
        $y = (int) round(($size / 2) + (abs($box[7]) / 2));
        imagettftext($img, $pt, 0, $x, $y, $fg, $font, $text);
    } else {
        // No TTF anywhere: draw the built-in font small and blow it up. Blocky,
        // but an icon that exists beats a 404 the phone replaces with a
        // screenshot of the page.
        $tile = imagecreatetruecolor(40, 20);
        $tbg = imagecolorallocate($tile, $field[0], $field[1], $field[2]);
        $tfg = imagecolorallocate($tile, $ink[0], $ink[1], $ink[2]);
        imagefilledrectangle($tile, 0, 0, 40, 20, $tbg);
        imagestring($tile, 5, 6, 2, $text, $tfg);
        $inner = $size - 2 * ($inset + $thickness + (int) round($size * 0.06));
        imagecopyresampled($img, $tile, $inset + $thickness + (int) round($size * 0.06),
            (int) round(($size - $inner / 2) / 2), 0, 0, $inner, (int) round($inner / 2), 40, 20);

    }

    $ok = imagepng($img, $path, 9);


    return $ok;
}

$targets = [
    ['icon-192.png', 192, false],
    ['icon-512.png', 512, false],
    ['icon-512-maskable.png', 512, true],
    ['apple-touch-icon.png', 180, false],
];
foreach ($targets as [$name, $size, $maskable]) {
    $path = $outDir . '/' . $name;
    $ok = draw_icon($size, $maskable, $path, $FIELD, $EDGE, $INK, $TEXT);
    printf("  %-26s %s  %s\n", $name, $ok ? 'written' : 'FAILED',
        $ok ? number_format((int) filesize($path)) . ' bytes' : '');
}
echo "\nFont used: " . (icon_font() ?? 'none — GD built-in fallback') . "\n";
