<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();
require_company_context();
$pageTitle = 'Reports';
$bodyClass = 'admin-layout reports-page';
$company = current_company();
$companyId = (int) ($company['id'] ?? 0);

$allowedDateRanges = ['today', '7d', '30d', 'this_month', 'last_month', 'custom'];
$allowedBillingCycles = ['all', 'monthly', 'yearly'];
$allowedOrderStatuses = ['all', 'pending', 'confirmed', 'processing', 'completed', 'cancelled'];
$allowedPaymentStatuses = ['all', 'pending', 'paid', 'failed', 'refunded'];
$allowedPaymentMethods = ['all', 'manual', 'stripe', 'paypal'];

$range = (string) ($_GET['range'] ?? '30d');
if (!in_array($range, $allowedDateRanges, true)) {
    $range = '30d';
}

$billingCycle = (string) ($_GET['billing_cycle'] ?? 'all');
if (!in_array($billingCycle, $allowedBillingCycles, true)) {
    $billingCycle = 'all';
}

$orderStatus = (string) ($_GET['order_status'] ?? 'all');
if (!in_array($orderStatus, $allowedOrderStatuses, true)) {
    $orderStatus = 'all';
}

$paymentStatus = (string) ($_GET['payment_status'] ?? 'all');
if (!in_array($paymentStatus, $allowedPaymentStatuses, true)) {
    $paymentStatus = 'all';
}

$paymentMethod = (string) ($_GET['payment_method'] ?? 'all');
if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
    $paymentMethod = 'all';
}

$startInput = trim((string) ($_GET['start_date'] ?? ''));
$endInput = trim((string) ($_GET['end_date'] ?? ''));

$today = new DateTimeImmutable('today');
$startDate = null;
$endDate = null;

switch ($range) {
    case 'today':
        $startDate = $today;
        $endDate = $today;
        break;
    case '7d':
        $startDate = $today->modify('-6 days');
        $endDate = $today;
        break;
    case '30d':
        $startDate = $today->modify('-29 days');
        $endDate = $today;
        break;
    case 'this_month':
        $startDate = $today->modify('first day of this month');
        $endDate = $today->modify('last day of this month');
        break;
    case 'last_month':
        $startDate = $today->modify('first day of last month');
        $endDate = $today->modify('last day of last month');
        break;
    case 'custom':
        $startCustom = DateTimeImmutable::createFromFormat('Y-m-d', $startInput) ?: null;
        $endCustom = DateTimeImmutable::createFromFormat('Y-m-d', $endInput) ?: null;

        if ($startCustom && $endCustom) {
            if ($startCustom > $endCustom) {
                [$startCustom, $endCustom] = [$endCustom, $startCustom];
            }
            $startDate = $startCustom;
            $endDate = $endCustom;
        } else {
            flash('error', 'For custom range, both start and end dates are required.');
            redirect('admin/reports.php');
        }
        break;
}

$where = [];
$params = [];

$where[] = 'o.company_id = :company_id';
$params['company_id'] = $companyId;

if ($startDate !== null && $endDate !== null) {
    $where[] = 'DATE(o.created_at) BETWEEN :start_date AND :end_date';
    $params['start_date'] = $startDate->format('Y-m-d');
    $params['end_date'] = $endDate->format('Y-m-d');
}

if ($billingCycle !== 'all') {
    $where[] = 'o.billing_cycle = :billing_cycle';
    $params['billing_cycle'] = $billingCycle;
}

if ($orderStatus !== 'all') {
    $where[] = 'o.status = :order_status';
    $params['order_status'] = $orderStatus;
}

if ($paymentStatus !== 'all') {
    $where[] = 'o.payment_status = :payment_status';
    $params['payment_status'] = $paymentStatus;
}

if ($paymentMethod !== 'all') {
    $where[] = 'o.payment_method = :payment_method';
    $params['payment_method'] = $paymentMethod;
}

$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

$ordersSql = 'SELECT o.id, o.full_name, o.email, o.domain_name, o.billing_cycle, o.amount, o.payment_method, o.payment_status, o.status, o.created_at, p.name AS plan_name FROM orders o INNER JOIN plans p ON p.id = o.plan_id' . $whereSql . ' ORDER BY o.created_at DESC';
$ordersStmt = db()->prepare($ordersSql);
foreach ($params as $key => $value) {
    $ordersStmt->bindValue(':' . $key, $value);
}
$ordersStmt->execute();
$orders = $ordersStmt->fetchAll();

$totalOrders = count($orders);
$totalRevenue = 0.0;
$paidRevenue = 0.0;
$pendingRevenue = 0.0;
$paidCount = 0;

foreach ($orders as $order) {
    $amount = (float) $order['amount'];
    $totalRevenue += $amount;

    if (($order['payment_status'] ?? 'pending') === 'paid') {
        $paidRevenue += $amount;
        $paidCount++;
    } else {
        $pendingRevenue += $amount;
    }
}

$unpaidCount = $totalOrders - $paidCount;
$conversionRate = $totalOrders > 0 ? ($paidCount / $totalOrders) * 100 : 0;

$planStatsSql = 'SELECT p.name AS plan_name, COUNT(*) AS order_count, COALESCE(SUM(o.amount), 0) AS total_amount FROM orders o INNER JOIN plans p ON p.id = o.plan_id' . $whereSql . ' GROUP BY p.id, p.name ORDER BY total_amount DESC, order_count DESC';
$planStatsStmt = db()->prepare($planStatsSql);
foreach ($params as $key => $value) {
    $planStatsStmt->bindValue(':' . $key, $value);
}
$planStatsStmt->execute();
$planStats = $planStatsStmt->fetchAll();

$statusStatsSql = 'SELECT o.status, o.payment_status, COUNT(*) AS total FROM orders o' . $whereSql . ' GROUP BY o.status, o.payment_status ORDER BY total DESC';
$statusStatsStmt = db()->prepare($statusStatsSql);
foreach ($params as $key => $value) {
    $statusStatsStmt->bindValue(':' . $key, $value);
}
$statusStatsStmt->execute();
$statusStats = $statusStatsStmt->fetchAll();

$contactWhere = [];
$contactParams = [];

$contactWhere[] = 'c.company_id = :company_id';
$contactParams['company_id'] = $companyId;
if ($startDate !== null && $endDate !== null) {
    $contactWhere[] = 'DATE(c.created_at) BETWEEN :start_date AND :end_date';
    $contactParams['start_date'] = $startDate->format('Y-m-d');
    $contactParams['end_date'] = $endDate->format('Y-m-d');
}
$contactWhereSql = $contactWhere === [] ? '' : ' WHERE ' . implode(' AND ', $contactWhere);

$contactSummarySql = 'SELECT c.status, COUNT(*) AS total FROM contacts c' . $contactWhereSql . ' GROUP BY c.status ORDER BY total DESC';
$contactSummaryStmt = db()->prepare($contactSummarySql);
foreach ($contactParams as $key => $value) {
    $contactSummaryStmt->bindValue(':' . $key, $value);
}
$contactSummaryStmt->execute();
$contactSummary = $contactSummaryStmt->fetchAll();

$baseQuery = [
    'range' => $range,
    'start_date' => $startDate ? $startDate->format('Y-m-d') : '',
    'end_date' => $endDate ? $endDate->format('Y-m-d') : '',
    'billing_cycle' => $billingCycle,
    'order_status' => $orderStatus,
    'payment_status' => $paymentStatus,
    'payment_method' => $paymentMethod,
];

$buildReportsUrl = static function (array $extra = []) use ($baseQuery): string {
    $query = array_merge($baseQuery, $extra);
    $query = array_filter($query, static fn($value) => $value !== '' && $value !== null);

    return url('admin/reports.php' . ($query === [] ? '' : '?' . http_build_query($query)));
};

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reports-orders-' . date('Ymd-His') . '.csv"');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        exit('Unable to generate CSV export.');
    }

    fputcsv($output, ['Order ID', 'Created At', 'Customer', 'Email', 'Plan', 'Domain', 'Billing Cycle', 'Amount', 'Payment Method', 'Payment Status', 'Order Status']);

    foreach ($orders as $order) {
        fputcsv($output, [
            $order['id'],
            $order['created_at'],
            $order['full_name'],
            $order['email'],
            $order['plan_name'],
            $order['domain_name'],
            $order['billing_cycle'],
            $order['amount'],
            $order['payment_method'],
            $order['payment_status'],
            $order['status'],
        ]);
    }

    fclose($output);
    exit;
}

include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="form-card reports-filter-shell digital-panel">
    <h2>Report filters</h2>
    <form method="get" class="reports-filters">
        <label>Date range
            <select name="range">
                <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="7d" <?= $range === '7d' ? 'selected' : '' ?>>Last 7 days</option>
                <option value="30d" <?= $range === '30d' ? 'selected' : '' ?>>Last 30 days</option>
                <option value="this_month" <?= $range === 'this_month' ? 'selected' : '' ?>>This month</option>
                <option value="last_month" <?= $range === 'last_month' ? 'selected' : '' ?>>Last month</option>
                <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom</option>
            </select>
        </label>
        <label>Start date
            <input type="date" name="start_date" value="<?= e($startDate ? $startDate->format('Y-m-d') : '') ?>">
        </label>
        <label>End date
            <input type="date" name="end_date" value="<?= e($endDate ? $endDate->format('Y-m-d') : '') ?>">
        </label>
        <label>Billing cycle
            <select name="billing_cycle">
                <option value="all" <?= $billingCycle === 'all' ? 'selected' : '' ?>>All</option>
                <option value="monthly" <?= $billingCycle === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                <option value="yearly" <?= $billingCycle === 'yearly' ? 'selected' : '' ?>>Yearly</option>
            </select>
        </label>
        <label>Order status
            <select name="order_status">
                <?php foreach ($allowedOrderStatuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $orderStatus === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Payment status
            <select name="payment_status">
                <?php foreach ($allowedPaymentStatuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $paymentStatus === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Payment method
            <select name="payment_method">
                <?php foreach ($allowedPaymentMethods as $method): ?>
                    <option value="<?= e($method) ?>" <?= $paymentMethod === $method ? 'selected' : '' ?>><?= e(ucfirst($method)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="actions reports-filter-actions">
            <button type="submit">Apply</button>
            <a class="button secondary" href="<?= e(url('admin/reports.php')) ?>">Reset</a>
            <a class="button secondary" href="<?= e($buildReportsUrl(['export' => 'csv'])) ?>">Export CSV</a>
            <button type="button" class="secondary" onclick="window.print()">Print</button>
        </div>
    </form>
</div>

<div class="admin-stats reports-kpis">
    <div class="card digital-panel">
        <span class="stat-icon"><?= icon('reports') ?></span>
        <strong><?= e((string) $totalOrders) ?></strong>
        <p>Total orders</p>
    </div>
    <div class="card digital-panel">
        <span class="stat-icon"><?= icon('accounting') ?></span>
        <strong><?= e(site_currency_symbol()) ?><?= e(number_format($totalRevenue, 2)) ?></strong>
        <p>Total revenue</p>
    </div>
    <div class="card digital-panel">
        <span class="stat-icon"><?= icon('invoices') ?></span>
        <strong><?= e(site_currency_symbol()) ?><?= e(number_format($paidRevenue, 2)) ?></strong>
        <p>Paid revenue</p>
    </div>
    <div class="card digital-panel">
        <span class="stat-icon"><?= icon('tasks') ?></span>
        <strong><?= e((string) $paidCount) ?></strong>
        <p>Paid orders</p>
    </div>
    <div class="card digital-panel">
        <span class="stat-icon"><?= icon('insights') ?></span>
        <strong><?= e(number_format($conversionRate, 2)) ?>%</strong>
        <p>Payment conversion</p>
    </div>
</div>

<div class="reports-grid">
    <div class="table-card reports-card digital-panel">
        <h3>Revenue by plan</h3>
        <table>
            <thead>
                <tr>
                    <th>Plan</th>
                    <th>Orders</th>
                    <th>Total amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($planStats === []): ?>
                    <tr>
                        <td colspan="3">No plan data for selected filters.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($planStats as $row): ?>
                    <tr>
                        <td><?= e($row['plan_name']) ?></td>
                        <td><?= e($row['order_count']) ?></td>
                        <td><?= e(site_currency_symbol()) ?><?= e(number_format((float) $row['total_amount'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="table-card reports-card digital-panel">
        <h3>Status matrix</h3>
        <table>
            <thead>
                <tr>
                    <th>Order status</th>
                    <th>Payment status</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($statusStats === []): ?>
                    <tr>
                        <td colspan="3">No status data for selected filters.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($statusStats as $row): ?>
                    <tr>
                        <td><?= e($row['status']) ?></td>
                        <td><?= e($row['payment_status']) ?></td>
                        <td><?= e($row['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="reports-grid">
    <div class="card reports-card">
        <h3>Collection summary</h3>
        <p class="report-summary-line">Paid orders: <strong><?= e((string) $paidCount) ?></strong></p>
        <p class="report-summary-line">Unpaid orders: <strong><?= e((string) $unpaidCount) ?></strong></p>
        <p class="report-summary-line">Pending amount: <strong><?= e(site_currency_symbol()) ?><?= e(number_format($pendingRevenue, 2)) ?></strong></p>
        <p class="report-summary-line">Filtered period: <strong><?= e($startDate ? $startDate->format('Y-m-d') : 'Any') ?> to <?= e($endDate ? $endDate->format('Y-m-d') : 'Any') ?></strong></p>
    </div>

    <div class="table-card reports-card">
        <h3>Contact intake</h3>
        <table>
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($contactSummary === []): ?>
                    <tr>
                        <td colspan="2">No contact records for selected period.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($contactSummary as $row): ?>
                    <tr>
                        <td><?= e($row['status']) ?></td>
                        <td><?= e($row['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="table-card">
    <h2>Orders in selected report scope</h2>
    <p>Showing <?= e((string) $totalOrders) ?> order(s) matching current filters.</p>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Plan</th>
                <th>Domain</th>
                <th>Amount</th>
                <th>Payment</th>
                <th>Order status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($orders === []): ?>
                <tr>
                    <td colspan="9">No orders found for current report filters.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td>#<?= e((int) $order['id']) ?></td>
                    <td><?= e($order['created_at']) ?></td>
                    <td><?= e($order['full_name']) ?><br><small><?= e($order['email']) ?></small></td>
                    <td><?= e($order['plan_name']) ?><br><small><?= e($order['billing_cycle']) ?></small></td>
                    <td><?= e($order['domain_name']) ?></td>
                    <td><?= e(site_currency_symbol()) ?><?= e(number_format((float) $order['amount'], 2)) ?></td>
                    <td>
                        <span class="tag"><?= e($order['payment_method']) ?></span>
                        <div><small><?= e($order['payment_status']) ?></small></div>
                    </td>
                    <td><span class="tag"><?= e($order['status']) ?></span></td>
                    <td>
                        <div class="actions" style="margin-top:0;">
                            <a class="button secondary" href="<?= e(url('invoice.php?id=' . (int) $order['id'])) ?>">Invoice</a>
                            <a class="button secondary" href="<?= e(url('admin/orders.php?edit=' . (int) $order['id'])) ?>">Edit</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
