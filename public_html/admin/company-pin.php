<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();

$companyId = (int) ($_GET['company_id'] ?? selected_company_id());
handle_company_switch($companyId, user_is_super_admin() ? 'platform/index.php' : 'portal.php');
