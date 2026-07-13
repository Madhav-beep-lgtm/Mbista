<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/reports_engine.php';

require_staff_or_admin();
if ((string) (current_user()['role'] ?? '') === 'admin') {
    require_company_context();
}
accounting_module_repair_database();

$clientId = (int) ($_GET['client'] ?? ($_POST['client_id'] ?? 0));
$profileStmt = db()->prepare('SELECT * FROM client_profiles WHERE id = :id LIMIT 1');
$profileStmt->execute(['id' => $clientId]);
$client = $profileStmt->fetch();
$access = $client ? client_books_access_level($client) : '';
if (!$client || $access === '') {
    flash('error', 'You do not have access to that client\'s books.');
    redirect('admin/manage-clients.php');
}
$booksCompanyId = (int) ($client['books_company_id'] ?? 0);
if ($booksCompanyId <= 0) {
    flash('error', 'Books are not provisioned for this client yet.');
    redirect('admin/manage-clients.php');
}

$fyStmt = db()->prepare('SELECT * FROM fiscal_years WHERE company_id = :cid ORDER BY is_default DESC, is_active DESC, id DESC LIMIT 1');
$fyStmt->execute(['cid' => $booksCompanyId]);
$booksFy = $fyStmt->fetch();
$booksFyId = (int) ($booksFy['id'] ?? 0);
$currency = site_currency_symbol();
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$tab = in_array((string) ($_GET['tab'] ?? ''), ['overview', 'vouchers', 'reports'], true) ? (string) $_GET['tab'] : 'overview';
$booksUrl = static fn (string $t): string => url('admin/client-books.php?client=' . $clientId . '&tab=' . $t);

// Voucher posting into the client's books (approval-gated for staff).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'post_client_voucher' && $booksFyId > 0) {
        require_permission('accounting', 'post');

        $voucherType = in_array((string) ($_POST['voucher_type'] ?? ''), ['journal', 'payment', 'receipt', 'sales', 'purchase', 'contra'], true) ? (string) $_POST['voucher_type'] : 'journal';
        $narration = trim((string) ($_POST['narration'] ?? ''));
        $voucherDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['voucher_date'] ?? '')) ? (string) $_POST['voucher_date'] : date('Y-m-d');
        $entries = [];
        $debit = 0.0;
        $credit = 0.0;
        foreach ((array) ($_POST['ledger_id'] ?? []) as $i => $lid) {
            $lid = (int) $lid;
            $type = (string) (($_POST['entry_type'] ?? [])[$i] ?? '');
            $amount = round((float) (($_POST['amount'] ?? [])[$i] ?? 0), 2);
            if ($lid <= 0 || $amount <= 0 || !in_array($type, ['debit', 'credit'], true)) {
                continue;
            }
            $own = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :id AND company_id = :cid');
            $own->execute(['id' => $lid, 'cid' => $booksCompanyId]);
            if ((int) $own->fetchColumn() === 0) {
                continue;
            }
            $entries[] = ['ledger_id' => $lid, 'entry_type' => $type, 'amount' => $amount, 'memo' => trim((string) (($_POST['memo'] ?? [])[$i] ?? ''))];
            $type === 'debit' ? $debit += $amount : $credit += $amount;
        }
        if (count($entries) < 2 || round($debit, 2) !== round($credit, 2)) {
            flash('error', 'The entry needs at least two lines with equal debit and credit.');
            redirect($booksUrl('vouchers'));
        }
        $needsClientApproval = $access !== 'direct';
        try {
        $voucherId = create_voucher_with_entries([
            'company_id' => $booksCompanyId,
            'fiscal_year_id' => $booksFyId,
            'voucher_no' => strtoupper(substr($voucherType, 0, 2)) . '-' . date('Ymd-His'),
            'voucher_type' => $voucherType,
            'source_type' => 'client_books',
            'source_id' => null,
            'party_id' => null,
            'reference_no' => null,
            'voucher_date' => $voucherDate,
            'narration' => $narration,
            'total_amount' => $debit,
            'status' => $needsClientApproval ? 'draft' : 'posted',
            'approval_state' => $needsClientApproval ? 'pending_approval' : 'approved',
            'submitted_by' => $userId,
            'posted_by' => $needsClientApproval ? null : $userId,
            'posted_at' => $needsClientApproval ? null : date('Y-m-d H:i:s'),
        ], $entries);
        } catch (Throwable $cbEx) {
            flash('error', 'Could not save the entry: ' . $cbEx->getMessage());
            redirect($booksUrl('vouchers'));
        }
        if ($voucherId > 0 && $needsClientApproval && column_exists('vouchers', 'requires_client_approval')) {
            db()->prepare('UPDATE vouchers SET requires_client_approval = 1 WHERE id = :id')->execute(['id' => $voucherId]);
        }
        if ($voucherId > 0) {
            security_event('client_voucher_posted', 'success', 'Client voucher #' . $voucherId . ' posted for company #' . $booksCompanyId . '.', $booksCompanyId, $userId);
        }
        log_activity('client_books', $clientId, 'client_voucher', ($needsClientApproval ? 'Submitted for client approval' : 'Posted') . ' in client books #' . $booksCompanyId . '.', $userId ?: null);
        flash('success', $needsClientApproval ? 'Entry submitted — waiting for the client\'s approval.' : 'Entry posted to the client\'s books.');
        redirect($booksUrl('vouchers'));
    }
    if ($action === 'set_client_business_type' && table_exists('company_accounting_preferences')) {
        require_permission('clients', 'edit');

        $bt = in_array((string) ($_POST['business_type'] ?? ''), ['service', 'trading', 'manufacturing'], true) ? (string) $_POST['business_type'] : 'service';
        db()->prepare('INSERT INTO company_accounting_preferences (company_id, business_type, updated_by) VALUES (:cid, :bt, :uid)
            ON DUPLICATE KEY UPDATE business_type = VALUES(business_type), updated_by = VALUES(updated_by)')
            ->execute(['cid' => $booksCompanyId, 'bt' => $bt, 'uid' => $userId ?: null]);
        flash('success', 'Client business type set to ' . ucfirst($bt) . '.');
        redirect($booksUrl('overview'));
    }
    if ($action === 'cancel_client_voucher') {
        require_permission('accounting', 'edit');

        $vid = (int) ($_POST['voucher_id'] ?? 0);
        $where = $access === 'direct' ? '' : " AND approval_state = 'pending_approval' AND submitted_by = " . $userId;
        db()->exec("UPDATE vouchers SET status = 'cancelled', approval_state = 'rejected' WHERE id = " . $vid . ' AND company_id = ' . $booksCompanyId . $where);
        security_event('client_voucher_cancelled', 'success', 'Client voucher #' . $vid . ' cancelled.', $booksCompanyId, $userId);
        log_activity('client_books', $clientId, 'client_voucher_cancelled', 'Voucher #' . $vid . ' cancelled in client books #' . $booksCompanyId . '.', $userId ?: null);
        flash('success', 'Voucher cancelled.');
        redirect($booksUrl('vouchers'));
    }
}

$booksBusinessType = company_accounting_business_type($booksCompanyId);
$booksProfile = accounting_business_profile($booksBusinessType);
$snapshot = $booksFyId > 0 ? company_financials_snapshot($booksCompanyId, $booksFyId) : ['cash' => 0.0, 'receivables' => 0.0, 'payables' => 0.0, 'income' => 0.0, 'expenses' => 0.0, 'net' => 0.0];
$fmtMoney = static fn (float $a): string => $currency . number_format($a, 2);

$ledgerStmt = db()->prepare("SELECT id, code, name FROM ledgers WHERE company_id = :cid AND status = 'active' ORDER BY name ASC");
$ledgerStmt->execute(['cid' => $booksCompanyId]);
$booksLedgers = $ledgerStmt->fetchAll();

$voucherStmt = db()->prepare('SELECT * FROM vouchers WHERE company_id = :cid ORDER BY id DESC LIMIT 40');
$voucherStmt->execute(['cid' => $booksCompanyId]);
$booksVouchers = $voucherStmt->fetchAll();

$pageTitle = $client['organization_name'];
$pageSubtitle = 'Client accounting books · ' . ($booksFy['label'] ?? 'No fiscal year') . ($access === 'direct' ? ' · Direct posting' : ' · Client approval required');
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<nav class="mbw-tabbar" aria-label="Client books">
    <a class="mbw-tab <?= $tab === 'overview' ? 'is-active' : '' ?>" href="<?= e($booksUrl('overview')) ?>"><?= icon('dashboard') ?>Overview</a>
    <a class="mbw-tab <?= $tab === 'vouchers' ? 'is-active' : '' ?>" href="<?= e($booksUrl('vouchers')) ?>"><?= icon('journal') ?>Vouchers</a>
    <a class="mbw-tab <?= $tab === 'reports' ? 'is-active' : '' ?>" href="<?= e($booksUrl('reports')) ?>"><?= icon('reports') ?>Reports</a>
    <a class="mbw-tab" href="<?= e(url('admin/manage-clients.php')) ?>"><?= icon('chevron-right') ?>All Clients</a>
    <?php if ($access === 'direct'): ?>
        <form method="post" action="<?= e(url('admin/switch-company.php')) ?>" style="margin-left:auto">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="company_id" value="<?= (int) $booksCompanyId ?>">
            <button type="submit" class="button" style="min-height:36px"><?= icon('accounting') ?>Open Full Accounting</button>
        </form>
    <?php endif; ?>
</nav>

<?php if ($tab === 'overview'): ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Client Business Type</h2><span class="frm-optional">Drives the statement formats and available features for this client</span></div>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="set_client_business_type">
            <input type="hidden" name="client_id" value="<?= (int) $clientId ?>">
            <?php foreach (['service' => 'Service', 'trading' => 'Trading', 'manufacturing' => 'Manufacturing'] as $btValue => $btLabel): ?>
                <button type="submit" name="business_type" value="<?= e($btValue) ?>" class="button <?= $booksBusinessType === $btValue ? '' : 'secondary' ?>"><?= e($btLabel) ?></button>
            <?php endforeach; ?>
        </form>
    </section>
    <section class="mbw-kpi-grid">
        <?php foreach ([['Cash & Bank', $snapshot['cash'], 'blue', 'bank'], ['Receivables', $snapshot['receivables'], 'green', 'clients'], ['Payables', $snapshot['payables'], 'red', 'card'], ['Income', $snapshot['income'], 'purple', 'analytics'], ['Net Profit', $snapshot['net'], 'green', 'trend-up']] as [$label, $amount, $tone, $iconName]): ?>
            <article class="mbw-kpi"><div><span class="mbw-kpi-label"><?= e($label) ?></span><div class="mbw-kpi-value"><?= e($fmtMoney((float) $amount)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= e($booksFy['label'] ?? '') ?></span></span></div><span class="mbw-chip tone-<?= e($tone) ?>"><?= icon($iconName) ?></span></article>
        <?php endforeach; ?>
    </section>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Recent Activity</h2><div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e($booksUrl('vouchers')) ?>">All Vouchers</a></div></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Date</th><th>Voucher No.</th><th>Type</th><th>Narration</th><th class="is-numeric">Amount</th><th>Status</th></tr></thead>
            <tbody>
            <?php if ($booksVouchers === []): ?><tr><td colspan="6" style="color:var(--mbw-muted)">No entries yet in this client's books.</td></tr><?php endif; ?>
            <?php foreach (array_slice($booksVouchers, 0, 6) as $v): ?>
                <tr><td><?= e(date('d M Y', strtotime((string) ($v['voucher_date'] ?? $v['created_at'])))) ?></td><td><?= e($v['voucher_no']) ?></td><td><span class="mbw-pill tone-blue"><?= e(ucfirst((string) $v['voucher_type'])) ?></span></td><td><?= e((string) ($v['narration'] ?: '—')) ?></td><td class="is-numeric"><?= e($fmtMoney((float) $v['total_amount'])) ?></td><td><?= (string) $v['status'] === 'posted' ? '<span class="mbw-pill tone-green">Posted</span>' : ((string) $v['approval_state'] === 'pending_approval' ? '<span class="mbw-pill tone-amber">Awaiting client</span>' : '<span class="mbw-pill tone-red">' . e(ucfirst((string) $v['status'])) . '</span>') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
<?php elseif ($tab === 'vouchers'): ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>New Entry</h2><span class="frm-optional"><?= $access === 'direct' ? 'Posts immediately (Super Admin / serving admin)' : 'Requires the client\'s approval before posting' ?></span></div>
        <form method="post" class="frm-grid frm-grid-4">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="post_client_voucher">
            <input type="hidden" name="client_id" value="<?= (int) $clientId ?>">
            <label>Type
                <select name="voucher_type"><option value="journal">Journal</option><option value="receipt">Receipt</option><option value="payment">Payment</option><option value="sales">Sales</option><option value="purchase">Purchase</option><option value="contra">Contra</option></select>
            </label>
            <label>Date<input type="date" name="voucher_date" value="<?= e(date('Y-m-d')) ?>"></label>
            <label class="frm-span-3" style="grid-column:span 2">Narration<input type="text" name="narration" maxlength="255" placeholder="Description for this entry"></label>
            <?php for ($i = 0; $i < 4; $i++): ?>
                <label>Ledger
                    <select name="ledger_id[]"><option value="">Select ledger</option>
                        <?php foreach ($booksLedgers as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['name'] . ' (' . $l['code'] . ')') ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Side<select name="entry_type[]"><option value="">Select</option><option value="debit">Debit</option><option value="credit">Credit</option></select></label>
                <label>Amount<input type="number" name="amount[]" step="0.01" min="0" placeholder="0.00"></label>
                <label>Memo<input type="text" name="memo[]" maxlength="255"></label>
            <?php endfor; ?>
            <div style="grid-column:1/-1"><button type="submit"><?= icon('journal') ?><?= $access === 'direct' ? 'Post Entry' : 'Submit for Client Approval' ?></button></div>
        </form>
    </section>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Voucher Register</h2><span class="mbw-pill tone-gray"><?= count($booksVouchers) ?> shown</span></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Date</th><th>Voucher No.</th><th>Type</th><th>Narration</th><th class="is-numeric">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if ($booksVouchers === []): ?><tr><td colspan="7" style="color:var(--mbw-muted)">No vouchers yet.</td></tr><?php endif; ?>
            <?php foreach ($booksVouchers as $v): ?>
                <tr>
                    <td><?= e(date('d M Y', strtotime((string) ($v['voucher_date'] ?? $v['created_at'])))) ?></td>
                    <td><?= e($v['voucher_no']) ?></td>
                    <td><span class="mbw-pill tone-blue"><?= e(ucfirst((string) $v['voucher_type'])) ?></span></td>
                    <td><?= e((string) ($v['narration'] ?: '—')) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney((float) $v['total_amount'])) ?></td>
                    <td><?= (string) $v['status'] === 'posted' ? '<span class="mbw-pill tone-green">Posted</span>' : ((string) $v['approval_state'] === 'pending_approval' ? '<span class="mbw-pill tone-amber">Awaiting client</span>' : '<span class="mbw-pill tone-red">' . e(ucfirst((string) $v['status'])) . '</span>') ?></td>
                    <td>
                        <?php if ((string) $v['status'] !== 'cancelled' && ($access === 'direct' || ((string) $v['approval_state'] === 'pending_approval' && (int) ($v['submitted_by'] ?? 0) === $userId))): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Cancel this voucher?')">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="cancel_client_voucher">
                                <input type="hidden" name="client_id" value="<?= (int) $clientId ?>">
                                <input type="hidden" name="voucher_id" value="<?= (int) $v['id'] ?>">
                                <button type="submit" class="button danger" style="min-height:30px;padding:3px 10px">Cancel</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
<?php else: ?>
    <?php
    $clientReports = ['trial-balance' => 'Trial Balance', 'profit-loss' => 'Profit or Loss', 'balance-sheet' => 'Balance Sheet', 'cash-flow' => 'Cash Flow', 'ledger-report' => 'Ledger Report'];
    if ($booksProfile['show_inventory'] ?? false) {
        $clientReports['inventory-summary'] = 'Inventory Summary';
        $clientReports['stock-ledger'] = 'Stock Ledger';
    }
    if ($booksProfile['show_manufacturing'] ?? false) {
        $clientReports['manufacturing-statement'] = 'Manufacturing Statement';
    }
    $reportId = array_key_exists((string) ($_GET['report'] ?? ''), $clientReports) ? (string) $_GET['report'] : 'trial-balance';
    $from = (string) ($booksFy['start_date'] ?? date('Y-01-01'));
    $to = (string) ($booksFy['end_date'] ?? date('Y-12-31'));
    $report = rc_generate($reportId, $booksCompanyId, $from, $to, ['currency' => $currency, 'vtype' => '', 'group_id' => 0, 'ledger_id' => 0, 'item_id' => 0, 'biz' => $booksBusinessType, 'org_default' => $booksBusinessType, 'company_id' => $booksCompanyId, 'company_name' => (string) $client['organization_name'], 'subsidiaries' => []]);
    $reportMeta = [
        'company_name' => (string) $client['organization_name'],
        'fiscal_label' => (string) ($booksFy['label'] ?? ''),
        'from' => $from, 'to' => $to,
        'currency_code' => trim($currency) !== '' ? trim($currency) : 'NPR',
        'generated_by' => (string) ($currentUser['name'] ?? 'User'),
    ];
    ?>
    <section class="mbw-card rpt-picker">
        <?php foreach ($clientReports as $rid => $rlabel): ?>
            <a class="rpt-pick <?= $rid === $reportId ? 'is-active' : '' ?>" href="<?= e($booksUrl('reports') . '&report=' . $rid) ?>"><?= icon('reports') ?><span><?= e($rlabel) ?></span></a>
        <?php endforeach; ?>
    </section>
    <main class="rc-report-view rpt-statement">
        <div class="rpt-bar"><?= e(($report['number'] ?? '') !== '' ? $report['number'] . '. ' : '') ?><?= e($report['title'] ?? ucwords(str_replace('-', ' ', $reportId))) ?></div>
        <?php rc_render_letterhead($report, $reportMeta); ?>
        <div class="rc-table-scroll"><?php rc_render_table($report, rc_has_group_columns($report)); ?></div>
        <?php rc_render_report_foot(['generated_by' => $reportMeta['generated_by']]); ?>
    </main>
<?php endif; ?>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
