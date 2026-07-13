<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_or_admin();
require_company_context();
accounting_module_repair_database();

// Handle fiscal year selection for accounting module
if (!empty($_GET['fiscal_year_id'])) {
    $_SESSION['fiscal_year_id'] = (int) $_GET['fiscal_year_id'];
}

$pageTitle = 'Vouchers';
$pageSubtitle = 'Post balanced voucher entries, manage approvals, fiscal years, and period locks.';
$company = current_company();
$fiscalYear = current_fiscal_year();
$currentAdmin = current_user();
$companyId = (int) ($company['id'] ?? 0);
$fiscalYearId = (int) ($fiscalYear['id'] ?? 0);
$userId = (int) ($currentAdmin['id'] ?? 0);
$hasVoucherApprovals = column_exists('vouchers', 'approval_state');
$lockedThrough = period_locked_through($companyId, $fiscalYearId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'save_period_lock' && user_can('admin')) {
        require_permission('accounting', 'edit');

        $lockedThroughInput = trim((string) ($_POST['locked_through'] ?? ''));
        if ($companyId <= 0 || $fiscalYearId <= 0 || $lockedThroughInput === '' || !table_exists('fiscal_period_locks')) {
            flash('error', 'Select a valid fiscal period lock date.');
            redirect('admin/accounting.php');
        }

        db()->prepare('
            INSERT INTO fiscal_period_locks (company_id, fiscal_year_id, locked_through, locked_by)
            VALUES (:company_id, :fiscal_year_id, :locked_through, :locked_by)
            ON DUPLICATE KEY UPDATE locked_through = VALUES(locked_through), locked_by = VALUES(locked_by)
        ')->execute([
            'company_id' => $companyId,
            'fiscal_year_id' => $fiscalYearId,
            'locked_through' => $lockedThroughInput,
            'locked_by' => $userId,
        ]);

        log_activity('fiscal_period_lock', $fiscalYearId, 'updated', 'Accounting period locked through ' . $lockedThroughInput . '.', $userId);
        flash('success', 'Fiscal period lock saved.');
        redirect('admin/accounting.php');
    }

    if (in_array($postAction, ['approve_voucher', 'reject_voucher'], true) && $hasVoucherApprovals) {
        if (!user_can('approve')) {
            flash('error', 'You do not have approval permission.');
            redirect('admin/accounting.php');
        }

        require_permission('accounting', 'approve');

        $voucherId = (int) ($_POST['voucher_id'] ?? 0);
        $rejectionReason = trim((string) ($_POST['rejection_reason'] ?? ''));
        $voucherStmt = db()->prepare("SELECT id, approval_state FROM vouchers WHERE id = :id AND company_id = :company_id AND fiscal_year_id = :fiscal_year_id LIMIT 1");
        $voucherStmt->execute(['id' => $voucherId, 'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);
        $voucher = $voucherStmt->fetch();
        if (!$voucher || (string) ($voucher['approval_state'] ?? '') !== 'pending_approval') {
            flash('error', 'Voucher is not pending approval.');
            redirect('admin/accounting.php');
        }
        if ($postAction === 'reject_voucher' && $rejectionReason === '') {
            flash('error', 'Rejection reason is required.');
            redirect('admin/accounting.php');
        }

        if ($postAction === 'approve_voucher') {
            db()->prepare("UPDATE vouchers SET approval_state = 'approved', status = 'posted', approved_by = :approved_by, approved_at = NOW(), posted_by = :posted_by, posted_at = NOW(), rejection_reason = NULL WHERE id = :id AND company_id = :company_id")
                ->execute(['approved_by' => $userId, 'posted_by' => $userId, 'id' => $voucherId, 'company_id' => $companyId]);
            security_event('voucher_approved', 'success', 'Voucher #' . $voucherId . ' approved.', $companyId, $userId);
            log_activity('voucher', $voucherId, 'approved', 'Voucher approved and posted.', $userId);
            flash('success', 'Voucher approved and posted.');
        } else {
            db()->prepare("UPDATE vouchers SET approval_state = 'rejected', status = 'cancelled', approved_by = :approved_by, approved_at = NOW(), rejection_reason = :rejection_reason WHERE id = :id AND company_id = :company_id")
                ->execute(['approved_by' => $userId, 'rejection_reason' => $rejectionReason, 'id' => $voucherId, 'company_id' => $companyId]);
            security_event('voucher_rejected', 'success', 'Voucher #' . $voucherId . ' rejected.', $companyId, $userId);
            log_activity('voucher', $voucherId, 'rejected', 'Voucher rejected: ' . $rejectionReason, $userId);
            flash('success', 'Voucher rejected.');
        }
        redirect('admin/accounting.php');
    }


    if ($postAction === 'create_voucher') {
        if (!user_can('create')) {
            flash('error', 'You do not have permission to create vouchers.');
            redirect('admin/accounting.php');
        }

        require_permission('accounting', 'create');

        $voucherType = (string) ($_POST['voucher_type'] ?? 'journal');
        $voucherNo = trim((string) ($_POST['voucher_no'] ?? ''));
        $voucherDate = (string) ($_POST['voucher_date'] ?? date('Y-m-d'));
        $partyId = (int) ($_POST['party_id'] ?? 0);
        $referenceNo = trim((string) ($_POST['reference_no'] ?? ''));
        $narration = trim((string) ($_POST['narration'] ?? ''));
        $ledgerIds = $_POST['ledger_id'] ?? [];
        $entryTypes = $_POST['entry_type'] ?? [];
        $amounts = $_POST['amount'] ?? [];
        $memos = $_POST['memo'] ?? [];

        $allowedVoucherTypes = ['payment', 'receipt', 'journal', 'sales', 'purchase', 'contra', 'debit_note', 'credit_note'];
        if ($companyId <= 0 || $fiscalYearId <= 0 || !in_array($voucherType, $allowedVoucherTypes, true)) {
            flash('error', 'Company, fiscal year, and voucher type are required.');
            redirect('admin/accounting.php');
        }

        if ($voucherNo === '') {
            $voucherNo = strtoupper(substr($voucherType, 0, 2)) . '-' . date('Ymd-His');
        }

        if (is_period_locked($companyId, $fiscalYearId, $voucherDate !== '' ? $voucherDate : date('Y-m-d'))) {
            flash('error', 'This voucher date is inside a locked accounting period.');
            redirect('admin/accounting.php');
        }

        $entries = [];
        $debitTotal = 0.0;
        $creditTotal = 0.0;
        $cashBankViolation = false;

        foreach ($ledgerIds as $index => $ledgerIdRaw) {
            $ledgerId = (int) $ledgerIdRaw;
            $entryType = (string) ($entryTypes[$index] ?? '');
            $amount = round((float) ($amounts[$index] ?? 0), 2);

            if ($ledgerId <= 0 || $amount <= 0 || !in_array($entryType, ['debit', 'credit'], true)) {
                continue;
            }

            $ledgerCheck = db()->prepare("SELECT l.id, COALESCE(g.is_cash_or_bank, 0) AS is_cash_or_bank
                FROM ledgers l
                LEFT JOIN ledger_groups g ON g.id = l.group_id
                WHERE l.id = :id AND l.company_id = :company_id AND l.status = 'active' LIMIT 1");
            $ledgerCheck->execute([
                'id' => $ledgerId,
                'company_id' => $companyId,
            ]);
            $ledgerRow = $ledgerCheck->fetch();
            if (!$ledgerRow) {
                continue;
            }

            $isCashOrBank = (int) $ledgerRow['is_cash_or_bank'] === 1;
            $mustBeCashOrBank = $voucherType === 'contra'
                || ($voucherType === 'payment' && $entryType === 'credit')
                || ($voucherType === 'receipt' && $entryType === 'debit');
            if ($mustBeCashOrBank && !$isCashOrBank) {
                $cashBankViolation = true;
            }

            $entries[] = [
                'ledger_id' => $ledgerId,
                'entry_type' => $entryType,
                'amount' => $amount,
                'memo' => trim((string) ($memos[$index] ?? '')),
            ];

            if ($entryType === 'debit') {
                $debitTotal += $amount;
            } else {
                $creditTotal += $amount;
            }
        }

        if ($cashBankViolation) {
            flash('error', 'Payment, receipt, and contra vouchers can only use ledgers created under a Bank/Cash group.');
            redirect('admin/accounting.php');
        }

        if (count($entries) < 2 || round($debitTotal, 2) !== round($creditTotal, 2)) {
            flash('error', 'Voucher must contain at least two valid entries and total debit must equal total credit.');
            redirect('admin/accounting.php');
        }

        try {
            $needsApproval = $hasVoucherApprovals && approvals_enabled() && !user_can('approve');
            $voucherId = create_voucher_with_entries([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId,
                'voucher_no' => $voucherNo,
                'voucher_type' => $voucherType,
                'source_type' => 'manual_accounting',
                'source_id' => null,
                'party_id' => $partyId > 0 ? $partyId : null,
                'reference_no' => $referenceNo !== '' ? $referenceNo : null,
                'voucher_date' => $voucherDate !== '' ? $voucherDate : date('Y-m-d'),
                'narration' => $narration,
                'total_amount' => $debitTotal,
                'status' => $needsApproval ? 'draft' : 'posted',
                'approval_state' => $needsApproval ? 'pending_approval' : 'approved',
                'submitted_by' => $userId,
                'approved_by' => $needsApproval ? null : $userId,
                'approved_at' => $needsApproval ? null : date('Y-m-d H:i:s'),
                'posted_by' => $needsApproval ? null : $userId,
                'posted_at' => $needsApproval ? null : date('Y-m-d H:i:s'),
            ], $entries);
            if ($voucherId > 0) {
                security_event('voucher_posted', 'success', 'Voucher #' . $voucherId . ' posted.', $companyId, $userId);
            }
            flash('success', $needsApproval ? 'Voucher submitted for approval.' : 'Voucher posted.');
        } catch (Throwable $exception) {
            flash('error', 'Could not post voucher. Check voucher number uniqueness.');
        }

        redirect('admin/accounting.php');
    }

}

$ledgers = [];
$vouchers = [];
$balances = [];
$parties = [];
$daybookEntries = [];
$shareholdings = [];
$availableInvesteeCompanies = [];

if ($company && table_exists('ledgers')) {
    $ledgerStmt = db()->prepare("SELECT l.*, g.master_key, COALESCE(g.is_cash_or_bank, 0) AS is_cash_or_bank
        FROM ledgers l
        LEFT JOIN ledger_groups g ON g.id = l.group_id
        WHERE l.company_id = :company_id AND l.status = 'active'
        ORDER BY l.code ASC");
    $ledgerStmt->execute(['company_id' => (int) $company['id']]);
    $ledgers = $ledgerStmt->fetchAll();

    if (table_exists('accounting_parties')) {
        $partyStmt = db()->prepare("SELECT id, code, name, party_type FROM accounting_parties WHERE company_id = :company_id AND status = 'active' ORDER BY name ASC");
        $partyStmt->execute(['company_id' => (int) $company['id']]);
        $parties = $partyStmt->fetchAll();
    }

    $voucherStmt = db()->prepare('
        SELECT v.*, p.code AS party_code, p.name AS party_name
        FROM vouchers v
        LEFT JOIN accounting_parties p ON p.id = v.party_id
        WHERE v.company_id = :company_id AND v.fiscal_year_id = :fiscal_year_id
        ORDER BY COALESCE(v.voucher_date, DATE(v.posted_at)) DESC, v.id DESC
        LIMIT 100
    ');
    $voucherStmt->execute([
        'company_id' => (int) $company['id'],
        'fiscal_year_id' => (int) ($fiscalYear['id'] ?? 0),
    ]);
    $vouchers = $voucherStmt->fetchAll();

    $balanceStmt = db()->prepare("SELECT l.id, l.code, l.name, l.type, COALESCE(SUM(CASE WHEN v.id IS NOT NULL AND ve.entry_type='debit' THEN ve.amount ELSE 0 END),0) AS debit_total, COALESCE(SUM(CASE WHEN v.id IS NOT NULL AND ve.entry_type='credit' THEN ve.amount ELSE 0 END),0) AS credit_total FROM ledgers l LEFT JOIN voucher_entries ve ON ve.ledger_id = l.id LEFT JOIN vouchers v ON v.id = ve.voucher_id AND v.company_id = l.company_id AND v.fiscal_year_id = :fiscal_year_id AND v.status = 'posted' WHERE l.company_id = :company_id GROUP BY l.id, l.code, l.name, l.type ORDER BY l.code ASC");
    $balanceStmt->execute([
        'company_id' => (int) $company['id'],
        'fiscal_year_id' => (int) ($fiscalYear['id'] ?? 0),
    ]);
    $balances = $balanceStmt->fetchAll();

    $daybookStmt = db()->prepare("
        SELECT v.id, v.voucher_no, v.voucher_type, COALESCE(v.voucher_date, DATE(v.posted_at)) AS voucher_date,
               v.reference_no, v.narration, p.name AS party_name, l.code AS ledger_code, l.name AS ledger_name,
               ve.entry_type, ve.amount, ve.memo
        FROM vouchers v
        INNER JOIN voucher_entries ve ON ve.voucher_id = v.id
        INNER JOIN ledgers l ON l.id = ve.ledger_id
        LEFT JOIN accounting_parties p ON p.id = v.party_id
        WHERE v.company_id = :company_id AND v.fiscal_year_id = :fiscal_year_id
        ORDER BY COALESCE(v.voucher_date, DATE(v.posted_at)) DESC, v.id DESC, ve.id ASC
        LIMIT 240
    ");
    $daybookStmt->execute([
        'company_id' => (int) $company['id'],
        'fiscal_year_id' => (int) ($fiscalYear['id'] ?? 0),
    ]);
    $daybookEntries = $daybookStmt->fetchAll();
}

if ($company && table_exists('company_shareholdings')) {
    $shareholdings = company_shareholdings_for_parent((int) $company['id']);
    $companyRows = db()->prepare('SELECT id, name, code FROM companies WHERE id <> :id AND is_active = 1 ORDER BY name ASC');
    $companyRows->execute(['id' => (int) $company['id']]);
    $availableInvesteeCompanies = $companyRows->fetchAll();
}

$voucherTotal = array_sum(array_map(static fn (array $voucher): float => (float) $voucher['total_amount'], $vouchers));
$ledgerDebitTotal = array_sum(array_map(static fn (array $row): float => (float) $row['debit_total'], $balances));
$ledgerCreditTotal = array_sum(array_map(static fn (array $row): float => (float) $row['credit_total'], $balances));
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<nav class="mbw-tabbar" aria-label="Voucher workspace">
    <a class="mbw-tab is-active" href="<?= e(url('admin/accounting.php')) ?>"><?= icon('journal') ?>Voucher Register</a>
    <a class="mbw-tab" href="<?= e(url('admin/voucher-form.php')) ?>"><?= icon('receipt-voucher') ?>New Voucher</a>
    <a class="mbw-tab" href="<?= e(url('admin/voucher-import.php')) ?>"><?= icon('upload') ?>Import from Excel</a>
</nav>

<section class="mbw-kpi-grid">
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Posted vouchers</span><div class="mbw-kpi-value"><?= e((string) count($vouchers)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= e(site_currency_symbol()) ?><?= e(number_format($voucherTotal, 2)) ?> total</span></span></div><span class="mbw-chip tone-blue"><?= icon('journal') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Total debits</span><div class="mbw-kpi-value"><?= e(site_currency_symbol()) ?><?= e(number_format($ledgerDebitTotal, 2)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Selected fiscal year</span></span></div><span class="mbw-chip tone-green"><?= icon('trend-up') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Total credits</span><div class="mbw-kpi-value"><?= e(site_currency_symbol()) ?><?= e(number_format($ledgerCreditTotal, 2)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Selected fiscal year</span></span></div><span class="mbw-chip tone-red"><?= icon('trend-down') ?></span></article>
    <a class="mbw-kpi" href="<?= e(url('admin/accounting-parties.php')) ?>"><div><span class="mbw-kpi-label">Active parties</span><div class="mbw-kpi-value"><?= e((string) count($parties)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Available for voucher posting</span></span></div><span class="mbw-chip tone-purple"><?= icon('users') ?></span></a>
</section>

<?php if ($lockedThrough !== null): ?>
    <div class="alert alert-info">Accounting entries dated on or before <?= e($lockedThrough) ?> are locked for this fiscal year.</div>
<?php endif; ?>

<!-- Fiscal Year Selector - Only in Accounting Module -->
<?php 
$fiscalYears = table_exists('fiscal_years') ? fiscal_years_for_company((int) $company['id']) : [];
if ($fiscalYears):
?>
<section class="mbw-card">
    <div class="mbw-card-head"><h2>Fiscal Year</h2></div>
    <form method="GET" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
        <label for="fiscal_year" style="margin: 0; font-weight: 600; color: var(--mbw-heading);">Select Fiscal Year:</label>
        <select name="fiscal_year_id" id="fiscal_year" onchange="document.location='<?= url("admin/accounting.php?fiscal_year_id=") ?>' + this.value;">
            <?php foreach ($fiscalYears as $fy): ?>
                <option value="<?= (int) $fy['id'] ?>" <?= ((int) ($fiscalYear['id'] ?? 0) === (int) $fy['id']) ? 'selected' : '' ?>>
                    <?= e($fy['label']) ?> (<?= e($fy['start_date']) ?> - <?= e($fy['end_date']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ((int) ($fiscalYear['is_active'] ?? 0) === 1): ?>
            <span class="mbw-pill tone-green">Active</span>
        <?php else: ?>
            <span class="mbw-pill tone-red">Closed</span>
        <?php endif; ?>
    </form>
</section>
<?php endif; ?>

<section class="mbw-card">
    <div class="mbw-card-head"><h2>Create Vouchers</h2><div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('admin/settings.php')) ?>">Fiscal setup moved to Settings</a></div></div>
    <div class="mbw-qa-grid" id="post-voucher">
        <a class="mbw-qa" href="<?= e(url('admin/voucher-form.php')) ?>">
            <span class="mbw-chip is-square tone-green"><?= icon('receipt-voucher') ?></span>
            <div><strong><?= approvals_enabled() && !user_can('approve') ? 'Submit Voucher' : 'Post Voucher' ?></strong><span>Guided form with validation checklist &amp; attachments</span></div>
        </a>
        <a class="mbw-qa" href="<?= e(url('admin/voucher-form.php?type=journal')) ?>">
            <span class="mbw-chip is-square tone-blue"><?= icon('journal') ?></span>
            <div><strong>Journal Voucher</strong><span>Create new journal entry</span></div>
        </a>
        <a class="mbw-qa" href="<?= e(url('admin/voucher-form.php?type=receipt')) ?>">
            <span class="mbw-chip is-square tone-teal"><?= icon('trend-up') ?></span>
            <div><strong>Receipt Voucher</strong><span>Record money received</span></div>
        </a>
        <a class="mbw-qa" href="<?= e(url('admin/voucher-form.php?type=payment')) ?>">
            <span class="mbw-chip is-square tone-amber"><?= icon('card') ?></span>
            <div><strong>Payment Voucher</strong><span>Record money paid out</span></div>
        </a>
    </div>
</section>

<?php if ($shareholdings !== []): ?>
<section class="mbw-card">
    <div class="mbw-card-head"><h2>Shareholding and consolidation rules</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr><th>Investee</th><th class="is-numeric">Ownership</th><th>Relationship</th><th>Method</th><th>Effective From</th></tr>
        </thead>
        <tbody>
            <?php foreach ($shareholdings as $shareholding): ?>
                <tr>
                    <td><?= e($shareholding['investee_name'] . ' / ' . $shareholding['investee_code']) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $shareholding['ownership_percent'], 2)) ?>%</td>
                    <td><?= e(str_replace('_', ' ', $shareholding['relationship_type'])) ?></td>
                    <td><?= e(str_replace('_', ' ', $shareholding['consolidation_method'])) ?></td>
                    <td><?= e($shareholding['effective_from'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>

<section class="mbw-card">
    <div class="mbw-card-head"><h2>Ledger balances</h2><div class="mbw-card-tools"><?php if ($company && $fiscalYear): ?><a class="mbw-view-all" href="<?= e(url('admin/export-ledger.php?format=pdf')) ?>" target="_blank">Export PDF</a> <a class="mbw-view-all" href="<?= e(url('admin/export-ledger.php?format=excel')) ?>">Export Excel</a><?php endif; ?></div></div>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr><th>Code</th><th>Name</th><th>Type</th><th class="is-numeric">Total Debit</th><th class="is-numeric">Total Credit</th><th class="is-numeric">Balance</th></tr>
        </thead>
        <tbody>
            <?php if ($balances === []): ?>
                <tr><td colspan="6">No ledger balances available yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($balances as $row): ?>
                <?php $balance = (float) $row['debit_total'] - (float) $row['credit_total']; ?>
                <tr>
                    <td><?= e($row['code']) ?></td>
                    <td><?= e($row['name']) ?></td>
                    <td><?= e($row['type']) ?></td>
                    <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $row['debit_total'], 2)) ?></td>
                    <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $row['credit_total'], 2)) ?></td>
                    <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format($balance, 2)) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="mbw-card">
    <div class="mbw-card-head"><h2>Posted vouchers</h2><div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('admin/voucher-form.php')) ?>">＋ New Voucher</a></div></div>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr><th>Date</th><th>Voucher No</th><th>Type</th><th>Party</th><th>Reference</th><th class="is-numeric">Total</th><th>Status</th><th>Posted At</th><?php if ($hasVoucherApprovals): ?><th>Approval</th><?php endif; ?></tr>
        </thead>
        <tbody>
            <?php if ($vouchers === []): ?>
                <tr><td colspan="<?= $hasVoucherApprovals ? '9' : '8' ?>">No vouchers posted for selected context.</td></tr>
            <?php endif; ?>
            <?php foreach ($vouchers as $voucher): ?>
                <tr>
                    <td><?= e($voucher['voucher_date'] ?? date('Y-m-d', strtotime((string) $voucher['posted_at']))) ?></td>
                    <td><?= e($voucher['voucher_no']) ?></td>
                    <td><?= e($voucher['voucher_type']) ?></td>
                    <td><?= e($voucher['party_name'] ?? '') ?></td>
                    <td><?= e($voucher['reference_no'] ?? '') ?></td>
                    <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $voucher['total_amount'], 2)) ?></td>
                    <td><?php
                        $voucherStatus = (string) $voucher['status'];
                        $statusTone = ['posted' => 'green', 'draft' => 'blue', 'cancelled' => 'red'][$voucherStatus] ?? 'gray';
                    ?><span class="mbw-pill tone-<?= e($statusTone) ?>"><?= e($voucherStatus) ?></span></td>
                    <td><?= e($voucher['posted_at']) ?></td>
                    <?php if ($hasVoucherApprovals): ?>
                        <td>
                            <?php
                                $approvalState = (string) ($voucher['approval_state'] ?? 'approved');
                                $approvalTone = ['approved' => 'green', 'pending_approval' => 'amber', 'rejected' => 'red'][$approvalState] ?? 'gray';
                            ?>
                            <span class="mbw-pill tone-<?= e($approvalTone) ?>"><?= e(str_replace('_', ' ', $approvalState)) ?></span>
                            <?php if (($voucher['approval_state'] ?? '') === 'pending_approval' && user_can('approve')): ?>
                                <form method="post" class="inline-action-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="approve_voucher">
                                    <input type="hidden" name="voucher_id" value="<?= e((int) $voucher['id']) ?>">
                                    <button type="submit" class="button secondary">Approve</button>
                                </form>
                                <form method="post" class="inline-action-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="reject_voucher">
                                    <input type="hidden" name="voucher_id" value="<?= e((int) $voucher['id']) ?>">
                                    <input type="text" name="rejection_reason" placeholder="Reason" required>
                                    <button type="submit" class="button secondary">Reject</button>
                                </form>
                            <?php elseif (!empty($voucher['rejection_reason'])): ?>
                                <br><small class="muted"><?= e($voucher['rejection_reason']) ?></small>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="mbw-card" id="daybook">
    <div class="mbw-card-head"><h2>Daybook</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr><th>Date</th><th>Voucher</th><th>Type</th><th>Party</th><th>Ledger</th><th class="is-numeric">Debit</th><th class="is-numeric">Credit</th><th>Memo</th></tr>
        </thead>
        <tbody>
            <?php if ($daybookEntries === []): ?>
                <tr><td colspan="8">No daybook entries available for selected context.</td></tr>
            <?php endif; ?>
            <?php foreach ($daybookEntries as $entry): ?>
                <tr>
                    <td><?= e($entry['voucher_date']) ?></td>
                    <td><?= e($entry['voucher_no']) ?></td>
                    <td><?= e($entry['voucher_type']) ?></td>
                    <td><?= e($entry['party_name'] ?? '') ?></td>
                    <td><?= e($entry['ledger_code'] . ' - ' . $entry['ledger_name']) ?></td>
                    <td class="is-numeric"><?= $entry['entry_type'] === 'debit' ? e(site_currency_symbol()) . e(number_format((float) $entry['amount'], 2)) : '' ?></td>
                    <td class="is-numeric"><?= $entry['entry_type'] === 'credit' ? e(site_currency_symbol()) . e(number_format((float) $entry['amount'], 2)) : '' ?></td>
                    <td><?= e($entry['memo'] ?: $entry['narration']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
