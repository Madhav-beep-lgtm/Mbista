<?php
declare(strict_types=1);

function company_business_types(): array
{
    return ['service'=>'Service business','internal_service'=>'M. Bista / Altiora group',
        'trading_manufacturing'=>'Trading & manufacturing','hospitality'=>'Hospitality','jewellery'=>'Jewellery'];
}

/** The platform profile selects the workflow; tenant preferences cannot override it. */
function company_business_workflow(string $type): array
{
    $inventory=in_array($type,['trading_manufacturing','hospitality','jewellery'],true);
    return [
        'inventory'=>$inventory,
        'manufacturing'=>$type==='trading_manufacturing',
        'task_billing'=>$type==='internal_service',
        'default_trade_mode'=>$inventory ? 'goods' : 'services',
        'trade_modes'=>$inventory ? ['goods','services'] : ['services'],
        'sales_label'=>match($type) {'hospitality'=>'Menu sales','jewellery'=>'Jewellery sales','internal_service'=>'Task sales',default=>$inventory ? 'Goods sales' : 'Service sales'},
        'purchase_label'=>match($type) {'hospitality'=>'Ingredients & supplies','jewellery'=>'Jewellery purchase',default=>$inventory ? 'Goods purchase' : 'Service purchase'},
        'legacy_type'=>$type==='trading_manufacturing' ? 'manufacturing' : ($inventory ? 'trading' : 'service'),
    ];
}

function company_voucher_type_allowed(string $business,string $voucherType): bool
{
    $workflow=company_business_workflow($business);
    return match($voucherType) {
        'production'=>$workflow['manufacturing'],
        'stock_transfer'=>$workflow['inventory'],
        default=>isset(company_business_types()[$business]),
    };
}

/** Ledger imports cannot replace workflows that also allocate invoices or stock. */
function company_voucher_import_allowed(string $business, string $type): bool
{
    if (!isset(company_business_types()[$business]) || $type === 'purchase') return false;
    if ($business === 'internal_service' && in_array($type, ['sales', 'receipt'], true)) return false;
    return !in_array($business, ['hospitality', 'jewellery'], true)
        || !in_array($type, ['sales', 'credit_note', 'debit_note'], true);
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
