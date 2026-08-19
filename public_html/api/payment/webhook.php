<?php
declare(strict_types=1);

/**
 * Payment Webhook Handler
 *
 * Receives callbacks from payment gateways and processes them
 * Routes: /api/payment/webhook/{gateway}
 */

require_once '../../../app/init.php';

// Get gateway from URL
$gateway = isset($_GET['gateway']) ? strtolower($_GET['gateway']) : '';
$body = file_get_contents('php://input');
$data = json_decode($body, true) ?? $_GET ?? $_POST ?? [];

// Log all webhook events for debugging
error_log("[Payment Webhook] Gateway: $gateway | Data: " . json_encode($data));

$response = ['success' => false, 'message' => 'Invalid request'];
$httpCode = 400;

try {
    switch ($gateway) {
        case 'esewa':
            $response = handleESewaWebhook($data);
            break;

        case 'khalti':
            $response = handleKhaltiWebhook($data);
            break;

        case 'fonepay':
            $response = handleFonepayWebhook($data);
            break;

        case 'stripe':
            $response = handleStripeWebhook($data);
            break;

        default:
            $response = ['success' => false, 'message' => 'Unknown gateway'];
            $httpCode = 404;
    }

} catch (Exception $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
    $httpCode = 500;
}

// Send response
http_response_code($httpCode);
header('Content-Type: application/json');
echo json_encode($response);
exit;

/**
 * Handle eSewa Webhook
 */
function handleESewaWebhook(array $data): array
{
    // eSewa sends verification data in query params on redirect
    $transactionId = $data['transaction_uuid'] ?? null;
    $status = $data['status'] ?? null;

    if (empty($transactionId)) {
        return ['success' => false, 'message' => 'Missing transaction_uuid'];
    }

    // Verify payment with eSewa
    $config = getPaymentConfig('esewa');
    if (empty($config)) {
        return ['success' => false, 'message' => 'eSewa not configured'];
    }

    $esewa = new ESewaGateway(1, $config);
    $result = $esewa->verifyPayment($data);

    if (!$result['success']) {
        return ['success' => false, 'message' => $result['error'] ?? 'Verification failed'];
    }

    // Update invoice payment status
    updateInvoicePayment($result['invoice_id'], 'completed');

    return ['success' => true, 'message' => 'Payment verified', 'invoice_id' => $result['invoice_id']];
}

/**
 * Handle Khalti Webhook
 */
function handleKhaltiWebhook(array $data): array
{
    // Khalti sends webhook verification data
    $signature = $data['signature'] ?? null;
    $payload = $data['payload'] ?? null;

    if (empty($signature) || empty($payload)) {
        return ['success' => false, 'message' => 'Missing signature or payload'];
    }

    $config = getPaymentConfig('khalti');
    if (empty($config)) {
        return ['success' => false, 'message' => 'Khalti not configured'];
    }

    // Verify signature
    $expectedSignature = hash_hmac('sha256', json_encode($payload), $config['secret_key'] ?? '');
    if (!hash_equals($expectedSignature, $signature)) {
        return ['success' => false, 'message' => 'Invalid signature'];
    }

    // Extract invoice ID from reference
    $reference = $payload['reference_id'] ?? null;
    if (empty($reference) || !preg_match('/^JO-(\d+)-/', $reference, $matches)) {
        return ['success' => false, 'message' => 'Invalid reference format'];
    }

    $invoiceId = (int) $matches[1];
    $amount = (float) ($payload['total_amount'] ?? 0);

    // Record payment
    recordWebhookPayment(
        $invoiceId,
        'khalti',
        $reference,
        $amount,
        'completed',
        $payload
    );

    return ['success' => true, 'message' => 'Payment processed', 'invoice_id' => $invoiceId];
}

/**
 * Handle Fonepay Webhook
 */
function handleFonepayWebhook(array $data): array
{
    $merchantId = $data['merchant_id'] ?? null;
    $transactionId = $data['transaction_id'] ?? null;
    $status = $data['status'] ?? null;

    if (empty($transactionId) || $status !== 'complete') {
        return ['success' => false, 'message' => 'Invalid or incomplete transaction'];
    }

    $config = getPaymentConfig('fonepay');
    if (empty($config) || $merchantId !== $config['merchant_id']) {
        return ['success' => false, 'message' => 'Invalid merchant'];
    }

    // Extract invoice ID
    if (!preg_match('/^JO-(\d+)-/', $transactionId, $matches)) {
        return ['success' => false, 'message' => 'Invalid transaction ID format'];
    }

    $invoiceId = (int) $matches[1];
    $amount = (float) ($data['amount'] ?? 0);

    recordWebhookPayment(
        $invoiceId,
        'fonepay',
        $transactionId,
        $amount,
        'completed',
        $data
    );

    return ['success' => true, 'message' => 'Payment processed', 'invoice_id' => $invoiceId];
}

/**
 * Handle Stripe Webhook
 */
function handleStripeWebhook(array $data): array
{
    $event = $data['type'] ?? null;

    if ($event !== 'payment_intent.succeeded') {
        // Log but don't fail other event types
        return ['success' => true, 'message' => 'Event ignored'];
    }

    $paymentIntent = $data['data']['object'] ?? [];
    $clientSecret = $paymentIntent['client_secret'] ?? null;
    $amount = (int) ($paymentIntent['amount'] ?? 0) / 100; // Stripe uses cents

    if (empty($clientSecret) || !preg_match('/^JO-(\d+)-/', $clientSecret, $matches)) {
        return ['success' => false, 'message' => 'Invalid client secret'];
    }

    $invoiceId = (int) $matches[1];

    recordWebhookPayment(
        $invoiceId,
        'stripe',
        $paymentIntent['id'] ?? '',
        $amount,
        'completed',
        $paymentIntent
    );

    return ['success' => true, 'message' => 'Payment processed', 'invoice_id' => $invoiceId];
}

/**
 * Get payment config for a gateway
 */
function getPaymentConfig(string $gateway): ?array
{
    $stmt = db()->prepare(
        "SELECT config_json FROM payment_gateway_config
         WHERE company_id = 1 AND gateway_id = :gateway"
    );
    $stmt->execute(['gateway' => $gateway]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? json_decode($row['config_json'], true) : null;
}

/**
 * Update invoice payment status
 */
function updateInvoicePayment(int $invoiceId, string $status): bool
{
    $stmt = db()->prepare(
        "UPDATE invoice_payments SET status = :status, updated_at = NOW()
         WHERE invoice_id = :iid AND status != 'completed'"
    );

    return $stmt->execute([
        'iid' => $invoiceId,
        'status' => $status
    ]);
}

/**
 * Record a webhook payment
 */
function recordWebhookPayment(
    int $invoiceId,
    string $gateway,
    string $refId,
    float $amount,
    string $status,
    array $rawResponse
): void {
    $stmt = db()->prepare(
        "INSERT INTO invoice_payments
         (company_id, invoice_id, payment_gateway, gateway_ref_id, amount, status, raw_response)
         VALUES (1, :iid, :gw, :ref, :amt, :status, :resp)
         ON DUPLICATE KEY UPDATE status = VALUES(status)"
    );

    $stmt->execute([
        'iid' => $invoiceId,
        'gw' => $gateway,
        'ref' => $refId,
        'amt' => $amount,
        'status' => $status,
        'resp' => json_encode($rawResponse)
    ]);
}
