<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_or_admin();
require_company_context();
$repairErrors = accounting_module_repair_database();

$company = current_company();
$fiscalYear = current_fiscal_year();
if (!$company || !$fiscalYear) {
    flash('error', 'Company and fiscal year context required.');
    redirect('admin/accounting.php');
}

$companyId = (int) $company['id'];
$fiscalYearId = (int) $fiscalYear['id'];
$currency = site_currency_symbol();
$hasBankMeta = column_exists('ledgers', 'bank_account_no');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_bank_meta' && $hasBankMeta) {
        $ledgerId = (int) ($_POST['ledger_id'] ?? 0);
        $bankName = trim((string) ($_POST['bank_name'] ?? ''));
        $accountNo = preg_replace('/[^0-9A-Za-z-]/', '', (string) ($_POST['bank_account_no'] ?? ''));
        $ownStmt = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :id AND company_id = :company_id');
        $ownStmt->execute(['id' => $ledgerId, 'company_id' => $companyId]);
        if ($ledgerId <= 0 || (int) $ownStmt->fetchColumn() === 0) {
            flash('error', 'Select a valid bank or cash ledger for this company.');
            redirect('admin/banking.php');
        }
        db()->prepare('UPDATE ledgers SET bank_name = :bank_name, bank_account_no = :bank_account_no WHERE id = :id')
            ->execute(['bank_name' => $bankName !== '' ? $bankName : null, 'bank_account_no' => $accountNo !== '' ? $accountNo : null, 'id' => $ledgerId]);
        log_activity('company', $companyId, 'bank_account_updated', 'Bank details updated for ledger #' . $ledgerId . '.', (int) (current_user()['id'] ?? 0) ?: null);
        flash('success', 'Bank account details saved.');
        redirect('admin/banking.php');
    }
}

// Cash & bank ledgers with FY movement + reconciliation coverage.
$accountStmt = db()->prepare('
    SELECT l.id, l.code, l.name,
           ' . ($hasBankMeta ? 'l.bank_name, l.bank_account_no,' : 'NULL AS bank_name, NULL AS bank_account_no,') . '
           COALESCE(SUM(CASE WHEN ve.entry_type = \'debit\' THEN ve.amount ELSE 0 END), 0) AS inflow,
           COALESCE(SUM(CASE WHEN ve.entry_type = \'credit\' THEN ve.amount ELSE 0 END), 0) AS outflow,
           COALESCE(SUM(CASE WHEN ve.id IS NOT NULL AND ve.reconciled_at IS NULL THEN 1 ELSE 0 END), 0) AS unreconciled,
           MAX(COALESCE(v.voucher_date, DATE(v.created_at))) AS last_move
    FROM ledgers l
    INNER JOIN ledger_groups g ON g.id = l.group_id AND COALESCE(g.is_cash_or_bank, 0) = 1
    LEFT JOIN voucher_entries ve ON ve.ledger_id = l.id
    LEFT JOIN vouchers v ON v.id = ve.voucher_id
        AND v.company_id = l.company_id AND v.fiscal_year_id = :fiscal_year_id AND v.status = \'posted\'
    WHERE l.company_id = :company_id
    GROUP BY l.id, l.code, l.name' . ($hasBankMeta ? ', l.bank_name, l.bank_account_no' : '') . '
    ORDER BY l.name ASC
');
$accountStmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);
$accounts = $accountStmt->fetchAll();

$totalBalance = 0.0;
$totalInflow = 0.0;
$totalOutflow = 0.0;
$totalUnreconciled = 0;
foreach ($accounts as $account) {
    $totalBalance += (float) $account['inflow'] - (float) $account['outflow'];
    $totalInflow += (float) $account['inflow'];
    $totalOutflow += (float) $account['outflow'];
    $totalUnreconciled += (int) $account['unreconciled'];
}

// Recent bank/cash movements in this fiscal year.
$txnStmt = db()->prepare('
    SELECT ve.id, ve.entry_type, ve.amount, ve.reconciled_at, l.name AS ledger_name,
           v.voucher_no, v.voucher_type, v.narration,
           COALESCE(v.voucher_date, DATE(v.created_at)) AS voucher_date
    FROM voucher_entries ve
    INNER JOIN ledgers l ON l.id = ve.ledger_id
    INNER JOIN ledger_groups g ON g.id = l.group_id AND COALESCE(g.is_cash_or_bank, 0) = 1
    INNER JOIN vouchers v ON v.id = ve.voucher_id
    WHERE v.company_id = :company_id AND v.fiscal_year_id = :fiscal_year_id AND v.status = \'posted\'
    ORDER BY COALESCE(v.voucher_date, DATE(v.created_at)) DESC, ve.id DESC
    LIMIT 25
');
$txnStmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);
$transactions = $txnStmt->fetchAll();

$fmtMoney = static fn (float $amount): string => $currency . number_format($amount, 2);
$maskAccount = static fn (?string $no): string => ($no !== null && $no !== '') ? '**** **** ' . substr($no, -4) : '—';

$pageTitle = 'Banking';
$pageSubtitle = 'Bank and cash accounts, balances, and account activity';
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<?php if ($repairErrors !== []): ?><div class="notice error">Accounting module repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div><?php endif; ?>

<section class="mbw-kpi-grid" aria-label="Banking overview">
    <article class="mbw-kpi">
        <div>
            <span class="mbw-kpi-label">Cash &amp; Bank Balance</span>
            <div class="mbw-kpi-value"><?= e($fmtMoney($totalBalance)) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= count($accounts) ?> accounts</span></span>
        </div>
        <span class="mbw-chip tone-blue"><?= icon('bank') ?></span>
    </article>
    <article class="mbw-kpi">
        <div>
            <span class="mbw-kpi-label">Inflow (This FY)</span>
            <div class="mbw-kpi-value"><?= e($fmtMoney($totalInflow)) ?></div>
            <span class="mbw-kpi-delta is-up"><?= icon('trend-up') ?><span class="mbw-kpi-vs">Money received</span></span>
        </div>
        <span class="mbw-chip tone-green"><?= icon('trend-up') ?></span>
    </article>
    <article class="mbw-kpi">
        <div>
            <span class="mbw-kpi-label">Outflow (This FY)</span>
            <div class="mbw-kpi-value"><?= e($fmtMoney($totalOutflow)) ?></div>
            <span class="mbw-kpi-delta is-down"><?= icon('trend-down') ?><span class="mbw-kpi-vs">Money paid out</span></span>
        </div>
        <span class="mbw-chip tone-red"><?= icon('trend-down') ?></span>
    </article>
    <a class="mbw-kpi" href="<?= e(url('admin/reconciliation.php')) ?>">
        <div>
            <span class="mbw-kpi-label">Unreconciled Entries</span>
            <div class="mbw-kpi-value"><?= (int) $totalUnreconciled ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Open items to reconcile</span></span>
        </div>
        <span class="mbw-chip tone-purple"><?= icon('reconcile') ?></span>
    </a>
</section>

<section class="mbw-card" aria-label="Bank accounts">
    <div class="mbw-card-head">
        <h2>Bank Accounts</h2>
        <div class="mbw-card-tools">
            <a class="mbw-view-all" href="<?= e(url('admin/chart-of-accounts.php#create-ledger')) ?>">Add Bank Ledger</a>
        </div>
    </div>
    <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Bank Account</th><th>Ledger</th><th>Account No.</th><th class="is-numeric">Balance</th><th>Updated On</th><th class="is-numeric">Unreconciled</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if ($accounts === []): ?><tr><td colspan="7" style="color:var(--mbw-muted)">No cash or bank ledgers yet. Create one from the Chart of Accounts.</td></tr><?php endif; ?>
            <?php foreach ($accounts as $account): ?>
                <?php $balance = (float) $account['inflow'] - (float) $account['outflow']; ?>
                <tr>
                    <td><strong style="color:var(--mbw-heading)"><?= e(($account['bank_name'] ?? '') !== '' && $account['bank_name'] !== null ? $account['bank_name'] : $account['name']) ?></strong></td>
                    <td><?= e($account['name']) ?> <span class="mbw-pill tone-gray"><?= e($account['code']) ?></span></td>
                    <td><?= e($maskAccount($account['bank_account_no'] ?? null)) ?></td>
                    <td class="is-numeric <?= $balance < 0 ? 'amount-negative' : '' ?>"><?= e($fmtMoney($balance)) ?></td>
                    <td><?= $account['last_move'] ? e(date('d M Y', strtotime((string) $account['last_move']))) : '<span style="color:var(--mbw-muted)">No activity</span>' ?></td>
                    <td class="is-numeric"><?= (int) $account['unreconciled'] > 0 ? '<span class="mbw-pill tone-amber">' . (int) $account['unreconciled'] . ' open</span>' : '<span class="mbw-pill tone-green">Clear</span>' ?></td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <a class="button secondary" style="min-height:32px;padding:4px 10px" href="<?= e(url('admin/reconciliation.php?ledger_id=' . (int) $account['id'])) ?>"><?= icon('reconcile') ?>Reconcile</a>
                            <?php if ($hasBankMeta): ?>
                                <button type="button" class="button secondary" style="min-height:32px;padding:4px 10px" data-modal-open="bank-meta-<?= (int) $account['id'] ?>"><?= icon('settings') ?>Details</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($accounts !== []): ?>
                <tfoot><tr><td colspan="3">Total</td><td class="is-numeric"><?= e($fmtMoney($totalBalance)) ?></td><td colspan="3"></td></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</section>

<section class="mbw-card" aria-label="Recent bank activity">
    <div class="mbw-card-head">
        <h2>Recent Bank &amp; Cash Activity</h2>
        <div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('admin/reports-center.php?report=bank-book&fy=' . $fiscalYearId)) ?>">Bank Book</a></div>
    </div>
    <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Date</th><th>Voucher No.</th><th>Account</th><th>Narration</th><th class="is-numeric">In</th><th class="is-numeric">Out</th><th>Status</th></tr></thead>
            <tbody>
            <?php if ($transactions === []): ?><tr><td colspan="7" style="color:var(--mbw-muted)">No bank or cash movements posted in this fiscal year.</td></tr><?php endif; ?>
            <?php foreach ($transactions as $txn): ?>
                <tr>
                    <td><?= e(date('d M Y', strtotime((string) $txn['voucher_date']))) ?></td>
                    <td><a href="<?= e(url('admin/accounting.php')) ?>" style="color:var(--mbw-primary);text-decoration:none"><?= e($txn['voucher_no']) ?></a></td>
                    <td><?= e($txn['ledger_name']) ?></td>
                    <td><?= e($txn['narration'] ?: ucwords(str_replace('_', ' ', (string) $txn['voucher_type']))) ?></td>
                    <td class="is-numeric amount-positive"><?= (string) $txn['entry_type'] === 'debit' ? e($fmtMoney((float) $txn['amount'])) : '' ?></td>
                    <td class="is-numeric amount-negative"><?= (string) $txn['entry_type'] === 'credit' ? e($fmtMoney((float) $txn['amount'])) : '' ?></td>
                    <td><?= $txn['reconciled_at'] ? '<span class="mbw-pill tone-green">Reconciled</span>' : '<span class="mbw-pill tone-amber">Pending</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($hasBankMeta): ?>
    <?php foreach ($accounts as $account): ?>
        <div class="modal-overlay" data-modal="bank-meta-<?= (int) $account['id'] ?>">
            <div class="modal-card">
                <div class="modal-head">
                    <h3>Bank Details — <?= e($account['name']) ?></h3>
                    <button type="button" class="button secondary" data-modal-close aria-label="Close"><?= icon('close') ?></button>
                </div>
                <form method="post" action="<?= e(url('admin/banking.php')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_bank_meta">
                    <input type="hidden" name="ledger_id" value="<?= (int) $account['id'] ?>">
                    <label>Bank name<input type="text" name="bank_name" maxlength="120" value="<?= e((string) ($account['bank_name'] ?? '')) ?>" placeholder="e.g. Nabil Bank"></label>
                    <label>Account number<input type="text" name="bank_account_no" maxlength="40" value="<?= e((string) ($account['bank_account_no'] ?? '')) ?>" placeholder="Shown masked except last 4 digits"></label>
                    <button type="submit">Save Details</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
