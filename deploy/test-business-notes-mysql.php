<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
if(!in_array(DB_HOST,['localhost','127.0.0.1'],true)) throw new RuntimeException('Local database only');
require __DIR__.'/../app/business_notes_schema.php';
$pdo=db();
$fy=$pdo->query('SELECT f.* FROM fiscal_years f WHERE (SELECT COUNT(*) FROM ledgers l WHERE l.company_id=f.company_id)>=2 ORDER BY f.id DESC LIMIT 1')->fetch();
if(!$fy)throw new RuntimeException('Local company fixture required');
$cid=(int)$fy['company_id'];
$actor=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$q=$pdo->prepare('SELECT id FROM ledgers WHERE company_id=? ORDER BY id LIMIT 2');$q->execute([$cid]);$ledgers=$q->fetchAll(PDO::FETCH_COLUMN);
$pdo->beginTransaction();
try {
 $pdo->prepare("UPDATE fiscal_years SET status='open',is_active=1 WHERE id=?")->execute([$fy['id']]);
 update_settings(['company_business_type'=>'hospitality'],$cid);
 $make=function()use($pdo,$fy,$cid,$actor,$ledgers):int {
  $id=create_voucher_with_entries(['company_id'=>$cid,'fiscal_year_id'=>$fy['id'],'voucher_date'=>$fy['start_date'],'voucher_no'=>'BNTEST-'.bin2hex(random_bytes(6)),'voucher_type'=>'sales','source_type'=>'hospitality_sales_upload','total_amount'=>113,'status'=>'posted','posted_by'=>$actor], [['ledger_id'=>$ledgers[0],'entry_type'=>'debit','amount'=>113],['ledger_id'=>$ledgers[1],'entry_type'=>'credit','amount'=>113]]);
  $pdo->prepare("INSERT INTO hospitality_sales_uploads(company_id,fiscal_year_id,date_from,date_to,row_count,voucher_count,gross_amount,taxable_amount,vat_amount,receivable_amount,status) VALUES(?,?,?,?,1,1,100,100,13,113,'posted')")->execute([$cid,$fy['id'],$fy['start_date'],$fy['start_date']]);$upload=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO hospitality_sales_upload_lines(upload_id,company_id,sale_date,category,item_name,qty,gross_amount,vat_amount,taxable_amount,voucher_id) VALUES(?,?,?,'Test','Test menu',2,100,13,100,?)")->execute([$upload,$cid,$fy['start_date'],$id]);
  $pdo->prepare("INSERT INTO hospitality_sales_invoice_lines(upload_id,company_id,sale_date,invoice_no,total_amount,voucher_id) VALUES(?,?,?,'TEST',113,?)")->execute([$upload,$cid,$fy['start_date'],$id]);
  return $id;
 };
 $original=$make();
 $note=business_note_create($cid,$original,'credit_note',$fy['start_date'],'Integration test',$actor,false);
 $admin=(int)$pdo->query("SELECT id FROM users WHERE role='admin' AND status='active' ORDER BY id LIMIT 1")->fetchColumn();
 if (!$admin) throw new RuntimeException('Local admin fixture required');
 $_SESSION['user_id']=$admin;
 $_SESSION['auth_issued_at']=time();
 foreach ([$original,$note] as $protectedId) {
  $deleted=force_delete_voucher($protectedId,$cid,'Regression test protected return',$admin);
  if ($deleted['ok'] || !str_contains($deleted['error'],'return note')) throw new RuntimeException('Force delete bypassed return protection');
 }
 $q=$pdo->prepare('SELECT qty,gross_amount,vat_amount FROM hospitality_sales_upload_lines WHERE voucher_id=?');$q->execute([$note]);$line=$q->fetch();
 if((float)$line['qty']!==-2.0 || (float)$line['gross_amount']!==-100.0 || (float)$line['vat_amount']!==-13.0)throw new RuntimeException('Negative return lines failed');
 business_note_finalize($note);
 $q=$pdo->prepare('SELECT COUNT(*) FROM hospitality_sales_upload_lines WHERE voucher_id=?');$q->execute([$note]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Replay duplicated lines');
 try {business_note_create($cid,$original,'credit_note',$fy['start_date'],'Duplicate',$actor,false);throw new LogicException('Duplicate accepted');}catch(RuntimeException $e){}
 try {business_note_create($cid+100000,$original,'credit_note',$fy['start_date'],'Foreign company',$actor,false);throw new LogicException('Foreign source accepted');}catch(RuntimeException $e){}
 $pending=business_note_create($cid,$make(),'credit_note',$fy['start_date'],'Approval test',$actor,true);
 $q->execute([$pending]);if((int)$q->fetchColumn()!==0)throw new RuntimeException('Pending note applied');
 $pdo->prepare("UPDATE vouchers SET status='posted',approval_state='approved' WHERE id=?")->execute([$pending]);business_note_finalize($pending);
 $q->execute([$pending]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Approved note not applied');
 echo "MySQL business notes: migration, full hospitality return, replay, duplicate, tenant boundary and pending/approved effects passed (fixtures rolled back).\n";
} finally {if($pdo->inTransaction())$pdo->rollBack();}
