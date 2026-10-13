<?php
declare(strict_types=1);
require_permission('accounting', 'view');
$historyTypes = $invTask === 'purchase' ? ['purchase', 'debit_note'] : ['sales', 'credit_note'];
$historyPage = max(1, (int) ($_GET['page'] ?? 1));
$historyParams = [$companyId, $fiscalYearId, ...$historyTypes];
$historyWhere = 'company_id = ? AND fiscal_year_id = ? AND voucher_type IN (?, ?)';
$historyCount = db()->prepare('SELECT COUNT(*) FROM vouchers WHERE ' . $historyWhere);
$historyCount->execute($historyParams);
$historyPages = max(1, (int) ceil((int) $historyCount->fetchColumn() / 50));
$historyPage = min($historyPage, $historyPages);
$historyStmt = db()->prepare('SELECT id, voucher_date, voucher_no, voucher_type, reference_no, total_amount, status
    FROM vouchers WHERE ' . $historyWhere . ' ORDER BY voucher_date DESC, id DESC LIMIT 50 OFFSET ' . (($historyPage - 1) * 50));
$historyStmt->execute($historyParams);
$historyVouchers = $historyStmt->fetchAll();
$historyLabels = ['purchase' => 'Purchase', 'debit_note' => 'Purchase return', 'sales' => 'Sales', 'credit_note' => 'Sales return'];
?>
<div class="rc-table-scroll">
    <table class="rc-table">
        <thead><tr><th>Date</th><th>Voucher</th><th>Type</th><th>Reference</th><th class="is-numeric">Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php if ($historyVouchers === []): ?>
            <tr><td colspan="6">No vouchers in this financial year.</td></tr>
        <?php endif; ?>
        <?php foreach ($historyVouchers as $historyVoucher): ?>
            <tr>
                <td><?= e(app_date((string) ($historyVoucher['voucher_date'] ?? ''))) ?></td>
                <td><a data-voucher-preview href="<?= e(url('admin/voucher-view.php?id=' . (int) $historyVoucher['id'])) ?>"><?= e((string) ($historyVoucher['voucher_no'] ?: 'Draft #' . $historyVoucher['id'])) ?></a></td>
                <td><?= e($historyLabels[$historyVoucher['voucher_type']]) ?></td>
                <td><?= e((string) ($historyVoucher['reference_no'] ?? '')) ?></td>
                <td class="is-numeric"><?= e(number_format((float) $historyVoucher['total_amount'], 2)) ?></td>
                <td><?= e(ucfirst((string) $historyVoucher['status'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if ($historyPages > 1): ?>
<div class="actions">
    <?php if ($historyPage > 1): ?><a class="button secondary" href="<?= e(url(inv_back_url('page=' . ($historyPage - 1)))) ?>">Previous</a><?php endif; ?>
    <span><?= $historyPage ?> / <?= $historyPages ?></span>
    <?php if ($historyPage < $historyPages): ?><a class="button secondary" href="<?= e(url(inv_back_url('page=' . ($historyPage + 1)))) ?>">Next</a><?php endif; ?>
</div>
<?php endif; ?>
