<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_admin_or_client_books();
require_company_context();
accounting_module_repair_database();

$company = current_company();
$companyId = (int) ($company['id'] ?? 0);
$masters = ledger_masters();

$groupsStmt = db()->prepare('SELECT * FROM ledger_groups WHERE company_id = :cid ORDER BY name ASC');
$groupsStmt->execute(['cid' => $companyId]);
$groups = $groupsStmt->fetchAll();

$ledgerCountStmt = db()->prepare('SELECT group_id, COUNT(*) AS n FROM ledgers WHERE company_id = :cid GROUP BY group_id');
$ledgerCountStmt->execute(['cid' => $companyId]);
$ledgerCounts = [];
foreach ($ledgerCountStmt->fetchAll() as $row) {
    $ledgerCounts[(int) $row['group_id']] = (int) $row['n'];
}

$pageTitle = 'Account Masters';
$pageSubtitle = 'Fixed accounting taxonomy and bulk ledger import.';
$bodyClass = 'admin-layout accounting-module-page chart-accounts-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<nav class="mbw-tabbar" aria-label="Chart of accounts sections">
    <a class="mbw-tab" href="<?= e(url('admin/chart-of-accounts.php')) ?>"><?= icon('tree') ?>Hierarchy View</a>
    <a class="mbw-tab is-active" href="<?= e(url('admin/chart-masters.php')) ?>"><?= icon('layers') ?>Masters</a>
    <a class="mbw-tab" href="<?= e(url('admin/chart-groups.php')) ?>"><?= icon('teams') ?>Groups</a>
    <a class="mbw-tab" href="<?= e(url('admin/chart-ledgers.php')) ?>"><?= icon('journal') ?>Ledgers</a>
    <a class="mbw-tab" href="<?= e(url('admin/chart-posting-accounts.php')) ?>"><?= icon('settings') ?>Posting Accounts</a>
    <a class="mbw-tab" href="<?= e(url('admin/audit-trail.php')) ?>"><?= icon('admin') ?>Audit Log</a>
</nav>

<section class="mbw-card" aria-label="Masters">
    <div class="mbw-card-head"><h2>Masters</h2><span class="frm-optional">Fixed accounting taxonomy — groups attach to a master, ledgers to a group</span></div>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>Master</th><th>Nature</th><th class="is-numeric">Groups</th><th class="is-numeric">Ledgers</th></tr></thead>
        <tbody>
        <?php foreach ($masters as $masterKey => $master): ?>
            <?php
            $mGroups = array_filter($groups, static fn (array $g): bool => (string) $g['master_key'] === $masterKey);
            $mLedgers = 0;
            foreach ($mGroups as $mg) {
                $mLedgers += $ledgerCounts[(int) $mg['id']] ?? 0;
            }
            ?>
            <tr>
                <td><strong style="color:var(--mbw-heading)"><?= e($master['label']) ?></strong> <span class="mbw-pill tone-gray"><?= e($masterKey) ?></span></td>
                <td><span class="mbw-pill tone-blue"><?= e(ucfirst((string) $master['nature'])) ?></span></td>
                <td class="is-numeric"><?= count($mGroups) ?></td>
                <td class="is-numeric"><?= $mLedgers ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<?php // The importer lives on the Chart of Accounts page, beside the tree it
      // writes into. Uploading is now a two-step review rather than a one-line
      // form, and the preview has to be shown somewhere — next to the chart it
      // is about to change is where it belongs. ?>
<section class="mbw-card" aria-label="Bulk import">
    <div class="mbw-card-head"><h2>Import a Chart of Accounts</h2><span class="frm-optional">Excel or CSV — groups, ledgers and opening balances in one sheet</span></div>
    <p class="frm-optional" style="margin:0 0 12px">
        Upload a spreadsheet to create groups and ledgers together. Every row is checked and shown back to you
        before anything is written, so you can see what will be created, what already exists, and what was
        rejected and why.
    </p>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <a class="button" href="<?= e(url('admin/chart-of-accounts.php#coa-import')) ?>"><?= icon('layers') ?>Open the importer</a>
        <a class="mbw-view-all" href="<?= e(url('admin/chart-of-accounts.php?import_template=xlsx')) ?>">Download the template</a>
        <a class="mbw-view-all" href="<?= e(url('admin/chart-of-accounts.php?export=csv')) ?>">Export the current chart</a>
    </div>
</section>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
