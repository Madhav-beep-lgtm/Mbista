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
echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
