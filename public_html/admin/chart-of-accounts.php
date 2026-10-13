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

// ---------------------------------------------------------------------------
// Presentation export is Excel; CSV remains the round-trip import format.
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'xlsx') {
    require_permission('reports', 'export');
    require_once __DIR__ . '/../../app/coa_report.php';
    $reportLedgers = db()->prepare('SELECT * FROM ledgers WHERE company_id = :cid ORDER BY code ASC');
    $reportLedgers->execute(['cid' => $companyId]);
    $report = coa_report_data($company, current_fiscal_year() ?? [], $masters, $groups, $reportLedgers->fetchAll(PDO::FETCH_ASSOC));
    security_event('report_exported', 'success', 'Chart of accounts exported to Excel.', $companyId, $userId ?: null);
    export_xlsx('chart-of-accounts-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($company['name'] ?? 'company')) . '.xlsx',
        $report['rows'], 'Chart of Accounts', $report['widths'], $report['options']);
}
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
    fputcsv($out, COA_IMPORT_COLUMNS, ',', '"', '\\');
    foreach ($masters as $masterKey => $master) {
        fputcsv($out, ['Master', $masterKey, $master['label'], $masterKey, $master['nature'], '', '', '', 'Active'], ',', '"', '\\');
    }
    foreach ($groups as $g) {
        fputcsv($out, ['Group', $g['code'], $g['name'], $g['master_key'], ledger_master_nature((string) $g['master_key']) ?? '', '', '', '', ((int) $g['is_active'] === 1 ? 'Active' : 'Inactive')], ',', '"', '\\');
    }
    // Stream the potentially large ledger set instead of retaining it in PHP
    // memory. CSV responses are consumed one row at a time by the browser.
    $exportLedgers = db()->prepare('SELECT * FROM ledgers WHERE company_id = :cid ORDER BY code ASC');
    $exportLedgers->execute(['cid' => $companyId]);
    while ($l = $exportLedgers->fetch(PDO::FETCH_ASSOC)) {
        $g = $groupsById[(int) ($l['group_id'] ?? 0)] ?? null;
        fputcsv($out, ['Ledger', $l['code'], $l['name'], $g['master_key'] ?? '', $l['type'], $g['code'] ?? '', '', '', ucfirst((string) $l['status'])], ',', '"', '\\');
    }
    fclose($out);
    exit;
}

// Keep the interactive hierarchy bounded. Large charts previously loaded
// every ledger, even when the user only needed one account. A shared search
// covers ledger codes, names and their groups.
$coaSearch = trim((string) ($_GET['q'] ?? ''));
$coaViews = ['hierarchy' => 'Hierarchy', 'masters' => 'Masters', 'groups' => 'Groups', 'ledgers' => 'Ledgers', 'posting' => 'Posting accounts', 'audit' => 'Audit log'];
$coaView = (string) ($_GET['view'] ?? 'hierarchy');
if (!isset($coaViews[$coaView])) {
    $coaView = 'hierarchy';
}
$coaWhere = ['company_id = :cid'];
$coaBindings = ['cid' => $companyId];
if ($coaSearch !== '') {
    // Native PDO prepares cannot reuse one named placeholder twice.
    $coaWhere[] = '(code LIKE :coa_code_search OR name LIKE :coa_name_search
        OR EXISTS (SELECT 1 FROM ledger_groups g
            WHERE g.id = ledgers.group_id AND g.company_id = ledgers.company_id
              AND (g.name LIKE :coa_group_name_search OR g.code LIKE :coa_group_code_search)))';
    $coaBindings['coa_code_search'] = '%' . $coaSearch . '%';
    $coaBindings['coa_name_search'] = '%' . $coaSearch . '%';
    $coaBindings['coa_group_name_search'] = '%' . $coaSearch . '%';
    $coaBindings['coa_group_code_search'] = '%' . $coaSearch . '%';
}
$coaWhereSql = implode(' AND ', $coaWhere);
$ledgerStatsStmt = db()->prepare("SELECT COUNT(*) AS total_count,
        COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active_count
    FROM ledgers WHERE company_id = :cid");
$ledgerStatsStmt->execute(['cid' => $companyId]);
$ledgerStats = $ledgerStatsStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_count' => 0, 'active_count' => 0];
$totalLedgers = (int) $ledgerStats['total_count'];
$activeLedgers = (int) $ledgerStats['active_count'];
$activePct = $totalLedgers > 0 ? (int) round($activeLedgers / $totalLedgers * 100) : 0;

$filteredCountStmt = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE ' . $coaWhereSql);
$filteredCountStmt->execute($coaBindings);
$filteredLedgerCount = (int) $filteredCountStmt->fetchColumn();
$ledgerViewLimit = 500;
$ledgersStmt = db()->prepare('SELECT * FROM ledgers WHERE ' . $coaWhereSql . ' ORDER BY code ASC LIMIT ' . $ledgerViewLimit);
$ledgersStmt->execute($coaBindings);
$ledgers = $ledgersStmt->fetchAll(PDO::FETCH_ASSOC);

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
$coaImportActions = ['coa_upload', 'coa_commit', 'coa_discard', 'coa_row_save', 'coa_row_delete'];
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
        } elseif ($importAction === 'coa_row_save') {
            // Correcting one cell can change the verdict on a different row —
            // the group being fixed here may be the one another row names — so
            // the engine re-judges the batch and the whole preview is redrawn.
            $rowResult = coa_import_update_row($companyId, (int) ($_POST['row_id'] ?? 0), [
                'level' => (string) ($_POST['level'] ?? ''),
                'code' => (string) ($_POST['code'] ?? ''),
                'name' => (string) ($_POST['name'] ?? ''),
                'master' => (string) ($_POST['master'] ?? ''),
                'type' => (string) ($_POST['type'] ?? ''),
                'group_code' => (string) ($_POST['group_code'] ?? ''),
                'opening_dr' => (string) ($_POST['opening_dr'] ?? ''),
                'opening_cr' => (string) ($_POST['opening_cr'] ?? ''),
            ]);
            if (!$rowResult['ok']) {
                throw new RuntimeException((string) $rowResult['error']);
            }
            if ((string) $rowResult['status'] === 'ready') {
                flash('success', 'Row updated — it will be created on commit.');
            } else {
                flash('error', 'Row updated, but it still will not be created: ' . $rowResult['row_error']);
            }
        } elseif ($importAction === 'coa_row_delete') {
            $rowResult = coa_import_delete_row($companyId, (int) ($_POST['row_id'] ?? 0));
            if (!$rowResult['ok']) {
                throw new RuntimeException((string) $rowResult['error']);
            }
            flash('success', 'Row removed from the upload.');
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
$pageTitle = 'Chart of Accounts';
$postingRows = [];
if (table_exists('company_ledger_mappings')) {
    $postingStmt = db()->prepare('SELECT m.map_key, l.code, l.name FROM company_ledger_mappings m LEFT JOIN ledgers l ON l.id = m.ledger_id AND l.company_id = m.company_id WHERE m.company_id = :cid ORDER BY m.map_key');
    $postingStmt->execute(['cid' => $companyId]);
    $postingRows = $postingStmt->fetchAll(PDO::FETCH_ASSOC);
}
$coaAuditRows = [];
$coaCanViewAudit = user_can_do('accounting', 'view');
if ($coaCanViewAudit && table_exists('activity_logs') && column_exists('activity_logs', 'company_id')) {
    $coaAuditStmt = db()->prepare('SELECT al.created_at, al.entity_type, al.action, al.details, u.name AS actor_name FROM activity_logs al LEFT JOIN users u ON u.id = al.actor_id WHERE al.company_id = :cid ORDER BY al.created_at DESC, al.id DESC LIMIT 100');
    $coaAuditStmt->execute(['cid' => $companyId]);
    $coaAuditRows = $coaAuditStmt->fetchAll(PDO::FETCH_ASSOC);
}
$pageHero = false;
$pageSubtitle = 'Manage masters, groups, ledgers, opening balances, and posting structure.';
$bodyClass = 'admin-layout accounting-module-page chart-accounts-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
$statusPill = static fn (string $status): string => $status === 'active'
    ? '<span class="mbw-pill tone-green">Active</span>'
    : '<span class="mbw-pill tone-red">' . e(ucfirst($status)) . '</span>';
?>
<?php if ($repairErrors !== []): ?><div class="notice error">Repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div><?php endif; ?>

<form method="get" class="coa-filter-bar" style="display:flex;align-items:flex-end;flex-wrap:wrap;gap:12px;margin:14px 0">
    <label style="flex:0 1 180px;min-width:0">View
        <select id="coa-section" name="view" aria-controls="coa-tree-panel coa-posting-panel coa-audit-panel">
            <?php foreach ($coaViews as $viewKey => $viewLabel): ?>
                <option value="<?= e($viewKey) ?>" <?= $coaView === $viewKey ? 'selected' : '' ?>><?= e($viewLabel) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label style="flex:0 1 240px;min-width:0">Accounts
        <input type="search" id="coa-search" name="q" value="<?= e($coaSearch) ?>" placeholder="Search accounts...">
    </label>
    <div style="align-self:end;display:flex;gap:10px">
        <button class="button" type="submit"><?= icon('search') ?>Filter</button>
        <a class="button secondary" href="<?= e(url('admin/chart-of-accounts.php')) ?>">Clear</a>
        <details class="action-menu" id="coa-upload-menu">
            <summary class="button secondary coa-upload-toggle" aria-label="Spreadsheet actions" title="Upload or download template">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 16V3m-5 5 5-5 5 5M4 15v5h16v-5"/></svg>
            </summary>
            <div class="action-menu-options">
                <button type="button" id="coa-upload-trigger">Upload</button>
                <a href="<?= e(url('admin/chart-of-accounts.php?import_template=xlsx')) ?>">Download template</a>
            </div>
        </details>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto">
        <a class="button secondary" href="<?= e(url('admin/chart-of-accounts.php?export=xlsx')) ?>"><?= icon('analytics') ?>Export COA</a>
        <a class="button" href="<?= e(url('admin/create-account.php')) ?>"><?= icon('layers') ?>Create account</a>
    </div>
</form>
<form method="post" enctype="multipart/form-data" id="coa-upload-form" hidden>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="coa_upload">
    <input type="file" name="import_file" id="coa-upload-file" accept=".xlsx,.csv" aria-label="Upload chart of accounts spreadsheet" required>
</form>
<p id="coa-upload-status" role="status" hidden>Uploading spreadsheet for preview…</p>
<?php if ($filteredLedgerCount > count($ledgers)): ?>
    <div class="notice">Showing the first <?= count($ledgers) ?> of <?= $filteredLedgerCount ?> matching ledgers. Narrow the search or group to locate another account.</div>
<?php endif; ?>

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
<section class="mbw-card" id="coa-import" aria-label="Spreadsheet preview"<?= $coaStaged === null ? ' hidden' : '' ?>>

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
                    <th>Group</th><th class="num">Opening Dr</th><th class="num">Opening Cr</th>
                    <th>Verdict</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($coaStagedRows as $row): ?>
                    <?php
                    // Rows that will import read as plain text — the preview's main
                    // job is to be READ, and 200 accounts' worth of input boxes is
                    // not readable. A row that needs attention becomes editable in
                    // place, which is the only place editing actually earns its
                    // keep: fixing one cell here beats correcting the sheet in
                    // Excel and uploading the whole thing again.
                    $rowId = (int) $row['id'];
                    $formId = 'coa-row-' . $rowId;
                    $editable = in_array((string) $row['status'], ['error', 'skipped'], true);
                    $cell = static fn (string $field, string $value, string $placeholder = ''): string
                        => '<input form="' . $formId . '" name="' . $field . '" value="' . e($value)
                            . '" placeholder="' . e($placeholder) . '" style="width:100%;min-width:78px">';
                    ?>
                    <tr>
                        <td><?= (int) $row['source_row_no'] ?></td>
                        <?php if ($editable): ?>
                            <td><?= $cell('level', (string) $row['raw_level'], 'Group/Ledger') ?></td>
                            <td><?= $cell('code', (string) $row['raw_code'], 'auto') ?></td>
                            <td><?= $cell('name', (string) $row['raw_name']) ?></td>
                            <td>
                                <?= $cell('master', (string) $row['raw_master'], 'master') ?>
                                <?= $cell('type', (string) $row['raw_type'], 'type') ?>
                            </td>
                            <td><?= $cell('group_code', (string) $row['raw_group_code'], 'group') ?></td>
                            <td class="num"><?= $cell('opening_dr', (string) $row['raw_opening_dr']) ?></td>
                            <td class="num"><?= $cell('opening_cr', (string) $row['raw_opening_cr']) ?></td>
                        <?php else: ?>
                            <td><?= e(ucfirst((string) $row['raw_level'])) ?></td>
                            <td><?= e((string) $row['raw_code']) ?: '<span class="frm-optional">auto</span>' ?></td>
                            <td><?= e((string) $row['raw_name']) ?></td>
                            <td><?= e((string) $row['raw_master'] !== '' ? (string) $row['raw_master'] : (string) $row['raw_type']) ?></td>
                            <td><?= e((string) $row['raw_group_code']) ?: '—' ?></td>
                            <td class="num"><?= (float) $row['opening_dr'] > 0 ? e(number_format((float) $row['opening_dr'], 2)) : '' ?></td>
                            <td class="num"><?= (float) $row['opening_cr'] > 0 ? e(number_format((float) $row['opening_cr'], 2)) : '' ?></td>
                        <?php endif; ?>
                        <td>
                            <?= $coaRowPill((string) $row['status']) ?>
                            <?php if ((string) ($row['error_text'] ?? '') !== ''): ?>
                                <br><small class="frm-optional"><?= e((string) $row['error_text']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <?php if ($editable): ?>
                                <?php // The inputs above live in other cells and join this
                                      // form by id — a form cannot wrap table cells. ?>
                                <form method="post" id="<?= e($formId) ?>" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="coa_row_save">
                                    <input type="hidden" name="row_id" value="<?= $rowId ?>">
                                    <button type="submit" class="button secondary">Save</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" style="display:inline"
                                  onsubmit="return confirm('Remove row <?= (int) $row['source_row_no'] ?> from this upload?')">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="coa_row_delete">
                                <input type="hidden" name="row_id" value="<?= $rowId ?>">
                                <button type="submit" class="button secondary">Remove</button>
                            </form>
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
            
        <?php endif; ?>
    <?php endif; ?>
</section>



    <section class="mbw-card" id="coa-tree-panel" aria-label="Chart of accounts hierarchy">
        <div style="overflow-x:auto">
            <table id="coa-tree" class="coa-table">
                <thead><tr><th scope="col" style="width:240px">Code</th><th scope="col">Account name</th><th scope="col" style="width:100px">Level</th><th scope="col" style="width:110px">Nature</th><th scope="col" style="width:110px">Status</th><th scope="col" style="width:140px">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($natureOrder as $nature => [$digit, $natureLabel]): ?>
                    <tr class="coa-node coa-master-row" data-name="<?= e(strtolower($natureLabel)) ?>">
                        <td><button type="button" class="coa-tgl" data-parent="m<?= e($digit) ?>" style="min-height:22px;width:22px;padding:0;border:0;background:transparent;color:var(--mbw-muted)">▾</button> <strong style="color:var(--mbw-heading)"><?= e($digit) ?></strong></td>
                        <td><strong style="color:var(--mbw-heading)"><?= e($natureLabel) ?></strong></td>
                        <td><span class="mbw-pill tone-blue">Master</span></td>
                        <td><?= e(ucfirst((string) $nature)) ?></td>
                        <td><span class="mbw-pill tone-green">Active</span></td>
                        <td><span style="color:var(--mbw-muted);font-size:11.5px">System-defined</span></td>
                    </tr>
                    <?php foreach ($groupsByNature[$nature] as $g): ?>
                        <?php $gid = (int) $g['id']; ?>
                        <tr class="coa-node coa-child coa-group-row" data-parent-of="m<?= e($digit) ?>" data-name="<?= e(strtolower($g['name'] . ' ' . $g['code'])) ?>">
                            <td style="padding-left:32px"><button type="button" class="coa-tgl" data-parent="g<?= $gid ?>" style="min-height:22px;width:22px;padding:0;border:0;background:transparent;color:var(--mbw-muted)">▾</button> <?= e($g['code']) ?></td>
                            <td style="padding-left:18px"><?= e($g['name']) ?></td>
                            <td><span class="mbw-pill tone-purple"><?= !empty($g['parent_group_id']) ? 'Subgroup' : 'Group' ?></span></td>
                            <td><?= e(ucfirst((string) (ledger_master_nature((string) $g['master_key']) ?? ''))) ?></td>
                            <td><?= (int) $g['is_active'] === 1 ? '<span class="mbw-pill tone-green">Active</span>' : '<span class="mbw-pill tone-red">Inactive</span>' ?></td>
                            <td><a class="mbw-view-all" href="<?= e(url('admin/chart-groups.php?edit=' . $gid)) ?>">Edit</a></td>
                        </tr>
                        <?php foreach ($ledgersByGroup[$gid] ?? [] as $l): ?>
                            <tr class="coa-node coa-child" data-parent-of="m<?= e($digit) ?> g<?= $gid ?>" data-name="<?= e(strtolower($l['name'] . ' ' . $l['code'])) ?>">
                                <td style="padding-left:64px"><?= e($l['code']) ?></td>
                                <td style="padding-left:36px"><?= e($l['name']) ?></td>
                                <td><span class="mbw-pill tone-teal"><?= !empty($l['parent_ledger_id']) ? 'Subledger' : 'Ledger' ?></span></td>
                                <td><?= e(ucfirst((string) $l['type'])) ?></td>
                                <td><?= $statusPill((string) $l['status']) ?></td>
                                <td><div class="coa-row-actions">
                                    <a class="mbw-view-all" href="<?= e(url('admin/reports-center.php?report=ledger-report&ledger_id=' . (int) $l['id'])) ?>" title="View statement"><?= icon('search') ?></a>
                                    <a class="mbw-view-all" href="<?= e(url('admin/chart-ledgers.php?edit_id=' . (int) $l['id'])) ?>" title="Edit"><?= icon('settings') ?></a>
                                    <a class="mbw-view-all" href="<?= e(url('admin/audit-trail.php')) ?>" title="History"><?= icon('admin') ?></a>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php foreach ($orphanLedgers as $l): ?>
                    <tr class="coa-node" data-name="<?= e(strtolower($l['name'] . ' ' . $l['code'])) ?>">
                        <td><?= e($l['code']) ?></td>
                        <td><?= e($l['name']) ?> <span class="mbw-pill tone-amber">No group</span></td>
                        <td><span class="mbw-pill tone-teal">Ledger</span></td>
                        <td><?= e(ucfirst((string) $l['type'])) ?></td>
                        <td><?= $statusPill((string) $l['status']) ?></td>
                        <td><a class="mbw-view-all" href="<?= e(url('admin/chart-ledgers.php?edit_id=' . (int) $l['id'])) ?>">Assign group</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p id="coa-no-matches" hidden style="padding:16px">No matching accounts.</p>
    </section>

    <section class="mbw-card" id="coa-posting-panel" aria-label="Posting accounts" hidden>
        <div style="overflow-x:auto">
            <table class="coa-table">
                <thead><tr><th>Posting role</th><th>Code</th><th>Ledger</th></tr></thead>
                <tbody>
                <?php foreach ($postingRows as $row): ?>
                    <tr data-coa-search-row><td><?= e(ucwords(str_replace('_', ' ', (string) $row['map_key']))) ?></td><td><?= e((string) ($row['code'] ?? '')) ?></td><td><?= e((string) ($row['name'] ?? 'Unassigned')) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p data-coa-empty style="padding:16px" hidden>No matching posting accounts.</p>
        </div>
    </section>
    <section class="mbw-card" id="coa-audit-panel" aria-label="Audit log" hidden>
        <?php if (!$coaCanViewAudit): ?>
            <p style="padding:16px">You do not have permission to view the audit log.</p>
        <?php else: ?>
            <p style="padding:12px 16px">Latest 100 company activities.</p>
            <div style="overflow-x:auto">
                <table class="coa-table">
                    <thead><tr><th>Date and time</th><th>User</th><th>Module</th><th>Action</th><th>Details</th></tr></thead>
                    <tbody>
                    <?php foreach ($coaAuditRows as $row): ?>
                        <tr data-coa-search-row><td><?= e((string) $row['created_at']) ?></td><td><?= e((string) ($row['actor_name'] ?? 'System')) ?></td><td><?= e((string) $row['entity_type']) ?></td><td><?= e((string) $row['action']) ?></td><td><?= e((string) ($row['details'] ?? '')) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p data-coa-empty style="padding:16px" hidden>No matching activity.</p>
            </div>
        <?php endif; ?>
    </section>

</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    var uploadMenu = document.getElementById('coa-upload-menu');
    var uploadFile = document.getElementById('coa-upload-file');
    document.getElementById('coa-upload-trigger').addEventListener('click', function () {
        uploadMenu.open = false;
        uploadFile.click();
    });
    uploadFile.addEventListener('change', function () {
        if (!uploadFile.files.length) return;
        document.getElementById('coa-upload-status').hidden = false;
        document.getElementById('coa-upload-form').requestSubmit();
    });
    document.addEventListener('click', function (event) {
        if (!uploadMenu.contains(event.target)) uploadMenu.open = false;
    });
    uploadMenu.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            uploadMenu.open = false;
            uploadMenu.querySelector('summary').focus();
        }
    });
    var view = document.getElementById('coa-section');
    var search = document.getElementById('coa-search');
    var rows = Array.from(document.querySelectorAll('#coa-tree .coa-node'));
    var branches = {};
    rows.forEach(function (row) {
        var toggle = row.querySelector('.coa-tgl');
        if (toggle) branches[toggle.getAttribute('data-parent')] = row;
    });
    function updateView() {
        var kind = view.value;
        var q = search.value.trim().toLowerCase();
        var treeVisible = ['hierarchy', 'masters', 'groups', 'ledgers'].indexOf(kind) !== -1;
        document.getElementById('coa-tree-panel').hidden = !treeVisible;
        document.getElementById('coa-posting-panel').hidden = kind !== 'posting';
        document.getElementById('coa-audit-panel').hidden = kind !== 'audit';
        var matches = new Set();
        rows.forEach(function (row) {
            var type = row.cells[2].textContent.trim().toLowerCase().replace(/^sub/, '') + 's';
            var parents = (row.getAttribute('data-parent-of') || '').split(' ').filter(Boolean);
            var text = row.getAttribute('data-name') || '';
            var match = !q || text.indexOf(q) !== -1 || parents.some(function (key) {
                return branches[key] && (branches[key].getAttribute('data-name') || '').indexOf(q) !== -1;
            });
            if ((kind === 'hierarchy' || kind === type) && match) {
                matches.add(row);
                if (kind === 'hierarchy' && q) parents.forEach(function (key) {
                    if (branches[key]) matches.add(branches[key]);
                });
            }
        });
        rows.forEach(function (row) {
            var parents = (row.getAttribute('data-parent-of') || '').split(' ').filter(Boolean);
            var collapsed = kind === 'hierarchy' && !q && parents.some(function (key) {
                return branches[key] && branches[key].dataset.collapsed === 'true';
            });
            row.style.display = matches.has(row) && !collapsed ? '' : 'none';
            var toggle = row.querySelector('.coa-tgl');
            if (toggle) toggle.hidden = kind !== 'hierarchy';
        });
        document.getElementById('coa-no-matches').hidden = matches.size !== 0;
        ['coa-posting-panel', 'coa-audit-panel'].forEach(function (id) {
            var panel = document.getElementById(id);
            var count = 0;
            panel.querySelectorAll('[data-coa-search-row]').forEach(function (row) {
                var match = !q || row.textContent.toLowerCase().indexOf(q) !== -1;
                row.hidden = !match;
                if (match) count++;
            });
            var empty = panel.querySelector('[data-coa-empty]');
            if (empty) empty.hidden = count !== 0;
        });
    }
    view.addEventListener('change', updateView);
    search.addEventListener('input', updateView);
    // Collapse/expand branches.
    document.querySelectorAll('.coa-tgl').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            var key = toggle.getAttribute('data-parent');
            var open = toggle.textContent.trim() === '▾';
            toggle.textContent = open ? '▸' : '▾';
            branches[key].dataset.collapsed = open ? 'true' : 'false';
            updateView();
        });
    });
    updateView();
});
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
