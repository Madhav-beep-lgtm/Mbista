<?php
declare(strict_types=1);
if (!function_exists('send_app_email')) require_once __DIR__ . '/mailer.php';

/** Send only after commit. Never log plaintext credentials using the log transport. */
function company_access_email(array $company, string $email, string $key, ?string $password = null): bool
{
    $previousScope = $GLOBALS['mail_settings_company_id'] ?? null;
    // Platform-issued credentials must never use an unrelated open tenant's SMTP.
    $GLOBALS['mail_settings_company_id'] = 0;
    try {
        if (!mail_is_configured()) return false;
        $html = '<p>' . e($company['name']) . '</p>'
            . '<p><strong>Company code:</strong> ' . e($company['code']) . '</p>'
            . '<p><strong>User ID / login email:</strong> ' . e($email) . '</p>'
            . '<p><strong>License key:</strong> ' . e($key) . '</p>';
        if ($password !== null) $html .= '<p><strong>Temporary password:</strong> ' . e($password) . '</p><p>Change your temporary password at first sign-in.</p>';
        $html .= '<p><a href="' . e(mail_public_url('login.php')) . '">Sign in</a> and enter the license key to activate your company.</p>';
        $result = send_app_email($email, 'Your company access', branded_email_html('Company access', $html));
        return !empty($result['ok']) && ($result['transport'] ?? 'log') !== 'log';
    } catch (Throwable $exception) {
        // Transport errors must not leak credentials or invalidate committed onboarding.
        return false;
    } finally {
        if ($previousScope === null) unset($GLOBALS['mail_settings_company_id']);
        else $GLOBALS['mail_settings_company_id'] = $previousScope;
    }
}
