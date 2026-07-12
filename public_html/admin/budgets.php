<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_admin();
require_company_context();
accounting_module_repair_database();

$company = current_company();
$fiscalYear = current_fiscal_year();
if (!$company || !$fiscalYear) {
    flash('error', 'Company and fiscal year context required.');
    redirect('admin/accounting-dashboard.php');
}
$companyId = (int) $company['id'];
$fiscalYearId = (int) $fiscalYear['id'];
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$sym = site_currency_symbol();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ((string) ($_POST['action'] ?? '') === 'save_budgets') {
        $amounts = (array) ($_POST['budget'] ?? []);
        $saved = 0;
        $upsert = db()->prepare('INSERT INTO budgets (company_id, fiscal_year_id, ledger_id, amount, created_by)
            VALUES (:cid, :fy, :lid, :amount, :by)
            ON DUPLICATE KEY UPDATE amount = VALUES(amount)');
        $remove = db()->prepare('DELETE FROM budgets WHERE company_id = :cid AND fiscal_year_id = :fy AND ledger_id = :lid');
        foreach ($amounts as $ledgerId => $raw) {
            $ledgerId = (int) $ledgerId;
            $raw = trim((string) $raw);
            if ($ledgerId <= 0) {
                continue;
            }
            if ($raw === '' || !is_numeric($raw) || (float) $raw == 0.0) {
                $remove->execute(['cid' => $companyId, 'fy' => $fiscalYearId, 'lid' => $ledgerId]);
                continue;
            }
            $upsert->execute(['cid' => $companyId, 'fy' => $fiscalYearId, 'lid' => $ledgerId, 'amount' => round((float) $raw, 2), 'by' => $userId]);
            $saved++;
        }
        log_activity('budgets', $companyId, 'saved', $saved . ' budget lines saved for ' . ($fiscalYear['label'] ?? ''), $userId);
        flash('success', 'Budgets saved (' . $saved . ' lines). They now appear in the Profit or Loss statement.');
        redirect('admin/budgets.php');
    }
}

// P&L ledgers grouped Master -> Group, with existing budgets and actuals.
$rowsStmt = db()->prepare("SELECT l.id, l.code, l.name, l.type, lg.name AS group_name, lg.master_key,
        COALESCE(b.amount, 0) AS budget_amount
    FROM ledgers l
    LEFT JOIN ledger_groups lg ON lg.id = l.group_id
    LEFT JOIN budgets b ON b.ledger_id = l.id AND b.company_id = l.company_id AND b.fiscal_year_id = :fy
    WHERE l.company_id = :cid AND l.status = 'active'
      AND (l.type IN ('revenue', 'expense') OR lg.master_key IN ('direct_income', 'indirect_income', 'direct_expense', 'indirect_expense'))
    ORDER BY lg.master_key ASC, lg.name ASC, l.code ASC");
$rowsStmt->execute(['fy' => $fiscalYearId, 'cid' => $companyId]);
$ledgerRows = $rowsStmt->fetchAll();

$actualStmt = db()->prepare("SELECT ve.ledger_id,
        SUM(CASE WHEN ve.entry_type = 'debit' THEN ve.amount ELSE -ve.amount END) AS net_dr
    FROM voucher_entries ve
    INNER JOIN vouchers v ON v.id = ve.voucher_id AND v.company_id = :cid AND v.fiscal_year_id = :fy AND v.status = 'posted'
    GROUP BY ve.ledger_id");
$actualStmt->execute(['cid' => $companyId, 'fy' => $fiscalYearId]);
$actualByLedger = $actualStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Budgets';
$pageSubtitle = 'Annual budget per ledger for ' . ($fiscalYear['label'] ?? '') . ' — powers the Budget and Budget Variance columns on the ledger-wise Profit or Loss statement.';
$pageHero = ['icon' => 'pie'];
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<form method="post" class="mbw-card" aria-label="Budget entry">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_budgets">
    <div class="mbw-card-head">
        <h2>Budget for <?= e($fiscalYear['label'] ?? '') ?></h2>
        <div class="mbw-card-tools">
            <a class="mbw-view-all" href="<?= e(url('admin/reports-center.php?report=profit-loss&biz=service')) ?>">Open Profit or Loss &#8594;</a>
            <button type="submit"><?= icon('tasks') ?>Save Budgets</button>
        </div>
    </div>
    <p style="margin:0 0 12px;color:var(--mbw-muted);font-size:12.5px">
        Enter the yearly amount for each income and expense ledger. Leave a field empty (or zero) to remove that budget line. Actuals shown are posted movements this fiscal year.
    </p>
    <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>Code</th><th>Ledger</th>
                    <th class="is-numeric">Actual to date (<?= e($sym) ?>)</th>
                    <th class="is-numeric">Budget (<?= e($sym) ?>)</th>
                    <th class="is-numeric">Used</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($ledgerRows === []): ?>
                    <tr><td colspan="5">No income or expense ledgers found for this company.</td></tr>
                <?php endif; ?>
                <?php $lastGroup = null; ?>
                <?php foreach ($ledgerRows as $row): ?>
                    <?php
                    $groupLabel = (string) (($row['group_name'] ?? '') !== '' ? $row['group_name'] : 'Ungrouped');
                    $isRevenue = (string) $row['type'] === 'revenue' || in_array((string) ($row['master_key'] ?? ''), ['direct_income', 'indirect_income'], true);
                    $netDr = (float) ($actualByLedger[(int) $row['id']] ?? 0);
                    $actual = $isRevenue ? -$netDr : $netDr;
                    $budget = (float) $row['budget_amount'];
                    $used = $budget > 0 ? ($actual / $budget) * 100 : null;
                    ?>
                    <?php if ($groupLabel !== $lastGroup): $lastGroup = $groupLabel; ?>
                        <tr><td colspan="5" style="background:var(--mbw-card-soft);font-weight:700;color:var(--mbw-heading)"><?= e($groupLabel) ?></td></tr>
                    <?php endif; ?>
                    <tr>
                        <td><?= e((string) $row['code']) ?></td>
                        <td><?= e((string) $row['name']) ?></td>
                        <td class="is-numeric"><?= e(number_format($actual, 2)) ?></td>
                        <td class="is-numeric" style="max-width:160px">
                            <input type="number" name="budget[<?= (int) $row['id'] ?>]" step="0.01" min="0" value="<?= $budget > 0 ? e(number_format($budget, 2, '.', '')) : '' ?>" style="text-align:right;min-height:36px" placeholder="–">
                        </td>
                        <td class="is-numeric">
                            <?php if ($used === null): ?>–<?php else: ?>
                                <span class="mbw-pill <?= $used > 100 ? 'tone-red' : ($used > 80 ? 'tone-amber' : 'tone-green') ?>"><?= e(number_format($used, 1)) ?>%</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</form>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
