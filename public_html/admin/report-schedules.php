<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/reports_engine.php';
require_once __DIR__ . '/../../app/mailer.php';

require_staff_or_admin();
require_company_context();
$repairErrors = accounting_module_repair_database();

$pageTitle = 'Report Schedules';
$pageSubtitle = 'Manage recurring report deliveries, recipients, and routing.';
$company = current_company();
$companyId = (int) ($company['id'] ?? 0);
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$reportRegistry = rc_report_registry();
$reportKey = (string) ($_GET['report_key'] ?? $_GET['report'] ?? 'trial-balance');
if (!isset($reportRegistry[$reportKey])) {
    $reportKey = array_key_first($reportRegistry) ?: 'trial-balance';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create_schedule') {
        require_permission('reports', 'export');
        $recipient = trim((string) ($_POST['recipient_email'] ?? ''));
        $frequency = (string) ($_POST['frequency'] ?? 'monthly');
        $format = (string) ($_POST['export_format'] ?? 'both');
        $selectedReportKey = (string) ($_POST['report_key'] ?? $reportKey);
        if (!isset($reportRegistry[$selectedReportKey])) {
            $selectedReportKey = $reportKey;
        }
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid recipient email address.');
        } elseif (!in_array($frequency, ['daily', 'weekly', 'monthly'], true) || !in_array($format, ['csv', 'html', 'both'], true)) {
            flash('error', 'Choose a valid frequency and format.');
        } else {
            $nextRun = match ($frequency) {
                'daily' => date('Y-m-d', strtotime('tomorrow')),
                'weekly' => date('Y-m-d', strtotime('+7 days')),
                default => date('Y-m-01', strtotime('first day of next month')),
            };
            $filters = json_encode(array_filter([
                'report_key' => $selectedReportKey,
                'fy' => $_POST['fy'] ?? null,
                'from' => trim((string) ($_POST['from'] ?? '')),
                'to' => trim((string) ($_POST['to'] ?? '')),
                'biz' => trim((string) ($_POST['biz'] ?? '')),
            ], static fn ($value): bool => $value !== null && $value !== ''), JSON_UNESCAPED_SLASHES);
            db()->prepare('
                INSERT INTO report_schedules (company_id, report_key, recipient_email, frequency, export_format, filters, next_run_on, created_by)
                VALUES (:company_id, :report_key, :recipient_email, :frequency, :export_format, :filters, :next_run_on, :created_by)
            ')->execute([
                'company_id' => $companyId,
                'report_key' => $selectedReportKey,
                'recipient_email' => $recipient,
                'frequency' => $frequency,
                'export_format' => $format,
                'filters' => $filters,
                'next_run_on' => $nextRun,
                'created_by' => $userId ?: null,
            ]);
            security_event('report_schedule_created', 'success', 'Report schedule created for ' . ($reportRegistry[$selectedReportKey][0] ?? $selectedReportKey) . ' (recipient: ' . $recipient . ').', $companyId, $userId);
            flash('success', 'Schedule created.');
        }
        redirect('admin/report-schedules.php?report_key=' . urlencode($selectedReportKey));
    }
    if ($action === 'delete_schedule') {
        require_permission('reports', 'export');
        $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
        if ($scheduleId > 0) {
            db()->prepare('DELETE FROM report_schedules WHERE id = :id AND company_id = :company_id')
                ->execute(['id' => $scheduleId, 'company_id' => $companyId]);
            security_event('report_schedule_deleted', 'success', 'Report schedule #' . $scheduleId . ' deleted.', $companyId, $userId);
            flash('success', 'Schedule removed.');
        }
        redirect('admin/report-schedules.php?report_key=' . urlencode($reportKey));
    }
}

$scheduleStmt = db()->prepare('
    SELECT *
    FROM report_schedules
    WHERE company_id = :company_id
      AND report_key = :report_key
    ORDER BY is_active DESC, next_run_on ASC, id DESC
');
$scheduleStmt->execute(['company_id' => $companyId, 'report_key' => $reportKey]);
$schedules = $scheduleStmt->fetchAll();

$bodyClass = 'admin-layout accounting-module-page reports-center-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<?php if ($repairErrors !== []): ?><div class="notice error">Accounting module repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div><?php endif; ?>

<section class="mbw-card">
    <div class="mbw-card-head">
        <h2>Report Library</h2>
        <div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('admin/reports-center.php')) ?>">Reports Center</a></div>
    </div>
    <div class="mbw-qa-grid" aria-label="Reports module switcher">
        <?php foreach ($reportRegistry as $key => [$label, $description, $iconName]): ?>
            <a class="mbw-qa" href="<?= e(url('admin/report-schedules.php?report_key=' . urlencode($key))) ?>" <?= $key === $reportKey ? 'style="border-color:var(--mbw-primary)" aria-current="page"' : '' ?>>
                <span class="mbw-chip is-square <?= $key === $reportKey ? 'tone-green' : 'tone-blue' ?>"><?= icon($iconName) ?></span>
                <div>
                    <strong><?= e($label) ?></strong>
                    <span><?= e($description) ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="mbw-card">
    <div class="mbw-card-head">
        <h2>Create Schedule — <?= e($reportRegistry[$reportKey][0]) ?></h2>
        <div class="mbw-card-tools"><span style="color:var(--mbw-muted);font-size:.85rem"><?= e($reportRegistry[$reportKey][1]) ?></span></div>
    </div>
    <?php if (!mail_is_configured()): ?>
        <p style="color:var(--mbw-muted);font-size:.85rem">SMTP is not configured. Delivery previews are stored locally until MAIL_* is set in <code>.env</code>.</p>
    <?php endif; ?>
    <form method="post" class="rc-schedule-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create_schedule">
        <label>Report
            <select name="report_key">
                <?php foreach ($reportRegistry as $key => [$label]) : ?>
                    <option value="<?= e($key) ?>" <?= $key === $reportKey ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Recipient Email<input type="email" name="recipient_email" placeholder="name@company.com" required></label>
        <div class="rc-compare-dates">
            <label>Frequency
                <select name="frequency">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly" selected>Monthly</option>
                </select>
            </label>
            <label>Format
                <select name="export_format">
                    <option value="both" selected>CSV + HTML</option>
                    <option value="csv">CSV only</option>
                    <option value="html">HTML only</option>
                </select>
            </label>
        </div>
        <button class="button secondary" type="submit"><?= icon('compliance') ?>Save Schedule</button>
    </form>
</section>

<section class="mbw-card">
    <div class="mbw-card-head">
        <h2>Saved Schedules</h2>
        <div class="mbw-card-tools"><span style="color:var(--mbw-muted);font-size:.85rem"><?= $schedules !== [] ? e((string) count($schedules)) . ' schedules' : 'No schedules yet' ?></span></div>
    </div>
    <?php if ($schedules === []): ?>
        <p style="color:var(--mbw-muted)">No schedules created for this report yet.</p>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Recipient</th>
                        <th>Frequency</th>
                        <th>Format</th>
                        <th>Next Run</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules as $schedule): ?>
                        <tr>
                            <td><strong style="color:var(--mbw-heading)"><?= e($schedule['recipient_email']) ?></strong>
                                <?php if (!empty($schedule['last_run_status'])): ?><br><small style="color:var(--mbw-muted)"><?= e($schedule['last_run_status']) ?></small><?php endif; ?>
                            </td>
                            <td><?= e(ucfirst((string) $schedule['frequency'])) ?></td>
                            <td><span class="mbw-pill tone-blue"><?= e(strtoupper((string) $schedule['export_format'])) ?></span></td>
                            <td><?= e(date('d M Y', strtotime((string) $schedule['next_run_on']))) ?></td>
                            <td><?= !empty($schedule['is_active']) ? '<span class="mbw-pill tone-green">Active</span>' : '<span class="mbw-pill tone-red">Inactive</span>' ?></td>
                            <td>
                                <form method="post" data-confirm="Delete this report schedule?">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_schedule">
                                    <input type="hidden" name="schedule_id" value="<?= e((int) $schedule['id']) ?>">
                                    <button type="submit" title="Remove schedule">&times;</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
