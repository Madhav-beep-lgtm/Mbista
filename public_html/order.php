<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$pageTitle = 'Order Hosting';
$selectedPlanId = (int) ($_GET['plan_id'] ?? 0);
$selectedPlan = $selectedPlanId ? plan_by_id($selectedPlanId) : null;
$plans = plans();
$user = current_user();
$companyIdForOrder = (int) (($user['company_id'] ?? 0) ?: current_company_id());
$paymentMode = (string) setting('payment_mode', 'manual');
$paymentLabel = (string) setting('payment_label', 'Manual payment / bank transfer');

if (!$selectedPlan && $plans !== []) {
    $selectedPlan = $plans[0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $planId = (int) ($_POST['plan_id'] ?? 0);
    $plan = plan_by_id($planId);
    $billingCycle = ($_POST['billing_cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $domainName = trim((string) ($_POST['domain_name'] ?? ''));
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'manual'));
    $transactionId = trim((string) ($_POST['transaction_id'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $allowedPaymentMethods = ['manual', 'stripe', 'paypal'];

    if (!$plan || $fullName === '' || $email === '' || $domainName === '') {
        flash('error', 'Please choose a plan and complete the required fields.');
        redirect('order.php');
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        flash('error', 'Please provide a valid email address.');
        redirect('order.php');
    }

    if (!preg_match('/^[a-z0-9][a-z0-9.-]+\.[a-z]{2,}$/i', $domainName)) {
        flash('error', 'Please provide a valid domain name (for example: example.com).');
        redirect('order.php');
    }

    if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
        $paymentMethod = 'manual';
    }

    $amount = (float) $plan['price'];
    if ($billingCycle === 'yearly') {
        $amount = $amount * 10;
    }

    $orderId = create_order([
        'company_id' => $companyIdForOrder > 0 ? $companyIdForOrder : null,
        'user_id' => $user['id'] ?? null,
        'plan_id' => $planId,
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'domain_name' => $domainName,
        'billing_cycle' => $billingCycle,
        'amount' => $amount,
        'payment_method' => $paymentMethod !== '' ? $paymentMethod : 'manual',
        'payment_status' => 'pending',
        'transaction_id' => $transactionId !== '' ? $transactionId : null,
        'notes' => $notes,
        'status' => 'pending',
    ]);

    $_SESSION['last_order_id'] = $orderId;

    flash('success', 'Your hosting request has been saved. We will review it shortly.');
    redirect('invoice.php?id=' . $orderId);
}

include __DIR__ . '/../app/views/partials/header.php';
?>
<section class="hero">
    <div class="container hero-panel">
        <div class="hero-grid">
            <div>
                <div class="kicker">Order request</div>
                <h1>Reserve a hosting plan for your client or business site.</h1>
                <p>Choose a package, enter the domain name, and submit the request. The order will be stored in MySQL for admin review.</p>
            </div>
            <div class="hero-card">
                <h3>Current selection</h3>
                <p><?= e($selectedPlan['name'] ?? 'Select a package') ?></p>
                <?php if ($selectedPlan): ?>
                    <div class="price"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $selectedPlan['price'], 2)) ?> <small>/ month</small></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container auth-grid">
        <div class="form-card">
            <h2>Request hosting</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Package
                    <select name="plan_id" required>
                        <?php foreach ($plans as $plan): ?>
                            <option value="<?= e((int) $plan['id']) ?>" <?= $selectedPlan && (int) $selectedPlan['id'] === (int) $plan['id'] ? 'selected' : '' ?>>
                                <?= e($plan['name']) ?> - <?= e(site_currency_symbol()) ?><?= e(number_format((float) $plan['price'], 2)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Billing cycle
                    <select name="billing_cycle">
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </label>
                <label>Full name<input type="text" name="full_name" value="<?= e($user['name'] ?? '') ?>" required></label>
                <label>Email<input type="email" name="email" value="<?= e($user['email'] ?? '') ?>" required></label>
                <label>Phone<input type="text" name="phone" value="<?= e($user['phone'] ?? '') ?>"></label>
                <label>Domain name<input type="text" name="domain_name" placeholder="example.com" required></label>
                <label>Payment method
                    <select name="payment_method" required>
                        <option value="manual" <?= $paymentMode === 'manual' ? 'selected' : '' ?>><?= e($paymentLabel) ?></option>
                        <option value="stripe">Stripe</option>
                        <option value="paypal">PayPal</option>
                    </select>
                </label>
                <label>Transaction / reference ID<input type="text" name="transaction_id" placeholder="Optional for manual or gateway payments"></label>
                <label>Notes<textarea name="notes" placeholder="Anything special about the site or migration?"></textarea></label>
                <button type="submit">Save order request</button>
            </form>
        </div>
        <div class="card">
            <h3>Order policy</h3>
            <p>This starter now stores payment method details on each order. You can keep it manual or wire it to a hosted checkout later.</p>
            <?php if ($paymentMode === 'manual'): ?>
                <p><strong><?= e(setting('bank_name', 'Bank')) ?></strong></p>
                <p><?= e(setting('bank_account_name', 'M.Bista Altiora Complete Hosting')) ?></p>
                <p><?= e(setting('bank_account_number', '0000000000000000')) ?></p>
                <p><?= e(setting('payment_note', 'After placing the order, send the transaction reference to support.')) ?></p>
            <?php endif; ?>
            <ul class="checklist">
                <li>Customer data stored in MySQL</li>
                <li>Printable invoice created after submission</li>
                <li>Works without a framework</li>
            </ul>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
