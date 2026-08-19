<?php
declare(strict_types=1);

/**
 * Abstract Payment Gateway
 *
 * Base class for all payment gateway implementations
 */
abstract class PaymentGateway
{
    protected int $companyId;
    protected array $config;

    public function __construct(int $companyId, array $config)
    {
        $this->companyId = $companyId;
        $this->config = $config;
    }

    /**
     * Initialize a payment for an invoice
     *
     * @param int $invoiceId
     * @param float $amount
     * @param string $currency
     * @param array $customerData Name, email, phone
     * @return array ['success' => bool, 'redirect_url' => string|null, 'error' => string|null]
     */
    abstract public function initiatePayment(
        int $invoiceId,
        float $amount,
        string $currency,
        array $customerData
    ): array;

    /**
     * Verify payment from callback
     *
     * @param array $data Payment gateway callback data
     * @return array ['success' => bool, 'invoice_id' => int, 'amount' => float, 'error' => string|null]
     */
    abstract public function verifyPayment(array $data): array;

    /**
     * Process refund
     *
     * @param string $transactionId Gateway transaction ID
     * @param float $amount
     * @return array ['success' => bool, 'error' => string|null]
     */
    abstract public function processRefund(string $transactionId, float $amount): array;

    /**
     * Get payment status
     */
    abstract public function getPaymentStatus(string $transactionId): string;

    /**
     * Store payment record
     */
    protected function recordPayment(
        int $invoiceId,
        string $gatewayRefId,
        float $amount,
        string $status,
        string $gateway,
        array $rawResponse = []
    ): bool {
        $stmt = db()->prepare(
            "INSERT INTO invoice_payments
             (company_id, invoice_id, payment_gateway, gateway_ref_id, amount, status, raw_response)
             VALUES (:cid, :iid, :gw, :ref, :amt, :status, :resp)
             ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = NOW()"
        );

        return $stmt->execute([
            'cid' => $this->companyId,
            'iid' => $invoiceId,
            'gw' => $gateway,
            'ref' => $gatewayRefId,
            'amt' => $amount,
            'status' => $status,
            'resp' => json_encode($rawResponse)
        ]);
    }

    /**
     * Log webhook event
     */
    protected function logWebhookEvent(
        string $gatewayId,
        string $eventType,
        array $payload,
        ?string $errorMessage = null
    ): bool {
        $stmt = db()->prepare(
            "INSERT INTO payment_webhook_events
             (company_id, gateway_id, event_type, payload, error_message, processed)
             VALUES (:cid, :gid, :type, :payload, :error, :processed)"
        );

        return $stmt->execute([
            'cid' => $this->companyId,
            'gid' => $gatewayId,
            'type' => $eventType,
            'payload' => json_encode($payload),
            'error' => $errorMessage,
            'processed' => $errorMessage === null ? 1 : 0
        ]);
    }

    /**
     * Get enabled status
     */
    public function isEnabled(): bool
    {
        return ($this->config['enabled'] ?? false) == 1 || ($this->config['enabled'] ?? false) === '1';
    }
}
