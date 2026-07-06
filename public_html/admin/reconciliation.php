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
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$hasReconColumns = column_exists('voucher_entries', 'reconciled_at');

// Cash/bank ledgers for the picker.
$ledgerStmt = db()->prepare('
    SELECT l.id, l.code, l.name
    FROM ledgers l
    INNER JOIN ledger_groups g ON g.id = l.group_id AND COALESCE(g.is_cash_or_bank, 0) = 1
    WHERE l.company_id = :company_id
    ORDER BY l.name ASC
');
$ledgerStmt->execute(['company_id' => $companyId]);
$bankLedgers = $ledgerStmt->fetchAll();
$ledgerIds = array_map(static fn (array $row): int => (int) $row['id'], $bankLedgers);

$selectedLedgerId = (int) ($_GET['ledger_id'] ?? ($_POST['ledger_id'] ?? 0));
if ($selectedLedgerId <= 0 || !in_array($selectedLedgerId, $ledgerIds, true)) {
    $selectedLedgerId = $ledgerIds[0] ?? 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasReconColumns) {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'reconcile_entries') {
        $entryIds = array_values(array_filter(array_map('intval', (array) ($_POST['entry_ids'] ?? []))));
        $statementDate = (string) ($_POST['statement_date'] ?? '');
        $statementDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $statementDate) ? $statementDate : date('Y-m-d');
        if ($entryIds === [] || $selectedLedgerId <= 0) {
            flash('error', 'Select at least one entry to reconcile.');
            redirect('admin/reconciliation.php?ledger_id=' . $selectedLedgerId);
        }
        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
        $updateStmt = db()->prepare('
            UPDATE voucher_entries ve
            INNER JOIN vouchers v ON v.id = ve.voucher_id AND v.company_id = ? AND v.status = \'posted\'
            SET ve.reconciled_at = NOW(), ve.reconciled_by = ?, ve.statement_date = ?
            WHERE ve.id IN (' . $placeholders . ') AND ve.ledger_id = ? AND ve.reconciled_at IS NULL
        ');
        $updateStmt->execute(array_merge([$companyId, $userId ?: null, $statementDate], $entryIds, [$selectedLedgerId]));
        $count = $updateStmt->rowCount();
        log_activity('company', $companyId, 'bank_reconciliation', $count . ' entries reconciled against statement ' . $statementDate . ' for ledger #' . $selectedLedgerId . '.', $userId ?: null);
        flash('success', $count . ' ' . ($count === 1 ? 'entry' : 'entries') . ' marked as reconciled.');
        redirect('admin/reconciliation.php?ledger_id=' . $selectedLedgerId);
    }
    if ($action === 'unreconcile_entry') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $updateStmt = db()->prepare('
            UPDATE voucher_entries ve
            INNER JOIN vouchers v ON v.id = ve.voucher_id AND v.company_id = ?
            SET ve.reconciled_at = NULL, ve.reconciled_by = NULL, ve.statement_date = NULL
            WHERE ve.id = ? AND ve.ledger_id = ?
        ');
        $updateStmt->execute([$companyId, $entryId, $selectedLedgerId]);
        log_activity('company', $companyId, 'bank_reconciliation', 'Entry #' . $entryId . ' reopened for reconciliation.', $userId ?: null);
        flash('success', 'Entry reopened for reconciliation.');
        redirect('admin/reconciliation.php?ledger_id=' . $selectedLedgerId);
    }
}

$unreconciled = [];
$recentlyReconciled = [];
$bookBalance = 0.0;
$reconciledBalance = 0.0;
if ($selectedLedgerId > 0) {
    $entriesStmt = db()->prepare('
        SELECT ve.id, ve.entry_type, ve.amount, ve.memo' . ($hasReconColumns ? ', ve.reconciled_at, ve.statement_date' : ', NULL AS reconciled_at, NULL AS statement_date') . ',
               v.voucher_no, v.voucher_type, v.narration,
               COALESCE(v.voucher_date, DATE(v.created_at)) AS voucher_date
        FROM voucher_entries ve
        INNER JOIN vouchers v ON v.id = ve.voucher_id
        WHERE v.company_id = :company_id AND v.fiscal_year_id = :fiscal_year_id AND v.status = \'posted\'
          AND ve.ledger_id = :ledger_id
        ORDER BY COALESCE(v.voucher_date, DATE(v.created_at)) ASC, ve.id ASC
    ');
    $entriesStmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId, 'ledger_id' => $selectedLedgerId]);
    foreach ($entriesStmt->fetchAll() as $entry) {
        $signed = (string) $entry['entry_type'] === 'debit' ? (float) $entry['amount'] : -(float) $entry['amount'];
        $bookBalance += $signed;
        if ($entry['reconciled_at'] !== null) {
            $reconciledBalance += $signed;
            $recentlyReconciled[] = $entry;
        } else {
            $unreconciled[] = $entry;
        }
    }
    $recentlyReconciled = array_slice(array_reverse($recentlyReconciled), 0, 10);
}
$unreconciledDifference = $bookBalance - $reconciledBalance;

$fmtMoney = static fn (float $amount): string => $currency . number_format($amount, 2);
$selectedLedger = null;
foreach ($bankLedgers as $candidate) {
    if ((int) $candidate['id'] === $selectedLedgerId) {
        $selectedLedger = $candidate;
        break;
    }
}

$pageTitle = 'Bank Reconciliation';
$pageSubtitle = 'Match posted bank and cash entries against bank statements';
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<?php if ($repairErrors !== []): ?><div class="notice error">Accounting module repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div><?php endif; ?>

<?php if (!$hasReconColumns): ?>
    <div class="notice error">Reconciliation columns are missing and could not be added automatically. Run database/migrations/026_banking_reconciliation.sql.</div>
<?php endif; ?>

<section class="mbw-card" aria-label="Reconciliation account picker">
    <div class="mbw-card-head">
        <h2>Account to Reconcile</h2>
        <div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('admin/banking.php')) ?>">Banking Overview</a></div>
    </div>
    <?php if ($bankLedgers === []): ?>
        <p style="color:var(--mbw-muted);font-size:13.5px">No cash or bank ledgers exist yet. <a href="<?= e(url('admin/chart-ledgers.php')) ?>" style="color:var(--mbw-primary)">Create one from the Chart of Accounts</a>.</p>
    <?php else: ?>
        <form method="get" class="mbw-inline-form" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <label class="sr-only" for="recon-ledger">Bank ledger</label>
            <select id="recon-ledger" class="mbw-select" name="ledger_id" onchange="this.form.submit()">
                <?php foreach ($bankLedgers as $ledger): ?>
                    <option value="<?= (int) $ledger['id'] ?>" <?= (int) $ledger['id'] === $selectedLedgerId ? 'selected' : '' ?>><?= e($ledger['name']) ?> (<?= e($ledger['code']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <span class="mbw-pill tone-blue">Book balance: <?= e($fmtMoney($bookBalance)) ?></span>
            <span class="mbw-pill tone-green">Reconciled: <?= e($fmtMoney($reconciledBalance)) ?></span>
            <span class="mbw-pill <?= abs($unreconciledDifference) < 0.005 ? 'tone-green' : 'tone-amber' ?>">Unreconciled difference: <?= e($fmtMoney($unreconciledDifference)) ?></span>
        </form>
    <?php endif; ?>
</section>

<?php if ($selectedLedger !== null): ?>
    <section class="mbw-card" aria-label="Unreconciled entries">
        <div class="mbw-card-head">
            <h2>Unreconciled Entries — <?= e($selectedLedger['name']) ?></h2>
            <span class="mbw-pill tone-amber"><?= count($unreconciled) ?> open</span>
        </div>
        <?php if ($unreconciled === []): ?>
            <p style="color:var(--mbw-muted);font-size:13.5px">Everything is reconciled for this account in the current fiscal year.</p>
        <?php else: ?>
            <form method="post" action="<?= e(url('admin/reconciliation.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="reconcile_entries">
                <input type="hidden" name="ledger_id" value="<?= (int) $selectedLedgerId ?>">
                <div style="overflow-x:auto">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:36px"><input type="checkbox" onclick="document.querySelectorAll('input[name=\'entry_ids[]\']').forEach(c => c.checked = this.checked)" aria-label="Select all"></th>
                                <th>Date</th><th>Voucher No.</th><th>Type</th><th>Narration</th>
                                <th class="is-numeric">In</th><th class="is-numeric">Out</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($unreconciled as $entry): ?>
                            <tr>
                                <td><input type="checkbox" name="entry_ids[]" value="<?= (int) $entry['id'] ?>" aria-label="Select entry <?= e($entry['voucher_no']) ?>"></td>
                                <td><?= e(date('d M Y', strtotime((string) $entry['voucher_date']))) ?></td>
                                <td><?= e($entry['voucher_no']) ?></td>
                                <td><span class="mbw-pill tone-blue"><?= e(ucwords(str_replace('_', ' ', (string) $entry['voucher_type']))) ?></span></td>
                                <td><?= e($entry['memo'] ?: ($entry['narration'] ?: '—')) ?></td>
                                <td class="is-numeric amount-positive"><?= (string) $entry['entry_type'] === 'debit' ? e($fmtMoney((float) $entry['amount'])) : '' ?></td>
                                <td class="is-numeric amount-negative"><?= (string) $entry['entry_type'] === 'credit' ? e($fmtMoney((float) $entry['amount'])) : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-top:14px">
                    <label style="display:grid;gap:4px">Statement date
                        <input type="date" name="statement_date" value="<?= e(date('Y-m-d')) ?>" required>
                    </label>
                    <button type="submit" <?= $hasReconColumns ? '' : 'disabled' ?>><?= icon('reconcile') ?>Mark Selected as Reconciled</button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="mbw-card" aria-label="Recently reconciled entries">
        <div class="mbw-card-head">
            <h2>Recently Reconciled</h2>
            <span class="mbw-pill tone-green"><?= count($recentlyReconciled) ?> shown</span>
        </div>
        <?php if ($recentlyReconciled === []): ?>
            <p style="color:var(--mbw-muted);font-size:13.5px">No reconciled entries yet for this account.</p>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>Date</th><th>Voucher No.</th><th>Statement Date</th><th class="is-numeric">In</th><th class="is-numeric">Out</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($recentlyReconciled as $entry): ?>
                        <tr>
                            <td><?= e(date('d M Y', strtotime((string) $entry['voucher_date']))) ?></td>
                            <td><?= e($entry['voucher_no']) ?></td>
                            <td><?= $entry['statement_date'] ? e(date('d M Y', strtotime((string) $entry['statement_date']))) : '—' ?></td>
                            <td class="is-numeric amount-positive"><?= (string) $entry['entry_type'] === 'debit' ? e($fmtMoney((float) $entry['amount'])) : '' ?></td>
                            <td class="is-numeric amount-negative"><?= (string) $entry['entry_type'] === 'credit' ? e($fmtMoney((float) $entry['amount'])) : '' ?></td>
                            <td><span class="mbw-pill tone-green">Reconciled</span></td>
                            <td>
                                <form method="post" action="<?= e(url('admin/reconciliation.php')) ?>" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="unreconcile_entry">
                                    <input type="hidden" name="ledger_id" value="<?= (int) $selectedLedgerId ?>">
                                    <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
                                    <button type="submit" class="button secondary" style="min-height:30px;padding:3px 10px">Undo</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
