<?php
declare(strict_types=1);

/** Client administration belongs to the platform, not to one company's books. */
require_once __DIR__ . '/../../app/bootstrap.php';
require_admin();

$platformReturnTo = 'platform/clients.php';
require __DIR__ . '/_firm_context.php';

$platformShell = true;
require __DIR__ . '/../admin/manage-clients.php';
