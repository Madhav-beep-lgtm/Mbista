<?php
declare(strict_types=1);

/**
 * eSewa Payment Gateway
 *
 * Handles eSewa payment processing
 * eSewa is the most commonly used payment gateway in Nepal
 */
class ESewaGateway extends PaymentGateway
{
    private const API_URL = 'https://eSewa.com.np/api/epay/main/v2/form';
    private const VERIFY_URL = 'https://eSewa.com.np/api/epay/transaction/status/';

    public function initiatePayment(
        int $invoiceId,
        float $amount,
        string $currency,
        array $customerData
    ): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'eSewa is not enabled'];
        }

        $merchantCode = $this->config['merchant_code'] ?? null;
        if (empty($merchantCode)) {
            return ['success' => false, 'error' => 'eSewa merchant code not configured'];
        }

        // Generate unique transaction ID
        $transactionId = "JO-" . $invoiceId . "-" . time();

        // Create payment link
        $params = [
            'amount' => $amount,
            'merchant_code' => $merchantCode,
            'success_url' => $this->config['success_url'] ?? app_url('/payments/esewa/success'),
            'failure_url' => $this->config['failure_url'] ?? app_url('/payments/esewa/failure'),
            'signed_field_names' => 'total_amount,transaction_uuid,product_code',
            'transaction_uuid' => $transactionId,
            'product_code' => 'INVOICE',
            'product_service_charge' => 0,
            'product_delivery_charge' => 0,
            'tax_amount' => 0,
            'total_amount' => $amount,
        ];

        // Create signature
        $signature = $this->createSignature($params);
        $params['signature'] = $signature;

        // Build form
        $formHtml = $this->buildPaymentForm(self::API_URL, $params);

        // Store reference
        $this->recordPayment(
            $invoiceId,
            $transactionId,
            $amount,
            'pending',
            'esewa',
            ['merchant_code' => $merchantCode]
        );

        return [
            'success' => true,
            'redirect_url' => self::API_URL,
            'form_data' => $params,
            'transaction_id' => $transactionId
        ];
    }

    public function verifyPayment(array $data): array
    {
        $transactionId = $data['transaction_uuid'] ?? null;
        $status = $data['status'] ?? null;

        if (empty($transactionId) || empty($status)) {
            return ['success' => false, 'error' => 'Missing transaction data'];
        }

        // Verify with eSewa
        try {
            $response = $this->makeRequest(
                'GET',
                self::VERIFY_URL . $transactionId,
                [],
                ['Authorization' => 'Bearer ' . ($this->config['api_key'] ?? '')]
            );

            if (!$response || $response['status'] !== 'COMPLETE') {
                return ['success' => false, 'error' => 'Payment verification failed'];
            }

            // Extract invoice ID from transaction ID (format: JO-{invoiceId}-{timestamp})
            $parts = explode('-', $transactionId);
            $invoiceId = (int) ($parts[1] ?? 0);

            if ($invoiceId <= 0) {
                return ['success' => false, 'error' => 'Invalid invoice ID in transaction'];
            }

            $amount = (float) ($response['total_amount'] ?? 0);

            // Record successful payment
            $this->recordPayment(
                $invoiceId,
                $transactionId,
                $amount,
                'completed',
                'esewa',
                $response
            );

            return [
                'success' => true,
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'gateway_ref_id' => $transactionId
            ];

        } catch (Exception $e) {
            $this->logWebhookEvent('esewa', 'verify_failure', $data, $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function processRefund(string $transactionId, float $amount): array
    {
        // eSewa requires manual refund processing
        // This is a placeholder for future automation
        return [
            'success' => false,
            'error' => 'eSewa refunds must be processed manually through merchant dashboard'
        ];
    }

    public function getPaymentStatus(string $transactionId): string
    {
        try {
            $response = $this->makeRequest(
                'GET',
                self::VERIFY_URL . $transactionId,
                [],
                ['Authorization' => 'Bearer ' . ($this->config['api_key'] ?? '')]
            );

            return $response['status'] ?? 'unknown';
        } catch (Exception $e) {
            return 'error';
        }
    }

    private function createSignature(array $params): string
    {
        $secret = $this->config['api_key'] ?? '';
        $fields = explode(',', $params['signed_field_names'] ?? '');

        $message = '';
        foreach ($fields as $field) {
            $message .= ($params[$field] ?? '') . ',';
        }
        $message = rtrim($message, ',');

        return base64_encode(hash_hmac('sha256', $message, $secret, true));
    }

    private function buildPaymentForm(string $url, array $data): string
    {
        $html = "<form id='esewa-form' action='" . e($url) . "' method='POST'>";
        foreach ($data as $key => $value) {
            $html .= "<input type='hidden' name='" . e($key) . "' value='" . e((string) $value) . "'>";
        }
        $html .= "</form><script>document.getElementById('esewa-form').submit();</script>";
        return $html;
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
            throw new Exception("eSewa request failed with status $httpCode");
        }

        return json_decode($response, true);
    }
}
