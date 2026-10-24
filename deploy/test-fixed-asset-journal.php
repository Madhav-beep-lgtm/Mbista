<?php
declare(strict_types=1);
require __DIR__.'/../app/fixed_asset_journal.php';
function faj_test(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
$data=['operation'=>'acquisition','name'=>'Machine','code'=>'M01','amount'=>1200,'method'=>'straight_line','rate'=>20,'life_months'=>60,'residual'=>0,'cost_ledger'=>1,'counter_ledger'=>2,'expense_ledger'=>3,'accumulated_ledger'=>4,'reserve_ledger'=>5,'loss_ledger'=>6,'gain_ledger'=>7,'impairment_ledger'=>8,'evidence'=>'Valuer report'];
faj_test(faj_plan($data,null)['deltas']===[1=>1200.0,2=>-1200.0],'Acquisition journal');
$asset=['cost'=>1200,'accumulated_depreciation'=>200,'accumulated_impairment'=>0,'residual_value'=>100,'revaluation_reserve'=>0,'revaluation_loss_balance'=>0];
$data['operation']='depreciation';$data['amount']=20;$plan=faj_plan($data,$asset);
faj_test($plan['deltas']===[3=>20.0,4=>-20.0] && $plan['changes']['carrying_amount']===980.0,'Depreciation journal');
$data['amount']=901;
try{faj_plan($data,$asset);throw new LogicException('Residual cap bypassed');}catch(RuntimeException $e){}
$data['operation']='addition';$data['amount']=100;faj_test(faj_plan($data,$asset)['changes']['cost']===1300.0,'Addition');
$data['operation']='revaluation';$data['amount']=1500;$plan=faj_plan($data,$asset);
faj_test($plan['deltas']===[1=>300.0,4=>200.0,5=>-500.0] && $plan['changes']['accumulated_depreciation']===0,'Revaluation eliminates accumulated depreciation');
$data['amount']=800;$asset['revaluation_reserve']=150;$plan=faj_plan($data,$asset);
faj_test($plan['deltas']===[1=>-400.0,4=>200.0,5=>150.0,6=>50.0],'Revaluation reserve then loss');
$data['operation']='disposal';$data['amount']=1100;$plan=faj_plan($data,$asset);
faj_test($plan['deltas']===[1=>-1200.0,4=>200.0,2=>1100.0,7=>-100.0] && $plan['changes']['status']==='disposed','Disposal journal');
$data['amount']=900;$plan=faj_plan($data,$asset);faj_test($plan['deltas'][6]===100.0,'Disposal loss');
echo "Fixed asset journal plans: acquisition, addition, depreciation cap, revaluation allocation and disposal passed.\n";
$asset['revaluation_reserve']=0;
$data['operation']='impairment';$data['amount']=700;
$plan=faj_plan($data,$asset);
faj_test($plan['deltas']===[8=>-300.0,6=>300.0] && $plan['changes']['carrying_amount']===700.0,'Impairment journal');
$asset=array_merge($asset,$plan['changes']);
$data['operation']='impairment_reversal';$data['reversal_ledger']=9;$data['amount']=5000;
$plan=faj_plan($data,$asset);
faj_test($plan['deltas']===[8=>300.0,9=>-300.0] && $plan['changes']['carrying_amount']===1000.0,'Reversal capped at recognised impairment');
$data['amount']=600;
try{faj_plan($data,$asset);throw new LogicException('Non-positive reversal accepted');}catch(RuntimeException $e){}
$asset['carrying_amount']=123;
try{faj_plan($data,$asset);throw new LogicException('Legacy valuation mismatch silently changed');}catch(RuntimeException $e){}
echo "Impairment, reversal cap and historical-value guards passed.\n";
