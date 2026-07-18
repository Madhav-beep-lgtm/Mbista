<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/payment_gateway_engine.php';

require_login();
$pageTitle = 'Dashboard';
$user = current_user();
$userId = (int) $user['id'];

$allowedViews = ['home', 'tasks', 'contracts', 'invoices', 'documents', 'document-requests', 'compliance', 'messages', 'tickets', 'accounting'];
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

// Owner login or portal member added by the owner — both land on the same org.
$clientProfile = client_profile_for_user($userId);
if ($clientProfile) {
    $clientProfile['industry_name'] = null;
    if (!empty($clientProfile['industry_id']) && table_exists('industries')) {
        $industryStmt = db()->prepare('SELECT name FROM industries WHERE id = :id LIMIT 1');
        $industryStmt->execute(['id' => (int) $clientProfile['industry_id']]);
        $clientProfile['industry_name'] = $industryStmt->fetchColumn() ?: null;
    }
}

if ($clientProfile && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $clientId = (int) $clientProfile['id'];

    // Approve or reject accounting entries submitted by assigned staff.
    if ($action === 'client_voucher_decision' && column_exists('vouchers', 'requires_client_approval')) {
        // Only an owner/approver may decide a pending entry. Entry makers can
        // create and edit but never approve/post — otherwise a maker could
        // self-approve their own entries straight into the official books,
        // defeating the whole approval control.
        if (!client_portal_capability('approve')) {
            flash('error', 'You do not have permission to approve or reject accounting entries. Ask an account owner or approver to review it.');
            redirect('dashboard.php?view=accounting');
        }
        $voucherId = (int) ($_POST['voucher_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $booksCompanyId = (int) ($clientProfile['books_company_id'] ?? 0);
        $checkStmt = db()->prepare("SELECT id, submitted_by FROM vouchers WHERE id = :id AND company_id = :cid AND requires_client_approval = 1 AND approval_state = 'pending_approval' LIMIT 1");
        $checkStmt->execute(['id' => $voucherId, 'cid' => $booksCompanyId]);
        $pendingVoucher = $checkStmt->fetch();
        if (!$pendingVoucher || $booksCompanyId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
            flash('error', 'That entry is not awaiting your approval.');
            redirect('dashboard.php?view=accounting');
        }
        if ($decision === 'approve') {
            db()->prepare("UPDATE vouchers SET status = 'posted', approval_state = 'approved', client_approved_by = :uid, client_approved_at = NOW(), posted_by = submitted_by, posted_at = NOW() WHERE id = :id")
                ->execute(['uid' => $userId, 'id' => $voucherId]);
            flash('success', 'Entry approved and posted to your books.');
        } else {
            db()->prepare("UPDATE vouchers SET status = 'cancelled', approval_state = 'rejected', client_approved_by = :uid, client_approved_at = NOW() WHERE id = :id")
                ->execute(['uid' => $userId, 'id' => $voucherId]);
            flash('success', 'Entry rejected.');
        }
        log_activity('client_books', $clientId, 'client_voucher_decision', 'Client ' . $decision . 'd voucher #' . $voucherId . '.', $userId);
        redirect('dashboard.php?view=accounting');
    }

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

        $prPartyRoute = column_exists('accounting_parties', 'client_profile_id')
            ? ' OR ti.party_id IN (SELECT ap.id FROM accounting_parties ap WHERE ap.client_profile_id = :party_client_id)'
            : '';
        $prStmt = db()->prepare("SELECT ipr.* FROM invoice_payment_requests ipr
            INNER JOIN task_invoices ti ON ti.id = ipr.invoice_id
            LEFT JOIN client_tasks ct ON ct.id = ti.task_id
            WHERE ipr.id = :id AND (ct.client_id = :client_id{$prPartyRoute}) AND ipr.status IN ('pending', 'partial')
            LIMIT 1");
        $prParams = ['id' => $paymentRequestId, 'client_id' => $clientId];
        if ($prPartyRoute !== '') {
            $prParams['party_client_id'] = $clientId;
        }
        $prStmt->execute($prParams);
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

        $invPartyRoute = column_exists('accounting_parties', 'client_profile_id')
            ? ' OR ti.party_id IN (SELECT ap.id FROM accounting_parties ap WHERE ap.client_profile_id = :party_client_id)'
            : '';
        $invStmt = db()->prepare("SELECT ti.* FROM task_invoices ti
            LEFT JOIN client_tasks ct ON ct.id = ti.task_id
            WHERE ti.id = :id AND (ct.client_id = :client_id{$invPartyRoute}) LIMIT 1");
        $invParams = ['id' => $targetInvoiceId, 'client_id' => $clientId];
        if ($invPartyRoute !== '') {
            $invParams['party_client_id'] = $clientId;
        }
        $invStmt->execute($invParams);
        $targetInvoice = $invStmt->fetch();

        $adjustmentTypeLabels = [
            'credit_period_request' => 'Additional credit period',
            'discount_request' => 'Discount',
            'partial_payment_request' => 'Partial payment',
            'advance_payment_request' => 'Advance payment',
        ];

        if (!$targetInvoice) {
            flash('error', 'That invoice is not available.');
            redirect('dashboard.php?view=invoices');
        }
        if (!isset($adjustmentTypeLabels[$adjustmentType])) {
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
        if (in_array($adjustmentType, ['partial_payment_request', 'advance_payment_request'], true)
            && ($requestedAmount === '' || !is_numeric($requestedAmount) || (float) $requestedAmount <= 0)) {
            flash('error', 'Enter the amount you want to pay.');
            redirect('dashboard.php?view=invoices');
        }

        $typeLabel = $adjustmentTypeLabels[$adjustmentType];
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

    if ($action === 'accept_request_offer' && table_exists('support_ticket_requests')) {
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);

        $offerStmt = db()->prepare('SELECT st.subject, tr.*
            FROM support_tickets st
            INNER JOIN support_ticket_requests tr ON tr.ticket_id = st.id
            WHERE st.id = :id AND st.client_id = :client_id
            LIMIT 1');
        $offerStmt->execute(['id' => $ticketId, 'client_id' => $clientId]);
        $offer = $offerStmt->fetch();

        if (!$offer || (string) ($offer['decision_status'] ?? '') !== 'negotiation') {
            flash('error', 'There is no open counter-offer on that ticket.');
            redirect('dashboard.php?view=tickets');
        }

        $offerAmount = $offer['approved_amount'] !== null ? (float) $offer['approved_amount'] : null;
        $offerPercent = $offer['approved_percent'] !== null ? (float) $offer['approved_percent'] : null;
        $offerDueOn = !empty($offer['approved_due_on']) ? (string) $offer['approved_due_on'] : null;

        $result = apply_ticket_request_decision($offer, (int) $offer['company_id'], $offerAmount, $offerPercent, $offerDueOn, $userId);
        if ($result['error']) {
            flash('error', $result['error']);
            redirect('dashboard.php?view=tickets&ticket_id=' . $ticketId);
        }

        db()->prepare('UPDATE support_ticket_requests SET decision_status = :status, applied_invoice_id = :invoice_id, processed_on = NOW() WHERE ticket_id = :ticket_id')
            ->execute([
                'status' => 'approved',
                'invoice_id' => $result['invoice_id'],
                'ticket_id' => $ticketId,
            ]);
        db()->prepare('UPDATE support_tickets SET status = :status WHERE id = :id')
            ->execute(['status' => 'resolved', 'id' => $ticketId]);

        ticket_request_note($ticketId, $userId, 'Client accepted the counter-offer. The agreed terms have been applied.');
        log_activity('support_ticket', $ticketId, 'counter_accepted', 'Client accepted the negotiated terms.', $userId);
        flash('success', 'Counter-offer accepted — the agreed terms are now applied.');
        redirect('dashboard.php?view=tickets&ticket_id=' . $ticketId);
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

        if ($tasks !== [] && table_exists('task_stages')) {
            $taskIds = array_map('intval', array_column($tasks, 'id'));
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            $stmt = db()->prepare("SELECT * FROM task_stages WHERE task_id IN ($placeholders) ORDER BY task_id ASC, sequence_no ASC");
            $stmt->execute($taskIds);
            foreach ($stmt->fetchAll() as $stage) {
                $stagesByTask[(int) $stage['task_id']][] = $stage;
            }
        }
    }

    // Invoices reach the client two ways: through their tasks, and through an
    // accounting party linked to their profile (inventory/manufacturing/other
    // invoices carry no task). Fetch both — this must NOT depend on any task
    // existing, or a client with only party invoices sees an empty list.
    if (table_exists('task_invoices')) {
        $invoiceConditions = [];
        $invoiceParams = [];
        if (table_exists('client_tasks')) {
            $invoiceConditions[] = 't.client_id = ?';
            $invoiceParams[] = $clientId;
        }
        if (column_exists('accounting_parties', 'client_profile_id')) {
            $invoiceConditions[] = 'ti.party_id IN (SELECT ap.id FROM accounting_parties ap WHERE ap.client_profile_id = ?)';
            $invoiceParams[] = $clientId;
        }

        if ($invoiceConditions !== []) {
            $stmt = db()->prepare("SELECT ti.*, t.title AS task_title, ts.stage_name
                FROM task_invoices ti
                LEFT JOIN client_tasks t ON t.id = ti.task_id
                LEFT JOIN task_stages ts ON ts.id = ti.stage_id
                WHERE " . implode(' OR ', $invoiceConditions) . "
                ORDER BY ti.created_at DESC");
            $stmt->execute($invoiceParams);
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
$pageSubtitle = match ($view) {
    'tasks' => 'Track the progress, stages, and deadlines of your assigned work.',
    'contracts' => 'Your service contracts, values, and billing cycles.',
    'invoices' => 'Invoices issued to you, payment requests, and adjustment options.',
    'documents' => 'Latest versions of documents shared with you.',
    'document-requests' => 'Documents your service provider has asked you to upload.',
    'compliance' => 'Statutory deadlines, filing status, and supporting documents.',
    'messages' => $thread ? 'Conversation with your service provider.' : 'Message threads with your service provider.',
    'tickets' => $ticket ? 'Ticket conversation and resolution history.' : 'Raise and track support requests.',
    default => 'Your workspace overview: tasks, invoices, compliance, and messages.',
};

$mbwTone = static function (?string $status): string {
    return match (strtolower((string) $status)) {
        'paid', 'completed', 'complete', 'active', 'approved', 'filed', 'resolved', 'verified', 'leader' => 'green',
        'pending', 'partial', 'in_progress', 'on_hold', 'waiting_for_client', 'upcoming', 'uploaded', 'assigned', 'high', 'signed' => 'amber',
        'overdue', 'cancelled', 'rejected', 'expired', 'urgent', 'declined', 'closed' => 'red',
        'draft', 'new', 'open', 'sent', 'issued', 'requested', 'normal', 'not_started', 'low' => 'blue',
        default => 'gray',
    };
};
$mbwPill = static function (?string $status, ?string $label = null) use ($mbwTone): string {
    $text = $label ?? str_replace('_', ' ', (string) $status);
    return '<span class="mbw-pill tone-' . $mbwTone($status) . '">' . e($text) . '</span>';
};

include __DIR__ . '/../app/views/partials/client_header.php';
?>

<?php if (!$clientProfile): ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Workspace guidance</h2></div>
        <p style="color: var(--mbw-muted);">Your account is not yet linked to a client workspace.</p>
        <p style="color: var(--mbw-muted);">Contact your service provider so they can create your client profile and link it to this login.</p>
    </section>
<?php else: ?>

    <?php if ($view === 'home'): ?>
        <?php if ($pendingPaymentResponseCount > 0): ?>
            <div class="notice error">
                <strong><?= e((string) $pendingPaymentResponseCount) ?> payment request<?= $pendingPaymentResponseCount > 1 ? 's' : '' ?></strong>
                awaiting your response — <a href="<?= e(url('dashboard.php?view=invoices')) ?>">open My Invoices</a> to make a payment or ask for more time.
            </div>
        <?php endif; ?>
        <section class="mbw-kpi-grid">
            <article class="mbw-kpi"><div><span class="mbw-kpi-label">Organization</span><div class="mbw-kpi-value" style="font-size:1.05rem;"><?= e($clientProfile['organization_name']) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Code <?= e($clientProfile['client_code'] ?? 'N/A') ?></span></span></div><span class="mbw-chip tone-blue"><?= icon('clients') ?></span></article>
            <article class="mbw-kpi"><div><span class="mbw-kpi-label">Industry</span><div class="mbw-kpi-value" style="font-size:1.05rem;"><?= e($clientProfile['industry_name'] ?? 'N/A') ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Business classification</span></span></div><span class="mbw-chip tone-purple"><?= icon('accounting') ?></span></article>
            <a class="mbw-kpi" href="<?= e(url('dashboard.php?view=tasks')) ?>"><div><span class="mbw-kpi-label">Tracked tasks</span><div class="mbw-kpi-value"><?= e((string) count($tasks)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">View progress</span></span></div><span class="mbw-chip tone-teal"><?= icon('tasks') ?></span></a>
            <a class="mbw-kpi" href="<?= e(url('dashboard.php?view=compliance')) ?>"><div><span class="mbw-kpi-label">Compliance deadlines</span><div class="mbw-kpi-value"><?= e((string) $upcomingComplianceCount) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Upcoming or overdue</span></span></div><span class="mbw-chip <?= $upcomingComplianceCount > 0 ? 'tone-amber' : 'tone-green' ?>"><?= icon('compliance') ?></span></a>
            <a class="mbw-kpi" href="<?= e(url('dashboard.php?view=messages')) ?>"><div><span class="mbw-kpi-label">Unread messages</span><div class="mbw-kpi-value"><?= e((string) $unreadMessageCount) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Open inbox</span></span></div><span class="mbw-chip <?= $unreadMessageCount > 0 ? 'tone-amber' : 'tone-blue' ?>"><?= icon('messages') ?></span></a>
            <a class="mbw-kpi" href="<?= e(url('dashboard.php?view=tickets')) ?>"><div><span class="mbw-kpi-label">Open support tickets</span><div class="mbw-kpi-value"><?= e((string) $openTicketCount) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Track requests</span></span></div><span class="mbw-chip <?= $openTicketCount > 0 ? 'tone-red' : 'tone-green' ?>"><?= icon('tickets') ?></span></a>
        </section>

        <?php if ($teamMembers !== []): ?>
        <section class="mbw-card">
            <div class="mbw-card-head"><h2>Team members</h2></div>
            <div style="overflow-x:auto">
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
                            <td><?= $mbwPill($member['member_role'] ?? 'member') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </section>
        <?php endif; ?>

        <section class="mbw-card">
            <div class="mbw-card-head"><h2>Recent tasks</h2><div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('dashboard.php?view=tasks')) ?>">View All</a></div></div>
            <div style="overflow-x:auto">
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
                            <td><?= $mbwPill($task['status']) ?></td>
                            <td><?= e($task['due_date'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php if (count($tasks) > 5): ?>
                <p><a href="<?= e(url('dashboard.php?view=tasks')) ?>">View all <?= e((string) count($tasks)) ?> tasks &rarr;</a></p>
            <?php endif; ?>
        </section>

        <section class="mbw-card">
            <div class="mbw-card-head"><h2>Recent invoices</h2><div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('dashboard.php?view=invoices')) ?>">View All</a></div></div>
            <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th class="is-numeric">Amount</th>
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
                            <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $invoice['amount'], 2)) ?></td>
                            <td><?= $mbwPill($invoice['status']) ?></td>
                            <td><?= e($invoice['issued_on']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php if (count($invoices) > 5): ?>
                <p><a href="<?= e(url('dashboard.php?view=invoices')) ?>">View all <?= e((string) count($invoices)) ?> invoices &rarr;</a></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($view === 'accounting' && $clientProfile): ?>
        <?php
        require_once __DIR__ . '/../app/reports_engine.php';
        $abCompanyId = (int) ($clientProfile['books_company_id'] ?? 0);
        $abFy = null;
        $abVouchers = [];
        $abPending = [];
        $abSnapshot = ['cash' => 0.0, 'receivables' => 0.0, 'payables' => 0.0, 'income' => 0.0, 'expenses' => 0.0, 'net' => 0.0];
        $abFyList = [];
        if ($abCompanyId > 0) {
            // Client portal fiscal-year selection: view-only, remembered per
            // client-books company in the session, validated to belong to the
            // client's OWN books — a tampered id cannot reach another entity.
            $abFyList = fiscal_years_for_company($abCompanyId, true);
            $abRequestedFy = (int) ($_GET['fy'] ?? 0);
            if ($abRequestedFy > 0) {
                foreach ($abFyList as $abFyOption) {
                    if ((int) $abFyOption['id'] === $abRequestedFy) {
                        $_SESSION['client_fy_selection'][$abCompanyId] = $abRequestedFy;
                        break;
                    }
                }
            }
            $abRememberedFy = (int) ($_SESSION['client_fy_selection'][$abCompanyId] ?? 0);
            foreach ($abFyList as $abFyOption) {
                if ((int) $abFyOption['id'] === $abRememberedFy) {
                    $abFy = $abFyOption;
                    break;
                }
            }
            if (!$abFy) {
                $abFyStmt = db()->prepare('SELECT * FROM fiscal_years WHERE company_id = :cid ORDER BY is_default DESC, is_active DESC, id DESC LIMIT 1');
                $abFyStmt->execute(['cid' => $abCompanyId]);
                $abFy = $abFyStmt->fetch() ?: null;
            }
            if ($abFy) {
                $abSnapshot = company_financials_snapshot($abCompanyId, (int) $abFy['id']);
            }
            $abVoucherStmt = db()->prepare('SELECT * FROM vouchers WHERE company_id = :cid ORDER BY id DESC LIMIT 25');
            $abVoucherStmt->execute(['cid' => $abCompanyId]);
            $abVouchers = $abVoucherStmt->fetchAll();
            $abPending = array_values(array_filter($abVouchers, static fn (array $v): bool => (string) $v['approval_state'] === 'pending_approval' && (int) ($v['requires_client_approval'] ?? 0) === 1));
        }
        $abCurrency = site_currency_symbol();
        $abMoney = static fn (float $a): string => $abCurrency . number_format($a, 2);
        ?>
        <?php if ($abCompanyId <= 0): ?>
            <section class="mbw-card"><div class="mbw-card-head"><h2>My Accounting</h2></div><p style="color:var(--mbw-muted)">Your accounting books have not been set up yet. Please contact your service provider.</p></section>
        <?php else: ?>
            <?php if (count($abFyList) > 1 && $abFy): ?>
                <section class="mbw-card" style="padding:10px 16px">
                    <form method="get" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                        <input type="hidden" name="view" value="accounting">
                        <label for="client-fy" style="margin:0;font-weight:600">Fiscal year</label>
                        <select name="fy" id="client-fy" onchange="this.form.submit()" style="min-height:34px">
                            <?php foreach ($abFyList as $abFyOption): ?>
                                <option value="<?= (int) $abFyOption['id'] ?>" <?= (int) $abFyOption['id'] === (int) $abFy['id'] ? 'selected' : '' ?>>
                                    <?= e($abFyOption['label']) ?> (<?= e($abFyOption['start_date']) ?> to <?= e($abFyOption['end_date']) ?>)<?= (int) ($abFyOption['is_default'] ?? 0) === 1 ? ' · Default' : '' ?><?= in_array(fiscal_year_status($abFyOption), ['closed', 'locked'], true) ? ' · ' . ucfirst(fiscal_year_status($abFyOption)) . ' 🔒' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="mbw-pill tone-<?= e(['upcoming' => 'blue', 'open' => 'green', 'closed' => 'amber', 'locked' => 'red'][fiscal_year_status($abFy)] ?? 'gray') ?>"><?= e(ucfirst(fiscal_year_status($abFy))) ?></span>
                    </form>
                </section>
            <?php endif; ?>
            <section class="mbw-kpi-grid">
                <?php foreach ([['Cash & Bank', $abSnapshot['cash'], 'blue', 'bank'], ['Receivables', $abSnapshot['receivables'], 'green', 'clients'], ['Payables', $abSnapshot['payables'], 'red', 'card'], ['Net Profit', $abSnapshot['net'], 'purple', 'trend-up']] as [$abLabel, $abAmount, $abTone, $abIcon]): ?>
                    <article class="mbw-kpi"><div><span class="mbw-kpi-label"><?= e($abLabel) ?></span><div class="mbw-kpi-value"><?= e($abMoney((float) $abAmount)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= e($abFy['label'] ?? '') ?></span></span></div><span class="mbw-chip tone-<?= e($abTone) ?>"><?= icon($abIcon) ?></span></article>
                <?php endforeach; ?>
            </section>

            <section class="mbw-card">
                <div class="mbw-card-head"><h2>Entries Awaiting Your Approval</h2><span class="mbw-pill <?= $abPending === [] ? 'tone-green' : 'tone-amber' ?>"><?= count($abPending) ?> pending</span></div>
                <?php if ($abPending === []): ?>
                    <p style="color:var(--mbw-muted);font-size:13px">Nothing waiting for you. Entries submitted by your accountant will appear here for approval.</p>
                <?php else: ?>
                    <div style="overflow-x:auto"><table>
                        <thead><tr><th>Date</th><th>Voucher No.</th><th>Type</th><th>Narration</th><th class="is-numeric">Amount</th><th>Decision</th></tr></thead>
                        <tbody>
                        <?php foreach ($abPending as $v): ?>
                            <tr>
                                <td><?= e(date('d M Y', strtotime((string) ($v['voucher_date'] ?? $v['created_at'])))) ?></td>
                                <td><?= e($v['voucher_no']) ?></td>
                                <td><span class="mbw-pill tone-blue"><?= e(ucfirst((string) $v['voucher_type'])) ?></span></td>
                                <td><?= e((string) ($v['narration'] ?: '—')) ?></td>
                                <td class="is-numeric"><?= e($abMoney((float) $v['total_amount'])) ?></td>
                                <td>
                                    <form method="post" style="display:inline-flex;gap:6px">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="client_voucher_decision">
                                        <input type="hidden" name="voucher_id" value="<?= (int) $v['id'] ?>">
                                        <button type="submit" name="decision" value="approve" class="button success" style="min-height:32px;padding:4px 12px">Approve</button>
                                        <button type="submit" name="decision" value="reject" class="button danger" style="min-height:32px;padding:4px 12px">Reject</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </section>

            <section class="mbw-card">
                <div class="mbw-card-head"><h2>Recent Entries</h2></div>
                <div style="overflow-x:auto"><table>
                    <thead><tr><th>Date</th><th>Voucher No.</th><th>Type</th><th>Narration</th><th class="is-numeric">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if ($abVouchers === []): ?><tr><td colspan="6" style="color:var(--mbw-muted)">No entries in your books yet.</td></tr><?php endif; ?>
                    <?php foreach (array_slice($abVouchers, 0, 10) as $v): ?>
                        <tr><td><?= e(date('d M Y', strtotime((string) ($v['voucher_date'] ?? $v['created_at'])))) ?></td><td><?= e($v['voucher_no']) ?></td><td><span class="mbw-pill tone-blue"><?= e(ucfirst((string) $v['voucher_type'])) ?></span></td><td><?= e((string) ($v['narration'] ?: '—')) ?></td><td class="is-numeric"><?= e($abMoney((float) $v['total_amount'])) ?></td><td><?= (string) $v['status'] === 'posted' ? '<span class="mbw-pill tone-green">Posted</span>' : ((string) $v['approval_state'] === 'pending_approval' ? '<span class="mbw-pill tone-amber">Awaiting you</span>' : '<span class="mbw-pill tone-red">' . e(ucfirst((string) $v['status'])) . '</span>') ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </section>

            <?php if ($abFy): ?>
                <?php
                $abReportId = in_array((string) ($_GET['report'] ?? ''), ['trial-balance', 'profit-loss', 'balance-sheet', 'cash-flow', 'ledger-report'], true) ? (string) $_GET['report'] : 'trial-balance';
                $abReport = rc_generate($abReportId, $abCompanyId, (string) $abFy['start_date'], (string) $abFy['end_date'], ['currency' => $abCurrency, 'vtype' => '', 'group_id' => 0, 'ledger_id' => 0, 'item_id' => 0, 'biz' => company_accounting_business_type($abCompanyId), 'org_default' => company_accounting_business_type($abCompanyId), 'company_id' => $abCompanyId, 'company_name' => (string) $clientProfile['organization_name'], 'subsidiaries' => []]);
                ?>
                <section class="mbw-card rpt-picker">
                    <?php foreach (['trial-balance' => 'Trial Balance', 'profit-loss' => 'Profit or Loss', 'balance-sheet' => 'Balance Sheet', 'cash-flow' => 'Cash Flow', 'ledger-report' => 'Ledger Report'] as $abRid => $abRlabel): ?>
                        <a class="rpt-pick <?= $abRid === $abReportId ? 'is-active' : '' ?>" href="<?= e(url('dashboard.php?view=accounting&report=' . $abRid)) ?>"><?= icon('reports') ?><span><?= e($abRlabel) ?></span></a>
                    <?php endforeach; ?>
                </section>
                <main class="rc-report-view rpt-statement">
                    <div class="rpt-bar"><?= e(($abReport['number'] ?? '') !== '' ? $abReport['number'] . '. ' : '') ?><?= e($abReport['title'] ?? 'Report') ?></div>
                    <?php rc_render_letterhead($abReport, ['company_name' => (string) $clientProfile['organization_name'], 'fiscal_label' => (string) $abFy['label'], 'from' => (string) $abFy['start_date'], 'to' => (string) $abFy['end_date'], 'currency_code' => trim($abCurrency) !== '' ? trim($abCurrency) : 'NPR', 'generated_by' => (string) ($user['name'] ?? 'Client')]); ?>
                    <div class="rc-table-scroll"><?php rc_render_table($abReport, rc_has_group_columns($abReport)); ?></div>
                    <?php rc_render_report_foot(['generated_by' => (string) ($user['name'] ?? 'Client')]); ?>
                </main>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($view === 'tasks'): ?>
        <section class="mbw-card">
            <div class="mbw-card-head"><h2>Tasks and work progress</h2></div>
            <div style="overflow-x:auto">
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
                            <td><?= $mbwPill($task['status']) ?></td>
                            <td><?= $mbwPill($task['priority']) ?></td>
                            <td><?= e($task['due_date'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($view === 'contracts'): ?>
        <section class="mbw-card">
            <div class="mbw-card-head"><h2>Service contracts</h2></div>
            <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Contract</th>
                        <th class="is-numeric">Value</th>
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
                            <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $contract['total_value'], 2)) ?></td>
                            <td><?= $mbwPill($contract['status']) ?></td>
                            <td><?= e($contract['start_date'] ?? '-') ?> to <?= e($contract['end_date'] ?? '-') ?></td>
                            <td><?= e($contract['billing_cycle'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($view === 'invoices'): ?>
        <?php if ($pendingPaymentResponseCount > 0): ?>
            <div class="notice error">
                <strong><?= e((string) $pendingPaymentResponseCount) ?> payment request<?= $pendingPaymentResponseCount > 1 ? 's' : '' ?></strong>
                below <?= $pendingPaymentResponseCount > 1 ? 'are' : 'is' ?> awaiting your response. Use the buttons under each invoice to submit your payment details or ask for more time / a discount.
            </div>
        <?php endif; ?>
        <section class="mbw-card">
            <div class="mbw-card-head"><h2>Invoices</h2></div>
            <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Task / Stage</th>
                        <th class="is-numeric">Amount</th>
                        <th class="is-numeric">Total (incl. VAT)</th>
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
                        <?php
                            $invoicePaymentRequests = $paymentRequestsByInvoice[(int) $invoice['id']] ?? [];
                            $invoiceAwaitingPayment = in_array((string) $invoice['status'], ['issued', 'draft'], true);
                        ?>
                        <tr>
                            <td><?= e($invoice['invoice_no']) ?><br><small><?= e($invoice['invoice_type']) ?> invoice · <?= e($invoice['invoice_category'] ?? 'proforma') ?></small></td>
                            <td><?= e($invoice['task_title'] ?? ($invoice['description'] ?? 'General invoice')) ?><br><small><?= e($invoice['task_title'] !== null ? ($invoice['stage_name'] ?? 'Full task invoice') : ucfirst((string) ($invoice['invoice_source_type'] ?? 'general')) . ' invoice') ?></small></td>
                            <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $invoice['amount'], 2)) ?></td>
                            <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) ($invoice['total_amount'] ?? 0) > 0 ? (float) $invoice['total_amount'] : (float) $invoice['amount'], 2)) ?></td>
                            <td><?= $mbwPill($invoice['status']) ?></td>
                            <td><?= e($invoice['issued_on']) ?></td>
                            <td><?= e($invoice['due_on'] ?? '-') ?></td>
                        </tr>
                        <?php if ($invoicePaymentRequests !== [] || $invoiceAwaitingPayment): ?>
                            <tr>
                                <td colspan="7" style="padding: 0.5rem 1rem 1rem;">
                                    <?php foreach ($invoicePaymentRequests as $paymentRequest): ?>
                                        <?php
                                            $prOpen = in_array((string) $paymentRequest['status'], ['pending', 'partial'], true);
                                            $prDeclared = (string) ($paymentRequest['client_declared_status'] ?? 'none') !== 'none';
                                        ?>
                                        <div style="border: 1px solid var(--mbw-border); border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 0.75rem;">
                                            <strong style="color: var(--mbw-heading);"><?= icon('invoices') ?>Payment request:</strong>
                                            <?= e(site_currency_symbol()) ?><?= e(number_format((float) $paymentRequest['amount_requested'], 2)) ?>
                                            <?= $mbwPill($paymentRequest['status']) ?>
                                            <small>requested <?= e((string) $paymentRequest['requested_on']) ?></small>
                                            <?php if (!empty($paymentRequest['notes'])): ?>
                                                <br><small><?= e($paymentRequest['notes']) ?></small>
                                            <?php endif; ?>

                                            <?php if ($prDeclared): ?>
                                                <p style="margin: 0.5rem 0 0;">
                                                    <?= $mbwPill((string) $paymentRequest['client_declared_status'], 'You declared a ' . (string) $paymentRequest['client_declared_status'] . ' payment') ?>
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

                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php $payGateways = $invoiceAwaitingPayment ? pg_enabled_configs((int) $invoice['company_id']) : []; ?>
                                    <?php if ($payGateways !== []): ?>
                                        <div style="border: 1px solid var(--mbw-border); border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 0.75rem;">
                                            <strong style="color: var(--mbw-heading);"><?= icon('card') ?>Pay online now</strong>
                                            <p style="margin: 0.25rem 0 0.5rem; font-size: 12.5px; color: var(--mbw-muted);">You'll be redirected to the provider; your invoice updates automatically once payment is confirmed.</p>
                                            <div style="display:flex;flex-wrap:wrap;gap:8px">
                                                <?php foreach ($payGateways as $pgProvider => $pgConfig): ?>
                                                    <form method="post" action="<?= e(url('pay/start.php')) ?>" style="display:inline">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="invoice_id" value="<?= e((int) $invoice['id']) ?>">
                                                        <input type="hidden" name="provider" value="<?= e((string) $pgProvider) ?>">
                                                        <button type="submit" class="button"><?= icon('card') ?>Pay with <?= e(pg_provider_label((string) $pgProvider)) ?></button>
                                                    </form>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($invoiceAwaitingPayment && table_exists('support_ticket_requests')): ?>
                                        <details class="feature-disclosure" style="margin-top: 0.5rem;">
                                            <summary>
                                                <span><strong><?= icon('tickets') ?>Ask for more time, a discount, or offer a payment</strong>
                                                <small>Request a credit period, a discount, or propose a partial / advance payment on this invoice.</small></span>
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
                                                        <option value="partial_payment_request">Partial payment (pay part now)</option>
                                                        <option value="advance_payment_request">Advance payment</option>
                                                    </select>
                                                </label>
                                                <label>New due date (for credit period)<input type="date" name="requested_due_on"></label>
                                                <label>Amount (discount or payment)<input type="number" name="requested_amount" min="0" step="0.01"></label>
                                                <label>Discount percent (optional)<input type="number" name="requested_percent" min="0" max="100" step="0.01"></label>
                                                <label class="workspace-span-2">Reason<textarea name="reason" rows="2" required></textarea></label>
                                                <div class="workspace-span-2">
                                                    <button type="submit"><?= icon('tickets') ?>Send request</button>
                                                </div>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($view === 'documents'): ?>
        <section class="mbw-card">
            <div class="mbw-card-head"><h2>My documents</h2></div>
            <div style="overflow-x:auto">
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
        </section>
    <?php endif; ?>

    <?php if ($view === 'document-requests'): ?>
        <section class="mbw-card">
            <div class="mbw-card-head"><h2>Document requests</h2></div>
            <div style="overflow-x:auto">
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
                            <td><?= $mbwPill($request['status']) ?></td>
                            <td><?= $mbwPill($request['priority']) ?></td>
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
        </section>
    <?php endif; ?>

    <?php if ($view === 'compliance'): ?>
        <section class="mbw-card">
            <div class="mbw-card-head"><h2>Compliance calendar</h2></div>
            <div style="overflow-x:auto">
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
                            <td><?= $mbwPill($deadline['effective_status'], $complianceStatusLabels[$deadline['effective_status']] ?? $deadline['effective_status']) ?></td>
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
        </section>
    <?php endif; ?>

    <?php if ($view === 'messages' && !$thread): ?>
        <section class="mbw-card">
            <div class="mbw-card-head"><h2>Messages</h2></div>
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

            <div style="overflow-x:auto; margin-top: 1rem;">
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
                                        <span class="mbw-pill tone-amber"><?= e((string) $t['unread_count']) ?> new</span>
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
        </section>
    <?php endif; ?>

    <?php if ($view === 'messages' && $thread): ?>
        <section class="mbw-card">
            <div class="mbw-card-head"><h2><?= e($thread['subject']) ?></h2><div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('dashboard.php?view=messages')) ?>">Back to messages</a></div></div>

            <div>
                <?php foreach ($threadMessages as $message): ?>
                    <div style="padding:12px 0; border-bottom:1px solid var(--mbw-border);">
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
        </section>
    <?php endif; ?>

    <?php if ($view === 'tickets' && !$ticket): ?>
        <section class="mbw-card">
            <div class="mbw-card-head"><h2>Support tickets</h2></div>
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

            <div style="overflow-x:auto; margin-top: 1rem;">
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
                                <td><?= $mbwPill($t['priority']) ?></td>
                                <td><?= $mbwPill($t['status'], $ticketStatusLabels[$t['status']] ?? $t['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($view === 'tickets' && $ticket): ?>
        <section class="mbw-card">
            <div class="mbw-card-head"><h2><?= e($ticket['ticket_no']) ?> - <?= e($ticket['subject']) ?></h2><div class="mbw-card-tools"><?= $mbwPill($ticket['status'], $ticketStatusLabels[$ticket['status']] ?? $ticket['status']) ?><a class="mbw-view-all" href="<?= e(url('dashboard.php?view=tickets')) ?>">Back to tickets</a></div></div>

            <div style="margin-bottom: 1rem;">
                <p><strong>Category:</strong> <?= e($ticketCategoryLabels[$ticket['category']] ?? $ticket['category']) ?> | <strong>Priority:</strong> <?= e($ticket['priority']) ?></p>
                <p><strong>Request Type:</strong> <?= e($ticketRequestTypeLabels[$ticket['request_type'] ?? 'none'] ?? 'General support') ?></p>
                <?php if (!empty($ticket['decision_status']) && ($ticket['decision_status'] ?? 'pending') !== 'pending'): ?>
                    <p><strong>Decision:</strong> <?= e(ucwords(str_replace('_', ' ', (string) $ticket['decision_status']))) ?></p>
                <?php endif; ?>
                <?php if (($ticket['decision_status'] ?? '') === 'negotiation'): ?>
                    <div class="notice">
                        <strong>Counter-offer from your service provider:</strong>
                        <?php if ($ticket['approved_amount'] !== null): ?> Amount <?= e(site_currency_symbol()) ?><?= e(number_format((float) $ticket['approved_amount'], 2)) ?>.<?php endif; ?>
                        <?php if ($ticket['approved_percent'] !== null): ?> <?= e((string) (float) $ticket['approved_percent']) ?>%.<?php endif; ?>
                        <?php if (!empty($ticket['approved_due_on'])): ?> Proposed due date <?= e((string) $ticket['approved_due_on']) ?>.<?php endif; ?>
                        <?php if (!empty($ticket['admin_note'])): ?><br><em><?= e((string) $ticket['admin_note']) ?></em><?php endif; ?>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="accept_request_offer">
                            <input type="hidden" name="ticket_id" value="<?= e((int) $ticket['id']) ?>">
                            <button type="submit"><?= icon('tasks') ?>Accept counter-offer</button>
                        </form>
                        <small>Not happy with the terms? Just reply below to keep negotiating.</small>
                    </div>
                <?php endif; ?>
                <p><?= nl2br(e($ticket['description'])) ?></p>
                <?php if ($ticket['resolution']): ?>
                    <div class="notice success"><strong>Resolution:</strong> <?= nl2br(e($ticket['resolution'])) ?></div>
                <?php endif; ?>
            </div>

            <div>
                <h3 style="color: var(--mbw-heading);">Conversation</h3>
                <?php foreach ($ticketMessages as $message): ?>
                    <div style="padding:12px 0; border-bottom:1px solid var(--mbw-border);">
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
        </section>
    <?php endif; ?>

<?php endif; ?>
<?php include __DIR__ . '/../app/views/partials/client_footer.php'; ?>
