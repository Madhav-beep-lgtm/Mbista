<?php
declare(strict_types=1);

/**
 * Fixed-asset IFRS acceptance tests (spec section S, tests 13-17).
 * Run: php database/test_fixed_assets.php
 * Pure-function tests — no DB required.
 */

require __DIR__ . '/../app/fixed_asset_engine.php';

$pass = 0;
$fail = 0;
function check(string $label, $expected, $actual): void
{
    global $pass, $fail;
    $ok = is_float($expected) || is_float($actual)
        ? abs((float) $expected - (float) $actual) < 0.01
        : $expected === $actual;
    if ($ok) {
        $pass++;
        echo "  PASS  $label  = " . (is_float($actual) ? number_format((float) $actual, 2) : var_export($actual, true)) . "\n";
    } else {
        $fail++;
        echo "  FAIL  $label  expected " . var_export($expected, true) . " got " . var_export($actual, true) . "\n";
    }
}

echo "== S13. IAS 16 straight-line depreciation ==\n";
// Cost 1,100,000; residual 100,000; life 5y -> annual 200,000; monthly 16,666.67.
$sl = fa_straight_line(1100000, 100000, 5);
check('S13 depreciable amount', 1000000.0, $sl['depreciable']);
check('S13 annual depreciation', 200000.0, $sl['annual']);
check('S13 monthly depreciation', 16666.67, $sl['monthly']);
// Full 60-month schedule closes to residual exactly.
$sched = fa_depreciation_schedule_sl(1100000, 100000, 60);
check('S13 schedule length', 60, count($sched));
check('S13 final accumulated = depreciable', 1000000.0, $sched[59]['accumulated']);
check('S13 final carrying = residual', 100000.0, $sched[59]['carrying']);

echo "== S14. IAS 38 amortization ==\n";
// Software 600,000; residual 0; life 3y -> annual 200,000.
$am = fa_amortization(600000, 0, 3);
check('S14 annual amortization', 200000.0, $am['annual']);

echo "== S15. IFRS 16 initial ROU asset ==\n";
// Liability 900,000 + prepayment 50,000 + IDC 20,000 - incentive 10,000 = 960,000.
$rou = fa_rou_initial(900000, 50000, 20000, 0, 10000);
check('S15 initial ROU asset', 960000.0, $rou);
check('S15 annual ROU depreciation (4y)', 240000.0, fa_straight_line($rou, 0, 4)['annual']);
// A lease schedule fully amortizes the liability to zero.
$ls = fa_lease_schedule(900000, 0.10, 283897.36, 4, 'arrears');
check('S15 lease schedule closes to 0', 0.0, $ls[3]['closing']);

echo "== S16. IFRS 5 held for sale ==\n";
// Carrying 500,000; FV 480,000; costs to sell 30,000 -> FVLCS 450,000; impairment 50,000.
$hfs = fa_held_for_sale(500000, 480000, 30000);
check('S16 FVLCS', 450000.0, $hfs['fvlcs']);
check('S16 measured at', 450000.0, $hfs['measured']);
check('S16 impairment', 50000.0, $hfs['impairment']);
check('S16 depreciation stops', true, $hfs['stop_depreciation']);

echo "== S17. IAS 36 impairment ==\n";
// Carrying 800,000; FVLCD 620,000; VIU 680,000 -> recoverable 680,000; impairment 120,000.
$imp = fa_impairment(800000, 620000, 680000);
check('S17 recoverable amount', 680000.0, $imp['recoverable']);
check('S17 impairment loss', 120000.0, $imp['impairment']);
check('S17 revised carrying', 680000.0, $imp['revised_carrying']);
// Reversal capped at the would-have-been carrying (no prior impairment).
$rev = fa_impairment_reversal(680000, 900000, 760000);
check('S17b reversal capped at ceiling', 80000.0, $rev['reversal']);
check('S17b revised carrying <= ceiling', 760000.0, $rev['revised_carrying']);

echo "== Extras: diminishing balance & units of production ==\n";
check('Diminishing 20% on 100000', 20000.0, fa_diminishing_balance(100000, 0, 20));
check('Diminishing floored at residual', 5000.0, fa_diminishing_balance(15000, 10000, 50));
// depreciable = 1,000,000 - 100,000 = 900,000; 900,000 * 9,000/100,000 = 81,000.
check('Units of production', 81000.0, fa_units_of_production(1000000, 100000, 100000, 9000));

echo "\n==== RESULT: $pass passed, $fail failed ====\n";
exit($fail === 0 ? 0 : 1);
