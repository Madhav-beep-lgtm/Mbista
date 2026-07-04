<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_or_admin();

$pageTitle = 'HR';
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

$allowedViews = ['attendance', 'leave', 'timesheets'];
$view = (string) ($_GET['view'] ?? 'attendance');
if (!in_array($view, $allowedViews, true)) {
    $view = 'attendance';
}

$staffUsers = [];
$staffStmt = db()->prepare("SELECT id, name FROM users WHERE role = 'staff' AND status = 'active' AND company_id = :company_id ORDER BY name ASC");
$staffStmt->execute(['company_id' => $companyId]);
$staffUsers = $staffStmt->fetchAll();

$clients = [];
if (table_exists('client_profiles')) {
    $scopedClientIds = $role === 'staff' ? staff_scoped_client_ids($userId, $companyId) : null;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'check_in') {
        $today = date('Y-m-d');
        $existingStmt = db()->prepare('SELECT id, check_in_time FROM attendance WHERE staff_user_id = :staff_user_id AND attendance_date = :attendance_date LIMIT 1');
        $existingStmt->execute(['staff_user_id' => $userId, 'attendance_date' => $today]);
        $existing = $existingStmt->fetch();

        if ($existing && $existing['check_in_time']) {
            flash('error', 'You have already checked in today.');
            redirect('admin/hr.php?view=attendance');
        }

        if ($existing) {
            db()->prepare('UPDATE attendance SET check_in_time = NOW() WHERE id = :id')->execute(['id' => $existing['id']]);
        } else {
            db()->prepare('INSERT INTO attendance (company_id, staff_user_id, attendance_date, check_in_time) VALUES (:company_id, :staff_user_id, :attendance_date, NOW())')
                ->execute(['company_id' => $companyId, 'staff_user_id' => $userId, 'attendance_date' => $today]);
        }
        log_activity('attendance', $userId, 'check_in', 'Staff checked in.', $userId);
        flash('success', 'Checked in.');
        redirect('admin/hr.php?view=attendance');
    }

    if ($action === 'check_out') {
        $today = date('Y-m-d');
        $stmt = db()->prepare('SELECT id, check_out_time FROM attendance WHERE staff_user_id = :staff_user_id AND attendance_date = :attendance_date LIMIT 1');
        $stmt->execute(['staff_user_id' => $userId, 'attendance_date' => $today]);
        $row = $stmt->fetch();

        if (!$row) {
            flash('error', 'Check in before checking out.');
            redirect('admin/hr.php?view=attendance');
        }
        if ($row['check_out_time']) {
            flash('error', 'You have already checked out today.');
            redirect('admin/hr.php?view=attendance');
        }

        db()->prepare('UPDATE attendance SET check_out_time = NOW() WHERE id = :id')->execute(['id' => $row['id']]);
        log_activity('attendance', $userId, 'check_out', 'Staff checked out.', $userId);
        flash('success', 'Checked out.');
        redirect('admin/hr.php?view=attendance');
    }

    if ($action === 'create_attendance_manual' && $role === 'admin') {
        $staffUserId = (int) ($_POST['staff_user_id'] ?? 0);
        $attendanceDate = trim((string) ($_POST['attendance_date'] ?? ''));
        $checkInTime = trim((string) ($_POST['check_in_time'] ?? ''));
        $checkOutTime = trim((string) ($_POST['check_out_time'] ?? ''));
        $workLocation = trim((string) ($_POST['work_location'] ?? ''));
        $remarks = trim((string) ($_POST['remarks'] ?? ''));

        if ($staffUserId <= 0 || $attendanceDate === '') {
            flash('error', 'Staff and date are required.');
            redirect('admin/hr.php?view=attendance');
        }

        $staffCheck = db()->prepare("SELECT id FROM users WHERE id = :id AND role = 'staff' AND company_id = :company_id LIMIT 1");
        $staffCheck->execute(['id' => $staffUserId, 'company_id' => $companyId]);
        if (!$staffCheck->fetch()) {
            flash('error', 'Invalid staff member.');
            redirect('admin/hr.php?view=attendance');
        }

        $stmt = db()->prepare('INSERT INTO attendance (company_id, staff_user_id, attendance_date, check_in_time, check_out_time, work_location, remarks)
            VALUES (:company_id, :staff_user_id, :attendance_date, :check_in_time, :check_out_time, :work_location, :remarks)
            ON DUPLICATE KEY UPDATE check_in_time = VALUES(check_in_time), check_out_time = VALUES(check_out_time), work_location = VALUES(work_location), remarks = VALUES(remarks)');
        $stmt->execute([
            'company_id' => $companyId,
            'staff_user_id' => $staffUserId,
            'attendance_date' => $attendanceDate,
            'check_in_time' => $checkInTime !== '' ? $attendanceDate . ' ' . $checkInTime . ':00' : null,
            'check_out_time' => $checkOutTime !== '' ? $attendanceDate . ' ' . $checkOutTime . ':00' : null,
            'work_location' => $workLocation !== '' ? $workLocation : null,
            'remarks' => $remarks !== '' ? $remarks : null,
        ]);
        log_activity('attendance', $staffUserId, 'manual_entry', 'Admin recorded attendance manually.', $userId);
        flash('success', 'Attendance recorded.');
        redirect('admin/hr.php?view=attendance');
    }

    if ($action === 'request_attendance_correction') {
        $attendanceDate = trim((string) ($_POST['attendance_date'] ?? ''));
        $requestedCheckIn = trim((string) ($_POST['requested_check_in'] ?? ''));
        $requestedCheckOut = trim((string) ($_POST['requested_check_out'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if ($attendanceDate === '' || $reason === '') {
            flash('error', 'Date and reason are required.');
            redirect('admin/hr.php?view=attendance');
        }

        $stmt = db()->prepare('INSERT INTO attendance_correction_requests (company_id, staff_user_id, attendance_date, requested_check_in, requested_check_out, reason)
            VALUES (:company_id, :staff_user_id, :attendance_date, :requested_check_in, :requested_check_out, :reason)');
        $stmt->execute([
            'company_id' => $companyId,
            'staff_user_id' => $userId,
            'attendance_date' => $attendanceDate,
            'requested_check_in' => $requestedCheckIn !== '' ? $attendanceDate . ' ' . $requestedCheckIn . ':00' : null,
            'requested_check_out' => $requestedCheckOut !== '' ? $attendanceDate . ' ' . $requestedCheckOut . ':00' : null,
            'reason' => $reason,
        ]);
        log_activity('attendance_correction', (int) db()->lastInsertId(), 'created', 'Correction request submitted.', $userId);
        flash('success', 'Correction request submitted.');
        redirect('admin/hr.php?view=attendance');
    }

    if ($action === 'review_attendance_correction' && $role === 'admin') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $reviewerRemarks = trim((string) ($_POST['reviewer_remarks'] ?? ''));

        if ($requestId <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
            flash('error', 'Invalid review.');
            redirect('admin/hr.php?view=attendance');
        }

        $reqStmt = db()->prepare('SELECT * FROM attendance_correction_requests WHERE id = :id AND company_id = :company_id LIMIT 1');
        $reqStmt->execute(['id' => $requestId, 'company_id' => $companyId]);
        $correctionRequest = $reqStmt->fetch();
        if (!$correctionRequest) {
            flash('error', 'Request not found.');
            redirect('admin/hr.php?view=attendance');
        }

        if ($decision === 'approved') {
            db()->prepare('INSERT INTO attendance (company_id, staff_user_id, attendance_date, check_in_time, check_out_time)
                VALUES (:company_id, :staff_user_id, :attendance_date, :check_in_time, :check_out_time)
                ON DUPLICATE KEY UPDATE check_in_time = VALUES(check_in_time), check_out_time = VALUES(check_out_time)')
                ->execute([
                    'company_id' => $companyId,
                    'staff_user_id' => $correctionRequest['staff_user_id'],
                    'attendance_date' => $correctionRequest['attendance_date'],
                    'check_in_time' => $correctionRequest['requested_check_in'],
                    'check_out_time' => $correctionRequest['requested_check_out'],
                ]);
        }

        db()->prepare('UPDATE attendance_correction_requests SET status = :status, reviewer_remarks = :reviewer_remarks, reviewed_by = :reviewed_by WHERE id = :id')
            ->execute([
                'status' => $decision,
                'reviewer_remarks' => $reviewerRemarks !== '' ? $reviewerRemarks : null,
                'reviewed_by' => $userId,
                'id' => $requestId,
            ]);
        log_activity('attendance_correction', $requestId, $decision, 'Correction request ' . $decision . '.', $userId);
        flash('success', 'Correction request ' . $decision . '.');
        redirect('admin/hr.php?view=attendance');
    }

    if ($action === 'create_leave_type' && $role === 'admin') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $defaultDays = (int) ($_POST['default_days_per_year'] ?? 0);

        if ($name === '') {
            flash('error', 'Provide a leave type name.');
            redirect('admin/hr.php?view=leave');
        }

        try {
            $stmt = db()->prepare('INSERT INTO leave_types (company_id, name, default_days_per_year, is_system) VALUES (:company_id, :name, :default_days, 0)');
            $stmt->execute(['company_id' => $companyId, 'name' => $name, 'default_days' => max(0, $defaultDays)]);
            flash('success', 'Leave type created.');
        } catch (Throwable $exception) {
            flash('error', 'Could not create leave type. It may already exist.');
        }
        redirect('admin/hr.php?view=leave');
    }

    if ($action === 'toggle_leave_type_active' && $role === 'admin') {
        $typeId = (int) ($_POST['type_id'] ?? 0);
        db()->prepare('UPDATE leave_types SET is_active = NOT is_active WHERE id = :id AND company_id = :company_id')
            ->execute(['id' => $typeId, 'company_id' => $companyId]);
        flash('success', 'Leave type updated.');
        redirect('admin/hr.php?view=leave');
    }

    if ($action === 'apply_leave') {
        $leaveTypeId = (int) ($_POST['leave_type_id'] ?? 0);
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if ($leaveTypeId <= 0 || $startDate === '' || $endDate === '' || $reason === '' || $startDate > $endDate) {
            flash('error', 'Leave type, valid date range, and reason are required.');
            redirect('admin/hr.php?view=leave');
        }

        $typeCheck = db()->prepare('SELECT id FROM leave_types WHERE id = :id AND company_id = :company_id AND is_active = 1 LIMIT 1');
        $typeCheck->execute(['id' => $leaveTypeId, 'company_id' => $companyId]);
        if (!$typeCheck->fetch()) {
            flash('error', 'Selected leave type is not available.');
            redirect('admin/hr.php?view=leave');
        }

        $start = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
        $totalDays = $end->diff($start)->days + 1;

        $uploadResult = ['file_path' => null, 'original_file_name' => null];
        if (isset($_FILES['attachment']) && (int) $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = handle_message_attachment_upload($_FILES['attachment'], 'leave/' . $userId);
            if (!$uploadResult['ok']) {
                flash('error', $uploadResult['error']);
                redirect('admin/hr.php?view=leave');
            }
        }

        $stmt = db()->prepare('INSERT INTO leave_requests (company_id, staff_user_id, leave_type_id, start_date, end_date, total_days, reason, attachment_path, attachment_name)
            VALUES (:company_id, :staff_user_id, :leave_type_id, :start_date, :end_date, :total_days, :reason, :attachment_path, :attachment_name)');
        $stmt->execute([
            'company_id' => $companyId,
            'staff_user_id' => $userId,
            'leave_type_id' => $leaveTypeId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_days' => $totalDays,
            'reason' => $reason,
            'attachment_path' => $uploadResult['file_path'],
            'attachment_name' => $uploadResult['original_file_name'],
        ]);
        log_activity('leave_request', (int) db()->lastInsertId(), 'created', 'Leave requested.', $userId);
        flash('success', 'Leave request submitted.');
        redirect('admin/hr.php?view=leave');
    }

    if ($action === 'review_leave_request' && $role === 'admin') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $reviewerRemarks = trim((string) ($_POST['reviewer_remarks'] ?? ''));

        if ($requestId <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
            flash('error', 'Invalid review.');
            redirect('admin/hr.php?view=leave');
        }

        $reqStmt = db()->prepare('SELECT id FROM leave_requests WHERE id = :id AND company_id = :company_id LIMIT 1');
        $reqStmt->execute(['id' => $requestId, 'company_id' => $companyId]);
        if (!$reqStmt->fetch()) {
            flash('error', 'Request not found.');
            redirect('admin/hr.php?view=leave');
        }

        db()->prepare('UPDATE leave_requests SET status = :status, reviewer_remarks = :reviewer_remarks, reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE id = :id')
            ->execute([
                'status' => $decision,
                'reviewer_remarks' => $reviewerRemarks !== '' ? $reviewerRemarks : null,
                'reviewed_by' => $userId,
                'id' => $requestId,
            ]);
        log_activity('leave_request', $requestId, $decision, 'Leave request ' . $decision . '.', $userId);
        flash('success', 'Leave request ' . $decision . '.');
        redirect('admin/hr.php?view=leave');
    }

    if ($action === 'create_timesheet_entry') {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $entryDate = trim((string) ($_POST['entry_date'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $startTime = trim((string) ($_POST['start_time'] ?? ''));
        $endTime = trim((string) ($_POST['end_time'] ?? ''));
        $isBillable = isset($_POST['is_billable']) ? 1 : 0;
        $workLocation = trim((string) ($_POST['work_location'] ?? ''));

        $clientIdsAllowed = array_map(static fn (array $c): int => (int) $c['id'], $clients);

        if ($entryDate === '' || $description === '' || $startTime === '' || $endTime === '' || $startTime >= $endTime) {
            flash('error', 'Date, description, and a valid start/end time are required.');
            redirect('admin/hr.php?view=timesheets');
        }
        if ($clientId > 0 && !in_array($clientId, $clientIdsAllowed, true)) {
            flash('error', 'Selected client is not in your scope.');
            redirect('admin/hr.php?view=timesheets');
        }

        $start = DateTimeImmutable::createFromFormat('H:i', $startTime);
        $end = DateTimeImmutable::createFromFormat('H:i', $endTime);
        $totalHours = ($start && $end) ? round(($end->getTimestamp() - $start->getTimestamp()) / 3600, 2) : 0;

        $stmt = db()->prepare('INSERT INTO timesheet_entries (company_id, staff_user_id, client_id, task_id, entry_date, description, start_time, end_time, total_hours, is_billable, work_location, status)
            VALUES (:company_id, :staff_user_id, :client_id, :task_id, :entry_date, :description, :start_time, :end_time, :total_hours, :is_billable, :work_location, :status)');
        $stmt->execute([
            'company_id' => $companyId,
            'staff_user_id' => $userId,
            'client_id' => $clientId > 0 ? $clientId : null,
            'task_id' => $taskId > 0 ? $taskId : null,
            'entry_date' => $entryDate,
            'description' => $description,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'total_hours' => $totalHours,
            'is_billable' => $isBillable,
            'work_location' => $workLocation !== '' ? $workLocation : null,
            'status' => 'draft',
        ]);
        flash('success', 'Timesheet entry saved as draft.');
        redirect('admin/hr.php?view=timesheets');
    }

    if ($action === 'delete_timesheet_entry') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        db()->prepare("DELETE FROM timesheet_entries WHERE id = :id AND staff_user_id = :staff_user_id AND status = 'draft'")
            ->execute(['id' => $entryId, 'staff_user_id' => $userId]);
        flash('success', 'Draft entry deleted.');
        redirect('admin/hr.php?view=timesheets');
    }

    if ($action === 'submit_draft_timesheets') {
        db()->prepare("UPDATE timesheet_entries SET status = 'submitted' WHERE staff_user_id = :staff_user_id AND status = 'draft'")
            ->execute(['staff_user_id' => $userId]);
        log_activity('timesheet', $userId, 'submitted', 'Draft timesheet entries submitted.', $userId);
        flash('success', 'Draft entries submitted for approval.');
        redirect('admin/hr.php?view=timesheets');
    }

    if ($action === 'review_timesheet_entry' && $role === 'admin') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $reviewerRemarks = trim((string) ($_POST['reviewer_remarks'] ?? ''));

        if ($entryId <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
            flash('error', 'Invalid review.');
            redirect('admin/hr.php?view=timesheets');
        }

        $entryStmt = db()->prepare("SELECT id FROM timesheet_entries WHERE id = :id AND company_id = :company_id AND status = 'submitted' LIMIT 1");
        $entryStmt->execute(['id' => $entryId, 'company_id' => $companyId]);
        if (!$entryStmt->fetch()) {
            flash('error', 'Entry not found or not pending review.');
            redirect('admin/hr.php?view=timesheets');
        }

        db()->prepare('UPDATE timesheet_entries SET status = :status, reviewer_remarks = :reviewer_remarks, reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE id = :id')
            ->execute([
                'status' => $decision,
                'reviewer_remarks' => $reviewerRemarks !== '' ? $reviewerRemarks : null,
                'reviewed_by' => $userId,
                'id' => $entryId,
            ]);
        log_activity('timesheet_entry', $entryId, $decision, 'Timesheet entry ' . $decision . '.', $userId);
        flash('success', 'Timesheet entry ' . $decision . '.');
        redirect('admin/hr.php?view=timesheets');
    }

    flash('error', 'Unsupported action.');
    redirect('admin/hr.php?view=' . $view);
}

$myAttendance = [];
$companyAttendance = [];
$myCorrectionRequests = [];
$pendingCorrectionRequests = [];
$leaveTypes = [];
$myLeaveRequests = [];
$allLeaveRequests = [];
$leaveBalances = [];
$myTimesheetEntries = [];
$submittedTimesheetEntries = [];
$utilizationSummary = [];
$taskOptions = [];

if ($view === 'attendance') {
    if ($role === 'admin') {
        $stmt = db()->prepare('SELECT a.*, u.name AS staff_name FROM attendance a INNER JOIN users u ON u.id = a.staff_user_id WHERE a.company_id = :company_id ORDER BY a.attendance_date DESC LIMIT 100');
        $stmt->execute(['company_id' => $companyId]);
        $companyAttendance = $stmt->fetchAll();

        $pendingStmt = db()->prepare("SELECT acr.*, u.name AS staff_name FROM attendance_correction_requests acr INNER JOIN users u ON u.id = acr.staff_user_id WHERE acr.company_id = :company_id ORDER BY acr.created_at DESC LIMIT 100");
        $pendingStmt->execute(['company_id' => $companyId]);
        $pendingCorrectionRequests = $pendingStmt->fetchAll();
    } else {
        $stmt = db()->prepare('SELECT * FROM attendance WHERE staff_user_id = :staff_user_id ORDER BY attendance_date DESC LIMIT 30');
        $stmt->execute(['staff_user_id' => $userId]);
        $myAttendance = $stmt->fetchAll();

        $corrStmt = db()->prepare('SELECT * FROM attendance_correction_requests WHERE staff_user_id = :staff_user_id ORDER BY created_at DESC LIMIT 20');
        $corrStmt->execute(['staff_user_id' => $userId]);
        $myCorrectionRequests = $corrStmt->fetchAll();
    }
}

if ($view === 'leave') {
    $typeStmt = db()->prepare('SELECT * FROM leave_types WHERE company_id = :company_id ORDER BY name ASC');
    $typeStmt->execute(['company_id' => $companyId]);
    $leaveTypes = $typeStmt->fetchAll();

    if ($role === 'admin') {
        $allStmt = db()->prepare('SELECT lr.*, u.name AS staff_name, lt.name AS leave_type_name
            FROM leave_requests lr
            INNER JOIN users u ON u.id = lr.staff_user_id
            INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
            WHERE lr.company_id = :company_id ORDER BY lr.created_at DESC LIMIT 150');
        $allStmt->execute(['company_id' => $companyId]);
        $allLeaveRequests = $allStmt->fetchAll();
    } else {
        foreach (array_filter($leaveTypes, static fn (array $t): bool => (int) $t['is_active'] === 1) as $type) {
            $leaveBalances[] = [
                'name' => $type['name'],
                'default_days' => (int) $type['default_days_per_year'],
                'remaining' => leave_balance_remaining($userId, (int) $type['id'], (int) $type['default_days_per_year']),
            ];
        }

        $myStmt = db()->prepare('SELECT lr.*, lt.name AS leave_type_name FROM leave_requests lr INNER JOIN leave_types lt ON lt.id = lr.leave_type_id WHERE lr.staff_user_id = :staff_user_id ORDER BY lr.created_at DESC LIMIT 30');
        $myStmt->execute(['staff_user_id' => $userId]);
        $myLeaveRequests = $myStmt->fetchAll();
    }
}

if ($view === 'timesheets') {
    if (table_exists('client_tasks')) {
        $taskStmt = db()->prepare('SELECT t.id, t.title, cp.organization_name FROM client_tasks t INNER JOIN client_profiles cp ON cp.id = t.client_id WHERE t.company_id = :company_id ORDER BY t.created_at DESC LIMIT 200');
        $taskStmt->execute(['company_id' => $companyId]);
        $taskOptions = $taskStmt->fetchAll();
    }

    if ($role === 'admin') {
        $subStmt = db()->prepare("SELECT te.*, u.name AS staff_name, cp.organization_name, t.title AS task_title
            FROM timesheet_entries te
            INNER JOIN users u ON u.id = te.staff_user_id
            LEFT JOIN client_profiles cp ON cp.id = te.client_id
            LEFT JOIN client_tasks t ON t.id = te.task_id
            WHERE te.company_id = :company_id AND te.status = 'submitted'
            ORDER BY te.entry_date DESC LIMIT 150");
        $subStmt->execute(['company_id' => $companyId]);
        $submittedTimesheetEntries = $subStmt->fetchAll();

        $utilStmt = db()->prepare("SELECT u.name AS staff_name,
                SUM(te.total_hours) AS total_hours,
                SUM(CASE WHEN te.is_billable = 1 THEN te.total_hours ELSE 0 END) AS billable_hours
            FROM timesheet_entries te
            INNER JOIN users u ON u.id = te.staff_user_id
            WHERE te.company_id = :company_id AND te.status = 'approved'
            GROUP BY te.staff_user_id, u.name
            ORDER BY total_hours DESC");
        $utilStmt->execute(['company_id' => $companyId]);
        $utilizationSummary = $utilStmt->fetchAll();
    } else {
        $myStmt = db()->prepare('SELECT te.*, cp.organization_name, t.title AS task_title FROM timesheet_entries te
            LEFT JOIN client_profiles cp ON cp.id = te.client_id
            LEFT JOIN client_tasks t ON t.id = te.task_id
            WHERE te.staff_user_id = :staff_user_id ORDER BY te.entry_date DESC LIMIT 50');
        $myStmt->execute(['staff_user_id' => $userId]);
        $myTimesheetEntries = $myStmt->fetchAll();
    }
}

$pageTitle = match ($view) {
    'leave' => 'Leave',
    'timesheets' => 'Timesheets',
    default => 'Attendance',
};

include __DIR__ . '/../../app/views/partials/' . ($role === 'admin' ? 'admin_header' : 'staff_header') . '.php';
?>
<div class="actions workspace-module-bar" style="margin-bottom: 16px;">
    <a class="button<?= $view === 'attendance' ? '' : ' secondary' ?>" href="<?= e(url('admin/hr.php?view=attendance')) ?>"><?= icon('attendance') ?>Attendance</a>
    <a class="button<?= $view === 'leave' ? '' : ' secondary' ?>" href="<?= e(url('admin/hr.php?view=leave')) ?>"><?= icon('leave') ?>Leave</a>
    <a class="button<?= $view === 'timesheets' ? '' : ' secondary' ?>" href="<?= e(url('admin/hr.php?view=timesheets')) ?>"><?= icon('timesheets') ?>Timesheets</a>
</div>

<?php if ($view === 'attendance'): ?>
    <?php if ($role === 'staff'): ?>
        <?php
            $todayRow = null;
            foreach ($myAttendance as $row) {
                if ($row['attendance_date'] === date('Y-m-d')) {
                    $todayRow = $row;
                    break;
                }
            }
        ?>
        <div class="table-card">
            <h2><?= icon('attendance') ?>Today</h2>
            <div class="actions">
                <?php if (!$todayRow || !$todayRow['check_in_time']): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="check_in">
                        <button type="submit"><?= icon('attendance') ?>Check in</button>
                    </form>
                <?php elseif (!$todayRow['check_out_time']): ?>
                    <p>Checked in at <?= e($todayRow['check_in_time']) ?></p>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="check_out">
                        <button type="submit"><?= icon('attendance') ?>Check out</button>
                    </form>
                <?php else: ?>
                    <p>Checked in <?= e($todayRow['check_in_time']) ?>, checked out <?= e($todayRow['check_out_time']) ?> (<?= e((string) attendance_hours_worked($todayRow['check_in_time'], $todayRow['check_out_time'])) ?> hrs)</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-card">
            <h3>My recent attendance</h3>
            <table>
                <thead>
                    <tr><th>Date</th><th>Check in</th><th>Check out</th><th>Hours</th><th>Flags</th></tr>
                </thead>
                <tbody>
                    <?php if ($myAttendance === []): ?>
                        <tr><td colspan="5">No attendance recorded yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($myAttendance as $row): ?>
                        <tr>
                            <td><?= e($row['attendance_date']) ?></td>
                            <td><?= e($row['check_in_time'] ?? '-') ?></td>
                            <td><?= e($row['check_out_time'] ?? '-') ?></td>
                            <td><?= e((string) attendance_hours_worked($row['check_in_time'], $row['check_out_time'])) ?></td>
                            <td>
                                <?php if (attendance_is_late($row['check_in_time'])): ?><span class="tag">Late</span><?php endif; ?>
                                <?php if (attendance_is_early_departure($row['check_out_time'])): ?><span class="tag">Early departure</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <details class="feature-disclosure">
            <summary>
                <span><strong><?= icon('attendance') ?>Request correction</strong><small>Ask admin to fix a past attendance record.</small></span>
                <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
            </summary>
            <form method="post" class="workspace-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="request_attendance_correction">
                <label>Date<input type="date" name="attendance_date" required></label>
                <label>Correct check-in<input type="time" name="requested_check_in"></label>
                <label>Correct check-out<input type="time" name="requested_check_out"></label>
                <label class="workspace-span-2">Reason<textarea name="reason" required></textarea></label>
                <div class="workspace-span-2"><button type="submit"><?= icon('attendance') ?>Submit request</button></div>
            </form>
        </details>

        <div class="table-card">
            <h3>My correction requests</h3>
            <table>
                <thead><tr><th>Date</th><th>Requested</th><th>Reason</th><th>Status</th><th>Remarks</th></tr></thead>
                <tbody>
                    <?php if ($myCorrectionRequests === []): ?>
                        <tr><td colspan="5">No correction requests yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($myCorrectionRequests as $row): ?>
                        <tr>
                            <td><?= e($row['attendance_date']) ?></td>
                            <td><?= e($row['requested_check_in'] ?? '-') ?> / <?= e($row['requested_check_out'] ?? '-') ?></td>
                            <td><?= e($row['reason']) ?></td>
                            <td><span class="tag"><?= e($row['status']) ?></span></td>
                            <td><?= e($row['reviewer_remarks'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="table-card">
            <h2><?= icon('attendance') ?>Company attendance</h2>
            <details class="feature-disclosure">
                <summary>
                    <span><strong><?= icon('attendance') ?>Manual entry</strong><small>Record attendance for a staff member.</small></span>
                    <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
                </summary>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_attendance_manual">
                    <label>Staff
                        <select name="staff_user_id" required>
                            <option value="">Select staff</option>
                            <?php foreach ($staffUsers as $staffUser): ?>
                                <option value="<?= e((int) $staffUser['id']) ?>"><?= e($staffUser['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Date<input type="date" name="attendance_date" required></label>
                    <label>Check-in<input type="time" name="check_in_time"></label>
                    <label>Check-out<input type="time" name="check_out_time"></label>
                    <label>Work location<input type="text" name="work_location"></label>
                    <label class="workspace-span-2">Remarks<textarea name="remarks"></textarea></label>
                    <div class="workspace-span-2"><button type="submit"><?= icon('attendance') ?>Save</button></div>
                </form>
            </details>

            <div class="table-card">
                <h3>Recent records</h3>
                <table>
                    <thead><tr><th>Staff</th><th>Date</th><th>Check in</th><th>Check out</th><th>Hours</th><th>Flags</th></tr></thead>
                    <tbody>
                        <?php if ($companyAttendance === []): ?>
                            <tr><td colspan="6">No attendance recorded yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($companyAttendance as $row): ?>
                            <tr>
                                <td><?= e($row['staff_name']) ?></td>
                                <td><?= e($row['attendance_date']) ?></td>
                                <td><?= e($row['check_in_time'] ?? '-') ?></td>
                                <td><?= e($row['check_out_time'] ?? '-') ?></td>
                                <td><?= e((string) attendance_hours_worked($row['check_in_time'], $row['check_out_time'])) ?></td>
                                <td>
                                    <?php if (attendance_is_late($row['check_in_time'])): ?><span class="tag">Late</span><?php endif; ?>
                                    <?php if (attendance_is_early_departure($row['check_out_time'])): ?><span class="tag">Early</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-card">
                <h3>Correction requests</h3>
                <table>
                    <thead><tr><th>Staff</th><th>Date</th><th>Requested</th><th>Reason</th><th>Status</th><th>Review</th></tr></thead>
                    <tbody>
                        <?php if ($pendingCorrectionRequests === []): ?>
                            <tr><td colspan="6">No correction requests.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($pendingCorrectionRequests as $row): ?>
                            <tr>
                                <td><?= e($row['staff_name']) ?></td>
                                <td><?= e($row['attendance_date']) ?></td>
                                <td><?= e($row['requested_check_in'] ?? '-') ?> / <?= e($row['requested_check_out'] ?? '-') ?></td>
                                <td><?= e($row['reason']) ?></td>
                                <td><span class="tag"><?= e($row['status']) ?></span></td>
                                <td>
                                    <?php if ($row['status'] === 'pending'): ?>
                                        <form method="post" class="inline-action-form">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="review_attendance_correction">
                                            <input type="hidden" name="request_id" value="<?= e((int) $row['id']) ?>">
                                            <input type="hidden" name="decision" value="approved">
                                            <button type="submit" class="button">Approve</button>
                                        </form>
                                        <form method="post" class="inline-action-form">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="review_attendance_correction">
                                            <input type="hidden" name="request_id" value="<?= e((int) $row['id']) ?>">
                                            <input type="hidden" name="decision" value="rejected">
                                            <input type="text" name="reviewer_remarks" placeholder="Remarks">
                                            <button type="submit" class="button secondary">Reject</button>
                                        </form>
                                    <?php else: ?>
                                        <?= e($row['reviewer_remarks'] ?? '-') ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($view === 'leave'): ?>
    <?php if ($role === 'admin'): ?>
        <div class="table-card">
            <h2><?= icon('leave') ?>Leave types</h2>
            <details class="feature-disclosure">
                <summary>
                    <span><strong><?= icon('leave') ?>Create type</strong><small>Add a leave type for this company.</small></span>
                    <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
                </summary>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_leave_type">
                    <label>Name<input type="text" name="name" maxlength="100" required></label>
                    <label>Default days/year<input type="number" name="default_days_per_year" min="0" value="0" required></label>
                    <div><button type="submit"><?= icon('leave') ?>Create</button></div>
                </form>
            </details>
            <div class="table-card">
                <table>
                    <thead><tr><th>Name</th><th>Days/year</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($leaveTypes as $type): ?>
                            <tr>
                                <td><?= e($type['name']) ?></td>
                                <td><?= e((string) $type['default_days_per_year']) ?></td>
                                <td><?= (int) $type['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                                <td>
                                    <form method="post" class="inline-action-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle_leave_type_active">
                                        <input type="hidden" name="type_id" value="<?= e((int) $type['id']) ?>">
                                        <button type="submit" class="button secondary"><?= (int) $type['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-card">
            <h2><?= icon('leave') ?>Leave requests</h2>
            <table>
                <thead><tr><th>Staff</th><th>Type</th><th>Dates</th><th>Days</th><th>Reason</th><th>Status</th><th>Review</th></tr></thead>
                <tbody>
                    <?php if ($allLeaveRequests === []): ?>
                        <tr><td colspan="7">No leave requests yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($allLeaveRequests as $row): ?>
                        <tr>
                            <td><?= e($row['staff_name']) ?></td>
                            <td><?= e($row['leave_type_name']) ?></td>
                            <td><?= e($row['start_date']) ?> to <?= e($row['end_date']) ?></td>
                            <td><?= e((string) $row['total_days']) ?></td>
                            <td><?= e($row['reason']) ?><?php if ($row['attachment_path']): ?><br><a href="<?= e(url('attachment-download.php?type=leave&id=' . (int) $row['id'])) ?>">📎 <?= e((string) $row['attachment_name']) ?></a><?php endif; ?></td>
                            <td><span class="tag"><?= e($row['status']) ?></span></td>
                            <td>
                                <?php if ($row['status'] === 'pending'): ?>
                                    <form method="post" class="inline-action-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="review_leave_request">
                                        <input type="hidden" name="request_id" value="<?= e((int) $row['id']) ?>">
                                        <input type="hidden" name="decision" value="approved">
                                        <button type="submit" class="button">Approve</button>
                                    </form>
                                    <form method="post" class="inline-action-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="review_leave_request">
                                        <input type="hidden" name="request_id" value="<?= e((int) $row['id']) ?>">
                                        <input type="hidden" name="decision" value="rejected">
                                        <input type="text" name="reviewer_remarks" placeholder="Remarks">
                                        <button type="submit" class="button secondary">Reject</button>
                                    </form>
                                <?php else: ?>
                                    <?= e($row['reviewer_remarks'] ?? '-') ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="table-card">
            <h2><?= icon('leave') ?>My leave balance</h2>
            <div class="admin-stats">
                <?php foreach ($leaveBalances as $balance): ?>
                    <div class="card"><span class="stat-icon"><?= icon('leave') ?></span><strong><?= e((string) $balance['remaining']) ?></strong><p><?= e($balance['name']) ?> (of <?= e((string) $balance['default_days']) ?>)</p></div>
                <?php endforeach; ?>
            </div>
        </div>

        <details class="feature-disclosure">
            <summary>
                <span><strong><?= icon('leave') ?>Apply for leave</strong><small>Submit a leave request for approval.</small></span>
                <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
            </summary>
            <form method="post" enctype="multipart/form-data" class="workspace-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="apply_leave">
                <label>Leave type
                    <select name="leave_type_id" required>
                        <option value="">Select type</option>
                        <?php foreach ($leaveTypes as $type): ?>
                            <?php if ((int) $type['is_active'] === 1): ?>
                                <option value="<?= e((int) $type['id']) ?>"><?= e($type['name']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Start date<input type="date" name="start_date" required></label>
                <label>End date<input type="date" name="end_date" required></label>
                <label class="workspace-span-2">Reason<textarea name="reason" required></textarea></label>
                <label class="workspace-span-2">Supporting document (optional)<input type="file" name="attachment"></label>
                <div class="workspace-span-2"><button type="submit"><?= icon('leave') ?>Submit</button></div>
            </form>
        </details>

        <div class="table-card">
            <h3>My leave requests</h3>
            <table>
                <thead><tr><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Remarks</th></tr></thead>
                <tbody>
                    <?php if ($myLeaveRequests === []): ?>
                        <tr><td colspan="5">No leave requests yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($myLeaveRequests as $row): ?>
                        <tr>
                            <td><?= e($row['leave_type_name']) ?></td>
                            <td><?= e($row['start_date']) ?> to <?= e($row['end_date']) ?></td>
                            <td><?= e((string) $row['total_days']) ?></td>
                            <td><span class="tag"><?= e($row['status']) ?></span></td>
                            <td><?= e($row['reviewer_remarks'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($view === 'timesheets'): ?>
    <?php if ($role === 'admin'): ?>
        <div class="table-card">
            <h2><?= icon('timesheets') ?>Timesheets pending approval</h2>
            <table>
                <thead><tr><th>Staff</th><th>Date</th><th>Client</th><th>Task</th><th>Description</th><th>Hours</th><th>Billable</th><th>Review</th></tr></thead>
                <tbody>
                    <?php if ($submittedTimesheetEntries === []): ?>
                        <tr><td colspan="8">No entries pending approval.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($submittedTimesheetEntries as $entry): ?>
                        <tr>
                            <td><?= e($entry['staff_name']) ?></td>
                            <td><?= e($entry['entry_date']) ?></td>
                            <td><?= e($entry['organization_name'] ?? '-') ?></td>
                            <td><?= e($entry['task_title'] ?? '-') ?></td>
                            <td><?= e($entry['description']) ?></td>
                            <td><?= e((string) $entry['total_hours']) ?></td>
                            <td><?= (int) $entry['is_billable'] === 1 ? 'Yes' : 'No' ?></td>
                            <td>
                                <form method="post" class="inline-action-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="review_timesheet_entry">
                                    <input type="hidden" name="entry_id" value="<?= e((int) $entry['id']) ?>">
                                    <input type="hidden" name="decision" value="approved">
                                    <button type="submit" class="button">Approve</button>
                                </form>
                                <form method="post" class="inline-action-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="review_timesheet_entry">
                                    <input type="hidden" name="entry_id" value="<?= e((int) $entry['id']) ?>">
                                    <input type="hidden" name="decision" value="rejected">
                                    <input type="text" name="reviewer_remarks" placeholder="Remarks">
                                    <button type="submit" class="button secondary">Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="table-card">
            <h3>Staff utilization (approved entries)</h3>
            <table>
                <thead><tr><th>Staff</th><th>Total hours</th><th>Billable hours</th><th>Billable %</th></tr></thead>
                <tbody>
                    <?php if ($utilizationSummary === []): ?>
                        <tr><td colspan="4">No approved entries yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($utilizationSummary as $row): ?>
                        <?php $pct = (float) $row['total_hours'] > 0 ? round(((float) $row['billable_hours'] / (float) $row['total_hours']) * 100, 1) : 0; ?>
                        <tr>
                            <td><?= e($row['staff_name']) ?></td>
                            <td><?= e((string) $row['total_hours']) ?></td>
                            <td><?= e((string) $row['billable_hours']) ?></td>
                            <td><?= e((string) $pct) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <details class="feature-disclosure">
            <summary>
                <span><strong><?= icon('timesheets') ?>Add entry</strong><small>Record time worked (saved as draft).</small></span>
                <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
            </summary>
            <form method="post" class="workspace-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_timesheet_entry">
                <label>Date<input type="date" name="entry_date" required></label>
                <label>Client (optional)
                    <select name="client_id">
                        <option value="0">No client</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= e((int) $client['id']) ?>"><?= e($client['organization_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Task (optional)
                    <select name="task_id">
                        <option value="0">No task</option>
                        <?php foreach ($taskOptions as $task): ?>
                            <option value="<?= e((int) $task['id']) ?>"><?= e($task['title']) ?> (<?= e($task['organization_name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="workspace-span-2">Description<input type="text" name="description" maxlength="255" required></label>
                <label>Start time<input type="time" name="start_time" required></label>
                <label>End time<input type="time" name="end_time" required></label>
                <label>Work location<input type="text" name="work_location"></label>
                <label class="checkbox-line"><input type="checkbox" name="is_billable" value="1" checked> Billable</label>
                <div class="workspace-span-2"><button type="submit"><?= icon('timesheets') ?>Save as draft</button></div>
            </form>
        </details>

        <div class="table-card">
            <h3>My timesheet entries</h3>
            <form method="post" style="margin-bottom:12px;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="submit_draft_timesheets">
                <button type="submit"><?= icon('timesheets') ?>Submit all drafts for approval</button>
            </form>
            <table>
                <thead><tr><th>Date</th><th>Client</th><th>Description</th><th>Hours</th><th>Billable</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if ($myTimesheetEntries === []): ?>
                        <tr><td colspan="7">No timesheet entries yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($myTimesheetEntries as $entry): ?>
                        <tr>
                            <td><?= e($entry['entry_date']) ?></td>
                            <td><?= e($entry['organization_name'] ?? '-') ?></td>
                            <td><?= e($entry['description']) ?></td>
                            <td><?= e((string) $entry['total_hours']) ?></td>
                            <td><?= (int) $entry['is_billable'] === 1 ? 'Yes' : 'No' ?></td>
                            <td><span class="tag"><?= e($entry['status']) ?></span><?php if ($entry['reviewer_remarks']): ?><br><small><?= e($entry['reviewer_remarks']) ?></small><?php endif; ?></td>
                            <td>
                                <?php if ($entry['status'] === 'draft'): ?>
                                    <form method="post" class="inline-action-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_timesheet_entry">
                                        <input type="hidden" name="entry_id" value="<?= e((int) $entry['id']) ?>">
                                        <button type="submit" class="button secondary">Delete</button>
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
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/' . ($role === 'admin' ? 'admin_footer' : 'staff_footer') . '.php'; ?>
