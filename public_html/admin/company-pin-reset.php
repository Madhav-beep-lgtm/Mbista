<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();
redirect(user_is_super_admin() ? 'platform/index.php' : 'portal.php');
