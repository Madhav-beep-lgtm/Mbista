<?php
declare(strict_types=1);
require '../../app/init.php';

if (!is_admin()) {
    redirect_to('/');
}

$companyId = (int) get_session_company();
$currency = 'NPR'; // Default to NPR for now

// Gateway definitions
$gateways = [
    'esewa' => [
        'name' => 'eSewa',
        'description' => 'eSewa Payment Gateway (Recommended for Nepal)',
        'fields' => [
            'enabled' => ['type' => 'checkbox', 'label' => 'Enable eSewa'],
            'merchant_code' => ['type' => 'text', 'label' => 'Merchant Code', 'required' => true],
            'api_key' => ['type' => 'password', 'label' => 'API Key', 'required' => true],
            'success_url' => ['type' => 'text', 'label' => 'Success URL', 'required' => true],
            'failure_url' => ['type' => 'text', 'label' => 'Failure URL', 'required' => true],
        ]
    ],
    'khalti' => [
        'name' => 'Khalti',
        'description' => 'Khalti Payment Gateway',
        'fields' => [
            'enabled' => ['type' => 'checkbox', 'label' => 'Enable Khalti'],
            'public_key' => ['type' => 'text', 'label' => 'Public Key', 'required' => true],
            'secret_key' => ['type' => 'password', 'label' => 'Secret Key', 'required' => true],
            'return_url' => ['type' => 'text', 'label' => 'Return URL', 'required' => true],
        ]
    ],
    'fonepay' => [
        'name' => 'Fonepay',
        'description' => 'Fonepay Payment Gateway (Go-live priority)',
        'fields' => [
            'enabled' => ['type' => 'checkbox', 'label' => 'Enable Fonepay'],
            'merchant_id' => ['type' => 'text', 'label' => 'Merchant ID', 'required' => true],
            'secret_key' => ['type' => 'password', 'label' => 'Secret Key', 'required' => true],
            'app_id' => ['type' => 'text', 'label' => 'App ID', 'required' => true],
            'success_url' => ['type' => 'text', 'label' => 'Success URL', 'required' => true],
        ]
    ],
    'stripe' => [
        'name' => 'Stripe',
        'description' => 'Stripe Payment Gateway (International, FX fees apply)',
        'fields' => [
            'enabled' => ['type' => 'checkbox', 'label' => 'Enable Stripe'],
            'publishable_key' => ['type' => 'text', 'label' => 'Publishable Key', 'required' => true],
            'secret_key' => ['type' => 'password', 'label' => 'Secret Key', 'required' => true],
            'webhook_secret' => ['type' => 'password', 'label' => 'Webhook Secret', 'required' => true],
            'supported_currency' => ['type' => 'text', 'label' => 'Supported Currency (NPR/USD)', 'value' => 'USD', 'required' => true],
        ]
    ]
];

// Load current config
$configStmt = db()->prepare("SELECT gateway_id, config_json FROM payment_gateway_config WHERE company_id = :cid");
$configStmt->execute(['cid' => $companyId]);
$currentConfig = [];
while ($row = $configStmt->fetch(PDO::FETCH_ASSOC)) {
    $currentConfig[$row['gateway_id']] = json_decode($row['config_json'], true) ?? [];
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        db()->beginTransaction();

        foreach ($gateways as $gatewayId => $gateway) {
            $config = [];
            foreach ($gateway['fields'] as $fieldName => $fieldDef) {
                $key = "gateway_{$gatewayId}_{$fieldName}";
                $config[$fieldName] = $_POST[$key] ?? null;
            }

            // Check if config exists
            $existStmt = db()->prepare("SELECT id FROM payment_gateway_config WHERE company_id = :cid AND gateway_id = :gid");
            $existStmt->execute(['cid' => $companyId, 'gid' => $gatewayId]);

            if ($existStmt->fetch()) {
                // Update
                $updateStmt = db()->prepare(
                    "UPDATE payment_gateway_config SET config_json = :config, updated_at = NOW()
                     WHERE company_id = :cid AND gateway_id = :gid"
                );
                $updateStmt->execute([
                    'cid' => $companyId,
                    'gid' => $gatewayId,
                    'config' => json_encode($config)
                ]);
            } else {
                // Insert
                $insertStmt = db()->prepare(
                    "INSERT INTO payment_gateway_config (company_id, gateway_id, config_json)
                     VALUES (:cid, :gid, :config)"
                );
                $insertStmt->execute([
                    'cid' => $companyId,
                    'gid' => $gatewayId,
                    'config' => json_encode($config)
                ]);
            }
        }

        db()->commit();

        // Refresh config
        $configStmt = db()->prepare("SELECT gateway_id, config_json FROM payment_gateway_config WHERE company_id = :cid");
        $configStmt->execute(['cid' => $companyId]);
        $currentConfig = [];
        while ($row = $configStmt->fetch(PDO::FETCH_ASSOC)) {
            $currentConfig[$row['gateway_id']] = json_decode($row['config_json'], true) ?? [];
        }

        $successMessage = "Payment gateway configuration saved successfully.";
    } catch (Exception $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $errorMessage = "Error saving configuration: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment Gateway Configuration</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { margin-bottom: 30px; }
        h1 { margin: 0 0 10px; color: #333; }
        .subtitle { color: #666; font-size: 14px; margin-bottom: 20px; }
        .alert { padding: 12px 16px; margin-bottom: 20px; border-radius: 6px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .gateway-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .card { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; }
        .card h2 { margin: 0 0 8px; font-size: 18px; color: #333; }
        .card .desc { color: #666; font-size: 13px; margin-bottom: 15px; }
        .card .warning { background: #fff3cd; border: 1px solid #ffc107; color: #856404; padding: 8px 12px; border-radius: 4px; font-size: 12px; margin-bottom: 15px; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333; }
        .form-group input[type="text"],
        .form-group input[type="password"] { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; box-sizing: border-box; }
        .form-group input:focus { outline: none; border-color: #0066cc; box-shadow: 0 0 0 3px rgba(0,102,204,0.1); }
        .form-group input[type="checkbox"] { margin-right: 6px; }
        .form-group.checkbox { display: flex; align-items: center; }
        .form-group.checkbox label { margin: 0; }

        .button-group { display: flex; gap: 10px; margin-top: 30px; }
        .button { padding: 10px 20px; border: none; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; }
        .button-primary { background: #0066cc; color: white; }
        .button-primary:hover { background: #0052a3; }
        .button-secondary { background: #f0f0f0; color: #333; }
        .button-secondary:hover { background: #e0e0e0; }

        .webhook-info { background: #e7f3ff; border: 1px solid #b3d9ff; color: #004085; padding: 12px; border-radius: 4px; font-size: 12px; margin-top: 20px; line-height: 1.5; }
        .webhook-info code { background: #d4e9ff; padding: 2px 4px; border-radius: 2px; font-family: monospace; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>💳 Payment Gateway Configuration</h1>
            <p class="subtitle">Configure payment processors for online invoice payments</p>
        </div>

        <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success"><?= e($successMessage) ?></div>
        <?php endif; ?>
        <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-error"><?= e($errorMessage) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="gateway-cards">
                <?php foreach ($gateways as $gatewayId => $gateway): ?>
                    <div class="card">
                        <h2><?= e($gateway['name']) ?></h2>
                        <p class="desc"><?= e($gateway['description']) ?></p>

                        <?php if ($gatewayId === 'stripe'): ?>
                            <div class="warning">⚠️ FX fees apply. Use only for international payments.</div>
                        <?php elseif ($gatewayId === 'fonepay'): ?>
                            <div class="warning">✅ Recommended for go-live - lowest fees in Nepal</div>
                        <?php endif; ?>

                        <?php foreach ($gateway['fields'] as $fieldName => $fieldDef):
                            $key = "gateway_{$gatewayId}_{$fieldName}";
                            $value = $currentConfig[$gatewayId][$fieldName] ?? ($fieldDef['value'] ?? '');
                        ?>
                            <?php if ($fieldDef['type'] === 'checkbox'): ?>
                                <div class="form-group checkbox">
                                    <input type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1" <?= $value ? 'checked' : '' ?>>
                                    <label for="<?= e($key) ?>"><?= e($fieldDef['label']) ?></label>
                                </div>
                            <?php else: ?>
                                <div class="form-group">
                                    <label for="<?= e($key) ?>"><?= e($fieldDef['label']) ?></label>
                                    <input type="<?= e($fieldDef['type']) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($value) ?>" <?= ($fieldDef['required'] ?? false) ? 'required' : '' ?>>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="webhook-info">
                <strong>Webhook Configuration:</strong><br>
                After saving, configure webhook receivers in each payment processor's dashboard to point to:<br>
                <code><?= e(app_url('/api/payment/webhook/esewa')) ?></code><br>
                <code><?= e(app_url('/api/payment/webhook/khalti')) ?></code><br>
                <code><?= e(app_url('/api/payment/webhook/fonepay')) ?></code><br>
                <code><?= e(app_url('/api/payment/webhook/stripe')) ?></code>
            </div>

            <div class="button-group">
                <button type="submit" class="button button-primary">💾 Save Configuration</button>
                <a href="<?= e(url('admin/')) ?>" class="button button-secondary">← Back to Admin</a>
            </div>
        </form>
    </div>
</body>
</html>
