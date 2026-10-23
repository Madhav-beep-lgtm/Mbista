<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
if(!in_array(DB_HOST,['localhost','127.0.0.1'],true)) throw new RuntimeException('Local database only.');
faj_schema();
$pdo=db();$fy=$pdo->query("SELECT * FROM fiscal_years ORDER BY id DESC LIMIT 1")->fetch();$cid=(int)$fy['company_id'];$actor=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$pdo->beginTransaction();
try {
    $suffix=bin2hex(random_bytes(5));$ledgers=[];
    foreach(['cost_ledger'=>'asset','counter_ledger'=>'liability','accumulated_ledger'=>'asset','expense_ledger'=>'expense','reserve_ledger'=>'equity'] as $key=>$nature) {
        $pdo->prepare("INSERT INTO ledgers(company_id,code,name,type,status,is_fixed_asset) VALUES(?,?,?,?,'active',?)")->execute([$cid,'FJ-'.$key.'-'.$suffix,$key,$nature,$key==='cost_ledger'?1:0]);$ledgers[$key]=(int)$pdo->lastInsertId();
    }
    $data=$ledgers+['operation'=>'acquisition','name'=>'Journal machine','code'=>'FAJ'.$suffix,'amount'=>1200,'method'=>'straight_line','rate'=>20,'life_months'=>60,'residual'=>0,'start_date'=>$fy['start_date']];
    $plan=faj_validate($cid,$data,$fy['start_date']);
    $pdo->prepare("INSERT INTO vouchers(company_id,fiscal_year_id,voucher_no,voucher_type,voucher_date,narration,total_amount,status,submitted_by) VALUES(?,?,?,'journal',?,'Asset test',1200,'draft',?)")->execute([$cid,$fy['id'],'FAJ-'.$suffix,$fy['start_date'],$actor]);$vid=(int)$pdo->lastInsertId();
    foreach($plan['deltas'] as $lid=>$amount) $pdo->prepare('INSERT INTO voucher_entries(voucher_id,ledger_id,entry_type,amount) VALUES(?,?,?,?)')->execute([$vid,$lid,$amount>0?'debit':'credit',abs($amount)]);
    faj_save($vid,$cid,$data);
    $q=$pdo->prepare('SELECT COUNT(*) FROM fixed_assets WHERE company_id=? AND asset_code=?');$q->execute([$cid,$data['code']]);if((int)$q->fetchColumn())throw new RuntimeException('Draft changed the register.');
    $pdo->prepare("UPDATE vouchers SET status='posted' WHERE id=?")->execute([$vid]);faj_finalize($vid,$cid);faj_finalize($vid,$cid);
    $q=$pdo->prepare('SELECT * FROM fixed_assets WHERE company_id=? AND asset_code=?');$q->execute([$cid,$data['code']]);$assets=$q->fetchAll();
    if(count($assets)!==1 || (float)$assets[0]['carrying_amount']!==1200.0) throw new RuntimeException('Posting is not idempotent.');
    if(!faj_blocker($vid))throw new RuntimeException('Posted asset voucher is mutable.');
    try{faj_validate($cid,$data,$fy['start_date']);throw new LogicException('Duplicate asset accepted');}catch(RuntimeException $e){}
    $data['operation']='depreciation';$data['asset_id']=$assets[0]['id'];$data['amount']=20;
    $plan=faj_validate($cid,$data,$fy['start_date']);
    $pdo->prepare("INSERT INTO vouchers(company_id,fiscal_year_id,voucher_no,voucher_type,voucher_date,narration,total_amount,status,submitted_by) VALUES(?,?,?,'journal',?,'Depreciation test',20,'posted',?)")->execute([$cid,$fy['id'],'FAJ-DEP-'.$suffix,$fy['start_date'],$actor]);$dep=(int)$pdo->lastInsertId();
    foreach($plan['deltas'] as $lid=>$amount) $pdo->prepare('INSERT INTO voucher_entries(voucher_id,ledger_id,entry_type,amount) VALUES(?,?,?,?)')->execute([$dep,$lid,$amount>0?'debit':'credit',abs($amount)]);
    faj_save($dep,$cid,$data);
    $q=$pdo->prepare('SELECT carrying_amount FROM fixed_assets WHERE id=?');$q->execute([$data['asset_id']]);if((float)$q->fetchColumn()!==1180.0)throw new RuntimeException('Depreciation did not update register');
    try{faj_validate($cid,$data,$fy['start_date']);throw new LogicException('Duplicate depreciation accepted');}catch(RuntimeException $e){}
    try{faj_validate($cid+100000,$data,$fy['start_date']);throw new LogicException('Foreign asset accepted');}catch(RuntimeException $e){}
    $pdo->prepare("INSERT INTO asset_class_measurement_models(company_id,asset_class,measurement_model,effective_date,created_by) VALUES(?,'ppe','revaluation',?,?) ON DUPLICATE KEY UPDATE measurement_model='revaluation'")->execute([$cid,$fy['start_date'],$actor]);
    $nextDate=date('Y-m-d',strtotime($fy['start_date'].' +1 day'));
    $data['operation']='revaluation';$data['amount']=1500;$data['evidence']='Test valuation';
    $plan=faj_validate($cid,$data,$nextDate);
    $pdo->prepare("INSERT INTO vouchers(company_id,fiscal_year_id,voucher_no,voucher_type,voucher_date,narration,total_amount,status,submitted_by) VALUES(?,?,?,'journal',?,'Revaluation test',320,'posted',?)")->execute([$cid,$fy['id'],'FAJ-REV-'.$suffix,$nextDate,$actor]);$rev=(int)$pdo->lastInsertId();
    foreach($plan['deltas'] as $lid=>$amount) $pdo->prepare('INSERT INTO voucher_entries(voucher_id,ledger_id,entry_type,amount) VALUES(?,?,?,?)')->execute([$rev,$lid,$amount>0?'debit':'credit',abs($amount)]);
    faj_save($rev,$cid,$data);
    foreach([$fy['start_date']=>1180.0,$nextDate=>1500.0] as $asof=>$expected) {
        $report=fa_register_schedule($cid,$fy['start_date'],$asof);
        $found=false;
        foreach($report as $row) if((int)$row['id']===(int)$data['asset_id']){$found=true;if(abs((float)$row['carry_closing']-$expected)>0.005)throw new RuntimeException('Asset report does not agree at '.$asof);}
        if(!$found)throw new RuntimeException('Asset missing from report');
    }
    echo "Asset journal MySQL: draft isolation, posting, replay, duplicate code, depreciation, duplicate date, tenant isolation and mutation guards passed (rolled back).\n";
} finally {if($pdo->inTransaction())$pdo->rollBack();}
