<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/jewellery_reports.php';
require_once __DIR__.'/../app/jewellery_trace.php';
if(!in_array(DB_HOST,['localhost','127.0.0.1'],true))throw new RuntimeException('Local database only');
require __DIR__.'/../app/business_notes_schema.php';
$pdo=db();
$fixture=$pdo->query('SELECT p.*,f.id AS fy_id,f.start_date FROM jewellery_item_profiles p JOIN fiscal_years f ON f.company_id=p.company_id ORDER BY f.id DESC LIMIT 1')->fetch();
if(!$fixture)throw new RuntimeException('Local jewellery fixture required');
$cid=(int)$fixture['company_id'];$fy=(int)$fixture['fy_id'];$date=$fixture['start_date'];
$actor=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$pdo->beginTransaction();
try {
 $pdo->prepare("UPDATE fiscal_years SET status='open',is_active=1 WHERE id=?")->execute([$fy]);
 update_settings(['company_business_type'=>'jewellery'],$cid);
 $q=$pdo->prepare('SELECT id FROM ledgers WHERE company_id=? ORDER BY id LIMIT 3');$q->execute([$cid]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$cash=(int)$ids[0];
 if(count($ids)<3)throw new RuntimeException('Three fixture ledgers required');
 foreach(['stock_metal','stock_finished','stock_stone','sales_metal','sales_making','sales_stone','sales_discount','other_charges','cogs','vat_input','vat_output','spt_input','spt_output','rounding'] as $purpose) jewellery_save_mapping($cid,$purpose,(int)$ids[str_starts_with($purpose,'stock_')?1:2],$actor);
 $suffix=strtoupper(bin2hex(random_bytes(4)));
 $pdo->prepare("INSERT INTO accounting_parties(company_id,code,name,party_type,status) VALUES(?,?,?,'both','active')")->execute([$cid,'BN'.$suffix,'Return test']);$party=(int)$pdo->lastInsertId();
 $item=jewellery_save_item($cid,['code'=>'BN'.$suffix,'name'=>'Return test','item_type'=>'bullion','metal_id'=>$fixture['metal_id'],'purity_id'=>$fixture['purity_id'],'unit_id'=>$fixture['unit_id'],'gross_weight'=>1,'vat_applicable'=>1,'vat_base'=>'full_value'],$actor);
 $purchase=function()use($cid,$fy,$date,$party,$cash,$item,$actor):array {
  $id=jewellery_save_purchase($cid,$fy,['purchase_date'=>$date,'party_id'=>$party,'settle_mode'=>'cash','settle_ledger_id'=>$cash],[['item_id'=>$item,'qty_pieces'=>1,'gross_weight'=>1,'rate'=>100,'stone_amount'=>20]],$actor);
  $posted=jewellery_post_purchase($cid,$id,$actor);
  if(empty($posted['ok']))throw new RuntimeException('Purchase: '.($posted['error'] ?? 'failed'));
  return [$id,(int)$posted['voucher_id']];
 };
 [$purchaseId,$voucher]=$purchase();
 $note=business_note_create($cid,$voucher,'debit_note',$date,'Supplier return',$actor,false);
 $balance=jw_item_balance($cid,$item);
 if(abs((float)$balance['qty_pieces'])>.001 || abs((float)$balance['fine_weight'])>.0001)throw new RuntimeException('Purchase stock reversal failed');
 $q=$pdo->prepare('SELECT status,current_holder_type FROM jewellery_stock_units WHERE company_id=? AND origin_type=? AND origin_id=?');$q->execute([$cid,'purchase',$purchaseId]);
 foreach($q->fetchAll() as $unit)if($unit['status']!=='returned'||$unit['current_holder_type']!=='supplier')throw new RuntimeException('Supplier custody not recorded');
 $report=jw_report_purchase_detail($cid,$date,$date);
 $rows=array_filter($report['rows'],fn($row)=>$row['item_code']==='BN'.$suffix);
 if(count($rows)!==2 || abs(array_sum(array_column($rows,'line_total')))>.01)throw new RuntimeException('Purchase report not net of return');
 [$purchaseId,$voucher]=$purchase();
 $before=jw_item_balance($cid,$item);
 $sale=jewellery_save_sale($cid,$fy,['sale_date'=>$date,'party_id'=>$party,'settle_mode'=>'cash','settle_ledger_id'=>$cash,'received_amount'=>150],[['item_id'=>$item,'qty_pieces'=>1,'gross_weight'=>1,'rate'=>150,'stone_amount'=>20]],[],$actor);
 $posted=jewellery_post_sale($cid,$sale,$actor);
 if(empty($posted['ok']))throw new RuntimeException('Sale: '.($posted['error']??'failed'));
 $credit=business_note_create($cid,(int)$posted['voucher_id'],'credit_note',$date,'Customer return',$actor,false);
 $after=jw_item_balance($cid,$item);
 if(abs((float)$after['qty_pieces']-(float)$before['qty_pieces'])>.001 || abs((float)$after['fine_weight']-(float)$before['fine_weight'])>.0001)throw new RuntimeException('Sales stock return failed');
 $report=jw_report_sales_detail($cid,$date,$date);
 $rows=array_filter($report['rows'],fn($row)=>$row['item_code']==='BN'.$suffix);
 if(count($rows)!==2 || abs(array_sum(array_column($rows,'line_total')))>.01)throw new RuntimeException('Sales report not net of return');
 $vat=jw_report_vat_register($cid,$date,$date);
 $vatRows=array_filter($vat['output_rows'],fn($row)=>$row['item_code']==='BN'.$suffix);
 if(count($vatRows)!==2 || abs(array_sum(array_column($vatRows,'vat_amount')))>.01)throw new RuntimeException('VAT return report failed');
 jw_report_tax_register($cid,$date,$date);
 // A failed stock posting must not leave its financial voucher behind.
 require_once __DIR__.'/../app/voucher_types.php';
 $pdo->exec('SAVEPOINT before_failed_stock');
 $bad=create_voucher_with_entries(['company_id'=>$cid,'fiscal_year_id'=>$fy,'voucher_date'=>$date,'voucher_no'=>'BNFAIL-'.$suffix,'voucher_type'=>'debit_note','source_type'=>'voucher_form','total_amount'=>100,'status'=>'posted'],[['ledger_id'=>$ids[0],'entry_type'=>'debit','amount'=>100],['ledger_id'=>$ids[1],'entry_type'=>'credit','amount'=>100]]);
 $pdo->prepare('UPDATE voucher_entries SET item_id=?,quantity=1000000 WHERE voucher_id=? AND entry_type=?')->execute([$item,$bad,'credit']);
 $pdo->prepare('UPDATE inventory_items SET allow_negative_stock=0 WHERE id=?')->execute([$item]);
 try {
  voucher_stock_sync($cid,$fy,['id'=>$bad,'voucher_type'=>'debit_note','status'=>'posted','voucher_date'=>$date,'voucher_no'=>'BNFAIL-'.$suffix],$actor,true);
  throw new LogicException('Oversized purchase return was accepted');
 } catch(RuntimeException $e) {$pdo->exec('ROLLBACK TO SAVEPOINT before_failed_stock');}
 $q=$pdo->prepare('SELECT COUNT(*) FROM vouchers WHERE id=?');$q->execute([$bad]);if((int)$q->fetchColumn()!==0)throw new RuntimeException('Failed stock left a voucher');
 echo "MySQL jewellery returns: purchase, sales, fine weight, pieces, supplier custody and sales/purchase/VAT/tax reports passed (fixtures rolled back).\n";
} finally {if($pdo->inTransaction())$pdo->rollBack();}
