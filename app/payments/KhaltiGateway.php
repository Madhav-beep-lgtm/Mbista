<?php
declare(strict_types=1);

/**
 * Khalti Payment Gateway
 *
 * Handles Khalti payment processing
 * Khalti is popular for digital wallets in Nepal
 */
class KhaltiGateway extends PaymentGateway
{
    private const API_URL = 'https://khalti.com/api/payment/initiate/';
    private const VERIFY_URL = 'https://khalti.com/api/payment/verify/';

    public function initiatePayment(
        int $invoiceId,
        float $amount,
        string $currency,
        array $customerData
    ): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'Khalti is not enabled'];
        }

        $publicKey = $this->config['public_key'] ?? null;
        if (empty($publicKey)) {
            return ['success' => false, 'error' => 'Khalti public key not configured'];
        }

        $transactionId = "JO-" . $invoiceId . "-" . time();

        $payload = [
            'public_key' => $publicKey,
            'transaction_uuid' => $transactionId,
            'description' => "Invoice Payment #$invoiceId",
            'amount' => (int) ($amount * 100), // Khalti uses cents
            'customer_name' => $customerData['name'] ?? 'Customer',
            'customer_email' => $customerData['email'] ?? '',
            'customer_phone' => $customerData['phone'] ?? '',
            'return_url' => $this->config['return_url'] ?? app_url("/payments/khalti/return"),
            'website_url' => app_url('/')
        ];

        // Record payment
        $this->recordPayment($invoiceId, $transactionId, $amount, 'pending', 'khalti', $payload);

        return [
            'success' => true,
            'payload' => $payload,
            'transaction_id' => $transactionId
        ];
    }

    public function verifyPayment(array $data): array
    {
        $transactionId = $data['transaction_uuid'] ?? null;
        $token = $data['token'] ?? null;

        if (empty($transactionId) || empty($token)) {
            return ['success' => false, 'error' => 'Missing transaction data'];
        }

        try {
            $response = $this->makeRequest(
                'POST',
                self::VERIFY_URL,
                [
                    'token' => $token,
                    'transaction_uuid' => $transactionId
                ],
                ['Authorization' => 'Key ' . ($this->config['secret_key'] ?? '')]
            );

            if ($response['state']['name'] !== 'Completed') {
                return ['success' => false, 'error' => 'Payment not completed'];
            }

            // Extract invoice ID
            $parts = explode('-', $transactionId);
            $invoiceId = (int) ($parts[1] ?? 0);

            if ($invoiceId <= 0) {
                return ['success' => false, 'error' => 'Invalid invoice ID'];
            }

            $amount = (float) ($response['amount'] ?? 0) / 100;

            // Record successful payment
            $this->recordPayment(
                $invoiceId,
                $transactionId,
                $amount,
                'completed',
                'khalti',
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
        // Khalti requires manual refund processing
        return [
            'success' => false,
            'error' => 'Khalti refunds must be processed manually through merchant dashboard'
        ];
    }

    public function getPaymentStatus(string $transactionId): string
    {
        try {
            $response = $this->makeRequest('GET', self::VERIFY_URL . $transactionId);
            return $response['state']['name'] ?? 'unknown';
        } catch (Exception $e) {
            return 'error';
        }
    }

    private function makeRequest(
        string $method,
        string $url,
        array $data = [],
        array $headers = []
    ): ?array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers['Content-Type'] = 'application/json';
        }

        if (!empty($headers)) {
            $headerArray = [];
            foreach ($headers as $k => $v) {
                $headerArray[] = "$k: $v";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Khalti request failed with status $httpCode");
        }

        return json_decode($response, true);
    }
}
