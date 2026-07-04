<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('portal.php');
}

verify_csrf();

$companyId = (int) ($_POST['company_id'] ?? 0);
$returnTo = trim((string) ($_POST['return_to'] ?? 'admin/index.php'));

// The new helper function centralizes all the logic.
// It handles validation, redirection, and session state changes.
$failureUrl = $returnTo !== '' ? $returnTo : 'portal.php';
handle_company_switch($companyId, $failureUrl);
