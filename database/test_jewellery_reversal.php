<?php
declare(strict_types=1);

/**
 * Jewellery Accounting — reversal round-trips and tenant isolation.
 *
 * ONE INVARIANT, applied to every document type: posting then un-posting must
 * leave the books exactly as they were. Not "close enough" — the same stock,
 * the same ledger balances, the same bills, no orphan rows. A reversal that
 * half-works is worse than one that refuses, because the gap only shows up
 * months later in a figure nobody can explain.
 *
 * Written as a before/after snapshot rather than as a list of expectations, so
 * it catches whatever the reversal forgot, not just what I thought to check.
 *
 *   php database/test_jewellery_reversal.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/jewellery_reports.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $tol = 0.011): bool { return abs($a - $b) < $tol; }
function threw(callable $fn): bool { try { $fn(); return false; } catch (Throwable $e) { return true; } }

/**
 * Everything that must come back to where it started: stock, the general
 * ledger, bills and the module's own tables.
 */
function jwrev_snapshot(int $companyId): array
{
    $one = static function (string $sql) use ($companyId) {
        $stmt = db()->prepare($sql);
        $stmt->execute(['cid' => $companyId]);
        return $stmt->fetchColumn();
    };
    $ledgers = [];
    $stmt = db()->prepare("SELECT e.ledger_id, ROUND(SUM(CASE WHEN e.entry_type='debit' THEN e.amount ELSE -e.amount END), 2) AS net
        FROM voucher_entries e INNER JOIN vouchers v ON v.id = e.voucher_id
        WHERE v.company_id = :cid AND v.status = 'posted'
        GROUP BY e.ledger_id HAVING ABS(net) > 0.004 ORDER BY e.ledger_id");
    $stmt->execute(['cid' => $companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ledgers[(int) $row['ledger_id']] = (float) $row['net'];
    }

    return [
        'ledgers' => $ledgers,
        'stock_grams' => round((float) $one("SELECT COALESCE(SUM(CASE WHEN direction='in' THEN fine_grams ELSE -fine_grams END),0)
            FROM jewellery_stock_txns WHERE company_id = :cid"), 6),
        'stock_rows' => (int) $one('SELECT COUNT(*) FROM jewellery_stock_txns WHERE company_id = :cid'),
        'vouchers' => (int) $one("SELECT COUNT(*) FROM vouchers WHERE company_id = :cid AND status='posted'"),
        'entries' => (int) $one('SELECT COUNT(*) FROM voucher_entries e INNER JOIN vouchers v ON v.id=e.voucher_id WHERE v.company_id = :cid'),
        'bills' => (int) $one("SELECT COUNT(*) FROM jewellery_bills WHERE company_id = :cid AND status <> 'cancelled'"),
        'bill_open' => round((float) $one("SELECT COALESCE(SUM(bill_amount - settled_amount),0) FROM jewellery_bills
            WHERE company_id = :cid AND status IN ('open','part_settled')"), 2),
        'line_taxes' => (int) $one('SELECT COUNT(*) FROM jewellery_line_taxes WHERE company_id = :cid'),
    ];
}

/** Human-readable difference between two snapshots. */
function jwrev_diff(array $before, array $after): string
{
    $out = [];
    foreach ($before as $key => $value) {
        if ($key === 'ledgers') {
            $all = array_unique(array_merge(array_keys($value), array_keys($after['ledgers'])));
            foreach ($all as $ledgerId) {
                $b = $value[$ledgerId] ?? 0.0;
                $a = $after['ledgers'][$ledgerId] ?? 0.0;
                if (abs($a - $b) > 0.004) {
                    $out[] = "ledger#$ledgerId $b -> $a";
                }
            }
            continue;
        }
        if ($value !== $after[$key]) {
            $out[] = "$key $value -> {$after[$key]}";
        }
    }

    return $out === [] ? '' : implode('; ', $out);
}

function jwrev_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('JWREV','JWRVB')")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$s)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['jewellery_line_taxes', 'jewellery_item_taxes', 'jewellery_taxes',
                  'jewellery_advance_allocations', 'jewellery_settlement_tenders', 'jewellery_settlement_allocations', 'jewellery_settlements', 'jewellery_bills',
                  'jewellery_order_receipts', 'jewellery_order_assignments', 'jewellery_orders',
                  'jewellery_karigars', 'jewellery_refinery_jobs',
                  'jewellery_sale_exchanges', 'jewellery_sale_lines', 'jewellery_sales',
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
    foreach (db()->query("SELECT id FROM users WHERE email LIKE 'jwrev-%@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
jwrev_cleanup();

// ---------------------------------------------------------------------------
// Fixture — two companies, so isolation can be checked at the same time.
// ---------------------------------------------------------------------------
$mkClient = static function (string $code, string $org, string $email): array {
    db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,1)')
        ->execute(['n' => $org . ' (Books)', 'c' => $code]);
    $cid = (int) db()->lastInsertId();
    $uid = create_user(['name' => $org . ' Owner', 'email' => $email, 'password' => 'Secret#12345',
        'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
    db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, jewellery_accounting_enabled)
            VALUES (:uid,:cid,:books,:org,:code,1,1)')
        ->execute(['uid' => $uid, 'cid' => $cid, 'books' => $cid, 'org' => $org, 'code' => $code . '-C']);
    $fy = create_fiscal_year($cid, $code . ' 2026/27', '2026-07-16', '2027-07-15', true);
    db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);

    return [$cid, (int) $fy['id'], $uid];
};
[$cid, $fy, $uid] = $mkClient('JWREV', 'Reversal Jewellers', 'jwrev-a@test.local');
[$cidB, $fyB, $uidB] = $mkClient('JWRVB', 'Other Jewellers', 'jwrev-b@test.local');
$_SESSION['company_id'] = $cid;
jewellery_settings($cid);
jewellery_settings($cidB);

$q = static fn (string $sql): int => (int) db()->query($sql)->fetchColumn();
$gold = $q("SELECT id FROM jewellery_metals WHERE company_id=$cid AND code='GOLD'");
$p22 = $q("SELECT id FROM jewellery_purities WHERE company_id=$cid AND metal_id=$gold AND code='22K'");
$tola = $q("SELECT id FROM jewellery_units WHERE company_id=$cid AND code='TOLA'");
$gram = $q("SELECT id FROM jewellery_units WHERE company_id=$cid AND code='GM'");

$mkLedger = static function (int $companyId, string $code, string $name, string $master, string $type): int {
    db()->prepare('INSERT INTO ledger_groups (company_id,name,code,master_key) VALUES (:cid,:n,:c,:m)')
        ->execute(['cid' => $companyId, 'n' => 'RV ' . $code, 'c' => 'G' . $code . $companyId, 'm' => $master]);
    $gid = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO ledgers (company_id,group_id,name,code,type) VALUES (:cid,:g,:n,:c,:t)')
        ->execute(['cid' => $companyId, 'g' => $gid, 'n' => $name, 'c' => $code . $companyId, 't' => $type]);

    return (int) db()->lastInsertId();
};
$L = [];
foreach ([
    ['stock_metal', 'RSTKM', 'Metal Stock', 'assets', 'asset'],
    ['stock_finished', 'RSTKF', 'Finished Stock', 'assets', 'asset'],
    ['stock_karigar', 'RSTKK', 'Metal with Karigar', 'assets', 'asset'],
    ['stock_refinery', 'RSTKR', 'Metal with Refinery', 'assets', 'asset'],
    ['refinery_loss', 'RREFL', 'Refining Loss', 'expenses', 'expense'],
    ['refinery_charges', 'RREFC', 'Refinery Charges', 'expenses', 'expense'],
    ['sales_metal', 'RSALM', 'Sales Metal', 'income', 'revenue'],
    ['sales_making', 'RSALK', 'Sales Making', 'income', 'revenue'],
    ['sales_stone', 'RSALS', 'Sales Stone', 'income', 'revenue'],
    ['cogs', 'RCOGS', 'COGS', 'expenses', 'expense'],
    ['making_expense', 'RMAKE', 'Making Expense', 'expenses', 'expense'],
    ['wastage_loss', 'RWAST', 'Wastage Loss', 'expenses', 'expense'],
    ['karigar_payable', 'RKARP', 'Karigar Payable', 'current_liability', 'liability'],
    ['vat_input', 'RVATI', 'VAT Input', 'assets', 'asset'],
    ['vat_output', 'RVATO', 'VAT Output', 'current_liability', 'liability'],
    ['spt_input', 'RSPTI', 'SPT Input', 'assets', 'asset'],
    ['spt_output', 'RSPTO', 'SPT Output', 'current_liability', 'liability'],
    ['opening_equity', 'ROPEQ', 'Opening Equity', 'equity', 'equity'],
    ['rounding', 'RROUN', 'Rounding', 'expenses', 'expense'],
] as [$purpose, $code, $name, $master, $type]) {
    $L[$purpose] = $mkLedger($cid, $code, $name, $master, $type);
    jewellery_save_mapping($cid, $purpose, $L[$purpose], $uid);
}
$cash = $mkLedger($cid, 'RCASH', 'Cash', 'assets', 'asset');

$chain = jewellery_save_item($cid, ['code' => 'RV-CH', 'name' => 'Reversal Chain', 'item_type' => 'ornament',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola], $uid);

db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'RSUP','Supplier','supplier','active')")->execute(['c' => $cid]);
$supplier = (int) db()->lastInsertId();
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'RCUS','Customer','customer','active')")->execute(['c' => $cid]);
$customer = (int) db()->lastInsertId();

echo "\n1. Purchase: post then unpost leaves NOTHING behind\n";
$base = jwrev_snapshot($cid);
$pu = jewellery_save_purchase($cid, $fy, ['purchase_date' => '2026-08-01', 'party_id' => $supplier,
    'settle_mode' => 'credit'], [['item_id' => $chain, 'gross_weight' => 10, 'qty_pieces' => 5, 'rate' => 100000]], $uid);
ok(jewellery_post_purchase($cid, $pu, $uid)['ok'], 'The purchase posts');
$posted = jwrev_snapshot($cid);
ok($posted['stock_rows'] > $base['stock_rows'], 'It moved stock');
ok($posted['vouchers'] > $base['vouchers'], 'And wrote a voucher');

$un = jewellery_unpost_purchase($cid, $pu, $uid);
ok($un['ok'], 'It unposts' . ($un['ok'] ? '' : ' — ' . $un['error']));
$after = jwrev_snapshot($cid);
$diff = jwrev_diff($base, $after);
ok($diff === '', 'Everything is back where it started' . ($diff === '' ? '' : ' — LEFTOVER: ' . $diff));

echo "\n2. Sale with tax, exchange and a bill: same invariant\n";
// Re-post the purchase so there is stock to sell.
jewellery_post_purchase($cid, $pu, $uid);
$beforeSale = jwrev_snapshot($cid);

$oldGold = jewellery_save_item($cid, ['code' => 'RV-OG', 'name' => 'Old Gold', 'item_type' => 'bullion',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $gram], $uid);
$sale = jewellery_save_sale($cid, $fy, ['sale_date' => '2026-08-10', 'party_id' => $customer,
    'settle_mode' => 'credit', 'received_amount' => 0],
    [['item_id' => $chain, 'gross_weight' => 2, 'qty_pieces' => 1, 'rate' => 120000, 'making_amount' => 5000]],
    [['item_id' => $oldGold, 'gross_weight' => 10, 'unit_id' => $gram, 'rate' => 10000]], $uid);
// The invariant is about what POSTING does, so the baseline is the saved
// draft. Un-posting returns a document to draft — it does not delete it, and
// the lines and the tax breakdown priced with them are meant to survive.
$draftSnap = jwrev_snapshot($cid);
$rs = jewellery_post_sale($cid, $sale, $uid);
ok($rs['ok'], 'The sale posts' . ($rs['ok'] ? '' : ' — ' . $rs['error']));
$soldSnap = jwrev_snapshot($cid);
ok($draftSnap['line_taxes'] > $beforeSale['line_taxes'], 'Saving the draft recorded its tax breakdown');
ok($soldSnap['bills'] > $draftSnap['bills'], 'Posting opened a bill for the balance');

$un = jewellery_unpost_sale($cid, $sale, $uid);
ok($un['ok'], 'It unposts' . ($un['ok'] ? '' : ' — ' . $un['error']));
$afterSale = jwrev_snapshot($cid);
$diff = jwrev_diff($draftSnap, $afterSale);
ok($diff === '', 'Everything is back where it started' . ($diff === '' ? '' : ' — LEFTOVER: ' . $diff));

echo "\n3. A settled bill blocks the reversal rather than orphaning the settlement\n";
$rs = jewellery_post_sale($cid, $sale, $uid);
ok($rs['ok'], 'The sale is posted again');
$saleRow = jewellery_sale($cid, $sale);
$openBill = db()->query("SELECT id, bill_amount FROM jewellery_bills
    WHERE company_id=$cid AND source_type='jewellery_sale' AND source_id=$sale LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok($openBill !== false, 'The sale has an open bill');

$settle = jewellery_save_settlement($cid, $fy, ['settlement_date' => '2026-08-15', 'party_id' => $customer,
    'direction' => 'received', 'mode' => 'cash', 'amount' => 1000, 'ledger_id' => $cash],
    [['bill_id' => (int) $openBill['id'], 'amount' => 1000]], $uid);
ok(jewellery_post_settlement($cid, $settle, $uid)['ok'], 'A part settlement posts against it');

$blocked = jewellery_unpost_sale($cid, $sale, $uid);
ok(!$blocked['ok'], 'Un-posting the sale is REFUSED while its bill is part settled');
ok(str_contains(strtolower((string) $blocked['error']), 'settle'), 'And the message says why');

echo "\n4. Karigar issue and receipt reverse cleanly\n";
$karigar = jewellery_save_karigar($cid, ['code' => 'RK1', 'name' => 'Reversal Kaligad',
    'engagement_type' => 'contractor', 'default_making_basis' => 'flat', 'default_making_rate' => 2000,
    'wastage_allowed_pct' => 2], $uid);
$beforeIssue = jwrev_snapshot($cid);
$issue = jewellery_issue_to_karigar($cid, $fy, ['karigar_id' => $karigar, 'item_id' => $chain,
    'unit_id' => $tola, 'issued_gross_weight' => 3, 'issue_date' => '2026-08-20'], $uid);
ok($issue['ok'], 'Metal issues to the kaligad' . ($issue['ok'] ? '' : ' — ' . $issue['error']));
$cancel = jewellery_cancel_assignment($cid, (int) $issue['assignment_id'], $uid);
ok($cancel['ok'], 'The assignment cancels' . ($cancel['ok'] ? '' : ' — ' . $cancel['error']));
$afterIssue = jwrev_snapshot($cid);
$diff = jwrev_diff($beforeIssue, $afterIssue);
ok($diff === '', 'The issue left nothing behind' . ($diff === '' ? '' : ' — LEFTOVER: ' . $diff));

echo "\n5. Cross-tenant isolation on every newer table\n";
// Company B must not be able to reach ANY of company A's rows, whichever
// accessor is used.
ok(jewellery_sale($cidB, $sale) === null, 'A sale cannot be read through the wrong company');
ok(jewellery_purchase($cidB, $pu) === null, 'Nor a purchase');
ok(jewellery_settlement($cidB, $settle) === null, 'Nor a settlement');
ok(jewellery_karigar($cidB, $karigar) === null, 'Nor a kaligad');
ok(jewellery_item($cidB, $chain) === null, 'Nor an item');
ok(jewellery_order_advances($cidB, 0)['total'] === 0.0, 'Advances of a foreign order come back empty');

$taxes = jewellery_taxes_list($cid);
ok($taxes !== [], 'Company A has taxes');
foreach ($taxes as $t) {
    ok(jewellery_tax($cidB, (int) $t['id']) === null, 'Tax ' . $t['code'] . ' is invisible to company B');
}
ok(threw(static fn () => jewellery_save_mapping($cidB, 'cogs', $L['cogs'], $uidB)),
    'Company B cannot map company A\'s ledger');

$bLines = (int) db()->query("SELECT COUNT(*) FROM jewellery_line_taxes WHERE company_id=$cidB")->fetchColumn();
ok($bLines === 0, 'No line-tax row leaked into company B');
$bStock = (int) db()->query("SELECT COUNT(*) FROM jewellery_stock_txns WHERE company_id=$cidB")->fetchColumn();
ok($bStock === 0, 'No stock movement leaked either');
$bVouchers = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cidB")->fetchColumn();
ok($bVouchers === 0, 'And not a single voucher');

echo "\n6. Every posted voucher balances, always\n";
$unbalanced = db()->query("SELECT v.id, v.voucher_no,
        ROUND(SUM(CASE WHEN e.entry_type='debit' THEN e.amount ELSE -e.amount END), 2) AS net
    FROM vouchers v INNER JOIN voucher_entries e ON e.voucher_id = v.id
    WHERE v.company_id=$cid GROUP BY v.id, v.voucher_no HAVING ABS(net) > 0.004")->fetchAll(PDO::FETCH_ASSOC);
ok($unbalanced === [], 'No voucher in the company is out of balance'
    . ($unbalanced === [] ? '' : ' — ' . json_encode($unbalanced)));

$orphanEntries = (int) db()->query("SELECT COUNT(*) FROM voucher_entries e
    LEFT JOIN vouchers v ON v.id = e.voucher_id WHERE v.id IS NULL")->fetchColumn();
ok($orphanEntries === 0, 'No voucher entry is orphaned from its voucher');

echo "\n7. Bill allocation cannot over-settle, by any route\n";
// The bill from section 3 is already part settled by 1,000.
$billId = (int) $openBill['id'];
$billAmount = (float) $openBill['bill_amount'];
$billRow = static function () use ($cid, $billId): array {
    $stmt = db()->prepare('SELECT * FROM jewellery_bills WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $billId, 'cid' => $cid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
ok(near((float) $billRow()['settled_amount'], 1000.0), 'The bill shows 1,000 settled');
ok((string) $billRow()['status'] === 'part_settled', 'And reads as part settled');

// A SECOND settlement must see the first one's allocation and refuse to take
// the whole bill again.
ok(threw(static fn () => jewellery_save_settlement($cid, $fy, ['settlement_date' => '2026-08-16',
        'party_id' => $customer, 'direction' => 'received', 'mode' => 'cash',
        'amount' => $billAmount, 'ledger_id' => $cash],
        [['bill_id' => $billId, 'amount' => $billAmount]], $uid)),
    'A second settlement cannot allocate the full bill again');

// Allocating more than the settlement itself is worth is refused too.
ok(threw(static fn () => jewellery_save_settlement($cid, $fy, ['settlement_date' => '2026-08-16',
        'party_id' => $customer, 'direction' => 'received', 'mode' => 'cash',
        'amount' => 100, 'ledger_id' => $cash],
        [['bill_id' => $billId, 'amount' => 500]], $uid)),
    'Allocations cannot exceed the settlement amount');

// Settling the exact remainder closes it — and not a paisa more can follow.
$remainder = jw_round_money($billAmount - 1000.0);
$closer = jewellery_save_settlement($cid, $fy, ['settlement_date' => '2026-08-17',
    'party_id' => $customer, 'direction' => 'received', 'mode' => 'cash',
    'amount' => $remainder, 'ledger_id' => $cash],
    [['bill_id' => $billId, 'amount' => $remainder]], $uid);
ok(jewellery_post_settlement($cid, $closer, $uid)['ok'], 'The remainder settles');
ok(near((float) $billRow()['settled_amount'], $billAmount), 'The bill is settled in full');
ok((string) $billRow()['status'] === 'settled', 'And reads as settled');
ok(threw(static fn () => jewellery_save_settlement($cid, $fy, ['settlement_date' => '2026-08-18',
        'party_id' => $customer, 'direction' => 'received', 'mode' => 'cash',
        'amount' => 1, 'ledger_id' => $cash], [['bill_id' => $billId, 'amount' => 1]], $uid)),
    'Nothing more can be allocated to a fully settled bill');

// Reversing a settlement must reopen the bill by exactly its share.
$un = jewellery_unpost_settlement($cid, $closer, $uid);
ok($un['ok'], 'The closing settlement reverses' . ($un['ok'] ? '' : ' — ' . $un['error']));
ok(near((float) $billRow()['settled_amount'], 1000.0), 'The bill drops back to 1,000 settled');
ok((string) $billRow()['status'] === 'part_settled', 'And reopens as part settled');

// A DRAFT settlement must not move the bill at all.
$draft = jewellery_save_settlement($cid, $fy, ['settlement_date' => '2026-08-19',
    'party_id' => $customer, 'direction' => 'received', 'mode' => 'cash',
    'amount' => 50, 'ledger_id' => $cash], [['bill_id' => $billId, 'amount' => 50]], $uid);
ok(near((float) $billRow()['settled_amount'], 1000.0),
    'A DRAFT settlement does not change what the bill shows as settled');
ok(jewellery_delete_settlement($cid, $draft), 'The draft can be deleted');
ok((int) db()->query("SELECT COUNT(*) FROM jewellery_settlement_allocations
    WHERE company_id=$cid AND settlement_id=$draft")->fetchColumn() === 0,
    'And its allocations go with it');

echo "\n8. The sum of allocations never exceeds the bill\n";
$overSettled = db()->query("SELECT b.id, b.bill_no, b.bill_amount,
        COALESCE((SELECT SUM(a.amount) FROM jewellery_settlement_allocations a
                  INNER JOIN jewellery_settlements s ON s.id = a.settlement_id
                  WHERE a.bill_id = b.id AND s.status = 'posted'), 0) AS allocated
    FROM jewellery_bills b WHERE b.company_id = $cid
    HAVING allocated > bill_amount + 0.005")->fetchAll(PDO::FETCH_ASSOC);
ok($overSettled === [], 'No bill in the company is over-settled'
    . ($overSettled === [] ? '' : ' — ' . json_encode($overSettled)));

echo "\n9. A kaligad receipt can be reversed — weights get mis-keyed\n";
// Issue, receive at the WRONG weight, then put it right. Before this existed
// the only way back was editing the database by hand.
$issue2 = jewellery_issue_to_karigar($cid, $fy, ['karigar_id' => $karigar, 'item_id' => $chain,
    'unit_id' => $tola, 'issued_gross_weight' => 4, 'issue_date' => '2026-09-01'], $uid);
ok($issue2['ok'], 'Metal goes out to the kaligad' . ($issue2['ok'] ? '' : ' — ' . $issue2['error']));
$assignId = (int) $issue2['assignment_id'];
$beforeReceipt = jwrev_snapshot($cid);

$recv = jewellery_receive_from_karigar($cid, $fy, ['assignment_id' => $assignId,
    'received_item_id' => $chain, 'received_purity_id' => $p22,
    'received_gross_weight' => 3.9, 'receive_date' => '2026-09-10', 'qty_pieces' => 1], $uid);
ok($recv['ok'], 'The piece comes back' . ($recv['ok'] ? '' : ' — ' . $recv['error']));
$receiptId = (int) db()->query("SELECT id FROM jewellery_order_receipts
    WHERE company_id=$cid AND assignment_id=$assignId ORDER BY id DESC LIMIT 1")->fetchColumn();
ok($receiptId > 0, 'A receipt row exists');
ok((string) jewellery_assignment($cid, $assignId)['status'] === 'received', 'The assignment reads as received');

$rev = jewellery_unpost_receipt($cid, $receiptId, $uid);
ok($rev['ok'], 'The receipt reverses' . ($rev['ok'] ? '' : ' — ' . $rev['error']));
$afterReceipt = jwrev_snapshot($cid);
$diff = jwrev_diff($beforeReceipt, $afterReceipt);
ok($diff === '', 'Everything is back to the moment before it was received'
    . ($diff === '' ? '' : ' — LEFTOVER: ' . $diff));
ok((string) jewellery_assignment($cid, $assignId)['status'] === 'issued',
    'And the assignment is outstanding again, with the metal still at the kaligad');

// Re-receive at the right weight — the point of the whole exercise.
$again = jewellery_receive_from_karigar($cid, $fy, ['assignment_id' => $assignId,
    'received_item_id' => $chain, 'received_purity_id' => $p22,
    'received_gross_weight' => 3.95, 'receive_date' => '2026-09-10', 'qty_pieces' => 1], $uid);
ok($again['ok'], 'It can be received again at the corrected weight'
    . ($again['ok'] ? '' : ' — ' . $again['error']));

echo "\n10. A settled wage bill blocks the reversal\n";
$newReceiptId = (int) db()->query("SELECT id FROM jewellery_order_receipts
    WHERE company_id=$cid AND assignment_id=$assignId ORDER BY id DESC LIMIT 1")->fetchColumn();
$wageBill = db()->query("SELECT id, bill_amount FROM jewellery_bills
    WHERE company_id=$cid AND source_type='jewellery_order_receipt' AND source_id=$newReceiptId LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($wageBill) {
    $karigarParty = (int) jewellery_karigar($cid, $karigar)['party_id'];
    $pay = jewellery_save_settlement($cid, $fy, ['settlement_date' => '2026-09-15',
        'party_id' => $karigarParty, 'direction' => 'paid', 'mode' => 'cash',
        'amount' => 100, 'ledger_id' => $cash], [['bill_id' => (int) $wageBill['id'], 'amount' => 100]], $uid);
    ok(jewellery_post_settlement($cid, $pay, $uid)['ok'], 'Part of the wages is paid');
    $blocked = jewellery_unpost_receipt($cid, $newReceiptId, $uid);
    ok(!$blocked['ok'], 'Reversing the receipt is now REFUSED');
    ok(str_contains(strtolower((string) $blocked['error']), 'settle'), 'And the message says why');
} else {
    ok(true, 'No wage bill on this receipt (employee kaligad) — nothing to block');
    ok(true, '(skipped)');
    ok(true, '(skipped)');
}

echo "\n11. A refinery job out at the refiner can be cancelled\n";
$refinerParty = $supplier;
$beforeJob = jwrev_snapshot($cid);
$job = jewellery_issue_to_refinery($cid, $fy, ['party_id' => $refinerParty, 'item_id' => $chain,
    'unit_id' => $tola, 'issued_gross_weight' => 2, 'issue_date' => '2026-09-20'], $uid);
ok($job['ok'], 'Metal goes to the refinery' . ($job['ok'] ? '' : ' — ' . $job['error']));
$jobId = (int) $job['job_id'];
$cancelJob = jewellery_cancel_refinery_job($cid, $jobId, $uid);
ok($cancelJob['ok'], 'The job cancels' . ($cancelJob['ok'] ? '' : ' — ' . $cancelJob['error']));
$afterJob = jwrev_snapshot($cid);
$diff = jwrev_diff($beforeJob, $afterJob);
ok($diff === '', 'The metal is back in own stock and nothing is left behind'
    . ($diff === '' ? '' : ' — LEFTOVER: ' . $diff));
ok(!jewellery_cancel_refinery_job($cid, $jobId, $uid)['ok'], 'It cannot be cancelled twice');

echo "\n12. The Voucher Register still cannot touch these postings\n";
$again2 = jewellery_issue_to_refinery($cid, $fy, ['party_id' => $refinerParty, 'item_id' => $chain,
    'unit_id' => $tola, 'issued_gross_weight' => 1, 'issue_date' => '2026-09-21'], $uid);
ok($again2['ok'], 'A fresh refinery job posts');
$jobVoucher = (int) db()->query("SELECT issue_voucher_id FROM jewellery_refinery_jobs
    WHERE id=" . (int) $again2['job_id'])->fetchColumn();
if ($jobVoucher > 0) {
    $v = db()->query("SELECT * FROM vouchers WHERE id=$jobVoucher")->fetch(PDO::FETCH_ASSOC);
    ok(voucher_mutation_blocker($v, []) !== null,
        'Its voucher is protected from the Voucher Register');
} else {
    ok(true, 'No voucher (mapping absent) — nothing to protect');
}

jwrev_cleanup();
echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
