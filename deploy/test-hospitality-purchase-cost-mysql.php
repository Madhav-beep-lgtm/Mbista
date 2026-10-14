<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/hospitality_engine.php';
if(!in_array(DB_HOST,['localhost','127.0.0.1'],true))throw new RuntimeException('Local database only');
$pdo=db();
$fy=$pdo->query('SELECT f.* FROM fiscal_years f WHERE (SELECT COUNT(*) FROM ledgers l WHERE l.company_id=f.company_id)>=2 ORDER BY f.id DESC LIMIT 1')->fetch();
if(!$fy)throw new RuntimeException('Local fixture required');
$cid=(int)$fy['company_id'];$date=$fy['start_date'];
$q=$pdo->prepare('SELECT id FROM ledgers WHERE company_id=? ORDER BY id LIMIT 2');$q->execute([$cid]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
$pdo->beginTransaction();
try {
 $pdo->prepare("UPDATE fiscal_years SET status='open',is_active=1 WHERE id=?")->execute([$fy['id']]);
 update_settings(['company_business_type'=>'hospitality'],$cid);
 if(company_accounting_business_type($cid)!=='trading' || accounting_business_profile(company_accounting_business_type($cid))['show_manufacturing'])throw new RuntimeException('Legacy accounting adapter ignored platform profile');
 $pdo->prepare("INSERT INTO inventory_items(company_id,ledger_id,sku,name,unit,item_type,status) VALUES(?,?,?,'Cost test','kg','stock','active')")->execute([$cid,$ids[0],'HC'.bin2hex(random_bytes(4))]);$item=(int)$pdo->lastInsertId();
 $make=function(string $day,float $rate,string $status)use($pdo,$cid,$fy,$item,$ids):void {
  $v=create_voucher_with_entries(['company_id'=>$cid,'fiscal_year_id'=>$fy['id'],'voucher_date'=>$day,'voucher_no'=>'HC'.bin2hex(random_bytes(5)),'voucher_type'=>'purchase','source_type'=>'voucher_form','total_amount'=>$rate*2,'status'=>$status],[['ledger_id'=>$ids[0],'entry_type'=>'debit','amount'=>$rate*2],['ledger_id'=>$ids[1],'entry_type'=>'credit','amount'=>$rate*2]]);
  $pdo->prepare("INSERT INTO inventory_transactions(company_id,fiscal_year_id,item_id,transaction_type,transaction_date,qty_in,qty_out,rate,amount,source_voucher_id) VALUES(?,?,?,'purchase',?,2,0,?,?,?)")->execute([$cid,$fy['id'],$item,$day,$rate,$rate*2,$v]);
 };
 $make($date,20,'posted');$make($date,99,'draft');
 $future=(new DateTimeImmutable($date))->modify('+10 days')->format('Y-m-d');$make($future,100,'posted');
 $ingredient=['company_id'=>$cid,'inventory_item_id'=>$item,'purchase_cost'=>7];
 if(hospitality_ingredient_cost_on($ingredient,$date,'latest_purchase')!==20.0)throw new RuntimeException('Draft/future purchase contaminated costing');
 if(hospitality_ingredient_cost_on($ingredient,$future,'latest_purchase')!==100.0)throw new RuntimeException('New purchase not selected');
 if(hospitality_ingredient_cost_on($ingredient,$date,'manual')!==7.0)throw new RuntimeException('Manual cost overridden');
 $ingredient['company_id']=$cid+100000;
 if(hospitality_ingredient_cost_on($ingredient,$date,'latest_purchase')!==0.0)throw new RuntimeException('Foreign purchase exposed');
 $pdo->prepare('UPDATE inventory_items SET is_ingredient=1,purchase_rate=10 WHERE id=?')->execute([$item]);
 hospitality_sync_ingredients_from_inventory($cid);
 $pdo->prepare("UPDATE hospitality_ingredients SET cost_source='manual',purchase_cost=77 WHERE company_id=? AND inventory_item_id=?")->execute([$cid,$item]);
 hospitality_sync_ingredients_from_inventory($cid);
 $q=$pdo->prepare('SELECT purchase_cost FROM hospitality_ingredients WHERE company_id=? AND inventory_item_id=?');$q->execute([$cid,$item]);
 if((float)$q->fetchColumn()!==77.0)throw new RuntimeException('Inventory sync overwrote manual cost');
 echo "Hospitality purchase costs: posted purchases, date cutoff, pending exclusion, manual fallback and tenant isolation passed (fixtures rolled back).\n";
} finally {if($pdo->inTransaction())$pdo->rollBack();}
