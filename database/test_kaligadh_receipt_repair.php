<?php
declare(strict_types=1);

/**
 * database/repair_kaligadh_receipts.php, against books broken the way the old
 * receive path broke them.
 *
 * The repair cannot be tested by posting a receipt and looking at it — the
 * receipt is CORRECT now. So a good one is posted and then taken apart into
 * exactly the shape the old code left behind: the stones' value struck off both
 * sides of the voucher, the stone movements deleted, the finished piece's value
 * reduced to the metal alone. That is a real broken receipt, built from the
 * fault's own signature, and the repair either puts it back or it does not.
 *
 *   php database/test_kaligadh_receipt_repair.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require_once $root . '/app/accounting_module_repair.php';
require_once $root . '/app/jewellery_reports.php';
require_once $root . '/app/jewellery_assign.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $tol = 0.011): bool { return abs($a - $b) < $tol; }

/** What one ledger stands at across every voucher of a company. */
function led_balance(int $companyId, int $ledgerId): float
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN e.entry_type='debit' THEN e.amount ELSE -e.amount END),0)
        FROM voucher_entries e INNER JOIN vouchers v ON v.id = e.voucher_id
        WHERE v.company_id = :cid AND e.ledger_id = :lid");
    $stmt->execute(['cid' => $companyId, 'lid' => $ledgerId]);

    return round((float) $stmt->fetchColumn(), 2);
}

function krr_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'KRREP'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$s)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['jewellery_assignment_components', 'jewellery_order_receipts', 'jewellery_order_assignments',
                  'jewellery_order_lines', 'jewellery_orders', 'jewellery_karigars', 'jewellery_bills',
                  'jewellery_purchase_lines', 'jewellery_purchases', 'jewellery_stock_txns',
                  'jewellery_item_profiles', 'inventory_items', 'jewellery_daily_rates',
                  'inventory_ledger_mappings', 'jewellery_settings', 'jewellery_purities',
                  'jewellery_metals', 'jewellery_units'] as $t) {
            db()->exec("DELETE FROM `$t` WHERE company_id=$s");
        }
        db()->exec("DELETE FROM accounting_parties WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email = 'krrep@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
krr_cleanup();

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------
db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,1)')
    ->execute(['n' => 'Repair Test Jewellers (Books)', 'c' => 'KRREP']);
$cid = (int) db()->lastInsertId();
$uid = create_user(['name' => 'Repair Owner', 'email' => 'krrep@test.local', 'password' => 'Secret#12345',
    'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, jewellery_accounting_enabled)
        VALUES (:uid,:cid,:books,:org,:code,1,1)')
    ->execute(['uid' => $uid, 'cid' => $cid, 'books' => $cid, 'org' => 'Repair Test Jewellers', 'code' => 'KRREP-C']);
$fy = create_fiscal_year($cid, 'KRREP 2026/27', '2026-07-16', '2027-07-15', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId = (int) $fy['id'];
$_SESSION['company_id'] = $cid;
jewellery_settings($cid);

$q = static fn (string $sql): int => (int) db()->query($sql)->fetchColumn();
$gold = $q("SELECT id FROM jewellery_metals WHERE company_id=$cid AND code='GOLD'");
$p22 = $q("SELECT id FROM jewellery_purities WHERE company_id=$cid AND metal_id=$gold AND code='22K'");
$tola = $q("SELECT id FROM jewellery_units WHERE company_id=$cid AND code='TOLA'");
$dia = $q("SELECT id FROM jewellery_metals WHERE company_id=$cid AND code='DIAMOND'");
$pStd = $q("SELECT id FROM jewellery_purities WHERE company_id=$cid AND metal_id=$dia AND code='STD'");
$carat = $q("SELECT id FROM jewellery_units WHERE company_id=$cid AND code='CT'");

$mkLedger = static function (int $companyId, string $code, string $name, string $master): int {
    db()->prepare('INSERT INTO ledger_groups (company_id,name,code,master_key) VALUES (:cid,:n,:c,:m)')
        ->execute(['cid' => $companyId, 'n' => 'KR ' . $code, 'c' => 'G' . $code, 'm' => $master]);
    $gid = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO ledgers (company_id,group_id,name,code) VALUES (:cid,:g,:n,:c)')
        ->execute(['cid' => $companyId, 'g' => $gid, 'n' => $name, 'c' => $code]);

    return (int) db()->lastInsertId();
};
$L = [];
foreach ([
    ['stock_metal', 'STKM', 'Metal Stock', 'assets'],
    ['stock_finished', 'STKF', 'Finished Stock', 'assets'],
    ['stock_stone', 'STKS', 'Stone Stock', 'assets'],
    ['stock_karigar', 'STKK', 'Metal with Karigar', 'assets'],
    ['making_expense', 'MAKE', 'Making Charges', 'expenses'],
    ['wastage_loss', 'WAST', 'Wastage Loss', 'expenses'],
    ['karigar_payable', 'KARP', 'Karigar Payable', 'liabilities'],
    ['cogs', 'COGS', 'Cost of Goods Sold', 'expenses'],
] as [$purpose, $code, $name, $master]) {
    $L[$purpose] = $mkLedger($cid, $code, $name, $master);
    jewellery_save_mapping($cid, $purpose, $L[$purpose], $uid);
}
$cash = $mkLedger($cid, 'CASHK', 'Cash', 'assets');
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'SUP1','Supplier','supplier','active')")->execute(['c' => $cid]);
$supplier = (int) db()->lastInsertId();

$chain = jewellery_save_item($cid, ['code' => 'CH22', 'name' => '22K Chain', 'item_type' => 'ornament',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'gross_weight' => 0], $uid);
$stone = jewellery_save_item($cid, ['code' => 'DIA1', 'name' => 'Loose Diamond', 'item_type' => 'stone',
    'metal_id' => $dia, 'purity_id' => $pStd, 'unit_id' => $carat, 'gross_weight' => 0], $uid);

// 20 tola of 22K = 18.32 fine for 2,748,000 -> exactly 150,000 per fine tola.
$p1 = jewellery_save_purchase($cid, $fyId, ['purchase_date' => '2026-08-01', 'settle_mode' => 'cash',
    'settle_ledger_id' => $cash, 'party_id' => $supplier], [['item_id' => $chain, 'gross_weight' => 20, 'rate' => 137400]], $uid);
jewellery_post_purchase($cid, $p1, $uid);
$p2 = jewellery_save_purchase($cid, $fyId, ['purchase_date' => '2026-08-01', 'settle_mode' => 'cash',
    'settle_ledger_id' => $cash, 'party_id' => $supplier], [['item_id' => $stone, 'gross_weight' => 10, 'rate' => 10000]], $uid);
jewellery_post_purchase($cid, $p2, $uid);

$karigarId = jewellery_save_karigar($cid, ['code' => 'RAM', 'name' => 'Ram Shakya',
    'engagement_type' => 'contractor', 'default_making_basis' => 'flat', 'default_making_rate' => 5000], $uid);
$karigar = jewellery_karigar($cid, $karigarId);
$ramLedger = jw_karigar_metal_ledger_id($cid, $karigar);
$chainStockLedger = jw_item_stock_ledger_id($cid, jewellery_item($cid, $chain));

echo "\n1. A stone-set job, posted correctly by the fixed code\n";
$issue = jewellery_issue_to_karigar($cid, $fyId, [
    'karigar_id' => $karigarId, 'item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola,
    'issued_gross_weight' => 2, 'issue_date' => '2026-09-01', 'making_basis' => 'flat', 'making_rate' => 5000,
], $uid);
$aid = (int) $issue['assignment_id'];
ok(jewellery_issue_component($cid, $fyId, $aid, ['item_id' => $stone, 'purity_id' => $pStd,
    'unit_id' => $carat, 'gross_weight' => 3, 'issue_date' => '2026-09-01'], $uid)['ok'],
    'Three carats go out with the two tola');
$assignment = jewellery_assignment($cid, $aid);
$stoneAmount = round((float) $assignment['issued_stone_amount'], 2);
$issuedAmount = round((float) $assignment['issued_amount'], 2);
ok(near($stoneAmount, 30000.0) && near($issuedAmount, 274800.0),
    'He holds 274,800 of gold and 30,000 of stones');

$rec = jewellery_receive_from_karigar($cid, $fyId, [
    'assignment_id' => $aid, 'received_item_id' => $chain, 'received_purity_id' => $p22,
    'received_gross_weight' => 2.6, 'stone_weight' => 0.6, 'qty_pieces' => 1, 'receive_date' => '2026-09-05',
], $uid);
ok($rec['ok'], 'The ring comes back' . ($rec['ok'] ? '' : ' — ' . $rec['error']));
$receiptId = (int) $rec['receipt_id'];
$receiptVoucherId = (int) $rec['voucher_id'];
// From the row, not the return value — jewellery_receive_from_karigar hands
// back ids and the settlement, not the number. Reading a key that is not there
// gave an empty string, and "POST  receipt " then matched the report whatever
// it said: an assertion that could not fail.
$receiptNo = (string) db()->query("SELECT receipt_no FROM jewellery_order_receipts WHERE id=$receiptId")->fetchColumn();
ok($receiptNo !== '', 'The receipt has a number to look for in the report');

// A work order settled at zero, which the repair must REPORT and never touch.
// Posted BEFORE the snapshot below, because it debits the same finished-stock
// ledger: a baseline taken above it would be measuring this receipt as well as
// the one being repaired.
$workIssue = jewellery_issue_to_karigar($cid, $fyId, [
    'karigar_id' => $karigarId, 'item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola,
    'issued_gross_weight' => 0, 'issue_date' => '2026-09-10', 'making_basis' => 'flat', 'making_rate' => 900,
], $uid);
$workRec = jewellery_receive_from_karigar($cid, $fyId, [
    'assignment_id' => (int) $workIssue['assignment_id'], 'received_item_id' => $chain,
    'received_purity_id' => $p22, 'received_gross_weight' => 1, 'qty_pieces' => 1,
    'receive_date' => '2026-09-12', 'own_metal_rate' => '150000',
], $uid);
ok($workRec['ok'], 'A work order is posted too' . ($workRec['ok'] ? '' : ' — ' . $workRec['error']));
$workReceiptId = (int) $workRec['receipt_id'];
db()->prepare('UPDATE jewellery_order_receipts SET avg_fine_rate = 0 WHERE id = :id')->execute(['id' => $workReceiptId]);

// The state the repair has to restore, taken while it is still right.
$goodRam = led_balance($cid, $ramLedger);
$goodStock = led_balance($cid, $chainStockLedger);
$goodStoneHeld = jw_item_balance($cid, $stone, null, 'karigar', $karigarId)['fine_weight'];
$goodStoneShelf = jw_item_balance($cid, $stone, null, 'stock')['fine_weight'];
ok(near($goodStoneHeld, 0.0), 'Correctly posted, he is holding no stones');

echo "\n2. Broken back into the shape the old code left\n";
// The stones struck off both sides of the voucher, their movements deleted and
// the finished piece reduced to the metal alone — which is precisely what a
// receipt posted before the fix looks like.
db()->prepare("UPDATE voucher_entries SET amount = amount - :s
    WHERE voucher_id = :v AND ledger_id = :l AND entry_type = 'credit'")
    ->execute(['s' => $stoneAmount, 'v' => $receiptVoucherId, 'l' => $ramLedger]);
db()->prepare("UPDATE voucher_entries SET amount = amount - :s
    WHERE voucher_id = :v AND ledger_id = :l AND entry_type = 'debit'")
    ->execute(['s' => $stoneAmount, 'v' => $receiptVoucherId, 'l' => $chainStockLedger]);
db()->prepare("DELETE FROM jewellery_stock_txns WHERE company_id = :cid AND source_type = 'jewellery_order_receipt'
        AND source_id = :sid AND direction = 'out' AND item_id = :item")
    ->execute(['cid' => $cid, 'sid' => $receiptId, 'item' => $stone]);
db()->prepare("UPDATE jewellery_stock_txns SET amount = amount - :s WHERE company_id = :cid
        AND source_type = 'jewellery_order_receipt' AND source_id = :sid AND direction = 'in' AND holder_type = 'stock'")
    ->execute(['s' => $stoneAmount, 'cid' => $cid, 'sid' => $receiptId]);

ok(near(led_balance($cid, $ramLedger), $goodRam + $stoneAmount),
    'His ledger is now 30,000 heavy — the stones never came off it');
ok(near(jw_item_balance($cid, $stone, null, 'karigar', $karigarId)['fine_weight'], 3.0),
    'And the register says he still has the diamonds');
ok(near(led_balance($cid, $chainStockLedger), $goodStock - $stoneAmount),
    'While the finished ring is short by the same 30,000');

// The script is the thing being tested, so it is run AS a script — PHP_BINARY
// rather than "php", which is not on every PATH the suite runs from.
$run = static function (string $flags = ''): string {
    $script = escapeshellarg(dirname(__DIR__) . '/database/repair_kaligadh_receipts.php');

    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . $script
        . ($flags !== '' ? ' ' . $flags : '') . ' 2>&1');
};

echo "\n3. Preview reports it and changes nothing\n";
$preview = $run();
ok(strpos($preview, 'PREVIEW MODE') !== false, 'It says it is only previewing');
ok(strpos($preview, 'POST  receipt ' . $receiptNo) !== false,
    'The stone-set receipt is listed for repair');
ok(near(led_balance($cid, $ramLedger), $goodRam + $stoneAmount),
    'And the preview posted nothing — his ledger is untouched');
ok(strpos($preview, 'settled a work order at a ZERO rate') !== false,
    'The zero-rate work order is reported');
ok(strpos($preview, 'per fine') !== false,
    'With a suggested rate, so somebody has a figure to argue from');

echo "\n4. Applied, the books come back\n";
$applied = $run('--apply');
ok(strpos($applied, 'DONE  receipt ' . $receiptNo) !== false, 'It reports the repair done');
ok(near(led_balance($cid, $ramLedger), $goodRam),
    'His ledger is back to exactly where a correct receipt left it');
ok(near(led_balance($cid, $chainStockLedger), $goodStock),
    'And the finished ring carries the stones again');
ok(near(jw_item_balance($cid, $stone, null, 'karigar', $karigarId)['fine_weight'], $goodStoneHeld),
    'The diamonds are off his holding');
ok(near(jw_item_balance($cid, $stone, null, 'stock')['fine_weight'], $goodStoneShelf),
    'And the shelf reads the same as it would have all along');
$repairVoucher = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid
    AND source_type='jewellery_stone_return_repair' AND source_id=$receiptId")->fetchColumn();
ok($repairVoucher === 1, 'One correcting voucher, sourced on the receipt');
$balanced = db()->query("SELECT COALESCE(SUM(CASE WHEN e.entry_type='debit' THEN e.amount ELSE -e.amount END),0)
    FROM voucher_entries e INNER JOIN vouchers v ON v.id=e.voucher_id
    WHERE v.company_id=$cid AND v.source_type='jewellery_stone_return_repair'")->fetchColumn();
ok(near((float) $balanced, 0.0), 'Which balances');

echo "\n5. It is safe to run twice\n";
$again = $run('--apply');
ok(strpos($again, 'DONE  receipt ' . $receiptNo) === false, 'A repaired receipt is not repaired again');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid
    AND source_type='jewellery_stone_return_repair' AND source_id=$receiptId")->fetchColumn() === 1,
    'Still one voucher, not two');
ok(near(led_balance($cid, $ramLedger), $goodRam), 'And his ledger did not move a second time');
ok(near(jw_item_balance($cid, $stone, null, 'karigar', $karigarId)['fine_weight'], 0.0),
    'Nor did the stone holding go negative');

echo "\n6. The work order was reported, never rewritten\n";
$workRow = db()->query("SELECT net_payable, making_amount FROM jewellery_order_receipts WHERE id=$workReceiptId")->fetch(PDO::FETCH_ASSOC);
ok($workRow && near((float) $workRow['net_payable'], (float) $workRec['net_payable']),
    'Its settlement is exactly as it was — no script guessed a rate for a bill somebody may have paid');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid
    AND source_type='jewellery_stone_return_repair' AND source_id=$workReceiptId")->fetchColumn() === 0,
    'And nothing was posted against it');

echo "\n7. Reversing a repaired receipt takes the correction with it\n";
// The repair voucher is sourced on the receipt but is not the receipt's own
// voucher_id. Left standing, it would credit the kaligad for stones that had
// just been put back into his hands.
ok(jewellery_unpost_receipt($cid, $receiptId, $uid)['ok'], 'The repaired receipt reverses');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid
    AND source_type='jewellery_stone_return_repair' AND source_id=$receiptId")->fetchColumn() === 0,
    'And the correcting voucher goes with it — not left crediting stones he is holding again');
ok(near(jw_item_balance($cid, $stone, null, 'karigar', $karigarId)['fine_weight'], 3.0),
    'The diamonds are back with the kaligad, all three carats of them');

krr_cleanup();
echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail === 0 ? 0 : 1);
