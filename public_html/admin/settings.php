<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();
$pageTitle = 'Settings Center';
$pageSubtitle = 'Manage global settings that control your brand, operations, and portal experience.';
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
$settingsUserId = (int) (current_user()['id'] ?? 0);

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
                ->execute(['company_id' => $settingsCompanyId, 'fy' => $settingsFiscalYearId, 'locked_through' => $lockThrough, 'locked_by' => $settingsUserId]);
            log_activity('settings', $settingsCompanyId, 'period_locked', 'Accounting period locked through ' . $lockThrough . '.', $settingsUserId);
        } else {
            db()->prepare('DELETE FROM fiscal_period_locks WHERE company_id = :company_id AND fiscal_year_id = :fy')
                ->execute(['company_id' => $settingsCompanyId, 'fy' => $settingsFiscalYearId]);
        }
    }

    flash('success', 'Fiscal & accounting controls saved.');
    redirect('admin/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'create_fiscal_year') {
    verify_csrf();
    $label = trim((string) ($_POST['label'] ?? ''));
    $startDate = (string) ($_POST['start_date'] ?? '');
    $endDate = (string) ($_POST['end_date'] ?? '');
    $isDefault = isset($_POST['is_default']);

    if ($settingsCompanyId <= 0 || $label === '') {
        flash('error', 'Fiscal year label, start date, and end date are required.');
        redirect('admin/settings.php');
    }

    // Overlap/duplicate protection + race-safe single default live in the
    // service function, shared with the Companies page.
    $result = create_fiscal_year($settingsCompanyId, $label, $startDate, $endDate, $isDefault, $settingsUserId);
    if ($result['ok']) {
        if ($isDefault) {
            set_context($settingsCompanyId, $result['id']);
        }
        flash('success', 'Fiscal year created for accounting.');
    } else {
        flash('error', (string) $result['error']);
    }

    redirect('admin/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'set_default_fiscal_year') {
    verify_csrf();
    if (!user_can('admin')) {
        flash('error', 'Only administrators can change the company default fiscal year.');
        redirect('admin/settings.php');
    }
    require_permission('accounting', 'edit');
    $result = set_default_fiscal_year($settingsCompanyId, (int) ($_POST['fiscal_year_id'] ?? 0), $settingsUserId);
    flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Default fiscal year updated.' : (string) $result['error']);
    redirect('admin/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'transition_fiscal_year') {
    verify_csrf();
    if (!user_can('admin')) {
        flash('error', 'Only administrators can change a fiscal year\'s status.');
        redirect('admin/settings.php');
    }
    require_permission('accounting', 'edit');

    $fyId = (int) ($_POST['fiscal_year_id'] ?? 0);
    $target = (string) ($_POST['target_status'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $fy = fiscal_year_by_id($fyId);
    if (!$fy || (int) $fy['company_id'] !== $settingsCompanyId || !column_exists('fiscal_years', 'status')) {
        flash('error', 'Fiscal year not found for this company.');
        redirect('admin/settings.php');
    }
    $currentStatus = fiscal_year_status($fy);
    // Lifecycle: upcoming -> open -> closed -> locked; closed -> open is the
    // audited reopening path. Locked years never move except by unlocking to
    // closed (also audited).
    $allowedTransitions = [
        'upcoming' => ['open'],
        'open' => ['closed'],
        'closed' => ['locked', 'open'],
        'locked' => ['closed'],
    ];
    if (!in_array($target, $allowedTransitions[$currentStatus] ?? [], true)) {
        flash('error', 'Cannot change fiscal year "' . $fy['label'] . '" from ' . $currentStatus . ' to ' . $target . '.');
        redirect('admin/settings.php');
    }
    if (($currentStatus === 'closed' && $target === 'open') || ($currentStatus === 'locked' && $target === 'closed')) {
        if ($reason === '') {
            flash('error', 'Reopening or unlocking a fiscal year requires a reason (it is recorded in the audit log).');
            redirect('admin/settings.php');
        }
    }
    if ($target === 'closed' && $currentStatus === 'open') {
        // Earlier open years must be resolved first so balances brought
        // forward into this year cannot change after it is closed.
        $earlierOpen = db()->prepare("SELECT label FROM fiscal_years WHERE company_id = :cid AND end_date < :start AND status IN ('open') LIMIT 1");
        $earlierOpen->execute(['cid' => $settingsCompanyId, 'start' => (string) $fy['start_date']]);
        $earlier = $earlierOpen->fetchColumn();
        if ($earlier) {
            flash('error', 'Close earlier fiscal year "' . $earlier . '" first — a year cannot be closed while an earlier year is still open.');
            redirect('admin/settings.php');
        }
    }
    db()->prepare('UPDATE fiscal_years SET status = :status' . (column_exists('fiscal_years', 'updated_by') ? ', updated_by = :uid' : '') . ' WHERE id = :id')
        ->execute(['status' => $target, 'id' => $fyId] + (column_exists('fiscal_years', 'updated_by') ? ['uid' => $settingsUserId] : []));
    $auditNote = 'Fiscal year "' . $fy['label'] . '" status changed: ' . $currentStatus . ' -> ' . $target . ($reason !== '' ? ' — reason: ' . $reason : '') . '.';
    log_activity('fiscal_year', $fyId, 'status_' . $target, $auditNote, $settingsUserId);
    security_event('fiscal_year_' . $target, 'success', $auditNote, $settingsCompanyId, $settingsUserId);
    if ($currentStatus === 'closed' && $target === 'open') {
        // Report-based carry-forward recalculates later-year openings live,
        // so no snapshot goes stale — but the reopening itself must be loud.
        flash('success', 'Fiscal year "' . $fy['label'] . '" reopened. Later years\' opening balances and retained earnings recalculate automatically from the ledger; review them after making changes.');
    } else {
        flash('success', 'Fiscal year "' . $fy['label'] . '" is now ' . $target . '.');
    }
    redirect('admin/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_shareholding' && table_exists('company_shareholdings')) {
    verify_csrf();
    $investeeCompanyId = (int) ($_POST['investee_company_id'] ?? 0);
    $ownershipPercent = max(0, min(100, (float) ($_POST['ownership_percent'] ?? 0)));
    $relationshipType = (string) ($_POST['relationship_type'] ?? 'investment');
    $method = (string) ($_POST['consolidation_method'] ?? 'cost');

    if (!in_array($relationshipType, ['subsidiary', 'associate', 'joint_venture', 'investment'], true)) {
        $relationshipType = $ownershipPercent > 50 ? 'subsidiary' : ($ownershipPercent >= 20 ? 'associate' : 'investment');
    }
    if (!in_array($method, ['full', 'equity', 'proportionate', 'cost'], true)) {
        $method = $ownershipPercent > 50 ? 'full' : ($ownershipPercent >= 20 ? 'equity' : 'cost');
    }

    if ($ownershipPercent > 50) {
        $relationshipType = 'subsidiary';
        $method = 'full';
    } elseif ($ownershipPercent == 50.0) {
        $relationshipType = 'joint_venture';
        $method = 'proportionate';
    } elseif ($ownershipPercent >= 20) {
        $relationshipType = 'associate';
        $method = 'equity';
    } else {
        $relationshipType = 'investment';
        $method = 'cost';
    }

    if ($settingsCompanyId <= 0 || $investeeCompanyId <= 0 || $investeeCompanyId === $settingsCompanyId) {
        flash('error', 'Select a valid investee company.');
        redirect('admin/settings.php');
    }

    $stmt = db()->prepare('
        INSERT INTO company_shareholdings (investor_company_id, investee_company_id, ownership_percent, relationship_type, consolidation_method, effective_from, notes)
        VALUES (:investor_company_id, :investee_company_id, :ownership_percent, :relationship_type, :consolidation_method, :effective_from, :notes)
        ON DUPLICATE KEY UPDATE
            ownership_percent = VALUES(ownership_percent),
            relationship_type = VALUES(relationship_type),
            consolidation_method = VALUES(consolidation_method),
            effective_from = VALUES(effective_from),
            notes = VALUES(notes)
    ');
    $stmt->execute([
        'investor_company_id' => $settingsCompanyId,
        'investee_company_id' => $investeeCompanyId,
        'ownership_percent' => $ownershipPercent,
        'relationship_type' => $relationshipType,
        'consolidation_method' => $method,
        'effective_from' => $_POST['effective_from'] !== '' ? $_POST['effective_from'] : null,
        'notes' => trim((string) ($_POST['notes'] ?? '')),
    ]);

    flash('success', 'Shareholding rule saved.');
    redirect('admin/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'reset_draft') {
    verify_csrf();
    update_settings(['settings_draft' => '']);
    flash('success', 'Draft discarded — showing the live settings.');
    redirect('admin/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $saveMode = (string) ($_POST['save_mode'] ?? 'save');

    if ($saveMode === 'draft') {
        $draftPayload = $_POST;
        unset($draftPayload['csrf_token'], $draftPayload['action'], $draftPayload['save_mode']);
        update_settings(['settings_draft' => json_encode($draftPayload, JSON_UNESCAPED_SLASHES)]);
        flash('success', 'Draft saved. It stays private until you Save or Publish.');
        redirect('admin/settings.php');
    }

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
    $companyQrPath = settings_upload_image('company_qr', $currentSettings);
    // Platform (M.B. World) brand logo — used across portal/login chrome via
    // brand_logo(); light variant is for dark surfaces (sidebar). Empty keeps
    // the built-in SVG lockup.
    $platformLogoPath = settings_upload_image('platform_logo', $currentSettings);
    $platformLogoLightPath = settings_upload_image('platform_logo_light', $currentSettings);

    update_settings([
        'site_name' => trim((string) ($_POST['site_name'] ?? APP_NAME)),
        'site_tagline' => trim((string) ($_POST['site_tagline'] ?? '')),
        'support_email' => trim((string) ($_POST['support_email'] ?? '')),
        'support_phone' => trim((string) ($_POST['support_phone'] ?? '')),
        'office_address' => trim((string) ($_POST['office_address'] ?? '')),
        'company_name_np' => trim((string) ($_POST['company_name_np'] ?? '')),
        'company_pan' => trim((string) ($_POST['company_pan'] ?? '')),
        'company_vat_no' => trim((string) ($_POST['company_vat_no'] ?? '')),
        'currency_symbol' => trim((string) ($_POST['currency_symbol'] ?? '$')),
        'hero_title' => trim((string) ($_POST['hero_title'] ?? '')),
        'hero_description' => trim((string) ($_POST['hero_description'] ?? '')),
        'about_text' => trim((string) ($_POST['about_text'] ?? '')),
        'company_logo_path' => $companyLogoPath,
        'company_signature_path' => $companySignaturePath,
        'company_stamp_path' => $companyStampPath,
        'company_qr_path' => $companyQrPath,
        'platform_logo_path' => $platformLogoPath,
        'platform_logo_light_path' => $platformLogoLightPath,

        'payment_mode' => $paymentMode,
        'payment_label' => trim((string) ($_POST['payment_label'] ?? 'Manual payment / bank transfer')),
        'bank_name' => trim((string) ($_POST['bank_name'] ?? '')),
        'bank_account_name' => trim((string) ($_POST['bank_account_name'] ?? '')),
        'bank_account_number' => trim((string) ($_POST['bank_account_number'] ?? '')),
        'bank_branch' => trim((string) ($_POST['bank_branch'] ?? '')),
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

        'sync_client_portal' => isset($_POST['sync_client_portal']) ? '1' : '0',
        'sync_home_page' => isset($_POST['sync_home_page']) ? '1' : '0',
        'security_session_timeout' => (string) max(5, min(1440, (int) ($_POST['security_session_timeout'] ?? 120))),
        'security_password_min_length' => (string) max(6, min(64, (int) ($_POST['security_password_min_length'] ?? 8))),
        'security_2fa_required' => isset($_POST['security_2fa_required']) ? '1' : '0',
        'audit_retention_days' => (string) max(30, min(3650, (int) ($_POST['audit_retention_days'] ?? 365))),

        'settings_draft' => '',
    ]);

    // Per-company portal logo (companies.logo_path) — this organization's own
    // logo, shown inside its own portal. Uploaded to the current company only.
    if ($settingsCompanyId > 0 && column_exists('companies', 'logo_path')) {
        $companyOwnLogo = settings_upload_image('company_portal_logo', ['company_portal_logo_path' => (string) ($settingsCompany['logo_path'] ?? '')]);
        if ($companyOwnLogo !== '') {
            db()->prepare('UPDATE companies SET logo_path = :p WHERE id = :id')
                ->execute(['p' => $companyOwnLogo, 'id' => $settingsCompanyId]);
        }
    }

    log_activity('settings', $settingsCompanyId, $saveMode === 'publish' ? 'settings_published' : 'settings_saved', $saveMode === 'publish' ? 'Settings published to all portals.' : 'Settings updated.', $settingsUserId);
    flash('success', $saveMode === 'publish' ? 'Settings published to the admin portal, client portal, and home page.' : 'Settings updated.');
    redirect('admin/settings.php');
}

$settings = all_settings();
$draftRaw = (string) ($settings['settings_draft'] ?? '');
$draft = $draftRaw !== '' ? (json_decode($draftRaw, true) ?: []) : [];
$hasDraft = $draft !== [];
if ($hasDraft) {
    // Draft overlays the live values for display until saved or discarded.
    $settings = array_merge($settings, array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $draft));
}
$checked = static fn (string $key, string $default = '0'): string => (($settings[$key] ?? $default) === '1') ? ' checked' : '';

// Section registry: tab, icon, tone, blurb, and the keys that drive completeness.
$sectionDefs = [
    'branding' => ['tab' => 'branding', 'icon' => 'companies', 'tone' => 'blue', 'title' => 'Company Profile & Branding', 'blurb' => 'Manage company identity, contact details, logos, colors, and brand assets.', 'keys' => ['site_name', 'site_tagline', 'support_email', 'support_phone', 'office_address', 'currency_symbol', 'company_logo_path']],
    'homepage' => ['tab' => 'branding', 'icon' => 'home', 'tone' => 'amber', 'title' => 'Home Page Content', 'blurb' => 'Control homepage hero, about text, services, CTAs, and contact highlights.', 'keys' => ['hero_title', 'hero_description', 'about_text']],
    'fiscal' => ['tab' => 'accounting', 'icon' => 'accounting', 'tone' => 'green', 'title' => 'Fiscal & Accounting Controls', 'blurb' => 'Approval workflows, period lock, tax rates, fiscal year, and voucher numbering.', 'keys' => ['approvals_enabled', 'default_vat_rate']],
    'payments' => ['tab' => 'payments', 'icon' => 'card', 'tone' => 'purple', 'title' => 'Payment Settings', 'blurb' => 'Bank details, payment methods, Stripe/PayPal, and payment notes.', 'keys' => ['payment_mode', 'payment_label', 'bank_name', 'bank_account_name', 'bank_account_number']],
    'invoice' => ['tab' => 'invoice', 'icon' => 'invoices', 'tone' => 'teal', 'title' => 'Invoice Settings', 'blurb' => 'Configure proforma and tax invoice labels, notes, terms, and footer text.', 'keys' => ['proforma_invoice_prefix', 'proforma_invoice_title', 'tax_invoice_prefix', 'tax_invoice_title', 'tax_invoice_tax_label', 'tax_invoice_tax_rate']],
    'notifications' => ['tab' => 'notifications', 'icon' => 'messages', 'tone' => 'red', 'title' => 'Notification Access', 'blurb' => 'Manage email addresses, notification preferences, and system alerts.', 'keys' => ['notification_from_email', 'notification_reply_to_email', 'notify_admin_email', 'notify_client_email']],
    'sync' => ['tab' => 'sync', 'icon' => 'reconcile', 'tone' => 'blue', 'title' => 'Portal Sync & Visibility Rules', 'blurb' => 'Choose which settings appear on Admin Portal, Client Portal, and Home Page.', 'keys' => ['sync_client_portal', 'sync_home_page']],
    'security' => ['tab' => 'security', 'icon' => 'admin', 'tone' => 'green', 'title' => 'Security & Access Controls', 'blurb' => 'Session timeout, password policy, 2FA policy, audit retention, and backups.', 'keys' => ['security_session_timeout', 'security_password_min_length', 'audit_retention_days']],
];

$sectionCompleteness = [];
foreach ($sectionDefs as $sectionKey => $def) {
    $filled = 0;
    foreach ($def['keys'] as $k) {
        if (trim((string) ($settings[$k] ?? '')) !== '') {
            $filled++;
        }
    }
    $sectionCompleteness[$sectionKey] = (int) round(($filled / max(1, count($def['keys']))) * 100);
}
$overallCompleteness = (int) round(array_sum($sectionCompleteness) / max(1, count($sectionCompleteness)));

// Status strip data.
$lastUpdatedRow = db()->query('SELECT setting_key, updated_at FROM settings ORDER BY updated_at DESC LIMIT 1')->fetch();
$recentChanges = db()->query("SELECT setting_key, updated_at FROM settings WHERE setting_key <> 'settings_draft' ORDER BY updated_at DESC LIMIT 4")->fetchAll();
$notifyActive = ($settings['notify_admin_email'] ?? '1') === '1' || ($settings['notify_client_email'] ?? '1') === '1';
$isClientBooksPortal = (int) ($settingsCompany['is_client_company'] ?? 0) === 1;
$friendlyKey = static fn (string $k): string => ucwords(str_replace('_', ' ', preg_replace('/_path$/', '', $k)));

$currentLockThrough = period_locked_through($settingsCompanyId, $settingsFiscalYearId);
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<?php if ($hasDraft): ?>
    <div class="notice success" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <span>A saved draft is loaded below — the live settings are unchanged until you Save or Publish.</span>
        <form method="post" style="margin-left:auto">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="reset_draft">
            <button type="submit" class="button secondary" style="min-height:32px;padding:4px 12px">Discard Draft</button>
        </form>
    </div>
<?php endif; ?>

<nav class="mbw-tabbar" aria-label="Settings tabs" id="stc-tabs">
    <?php foreach (['general' => ['settings', 'General'], 'branding' => ['companies', 'Branding'], 'accounting' => ['accounting', 'Accounting'], 'payments' => ['card', 'Payments'], 'invoice' => ['invoices', 'Invoice'], 'notifications' => ['messages', 'Notifications'], 'sync' => ['reconcile', 'Portal Sync'], 'security' => ['admin', 'Security']] as $tabKey => [$tabIcon, $tabLabel]): ?>
        <a class="mbw-tab <?= $tabKey === 'general' ? 'is-active' : '' ?>" href="#" data-stab="<?= e($tabKey) ?>"><?= icon($tabIcon) ?><?= e($tabLabel) ?></a>
    <?php endforeach; ?>
</nav>

<section class="mbw-kpi-grid" aria-label="Settings status">
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Last updated</span><div class="mbw-kpi-value" style="font-size:15px"><?= $lastUpdatedRow ? e(date('d M Y, h:i A', strtotime((string) $lastUpdatedRow['updated_at']))) : '—' ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= $lastUpdatedRow ? e($friendlyKey((string) $lastUpdatedRow['setting_key'])) : 'No changes yet' ?></span></span></div><span class="mbw-chip tone-blue"><?= icon('attendance') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Active company</span><div class="mbw-kpi-value" style="font-size:15px"><?= e($settingsCompany['name'] ?? '—') ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= $isClientBooksPortal ? 'Client accounting books' : 'Group company portal' ?></span></span></div><span class="mbw-chip tone-purple"><?= icon('companies') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Sync status</span><div class="mbw-kpi-value" style="font-size:15px;color:var(--mbw-green)"><?= $hasDraft ? 'Draft pending' : 'Synchronized' ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= $hasDraft ? 'Unpublished draft loaded' : 'All portals up to date' ?></span></span></div><span class="mbw-chip tone-teal"><?= icon('reconcile') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Branding completeness</span><div class="mbw-kpi-value" style="font-size:15px"><?= (int) $sectionCompleteness['branding'] ?>% Complete</div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><span style="display:inline-block;width:110px;height:6px;border-radius:99px;background:var(--mbw-gray-soft);vertical-align:middle"><span style="display:block;width:<?= (int) $sectionCompleteness['branding'] ?>%;height:6px;border-radius:99px;background:var(--mbw-green)"></span></span></span></span></div><span class="mbw-chip tone-amber"><?= icon('target') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Notification status</span><div class="mbw-kpi-value" style="font-size:15px;color:<?= $notifyActive ? 'var(--mbw-green)' : 'var(--mbw-red)' ?>"><?= $notifyActive ? 'Active' : 'Muted' ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= $notifyActive ? 'Email channels enabled' : 'All channels off' ?></span></span></div><span class="mbw-chip tone-red"><?= icon('messages') ?></span></article>
</section>

<div class="frm-layout">
<div class="frm-main">

<!-- Fiscal controls keep their own form (separate save action) -->
<details class="mbw-card stc-card" data-section="accounting" open>
    <summary class="stc-head">
        <span class="mbw-chip is-square tone-<?= e($sectionDefs['fiscal']['tone']) ?>"><?= icon($sectionDefs['fiscal']['icon']) ?></span>
        <span class="stc-title"><strong><?= e($sectionDefs['fiscal']['title']) ?></strong><small><?= e($sectionDefs['fiscal']['blurb']) ?></small></span>
        <span class="mbw-pill <?= $sectionCompleteness['fiscal'] >= 90 ? 'tone-green' : 'tone-amber' ?>"><?= (int) $sectionCompleteness['fiscal'] ?>% Complete</span>
        <span class="stc-caret"><?= icon('chevron') ?></span>
    </summary>
    <form method="post" class="frm-grid frm-grid-4" style="padding-top:14px">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_fiscal_controls">
        <label class="frm-toggle-wrap">Approval workflow
            <span class="frm-toggle"><input type="checkbox" name="approvals_enabled" value="1" <?= approvals_enabled() ? 'checked' : '' ?>><i></i></span>
        </label>
        <label>Default VAT rate (%)<input type="number" step="0.01" min="0" name="default_vat_rate" value="<?= e((string) default_vat_rate()) ?>"></label>
        <label>Period locked through<input type="date" name="locked_through" value="<?= e($currentLockThrough ?? '') ?>"></label>
        <label>Fiscal year<input type="text" value="<?= e($settingsFiscalYear['label'] ?? 'From portal context') ?>" disabled></label>
        <div style="grid-column:1/-1"><button type="submit" class="button secondary"><?= icon('accounting') ?>Save Accounting Controls</button></div>
    </form>
</details>

<details class="mbw-card stc-card" data-section="accounting" open>
    <summary class="stc-head">
        <span class="mbw-chip is-square tone-blue"><?= icon('calendar') ?></span>
        <span class="stc-title"><strong>Create Fiscal Year</strong><small>Fiscal years drive voucher posting, reports, and period locks.</small></span>
        <span class="stc-caret"><?= icon('chevron') ?></span>
    </summary>
    <form method="post" class="frm-grid frm-grid-4" style="padding-top:14px">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create_fiscal_year">
        <label>Fiscal year label<input type="text" name="label" placeholder="FY 2026-2027" required></label>
        <label>Start date<input type="date" name="start_date" required></label>
        <label>End date<input type="date" name="end_date" required></label>
        <label class="frm-toggle-wrap">Use as default year
            <span class="frm-toggle"><input type="checkbox" name="is_default" value="1" checked><i></i></span>
        </label>
        <div style="grid-column:1/-1"><button type="submit" class="button secondary"><?= icon('calendar') ?>Create Fiscal Year</button></div>
    </form>

    <?php
    $settingsFyRows = fiscal_years_for_company($settingsCompanyId, false);
    $settingsFyHasStatus = column_exists('fiscal_years', 'status');
    ?>
    <div style="padding-top:16px">
        <h3 style="margin:0 0 8px">Fiscal years of <?= e($settingsCompany['name'] ?? 'this company') ?></h3>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Fiscal year</th><th>Period</th><th>Status</th><th>Default</th><th>Cutoff (locked through)</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if ($settingsFyRows === []): ?><tr><td colspan="6">No fiscal years yet — create one above.</td></tr><?php endif; ?>
            <?php foreach ($settingsFyRows as $fyRow): ?>
                <?php
                $fyRowStatus = fiscal_year_status($fyRow);
                $fyStatusTone = ['upcoming' => 'blue', 'open' => 'green', 'closed' => 'amber', 'locked' => 'red'][$fyRowStatus] ?? 'gray';
                $fyRowCutoff = period_locked_through($settingsCompanyId, (int) $fyRow['id']);
                $fyTransitions = [
                    'upcoming' => [['open', 'Open year', false]],
                    'open' => [['closed', 'Close year', false]],
                    'closed' => [['locked', 'Lock year', false], ['open', 'Reopen', true]],
                    'locked' => [['closed', 'Unlock to closed', true]],
                ][$fyRowStatus] ?? [];
                ?>
                <tr>
                    <td><strong><?= e($fyRow['label']) ?></strong></td>
                    <td><?= e($fyRow['start_date']) ?> — <?= e($fyRow['end_date']) ?></td>
                    <td><span class="mbw-pill tone-<?= e($fyStatusTone) ?>"><?= e(ucfirst($fyRowStatus)) ?></span></td>
                    <td>
                        <?php if ((int) $fyRow['is_default'] === 1): ?>
                            <span class="mbw-pill tone-green">Default</span>
                        <?php else: ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="set_default_fiscal_year">
                                <input type="hidden" name="fiscal_year_id" value="<?= (int) $fyRow['id'] ?>">
                                <button type="submit" class="button secondary" style="min-height:30px;padding:3px 10px">Make default</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td><?= $fyRowCutoff !== null ? e($fyRowCutoff) : '<span style="color:var(--mbw-muted)">none</span>' ?></td>
                    <td>
                        <?php if (!$settingsFyHasStatus): ?>
                            <span style="color:var(--mbw-muted);font-size:12px">Run migration 051 for status controls</span>
                        <?php else: ?>
                            <?php foreach ($fyTransitions as [$fyTarget, $fyLabel, $fyNeedsReason]): ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('<?= e($fyLabel) ?> — <?= e($fyRow['label']) ?>?')">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="transition_fiscal_year">
                                    <input type="hidden" name="fiscal_year_id" value="<?= (int) $fyRow['id'] ?>">
                                    <input type="hidden" name="target_status" value="<?= e($fyTarget) ?>">
                                    <?php if ($fyNeedsReason): ?><input type="text" name="reason" placeholder="Reason (audited)" required style="min-width:140px"><?php endif; ?>
                                    <button type="submit" class="button <?= $fyTarget === 'locked' ? 'danger' : 'secondary' ?>" style="min-height:30px;padding:3px 10px"><?= e($fyLabel) ?></button>
                                </form>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <p style="color:var(--mbw-muted);font-size:12px;margin:8px 0 0">Lifecycle: Upcoming → Open → Closed → Locked. Closed and locked years stay fully viewable and exportable but reject every accounting change. Reopening requires a reason and is written to the audit log.</p>
    </div>
</details>

<?php
$settingsInvestees = [];
if (table_exists('company_shareholdings')) {
    $investeeStmt = db()->prepare('SELECT id, name, code FROM companies WHERE id <> :cid AND is_active = 1 AND COALESCE(is_client_company, 0) = 0 ORDER BY name ASC');
    $investeeStmt->execute(['cid' => $settingsCompanyId]);
    $settingsInvestees = $investeeStmt->fetchAll();
}
?>
<?php if ($settingsInvestees !== []): ?>
<details class="mbw-card stc-card" data-section="accounting">
    <summary class="stc-head">
        <span class="mbw-chip is-square tone-purple"><?= icon('companies') ?></span>
        <span class="stc-title"><strong>Shareholding &amp; Consolidation</strong><small>Set subsidiary, associate, joint venture, and investment treatment for consolidated reports.</small></span>
        <span class="stc-caret"><?= icon('chevron') ?></span>
    </summary>
    <form method="post" class="frm-grid frm-grid-4" style="padding-top:14px">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_shareholding">
        <label>Investee company
            <select name="investee_company_id" required>
                <option value="">Select company</option>
                <?php foreach ($settingsInvestees as $investee): ?>
                    <option value="<?= (int) $investee['id'] ?>"><?= e($investee['name'] . ' / ' . $investee['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Ownership %<input type="number" name="ownership_percent" step="0.01" min="0" max="100" value="100" required></label>
        <label>Relationship
            <select name="relationship_type">
                <option value="subsidiary">Subsidiary</option>
                <option value="associate">Associate</option>
                <option value="joint_venture">Joint venture</option>
                <option value="investment">Investment</option>
            </select>
        </label>
        <label>Consolidation method
            <select name="consolidation_method">
                <option value="full">Full consolidation</option>
                <option value="equity">Equity method</option>
                <option value="proportionate">Proportionate</option>
                <option value="cost">Cost / no consolidation</option>
            </select>
        </label>
        <label>Effective from<input type="date" name="effective_from"></label>
        <label class="frm-span-3" style="grid-column:span 3">Notes<input type="text" name="notes" placeholder="IAS 28, IFRS 10, NCI, associate basis, or internal note"></label>
        <div style="grid-column:1/-1"><button type="submit" class="button secondary"><?= icon('companies') ?>Save Shareholding Rule</button></div>
    </form>
</details>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="stc-main-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="save_mode" id="stc-save-mode" value="save">

    <details class="mbw-card stc-card" data-section="branding" open>
        <summary class="stc-head">
            <span class="mbw-chip is-square tone-blue"><?= icon('companies') ?></span>
            <span class="stc-title"><strong>Company Profile &amp; Branding</strong><small><?= e($sectionDefs['branding']['blurb']) ?></small></span>
            <span class="mbw-pill <?= $sectionCompleteness['branding'] >= 90 ? 'tone-green' : 'tone-amber' ?>"><?= (int) $sectionCompleteness['branding'] ?>% Complete</span>
            <span class="stc-caret"><?= icon('chevron') ?></span>
        </summary>
        <div class="frm-grid frm-grid-4" style="padding-top:14px">
            <label>Site name<input type="text" name="site_name" value="<?= e($settings['site_name'] ?? APP_NAME) ?>"></label>
            <label>Site tagline<input type="text" name="site_tagline" value="<?= e($settings['site_tagline'] ?? '') ?>"></label>
            <label>Support email<input type="email" name="support_email" value="<?= e($settings['support_email'] ?? '') ?>"></label>
            <label>Support phone<input type="text" name="support_phone" value="<?= e($settings['support_phone'] ?? '') ?>"></label>
            <label>Office address<input type="text" name="office_address" value="<?= e($settings['office_address'] ?? '') ?>"></label>
            <label>Company name (Nepali)<input type="text" name="company_name_np" value="<?= e($settings['company_name_np'] ?? '') ?>" placeholder="एक्सल बिजनेस कन्सल्टिङ प्रा.लि."></label>
            <label>PAN number<input type="text" name="company_pan" value="<?= e($settings['company_pan'] ?? '') ?>"></label>
            <label>VAT registration no.<input type="text" name="company_vat_no" value="<?= e($settings['company_vat_no'] ?? '') ?>" placeholder="Same as PAN if unified"></label>
            <label>Currency symbol<input type="text" name="currency_symbol" value="<?= e($settings['currency_symbol'] ?? '$') ?>"></label>
            <label>Company logo<input type="file" name="company_logo" accept="image/png,image/jpeg,image/webp,image/gif"><?= ($settings['company_logo_path'] ?? '') !== '' ? '<small style="color:var(--mbw-green)">Uploaded ✓</small>' : '' ?></label>
            <label>Authorized signature<input type="file" name="company_signature" accept="image/png,image/jpeg,image/webp,image/gif"><?= ($settings['company_signature_path'] ?? '') !== '' ? '<small style="color:var(--mbw-green)">Uploaded ✓</small>' : '' ?></label>
            <label>Company stamp<input type="file" name="company_stamp" accept="image/png,image/jpeg,image/webp,image/gif"><?= ($settings['company_stamp_path'] ?? '') !== '' ? '<small style="color:var(--mbw-green)">Uploaded ✓</small>' : '' ?></label>
            <label>Payment QR code<input type="file" name="company_qr" accept="image/png,image/jpeg,image/webp,image/gif"><?= ($settings['company_qr_path'] ?? '') !== '' ? '<small style="color:var(--mbw-green)">Uploaded ✓</small>' : '' ?></label>
            <label>M.B. World platform logo <small style="color:var(--mbw-muted)">(shown on login, portal &amp; footers)</small><input type="file" name="platform_logo" accept="image/png,image/jpeg,image/webp,image/gif"><?= ($settings['platform_logo_path'] ?? '') !== '' ? '<small style="color:var(--mbw-green)">Uploaded ✓</small>' : '<small style="color:var(--mbw-muted)">Using built-in logo</small>' ?></label>
            <label>Platform logo — light/inverse <small style="color:var(--mbw-muted)">(for dark sidebars)</small><input type="file" name="platform_logo_light" accept="image/png,image/jpeg,image/webp,image/gif"><?= ($settings['platform_logo_light_path'] ?? '') !== '' ? '<small style="color:var(--mbw-green)">Uploaded ✓</small>' : '<small style="color:var(--mbw-muted)">Falls back to built-in</small>' ?></label>
            <label>This company's portal logo <small style="color:var(--mbw-muted)">(shown inside <?= e($settingsCompany['name'] ?? 'this company') ?>'s own portal)</small><input type="file" name="company_portal_logo" accept="image/png,image/jpeg,image/webp,image/gif"><?= (($settingsCompany['logo_path'] ?? '') !== '') ? '<small style="color:var(--mbw-green)">Uploaded ✓</small>' : '<small style="color:var(--mbw-muted)">Optional</small>' ?></label>
        </div>
    </details>

    <details class="mbw-card stc-card" data-section="branding">
        <summary class="stc-head">
            <span class="mbw-chip is-square tone-amber"><?= icon('home') ?></span>
            <span class="stc-title"><strong>Home Page Content</strong><small><?= e($sectionDefs['homepage']['blurb']) ?></small></span>
            <span class="mbw-pill <?= $sectionCompleteness['homepage'] >= 90 ? 'tone-green' : 'tone-amber' ?>"><?= (int) $sectionCompleteness['homepage'] ?>% Complete</span>
            <span class="stc-caret"><?= icon('chevron') ?></span>
        </summary>
        <div class="frm-grid frm-grid-2" style="padding-top:14px">
            <label class="frm-span-2" style="grid-column:1/-1">Hero title<input type="text" name="hero_title" value="<?= e($settings['hero_title'] ?? '') ?>" placeholder="Integrated solutions. Trusted guidance. Stronger tomorrow."></label>
            <label>Hero description<textarea name="hero_description" rows="3"><?= e($settings['hero_description'] ?? '') ?></textarea></label>
            <label>About text<textarea name="about_text" rows="3"><?= e($settings['about_text'] ?? '') ?></textarea></label>
        </div>
    </details>

    <details class="mbw-card stc-card" data-section="payments">
        <summary class="stc-head">
            <span class="mbw-chip is-square tone-purple"><?= icon('card') ?></span>
            <span class="stc-title"><strong>Payment Settings</strong><small><?= e($sectionDefs['payments']['blurb']) ?></small></span>
            <span class="mbw-pill <?= $sectionCompleteness['payments'] >= 90 ? 'tone-green' : 'tone-amber' ?>"><?= (int) $sectionCompleteness['payments'] ?>% Complete</span>
            <span class="stc-caret"><?= icon('chevron') ?></span>
        </summary>
        <div class="frm-grid frm-grid-4" style="padding-top:14px">
            <label>Payment mode
                <select name="payment_mode">
                    <?php foreach (['manual' => 'Manual / bank transfer', 'stripe' => 'Stripe checkout', 'paypal' => 'PayPal checkout'] as $pmValue => $pmLabel): ?>
                        <option value="<?= e($pmValue) ?>" <?= ($settings['payment_mode'] ?? 'manual') === $pmValue ? 'selected' : '' ?>><?= e($pmLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Payment label<input type="text" name="payment_label" value="<?= e($settings['payment_label'] ?? 'Manual payment / bank transfer') ?>"></label>
            <label>Bank name<input type="text" name="bank_name" value="<?= e($settings['bank_name'] ?? '') ?>"></label>
            <label>Bank account name<input type="text" name="bank_account_name" value="<?= e($settings['bank_account_name'] ?? '') ?>"></label>
            <label>Bank account number<input type="text" name="bank_account_number" value="<?= e($settings['bank_account_number'] ?? '') ?>"></label>
            <label>Bank branch<input type="text" name="bank_branch" value="<?= e($settings['bank_branch'] ?? '') ?>" placeholder="e.g. Sankhamul"></label>
            <label>Stripe checkout URL<input type="url" name="stripe_checkout_url" value="<?= e($settings['stripe_checkout_url'] ?? '') ?>" placeholder="https://buy.stripe.com/..."></label>
            <label>PayPal checkout URL<input type="url" name="paypal_checkout_url" value="<?= e($settings['paypal_checkout_url'] ?? '') ?>" placeholder="https://www.paypal.com/..."></label>
            <label>Payment note<input type="text" name="payment_note" value="<?= e($settings['payment_note'] ?? '') ?>"></label>
        </div>
    </details>

    <details class="mbw-card stc-card" data-section="invoice">
        <summary class="stc-head">
            <span class="mbw-chip is-square tone-teal"><?= icon('invoices') ?></span>
            <span class="stc-title"><strong>Invoice Settings</strong><small><?= e($sectionDefs['invoice']['blurb']) ?></small></span>
            <span class="mbw-pill <?= $sectionCompleteness['invoice'] >= 90 ? 'tone-green' : 'tone-amber' ?>"><?= (int) $sectionCompleteness['invoice'] ?>% Complete</span>
            <span class="stc-caret"><?= icon('chevron') ?></span>
        </summary>
        <div class="frm-grid frm-grid-4" style="padding-top:14px">
            <label>Proforma prefix<input type="text" name="proforma_invoice_prefix" value="<?= e($settings['proforma_invoice_prefix'] ?? 'PRO') ?>"></label>
            <label>Proforma title<input type="text" name="proforma_invoice_title" value="<?= e($settings['proforma_invoice_title'] ?? 'Proforma Invoice') ?>"></label>
            <label>Proforma note<input type="text" name="proforma_invoice_note" value="<?= e($settings['proforma_invoice_note'] ?? '') ?>"></label>
            <label>Proforma terms<input type="text" name="proforma_invoice_terms" value="<?= e($settings['proforma_invoice_terms'] ?? '') ?>"></label>
            <label>Proforma footer<input type="text" name="proforma_invoice_footer" value="<?= e($settings['proforma_invoice_footer'] ?? '') ?>"></label>
            <label>Tax invoice prefix<input type="text" name="tax_invoice_prefix" value="<?= e($settings['tax_invoice_prefix'] ?? 'TAX') ?>"></label>
            <label>Tax invoice title<input type="text" name="tax_invoice_title" value="<?= e($settings['tax_invoice_title'] ?? 'Tax Invoice') ?>"></label>
            <label>Tax label<input type="text" name="tax_invoice_tax_label" value="<?= e($settings['tax_invoice_tax_label'] ?? 'VAT') ?>"></label>
            <label>Tax rate %<input type="number" step="0.01" min="0" name="tax_invoice_tax_rate" value="<?= e($settings['tax_invoice_tax_rate'] ?? '0') ?>"></label>
            <label>Tax invoice note<input type="text" name="tax_invoice_note" value="<?= e($settings['tax_invoice_note'] ?? '') ?>"></label>
            <label>Tax invoice terms<input type="text" name="tax_invoice_terms" value="<?= e($settings['tax_invoice_terms'] ?? '') ?>"></label>
            <label>Tax invoice footer<input type="text" name="tax_invoice_footer" value="<?= e($settings['tax_invoice_footer'] ?? '') ?>"></label>
        </div>
    </details>

    <details class="mbw-card stc-card" data-section="notifications">
        <summary class="stc-head">
            <span class="mbw-chip is-square tone-red"><?= icon('messages') ?></span>
            <span class="stc-title"><strong>Notification Access</strong><small><?= e($sectionDefs['notifications']['blurb']) ?></small></span>
            <span class="mbw-pill <?= $sectionCompleteness['notifications'] >= 90 ? 'tone-green' : 'tone-amber' ?>"><?= (int) $sectionCompleteness['notifications'] ?>% Complete</span>
            <span class="stc-caret"><?= icon('chevron') ?></span>
        </summary>
        <div class="frm-grid frm-grid-4" style="padding-top:14px">
            <label>From email<input type="email" name="notification_from_email" value="<?= e($settings['notification_from_email'] ?? '') ?>"></label>
            <label>Reply-to email<input type="email" name="notification_reply_to_email" value="<?= e($settings['notification_reply_to_email'] ?? '') ?>"></label>
            <label class="frm-span-2" style="grid-column:span 2">Email footer text<input type="text" name="notification_footer_text" value="<?= e($settings['notification_footer_text'] ?? '') ?>"></label>
            <?php foreach ([['notify_admin_email', 'Admin alerts'], ['notify_staff_email', 'Staff alerts'], ['notify_client_email', 'Client alerts'], ['notify_payment_email', 'Payment alerts'], ['notify_task_email', 'Task alerts']] as [$notifyKey, $notifyLabel]): ?>
                <label class="frm-toggle-wrap"><?= e($notifyLabel) ?>
                    <span class="frm-toggle"><input type="checkbox" name="<?= e($notifyKey) ?>" value="1"<?= $checked($notifyKey, '1') ?>><i></i></span>
                </label>
            <?php endforeach; ?>
        </div>
    </details>

    <details class="mbw-card stc-card" data-section="sync">
        <summary class="stc-head">
            <span class="mbw-chip is-square tone-blue"><?= icon('reconcile') ?></span>
            <span class="stc-title"><strong>Portal Sync &amp; Visibility Rules</strong><small><?= e($sectionDefs['sync']['blurb']) ?></small></span>
            <span class="mbw-pill <?= $sectionCompleteness['sync'] >= 90 ? 'tone-green' : 'tone-amber' ?>"><?= (int) $sectionCompleteness['sync'] ?>% Complete</span>
            <span class="stc-caret"><?= icon('chevron') ?></span>
        </summary>
        <div class="frm-grid frm-grid-4" style="padding-top:14px">
            <label class="frm-toggle-wrap">Admin Portal
                <span class="frm-toggle"><input type="checkbox" checked disabled title="Settings always apply to the admin portal"><i></i></span>
            </label>
            <label class="frm-toggle-wrap">Client Portal
                <span class="frm-toggle"><input type="checkbox" name="sync_client_portal" value="1"<?= $checked('sync_client_portal', '1') ?>><i></i></span>
            </label>
            <label class="frm-toggle-wrap">Home Page
                <span class="frm-toggle"><input type="checkbox" name="sync_home_page" value="1"<?= $checked('sync_home_page', '1') ?>><i></i></span>
            </label>
            <p style="grid-column:1/-1;margin:0;color:var(--mbw-muted);font-size:12px">Branding, contact details, and payment info flow to the client portal and the public home page when their toggles are on. The hero and about text above render on the public home page.</p>
        </div>
    </details>

    <details class="mbw-card stc-card" data-section="security">
        <summary class="stc-head">
            <span class="mbw-chip is-square tone-green"><?= icon('admin') ?></span>
            <span class="stc-title"><strong>Security &amp; Access Controls</strong><small><?= e($sectionDefs['security']['blurb']) ?></small></span>
            <span class="mbw-pill <?= $sectionCompleteness['security'] >= 90 ? 'tone-green' : 'tone-amber' ?>"><?= (int) $sectionCompleteness['security'] ?>% Complete</span>
            <span class="stc-caret"><?= icon('chevron') ?></span>
        </summary>
        <div class="frm-grid frm-grid-4" style="padding-top:14px">
            <label>Session timeout (minutes)<input type="number" min="5" max="1440" name="security_session_timeout" value="<?= e($settings['security_session_timeout'] ?? '120') ?>"></label>
            <label>Password minimum length<input type="number" min="6" max="64" name="security_password_min_length" value="<?= e($settings['security_password_min_length'] ?? '8') ?>"></label>
            <label>Audit log retention (days)<input type="number" min="30" max="3650" name="audit_retention_days" value="<?= e($settings['audit_retention_days'] ?? '365') ?>"></label>
            <label class="frm-toggle-wrap">Require 2FA (policy)
                <span class="frm-toggle"><input type="checkbox" name="security_2fa_required" value="1"<?= $checked('security_2fa_required') ?>><i></i></span>
            </label>
            <p style="grid-column:1/-1;margin:0;color:var(--mbw-muted);font-size:12px">These are stored as the account security policy. Enforcement is rolled out per module — the audit trail already records every change.</p>
        </div>
    </details>
</form>

<div class="frm-footer mbw-card" style="position:sticky;bottom:12px">
    <span style="color:var(--mbw-muted);font-size:12px"><?= icon('about') ?> Changes can be applied selectively across portals. Drafts are saved separately from live settings.</span>
    <button type="submit" form="stc-main-form" class="button secondary" onclick="document.getElementById('stc-save-mode').value='draft'"><?= icon('documents') ?>Save Draft</button>
    <button type="submit" form="stc-main-form" class="button" onclick="document.getElementById('stc-save-mode').value='save'" style="margin-left:auto"><?= icon('tasks') ?>Save Settings</button>
    <button type="submit" form="stc-main-form" class="button" onclick="document.getElementById('stc-save-mode').value='publish'" style="background:var(--mbw-gold);color:#1a2233"><?= icon('portal') ?>Publish to Portals</button>
</div>
</div>

<aside class="frm-rail">
    <section class="mbw-card frm-rail-card">
        <div class="frm-section-head"><span class="mbw-chip is-square tone-blue"><?= icon('portal') ?></span><h2>Apply Changes To</h2></div>
        <p style="margin:0 0 10px;color:var(--mbw-muted);font-size:12px">Choose where these settings should be applied.</p>
        <div style="display:grid;gap:8px">
            <label class="frm-toggle-wrap" style="display:flex;justify-content:space-between;border:1px solid var(--mbw-border);border-radius:10px;padding:9px 12px">Admin Portal
                <span class="frm-toggle"><input type="checkbox" checked disabled><i></i></span>
            </label>
            <label class="frm-toggle-wrap" style="display:flex;justify-content:space-between;border:1px solid var(--mbw-border);border-radius:10px;padding:9px 12px">Client Portal
                <span class="frm-toggle"><input type="checkbox" name="sync_client_portal_rail" value="1"<?= $checked('sync_client_portal', '1') ?> onclick="var m=document.querySelector('#stc-main-form [name=sync_client_portal]'); if(m){m.checked=this.checked;}"><i></i></span>
            </label>
            <label class="frm-toggle-wrap" style="display:flex;justify-content:space-between;border:1px solid var(--mbw-gold);border-radius:10px;padding:9px 12px">Home Page
                <span class="frm-toggle"><input type="checkbox" name="sync_home_page_rail" value="1"<?= $checked('sync_home_page', '1') ?> onclick="var m=document.querySelector('#stc-main-form [name=sync_home_page]'); if(m){m.checked=this.checked;}"><i></i></span>
            </label>
        </div>
    </section>

    <section class="mbw-card frm-rail-card">
        <div class="frm-section-head"><span class="mbw-chip is-square tone-teal"><?= icon('reconcile') ?></span><h2>Sync Preview</h2></div>
        <div class="frm-summary">
            <div><dt style="color:var(--mbw-muted);font-size:12px;font-weight:600">Admin Portal</dt><dd style="margin:0;font-size:12.5px;font-weight:600;color:var(--mbw-heading)"><?= count($sectionDefs) ?> sections will be updated</dd></div>
            <div><dt style="color:var(--mbw-muted);font-size:12px;font-weight:600">Client Portal</dt><dd style="margin:0;font-size:12.5px;font-weight:600;color:var(--mbw-heading)"><?= ($settings['sync_client_portal'] ?? '1') === '1' ? '5 sections will be updated' : 'Sync off' ?></dd></div>
            <div><dt style="color:var(--mbw-muted);font-size:12px;font-weight:600">Home Page</dt><dd style="margin:0;font-size:12.5px;font-weight:600;color:var(--mbw-heading)"><?= ($settings['sync_home_page'] ?? '1') === '1' ? '3 sections will be updated' : 'Sync off' ?></dd></div>
        </div>
    </section>

    <section class="mbw-card frm-rail-card">
        <div class="frm-section-head"><span class="mbw-chip is-square tone-green"><?= icon('tasks') ?></span><h2>Validation Checklist</h2><span class="mbw-pill tone-green" style="margin-left:auto"><?= (int) $overallCompleteness ?>%</span></div>
        <ul class="frm-checklist">
            <?php foreach ($sectionDefs as $sectionKey => $def): ?>
                <li class="<?= $sectionCompleteness[$sectionKey] >= 90 ? 'is-ok' : '' ?>"><?= e($def['title']) ?><span style="float:right;font-size:11px;color:<?= $sectionCompleteness[$sectionKey] >= 90 ? 'var(--mbw-green)' : 'var(--mbw-amber)' ?>"><?= $sectionCompleteness[$sectionKey] >= 90 ? 'Complete' : 'Needs review' ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="mbw-card frm-rail-card">
        <div class="frm-section-head"><span class="mbw-chip is-square tone-amber"><?= icon('attendance') ?></span><h2>Recent Changes</h2></div>
        <div style="display:grid;gap:9px">
            <?php if ($recentChanges === []): ?><p style="margin:0;color:var(--mbw-muted);font-size:12px">No changes recorded yet.</p><?php endif; ?>
            <?php foreach ($recentChanges as $change): ?>
                <div style="display:flex;justify-content:space-between;gap:8px;font-size:12px">
                    <span style="color:var(--mbw-heading);font-weight:600"><?= e($friendlyKey((string) $change['setting_key'])) ?> updated</span>
                    <span style="color:var(--mbw-muted);white-space:nowrap"><?= e(date('d M, h:i A', strtotime((string) $change['updated_at']))) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</aside>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var tabs = document.querySelectorAll('#stc-tabs .mbw-tab');
    var cards = document.querySelectorAll('.stc-card');
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (event) {
            event.preventDefault();
            tabs.forEach(function (t) { t.classList.remove('is-active'); });
            tab.classList.add('is-active');
            var key = tab.getAttribute('data-stab');
            cards.forEach(function (card) {
                var show = key === 'general' || card.getAttribute('data-section') === key;
                card.style.display = show ? '' : 'none';
                if (show && key !== 'general') { card.setAttribute('open', ''); }
            });
        });
    });
});
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
