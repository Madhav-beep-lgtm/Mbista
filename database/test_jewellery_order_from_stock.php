<?php
declare(strict_types=1);

/**
 * Ordering a piece the shop ALREADY HAS.
 *
 * A customer points at a ring in the case. That is an order — a customer, an
 * advance, a promised day and a bill — but there is nothing for a kaligad to
 * make, and if the module treats it as work it will send a craftsman off to
 * make a second ring the shop never wanted.
 *
 * So this proves the whole of that arrangement (migration 106):
 *   - the Ready to Sale shelf is what an order may pick from, and only that
 *   - the PIECE states its own facts; a weight retyped at the counter loses
 *   - the piece is reserved: one ring cannot be promised to two customers
 *   - no kaligad is ever assigned to it, on any screen or by any call
 *   - the order is 'received' the moment it is written, and goes to delivery
 *   - a cancelled order hands the piece back
 *
 *   php database/test_jewellery_order_from_stock.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/jewellery_assign.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $tol = 0.011): bool { return abs($a - $b) < $tol; }
function why(callable $fn): string { try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); } }

function jwfs_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'JWFS1'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$s)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['jewellery_order_receipts', 'jewellery_assignment_components', 'jewellery_order_lines',
                  'jewellery_order_assignments', 'jewellery_orders', 'jewellery_karigars',
                  'jewellery_settlement_allocations', 'jewellery_settlements', 'jewellery_bills',
                  'jewellery_sale_lines', 'jewellery_sales', 'jewellery_purchase_lines', 'jewellery_purchases',
                  'jewellery_stock_txns', 'jewellery_item_profiles', 'inventory_items', 'jewellery_daily_rates',
                  'inventory_ledger_mappings', 'jewellery_settings', 'jewellery_purities', 'jewellery_metals',
                  'jewellery_units'] as $t) {
            db()->exec("DELETE FROM `$t` WHERE company_id=$s");
        }
        db()->exec("DELETE FROM accounting_parties WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email = 'jwfromstock@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
jwfs_cleanup();

// ---------------------------------------------------------------------------
// Fixture — one shop, stocked, with a kaligad and a customer
// ---------------------------------------------------------------------------
db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,1)')
    ->execute(['n' => 'Shelf Jewellers (Books)', 'c' => 'JWFS1']);
$cid = (int) db()->lastInsertId();
$uid = create_user(['name' => 'Shelf Owner', 'email' => 'jwfromstock@test.local', 'password' => 'Secret#12345',
    'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, jewellery_accounting_enabled)
        VALUES (:uid,:cid,:books,:org,:code,1,1)')
    ->execute(['uid' => $uid, 'cid' => $cid, 'books' => $cid, 'org' => 'Shelf Jewellers', 'code' => 'JWFS1-C']);
$fy = create_fiscal_year($cid, 'JWFS1 2026/27', '2026-07-16', '2027-07-15', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId = (int) $fy['id'];
$_SESSION['company_id'] = $cid;
jewellery_settings($cid);

$q = static fn (string $sql): int => (int) db()->query($sql)->fetchColumn();
$gold = $q("SELECT id FROM jewellery_metals WHERE company_id=$cid AND code='GOLD'");
$p22 = $q("SELECT id FROM jewellery_purities WHERE company_id=$cid AND metal_id=$gold AND code='22K'");
$tola = $q("SELECT id FROM jewellery_units WHERE company_id=$cid AND code='TOLA'");

$mkLedger = static function (string $code, string $name, string $master) use ($cid): int {
    db()->prepare('INSERT INTO ledger_groups (company_id,name,code,master_key) VALUES (:cid,:n,:c,:m)')
        ->execute(['cid' => $cid, 'n' => 'JW ' . $code, 'c' => 'G' . $code, 'm' => $master]);
    $gid = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO ledgers (company_id,group_id,name,code) VALUES (:cid,:g,:n,:c)')
        ->execute(['cid' => $cid, 'g' => $gid, 'n' => $name, 'c' => $code]);

    return (int) db()->lastInsertId();
};
foreach ([
    ['stock_metal', 'STKM', 'Metal Stock', 'assets'],
    ['stock_finished', 'STKF', 'Finished Stock', 'assets'],
    ['stock_karigar', 'STKK', 'Metal with Karigar', 'assets'],
    ['making_expense', 'MAKE', 'Making Charges', 'expenses'],
    ['wastage_loss', 'WAST', 'Wastage Loss', 'expenses'],
    ['karigar_payable', 'KARP', 'Karigar Payable', 'liabilities'],
    ['cogs', 'COGS', 'Cost of Goods Sold', 'expenses'],
    ['sales_metal', 'SALM', 'Sales Metal', 'income'],
    ['sales_making', 'SALK', 'Sales Making', 'income'],
    ['sales_stone', 'SALS', 'Sales Stone', 'income'],
    ['vat_output', 'VATO', 'VAT Output', 'liabilities'],
    ['vat_input', 'VATI', 'VAT Input', 'assets'],
    ['spt_output', 'SPTO', 'SD Tax Output', 'liabilities'],
    ['spt_input', 'SPTI', 'SD Tax Input', 'assets'],
] as [$purpose, $code, $name, $master]) {
    jewellery_save_mapping($cid, $purpose, $mkLedger($code, $name, $master), $uid);
}
$cash = $mkLedger('CASHJ', 'Cash', 'assets');

db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'SUP1','Bullion House','supplier','active')")->execute(['c' => $cid]);
$supplier = (int) db()->lastInsertId();
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'CUS1','Gita Sharma','customer','active')")->execute(['c' => $cid]);
$gita = (int) db()->lastInsertId();
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'CUS2','Hari Thapa','customer','active')")->execute(['c' => $cid]);
$hari = (int) db()->lastInsertId();

$ring = jewellery_save_item($cid, ['code' => 'RING22', 'name' => '22K Ring', 'item_type' => 'ornament',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'gross_weight' => 0], $uid);
$bar = jewellery_save_item($cid, ['code' => 'BAR22', 'name' => '22K Bar', 'item_type' => 'bullion',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'gross_weight' => 0], $uid);
$pIn = jewellery_save_purchase($cid, $fyId, ['purchase_date' => '2026-08-01', 'settle_mode' => 'cash',
    'settle_ledger_id' => $cash, 'party_id' => $supplier],
    [['item_id' => $bar, 'gross_weight' => 20, 'rate' => 137400]], $uid);
jewellery_post_purchase($cid, $pIn, $uid);

$karigar = jewellery_save_karigar($cid, ['code' => 'BHARAT', 'name' => 'Bharat Shakya',
    'engagement_type' => 'contractor', 'default_making_basis' => 'flat',
    'default_making_rate' => 4000, 'wastage_allowed_pct' => 1.0], $uid);

/** Make one showroom piece and put it on the shelf; returns its receipt id. */
$makeShelfPiece = static function (string $ornament, float $issue, float $back, float $stone, string $size = '')
        use ($cid, $fyId, $uid, $karigar, $ring, $bar, $p22, $tola): int {
    $assigned = jewellery_save_assignment($cid, $fyId, [
        'assign_kind' => 'self', 'karigar_id' => $karigar, 'item_id' => $ring,
        'purity_id' => $p22, 'unit_id' => $tola, 'expected_gross_weight' => $back,
        'expected_stone_weight' => $stone, 'expected_ornament' => $ornament, 'size_design' => $size,
        'assigned_date' => '2026-08-05', 'expected_delivery' => '2026-08-20',
        'making_basis' => 'flat', 'making_rate' => 4000,
    ], $uid);
    if (!$assigned['ok']) {
        throw new RuntimeException('fixture assignment: ' . implode(' ', $assigned['errors']));
    }
    $aid = (int) $assigned['id'];
    $out = jewellery_issue_component($cid, $fyId, $aid, ['item_id' => $bar, 'purity_id' => $p22,
        'unit_id' => $tola, 'gross_weight' => $issue, 'issue_date' => '2026-08-05'], $uid);
    if (!$out['ok']) {
        throw new RuntimeException('fixture issue: ' . $out['error']);
    }
    $backIn = jewellery_receive_from_karigar($cid, $fyId, [
        'assignment_id' => $aid, 'received_item_id' => $ring, 'received_purity_id' => $p22,
        'unit_id' => $tola, 'received_gross_weight' => $back, 'stone_weight' => $stone,
        'qty_pieces' => 1, 'receive_date' => '2026-08-18', 'making_amount' => 4000,
    ], $uid);
    if (!$backIn['ok']) {
        throw new RuntimeException('fixture receive: ' . $backIn['error']);
    }

    return (int) $backIn['receipt_id'];
};

echo "\n1. The shelf is what an order may pick from\n";
$piece = $makeShelfPiece('Solitaire ring', 2.0, 1.9, 0.1, 'ring 14');
$second = $makeShelfPiece('Filigree bangle', 3.0, 2.85, 0.0);
$shelf = jewellery_ready_to_sale($cid);
ok(count($shelf) === 2, 'Both showroom pieces are on the Ready to Sale shelf');
ok(array_column($shelf, 'reserved_order_no') === [null, null], 'Nobody has spoken for either of them yet');
ok(count(jewellery_ready_to_sale_options($cid)) === 2, 'So the order form is offered both');

// A customer's piece is NOT shelf stock: it came back for the person who
// ordered it, and offering it to the next customer would sell it twice.
$hisOrder = jewellery_save_order($cid, $fyId, ['order_date' => '2026-08-06', 'party_id' => $hari,
    'status' => 'confirmed'], [['item_id' => $ring, 'purity_id' => $p22, 'unit_id' => $tola,
        'qty_pieces' => 1, 'gross_weight' => 2.0, 'rate' => 150000, 'karigar_id' => $karigar,
        'delivery_date' => '2026-08-30']], $uid);
$hisLine = jewellery_order_line_rows($cid, $hisOrder)[0];
$hisAssign = jewellery_save_assignment($cid, $fyId, ['assign_kind' => 'customer', 'order_id' => $hisOrder,
    'order_line_id' => (int) $hisLine['id'], 'karigar_id' => $karigar, 'assigned_date' => '2026-08-06'], $uid);
ok($hisAssign['ok'], 'A customer order still goes out to a kaligad the usual way');
jewellery_issue_component($cid, $fyId, (int) $hisAssign['id'], ['item_id' => $bar, 'purity_id' => $p22,
    'unit_id' => $tola, 'gross_weight' => 2.1, 'issue_date' => '2026-08-06'], $uid);
$hisBack = jewellery_receive_from_karigar($cid, $fyId, ['assignment_id' => (int) $hisAssign['id'],
    'received_item_id' => $ring, 'received_purity_id' => $p22, 'unit_id' => $tola,
    'received_gross_weight' => 2.0, 'qty_pieces' => 1, 'receive_date' => '2026-08-19', 'making_amount' => 4000], $uid);
ok($hisBack['ok'], 'And it comes back' . ($hisBack['ok'] ? '' : ' — ' . $hisBack['error']));
ok(count(jewellery_ready_to_sale($cid)) === 2,
    "Hari's ring is NOT on the shelf — it came back for him, not for the showroom");
$claimHis = why(static fn () => jewellery_save_order($cid, $fyId, ['order_date' => '2026-08-20', 'party_id' => $gita,
    'status' => 'confirmed'], [['item_id' => $ring, 'stock_receipt_id' => (int) $hisBack['receipt_id'],
        'rate' => 150000]], $uid));
ok(str_contains($claimHis, 'Ready to Sale'),
    'And an order cannot claim it — only what is on the shelf may be sold off the shelf: ' . $claimHis);

echo "\n2. The piece states its own facts\n";
// Everything physical is typed WRONG on purpose. The piece wins every one.
$order = jewellery_save_order($cid, $fyId, [
    'order_date' => '2026-08-20', 'party_id' => $gita, 'status' => 'confirmed',
], [[
    'item_id' => $bar,                 // wrong item
    'purity_id' => $p22, 'unit_id' => $tola,
    'stock_receipt_id' => $piece,
    'qty_pieces' => 9, 'gross_weight' => 99.0, 'stone_weight' => 44.0,
    'rate' => 150000, 'making_amount' => 6000,
    'karigar_id' => $karigar, 'delivery_date' => '2026-09-30',
]], $uid);
$line = jewellery_order_line_rows($cid, $order)[0];
ok((string) $line['source'] === 'stock', 'The line is recorded as coming off the shelf');
ok((int) $line['stock_receipt_id'] === $piece, 'And it names the exact piece it is holding');
ok((int) $line['item_id'] === $ring, 'The item is read off the piece, not off the form');
ok(near((float) $line['gross_weight'], 1.9) && near((float) $line['stone_weight'], 0.1),
    'So are its weights — a figure retyped at the counter cannot bill one ring at another ring\'s weight');
ok(near((float) $line['qty_pieces'], 1.0), 'And the whole of the piece is what the order holds');
ok(near((float) $line['making_amount'], 6000.0),
    'But the MAKING is the shop\'s to set — that is the deal being struck, not a fact about the object');
ok($line['karigar_id'] === null, 'No kaligad is put against it');
ok($line['delivery_date'] === null, 'And no day to wait for — it is already made');
ok((string) $line['stock_assignment_no'] !== '', 'The line can name the assignment the piece came back on');

echo "\n3. The order is finished the moment it is written\n";
ok((string) jewellery_order($cid, $order)['status'] === 'received',
    'An order of nothing but shelf pieces is "received" straight away — nothing is being waited for');
ok(in_array($order, array_map(static fn (array $r): int => (int) $r['id'], jewellery_pending_delivery($cid)), true),
    'So it is on the ready-to-deliver board, not lost at "confirmed" for ever');
// The self-repair recomputes every order's status on EVERY page load. A rule
// it does not know is a rule it silently undoes — this exact step knocked
// shelf orders back to 'confirmed' seconds after they were written, and the
// customer's ring dropped off the delivery board again.
accounting_module_repair_database();
ok((string) jewellery_order($cid, $order)['status'] === 'received',
    'And it survives the self-repair, which recomputes order status on every page load');

echo "\n4. No kaligad is ever assigned to it\n";
$payloadLines = [];
foreach (jewellery_assign_order_payload($cid) as $payloadOrder) {
    foreach ($payloadOrder['lines'] as $payloadLine) {
        $payloadLines[] = (int) $payloadLine['id'];
    }
}
ok(!in_array((int) $line['id'], $payloadLines, true), 'The Kaligad Assign screen does not offer it');
ok(!in_array((int) $line['id'], array_map(static fn (array $r): int => (int) $r['id'], jewellery_pending_order_lines($cid)), true),
    'Nor does the board of items still waiting to go out');
$refused = jewellery_save_assignment($cid, $fyId, ['assign_kind' => 'customer', 'order_id' => $order,
    'order_line_id' => (int) $line['id'], 'karigar_id' => $karigar, 'assigned_date' => '2026-08-21'], $uid);
ok(!$refused['ok'] && str_contains(implode(' ', $refused['errors']), 'Ready to Sale'),
    'And a call that tries anyway is refused in a sentence: ' . implode(' ', $refused['errors']));

echo "\n5. One ring, one customer\n";
$onShelf = jewellery_ready_to_sale($cid);
$heldRow = null;
foreach ($onShelf as $shelfRow) {
    if ((int) $shelfRow['receipt_id'] === $piece) { $heldRow = $shelfRow; }
}
ok((int) ($heldRow['reserved_order_id'] ?? 0) === $order, 'The shelf says who the piece is being held for');
ok((string) ($heldRow['reserved_for'] ?? '') === 'Gita Sharma', 'By name: ' . (string) ($heldRow['reserved_for'] ?? ''));
ok(count(jewellery_ready_to_sale_options($cid)) === 1, 'And it is no longer offered to the next customer');
ok(count(jewellery_ready_to_sale_options($cid, $order)) === 2,
    'Except to the order already holding it — revising an order must not strike out its own items');

$clash = why(static fn () => jewellery_save_order($cid, $fyId, ['order_date' => '2026-08-21',
    'party_id' => $hari, 'status' => 'confirmed'],
    [['item_id' => $ring, 'stock_receipt_id' => $piece, 'rate' => 150000]], $uid));
ok(str_contains($clash, 'already promised') && str_contains($clash, 'Gita Sharma'),
    'A second customer is told who has it: ' . $clash);

$twice = why(static fn () => jewellery_save_order($cid, $fyId, ['order_date' => '2026-08-21',
    'party_id' => $hari, 'status' => 'confirmed'], [
        ['item_id' => $ring, 'stock_receipt_id' => $second, 'rate' => 150000],
        ['item_id' => $ring, 'stock_receipt_id' => $second, 'rate' => 150000],
    ], $uid));
ok(str_contains($twice, 'There is only one of it'), 'The same ring twice on ONE order is caught too: ' . $twice);

// Re-saving the order it is already on must not find itself in the way.
jewellery_save_order($cid, $fyId, ['id' => $order, 'order_date' => '2026-08-20', 'party_id' => $gita,
    'status' => 'confirmed'], [['line_id' => (int) $line['id'], 'item_id' => $ring,
        'stock_receipt_id' => $piece, 'rate' => 152000]], $uid);
$resaved = jewellery_order_line_rows($cid, $order)[0];
ok((int) $resaved['stock_receipt_id'] === $piece && near((float) $resaved['rate'], 152000.0),
    'Re-saving the holder keeps its piece and takes the new price');

echo "\n6. A cancelled order hands the piece back\n";
$walkAway = jewellery_save_order($cid, $fyId, ['order_date' => '2026-08-22', 'party_id' => $hari,
    'status' => 'confirmed'], [['item_id' => $ring, 'stock_receipt_id' => $second, 'rate' => 150000]], $uid);
ok(count(jewellery_ready_to_sale_options($cid)) === 0, 'Both pieces are now spoken for');
ok(jewellery_cancel_order($cid, $walkAway, 'changed their mind', $uid)['ok'], 'The customer walks away');
ok(count(jewellery_ready_to_sale_options($cid)) === 1, 'And the bangle goes back on the shelf');
$cancelledLine = jewellery_order_line_rows($cid, $walkAway)[0];
ok((int) $cancelledLine['stock_receipt_id'] === $second,
    'The cancelled order still records WHICH piece it once held — releasing it is not forgetting it');

echo "\n7. A shelf order can still be removed outright\n";
// It is 'received' from the minute it is written, and that word used to bar
// deletion — so an order taken by mistake could never be taken back off the
// books. Nothing has happened to it: no kaligad, no metal, no bill.
$mistake = jewellery_save_order($cid, $fyId, ['order_date' => '2026-08-22', 'party_id' => $hari,
    'status' => 'confirmed'], [['item_id' => $ring, 'stock_receipt_id' => $second, 'rate' => 150000]], $uid);
ok((string) jewellery_order($cid, $mistake)['status'] === 'received', 'It is "received" as soon as it is written');
ok(jewellery_delete_order($cid, $mistake), 'And it can still be deleted — nothing has happened to it');
ok(count(jewellery_ready_to_sale_options($cid)) === 1, 'Deleting it hands the piece back to the shelf');

echo "\n8. An order can be part shelf, part workshop\n";
$mixed = jewellery_save_order($cid, $fyId, ['order_date' => '2026-08-23', 'party_id' => $hari,
    'delivery_date' => '2026-09-15', 'status' => 'confirmed'], [
        ['item_id' => $ring, 'stock_receipt_id' => $second, 'rate' => 150000],
        ['item_id' => $ring, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
         'gross_weight' => 4.0, 'rate' => 150000, 'karigar_id' => $karigar, 'delivery_date' => '2026-09-10'],
    ], $uid);
$mixedLines = jewellery_order_line_rows($cid, $mixed);
ok((string) $mixedLines[0]['source'] === 'stock' && (string) $mixedLines[1]['source'] === 'workshop',
    'One item off the shelf, one to be made, on one order');
ok($mixedLines[0]['karigar_id'] === null && (int) $mixedLines[1]['karigar_id'] === $karigar,
    'Only the one being made has a craftsman against it');
ok((string) jewellery_order($cid, $mixed)['status'] === 'partially_received',
    'Half of it is already in hand, so the order reads partially_received');
$mixedPayload = [];
foreach (jewellery_assign_order_payload($cid) as $payloadOrder) {
    if ((int) $payloadOrder['id'] === $mixed) {
        $mixedPayload = array_map(static fn (array $r): int => (int) $r['id'], $payloadOrder['lines']);
    }
}
ok($mixedPayload === [(int) $mixedLines[1]['id']],
    'And the assign screen offers exactly the item that has to be made — no more, no less');

echo "\n9. Refusals a person can act on\n";
ok(str_contains(why(static fn () => jewellery_save_order($cid, $fyId, ['order_date' => '2026-08-24',
    'party_id' => $hari, 'status' => 'confirmed'],
    [['item_id' => $ring, 'source' => 'stock', 'rate' => 150000]], $uid)), 'choose which piece'),
    'Saying "off the shelf" without saying which piece is refused, and says so');
ok(str_contains(why(static fn () => jewellery_save_order($cid, $fyId, ['order_date' => '2026-08-24',
    'party_id' => $hari, 'status' => 'confirmed'],
    [['item_id' => $ring, 'stock_receipt_id' => 999999, 'rate' => 150000]], $uid)), 'Ready to Sale'),
    'A piece that is not on this company\'s shelf is refused');

echo "\n10. Billing the order bills the piece\n";
$prefill = jewellery_order_sale_prefill($cid, $order);
ok($prefill['ok'], 'The order prefills a sale');
ok((int) $prefill['lines'][0]['item_id'] === $ring
    && near((float) $prefill['lines'][0]['gross_weight'], 1.9)
    && near((float) $prefill['lines'][0]['stone_weight'], 0.1),
    'And the bill carries the piece\'s own item and weights, not a workshop receipt\'s');

jwfs_cleanup();
echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail === 0 ? 0 : 1);
