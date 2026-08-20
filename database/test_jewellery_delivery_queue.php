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
        foreach (['jewellery_order_assignments', 'jewellery_orders', 'jewellery_karigars', 'jewellery_purities',
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

// Two customers (one from the master, one typed on the order), one assignment
// with nobody attached, one showroom piece.
$mk('001', $partyId, '', 'customer', '2026-07-10', 12.5000, '2026-07-20');
$mk('002', null, 'Walk-in Ajay', 'customer', '2026-07-12', 8.2500, '2026-07-25');
$mk('003', null, '', 'customer', '2026-07-11', 5.0000, '2026-07-22');
$mk('004', null, '', 'self', '2026-07-09', 20.0000, '2026-07-30');

echo "\n== The three origins are told apart ==\n";
$rows = jewellery_pending_delivery($cid);
ok(count($rows) === 4, 'All four are in the queue');
$byOrder = [];
foreach ($rows as $row) { $byOrder[(string) $row['order_no']] = $row; }
ok((string) $byOrder['001']['origin'] === 'customer', 'An order naming a master customer is a customer order');
ok((string) $byOrder['002']['origin'] === 'customer', 'An order with a typed-in name is a customer order too');
ok((string) $byOrder['003']['origin'] === 'assignment', 'An order with nobody named is a new assignment');
ok((string) $byOrder['004']['origin'] === 'showroom', 'A self-assigned piece is showroom stock');
ok(count(jewellery_delivery_origins()) === 3, 'Three origins are offered');

echo "\n== Counts, for the dropdown ==\n";
$counts = jewellery_pending_delivery_counts($cid);
ok((int) $counts['customer'] === 2, 'Two customer orders counted');
ok((int) $counts['assignment'] === 1, 'One new assignment counted');
ok((int) $counts['showroom'] === 1, 'One showroom piece counted');
ok((int) $counts['all'] === 4, 'Four in total');

echo "\n== Filtering to one kind ==\n";
foreach (['customer' => 2, 'assignment' => 1, 'showroom' => 1] as $originKey => $expected) {
    $filtered = jewellery_pending_delivery($cid, ['origin' => $originKey]);
    ok(count($filtered) === $expected, 'Filtering to "' . $originKey . '" returns ' . $expected);
    $wrong = array_filter($filtered, static fn (array $r): bool => (string) $r['origin'] !== $originKey);
    ok($wrong === [], '  ...and nothing else');
}
ok(count(jewellery_pending_delivery($cid, ['origin' => ''])) === 4, 'No filter means all of them');
ok(count(jewellery_pending_delivery($cid, ['origin' => 'nonsense'])) === 4, 'An origin nobody offers is ignored, not obeyed');

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
ok($firstOrder(['sort' => 'order', 'dir' => 'desc']) === '004', '  ...and descending');
ok($firstOrder(['sort' => 'promised', 'dir' => 'asc']) === '001', 'By what was promised, soonest first');
ok($firstOrder(['sort' => 'promised', 'dir' => 'desc']) === '004', '  ...and latest first');
$byCustomer = jewellery_pending_delivery($cid, ['sort' => 'customer', 'dir' => 'asc']);
ok((string) $byCustomer[0]['order_no'] === '001', 'By customer name, alphabetically (Sunita Sharma before Walk-in Ajay)');
ok((string) $byCustomer[1]['order_no'] === '002', '  ...then the next name');
$namedLast = (string) $byCustomer[count($byCustomer) - 1]['order_no'];
ok(in_array($namedLast, ['003', '004'], true), '  ...and the ones naming nobody sort to the end');
$byOrigin = jewellery_pending_delivery($cid, ['sort' => 'origin', 'dir' => 'asc']);
ok((string) $byOrigin[0]['origin'] === 'assignment', 'Sorting by origin groups them');

echo "\n== A sort key cannot choose its own ORDER BY ==\n";
$injected = jewellery_pending_delivery($cid, ['sort' => 'o.id; DROP TABLE jewellery_orders', 'dir' => 'asc']);
ok(count($injected) === 4, 'A sort key nobody offers falls back to the default instead of reaching the query');
ok((int) db()->query("SELECT COUNT(*) FROM jewellery_orders WHERE company_id=$cid")->fetchColumn() === 4, '  ...and the table is still there');
$injectedDir = jewellery_pending_delivery($cid, ['sort' => 'weight', 'dir' => 'asc; DELETE FROM jewellery_orders']);
ok(count($injectedDir) === 4, 'A direction nobody offers falls back to ascending');

echo "\n== Filtering and sorting hold together ==\n";
$both = jewellery_pending_delivery($cid, ['origin' => 'customer', 'sort' => 'weight', 'dir' => 'desc']);
ok(count($both) === 2, 'Filtered to customer orders');
ok((string) $both[0]['order_no'] === '001', '  ...heaviest first within them');

echo "\n== Shape ==\n";
$q0 = questions();
jewellery_pending_delivery($cid, ['origin' => 'customer', 'sort' => 'weight', 'dir' => 'desc']);
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
ok(count($splitRows) === 4, 'The split order is one row, not two');
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
ok((int) $splitCounts['all'] === 4, 'The counts still say four are waiting');
ok((int) $splitCounts['customer'] === 2, '  ...two of them customer orders');
ok(count(jewellery_pending_delivery($cid, ['origin' => 'customer'])) === 2, 'Filtering does not double it either');


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
