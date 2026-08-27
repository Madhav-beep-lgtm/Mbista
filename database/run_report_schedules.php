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
        // The hospitality pack is not one of the Reports Centre's reports -- it
        // is a workbook of several sections -- so it is built and sent on its
        // own path and never reaches the single-report code below.
        if ($reportKey === 'hospitality-pack') {
            require_once __DIR__ . '/../app/hospitality_engine.php';
            require_once __DIR__ . '/../app/hospitality_management_report.php';
            $company = company_by_id($companyId);
            $companyName = (string) ($company['name'] ?? 'Company');
            $packFilters = json_decode((string) ($schedule['filters'] ?? '{}'), true) ?: [];
            $packSections = (array) ($packFilters['sections'] ?? []);
            $pack = hospitality_pack_build($companyId, $from, $to, $packSections);
            $packBytes = hospitality_pack_xlsx($pack, [
                'company_name' => $companyName, 'from' => $from, 'to' => $to,
            ]);
            $packTitles = [];
            foreach ($pack as $packSection) {
                $packTitles[] = '<li>' . e((string) $packSection['title']) . '</li>';
            }
            $packInner = '<p>The management pack for ' . e($companyName) . ', ' . e($from) . ' to ' . e($to)
                . ', is attached as one workbook with a sheet for each section:</p><ul>'
                . implode('', $packTitles) . '</ul>'
                . '<p style="color:#64748b;font-size:12px;">Recipe costing is an estimate from configured recipes'
                . ' and reference ingredient prices. It is not posted cost of goods sold — the posted figures are'
                . ' on the Profit &amp; Loss sheet.</p>';
            $packBody = function_exists('branded_email_html')
                ? branded_email_html('Hospitality management pack', $packInner)
                : $packInner;
            $packResult = send_app_email((string) $schedule['recipient_email'],
                'Management pack · ' . $companyName . ' · ' . date('d M Y', strtotime($from)) . ' - ' . date('d M Y', strtotime($to)),
                $packBody,
                [[
                    'name' => 'management-pack-' . $from . '-to-' . $to . '.xlsx',
                    'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'content' => $packBytes,
                ]]);
            if (!empty($packResult['ok']) && ($packResult['transport'] ?? '') === 'log') {
                $status = 'Not sent: email is in log mode — configure SMTP in Settings > Notifications (written to storage/mail).';
                $advance = true;
            } elseif (!empty($packResult['ok'])) {
                $status = 'Sent ' . date('Y-m-d H:i') . ' via ' . $packResult['transport'];
                $advance = true;
            } else {
                $status = 'Failed: ' . ($packResult['error'] ?? 'unknown error');
                $advance = false;
            }
            echo "#{$scheduleId} {$reportKey} -> {$schedule['recipient_email']}: {$status}
";
            db()->prepare('UPDATE report_schedules SET last_run_at = NOW(), last_run_status = :status'
                    . ($advance ? ', next_run_on = :next_run' : '') . ' WHERE id = :id')
                ->execute($advance
                    ? ['status' => substr($status, 0, 255), 'next_run' => $nextRun, 'id' => $scheduleId]
                    : ['status' => substr($status, 0, 255), 'id' => $scheduleId]);
            continue;
        }
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
            // Temporary (income/expense) accounts reset at the fiscal-year
            // boundary, with prior years' P&L rolling into Retained Earnings b/f
            // (see rc_ledger_balances). The UI supplies this; without it an
            // emailed trial balance / balance sheet differs from the on-screen one.
            'fy_start' => (string) (fiscal_year_for_date($scopeCompanyId, $to)['start_date'] ?? ''),
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
        fputcsv($handle, [$reportLabel . ' — ' . $companyName . ' — ' . $from . ' to ' . $to], ',', '"', '\\');
        fputcsv($handle, array_map(static fn (array $col): string => ($col[2] !== '' ? $col[2] . ' ' : '') . $col[0], $report['columns']), ',', '"', '\\');
        foreach ($report['rows'] as $row) {
            fputcsv($handle, rc_row_cells($row), ',', '"', '\\');
        }
        if ($report['totals'] !== null) {
            fputcsv($handle, $report['totals'], ',', '"', '\\');
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
        // The emailed copy is the same report as the downloaded one, so it gets
        // the same workbook — letterhead, boxed table, bold headings and real
        // numbers — rather than a CSV somebody has to reformat before they can
        // forward it on.
        if ($format === 'xlsx') {
            require_once __DIR__ . '/../app/export_engine.php';
            $book = rc_workbook($report, [
                'report_label' => $reportLabel,
                'company_name' => $companyName,
                'from' => $from,
                'to' => $to,
                'fiscal_label' => (string) (fiscal_year_for_date($scopeCompanyId, $to)['label'] ?? ''),
                'branch' => 'Head Office',
                'currency_code' => rtrim(trim(site_currency_symbol()), '. ') ?: 'NPR',
                'generated_by' => 'Scheduled delivery',
            ]);
            $attachments[] = [
                'name' => $reportKey . '-' . $from . '-to-' . $to . '.xlsx',
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'content' => xlsx_build($book['rows'], $book['sheet'], $book['widths'], $book['options']),
            ];
        }
        $inner = match ($format) {
            'csv' => '<p>The scheduled report is attached as CSV.</p>' . $html,
            'xlsx' => '<p>The scheduled report is attached as an Excel workbook.</p>' . $html,
            default => $html,
        };
        $body = function_exists('branded_email_html') ? branded_email_html($reportLabel, $inner) : $inner;

        $subject = $reportLabel . ' · ' . $companyName . ' · ' . date('d M Y', strtotime($from)) . ' - ' . date('d M Y', strtotime($to));
        $result = send_app_email((string) $schedule['recipient_email'], $subject, $body, $attachments);
        if (!empty($result['ok']) && ($result['transport'] ?? '') === 'log') {
            // "log" transport means SMTP is not configured — it did NOT reach the
            // recipient. Report it honestly; advancing is fine (a config issue,
            // not transient) so the same period is not retried forever.
            $status = 'Not sent: email is in log mode — configure SMTP in Settings > Notifications (written to storage/mail).';
            $advance = true;
        } elseif (!empty($result['ok'])) {
            $status = 'Sent ' . date('Y-m-d H:i') . ' via ' . $result['transport'];
            $advance = true;
        } else {
            $status = 'Failed: ' . ($result['error'] ?? 'unknown error');
            $advance = false; // transient — keep next_run_on so the next cron retries
        }
        echo "#{$scheduleId} {$reportKey} -> {$schedule['recipient_email']}: {$status}\n";
    } catch (Throwable $exception) {
        $status = 'Failed: ' . substr($exception->getMessage(), 0, 200);
        $advance = false;
        fwrite(STDERR, "#{$scheduleId} error: {$exception->getMessage()}\n");
    }

    db()->prepare('UPDATE report_schedules SET last_run_at = NOW(), last_run_status = :status'
            . ($advance ? ', next_run_on = :next_run' : '') . ' WHERE id = :id')
        ->execute($advance
            ? ['status' => substr($status, 0, 255), 'next_run' => $nextRun, 'id' => $scheduleId]
            : ['status' => substr($status, 0, 255), 'id' => $scheduleId]);
}

echo "Done.\n";
