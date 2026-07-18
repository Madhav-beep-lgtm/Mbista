<?php
declare(strict_types=1);

/**
 * Payroll "reopen for correction" acceptance suite (CLI, self-contained).
 *
 * Builds a throw-away company with one open fiscal year, a payroll chart of
 * accounts, mapped payroll settings, a published tax version, two employees and
 * one advance; exercises the REAL payroll engine end to end
 * (create -> calculate -> approve/post -> pay -> REOPEN -> edit -> re-post); then
 * removes every row it created.
 *
 *   php database/test_payroll_reopen.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/payroll_engine.php';

$pass = 0;
$fail = 0;
function ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
}
function near(float $a, float $b): bool { return abs($a - $b) < 0.01; }

$voucherExists = static fn (int $id): int => (int) db()->query('SELECT COUNT(*) FROM vouchers WHERE id = ' . $id)->fetchColumn();
$entriesExist = static fn (int $id): int => (int) db()->query('SELECT COUNT(*) FROM voucher_entries WHERE voucher_id = ' . $id)->fetchColumn();
$loanBalance = static fn (int $id): float => (float) db()->query('SELECT balance FROM payroll_loans WHERE id = ' . $id)->fetchColumn();
$loanStatus = static fn (int $id): string => (string) db()->query('SELECT status FROM payroll_loans WHERE id = ' . $id)->fetchColumn();
$recoveryRows = static fn (int $runId): int => (int) db()->query("SELECT COUNT(*) FROM payroll_loan_txns WHERE run_id = $runId AND txn_type = 'recovery'")->fetchColumn();

// ---------------------------------------------------------------------------
// Clean any stale fixture from a previous run
// ---------------------------------------------------------------------------
function pr_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'PRTESTA'")->fetchAll(PDO::FETCH_COLUMN) as $stale) {
        $stale = (int) $stale;
        db()->exec("DELETE lt FROM payroll_loan_txns lt INNER JOIN payroll_loans l ON l.id = lt.loan_id WHERE l.company_id = $stale");
        db()->exec("DELETE FROM payroll_loans WHERE company_id = $stale");
        db()->exec("DELETE rl FROM payroll_run_lines rl INNER JOIN payroll_runs r ON r.id = rl.run_id WHERE r.company_id = $stale");
        db()->exec("DELETE FROM payroll_runs WHERE company_id = $stale");
        db()->exec("DELETE ec FROM payroll_employee_components ec INNER JOIN payroll_employees e ON e.id = ec.payroll_employee_id WHERE e.company_id = $stale");
        db()->exec("DELETE FROM payroll_employees WHERE company_id = $stale");
        db()->exec("DELETE FROM payroll_components WHERE company_id = $stale");
        db()->exec("DELETE s FROM payroll_tax_slabs s INNER JOIN payroll_tax_versions v ON v.id = s.version_id WHERE v.company_id = $stale");
        db()->exec("DELETE FROM payroll_tax_versions WHERE company_id = $stale");
        db()->exec("DELETE FROM payroll_settings WHERE company_id = $stale");
        db()->exec("DELETE ve FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id WHERE v.company_id = $stale");
        db()->exec("DELETE FROM vouchers WHERE company_id = $stale");
        db()->exec("DELETE FROM fiscal_period_locks WHERE company_id = $stale");
        db()->exec("DELETE FROM ledgers WHERE company_id = $stale");
        db()->exec("DELETE FROM ledger_groups WHERE company_id = $stale");
        db()->exec("DELETE FROM fiscal_years WHERE company_id = $stale");
        db()->exec("DELETE FROM companies WHERE id = $stale");
    }
}
pr_cleanup();

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------
echo "Setting up fixture...\n";
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Payroll Reopen Test Co', 'PRTESTA', 1)")->execute();
$cid = (int) db()->lastInsertId();
$_SESSION['company_id'] = $cid;

$mkGroup = static function (string $master, string $code, string $name, int $cash = 0) use ($cid): int {
    db()->prepare('INSERT INTO ledger_groups (company_id, master_key, code, name, is_cash_or_bank) VALUES (?,?,?,?,?)')
        ->execute([$cid, $master, $code, $name, $cash]);
    return (int) db()->lastInsertId();
};
$mkLedger = static function (int $gid, string $code, string $name, string $type) use ($cid): int {
    db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status) VALUES (?,?,?,?,?, 'active')")
        ->execute([$cid, $gid, $code, $name, $type]);
    return (int) db()->lastInsertId();
};
$gExp = $mkGroup('indirect_expense', 'PR-EXP', 'Employee Benefit Expenses');
$gLiab = $mkGroup('current_liability', 'PR-LIAB', 'Employee Payables');
$gAsset = $mkGroup('current_asset', 'PR-ADV', 'Loans and Advances');
$gBank = $mkGroup('current_asset', 'PR-BANK', 'Cash and Bank', 1);

$lSalExp = $mkLedger($gExp, 'SAL-EXP', 'Salary Expense', 'expense');
$lErExp = $mkLedger($gExp, 'SSF-EXP', 'Employer Retirement Contribution Expense', 'expense');
$lTds = $mkLedger($gLiab, 'TDS-PAY', 'Salary TDS Payable', 'liability');
$lRet = $mkLedger($gLiab, 'RET-PAY', 'Retirement Fund Payable', 'liability');
$lSalPay = $mkLedger($gLiab, 'SAL-PAY', 'Salary Payable', 'liability');
$lAdv = $mkLedger($gAsset, 'EMP-ADV', 'Employee Advances', 'asset');
$lBank = $mkLedger($gBank, 'PR-CASH', 'Bank', 'asset');

$fy = create_fiscal_year($cid, 'PR 2026/27', '2026-07-16', '2027-07-15', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId = (int) $fy['id'];
$_SESSION['fiscal_year_id'] = $fyId;
$payDate = '2026-07-17';

payroll_settings($cid);
db()->prepare('UPDATE payroll_settings SET salary_expense_ledger_id = :se, employer_contrib_expense_ledger_id = :ee,
        tds_payable_ledger_id = :tds, retirement_payable_ledger_id = :rp, salary_payable_ledger_id = :sp,
        advance_ledger_id = :adv, bank_ledger_id = :bank, auto_post = 1, enforce_sod = 0 WHERE company_id = :cid')
    ->execute(['se' => $lSalExp, 'ee' => $lErExp, 'tds' => $lTds, 'rp' => $lRet, 'sp' => $lSalPay,
        'adv' => $lAdv, 'bank' => $lBank, 'cid' => $cid]);

// Published tax version + individual slabs (demo values).
db()->prepare("INSERT INTO payroll_tax_versions (company_id, fiscal_year_id, label, legal_reference, status, effective_from,
        retirement_limit_pct, retirement_limit_cap, ssf_first_slab_exempt, rounding, created_by, approved_by)
    VALUES (:cid, :fy, 'PR test slabs', 'DEMO', 'published', '2026-07-16', 33.33, 500000, 1, 'nearest', NULL, NULL)")
    ->execute(['cid' => $cid, 'fy' => $fyId]);
$versionId = (int) db()->lastInsertId();
$slabInsert = db()->prepare('INSERT INTO payroll_tax_slabs (version_id, category, lower_bound, upper_bound, rate, sort_order) VALUES (?,?,?,?,?,?)');
foreach ([[0, 500000, 1], [500000, 700000, 10], [700000, 1000000, 20], [1000000, null, 30]] as $sort => [$lo, $hi, $rate]) {
    $slabInsert->execute([$versionId, 'individual', $lo, $hi, $rate, $sort]);
}

// Two employees on existing user rows; E001 (SSF + an advance), E002 (no scheme).
$userIds = db()->query('SELECT id FROM users ORDER BY id ASC LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if (count($userIds) < 2) { exit("Need at least 2 users in the DB to run this test.\n"); }
[$uidA, $uidB] = array_map('intval', $userIds);
$empInsert = db()->prepare('INSERT INTO payroll_employees (company_id, user_id, employee_code, department, designation,
        pan_no, bank_name, bank_account, marital_status, retirement_scheme, retirement_id,
        retirement_employee_rate, retirement_employer_rate, basic_salary, status, joined_on)
    VALUES (:cid, :uid, :code, :dept, :desig, :pan, :bname, :bacc, :marital, :scheme, :rid, :rer, :rerr, :basic, \'active\', :joined)');
$empInsert->execute(['cid' => $cid, 'uid' => $uidA, 'code' => 'E001', 'dept' => 'Audit', 'desig' => 'Senior',
    'pan' => '601234567', 'bname' => 'Sunrise', 'bacc' => '0150010012345', 'marital' => 'individual', 'scheme' => 'ssf',
    'rid' => 'SSF-1', 'rer' => 11, 'rerr' => 20, 'basic' => 55000, 'joined' => '2026-07-16']);
$empA = (int) db()->lastInsertId();
$empInsert->execute(['cid' => $cid, 'uid' => $uidB, 'code' => 'E002', 'dept' => 'Consulting', 'desig' => 'Consultant',
    'pan' => '609876543', 'bname' => 'Everest', 'bacc' => '0150010067890', 'marital' => 'individual', 'scheme' => 'none',
    'rid' => null, 'rer' => 0, 'rerr' => 0, 'basic' => 30000, 'joined' => '2026-07-16']);
$empB = (int) db()->lastInsertId();

// Advance for E001, recovered 5,000/month.
db()->prepare("INSERT INTO payroll_loans (company_id, payroll_employee_id, title, principal, balance, monthly_installment, status, issued_on)
        VALUES (:cid, :pe, 'Festival advance', 20000, 20000, 5000, 'active', :dt)")
    ->execute(['cid' => $cid, 'pe' => $empA, 'dt' => '2026-07-01']);
$loanId = (int) db()->lastInsertId();
db()->prepare("INSERT INTO payroll_loan_txns (loan_id, txn_type, amount, txn_date, notes) VALUES (:l, 'disbursement', 20000, :dt, 'Advance disbursed')")
    ->execute(['l' => $loanId, 'dt' => '2026-07-01']);

$actorId = $uidA;

// ---------------------------------------------------------------------------
// Lifecycle: create -> calculate -> approve/post -> pay
// ---------------------------------------------------------------------------
echo "\nPost + pay\n";
db()->prepare('INSERT INTO payroll_runs (company_id, fiscal_year_id, period_no, period_label, pay_date, created_by)
        VALUES (:cid, :fy, 1, :label, :pay, :by)')
    ->execute(['cid' => $cid, 'fy' => $fyId, 'label' => 'PR Month 1', 'pay' => $payDate, 'by' => $actorId]);
$runId = (int) db()->lastInsertId();

$calc = payroll_calculate_run($runId);
ok($calc['ok'], 'Run calculated');
ok((string) payroll_run($runId)['status'] === 'calculated', 'Status is calculated');
ok(near($loanBalance($loanId), 20000), 'Loan balance untouched by calculation (20,000)');

$postRes = payroll_approve_and_post($runId, $actorId + 1); // different approver (SOD off)
ok($postRes['ok'], 'Approve & post succeeded');
$accrualVid = (int) ($postRes['voucher_id'] ?? 0);
ok($accrualVid > 0 && $voucherExists($accrualVid) === 1, 'Accrual voucher posted');
ok((string) payroll_run($runId)['status'] === 'posted', 'Status is posted');
ok(near($loanBalance($loanId), 15000), 'Advance recovered on post (balance 20,000 -> 15,000)');
ok($recoveryRows($runId) === 1, 'Exactly one recovery ledger row after post');
$salExpBal = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE -amount END),0)
    FROM voucher_entries WHERE ledger_id = $lSalExp")->fetchColumn();
ok(near($salExpBal, 55000 + 30000), 'Salary expense debited with gross (85,000)');

$payRes = payroll_record_payment($runId, $actorId, $payDate);
ok($payRes['ok'], 'Payment recorded');
$paymentVid = (int) ($payRes['voucher_id'] ?? 0);
ok($paymentVid > 0 && $voucherExists($paymentVid) === 1, 'Payment voucher posted');
ok((string) payroll_run($runId)['status'] === 'paid', 'Status is paid');

// ---------------------------------------------------------------------------
// Reopen guards
// ---------------------------------------------------------------------------
echo "\nReopen guards\n";
$short = payroll_reopen_run($runId, $actorId, 'too short');
ok(!$short['ok'] && str_contains((string) $short['error'], 'at least 10'), 'Reopen rejects a reason under 10 chars');

// A fresh calculated run cannot be reopened (it is already editable).
db()->prepare('INSERT INTO payroll_runs (company_id, fiscal_year_id, period_no, period_label, pay_date, created_by)
        VALUES (:cid, :fy, 2, :label, :pay, :by)')
    ->execute(['cid' => $cid, 'fy' => $fyId, 'label' => 'PR Month 2', 'pay' => $payDate, 'by' => $actorId]);
$calcRunId = (int) db()->lastInsertId();
payroll_calculate_run($calcRunId);
$draftReopen = payroll_reopen_run($calcRunId, $actorId, 'trying to reopen an editable run');
ok(!$draftReopen['ok'] && str_contains((string) $draftReopen['error'], 'already editable'), 'Reopen rejects a calculated run');

// A reconciled bank entry blocks the reopen (integrity guard).
$reconTested = false;
if (column_exists('voucher_entries', 'reconciled_at')) {
    db()->prepare('UPDATE voucher_entries SET reconciled_at = NOW() WHERE voucher_id = :v LIMIT 1')->execute(['v' => $paymentVid]);
    $blocked = payroll_reopen_run($runId, $actorId, 'attempting while a bank entry is reconciled');
    ok(!$blocked['ok'] && stripos((string) $blocked['error'], 'reconcil') !== false, 'Reopen blocked while a bank entry is reconciled');
    db()->prepare('UPDATE voucher_entries SET reconciled_at = NULL WHERE voucher_id = :v')->execute(['v' => $paymentVid]);
    $reconTested = true;
}
if (!$reconTested) { echo "  SKIP  reconciled-entry guard (no reconciled_at column)\n"; }

// A locked period blocks the reopen.
db()->prepare('INSERT INTO fiscal_period_locks (company_id, fiscal_year_id, locked_through, locked_by)
        VALUES (:cid, :fy, :through, :by)')
    ->execute(['cid' => $cid, 'fy' => $fyId, 'through' => '2026-07-31', 'by' => $actorId]);
$lockBlocked = payroll_reopen_run($runId, $actorId, 'attempting inside a locked period');
ok(!$lockBlocked['ok'] && stripos((string) $lockBlocked['error'], 'locked') !== false, 'Reopen blocked inside a locked period');
db()->exec("DELETE FROM fiscal_period_locks WHERE company_id = $cid");

// ---------------------------------------------------------------------------
// Reopen the paid run (happy path)
// ---------------------------------------------------------------------------
echo "\nReopen (paid -> calculated)\n";
$reopen = payroll_reopen_run($runId, $actorId, 'Overtime for E002 was missed; correcting before filing.');
ok($reopen['ok'], 'Reopen succeeded');
ok((int) $reopen['reversed_vouchers'] === 2, 'Reopen reversed 2 vouchers (accrual + payment)');
ok((string) payroll_run($runId)['status'] === 'calculated', 'Status back to calculated');
ok($voucherExists($accrualVid) === 0 && $entriesExist($accrualVid) === 0, 'Accrual voucher + entries deleted');
ok($voucherExists($paymentVid) === 0 && $entriesExist($paymentVid) === 0, 'Payment voucher + entries deleted');
$reopened = payroll_run($runId);
ok((int) ($reopened['accrual_voucher_id'] ?? 0) === 0 && (int) ($reopened['payment_voucher_id'] ?? 0) === 0
    && $reopened['approved_by'] === null && $reopened['paid_at'] === null, 'Run header voucher/approval/paid fields cleared');
ok(near($loanBalance($loanId), 20000), 'Advance recovery reversed (balance 15,000 -> 20,000)');
ok($loanStatus($loanId) === 'active', 'Loan status active after reversal');
ok($recoveryRows($runId) === 0, 'Recovery ledger rows deleted');

// ---------------------------------------------------------------------------
// Edit and re-post — no double recovery, keys reused cleanly
// ---------------------------------------------------------------------------
echo "\nEdit + re-post\n";
$lineBId = (int) db()->query("SELECT id FROM payroll_run_lines WHERE run_id = $runId AND payroll_employee_id = $empB")->fetchColumn();
$adj = payroll_set_line_adjustment($runId, $lineBId, 2000.0, 0.0, 'Missed overtime');
ok($adj['ok'], 'Salary line editable again after reopen');

$repost = payroll_approve_and_post($runId, $actorId + 1);
ok($repost['ok'], 'Re-approve & post succeeded (source-unique keys freed)');
$newAccrualVid = (int) ($repost['voucher_id'] ?? 0);
ok($newAccrualVid > 0 && $newAccrualVid !== $accrualVid && $voucherExists($newAccrualVid) === 1, 'Fresh accrual voucher posted');
$newVoucherNo = (string) db()->query("SELECT voucher_no FROM vouchers WHERE id = $newAccrualVid")->fetchColumn();
ok($newVoucherNo === 'PAY-PR Month 1-' . $runId, 'Re-post reused the same voucher number');
ok(near($loanBalance($loanId), 15000), 'Advance recovered exactly once on re-post (20,000 -> 15,000, not 10,000)');
ok($recoveryRows($runId) === 1, 'Exactly one recovery row after re-post (no duplicate)');
$reDr = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END),0) FROM voucher_entries WHERE voucher_id = $newAccrualVid")->fetchColumn();
$reCr = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END),0) FROM voucher_entries WHERE voucher_id = $newAccrualVid")->fetchColumn();
ok(near($reDr, $reCr) && $reDr > 0, 'Re-posted accrual voucher balances (Dr = Cr)');
ok(near($reDr, 85000 + 2000 + (55000 * 0.20)), 'Re-posted gross reflects the +2,000 adjustment');

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
pr_cleanup();

echo "\n----------------------------------------\n";
echo "PASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
