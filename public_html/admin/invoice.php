<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_admin();
require_company_context();

accounting_module_repair_database();

$currentCompany = current_company();
$currentAdmin = current_user();
$adminId = (int) ($currentAdmin['id'] ?? 0);
$invoiceBusinessType = company_accounting_business_type((int) ($currentCompany['id'] ?? 0));
$invoiceBusinessProfile = accounting_business_profile($invoiceBusinessType);
$defaultExciseRate = company_accounting_default_excise_rate((int) ($currentCompany['id'] ?? 0));

$invoiceSchemaReady = table_exists('task_invoices')
    && table_exists('client_tasks')
    && table_exists('invoice_payment_requests')
    && column_exists('task_invoices', 'invoice_category')
    && column_exists('task_invoices', 'total_amount');
if (!$invoiceSchemaReady) {
    $pageTitle = 'Invoice Management';
    $pageSubtitle = 'Create, issue, convert, and collect client invoices.';
    $bodyClass = 'admin-layout';
    include __DIR__ . '/../../app/views/partials/admin_header.php';
    ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Invoice module unavailable</h2></div>
        <p>The database is missing invoice tables or columns. Apply the pending migrations
            (<code>010</code>, <code>018</code>, <code>019</code> — or run
            <code>database/production_upgrade_invoice_module.sql</code> once via phpMyAdmin), then reload this page.</p>
    </section>
    <?php
    include __DIR__ . '/../../app/views/partials/admin_footer.php';
    exit;
}

$action = $_GET['action'] ?? 'list';
$invoiceId = (int) ($_GET['id'] ?? 0);
$message = null;
$error = null;
$allowedInvoiceType = ['stage', 'task', 'inventory', 'manufacturing', 'other', 'termination'];
$allowedInvoiceSourceTypes = $invoiceBusinessProfile['allowed_invoice_sources'];
$defaultInvoiceSourceType = $invoiceBusinessProfile['default_invoice_source'];
$allowedInvoiceStatus = ['draft', 'issued', 'paid', 'cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $postAction = (string) ($_POST['action'] ?? '');
    $postedInvoiceId = (int) ($_POST['invoice_id'] ?? $invoiceId);

    if ($postedInvoiceId > 0) {
        $invoiceId = $postedInvoiceId;
    }

    if ($postAction === 'create_invoice') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $stageId = (int) ($_POST['stage_id'] ?? 0);
        $invoiceSourceType = (string) ($_POST['invoice_source_type'] ?? $defaultInvoiceSourceType);
        if (!in_array($invoiceSourceType, $allowedInvoiceSourceTypes, true)) {
            $invoiceSourceType = $defaultInvoiceSourceType;
        }
        $invoiceType = $invoiceSourceType === 'task' ? (string) ($_POST['invoice_type'] ?? 'stage') : $invoiceSourceType;
        $partyId = (int) ($_POST['party_id'] ?? 0);
        $sourceId = match ($invoiceSourceType) {
            'inventory' => (int) (($_POST['item_id'][0] ?? 0)),
            'manufacturing' => (int) ($_POST['manufacturing_order_id'] ?? 0),
            default => null,
        };
        $amountRaw = trim((string) ($_POST['amount'] ?? '0'));
        $status = (string) ($_POST['status'] ?? 'issued');
        $issuedOn = trim((string) ($_POST['issued_on'] ?? date('Y-m-d')));
        $dueOn = trim((string) ($_POST['due_on'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $vatRate = (float) ($_POST['vat_rate'] ?? default_vat_rate());
        $exciseRate = $invoiceSourceType === 'manufacturing'
            ? round((float) ($_POST['excise_rate'] ?? $defaultExciseRate), 2)
            : 0.00;
        $invoiceCategory = (string) ($_POST['invoice_category'] ?? 'proforma');
        $description = trim((string) ($_POST['description'] ?? ''));

        if (!is_numeric($amountRaw) || !in_array($invoiceType, $allowedInvoiceType, true)) {
            $error = 'Invoice type and valid amount are required.';
        } elseif (!in_array($status, $allowedInvoiceStatus, true)) {
            $error = 'Invalid invoice status selected.';
        } else {
            $task = null;
            $lineItems = [];
            $amount = round((float) $amountRaw, 2);
            $serviceMode = in_array($invoiceSourceType, ['task', 'other'], true);

            if ($serviceMode) {
                if ($invoiceSourceType === 'task') {
                    if ($taskId <= 0) {
                        $error = 'Task is required for task or stage invoice.';
                    } else {
                        $taskStmt = db()->prepare('SELECT id, title, quoted_fee, status FROM client_tasks WHERE id = :id AND company_id = :company_id LIMIT 1');
                        $taskStmt->execute([
                            'id' => $taskId,
                            'company_id' => (int) $currentCompany['id'],
                        ]);
                        $task = $taskStmt->fetch();
                    }
                } else {
                    $taskId = 0;
                    $stageId = 0;
                }
            } else {
                $taskId = 0;
                $stageId = 0;
                $itemIds = $_POST['item_id'] ?? [];
                $descriptions = $_POST['line_description'] ?? [];
                $quantities = $_POST['line_quantity'] ?? [];
                $rates = $_POST['line_rate'] ?? [];
                $lineVatRates = $_POST['line_vat_rate'] ?? [];
                foreach ($descriptions as $index => $lineDescriptionRaw) {
                    $lineDescription = trim((string) $lineDescriptionRaw);
                    $quantity = round((float) ($quantities[$index] ?? 0), 3);
                    $rate = round((float) ($rates[$index] ?? 0), 2);
                    if ($lineDescription === '' && !empty($itemIds[$index])) {
                        $lineDescription = 'Inventory item #' . (int) $itemIds[$index];
                    }
                    if ($lineDescription === '' || $quantity <= 0 || $rate < 0) {
                        continue;
                    }
                    $lineVatRate = round((float) ($lineVatRates[$index] ?? $vatRate), 2);
                    $taxableLine = round($quantity * $rate, 2);
                    $lineExcise = $invoiceSourceType === 'manufacturing' ? round($taxableLine * ($exciseRate / 100), 2) : 0.0;
                    $vatLine = round(($taxableLine + $lineExcise) * ($lineVatRate / 100), 2);
                    $lineItems[] = [
                        'item_id' => (int) ($itemIds[$index] ?? 0) ?: null,
                        'description' => $lineDescription,
                        'quantity' => $quantity,
                        'unit' => 'pcs',
                        'rate' => $rate,
                        'taxable_amount' => $taxableLine,
                        'excise_rate' => $invoiceSourceType === 'manufacturing' ? $exciseRate : 0.0,
                        'excise_amount' => $lineExcise,
                        'vat_rate' => $lineVatRate,
                        'vat_amount' => $vatLine,
                        'total_amount' => round($taxableLine + $lineExcise + $vatLine, 2),
                    ];
                }
                if ($lineItems !== []) {
                    $amount = round(array_sum(array_map(static fn (array $line): float => (float) $line['taxable_amount'], $lineItems)), 2);
                }
            }

            if ($invoiceSourceType === 'task' && !$task) {
                $error = 'Selected task not found for invoicing.';
            } elseif ($amount <= 0) {
                $error = 'Invoice amount must be greater than zero.';
            } elseif ($invoiceSourceType === 'task' && $invoiceType === 'task' && ($task['status'] ?? 'new') !== 'completed') {
                $error = 'Task invoice can be issued only after task completion.';
            } elseif ($invoiceSourceType === 'task' && $invoiceType === 'termination' && ($task['status'] ?? 'new') !== 'terminated') {
                $error = 'Termination/compensation invoice can be issued only for a terminated task.';
            } elseif ($invoiceSourceType !== 'task' && $invoiceType === 'termination') {
                $error = 'Termination invoice applies only to task invoicing.';
            } else {
                if ($invoiceSourceType === 'task' && $invoiceType === 'stage') {
                    if ($stageId <= 0) {
                        $error = 'Stage invoice requires a valid completed stage.';
                    } else {
                        $stageStmt = db()->prepare('SELECT id, task_id, stage_fee, status FROM task_stages WHERE id = :id AND task_id = :task_id AND company_id = :company_id LIMIT 1');
                        $stageStmt->execute([
                            'id' => $stageId,
                            'task_id' => $taskId,
                            'company_id' => (int) $currentCompany['id'],
                        ]);
                        $stage = $stageStmt->fetch();

                        if (!$stage || ($stage['status'] ?? 'pending') !== 'completed') {
                            $error = 'Stage invoice can be issued only for a completed stage.';
                        } else {
                            $stageInvoicedStmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM task_invoices WHERE stage_id = :stage_id AND company_id = :company_id AND status <> :cancelled_status');
                            $stageInvoicedStmt->execute([
                                'stage_id' => $stageId,
                                'company_id' => (int) $currentCompany['id'],
                                'cancelled_status' => 'cancelled',
                            ]);
                            $stageInvoiced = (float) $stageInvoicedStmt->fetchColumn();
                            $stageRemaining = round(((float) $stage['stage_fee']) - $stageInvoiced, 2);
                            if ($amount > $stageRemaining) {
                                $error = 'Invoice amount exceeds remaining amount available for this stage.';
                            }
                        }
                    }
                } else {
                    $stageId = 0;
                }

                if (!$error && $invoiceSourceType === 'task') {
                    $taskInvoicedStmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM task_invoices WHERE task_id = :task_id AND company_id = :company_id AND status <> :cancelled_status');
                    $taskInvoicedStmt->execute([
                        'task_id' => $taskId,
                        'company_id' => (int) $currentCompany['id'],
                        'cancelled_status' => 'cancelled',
                    ]);
                    $taskInvoicedTotal = (float) $taskInvoicedStmt->fetchColumn();
                    $taskRemaining = round(((float) ($task['quoted_fee'] ?? 0)) - $taskInvoicedTotal, 2);

                    if ($amount > $taskRemaining) {
                        $error = 'Invoice amount exceeds remaining quoted fee for this task.';
                    }
                }

                // A Manufacturing Output invoice must reference a completed order
                // of this company — previously the id was stored unchecked.
                if (!$error && $invoiceSourceType === 'manufacturing' && $sourceId > 0 && table_exists('manufacturing_orders')) {
                    $moStmt = db()->prepare('SELECT status FROM manufacturing_orders WHERE id = :id AND company_id = :company_id LIMIT 1');
                    $moStmt->execute(['id' => $sourceId, 'company_id' => (int) $currentCompany['id']]);
                    $moStatus = $moStmt->fetchColumn();
                    if ($moStatus === false) {
                        $error = 'Selected manufacturing order not found for this company.';
                    } elseif ((string) $moStatus !== 'completed') {
                        $error = 'Manufacturing order must be completed before its output can be invoiced.';
                    }
                }

                // Goods invoices that will issue stock now are validated against
                // on-hand quantities up front — previously the invoice and its
                // full revenue voucher posted even when the stock leg failed.
                if (!$error && in_array($invoiceSourceType, ['inventory', 'manufacturing'], true)
                    && in_array($status, ['issued', 'paid'], true) && table_exists('inventory_transactions')) {
                    $requestedByItem = [];
                    foreach ($lineItems as $line) {
                        if (!empty($line['item_id'])) {
                            $requestedByItem[(int) $line['item_id']] = ($requestedByItem[(int) $line['item_id']] ?? 0) + (float) $line['quantity'];
                        }
                    }
                    if ($requestedByItem !== []) {
                        $onHandStmt = db()->prepare('
                            SELECT i.sku, i.name,
                                   i.opening_qty + COALESCE((SELECT SUM(t.qty_in - t.qty_out) FROM inventory_transactions t WHERE t.item_id = i.id), 0) AS on_hand
                            FROM inventory_items i WHERE i.id = :id AND i.company_id = :company_id LIMIT 1
                        ');
                        $shortages = [];
                        foreach ($requestedByItem as $requestedItemId => $requestedQty) {
                            $onHandStmt->execute(['id' => $requestedItemId, 'company_id' => (int) $currentCompany['id']]);
                            $onHandRow = $onHandStmt->fetch();
                            if (!$onHandRow) {
                                $shortages[] = 'item #' . $requestedItemId . ' not found';
                                continue;
                            }
                            if ((float) $onHandRow['on_hand'] + 0.0005 < $requestedQty) {
                                $shortages[] = $onHandRow['sku'] . ' (' . $onHandRow['name'] . '): need ' . rtrim(rtrim(number_format($requestedQty, 3), '0'), '.') . ', on hand ' . rtrim(rtrim(number_format((float) $onHandRow['on_hand'], 3), '0'), '.');
                            }
                        }
                        if ($shortages !== []) {
                            $error = 'Insufficient stock — ' . implode('; ', $shortages) . '. Reduce quantities or receive stock first.';
                        }
                    }
                }

                if (!$error) {
                    if ($lineItems === []) {
                        $lineExcise = $invoiceSourceType === 'manufacturing' ? round($amount * ($exciseRate / 100), 2) : 0.0;
                        $vatAmount = round(($amount + $lineExcise) * ($vatRate / 100), 2);
                        $lineItems[] = [
                            'item_id' => null,
                            'description' => $description !== '' ? $description : (($task['title'] ?? '') !== '' ? (string) $task['title'] : 'Manual invoice line'),
                            'quantity' => 1,
                            'unit' => 'job',
                            'rate' => $amount,
                            'taxable_amount' => $amount,
                            'excise_rate' => $invoiceSourceType === 'manufacturing' ? $exciseRate : 0.0,
                            'excise_amount' => $lineExcise,
                            'vat_rate' => $vatRate,
                            'vat_amount' => $vatAmount,
                            'total_amount' => round($amount + $lineExcise + $vatAmount, 2),
                        ];
                    }
                    $amount = round(array_sum(array_map(static fn (array $line): float => (float) $line['taxable_amount'], $lineItems)), 2);
                    $exciseAmount = round(array_sum(array_map(static fn (array $line): float => (float) ($line['excise_amount'] ?? 0), $lineItems)), 2);
                    $vatAmount = round(array_sum(array_map(static fn (array $line): float => (float) $line['vat_amount'], $lineItems)), 2);
                    $totalAmount = round($amount + $exciseAmount + $vatAmount, 2);

                    $prefix = match ($invoiceSourceType) {
                        'inventory' => 'INV-STK',
                        'manufacturing' => 'INV-MFG',
                        'other' => 'INV-OTH',
                        default => 'INV-TSK',
                    };
                    $invoiceNo = $prefix . '-' . date('Ymd') . '-' . strtoupper(substr((string) bin2hex(random_bytes(4)), 0, 6));

                    $insertStmt = db()->prepare('
                        INSERT INTO task_invoices (
                            company_id, task_id, stage_id, invoice_no, invoice_type, invoice_source_type,
                            source_id, party_id, description, amount, status, issued_on, due_on, notes, issued_by
                        ) VALUES (
                            :company_id, :task_id, :stage_id, :invoice_no, :invoice_type, :invoice_source_type,
                            :source_id, :party_id, :description, :amount, :status, :issued_on, :due_on, :notes, :issued_by
                        )
                    ');
                    $insertStmt->execute([
                        'company_id' => (int) $currentCompany['id'],
                        'task_id' => $taskId > 0 ? $taskId : null,
                        'stage_id' => $stageId > 0 ? $stageId : null,
                        'invoice_no' => $invoiceNo,
                        'invoice_type' => $invoiceType,
                        'invoice_source_type' => $invoiceSourceType,
                        'source_id' => $sourceId,
                        'party_id' => $partyId > 0 ? $partyId : null,
                        'description' => $description !== '' ? $description : null,
                        'amount' => $amount,
                        'status' => $status,
                        'issued_on' => $issuedOn,
                        'due_on' => $dueOn !== '' ? $dueOn : null,
                        'notes' => $notes !== '' ? $notes : null,
                        'issued_by' => $adminId > 0 ? $adminId : null,
                    ]);

                    $newInvoiceId = (int) db()->lastInsertId();

                    if (table_exists('invoice_line_items')) {
                        $lineStmt = db()->prepare('
                            INSERT INTO invoice_line_items (
                                invoice_id, item_id, source_type, source_id, description, quantity, rate,
                                taxable_amount, vat_rate, vat_amount, total_amount
                            ) VALUES (
                                :invoice_id, :item_id, :source_type, :source_id, :description, :quantity, :rate,
                                :taxable_amount, :vat_rate, :vat_amount, :total_amount
                            )
                        ');
                        foreach ($lineItems as $line) {
                            $lineStmt->execute([
                                'invoice_id' => $newInvoiceId,
                                'item_id' => $line['item_id'],
                                'source_type' => $invoiceSourceType,
                                'source_id' => $sourceId,
                                'description' => $line['description'],
                                'quantity' => $line['quantity'],
                                'rate' => $line['rate'],
                                'taxable_amount' => $line['taxable_amount'],
                                'vat_rate' => $line['vat_rate'],
                                'vat_amount' => $line['vat_amount'],
                                'total_amount' => $line['total_amount'],
                            ]);
                        }
                    }

                    // Stock issue + COGS + NRV release for goods invoices
                    // (inventory AND manufacturing) via the shared idempotent
                    // helper — the same routine also runs on the draft→issued
                    // /paid transition so no path skips the stock leg.
                    $stockPostingNotes = invoice_issue_inventory_stock($newInvoiceId, $adminId);

                    try {
                        $updateVatStmt = db()->prepare('UPDATE task_invoices SET invoice_category = :invoice_category, vat_rate = :vat_rate, vat_amount = :vat_amount, taxable_amount = :taxable_amount, excise_rate = :excise_rate, excise_amount = :excise_amount, total_amount = :total_amount WHERE id = :id AND company_id = :company_id');
                        $updateVatStmt->execute([
                            'invoice_category' => in_array($invoiceCategory, ['proforma', 'tax'], true) ? $invoiceCategory : 'proforma',
                            'vat_rate' => $vatRate,
                            'vat_amount' => $vatAmount,
                            'taxable_amount' => $amount,
                            'excise_rate' => $exciseAmount > 0 ? $exciseRate : 0.0,
                            'excise_amount' => $exciseAmount,
                            'total_amount' => $totalAmount,
                            'id' => $newInvoiceId,
                            'company_id' => (int) $currentCompany['id'],
                        ]);
                    } catch (Exception $e) {
                        // Keep backward compatibility for databases without VAT migration.
                    }

                    log_activity('task_invoice', $newInvoiceId, 'issued', 'Task invoice issued from invoice tab.', $adminId);
                    $mirrorNote = '';
                    if (in_array($status, ['issued', 'paid'], true)) {
                        auto_post_task_invoice_voucher($newInvoiceId, $adminId);
                        // Tell the issuer whether the client-books mirror entry
                        // went out, and if not, exactly why (it used to skip
                        // silently, which read as "unable to post").
                        $mirrorReason = auto_post_client_mirror_invoice($newInvoiceId, $adminId);
                        if ($mirrorReason === null) {
                            $mirrorNote = ' A mirror purchase entry was submitted to the client\'s books for their approval.';
                        } elseif ($partyId > 0) {
                            $mirrorNote = ' No client mirror entry: ' . $mirrorReason . '.';
                        }
                    }
                    if ($status === 'paid') {
                        $requestId = create_payment_request($newInvoiceId, (int) $currentCompany['id'], $adminId, $totalAmount, 'manual', 'Auto-created for paid invoice.');
                        if ($requestId) {
                            db()->prepare('UPDATE invoice_payment_requests SET status = :status, payment_received_on = :payment_received_on, payment_amount = :payment_amount WHERE id = :id')
                                ->execute([
                                    'status' => 'paid',
                                    'payment_received_on' => $issuedOn,
                                    'payment_amount' => $totalAmount,
                                    'id' => $requestId,
                                ]);
                            auto_post_invoice_payment_voucher((int) $requestId, $adminId);
                        }
                    }
                    $message = 'Invoice created successfully.' . $mirrorNote . ($stockPostingNotes !== [] ? ' ' . implode(' ', $stockPostingNotes) : '');
                }
            }
        }
    } else {
        $targetInvoice = $invoiceId > 0 ? get_invoice($invoiceId) : null;
        if (!$targetInvoice || (int) ($targetInvoice['company_id'] ?? 0) !== (int) $currentCompany['id']) {
            $error = 'Invoice not found for this company portal.';
        } elseif ($postAction === 'request_payment') {
        $amount = (float) ($_POST['amount'] ?? 0);
        $method = $_POST['payment_method'] ?? null;
        $notes = $_POST['notes'] ?? null;

        if ($amount > 0) {
            $requestId = create_payment_request($invoiceId, (int) $currentCompany['id'], $adminId, $amount, $method, $notes);
            if ($requestId) {
                // Redirect-after-POST so a browser refresh cannot create a
                // duplicate payment request.
                flash('success', 'Payment request created successfully. The client sees it on their dashboard.');
                redirect('admin/invoice.php?action=view&id=' . $invoiceId);
            } else {
                $error = 'Failed to create payment request';
            }
        } else {
            $error = 'Payment amount must be greater than zero.';
        }
        } elseif ($postAction === 'record_payment') {
            $paymentRequestId = (int) ($_POST['payment_request_id'] ?? 0);
            $paymentStatus = (string) ($_POST['payment_status'] ?? 'paid');
            $paymentAmount = (float) ($_POST['payment_amount'] ?? 0);
            $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
            $paymentReference = trim((string) ($_POST['payment_reference'] ?? ''));
            $paymentDate = trim((string) ($_POST['payment_received_on'] ?? date('Y-m-d')));
            $paymentNotes = trim((string) ($_POST['payment_notes'] ?? ''));

            if ($paymentRequestId <= 0 || !in_array($paymentStatus, ['paid', 'partial'], true) || $paymentAmount <= 0) {
                $error = 'Invalid payment update details.';
            } else {
                $requestStmt = db()->prepare('SELECT * FROM invoice_payment_requests WHERE id = :id AND invoice_id = :invoice_id AND company_id = :company_id LIMIT 1');
                $requestStmt->execute([
                    'id' => $paymentRequestId,
                    'invoice_id' => $invoiceId,
                    'company_id' => (int) $currentCompany['id'],
                ]);
                $paymentRequest = $requestStmt->fetch();

                if (!$paymentRequest) {
                    $error = 'Payment request not found for this invoice.';
                } else {
                    $notesToSave = trim(($paymentRequest['notes'] ?? '') . ($paymentNotes !== '' ? "\n" . $paymentNotes : '') . ($paymentReference !== '' ? "\nRef: " . $paymentReference : ''));
                    db()->prepare('UPDATE invoice_payment_requests SET status = :status, payment_received_on = :payment_received_on, payment_amount = :payment_amount, payment_method = :payment_method, notes = :notes WHERE id = :id')
                        ->execute([
                            'status' => $paymentStatus,
                            'payment_received_on' => $paymentDate,
                            'payment_amount' => $paymentAmount,
                            'payment_method' => $paymentMethod !== '' ? $paymentMethod : ($paymentRequest['payment_method'] ?? null),
                            'notes' => $notesToSave !== '' ? $notesToSave : null,
                            'id' => $paymentRequestId,
                        ]);

                    $invoiceTotal = (float) ($targetInvoice['total_amount'] ?? $targetInvoice['amount'] ?? 0);
                    $newInvoiceStatus = $paymentStatus === 'paid' || $paymentAmount >= $invoiceTotal ? 'paid' : 'issued';
                    db()->prepare('UPDATE task_invoices SET status = :status WHERE id = :id AND company_id = :company_id')
                        ->execute([
                            'status' => $newInvoiceStatus,
                            'id' => $invoiceId,
                            'company_id' => (int) $currentCompany['id'],
                        ]);

                    auto_post_invoice_payment_voucher($paymentRequestId, $adminId);

                    // A draft goods invoice collected here transitions to
                    // issued/paid — issue its stock and COGS now (idempotent;
                    // no-op for invoices that already moved stock at creation).
                    $paymentStockNotes = invoice_issue_inventory_stock($invoiceId, $adminId);
                    if ($paymentStockNotes !== []) {
                        flash('info', implode(' ', $paymentStockNotes));
                    }

                    if (table_exists('invoice_payment_receipts')) {
                        $existingReceipt = get_payment_receipt_by_request($paymentRequestId, (int) $currentCompany['id']);
                        if (!$existingReceipt) {
                            $clientId = null;
                            if (!empty($targetInvoice['task_id']) && table_exists('client_tasks')) {
                                $clientStmt = db()->prepare('SELECT client_id FROM client_tasks WHERE id = :id LIMIT 1');
                                $clientStmt->execute(['id' => (int) $targetInvoice['task_id']]);
                                $clientId = (int) ($clientStmt->fetchColumn() ?: 0);
                            }

                            db()->prepare('INSERT INTO invoice_payment_receipts (payment_request_id, invoice_id, company_id, client_id, receipt_no, amount_received, payment_method, payment_reference, received_on, notes, created_by)
                                VALUES (:payment_request_id, :invoice_id, :company_id, :client_id, :receipt_no, :amount_received, :payment_method, :payment_reference, :received_on, :notes, :created_by)')
                                ->execute([
                                    'payment_request_id' => $paymentRequestId,
                                    'invoice_id' => $invoiceId,
                                    'company_id' => (int) $currentCompany['id'],
                                    'client_id' => $clientId > 0 ? $clientId : null,
                                    'receipt_no' => next_receipt_number((int) $currentCompany['id']),
                                    'amount_received' => $paymentAmount,
                                    'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
                                    'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
                                    'received_on' => $paymentDate,
                                    'notes' => $paymentNotes !== '' ? $paymentNotes : null,
                                    'created_by' => $adminId > 0 ? $adminId : null,
                                ]);
                        }
                    }

                    // Redirect-after-POST: a refresh must not record the payment
                    // twice, and the Record Payment form re-renders collapsed.
                    flash('success', 'Payment recorded and receipt generated.');
                    redirect('admin/invoice.php?action=view&id=' . $invoiceId);
                }
            }
        } elseif ($postAction === 'convert_to_tax') {
        if (convert_to_tax_invoice($invoiceId, $adminId)) {
            $message = 'Invoice converted to tax invoice successfully';
        } else {
            $error = 'Failed to convert invoice or invalid state';
        }
        } elseif ($postAction === 'update_invoice') {
        $invoiceNo = $_POST['invoice_no'] ?? null;
        $amount = (float) ($_POST['amount'] ?? 0);
        $issueDate = $_POST['issued_on'] ?? null;
        $dueDate = $_POST['due_on'] ?? null;
        $notes = $_POST['notes'] ?? null;
        $vatRate = (float) ($_POST['vat_rate'] ?? default_vat_rate());
        $invoiceSourceType = (string) ($targetInvoice['invoice_source_type'] ?? 'task');
        $exciseRate = $invoiceSourceType === 'manufacturing'
            ? (float) ($_POST['excise_rate'] ?? ($targetInvoice['excise_rate'] ?? $defaultExciseRate))
            : 0.0;

        if (!invoice_is_editable($targetInvoice)) {
            $error = 'This invoice can no longer be edited. Only draft and proforma invoices that are not paid or cancelled are editable.';
        } elseif ($invoiceNo && $amount > 0 && $issueDate) {
            $existingDiscount = (float) ($targetInvoice['discount_amount'] ?? 0);
            $taxableAmount = max($amount - $existingDiscount, 0.0);
            $exciseAmount = round($taxableAmount * ($exciseRate / 100), 2);
            $vatAmount = round(($taxableAmount + $exciseAmount) * ($vatRate / 100), 2);
            $totalAmount = round($taxableAmount + $exciseAmount + $vatAmount, 2);

            try {
                $stmt = db()->prepare('
                    UPDATE task_invoices 
                    SET invoice_no = :invoice_no,
                        amount = :amount,
                        issued_on = :issued_on,
                        due_on = :due_on,
                        notes = :notes,
                        vat_rate = :vat_rate,
                        excise_rate = :excise_rate,
                        excise_amount = :excise_amount,
                        vat_amount = :vat_amount,
                        taxable_amount = :taxable_amount,
                        total_amount = :total_amount
                    WHERE id = :id AND company_id = :company_id
                ');

                $stmt->execute([
                    'invoice_no' => $invoiceNo,
                    'amount' => $amount,
                    'issued_on' => $issueDate,
                    'due_on' => $dueDate,
                    'notes' => $notes,
                    'vat_rate' => $vatRate,
                    'excise_rate' => $exciseRate,
                    'excise_amount' => $exciseAmount,
                    'vat_amount' => $vatAmount,
                    'taxable_amount' => $taxableAmount,
                    'total_amount' => $totalAmount,
                    'id' => $invoiceId,
                    'company_id' => $currentCompany['id']
                ]);

                $message = 'Invoice updated successfully';
            } catch (Exception $e) {
                $error = 'Failed to update invoice: ' . $e->getMessage();
            }
        } else {
            $error = 'Invoice number, amount, and issue date are required.';
        }
        }
    }
}

// Get filter parameters
$status = $_GET['status'] ?? null;
$category = $_GET['category'] ?? null;
$search = $_GET['search'] ?? null;
$fromDate = $_GET['from_date'] ?? null;
$toDate = $_GET['to_date'] ?? null;

$filters = [];
if ($status) $filters['status'] = $status;
if ($category) $filters['category'] = $category;
if ($search) $filters['search'] = $search;
if ($fromDate) $filters['from_date'] = $fromDate;
if ($toDate) $filters['to_date'] = $toDate;

$invoices = get_company_invoices((int) $currentCompany['id'], $filters);
$invoice = $action !== 'list' && $invoiceId ? get_invoice($invoiceId) : null;
$subsidiaryInvoices = [];
$subsidiaryCount = 0;
$taskOptions = [];
$stageOptions = [];
$partyOptions = [];
$inventoryItems = [];
$manufacturingOptions = [];

if ($action === 'list') {
    if (table_exists('client_tasks')) {
        $taskOptionsStmt = db()->prepare('SELECT t.id, t.title, t.status, t.quoted_fee, cp.organization_name FROM client_tasks t LEFT JOIN client_profiles cp ON cp.id = t.client_id WHERE t.company_id = :company_id ORDER BY t.created_at DESC LIMIT 200');
        $taskOptionsStmt->execute(['company_id' => (int) $currentCompany['id']]);
        $taskOptions = $taskOptionsStmt->fetchAll() ?: [];
    }

    if (table_exists('task_stages')) {
        $stageOptionsStmt = db()->prepare('SELECT ts.id, ts.task_id, ts.stage_name, ts.status, ts.stage_fee, t.title AS task_title FROM task_stages ts INNER JOIN client_tasks t ON t.id = ts.task_id WHERE ts.company_id = :company_id ORDER BY ts.created_at DESC LIMIT 200');
        $stageOptionsStmt->execute(['company_id' => (int) $currentCompany['id']]);
        $stageOptions = $stageOptionsStmt->fetchAll() ?: [];
    }

    if (table_exists('accounting_parties')) {
        $partyStmt = db()->prepare("SELECT id, code, name, party_type FROM accounting_parties WHERE company_id = :company_id AND status = 'active' ORDER BY name ASC");
        $partyStmt->execute(['company_id' => (int) $currentCompany['id']]);
        $partyOptions = $partyStmt->fetchAll() ?: [];
    }

    if (table_exists('inventory_items')) {
        $itemStmt = db()->prepare("SELECT id, sku, name, unit, hs_code, tax_rate, sales_rate FROM inventory_items WHERE company_id = :company_id AND status = 'active' ORDER BY name ASC");
        $itemStmt->execute(['company_id' => (int) $currentCompany['id']]);
        $inventoryItems = $itemStmt->fetchAll() ?: [];
    }

    if (table_exists('manufacturing_orders')) {
        $manufacturingStmt = db()->prepare('
            SELECT mo.id, mo.order_no, mo.quantity, i.sku, i.name AS item_name
            FROM manufacturing_orders mo
            INNER JOIN inventory_items i ON i.id = mo.finished_item_id
            WHERE mo.company_id = :company_id
            ORDER BY mo.created_at DESC
            LIMIT 100
        ');
        $manufacturingStmt->execute(['company_id' => (int) $currentCompany['id']]);
        $manufacturingOptions = $manufacturingStmt->fetchAll() ?: [];
    }

    $childCompanies = child_companies_for_company((int) $currentCompany['id']);
    $subsidiaryCount = count($childCompanies);

    if ($subsidiaryCount > 0) {
        $companyIds = array_map(static fn(array $company): int => (int) $company['id'], $childCompanies);
        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
        $stmt = db()->prepare("\n            SELECT ti.id, ti.invoice_no, ti.invoice_category, ti.status, ti.amount, ti.total_amount,\n                   ti.issued_on, ti.due_on, ct.title AS task_title, c.name AS company_name\n            FROM task_invoices ti\n            LEFT JOIN client_tasks ct ON ti.task_id = ct.id\n            LEFT JOIN companies c ON ti.company_id = c.id\n            WHERE ti.company_id IN ($placeholders)\n            ORDER BY ti.issued_on DESC, ti.id DESC\n            LIMIT 20\n        ");
        $stmt->execute($companyIds);
        $subsidiaryInvoices = $stmt->fetchAll() ?: [];
    }
}

if ($action !== 'list' && (!$invoice || (int) ($invoice['company_id'] ?? 0) !== (int) $currentCompany['id'])) {
    $action = 'list';
    $error = "Invoice not found";
}

if ($action === 'edit' && $invoice && !invoice_is_editable($invoice)) {
    $action = 'view';
    $error = 'This invoice can no longer be edited. Only draft and proforma invoices that are not paid or cancelled are editable.';
}

$hasClientDeclarations = column_exists('invoice_payment_requests', 'client_declared_status');
$declarationsAwaitingReview = 0;
$invoiceIdsWithDeclarations = [];
if ($hasClientDeclarations) {
    $declStmt = db()->prepare("SELECT invoice_id FROM invoice_payment_requests WHERE company_id = :company_id AND client_declared_status <> 'none' AND status IN ('pending', 'partial')");
    $declStmt->execute(['company_id' => (int) $currentCompany['id']]);
    $invoiceIdsWithDeclarations = array_map('intval', array_column($declStmt->fetchAll(), 'invoice_id'));
    $declarationsAwaitingReview = count($invoiceIdsWithDeclarations);
}

$selectedInvoiceSourceType = (string) ($_POST['invoice_source_type'] ?? $defaultInvoiceSourceType);
if (!in_array($selectedInvoiceSourceType, $allowedInvoiceSourceTypes, true)) {
    $selectedInvoiceSourceType = $defaultInvoiceSourceType;
}
$selectedInvoiceType = $selectedInvoiceSourceType === 'task' ? (string) ($_POST['invoice_type'] ?? 'stage') : $selectedInvoiceSourceType;
$showTaskFields = $selectedInvoiceSourceType === 'task';
$showGoodsFields = in_array($selectedInvoiceSourceType, ['inventory', 'manufacturing'], true);
$showManufacturingFields = $selectedInvoiceSourceType === 'manufacturing';
$showServiceFields = in_array($selectedInvoiceSourceType, ['task', 'other'], true);

$pageTitle = 'Invoice Management';
$pageSubtitle = 'Create, issue, convert, and collect client invoices for ' . (string) ($currentCompany['name'] ?? 'this portal') . '.';
$bodyClass = 'admin-layout';
require __DIR__ . '/../../app/views/partials/admin_header.php';
?>
    <style>
        .invoice-line { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 1rem; padding: 0.5rem 0; border-bottom: 1px solid var(--border-color); }
        .invoice-line.header { font-weight: 600; background: var(--surface-soft); padding: 0.75rem 0.5rem; }
        .invoice-total { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 1rem; padding: 1rem 0; margin-top: 1rem; border-top: 2px solid var(--border-dark); font-weight: 600; }
        .vat-section { background: var(--surface-soft); border: 1px solid var(--border-color); padding: 1rem; border-radius: var(--radius-sm); margin: 1rem 0; }
        .badge { display: inline-block; padding: 0.2rem 0.65rem; border-radius: 999px; font-size: 0.78rem; font-weight: 600; margin-right: 0.5rem; border: 0; background: color-mix(in srgb, var(--primary) 8%, transparent); color: var(--primary); }
        .badge-info { background: color-mix(in srgb, var(--info) 12%, transparent); color: var(--info); }
        .badge-success { background: color-mix(in srgb, var(--success) 12%, transparent); color: var(--success); }
        .badge-primary { background: color-mix(in srgb, var(--secondary) 12%, transparent); color: var(--secondary); }
        .badge-warning { background: color-mix(in srgb, var(--warning) 14%, transparent); color: var(--warning); }
        .badge-danger { background: color-mix(in srgb, var(--danger) 12%, transparent); color: var(--danger); }
        .action-buttons { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 0.25rem; }
        .form-group input, .form-group textarea, .form-group select { width: 100%; }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .alert { padding: 0.9rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1rem; font-weight: 500; border-left: 3px solid var(--border-dark); }
        .alert-success { background: color-mix(in srgb, var(--success) 7%, var(--surface)); color: color-mix(in srgb, var(--success) 75%, var(--text-primary)); border-left-color: var(--success); }
        .alert-danger { background: color-mix(in srgb, var(--danger) 7%, var(--surface)); color: color-mix(in srgb, var(--danger) 75%, var(--text-primary)); border-left-color: var(--danger); }
        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(10, 21, 38, 0.55); z-index: 1000; }
        .modal.active { display: flex; align-items: center; justify-content: center; }
        .modal-content { position: relative; background: var(--surface); color: var(--text-primary); padding: 1.75rem; border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); max-width: 500px; width: 90%; }
        .modal-close { position: absolute; top: 0.75rem; right: 1rem; cursor: pointer; font-size: 1.5rem; color: var(--text-secondary); }
        .invoice-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .invoice-table thead { background: var(--surface-soft); }
        .invoice-table th, .invoice-table td { padding: 0.7rem 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color); }
        .invoice-table tbody tr:hover { background: var(--surface-soft); }
        .invoice-empty-state { border: 1px dashed var(--mbw-border); border-radius: var(--mbw-radius-sm); padding: 1rem; background: var(--mbw-card-soft); margin-top: 1rem; }
        .invoice-empty-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.75rem; }
        .create-invoice-grid { display: grid; grid-template-columns: 1.3fr 1fr 1fr; gap: 0.75rem; }
        .create-invoice-grid .full { grid-column: 1 / -1; }
        .create-invoice-grid label { display: block; font-weight: 600; margin-bottom: 0.25rem; }
        .create-invoice-grid input, .create-invoice-grid select, .create-invoice-grid textarea { width: 100%; }
        .invoice-lines-box { display: grid; gap: 0.75rem; }
        .invoice-line-entry { display: grid; grid-template-columns: 1.2fr 1.5fr 0.55fr 0.7fr 0.55fr; gap: 0.5rem; }
        .invoice-line-entry input,
        .invoice-line-entry select { min-width: 0; }
        .is-hidden { display: none !important; }
        @media (max-width: 960px) {
            .create-invoice-grid { grid-template-columns: 1fr; }
            .invoice-line-entry { grid-template-columns: 1fr; }
        }
    </style>
    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo e($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if ($action === 'list'): ?>
            <!-- List View -->
            <?php if ($declarationsAwaitingReview > 0): ?>
                <div class="alert" style="background: var(--mbw-amber-soft); color: var(--mbw-amber); border-left-color: var(--mbw-amber);">
                    <strong><?php echo (int) $declarationsAwaitingReview; ?></strong> client payment declaration(s) awaiting your review — open the flagged invoice(s) below to verify and record the payment.
                </div>
            <?php endif; ?>

            <section class="mbw-kpi-grid">
                <a class="mbw-kpi" href="<?php echo e(url('admin/invoice.php')); ?>">
                    <div>
                        <span class="mbw-kpi-label">Portal Invoices</span>
                        <div class="mbw-kpi-value"><?php echo (int) count($invoices); ?></div>
                        <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">in current view</span></span>
                    </div>
                    <span class="mbw-chip tone-blue"><?= icon('invoices') ?></span>
                </a>
                <a class="mbw-kpi" href="<?php echo e(url('admin/invoice.php?status=draft')); ?>">
                    <div>
                        <span class="mbw-kpi-label">Draft</span>
                        <div class="mbw-kpi-value"><?php echo (int) count(array_filter($invoices, static fn(array $row): bool => ($row['status'] ?? '') === 'draft')); ?></div>
                        <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">not yet issued</span></span>
                    </div>
                    <span class="mbw-chip tone-gray"><?= icon('documents') ?></span>
                </a>
                <a class="mbw-kpi" href="<?php echo e(url('admin/invoice.php?status=issued')); ?>">
                    <div>
                        <span class="mbw-kpi-label">Issued</span>
                        <div class="mbw-kpi-value"><?php echo (int) count(array_filter($invoices, static fn(array $row): bool => ($row['status'] ?? '') === 'issued')); ?></div>
                        <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">awaiting payment</span></span>
                    </div>
                    <span class="mbw-chip tone-amber"><?= icon('receipt-voucher') ?></span>
                </a>
                <a class="mbw-kpi" href="<?php echo e(url('admin/invoice.php?status=paid')); ?>">
                    <div>
                        <span class="mbw-kpi-label">Paid</span>
                        <div class="mbw-kpi-value"><?php echo (int) count(array_filter($invoices, static fn(array $row): bool => ($row['status'] ?? '') === 'paid')); ?></div>
                        <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">collected</span></span>
                    </div>
                    <span class="mbw-chip tone-green"><?= icon('wallet') ?></span>
                </a>
            </section>

            <section class="mbw-card">
                <div class="mbw-card-head">
                    <h2>Create Invoice</h2>
                    <div class="mbw-card-tools"><span style="color: var(--mbw-muted); font-size: 0.85rem;">Create directly from this tab without leaving Invoice Management.</span></div>
                </div>
                    <form method="post" class="create-invoice-grid">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_invoice">
                        <?php $invoiceSourceLabels = ['task' => 'Client Task / Service', 'inventory' => 'Inventory Sale', 'manufacturing' => 'Manufacturing Output', 'other' => 'Other / Manual']; ?>

                        <div>
                            <label>Invoice Source</label>
                            <select name="invoice_source_type" id="invoice_source_type">
                                <?php foreach ($allowedInvoiceSourceTypes as $sourceTypeOption): ?>
                                    <option value="<?php echo e($sourceTypeOption); ?>" <?php echo $selectedInvoiceSourceType === $sourceTypeOption ? 'selected' : ''; ?>><?php echo e($invoiceSourceLabels[$sourceTypeOption] ?? ucfirst($sourceTypeOption)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label>Party / Customer</label>
                            <select name="party_id">
                                <option value="0">No accounting party</option>
                                <?php foreach ($partyOptions as $party): ?>
                                    <option value="<?php echo e((int) $party['id']); ?>"><?php echo e($party['code'] . ' - ' . $party['name'] . ' (' . $party['party_type'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label>Amount before VAT</label>
                            <input type="number" min="0" step="0.01" name="amount" placeholder="Leave 0 when using line items">
                        </div>

                        <div id="task-field" class="<?php echo $showTaskFields ? '' : 'is-hidden'; ?>">
                            <label>Task</label>
                            <select name="task_id">
                                <option value="">Select task</option>
                                <?php foreach ($taskOptions as $task): ?>
                                    <option value="<?php echo e((int) $task['id']); ?>">
                                        #<?php echo e((int) $task['id']); ?> - <?php echo e($task['title']); ?> (<?php echo e($task['organization_name'] ?? 'No client'); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="invoice-type-field" class="<?php echo $showTaskFields ? '' : 'is-hidden'; ?>">
                            <label>Invoice Type</label>
                            <select name="invoice_type" id="invoice_type">
                                <option value="stage" <?php echo $selectedInvoiceType === 'stage' ? 'selected' : ''; ?>>Stage Invoice</option>
                                <option value="task" <?php echo $selectedInvoiceType === 'task' ? 'selected' : ''; ?>>Task Invoice</option>
                                <option value="termination" <?php echo $selectedInvoiceType === 'termination' ? 'selected' : ''; ?>>Termination Settlement / Compensation</option>
                            </select>
                        </div>

                        <div id="stage-field" class="<?php echo $showTaskFields ? '' : 'is-hidden'; ?>">
                            <label>Stage (required for stage invoice)</label>
                            <select name="stage_id">
                                <option value="0">No stage selected</option>
                                <?php foreach ($stageOptions as $stage): ?>
                                    <option value="<?php echo e((int) $stage['id']); ?>">
                                        #<?php echo e((int) $stage['task_id']); ?> - <?php echo e($stage['stage_name']); ?> (<?php echo e($stage['status']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="manufacturing-order-field" class="<?php echo $showManufacturingFields ? '' : 'is-hidden'; ?>">
                            <label>Manufacturing Order</label>
                            <select name="manufacturing_order_id">
                                <option value="0">No manufacturing order</option>
                                <?php foreach ($manufacturingOptions as $order): ?>
                                    <option value="<?php echo e((int) $order['id']); ?>"><?php echo e($order['order_no'] . ' - ' . $order['sku'] . ' ' . $order['item_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label>Status</label>
                            <select name="status" required>
                                <option value="issued">Issued</option>
                                <option value="draft">Draft</option>
                                <option value="paid">Paid</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>

                        <div>
                            <label>Category</label>
                            <select name="invoice_category" required>
                                <option value="proforma">Proforma</option>
                                <option value="tax">Tax Invoice</option>
                            </select>
                        </div>

                        <div>
                            <label>Issued On</label>
                            <input type="date" name="issued_on" value="<?php echo e(date('Y-m-d')); ?>" required>
                        </div>

                        <div>
                            <label>Due On</label>
                            <input type="date" name="due_on">
                        </div>

                        <div>
                            <label>VAT Rate (%)</label>
                            <input type="number" name="vat_rate" step="0.01" value="13.00" required>
                        </div>

                        <div id="excise-rate-field" class="<?php echo $showManufacturingFields ? '' : 'is-hidden'; ?>">
                            <label>Excise Duty Rate (%)</label>
                            <input type="number" name="excise_rate" step="0.01" min="0" value="<?php echo e(number_format($defaultExciseRate, 2, '.', '')); ?>">
                        </div>

                        <div class="full">
                            <label>Invoice Description</label>
                            <input type="text" name="description" placeholder="<?php echo $showGoodsFields ? 'Inventory sale, manufacturing invoice, or manual billing description' : 'Service or manual billing description'; ?>">
                        </div>

                        <div class="full invoice-lines-box <?php echo $showGoodsFields ? '' : 'is-hidden'; ?>" id="line-items-field">
                            <label>Invoice Line Items</label>
                            <div class="invoice-line-entry">
                                <select name="item_id[]">
                                    <option value="0">No inventory item</option>
                                    <?php foreach ($inventoryItems as $item): ?>
                                        <option value="<?php echo e((int) $item['id']); ?>" data-rate="<?php echo e((float) $item['sales_rate']); ?>" data-tax="<?php echo e((float) $item['tax_rate']); ?>" data-label="<?php echo e($item['sku'] . ' - ' . $item['name']); ?>">
                                            <?php echo e($item['sku'] . ' - ' . $item['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="line_description[]" placeholder="Line description">
                                <input type="number" name="line_quantity[]" step="0.001" min="0" placeholder="Qty">
                                <input type="number" name="line_rate[]" step="0.01" min="0" placeholder="Rate">
                                <input type="number" name="line_vat_rate[]" step="0.01" min="0" placeholder="VAT %" value="13.00">
                            </div>
                            <div class="invoice-line-entry">
                                <select name="item_id[]">
                                    <option value="0">No inventory item</option>
                                    <?php foreach ($inventoryItems as $item): ?>
                                        <option value="<?php echo e((int) $item['id']); ?>" data-rate="<?php echo e((float) $item['sales_rate']); ?>" data-tax="<?php echo e((float) $item['tax_rate']); ?>" data-label="<?php echo e($item['sku'] . ' - ' . $item['name']); ?>">
                                            <?php echo e($item['sku'] . ' - ' . $item['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="line_description[]" placeholder="Line description">
                                <input type="number" name="line_quantity[]" step="0.001" min="0" placeholder="Qty">
                                <input type="number" name="line_rate[]" step="0.01" min="0" placeholder="Rate">
                                <input type="number" name="line_vat_rate[]" step="0.01" min="0" placeholder="VAT %" value="13.00">
                            </div>
                            <small class="text-muted">For inventory invoices, selected item quantities are issued from stock automatically when the invoice is issued or paid. Manufacturing invoices also apply the configured excise rate on the taxable amount.</small>
                        </div>

                        <div class="full">
                            <label>Notes</label>
                            <textarea name="notes" rows="3"></textarea>
                        </div>

                        <div class="full">
                            <button type="submit" class="btn btn-success">Create Invoice</button>
                        </div>
                    </form>
                    <?php if ($taskOptions === []): ?>
                        <p style="margin-bottom: 0; color: var(--mbw-muted);">No tasks available yet. Create a task in Work Portal first, then return here to issue invoices.</p>
                    <?php endif; ?>
            </section>

            <section class="mbw-card">
                <div class="mbw-card-head">
                    <h2>Invoices</h2>
                    <div class="mbw-card-tools"><a class="mbw-view-all" href="<?php echo e(url('admin/reports-center.php?report=collections-register')); ?>">Receipt Register</a></div>
                </div>
                <form method="get" style="margin-bottom: 1rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr 1fr; gap: 0.5rem; margin-bottom: 1rem;">
                        <input type="text" name="search" placeholder="Search invoice #" value="<?php echo e($search ?? ''); ?>">
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="issued" <?php echo $status === 'issued' ? 'selected' : ''; ?>>Issued</option>
                            <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                        <select name="category">
                            <option value="">All Types</option>
                            <option value="proforma" <?php echo $category === 'proforma' ? 'selected' : ''; ?>>Proforma</option>
                            <option value="tax" <?php echo $category === 'tax' ? 'selected' : ''; ?>>Tax Invoice</option>
                        </select>
                        <input type="date" name="from_date" value="<?php echo e($fromDate ?? ''); ?>">
                        <input type="date" name="to_date" value="<?php echo e($toDate ?? ''); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Filter</button>
                </form>

                <?php if (empty($invoices)): ?>
                    <div class="invoice-empty-state">
                        <p class="text-muted" style="margin: 0;">No invoices found for this portal yet.</p>
                        <div class="invoice-empty-actions">
                            <a href="<?php echo e(url('admin/workspace.php?view=home')); ?>" class="btn btn-primary">Open Work Portal</a>
                            <a href="<?php echo e(url('admin/accounting.php')); ?>" class="btn btn-secondary">Open Accounting</a>
                            <a href="<?php echo e(url('admin/invoice.php')); ?>" class="btn btn-secondary">Refresh</a>
                        </div>
                        <?php if ($subsidiaryCount > 0): ?>
                            <p class="text-muted" style="margin-top: 0.75rem; margin-bottom: 0;">
                                This parent portal has <?php echo (int) $subsidiaryCount; ?> subsidiaries. Recent subsidiary invoices are shown below.
                            </p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto">
                    <table class="invoice-table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Task</th>
                                <th class="is-numeric">Amount</th>
                                <th>Issued Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $inv): ?>
                                <tr>
                                    <td>
                                        <?php echo e($inv['invoice_no']); ?>
                                        <?php if (in_array((int) $inv['id'], $invoiceIdsWithDeclarations, true)): ?>
                                            <br><span class="mbw-pill tone-amber">Client payment declared</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="mbw-pill tone-blue"><?php echo e(ucfirst($inv['invoice_category'])); ?></span></td>
                                    <td><span class="mbw-pill tone-<?php echo $inv['status'] === 'paid' ? 'green' : ($inv['status'] === 'cancelled' ? 'red' : ($inv['status'] === 'issued' ? 'amber' : 'blue')); ?>"><?php echo e(ucfirst($inv['status'])); ?></span></td>
                                    <td><?php echo e($inv['task_title'] ?? 'N/A'); ?></td>
                                    <td class="is-numeric">NPR <?php echo number_format((float) ($inv['total_amount'] ?? $inv['amount']), 2); ?></td>
                                    <td><?php echo e($inv['issued_on']); ?></td>
                                    <td>
                                        <a href="?action=view&id=<?php echo (int) $inv['id']; ?>" class="btn btn-primary">View</a>
                                        <?php if (invoice_is_editable($inv)): ?>
                                            <a href="?action=edit&id=<?php echo (int) $inv['id']; ?>" class="btn btn-secondary">Edit</a>
                                        <?php endif; ?>
                                        <?php if ($inv['invoice_category'] === 'proforma' && $inv['status'] === 'issued'): ?>
                                            <button onclick="convertToTax(<?php echo (int) $inv['id']; ?>)" class="btn btn-success">Convert to Tax</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </section>

            <?php if (!empty($subsidiaryInvoices)): ?>
                <section class="mbw-card">
                    <div class="mbw-card-head"><h2>Recent Subsidiary Invoices</h2></div>
                    <div style="overflow-x:auto">
                    <table class="invoice-table">
                        <thead>
                            <tr>
                                <th>Company</th>
                                <th>Invoice #</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Task</th>
                                <th class="is-numeric">Amount</th>
                                <th>Issued Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subsidiaryInvoices as $subInv): ?>
                                <tr>
                                    <td><?php echo e($subInv['company_name'] ?? 'Subsidiary'); ?></td>
                                    <td><?php echo e($subInv['invoice_no']); ?></td>
                                    <td><span class="mbw-pill tone-blue"><?php echo e(ucfirst($subInv['invoice_category'])); ?></span></td>
                                    <td><span class="mbw-pill tone-<?php echo ($subInv['status'] ?? '') === 'paid' ? 'green' : ((($subInv['status'] ?? '') === 'cancelled') ? 'red' : ((($subInv['status'] ?? '') === 'issued') ? 'amber' : 'blue')); ?>"><?php echo e(ucfirst($subInv['status'] ?? 'draft')); ?></span></td>
                                    <td><?php echo e($subInv['task_title'] ?? 'N/A'); ?></td>
                                    <td class="is-numeric">NPR <?php echo number_format((float) ($subInv['total_amount'] ?? $subInv['amount']), 2); ?></td>
                                    <td><?php echo e($subInv['issued_on']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </section>
            <?php endif; ?>

        <?php elseif ($action === 'view' && $invoice): ?>
            <!-- View Invoice -->
            <section class="mbw-card">
                <div class="mbw-card-head">
                    <h2>Invoice: <?php echo e($invoice['invoice_no']); ?></h2>
                    <div class="mbw-card-tools"><a class="mbw-view-all" href="?action=list">Back to List</a></div>
                </div>
                <div><?php echo get_invoice_status_badge($invoice); ?></div>

                <div style="margin-top: 2rem; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 2rem;">
                    <div>
                        <h4>From</h4>
                        <p><?php echo e($invoice['company_name']); ?></p>
                        <?php if ($invoice['pan_number']): ?>
                            <p>PAN: <?php echo e($invoice['pan_number']); ?></p>
                        <?php endif; ?>
                        <?php if ($invoice['vat_reg_number']): ?>
                            <p>VAT Reg: <?php echo e($invoice['vat_reg_number']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h4>Invoice Details</h4>
                        <p><strong>Issued:</strong> <?php echo e($invoice['issued_on']); ?></p>
                        <p><strong>Due:</strong> <?php echo e($invoice['due_on'] ?? 'On demand'); ?></p>
                        <p><strong>Template:</strong> <?php echo e(ucfirst((string) ($invoice['invoice_source_type'] ?? 'task'))); ?></p>
                        <?php if ($invoice['invoice_category'] === 'tax' && $invoice['tax_invoice_date']): ?>
                            <p><strong>Tax Invoice Date:</strong> <?php echo e($invoice['tax_invoice_date']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h4>Issued By</h4>
                        <p><?php echo e($invoice['issued_by_name'] ?? 'System'); ?></p>
                        <p><?php echo e($invoice['issued_by_email'] ?? ''); ?></p>
                    </div>
                </div>

                <div style="margin-top: 2rem;">
                    <h4>Invoice Items</h4>
                    <?php
                        $invoiceSourceType = (string) ($invoice['invoice_source_type'] ?? 'task');
                        $showQuantityRate = !in_array($invoiceSourceType, ['task', 'other'], true);
                        $showExcise = $invoiceSourceType === 'manufacturing';
                        $exciseRateDisplay = $showExcise ? (float) ($invoice['excise_rate'] ?? $defaultExciseRate) : 0.0;
                        $invoiceLines = $invoice['line_items'] ?? [];
                        if ($invoiceLines === []) {
                            // The VAT columns default to 0.00 (not NULL), so treat a
                            // stored zero as "not computed" and rebuild the figure —
                            // null-coalescing alone never reaches the fallback.
                            $fallbackTaxable = (float) ($invoice['taxable_amount'] ?? 0);
                            if ($fallbackTaxable <= 0) {
                                $fallbackTaxable = (float) ($invoice['amount'] ?? 0);
                            }
                            $fallbackExcise = $showExcise ? (float) ($invoice['excise_amount'] ?? 0) : 0.0;
                            if ($showExcise && $fallbackExcise <= 0) {
                                $fallbackExcise = round($fallbackTaxable * ($exciseRateDisplay / 100), 2);
                            }
                            $fallbackVatRate = (float) ($invoice['vat_rate'] ?? 13.00);
                            $fallbackVat = (float) ($invoice['vat_amount'] ?? 0);
                            if ($fallbackVat <= 0) {
                                $fallbackVat = round(($fallbackTaxable + $fallbackExcise) * ($fallbackVatRate / 100), 2);
                            }
                            $invoiceLines = [[
                                'description' => $invoice['description'] ?? ($invoice['task_title'] ?? 'Invoice line'),
                                'unit' => 'job',
                                'quantity' => 1,
                                'rate' => $fallbackTaxable,
                                'taxable_amount' => $fallbackTaxable,
                                'excise_amount' => $fallbackExcise,
                                'vat_rate' => $fallbackVatRate,
                                'vat_amount' => $fallbackVat,
                                'total_amount' => round($fallbackTaxable + $fallbackExcise + $fallbackVat, 2),
                            ]];
                        }
                    ?>
                    <div class="invoice-line header">
                        <div>Description</div>
                        <?php if ($showQuantityRate): ?>
                            <div>Unit</div>
                            <div>Qty</div>
                            <div>Rate</div>
                            <div>Taxable</div>
                        <?php else: ?>
                            <div>Amount</div>
                        <?php endif; ?>
                        <?php if ($showExcise): ?>
                            <div>Excise Duty</div>
                        <?php endif; ?>
                        <div>VAT</div>
                        <div>Total</div>
                    </div>
                    <?php foreach ($invoiceLines as $line): ?>
                        <?php
                            $lineTaxable = (float) ($line['taxable_amount'] ?? 0);
                            if ($lineTaxable <= 0) {
                                $lineTaxable = round((float) ($line['quantity'] ?? 1) * (float) ($line['rate'] ?? 0), 2);
                            }
                            $lineExcise = $showExcise ? (float) ($line['excise_amount'] ?? 0) : 0.0;
                            if ($showExcise && $lineExcise <= 0) {
                                $lineExcise = round($lineTaxable * ($exciseRateDisplay / 100), 2);
                            }
                            $lineVatRate = (float) ($line['vat_rate'] ?? $invoice['vat_rate'] ?? 13.00);
                            // Stored 0.00 (column default) means "not computed" — rebuild
                            // from the line's own rate instead of printing zero.
                            $lineVat = (float) ($line['vat_amount'] ?? 0);
                            if ($lineVat <= 0) {
                                $lineVat = round(($lineTaxable + $lineExcise) * ($lineVatRate / 100), 2);
                            }
                            $lineTotal = (float) ($line['total_amount'] ?? 0);
                            if ($lineTotal <= 0) {
                                $lineTotal = round($lineTaxable + $lineExcise + $lineVat, 2);
                            }
                        ?>
                        <div class="invoice-line">
                            <div><?php echo e($line['description'] ?? ($invoice['task_title'] ?? 'Invoice line')); ?></div>
                            <?php if ($showQuantityRate): ?>
                                <div><?php echo e($line['unit'] ?? 'pcs'); ?></div>
                                <div><?php echo e(number_format((float) ($line['quantity'] ?? 1), 3)); ?></div>
                                <div>NPR <?php echo number_format((float) ($line['rate'] ?? 0), 2); ?></div>
                                <div>NPR <?php echo number_format($lineTaxable, 2); ?></div>
                            <?php else: ?>
                                <div>NPR <?php echo number_format($lineTaxable, 2); ?></div>
                            <?php endif; ?>
                            <?php if ($showExcise): ?>
                                <div>NPR <?php echo number_format($lineExcise, 2); ?></div>
                            <?php endif; ?>
                            <div>NPR <?php echo number_format($lineVat, 2); ?></div>
                            <div>NPR <?php echo number_format($lineTotal, 2); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="vat-section">
                    <div style="display: grid; grid-template-columns: auto 1fr; gap: 1rem; margin-bottom: 0.5rem;">
                        <strong>Taxable Amount:</strong>
                        <span>NPR <?php echo number_format((float) ($invoice['taxable_amount'] ?? $invoice['amount']), 2); ?></span>
                    </div>
                    <?php if ($showExcise): ?>
                        <div style="display: grid; grid-template-columns: auto 1fr; gap: 1rem; margin-bottom: 0.5rem;">
                            <strong>Excise Duty:</strong>
                            <span>NPR <?php echo number_format((float) ($invoice['excise_amount'] ?? 0), 2); ?> @ <?php echo number_format($exciseRateDisplay, 2); ?>%</span>
                        </div>
                    <?php endif; ?>
                    <div style="display: grid; grid-template-columns: auto 1fr; gap: 1rem; margin-bottom: 0.5rem;">
                        <strong>VAT Rate:</strong>
                        <span><?php echo number_format((float) ($invoice['vat_rate'] ?? 13.00), 2); ?>%</span>
                    </div>
                    <div style="display: grid; grid-template-columns: auto 1fr; gap: 1rem; margin-bottom: 0.5rem;">
                        <strong>VAT Amount:</strong>
                        <span>NPR <?php echo number_format((float) $invoice['vat_amount'], 2); ?></span>
                    </div>
                    <div style="display: grid; grid-template-columns: auto 1fr; gap: 1rem; border-top: 2px solid var(--mbw-border); padding-top: 0.5rem; margin-top: 0.5rem;">
                        <strong style="font-size: 1.1rem;">Total Amount:</strong>
                        <span style="font-size: 1.1rem;">NPR <?php echo number_format((float) $invoice['total_amount'], 2); ?></span>
                    </div>
                </div>

                <?php if ($invoice['notes']): ?>
                    <div style="margin-top: 1rem;">
                        <h4>Notes</h4>
                        <p><?php echo nl2br(e($invoice['notes'])); ?></p>
                    </div>
                <?php endif; ?>

                <div class="action-buttons">
                    <a href="?action=list" class="btn btn-secondary">Back to List</a>
                    <?php if (invoice_is_editable($invoice)): ?>
                        <a href="?action=edit&id=<?php echo (int) $invoice['id']; ?>" class="btn btn-secondary">Edit</a>
                    <?php endif; ?>
                    <?php if ($invoice['invoice_category'] === 'proforma'): ?>
                        <button onclick="convertToTax(<?php echo (int) $invoice['id']; ?>)" class="btn btn-success">Convert to Tax Invoice</button>
                    <?php endif; ?>
                    <button onclick="showPaymentModal(<?php echo (int) $invoice['id']; ?>)" class="btn btn-primary">Request Payment</button>
                    <a href="<?php echo e(url('admin/reports-center.php?report=collections-register')); ?>" class="btn btn-secondary">Receipt Register</a>
                    <a href="<?php echo e(url('admin/export-invoice.php?id=' . $invoice['id'] . '&format=pdf')); ?>" class="btn btn-secondary" target="_blank">📄 Export PDF</a>
                    <a href="<?php echo e(url('admin/export-invoice.php?id=' . $invoice['id'] . '&format=excel')); ?>" class="btn btn-secondary">📊 Export Excel</a>
                </div>

                <?php if (!empty($invoice['payment_requests'])): ?>
                    <div style="margin-top: 2rem;">
                        <h3>Payment Requests</h3>
                        <div style="overflow-x:auto">
                        <table class="invoice-table">
                            <thead>
                                <tr>
                                    <th class="is-numeric">Amount</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <th>Requested On</th>
                                    <th>Received</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invoice['payment_requests'] as $pr): ?>
                                    <?php
                                        $prDeclared = $hasClientDeclarations && ($pr['client_declared_status'] ?? 'none') !== 'none';
                                        $prefillAmount = $prDeclared && !empty($pr['client_declared_amount'])
                                            ? (float) $pr['client_declared_amount']
                                            : (float) $pr['amount_requested'];
                                        $prefillStatus = $prDeclared && ($pr['client_declared_status'] ?? '') === 'partial' ? 'partial' : 'paid';
                                        $prefillDate = $prDeclared && !empty($pr['client_declared_on']) ? (string) $pr['client_declared_on'] : date('Y-m-d');
                                        $prefillMethod = $prDeclared ? (string) ($pr['client_declared_method'] ?? '') : '';
                                        $prefillReference = $prDeclared ? (string) ($pr['client_declared_reference'] ?? '') : '';
                                    ?>
                                    <tr>
                                        <td class="is-numeric">NPR <?php echo number_format((float) $pr['amount_requested'], 2); ?></td>
                                        <td><?php echo e($pr['payment_method'] ?? 'Not specified'); ?></td>
                                        <td><span class="mbw-pill tone-<?php echo $pr['status'] === 'paid' ? 'green' : ($pr['status'] === 'cancelled' ? 'red' : 'amber'); ?>"><?php echo e(ucfirst($pr['status'])); ?></span></td>
                                        <td><?php echo e($pr['requested_on']); ?></td>
                                        <td><?php echo e($pr['payment_received_on'] ?? 'Pending'); ?></td>
                                        <td>
                                            <?php if ($prDeclared && in_array($pr['status'], ['pending', 'partial'], true)): ?>
                                                <div style="background:var(--mbw-amber-soft); color:var(--mbw-heading); border:1px solid var(--mbw-border); border-radius:6px; padding:0.5rem 0.75rem; margin-bottom:0.5rem; max-width:260px;">
                                                    <strong>Client declared <?php echo e($pr['client_declared_status']); ?> payment</strong><br>
                                                    Amount: NPR <?php echo number_format((float) ($pr['client_declared_amount'] ?? 0), 2); ?><br>
                                                    <?php if (!empty($pr['client_declared_method'])): ?>Method: <?php echo e($pr['client_declared_method']); ?><br><?php endif; ?>
                                                    <?php if (!empty($pr['client_declared_reference'])): ?>Ref: <?php echo e($pr['client_declared_reference']); ?><br><?php endif; ?>
                                                    <?php if (!empty($pr['client_declared_on'])): ?>Paid on: <?php echo e($pr['client_declared_on']); ?><br><?php endif; ?>
                                                    <?php if (!empty($pr['client_declared_note'])): ?>Note: <?php echo e($pr['client_declared_note']); ?><br><?php endif; ?>
                                                    <small>Declared at <?php echo e($pr['client_declared_at'] ?? ''); ?> — verify before recording.</small>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (in_array($pr['status'], ['pending', 'partial'], true)): ?>
                                                <details class="feature-disclosure" style="max-width:280px;" <?php echo $prDeclared && (string) $pr['status'] === 'pending' ? 'open' : ''; ?>>
                                                    <summary>
                                                        <span><strong>Record Payment</strong>
                                                        <small><?php echo $prDeclared ? 'Client declared a payment — verify and record it.' : 'Open to record a received payment.'; ?></small></span>
                                                    </summary>
                                                    <form method="post" class="inline-action-form" style="display:grid; gap:6px; max-width:260px; margin-top:6px;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="record_payment">
                                                        <input type="hidden" name="invoice_id" value="<?php echo (int) $invoice['id']; ?>">
                                                        <input type="hidden" name="payment_request_id" value="<?php echo (int) $pr['id']; ?>">
                                                        <select name="payment_status">
                                                            <option value="paid" <?php echo $prefillStatus === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                                            <option value="partial" <?php echo $prefillStatus === 'partial' ? 'selected' : ''; ?>>Partial</option>
                                                        </select>
                                                        <input type="number" name="payment_amount" min="0.01" step="0.01" value="<?php echo e(number_format($prefillAmount, 2, '.', '')); ?>" required>
                                                        <input type="date" name="payment_received_on" value="<?php echo e($prefillDate); ?>" required>
                                                        <input type="text" name="payment_method" placeholder="Method (bank/cash/etc)" value="<?php echo e($prefillMethod); ?>">
                                                        <input type="text" name="payment_reference" placeholder="Reference #" value="<?php echo e($prefillReference); ?>">
                                                        <textarea name="payment_notes" placeholder="Payment note"></textarea>
                                                        <button type="submit" class="btn btn-success">Record Payment</button>
                                                    </form>
                                                </details>
                                            <?php endif; ?>
                                            <?php if (table_exists('invoice_payment_receipts')): ?>
                                                <?php $receipt = get_payment_receipt_by_request((int) $pr['id'], (int) $currentCompany['id']); ?>
                                                <?php if ($receipt): ?>
                                                    <a class="btn btn-secondary" href="<?php echo e(url('admin/export-payment-receipt.php?request_id=' . (int) $pr['id'])); ?>" target="_blank" rel="noopener">Receipt</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

        <?php elseif ($action === 'edit' && $invoice): ?>
            <!-- Edit Invoice -->
            <section class="mbw-card">
                <div class="mbw-card-head">
                    <h2>Edit Invoice: <?php echo e($invoice['invoice_no']); ?></h2>
                    <div class="mbw-card-tools"><a class="mbw-view-all" href="?action=view&id=<?php echo (int) $invoice['id']; ?>">Back to Invoice</a></div>
                </div>
                <?php $editShowsExcise = (string) ($invoice['invoice_source_type'] ?? 'task') === 'manufacturing'; ?>
                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="update_invoice">
                    <input type="hidden" name="invoice_id" value="<?php echo (int) $invoice['id']; ?>">
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label>Invoice Number</label>
                            <input type="text" name="invoice_no" value="<?php echo e($invoice['invoice_no']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Amount (Before VAT)</label>
                            <input type="number" name="amount" step="0.01" value="<?php echo e(number_format((float) $invoice['amount'], 2, '.', '')); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Issued On</label>
                            <input type="date" name="issued_on" value="<?php echo e($invoice['issued_on']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Due On</label>
                            <input type="date" name="due_on" value="<?php echo e($invoice['due_on'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>VAT Rate (%)</label>
                            <input type="number" name="vat_rate" step="0.01" value="<?php echo e(number_format((float) ($invoice['vat_rate'] ?? 13.00), 2, '.', '')); ?>" required>
                        </div>
                        <?php if ($editShowsExcise): ?>
                            <div class="form-group">
                                <label>Excise Duty Rate (%)</label>
                                <input type="number" name="excise_rate" step="0.01" value="<?php echo e(number_format((float) ($invoice['excise_rate'] ?? $defaultExciseRate), 2, '.', '')); ?>">
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes"><?php echo e($invoice['notes'] ?? ''); ?></textarea>
                    </div>

                    <div style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn btn-success">Save Changes</button>
                        <a href="?action=view&id=<?php echo (int) $invoice['id']; ?>" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>

    <!-- Payment Request Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closePaymentModal()">&times;</span>
            <h3>Request Payment</h3>
            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="request_payment">
                <input type="hidden" id="modalInvoiceId" name="invoice_id" value="">
                
                <div class="form-group">
                    <label>Amount</label>
                    <input type="number" name="amount" step="0.01" required>
                </div>
                
                <div class="form-group">
                    <label>Payment Method</label>
                    <input type="text" name="payment_method" placeholder="e.g., Bank Transfer, Check, Cash">
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" placeholder="Add any payment instructions or notes"></textarea>
                </div>

                <div style="display: flex; gap: 0.5rem;">
                    <button type="submit" class="btn btn-success">Create Request</button>
                    <button type="button" onclick="closePaymentModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Convert to Tax Modal -->
    <div id="convertModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeConvertModal()">&times;</span>
            <h3>Convert to Tax Invoice</h3>
            <p>This action will convert the proforma invoice to a tax invoice. This cannot be undone.</p>
            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="convert_to_tax">
                <input type="hidden" id="modalConvertId" name="invoice_id" value="">
                
                <div style="display: flex; gap: 0.5rem;">
                    <button type="submit" class="btn btn-success">Confirm Conversion</button>
                    <button type="button" onclick="closeConvertModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleInvoiceField(id, hidden) {
            const field = document.getElementById(id);
            if (field) {
                field.classList.toggle('is-hidden', hidden);
            }
        }

        function syncInvoiceFields() {
            const sourceSelect = document.getElementById('invoice_source_type');
            if (!sourceSelect) return;
            const sourceType = sourceSelect.value;
            const isTask = sourceType === 'task';
            const isGoods = sourceType === 'inventory' || sourceType === 'manufacturing';
            const isManufacturing = sourceType === 'manufacturing';

            toggleInvoiceField('task-field', !isTask);
            toggleInvoiceField('invoice-type-field', !isTask);
            toggleInvoiceField('stage-field', !isTask);
            toggleInvoiceField('manufacturing-order-field', !isManufacturing);
            toggleInvoiceField('excise-rate-field', !isManufacturing);
            toggleInvoiceField('line-items-field', !isGoods);
        }

        function showPaymentModal(invoiceId) {
            document.getElementById('modalInvoiceId').value = invoiceId;
            document.getElementById('paymentModal').classList.add('active');
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.remove('active');
        }

        function convertToTax(invoiceId) {
            document.getElementById('modalConvertId').value = invoiceId;
            document.getElementById('convertModal').classList.add('active');
        }

        function closeConvertModal() {
            document.getElementById('convertModal').classList.remove('active');
        }

        // Close modals on background click
        document.getElementById('paymentModal').addEventListener('click', function(e) {
            if (e.target === this) closePaymentModal();
        });

        document.getElementById('convertModal').addEventListener('click', function(e) {
            if (e.target === this) closeConvertModal();
        });

        const invoiceSourceSelect = document.getElementById('invoice_source_type');
        if (invoiceSourceSelect) {
            invoiceSourceSelect.addEventListener('change', syncInvoiceFields);
            syncInvoiceFields();
        }

        document.querySelectorAll('.invoice-line-entry select[name="item_id[]"]').forEach(function(select) {
            select.addEventListener('change', function() {
                const option = select.options[select.selectedIndex];
                const row = select.closest('.invoice-line-entry');
                if (!row || !option) return;
                const description = row.querySelector('input[name="line_description[]"]');
                const quantity = row.querySelector('input[name="line_quantity[]"]');
                const rate = row.querySelector('input[name="line_rate[]"]');
                const vat = row.querySelector('input[name="line_vat_rate[]"]');
                if (description && !description.value && option.dataset.label) description.value = option.dataset.label;
                if (quantity && !quantity.value) quantity.value = '1';
                if (rate && option.dataset.rate) rate.value = option.dataset.rate;
                if (vat && option.dataset.tax) vat.value = option.dataset.tax;
            });
        });
    </script>
<?php require __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
