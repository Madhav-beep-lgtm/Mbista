<?php
declare(strict_types=1);

/**
 * Does a closing balance become the next day's opening balance?
 *
 * The rule this proves, in one sentence: for any cut-off date, the opening
 * balance is the cumulative net of everything posted BEFORE it, and the
 * closing balance at the end of a period is therefore the opening balance of
 * the day that follows — across a fiscal-year boundary as well as inside one.
 *
 * With one deliberate exception, which is not a bug but the whole point of a
 * year end: INCOME AND EXPENSE ACCOUNTS DO NOT CARRY FORWARD. They reset to
 * nil and their prior net moves to Retained Earnings brought forward. An asset
 * carried into the new year and a sale carried into the new year would be two
 * very different mistakes; only the first is right.
 *
 * Checked here, on a real two-year fixture:
 *   1. Ledgers of every chart-of-accounts nature (asset, liability, equity,
 *      revenue, expense) — closing at year end vs opening at year start.
 *   2. Every ledger in the chart, so none is silently missing from the report.
 *   3. An arbitrary date range used as a cut-off, INSIDE a year.
 *   4. Stock item balances across the same boundary.
 *   5. Jewellery orders open at the year end.
 *   6. The books balance at every one of those cut-offs.
 *     php database/test_period_carry_forward.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/reports_engine.php';
require_once __DIR__ . '/../app/stock_report_engine.php';
require_once __DIR__ . '/../app/jewellery_trade.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $eps = 0.011): bool { return abs($a - $b) < $eps; }
function money(float $v): string { return number_format($v, 2); }

function pcf_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code='PCFTEST'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE e FROM voucher_entries e JOIN vouchers v ON v.id=e.voucher_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['inventory_stock_counts', 'inventory_cost_layers', 'inventory_transactions', 'inventory_items',
                  'inventory_ledger_mappings', 'jewellery_orders', 'jewellery_settings', 'jewellery_purities',
                  'jewellery_metals', 'jewellery_units', 'accounting_parties', 'warehouses'] as $t) {
            if (table_exists($t) && column_exists($t, 'company_id')) { db()->exec("DELETE FROM `$t` WHERE company_id=$s"); }
        }
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
}
pcf_cleanup();

// ---------------------------------------------------------------------------
// Two consecutive fiscal years, back to back with no gap: FY2 starts the very
// day after FY1 ends, which is the boundary this whole suite is about.
// ---------------------------------------------------------------------------
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Carry Forward Co','PCFTEST',1)")->execute();
$cid = (int) db()->lastInsertId();
$FY1_START = '2026-07-16'; $FY1_END = '2027-07-15';
$FY2_START = '2027-07-16'; $FY2_END = '2028-07-15';
$fy1 = create_fiscal_year($cid, 'PCF 2026/27', $FY1_START, $FY1_END, true);
$fy2 = create_fiscal_year($cid, 'PCF 2027/28', $FY2_START, $FY2_END, false);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id IN (?,?)")->execute([$fy1['id'], $fy2['id']]);
$fy1Id = (int) $fy1['id'];
$fy2Id = (int) $fy2['id'];
$_SESSION['company_id'] = $cid;
$_SESSION['fiscal_year_id'] = $fy1Id;
$uid = (int) db()->query("SELECT id FROM users WHERE role='admin' AND status='active' ORDER BY id LIMIT 1")->fetchColumn();

ok((new DateTimeImmutable($FY1_END))->modify('+1 day')->format('Y-m-d') === $FY2_START,
    'The two fiscal years meet with no gap: ' . $FY1_END . ' then ' . $FY2_START);

// One ledger of every nature the chart of accounts has.
$mkLedger = static function (string $code, string $name, string $type, string $master) use ($cid): int {
    db()->prepare('INSERT INTO ledger_groups (company_id,name,code,master_key) VALUES (:cid,:n,:c,:m)')
        ->execute(['cid' => $cid, 'n' => 'G ' . $code, 'c' => 'G' . $code, 'm' => $master]);
    $gid = (int) db()->lastInsertId();
    db()->prepare("INSERT INTO ledgers (company_id,group_id,name,code,type,status) VALUES (:cid,:g,:n,:c,:t,'active')")
        ->execute(['cid' => $cid, 'g' => $gid, 'n' => $name, 'c' => $code, 't' => $type]);
    return (int) db()->lastInsertId();
};
$LED = [
    'asset' => $mkLedger('PCF-1000', 'Bank', 'asset', 'assets'),
    'stock' => $mkLedger('PCF-1400', 'Inventory Asset', 'asset', 'assets'),
    'liability' => $mkLedger('PCF-2000', 'Trade Payable', 'liability', 'liabilities'),
    'equity' => $mkLedger('PCF-3000', 'Capital', 'equity', 'equity'),
    'revenue' => $mkLedger('PCF-4000', 'Sales', 'revenue', 'income'),
    'expense' => $mkLedger('PCF-5000', 'Rent', 'expense', 'expenses'),
    'clearing' => $mkLedger('PCF-2100', 'Purchase Clearing', 'liability', 'liabilities'),
    'cogs' => $mkLedger('PCF-5100', 'Cost of Goods Sold', 'expense', 'expenses'),
    'equity_open' => $mkLedger('PCF-3100', 'Opening Equity', 'equity', 'equity'),
];
foreach ([['inventory_asset', $LED['stock']], ['purchase_clearing', $LED['clearing']],
          ['cogs', $LED['cogs']], ['opening_equity', $LED['equity_open']]] as [$purpose, $ledgerId]) {
    db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, purpose, ledger_id) VALUES (:cid,'global',:p,:l)")
        ->execute(['cid' => $cid, 'p' => $purpose, 'l' => $ledgerId]);
}

$seq = 0;
$post = static function (string $date, int $fyId, array $legs, string $note) use ($cid, $uid, &$seq): int {
    $seq++;
    $lines = [];
    $total = 0.0;
    foreach ($legs as [$ledgerId, $amount]) {
        $lines[] = ['ledger_id' => $ledgerId, 'entry_type' => $amount > 0 ? 'debit' : 'credit', 'amount' => abs($amount)];
        if ($amount > 0) { $total += $amount; }
    }
    return (int) create_voucher_with_entries([
        'company_id' => $cid, 'fiscal_year_id' => $fyId,
        'voucher_no' => 'PCF-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
        'voucher_type' => 'journal', 'voucher_date' => $date,
        'total_amount' => round($total, 2), 'narration' => $note,
        'status' => 'posted', 'posted_by' => $uid,
    ], $lines);
};

// ---------------------------------------------------------------------------
// FY1 activity. Deliberately spread so a mid-year cut-off has something on
// both sides of it.
// ---------------------------------------------------------------------------
$post('2026-08-01', $fy1Id, [[$LED['asset'], 500000], [$LED['equity'], -500000]], 'Capital brought in');
$post('2026-09-10', $fy1Id, [[$LED['expense'], 60000], [$LED['asset'], -60000]], 'Rent, first half');
$post('2026-09-10', $fy1Id, [[$LED['asset'], 200000], [$LED['revenue'], -200000]], 'Sales, first half');
$MID = '2026-12-31';                       // the mid-year cut-off this suite uses
$post('2027-02-20', $fy1Id, [[$LED['expense'], 40000], [$LED['asset'], -40000]], 'Rent, second half');
$post('2027-02-20', $fy1Id, [[$LED['asset'], 300000], [$LED['revenue'], -300000]], 'Sales, second half');
$post('2027-03-15', $fy1Id, [[$LED['asset'], 150000], [$LED['liability'], -150000]], 'Bought on credit');

// FY2 activity, so "opening" is a real opening and not just an empty year.
$post('2027-08-05', $fy2Id, [[$LED['expense'], 25000], [$LED['asset'], -25000]], 'Rent, new year');
$post('2027-08-05', $fy2Id, [[$LED['asset'], 90000], [$LED['revenue'], -90000]], 'Sales, new year');

/** The report engine's own numbers, for one window, with the FY reset applied. */
$balancesFor = static function (string $from, string $to, ?string $fyStart) use ($cid): array {
    $out = [];
    foreach (rc_ledger_balances($cid, $from, $to, '', 0, 0, [], $fyStart) as $row) {
        $out[(int) $row['id']] = $row;
    }
    return $out;
};
/** Closing = opening + the window's own movement, signed by nature. */
$closingOf = static function (array $row): float {
    $nature = rc_ledger_nature($row);
    $dr = (float) $row['op_dr'] + (float) $row['tx_dr'];
    $cr = (float) $row['op_cr'] + (float) $row['tx_cr'];
    return in_array($nature, ['asset', 'expense'], true) ? round($dr - $cr, 2) : round($cr - $dr, 2);
};
$openingOf = static function (array $row): float {
    $nature = rc_ledger_nature($row);
    $dr = (float) $row['op_dr'];
    $cr = (float) $row['op_cr'];
    return in_array($nature, ['asset', 'expense'], true) ? round($dr - $cr, 2) : round($cr - $dr, 2);
};

// ===========================================================================
echo "\n1. LEDGERS — the year end and the day after it\n";
// ===========================================================================
$fy1Full = $balancesFor($FY1_START, $FY1_END, $FY1_START);
$fy2Full = $balancesFor($FY2_START, $FY2_END, $FY2_START);

echo "\n   Permanent accounts (asset / liability / equity) MUST carry forward\n";
foreach (['asset' => 'Bank', 'liability' => 'Trade Payable', 'equity' => 'Capital'] as $key => $label) {
    $ledgerId = $LED[$key];
    $closing = $closingOf($fy1Full[$ledgerId]);
    $opening = $openingOf($fy2Full[$ledgerId]);
    ok(near($closing, $opening),
        sprintf('%-14s closing %s at %s = opening %s at %s', $label, money($closing), $FY1_END, money($opening), $FY2_START));
    ok(abs($closing) > 0.01, sprintf('%-14s actually carries a balance, so the check above means something', $label));
}

echo "\n   Temporary accounts (income / expense) MUST NOT carry forward\n";
foreach (['revenue' => 'Sales', 'expense' => 'Rent'] as $key => $label) {
    $ledgerId = $LED[$key];
    $closing = $closingOf($fy1Full[$ledgerId]);
    $opening = $openingOf($fy2Full[$ledgerId]);
    ok(abs($closing) > 0.01, sprintf('%-14s earned/spent %s in the year just ended', $label, money($closing)));
    ok(near($opening, 0.0), sprintf('%-14s opens the new year at nil, not at %s', $label, money($closing)));
}
$fy1Profit = $closingOf($fy1Full[$LED['revenue']]) - $closingOf($fy1Full[$LED['expense']]);
ok(near($fy1Profit, 500000.0 - 100000.0), 'The year just ended made ' . money($fy1Profit) . ' (500,000 sales less 100,000 rent)');

// What left the income and expense accounts has to land somewhere, or the new
// year opens out of balance. It lands on ONE computed equity line, and it is
// not a plug figure: it equals exactly what was taken off those openings.
$retainedRow = null;
foreach (rc_ledger_balances($cid, $FY2_START, $FY2_END, '', 0, 0, [], $FY2_START) as $row) {
    if ((string) $row['name'] === 'Retained Earnings b/f') { $retainedRow = $row; }
}
ok($retainedRow !== null, 'The new year carries a Retained Earnings b/f line');
ok($retainedRow !== null && near((float) $retainedRow['op_cr'] - (float) $retainedRow['op_dr'], $fy1Profit),
    '  ...for exactly the ' . money($fy1Profit) . ' the closed accounts gave up');

// ===========================================================================
echo "\n2. EVERY ledger in the chart is accounted for\n";
// ===========================================================================
$chartIds = array_map('intval', db()->query("SELECT id FROM ledgers WHERE company_id=$cid")->fetchAll(PDO::FETCH_COLUMN));
$missingFy1 = array_values(array_diff($chartIds, array_keys($fy1Full)));
$missingFy2 = array_values(array_diff($chartIds, array_keys($fy2Full)));
ok($missingFy1 === [] && $missingFy2 === [],
    'All ' . count($chartIds) . ' chart-of-accounts ledgers appear in both years'
    . ($missingFy1 === [] && $missingFy2 === [] ? '' : ' — missing: ' . implode(',', array_merge($missingFy1, $missingFy2))));

$carried = 0;
$broken = [];
foreach ($chartIds as $ledgerId) {
    $nature = rc_ledger_nature($fy1Full[$ledgerId]);
    $closing = $closingOf($fy1Full[$ledgerId]);
    $opening = $openingOf($fy2Full[$ledgerId]);
    $expected = in_array($nature, ['revenue', 'expense'], true) ? 0.0 : $closing;
    if (!near($expected, $opening)) {
        $broken[] = $fy1Full[$ledgerId]['code'] . ' (' . $nature . '): closing ' . money($closing) . ' vs opening ' . money($opening);
        continue;
    }
    $carried++;
}
ok($broken === [], 'Every one of them carries forward by its own rule'
    . ($broken === [] ? " ($carried checked)" : ' — ' . implode(' | ', $broken)));

// ===========================================================================
echo "\n3. A DATE RANGE used as a cut-off, inside one year\n";
// ===========================================================================
$dayAfterMid = (new DateTimeImmutable($MID))->modify('+1 day')->format('Y-m-d');
$firstHalf = $balancesFor($FY1_START, $MID, $FY1_START);
$secondHalf = $balancesFor($dayAfterMid, $FY1_END, $FY1_START);
$rangeBroken = [];
foreach ($chartIds as $ledgerId) {
    // Inside ONE year nothing resets, so every nature must carry across the
    // cut-off — income and expense included.
    $closing = $closingOf($firstHalf[$ledgerId]);
    $opening = $openingOf($secondHalf[$ledgerId]);
    if (!near($closing, $opening)) {
        $rangeBroken[] = $firstHalf[$ledgerId]['code'] . ': ' . money($closing) . ' vs ' . money($opening);
    }
}
ok($rangeBroken === [],
    'Closing at ' . $MID . ' = opening at ' . $dayAfterMid . ' for every ledger, income and expense included'
    . ($rangeBroken === [] ? '' : ' — ' . implode(' | ', $rangeBroken)));
ok(near($closingOf($firstHalf[$LED['revenue']]), 200000.0), '  ...the half-year cut-off really does split the year (200,000 of 500,000 sales)');
ok(near($openingOf($secondHalf[$LED['revenue']]), 200000.0), '  ...and the second half opens on the first half\'s figure');

// A one-day window: its opening must be the previous day's closing.
$oneDay = $balancesFor('2027-02-20', '2027-02-20', $FY1_START);
$dayBefore = $balancesFor($FY1_START, '2027-02-19', $FY1_START);
ok(near($closingOf($dayBefore[$LED['asset']]), $openingOf($oneDay[$LED['asset']])),
    'A single-day window opens on the previous day\'s closing (' . money($openingOf($oneDay[$LED['asset']])) . ')');

// ===========================================================================
echo "\n4. THE BOOKS BALANCE at every cut-off\n";
// ===========================================================================
foreach ([[$FY1_START, $MID, 'mid-year'], [$FY1_START, $FY1_END, 'year end'],
          [$FY2_START, $FY2_END, 'the new year']] as [$wFrom, $wTo, $wLabel]) {
    $rows = rc_ledger_balances($cid, $wFrom, $wTo, '', 0, 0, [], $wFrom);
    $dr = 0.0; $cr = 0.0;
    foreach ($rows as $row) {
        $dr += (float) $row['op_dr'] + (float) $row['tx_dr'];
        $cr += (float) $row['op_cr'] + (float) $row['tx_cr'];
    }
    ok(near($dr, $cr), 'Debits equal credits at ' . $wLabel . ' (' . money($dr) . ')');
}

// ===========================================================================
echo "\n5. STOCK — the same boundary, item by item\n";
// ===========================================================================
db()->prepare("INSERT INTO inventory_items (company_id, sku, name, item_type, valuation_method, unit, purchase_rate, opening_qty, status)
        VALUES (:cid,'PCF-ITEM','Carry Forward Item','stock','weighted_average','pcs',100,0,'active')")->execute(['cid' => $cid]);
$itemId = (int) db()->lastInsertId();
$stockMove = static function (string $date, int $fyId, float $in, float $out, float $rate) use ($cid, $itemId): void {
    db()->prepare('INSERT INTO inventory_transactions (company_id, fiscal_year_id, item_id, transaction_type, transaction_date, qty_in, qty_out, rate, amount)
            VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $fyId, $itemId, $in > 0 ? 'purchase' : 'sale', $date, $in, $out, $rate, round(($in ?: $out) * $rate, 2)]);
    inv_apply_movement($cid, $itemId, $in, $out, $rate, $date, 'weighted_average');
};
$stockMove('2026-09-01', $fy1Id, 100, 0, 100);   // FY1: buy 100
$stockMove('2026-11-01', $fy1Id, 0, 30, 100);    // FY1: sell 30, before the mid cut-off
$stockMove('2027-03-01', $fy1Id, 0, 20, 100);    // FY1: sell 20, after it
$stockMove('2027-08-01', $fy2Id, 0, 10, 100);    // FY2: sell 10

$stockAt = static function (string $from, string $to) use ($cid, $itemId): array {
    foreach (sr_stock_summary($cid, ['from' => $from, 'to' => $to, 'dormant' => true])['rows'] as $row) {
        if ((int) $row['item_id'] === $itemId) { return $row; }
    }
    return [];
};
$stockFy1 = $stockAt($FY1_START, $FY1_END);
$stockFy2 = $stockAt($FY2_START, $FY2_END);
ok(near((float) $stockFy1['closing_qty'], 50.0), 'Stock closes the year at 50 (100 in, 50 out)');
ok(near((float) $stockFy2['opening_qty'], 50.0), '  ...and opens the new year at the same 50');
ok(near((float) $stockFy1['closing_amount'], (float) $stockFy2['opening_amount']),
    '  ...at the same value too (' . money((float) $stockFy1['closing_amount']) . ')');
ok(near((float) $stockFy2['closing_qty'], 40.0), 'The new year then trades on from there, closing at 40');

$stockH1 = $stockAt($FY1_START, $MID);
$stockH2 = $stockAt($dayAfterMid, $FY1_END);
ok(near((float) $stockH1['closing_qty'], 70.0), 'A mid-year cut-off closes at 70');
ok(near((float) $stockH2['opening_qty'], 70.0), '  ...and the next day opens at 70');
ok(near((float) $stockFy1['opening_qty'], 0.0),
    'The first year opens at nil, because nothing was posted before it');

// The subledger and the GL must agree about that carried-forward stock.
$glStock = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN e.entry_type='debit' THEN e.amount ELSE -e.amount END),0)
    FROM voucher_entries e JOIN vouchers v ON v.id=e.voucher_id
    WHERE v.company_id=$cid AND v.status='posted' AND e.ledger_id=" . $LED['stock'])->fetchColumn();
ok(near($glStock, 0.0), 'These stock rows were recorded without vouchers, so the GL stock ledger is nil — '
    . 'the carry-forward above is the SUBLEDGER\'s own, proved independently of the books');

// ===========================================================================
echo "\n6. ORDERS — open at the year end, still open the day after\n";
// ===========================================================================
if (table_exists('jewellery_orders')) {
    db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status)
            VALUES (:cid,'PCFCUS','Carry Customer','customer','active')")->execute(['cid' => $cid]);
    $partyId = (int) db()->lastInsertId();
    // The order table points at the jewellery masters, so they have to exist.
    jewellery_settings($cid);
    $metalId = (int) db()->query("SELECT id FROM jewellery_metals WHERE company_id=$cid AND code='GOLD'")->fetchColumn();
    $purityId = (int) db()->query("SELECT id FROM jewellery_purities WHERE company_id=$cid AND metal_id=$metalId AND code='22K'")->fetchColumn();
    $unitId = (int) db()->query("SELECT id FROM jewellery_units WHERE company_id=$cid AND code='TOLA'")->fetchColumn();
    $mkOrder = static function (string $date, string $no, string $status) use ($cid, $fy1Id, $partyId, $metalId, $purityId, $unitId): int {
        db()->prepare("INSERT INTO jewellery_orders (company_id, fiscal_year_id, order_no, order_date, party_id,
                    metal_id, purity_id, unit_id, status)
                VALUES (:cid,:fy,:no,:d,:p,:m,:pu,:u,:s)")
            ->execute(['cid' => $cid, 'fy' => $fy1Id, 'no' => $no, 'd' => $date, 'p' => $partyId,
                'm' => $metalId, 'pu' => $purityId, 'u' => $unitId, 's' => $status]);
        return (int) db()->lastInsertId();
    };
    $openOrder = $mkOrder('2027-06-01', 'PCF-ORD-OPEN', 'confirmed');
    $doneOrder = $mkOrder('2027-06-02', 'PCF-ORD-DONE', 'closed');

    // How a register with a date-range filter counts them.
    $inRange = static function (string $from, string $to) use ($cid): int {
        $stmt = db()->prepare("SELECT COUNT(*) FROM jewellery_orders
            WHERE company_id = :cid AND order_date BETWEEN :f AND :t");
        $stmt->execute(['cid' => $cid, 'f' => $from, 't' => $to]);
        return (int) $stmt->fetchColumn();
    };
    $stillOpen = static function (string $asAt) use ($cid): int {
        $stmt = db()->prepare("SELECT COUNT(*) FROM jewellery_orders
            WHERE company_id = :cid AND order_date <= :d AND status NOT IN ('closed','cancelled','delivered')");
        $stmt->execute(['cid' => $cid, 'd' => $asAt]);
        return (int) $stmt->fetchColumn();
    };
    ok($inRange($FY1_START, $FY1_END) === 2, 'Both orders were raised inside the year just ended');
    ok($inRange($FY2_START, $FY2_END) === 0,
        'A DATE-RANGE filter on the new year shows neither of them — a range filters by order DATE');
    ok($stillOpen($FY1_END) === 1, 'One order was still open at the year end');
    ok($stillOpen($FY2_START) === 1,
        '  ...and is still open the day after, because an open order is a POSITION, not a period movement');
    echo "        NOTE: an order register filtered by date range is a LIST OF ORDERS RAISED\n";
    echo "              in that range, not a balance. Open orders carry forward only where a\n";
    echo "              screen asks 'what is still open as at', which is a different query.\n";
} else {
    ok(true, 'Jewellery orders table absent — order carry-forward check skipped');
}

// ===========================================================================
echo "\n7. THE RESET IS ONLY APPLIED WHEN THE REPORT ASKS FOR IT\n";
// ===========================================================================
// rc_ledger_balances() resets income and expense only when it is TOLD where
// the fiscal year starts. A report that reads closing_net without passing it
// shows revenue accumulated since the company was founded.
$withReset = [];
foreach (rc_ledger_balances($cid, $FY2_START, $FY2_END, '', 0, 0, [], $FY2_START) as $row) {
    $withReset[(int) $row['id']] = $row;
}
$withoutReset = [];
foreach (rc_ledger_balances($cid, $FY2_START, $FY2_END) as $row) {
    $withoutReset[(int) $row['id']] = $row;
}
ok(near((float) $withReset[$LED['revenue']]['closing_net'], -90000.0),
    "Told the year start, Sales for the new year reads 90,000 — the new year's own");
ok(near((float) $withoutReset[$LED['revenue']]['closing_net'], -590000.0),
    'NOT told it, the same call reads 590,000 — every year since the company opened');
ok(near((float) $withReset[$LED['asset']]['closing_net'], (float) $withoutReset[$LED['asset']]['closing_net']),
    '  ...while a permanent account reads the same either way, which is why this is easy to miss');

// So every report that reads closing_net or the opening columns has to pass
// the year start. The movement-only callers (profit & loss figures, the
// comparative columns) read tx_dr/tx_cr and are unaffected.
$engine = file_get_contents(dirname(__DIR__) . '/app/reports_engine.php');
// Scanned LINE BY LINE, not by regexing up to the next semicolon: the
// consolidated report calls it inside a foreach head, where there is no
// semicolon, and a scan that needed one skipped the very call site that was
// wrong. A check that cannot see the fault is worse than no check.
$engineLines = explode("\n", $engine);
$unreset = [];
foreach ($engineLines as $lineNo => $lineText) {
    if (!str_contains($lineText, 'rc_ledger_balances(')) {
        continue;
    }
    if (str_contains($lineText, 'function rc_ledger_balances') || str_contains($lineText, ' * ')) {
        continue; // the declaration, and the comments about it
    }
    // Matched case-insensitively on the IDEA, not on one spelling of the
    // variable: a call site that passes $consolidatedFyStart is passing it.
    if (stripos($lineText, 'fystart') !== false || stripos($lineText, 'fy_start') !== false) {
        continue;
    }
    $unreset[$lineNo + 1] = trim($lineText);
}
echo "        rc_ledger_balances() call sites with no fiscal-year start:\n";
foreach ($unreset as $atLine => $call) {
    echo '          line ' . $atLine . ': ' . substr($call, 0, 104) . "\n";
}
// A call site is only at risk if what it does with the result reads the
// OPENING. rc_pl_figures and the comparative columns read tx_dr/tx_cr —
// period movement — which is unaffected by where the year starts. Anything
// reading closing_net or the opening columns is not, so each call site is
// followed a few lines to see which it does.
$readsOpening = [];
foreach ($unreset as $atLine => $call) {
    $window = $call . ' ' . implode(' ', array_slice($engineLines, $atLine, 8));
    if (str_contains($window, 'closing_net') || str_contains($window, 'opening_net')
        || str_contains($window, "['op_dr']") || str_contains($window, "['op_cr']")
        || str_contains($window, 'op_side_') || str_contains($window, 'cl_side_')) {
        $readsOpening[] = 'line ' . $atLine . ': ' . substr($call, 0, 80);
    }
}
ok($readsOpening === [],
    'Every call site that reads an opening balance passes the year start'
    . ($readsOpening === [] ? '' : ' — MISSING ON ' . implode(' | ', $readsOpening)));

pcf_cleanup();
echo "
" . str_repeat('=', 62) . "
  PASS: $pass   FAIL: $fail
" . str_repeat('=', 62) . "
";
exit($fail > 0 ? 1 : 0);
