<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/payroll_engine.php';

require_admin();
require_company_context();
accounting_module_repair_database();

$company = current_company();
$fiscalYear = current_fiscal_year();
if (!$company || !$fiscalYear) {
    flash('error', 'Company and fiscal year context required.');
    redirect('admin/accounting-dashboard.php');
}
$companyId = (int) $company['id'];
$fiscalYearId = (int) $fiscalYear['id'];
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$sym = site_currency_symbol();
$settings = payroll_settings($companyId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_run') {
        $periodNo = max(1, min(12, (int) ($_POST['period_no'] ?? 1)));
        // Periods are named by their Nepali BS month (Shrawan … Ashadh); the
        // label defaults to that month name so runs read the way users expect.
        $periodLabel = trim((string) ($_POST['period_label'] ?? ''));
        if ($periodLabel === '') {
            $periodLabel = payroll_bs_month_name($periodNo);
        }
        $payDate = (string) ($_POST['pay_date'] ?? date('Y-m-d'));
        $voucherDate = trim((string) ($_POST['voucher_date'] ?? ''));
        $voucherDate = ($voucherDate !== '') ? $voucherDate : null;
        // An explicit voucher date must sit inside the run's fiscal year — the
        // engine would clamp it anyway, but silently "fixing" a typo hides it.
        if ($voucherDate !== null && (($fiscalYear['start_date'] ?? '') !== '' && ($voucherDate < $fiscalYear['start_date'] || $voucherDate > $fiscalYear['end_date']))) {
            flash('error', 'Voucher date ' . $voucherDate . ' is outside fiscal year ' . ($fiscalYear['label'] ?? '') . ' (' . $fiscalYear['start_date'] . ' to ' . $fiscalYear['end_date'] . '). Leave it blank to use the pay date, or pick a date inside the year.');
            redirect('admin/payroll.php');
        }
        $dup = db()->prepare("SELECT id FROM payroll_runs WHERE company_id = :cid AND fiscal_year_id = :fy AND period_no = :p AND status <> 'cancelled' LIMIT 1");
        $dup->execute(['cid' => $companyId, 'fy' => $fiscalYearId, 'p' => $periodNo]);
        if ($dup->fetchColumn()) {
            flash('error', 'A payroll run for ' . payroll_bs_month_name($periodNo) . ' of this fiscal year already exists. Cancel it first to redo it.');
            redirect('admin/payroll.php');
        }
        db()->prepare('INSERT INTO payroll_runs (company_id, fiscal_year_id, period_no, period_label, pay_date, voucher_date, created_by)
                VALUES (:cid, :fy, :p, :label, :pay, :vdate, :by)')
            ->execute(['cid' => $companyId, 'fy' => $fiscalYearId, 'p' => $periodNo, 'label' => $periodLabel, 'pay' => $payDate, 'vdate' => $voucherDate, 'by' => $userId]);
        $newRunId = (int) db()->lastInsertId();
        log_activity('payroll_run', $newRunId, 'created', 'Payroll run ' . $periodLabel . ' created.', $userId);
        security_event('payroll_run_created', 'success', 'Payroll run #' . $newRunId . ' created.', $companyId, $userId);
        $calc = payroll_calculate_run($newRunId);
        flash($calc['ok'] ? 'success' : 'error', $calc['ok']
            ? 'Payroll run created and calculated for ' . $calc['employees'] . ' employees.'
            : 'Run created but not calculated: ' . ($calc['error'] ?? ''));
        redirect('admin/payroll.php?run=' . $newRunId);
    }

    $runId = (int) ($_POST['run_id'] ?? 0);
    $runRow = payroll_run($runId);
    if (!$runRow || (int) $runRow['company_id'] !== $companyId) {
        flash('error', 'Payroll run not found for this company.');
        redirect('admin/payroll.php');
    }

    if ($action === 'recalculate') {
        $calc = payroll_calculate_run($runId);
        flash($calc['ok'] ? 'success' : 'error', $calc['ok']
            ? 'Recalculated for ' . $calc['employees'] . ' employees.'
            : (string) ($calc['error'] ?? 'Could not calculate.'));
        redirect('admin/payroll.php?run=' . $runId);
    }

    if ($action === 'edit_line') {
        $lineId = (int) ($_POST['line_id'] ?? 0);
        $adjEarning = (float) ($_POST['adj_earning'] ?? 0);
        $adjDeduction = (float) ($_POST['adj_deduction'] ?? 0);
        $adjRemark = trim((string) ($_POST['adj_remark'] ?? ''));
        $result = payroll_set_line_adjustment($runId, $lineId, $adjEarning, $adjDeduction, $adjRemark);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Salary line updated — tax and net pay recalculated.'
            : (string) ($result['error'] ?? 'Could not update the salary line.'));
        redirect('admin/payroll.php?run=' . $runId);
    }

    if ($action === 'approve_post') {
        $result = payroll_approve_and_post($runId, $userId);
        if ($result['ok']) {
            log_activity('payroll_run', $runId, 'approved', 'Payroll approved' . (($result['voucher_id'] ?? 0) > 0 ? ' and posted (voucher #' . $result['voucher_id'] . ')' : '') . '.', $userId);
            security_event('payroll_run_posted', 'success', 'Payroll run #' . $runId . ' approved and posted.', $companyId, $userId);
            flash('success', 'Payroll approved.' . (($result['voucher_id'] ?? 0) > 0 ? ' Accrual voucher posted automatically.' : ' Auto-post is off — use “Post accrual to books” below to post the accrual.'));
        } else {
            flash('error', (string) $result['error']);
        }
        redirect('admin/payroll.php?run=' . $runId);
    }

    if ($action === 'post_accrual') {
        $result = payroll_post_accrual($runId, $userId);
        if ($result['ok']) {
            log_activity('payroll_run', $runId, 'accrual_posted', 'Accrual voucher #' . (int) $result['voucher_id'] . ' posted to the books.', $userId);
            security_event('payroll_run_posted', 'success', 'Payroll run #' . $runId . ' accrual posted (voucher #' . (int) $result['voucher_id'] . ').', $companyId, $userId);
            flash('success', 'Accrual voucher posted to the books.');
        } else {
            flash('error', (string) $result['error']);
        }
        redirect('admin/payroll.php?run=' . $runId);
    }

    if ($action === 'record_payment') {
        $result = payroll_record_payment($runId, $userId, (string) ($_POST['payment_date'] ?? ''));
        if ($result['ok']) {
            log_activity('payroll_run', $runId, 'paid', 'Salary payment voucher posted (' . number_format((float) $result['amount'], 2) . ').', $userId);
            security_event('payroll_payment_recorded', 'success', 'Payment recorded for payroll run #' . $runId . '.', $companyId, $userId);
            flash('success', 'Salary payment recorded: ' . $sym . number_format((float) $result['amount'], 2) . ' paid from bank.');
        } else {
            flash('error', (string) $result['error']);
        }
        redirect('admin/payroll.php?run=' . $runId);
    }

    if ($action === 'cancel_run') {
        if (!in_array((string) $runRow['status'], ['draft', 'calculated'], true)) {
            flash('error', 'Approved/posted runs cannot be cancelled - reopen the run for correction (which reverses its vouchers) or post a reversal voucher instead.');
        } else {
            db()->prepare("UPDATE payroll_runs SET status = 'cancelled' WHERE id = :id")->execute(['id' => $runId]);
            log_activity('payroll_run', $runId, 'cancelled', 'Payroll run cancelled before approval.', $userId);
            security_event('payroll_run_cancelled', 'success', 'Payroll run #' . $runId . ' cancelled.', $companyId, $userId);
            flash('success', 'Run cancelled.');
        }
        redirect('admin/payroll.php');
    }

    if ($action === 'delete_run') {
        // Delete a salary sheet outright. Only runs with NOTHING in the books:
        // draft/calculated (never approved) or cancelled — an approved/posted/
        // paid run must be reopened first, which reverses its vouchers and
        // loan recoveries and lands it back here as 'calculated'.
        if (!in_array((string) $runRow['status'], ['draft', 'calculated', 'cancelled'], true)) {
            flash('error', 'Only a draft, calculated or cancelled salary sheet can be deleted. Reopen a posted run for correction first — that reverses its vouchers, then it can be deleted.');
            redirect('admin/payroll.php?run=' . $runId);
        }
        if ((int) ($runRow['accrual_voucher_id'] ?? 0) > 0 || (int) ($runRow['payment_voucher_id'] ?? 0) > 0) {
            flash('error', 'This run still has posted vouchers — reopen it for correction first.');
            redirect('admin/payroll.php?run=' . $runId);
        }
        db()->prepare('DELETE FROM payroll_run_lines WHERE run_id = :id')->execute(['id' => $runId]);
        db()->prepare('DELETE FROM payroll_runs WHERE id = :id AND company_id = :cid')->execute(['id' => $runId, 'cid' => $companyId]);
        log_activity('payroll_run', $runId, 'deleted', 'Salary sheet ' . (string) $runRow['period_label'] . ' deleted (' . (string) $runRow['status'] . ').', $userId);
        security_event('payroll_run_deleted', 'warning', 'Payroll run #' . $runId . ' (' . (string) $runRow['period_label'] . ') deleted.', $companyId, $userId);
        flash('success', 'Salary sheet deleted.');
        redirect('admin/payroll.php');
    }

    if ($action === 'reopen_run') {
        $reason = trim((string) ($_POST['reopen_reason'] ?? ''));
        $result = payroll_reopen_run($runId, $userId, $reason);
        if ($result['ok']) {
            log_activity('payroll_run', $runId, 'reopened', 'Payroll run reopened for correction (' . (int) $result['reversed_vouchers'] . ' voucher(s) reversed): ' . $reason, $userId);
            security_event('payroll_run_reopened', 'warning', 'Payroll run #' . $runId . ' reopened for correction; ' . (int) $result['reversed_vouchers'] . ' voucher(s) reversed.', $companyId, $userId);
            $message = 'Run reopened for correction.'
                . ((int) $result['reversed_vouchers'] > 0 ? ' ' . (int) $result['reversed_vouchers'] . ' voucher(s) reversed and advance recoveries restored.' : '')
                . ' Edit the salary sheet, then Approve & Post again.';
            if (!empty($result['was_paid'])) {
                $message .= ' Because this run was already paid, Record Payment again after re-posting so the bank payout is booked once.';
            }
            if ((int) ($result['later_posted'] ?? 0) > 0) {
                $message .= ' Note: ' . (int) $result['later_posted'] . ' later posted run(s) this year used this run’s tax as their year-to-date base — recalculate and repost them after this correction.';
            }
            flash('success', $message);
        } else {
            flash('error', (string) $result['error']);
        }
        redirect('admin/payroll.php?run=' . $runId);
    }
}

// ---------------------------------------------------------------------------
// Page data.
// ---------------------------------------------------------------------------
// Scope the run list to the current (open/default) fiscal year — the one
// selected in the header — so runs from other years don't mix in.
$runsStmt = db()->prepare('SELECT r.*, fy.label AS fy_label FROM payroll_runs r
    INNER JOIN fiscal_years fy ON fy.id = r.fiscal_year_id
    WHERE r.company_id = :cid AND r.fiscal_year_id = :fy ORDER BY r.created_at DESC LIMIT 30');
$runsStmt->execute(['cid' => $companyId, 'fy' => $fiscalYearId]);
$runs = $runsStmt->fetchAll();

$selectedRunId = (int) ($_GET['run'] ?? 0);
$run = $selectedRunId > 0 ? payroll_run($selectedRunId) : null;
if ($run && (int) $run['company_id'] !== $companyId) {
    $run = null;
}
if (!$run && $runs !== []) {
    $run = payroll_run((int) $runs[0]['id']);
}
$lines = $run ? payroll_run_lines((int) $run['id']) : [];
$deptFilter = trim((string) ($_GET['dept'] ?? ''));
if ($deptFilter !== '') {
    $lines = array_values(array_filter($lines, static fn (array $l): bool => (string) $l['department'] === $deptFilter));
}
$departments = array_values(array_unique(array_filter(array_map(static fn (array $l): string => (string) ($l['department'] ?? ''), $run ? payroll_run_lines((int) $run['id']) : []))));

$taxVersion = payroll_active_tax_version($companyId, $fiscalYearId);
$validation = $run ? payroll_validate_run($run, $settings, $userId) : ['errors' => [], 'warnings' => []];
// Journal section. A run whose accrual IS posted shows the voucher's real
// entries straight from the books — the source of truth. (It must NOT be gated
// on $validation: payroll_validate_run checks approval READINESS, so it always
// "errors" with "Run must be calculated" on approved/posted/paid runs, which
// used to blank the journal on every posted run.) An unposted run computes a
// live preview, validated as-if calculated so ledger-mapping problems still
// hide a would-be-garbage preview without the status false-positive.
$journalPreview = [];
if ($run && (int) ($run['accrual_voucher_id'] ?? 0) > 0) {
    $journalStmt = db()->prepare("SELECT ledger_id, entry_type, amount, memo FROM voucher_entries
        WHERE voucher_id = :vid ORDER BY entry_type = 'credit', id");
    $journalStmt->execute(['vid' => (int) $run['accrual_voucher_id']]);
    $journalPreview = array_map(static fn (array $e): array => [
        'ledger_id' => (int) $e['ledger_id'], 'entry_type' => (string) $e['entry_type'],
        'amount' => (float) $e['amount'], 'memo' => (string) ($e['memo'] ?? ''),
    ], $journalStmt->fetchAll(PDO::FETCH_ASSOC));
} elseif ($run && in_array((string) $run['status'], ['calculated', 'approved', 'posted', 'paid'], true)) {
    $previewValidation = payroll_validate_run(array_merge($run, ['status' => 'calculated']), $settings, 0);
    if ($previewValidation['errors'] === []) {
        $journalPreview = payroll_accrual_entries($run, $settings);
    }
}
$ledgerNames = [];
if ($journalPreview !== []) {
    $ledgerIds = array_values(array_unique(array_map(static fn (array $entry): int => (int) $entry['ledger_id'], $journalPreview)));
    $namesStmt = db()->prepare('SELECT id, name FROM ledgers WHERE id IN (' . implode(',', array_fill(0, count($ledgerIds), '?')) . ')');
    $namesStmt->execute($ledgerIds);
    $ledgerNames = $namesStmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

$kpi = [
    'employees' => count($lines),
    'gross' => (float) ($run['total_gross'] ?? 0),
    'employer' => (float) ($run['total_employer_contrib'] ?? 0),
    'deductions' => (float) ($run['total_deductions'] ?? 0),
    'tax' => (float) ($run['total_tax'] ?? 0),
    'net' => (float) ($run['total_net'] ?? 0),
];
$statusFlow = ['draft' => 1, 'calculated' => 2, 'approved' => 3, 'posted' => 4, 'paid' => 5];
$runStage = $statusFlow[(string) ($run['status'] ?? 'draft')] ?? 0;

$pageTitle = 'Payroll Processing';
$pageSubtitle = 'Monthly payroll runs: calculate, review, approve with automatic voucher posting, and pay.';
$pageHero = ['icon' => 'wallet'];
$bodyClass = 'admin-layout accounting-module-page payroll-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>

<?php if (!$taxVersion): ?>
    <div class="notice error">No published income tax configuration for <?= e($fiscalYear['label'] ?? 'this fiscal year') ?>.
        <a href="<?= e(url('admin/payroll-settings.php#tax')) ?>">Set the tax slabs in Payroll Settings</a> before calculating payroll.</div>
<?php endif; ?>

<section class="mbw-card" aria-label="Payroll run selection">
    <div class="mbw-card-head">
        <h2>Payroll Run</h2>
        <div class="mbw-card-tools">
            <a class="mbw-view-all" href="<?= e(url('admin/payroll-employees.php')) ?>">Employees &#8594;</a>
            <a class="mbw-view-all" href="<?= e(url('admin/payroll-settings.php')) ?>">Settings &#8594;</a>
            <a class="mbw-view-all" href="<?= e(url('admin/reports-center.php?report=salary-sheet' . ($run ? '&payroll_run=' . (int) $run['id'] : ''))) ?>">Salary Sheet report &#8594;</a>
        </div>
    </div>
    <div class="pr-runbar">
        <form method="get" class="pr-runpick">
            <label>Run
                <select name="run" onchange="this.form.submit()">
                    <?php if ($runs === []): ?><option value="">No runs yet</option><?php endif; ?>
                    <?php foreach ($runs as $runOption): ?>
                        <option value="<?= e((int) $runOption['id']) ?>" <?= $run && (int) $runOption['id'] === (int) $run['id'] ? 'selected' : '' ?>>
                            <?= e(payroll_bs_month_name((int) $runOption['period_no']) . ' — ' . str_replace('_', ' ', ucfirst((string) $runOption['status'])) . ' (' . $runOption['fy_label'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($departments !== []): ?>
                <label>Department
                    <select name="dept" onchange="this.form.submit()">
                        <option value="">All departments</option>
                        <?php foreach ($departments as $departmentOption): ?>
                            <option value="<?= e($departmentOption) ?>" <?= $deptFilter === $departmentOption ? 'selected' : '' ?>><?= e($departmentOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
        </form>
        <form method="post" class="pr-newrun">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_run">
            <label>Period month<select name="period_no"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"><?= e(payroll_bs_month_name($m)) ?></option><?php endfor; ?></select></label>
            <label>Label <span class="pr-hint">(optional)</span><input type="text" name="period_label" placeholder="defaults to month name" maxlength="60"></label>
            <label>Pay date<input type="date" name="pay_date" value="<?= e(date('Y-m-d')) ?>"></label>
            <label>Voucher date <span class="pr-hint">(optional)</span><input type="date" name="voucher_date" title="Accounting date for the payroll postings. Leave blank to use the pay date."></label>
            <button type="submit"><?= icon('plus') ?>New Run + Calculate</button>
        </form>
    </div>
    <?php if ($run): ?>
        <div class="pr-flow" aria-label="Approval timeline">
            <?php foreach (['Draft', 'Calculated', 'Approved', 'Posted', 'Paid'] as $stageIndex => $stageLabel): ?>
                <span class="pr-flow-step<?= $runStage >= $stageIndex + 1 ? ' is-done' : '' ?><?= $runStage === $stageIndex + 1 ? ' is-current' : '' ?>">
                    <b><?= $stageIndex + 1 ?></b><?= e($stageLabel) ?>
                </span>
            <?php endforeach; ?>
            <?php if ((string) $run['status'] === 'cancelled'): ?><span class="mbw-pill tone-red">Cancelled</span><?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($run): ?>
<section class="mbw-kpi-grid pr-kpis" aria-label="Payroll totals">
    <?php foreach ([
        ['Employees', (string) $kpi['employees'], 'users', 'tone-blue'],
        ['Gross Earnings', $sym . number_format($kpi['gross'], 2), 'wallet', 'tone-green'],
        ['Employer Contribution', $sym . number_format($kpi['employer'], 2), 'bank', 'tone-blue'],
        ['Deductions', $sym . number_format($kpi['deductions'], 2), 'tag', 'tone-amber'],
        ['Income Tax (TDS)', $sym . number_format($kpi['tax'], 2), 'compliance', 'tone-amber'],
        ['Net Payable', $sym . number_format($kpi['net'], 2), 'card', 'tone-green'],
    ] as [$kpiLabel, $kpiValue, $kpiIcon, $kpiTone]): ?>
        <article class="mbw-kpi">
            <div>
                <span class="mbw-kpi-label"><?= e($kpiLabel) ?></span>
                <div class="mbw-kpi-value"><?= e($kpiValue) ?></div>
            </div>
            <span class="mbw-chip <?= e($kpiTone) ?>"><?= icon($kpiIcon) ?></span>
        </article>
    <?php endforeach; ?>
</section>

<?php if ($validation['errors'] !== [] && in_array((string) $run['status'], ['draft', 'calculated'], true)): ?>
    <div class="notice error"><strong>Blocking issues:</strong> <?= e(implode(' ', $validation['errors'])) ?></div>
<?php endif; ?>
<?php if ($validation['warnings'] !== []): ?>
    <div class="notice"><strong>Review warnings:</strong> <?= e(implode(' | ', array_slice($validation['warnings'], 0, 6))) ?><?= count($validation['warnings']) > 6 ? ' (+' . (count($validation['warnings']) - 6) . ' more)' : '' ?></div>
<?php endif; ?>

<div class="pr-workspace">
<section class="mbw-card pr-sheet" aria-label="Payroll worksheet">
    <div class="mbw-card-head">
        <?php $wsMonth = payroll_bs_month_name((int) $run['period_no']); $wsLabel = trim((string) $run['period_label']); ?>
        <h2>Worksheet — <?= e($wsMonth) ?><?php if ($wsLabel !== '' && $wsLabel !== $wsMonth): ?> <span class="pr-sub">(<?= e($wsLabel) ?>)</span><?php endif; ?></h2>
        <div class="mbw-card-tools">
            <?php if (in_array((string) $run['status'], ['draft', 'calculated'], true)): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="recalculate">
                    <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
                    <button type="submit" class="button secondary"><?= icon('reconcile') ?>Recalculate</button>
                </form>
                <form method="post" style="display:inline" data-confirm="Approve payroll <?= e($run['period_label']) ?> and auto-post the accrual voucher? Employee inputs lock after approval.">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="approve_post">
                    <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
                    <button type="submit" <?= $validation['errors'] !== [] ? 'disabled title="Resolve blocking issues first"' : '' ?>><?= icon('badge-check') ?>Approve &amp; Post</button>
                </form>
                <form method="post" style="display:inline" data-confirm="Cancel this run? It can be recreated for the same period afterwards.">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="cancel_run">
                    <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
                    <button type="submit" class="button secondary">Cancel Run</button>
                </form>
                <form method="post" style="display:inline" data-confirm="DELETE salary sheet <?= e($run['period_label']) ?> and all its calculated lines? This cannot be undone.">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_run">
                    <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
                    <button type="submit" class="button secondary" style="color:#a33">Delete Sheet</button>
                </form>
            <?php elseif ((string) $run['status'] === 'cancelled'): ?>
                <span class="mbw-pill tone-amber">Cancelled</span>
                <form method="post" style="display:inline" data-confirm="DELETE cancelled salary sheet <?= e($run['period_label']) ?> permanently?">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_run">
                    <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
                    <button type="submit" class="button secondary" style="color:#a33">Delete Sheet</button>
                </form>
            <?php elseif (in_array((string) $run['status'], ['approved', 'posted'], true)): ?>
                <form method="post" style="display:inline" data-confirm="Record the salary payment for <?= e($run['period_label']) ?>? This posts a bank payment voucher for the total net pay.">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="record_payment">
                    <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
                    <input type="date" name="payment_date" value="<?= e(date('Y-m-d')) ?>" style="min-height:36px">
                    <button type="submit"><?= icon('bank') ?>Record Payment</button>
                </form>
                <?php include __DIR__ . '/../../app/views/partials/_payroll_reopen.php'; ?>
            <?php elseif ((string) $run['status'] === 'paid'): ?>
                <span class="mbw-pill tone-green">Paid<?= $run['paid_at'] ? ' ' . e(date('d M Y', strtotime((string) $run['paid_at']))) : '' ?></span>
                <?php include __DIR__ . '/../../app/views/partials/_payroll_reopen.php'; ?>
            <?php endif; ?>
        </div>
    </div>
    <style>
    .pr-actions { white-space: nowrap; }
    .pr-adjust { position: relative; display: inline-block; }
    .pr-adjust > summary { list-style: none; cursor: pointer; }
    .pr-adjust > summary::-webkit-details-marker { display: none; }
    .pr-adjust-form {
        position: absolute; right: 0; z-index: 40; margin-top: 6px; width: 240px;
        display: grid; gap: 8px; padding: 12px; text-align: left;
        background: var(--mbw-surface, #ffffff); color: var(--mbw-ink, #12261f);
        border: 1px solid var(--mbw-line, rgba(0,0,0,.16)); border-radius: 10px;
        box-shadow: 0 14px 34px rgba(0,0,0,.22);
    }
    .pr-adjust-form label { display: grid; gap: 3px; font-size: 12px; font-weight: 600; color: var(--mbw-ink, #12261f); }
    .pr-adjust-form input { min-height: 34px; }
    .pr-adjust-form textarea { width: 100%; min-height: 60px; font: inherit; padding: 6px 8px;
        border: 1px solid var(--mbw-line, rgba(0,0,0,.16)); border-radius: 8px; color: var(--mbw-ink, #12261f); resize: vertical; }
    .pr-adjust-form small { color: var(--mbw-muted, #5b6b64); font-weight: 400; font-size: 11px; }
    .pr-reopen { margin-left: 4px; }
    .pr-reopen > summary { color: var(--mbw-ink, #12261f); }
    .pr-reopen .pr-adjust-form { width: 288px; }
    </style>
    <div style="overflow-x:auto">
    <table class="pr-table">
        <thead><tr>
            <th>Employee</th><th class="is-numeric">Basic</th><th class="is-numeric">Allowances</th><th class="is-numeric">Overtime</th>
            <th class="is-numeric">Benefits</th><th class="is-numeric">Gross</th><th class="is-numeric">Assessable (yr)</th>
            <th class="is-numeric">Retirement Ded. (yr)</th><th class="is-numeric">Taxable (yr)</th><th class="is-numeric">Tax (month)</th>
            <th class="is-numeric">Retirement (emp)</th><th class="is-numeric">Advance</th><th class="is-numeric">Other Ded.</th>
            <th class="is-numeric">Adjustment</th>
            <th class="is-numeric">Net Payable</th><th></th>
        </tr></thead>
        <tbody>
            <?php if ($lines === []): ?><tr><td colspan="16">No calculated lines. Enrol employees, then create or recalculate a run.</td></tr><?php endif; ?>
            <?php foreach ($lines as $line): ?>
                <tr class="pr-line<?= $line['line_status'] !== 'ok' ? ' pr-line-' . e($line['line_status']) : '' ?>" data-line-trace="<?= e((string) $line['trace']) ?>" data-line-name="<?= e($line['employee_code'] . ' — ' . $line['person_name']) ?>">
                    <td class="pr-emp"><strong><?= e($line['employee_code']) ?></strong> <?= e($line['person_name']) ?>
                        <?php if ((string) $line['department'] !== ''): ?><small><?= e($line['department']) ?></small><?php endif; ?>
                        <?php if ($line['line_status'] === 'warning'): ?><span class="mbw-pill tone-amber">Check</span><?php elseif ($line['line_status'] === 'error'): ?><span class="mbw-pill tone-red">Error</span><?php endif; ?>
                    </td>
                    <td class="is-numeric"><?= e(number_format((float) $line['basic'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $line['allowances'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $line['overtime'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $line['benefits'], 2)) ?></td>
                    <td class="is-numeric"><strong><?= e(number_format((float) $line['gross'], 2)) ?></strong></td>
                    <td class="is-numeric"><?= e(number_format((float) $line['assessable_annual'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $line['retirement_deduction_annual'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $line['taxable_annual'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $line['tax_month'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $line['retirement_employee_month'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $line['advance_deduction'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $line['other_deduction'], 2)) ?></td>
                    <?php $adjE = (float) ($line['adj_earning'] ?? 0); $adjD = (float) ($line['adj_deduction'] ?? 0); ?>
                    <td class="is-numeric">
                        <?php if ($adjE > 0 || $adjD > 0): ?>
                            <?php if ($adjE > 0): ?><span class="mbw-pill tone-green">+<?= e(number_format($adjE, 2)) ?></span><?php endif; ?>
                            <?php if ($adjD > 0): ?><span class="mbw-pill tone-amber">&minus;<?= e(number_format($adjD, 2)) ?></span><?php endif; ?>
                            <?php if ((string) ($line['adj_remark'] ?? '') !== ''): ?><small style="display:block"><?= e((string) $line['adj_remark']) ?></small><?php endif; ?>
                        <?php else: ?>&ndash;<?php endif; ?>
                    </td>
                    <td class="is-numeric"><strong><?= e(number_format((float) $line['net_pay'], 2)) ?></strong></td>
                    <td class="pr-actions" onclick="event.stopPropagation()">
                        <a class="button secondary" target="_blank" href="<?= e(url('admin/payroll-payslip.php?line=' . (int) $line['id'])) ?>" title="Open payslip">Payslip</a>
                        <?php if (in_array((string) $run['status'], ['draft', 'calculated'], true)): ?>
                        <details class="pr-adjust">
                            <summary class="button secondary">Adjust</summary>
                            <form method="post" class="pr-adjust-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="edit_line">
                                <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
                                <input type="hidden" name="line_id" value="<?= e((int) $line['id']) ?>">
                                <label>Extra earning (taxable)<input type="number" step="0.01" min="0" name="adj_earning" value="<?= e(number_format($adjE, 2, '.', '')) ?>"></label>
                                <label>Extra deduction<input type="number" step="0.01" min="0" name="adj_deduction" value="<?= e(number_format($adjD, 2, '.', '')) ?>"></label>
                                <label>Remark<input type="text" name="adj_remark" maxlength="255" value="<?= e((string) ($line['adj_remark'] ?? '')) ?>" placeholder="e.g. Dashain bonus"></label>
                                <button type="submit">Save &amp; recalculate</button>
                                <small>Set both to 0 to clear. Tax and net pay recompute; the value survives Recalculate.</small>
                            </form>
                        </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12px">Click a row to open its full calculation trace in the inspector. Amounts are monthly unless marked (yr).</p>
</section>

<aside class="mbw-card pr-inspector" aria-label="Calculation inspector" id="prInspector">
    <div class="mbw-card-head"><h2><?= icon('analytics') ?> Tax &amp; Pay Calculation</h2></div>
    <div id="prInspectorBody"><p style="color:var(--mbw-muted);font-size:12.5px">Select an employee row to see the annualized income, retirement limit applied, slab-wise tax and withholding method — including the exact tax configuration version used.</p></div>
</aside>
</div>

<section class="mbw-card" aria-label="Journal preview">
    <div class="mbw-card-head">
        <h2>Journal <?= $run['accrual_voucher_id'] ? '(posted — voucher #' . (int) $run['accrual_voucher_id'] . ')' : (in_array((string) $run['status'], ['posted', 'paid'], true) ? '(accrual NOT posted)' : 'Preview') ?></h2>
        <?php if ($run['payment_voucher_id']): ?><div class="mbw-card-tools"><span class="mbw-pill tone-green">Payment voucher #<?= (int) $run['payment_voucher_id'] ?></span></div><?php endif; ?>
    </div>
    <?php if (in_array((string) $run['status'], ['approved', 'posted', 'paid'], true) && !$run['accrual_voucher_id']): ?>
        <div class="notice error" style="margin:0 0 12px">
            <strong>The salary accrual is not posted to the books.</strong> This run was approved while auto-post was off, so the salary expense, TDS and payables were never journalised (which is why it doesn't appear correctly in the ledger / salary sheet). Post it now:
            <form method="post" style="display:block;margin-top:8px" data-confirm="Post the salary accrual voucher for <?= e($run['period_label']) ?> (Dr salary expense &amp; employer cost / Cr TDS, retirement and net salary payable)?">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="post_accrual">
                <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
                <button type="submit"><?= icon('badge-check') ?>Post accrual to books</button>
            </form>
        </div>
    <?php endif; ?>
    <?php if ($journalPreview === []): ?>
        <p style="color:var(--mbw-muted);font-size:12.5px;margin:0">Calculate the run and resolve blocking issues to preview the accrual voucher.</p>
    <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Ledger</th><th>Memo</th><th class="is-numeric">Debit (<?= e($sym) ?>)</th><th class="is-numeric">Credit (<?= e($sym) ?>)</th></tr></thead>
            <tbody>
                <?php $drTotal = 0.0; $crTotal = 0.0; ?>
                <?php foreach ($journalPreview as $entry): ?>
                    <?php if ($entry['entry_type'] === 'debit') { $drTotal += $entry['amount']; } else { $crTotal += $entry['amount']; } ?>
                    <tr>
                        <td><?= e((string) ($ledgerNames[(int) $entry['ledger_id']] ?? ('Ledger #' . $entry['ledger_id']))) ?></td>
                        <td><?= e((string) $entry['memo']) ?></td>
                        <td class="is-numeric"><?= $entry['entry_type'] === 'debit' ? e(number_format($entry['amount'], 2)) : '–' ?></td>
                        <td class="is-numeric"><?= $entry['entry_type'] === 'credit' ? e(number_format($entry['amount'], 2)) : '–' ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="font-weight:700"><td colspan="2">Total (balanced)</td><td class="is-numeric"><?= e(number_format($drTotal, 2)) ?></td><td class="is-numeric"><?= e(number_format($crTotal, 2)) ?></td></tr>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<script>
(function () {
    var body = document.getElementById('prInspectorBody');
    var fmt = function (n) { return Number(n).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
    document.querySelectorAll('.pr-line').forEach(function (row) {
        row.addEventListener('click', function (event) {
            if (event.target.closest('a, button, form')) { return; }
            var trace;
            try { trace = JSON.parse(row.getAttribute('data-line-trace') || '{}'); } catch (err) { return; }
            document.querySelectorAll('.pr-line.is-selected').forEach(function (r) { r.classList.remove('is-selected'); });
            row.classList.add('is-selected');
            var html = '<div class="pr-ins-name">' + (row.getAttribute('data-line-name') || '') + '</div>';
            html += '<div class="pr-ins-row"><span>Category</span><b>' + (trace.category || '-') + (trace.first_slab_exempt ? ' (SSF: first slab exempt)' : '') + '</b></div>';
            var components = trace.components || [];
            html += '<div class="pr-ins-sub">Monthly components</div>';
            components.forEach(function (c) {
                html += '<div class="pr-ins-row"><span>' + c.label + (c.taxable ? '' : ' (non-taxable)') + '</span><b>' + fmt(c.amount) + '</b></div>';
            });
            html += '<div class="pr-ins-sub">Annualization</div><div class="pr-ins-note">' + (trace.annualization || '') + '</div>';
            if (trace.retirement && trace.retirement.scheme !== 'none') {
                html += '<div class="pr-ins-sub">Retirement deduction (' + trace.retirement.scheme.toUpperCase() + ')</div>';
                html += '<div class="pr-ins-row"><span>Annual contribution</span><b>' + fmt(trace.retirement.annual_contribution) + '</b></div>';
                html += '<div class="pr-ins-row"><span>Limit (' + '% of assessable)</span><b>' + fmt(trace.retirement.limit_pct_amount) + '</b></div>';
                html += '<div class="pr-ins-row"><span>Absolute cap</span><b>' + fmt(trace.retirement.limit_cap) + '</b></div>';
                html += '<div class="pr-ins-row"><span>Allowed deduction</span><b>' + fmt(trace.retirement.allowed) + '</b></div>';
            }
            html += '<div class="pr-ins-sub">Slab-wise annual tax</div>';
            (trace.slabs || []).forEach(function (s) {
                html += '<div class="pr-ins-row"><span>' + s.band + ' @ ' + s.rate + '%</span><b>' + fmt(s.tax) + '</b></div>';
            });
            if (trace.withholding) {
                html += '<div class="pr-ins-sub">Withholding</div>';
                html += '<div class="pr-ins-row"><span>Method</span><b>' + trace.withholding.method + '</b></div>';
                html += '<div class="pr-ins-row"><span>Period / months left</span><b>' + trace.withholding.period_no + ' / ' + trace.withholding.months_remaining + '</b></div>';
                html += '<div class="pr-ins-row"><span>Tax withheld YTD</span><b>' + fmt(trace.withholding.ytd_withheld) + '</b></div>';
            }
            (trace.loans || []).forEach(function (loan) {
                html += '<div class="pr-ins-row"><span>Advance: ' + loan.title + '</span><b>' + fmt(loan.amount) + '</b></div>';
            });
            html += '<div class="pr-ins-note">Rule version: ' + (trace.tax_version_label || '-') + ' (#' + (trace.tax_version_id || '-') + ')</div>';
            body.innerHTML = html;
        });
    });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
