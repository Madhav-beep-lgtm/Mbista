<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_or_admin();
require_permission('compliance', 'view');

$pageTitle = 'Compliance Calendar';
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

$allowedViews = ['deadlines', 'types'];
$view = (string) ($_GET['view'] ?? 'deadlines');
if (!in_array($view, $allowedViews, true) || ($view === 'types' && $role !== 'admin')) {
    $view = 'deadlines';
}

$allowedStatus = ['not_started', 'upcoming', 'in_progress', 'waiting_for_client', 'filed', 'completed', 'overdue', 'not_applicable'];

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

$complianceTypes = [];
if (table_exists('compliance_types')) {
    $typeStmt = db()->prepare('SELECT * FROM compliance_types WHERE company_id = :company_id ORDER BY name ASC');
    $typeStmt->execute(['company_id' => $companyId]);
    $complianceTypes = $typeStmt->fetchAll();
}
$activeComplianceTypes = array_filter($complianceTypes, static fn (array $t): bool => (int) $t['is_active'] === 1);

require_once __DIR__ . '/../../app/tax_fines_engine.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (($_POST['action'] ?? '') === 'post_fine' && $role === 'admin') {
        // The calculator below only CALCULATES — this is the explicit admin
        // step that puts the charge into the books.
        require_permission('accounting', 'post');
        $fineResult = fines_post_voucher(
            $companyId,
            (int) ($_POST['fine_ledger_id'] ?? 0),
            (float) ($_POST['fine_amount'] ?? 0),
            trim((string) ($_POST['fine_narration'] ?? '')) ?: 'Government interest/fine on late payment',
            $userId
        );
        flash($fineResult['ok'] ? 'success' : 'error', $fineResult['ok']
            ? 'Fine/interest voucher #' . $fineResult['voucher_id'] . ' posted (Dr Government Fines & Interest / Cr tax payable).'
            : (string) $fineResult['error']);
        redirect('admin/compliance.php');
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_compliance_type' && $role === 'admin') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            flash('error', 'Provide a compliance type name.');
            redirect('admin/compliance.php?view=types');
        }
        try {
            $stmt = db()->prepare('INSERT INTO compliance_types (company_id, name, is_system) VALUES (:company_id, :name, 0)');
            $stmt->execute(['company_id' => $companyId, 'name' => $name]);
            log_activity('compliance_type', (int) db()->lastInsertId(), 'created', 'Compliance type created.', $userId);
            flash('success', 'Compliance type created.');
        } catch (Throwable $exception) {
            flash('error', 'Could not create compliance type. It may already exist.');
        }
        redirect('admin/compliance.php?view=types');
    }

    if ($action === 'toggle_compliance_type_active' && $role === 'admin') {
        $typeId = (int) ($_POST['type_id'] ?? 0);
        $stmt = db()->prepare('UPDATE compliance_types SET is_active = NOT is_active WHERE id = :id AND company_id = :company_id');
        $stmt->execute(['id' => $typeId, 'company_id' => $companyId]);
        log_activity('compliance_type', $typeId, 'toggled_active', 'Compliance type active state toggled.', $userId);
        flash('success', 'Compliance type updated.');
        redirect('admin/compliance.php?view=types');
    }

    if ($action === 'create_compliance_deadline' && $role === 'admin') {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $complianceTypeId = (int) ($_POST['compliance_type_id'] ?? 0);
        $applicablePeriod = trim((string) ($_POST['applicable_period'] ?? ''));
        $statutoryDueDate = trim((string) ($_POST['statutory_due_date'] ?? ''));
        $internalDueDate = trim((string) ($_POST['internal_due_date'] ?? ''));
        $assignedStaffUserId = (int) ($_POST['assigned_staff_user_id'] ?? 0);
        $reviewerUserId = (int) ($_POST['reviewer_user_id'] ?? 0);

        if ($clientId <= 0 || $complianceTypeId <= 0 || $applicablePeriod === '' || $statutoryDueDate === '' || !in_array($clientId, $clientIdsInScope, true)) {
            flash('error', 'Client, compliance type, period, and statutory due date are required.');
            redirect('admin/compliance.php?view=deadlines');
        }

        $typeCheck = db()->prepare('SELECT id FROM compliance_types WHERE id = :id AND company_id = :company_id LIMIT 1');
        $typeCheck->execute(['id' => $complianceTypeId, 'company_id' => $companyId]);
        if (!$typeCheck->fetch()) {
            flash('error', 'Selected compliance type was not found.');
            redirect('admin/compliance.php?view=deadlines');
        }

        $stmt = db()->prepare('INSERT INTO compliance_deadlines (company_id, client_id, compliance_type_id, applicable_period, statutory_due_date, internal_due_date, assigned_staff_user_id, reviewer_user_id, created_by) VALUES (:company_id, :client_id, :compliance_type_id, :applicable_period, :statutory_due_date, :internal_due_date, :assigned_staff_user_id, :reviewer_user_id, :created_by)');
        $stmt->execute([
            'company_id' => $companyId,
            'client_id' => $clientId,
            'compliance_type_id' => $complianceTypeId,
            'applicable_period' => $applicablePeriod,
            'statutory_due_date' => $statutoryDueDate,
            'internal_due_date' => $internalDueDate !== '' ? $internalDueDate : null,
            'assigned_staff_user_id' => $assignedStaffUserId > 0 ? $assignedStaffUserId : null,
            'reviewer_user_id' => $reviewerUserId > 0 ? $reviewerUserId : null,
            'created_by' => $userId,
        ]);
        $deadlineId = (int) db()->lastInsertId();
        log_activity('compliance_deadline', $deadlineId, 'created', 'Compliance deadline created.', $userId);
        flash('success', 'Compliance deadline created.');
        redirect('admin/compliance.php?view=deadlines');
    }

    if ($action === 'update_compliance_deadline') {
        // The page only requires the 'view' permission; editing a filing record
        // (status, filing reference/date, remarks, attached document) additionally
        // needs the 'edit' right, or a view-only staffer could mark filings done.
        require_permission('compliance', 'edit');
        $deadlineId = (int) ($_POST['deadline_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        $filingDate = trim((string) ($_POST['filing_date'] ?? ''));
        $filingReference = trim((string) ($_POST['filing_reference'] ?? ''));
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        $documentId = (int) ($_POST['document_id'] ?? 0);

        if ($deadlineId <= 0 || !in_array($status, $allowedStatus, true)) {
            flash('error', 'Invalid compliance deadline update.');
            redirect('admin/compliance.php?view=deadlines');
        }

        $deadlineStmt = db()->prepare('SELECT * FROM compliance_deadlines WHERE id = :id AND company_id = :company_id LIMIT 1');
        $deadlineStmt->execute(['id' => $deadlineId, 'company_id' => $companyId]);
        $deadline = $deadlineStmt->fetch();
        if (!$deadline || !in_array((int) $deadline['client_id'], $clientIdsInScope, true)) {
            flash('error', 'That compliance deadline is not in your scope.');
            redirect('admin/compliance.php?view=deadlines');
        }

        if ($documentId > 0) {
            $docCheck = db()->prepare('SELECT id FROM documents WHERE id = :id AND client_id = :client_id LIMIT 1');
            $docCheck->execute(['id' => $documentId, 'client_id' => (int) $deadline['client_id']]);
            if (!$docCheck->fetch()) {
                $documentId = 0;
            }
        }

        $stmt = db()->prepare('UPDATE compliance_deadlines SET status = :status, filing_date = :filing_date, filing_reference = :filing_reference, remarks = :remarks, document_id = :document_id WHERE id = :id');
        $stmt->execute([
            'status' => $status,
            'filing_date' => $filingDate !== '' ? $filingDate : null,
            'filing_reference' => $filingReference !== '' ? $filingReference : null,
            'remarks' => $remarks !== '' ? $remarks : null,
            'document_id' => $documentId > 0 ? $documentId : null,
            'id' => $deadlineId,
        ]);
        log_activity('compliance_deadline', $deadlineId, 'updated', 'Compliance deadline status updated to ' . $status . '.', $userId);
        flash('success', 'Compliance deadline updated.');
        redirect('admin/compliance.php?view=deadlines');
    }

    flash('error', 'Unsupported compliance action.');
    redirect('admin/compliance.php?view=deadlines');
}

$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$clientFilter = (int) ($_GET['client_id'] ?? 0);
$fromDate = trim((string) ($_GET['from_date'] ?? ''));
$toDate = trim((string) ($_GET['to_date'] ?? ''));

$deadlines = [];
$staffUsers = [];
$clientDocumentsByClient = [];

if (table_exists('compliance_deadlines') && $clientIdsInScope !== []) {
    $placeholders = implode(',', array_fill(0, count($clientIdsInScope), '?'));
    $where = ["cd.company_id = ?", "cd.client_id IN ($placeholders)"];
    $params = array_merge([$companyId], $clientIdsInScope);

    if ($clientFilter > 0 && in_array($clientFilter, $clientIdsInScope, true)) {
        $where[] = 'cd.client_id = ?';
        $params[] = $clientFilter;
    }
    if ($fromDate !== '') {
        $where[] = 'cd.statutory_due_date >= ?';
        $params[] = $fromDate;
    }
    if ($toDate !== '') {
        $where[] = 'cd.statutory_due_date <= ?';
        $params[] = $toDate;
    }

    $sql = "SELECT cd.*, cp.organization_name, ct.name AS compliance_type_name, us.name AS assigned_staff_name, ur.name AS reviewer_name, d.title AS document_title
        FROM compliance_deadlines cd
        INNER JOIN client_profiles cp ON cp.id = cd.client_id
        INNER JOIN compliance_types ct ON ct.id = cd.compliance_type_id
        LEFT JOIN users us ON us.id = cd.assigned_staff_user_id
        LEFT JOIN users ur ON ur.id = cd.reviewer_user_id
        LEFT JOIN documents d ON d.id = cd.document_id
        WHERE " . implode(' AND ', $where) . '
        ORDER BY cd.statutory_due_date ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $allDeadlines = $stmt->fetchAll();

    foreach ($allDeadlines as $deadline) {
        $deadline['effective_status'] = compliance_effective_status($deadline);
        if ($statusFilter !== 'all' && $deadline['effective_status'] !== $statusFilter) {
            continue;
        }
        $deadlines[] = $deadline;
    }

    $staffStmt = db()->prepare("SELECT id, name FROM users WHERE role IN ('staff', 'admin') AND status = 'active' AND company_id = :company_id ORDER BY name ASC");
    $staffStmt->execute(['company_id' => $companyId]);
    $staffUsers = $staffStmt->fetchAll();

    if (table_exists('documents')) {
        $docPlaceholders = implode(',', array_fill(0, count($clientIdsInScope), '?'));
        $docStmt = db()->prepare("SELECT id, client_id, title FROM documents WHERE client_id IN ($docPlaceholders) AND is_active = 1 ORDER BY title ASC");
        $docStmt->execute($clientIdsInScope);
        foreach ($docStmt->fetchAll() as $doc) {
            $clientDocumentsByClient[(int) $doc['client_id']][] = $doc;
        }
    }
}

$statusLabels = [
    'not_started' => 'Not started',
    'upcoming' => 'Upcoming',
    'in_progress' => 'In progress',
    'waiting_for_client' => 'Waiting for client',
    'filed' => 'Filed',
    'completed' => 'Completed',
    'overdue' => 'Overdue',
    'not_applicable' => 'Not applicable',
];

$pageTitle = $view === 'types' ? 'Compliance Types' : 'Compliance Calendar';
$pageSubtitle = $view === 'types'
    ? 'Manage the statutory compliance types available for this company'
    : 'Track statutory deadlines, filings, and assignments across your clients';

$statusTones = [
    'not_started' => 'gray',
    'upcoming' => 'blue',
    'in_progress' => 'amber',
    'waiting_for_client' => 'amber',
    'filed' => 'green',
    'completed' => 'green',
    'overdue' => 'red',
    'not_applicable' => 'gray',
];

$statusCounts = ['total' => count($deadlines), 'overdue' => 0, 'open' => 0, 'done' => 0];
foreach ($deadlines as $d) {
    $es = (string) $d['effective_status'];
    if ($es === 'overdue') {
        $statusCounts['overdue']++;
    } elseif (in_array($es, ['filed', 'completed'], true)) {
        $statusCounts['done']++;
    } elseif ($es !== 'not_applicable') {
        $statusCounts['open']++;
    }
}

include __DIR__ . '/../../app/views/partials/' . ($role === 'admin' ? 'admin_header' : 'staff_header') . '.php';
?>
<div class="actions workspace-module-bar" style="margin-bottom: 16px;">
    <a class="button<?= $view === 'deadlines' ? '' : ' secondary' ?>" href="<?= e(url('admin/compliance.php?view=deadlines')) ?>"><?= icon('compliance') ?>Deadlines</a>
    <?php if ($role === 'admin'): ?>
        <a class="button<?= $view === 'types' ? '' : ' secondary' ?>" href="<?= e(url('admin/compliance.php?view=types')) ?>"><?= icon('compliance') ?>Compliance types</a>
    <?php endif; ?>
</div>

<?php if ($view === 'deadlines'): ?>
    <section class="mbw-kpi-grid">
        <article class="mbw-kpi">
            <div>
                <span class="mbw-kpi-label">Deadlines Shown</span>
                <div class="mbw-kpi-value"><?= e((string) $statusCounts['total']) ?></div>
                <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">matching current filters</span></span>
            </div>
            <span class="mbw-chip tone-blue"><?= icon('compliance') ?></span>
        </article>
        <a class="mbw-kpi" href="<?= e(url('admin/compliance.php?view=deadlines&status=overdue')) ?>">
            <div>
                <span class="mbw-kpi-label">Overdue</span>
                <div class="mbw-kpi-value"><?= e((string) $statusCounts['overdue']) ?></div>
                <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">past statutory due date</span></span>
            </div>
            <span class="mbw-chip tone-red"><?= icon('calendar') ?></span>
        </a>
        <article class="mbw-kpi">
            <div>
                <span class="mbw-kpi-label">Open</span>
                <div class="mbw-kpi-value"><?= e((string) $statusCounts['open']) ?></div>
                <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">not yet filed</span></span>
            </div>
            <span class="mbw-chip tone-amber"><?= icon('tasks') ?></span>
        </article>
        <a class="mbw-kpi" href="<?= e(url('admin/compliance.php?view=deadlines&status=completed')) ?>">
            <div>
                <span class="mbw-kpi-label">Filed / Completed</span>
                <div class="mbw-kpi-value"><?= e((string) $statusCounts['done']) ?></div>
                <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">closed out</span></span>
            </div>
            <span class="mbw-chip tone-green"><?= icon('award') ?></span>
        </a>
    </section>

    <?php if ($role === 'admin' && $clients !== [] && $activeComplianceTypes !== []): ?>
    <section class="mbw-card" style="margin-bottom:16px;">
        <div class="mbw-card-head">
            <h2>Create Deadline</h2>
        </div>
            <details class="feature-disclosure">
                <summary>
                    <span>
                        <strong><?= icon('compliance') ?>Create deadline</strong>
                        <small>Add a statutory deadline for a client.</small>
                    </span>
                    <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
                </summary>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_compliance_deadline">
                    <label>Client
                        <select name="client_id" required>
                            <option value="">Select client</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= e((int) $client['id']) ?>"><?= e($client['organization_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Compliance type
                        <select name="compliance_type_id" required>
                            <option value="">Select type</option>
                            <?php foreach ($activeComplianceTypes as $type): ?>
                                <option value="<?= e((int) $type['id']) ?>"><?= e($type['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Applicable period<input type="text" name="applicable_period" placeholder="e.g. Shrawan 2083, Q1 FY2026-27" required></label>
                    <label>Statutory due date<input type="date" name="statutory_due_date" required></label>
                    <label>Internal due date<input type="date" name="internal_due_date"></label>
                    <label>Assigned staff
                        <select name="assigned_staff_user_id">
                            <option value="0">Unassigned</option>
                            <?php foreach ($staffUsers as $staff): ?>
                                <option value="<?= e((int) $staff['id']) ?>"><?= e($staff['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Reviewer
                        <select name="reviewer_user_id">
                            <option value="0">None</option>
                            <?php foreach ($staffUsers as $staff): ?>
                                <option value="<?= e((int) $staff['id']) ?>"><?= e($staff['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="workspace-span-2">
                        <button type="submit"><?= icon('compliance') ?>Create deadline</button>
                    </div>
                </form>
            </details>
    </section>
    <?php endif; ?>

    <section class="mbw-card" style="margin-bottom:16px;">
        <div class="mbw-card-head">
            <h2>Filters</h2>
        </div>
            <form method="get" class="users-filter-grid">
                <input type="hidden" name="view" value="deadlines">
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
                <label>From<input type="date" name="from_date" value="<?= e($fromDate) ?>"></label>
                <label>To<input type="date" name="to_date" value="<?= e($toDate) ?>"></label>
                <div class="users-filter-actions">
                    <button type="submit">Apply</button>
                    <a class="button secondary" href="<?= e(url('admin/compliance.php?view=deadlines')) ?>">Reset</a>
                </div>
            </form>
    </section>

    <section class="mbw-card">
        <div class="mbw-card-head">
            <h2>Compliance Deadlines</h2>
        </div>
        <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Type / Period</th>
                        <th>Statutory due</th>
                        <th>Status</th>
                        <th>Assigned staff</th>
                        <th>Filing details</th>
                        <th>Update</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($deadlines === []): ?>
                        <tr><td colspan="7">No compliance deadlines found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($deadlines as $deadline): ?>
                        <tr>
                            <td><?= e($deadline['organization_name']) ?></td>
                            <td><?= e($deadline['compliance_type_name']) ?><br><small><?= e($deadline['applicable_period']) ?></small></td>
                            <td><?= e($deadline['statutory_due_date']) ?></td>
                            <td><span class="mbw-pill tone-<?= e($statusTones[$deadline['effective_status']] ?? 'gray') ?>"><?= e($statusLabels[$deadline['effective_status']] ?? $deadline['effective_status']) ?></span></td>
                            <td><?= e($deadline['assigned_staff_name'] ?? 'Unassigned') ?></td>
                            <td>
                                <?php if ($deadline['filing_date']): ?>
                                    <small>Filed: <?= e($deadline['filing_date']) ?><?= $deadline['filing_reference'] ? ' (' . e($deadline['filing_reference']) . ')' : '' ?></small><br>
                                <?php endif; ?>
                                <?php if ($deadline['document_title']): ?>
                                    <a href="<?= e(url('document-download.php?id=' . (int) $deadline['document_id'] . '&inline=1')) ?>" target="_blank" rel="noopener">👁️ Preview</a>
                                    <a href="<?= e(url('document-download.php?id=' . (int) $deadline['document_id'])) ?>">📎 <?= e($deadline['document_title']) ?></a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" class="inline-action-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update_compliance_deadline">
                                    <input type="hidden" name="deadline_id" value="<?= e((int) $deadline['id']) ?>">
                                    <select name="status">
                                        <?php foreach ($statusLabels as $key => $label): ?>
                                            <option value="<?= e($key) ?>" <?= $deadline['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="date" name="filing_date" value="<?= e((string) ($deadline['filing_date'] ?? '')) ?>" placeholder="Filing date">
                                    <input type="text" name="filing_reference" value="<?= e((string) ($deadline['filing_reference'] ?? '')) ?>" placeholder="Filing reference">
                                    <?php $clientDocs = $clientDocumentsByClient[(int) $deadline['client_id']] ?? []; ?>
                                    <?php if ($clientDocs !== []): ?>
                                        <select name="document_id">
                                            <option value="0">No document</option>
                                            <?php foreach ($clientDocs as $doc): ?>
                                                <option value="<?= e((int) $doc['id']) ?>" <?= (int) ($deadline['document_id'] ?? 0) === (int) $doc['id'] ? 'selected' : '' ?>><?= e($doc['title']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                    <input type="text" name="remarks" value="<?= e((string) ($deadline['remarks'] ?? '')) ?>" placeholder="Remarks">
                                    <button type="submit" class="button secondary">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($view === 'types' && $role === 'admin'): ?>
    <section class="mbw-card" style="margin-bottom:16px;">
        <div class="mbw-card-head">
            <h2>Create Type</h2>
        </div>
        <details class="feature-disclosure">
            <summary>
                <span>
                    <strong><?= icon('compliance') ?>Create type</strong>
                    <small>Add a new compliance type for this company.</small>
                </span>
                <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
            </summary>
            <form method="post" class="workspace-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_compliance_type">
                <label>Type name<input type="text" name="name" maxlength="150" required></label>
                <div>
                    <button type="submit"><?= icon('compliance') ?>Create type</button>
                </div>
            </form>
        </details>
    </section>

    <section class="mbw-card">
        <div class="mbw-card-head">
            <h2>Compliance Types</h2>
        </div>
        <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($complianceTypes === []): ?>
                        <tr><td colspan="3">No compliance types yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($complianceTypes as $type): ?>
                        <tr>
                            <td><?= e($type['name']) ?></td>
                            <td><span class="mbw-pill <?= (int) $type['is_active'] === 1 ? 'tone-green' : 'tone-red' ?>"><?= (int) $type['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                            <td>
                                <form method="post" class="inline-action-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="toggle_compliance_type_active">
                                    <input type="hidden" name="type_id" value="<?= e((int) $type['id']) ?>">
                                    <button type="submit" class="button secondary"><?= (int) $type['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php
// ---------------------------------------------------------------------------
// Government interest & fines calculator (Nepali-month based). CALCULATES
// ONLY — the books change only when an admin presses "Post to books".
// ---------------------------------------------------------------------------
$dutyLedgers = fines_duty_tax_ledgers($companyId);
$calcLedgerId = (int) ($_GET['calc_ledger'] ?? 0);
$calcType = (string) ($_GET['calc_type'] ?? 'vat') === 'duties' ? 'duties' : 'vat';
$calcAmount = round((float) ($_GET['calc_amount'] ?? 0), 2);
$calcDue = trim((string) ($_GET['calc_due'] ?? ''));
$calcAsOf = trim((string) ($_GET['calc_asof'] ?? '')) ?: date('Y-m-d');
$calcResult = null;
if ($calcAmount > 0 && $calcDue !== '') {
    $calcResult = $calcType === 'vat'
        ? fines_vat_charges($calcAmount, $calcDue, $calcAsOf)
        : fines_duties_interest($calcAmount, $calcDue, $calcAsOf);
}
$overdueStmt = db()->prepare("SELECT cd.statutory_due_date, ct.name AS type_name
    FROM compliance_deadlines cd INNER JOIN compliance_types ct ON ct.id = cd.compliance_type_id
    WHERE cd.company_id = :cid AND cd.statutory_due_date < CURDATE()
      AND cd.status NOT IN ('filed', 'completed', 'not_applicable')
    ORDER BY cd.statutory_due_date DESC LIMIT 12");
$overdueStmt->execute(['cid' => $companyId]);
$overdueDeadlines = $overdueStmt->fetchAll();
?>
<section class="mbw-card" aria-label="Government interest and fines calculator">
    <div class="mbw-card-head">
        <h2><?= icon('analytics') ?>Government Interest &amp; Fines (calculator)</h2>
        <div class="mbw-card-tools"><span class="mbw-pill tone-amber">Calculates only — posts nothing until an admin does</span></div>
    </div>
    <form method="get" class="workspace-form-grid">
        <label>Charge type
            <select name="calc_type">
                <option value="vat" <?= $calcType === 'vat' ? 'selected' : '' ?>>VAT late payment (15% p.a. by BS month + 10% p.a. additional, daily)</option>
                <option value="duties" <?= $calcType === 'duties' ? 'selected' : '' ?>>SST / other Duties &amp; Taxes (15% p.a. by BS month)</option>
            </select>
        </label>
        <label>Tax ledger (Duties &amp; Taxes)
            <select name="calc_ledger" class="js-searchable" onchange="var o=this.options[this.selectedIndex];var a=this.form.querySelector('[name=calc_amount]');if(o.dataset.bal&&(!a.value||parseFloat(a.value)<=0)){a.value=o.dataset.bal;}">
                <option value="0">— pick or enter amount manually —</option>
                <?php foreach ($dutyLedgers as $dl): ?>
                    <option value="<?= (int) $dl['id'] ?>" data-bal="<?= e(number_format(max(0.0, (float) $dl['credit_balance']), 2, '.', '')) ?>" <?= $calcLedgerId === (int) $dl['id'] ? 'selected' : '' ?>>
                        <?= e($dl['code'] . ' — ' . $dl['name'] . ' (balance ' . number_format((float) $dl['credit_balance'], 2) . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Unpaid amount<input type="number" step="0.01" min="0" name="calc_amount" value="<?= $calcAmount > 0 ? e(number_format($calcAmount, 2, '.', '')) : '' ?>" required></label>
        <label>Due date (statutory)
            <input type="date" name="calc_due" value="<?= e($calcDue) ?>" required list="overdue-deadlines">
            <datalist id="overdue-deadlines">
                <?php foreach ($overdueDeadlines as $od): ?>
                    <option value="<?= e((string) $od['statutory_due_date']) ?>"><?= e((string) $od['type_name']) ?></option>
                <?php endforeach; ?>
            </datalist>
        </label>
        <label>As of<input type="date" name="calc_asof" value="<?= e($calcAsOf) ?>"></label>
        <div><button type="submit" class="button secondary"><?= icon('analytics') ?>Calculate</button></div>
    </form>
    <?php if ($overdueDeadlines !== []): ?>
        <p style="margin:8px 0 0;color:var(--mbw-muted);font-size:12px">Overdue on the calendar: <?php foreach (array_slice($overdueDeadlines, 0, 4) as $od): ?><span class="mbw-pill tone-red" style="margin-right:4px"><?= e($od['type_name'] . ' · due ' . $od['statutory_due_date']) ?></span><?php endforeach; ?> — pick the due date from the list in the Due date field.</p>
    <?php endif; ?>
    <?php if ($calcResult !== null): ?>
        <div style="margin-top:14px;overflow-x:auto">
        <table>
            <thead><tr><th>Charge</th><th>Basis</th><th class="is-numeric">Amount</th></tr></thead>
            <tbody>
                <tr>
                    <td>Interest 15% p.a. (simple)</td>
                    <td><?= (int) $calcResult['months'] ?> Nepali month<?= $calcResult['months'] === 1 ? '' : 's' ?> (month or part of month, BS calendar)</td>
                    <td class="is-numeric"><?= e(number_format($calcResult['interest_15'], 2)) ?></td>
                </tr>
                <?php if ($calcType === 'vat'): ?>
                <tr>
                    <td>Additional duty 10% p.a. (simple, daily)</td>
                    <td><?= (int) $calcResult['days'] ?> day<?= $calcResult['days'] === 1 ? '' : 's' ?> late</td>
                    <td class="is-numeric"><?= e(number_format($calcResult['additional_10'], 2)) ?></td>
                </tr>
                <?php endif; ?>
                <tr style="font-weight:700"><td colspan="2">Total charge on <?= e(number_format($calcAmount, 2)) ?> unpaid (due <?= e($calcDue) ?>, as of <?= e($calcAsOf) ?>)</td>
                    <td class="is-numeric"><?= e(number_format($calcResult['total'], 2)) ?></td></tr>
            </tbody>
        </table>
        </div>
        <?php if ($role === 'admin' && $calcResult['total'] > 0): ?>
            <form method="post" style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap"
                  data-confirm="Post <?= e(number_format($calcResult['total'], 2)) ?> to the books now? Dr Government Fines &amp; Interest / Cr the selected tax ledger. Until you confirm, nothing is posted.">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="post_fine">
                <input type="hidden" name="fine_ledger_id" value="<?= (int) $calcLedgerId ?>">
                <input type="hidden" name="fine_amount" value="<?= e(number_format($calcResult['total'], 2, '.', '')) ?>">
                <input type="hidden" name="fine_narration" value="<?= e(($calcType === 'vat' ? 'VAT late-payment interest 15% (' . $calcResult['months'] . ' BS months) + additional 10% (' . $calcResult['days'] . ' days)' : 'Late-payment interest 15% (' . $calcResult['months'] . ' BS months)') . ' on ' . number_format($calcAmount, 2) . ' due ' . $calcDue) ?>">
                <button type="submit" <?= $calcLedgerId > 0 ? '' : 'disabled title="Pick the tax ledger above first — the credit must land on the right payable"' ?>><?= icon('badge-check') ?>Post to books (admin)</button>
                <small style="color:var(--mbw-muted)">Dr Government Fines &amp; Interest (expense, auto-created) / Cr <?= e($calcLedgerId > 0 ? 'the selected tax ledger' : '— select a ledger') ?>.</small>
            </form>
        <?php endif; ?>
    <?php endif; ?>
    <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12px">VAT: 15% p.a. simple interest per started Nepali month PLUS 10% p.a. additional duty counted daily. SST and other Duties &amp; Taxes heads: 15% p.a. simple interest per started Nepali month. Month counting follows the Bikram Sambat calendar ("a month or part of a month" counts in full).</p>
</section>
<?php include __DIR__ . '/../../app/views/partials/' . ($role === 'admin' ? 'admin_footer' : 'staff_footer') . '.php'; ?>
