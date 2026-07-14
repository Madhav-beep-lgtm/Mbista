<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

require_login();

// Customer-only door into the shared accounting workspace: activates the
// session company context for the login's OWN books company (and nothing
// else), marking it PIN-verified the same way admin client-books access does.
$user = current_user();
if (($user['role'] ?? '') !== 'customer') {
    redirect(role_home_path($user));
}

$profile = client_profile_for_user((int) $user['id']);
$booksCompanyId = (int) ($profile['books_company_id'] ?? 0);

if (!$profile || empty($profile['is_active']) || $booksCompanyId <= 0) {
    flash('error', 'Your organization has no accounting books yet. Ask your accountant to enable client accounting.');
    redirect('dashboard.php');
}

if (!user_can_access_company($booksCompanyId)) {
    deny_access('Client login attempted to open books company #' . $booksCompanyId . ' outside its scope.', $booksCompanyId);
}

if (!activate_company_context($booksCompanyId, true)) {
    flash('error', 'No active fiscal year is available for your books. Ask your accountant to set one up.');
    redirect('dashboard.php');
}

security_event('company_switch', 'success', 'Client opened own accounting books (company #' . $booksCompanyId . ').', $booksCompanyId, (int) $user['id']);
flash('success', 'Opened accounting for ' . ($profile['organization_name'] ?? 'your organization') . '.');
redirect('admin/accounting-dashboard.php');
