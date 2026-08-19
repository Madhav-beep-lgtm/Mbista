<?php
declare(strict_types=1);

/**
 * Perpetual succession: what one fiscal year closed with is what the next one
 * opened with.
 *
 * Accounting does not stop at a year end, it is only reported in years — so the
 * closing position has to become the opening position without being re-typed,
 * without the earlier year losing its own record, and without the same gold
 * being counted twice because both the ledger carry and a jewellery carry
 * posted it.
 *
 * The two cases that make this real in a workshop are here in full: metal
 * issued to a kaligad before the year end and received after it, and a shelf
 * position that a physical count disagrees with.
 *   php database/test_jewellery_year_rollover.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/jewellery_stock.php';
require_once __DIR__ . '/../app/jewellery_workshop.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $tol = 0.011): bool { return abs($a - $b) < $tol; }

function jwr_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'JWROLL'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$s)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['jewellery_opening_balances', 'jewellery_stock_unit_events', 'jewellery_stock_units',
                  'jewellery_order_receipts', 'jewellery_order_assignments', 'jewellery_order_lines',
                  'jewellery_orders', 'jewellery_karigars', 'jewellery_bills', 'jewellery_stock_txns',
                  'jewellery_item_profiles', 'inventory_items', 'inventory_ledger_mappings',
                  'jewellery_item_categories', 'jewellery_settings',
                  'jewellery_purities', 'jewellery_metals', 'jewellery_units'] as $t) {
            if (table_exists($t)) { db()->exec("DELETE FROM `$t` WHERE company_id=$s"); }
        }
        db()->exec("DELETE FROM accounting_parties WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email = 'jwroll@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
jwr_cleanup();

// ---------------------------------------------------------------------------
// A shop with TWO fiscal years
// ---------------------------------------------------------------------------
db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,1)')
    ->execute(['n' => 'Rollover Jewellers (Books)', 'c' => 'JWROLL']);
$cid = (int) db()->lastInsertId();
$uid = create_user(['name' => 'Rollover Owner', 'email' => 'jwroll@test.local', 'password' => 'Secret#12345',
    'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, jewellery_accounting_enabled)
        VALUES (:uid,:cid,:books,:org,:code,1,1)')
    ->execute(['uid' => $uid, 'cid' => $cid, 'books' => $cid, 'org' => 'Rollover Jewellers', 'code' => 'JWROLL-C']);

$fy1 = create_fiscal_year($cid, 'JWROLL 2026/27', '2026-07-16', '2027-07-15', true);
$fy1Id = (int) $fy1['id'];
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy1Id]);
$fy2 = create_fiscal_year($cid, 'JWROLL 2027/28', '2027-07-16', '2028-07-15', false);
$fy2Id = (int) $fy2['id'];
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy2Id]);
set_context($cid, $fy1Id);

jewellery_settings($cid);
$masterId = static function (string $table, string $where) use ($cid): int {
    return (int) db()->query("SELECT id FROM $table WHERE company_id=$cid AND $where LIMIT 1")->fetchColumn();
};
$gold = $masterId('jewellery_metals', "code='GOLD'");
$p22 = $masterId('jewellery_purities', "metal_id=$gold AND code='22K'");
$tola = $masterId('jewellery_units', "code='TOLA'");

$mkLedger = static function (string $code, string $name, string $master) use ($cid): int {
    db()->prepare('INSERT INTO ledger_groups (company_id,name,code,master_key) VALUES (:cid,:n,:c,:m)')
        ->execute(['cid' => $cid, 'n' => 'JW ' . $code, 'c' => 'G' . $code, 'm' => $master]);
    $gid = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO ledgers (company_id,group_id,name,code) VALUES (:cid,:g,:n,:c)')
        ->execute(['cid' => $cid, 'g' => $gid, 'n' => $name, 'c' => $code]);

    return (int) db()->lastInsertId();
};
foreach ([
    ['stock_metal', 'STKM', 'Metal Stock', 'assets'], ['stock_finished', 'STKF', 'Finished Stock', 'assets'],
    ['stock_karigar', 'STKK', 'With Karigar', 'assets'], ['opening_equity', 'OPEQ', 'Opening Equity', 'equity'],
    ['karigar_payable', 'KARP', 'Karigar Payable', 'liabilities'],
    ['making_expense', 'MAKE', 'Making Charges', 'expenses'],
    ['wastage_loss', 'WAST', 'Wastage Loss', 'expenses'],
] as [$purpose, $code, $name, $master]) {
    jewellery_save_mapping($cid, $purpose, $mkLedger($code, $name, $master), $uid);
}

$shelfItem = jewellery_save_item($cid, ['code' => 'BG-SHELF', 'name' => 'Shelf Bangle', 'category' => 'Bangles',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola], $uid);
$jobItem = jewellery_save_item($cid, ['code' => 'BG-JOB', 'name' => 'Job Bangle', 'category' => 'Bangles',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola], $uid);

// ---------------------------------------------------------------------------
echo "1. Year one is where an opening is TYPED\n";
// ---------------------------------------------------------------------------
ok(!jw_ob_is_carried_year($cid, $fy1Id), 'The first year has nothing before it to carry');
$typed = jewellery_save_opening($cid, $fy1Id, ['item_id' => $shelfItem, 'gross_weight' => 10.0,
    'qty_pieces' => 2, 'amount' => 1000000], $uid);
ok($typed['ok'], 'An opening can be typed in the first year' . ($typed['ok'] ? '' : ' — ' . $typed['error']));
$typedJob = jewellery_save_opening($cid, $fy1Id, ['item_id' => $jobItem, 'gross_weight' => 6.0,
    'qty_pieces' => 1, 'amount' => 600000], $uid);
ok($typedJob['ok'], 'And for a second item too');
ok(near(jw_item_balance($cid, $shelfItem, '2026-07-16')['gross_weight'], 10.0), 'The shelf item opens at 10 tola');

// ---------------------------------------------------------------------------
echo "\n2. Metal issued before the year end and still out when it turns\n";
// ---------------------------------------------------------------------------
$karigarId = jewellery_save_karigar($cid, ['code' => 'KAR1', 'name' => 'Ram Kaligad',
    'engagement_type' => 'inhouse', 'default_making_basis' => 'per_gram', 'default_making_rate' => 500], $uid);
$issue = jewellery_issue_to_karigar($cid, $fy1Id, [
    'karigar_id' => $karigarId, 'item_id' => $jobItem, 'issue_date' => '2027-07-10',
    // issued_gross_weight, not gross_weight: the weight handed over is the
    // issuer's to state and is its own field. The VALUE is not an input at all
    // — it is the item's own average fine rate applied to what went out.
    'purity_id' => $p22, 'unit_id' => $tola, 'issued_gross_weight' => 6.0,
], $uid);
$assignmentId = (int) ($issue['assignment_id'] ?? 0);
ok($assignmentId > 0, 'Metal goes out to the kaligad six days before the year ends'
    . (!empty($issue['error']) ? ' — ' . $issue['error'] : ''));
$fy1End = '2027-07-15';
ok(near(jw_item_balance($cid, $jobItem, $fy1End, 'stock')['gross_weight'], 0.0),
    'At the year end the shelf holds none of it');
ok(near(jw_item_balance($cid, $jobItem, $fy1End, 'karigar')['gross_weight'], 6.0),
    'And the kaligad is holding all six tola of it');

// ---------------------------------------------------------------------------
echo "\n3. The carry is computed, and it posts NOTHING\n";
// ---------------------------------------------------------------------------
$trialOf = static function () use ($cid): array {
    return db()->query("SELECT ledger_id,
            ROUND(SUM(CASE WHEN entry_type='debit' THEN amount ELSE -amount END), 2) AS bal
        FROM voucher_entries e INNER JOIN vouchers v ON v.id = e.voucher_id
        WHERE v.company_id=$cid GROUP BY ledger_id ORDER BY ledger_id")->fetchAll(PDO::FETCH_ASSOC);
};
$vouchersBefore = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid")->fetchColumn();
$trialBefore = $trialOf();

$gen = jw_ob_generate($cid, $fy2Id, $uid);
ok($gen['ok'] && $gen['carried'] === true, 'Year two carries rather than seeds' . ($gen['ok'] ? '' : ' — ' . $gen['error']));

ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid")->fetchColumn() === $vouchersBefore,
    'Carrying forward posted NO voucher — the ledgers already carried');
ok($trialBefore === $trialOf(), 'And not one ledger balance moved, so no gold is counted twice');

// ---------------------------------------------------------------------------
echo "\n4. What carried, and who was holding it\n";
// ---------------------------------------------------------------------------
$rows = jw_ob_rows($cid, $fy2Id);
$byKey = [];
foreach ($rows as $r) { $byKey[$r['item_code'] . '|' . $r['holder_type']] = $r; }
ok(isset($byKey['BG-SHELF|stock']), 'The shelf item carries as a showroom line');
ok(isset($byKey['BG-JOB|karigar']), 'The job item carries as a WITH KALIGAD line, not as showroom stock');
ok(!isset($byKey['BG-JOB|stock']), 'And it is not double counted on the shelf as well');
ok(near((float) ($byKey['BG-SHELF|stock']['gross_weight'] ?? 0), 10.0), 'The showroom line carries 10 tola');
ok(near((float) ($byKey['BG-JOB|karigar']['gross_weight'] ?? 0), 6.0), 'The kaligad line carries 6 tola');
ok(str_contains((string) ($byKey['BG-JOB|karigar']['holder_label'] ?? ''), 'Ram Kaligad'),
    'The kaligad is named on the line, not left as a number');
$totals = jw_ob_totals($rows);
ok(isset($totals['stock'], $totals['karigar']),
    'The brought-forward totals separate the shelf from the workshop');

// ---------------------------------------------------------------------------
echo "\n5. Year one keeps its own opening, whatever year two does\n";
// ---------------------------------------------------------------------------
ok(jw_ob_is_carried_year($cid, $fy2Id), 'Year two is a carried year');
$refused = jewellery_save_opening($cid, $fy2Id, ['item_id' => $shelfItem, 'gross_weight' => 99.0, 'amount' => 1], $uid);
ok(!$refused['ok'], 'Typing an opening in year two is refused');
ok(str_contains(strtolower((string) $refused['error']), 'carried'),
    'And the refusal says where the figure comes from instead');
$year1Shelf = 0.0;
foreach (jewellery_opening_rows($cid, $fy1Id) as $r) {
    if ((string) $r['item_code'] === 'BG-SHELF') { $year1Shelf = (float) $r['gross_weight']; }
}
ok(near($year1Shelf, 10.0), 'Year one still opens with the 10 tola it was given, untouched');
ok(near(jw_item_balance($cid, $shelfItem, '2026-07-16')['gross_weight'], 10.0),
    'And the metal register agrees with it');

// ---------------------------------------------------------------------------
echo "\n6. Receiving in year two settles in year two\n";
// ---------------------------------------------------------------------------
set_context($cid, $fy2Id);
$receipt = jewellery_receive_from_karigar($cid, $fy2Id, [
    'assignment_id' => $assignmentId, 'receive_date' => '2027-07-20',
    'received_gross_weight' => 6.0, 'wastage_allowed_pct' => 0,
], $uid);
ok(!empty($receipt['ok']) || !empty($receipt['receipt_id']),
    'The kaligad returns the piece five days into the new year'
    . (!empty($receipt['error']) ? ' — ' . $receipt['error'] : ''));
ok(near(jw_item_balance($cid, $jobItem, '2027-07-20', 'karigar')['gross_weight'], 0.0),
    'His holding is cleared');
ok(near(jw_item_balance($cid, $jobItem, $fy1End, 'karigar')['gross_weight'], 6.0),
    'Year one is unchanged by it: on its last day the metal was still out');

// ---------------------------------------------------------------------------
echo "\n7. A physical count that differs posts the DIFFERENCE, once\n";
// ---------------------------------------------------------------------------
$shelfRow = null;
foreach (jw_ob_rows($cid, $fy2Id) as $r) {
    if ((string) $r['item_code'] === 'BG-SHELF' && (string) $r['holder_type'] === 'stock') { $shelfRow = $r; }
}
ok($shelfRow !== null, 'The showroom line can be found to correct');
$beforeAdj = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid")->fetchColumn();
$adj = jw_ob_adjust($cid, $fy2Id, (int) $shelfRow['id'], ['gross_weight' => 9.5, 'qty_pieces' => 2, 'amount' => 950000],
    'Physical count short half a tola', $uid);
ok($adj['ok'], 'The line is corrected against a physical count' . ($adj['ok'] ? '' : ' — ' . $adj['error']));
$adjVouchers = db()->query("SELECT total_amount FROM vouchers WHERE company_id=$cid AND source_type='jewellery_opening_adj'")->fetchAll(PDO::FETCH_COLUMN);
ok(count($adjVouchers) === 1, 'Exactly one adjustment voucher exists');
ok(near((float) ($adjVouchers[0] ?? 0), 50000.0), 'And it carries the DIFFERENCE (50,000), not the whole 950,000');
ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid")->fetchColumn() === $beforeAdj + 1,
    'One voucher was added, not two');

$adjAgain = jw_ob_adjust($cid, $fy2Id, (int) $shelfRow['id'], ['gross_weight' => 9.0, 'qty_pieces' => 2, 'amount' => 900000],
    'Recounted, shorter still', $uid);
ok($adjAgain['ok'], 'The same line can be corrected again');
$adjVouchers2 = db()->query("SELECT total_amount FROM vouchers WHERE company_id=$cid AND source_type='jewellery_opening_adj'")->fetchAll(PDO::FETCH_COLUMN);
ok(count($adjVouchers2) === 1, 'Correcting twice REPLACES the adjustment rather than stacking a second');
ok(near((float) ($adjVouchers2[0] ?? 0), 100000.0), 'And the replacement is measured from the carried figure, not the last correction');

$refusedNoReason = jw_ob_adjust($cid, $fy2Id, (int) $shelfRow['id'], ['gross_weight' => 8.0, 'amount' => 800000], '   ', $uid);
ok(!$refusedNoReason['ok'], 'An adjustment without a reason is refused');

// ---------------------------------------------------------------------------
echo "\n8. Regenerating keeps what somebody counted\n";
// ---------------------------------------------------------------------------
$regen = jw_ob_generate($cid, $fy2Id, $uid);
ok($regen['ok'] && $regen['kept'] >= 1, 'A refresh preserves the adjusted line');
$afterRegen = null;
foreach (jw_ob_rows($cid, $fy2Id) as $r) {
    if ((string) $r['item_code'] === 'BG-SHELF' && (string) $r['holder_type'] === 'stock') { $afterRegen = $r; }
}
ok($afterRegen !== null && (string) $afterRegen['source'] === 'adjusted', 'It is still marked as adjusted');
ok($afterRegen !== null && near((float) $afterRegen['gross_weight'], 9.0), 'And still holds the counted figure, not the replayed one');
ok($afterRegen !== null && (string) $afterRegen['adjust_reason'] === 'Recounted, shorter still', 'With the reason it was given');

// ---------------------------------------------------------------------------
echo "\n9. The carry costs the same whether a shop has two items or two thousand\n";
// ---------------------------------------------------------------------------
$questions = static function (): int {
    return (int) (db()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'] ?? 0);
};
$q1 = $questions();
jw_ob_lines_carried($cid, $fy1End);
$smallCost = $questions() - $q1 - 2;

$bulkItem = db()->prepare("INSERT INTO inventory_items (company_id, sku, name, category, unit, status) VALUES (:cid,:sku,:n,'Bangles','TOLA','active')");
$bulkProfile = db()->prepare("INSERT INTO jewellery_item_profiles (company_id, inventory_item_id, jewellery_type, metal_id, purity_id, unit_id, stock_kind, gross_weight)
    VALUES (:cid,:iid,'ornament',:m,:p,:u,'showroom',0)");
$bulkTxn = db()->prepare("INSERT INTO jewellery_stock_txns (company_id, fiscal_year_id, item_id, txn_type, direction, txn_date, metal_id, purity_id, unit_id,
        qty_pieces, gross_weight, gross_grams, fine_weight, fine_grams, rate, amount)
    VALUES (:cid,:fy,:iid,'opening','in','2026-07-16',:m,:p,:u,1,1,11.664,0.916,10.684,1000,1000)");
for ($n = 1; $n <= 60; $n++) {
    $bulkItem->execute(['cid' => $cid, 'sku' => 'BULK-' . $n, 'n' => 'Bulk ' . $n]);
    $iid = (int) db()->lastInsertId();
    $bulkProfile->execute(['cid' => $cid, 'iid' => $iid, 'm' => $gold, 'p' => $p22, 'u' => $tola]);
    $bulkTxn->execute(['cid' => $cid, 'fy' => $fy1Id, 'iid' => $iid, 'm' => $gold, 'p' => $p22, 'u' => $tola]);
}
$q2 = $questions();
$bulkLines = jw_ob_lines_carried($cid, $fy1End);
$bulkCost = $questions() - $q2 - 2;
ok(count($bulkLines) >= 60, 'Sixty more items with a closing position all carry');
ok($smallCost === $bulkCost, 'And cost the SAME number of queries — ' . $smallCost . ' either way, not one per item');
ok($bulkCost <= 4, 'Which is a handful, whatever the size of the shop (' . $bulkCost . ')');

// ---------------------------------------------------------------------------
echo "\n10. Both kinds of year actually draw\n";
// ---------------------------------------------------------------------------
// The screen IS a different screen in a carried year, so rendering one and not
// the other would miss the half that changed.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin/jewellery.php';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SESSION['user_id'] = (int) db()->query("SELECT id FROM users WHERE role IN ('admin','super_admin') ORDER BY id LIMIT 1")->fetchColumn();
mark_company_pin_verified($cid);
set_selected_company($cid);

$renderOpening = static function (int $forFiscalYearId) use ($cid): array {
    set_context($cid, $forFiscalYearId);
    $_GET = ['view' => 'opening'];
    $_POST = [];
    ob_start();
    $error = null;
    try {
        include __DIR__ . '/../public_html/admin/jewellery.php';
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    $html = (string) ob_get_clean();
    $problems = $error !== null ? [$error] : [];
    foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $needle) {
        $at = stripos($html, $needle);
        if ($at !== false) { $problems[] = trim(substr($html, $at, 160)); }
    }

    return [$html, $problems];
};

[$typedHtml, $typedProblems] = $renderOpening($fy1Id);
ok($typedProblems === [], 'The first year draws cleanly' . ($typedProblems === [] ? '' : ' — ' . $typedProblems[0]));
ok(str_contains($typedHtml, 'Record Opening Stock'), 'And still offers the form an opening is typed into');
ok(!str_contains($typedHtml, 'Brought forward from'), 'With no carry card, because there is nothing behind it');

[$carriedHtml, $carriedProblems] = $renderOpening($fy2Id);
ok($carriedProblems === [], 'The carried year draws cleanly' . ($carriedProblems === [] ? '' : ' — ' . $carriedProblems[0]));
ok(str_contains($carriedHtml, 'Brought forward from'), 'It leads with what was brought forward');
ok(str_contains($carriedHtml, 'name="action" value="carry_opening"'), 'And offers to recompute it');
ok(str_contains($carriedHtml, 'Held by'), 'The statement names who was holding each line');
ok(str_contains($carriedHtml, 'name="action" value="adjust_opening"'), 'Each line can be corrected against a count');
ok(!str_contains($carriedHtml, 'id="record-opening-stock"'), 'And the type-it-in form is NOT offered');
ok(str_contains($carriedHtml, 'Ram Kaligad'), 'The kaligad holding metal over the boundary is named on the page');

jwr_cleanup();
echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail === 0 ? 0 : 1);
