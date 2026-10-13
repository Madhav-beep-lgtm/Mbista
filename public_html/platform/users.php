<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_admin();
if (!user_is_super_admin()) {
    redirect('admin/index.php');
}

define('PLATFORM_USERS_PAGE', true);
$platformReturnTo = 'platform/users.php';
require __DIR__ . '/_firm_context.php';
$platformShell = true;
require __DIR__ . '/../admin/users.php';
