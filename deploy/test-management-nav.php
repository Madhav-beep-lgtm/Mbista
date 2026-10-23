<?php
declare(strict_types=1);
function current_company_id(): int { return 1; }
function company_accounting_business_type(int $id): string { return 'test'; }
function accounting_business_profile(string $type): array { return $GLOBALS['profile']; }
function company_module_enabled(string $module): bool { return $GLOBALS['modules'][$module] ?? false; }
function icon(string $name): string { return ''; }
function url(string $path): string { return $path; }
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
foreach ([false,true] as $inventory) {
    foreach ([false,true] as $assets) {
        foreach ([false,true] as $profileInventory) {
            $modules=['inventory'=>$inventory,'fixed_assets'=>$assets,'nfrs'=>true];
            $profile=['show_inventory'=>$profileInventory,'show_manufacturing'=>$profileInventory];
            $headerScript='dashboard.php'; $headerView='';
            ob_start();
            include __DIR__.'/../app/views/partials/books_management_nav.php';
            $html=ob_get_clean();
            $expected=($inventory && $profileInventory) || $assets;
            if (str_contains($html,'>Management</span>')!==$expected) throw new RuntimeException('Empty Management heading visibility mismatch');
            if (str_contains($html,'Inventory Management')!==($inventory && $profileInventory)) throw new RuntimeException('Inventory visibility mismatch');
            if (str_contains($html,'>Fixed Asset Management')!==$assets) throw new RuntimeException('Fixed assets visibility mismatch');
            if (!str_contains($html,'>NFRS Compliance</span>')) throw new RuntimeException('Independent NFRS section was hidden');
        }
    }
}
echo "Management navigation: all eight module/profile combinations passed; NFRS preserved.\n";
