<?php
declare(strict_types=1);

/**
 * Online payment gateways for client invoice collection.
 *
 * Design:
 * - Credentials live per company + provider in `payment_gateways`.
 * - Each client pay attempt is a `payment_intents` row (unique random token).
 * - On a provider-verified success we settle through the SAME machinery the
 *   admin "record payment" desk uses — insert an invoice_payment_requests row
 *   and call auto_post_invoice_payment_voucher() — so the receipt voucher, the
 *   client-books mirror, receivable resolution and idempotency are all reused,
 *   never re-implemented. Settlement is atomic: if the receipt voucher cannot
 *   post, the whole thing rolls back (no "paid" invoice with no cash booked).
 * - Callbacks are authenticated by the PROVIDER's signature / server lookup,
 *   never by our session/CSRF, and the amount is always re-verified server-side.
 */

/** Provider catalogue: label, presentment currency, credential fields, flow. */
function pg_providers(): array
{
    return [
        'esewa' => [
            'label' => 'eSewa',
            'currency' => 'NPR',
            'flow' => 'form_post',
            'fields' => ['merchant_code' => 'Product code (merchant code)', 'secret_key' => 'Secret key'],
            'hint' => 'From your eSewa merchant dashboard (ePay v2). Sandbox product code is EPAYTEST.',
        ],
        'khalti' => [
            'label' => 'Khalti',
            'currency' => 'NPR',
            'flow' => 'server_redirect',
            'fields' => ['secret_key' => 'Secret key (live/test)'],
            'hint' => 'Khalti merchant → Keys. Test keys work against a.khalti.com.',
        ],
        'fonepay' => [
            'label' => 'Fonepay',
            'currency' => 'NPR',
            'flow' => 'redirect',
            'fields' => ['merchant_code' => 'Merchant code (PID)', 'secret_key' => 'Shared secret key'],
            'hint' => 'Fonepay merchant onboarding provides the PID and shared secret.',
        ],
        'stripe' => [
            'label' => 'Stripe (international cards)',
            'currency' => 'USD',
            'flow' => 'server_redirect',
            'fields' => ['secret_key' => 'Secret key (sk_...)', 'public_key' => 'Publishable key (pk_...)'],
            'hint' => 'Charges in the Stripe currency (default USD) set in Advanced. Use for foreign-currency invoices.',
        ],
    ];
}

function pg_provider_label(string $provider): string
{
    return (string) (pg_providers()[$provider]['label'] ?? ucfirst($provider));
}

/** Live API endpoints per provider + mode. */
function pg_endpoints(string $provider, string $mode): array
{
    $test = $mode !== 'live';
    switch ($provider) {
        case 'esewa':
            return [
                'form' => $test ? 'https://rc-epay.esewa.com.np/api/epay/main/v2/form' : 'https://epay.esewa.com.np/api/epay/main/v2/form',
                'status' => $test ? 'https://rc.esewa.com.np/api/epay/transaction/status/' : 'https://epay.esewa.com.np/api/epay/transaction/status/',
            ];
        case 'khalti':
            return [
                'initiate' => $test ? 'https://a.khalti.com/api/v2/epayment/initiate/' : 'https://khalti.com/api/v2/epayment/initiate/',
                'lookup' => $test ? 'https://a.khalti.com/api/v2/epayment/lookup/' : 'https://khalti.com/api/v2/epayment/lookup/',
            ];
        case 'fonepay':
            return ['request' => $test ? 'https://dev-clientapi.fonepay.com/api/merchantRequest' : 'https://clientapi.fonepay.com/api/merchantRequest'];
        case 'stripe':
            return ['sessions' => 'https://api.stripe.com/v1/checkout/sessions'];
    }
    return [];
}

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

function pg_config(int $companyId, string $provider): ?array
{
    if (!table_exists('payment_gateways')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM payment_gateways WHERE company_id = :cid AND provider = :p LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'p' => $provider]);
    return $stmt->fetch() ?: null;
}

function pg_all_configs(int $companyId): array
{
    $out = [];
    foreach (array_keys(pg_providers()) as $provider) {
        $out[$provider] = pg_config($companyId, $provider);
    }
    return $out;
}

/** Enabled + credential-complete gateways a client can actually pay with. */
function pg_enabled_configs(int $companyId): array
{
    $out = [];
    foreach (pg_all_configs($companyId) as $provider => $config) {
        if ($config && (int) $config['enabled'] === 1 && pg_config_is_ready($config)) {
            $out[$provider] = $config;
        }
    }
    return $out;
}

/** A gateway is usable only when its required credential fields are filled. */
function pg_config_is_ready(array $config): bool
{
    $provider = (string) $config['provider'];
    switch ($provider) {
        case 'esewa':
            return trim((string) ($config['merchant_code'] ?? '')) !== '' && trim((string) ($config['secret_key'] ?? '')) !== '';
        case 'fonepay':
            return trim((string) ($config['merchant_code'] ?? '')) !== '' && trim((string) ($config['secret_key'] ?? '')) !== '';
        case 'khalti':
            return trim((string) ($config['secret_key'] ?? '')) !== '';
        case 'stripe':
            // Stripe presents in a foreign currency (not NPR), so it needs both a
            // secret key AND an FX rate to convert the NPR invoice amount.
            $ex = json_decode((string) ($config['extra_config'] ?? ''), true) ?: [];
            return trim((string) ($config['secret_key'] ?? '')) !== '' && (float) ($ex['fx_rate'] ?? 0) > 0;
    }
    return false;
}

function pg_save_config(int $companyId, string $provider, array $data): void
{
    if (!isset(pg_providers()[$provider])) {
        return;
    }
    $existing = pg_config($companyId, $provider);
    $params = [
        'cid' => $companyId,
        'provider' => $provider,
        'mode' => in_array((string) ($data['mode'] ?? 'test'), ['test', 'live'], true) ? $data['mode'] : 'test',
        'enabled' => !empty($data['enabled']) ? 1 : 0,
        'merchant_code' => trim((string) ($data['merchant_code'] ?? '')) ?: null,
        'secret_key' => trim((string) ($data['secret_key'] ?? '')) ?: null,
        'public_key' => trim((string) ($data['public_key'] ?? '')) ?: null,
        'extra_config' => !empty($data['extra_config']) ? json_encode($data['extra_config']) : null,
    ];
    if ($existing) {
        db()->prepare('UPDATE payment_gateways SET mode = :mode, enabled = :enabled, merchant_code = :merchant_code,
                secret_key = :secret_key, public_key = :public_key, extra_config = :extra_config
            WHERE company_id = :cid AND provider = :provider')->execute($params);
    } else {
        db()->prepare('INSERT INTO payment_gateways (company_id, provider, mode, enabled, merchant_code, secret_key, public_key, extra_config)
            VALUES (:cid, :provider, :mode, :enabled, :merchant_code, :secret_key, :public_key, :extra_config)')->execute($params);
    }
}

// ---------------------------------------------------------------------------
// Invoice helpers
// ---------------------------------------------------------------------------

/** Outstanding balance on an invoice, company-scoped. Returns [invoice, outstanding]. */
function pg_invoice_outstanding(int $invoiceId, int $companyId): array
{
    $stmt = db()->prepare('SELECT ti.id, ti.invoice_no, ti.company_id, ti.total_amount, ti.status,
            COALESCE((SELECT SUM(COALESCE(pr.payment_amount, 0)) FROM invoice_payment_requests pr
                      WHERE pr.invoice_id = ti.id AND pr.status IN ("paid", "partial")), 0) AS paid_amount
        FROM task_invoices ti
        WHERE ti.id = :id AND ti.company_id = :cid AND ti.status <> "cancelled" LIMIT 1');
    $stmt->execute(['id' => $invoiceId, 'cid' => $companyId]);
    $invoice = $stmt->fetch();
    if (!$invoice) {
        return [null, 0.0];
    }
    $outstanding = max(0.0, round((float) $invoice['total_amount'] - (float) $invoice['paid_amount'], 2));
    return [$invoice, $outstanding];
}

// ---------------------------------------------------------------------------
// Intents
// ---------------------------------------------------------------------------

function pg_create_intent(int $companyId, int $invoiceId, string $provider, float $amount, string $mode, string $currency, ?int $clientUserId): array
{
    $token = bin2hex(random_bytes(16));
    db()->prepare('INSERT INTO payment_intents (company_id, invoice_id, provider, mode, amount, currency, token, status, client_user_id)
        VALUES (:cid, :iid, :provider, :mode, :amount, :currency, :token, "pending", :uid)')
        ->execute([
            'cid' => $companyId, 'iid' => $invoiceId, 'provider' => $provider, 'mode' => $mode,
            'amount' => round($amount, 2), 'currency' => $currency, 'token' => $token, 'uid' => $clientUserId ?: null,
        ]);
    $id = (int) db()->lastInsertId();
    return pg_intent($id);
}

function pg_intent(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM payment_intents WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
}

function pg_intent_by_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM payment_intents WHERE token = :t LIMIT 1');
    $stmt->execute(['t' => $token]);
    return $stmt->fetch() ?: null;
}

function pg_set_intent_ref(int $intentId, string $ref): void
{
    db()->prepare('UPDATE payment_intents SET provider_ref = :r WHERE id = :id')->execute(['r' => $ref, 'id' => $intentId]);
}

function pg_mark_intent(int $intentId, string $status): void
{
    db()->prepare('UPDATE payment_intents SET status = :s WHERE id = :id AND status = "pending"')
        ->execute(['s' => $status, 'id' => $intentId]);
}

// ---------------------------------------------------------------------------
// HTTP (server-to-server for Khalti + Stripe)
// ---------------------------------------------------------------------------

function pg_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    if (!function_exists('curl_init')) {
        return ['code' => 0, 'body' => '', 'json' => null, 'error' => 'cURL is not available on this server.'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = $response === false ? curl_error($ch) : null;
    curl_close($ch);
    $json = null;
    if (is_string($response) && $response !== '') {
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $json = $decoded;
        }
    }
    return ['code' => $code, 'body' => is_string($response) ? $response : '', 'json' => $json, 'error' => $error];
}

// ---------------------------------------------------------------------------
// Initiate: build the redirect/form that sends the client to the provider
// ---------------------------------------------------------------------------

/**
 * Returns one of:
 *   ['type' => 'form_post', 'url' => ..., 'fields' => [...]]  (auto-submitted form)
 *   ['type' => 'redirect',  'url' => ...]                     (browser GET redirect)
 *   ['type' => 'error',     'error' => ...]
 */
function pg_initiate(array $config, array $intent, array $invoice, string $callbackUrl, string $cancelUrl): array
{
    $provider = (string) $config['provider'];
    $mode = (string) $config['mode'];
    $amount = round((float) $intent['amount'], 2);
    $amountStr = number_format($amount, 2, '.', '');
    $ep = pg_endpoints($provider, $mode);

    switch ($provider) {
        case 'esewa': {
            $code = trim((string) $config['merchant_code']);
            $secret = trim((string) $config['secret_key']);
            $uuid = (string) $intent['token'];
            $message = "total_amount={$amountStr},transaction_uuid={$uuid},product_code={$code}";
            $signature = base64_encode(hash_hmac('sha256', $message, $secret, true));
            return [
                'type' => 'form_post',
                'url' => $ep['form'],
                'fields' => [
                    'amount' => $amountStr,
                    'tax_amount' => '0',
                    'total_amount' => $amountStr,
                    'transaction_uuid' => $uuid,
                    'product_code' => $code,
                    'product_service_charge' => '0',
                    'product_delivery_charge' => '0',
                    'success_url' => $callbackUrl,
                    'failure_url' => $cancelUrl,
                    'signed_field_names' => 'total_amount,transaction_uuid,product_code',
                    'signature' => $signature,
                ],
            ];
        }

        case 'fonepay': {
            $pid = trim((string) $config['merchant_code']);
            $secret = trim((string) $config['secret_key']);
            $prn = (string) $intent['token'];
            $crn = 'NPR';
            $dt = date('m/d/Y');
            $r1 = 'Invoice ' . (string) ($invoice['invoice_no'] ?? $invoice['id']);
            $r2 = 'N/A';
            $dataString = "{$pid},P,{$prn},{$amountStr},{$crn},{$dt},{$r1},{$r2},{$callbackUrl}";
            $dv = hash_hmac('sha512', $dataString, $secret);
            $query = http_build_query([
                'PID' => $pid, 'MD' => 'P', 'PRN' => $prn, 'AMT' => $amountStr, 'CRN' => $crn,
                'DT' => $dt, 'R1' => $r1, 'R2' => $r2, 'DV' => $dv, 'RU' => $callbackUrl,
            ]);
            return ['type' => 'redirect', 'url' => $ep['request'] . '?' . $query];
        }

        case 'khalti': {
            $secret = trim((string) $config['secret_key']);
            $payload = json_encode([
                'return_url' => $callbackUrl,
                'website_url' => url(''),
                'amount' => (int) round($amount * 100), // paisa
                'purchase_order_id' => (string) $intent['token'],
                'purchase_order_name' => 'Invoice ' . (string) ($invoice['invoice_no'] ?? $invoice['id']),
            ]);
            $res = pg_http('POST', $ep['initiate'], [
                'Authorization: Key ' . $secret,
                'Content-Type: application/json',
            ], $payload);
            $pidx = (string) ($res['json']['pidx'] ?? '');
            $payUrl = (string) ($res['json']['payment_url'] ?? '');
            if ($pidx === '' || $payUrl === '') {
                $detail = (string) ($res['json']['detail'] ?? $res['error'] ?? ('HTTP ' . $res['code']));
                return ['type' => 'error', 'error' => 'Khalti did not accept the request: ' . $detail];
            }
            pg_set_intent_ref((int) $intent['id'], $pidx);
            return ['type' => 'redirect', 'url' => $payUrl];
        }

        case 'stripe': {
            $secret = trim((string) $config['secret_key']);
            $extra = json_decode((string) ($config['extra_config'] ?? ''), true) ?: [];
            $currency = strtolower((string) ($extra['stripe_currency'] ?? 'usd'));
            // The invoice amount is in the site currency (NPR); Stripe presents in a
            // foreign currency. Convert with the configured FX rate (NPR per 1 unit
            // of the Stripe currency) so we never charge the NPR figure as USD.
            $fx = (float) ($extra['fx_rate'] ?? 0);
            if ($fx <= 0) {
                return ['type' => 'error', 'error' => 'Stripe FX rate is not configured. Set it in Payment Gateways.'];
            }
            $presentmentCents = (int) round($amount / $fx * 100);
            $successUrl = $callbackUrl . (str_contains($callbackUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';
            $form = http_build_query([
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => (string) $intent['token'],
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $presentmentCents,
                        'product_data' => ['name' => 'Invoice ' . (string) ($invoice['invoice_no'] ?? $invoice['id'])],
                    ],
                ]],
            ]);
            $res = pg_http('POST', $ep['sessions'], [
                'Authorization: Bearer ' . $secret,
                'Content-Type: application/x-www-form-urlencoded',
            ], $form);
            $sessionId = (string) ($res['json']['id'] ?? '');
            $payUrl = (string) ($res['json']['url'] ?? '');
            if ($sessionId === '' || $payUrl === '') {
                $detail = (string) ($res['json']['error']['message'] ?? $res['error'] ?? ('HTTP ' . $res['code']));
                return ['type' => 'error', 'error' => 'Stripe did not create the checkout session: ' . $detail];
            }
            pg_set_intent_ref((int) $intent['id'], $sessionId);
            return ['type' => 'redirect', 'url' => $payUrl];
        }
    }
    return ['type' => 'error', 'error' => 'Unknown gateway.'];
}

// ---------------------------------------------------------------------------
// Verify: confirm with the provider that money actually moved
// ---------------------------------------------------------------------------

/** Returns ['paid' => bool, 'amount' => float, 'ref' => string, 'error' => ?string]. */
function pg_verify(array $config, array $intent, array $params): array
{
    $provider = (string) $config['provider'];
    $mode = (string) $config['mode'];
    $ep = pg_endpoints($provider, $mode);

    switch ($provider) {
        case 'esewa': {
            // eSewa returns a base64 JSON blob in ?data= signed over signed_field_names.
            $raw = (string) ($params['data'] ?? '');
            if ($raw === '') {
                return ['paid' => false, 'amount' => 0.0, 'ref' => '', 'error' => 'No eSewa response payload.'];
            }
            $data = json_decode((string) base64_decode($raw, true), true);
            if (!is_array($data)) {
                return ['paid' => false, 'amount' => 0.0, 'ref' => '', 'error' => 'Malformed eSewa payload.'];
            }
            $secret = trim((string) $config['secret_key']);
            $signedNames = explode(',', (string) ($data['signed_field_names'] ?? ''));
            $parts = [];
            foreach ($signedNames as $name) {
                $name = trim($name);
                $parts[] = $name . '=' . (string) ($data[$name] ?? '');
            }
            $expected = base64_encode(hash_hmac('sha256', implode(',', $parts), $secret, true));
            if (!hash_equals($expected, (string) ($data['signature'] ?? ''))) {
                return ['paid' => false, 'amount' => 0.0, 'ref' => '', 'error' => 'eSewa signature mismatch.'];
            }
            // Bind the signed transaction to THIS intent: transaction_uuid was set
            // to our token at initiate. Without this a valid signed payload from one
            // real payment could be replayed to settle a different equal-amount intent.
            if (!hash_equals((string) $intent['token'], (string) ($data['transaction_uuid'] ?? ''))) {
                return ['paid' => false, 'amount' => 0.0, 'ref' => '', 'error' => 'This eSewa payment does not belong to this invoice.'];
            }
            $paid = strtoupper((string) ($data['status'] ?? '')) === 'COMPLETE';
            return [
                'paid' => $paid,
                'amount' => round((float) str_replace(',', '', (string) ($data['total_amount'] ?? '0')), 2),
                'ref' => (string) ($data['transaction_code'] ?? ($data['transaction_uuid'] ?? '')),
                'error' => $paid ? null : 'eSewa status: ' . (string) ($data['status'] ?? 'unknown'),
            ];
        }

        case 'khalti': {
            $secret = trim((string) $config['secret_key']);
            // Look up ONLY the pidx we stored for this intent at initiate — never a
            // client-supplied one — so another completed payment can't be substituted.
            $pidx = (string) ($intent['provider_ref'] ?? '');
            if ($pidx === '') {
                return ['paid' => false, 'amount' => 0.0, 'ref' => '', 'error' => 'This payment was not initialised correctly.'];
            }
            $res = pg_http('POST', $ep['lookup'], [
                'Authorization: Key ' . $secret,
                'Content-Type: application/json',
            ], json_encode(['pidx' => $pidx]));
            if (!is_array($res['json'])) {
                return ['paid' => false, 'amount' => 0.0, 'ref' => $pidx, 'error' => 'Khalti lookup failed: ' . (string) ($res['error'] ?? ('HTTP ' . $res['code']))];
            }
            $paid = (string) ($res['json']['status'] ?? '') === 'Completed';
            return [
                'paid' => $paid,
                'amount' => round((float) ($res['json']['total_amount'] ?? 0) / 100, 2),
                'ref' => (string) ($res['json']['transaction_id'] ?? $pidx),
                'error' => $paid ? null : 'Khalti status: ' . (string) ($res['json']['status'] ?? 'unknown'),
            ];
        }

        case 'fonepay': {
            $secret = trim((string) $config['secret_key']);
            $names = ['PRN', 'PID', 'PS', 'RC', 'UID', 'BC', 'INI', 'P_AMT', 'R_AMT'];
            $parts = [];
            foreach ($names as $n) {
                $parts[] = (string) ($params[$n] ?? '');
            }
            $expected = hash_hmac('sha512', implode(',', $parts), $secret);
            if (!hash_equals($expected, (string) ($params['DV'] ?? ''))) {
                return ['paid' => false, 'amount' => 0.0, 'ref' => '', 'error' => 'Fonepay verification hash mismatch.'];
            }
            // Bind to THIS intent: PRN (inside the signed set) was our token at initiate.
            if (!hash_equals((string) $intent['token'], (string) ($params['PRN'] ?? ''))) {
                return ['paid' => false, 'amount' => 0.0, 'ref' => '', 'error' => 'This Fonepay payment does not belong to this invoice.'];
            }
            $paid = strtolower((string) ($params['PS'] ?? '')) === 'true';
            return [
                'paid' => $paid,
                'amount' => round((float) str_replace(',', '', (string) ($params['P_AMT'] ?? '0')), 2),
                'ref' => (string) ($params['UID'] ?? ($params['PRN'] ?? '')),
                'error' => $paid ? null : 'Fonepay status RC: ' . (string) ($params['RC'] ?? 'unknown'),
            ];
        }

        case 'stripe': {
            $secret = trim((string) $config['secret_key']);
            // Retrieve ONLY the session we created for this intent — never a
            // client-supplied session_id — so a paid session from another invoice
            // cannot be substituted.
            $sessionId = (string) ($intent['provider_ref'] ?? '');
            if ($sessionId === '') {
                return ['paid' => false, 'amount' => 0.0, 'ref' => '', 'error' => 'This payment was not initialised correctly.'];
            }
            $res = pg_http('GET', $ep['sessions'] . '/' . rawurlencode($sessionId), [
                'Authorization: Bearer ' . $secret,
            ]);
            if (!is_array($res['json'])) {
                return ['paid' => false, 'amount' => 0.0, 'ref' => $sessionId, 'error' => 'Stripe retrieve failed: ' . (string) ($res['error'] ?? ('HTTP ' . $res['code']))];
            }
            // Defence in depth: the retrieved session must reference this intent.
            if (!hash_equals((string) $intent['token'], (string) ($res['json']['client_reference_id'] ?? ''))) {
                return ['paid' => false, 'amount' => 0.0, 'ref' => $sessionId, 'error' => 'This Stripe session does not belong to this invoice.'];
            }
            // The presentment charge must equal what we asked Stripe to collect
            // (NPR outstanding converted at the stored FX rate), so a tampered or
            // mis-priced session is rejected. On success report the NPR equivalent.
            $extra = json_decode((string) ($config['extra_config'] ?? ''), true) ?: [];
            $fx = (float) ($extra['fx_rate'] ?? 0);
            $expectedCents = $fx > 0 ? (int) round((float) $intent['amount'] / $fx * 100) : -1;
            $paid = (string) ($res['json']['payment_status'] ?? '') === 'paid'
                && (int) ($res['json']['amount_total'] ?? 0) === $expectedCents;
            return [
                'paid' => $paid,
                'amount' => $paid ? round((float) $intent['amount'], 2) : round((float) ($res['json']['amount_total'] ?? 0) / 100, 2),
                'ref' => (string) ($res['json']['payment_intent'] ?? $sessionId),
                'error' => $paid ? null : 'Stripe payment not confirmed at the expected amount.',
            ];
        }
    }
    return ['paid' => false, 'amount' => 0.0, 'ref' => '', 'error' => 'Unknown gateway.'];
}

// ---------------------------------------------------------------------------
// Settle: turn a verified payment into a posted receipt (atomic, idempotent)
// ---------------------------------------------------------------------------

/**
 * Settle an intent whose provider confirmed payment. Reuses the admin receipt
 * machinery so the receivable resolution, receipt voucher, client-books mirror
 * and voucher idempotency are identical to a manually recorded payment. The
 * booked amount is the invoice's own outstanding (clamped), never a raw gateway
 * figure, so a tampered callback can't over- or under-credit the receivable.
 * Returns ['ok' => bool, 'already' => bool, 'payment_request_id' => int, 'error' => ?string].
 */
function pg_settle_intent(int $intentId, string $providerRef): array
{
    $pdo = db();
    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }
    // Save + set the accounting context so auto_post_invoice_payment_voucher()
    // resolves the invoice company's fiscal year (a public callback has none).
    $priorCompany = $_SESSION['company_id'] ?? null;
    $priorFy = $_SESSION['fiscal_year_id'] ?? null;
    try {
        $lockStmt = $pdo->prepare('SELECT * FROM payment_intents WHERE id = :id FOR UPDATE');
        $lockStmt->execute(['id' => $intentId]);
        $intent = $lockStmt->fetch();
        if (!$intent) {
            throw new RuntimeException('Payment intent not found.');
        }
        if ((string) $intent['status'] === 'paid') {
            if ($ownsTx) { $pdo->commit(); }
            return ['ok' => true, 'already' => true, 'payment_request_id' => (int) ($intent['payment_request_id'] ?? 0)];
        }

        $companyId = (int) $intent['company_id'];
        $invoiceId = (int) $intent['invoice_id'];

        // Single-use guard: a given provider transaction reference may settle at
        // most one intent — belt-and-suspenders on top of the per-provider binding
        // checks in pg_verify, so one real payment can never be booked twice.
        if ($providerRef !== '') {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM payment_intents
                WHERE company_id = :cid AND provider = :p AND provider_ref = :ref AND status = 'paid' AND id <> :id");
            $dup->execute(['cid' => $companyId, 'p' => (string) $intent['provider'], 'ref' => $providerRef, 'id' => $intentId]);
            if ((int) $dup->fetchColumn() > 0) {
                throw new RuntimeException('this payment reference was already applied to another invoice');
            }
        }

        [$invoice, $outstanding] = pg_invoice_outstanding($invoiceId, $companyId);
        if (!$invoice) {
            throw new RuntimeException('Invoice not found for this company.');
        }

        // Nothing left to collect (already settled another way): close the intent.
        if ($outstanding <= 0.0) {
            $pdo->prepare('UPDATE payment_intents SET status = "paid", provider_ref = :r, paid_at = NOW() WHERE id = :id')
                ->execute(['r' => $providerRef, 'id' => $intentId]);
            if ($ownsTx) { $pdo->commit(); }
            return ['ok' => true, 'already' => true, 'payment_request_id' => 0];
        }

        $amount = min(round((float) $intent['amount'], 2), $outstanding);
        $status = $amount >= $outstanding ? 'paid' : 'partial';

        $fyId = 0;
        $fy = function_exists('fiscal_year_for_date') ? fiscal_year_for_date($companyId, date('Y-m-d')) : null;
        $fyId = (int) ($fy['id'] ?? 0);
        $_SESSION['company_id'] = $companyId;
        if ($fyId > 0) {
            $_SESSION['fiscal_year_id'] = $fyId;
        }

        $method = pg_provider_label((string) $intent['provider']) . ' (online)';
        $note = 'Online payment via ' . pg_provider_label((string) $intent['provider']) . ' — ref ' . $providerRef;
        $pdo->prepare('INSERT INTO invoice_payment_requests
                (invoice_id, company_id, requested_by, amount_requested, payment_method, status, payment_received_on, payment_amount, notes)
            VALUES (:invoice_id, :company_id, :requested_by, :amount_requested, :payment_method, :status, :received_on, :payment_amount, :notes)')
            ->execute([
                'invoice_id' => $invoiceId,
                'company_id' => $companyId,
                'requested_by' => (int) ($intent['client_user_id'] ?? 0) ?: null,
                'amount_requested' => $amount,
                'payment_method' => $method,
                'status' => $status,
                'received_on' => date('Y-m-d'),
                'payment_amount' => $amount,
                'notes' => $note,
            ]);
        $paymentRequestId = (int) $pdo->lastInsertId();

        auto_post_invoice_payment_voucher($paymentRequestId, (int) ($intent['client_user_id'] ?? 0) ?: null);

        if (table_exists('vouchers')) {
            $rvCheck = $pdo->prepare("SELECT COUNT(*) FROM vouchers WHERE source_type = 'invoice_payment_request' AND source_id = :id");
            $rvCheck->execute(['id' => $paymentRequestId]);
            if ((int) $rvCheck->fetchColumn() === 0) {
                throw new RuntimeException('receipt voucher did not post — check the cash/receivable ledger mappings in Settings');
            }
        }

        if ($status === 'paid') {
            $pdo->prepare('UPDATE task_invoices SET status = "paid" WHERE id = :id AND company_id = :cid AND status <> "cancelled"')
                ->execute(['id' => $invoiceId, 'cid' => $companyId]);
        }
        $pdo->prepare('UPDATE payment_intents SET status = "paid", provider_ref = :r, payment_request_id = :prid, paid_at = NOW() WHERE id = :id')
            ->execute(['r' => $providerRef, 'prid' => $paymentRequestId, 'id' => $intentId]);

        if ($ownsTx) { $pdo->commit(); }

        // Best-effort receipt row after commit (mirrors the admin desks).
        if (table_exists('invoice_payment_receipts')) {
            try {
                db()->prepare('INSERT INTO invoice_payment_receipts (payment_request_id, invoice_id, company_id, receipt_no, amount_received, payment_method, received_on, notes, created_by)
                    VALUES (:prid, :iid, :cid, :rno, :amt, :method, :on, :notes, :by)')
                    ->execute([
                        'prid' => $paymentRequestId, 'iid' => $invoiceId, 'cid' => $companyId,
                        'rno' => next_receipt_number($companyId), 'amt' => $amount, 'method' => $method,
                        'on' => date('Y-m-d'), 'notes' => $note, 'by' => (int) ($intent['client_user_id'] ?? 0) ?: null,
                    ]);
            } catch (Throwable $e) {
                // receipt row is best-effort; the money is already booked
            }
        }
        return ['ok' => true, 'already' => false, 'payment_request_id' => $paymentRequestId];
    } catch (Throwable $exception) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'already' => false, 'payment_request_id' => 0, 'error' => $exception->getMessage()];
    } finally {
        // Restore the caller's (client) session context untouched.
        if ($priorCompany === null) { unset($_SESSION['company_id']); } else { $_SESSION['company_id'] = $priorCompany; }
        if ($priorFy === null) { unset($_SESSION['fiscal_year_id']); } else { $_SESSION['fiscal_year_id'] = $priorFy; }
    }
}
