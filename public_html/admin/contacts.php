<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();
require_company_context();
$pageTitle = 'Contacts Workflow';
$currentAdmin = current_user();
$company = current_company();
$companyId = (int) ($company['id'] ?? 0);
$allowedStatuses = ['new', 'read', 'replied'];
$allowedPriorities = ['low', 'normal', 'high', 'urgent'];
$allowedSort = [
    'created_desc' => 'c.created_at DESC',
    'created_asc' => 'c.created_at ASC',
    'priority_desc' => "FIELD(c.priority, 'urgent','high','normal','low') DESC, c.created_at DESC",
    'priority_asc' => "FIELD(c.priority, 'low','normal','high','urgent') ASC, c.created_at DESC",
    'status_asc' => "FIELD(c.status, 'new','read','replied') ASC, c.created_at DESC",
    'status_desc' => "FIELD(c.status, 'replied','read','new') ASC, c.created_at DESC",
];
$perPageOptions = [10, 20, 50, 100];

$uploadDir = __DIR__ . '/../uploads/contact_attachments';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'new');
        $priority = (string) ($_POST['priority'] ?? 'normal');
        $assignedAdminIdRaw = trim((string) ($_POST['assigned_admin_id'] ?? ''));
        $assignedAdminId = $assignedAdminIdRaw !== '' ? (int) $assignedAdminIdRaw : null;

        if ($name === '' || $email === '' || $subject === '' || $message === '') {
            flash('error', 'Name, email, subject, and message are required.');
            redirect('admin/contacts.php');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please provide a valid email address.');
            redirect('admin/contacts.php');
        }

        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'new';
        }

        if (!in_array($priority, $allowedPriorities, true)) {
            $priority = 'normal';
        }

        if ($assignedAdminId !== null) {
            $adminCheckStmt = db()->prepare("SELECT id FROM users WHERE id = :id AND role = 'admin' AND status = 'active' AND company_id = :company_id LIMIT 1");
            $adminCheckStmt->execute([
                'id' => $assignedAdminId,
                'company_id' => $companyId,
            ]);
            if (!$adminCheckStmt->fetch()) {
                flash('error', 'Assigned admin must belong to the selected company.');
                redirect('admin/contacts.php');
            }
        }

        $attachmentPath = null;
        $attachmentName = null;

        if (isset($_FILES['attachment']) && (int) $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $fileError = (int) ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($fileError !== UPLOAD_ERR_OK) {
                flash('error', 'Attachment upload failed.');
                redirect('admin/contacts.php');
            }

            $tmpName = (string) $_FILES['attachment']['tmp_name'];
            $originalName = (string) $_FILES['attachment']['name'];
            $size = (int) $_FILES['attachment']['size'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'txt', 'doc', 'docx'];

            if (!in_array($extension, $allowedExtensions, true)) {
                flash('error', 'Unsupported attachment format.');
                redirect('admin/contacts.php');
            }

            if ($size > 5 * 1024 * 1024) {
                flash('error', 'Attachment must be 5 MB or smaller.');
                redirect('admin/contacts.php');
            }

            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName) ?: 'attachment.' . $extension;
            $storedName = date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '_' . $safeName;
            $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $storedName;

            if (!move_uploaded_file($tmpName, $targetPath)) {
                flash('error', 'Could not save attachment file.');
                redirect('admin/contacts.php');
            }

            $attachmentPath = 'uploads/contact_attachments/' . $storedName;
            $attachmentName = $originalName;
        }

        $stmt = db()->prepare('INSERT INTO contacts (company_id, name, email, subject, message, status, priority, assigned_admin_id, attachment_path, attachment_name, replied_at) VALUES (:company_id, :name, :email, :subject, :message, :status, :priority, :assigned_admin_id, :attachment_path, :attachment_name, :replied_at)');
        $stmt->execute([
            'company_id' => $companyId,
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'message' => $message,
            'status' => $status,
            'priority' => $priority,
            'assigned_admin_id' => $assignedAdminId,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'replied_at' => $status === 'replied' ? date('Y-m-d H:i:s') : null,
        ]);

        $newId = (int) db()->lastInsertId();
        log_activity('contact', $newId, 'created', 'Contact request created from admin workflow.', (int) ($currentAdmin['id'] ?? 0));
        flash('success', 'Contact request created.');
        redirect('admin/contacts.php?view=' . $newId);
    }

    if ($action === 'update') {
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        if ($contactId <= 0) {
            flash('error', 'Invalid contact selected.');
            redirect('admin/contacts.php');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'new');
        $priority = (string) ($_POST['priority'] ?? 'normal');
        $assignedAdminIdRaw = trim((string) ($_POST['assigned_admin_id'] ?? ''));
        $assignedAdminId = $assignedAdminIdRaw !== '' ? (int) $assignedAdminIdRaw : null;

        if ($name === '' || $email === '' || $subject === '' || $message === '') {
            flash('error', 'Name, email, subject, and message are required.');
            redirect('admin/contacts.php?view=' . $contactId);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please provide a valid email address.');
            redirect('admin/contacts.php?view=' . $contactId);
        }

        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'new';
        }

        if (!in_array($priority, $allowedPriorities, true)) {
            $priority = 'normal';
        }

        if ($assignedAdminId !== null) {
            $adminCheckStmt = db()->prepare("SELECT id FROM users WHERE id = :id AND role = 'admin' AND status = 'active' AND company_id = :company_id LIMIT 1");
            $adminCheckStmt->execute([
                'id' => $assignedAdminId,
                'company_id' => $companyId,
            ]);
            if (!$adminCheckStmt->fetch()) {
                flash('error', 'Assigned admin must belong to the selected company.');
                redirect('admin/contacts.php?view=' . $contactId);
            }
        }

        $existingStmt = db()->prepare('SELECT attachment_path, attachment_name, status FROM contacts WHERE id = :id AND company_id = :company_id LIMIT 1');
        $existingStmt->execute([
            'id' => $contactId,
            'company_id' => $companyId,
        ]);
        $existing = $existingStmt->fetch();
        if (!$existing) {
            flash('error', 'Contact not found.');
            redirect('admin/contacts.php');
        }

        $attachmentPath = $existing['attachment_path'];
        $attachmentName = $existing['attachment_name'];

        if (isset($_POST['remove_attachment']) && $_POST['remove_attachment'] === '1') {
            if (!empty($attachmentPath)) {
                $diskPath = __DIR__ . '/../' . ltrim((string) $attachmentPath, '/');
                if (is_file($diskPath)) {
                    @unlink($diskPath);
                }
            }
            $attachmentPath = null;
            $attachmentName = null;
        }

        if (isset($_FILES['attachment']) && (int) $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $fileError = (int) ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($fileError !== UPLOAD_ERR_OK) {
                flash('error', 'Attachment upload failed.');
                redirect('admin/contacts.php?view=' . $contactId);
            }

            $tmpName = (string) $_FILES['attachment']['tmp_name'];
            $originalName = (string) $_FILES['attachment']['name'];
            $size = (int) $_FILES['attachment']['size'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'txt', 'doc', 'docx'];

            if (!in_array($extension, $allowedExtensions, true)) {
                flash('error', 'Unsupported attachment format.');
                redirect('admin/contacts.php?view=' . $contactId);
            }

            if ($size > 5 * 1024 * 1024) {
                flash('error', 'Attachment must be 5 MB or smaller.');
                redirect('admin/contacts.php?view=' . $contactId);
            }

            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName) ?: 'attachment.' . $extension;
            $storedName = date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '_' . $safeName;
            $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $storedName;

            if (!move_uploaded_file($tmpName, $targetPath)) {
                flash('error', 'Could not save attachment file.');
                redirect('admin/contacts.php?view=' . $contactId);
            }

            if (!empty($attachmentPath)) {
                $oldPath = __DIR__ . '/../' . ltrim((string) $attachmentPath, '/');
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $attachmentPath = 'uploads/contact_attachments/' . $storedName;
            $attachmentName = $originalName;
        }

        $repliedAt = null;
        if ($status === 'replied') {
            $repliedAt = ($existing['status'] ?? 'new') === 'replied' ? null : date('Y-m-d H:i:s');
        }

        $updateSql = 'UPDATE contacts SET name = :name, email = :email, subject = :subject, message = :message, status = :status, priority = :priority, assigned_admin_id = :assigned_admin_id, attachment_path = :attachment_path, attachment_name = :attachment_name';
        if ($status === 'replied' && $repliedAt !== null) {
            $updateSql .= ', replied_at = :replied_at';
        }
        $updateSql .= ' WHERE id = :id AND company_id = :company_id';

        $params = [
            'id' => $contactId,
            'company_id' => $companyId,
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'message' => $message,
            'status' => $status,
            'priority' => $priority,
            'assigned_admin_id' => $assignedAdminId,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
        ];

        if ($status === 'replied' && $repliedAt !== null) {
            $params['replied_at'] = $repliedAt;
        }

        $stmt = db()->prepare($updateSql);
        $stmt->execute($params);

        log_activity('contact', $contactId, 'updated', 'Contact request updated from admin workflow.', (int) ($currentAdmin['id'] ?? 0));
        if ($status === 'replied') {
            log_activity('contact', $contactId, 'status_changed', 'Status changed to replied.', (int) ($currentAdmin['id'] ?? 0));
        }

        flash('success', 'Contact request updated.');
        redirect('admin/contacts.php?view=' . $contactId);
    }

    if ($action === 'status') {
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'new');

        if ($contactId <= 0 || !in_array($status, $allowedStatuses, true)) {
            flash('error', 'Invalid status update request.');
            redirect('admin/contacts.php');
        }

        $stmt = db()->prepare('UPDATE contacts SET status = :status, replied_at = CASE WHEN :status_cond = \'replied\' AND replied_at IS NULL THEN CURRENT_TIMESTAMP ELSE replied_at END WHERE id = :id AND company_id = :company_id');
        $stmt->execute([
            'status' => $status,
            'status_cond' => $status,
            'id' => $contactId,
            'company_id' => $companyId,
        ]);

        log_activity('contact', $contactId, 'status_changed', 'Status changed to ' . $status . '.', (int) ($currentAdmin['id'] ?? 0));
        flash('success', 'Contact status updated.');
        redirect('admin/contacts.php?view=' . $contactId);
    }

    if ($action === 'delete') {
        $contactId = (int) ($_POST['delete_id'] ?? 0);
        if ($contactId <= 0) {
            flash('error', 'Invalid contact selected for deletion.');
            redirect('admin/contacts.php');
        }

        $stmt = db()->prepare('SELECT attachment_path FROM contacts WHERE id = :id AND company_id = :company_id LIMIT 1');
        $stmt->execute([
            'id' => $contactId,
            'company_id' => $companyId,
        ]);
        $contact = $stmt->fetch();
        if (!$contact) {
            flash('error', 'Contact not found in selected company context.');
            redirect('admin/contacts.php');
        }

        $deleteStmt = db()->prepare('DELETE FROM contacts WHERE id = :id AND company_id = :company_id');
        $deleteStmt->execute([
            'id' => $contactId,
            'company_id' => $companyId,
        ]);

        if (!empty($contact['attachment_path'])) {
            $diskPath = __DIR__ . '/../' . ltrim((string) $contact['attachment_path'], '/');
            if (is_file($diskPath)) {
                @unlink($diskPath);
            }
        }

        log_activity('contact', $contactId, 'deleted', 'Contact request deleted.', (int) ($currentAdmin['id'] ?? 0));
        flash('success', 'Contact request deleted.');
        redirect('admin/contacts.php');
    }

    flash('error', 'Unsupported action.');
    redirect('admin/contacts.php');
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$priorityFilter = trim((string) ($_GET['priority'] ?? 'all'));
$assignedFilter = trim((string) ($_GET['assigned'] ?? 'all'));
$sort = trim((string) ($_GET['sort'] ?? 'created_desc'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPageInput = (int) ($_GET['per_page'] ?? 20);
$perPage = in_array($perPageInput, $perPageOptions, true) ? $perPageInput : 20;
$offset = ($page - 1) * $perPage;

if (!array_key_exists($sort, $allowedSort)) {
    $sort = 'created_desc';
}

if (!in_array($statusFilter, array_merge(['all'], $allowedStatuses), true)) {
    $statusFilter = 'all';
}

if (!in_array($priorityFilter, array_merge(['all'], $allowedPriorities), true)) {
    $priorityFilter = 'all';
}

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(c.name LIKE :search1 OR c.email LIKE :search2 OR c.subject LIKE :search3 OR c.message LIKE :search4)';
    $params['search1'] = $params['search2'] = $params['search3'] = $params['search4'] = '%' . $search . '%';
}

if ($statusFilter !== 'all') {
    $where[] = 'c.status = :status_filter';
    $params['status_filter'] = $statusFilter;
}

if ($priorityFilter !== 'all') {
    $where[] = 'c.priority = :priority_filter';
    $params['priority_filter'] = $priorityFilter;
}

if ($assignedFilter !== 'all') {
    if ($assignedFilter === 'unassigned') {
        $where[] = 'c.assigned_admin_id IS NULL';
    } elseif (ctype_digit($assignedFilter)) {
        $where[] = 'c.assigned_admin_id = :assigned_filter';
        $params['assigned_filter'] = (int) $assignedFilter;
    }
}

$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$whereSql = $whereSql === '' ? ' WHERE c.company_id = :company_id' : $whereSql . ' AND c.company_id = :company_id';
$params['company_id'] = $companyId;

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportSql = 'SELECT c.id, c.name, c.email, c.subject, c.message, c.status, c.priority, c.created_at, c.replied_at, u.name AS assigned_admin FROM contacts c LEFT JOIN users u ON u.id = c.assigned_admin_id' . $whereSql . ' ORDER BY ' . $allowedSort[$sort];
    $exportStmt = db()->prepare($exportSql);
    foreach ($params as $key => $value) {
        $exportStmt->bindValue(':' . $key, $value);
    }
    $exportStmt->execute();
    $rows = $exportStmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="contacts-export-' . date('Ymd-His') . '.csv"');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        exit('Unable to generate CSV export.');
    }

    fputcsv($output, ['ID', 'Name', 'Email', 'Subject', 'Message', 'Status', 'Priority', 'Assigned Admin', 'Created At', 'Replied At']);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['id'],
            $row['name'],
            $row['email'],
            $row['subject'],
            $row['message'],
            $row['status'],
            $row['priority'],
            $row['assigned_admin'] ?? '',
            $row['created_at'],
            $row['replied_at'] ?? '',
        ]);
    }

    fclose($output);
    exit;
}

$countSql = 'SELECT COUNT(*) FROM contacts c LEFT JOIN users u ON u.id = c.assigned_admin_id' . $whereSql;
$countStmt = db()->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue(':' . $key, $value);
}
$countStmt->execute();
$totalContacts = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalContacts / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = 'SELECT c.*, u.name AS assigned_admin_name FROM contacts c LEFT JOIN users u ON u.id = c.assigned_admin_id' . $whereSql . ' ORDER BY ' . $allowedSort[$sort] . ' LIMIT :limit OFFSET :offset';
$stmt = db()->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$contacts = $stmt->fetchAll();

$adminsStmt = db()->prepare("SELECT id, name FROM users WHERE role = 'admin' AND status = 'active' AND company_id = :company_id ORDER BY name ASC");
$adminsStmt->execute(['company_id' => $companyId]);
$admins = $adminsStmt->fetchAll();

$viewId = (int) ($_GET['view'] ?? 0);
$viewContact = null;
$activityLogs = [];
if ($viewId > 0) {
    $viewStmt = db()->prepare('SELECT c.*, u.name AS assigned_admin_name FROM contacts c LEFT JOIN users u ON u.id = c.assigned_admin_id WHERE c.id = :id AND c.company_id = :company_id LIMIT 1');
    $viewStmt->execute([
        'id' => $viewId,
        'company_id' => $companyId,
    ]);
    $viewContact = $viewStmt->fetch() ?: null;

    if ($viewContact) {
        $activityLogs = activity_logs_for('contact', (int) $viewContact['id'], 30);
    }
}

$summary = [
    'all' => 0,
    'new' => 0,
    'read' => 0,
    'replied' => 0,
    'high_priority' => 0,
];

foreach ([
    'all' => 'SELECT COUNT(*) FROM contacts WHERE company_id = :company_id',
    'new' => "SELECT COUNT(*) FROM contacts WHERE company_id = :company_id AND status = 'new'",
    'read' => "SELECT COUNT(*) FROM contacts WHERE company_id = :company_id AND status = 'read'",
    'replied' => "SELECT COUNT(*) FROM contacts WHERE company_id = :company_id AND status = 'replied'",
    'high_priority' => "SELECT COUNT(*) FROM contacts WHERE company_id = :company_id AND priority IN ('high','urgent')",
] as $summaryKey => $summarySql) {
    $summaryStmt = db()->prepare($summarySql);
    $summaryStmt->execute(['company_id' => $companyId]);
    $summary[$summaryKey] = (int) $summaryStmt->fetchColumn();
}

$baseQuery = [
    'q' => $search,
    'status' => $statusFilter,
    'priority' => $priorityFilter,
    'assigned' => $assignedFilter,
    'sort' => $sort,
    'per_page' => (string) $perPage,
];

$buildContactsUrl = static function (array $extra = []) use ($baseQuery): string {
    $query = array_merge($baseQuery, $extra);
    $query = array_filter($query, static fn($value) => $value !== '' && $value !== null);

    return url('admin/contacts.php' . ($query === [] ? '' : '?' . http_build_query($query)));
};

include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="admin-hero">
    <div>
        <div class="badge">Contacts workflow</div>
        <h2>Manage incoming leads with assignment, priority, and reply tracking.</h2>
        <p>Use filters and workflow actions to keep response times fast and contact statuses accurate.</p>
    </div>
    <div class="actions">
        <button type="button" class="button" data-modal-open="contact-create-modal">New contact</button>
        <a class="button secondary" href="<?= e($buildContactsUrl(['export' => 'csv', 'page' => null])) ?>">Export CSV</a>
        <button type="button" class="button secondary" onclick="window.print()">Print</button>
    </div>
</div>

<div class="admin-stats contacts-stats-grid">
    <div class="card"><span class="stat-icon"><?= icon('contact') ?></span><strong><?= e((string) $summary['all']) ?></strong><p>Total contacts</p></div>
    <div class="card"><span class="stat-icon"><?= icon('invoices') ?></span><strong><?= e((string) $summary['new']) ?></strong><p>New</p></div>
    <div class="card"><span class="stat-icon"><?= icon('insights') ?></span><strong><?= e((string) $summary['read']) ?></strong><p>Read</p></div>
    <div class="card"><span class="stat-icon"><?= icon('login') ?></span><strong><?= e((string) $summary['replied']) ?></strong><p>Replied</p></div>
    <div class="card"><span class="stat-icon"><?= icon('reports') ?></span><strong><?= e((string) $summary['high_priority']) ?></strong><p>High/Urgent</p></div>
</div>

<div class="form-card contacts-filters-card">
    <h2>Search and filters</h2>
    <form method="get" class="contacts-filter-grid" id="contacts-filter-form">
        <label>Search
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Name, email, subject, message">
        </label>
        <label>Status
            <select name="status">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                <?php foreach ($allowedStatuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Priority
            <select name="priority">
                <option value="all" <?= $priorityFilter === 'all' ? 'selected' : '' ?>>All</option>
                <?php foreach ($allowedPriorities as $priority): ?>
                    <option value="<?= e($priority) ?>" <?= $priorityFilter === $priority ? 'selected' : '' ?>><?= e(ucfirst($priority)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Assigned admin
            <select name="assigned">
                <option value="all" <?= $assignedFilter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="unassigned" <?= $assignedFilter === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                <?php foreach ($admins as $admin): ?>
                    <option value="<?= e((int) $admin['id']) ?>" <?= $assignedFilter === (string) $admin['id'] ? 'selected' : '' ?>><?= e($admin['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Sort
            <select name="sort">
                <option value="created_desc" <?= $sort === 'created_desc' ? 'selected' : '' ?>>Newest first</option>
                <option value="created_asc" <?= $sort === 'created_asc' ? 'selected' : '' ?>>Oldest first</option>
                <option value="priority_desc" <?= $sort === 'priority_desc' ? 'selected' : '' ?>>Priority high to low</option>
                <option value="priority_asc" <?= $sort === 'priority_asc' ? 'selected' : '' ?>>Priority low to high</option>
                <option value="status_asc" <?= $sort === 'status_asc' ? 'selected' : '' ?>>Status new to replied</option>
                <option value="status_desc" <?= $sort === 'status_desc' ? 'selected' : '' ?>>Status replied to new</option>
            </select>
        </label>
        <label>Per page
            <select name="per_page">
                <?php foreach ($perPageOptions as $option): ?>
                    <option value="<?= e((string) $option) ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= e((string) $option) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="actions contacts-filter-actions">
            <button type="submit" id="contacts-apply-button">Apply</button>
            <a class="button secondary" href="<?= e(url('admin/contacts.php')) ?>">Reset</a>
        </div>
    </form>
</div>

<div class="table-card">
    <h2>Contact requests</h2>
    <p>Showing <?= e((string) count($contacts)) ?> of <?= e((string) $totalContacts) ?> contact(s).</p>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Subject</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Assigned</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($contacts === []): ?>
                <tr>
                    <td colspan="8">No contact records found for selected filters.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($contacts as $contact): ?>
                <tr>
                    <td>#<?= e((int) $contact['id']) ?></td>
                    <td>
                        <?= e($contact['name']) ?><br>
                        <small><?= e($contact['email']) ?></small>
                    </td>
                    <td><?= e($contact['subject']) ?></td>
                    <td><span class="tag contact-tag-status-<?= e((string) ($contact['status'] ?? 'new')) ?>\"><?= e($contact['status']) ?></span></td>
                    <td><span class="tag contact-tag-priority-<?= e((string) ($contact['priority'] ?? 'normal')) ?>\"><?= e($contact['priority'] ?? 'normal') ?></span></td>
                    <td><?= e($contact['assigned_admin_name'] ?? 'Unassigned') ?></td>
                    <td><?= e($contact['created_at']) ?></td>
                    <td>
                        <div class="actions contacts-row-actions">
                            <a class="button secondary" href="<?= e($buildContactsUrl(['view' => (int) $contact['id']])) ?>">View</a>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="status">
                                <input type="hidden" name="contact_id" value="<?= e((int) $contact['id']) ?>">
                                <input type="hidden" name="status" value="replied">
                                <button type="submit" class="secondary">Mark replied</button>
                            </form>
                            <form method="post" style="margin:0;" onsubmit="return confirm('Delete this contact request?');">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="delete_id" value="<?= e((int) $contact['id']) ?>">
                                <button type="submit" class="secondary">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="admin-orders-pagination">
        <?php if ($page > 1): ?>
            <a class="button secondary" href="<?= e($buildContactsUrl(['page' => $page - 1])) ?>">Previous</a>
        <?php endif; ?>
        <span>Page <?= e((string) $page) ?> of <?= e((string) $totalPages) ?></span>
        <?php if ($page < $totalPages): ?>
            <a class="button secondary" href="<?= e($buildContactsUrl(['page' => $page + 1])) ?>">Next</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($viewContact): ?>
    <div class="modal-overlay is-open" data-modal="contact-view-modal" role="dialog" aria-modal="true" aria-labelledby="contact-view-title">
        <div class="modal-card">
            <div class="modal-head">
                <h3 id="contact-view-title">Contact #<?= e((int) $viewContact['id']) ?></h3>
                <a class="button secondary" href="<?= e($buildContactsUrl(['view' => null])) ?>">Close</a>
            </div>

            <div class="contacts-view-grid">
                <div class="card">
                    <h3>Details</h3>
                    <p><strong>Name:</strong> <?= e($viewContact['name']) ?></p>
                    <p><strong>Email:</strong> <?= e($viewContact['email']) ?></p>
                    <p><strong>Subject:</strong> <?= e($viewContact['subject']) ?></p>
                    <p><strong>Status:</strong> <?= e($viewContact['status']) ?></p>
                    <p><strong>Priority:</strong> <?= e($viewContact['priority'] ?? 'normal') ?></p>
                    <p><strong>Assigned:</strong> <?= e($viewContact['assigned_admin_name'] ?? 'Unassigned') ?></p>
                    <p><strong>Created:</strong> <?= e($viewContact['created_at']) ?></p>
                    <p><strong>Replied:</strong> <?= e($viewContact['replied_at'] ?? 'Not replied') ?></p>
                    <?php if (!empty($viewContact['attachment_path'])): ?>
                        <p>
                            <strong>Attachment:</strong>
                            <a href="<?= e(url((string) $viewContact['attachment_path'])) ?>" target="_blank" rel="noopener">
                                <?= e($viewContact['attachment_name'] ?: 'Download file') ?>
                            </a>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h3>Message</h3>
                    <p><?= nl2br(e($viewContact['message'])) ?></p>
                </div>
            </div>

            <div class="form-card">
                <h3>Edit contact</h3>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="contact_id" value="<?= e((int) $viewContact['id']) ?>">

                    <div class="contacts-edit-grid">
                        <label>Name<input type="text" name="name" value="<?= e($viewContact['name']) ?>" required></label>
                        <label>Email<input type="email" name="email" value="<?= e($viewContact['email']) ?>" required></label>
                        <label>Subject<input type="text" name="subject" value="<?= e($viewContact['subject']) ?>" required></label>
                        <label>Status
                            <select name="status" required>
                                <?php foreach ($allowedStatuses as $status): ?>
                                    <option value="<?= e($status) ?>" <?= $viewContact['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Priority
                            <select name="priority" required>
                                <?php foreach ($allowedPriorities as $priority): ?>
                                    <option value="<?= e($priority) ?>" <?= ($viewContact['priority'] ?? 'normal') === $priority ? 'selected' : '' ?>><?= e(ucfirst($priority)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Assigned admin
                            <select name="assigned_admin_id">
                                <option value="">Unassigned</option>
                                <?php foreach ($admins as $admin): ?>
                                    <option value="<?= e((int) $admin['id']) ?>" <?= (int) ($viewContact['assigned_admin_id'] ?? 0) === (int) $admin['id'] ? 'selected' : '' ?>><?= e($admin['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="contacts-full">Message<textarea name="message" required><?= e($viewContact['message']) ?></textarea></label>
                        <label>Attachment (replace)
                            <input type="file" name="attachment" accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.doc,.docx">
                        </label>
                        <label>
                            <input type="checkbox" name="remove_attachment" value="1">
                            Remove existing attachment
                        </label>
                    </div>

                    <div class="actions">
                        <button type="submit">Save changes</button>
                        <a class="button secondary" href="<?= e($buildContactsUrl(['view' => null])) ?>">Cancel</a>
                    </div>
                </form>
            </div>

            <div class="table-card">
                <h3>Activity records</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Details</th>
                            <th>Actor</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($activityLogs === []): ?>
                            <tr>
                                <td colspan="4">No activity logs for this contact yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($activityLogs as $log): ?>
                            <tr>
                                <td><?= e($log['action']) ?></td>
                                <td><?= e($log['details'] ?? '') ?></td>
                                <td><?= e($log['actor_name'] ?? 'System') ?></td>
                                <td><?= e($log['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="modal-overlay" data-modal="contact-create-modal" role="dialog" aria-modal="true" aria-labelledby="contact-create-title">
    <div class="modal-card">
        <div class="modal-head">
            <h3 id="contact-create-title">Create contact request</h3>
            <button type="button" class="button secondary" data-modal-close="contact-create-modal">Close</button>
        </div>
        <div class="form-card">
            <form method="post" enctype="multipart/form-data" id="contact-create-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">

                <div class="contacts-edit-grid">
                    <label>Name<input type="text" name="name" required></label>
                    <label>Email<input type="email" name="email" required></label>
                    <label>Subject<input type="text" name="subject" required></label>
                    <label>Status
                        <select name="status" required>
                            <option value="new">New</option>
                            <option value="read">Read</option>
                            <option value="replied">Replied</option>
                        </select>
                    </label>
                    <label>Priority
                        <select name="priority" required>
                            <option value="low">Low</option>
                            <option value="normal" selected>Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </label>
                    <label>Assigned admin
                        <select name="assigned_admin_id">
                            <option value="">Unassigned</option>
                            <?php foreach ($admins as $admin): ?>
                                <option value="<?= e((int) $admin['id']) ?>"><?= e($admin['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="contacts-full">Message<textarea name="message" required></textarea></label>
                    <label class="contacts-full">Attachment
                        <input type="file" name="attachment" accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.doc,.docx">
                    </label>
                </div>

                <div class="actions">
                    <button type="submit" id="contact-create-submit">Create contact</button>
                    <button type="button" class="button secondary" data-modal-close="contact-create-modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
