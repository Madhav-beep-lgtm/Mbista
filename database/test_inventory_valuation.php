<?php
declare(strict_types=1);

/**
 * IAS 2 valuation acceptance tests (spec section S, tests 1-4 + reversal cap).
 * Run: php database/test_inventory_valuation.php
 * Pure-function tests — no DB required.
 */

require __DIR__ . '/../app/inventory_valuation.php';

$pass = 0;
$fail = 0;
function check(string $label, $expected, $actual): void
{
    global $pass, $fail;
    $ok = is_float($expected) || is_float($actual)
        ? abs((float) $expected - (float) $actual) < 0.005
        : $expected === $actual;
    if ($ok) {
        $pass++;
        echo "  PASS  $label  = " . (is_float($actual) ? number_format((float) $actual, 2) : var_export($actual, true)) . "\n";
    } else {
        $fail++;
        echo "  FAIL  $label  expected " . var_export($expected, true) . " got " . var_export($actual, true) . "\n";
    }
}

echo "== S1. Specific Identification ==\n";
// Machine A 120,000; Machine B 135,000; B sold. COGS 135,000; closing 120,000.
$spec = inv_specific_valuation(['MACH-A' => 120000, 'MACH-B' => 135000], ['MACH-B']);
check('S1 COGS', 135000.0, $spec['cogs']);
check('S1 closing inventory', 120000.0, $spec['closing_value']);

echo "== S2. FIFO ==\n";
// Opening 100@10, purchase 60@12, issue 120. COGS 1,240; closing 480 (40@12).
$fifo = inv_fifo_issue(
    [['qty' => 100, 'unit_cost' => 10], ['qty' => 60, 'unit_cost' => 12]],
    120
);
check('S2 COGS', 1240.0, $fifo['cogs']);
check('S2 closing inventory', 480.0, inv_layers_value($fifo['remaining']));
check('S2 closing qty', 40.0, inv_layers_qty($fifo['remaining']));

echo "== S3. Perpetual Moving Weighted Average ==\n";
// Opening 100@10, purchase 60@12 -> avg 10.75; issue 120 -> COGS 1,290; closing 430.
$wavg = inv_weighted_average_run([
    ['type' => 'in', 'qty' => 100, 'unit_cost' => 10],
    ['type' => 'in', 'qty' => 60, 'unit_cost' => 12],
    ['type' => 'out', 'qty' => 120],
]);
check('S3 moving average', 10.75, $wavg['avg']);
check('S3 COGS', 1290.0, $wavg['cogs_total']);
check('S3 closing value', 430.0, $wavg['balance_value']);
check('S3 closing qty', 40.0, $wavg['balance_qty']);

echo "== S4. Lower of Cost and NRV ==\n";
// 100 units, cost 500; SP 540, completion 30, selling 40 -> NRV 470; write-down 3,000.
$nrv = inv_nrv(100, 500, 540, 30, 40, 0.0);
check('S4 NRV per unit', 470.0, $nrv['nrv_per_unit']);
check('S4 carrying cost', 50000.0, $nrv['carrying_cost']);
check('S4 write-down', 3000.0, $nrv['write_down']);
check('S4 final carrying', 47000.0, $nrv['final_carrying']);

echo "== S4b. NRV reversal capped at prior write-down ==\n";
// NRV rises to 490 (SP 560, completion 30, selling 40). Prior write-down 3,000.
// lower = 490 -> carrying 49,000 -> permitted reversal 2,000; never exceed cost 50,000.
$rev = inv_nrv(100, 500, 560, 30, 40, 3000.0);
check('S4b NRV per unit', 490.0, $rev['nrv_per_unit']);
check('S4b reversal', 2000.0, $rev['reversal']);
check('S4b write-down (none)', 0.0, $rev['write_down']);
check('S4b final carrying', 49000.0, $rev['final_carrying']);

// Reversal must never push carrying above original cost even if NRV soars.
$revHigh = inv_nrv(100, 500, 900, 0, 0, 3000.0);
check('S4c reversal capped at prior', 3000.0, $revHigh['reversal']);
check('S4c final carrying <= cost', 50000.0, $revHigh['final_carrying']);

echo "== Guards ==\n";
try {
    inv_fifo_issue([['qty' => 10, 'unit_cost' => 5]], 20);
    check('FIFO over-issue throws', 'throw', 'no-throw');
} catch (RuntimeException $e) {
    check('FIFO over-issue throws', 'throw', 'throw');
}
try {
    inv_weighted_average_run([['type' => 'in', 'qty' => 5, 'unit_cost' => 1], ['type' => 'out', 'qty' => 9]]);
    check('WAvg over-issue throws', 'throw', 'no-throw');
} catch (RuntimeException $e) {
    check('WAvg over-issue throws', 'throw', 'throw');
}

echo "\n==== RESULT: $pass passed, $fail failed ====\n";
exit($fail === 0 ? 0 : 1);
