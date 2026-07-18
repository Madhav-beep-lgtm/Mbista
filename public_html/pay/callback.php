<?php
declare(strict_types=1);

/**
 * Gateway return/callback. The provider redirects the client's browser here.
 * Authenticated by the PROVIDER's signature/lookup (never our CSRF/session),
 * the amount re-verified, then settled atomically into the books. Idempotent:
 * a repeated callback on an already-paid intent just returns success.
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/payment_gateway_engine.php';

$backUrl = 'dashboard.php?view=invoices';
$provider = (string) ($_REQUEST['provider'] ?? '');
$token = (string) ($_REQUEST['token'] ?? '');

$intent = pg_intent_by_token($token);
if (!$intent || $provider === '' || (string) $intent['provider'] !== $provider) {
    flash('error', 'Payment session not found or expired.');
    redirect($backUrl);
}

// Idempotent: already booked.
if ((string) $intent['status'] === 'paid') {
    flash('success', 'Payment already recorded — thank you.');
    redirect($backUrl);
}

if (!empty($_REQUEST['cancelled'])) {
    pg_mark_intent((int) $intent['id'], 'cancelled');
    flash('info', 'Payment was cancelled. Nothing was charged.');
    redirect($backUrl);
}

$config = pg_config((int) $intent['company_id'], $provider);
if (!$config) {
    flash('error', 'Gateway configuration is missing. Please contact us.');
    redirect($backUrl);
}

$verify = pg_verify($config, $intent, $_REQUEST);
if (empty($verify['paid'])) {
    pg_mark_intent((int) $intent['id'], 'failed');
    flash('error', 'Payment was not completed: ' . (string) ($verify['error'] ?? 'verification failed') . '.');
    redirect($backUrl);
}

// Amount integrity: the provider must have collected what we asked for.
if (abs((float) $verify['amount'] - (float) $intent['amount']) > 0.5) {
    security_event('payment_gateway_amount_mismatch', 'warning',
        'Gateway ' . $provider . ' confirmed ' . $verify['amount'] . ' but intent was ' . $intent['amount'] . ' (token ' . $token . ').',
        (int) $intent['company_id'], (int) ($intent['client_user_id'] ?? 0) ?: null);
    flash('error', 'The confirmed amount did not match the invoice. No charge was booked — please contact us.');
    redirect($backUrl);
}

$settle = pg_settle_intent((int) $intent['id'], (string) ($verify['ref'] ?? ''));
if (empty($settle['ok'])) {
    security_event('payment_gateway_settle_failed', 'warning',
        'Verified ' . $provider . ' payment could not be booked (token ' . $token . '): ' . (string) ($settle['error'] ?? ''),
        (int) $intent['company_id'], (int) ($intent['client_user_id'] ?? 0) ?: null);
    flash('error', 'Payment verified but could not be booked: ' . (string) ($settle['error'] ?? '') . '. Please contact us.');
    redirect($backUrl);
}

security_event('payment_gateway_settled', 'success',
    'Invoice #' . (int) $intent['invoice_id'] . ' settled via ' . $provider . ' for ' . $verify['amount'] . '.',
    (int) $intent['company_id'], (int) ($intent['client_user_id'] ?? 0) ?: null);
flash('success', 'Payment received — thank you! Your invoice has been updated.');
redirect($backUrl);
