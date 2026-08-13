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
$trade=(string)file_get_contents($root.'/app/jewellery_trade.php');
$performanceMigration=(string)file_get_contents($root.'/database/migrations/115_aml_performance_indexes.sql');
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
$check(!str_contains($page,'jw_aml_scan($companyId,$from,$to,0)') && str_contains($trade,'jw_aml_scan_posted_date'),
    'AML detection is incremental instead of running on register GET requests');
$check(str_contains($engine,'array_chunk($transactions, 200)') && str_contains($engine,'VALUES ' . "' . implode"),
    'AML transaction links are inserted in bounded batches');
$check(str_contains($engine,'function jw_aml_case_count') && str_contains($page,'$pageSize=50'),
    'AML register results are paginated with a separate count');
$check(str_contains($engine,'function jw_aml_stream_csv') && str_contains($engine,"fopen('php://output', 'w')"),
    'AML CSV export streams bounded result pages');
$check(str_contains($performanceMigration,'idx_jw_aml_register') && str_contains($performanceMigration,'`company_id`, `case_date`, `status`'),
    'AML register has a matching composite index migration');
exit(count(array_filter($checks,static fn($c)=>!$c[0]))>0?1:0);
