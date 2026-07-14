<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();
require_company_context();
$pageTitle = 'Users Workflow';
$pageSubtitle = 'Manage user accounts, roles, approvals, and staff KYC records.';
$currentAdmin = current_user();
$company = current_company();
$companyId = (int) ($company['id'] ?? 0);
$allowedRoles = ['all', 'customer', 'staff', 'admin'];
$allowedStatuses = ['all', 'active', 'inactive'];
$allowedSort = [
    'created_desc' => 'u.created_at DESC',
    'created_asc' => 'u.created_at ASC',
    'name_asc' => 'u.name ASC, u.created_at DESC',
    'name_desc' => 'u.name DESC, u.created_at DESC',
    'email_asc' => 'u.email ASC, u.created_at DESC',
    'email_desc' => 'u.email DESC, u.created_at DESC',
];
$perPageOptions = [10, 20, 50, 100];
$staffWorkflowActions = ['save_staff_profile', 'upload_kyc_document', 'review_kyc_document', 'save_permissions'];
$hasAccessLevels = column_exists('users', 'access_level');

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['action'] ?? '') === 'save_role_matrix'
) {
    verify_csrf();

    try {
        $submittedMatrix = (array) ($_POST['cap'] ?? []);

        save_access_level_capabilities(
            $companyId,
            $submittedMatrix,
            (int) ($currentAdmin['id'] ?? 0)
        );

        security_event(
            'role_matrix_updated',
            'success',
            'Access-level capability matrix updated.',
            $companyId,
            (int) ($currentAdmin['id'] ?? 0)
        );

        log_activity(
            'company',
            $companyId,
            'role_matrix_updated',
            'Role and responsibility matrix updated.',
            (int) ($currentAdmin['id'] ?? 0)
        );

        flash('success', 'Role permissions updated successfully.');
    } catch (Throwable $exception) {
        flash('error', 'Could not update role permissions: ' . $exception->getMessage());
    }

    redirect('admin/users.php#role-matrix');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array((string) ($_POST['action'] ?? ''), $staffWorkflowActions, true)) {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = $action === 'create' ? 'staff' : (string) ($_POST['role'] ?? 'staff');
        $status = (string) ($_POST['status'] ?? 'active');
        $accessLevel = $hasAccessLevels ? (string) ($_POST['access_level'] ?? '') : '';
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $selectedCompanyId = $action === 'create' ? $companyId : (int) ($_POST['company_id'] ?? $companyId);

        if ($name === '' || $email === '' || ($action === 'create' && $password === '')) {
            flash('error', 'Name, email, and password are required for new users.');
            redirect('admin/users.php');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please provide a valid email address.');
            redirect('admin/users.php');
        }

        if ($action === 'create' && strlen($password) < 8) {
            flash('error', 'Password must be at least 8 characters.');
            redirect('admin/users.php');
        }

        if ($password !== '' && strlen($password) < 8) {
            flash('error', 'If provided, password must be at least 8 characters.');
            redirect('admin/users.php');
        }

        // Confirmation catches a mistyped password before it locks the user out.
        if ($password !== '' && $password !== (string) ($_POST['password_confirm'] ?? '')) {
            flash('error', 'Password and confirmation do not match.');
            redirect('admin/users.php' . ($action === 'update' && $userId > 0 ? '?edit=' . $userId : ''));
        }

        if (!in_array($role, ['customer', 'staff', 'admin'], true)) {
            $role = 'customer';
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }
        if ($hasAccessLevels && !array_key_exists($accessLevel, ACCESS_LEVELS)) {
            $accessLevel = default_access_level_for_role($role);
        }

        if ($action === 'create') {
            try {
                $newUserId = create_user([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'role' => $role,
                    'status' => $status,
                    'company_id' => $selectedCompanyId,
                    'phone' => $phone,
                    'company' => null,
                    'access_level' => $accessLevel,
                ]);

                log_activity('user', $newUserId, 'created', 'User profile created from admin workflow.', (int) ($currentAdmin['id'] ?? 0));
                log_activity('user', $newUserId, 'status_changed', 'User status set to ' . $status . '.', (int) ($currentAdmin['id'] ?? 0));
                flash('success', 'User created successfully.');
            } catch (Throwable $exception) {
                flash('error', 'Could not create user. The email may already exist.');
            }

            redirect('admin/users.php');
        }

        if ($userId <= 0) {
            flash('error', 'Invalid user selected for update.');
            redirect('admin/users.php');
        }

        $existingStmt = db()->prepare('SELECT id, name, email, role, status, phone, company' . ($hasAccessLevels ? ', access_level' : '') . ' FROM users WHERE id = :id AND company_id = :company_id LIMIT 1');
        $existingStmt->execute(['id' => $userId, 'company_id' => $companyId]);
        $existingUser = $existingStmt->fetch();
        if (!$existingUser) {
            flash('error', 'User not found.');
            redirect('admin/users.php');
        }

        if ((int) $existingUser['id'] === (int) ($currentAdmin['id'] ?? 0) && $status !== 'active') {
            flash('error', 'You cannot deactivate your own account.');
            redirect('admin/users.php');
        }

        $duplicateEmailStmt = db()->prepare(
            'SELECT id
             FROM users
             WHERE email = :email
               AND id <> :id
             LIMIT 1'
        );

        $duplicateEmailStmt->execute([
            'email' => $email,
            'id' => $userId,
        ]);

        if ($duplicateEmailStmt->fetch()) {
            flash(
                'error',
                'This email address is already assigned to another user.'
            );

            redirect('admin/users.php?edit=' . $userId);
        }
        try {
            $params = [
                'id' => $userId,
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'status' => $status,
                'phone' => $phone !== '' ? $phone : null,
                'company' => null,
                'company_id' => $companyId,
            ];

            $sql = 'UPDATE users SET name = :name, email = :email, role = :role, status = :status, phone = :phone, company = :company';
            if ($hasAccessLevels) {
                $sql .= ', access_level = :access_level';
                $params['access_level'] = $accessLevel;
            }
            if ($password !== '') {
                $sql .= ', password_hash = :password_hash';
                $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = :id AND company_id = :company_id';

            $stmt = db()->prepare($sql);
            $stmt->execute($params);

            $auditAfter = [
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'status' => $status,
                'phone' => $phone !== '' ? $phone : null,
                'company' => null,
            ];
            if ($hasAccessLevels) {
                $auditAfter['access_level'] = $accessLevel;
            }
            log_activity('user', $userId, 'updated', 'User profile details updated.', (int) ($currentAdmin['id'] ?? 0));
            log_field_changes('user', $userId, $existingUser, $auditAfter, $companyId, (int) ($currentAdmin['id'] ?? 0));
            $statusChanged = ($existingUser['status'] ?? '') !== $status;
            $accessChanged = $hasAccessLevels && (string) ($existingUser['access_level'] ?? '') !== (string) ($auditAfter['access_level'] ?? '');
            if ($statusChanged) {
                log_activity('user', $userId, 'status_changed', 'User status changed to ' . $status . '.', (int) ($currentAdmin['id'] ?? 0));
            }
            // Revoke sessions on any security-sensitive change: a status move
            // away from active, or a permission/role change (so the old scope
            // cannot outlive the change on an open session).
            if (($statusChanged && $status !== 'active') || $accessChanged) {
                revoke_user_sessions($userId);
                security_event('user_permission_change', 'success', 'Status/permission change forced re-login for user #' . $userId . '.', $companyId, (int) ($currentAdmin['id'] ?? 0));
            }

            flash('success', 'User updated successfully.');
        } catch (Throwable $exception) {
            error_log(
                'User update failed for user #' .
                $userId .
                ': ' .
                $exception->getMessage()
            );

            flash(
                'error',
                'Could not update the user. Please check the entered details and try again.'
            );

            redirect('admin/users.php?edit=' . $userId);
        }

        redirect('admin/users.php?view=' . $userId);
    }

    if ($action === 'approve') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            flash('error', 'Invalid user selected for approval.');
            redirect('admin/users.php');
        }

        $stmt = db()->prepare('UPDATE users SET status = :status WHERE id = :id AND company_id = :company_id');
        $stmt->execute([
            'status' => 'active',
            'id' => $userId,
            'company_id' => $companyId,
        ]);

        log_activity('user', $userId, 'status_changed', 'User approved and marked active.', (int) ($currentAdmin['id'] ?? 0));
        flash('success', 'User approved and activated.');
        redirect('admin/users.php?view=' . $userId);
    }

    if ($action === 'suspend') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            flash('error', 'Invalid user selected for suspension.');
            redirect('admin/users.php');
        }

        if ($userId === (int) ($currentAdmin['id'] ?? 0)) {
            flash('error', 'You cannot suspend your own account.');
            redirect('admin/users.php');
        }

        $stmt = db()->prepare('UPDATE users SET status = :status WHERE id = :id AND company_id = :company_id');
        $stmt->execute([
            'status' => 'inactive',
            'id' => $userId,
            'company_id' => $companyId,
        ]);

        // Kill any live session immediately so a suspended user cannot keep
        // working from an already-open browser.
        revoke_user_sessions($userId);
        security_event('user_suspended', 'success', 'User #' . $userId . ' suspended.', $companyId, (int) ($currentAdmin['id'] ?? 0));
        log_activity('user', $userId, 'status_changed', 'User suspended and marked inactive.', (int) ($currentAdmin['id'] ?? 0));
        flash('success', 'User suspended.');
        redirect('admin/users.php?view=' . $userId);
    }

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['delete_id'] ?? 0);
        if ($deleteId <= 0) {
            flash('error', 'Invalid user selected for deletion.');
            redirect('admin/users.php');
        }

        if ($deleteId === (int) ($currentAdmin['id'] ?? 0)) {
            flash('error', 'You cannot delete your own account.');
            redirect('admin/users.php');
        }

        $userStmt = db()->prepare('SELECT id, role FROM users WHERE id = :id AND company_id = :company_id LIMIT 1');
        $userStmt->execute(['id' => $deleteId, 'company_id' => $companyId]);
        $userToDelete = $userStmt->fetch();
        if (!$userToDelete) {
            flash('error', 'User not found.');
            redirect('admin/users.php');
        }

        if (($userToDelete['role'] ?? 'customer') === 'admin') {
            flash('error', 'Admin accounts cannot be deleted from this screen.');
            redirect('admin/users.php');
        }

        $stmt = db()->prepare('DELETE FROM users WHERE id = :id AND company_id = :company_id');
        $stmt->execute([
            'id' => $deleteId,
            'company_id' => $companyId,
        ]);

        log_activity('user', $deleteId, 'deleted', 'User account deleted.', (int) ($currentAdmin['id'] ?? 0));
        flash('success', 'User deleted successfully.');
        redirect('admin/users.php');
    }

    flash('error', 'Unsupported users action.');
    redirect('admin/users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) ($_POST['action'] ?? ''), $staffWorkflowActions, true)) {
    verify_csrf();
    $action = (string) $_POST['action'];
    $targetUserId = (int) ($_POST['user_id'] ?? 0);

    $targetStmt = db()->prepare("SELECT id, role FROM users WHERE id = :id AND company_id = :company_id AND role IN ('staff', 'admin') LIMIT 1");
    $targetStmt->execute(['id' => $targetUserId, 'company_id' => $companyId]);
    $targetStaff = $targetStmt->fetch();

    if (!$targetStaff) {
        flash('error', 'Staff member not found in this company portal.');
        redirect('admin/users.php');
    }

    if ($action === 'save_permissions') {
        // Only an admin who can manage users may set granular staff permissions,
        // and only for a staff member in this company portal. Admins are never
        // constrained by staff_permissions, so we only persist for staff rows.
        if ((string) ($targetStaff['role'] ?? '') !== 'staff') {
            flash('error', 'Granular permissions apply to staff accounts only.');
            redirect('admin/users.php?view=' . $targetUserId);
        }
        $grants = array_values(array_filter((array) ($_POST['perm'] ?? []), 'is_string'));
        set_staff_permissions($targetUserId, $grants, (int) ($currentAdmin['id'] ?? 0));
        // New permissions take effect immediately: force a re-login so an open
        // session cannot keep the old scope.
        revoke_user_sessions($targetUserId);
        security_event('permission_change', 'success', 'Set ' . count($grants) . ' grants for staff #' . $targetUserId . '.', $companyId, (int) ($currentAdmin['id'] ?? 0));
        log_activity('user', $targetUserId, 'permission_change', 'Granular permissions updated (' . count($grants) . ' grants).', (int) ($currentAdmin['id'] ?? 0));
        flash('success', 'Permissions updated. The staff member will sign in again to pick up the new access.');
        redirect('admin/users.php?view=' . $targetUserId);
    }

    if ($action === 'save_staff_profile' && table_exists('staff_profiles')) {
        $profileFields = [
            'job_title' => trim((string) ($_POST['job_title'] ?? '')),
            'department' => trim((string) ($_POST['department'] ?? '')),
            'education' => trim((string) ($_POST['education'] ?? '')),
            'qualifications' => trim((string) ($_POST['qualifications'] ?? '')),
            'expertise' => trim((string) ($_POST['expertise'] ?? '')),
            'bio' => trim((string) ($_POST['bio'] ?? '')),
        ];
        $teamCategory = (string) ($_POST['team_category'] ?? 'professional');
        if (!in_array($teamCategory, ['leadership', 'management', 'professional'], true)) {
            $teamCategory = 'professional';
        }
        $employmentStatus = (string) ($_POST['employment_status'] ?? 'active');
        if (!in_array($employmentStatus, ['active', 'inactive'], true)) {
            $employmentStatus = 'active';
        }
        $showOnPublicTeam = isset($_POST['show_on_public_team']) ? 1 : 0;
        $displayOrder = (int) ($_POST['display_order'] ?? 0);

        $photoPath = null;
        if (isset($_FILES['photo']) && (int) $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $photoResult = handle_staff_photo_upload($_FILES['photo']);
            if (!$photoResult['ok']) {
                flash('error', $photoResult['error']);
                redirect('admin/users.php?view=' . $targetUserId);
            }
            $photoPath = $photoResult['file_path'];
        }

        $existingProfile = staff_profile($targetUserId);
        if ($existingProfile) {
            $sql = 'UPDATE staff_profiles SET job_title = :job_title, department = :department, education = :education,
                    qualifications = :qualifications, expertise = :expertise, bio = :bio, team_category = :team_category,
                    show_on_public_team = :show_on_public_team, display_order = :display_order, employment_status = :employment_status';
            $params = $profileFields + [
                'team_category' => $teamCategory,
                'show_on_public_team' => $showOnPublicTeam,
                'display_order' => $displayOrder,
                'employment_status' => $employmentStatus,
                'user_id' => $targetUserId,
            ];
            if ($photoPath !== null) {
                $sql .= ', photo_path = :photo_path';
                $params['photo_path'] = $photoPath;
            }
            $sql .= ' WHERE user_id = :user_id';
            db()->prepare($sql)->execute($params);
        } else {
            db()->prepare('INSERT INTO staff_profiles (user_id, job_title, department, education, qualifications, expertise, bio, photo_path, team_category, show_on_public_team, display_order, employment_status)
                VALUES (:user_id, :job_title, :department, :education, :qualifications, :expertise, :bio, :photo_path, :team_category, :show_on_public_team, :display_order, :employment_status)')
                ->execute($profileFields + [
                    'user_id' => $targetUserId,
                    'photo_path' => $photoPath,
                    'team_category' => $teamCategory,
                    'show_on_public_team' => $showOnPublicTeam,
                    'display_order' => $displayOrder,
                    'employment_status' => $employmentStatus,
                ]);
        }

        log_activity('user', $targetUserId, 'profile_updated', 'Staff public profile updated.', (int) ($currentAdmin['id'] ?? 0));
        flash('success', 'Staff profile saved.');
        redirect('admin/users.php?view=' . $targetUserId);
    }

    if ($action === 'upload_kyc_document' && table_exists('staff_kyc_documents')) {
        $documentType = (string) ($_POST['document_type'] ?? '');
        if (!in_array($documentType, ['citizenship', 'national_id', 'passport', 'pan'], true)) {
            flash('error', 'Choose a valid document type.');
            redirect('admin/users.php?view=' . $targetUserId);
        }

        $uploadResult = handle_kyc_upload($_FILES['kyc_file'] ?? []);
        if (!$uploadResult['ok']) {
            flash('error', $uploadResult['error']);
            redirect('admin/users.php?view=' . $targetUserId);
        }

        db()->prepare('INSERT INTO staff_kyc_documents (staff_user_id, document_type, stored_filename, original_filename, mime_type, file_size, uploaded_by)
            VALUES (:staff_user_id, :document_type, :stored_filename, :original_filename, :mime_type, :file_size, :uploaded_by)')
            ->execute([
                'staff_user_id' => $targetUserId,
                'document_type' => $documentType,
                'stored_filename' => $uploadResult['stored_filename'],
                'original_filename' => $uploadResult['original_filename'],
                'mime_type' => $uploadResult['mime_type'],
                'file_size' => $uploadResult['file_size'],
                'uploaded_by' => (int) ($currentAdmin['id'] ?? 0),
            ]);

        log_activity('user', $targetUserId, 'kyc_uploaded', 'KYC document uploaded (' . $documentType . ').', (int) ($currentAdmin['id'] ?? 0));
        flash('success', 'KYC document stored securely.');
        redirect('admin/users.php?view=' . $targetUserId);
    }

    if ($action === 'review_kyc_document' && table_exists('staff_kyc_documents')) {
        $documentId = (int) ($_POST['document_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $remarks = trim((string) ($_POST['remarks'] ?? ''));

        if (!in_array($decision, ['verified', 'rejected', 'requires_update'], true)) {
            flash('error', 'Choose a valid verification decision.');
            redirect('admin/users.php?view=' . $targetUserId);
        }
        if (in_array($decision, ['rejected', 'requires_update'], true) && $remarks === '') {
            flash('error', 'Remarks are required when rejecting or requesting an update.');
            redirect('admin/users.php?view=' . $targetUserId);
        }

        $docCheck = db()->prepare('SELECT id FROM staff_kyc_documents WHERE id = :id AND staff_user_id = :staff_user_id LIMIT 1');
        $docCheck->execute(['id' => $documentId, 'staff_user_id' => $targetUserId]);
        if (!$docCheck->fetch()) {
            flash('error', 'KYC document not found for this staff member.');
            redirect('admin/users.php?view=' . $targetUserId);
        }

        db()->prepare('UPDATE staff_kyc_documents SET verification_status = :status, verified_by = :verified_by, verified_at = NOW(), remarks = :remarks WHERE id = :id')
            ->execute([
                'status' => $decision,
                'verified_by' => (int) ($currentAdmin['id'] ?? 0),
                'remarks' => $remarks !== '' ? $remarks : null,
                'id' => $documentId,
            ]);

        log_activity('user', $targetUserId, 'kyc_reviewed', 'KYC document #' . $documentId . ' marked ' . $decision . '.', (int) ($currentAdmin['id'] ?? 0));
        flash('success', 'KYC verification recorded.');
        redirect('admin/users.php?view=' . $targetUserId);
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? 'all'));
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$sort = trim((string) ($_GET['sort'] ?? 'created_desc'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPageInput = (int) ($_GET['per_page'] ?? 20);
$perPage = in_array($perPageInput, $perPageOptions, true) ? $perPageInput : 20;
$offset = ($page - 1) * $perPage;

if (!in_array($roleFilter, $allowedRoles, true)) {
    $roleFilter = 'all';
}

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

if (!array_key_exists($sort, $allowedSort)) {
    $sort = 'created_desc';
}

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(u.name LIKE :search1 OR u.email LIKE :search2 OR u.phone LIKE :search3 OR u.company LIKE :search4)';
    $params['search1'] = $params['search2'] = $params['search3'] = $params['search4'] = '%' . $search . '%';
}

if ($roleFilter !== 'all') {
    $where[] = 'u.role = :role_filter';
    $params['role_filter'] = $roleFilter;
}

if ($statusFilter !== 'all') {
    $where[] = 'u.status = :status_filter';
    $params['status_filter'] = $statusFilter;
}

$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$whereSql = $whereSql === '' ? ' WHERE u.company_id = :company_id' : $whereSql . ' AND u.company_id = :company_id';
$params['company_id'] = $companyId;

$baseQuery = [
    'q' => $search,
    'role' => $roleFilter,
    'status' => $statusFilter,
    'sort' => $sort,
    'per_page' => (string) $perPage,
];

$buildUsersUrl = static function (array $extra = []) use ($baseQuery): string {
    $query = array_merge($baseQuery, $extra);
    $query = array_filter($query, static fn($value) => $value !== '' && $value !== null);

    return url('admin/users.php' . ($query === [] ? '' : '?' . http_build_query($query)));
};

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportSql = 'SELECT u.id, u.name, u.email, u.role, u.status, u.phone, u.company' . ($hasAccessLevels ? ', u.access_level' : '') . ', u.created_at FROM users u' . $whereSql . ' ORDER BY ' . $allowedSort[$sort];
    $exportStmt = db()->prepare($exportSql);
    foreach ($params as $key => $value) {
        $exportStmt->bindValue(':' . $key, $value);
    }
    $exportStmt->execute();
    $rows = $exportStmt->fetchAll();

    security_event('report_exported', 'success', 'User list exported to CSV.', $companyId, (int) ($currentAdmin['id'] ?? 0));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users-export-' . date('Ymd-His') . '.csv"');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        exit('Unable to generate CSV export.');
    }

    $headers = ['ID', 'Name', 'Email', 'Role', 'Status', 'Phone', 'Company'];
    if ($hasAccessLevels) {
        $headers[] = 'Access Level';
    }
    $headers[] = 'Created At';
    fputcsv($output, $headers);

    foreach ($rows as $row) {
        $csvRow = [
            $row['id'],
            $row['name'],
            $row['email'],
            $row['role'],
            $row['status'],
            $row['phone'] ?? '',
            $row['company'] ?? '',
        ];
        if ($hasAccessLevels) {
            $csvRow[] = ACCESS_LEVELS[$row['access_level']] ?? $row['access_level'];
        }
        $csvRow[] = $row['created_at'];
        fputcsv($output, $csvRow);
    }

    fclose($output);
    exit;
}

$countSql = 'SELECT COUNT(*) FROM users u' . $whereSql;
$countStmt = db()->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue(':' . $key, $value);
}
$countStmt->execute();
$totalUsers = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalUsers / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = 'SELECT u.id, u.name, u.email, u.role, u.status, u.phone, u.company' . ($hasAccessLevels ? ', u.access_level' : '') . ', u.created_at FROM users u' . $whereSql . ' ORDER BY ' . $allowedSort[$sort] . ' LIMIT :limit OFFSET :offset';
$stmt = db()->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

$summary = [
    'all' => 0,
    'active' => 0,
    'inactive' => 0,
    'admins' => 0,
    'customers' => 0,
];

foreach (['all' => 'SELECT COUNT(*) FROM users WHERE company_id = :company_id', 'active' => "SELECT COUNT(*) FROM users WHERE company_id = :company_id AND status = 'active'", 'inactive' => "SELECT COUNT(*) FROM users WHERE company_id = :company_id AND status = 'inactive'", 'admins' => "SELECT COUNT(*) FROM users WHERE company_id = :company_id AND role = 'admin'", 'customers' => "SELECT COUNT(*) FROM users WHERE company_id = :company_id AND role = 'customer'"] as $key => $sqlSummary) {
    $stmtSummary = db()->prepare($sqlSummary);
    $stmtSummary->execute(['company_id' => $companyId]);
    $summary[$key] = (int) $stmtSummary->fetchColumn();
}

$viewUserId = (int) ($_GET['view'] ?? 0);
$viewUser = null;
$viewUserActivity = [];
$viewUserOrders = [];
if ($viewUserId > 0) {
    $viewStmt = db()->prepare('SELECT id, name, email, role, status, phone, company' . ($hasAccessLevels ? ', access_level' : '') . ', created_at FROM users WHERE id = :id AND company_id = :company_id LIMIT 1');
    $viewStmt->execute(['id' => $viewUserId, 'company_id' => $companyId]);
    $viewUser = $viewStmt->fetch() ?: null;

    if ($viewUser) {
        $viewUserActivity = activity_logs_for('user', (int) $viewUser['id'], 30);

        $orderStmt = db()->prepare('SELECT o.id, o.domain_name, o.amount, o.payment_status, o.status, o.created_at, p.name AS plan_name FROM orders o INNER JOIN plans p ON p.id = o.plan_id WHERE o.user_id = :user_id AND o.company_id = :company_id ORDER BY o.created_at DESC LIMIT 15');
        $orderStmt->execute([
            'user_id' => (int) $viewUser['id'],
            'company_id' => $companyId,
        ]);
        $viewUserOrders = $orderStmt->fetchAll();
    }
}

$viewStaffProfile = null;
$viewKycDocuments = [];
$viewKycStatus = null;
$kycTypeLabels = [
    'citizenship' => 'Citizenship certificate',
    'national_id' => 'National ID',
    'passport' => 'Passport',
    'pan' => 'PAN document',
];
if ($viewUser && in_array(($viewUser['role'] ?? ''), ['staff', 'admin'], true)) {
    $viewStaffProfile = staff_profile((int) $viewUser['id']);
    $viewKycStatus = staff_kyc_status((int) $viewUser['id']);
    if (table_exists('staff_kyc_documents')) {
        $kycStmt = db()->prepare('SELECT d.*, vu.name AS verified_by_name FROM staff_kyc_documents d LEFT JOIN users vu ON vu.id = d.verified_by WHERE d.staff_user_id = :staff_user_id ORDER BY d.uploaded_at DESC');
        $kycStmt->execute(['staff_user_id' => (int) $viewUser['id']]);
        $viewKycDocuments = $kycStmt->fetchAll();
    }
}

$editUserId = (int) ($_GET['edit'] ?? 0);
$editUser = null;
if ($editUserId > 0) {
    $editStmt = db()->prepare('SELECT id, name, email, role, status, phone, company' . ($hasAccessLevels ? ', access_level' : '') . ' FROM users WHERE id = :id AND company_id = :company_id LIMIT 1');
    $editStmt->execute(['id' => $editUserId, 'company_id' => $companyId]);
    $editUser = $editStmt->fetch() ?: null;
}

include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<!-- USERS WORKFLOW REDESIGN START -->
<div class="users-workflow-page">
<section class="uw-page-actions" aria-label="Users Workflow actions">
    <div class="uw-action-copy">
        <span class="uw-action-icon"><?= icon('users') ?></span>
        <div>
            <strong>User Access Management</strong>
            <small>Manage accounts, access levels, approvals, and KYC records.</small>
        </div>
    </div>

    <div class="uw-action-buttons">
        <button
            type="button"
            class="button uw-primary-button"
            data-modal-open="user-create-modal"
        >
            <span aria-hidden="true">+</span>
            New User
        </button>

        <a
            class="button secondary uw-secondary-button"
            href="<?= e($buildUsersUrl([
                'export' => 'csv',
                'page' => null,
            ])) ?>"
        >
            Export CSV
        </a>

        <button
            type="button"
            class="button secondary uw-secondary-button"
            onclick="window.print()"
        >
            Print
        </button>
    </div>
</section>

<details class="role-matrix-panel uw-role-matrix" id="role-matrix" open>
    <summary>
    <span class="uw-section-title">
        <span class="uw-section-icon"><?= icon('users') ?></span>
        <span>Role Matrix</span>
    </span>
    <small>Who can do what</small>
</summary>

    <?php $roleCapabilityMatrix = access_level_capabilities($companyId); ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_role_matrix">

        <div class="rc-table-scroll">
            <table class="rc-table role-matrix-table">
                <thead>
                    <tr>
                        <th>Role</th>
                        <?php foreach ([
                            'view' => 'View',
                            'create' => 'Create',
                            'edit' => 'Edit',
                            'approve' => 'Approve',
                            'post' => 'Post',
                            'delete' => 'Delete',
                            'report' => 'Report',
                            'admin' => 'Admin',
                        ] as $capLabel): ?>
                            <th class="align-center"><?= e($capLabel) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($roleCapabilityMatrix as $levelKey => $caps): ?>
                        <tr>
                            <td>
                                <strong><?= e(ACCESS_LEVELS[$levelKey] ?? $levelKey) ?></strong>
                                <?php if ($levelKey === 'super_admin'): ?>
                                    <span class="uw-protected-badge">Protected</span>
                                <?php endif; ?>
                            </td>

                            <?php foreach ([
                                'view', 'create', 'edit', 'approve',
                                'post', 'delete', 'report', 'admin',
                            ] as $cap): ?>
                                <td class="align-center">
                                    <input
                                        type="checkbox"
                                        name="cap[<?= e($levelKey) ?>][<?= e($cap) ?>]"
                                        value="1"
                                        aria-label="<?= e(
                                            (ACCESS_LEVELS[$levelKey] ?? $levelKey)
                                            . ' '
                                            . $cap
                                        ) ?>"
                                        <?= !empty($caps[$cap]) ? 'checked' : '' ?>
                                        <?= $levelKey === 'super_admin' ? 'disabled' : '' ?>
                                    >
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="actions uw-role-save-bar">
            <button type="submit" class="button">
                <?= icon('settings') ?>Save Role Permissions
            </button>

            <span class="muted">
                Super Admin remains protected. Other role permissions apply
                to users assigned that access level in this company.
            </span>
        </div>
    </form>
</details>
<section class="mbw-kpi-grid uw-summary-grid">
    <a class="mbw-kpi" href="<?= e(url('admin/users.php')) ?>">
        <div>
            <span class="mbw-kpi-label">Total Users</span>
            <div class="mbw-kpi-value"><?= e((string) $summary['all']) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">all accounts</span></span>
        </div>
        <span class="mbw-chip tone-blue"><?= icon('users') ?></span>
    </a>
    <a class="mbw-kpi" href="<?= e(url('admin/users.php?status=active')) ?>">
        <div>
            <span class="mbw-kpi-label">Active</span>
            <div class="mbw-kpi-value"><?= e((string) $summary['active']) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">can sign in</span></span>
        </div>
        <span class="mbw-chip tone-green"><?= icon('insights') ?></span>
    </a>
    <a class="mbw-kpi" href="<?= e(url('admin/users.php?status=inactive')) ?>">
        <div>
            <span class="mbw-kpi-label">Inactive</span>
            <div class="mbw-kpi-value"><?= e((string) $summary['inactive']) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">suspended / pending</span></span>
        </div>
        <span class="mbw-chip tone-amber"><?= icon('settings') ?></span>
    </a>
    <a class="mbw-kpi" href="<?= e(url('admin/users.php?role=customer')) ?>">
        <div>
            <span class="mbw-kpi-label">Customers</span>
            <div class="mbw-kpi-value"><?= e((string) $summary['customers']) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">client logins</span></span>
        </div>
        <span class="mbw-chip tone-teal"><?= icon('clients') ?></span>
    </a>
    <a class="mbw-kpi" href="<?= e(url('admin/users.php?role=admin')) ?>">
        <div>
            <span class="mbw-kpi-label">Admins</span>
            <div class="mbw-kpi-value"><?= e((string) $summary['admins']) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">full access</span></span>
        </div>
        <span class="mbw-chip tone-purple"><?= icon('admin') ?></span>
    </a>
</section>

<section
    class="mbw-card users-filters-card uw-filter-panel"
    id="users-filters-panel"
    <?= (
        $search === ''
        && $roleFilter === 'all'
        && $statusFilter === 'all'
        && $sort === 'created_desc'
        && $perPage === 10
    ) ? 'hidden' : '' ?>
>
    <div class="mbw-card-head">
        <h2>Search &amp; Filters</h2>
        <div class="mbw-card-tools">
            <a class="mbw-view-all" href="<?= e(url('admin/users.php')) ?>">Reset</a>
        </div>
    </div>
    <form method="get" class="users-filter-grid" id="users-filter-form">
        <label>Search
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Name, email, phone, company">
        </label>
        <label>Role
            <select name="role">
                <option value="all" <?= $roleFilter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="customer" <?= $roleFilter === 'customer' ? 'selected' : '' ?>>Customer</option>
                <option value="staff" <?= $roleFilter === 'staff' ? 'selected' : '' ?>>Staff</option>
                <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>
        </label>
        <label>Status
            <select name="status">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </label>
        <label>Sort
            <select name="sort">
                <option value="created_desc" <?= $sort === 'created_desc' ? 'selected' : '' ?>>Newest first</option>
                <option value="created_asc" <?= $sort === 'created_asc' ? 'selected' : '' ?>>Oldest first</option>
                <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                <option value="email_asc" <?= $sort === 'email_asc' ? 'selected' : '' ?>>Email A-Z</option>
                <option value="email_desc" <?= $sort === 'email_desc' ? 'selected' : '' ?>>Email Z-A</option>
            </select>
        </label>
        <label>Per page
            <select name="per_page">
                <?php foreach ($perPageOptions as $option): ?>
                    <option value="<?= e((string) $option) ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= e((string) $option) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="actions users-filter-actions">
            <button type="submit" id="users-apply-button">Apply</button>
            <a class="button secondary" href="<?= e(url('admin/users.php')) ?>">Reset</a>
        </div>
    </form>
</section>

<section class="mbw-card uw-directory-card">
    <div class="mbw-card-head">
        <h2>
    <span class="uw-section-icon"><?= icon('users') ?></span>
    Users Directory
</h2>
        <div class="mbw-card-tools">
            <button
    type="button"
    class="button secondary uw-filter-toggle"
    aria-controls="users-filters-panel"
    aria-expanded="<?= (
        $search !== ''
        || $roleFilter !== 'all'
        || $statusFilter !== 'all'
        || $sort !== 'created_desc'
        || $perPage !== 10
    ) ? 'true' : 'false' ?>"
    onclick="
        const panel = document.getElementById('users-filters-panel');
        panel.hidden = !panel.hidden;
        this.setAttribute('aria-expanded', String(!panel.hidden));
    "
>
    Filters
</button>

<span class="uw-directory-count">
    Showing <?= e((string) count($users)) ?>
    of <?= e((string) $totalUsers) ?> user(s)
</span>
        </div>
    </div>
    <div style="overflow-x:auto">
    <table class="uw-users-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>User</th>
                <th class="uw-email-column">Email</th>
                <th>Role</th>
                <?php if ($hasAccessLevels): ?><th>Access</th><?php endif; ?>
                <th>Status</th>
                <th>Phone</th>
                <th>Company</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($users === []): ?>
                <tr>
                    <td colspan="<?= $hasAccessLevels ? '10' : '9' ?>">No users found for selected filters.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td>#<?= e((int) $user['id']) ?></td>
                    <?php
    $uwNameParts = preg_split(
        '/\s+/',
        trim((string) $user['name'])
    ) ?: [];

    $uwInitials = '';

    foreach (array_slice($uwNameParts, 0, 2) as $uwNamePart) {
        $uwInitials .= strtoupper(substr($uwNamePart, 0, 1));
    }

    if ($uwInitials === '') {
        $uwInitials = 'U';
    }

    $uwAvatarTone = ((int) $user['id']) % 3;
?>
<td class="uw-user-column">
    <div class="uw-user-identity">
        <span class="uw-avatar uw-avatar-tone-<?= e((string) $uwAvatarTone) ?>">
            <?= e($uwInitials) ?>
        </span>

        <span class="uw-user-details">
            <strong><?= e($user['name']) ?></strong>
            <small><?= e($user['email']) ?></small>
        </span>
    </div>
</td>
                    <td class="uw-email-column"><?= e($user['email']) ?></td>
                    <td>
                        <?php $roleTone = ($user['role'] ?? '') === 'admin' ? 'tone-purple' : (($user['role'] ?? '') === 'staff' ? 'tone-blue' : 'tone-teal'); ?>
                        <span class="mbw-pill <?= $roleTone ?>"><?= e($user['role']) ?></span>
                        <?php if (in_array(($user['role'] ?? ''), ['staff', 'admin'], true)): ?>
                            <?php $rowKycStatus = staff_kyc_status((int) $user['id']); ?>
                            <br><small style="color:var(--mbw-muted);">KYC: <?= e($rowKycStatus) ?></small>
                        <?php endif; ?>
                    </td>
                    <?php if ($hasAccessLevels): ?>
                        <td><span class="mbw-pill tone-gray"><?= e(ACCESS_LEVELS[$user['access_level']] ?? $user['access_level']) ?></span></td>
                    <?php endif; ?>
                    <td><span class="mbw-pill <?= ($user['status'] ?? '') === 'active' ? 'tone-green' : 'tone-red' ?>"><?= e($user['status']) ?></span></td>
                    <td><?= e($user['phone'] ?? '-') ?></td>
                    <td><?= e($user['company'] ?? '-') ?></td>
                    <td><small><?= e($user['created_at']) ?></small></td>
                    <td>
                        <div class="actions users-row-actions">
                            <a class="button secondary" href="<?= e($buildUsersUrl(['view' => (int) $user['id']])) ?>">View</a>
                            <a class="button secondary" href="<?= e($buildUsersUrl(['edit' => (int) $user['id']])) ?>">Edit</a>

                            <?php if (($user['status'] ?? 'active') === 'inactive'): ?>
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="user_id" value="<?= e((int) $user['id']) ?>">
                                    <button type="submit" class="secondary">Approve</button>
                                </form>
                            <?php elseif ((int) $user['id'] !== (int) ($currentAdmin['id'] ?? 0)): ?>
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="suspend">
                                    <input type="hidden" name="user_id" value="<?= e((int) $user['id']) ?>">
                                    <button type="submit" class="secondary">Suspend</button>
                                </form>
                            <?php endif; ?>

                            <?php if (($user['role'] ?? 'customer') !== 'admin' && (int) $user['id'] !== (int) ($currentAdmin['id'] ?? 0)): ?>
                                <form method="post" style="margin:0;" onsubmit="return confirm('Delete this user account?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="delete_id" value="<?= e((int) $user['id']) ?>">
                                    <button type="submit" class="secondary">Delete</button>
                                </form>
                            <?php else: ?>
                                <span class="mbw-pill tone-gray">Protected</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="admin-orders-pagination">
        <?php if ($page > 1): ?>
            <a class="button secondary" href="<?= e($buildUsersUrl(['page' => $page - 1])) ?>">Previous</a>
        <?php endif; ?>
        <span>Page <?= e((string) $page) ?> of <?= e((string) $totalPages) ?></span>
        <?php if ($page < $totalPages): ?>
            <a class="button secondary" href="<?= e($buildUsersUrl(['page' => $page + 1])) ?>">Next</a>
        <?php endif; ?>
    </div>
</section>

<?php if ($viewUser): ?>
    <div class="modal-overlay is-open" data-modal="user-view-modal" role="dialog" aria-modal="true" aria-labelledby="user-view-title">
        <div class="modal-card">
            <div class="modal-head">
                <h3 id="user-view-title">User #<?= e((int) $viewUser['id']) ?></h3>
                <a class="button secondary" href="<?= e($buildUsersUrl(['view' => null])) ?>">Close</a>
            </div>

            <div class="users-view-grid">
                <div class="card">
                    <h3>Profile details</h3>
                    <p><strong>Name:</strong> <?= e($viewUser['name']) ?></p>
                    <p><strong>Email:</strong> <?= e($viewUser['email']) ?></p>
                    <p><strong>Role:</strong> <?= e($viewUser['role']) ?></p>
                    <?php if ($hasAccessLevels): ?><p><strong>Access:</strong> <?= e(ACCESS_LEVELS[$viewUser['access_level']] ?? $viewUser['access_level']) ?></p><?php endif; ?>
                    <p><strong>Status:</strong> <?= e($viewUser['status']) ?></p>
                    <p><strong>Phone:</strong> <?= e($viewUser['phone'] ?? '-') ?></p>
                    <p><strong>Company:</strong> <?= e($viewUser['company'] ?? '-') ?></p>
                    <p><strong>Created:</strong> <?= e($viewUser['created_at']) ?></p>
                </div>

                <div class="card">
                    <h3>Quick actions</h3>
                    <div class="actions" style="margin-top:0;">
                        <a class="button secondary" href="<?= e($buildUsersUrl(['edit' => (int) $viewUser['id'], 'view' => null])) ?>">Edit profile</a>
                        <?php if (($viewUser['status'] ?? 'active') === 'inactive'): ?>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="user_id" value="<?= e((int) $viewUser['id']) ?>">
                                <button type="submit" class="secondary">Approve account</button>
                            </form>
                        <?php elseif ((int) $viewUser['id'] !== (int) ($currentAdmin['id'] ?? 0)): ?>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="suspend">
                                <input type="hidden" name="user_id" value="<?= e((int) $viewUser['id']) ?>">
                                <button type="submit" class="secondary">Suspend account</button>
                            </form>
                        <?php endif; ?>
                        <?php if (($viewUser['role'] ?? 'customer') !== 'admin' && (int) $viewUser['id'] !== (int) ($currentAdmin['id'] ?? 0)): ?>
                            <form method="post" style="margin:0;" onsubmit="return confirm('Delete this user account?');">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="delete_id" value="<?= e((int) $viewUser['id']) ?>">
                                <button type="submit" class="secondary">Delete account</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (($viewUser['role'] ?? '') === 'staff'): ?>
                <?php
                $viewUserPermKeys = staff_permission_keys((int) $viewUser['id']);
                $viewUserConfigured = $viewUserPermKeys !== [];
                ?>
                <div class="form-card">
                    <h3>Module permissions</h3>
                    <p class="muted">
                        Tick the exact actions this staff member may perform. While nothing is ticked the account keeps
                        <strong>full legacy access</strong>; saving any selection switches it to <strong>strict mode</strong>
                        — it can then do only what is checked. Admins are never restricted here.
                        <?php if ($viewUserConfigured): ?>
                            <span class="mbw-pill tone-amber">Strict mode — <?= e((string) count($viewUserPermKeys)) ?> grants</span>
                        <?php else: ?>
                            <span class="mbw-pill tone-gray">Unconfigured — full access</span>
                        <?php endif; ?>
                    </p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_permissions">
                        <input type="hidden" name="user_id" value="<?= e((int) $viewUser['id']) ?>">
                        <div class="rc-table-scroll">
                            <table class="rc-table role-matrix-table perm-matrix-table">
                                <thead>
                                    <tr>
                                        <th>Module</th>
                                        <?php foreach (rbac_action_labels() as $actLabel): ?>
                                            <th class="align-center"><?= e($actLabel) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (rbac_modules() as $modKey => $mod): ?>
                                        <tr>
                                            <td><strong><?= e($mod['label']) ?></strong></td>
                                            <?php foreach (array_keys(rbac_action_labels()) as $actKey): ?>
                                                <td class="align-center">
                                                    <?php if (in_array($actKey, $mod['actions'], true)): ?>
                                                        <?php $pk = $modKey . '.' . $actKey; ?>
                                                        <input type="checkbox" name="perm[]" value="<?= e($pk) ?>" aria-label="<?= e($mod['label'] . ' ' . $actKey) ?>" <?= isset($viewUserPermKeys[$pk]) ? 'checked' : '' ?>>
                                                    <?php else: ?>
                                                        <span class="matrix-no">–</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="actions" style="margin-top:12px;">
                            <button type="submit" class="button">Save permissions</button>
                            <?php if ($viewUserConfigured): ?>
                                <span class="muted">Uncheck everything and save to return this account to full legacy access.</span>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (in_array(($viewUser['role'] ?? ''), ['staff', 'admin'], true)): ?>
                <div class="form-card">
                    <h3>Public profile</h3>
                    <p class="muted">Only these fields can appear on the public team page — never contact details, documents, or credentials. The member stays hidden until "Show on public team page" is ticked.</p>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_staff_profile">
                        <input type="hidden" name="user_id" value="<?= e((int) $viewUser['id']) ?>">
                        <div class="users-edit-grid">
                            <label>Job title<input type="text" name="job_title" maxlength="150" value="<?= e($viewStaffProfile['job_title'] ?? '') ?>"></label>
                            <label>Department<input type="text" name="department" maxlength="150" value="<?= e($viewStaffProfile['department'] ?? '') ?>"></label>
                            <label>Education<input type="text" name="education" maxlength="255" value="<?= e($viewStaffProfile['education'] ?? '') ?>"></label>
                            <label>Professional qualifications<input type="text" name="qualifications" maxlength="255" value="<?= e($viewStaffProfile['qualifications'] ?? '') ?>"></label>
                            <label>Areas of expertise<input type="text" name="expertise" maxlength="255" value="<?= e($viewStaffProfile['expertise'] ?? '') ?>"></label>
                            <label>Team category
                                <select name="team_category">
                                    <option value="leadership" <?= ($viewStaffProfile['team_category'] ?? '') === 'leadership' ? 'selected' : '' ?>>Leadership</option>
                                    <option value="management" <?= ($viewStaffProfile['team_category'] ?? '') === 'management' ? 'selected' : '' ?>>Management</option>
                                    <option value="professional" <?= ($viewStaffProfile['team_category'] ?? 'professional') === 'professional' ? 'selected' : '' ?>>Professional</option>
                                </select>
                            </label>
                            <label>Display order<input type="number" name="display_order" value="<?= e((int) ($viewStaffProfile['display_order'] ?? 0)) ?>"></label>
                            <label>Employment status
                                <select name="employment_status">
                                    <option value="active" <?= ($viewStaffProfile['employment_status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= ($viewStaffProfile['employment_status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </label>
                            <label>Profile photo (JPG/PNG/WEBP, max 3 MB)<input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp"></label>
                            <label class="checkbox-line users-full">
                                <input type="checkbox" name="show_on_public_team" value="1" <?= (int) ($viewStaffProfile['show_on_public_team'] ?? 0) === 1 ? 'checked' : '' ?>>
                                Show on public team page
                            </label>
                            <label class="users-full">Short biography<textarea name="bio" rows="3"><?= e($viewStaffProfile['bio'] ?? '') ?></textarea></label>
                        </div>
                        <div class="actions">
                            <button type="submit">Save profile</button>
                        </div>
                    </form>
                </div>

                <div class="form-card">
                    <h3>KYC documents
                        <?php if ($viewKycStatus === 'verified'): ?>
                            <span class="mbw-pill tone-green">KYC verified</span>
                        <?php elseif ($viewKycStatus === 'submitted'): ?>
                            <span class="mbw-pill tone-blue">KYC submitted</span>
                        <?php else: ?>
                            <span class="mbw-pill tone-amber">KYC pending</span>
                        <?php endif; ?>
                    </h3>
                    <?php if ($viewKycStatus !== 'verified'): ?>
                        <p class="muted">KYC requires at least one identity document (citizenship certificate, national ID, or passport) <strong>and</strong> a PAN document, each verified by an administrator. Files are stored in a protected directory outside the public website and are never shown publicly.</p>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data" class="inline-action-form" style="flex-wrap: wrap;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="upload_kyc_document">
                        <input type="hidden" name="user_id" value="<?= e((int) $viewUser['id']) ?>">
                        <select name="document_type" required>
                            <option value="">Document type</option>
                            <?php foreach ($kycTypeLabels as $typeKey => $typeLabel): ?>
                                <option value="<?= e($typeKey) ?>"><?= e($typeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="file" name="kyc_file" accept=".pdf,.jpg,.jpeg,.png" required>
                        <button type="submit">Upload document</button>
                    </form>

                    <?php if ($viewKycDocuments !== []): ?>
                        <table style="margin-top: 10px;">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>File</th>
                                    <th>Status</th>
                                    <th>Reviewed by</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($viewKycDocuments as $kycDoc): ?>
                                    <tr>
                                        <td><?= e($kycTypeLabels[$kycDoc['document_type']] ?? $kycDoc['document_type']) ?></td>
                                        <td>
                                            <a href="<?= e(url('admin/kyc-document.php?id=' . (int) $kycDoc['id'])) ?>" target="_blank" rel="noopener"><?= e($kycDoc['original_filename'] ?? 'Document') ?></a>
                                            <br><small class="muted"><?= e(number_format(((int) $kycDoc['file_size']) / 1024, 0)) ?> KB &middot; uploaded <?= e($kycDoc['uploaded_at']) ?></small>
                                        </td>
                                        <td>
                                            <?php $kycTone = $kycDoc['verification_status'] === 'verified' ? 'tone-green' : ($kycDoc['verification_status'] === 'rejected' ? 'tone-red' : 'tone-amber'); ?>
                                            <span class="mbw-pill <?= $kycTone ?>"><?= e(str_replace('_', ' ', $kycDoc['verification_status'])) ?></span>
                                            <?php if (!empty($kycDoc['remarks'])): ?>
                                                <br><small class="muted"><?= e($kycDoc['remarks']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= e($kycDoc['verified_by_name'] ?? '-') ?>
                                            <?php if (!empty($kycDoc['verified_at'])): ?><br><small class="muted"><?= e($kycDoc['verified_at']) ?></small><?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="post" style="display: grid; gap: 6px; max-width: 220px; margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="review_kyc_document">
                                                <input type="hidden" name="user_id" value="<?= e((int) $viewUser['id']) ?>">
                                                <input type="hidden" name="document_id" value="<?= e((int) $kycDoc['id']) ?>">
                                                <select name="decision" required>
                                                    <option value="verified">Verify</option>
                                                    <option value="rejected">Reject</option>
                                                    <option value="requires_update">Requires update</option>
                                                </select>
                                                <input type="text" name="remarks" placeholder="Remarks (required for reject)">
                                                <button type="submit" class="secondary">Record decision</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="muted" style="margin-bottom: 0;">No KYC documents uploaded yet.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="table-card">
                <h3>Activity logs</h3>
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
                        <?php if ($viewUserActivity === []): ?>
                            <tr>
                                <td colspan="4">No activity logs available for this user.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($viewUserActivity as $log): ?>
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

<?php if ($editUser): ?>
    <div class="modal-overlay is-open" data-modal="user-edit-modal" role="dialog" aria-modal="true" aria-labelledby="user-edit-title">
        <div class="modal-card">
            <div class="modal-head">
                <h3 id="user-edit-title">Edit user #<?= e((int) $editUser['id']) ?></h3>
                <a class="button secondary" href="<?= e($buildUsersUrl(['edit' => null])) ?>">Close</a>
            </div>

            <div class="form-card">
                <form method="post" id="user-edit-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="user_id" value="<?= e((int) $editUser['id']) ?>">

                    <div class="users-edit-grid">
                        <label>Name<input type="text" name="name" value="<?= e($editUser['name']) ?>" required></label>
                        <label>Email<input type="email" name="email" value="<?= e($editUser['email']) ?>" required></label>
                        <label>Password (optional)
                            <input type="password" name="password" minlength="8" placeholder="Leave blank to keep current password" data-confirm-source>
                        </label>
                        <label>Confirm password
                            <input type="password" name="password_confirm" minlength="8" placeholder="Repeat the new password" data-confirm-target>
                        </label>
                        <label>Role
                            <select name="role" required>
                                <option value="customer" <?= ($editUser['role'] ?? 'customer') === 'customer' ? 'selected' : '' ?>>Customer</option>
                                <option value="staff" <?= ($editUser['role'] ?? '') === 'staff' ? 'selected' : '' ?>>Staff</option>
                                <option value="admin" <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                        </label>
                        <label>Status
                            <select name="status" required>
                                <option value="active" <?= ($editUser['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($editUser['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </label>
                        <?php if ($hasAccessLevels): ?>
                            <label>Access level
                                <select name="access_level" required>
                                    <?php foreach (ACCESS_LEVELS as $levelKey => $levelLabel): ?>
                                        <option value="<?= e($levelKey) ?>" <?= ($editUser['access_level'] ?? '') === $levelKey ? 'selected' : '' ?>><?= e($levelLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>
                        <label>Phone<input type="text" name="phone" value="<?= e($editUser['phone'] ?? '') ?>"></label>
                        <label class="users-full">Company<input type="text" name="company" value="<?= e($editUser['company'] ?? '') ?>"></label>
                    </div>

                    <div class="actions">
                        <button type="submit" id="user-edit-submit">Save changes</button>
                        <a class="button secondary" href="<?= e($buildUsersUrl(['edit' => null])) ?>">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="modal-overlay" data-modal="user-create-modal" role="dialog" aria-modal="true" aria-labelledby="user-create-title">
    <div class="modal-card">
        <div class="modal-head">
            <h3 id="user-create-title">Create user account</h3>
            <button type="button" class="button secondary" data-modal-close="user-create-modal">Close</button>
        </div>

        <div class="form-card">
            <form method="post" id="user-create-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">

                <div class="users-edit-grid">
                    <label>Name<input type="text" name="name" required></label>
                    <label>Email<input type="email" name="email" required></label>
                    <label>Password<input type="password" name="password" minlength="8" required data-strength data-confirm-source></label>
                    <label>Confirm password<input type="password" name="password_confirm" minlength="8" required data-confirm-target></label>
                    <input type="hidden" name="role" value="staff">
                    <label>Role<input type="text" value="Staff" readonly></label>
                    <label>Status
                        <select name="status" required>
                            <option value="active" selected>Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </label>
                    <?php if ($hasAccessLevels): ?>
                        <label>Access level
                            <select name="access_level" required>
                                <?php foreach (ACCESS_LEVELS as $levelKey => $levelLabel): ?>
                                    <option value="<?= e($levelKey) ?>" <?= $levelKey === 'accountant' ? 'selected' : '' ?>><?= e($levelLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <label>Phone<input type="text" name="phone"></label>
                    <input type="hidden" name="company_id" value="<?= e($companyId) ?>">
                    <label class="users-full">Company portal<input type="text" value="<?= e($company['name'] ?? 'Current company') ?><?= !empty($company['code']) ? ' (' . e($company['code']) . ')' : '' ?>" readonly></label>
                </div>

                <div class="actions">
                    <button type="submit" id="user-create-submit">Create user</button>
                    <button type="button" class="button secondary" data-modal-close="user-create-modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

</div>
<!-- USERS WORKFLOW REDESIGN END -->

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
