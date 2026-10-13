<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_staff_admin_or_client_books();
require_company_context();
// Compatibility route only: legacy forms and mutation handlers are retired.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('This form has been replaced. Reopen the account from Chart of Accounts.');
}
$id = max(0, (int) ($_GET['edit_id'] ?? $_GET['edit_id'] ?? 0));
redirect('admin/create-account.php?' . ($id > 0 ? 'edit_kind=ledger&edit_id=' . $id : 'level=ledger'));
