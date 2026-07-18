<?php
declare(strict_types=1);

/**
 * Client-advance acceptance suite (CLI, self-contained).
 * Records an advance (Dr Bank / Cr Advances-from-Customers), then issues an
 * invoice for the task and verifies the advance is applied so the receivable is
 * shown net. Tears down after.
 *
 *   php database/test_client_advance.php
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/advance_engine.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.01; }

$ledgerBal = static function (int $ledgerId): float {
    // net debit-credit across posted vouchers
    return round((float) db()->query("SELECT COALESCE(SUM(CASE WHEN ve.entry_type='debit' THEN ve.amount ELSE -ve.amount END),0)
        FROM voucher_entries ve INNER JOIN vouchers v ON v.id=ve.voucher_id WHERE ve.ledger_id=$ledgerId AND v.status='posted'")->fetchColumn(), 2);
};

function adv_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'ADVTESTA'")->fetchAll(PDO::FETCH_COLUMN) as $stale) {
        $stale = (int) $stale;
        db()->exec("DELETE FROM invoice_payment_receipts WHERE company_id = $stale");
        db()->exec("DELETE FROM invoice_payment_requests WHERE company_id = $stale");
        db()->exec("DELETE ve FROM voucher_entries ve INNER JOIN vouchers v ON v.id=ve.voucher_id WHERE v.company_id = $stale");
        db()->exec("DELETE FROM vouchers WHERE company_id = $stale");
        db()->exec("DELETE FROM task_invoices WHERE company_id = $stale");
        db()->exec("DELETE ct FROM client_tasks ct WHERE ct.company_id = $stale");
        db()->exec("DELETE FROM accounting_parties WHERE company_id = $stale");
        db()->exec("DELETE FROM company_ledger_mappings WHERE company_id = $stale");
        db()->exec("DELETE FROM ledgers WHERE company_id = $stale");
        db()->exec("DELETE FROM ledger_groups WHERE company_id = $stale");
        db()->exec("DELETE FROM fiscal_years WHERE company_id = $stale");
        db()->exec("DELETE cp FROM client_profiles cp WHERE cp.organization_name = 'ADV Test Client'");
        db()->exec("DELETE FROM companies WHERE id = $stale");
    }
}
adv_cleanup();

echo "Fixture\n";
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Advance Test Co', 'ADVTESTA', 1)")->execute();
$cid = (int) db()->lastInsertId();
$_SESSION['company_id'] = $cid;

$mkGroup = static function (string $m, string $c, string $n, int $cash = 0) use ($cid): int {
    db()->prepare('INSERT INTO ledger_groups (company_id, master_key, code, name, is_cash_or_bank) VALUES (?,?,?,?,?)')->execute([$cid, $m, $c, $n, $cash]);
    return (int) db()->lastInsertId();
};
$mkLedger = static function (int $g, string $c, string $n, string $t) use ($cid): int {
    db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status) VALUES (?,?,?,?,?,'active')")->execute([$cid, $g, $c, $n, $t]);
    return (int) db()->lastInsertId();
};
$lBank = $mkLedger($mkGroup('current_asset', 'ADV-BANK', 'Cash and Bank', 1), 'CASH', 'Bank', 'asset');
$lAR = $mkLedger($mkGroup('current_asset', 'ADV-AR', 'Trade Receivables', 0), 'AR', 'Accounts Receivable', 'asset');
$lRev = $mkLedger($mkGroup('direct_income', 'ADV-INC', 'Sales', 0), 'REV', 'Service Revenue', 'revenue');
foreach (['default_cash_bank' => $lBank, 'default_accounts_receivable' => $lAR, 'default_service_revenue' => $lRev] as $k => $lid) {
    db()->prepare('INSERT INTO company_ledger_mappings (company_id, map_key, ledger_id) VALUES (?,?,?)')->execute([$cid, $k, $lid]);
}

$fy = create_fiscal_year($cid, 'ADV 2026/27', '2026-07-16', '2027-07-15', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$_SESSION['fiscal_year_id'] = (int) $fy['id'];

$uid = (int) db()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
db()->prepare("INSERT INTO client_profiles (user_id, organization_name) VALUES (?, 'ADV Test Client')")->execute([$uid]);
$clientId = (int) db()->lastInsertId();
db()->prepare("INSERT INTO client_tasks (company_id, client_id, title, quoted_fee, status) VALUES (?,?,?,?, 'new')")->execute([$cid, $clientId, 'Audit engagement', 8000]);
$taskId = (int) db()->lastInsertId();

$advLedgerId = ensure_customer_advance_ledger($cid);

// ---------------------------------------------------------------------------
// Record the advance
// ---------------------------------------------------------------------------
echo "\nRecord advance\n";
$res = record_task_advance($cid, $taskId, 5000.0, 'Cash', '2026-07-20', $uid);
ok(!empty($res['ok']) && (int) $res['voucher_id'] > 0, 'Advance recorded');
ok(near($ledgerBal($lBank), 5000), 'Bank debited 5,000');
ok(near($ledgerBal($advLedgerId), -5000), 'Advances-from-Customers credited 5,000 (liability)');
ok(near(task_advance_posted($cid, $taskId), 5000) && near(task_advance_available($cid, $taskId), 5000), 'Task advance posted=5,000, available=5,000');
$dupe = record_task_advance($cid, $taskId, 1000.0, 'Cash', '2026-07-21', $uid);
ok(empty($dupe['ok']) && str_contains((string) ($dupe['error'] ?? ''), 'already'), 'A second advance on the same task is rejected');

// ---------------------------------------------------------------------------
// Issue an 8,000 invoice for the task -> advance applied, receivable net 3,000
// ---------------------------------------------------------------------------
echo "\nIssue invoice, advance auto-applied\n";
db()->prepare("INSERT INTO task_invoices (company_id, task_id, invoice_no, invoice_type, invoice_category, amount, taxable_amount, vat_rate, vat_amount, total_amount, status, issued_on)
    VALUES (:cid,:tid,:no,'task','tax',8000,8000,0,0,8000,'issued','2026-07-25')")
    ->execute(['cid' => $cid, 'tid' => $taskId, 'no' => 'ADV-INV-1']);
$invId = (int) db()->lastInsertId();
$postErr = auto_post_task_invoice_voucher($invId, $uid);
ok($postErr === null, 'Invoice sales voucher posted (' . ($postErr ?? 'ok') . ')');
ok(near($ledgerBal($lRev), -8000), 'Revenue credited 8,000');

$applied = (float) db()->query("SELECT COALESCE(SUM(payment_amount),0) FROM invoice_payment_requests WHERE invoice_id=$invId AND payment_method='Advance applied'")->fetchColumn();
ok(near($applied, 5000), 'Advance of 5,000 applied to the invoice as a payment');
ok(near($ledgerBal($advLedgerId), 0), 'Advance liability cleared to 0 after application (5,000 Cr - 5,000 Dr)');
$paid = (float) db()->query("SELECT COALESCE(SUM(payment_amount),0) FROM invoice_payment_requests WHERE invoice_id=$invId AND status IN ('paid','partial')")->fetchColumn();
$outstanding = round(8000 - $paid, 2);
ok(near($outstanding, 3000), 'Receivable is NET of the advance: outstanding = 8,000 - 5,000 = 3,000');
// The sales voucher debits the resolved receivable ledger (the party's own ledger);
// the advance-applied voucher credits that SAME ledger, so its net = 3,000.
$arLedgerId = (int) db()->query("SELECT ve.ledger_id FROM vouchers v INNER JOIN voucher_entries ve ON ve.voucher_id=v.id
    WHERE v.source_type='task_invoice' AND v.source_id=$invId AND ve.entry_type='debit' LIMIT 1")->fetchColumn();
ok($arLedgerId > 0 && near($ledgerBal($arLedgerId), 3000), 'Receivable ledger net balance = 3,000 (8,000 Dr sales - 5,000 Cr advance-applied)');
ok((string) db()->query("SELECT status FROM task_invoices WHERE id=$invId")->fetchColumn() === 'issued', 'Partly-covered invoice stays issued (advance < total)');
// Idempotent: re-posting must not double-apply.
auto_post_task_invoice_voucher($invId, $uid);
$applied2 = (float) db()->query("SELECT COALESCE(SUM(payment_amount),0) FROM invoice_payment_requests WHERE invoice_id=$invId AND payment_method='Advance applied'")->fetchColumn();
ok(near($applied2, 5000), 'Re-posting is idempotent (advance applied once, still 5,000)');

// ---------------------------------------------------------------------------
// Review fixes
// ---------------------------------------------------------------------------
echo "\nReview fixes\n";
// Fix 2: advance_applied voucher dated to the invoice's issued_on (2026-07-25), not today.
$advAppDate = (string) db()->query("SELECT voucher_date FROM vouchers WHERE source_type='advance_applied' AND source_id=$invId LIMIT 1")->fetchColumn();
ok($advAppDate === '2026-07-25', "advance_applied voucher dated to the invoice's issued_on, not today ($advAppDate)");

// Fix 3: the paid-at-issuance shortcut must charge only the residual, not the full total.
ok(near(invoice_amount_after_advance($invId), 3000), 'invoice_amount_after_advance = residual 3,000 (paid shortcut charges only this, no double-count)');

// Fix 4: advance vouchers are guarded against deletion from the voucher UI.
$advVid = (int) db()->query("SELECT id FROM vouchers WHERE source_type='task_advance' AND source_id=$taskId LIMIT 1")->fetchColumn();
$del1 = delete_voucher_with_entries($advVid, $cid, $uid);
ok(empty($del1['ok']) && stripos((string) ($del1['error'] ?? ''), 'advance') !== false, 'Deleting the task_advance voucher is blocked (mutation guard)');
$advAppVid = (int) db()->query("SELECT id FROM vouchers WHERE source_type='advance_applied' AND source_id=$invId LIMIT 1")->fetchColumn();
ok(empty(delete_voucher_with_entries($advAppVid, $cid, $uid)['ok']), 'Deleting the advance_applied voucher is blocked (mutation guard)');

adv_cleanup();
echo "\n----------------------------------------\n";
echo "PASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
