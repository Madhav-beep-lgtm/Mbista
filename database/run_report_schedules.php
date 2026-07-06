<?php
declare(strict_types=1);

/**
 * Delivers due report schedules. Run from cPanel Cron Jobs, e.g. daily at 07:00:
 *   0 7 * * * /usr/local/bin/php /home/USERNAME/database/run_report_schedules.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/reports_engine.php';
require_once __DIR__ . '/../app/mailer.php';

if (!table_exists('report_schedules')) {
    fwrite(STDERR, "report_schedules table missing - run migration 024 first.\n");
    exit(1);
}

$registry = rc_report_registry();
$today = date('Y-m-d');

$dueStmt = db()->prepare('SELECT * FROM report_schedules WHERE is_active = 1 AND next_run_on <= :today ORDER BY id ASC');
$dueStmt->execute(['today' => $today]);
$due = $dueStmt->fetchAll();
echo count($due) . " schedule(s) due on {$today}.\n";

foreach ($due as $schedule) {
    $scheduleId = (int) $schedule['id'];
    $reportKey = (string) $schedule['report_key'];
    $companyId = (int) $schedule['company_id'];
    $frequency = (string) $schedule['frequency'];

    [$from, $to] = match ($frequency) {
        'daily' => [date('Y-m-d', strtotime('yesterday')), date('Y-m-d', strtotime('yesterday'))],
        'weekly' => [date('Y-m-d', strtotime('-7 days')), date('Y-m-d', strtotime('yesterday'))],
        default => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    };

    $nextRun = match ($frequency) {
        'daily' => date('Y-m-d', strtotime($today . ' +1 day')),
        'weekly' => date('Y-m-d', strtotime($today . ' +7 days')),
        default => date('Y-m-01', strtotime('first day of next month')),
    };

    $status = '';
    try {
        if (!isset($registry[$reportKey])) {
            throw new RuntimeException('Unknown report key: ' . $reportKey);
        }
        [$reportLabel] = $registry[$reportKey];
        $company = company_by_id($companyId);
        $companyName = (string) ($company['name'] ?? 'Company');

        $filters = json_decode((string) ($schedule['filters'] ?? '{}'), true) ?: [];
        $scopeCompanyId = (int) ($filters['scope_company'] ?? $companyId);
        $ctx = [
            'currency' => site_currency_symbol(),
            'vtype' => (string) ($filters['vtype'] ?? ''),
            'group_id' => (int) ($filters['group_id'] ?? 0),
            'ledger_id' => (int) ($filters['ledger_id'] ?? 0),
            'item_id' => (int) ($filters['item_id'] ?? 0),
            'biz' => (string) ($filters['biz'] ?? 'all'),
            'company_id' => $scopeCompanyId,
            'company_name' => $companyName,
            'subsidiaries' => array_map(
                static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
                child_companies_for_company($scopeCompanyId)
            ),
        ];

        $report = rc_generate($reportKey, $scopeCompanyId, $from, $to, $ctx);

        $csv = '';
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [$reportLabel . ' — ' . $companyName . ' — ' . $from . ' to ' . $to]);
        fputcsv($handle, array_map(static fn (array $col): string => ($col[2] !== '' ? $col[2] . ' ' : '') . $col[0], $report['columns']));
        foreach ($report['rows'] as $row) {
            fputcsv($handle, rc_row_cells($row));
        }
        if ($report['totals'] !== null) {
            fputcsv($handle, $report['totals']);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        ob_start();
        rc_render_table($report, rc_has_group_columns($report));
        $tableHtml = (string) ob_get_clean();
        $html = '<div style="font-family:Georgia,serif;color:#1c2434;">'
            . '<h2 style="margin:0 0 4px;">' . e($reportLabel) . '</h2>'
            . '<p style="margin:0 0 14px;color:#64748b;font-size:13px;">' . e($companyName) . ' · ' . e(date('d M Y', strtotime($from))) . ' to ' . e(date('d M Y', strtotime($to))) . '</p>'
            . '<style>.rc-table{border-collapse:collapse;width:100%;font-size:13px}.rc-table th,.rc-table td{border:1px solid #d7dfeb;padding:6px 9px}.rc-table thead th{background:#f4f7fc;font-size:11px;text-transform:uppercase}.align-right{text-align:right}.rc-total-row td{font-weight:bold;background:#f8fafc}</style>'
            . $tableHtml
            . '<p style="color:#64748b;font-size:12px;">Automated delivery from ' . e(app_name()) . '. Manage schedules in Admin → Reports.</p>'
            . '</div>';

        $format = (string) $schedule['export_format'];
        $attachments = [];
        if ($format === 'csv' || $format === 'both') {
            $attachments[] = ['name' => $reportKey . '-' . $from . '-to-' . $to . '.csv', 'mime' => 'text/csv', 'content' => $csv];
        }
        $body = ($format === 'csv') ? '<p>The scheduled report is attached as CSV.</p>' . $html : $html;

        $subject = $reportLabel . ' · ' . $companyName . ' · ' . date('d M Y', strtotime($from)) . ' - ' . date('d M Y', strtotime($to));
        $result = send_app_email((string) $schedule['recipient_email'], $subject, $body, $attachments);
        $status = $result['ok']
            ? 'Sent ' . date('Y-m-d H:i') . ' via ' . $result['transport']
            : 'Failed: ' . ($result['error'] ?? 'unknown error');
        echo "#{$scheduleId} {$reportKey} -> {$schedule['recipient_email']}: {$status}\n";
    } catch (Throwable $exception) {
        $status = 'Failed: ' . substr($exception->getMessage(), 0, 200);
        fwrite(STDERR, "#{$scheduleId} error: {$exception->getMessage()}\n");
    }

    db()->prepare('UPDATE report_schedules SET last_run_at = NOW(), last_run_status = :status, next_run_on = :next_run WHERE id = :id')
        ->execute(['status' => substr($status, 0, 255), 'next_run' => $nextRun, 'id' => $scheduleId]);
}

echo "Done.\n";
