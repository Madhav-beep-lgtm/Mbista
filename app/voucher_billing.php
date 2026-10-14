<?php
declare(strict_types=1);

function voucher_billing_group(int $companyId): bool
{
    $seen = [];
    while ($companyId > 0 && !isset($seen[$companyId])) {
        $seen[$companyId] = true;
        $row = company_by_id($companyId);
        if (!$row) break;
        if (in_array(strtoupper((string)$row['code']),['MBAACA','AGHPL'],true)) return true;
        $companyId = (int)($row['parent_company_id'] ?? 0);
    }
    return false;
}

function voucher_billing_link(int $id): array
{
    if (!table_exists('voucher_billing')) return [];
    $q = db()->prepare('SELECT * FROM voucher_billing WHERE voucher_id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: [];
}

function voucher_billing_tasks(int $companyId): array
{
    $q = db()->prepare("SELECT t.id AS task_id,0 AS stage_id,t.client_id,t.title AS description,t.quoted_fee AS fee
        FROM client_tasks t WHERE t.company_id = ? AND t.status = 'completed'
        AND NOT EXISTS (SELECT 1 FROM task_invoices i WHERE i.task_id = t.id AND i.status <> 'cancelled')
        UNION ALL
        SELECT t.id,s.id,t.client_id,CONCAT(t.title,' / ',s.stage_name),s.stage_fee
        FROM task_stages s JOIN client_tasks t ON t.id=s.task_id AND t.company_id=s.company_id
        WHERE t.company_id = ? AND s.status='completed'
        AND NOT EXISTS (SELECT 1 FROM task_invoices i WHERE i.task_id=t.id AND i.status <> 'cancelled' AND (i.stage_id=s.id OR i.stage_id IS NULL))
        ORDER BY description");
    $q->execute([$companyId,$companyId]);
    return $q->fetchAll();
}

function voucher_billing_outstanding(int $companyId): array
{
    $q = db()->prepare("SELECT i.*,t.title AS task_title,
        ROUND(COALESCE(NULLIF(i.total_amount,0),i.amount)-COALESCE((SELECT SUM(p.payment_amount) FROM invoice_payment_requests p WHERE p.invoice_id=i.id AND p.status IN ('paid','partial')),0),2) AS outstanding
        FROM task_invoices i LEFT JOIN client_tasks t ON t.id=i.task_id AND t.company_id=i.company_id
        WHERE i.company_id=? AND i.status IN ('issued','paid')
        HAVING outstanding > 0 ORDER BY i.issued_on,i.id");
    $q->execute([$companyId]);
    return $q->fetchAll();
}

/** Snapshot only validated composed entries, never client-supplied totals. */
function voucher_billing_payload(array $entries): array
{
    $lines = [];
    foreach ($entries as $entry) {
        if (($entry['entry_type'] ?? '') !== 'credit' || !preg_match('/^VAT:(none|exclusive|inclusive):([0-9.]+)$/',(string)($entry['tax_code'] ?? ''),$tax)) continue;
        $base = round((float)$entry['amount'],2);
        $vat = $tax[1] === 'none' ? 0.0 : round($base*(float)$tax[2]/100,2);
        $qty = (float)($entry['quantity'] ?? 0);
        $lines[] = ['description'=>(string)$entry['memo'],'item_id'=>(int)($entry['item_id'] ?? 0),'quantity'=>$qty > 0 ? $qty : 1,'taxable'=>$base,'vat'=>$vat,'vat_rate'=>$tax[1] === 'none' ? 0 : (float)$tax[2]];
    }
    return $lines;
}

/** Called within the voucher's transaction, under a company-row lock. */
function voucher_billing_save(int $id,array $input,array $entries): void
{
    if (!db()->inTransaction()) throw new RuntimeException('Voucher billing must share the posting transaction.');
    $q = db()->prepare('SELECT * FROM vouchers WHERE id=?');
    $q->execute([$id]); $v = $q->fetch();
    $type = $v['voucher_type'];
    $group = voucher_billing_group((int)$v['company_id']);
    if ($type !== 'sales' && !($type === 'receipt' && ($group || !empty($input['billing_invoice_id'])))) {
        if ($type === 'receipt' && table_exists('voucher_billing')) {
            db()->prepare('DELETE FROM voucher_billing WHERE voucher_id=? AND company_id=? AND document_id IS NULL')->execute([$id,$v['company_id']]);
        }
        return;
    }
    if (!table_exists('voucher_billing')) throw new RuntimeException('Deploy the voucher billing database update first.');
    $old = voucher_billing_link($id);
    if (!empty($old['document_id'])) throw new RuntimeException('This voucher already has an issued document. Use a correcting voucher.');
    $taskId = 0; $stageId = 0; $invoiceId = 0;
    if ($type === 'sales' && $group) {
        $key = explode(':',(string)($input['billing_task'] ?? ''));
        $taskId = (int)($key[0] ?? 0); $stageId = (int)($key[1] ?? 0);
        if ($taskId <= 0) throw new RuntimeException('Select a completed, uninvoiced task or stage.');
    } elseif ($type === 'receipt') {
        $invoiceId = (int)($input['billing_invoice_id'] ?? 0);
        if ($invoiceId <= 0) throw new RuntimeException('Select an invoice with an outstanding amount.');
    }
    $payload = json_encode(voucher_billing_payload($entries),JSON_THROW_ON_ERROR);
    db()->prepare('INSERT INTO voucher_billing (voucher_id,company_id,kind,task_id,stage_id,invoice_id,payload) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE task_id=VALUES(task_id),stage_id=VALUES(stage_id),invoice_id=VALUES(invoice_id),payload=VALUES(payload)')
        ->execute([$id,$v['company_id'],$type,$taskId ?: null,$stageId ?: null,$invoiceId ?: null,$payload]);
    if ($v['status'] === 'posted') voucher_billing_finalize($id);
}

/** Same transaction as posting. Company lock serializes task billing and collections. */
function voucher_billing_finalize(int $id): void
{
    $link = voucher_billing_link($id);
    if (!$link || !empty($link['document_id'])) return;
    if (!db()->inTransaction()) throw new RuntimeException('Invoice and receipt creation must share the posting transaction.');
    $q = db()->prepare('SELECT * FROM vouchers WHERE id=? AND company_id=?');
    $q->execute([$id,$link['company_id']]); $v = $q->fetch();
    if (!$v || $v['status'] !== 'posted') return;
    $companyId = (int)$v['company_id'];
    $partyId = (int)($v['party_id'] ?? 0);
    if ($partyId <= 0 && (voucher_billing_group($companyId) || $link['kind'] === 'receipt')) throw new RuntimeException('Select the client party for this voucher.');
    $party = db()->prepare('SELECT * FROM accounting_parties WHERE id=? AND company_id=?');
    $party->execute([$partyId,$companyId]); $party = $party->fetch();
    if ($partyId > 0 && !$party) throw new RuntimeException('Invalid company party.');
    $actor = (int)($v['approved_by'] ?? $v['submitted_by'] ?? $v['posted_by'] ?? 0);
    if ($link['kind'] === 'sales') {
        $lines = json_decode($link['payload'],true) ?: [];
        if (!$lines) throw new RuntimeException('Invoice lines are missing.');
        $base = round(array_sum(array_column($lines,'taxable')),2);
        $tax = round(array_sum(array_column($lines,'vat')),2);
        $total = round($base+$tax,2);
        if (abs($total-(float)$v['total_amount']) > 0.005) throw new RuntimeException('Invoice total does not match the sales voucher.');
        $description = implode('; ',array_column($lines,'description'));
        if (!empty($link['task_id'])) {
            $eligible = null;
            foreach (voucher_billing_tasks($companyId) as $task) {
                if ((int)$task['task_id'] === (int)$link['task_id'] && (int)$task['stage_id'] === (int)$link['stage_id']) { $eligible = $task; break; }
            }
            if (!$eligible) throw new RuntimeException('The selected work is no longer completed and uninvoiced.');
            if ((int)($party['client_profile_id'] ?? 0) !== (int)$eligible['client_id']) throw new RuntimeException('The selected task belongs to a different client.');
            if ($base > round((float)$eligible['fee'],2)+0.005) throw new RuntimeException('Invoice exceeds the task or stage fee.');
            $description = $eligible['description'];
        }
        $q = db()->prepare("INSERT INTO task_invoices (company_id,task_id,stage_id,invoice_no,invoice_type,invoice_source_type,source_id,party_id,description,invoice_category,amount,taxable_amount,vat_rate,vat_amount,total_amount,status,issued_on,due_on,notes,issued_by) VALUES (?,?,?,?,?,?,?,?,?,'tax',?,?,?,?,?,'issued',?,?,?,?)");
        $q->execute([$companyId,$link['task_id'],$link['stage_id'],'INV-'.$companyId.'-'.$id,!empty($link['stage_id']) ? 'stage' : (!empty($link['task_id']) ? 'task' : 'other'),!empty($link['task_id']) ? 'task' : 'other',$id,$partyId ?: null,mb_substr($description,0,255),$base,$base,$base > 0 ? round($tax/$base*100,2) : 0,$tax,$total,$v['voucher_date'],$v['due_date'] ?? null,$v['narration'],$actor ?: null]);
        $documentId = (int)db()->lastInsertId();
        foreach ($lines as $line) {
            db()->prepare("INSERT INTO invoice_line_items (invoice_id,item_id,source_type,description,quantity,rate,taxable_amount,vat_rate,vat_amount,total_amount) VALUES (?,?,'other',?,?,?,?,?,?,?)")
                ->execute([$documentId,$line['item_id'] ?: null,mb_substr(!empty($link['task_id']) ? $description : $line['description'],0,255),$line['quantity'],round($line['taxable']/$line['quantity'],2),$line['taxable'],$line['vat_rate'],$line['vat'],$line['taxable']+$line['vat']]);
        }
        // Immediate cash settlements are already in this voucher: record, never post again.
        $directory = voucher_ledger_directory($companyId);
        $paid = 0.0;
        $q = db()->prepare("SELECT ledger_id,amount FROM voucher_entries WHERE voucher_id=? AND entry_type='debit'");
        $q->execute([$id]);
        foreach ($q->fetchAll() as $entry) if (!empty($directory[(int)$entry['ledger_id']]['roles']['cash_bank'])) $paid += (float)$entry['amount'];
        if ($paid > 0) voucher_billing_payment($v,$documentId,min($paid,$total),'Sales voucher settlement');
        if ($paid >= $total-0.005) db()->prepare("UPDATE task_invoices SET status='paid' WHERE id=?")->execute([$documentId]);
        db()->prepare('UPDATE vouchers SET narration=? WHERE id=?')->execute([trim($description . "\n" . $v['narration']),$id]);
    } else {
        $invoice = null;
        foreach (voucher_billing_outstanding($companyId) as $candidate) if ((int)$candidate['id'] === (int)$link['invoice_id']) { $invoice=$candidate; break; }
        if (!$invoice || invoice_party_id($invoice,$companyId) !== $partyId) throw new RuntimeException('Select an outstanding invoice for this client.');
        $amount = round((float)$v['total_amount'],2);
        if ($amount <= 0 || $amount > (float)$invoice['outstanding']+0.005) throw new RuntimeException('Receipt exceeds the outstanding invoice amount.');
        $receivable = ensure_party_ledger($companyId,$partyId,'receivable');
        $q = db()->prepare("SELECT ledger_id,entry_type,amount FROM voucher_entries WHERE voucher_id=?");
        $q->execute([$id]); $directory = voucher_ledger_directory($companyId); $credit=0.0; $cash=0.0;
        foreach ($q->fetchAll() as $entry) {
            if ($entry['entry_type'] === 'credit' && (int)$entry['ledger_id'] === $receivable) $credit += (float)$entry['amount'];
            elseif ($entry['entry_type'] === 'debit' && !empty($directory[(int)$entry['ledger_id']]['roles']['cash_bank'])) $cash += (float)$entry['amount'];
            else throw new RuntimeException('A task receipt must debit cash/bank and credit the selected client receivable.');
        }
        if (abs($credit-$amount)>0.005 || abs($cash-$amount)>0.005) throw new RuntimeException('Receipt allocation does not match the journal.');
        $description = $invoice['invoice_no'].' — '.($invoice['task_title'] ?: $invoice['description']);
        $documentId = voucher_billing_payment($v,(int)$invoice['id'],$amount,$description);
        if ($amount >= (float)$invoice['outstanding']-0.005) db()->prepare("UPDATE task_invoices SET status='paid' WHERE id=?")->execute([$invoice['id']]);
        db()->prepare('UPDATE vouchers SET narration=? WHERE id=?')->execute([trim($description . "\n" . $v['narration']),$id]);
    }
    db()->prepare('UPDATE voucher_billing SET document_id=? WHERE voucher_id=?')->execute([$documentId,$id]);
}

function voucher_billing_payment(array $v,int $invoiceId,float $amount,string $description): int
{
    $actor = (int)($v['approved_by'] ?? $v['submitted_by'] ?? $v['posted_by'] ?? 0);
    db()->prepare("INSERT INTO invoice_payment_requests (invoice_id,company_id,requested_by,amount_requested,payment_method,status,payment_received_on,payment_amount,notes,source_voucher_id) VALUES (?,?,?,?,'Voucher','paid',?,?,?,?)")
        ->execute([$invoiceId,$v['company_id'],$actor,$amount,$v['voucher_date'],$amount,$description,$v['id']]);
    $paymentId = (int)db()->lastInsertId();
    $q = db()->prepare('SELECT t.client_id FROM task_invoices i LEFT JOIN client_tasks t ON t.id=i.task_id AND t.company_id=i.company_id WHERE i.id=? AND i.company_id=?');
    $q->execute([$invoiceId,$v['company_id']]); $clientId=(int)$q->fetchColumn();
    db()->prepare("INSERT INTO invoice_payment_receipts (payment_request_id,invoice_id,company_id,client_id,receipt_no,amount_received,payment_method,payment_reference,received_on,notes,created_by) VALUES (?,?,?,?,?,?,'Voucher',?,?,?,?)")
        ->execute([$paymentId,$invoiceId,$v['company_id'],$clientId ?: null,'RC-'.$v['company_id'].'-'.$v['id'],$amount,$v['reference_no'] ?? null,$v['voucher_date'],$description,$actor ?: null]);
    return $paymentId;
}

function voucher_billing_notify(int $id): void
{
    try {
    $link = voucher_billing_link($id);
    if (!$link || empty($link['document_id'])) return;
    if ($link['kind'] === 'sales') {
        $reason=auto_post_client_mirror_invoice((int)$link['document_id'],(int)current_user()['id']);
        if ($reason !== null) flash('info','Invoice created. Client approval notification unavailable: '.$reason);
    }
    $payment=db()->prepare('SELECT id FROM invoice_payment_requests WHERE source_voucher_id=? AND company_id=?');
    $payment->execute([$id,$link['company_id']]);
    if ($paymentId=(int)$payment->fetchColumn()) auto_post_client_mirror_payment($paymentId,(int)current_user()['id']);
    } catch (Throwable $exception) {
        error_log('Voucher billing notification failed for voucher '.$id.': '.$exception->getMessage());
        flash('info','Voucher saved. The client approval notification could not be delivered; please contact the administrator.');
    }
}

/** Unified admin posting transaction for source documents and their journals. */
function voucher_billing_approve(int $id,int $companyId,int $actor): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE');
        $lock->execute([$companyId]);
        $q = $pdo->prepare("SELECT * FROM vouchers WHERE id=? AND company_id=? AND status='draft' AND approval_state='pending_approval' FOR UPDATE");
        $q->execute([$id,$companyId]); $v=$q->fetch();
        if (!$v) throw new RuntimeException('Voucher is no longer pending approval.');
        $blocker = client_mirror_admin_approval_blocker($v);
        if ($blocker !== null) throw new RuntimeException($blocker);
        $fy = fiscal_year_by_id((int)$v['fiscal_year_id']);
        if (!$fy || (int)$fy['company_id'] !== $companyId) throw new RuntimeException('Fiscal year unavailable.');
        $blocker = fiscal_year_posting_blocker($fy,(string)$v['voucher_date']);
        if ($blocker !== null) throw new RuntimeException($blocker);
        if (is_period_locked($companyId,(int)$v['fiscal_year_id'],(string)$v['voucher_date'])) throw new RuntimeException('Accounting period is locked.');
        $q=$pdo->prepare('SELECT e.entry_type,e.amount,l.company_id FROM voucher_entries e JOIN ledgers l ON l.id=e.ledger_id WHERE e.voucher_id=?');
        $q->execute([$id]); $balance=0; $count=0;
        foreach ($q->fetchAll() as $line) {
            if ((int)$line['company_id'] !== $companyId || !in_array($line['entry_type'],['debit','credit'],true) || (float)$line['amount'] <= 0) throw new RuntimeException('Invalid journal line.');
            $balance += ($line['entry_type'] === 'debit' ? 1 : -1)*(int)round((float)$line['amount']*100); $count++;
        }
        if ($count<2 || $balance!==0) throw new RuntimeException('Journal must balance before posting.');
        $pdo->prepare("UPDATE vouchers SET status='posted',approval_state='approved',approved_by=?,approved_at=CURRENT_TIMESTAMP,posted_by=?,posted_at=CURRENT_TIMESTAMP,rejection_reason=NULL WHERE id=? AND company_id=?")->execute([$actor,$actor,$id,$companyId]);
        voucher_billing_finalize($id);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    voucher_billing_notify($id);
}
