<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_or_admin();
require_company_context();

$pageTitle = 'Audit Trail & Approvals';
$pageSubtitle = 'Track who did what, when, and under which company scope.';
$company = current_company();
$companyId = (int) ($company['id'] ?? 0);
$fiscalYear = current_fiscal_year();
$fiscalYearId = (int) ($fiscalYear['id'] ?? 0);
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$canApprove = user_can('approve');
$sym = site_currency_symbol();

// ---------------------------------------------------------------------------
// Approve / reject a pending voucher.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $voucherId = (int) ($_POST['voucher_id'] ?? 0);

    if (!$canApprove) {
        flash('error', 'Your role cannot approve or reject vouchers.');
        redirect('admin/audit-trail.php');
    }
    if (staff_accountant_forces_approval()) {
        flash('error', 'Vouchers in client books are approved by the client or the firm admin, not by staff.');
        redirect('admin/audit-trail.php');
    }

    require_permission('accounting', 'approve');

    $voucherStmt = db()->prepare("SELECT * FROM vouchers WHERE id = :id AND company_id = :company_id AND approval_state = 'pending_approval' LIMIT 1");
    $voucherStmt->execute(['id' => $voucherId, 'company_id' => $companyId]);
    $voucher = $voucherStmt->fetch();

    if (!$voucher) {
        flash('error', 'That voucher is not awaiting approval.');
        redirect('admin/audit-trail.php');
    }

    if ($action === 'approve_voucher') {
        db()->prepare("UPDATE vouchers SET approval_state = 'approved', status = 'posted', approved_by = :uid, approved_at = NOW() WHERE id = :id")
            ->execute(['uid' => $userId, 'id' => $voucherId]);
        security_event('voucher_approved', 'success', 'Voucher #' . $voucherId . ' approved.', $companyId, $userId);
        log_field_changes('voucher', $voucherId, ['approval_state' => 'pending_approval'], ['approval_state' => 'approved'], $companyId, $userId);
        log_activity('voucher', $voucherId, 'approved', 'Voucher ' . $voucher['voucher_no'] . ' approved and posted.', $userId);
        flash('success', 'Voucher ' . $voucher['voucher_no'] . ' approved and posted.');
        redirect('admin/audit-trail.php');
    }

    if ($action === 'reject_voucher') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        db()->prepare("UPDATE vouchers SET approval_state = 'rejected', status = 'cancelled', approved_by = :uid, approved_at = NOW(), rejection_reason = :reason WHERE id = :id")
            ->execute(['uid' => $userId, 'reason' => $reason !== '' ? $reason : 'Rejected', 'id' => $voucherId]);
        security_event('voucher_rejected', 'success', 'Voucher #' . $voucherId . ' rejected.', $companyId, $userId);
        log_field_changes('voucher', $voucherId, ['approval_state' => 'pending_approval'], ['approval_state' => 'rejected'], $companyId, $userId);
        log_activity('voucher', $voucherId, 'rejected', 'Voucher ' . $voucher['voucher_no'] . ' rejected: ' . $reason, $userId);
        flash('success', 'Voucher ' . $voucher['voucher_no'] . ' rejected.');
        redirect('admin/audit-trail.php');
    }
}

// ---------------------------------------------------------------------------
// Approval queue (pending vouchers).
// ---------------------------------------------------------------------------
$pendingVouchers = [];
$approvalSummary = ['approved' => 0, 'rejected' => 0, 'pending' => 0, 'draft' => 0];
if (table_exists('vouchers') && column_exists('vouchers', 'approval_state')) {
    $queueStmt = db()->prepare("
        SELECT v.*, u.name AS submitter_name, ap.name AS party_name
        FROM vouchers v
        LEFT JOIN users u ON u.id = v.submitted_by
        LEFT JOIN accounting_parties ap ON ap.id = v.party_id
        WHERE v.company_id = :company_id AND v.approval_state = 'pending_approval'
        ORDER BY v.created_at ASC
        LIMIT 100
    ");
    $queueStmt->execute(['company_id' => $companyId]);
    $pendingVouchers = $queueStmt->fetchAll();

    $sumStmt = db()->prepare('SELECT approval_state, COUNT(*) AS c FROM vouchers WHERE company_id = :company_id GROUP BY approval_state');
    $sumStmt->execute(['company_id' => $companyId]);
    foreach ($sumStmt->fetchAll() as $row) {
        $state = (string) $row['approval_state'];
        $key = $state === 'pending_approval' ? 'pending' : $state;
        if (isset($approvalSummary[$key])) {
            $approvalSummary[$key] = (int) $row['c'];
        }
    }
}

// ---------------------------------------------------------------------------
// Activity log (filterable).
// ---------------------------------------------------------------------------
$moduleFilter = trim((string) ($_GET['module'] ?? ''));
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$logSql = '
    SELECT al.*, u.name AS actor_name
    FROM activity_logs al
    LEFT JOIN users u ON u.id = al.actor_id
    WHERE 1 = 1
';
$logParams = [];
// activity_logs has no company_id column, so the feed is scoped by the actor's
// company: a non-super-admin only sees activity by users of a company they may
// access. Previously every admin AND staff member saw the platform-wide feed,
// including other tenants' financial descriptions embedded in `details`.
if (!user_is_super_admin()) {
    $auditCompanyIds = authorized_company_ids();
    if ($auditCompanyIds === []) {
        $logSql .= ' AND 1 = 0';
    } else {
        $ph = [];
        foreach ($auditCompanyIds as $i => $cid) {
            $key = 'ac' . $i;
            $ph[] = ':' . $key;
            $logParams[$key] = $cid;
        }
        $logSql .= ' AND u.company_id IN (' . implode(',', $ph) . ')';
    }
}
if ($moduleFilter !== '') {
    $logSql .= ' AND al.entity_type = :module';
    $logParams['module'] = $moduleFilter;
}
if ($searchQuery !== '') {
    $logSql .= ' AND (al.action LIKE :q OR al.details LIKE :q2)';
    $logParams['q'] = '%' . $searchQuery . '%';
    $logParams['q2'] = '%' . $searchQuery . '%';
}
$logSql .= ' ORDER BY al.created_at DESC LIMIT 60';
$logStmt = db()->prepare($logSql);
$logStmt->execute($logParams);
$activityRows = $logStmt->fetchAll();

$moduleOptions = [];
if (table_exists('activity_logs')) {
    $moduleOptions = db()->query('SELECT DISTINCT entity_type FROM activity_logs ORDER BY entity_type ASC')->fetchAll(PDO::FETCH_COLUMN);
}

// ---------------------------------------------------------------------------
// Recent field-level change history.
// ---------------------------------------------------------------------------
$changeRows = [];
if (table_exists('audit_change_history')) {
    $changeStmt = db()->prepare('
        SELECT ch.*, u.name AS actor_name
        FROM audit_change_history ch
        LEFT JOIN users u ON u.id = ch.actor_id
        WHERE ch.company_id = :company_id OR ch.company_id IS NULL
        ORDER BY ch.created_at DESC
        LIMIT 40
    ');
    $changeStmt->execute(['company_id' => $companyId]);
    $changeRows = $changeStmt->fetchAll();
}

function audit_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        }
    }
    $query = http_build_query($params);
    return url('admin/audit-trail.php' . ($query !== '' ? '?' . $query : ''));
}

$bodyClass = 'admin-layout accounting-module-page reports-center-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-kpi-grid">
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Pending Approval</span><div class="mbw-kpi-value"><?= e((string) $approvalSummary['pending']) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">awaiting review</span></span></div><span class="mbw-chip tone-amber"><?= icon('compliance') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Approved</span><div class="mbw-kpi-value"><?= e((string) $approvalSummary['approved']) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">posted vouchers</span></span></div><span class="mbw-chip tone-green"><?= icon('dashboard') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Rejected</span><div class="mbw-kpi-value"><?= e((string) $approvalSummary['rejected']) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">returned entries</span></span></div><span class="mbw-chip tone-red"><?= icon('tickets') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Activity Entries</span><div class="mbw-kpi-value"><?= e((string) count($activityRows)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">latest actions</span></span></div><span class="mbw-chip tone-blue"><?= icon('documents') ?></span></article>
</section>

<section class="mbw-card">
    <div class="mbw-card-head"><h2>Approval Queue (Pending Action)</h2></div>
    <?php if (!approvals_enabled()): ?>
        <p style="color:var(--mbw-muted)">Approval workflow is currently <strong>off</strong>. Vouchers post immediately. Turn it on in Settings → Fiscal &amp; Accounting Controls to route new manual vouchers through this queue.</p>
    <?php endif; ?>
    <div style="overflow-x:auto">
        <table class="rc-table">
            <thead>
                <tr><th>Voucher No.</th><th>Type</th><th>Party</th><th class="is-numeric">Amount</th><th>Submitted By</th><th>Submitted</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php if ($pendingVouchers === []): ?>
                    <tr><td colspan="7">No vouchers are awaiting approval.</td></tr>
                <?php endif; ?>
                <?php foreach ($pendingVouchers as $voucher): ?>
                    <tr>
                        <td><strong><?= e($voucher['voucher_no']) ?></strong></td>
                        <td><span class="mbw-pill tone-blue"><?= e(ucfirst(str_replace('_', ' ', (string) $voucher['voucher_type']))) ?></span></td>
                        <td><?= e($voucher['party_name'] ?? '-') ?></td>
                        <td class="is-numeric"><?= e($sym) ?><?= e(number_format((float) $voucher['total_amount'], 2)) ?></td>
                        <td><?= e($voucher['submitter_name'] ?? '-') ?></td>
                        <td><?= e(date('d M Y H:i', strtotime((string) $voucher['created_at']))) ?></td>
                        <td>
                            <?php if ($canApprove): ?>
                                <div class="audit-approve-actions">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="approve_voucher">
                                        <input type="hidden" name="voucher_id" value="<?= e((int) $voucher['id']) ?>">
                                        <button type="submit" class="button audit-approve">Approve</button>
                                    </form>
                                    <details class="reference-menu">
                                        <summary>Reject</summary>
                                        <form method="post" class="audit-reject-form">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="reject_voucher">
                                            <input type="hidden" name="voucher_id" value="<?= e((int) $voucher['id']) ?>">
                                            <input type="text" name="reason" placeholder="Reason for rejection" required>
                                            <button type="submit" class="button secondary">Confirm reject</button>
                                        </form>
                                    </details>
                                </div>
                            <?php else: ?>
                                <span style="color:var(--mbw-muted)">Approver only</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;align-items:start">
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Activity Log</h2></div>
        <p style="color:var(--mbw-muted);margin-top:0">Chronological record of actions across modules.</p>
        <form method="get" class="reference-filter-group audit-filter">
            <select name="module">
                <option value="">All modules</option>
                <?php foreach ($moduleOptions as $moduleOption): ?>
                    <option value="<?= e($moduleOption) ?>" <?= $moduleFilter === $moduleOption ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', (string) $moduleOption))) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="reference-search"><?= icon('portal') ?><input type="search" name="q" value="<?= e($searchQuery) ?>" placeholder="Search actions or details"></div>
            <button class="button secondary" type="submit"><?= icon('settings') ?>Apply</button>
        </form>
        <div style="overflow-x:auto">
            <table class="rc-table">
                <thead><tr><th>Date &amp; Time</th><th>User</th><th>Module</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
                <tbody>
                    <?php if ($activityRows === []): ?><tr><td colspan="6">No activity recorded yet.</td></tr><?php endif; ?>
                    <?php foreach ($activityRows as $row): ?>
                        <tr>
                            <td><?= e(date('d M Y H:i', strtotime((string) $row['created_at']))) ?></td>
                            <td><?= e($row['actor_name'] ?? 'System') ?></td>
                            <td><span class="mbw-pill tone-blue"><?= e(ucfirst(str_replace('_', ' ', (string) $row['entity_type']))) ?></span></td>
                            <td><?= e(ucfirst(str_replace('_', ' ', (string) $row['action']))) ?></td>
                            <td><?= e((string) ($row['details'] ?? '')) ?></td>
                            <td><?= e((string) ($row['ip_address'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <aside class="mbw-card audit-change-panel">
        <div class="mbw-card-head"><h2>Field-Level Changes</h2></div>
        <p style="color:var(--mbw-muted);margin-top:0">Before &rarr; after history.</p>
        <div style="overflow-x:auto">
            <table class="rc-table">
                <thead><tr><th>When</th><th>Entity</th><th>Field</th><th>Old → New</th><th>By</th></tr></thead>
                <tbody>
                    <?php if ($changeRows === []): ?><tr><td colspan="5">No field changes recorded yet.</td></tr><?php endif; ?>
                    <?php foreach ($changeRows as $change): ?>
                        <tr>
                            <td><?= e(date('d M H:i', strtotime((string) $change['created_at']))) ?></td>
                            <td><?= e(ucfirst(str_replace('_', ' ', (string) $change['entity_type']))) ?> #<?= e((int) $change['entity_id']) ?></td>
                            <td><?= e((string) $change['field_name']) ?></td>
                            <td><span class="audit-old"><?= e((string) ($change['old_value'] ?? '—')) ?></span> → <span class="audit-new"><?= e((string) ($change['new_value'] ?? '—')) ?></span></td>
                            <td><?= e($change['actor_name'] ?? 'System') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </aside>
</div>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
