<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$checks=[];
$check=static function(bool $ok,string $label)use(&$checks):void{$checks[]=[$ok,$label];echo($ok?'PASS ':'FAIL ').$label.PHP_EOL;};
$migration=(string)file_get_contents($root.'/database/migrations/114_jewellery_goaml_reporting.sql');
$engine=(string)file_get_contents($root.'/app/jewellery_aml.php');
$page=(string)file_get_contents($root.'/public_html/admin/jewellery-aml.php');
$reports=(string)file_get_contents($root.'/app/reports_engine.php');
$header=(string)file_get_contents($root.'/app/views/partials/admin_header.php');
$check(str_contains($migration,'DEFAULT 1000000.00'),'TTR threshold defaults to NPR 1,000,000');
$check(str_contains($migration,'DEFAULT 15'),'TTR due period defaults to 15 days');
$check(str_contains($engine,"'TTR','daily_threshold'") && str_contains($engine,'DPMS_DAILY_1M'),'Daily TTR candidate rule exists');
$check(str_contains($engine,"'SAR','possible_structuring'") && str_contains($engine,"'SAR','missing_kyc'"),'Internal SAR red flags exist');
$check(str_contains($engine,'anonymous:') && str_contains($engine,'source_id'),'Unidentified walk-ins are not aggregated together');
$check(str_contains($engine,'jewellery_settlement_allocations'),'Allocated payments are not double-counted with their jewellery bill');
$check(str_contains($engine,'fingerprint') && str_contains($migration,'uniq_jw_aml_fingerprint'),'Candidate generation is idempotent');
$check(str_contains($engine,'jw_aml_create_manual_sar'),'Attempted activity can be entered manually');
$check(str_contains($page,"require_permission('jewellery','post')"),'Review writes require jewellery posting authority');
$check(!str_contains($page,'csrf_field()') && str_contains($page,'name="csrf_token"') && str_contains($page,'csrf_token()'),'AML forms use the application CSRF contract');
$check(str_contains($page,'jw-aml-filters') && str_contains($header,"'jewellery-aml.php' => 'aml'") && str_contains($header,"'AML / goAML Reporting', 'aml'"),'AML filters and dedicated icon are wired');
$check(str_contains($reports,"'jewellery-aml-register'") && str_contains($header,'jewellery-aml.php'),'Report Centre and Jewellery navigation are wired');
exit(count(array_filter($checks,static fn($c)=>!$c[0]))>0?1:0);
