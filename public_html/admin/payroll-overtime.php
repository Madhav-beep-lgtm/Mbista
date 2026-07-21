<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/payroll_engine.php';

// Same audience as the payroll workspace: admins, staff with payroll grants,
// clients inside their own books (view only — approval needs the approve
// permission, which client logins do not carry).
require_staff_admin_or_client_books();
require_company_context();
require_permission('payroll', 'view');
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
    $backRun = (int) ($_POST['run_id'] ?? 0);
    $back = 'admin/payroll-overtime.php' . ($backRun > 0 ? '?run=' . $backRun : '');

    if ($action === 'sync_period') {
        require_permission('payroll', 'create');
        $periodStart = trim((string) ($_POST['period_start'] ?? ''));
        $periodEnd = trim((string) ($_POST['period_end'] ?? ''));
        if ($periodStart === '' || $periodEnd === '' || $periodEnd < $periodStart) {
            flash('error', 'Give a valid period range to scan.');
            redirect($back);
        }
        $weeks = payroll_ot_sync_period($companyId, $periodStart, $periodEnd);
        flash('success', 'Attendance scanned: ' . $weeks . ' week(s) crossed the ' . number_format((float) ($settings['ot_weekly_threshold'] ?? 40), 2) . '-hour threshold.');
        redirect($back);
    }

    if ($action === 'approve_week') {
        require_permission('payroll', 'approve');
        $weekId = (int) ($_POST['week_id'] ?? 0);
        $approvedRaw = trim((string) ($_POST['approved_amount'] ?? ''));
        $approved = $approvedRaw === '' ? null : (float) $approvedRaw;
        $reason = trim((string) ($_POST['adjust_reason'] ?? ''));
        $result = payroll_ot_approve_week($weekId, $approved, $reason, $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Overtime week approved at ' . $sym . number_format((float) $result['amount'], 2) . '. Recalculate the payroll run to pull it in.'
            : (string) ($result['error'] ?? 'Could not approve the week.'));
        redirect($back);
    }
}

// Context: pick a payroll run to review the weeks overlapping its period.
$runsStmt = db()->prepare('SELECT id, period_no, period_label, status FROM payroll_runs
    WHERE company_id = :cid AND fiscal_year_id = :fy AND status <> \'cancelled\' ORDER BY period_no DESC, id DESC LIMIT 30');
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
[$periodStart, $periodEnd] = $run
    ? payroll_period_range((int) $run['fiscal_year_id'], (int) $run['period_no'])
    : [date('Y-m-01'), date('Y-m-t')];

// Weeks overlapping the period (a week crossing two months shows in both).
$weeksStmt = db()->prepare("SELECT w.*, pe.employee_code,
        COALESCE(u.name, pe.full_name, CONCAT('Employee ', pe.employee_code)) AS person_name,
        (SELECT COUNT(*) FROM payroll_overtime_entries e
          WHERE e.payroll_employee_id = w.payroll_employee_id AND e.week_start = w.week_start AND e.run_id IS NOT NULL) AS consumed_days
    FROM payroll_overtime_weeks w
    INNER JOIN payroll_employees pe ON pe.id = w.payroll_employee_id
    LEFT JOIN users u ON u.id = pe.user_id
    WHERE w.company_id = :cid AND w.week_end >= :ps AND w.week_start <= :pe
    ORDER BY w.week_start ASC, pe.employee_code ASC");
$weeksStmt->execute(['cid' => $companyId, 'ps' => $periodStart, 'pe' => $periodEnd]);
$weeks = $weeksStmt->fetchAll();
$canApprove = user_can_do('payroll', 'approve');
$canSync = user_can_do('payroll', 'create');

$pageTitle = 'Overtime Review';
$pageSubtitle = 'Sunday-to-Saturday weekly overtime from attendance: hours over ' . number_format((float) ($settings['ot_weekly_threshold'] ?? 40), 2) . ' cumulative hours a week, dated on the day they were worked.';
$pageHero = ['icon' => 'attendance'];
$bodyClass = 'admin-layout accounting-module-page payroll-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card" aria-label="Overtime period">
    <div class="mbw-card-head">
        <h2>Review Period<?= $run ? ' — ' . e(payroll_bs_month_name((int) $run['period_no'])) . ' (' . e($periodStart) . ' → ' . e($periodEnd) . ')' : '' ?></h2>
        <div class="mbw-card-tools">
            <a class="mbw-view-all" href="<?= e(url('admin/payroll.php' . ($run ? '?run=' . (int) $run['id'] : ''))) ?>">Payroll Processing &#8594;</a>
            <a class="mbw-view-all" href="<?= e(url('admin/payroll-settings.php#otsc')) ?>">Overtime Settings &#8594;</a>
        </div>
    </div>
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
        <form method="get">
            <label>Payroll run
                <select name="run" onchange="this.form.submit()">
                    <?php if ($runs === []): ?><option value="">No runs yet</option><?php endif; ?>
                    <?php foreach ($runs as $runOption): ?>
                        <option value="<?= e((int) $runOption['id']) ?>" <?= $run && (int) $runOption['id'] === (int) $run['id'] ? 'selected' : '' ?>>
                            <?= e(payroll_bs_month_name((int) $runOption['period_no']) . ' — ' . ucfirst((string) $runOption['status'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <?php if ($canSync): ?>
            <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="sync_period">
                <input type="hidden" name="run_id" value="<?= e($run ? (int) $run['id'] : 0) ?>">
                <label>From<input type="date" name="period_start" value="<?= e($periodStart) ?>"></label>
                <label>To<input type="date" name="period_end" value="<?= e($periodEnd) ?>"></label>
                <button type="submit"><?= icon('reconcile') ?>Recalculate from attendance</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ((int) ($settings['ot_component_id'] ?? 0) <= 0): ?>
        <div class="notice error" style="margin-top:12px">No overtime pay component is mapped — approved overtime cannot flow into payroll.
            <a href="<?= e(url('admin/payroll-settings.php#otsc')) ?>">Map it in Payroll Settings</a>.</div>
    <?php endif; ?>
</section>

<section class="mbw-card" aria-label="Overtime weeks">
    <div class="mbw-card-head"><h2>Weeks over the threshold (<?= count($weeks) ?>)</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr>
            <th>Employee</th><th>Week (Sun–Sat)</th><th class="is-numeric">Total h</th><th class="is-numeric">Regular h</th>
            <th class="is-numeric">Overtime h</th><th class="is-numeric">Rate/h</th><th class="is-numeric">Calculated</th>
            <th class="is-numeric">Approved</th><th>Status</th><th></th>
        </tr></thead>
        <tbody>
            <?php if ($weeks === []): ?><tr><td colspan="10">No week in this period crossed the threshold. Working long on ONE day is not overtime — only cumulative weekly hours above <?= e(number_format((float) ($settings['ot_weekly_threshold'] ?? 40), 2)) ?> count.</td></tr><?php endif; ?>
            <?php foreach ($weeks as $week): ?>
                <?php $daily = json_decode((string) ($week['daily_json'] ?? ''), true) ?: []; ?>
                <tr>
                    <td><strong><?= e($week['employee_code']) ?></strong> <?= e($week['person_name']) ?></td>
                    <td>
                        <details>
                            <summary style="cursor:pointer"><?= e($week['week_start']) ?> → <?= e($week['week_end']) ?></summary>
                            <table style="margin-top:6px;font-size:12px">
                                <thead><tr><th>Date</th><th class="is-numeric">Worked</th><th class="is-numeric">Cumulative</th><th class="is-numeric">Regular</th><th class="is-numeric">OT</th></tr></thead>
                                <tbody>
                                    <?php foreach ($daily as $dayDate => $day): ?>
                                        <tr<?= ($dayDate >= $periodStart && $dayDate <= $periodEnd) ? '' : ' style="opacity:.55" title="Outside the selected payroll period — paid in its own month"' ?>>
                                            <td><?= e($dayDate) ?></td>
                                            <td class="is-numeric"><?= e(number_format((float) $day['hours'], 2)) ?></td>
                                            <td class="is-numeric"><?= e(number_format((float) ($day['cumulative'] ?? 0), 2)) ?></td>
                                            <td class="is-numeric"><?= e(number_format((float) $day['regular'], 2)) ?></td>
                                            <td class="is-numeric"><strong><?= e(number_format((float) $day['overtime'], 2)) ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    </td>
                    <td class="is-numeric"><?= e(number_format((float) $week['total_hours'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $week['regular_hours'], 2)) ?></td>
                    <td class="is-numeric"><strong><?= e(number_format((float) $week['overtime_hours'], 2)) ?></strong></td>
                    <td class="is-numeric"><?= e(number_format((float) $week['hourly_rate'], 2)) ?> ×<?= e(number_format((float) $week['multiplier'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $week['calculated_amount'], 2)) ?></td>
                    <td class="is-numeric"><?= $week['approved_amount'] !== null ? '<strong>' . e(number_format((float) $week['approved_amount'], 2)) . '</strong>' : '–' ?>
                        <?php if ((string) ($week['adjust_reason'] ?? '') !== ''): ?><br><small class="muted" style="color:var(--mbw-muted)"><?= e((string) $week['adjust_reason']) ?></small><?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $week['consumed_days'] > 0): ?>
                            <span class="mbw-pill tone-green">In payroll</span>
                        <?php elseif ((string) $week['status'] === 'approved'): ?>
                            <span class="mbw-pill tone-blue">Approved</span>
                        <?php else: ?>
                            <span class="mbw-pill tone-amber">Awaiting approval</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($canApprove && (int) $week['consumed_days'] === 0 && (string) $week['status'] !== 'approved'): ?>
                            <details class="pr-adjust" style="position:relative;display:inline-block">
                                <summary class="button secondary" style="list-style:none;cursor:pointer">Approve…</summary>
                                <form method="post" style="position:absolute;right:0;z-index:40;margin-top:6px;width:250px;display:grid;gap:8px;padding:12px;background:var(--mbw-card,#fff);border:1px solid var(--mbw-line,rgba(0,0,0,.16));border-radius:10px;box-shadow:0 14px 34px rgba(0,0,0,.22)">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="approve_week">
                                    <input type="hidden" name="run_id" value="<?= e($run ? (int) $run['id'] : 0) ?>">
                                    <input type="hidden" name="week_id" value="<?= e((int) $week['id']) ?>">
                                    <label style="display:grid;gap:3px;font-size:12px;font-weight:600">Approved amount (blank = calculated <?= e(number_format((float) $week['calculated_amount'], 2)) ?>)
                                        <input type="number" step="0.01" min="0" name="approved_amount"></label>
                                    <label style="display:grid;gap:3px;font-size:12px;font-weight:600">Reason (required if changed)
                                        <input type="text" name="adjust_reason" maxlength="255"></label>
                                    <button type="submit">Approve week</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12px">
        A week crossing two months is judged as a WHOLE week; each overtime hour is dated on the day it was worked and is paid by
        the payroll run covering that date — never twice. Greyed dates in a breakdown belong to the neighbouring month's run.
    </p>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
