<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_admin_or_client_books();
require_company_context();

$company = current_company();
$fiscalYear = current_fiscal_year();
if (!$company || !$fiscalYear) {
    flash('error', 'Company and fiscal year context required.');
    redirect('admin/accounting.php');
}
$companyId = (int) $company['id'];
$fiscalYearId = (int) $fiscalYear['id'];
$currency = site_currency_symbol();

// ---------------------------------------------------------------------------
// Filters: date range (defaults to the fiscal year), voucher type, ledger,
// status, and free text over narration / memo / voucher no / reference / party.
// ---------------------------------------------------------------------------
$fyStart = (string) ($fiscalYear['start_date'] ?? date('Y-01-01'));
$fyEnd = (string) ($fiscalYear['end_date'] ?? date('Y-12-31'));
$validDate = static fn (string $value): bool => $value !== '' && DateTimeImmutable::createFromFormat('Y-m-d', $value) !== false;
$fromDate = trim((string) ($_GET['from'] ?? ''));
$toDate = trim((string) ($_GET['to'] ?? ''));
if (!$validDate($fromDate)) {
    $fromDate = $fyStart;
}
if (!$validDate($toDate)) {
    $toDate = $fyEnd;
}
$typeFilter = (string) ($_GET['vtype'] ?? '');
$ledgerFilter = (int) ($_GET['ledger_id'] ?? 0);
$statusFilter = (string) ($_GET['status'] ?? 'posted');
if (!in_array($statusFilter, ['posted', 'draft', 'cancelled', ''], true)) {
    $statusFilter = 'posted';
}
$searchQuery = trim((string) ($_GET['q'] ?? ''));

$voucherTypesStmt = db()->prepare('SELECT DISTINCT voucher_type FROM vouchers WHERE company_id = :cid ORDER BY voucher_type ASC');
$voucherTypesStmt->execute(['cid' => $companyId]);
$voucherTypes = $voucherTypesStmt->fetchAll(PDO::FETCH_COLUMN);

$ledgerListStmt = db()->prepare('SELECT id, code, name FROM ledgers WHERE company_id = :cid ORDER BY code ASC');
$ledgerListStmt->execute(['cid' => $companyId]);
$allLedgers = $ledgerListStmt->fetchAll();

// Match vouchers first; ledger/memo filters must not reduce their totals.
$sql = "
    SELECT v.id AS voucher_id, v.voucher_no, v.voucher_type, v.status,
           v.voucher_date AS entry_date, v.narration,
           (SELECT COALESCE(SUM(e.amount), 0) FROM voucher_entries e
            WHERE e.voucher_id = v.id AND e.entry_type = 'debit') AS debit_total,
           (SELECT COALESCE(SUM(e.amount), 0) FROM voucher_entries e
            WHERE e.voucher_id = v.id AND e.entry_type = 'credit') AS credit_total
    FROM vouchers v
    LEFT JOIN accounting_parties p ON p.id = v.party_id AND p.company_id = v.company_id
    WHERE v.company_id = :cid
      AND v.voucher_date BETWEEN :from_date AND :to_date
";
$params = ['cid' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
if ($statusFilter !== '') {
    $sql .= ' AND v.status = :vstatus';
    $params['vstatus'] = $statusFilter;
}
if ($typeFilter !== '' && in_array($typeFilter, $voucherTypes, true)) {
    $sql .= ' AND v.voucher_type = :vtype';
    $params['vtype'] = $typeFilter;
}
if ($ledgerFilter > 0) {
    $sql .= ' AND EXISTS (SELECT 1 FROM voucher_entries le WHERE le.voucher_id = v.id AND le.ledger_id = :lid)';
    $params['lid'] = $ledgerFilter;
}
if ($searchQuery !== '') {
    $sql .= ' AND (v.voucher_no LIKE :q1 OR v.reference_no LIKE :q2 OR v.narration LIKE :q3 OR p.name LIKE :q5 OR EXISTS (SELECT 1 FROM voucher_entries se INNER JOIN ledgers sl ON sl.id = se.ledger_id AND sl.company_id = v.company_id WHERE se.voucher_id = v.id AND (se.memo LIKE :q4 OR sl.name LIKE :q6)))';
    $like = '%' . $searchQuery . '%';
    $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'q6' => $like];
}
$sql .= ' ORDER BY v.voucher_date DESC, v.id DESC LIMIT 1500';
$entriesStmt = db()->prepare($sql);
$entriesStmt->execute($params);
$entries = $entriesStmt->fetchAll();

$totalDebit = 0.0;
$totalCredit = 0.0;
$totalAmount = 0.0;
// Fetch memo fallbacks separately so long memos are not truncated by GROUP_CONCAT.
$memoStmt = db()->prepare('SELECT memo FROM voucher_entries WHERE voucher_id = :vid ORDER BY id');
foreach ($entries as &$entry) {
    $totalDebit += (float) $entry['debit_total'];
    $totalCredit += (float) $entry['credit_total'];
    $entry['total'] = max((float) $entry['debit_total'], (float) $entry['credit_total']);
    $totalAmount += $entry['total'];
    if (trim((string) $entry['narration']) === '') {
        $memoStmt->execute(['vid' => (int) $entry['voucher_id']]);
        $entry['narration'] = implode(' / ', array_unique(array_filter(array_map(
            static fn ($memo): string => trim((string) $memo),
            $memoStmt->fetchAll(PDO::FETCH_COLUMN)
        ), static fn (string $memo): bool => $memo !== '')));
    }
}
unset($entry);

$fmtMoney = static fn (float $amount): string => $currency . number_format($amount, 2);
$pageTitle = 'Day Book';
$pageHero = false;
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>

<section class="mbw-kpi-grid" aria-label="Day book totals">
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Vouchers shown</span><div class="mbw-kpi-value"><?= e((string) count($entries)) ?></div></div><span class="mbw-chip tone-blue"><?= icon('calendar') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Debits</span><div class="mbw-kpi-value"><?= e($fmtMoney($totalDebit)) ?></div></div><span class="mbw-chip tone-green"><?= icon('trend-up') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Credits</span><div class="mbw-kpi-value"><?= e($fmtMoney($totalCredit)) ?></div></div><span class="mbw-chip tone-red"><?= icon('trend-down') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Dr − Cr</span><div class="mbw-kpi-value"><?= e($fmtMoney($totalDebit - $totalCredit)) ?></div></div><span class="mbw-chip tone-purple"><?= icon('reconcile') ?></span></article>
</section>

<section class="mbw-card">
    <form method="get" action="<?= e(url('admin/day-book.php')) ?>" class="mbw-filter-bar">
        <input type="date" name="from" value="<?= e($fromDate) ?>" class="field-compact" aria-label="From date">
        <input type="date" name="to" value="<?= e($toDate) ?>" class="field-compact" aria-label="To date">
        <select name="vtype" class="field-compact" aria-label="Voucher type">
            <option value="">All types</option>
            <?php foreach ($voucherTypes as $voucherType): ?>
                <option value="<?= e($voucherType) ?>" <?= $typeFilter === $voucherType ? 'selected' : '' ?>><?= e(ucfirst($voucherType)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="ledger_id" class="field-compact" aria-label="Ledger">
            <option value="0">All ledgers</option>
            <?php foreach ($allLedgers as $ledgerRow): ?>
                <option value="<?= (int) $ledgerRow['id'] ?>" <?= $ledgerFilter === (int) $ledgerRow['id'] ? 'selected' : '' ?>><?= e($ledgerRow['code'] . ' — ' . $ledgerRow['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="field-compact" aria-label="Status">
            <option value="posted" <?= $statusFilter === 'posted' ? 'selected' : '' ?>>Posted</option>
            <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All statuses</option>
        </select>
        <input type="search" name="q" value="<?= e($searchQuery) ?>" class="field-compact" style="min-width:230px" placeholder="Search narration, memo, voucher, reference, party, ledger">
        <button type="submit" class="button secondary"><?= icon('filter') ?>Apply</button>
        <?php if ($searchQuery !== '' || $typeFilter !== '' || $ledgerFilter > 0): ?><a class="button secondary" href="<?= e(url('admin/day-book.php')) ?>">Clear</a><?php endif; ?>
    </form>
    <div style="overflow-x:auto">
    <table class="reference-table">
        <thead><tr><th>Date</th><th>Voucher</th><th>Type</th><th>Narration / Memo</th><th class="is-numeric">Total</th></tr></thead>
        <tbody>
            <?php if ($entries === []): ?><tr><td colspan="5">No vouchers match the current filters.</td></tr><?php endif; ?>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= e(app_date((string) $entry['entry_date'])) ?></td>
                    <td><a class="reference-link" data-voucher-preview href="<?= e(url('admin/voucher-view.php?id=' . (int) $entry['voucher_id'])) ?>"><?= e($entry['voucher_no']) ?></a></td>
                    <td><?= e($entry['voucher_type']) ?></td>
                    <td><?= e((string) $entry['narration']) ?></td>
                    <td class="is-numeric"><?= e($fmtMoney((float) $entry['total'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="4"><strong>Total</strong></td><td class="is-numeric"><strong><?= e($fmtMoney($totalAmount)) ?></strong></td></tr></tfoot>
    </table>
    </div>
</section>

<?php include __DIR__ . '/../../app/views/vouchers/preview_dialog.php'; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
