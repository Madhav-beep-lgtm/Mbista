<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/hospitality_engine.php';
if (!in_array(DB_HOST,['localhost','127.0.0.1'],true)) throw new RuntimeException('Local database only');
require __DIR__.'/../app/business_notes_schema.php';
$pdo=db();
$fy=$pdo->query('SELECT f.* FROM fiscal_years f WHERE (SELECT COUNT(*) FROM ledgers l WHERE l.company_id=f.company_id)>=3 ORDER BY f.id DESC LIMIT 1')->fetch();
if (!$fy) throw new RuntimeException('Local accounting fixture required');
$cid=(int)$fy['company_id']; $date=$fy['start_date'];
$actor=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$q=$pdo->prepare('SELECT id FROM ledgers WHERE company_id=? ORDER BY id LIMIT 3');
$q->execute([$cid]); $ledgers=$q->fetchAll(PDO::FETCH_COLUMN);
$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE fiscal_years SET status='open',is_active=1 WHERE id=?")->execute([$fy['id']]);
    update_settings(['company_business_type'=>'hospitality'],$cid);
    if (!company_module_enabled('inventory',$cid)) throw new RuntimeException('Fixture requires inventory enabled');
    $suffix=bin2hex(random_bytes(5));
    $pdo->prepare('INSERT INTO warehouses(company_id,name,code) VALUES(?,?,?)')->execute([$cid,'Return test '.$suffix,'RT'.$suffix]);
    $warehouse=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventory_items(company_id,ledger_id,sku,name,unit,item_type,status,allow_negative_stock,default_warehouse_id) VALUES(?,?,?,'Return ingredient','kg','stock','active',0,?)")
        ->execute([$cid,$ledgers[0],'RT'.$suffix,$warehouse]);
    $item=(int)$pdo->lastInsertId();
    $make=function(bool $stock=true)use($pdo,$cid,$fy,$date,$ledgers,$warehouse,$item,$actor):int {
        $id=create_voucher_with_entries(['company_id'=>$cid,'fiscal_year_id'=>$fy['id'],'voucher_date'=>$date,
            'voucher_no'=>'PR'.bin2hex(random_bytes(5)),'voucher_type'=>'purchase','source_type'=>'voucher_form','total_amount'=>113,'status'=>'posted'],[
                ['ledger_id'=>$ledgers[0],'entry_type'=>'debit','amount'=>100,'memo'=>'Ingredient / service'],
                ['ledger_id'=>$ledgers[1],'entry_type'=>'debit','amount'=>13,'memo'=>'VAT'],
                ['ledger_id'=>$ledgers[2],'entry_type'=>'credit','amount'=>113,'memo'=>'Supplier'],
            ]);
        if ($stock) {
            $pdo->prepare('UPDATE voucher_entries SET item_id=?,quantity=2 WHERE voucher_id=? AND ledger_id=?')
                ->execute([$item,$id,$ledgers[0]]);
            voucher_stock_sync($cid,(int)$fy['id'],['id'=>$id,'voucher_type'=>'purchase','status'=>'posted','voucher_date'=>$date,'voucher_no'=>'PR'.$id,'warehouse_id'=>$warehouse],$actor,true);
        }
        return $id;
    };
    $original=$make();
    $note=business_note_create($cid,$original,'debit_note',$date,'Supplier return',$actor,false);
    $ingredient=['company_id'=>$cid,'inventory_item_id'=>$item];
    if (hospitality_ingredient_cost_on($ingredient,$date,'latest_purchase')!==0.0) throw new RuntimeException('Returned purchase still supplies recipe cost');
    $q=$pdo->prepare('SELECT qty_out,warehouse_id,amount FROM inventory_transactions WHERE source_voucher_id=? AND company_id=?');
    $q->execute([$note,$cid]); $move=$q->fetch();
    if (!$move || (float)$move['qty_out']!==2.0 || (int)$move['warehouse_id']!==$warehouse || (float)$move['amount']!==100.0) throw new RuntimeException('Original purchase warehouse/quantity/value not returned');
    business_note_finalize($note);
    $q=$pdo->prepare('SELECT COUNT(*) FROM inventory_transactions WHERE source_voucher_id=?'); $q->execute([$note]);
    if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Return replay duplicated stock');
    $q=$pdo->prepare('SELECT SUM(CASE WHEN entry_type=\'debit\' THEN amount ELSE -amount END) FROM voucher_entries WHERE voucher_id IN (?,?)');
    $q->execute([$original,$note]); if (abs((float)$q->fetchColumn())>0.001) throw new RuntimeException('Return journal does not balance');
    $q=$pdo->prepare('SELECT SUM(quantity) FROM voucher_entries WHERE voucher_id=? AND item_id=?'); $q->execute([$note,$item]);
    if ((float)$q->fetchColumn()!==2.0) throw new RuntimeException('Return lost item metadata');
    // Service purchases reverse their journals without introducing stock.
    foreach (['service','internal_service','trading_manufacturing','hospitality'] as $profile) {
        update_settings(['company_business_type'=>$profile],$cid);
        $service=business_note_create($cid,$make(false),'debit_note',$date,'Service adjustment',$actor,false);
        $q=$pdo->prepare('SELECT COUNT(*) FROM inventory_transactions WHERE source_voucher_id=?'); $q->execute([$service]);
        if ((int)$q->fetchColumn()!==0) throw new RuntimeException('Service return moved stock');
    }
    update_settings(['company_business_type'=>'hospitality'],$cid);
    $pending=business_note_create($cid,$make(),'debit_note',$date,'Pending return',$actor,true);
    if (hospitality_ingredient_cost_on($ingredient,$date,'latest_purchase')!==50.0) throw new RuntimeException('Pending return removed purchase cost');
    $q->execute([$pending]); if ((int)$q->fetchColumn()!==0) throw new RuntimeException('Pending return moved stock');
    $pdo->prepare("UPDATE vouchers SET status='posted',approval_state='approved' WHERE id=?")->execute([$pending]);
    business_note_finalize($pending); $q->execute([$pending]);
    if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Approved return did not move stock');
    if (hospitality_ingredient_cost_on($ingredient,$date,'latest_purchase')!==0.0) throw new RuntimeException('Approved return retained purchase cost');
    // Remove stock from the source warehouse inside the rollback-only fixture.
    $original=$make();
    $pdo->prepare("INSERT INTO inventory_transactions(company_id,fiscal_year_id,item_id,warehouse_id,transaction_type,transaction_date,qty_out,rate,amount) VALUES(?,?,?,?,'consume',?,2,50,100)")
        ->execute([$cid,$fy['id'],$item,$warehouse,$date]);
    $pdo->exec('SAVEPOINT failed_return');
    try {
        business_note_create($cid,$original,'debit_note',$date,'Unavailable stock',$actor,false);
        throw new LogicException('Unavailable stock return accepted');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(),'Insufficient stock')) throw $e;
        $pdo->exec('ROLLBACK TO SAVEPOINT failed_return');
    }
    $q=$pdo->prepare('SELECT COUNT(*) FROM business_voucher_notes WHERE original_voucher_id=?'); $q->execute([$original]);
    if ((int)$q->fetchColumn()!==0) throw new RuntimeException('Failed return left a note');
    echo "Purchase returns: source warehouse, quantities, VAT journal, metadata, replay, four profiles, approval and insufficient-stock rollback passed (fixtures rolled back).\n";
} finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
