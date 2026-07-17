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

$sql = "
    SELECT v.id AS voucher_id, v.voucher_no, v.voucher_type, v.status,
           COALESCE(v.voucher_date, DATE(v.created_at)) AS entry_date,
           v.reference_no, v.narration, p.name AS party_name,
           l.code AS ledger_code, l.name AS ledger_name,
           ve.entry_type, ve.amount, ve.memo
    FROM vouchers v
    INNER JOIN voucher_entries ve ON ve.voucher_id = v.id
    INNER JOIN ledgers l ON l.id = ve.ledger_id
    LEFT JOIN accounting_parties p ON p.id = v.party_id
    WHERE v.company_id = :cid
      AND COALESCE(v.voucher_date, DATE(v.created_at)) BETWEEN :from_date AND :to_date
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
    $sql .= ' AND ve.ledger_id = :lid';
    $params['lid'] = $ledgerFilter;
}
if ($searchQuery !== '') {
    $sql .= ' AND (v.voucher_no LIKE :q1 OR v.reference_no LIKE :q2 OR v.narration LIKE :q3 OR ve.memo LIKE :q4 OR p.name LIKE :q5 OR l.name LIKE :q6)';
    $like = '%' . $searchQuery . '%';
    $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'q6' => $like];
}
$sql .= ' ORDER BY COALESCE(v.voucher_date, DATE(v.created_at)) DESC, v.id DESC, ve.id ASC LIMIT 1500';
$entriesStmt = db()->prepare($sql);
$entriesStmt->execute($params);
$entries = $entriesStmt->fetchAll();

$totalDebit = 0.0;
$totalCredit = 0.0;
foreach ($entries as $entry) {
    if ((string) $entry['entry_type'] === 'debit') {
        $totalDebit += (float) $entry['amount'];
    } else {
        $totalCredit += (float) $entry['amount'];
    }
}

$fmtMoney = static fn (float $amount): string => $currency . number_format($amount, 2);
$pageTitle = 'Day Book';
$pageSubtitle = 'Every entry, chronologically — filter by date, type, ledger, status, or narration';
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>

<section class="mbw-kpi-grid" aria-label="Day book totals">
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Entries shown</span><div class="mbw-kpi-value"><?= e((string) count($entries)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= e($fromDate) ?> → <?= e($toDate) ?></span></span></div><span class="mbw-chip tone-blue"><?= icon('calendar') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Debits</span><div class="mbw-kpi-value"><?= e($fmtMoney($totalDebit)) ?></div><span class="mbw-kpi-delta is-up"><?= icon('trend-up') ?><span class="mbw-kpi-vs">In the filtered window</span></span></div><span class="mbw-chip tone-green"><?= icon('trend-up') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Credits</span><div class="mbw-kpi-value"><?= e($fmtMoney($totalCredit)) ?></div><span class="mbw-kpi-delta is-down"><?= icon('trend-down') ?><span class="mbw-kpi-vs">In the filtered window</span></span></div><span class="mbw-chip tone-red"><?= icon('trend-down') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Dr − Cr</span><div class="mbw-kpi-value"><?= e($fmtMoney($totalDebit - $totalCredit)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">0.00 when the filter spans whole vouchers</span></span></div><span class="mbw-chip tone-purple"><?= icon('reconcile') ?></span></article>
</section>

<section class="mbw-card">
    <div class="mbw-card-head">
        <h2>Day Book</h2>
        <div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('admin/accounting.php')) ?>">Voucher Register</a></div>
    </div>
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
        <thead><tr><th>Date</th><th>Voucher</th><th>Type</th><th>Party</th><th>Ledger</th><th>Narration / Memo</th><th class="is-numeric">Debit</th><th class="is-numeric">Credit</th></tr></thead>
        <tbody>
            <?php if ($entries === []): ?><tr><td colspan="8">No entries match the current filters.</td></tr><?php endif; ?>
            <?php $previousVoucherId = 0; ?>
            <?php foreach ($entries as $entry): ?>
                <?php $firstOfVoucher = (int) $entry['voucher_id'] !== $previousVoucherId; $previousVoucherId = (int) $entry['voucher_id']; ?>
                <tr<?= $firstOfVoucher ? ' style="border-top:2px solid var(--mbw-border,#dcebf5)"' : '' ?>>
                    <td><?= $firstOfVoucher ? e(date('d M Y', strtotime((string) $entry['entry_date']))) : '' ?></td>
                    <td><?= $firstOfVoucher ? '<a class="reference-link" href="' . e(url('admin/voucher-form.php?edit=' . (int) $entry['voucher_id'])) . '">' . e($entry['voucher_no']) . '</a>' : '' ?></td>
                    <td><?= $firstOfVoucher ? e($entry['voucher_type']) : '' ?></td>
                    <td><?= $firstOfVoucher ? e($entry['party_name'] ?? '—') : '' ?></td>
                    <td><?= e($entry['ledger_code'] . ' — ' . $entry['ledger_name']) ?></td>
                    <td><?= e((string) ($entry['memo'] ?: $entry['narration'] ?: $entry['reference_no'] ?: '')) ?></td>
                    <td class="is-numeric"><?= (string) $entry['entry_type'] === 'debit' ? e($fmtMoney((float) $entry['amount'])) : '' ?></td>
                    <td class="is-numeric"><?= (string) $entry['entry_type'] === 'credit' ? e($fmtMoney((float) $entry['amount'])) : '' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="6"><strong>Totals</strong></td><td class="is-numeric"><strong><?= e($fmtMoney($totalDebit)) ?></strong></td><td class="is-numeric"><strong><?= e($fmtMoney($totalCredit)) ?></strong></td></tr></tfoot>
    </table>
    </div>
</section>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
