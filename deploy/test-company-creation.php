<?php
declare(strict_types=1);
$source = file_get_contents(__DIR__ . '/../public_html/admin/companies.php');
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
foreach (['company_bank_accounts','create_fiscal_year','save_bank_account','<table','Open portal','company-modules.php'] as $removed) check(!str_contains($source,$removed),'Creation page still includes ' . $removed);
foreach (['name','code','support_email','support_phone','office_address','parent_company_id'] as $field) check(str_contains($source,'name="' . $field . '"'),'Missing field ' . $field);
check(str_contains($source,'verify_csrf();'),'CSRF protection retained');
check(str_contains($source,'if (!user_is_super_admin()) deny_access('),'Super admin authority retained');
check(str_contains($source,"WHERE code = ? LIMIT 1"),'Duplicate code validation');
check(str_contains($source,'FILTER_VALIDATE_EMAIL'),'Email validation');
check(str_contains($source,'db()->beginTransaction();') && str_contains($source,'db()->rollBack();'),'Atomic company/details writes');
check(str_contains($source,"redirect('platform/index.php');"),'Returns to company directory');
check(str_contains($source,"'periodic'"),'Periodic inventory default retained');
check(!str_contains($source,'DELETE FROM'),'No existing data deleted');
$settings = file_get_contents(__DIR__ . '/../public_html/admin/company-settings.php');
check(str_contains($settings,'create_fiscal_year($settingsCompanyId'),'Fiscal years remain in company-specific settings');
echo "Company creation: creation-only layout, details, authorization, validation, atomic writes and retained fiscal-year setup passed.\n";
