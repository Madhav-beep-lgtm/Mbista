<?php
declare(strict_types=1);
$settings = [];
$superAdmin = true;
function current_company_id(): int { return 11; }
function setting($key, $default = '', $reload = false, $companyId = null) { global $settings; return $settings[$companyId][$key] ?? $default; }
function user_is_super_admin(): bool { global $superAdmin; return $superAdmin; }
function jewellery_legacy_enabled_for_company(int $companyId): bool { return $companyId === 12; }
function hospitality_legacy_enabled_for_company(int $companyId): bool { return false; }
function update_settings(array $values, int $companyId = 0): void { global $settings; $settings[$companyId] = array_replace($settings[$companyId] ?? [], $values); }
require __DIR__ . '/../app/company_modules.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
check(count(company_optional_modules()) === 12, 'Optional modules include hospitality and documents');
check(!company_module_enabled('jewellery',11) && company_module_enabled('jewellery',12),'Legacy jewellery access preserved before configuration');
company_modules_save(11,['jewellery']);
check(company_module_enabled('jewellery',11),'Super admin enables jewellery for company');
check(company_route_module('jewellery-trade.php') === 'jewellery','Jewellery route protected');
check(company_report_module('jewellery-aml-register') === 'jewellery','Jewellery report protected');
$settings = [];
check(company_module_enabled('inventory', 11), 'Existing companies stay enabled');
company_modules_save(11, ['payroll','hr']);
check(!company_module_enabled('inventory',11), 'Disabled company inventory');
check(company_module_enabled('inventory',12), 'Other company unaffected');
check(company_module_enabled('payroll',11), 'Payroll independently enabled');
check(!company_module_enabled('jewellery',11),'Disabling package also disables jewellery');
check(company_module_enabled('jewellery',12),'Other company jewellery access unaffected');
check(company_module_enabled('accounting',11), 'Core accounting unaffected');
try { require_company_module('inventory',11); throw new LogicException('Expected denial'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(),'disabled'),'Disabled module rejected'); }
$superAdmin = false;
try { company_modules_save(11,['inventory']); throw new LogicException('Expected denial'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(),'super admin'),'Authority enforced'); }
foreach (['items.php','inventory-purchases.php','stock-transfer-view.php','manufacturing-orders.php','production-voucher-view.php'] as $route) check(company_route_module($route) === 'inventory', $route);
check(company_route_module('voucher-form.php',['type'=>'production']) === 'inventory','Production voucher');
check(company_route_module('voucher-form.php',['type'=>'journal']) === null,'Core vouchers');
check(company_route_module('fixed-assets.php',['view'=>'leases']) === 'nfrs','Independent leases');
check(company_route_module('fixed-assets.php',['view'=>'leases','action'=>'create_asset']) === 'fixed_assets','Mixed action cannot bypass asset gate');
foreach (['salary-sheet','payroll-posting','consolidated-tax-report','overtime-report'] as $key) check(company_report_module($key) === 'payroll',$key);
check(company_route_module('reports-center.php',['report'=>'stock-ledger']) === 'inventory','Reports protected');
echo "Company module isolation, authority and route checks passed.\n";
$tenantSettings = file_get_contents(__DIR__ . '/../public_html/admin/company-settings.php');
check(!str_contains($tenantSettings, 'company_modules_save('), 'Tenant settings cannot save module limits');
check(!str_contains($tenantSettings, 'name="modules[]"'), 'Tenant settings contain no module controls');
$platformModules = file_get_contents(__DIR__ . '/../public_html/platform/company-modules.php');
check(str_contains($platformModules, 'if (!user_is_super_admin()) deny_access('), 'Platform module route requires super admin');
check(str_contains($platformModules, 'verify_csrf();'), 'Platform module changes require CSRF');
check(!str_contains($platformModules, 'set_context(') && !str_contains($platformModules, 'handle_company_switch('), 'Module management never switches tenant context');
$directory = file_get_contents(__DIR__ . '/../public_html/platform/index.php');
check(str_contains($directory, 'platform/company-modules.php?company_id='), 'Company directory exposes module management');
echo "Platform-only module controls and tenant separation checks passed.\n";
