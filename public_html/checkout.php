<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$orderId = (int) ($_GET['id'] ?? 0);
$order = $orderId > 0 ? order_by_id($orderId) : null;

if (!$order || !order_can_be_viewed($order)) {
    http_response_code(404);
    exit('Checkout link not available.');
}

if (($order['payment_status'] ?? 'pending') === 'paid') {
    flash('success', 'This order is already marked as paid.');
    redirect('invoice.php?id=' . (int) $order['id']);
}

$requestedMethod = strtolower(trim((string) ($_GET['method'] ?? '')));
$allowedOnlineMethods = ['stripe', 'paypal'];
$orderMethod = strtolower((string) ($order['payment_method'] ?? 'manual'));
$settingsMode = strtolower((string) setting('payment_mode', 'manual'));

$method = $orderMethod;

if (in_array($requestedMethod, $allowedOnlineMethods, true)) {
    $method = $requestedMethod;
} elseif (!in_array($method, $allowedOnlineMethods, true) && in_array($settingsMode, $allowedOnlineMethods, true)) {
    $method = $settingsMode;
}

if (!in_array($method, $allowedOnlineMethods, true)) {
    flash('error', 'Online checkout is not enabled for this order. Use manual payment instructions on the invoice.');
    redirect('invoice.php?id=' . (int) $order['id']);
}

$checkoutUrl = $method === 'stripe'
    ? trim((string) setting('stripe_checkout_url', ''))
    : trim((string) setting('paypal_checkout_url', ''));

if ($checkoutUrl === '' || filter_var($checkoutUrl, FILTER_VALIDATE_URL) === false) {
    flash('error', ucfirst($method) . ' checkout is not configured yet. Please contact support.');
    redirect('invoice.php?id=' . (int) $order['id']);
}

if ($orderMethod !== $method) {
    $stmt = db()->prepare('UPDATE orders SET payment_method = :payment_method WHERE id = :id');
    $stmt->execute([
        'payment_method' => $method,
        'id' => (int) $order['id'],
    ]);
}

header('Location: ' . $checkoutUrl, true, 302);
exit;
