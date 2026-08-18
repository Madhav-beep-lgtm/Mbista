<?php
declare(strict_types=1);

/**
 * Stripe Payment Gateway
 *
 * Handles Stripe payment processing
 * WARNING: Stripe uses FX rates and fees apply for non-USD transactions
 * Recommended for international payments only
 */
class StripeGateway extends PaymentGateway
{
    private const API_URL = 'https://api.stripe.com/v1';

    public function initiatePayment(
        int $invoiceId,
        float $amount,
        string $currency,
        array $customerData
    ): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'Stripe is not enabled'];
        }

        $secretKey = $this->config['secret_key'] ?? null;
        if (empty($secretKey)) {
            return ['success' => false, 'error' => 'Stripe secret key not configured'];
        }

        // Stripe prefers USD - check supported currency
        $supportedCurrency = $this->config['supported_currency'] ?? 'USD';
        if ($currency !== $supportedCurrency) {
            return [
                'success' => false,
                'error' => "Stripe configured for $supportedCurrency only. Currency mismatch: $currency"
            ];
        }

        $transactionId = "JO-" . $invoiceId . "-" . time();
        $amountCents = (int) ($amount * 100); // Stripe uses cents

        try {
            // Create payment intent
            $response = $this->makeRequest('POST', self::API_URL . '/payment_intents', [
                'amount' => $amountCents,
                'currency' => strtolower($supportedCurrency),
                'client_secret_metadata' => $transactionId,
                'description' => "Invoice Payment #$invoiceId",
                'statement_descriptor' => 'Invoice Payment',
                'metadata' => [
                    'invoice_id' => $invoiceId,
                    'transaction_id' => $transactionId
                ]
            ], ['Authorization' => 'Bearer ' . $secretKey]);

            if (empty($response['client_secret'])) {
                return ['success' => false, 'error' => 'Failed to create payment intent'];
            }

            $this->recordPayment(
                $invoiceId,
                $response['id'],
                $amount / 100,
                'pending',
                'stripe',
                $response
            );

            return [
                'success' => true,
                'client_secret' => $response['client_secret'],
                'payment_intent_id' => $response['id'],
                'publishable_key' => $this->config['publishable_key'],
                'transaction_id' => $transactionId
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function verifyPayment(array $data): array
    {
        $paymentIntentId = $data['payment_intent_id'] ?? null;

        if (empty($paymentIntentId)) {
            return ['success' => false, 'error' => 'Missing payment intent ID'];
        }

        try {
            $response = $this->makeRequest(
                'GET',
                self::API_URL . '/payment_intents/' . $paymentIntentId,
                [],
                ['Authorization' => 'Bearer ' . ($this->config['secret_key'] ?? '')]
            );

            if ($response['status'] !== 'succeeded') {
                return ['success' => false, 'error' => 'Payment not succeeded'];
            }

            // Extract invoice ID from metadata
            $invoiceId = (int) ($response['metadata']['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                return ['success' => false, 'error' => 'Invalid invoice ID in metadata'];
            }

            $amount = (float) ($response['amount'] ?? 0) / 100;

            $this->recordPayment(
                $invoiceId,
                $paymentIntentId,
                $amount,
                'completed',
                'stripe',
                $response
            );

            return [
                'success' => true,
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'gateway_ref_id' => $paymentIntentId
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function processRefund(string $transactionId, float $amount): array
    {
        try {
            $amountCents = (int) ($amount * 100);

            $response = $this->makeRequest(
                'POST',
                self::API_URL . '/refunds',
                [
                    'payment_intent' => $transactionId,
                    'amount' => $amountCents
                ],
                ['Authorization' => 'Bearer ' . ($this->config['secret_key'] ?? '')]
            );

            if ($response['status'] !== 'succeeded') {
                return ['success' => false, 'error' => 'Refund failed'];
            }

            return ['success' => true, 'refund_id' => $response['id']];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getPaymentStatus(string $transactionId): string
    {
        try {
            $response = $this->makeRequest(
                'GET',
                self::API_URL . '/payment_intents/' . $transactionId,
                [],
                ['Authorization' => 'Bearer ' . ($this->config['secret_key'] ?? '')]
            );

            return $response['status'] ?? 'unknown';
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
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
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
            throw new Exception("Stripe request failed with status $httpCode: $response");
        }

        return json_decode($response, true);
    }
}
