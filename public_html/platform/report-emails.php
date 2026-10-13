<?php
declare(strict_types=1);

/**
 * Report Emails, under the platform's own URL and inside its own shell.
 *
 * Scheduled reports belong to the company that sends them, so this runs in the
 * firm's own context. A client's schedules are reached from inside that
 * client's books, which is where they are kept.
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_admin();
$platformReturnTo = 'platform/report-emails.php';
require __DIR__ . '/_firm_context.php';

$platformShell = true;
require __DIR__ . '/../admin/report-schedules.php';
