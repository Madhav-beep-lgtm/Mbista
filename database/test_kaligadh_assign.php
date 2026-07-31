<?php
declare(strict_types=1);

/**
 * Assigning work to a kaligad, both ways.
 *
 * The rules the sheet is really made of, asserted one by one: what the customer
 * flow reads off the order rather than asking for, what the showroom flow has
 * to be told, and the two dates that fence a customer's promise. The validator
 * is pure — it takes the order and the line it was handed — so most of this
 * runs without touching a database.
 *
 *   php database/test_kaligadh_assign.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require_once $root . '/app/accounting_module_repair.php';
require_once $root . '/app/jewellery_assign.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.0005; }
/** True when any complaint mentions $needle. */
function said(array $result, string $needle): bool
{
    foreach ($result['errors'] as $error) {
        if (stripos($error, $needle) !== false) { return true; }
    }

    return false;
}

// An order taken on the 10th, promised for the 25th, with one ring on it.
$order = [
    'id' => 7,
    'order_no' => 'JO-2083-000003',
    'order_date' => '2026-08-10',
    'delivery_date' => '2026-08-25',
    'design_no' => 'D-114',
    'customer_label' => 'Sita Sharma',
];
$line = [
    'id' => 21,
    'order_id' => 7,
    'item_id' => 55,
    'purity_id' => 3,
    'unit_id' => 2,
    'size' => 'Ring 14',
    'gross_weight' => 12.5000,
    'stone_weight' => 1.2000,
    'net_weight' => 11.3000,
    'item_name' => 'Diamond ring',
    'fineness' => 916.0,
    'assignment_id' => null,
];
$base = [
    'karigar_id' => 4,
    'category' => 'gold',
    'making_basis' => 'flat',
    'making_rate' => 2500,
    'assigned_date' => '2026-08-12',
    'expected_delivery' => '2026-08-20',
    'description' => 'Bridal, engrave inside',
];

// ---------------------------------------------------------------------------
echo "1. The two kinds, and what the sheet calls them\n";
// ---------------------------------------------------------------------------
ok(array_keys(jewellery_assign_kinds()) === ['customer', 'self'], 'There are exactly two kinds of assignment');
ok(array_keys(jewellery_assign_categories()) === ['gold', 'diamond', 'other'], 'Category is Gold / Diamond / Other');
ok(count(jewellery_assign_making_bases()) === 3, 'Making charge can be flat, percentage, or per unit of weight');

// ---------------------------------------------------------------------------
echo "\n2. Customer flow reads the order rather than asking for it\n";
// ---------------------------------------------------------------------------
// Everything the order knows is sent as rubbish on purpose: the screen shows
// these fields, so the browser posts them, and the server must ignore them.
$tampered = $base + [
    'expected_gross_weight' => 999,
    'expected_stone_weight' => 998,
    'purity_id' => 99,
    'item_id' => 99,
    'size_design' => 'typed over',
];
$result = jewellery_assign_validate('customer', $tampered, $order, $line);
ok($result['ok'], 'A customer assignment against an open order validates');
ok(near((float) $result['row']['expected_gross_weight'], 12.5), 'Gross weight comes off the order line, not the form');
ok(near((float) $result['row']['expected_stone_weight'], 1.2), 'And so does the stone weight');
ok(near((float) $result['row']['expected_net_weight'], 11.3), 'Net weight is worked out — gross minus stone');
ok((int) $result['row']['purity_id'] === 3, 'Purity comes off the order line');
ok((int) $result['row']['item_id'] === 55, 'So does the item');
ok($result['row']['size_design'] === 'Ring 14', "Size is the line's own, not what the form sent");
ok($result['row']['expected_ornament'] === 'Diamond ring', 'The ornament names itself from the item ordered');
ok((int) $result['row']['order_line_id'] === 21, 'And the assignment is tied to that one item of the order');

$noSize = $line;
$noSize['size'] = '';
$result = jewellery_assign_validate('customer', $base, $order, $noSize);
ok($result['row']['size_design'] === 'D-114', "An item with no size of its own falls back to the order's design number");

// ---------------------------------------------------------------------------
echo "\n3. The two dates a customer's promise fences\n";
// ---------------------------------------------------------------------------
$early = $base;
$early['assigned_date'] = '2026-08-09';
$result = jewellery_assign_validate('customer', $early, $order, $line);
ok(!$result['ok'] && said($result, 'before the order was taken'),
    'Work cannot be assigned before the order was taken');

$late = $base;
$late['expected_delivery'] = '2026-08-28';
$result = jewellery_assign_validate('customer', $late, $order, $line);
ok(!$result['ok'] && said($result, 'promised'),
    'The kaligad cannot be given until after the customer was promised');

$onTheDay = $base;
$onTheDay['assigned_date'] = '2026-08-10';
$onTheDay['expected_delivery'] = '2026-08-25';
ok(jewellery_assign_validate('customer', $onTheDay, $order, $line)['ok'],
    'Both dates may sit exactly on the order date and the promise date');

$result = jewellery_assign_validate('customer', $base, null, null);
ok(!$result['ok'] && said($result, 'order number'), 'A customer assignment with no order is refused');
$result = jewellery_assign_validate('customer', $base, $order, null);
ok(!$result['ok'] && said($result, 'which of that order'), 'And so is one that names no item of it');

$foreignLine = $line;
$foreignLine['order_id'] = 99;
$result = jewellery_assign_validate('customer', $base, $order, $foreignLine);
ok(!$result['ok'] && said($result, 'does not belong'), 'An item from another order cannot be smuggled onto this one');

// ---------------------------------------------------------------------------
echo "\n4. Showroom flow is typed, and still cannot lie\n";
// ---------------------------------------------------------------------------
$self = $base + [
    'item_id' => 61,
    'purity_id' => 2,
    'unit_id' => 2,
    'size_design' => 'Chain 20 in',
    'expected_ornament' => 'Gold chain',
    'expected_gross_weight' => 20,
    'expected_stone_weight' => 0,
];
$result = jewellery_assign_validate('self', $self, null, null);
ok($result['ok'], 'A showroom assignment needs no order and no customer');
ok(near((float) $result['row']['expected_net_weight'], 20.0), 'With no stones, net is the whole gross');
ok((int) $result['row']['order_id'] === 0 && (int) $result['row']['order_line_id'] === 0,
    'And it is tied to no order at all');

$stoned = $self;
$stoned['expected_stone_weight'] = 3.5;
ok(near((float) jewellery_assign_validate('self', $stoned, null, null)['row']['expected_net_weight'], 16.5),
    'Net is gross minus stone, worked out and never typed');

$noItem = $self;
unset($noItem['item_id']);
ok(said(jewellery_assign_validate('self', $noItem, null, null), 'finished stock item'),
    'The piece has to be a finished stock item');

$noPurity = $self;
$noPurity['purity_id'] = 0;
ok(said(jewellery_assign_validate('self', $noPurity, null, null), 'purity list'),
    'And its purity has to come from the purity list');

$noWeight = $self;
$noWeight['expected_gross_weight'] = 0;
ok(said(jewellery_assign_validate('self', $noWeight, null, null), 'gross weight'),
    'A piece with no weight is not a specification');

$allStone = $self;
$allStone['expected_stone_weight'] = 20;
ok(said(jewellery_assign_validate('self', $allStone, null, null), 'as much as the whole piece'),
    'The stones cannot weigh the whole piece');

$negative = $self;
$negative['expected_stone_weight'] = -1;
ok(said(jewellery_assign_validate('self', $negative, null, null), 'negative'),
    'Nor can they weigh less than nothing');

$backwards = $self;
$backwards['expected_delivery'] = '2026-08-01';
ok(said(jewellery_assign_validate('self', $backwards, null, null), 'before it was assigned'),
    'A showroom piece cannot be due back before it was assigned');

// ---------------------------------------------------------------------------
echo "\n5. Whoever it is for, some things are always required\n";
// ---------------------------------------------------------------------------
foreach (['customer' => [$order, $line], 'self' => [null, null]] as $kind => [$o, $l]) {
    $noKarigar = ($kind === 'self' ? $self : $base);
    $noKarigar['karigar_id'] = 0;
    ok(said(jewellery_assign_validate($kind, $noKarigar, $o, $l), 'kaligad'),
        ucfirst($kind) . ' work still has to name the kaligad it goes to');

    $noDate = ($kind === 'self' ? $self : $base);
    $noDate['assigned_date'] = '';
    ok(said(jewellery_assign_validate($kind, $noDate, $o, $l), 'assigned'),
        ucfirst($kind) . ' work still has to say when it was assigned');
}
ok(jewellery_assign_validate('nonsense', $self, null, null)['row']['assign_kind'] === 'customer',
    'An unknown kind falls back to the customer flow rather than inventing a third');
ok(jewellery_assign_validate('self', ['category' => 'platinum'] + $self, null, null)['row']['category'] === 'gold',
    'A category outside the three falls back to gold');

// ---------------------------------------------------------------------------
echo "\n6. The export carries the sheet's own columns\n";
// ---------------------------------------------------------------------------
$row = [
    'assignment_no' => 'KA-2083-000001', 'karigar_code' => 'K-07', 'karigar_name' => 'Bharat Bajrachary',
    'order_no' => 'JO-2083-000003', 'customer_name' => 'Sita Sharma', 'size_design' => 'Ring 14',
    'expected_ornament' => 'Diamond ring', 'item_name' => 'Diamond ring', 'category' => 'gold',
    'expected_gross_weight' => 12.5, 'expected_stone_weight' => 1.2, 'expected_net_weight' => 11.3,
    'purity_code' => '22K', 'making_basis' => 'flat', 'making_rate' => 2500, 'unit_code' => 'TOLA',
    'issue_date' => '2026-08-12', 'expected_return_date' => '2026-08-20', 'notes' => 'Engrave inside',
];
$customerCols = array_keys(jewellery_assign_export_rows([$row], 'customer', 'Rs ')[0]);
ok($customerCols === ['SN', 'Assignment Number', 'Kaligadh Name', 'Order Number', 'Customer Name', 'Size/Design',
    'Expected Ornament', 'Category', 'Gross Weight', 'Stone / Diamond', 'Net Weight', 'Purity',
    'Making Charge', 'Assigned Date', 'Expected Delivery', 'Description'],
    'The customer export has the sheet\'s sixteen columns, in the sheet\'s order');

$selfCols = array_keys(jewellery_assign_export_rows([$row], 'self', 'Rs ')[0]);
ok(!in_array('Order Number', $selfCols, true) && !in_array('Customer Name', $selfCols, true),
    'The showroom export drops the two columns a showroom piece has no answer for');
ok(count($selfCols) === 14, 'Leaving fourteen');

$exported = jewellery_assign_export_rows([$row, $row], 'customer', 'Rs ');
ok((int) $exported[0]['SN'] === 1 && (int) $exported[1]['SN'] === 2, 'SN numbers itself down the sheet');
ok($exported[0]['Making Charge'] === 'Flat Rs 2,500.00', 'A flat charge reads as a flat charge');
ok(jewellery_assign_making_charge_label(['making_basis' => 'percent_of_metal', 'making_rate' => 8], 'Rs ') === '8% of metal',
    'A percentage reads as a percentage');
ok(jewellery_assign_making_charge_label(['making_basis' => 'per_unit_weight', 'making_rate' => 250, 'unit_code' => 'TOLA'], 'Rs ') === 'Rs 250.00 per TOLA',
    'And a piece rate says what it is per');
ok(jewellery_assign_making_charge_label(['making_basis' => 'flat', 'making_rate' => 0], 'Rs ') === '—',
    'No charge agreed reads as a dash, not as zero rupees');

// ---------------------------------------------------------------------------
echo "\n7. Where a finished piece is going, said in one line\n";
// ---------------------------------------------------------------------------
$customerWaiting = ['assign_kind' => 'customer', 'order_no' => 'JO-2083-000003',
    'customer_name' => 'Sita Sharma', 'order_status' => 'received'];
ok(jewellery_output_remark($customerWaiting) === 'Customer order JO-2083-000003 for Sita Sharma — ready to deliver',
    'A customer piece back from the kaligad is ready to deliver, and names whose it is');
foreach (['delivered', 'invoiced', 'closed'] as $goneStatus) {
    ok(str_ends_with(jewellery_output_remark(['assign_kind' => 'customer', 'order_no' => 'JO-1',
        'customer_name' => 'X', 'order_status' => $goneStatus]), '— delivered'),
        "An order marked $goneStatus reads as delivered, not as still waiting");
}
ok(jewellery_output_remark(['assign_kind' => 'customer', 'order_no' => 'JO-9', 'customer_name' => '', 'order_status' => 'received'])
    === 'Customer order JO-9 — ready to deliver',
    'A walk-in with no name on the order does not get a dangling "for"');
ok(jewellery_output_remark(['assign_kind' => 'self']) === 'Self order — ready to sale, showroom stock replenishment',
    'A showroom piece is ready to sale, and says why it was made');

$outputRow = [
    'assignment_no' => 'KA-2083-000001', 'assign_kind' => 'self', 'karigar_code' => 'K-01',
    'karigar_name' => 'Akshara', 'receipt_no' => 'JRC-00001', 'receive_date' => '2026-07-31',
    'expected_ornament' => 'Gold chain', 'item_name' => 'Gold chain', 'size_design' => 'Chain 20 in',
    'category' => 'gold', 'received_gross_weight' => 20, 'stone_weight' => 0, 'net_gold_weight' => 20,
    'received_fine_weight' => 11.7, 'purity_code' => '14K', 'wastage_fine_weight' => 0,
    'making_amount' => 3000, 'remark' => jewellery_output_remark(['assign_kind' => 'self']),
];
$outputCols = array_keys(jewellery_output_export_rows([$outputRow], 'Rs ')[0]);
ok(in_array('Remarks', $outputCols, true), 'The output register exports a Remarks column');
ok($outputCols[count($outputCols) - 1] === 'Remarks', 'And it is the last column, where a remark belongs');
ok(in_array('Kind', $outputCols, true), 'The register says which kind each piece was');
$exportedOutput = jewellery_output_export_rows([$outputRow], 'Rs ')[0];
ok($exportedOutput['Kind'] === 'Self ordered', 'A showroom piece exports as self ordered');
ok($exportedOutput['Remarks'] === 'Self order — ready to sale, showroom stock replenishment',
    'And carries the remark the screen shows');

// ---------------------------------------------------------------------------
echo "\n8. Gold and the stones set into it, on one issue and never mixed\n";
// ---------------------------------------------------------------------------
// The arithmetic that must never fold a diamond into a weight of gold, read in
// one place. A stone's purity is the masters' standard 1000, so its stock fine
// equals its carats — which is right for the stone's own balance and wrong for
// everything the kaligad is answerable for.
$components = [
    ['component_kind' => 'metal', 'gross_weight' => 10.0, 'fine_weight' => 9.16, 'qty_carat' => 0.0, 'amount' => 91600.0],
    ['component_kind' => 'metal', 'gross_weight' => 5.0, 'fine_weight' => 4.58, 'qty_carat' => 0.0, 'amount' => 45800.0],
    ['component_kind' => 'stone', 'gross_weight' => 3.0, 'fine_weight' => 0.0, 'qty_carat' => 3.0, 'amount' => 30000.0],
];
$totals = jewellery_component_totals($components);
ok($totals['metal_lines'] === 2 && $totals['stone_lines'] === 1, 'Two pieces of metal and one packet of stones are counted apart');
ok(near($totals['metal_fine'], 13.74), 'The fine is the metal only — 9.16 + 4.58');
ok(near($totals['metal_gross'], 15.0), 'And so is the gross weight');
ok(near($totals['stone_carat'], 3.0), 'The stones total in carats, on their own');
ok(near($totals['metal_amount'], 137400.0) && near($totals['stone_amount'], 30000.0),
    'Each side carries its own value');

$stonesOnly = jewellery_component_totals([$components[2]]);
ok(near($stonesOnly['metal_fine'], 0.0) && near($stonesOnly['metal_gross'], 0.0),
    'An issue of nothing but stones puts no fine gold on the kaligad at all');
ok(near($stonesOnly['stone_carat'], 3.0), 'Though he is holding the stones');
ok(jewellery_component_totals([])['metal_lines'] === 0, 'An issue with nothing on it totals to nothing');

// A component row that forgot to say which it is counts as metal, because that
// is what an issue was before stones could be put on one.
$untyped = jewellery_component_totals([['gross_weight' => 2.0, 'fine_weight' => 1.83, 'amount' => 100.0]]);
ok($untyped['metal_lines'] === 1 && near($untyped['metal_fine'], 1.83),
    'A row with no kind counts as metal, which is what every issue used to be');

// ---------------------------------------------------------------------------
echo "\n9. The wage said in rupees and in gold, without the two drifting\n";
// ---------------------------------------------------------------------------
// Rupees are the truth and the metal is worked out from them, at the rate the
// issue was valued at — the same rate the wastage is charged at, so a movement
// in the day's gold price cannot silently change what was earned.
ok(near(jewellery_wages_in_metal(139000.0, 139000.0), 1.0), 'A wage worth one fine unit converts to one');
ok(near(jewellery_wages_in_metal(69500.0, 139000.0), 0.5), 'And half of it to a half');
ok(near(jewellery_wages_in_metal(5000.0, 0.0), 0.0), 'With no rate there is no conversion, not a division by zero');
ok(near(jewellery_wages_from_metal(2.0, 139000.0), 278000.0), 'Going the other way, two fine is worth two rates');
// And here is the reason rupees are the truth rather than the gold. A weight
// carries four decimals, so at 139,000 a fine unit the smallest weight the shop
// can write is worth about Rs 14 — and a wage converted into gold and back
// cannot return exactly. Whichever figure is derived has to be the one allowed
// to lose that; making it the rupees would lose the wage itself.
$roundTripped = jewellery_wages_from_metal(jewellery_wages_in_metal(12345.0, 139000.0), 139000.0);
$lastDecimalWorth = 0.0001 * 139000.0;
ok(abs($roundTripped - 12345.0) < $lastDecimalWorth,
    'A wage converted to gold and back lands within one tick of the weight scale (' . number_format($roundTripped, 2) . ')');
ok(abs($roundTripped - 12345.0) > 0.005,
    'But NOT exactly — which is why the rupees are the truth and the gold is worked out from them');

$receipt = ['making_amount' => 5000.0, 'recovery_amount' => 1390.0, 'net_payable' => 3610.0, 'avg_fine_rate' => 139000.0];
$statement = jewellery_wage_statement($receipt);
ok($statement['rate_basis'] === 'issue', 'A receipt with metal behind it converts at the rate that metal went out at');
ok(near((float) $statement['making_fine'], 0.036), 'The making charge in fine');
ok(near((float) $statement['recovery_fine'], 0.01), 'The wastage recovered, in fine');
ok(near((float) $statement['net_payable_fine'], 0.026), 'And what he actually takes home, in fine');
ok($statement['convertible'] === true, 'The screen may show it');

// A work order: he found his own gold, so nothing was issued and there is no
// issue rate. The day's board converts instead, and the answer says so.
$workOrderReceipt = ['making_amount' => 5000.0, 'recovery_amount' => 0.0, 'net_payable' => 5000.0, 'avg_fine_rate' => 0.0];
$boardStatement = jewellery_wage_statement($workOrderReceipt, 140000.0);
ok($boardStatement['rate_basis'] === 'board', "With no metal issued, the day's board does the converting");
ok(near((float) $boardStatement['net_payable_fine'], 0.0357), 'And the wage still comes out in gold');
ok(str_contains(jewellery_wage_rate_note($boardStatement, 'Rs '), 'no metal was issued'),
    'The note says why that rate was used, because the two are different statements');

$noRate = jewellery_wage_statement($workOrderReceipt, 0.0);
ok($noRate['rate_basis'] === 'none' && $noRate['convertible'] === false,
    'With no rate anywhere the wage stands in rupees alone');
ok(near((float) $noRate['net_payable'], 5000.0), 'Which is still the wage, in full');
ok(str_contains(jewellery_wage_rate_note($noRate, 'Rs '), 'no gold rate available'),
    'And the screen says so rather than showing a confident zero');

// A kaligad who owes the shop more than he earned: the metal figure has to
// carry the same sign, or a debt reads as a payment.
$owing = jewellery_wage_statement(['making_amount' => 500.0, 'recovery_amount' => 1390.0,
    'net_payable' => -890.0, 'avg_fine_rate' => 139000.0]);
ok((float) $owing['net_payable_fine'] < 0, 'A kaligad who owes the shop owes it in gold too');
ok(near((float) $owing['net_payable_fine'], -0.0064), 'To the same amount');

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
