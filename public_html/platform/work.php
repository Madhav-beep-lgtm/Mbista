<?php
declare(strict_types=1);

/**
 * The Work Portal, under the platform's own URL and inside its own shell.
 *
 * Clients and their tasks are the practice's work, not any one client's, so it
 * belongs beside Companies and Staff rather than inside whichever ledger
 * happened to be open.
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_admin();
define('PLATFORM_WORK_PAGE', true);
$platformReturnTo = 'platform/work.php';
require __DIR__ . '/_firm_context.php';

$platformShell = true;
require __DIR__ . '/../admin/workspace.php';
