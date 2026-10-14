<?php
declare(strict_types=1);

function business_voucher_finalize(int $id,int $companyId,int $actor): void
{
    business_note_finalize($id);
    $q=db()->prepare('SELECT * FROM vouchers WHERE id=? AND company_id=?');
    $q->execute([$id,$companyId]); $v=$q->fetch();
    if ($v && ($v['source_type'] ?? '')==='voucher_form') voucher_stock_sync($companyId,(int)$v['fiscal_year_id'],$v,$actor,true);
}

/** A return is an additional document, never deletion of its original posting. */
function business_note_blocker(int $voucherId): ?string
{
    if (!table_exists('business_voucher_notes')) return null;
    $q=db()->prepare('SELECT voucher_id FROM business_voucher_notes WHERE voucher_id=? OR original_voucher_id=? LIMIT 1');
    $q->execute([$voucherId,$voucherId]);
    return $q->fetchColumn() ? 'This voucher is linked to a return note and cannot be edited or unposted.' : null;
}

function business_note_source_allowed(string $business,string $type,array $source): bool
{
    if (($source['status'] ?? '')!=='posted' || !in_array($type,['credit_note','debit_note'],true)) return false;
    if (($source['voucher_type'] ?? '')!==($type==='credit_note' ? 'sales' : 'purchase')) return false;
    if ($type==='debit_note' && in_array($business,['service','internal_service','trading_manufacturing','hospitality'],true)) {
        return ($source['source_type'] ?? '')==='voucher_form';
    }
    return $business==='jewellery'
        ? ($source['source_type'] ?? '')===($type==='credit_note' ? 'jewellery_sale' : 'jewellery_purchase')
        : ($business==='hospitality' && $type==='credit_note' && ($source['source_type'] ?? '')==='hospitality_sales_upload');
}

function business_note_reverse_entries(array $entries): array
{
    $result=[]; $balance=0;
    foreach($entries as $entry) {
        if (!in_array($entry['entry_type'],['debit','credit'],true) || (float)$entry['amount']<=0) throw new RuntimeException('Invalid source journal.');
        $balance+=($entry['entry_type']==='debit' ? 1 : -1)*(int)round((float)$entry['amount']*100);
        $result[]=['ledger_id'=>(int)$entry['ledger_id'],'entry_type'=>$entry['entry_type']==='debit' ? 'credit' : 'debit',
            'amount'=>(float)$entry['amount'],'memo'=>mb_substr('Return: '.(string)($entry['memo'] ?? ''),0,255)];
    }
    if (count($result)<2 || $balance!==0) throw new RuntimeException('Source journal must balance.');
    return $result;
}

/** Include dated return rows without rewriting or hiding historical invoices. */
function business_note_report_rows(string $sql,array $params,string $alias,string $dateColumn,string $dateKey,string $numberKey): array
{
    $q=db()->prepare($sql); $q->execute($params); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if (!table_exists('business_voucher_notes')) return $rows;
    $returnSql=preg_replace('/SELECT /','SELECT bn_v.voucher_date AS return_date, bn_v.voucher_no AS return_no, ',$sql,1);
    $join=" JOIN business_voucher_notes bn ON bn.original_voucher_id={$alias}.voucher_id AND bn.company_id={$alias}.company_id AND bn.applied_at IS NOT NULL JOIN vouchers bn_v ON bn_v.id=bn.voucher_id AND bn_v.status='posted' ";
    $returnSql=preg_replace('/\bWHERE\b/',$join.' WHERE',$returnSql,1);
    $returnSql=str_replace("{$alias}.{$dateColumn} BETWEEN :from AND :to",'bn_v.voucher_date BETWEEN :from AND :to',$returnSql);
    $q=db()->prepare($returnSql); $q->execute($params);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row[$dateKey]=$row['return_date']; $row[$numberKey]=$row['return_no'];
        foreach(['qty_pieces','gross_weight','fine_weight','metal_amount','wastage_amount','making_amount','stone_amount','diamond_amount','other_diamond_amount','vat_amount','tax_amount','allocated_adjust','line_total','cogs_amount','stock_amount','amount'] as $key) {
            if (isset($row[$key])) $row[$key]=-(float)$row[$key];
        }
        unset($row['return_date'],$row['return_no']); $rows[]=$row;
    }
    usort($rows,static fn(array $a,array $b):int=>strcmp((string)$a[$dateKey],(string)$b[$dateKey]));
    return $rows;
}

function business_note_create(int $companyId,int $originalId,string $type,string $date,string $reason,int $actor,bool $pending): int
{
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if (!$parsed || $parsed->format('Y-m-d')!==$date) throw new RuntimeException('Enter a valid return date.');
    if (trim($reason)==='') throw new RuntimeException('Enter the return reason.');
    if (!table_exists('business_voucher_notes')) throw new RuntimeException('Deploy the return-note migration first.');
    $pdo=db(); $owns=!$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE')->execute([$companyId]);
        $q=$pdo->prepare('SELECT * FROM vouchers WHERE id=? AND company_id=? FOR UPDATE');
        $q->execute([$originalId,$companyId]); $source=$q->fetch();
        $business=company_business_type($companyId);
        if (!$source || !business_note_source_allowed($business,$type,$source)) throw new RuntimeException('Select a posted original document for this business type.');
        if ($date<(string)$source['voucher_date']) throw new RuntimeException('Return date cannot precede the original document.');
        // A rejected request releases the original; posted notes remain immutable.
        $pdo->prepare("DELETE n FROM business_voucher_notes n JOIN vouchers v ON v.id=n.voucher_id WHERE n.original_voucher_id=? AND n.company_id=? AND n.applied_at IS NULL AND v.approval_state='rejected'")->execute([$originalId,$companyId]);
        if (business_note_blocker($originalId)!==null) throw new RuntimeException('This original already has a posted or pending return note.');
        $fy=fiscal_year_for_date($companyId,$date);
        if (!$fy || is_period_locked($companyId,(int)$fy['id'],$date)) throw new RuntimeException('Select an open accounting period.');
        $q=$pdo->prepare('SELECT e.* FROM voucher_entries e JOIN ledgers l ON l.id=e.ledger_id AND l.company_id=? WHERE e.voucher_id=? ORDER BY e.id');
        $q->execute([$companyId,$originalId]); $sourceEntries=$q->fetchAll(); $entries=business_note_reverse_entries($sourceEntries);
        $id=create_voucher_with_entries(['company_id'=>$companyId,'fiscal_year_id'=>$fy['id'],
            'voucher_no'=>($type==='credit_note' ? 'CN-' : 'DN-').$originalId.'-'.strtoupper(bin2hex(random_bytes(3))),
            'voucher_type'=>$type,'voucher_date'=>$date,'source_type'=>'business_return','source_id'=>null,
            'party_id'=>$source['party_id'],'reference_no'=>$source['voucher_no'],'total_amount'=>$source['total_amount'],
            'narration'=>'Full return of '.$source['voucher_no'].': '.trim($reason),
            'status'=>$pending ? 'draft' : 'posted','approval_state'=>$pending ? 'pending_approval' : 'approved',
            'submitted_by'=>$actor,'approved_by'=>$pending ? null : $actor,'posted_by'=>$pending ? null : $actor,
            'requires_client_approval'=>$pending && function_exists('staff_accountant_forces_approval') && staff_accountant_forces_approval() ? 1 : 0],$entries);
        $q=$pdo->prepare('SELECT id FROM voucher_entries WHERE voucher_id=? ORDER BY id');$q->execute([$id]);
        $entryIds=$q->fetchAll(PDO::FETCH_COLUMN);
        foreach(['cost_centre','tax_code','line_reference','item_id','quantity','bill_amount','bill_due_date'] as $column) {
            if (!column_exists('voucher_entries',$column)) continue;
            $copy=$pdo->prepare('UPDATE voucher_entries SET '.$column.'=? WHERE id=? AND voucher_id=?');
            foreach($entryIds as $index=>$entryId) $copy->execute([$sourceEntries[$index][$column] ?? null,$entryId,$id]);
        }
        $pdo->prepare('INSERT INTO business_voucher_notes(voucher_id,company_id,original_voucher_id,business_type) VALUES(?,?,?,?)')->execute([$id,$companyId,$originalId,$business]);
        business_note_finalize($id);
        if ($owns) $pdo->commit();
        return $id;
    } catch(Throwable $e) { if($owns && $pdo->inTransaction())$pdo->rollBack(); throw $e; }
}

function business_note_finalize(int $id): void
{
    if (!table_exists('business_voucher_notes')) return;
    $q=db()->prepare('SELECT n.*,v.status,v.voucher_date,v.voucher_no,v.approved_by,v.submitted_by FROM business_voucher_notes n JOIN vouchers v ON v.id=n.voucher_id WHERE n.voucher_id=? FOR UPDATE');
    $q->execute([$id]); $note=$q->fetch();
    if (!$note || $note['applied_at'] || $note['status']!=='posted') return;
    if (!db()->inTransaction()) throw new RuntimeException('Return posting must be atomic.');
    $q=db()->prepare("SELECT * FROM vouchers WHERE id=? AND company_id=? AND status='posted' FOR UPDATE");
    $q->execute([$note['original_voucher_id'],$note['company_id']]); $source=$q->fetch();
    if (!$source) throw new RuntimeException('The original document is no longer posted.');
    if ($note['business_type']==='jewellery') business_note_jewellery($note,$source);
    elseif ($source['voucher_type']==='purchase') business_note_purchase($note,$source);
    else business_note_hospitality($note,$source);
    db()->prepare('UPDATE business_voucher_notes SET applied_at=CURRENT_TIMESTAMP WHERE voucher_id=?')->execute([$id]);
}

/** Purchase returns share the normal stock engine and never post a second payable. */
function business_note_purchase(array $note,array $source): void
{
    $cid=(int)$note['company_id'];
    $lines=voucher_stock_item_lines((int)$note['voucher_id']);
    $q=db()->prepare('SELECT item_id,warehouse_id,SUM(qty_in-qty_out) AS quantity
        FROM inventory_transactions WHERE company_id=? AND source_voucher_id=?
        GROUP BY item_id,warehouse_id');
    $q->execute([$cid,$source['id']]); $movements=$q->fetchAll();
    if ($lines===[] && $movements===[]) return; // Service purchase: journal only.
    $warehouses=[]; $originalQty=[];
    foreach($movements as $move) {
        $warehouses[(int)$move['warehouse_id']]=true;
        $originalQty[(int)$move['item_id']]=($originalQty[(int)$move['item_id']] ?? 0)+(float)$move['quantity'];
    }
    $returnQty=[];
    foreach($lines as $line) $returnQty[(int)$line['item_id']]=($returnQty[(int)$line['item_id']] ?? 0)+(float)$line['quantity'];
    if (count($warehouses)!==1 || count($originalQty)!==count($returnQty)) throw new RuntimeException('The original purchase stock does not match its voucher. Reconcile it before returning.');
    foreach($returnQty as $itemId=>$qty) {
        if (abs($qty-($originalQty[$itemId] ?? 0))>0.0001) throw new RuntimeException('The original purchase quantity does not match its stock movement.');
    }
    $warehouse=(int)array_key_first($warehouses);
    if ($warehouse<=0) throw new RuntimeException('The original purchase has no receiving warehouse. Reconcile it before returning.');
    // Lock the items before checking their original warehouse, including concurrent returns.
    $itemIds=array_keys($returnQty); sort($itemIds);
    $marks=implode(',',array_fill(0,count($itemIds),'?'));
    $lock=db()->prepare("SELECT id FROM inventory_items WHERE company_id=? AND id IN ($marks) ORDER BY id FOR UPDATE");
    $lock->execute(array_merge([$cid],$itemIds));
    $q=db()->prepare("SELECT item_id,SUM(qty_in-qty_out) AS qty FROM inventory_transactions
        WHERE company_id=? AND warehouse_id=? AND transaction_date<=? AND item_id IN ($marks) GROUP BY item_id");
    $q->execute(array_merge([$cid,$warehouse,$note['voucher_date']],$itemIds)); $available=$q->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach($returnQty as $itemId=>$qty) if ($qty>(float)($available[$itemId] ?? 0)+0.0001) throw new RuntimeException('Insufficient stock in the original receiving warehouse.');
    $stockValue=db()->prepare("SELECT COALESCE(SUM(qty_remaining*unit_cost),0) FROM inventory_cost_layers WHERE company_id=? AND item_id IN ($marks)");
    foreach($itemIds as $itemId) inv_rebuild_item($cid,$itemId);
    $stockValue->execute(array_merge([$cid],$itemIds)); $before=(float)$stockValue->fetchColumn();
    $fy=fiscal_year_for_date($cid,$note['voucher_date']);
    $notes=voucher_stock_sync($cid,(int)$fy['id'],[
        'id'=>$note['voucher_id'],'voucher_type'=>'debit_note','status'=>'posted',
        'voucher_date'=>$note['voucher_date'],'voucher_no'=>$note['voucher_no'],
        'warehouse_id'=>$warehouse,'party_id'=>$source['party_id'],
    ],(int)($note['approved_by'] ?? $note['submitted_by']),true);
    $stockValue->execute(array_merge([$cid],$itemIds)); $after=(float)$stockValue->fetchColumn();
    if (abs(round($before-$after,2)-round(array_sum(array_column($lines,'amount')),2))>0.01) throw new RuntimeException('Stock valuation differs from the original purchase. Review the return valuation before posting.');
    foreach($notes as $warning) if (str_contains($warning,'check the return price')) throw new RuntimeException('Stock valuation differs from the original purchase. Review the return valuation before posting.');
}

function business_note_jewellery(array $note,array $source): void
{
    require_once __DIR__.'/jewellery_trade.php';
    require_once __DIR__.'/jewellery_trace.php';
    $cid=(int)$note['company_id']; $sourceId=(int)$source['source_id']; $actor=(int)($note['approved_by'] ?? $note['submitted_by']);
    $q=db()->prepare('SELECT * FROM jewellery_bills WHERE company_id=? AND voucher_id=? FOR UPDATE');
    $q->execute([$cid,$source['id']]); $bills=$q->fetchAll();
    foreach($bills as $bill) if ((float)$bill['settled_amount']>0.005) throw new RuntimeException('Reverse subsequent bill settlements before returning this document.');
    if ($source['source_type']==='jewellery_sale' && jewellery_sale_advance_allocations($cid,$sourceId)!==[]) throw new RuntimeException('Release the sale advance allocations before making a full return.');
    $q=db()->prepare('SELECT * FROM jewellery_stock_txns WHERE company_id=? AND voucher_id=? ORDER BY id FOR UPDATE');
    $q->execute([$cid,$source['id']]); $movements=$q->fetchAll();
    if (!$movements) throw new RuntimeException('The original document has no stock movements to return.');
    // Physical pieces leaving the shop must still be present, not sold or reserved.
    $q=db()->prepare("SELECT * FROM jewellery_stock_units WHERE company_id=? AND ((origin_type=? AND origin_id=?) OR sold_sale_id=?) FOR UPDATE");
    $sale=$source['source_type']==='jewellery_sale';
    $q->execute([$cid,$sale ? 'sale_exchange' : 'purchase',$sourceId,$sale ? $sourceId : 0]);
    $units=$q->fetchAll();
    foreach($units as $unit) {
        $incoming=$sale && (int)$unit['sold_sale_id']===$sourceId;
        if (!$incoming && ($unit['status']!=='in_stock' || $unit['current_holder_type']!=='stock')) throw new RuntimeException('Trace '.$unit['trace_code'].' is no longer available for return.');
        if ($incoming && !in_array($unit['status'],['sold','delivered'],true)) throw new RuntimeException('Trace '.$unit['trace_code'].' has already changed since this sale.');
        $changes=$incoming ? ['status'=>'in_stock','current_holder_type'=>'stock','current_holder_id'=>null,'sold_sale_id'=>null,'sold_sale_line_id'=>null,'reserved_sale_id'=>null,'reserved_sale_line_id'=>null,'reserved_order_id'=>null,'reserved_order_line_id'=>null] : ['status'=>'returned','current_holder_type'=>$sale ? 'customer' : 'supplier','current_holder_id'=>$source['party_id']];
        $result=jewellery_trace_transition($cid,(int)$unit['id'],'voucher_return',$changes,['event_date'=>$note['voucher_date'],'source_type'=>'business_return','source_id'=>$note['voucher_id'],'reference_no'=>$note['voucher_no']],$actor);
        if (empty($result['ok'])) throw new RuntimeException((string)$result['error']);
    }
    foreach($movements as $move) {
        $move['direction']=$move['direction']==='in' ? 'out' : 'in';
        $move['txn_type']=$move['direction']==='in' ? 'sales_return' : 'purchase_return';
        $move['txn_date']=$note['voucher_date']; $move['ref_no']=$note['voucher_no'];
        $move['source_type']='business_return'; $move['source_id']=$note['voucher_id'];
        $move['voucher_id']=$note['voucher_id']; $move['created_by']=$actor;
        jw_record_stock_txn($cid,$move);
    }
    db()->prepare("UPDATE jewellery_bills SET status='cancelled' WHERE company_id=? AND voucher_id=?")->execute([$cid,$source['id']]);
}

function business_note_hospitality(array $note,array $source): void
{
    // Keep the original upload intact; negative lines are the return document.
    $cid=(int)$note['company_id'];
    $q=db()->prepare('SELECT COUNT(*) AS rows_count,SUM(gross_amount) AS gross,SUM(discount) AS discount,SUM(vat_amount) AS vat,SUM(taxable_amount) AS taxable FROM hospitality_sales_upload_lines WHERE company_id=? AND voucher_id=?');
    $q->execute([$cid,$source['id']]); $totals=$q->fetch();
    if (!(int)$totals['rows_count']) throw new RuntimeException('The original hospitality voucher has no menu lines.');
    $fy=fiscal_year_for_date($cid,$note['voucher_date']);
    db()->prepare("INSERT INTO hospitality_sales_uploads(company_id,fiscal_year_id,file_name,date_from,date_to,row_count,voucher_count,gross_amount,discount_amount,vat_amount,taxable_amount,receivable_amount,status,posted_by,posted_at) VALUES(?,?,?,?,?,?,1,?,?,?,?,?,'posted',?,CURRENT_TIMESTAMP)")
        ->execute([$cid,$fy['id'],$note['voucher_no'],$note['voucher_date'],$note['voucher_date'],$totals['rows_count'],-(float)$totals['gross'],-(float)$totals['discount'],-(float)$totals['vat'],-(float)$totals['taxable'],-(float)$source['total_amount'],$note['approved_by'] ?? $note['submitted_by']]);
    $uploadId=(int)db()->lastInsertId();
    db()->prepare("INSERT INTO hospitality_sales_upload_lines(upload_id,company_id,sale_date,category,item_name,qty,gross_amount,discount,vat_amount,taxable_amount,sales_ledger_id,ledger_source,voucher_id)
        SELECT ?,company_id,?,category,item_name,-qty,-gross_amount,-discount,-vat_amount,-taxable_amount,sales_ledger_id,ledger_source,? FROM hospitality_sales_upload_lines WHERE company_id=? AND voucher_id=?")
        ->execute([$uploadId,$note['voucher_date'],$note['voucher_id'],$cid,$source['id']]);
    db()->prepare("INSERT INTO hospitality_sales_invoice_lines(upload_id,company_id,sale_date,invoice_no,payment_type,ledger_code,ledger_id,gross_amount,discount,taxable_amount,vat_amount,total_amount,voucher_id)
        SELECT ?,company_id,?,?,payment_type,ledger_code,ledger_id,-gross_amount,-discount,-taxable_amount,-vat_amount,-total_amount,? FROM hospitality_sales_invoice_lines WHERE company_id=? AND voucher_id=?")
        ->execute([$uploadId,$note['voucher_date'],$note['voucher_no'],$note['voucher_id'],$cid,$source['id']]);
}
