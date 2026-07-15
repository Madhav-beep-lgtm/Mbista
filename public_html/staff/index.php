<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_or_admin();
$user = current_user();
$staffId = (int) $user['id'];
$companyId = (int) ($user['company_id'] ?? 0);

$allowedViews = ['home', 'clients', 'tasks'];
$view = (string) ($_GET['view'] ?? 'home');
if (!in_array($view, $allowedViews, true)) {
    $view = 'home';
}
$allowedTaskStatus = ['new', 'in_progress', 'on_hold', 'completed', 'cancelled', 'terminated'];
$hasMultiAssignees = table_exists('client_task_assignees');
$hasProgressColumn = column_exists('client_tasks', 'progress_percent');

$myTeamIds = [];
if (table_exists('team_members')) {
    $stmt = db()->prepare('SELECT team_id FROM team_members WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $staffId]);
    $myTeamIds = array_map('intval', array_column($stmt->fetchAll(), 'team_id'));
}

$myClients = [];
$myClientIds = [];
if (table_exists('client_profiles')) {
    // Includes clients reachable through task, stage, or team task assignment.
    $myClientIds = staff_scoped_client_ids($staffId, $companyId);

    if ($myClientIds !== []) {
        $clientPlaceholders = implode(',', array_fill(0, count($myClientIds), '?'));
        $stmt = db()->prepare("SELECT cp.*, u.name, u.email
            FROM client_profiles cp
            INNER JOIN users u ON u.id = cp.user_id
            WHERE cp.id IN ($clientPlaceholders)
            ORDER BY cp.created_at DESC");
        $stmt->execute($myClientIds);
        $myClients = $stmt->fetchAll();
    }
}

$activeClients = count(array_filter($myClients, static fn (array $c): bool => (int) $c['is_active'] === 1));

$myContracts = [];
$activeContracts = 0;
if ($myClientIds !== [] && table_exists('service_contracts')) {
    $placeholders = implode(',', array_fill(0, count($myClientIds), '?'));
    $stmt = db()->prepare("SELECT sc.*, cp.organization_name FROM service_contracts sc
        INNER JOIN client_profiles cp ON cp.id = sc.client_id
        WHERE sc.client_id IN ($placeholders) ORDER BY sc.created_at DESC");
    $stmt->execute($myClientIds);
    $myContracts = $stmt->fetchAll();
    $activeContracts = count(array_filter($myContracts, static fn (array $c): bool => $c['status'] === 'active'));
}

$myTasks = [];
$stagesByTask = [];
$openTasks = 0;
$completedTasks = 0;
if (table_exists('client_tasks')) {
    // Task-wise/stage-wise assignment (with team fallback), not client-wise.
    $hasTaskAssignment = column_exists('client_tasks', 'assigned_staff_user_id');
    $hasStageAssignment = column_exists('task_stages', 'assigned_staff_user_id');

    $taskWhere = 't.company_id = ? AND (';
    $taskBindings = [$companyId];
    if ($hasTaskAssignment) {
        $taskWhere .= 't.assigned_staff_user_id = ?';
        $taskBindings[] = $staffId;
    } else {
        $taskWhere .= '1 = 0';
    }
    if ($myTeamIds !== []) {
        $teamPlaceholders = implode(',', array_fill(0, count($myTeamIds), '?'));
        $taskWhere .= " OR t.team_id IN ($teamPlaceholders)";
        $taskBindings = array_merge($taskBindings, $myTeamIds);
    }
    if ($hasStageAssignment) {
        $taskWhere .= ' OR EXISTS (SELECT 1 FROM task_stages sts WHERE sts.task_id = t.id AND sts.assigned_staff_user_id = ?)';
        $taskBindings[] = $staffId;
    }
    if ($hasMultiAssignees) {
        $taskWhere .= ' OR EXISTS (SELECT 1 FROM client_task_assignees cta WHERE cta.task_id = t.id AND cta.user_id = ?)';
        $taskBindings[] = $staffId;
    }
    $taskWhere .= ')';

    $assignedNameSelect = $hasTaskAssignment ? ', au.name AS assigned_staff_name' : ', NULL AS assigned_staff_name';
    $assignedNameJoin = $hasTaskAssignment ? ' LEFT JOIN users au ON au.id = t.assigned_staff_user_id' : '';
    $stmt = db()->prepare("SELECT t.*, cp.organization_name, tm.name AS team_name, sc.contract_no{$assignedNameSelect}
        FROM client_tasks t
        INNER JOIN client_profiles cp ON cp.id = t.client_id
        LEFT JOIN teams tm ON tm.id = t.team_id
        LEFT JOIN service_contracts sc ON sc.id = t.contract_id{$assignedNameJoin}
        WHERE $taskWhere
        ORDER BY t.created_at DESC");
    $stmt->execute($taskBindings);
    $myTasks = $stmt->fetchAll();
    $openTasks = count(array_filter($myTasks, static fn (array $t): bool => in_array($t['status'], ['new', 'in_progress', 'on_hold'], true)));
    $completedTasks = count(array_filter($myTasks, static fn (array $t): bool => $t['status'] === 'completed'));

    if ($myTasks !== [] && table_exists('task_stages')) {
        $taskIds = array_map('intval', array_column($myTasks, 'id'));
        $taskPlaceholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = db()->prepare("SELECT * FROM task_stages WHERE task_id IN ($taskPlaceholders) ORDER BY task_id ASC, sequence_no ASC");
        $stmt->execute($taskIds);
        foreach ($stmt->fetchAll() as $stage) {
            $stagesByTask[(int) $stage['task_id']][] = $stage;
        }
    }
}

$taskAssigneesMap = $hasMultiAssignees && $myTasks !== []
    ? task_assignees_by_task(array_map('intval', array_column($myTasks, 'id')))
    : [];

// Handoffs addressed to this staff member: the progress update recorded when
// they replaced someone on a task.
$myHandoffs = [];
if (table_exists('task_assignment_events')) {
    $handoffStmt = db()->prepare("SELECT tae.*, t.title AS task_title, fu.name AS from_user_name
        FROM task_assignment_events tae
        INNER JOIN client_tasks t ON t.id = tae.task_id
        LEFT JOIN users fu ON fu.id = tae.from_user_id
        WHERE tae.to_user_id = :staff_id AND tae.event_type = 'replaced' AND t.company_id = :company_id
        ORDER BY tae.created_at DESC LIMIT 5");
    $handoffStmt->execute(['staff_id' => $staffId, 'company_id' => $companyId]);
    $myHandoffs = $handoffStmt->fetchAll();
}

$unreadMessageCount = table_exists('message_thread_participants') ? unread_message_thread_count($staffId) : 0;

$weeklyHours = 0;
if (table_exists('timesheet_entries')) {
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $hoursStmt = db()->prepare('SELECT COALESCE(SUM(total_hours), 0) FROM timesheet_entries WHERE staff_user_id = :staff_user_id AND entry_date >= :week_start');
    $hoursStmt->execute(['staff_user_id' => $staffId, 'week_start' => $weekStart]);
    $weeklyHours = (float) $hoursStmt->fetchColumn();
}

$upcomingComplianceCount = 0;
if ($myClientIds !== [] && table_exists('compliance_deadlines')) {
    $placeholders = implode(',', array_fill(0, count($myClientIds), '?'));
    $stmt = db()->prepare("SELECT status, statutory_due_date FROM compliance_deadlines WHERE client_id IN ($placeholders)");
    $stmt->execute($myClientIds);
    foreach ($stmt->fetchAll() as $deadline) {
        if (in_array(compliance_effective_status($deadline), ['upcoming', 'overdue'], true)) {
            $upcomingComplianceCount++;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'complete_stage') {
        $stageId = (int) ($_POST['stage_id'] ?? 0);
        $stmt = db()->prepare('SELECT ts.id, ts.task_id, t.client_id, t.status AS task_status FROM task_stages ts INNER JOIN client_tasks t ON t.id = ts.task_id WHERE ts.id = :id LIMIT 1');
        $stmt->execute(['id' => $stageId]);
        $stageRow = $stmt->fetch();

        if (!$stageRow || !in_array((int) $stageRow['client_id'], $myClientIds, true)) {
            flash('error', 'That stage is not part of your assigned clients.');
            redirect('staff/index.php?view=tasks');
        }
        if (in_array((string) $stageRow['task_status'], ['cancelled', 'terminated'], true)) {
            flash('error', 'This task has been ' . (string) $stageRow['task_status'] . '; its stages can no longer be completed.');
            redirect('staff/index.php?view=tasks');
        }

        $update = db()->prepare("UPDATE task_stages SET status = 'completed', completed_at = NOW() WHERE id = :id");
        $update->execute(['id' => $stageId]);
        log_activity('task_stage', $stageId, 'completed', 'Task stage marked completed from staff portal.', $staffId);
        flash('success', 'Stage marked completed.');
        redirect('staff/index.php?view=tasks');
    }

    if ($action === 'update_task_progress') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $progressRaw = trim((string) ($_POST['progress_percent'] ?? ''));

        if (!column_exists('client_tasks', 'progress_percent')) {
            flash('error', 'Work-progress tracking is not available yet. Ask an admin to run the database repair.');
            redirect('staff/index.php?view=tasks');
        }
        if ($taskId <= 0 || $progressRaw === '' || !is_numeric($progressRaw) || (int) $progressRaw < 0 || (int) $progressRaw > 100) {
            flash('error', 'Work progress must be a number between 0 and 100.');
            redirect('staff/index.php?view=tasks');
        }

        $stmt = db()->prepare('SELECT id, client_id, status, assigned_staff_user_id FROM client_tasks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $taskId]);
        $taskRow = $stmt->fetch();
        if (!$taskRow || !in_array((int) $taskRow['client_id'], $myClientIds, true)) {
            flash('error', 'That task is not part of your assigned clients.');
            redirect('staff/index.php?view=tasks');
        }

        // Progress may only be updated by staff actually assigned to the task
        // (directly, through the multi-assignee list, or via one of its stages).
        $isAssignee = (int) ($taskRow['assigned_staff_user_id'] ?? 0) === $staffId;
        if (!$isAssignee && table_exists('client_task_assignees')) {
            $assigneeCheck = db()->prepare('SELECT id FROM client_task_assignees WHERE task_id = :task_id AND user_id = :user_id LIMIT 1');
            $assigneeCheck->execute(['task_id' => $taskId, 'user_id' => $staffId]);
            $isAssignee = (bool) $assigneeCheck->fetch();
        }
        if (!$isAssignee && column_exists('task_stages', 'assigned_staff_user_id')) {
            $stageCheck = db()->prepare('SELECT id FROM task_stages WHERE task_id = :task_id AND assigned_staff_user_id = :user_id LIMIT 1');
            $stageCheck->execute(['task_id' => $taskId, 'user_id' => $staffId]);
            $isAssignee = (bool) $stageCheck->fetch();
        }
        if (!$isAssignee) {
            flash('error', 'Only staff assigned to this task can update its work progress.');
            redirect('staff/index.php?view=tasks');
        }
        if (in_array((string) $taskRow['status'], ['completed', 'cancelled', 'terminated'], true)) {
            flash('error', 'Progress of a ' . str_replace('_', ' ', (string) $taskRow['status']) . ' task can no longer be changed.');
            redirect('staff/index.php?view=tasks');
        }

        $progressPercent = (int) $progressRaw;
        $statusSql = $progressPercent > 0 && (string) $taskRow['status'] === 'new' ? ", status = 'in_progress'" : '';
        db()->prepare("UPDATE client_tasks SET progress_percent = :progress{$statusSql} WHERE id = :id")
            ->execute(['progress' => $progressPercent, 'id' => $taskId]);

        log_activity('client_task', $taskId, 'progress_updated', 'Work progress set to ' . $progressPercent . '% from staff portal.', $staffId);
        flash('success', 'Work progress updated to ' . $progressPercent . '%.');
        redirect('staff/index.php?view=tasks');
    }

    if ($action === 'complete_task') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $stmt = db()->prepare('SELECT id, client_id, status FROM client_tasks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $taskId]);
        $taskRow = $stmt->fetch();

        if (!$taskRow || !in_array((int) $taskRow['client_id'], $myClientIds, true)) {
            flash('error', 'That task is not part of your assigned clients.');
            redirect('staff/index.php?view=tasks');
        }
        if (in_array((string) $taskRow['status'], ['cancelled', 'terminated'], true)) {
            flash('error', 'A ' . (string) $taskRow['status'] . ' task cannot be marked completed.');
            redirect('staff/index.php?view=tasks');
        }

        $update = db()->prepare("UPDATE client_tasks SET status = 'completed', completed_at = NOW() WHERE id = :id");
        $update->execute(['id' => $taskId]);
        log_activity('client_task', $taskId, 'completed', 'Task marked completed from staff portal.', $staffId);
        flash('success', 'Task marked completed.');
        redirect('staff/index.php?view=tasks');
    }

    flash('error', 'Unsupported portal action.');
    redirect('staff/index.php?view=home');
}

function staff_task_progress_percent(array $task, array $stages): int
{
    // A manually set work-progress value overrides the stage-derived figure.
    if (array_key_exists('progress_percent', $task) && $task['progress_percent'] !== null) {
        return max(0, min(100, (int) $task['progress_percent']));
    }

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

$pageTitle = match ($view) {
    'clients' => 'My Clients',
    'tasks' => 'Client Tasks',
    default => 'Staff Portal',
};
$pageSubtitle = match ($view) {
    'clients' => 'Clients assigned to you through tasks, stages, or team work',
    'tasks' => 'Track, progress, and complete tasks for your assigned clients',
    default => 'Your workload at a glance: clients, contracts, tasks, and deadlines',
};

function staff_status_tone(string $status): string
{
    return match ($status) {
        'completed', 'active' => 'green',
        'in_progress' => 'blue',
        'on_hold' => 'amber',
        'cancelled', 'terminated' => 'red',
        default => 'gray',
    };
}

function staff_priority_tone(string $priority): string
{
    return match ($priority) {
        'high', 'urgent' => 'red',
        'medium' => 'amber',
        'low' => 'gray',
        default => 'blue',
    };
}

include __DIR__ . '/../../app/views/partials/staff_header.php';
?>

<?php if ($view === 'home'): ?>
    <section class="mbw-kpi-grid">
        <a class="mbw-kpi" href="<?= e(url('staff/index.php?view=clients')) ?>"><div><span class="mbw-kpi-label">My Active Clients</span><div class="mbw-kpi-value"><?= e((string) $activeClients) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">assigned to me</span></span></div><span class="mbw-chip tone-blue"><?= icon('clients') ?></span></a>
        <article class="mbw-kpi"><div><span class="mbw-kpi-label">My Active Contracts</span><div class="mbw-kpi-value"><?= e((string) $activeContracts) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">across my clients</span></span></div><span class="mbw-chip tone-purple"><?= icon('contracts') ?></span></article>
        <a class="mbw-kpi" href="<?= e(url('staff/index.php?view=tasks')) ?>"><div><span class="mbw-kpi-label">My Open Tasks</span><div class="mbw-kpi-value"><?= e((string) $openTasks) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">new, in progress, on hold</span></span></div><span class="mbw-chip tone-amber"><?= icon('tasks') ?></span></a>
        <a class="mbw-kpi" href="<?= e(url('staff/index.php?view=tasks')) ?>"><div><span class="mbw-kpi-label">My Completed Tasks</span><div class="mbw-kpi-value"><?= e((string) $completedTasks) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">all time</span></span></div><span class="mbw-chip tone-green"><?= icon('insights') ?></span></a>
        <article class="mbw-kpi"><div><span class="mbw-kpi-label">Compliance Deadlines</span><div class="mbw-kpi-value"><?= e((string) $upcomingComplianceCount) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">upcoming or overdue</span></span></div><span class="mbw-chip tone-red"><?= icon('compliance') ?></span></article>
        <article class="mbw-kpi"><div><span class="mbw-kpi-label">Unread Messages</span><div class="mbw-kpi-value"><?= e((string) $unreadMessageCount) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">awaiting reply</span></span></div><span class="mbw-chip tone-teal"><?= icon('messages') ?></span></article>
        <article class="mbw-kpi"><div><span class="mbw-kpi-label">Hours This Week</span><div class="mbw-kpi-value"><?= e((string) $weeklyHours) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">recorded on timesheets</span></span></div><span class="mbw-chip tone-gray"><?= icon('timesheets') ?></span></article>
    </section>

    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Recent Tasks for My Clients</h2><div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('staff/index.php?view=tasks')) ?>">View All</a></div></div>
        <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>Task</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($myTasks === []): ?>
                    <tr><td colspan="5">No service tasks available for your assigned clients.</td></tr>
                <?php endif; ?>
                <?php foreach (array_slice($myTasks, 0, 10) as $task): ?>
                    <tr>
                        <td>#<?= e((int) $task['id']) ?> <?= e($task['title']) ?></td>
                        <td><?= e($task['organization_name']) ?></td>
                        <td><span class="mbw-pill tone-<?= e(staff_status_tone((string) $task['status'])) ?>"><?= e($task['status']) ?></span></td>
                        <td><span class="mbw-pill tone-<?= e(staff_priority_tone((string) $task['priority'])) ?>"><?= e($task['priority']) ?></span></td>
                        <td><?= e($task['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($view === 'clients'): ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Clients Assigned to Me</h2></div>
        <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Code</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($myClients === []): ?>
                    <tr><td colspan="3">No clients are assigned to you through tasks yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($myClients as $client): ?>
                    <tr>
                        <td><?= e($client['organization_name']) ?><br><small><?= e($client['name']) ?> (<?= e($client['email']) ?>)</small></td>
                        <td><?= e($client['client_code'] ?? 'N/A') ?></td>
                        <td><?= (int) $client['is_active'] === 1 ? '<span class="mbw-pill tone-green">Active</span>' : '<span class="mbw-pill tone-red">Inactive</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($view === 'tasks'): ?>
    <?php if ($myHandoffs !== []): ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Work Handed Over to You</h2></div>
        <p style="color:var(--mbw-muted);">You were assigned to take over these tasks. Each handoff includes the work progress at the time of the replacement.</p>
        <?php foreach ($myHandoffs as $handoff): ?>
            <details style="margin-bottom:10px;">
                <summary>
                    <strong>#<?= e((int) $handoff['task_id']) ?> <?= e($handoff['task_title']) ?></strong>
                    — taking over from <?= e($handoff['from_user_name'] ?? 'previous staff') ?> on <?= e($handoff['created_at']) ?>
                </summary>
                <?php if (!empty($handoff['progress_summary'])): ?>
                    <pre style="white-space:pre-wrap; background:var(--mbw-surface-2, rgba(0,0,0,0.04)); padding:10px; border-radius:8px; margin:8px 0;"><?= e($handoff['progress_summary']) ?></pre>
                <?php endif; ?>
                <?php if (!empty($handoff['note'])): ?>
                    <p><strong>Handoff note:</strong> <?= nl2br(e($handoff['note'])) ?></p>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Tasks for My Clients</h2></div>
        <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>Task</th>
                    <th>Client</th>
                    <th>Progress</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Due</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($myTasks === []): ?>
                    <tr><td colspan="7">No tasks available for your assigned clients.</td></tr>
                <?php endif; ?>
                <?php foreach ($myTasks as $task): ?>
                    <?php
                        $taskStages = $stagesByTask[(int) $task['id']] ?? [];
                        $progress = staff_task_progress_percent($task, $taskStages);
                        $pendingStages = array_filter($taskStages, static fn (array $s): bool => ($s['status'] ?? '') !== 'completed');
                        $taskIsLocked = in_array((string) $task['status'], ['cancelled', 'terminated'], true);
                        $rowAssignees = $taskAssigneesMap[(int) $task['id']] ?? [];
                        $isMyAssignment = (int) ($task['assigned_staff_user_id'] ?? 0) === $staffId
                            || in_array($staffId, array_map(static fn (array $a): int => $a['user_id'], $rowAssignees), true);
                        if (!$isMyAssignment) {
                            foreach ($taskStages as $stageRow) {
                                if ((int) ($stageRow['assigned_staff_user_id'] ?? 0) === $staffId) {
                                    $isMyAssignment = true;
                                    break;
                                }
                            }
                        }
                    ?>
                    <tr>
                        <td>
                            #<?= e((int) $task['id']) ?> <?= e($task['title']) ?><br><small><?= e($task['contract_no'] ?? 'No contract') ?></small>
                            <?php $rowAssignees = $taskAssigneesMap[(int) $task['id']] ?? []; ?>
                            <?php if ($rowAssignees !== []): ?>
                                <br><small>Assigned: <?= e(implode(', ', array_map(static fn (array $a): string => $a['name'], $rowAssignees))) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= e($task['organization_name']) ?></td>
                        <td>
                            <div class="progress-track"><div class="progress-fill" style="width: <?= e((string) $progress) ?>%"></div></div>
                            <small><?= e((string) $progress) ?>%</small>
                            <?php if ($hasProgressColumn && $isMyAssignment && !$taskIsLocked && $task['status'] !== 'completed'): ?>
                                <form method="post" style="display:flex; gap:4px; align-items:center; margin-top:4px;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update_task_progress">
                                    <input type="hidden" name="task_id" value="<?= e((int) $task['id']) ?>">
                                    <input type="number" name="progress_percent" min="0" max="100" step="1" value="<?= e((string) $progress) ?>" style="width:64px;" aria-label="Work progress percent">
                                    <button type="submit" class="button secondary">Update</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td><span class="mbw-pill tone-<?= e(staff_status_tone((string) $task['status'])) ?>"><?= e($task['status']) ?></span></td>
                        <td><span class="mbw-pill tone-<?= e(staff_priority_tone((string) $task['priority'])) ?>"><?= e($task['priority']) ?></span></td>
                        <td><?= e($task['due_date'] ?? '-') ?></td>
                        <td>
                            <?php if ($taskIsLocked): ?>
                                <small><?= e($task['status'] === 'terminated' ? 'Task terminated; no further work.' : 'Task cancelled.') ?></small>
                            <?php endif; ?>
                            <?php if ($pendingStages !== [] && !$taskIsLocked): ?>
                                <form method="post" class="inline-action-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="complete_stage">
                                    <select name="stage_id" required>
                                        <?php foreach ($pendingStages as $stage): ?>
                                            <option value="<?= e((int) $stage['id']) ?>"><?= e($stage['stage_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="button secondary">Complete stage</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($task['status'] !== 'completed' && !$taskIsLocked): ?>
                                <form method="post" class="inline-action-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="complete_task">
                                    <input type="hidden" name="task_id" value="<?= e((int) $task['id']) ?>">
                                    <button type="submit" class="button">Mark task complete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/../../app/views/partials/staff_footer.php'; ?>
