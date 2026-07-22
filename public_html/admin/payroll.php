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
        // Optional employee scope: a run for a single staff member (or subset).
        // Chosen ids are validated against the roster, stored on the run, and
        // guarded so nobody is paid twice for the same period.
        $scopeIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['employee_ids'] ?? [])), static fn (int $i): bool => $i > 0)));
        if ($scopeIds !== []) {
            $own = db()->prepare('SELECT id FROM payroll_employees WHERE company_id = ? AND id IN (' . implode(',', array_fill(0, count($scopeIds), '?')) . ')');
            $own->execute(array_merge([$companyId], $scopeIds));
            $scopeIds = array_map('intval', $own->fetchAll(PDO::FETCH_COLUMN));
        }
        // Period-overlap guard: a full-company run occupies the whole period;
        // scoped runs may coexist as long as their employee sets don't overlap.
        $periodRuns = db()->prepare("SELECT id, period_label, employee_scope FROM payroll_runs
            WHERE company_id = :cid AND fiscal_year_id = :fy AND period_no = :p AND status <> 'cancelled'");
        $periodRuns->execute(['cid' => $companyId, 'fy' => $fiscalYearId, 'p' => $periodNo]);
        $occupied = [];
        foreach ($periodRuns->fetchAll(PDO::FETCH_ASSOC) as $existingRun) {
            $existingScope = payroll_run_scope_ids((array) $existingRun);
            if ($existingScope === []) {
                flash('error', 'A full-company payroll run (' . $existingRun['period_label'] . ') already exists for ' . payroll_bs_month_name($periodNo) . '. Cancel or reopen it first.');
                redirect('admin/payroll.php');
            }
            $occupied = array_merge($occupied, $existingScope);
        }
        if ($occupied !== [] && $scopeIds === []) {
            flash('error', 'Single-staff run(s) already exist for ' . payroll_bs_month_name($periodNo) . '. A full-company run would pay those employees twice — select only the remaining employees.');
            redirect('admin/payroll.php');
        }
        $overlap = array_intersect($scopeIds, $occupied);
        if ($overlap !== []) {
            $codes = db()->prepare('SELECT employee_code FROM payroll_employees WHERE id IN (' . implode(',', array_fill(0, count($overlap), '?')) . ')');
            $codes->execute(array_values($overlap));
            flash('error', 'Already on another run for ' . payroll_bs_month_name($periodNo) . ': ' . implode(', ', $codes->fetchAll(PDO::FETCH_COLUMN)) . '. Remove them from the selection.');
            redirect('admin/payroll.php');
        }
        db()->prepare('INSERT INTO payroll_runs (company_id, fiscal_year_id, period_no, period_label, pay_date, voucher_date, employee_scope, created_by)
                VALUES (:cid, :fy, :p, :label, :pay, :vdate, :scope, :by)')
            ->execute(['cid' => $companyId, 'fy' => $fiscalYearId, 'p' => $periodNo, 'label' => $periodLabel, 'pay' => $payDate, 'vdate' => $voucherDate,
                'scope' => $scopeIds !== [] ? json_encode(array_values($scopeIds)) : null, 'by' => $userId]);
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

    if ($action === 'edit_component') {
        $componentId = (int) ($_POST['component_id'] ?? 0);
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $reason = trim((string) ($_POST['override_reason'] ?? ''));
        $remove = (string) ($_POST['remove'] ?? '') === '1';
        $oneTime = (string) ($_POST['one_time'] ?? '') === '1';
        $result = payroll_set_run_component($runId, $employeeId, $componentId, $amount, $reason, $userId, $oneTime, $remove);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ($remove ? 'Component removed for this month — line recalculated.' : 'Component amount saved — tax and net pay recalculated.')
            : (string) ($result['error'] ?? 'Could not update the component.'));
        redirect('admin/payroll.php?run=' . $runId . '#components');
    }

    if ($action === 'edit_tax_override') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $clear = (string) ($_POST['clear'] ?? '') === '1';
        $amountRaw = trim((string) ($_POST['approved_tax'] ?? ''));
        $result = payroll_set_tax_override(
            $runId,
            $employeeId,
            $clear || $amountRaw === '' ? null : (float) $amountRaw,
            trim((string) ($_POST['override_reason'] ?? '')),
            $userId
        );
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ($clear ? 'Tax override cleared — system calculation restored.' : 'Tax override applied — the system figure stays visible in the breakdown.')
            : (string) ($result['error'] ?? 'Could not update the tax override.'));
        redirect('admin/payroll.php?run=' . $runId);
    }

    if ($action === 'sync_overtime') {
        [$periodStart, $periodEnd] = payroll_period_range((int) $runRow['fiscal_year_id'], (int) $runRow['period_no']);
        if ($periodStart === '') {
            flash('error', 'Could not resolve the run period dates.');
        } else {
            $weeks = payroll_ot_sync_period($companyId, $periodStart, $periodEnd);
            $calc = payroll_calculate_run($runId);
            flash($calc['ok'] ? 'success' : 'error', $calc['ok']
                ? 'Attendance scanned: ' . $weeks . ' week(s) with overtime. Approve them in Overtime Review, then Recalculate to pull the amounts in.'
                : (string) ($calc['error'] ?? ''));
        }
        redirect('admin/payroll.php?run=' . $runId);
    }

    if ($action === 'cancel_run') {
        if (!in_array((string) $runRow['status'], ['draft', 'calculated'], true)) {
            flash('error', 'Approved/posted runs cannot be cancelled - reopen the run for correction (which reverses its vouchers) or post a reversal voucher instead.');
        } else {
            payroll_ot_release_run($runId); // freed hours can be paid by another run
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
        payroll_ot_release_run($runId); // freed hours can be paid by another run
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

$rosterForRun = payroll_company_employees($companyId);
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

// Per-employee component lines of this run (the dynamic matrix) + the master
// catalog for one-time additions.
$runComponentsByEmployee = [];
$matrixCodes = [];
if ($run && table_exists('payroll_run_components')) {
    foreach (payroll_run_component_rows((int) $run['id']) as $componentRow) {
        $runComponentsByEmployee[(int) $componentRow['payroll_employee_id']][] = $componentRow;
        $matrixCodes[(string) $componentRow['component_code']] = (string) $componentRow['component_name'];
    }
    ksort($matrixCodes);
}
$componentCatalog = payroll_component_catalog($companyId);
$componentIdByCode = [];
foreach ($componentCatalog as $catalogRow) {
    $componentIdByCode[(string) $catalogRow['code']] = (int) $catalogRow['id'];
}
$scSummary = $run ? payroll_sc_get((int) $run['id']) : null;

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
            <a class="mbw-view-all" href="<?= e(url('admin/payroll-overtime.php' . ($run ? '?run=' . (int) $run['id'] : ''))) ?>">Overtime Review &#8594;</a>
            <a class="mbw-view-all" href="<?= e(url('admin/payroll-service-charge.php' . ($run ? '?run=' . (int) $run['id'] : ''))) ?>">Service Charge &#8594;</a>
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
            <details class="pr-adjust">
                <summary class="button secondary" style="min-height:38px;display:inline-flex;align-items:center">Employees: all</summary>
                <div class="pr-adjust-form" style="width:280px;max-height:280px;overflow:auto">
                    <small>Tick employees for a single-staff / subset run. Leave all unticked to run the whole company. Employees already on another run of the same month are refused.</small>
                    <?php foreach ($rosterForRun as $rosterEmp): ?>
                        <label style="display:flex;gap:8px;align-items:center;font-weight:500">
                            <input type="checkbox" name="employee_ids[]" value="<?= (int) $rosterEmp['id'] ?>" onchange="var d=this.closest('details'),n=d.querySelectorAll('input:checked').length;d.querySelector('summary').textContent='Employees: '+(n===0?'all':n+' selected');">
                            <?= e($rosterEmp['employee_code'] . ' — ' . ($rosterEmp['person_name'] ?? $rosterEmp['name'] ?? '')) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </details>
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
        <?php $wsMonth = payroll_bs_month_name((int) $run['period_no']); $wsLabel = trim((string) $run['period_label']); $wsScope = payroll_run_scope_ids($run); ?>
        <h2>Worksheet — <?= e($wsMonth) ?><?php if ($wsLabel !== '' && $wsLabel !== $wsMonth): ?> <span class="pr-sub">(<?= e($wsLabel) ?>)</span><?php endif; ?>
            <?php if ($wsScope !== []): ?><span class="mbw-pill tone-blue" title="This run pays only the selected employees"><?= count($wsScope) ?> selected employee<?= count($wsScope) === 1 ? '' : 's' ?></span><?php endif; ?></h2>
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
                        <?php $lineComponents = $runComponentsByEmployee[(int) $line['payroll_employee_id']] ?? []; ?>
                        <?php if (in_array((string) $run['status'], ['draft', 'calculated'], true)): ?>
                        <details class="pr-adjust">
                            <summary class="button secondary">Components<?= $lineComponents !== [] ? ' (' . count($lineComponents) . ')' : '' ?></summary>
                            <div class="pr-adjust-form" style="width:330px;max-height:420px;overflow:auto">
                                <?php foreach ($lineComponents as $componentRow): ?>
                                    <?php $isOverridden = (string) ($componentRow['override_reason'] ?? '') !== '' || (int) ($componentRow['updated_by'] ?? 0) > 0; ?>
                                    <form method="post" style="display:grid;gap:4px;border-bottom:1px dashed var(--mbw-line,rgba(0,0,0,.12));padding-bottom:8px;margin:0">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="edit_component">
                                        <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
                                        <input type="hidden" name="employee_id" value="<?= e((int) $line['payroll_employee_id']) ?>">
                                        <input type="hidden" name="component_id" value="<?= e((int) ($componentIdByCode[(string) $componentRow['component_code']] ?? (int) $componentRow['component_id'])) ?>">
                                        <label style="display:flex;justify-content:space-between;align-items:center;gap:8px;font-weight:600">
                                            <span><?= e($componentRow['component_name']) ?>
                                                <?php if ((string) $componentRow['source'] !== 'standard'): ?><span class="mbw-pill tone-blue"><?= e(str_replace('_', ' ', (string) $componentRow['source'])) ?></span><?php endif; ?>
                                                <?php if ($isOverridden): ?><span class="mbw-pill tone-amber" title="<?= e((string) ($componentRow['override_reason'] ?? '')) ?>">edited</span><?php endif; ?>
                                            </span>
                                            <small><?= $componentRow['suggested_amount'] !== null ? 'suggested ' . e(number_format((float) $componentRow['suggested_amount'], 2)) : 'no suggestion' ?></small>
                                        </label>
                                        <div style="display:flex;gap:6px;align-items:center">
                                            <input type="number" step="0.01" min="0" name="amount" value="<?= e(number_format((float) $componentRow['amount'], 2, '.', '')) ?>" style="max-width:110px" <?= in_array((string) $componentRow['source'], ['overtime', 'service_charge'], true) ? 'readonly title="Managed by its own workflow"' : '' ?>>
                                            <input type="text" name="override_reason" maxlength="255" placeholder="Reason if changed" value="<?= e((string) ($componentRow['override_reason'] ?? '')) ?>" style="flex:1">
                                            <?php if (!in_array((string) $componentRow['source'], ['overtime', 'service_charge'], true)): ?>
                                                <button type="submit" class="button secondary" style="min-height:32px;padding:4px 8px">Save</button>
                                                <button type="submit" name="remove" value="1" class="button secondary" style="min-height:32px;padding:4px 8px;color:#a33" title="Remove for this month">&times;</button>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                <?php endforeach; ?>
                                <?php if ($componentCatalog !== []): ?>
                                    <form method="post" style="display:grid;gap:4px;margin:0">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="edit_component">
                                        <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
                                        <input type="hidden" name="employee_id" value="<?= e((int) $line['payroll_employee_id']) ?>">
                                        <input type="hidden" name="one_time" value="1">
                                        <label style="font-weight:600">Add one-time component for this month</label>
                                        <div style="display:flex;gap:6px;align-items:center">
                                            <select name="component_id" style="flex:1;min-height:32px">
                                                <?php foreach ($componentCatalog as $catalogRow): ?>
                                                    <?php if (in_array((string) $catalogRow['calc_type'], ['overtime_hours', 'service_charge'], true)) { continue; } ?>
                                                    <option value="<?= (int) $catalogRow['id'] ?>"><?= e($catalogRow['code'] . ' — ' . $catalogRow['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="number" step="0.01" min="0" name="amount" placeholder="Amount" style="max-width:100px" required>
                                        </div>
                                        <input type="text" name="override_reason" maxlength="255" placeholder="Reason (required)" required>
                                        <button type="submit" class="button secondary" style="min-height:32px">Add for this month</button>
                                    </form>
                                <?php endif; ?>
                                <small>Zero keeps the row visible without pay or posting. Overtime and service-charge lines change in their own workflows.</small>
                            </div>
                        </details>
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
                        <details class="pr-adjust">
                            <summary class="button secondary">Tax<?= ($line['tax_override'] ?? null) !== null ? ' *' : '' ?></summary>
                            <form method="post" class="pr-adjust-form" style="width:260px">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="edit_tax_override">
                                <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
                                <input type="hidden" name="employee_id" value="<?= e((int) $line['payroll_employee_id']) ?>">
                                <label>System-calculated tax
                                    <input type="text" value="<?= e(number_format((float) ($line['tax_override'] !== null ? (json_decode((string) $line['trace'], true)['withholding']['system_tax'] ?? $line['tax_month']) : $line['tax_month']), 2)) ?>" readonly></label>
                                <label>Approved (override) tax<input type="number" step="0.01" min="0" name="approved_tax" value="<?= ($line['tax_override'] ?? null) !== null ? e(number_format((float) $line['tax_override'], 2, '.', '')) : '' ?>"></label>
                                <label>Reason (required)<input type="text" name="override_reason" maxlength="255" value="<?= e((string) ($line['tax_override_reason'] ?? '')) ?>"></label>
                                <button type="submit">Apply override</button>
                                <?php if (($line['tax_override'] ?? null) !== null): ?>
                                    <button type="submit" name="clear" value="1" class="button secondary">Clear — restore system tax</button>
                                <?php endif; ?>
                                <small>The system figure stays stored and visible; the override is audited.</small>
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

<section class="mbw-card" aria-label="Component matrix" id="components">
    <div class="mbw-card-head">
        <h2>Component Matrix — who gets what this month</h2>
        <div class="mbw-card-tools">
            <?php if (in_array((string) $run['status'], ['draft', 'calculated'], true)): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="sync_overtime">
                    <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
                    <button type="submit" class="button secondary" title="Scan attendance for Sunday-Saturday weeks over the threshold"><?= icon('reconcile') ?>Scan attendance for overtime</button>
                </form>
            <?php endif; ?>
            <?php if ($scSummary): ?>
                <span class="mbw-pill <?= (string) $scSummary['status'] === 'approved' ? 'tone-green' : 'tone-amber' ?>">
                    Service charge <?= e((string) $scSummary['status']) ?>: <?= e($sym . number_format((float) $scSummary['employee_pool'], 2)) ?> to employees
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($matrixCodes === []): ?>
        <p style="color:var(--mbw-muted);font-size:12.5px;margin:0">No component lines yet — assign components to employees (Employees &rarr; pay structure), then Recalculate. Use the per-row "Components" button above to add one-time items.</p>
    <?php else: ?>
        <div style="overflow-x:auto">
        <table class="pr-table">
            <thead><tr>
                <th>Employee</th>
                <?php foreach ($matrixCodes as $matrixCode => $matrixName): ?>
                    <th class="is-numeric" title="<?= e($matrixName) ?>"><?= e($matrixCode) ?></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
                <?php foreach ($lines as $line): ?>
                    <?php
                    $cellByCode = [];
                    foreach ($runComponentsByEmployee[(int) $line['payroll_employee_id']] ?? [] as $componentRow) {
                        $cellByCode[(string) $componentRow['component_code']] = $componentRow;
                    }
                    ?>
                    <tr>
                        <td><strong><?= e($line['employee_code']) ?></strong> <?= e($line['person_name']) ?></td>
                        <?php foreach ($matrixCodes as $matrixCode => $matrixName): ?>
                            <?php $cell = $cellByCode[$matrixCode] ?? null; ?>
                            <td class="is-numeric">
                                <?php if ($cell === null): ?>
                                    <span style="color:var(--mbw-muted)">–</span>
                                <?php else: ?>
                                    <?php $cellOverridden = (string) ($cell['override_reason'] ?? '') !== '' || (int) ($cell['updated_by'] ?? 0) > 0; ?>
                                    <span<?= $cellOverridden ? ' class="mbw-pill tone-amber" title="' . e('Suggested ' . number_format((float) ($cell['suggested_amount'] ?? 0), 2) . ($cell['override_reason'] ? ' — ' . $cell['override_reason'] : '')) . '"' : '' ?>>
                                        <?= e(number_format((float) $cell['amount'], 2)) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12px">Amber cells were edited for this month (hover shows the suggested value and reason). Edit amounts via each row's "Components" button in the worksheet above.</p>
    <?php endif; ?>
</section>

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
            var proj = trace.projection;
            if (proj) {
                html += '<div class="pr-ins-sub">Projected annual tax (employment periods ' + proj.start_period + '–' + proj.end_period + ')</div>';
                html += '<div class="pr-ins-row"><span>Actual regular income to date</span><b>' + fmt(proj.actual_regular_to_date) + '</b></div>';
                html += '<div class="pr-ins-row"><span>Actual irregular income to date</span><b>' + fmt(proj.actual_irregular_to_date) + '</b></div>';
                html += '<div class="pr-ins-row"><span>Current period — regular</span><b>' + fmt(proj.current_regular) + '</b></div>';
                html += '<div class="pr-ins-row"><span>Current period — irregular (earned only)</span><b>' + fmt(proj.current_irregular) + '</b></div>';
                html += '<div class="pr-ins-row"><span>Projected future REGULAR income</span><b>' + fmt(proj.projected_future_regular) + '</b></div>';
                if (proj.manual_projected > 0) {
                    html += '<div class="pr-ins-row"><span>Approved manual projections</span><b>' + fmt(proj.manual_projected) + '</b></div>';
                }
                if (proj.prior_employment_income > 0) {
                    html += '<div class="pr-ins-row"><span>Prior employment income (approved)</span><b>' + fmt(proj.prior_employment_income) + '</b></div>';
                }
                html += '<div class="pr-ins-row"><span>Estimated annual taxable (assessable)</span><b>' + fmt(proj.estimated_annual_taxable) + '</b></div>';
                if (proj.projected_periods && proj.projected_periods.length) {
                    html += '<details style="margin:4px 0"><summary style="cursor:pointer;font-size:12px">Period-by-period projection (' + proj.projected_periods.length + ' future months)</summary>';
                    proj.projected_periods.forEach(function (pp) {
                        html += '<div class="pr-ins-row"><span>Period ' + pp.period + ' (basic ' + fmt(pp.basic) + ')</span><b>' + fmt(pp.assessable) + '</b></div>';
                    });
                    html += '</details>';
                }
                html += '<div class="pr-ins-row"><span>Remaining periods (incl. current)</span><b>' + proj.remaining_periods + '</b></div>';
                html += '<div class="pr-ins-row"><span>Remaining tax to withhold</span><b>' + fmt(proj.remaining_tax) + '</b></div>';
                if (proj.excess_tax > 0) {
                    html += '<div class="pr-ins-row" style="color:#c0392b"><span>EXCESS tax already deducted</span><b>' + fmt(proj.excess_tax) + '</b></div>';
                    html += '<div class="pr-ins-note">Treatment: ' + (proj.excess_treatment || 'offset') + ' — see Payroll Settings.</div>';
                }
                if (trace.withholding && trace.withholding.tax_override !== null && trace.withholding.tax_override !== undefined) {
                    html += '<div class="pr-ins-row" style="color:#b9770e"><span>Approved override (system ' + fmt(trace.withholding.system_tax) + ')</span><b>' + fmt(trace.withholding.tax_override) + '</b></div>';
                }
            }
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
