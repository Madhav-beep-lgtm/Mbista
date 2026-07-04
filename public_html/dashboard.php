<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

require_login();
$pageTitle = 'Dashboard';
$user = current_user();
$userId = (int) $user['id'];

$allowedViews = ['home', 'tasks', 'contracts', 'invoices', 'documents', 'document-requests', 'compliance', 'messages', 'tickets'];
$view = (string) ($_GET['view'] ?? 'home');
if (!in_array($view, $allowedViews, true)) {
    $view = 'home';
}

$clientProfile = null;
$teamMembers = [];
$contracts = [];
$tasks = [];
$stagesByTask = [];
$invoices = [];
$documents = [];
$documentRequests = [];
$complianceDeadlines = [];
$upcomingComplianceCount = 0;
$unreadMessageCount = 0;
$openTicketCount = 0;
$threads = [];
$thread = null;
$threadMessages = [];
$tickets = [];
$ticket = null;
$ticketMessages = [];
$hasTicketRequests = table_exists('support_ticket_requests');
$hasPaymentResponses = column_exists('invoice_payment_requests', 'client_declared_status');
$paymentRequestsByInvoice = [];
$pendingPaymentResponseCount = 0;

if (table_exists('client_profiles')) {
    $stmt = db()->prepare('SELECT cp.*, i.name AS industry_name
        FROM client_profiles cp
        LEFT JOIN industries i ON i.id = cp.industry_id
        WHERE cp.user_id = :user_id
        LIMIT 1');
    $stmt->execute(['user_id' => $userId]);
    $clientProfile = $stmt->fetch() ?: null;
}

if ($clientProfile && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $clientId = (int) $clientProfile['id'];

    if ($action === 'respond_document_request') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $clientComment = trim((string) ($_POST['client_comment'] ?? ''));

        $reqStmt = db()->prepare("SELECT * FROM document_requests WHERE id = :id AND client_id = :client_id AND status IN ('requested', 'rejected') LIMIT 1");
        $reqStmt->execute(['id' => $requestId, 'client_id' => $clientId]);
        $docRequest = $reqStmt->fetch();

        if (!$docRequest) {
            flash('error', 'That document request is not available for a response.');
            redirect('dashboard.php?view=document-requests');
        }

        if (!isset($_FILES['file'])) {
            flash('error', 'Choose a file to upload.');
            redirect('dashboard.php?view=document-requests');
        }

        $uploadResult = handle_document_upload($_FILES['file'], (int) $docRequest['company_id'], $clientId);
        if (!$uploadResult['ok']) {
            flash('error', $uploadResult['error']);
            redirect('dashboard.php?view=document-requests');
        }

        $insertStmt = db()->prepare('INSERT INTO documents (company_id, client_id, task_id, category, title, original_file_name, stored_file_name, file_path, file_type, file_size, visibility, uploaded_by) VALUES (:company_id, :client_id, :task_id, :category, :title, :original_file_name, :stored_file_name, :file_path, :file_type, :file_size, :visibility, :uploaded_by)');
        $insertStmt->execute([
            'company_id' => (int) $docRequest['company_id'],
            'client_id' => $clientId,
            'task_id' => $docRequest['task_id'],
            'category' => 'other',
            'title' => $docRequest['title'],
            'original_file_name' => $uploadResult['original_file_name'],
            'stored_file_name' => $uploadResult['stored_file_name'],
            'file_path' => $uploadResult['file_path'],
            'file_type' => $uploadResult['file_type'],
            'file_size' => $uploadResult['file_size'],
            'visibility' => 'client',
            'uploaded_by' => $userId,
        ]);
        $newDocumentId = (int) db()->lastInsertId();

        $updateStmt = db()->prepare("UPDATE document_requests SET status = 'uploaded', document_id = :document_id, client_comment = :client_comment WHERE id = :id");
        $updateStmt->execute([
            'document_id' => $newDocumentId,
            'client_comment' => $clientComment !== '' ? $clientComment : null,
            'id' => $requestId,
        ]);

        log_activity('document_request', $requestId, 'uploaded', 'Client uploaded a response document.', $userId);
        flash('success', 'Document uploaded successfully.');
        redirect('dashboard.php?view=document-requests');
    }

    if ($action === 'create_thread') {
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));

        if ($subject === '' || $body === '') {
            flash('error', 'Subject and message are required.');
            redirect('dashboard.php?view=messages');
        }

        $uploadResult = [];
        if (isset($_FILES['attachment']) && (int) $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = handle_message_attachment_upload($_FILES['attachment'], 'new');
            if (!$uploadResult['ok']) {
                flash('error', $uploadResult['error']);
                redirect('dashboard.php?view=messages');
            }
        }

        $newThreadId = create_message_thread((int) $clientProfile['company_id'], $clientId, $subject, $userId, $body, $uploadResult);
        log_activity('message_thread', $newThreadId, 'created', 'Message thread created by client.', $userId);
        flash('success', 'Message sent.');
        redirect('dashboard.php?view=messages&thread_id=' . $newThreadId);
    }

    if ($action === 'send_message') {
        $threadId = (int) ($_POST['thread_id'] ?? 0);
        $body = trim((string) ($_POST['body'] ?? ''));

        $participantStmt = db()->prepare('SELECT id FROM message_thread_participants WHERE thread_id = :thread_id AND user_id = :user_id LIMIT 1');
        $participantStmt->execute(['thread_id' => $threadId, 'user_id' => $userId]);
        if (!$participantStmt->fetch()) {
            flash('error', 'You do not have access to that thread.');
            redirect('dashboard.php?view=messages');
        }
        if ($body === '') {
            flash('error', 'Message cannot be empty.');
            redirect('dashboard.php?view=messages&thread_id=' . $threadId);
        }

        $uploadResult = [];
        if (isset($_FILES['attachment']) && (int) $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = handle_message_attachment_upload($_FILES['attachment'], (string) $threadId);
            if (!$uploadResult['ok']) {
                flash('error', $uploadResult['error']);
                redirect('dashboard.php?view=messages&thread_id=' . $threadId);
            }
        }

        $stmt = db()->prepare('INSERT INTO messages (thread_id, sender_id, body, attachment_path, attachment_name) VALUES (:thread_id, :sender_id, :body, :attachment_path, :attachment_name)');
        $stmt->execute([
            'thread_id' => $threadId,
            'sender_id' => $userId,
            'body' => $body,
            'attachment_path' => $uploadResult['file_path'] ?? null,
            'attachment_name' => $uploadResult['original_file_name'] ?? null,
        ]);
        db()->prepare('UPDATE message_threads SET updated_at = NOW() WHERE id = :id')->execute(['id' => $threadId]);
        db()->prepare('UPDATE message_thread_participants SET last_read_at = NOW() WHERE thread_id = :thread_id AND user_id = :user_id')
            ->execute(['thread_id' => $threadId, 'user_id' => $userId]);

        log_activity('message_thread', $threadId, 'replied', 'Message sent by client.', $userId);
        redirect('dashboard.php?view=messages&thread_id=' . $threadId);
    }

    if ($action === 'create_ticket') {
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $category = (string) ($_POST['category'] ?? 'general');
        $priority = (string) ($_POST['priority'] ?? 'normal');
        $description = trim((string) ($_POST['description'] ?? ''));
        $requestType = (string) ($_POST['request_type'] ?? 'none');
        $targetTaskId = (int) ($_POST['target_task_id'] ?? 0);
        $targetStageId = (int) ($_POST['target_stage_id'] ?? 0);
        $targetInvoiceId = (int) ($_POST['target_invoice_id'] ?? 0);
        $requestedAmount = trim((string) ($_POST['requested_amount'] ?? ''));
        $requestedPercent = trim((string) ($_POST['requested_percent'] ?? ''));
        $requestedDueOn = trim((string) ($_POST['requested_due_on'] ?? ''));
        $allowedCategories = ['general', 'technical', 'billing', 'document', 'other'];
        $allowedPriorities = ['low', 'normal', 'high', 'urgent'];
        $allowedRequestTypes = ['none', 'invoice_request', 'discount_request', 'credit_period_request', 'advance_payment_request', 'partial_payment_request'];

        if ($subject === '' || $description === '') {
            flash('error', 'Subject and description are required.');
            redirect('dashboard.php?view=tickets');
        }
        if (!in_array($category, $allowedCategories, true)) {
            $category = 'general';
        }
        if (!in_array($priority, $allowedPriorities, true)) {
            $priority = 'normal';
        }
        if (!in_array($requestType, $allowedRequestTypes, true)) {
            $requestType = 'none';
        }

        $requestedAmountValue = $requestedAmount !== '' && is_numeric($requestedAmount) ? round((float) $requestedAmount, 2) : null;
        $requestedPercentValue = $requestedPercent !== '' && is_numeric($requestedPercent) ? round((float) $requestedPercent, 2) : null;

        $uploadResult = [];
        if (isset($_FILES['attachment']) && (int) $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = handle_message_attachment_upload($_FILES['attachment'], 'new');
            if (!$uploadResult['ok']) {
                flash('error', $uploadResult['error']);
                redirect('dashboard.php?view=tickets');
            }
        }

        $ticketNo = next_ticket_number((int) $clientProfile['company_id']);
        $stmt = db()->prepare('INSERT INTO support_tickets (company_id, client_id, ticket_no, subject, category, priority, description) VALUES (:company_id, :client_id, :ticket_no, :subject, :category, :priority, :description)');
        $stmt->execute([
            'company_id' => (int) $clientProfile['company_id'],
            'client_id' => $clientId,
            'ticket_no' => $ticketNo,
            'subject' => $subject,
            'category' => $category,
            'priority' => $priority,
            'description' => $description,
        ]);
        $newTicketId = (int) db()->lastInsertId();

        if ($requestType !== 'none' && table_exists('support_ticket_requests')) {
            $requestStmt = db()->prepare('INSERT INTO support_ticket_requests (ticket_id, company_id, client_id, request_type, target_task_id, target_stage_id, target_invoice_id, requested_amount, requested_percent, requested_due_on) VALUES (:ticket_id, :company_id, :client_id, :request_type, :target_task_id, :target_stage_id, :target_invoice_id, :requested_amount, :requested_percent, :requested_due_on)');
            $requestStmt->execute([
                'ticket_id' => $newTicketId,
                'company_id' => (int) $clientProfile['company_id'],
                'client_id' => $clientId,
                'request_type' => $requestType,
                'target_task_id' => $targetTaskId > 0 ? $targetTaskId : null,
                'target_stage_id' => $targetStageId > 0 ? $targetStageId : null,
                'target_invoice_id' => $targetInvoiceId > 0 ? $targetInvoiceId : null,
                'requested_amount' => $requestedAmountValue,
                'requested_percent' => $requestedPercentValue,
                'requested_due_on' => $requestedDueOn !== '' ? $requestedDueOn : null,
            ]);
        }

        if (!empty($uploadResult['file_path'])) {
            $msgStmt = db()->prepare('INSERT INTO support_ticket_messages (ticket_id, sender_id, body, attachment_path, attachment_name) VALUES (:ticket_id, :sender_id, :body, :attachment_path, :attachment_name)');
            $msgStmt->execute([
                'ticket_id' => $newTicketId,
                'sender_id' => $userId,
                'body' => $description,
                'attachment_path' => $uploadResult['file_path'],
                'attachment_name' => $uploadResult['original_file_name'],
            ]);
        }

        log_activity('support_ticket', $newTicketId, 'created', 'Support ticket created by client.', $userId);
        flash('success', 'Ticket ' . $ticketNo . ' created.');
        redirect('dashboard.php?view=tickets&ticket_id=' . $newTicketId);
    }

    if ($action === 'reply_ticket') {
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $body = trim((string) ($_POST['body'] ?? ''));

        $ticketStmt = db()->prepare('SELECT * FROM support_tickets WHERE id = :id AND client_id = :client_id LIMIT 1');
        $ticketStmt->execute(['id' => $ticketId, 'client_id' => $clientId]);
        $ticket = $ticketStmt->fetch();
        if (!$ticket) {
            flash('error', 'That ticket is not available.');
            redirect('dashboard.php?view=tickets');
        }
        if ($body === '') {
            flash('error', 'Reply cannot be empty.');
            redirect('dashboard.php?view=tickets&ticket_id=' . $ticketId);
        }

        $uploadResult = [];
        if (isset($_FILES['attachment']) && (int) $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = handle_message_attachment_upload($_FILES['attachment'], 'tickets/' . $ticketId);
            if (!$uploadResult['ok']) {
                flash('error', $uploadResult['error']);
                redirect('dashboard.php?view=tickets&ticket_id=' . $ticketId);
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
        log_activity('support_ticket', $ticketId, 'replied', 'Client reply added.', $userId);
        redirect('dashboard.php?view=tickets&ticket_id=' . $ticketId);
    }

    if ($action === 'declare_payment' && column_exists('invoice_payment_requests', 'client_declared_status')) {
        $paymentRequestId = (int) ($_POST['payment_request_id'] ?? 0);
        $declaredStatus = (string) ($_POST['declared_status'] ?? '');
        $declaredAmount = trim((string) ($_POST['declared_amount'] ?? ''));
        $declaredMethod = trim((string) ($_POST['declared_method'] ?? ''));
        $declaredReference = trim((string) ($_POST['declared_reference'] ?? ''));
        $declaredOn = trim((string) ($_POST['declared_on'] ?? ''));
        $declaredNote = trim((string) ($_POST['declared_note'] ?? ''));

        $prStmt = db()->prepare("SELECT ipr.* FROM invoice_payment_requests ipr
            INNER JOIN task_invoices ti ON ti.id = ipr.invoice_id
            INNER JOIN client_tasks ct ON ct.id = ti.task_id
            WHERE ipr.id = :id AND ct.client_id = :client_id AND ipr.status IN ('pending', 'partial')
            LIMIT 1");
        $prStmt->execute(['id' => $paymentRequestId, 'client_id' => $clientId]);
        $paymentRequest = $prStmt->fetch();

        if (!$paymentRequest) {
            flash('error', 'That payment request is not available for a response.');
            redirect('dashboard.php?view=invoices');
        }
        if (!in_array($declaredStatus, ['partial', 'complete'], true) || $declaredAmount === '' || !is_numeric($declaredAmount) || (float) $declaredAmount <= 0 || $declaredOn === '') {
            flash('error', 'Payment type, a valid amount, and the payment date are required.');
            redirect('dashboard.php?view=invoices');
        }

        db()->prepare('UPDATE invoice_payment_requests SET
                client_declared_status = :declared_status,
                client_declared_amount = :declared_amount,
                client_declared_method = :declared_method,
                client_declared_reference = :declared_reference,
                client_declared_on = :declared_on,
                client_declared_note = :declared_note,
                client_declared_at = NOW()
            WHERE id = :id')
            ->execute([
                'declared_status' => $declaredStatus,
                'declared_amount' => round((float) $declaredAmount, 2),
                'declared_method' => $declaredMethod !== '' ? $declaredMethod : null,
                'declared_reference' => $declaredReference !== '' ? $declaredReference : null,
                'declared_on' => $declaredOn,
                'declared_note' => $declaredNote !== '' ? $declaredNote : null,
                'id' => $paymentRequestId,
            ]);

        log_activity('invoice_payment_request', $paymentRequestId, 'client_declared', 'Client declared ' . $declaredStatus . ' payment.', $userId);
        flash('success', 'Payment details submitted. Your service provider will verify and confirm the payment.');
        redirect('dashboard.php?view=invoices');
    }

    if ($action === 'request_invoice_adjustment' && table_exists('support_ticket_requests')) {
        $targetInvoiceId = (int) ($_POST['target_invoice_id'] ?? 0);
        $adjustmentType = (string) ($_POST['adjustment_type'] ?? '');
        $requestedDueOn = trim((string) ($_POST['requested_due_on'] ?? ''));
        $requestedAmount = trim((string) ($_POST['requested_amount'] ?? ''));
        $requestedPercent = trim((string) ($_POST['requested_percent'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));

        $invStmt = db()->prepare('SELECT ti.* FROM task_invoices ti
            INNER JOIN client_tasks ct ON ct.id = ti.task_id
            WHERE ti.id = :id AND ct.client_id = :client_id LIMIT 1');
        $invStmt->execute(['id' => $targetInvoiceId, 'client_id' => $clientId]);
        $targetInvoice = $invStmt->fetch();

        if (!$targetInvoice) {
            flash('error', 'That invoice is not available.');
            redirect('dashboard.php?view=invoices');
        }
        if (!in_array($adjustmentType, ['credit_period_request', 'discount_request'], true)) {
            flash('error', 'Choose a valid request type.');
            redirect('dashboard.php?view=invoices');
        }
        if ($reason === '') {
            flash('error', 'Please give a reason for your request.');
            redirect('dashboard.php?view=invoices');
        }
        if ($adjustmentType === 'credit_period_request' && $requestedDueOn === '') {
            flash('error', 'Choose the new due date you are requesting.');
            redirect('dashboard.php?view=invoices');
        }
        if ($adjustmentType === 'discount_request' && $requestedAmount === '' && $requestedPercent === '') {
            flash('error', 'Enter the discount amount or percentage you are requesting.');
            redirect('dashboard.php?view=invoices');
        }

        $typeLabel = $adjustmentType === 'credit_period_request' ? 'Additional credit period' : 'Discount';
        $ticketNo = next_ticket_number((int) $clientProfile['company_id']);
        $stmt = db()->prepare('INSERT INTO support_tickets (company_id, client_id, ticket_no, subject, category, priority, description) VALUES (:company_id, :client_id, :ticket_no, :subject, :category, :priority, :description)');
        $stmt->execute([
            'company_id' => (int) $clientProfile['company_id'],
            'client_id' => $clientId,
            'ticket_no' => $ticketNo,
            'subject' => $typeLabel . ' request for invoice ' . (string) $targetInvoice['invoice_no'],
            'category' => 'billing',
            'priority' => 'normal',
            'description' => $reason,
        ]);
        $newTicketId = (int) db()->lastInsertId();

        db()->prepare('INSERT INTO support_ticket_requests (ticket_id, company_id, client_id, request_type, target_task_id, target_invoice_id, requested_amount, requested_percent, requested_due_on) VALUES (:ticket_id, :company_id, :client_id, :request_type, :target_task_id, :target_invoice_id, :requested_amount, :requested_percent, :requested_due_on)')
            ->execute([
                'ticket_id' => $newTicketId,
                'company_id' => (int) $clientProfile['company_id'],
                'client_id' => $clientId,
                'request_type' => $adjustmentType,
                'target_task_id' => (int) $targetInvoice['task_id'] > 0 ? (int) $targetInvoice['task_id'] : null,
                'target_invoice_id' => $targetInvoiceId,
                'requested_amount' => $requestedAmount !== '' && is_numeric($requestedAmount) ? round((float) $requestedAmount, 2) : null,
                'requested_percent' => $requestedPercent !== '' && is_numeric($requestedPercent) ? round((float) $requestedPercent, 2) : null,
                'requested_due_on' => $requestedDueOn !== '' ? $requestedDueOn : null,
            ]);

        log_activity('support_ticket', $newTicketId, 'created', $typeLabel . ' request raised from invoice view.', $userId);
        flash('success', $typeLabel . ' request sent (ticket ' . $ticketNo . '). Track the decision under Tickets.');
        redirect('dashboard.php?view=tickets&ticket_id=' . $newTicketId);
    }
}

if ($clientProfile) {
    $clientId = (int) $clientProfile['id'];
    $assignedTeamId = 0;

    if ($assignedTeamId > 0 && table_exists('team_members')) {
        $stmt = db()->prepare("SELECT u.name, u.email, tmem.member_role
            FROM team_members tmem
            INNER JOIN users u ON u.id = tmem.user_id
            WHERE tmem.team_id = :team_id
            ORDER BY (tmem.member_role = 'leader') DESC, u.name ASC");
        $stmt->execute(['team_id' => $assignedTeamId]);
        $teamMembers = $stmt->fetchAll();
    }

    if (table_exists('service_contracts')) {
        $stmt = db()->prepare('SELECT * FROM service_contracts WHERE client_id = :client_id ORDER BY created_at DESC');
        $stmt->execute(['client_id' => $clientId]);
        $contracts = $stmt->fetchAll();
    }

    if (table_exists('client_tasks')) {
        $taskAssignedSelect = column_exists('client_tasks', 'assigned_staff_user_id') ? ', au.name AS task_staff_name' : ', NULL AS task_staff_name';
        $taskAssignedJoin = column_exists('client_tasks', 'assigned_staff_user_id') ? ' LEFT JOIN users au ON au.id = t.assigned_staff_user_id' : '';
        $stmt = db()->prepare("SELECT t.*, tm.name AS team_name, sc.contract_no{$taskAssignedSelect},
                COALESCE((SELECT SUM(ti.amount) FROM task_invoices ti WHERE ti.task_id = t.id AND ti.status <> 'cancelled'), 0) AS invoiced_total
            FROM client_tasks t
            LEFT JOIN teams tm ON tm.id = t.team_id
            LEFT JOIN service_contracts sc ON sc.id = t.contract_id{$taskAssignedJoin}
            WHERE t.client_id = :client_id
            ORDER BY t.created_at DESC");
        $stmt->execute(['client_id' => $clientId]);
        $tasks = $stmt->fetchAll();

        if ($tasks !== []) {
            $taskIds = array_map('intval', array_column($tasks, 'id'));
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));

            if (table_exists('task_stages')) {
                $stmt = db()->prepare("SELECT * FROM task_stages WHERE task_id IN ($placeholders) ORDER BY task_id ASC, sequence_no ASC");
                $stmt->execute($taskIds);
                foreach ($stmt->fetchAll() as $stage) {
                    $stagesByTask[(int) $stage['task_id']][] = $stage;
                }
            }

            if (table_exists('task_invoices')) {
                $stmt = db()->prepare("SELECT ti.*, t.title AS task_title, ts.stage_name
                    FROM task_invoices ti
                    INNER JOIN client_tasks t ON t.id = ti.task_id
                    LEFT JOIN task_stages ts ON ts.id = ti.stage_id
                    WHERE ti.task_id IN ($placeholders)
                    ORDER BY ti.created_at DESC");
                $stmt->execute($taskIds);
                $invoices = $stmt->fetchAll();

                if ($invoices !== [] && $hasPaymentResponses) {
                    $invoiceIds = array_map('intval', array_column($invoices, 'id'));
                    $invPlaceholders = implode(',', array_fill(0, count($invoiceIds), '?'));
                    $stmt = db()->prepare("SELECT * FROM invoice_payment_requests WHERE invoice_id IN ($invPlaceholders) ORDER BY requested_on DESC");
                    $stmt->execute($invoiceIds);
                    foreach ($stmt->fetchAll() as $paymentRequestRow) {
                        $paymentRequestsByInvoice[(int) $paymentRequestRow['invoice_id']][] = $paymentRequestRow;
                        if (in_array((string) $paymentRequestRow['status'], ['pending', 'partial'], true)
                            && (string) ($paymentRequestRow['client_declared_status'] ?? 'none') === 'none') {
                            $pendingPaymentResponseCount++;
                        }
                    }
                }
            }
        }
    }

    if (table_exists('documents')) {
        $stmt = db()->prepare("SELECT * FROM documents WHERE client_id = :client_id AND visibility = 'client' AND is_active = 1 ORDER BY created_at DESC");
        $stmt->execute(['client_id' => $clientId]);
        $allClientDocuments = $stmt->fetchAll();

        $latestByLineage = [];
        foreach ($allClientDocuments as $doc) {
            $lineageId = (int) ($doc['parent_document_id'] ?: $doc['id']);
            if (!isset($latestByLineage[$lineageId]) || (int) $doc['version_number'] > (int) $latestByLineage[$lineageId]['version_number']) {
                $latestByLineage[$lineageId] = $doc;
            }
        }
        $documents = array_values($latestByLineage);
    }

    if (table_exists('document_requests')) {
        $stmt = db()->prepare('SELECT * FROM document_requests WHERE client_id = :client_id ORDER BY created_at DESC');
        $stmt->execute(['client_id' => $clientId]);
        $documentRequests = $stmt->fetchAll();
    }

    if (table_exists('compliance_deadlines')) {
        $stmt = db()->prepare('SELECT cd.*, ct.name AS compliance_type_name, d.title AS document_title
            FROM compliance_deadlines cd
            INNER JOIN compliance_types ct ON ct.id = cd.compliance_type_id
            LEFT JOIN documents d ON d.id = cd.document_id
            WHERE cd.client_id = :client_id
            ORDER BY cd.statutory_due_date ASC');
        $stmt->execute(['client_id' => $clientId]);
        $rawComplianceDeadlines = $stmt->fetchAll();

        foreach ($rawComplianceDeadlines as $deadline) {
            $deadline['effective_status'] = compliance_effective_status($deadline);
            $complianceDeadlines[] = $deadline;
            if (in_array($deadline['effective_status'], ['upcoming', 'overdue'], true)) {
                $upcomingComplianceCount++;
            }
        }
    }

    if (table_exists('message_thread_participants')) {
        $unreadMessageCount = unread_message_thread_count($userId);
    }

    if (table_exists('support_tickets')) {
        $ticketStmt = db()->prepare("SELECT COUNT(*) FROM support_tickets WHERE client_id = :client_id AND status NOT IN ('resolved', 'closed')");
        $ticketStmt->execute(['client_id' => $clientId]);
        $openTicketCount = (int) $ticketStmt->fetchColumn();
    }
}

function task_progress_percent(array $task, array $stages): int
{
    if ($stages !== []) {
        $completed = 0;
        foreach ($stages as $stage) {
            if (($stage['status'] ?? '') === 'completed') {
                $completed++;
            }
        }

        return (int) round(($completed / count($stages)) * 100);
    }

    return match ($task['status'] ?? 'new') {
        'completed' => 100,
        'in_progress' => 50,
        'on_hold' => 25,
        default => 0,
    };
}

$documentCategoryLabels = [
    'registration_kyc' => 'Registration & KYC',
    'accounting_records' => 'Accounting records',
    'tax_documents' => 'Tax documents',
    'audit_documents' => 'Audit documents',
    'reports' => 'Reports',
    'certificates' => 'Certificates',
    'invoices_receipts' => 'Invoices & receipts',
    'correspondence' => 'Correspondence',
    'other' => 'Other',
];

$complianceStatusLabels = [
    'not_started' => 'Not started',
    'upcoming' => 'Upcoming',
    'in_progress' => 'In progress',
    'waiting_for_client' => 'Waiting for client',
    'filed' => 'Filed',
    'completed' => 'Completed',
    'overdue' => 'Overdue',
    'not_applicable' => 'Not applicable',
];

$ticketCategoryLabels = [
    'general' => 'General',
    'technical' => 'Technical',
    'billing' => 'Billing',
    'document' => 'Document',
    'other' => 'Other',
];
$ticketRequestTypeLabels = ticket_request_type_labels();
$ticketStatusLabels = [
    'open' => 'Open',
    'assigned' => 'Assigned',
    'in_progress' => 'In progress',
    'waiting_for_client' => 'Waiting for client',
    'resolved' => 'Resolved',
    'closed' => 'Closed',
];

if ($clientProfile && $view === 'messages') {
    $clientId = (int) $clientProfile['id'];
    $requestedThreadId = (int) ($_GET['thread_id'] ?? 0);

    if ($requestedThreadId > 0) {
        $participantCheck = db()->prepare('SELECT id FROM message_thread_participants WHERE thread_id = :thread_id AND user_id = :user_id LIMIT 1');
        $participantCheck->execute(['thread_id' => $requestedThreadId, 'user_id' => $userId]);
        if ($participantCheck->fetch()) {
            $threadStmt = db()->prepare('SELECT * FROM message_threads WHERE id = :id LIMIT 1');
            $threadStmt->execute(['id' => $requestedThreadId]);
            $thread = $threadStmt->fetch() ?: null;

            if ($thread) {
                db()->prepare('UPDATE message_thread_participants SET last_read_at = NOW() WHERE thread_id = :thread_id AND user_id = :user_id')
                    ->execute(['thread_id' => $requestedThreadId, 'user_id' => $userId]);

                $msgStmt = db()->prepare('SELECT m.*, u.name AS sender_name FROM messages m INNER JOIN users u ON u.id = m.sender_id WHERE m.thread_id = :thread_id ORDER BY m.created_at ASC');
                $msgStmt->execute(['thread_id' => $requestedThreadId]);
                $threadMessages = $msgStmt->fetchAll();
            }
        } else {
            flash('error', 'You do not have access to that thread.');
        }
    }

    if (!$thread) {
        $inboxStmt = db()->prepare("SELECT mt.*,
                (SELECT body FROM messages WHERE thread_id = mt.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                (SELECT COUNT(*) FROM messages m2 WHERE m2.thread_id = mt.id AND m2.sender_id <> :self1
                    AND (mtp.last_read_at IS NULL OR m2.created_at > mtp.last_read_at)) AS unread_count
            FROM message_threads mt
            INNER JOIN message_thread_participants mtp ON mtp.thread_id = mt.id AND mtp.user_id = :self2
            WHERE mt.client_id = :client_id
            ORDER BY mt.updated_at DESC");
        $inboxStmt->execute(['self1' => $userId, 'self2' => $userId, 'client_id' => $clientId]);
        $threads = $inboxStmt->fetchAll();
    }
}

if ($clientProfile && $view === 'tickets') {
    $clientId = (int) $clientProfile['id'];
    $requestedTicketId = (int) ($_GET['ticket_id'] ?? 0);

    if ($requestedTicketId > 0) {
        $ticketSelect = 'SELECT st.*';
        if ($hasTicketRequests) {
            $ticketSelect .= ', tr.request_type, tr.target_task_id, tr.target_stage_id, tr.target_invoice_id, tr.requested_amount, tr.requested_percent, tr.requested_due_on, tr.decision_status, tr.approved_amount, tr.approved_percent, tr.approved_due_on, tr.admin_note';
        }
        $ticketSelect .= ' FROM support_tickets st';
        if ($hasTicketRequests) {
            $ticketSelect .= ' LEFT JOIN support_ticket_requests tr ON tr.ticket_id = st.id';
        }
        $ticketSelect .= ' WHERE st.id = :id AND st.client_id = :client_id LIMIT 1';
        $ticketStmt = db()->prepare($ticketSelect);
        $ticketStmt->execute(['id' => $requestedTicketId, 'client_id' => $clientId]);
        $ticket = $ticketStmt->fetch() ?: null;

        if ($ticket) {
            $msgStmt = db()->prepare('SELECT tm.*, u.name AS sender_name FROM support_ticket_messages tm INNER JOIN users u ON u.id = tm.sender_id WHERE tm.ticket_id = :ticket_id ORDER BY tm.created_at ASC');
            $msgStmt->execute(['ticket_id' => $requestedTicketId]);
            $ticketMessages = $msgStmt->fetchAll();
        } else {
            flash('error', 'That ticket is not available.');
        }
    }

    if (!$ticket) {
        $listSql = 'SELECT st.*';
        if ($hasTicketRequests) {
            $listSql .= ', tr.request_type, tr.decision_status';
        }
        $listSql .= ' FROM support_tickets st';
        if ($hasTicketRequests) {
            $listSql .= ' LEFT JOIN support_ticket_requests tr ON tr.ticket_id = st.id';
        }
        $listSql .= ' WHERE st.client_id = :client_id ORDER BY st.created_at DESC';
        $listStmt = db()->prepare($listSql);
        $listStmt->execute(['client_id' => $clientId]);
        $tickets = $listStmt->fetchAll();
    }
}

$headerClientProfile = $clientProfile;
$pageTitle = match ($view) {
    'tasks' => 'My Tasks',
    'contracts' => 'My Contracts',
    'invoices' => 'My Invoices',
    'documents' => 'My Documents',
    'document-requests' => 'Document Requests',
    'compliance' => 'Compliance Calendar',
    'messages' => $thread['subject'] ?? 'Messages',
    'tickets' => $ticket['subject'] ?? 'Support Tickets',
    default => 'Dashboard',
};

include __DIR__ . '/../app/views/partials/client_header.php';
?>

<?php if (!$clientProfile): ?>
    <div class="table-card">
        <h2><?= icon('insights') ?>Workspace guidance</h2>
        <p>Your account is not yet linked to a client workspace.</p>
        <p>Contact your service provider so they can create your client profile and link it to this login.</p>
    </div>
<?php else: ?>

    <?php if ($view === 'home'): ?>
        <?php if ($pendingPaymentResponseCount > 0): ?>
            <div class="notice error">
                <strong><?= e((string) $pendingPaymentResponseCount) ?> payment request<?= $pendingPaymentResponseCount > 1 ? 's' : '' ?></strong>
                awaiting your response — <a href="<?= e(url('dashboard.php?view=invoices')) ?>">open My Invoices</a> to make a payment or ask for more time.
            </div>
        <?php endif; ?>
        <div class="admin-stats">
            <div class="card"><span class="stat-icon"><?= icon('clients') ?></span><strong><?= e($clientProfile['organization_name']) ?></strong><p>Organization</p></div>
            <div class="card"><span class="stat-icon"><?= icon('reports') ?></span><strong><?= e($clientProfile['client_code'] ?? 'N/A') ?></strong><p>Client code</p></div>
            <div class="card"><span class="stat-icon"><?= icon('accounting') ?></span><strong><?= e($clientProfile['industry_name'] ?? 'N/A') ?></strong><p>Industry</p></div>
            <div class="card"><span class="stat-icon"><?= icon('tasks') ?></span><strong><?= e((string) count($tasks)) ?></strong><p>Tracked tasks</p></div>
            <div class="card"><span class="stat-icon"><?= icon('compliance') ?></span><strong><?= e((string) $upcomingComplianceCount) ?></strong><p>Upcoming compliance deadlines</p></div>
            <div class="card"><span class="stat-icon"><?= icon('messages') ?></span><strong><?= e((string) $unreadMessageCount) ?></strong><p>Unread messages</p></div>
            <div class="card"><span class="stat-icon"><?= icon('tickets') ?></span><strong><?= e((string) $openTicketCount) ?></strong><p>Open support tickets</p></div>
        </div>

        <?php if ($teamMembers !== []): ?>
        <div class="table-card">
            <h2><?= icon('teams') ?>Team members</h2>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teamMembers as $member): ?>
                        <tr>
                            <td><?= e($member['name']) ?></td>
                            <td><?= e($member['email']) ?></td>
                            <td><span class="tag"><?= e($member['member_role'] ?? 'member') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="table-card">
            <h2><?= icon('tasks') ?>Recent tasks</h2>
            <table>
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Progress</th>
                        <th>Status</th>
                        <th>Due</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($tasks === []): ?>
                        <tr><td colspan="4">No tasks assigned yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach (array_slice($tasks, 0, 5) as $task): ?>
                        <?php $progress = task_progress_percent($task, $stagesByTask[(int) $task['id']] ?? []); ?>
                        <tr>
                            <td>#<?= e((int) $task['id']) ?> <?= e($task['title']) ?></td>
                            <td>
                                <div class="progress-track"><div class="progress-fill" style="width: <?= e((string) $progress) ?>%"></div></div>
                                <small><?= e((string) $progress) ?>%</small>
                            </td>
                            <td><span class="tag"><?= e($task['status']) ?></span></td>
                            <td><?= e($task['due_date'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($tasks) > 5): ?>
                <p><a href="<?= e(url('dashboard.php?view=tasks')) ?>">View all <?= e((string) count($tasks)) ?> tasks &rarr;</a></p>
            <?php endif; ?>
        </div>

        <div class="table-card">
            <h2><?= icon('invoices') ?>Recent invoices</h2>
            <table>
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Issued</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($invoices === []): ?>
                        <tr><td colspan="4">No invoices issued yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach (array_slice($invoices, 0, 5) as $invoice): ?>
                        <tr>
                            <td><?= e($invoice['invoice_no']) ?></td>
                            <td><?= e(site_currency_symbol()) ?><?= e(number_format((float) $invoice['amount'], 2)) ?></td>
                            <td><span class="tag"><?= e($invoice['status']) ?></span></td>
                            <td><?= e($invoice['issued_on']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($invoices) > 5): ?>
                <p><a href="<?= e(url('dashboard.php?view=invoices')) ?>">View all <?= e((string) count($invoices)) ?> invoices &rarr;</a></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($view === 'tasks'): ?>
        <div class="table-card">
            <h2><?= icon('tasks') ?>Tasks and work progress</h2>
            <table>
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Team</th>
                        <th>Assigned staff</th>
                        <th>Progress</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Due</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($tasks === []): ?>
                        <tr><td colspan="7">No tasks assigned yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($tasks as $task): ?>
                        <?php
                            $taskStages = $stagesByTask[(int) $task['id']] ?? [];
                            $progress = task_progress_percent($task, $taskStages);
                        ?>
                        <tr>
                            <td>#<?= e((int) $task['id']) ?> <?= e($task['title']) ?><br><small><?= e($task['contract_no'] ?? 'No contract') ?></small></td>
                            <td><?= e($task['team_name'] ?? 'Unassigned') ?></td>
                            <td><?= e($task['task_staff_name'] ?? 'Unassigned') ?></td>
                            <td>
                                <div class="progress-track"><div class="progress-fill" style="width: <?= e((string) $progress) ?>%"></div></div>
                                <small><?= e((string) $progress) ?>% <?= $taskStages !== [] ? '(' . e((string) count(array_filter($taskStages, static fn (array $s): bool => ($s['status'] ?? '') === 'completed'))) . ' of ' . e((string) count($taskStages)) . ' stages)' : '' ?></small>
                            </td>
                            <td><span class="tag"><?= e($task['status']) ?></span></td>
                            <td><span class="tag"><?= e($task['priority']) ?></span></td>
                            <td><?= e($task['due_date'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($view === 'contracts'): ?>
        <div class="table-card">
            <h2><?= icon('contracts') ?>Service contracts</h2>
            <table>
                <thead>
                    <tr>
                        <th>Contract</th>
                        <th>Value</th>
                        <th>Status</th>
                        <th>Period</th>
                        <th>Billing cycle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($contracts === []): ?>
                        <tr><td colspan="5">No service contracts yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($contracts as $contract): ?>
                        <tr>
                            <td><?= e($contract['contract_no']) ?><br><small><?= e($contract['title']) ?></small></td>
                            <td><?= e(site_currency_symbol()) ?><?= e(number_format((float) $contract['total_value'], 2)) ?></td>
                            <td><span class="tag"><?= e($contract['status']) ?></span></td>
                            <td><?= e($contract['start_date'] ?? '-') ?> to <?= e($contract['end_date'] ?? '-') ?></td>
                            <td><?= e($contract['billing_cycle'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($view === 'invoices'): ?>
        <?php if ($pendingPaymentResponseCount > 0): ?>
            <div class="notice error">
                <strong><?= e((string) $pendingPaymentResponseCount) ?> payment request<?= $pendingPaymentResponseCount > 1 ? 's' : '' ?></strong>
                below <?= $pendingPaymentResponseCount > 1 ? 'are' : 'is' ?> awaiting your response. Use the buttons under each invoice to submit your payment details or ask for more time / a discount.
            </div>
        <?php endif; ?>
        <div class="table-card">
            <h2><?= icon('invoices') ?>Invoices</h2>
            <table>
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Task / Stage</th>
                        <th>Amount</th>
                        <th>Total (incl. VAT)</th>
                        <th>Status</th>
                        <th>Issued</th>
                        <th>Due</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($invoices === []): ?>
                        <tr><td colspan="7">No invoices issued yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($invoices as $invoice): ?>
                        <?php $invoicePaymentRequests = $paymentRequestsByInvoice[(int) $invoice['id']] ?? []; ?>
                        <tr>
                            <td><?= e($invoice['invoice_no']) ?><br><small><?= e($invoice['invoice_type']) ?> invoice · <?= e($invoice['invoice_category'] ?? 'proforma') ?></small></td>
                            <td><?= e($invoice['task_title']) ?><br><small><?= e($invoice['stage_name'] ?? 'Full task invoice') ?></small></td>
                            <td><?= e(site_currency_symbol()) ?><?= e(number_format((float) $invoice['amount'], 2)) ?></td>
                            <td><?= e(site_currency_symbol()) ?><?= e(number_format((float) ($invoice['total_amount'] ?? 0) > 0 ? (float) $invoice['total_amount'] : (float) $invoice['amount'], 2)) ?></td>
                            <td><span class="tag"><?= e($invoice['status']) ?></span></td>
                            <td><?= e($invoice['issued_on']) ?></td>
                            <td><?= e($invoice['due_on'] ?? '-') ?></td>
                        </tr>
                        <?php if ($invoicePaymentRequests !== []): ?>
                            <tr>
                                <td colspan="7" style="padding: 0.5rem 1rem 1rem;">
                                    <?php foreach ($invoicePaymentRequests as $paymentRequest): ?>
                                        <?php
                                            $prOpen = in_array((string) $paymentRequest['status'], ['pending', 'partial'], true);
                                            $prDeclared = (string) ($paymentRequest['client_declared_status'] ?? 'none') !== 'none';
                                        ?>
                                        <div style="border: 1px solid var(--border-color, #d1d5db); border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 0.75rem;">
                                            <strong><?= icon('invoices') ?>Payment request:</strong>
                                            <?= e(site_currency_symbol()) ?><?= e(number_format((float) $paymentRequest['amount_requested'], 2)) ?>
                                            <span class="tag"><?= e($paymentRequest['status']) ?></span>
                                            <small>requested <?= e((string) $paymentRequest['requested_on']) ?></small>
                                            <?php if (!empty($paymentRequest['notes'])): ?>
                                                <br><small><?= e($paymentRequest['notes']) ?></small>
                                            <?php endif; ?>

                                            <?php if ($prDeclared): ?>
                                                <p style="margin: 0.5rem 0 0;">
                                                    <span class="tag">You declared a <?= e((string) $paymentRequest['client_declared_status']) ?> payment</span>
                                                    of <?= e(site_currency_symbol()) ?><?= e(number_format((float) ($paymentRequest['client_declared_amount'] ?? 0), 2)) ?>
                                                    on <?= e((string) ($paymentRequest['client_declared_on'] ?? '')) ?>
                                                    <?php if ($prOpen): ?>— awaiting verification by your service provider.<?php else: ?>— confirmed.<?php endif; ?>
                                                </p>
                                            <?php endif; ?>

                                            <?php if ($prOpen && $hasPaymentResponses): ?>
                                                <details class="feature-disclosure" style="margin-top: 0.5rem;">
                                                    <summary>
                                                        <span><strong><?= icon('invoices') ?><?= $prDeclared ? 'Update payment details' : 'I have made this payment' ?></strong>
                                                        <small>Tell us how much you paid so we can verify and issue a receipt.</small></span>
                                                        <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
                                                    </summary>
                                                    <form method="post" class="workspace-form-grid">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="declare_payment">
                                                        <input type="hidden" name="payment_request_id" value="<?= e((int) $paymentRequest['id']) ?>">
                                                        <label>Payment type
                                                            <select name="declared_status" required>
                                                                <option value="complete">Complete payment</option>
                                                                <option value="partial">Partial payment</option>
                                                            </select>
                                                        </label>
                                                        <label>Amount paid<input type="number" name="declared_amount" min="0.01" step="0.01" value="<?= e(number_format((float) $paymentRequest['amount_requested'], 2, '.', '')) ?>" required></label>
                                                        <label>Paid on<input type="date" name="declared_on" value="<?= e(date('Y-m-d')) ?>" required></label>
                                                        <label>Method (bank/cash/wallet)<input type="text" name="declared_method" maxlength="100"></label>
                                                        <label>Reference / transaction #<input type="text" name="declared_reference" maxlength="190"></label>
                                                        <label class="workspace-span-2">Note (optional)<textarea name="declared_note" rows="2"></textarea></label>
                                                        <div class="workspace-span-2">
                                                            <button type="submit"><?= icon('invoices') ?>Submit payment details</button>
                                                        </div>
                                                    </form>
                                                </details>

                                                <details class="feature-disclosure" style="margin-top: 0.5rem;">
                                                    <summary>
                                                        <span><strong><?= icon('tickets') ?>Ask for more time or a discount</strong>
                                                        <small>Request an additional credit period or a discount on this invoice.</small></span>
                                                        <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
                                                    </summary>
                                                    <form method="post" class="workspace-form-grid">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="request_invoice_adjustment">
                                                        <input type="hidden" name="target_invoice_id" value="<?= e((int) $invoice['id']) ?>">
                                                        <label>Request type
                                                            <select name="adjustment_type" required>
                                                                <option value="credit_period_request">Additional credit period</option>
                                                                <option value="discount_request">Discount</option>
                                                            </select>
                                                        </label>
                                                        <label>New due date (for credit period)<input type="date" name="requested_due_on"></label>
                                                        <label>Discount amount (optional)<input type="number" name="requested_amount" min="0" step="0.01"></label>
                                                        <label>Discount percent (optional)<input type="number" name="requested_percent" min="0" max="100" step="0.01"></label>
                                                        <label class="workspace-span-2">Reason<textarea name="reason" rows="2" required></textarea></label>
                                                        <div class="workspace-span-2">
                                                            <button type="submit"><?= icon('tickets') ?>Send request</button>
                                                        </div>
                                                    </form>
                                                </details>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($view === 'documents'): ?>
        <div class="table-card">
            <h2><?= icon('documents') ?>My documents</h2>
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Version</th>
                        <th>Uploaded</th>
                        <th>Size</th>
                        <th>Download</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($documents === []): ?>
                        <tr><td colspan="6">No documents available yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($documents as $document): ?>
                        <tr>
                            <td><?= e($document['title']) ?></td>
                            <td><?= e($documentCategoryLabels[$document['category']] ?? $document['category']) ?></td>
                            <td>v<?= e((string) $document['version_number']) ?></td>
                            <td><?= e($document['created_at']) ?></td>
                            <td><?= e(number_format((int) $document['file_size'] / 1024, 1)) ?> KB</td>
                            <td>
                                <a class="button secondary" href="<?= e(url('document-download.php?id=' . (int) $document['id'] . '&inline=1')) ?>" target="_blank" rel="noopener">Preview</a>
                                <a class="button secondary" href="<?= e(url('document-download.php?id=' . (int) $document['id'])) ?>">Download</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($view === 'document-requests'): ?>
        <div class="table-card">
            <h2><?= icon('documents') ?>Document requests</h2>
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Due</th>
                        <th>Comments</th>
                        <th>Response</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($documentRequests === []): ?>
                        <tr><td colspan="6">No document requests yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($documentRequests as $request): ?>
                        <tr>
                            <td><?= e($request['title']) ?><?= $request['description'] ? '<br><small>' . e($request['description']) . '</small>' : '' ?></td>
                            <td><span class="tag"><?= e($request['status']) ?></span></td>
                            <td><span class="tag"><?= e($request['priority']) ?></span></td>
                            <td><?= e($request['due_date'] ?? '-') ?></td>
                            <td>
                                <?php if ($request['staff_comment']): ?><small>Staff: <?= e($request['staff_comment']) ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?php if (in_array($request['status'], ['requested', 'rejected'], true)): ?>
                                    <form method="post" enctype="multipart/form-data" class="inline-action-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="respond_document_request">
                                        <input type="hidden" name="request_id" value="<?= e((int) $request['id']) ?>">
                                        <input type="file" name="file" required>
                                        <button type="submit" class="button">Upload</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($view === 'compliance'): ?>
        <div class="table-card">
            <h2><?= icon('compliance') ?>Compliance calendar</h2>
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Period</th>
                        <th>Statutory due</th>
                        <th>Status</th>
                        <th>Filing details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($complianceDeadlines === []): ?>
                        <tr><td colspan="5">No compliance deadlines yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($complianceDeadlines as $deadline): ?>
                        <tr>
                            <td><?= e($deadline['compliance_type_name']) ?></td>
                            <td><?= e($deadline['applicable_period']) ?></td>
                            <td><?= e($deadline['statutory_due_date']) ?></td>
                            <td><span class="tag"><?= e($complianceStatusLabels[$deadline['effective_status']] ?? $deadline['effective_status']) ?></span></td>
                            <td>
                                <?php if ($deadline['filing_date']): ?>
                                    <small>Filed: <?= e($deadline['filing_date']) ?><?= $deadline['filing_reference'] ? ' (' . e($deadline['filing_reference']) . ')' : '' ?></small><br>
                                <?php endif; ?>
                                <?php if ($deadline['document_title']): ?>
                                    <a href="<?= e(url('document-download.php?id=' . (int) $deadline['document_id'] . '&inline=1')) ?>" target="_blank" rel="noopener">👁️ Preview</a>
                                    <a href="<?= e(url('document-download.php?id=' . (int) $deadline['document_id'])) ?>">📎 <?= e($deadline['document_title']) ?></a>
                                <?php endif; ?>
                                <?php if (!$deadline['filing_date'] && !$deadline['document_title']): ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($view === 'messages' && !$thread): ?>
        <div class="table-card">
            <h2><?= icon('messages') ?>Messages</h2>
            <details class="feature-disclosure">
                <summary>
                    <span>
                        <strong><?= icon('messages') ?>New message</strong>
                        <small>Start a conversation with your service provider.</small>
                    </span>
                    <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
                </summary>
                <form method="post" enctype="multipart/form-data" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_thread">
                    <label>Subject<input type="text" name="subject" maxlength="190" required></label>
                    <label class="workspace-span-2">Message<textarea name="body" required></textarea></label>
                    <label class="workspace-span-2">Attachment (optional)<input type="file" name="attachment"></label>
                    <div class="workspace-span-2">
                        <button type="submit"><?= icon('messages') ?>Send</button>
                    </div>
                </form>
            </details>

            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Last message</th>
                            <th>Updated</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($threads === []): ?>
                            <tr><td colspan="4">No messages yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($threads as $t): ?>
                            <tr>
                                <td>
                                    <a href="<?= e(url('dashboard.php?view=messages&thread_id=' . (int) $t['id'])) ?>"><?= e($t['subject']) ?></a>
                                    <?php if ((int) ($t['unread_count'] ?? 0) > 0): ?>
                                        <span class="tag"><?= e((string) $t['unread_count']) ?> new</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= e(mb_strimwidth((string) ($t['last_message'] ?? ''), 0, 80, '...')) ?></small></td>
                                <td><?= e($t['updated_at']) ?></td>
                                <td><a class="button secondary" href="<?= e(url('dashboard.php?view=messages&thread_id=' . (int) $t['id'])) ?>">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($view === 'messages' && $thread): ?>
        <div class="table-card">
            <h2><?= icon('messages') ?><?= e($thread['subject']) ?></h2>
            <p><a href="<?= e(url('dashboard.php?view=messages')) ?>">&larr; Back to messages</a></p>

            <div class="table-card">
                <?php foreach ($threadMessages as $message): ?>
                    <div style="padding:12px 0; border-bottom:1px solid var(--border);">
                        <strong><?= e($message['sender_name']) ?></strong> <small><?= e($message['created_at']) ?></small>
                        <p><?= nl2br(e($message['body'])) ?></p>
                        <?php if ($message['attachment_path']): ?>
                            <p><a href="<?= e(url('attachment-download.php?type=message&id=' . (int) $message['id'])) ?>">📎 <?= e((string) $message['attachment_name']) ?></a></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <form method="post" enctype="multipart/form-data" class="workspace-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="send_message">
                <input type="hidden" name="thread_id" value="<?= e((int) $thread['id']) ?>">
                <label class="workspace-span-2">Reply<textarea name="body" required></textarea></label>
                <label class="workspace-span-2">Attachment (optional)<input type="file" name="attachment"></label>
                <div class="workspace-span-2">
                    <button type="submit"><?= icon('messages') ?>Send reply</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($view === 'tickets' && !$ticket): ?>
        <div class="table-card">
            <h2><?= icon('tickets') ?>Support tickets</h2>
            <details class="feature-disclosure">
                <summary>
                    <span>
                        <strong><?= icon('tickets') ?>Create ticket</strong>
                        <small>Raise a support issue with your service provider.</small>
                    </span>
                    <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
                </summary>
                <form method="post" enctype="multipart/form-data" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_ticket">
                    <label>Subject<input type="text" name="subject" maxlength="190" required></label>
                    <label>Category
                        <select name="category">
                            <?php foreach ($ticketCategoryLabels as $key => $label): ?>
                                <option value="<?= e($key) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Request type
                        <select name="request_type">
                            <?php foreach ($ticketRequestTypeLabels as $key => $label): ?>
                                <option value="<?= e($key) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Priority
                        <select name="priority">
                            <option value="low">Low</option>
                            <option value="normal" selected>Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </label>
                    <label>Task (optional)
                        <select name="target_task_id">
                            <option value="0">No task</option>
                            <?php foreach ($tasks as $task): ?>
                                <option value="<?= e((int) $task['id']) ?>">#<?= e((int) $task['id']) ?> - <?= e($task['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Invoice (optional)
                        <select name="target_invoice_id">
                            <option value="0">No invoice</option>
                            <?php foreach ($invoices as $invoice): ?>
                                <option value="<?= e((int) $invoice['id']) ?>"><?= e($invoice['invoice_no']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Requested amount (optional)<input type="number" name="requested_amount" min="0" step="0.01"></label>
                    <label>Requested percent (optional)<input type="number" name="requested_percent" min="0" max="100" step="0.01"></label>
                    <label>Requested due date (optional)<input type="date" name="requested_due_on"></label>
                    <label class="workspace-span-2">Description<textarea name="description" required></textarea></label>
                    <label class="workspace-span-2">Attachment (optional)<input type="file" name="attachment"></label>
                    <div class="workspace-span-2">
                        <button type="submit"><?= icon('tickets') ?>Create ticket</button>
                    </div>
                </form>
            </details>

            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Request type</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($tickets === []): ?>
                            <tr><td colspan="5">No support tickets yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($tickets as $t): ?>
                            <tr>
                                <td><a href="<?= e(url('dashboard.php?view=tickets&ticket_id=' . (int) $t['id'])) ?>"><?= e($t['ticket_no']) ?></a><br><small><?= e($t['subject']) ?></small></td>
                                <td><?= e($ticketRequestTypeLabels[$t['request_type'] ?? 'none'] ?? 'General support') ?></td>
                                <td><?= e($ticketCategoryLabels[$t['category']] ?? $t['category']) ?></td>
                                <td><span class="tag"><?= e($t['priority']) ?></span></td>
                                <td><span class="tag"><?= e($ticketStatusLabels[$t['status']] ?? $t['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($view === 'tickets' && $ticket): ?>
        <div class="table-card">
            <h2><?= icon('tickets') ?><?= e($ticket['ticket_no']) ?> - <?= e($ticket['subject']) ?></h2>
            <p><a href="<?= e(url('dashboard.php?view=tickets')) ?>">&larr; Back to tickets</a> | Status: <span class="tag"><?= e($ticketStatusLabels[$ticket['status']] ?? $ticket['status']) ?></span></p>

            <div class="table-card">
                <p><strong>Category:</strong> <?= e($ticketCategoryLabels[$ticket['category']] ?? $ticket['category']) ?> | <strong>Priority:</strong> <?= e($ticket['priority']) ?></p>
                <p><strong>Request Type:</strong> <?= e($ticketRequestTypeLabels[$ticket['request_type'] ?? 'none'] ?? 'General support') ?></p>
                <?php if (!empty($ticket['decision_status']) && ($ticket['decision_status'] ?? 'pending') !== 'pending'): ?>
                    <p><strong>Decision:</strong> <?= e($ticket['decision_status']) ?></p>
                <?php endif; ?>
                <p><?= nl2br(e($ticket['description'])) ?></p>
                <?php if ($ticket['resolution']): ?>
                    <div class="notice success"><strong>Resolution:</strong> <?= nl2br(e($ticket['resolution'])) ?></div>
                <?php endif; ?>
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

<?php endif; ?>
<?php include __DIR__ . '/../app/views/partials/client_footer.php'; ?>
