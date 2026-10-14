<?php
declare(strict_types=1);

function company_business_types(): array
{
    return ['service'=>'Service business','internal_service'=>'M. Bista / Altiora group',
        'trading_manufacturing'=>'Trading & manufacturing','hospitality'=>'Hospitality','jewellery'=>'Jewellery'];
}

function company_business_type(int $companyId): string
{
    $configured = (string)setting('company_business_type','',false,$companyId);
    if (isset(company_business_types()[$configured])) return $configured;
    if (function_exists('voucher_billing_group') && voucher_billing_group($companyId)) return 'internal_service';
    $jewellery = company_module_enabled('jewellery',$companyId);
    $hospitality = company_module_enabled('hospitality',$companyId);
    if ($jewellery && $hospitality) return 'conflict';
    if ($jewellery) return 'jewellery';
    if ($hospitality) return 'hospitality';
    return company_module_enabled('inventory',$companyId) ? 'trading_manufacturing' : 'service';
}

function company_business_save(int $companyId,string $type,array $modules): void
{
    if (!user_is_super_admin()) throw new RuntimeException('Only the super admin can configure business types.');
    if (!isset(company_business_types()[$type])) throw new RuntimeException('Select a business type.');
    if (($type === 'internal_service') !== voucher_billing_group($companyId)) {
        throw new RuntimeException('The internal service profile is reserved for M. Bista, Altiora and their subsidiaries.');
    }
    $modules=array_values(array_diff($modules,['hospitality','jewellery']));
    if (in_array($type,['hospitality','jewellery'],true)) $modules[]=$type;
    if (in_array($type,['trading_manufacturing','hospitality','jewellery'],true)) $modules[]='inventory';
    company_modules_save($companyId,$modules);
    update_settings(['company_business_type'=>$type],$companyId);
}
