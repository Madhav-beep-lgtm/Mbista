<?php
declare(strict_types=1);

/**
 * The collection queue: telling apart the three things that land in it
 * (a customer's order, an assignment nobody is attached to yet, and showroom
 * replenishment), filtering to one of them, counting all three, and sorting
 * every column both ways without letting a URL choose its own ORDER BY.
 *   php database/test_jewellery_delivery_queue.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/jewellery_workshop.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function questions(): int { return (int) db()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value']; }

function jdq_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'JDQ01'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE r FROM jewellery_order_receipts r JOIN jewellery_order_assignments a ON a.id = r.assignment_id WHERE a.company_id=$s");
        foreach (['jewellery_stock_unit_events', 'jewellery_stock_units',
                  'jewellery_order_assignments', 'jewellery_orders', 'jewellery_karigars', 'jewellery_purities',
                  'jewellery_units', 'jewellery_metals', 'accounting_parties', 'inventory_items'] as $t) {
            if (table_exists($t) && column_exists($t, 'company_id')) { db()->exec("DELETE FROM `$t` WHERE company_id=$s"); }
        }
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
}
jdq_cleanup();

// ---------------------------------------------------------------- the fixture
db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n, :c, 1, 1)')
    ->execute(['n' => 'Delivery Queue Jewellers (Books)', 'c' => 'JDQ01']);
$cid = (int) db()->lastInsertId();
$fy = create_fiscal_year($cid, 'JDQ 2026/27', '2026-04-01', '2027-03-31', true);
$fyId = (int) $fy['id'];
$_SESSION['company_id'] = $cid;
set_context($cid, $fyId);

db()->prepare("INSERT INTO jewellery_metals (company_id, code, name, sort_order) VALUES (:c,'AU','Gold',1)")->execute(['c' => $cid]);
$metalId = (int) db()->lastInsertId();
db()->prepare("INSERT INTO jewellery_purities (company_id, metal_id, code, name, fineness) VALUES (:c,:m,'22K','22 Karat',0.916)")
    ->execute(['c' => $cid, 'm' => $metalId]);
$purityId = (int) db()->lastInsertId();
db()->prepare("INSERT INTO jewellery_units (company_id, code, name, grams, is_base) VALUES (:c,'GM','Gram',1,1)")->execute(['c' => $cid]);
$unitId = (int) db()->lastInsertId();
db()->prepare("INSERT INTO jewellery_karigars (company_id, code, name, status) VALUES (:c,'K1','Ram Kaligad','active')")->execute(['c' => $cid]);
$karigarId = (int) db()->lastInsertId();
db()->prepare("INSERT INTO inventory_items (company_id, sku, name, item_type, valuation_method, unit, status)
    VALUES (:c,'JDQ-RING','Gold Ring','stock','weighted_average','GM','active')")->execute(['c' => $cid]);
$itemId = (int) db()->lastInsertId();
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'CUST-1','Sunita Sharma','customer','active')")
    ->execute(['c' => $cid]);
$partyId = (int) db()->lastInsertId();

/** An order sitting in the collection queue, with the assignment that put it there. */
function jdq_order(int $cid, int $fyId, int $metalId, int $purityId, int $unitId, int $karigarId, int $itemId,
                   string $no, ?int $partyId, string $customerName, string $assignKind,
                   string $receiveDate, float $gross, string $promised): int
{
    db()->prepare("INSERT INTO jewellery_orders
            (company_id, fiscal_year_id, order_no, order_date, delivery_date, party_id, customer_name,
             metal_id, purity_id, unit_id, expected_gross_weight, expected_fine_weight, making_basis, status)
        VALUES (:c,:fy,:no,'2026-07-01',:promised,:pid,:cn,:m,:p,:u,:g,:g2,'per_unit_weight','received')")
        ->execute(['c' => $cid, 'fy' => $fyId, 'no' => $no, 'promised' => $promised,
            'pid' => $partyId, 'cn' => $customerName, 'm' => $metalId, 'p' => $purityId, 'u' => $unitId,
            'g' => $gross, 'g2' => $gross]);
    $orderId = (int) db()->lastInsertId();

    db()->prepare("INSERT INTO jewellery_order_assignments
            (company_id, fiscal_year_id, issue_no, assignment_no, issue_date, karigar_id, order_id, assign_kind, category,
             item_id, purity_id, unit_id, issued_gross_weight, issued_fine_weight, status)
        VALUES (:c,:fy,:inr,:an,'2026-07-02',:k,:o,:kind,'gold',:i,:p,:u,:g,:g2,'received')")
        ->execute(['c' => $cid, 'fy' => $fyId, 'inr' => 'I-' . $no, 'an' => 'A-' . $no, 'k' => $karigarId, 'o' => $orderId,
            'kind' => $assignKind, 'i' => $itemId, 'p' => $purityId, 'u' => $unitId, 'g' => $gross, 'g2' => $gross]);
    $assignmentId = (int) db()->lastInsertId();

    db()->prepare("INSERT INTO jewellery_order_receipts
            (company_id, fiscal_year_id, assignment_id, receipt_no, receive_date, received_item_id, received_purity_id, unit_id,
             received_gross_weight, received_fine_weight, status)
        VALUES (:c,:fy,:a,:rn,:rd,:i,:p,:u,:g,:g2,'posted')")
        ->execute(['c' => $cid, 'fy' => $fyId, 'a' => $assignmentId, 'rn' => 'R-' . $no,
            'rd' => $receiveDate, 'i' => $itemId, 'p' => $purityId, 'u' => $unitId, 'g' => $gross, 'g2' => $gross]);

    return $orderId;
}

$mk = static fn (string $no, ?int $pid, string $cn, string $kind, string $rd, float $g, string $promised): int
    => jdq_order($cid, $fyId, $metalId, $purityId, $unitId, $karigarId, $itemId, $no, $pid, $cn, $kind, $rd, $g, $promised);

// One made for a master customer, one for a walk-in, one the shop assigned to
// itself to restock the shelf. A fourth is added below off the showroom shelf.
$mk('001', $partyId, '', 'customer', '2026-07-10', 12.5000, '2026-07-20');
$mk('002', null, 'Walk-in Ajay', 'customer', '2026-07-12', 8.2500, '2026-07-25');
$mk('003', null, '', 'customer', '2026-07-11', 5.0000, '2026-07-22');
$mk('004', null, '', 'self', '2026-07-09', 20.0000, '2026-07-30');

echo "\n== How the customer is getting it ==\n";
// A customer gets a piece one of two ways: it is made for them by a kaligad,
// or it was already on the shelf and is set aside. A third kind is not a
// customer order at all -- the shop restocking its own shelf.
$rows = jewellery_pending_delivery($cid);
$byOrder = [];
foreach ($rows as $row) { $byOrder[(string) $row['order_no']] = $row; }
ok((string) $byOrder['001']['origin'] === 'workshop', 'An order sent out to a kaligad is made to order');
ok((string) $byOrder['002']['origin'] === 'workshop', '  ...whether the customer is on the master or typed in');
ok((string) $byOrder['004']['origin'] === 'replenishment', "The shop's own assignment is showroom replenishment");
ok(count(jewellery_order_sources()) === 4, 'Four ways an order can be fulfilled are named');
ok(isset(jewellery_order_sources()['workshop'], jewellery_order_sources()['showroom']),
    '  ...including both ways a customer can get one');

echo "\n== An order taken off the showroom shelf ==\n";
// Nothing goes to a kaligad for this one: a piece already on the shelf is
// reserved against the order. It is how the shop sells what it has.
$shelfOrderId = 0;
if (jewellery_trace_ready()) {
    db()->prepare("INSERT INTO jewellery_orders
            (company_id, fiscal_year_id, order_no, order_date, delivery_date, party_id, customer_name,
             metal_id, purity_id, unit_id, expected_gross_weight, expected_fine_weight, making_basis, status)
        VALUES (:c,:fy,'005','2026-07-05','2026-07-15',:pid,'',:m,:p,:u,6,6,'per_unit_weight','received')")
        ->execute(['c' => $cid, 'fy' => $fyId, 'pid' => $partyId, 'm' => $metalId, 'p' => $purityId, 'u' => $unitId]);
    $shelfOrderId = (int) db()->lastInsertId();
    db()->prepare("INSERT INTO jewellery_stock_units
            (company_id, trace_code, item_id, purity_id, unit_id, stock_kind, status, reserved_order_id,
             gross_weight, fine_weight)
        VALUES (:c,'TRC-SHELF-1',:i,:p,:u,'showroom','reserved',:o,6,6)")
        ->execute(['c' => $cid, 'i' => $itemId, 'p' => $purityId, 'u' => $unitId, 'o' => $shelfOrderId]);
}
$shelfRows = jewellery_pending_delivery($cid);
$shelfRow = null;
foreach ($shelfRows as $candidate) { if ((string) $candidate['order_no'] === '005') { $shelfRow = $candidate; } }
if (jewellery_trace_ready()) {
    ok($shelfRow !== null, 'The shelf order is in the queue');
    ok($shelfRow !== null && (string) $shelfRow['origin'] === 'showroom',
        '  ...and reads as From showroom stock, not as made to order');
} else {
    ok(true, 'Traced stock is not installed, so shelf orders cannot arise (skipped)');
    ok(true, '  (skipped)');
}

echo "\n== Counts, for the dropdown ==\n";
$counts = jewellery_pending_delivery_counts($cid);
$expectShelf = jewellery_trace_ready() ? 1 : 0;
// How many are in the queue at all: the four assigned ones, plus the shelf
// order when traced stock is installed.
$allCount = 4 + $expectShelf;
ok((int) $counts['workshop'] === 3, 'Three made-to-order pieces counted');
ok((int) $counts['replenishment'] === 1, 'One showroom replenishment counted');
ok((int) $counts['showroom'] === $expectShelf, 'The shelf order is counted as showroom stock');
ok((int) $counts['all'] === 4 + $expectShelf, 'Everything in the queue is counted once');

echo "\n== Filtering to one kind ==\n";
foreach (['workshop' => 3, 'replenishment' => 1] as $originKey => $expected) {
    $filtered = jewellery_pending_delivery($cid, ['origin' => $originKey]);
    ok(count($filtered) === $expected, 'Filtering to "' . $originKey . '" returns ' . $expected);
    $wrong = array_filter($filtered, static fn (array $r): bool => (string) $r['origin'] !== $originKey);
    ok($wrong === [], '  ...and nothing else');
}
ok(count(jewellery_pending_delivery($cid, ['origin' => ''])) === $allCount, 'No filter means all of them');
ok(count(jewellery_pending_delivery($cid, ['origin' => 'nonsense'])) === $allCount, 'A type nobody offers is ignored, not obeyed');

echo "\n== Sorting ==\n";
$firstOrder = static function (array $filters) use ($cid): string {
    $rows = jewellery_pending_delivery($cid, $filters);
    return (string) ($rows[0]['order_no'] ?? '');
};
ok($firstOrder(['sort' => 'received', 'dir' => 'asc']) === '004', 'Oldest received first, ascending');
ok($firstOrder(['sort' => 'received', 'dir' => 'desc']) === '002', '  ...and newest first, descending');
ok($firstOrder(['sort' => 'weight', 'dir' => 'desc']) === '004', 'Heaviest first by weight');
ok($firstOrder(['sort' => 'weight', 'dir' => 'asc']) === '003', '  ...and lightest first the other way');
ok($firstOrder(['sort' => 'order', 'dir' => 'asc']) === '001', 'By order number, ascending');
ok($firstOrder(['sort' => 'order', 'dir' => 'desc']) === ($allCount === 5 ? '005' : '004'), '  ...and descending');
ok($firstOrder(['sort' => 'promised', 'dir' => 'asc']) === ($allCount === 5 ? '005' : '001'), 'By what was promised, soonest first');
ok($firstOrder(['sort' => 'promised', 'dir' => 'desc']) === '004', '  ...and latest first');
$byCustomer = jewellery_pending_delivery($cid, ['sort' => 'customer', 'dir' => 'asc']);
ok((string) $byCustomer[0]['order_no'] === '001', 'By customer name, alphabetically (Sunita Sharma before Walk-in Ajay)');
// 001 and 005 are both Sunita's; the walk-in follows, then the ones naming nobody.
$customerOrder = array_map(static fn (array $r): string => (string) $r['order_no'], $byCustomer);
ok(array_search('002', $customerOrder, true) < array_search('003', $customerOrder, true),
    '  ...a named customer sorts above one naming nobody');
$namedLast = (string) $byCustomer[count($byCustomer) - 1]['order_no'];
ok(in_array($namedLast, ['003', '004'], true), '  ...and the ones naming nobody sort to the end');
$byOrigin = jewellery_pending_delivery($cid, ['sort' => 'origin', 'dir' => 'asc']);
$originOrder = array_map(static fn (array $r): string => (string) $r['origin'], $byOrigin);
ok($originOrder === array_values(array_merge(...array_map(
        static fn (string $k): array => array_fill(0, count(array_keys($originOrder, $k, true)), $k),
        array_values(array_unique($originOrder))))),
    'Sorting by type groups them together');
ok((string) $byOrigin[0]['origin'] === 'replenishment', '  ...alphabetically, replenishment first');

echo "\n== A sort key cannot choose its own ORDER BY ==\n";
$injected = jewellery_pending_delivery($cid, ['sort' => 'o.id; DROP TABLE jewellery_orders', 'dir' => 'asc']);
ok(count($injected) === $allCount, 'A sort key nobody offers falls back to the default instead of reaching the query');
ok((int) db()->query("SELECT COUNT(*) FROM jewellery_orders WHERE company_id=$cid")->fetchColumn() === $allCount, '  ...and the table is still there');
$injectedDir = jewellery_pending_delivery($cid, ['sort' => 'weight', 'dir' => 'asc; DELETE FROM jewellery_orders']);
ok(count($injectedDir) === $allCount, 'A direction nobody offers falls back to ascending');

echo "\n== Filtering and sorting hold together ==\n";
$both = jewellery_pending_delivery($cid, ['origin' => 'workshop', 'sort' => 'weight', 'dir' => 'desc']);
ok(count($both) === 3, 'Filtered to made-to-order');
ok((string) $both[0]['order_no'] === '001', '  ...heaviest first within them');

echo "\n== Shape ==\n";
$q0 = questions();
jewellery_pending_delivery($cid, ['origin' => 'workshop', 'sort' => 'weight', 'dir' => 'desc']);
$listCost = questions() - $q0 - 2;
ok($listCost <= 2, "The list is one query however it is filtered or sorted ($listCost)");
$q0 = questions();
jewellery_pending_delivery_counts($cid);
$countCost = questions() - $q0 - 2;
ok($countCost <= 2, "All three counts come from one grouped query ($countCost)");

echo "\n== A job split between two kaligads is still ONE piece ==\n";
// An order can be shared out to two workers, which gives it two received
// assignments. The queue used to show it twice -- the same ring, counted twice,
// waiting twice -- so any count taken off this list was wrong by however many
// orders had been split.
$splitOrderId = (int) db()->query("SELECT id FROM jewellery_orders WHERE company_id=$cid AND order_no='001'")->fetchColumn();
$splitFrom = db()->query("SELECT * FROM jewellery_order_assignments WHERE order_id=$splitOrderId LIMIT 1")->fetch(PDO::FETCH_ASSOC);
db()->prepare("INSERT INTO jewellery_order_assignments
        (company_id, fiscal_year_id, issue_no, assignment_no, issue_date, karigar_id, order_id, assign_kind, category,
         item_id, purity_id, unit_id, issued_gross_weight, issued_fine_weight, status)
    VALUES (:c,:fy,'I-001b','A-001b','2026-07-03',:k,:o,'customer','gold',:i,:p,:u,5,5,'received')")
    ->execute(['c' => $cid, 'fy' => $splitFrom['fiscal_year_id'], 'k' => $splitFrom['karigar_id'], 'o' => $splitOrderId,
        'i' => $splitFrom['item_id'], 'p' => $splitFrom['purity_id'], 'u' => $splitFrom['unit_id']]);
$splitAssignmentId = (int) db()->lastInsertId();
db()->prepare("INSERT INTO jewellery_order_receipts
        (company_id, fiscal_year_id, assignment_id, receipt_no, receive_date, received_item_id, received_purity_id, unit_id,
         received_gross_weight, received_fine_weight, status)
    VALUES (:c,:fy,:a,'R-001b','2026-07-13',:i,:p,:u,5,5,'posted')")
    ->execute(['c' => $cid, 'fy' => $splitFrom['fiscal_year_id'], 'a' => $splitAssignmentId,
        'i' => $splitFrom['item_id'], 'p' => $splitFrom['purity_id'], 'u' => $splitFrom['unit_id']]);

$splitRows = jewellery_pending_delivery($cid);
ok(count($splitRows) === $allCount, 'The split order is one row, not two');
$splitRow = null;
foreach ($splitRows as $candidate) {
    if ((string) $candidate['order_no'] === '001') {
        $splitRow = $candidate;
    }
}
ok($splitRow !== null && (int) $splitRow['assignment_count'] === 2, '  ...and says it came back from two kaligads');
ok($splitRow !== null && abs((float) $splitRow['received_gross_weight'] - 17.5) < 0.0001, '  ...with both weights added up (12.5 + 5)');
ok($splitRow !== null && (string) $splitRow['receive_date'] === '2026-07-13', '  ...dated by the LAST piece back, which is when it was ready');
$splitCounts = jewellery_pending_delivery_counts($cid);
ok((int) $splitCounts['all'] === $allCount, 'The counts do not grow because a job was split');
ok((int) $splitCounts['workshop'] === 3, '  ...three still made to order');
ok(count(jewellery_pending_delivery($cid, ['origin' => 'workshop'])) === 3, 'Filtering does not double it either');


echo "\n== The Orders list says the same thing ==\n";
// The order list and the collection queue read the same classifier, so a piece
// cannot be "made to order" on one screen and something else on the other.
$listed = jewellery_orders_list($cid, ['limit' => 50]);
$listedByNo = [];
foreach ($listed as $listedRow) { $listedByNo[(string) $listedRow['order_no']] = $listedRow; }
ok(isset($listedByNo['001']['order_source']), 'Every order carries its type');
ok((string) $listedByNo['001']['order_source'] === 'workshop', 'A kaligad order is made to order');
ok((string) $listedByNo['004']['order_source'] === 'replenishment', "The shop's own work is replenishment");
if (jewellery_trace_ready()) {
    ok((string) ($listedByNo['005']['order_source'] ?? '') === 'showroom', 'A reserved shelf piece is from showroom stock');
} else {
    ok(true, 'Traced stock is not installed (skipped)');
}
foreach ($listedByNo as $listedNo => $listedRow) {
    $queued = null;
    foreach (jewellery_pending_delivery($cid) as $queuedRow) {
        if ((string) $queuedRow['order_no'] === $listedNo) { $queued = $queuedRow; }
    }
    if ($queued !== null) {
        ok((string) $queued['origin'] === (string) $listedRow['order_source'],
            'Order ' . $listedNo . ' reads the same on both screens');
    }
}

echo "\n== Filtering the Orders list by type ==\n";
ok(count(jewellery_orders_list($cid, ['limit' => 50, 'source' => 'workshop'])) === 3, 'Three made to order');
ok(count(jewellery_orders_list($cid, ['limit' => 50, 'source' => 'replenishment'])) === 1, 'One replenishment');
ok(count(jewellery_orders_list($cid, ['limit' => 50, 'source' => 'pending'])) === 0, 'None unstarted — all four went somewhere');
ok(count(jewellery_orders_list($cid, ['limit' => 50, 'source' => 'nonsense'])) === count($listed),
    'A type nobody offers is ignored rather than obeyed');
$listQ = questions();
jewellery_orders_list($cid, ['limit' => 50, 'source' => 'workshop']);
$listCost = questions() - $listQ - 2;
ok($listCost <= 2, "The orders list is still one query with the type on it ($listCost)");

echo "\n== An order with neither yet ==\n";
db()->prepare("INSERT INTO jewellery_orders
        (company_id, fiscal_year_id, order_no, order_date, party_id, customer_name,
         metal_id, purity_id, unit_id, expected_gross_weight, expected_fine_weight, making_basis, status)
    VALUES (:c,:fy,'006','2026-07-06',:pid,'',:m,:p,:u,3,3,'per_unit_weight','confirmed')")
    ->execute(['c' => $cid, 'fy' => $fyId, 'pid' => $partyId, 'm' => $metalId, 'p' => $purityId, 'u' => $unitId]);
$unstarted = jewellery_orders_list($cid, ['limit' => 50, 'source' => 'pending']);
ok(count($unstarted) === 1, 'An order that has gone nowhere yet reads as Not started');
ok((string) $unstarted[0]['order_no'] === '006', '  ...and it is the right one');


echo "\n== The queue exports ==\n";
// The spreadsheet is built from the same rows the screen renders, so the file
// and the page can never disagree -- and the order number leads, because that
// is what a customer quotes when they ring up.
require_once __DIR__ . '/../app/export_engine.php';
$exportRows = [['Order No', 'Order date', 'Customer', 'Phone', 'Ordered as', 'Item', 'Design',
    'Received on', 'Weight back', 'Fine wt', 'Unit', 'Days waiting', 'Promised', 'Kaligads', 'Order status']];
$exportLabels = jewellery_order_sources();
foreach (jewellery_pending_delivery($cid) as $r) {
    $exportRows[] = [
        (string) $r['order_no'], (string) $r['order_date'],
        (string) ($r['party_name'] ?? '') !== '' ? (string) $r['party_name'] : (string) ($r['customer_name'] ?? ''),
        (string) ($r['customer_phone'] ?? ''),
        (string) ($exportLabels[(string) ($r['origin'] ?? '')] ?? ''),
        (string) ($r['expected_item'] ?? ''), (string) ($r['design_no'] ?? ''),
        (string) ($r['receive_date'] ?? ''), $r['received_gross_weight'] ?? 0, $r['received_fine_weight'] ?? 0,
        (string) ($r['unit_code'] ?? ''), (int) ($r['days_waiting'] ?? 0),
        (string) ($r['delivery_date'] ?? ''), (int) ($r['assignment_count'] ?? 0), (string) $r['status'],
    ];
}
ok((string) $exportRows[0][0] === 'Order No', 'The order number is the first column of the export');
ok(count($exportRows) === $allCount + 1, 'Every row on the screen is in the export');
$exportedNumbers = array_map(static fn (array $r): string => (string) $r[0], array_slice($exportRows, 1));
ok(in_array('001', $exportedNumbers, true) && in_array('004', $exportedNumbers, true),
    '  ...carrying the order numbers');
ok(in_array('Made to order', array_column(array_slice($exportRows, 1), 4), true),
    '  ...and the type in words, not a code');

$xlsxBytes = xlsx_build($exportRows, 'Awaiting Collection');
$xlsxPath = tempnam(sys_get_temp_dir(), 'jdq') . '.xlsx';
file_put_contents($xlsxPath, $xlsxBytes);
ok(str_starts_with($xlsxBytes, 'PK'), 'The Excel file is a real workbook, with nothing written in front of it');
$readBack = spreadsheet_read_xlsx_all($xlsxPath);
ok(array_keys($readBack) === ['Awaiting Collection'], '  ...on a sheet named for what it holds');
ok(count(reset($readBack)) === $allCount + 1, '  ...and it reads back with every row');
ok((string) reset($readBack)[0]['cells'][0] === 'Order No', '  ...order number still leading');
@unlink($xlsxPath);


echo "\n== Tenant isolation ==\n";
db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n, :c, 1, 1)')
    ->execute(['n' => 'Other Jewellers', 'c' => 'JDQ02']);
$otherId = (int) db()->lastInsertId();
ok(jewellery_pending_delivery($otherId) === [], "Another company sees none of this company's queue");
ok((int) jewellery_pending_delivery_counts($otherId)['all'] === 0, '  ...and counts none of it');
db()->exec("DELETE FROM companies WHERE id=$otherId");

jdq_cleanup();

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass   FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
