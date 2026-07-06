<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();
$pageTitle = 'Settings';
$pageSubtitle = 'Fiscal controls, company profile, payments, invoice numbering, and notification setup.';
$bodyClass = 'admin-layout settings-page';

function settings_upload_image(string $field, array $settings): string
{
    $existingPath = (string) ($settings[$field . '_path'] ?? '');

    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existingPath;
    }

    if ((int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        flash('error', 'Upload failed for ' . str_replace('_', ' ', $field) . '.');
        redirect('admin/settings.php');
    }

    $tmpPath = (string) ($_FILES[$field]['tmp_name'] ?? '');
    $size = (int) ($_FILES[$field]['size'] ?? 0);

    if ($size <= 0 || $size > 2 * 1024 * 1024 || !is_uploaded_file($tmpPath)) {
        flash('error', 'Upload ' . str_replace('_', ' ', $field) . ' as an image under 2 MB.');
        redirect('admin/settings.php');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpPath);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensions[$mime])) {
        flash('error', 'Only JPG, PNG, WEBP, or GIF images are allowed.');
        redirect('admin/settings.php');
    }

    $uploadDir = __DIR__ . '/../assets/uploads/company-assets';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        flash('error', 'Could not create upload directory.');
        redirect('admin/settings.php');
    }

    $fileName = $field . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
    $targetPath = $uploadDir . '/' . $fileName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        flash('error', 'Could not save uploaded image.');
        redirect('admin/settings.php');
    }

    return 'assets/uploads/company-assets/' . $fileName;
}

$settingsCompany = current_company();
$settingsCompanyId = (int) ($settingsCompany['id'] ?? 0);
$settingsFiscalYear = current_fiscal_year();
$settingsFiscalYearId = (int) ($settingsFiscalYear['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_fiscal_controls') {
    verify_csrf();

    $vatRate = (string) ($_POST['default_vat_rate'] ?? '13');
    if (!is_numeric($vatRate) || (float) $vatRate < 0) {
        $vatRate = '13';
    }
    update_settings([
        'approvals_enabled' => isset($_POST['approvals_enabled']) ? '1' : '0',
        'default_vat_rate' => $vatRate,
    ]);

    $lockThrough = trim((string) ($_POST['locked_through'] ?? ''));
    if ($settingsCompanyId > 0 && $settingsFiscalYearId > 0 && table_exists('fiscal_period_locks')) {
        if ($lockThrough !== '' && DateTimeImmutable::createFromFormat('Y-m-d', $lockThrough) !== false) {
            db()->prepare('INSERT INTO fiscal_period_locks (company_id, fiscal_year_id, locked_through, locked_by)
                VALUES (:company_id, :fy, :locked_through, :locked_by)
                ON DUPLICATE KEY UPDATE locked_through = VALUES(locked_through), locked_by = VALUES(locked_by)')
                ->execute(['company_id' => $settingsCompanyId, 'fy' => $settingsFiscalYearId, 'locked_through' => $lockThrough, 'locked_by' => (int) (current_user()['id'] ?? 0)]);
            log_activity('settings', $settingsCompanyId, 'period_locked', 'Accounting period locked through ' . $lockThrough . '.', (int) (current_user()['id'] ?? 0));
        } else {
            db()->prepare('DELETE FROM fiscal_period_locks WHERE company_id = :company_id AND fiscal_year_id = :fy')
                ->execute(['company_id' => $settingsCompanyId, 'fy' => $settingsFiscalYearId]);
        }
    }

    flash('success', 'Fiscal & accounting controls saved.');
    redirect('admin/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $paymentMode = trim((string) ($_POST['payment_mode'] ?? 'manual'));
    $allowedPaymentModes = ['manual', 'stripe', 'paypal'];

    if (!in_array($paymentMode, $allowedPaymentModes, true)) {
        $paymentMode = 'manual';
    }

    $stripeCheckoutUrl = trim((string) ($_POST['stripe_checkout_url'] ?? ''));
    $paypalCheckoutUrl = trim((string) ($_POST['paypal_checkout_url'] ?? ''));
    $notificationFromEmail = trim((string) ($_POST['notification_from_email'] ?? ''));
    $notificationReplyToEmail = trim((string) ($_POST['notification_reply_to_email'] ?? ''));

    if ($stripeCheckoutUrl !== '' && filter_var($stripeCheckoutUrl, FILTER_VALIDATE_URL) === false) {
        flash('error', 'Stripe checkout URL must be a valid URL.');
        redirect('admin/settings.php');
    }

    if ($paypalCheckoutUrl !== '' && filter_var($paypalCheckoutUrl, FILTER_VALIDATE_URL) === false) {
        flash('error', 'PayPal checkout URL must be a valid URL.');
        redirect('admin/settings.php');
    }

    if ($notificationFromEmail !== '' && filter_var($notificationFromEmail, FILTER_VALIDATE_EMAIL) === false) {
        flash('error', 'Notification from email must be a valid email address.');
        redirect('admin/settings.php');
    }

    if ($notificationReplyToEmail !== '' && filter_var($notificationReplyToEmail, FILTER_VALIDATE_EMAIL) === false) {
        flash('error', 'Notification reply-to email must be a valid email address.');
        redirect('admin/settings.php');
    }

    $taxRate = trim((string) ($_POST['tax_invoice_tax_rate'] ?? '0'));
    if ($taxRate !== '' && !is_numeric($taxRate)) {
        flash('error', 'Tax invoice tax rate must be numeric.');
        redirect('admin/settings.php');
    }

    $currentSettings = all_settings();
    $companyLogoPath = settings_upload_image('company_logo', $currentSettings);
    $companySignaturePath = settings_upload_image('company_signature', $currentSettings);
    $companyStampPath = settings_upload_image('company_stamp', $currentSettings);

    update_settings([
        'site_name' => trim((string) ($_POST['site_name'] ?? APP_NAME)),
        'site_tagline' => trim((string) ($_POST['site_tagline'] ?? '')),
        'support_email' => trim((string) ($_POST['support_email'] ?? '')),
        'support_phone' => trim((string) ($_POST['support_phone'] ?? '')),
        'office_address' => trim((string) ($_POST['office_address'] ?? '')),
        'currency_symbol' => trim((string) ($_POST['currency_symbol'] ?? '$')),
        'hero_title' => trim((string) ($_POST['hero_title'] ?? '')),
        'hero_description' => trim((string) ($_POST['hero_description'] ?? '')),
        'about_text' => trim((string) ($_POST['about_text'] ?? '')),
        'company_logo_path' => $companyLogoPath,
        'company_signature_path' => $companySignaturePath,
        'company_stamp_path' => $companyStampPath,

        'payment_mode' => $paymentMode,
        'payment_label' => trim((string) ($_POST['payment_label'] ?? 'Manual payment / bank transfer')),
        'bank_name' => trim((string) ($_POST['bank_name'] ?? '')),
        'bank_account_name' => trim((string) ($_POST['bank_account_name'] ?? '')),
        'bank_account_number' => trim((string) ($_POST['bank_account_number'] ?? '')),
        'payment_note' => trim((string) ($_POST['payment_note'] ?? '')),
        'stripe_checkout_url' => $stripeCheckoutUrl,
        'paypal_checkout_url' => $paypalCheckoutUrl,

        'proforma_invoice_prefix' => trim((string) ($_POST['proforma_invoice_prefix'] ?? 'PRO')),
        'proforma_invoice_title' => trim((string) ($_POST['proforma_invoice_title'] ?? 'Proforma Invoice')),
        'proforma_invoice_note' => trim((string) ($_POST['proforma_invoice_note'] ?? '')),
        'proforma_invoice_terms' => trim((string) ($_POST['proforma_invoice_terms'] ?? '')),
        'proforma_invoice_footer' => trim((string) ($_POST['proforma_invoice_footer'] ?? '')),

        'tax_invoice_prefix' => trim((string) ($_POST['tax_invoice_prefix'] ?? 'TAX')),
        'tax_invoice_title' => trim((string) ($_POST['tax_invoice_title'] ?? 'Tax Invoice')),
        'tax_invoice_tax_label' => trim((string) ($_POST['tax_invoice_tax_label'] ?? 'VAT')),
        'tax_invoice_tax_rate' => $taxRate === '' ? '0' : $taxRate,
        'tax_invoice_note' => trim((string) ($_POST['tax_invoice_note'] ?? '')),
        'tax_invoice_terms' => trim((string) ($_POST['tax_invoice_terms'] ?? '')),
        'tax_invoice_footer' => trim((string) ($_POST['tax_invoice_footer'] ?? '')),

        'notify_admin_email' => isset($_POST['notify_admin_email']) ? '1' : '0',
        'notify_staff_email' => isset($_POST['notify_staff_email']) ? '1' : '0',
        'notify_client_email' => isset($_POST['notify_client_email']) ? '1' : '0',
        'notify_payment_email' => isset($_POST['notify_payment_email']) ? '1' : '0',
        'notify_task_email' => isset($_POST['notify_task_email']) ? '1' : '0',
        'notification_from_email' => $notificationFromEmail,
        'notification_reply_to_email' => $notificationReplyToEmail,
        'notification_footer_text' => trim((string) ($_POST['notification_footer_text'] ?? '')),
    ]);

    flash('success', 'Settings updated.');
    redirect('admin/settings.php');
}

$settings = all_settings();
$checked = static fn (string $key, string $default = '0'): string => (($settings[$key] ?? $default) === '1') ? ' checked' : '';

include __DIR__ . '/../../app/views/partials/admin_header.php';
$currentLockThrough = period_locked_through($settingsCompanyId, $settingsFiscalYearId);
?>
<form method="post" class="settings-form" id="fiscal-controls">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_fiscal_controls">
    <section class="mbw-card">
        <div class="mbw-card-head">
            <h2>Approvals, Period Lock &amp; Tax</h2>
            <div class="mbw-card-tools"><span class="mbw-pill tone-blue">Fiscal &amp; Accounting Controls</span><span class="mbw-chip tone-amber"><?= icon('compliance') ?></span></div>
        </div>
        <p style="margin:0 0 12px;color:var(--mbw-muted)">Governance controls for the accounting workspace (blueprint pages 5, 18, 19).</p>
        <div class="settings-grid">
            <label class="settings-check">
                <input type="checkbox" name="approvals_enabled" value="1" <?= approvals_enabled() ? 'checked' : '' ?>>
                <span>Require approval for manual vouchers<small>When on, accountants submit vouchers for an approver to post from Audit Trail &amp; Approvals.</small></span>
            </label>
            <label>Default VAT / tax rate (%)
                <input type="number" step="0.01" min="0" name="default_vat_rate" value="<?= e((string) default_vat_rate()) ?>">
            </label>
            <label>Lock accounting periods through
                <input type="date" name="locked_through" value="<?= e($currentLockThrough ?? '') ?>">
                <small>Vouchers dated on/before this date cannot be posted for <?= e($settingsFiscalYear['label'] ?? 'the current fiscal year') ?>. Clear to unlock.</small>
            </label>
        </div>
        <div class="settings-actions"><button type="submit" class="button"><?= icon('settings') ?>Save fiscal controls</button></div>
    </section>
</form>

<form method="post" class="settings-form" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <section class="mbw-card" id="company-profile">
        <div class="mbw-card-head">
            <h2>Company Profile</h2>
            <div class="mbw-card-tools"><span class="mbw-chip tone-blue"><?= icon('companies') ?></span></div>
        </div>
        <p style="margin:0 0 12px;color:var(--mbw-muted)">Public identity, contact details, homepage text, and default currency.</p>
        <div class="settings-grid">
            <label>Site name<input type="text" name="site_name" value="<?= e($settings['site_name'] ?? APP_NAME) ?>"></label>
            <label>Site tagline<input type="text" name="site_tagline" value="<?= e($settings['site_tagline'] ?? '') ?>"></label>
            <label>Support email<input type="email" name="support_email" value="<?= e($settings['support_email'] ?? '') ?>"></label>
            <label>Support phone<input type="text" name="support_phone" value="<?= e($settings['support_phone'] ?? '') ?>"></label>
            <label>Office address<input type="text" name="office_address" value="<?= e($settings['office_address'] ?? '') ?>"></label>
            <label>Currency symbol<input type="text" name="currency_symbol" value="<?= e($settings['currency_symbol'] ?? '$') ?>"></label>
            <div class="settings-span-2 document-assets-upload">
                <div>
                    <label>Company logo<input type="file" name="company_logo" accept="image/png,image/jpeg,image/webp,image/gif"></label>
                    <?php if (!empty($settings['company_logo_path'])): ?>
                        <img src="<?= e(url((string) $settings['company_logo_path'])) ?>" alt="Company logo preview">
                    <?php endif; ?>
                </div>
                <div>
                    <label>Authorized signature<input type="file" name="company_signature" accept="image/png,image/jpeg,image/webp,image/gif"></label>
                    <?php if (!empty($settings['company_signature_path'])): ?>
                        <img src="<?= e(url((string) $settings['company_signature_path'])) ?>" alt="Signature preview">
                    <?php endif; ?>
                </div>
                <div>
                    <label>Company stamp<input type="file" name="company_stamp" accept="image/png,image/jpeg,image/webp,image/gif"></label>
                    <?php if (!empty($settings['company_stamp_path'])): ?>
                        <img src="<?= e(url((string) $settings['company_stamp_path'])) ?>" alt="Company stamp preview">
                    <?php endif; ?>
                </div>
            </div>
            <label class="settings-span-2">Hero title<input type="text" name="hero_title" value="<?= e($settings['hero_title'] ?? '') ?>"></label>
            <label class="settings-span-2">Hero description<textarea name="hero_description"><?= e($settings['hero_description'] ?? '') ?></textarea></label>
            <label class="settings-span-2">About text<textarea name="about_text"><?= e($settings['about_text'] ?? '') ?></textarea></label>
        </div>
    </section>

    <section class="mbw-card" id="payment">
        <div class="mbw-card-head">
            <h2>Payment</h2>
            <div class="mbw-card-tools"><span class="mbw-chip tone-green"><?= icon('wallet') ?></span></div>
        </div>
        <p style="margin:0 0 12px;color:var(--mbw-muted)">Manual bank details and hosted checkout links.</p>
        <div class="settings-grid">
            <label>Payment mode
                <select name="payment_mode">
                    <option value="manual" <?= ($settings['payment_mode'] ?? 'manual') === 'manual' ? 'selected' : '' ?>>Manual / bank transfer</option>
                    <option value="stripe" <?= ($settings['payment_mode'] ?? '') === 'stripe' ? 'selected' : '' ?>>Stripe-ready</option>
                    <option value="paypal" <?= ($settings['payment_mode'] ?? '') === 'paypal' ? 'selected' : '' ?>>PayPal-ready</option>
                </select>
            </label>
            <label>Payment label<input type="text" name="payment_label" value="<?= e($settings['payment_label'] ?? 'Manual payment / bank transfer') ?>"></label>
            <label>Bank name<input type="text" name="bank_name" value="<?= e($settings['bank_name'] ?? '') ?>"></label>
            <label>Bank account name<input type="text" name="bank_account_name" value="<?= e($settings['bank_account_name'] ?? '') ?>"></label>
            <label>Bank account number<input type="text" name="bank_account_number" value="<?= e($settings['bank_account_number'] ?? '') ?>"></label>
            <label>Stripe checkout URL<input type="url" name="stripe_checkout_url" value="<?= e($settings['stripe_checkout_url'] ?? '') ?>" placeholder="https://buy.stripe.com/..."></label>
            <label>PayPal checkout URL<input type="url" name="paypal_checkout_url" value="<?= e($settings['paypal_checkout_url'] ?? '') ?>" placeholder="https://www.paypal.com/..."></label>
            <label class="settings-span-2">Payment note<textarea name="payment_note"><?= e($settings['payment_note'] ?? '') ?></textarea></label>
        </div>
    </section>

    <section class="mbw-card" id="invoice-settings">
        <div class="mbw-card-head">
            <h2>Invoice settings</h2>
            <div class="mbw-card-tools"><span class="mbw-chip tone-purple"><?= icon('invoices') ?></span></div>
        </div>
        <p style="margin:0 0 12px;color:var(--mbw-muted)">Separate setup for proforma invoices and tax invoices.</p>
        <div class="settings-subgrid">
            <div class="settings-subcard">
                <h3><?= icon('invoices') ?>Proforma invoice settings</h3>
                <div class="settings-grid compact">
                    <label>Invoice prefix<input type="text" name="proforma_invoice_prefix" value="<?= e($settings['proforma_invoice_prefix'] ?? 'PRO') ?>"></label>
                    <label>Invoice title<input type="text" name="proforma_invoice_title" value="<?= e($settings['proforma_invoice_title'] ?? 'Proforma Invoice') ?>"></label>
                    <label class="settings-span-2">Invoice note<textarea name="proforma_invoice_note"><?= e($settings['proforma_invoice_note'] ?? '') ?></textarea></label>
                    <label class="settings-span-2">Terms and conditions<textarea name="proforma_invoice_terms"><?= e($settings['proforma_invoice_terms'] ?? '') ?></textarea></label>
                    <label class="settings-span-2">Footer text<textarea name="proforma_invoice_footer"><?= e($settings['proforma_invoice_footer'] ?? '') ?></textarea></label>
                </div>
            </div>
            <div class="settings-subcard">
                <h3><?= icon('reports') ?>Tax invoice settings</h3>
                <div class="settings-grid compact">
                    <label>Invoice prefix<input type="text" name="tax_invoice_prefix" value="<?= e($settings['tax_invoice_prefix'] ?? 'TAX') ?>"></label>
                    <label>Invoice title<input type="text" name="tax_invoice_title" value="<?= e($settings['tax_invoice_title'] ?? 'Tax Invoice') ?>"></label>
                    <label>Tax label<input type="text" name="tax_invoice_tax_label" value="<?= e($settings['tax_invoice_tax_label'] ?? 'VAT') ?>"></label>
                    <label>Tax rate %<input type="number" step="0.01" min="0" name="tax_invoice_tax_rate" value="<?= e($settings['tax_invoice_tax_rate'] ?? '0') ?>"></label>
                    <label class="settings-span-2">Invoice note<textarea name="tax_invoice_note"><?= e($settings['tax_invoice_note'] ?? '') ?></textarea></label>
                    <label class="settings-span-2">Terms and conditions<textarea name="tax_invoice_terms"><?= e($settings['tax_invoice_terms'] ?? '') ?></textarea></label>
                    <label class="settings-span-2">Footer text<textarea name="tax_invoice_footer"><?= e($settings['tax_invoice_footer'] ?? '') ?></textarea></label>
                </div>
            </div>
        </div>
    </section>

    <section class="mbw-card" id="notification-access">
        <div class="mbw-card-head">
            <h2>Notification access</h2>
            <div class="mbw-card-tools"><span class="mbw-chip tone-teal"><?= icon('contact') ?></span></div>
        </div>
        <p style="margin:0 0 12px;color:var(--mbw-muted)">Control who receives system emails and how outgoing messages identify the firm.</p>
        <div class="settings-grid">
            <label>From email<input type="email" name="notification_from_email" value="<?= e($settings['notification_from_email'] ?? '') ?>"></label>
            <label>Reply-to email<input type="email" name="notification_reply_to_email" value="<?= e($settings['notification_reply_to_email'] ?? '') ?>"></label>
            <div class="settings-checkboxes settings-span-2">
                <label><input type="checkbox" name="notify_admin_email" value="1"<?= $checked('notify_admin_email', '1') ?>> Admin notifications</label>
                <label><input type="checkbox" name="notify_staff_email" value="1"<?= $checked('notify_staff_email', '1') ?>> Staff notifications</label>
                <label><input type="checkbox" name="notify_client_email" value="1"<?= $checked('notify_client_email', '1') ?>> Client notifications</label>
                <label><input type="checkbox" name="notify_payment_email" value="1"<?= $checked('notify_payment_email', '1') ?>> Payment notifications</label>
                <label><input type="checkbox" name="notify_task_email" value="1"<?= $checked('notify_task_email', '1') ?>> Task notifications</label>
            </div>
            <label class="settings-span-2">Notification footer text<textarea name="notification_footer_text"><?= e($settings['notification_footer_text'] ?? '') ?></textarea></label>
        </div>
    </section>

    <div class="settings-save-bar">
        <button type="submit"><?= icon('settings') ?>Save settings</button>
    </div>
</form>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
