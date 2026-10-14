<?php
declare(strict_types=1);
class BillingDb extends PDO {
 public function prepare(string $query,array $options=[]): PDOStatement|false {
  $query=str_replace([' FOR UPDATE',"CONCAT(t.title,' / ',s.stage_name)"],['',"t.title || ' / ' || s.stage_name"],$query);
  $query=str_replace('ON DUPLICATE KEY UPDATE task_id=VALUES(task_id),stage_id=VALUES(stage_id),invoice_id=VALUES(invoice_id),payload=VALUES(payload)','ON CONFLICT(voucher_id) DO UPDATE SET task_id=excluded.task_id,stage_id=excluded.stage_id,invoice_id=excluded.invoice_id,payload=excluded.payload',$query);
  if (str_contains($query,'HAVING outstanding')) $query='SELECT * FROM ('.str_replace('HAVING outstanding > 0 ORDER BY i.issued_on,i.id',') WHERE outstanding > 0 ORDER BY issued_on,id',$query);
  return parent::prepare($query,$options);
 }
}
$pdo=new BillingDb('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function db():PDO { global $pdo; return $pdo; }
function table_exists(string $name):bool { return true; }
function company_by_id(int $id):array { return ['id'=>$id,'code'=>$id===1 ? 'MBAACA' : 'OTHER','parent_company_id'=>$id===2 ? 1 : 0]; }
function voucher_ledger_directory(int $id):array { return [1=>['roles'=>['income'=>true]],2=>['roles'=>['tax'=>true]],3=>['roles'=>['receivable'=>true]],4=>['roles'=>['cash_bank'=>true]]]; }
function invoice_party_id(array $i,int $id):int { return (int)$i['party_id']; }
function ensure_party_ledger(int $c,int $p,string $s):int { return 3; }
function current_user():array { return ['id'=>1]; }
function auto_post_client_mirror_invoice(int $id,int $actor):?string { return null; }
function auto_post_client_mirror_payment(int $id,int $actor):void {}
function client_mirror_admin_approval_blocker(array $v):?string { return null; }
function fiscal_year_by_id(int $id):array { return ['company_id'=>1]; }
function fiscal_year_posting_blocker(array $fy,string $date):?string { return null; }
function is_period_locked(int $c,int $fy,string $d):bool { return false; }
require __DIR__.'/../app/voucher_billing.php';
function ck(bool $ok,string $why):void { if(!$ok)throw new RuntimeException($why); }
function transaction(callable $fn,bool $reject=false):void {
 db()->beginTransaction();
 try { $fn(); if($reject)throw new LogicException('Expected rejection'); db()->commit(); }
 catch(RuntimeException $e) { db()->rollBack(); if(!$reject)throw $e; }
}
$pdo->exec('CREATE TABLE companies(id INTEGER PRIMARY KEY); INSERT INTO companies VALUES(1),(2),(3)');
$pdo->exec("CREATE TABLE vouchers(id INTEGER PRIMARY KEY,company_id INTEGER,voucher_type TEXT,total_amount NUMERIC,party_id INTEGER,status TEXT,approval_state TEXT,voucher_date TEXT,fiscal_year_id INTEGER DEFAULT 1,approved_by INTEGER,submitted_by INTEGER DEFAULT 1,posted_by INTEGER,approved_at TEXT,posted_at TEXT,rejection_reason TEXT,due_date TEXT,narration TEXT,reference_no TEXT)");
$pdo->exec('CREATE TABLE voucher_billing(voucher_id INTEGER PRIMARY KEY,company_id INTEGER,kind TEXT,task_id INTEGER,stage_id INTEGER,invoice_id INTEGER,document_id INTEGER,payload TEXT)');
$pdo->exec('CREATE TABLE accounting_parties(id INTEGER PRIMARY KEY,company_id INTEGER,client_profile_id INTEGER); INSERT INTO accounting_parties VALUES(10,1,7),(11,1,8),(12,3,7)');
$pdo->exec("CREATE TABLE client_tasks(id INTEGER PRIMARY KEY,company_id INTEGER,client_id INTEGER,title TEXT,quoted_fee NUMERIC,status TEXT); INSERT INTO client_tasks VALUES(5,1,7,'Annual audit',100,'completed'),(6,1,8,'Foreign client',100,'completed'),(7,3,7,'Foreign company',100,'completed'),(8,1,7,'In progress',100,'in_progress')");
$pdo->exec("CREATE TABLE task_stages(id INTEGER PRIMARY KEY,company_id INTEGER,task_id INTEGER,stage_name TEXT,stage_fee NUMERIC,status TEXT); INSERT INTO task_stages VALUES(50,1,8,'Reporting',40,'completed'),(51,1,8,'Pending',60,'pending')");
$pdo->exec("CREATE TABLE task_invoices(id INTEGER PRIMARY KEY AUTOINCREMENT,company_id INTEGER,task_id INTEGER,stage_id INTEGER,invoice_no TEXT UNIQUE,invoice_type TEXT,invoice_source_type TEXT,source_id INTEGER,party_id INTEGER,description TEXT,invoice_category TEXT,amount NUMERIC,taxable_amount NUMERIC,vat_rate NUMERIC,vat_amount NUMERIC,total_amount NUMERIC,status TEXT,issued_on TEXT,due_on TEXT,notes TEXT,issued_by INTEGER)");
$pdo->exec('CREATE TABLE invoice_line_items(invoice_id INTEGER,item_id INTEGER,source_type TEXT,description TEXT,quantity NUMERIC,rate NUMERIC,taxable_amount NUMERIC,vat_rate NUMERIC,vat_amount NUMERIC,total_amount NUMERIC)');
$pdo->exec('CREATE TABLE invoice_payment_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,invoice_id INTEGER,company_id INTEGER,requested_by INTEGER,amount_requested NUMERIC,payment_method TEXT,status TEXT,payment_received_on TEXT,payment_amount NUMERIC,notes TEXT,source_voucher_id INTEGER UNIQUE)');
$pdo->exec('CREATE TABLE invoice_payment_receipts(payment_request_id INTEGER,invoice_id INTEGER,company_id INTEGER,client_id INTEGER,receipt_no TEXT UNIQUE,amount_received NUMERIC,payment_method TEXT,payment_reference TEXT,received_on TEXT,notes TEXT,created_by INTEGER)');
$pdo->exec('CREATE TABLE ledgers(id INTEGER PRIMARY KEY,company_id INTEGER); INSERT INTO ledgers VALUES(1,1),(2,1),(3,1),(4,1)');
$pdo->exec('CREATE TABLE voucher_entries(voucher_id INTEGER,ledger_id INTEGER,entry_type TEXT,amount NUMERIC)');
function makeVoucher(int $id,string $type,float $total=113,string $status='posted'):void {
 db()->prepare("INSERT INTO vouchers(id,company_id,voucher_type,total_amount,party_id,status,approval_state,voucher_date,narration) VALUES(?,1,?,?,10,?,?,'2026-10-14','Notes')")->execute([$id,$type,$total,$status,$status==='posted'?'approved':'pending_approval']);
 if($type==='sales') db()->exec("INSERT INTO voucher_entries VALUES($id,3,'debit',$total),($id,1,'credit',100),($id,2,'credit',13)");
 else db()->exec("INSERT INTO voucher_entries VALUES($id,4,'debit',$total),($id,3,'credit',$total)");
}
$entries=[['entry_type'=>'credit','tax_code'=>'VAT:exclusive:13','amount'=>100,'memo'=>'Audit services','quantity'=>0,'item_id'=>0]];
ck(voucher_billing_group(1)&&voucher_billing_group(2)&&!voucher_billing_group(3),'Root and subsidiary scope');
ck(count(voucher_billing_tasks(1))===3,'Only completed company tasks/stages eligible');
makeVoucher(1,'sales',113,'draft');
transaction(fn()=>voucher_billing_save(1,['billing_task'=>'5:0'],$entries));
ck((int)$pdo->query('SELECT COUNT(*) FROM task_invoices')->fetchColumn()===0,'Draft does not issue invoice');
voucher_billing_approve(1,1,1);
$invoice=(int)voucher_billing_link(1)['document_id'];
ck($invoice>0,'Approval issues invoice');
transaction(fn()=>voucher_billing_finalize(1));
ck((int)$pdo->query('SELECT COUNT(*) FROM task_invoices')->fetchColumn()===1,'Retry cannot duplicate invoice');
ck(str_contains($pdo->query('SELECT narration FROM vouchers WHERE id=1')->fetchColumn(),'Annual audit'),'Task narration recorded');
makeVoucher(2,'sales'); transaction(fn()=>voucher_billing_save(2,['billing_task'=>'5:0'],$entries),true);
makeVoucher(3,'sales'); transaction(fn()=>voucher_billing_save(3,['billing_task'=>'6:0'],$entries),true);
makeVoucher(4,'sales'); transaction(fn()=>voucher_billing_save(4,['billing_task'=>'7:0'],$entries),true);
makeVoucher(5,'sales'); transaction(fn()=>voucher_billing_save(5,['billing_task'=>'8:51'],$entries),true);
makeVoucher(6,'receipt',50);
transaction(fn()=>voucher_billing_save(6,['billing_invoice_id'=>$invoice],[]));
ck((float)voucher_billing_outstanding(1)[0]['outstanding']===63.0,'Partial collection leaves correct balance');
ck((int)$pdo->query('SELECT COUNT(*) FROM invoice_payment_receipts')->fetchColumn()===1,'Detailed receipt recorded');
makeVoucher(7,'receipt',64); transaction(fn()=>voucher_billing_save(7,['billing_invoice_id'=>$invoice],[]),true);
makeVoucher(8,'receipt',63); transaction(fn()=>voucher_billing_save(8,['billing_invoice_id'=>$invoice],[]));
ck(voucher_billing_outstanding(1)===[],'Fully collected invoice removed from selector');
ck($pdo->query('SELECT status FROM task_invoices')->fetchColumn()==='paid','Invoice paid without extra posting');
makeVoucher(9,'sales',40);
$pdo->exec("UPDATE voucher_entries SET ledger_id=4 WHERE voucher_id=9 AND entry_type='debit'");
transaction(fn()=>voucher_billing_save(9,['billing_task'=>'8:50'],[['entry_type'=>'credit','amount'=>40,'memo'=>'Reporting','tax_code'=>'VAT:none:0']]));
$stageInvoice=(int)voucher_billing_link(9)['document_id'];
ck($pdo->query('SELECT status FROM task_invoices WHERE id='.$stageInvoice)->fetchColumn()==='paid','Cash stage invoice settles once');
ck((int)$pdo->query('SELECT COUNT(*) FROM invoice_payment_requests WHERE source_voucher_id=9')->fetchColumn()===1,'Cash settlement recorded once');
transaction(fn()=>voucher_billing_finalize(9));
ck((int)$pdo->query('SELECT COUNT(*) FROM invoice_payment_requests WHERE source_voucher_id=9')->fetchColumn()===1,'Cash settlement retry idempotent');
echo "Voucher billing: scope, eligibility, drafts, approval, atomic rollback, retries, task narration, partial receipts, overcollection, cash stage invoices and settlement passed.\n";
