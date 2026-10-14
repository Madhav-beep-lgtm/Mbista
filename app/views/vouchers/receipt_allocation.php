<?php if (!empty($receiptAllocation)): ?>
<section class="voucher-meta">
 <div><strong>Receipt:</strong> <?= e($receiptAllocation['receipt_no']) ?></div>
 <div><strong>Client:</strong> <?= e($receiptAllocation['client_name']) ?></div>
 <div><strong>Invoice:</strong> <?= e($receiptAllocation['invoice_no']) ?></div>
 <div><strong>Task / stage:</strong> <?= e($receiptAllocation['task_description']) ?></div>
</section>
<table><thead><tr><th>Invoice total</th><th>Previously collected</th><th>This receipt</th><th>Remaining</th></tr></thead>
<tbody><tr><?php foreach (['invoice_total','previous','received','remaining'] as $field): ?><td class="amount"><?= number_format((float)$receiptAllocation[$field],2) ?></td><?php endforeach; ?></tr></tbody></table>
<h2>Journal entries</h2>
<?php endif; ?>
