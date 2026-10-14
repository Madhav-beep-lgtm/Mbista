<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/voucher_types.php';
require_once __DIR__.'/../app/accounting_module_repair.php';
if (!in_array(DB_HOST,['localhost','127.0.0.1'],true)) throw new RuntimeException('Local database only.');
// Additive local schema installation; every business-data fixture rolls back.
require __DIR__.'/../app/voucher_billing_schema.php';
$fy=db()->query("SELECT f.* FROM fiscal_years f WHERE EXISTS (SELECT 1 FROM client_profiles cp WHERE cp.company_id=f.company_id) AND EXISTS (SELECT 1 FROM ledgers l JOIN ledger_groups g ON g.id=l.group_id WHERE l.company_id=f.company_id AND l.status='active' AND g.master_key='direct_income') AND EXISTS (SELECT 1 FROM ledgers l JOIN ledger_groups g ON g.id=l.group_id WHERE l.company_id=f.company_id AND l.status='active' AND g.is_cash_or_bank=1) ORDER BY f.id DESC LIMIT 1")->fetch();
$clientQuery=db()->prepare('SELECT id,user_id FROM client_profiles WHERE company_id=? ORDER BY id LIMIT 1');
$clientQuery->execute([(int)($fy['company_id'] ?? 0)]);
$client=$clientQuery->fetch();
if (!$fy || !$client) throw new RuntimeException('Local company/client fixtures required.');
$cid=(int)$fy['company_id']; $actor=(int)$client['user_id']; $suffix=bin2hex(random_bytes(5));
db()->beginTransaction();
try {
 // Test-only lifecycle change, rolled back together with all fixtures.
 db()->prepare("UPDATE fiscal_years SET status='open',is_active=1 WHERE id=?")->execute([$fy['id']]);
 $party=ensure_party_for_client($cid,(int)$client['id']);
 $ar=ensure_party_ledger($cid,$party,'receivable');
 $directory=voucher_ledger_directory($cid);
 $income=0; $cash=0;
 foreach($directory as $id=>$row) { if(!empty($row['roles']['income'])) $income=(int)$id; if(!empty($row['roles']['cash_bank'])) $cash=(int)$id; }
 if(!$income || !$cash || !$ar) throw new RuntimeException('Local income, cash and receivable ledgers required.');
 db()->prepare("INSERT INTO client_tasks(company_id,client_id,title,quoted_fee,status,created_by) VALUES(?,?,'Voucher billing fixture',100,'completed',?)")->execute([$cid,$client['id'],$actor]);
 $task=(int)db()->lastInsertId();
 $selected=false;
 foreach(voucher_billing_tasks($cid) as $row) if((int)$row['task_id']===$task) $selected=true;
 if(!$selected)throw new RuntimeException('MySQL task eligibility failed.');
 $make=function(string $type,float $amount,array $lines)use($cid,$fy,$party,$actor,$suffix):int {
  $id=create_voucher_with_entries(['company_id'=>$cid,'fiscal_year_id'=>$fy['id'],'voucher_no'=>'VBTEST-'.$type.'-'.$suffix,'voucher_type'=>$type,'voucher_date'=>$fy['start_date'],'party_id'=>$party,'total_amount'=>$amount,'status'=>'draft','approval_state'=>'draft','submitted_by'=>$actor,'narration'=>'Test'], $lines);
  db()->prepare("UPDATE vouchers SET status='posted',approval_state='approved',approved_by=? WHERE id=?")->execute([$actor,$id]);
  return $id;
 };
 $sales=$make('sales',100,[['ledger_id'=>$ar,'entry_type'=>'debit','amount'=>100,'memo'=>'Client'],['ledger_id'=>$income,'entry_type'=>'credit','amount'=>100,'memo'=>'Services']]);
 voucher_billing_save($sales,['billing_task'=>$task.':0'],[['entry_type'=>'credit','amount'=>100,'memo'=>'Services','tax_code'=>'VAT:none:0','item_id'=>0,'quantity'=>0]]);
 $invoice=(int)voucher_billing_link($sales)['document_id'];
 if(!$invoice)throw new RuntimeException('MySQL invoice creation failed.');
 $receipt=$make('receipt',40,[['ledger_id'=>$cash,'entry_type'=>'debit','amount'=>40,'memo'=>'Cash'],['ledger_id'=>$ar,'entry_type'=>'credit','amount'=>40,'memo'=>'Client']]);
 voucher_billing_save($receipt,['billing_invoice_id'=>$invoice],[]);
 $remaining=null;
 foreach(voucher_billing_outstanding($cid) as $row) if((int)$row['id']===$invoice) $remaining=(float)$row['outstanding'];
 if($remaining!==60.0)throw new RuntimeException('MySQL receipt allocation failed.');
 $check=db()->prepare('SELECT COUNT(*) FROM invoice_payment_receipts WHERE invoice_id=?'); $check->execute([$invoice]);
 if((int)$check->fetchColumn()!==1)throw new RuntimeException('Detailed receipt was not recorded.');
 echo "MySQL voucher billing: migration, eligibility, invoice, partial receipt and foreign keys passed (business fixtures rolled back).\n";
} finally { if(db()->inTransaction())db()->rollBack(); }
