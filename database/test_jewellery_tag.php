<?php
declare(strict_types=1);

/**
 * Jewellery tag ZPL for the Zebra ZD230.
 *
 * A tag that prints wrong is not a cosmetic problem: the barcode on it is what
 * the billing screen scans, so a mis-encoded or unscannable strip means the
 * piece cannot be sold without typing its code by hand. These check the two
 * things that decide that — what goes on the tag, and the ZPL that draws it.
 *
 *   php database/test_jewellery_tag.php
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/jewellery_tag.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

// The sample tag this was built from: 12mm across the head, 75mm along the feed.
$cfg = [
    'shop_name' => 'GANDAKI JEWELLERS',
    'width_mm' => 12.0, 'height_mm' => 75.0, 'gap_mm' => 3.0, 'wing_mm' => 22.0,
    'dpi' => 203, 'darkness' => 15, 'speed' => 3, 'rotation' => '0',
    'offset_x_mm' => 0.0, 'offset_y_mm' => 0.0, 'media' => 'gap',
    'hide_empty_stone' => true, 'weight_precision' => 3,
];
$item = [
    'sku' => 'GEA240', 'name' => 'Gents Authi', 'purity_code' => '24K',
    'unit_code' => 'gm', 'gross_weight' => 3.6200, 'stone_weight' => 0.0000, 'net_weight' => 3.6200,
];

echo "\n1. Millimetres to dots — the only unit ZPL understands\n";
ok(jw_tag_dots(12.0, 203) === 96, '12mm at 203 dpi is 96 dots (got ' . jw_tag_dots(12.0, 203) . ')');
ok(jw_tag_dots(75.0, 203) === 599, '75mm at 203 dpi is 599 dots (got ' . jw_tag_dots(75.0, 203) . ')');
ok(jw_tag_dots(12.0, 300) === 142, 'The same 12mm is 142 dots on a 300 dpi printer (got ' . jw_tag_dots(12.0, 300) . ')');
ok(jw_tag_dots(25.4, 203) === 203, 'One inch is exactly the dpi');

echo "\n2. What goes on the tag\n";
$f = jewellery_tag_fields($item, $cfg);
ok($f['lines'][0] === 'G.WT. : 3.620 GM', 'Gross weight reads as the sample does: ' . $f['lines'][0]);
ok(!in_array('Stone : ', $f['lines'], true) && !preg_grep('/^Stone/', $f['lines']),
    'A piece with no stone does not waste a line saying so');
ok(in_array('N.WT. : 3.620 GM', $f['lines'], true), 'Net weight is printed');
ok(in_array('GANDAKI JEWELLERS', $f['lines'], true), 'The shop name is on it');
ok(in_array('Gents Authi', $f['lines'], true), 'And what the piece is');
ok($f['barcode_1'] === 'GEA240' && $f['barcode_2'] === '24K',
    'Wing one carries the item code, wing two the purity');

$withStone = jewellery_tag_fields(['sku' => 'X', 'name' => 'Ring', 'purity_code' => '22K',
    'unit_code' => 'gm', 'gross_weight' => 5.0, 'stone_weight' => 1.25, 'net_weight' => 3.75], $cfg);
ok(in_array('Stone : 1.250 GM', $withStone['lines'], true), 'A piece WITH a stone prints the stone line');

// The commonest data-entry shape: gross typed, net left at zero.
$noNet = jewellery_tag_fields(['sku' => 'Y', 'name' => 'Bangle', 'purity_code' => '22K',
    'unit_code' => 'gm', 'gross_weight' => 8.0, 'stone_weight' => 1.0, 'net_weight' => 0.0], $cfg);
ok(in_array('N.WT. : 7.000 GM', $noNet['lines'], true),
    'A net of zero is derived as gross minus stone, not printed as 0.000');

$tola = jewellery_tag_fields(['sku' => 'Z', 'name' => 'Chain', 'purity_code' => '24K',
    'unit_code' => 'tola', 'gross_weight' => 1.5, 'stone_weight' => 0, 'net_weight' => 1.5], $cfg);
ok(in_array('G.WT. : 1.500 TOLA', $tola['lines'], true), 'The unit on the tag is the item\'s own unit');

echo "\n3. The ZPL itself\n";
$zpl = jewellery_tag_zpl($item, $cfg);
ok(str_starts_with($zpl, '^XA') && str_ends_with(trim($zpl), '^XZ'), 'It is a complete label between ^XA and ^XZ');
ok(str_contains($zpl, '^CI28'), 'UTF-8 is selected, so a rupee sign or a degree survives');
ok(str_contains($zpl, '^PW96'), 'Print width is the 12mm across the head, in dots');
ok(str_contains($zpl, '^LL599'), 'Label length is the 75mm along the feed, in dots');
ok(str_contains($zpl, '^MNY'), 'Gap sensing for die-cut tag stock');
ok(str_contains($zpl, '^MD15') && str_contains($zpl, '^PR3'), 'Darkness and speed come from settings');
ok(str_contains($zpl, '^FDGEA240^FS'), 'The item code is encoded');
ok(str_contains($zpl, '^FD24K^FS'), 'The purity is encoded');
ok(substr_count($zpl, '^BC') === 2, 'Two Code 128 barcodes, one per wing (got ' . substr_count($zpl, '^BC') . ')');
ok(str_contains($zpl, '^BCN,'), 'Barcodes take the configured orientation');
ok(str_contains($zpl, ',Y,N,N'), 'The printer prints the human-readable text, so it cannot drift from the bars');
ok(str_contains($zpl, '^PQ1'), 'One copy by default');
ok(str_contains(jewellery_tag_zpl($item, $cfg, 3), '^PQ3'), 'And the quantity is honoured');

echo "\n4. Nothing on a tag can break the printer's parser\n";
$nasty = jewellery_tag_zpl(['sku' => 'A^B', 'name' => 'Ring ~ Fancy', 'purity_code' => '18K',
    'unit_code' => 'gm', 'gross_weight' => 1, 'stone_weight' => 0, 'net_weight' => 1], $cfg);
ok(!str_contains($nasty, 'A^B'), 'A caret in a code is escaped, not left to end the field');
ok(!str_contains($nasty, '~ Fancy'), 'And so is a tilde');
ok(str_contains($nasty, '\\5E') && str_contains($nasty, '\\7E'), 'They go through as hex escapes');
ok(jw_tag_escape("Ring\r\nSecond") === 'Ring Second', 'A newline pasted into a name cannot split a field');

echo "\n5. Rotation follows how the roll is wound\n";
ok(jw_tag_orientation('0') === 'N' && jw_tag_orientation('90') === 'R'
    && jw_tag_orientation('180') === 'I' && jw_tag_orientation('270') === 'B',
    'Each rotation maps to its ZPL orientation letter');
$rotated = jewellery_tag_zpl($item, array_merge($cfg, ['rotation' => '90']));
ok(str_contains($rotated, '^BCR,') && str_contains($rotated, '^A0R,'),
    'Rotating the tag rotates both the text and the barcodes');

echo "\n6. Batch and calibration\n";
$batch = jewellery_tag_batch_zpl([$item, $item, $item], $cfg);
ok(substr_count($batch, '^XA') === 3, 'A run of three items is three labels in one stream');
$cal = jewellery_tag_calibration_zpl($cfg);
ok(str_contains($cal, '^GB'), 'The calibration tag draws a border to measure against');
ok(str_contains($cal, '12 x 75 mm @ 203dpi'), 'And prints the size it believes it is: ' .
    (preg_match('/\^FD([^\^]*mm[^\^]*)\^FS/', $cal, $m) ? $m[1] : '?'));

echo "\n7. Settings are read per company, with sane fallbacks\n";
$cid = (int) (db()->query("SELECT id FROM companies WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
if ($cid > 0) {
    $live = jewellery_tag_settings($cid);
    ok($live['dpi'] >= 150 && $live['width_mm'] > 0 && $live['height_mm'] > 0, 'A real company returns usable geometry');
    ok(trim((string) $live['shop_name']) !== '', 'The shop name falls back to the company name rather than printing blank');
    ok(is_bool($live['hide_empty_stone']), 'Flags come back typed');
} else {
    echo "  (skipped — no active company in this database)\n";
}

echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail > 0 ? 1 : 0);
