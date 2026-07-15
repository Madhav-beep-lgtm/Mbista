<?php
declare(strict_types=1);

/**
 * Fiscal-year control test suite (CLI, self-contained).
 *
 * Builds two throw-away companies with three consecutive fiscal years
 * (2024/25, 2025/26, 2026/27) plus a small chart of accounts, exercises the
 * REAL service layer, posting engine and reports engine, then removes every
 * row it created. Run:
 *
 *   php database/test_fiscal_year_controls.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/reports_engine.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';

accounting_module_repair_database(); // fiscal_years lifecycle columns

$pass = 0;
$fail = 0;
function ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label\n";
    }
}
function near(float $a, float $b): bool
{
    return abs($a - $b) < 0.005;
}

// ---------------------------------------------------------------------------
// Fixture: two companies, chart of accounts, three consecutive fiscal years.
// ---------------------------------------------------------------------------
echo "Setting up fixture...\n";
// Remove leftovers from an aborted previous run first.
foreach (db()->query("SELECT id FROM companies WHERE code IN ('FYTESTA', 'FYTESTB')")->fetchAll(PDO::FETCH_COLUMN) as $staleCid) {
    $staleCid = (int) $staleCid;
    db()->exec('DELETE ve FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id WHERE v.company_id = ' . $staleCid);
    db()->exec('DELETE FROM vouchers WHERE company_id = ' . $staleCid);
    db()->exec('DELETE FROM fiscal_period_locks WHERE company_id = ' . $staleCid);
    db()->exec('DELETE FROM fiscal_years WHERE company_id = ' . $staleCid);
    db()->exec('DELETE FROM ledgers WHERE company_id = ' . $staleCid);
    db()->exec('DELETE FROM ledger_groups WHERE company_id = ' . $staleCid);
    db()->exec('DELETE FROM companies WHERE id = ' . $staleCid);
}
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('FY Test Co', 'FYTESTA', 1)")->execute();
$cidA = (int) db()->lastInsertId();
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('FY Test Co B', 'FYTESTB', 1)")->execute();
$cidB = (int) db()->lastInsertId();

$mkGroup = static function (int $cid, string $master, string $code, string $name, int $cash = 0): int {
    db()->prepare('INSERT INTO ledger_groups (company_id, master_key, code, name, is_cash_or_bank) VALUES (?, ?, ?, ?, ?)')
        ->execute([$cid, $master, $code, $name, $cash]);
    return (int) db()->lastInsertId();
};
$mkLedger = static function (int $cid, int $gid, string $code, string $name, string $type): int {
    db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status) VALUES (?, ?, ?, ?, ?, 'active')")
        ->execute([$cid, $gid, $code, $name, $type]);
    return (int) db()->lastInsertId();
};
$gCash = $mkGroup($cidA, 'current_asset', 'FYT-CASH', 'Cash and Bank', 1);
$gEquity = $mkGroup($cidA, 'equity', 'FYT-EQ', 'Share Capital');
$gIncome = $mkGroup($cidA, 'direct_income', 'FYT-INC', 'Operating Income');
$gExpense = $mkGroup($cidA, 'direct_expense', 'FYT-EXP', 'Operating Expenses');
$lCash = $mkLedger($cidA, $gCash, 'FYT-100', 'Cash', 'asset');
$lEquity = $mkLedger($cidA, $gEquity, 'FYT-300', 'Share Capital', 'equity');
$lIncome = $mkLedger($cidA, $gIncome, 'FYT-400', 'Service Income', 'revenue');
$lExpense = $mkLedger($cidA, $gExpense, 'FYT-500', 'Office Expense', 'expense');

// ---------------------------------------------------------------------------
// 1-8: creation, duplicates, overlaps, adjacency, defaults, isolation.
// ---------------------------------------------------------------------------
echo "\nFiscal-year creation and overlap protection\n";
$fy1 = create_fiscal_year($cidA, 'FY 2024/25', '2024-07-16', '2025-07-15', true);
$fy2 = create_fiscal_year($cidA, 'FY 2025/26', '2025-07-16', '2026-07-15', false);
$fy3 = create_fiscal_year($cidA, 'FY 2026/27', '2026-07-16', '2027-07-15', false);
ok($fy1['ok'] && $fy2['ok'] && $fy3['ok'], 'Three consecutive fiscal years created for one company');

ok(!create_fiscal_year($cidA, 'FY dup', '2024-07-16', '2025-07-15', false)['ok'], 'Exact duplicate period rejected');
ok(!create_fiscal_year($cidA, 'FY 2024/25', '2030-01-01', '2030-12-31', false)['ok'], 'Duplicate label rejected');
ok(!create_fiscal_year($cidA, 'FY partial', '2025-01-01', '2025-12-31', false)['ok'], 'Partial overlap (start inside existing) rejected');
ok(!create_fiscal_year($cidA, 'FY inside', '2024-10-01', '2025-03-31', false)['ok'], 'New period contained in existing rejected');
ok(!create_fiscal_year($cidA, 'FY around', '2024-01-01', '2028-01-01', false)['ok'], 'New period containing existing rejected');
ok(!create_fiscal_year($cidA, 'FY reversed', '2028-12-31', '2028-01-01', false)['ok'], 'Start after end rejected');
ok(!create_fiscal_year($cidA, 'FY invalid', '2028-02-30', '2028-12-31', false)['ok'], 'Invalid calendar date rejected');
$fyAdj = create_fiscal_year($cidA, 'FY 2027/28', '2027-07-16', '2028-07-15', false);
ok($fyAdj['ok'], 'Adjacent non-overlapping year accepted');
ok(create_fiscal_year($cidB, 'FY 2024/25', '2024-07-16', '2025-07-15', true)['ok'], 'Same period accepted for a DIFFERENT company');

// Continuity: years form an unbroken chain — no islands, no gaps.
$island = create_fiscal_year($cidA, 'FY island', '2035-01-01', '2035-12-31', false);
ok(!$island['ok'] && str_contains((string) $island['error'], 'continuous'), 'A year leaving a gap (island) is rejected — fiscal years must be continuous');
ok(create_fiscal_year($cidA, 'FY 2023/24', '2023-07-16', '2024-07-15', false)['ok'], 'Extending the chain backward (ends the day before the first year) accepted');
// Legacy gap (inserted directly, as old data could be) can be FILLED via the service.
db()->prepare("INSERT INTO fiscal_years (company_id, label, start_date, end_date, is_active, is_default) VALUES (?, 'FY legacy 2030', '2030-01-01', '2030-12-31', 1, 0)")->execute([$cidA]);
ok(create_fiscal_year($cidA, 'FY gap filler', '2028-07-16', '2029-12-31', false)['ok'], 'Filling a legacy gap (adjacent to the chain) accepted');

$defCount = db()->prepare('SELECT COUNT(*) FROM fiscal_years WHERE company_id = ? AND is_default = 1');
$defCount->execute([$cidA]);
ok((int) $defCount->fetchColumn() === 1, 'Exactly one default fiscal year after creation');
$switch = set_default_fiscal_year($cidA, $fy2['id']);
$defCount->execute([$cidA]);
ok($switch['ok'] && (int) $defCount->fetchColumn() === 1, 'set_default_fiscal_year keeps exactly one default');
set_default_fiscal_year($cidA, $fy1['id']);
$auditStmt = db()->prepare("SELECT COUNT(*) FROM activity_logs WHERE entity_type = 'fiscal_year' AND action = 'default_changed'");
$auditStmt->execute();
ok((int) $auditStmt->fetchColumn() >= 2, 'Default changes are written to the audit log');

$_SESSION['fy_selection'] = [];
set_context($cidA, $fy2['id']);
set_context($cidB, (int) db()->query('SELECT id FROM fiscal_years WHERE company_id = ' . $cidB . ' LIMIT 1')->fetchColumn());
ok(remembered_fiscal_year_id($cidA) === $fy2['id'], 'User selection remembered per company (A)');
ok(remembered_fiscal_year_id($cidA) !== remembered_fiscal_year_id($cidB), 'Selection isolated between companies');
$defA = db()->prepare('SELECT id FROM fiscal_years WHERE company_id = ? AND is_default = 1');
$defA->execute([$cidA]);
ok((int) $defA->fetchColumn() === $fy1['id'], 'Selecting a year for viewing does not change the company default');

// ---------------------------------------------------------------------------
// 9-14: posting engine — date decides the year; cutoff; status.
// ---------------------------------------------------------------------------
echo "\nPosting engine enforcement\n";
$post = static function (string $date, int $fyId, int $drLedger, int $crLedger, float $amount, int $cid) {
    return create_voucher_with_entries([
        'company_id' => $cid, 'fiscal_year_id' => $fyId,
        'voucher_no' => 'FYT-' . strtoupper(bin2hex(random_bytes(4))),
        'voucher_type' => 'journal', 'voucher_date' => $date,
        'source_type' => 'fyt_test', 'source_id' => null,
        'total_amount' => $amount, 'narration' => 'FY test voucher', 'status' => 'posted',
    ], [
        ['ledger_id' => $drLedger, 'entry_type' => 'debit', 'amount' => $amount],
        ['ledger_id' => $crLedger, 'entry_type' => 'credit', 'amount' => $amount],
    ]);
};

$vid = $post('2024-08-01', $fy1['id'], $lCash, $lEquity, 1000.00, $cidA);
ok($vid > 0, 'Voucher dated inside its fiscal year accepted');

$tamperedId = $post('2024-09-01', $fy3['id'], $lCash, $lIncome, 500.00, $cidA); // FY3 id, FY1 date
$storedFy = db()->prepare('SELECT fiscal_year_id FROM vouchers WHERE id = ?');
$storedFy->execute([$tamperedId]);
ok((int) $storedFy->fetchColumn() === $fy1['id'], 'Tampered fiscal_year_id recalculated from the transaction date');

$rejected = false;
try {
    $post('2023-01-01', $fy1['id'], $lCash, $lEquity, 10.00, $cidA);
} catch (Throwable $e) {
    $rejected = str_contains($e->getMessage(), 'covers the transaction date');
}
ok($rejected, 'Voucher dated outside every fiscal year rejected with a clear reason');

db()->prepare('INSERT INTO fiscal_period_locks (company_id, fiscal_year_id, locked_through) VALUES (?, ?, ?)')
    ->execute([$cidA, $fy2['id'], '2025-12-31']);
$rejected = false;
try {
    $post('2025-10-01', $fy2['id'], $lExpense, $lCash, 10.00, $cidA);
} catch (Throwable $e) {
    $rejected = str_contains($e->getMessage(), 'cutoff');
}
ok($rejected, 'Transaction on or before the cutoff date blocked');
ok($post('2026-01-10', $fy2['id'], $lCash, $lIncome, 100.00, $cidA) > 0, 'Transaction after the cutoff accepted');

db()->prepare("UPDATE fiscal_years SET status = 'closed' WHERE id = ?")->execute([$fy1['id']]);
$rejected = false;
try {
    $post('2025-01-05', $fy1['id'], $lExpense, $lCash, 10.00, $cidA);
} catch (Throwable $e) {
    $rejected = str_contains($e->getMessage(), 'closed');
}
ok($rejected, 'Closed fiscal year rejects new postings');
$fy1Voucher = db()->prepare('SELECT * FROM vouchers WHERE id = ?');
$fy1Voucher->execute([$vid]);
$blocker = voucher_mutation_blocker($fy1Voucher->fetch());
ok($blocker !== null && str_contains($blocker, 'closed'), 'Vouchers inside a closed year refuse edit/delete');

db()->prepare("UPDATE fiscal_years SET status = 'locked' WHERE id = ?")->execute([$fy1['id']]);
$rejected = false;
try {
    $post('2025-01-05', $fy1['id'], $lExpense, $lCash, 10.00, $cidA);
} catch (Throwable $e) {
    $rejected = str_contains($e->getMessage(), 'locked');
}
ok($rejected, 'Locked fiscal year rejects new postings');
db()->prepare("UPDATE fiscal_years SET status = 'open' WHERE id = ?")->execute([$fy1['id']]);

db()->prepare("UPDATE fiscal_years SET status = 'upcoming' WHERE id = ?")->execute([$fyAdj['id']]);
$rejected = false;
try {
    $post('2027-08-01', $fyAdj['id'], $lCash, $lIncome, 10.00, $cidA);
} catch (Throwable $e) {
    $rejected = str_contains($e->getMessage(), 'upcoming');
}
ok($rejected, 'Upcoming fiscal year rejects postings until opened');

// ---------------------------------------------------------------------------
// 15-23: carry-forward, retained earnings, trial balance, balance sheet.
// FY1: capital 1000, income 500, expense 200  -> profit 300, cash 1300
// FY2: income 100 (posted above)              -> profit 100, cash 1400
// FY3: expense 250                            -> loss 250,  cash 1150
// ---------------------------------------------------------------------------
echo "\nOpening balances, retained earnings, and statements\n";
$post('2024-09-15', $fy1['id'], $lExpense, $lCash, 200.00, $cidA); // completes FY1 activity (income 500 already posted)

$fy2Rows = rc_ledger_balances($cidA, '2025-07-16', '2026-07-15', '', 0, 0, [], '2025-07-16');
$byName = static function (array $rows, string $name): ?array {
    foreach ($rows as $r) {
        if ((string) $r['name'] === $name) {
            return $r;
        }
    }
    return null;
};
$cashRow = $byName($fy2Rows, 'Cash');
$incomeRow = $byName($fy2Rows, 'Service Income');
$expenseRow = $byName($fy2Rows, 'Office Expense');
$reRow = $byName($fy2Rows, 'Retained Earnings b/f');
ok($cashRow && near((float) $cashRow['opening_net'], 1300.00), 'Asset closing balance carries forward as next-year opening (1300)');
ok($incomeRow && near((float) $incomeRow['opening_net'], 0.0), 'Income opening balance resets to zero in the new fiscal year');
ok($expenseRow && near((float) $expenseRow['opening_net'], 0.0), 'Expense opening balance resets to zero in the new fiscal year');
ok($reRow && near((float) $reRow['opening_net'], -300.00), 'Prior-year profit (300) appears as opening retained earnings (credit)');
$fy2Profit = 0.0;
foreach ($fy2Rows as $r) {
    $nature = rc_ledger_nature($r);
    if ($nature === 'revenue') {
        $fy2Profit += -(float) $r['closing_net'];
    } elseif ($nature === 'expense') {
        $fy2Profit -= (float) $r['closing_net'];
    }
}
ok(near($fy2Profit, 100.00), 'Current-year profit contains only the selected year\'s activity (100, counted once)');

$opDr = 0.0;
$opCr = 0.0;
$clDr = 0.0;
$clCr = 0.0;
foreach ($fy2Rows as $r) {
    $opDr += (float) $r['op_side_dr'];
    $opCr += (float) $r['op_side_cr'];
    $clDr += (float) $r['cl_side_dr'];
    $clCr += (float) $r['cl_side_cr'];
}
ok(near($opDr, $opCr), 'Trial balance opening debits equal opening credits');
ok(near($clDr, $clCr), 'Trial balance closing debits equal closing credits');

$assets = 0.0;
$equityAndRe = 0.0;
foreach ($fy2Rows as $r) {
    $nature = rc_ledger_nature($r);
    if ($nature === 'asset') {
        $assets += (float) $r['closing_net'];
    } elseif ($nature === 'equity' || $nature === 'liability') {
        $equityAndRe += -(float) $r['closing_net'];
    }
}
ok(near($assets, $equityAndRe + $fy2Profit), 'Balance sheet balances: Assets = Liabilities + Equity (incl. RE b/f + current profit)');

// Multi-year continuity + loss handling. FY3 may have been auto-created as
// "upcoming" (its start date can be in the server's future) — opening it is
// the authorized transition that permits posting.
db()->prepare("UPDATE fiscal_years SET status = 'open' WHERE id = ?")->execute([$fy3['id']]);
$post('2026-09-01', $fy3['id'], $lExpense, $lCash, 250.00, $cidA);
$fy3Rows = rc_ledger_balances($cidA, '2026-07-16', '2027-07-15', '', 0, 0, [], '2026-07-16');
$reRow3 = $byName($fy3Rows, 'Retained Earnings b/f');
$cashRow3 = $byName($fy3Rows, 'Cash');
ok($reRow3 && near((float) $reRow3['opening_net'], -400.00), 'Retained earnings accumulate across multiple years (300 + 100 = 400)');
ok($cashRow3 && near((float) $cashRow3['opening_net'], 1400.00), 'Cash continuity across two year boundaries (1400)');
$fyAdjRows = rc_ledger_balances($cidA, '2027-07-16', '2028-07-15', '', 0, 0, [], '2027-07-16');
$reRowAdj = $byName($fyAdjRows, 'Retained Earnings b/f');
ok($reRowAdj && near((float) $reRowAdj['opening_net'], -150.00), 'Current-year loss reduces retained earnings (400 - 250 = 150)');

$fy2RowsAgain = rc_ledger_balances($cidA, '2025-07-16', '2026-07-15', '', 0, 0, [], '2025-07-16');
$reAgain = $byName($fy2RowsAgain, 'Retained Earnings b/f');
ok($reAgain && near((float) $reAgain['opening_net'], (float) $reRow['opening_net']), 'Re-running the carry-forward calculation never duplicates balances (pure recalculation)');

// Trial balance through the full generator with fiscal-year context.
$tb = rc_generate('trial-balance', $cidA, '2025-07-16', '2026-07-15', ['currency' => 'Rs ', 'org_default' => 'service', 'fy_start' => '2025-07-16', 'dims' => []]);
$tbTotals = $tb['totals'];
ok($tbTotals[2] === $tbTotals[3] && $tbTotals[6] === $tbTotals[7], 'rc_generate trial balance: total debit equals total credit (opening and closing)');

// ---------------------------------------------------------------------------
// 24: fiscal_year_for_date + overlap rule helper sanity.
// ---------------------------------------------------------------------------
echo "\nHelpers\n";
$resolved = fiscal_year_for_date($cidA, '2026-07-15');
ok($resolved && (int) $resolved['id'] === $fy2['id'], 'fiscal_year_for_date resolves boundary date to the correct year');
$resolved = fiscal_year_for_date($cidA, '2026-07-16');
ok($resolved && (int) $resolved['id'] === $fy3['id'], 'fiscal_year_for_date resolves the next day to the next year');

// ---------------------------------------------------------------------------
// Cleanup — remove everything the fixture created.
// ---------------------------------------------------------------------------
echo "\nCleaning up fixture...\n";
foreach ([$cidA, $cidB] as $cid) {
    db()->exec('DELETE ve FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id WHERE v.company_id = ' . $cid);
    db()->exec('DELETE FROM vouchers WHERE company_id = ' . $cid);
    db()->exec('DELETE FROM fiscal_period_locks WHERE company_id = ' . $cid);
    db()->exec('DELETE FROM fiscal_years WHERE company_id = ' . $cid);
    db()->exec('DELETE FROM ledgers WHERE company_id = ' . $cid);
    db()->exec('DELETE FROM ledger_groups WHERE company_id = ' . $cid);
    db()->exec('DELETE FROM companies WHERE id = ' . $cid);
}
db()->exec("DELETE FROM activity_logs WHERE entity_type = 'fiscal_year' AND details LIKE '%FY 20%'");

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
