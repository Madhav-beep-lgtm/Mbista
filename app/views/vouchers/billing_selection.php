<?php $billingRequired = voucher_billing_group($companyId);
if (($spec['key']==='sales' && $billingRequired) || $spec['key']==='receipt'):
 $billingSaved = $editVoucher ? voucher_billing_link((int)$editVoucher['id']) : [];
 $billingInput = is_array($retry) && ($retry['type'] ?? '') === $type ? ($retry['input'] ?? []) : [];
?>
<section class="mbw-card frm-section">
 <div class="frm-grid frm-grid-2">
 <?php if ($spec['key'] === 'sales'): ?>
 <label>Completed task / stage <em>*</em>
  <select name="billing_task" required>
   <option value="">Select completed, uninvoiced work</option>
   <?php $billingSelected = $billingInput['billing_task'] ?? (!empty($billingSaved['task_id']) ? $billingSaved['task_id'].':'.(int)$billingSaved['stage_id'] : ''); ?>
   <?php foreach (voucher_billing_tasks($companyId) as $work): $workKey=$work['task_id'].':'.$work['stage_id']; ?>
   <option value="<?= e($workKey) ?>" <?= $billingSelected === $workKey ? 'selected' : '' ?>><?= e($work['description']) ?> — <?= e(number_format((float)$work['fee'],2)) ?></option>
   <?php endforeach; ?>
  </select>
 </label>
 <?php else: ?>
 <label>Uncollected invoice / task <?= $billingRequired ? '<em>*</em>' : '(optional)' ?>
  <select name="billing_invoice_id" <?= $billingRequired ? 'required' : '' ?>>
   <option value="">Select outstanding invoice</option>
   <?php foreach (voucher_billing_outstanding($companyId) as $bill): ?>
   <option value="<?= (int)$bill['id'] ?>" <?= (int)($billingInput['billing_invoice_id'] ?? $billingSaved['invoice_id'] ?? 0) === (int)$bill['id'] ? 'selected' : '' ?>><?= e($bill['invoice_no'].' — '.($bill['task_title'] ?: $bill['description'])) ?> — <?= e(number_format((float)$bill['outstanding'],2)) ?></option>
   <?php endforeach; ?>
  </select>
 </label>
 <?php endif; ?>
 </div>
</section>
<?php endif; ?>
