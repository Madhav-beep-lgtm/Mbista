<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/export_engine.php';
require_once __DIR__ . '/../../app/coa_import.php';

require_staff_admin_or_client_books();
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
    require_permission('reports', 'export');
    security_event('report_exported', 'success', 'Chart of accounts exported to CSV.', $companyId, $userId ?: null);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chart-of-accounts-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($company['name'] ?? 'company')) . '.csv"');
    $out = fopen('php://output', 'w');
    // Deliberately the SAME columns the importer reads, so an exported chart can
    // be edited and uploaded straight back. The two used to disagree — seven
    // columns out, four different ones in — which made "download the current COA
    // as a template" advice that could not work.
    //
    // The opening columns go out empty. This file describes the SHAPE of the
    // chart; balances are derived from the perpetual ledger, and re-importing
    // them would either double-post or be skipped, so writing numbers here
    // would only imply a round-trip that does not exist.
    fputcsv($out, COA_IMPORT_COLUMNS);
    foreach ($masters as $masterKey => $master) {
        fputcsv($out, ['Master', $masterKey, $master['label'], $masterKey, $master['nature'], '', '', '', 'Active']);
    }
    foreach ($groups as $g) {
        fputcsv($out, ['Group', $g['code'], $g['name'], $g['master_key'], ledger_master_nature((string) $g['master_key']) ?? '', '', '', '', ((int) $g['is_active'] === 1 ? 'Active' : 'Inactive')]);
    }
    foreach ($ledgers as $l) {
        $g = $groupsById[(int) ($l['group_id'] ?? 0)] ?? null;
        fputcsv($out, ['Ledger', $l['code'], $l['name'], $g['master_key'] ?? '', $l['type'], $g['code'] ?? '', '', '', ucfirst((string) $l['status'])]);
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// The blank template, in either format.
// ---------------------------------------------------------------------------
if (($_GET['import_template'] ?? '') !== '') {
    $tplRows = coa_import_template_rows();
    if ((string) $_GET['import_template'] === 'csv') {
        export_csv('chart-of-accounts-template.csv', $tplRows);
    }
    export_xlsx('chart-of-accounts-template.xlsx', $tplRows, 'Chart of Accounts');
}

// ---------------------------------------------------------------------------
// Chart of accounts from a spreadsheet: upload → preview → commit.
//
// The old importer applied the file the moment it was uploaded and reported
// "n rows skipped" without saying which or why. A chart is what every voucher,
// mapping and report in the system points at, so it is worth reading before it
// is created — the upload now stages, and only Commit writes anything.
// ---------------------------------------------------------------------------
$coaImportActions = ['coa_upload', 'coa_commit', 'coa_discard'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) ($_POST['action'] ?? ''), $coaImportActions, true)) {
    verify_csrf();
    $importAction = (string) $_POST['action'];
    if (!user_can('create')) {
        flash('error', 'You do not have permission to import a chart of accounts.');
        redirect('admin/chart-of-accounts.php');
    }
    require_permission('accounting', 'create');

    try {
        if ($importAction === 'coa_upload') {
            $file = $_FILES['import_file'] ?? null;
            if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_uploaded_file((string) $file['tmp_name'])) {
                throw new RuntimeException('Choose a .xlsx or .csv file to upload.');
            }
            // A batch nobody decided on would otherwise sit behind this one and
            // the preview would be showing a different file than the one just
            // uploaded. The newest upload is the one the user means.
            $pending = coa_import_latest_staged($companyId);
            if ($pending !== null) {
                coa_import_discard($companyId, (int) $pending['id']);
            }
            $importId = coa_import_stage($companyId, (int) (current_fiscal_year()['id'] ?? 0) ?: null,
                $userId, (string) $file['tmp_name'], (string) ($file['name'] ?? 'chart.xlsx'));
            $staged = coa_import_batch($companyId, $importId);
            flash('success', 'Read ' . (int) $staged['row_count'] . ' rows — nothing has been created yet. '
                . (int) $staged['ready_count'] . ' will be created. Check the preview, then Commit.');
        } elseif ($importAction === 'coa_commit') {
            $result = coa_import_commit($companyId, (int) ($_POST['import_id'] ?? 0), $userId);
            if (!$result['ok']) {
                throw new RuntimeException((string) $result['error']);
            }
            $summary = $result['groups'] . ' group(s), ' . $result['ledgers'] . ' ledger(s) and '
                . $result['openings'] . ' opening balance(s) created.';
            security_event('coa_bulk_import', 'success', $summary, $companyId, $userId ?: null);
            log_activity('company', $companyId, 'coa_bulk_import', $summary, $userId ?: null);
            flash('success', $summary);
        } else {
            coa_import_discard($companyId, (int) ($_POST['import_id'] ?? 0));
            flash('success', 'The upload was discarded. Nothing was created.');
        }
    } catch (Throwable $importException) {
        flash('error', $importException->getMessage());
    }
    redirect('admin/chart-of-accounts.php#coa-import');
}

$coaStaged = coa_import_latest_staged($companyId);
$coaStagedRows = $coaStaged !== null ? coa_import_rows($companyId, (int) $coaStaged['id']) : [];
$coaBalanceError = $coaStaged !== null ? coa_import_balance_error($coaStaged) : null;

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

<?php
// The verdict on each row, said in the words the preview uses. "Create" rather
// than "ready" because the question the reader is actually asking is what this
// button is about to do to their books.
$coaRowPill = static function (string $status): string {
    if ($status === 'ready') { return '<span class="mbw-pill tone-green">Create</span>'; }
    if ($status === 'skipped') { return '<span class="mbw-pill tone-amber">Skip</span>'; }
    if ($status === 'committed') { return '<span class="mbw-pill tone-blue">Created</span>'; }

    return '<span class="mbw-pill tone-red">Reject</span>';
};
?>
<details class="feature-disclosure" id="coa-import"<?= $coaStaged !== null ? ' open' : '' ?>>
    <summary><span><strong><?= icon('documents') ?>Chart of accounts from a spreadsheet</strong></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>

    <p class="frm-optional" style="margin:0 0 12px">
        <strong>Uploading creates nothing.</strong> Every row is checked and shown back to you first; only
        Commit writes to the chart. Groups and ledgers can travel in one sheet — a ledger may name a group
        written further down. Leave <em>Code</em> blank to have codes generated.
        <a href="<?= e(url('admin/chart-of-accounts.php?import_template=xlsx')) ?>">Download template (.xlsx)</a>
        &middot; <a href="<?= e(url('admin/chart-of-accounts.php?import_template=csv')) ?>">.csv</a>
        &middot; <a href="<?= e(url('admin/chart-of-accounts.php?export=csv')) ?>">Export the current chart to edit</a>
    </p>

    <form method="post" enctype="multipart/form-data" class="workspace-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="coa_upload">
        <label>Spreadsheet<input type="file" name="import_file" accept=".xlsx,.csv" required></label>
        <div style="align-self:end">
            <button type="submit" class="button"><?= icon('layers') ?>Upload &amp; Preview</button>
            <span class="frm-optional">.xlsx or .csv, up to <?= number_format(COA_IMPORT_MAX_ROWS) ?> rows</span>
        </div>
    </form>

    <?php if ($coaStaged !== null): ?>
        <?php
        $readyCount = (int) $coaStaged['ready_count'];
        $drTotal = (float) $coaStaged['opening_dr_total'];
        $crTotal = (float) $coaStaged['opening_cr_total'];
        ?>
        <div class="mbw-card-head" style="margin-top:18px">
            <h2>Preview — <?= e((string) $coaStaged['original_name']) ?></h2>
            <span class="frm-optional">
                <?= (int) $coaStaged['row_count'] ?> rows read &middot;
                <strong><?= $readyCount ?></strong> to create &middot;
                <?= (int) $coaStaged['skipped_count'] ?> skipped &middot;
                <?= (int) $coaStaged['error_count'] ?> rejected
            </span>
        </div>

        <?php if ($drTotal > 0 || $crTotal > 0): ?>
            <div class="notice <?= $coaBalanceError !== null ? 'error' : 'success' ?>" style="margin:0 0 12px">
                Opening balances: debits <?= e(number_format($drTotal, 2)) ?>,
                credits <?= e(number_format($crTotal, 2)) ?>.
                <?= $coaBalanceError !== null ? e($coaBalanceError) : 'These balance, so they can be posted.' ?>
            </div>
        <?php endif; ?>

        <div style="overflow-x:auto">
            <table class="mbw-table">
                <thead><tr>
                    <th>Row</th><th>Level</th><th>Code</th><th>Name</th><th>Master / Type</th>
                    <th>Group</th><th class="num">Opening Dr</th><th class="num">Opening Cr</th><th>Verdict</th>
                </tr></thead>
                <tbody>
                <?php foreach ($coaStagedRows as $row): ?>
                    <tr>
                        <td><?= (int) $row['source_row_no'] ?></td>
                        <td><?= e(ucfirst((string) $row['raw_level'])) ?></td>
                        <td><?= e((string) $row['raw_code']) ?: '<span class="frm-optional">auto</span>' ?></td>
                        <td><?= e((string) $row['raw_name']) ?></td>
                        <td><?= e((string) $row['raw_master'] !== '' ? (string) $row['raw_master'] : (string) $row['raw_type']) ?></td>
                        <td><?= e((string) $row['raw_group_code']) ?: '—' ?></td>
                        <td class="num"><?= (float) $row['opening_dr'] > 0 ? e(number_format((float) $row['opening_dr'], 2)) : '' ?></td>
                        <td class="num"><?= (float) $row['opening_cr'] > 0 ? e(number_format((float) $row['opening_cr'], 2)) : '' ?></td>
                        <td>
                            <?= $coaRowPill((string) $row['status']) ?>
                            <?php if ((string) ($row['error_text'] ?? '') !== ''): ?>
                                <br><small class="frm-optional"><?= e((string) $row['error_text']) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
            <form method="post" onsubmit="return confirm('Create <?= $readyCount ?> account(s) in the chart?')">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="coa_commit">
                <input type="hidden" name="import_id" value="<?= (int) $coaStaged['id'] ?>">
                <button type="submit" class="button" <?= ($readyCount === 0 || $coaBalanceError !== null) ? 'disabled' : '' ?>>
                    <?= icon('journal') ?>Commit <?= $readyCount ?> account(s)
                </button>
            </form>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="coa_discard">
                <input type="hidden" name="import_id" value="<?= (int) $coaStaged['id'] ?>">
                <button type="submit" class="button secondary">Discard</button>
            </form>
        </div>
        <?php if ($readyCount === 0): ?>
            <p class="frm-optional" style="margin-top:8px">There is nothing to create from this file — every row
                either already exists or was rejected.</p>
        <?php endif; ?>
    <?php endif; ?>
</details>

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
