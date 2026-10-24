<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
if(!in_array(DB_HOST,['localhost','127.0.0.1'],true)) throw new RuntimeException('Local database only.');
$companyId=(int)db()->query('SELECT id FROM companies ORDER BY id LIMIT 1')->fetchColumn();
$editVoucher=null;$costCentres=[];$optionsAll=[];$defaultEntryDate=date('Y-m-d');$prefill=[];
$renderLedgerOptions=static fn(array $options,int $selected):string=>'';
$events=[['operation'=>'acquisition','name'=>'Machine </script><script>alert(1)</script>','code'=>'UI-1','amount'=>100],['operation'=>'acquisition','name'=>'Desk','code'=>'UI-2','amount'=>200]];
$retry=['input'=>['asset_payload'=>json_encode(['events'=>$events]),'asset_event_index'=>1]];
ob_start();
require __DIR__.'/../app/views/vouchers/asset_details.php';
$html=ob_get_clean();
foreach(['asset-event-picker','asset-add-another','remove_asset_journal','impairment_reversal','asset_event_index','asset-debit-entry','asset-credit-entry','Attach asset details'] as $required) if(!str_contains($html,$required))throw new RuntimeException('Missing inline asset control: '.$required);
if(str_contains($html,'<dialog') || str_contains($html,'showModal'))throw new RuntimeException('Asset entry must not open a popup');
if(str_contains(file_get_contents(__DIR__.'/../public_html/admin/fixed-assets.php'),'Register a fixed asset'))throw new RuntimeException('Legacy registration form remains');
if(str_contains($html,'Machine </script><script>'))throw new RuntimeException('Asset name is not escaped');
if(!preg_match('~<script>(.*?)</script>~s',$html,$script))throw new RuntimeException('Popup script missing');
if(in_array('--javascript',$argv,true))echo $script[1];
else echo "Inline asset details: journal line selectors, no popup, legacy registration removal and output escaping passed.\n";
