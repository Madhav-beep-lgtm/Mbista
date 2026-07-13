<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/voucher_import.php';

require_staff_or_admin();
require_company_context();
$repairErrors = accounting_module_repair_database();

$company = current_company();
$fiscalYear = current_fiscal_year();
if (!$company || !$fiscalYear) {
    flash('error', 'Company and fiscal year context required.');
    redirect('admin/accounting.php');
}

$companyId = (int) $company['id'];
$fiscalYearId = (int) $fiscalYear['id'];
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$currency = site_currency_symbol();
$hasVoucherApprovals = column_exists('vouchers', 'approval_state');
$hasFormMeta = column_exists('vouchers', 'priority');
$hasXlsx = class_exists('ZipArchive');
$canCreate = user_can('create');

$importDir = __DIR__ . '/../uploads/voucher-imports';

function voucher_import_prepare_dir(string $importDir): void
{
    if (!is_dir($importDir)) {
        mkdir($importDir, 0755, true);
    }
    $htaccess = $importDir . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }
    foreach (glob($importDir . '/*.{csv,xlsx}', GLOB_BRACE) ?: [] as $stale) {
        if (filemtime($stale) < time() - 6 * 3600) {
            @unlink($stale);
        }
    }
}

function voucher_import_stored_file(string $importDir, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
        return null;
    }
    foreach (['xlsx', 'csv'] as $extension) {
        $path = $importDir . '/' . $token . '.' . $extension;
        if (is_file($path)) {
            return ['path' => $path, 'extension' => $extension];
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// Template downloads.
// ---------------------------------------------------------------------------
$template = (string) ($_GET['template'] ?? '');
if ($template !== '') {
    if ($template === 'xlsx' && $hasXlsx) {
        $bytes = voucher_import_template_xlsx();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="voucher-import-template.xlsx"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }
    if ($template === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="voucher-import-template.csv"');
        echo voucher_import_template_csv();
        exit;
    }
    if ($template === 'ledgers') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="ledger-list-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) $company['name']) . '.csv"');
        echo voucher_import_ledger_list_csv($companyId);
        exit;
    }
    redirect('admin/voucher-import.php');
}

// ---------------------------------------------------------------------------
// POST: receive the sheet, or commit a previewed import.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canCreate) {
        flash('error', 'You do not have permission to create vouchers.');
        redirect('admin/voucher-import.php');
    }

    require_permission('accounting', 'create');

    $stage = (string) ($_POST['stage'] ?? 'upload');

    if ($stage === 'upload') {
        $file = $_FILES['import_file'] ?? null;
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $size = (int) ($file['size'] ?? 0);
        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($errorCode !== UPLOAD_ERR_OK || $size <= 0) {
            flash('error', 'Choose an Excel (.xlsx) or CSV file to upload.');
            redirect('admin/voucher-import.php');
        }
        if ($size > 10 * 1024 * 1024) {
            flash('error', 'The file is larger than 10 MB.');
            redirect('admin/voucher-import.php');
        }
        if (!in_array($extension, ['xlsx', 'csv'], true)) {
            flash('error', $extension === 'xls'
                ? 'Legacy .xls files are not supported — open the file in Excel and save it as .xlsx or .csv.'
                : 'Only .xlsx and .csv files can be imported.');
            redirect('admin/voucher-import.php');
        }
        if ($extension === 'xlsx' && !$hasXlsx) {
            flash('error', 'This server cannot read .xlsx files (PHP zip extension missing). Save the sheet as .csv and upload that instead.');
            redirect('admin/voucher-import.php');
        }
        voucher_import_prepare_dir($importDir);
        $token = bin2hex(random_bytes(20));
        if (!move_uploaded_file((string) $file['tmp_name'], $importDir . '/' . $token . '.' . $extension)) {
            flash('error', 'The uploaded file could not be stored. Try again.');
            redirect('admin/voucher-import.php');
        }
        redirect('admin/voucher-import.php?token=' . $token);
    }

    if ($stage === 'commit') {
        $stored = voucher_import_stored_file($importDir, (string) ($_POST['token'] ?? ''));
        if ($stored === null) {
            flash('error', 'The uploaded sheet has expired. Upload it again.');
            redirect('admin/voucher-import.php');
        }
        try {
            $rows = voucher_import_read_rows($stored['path'], $stored['extension']);
            $parsed = voucher_import_parse($rows, $companyId, $fiscalYearId);
        } catch (Throwable $exception) {
            flash('error', 'Could not read the sheet: ' . $exception->getMessage());
            redirect('admin/voucher-import.php');
        }
        if (isset($parsed['error'])) {
            flash('error', $parsed['error']);
            redirect('admin/voucher-import.php');
        }
        $valid = array_values(array_filter($parsed['vouchers'], static fn (array $v): bool => $v['errors'] === []));
        $invalidCount = count($parsed['vouchers']) - count($valid);
        if ($valid === []) {
            flash('error', 'No valid vouchers to import — fix the errors shown in the preview and upload again.');
            redirect('admin/voucher-import.php?token=' . (string) $_POST['token']);
        }
        if ($invalidCount > 0 && (string) ($_POST['skip_invalid'] ?? '') !== '1') {
            flash('error', $invalidCount . ' voucher(s) still have errors. Fix them in the sheet and re-upload, or tick “Skip vouchers with errors”.');
            redirect('admin/voucher-import.php?token=' . (string) $_POST['token']);
        }

        $needsApproval = $hasVoucherApprovals && approvals_enabled() && !user_can('approve');
        $batch = strtoupper(bin2hex(random_bytes(2)));
        $now = date('Y-m-d H:i:s');
        $hasLineMeta = column_exists('voucher_entries', 'cost_centre');
        $imported = 0;

        try {
            db()->beginTransaction();
            foreach ($valid as $sequence => $voucher) {
                $voucherNo = strtoupper(substr((string) $voucher['type'], 0, 2)) . '-' . date('Ymd') . '-' . $batch . '-' . str_pad((string) ($sequence + 1), 3, '0', STR_PAD_LEFT);
                $narration = $voucher['title'] . ($voucher['narration'] !== '' && $voucher['narration'] !== $voucher['title'] ? ' — ' . $voucher['narration'] : '');
                $voucherId = create_voucher_with_entries([
                    'company_id' => $companyId,
                    'fiscal_year_id' => $fiscalYearId,
                    'voucher_no' => $voucherNo,
                    'voucher_type' => $voucher['type'],
                    'source_type' => 'voucher_import',
                    'source_id' => null,
                    'party_id' => $voucher['party_id'],
                    'reference_no' => mb_substr($voucher['key'], 0, 120),
                    'voucher_date' => $voucher['date'],
                    'narration' => $narration,
                    'total_amount' => $voucher['debit_total'],
                    'status' => $needsApproval ? 'draft' : 'posted',
                    'approval_state' => $needsApproval ? 'pending_approval' : 'approved',
                    'submitted_by' => $userId,
                    'approved_by' => $needsApproval ? null : $userId,
                    'approved_at' => $needsApproval ? null : $now,
                    'posted_by' => $needsApproval ? null : $userId,
                    'posted_at' => $needsApproval ? null : $now,
                ], array_map(static fn (array $line): array => [
                    'ledger_id' => $line['ledger_id'],
                    'entry_type' => $line['entry_type'],
                    'amount' => $line['amount'],
                    'memo' => $line['memo'] !== '' ? $line['memo'] : null,
                ], $voucher['lines']));
                if ($voucherId <= 0) {
                    throw new RuntimeException('Voucher ' . $voucher['key'] . ' could not be created.');
                }
                if ($hasFormMeta) {
                    db()->prepare('UPDATE vouchers SET posting_date = :posting_date, department = :department, location = :location, cost_centre = :cost_centre
                        WHERE id = :id AND company_id = :company_id')->execute([
                        'posting_date' => $voucher['date'],
                        'department' => $voucher['department'] !== '' ? mb_substr($voucher['department'], 0, 80) : null,
                        'location' => $voucher['location'] !== '' ? mb_substr($voucher['location'], 0, 80) : null,
                        'cost_centre' => $voucher['cost_centre'] !== '' ? mb_substr($voucher['cost_centre'], 0, 80) : null,
                        'id' => $voucherId, 'company_id' => $companyId,
                    ]);
                }
                if ($hasLineMeta) {
                    $lineStmt = db()->prepare('SELECT id FROM voucher_entries WHERE voucher_id = :voucher_id ORDER BY id ASC');
                    $lineStmt->execute(['voucher_id' => $voucherId]);
                    $lineUpdate = db()->prepare('UPDATE voucher_entries SET cost_centre = :cost_centre, tax_code = :tax_code, line_reference = :line_reference WHERE id = :id');
                    foreach ($lineStmt->fetchAll() as $lineIndex => $lineRow) {
                        $line = $voucher['lines'][$lineIndex] ?? null;
                        if ($line !== null && ($line['cost_centre'] !== '' || $line['tax_code'] !== '' || $line['line_reference'] !== '')) {
                            $lineUpdate->execute([
                                'cost_centre' => $line['cost_centre'] !== '' ? mb_substr($line['cost_centre'], 0, 80) : null,
                                'tax_code' => $line['tax_code'] !== '' ? mb_substr($line['tax_code'], 0, 40) : null,
                                'line_reference' => $line['line_reference'] !== '' ? mb_substr($line['line_reference'], 0, 120) : null,
                                'id' => (int) $lineRow['id'],
                            ]);
                        }
                    }
                }
                log_activity('voucher', $voucherId, 'voucher_imported', VOUCHER_IMPORT_TYPES[$voucher['type']] . ' ' . $voucherNo . ' imported from spreadsheet (sheet ref ' . $voucher['key'] . ').', $userId ?: null);
                $imported++;
            }
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Import failed, nothing was saved: ' . $exception->getMessage());
            redirect('admin/voucher-import.php?token=' . (string) $_POST['token']);
        }
        @unlink($stored['path']);
        security_event('voucher_posted', 'success', $imported . ' voucher(s) imported.', $companyId, $userId);
        flash('success', $imported . ' voucher(s) imported' . ($needsApproval ? ' and submitted for approval' : ' and posted') . ($invalidCount > 0 ? '; ' . $invalidCount . ' with errors were skipped' : '') . '.');
        redirect('admin/accounting.php');
    }

    redirect('admin/voucher-import.php');
}

// ---------------------------------------------------------------------------
// GET: preview a stored sheet, or show the upload form.
// ---------------------------------------------------------------------------
$preview = null;
$previewToken = (string) ($_GET['token'] ?? '');
if ($previewToken !== '') {
    $stored = voucher_import_stored_file($importDir, $previewToken);
    if ($stored === null) {
        flash('error', 'The uploaded sheet has expired. Upload it again.');
        redirect('admin/voucher-import.php');
    }
    try {
        $rows = voucher_import_read_rows($stored['path'], $stored['extension']);
        $preview = voucher_import_parse($rows, $companyId, $fiscalYearId);
    } catch (Throwable $exception) {
        @unlink($stored['path']);
        flash('error', 'Could not read the sheet: ' . $exception->getMessage());
        redirect('admin/voucher-import.php');
    }
    if (isset($preview['error'])) {
        @unlink($stored['path']);
        flash('error', $preview['error']);
        redirect('admin/voucher-import.php');
    }
}

$validVouchers = $preview !== null ? array_filter($preview['vouchers'], static fn (array $v): bool => $v['errors'] === []) : [];
$invalidVouchers = $preview !== null ? array_filter($preview['vouchers'], static fn (array $v): bool => $v['errors'] !== []) : [];
$previewDebitTotal = array_sum(array_map(static fn (array $v): float => $v['debit_total'], $validVouchers));
$previewLineCount = array_sum(array_map(static fn (array $v): int => count($v['lines']), $validVouchers));

$pageTitle = 'Import Vouchers from Excel';
$pageSubtitle = 'Upload a spreadsheet to create hundreds of voucher entries in one click.';
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<?php if ($repairErrors !== []): ?><div class="notice error">Accounting module repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div><?php endif; ?>

<nav class="mbw-tabbar" aria-label="Voucher workspace">
    <a class="mbw-tab" href="<?= e(url('admin/accounting.php')) ?>"><?= icon('journal') ?>Voucher Register</a>
    <a class="mbw-tab" href="<?= e(url('admin/voucher-form.php')) ?>"><?= icon('receipt-voucher') ?>New Voucher</a>
    <a class="mbw-tab is-active" href="<?= e(url('admin/voucher-import.php')) ?>"><?= icon('upload') ?>Import from Excel</a>
</nav>

<style>
.vimp-columns-table td, .vimp-columns-table th, .vimp-rules-table td, .vimp-rules-table th { padding: 8px 10px; text-align: left; vertical-align: top; }
.vimp-preview-table .vimp-errors { color: #b42318; font-size: 0.86rem; margin: 4px 0 0; padding-left: 18px; }
.vimp-preview-table .vimp-warnings { color: #b54708; font-size: 0.86rem; margin: 4px 0 0; padding-left: 18px; }
.vimp-lines summary { cursor: pointer; font-size: 0.86rem; }
.vimp-lines table { margin-top: 6px; }
.vimp-summary { display: flex; flex-wrap: wrap; gap: 18px; align-items: center; }
.vimp-summary strong { font-size: 1.25rem; display: block; }
</style>

<?php if ($preview === null): ?>
<div class="frm-main">
    <section class="mbw-card frm-section">
        <div class="frm-section-head"><span class="mbw-chip is-square tone-blue"><?= icon('upload') ?></span><h2>Upload Voucher Sheet</h2></div>
        <?php if (!$canCreate): ?>
            <div class="notice error">You do not have permission to create vouchers, so importing is disabled.</div>
        <?php else: ?>
        <form method="post" action="<?= e(url('admin/voucher-import.php')) ?>" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="stage" value="upload">
            <div class="frm-grid frm-grid-2">
                <label>Spreadsheet file <em>*</em>
                    <span class="frm-dropzone">
                        <?= icon('documents') ?>
                        <strong>Drag &amp; drop the sheet here <u>or click to choose</u></strong>
                        <small>Excel (.xlsx) or CSV — max 10 MB, up to <?= number_format(VOUCHER_IMPORT_MAX_ROWS) ?> rows</small>
                        <input type="file" name="import_file" accept=".xlsx,.csv" required>
                    </span>
                </label>
                <div>
                    <p><strong>Get started</strong></p>
                    <p>
                        <?php if ($hasXlsx): ?><a class="button soft" href="<?= e(url('admin/voucher-import.php?template=xlsx')) ?>"><?= icon('documents') ?>Download Excel template</a><?php endif; ?>
                        <a class="button soft" href="<?= e(url('admin/voucher-import.php?template=csv')) ?>"><?= icon('documents') ?>Download CSV template</a>
                        <a class="button soft" href="<?= e(url('admin/voucher-import.php?template=ledgers')) ?>"><?= icon('tree') ?>Download ledger list</a>
                    </p>
                    <p class="frm-optional">The ledger list shows every active ledger of <?= e($company['name']) ?> with its code, group, and whether it counts as Bank/Cash — use those codes or exact names in the Ledger column.</p>
                    <p><button type="submit" class="button frm-submit"><?= icon('chevron-right') ?>Upload &amp; Preview</button></p>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </section>

    <section class="mbw-card frm-section">
        <div class="frm-section-head"><span class="mbw-chip is-square tone-purple"><?= icon('layers') ?></span><h2>How the Sheet Works</h2></div>
        <p>Each spreadsheet row is one ledger line. Rows are grouped into vouchers by the <strong>Voucher No</strong> column: put a value on the first line of a voucher and leave it blank on its continuation lines (or repeat the same value on every line). Voucher Type, Date, and Title are read from the first line of each group. Your Voucher No is stored as the reference; the system assigns its own sequential voucher numbers on import.</p>
        <div style="overflow-x:auto">
        <table class="frm-entries vimp-columns-table">
            <thead><tr><th>Column</th><th>Required</th><th>What it takes</th></tr></thead>
            <tbody>
                <tr><td>Voucher No</td><td>Yes (first line of each voucher)</td><td>Any grouping key, e.g. JV-001. Saved as the voucher reference.</td></tr>
                <tr><td>Voucher Type</td><td>Yes</td><td>Journal, Payment, Receipt, Sales, Purchase, Contra, Debit Note, Credit Note (or shorthand JV, PV, RV, SV, CV, DN, CN).</td></tr>
                <tr><td>Date (AD or BS)</td><td>Yes</td><td>YYYY-MM-DD. Years 2064+ are treated as Bikram Sambat and converted, e.g. 2083-03-24. Excel date cells also work.</td></tr>
                <tr><td>Ledger (Code or Name)</td><td>Yes (every line)</td><td>Ledger code or exact ledger name from the Chart of Accounts.</td></tr>
                <tr><td>Debit / Credit</td><td>Yes (one per line)</td><td>Amount in exactly one of the two columns. Commas are fine (1,25,000.50).</td></tr>
                <tr><td>Title</td><td>Recommended</td><td>Voucher title; falls back to the narration or an auto title.</td></tr>
                <tr><td>Narration, Line Description, Reference, Party, Cost Centre</td><td>Optional</td><td>Party must match an existing party code or name. Reference is per line (e.g. cheque no).</td></tr>
            </tbody>
        </table>
        </div>
    </section>

    <section class="mbw-card frm-section">
        <div class="frm-section-head"><span class="mbw-chip is-square tone-teal"><?= icon('tasks') ?></span><h2>Voucher Rules Checked on Import</h2></div>
        <p>Every voucher in the sheet is validated with the same rules as the New Voucher form before anything is saved:</p>
        <ul>
            <li>At least two ledger lines, and total debit must equal total credit.</li>
            <li>Ledgers must exist, belong to <?= e($company['name']) ?>, and be active.</li>
            <li>The date must not fall inside a locked accounting period.</li>
            <li><?= $hasVoucherApprovals && approvals_enabled() && !user_can('approve') ? 'Imported vouchers will be submitted for approval (you cannot self-approve).' : 'Imported vouchers are posted immediately under your approval rights.' ?></li>
        </ul>
        <div style="overflow-x:auto">
        <table class="frm-entries vimp-rules-table">
            <thead><tr><th>Voucher Type</th><th>Bank/Cash Group Requirement</th></tr></thead>
            <tbody>
                <?php foreach (VOUCHER_IMPORT_TYPES as $typeKey => $typeLabel): ?>
                    <tr><td><?= e($typeLabel) ?></td><td><?= e(voucher_import_cash_bank_rule($typeKey)['label']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="frm-optional">“Bank/Cash group ledger” means a ledger created under a Chart of Accounts group flagged as Bank/Cash — the downloadable ledger list marks them.</p>
    </section>
</div>

<?php else: ?>
<div class="frm-main">
    <section class="mbw-card frm-section">
        <div class="frm-section-head"><span class="mbw-chip is-square tone-blue"><?= icon('upload') ?></span><h2>Import Preview</h2></div>
        <div class="vimp-summary">
            <span><strong><?= count($preview['vouchers']) ?></strong>vouchers in sheet</span>
            <span><strong style="color:#067647"><?= count($validVouchers) ?></strong>ready to import</span>
            <span><strong style="color:#b42318"><?= count($invalidVouchers) ?></strong>with errors</span>
            <span><strong><?= $previewLineCount ?></strong>ledger lines</span>
            <span><strong><?= e($currency) ?><?= number_format($previewDebitTotal, 2) ?></strong>total amount (valid)</span>
        </div>
    </section>

    <section class="mbw-card frm-section">
        <div style="overflow-x:auto">
        <table class="frm-entries vimp-preview-table">
            <thead><tr><th>Sheet Ref</th><th>Type</th><th>Date</th><th>Title</th><th class="is-numeric">Debit</th><th class="is-numeric">Credit</th><th>Lines</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($preview['vouchers'] as $voucher): ?>
                <tr>
                    <td><?= e($voucher['key']) ?><?= !empty($voucher['party_label']) ? '<br><small>' . e($voucher['party_label']) . '</small>' : '' ?></td>
                    <td><?= e($voucher['type_label']) ?></td>
                    <td><?= e($voucher['date_display']) ?></td>
                    <td><?= e((string) ($voucher['title'] ?? '')) ?></td>
                    <td class="is-numeric"><?= number_format($voucher['debit_total'], 2) ?></td>
                    <td class="is-numeric"><?= number_format($voucher['credit_total'], 2) ?></td>
                    <td>
                        <details class="vimp-lines"><summary><?= count($voucher['lines']) ?> line(s)</summary>
                            <table class="frm-entries">
                                <thead><tr><th>Row</th><th>Ledger</th><th class="is-numeric">Debit</th><th class="is-numeric">Credit</th><th>Description</th><th>Reference</th></tr></thead>
                                <tbody>
                                <?php foreach ($voucher['lines'] as $line): ?>
                                    <tr>
                                        <td><?= (int) $line['row'] ?></td>
                                        <td><?= e($line['ledger_label']) ?></td>
                                        <td class="is-numeric"><?= $line['entry_type'] === 'debit' ? number_format($line['amount'], 2) : '' ?></td>
                                        <td class="is-numeric"><?= $line['entry_type'] === 'credit' ? number_format($line['amount'], 2) : '' ?></td>
                                        <td><?= e($line['memo']) ?></td>
                                        <td><?= e($line['line_reference']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    </td>
                    <td>
                        <?php if ($voucher['errors'] === []): ?>
                            <span class="mbw-pill tone-green">✓ Ready</span>
                        <?php else: ?>
                            <span class="mbw-pill tone-red"><?= count($voucher['errors']) ?> error(s)</span>
                            <ul class="vimp-errors"><?php foreach ($voucher['errors'] as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
                        <?php endif; ?>
                        <?php if (!empty($voucher['warnings'])): ?>
                            <ul class="vimp-warnings"><?php foreach ($voucher['warnings'] as $warning): ?><li><?= e($warning) ?></li><?php endforeach; ?></ul>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <div class="frm-footer mbw-card">
        <form method="post" action="<?= e(url('admin/voucher-import.php')) ?>" style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;width:100%">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="stage" value="commit">
            <input type="hidden" name="token" value="<?= e($previewToken) ?>">
            <?php if ($invalidVouchers !== []): ?>
                <label style="display:flex;gap:8px;align-items:center;margin:0"><input type="checkbox" name="skip_invalid" value="1" checked> Skip the <?= count($invalidVouchers) ?> voucher(s) with errors</label>
            <?php endif; ?>
            <span style="flex:1"></span>
            <a class="button secondary" href="<?= e(url('admin/voucher-import.php')) ?>">Upload a different sheet</a>
            <?php if ($canCreate && $validVouchers !== []): ?>
                <button type="submit" class="button frm-submit"><?= icon('chevron-right') ?>Import <?= count($validVouchers) ?> Voucher(s)</button>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
