<?php
declare(strict_types=1);

/**
 * Fonepay Payment Gateway
 *
 * Handles Fonepay payment processing
 * Fonepay is recommended for go-live with lowest fees
 */
class FonepayGateway extends PaymentGateway
{
    private const API_URL = 'https://app.fonepay.com/api/payment/initiate';
    private const VERIFY_URL = 'https://app.fonepay.com/api/payment/status';

    public function initiatePayment(
        int $invoiceId,
        float $amount,
        string $currency,
        array $customerData
    ): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'Fonepay is not enabled'];
        }

        $merchantId = $this->config['merchant_id'] ?? null;
        if (empty($merchantId)) {
            return ['success' => false, 'error' => 'Fonepay merchant ID not configured'];
        }

        $transactionId = "JO-" . $invoiceId . "-" . time();

        $payload = [
            'merchant_id' => $merchantId,
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'currency' => $currency,
            'description' => "Invoice Payment #$invoiceId",
            'customer_name' => $customerData['name'] ?? 'Customer',
            'customer_email' => $customerData['email'] ?? '',
            'customer_phone' => $customerData['phone'] ?? '',
            'return_url' => $this->config['success_url'] ?? app_url("/payments/fonepay/return"),
        ];

        // Generate signature
        $payload['signature'] = $this->createSignature($payload);

        $this->recordPayment($invoiceId, $transactionId, $amount, 'pending', 'fonepay', $payload);

        return [
            'success' => true,
            'payload' => $payload,
            'transaction_id' => $transactionId
        ];
    }

    public function verifyPayment(array $data): array
    {
        $transactionId = $data['transaction_id'] ?? null;
        $status = $data['status'] ?? null;

        if (empty($transactionId) || $status !== 'complete') {
            return ['success' => false, 'error' => 'Payment not complete'];
        }

        try {
            // Verify with Fonepay
            $response = $this->makeRequest('POST', self::VERIFY_URL, [
                'merchant_id' => $this->config['merchant_id'],
                'transaction_id' => $transactionId
            ]);

            if ($response['status'] !== 'completed') {
                return ['success' => false, 'error' => 'Verification failed'];
            }

            // Extract invoice ID
            $parts = explode('-', $transactionId);
            $invoiceId = (int) ($parts[1] ?? 0);

            if ($invoiceId <= 0) {
                return ['success' => false, 'error' => 'Invalid invoice ID'];
            }

            $amount = (float) ($response['amount'] ?? 0);

            $this->recordPayment(
                $invoiceId,
                $transactionId,
                $amount,
                'completed',
                'fonepay',
                $response
            );

            return [
                'success' => true,
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'gateway_ref_id' => $transactionId
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function processRefund(string $transactionId, float $amount): array
    {
        // Fonepay refund processing
        // This would require additional API endpoint
        return [
            'success' => false,
            'error' => 'Fonepay refunds require manual processing'
        ];
    }

    public function getPaymentStatus(string $transactionId): string
    {
        try {
            $response = $this->makeRequest('POST', self::VERIFY_URL, [
                'merchant_id' => $this->config['merchant_id'],
                'transaction_id' => $transactionId
            ]);

            return $response['status'] ?? 'unknown';
        } catch (Exception $e) {
            return 'error';
        }
    }

    private function createSignature(array $payload): string
    {
        $secret = $this->config['secret_key'] ?? '';
        $message = $payload['merchant_id'] . '|' .
                   $payload['transaction_id'] . '|' .
                   $payload['amount'];

        return hash_hmac('sha256', $message, $secret);
    }

    private function makeRequest(string $method, string $url, array $data = []): ?array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Fonepay request failed with status $httpCode");
        }

        return json_decode($response, true);
    }
}
