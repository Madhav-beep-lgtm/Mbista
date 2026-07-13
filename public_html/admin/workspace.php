<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/admin_work_portal_repair.php';

require_admin();
require_company_context();
$pageTitle = 'Admin Work Portal';
$pageSubtitle = 'Operations hub for clients, teams, contracts, tasks, and invoicing';
$bodyClass = 'admin-layout admin-workspace';
$currentAdmin = current_user();
$adminId = (int) ($currentAdmin['id'] ?? 0);
$company = current_company();
$fiscalYear = current_fiscal_year();
$companyId = (int) ($company['id'] ?? 0);
$documentLogoPath = (string) setting('company_logo_path', '');
$documentSignaturePath = (string) setting('company_signature_path', '');
$documentStampPath = (string) setting('company_stamp_path', '');

$requiredTables = [
    'teams',
    'team_members',
    'client_profiles',
    'industries',
    'service_provider_entities',
    'client_service_provider_entities',
    'service_contracts',
    'client_tasks',
    'task_stages',
    'task_invoices',
];
$missingTables = [];
foreach ($requiredTables as $tableName) {
    if (!admin_work_portal_repair_table_exists($tableName)) {
        $missingTables[] = $tableName;
    }
}
$autoRepairErrors = [];
if (array_intersect($missingTables, admin_work_portal_repair_required_tables()) !== []) {
    $autoRepairErrors = admin_work_portal_repair_database();
    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!admin_work_portal_repair_table_exists($tableName)) {
            $missingTables[] = $tableName;
        }
    }
}

$allowedRoles = ['admin', 'staff', 'customer'];
$allowedStatus = ['active', 'inactive'];
$allowedTaskStatus = ['new', 'in_progress', 'on_hold', 'completed', 'cancelled'];
$allowedTaskPriority = ['low', 'normal', 'high', 'urgent'];
$allowedContractStatus = ['draft', 'active', 'completed', 'terminated'];
$allowedInvoiceType = ['stage', 'task'];
$allowedInvoiceStatus = ['draft', 'issued', 'paid', 'cancelled'];
$allowedStageStatus = ['pending', 'in_progress', 'completed'];
$allowedClientStatus = ['active', 'suspended', 'inactive'];

$hasTaskAssignment = column_exists('client_tasks', 'assigned_staff_user_id');
$hasStageAssignment = column_exists('task_stages', 'assigned_staff_user_id');

$allowedViews = ['home', 'clients', 'industries', 'service-providers', 'teams', 'contracts', 'tasks', 'invoices', 'staff'];
$view = (string) ($_GET['view'] ?? 'home');
if ($view === 'users') {
    redirect('admin/users.php');
}
if (!in_array($view, $allowedViews, true)) {
    $view = 'home';
}

if ($missingTables === [] && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_user') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'customer');
        $status = (string) ($_POST['status'] ?? 'active');
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $company = trim((string) ($_POST['company'] ?? ''));

        if ($name === '' || $email === '' || $password === '') {
            flash('error', 'Name, email, and password are required for user creation.');
            redirect('admin/workspace.php?view=users');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please provide a valid email address.');
            redirect('admin/workspace.php?view=users');
        }
        if (strlen($password) < 8) {
            flash('error', 'Password must be at least 8 characters.');
            redirect('admin/workspace.php?view=users');
        }
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'customer';
        }
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'active';
        }

        try {
            $userId = create_user([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => $role,
                'status' => $status,
                'company_id' => $companyId,
                'phone' => $phone !== '' ? $phone : null,
                'company' => $company !== '' ? $company : null,
            ]);
            log_activity('user', $userId, 'created', 'User created from admin work portal.', $adminId);
            flash('success', 'User created successfully.');
        } catch (Throwable $exception) {
            flash('error', 'User creation failed. The email may already exist.');
        }
        redirect('admin/workspace.php?view=users');
    }

    if ($action === 'assign_user_role') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? 'customer');
        $status = (string) ($_POST['status'] ?? 'active');

        if ($userId <= 0) {
            flash('error', 'Invalid user selected for role assignment.');
            redirect('admin/workspace.php?view=users');
        }
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'customer';
        }
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'active';
        }
        if ($userId === $adminId && $status !== 'active') {
            flash('error', 'You cannot deactivate your own account.');
            redirect('admin/workspace.php?view=users');
        }

        $stmt = db()->prepare('UPDATE users SET role = :role, status = :status WHERE id = :id AND company_id = :company_id');
        $stmt->execute([
            'role' => $role,
            'status' => $status,
            'id' => $userId,
            'company_id' => $companyId,
        ]);
        log_activity('user', $userId, 'role_assigned', 'Role/status updated from admin work portal.', $adminId);
        flash('success', 'User role assignment updated.');
        redirect('admin/workspace.php?view=users');
    }

    if ($action === 'create_team') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $leaderUserId = (int) ($_POST['leader_user_id'] ?? 0);
        $memberIds = array_map('intval', (array) ($_POST['member_ids'] ?? []));

        if ($name === '' || $leaderUserId <= 0) {
            flash('error', 'Team name and leader are required.');
            redirect('admin/workspace.php?view=teams');
        }

        $staffCheck = db()->prepare("SELECT id FROM users WHERE id = :id AND role = 'staff' AND company_id = :company_id LIMIT 1");
        $staffCheck->execute(['id' => $leaderUserId, 'company_id' => $companyId]);
        if (!$staffCheck->fetch()) {
            flash('error', 'Leader must be selected from existing staff users.');
            redirect('admin/workspace.php?view=teams');
        }

        try {
            $teamStmt = db()->prepare('INSERT INTO teams (company_id, name, description, leader_user_id) VALUES (:company_id, :name, :description, :leader_user_id)');
            $teamStmt->execute([
                'company_id' => $companyId,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'leader_user_id' => $leaderUserId,
            ]);
            $teamId = (int) db()->lastInsertId();

            $insertMember = db()->prepare('INSERT IGNORE INTO team_members (team_id, user_id, member_role) VALUES (:team_id, :user_id, :member_role)');
            $insertMember->execute([
                'team_id' => $teamId,
                'user_id' => $leaderUserId,
                'member_role' => 'leader',
            ]);

            foreach ($memberIds as $memberId) {
                if ($memberId <= 0 || $memberId === $leaderUserId) {
                    continue;
                }
                $insertMember->execute([
                    'team_id' => $teamId,
                    'user_id' => $memberId,
                    'member_role' => 'member',
                ]);
            }

            log_activity('team', $teamId, 'created', 'Team created with leader and members.', $adminId);
            flash('success', 'Team created successfully.');
        } catch (Throwable $exception) {
            flash('error', 'Team creation failed. Team name may already exist.');
        }
        redirect('admin/workspace.php?view=teams');
    }

    if ($action === 'create_client') {
        $name = trim((string) ($_POST['authorized_person_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $organizationName = trim((string) ($_POST['organization_name'] ?? ''));
        $registrationNo = trim((string) ($_POST['registration_no'] ?? ''));
        $industryId = (int) ($_POST['industry_id'] ?? 0);
        $address = trim((string) ($_POST['address'] ?? ''));
        $panNo = trim((string) ($_POST['pan_no'] ?? ''));
        $position = trim((string) ($_POST['authorized_person_position'] ?? ''));
        $authorizedSignatoryName = trim((string) ($_POST['authorized_signatory_name'] ?? ''));
        $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
        $website = trim((string) ($_POST['website'] ?? ''));
        $clientStatus = (string) ($_POST['client_status'] ?? 'active');
        $serviceProviderIds = (array) ($_POST['service_provider_entity_ids'] ?? []);
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($name === '' || $email === '' || $password === '' || $organizationName === '') {
            flash('error', 'Authorized person name, email, password, and client/company name are required.');
            redirect('admin/workspace.php?view=clients');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please provide a valid client email address.');
            redirect('admin/workspace.php?view=clients');
        }
        if (strlen($password) < 8) {
            flash('error', 'Client password must be at least 8 characters.');
            redirect('admin/workspace.php?view=clients');
        }
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            flash('error', 'Temporary password must include uppercase, lowercase, and a number.');
            redirect('admin/workspace.php?view=clients');
        }
        if ($contactNumber !== '' && !preg_match('/^[0-9+\-\s().]{6,30}$/', $contactNumber)) {
            flash('error', 'Contact number contains invalid characters.');
            redirect('admin/workspace.php?view=clients');
        }
        if ($website !== '' && filter_var($website, FILTER_VALIDATE_URL) === false) {
            flash('error', 'Website must be a valid URL.');
            redirect('admin/workspace.php?view=clients');
        }
        if (!in_array($clientStatus, $allowedClientStatus, true)) {
            $clientStatus = 'active';
        }
        if ($industryId > 0 && table_exists('industries')) {
            $industryCheck = db()->prepare('SELECT id FROM industries WHERE id = :id AND is_active = 1 LIMIT 1');
            $industryCheck->execute(['id' => $industryId]);
            if (!$industryCheck->fetch()) {
                flash('error', 'Selected industry is not active.');
                redirect('admin/workspace.php?view=clients');
            }
        }

        try {
            $logoPath = upload_image_file('company_logo', 'assets/uploads/client-logos');
            db()->beginTransaction();
            $clientCode = generate_client_code($organizationName);
            $userId = create_user([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => 'customer',
                'status' => $clientStatus === 'active' ? 'active' : 'inactive',
                'company_id' => $companyId,
                'phone' => $contactNumber !== '' ? $contactNumber : null,
                'company' => $organizationName,
                'must_change_password' => 1,
            ]);

            $profileStmt = db()->prepare('
                INSERT INTO client_profiles (
                    user_id, company_id, organization_name, client_code, registration_no, industry_id,
                    address, pan_no, authorized_person_position, authorized_signatory_name,
                    contact_number, website, company_logo_path, client_status, notes, is_active
                ) VALUES (
                    :user_id, :company_id, :organization_name, :client_code, :registration_no, :industry_id,
                    :address, :pan_no, :authorized_person_position, :authorized_signatory_name,
                    :contact_number, :website, :company_logo_path, :client_status, :notes, :is_active
                )
            ');
            $profileStmt->execute([
                'user_id' => $userId,
                'company_id' => $companyId,
                'organization_name' => $organizationName,
                'client_code' => $clientCode,
                'registration_no' => $registrationNo !== '' ? $registrationNo : null,
                'industry_id' => $industryId > 0 ? $industryId : null,
                'address' => $address !== '' ? $address : null,
                'pan_no' => $panNo !== '' ? $panNo : null,
                'authorized_person_position' => $position !== '' ? $position : null,
                'authorized_signatory_name' => $authorizedSignatoryName !== '' ? $authorizedSignatoryName : null,
                'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                'website' => $website !== '' ? $website : null,
                'company_logo_path' => $logoPath,
                'client_status' => $clientStatus,
                'notes' => $notes !== '' ? $notes : null,
                'is_active' => $clientStatus === 'active' ? 1 : 0,
            ]);
            $clientProfileId = (int) db()->lastInsertId();
            sync_client_service_providers($clientProfileId, $serviceProviderIds);

            db()->commit();
            log_activity('client_profile', $clientProfileId, 'created', 'Client created with generated code ' . $clientCode . '.', $adminId);
            flash('success', 'Client created successfully. Code: ' . $clientCode . ' / Login: ' . $email);
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Client creation failed. Email may already exist or the logo was invalid.');
        }

        redirect('admin/workspace.php?view=clients');
    }

    if ($action === 'update_client') {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $name = trim((string) ($_POST['authorized_person_name'] ?? ''));
        $organizationName = trim((string) ($_POST['organization_name'] ?? ''));
        $registrationNo = trim((string) ($_POST['registration_no'] ?? ''));
        $industryId = (int) ($_POST['industry_id'] ?? 0);
        $address = trim((string) ($_POST['address'] ?? ''));
        $panNo = trim((string) ($_POST['pan_no'] ?? ''));
        $position = trim((string) ($_POST['authorized_person_position'] ?? ''));
        $authorizedSignatoryName = trim((string) ($_POST['authorized_signatory_name'] ?? ''));
        $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
        $website = trim((string) ($_POST['website'] ?? ''));
        $clientStatus = (string) ($_POST['client_status'] ?? 'active');
        $serviceProviderIds = (array) ($_POST['service_provider_entity_ids'] ?? []);
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($clientId <= 0 || $name === '' || $organizationName === '') {
            flash('error', 'Client/company name and authorized person name are required.');
            redirect('admin/workspace.php?view=clients');
        }
        if ($contactNumber !== '' && !preg_match('/^[0-9+\-\s().]{6,30}$/', $contactNumber)) {
            flash('error', 'Contact number contains invalid characters.');
            redirect('admin/workspace.php?view=clients&mode=edit&client_id=' . $clientId);
        }
        if ($website !== '' && filter_var($website, FILTER_VALIDATE_URL) === false) {
            flash('error', 'Website must be a valid URL.');
            redirect('admin/workspace.php?view=clients&mode=edit&client_id=' . $clientId);
        }
        if (!in_array($clientStatus, $allowedClientStatus, true)) {
            $clientStatus = 'active';
        }

        $clientStmt = db()->prepare('SELECT cp.*, u.id AS login_user_id FROM client_profiles cp INNER JOIN users u ON u.id = cp.user_id WHERE cp.id = :id AND cp.company_id = :company_id LIMIT 1');
        $clientStmt->execute(['id' => $clientId, 'company_id' => $companyId]);
        $clientRecord = $clientStmt->fetch();
        if (!$clientRecord) {
            flash('error', 'Client not found.');
            redirect('admin/workspace.php?view=clients');
        }

        try {
            $logoPath = !empty($_POST['remove_logo']) ? null : upload_image_file('company_logo', 'assets/uploads/client-logos', $clientRecord['company_logo_path'] ?? null);
            $stmt = db()->prepare('
                UPDATE client_profiles
                SET organization_name = :organization_name,
                    registration_no = :registration_no,
                    industry_id = :industry_id,
                    address = :address,
                    pan_no = :pan_no,
                    authorized_person_position = :authorized_person_position,
                    authorized_signatory_name = :authorized_signatory_name,
                    contact_number = :contact_number,
                    website = :website,
                    company_logo_path = :company_logo_path,
                    client_status = :client_status,
                    notes = :notes,
                    is_active = :is_active
                WHERE id = :id AND company_id = :company_id
            ');
            $stmt->execute([
                'organization_name' => $organizationName,
                'registration_no' => $registrationNo !== '' ? $registrationNo : null,
                'industry_id' => $industryId > 0 ? $industryId : null,
                'address' => $address !== '' ? $address : null,
                'pan_no' => $panNo !== '' ? $panNo : null,
                'authorized_person_position' => $position !== '' ? $position : null,
                'authorized_signatory_name' => $authorizedSignatoryName !== '' ? $authorizedSignatoryName : null,
                'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                'website' => $website !== '' ? $website : null,
                'company_logo_path' => $logoPath,
                'client_status' => $clientStatus,
                'notes' => $notes !== '' ? $notes : null,
                'is_active' => $clientStatus === 'active' ? 1 : 0,
                'id' => $clientId,
                'company_id' => $companyId,
            ]);
            db()->prepare('UPDATE users SET name = :name, phone = :phone, company = :company, status = :status WHERE id = :id')
                ->execute([
                    'name' => $name,
                    'phone' => $contactNumber !== '' ? $contactNumber : null,
                    'company' => $organizationName,
                    'status' => $clientStatus === 'active' ? 'active' : 'inactive',
                    'id' => (int) $clientRecord['login_user_id'],
                ]);
            sync_client_service_providers($clientId, $serviceProviderIds);
            log_activity('client_profile', $clientId, 'updated', 'Client profile updated.', $adminId);
            flash('success', 'Client updated successfully.');
        } catch (Throwable $exception) {
            flash('error', 'Client update failed. Check logo format and entered values.');
        }

        redirect('admin/workspace.php?view=clients&mode=view&client_id=' . $clientId);
    }

    if (in_array($action, ['suspend_client', 'activate_client', 'delete_client'], true)) {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $clientStmt = db()->prepare('SELECT cp.*, u.id AS login_user_id FROM client_profiles cp INNER JOIN users u ON u.id = cp.user_id WHERE cp.id = :id AND cp.company_id = :company_id LIMIT 1');
        $clientStmt->execute(['id' => $clientId, 'company_id' => $companyId]);
        $clientRecord = $clientStmt->fetch();
        if (!$clientRecord) {
            flash('error', 'Client not found.');
            redirect('admin/workspace.php?view=clients');
        }

        if ($action === 'delete_client') {
            $relatedChecks = [
                'client_tasks' => 'SELECT COUNT(*) FROM client_tasks WHERE client_id = :client_id',
                'service_contracts' => 'SELECT COUNT(*) FROM service_contracts WHERE client_id = :client_id',
                'task_invoices' => 'SELECT COUNT(*) FROM task_invoices ti INNER JOIN client_tasks t ON t.id = ti.task_id WHERE t.client_id = :client_id',
            ];
            $relatedCount = 0;
            foreach ($relatedChecks as $table => $sql) {
                if (!table_exists($table)) {
                    continue;
                }
                $check = db()->prepare($sql);
                $check->execute(['client_id' => $clientId]);
                $relatedCount += (int) $check->fetchColumn();
            }
            if ($relatedCount > 0) {
                db()->prepare("UPDATE client_profiles SET client_status = 'suspended', is_active = 0 WHERE id = :id")->execute(['id' => $clientId]);
                db()->prepare("UPDATE users SET status = 'inactive' WHERE id = :id")->execute(['id' => (int) $clientRecord['login_user_id']]);
                log_activity('client_profile', $clientId, 'soft_deleted', 'Client suspended because related records exist.', $adminId);
                flash('success', 'Client has related records, so it was suspended instead of permanently deleted.');
            } else {
                db()->prepare('DELETE FROM client_profiles WHERE id = :id')->execute(['id' => $clientId]);
                db()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => (int) $clientRecord['login_user_id']]);
                log_activity('client_profile', $clientId, 'deleted', 'Client permanently deleted.', $adminId);
                flash('success', 'Client deleted successfully.');
            }
            redirect('admin/workspace.php?view=clients');
        }

        $newStatus = $action === 'activate_client' ? 'active' : 'suspended';
        db()->prepare('UPDATE client_profiles SET client_status = :client_status, is_active = :is_active WHERE id = :id')
            ->execute([
                'client_status' => $newStatus,
                'is_active' => $newStatus === 'active' ? 1 : 0,
                'id' => $clientId,
            ]);
        db()->prepare('UPDATE users SET status = :status WHERE id = :id')
            ->execute([
                'status' => $newStatus === 'active' ? 'active' : 'inactive',
                'id' => (int) $clientRecord['login_user_id'],
            ]);
        log_activity('client_profile', $clientId, $newStatus === 'active' ? 'activated' : 'suspended', 'Client status updated.', $adminId);
        flash('success', $newStatus === 'active' ? 'Client activated.' : 'Client suspended.');
        redirect('admin/workspace.php?view=clients');
    }

    if ($action === 'save_industry') {
        $industryId = (int) ($_POST['industry_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '') {
            flash('error', 'Industry name is required.');
            redirect('admin/workspace.php?view=industries');
        }
        if ($industryId > 0) {
            db()->prepare('UPDATE industries SET name = :name, is_active = :is_active WHERE id = :id')
                ->execute(['name' => $name, 'is_active' => $isActive, 'id' => $industryId]);
            log_activity('industry', $industryId, 'updated', 'Industry updated.', $adminId);
        } else {
            db()->prepare('INSERT INTO industries (name, is_active) VALUES (:name, :is_active)')
                ->execute(['name' => $name, 'is_active' => $isActive]);
            $industryId = (int) db()->lastInsertId();
            log_activity('industry', $industryId, 'created', 'Industry created.', $adminId);
        }
        flash('success', 'Industry saved.');
        redirect('admin/workspace.php?view=industries');
    }

    if ($action === 'delete_industry') {
        $industryId = (int) ($_POST['industry_id'] ?? 0);
        $check = db()->prepare('SELECT COUNT(*) FROM client_profiles WHERE industry_id = :id');
        $check->execute(['id' => $industryId]);
        if ((int) $check->fetchColumn() > 0) {
            db()->prepare('UPDATE industries SET is_active = 0 WHERE id = :id')->execute(['id' => $industryId]);
            flash('success', 'Industry is used by clients, so it was deactivated.');
        } else {
            db()->prepare('DELETE FROM industries WHERE id = :id')->execute(['id' => $industryId]);
            flash('success', 'Industry deleted.');
        }
        log_activity('industry', $industryId, 'deleted_or_deactivated', 'Industry delete requested.', $adminId);
        redirect('admin/workspace.php?view=industries');
    }

    if ($action === 'save_service_provider_entity') {
        $entityId = (int) ($_POST['entity_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
        $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $website = trim((string) ($_POST['website'] ?? ''));
        $signatory = trim((string) ($_POST['authorized_signatory_name'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '') {
            flash('error', 'Service provider entity name is required.');
            redirect('admin/workspace.php?view=service-providers');
        }
        if ($contactEmail !== '' && filter_var($contactEmail, FILTER_VALIDATE_EMAIL) === false) {
            flash('error', 'Entity contact email is invalid.');
            redirect('admin/workspace.php?view=service-providers');
        }
        if ($website !== '' && filter_var($website, FILTER_VALIDATE_URL) === false) {
            flash('error', 'Entity website must be a valid URL.');
            redirect('admin/workspace.php?view=service-providers');
        }
        $existingLogo = null;
        if ($entityId > 0) {
            $existingStmt = db()->prepare('SELECT logo_path FROM service_provider_entities WHERE id = :id LIMIT 1');
            $existingStmt->execute(['id' => $entityId]);
            $existingLogo = $existingStmt->fetchColumn() ?: null;
        }
        try {
            $logoPath = !empty($_POST['remove_logo']) ? null : upload_image_file('entity_logo', 'assets/uploads/service-provider-logos', $existingLogo ?: null);
            if ($entityId > 0) {
                db()->prepare('UPDATE service_provider_entities SET name = :name, code = :code, logo_path = :logo_path, contact_email = :contact_email, contact_number = :contact_number, address = :address, website = :website, authorized_signatory_name = :authorized_signatory_name, is_active = :is_active WHERE id = :id')
                    ->execute([
                        'name' => $name,
                        'code' => $code !== '' ? $code : null,
                        'logo_path' => $logoPath,
                        'contact_email' => $contactEmail !== '' ? $contactEmail : null,
                        'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                        'address' => $address !== '' ? $address : null,
                        'website' => $website !== '' ? $website : null,
                        'authorized_signatory_name' => $signatory !== '' ? $signatory : null,
                        'is_active' => $isActive,
                        'id' => $entityId,
                    ]);
                log_activity('service_provider_entity', $entityId, 'updated', 'Service provider entity updated.', $adminId);
            } else {
                db()->prepare('INSERT INTO service_provider_entities (name, code, logo_path, contact_email, contact_number, address, website, authorized_signatory_name, is_active) VALUES (:name, :code, :logo_path, :contact_email, :contact_number, :address, :website, :authorized_signatory_name, :is_active)')
                    ->execute([
                        'name' => $name,
                        'code' => $code !== '' ? $code : null,
                        'logo_path' => $logoPath,
                        'contact_email' => $contactEmail !== '' ? $contactEmail : null,
                        'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                        'address' => $address !== '' ? $address : null,
                        'website' => $website !== '' ? $website : null,
                        'authorized_signatory_name' => $signatory !== '' ? $signatory : null,
                        'is_active' => $isActive,
                    ]);
                $entityId = (int) db()->lastInsertId();
                log_activity('service_provider_entity', $entityId, 'created', 'Service provider entity created.', $adminId);
            }
            flash('success', 'Service provider entity saved.');
        } catch (Throwable $exception) {
            flash('error', 'Could not save service provider entity. Check logo and unique code.');
        }
        redirect('admin/workspace.php?view=service-providers');
    }

    if ($action === 'create_service_contract') {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $contractNo = strtoupper(trim((string) ($_POST['contract_no'] ?? '')));
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $totalValueRaw = trim((string) ($_POST['total_value'] ?? '0'));
        $billingCycle = trim((string) ($_POST['billing_cycle'] ?? 'one_time'));
        $status = (string) ($_POST['status'] ?? 'draft');
        $terms = trim((string) ($_POST['terms'] ?? ''));

        if ($clientId <= 0 || $title === '' || $contractNo === '' || !is_numeric($totalValueRaw)) {
            flash('error', 'Client, title, contract number, and total value are required.');
            redirect('admin/workspace.php?view=contracts');
        }

        $totalValue = round((float) $totalValueRaw, 2);
        if ($totalValue < 0) {
            flash('error', 'Contract total value cannot be negative.');
            redirect('admin/workspace.php?view=contracts');
        }
        if (!in_array($status, $allowedContractStatus, true)) {
            $status = 'draft';
        }

        $clientCheck = db()->prepare('SELECT id FROM client_profiles WHERE id = :id AND company_id = :company_id LIMIT 1');
        $clientCheck->execute(['id' => $clientId, 'company_id' => $companyId]);
        if (!$clientCheck->fetch()) {
            flash('error', 'Selected client profile does not exist.');
            redirect('admin/workspace.php?view=contracts');
        }

        try {
            $stmt = db()->prepare('INSERT INTO service_contracts (company_id, client_id, title, contract_no, start_date, end_date, total_value, billing_cycle, status, terms, created_by) VALUES (:company_id, :client_id, :title, :contract_no, :start_date, :end_date, :total_value, :billing_cycle, :status, :terms, :created_by)');
            $stmt->execute([
                'company_id' => $companyId,
                'client_id' => $clientId,
                'title' => $title,
                'contract_no' => $contractNo,
                'start_date' => $startDate !== '' ? $startDate : null,
                'end_date' => $endDate !== '' ? $endDate : null,
                'total_value' => $totalValue,
                'billing_cycle' => $billingCycle !== '' ? $billingCycle : null,
                'status' => $status,
                'terms' => $terms !== '' ? $terms : null,
                'created_by' => $adminId > 0 ? $adminId : null,
            ]);

            $contractId = (int) db()->lastInsertId();
            log_activity('service_contract', $contractId, 'created', 'Service contract created from admin work portal.', $adminId);
            flash('success', 'Service contract created successfully.');
        } catch (Throwable $exception) {
            flash('error', 'Service contract creation failed. Contract number may already exist.');
        }

        redirect('admin/workspace.php?view=contracts');
    }

    if ($action === 'update_service_contract_status') {
        $contractId = (int) ($_POST['contract_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'draft');

        if ($contractId <= 0 || !in_array($status, $allowedContractStatus, true)) {
            flash('error', 'Invalid contract update request.');
            redirect('admin/workspace.php?view=contracts');
        }

        $updateStmt = db()->prepare('UPDATE service_contracts SET status = :status WHERE id = :id AND company_id = :company_id');
        $updateStmt->execute([
            'status' => $status,
            'id' => $contractId,
            'company_id' => $companyId,
        ]);

        if ($updateStmt->rowCount() > 0) {
            log_activity('service_contract', $contractId, 'status_updated', 'Service contract status updated from workspace.', $adminId);
            flash('success', 'Contract status updated.');
        } else {
            flash('error', 'Contract was not found in current company scope.');
        }

        redirect('admin/workspace.php?view=contracts');
    }

    if ($action === 'create_task') {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $serviceProviderEntityId = (int) ($_POST['service_provider_entity_id'] ?? 0);
        $contractId = (int) ($_POST['contract_id'] ?? 0);
        $teamId = (int) ($_POST['team_id'] ?? 0);
        $assignedStaffUserId = (int) ($_POST['assigned_staff_user_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $quotedFeeRaw = trim((string) ($_POST['quoted_fee'] ?? '0'));
        $priority = (string) ($_POST['priority'] ?? 'normal');
        $status = (string) ($_POST['status'] ?? 'new');
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));

        if ($clientId <= 0 || $title === '' || !is_numeric($quotedFeeRaw)) {
            flash('error', 'Client, task title, and quoted fee are required.');
            redirect('admin/workspace.php?view=tasks');
        }

        $clientCheck = db()->prepare('SELECT id FROM client_profiles WHERE id = :id AND company_id = :company_id LIMIT 1');
        $clientCheck->execute(['id' => $clientId, 'company_id' => $companyId]);
        if (!$clientCheck->fetch()) {
            flash('error', 'Task can be created only for existing client profiles.');
            redirect('admin/workspace.php?view=tasks');
        }

        if ($serviceProviderEntityId > 0 && table_exists('client_service_provider_entities')) {
            $entityCheck = db()->prepare('SELECT service_provider_entity_id FROM client_service_provider_entities WHERE client_id = :client_id AND service_provider_entity_id = :entity_id LIMIT 1');
            $entityCheck->execute([
                'client_id' => $clientId,
                'entity_id' => $serviceProviderEntityId,
            ]);
            if (!$entityCheck->fetch()) {
                flash('error', 'Select a service provider entity linked to this client.');
                redirect('admin/workspace.php?view=tasks');
            }
        }

        if ($contractId > 0) {
            $contractCheck = db()->prepare('SELECT id FROM service_contracts WHERE id = :id AND client_id = :client_id AND company_id = :company_id LIMIT 1');
            $contractCheck->execute([
                'id' => $contractId,
                'client_id' => $clientId,
                'company_id' => $companyId,
            ]);
            if (!$contractCheck->fetch()) {
                flash('error', 'Selected contract is invalid for this client.');
                redirect('admin/workspace.php?view=tasks');
            }
        }

        if ($teamId > 0) {
            $teamCheck = db()->prepare('SELECT id FROM teams WHERE id = :id AND company_id = :company_id LIMIT 1');
            $teamCheck->execute(['id' => $teamId, 'company_id' => $companyId]);
            if (!$teamCheck->fetch()) {
                flash('error', 'Selected team was not found.');
                redirect('admin/workspace.php?view=tasks');
            }
        }

        if ($assignedStaffUserId > 0) {
            $staffCheck = db()->prepare("SELECT id FROM users WHERE id = :id AND company_id = :company_id AND role IN ('staff', 'admin') AND status = 'active' LIMIT 1");
            $staffCheck->execute(['id' => $assignedStaffUserId, 'company_id' => $companyId]);
            if (!$staffCheck->fetch()) {
                flash('error', 'Selected staff member was not found in this company.');
                redirect('admin/workspace.php?view=tasks');
            }
        }

        $quotedFee = round((float) $quotedFeeRaw, 2);
        if ($quotedFee < 0) {
            flash('error', 'Quoted fee cannot be negative.');
            redirect('admin/workspace.php?view=tasks');
        }
        if (!in_array($priority, $allowedTaskPriority, true)) {
            $priority = 'normal';
        }
        if (!in_array($status, $allowedTaskStatus, true)) {
            $status = 'new';
        }

        $serviceProviderColumn = column_exists('client_tasks', 'service_provider_entity_id') ? ', service_provider_entity_id' : '';
        $serviceProviderValue = column_exists('client_tasks', 'service_provider_entity_id') ? ', :service_provider_entity_id' : '';
        $assignmentColumn = $hasTaskAssignment ? ', assigned_staff_user_id' : '';
        $assignmentValue = $hasTaskAssignment ? ', :assigned_staff_user_id' : '';
        $stmt = db()->prepare("INSERT INTO client_tasks (company_id, client_id{$serviceProviderColumn}, contract_id, team_id{$assignmentColumn}, title, description, quoted_fee, status, priority, start_date, due_date, created_by) VALUES (:company_id, :client_id{$serviceProviderValue}, :contract_id, :team_id{$assignmentValue}, :title, :description, :quoted_fee, :status, :priority, :start_date, :due_date, :created_by)");
        $taskInsertParams = [
            'company_id' => $companyId,
            'client_id' => $clientId,
            'contract_id' => $contractId > 0 ? $contractId : null,
            'team_id' => $teamId > 0 ? $teamId : null,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'quoted_fee' => $quotedFee,
            'status' => $status,
            'priority' => $priority,
            'start_date' => $startDate !== '' ? $startDate : null,
            'due_date' => $dueDate !== '' ? $dueDate : null,
            'created_by' => $adminId > 0 ? $adminId : null,
        ];
        if (column_exists('client_tasks', 'service_provider_entity_id')) {
            $taskInsertParams['service_provider_entity_id'] = $serviceProviderEntityId > 0 ? $serviceProviderEntityId : null;
        }
        if ($hasTaskAssignment) {
            $taskInsertParams['assigned_staff_user_id'] = $assignedStaffUserId > 0 ? $assignedStaffUserId : null;
        }
        $stmt->execute($taskInsertParams);

        $taskId = (int) db()->lastInsertId();
        log_activity('client_task', $taskId, 'created', 'Client task created from admin work portal.', $adminId);
        flash('success', 'Client task created successfully.');
        redirect('admin/workspace.php?view=tasks');
    }

    if ($action === 'add_task_stage') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $stageName = trim((string) ($_POST['stage_name'] ?? ''));
        $sequenceNo = (int) ($_POST['sequence_no'] ?? 1);
        $stageFeeRaw = trim((string) ($_POST['stage_fee'] ?? '0'));
        $status = (string) ($_POST['status'] ?? 'pending');
        $assignedStaffUserId = (int) ($_POST['assigned_staff_user_id'] ?? 0);

        if ($assignedStaffUserId > 0) {
            $staffCheck = db()->prepare("SELECT id FROM users WHERE id = :id AND company_id = :company_id AND role IN ('staff', 'admin') AND status = 'active' LIMIT 1");
            $staffCheck->execute(['id' => $assignedStaffUserId, 'company_id' => $companyId]);
            if (!$staffCheck->fetch()) {
                flash('error', 'Selected staff member was not found in this company.');
                redirect('admin/workspace.php?view=tasks');
            }
        }

        if ($taskId <= 0 || $stageName === '' || !is_numeric($stageFeeRaw)) {
            flash('error', 'Task, stage name, and stage fee are required.');
            redirect('admin/workspace.php?view=tasks');
        }

        $taskStmt = db()->prepare('SELECT id FROM client_tasks WHERE id = :id AND company_id = :company_id LIMIT 1');
        $taskStmt->execute(['id' => $taskId, 'company_id' => $companyId]);
        if (!$taskStmt->fetch()) {
            flash('error', 'Selected task does not exist for stage creation.');
            redirect('admin/workspace.php?view=tasks');
        }

        if (!in_array($status, $allowedStageStatus, true)) {
            $status = 'pending';
        }

        $stageFee = round((float) $stageFeeRaw, 2);
        if ($stageFee < 0) {
            flash('error', 'Stage fee cannot be negative.');
            redirect('admin/workspace.php?view=tasks');
        }

        $stageAssignmentColumn = $hasStageAssignment ? ', assigned_staff_user_id' : '';
        $stageAssignmentValue = $hasStageAssignment ? ', :assigned_staff_user_id' : '';
        $stmt = db()->prepare("INSERT INTO task_stages (company_id, task_id, stage_name, sequence_no, stage_fee{$stageAssignmentColumn}, status, completed_at) VALUES (:company_id, :task_id, :stage_name, :sequence_no, :stage_fee{$stageAssignmentValue}, :status, :completed_at)");
        $stageInsertParams = [
            'company_id' => $companyId,
            'task_id' => $taskId,
            'stage_name' => $stageName,
            'sequence_no' => max(1, $sequenceNo),
            'stage_fee' => $stageFee,
            'status' => $status,
            'completed_at' => $status === 'completed' ? date('Y-m-d H:i:s') : null,
        ];
        if ($hasStageAssignment) {
            $stageInsertParams['assigned_staff_user_id'] = $assignedStaffUserId > 0 ? $assignedStaffUserId : null;
        }
        $stmt->execute($stageInsertParams);

        $stageId = (int) db()->lastInsertId();
        log_activity('task_stage', $stageId, 'created', 'Task stage created from admin work portal.', $adminId);
        flash('success', 'Task stage added successfully.');
        redirect('admin/workspace.php?view=tasks');
    }

    if ($action === 'assign_task_staff' && $hasTaskAssignment) {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $assignedStaffUserId = (int) ($_POST['assigned_staff_user_id'] ?? 0);

        $taskCheck = db()->prepare('SELECT id FROM client_tasks WHERE id = :id AND company_id = :company_id LIMIT 1');
        $taskCheck->execute(['id' => $taskId, 'company_id' => $companyId]);
        if (!$taskCheck->fetch()) {
            flash('error', 'Task not found for assignment.');
            redirect('admin/workspace.php?view=tasks');
        }

        if ($assignedStaffUserId > 0) {
            $staffCheck = db()->prepare("SELECT id FROM users WHERE id = :id AND company_id = :company_id AND role IN ('staff', 'admin') AND status = 'active' LIMIT 1");
            $staffCheck->execute(['id' => $assignedStaffUserId, 'company_id' => $companyId]);
            if (!$staffCheck->fetch()) {
                flash('error', 'Selected staff member was not found in this company.');
                redirect('admin/workspace.php?view=tasks');
            }
        }

        db()->prepare('UPDATE client_tasks SET assigned_staff_user_id = :staff_id WHERE id = :id AND company_id = :company_id')
            ->execute([
                'staff_id' => $assignedStaffUserId > 0 ? $assignedStaffUserId : null,
                'id' => $taskId,
                'company_id' => $companyId,
            ]);

        log_activity('client_task', $taskId, 'staff_assigned', $assignedStaffUserId > 0 ? 'Task assigned to staff #' . $assignedStaffUserId . '.' : 'Task staff assignment cleared.', $adminId);
        flash('success', $assignedStaffUserId > 0 ? 'Task assigned to staff member.' : 'Task staff assignment cleared.');
        redirect('admin/workspace.php?view=tasks');
    }

    if ($action === 'assign_stage_staff' && $hasStageAssignment) {
        $stageId = (int) ($_POST['stage_id'] ?? 0);
        $assignedStaffUserId = (int) ($_POST['assigned_staff_user_id'] ?? 0);

        $stageCheck = db()->prepare('SELECT id FROM task_stages WHERE id = :id AND company_id = :company_id LIMIT 1');
        $stageCheck->execute(['id' => $stageId, 'company_id' => $companyId]);
        if (!$stageCheck->fetch()) {
            flash('error', 'Stage not found for assignment.');
            redirect('admin/workspace.php?view=tasks');
        }

        if ($assignedStaffUserId > 0) {
            $staffCheck = db()->prepare("SELECT id FROM users WHERE id = :id AND company_id = :company_id AND role IN ('staff', 'admin') AND status = 'active' LIMIT 1");
            $staffCheck->execute(['id' => $assignedStaffUserId, 'company_id' => $companyId]);
            if (!$staffCheck->fetch()) {
                flash('error', 'Selected staff member was not found in this company.');
                redirect('admin/workspace.php?view=tasks');
            }
        }

        db()->prepare('UPDATE task_stages SET assigned_staff_user_id = :staff_id WHERE id = :id AND company_id = :company_id')
            ->execute([
                'staff_id' => $assignedStaffUserId > 0 ? $assignedStaffUserId : null,
                'id' => $stageId,
                'company_id' => $companyId,
            ]);

        log_activity('task_stage', $stageId, 'staff_assigned', $assignedStaffUserId > 0 ? 'Stage assigned to staff #' . $assignedStaffUserId . '.' : 'Stage staff assignment cleared.', $adminId);
        flash('success', $assignedStaffUserId > 0 ? 'Stage assigned to staff member.' : 'Stage staff assignment cleared.');
        redirect('admin/workspace.php?view=tasks');
    }

    if ($action === 'complete_stage') {
        $stageId = (int) ($_POST['stage_id'] ?? 0);
        if ($stageId <= 0) {
            flash('error', 'Invalid stage selected.');
            redirect('admin/workspace.php?view=tasks');
        }

        $stageCheckStmt = db()->prepare('SELECT id FROM task_stages WHERE id = :id AND company_id = :company_id LIMIT 1');
        $stageCheckStmt->execute([
            'id' => $stageId,
            'company_id' => $companyId,
        ]);
        if (!$stageCheckStmt->fetch()) {
            flash('error', 'Selected stage is not available in the current company context.');
            redirect('admin/workspace.php?view=tasks');
        }

        $stmt = db()->prepare("UPDATE task_stages SET status = 'completed', completed_at = NOW() WHERE id = :id AND company_id = :company_id");
        $stmt->execute([
            'id' => $stageId,
            'company_id' => $companyId,
        ]);
        log_activity('task_stage', $stageId, 'completed', 'Task stage marked completed.', $adminId);
        flash('success', 'Task stage marked completed.');
        redirect('admin/workspace.php?view=tasks');
    }

    if ($action === 'complete_task') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        if ($taskId <= 0) {
            flash('error', 'Invalid task selected.');
            redirect('admin/workspace.php?view=tasks');
        }

        $stmt = db()->prepare("UPDATE client_tasks SET status = 'completed', completed_at = NOW() WHERE id = :id AND company_id = :company_id");
        $stmt->execute([
            'id' => $taskId,
            'company_id' => $companyId,
        ]);
        if ($stmt->rowCount() === 0) {
            flash('error', 'Selected task is not available in the current company context.');
            redirect('admin/workspace.php?view=tasks');
        }
        log_activity('client_task', $taskId, 'completed', 'Task marked completed.', $adminId);
        flash('success', 'Task marked as completed.');
        redirect('admin/workspace.php?view=tasks');
    }

    if ($action === 'issue_invoice') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $stageId = (int) ($_POST['stage_id'] ?? 0);
        $invoiceType = (string) ($_POST['invoice_type'] ?? 'stage');
        $amountRaw = trim((string) ($_POST['amount'] ?? '0'));
        $status = (string) ($_POST['status'] ?? 'issued');
        $issuedOn = trim((string) ($_POST['issued_on'] ?? date('Y-m-d')));
        $dueOn = trim((string) ($_POST['due_on'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($taskId <= 0 || !is_numeric($amountRaw) || !in_array($invoiceType, $allowedInvoiceType, true)) {
            flash('error', 'Task, invoice type, and valid amount are required.');
            redirect('admin/workspace.php?view=invoices');
        }
        if (!in_array($status, $allowedInvoiceStatus, true)) {
            $status = 'issued';
        }

        $taskStmt = db()->prepare('SELECT id, quoted_fee, status FROM client_tasks WHERE id = :id AND company_id = :company_id LIMIT 1');
        $taskStmt->execute(['id' => $taskId, 'company_id' => $companyId]);
        $task = $taskStmt->fetch();
        if (!$task) {
            flash('error', 'Selected task not found for invoicing.');
            redirect('admin/workspace.php?view=invoices');
        }

        $amount = round((float) $amountRaw, 2);
        if ($amount <= 0) {
            flash('error', 'Invoice amount must be greater than zero.');
            redirect('admin/workspace.php?view=invoices');
        }

        if ($invoiceType === 'task' && ($task['status'] ?? 'new') !== 'completed') {
            flash('error', 'Task invoice can be issued only after task completion.');
            redirect('admin/workspace.php?view=invoices');
        }

        $taskQuoted = (float) ($task['quoted_fee'] ?? 0);

        if ($invoiceType === 'stage') {
            if ($stageId <= 0) {
                flash('error', 'Stage invoice requires a valid completed stage.');
                redirect('admin/workspace.php?view=invoices');
            }

            $stageStmt = db()->prepare("SELECT id, task_id, stage_fee, status FROM task_stages WHERE id = :id AND task_id = :task_id AND company_id = :company_id LIMIT 1");
            $stageStmt->execute([
                'id' => $stageId,
                'task_id' => $taskId,
                'company_id' => $companyId,
            ]);
            $stage = $stageStmt->fetch();

            if (!$stage || ($stage['status'] ?? 'pending') !== 'completed') {
                flash('error', 'Stage invoice can be issued only for a completed stage.');
                redirect('admin/workspace.php?view=invoices');
            }

            $stageInvoicedStmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM task_invoices WHERE stage_id = :stage_id AND company_id = :company_id AND status <> :cancelled_status');
            $stageInvoicedStmt->execute([
                'stage_id' => $stageId,
                'company_id' => $companyId,
                'cancelled_status' => 'cancelled',
            ]);
            $stageInvoiced = (float) $stageInvoicedStmt->fetchColumn();
            $stageRemaining = round(((float) $stage['stage_fee']) - $stageInvoiced, 2);
            if ($amount > $stageRemaining) {
                flash('error', 'Invoice amount exceeds remaining amount available for this stage.');
                redirect('admin/workspace.php?view=invoices');
            }
        } else {
            $stageId = 0;
        }

        $taskInvoicedStmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM task_invoices WHERE task_id = :task_id AND company_id = :company_id AND status <> :cancelled_status');
        $taskInvoicedStmt->execute([
            'task_id' => $taskId,
            'company_id' => $companyId,
            'cancelled_status' => 'cancelled',
        ]);
        $taskInvoicedTotal = (float) $taskInvoicedStmt->fetchColumn();
        $taskRemaining = round($taskQuoted - $taskInvoicedTotal, 2);

        if ($amount > $taskRemaining) {
            flash('error', 'Invoice amount exceeds remaining quoted fee for this task.');
            redirect('admin/workspace.php?view=invoices');
        }

        $invoiceNo = 'INV-TSK-' . date('Ymd') . '-' . str_pad((string) $taskId, 5, '0', STR_PAD_LEFT) . '-' . strtoupper(substr((string) bin2hex(random_bytes(3)), 0, 4));

        $stmt = db()->prepare('INSERT INTO task_invoices (company_id, task_id, stage_id, invoice_no, invoice_type, amount, status, issued_on, due_on, notes, issued_by) VALUES (:company_id, :task_id, :stage_id, :invoice_no, :invoice_type, :amount, :status, :issued_on, :due_on, :notes, :issued_by)');
        $stmt->execute([
            'company_id' => $companyId,
            'task_id' => $taskId,
            'stage_id' => $stageId > 0 ? $stageId : null,
            'invoice_no' => $invoiceNo,
            'invoice_type' => $invoiceType,
            'amount' => $amount,
            'status' => $status,
            'issued_on' => $issuedOn,
            'due_on' => $dueOn !== '' ? $dueOn : null,
            'notes' => $notes !== '' ? $notes : null,
            'issued_by' => $adminId > 0 ? $adminId : null,
        ]);

        $invoiceId = (int) db()->lastInsertId();
        log_activity('task_invoice', $invoiceId, 'issued', 'Task invoice issued from admin work portal.', $adminId);
        if ($status === 'issued') {
            auto_post_task_invoice_voucher($invoiceId, $adminId);
        }
        flash('success', 'Invoice issued successfully.');
        redirect('admin/workspace.php?view=invoices');
    }

    flash('error', 'Unsupported portal action.');
    redirect('admin/workspace.php?view=home');
}

$search = trim((string) ($_GET['q'] ?? ''));
$taskStatusFilter = trim((string) ($_GET['task_status'] ?? 'all'));
$clientFilter = (int) ($_GET['client_id'] ?? 0);
$sort = trim((string) ($_GET['sort'] ?? 'created_desc'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$allowedSort = [
    'created_desc' => 't.created_at DESC',
    'created_asc' => 't.created_at ASC',
    'due_asc' => 't.due_date ASC, t.created_at DESC',
    'due_desc' => 't.due_date DESC, t.created_at DESC',
    'fee_desc' => 't.quoted_fee DESC, t.created_at DESC',
    'fee_asc' => 't.quoted_fee ASC, t.created_at DESC',
];
if (!array_key_exists($sort, $allowedSort)) {
    $sort = 'created_desc';
}

$staffUsers = [];
$portalUsers = [];
$clients = [];
$teams = [];
$contracts = [];
$tasks = [];
$taskOptions = [];
$totalTasks = 0;
$totalPages = 1;
$stages = [];
$invoices = [];
$staffWorkloads = [];

if ($missingTables === []) {
    $staffStmt = db()->prepare("SELECT id, name, email FROM users WHERE role = 'staff' AND status = 'active' AND company_id = :company_id ORDER BY name ASC");
    $staffStmt->execute(['company_id' => $companyId]);
    $staffUsers = $staffStmt->fetchAll();
    $portalStmt = db()->prepare("SELECT id, name, email, role, status, created_at FROM users WHERE company_id = :company_id ORDER BY created_at DESC LIMIT 150");
    $portalStmt->execute(['company_id' => $companyId]);
    $portalUsers = $portalStmt->fetchAll();

    if ($view === 'staff' && table_exists('team_members')) {
        foreach ($staffUsers as $staffUser) {
            $staffUserId = (int) $staffUser['id'];

            $staffTeamIdsStmt = db()->prepare('SELECT team_id FROM team_members WHERE user_id = :user_id');
            $staffTeamIdsStmt->execute(['user_id' => $staffUserId]);
            $staffTeamIds = array_map('intval', array_column($staffTeamIdsStmt->fetchAll(), 'team_id'));

            $staffClientIds = staff_scoped_client_ids($staffUserId, $companyId);

            $staffOpenTasks = 0;
            $staffCompletedTasks = 0;
            if (table_exists('client_tasks')) {
                // Task-wise/stage-wise assignment (with team as fallback), not client-wise.
                $staffTaskWhere = 't.company_id = ? AND (';
                $staffTaskBindings = [$companyId];
                if ($hasTaskAssignment) {
                    $staffTaskWhere .= 't.assigned_staff_user_id = ?';
                    $staffTaskBindings[] = $staffUserId;
                } else {
                    $staffTaskWhere .= '1 = 0';
                }
                if ($staffTeamIds !== []) {
                    $staffTeamPlaceholders = implode(',', array_fill(0, count($staffTeamIds), '?'));
                    $staffTaskWhere .= " OR t.team_id IN ($staffTeamPlaceholders)";
                    $staffTaskBindings = array_merge($staffTaskBindings, $staffTeamIds);
                }
                if ($hasStageAssignment) {
                    $staffTaskWhere .= ' OR EXISTS (SELECT 1 FROM task_stages sts WHERE sts.task_id = t.id AND sts.assigned_staff_user_id = ?)';
                    $staffTaskBindings[] = $staffUserId;
                }
                $staffTaskWhere .= ')';

                $staffTaskStmt = db()->prepare("SELECT t.status, COUNT(*) AS total FROM client_tasks t WHERE $staffTaskWhere GROUP BY t.status");
                $staffTaskStmt->execute($staffTaskBindings);
                foreach ($staffTaskStmt->fetchAll() as $statusRow) {
                    $statusCount = (int) $statusRow['total'];
                    if (in_array($statusRow['status'], ['new', 'in_progress', 'on_hold'], true)) {
                        $staffOpenTasks += $statusCount;
                    } elseif ($statusRow['status'] === 'completed') {
                        $staffCompletedTasks += $statusCount;
                    }
                }
            }

            $staffWorkloads[] = [
                'id' => $staffUserId,
                'name' => $staffUser['name'],
                'email' => $staffUser['email'],
                'team_count' => count($staffTeamIds),
                'client_count' => count($staffClientIds),
                'open_tasks' => $staffOpenTasks,
                'completed_tasks' => $staffCompletedTasks,
            ];
        }
    }

    $industries = active_industries();
    $serviceProviderEntities = active_service_provider_entities();
    $clientMode = (string) ($_GET['mode'] ?? '');
    $selectedClientId = (int) ($_GET['client_id'] ?? 0);
    $selectedClient = null;
    $selectedClientServiceProviderIds = [];

    $clientStmt = db()->prepare("SELECT cp.*, u.name, u.email, i.name AS industry_name
        FROM client_profiles cp
        INNER JOIN users u ON u.id = cp.user_id
        LEFT JOIN industries i ON i.id = cp.industry_id
        WHERE cp.company_id = :company_id
        ORDER BY cp.created_at DESC");
    $clientStmt->execute(['company_id' => $companyId]);
    $clients = $clientStmt->fetchAll();

    foreach ($clients as &$clientRow) {
        $clientRow['service_provider_names'] = client_service_provider_names((int) $clientRow['id']);
    }
    unset($clientRow);

    if ($selectedClientId > 0) {
        $selectedStmt = db()->prepare("SELECT cp.*, u.name, u.email, i.name AS industry_name
            FROM client_profiles cp
            INNER JOIN users u ON u.id = cp.user_id
            LEFT JOIN industries i ON i.id = cp.industry_id
            WHERE cp.id = :id AND cp.company_id = :company_id
            LIMIT 1");
        $selectedStmt->execute([
            'id' => $selectedClientId,
            'company_id' => $companyId,
        ]);
        $selectedClient = $selectedStmt->fetch() ?: null;
        if ($selectedClient) {
            $selectedClient['service_provider_names'] = client_service_provider_names((int) $selectedClient['id']);
            $selectedClientServiceProviderIds = client_service_provider_ids((int) $selectedClient['id']);
        }
    }

    $teamStmt = db()->prepare("SELECT t.*, u.name AS leader_name, COUNT(tm.id) AS member_count
        FROM teams t
        INNER JOIN users u ON u.id = t.leader_user_id
        LEFT JOIN team_members tm ON tm.team_id = t.id
        WHERE t.company_id = :company_id
        GROUP BY t.id
        ORDER BY t.created_at DESC");
    $teamStmt->execute(['company_id' => $companyId]);
    $teams = $teamStmt->fetchAll();

    $contractStmt = db()->prepare("SELECT sc.*, cp.organization_name, u.name AS created_by_name
        FROM service_contracts sc
        INNER JOIN client_profiles cp ON cp.id = sc.client_id
        LEFT JOIN users u ON u.id = sc.created_by
        WHERE sc.company_id = :company_id
        ORDER BY sc.created_at DESC LIMIT 100");
    $contractStmt->execute(['company_id' => $companyId]);
    $contracts = $contractStmt->fetchAll();

    $taskOptionsStmt = db()->prepare("SELECT t.id, t.title, cp.organization_name
        FROM client_tasks t
        INNER JOIN client_profiles cp ON cp.id = t.client_id
        WHERE t.company_id = :company_id
        ORDER BY t.created_at DESC LIMIT 500");
    $taskOptionsStmt->execute(['company_id' => $companyId]);
    $taskOptions = $taskOptionsStmt->fetchAll();

    $where = [];
    $params = [];

    $where[] = 't.company_id = :company_id';
    $params['company_id'] = $companyId;

    if ($search !== '') {
        $where[] = '(t.title LIKE :search1 OR cp.organization_name LIKE :search2)';
        $params['search1'] = $params['search2'] = '%' . $search . '%';
    }

    if ($taskStatusFilter !== 'all' && in_array($taskStatusFilter, $allowedTaskStatus, true)) {
        $where[] = 't.status = :task_status';
        $params['task_status'] = $taskStatusFilter;
    }

    if ($clientFilter > 0) {
        $where[] = 't.client_id = :client_id';
        $params['client_id'] = $clientFilter;
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    $countStmt = db()->prepare('SELECT COUNT(*) FROM client_tasks t INNER JOIN client_profiles cp ON cp.id = t.client_id' . $whereSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue(':' . $key, $value);
    }
    $countStmt->execute();
    $totalTasks = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalTasks / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $assignedNameSelect = $hasTaskAssignment ? ', au.name AS assigned_staff_name' : ', NULL AS assigned_staff_name';
    $assignedNameJoin = $hasTaskAssignment ? ' LEFT JOIN users au ON au.id = t.assigned_staff_user_id' : '';
    $tasksSql = "SELECT t.*, cp.organization_name, tm.name AS team_name, sc.contract_no, u.name AS created_by_name{$assignedNameSelect},
            COALESCE((SELECT SUM(si.amount) FROM task_invoices si WHERE si.task_id = t.id AND si.company_id = t.company_id AND si.status <> 'cancelled'), 0) AS invoiced_total
        FROM client_tasks t
        INNER JOIN client_profiles cp ON cp.id = t.client_id
        LEFT JOIN teams tm ON tm.id = t.team_id
        LEFT JOIN service_contracts sc ON sc.id = t.contract_id
        LEFT JOIN users u ON u.id = t.created_by{$assignedNameJoin}
        " . $whereSql .
        ' ORDER BY ' . $allowedSort[$sort] . ' LIMIT :limit OFFSET :offset';

    $taskStmt = db()->prepare($tasksSql);
    foreach ($params as $key => $value) {
        $taskStmt->bindValue(':' . $key, $value);
    }
    $taskStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $taskStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $taskStmt->execute();
    $tasks = $taskStmt->fetchAll();

    $stageNameSelect = $hasStageAssignment ? ', sau.name AS assigned_staff_name' : ', NULL AS assigned_staff_name';
    $stageNameJoin = $hasStageAssignment ? ' LEFT JOIN users sau ON sau.id = ts.assigned_staff_user_id' : '';
    $stageStmt = db()->prepare("SELECT ts.*, t.title AS task_title, cp.organization_name{$stageNameSelect}
        FROM task_stages ts
        INNER JOIN client_tasks t ON t.id = ts.task_id
        INNER JOIN client_profiles cp ON cp.id = t.client_id{$stageNameJoin}
        WHERE ts.company_id = :stage_company_id AND t.company_id = :task_company_id
        ORDER BY ts.created_at DESC LIMIT 150");
    $stageStmt->execute([
        'stage_company_id' => $companyId,
        'task_company_id' => $companyId,
    ]);
    $stages = $stageStmt->fetchAll();

    $invoiceStmt = db()->prepare("SELECT ti.*, t.title AS task_title, cp.organization_name, ts.stage_name, u.name AS issued_by_name
        FROM task_invoices ti
        INNER JOIN client_tasks t ON t.id = ti.task_id
        INNER JOIN client_profiles cp ON cp.id = t.client_id
        LEFT JOIN task_stages ts ON ts.id = ti.stage_id
        LEFT JOIN users u ON u.id = ti.issued_by
        WHERE ti.company_id = :invoice_company_id AND t.company_id = :task_company_id
        ORDER BY ti.created_at DESC LIMIT 150");
    $invoiceStmt->execute([
        'invoice_company_id' => $companyId,
        'task_company_id' => $companyId,
    ]);
    $invoices = $invoiceStmt->fetchAll();
}

$mbwStatusTone = [
    'new' => 'blue', 'in_progress' => 'amber', 'on_hold' => 'gray', 'completed' => 'green',
    'cancelled' => 'red', 'pending' => 'amber', 'draft' => 'blue', 'issued' => 'amber',
    'paid' => 'green', 'active' => 'green', 'inactive' => 'red', 'suspended' => 'red', 'terminated' => 'red',
];
$mbwPriorityTone = ['low' => 'gray', 'normal' => 'blue', 'high' => 'amber', 'urgent' => 'red'];
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="actions workspace-module-bar" style="margin-bottom: 16px;">
    <a class="button<?= $view === 'home' ? '' : ' secondary' ?>" href="<?= e(url('admin/workspace.php?view=home')) ?>"><?= icon('home') ?>Home</a>
    <a class="button<?= $view === 'clients' ? '' : ' secondary' ?>" href="<?= e(url('admin/workspace.php?view=clients')) ?>"><?= icon('clients') ?>Clients</a>
    <a class="button<?= $view === 'industries' ? '' : ' secondary' ?>" href="<?= e(url('admin/workspace.php?view=industries')) ?>"><?= icon('settings') ?>Industries</a>
    <a class="button<?= $view === 'service-providers' ? '' : ' secondary' ?>" href="<?= e(url('admin/workspace.php?view=service-providers')) ?>"><?= icon('companies') ?>Service providers</a>
    <a class="button<?= $view === 'teams' ? '' : ' secondary' ?>" href="<?= e(url('admin/workspace.php?view=teams')) ?>"><?= icon('teams') ?>Teams</a>
    <a class="button<?= $view === 'contracts' ? '' : ' secondary' ?>" href="<?= e(url('admin/workspace.php?view=contracts')) ?>"><?= icon('contracts') ?>Contracts</a>
    <a class="button<?= $view === 'tasks' ? '' : ' secondary' ?>" href="<?= e(url('admin/workspace.php?view=tasks')) ?>"><?= icon('tasks') ?>Tasks</a>
    <a class="button<?= $view === 'invoices' ? '' : ' secondary' ?>" href="<?= e(url('admin/workspace.php?view=invoices')) ?>"><?= icon('invoices') ?>Invoices</a>
    <a class="button<?= $view === 'staff' ? '' : ' secondary' ?>" href="<?= e(url('admin/workspace.php?view=staff')) ?>"><?= icon('staff') ?>Staff workload</a>
</div>

<?php if ($missingTables !== []): ?>
    <div class="notice error">
        Missing required database tables for the admin work portal: <?= e(implode(', ', $missingTables)) ?>.
        <a class="button secondary" href="<?= e(url('admin/fix-admin-work-portal.php')) ?>">Run database repair</a>
        <?php if ($autoRepairErrors !== []): ?>
            <br><small>Automatic repair could not complete: <?= e(implode(' | ', $autoRepairErrors)) ?></small>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php if ($view === 'home'): ?>
    <section class="mbw-kpi-grid">
        <a class="mbw-kpi" href="<?= e(url('admin/users.php')) ?>"><div><span class="mbw-kpi-label">Users</span><div class="mbw-kpi-value"><?= e((string) count($portalUsers)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">latest 150</span></span></div><span class="mbw-chip tone-blue"><?= icon('users') ?></span></a>
        <a class="mbw-kpi" href="<?= e(url('admin/workspace.php?view=clients')) ?>"><div><span class="mbw-kpi-label">Client Profiles</span><div class="mbw-kpi-value"><?= e((string) count($clients)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">all clients</span></span></div><span class="mbw-chip tone-teal"><?= icon('clients') ?></span></a>
        <a class="mbw-kpi" href="<?= e(url('admin/workspace.php?view=teams')) ?>"><div><span class="mbw-kpi-label">Teams</span><div class="mbw-kpi-value"><?= e((string) count($teams)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">active groups</span></span></div><span class="mbw-chip tone-purple"><?= icon('teams') ?></span></a>
        <a class="mbw-kpi" href="<?= e(url('admin/workspace.php?view=tasks')) ?>"><div><span class="mbw-kpi-label">Tracked Tasks</span><div class="mbw-kpi-value"><?= e((string) $totalTasks) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">all statuses</span></span></div><span class="mbw-chip tone-amber"><?= icon('tasks') ?></span></a>
    </section>

    <section class="mbw-card">
        <div class="mbw-card-head">
            <h2>Work Modules</h2>
        </div>
        <div class="mbw-qa-grid">
            <a class="mbw-qa" href="<?= e(url('admin/users.php')) ?>"><span class="mbw-chip is-square tone-blue"><?= icon('users') ?></span><div><strong>Users workflow</strong><span>Create, edit, filter, suspend, and delete users.</span></div></a>
            <a class="mbw-qa" href="<?= e(url('admin/workspace.php?view=clients')) ?>"><span class="mbw-chip is-square tone-teal"><?= icon('clients') ?></span><div><strong>Clients</strong><span>Profiles, credentials, industries, and providers.</span></div></a>
            <a class="mbw-qa" href="<?= e(url('admin/workspace.php?view=teams')) ?>"><span class="mbw-chip is-square tone-purple"><?= icon('teams') ?></span><div><strong>Teams</strong><span>Create teams from staff and assign leaders.</span></div></a>
            <a class="mbw-qa" href="<?= e(url('admin/workspace.php?view=contracts')) ?>"><span class="mbw-chip is-square tone-green"><?= icon('contracts') ?></span><div><strong>Contracts</strong><span>Define service contracts and billing terms.</span></div></a>
            <a class="mbw-qa" href="<?= e(url('admin/workspace.php?view=tasks')) ?>"><span class="mbw-chip is-square tone-amber"><?= icon('tasks') ?></span><div><strong>Tasks</strong><span>Track client tasks, stages, and completion.</span></div></a>
            <a class="mbw-qa" href="<?= e(url('admin/workspace.php?view=invoices')) ?>"><span class="mbw-chip is-square tone-red"><?= icon('invoices') ?></span><div><strong>Invoices</strong><span>Issue stage/task invoices with amount checks.</span></div></a>
            <a class="mbw-qa" href="<?= e(url('admin/workspace.php?view=staff')) ?>"><span class="mbw-chip is-square tone-gray"><?= icon('staff') ?></span><div><strong>Staff workload</strong><span>See what each staff member sees in their portal.</span></div></a>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($view === 'clients'): ?>
    <section class="mbw-card" id="clients">
        <div class="mbw-card-head">
            <h2>Clients</h2>
        </div>
        
            <div class="form-card">
                <h3>New Client</h3>
                <form method="post" class="workspace-form-grid" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_client">
                    <label>Client or Company Name<input type="text" name="organization_name" required></label>
                    <label>Automatically Generated Client Code<input type="text" value="Generated after save" readonly></label>
                    <label>Registration Number<input type="text" name="registration_no"></label>
                    <label>PAN Number<input type="text" name="pan_no"></label>
                    <label>Industry
                        <select name="industry_id">
                            <option value="0">Select industry</option>
                            <?php foreach ($industries as $industry): ?>
                                <option value="<?= e((int) $industry['id']) ?>"><?= e($industry['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Status
                        <select name="client_status">
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </label>
                    <label>Authorized Person Name<input type="text" name="authorized_person_name" required></label>
                    <label>Position or Designation<input type="text" name="authorized_person_position"></label>
                    <label>Authorized Signatory Name<input type="text" name="authorized_signatory_name"></label>
                    <label>Client Login Email<input type="email" name="email" required></label>
                    <label>Temporary Client Login Password<input type="password" name="password" minlength="8" required></label>
                    <label>Contact Number<input type="text" name="contact_number"></label>
                    <label>Website<input type="url" name="website" placeholder="https://example.com"></label>
                    <label class="workspace-span-2">Address<textarea name="address"></textarea></label>
                    <label class="workspace-span-2">Service Provider Entity
                        <select name="service_provider_entity_ids[]" class="mbw-select">
                            <option value="">Select service provider</option>
                            <?php foreach ($serviceProviderEntities as $entity): ?>
                                <option value="<?= e((int) $entity['id']) ?>"><?= e($entity['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Company Logo<input type="file" name="company_logo" accept="image/jpeg,image/png,image/webp"></label>
                    <label class="workspace-span-2">Notes<textarea name="notes"></textarea></label>
                    <div class="workspace-span-2">
                        <button type="submit"><?= icon('clients') ?>New Client</button>
                    </div>
                </form>
            </div>
        

        <?php if ($selectedClient && $clientMode === 'view'): ?>
            <section class="mbw-card">
                <div class="mbw-card-head">
                    <h2>Client Profile</h2>
                    <div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('admin/workspace.php?view=clients')) ?>">Back to List</a></div>
                </div>
                <section class="mbw-kpi-grid">
                    <article class="mbw-kpi">
                        <div>
                            <span class="mbw-kpi-label">Organization</span>
                            <div class="mbw-kpi-value" style="font-size:1rem;"><?= e($selectedClient['organization_name']) ?></div>
                            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= e($selectedClient['client_code'] ?? 'N/A') ?></span></span>
                        </div>
                        <?php if (!empty($selectedClient['company_logo_path'])): ?>
                            <img src="<?= e(url((string) $selectedClient['company_logo_path'])) ?>" alt="" style="max-width: 64px; max-height: 48px; object-fit: contain;">
                        <?php else: ?>
                            <span class="mbw-chip tone-teal"><?= icon('clients') ?></span>
                        <?php endif; ?>
                    </article>
                    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Registration No.</span><div class="mbw-kpi-value" style="font-size:1rem;"><?= e($selectedClient['registration_no'] ?? 'N/A') ?></div></div><span class="mbw-chip tone-blue"><?= icon('documents') ?></span></article>
                    <article class="mbw-kpi"><div><span class="mbw-kpi-label">PAN No.</span><div class="mbw-kpi-value" style="font-size:1rem;"><?= e($selectedClient['pan_no'] ?? 'N/A') ?></div></div><span class="mbw-chip tone-purple"><?= icon('tag') ?></span></article>
                    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Status</span><div class="mbw-kpi-value" style="font-size:1rem;"><span class="mbw-pill tone-<?= e($mbwStatusTone[$selectedClient['client_status'] ?? 'active'] ?? 'gray') ?>"><?= e(ucfirst((string) ($selectedClient['client_status'] ?? 'active'))) ?></span></div></div><span class="mbw-chip tone-green"><?= icon('compliance') ?></span></article>
                </section>
                <div style="overflow-x:auto">
                <table>
                    <tbody>
                        <tr><th>Industry</th><td><?= e($selectedClient['industry_name'] ?? 'N/A') ?></td></tr>
                        <tr><th>Authorized Person Name</th><td><?= e($selectedClient['name']) ?></td></tr>
                        <tr><th>Position</th><td><?= e($selectedClient['authorized_person_position'] ?? 'N/A') ?></td></tr>
                        <tr><th>Login email</th><td><?= e($selectedClient['email']) ?></td></tr>
                        <tr><th>Contact number</th><td><?= e($selectedClient['contact_number'] ?? 'N/A') ?></td></tr>
                        <tr><th>Address</th><td><?= nl2br(e($selectedClient['address'] ?? 'N/A')) ?></td></tr>
                        <tr><th>Website</th><td><?php if (!empty($selectedClient['website'])): ?><a href="<?= e($selectedClient['website']) ?>" target="_blank" rel="noopener"><?= e($selectedClient['website']) ?></a><?php else: ?>N/A<?php endif; ?></td></tr>
                        <tr><th>Authorized signatory</th><td><?= e($selectedClient['authorized_signatory_name'] ?? 'N/A') ?></td></tr>
                        <tr><th>Service-provider entities</th><td><?= e($selectedClient['service_provider_names'] ?: 'N/A') ?></td></tr>
                        <tr><th>Created</th><td><?= e($selectedClient['created_at'] ?? '') ?></td></tr>
                        <tr><th>Updated</th><td><?= e($selectedClient['updated_at'] ?? '') ?></td></tr>
                    </tbody>
                </table>
                </div>
                <div class="actions">
                    <a class="button secondary" href="<?= e(url('admin/workspace.php?view=clients&mode=edit&client_id=' . (int) $selectedClient['id'])) ?>"><?= icon('settings') ?>Edit</a>
                    <a class="button secondary" href="<?= e(url('admin/workspace.php?view=clients')) ?>">Return to Client List</a>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($selectedClient && $clientMode === 'edit'): ?>
            <div class="form-card">
                <h3>Edit Client</h3>
                <form method="post" class="workspace-form-grid" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_client">
                    <input type="hidden" name="client_id" value="<?= e((int) $selectedClient['id']) ?>">
                    <label>Client or Company Name<input type="text" name="organization_name" value="<?= e($selectedClient['organization_name']) ?>" required></label>
                    <label>Client Code<input type="text" value="<?= e($selectedClient['client_code'] ?? '') ?>" readonly></label>
                    <label>Registration Number<input type="text" name="registration_no" value="<?= e($selectedClient['registration_no'] ?? '') ?>"></label>
                    <label>PAN Number<input type="text" name="pan_no" value="<?= e($selectedClient['pan_no'] ?? '') ?>"></label>
                    <label>Industry
                        <select name="industry_id">
                            <option value="0">Select industry</option>
                            <?php foreach ($industries as $industry): ?>
                                <option value="<?= e((int) $industry['id']) ?>" <?= (int) ($selectedClient['industry_id'] ?? 0) === (int) $industry['id'] ? 'selected' : '' ?>><?= e($industry['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Status
                        <select name="client_status">
                            <?php foreach ($allowedClientStatus as $statusOption): ?>
                                <option value="<?= e($statusOption) ?>" <?= ($selectedClient['client_status'] ?? 'active') === $statusOption ? 'selected' : '' ?>><?= e(ucfirst($statusOption)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Authorized Person Name<input type="text" name="authorized_person_name" value="<?= e($selectedClient['name']) ?>" required></label>
                    <label>Position or Designation<input type="text" name="authorized_person_position" value="<?= e($selectedClient['authorized_person_position'] ?? '') ?>"></label>
                    <label>Authorized Signatory Name<input type="text" name="authorized_signatory_name" value="<?= e($selectedClient['authorized_signatory_name'] ?? '') ?>"></label>
                    <label>Contact Number<input type="text" name="contact_number" value="<?= e($selectedClient['contact_number'] ?? '') ?>"></label>
                    <label>Website<input type="url" name="website" value="<?= e($selectedClient['website'] ?? '') ?>"></label>
                    <label class="workspace-span-2">Address<textarea name="address"><?= e($selectedClient['address'] ?? '') ?></textarea></label>
                    <label class="workspace-span-2">Service Provider Entity
                        <select name="service_provider_entity_ids[]" class="mbw-select">
                            <option value="">Select service provider</option>
                            <?php foreach ($serviceProviderEntities as $entity): ?>
                                <option value="<?= e((int) $entity['id']) ?>" <?= in_array((int) $entity['id'], $selectedClientServiceProviderIds, true) ? 'selected' : '' ?>><?= e($entity['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Replace Company Logo<input type="file" name="company_logo" accept="image/jpeg,image/png,image/webp"></label>
                    <label class="checkbox-line"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
                    <label class="workspace-span-2">Notes<textarea name="notes"><?= e($selectedClient['notes'] ?? '') ?></textarea></label>
                    <div class="workspace-span-2">
                        <button type="submit"><?= icon('settings') ?>Save Client</button>
                        <a class="button secondary" href="<?= e(url('admin/workspace.php?view=clients&mode=view&client_id=' . (int) $selectedClient['id'])) ?>">Cancel</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <section class="mbw-card">
            <div class="mbw-card-head">
                <h2>Recent Client Profiles</h2>
            </div>
            <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Code</th>
                        <th>Registration No.</th>
                        <th>PAN No.</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($clients === []): ?>
                        <tr><td colspan="5">No client profiles yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($clients as $client): ?>
                        <tr>
                            <td><?= e($client['organization_name']) ?><br><small><?= e($client['name']) ?> (<?= e($client['email']) ?>)</small></td>
                            <td><?= e($client['client_code'] ?? 'N/A') ?></td>
                            <td><?= e($client['registration_no'] ?? 'N/A') ?></td>
                            <td><?= e($client['pan_no'] ?? 'N/A') ?></td>
                            <td>
                                <div class="actions">
                                    <a class="button secondary" href="<?= e(url('admin/workspace.php?view=clients&mode=view&client_id=' . (int) $client['id'])) ?>">View</a>
                                    <a class="button secondary" href="<?= e(url('admin/workspace.php?view=clients&mode=edit&client_id=' . (int) $client['id'])) ?>">Edit</a>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="client_id" value="<?= e((int) $client['id']) ?>">
                                        <input type="hidden" name="action" value="<?= ($client['client_status'] ?? 'active') === 'active' ? 'suspend_client' : 'activate_client' ?>">
                                        <button class="button secondary" type="submit"><?= ($client['client_status'] ?? 'active') === 'active' ? 'Suspend' : 'Activate' ?></button>
                                    </form>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this client? Related records will cause a safe suspension instead.');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="client_id" value="<?= e((int) $client['id']) ?>">
                                        <input type="hidden" name="action" value="delete_client">
                                        <button class="button secondary" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </section>
    </section>
    <?php endif; ?>

    <?php if ($view === 'industries'): ?>
    <section class="mbw-card" id="industries">
        <div class="mbw-card-head">
            <h2>Industry Management</h2>
        </div>
        
            <form method="post" class="workspace-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_industry">
                <label>Industry name<input type="text" name="name" required></label>
                <label class="checkbox-line"><input type="checkbox" name="is_active" value="1" checked> Active</label>
                <button type="submit"><?= icon('settings') ?>Save industry</button>
            </form>
        
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Industry</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                <?php $allIndustries = table_exists('industries') ? db()->query('SELECT * FROM industries ORDER BY name ASC')->fetchAll() : []; ?>
                <?php if ($allIndustries === []): ?><tr><td colspan="3">No industries found.</td></tr><?php endif; ?>
                <?php foreach ($allIndustries as $industry): ?>
                    <tr>
                        <td>
                            <form method="post" class="actions">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="save_industry">
                                <input type="hidden" name="industry_id" value="<?= e((int) $industry['id']) ?>">
                                <input type="text" name="name" value="<?= e($industry['name']) ?>" required>
                        </td>
                        <td><label class="checkbox-line"><input type="checkbox" name="is_active" value="1" <?= (int) $industry['is_active'] === 1 ? 'checked' : '' ?>> Active</label></td>
                        <td>
                                <button class="button secondary" type="submit">Save</button>
                            </form>
                            <form method="post" onsubmit="return confirm('Delete or deactivate this industry?');">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_industry">
                                <input type="hidden" name="industry_id" value="<?= e((int) $industry['id']) ?>">
                                <button class="button secondary" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($view === 'service-providers'): ?>
    <section class="mbw-card" id="service-providers">
        <div class="mbw-card-head">
            <h2>Service Provider Entity Management</h2>
        </div>
        
            <form method="post" class="workspace-form-grid" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_service_provider_entity">
                <label>Entity name<input type="text" name="name" required></label>
                <label>Code<input type="text" name="code"></label>
                <label>Contact email<input type="email" name="contact_email"></label>
                <label>Contact number<input type="text" name="contact_number"></label>
                <label>Website<input type="url" name="website"></label>
                <label>Authorized signatory<input type="text" name="authorized_signatory_name"></label>
                <label class="workspace-span-2">Address<textarea name="address"></textarea></label>
                <label>Entity logo<input type="file" name="entity_logo" accept="image/jpeg,image/png,image/webp"></label>
                <label class="checkbox-line"><input type="checkbox" name="is_active" value="1" checked> Active</label>
                <button type="submit"><?= icon('companies') ?>Save entity</button>
            </form>
        

        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Entity</th><th>Contact</th><th>Status</th><th class="is-numeric">Connected Clients</th></tr></thead>
            <tbody>
                <?php $allEntities = table_exists('service_provider_entities') ? db()->query('SELECT spe.*, (SELECT COUNT(*) FROM client_service_provider_entities cspe WHERE cspe.service_provider_entity_id = spe.id) AS client_count FROM service_provider_entities spe ORDER BY spe.name ASC')->fetchAll() : []; ?>
                <?php if ($allEntities === []): ?><tr><td colspan="4">No service provider entities found.</td></tr><?php endif; ?>
                <?php foreach ($allEntities as $entity): ?>
                    <tr>
                        <td><?= e($entity['name']) ?><br><small><?= e($entity['code'] ?? '') ?></small></td>
                        <td><?= e($entity['contact_email'] ?? '') ?><br><small><?= e($entity['contact_number'] ?? '') ?></small></td>
                        <td><?= (int) $entity['is_active'] === 1 ? '<span class="mbw-pill tone-green">Active</span>' : '<span class="mbw-pill tone-red">Inactive</span>' ?></td>
                        <td class="is-numeric"><?= e((string) (int) $entity['client_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($view === 'teams'): ?>
    <section class="mbw-card" id="teams">
        <div class="mbw-card-head">
            <h2>Teams</h2>
        </div>
        
            <div class="form-card">
                <h3>Create team</h3>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_team">
                    <label>Team name<input type="text" name="name" required></label>
                    <label>Leader (must be staff)
                        <select name="leader_user_id" required>
                            <option value="">Select leader</option>
                            <?php foreach ($staffUsers as $staff): ?>
                                <option value="<?= e((int) $staff['id']) ?>"><?= e($staff['name']) ?> (<?= e($staff['email']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Additional members
                        <select name="member_ids[]" multiple size="6">
                            <?php foreach ($staffUsers as $staff): ?>
                                <option value="<?= e((int) $staff['id']) ?>"><?= e($staff['name']) ?> (<?= e($staff['email']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="workspace-span-2">Description<textarea name="description"></textarea></label>
                    <div class="workspace-span-2">
                        <button type="submit"><?= icon('teams') ?>Create team</button>
                    </div>
                </form>
            </div>
        

        <section class="mbw-card">
            <div class="mbw-card-head">
                <h2>Team List</h2>
            </div>
            <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Team</th>
                        <th>Leader</th>
                        <th class="is-numeric">Members</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($teams === []): ?>
                        <tr><td colspan="4">No teams yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($teams as $team): ?>
                        <tr>
                            <td><?= e($team['name']) ?></td>
                            <td><?= e($team['leader_name']) ?></td>
                            <td class="is-numeric"><?= e((int) $team['member_count']) ?></td>
                            <td><?= (int) $team['is_active'] === 1 ? '<span class="mbw-pill tone-green">Active</span>' : '<span class="mbw-pill tone-red">Inactive</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </section>
    </section>
    <?php endif; ?>

    <?php if ($view === 'contracts'): ?>
    <section class="mbw-card" id="contracts">
        <div class="mbw-card-head">
            <h2>Service Contracts</h2>
        </div>
        <?php if ($documentLogoPath !== '' || $documentSignaturePath !== '' || $documentStampPath !== ''): ?>
            <div class="document-preview-card">
                <div class="invoice-brand-block">
                    <?php if ($documentLogoPath !== ''): ?>
                        <img class="document-logo" src="<?= e(url($documentLogoPath)) ?>" alt="<?= e(app_name()) ?> logo">
                    <?php endif; ?>
                    <div>
                        <h3>Contract document assets</h3>
                        <p>These logo, signature, and stamp assets will be used on contract and invoice documents.</p>
                    </div>
                </div>
                <div class="document-assets-row">
                    <?php if ($documentSignaturePath !== ''): ?>
                        <div>
                            <img class="document-signature" src="<?= e(url($documentSignaturePath)) ?>" alt="Authorized signature">
                            <span>Authorized signature</span>
                        </div>
                    <?php endif; ?>
                    <?php if ($documentStampPath !== ''): ?>
                        <div>
                            <img class="document-stamp" src="<?= e(url($documentStampPath)) ?>" alt="Company stamp">
                            <span>Company stamp</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
            <div class="form-card">
                <h3>Create service contract</h3>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_service_contract">
                    <label>Client
                        <select name="client_id" required>
                            <option value="">Select client</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= e((int) $client['id']) ?>"><?= e($client['organization_name']) ?> (<?= e($client['email']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Contract title<input type="text" name="title" required></label>
                    <label>Contract number<input type="text" name="contract_no" required></label>
                    <label>Total contract value<input type="number" step="0.01" min="0" name="total_value" required></label>
                    <label>Billing cycle<input type="text" name="billing_cycle" placeholder="monthly, quarterly, yearly, one_time"></label>
                    <label>Status
                        <select name="status" required>
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="terminated">Terminated</option>
                        </select>
                    </label>
                    <label>Start date<input type="date" name="start_date"></label>
                    <label>End date<input type="date" name="end_date"></label>
                    <label class="workspace-span-2">Terms<textarea name="terms"></textarea></label>
                    <div class="workspace-span-2">
                        <button type="submit"><?= icon('contracts') ?>Create service contract</button>
                    </div>
                </form>
            </div>
        

        <section class="mbw-card">
            <div class="mbw-card-head">
                <h2>Recent Service Contracts</h2>
            </div>
            <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Contract</th>
                        <th>Client</th>
                        <th class="is-numeric">Value</th>
                        <th>Status</th>
                        <th>Period</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($contracts === []): ?>
                        <tr><td colspan="6">No service contracts yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($contracts as $contract): ?>
                        <tr>
                            <td><?= e($contract['contract_no']) ?><br><small><?= e($contract['title']) ?></small></td>
                            <td><?= e($contract['organization_name']) ?></td>
                            <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $contract['total_value'], 2)) ?></td>
                            <td>
                                <form method="post" class="inline-action-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update_service_contract_status">
                                    <input type="hidden" name="contract_id" value="<?= e((int) $contract['id']) ?>">
                                    <select name="status">
                                        <?php foreach ($allowedContractStatus as $contractStatus): ?>
                                            <option value="<?= e($contractStatus) ?>" <?= (string) $contract['status'] === $contractStatus ? 'selected' : '' ?>><?= e($contractStatus) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="button secondary">Save</button>
                                </form>
                            </td>
                            <td><?= e($contract['start_date'] ?? '-') ?> to <?= e($contract['end_date'] ?? '-') ?></td>
                            <td>
                                <a class="button secondary" href="<?= e(url('admin/export-contract.php?id=' . (int) $contract['id'] . '&format=pdf')) ?>" target="_blank" rel="noopener">Preview Template</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </section>
    </section>
    <?php endif; ?>

    <?php if ($view === 'tasks'): ?>
    <section class="mbw-card" id="tasks">
        <div class="mbw-card-head">
            <h2>Tasks</h2>
        </div>
        <div class="workspace-feature-stack">
            
                <div class="form-card">
                <h3>Create task</h3>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_task">
                    <label>Client (required)
                        <select name="client_id" required>
                            <option value="">Select client</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= e((int) $client['id']) ?>"><?= e($client['organization_name']) ?> (<?= e($client['email']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Contract (optional)
                        <select name="contract_id">
                            <option value="0">No contract</option>
                            <?php foreach ($contracts as $contract): ?>
                                <option value="<?= e((int) $contract['id']) ?>"><?= e($contract['contract_no']) ?> - <?= e($contract['organization_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Service Provider Entity
                        <select name="service_provider_entity_id">
                            <option value="0">Select when applicable</option>
                            <?php foreach ($serviceProviderEntities as $entity): ?>
                                <option value="<?= e((int) $entity['id']) ?>"><?= e($entity['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Team assignment (optional)
                        <select name="team_id">
                            <option value="0">Unassigned</option>
                            <?php foreach ($teams as $team): ?>
                                <option value="<?= e((int) $team['id']) ?>"><?= e($team['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php if ($hasTaskAssignment): ?>
                        <label>Assigned staff (optional)
                            <select name="assigned_staff_user_id">
                                <option value="0">Unassigned</option>
                                <?php foreach ($staffUsers as $staffUser): ?>
                                    <option value="<?= e((int) $staffUser['id']) ?>"><?= e($staffUser['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <label>Task title<input type="text" name="title" required></label>
                    <label>Quoted total fee<input type="number" step="0.01" min="0" name="quoted_fee" required></label>
                    <label>Status
                        <select name="status" required>
                            <option value="new">New</option>
                            <option value="in_progress">In progress</option>
                            <option value="on_hold">On hold</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
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
                    <label>Start date<input type="date" name="start_date"></label>
                    <label>Due date<input type="date" name="due_date"></label>
                    <label class="workspace-span-2">Description<textarea name="description"></textarea></label>
                    <div class="workspace-span-2">
                        <button type="submit"><?= icon('tasks') ?>Create task</button>
                    </div>
                </form>
                </div>
            

            <details class="feature-disclosure">
                <summary>
                    <span class="stat-icon"><?= icon('insights') ?></span>
                    <span>
                        <strong>Manage stages and completion</strong>
                        <small>Add task stages or mark a stage/task as completed.</small>
                    </span>
                    <span class="feature-disclosure-action"><?= icon('login') ?>Open forms</span>
                </summary>
                <div class="form-card">
                <h3>Add stage to task</h3>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_task_stage">
                    <label>Task
                        <select name="task_id" required>
                            <option value="">Select task</option>
                            <?php foreach ($taskOptions as $task): ?>
                                <option value="<?= e((int) $task['id']) ?>">#<?= e((int) $task['id']) ?> - <?= e($task['title']) ?> (<?= e($task['organization_name']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Stage name<input type="text" name="stage_name" required></label>
                    <label>Sequence number<input type="number" name="sequence_no" min="1" value="1" required></label>
                    <label>Stage fee<input type="number" step="0.01" min="0" name="stage_fee" required></label>
                    <label>Status
                        <select name="status" required>
                            <option value="pending">Pending</option>
                            <option value="in_progress">In progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </label>
                    <?php if ($hasStageAssignment): ?>
                        <label>Assigned staff (optional)
                            <select name="assigned_staff_user_id">
                                <option value="0">Unassigned</option>
                                <?php foreach ($staffUsers as $staffUser): ?>
                                    <option value="<?= e((int) $staffUser['id']) ?>"><?= e($staffUser['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <div>
                        <button type="submit"><?= icon('tasks') ?>Add stage</button>
                    </div>
                </form>

                <?php if ($hasStageAssignment): ?>
                <h3 style="margin-top:18px;">Assign stage to staff</h3>
                <form method="post" class="workspace-form-grid" style="margin-bottom:12px;">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="assign_stage_staff">
                    <label>Stage
                        <select name="stage_id" required>
                            <option value="">Select stage</option>
                            <?php foreach ($stages as $stage): ?>
                                <option value="<?= e((int) $stage['id']) ?>">#<?= e((int) $stage['task_id']) ?> <?= e($stage['task_title']) ?> - <?= e($stage['stage_name']) ?> (<?= e($stage['assigned_staff_name'] ?? 'unassigned') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Staff member
                        <select name="assigned_staff_user_id">
                            <option value="0">Unassigned</option>
                            <?php foreach ($staffUsers as $staffUser): ?>
                                <option value="<?= e((int) $staffUser['id']) ?>"><?= e($staffUser['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div>
                        <button type="submit"><?= icon('staff') ?>Assign stage</button>
                    </div>
                </form>
                <?php endif; ?>

                <h3 style="margin-top:18px;">Complete task/stage</h3>
                <form method="post" class="workspace-form-grid" style="margin-bottom:12px;">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="complete_stage">
                    <label>Stage
                        <select name="stage_id" required>
                            <option value="">Select stage</option>
                            <?php foreach ($stages as $stage): ?>
                                <option value="<?= e((int) $stage['id']) ?>">#<?= e((int) $stage['task_id']) ?> <?= e($stage['task_title']) ?> - <?= e($stage['stage_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div>
                        <button type="submit"><?= icon('insights') ?>Mark stage completed</button>
                    </div>
                </form>

                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="complete_task">
                    <label>Task
                        <select name="task_id" required>
                            <option value="">Select task</option>
                            <?php foreach ($taskOptions as $task): ?>
                                <option value="<?= e((int) $task['id']) ?>">#<?= e((int) $task['id']) ?> - <?= e($task['title']) ?> (<?= e($task['organization_name']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div>
                        <button type="submit"><?= icon('tasks') ?>Mark task completed</button>
                    </div>
                </form>
                </div>
            </details>
        </div>

        <div class="users-filters-card" style="margin-top:20px;">
            <h3>Task filters</h3>
            <form id="workspace-task-filter-form" method="get" class="users-filter-grid">
                <input type="hidden" name="view" value="tasks">
                <label>Search
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Task or client">
                </label>
                <label>Status
                    <select name="task_status">
                        <option value="all" <?= $taskStatusFilter === 'all' ? 'selected' : '' ?>>All</option>
                        <?php foreach ($allowedTaskStatus as $status): ?>
                            <option value="<?= e($status) ?>" <?= $taskStatusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option>
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
                <label>Sort
                    <select name="sort">
                        <option value="created_desc" <?= $sort === 'created_desc' ? 'selected' : '' ?>>Newest</option>
                        <option value="created_asc" <?= $sort === 'created_asc' ? 'selected' : '' ?>>Oldest</option>
                        <option value="due_asc" <?= $sort === 'due_asc' ? 'selected' : '' ?>>Due date (asc)</option>
                        <option value="due_desc" <?= $sort === 'due_desc' ? 'selected' : '' ?>>Due date (desc)</option>
                        <option value="fee_desc" <?= $sort === 'fee_desc' ? 'selected' : '' ?>>Fee (high to low)</option>
                        <option value="fee_asc" <?= $sort === 'fee_asc' ? 'selected' : '' ?>>Fee (low to high)</option>
                    </select>
                </label>
                <div class="users-filter-actions">
                    <button id="workspace-task-filter-apply" type="submit">Apply</button>
                    <a class="button secondary" href="<?= e(url('admin/workspace.php?view=tasks')) ?>">Reset</a>
                </div>
            </form>
        </div>

        <section class="mbw-card" style="margin-top:20px;">
            <div class="mbw-card-head">
                <h2>Task List</h2>
            </div>
            <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Client</th>
                        <th>Team</th>
                        <th>Assigned staff</th>
                        <th class="is-numeric">Quoted fee</th>
                        <th class="is-numeric">Invoiced</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Due</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($tasks === []): ?>
                        <tr><td colspan="9">No tasks found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($tasks as $task): ?>
                        <?php $remaining = round((float) $task['quoted_fee'] - (float) $task['invoiced_total'], 2); ?>
                        <tr>
                            <td>#<?= e((int) $task['id']) ?> <?= e($task['title']) ?><br><small><?= e($task['contract_no'] ?? 'No contract') ?></small></td>
                            <td><?= e($task['organization_name']) ?></td>
                            <td><?= e($task['team_name'] ?? 'Unassigned') ?></td>
                            <td>
                                <?php if ($hasTaskAssignment): ?>
                                    <form method="post" style="display:flex; gap:4px; align-items:center;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="assign_task_staff">
                                        <input type="hidden" name="task_id" value="<?= e((int) $task['id']) ?>">
                                        <select name="assigned_staff_user_id">
                                            <option value="0">Unassigned</option>
                                            <?php foreach ($staffUsers as $staffUser): ?>
                                                <option value="<?= e((int) $staffUser['id']) ?>" <?= (int) ($task['assigned_staff_user_id'] ?? 0) === (int) $staffUser['id'] ? 'selected' : '' ?>><?= e($staffUser['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="button secondary" title="Save staff assignment">Save</button>
                                    </form>
                                <?php else: ?>
                                    <?= e($task['assigned_staff_name'] ?? 'Unassigned') ?>
                                <?php endif; ?>
                            </td>
                            <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $task['quoted_fee'], 2)) ?></td>
                            <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $task['invoiced_total'], 2)) ?><br><small>Remaining: <?= e(site_currency_symbol()) ?><?= e(number_format($remaining, 2)) ?></small></td>
                            <td><span class="mbw-pill tone-<?= e($mbwStatusTone[$task['status']] ?? 'gray') ?>"><?= e(str_replace('_', ' ', (string) $task['status'])) ?></span></td>
                            <td><span class="mbw-pill tone-<?= e($mbwPriorityTone[$task['priority']] ?? 'gray') ?>"><?= e($task['priority']) ?></span></td>
                            <td><?= e($task['due_date'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div class="admin-orders-pagination">
                <span>Page <?= e((string) $page) ?> of <?= e((string) $totalPages) ?></span>
                <?php if ($page > 1): ?>
                    <a class="button secondary" href="<?= e(url('admin/workspace.php?view=tasks&page=' . ($page - 1) . '&q=' . rawurlencode($search) . '&task_status=' . rawurlencode($taskStatusFilter) . '&client_id=' . $clientFilter . '&sort=' . rawurlencode($sort))) ?>">Previous</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a class="button secondary" href="<?= e(url('admin/workspace.php?view=tasks&page=' . ($page + 1) . '&q=' . rawurlencode($search) . '&task_status=' . rawurlencode($taskStatusFilter) . '&client_id=' . $clientFilter . '&sort=' . rawurlencode($sort))) ?>">Next</a>
                 <?php endif; ?>
             </div>
         </section>
     </section>
    <?php endif; ?>

    <?php if ($view === 'invoices'): ?>
     <section class="mbw-card" id="invoices">
         <div class="mbw-card-head">
             <h2>Invoices</h2>
         </div>
         
             <div class="form-card">
                 <h3>Issue invoice</h3>
                 <form method="post" class="workspace-form-grid">
                     <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                     <input type="hidden" name="action" value="issue_invoice">
                     <label>Task
                         <select name="task_id" required>
                             <option value="">Select task</option>
                            <?php foreach ($taskOptions as $task): ?>
                                 <option value="<?= e((int) $task['id']) ?>">#<?= e((int) $task['id']) ?> - <?= e($task['title']) ?> (<?= e($task['organization_name']) ?>)</option>
                             <?php endforeach; ?>
                         </select>
                     </label>
                     <label>Invoice type
                         <select name="invoice_type" required>
                             <option value="stage">Completed stage invoice</option>
                             <option value="task">Completed task invoice</option>
                         </select>
                     </label>
                     <label>Stage (required for stage invoice)
                         <select name="stage_id">
                             <option value="0">No stage selected</option>
                             <?php foreach ($stages as $stage): ?>
                                 <option value="<?= e((int) $stage['id']) ?>">Task #<?= e((int) $stage['task_id']) ?> - <?= e($stage['stage_name']) ?> (<?= e($stage['status']) ?>, <?= e(site_currency_symbol()) ?><?= e(number_format((float) $stage['stage_fee'], 2)) ?>)</option>
                             <?php endforeach; ?>
                         </select>
                     </label>
                     <label>Invoice amount<input type="number" step="0.01" min="0.01" name="amount" required></label>
                     <label>Status
                         <select name="status" required>
                             <option value="issued">Issued</option>
                             <option value="draft">Draft</option>
                             <option value="paid">Paid</option>
                             <option value="cancelled">Cancelled</option>
                         </select>
                     </label>
                     <label>Issued on<input type="date" name="issued_on" value="<?= e(date('Y-m-d')) ?>" required></label>
                     <label>Due on<input type="date" name="due_on"></label>
                     <label class="workspace-span-2">Notes<textarea name="notes"></textarea></label>
                     <div class="workspace-span-2">
                         <button type="submit"><?= icon('invoices') ?>Issue invoice</button>
                     </div>
                 </form>
             </div>
         

             <section class="mbw-card">
                 <div class="mbw-card-head">
                     <h2>Recent Invoices</h2>
                 </div>
                 <div style="overflow-x:auto">
                 <table>
                     <thead>
                         <tr>
                             <th>Invoice</th>
                             <th>Task / Stage</th>
                             <th>Client</th>
                             <th class="is-numeric">Amount</th>
                             <th>Status</th>
                             <th>Issued</th>
                             <th>Actions</th>
                         </tr>
                     </thead>
                     <tbody>
                         <?php if ($invoices === []): ?>
                             <tr><td colspan="7">No invoices issued yet.</td></tr>
                         <?php endif; ?>
                         <?php foreach ($invoices as $invoice): ?>
                             <tr>
                                 <td><?= e($invoice['invoice_no']) ?><br><small><?= e($invoice['invoice_type']) ?> invoice</small></td>
                                 <td><?= e($invoice['task_title']) ?><br><small><?= e($invoice['stage_name'] ?? 'Full task invoice') ?></small></td>
                                 <td><?= e($invoice['organization_name']) ?></td>
                                 <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $invoice['amount'], 2)) ?></td>
                                 <td><span class="mbw-pill tone-<?= e($mbwStatusTone[$invoice['status']] ?? 'gray') ?>"><?= e($invoice['status']) ?></span></td>
                                 <td><?= e($invoice['issued_on']) ?></td>
                                 <td>
                                     <a class="button secondary" href="<?= e(url('admin/invoice.php?action=view&id=' . (int) $invoice['id'])) ?>">Open</a>
                                     <a class="button secondary" href="<?= e(url('admin/export-invoice.php?id=' . (int) $invoice['id'] . '&format=pdf')) ?>" target="_blank" rel="noopener">Preview Template</a>
                                 </td>
                             </tr>
                         <?php endforeach; ?>
                     </tbody>
                 </table>
                 </div>
             </section>
     </section>
    <?php endif; ?>

    <?php if ($view === 'staff'): ?>
    <section class="mbw-card" id="staff">
        <div class="mbw-card-head">
            <h2>Staff Workload</h2>
            <div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('admin/workspace.php?view=teams')) ?>">Manage Teams</a></div>
        </div>
        <p style="color:var(--mbw-muted);">Shows what each active staff member can see in their staff portal: clients assigned to them directly or through a team, and the open/completed tasks under those clients. Assign clients and teams from the <a href="<?= e(url('admin/workspace.php?view=clients')) ?>">Clients</a> and <a href="<?= e(url('admin/workspace.php?view=teams')) ?>">Teams</a> tabs.</p>
        <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>Staff</th>
                    <th class="is-numeric">Teams</th>
                    <th class="is-numeric">Assigned clients</th>
                    <th class="is-numeric">Open tasks</th>
                    <th class="is-numeric">Completed tasks</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($staffWorkloads === []): ?>
                    <tr><td colspan="5">No active staff users found for this company.</td></tr>
                <?php endif; ?>
                <?php foreach ($staffWorkloads as $workload): ?>
                    <tr>
                        <td><?= e($workload['name']) ?><br><small><?= e($workload['email']) ?></small></td>
                        <td class="is-numeric"><?= e((string) $workload['team_count']) ?></td>
                        <td class="is-numeric"><?= e((string) $workload['client_count']) ?></td>
                        <td class="is-numeric"><?= e((string) $workload['open_tasks']) ?></td>
                        <td class="is-numeric"><?= e((string) $workload['completed_tasks']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
