<?php
declare(strict_types=1);

function company_optional_modules(): array
{
    return ['budgets'=>'Budgets', 'inventory'=>'Inventory & Manufacturing', 'fixed_assets'=>'Fixed Assets',
        'nfrs'=>'NFRS Compliance', 'messages'=>'Messages', 'payroll'=>'Payroll', 'hr'=>'HR & Attendance',
        'tickets'=>'Tickets', 'compliance'=>'Compliance', 'jewellery'=>'Jewellery'];
}

function company_module_enabled(string $module, ?int $companyId = null): bool
{
    if (!isset(company_optional_modules()[$module])) return true;
    $companyId ??= current_company_id();
    if ($module === 'jewellery') {
        if ($companyId <= 0) return false;
        $configured = (string)setting('company_module_jewellery', '', false, $companyId);
        if ($configured !== '') return $configured === '1';
        if (!function_exists('jewellery_legacy_enabled_for_company')) require_once __DIR__ . '/jewellery_engine.php';
        return jewellery_legacy_enabled_for_company($companyId);
    }
    // Preserve existing installations until the super admin configures a company.
    return $companyId <= 0 || (string)setting('company_module_' . $module, '1', false, $companyId) === '1';
}

function require_company_module(string $module, ?int $companyId = null): void
{
    if (!company_module_enabled($module, $companyId)) throw new RuntimeException(company_optional_modules()[$module] . ' is disabled for this company.');
}

function company_report_module(string $report): ?string
{
    if (str_starts_with($report, 'jewellery-')) return 'jewellery';
    if (in_array($report, ['fixed-asset-register','depreciation-schedule','asset-gl-reconciliation'], true)) return 'fixed_assets';
    if (in_array($report, ['salary-sheet','consolidated-salary-sheet','employee-ytd-statement','consolidated-tax-report','payroll-components','overtime-report','service-charge-report','payroll-posting'], true)) return 'payroll';
    if (in_array($report, ['inventory-summary','stock-ledger','stock-movement','stock-valuation','manufacturing-statement','manufacturing-cost','manufacturing-wip','production-variance','nrv-report','inventory-gl-reconciliation'], true)) return 'inventory';
    return null;
}

function company_route_module(string $script, array $query = []): ?string
{
    $script = basename($script);
    if (str_starts_with($script, 'jewellery')) return 'jewellery';
    if ($script === 'fixed-assets.php') {
        if (!empty($query['action'])) return in_array($query['action'], ['create_lease','post_lease_period','modify_lease'], true) ? 'nfrs' : 'fixed_assets';
        return ($query['view'] ?? '') === 'leases' ? 'nfrs' : 'fixed_assets';
    }
    if ($script === 'voucher-form.php' && in_array($query['type'] ?? '', ['production','stock_transfer'], true)) return 'inventory';
    if ($script === 'reports-center.php' || $script === 'report-schedules.php') return company_report_module((string)($query['report'] ?? $query['report_key'] ?? ''));
    if (str_starts_with($script, 'payroll')) return 'payroll';
    if (str_starts_with($script, 'inventory-') || str_starts_with($script, 'manufacturing-') || str_starts_with($script, 'stock-') || in_array($script,['items.php','item-profit.php','accounting-inventory.php','production-voucher-view.php'],true)) return 'inventory';
    return ['budgets.php'=>'budgets','messages.php'=>'messages','tickets.php'=>'tickets','hr.php'=>'hr','compliance.php'=>'compliance'][$script] ?? null;
}

function company_modules_enforce_request(): void
{
    if (PHP_SAPI === 'cli' || str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/platform/')) return;
    foreach ([$_GET, $_POST + $_GET] as $query) {
        $module = company_route_module((string)($_SERVER['SCRIPT_NAME'] ?? ''), $query);
        if ($module && !company_module_enabled($module)) deny_access(company_optional_modules()[$module] . ' is disabled for this company.');
    }
}

function company_can_manage_settings(): bool
{
    $user = current_user();
    if (!$user || !user_can_access_company(current_company_id())) return false;
    if (($user['role'] ?? '') === 'admin') return true;
    if (($user['role'] ?? '') !== 'customer' || !client_portal_in_own_books($user)) return false;
    $profile = client_profile_for_user((int)$user['id']);
    return ($profile['portal_member_role'] ?? '') === 'owner';
}

function company_modules_save(int $companyId, array $enabled): void
{
    if (!user_is_super_admin() || $companyId <= 0) throw new RuntimeException('Only the super admin can configure company modules.');
    $settings = [];
    foreach (company_optional_modules() as $key=>$label) $settings['company_module_' . $key] = in_array($key,$enabled,true) ? '1' : '0';
    update_settings($settings, $companyId);
}
