<?php
declare(strict_types=1);

/** Zero exempts platform staff; false rejects the submitted tenant identity. */
function login_company_target(array $user, string $code): int|false
{
    if (user_is_super_admin($user) || ($user['role'] ?? '') === 'staff') return 0;
    $code = trim($code);
    if ($code === '' || strlen($code) > 30) return false;
    $company = company_by_code($code);
    if (!$company || (int)($company['is_active'] ?? 0) !== 1) return false;
    $companyId = (int)$company['id'];
    if (($user['role'] ?? '') === 'customer') {
        $profile = client_profile_for_user((int)$user['id']);
        if (!$profile || empty($profile['is_active']) || (int)($profile['books_company_id'] ?? 0) !== $companyId) return false;
    }
    return user_can_access_company($companyId, $user) ? $companyId : false;
}

/** Called only after password and any second factor have succeeded. */
function finish_login_company_context(array $user): void
{
    $companyId = (int)($_SESSION['login_company_id'] ?? 0);
    unset($_SESSION['login_company_id']);
    if ($companyId <= 0) return;
    $company = company_by_id($companyId);
    if (!$company || login_company_target($user, (string)$company['code']) !== $companyId) {
        unset($_SESSION['user_id']);
        clear_selected_company();
        clear_company_pin_verification();
        unset($_SESSION['company_id'], $_SESSION['fiscal_year_id']);
        flash('error', 'Company access is no longer available. Please sign in again.');
        redirect('login.php');
    }
    if (($user['role'] ?? '') === 'customer') {
        activate_company_context($companyId);
        return;
    }
    // Company admins still pass the existing company-opening authorization checks.
    handle_company_switch($companyId, 'portal.php');
}
