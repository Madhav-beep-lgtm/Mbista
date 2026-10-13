<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_admin_or_client_books();
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
$cashAccountSql = '(COALESCE(g.is_cash_or_bank, 0) = 1'
    . (column_exists('ledgers', 'is_cash_equivalent') ? ' OR COALESCE(l.is_cash_equivalent, 0) = 1' : '')
    . (column_exists('ledger_groups', 'is_cash_equivalent') ? ' OR COALESCE(g.is_cash_equivalent, 0) = 1' : '') . ')';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_bank_meta' && $hasBankMeta) {
        require_permission('accounting', 'edit');
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

// Inventory stock ledgers can end up misfiled in the Bank group (seed data did
// this); anything the inventory module posts to is never a bank/cash account.
$inventoryLedgerGuard = '';
if (table_exists('inventory_ledger_mappings')) {
    $inventoryLedgerGuard .= ' AND l.id NOT IN (SELECT ilm.ledger_id FROM inventory_ledger_mappings ilm WHERE ilm.company_id = l.company_id AND ilm.ledger_id IS NOT NULL)';
}
if (table_exists('inventory_items') && column_exists('inventory_items', 'ledger_id')) {
    $inventoryLedgerGuard .= ' AND l.id NOT IN (SELECT ii.ledger_id FROM inventory_items ii WHERE ii.company_id = l.company_id AND ii.ledger_id IS NOT NULL)';
}

// Cash & bank ledgers. Inflow/Outflow/Unreconciled are movement within the
// selected fiscal year (posted vouchers only — the old query let drafts and
// other years leak into the SUMs via the LEFT JOIN). Balance is cumulative:
// every posted entry dated up to the FY end, so assets carry forward.
$fiscalYearEnd = (string) ($fiscalYear['end_date'] ?? date('Y-m-d'));
$accountStmt = db()->prepare('
    SELECT l.id, l.code, l.name,
           ' . ($hasBankMeta ? 'l.bank_name, l.bank_account_no,' : 'NULL AS bank_name, NULL AS bank_account_no,') . '
           COALESCE(SUM(CASE WHEN v.id IS NOT NULL AND ve.entry_type = \'debit\' THEN ve.amount ELSE 0 END), 0) AS inflow,
           COALESCE(SUM(CASE WHEN v.id IS NOT NULL AND ve.entry_type = \'credit\' THEN ve.amount ELSE 0 END), 0) AS outflow,
           COALESCE(SUM(CASE WHEN v.id IS NOT NULL AND ve.reconciled_at IS NULL THEN 1 ELSE 0 END), 0) AS unreconciled,
           MAX(v.voucher_date) AS last_move,
           COALESCE((
               SELECT SUM(CASE WHEN ve2.entry_type = \'debit\' THEN ve2.amount ELSE -ve2.amount END)
               FROM voucher_entries ve2
               INNER JOIN vouchers v2 ON v2.id = ve2.voucher_id
               WHERE ve2.ledger_id = l.id AND v2.company_id = l.company_id AND v2.status = \'posted\'
                 AND v2.voucher_date <= :fy_end
           ), 0) + CASE WHEN l.opening_side = \'credit\' THEN -COALESCE(l.opening_balance, 0) ELSE COALESCE(l.opening_balance, 0) END AS balance
    FROM ledgers l
    INNER JOIN ledger_groups g ON g.id = l.group_id AND g.company_id = l.company_id AND ' . $cashAccountSql . '
    LEFT JOIN voucher_entries ve ON ve.ledger_id = l.id
    LEFT JOIN vouchers v ON v.id = ve.voucher_id
        AND v.company_id = l.company_id AND v.fiscal_year_id = :fiscal_year_id AND v.status = \'posted\'
    WHERE l.company_id = :company_id' . $inventoryLedgerGuard . '
    GROUP BY l.id, l.code, l.name, l.opening_side, l.opening_balance' . ($hasBankMeta ? ', l.bank_name, l.bank_account_no' : '') . '
    ORDER BY l.name ASC
');
$accountStmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId, 'fy_end' => $fiscalYearEnd]);
$accounts = $accountStmt->fetchAll();

$totalBalance = 0.0;
$totalInflow = 0.0;
$totalOutflow = 0.0;
$totalUnreconciled = 0;
foreach ($accounts as $account) {
    $totalBalance += (float) $account['balance'];
    $totalInflow += (float) $account['inflow'];
    $totalOutflow += (float) $account['outflow'];
    $totalUnreconciled += (int) $account['unreconciled'];
}

// Recent bank/cash movements in this fiscal year.
$txnStmt = db()->prepare('
    SELECT ve.id, ve.ledger_id, ve.entry_type, ve.amount, ve.reconciled_at, l.name AS ledger_name,
           v.id AS voucher_id, v.voucher_no, v.voucher_type, v.narration,
           v.voucher_date AS voucher_date
    FROM voucher_entries ve
    INNER JOIN ledgers l ON l.id = ve.ledger_id
    INNER JOIN ledger_groups g ON g.id = l.group_id AND g.company_id = l.company_id AND ' . $cashAccountSql . '
    INNER JOIN vouchers v ON v.id = ve.voucher_id AND v.company_id = l.company_id
    WHERE v.company_id = :company_id AND v.fiscal_year_id = :fiscal_year_id AND v.status = \'posted\'' . $inventoryLedgerGuard . '
    ORDER BY v.voucher_date DESC, ve.id DESC
    LIMIT 25
');
$txnStmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);
$transactions = $txnStmt->fetchAll();

$fmtMoney = static fn (float $amount): string => $currency . number_format($amount, 2);
$maskAccount = static fn (?string $no): string => ($no !== null && $no !== '') ? '**** **** ' . substr($no, -4) : '—';

$pageTitle = 'Banking';
$pageHero = false;
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<?php if ($repairErrors !== []): ?><div class="notice error">Accounting module repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div><?php endif; ?>

<section class="mbw-kpi-grid" aria-label="Banking overview">
    <a class="mbw-kpi" href="<?= e(url('admin/reports-center.php?report=cash-book&fy=' . $fiscalYearId)) ?>">
        <div>
            <span class="mbw-kpi-label">Cash &amp; Bank Balance</span>
            <div class="mbw-kpi-value"><?= e($fmtMoney($totalBalance)) ?></div>

        </div>
        <span class="mbw-chip tone-blue"><?= icon('bank') ?></span>
    </a>
    <a class="mbw-kpi" href="<?= e(url('admin/reports-center.php?report=cash-book&fy=' . $fiscalYearId)) ?>">
        <div>
            <span class="mbw-kpi-label">Inflow (This FY)</span>
            <div class="mbw-kpi-value"><?= e($fmtMoney($totalInflow)) ?></div>

        </div>
        <span class="mbw-chip tone-green"><?= icon('trend-up') ?></span>
    </a>
    <a class="mbw-kpi" href="<?= e(url('admin/reports-center.php?report=cash-book&fy=' . $fiscalYearId)) ?>">
        <div>
            <span class="mbw-kpi-label">Outflow (This FY)</span>
            <div class="mbw-kpi-value"><?= e($fmtMoney($totalOutflow)) ?></div>

        </div>
        <span class="mbw-chip tone-red"><?= icon('trend-down') ?></span>
    </a>
    <a class="mbw-kpi" href="<?= e(url('admin/reconciliation.php')) ?>">
        <div>
            <span class="mbw-kpi-label">Unreconciled Entries</span>
            <div class="mbw-kpi-value"><?= (int) $totalUnreconciled ?></div>

        </div>
        <span class="mbw-chip tone-purple"><?= icon('reconcile') ?></span>
    </a>
</section>

<?php
$activityByLedger = [];
foreach ($transactions as $txn) { $activityByLedger[(int) $txn['ledger_id']][] = $txn; }
?>
<section class="mbw-card" aria-label="Cash, bank and cash equivalent accounts and activity">
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Account</th><th>Account no.</th><th>FY-end balance</th><th>Date</th><th>Voucher no.</th><th>Narration / Memo</th><th>In</th><th>Out</th><th>Reconciliation</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if ($accounts === []): ?><tr><td colspan="10">No cash, bank or cash equivalent accounts.</td></tr><?php endif; ?>
        <?php foreach ($accounts as $account):
            $rows = $activityByLedger[(int) $account['id']] ?? [null];
            foreach ($rows as $rowIndex => $txn):
        ?>
        <tr>
            <?php if ($rowIndex === 0): ?>
            <td rowspan="<?= count($rows) ?>"><a href="<?= e(url('admin/ledgers.php?ledger_id=' . (int) $account['id'])) ?>"><?= e($account['name'] . ' (' . $account['code'] . ')') ?></a></td>
            <td rowspan="<?= count($rows) ?>"><?= e($maskAccount($account['bank_account_no'] ?? null)) ?></td>
            <td rowspan="<?= count($rows) ?>" class="is-numeric"><?= e($fmtMoney((float) $account['balance'])) ?></td>
            <?php endif; ?>
            <td><?= $txn ? e(app_date_short((string) $txn['voucher_date'])) : '—' ?></td>
            <td><?php if ($txn): ?><a data-voucher-preview href="<?= e(url('admin/voucher-view.php?id=' . (int) $txn['voucher_id'])) ?>"><?= e($txn['voucher_no']) ?></a><?php else: ?>No recent activity<?php endif; ?></td>
            <td><?= $txn ? e((string) $txn['narration']) : '' ?></td>
            <td class="is-numeric"><?= $txn && $txn['entry_type'] === 'debit' ? e($fmtMoney((float) $txn['amount'])) : '' ?></td>
            <td class="is-numeric"><?= $txn && $txn['entry_type'] === 'credit' ? e($fmtMoney((float) $txn['amount'])) : '' ?></td>
            <td><?= $txn ? ($txn['reconciled_at'] ? 'Reconciled' : 'Pending') : '—' ?></td>
            <?php if ($rowIndex === 0): ?>
            <td rowspan="<?= count($rows) ?>"><a class="button secondary" href="<?= e(url('admin/reconciliation.php?ledger_id=' . (int) $account['id'])) ?>">Reconcile (<?= (int) $account['unreconciled'] ?>)</a>
            <?php if ($hasBankMeta): ?><button type="button" class="button secondary" data-modal-open="bank-meta-<?= (int) $account['id'] ?>">Details</button><?php endif; ?></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="2">Total balance</th><th class="is-numeric"><?= e($fmtMoney($totalBalance)) ?></th><td colspan="7"></td></tr></tfoot>
    </table>
    </div>
</section>
<?php include __DIR__ . '/../../app/views/vouchers/preview_dialog.php'; ?>

<?php if ($hasBankMeta): ?>
    <?php foreach ($accounts as $account): ?>
        <div class="modal-overlay" data-modal="bank-meta-<?= (int) $account['id'] ?>">
            <div class="modal-card">
                <div class="modal-head">
                    <h3>Bank Details — <?= e($account['name']) ?></h3>
                    <button type="button" class="button secondary" data-modal-close="bank-meta-<?= (int) $account['id'] ?>" aria-label="Close"><?= icon('close') ?></button>
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
