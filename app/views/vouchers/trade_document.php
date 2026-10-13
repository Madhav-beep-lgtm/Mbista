<?php
declare(strict_types=1);
$documentSubtotal = 0.0;
$documentTax = 0.0;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($documentTitle . ' ' . $voucher['voucher_no']) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('assets/css/voucher-print.css')) ?>">
</head><body>
<nav class="print-toolbar"><button type="button" onclick="window.print()">Print / Save PDF</button>
<a href="<?= e(url('admin/voucher-view.php?id=' . (int) $voucher['id'])) ?>">Journal entries</a></nav>
<main class="voucher-document">
<header><h1><?= e((string) $company['name']) ?></h1><p><?= nl2br(e((string) ($company['address'] ?? ''))) ?></p>
<h2><?= e($documentTitle) ?><?= $voucher['status'] !== 'posted' ? ' - ' . e(strtoupper((string) $voucher['status'])) : '' ?></h2></header>
<section class="voucher-meta">
<div><strong>Document no.:</strong> <?= e((string) $voucher['voucher_no']) ?></div>
<div><strong>Date:</strong> <?= e(app_date((string) $voucher['voucher_date'])) ?></div>
<div><strong>Financial year:</strong> <?= e($financialYear) ?></div>
<div><strong>Party:</strong> <?= e((string) ($documentParty['name'] ?? 'Cash / bank settlement')) ?></div>
<div><strong>Address:</strong> <?= e((string) ($documentParty['address'] ?? '')) ?></div>
<div><strong>PAN:</strong> <?= e((string) ($documentParty['pan'] ?? '')) ?></div>
<div><strong>Reference:</strong> <?= e((string) ($voucher['reference_no'] ?? '')) ?></div>
<div><strong>Due date:</strong> <?= e(app_date((string) ($voucher['due_date'] ?? ''))) ?></div>
</section>
<table><thead><tr><th>SN</th><th>Description</th><th>Qty</th><th>Rate</th><th>Amount</th><th>Tax</th><th>Total</th></tr></thead><tbody>
<?php foreach ($documentLines['values'] as $index => $line):
    $mode = $line['tax_mode'] ?? $documentLines['tax_mode'];
    $rate = (float) ($line['tax_rate'] ?? $documentLines['tax_rate']);
    $base = $mode === 'inclusive' ? round((float) $line['amount'] / (1 + $rate / 100), 2) : (float) $line['amount'];
    $tax = $mode === 'none' ? 0 : round($base * $rate / 100, 2);
    $documentSubtotal += $base;
    $documentTax += $tax;
?>
<tr><td><?= $index + 1 ?></td><td><?= e((string) $line['description']) ?></td><td class="amount"><?= e((string) $line['qty']) ?></td>
<td class="amount"><?= e((string) $line['rate']) ?></td><td class="amount"><?= number_format($base, 2) ?></td>
<td class="amount"><?= number_format($tax, 2) ?></td><td class="amount"><?= number_format($base + $tax, 2) ?></td></tr>
<?php endforeach; ?>
</tbody><tbody class="totals">
<tr><th colspan="6">Subtotal</th><th class="amount"><?= number_format($documentSubtotal, 2) ?></th></tr>
<tr><th colspan="6">Tax</th><th class="amount"><?= number_format($documentTax, 2) ?></th></tr>
<tr><th colspan="6">Total (<?= e(trim(site_currency_symbol())) ?>)</th><th class="amount"><?= number_format($documentSubtotal + $documentTax, 2) ?></th></tr>
</tbody></table>
<section class="narration"><strong>Narration:</strong> <?= nl2br(e((string) ($voucher['narration'] ?? ''))) ?>
<?php if (!empty($voucher['return_reason'])): ?><p><strong>Reason:</strong> <?= e((string) $voucher['return_reason']) ?></p><?php endif; ?></section>
<footer class="signatures"><div><div class="signature-line"></div><strong>Maker / Prepared by</strong><p><?= e($names['submitted_by']) ?></p></div>
<div><div class="signature-line"></div><strong>Checker / Approved by</strong><p><?= e($names['approved_by']) ?></p></div></footer>
</main></body></html>
