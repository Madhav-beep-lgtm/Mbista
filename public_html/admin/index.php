<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();
require_company_context();
$pageTitle = 'Admin Overview';
$bodyClass = 'admin-layout admin-dashboard';
$company = current_company();
$fiscalYear = current_fiscal_year();
$companyId = (int) ($company['id'] ?? 0);
$companyCode = (string) ($company['code'] ?? '');
$mbistaCompany = company_by_code('MBAACA');
$altioraCompany = company_by_code('AGHPL');
$isMbistaSuperadminPortal = $companyCode === 'MBAACA';
$isAltioraPortal = $companyCode === 'AGHPL';
$subsidiaryCompanies = $isAltioraPortal ? child_companies_for_company($companyId) : [];

$companyCount = 1;
$activeStaffCount = 0;
if (table_exists('users')) {
    $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE company_id = :company_id AND role = 'staff' AND status = 'active'");
    $stmt->execute(['company_id' => $companyId]);
    $activeStaffCount = (int) $stmt->fetchColumn();
}
$activeClientCount = table_exists('client_profiles')
    ? (function () use ($companyId): int {
        $stmt = db()->prepare('SELECT COUNT(*) FROM client_profiles WHERE company_id = :company_id AND is_active = 1');
        $stmt->execute(['company_id' => $companyId]);
        return (int) $stmt->fetchColumn();
    })()
    : 0;
$openTaskCount = table_exists('client_tasks')
    ? (function () use ($companyId): int {
        $stmt = db()->prepare("SELECT COUNT(*) FROM client_tasks WHERE company_id = :company_id AND status IN ('new','in_progress','on_hold')");
        $stmt->execute(['company_id' => $companyId]);
        return (int) $stmt->fetchColumn();
    })()
    : 0;
$activeContractCount = table_exists('service_contracts')
    ? (function () use ($companyId): int {
        $stmt = db()->prepare("SELECT COUNT(*) FROM service_contracts WHERE company_id = :company_id AND status = 'active'");
        $stmt->execute(['company_id' => $companyId]);
        return (int) $stmt->fetchColumn();
    })()
    : 0;

$taskStatusCounts = [
    'new' => 0,
    'in_progress' => 0,
    'on_hold' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
$invoiceStatusCounts = [
    'draft' => 0,
    'issued' => 0,
    'paid' => 0,
    'cancelled' => 0,
];

$taskTotalCount = 0;
$taskCompletedCount = 0;
$overallTaskProgress = 0.0;

$invoiceTotalAmount = 0.0;
$invoicePaidAmount = 0.0;
$invoiceCollectionRate = 0.0;
$pendingInvoiceCount = 0;
$pendingInvoiceAmount = 0.0;

$staffPerformance = [];
$pendingInvoices = [];

if (table_exists('client_tasks')) {
    $taskStmt = db()->prepare("SELECT status, COUNT(*) AS total
        FROM client_tasks
        WHERE company_id = :company_id
        GROUP BY status");
    $taskStmt->execute(['company_id' => $companyId]);
    $taskStatusRows = $taskStmt->fetchAll();

    foreach ($taskStatusRows as $row) {
        $status = (string) ($row['status'] ?? '');
        $total = (int) ($row['total'] ?? 0);
        if (array_key_exists($status, $taskStatusCounts)) {
            $taskStatusCounts[$status] = $total;
        }
    }

    $taskTotalCount = array_sum($taskStatusCounts);
    $taskCompletedCount = (int) ($taskStatusCounts['completed'] ?? 0);
    $overallTaskProgress = $taskTotalCount > 0
        ? round(($taskCompletedCount / $taskTotalCount) * 100, 1)
        : 0.0;

    $staffStmt = db()->prepare("SELECT
            u.id,
            u.name,
            COUNT(t.id) AS total_tasks,
            SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks,
            SUM(CASE WHEN t.status IN ('new', 'in_progress', 'on_hold') THEN 1 ELSE 0 END) AS open_tasks
        FROM users u
        LEFT JOIN client_tasks t ON t.created_by = u.id AND t.company_id = :cid1
        WHERE u.role = 'staff' AND u.status = 'active' AND u.company_id = :cid2
        GROUP BY u.id, u.name
        ORDER BY completed_tasks DESC, total_tasks DESC, u.name ASC
        LIMIT 7");
    $staffStmt->execute(['cid1' => $companyId, 'cid2' => $companyId]);
    $staffPerformance = $staffStmt->fetchAll();
}

if (table_exists('task_invoices')) {
    $invoiceStmt = db()->prepare("SELECT status, COUNT(*) AS total, COALESCE(SUM(amount), 0) AS amount
        FROM task_invoices
        WHERE company_id = :company_id
        GROUP BY status");
    $invoiceStmt->execute(['company_id' => $companyId]);
    $invoiceRows = $invoiceStmt->fetchAll();

    foreach ($invoiceRows as $row) {
        $status = (string) ($row['status'] ?? '');
        $total = (int) ($row['total'] ?? 0);
        $amount = (float) ($row['amount'] ?? 0);
        if (array_key_exists($status, $invoiceStatusCounts)) {
            $invoiceStatusCounts[$status] = $total;
        }

        if ($status !== 'cancelled') {
            $invoiceTotalAmount += $amount;
        }

        if ($status === 'paid') {
            $invoicePaidAmount += $amount;
        }

        if ($status === 'draft' || $status === 'issued') {
            $pendingInvoiceCount += $total;
            $pendingInvoiceAmount += $amount;
        }
    }

    $invoiceCollectionRate = $invoiceTotalAmount > 0
        ? round(($invoicePaidAmount / $invoiceTotalAmount) * 100, 1)
        : 0.0;

    if (table_exists('client_tasks') && table_exists('client_profiles')) {
        $pendingStmt = db()->prepare("SELECT
                ti.id,
                ti.invoice_no,
                ti.amount,
                ti.status,
                ti.due_on,
                t.title AS task_title,
                cp.organization_name
            FROM task_invoices ti
            INNER JOIN client_tasks t ON t.id = ti.task_id
            INNER JOIN client_profiles cp ON cp.id = t.client_id
            WHERE ti.company_id = :company_id AND ti.status IN ('draft', 'issued')
            ORDER BY (ti.due_on IS NULL), ti.due_on ASC, ti.created_at DESC
            LIMIT 10");
        $pendingStmt->execute(['company_id' => $companyId]);
        $pendingInvoices = $pendingStmt->fetchAll();
    }
}

$recentTasks = [];
if (table_exists('client_tasks') && table_exists('client_profiles')) {
    $recentStmt = db()->prepare("SELECT t.id, t.title, t.status, t.priority, t.due_date, t.created_at, cp.organization_name
        FROM client_tasks t
        INNER JOIN client_profiles cp ON cp.id = t.client_id
        WHERE t.company_id = :company_id
        ORDER BY t.created_at DESC
        LIMIT 8");
    $recentStmt->execute(['company_id' => $companyId]);
    $recentTasks = $recentStmt->fetchAll();
}

include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="admin-stats">
    <a class="card stat-link" href="<?= e(url('admin/companies.php')) ?>"><span class="stat-icon"><?= icon('companies') ?></span><strong><?= e($companyCount) ?></strong><p>Companies</p><small>Click → View all companies</small></a>
    <a class="card stat-link" href="<?= e(url('admin/users.php')) ?>"><span class="stat-icon"><?= icon('staff') ?></span><strong><?= e($activeStaffCount) ?></strong><p>Active staff</p><small>Click → View all staff</small></a>
    <a class="card stat-link" href="<?= e(url('admin/workspace.php?view=clients')) ?>"><span class="stat-icon"><?= icon('clients') ?></span><strong><?= e($activeClientCount) ?></strong><p>Active clients</p><small>Click → View all clients</small></a>
    <a class="card stat-link" href="<?= e(url('admin/workspace.php?view=home')) ?>"><span class="stat-icon"><?= icon('tasks') ?></span><strong><?= e($openTaskCount) ?></strong><p>Open tasks</p><small>Click → View all tasks</small></a>
    <a class="card stat-link" href="<?= e(url('admin/workspace.php?view=home')) ?>"><span class="stat-icon"><?= icon('insights') ?></span><strong><?= e(number_format($overallTaskProgress, 1)) ?>%</strong><p>Overall task progress</p><small>Click → Open task analytics</small></a>
    <a class="card stat-link" href="<?= e(url('admin/invoice.php')) ?>"><span class="stat-icon"><?= icon('invoices') ?></span><strong><?= e($pendingInvoiceCount) ?></strong><p>Pending invoices</p><small>Click → View pending invoices</small></a>
    <a class="card stat-link" href="<?= e(url('admin/invoice.php')) ?>"><span class="stat-icon"><?= icon('accounting') ?></span><strong>Rs.<?= e(number_format($pendingInvoiceAmount, 2)) ?></strong><p>Pending invoice amount</p><small>Click → View amount details</small></a>
    <a class="card stat-link" href="<?= e(url('admin/accounting-parties.php?tab=collections')) ?>"><span class="stat-icon"><?= icon('reports') ?></span><strong><?= e(number_format($invoiceCollectionRate, 1)) ?>%</strong><p>Invoice collection rate</p><small>Click → View collection analytics</small></a>
</div>

<div class="admin-dashboard-grid">
    <div class="card admin-chart-card">
        <div class="badge"><?= icon('tasks') ?>Task analysis</div>
        <h3><?= icon('insights') ?>Task progress by status</h3>
        <canvas id="taskStatusChart" height="220"></canvas>
    </div>

    <div class="card admin-chart-card">
        <div class="badge"><?= icon('invoices') ?>Invoice analysis</div>
        <h3><?= icon('reports') ?>Invoice pipeline by status</h3>
        <canvas id="invoiceStatusChart" height="220"></canvas>
    </div>

    <div class="card admin-analysis-card">
        <div class="badge"><?= icon('staff') ?>Performance</div>
        <h3><?= icon('users') ?>Staff performance snapshot</h3>
        <?php if ($staffPerformance === []): ?>
            <p>No staff task performance data yet. Start assigning and completing tasks to populate this panel.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Staff</th>
                        <th>Total tasks</th>
                        <th>Completed</th>
                        <th>Open</th>
                        <th>Completion %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staffPerformance as $staff): ?>
                        <?php
                        $totalTasks = (int) ($staff['total_tasks'] ?? 0);
                        $completedTasks = (int) ($staff['completed_tasks'] ?? 0);
                        $openTasks = (int) ($staff['open_tasks'] ?? 0);
                        $completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><?= e($staff['name']) ?></td>
                            <td><?= e($totalTasks) ?></td>
                            <td><?= e($completedTasks) ?></td>
                            <td><?= e($openTasks) ?></td>
                            <td><span class="tag"><?= e(number_format($completionRate, 1)) ?>%</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card admin-analysis-card">
        <div class="badge"><?= icon('accounting') ?>Billing focus</div>
        <h3><?= icon('invoices') ?>Pending invoices (draft + issued)</h3>
        <?php if ($pendingInvoices === []): ?>
            <p>No pending invoices at the moment.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Client</th>
                        <th>Task</th>
                        <th>Status</th>
                        <th>Due on</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingInvoices as $invoice): ?>
                        <tr>
                            <td><?= e($invoice['invoice_no']) ?></td>
                            <td><?= e($invoice['organization_name']) ?></td>
                            <td><?= e($invoice['task_title']) ?></td>
                            <td><span class="tag"><?= e($invoice['status']) ?></span></td>
                            <td><?= e($invoice['due_on'] ?? '-') ?></td>
                            <td>Rs.<?= e(number_format((float) ($invoice['amount'] ?? 0), 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="table-card">
    <h2><?= icon('tasks') ?>Recent service tasks</h2>
    <?php if ($recentTasks === []): ?>
        <p>No service tasks are available yet. Use Work Portal > Tasks to add the first task.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Task</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Due</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentTasks as $task): ?>
                    <tr>
                        <td>#<?= e((int) $task['id']) ?> <?= e($task['title']) ?></td>
                        <td><?= e($task['organization_name']) ?></td>
                        <td><span class="tag"><?= e($task['status']) ?></span></td>
                        <td><span class="tag"><?= e($task['priority']) ?></span></td>
                        <td><?= e($task['due_date'] ?? '-') ?></td>
                        <td><?= e($task['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(() => {
    if (!window.Chart) {
        return;
    }

    const taskStatusData = <?= json_encode([
        'new' => (int) ($taskStatusCounts['new'] ?? 0),
        'in_progress' => (int) ($taskStatusCounts['in_progress'] ?? 0),
        'on_hold' => (int) ($taskStatusCounts['on_hold'] ?? 0),
        'completed' => (int) ($taskStatusCounts['completed'] ?? 0),
        'cancelled' => (int) ($taskStatusCounts['cancelled'] ?? 0),
    ], JSON_UNESCAPED_SLASHES) ?>;

    const invoiceStatusData = <?= json_encode([
        'draft' => (int) ($invoiceStatusCounts['draft'] ?? 0),
        'issued' => (int) ($invoiceStatusCounts['issued'] ?? 0),
        'paid' => (int) ($invoiceStatusCounts['paid'] ?? 0),
        'cancelled' => (int) ($invoiceStatusCounts['cancelled'] ?? 0),
    ], JSON_UNESCAPED_SLASHES) ?>;

    const taskCanvas = document.getElementById('taskStatusChart');
    if (taskCanvas) {
        new Chart(taskCanvas, {
            type: 'bar',
            data: {
                labels: ['New', 'In progress', 'On hold', 'Completed', 'Cancelled'],
                datasets: [{
                    label: 'Tasks',
                    data: [taskStatusData.new, taskStatusData.in_progress, taskStatusData.on_hold, taskStatusData.completed, taskStatusData.cancelled],
                    backgroundColor: ['#c96b3b', '#2f7d74', '#a270d6', '#1f9d55', '#b45309'],
                    borderRadius: 8,
                    maxBarThickness: 52
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    }

    const invoiceCanvas = document.getElementById('invoiceStatusChart');
    if (invoiceCanvas) {
        new Chart(invoiceCanvas, {
            type: 'doughnut',
            data: {
                labels: ['Draft', 'Issued', 'Paid', 'Cancelled'],
                datasets: [{
                    label: 'Invoices',
                    data: [invoiceStatusData.draft, invoiceStatusData.issued, invoiceStatusData.paid, invoiceStatusData.cancelled],
                    backgroundColor: ['#f1a678', '#16324f', '#2f7d74', '#a8702f']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }
})();
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
