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

// Chart range filter (All time / 30 / 90 days) — applies to the two charts.
$range = (string) ($_GET['range'] ?? 'all');
if (!in_array($range, ['all', '30', '90'], true)) {
    $range = 'all';
}
$rangeSince = $range === 'all' ? null : date('Y-m-d H:i:s', strtotime('-' . $range . ' days'));
$rangeLabels = ['all' => 'All Time', '30' => 'Last 30 Days', '90' => 'Last 90 Days'];

// 30-day trend helper: growth of the last 30 days against the prior base.
$since30 = date('Y-m-d H:i:s', strtotime('-30 days'));
$trendFor = static function (int $recentCount, int $total): array {
    if ($recentCount <= 0 || $total <= 0) {
        return ['dir' => 'flat', 'text' => 'No change'];
    }
    $base = max(1, $total - $recentCount);
    return ['dir' => 'up', 'text' => '+' . number_format(($recentCount / $base) * 100, 1) . '% vs last 30 days'];
};
$countSince = static function (string $sql, array $params): int {
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $exception) {
        return 0;
    }
};

// Status/priority pill renderer for the tables.
$ovPill = static function (string $value): string {
    $tones = [
        'new' => 'tone-blue', 'in_progress' => 'tone-amber', 'on_hold' => 'tone-purple',
        'completed' => 'tone-green', 'cancelled' => 'tone-red',
        'draft' => 'tone-amber', 'issued' => 'tone-blue', 'paid' => 'tone-green',
        'low' => 'tone-teal', 'normal' => 'tone-blue', 'high' => 'tone-red', 'urgent' => 'tone-red',
    ];
    $tone = $tones[$value] ?? 'tone-blue';
    return '<span class="mbw-pill ' . $tone . '">' . e(str_replace('_', ' ', $value)) . '</span>';
};

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

    $staffTaskJoin = column_exists('client_tasks', 'assigned_staff_user_id') ? 'assigned_staff_user_id' : 'created_by';
    $staffStmt = db()->prepare("SELECT
            u.id,
            u.name,
            COUNT(t.id) AS total_tasks,
            SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks,
            SUM(CASE WHEN t.status IN ('new', 'in_progress', 'on_hold') THEN 1 ELSE 0 END) AS open_tasks
        FROM users u
        LEFT JOIN client_tasks t ON t.{$staffTaskJoin} = u.id AND t.company_id = :cid1
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

// Chart data: same shape as the KPI aggregates, but honouring the range picker.
$taskChartCounts = $taskStatusCounts;
$invoiceChartCounts = $invoiceStatusCounts;
if ($rangeSince !== null) {
    $taskChartCounts = array_fill_keys(array_keys($taskStatusCounts), 0);
    $invoiceChartCounts = array_fill_keys(array_keys($invoiceStatusCounts), 0);
    try {
        if (table_exists('client_tasks')) {
            $stmt = db()->prepare('SELECT status, COUNT(*) AS total FROM client_tasks WHERE company_id = :cid AND created_at >= :since GROUP BY status');
            $stmt->execute(['cid' => $companyId, 'since' => $rangeSince]);
            foreach ($stmt->fetchAll() as $row) {
                if (array_key_exists((string) $row['status'], $taskChartCounts)) {
                    $taskChartCounts[(string) $row['status']] = (int) $row['total'];
                }
            }
        }
        if (table_exists('task_invoices')) {
            $stmt = db()->prepare('SELECT status, COUNT(*) AS total FROM task_invoices WHERE company_id = :cid AND created_at >= :since GROUP BY status');
            $stmt->execute(['cid' => $companyId, 'since' => $rangeSince]);
            foreach ($stmt->fetchAll() as $row) {
                if (array_key_exists((string) $row['status'], $invoiceChartCounts)) {
                    $invoiceChartCounts[(string) $row['status']] = (int) $row['total'];
                }
            }
        }
    } catch (Throwable $exception) {
        $taskChartCounts = $taskStatusCounts;
        $invoiceChartCounts = $invoiceStatusCounts;
    }
}
$invoiceChartTotal = array_sum($invoiceChartCounts);

// 30-day movement for each KPI card.
$trends = [
    'companies' => $trendFor(0, $companyCount),
    'staff' => $trendFor($countSince("SELECT COUNT(*) FROM users WHERE company_id = :cid AND role = 'staff' AND status = 'active' AND created_at >= :since", ['cid' => $companyId, 'since' => $since30]), $activeStaffCount),
    'clients' => $trendFor($countSince('SELECT COUNT(*) FROM client_profiles WHERE company_id = :cid AND is_active = 1 AND created_at >= :since', ['cid' => $companyId, 'since' => $since30]), $activeClientCount),
    'tasks' => $trendFor($countSince("SELECT COUNT(*) FROM client_tasks WHERE company_id = :cid AND status IN ('new','in_progress','on_hold') AND created_at >= :since", ['cid' => $companyId, 'since' => $since30]), $openTaskCount),
    'progress' => $trendFor($countSince("SELECT COUNT(*) FROM client_tasks WHERE company_id = :cid AND status = 'completed' AND updated_at >= :since", ['cid' => $companyId, 'since' => $since30]), max(1, $taskTotalCount)),
    'invoices' => $trendFor($countSince("SELECT COUNT(*) FROM task_invoices WHERE company_id = :cid AND status IN ('draft','issued') AND created_at >= :since", ['cid' => $companyId, 'since' => $since30]), $pendingInvoiceCount),
    'collection' => $trendFor($countSince("SELECT COUNT(*) FROM invoice_payment_requests WHERE company_id = :cid AND status IN ('paid','partial') AND payment_received_on >= :since", ['cid' => $companyId, 'since' => date('Y-m-d', strtotime('-30 days'))]), max(1, (int) $invoiceStatusCounts['paid'])),
];

include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<?php
$ovTrend = static function (array $trend): string {
    $arrow = $trend['dir'] === 'up' ? '&#8593; ' : '&#8212; ';
    return '<span class="ov-kpi-trend ' . ($trend['dir'] === 'up' ? 'is-up' : 'is-flat') . '">' . $arrow . e($trend['text']) . '</span>';
};
?>
<div class="ov-kpis">
    <a class="ov-kpi" href="<?= e(url('admin/companies.php')) ?>">
        <span class="ov-kpi-top"><span class="mbw-chip is-square tone-blue"><?= icon('companies') ?></span><span class="ov-kpi-label">Companies</span></span>
        <strong class="ov-kpi-value"><?= e($companyCount) ?></strong>
        <span class="ov-kpi-sub">Total companies</span>
        <?= $ovTrend($trends['companies']) ?>
    </a>
    <a class="ov-kpi" href="<?= e(url('admin/users.php')) ?>">
        <span class="ov-kpi-top"><span class="mbw-chip is-square tone-teal"><?= icon('staff') ?></span><span class="ov-kpi-label">Active staff</span></span>
        <strong class="ov-kpi-value"><?= e($activeStaffCount) ?></strong>
        <span class="ov-kpi-sub">Team members</span>
        <?= $ovTrend($trends['staff']) ?>
    </a>
    <a class="ov-kpi" href="<?= e(url('admin/manage-clients.php')) ?>">
        <span class="ov-kpi-top"><span class="mbw-chip is-square tone-purple"><?= icon('clients') ?></span><span class="ov-kpi-label">Active clients</span></span>
        <strong class="ov-kpi-value"><?= e($activeClientCount) ?></strong>
        <span class="ov-kpi-sub">Total clients</span>
        <?= $ovTrend($trends['clients']) ?>
    </a>
    <a class="ov-kpi" href="<?= e(url('admin/workspace.php?view=home')) ?>">
        <span class="ov-kpi-top"><span class="mbw-chip is-square tone-green"><?= icon('tasks') ?></span><span class="ov-kpi-label">Open tasks</span></span>
        <strong class="ov-kpi-value"><?= e($openTaskCount) ?></strong>
        <span class="ov-kpi-sub">Tasks to complete</span>
        <?= $ovTrend($trends['tasks']) ?>
    </a>
    <a class="ov-kpi" href="<?= e(url('admin/workspace.php?view=home')) ?>">
        <span class="ov-kpi-top"><span class="ov-ring" style="--p: <?= e((string) min(100, $overallTaskProgress)) ?>"></span><span class="ov-kpi-label">Overall task progress</span></span>
        <strong class="ov-kpi-value"><?= e(number_format($overallTaskProgress, 1)) ?>%</strong>
        <span class="ov-kpi-sub">All tasks</span>
        <?= $ovTrend($trends['progress']) ?>
    </a>
    <a class="ov-kpi" href="<?= e(url('admin/invoice.php')) ?>">
        <span class="ov-kpi-top"><span class="mbw-chip is-square tone-amber"><?= icon('invoices') ?></span><span class="ov-kpi-label">Pending invoices</span></span>
        <strong class="ov-kpi-value"><?= e($pendingInvoiceCount) ?></strong>
        <span class="ov-kpi-sub">Awaiting payment</span>
        <?= $ovTrend($trends['invoices']) ?>
    </a>
    <a class="ov-kpi" href="<?= e(url('admin/reports-center.php?report=collections-register')) ?>">
        <span class="ov-kpi-top"><span class="mbw-chip is-square tone-red"><?= icon('reports') ?></span><span class="ov-kpi-label">Invoice collection rate</span></span>
        <strong class="ov-kpi-value"><?= e(number_format($invoiceCollectionRate, 1)) ?>%</strong>
        <span class="ov-kpi-sub">This period</span>
        <?= $ovTrend($trends['collection']) ?>
    </a>
</div>

<div class="admin-dashboard-grid">
    <div class="card admin-chart-card">
        <div class="ov-card-head">
            <h3><?= icon('insights') ?>Task progress by status</h3>
            <form method="get" class="ov-range">
                <select name="range" onchange="this.form.submit()" aria-label="Chart period">
                    <?php foreach ($rangeLabels as $rangeKey => $rangeLabel): ?>
                        <option value="<?= e($rangeKey) ?>" <?= $range === $rangeKey ? 'selected' : '' ?>><?= e($rangeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="mbw-chart-wrap" style="height:220px"><canvas id="taskStatusChart"></canvas></div>
    </div>

    <div class="card admin-chart-card">
        <div class="ov-card-head">
            <h3><?= icon('reports') ?>Invoice pipeline by status</h3>
            <form method="get" class="ov-range">
                <select name="range" onchange="this.form.submit()" aria-label="Chart period">
                    <?php foreach ($rangeLabels as $rangeKey => $rangeLabel): ?>
                        <option value="<?= e($rangeKey) ?>" <?= $range === $rangeKey ? 'selected' : '' ?>><?= e($rangeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="mbw-donut-row">
            <div class="mbw-donut-box" style="width:150px;height:150px;position:relative">
                <canvas id="invoiceStatusChart"></canvas>
                <span class="ov-donut-center"><b>Total <?= e($invoiceChartTotal) ?></b><small>Invoices</small></span>
            </div>
            <div class="mbw-chart-legend ov-donut-legend" id="invoiceStatusLegend"></div>
        </div>
    </div>

    <div class="card admin-analysis-card">
        <div class="ov-card-head">
            <h3><?= icon('users') ?>Staff performance snapshot</h3>
        </div>
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
                        $rateTone = $completionRate >= 80 ? 'tone-green' : ($completionRate >= 40 ? 'tone-amber' : 'tone-red');
                        ?>
                        <tr>
                            <td><?= e($staff['name']) ?></td>
                            <td><?= e($totalTasks) ?></td>
                            <td><?= e($completedTasks) ?></td>
                            <td><?= e($openTasks) ?></td>
                            <td><span class="mbw-pill <?= e($rateTone) ?>"><?= e(number_format($completionRate, 1)) ?>%</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <a class="mbw-view-all ov-view-all" href="<?= e(url('admin/users.php')) ?>">View all staff performance &#8594;</a>
    </div>

    <div class="card admin-analysis-card">
        <div class="ov-card-head">
            <h3><?= icon('invoices') ?>Pending invoices (draft + issued)</h3>
        </div>
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
                            <td><a href="<?= e(url('admin/invoice.php?invoice_id=' . (int) $invoice['id'])) ?>"><?= e($invoice['invoice_no']) ?></a></td>
                            <td><?= e($invoice['organization_name']) ?></td>
                            <td><?= e($invoice['task_title']) ?></td>
                            <td><?= $ovPill((string) $invoice['status']) ?></td>
                            <td><?= e($invoice['due_on'] ?? '-') ?></td>
                            <td>Rs.<?= e(number_format((float) ($invoice['amount'] ?? 0), 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <a class="mbw-view-all ov-view-all" href="<?= e(url('admin/invoice.php')) ?>">View all invoices &#8594;</a>
    </div>
</div>

<div class="table-card">
    <div class="ov-card-head">
        <h2><?= icon('tasks') ?>Recent service tasks</h2>
        <a class="mbw-view-all" href="<?= e(url('admin/workspace.php?view=tasks')) ?>">View all tasks &#8594;</a>
    </div>
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
                        <td><?= $ovPill((string) $task['status']) ?></td>
                        <td><?= $ovPill((string) $task['priority']) ?></td>
                        <td><?= e($task['due_date'] ?? '-') ?></td>
                        <td><?= e($task['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!window.MBWCharts) {
        return;
    }

    const taskStatusData = <?= json_encode([
        'new' => (int) ($taskChartCounts['new'] ?? 0),
        'in_progress' => (int) ($taskChartCounts['in_progress'] ?? 0),
        'on_hold' => (int) ($taskChartCounts['on_hold'] ?? 0),
        'completed' => (int) ($taskChartCounts['completed'] ?? 0),
        'cancelled' => (int) ($taskChartCounts['cancelled'] ?? 0),
    ], JSON_UNESCAPED_SLASHES) ?>;

    const invoiceStatusData = <?= json_encode([
        'draft' => (int) ($invoiceChartCounts['draft'] ?? 0),
        'issued' => (int) ($invoiceChartCounts['issued'] ?? 0),
        'paid' => (int) ($invoiceChartCounts['paid'] ?? 0),
        'cancelled' => (int) ($invoiceChartCounts['cancelled'] ?? 0),
    ], JSON_UNESCAPED_SLASHES) ?>;

    const taskCanvas = document.getElementById('taskStatusChart');
    if (taskCanvas) {
        MBWCharts.barLine(taskCanvas, {
            labels: ['New', 'In progress', 'On hold', 'Completed', 'Cancelled'],
            bars: [{
                label: 'Tasks',
                color: 'primary',
                values: [taskStatusData.new, taskStatusData.in_progress, taskStatusData.on_hold, taskStatusData.completed, taskStatusData.cancelled]
            }],
            format: (v) => v.toLocaleString()
        });
    }

    const invoiceCanvas = document.getElementById('invoiceStatusChart');
    if (invoiceCanvas) {
        const segments = [
            { label: 'Draft', value: invoiceStatusData.draft, color: 'amber' },
            { label: 'Issued', value: invoiceStatusData.issued, color: 'primary' },
            { label: 'Paid', value: invoiceStatusData.paid, color: 'green' },
            { label: 'Cancelled', value: invoiceStatusData.cancelled, color: 'red' }
        ];
        MBWCharts.donut(invoiceCanvas, { segments: segments, thickness: 0.36 });
        const legend = document.getElementById('invoiceStatusLegend');
        if (legend) {
            const tokens = { amber: 'var(--mbw-amber)', primary: 'var(--mbw-primary)', green: 'var(--mbw-green)', red: 'var(--mbw-red)' };
            const totalSegments = segments.reduce((sum, seg) => sum + seg.value, 0);
            legend.innerHTML = segments.map((seg) => {
                const pct = totalSegments > 0 ? Math.round((seg.value / totalSegments) * 100) : 0;
                return '<span class="ov-legend-row"><i class="mbw-legend-dot" style="background:' + tokens[seg.color] + '"></i><span>' + seg.label + '</span><b>' + seg.value.toLocaleString() + '</b><em>' + pct + '%</em></span>';
            }).join('');
        }
    }
});
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
