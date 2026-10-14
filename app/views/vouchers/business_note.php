<?php
declare(strict_types=1);
require_permission('accounting','create');
require_permission($businessType,'view');
require_permission($businessType,$businessType==='jewellery' ? 'edit' : 'create');
$type=$requestedType;
$title=$type==='credit_note' ? 'Credit note — sales return' : 'Debit note — purchase return';
$sourceType=$businessType==='jewellery' ? ($type==='credit_note' ? 'jewellery_sale' : 'jewellery_purchase') : 'hospitality_sales_upload';
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
$q=db()->prepare("SELECT id,voucher_no,voucher_date,total_amount FROM vouchers WHERE company_id=? AND source_type=? AND status='posted' ORDER BY voucher_date DESC,id DESC LIMIT 250");
$q->execute([$companyId,$sourceType]); $sources=$q->fetchAll();
$pageTitle=$title; $pageHero=false;
include __DIR__.'/../partials/admin_header.php';
?>
<section class="mbw-card">
 <div class="mbw-card-head"><h2><?= e($title) ?></h2><a class="button secondary" href="<?= e(url('admin/accounting.php')) ?>">Voucher register</a></div>
 <?php if($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
 <form method="post">
 <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="context_fiscal_year_id" value="<?= $fiscalYearId ?>">
 <div class="frm-grid frm-grid-3">
 <label>Original document — full return<select name="original_id" required><option value="">Select posted document</option><?php foreach($sources as $source): ?><option value="<?= (int)$source['id'] ?>" <?= (int)($_POST['original_id'] ?? 0)===(int)$source['id'] ? 'selected' : '' ?>><?= e($source['voucher_no'].' · '.app_date($source['voucher_date']).' · '.number_format((float)$source['total_amount'],2)) ?></option><?php endforeach; ?></select></label>
 <label>Return date<input type="date" name="voucher_date" required value="<?= e($_POST['voucher_date'] ?? date('Y-m-d')) ?>"></label>
 <label>Reason<input name="reason" maxlength="255" required value="<?= e($_POST['reason'] ?? '') ?>"></label>
 </div>
 <label class="checkbox-line"><input type="checkbox" required name="confirm_full_return" value="1">Return the entire original document, including its tax and settlement entries.</label>
 <div class="actions"><button class="button" type="submit">Save return note</button></div>
 </form>
</section>
<?php include __DIR__.'/../partials/admin_footer.php'; ?>
