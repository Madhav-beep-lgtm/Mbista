<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();
require_company_context();
$pageTitle = 'Orders';
$currentAdmin = current_user();
$company = current_company();
$companyId = (int) ($company['id'] ?? 0);
$allowedStatuses = ['pending', 'confirmed', 'processing', 'completed', 'cancelled'];
$allowedPaymentStatuses = ['pending', 'paid', 'failed', 'refunded'];
$allowedPaymentMethods = ['manual', 'stripe', 'paypal'];
$allowedBillingCycles = ['monthly', 'yearly'];
$perPageOptions = [10, 20, 50, 100];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['delete_id'] ?? 0);

        if ($deleteId <= 0) {
            flash('error', 'Invalid order selected for deletion.');
            redirect('admin/orders.php');
        }

        $stmt = db()->prepare('DELETE FROM orders WHERE id = :id AND company_id = :company_id');
        $stmt->execute([
            'id' => $deleteId,
            'company_id' => $companyId,
        ]);
        flash('success', 'Order deleted.');
        redirect('admin/orders.php');
    }

    if ($action === 'quick_update') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'pending');
        $paymentStatus = (string) ($_POST['payment_status'] ?? 'pending');

        if ($orderId <= 0 || !in_array($status, $allowedStatuses, true) || !in_array($paymentStatus, $allowedPaymentStatuses, true)) {
            flash('error', 'Invalid order update request.');
            redirect('admin/orders.php');
        }

        if ($paymentStatus === 'paid' && $status === 'pending') {
            $status = 'confirmed';
        }

        $stmt = db()->prepare('UPDATE orders SET status = :status, payment_status = :payment_status WHERE id = :id AND company_id = :company_id');
        $stmt->execute([
            'status' => $status,
            'payment_status' => $paymentStatus,
            'id' => $orderId,
            'company_id' => $companyId,
        ]);
        if ($stmt->rowCount() === 0) {
            flash('error', 'Order not found in selected company context.');
            redirect('admin/orders.php');
        }

        if ($paymentStatus === 'paid') {
            auto_post_order_payment_voucher($orderId, (int) ($currentAdmin['id'] ?? 0));
        }
        if ($paymentStatus === 'refunded') {
            auto_post_order_refund_voucher($orderId, (int) ($currentAdmin['id'] ?? 0));
        }

        flash('success', 'Order updated.');
        redirect('admin/orders.php');
    }

    if ($action === 'create' || $action === 'update') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $planId = (int) ($_POST['plan_id'] ?? 0);
        $userIdRaw = trim((string) ($_POST['user_id'] ?? ''));
        $userId = $userIdRaw === '' ? null : (int) $userIdRaw;
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $domainName = trim((string) ($_POST['domain_name'] ?? ''));
        $billingCycle = (string) ($_POST['billing_cycle'] ?? 'monthly');
        $amountRaw = trim((string) ($_POST['amount'] ?? '0'));
        $paymentMethod = (string) ($_POST['payment_method'] ?? 'manual');
        $paymentStatus = (string) ($_POST['payment_status'] ?? 'pending');
        $transactionId = trim((string) ($_POST['transaction_id'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'pending');
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($planId <= 0 || $fullName === '' || $email === '' || $domainName === '') {
            flash('error', 'Plan, full name, email, and domain are required.');
            redirect('admin/orders.php');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please provide a valid email address.');
            redirect('admin/orders.php');
        }

        if (!preg_match('/^[a-z0-9][a-z0-9.-]+\.[a-z]{2,}$/i', $domainName)) {
            flash('error', 'Please provide a valid domain name.');
            redirect('admin/orders.php');
        }

        if (!in_array($billingCycle, $allowedBillingCycles, true)) {
            flash('error', 'Invalid billing cycle.');
            redirect('admin/orders.php');
        }

        if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
            flash('error', 'Invalid payment method.');
            redirect('admin/orders.php');
        }

        if (!in_array($paymentStatus, $allowedPaymentStatuses, true)) {
            flash('error', 'Invalid payment status.');
            redirect('admin/orders.php');
        }

        if (!in_array($status, $allowedStatuses, true)) {
            flash('error', 'Invalid order status.');
            redirect('admin/orders.php');
        }

        if (!is_numeric($amountRaw)) {
            flash('error', 'Amount must be a valid number.');
            redirect('admin/orders.php');
        }

        $amount = round((float) $amountRaw, 2);
        if ($amount < 0) {
            flash('error', 'Amount cannot be negative.');
            redirect('admin/orders.php');
        }

        if ($userId !== null) {
            $userCheck = db()->prepare('SELECT id FROM users WHERE id = :id AND company_id = :company_id LIMIT 1');
            $userCheck->execute([
                'id' => $userId,
                'company_id' => $companyId,
            ]);
            if (!$userCheck->fetch()) {
                flash('error', 'Selected user does not exist in the current company.');
                redirect('admin/orders.php');
            }
        }

        $planCheck = db()->prepare('SELECT id FROM plans WHERE id = :id LIMIT 1');
        $planCheck->execute(['id' => $planId]);
        if (!$planCheck->fetch()) {
            flash('error', 'Selected plan does not exist.');
            redirect('admin/orders.php');
        }

        if ($paymentStatus === 'paid' && $status === 'pending') {
            $status = 'confirmed';
        }

        if ($action === 'create') {
            $stmt = db()->prepare('INSERT INTO orders (company_id, user_id, plan_id, full_name, email, phone, domain_name, billing_cycle, amount, payment_method, payment_status, transaction_id, status, notes) VALUES (:company_id, :user_id, :plan_id, :full_name, :email, :phone, :domain_name, :billing_cycle, :amount, :payment_method, :payment_status, :transaction_id, :status, :notes)');
            $stmt->execute([
                'company_id' => $companyId,
                'user_id' => $userId,
                'plan_id' => $planId,
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'domain_name' => $domainName,
                'billing_cycle' => $billingCycle,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'transaction_id' => $transactionId !== '' ? $transactionId : null,
                'status' => $status,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            $newOrderId = (int) db()->lastInsertId();
            if ($paymentStatus === 'paid') {
                auto_post_order_payment_voucher($newOrderId, (int) ($currentAdmin['id'] ?? 0));
            }
            if ($paymentStatus === 'refunded') {
                auto_post_order_refund_voucher($newOrderId, (int) ($currentAdmin['id'] ?? 0));
            }

            flash('success', 'Order created.');
            redirect('admin/orders.php');
        }

        if ($orderId <= 0) {
            flash('error', 'Invalid order selected for update.');
            redirect('admin/orders.php');
        }

        $stmt = db()->prepare('UPDATE orders SET user_id = :user_id, plan_id = :plan_id, full_name = :full_name, email = :email, phone = :phone, domain_name = :domain_name, billing_cycle = :billing_cycle, amount = :amount, payment_method = :payment_method, payment_status = :payment_status, transaction_id = :transaction_id, status = :status, notes = :notes WHERE id = :id AND company_id = :company_id');
        $stmt->execute([
            'id' => $orderId,
            'company_id' => $companyId,
            'user_id' => $userId,
            'plan_id' => $planId,
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'domain_name' => $domainName,
            'billing_cycle' => $billingCycle,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentStatus,
            'transaction_id' => $transactionId !== '' ? $transactionId : null,
            'status' => $status,
            'notes' => $notes !== '' ? $notes : null,
        ]);

        if ($paymentStatus === 'paid') {
            auto_post_order_payment_voucher($orderId, (int) ($currentAdmin['id'] ?? 0));
        }
        if ($paymentStatus === 'refunded') {
            auto_post_order_refund_voucher($orderId, (int) ($currentAdmin['id'] ?? 0));
        }

        flash('success', 'Order details updated.');
        redirect('admin/orders.php');
    }

    flash('error', 'Unsupported action.');

    redirect('admin/orders.php');
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$paymentStatusFilter = trim((string) ($_GET['payment_status'] ?? ''));
$paymentMethodFilter = trim((string) ($_GET['payment_method'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPageInput = (int) ($_GET['per_page'] ?? 20);
$perPage = in_array($perPageInput, $perPageOptions, true) ? $perPageInput : 20;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(o.full_name LIKE :search1 OR o.email LIKE :search2 OR o.domain_name LIKE :search3 OR o.transaction_id LIKE :search4 OR p.name LIKE :search5)';
    $params['search1'] = $params['search2'] = $params['search3'] = $params['search4'] = $params['search5'] = '%' . $search . '%';
}

if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = 'o.status = :status_filter';
    $params['status_filter'] = $statusFilter;
}

if ($paymentStatusFilter !== '' && in_array($paymentStatusFilter, $allowedPaymentStatuses, true)) {
    $where[] = 'o.payment_status = :payment_status_filter';
    $params['payment_status_filter'] = $paymentStatusFilter;
}

if ($paymentMethodFilter !== '' && in_array($paymentMethodFilter, $allowedPaymentMethods, true)) {
    $where[] = 'o.payment_method = :payment_method_filter';
    $params['payment_method_filter'] = $paymentMethodFilter;
}

$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$whereSql = $whereSql === '' ? ' WHERE o.company_id = :company_id' : $whereSql . ' AND o.company_id = :company_id';
$params['company_id'] = $companyId;

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportSql = 'SELECT o.id, o.full_name, o.email, o.phone, o.domain_name, p.name AS plan_name, o.billing_cycle, o.amount, o.payment_method, o.payment_status, o.transaction_id, o.status, o.created_at FROM orders o INNER JOIN plans p ON p.id = o.plan_id' . $whereSql . ' ORDER BY o.created_at DESC';
    $exportStmt = db()->prepare($exportSql);
    foreach ($params as $key => $value) {
        $exportStmt->bindValue(':' . $key, $value);
    }
    $exportStmt->execute();
    $rows = $exportStmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders-export-' . date('Ymd-His') . '.csv"');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        exit('Unable to generate CSV export.');
    }

    fputcsv($output, ['Order ID', 'Customer', 'Email', 'Phone', 'Domain', 'Plan', 'Billing Cycle', 'Amount', 'Payment Method', 'Payment Status', 'Transaction ID', 'Order Status', 'Created At']);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['id'],
            $row['full_name'],
            $row['email'],
            $row['phone'],
            $row['domain_name'],
            $row['plan_name'],
            $row['billing_cycle'],
            $row['amount'],
            $row['payment_method'],
            $row['payment_status'],
            $row['transaction_id'],
            $row['status'],
            $row['created_at'],
        ]);
    }

    fclose($output);
    exit;
}

$countSql = 'SELECT COUNT(*) FROM orders o INNER JOIN plans p ON p.id = o.plan_id' . $whereSql;
$countStmt = db()->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue(':' . $key, $value);
}
$countStmt->execute();
$totalOrders = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalOrders / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = 'SELECT o.*, p.name AS plan_name, u.name AS user_name FROM orders o INNER JOIN plans p ON p.id = o.plan_id LEFT JOIN users u ON u.id = o.user_id' . $whereSql . ' ORDER BY o.created_at DESC LIMIT :limit OFFSET :offset';
$stmt = db()->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll();

$plans = db()->query('SELECT id, name FROM plans ORDER BY sort_order ASC, id ASC')->fetchAll();
$usersStmt = db()->prepare('SELECT id, name, email FROM users WHERE company_id = :company_id ORDER BY name ASC, email ASC');
$usersStmt->execute(['company_id' => $companyId]);
$users = $usersStmt->fetchAll();

$editOrderId = (int) ($_GET['edit'] ?? 0);
$editOrder = null;
if ($editOrderId > 0) {
    $editStmt = db()->prepare('SELECT * FROM orders WHERE id = :id AND company_id = :company_id LIMIT 1');
    $editStmt->execute([
        'id' => $editOrderId,
        'company_id' => $companyId,
    ]);
    $editOrder = $editStmt->fetch() ?: null;
}

$baseQuery = [
    'q' => $search,
    'status' => $statusFilter,
    'payment_status' => $paymentStatusFilter,
    'payment_method' => $paymentMethodFilter,
    'per_page' => (string) $perPage,
];

$buildAdminOrdersUrl = static function (array $extra = []) use ($baseQuery): string {
    $query = array_merge($baseQuery, $extra);
    $query = array_filter($query, static fn($value) => $value !== '' && $value !== null);

    return url('admin/orders.php' . ($query === [] ? '' : '?' . http_build_query($query)));
};

include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="form-card admin-orders-card">
    <h2>Create order</h2>
    <form method="post" class="admin-orders-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <label>Plan
            <select name="plan_id" required>
                <option value="">Select plan</option>
                <?php foreach ($plans as $plan): ?>
                    <option value="<?= e((int) $plan['id']) ?>"><?= e($plan['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>User (optional)
            <select name="user_id">
                <option value="">Guest / not linked</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= e((int) $user['id']) ?>"><?= e($user['name']) ?> (<?= e($user['email']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Full name<input type="text" name="full_name" required></label>
        <label>Email<input type="email" name="email" required></label>
        <label>Phone<input type="text" name="phone"></label>
        <label>Domain<input type="text" name="domain_name" placeholder="example.com" required></label>
        <label>Billing cycle
            <select name="billing_cycle" required>
                <option value="monthly">Monthly</option>
                <option value="yearly">Yearly</option>
            </select>
        </label>
        <label>Amount<input type="number" name="amount" min="0" step="0.01" required></label>
        <label>Payment method
            <select name="payment_method" required>
                <?php foreach ($allowedPaymentMethods as $method): ?>
                    <option value="<?= e($method) ?>"><?= e(ucfirst($method)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Payment status
            <select name="payment_status" required>
                <?php foreach ($allowedPaymentStatuses as $paymentStatus): ?>
                    <option value="<?= e($paymentStatus) ?>" <?= $paymentStatus === 'pending' ? 'selected' : '' ?>><?= e(ucfirst($paymentStatus)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Transaction ID<input type="text" name="transaction_id"></label>
        <label>Order status
            <select name="status" required>
                <?php foreach ($allowedStatuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $status === 'pending' ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="admin-orders-form-full">Notes<textarea name="notes"></textarea></label>
        <div class="admin-orders-form-full actions">
            <button type="submit">Create order</button>
        </div>
    </form>
</div>

<?php if ($editOrder): ?>
    <div class="form-card admin-orders-card">
        <h2>Edit order #<?= e((int) $editOrder['id']) ?></h2>
        <form method="post" class="admin-orders-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="order_id" value="<?= e((int) $editOrder['id']) ?>">
            <label>Plan
                <select name="plan_id" required>
                    <?php foreach ($plans as $plan): ?>
                        <option value="<?= e((int) $plan['id']) ?>" <?= (int) $editOrder['plan_id'] === (int) $plan['id'] ? 'selected' : '' ?>><?= e($plan['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>User (optional)
                <select name="user_id">
                    <option value="" <?= $editOrder['user_id'] === null ? 'selected' : '' ?>>Guest / not linked</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= e((int) $user['id']) ?>" <?= (int) ($editOrder['user_id'] ?? 0) === (int) $user['id'] ? 'selected' : '' ?>><?= e($user['name']) ?> (<?= e($user['email']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Full name<input type="text" name="full_name" value="<?= e($editOrder['full_name']) ?>" required></label>
            <label>Email<input type="email" name="email" value="<?= e($editOrder['email']) ?>" required></label>
            <label>Phone<input type="text" name="phone" value="<?= e($editOrder['phone'] ?? '') ?>"></label>
            <label>Domain<input type="text" name="domain_name" value="<?= e($editOrder['domain_name']) ?>" required></label>
            <label>Billing cycle
                <select name="billing_cycle" required>
                    <?php foreach ($allowedBillingCycles as $billingCycle): ?>
                        <option value="<?= e($billingCycle) ?>" <?= $editOrder['billing_cycle'] === $billingCycle ? 'selected' : '' ?>><?= e(ucfirst($billingCycle)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Amount<input type="number" name="amount" min="0" step="0.01" value="<?= e((string) $editOrder['amount']) ?>" required></label>
            <label>Payment method
                <select name="payment_method" required>
                    <?php foreach ($allowedPaymentMethods as $method): ?>
                        <option value="<?= e($method) ?>" <?= ($editOrder['payment_method'] ?? 'manual') === $method ? 'selected' : '' ?>><?= e(ucfirst($method)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Payment status
                <select name="payment_status" required>
                    <?php foreach ($allowedPaymentStatuses as $paymentStatus): ?>
                        <option value="<?= e($paymentStatus) ?>" <?= ($editOrder['payment_status'] ?? 'pending') === $paymentStatus ? 'selected' : '' ?>><?= e(ucfirst($paymentStatus)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Transaction ID<input type="text" name="transaction_id" value="<?= e($editOrder['transaction_id'] ?? '') ?>"></label>
            <label>Order status
                <select name="status" required>
                    <?php foreach ($allowedStatuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $editOrder['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="admin-orders-form-full">Notes<textarea name="notes"><?= e($editOrder['notes'] ?? '') ?></textarea></label>
            <div class="admin-orders-form-full actions">
                <button type="submit">Save changes</button>
                <a class="button secondary" href="<?= e($buildAdminOrdersUrl()) ?>">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="form-card admin-orders-card">
    <h2>Search and filters</h2>
    <form method="get" class="admin-orders-filter-grid">
        <label>Search
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Customer, email, domain, transaction ID, plan">
        </label>
        <label>Status
            <select name="status">
                <option value="">All</option>
                <?php foreach ($allowedStatuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Payment status
            <select name="payment_status">
                <option value="">All</option>
                <?php foreach ($allowedPaymentStatuses as $paymentStatus): ?>
                    <option value="<?= e($paymentStatus) ?>" <?= $paymentStatusFilter === $paymentStatus ? 'selected' : '' ?>><?= e(ucfirst($paymentStatus)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Payment method
            <select name="payment_method">
                <option value="">All</option>
                <?php foreach ($allowedPaymentMethods as $method): ?>
                    <option value="<?= e($method) ?>" <?= $paymentMethodFilter === $method ? 'selected' : '' ?>><?= e(ucfirst($method)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Per page
            <select name="per_page">
                <?php foreach ($perPageOptions as $option): ?>
                    <option value="<?= e((string) $option) ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= e((string) $option) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="actions admin-orders-filter-actions">
            <button type="submit">Apply</button>
            <a class="button secondary" href="<?= e(url('admin/orders.php')) ?>">Reset</a>
            <a class="button secondary" href="<?= e($buildAdminOrdersUrl(['export' => 'csv', 'page' => null])) ?>">Export CSV</a>
        </div>
    </form>
</div>

<div class="table-card">
    <h2>All orders</h2>
    <p>Showing <?= e((string) count($orders)) ?> of <?= e((string) $totalOrders) ?> order(s).</p>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Customer</th>
                <th>Plan</th>
                <th>Domain</th>
                <th>Amount</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($orders === []): ?>
                <tr>
                    <td colspan="9">No orders found for the selected search and filters.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td>#<?= e((int) $order['id']) ?></td>
                    <td><?= e($order['full_name']) ?><br><small><?= e($order['email']) ?></small></td>
                    <td><?= e($order['plan_name']) ?></td>
                    <td><?= e($order['domain_name']) ?></td>
                    <td><?= e(site_currency_symbol()) ?><?= e(number_format((float) $order['amount'], 2)) ?></td>
                    <td>
                        <div><span class="tag"><?= e($order['payment_method'] ?? 'manual') ?></span></div>
                        <small><?= e($order['payment_status'] ?? 'pending') ?></small>
                        <?php if (!empty($order['transaction_id'])): ?>
                            <div><small><?= e($order['transaction_id']) ?></small></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="tag"><?= e($order['status']) ?></span></td>
                    <td><small><?= e($order['created_at']) ?></small></td>
                    <td>
                        <div style="margin-bottom:8px;" class="admin-orders-actions">
                            <a class="button secondary" href="<?= e(url('invoice.php?id=' . (int) $order['id'])) ?>">Invoice</a>
                            <a class="button secondary" href="<?= e($buildAdminOrdersUrl(['edit' => (int) $order['id']])) ?>">Edit</a>
                        </div>
                        <form method="post" class="actions" style="gap:8px; margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="quick_update">
                            <input type="hidden" name="order_id" value="<?= e((int) $order['id']) ?>">
                            <select name="status">
                                <?php foreach (['pending', 'confirmed', 'processing', 'completed', 'cancelled'] as $status): ?>
                                    <option value="<?= e($status) ?>" <?= $order['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="payment_status">
                                <?php foreach (['pending', 'paid', 'failed', 'refunded'] as $paymentStatus): ?>
                                    <option value="<?= e($paymentStatus) ?>" <?= ($order['payment_status'] ?? 'pending') === $paymentStatus ? 'selected' : '' ?>><?= e($paymentStatus) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="secondary">Save</button>
                        </form>
                        <form method="post" style="margin-top:8px;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="delete_id" value="<?= e((int) $order['id']) ?>">
                            <button type="submit" class="secondary">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="admin-orders-pagination">
        <?php if ($page > 1): ?>
            <a class="button secondary" href="<?= e($buildAdminOrdersUrl(['page' => $page - 1])) ?>">Previous</a>
        <?php endif; ?>
        <span>Page <?= e((string) $page) ?> of <?= e((string) $totalPages) ?></span>
        <?php if ($page < $totalPages): ?>
            <a class="button secondary" href="<?= e($buildAdminOrdersUrl(['page' => $page + 1])) ?>">Next</a>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
