<?php
declare(strict_types=1);

/**
 * Platform access control (migration 033).
 *
 * Answers one question everywhere in the app: *which companies may this user
 * touch?* Before this module the only thing between an admin and any company's
 * books was a 4-digit PIN, so a service-provider admin could open M.Bista's or
 * a rival provider's portal. Authorization is now derived from explicit
 * memberships plus the corporate hierarchy, and it is checked server-side on
 * every company switch, every page load with company context, and every
 * company-scoped record fetch that routes through require_record_company().
 *
 * Degradation rule: if company_memberships is missing (DB not migrated yet) the
 * scope falls back to *derived* rules (home company + its subsidiaries), never
 * to open access.
 */

// ---------------------------------------------------------------------------
// Schema self-healing (mirrors the app's existing repair-on-load convention)
// ---------------------------------------------------------------------------

function access_control_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        if (!table_exists('company_memberships') && table_exists('users') && table_exists('companies')) {
            db()->exec(
                'CREATE TABLE IF NOT EXISTS company_memberships (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    company_id INT UNSIGNED NOT NULL,
                    access_level ENUM(\'super_admin\',\'parent_admin\',\'subsidiary_admin\',\'accountant\',\'approver\',\'viewer\',\'support\') NOT NULL DEFAULT \'accountant\',
                    is_primary TINYINT(1) NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    granted_by INT UNSIGNED DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_company_membership (user_id, company_id),
                    KEY idx_company_memberships_company (company_id, is_active),
                    KEY idx_company_memberships_user (user_id, is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            db()->exec(
                'INSERT IGNORE INTO company_memberships (user_id, company_id, access_level, is_primary, is_active)
                 SELECT u.id, u.company_id, u.access_level, 1, 1
                 FROM users u JOIN companies c ON c.id = u.company_id
                 WHERE u.company_id IS NOT NULL'
            );
        }

        if (!table_exists('security_events')) {
            db()->exec(
                'CREATE TABLE IF NOT EXISTS security_events (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT UNSIGNED DEFAULT NULL,
                    email VARCHAR(190) DEFAULT NULL,
                    event_type VARCHAR(60) NOT NULL,
                    company_id INT UNSIGNED DEFAULT NULL,
                    outcome ENUM(\'success\',\'denied\',\'failure\') NOT NULL DEFAULT \'success\',
                    details VARCHAR(500) DEFAULT NULL,
                    request_path VARCHAR(255) DEFAULT NULL,
                    ip_address VARCHAR(60) DEFAULT NULL,
                    user_agent VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_security_events_type_time (event_type, created_at),
                    KEY idx_security_events_user_time (user_id, created_at),
                    KEY idx_security_events_ip_time (ip_address, created_at),
                    KEY idx_security_events_outcome_time (outcome, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        if (table_exists('users') && !column_exists('users', 'sessions_valid_from')) {
            db()->exec('ALTER TABLE users ADD COLUMN sessions_valid_from DATETIME DEFAULT NULL');
        }

        if (!table_exists('staff_permissions') && table_exists('users')) {
            db()->exec(
                'CREATE TABLE IF NOT EXISTS staff_permissions (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    module_key VARCHAR(40) NOT NULL,
                    action_key VARCHAR(40) NOT NULL,
                    granted_by INT UNSIGNED DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_staff_permission (user_id, module_key, action_key),
                    KEY idx_staff_permissions_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        // Migration 049: per-client accounting access for staff accountants.
        if (!table_exists('staff_client_accounting_access') && table_exists('users') && table_exists('client_profiles')) {
            db()->exec(
                'CREATE TABLE IF NOT EXISTS staff_client_accounting_access (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    company_id INT UNSIGNED DEFAULT NULL,
                    staff_user_id INT UNSIGNED NOT NULL,
                    client_id INT UNSIGNED NOT NULL,
                    granted_by INT UNSIGNED DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_staff_client_accounting (staff_user_id, client_id),
                    KEY idx_staff_client_accounting_company (company_id),
                    KEY idx_staff_client_accounting_client (client_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        // Migration 045: extra customer logins per client organization.
        if (!table_exists('client_portal_users') && table_exists('client_profiles')) {
            db()->exec(
                'CREATE TABLE IF NOT EXISTS client_portal_users (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    client_id INT UNSIGNED NOT NULL,
                    user_id INT UNSIGNED NOT NULL,
                    member_role ENUM(\'owner\',\'approver\',\'entry_maker\') NOT NULL DEFAULT \'entry_maker\',
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_by INT UNSIGNED DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_client_portal_user (user_id),
                    KEY idx_client_portal_users_client (client_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
    } catch (Throwable $exception) {
        // Never take the site down over the repair path; the degradation rule
        // below keeps authorization closed rather than open.
    }
}

// ---------------------------------------------------------------------------
// Client portal membership
// ---------------------------------------------------------------------------

/**
 * The client profile a customer login belongs to: either as the OWNER
 * (client_profiles.user_id, the login the firm created with the profile) or
 * as an additional MEMBER the owner added from the portal (client_portal_users,
 * migration 045). A login belongs to at most one client organization.
 *
 * Every "which client is this customer?" lookup must go through here so that
 * member logins see the same portal as the owner.
 */
function client_profile_for_user(?int $userId = null): ?array
{
    $userId = $userId ?? (int) (current_user()['id'] ?? 0);
    if ($userId <= 0 || !table_exists('client_profiles')) {
        return null;
    }

    static $cache = [];
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    $stmt = db()->prepare('SELECT cp.*, \'owner\' AS portal_member_role FROM client_profiles cp WHERE cp.user_id = :uid LIMIT 1');
    $stmt->execute(['uid' => $userId]);
    $profile = $stmt->fetch() ?: null;

    if (!$profile && table_exists('client_portal_users')) {
        $stmt = db()->prepare(
            'SELECT cp.*, cpu.member_role AS portal_member_role
             FROM client_portal_users cpu
             INNER JOIN client_profiles cp ON cp.id = cpu.client_id
             WHERE cpu.user_id = :uid AND cpu.is_active = 1
             LIMIT 1'
        );
        $stmt->execute(['uid' => $userId]);
        $profile = $stmt->fetch() ?: null;
    }

    $cache[$userId] = $profile;

    return $profile;
}

/** True when the customer login owns its client profile (may manage users). */
function client_portal_user_is_owner(?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user || (string) ($user['role'] ?? '') !== 'customer') {
        return false;
    }
    $profile = client_profile_for_user((int) $user['id']);

    return $profile !== null && (string) ($profile['portal_member_role'] ?? '') === 'owner';
}

/**
 * True while the current session is a customer login working INSIDE its own
 * client books company. Every client accounting right hangs off this check.
 */
function client_portal_in_own_books(?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user || (string) ($user['role'] ?? '') !== 'customer') {
        return false;
    }
    $profile = client_profile_for_user((int) $user['id']);
    if (!$profile || empty($profile['is_active'])) {
        return false;
    }
    $booksCompanyId = (int) ($profile['books_company_id'] ?? 0);

    return $booksCompanyId > 0 && current_company_id() === $booksCompanyId;
}

/**
 * Coarse voucher-lifecycle capability of a client portal login, by the role
 * the organization's owner assigned:
 *   owner / approver — view, create, edit, approve, post, report
 *   entry_maker      — view, create, edit, report (never approve/post)
 * delete and admin capabilities stay with the firm.
 */
function client_portal_capability(string $capability, ?array $user = null): bool
{
    if (!client_portal_in_own_books($user)) {
        return false;
    }
    $user = $user ?? current_user();
    $profile = client_profile_for_user((int) $user['id']);
    $memberRole = (string) ($profile['portal_member_role'] ?? '');
    $canApprove = in_array($memberRole, ['owner', 'approver'], true);

    return match ($capability) {
        'view', 'create', 'edit', 'report' => true,
        'approve', 'post' => $canApprove,
        default => false,
    };
}

/**
 * Client books always run the voucher approval workflow: an entry maker's
 * voucher must wait for an owner/approver even when the firm-wide
 * approvals_enabled setting is off. Owners/approvers self-approve as usual.
 */
function client_portal_forces_approval(?array $user = null): bool
{
    $user = $user ?? current_user();

    return (string) ($user['role'] ?? '') === 'customer';
}

/**
 * Page gate for the shared accounting workspace: admins and staff pass as
 * before; a customer passes only while the active company context is their
 * own books company (entered via open-books.php, which also verifies the
 * PIN-equivalent). Anyone else is turned away.
 */
function require_staff_admin_or_client_books(): void
{
    require_login();
    $user = current_user();
    $role = (string) ($user['role'] ?? '');

    if (in_array($role, ['admin', 'staff'], true)) {
        return;
    }

    if ($role === 'customer') {
        if (client_portal_in_own_books($user)) {
            return;
        }
        flash('error', 'Open your accounting books from the dashboard first.');
        redirect('dashboard.php');
    }

    flash('error', 'Staff or admin access required.');
    redirect('login.php');
}

// ---------------------------------------------------------------------------
// Security event log
// ---------------------------------------------------------------------------

/**
 * Records a security-relevant event: login, failed login, logout, org switch,
 * role/permission change, activation/suspension, password reset, posting,
 * reversal, export, and every restricted-access attempt.
 */
function security_event(string $eventType, string $outcome = 'success', ?string $details = null, ?int $companyId = null, ?int $userId = null, ?string $email = null): void
{
    access_control_ensure_schema();
    if (!table_exists('security_events')) {
        return;
    }

    if ($userId === null && !empty($_SESSION['user_id'])) {
        $userId = (int) $_SESSION['user_id'];
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO security_events (user_id, email, event_type, company_id, outcome, details, request_path, ip_address, user_agent)
             VALUES (:user_id, :email, :event_type, :company_id, :outcome, :details, :request_path, :ip_address, :user_agent)'
        );
        $stmt->execute([
            'user_id' => $userId ?: null,
            'email' => $email !== null ? substr($email, 0, 190) : null,
            'event_type' => substr($eventType, 0, 60),
            'company_id' => $companyId ?: null,
            'outcome' => in_array($outcome, ['success', 'denied', 'failure'], true) ? $outcome : 'success',
            'details' => $details !== null ? substr($details, 0, 500) : null,
            'request_path' => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 255),
            'ip_address' => substr(client_ip_address(), 0, 60),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $exception) {
        // Logging must never break the request it is auditing.
    }
}

function client_ip_address(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

// ---------------------------------------------------------------------------
// Company scope
// ---------------------------------------------------------------------------

/**
 * A super admin is a platform operator (M. Bista & Associates). Only they see
 * across tenant boundaries.
 */
function user_is_super_admin(?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        return false;
    }

    return current_access_level_for($user) === 'super_admin';
}

/**
 * access_level of a specific user row, applying the legacy inference that
 * current_access_level() uses (admins of company 0/1 with no explicit level).
 */
function current_access_level_for(array $user): string
{
    $level = (string) ($user['access_level'] ?? '');
    if (($user['role'] ?? '') === 'admin' && ($level === '' || $level === 'accountant')) {
        $companyId = (int) ($user['company_id'] ?? 0);
        return in_array($companyId, [0, 1], true) ? 'super_admin' : 'parent_admin';
    }

    return $level !== '' ? $level : 'viewer';
}

/**
 * Every company below $companyId in the parent_company_id tree, inclusive.
 */
function company_descendant_ids(int $companyId): array
{
    static $cache = [];
    if ($companyId <= 0 || !table_exists('companies')) {
        return [];
    }
    if (isset($cache[$companyId])) {
        return $cache[$companyId];
    }

    $ids = [$companyId];
    $frontier = [$companyId];
    $guard = 0;

    while ($frontier !== [] && $guard++ < 20) {
        $placeholders = implode(',', array_fill(0, count($frontier), '?'));
        $stmt = db()->prepare('SELECT id FROM companies WHERE parent_company_id IN (' . $placeholders . ')');
        $stmt->execute($frontier);
        $children = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $children = array_values(array_diff($children, $ids));
        if ($children === []) {
            break;
        }
        $ids = array_merge($ids, $children);
        $frontier = $children;
    }

    $cache[$companyId] = $ids;

    return $ids;
}

/**
 * The client-books companies belonging to clients served by any of $companyIds.
 */
function client_books_company_ids_for_servers(array $companyIds): array
{
    if ($companyIds === [] || !table_exists('client_profiles') || !column_exists('client_profiles', 'books_company_id')) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
    $stmt = db()->prepare('SELECT books_company_id FROM client_profiles WHERE books_company_id IS NOT NULL AND is_active = 1 AND company_id IN (' . $placeholders . ')');
    $stmt->execute(array_values($companyIds));

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Client ids a staff member may account for (migration 049): active rows the
 * admin granted in staff_client_accounting_access. Cached per request.
 */
function staff_accounting_client_ids(?int $staffUserId = null): array
{
    $staffUserId = $staffUserId ?? (int) (current_user()['id'] ?? 0);
    if ($staffUserId <= 0 || !table_exists('staff_client_accounting_access')) {
        return [];
    }

    static $cache = [];
    if (isset($cache[$staffUserId])) {
        return $cache[$staffUserId];
    }

    $stmt = db()->prepare('SELECT client_id FROM staff_client_accounting_access WHERE staff_user_id = :uid AND is_active = 1');
    $stmt->execute(['uid' => $staffUserId]);
    $cache[$staffUserId] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    return $cache[$staffUserId];
}

/**
 * Replace a staff member's client accounting grants with $clientIds.
 * Deactivates removed grants (keeps the audit trail) and re-activates or
 * inserts the granted ones. Unknown client ids are silently dropped.
 */
function set_staff_accounting_clients(int $staffUserId, array $clientIds, ?int $grantedBy = null): void
{
    if ($staffUserId <= 0 || !table_exists('staff_client_accounting_access')) {
        return;
    }

    $clientIds = array_values(array_unique(array_filter(array_map('intval', $clientIds), static fn (int $id): bool => $id > 0)));

    db()->prepare('UPDATE staff_client_accounting_access SET is_active = 0 WHERE staff_user_id = :uid')
        ->execute(['uid' => $staffUserId]);

    if ($clientIds === []) {
        return;
    }

    $companyStmt = db()->prepare('SELECT id, company_id FROM client_profiles WHERE id IN (' . implode(',', array_fill(0, count($clientIds), '?')) . ')');
    $companyStmt->execute($clientIds);
    $clientCompanies = [];
    foreach ($companyStmt->fetchAll() as $row) {
        $clientCompanies[(int) $row['id']] = (int) ($row['company_id'] ?? 0);
    }

    $grantStmt = db()->prepare('INSERT INTO staff_client_accounting_access (company_id, staff_user_id, client_id, granted_by, is_active)
        VALUES (:company_id, :staff_user_id, :client_id, :granted_by, 1)
        ON DUPLICATE KEY UPDATE is_active = 1, granted_by = VALUES(granted_by), company_id = VALUES(company_id)');
    foreach ($clientIds as $clientId) {
        if (!isset($clientCompanies[$clientId])) {
            continue;
        }
        $grantStmt->execute([
            'company_id' => $clientCompanies[$clientId] > 0 ? $clientCompanies[$clientId] : null,
            'staff_user_id' => $staffUserId,
            'client_id' => $clientId,
            'granted_by' => $grantedBy,
        ]);
    }
}

/**
 * True while a STAFF login is working inside a client's books company.
 * Their vouchers are then always forced to pending_approval and flagged
 * requires_client_approval — both the client (owner/approver) and the firm
 * admin are notified and either may approve. Staff never self-approve there.
 */
function staff_accountant_forces_approval(?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user || (string) ($user['role'] ?? '') !== 'staff') {
        return false;
    }

    $companyId = current_company_id();
    if ($companyId <= 0 || !table_exists('client_profiles') || !column_exists('client_profiles', 'books_company_id')) {
        return false;
    }

    static $cache = [];
    if (!array_key_exists($companyId, $cache)) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM client_profiles WHERE books_company_id = :cid');
        $stmt->execute(['cid' => $companyId]);
        $cache[$companyId] = (int) $stmt->fetchColumn() > 0;
    }

    return $cache[$companyId];
}

/**
 * The complete set of company ids the user may open, read or report on.
 *
 *  - Super Admin (M. Bista & Associates): every active company.
 *  - Admin (service provider / client admin): home company + its subsidiaries
 *    + explicit memberships + the books of clients those companies serve.
 *  - Staff: home company + explicit memberships + books of clients assigned
 *    to them personally or granted to them for accounting (migration 049).
 *  - Customer (client login): the books company of their own client profile.
 */
function authorized_company_ids(?array $user = null): array
{
    $user = $user ?? current_user();
    if (!$user) {
        return [];
    }

    static $cache = [];
    $cacheKey = (int) $user['id'];
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    access_control_ensure_schema();

    $role = (string) ($user['role'] ?? '');
    $userId = (int) $user['id'];

    // Super admins are unrestricted by design.
    if (user_is_super_admin($user)) {
        $ids = table_exists('companies')
            ? array_map('intval', db()->query('SELECT id FROM companies WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN))
            : [];
        $cache[$cacheKey] = $ids;

        return $ids;
    }

    $ids = [];

    // Home company and, for admins, everything beneath it in the group tree.
    $homeCompanyId = (int) ($user['company_id'] ?? 0);
    if ($homeCompanyId > 0) {
        $ids = $role === 'admin' ? company_descendant_ids($homeCompanyId) : [$homeCompanyId];
    }

    // Explicit memberships (granted by a super admin or a company's own admin).
    if (table_exists('company_memberships')) {
        $stmt = db()->prepare('SELECT company_id FROM company_memberships WHERE user_id = :uid AND is_active = 1');
        $stmt->execute(['uid' => $userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $membershipCompanyId) {
            $membershipCompanyId = (int) $membershipCompanyId;
            // An admin's membership carries its subsidiaries with it.
            $ids = array_merge($ids, $role === 'admin' ? company_descendant_ids($membershipCompanyId) : [$membershipCompanyId]);
        }
    }

    if ($role === 'admin') {
        // Books of every client served by a company this admin controls.
        $ids = array_merge($ids, client_books_company_ids_for_servers($ids));
    } elseif ($role === 'staff' && table_exists('client_profiles') && column_exists('client_profiles', 'books_company_id')) {
        // Staff reach only the clients assigned to them.
        $stmt = db()->prepare('SELECT books_company_id FROM client_profiles WHERE assigned_staff_user_id = :uid AND books_company_id IS NOT NULL AND is_active = 1');
        $stmt->execute(['uid' => $userId]);
        $ids = array_merge($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

        // ... plus the books of clients the admin granted them accounting
        // access to (staff_client_accounting_access, migration 049).
        $grantedClientIds = staff_accounting_client_ids($userId);
        if ($grantedClientIds !== []) {
            $placeholders = implode(',', array_fill(0, count($grantedClientIds), '?'));
            $stmt = db()->prepare('SELECT books_company_id FROM client_profiles WHERE id IN (' . $placeholders . ') AND books_company_id IS NOT NULL AND is_active = 1');
            $stmt->execute($grantedClientIds);
            $ids = array_merge($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
    } elseif ($role === 'customer' && table_exists('client_profiles') && column_exists('client_profiles', 'books_company_id')) {
        // A client login (owner or added portal member) reaches only its own books.
        $profile = client_profile_for_user($userId);
        if ($profile && !empty($profile['is_active']) && (int) ($profile['books_company_id'] ?? 0) > 0) {
            $ids[] = (int) $profile['books_company_id'];
        }
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

    // Never hand back a suspended/archived company.
    if ($ids !== [] && table_exists('companies')) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare('SELECT id FROM companies WHERE is_active = 1 AND id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $cache[$cacheKey] = $ids;

    return $ids;
}

function user_can_access_company(int $companyId, ?array $user = null): bool
{
    if ($companyId <= 0) {
        return false;
    }

    return in_array($companyId, authorized_company_ids($user), true);
}

/**
 * The authorized companies as full rows, for portal selectors and switchers.
 */
function authorized_companies(?array $user = null): array
{
    $ids = authorized_company_ids($user);
    if ($ids === [] || !table_exists('companies')) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        'SELECT c.*, p.name AS parent_company_name
         FROM companies c
         LEFT JOIN companies p ON p.id = c.parent_company_id
         WHERE c.is_active = 1 AND c.id IN (' . $placeholders . ')
         ORDER BY c.is_client_company ASC, c.parent_company_id IS NULL DESC, c.name ASC'
    );
    $stmt->execute($ids);

    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Denial
// ---------------------------------------------------------------------------

/**
 * Hard stop for an unauthorized request: audit it, then 403. Used for IDOR
 * attempts (tampered ids in URLs/APIs) rather than a friendly redirect, so the
 * attempt is unmistakable in the log and in tests.
 */
function deny_access(string $reason, ?int $companyId = null): never
{
    security_event('access_denied', 'denied', $reason, $companyId);

    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden', true, 403);
        header('Content-Type: text/html; charset=utf-8');
    }

    $isJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'forbidden', 'message' => 'You are not authorized to access this record.']);
        exit;
    }

    $home = function_exists('role_home_path') ? role_home_path() : 'login.php';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>403 — Access denied</title>'
        . '<link rel="icon" type="image/svg+xml" href="' . e(url('assets/img/favicon.svg')) . '">'
        . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'font-family:Inter,-apple-system,"Segoe UI",sans-serif;background:#0b1c36;color:#eaf1ff;padding:24px}'
        . '.box{max-width:460px;text-align:center}.mark{width:56px;height:56px;border-radius:14px;background:#0b1c36;'
        . 'border:3px solid #d9a33a;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;'
        . 'font-family:Georgia,serif;font-weight:700;font-size:22px}h1{font-size:20px;margin:0 0 10px}'
        . 'p{color:#9fb2cc;line-height:1.6;margin:0 0 22px;font-size:14px}'
        . 'a{display:inline-block;background:#d9a33a;color:#0b1c36;text-decoration:none;font-weight:700;'
        . 'padding:11px 20px;border-radius:9px;font-size:14px}</style></head><body><div class="box">'
        . '<div class="mark">MB</div><h1>Access denied</h1>'
        . '<p>You are not authorized to view this record or organization. This attempt has been logged.</p>'
        . '<a href="' . e(url($home)) . '">Return to your portal</a></div></body></html>';
    exit;
}

/**
 * Gate for any page that acts on an explicit company id from the request.
 */
function require_company_access(int $companyId): void
{
    if (!user_can_access_company($companyId)) {
        deny_access('Attempted to access company #' . $companyId . ' without authorization.', $companyId);
    }
}

/**
 * Gate for a fetched record: proves the row belongs to a company the user may
 * touch. Pass the row (or null) and the column holding its company id.
 */
function require_record_company(?array $record, string $companyColumn = 'company_id'): array
{
    if (!$record) {
        deny_access('Record not found or not visible in the active scope.');
    }

    $companyId = (int) ($record[$companyColumn] ?? 0);
    if ($companyId <= 0 || !user_can_access_company($companyId)) {
        deny_access('Record belongs to company #' . $companyId . ', outside the caller\'s scope.', $companyId);
    }

    return $record;
}

// ---------------------------------------------------------------------------
// Session lifecycle
// ---------------------------------------------------------------------------

/**
 * Invalidates every existing session of a user. Called on suspension,
 * deactivation, role/permission change and password reset.
 */
function revoke_user_sessions(int $userId): void
{
    access_control_ensure_schema();
    if ($userId <= 0 || !column_exists('users', 'sessions_valid_from')) {
        return;
    }

    try {
        // Stamp with PHP's clock, not MySQL NOW(): session_is_revoked() parses
        // this value with strtotime() in PHP's timezone, and the two clocks can
        // disagree (e.g. XAMPP ships date.timezone=Europe/Berlin while MySQL
        // runs on system time). A NOW() written hours "ahead" of PHP's reading
        // locked every re-login out until the skew elapsed.
        db()->prepare('UPDATE users SET sessions_valid_from = :now WHERE id = :id')
            ->execute(['now' => date('Y-m-d H:i:s'), 'id' => $userId]);
        security_event('sessions_revoked', 'success', 'All active sessions revoked for user #' . $userId, null, $userId);
    } catch (Throwable $exception) {
        // ignore
    }
}

/**
 * True when the *current session* predates the user's session-revocation
 * epoch, i.e. the session must be killed.
 */
function session_is_revoked(array $user): bool
{
    $validFrom = (string) ($user['sessions_valid_from'] ?? '');
    if ($validFrom === '') {
        return false;
    }

    $validTs = strtotime($validFrom);
    if ($validTs === false) {
        return false;
    }

    // A revocation stamp in the future is impossible — it is a leftover from
    // the pre-fix code that wrote MySQL NOW() while this reader parses in
    // PHP's timezone (the clocks can sit hours apart). Left alone it locks the
    // user out until the skew elapses; heal the row and let the session live.
    if ($validTs > time() + 300) {
        try {
            db()->prepare('UPDATE users SET sessions_valid_from = NULL WHERE id = :id')
                ->execute(['id' => (int) ($user['id'] ?? 0)]);
        } catch (Throwable $exception) {
            // ignore — worst case the stale stamp stays and is handled next time
        }

        return false;
    }

    $issuedAt = (int) ($_SESSION['auth_issued_at'] ?? 0);
    if ($issuedAt <= 0) {
        return true;
    }

    return $issuedAt < $validTs;
}

/**
 * Statuses that may hold a live session. 'invited' users must accept and set a
 * password first; 'suspended', 'locked' and 'inactive' are shut out.
 */
function user_status_allows_login(array $user): bool
{
    return (string) ($user['status'] ?? '') === 'active';
}

// ---------------------------------------------------------------------------
// Login throttling
// ---------------------------------------------------------------------------

const LOGIN_MAX_ATTEMPTS = 8;
const LOGIN_LOCKOUT_WINDOW_MINUTES = 15;

/**
 * Counts recent failed logins for an email/IP pair. Used to throttle both the
 * login form and the 4-digit company PIN (10k combinations, previously
 * unlimited tries).
 */
function recent_failed_attempts(string $eventType, ?string $email = null, ?string $ip = null): int
{
    access_control_ensure_schema();
    if (!table_exists('security_events')) {
        return 0;
    }

    $ip = $ip ?? client_ip_address();
    $sql = 'SELECT COUNT(*) FROM security_events
            WHERE event_type = :event_type AND outcome IN (\'failure\', \'denied\')
              AND created_at > (NOW() - INTERVAL :minutes MINUTE)
              AND (ip_address = :ip';
    $params = [
        'event_type' => $eventType,
        'minutes' => LOGIN_LOCKOUT_WINDOW_MINUTES,
        'ip' => $ip,
    ];
    if ($email !== null && $email !== '') {
        $sql .= ' OR email = :email';
        $params['email'] = $email;
    }
    $sql .= ')';

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $exception) {
        return 0;
    }
}

function login_is_throttled(?string $email = null, string $eventType = 'login_failed'): bool
{
    return recent_failed_attempts($eventType, $email) >= LOGIN_MAX_ATTEMPTS;
}

// ---------------------------------------------------------------------------
// Granular per-staff RBAC (migration 034)
//
// The access_level matrix (user_can) is a coarse capability tier. This layer
// lets an admin grant a specific staff member exactly which actions they may
// take in each module. Model:
//   - Admins (and super admins) always pass — they hold full rights within the
//     companies they are authorized for.
//   - A staff user with NO grants keeps full LEGACY access (so nothing breaks
//     on upgrade); once ANY grant exists the user is in STRICT mode and may do
//     only what is ticked.
//   - Customers never pass admin-module checks (they use their own portal).
// ---------------------------------------------------------------------------

/**
 * The module → actions catalogue that the grant matrix and enforcement share.
 * Keep module keys stable; they are stored in staff_permissions.module_key.
 */
function rbac_modules(): array
{
    return [
        'accounting' => ['label' => 'Accounting & Vouchers', 'actions' => ['view', 'create', 'edit', 'approve', 'post', 'export']],
        'sales'      => ['label' => 'Sales & Invoices',      'actions' => ['view', 'create', 'edit', 'export']],
        'purchases'  => ['label' => 'Purchases',             'actions' => ['view', 'create', 'edit', 'export']],
        'receipts'   => ['label' => 'Receipts & Payments',   'actions' => ['view', 'create', 'edit', 'export']],
        'inventory'  => ['label' => 'Inventory',             'actions' => ['view', 'create', 'edit', 'export']],
        'payroll'    => ['label' => 'Payroll',               'actions' => ['view', 'create', 'post', 'export']],
        'reports'    => ['label' => 'Reports',               'actions' => ['view', 'export']],
        'documents'  => ['label' => 'Documents',             'actions' => ['view', 'create', 'edit']],
        'compliance' => ['label' => 'Compliance',            'actions' => ['view', 'edit']],
        'clients'    => ['label' => 'Clients',               'actions' => ['view', 'create', 'edit']],
        'messages'   => ['label' => 'Messages',              'actions' => ['view', 'create']],
        'tickets'    => ['label' => 'Support Tickets',       'actions' => ['view', 'create', 'edit']],
        'hr'         => ['label' => 'HR & Attendance',       'actions' => ['view', 'edit', 'approve']],
    ];
}

function rbac_action_labels(): array
{
    return [
        'view' => 'View', 'create' => 'Create', 'edit' => 'Edit',
        'approve' => 'Approve', 'post' => 'Post', 'export' => 'Export',
    ];
}

/**
 * All granted "module.action" keys for a user (empty array = unconfigured).
 */
function staff_permission_keys(int $userId): array
{
    if ($userId <= 0 || !table_exists('staff_permissions')) {
        return [];
    }

    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $stmt = db()->prepare('SELECT module_key, action_key FROM staff_permissions WHERE user_id = :uid');
    $stmt->execute(['uid' => $userId]);
    $keys = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $keys[$row['module_key'] . '.' . $row['action_key']] = true;
    }

    $cache[$userId] = $keys;

    return $keys;
}

/**
 * True once an admin has assigned at least one explicit permission to the user
 * (i.e. the user is in STRICT mode rather than legacy full-access).
 */
function staff_permissions_configured(int $userId): bool
{
    return staff_permission_keys($userId) !== [];
}

/**
 * The core check: may this user take $action in $module?
 */
function user_can_do(string $module, string $action, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user) {
        return false;
    }

    $role = (string) ($user['role'] ?? '');

    // Admins keep full rights only inside companies already authorized to them.
    if ($role === 'admin') {
        return true;
    }

    // Clients may perform full accounting work only inside their OWN linked
    // books company (not merely any authorized company). This does not grant
    // access to admin, client-management, company-switching, user-management,
    // HR, settings, or system modules. The portal role assigned by the
    // organization's owner decides the voucher lifecycle rights: owners and
    // approvers may approve/post; entry makers may only view, create and edit.
    if ($role === 'customer') {
        if (!client_portal_in_own_books($user)) {
            return false;
        }

        $profile = client_profile_for_user((int) $user['id']);
        $memberRole = (string) ($profile['portal_member_role'] ?? '');
        $canApprove = in_array($memberRole, ['owner', 'approver'], true);

        $clientAccountingPermissions = [
            'accounting' => ['view', 'create', 'edit', 'approve', 'post', 'export'],
            'sales' => ['view', 'create', 'edit', 'export'],
            'purchases' => ['view', 'create', 'edit', 'export'],
            'receipts' => ['view', 'create', 'edit', 'export'],
            'inventory' => ['view', 'create', 'edit', 'post', 'export'],
            'payroll' => ['view', 'create', 'post', 'export'],
            'reports' => ['view', 'export'],
        ];

        if (!$canApprove && in_array($action, ['approve', 'post'], true)) {
            return false;
        }

        return in_array($action, $clientAccountingPermissions[$module] ?? [], true);
    }

    if ($role !== 'staff') {
        return false;
    }

    $userId = (int) $user['id'];

    // Staff permissions remain exactly as configured by the administrator.
    if (!staff_permissions_configured($userId)) {
        return true;
    }

    return isset(staff_permission_keys($userId)[$module . '.' . $action]);
}
/**
 * Page/endpoint gate. Admins pass; a configured staff member without the grant
 * gets a 403 (and the attempt is logged). Because admins always pass, adding
 * this to a shared admin page only ever constrains staff.
 */
function require_permission(string $module, string $action): void
{
    if (!user_can_do($module, $action)) {
        deny_access('Missing permission ' . $module . '.' . $action . '.');
    }
}

/**
 * Replace a staff user's entire grant set (used by the admin matrix editor).
 * $grants is a list of "module.action" strings. Validates against rbac_modules
 * so a tampered POST cannot invent permissions.
 */
function set_staff_permissions(int $userId, array $grants, ?int $grantedBy = null): void
{
    if ($userId <= 0 || !table_exists('staff_permissions')) {
        return;
    }

    $valid = [];
    $modules = rbac_modules();
    foreach ($grants as $grant) {
        $parts = explode('.', (string) $grant, 2);
        if (count($parts) !== 2) {
            continue;
        }
        [$module, $action] = $parts;
        if (isset($modules[$module]) && in_array($action, $modules[$module]['actions'], true)) {
            $valid[$module . '.' . $action] = [$module, $action];
        }
    }

    db()->prepare('DELETE FROM staff_permissions WHERE user_id = :uid')->execute(['uid' => $userId]);
    if ($valid !== []) {
        $ins = db()->prepare('INSERT INTO staff_permissions (user_id, module_key, action_key, granted_by) VALUES (:uid, :m, :a, :by)');
        foreach ($valid as [$module, $action]) {
            $ins->execute(['uid' => $userId, 'm' => $module, 'a' => $action, 'by' => $grantedBy]);
        }
    }
}
