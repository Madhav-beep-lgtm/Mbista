<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

// Staff switch too: handle_company_switch() already authorizes per role —
// membership/hierarchy via user_can_access_company(), and client-books
// companies additionally via client_books_access_level(), which grants staff
// exactly the clients assigned/granted to them ('approval' level: every
// voucher they post there is forced to pending approval). require_admin()
// here was the one wall that made granted staff accountants dead-end with
// "Admin access required" — the model below it was already staff-aware.
require_staff_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('portal.php');
}

verify_csrf();

$companyId = (int) ($_POST['company_id'] ?? 0);
$returnTo = trim((string) ($_POST['return_to'] ?? 'admin/index.php'));

// A staff switch is ONLY for entering a client's books (the assigned/granted
// accounting workspace). The firm portal and the PIN flow stay admin-only —
// without this, the MBAACA/PIN branches below would let a staff switch mark
// firm company context verified.
if ((string) (current_user()['role'] ?? '') === 'staff') {
    $switchTarget = company_by_id($companyId);
    if (!$switchTarget || (int) ($switchTarget['is_client_company'] ?? 0) !== 1) {
        flash('error', 'Staff can open client books only. Ask an admin for anything beyond your assigned clients.');
        redirect('portal.php');
    }
}

// The new helper function centralizes all the logic.
// It handles validation, redirection, and session state changes.
$failureUrl = $returnTo !== '' ? $returnTo : 'portal.php';
handle_company_switch($companyId, $failureUrl);
