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
// Filters. All optional; dates clamp inside the selected fiscal year.
// ---------------------------------------------------------------------------
$selectedLedgerId = (int) ($_GET['ledger_id'] ?? 0);
$searchQuery = ''; // Account selection replaces the separate free-text search.
$typeFilter = (string) ($_GET['type'] ?? '');
if (!in_array($typeFilter, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
    $typeFilter = '';
}
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

$ledgerListStmt = db()->prepare('SELECT l.id, l.code, l.name, l.type, l.opening_balance, l.opening_side FROM ledgers l WHERE l.company_id = :cid ORDER BY l.code ASC');
$ledgerListStmt->execute(['cid' => $companyId]);
$allLedgers = $ledgerListStmt->fetchAll();

$selectedLedger = null;
foreach ($allLedgers as $ledgerRow) {
    if ((int) $ledgerRow['id'] === $selectedLedgerId) {
        $selectedLedger = $ledgerRow;
        break;
    }
}

// ---------------------------------------------------------------------------
// Statement mode: one ledger, opening carry-forward + running balance.
// ---------------------------------------------------------------------------
$statementRows = [];
$openingBalance = 0.0;
if ($selectedLedger) {
    require_once __DIR__ . '/../../app/reports_engine.php';
    $openingSourceSql = accounting_opening_source_sql('v.source_type');
    $statementBalances = rc_ledger_balances($companyId, $fromDate, $toDate, '', 0, $selectedLedgerId, [], $fyStart);
    $openingBalance = (float) ($statementBalances[0]['opening_net'] ?? 0);

    $stmtSql = "
        SELECT v.id AS voucher_id, v.voucher_no, v.voucher_type, v.reference_no, v.narration,
               v.voucher_date AS entry_date,
               ve.entry_type, ve.amount, ve.memo, p.name AS party_name
        FROM voucher_entries ve
        INNER JOIN vouchers v ON v.id = ve.voucher_id
        LEFT JOIN accounting_parties p ON p.id = v.party_id
        WHERE ve.ledger_id = :lid AND v.company_id = :cid AND v.status = 'posted'
          AND v.voucher_date BETWEEN :from_date AND :to_date
          AND NOT (v.voucher_date = :from_open AND {$openingSourceSql})
    ";
    $stmtParams = ['lid' => $selectedLedgerId, 'cid' => $companyId, 'from_date' => $fromDate,
        'from_open' => $fromDate, 'to_date' => $toDate];
    if ($searchQuery !== '') {
        $stmtSql .= ' AND (v.voucher_no LIKE :q1 OR v.reference_no LIKE :q2 OR v.narration LIKE :q3 OR ve.memo LIKE :q4 OR p.name LIKE :q5)';
        $like = '%' . $searchQuery . '%';
        $stmtParams += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
    }
    $stmtSql .= ' ORDER BY v.voucher_date ASC, v.id ASC, ve.id ASC LIMIT 1000';
    $entriesStmt = db()->prepare($stmtSql);
    $entriesStmt->execute($stmtParams);
    $statementRows = $entriesStmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Overview mode: every ledger with FY totals and cumulative balance.
// ---------------------------------------------------------------------------
$balances = [];
if (!$selectedLedger) {
    $balances = array_values(array_filter(
        ledger_report_balances($companyId, $fiscalYearId),
        static fn (array $row): bool => $typeFilter === '' || $row['type'] === $typeFilter
    ));
}

$fmtMoney = static fn (float $amount): string => $currency . number_format($amount, 2);
$pageTitle = 'Ledgers';
$pageHero = false;
$pageSubtitle = 'Ledger balances and account statements with running balance';
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>

<section class="mbw-card">
    <?php if ($selectedLedger): ?>
    <div class="mbw-card-head">
        <?php if ($selectedLedger): ?><span class="muted"><?= e($selectedLedger['code'] . ' · ' . $selectedLedger['name']) ?></span><?php endif; ?>
        <div class="mbw-card-tools" style="margin-left:auto">
            <?php if ($selectedLedger): ?>
                <?php
                // An inventory-designated ledger's balance is made of ITEMS —
                // wire it straight to the Stock Summary grouped by this ledger.
                require_once __DIR__ . '/../../app/stock_report_engine.php';
                if (in_array($selectedLedgerId, sr_inventory_ledger_ids((int) $company['id']), true)): ?>
                    <a class="mbw-view-all" href="<?= e(url('admin/stock-summary-report.php?ledger=' . $selectedLedgerId . '&applied=1')) ?>" title="The item-wise stock that makes up this GL balance"><?= icon('box') ?>Items behind this balance</a>
                <?php endif; ?>
                <a class="mbw-view-all" href="<?= e(url('admin/reports-center.php?report=ledger-report&ledger_id=' . $selectedLedgerId . '&fy=' . $fiscalYearId)) ?>">Reports Center view</a>
                <a class="mbw-view-all" href="<?= e(url('admin/ledgers.php')) ?>">All ledgers</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <form method="get" action="<?= e(url('admin/ledgers.php')) ?>" class="mbw-filter-bar">
        <label class="ledger-filter"><span>Account</span><select name="ledger_id" class="field-compact js-searchable" onchange="this.form.submit()" aria-label="Ledger">
            <option value="0">All ledgers (balances)</option>
            <?php foreach ($allLedgers as $ledgerRow): ?>
                <option value="<?= (int) $ledgerRow['id'] ?>" <?= $selectedLedgerId === (int) $ledgerRow['id'] ? 'selected' : '' ?>><?= e($ledgerRow['code'] . ' — ' . $ledgerRow['name']) ?></option>
            <?php endforeach; ?>
        </select></label>
        <?php if (!$selectedLedger): ?>
            <label><span>Nature</span><select name="type" class="field-compact" aria-label="Type filter">
                <option value="">All types</option>
                <?php foreach (['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'revenue' => 'Income', 'expense' => 'Expenses'] as $typeValue => $typeLabel): ?>
                    <option value="<?= e($typeValue) ?>" <?= $typeFilter === $typeValue ? 'selected' : '' ?>><?= e($typeLabel) ?></option>
                <?php endforeach; ?>
            </select></label>
        <?php else: ?>
            <label><span>From date</span><input type="date" name="from" value="<?= e($fromDate) ?>" class="field-compact" aria-label="From date"></label>
            <label><span>To date</span><input type="date" name="to" value="<?= e($toDate) ?>" class="field-compact" aria-label="To date"></label>
        <?php endif; ?>
        <button type="submit" class="button secondary"><?= icon('filter') ?>Apply</button>
        <?php if ($searchQuery !== '' || $typeFilter !== ''): ?><a class="button secondary" href="<?= e(url('admin/ledgers.php' . ($selectedLedgerId > 0 ? '?ledger_id=' . $selectedLedgerId : ''))) ?>">Clear</a><?php endif; ?>
        <?php if (!$selectedLedger): ?>
        <div class="actions" style="margin-left:auto">
                <a class="mbw-view-all" href="<?= e(url('admin/export-ledger.php?format=pdf')) ?>" target="_blank" aria-label="Export PDF" title="Export PDF"><?= icon('documents') ?><span>PDF</span></a>
                <a class="mbw-view-all" href="<?= e(url('admin/export-ledger.php?format=excel')) ?>" aria-label="Export Excel" title="Export Excel"><?= icon('analytics') ?><span>Excel</span></a>
        </div>
        <?php endif; ?>
    </form>

    <?php if ($selectedLedger): ?>
        <div style="overflow-x:auto">
        <table class="reference-table">
            <thead><tr><th>Date</th><th>Voucher</th><th>Type</th><th>Party</th><th>Narration / Memo</th><th class="is-numeric">Debit</th><th class="is-numeric">Credit</th><th class="is-numeric">Closing Balance</th></tr></thead>
            <tbody>
                <?php
                $running = $openingBalance;
                $totalDr = 0.0;
                $totalCr = 0.0;
                ?>
                <tr>
                    <td><?= e($fromDate) ?></td>
                    <td colspan="4"><strong>Opening balance (carried forward)</strong></td>
                    <td class="is-numeric"></td><td class="is-numeric"></td>
                    <td class="is-numeric"><strong><?= e($fmtMoney(abs($running))) ?> <?= $running >= 0 ? 'Dr' : 'Cr' ?></strong></td>
                </tr>
                <?php if ($statementRows === []): ?>
                    <tr><td colspan="8">No entries match the current filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($statementRows as $row): ?>
                    <?php
                    $isDebit = (string) $row['entry_type'] === 'debit';
                    $amount = (float) $row['amount'];
                    $running += $isDebit ? $amount : -$amount;
                    $totalDr += $isDebit ? $amount : 0.0;
                    $totalCr += $isDebit ? 0.0 : $amount;
                    ?>
                    <tr>
                        <td><?= e(app_date((string) $row['entry_date'])) ?></td>
                        <td><a class="reference-link" data-voucher-preview href="<?= e(url('admin/voucher-view.php?id=' . (int) $row['voucher_id'])) ?>"><?= e($row['voucher_no']) ?></a></td>
                        <td><?= e($row['voucher_type']) ?></td>
                        <td><?= e($row['party_name'] ?? '—') ?></td>
                        <td><?= e((string) ($row['memo'] ?: $row['narration'] ?: $row['reference_no'] ?: '')) ?></td>
                        <td class="is-numeric"><?= $isDebit ? e($fmtMoney($amount)) : '' ?></td>
                        <td class="is-numeric"><?= $isDebit ? '' : e($fmtMoney($amount)) ?></td>
                        <td class="is-numeric"><?= e($fmtMoney(abs($running))) ?> <?= $running >= 0 ? 'Dr' : 'Cr' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5"><strong>Period totals · Closing balance</strong></td>
                    <td class="is-numeric"><strong><?= e($fmtMoney($totalDr)) ?></strong></td>
                    <td class="is-numeric"><strong><?= e($fmtMoney($totalCr)) ?></strong></td>
                    <td class="is-numeric"><strong><?= e($fmtMoney(abs($running))) ?> <?= $running >= 0 ? 'Dr' : 'Cr' ?></strong></td>
                </tr>
            </tfoot>
        </table>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto">
        <table class="reference-table">
            <thead><tr><th>Code</th><th>Name</th><th>Type</th><th class="is-numeric">Debit (FY)</th><th class="is-numeric">Credit (FY)</th><th class="is-numeric">Balance</th><th>Statement</th></tr></thead>
            <tbody>
                <?php if ($balances === []): ?><tr><td colspan="7">No ledgers match the current filters.</td></tr><?php endif; ?>
                <?php foreach ($balances as $row): ?>
                    <?php $rowBalance = round((float) $row['closing_net'], 2); ?>
                    <tr>
                        <td><?= e($row['code']) ?></td>
                        <td><?= e($row['name']) ?></td>
                        <td><?= e($row['type']) ?></td>
                        <td class="is-numeric"><?= e($fmtMoney((float) $row['debit_total'])) ?></td>
                        <td class="is-numeric"><?= e($fmtMoney((float) $row['credit_total'])) ?></td>
                        <td class="is-numeric <?= $rowBalance < 0 ? 'text-danger' : '' ?>"><?= e($fmtMoney(abs($rowBalance))) ?> <?= $rowBalance > 0 ? 'Dr' : ($rowBalance < 0 ? 'Cr' : '') ?></td>
                        <td><?php if ((int) $row['id'] > 0): ?><div style="display:flex;gap:6px"><a class="button secondary" style="min-height:30px;padding:3px 10px" href="<?= e(url('admin/ledgers.php?ledger_id=' . (int) $row['id'])) ?>">Open</a><a class="button secondary" style="min-height:30px;padding:3px 10px" href="<?= e(url('admin/create-account.php?edit_id=' . (int) $row['id'])) ?>" title="Edit account details">Edit</a></div><?php else: ?>—<?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../../app/views/vouchers/preview_dialog.php'; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
