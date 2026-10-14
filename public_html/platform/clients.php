<?php
declare(strict_types=1);

/** Client administration belongs to the platform, not to one company's books. */
require_once __DIR__ . '/../../app/bootstrap.php';
require_admin();

if (!user_is_super_admin()) deny_access('Super admin required.');
redirect('platform/company-management.php');
