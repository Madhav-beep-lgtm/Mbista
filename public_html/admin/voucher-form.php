<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/voucher_types.php';
require_once __DIR__ . '/../../app/voucher_bills.php';

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
$hasTypeMeta = column_exists('vouchers', 'reference_date');

$voucherTypes = voucher_type_catalog();
$departments = ['Accounts & Finance', 'Administration', 'Operations', 'Consulting', 'Training', 'Sales & Marketing'];
$locations = ['Head Office', 'Branch Office', 'Client Site'];
$costCentres = ['General', 'Accounting Services', 'Advisory', 'Training', 'Administration'];
$paymentTermsOptions = ['Due on receipt', 'Net 7', 'Net 15', 'Net 30', 'Net 45', 'Advance'];

/**
 * The party ledger a trade voucher settles against, creating it under Trade
 * Receivables / Trade Payables if this is the party's first document.
 */
$resolvePartyLedger = static function (int $companyId, int $partyId, string $side): int {
    if ($partyId <= 0) {
        return 0;
    }
    $ledgerId = function_exists('ensure_party_ledger') ? ensure_party_ledger($companyId, $partyId, $side) : 0;
    if ($ledgerId > 0) {
        return $ledgerId;
    }
    // Never merge an unlinked party into a generic balance: each party must
    // retain its independently viewable ledger. The composer reports the error.
    return 0;
};

// ---------------------------------------------------------------------------
// Submit: compose the entries this type implies, then save or post.
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

    $existingVoucher = null;
    if ($editVoucherId > 0) {
        $existingStmt = db()->prepare('SELECT * FROM vouchers WHERE id = :id AND company_id = :company_id LIMIT 1');
        $existingStmt->execute(['id' => $editVoucherId, 'company_id' => $companyId]);
        $existingVoucher = $existingStmt->fetch() ?: null;
        if (!$existingVoucher) {
            flash('error', 'Voucher not found for this company.');
            redirect('admin/accounting.php');
        }
        $blocker = voucher_mutation_blocker($existingVoucher);
        if ($blocker !== null) {
            flash('error', $blocker);
            redirect('admin/accounting.php');
        }
    }

    // The type is fixed once a voucher carries a number from that type's
    // series: a payment renumbered as a journal would leave a hole in one
    // series and a stranger in the other.
    $voucherType = $existingVoucher !== null
        ? (string) $existingVoucher['voucher_type']
        : (string) ($_POST['voucher_type'] ?? 'journal');
    if (!voucher_type_exists($voucherType)) {
        flash('error', 'Select a valid voucher type.');
        redirect('admin/voucher-form.php');
    }
    $spec = voucher_type_spec($voucherType);

    $saveMode = (string) ($_POST['save_mode'] ?? 'submit');
    $isDraft = $saveMode === 'draft';
    $formReturnUrl = $editVoucherId > 0 ? 'admin/voucher-form.php?edit=' . $editVoucherId : voucher_type_url($voucherType);

    $voucherDate = (string) ($_POST['voucher_date'] ?? date('Y-m-d'));
    $postingDate = (string) ($_POST['posting_date'] ?? $voucherDate);
    $narration = trim((string) ($_POST['narration'] ?? ''));
    $partyId = (int) ($_POST['party_id'] ?? 0);
    $priority = in_array((string) ($_POST['priority'] ?? ''), ['low', 'medium', 'high'], true) ? (string) $_POST['priority'] : 'medium';
    $department = in_array((string) ($_POST['department'] ?? ''), $departments, true) ? (string) $_POST['department'] : null;
    $location = in_array((string) ($_POST['location'] ?? ''), $locations, true) ? (string) $_POST['location'] : null;
    $costCentre = in_array((string) ($_POST['cost_centre'] ?? ''), $costCentres, true) ? (string) $_POST['cost_centre'] : null;
    $paymentTerms = in_array((string) ($_POST['payment_terms'] ?? ''), $paymentTermsOptions, true) ? (string) $_POST['payment_terms'] : null;
    $dueDate = voucher_date_or_null((string) ($_POST['due_date'] ?? ''));
    $exchangeRate = max(0.0001, round((float) ($_POST['exchange_rate'] ?? 1), 4));
    $referenceNo = substr(trim((string) ($_POST['reference_no'] ?? '')), 0, 120);
    $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);

    // Anything rejected below comes back typed in, not blank.
    $_SESSION['voucher_retry'] = ['type' => $voucherType, 'input' => $_POST];

    $problems = [];
    if ($narration === '') {
        $problems[] = 'Enter a narration for the voucher register.';
    }
    if (is_period_locked($companyId, $fiscalYearId, $voucherDate !== '' ? $voucherDate : date('Y-m-d'))) {
        $problems[] = 'This transaction date is inside a locked accounting period.';
    }

    $ledgerDirectory = voucher_ledger_directory($companyId);

    // A trade voucher settles against the party's own ledger, against cash, or
    // across several of both at once. The party ledger is resolved here, where
    // the database lives, so the composer stays pure.
    $composeInput = $_POST;
    if ((string) $spec['layout'] === 'trade') {
        $settlementMode = (string) ($_POST['settlement_mode'] ?? 'party');
        if (!in_array($settlementMode, ['party', 'cash', 'split'], true)) {
            $settlementMode = 'party';
        }
        $composeInput['settlement_mode'] = $settlementMode;
        // A split may leave part of the bill standing on the party's account,
        // so the party ledger is resolved for that case too — but only when a
        // line actually asks for it, so a sale taken entirely in cash and QR
        // never opens a receivable nobody wanted.
        $splitWantsParty = $settlementMode === 'split'
            && in_array('party', array_map('strval', (array) ($_POST['settle_ledger'] ?? [])), true);
        if ($settlementMode === 'party' || $splitWantsParty) {
            $partyLedgerId = $resolvePartyLedger($companyId, $partyId, (string) $spec['party_ledger_side']);
            $composeInput['settlement_ledger_id'] = $partyLedgerId;
            $composeInput['settlement_party_ledger_id'] = $partyLedgerId;
            if ($partyLedgerId > 0 && !isset($ledgerDirectory[$partyLedgerId])) {
                // ensure_party_ledger may have just created it.
                $ledgerDirectory = voucher_ledger_directory($companyId);
            }
        }
    }

    if (($spec['layout'] ?? '') === 'trade'
        && (($composeInput['trade_mode'] ?? '') === 'goods' || in_array($voucherType, ['purchase', 'debit_note'], true))) {
        $itemLookup = db()->prepare("SELECT * FROM inventory_items WHERE id = ? AND company_id = ? AND status = 'active'");
        foreach ((array) ($composeInput['value_item'] ?? []) as $index => $itemId) {
            $itemLookup->execute([(int) $itemId, $companyId]);
            $selectedItem = $itemLookup->fetch(PDO::FETCH_ASSOC);
            if (!$selectedItem) {
                $problems[] = 'Line ' . ($index + 1) . ': select an active inventory item belonging to this company.';
                continue;
            }
            $composeInput['value_description'][$index] = (string) $selectedItem['name'];
            if (in_array($voucherType, ['purchase', 'debit_note'], true)) {
                $purchaseLedgerId = inv_accounting_method($companyId) === 'periodic'
                    ? (int) (inv_resolve_mapping($companyId, 'purchases', (int) $selectedItem['id'], (string) ($selectedItem['category'] ?? ''))['id'] ?? 0)
                    : inv_item_stock_ledger_id($companyId, $selectedItem);
                $composeInput['value_ledger'][$index] = $purchaseLedgerId;
                if ($purchaseLedgerId <= 0 || !isset($ledgerDirectory[$purchaseLedgerId])) {
                    $problems[] = 'Configure an active purchase / inventory ledger on item "' . $selectedItem['name'] . '" before saving.';
                }
            }
        }
    }
    $composed = voucher_compose($voucherType, $composeInput, $ledgerDirectory, $isDraft);
    $problems = array_merge($problems, $composed['errors']);
    $entries = $composed['entries'];
    if (in_array($voucherType, ['journal', 'contra', 'payment', 'receipt'], true)) {
        foreach ($entries as $lineIndex => $line) {
            if (!in_array((string) ($line['cost_centre'] ?? ''), $costCentres, true)) {
                $problems[] = 'Select a cost centre for line ' . ($lineIndex + 1) . '.';
            }
        }
    }
    $billResult = voucher_validate_bill_details($entries, $ledgerDirectory, $_POST['bill_details'] ?? []);
    $problems = array_merge($problems, $billResult['errors']);
    if ($billResult['details'] !== [] && !column_exists('vouchers', 'bill_details_json')) {
        $problems[] = 'Run the deployment database upgrade before saving bill-by-bill vouchers.';
    }
    $billDetailsJson = json_encode($billResult['details'], JSON_THROW_ON_ERROR);

    // Stock is checked BEFORE anything is written. A voucher whose ledger
    // entries are in the books and whose goods quietly are not is the one
    // failure this whole feature exists to prevent.
    if (!$isDraft && $problems === []) {
        $problems = array_merge($problems, voucher_stock_preflight($companyId, $voucherType, $entries, $editVoucherId));
    }
    if ($problems === [] && $entries === []) {
        $problems[] = $isDraft
            ? 'There is nothing on this voucher to save yet.'
            : 'This voucher has no lines to post.';
    }
    if ($problems !== []) {
        flash('error', $spec['label'] . ' not saved. ' . implode(' ', $problems));
        redirect($formReturnUrl);
    }
    unset($_SESSION['voucher_retry']);

    $debitTotal = 0.0;
    foreach ($entries as $entry) {
        if ($entry['entry_type'] === 'debit') {
            $debitTotal += (float) $entry['amount'];
        }
    }
    $totalAmount = round(max($debitTotal, (float) $composed['total']), 2);

    // Staff accountants in a client's books never self-post — see accounting.php.
    $staffForcedApproval = $hasVoucherApprovals && staff_accountant_forces_approval();
    $needsApproval = $staffForcedApproval
        || ($hasVoucherApprovals && (approvals_enabled() || client_portal_forces_approval()) && !user_can('approve'));
    $fullNarration = $narration;
    $newStatus = $isDraft ? 'draft' : ($needsApproval ? 'draft' : 'posted');
    $newApprovalState = $isDraft ? 'draft' : ($needsApproval ? 'pending_approval' : 'approved');

    $typeMeta = array_merge([
        'reference_date' => null,
        'instrument_type' => null,
        'instrument_no' => null,
        'instrument_date' => null,
        'return_reason' => null,
    ], $composed['header']);

    try {
        if ($editVoucherId > 0) {
            // === Edit: replace the header and lines, keep voucher_no and the
            // source link so auto-post idempotency (UNIQUE source) still holds.
            $voucherNo = (string) $existingVoucher['voucher_no'];

            // The transaction DATE decides the fiscal year. Re-derive it exactly
            // like create_voucher_with_entries does for new vouchers, so editing a
            // voucher's date refiles it into the correct year (and honours that
            // year's period lock) instead of leaving fiscal_year_id on the old
            // year — which would file the voucher in two years at once (register
            // KPIs sum by fiscal_year_id, date-range reports by voucher_date).
            $editVoucherDate = $voucherDate !== '' ? $voucherDate : date('Y-m-d');
            $editFiscalYearId = $fiscalYearId;
            if (table_exists('fiscal_years')) {
                $dateFiscalYear = fiscal_year_for_date($companyId, $editVoucherDate);
                if (!$dateFiscalYear) {
                    flash('error', 'No fiscal year covers ' . $editVoucherDate . '. Open a fiscal year for that period before saving.');
                    redirect($formReturnUrl);
                }
                $postingBlocker = fiscal_year_posting_blocker($dateFiscalYear, $editVoucherDate);
                if ($postingBlocker !== null) {
                    flash('error', $postingBlocker);
                    redirect($formReturnUrl);
                }
                $editFiscalYearId = (int) $dateFiscalYear['id'];
            }

            db()->beginTransaction();
            $updateSql = 'UPDATE vouchers SET voucher_type = :voucher_type, voucher_date = :voucher_date, fiscal_year_id = :fiscal_year_id, narration = :narration, total_amount = :total_amount, status = :status';
            $updateParams = [
                'voucher_type' => $voucherType,
                'voucher_date' => $editVoucherDate,
                'fiscal_year_id' => $editFiscalYearId,
                'narration' => $fullNarration,
                'total_amount' => $totalAmount,
                'status' => $newStatus,
            ];
            if (column_exists('vouchers', 'party_id')) {
                $updateSql .= ', party_id = :party_id';
                $updateParams['party_id'] = $partyId > 0 ? $partyId : null;
            }
            if (column_exists('vouchers', 'reference_no')) {
                $updateSql .= ', reference_no = :reference_no';
                $updateParams['reference_no'] = $referenceNo !== '' ? $referenceNo : null;
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
            if (column_exists('vouchers', 'bill_details_json')) {
                $updateSql .= ', bill_details_json = :bill_details_json';
                $updateParams['bill_details_json'] = $billDetailsJson;
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
            // voucher_no is UNIQUE per company: two people saving a payment in
            // the same second means one INSERT loses, and takes the next number.
            $voucherId = 0;
            $voucherNo = '';
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $voucherNo = voucher_next_number($companyId, $fiscalYearId, $voucherType, $attempt);
                try {
                    $voucherId = create_voucher_with_entries([
                        'company_id' => $companyId,
                        'fiscal_year_id' => $fiscalYearId,
                        'voucher_no' => $voucherNo,
                        'voucher_type' => $voucherType,
                        'source_type' => 'voucher_form',
                        'bill_details_json' => $billDetailsJson,
                        'source_id' => null,
                        'party_id' => $partyId > 0 ? $partyId : null,
                        'reference_no' => $referenceNo !== '' ? $referenceNo : null,
                        'voucher_date' => $voucherDate !== '' ? $voucherDate : date('Y-m-d'),
                        'narration' => $fullNarration,
                        'total_amount' => $totalAmount,
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
                    break;
                } catch (PDOException $duplicate) {
                    if ((string) $duplicate->getCode() !== '23000' || $attempt === 4) {
                        throw $duplicate;
                    }
                }
            }
        }

        if ($voucherId > 0 && !$isDraft && $staffForcedApproval) {
            mark_voucher_requires_client_approval($voucherId);
        }
        if ($voucherId > 0) {
            $eventAction = $editVoucherId > 0 ? 'voucher_edited' : 'voucher_posted';
            security_event($eventAction, 'success', $spec['label'] . ' #' . $voucherId . ($editVoucherId > 0 ? ' edited.' : ($staffForcedApproval && !$isDraft ? ' submitted for client/admin approval.' : ' posted.')), $companyId, $userId);
        }

        if ($voucherId > 0 && $hasTypeMeta) {
            db()->prepare('UPDATE vouchers SET reference_date = :reference_date, instrument_type = :instrument_type,
                instrument_no = :instrument_no, instrument_date = :instrument_date, return_reason = :return_reason
                WHERE id = :id AND company_id = :company_id')->execute([
                'reference_date' => $typeMeta['reference_date'],
                'instrument_type' => $typeMeta['instrument_type'],
                'instrument_no' => $typeMeta['instrument_no'],
                'instrument_date' => $typeMeta['instrument_date'],
                'return_reason' => $typeMeta['return_reason'],
                'id' => $voucherId,
                'company_id' => $companyId,
            ]);
        }

        if ($voucherId > 0 && $hasFormMeta) {
            db()->prepare('UPDATE vouchers SET priority = :priority, department = :department, location = :location,
                cost_centre = :cost_centre, posting_date = :posting_date, due_date = :due_date,
                payment_terms = :payment_terms, exchange_rate = :exchange_rate
                WHERE id = :id AND company_id = :company_id')->execute([
                'priority' => $priority, 'department' => $department, 'location' => $location,
                'cost_centre' => $costCentre, 'posting_date' => $postingDate ?: null, 'due_date' => $dueDate,
                'payment_terms' => $paymentTerms, 'exchange_rate' => $exchangeRate,
                'id' => $voucherId, 'company_id' => $companyId,
            ]);
            if (column_exists('voucher_entries', 'cost_centre')) {
                // create_voucher_with_entries writes the four columns every
                // posting engine shares; the per-line dimensions this form adds
                // are matched back on by position, in the order they were sent.
                $hasStockColumns = column_exists('voucher_entries', 'item_id');
                $lineStmt = db()->prepare('SELECT id FROM voucher_entries WHERE voucher_id = :voucher_id ORDER BY id ASC');
                $lineStmt->execute(['voucher_id' => $voucherId]);
                $lineSql = 'UPDATE voucher_entries SET cost_centre = :cost_centre, tax_code = :tax_code, line_reference = :line_reference'
                    . ($hasStockColumns ? ', item_id = :item_id, quantity = :quantity' : '')
                    . ' WHERE id = :id';
                $lineUpdate = db()->prepare($lineSql);
                foreach ($lineStmt->fetchAll() as $lineIndex => $lineRow) {
                    $sourceEntry = $entries[$lineIndex] ?? null;
                    if ($sourceEntry === null) {
                        continue;
                    }
                    $lineParams = [
                        'cost_centre' => $sourceEntry['cost_centre'] !== '' ? $sourceEntry['cost_centre'] : null,
                        'tax_code' => $sourceEntry['tax_code'] !== '' ? $sourceEntry['tax_code'] : null,
                        'line_reference' => $sourceEntry['line_reference'] !== '' ? $sourceEntry['line_reference'] : null,
                        'id' => (int) $lineRow['id'],
                    ];
                    if ($hasStockColumns) {
                        $lineParams['item_id'] = ((int) ($sourceEntry['item_id'] ?? 0)) ?: null;
                        $lineParams['quantity'] = (float) ($sourceEntry['quantity'] ?? 0);
                    }
                    $lineUpdate->execute($lineParams);
                }
            }
        }

        if ($voucherId > 0 && column_exists('vouchers', 'warehouse_id')) {
            db()->prepare('UPDATE vouchers SET warehouse_id = :warehouse_id WHERE id = :id AND company_id = :company_id')
                ->execute(['warehouse_id' => $warehouseId > 0 ? $warehouseId : null, 'id' => $voucherId, 'company_id' => $companyId]);
        }

        // The goods follow the entry. Runs after the lines are stored because
        // it reads them back — and after the transaction, because a stock
        // problem must never roll back a voucher that is otherwise sound.
        $stockNotes = [];
        if ($voucherId > 0) {
            $savedStmt = db()->prepare('SELECT * FROM vouchers WHERE id = :id AND company_id = :company_id LIMIT 1');
            $savedStmt->execute(['id' => $voucherId, 'company_id' => $companyId]);
            $savedVoucher = $savedStmt->fetch();
            if ($savedVoucher) {
                $stockNotes = voucher_stock_sync($companyId, (int) $savedVoucher['fiscal_year_id'], $savedVoucher, $userId);
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
            log_activity('voucher', $voucherId, 'voucher_edited', $spec['label'] . ' ' . $voucherNo . ' edited and' . $savedVerb, $userId ?: null);
            flash('success', $spec['label'] . ' ' . $voucherNo . ' updated and' . $savedVerb);
        } else {
            log_activity('voucher', $voucherId, $isDraft ? 'voucher_draft_saved' : 'voucher_form_submitted', $spec['label'] . ' ' . $voucherNo . $savedVerb, $userId ?: null);
            flash('success', $spec['label'] . ' ' . $voucherNo . $savedVerb);
        }
        $advisories = array_merge($composed['warnings'], $stockNotes);
        if ($advisories !== []) {
            flash('info', implode(' ', $advisories));
        }
        redirect('admin/accounting.php');
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $_SESSION['voucher_retry'] = ['type' => $voucherType, 'input' => $_POST];
        flash('error', 'Could not save the voucher: ' . $exception->getMessage());
        redirect($formReturnUrl);
    }
}

// ---------------------------------------------------------------------------
// Edit mode: load an existing voucher (any status) into its own screen.
// ---------------------------------------------------------------------------
$editVoucher = null;
$editEntries = [];
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
}

$retry = $_SESSION['voucher_retry'] ?? null;
unset($_SESSION['voucher_retry']);

$type = 'journal';
if ($editVoucher !== null && voucher_type_exists((string) $editVoucher['voucher_type'])) {
    $type = (string) $editVoucher['voucher_type'];
} elseif (is_array($retry) && voucher_type_exists((string) ($retry['type'] ?? ''))) {
    $type = (string) $retry['type'];
} elseif (voucher_type_exists((string) ($_GET['type'] ?? ''))) {
    $type = (string) $_GET['type'];
}
$spec = voucher_type_spec($type);

$ledgerDirectory = voucher_ledger_directory($companyId);
$optionsAll = voucher_ledgers_for_role($ledgerDirectory, 'any');
$optionsCashBank = voucher_ledgers_for_role($ledgerDirectory, 'cash_bank');
$optionsTax = voucher_ledgers_for_role($ledgerDirectory, 'tax');
// Where a trade voucher's money can actually land: tills, banks, the party
// accounts a balance sits on, and the clearing accounts a wallet or gateway
// pays into before the bank sweeps it.
$optionsSettlement = voucher_ledgers_for_role($ledgerDirectory, 'settlement');
if ($optionsSettlement === []) {
    $optionsSettlement = $optionsAll;
}
$optionsValue = ($spec['layout'] ?? '') === 'trade'
    ? voucher_ledgers_for_role($ledgerDirectory, (string) $spec['value_role'])
    : $optionsAll;
// Better an unfiltered list than an empty one: a company whose chart does not
// use the seeded groups would otherwise see a dropdown with nothing in it.
if ($optionsValue === []) {
    $optionsValue = $optionsAll;
}

// The line ledger list, and which of them belong to a party, so that choosing a
// party on a payment or receipt narrows the lines to that party's own ledgers
// plus the cash and bank accounts the money moves through. Only those two
// groups: a payment settles a party from a bank, and nothing else belongs on it.
//
// Deliberately NOT applied to purchase and sales vouchers, which need a third
// and fourth ledger on the same voucher - VAT recoverable, TDS withheld - and
// would become impossible to enter if the list were cut to two groups.
$vchLedgerCatalog = [];
foreach ($optionsAll as $catalogLedger) {
    $vchLedgerCatalog[] = [
        'id' => (int) $catalogLedger['id'],
        'label' => (string) $catalogLedger['name'] . ' (' . (string) $catalogLedger['code'] . ')',
        'group' => (string) ($catalogLedger['group_name'] ?? '') !== '' ? (string) $catalogLedger['group_name'] : 'Ungrouped',
        'cash_bank' => (int) ($catalogLedger['is_cash_or_bank'] ?? 0) === 1 ? 1 : 0,
    ];
}
// A party's own ledgers: what they owe or are owed, and any advance held either
// way. All four are read because a party set up as "both" has a receivable and a
// payable, and paying an advance is not the same ledger as settling a bill.
$vchPartyLedgers = [];
if (table_exists('accounting_parties')) {
    $partyLedgerCols = ['ledger_id', 'payable_ledger_id'];
    foreach (['advance_ledger_id', 'supplier_advance_ledger_id'] as $optionalCol) {
        if (column_exists('accounting_parties', $optionalCol)) {
            $partyLedgerCols[] = $optionalCol;
        }
    }
    $plStmt = db()->prepare('SELECT id, ' . implode(', ', $partyLedgerCols) . " FROM accounting_parties WHERE company_id = :cid AND status = 'active'");
    $plStmt->execute(['cid' => $companyId]);
    foreach ($plStmt->fetchAll(PDO::FETCH_ASSOC) as $plRow) {
        $ownIds = [];
        foreach ($partyLedgerCols as $col) {
            if ((int) ($plRow[$col] ?? 0) > 0) {
                $ownIds[] = (int) $plRow[$col];
            }
        }
        if ($ownIds !== []) {
            $vchPartyLedgers[(int) $plRow['id']] = array_values(array_unique($ownIds));
        }
    }
}

// Stock items, offered only on the four types that can actually move goods and
// only when this company keeps any. Each carries the ledger its value belongs
// in, so choosing an item on a purchase fills the line's ledger too.
$itemOptions = [];
$warehouseOptions = [];
$stockMovementType = voucher_stock_movement_type($spec['key']);
$stockDirection = $stockMovementType !== null ? voucher_stock_direction($stockMovementType) : '';
if ($stockMovementType !== null && voucher_stock_ready()) {
    $itemStmt = db()->prepare("SELECT id, sku, name, unit, sales_rate, purchase_rate, item_type, category, ledger_id
        FROM inventory_items WHERE company_id = :company_id AND status = 'active' ORDER BY name ASC");
    $itemStmt->execute(['company_id' => $companyId]);
    foreach ($itemStmt->fetchAll() as $item) {
        $item['stock_ledger_id'] = inv_item_stock_ledger_id($companyId, $item);
        if (in_array($spec['key'], ['purchase', 'debit_note'], true) && inv_accounting_method($companyId) === 'periodic') {
            $item['stock_ledger_id'] = (int) (inv_resolve_mapping($companyId, 'purchases', (int) $item['id'], (string) ($item['category'] ?? ''))['id'] ?? 0);
        }
        $itemOptions[] = $item;
    }
    if ($itemOptions !== [] && table_exists('warehouses')) {
        $warehouseStmt = db()->prepare('SELECT id, name FROM warehouses WHERE company_id = :company_id AND is_active = 1 ORDER BY name ASC');
        $warehouseStmt->execute(['company_id' => $companyId]);
        $warehouseOptions = $warehouseStmt->fetchAll();
    }
}

// Parties, narrowed to the side this type deals with, each carrying the ledger
// their balance already sits in (0 when they have never been billed).
$partyOptions = [];
if (table_exists('accounting_parties')) {
    $partyKind = (string) ($spec['party_kind'] ?? '');
    $partySql = "SELECT id, code, name, party_type, ledger_id" . (column_exists('accounting_parties', 'payable_ledger_id') ? ', payable_ledger_id' : '')
        . " FROM accounting_parties WHERE company_id = :company_id AND status = 'active'";
    if ($partyKind === 'customer') {
        $partySql .= " AND party_type IN ('customer', 'both')";
    } elseif ($partyKind === 'supplier') {
        $partySql .= " AND party_type IN ('supplier', 'both')";
    }
    $partySql .= ' ORDER BY name ASC';
    $partyStmt = db()->prepare($partySql);
    $partyStmt->execute(['company_id' => $companyId]);
    $wantsPayable = ($spec['party_ledger_side'] ?? '') === 'payable' || $partyKind === 'supplier';
    foreach ($partyStmt->fetchAll() as $party) {
        $party['side_ledger_id'] = $wantsPayable
            ? (int) ($party['payable_ledger_id'] ?? 0)
            : (int) ($party['ledger_id'] ?? 0);
        $partyOptions[] = $party;
    }
}

// The bills each party has on record, so a payment can say WHICH bill it
// settles. Without it a payment lands against the party as a whole and the
// only way to tell which invoice was paid is to read narrations and guess -
// bill-wise tracing is impossible after the fact.
//
// A bill is any voucher that is against a party and carries a supplier's
// reference: a fixed-asset acquisition quoting its purchase_ref, an inventory
// purchase quoting the supplier's invoice number, a purchase voucher typed by
// hand. Payments, receipts and contras are excluded - they settle bills, they
// are not bills.
$partyBills = [];
if (table_exists('vouchers') && column_exists('vouchers', 'reference_no') && column_exists('vouchers', 'party_id')) {
    $billStmt = db()->prepare(
        "SELECT party_id, reference_no, voucher_no, voucher_date, total_amount, status, voucher_type
         FROM vouchers
         WHERE company_id = :cid
           AND party_id IS NOT NULL
           AND reference_no IS NOT NULL AND reference_no <> ''
           AND voucher_type NOT IN ('payment', 'receipt', 'contra')
           AND status <> 'cancelled'
         ORDER BY voucher_date DESC, id DESC
         LIMIT 400"
    );
    $billStmt->execute(['cid' => $companyId]);
    $billRows = $billStmt->fetchAll(PDO::FETCH_ASSOC);

    // How often each bill has already been named on a payment or receipt. Not a
    // settled/outstanding figure - that needs allocation this form does not do -
    // but enough to stop the same bill being paid twice by accident.
    $settled = [];
    if ($billRows !== []) {
        $paidStmt = db()->prepare(
            "SELECT party_id, reference_no, COUNT(*) AS times, COALESCE(SUM(total_amount), 0) AS paid
             FROM vouchers
             WHERE company_id = :cid AND party_id IS NOT NULL
               AND reference_no IS NOT NULL AND reference_no <> ''
               AND voucher_type IN ('payment', 'receipt')
               AND status = 'posted'
             GROUP BY party_id, reference_no"
        );
        $paidStmt->execute(['cid' => $companyId]);
        foreach ($paidStmt->fetchAll(PDO::FETCH_ASSOC) as $paid) {
            $settled[(int) $paid['party_id'] . '|' . (string) $paid['reference_no']] = $paid;
        }
    }
    foreach ($billRows as $bill) {
        $key = (int) $bill['party_id'] . '|' . (string) $bill['reference_no'];
        $against = $settled[$key] ?? null;
        // The type is on the label because a party marked "both" has bills on
        // each side, and a sales invoice must not be mistaken for something to
        // pay.
        $label = (string) $bill['reference_no']
            . ' — ' . (string) ($bill['voucher_date'] ?? '')
            . ' — ' . number_format((float) $bill['total_amount'], 2)
            . ' ' . str_replace('_', ' ', (string) $bill['voucher_type'])
            . ((string) $bill['status'] !== 'posted' ? ' (' . (string) $bill['status'] . ')' : '')
            . ($against ? ' — already paid against ' . (int) $against['times'] . 'x (' . number_format((float) $against['paid'], 2) . ')' : '');
        $partyBills[(int) $bill['party_id']][] = [
            'ref' => (string) $bill['reference_no'],
            'label' => $label,
        ];
    }
}

// ---------------------------------------------------------------------------
// What the screen opens with: a rejected submission, an existing voucher, or
// this type's own sensible defaults.
// ---------------------------------------------------------------------------
$fyStartBound = (string) ($fiscalYear['start_date'] ?? '');
$fyEndBound = (string) ($fiscalYear['end_date'] ?? '');
$defaultEntryDate = date('Y-m-d');
if ($fyStartBound !== '' && $fyEndBound !== '' && ($defaultEntryDate < $fyStartBound || $defaultEntryDate > $fyEndBound)) {
    // Today falls outside the selected year — propose its last day rather than
    // silently posting into a different year.
    $defaultEntryDate = $fyEndBound;
}

$editNarration = '';
$decomposeFailed = false;

if (is_array($retry) && (string) ($retry['type'] ?? '') === $type) {
    $prefill = voucher_prefill_from_input($type, (array) ($retry['input'] ?? []));
    $editNarration = (string) $prefill['narration'];
} elseif ($editVoucher !== null) {
    $prefill = voucher_decompose($type, $editVoucher, $editEntries, $ledgerDirectory);
    $decomposeFailed = empty($prefill['ok']);
    if ($decomposeFailed) {
        // An auto-posted voucher, or one from before these screens existed,
        // whose lines do not fit this type's shape. It is still editable — as a
        // plain debit/credit grid, which can express anything.
        $prefill = voucher_decompose('journal', $editVoucher, $editEntries, $ledgerDirectory);
        $spec = voucher_type_spec('journal');
    }
    $prefill['party_id'] = (int) ($editVoucher['party_id'] ?? 0);
    $prefill['reference_no'] = (string) ($editVoucher['reference_no'] ?? '');
    $prefill['reference_date'] = (string) ($editVoucher['reference_date'] ?? '');
    $prefill['instrument_type'] = (string) ($editVoucher['instrument_type'] ?? '');
    $prefill['instrument_no'] = (string) ($editVoucher['instrument_no'] ?? '');
    $prefill['instrument_date'] = (string) ($editVoucher['instrument_date'] ?? '');
    $prefill['return_reason'] = (string) ($editVoucher['return_reason'] ?? '');
    $prefill['voucher_date'] = (string) ($editVoucher['voucher_date'] ?? $defaultEntryDate);
    $prefill['posting_date'] = (string) ($editVoucher['posting_date'] ?? $editVoucher['voucher_date'] ?? $defaultEntryDate);
    $prefill['due_date'] = (string) ($editVoucher['due_date'] ?? '');
    $prefill['priority'] = (string) ($editVoucher['priority'] ?? 'medium');
    $prefill['department'] = (string) ($editVoucher['department'] ?? '');
    $prefill['location'] = (string) ($editVoucher['location'] ?? '');
    $prefill['cost_centre'] = (string) ($editVoucher['cost_centre'] ?? '');
    $prefill['payment_terms'] = (string) ($editVoucher['payment_terms'] ?? '');
    $prefill['warehouse_id'] = (int) ($editVoucher['warehouse_id'] ?? 0);

    $editNarration = (string) ($editVoucher['narration'] ?? '');
} else {
    $prefill = [
        'voucher_date' => $defaultEntryDate,
        'posting_date' => $defaultEntryDate,
        'priority' => 'medium',
        'tax_mode' => 'exclusive',
        'tax_rate' => 13.0,
        'tax_ledger_id' => 0,
        'settlement_mode' => 'party',
        'settlements' => [],
    ];
    $mappedTax = get_mapped_ledger($companyId, 'default_tax_payable');
    if (($spec['value_role'] ?? '') === 'income' && $mappedTax && !empty($ledgerDirectory[(int) $mappedTax['id']]['roles']['tax'])) {
        $prefill['tax_ledger_id'] = (int) $mappedTax['id'];
    }
}

$editSourceType = (string) ($editVoucher['source_type'] ?? '');
$editIsAutoPosted = $editVoucher && $editSourceType !== '' && $editSourceType !== 'voucher_form';
$canApprove = user_can('approve');
$nextNumberPreview = $editVoucher ? (string) $editVoucher['voucher_no'] : voucher_next_number($companyId, $fiscalYearId, $type);

/** Ledger <option> tags, grouped so a long chart stays navigable. */
$renderLedgerOptions = static function (array $ledgers, int $selectedId = 0): string {
    $html = '';
    $currentGroup = null;
    foreach ($ledgers as $ledger) {
        $group = (string) ($ledger['group_name'] ?? '');
        if ($group !== $currentGroup) {
            if ($currentGroup !== null) {
                $html .= '</optgroup>';
            }
            $html .= '<optgroup label="' . e($group !== '' ? $group : 'Ungrouped') . '">';
            $currentGroup = $group;
        }
        $html .= '<option value="' . (int) $ledger['id'] . '"' . ((int) $ledger['id'] === $selectedId ? ' selected' : '') . '>'
            . e((string) $ledger['name']) . ' (' . e((string) $ledger['code']) . ')</option>';
    }
    if ($currentGroup !== null) {
        $html .= '</optgroup>';
    }

    return $html;
};

$pageTitle = $editVoucher ? 'Edit ' . $spec['label'] . ' ' . (string) $editVoucher['voucher_no'] : 'New ' . $spec['label'];
$pageSubtitle = '';
$bodyClass = 'admin-layout accounting-module-page voucher-entry-page voucher-type-' . $spec['key'];
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<?php if ($repairErrors !== []): ?><div class="notice error">Accounting module repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div><?php endif; ?>

<nav class="mbw-tabbar" aria-label="Voucher workspace">
    <a class="mbw-tab" href="<?= e(url('admin/accounting.php')) ?>"><?= icon('journal') ?>Voucher Register</a>
    <a class="mbw-tab" href="<?= e(url('admin/voucher-import.php')) ?>"><?= icon('upload') ?>Import from Excel</a>
    <?php if ($editVoucher && (string) $spec['layout'] === 'trade'): ?>
    <a class="mbw-tab" target="_blank" rel="noopener" href="<?= e(url('admin/voucher-view.php?id=' . (int) $editVoucher['id'] . '&document=1')) ?>"><?= icon('documents') ?>Invoice / note</a>
    <?php endif; ?>
</nav>

<?php if ($editIsAutoPosted): ?>
    <div class="notice">This voucher was auto-posted from <strong><?= e(str_replace('_', ' ', $editSourceType)) ?><?= !empty($editVoucher['source_id']) ? ' #' . (int) $editVoucher['source_id'] : '' ?></strong>. Editing changes only the books — the source document stays as it is.</div>
<?php endif; ?>
<?php if ($decomposeFailed): ?>
    <div class="notice">These lines do not fit the <?= e(voucher_type_label((string) $editVoucher['voucher_type'])) ?> screen — they were posted by another part of the system. They are shown as a plain debit/credit grid, which can express anything.</div>
<?php endif; ?>

<form method="post" action="<?= e(url('admin/voucher-form.php' . ($editVoucher ? '?edit=' . (int) $editVoucher['id'] : ''))) ?>" enctype="multipart/form-data" id="voucher-form" class="voucher-entry-form" data-balanced="0">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="save_mode" id="frm-save-mode" value="submit">
    <input type="hidden" name="context_fiscal_year_id" value="<?= (int) $fiscalYearId ?>">
    <input type="hidden" name="voucher_type" value="<?= e($spec['key']) ?>">
    <?php if ($editVoucher): ?><input type="hidden" name="voucher_id" value="<?= (int) $editVoucher['id'] ?>"><?php endif; ?>

    <div class="frm-main">
        <section class="mbw-card frm-section vch-head-card">
            <div class="frm-grid vch-header-grid<?= $spec['layout'] === 'trade' ? ' vch-trade-header-grid' : '' ?>">
                <?php if ($editVoucher === null): ?>
                    <?php include __DIR__ . '/../../app/views/vouchers/type_strip.php'; ?>
                <?php else: ?>
                    <label>Voucher type
                        <input type="text" value="<?= e((string) $spec['short']) ?>" disabled>
                    </label>
                <?php endif; ?>
                <label>Voucher no.
                    <input type="text" value="<?= e($nextNumberPreview) ?>" disabled title="<?= $editVoucher ? 'A voucher keeps its number for life' : 'The next number in this type\'s series — issued when you save' ?>">
                </label>
                <label><span>Voucher date <em>*</em></span>
                    <input type="date" name="voucher_date" id="frm-date" value="<?= e((string) ($prefill['voucher_date'] ?? $defaultEntryDate)) ?>" <?= $fyStartBound !== '' ? 'min="' . e($fyStartBound) . '" max="' . e($fyEndBound) . '"' : '' ?> required>
                </label>
                <?php if ((string) $spec['layout'] === 'trade'): ?>
        <label><span class="vch-party-label"><em aria-hidden="true">*</em><?= e((string) $spec['party_label']) ?></span>
            <select name="party_id" id="vch-party" class="js-searchable">
                <option value="0">Select <?= e(strtolower((string) $spec['party_label'])) ?></option>
                <?php foreach ($partyOptions as $party): ?>
                    <option value="<?= (int) $party['id'] ?>"
                            data-ledger="<?= (int) $party['side_ledger_id'] ?>"
                            <?= (int) ($prefill['party_id'] ?? 0) === (int) $party['id'] ? 'selected' : '' ?>><?= e((string) $party['name']) ?> (<?= e((string) $party['code']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
                <?php endif; ?>
                <label>Branch
                    <select name="location">
                        <option value="">Not assigned</option>
                        <?php foreach ($locations as $locationOption): ?>
                            <option value="<?= e($locationOption) ?>" <?= (string) ($prefill['location'] ?? '') === $locationOption ? 'selected' : '' ?>><?= e($locationOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ((string) $spec['layout'] === 'trade'):
                    $supportsServices = in_array($spec['key'], ['sales', 'credit_note'], true);
                    $hasExistingValues = !empty($prefill['values']);
                    $hasInventoryValues = count(array_filter($prefill['values'] ?? [], static fn (array $row): bool => (int) ($row['item_id'] ?? 0) > 0)) > 0;
                    $tradeMode = $supportsServices ? (string) ($prefill['trade_mode'] ?? ($hasExistingValues && !$hasInventoryValues ? 'services' : 'goods')) : 'goods';
                ?>
                <label><span><?= $supportsServices ? 'Sales category' : 'Purchase category' ?></span>
                    <select name="trade_mode" id="vch-trade-mode">
                        <option value="goods" <?= $tradeMode === 'goods' ? 'selected' : '' ?>><?= $supportsServices ? 'Goods sales' : 'Goods purchase' ?></option>
                        <?php if ($supportsServices): ?><option value="services" <?= $tradeMode === 'services' ? 'selected' : '' ?>>Service sales</option><?php endif; ?>
                    </select>
                </label>
                <?php endif; ?>
                <?php if ((string) $spec['layout'] === 'tender'): ?>
                    <?php
                    $selectedTenderBill = (string) ($prefill['reference_no'] ?? '');
                    $selectedTenderParty = (int) ($prefill['party_id'] ?? 0);
                    $selectedTenderBillFound = false;
                    ?>
                    <input type="hidden" name="party_id" id="vch-party" value="<?= (int) ($prefill['party_id'] ?? 0) ?>">
                    <label class="vch-against-bill-field">Against bill
                        <select name="reference_no" id="vch-bill">
                            <option value=""></option>
                            <?php foreach ($partyOptions as $billParty): ?>
                                <?php $billsForParty = $partyBills[(int) $billParty['id']] ?? []; ?>
                                <?php if ($billsForParty !== []): ?>
                                    <optgroup label="<?= e((string) $billParty['name']) ?>">
                                        <?php foreach ($billsForParty as $billOption): ?>
                                            <?php
                                            $isSelectedTenderBill = $selectedTenderBill === (string) $billOption['ref']
                                                && ($selectedTenderParty === 0 || $selectedTenderParty === (int) $billParty['id']);
                                            if ($isSelectedTenderBill) {
                                                $selectedTenderBillFound = true;
                                            }
                                            ?>
                                            <option value="<?= e((string) $billOption['ref']) ?>"
                                                    data-party="<?= (int) $billParty['id'] ?>"
                                                    data-ledger="<?= (int) $billParty['side_ledger_id'] ?>"
                                                    <?= $isSelectedTenderBill ? 'selected' : '' ?>><?= e((string) $billOption['label']) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($selectedTenderBill !== '' && !$selectedTenderBillFound): ?>
                                <option value="<?= e($selectedTenderBill) ?>" data-party="<?= $selectedTenderParty ?>" selected><?= e($selectedTenderBill) ?> (on this voucher)</option>
                            <?php endif; ?>
                        </select>
                    </label>
                    <label>Instrument date
                        <input type="date" name="instrument_date" value="<?= e((string) ($prefill['instrument_date'] ?? '')) ?>">
                    </label>
                <?php endif; ?>
                <?php if (in_array((string) $spec['layout'], ['journal', 'transfer'], true)): ?>
                    <input type="hidden" name="reference_no" value="<?= e((string) ($prefill['reference_no'] ?? '')) ?>">
                <?php endif; ?>
                <input type="hidden" name="cost_centre" value="<?= e((string) ($prefill['cost_centre'] ?? '')) ?>">
            </div>
            <?php if ($spec['layout'] === 'trade') include __DIR__ . '/../../app/views/vouchers/layout_trade.php'; ?>
        </section>

        <?php if ($spec['layout'] !== 'trade') include __DIR__ . '/../../app/views/vouchers/layout_' . $spec['layout'] . '.php'; ?>

        <section class="mbw-card frm-section vch-support-card">
            <div class="vch-support-grid">
                <label><span>Narration <em>*</em></span>
                    <textarea name="narration" rows="2" placeholder="Additional description for this voucher" required><?= e($editNarration) ?></textarea>
                </label>
                <div class="vch-attachment-field">
                    <label id="frm-dropzone" for="frm-attachments">Supporting files
                        <input type="file" name="attachments[]" id="frm-attachments" multiple accept=".pdf,.xls,.xlsx,.csv,.jpg,.jpeg,.png">
                    </label>
                    <span id="frm-file-list">No files selected</span>
                </div>
            </div>
        </section>

        <div class="frm-footer mbw-card">
            <a class="button secondary" href="<?= e(url('admin/accounting.php')) ?>">Cancel</a>
            <button type="submit" class="button secondary vch-save-draft" onclick="document.getElementById('frm-save-mode').value='draft'"><?= icon('save') ?>Save draft</button>
            <button type="submit" class="button frm-submit" onclick="document.getElementById('frm-save-mode').value='submit'"><?= icon('chevron-right') ?><?= $editVoucher ? ($canApprove ? 'Save &amp; post changes' : 'Save &amp; submit for approval') : ($canApprove ? 'Post ' . e((string) $spec['short']) : 'Submit for approval') ?></button>
        </div>
    </div>
</form>

<?php include __DIR__ . '/../../app/views/vouchers/grid_script.php'; ?>
<?php include __DIR__ . '/../../app/views/vouchers/bill_dialog.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var attach = document.getElementById('frm-attachments');
    if (attach) {
        attach.addEventListener('change', function () {
            var names = Array.prototype.map.call(attach.files, function (file) { return file.name; });
            document.getElementById('frm-file-list').textContent = names.length ? names.join(', ') : 'No files selected';
        });
    }

    // A draft may be anything. A posting may not, and the server says so too —
    // this only saves the person the round trip.
    document.getElementById('voucher-form').addEventListener('submit', function (event) {
        if (document.getElementById('frm-save-mode').value === 'draft') { return; }
        if (this.getAttribute('data-balanced') !== '1') {
            event.preventDefault();
            alert('This voucher is not complete yet — check the totals shown above. Use "Save as draft" to keep what you have typed.');
        }
    });
});
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
