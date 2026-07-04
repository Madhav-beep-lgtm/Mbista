<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$orderId = (int) ($_GET['id'] ?? 0);
$order = $orderId > 0 ? order_by_id($orderId) : null;

if (!$order || !order_can_be_viewed($order)) {
    http_response_code(404);
    exit('Invoice not found.');
}

$payment = payment_settings();
$pageTitle = 'Invoice ' . order_invoice_number((int) $order['id']);
$orderMethod = strtolower((string) ($order['payment_method'] ?? 'manual'));
$settingsMode = strtolower((string) ($payment['mode'] ?? 'manual'));
$activeMethod = in_array($orderMethod, ['stripe', 'paypal'], true)
    ? $orderMethod
    : (in_array($settingsMode, ['stripe', 'paypal'], true) ? $settingsMode : 'manual');
$canPayOnline = in_array($activeMethod, ['stripe', 'paypal'], true);
$onlineCheckoutUrl = $activeMethod === 'stripe' ? $payment['stripe_checkout_url'] : $payment['paypal_checkout_url'];
$onlineCheckoutRoute = url('checkout.php?id=' . (int) $order['id'] . '&method=' . rawurlencode($activeMethod));
$companyLogoPath = (string) setting('company_logo_path', '');
$companySignaturePath = (string) setting('company_signature_path', '');
$companyStampPath = (string) setting('company_stamp_path', '');
include __DIR__ . '/../app/views/partials/header.php';
?>
<section class="section invoice-shell">
    <div class="container invoice-card">
        <div class="invoice-top">
            <div class="invoice-brand-block">
                <?php if ($companyLogoPath !== ''): ?>
                    <img class="document-logo" src="<?= e(url($companyLogoPath)) ?>" alt="<?= e(app_name()) ?> logo">
                <?php endif; ?>
                <div>
                <div class="badge">Invoice</div>
                <h1><?= e(order_invoice_number((int) $order['id'])) ?></h1>
                <p><?= e(app_name()) ?></p>
                </div>
            </div>
            <div class="actions invoice-actions">
                <button type="button" class="secondary" onclick="window.print()">Print invoice</button>
                <a class="button secondary" href="<?= e(url('dashboard.php')) ?>">Dashboard</a>
            </div>
        </div>

        <div class="invoice-grid">
            <div class="card">
                <h3>Customer</h3>
                <p><?= e($order['full_name']) ?></p>
                <p><?= e($order['email']) ?></p>
                <p><?= e($order['phone'] ?: 'No phone provided') ?></p>
            </div>
            <div class="card">
                <h3>Order details</h3>
                <p>Plan: <?= e($order['plan_name']) ?></p>
                <p>Domain: <?= e($order['domain_name']) ?></p>
                <p>Billing: <?= e($order['billing_cycle']) ?></p>
                <p>Status: <?= e($order['status']) ?></p>
            </div>
            <div class="card">
                <h3>Payment</h3>
                <p>Method: <?= e($order['payment_method'] ?? 'manual') ?></p>
                <p>Payment status: <?= e($order['payment_status'] ?? 'pending') ?></p>
                <?php if (!empty($order['transaction_id'])): ?>
                    <p>Reference: <?= e($order['transaction_id']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h3>Amount due</h3>
            <div class="invoice-amount"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $order['amount'], 2)) ?></div>
            <p>Use the payment instructions below or mark the invoice as paid from the admin panel.</p>
        </div>

        <div class="card">
            <h3>Payment instructions</h3>
            <?php if (!$canPayOnline): ?>
                <p><?= e($payment['label']) ?></p>
                <p><strong><?= e($payment['bank_name'] ?: 'Bank') ?></strong></p>
                <p><?= e($payment['bank_account_name'] ?: 'M.Bista Altiora Complete Hosting') ?></p>
                <p><?= e($payment['bank_account_number'] ?: '0000000000000000') ?></p>
                <p><?= e($payment['payment_note'] ?: 'After paying, send the reference number to support.') ?></p>
            <?php elseif ($canPayOnline && $onlineCheckoutUrl !== ''): ?>
                <p>Click the hosted checkout link to complete payment through <?= e(ucfirst($activeMethod)) ?>.</p>
                <a class="button" href="<?= e($onlineCheckoutRoute) ?>">Pay with <?= e(ucfirst($activeMethod)) ?></a>
            <?php else: ?>
                <p>Payment mode is set to <?= e($activeMethod) ?>, but no hosted checkout URL is configured yet.</p>
            <?php endif; ?>
        </div>

        <?php if ($companySignaturePath !== '' || $companyStampPath !== ''): ?>
            <div class="document-approval-card">
                <div>
                    <h3>Authorized approval</h3>
                    <p><?= e(app_name()) ?></p>
                </div>
                <div class="document-assets-row">
                    <?php if ($companySignaturePath !== ''): ?>
                        <div>
                            <img class="document-signature" src="<?= e(url($companySignaturePath)) ?>" alt="Authorized signature">
                            <span>Authorized signature</span>
                        </div>
                    <?php endif; ?>
                    <?php if ($companyStampPath !== ''): ?>
                        <div>
                            <img class="document-stamp" src="<?= e(url($companyStampPath)) ?>" alt="Company stamp">
                            <span>Company stamp</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
