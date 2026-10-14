<?php
declare(strict_types=1);
require_permission('accounting','create');
if (in_array($businessType,['hospitality','jewellery'],true)) {
    require_permission($businessType,'view');
    require_permission($businessType,$businessType==='jewellery' ? 'edit' : 'create');
}
$type=$requestedType;
$title=$type==='credit_note' ? 'Credit note — sales return' : 'Debit note — purchase return';
$sourceType=$businessType==='jewellery' ? ($type==='credit_note' ? 'jewellery_sale' : 'jewellery_purchase') : ($type==='debit_note' ? 'voucher_form' : 'hospitality_sales_upload');
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        if (($_POST['confirm_full_return'] ?? '')!=='1') throw new RuntimeException('Confirm that this is a full-document return.');
        if ((int)($_POST['context_fiscal_year_id'] ?? 0)!==$fiscalYearId) throw new RuntimeException('Fiscal year changed. Reload the note.');
        $pending=staff_accountant_forces_approval() || ((approvals_enabled() || client_portal_forces_approval()) && !user_can('approve'));
        if ($businessType==='jewellery' && !user_can_do('jewellery','post')) $pending=true;
        $id=business_note_create($companyId,(int)($_POST['original_id'] ?? 0),$type,(string)($_POST['voucher_date'] ?? ''),(string)($_POST['reason'] ?? ''),(int)current_user()['id'],$pending);
        log_activity('voucher',$id,'created','Source-linked full return note created.',(int)current_user()['id']);
        flash('success',$pending ? 'Return note submitted for approval.' : 'Return note posted.');
        redirect('admin/voucher-view.php?id='.$id);
    } catch(Throwable $exception) { $error=$exception instanceof PDOException ? 'Could not save the return note.' : $exception->getMessage(); }
}
$selectedId=(int)($_POST['original_id'] ?? $_GET['original_id'] ?? 0);
$q=db()->prepare("SELECT v.id,v.voucher_no,v.voucher_date,v.total_amount FROM vouchers v
    WHERE v.company_id=? AND v.source_type=? AND v.voucher_type=? AND v.status='posted'
    AND NOT EXISTS (SELECT 1 FROM business_voucher_notes n JOIN vouchers r ON r.id=n.voucher_id
        WHERE n.original_voucher_id=v.id AND (n.applied_at IS NOT NULL OR r.approval_state<>'rejected'))
    ORDER BY (v.id=?) DESC,v.voucher_date DESC,v.id DESC LIMIT 250");
$q->execute([$companyId,$sourceType,$type==='debit_note' ? 'purchase' : 'sales',$selectedId]); $sources=$q->fetchAll();
$selected=null;
foreach($sources as $source) if ((int)$source['id']===$selectedId) $selected=$source;
$preview=[];
if ($selected) {
    $q=db()->prepare('SELECT e.*,l.name AS ledger_name FROM voucher_entries e JOIN ledgers l ON l.id=e.ledger_id AND l.company_id=? WHERE e.voucher_id=? ORDER BY e.id');
    $q->execute([$companyId,$selectedId]); $preview=$q->fetchAll();
}
$pageTitle=$title; $pageHero=false;
include __DIR__.'/../partials/admin_header.php';
?>
<section class="mbw-card">
 <div class="mbw-card-head"><h2><?= e($title) ?></h2><a class="button secondary" href="<?= e(url('admin/accounting.php')) ?>">Voucher register</a></div>
 <?php if($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
 <form method="get" class="reference-toolbar" style="display:flex;align-items:flex-end;flex-wrap:wrap;gap:12px;margin-bottom:16px">
 <input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="entry" value="return">
 <label>Original document<select name="original_id" required><option value="">Select posted document</option><?php foreach($sources as $source): ?><option value="<?= (int)$source['id'] ?>" <?= $selectedId===(int)$source['id'] ? 'selected' : '' ?>><?= e($source['voucher_no'].' · '.app_date($source['voucher_date']).' · '.number_format((float)$source['total_amount'],2)) ?></option><?php endforeach; ?></select></label>
 <button class="button secondary" type="submit" <?= $sources===[] ? 'disabled' : '' ?>>Load document</button>
 <?php if($type==='debit_note'): ?><a class="button secondary" href="<?= e(url('admin/voucher-form.php?type=debit_note&entry='.($businessType==='jewellery' ? 'expense' : 'adjustment'))) ?>">Expense adjustment</a><?php endif; ?>
 </form>
 <?php if($sources===[]): ?><p>No eligible posted documents.</p><?php endif; ?>
 <?php if($selected): ?>
 <div class="mbw-tablewrap"><table><thead><tr><th>Account / description</th><th>Quantity</th><th>Return debit</th><th>Return credit</th></tr></thead><tbody>
 <?php foreach($preview as $line): ?><tr><td><?= e($line['ledger_name']) ?><?php if(!empty($line['memo'])): ?><br><?= e($line['memo']) ?><?php endif; ?></td><td><?= !empty($line['quantity']) ? e(number_format((float)$line['quantity'],3)) : '—' ?></td><td><?= $line['entry_type']==='credit' ? e(number_format((float)$line['amount'],2)) : '—' ?></td><td><?= $line['entry_type']==='debit' ? e(number_format((float)$line['amount'],2)) : '—' ?></td></tr><?php endforeach; ?>
 </tbody></table></div>
 <form method="post">
 <input type="hidden" name="original_id" value="<?= $selectedId ?>">
 <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="context_fiscal_year_id" value="<?= $fiscalYearId ?>">
 <div class="frm-grid frm-grid-3">
 <label>Return date<input type="date" name="voucher_date" required value="<?= e($_POST['voucher_date'] ?? date('Y-m-d')) ?>"></label>
 <label>Reason<input name="reason" maxlength="255" required value="<?= e($_POST['reason'] ?? '') ?>"></label>
 </div>
 <label class="checkbox-line"><input type="checkbox" required name="confirm_full_return" value="1">Return the entire original document, including its tax and settlement entries.</label>
 <div class="actions"><button class="button" type="submit">Save return note</button></div>
 </form>
 <?php endif; ?>
</section>
<?php include __DIR__.'/../partials/admin_footer.php'; ?>
