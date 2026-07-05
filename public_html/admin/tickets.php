<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_or_admin();

$pageTitle = 'Support Tickets';
$user = current_user();
$role = (string) ($user['role'] ?? '');
$userId = (int) $user['id'];

if ($role === 'admin') {
    require_company_context();
    $company = current_company();
    $companyId = (int) ($company['id'] ?? 0);
} else {
    $companyId = (int) ($user['company_id'] ?? 0);
}

$scopedClientIds = $role === 'staff' ? staff_scoped_client_ids($userId, $companyId) : null;

$clients = [];
if (table_exists('client_profiles')) {
    $clientSql = 'SELECT cp.id, cp.organization_name FROM client_profiles cp WHERE cp.company_id = ?';
    $clientParams = [$companyId];
    if ($scopedClientIds !== null) {
        if ($scopedClientIds === []) {
            $clientSql .= ' AND 1 = 0';
        } else {
            $placeholders = implode(',', array_fill(0, count($scopedClientIds), '?'));
            $clientSql .= " AND cp.id IN ($placeholders)";
            $clientParams = array_merge($clientParams, $scopedClientIds);
        }
    }
    $clientSql .= ' ORDER BY cp.organization_name ASC';
    $clientStmt = db()->prepare($clientSql);
    $clientStmt->execute($clientParams);
    $clients = $clientStmt->fetchAll();
}
$clientIdsInScope = array_map(static fn (array $c): int => (int) $c['id'], $clients);

$allowedStatus = ['open', 'assigned', 'in_progress', 'waiting_for_client', 'resolved', 'closed'];
$statusLabels = [
    'open' => 'Open',
    'assigned' => 'Assigned',
    'in_progress' => 'In progress',
    'waiting_for_client' => 'Waiting for client',
    'resolved' => 'Resolved',
    'closed' => 'Closed',
];
$categoryLabels = [
    'general' => 'General',
    'technical' => 'Technical',
    'billing' => 'Billing',
    'document' => 'Document',
    'other' => 'Other',
];
$requestTypeLabels = ticket_request_type_labels();

$requestDecisionLabels = [
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'negotiation' => 'Negotiation',
    'deferred' => 'Deferred',
];

$staffUsers = [];
$staffStmt = db()->prepare("SELECT id, name FROM users WHERE role IN ('staff', 'admin') AND status = 'active' AND company_id = :company_id ORDER BY name ASC");
$staffStmt->execute(['company_id' => $companyId]);
$staffUsers = $staffStmt->fetchAll();
$hasTicketRequests = table_exists('support_ticket_requests');
$hasTicketSla = column_exists('support_tickets', 'sla_due_at');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_ticket') {
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        $assignedStaffUserId = (int) ($_POST['assigned_staff_user_id'] ?? 0);
        $resolution = trim((string) ($_POST['resolution'] ?? ''));
        $slaDueAt = trim((string) ($_POST['sla_due_at'] ?? ''));

        if ($ticketId <= 0 || !in_array($status, $allowedStatus, true)) {
            flash('error', 'Invalid ticket update.');
            redirect('admin/tickets.php');
        }

        $ticketStmt = db()->prepare('SELECT * FROM support_tickets WHERE id = :id AND company_id = :company_id LIMIT 1');
        $ticketStmt->execute(['id' => $ticketId, 'company_id' => $companyId]);
        $ticket = $ticketStmt->fetch();
        if (!$ticket || !in_array((int) $ticket['client_id'], $clientIdsInScope, true)) {
            flash('error', 'That ticket is not in your scope.');
            redirect('admin/tickets.php');
        }

        $sql = 'UPDATE support_tickets SET status = :status, assigned_staff_user_id = :assigned_staff_user_id, resolution = :resolution';
        $params = [
            'status' => $status,
            'assigned_staff_user_id' => $assignedStaffUserId > 0 ? $assignedStaffUserId : null,
            'resolution' => $resolution !== '' ? $resolution : null,
            'id' => $ticketId,
            'company_id' => $companyId,
        ];
        if ($hasTicketSla) {
            $sql .= ', sla_due_at = :sla_due_at, resolved_at = :resolved_at';
            $params['sla_due_at'] = $slaDueAt !== '' ? str_replace('T', ' ', $slaDueAt) . ':00' : null;
            $params['resolved_at'] = in_array($status, ['resolved', 'closed'], true) ? (($ticket['resolved_at'] ?? null) ?: date('Y-m-d H:i:s')) : null;
        }
        $sql .= ' WHERE id = :id AND company_id = :company_id';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        log_field_changes('support_ticket', $ticketId, $ticket, ['status' => $status, 'assigned_staff_user_id' => $assignedStaffUserId > 0 ? $assignedStaffUserId : null, 'resolution' => $resolution !== '' ? $resolution : null, 'sla_due_at' => $params['sla_due_at'] ?? null, 'resolved_at' => $params['resolved_at'] ?? null], $companyId, $userId);
        log_activity('support_ticket', $ticketId, 'updated', 'Ticket status updated to ' . $status . '.', $userId);
        flash('success', 'Ticket updated.');
        redirect('admin/tickets.php?ticket_id=' . $ticketId);
    }

    if ($action === 'reply_ticket') {
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $body = trim((string) ($_POST['body'] ?? ''));

        $ticketStmt = db()->prepare('SELECT * FROM support_tickets WHERE id = :id AND company_id = :company_id LIMIT 1');
        $ticketStmt->execute(['id' => $ticketId, 'company_id' => $companyId]);
        $ticket = $ticketStmt->fetch();
        if (!$ticket || !in_array((int) $ticket['client_id'], $clientIdsInScope, true)) {
            flash('error', 'That ticket is not in your scope.');
            redirect('admin/tickets.php');
        }
        if ($body === '') {
            flash('error', 'Reply cannot be empty.');
            redirect('admin/tickets.php?ticket_id=' . $ticketId);
        }

        $uploadResult = [];
        if (isset($_FILES['attachment']) && (int) $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = handle_message_attachment_upload($_FILES['attachment'], 'tickets/' . $ticketId);
            if (!$uploadResult['ok']) {
                flash('error', $uploadResult['error']);
                redirect('admin/tickets.php?ticket_id=' . $ticketId);
            }
        }

        $stmt = db()->prepare('INSERT INTO support_ticket_messages (ticket_id, sender_id, body, attachment_path, attachment_name) VALUES (:ticket_id, :sender_id, :body, :attachment_path, :attachment_name)');
        $stmt->execute([
            'ticket_id' => $ticketId,
            'sender_id' => $userId,
            'body' => $body,
            'attachment_path' => $uploadResult['file_path'] ?? null,
            'attachment_name' => $uploadResult['original_file_name'] ?? null,
        ]);
        log_activity('support_ticket', $ticketId, 'replied', 'Staff reply added.', $userId);
        redirect('admin/tickets.php?ticket_id=' . $ticketId);
    }

    if ($action === 'process_ticket_request') {
        if (!$hasTicketRequests) {
            flash('error', 'Support request workflow table is missing. Apply latest migration first.');
            redirect('admin/tickets.php');
        }
        if ($role !== 'admin') {
            flash('error', 'Only admin can process invoice-related requests.');
            redirect('admin/tickets.php');
        }

        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $adminNote = trim((string) ($_POST['admin_note'] ?? ''));
        $approvedAmount = trim((string) ($_POST['approved_amount'] ?? ''));
        $approvedPercent = trim((string) ($_POST['approved_percent'] ?? ''));
        $approvedDueOn = trim((string) ($_POST['approved_due_on'] ?? ''));

        if (!in_array($decision, ['approved', 'rejected', 'negotiation', 'deferred'], true) || $ticketId <= 0) {
            flash('error', 'Invalid request decision.');
            redirect('admin/tickets.php');
        }

        $requestStmt = db()->prepare('SELECT st.id AS ticket_id, st.client_id, st.company_id, st.subject, tr.*
            FROM support_tickets st
            INNER JOIN support_ticket_requests tr ON tr.ticket_id = st.id
            WHERE st.id = :ticket_id AND st.company_id = :company_id
            LIMIT 1');
        $requestStmt->execute([
            'ticket_id' => $ticketId,
            'company_id' => $companyId,
        ]);
        $request = $requestStmt->fetch();

        if (!$request || !in_array((int) $request['client_id'], $clientIdsInScope, true)) {
            flash('error', 'Request not found in your scope.');
            redirect('admin/tickets.php');
        }

        $approvedAmountValue = $approvedAmount !== '' && is_numeric($approvedAmount) ? round((float) $approvedAmount, 2) : null;
        $approvedPercentValue = $approvedPercent !== '' && is_numeric($approvedPercent) ? round((float) $approvedPercent, 2) : null;
        $approvedDueOnValue = $approvedDueOn !== '' ? $approvedDueOn : null;

        $appliedInvoiceId = null;
        $applyError = null;

        if ($decision === 'approved') {
            $requestType = (string) $request['request_type'];

            if ($requestType === 'invoice_request') {
                if (!empty($request['target_invoice_id'])) {
                    $appliedInvoiceId = (int) $request['target_invoice_id'];
                } else {
                    $targetTaskId = (int) ($request['target_task_id'] ?? 0);
                    $targetStageId = (int) ($request['target_stage_id'] ?? 0);
                    $issueAmount = $approvedAmountValue ?? (is_numeric((string) $request['requested_amount']) ? round((float) $request['requested_amount'], 2) : 0.0);

                    if ($targetTaskId <= 0 || $issueAmount <= 0) {
                        $applyError = 'Invoice request needs target task and amount.';
                    } else {
                        $taskStmt = db()->prepare('SELECT id, status FROM client_tasks WHERE id = :id AND company_id = :company_id LIMIT 1');
                        $taskStmt->execute(['id' => $targetTaskId, 'company_id' => $companyId]);
                        $task = $taskStmt->fetch();

                        if (!$task || ($task['status'] ?? '') !== 'completed') {
                            $applyError = 'Invoice can be issued only for completed task.';
                        }

                        if (!$applyError && $targetStageId > 0) {
                            $stageStmt = db()->prepare('SELECT id, status FROM task_stages WHERE id = :id AND task_id = :task_id AND company_id = :company_id LIMIT 1');
                            $stageStmt->execute(['id' => $targetStageId, 'task_id' => $targetTaskId, 'company_id' => $companyId]);
                            $stage = $stageStmt->fetch();
                            if (!$stage || ($stage['status'] ?? '') !== 'completed') {
                                $applyError = 'Stage invoice can be issued only for completed stage.';
                            }
                        }

                        if (!$applyError) {
                            $invoiceNo = 'INV-REQ-' . date('Ymd') . '-' . str_pad((string) $targetTaskId, 5, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
                            $insertInvoice = db()->prepare('INSERT INTO task_invoices (company_id, task_id, stage_id, invoice_no, invoice_type, amount, status, issued_on, due_on, notes, issued_by) VALUES (:company_id, :task_id, :stage_id, :invoice_no, :invoice_type, :amount, :status, :issued_on, :due_on, :notes, :issued_by)');
                            $insertInvoice->execute([
                                'company_id' => $companyId,
                                'task_id' => $targetTaskId,
                                'stage_id' => $targetStageId > 0 ? $targetStageId : null,
                                'invoice_no' => $invoiceNo,
                                'invoice_type' => $targetStageId > 0 ? 'stage' : 'task',
                                'amount' => $issueAmount,
                                'status' => 'issued',
                                'issued_on' => date('Y-m-d'),
                                'due_on' => $approvedDueOnValue,
                                'notes' => 'Issued from support ticket request #' . (int) $request['ticket_id'],
                                'issued_by' => $userId,
                            ]);
                            $appliedInvoiceId = (int) db()->lastInsertId();

                            try {
                                $vatRate = 13.00;
                                $vatAmount = round($issueAmount * ($vatRate / 100), 2);
                                $totalAmount = round($issueAmount + $vatAmount, 2);
                                db()->prepare('UPDATE task_invoices SET invoice_category = :category, vat_rate = :vat_rate, vat_amount = :vat_amount, taxable_amount = :taxable_amount, total_amount = :total_amount WHERE id = :id AND company_id = :company_id')
                                    ->execute([
                                        'category' => 'proforma',
                                        'vat_rate' => $vatRate,
                                        'vat_amount' => $vatAmount,
                                        'taxable_amount' => $issueAmount,
                                        'total_amount' => $totalAmount,
                                        'id' => $appliedInvoiceId,
                                        'company_id' => $companyId,
                                    ]);
                            } catch (Throwable $ignored) {
                            }

                            auto_post_task_invoice_voucher($appliedInvoiceId, $userId);
                        }
                    }
                }
            }

            if ($requestType === 'discount_request') {
                $targetInvoiceId = (int) ($request['target_invoice_id'] ?? 0);
                if ($targetInvoiceId <= 0) {
                    $applyError = 'Discount request needs a target invoice.';
                } else {
                    $invoiceStmt = db()->prepare('SELECT * FROM task_invoices WHERE id = :id AND company_id = :company_id LIMIT 1');
                    $invoiceStmt->execute(['id' => $targetInvoiceId, 'company_id' => $companyId]);
                    $invoice = $invoiceStmt->fetch();
                    if (!$invoice) {
                        $applyError = 'Target invoice not found.';
                    } else {
                        $taxable = (float) ($invoice['taxable_amount'] ?? $invoice['amount'] ?? 0);
                        $discountPercent = $approvedPercentValue ?? (is_numeric((string) $request['requested_percent']) ? (float) $request['requested_percent'] : 0.0);
                        $discountFixed = $approvedAmountValue ?? (is_numeric((string) $request['requested_amount']) ? (float) $request['requested_amount'] : 0.0);

                        $discountAmount = $discountFixed > 0
                            ? $discountFixed
                            : ($discountPercent > 0 ? round($taxable * ($discountPercent / 100), 2) : 0.0);

                        if ($discountAmount <= 0 || $discountAmount >= $taxable) {
                            $applyError = 'Discount amount is invalid for selected invoice.';
                        } else {
                            $newTaxable = round($taxable - $discountAmount, 2);
                            $vatRate = (float) ($invoice['vat_rate'] ?? 13.00);
                            $newVat = round($newTaxable * ($vatRate / 100), 2);
                            $newTotal = round($newTaxable + $newVat, 2);
                            $discountType = $discountFixed > 0 ? 'fixed' : 'percent';
                            $discountValue = $discountFixed > 0 ? $discountFixed : $discountPercent;

                            db()->prepare('UPDATE task_invoices SET taxable_amount = :taxable_amount, vat_amount = :vat_amount, total_amount = :total_amount, discount_type = :discount_type, discount_value = :discount_value, discount_amount = :discount_amount, adjusted_on = NOW(), adjusted_by = :adjusted_by WHERE id = :id AND company_id = :company_id')
                                ->execute([
                                    'taxable_amount' => $newTaxable,
                                    'vat_amount' => $newVat,
                                    'total_amount' => $newTotal,
                                    'discount_type' => $discountType,
                                    'discount_value' => $discountValue,
                                    'discount_amount' => $discountAmount,
                                    'adjusted_by' => $userId,
                                    'id' => $targetInvoiceId,
                                    'company_id' => $companyId,
                                ]);
                            $appliedInvoiceId = $targetInvoiceId;
                        }
                    }
                }
            }

            if ($requestType === 'credit_period_request') {
                $targetInvoiceId = (int) ($request['target_invoice_id'] ?? 0);
                $newDue = $approvedDueOnValue ?: ($request['requested_due_on'] ?? null);
                if ($targetInvoiceId <= 0 || !$newDue) {
                    $applyError = 'Credit period request needs target invoice and due date.';
                } else {
                    db()->prepare('UPDATE task_invoices SET due_on = :due_on, adjusted_on = NOW(), adjusted_by = :adjusted_by WHERE id = :id AND company_id = :company_id')
                        ->execute([
                            'due_on' => $newDue,
                            'adjusted_by' => $userId,
                            'id' => $targetInvoiceId,
                            'company_id' => $companyId,
                        ]);
                    $appliedInvoiceId = $targetInvoiceId;
                }
            }

            if (in_array($requestType, ['advance_payment_request', 'partial_payment_request'], true)) {
                $targetInvoiceId = (int) ($request['target_invoice_id'] ?? 0);
                $requestAmount = $approvedAmountValue ?? (is_numeric((string) $request['requested_amount']) ? round((float) $request['requested_amount'], 2) : 0.0);
                if ($targetInvoiceId <= 0 || $requestAmount <= 0) {
                    $applyError = 'Payment request needs target invoice and amount.';
                } else {
                    db()->prepare('INSERT INTO invoice_payment_requests (invoice_id, company_id, requested_by, amount_requested, payment_method, status, notes) VALUES (:invoice_id, :company_id, :requested_by, :amount_requested, :payment_method, :status, :notes)')
                        ->execute([
                            'invoice_id' => $targetInvoiceId,
                            'company_id' => $companyId,
                            'requested_by' => $userId,
                            'amount_requested' => $requestAmount,
                            'payment_method' => 'Pending client payment',
                            'status' => 'pending',
                            'notes' => ucfirst(str_replace('_', ' ', $requestType)) . ' approved from support ticket #' . (int) $request['ticket_id'],
                        ]);
                    $appliedInvoiceId = $targetInvoiceId;
                }
            }
        }

        if ($applyError) {
            flash('error', $applyError);
            redirect('admin/tickets.php?ticket_id=' . $ticketId);
        }

        $requestUpdate = db()->prepare('UPDATE support_ticket_requests
            SET decision_status = :decision_status,
                approved_amount = :approved_amount,
                approved_percent = :approved_percent,
                approved_due_on = :approved_due_on,
                applied_invoice_id = :applied_invoice_id,
                admin_note = :admin_note,
                processed_by = :processed_by,
                processed_on = NOW()
            WHERE ticket_id = :ticket_id');
        $requestUpdate->execute([
            'decision_status' => $decision,
            'approved_amount' => $approvedAmountValue,
            'approved_percent' => $approvedPercentValue,
            'approved_due_on' => $approvedDueOnValue,
            'applied_invoice_id' => $appliedInvoiceId,
            'admin_note' => $adminNote !== '' ? $adminNote : null,
            'processed_by' => $userId,
            'ticket_id' => $ticketId,
        ]);

        $ticketStatus = $decision === 'rejected' ? 'resolved' : 'waiting_for_client';
        db()->prepare('UPDATE support_tickets SET status = :status WHERE id = :id AND company_id = :company_id')
            ->execute([
                'status' => $ticketStatus,
                'id' => $ticketId,
                'company_id' => $companyId,
            ]);

        $messageBody = 'Request update: ' . strtoupper($decision) . ".\n";
        if ($adminNote !== '') {
            $messageBody .= 'Admin note: ' . $adminNote . "\n";
        }
        if ($appliedInvoiceId) {
            $messageBody .= 'Reference invoice ID: #' . $appliedInvoiceId;
        }

        db()->prepare('INSERT INTO support_ticket_messages (ticket_id, sender_id, body) VALUES (:ticket_id, :sender_id, :body)')
            ->execute([
                'ticket_id' => $ticketId,
                'sender_id' => $userId,
                'body' => $messageBody,
            ]);

        log_activity('support_ticket', $ticketId, 'request_processed', 'Support request processed with decision: ' . $decision, $userId);
        flash('success', 'Support request processed and client notified.');
        redirect('admin/tickets.php?ticket_id=' . $ticketId);
    }

    flash('error', 'Unsupported ticket action.');
    redirect('admin/tickets.php');
}

$ticketId = (int) ($_GET['ticket_id'] ?? 0);
$ticket = null;
$ticketMessages = [];
$tickets = [];

if ($ticketId > 0 && $clientIdsInScope !== []) {
    $placeholders = implode(',', array_fill(0, count($clientIdsInScope), '?'));
    $ticketSql = "SELECT st.*, cp.organization_name, us.name AS assigned_staff_name";
    if ($hasTicketRequests) {
        $ticketSql .= ", tr.request_type, tr.decision_status, tr.requested_amount, tr.requested_percent, tr.requested_due_on,
            tr.target_task_id, tr.target_stage_id, tr.target_invoice_id, tr.approved_amount, tr.approved_percent,
            tr.approved_due_on, tr.admin_note";
    }
    $ticketSql .= "
        FROM support_tickets st
        INNER JOIN client_profiles cp ON cp.id = st.client_id
        LEFT JOIN users us ON us.id = st.assigned_staff_user_id";
    if ($hasTicketRequests) {
        $ticketSql .= " LEFT JOIN support_ticket_requests tr ON tr.ticket_id = st.id";
    }
    $ticketSql .= "
        WHERE st.id = ? AND st.company_id = ? AND st.client_id IN ($placeholders)
        LIMIT 1";
    $ticketStmt = db()->prepare($ticketSql);
    $ticketStmt->execute(array_merge([$ticketId, $companyId], $clientIdsInScope));
    $ticket = $ticketStmt->fetch();

    if ($ticket) {
        $msgStmt = db()->prepare('SELECT tm.*, u.name AS sender_name FROM support_ticket_messages tm INNER JOIN users u ON u.id = tm.sender_id WHERE tm.ticket_id = :ticket_id ORDER BY tm.created_at ASC');
        $msgStmt->execute(['ticket_id' => $ticketId]);
        $ticketMessages = $msgStmt->fetchAll();
    } else {
        $ticketId = 0;
    }
}

$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$clientFilter = (int) ($_GET['client_id'] ?? 0);

if ($ticketId === 0 && $clientIdsInScope !== []) {
    $placeholders = implode(',', array_fill(0, count($clientIdsInScope), '?'));
    $where = ['st.company_id = ?', "st.client_id IN ($placeholders)"];
    $params = array_merge([$companyId], $clientIdsInScope);

    if ($statusFilter !== 'all' && in_array($statusFilter, $allowedStatus, true)) {
        $where[] = 'st.status = ?';
        $params[] = $statusFilter;
    }
    if ($clientFilter > 0 && in_array($clientFilter, $clientIdsInScope, true)) {
        $where[] = 'st.client_id = ?';
        $params[] = $clientFilter;
    }

    $sql = 'SELECT st.*, cp.organization_name, us.name AS assigned_staff_name';
    if ($hasTicketRequests) {
        $sql .= ', tr.request_type, tr.decision_status';
    }
    $sql .= '
        FROM support_tickets st
        INNER JOIN client_profiles cp ON cp.id = st.client_id
        LEFT JOIN users us ON us.id = st.assigned_staff_user_id';
    if ($hasTicketRequests) {
        $sql .= ' LEFT JOIN support_ticket_requests tr ON tr.ticket_id = st.id';
    }
    $sql .= ' WHERE ' . implode(' AND ', $where) . ' ORDER BY st.created_at DESC LIMIT 150';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();
}

$pageTitle = $ticket ? $ticket['subject'] : 'Support Tickets';

include __DIR__ . '/../../app/views/partials/' . ($role === 'admin' ? 'admin_header' : 'staff_header') . '.php';
?>

<?php if ($ticketId === 0): ?>
    <div class="table-card">
        <h2><?= icon('tickets') ?>Support tickets</h2>

        <div class="users-filters-card">
            <h3>Filters</h3>
            <form method="get" class="users-filter-grid">
                <label>Status
                    <select name="status">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                        <?php foreach ($statusLabels as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Client
                    <select name="client_id">
                        <option value="0">All clients</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= e((int) $client['id']) ?>" <?= $clientFilter === (int) $client['id'] ? 'selected' : '' ?>><?= e($client['organization_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="users-filter-actions">
                    <button type="submit">Apply</button>
                    <a class="button secondary" href="<?= e(url('admin/tickets.php')) ?>">Reset</a>
                </div>
            </form>
        </div>

        <div class="table-card" style="margin-top:20px;">
            <table>
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Client</th>
                        <th>Request type</th>
                        <th>Category</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <?php if ($hasTicketSla): ?><th>SLA</th><?php endif; ?>
                        <th>Assigned</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($tickets === []): ?>
                        <tr><td colspan="<?= $hasTicketSla ? '8' : '7' ?>">No support tickets found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($tickets as $t): ?>
                        <tr>
                            <td><a href="<?= e(url('admin/tickets.php?ticket_id=' . (int) $t['id'])) ?>"><?= e($t['ticket_no']) ?></a><br><small><?= e($t['subject']) ?></small></td>
                            <td><?= e($t['organization_name']) ?></td>
                            <td>
                                <?= e($requestTypeLabels[$t['request_type'] ?? 'none'] ?? 'General support') ?>
                                <?php if (!empty($t['decision_status']) && ($t['decision_status'] ?? 'pending') !== 'pending'): ?>
                                    <br><small><?= e($requestDecisionLabels[$t['decision_status']] ?? $t['decision_status']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= e($categoryLabels[$t['category']] ?? $t['category']) ?></td>
                            <td><span class="tag"><?= e($t['priority']) ?></span></td>
                            <td><span class="tag"><?= e($statusLabels[$t['status']] ?? $t['status']) ?></span></td>
                            <?php if ($hasTicketSla): ?>
                                <td>
                                    <?php
                                    $slaBadge = ['label' => 'No SLA', 'class' => 'sla-none'];
                                    if (!empty($t['resolved_at'])) {
                                        $slaBadge = ['label' => 'Resolved', 'class' => 'sla-ok'];
                                    } elseif (!empty($t['sla_due_at'])) {
                                        $hoursLeft = (strtotime((string) $t['sla_due_at']) - time()) / 3600;
                                        if ($hoursLeft < 0) {
                                            $slaBadge = ['label' => 'Breached', 'class' => 'sla-breach'];
                                        } elseif ($hoursLeft <= 8) {
                                            $slaBadge = ['label' => 'At Risk', 'class' => 'sla-risk'];
                                        } else {
                                            $slaBadge = ['label' => 'On Track', 'class' => 'sla-ok'];
                                        }
                                    }
                                    ?>
                                    <span class="sla-badge <?= e($slaBadge['class']) ?>"><?= e($slaBadge['label']) ?></span>
                                    <?php if (!empty($t['sla_due_at']) && empty($t['resolved_at'])): ?><br><small>Due <?= e(date('d M H:i', strtotime((string) $t['sla_due_at']))) ?></small><?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td><?= e($t['assigned_staff_name'] ?? 'Unassigned') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <div class="table-card">
        <h2><?= icon('tickets') ?><?= e($ticket['ticket_no']) ?> - <?= e($ticket['subject']) ?></h2>
        <p><a href="<?= e(url('admin/tickets.php')) ?>">&larr; Back to tickets</a> | Client: <?= e($ticket['organization_name']) ?></p>

        <div class="table-card">
            <p><strong>Category:</strong> <?= e($categoryLabels[$ticket['category']] ?? $ticket['category']) ?> | <strong>Priority:</strong> <?= e($ticket['priority']) ?></p>
            <?php if ($hasTicketSla): ?>
                <p><strong>SLA due:</strong> <?= e($ticket['sla_due_at'] ?? '-') ?> | <strong>Resolved:</strong> <?= e($ticket['resolved_at'] ?? '-') ?></p>
            <?php endif; ?>
            <p><strong>Request Type:</strong> <?= e($requestTypeLabels[$ticket['request_type'] ?? 'none'] ?? 'General support') ?></p>
            <?php if (($ticket['request_type'] ?? 'none') !== 'none'): ?>
                <p>
                    <strong>Request Details:</strong>
                    <?php if (!empty($ticket['target_task_id'])): ?>Task #<?= e((int) $ticket['target_task_id']) ?> <?php endif; ?>
                    <?php if (!empty($ticket['target_stage_id'])): ?>Stage #<?= e((int) $ticket['target_stage_id']) ?> <?php endif; ?>
                    <?php if (!empty($ticket['target_invoice_id'])): ?>Invoice #<?= e((int) $ticket['target_invoice_id']) ?> <?php endif; ?>
                    <?php if (!empty($ticket['requested_amount'])): ?>Amount <?= e(site_currency_symbol()) ?><?= e(number_format((float) $ticket['requested_amount'], 2)) ?> <?php endif; ?>
                    <?php if (!empty($ticket['requested_percent'])): ?>Percent <?= e(number_format((float) $ticket['requested_percent'], 2)) ?>% <?php endif; ?>
                    <?php if (!empty($ticket['requested_due_on'])): ?>Due <?= e($ticket['requested_due_on']) ?><?php endif; ?>
                </p>
                <p><strong>Decision Status:</strong> <?= e($requestDecisionLabels[$ticket['decision_status'] ?? 'pending'] ?? ($ticket['decision_status'] ?? 'pending')) ?></p>
            <?php endif; ?>
            <p><?= nl2br(e($ticket['description'])) ?></p>
        </div>

        <?php if ($role === 'admin' && ($ticket['request_type'] ?? 'none') !== 'none'): ?>
            <div class="table-card">
                <h3>Process request</h3>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="process_ticket_request">
                    <input type="hidden" name="ticket_id" value="<?= e((int) $ticket['id']) ?>">

                    <label>Decision
                        <select name="decision" required>
                            <option value="approved">Approve</option>
                            <option value="rejected">Reject</option>
                            <option value="negotiation">Negotiate</option>
                            <option value="deferred">Defer</option>
                        </select>
                    </label>
                    <label>Approved amount (optional)
                        <input type="number" name="approved_amount" min="0" step="0.01" value="<?= e((string) ($ticket['approved_amount'] ?? '')) ?>">
                    </label>
                    <label>Approved percent (optional)
                        <input type="number" name="approved_percent" min="0" max="100" step="0.01" value="<?= e((string) ($ticket['approved_percent'] ?? '')) ?>">
                    </label>
                    <label>Approved due date (optional)
                        <input type="date" name="approved_due_on" value="<?= e((string) ($ticket['approved_due_on'] ?? '')) ?>">
                    </label>
                    <label class="workspace-span-2">Admin note<textarea name="admin_note"><?= e((string) ($ticket['admin_note'] ?? '')) ?></textarea></label>
                    <div class="workspace-span-2">
                        <button type="submit"><?= icon('tickets') ?>Apply decision and notify client</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="table-card">
            <h3>Update ticket</h3>
            <form method="post" class="workspace-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_ticket">
                <input type="hidden" name="ticket_id" value="<?= e((int) $ticket['id']) ?>">
                <label>Status
                    <select name="status">
                        <?php foreach ($statusLabels as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $ticket['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Assigned staff
                    <select name="assigned_staff_user_id">
                        <option value="0">Unassigned</option>
                        <?php foreach ($staffUsers as $staffUser): ?>
                            <option value="<?= e((int) $staffUser['id']) ?>" <?= (int) ($ticket['assigned_staff_user_id'] ?? 0) === (int) $staffUser['id'] ? 'selected' : '' ?>><?= e($staffUser['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($hasTicketSla): ?>
                    <label>SLA due
                        <input type="datetime-local" name="sla_due_at" value="<?= e(!empty($ticket['sla_due_at']) ? date('Y-m-d\TH:i', strtotime((string) $ticket['sla_due_at'])) : '') ?>">
                    </label>
                <?php endif; ?>
                <label class="workspace-span-2">Resolution notes<textarea name="resolution"><?= e((string) ($ticket['resolution'] ?? '')) ?></textarea></label>
                <div class="workspace-span-2">
                    <button type="submit"><?= icon('tickets') ?>Save</button>
                </div>
            </form>
        </div>

        <div class="table-card">
            <h3>Conversation</h3>
            <?php foreach ($ticketMessages as $message): ?>
                <div style="padding:12px 0; border-bottom:1px solid var(--border);">
                    <strong><?= e($message['sender_name']) ?></strong> <small><?= e($message['created_at']) ?></small>
                    <p><?= nl2br(e($message['body'])) ?></p>
                    <?php if ($message['attachment_path']): ?>
                        <p><a href="<?= e(url('attachment-download.php?type=ticket_message&id=' . (int) $message['id'])) ?>">📎 <?= e((string) $message['attachment_name']) ?></a></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <form method="post" enctype="multipart/form-data" class="workspace-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="reply_ticket">
                <input type="hidden" name="ticket_id" value="<?= e((int) $ticket['id']) ?>">
                <label class="workspace-span-2">Reply<textarea name="body" required></textarea></label>
                <label class="workspace-span-2">Attachment (optional)<input type="file" name="attachment"></label>
                <div class="workspace-span-2">
                    <button type="submit"><?= icon('tickets') ?>Send reply</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/' . ($role === 'admin' ? 'admin_footer' : 'staff_footer') . '.php'; ?>
