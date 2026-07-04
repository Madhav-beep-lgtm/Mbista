<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_or_admin();

$pageTitle = 'Documents';
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

$allowedViews = ['requests', 'library'];
$view = (string) ($_GET['view'] ?? 'requests');
if (!in_array($view, $allowedViews, true)) {
    $view = 'requests';
}

$allowedCategories = [
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
$allowedPriorities = ['low', 'normal', 'high', 'urgent'];
$allowedRequestStatus = ['requested', 'uploaded', 'under_review', 'accepted', 'rejected', 'waived'];

$scopedClientIds = $role === 'staff' ? staff_scoped_client_ids($userId, $companyId) : null;

$clients = [];
if (table_exists('client_profiles')) {
    $clientSql = "SELECT cp.id, cp.organization_name FROM client_profiles cp WHERE cp.company_id = :company_id";
    $clientParams = ['company_id' => $companyId];
    if ($scopedClientIds !== null) {
        if ($scopedClientIds === []) {
            $clientSql .= ' AND 1 = 0';
        } else {
            $placeholders = implode(',', array_fill(0, count($scopedClientIds), '?'));
            $clientSql = "SELECT cp.id, cp.organization_name FROM client_profiles cp WHERE cp.company_id = ? AND cp.id IN ($placeholders)";
            $clientParams = array_merge([$companyId], $scopedClientIds);
        }
    }
    $clientSql .= ' ORDER BY cp.organization_name ASC';
    $clientStmt = db()->prepare($clientSql);
    $clientStmt->execute($clientParams);
    $clients = $clientStmt->fetchAll();
}
$clientIdsInScope = array_map(static fn (array $c): int => (int) $c['id'], $clients);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_document_request') {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $priority = (string) ($_POST['priority'] ?? 'normal');
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));

        if ($clientId <= 0 || $title === '' || !in_array($clientId, $clientIdsInScope, true)) {
            flash('error', 'Select a valid client and provide a title.');
            redirect('admin/documents.php?view=requests');
        }
        if (!in_array($priority, $allowedPriorities, true)) {
            $priority = 'normal';
        }

        $stmt = db()->prepare('INSERT INTO document_requests (company_id, client_id, title, description, priority, due_date, requested_by) VALUES (:company_id, :client_id, :title, :description, :priority, :due_date, :requested_by)');
        $stmt->execute([
            'company_id' => $companyId,
            'client_id' => $clientId,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'priority' => $priority,
            'due_date' => $dueDate !== '' ? $dueDate : null,
            'requested_by' => $userId,
        ]);
        $requestId = (int) db()->lastInsertId();
        log_activity('document_request', $requestId, 'created', 'Document request created.', $userId);
        flash('success', 'Document request created.');
        redirect('admin/documents.php?view=requests');
    }

    if ($action === 'review_document_request') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $staffComment = trim((string) ($_POST['staff_comment'] ?? ''));

        if ($requestId <= 0 || !in_array($decision, ['accepted', 'rejected', 'waived'], true)) {
            flash('error', 'Invalid review decision.');
            redirect('admin/documents.php?view=requests');
        }

        $reqStmt = db()->prepare('SELECT * FROM document_requests WHERE id = :id AND company_id = :company_id LIMIT 1');
        $reqStmt->execute(['id' => $requestId, 'company_id' => $companyId]);
        $request = $reqStmt->fetch();
        if (!$request || !in_array((int) $request['client_id'], $clientIdsInScope, true)) {
            flash('error', 'That document request is not in your scope.');
            redirect('admin/documents.php?view=requests');
        }
        if ($decision === 'rejected' && $staffComment === '') {
            flash('error', 'Provide a comment explaining the rejection.');
            redirect('admin/documents.php?view=requests');
        }

        if ($decision === 'accepted' && $request['document_id'] !== null) {
            $updateDoc = db()->prepare("UPDATE documents SET visibility = 'client' WHERE id = :id AND company_id = :company_id");
            $updateDoc->execute(['id' => (int) $request['document_id'], 'company_id' => $companyId]);
        }

        $updateReq = db()->prepare('UPDATE document_requests SET status = :status, staff_comment = :staff_comment WHERE id = :id');
        $updateReq->execute([
            'status' => $decision,
            'staff_comment' => $staffComment !== '' ? $staffComment : null,
            'id' => $requestId,
        ]);
        log_activity('document_request', $requestId, $decision, 'Document request ' . $decision . '.', $userId);
        flash('success', 'Document request updated.');
        redirect('admin/documents.php?view=requests');
    }

    if ($action === 'upload_document' || $action === 'upload_document_version') {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $category = (string) ($_POST['category'] ?? 'other');
        $visibility = (string) ($_POST['visibility'] ?? 'internal');
        $parentDocumentId = (int) ($_POST['parent_document_id'] ?? 0);

        $versionNumber = 1;
        $rootParentId = null;
        if ($action === 'upload_document_version') {
            $parentStmt = db()->prepare('SELECT * FROM documents WHERE id = :id AND company_id = :company_id LIMIT 1');
            $parentStmt->execute(['id' => $parentDocumentId, 'company_id' => $companyId]);
            $parentDocument = $parentStmt->fetch();
            if (!$parentDocument) {
                flash('error', 'Original document not found.');
                redirect('admin/documents.php?view=library');
            }
            $rootParentId = (int) ($parentDocument['parent_document_id'] ?: $parentDocument['id']);
            $latestVersionStmt = db()->prepare('SELECT MAX(version_number) AS max_version FROM documents WHERE id = :root_id OR parent_document_id = :root_id2');
            $latestVersionStmt->execute(['root_id' => $rootParentId, 'root_id2' => $rootParentId]);
            $versionNumber = (int) ($latestVersionStmt->fetch()['max_version'] ?? 0) + 1;
            $clientId = (int) $parentDocument['client_id'];
            $title = (string) $parentDocument['title'];
            $category = (string) $parentDocument['category'];
            $visibility = (string) $parentDocument['visibility'];
        }

        if ($clientId <= 0 || $title === '' || !in_array($clientId, $clientIdsInScope, true)) {
            flash('error', 'Select a valid client and provide a title.');
            redirect('admin/documents.php?view=library');
        }
        if (!array_key_exists($category, $allowedCategories)) {
            $category = 'other';
        }
        if (!in_array($visibility, ['internal', 'client'], true)) {
            $visibility = 'internal';
        }

        if (!isset($_FILES['file'])) {
            flash('error', 'Choose a file to upload.');
            redirect('admin/documents.php?view=library');
        }

        $uploadResult = handle_document_upload($_FILES['file'], $companyId, $clientId);
        if (!$uploadResult['ok']) {
            flash('error', $uploadResult['error']);
            redirect('admin/documents.php?view=library');
        }

        $stmt = db()->prepare('INSERT INTO documents (company_id, client_id, parent_document_id, version_number, category, title, original_file_name, stored_file_name, file_path, file_type, file_size, visibility, uploaded_by) VALUES (:company_id, :client_id, :parent_document_id, :version_number, :category, :title, :original_file_name, :stored_file_name, :file_path, :file_type, :file_size, :visibility, :uploaded_by)');
        $stmt->execute([
            'company_id' => $companyId,
            'client_id' => $clientId,
            'parent_document_id' => $rootParentId,
            'version_number' => $versionNumber,
            'category' => $category,
            'title' => $title,
            'original_file_name' => $uploadResult['original_file_name'],
            'stored_file_name' => $uploadResult['stored_file_name'],
            'file_path' => $uploadResult['file_path'],
            'file_type' => $uploadResult['file_type'],
            'file_size' => $uploadResult['file_size'],
            'visibility' => $visibility,
            'uploaded_by' => $userId,
        ]);
        $documentId = (int) db()->lastInsertId();
        log_activity('document', $documentId, 'uploaded', 'Document uploaded to library.', $userId);
        flash('success', 'Document uploaded.');
        redirect('admin/documents.php?view=library');
    }

    flash('error', 'Unsupported document action.');
    redirect('admin/documents.php?view=requests');
}

$documentRequests = [];
$documents = [];

if (table_exists('document_requests') && $clientIdsInScope !== []) {
    $placeholders = implode(',', array_fill(0, count($clientIdsInScope), '?'));
    $stmt = db()->prepare("SELECT dr.*, cp.organization_name, d.title AS document_title
        FROM document_requests dr
        INNER JOIN client_profiles cp ON cp.id = dr.client_id
        LEFT JOIN documents d ON d.id = dr.document_id
        WHERE dr.company_id = ? AND dr.client_id IN ($placeholders)
        ORDER BY dr.created_at DESC");
    $stmt->execute(array_merge([$companyId], $clientIdsInScope));
    $documentRequests = $stmt->fetchAll();
}

if (table_exists('documents') && $clientIdsInScope !== []) {
    $placeholders = implode(',', array_fill(0, count($clientIdsInScope), '?'));
    $stmt = db()->prepare("SELECT d.*, cp.organization_name, u.name AS uploaded_by_name
        FROM documents d
        INNER JOIN client_profiles cp ON cp.id = d.client_id
        LEFT JOIN users u ON u.id = d.uploaded_by
        WHERE d.company_id = ? AND d.client_id IN ($placeholders) AND d.is_active = 1
        ORDER BY d.created_at DESC");
    $stmt->execute(array_merge([$companyId], $clientIdsInScope));
    $allDocuments = $stmt->fetchAll();

    $latestByLineage = [];
    foreach ($allDocuments as $doc) {
        $lineageId = (int) ($doc['parent_document_id'] ?: $doc['id']);
        if (!isset($latestByLineage[$lineageId]) || (int) $doc['version_number'] > (int) $latestByLineage[$lineageId]['version_number']) {
            $latestByLineage[$lineageId] = $doc;
        }
    }
    $documents = array_values($latestByLineage);

    $historyByLineage = [];
    foreach ($allDocuments as $doc) {
        $lineageId = (int) ($doc['parent_document_id'] ?: $doc['id']);
        $historyByLineage[$lineageId][] = $doc;
    }
}

$pageTitle = $view === 'library' ? 'Document Library' : 'Document Requests';

include __DIR__ . '/../../app/views/partials/' . ($role === 'admin' ? 'admin_header' : 'staff_header') . '.php';
?>
<div class="actions workspace-module-bar" style="margin-bottom: 16px;">
    <a class="button<?= $view === 'requests' ? '' : ' secondary' ?>" href="<?= e(url('admin/documents.php?view=requests')) ?>"><?= icon('documents') ?>Document requests</a>
    <a class="button<?= $view === 'library' ? '' : ' secondary' ?>" href="<?= e(url('admin/documents.php?view=library')) ?>"><?= icon('documents') ?>Document library</a>
</div>

<?php if ($view === 'requests'): ?>
    <div class="table-card">
        <h2><?= icon('documents') ?>Request a document from a client</h2>
        <?php if ($clients === []): ?>
            <p class="muted">No clients in scope yet.</p>
        <?php else: ?>
            <details class="feature-disclosure">
                <summary>
                    <span>
                        <strong><?= icon('documents') ?>Create request</strong>
                        <small>Ask a client to upload a specific document.</small>
                    </span>
                    <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
                </summary>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_document_request">
                    <label>Client
                        <select name="client_id" required>
                            <option value="">Select client</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= e((int) $client['id']) ?>"><?= e($client['organization_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Title<input type="text" name="title" maxlength="190" required></label>
                    <label>Priority
                        <select name="priority">
                            <option value="low">Low</option>
                            <option value="normal" selected>Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </label>
                    <label>Due date<input type="date" name="due_date"></label>
                    <label class="workspace-span-2">Description<textarea name="description"></textarea></label>
                    <div class="workspace-span-2">
                        <button type="submit"><?= icon('documents') ?>Send request</button>
                    </div>
                </form>
            </details>
        <?php endif; ?>

        <div class="table-card">
            <h3>Requests</h3>
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Client</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Due</th>
                        <th>Comments</th>
                        <th>Review</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($documentRequests === []): ?>
                        <tr><td colspan="7">No document requests yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($documentRequests as $request): ?>
                        <tr>
                            <td><?= e($request['title']) ?><?= $request['document_title'] ? '<br><small>' . e($request['document_title']) . '</small>' : '' ?></td>
                            <td><?= e($request['organization_name']) ?></td>
                            <td><span class="tag"><?= e($request['status']) ?></span></td>
                            <td><span class="tag"><?= e($request['priority']) ?></span></td>
                            <td><?= e($request['due_date'] ?? '-') ?></td>
                            <td>
                                <?php if ($request['client_comment']): ?><small>Client: <?= e($request['client_comment']) ?></small><br><?php endif; ?>
                                <?php if ($request['staff_comment']): ?><small>Staff: <?= e($request['staff_comment']) ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?php if (in_array($request['status'], ['uploaded', 'under_review'], true)): ?>
                                    <form method="post" class="inline-action-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="review_document_request">
                                        <input type="hidden" name="request_id" value="<?= e((int) $request['id']) ?>">
                                        <input type="hidden" name="decision" value="accepted">
                                        <button type="submit" class="button">Accept</button>
                                    </form>
                                    <form method="post" class="inline-action-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="review_document_request">
                                        <input type="hidden" name="request_id" value="<?= e((int) $request['id']) ?>">
                                        <input type="hidden" name="decision" value="rejected">
                                        <input type="text" name="staff_comment" placeholder="Reason" required>
                                        <button type="submit" class="button secondary">Reject</button>
                                    </form>
                                <?php elseif ($request['status'] === 'requested'): ?>
                                    <form method="post" class="inline-action-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="review_document_request">
                                        <input type="hidden" name="request_id" value="<?= e((int) $request['id']) ?>">
                                        <input type="hidden" name="decision" value="waived">
                                        <button type="submit" class="button secondary">Waive</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($view === 'library'): ?>
    <div class="table-card">
        <h2><?= icon('documents') ?>Document library</h2>
        <?php if ($clients === []): ?>
            <p class="muted">No clients in scope yet.</p>
        <?php else: ?>
            <details class="feature-disclosure">
                <summary>
                    <span>
                        <strong><?= icon('documents') ?>Upload document</strong>
                        <small>Add a document to a client's library.</small>
                    </span>
                    <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
                </summary>
                <form method="post" enctype="multipart/form-data" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="upload_document">
                    <label>Client
                        <select name="client_id" required>
                            <option value="">Select client</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= e((int) $client['id']) ?>"><?= e($client['organization_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Title<input type="text" name="title" maxlength="190" required></label>
                    <label>Category
                        <select name="category">
                            <?php foreach ($allowedCategories as $key => $label): ?>
                                <option value="<?= e($key) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Visibility
                        <select name="visibility">
                            <option value="internal">Internal only</option>
                            <option value="client">Visible to client</option>
                        </select>
                    </label>
                    <label class="workspace-span-2">File<input type="file" name="file" required></label>
                    <div class="workspace-span-2">
                        <button type="submit"><?= icon('documents') ?>Upload</button>
                    </div>
                </form>
            </details>
        <?php endif; ?>

        <div class="table-card">
            <h3>Documents</h3>
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Client</th>
                        <th>Version</th>
                        <th>Visibility</th>
                        <th>Uploaded</th>
                        <th>Size</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($documents === []): ?>
                        <tr><td colspan="8">No documents yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($documents as $document): ?>
                        <?php $lineageId = (int) ($document['parent_document_id'] ?: $document['id']); ?>
                        <tr>
                            <td><?= e($document['title']) ?></td>
                            <td><?= e($allowedCategories[$document['category']] ?? $document['category']) ?></td>
                            <td><?= e($document['organization_name']) ?></td>
                            <td>v<?= e((string) $document['version_number']) ?><?= count($historyByLineage[$lineageId] ?? []) > 1 ? ' (' . e((string) count($historyByLineage[$lineageId])) . ' versions)' : '' ?></td>
                            <td><span class="tag"><?= e($document['visibility']) ?></span></td>
                            <td><?= e($document['uploaded_by_name'] ?? 'N/A') ?><br><small><?= e($document['created_at']) ?></small></td>
                            <td><?= e(number_format((int) $document['file_size'] / 1024, 1)) ?> KB</td>
                            <td>
                                <a class="button secondary" href="<?= e(url('document-download.php?id=' . (int) $document['id'] . '&inline=1')) ?>" target="_blank" rel="noopener">Preview</a>
                                <a class="button secondary" href="<?= e(url('document-download.php?id=' . (int) $document['id'])) ?>">Download</a>
                                <form method="post" enctype="multipart/form-data" class="inline-action-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="upload_document_version">
                                    <input type="hidden" name="parent_document_id" value="<?= e((int) $document['id']) ?>">
                                    <input type="file" name="file" required>
                                    <button type="submit" class="button secondary">New version</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/' . ($role === 'admin' ? 'admin_footer' : 'staff_footer') . '.php'; ?>
