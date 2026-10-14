<?php
declare(strict_types=1);
function company_license_today(): string { return (new DateTimeImmutable('now',new DateTimeZone('Asia/Kathmandu')))->format('Y-m-d'); }
function company_license_read(int $id): array
{
    $stmt = db()->prepare("SELECT setting_value FROM settings WHERE company_id = ? AND setting_key = 'company_license'");
    $stmt->execute([$id]);
    return json_decode((string)$stmt->fetchColumn(),true) ?: [];
}
function company_license_state(array $license, ?string $today = null): string
{
    if (empty($license['hash'])) return 'Not issued';
    if (empty($license['enabled'])) return 'Inactive';
    if (empty($license['expires']) || $license['expires'] < ($today ?? company_license_today())) return 'Expired';
    return empty($license['activated_at']) ? 'Awaiting activation' : 'Active';
}
function company_license_write(int $id,array $license): void
{
    db()->prepare("INSERT INTO settings (company_id,setting_key,setting_value) VALUES (?, 'company_license', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$id,json_encode($license,JSON_THROW_ON_ERROR)]);
}
function company_license_expiry(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < company_license_today()) throw new RuntimeException('Select an expiry date of today or later.');
    return $date;
}
/** Caller owns the transaction. Key is shown once, only its hash is stored. */
function company_license_issue(int $id,string $expires): string
{
    if (!user_is_super_admin()) throw new RuntimeException('Super admin required.');
    $expires = company_license_expiry($expires);
    $key = strtoupper(implode('-',str_split(bin2hex(random_bytes(20)),8)));
    $previous = company_license_read($id);
    // Unknown historical generation dates stay unknown; never substitute redemption.
    $issuedAt = $previous['issued_at'] ?? (empty($previous['hash']) ? (new DateTimeImmutable('now',new DateTimeZone('Asia/Kathmandu')))->format('Y-m-d H:i:s') : null);
    company_license_write($id,['hash'=>hash('sha256',$key),'enabled'=>true,'expires'=>$expires,'issued_at'=>$issuedAt,'activated_at'=>null]);
    return $key;
}
function company_license_owner(array $user,int $id): bool
{
    if (!user_can_access_company($id,$user)) return false;
    if (($user['role'] ?? '') === 'admin') return true;
    if (($user['role'] ?? '') !== 'customer') return false;
    $profile = client_profile_for_user((int)$user['id']);
    return $profile && !empty($profile['is_active']) && (int)($profile['books_company_id'] ?? 0) === $id && ($profile['portal_member_role'] ?? '') === 'owner';
}
/** Caller holds the company lock. This operation cannot extend expiry or enable access. */
function company_license_activate(int $id,array $user,string $key): void
{
    if (!company_license_owner($user,$id)) throw new RuntimeException('Company owner or administrator required.');
    $license = company_license_read($id);
    if (company_license_state($license) !== 'Awaiting activation') throw new RuntimeException('The license is unavailable, inactive or expired. Contact the platform administrator.');
    $key = strtoupper(trim($key));
    if (!hash_equals($license['hash'],hash('sha256',$key))) throw new RuntimeException('Invalid license key.');
    $license['activated_at'] = (new DateTimeImmutable('now',new DateTimeZone('Asia/Kathmandu')))->format('Y-m-d H:i:s');
    $license['activated_by'] = (int)$user['id'];
    company_license_write($id,$license);
}
function company_license_require(int $id): void
{
    $user = current_user();
    if (!$user || user_is_super_admin($user) || $id <= 0) return;
    if (company_license_state(company_license_read($id)) !== 'Active') {
        $_SESSION['license_company_id'] = $id;
        redirect('activate-company.php');
    }
}
function company_license_enforce_request(): void
{
    if (PHP_SAPI === 'cli') return;
    $user = current_user();
    if (!$user || user_is_super_admin($user)) return;
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script,['activate-company.php','change-password.php','two-factor.php','logout.php'],true)) return;
    $id = current_company_id();
    if (($user['role'] ?? '') === 'customer') {
        $profile = client_profile_for_user((int)$user['id']);
        $id = (int)($profile['books_company_id'] ?? 0);
    }
    if ($id <= 0) $id = (int)($_SESSION['login_company_id'] ?? $_SESSION['license_company_id'] ?? 0);
    company_license_require($id);
}
