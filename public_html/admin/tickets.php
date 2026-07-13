<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_or_admin();
require_permission('tickets', 'view');

$pageTitle = 'Support Tickets';
$pageSubtitle = 'Track, assign and resolve client support requests with SLA visibility';
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
            $result = apply_ticket_request_decision($request, $companyId, $approvedAmountValue, $approvedPercentValue, $approvedDueOnValue, $userId);
            $applyError = $result['error'];
            $appliedInvoiceId = $result['invoice_id'];
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

        $ticketStatus = 'waiting_for_client';
        if ($decision === 'rejected' || $decision === 'approved') {
            $ticketStatus = 'resolved';
        } elseif ($decision === 'deferred') {
            $ticketStatus = 'in_progress';
        }
        db()->prepare('UPDATE support_tickets SET status = :status WHERE id = :id AND company_id = :company_id')
            ->execute([
                'status' => $ticketStatus,
                'id' => $ticketId,
                'company_id' => $companyId,
            ]);

        $requestTypeLabel = ucwords(str_replace('_', ' ', (string) $request['request_type']));
        $currency = site_currency_symbol();
        $termsParts = [];
        if ($approvedAmountValue !== null) {
            $termsParts[] = 'amount ' . $currency . number_format($approvedAmountValue, 2);
        }
        if ($approvedPercentValue !== null) {
            $termsParts[] = $approvedPercentValue . '%';
        }
        if ($approvedDueOnValue !== null) {
            $termsParts[] = 'due date ' . $approvedDueOnValue;
        }
        $termsText = $termsParts !== [] ? ' (' . implode(', ', $termsParts) . ')' : '';

        if ($decision === 'approved') {
            $messageBody = 'Your ' . $requestTypeLabel . ' has been APPROVED and applied' . $termsText . '.';
        } elseif ($decision === 'rejected') {
            $messageBody = 'Your ' . $requestTypeLabel . ' has been REJECTED.';
        } elseif ($decision === 'negotiation') {
            $messageBody = 'COUNTER-OFFER on your ' . $requestTypeLabel . $termsText . ".\n"
                . 'If you agree with these terms, open this ticket in your portal and press "Accept counter-offer" — it will be applied immediately.';
        } else {
            $messageBody = 'Your ' . $requestTypeLabel . ' has been DEFERRED for now. We will come back to it.';
        }
        if ($adminNote !== '') {
            $messageBody .= "\nNote from our team: " . $adminNote;
        }
        if ($appliedInvoiceId) {
            $messageBody .= "\nReference invoice ID: #" . $appliedInvoiceId;
        }

        ticket_request_note($ticketId, $userId, $messageBody);
        notify_ticket_client_email($ticketId, 'Update on your request: ' . (string) $request['subject'], nl2br(e($messageBody)));

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

        // Pull the target invoice so the admin can judge the request against
        // the real figures and jump straight to the invoice desk.
        $requestInvoice = null;
        if (!empty($ticket['target_invoice_id'])) {
            $reqInvStmt = db()->prepare('SELECT id, invoice_no, status, taxable_amount, vat_amount, total_amount, discount_amount, due_on FROM task_invoices WHERE id = :id AND company_id = :company_id LIMIT 1');
            $reqInvStmt->execute(['id' => (int) $ticket['target_invoice_id'], 'company_id' => $companyId]);
            $requestInvoice = $reqInvStmt->fetch() ?: null;
        }
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
$pageSubtitle = $ticket
    ? 'Ticket ' . (string) $ticket['ticket_no'] . ' — ' . (string) $ticket['organization_name']
    : 'Track, assign and resolve client support requests with SLA visibility';

$statusTones = [
    'open' => 'tone-blue',
    'assigned' => 'tone-blue',
    'in_progress' => 'tone-amber',
    'waiting_for_client' => 'tone-amber',
    'resolved' => 'tone-green',
    'closed' => 'tone-gray',
];
$priorityTones = [
    'urgent' => 'tone-red',
    'high' => 'tone-red',
    'medium' => 'tone-amber',
    'normal' => 'tone-blue',
    'low' => 'tone-gray',
];
$decisionTones = [
    'pending' => 'tone-amber',
    'approved' => 'tone-green',
    'rejected' => 'tone-red',
    'negotiation' => 'tone-blue',
    'deferred' => 'tone-gray',
];

include __DIR__ . '/../../app/views/partials/' . ($role === 'admin' ? 'admin_header' : 'staff_header') . '.php';
?>

<?php if ($ticketId === 0): ?>
    <section class="mbw-card">
        <div class="mbw-card-head">
            <h2>Filters</h2>
        </div>
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
    </section>

    <section class="mbw-card">
        <div class="mbw-card-head">
            <h2>Support tickets</h2>
        </div>
        <div style="overflow-x:auto">
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
                                    <br><span class="mbw-pill <?= e($decisionTones[$t['decision_status']] ?? 'tone-gray') ?>"><?= e($requestDecisionLabels[$t['decision_status']] ?? $t['decision_status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($categoryLabels[$t['category']] ?? $t['category']) ?></td>
                            <td><span class="mbw-pill <?= e($priorityTones[strtolower((string) $t['priority'])] ?? 'tone-gray') ?>"><?= e($t['priority']) ?></span></td>
                            <td><span class="mbw-pill <?= e($statusTones[$t['status']] ?? 'tone-gray') ?>"><?= e($statusLabels[$t['status']] ?? $t['status']) ?></span></td>
                            <?php if ($hasTicketSla): ?>
                                <td>
                                    <?php
                                    $slaBadge = ['label' => 'No SLA', 'class' => 'tone-gray'];
                                    if (!empty($t['resolved_at'])) {
                                        $slaBadge = ['label' => 'Resolved', 'class' => 'tone-green'];
                                    } elseif (!empty($t['sla_due_at'])) {
                                        $hoursLeft = (strtotime((string) $t['sla_due_at']) - time()) / 3600;
                                        if ($hoursLeft < 0) {
                                            $slaBadge = ['label' => 'Breached', 'class' => 'tone-red'];
                                        } elseif ($hoursLeft <= 8) {
                                            $slaBadge = ['label' => 'At Risk', 'class' => 'tone-amber'];
                                        } else {
                                            $slaBadge = ['label' => 'On Track', 'class' => 'tone-green'];
                                        }
                                    }
                                    ?>
                                    <span class="mbw-pill <?= e($slaBadge['class']) ?>"><?= e($slaBadge['label']) ?></span>
                                    <?php if (!empty($t['sla_due_at']) && empty($t['resolved_at'])): ?><br><small>Due <?= e(date('d M H:i', strtotime((string) $t['sla_due_at']))) ?></small><?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td><?= e($t['assigned_staff_name'] ?? 'Unassigned') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php else: ?>
    <section class="mbw-card">
        <div class="mbw-card-head">
            <h2><?= e($ticket['ticket_no']) ?> - <?= e($ticket['subject']) ?></h2>
            <div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('admin/tickets.php')) ?>">&larr; Back to tickets</a></div>
        </div>
        <p style="color:var(--mbw-muted);">Client: <?= e($ticket['organization_name']) ?></p>
        <p>
            <strong>Category:</strong> <?= e($categoryLabels[$ticket['category']] ?? $ticket['category']) ?>
            | <strong>Priority:</strong> <span class="mbw-pill <?= e($priorityTones[strtolower((string) $ticket['priority'])] ?? 'tone-gray') ?>"><?= e($ticket['priority']) ?></span>
            | <strong>Status:</strong> <span class="mbw-pill <?= e($statusTones[$ticket['status']] ?? 'tone-gray') ?>"><?= e($statusLabels[$ticket['status']] ?? $ticket['status']) ?></span>
        </p>
            <?php if ($hasTicketSla): ?>
                <p><strong>SLA due:</strong> <?= e($ticket['sla_due_at'] ?? '-') ?> | <strong>Resolved:</strong> <?= e($ticket['resolved_at'] ?? '-') ?></p>
            <?php endif; ?>
            <p><strong>Request Type:</strong> <?= e($requestTypeLabels[$ticket['request_type'] ?? 'none'] ?? 'General support') ?></p>
            <?php if (($ticket['request_type'] ?? 'none') !== 'none'): ?>
                <p>
                    <strong>Request Details:</strong>
                    <?php if (!empty($ticket['target_task_id'])): ?>Task #<?= e((int) $ticket['target_task_id']) ?> <?php endif; ?>
                    <?php if (!empty($ticket['target_stage_id'])): ?>Stage #<?= e((int) $ticket['target_stage_id']) ?> <?php endif; ?>
                    <?php if (!empty($ticket['requested_amount'])): ?>Amount <?= e(site_currency_symbol()) ?><?= e(number_format((float) $ticket['requested_amount'], 2)) ?> <?php endif; ?>
                    <?php if (!empty($ticket['requested_percent'])): ?>Percent <?= e(number_format((float) $ticket['requested_percent'], 2)) ?>% <?php endif; ?>
                    <?php if (!empty($ticket['requested_due_on'])): ?>Due <?= e($ticket['requested_due_on']) ?><?php endif; ?>
                </p>
                <?php if (!empty($requestInvoice)): ?>
                    <p>
                        <strong>Target Invoice:</strong>
                        <?= e($requestInvoice['invoice_no']) ?>
                        — taxable <?= e(site_currency_symbol()) ?><?= e(number_format((float) $requestInvoice['taxable_amount'], 2)) ?>,
                        total <?= e(site_currency_symbol()) ?><?= e(number_format((float) $requestInvoice['total_amount'], 2)) ?>
                        <?php if ((float) ($requestInvoice['discount_amount'] ?? 0) > 0): ?>, discount already given <?= e(site_currency_symbol()) ?><?= e(number_format((float) $requestInvoice['discount_amount'], 2)) ?><?php endif; ?>
                        <?php if (!empty($requestInvoice['due_on'])): ?>, due <?= e((string) $requestInvoice['due_on']) ?><?php endif; ?>
                        (<?= e((string) $requestInvoice['status']) ?>)
                        &nbsp;<a class="mbw-view-all" href="<?= e(url('admin/invoice.php?invoice_id=' . (int) $requestInvoice['id'])) ?>">Open invoice desk</a>
                    </p>
                <?php elseif (!empty($ticket['target_invoice_id'])): ?>
                    <p><strong>Target Invoice:</strong> #<?= e((int) $ticket['target_invoice_id']) ?></p>
                <?php endif; ?>
                <p><strong>Decision Status:</strong> <span class="mbw-pill <?= e($decisionTones[$ticket['decision_status'] ?? 'pending'] ?? 'tone-gray') ?>"><?= e($requestDecisionLabels[$ticket['decision_status'] ?? 'pending'] ?? ($ticket['decision_status'] ?? 'pending')) ?></span></p>
            <?php endif; ?>
            <p><?= nl2br(e($ticket['description'])) ?></p>
    </section>

        <?php if ($role === 'admin' && ($ticket['request_type'] ?? 'none') !== 'none'): ?>
            <section class="mbw-card">
                <div class="mbw-card-head">
                    <h2>Process request</h2>
                </div>
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
                    <label>Approved amount (prefilled with the client's ask — change it to counter)
                        <input type="number" name="approved_amount" min="0" step="0.01" value="<?= e((string) ($ticket['approved_amount'] ?? $ticket['requested_amount'] ?? '')) ?>">
                    </label>
                    <label>Approved percent
                        <input type="number" name="approved_percent" min="0" max="100" step="0.01" value="<?= e((string) ($ticket['approved_percent'] ?? $ticket['requested_percent'] ?? '')) ?>">
                    </label>
                    <label>Approved due date
                        <input type="date" name="approved_due_on" value="<?= e((string) ($ticket['approved_due_on'] ?? $ticket['requested_due_on'] ?? '')) ?>">
                    </label>
                    <label class="workspace-span-2">Admin note<textarea name="admin_note"><?= e((string) ($ticket['admin_note'] ?? '')) ?></textarea></label>
                    <div class="workspace-span-2">
                        <button type="submit"><?= icon('tickets') ?>Apply decision and notify client</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="mbw-card">
            <div class="mbw-card-head">
                <h2>Update ticket</h2>
            </div>
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
        </section>

        <section class="mbw-card">
            <div class="mbw-card-head">
                <h2>Conversation</h2>
            </div>
            <?php foreach ($ticketMessages as $message): ?>
                <div style="padding:12px 0; border-bottom:1px solid var(--mbw-border);">
                    <strong style="color:var(--mbw-heading);"><?= e($message['sender_name']) ?></strong> <small style="color:var(--mbw-muted);"><?= e($message['created_at']) ?></small>
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
        </section>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/' . ($role === 'admin' ? 'admin_footer' : 'staff_footer') . '.php'; ?>
