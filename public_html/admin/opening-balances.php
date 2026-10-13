<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_admin_or_client_books();
require_company_context();
require_permission('accounting', 'view');

// The manual opening-balance workspace is retired. Carry-forward remains
// calculated by the reporting engine; never process legacy mutation requests.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    http_response_code(410);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('The manual opening-balance workspace is no longer available.');
}

redirect('admin/ledgers.php');
