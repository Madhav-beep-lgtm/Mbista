<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_admin_or_client_books();
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

$formTypes = [
    'journal' => 'Journal Voucher', 'payment' => 'Payment Voucher', 'receipt' => 'Receipt Voucher',
    'sales' => 'Sales Voucher', 'purchase' => 'Purchase Voucher', 'contra' => 'Contra Voucher',
    'debit_note' => 'Debit Note', 'credit_note' => 'Credit Note',
];
$departments = ['Accounts & Finance', 'Administration', 'Operations', 'Consulting', 'Training', 'Sales & Marketing'];
$locations = ['Head Office', 'Branch Office', 'Client Site'];
$costCentres = ['General', 'Accounting Services', 'Advisory', 'Training', 'Administration'];
$paymentTermsOptions = ['Due on receipt', 'Net 7', 'Net 15', 'Net 30', 'Net 45', 'Advance'];
$taxCategories = ['Standard VAT 13%', 'VAT Exempt', 'Zero Rated', 'No Tax'];
$prefillType = isset($formTypes[(string) ($_GET['type'] ?? '')]) ? (string) $_GET['type'] : 'journal';

// ---------------------------------------------------------------------------
// Submit handler: Save as Draft or Submit for Approval / Post.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    // Multi-tab safety: the fiscal year selected when this form was OPENED
    // must still be the one the backend resolves now. If another tab switched
    // years in between, reject instead of posting into the wrong year.
    $formContextFy = (int) ($_POST['context_fiscal_year_id'] ?? 0);
    if ($formContextFy > 0 && $formContextFy !== $fiscalYearId) {
        $staleFy = fiscal_year_by_id($formContextFy);
        flash('error', 'The fiscal-year context changed after this form was opened (' . ($staleFy['label'] ?? '#' . $formContextFy) . ' → ' . ($fiscalYear['label'] ?? '#' . $fiscalYearId) . '), possibly from another browser tab. Nothing was saved — please review the form and submit again.');
        redirect('admin/voucher-form.php');
    }
    $editVoucherId = (int) ($_POST['voucher_id'] ?? 0);
    if ($editVoucherId > 0) {
        if (!user_can('edit')) {
            flash('error', 'You do not have permission to edit vouchers.');
            redirect('admin/accounting.php');
        }
        require_permission('accounting', 'edit');
    } else {
        if (!user_can('create')) {
            flash('error', 'You do not have permission to create vouchers.');
            redirect('admin/voucher-form.php');
        }
        require_permission('accounting', 'create');
    }

    $saveMode = (string) ($_POST['save_mode'] ?? 'submit');
    $voucherType = (string) ($_POST['voucher_type'] ?? 'journal');
    $voucherDate = (string) ($_POST['voucher_date'] ?? date('Y-m-d'));
    $postingDate = (string) ($_POST['posting_date'] ?? $voucherDate);
    $narration = trim((string) ($_POST['narration'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $partyId = (int) ($_POST['party_id'] ?? 0);
    $priority = in_array((string) ($_POST['priority'] ?? ''), ['low', 'medium', 'high'], true) ? (string) $_POST['priority'] : 'medium';
    $department = in_array((string) ($_POST['department'] ?? ''), $departments, true) ? (string) $_POST['department'] : null;
    $location = in_array((string) ($_POST['location'] ?? ''), $locations, true) ? (string) $_POST['location'] : null;
    $costCentre = in_array((string) ($_POST['cost_centre'] ?? ''), $costCentres, true) ? (string) $_POST['cost_centre'] : null;
    $paymentTerms = in_array((string) ($_POST['payment_terms'] ?? ''), $paymentTermsOptions, true) ? (string) $_POST['payment_terms'] : null;
    $taxCategory = in_array((string) ($_POST['tax_category'] ?? ''), $taxCategories, true) ? (string) $_POST['tax_category'] : null;
    $dueDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['due_date'] ?? '')) ? (string) $_POST['due_date'] : null;
    $exchangeRate = max(0.0001, round((float) ($_POST['exchange_rate'] ?? 1), 4));

    $formReturnUrl = $editVoucherId > 0 ? 'admin/voucher-form.php?edit=' . $editVoucherId : 'admin/voucher-form.php?type=' . $voucherType;

    if (!isset($formTypes[$voucherType])) {
        flash('error', 'Select a valid form type.');
        redirect('admin/voucher-form.php');
    }
    if ($title === '') {
        flash('error', 'Title / description is required.');
        redirect($formReturnUrl);
    }
    if (is_period_locked($companyId, $fiscalYearId, $voucherDate !== '' ? $voucherDate : date('Y-m-d'))) {
        flash('error', 'This transaction date is inside a locked accounting period.');
        redirect($formReturnUrl);
    }

    // Entry lines — same validation contract as the classic voucher form.
    $ledgerIds = $_POST['ledger_id'] ?? [];
    $entryTypes = $_POST['entry_type'] ?? [];
    $amounts = $_POST['amount'] ?? [];
    $memos = $_POST['memo'] ?? [];
    $lineCostCentres = $_POST['line_cost_centre'] ?? [];
    $lineTaxes = $_POST['line_tax'] ?? [];
    $lineReferences = $_POST['line_reference'] ?? [];

    $entries = [];
    $debitTotal = 0.0;
    $creditTotal = 0.0;
    $cashBankViolation = false;
    foreach ($ledgerIds as $index => $ledgerIdRaw) {
        $ledgerId = (int) $ledgerIdRaw;
        $entryType = (string) ($entryTypes[$index] ?? '');
        $amount = round((float) ($amounts[$index] ?? 0), 2);
        if ($ledgerId <= 0 || $amount <= 0 || !in_array($entryType, ['debit', 'credit'], true)) {
            continue;
        }
        $ledgerCheck = db()->prepare("SELECT l.id, COALESCE(g.is_cash_or_bank, 0) AS is_cash_or_bank
            FROM ledgers l LEFT JOIN ledger_groups g ON g.id = l.group_id
            WHERE l.id = :id AND l.company_id = :company_id AND l.status = 'active' LIMIT 1");
        $ledgerCheck->execute(['id' => $ledgerId, 'company_id' => $companyId]);
        $ledgerRow = $ledgerCheck->fetch();
        if (!$ledgerRow) {
            continue;
        }
        $isCashOrBank = (int) $ledgerRow['is_cash_or_bank'] === 1;
        $mustBeCashOrBank = $voucherType === 'contra'
            || ($voucherType === 'payment' && $entryType === 'credit')
            || ($voucherType === 'receipt' && $entryType === 'debit');
        if ($mustBeCashOrBank && !$isCashOrBank) {
            $cashBankViolation = true;
        }
        $entries[] = [
            'ledger_id' => $ledgerId,
            'entry_type' => $entryType,
            'amount' => $amount,
            'memo' => trim((string) ($memos[$index] ?? '')),
            'cost_centre' => trim((string) ($lineCostCentres[$index] ?? '')),
            'tax_code' => trim((string) ($lineTaxes[$index] ?? '')),
            'line_reference' => trim((string) ($lineReferences[$index] ?? '')),
        ];
        if ($entryType === 'debit') {
            $debitTotal += $amount;
        } else {
            $creditTotal += $amount;
        }
    }
    if ($cashBankViolation) {
        flash('error', 'Payment, receipt, and contra vouchers can only use ledgers created under a Bank/Cash group.');
        redirect($formReturnUrl);
    }
    $isDraft = $saveMode === 'draft';
    if ($isDraft && $entries === []) {
        flash('error', 'Add at least one ledger line before saving a draft.');
        redirect($formReturnUrl);
    }
    if (!$isDraft && (count($entries) < 2 || round($debitTotal, 2) !== round($creditTotal, 2))) {
        flash('error', 'The voucher needs at least two ledger lines and total debit must equal total credit.');
        redirect($formReturnUrl);
    }

    // Staff accountants in a client's books never self-post — see accounting.php.
    $staffForcedApproval = $hasVoucherApprovals && staff_accountant_forces_approval();
    $needsApproval = $staffForcedApproval
        || ($hasVoucherApprovals && (approvals_enabled() || client_portal_forces_approval()) && !user_can('approve'));
    $fullNarration = $title . ($narration !== '' ? ' — ' . $narration : '');
    $newStatus = $isDraft ? 'draft' : ($needsApproval ? 'draft' : 'posted');
    $newApprovalState = $isDraft ? 'draft' : ($needsApproval ? 'pending_approval' : 'approved');

    try {
        if ($editVoucherId > 0) {
            // === Edit: replace the header and lines, keep voucher_no and the
            // source link so auto-post idempotency (UNIQUE source) still holds.
            $existingStmt = db()->prepare('SELECT * FROM vouchers WHERE id = :id AND company_id = :company_id LIMIT 1');
            $existingStmt->execute(['id' => $editVoucherId, 'company_id' => $companyId]);
            $existingVoucher = $existingStmt->fetch();
            if (!$existingVoucher) {
                flash('error', 'Voucher not found for this company.');
                redirect('admin/accounting.php');
            }
            $blocker = voucher_mutation_blocker($existingVoucher);
            if ($blocker !== null) {
                flash('error', $blocker);
                redirect('admin/accounting.php');
            }
            $voucherNo = (string) $existingVoucher['voucher_no'];

            db()->beginTransaction();
            $updateSql = 'UPDATE vouchers SET voucher_type = :voucher_type, voucher_date = :voucher_date, narration = :narration, total_amount = :total_amount, status = :status';
            $updateParams = [
                'voucher_type' => $voucherType,
                'voucher_date' => $voucherDate !== '' ? $voucherDate : date('Y-m-d'),
                'narration' => $fullNarration,
                'total_amount' => $debitTotal,
                'status' => $newStatus,
            ];
            if (column_exists('vouchers', 'party_id')) {
                $updateSql .= ', party_id = :party_id';
                $updateParams['party_id'] = $partyId > 0 ? $partyId : null;
            }
            if ($hasVoucherApprovals) {
                $updateSql .= ', approval_state = :approval_state, approved_by = :approved_by, approved_at = :approved_at, posted_by = :posted_by, posted_at = :posted_at, rejection_reason = NULL';
                $updateParams['approval_state'] = $newApprovalState;
                $updateParams['approved_by'] = (!$isDraft && !$needsApproval) ? $userId : null;
                $updateParams['approved_at'] = (!$isDraft && !$needsApproval) ? date('Y-m-d H:i:s') : null;
                $updateParams['posted_by'] = (!$isDraft && !$needsApproval) ? $userId : null;
                $updateParams['posted_at'] = (!$isDraft && !$needsApproval) ? date('Y-m-d H:i:s') : null;
            }
            if (column_exists('vouchers', 'requires_client_approval')) {
                $updateSql .= ', requires_client_approval = 0, client_approved_by = NULL, client_approved_at = NULL';
            }
            $updateSql .= ' WHERE id = :id AND company_id = :company_id';
            $updateParams['id'] = $editVoucherId;
            $updateParams['company_id'] = $companyId;
            db()->prepare($updateSql)->execute($updateParams);

            db()->prepare('DELETE FROM voucher_entries WHERE voucher_id = :id')->execute(['id' => $editVoucherId]);
            $entryInsert = db()->prepare('INSERT INTO voucher_entries (voucher_id, ledger_id, entry_type, amount, memo) VALUES (:voucher_id, :ledger_id, :entry_type, :amount, :memo)');
            foreach ($entries as $entry) {
                $entryInsert->execute([
                    'voucher_id' => $editVoucherId,
                    'ledger_id' => $entry['ledger_id'],
                    'entry_type' => $entry['entry_type'],
                    'amount' => $entry['amount'],
                    'memo' => $entry['memo'] !== '' ? $entry['memo'] : null,
                ]);
            }
            db()->commit();
            $voucherId = $editVoucherId;
        } else {
            $voucherNo = strtoupper(substr($voucherType, 0, 2)) . '-' . date('Ymd-His');
            $voucherId = create_voucher_with_entries([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId,
                'voucher_no' => $voucherNo,
                'voucher_type' => $voucherType,
                'source_type' => 'voucher_form',
                'source_id' => null,
                'party_id' => $partyId > 0 ? $partyId : null,
                'reference_no' => null,
                'voucher_date' => $voucherDate !== '' ? $voucherDate : date('Y-m-d'),
                'narration' => $fullNarration,
                'total_amount' => $debitTotal,
                'status' => $newStatus,
                'approval_state' => $newApprovalState,
                'submitted_by' => $userId,
                'approved_by' => (!$isDraft && !$needsApproval) ? $userId : null,
                'approved_at' => (!$isDraft && !$needsApproval) ? date('Y-m-d H:i:s') : null,
                'posted_by' => (!$isDraft && !$needsApproval) ? $userId : null,
                'posted_at' => (!$isDraft && !$needsApproval) ? date('Y-m-d H:i:s') : null,
            ], array_map(static fn (array $entry): array => [
                'ledger_id' => $entry['ledger_id'],
                'entry_type' => $entry['entry_type'],
                'amount' => $entry['amount'],
                'memo' => $entry['memo'],
            ], $entries));
        }

        if ($voucherId > 0 && !$isDraft && $staffForcedApproval) {
            mark_voucher_requires_client_approval($voucherId);
        }
        if ($voucherId > 0) {
            $eventAction = $editVoucherId > 0 ? 'voucher_edited' : 'voucher_posted';
            security_event($eventAction, 'success', 'Voucher #' . $voucherId . ($editVoucherId > 0 ? ' edited via guided form.' : ($staffForcedApproval && !$isDraft ? ' submitted for client/admin approval via guided form.' : ' posted via guided form.')), $companyId, $userId);
        }

        if ($voucherId > 0 && $hasFormMeta) {
            db()->prepare('UPDATE vouchers SET priority = :priority, department = :department, location = :location,
                cost_centre = :cost_centre, posting_date = :posting_date, due_date = :due_date,
                payment_terms = :payment_terms, exchange_rate = :exchange_rate, tax_category = :tax_category
                WHERE id = :id AND company_id = :company_id')->execute([
                'priority' => $priority, 'department' => $department, 'location' => $location,
                'cost_centre' => $costCentre, 'posting_date' => $postingDate ?: null, 'due_date' => $dueDate,
                'payment_terms' => $paymentTerms, 'exchange_rate' => $exchangeRate, 'tax_category' => $taxCategory,
                'id' => $voucherId, 'company_id' => $companyId,
            ]);
            if (column_exists('voucher_entries', 'cost_centre')) {
                $lineStmt = db()->prepare('SELECT id FROM voucher_entries WHERE voucher_id = :voucher_id ORDER BY id ASC');
                $lineStmt->execute(['voucher_id' => $voucherId]);
                $lineUpdate = db()->prepare('UPDATE voucher_entries SET cost_centre = :cost_centre, tax_code = :tax_code, line_reference = :line_reference WHERE id = :id');
                foreach ($lineStmt->fetchAll() as $lineIndex => $lineRow) {
                    $sourceEntry = $entries[$lineIndex] ?? null;
                    if ($sourceEntry !== null) {
                        $lineUpdate->execute([
                            'cost_centre' => $sourceEntry['cost_centre'] !== '' ? $sourceEntry['cost_centre'] : null,
                            'tax_code' => $sourceEntry['tax_code'] !== '' ? $sourceEntry['tax_code'] : null,
                            'line_reference' => $sourceEntry['line_reference'] !== '' ? $sourceEntry['line_reference'] : null,
                            'id' => (int) $lineRow['id'],
                        ]);
                    }
                }
            }
        }

        // Attachments (PDF, Excel, JPG, PNG — max 10 MB each).
        if ($voucherId > 0 && table_exists('voucher_attachments') && !empty($_FILES['attachments']['name'][0])) {
            $uploadDir = __DIR__ . '/../uploads/voucher-attachments';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $allowedExtensions = ['pdf', 'xls', 'xlsx', 'csv', 'jpg', 'jpeg', 'png'];
            $attachStmt = db()->prepare('INSERT INTO voucher_attachments (voucher_id, file_path, original_name, file_size, uploaded_by) VALUES (:voucher_id, :file_path, :original_name, :file_size, :uploaded_by)');
            foreach ((array) $_FILES['attachments']['name'] as $fileIndex => $originalName) {
                $tmpName = $_FILES['attachments']['tmp_name'][$fileIndex] ?? '';
                $size = (int) ($_FILES['attachments']['size'][$fileIndex] ?? 0);
                $errorCode = (int) ($_FILES['attachments']['error'][$fileIndex] ?? UPLOAD_ERR_NO_FILE);
                $extension = strtolower((string) pathinfo((string) $originalName, PATHINFO_EXTENSION));
                if ($errorCode !== UPLOAD_ERR_OK || $size <= 0 || $size > 10 * 1024 * 1024 || !in_array($extension, $allowedExtensions, true)) {
                    continue;
                }
                $storedName = 'voucher-' . $voucherId . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
                if (move_uploaded_file($tmpName, $uploadDir . '/' . $storedName)) {
                    $attachStmt->execute([
                        'voucher_id' => $voucherId,
                        'file_path' => 'uploads/voucher-attachments/' . $storedName,
                        'original_name' => substr((string) $originalName, 0, 255),
                        'file_size' => $size,
                        'uploaded_by' => $userId ?: null,
                    ]);
                }
            }
        }

        $savedVerb = $isDraft ? ' saved as draft.' : ($needsApproval ? ' submitted for approval.' : ' posted.');
        if ($editVoucherId > 0) {
            log_activity('voucher', $voucherId, 'voucher_edited', $formTypes[$voucherType] . ' ' . $voucherNo . ' edited and' . $savedVerb, $userId ?: null);
            flash('success', $formTypes[$voucherType] . ' ' . $voucherNo . ' updated and' . $savedVerb);
        } else {
            log_activity('voucher', $voucherId, $isDraft ? 'voucher_draft_saved' : 'voucher_form_submitted', $formTypes[$voucherType] . ' ' . $voucherNo . $savedVerb, $userId ?: null);
            flash('success', $isDraft
                ? $formTypes[$voucherType] . ' saved as draft (' . $voucherNo . ').'
                : ($needsApproval ? $formTypes[$voucherType] . ' submitted for approval (' . $voucherNo . ').' : $formTypes[$voucherType] . ' posted (' . $voucherNo . ').'));
        }
        redirect('admin/accounting.php');
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', 'Could not save the voucher: ' . $exception->getMessage());
        redirect($formReturnUrl);
    }
}

// ---------------------------------------------------------------------------
// Edit mode: load an existing voucher (any status) into the form.
// ---------------------------------------------------------------------------
$editVoucher = null;
$editEntries = [];
$editTitle = '';
$editNarration = '';
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    if (!user_can('edit')) {
        flash('error', 'You do not have permission to edit vouchers.');
        redirect('admin/accounting.php');
    }
    $editStmt = db()->prepare('SELECT * FROM vouchers WHERE id = :id AND company_id = :company_id LIMIT 1');
    $editStmt->execute(['id' => $editId, 'company_id' => $companyId]);
    $editVoucher = $editStmt->fetch() ?: null;
    if (!$editVoucher) {
        flash('error', 'Voucher not found for this company.');
        redirect('admin/accounting.php');
    }
    $blocker = voucher_mutation_blocker($editVoucher);
    if ($blocker !== null) {
        flash('error', $blocker);
        redirect('admin/accounting.php');
    }
    $editEntriesStmt = db()->prepare('SELECT * FROM voucher_entries WHERE voucher_id = :id ORDER BY id ASC');
    $editEntriesStmt->execute(['id' => $editId]);
    $editEntries = $editEntriesStmt->fetchAll();

    $prefillType = isset($formTypes[(string) $editVoucher['voucher_type']]) ? (string) $editVoucher['voucher_type'] : $prefillType;
    // The guided form stores "Title — narration" in one column; split it back.
    $editTitle = (string) ($editVoucher['narration'] ?? '');
    $splitAt = strpos($editTitle, ' — ');
    if ($splitAt !== false) {
        $editNarration = substr($editTitle, $splitAt + strlen(' — '));
        $editTitle = substr($editTitle, 0, $splitAt);
    }
}
$editSourceType = (string) ($editVoucher['source_type'] ?? '');
$editIsAutoPosted = $editVoucher && $editSourceType !== '' && $editSourceType !== 'voucher_form';

// ---------------------------------------------------------------------------
// Form data.
// ---------------------------------------------------------------------------
$ledgerStmt = db()->prepare("SELECT l.id, l.code, l.name, COALESCE(g.is_cash_or_bank, 0) AS is_cash_or_bank, g.master_key
    FROM ledgers l LEFT JOIN ledger_groups g ON g.id = l.group_id
    WHERE l.company_id = :company_id AND l.status = 'active' ORDER BY l.name ASC");
$ledgerStmt->execute(['company_id' => $companyId]);
$ledgers = $ledgerStmt->fetchAll();

$parties = [];
if (table_exists('accounting_parties')) {
    $partyStmt = db()->prepare("SELECT id, code, name, party_type FROM accounting_parties WHERE company_id = :company_id AND status = 'active' ORDER BY name ASC");
    $partyStmt->execute(['company_id' => $companyId]);
    $parties = $partyStmt->fetchAll();
}
$fiscalYears = fiscal_years_for_company($companyId, false);

// Accounting periods = months of the current fiscal year.
$periods = [];
$periodCursor = new DateTimeImmutable(date('Y-m-01', strtotime((string) $fiscalYear['start_date'])));
$periodEnd = new DateTimeImmutable(date('Y-m-01', strtotime((string) $fiscalYear['end_date'])));
while ($periodCursor <= $periodEnd && count($periods) < 18) {
    $periods[] = $periodCursor->format('M Y');
    $periodCursor = $periodCursor->modify('+1 month');
}
$currentPeriod = date('M Y');
$canApprove = user_can('approve');

$pageTitle = $editVoucher ? 'Edit Voucher ' . (string) $editVoucher['voucher_no'] : 'New Voucher';
$pageSubtitle = $editVoucher ? 'Editing replaces the voucher\'s lines and re-applies the posting/approval rules.' : 'Reusable form template for all modules across the ERP system.';
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<?php if ($repairErrors !== []): ?><div class="notice error">Accounting module repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div><?php endif; ?>

<nav class="mbw-tabbar" aria-label="Voucher workspace">
    <a class="mbw-tab" href="<?= e(url('admin/accounting.php')) ?>"><?= icon('journal') ?>Voucher Register</a>
    <a class="mbw-tab is-active" href="<?= e(url('admin/voucher-form.php')) ?>"><?= icon('receipt-voucher') ?><?= $editVoucher ? 'Edit Voucher' : 'New Voucher' ?></a>
    <a class="mbw-tab" href="<?= e(url('admin/voucher-import.php')) ?>"><?= icon('upload') ?>Import from Excel</a>
</nav>

<?php if ($editIsAutoPosted): ?>
    <div class="notice">This voucher was auto-posted from <strong><?= e(str_replace('_', ' ', $editSourceType)) ?><?= !empty($editVoucher['source_id']) ? ' #' . (int) $editVoucher['source_id'] : '' ?></strong>. Editing changes only the books — the source document stays as it is.</div>
<?php endif; ?>

<?php
// Default the entry date INSIDE the selected fiscal year: when today falls
// outside it, the year-end date is proposed instead of silently posting
// today's date into a different year. The engine re-validates on save.
$fyStartBound = (string) ($fiscalYear['start_date'] ?? '');
$fyEndBound = (string) ($fiscalYear['end_date'] ?? '');
$defaultEntryDate = date('Y-m-d');
if ($fyStartBound !== '' && $fyEndBound !== '' && ($defaultEntryDate < $fyStartBound || $defaultEntryDate > $fyEndBound)) {
    $defaultEntryDate = $fyEndBound;
}
?>
<form method="post" action="<?= e(url('admin/voucher-form.php' . ($editVoucher ? '?edit=' . (int) $editVoucher['id'] : ''))) ?>" enctype="multipart/form-data" id="voucher-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="save_mode" id="frm-save-mode" value="submit">
    <input type="hidden" name="context_fiscal_year_id" value="<?= (int) $fiscalYearId ?>">
    <?php if ($editVoucher): ?><input type="hidden" name="voucher_id" value="<?= (int) $editVoucher['id'] ?>"><?php endif; ?>
    <div class="frm-main">
        <section class="mbw-card frm-section" data-step-target="1">
            <div class="frm-section-head"><span class="mbw-chip is-square tone-blue"><?= icon('journal') ?></span><h2>Basic Information</h2></div>
            <div class="frm-grid frm-grid-4">
                <label>Form Type <em>*</em>
                    <select name="voucher_type" id="frm-type" required>
                        <option value="">Select form type</option>
                        <?php foreach ($formTypes as $typeValue => $typeLabel): ?>
                            <option value="<?= e($typeValue) ?>" <?= $typeValue === $prefillType ? 'selected' : '' ?>><?= e($typeLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Reference No.
                    <input type="text" value="<?= $editVoucher ? e((string) $editVoucher['voucher_no']) : 'Auto-generated' ?>" disabled title="<?= $editVoucher ? 'The voucher number never changes on edit' : 'A voucher number is generated automatically on save' ?>">
                </label>
                <label>Title / Description <em>*</em>
                    <input type="text" name="title" id="frm-title" maxlength="180" placeholder="Enter title or description" value="<?= e($editTitle) ?>" required>
                </label>
                <label>Priority
                    <?php $editPriority = (string) ($editVoucher['priority'] ?? 'medium'); ?>
                    <select name="priority">
                        <option value="low" <?= $editPriority === 'low' ? 'selected' : '' ?>>Low</option>
                        <option value="medium" <?= $editPriority === 'medium' || !in_array($editPriority, ['low', 'high'], true) ? 'selected' : '' ?>>● Medium</option>
                        <option value="high" <?= $editPriority === 'high' ? 'selected' : '' ?>>High</option>
                    </select>
                </label>
            </div>
        </section>

        <section class="mbw-card frm-section" data-step-target="2">
            <div class="frm-section-head"><span class="mbw-chip is-square tone-purple"><?= icon('companies') ?></span><h2>Organization Context</h2></div>
            <div class="frm-grid frm-grid-4">
                <label>Company <em>*</em>
                    <select id="frm-company" required>
                        <option value="<?= $companyId ?>" selected><?= e($company['name']) ?></option>
                    </select>
                </label>
                <label>Department
                    <select name="department">
                        <option value="">Select department</option>
                        <?php foreach ($departments as $dept): ?><option <?= (string) ($editVoucher['department'] ?? '') === $dept ? 'selected' : '' ?>><?= e($dept) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Location
                    <select name="location">
                        <option value="">Select location</option>
                        <?php foreach ($locations as $loc): ?><option <?= (string) ($editVoucher['location'] ?? '') === $loc ? 'selected' : '' ?>><?= e($loc) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Cost Centre
                    <select name="cost_centre">
                        <option value="">Select cost centre</option>
                        <?php foreach ($costCentres as $cc): ?><option <?= (string) ($editVoucher['cost_centre'] ?? '') === $cc ? 'selected' : '' ?>><?= e($cc) ?></option><?php endforeach; ?>
                    </select>
                </label>
            </div>
        </section>

        <section class="mbw-card frm-section" data-step-target="2">
            <div class="frm-section-head"><span class="mbw-chip is-square tone-amber"><?= icon('calendar') ?></span><h2>Dates &amp; Period</h2></div>
            <div class="frm-grid frm-grid-4">
                <label>Transaction Date <em>*</em><input type="date" name="voucher_date" id="frm-date" value="<?= e((string) ($editVoucher['voucher_date'] ?? $defaultEntryDate)) ?>" <?= $fyStartBound !== '' ? 'min="' . e($fyStartBound) . '" max="' . e($fyEndBound) . '"' : '' ?> required></label>
                <label>Posting Date <em>*</em><input type="date" name="posting_date" value="<?= e((string) ($editVoucher['posting_date'] ?? $editVoucher['voucher_date'] ?? $defaultEntryDate)) ?>" <?= $fyStartBound !== '' ? 'min="' . e($fyStartBound) . '" max="' . e($fyEndBound) . '"' : '' ?> required></label>
                <label>Fiscal Year <em>*</em>
                    <select disabled title="The fiscal year comes from your current portal context">
                        <option selected><?= e($fiscalYear['label']) ?> (<?= e((string) $fiscalYear['start_date']) ?> – <?= e((string) $fiscalYear['end_date']) ?>)</option>
                    </select>
                </label>
                <label>Accounting Period <em>*</em>
                    <select name="accounting_period">
                        <?php foreach ($periods as $period): ?>
                            <option <?= $period === $currentPeriod ? 'selected' : '' ?>><?= e($period) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </section>

        <section class="mbw-card frm-section" data-step-target="3">
            <div class="frm-section-head"><span class="mbw-chip is-square tone-teal"><?= icon('wallet') ?></span><h2>Financial Details</h2></div>
            <div class="frm-grid frm-grid-4">
                <label>Currency <em>*</em>
                    <select disabled><option selected>NPR - Nepalese Rupee</option></select>
                </label>
                <label>Exchange Rate<input type="number" name="exchange_rate" value="<?= e(number_format((float) ($editVoucher['exchange_rate'] ?? 1), 4, '.', '')) ?>" step="0.0001" min="0.0001"></label>
                <label>Payment Terms
                    <select name="payment_terms">
                        <option value="">Select terms</option>
                        <?php foreach ($paymentTermsOptions as $term): ?><option <?= (string) ($editVoucher['payment_terms'] ?? '') === $term ? 'selected' : '' ?>><?= e($term) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Due Date<input type="date" name="due_date" value="<?= e((string) ($editVoucher['due_date'] ?? '')) ?>"></label>
                <label>Total Amount
                    <input type="text" id="frm-display-total" value="<?= e($currency) ?>0.00" disabled title="Calculated from the ledger lines below">
                </label>
                <label class="frm-span-3">Narration
                    <input type="text" name="narration" maxlength="255" placeholder="Enter narration (optional)" id="frm-narration" value="<?= e($editNarration) ?>">
                </label>
            </div>
        </section>

        <section class="mbw-card frm-section" data-step-target="3">
            <div class="frm-section-head"><span class="mbw-chip is-square tone-green"><?= icon('clients') ?></span><h2>Related Party / Ledger Selection</h2></div>
            <div class="frm-grid frm-grid-4">
                <label>Party
                    <select name="party_id" id="frm-party">
                        <option value="0">Select ledger or party</option>
                        <?php foreach ($parties as $party): ?>
                            <option value="<?= (int) $party['id'] ?>" <?= (int) ($editVoucher['party_id'] ?? 0) === (int) $party['id'] ? 'selected' : '' ?> data-type="<?= e(ucfirst((string) $party['party_type'])) ?>"><?= e($party['name']) ?> (<?= e($party['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Party Type
                    <input type="text" id="frm-party-type" value="" placeholder="Select type" disabled>
                </label>
                <label>Tax Category
                    <select name="tax_category">
                        <option value="">Select tax category</option>
                        <?php foreach ($taxCategories as $cat): ?><option <?= (string) ($editVoucher['tax_category'] ?? '') === $cat ? 'selected' : '' ?>><?= e($cat) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="frm-toggle-wrap">Tax Inclusive
                    <span class="frm-toggle"><input type="checkbox" name="tax_inclusive" value="1" id="frm-tax-inclusive"><i></i></span>
                </label>
            </div>
        </section>

        <section class="mbw-card frm-section" data-step-target="3">
            <div class="frm-section-head">
                <span class="mbw-chip is-square tone-blue"><?= icon('layers') ?></span>
                <h2>Multiple Ledger Entries</h2>
                <span class="frm-optional">Voucher lines — total debit must equal total credit</span>
                <label class="frm-toggle-wrap frm-head-toggle">Enable Multi-ledger
                    <span class="frm-toggle"><input type="checkbox" id="frm-multiledger" checked><i></i></span>
                </label>
            </div>
            <div id="frm-entries-wrap">
                <div style="overflow-x:auto">
                    <table class="frm-entries" id="frm-entries-table">
                        <thead>
                            <tr><th style="width:36px">SN</th><th>Ledger / Party <em>*</em></th><th>Description</th><th>Cost Centre</th><th>Tax</th><th class="is-numeric">Debit (<?= e(trim($currency)) ?>)</th><th class="is-numeric">Credit (<?= e(trim($currency)) ?>)</th><th>Reference</th><th style="width:40px"></th></tr>
                        </thead>
                        <tbody id="frm-entry-rows"></tbody>
                    </table>
                </div>
                <div class="frm-entries-foot">
                    <button type="button" class="button soft" id="frm-add-line">＋ Add Line</button>
                    <div class="frm-entry-totals">
                        <span>Total Debit (<?= e(trim($currency)) ?>) <strong id="frm-total-debit">0.00</strong></span>
                        <span>Total Credit (<?= e(trim($currency)) ?>) <strong id="frm-total-credit">0.00</strong></span>
                        <span class="mbw-pill tone-gray" id="frm-balance-pill">Enter lines</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="mbw-card frm-section" data-step-target="4">
            <div class="frm-section-head"><span class="mbw-chip is-square tone-purple"><?= icon('documents') ?></span><h2>Notes &amp; Attachments</h2></div>
            <div class="frm-grid frm-grid-2">
                <label>Notes<textarea name="notes" rows="4" placeholder="Enter notes or additional information"></textarea></label>
                <label>Attachments
                    <span class="frm-dropzone" id="frm-dropzone">
                        <?= icon('documents') ?>
                        <strong>Drag &amp; drop files here <u>or click to upload</u></strong>
                        <small>PDF, Excel, JPG, PNG (Max. 10MB)</small>
                        <input type="file" name="attachments[]" id="frm-attachments" multiple accept=".pdf,.xls,.xlsx,.csv,.jpg,.jpeg,.png">
                        <span id="frm-file-list"></span>
                    </span>
                </label>
            </div>
        </section>

        <section class="mbw-card frm-section" data-step-target="5">
            <div class="frm-section-head"><span class="mbw-chip is-square tone-teal"><?= icon('admin') ?></span><h2>Approval &amp; Review</h2><span class="frm-optional">(Preview)</span></div>
            <div class="frm-grid frm-grid-4 frm-approvers">
                <div><small>Prepared By</small><strong><?= e($currentUser['name'] ?? 'User') ?></strong><span><?= e(date('m/d/Y h:i A')) ?></span></div>
                <div><small>Review By</small><strong class="frm-muted">Not assigned yet</strong></div>
                <div><small>Approve By</small><strong class="frm-muted"><?= $canApprove ? e($currentUser['name'] ?? 'You') . ' (auto)' : 'Not assigned yet' ?></strong></div>
                <div><small>Post By</small><strong class="frm-muted"><?= $canApprove ? e($currentUser['name'] ?? 'You') . ' (auto)' : 'Not assigned yet' ?></strong></div>
            </div>
        </section>

        <div class="frm-footer mbw-card">
            <button type="submit" class="button secondary" onclick="document.getElementById('frm-save-mode').value='draft'"><?= icon('documents') ?>Save as Draft</button>
            <a class="button secondary" href="<?= e(url('admin/accounting.php')) ?>">Cancel</a>
            <button type="submit" class="button frm-submit" onclick="document.getElementById('frm-save-mode').value='submit'"><?= icon('chevron-right') ?><?= $editVoucher ? ($canApprove ? 'Save & Post Changes' : 'Save & Submit for Approval') : ($canApprove ? 'Post Voucher' : 'Submit for Approval') ?></button>
        </div>
    </div>

</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var currency = <?= json_encode($currency) ?>;
    var ledgerOptions = <?= json_encode(array_map(static fn (array $l): array => ['id' => (int) $l['id'], 'label' => $l['name'] . ' (' . $l['code'] . ')'], $ledgers), JSON_UNESCAPED_SLASHES) ?>;
    var costCentres = <?= json_encode($costCentres) ?>;
    var taxCodes = ['', 'VAT 13%', 'Exempt', 'Zero Rated'];
    var rowsHost = document.getElementById('frm-entry-rows');
    var rowCount = 0;

    function buildSelect(name, options, placeholder) {
        var select = document.createElement('select');
        select.name = name;
        var opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = placeholder;
        select.appendChild(opt0);
        options.forEach(function (item) {
            var opt = document.createElement('option');
            if (typeof item === 'object') { opt.value = item.id; opt.textContent = item.label; }
            else { opt.value = item; opt.textContent = item === '' ? placeholder : item; }
            if (item !== '') { select.appendChild(opt); }
        });
        return select;
    }

    function addRow(defaultType, prefill) {
        rowCount++;
        var tr = document.createElement('tr');
        var tdSn = document.createElement('td');
        tdSn.textContent = rowCount;
        tr.appendChild(tdSn);

        var tdLedger = document.createElement('td');
        var ledgerSelect = buildSelect('ledger_id[]', ledgerOptions, 'Select ledger/party');
        tdLedger.appendChild(ledgerSelect);
        tr.appendChild(tdLedger);

        var tdDesc = document.createElement('td');
        var desc = document.createElement('input');
        desc.type = 'text'; desc.name = 'memo[]'; desc.placeholder = 'Enter description';
        tdDesc.appendChild(desc); tr.appendChild(tdDesc);

        var tdCc = document.createElement('td');
        var ccSelect = buildSelect('line_cost_centre[]', costCentres, 'Select cost centre');
        tdCc.appendChild(ccSelect);
        tr.appendChild(tdCc);

        var tdTax = document.createElement('td');
        var taxSelect = buildSelect('line_tax[]', taxCodes, 'Select tax');
        tdTax.appendChild(taxSelect);
        tr.appendChild(tdTax);

        var typeInput = document.createElement('input');
        typeInput.type = 'hidden'; typeInput.name = 'entry_type[]'; typeInput.value = defaultType || 'debit';
        var amountInput = document.createElement('input');
        amountInput.type = 'hidden'; amountInput.name = 'amount[]'; amountInput.value = '0';

        var tdDr = document.createElement('td'); tdDr.className = 'is-numeric';
        var dr = document.createElement('input');
        dr.type = 'number'; dr.step = '0.01'; dr.min = '0'; dr.placeholder = '0.00'; dr.className = 'frm-num frm-dr';
        tdDr.appendChild(dr); tr.appendChild(tdDr);

        var tdCr = document.createElement('td'); tdCr.className = 'is-numeric';
        var cr = document.createElement('input');
        cr.type = 'number'; cr.step = '0.01'; cr.min = '0'; cr.placeholder = '0.00'; cr.className = 'frm-num frm-cr';
        tdCr.appendChild(cr); tr.appendChild(tdCr);

        var tdRef = document.createElement('td');
        var ref = document.createElement('input');
        ref.type = 'text'; ref.name = 'line_reference[]'; ref.placeholder = 'Enter reference';
        tdRef.appendChild(ref);
        tdRef.appendChild(typeInput);
        tdRef.appendChild(amountInput);
        tr.appendChild(tdRef);

        var tdDel = document.createElement('td');
        var del = document.createElement('button');
        del.type = 'button'; del.className = 'frm-del'; del.setAttribute('aria-label', 'Remove line'); del.innerHTML = '&#128465;';
        del.addEventListener('click', function () { tr.remove(); renumber(); recalc(); });
        tdDel.appendChild(del); tr.appendChild(tdDel);

        function sync() {
            var drVal = parseFloat(dr.value) || 0;
            var crVal = parseFloat(cr.value) || 0;
            if (drVal > 0 && document.activeElement === dr) { cr.value = ''; crVal = 0; }
            if (crVal > 0 && document.activeElement === cr) { dr.value = ''; drVal = 0; }
            typeInput.value = crVal > 0 ? 'credit' : 'debit';
            amountInput.value = String(drVal > 0 ? drVal : crVal);
            recalc();
        }
        dr.addEventListener('input', sync);
        cr.addEventListener('input', sync);

        if (prefill) {
            ledgerSelect.value = String(prefill.ledger_id || '');
            desc.value = prefill.memo || '';
            ccSelect.value = prefill.cost_centre || '';
            taxSelect.value = prefill.tax_code || '';
            ref.value = prefill.line_reference || '';
            var prefillAmount = Number(prefill.amount) || 0;
            if (prefill.entry_type === 'credit') { cr.value = prefillAmount; } else { dr.value = prefillAmount; }
            typeInput.value = prefill.entry_type === 'credit' ? 'credit' : 'debit';
            amountInput.value = String(prefillAmount);
        }
        rowsHost.appendChild(tr);
    }

    function renumber() {
        rowCount = 0;
        rowsHost.querySelectorAll('tr').forEach(function (tr) {
            rowCount++;
            tr.querySelector('td').textContent = rowCount;
        });
    }

    function recalc() {
        var totalDr = 0, totalCr = 0, lineCount = 0;
        rowsHost.querySelectorAll('tr').forEach(function (tr) {
            var drVal = parseFloat((tr.querySelector('.frm-dr') || {}).value) || 0;
            var crVal = parseFloat((tr.querySelector('.frm-cr') || {}).value) || 0;
            totalDr += drVal; totalCr += crVal;
            if ((drVal > 0 || crVal > 0) && (tr.querySelector('select[name="ledger_id[]"]') || {}).value) { lineCount++; }
        });
        document.getElementById('frm-total-debit').textContent = totalDr.toFixed(2);
        document.getElementById('frm-total-credit').textContent = totalCr.toFixed(2);
        var pill = document.getElementById('frm-balance-pill');
        var balanced = totalDr > 0 && Math.abs(totalDr - totalCr) < 0.005;
        pill.textContent = balanced ? '✓ Balanced' : (totalDr === 0 && totalCr === 0 ? 'Enter lines' : 'Not balanced');
        pill.className = 'mbw-pill ' + (balanced ? 'tone-green' : (totalDr === 0 && totalCr === 0 ? 'tone-gray' : 'tone-red'));
        document.getElementById('frm-display-total').value = currency + totalDr.toFixed(2);
    }



    var partySelect = document.getElementById('frm-party');
    if (partySelect) {
        partySelect.addEventListener('change', function () {
            var opt = partySelect.options[partySelect.selectedIndex];
            document.getElementById('frm-party-type').value = opt && opt.getAttribute('data-type') ? opt.getAttribute('data-type') : '';
        });
    }

    var multiToggle = document.getElementById('frm-multiledger');
    multiToggle.addEventListener('change', function () {
        document.getElementById('frm-entries-wrap').style.display = multiToggle.checked ? '' : 'none';
    });

    document.getElementById('frm-add-line').addEventListener('click', function () { addRow('debit'); });

    var attach = document.getElementById('frm-attachments');
    attach.addEventListener('change', function () {
        var names = Array.prototype.map.call(attach.files, function (f) { return f.name; });
        document.getElementById('frm-file-list').textContent = names.length ? names.join(', ') : '';
    });

    document.getElementById('voucher-form').addEventListener('submit', function (event) {
        var mode = document.getElementById('frm-save-mode').value;
        if (mode === 'draft') { return; }
        var balanced = document.getElementById('frm-balance-pill').classList.contains('tone-green');
        if (!balanced) {
            event.preventDefault();
            alert('Total debit must equal total credit before submitting. Use Save as Draft to keep your work.');
        }
    });

    var existingLines = <?= json_encode(array_map(static fn (array $line): array => [
        'ledger_id' => (int) $line['ledger_id'],
        'entry_type' => (string) $line['entry_type'],
        'amount' => (float) $line['amount'],
        'memo' => (string) ($line['memo'] ?? ''),
        'cost_centre' => (string) ($line['cost_centre'] ?? ''),
        'tax_code' => (string) ($line['tax_code'] ?? ''),
        'line_reference' => (string) ($line['line_reference'] ?? ''),
    ], $editEntries), JSON_UNESCAPED_SLASHES) ?>;
    if (existingLines.length) {
        existingLines.forEach(function (line) { addRow(line.entry_type, line); });
    } else {
        addRow('debit');
        addRow('credit');
        addRow('debit');
    }
    recalc();
});
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
