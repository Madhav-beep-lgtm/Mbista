<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e((string) $voucher['voucher_no']) ?> — <?= e($voucherLabel) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('assets/css/voucher-print.css')) ?>">
</head><body>
<nav class="print-toolbar"><button type="button" onclick="window.print()">Print voucher</button>
<?php if (in_array((string) ($voucher['voucher_type'] ?? ''), ['sales', 'purchase', 'debit_note', 'credit_note'], true)): ?>
<a href="<?= e(url('admin/voucher-view.php?id=' . (int) $voucher['id'] . '&document=1')) ?>">Invoice / note</a>
<?php endif; ?></nav>
<main class="voucher-document">
    <header><h1><?= e((string) $company['name']) ?></h1><p><?= nl2br(e(trim((string) ($company['address'] ?? '')) ?: 'Address not recorded')) ?></p><h2><?= e($voucherLabel) ?></h2></header>
    <section class="voucher-meta">
        <div><strong>Voucher no.:</strong> <?= e((string) $voucher['voucher_no']) ?></div>
        <div><strong>Voucher date (<?= e(strtoupper(date_mode())) ?>):</strong> <?= e(app_date((string) ($voucher['voucher_date'] ?? ''), 'Y-m-d')) ?></div>
        <div><strong>Financial year:</strong> <?= e($financialYear) ?></div>
        <div><strong>Status:</strong> <?= e(ucfirst((string) $voucher['status'])) ?><?= !empty($voucher['approval_state']) ? ' / ' . e(ucwords(str_replace('_', ' ', (string) $voucher['approval_state']))) : '' ?></div>
    </section>
    <?php include __DIR__ . '/receipt_allocation.php'; ?>
    <table><thead><tr><th class="serial">SN</th><th>Ledger name</th><th>Description</th><th class="amount">Debit</th><th class="amount">Credit</th></tr></thead><tbody>
    <?php foreach ($entries as $index => $entry):
        $debit = $entry['entry_type'] === 'debit' ? (float) $entry['amount'] : 0;
        $credit = $entry['entry_type'] === 'credit' ? (float) $entry['amount'] : 0;
        $totalDebit += $debit; $totalCredit += $credit;
    ?>
        <tr><td><?= $index + 1 ?></td><td><?= e((string) $entry['ledger_name']) ?></td><td><?= e((string) ($entry['memo'] ?? '')) ?></td><td class="amount"><?= $debit ? number_format($debit, 2) : '—' ?></td><td class="amount"><?= $credit ? number_format($credit, 2) : '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody><tbody class="totals"><tr><th colspan="3">Total (<?= e(trim(site_currency_symbol())) ?>)</th><th class="amount"><?= number_format($totalDebit, 2) ?></th><th class="amount"><?= number_format($totalCredit, 2) ?></th></tr></tbody></table>
    <section class="narration"><strong>Narration:</strong> <?= nl2br(e((string) ($voucher['narration'] ?? ''))) ?></section>
    <footer class="signatures">
        <div><div class="signature-line"></div><strong>Maker / Prepared by</strong><p><?= e($names['submitted_by']) ?></p><small>Date: __________________</small></div>
        <div><div class="signature-line"></div><strong>Checker / Approved by</strong><p><?= e($names['approved_by']) ?></p><small>Date: __________________</small></div>
    </footer>
</main></body></html>
