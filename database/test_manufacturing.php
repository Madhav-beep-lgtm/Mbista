<?php
declare(strict_types=1);

/**
 * Manufacturing costing acceptance tests (IAS 2 cost accumulation + variances).
 * Run: php database/test_manufacturing.php — pure functions, no DB.
 */

require __DIR__ . '/../app/manufacturing_engine.php';

$pass = 0;
$fail = 0;
function check(string $label, $expected, $actual): void
{
    global $pass, $fail;
    $ok = abs((float) $expected - (float) $actual) < 0.005;
    if ($ok) { $pass++; echo "  PASS  $label = " . number_format((float) $actual, 2) . "\n"; }
    else { $fail++; echo "  FAIL  $label expected " . var_export($expected, true) . " got " . var_export($actual, true) . "\n"; }
}

echo "== Cost accumulation (IAS 2.10-18) ==\n";
// materials 50,000 + labour 12,000 + overhead 8,000 - by-product 2,000, abnormal waste 3,000 excluded, output 100
$c = mfg_order_cost(50000, 12000, 8000, 2000, 3000, 100);
check('inventoriable cost', 65000.0, $c['inventoriable']);   // (50000-3000)+12000+8000-2000
check('unit cost', 650.0, $c['unit_cost']);
check('abnormal waste excluded (period expense)', 3000.0, $c['excluded_abnormal']);

echo "== Standard material cost from BOM ==\n";
// BOM for 10 units: 20kg @ Rs.50 with 5% waste => allowed 21kg * 50 = 1050 per batch; produce 20 units => 2100
$lines = [['item_id' => 1, 'std_qty' => 20, 'waste_pct' => 5, 'std_rate' => 50]];
check('std material cost (2 batches)', 2100.0, mfg_standard_material_cost($lines, 10, 20));

echo "== Material variances ==\n";
// actual: 44kg @ Rs.52 (std allowed 42kg @ 50)
$v = mfg_material_variances([['item_id' => 1, 'qty' => 44, 'rate' => 52]], $lines, 10, 20);
check('price variance 44*(52-50)', 88.0, $v['price']);        // unfavourable
check('usage variance (44-42)*50', 100.0, $v['usage']);       // unfavourable

echo "== Conversion variances ==\n";
// std labour 500/batch, std OH 300/batch; 2 batches => allowed 1000/600; actual 1150/550
$cv = mfg_conversion_variances(1150, 550, 500, 300, 10, 20);
check('labour variance', 150.0, $cv['labour']);               // unfavourable
check('overhead variance', -50.0, $cv['overhead']);           // favourable

echo "\n==== RESULT: $pass passed, $fail failed ====\n";
exit($fail === 0 ? 0 : 1);
