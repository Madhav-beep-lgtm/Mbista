<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/payment_gateway_engine.php';

require_admin();
require_company_context();
accounting_module_repair_database();

$company = current_company();
if (!$company) {
    flash('error', 'Company context required.');
    redirect('admin/accounting-dashboard.php');
}
$companyId = (int) $company['id'];
$userId = (int) (current_user()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $provider = (string) ($_POST['provider'] ?? '');
    if (isset(pg_providers()[$provider])) {
        $extra = [];
        if ($provider === 'stripe') {
            $cur = strtolower(trim((string) ($_POST['stripe_currency'] ?? 'usd')));
            $extra['stripe_currency'] = preg_match('/^[a-z]{3}$/', $cur) ? $cur : 'usd';
            $extra['fx_rate'] = max(0.0, (float) ($_POST['fx_rate'] ?? 0));
        }
        pg_save_config($companyId, $provider, [
            'mode' => (string) ($_POST['mode'] ?? 'test'),
            'enabled' => !empty($_POST['enabled']),
            'merchant_code' => (string) ($_POST['merchant_code'] ?? ''),
            'secret_key' => (string) ($_POST['secret_key'] ?? ''),
            'public_key' => (string) ($_POST['public_key'] ?? ''),
            'extra_config' => $extra,
        ]);
        log_activity('payment_gateway', $companyId, 'saved', 'Saved ' . pg_provider_label($provider) . ' gateway settings.', $userId);
        flash('success', pg_provider_label($provider) . ' settings saved.');
    }
    redirect('admin/payment-gateways.php');
}

$configs = pg_all_configs($companyId);
$cashLedger = get_mapped_ledger($companyId, 'default_cash_bank');

$pageTitle = 'Payment Gateways';
$pageSubtitle = 'Let clients pay their invoices online — the confirmed payment posts a receipt voucher automatically.';
$pageHero = ['icon' => 'card'];
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card">
    <div class="mbw-card-head"><h2>How online collection works</h2></div>
    <p style="margin:0 0 8px">A client opens an unpaid invoice in their portal, taps <strong>Pay online</strong>, and completes payment on the provider. When the provider confirms, the app posts a <strong>Dr Bank / Cr Receivable</strong> receipt against that invoice — the same entry as a manually recorded payment — and marks the invoice paid.</p>
    <p style="margin:0;color:var(--mbw-muted);font-size:12.5px">
        Settlement debits the <strong><?= e($cashLedger['name'] ?? 'default cash/bank') ?></strong> ledger (your <code>default_cash_bank</code> mapping<?= $cashLedger ? '' : ' — not set yet; map it in Settings or receipts cannot post' ?>). Credentials below are visible only to admins; keep them secret. Start in <strong>Test</strong> mode, verify a sandbox payment, then switch to Live.
    </p>
</section>

<?php foreach (pg_providers() as $provider => $meta): ?>
    <?php
    $cfg = $configs[$provider] ?? null;
    $ready = $cfg ? pg_config_is_ready((array) $cfg) : false;
    $enabled = $cfg && (int) $cfg['enabled'] === 1;
    $extra = $cfg ? (json_decode((string) ($cfg['extra_config'] ?? ''), true) ?: []) : [];
    ?>
    <section class="mbw-card">
        <div class="mbw-card-head">
            <h2><?= icon('card') ?> <?= e($meta['label']) ?></h2>
            <div class="mbw-card-tools">
                <?php if ($enabled && $ready): ?>
                    <span class="mbw-pill tone-green">Enabled · <?= e(ucfirst((string) $cfg['mode'])) ?></span>
                <?php elseif ($enabled && !$ready): ?>
                    <span class="mbw-pill tone-amber">Enabled but credentials incomplete</span>
                <?php else: ?>
                    <span class="mbw-pill tone-gray">Disabled</span>
                <?php endif; ?>
            </div>
        </div>
        <p style="margin:0 0 10px;color:var(--mbw-muted);font-size:12.5px"><?= e($meta['hint']) ?> Charges in <strong><?= e($meta['currency']) ?></strong>.</p>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="provider" value="<?= e($provider) ?>">
            <label>Mode
                <select name="mode">
                    <option value="test" <?= ($cfg['mode'] ?? 'test') === 'test' ? 'selected' : '' ?>>Test / sandbox</option>
                    <option value="live" <?= ($cfg['mode'] ?? '') === 'live' ? 'selected' : '' ?>>Live</option>
                </select>
            </label>
            <label style="display:flex;align-items:center;gap:8px;flex-direction:row;align-self:end">
                <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?> style="width:auto;min-height:auto"> Enabled (show to clients)
            </label>
            <?php foreach ($meta['fields'] as $field => $fieldLabel): ?>
                <label class="workspace-span-2"><?= e($fieldLabel) ?>
                    <input type="text" name="<?= e($field) ?>" autocomplete="off" value="<?= e((string) ($cfg[$field] ?? '')) ?>" placeholder="<?= e($fieldLabel) ?>">
                </label>
            <?php endforeach; ?>
            <?php if ($provider === 'stripe'): ?>
                <label>Stripe currency (3-letter)
                    <input type="text" name="stripe_currency" maxlength="3" value="<?= e((string) ($extra['stripe_currency'] ?? 'usd')) ?>" placeholder="usd">
                </label>
                <label>FX rate — 1 <?= e(strtoupper((string) ($extra['stripe_currency'] ?? 'usd'))) ?> = ? (<?= e(site_currency_symbol()) ?>)
                    <input type="number" step="0.0001" min="0" name="fx_rate" value="<?= e((string) ($extra['fx_rate'] ?? '')) ?>" placeholder="e.g. 133">
                </label>
                <p class="workspace-span-2" style="margin:0;color:var(--mbw-muted);font-size:12px">Stripe can't present NPR, so the invoice amount is converted at this rate before charging, then re-checked on return. Required before Stripe can be enabled.</p>
            <?php endif; ?>
            <div class="workspace-span-2"><button type="submit"><?= icon('save') ?>Save <?= e($meta['label']) ?></button></div>
        </form>
    </section>
<?php endforeach; ?>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
