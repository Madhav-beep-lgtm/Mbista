<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_or_admin();
require_company_context();
$repairErrors = accounting_module_repair_database();

$company = current_company();
$companyId = (int) ($company['id'] ?? 0);
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);

$masters = ledger_masters();

$groupsStmt = db()->prepare('SELECT * FROM ledger_groups WHERE company_id = :cid ORDER BY name ASC');
$groupsStmt->execute(['cid' => $companyId]);
$groups = $groupsStmt->fetchAll();
$groupsById = [];
foreach ($groups as $g) {
    $groupsById[(int) $g['id']] = $g;
}

$ledgersStmt = db()->prepare('SELECT * FROM ledgers WHERE company_id = :cid ORDER BY code ASC');
$ledgersStmt->execute(['cid' => $companyId]);
$ledgers = $ledgersStmt->fetchAll();

// ---------------------------------------------------------------------------
// Export COA as CSV.
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chart-of-accounts-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($company['name'] ?? 'company')) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Level', 'Code', 'Name', 'Master', 'Nature', 'Group Code', 'Status']);
    foreach ($masters as $masterKey => $master) {
        fputcsv($out, ['Master', $masterKey, $master['label'], $masterKey, $master['nature'], '', 'Active']);
    }
    foreach ($groups as $g) {
        fputcsv($out, ['Group', $g['code'], $g['name'], $g['master_key'], ledger_master_nature((string) $g['master_key']) ?? '', '', ((int) $g['is_active'] === 1 ? 'Active' : 'Inactive')]);
    }
    foreach ($ledgers as $l) {
        $g = $groupsById[(int) ($l['group_id'] ?? 0)] ?? null;
        fputcsv($out, ['Ledger', $l['code'], $l['name'], $g['master_key'] ?? '', $l['type'], $g['code'] ?? '', ucfirst((string) $l['status'])]);
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Bulk import ledgers (CSV: group_code, ledger_code, ledger_name, type).
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'bulk_import') {
    verify_csrf();
    if (!user_can('create')) {
        flash('error', 'You do not have permission to import ledgers.');
        redirect('admin/chart-of-accounts.php');
    }
    $tmp = (string) ($_FILES['import_file']['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        flash('error', 'Choose a CSV file to import.');
        redirect('admin/chart-of-accounts.php');
    }
    $groupsByCode = [];
    foreach ($groups as $g) {
        $groupsByCode[strtoupper((string) $g['code'])] = $g;
    }
    $created = 0;
    $skipped = 0;
    $handle = fopen($tmp, 'r');
    $insert = db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, is_system, status) VALUES (:cid, :gid, :code, :name, :type, 0, 'active')");
    $exists = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE company_id = :cid AND code = :code');
    while (($row = fgetcsv($handle)) !== false) {
        $groupCode = strtoupper(trim((string) ($row[0] ?? '')));
        $code = strtoupper(trim((string) ($row[1] ?? '')));
        $name = trim((string) ($row[2] ?? ''));
        $type = strtolower(trim((string) ($row[3] ?? '')));
        if ($groupCode === 'GROUP_CODE' || $name === '' || !isset($groupsByCode[$groupCode]) || !in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
            $skipped++;
            continue;
        }
        if ($code === '') {
            $code = coa_next_ledger_code($companyId, (int) $groupsByCode[$groupCode]['id']);
        }
        $exists->execute(['cid' => $companyId, 'code' => $code]);
        if ((int) $exists->fetchColumn() > 0) {
            $skipped++;
            continue;
        }
        $insert->execute(['cid' => $companyId, 'gid' => (int) $groupsByCode[$groupCode]['id'], 'code' => $code, 'name' => $name, 'type' => $type]);
        $created++;
    }
    fclose($handle);
    log_activity('company', $companyId, 'coa_bulk_import', $created . ' ledgers imported, ' . $skipped . ' rows skipped.', $userId ?: null);
    flash($created > 0 ? 'success' : 'error', $created . ' ledgers imported' . ($skipped > 0 ? ', ' . $skipped . ' rows skipped (bad group/type, duplicate, or header)' : '') . '.');
    redirect('admin/chart-masters.php');
}

// ---------------------------------------------------------------------------
// Hierarchy: nature (1-5) -> groups -> ledgers. Real validation checks.
// ---------------------------------------------------------------------------
$natureOrder = ['asset' => ['1', 'Assets'], 'liability' => ['2', 'Liabilities'], 'equity' => ['3', 'Equity'], 'revenue' => ['4', 'Revenue'], 'expense' => ['5', 'Expenses']];
$groupsByNature = ['asset' => [], 'liability' => [], 'equity' => [], 'revenue' => [], 'expense' => []];
foreach ($groups as $g) {
    $nature = ledger_master_nature((string) $g['master_key']) ?? 'asset';
    $groupsByNature[$nature][] = $g;
}
$ledgersByGroup = [];
$orphanLedgers = [];
foreach ($ledgers as $l) {
    $gid = (int) ($l['group_id'] ?? 0);
    if ($gid > 0 && isset($groupsById[$gid])) {
        $ledgersByGroup[$gid][] = $l;
    } else {
        $orphanLedgers[] = $l;
    }
}

$mappingsCount = 0;
if (table_exists('company_ledger_mappings')) {
    $mapStmt = db()->prepare('SELECT COUNT(*) FROM company_ledger_mappings WHERE company_id = :cid');
    $mapStmt->execute(['cid' => $companyId]);
    $mappingsCount = (int) $mapStmt->fetchColumn();
}
$activeLedgers = count(array_filter($ledgers, static fn (array $l): bool => (string) $l['status'] === 'active'));
$dupStmt = db()->prepare('SELECT COUNT(*) FROM (SELECT code FROM ledgers WHERE company_id = :cid GROUP BY code HAVING COUNT(*) > 1) d');
$dupStmt->execute(['cid' => $companyId]);
$duplicateCodes = (int) $dupStmt->fetchColumn();
$activePct = count($ledgers) > 0 ? (int) round($activeLedgers / count($ledgers) * 100) : 0;

$pageTitle = 'Chart of Accounts';
$pageSubtitle = 'Manage masters, groups, ledgers, opening balances, and posting structure.';
$bodyClass = 'admin-layout accounting-module-page chart-accounts-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
$statusPill = static fn (string $status): string => $status === 'active'
    ? '<span class="mbw-pill tone-green">Active</span>'
    : '<span class="mbw-pill tone-red">' . e(ucfirst($status)) . '</span>';
?>
<?php if ($repairErrors !== []): ?><div class="notice error">Repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div><?php endif; ?>

<div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap">
    <a class="button secondary" href="<?= e(url('admin/chart-of-accounts.php')) ?>"><?= icon('reconcile') ?>Refresh</a>
    <a class="button secondary" href="<?= e(url('admin/chart-of-accounts.php?export=csv')) ?>"><?= icon('analytics') ?>Export COA</a>
    <a class="button" href="<?= e(url('admin/chart-groups.php')) ?>"><?= icon('layers') ?>＋ Create Group</a>
    <a class="button" href="<?= e(url('admin/chart-ledgers.php')) ?>" id="create-ledger"><?= icon('journal') ?>＋ Create Ledger</a>
</div>

<section class="mbw-kpi-grid" aria-label="Chart of accounts overview">
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Masters</span><div class="mbw-kpi-value"><?= count($natureOrder) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">System-defined</span></span></div><span class="mbw-chip tone-blue"><?= icon('layers') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Groups</span><div class="mbw-kpi-value"><?= count($groups) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Active</span></span></div><span class="mbw-chip tone-green"><?= icon('tree') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Ledgers</span><div class="mbw-kpi-value"><?= count($ledgers) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Posting accounts</span></span></div><span class="mbw-chip tone-purple"><?= icon('journal') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Posting Mappings</span><div class="mbw-kpi-value"><?= $mappingsCount ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Configured</span></span></div><span class="mbw-chip tone-amber"><?= icon('settings') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Active Ledgers</span><div class="mbw-kpi-value"><?= $activeLedgers ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= $activePct ?>% of total</span></span></div><span class="mbw-chip tone-teal"><?= icon('tasks') ?></span></article>
</section>

<nav class="mbw-tabbar" aria-label="Chart of accounts sections">
    <a class="mbw-tab is-active" href="<?= e(url('admin/chart-of-accounts.php')) ?>"><?= icon('tree') ?>Hierarchy View</a>
    <a class="mbw-tab" href="<?= e(url('admin/chart-masters.php')) ?>"><?= icon('layers') ?>Masters</a>
    <a class="mbw-tab" href="<?= e(url('admin/chart-groups.php')) ?>"><?= icon('teams') ?>Groups</a>
    <a class="mbw-tab" href="<?= e(url('admin/chart-ledgers.php')) ?>"><?= icon('journal') ?>Ledgers</a>
    <a class="mbw-tab" href="<?= e(url('admin/chart-posting-accounts.php')) ?>"><?= icon('settings') ?>Posting Accounts</a>
    <a class="mbw-tab" href="<?= e(url('admin/audit-trail.php')) ?>"><?= icon('admin') ?>Audit Log</a>
</nav>

<div class="frm-layout">
<div class="frm-main">
    <section class="mbw-card" aria-label="Chart of accounts hierarchy">
        <div class="mbw-card-head">
            <h2>Chart of Accounts Hierarchy</h2>
            <div class="mbw-card-tools">
                <button type="button" class="button secondary" style="min-height:32px;padding:4px 12px" onclick="document.querySelectorAll('.coa-node').forEach(function(r){r.style.display='';});document.querySelectorAll('.coa-tgl').forEach(function(t){t.textContent='▾';})">Expand All</button>
                <button type="button" class="button secondary" style="min-height:32px;padding:4px 12px" onclick="document.querySelectorAll('.coa-node.coa-child').forEach(function(r){r.style.display='none';});document.querySelectorAll('.coa-tgl').forEach(function(t){t.textContent='▸';})">Collapse All</button>
                <input type="search" id="coa-search" placeholder="Search in hierarchy..." style="min-height:34px;padding:5px 10px;font-size:12.5px;border:1px solid var(--mbw-border);border-radius:8px;background:var(--mbw-card);color:var(--mbw-text)">
            </div>
        </div>
        <div style="overflow-x:auto">
            <table id="coa-tree">
                <thead><tr><th style="width:120px">Code</th><th>Name</th><th style="width:90px">Type</th><th style="width:90px">Status</th><th style="width:140px">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($natureOrder as $nature => [$digit, $natureLabel]): ?>
                    <tr class="coa-node" data-name="<?= e(strtolower($natureLabel)) ?>">
                        <td><button type="button" class="coa-tgl" data-parent="m<?= e($digit) ?>" style="min-height:22px;width:22px;padding:0;border:0;background:transparent;color:var(--mbw-muted)">▾</button> <strong style="color:var(--mbw-heading)"><?= e($digit) ?></strong></td>
                        <td><strong style="color:var(--mbw-heading)"><?= e($natureLabel) ?></strong></td>
                        <td><span class="mbw-pill tone-blue">Master</span></td>
                        <td><span class="mbw-pill tone-green">Active</span></td>
                        <td><span style="color:var(--mbw-muted);font-size:11.5px">System-defined</span></td>
                    </tr>
                    <?php foreach ($groupsByNature[$nature] as $g): ?>
                        <?php $gid = (int) $g['id']; ?>
                        <tr class="coa-node coa-child" data-parent-of="m<?= e($digit) ?>" data-name="<?= e(strtolower($g['name'] . ' ' . $g['code'])) ?>">
                            <td style="padding-left:32px"><button type="button" class="coa-tgl" data-parent="g<?= $gid ?>" style="min-height:22px;width:22px;padding:0;border:0;background:transparent;color:var(--mbw-muted)">▾</button> <?= e($g['code']) ?></td>
                            <td style="padding-left:18px"><?= e($g['name']) ?></td>
                            <td><span class="mbw-pill tone-purple">Group</span></td>
                            <td><?= (int) $g['is_active'] === 1 ? '<span class="mbw-pill tone-green">Active</span>' : '<span class="mbw-pill tone-red">Inactive</span>' ?></td>
                            <td><a class="mbw-view-all" href="<?= e(url('admin/chart-groups.php')) ?>">Edit</a></td>
                        </tr>
                        <?php foreach ($ledgersByGroup[$gid] ?? [] as $l): ?>
                            <tr class="coa-node coa-child" data-parent-of="m<?= e($digit) ?> g<?= $gid ?>" data-name="<?= e(strtolower($l['name'] . ' ' . $l['code'])) ?>">
                                <td style="padding-left:64px"><?= e($l['code']) ?></td>
                                <td style="padding-left:36px"><?= e($l['name']) ?></td>
                                <td><span class="mbw-pill tone-teal">Ledger</span></td>
                                <td><?= $statusPill((string) $l['status']) ?></td>
                                <td style="display:flex;gap:10px">
                                    <a class="mbw-view-all" href="<?= e(url('admin/reports-center.php?report=ledger-report&ledger_id=' . (int) $l['id'])) ?>" title="View statement"><?= icon('search') ?></a>
                                    <a class="mbw-view-all" href="<?= e(url('admin/chart-ledgers.php')) ?>" title="Edit"><?= icon('settings') ?></a>
                                    <a class="mbw-view-all" href="<?= e(url('admin/audit-trail.php')) ?>" title="History"><?= icon('admin') ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php foreach ($orphanLedgers as $l): ?>
                    <tr class="coa-node" data-name="<?= e(strtolower($l['name'] . ' ' . $l['code'])) ?>">
                        <td><?= e($l['code']) ?></td>
                        <td><?= e($l['name']) ?> <span class="mbw-pill tone-amber">No group</span></td>
                        <td><span class="mbw-pill tone-teal">Ledger</span></td>
                        <td><?= $statusPill((string) $l['status']) ?></td>
                        <td><a class="mbw-view-all" href="<?= e(url('admin/chart-ledgers.php')) ?>">Assign group</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>


</div>

<aside class="frm-rail">
    <section class="mbw-card frm-rail-card">
        <div class="frm-section-head"><span class="mbw-chip is-square tone-blue"><?= icon('portal') ?></span><h2>Quick Actions</h2></div>
        <div class="mbw-qa-grid" style="grid-template-columns:1fr 1fr">
            <a class="mbw-qa" href="<?= e(url('admin/chart-groups.php')) ?>"><span class="mbw-chip is-square tone-green"><?= icon('tree') ?></span><div><strong>Create Group</strong></div></a>
            <a class="mbw-qa" href="<?= e(url('admin/chart-ledgers.php')) ?>"><span class="mbw-chip is-square tone-purple"><?= icon('journal') ?></span><div><strong>Create Ledger</strong></div></a>
            <a class="mbw-qa" href="<?= e(url('admin/chart-masters.php')) ?>"><span class="mbw-chip is-square tone-amber"><?= icon('layers') ?></span><div><strong>Bulk Import</strong></div></a>
            <a class="mbw-qa" href="<?= e(url('admin/chart-of-accounts.php?export=csv')) ?>"><span class="mbw-chip is-square tone-teal"><?= icon('analytics') ?></span><div><strong>Export COA</strong></div></a>
            <a class="mbw-qa" href="<?= e(url('admin/accounting.php')) ?>"><span class="mbw-chip is-square tone-blue"><?= icon('wallet') ?></span><div><strong>Opening Balances</strong></div></a>
            <a class="mbw-qa" href="<?= e(url('admin/chart-posting-accounts.php')) ?>"><span class="mbw-chip is-square tone-red"><?= icon('settings') ?></span><div><strong>Posting Mappings</strong></div></a>
        </div>
    </section>

    <section class="mbw-card frm-rail-card">
        <div class="frm-section-head"><span class="mbw-chip is-square tone-blue"><?= icon('about') ?></span><h2>Code Structure Rules</h2></div>
        <div style="display:grid;gap:10px;font-size:12px">
            <div style="border:1px solid var(--mbw-primary-soft);background:var(--mbw-primary-soft);border-radius:8px;padding:8px 10px"><strong style="color:var(--mbw-primary)"># Master Code = 1 Digit</strong><br><span style="color:var(--mbw-muted)">Examples: 1 Assets · 2 Liabilities · 3 Equity · 4 Revenue · 5 Expenses</span></div>
            <div style="border:1px solid var(--mbw-green-soft);background:var(--mbw-green-soft);border-radius:8px;padding:8px 10px"><strong style="color:var(--mbw-green)">## Group Code = 2 Digits</strong><br><span style="color:var(--mbw-muted)">Examples: 11 Current Assets · 12 Non-Current Assets · 21 Current Liabilities · 41 Operating Revenue · 51 Administrative Expenses</span></div>
            <div style="border:1px solid var(--mbw-purple-soft);background:var(--mbw-purple-soft);border-radius:8px;padding:8px 10px"><strong style="color:var(--mbw-purple)">### Ledger Code = 3 Digits</strong><br><span style="color:var(--mbw-muted)">Examples: 111 Cash in Hand · 112 Bank Account · 113 Trade Receivables · 211 Trade Payables · 411 Service Revenue · 511 Office Rent</span></div>
            <p style="margin:0;color:var(--mbw-muted)">Codes are generated automatically — never typed by hand. Existing legacy codes remain valid.</p>
        </div>
    </section>

    <section class="mbw-card frm-rail-card">
        <div class="frm-section-head"><span class="mbw-chip is-square tone-amber"><?= icon('settings') ?></span><h2>Modify Features</h2></div>
        <ul class="frm-checklist" style="gap:6px">
            <?php foreach ([['Edit code / name', 'admin/chart-ledgers.php'], ['Move ledger to another group', 'admin/chart-ledgers.php'], ['Deactivate / Archive', 'admin/chart-ledgers.php'], ['Set opening balance', 'admin/accounting.php'], ['Map auto-posting accounts', 'admin/chart-posting-accounts.php'], ['View audit history', 'admin/audit-trail.php']] as [$featureLabel, $featureUrl]): ?>
                <li class="is-ok" style="padding-left:0;list-style:none"><a class="mbw-view-all" href="<?= e(url($featureUrl)) ?>" style="display:flex;justify-content:space-between"><?= e($featureLabel) ?><span>→</span></a></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="mbw-card frm-rail-card">
        <div class="frm-section-head"><span class="mbw-chip is-square tone-green"><?= icon('tasks') ?></span><h2>Impact &amp; Validation</h2></div>
        <ul class="frm-checklist">
            <li class="<?= $duplicateCodes === 0 ? 'is-ok' : '' ?>">Unique code validation<br><small style="color:var(--mbw-muted)"><?= $duplicateCodes === 0 ? 'No duplicates found' : $duplicateCodes . ' duplicate codes!' ?></small></li>
            <li class="<?= $orphanLedgers === [] ? 'is-ok' : '' ?>">Parent-child dependency<br><small style="color:var(--mbw-muted)"><?= $orphanLedgers === [] ? 'Structure is valid' : count($orphanLedgers) . ' ledgers without a group' ?></small></li>
            <li class="<?= $activeLedgers === count($ledgers) ? 'is-ok' : '' ?>">Active / Inactive status<br><small style="color:var(--mbw-muted)"><?= $activeLedgers === count($ledgers) ? 'All accounts are active' : (count($ledgers) - $activeLedgers) . ' inactive accounts' ?></small></li>
        </ul>
        <p style="margin:12px 0 4px;color:var(--mbw-muted);font-size:11.5px;font-weight:600">Mapped modules affected</p>
        <div style="display:flex;gap:5px;flex-wrap:wrap">
            <?php foreach (['Vouchers' => 'blue', 'Sales' => 'green', 'Purchases' => 'red', 'Banking' => 'teal', 'Inventory' => 'amber', 'Reports' => 'purple'] as $module => $tone): ?>
                <span class="mbw-pill tone-<?= e($tone) ?>"><?= e($module) ?></span>
            <?php endforeach; ?>
        </div>
        <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:11px"><?= icon('tasks') ?> Last validation: <?= e(date('d M Y, h:i A')) ?></p>
    </section>
</aside>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Collapse/expand branches.
    document.querySelectorAll('.coa-tgl').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            var key = toggle.getAttribute('data-parent');
            var open = toggle.textContent.trim() === '▾';
            toggle.textContent = open ? '▸' : '▾';
            document.querySelectorAll('.coa-node.coa-child').forEach(function (row) {
                var parents = (row.getAttribute('data-parent-of') || '').split(' ');
                if (parents.indexOf(key) !== -1) {
                    row.style.display = open ? 'none' : '';
                }
            });
        });
    });
    // Live search across the tree.
    var search = document.getElementById('coa-search');
    search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        document.querySelectorAll('.coa-node').forEach(function (row) {
            row.style.display = q === '' || (row.getAttribute('data-name') || '').indexOf(q) !== -1 ? '' : 'none';
        });
    });
});
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
