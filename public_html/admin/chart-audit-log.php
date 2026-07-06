<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_or_admin();
require_company_context();

redirect('admin/audit-trail.php');
