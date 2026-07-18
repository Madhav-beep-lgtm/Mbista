<?php
declare(strict_types=1);

/**
 * Client-initiated payment start. The logged-in client picks a gateway on an
 * unpaid invoice; we verify ownership, create a payment intent, and hand off to
 * the provider (auto-submitting form for eSewa, redirect for the rest).
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/payment_gateway_engine.php';

require_login();
accounting_module_repair_database();

$backUrl = 'dashboard.php?view=invoices';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($backUrl);
}
verify_csrf();

$userId = (int) (current_user()['id'] ?? 0);
$clientProfile = client_profile_for_user($userId);
if (!$clientProfile) {
    flash('error', 'No client profile is linked to your account.');
    redirect($backUrl);
}
$clientId = (int) $clientProfile['id'];

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$provider = (string) ($_POST['provider'] ?? '');
if (!isset(pg_providers()[$provider])) {
    flash('error', 'Unknown payment method.');
    redirect($backUrl);
}

// Ownership: the invoice must belong to this client via its task or its party.
$stmt = db()->prepare('SELECT ti.* FROM task_invoices ti
    LEFT JOIN client_tasks ct ON ct.id = ti.task_id
    WHERE ti.id = :iid AND ti.status <> "cancelled"
      AND (ct.client_id = :cid OR ti.party_id IN (SELECT id FROM accounting_parties WHERE client_profile_id = :cid2))
    LIMIT 1');
$stmt->execute(['iid' => $invoiceId, 'cid' => $clientId, 'cid2' => $clientId]);
$invoice = $stmt->fetch();
if (!$invoice) {
    flash('error', 'Invoice not found.');
    redirect($backUrl);
}
$companyId = (int) $invoice['company_id'];

$config = pg_config($companyId, $provider);
if (!$config || (int) $config['enabled'] !== 1 || !pg_config_is_ready($config)) {
    flash('error', 'That payment method is not available for this invoice.');
    redirect($backUrl);
}

[$inv, $outstanding] = pg_invoice_outstanding($invoiceId, $companyId);
if ($outstanding <= 0) {
    flash('info', 'This invoice is already fully paid.');
    redirect($backUrl);
}

$currency = (string) pg_providers()[$provider]['currency'];
$intent = pg_create_intent($companyId, $invoiceId, $provider, $outstanding, (string) $config['mode'], $currency, $userId);

$callbackUrl = url('pay/callback.php?provider=' . $provider . '&token=' . $intent['token']);
$cancelUrl = url('pay/callback.php?provider=' . $provider . '&token=' . $intent['token'] . '&cancelled=1');
$result = pg_initiate($config, $intent, $invoice, $callbackUrl, $cancelUrl);

if ($result['type'] === 'error') {
    pg_mark_intent((int) $intent['id'], 'failed');
    flash('error', 'Could not start the payment: ' . (string) $result['error']);
    redirect($backUrl);
}
if ($result['type'] === 'redirect') {
    header('Location: ' . $result['url']);
    exit;
}
// form_post — auto-submit to the provider (eSewa).
$label = pg_provider_label($provider);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Redirecting to <?= e($label) ?>…</title>
<style>body{font-family:system-ui,Arial,sans-serif;padding:40px;text-align:center;color:#12261f}</style></head>
<body onload="document.forms[0].submit()">
<p>Redirecting you to <strong><?= e($label) ?></strong> to complete your payment…</p>
<form method="POST" action="<?= e($result['url']) ?>">
    <?php foreach ($result['fields'] as $k => $v): ?>
        <input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>">
    <?php endforeach; ?>
    <noscript><button type="submit">Continue to <?= e($label) ?></button></noscript>
</form>
</body></html>
