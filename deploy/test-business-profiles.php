<?php
declare(strict_types=1);
$settings=[]; $super=true;
function current_company_id():int { return 1; }
function setting($key,$default='',$reload=false,$companyId=null) { global $settings; return $settings[$companyId][$key] ?? $default; }
function update_settings(array $values,int $companyId=0):void { global $settings; $settings[$companyId]=array_replace($settings[$companyId] ?? [],$values); }
function user_is_super_admin():bool { global $super; return $super; }
function jewellery_legacy_enabled_for_company(int $companyId):bool { return $companyId===3; }
function hospitality_legacy_enabled_for_company(int $companyId):bool { return $companyId===4; }
function voucher_billing_group(int $companyId):bool { return $companyId===2; }
require __DIR__.'/../app/company_modules.php';
require __DIR__.'/../app/company_business.php';
function ck(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
function rejects(callable $fn):void { try { $fn(); } catch(RuntimeException $e) { return; } throw new RuntimeException('Expected denial'); }
ck(count(company_business_types())===5,'Five business types');
ck(company_business_type(2)==='internal_service','Internal company inference');
ck(company_business_type(3)==='jewellery','Existing jewellery preserved');
ck(company_business_type(4)==='hospitality','Existing hospitality preserved');
foreach(['service','trading_manufacturing','hospitality','jewellery'] as $type) {
    company_business_save(1,$type,['documents','payroll']);
    ck(company_business_type(1)===$type,'Selected type retained');
    ck(company_module_enabled('hospitality',1)===($type==='hospitality'),'Hospitality exclusive');
    ck(company_module_enabled('jewellery',1)===($type==='jewellery'),'Jewellery exclusive');
    ck(company_module_enabled('documents',1) && company_module_enabled('payroll',1),'Independent add-ons');
    ck(!company_module_enabled('budgets',1),'Disabled add-on');
}
rejects(fn()=>company_modules_save(1,['hospitality','jewellery']));
rejects(fn()=>company_business_save(1,'internal_service',[]));
rejects(fn()=>company_business_save(2,'hospitality',[]));
company_business_save(2,'internal_service',['hr']);
ck(company_module_enabled('jewellery',3),'Other tenant unaffected');
$super=false;
rejects(fn()=>company_business_save(1,'service',[]));
ck(company_route_module('documents.php')==='documents','Document gate');
ck(company_route_module('hospitality.php')==='hospitality','Hospitality gate');
foreach(array_keys(company_business_types()) as $type) {
    $workflow=company_business_workflow($type);
    ck($workflow['manufacturing']===($type==='trading_manufacturing'),'Manufacturing separated');
    ck($workflow['inventory']===in_array($type,['trading_manufacturing','hospitality','jewellery'],true),'Stock profiles separated');
    ck(company_voucher_type_allowed($type,'production')===($type==='trading_manufacturing'),'Production route policy');
    ck(company_voucher_type_allowed($type,'stock_transfer')===$workflow['inventory'],'Transfer route policy');
    ck($workflow['task_billing']===($type==='internal_service'),'Task billing reserved');
    ck(in_array('goods',$workflow['trade_modes'],true)===$workflow['inventory'],'Service-only entry policy');
}
ck(!company_voucher_type_allowed('conflict','sales'),'Conflicting profiles denied');
foreach (['hospitality', 'jewellery'] as $type) {
    foreach (['sales', 'purchase', 'debit_note', 'credit_note'] as $voucherType) {
        ck(!company_voucher_import_allowed($type, $voucherType), 'Specialist import bypass denied');
    }
    ck(company_voucher_import_allowed($type, 'journal'), 'General journal import preserved');
}
ck(!company_voucher_import_allowed('internal_service', 'sales'), 'Task sales import bypass denied');
ck(!company_voucher_import_allowed('internal_service', 'receipt'), 'Receipt allocation import bypass denied');
ck(company_voucher_import_allowed('service', 'journal'), 'Service journal import preserved');
echo "Five profiles: exclusivity, legacy defaults, tenant isolation, add-ons and super-admin authority passed.\n";
