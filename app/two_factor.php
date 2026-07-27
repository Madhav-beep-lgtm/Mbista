<?php
declare(strict_types=1);

/**
 * Two-factor authentication (TOTP, RFC 6238).
 *
 * Written against the spec rather than pulled from a package: the whole
 * algorithm is about sixty lines, and a dependency that must be trusted with
 * the second factor is a poor trade for that.
 *
 * The shape of it:
 *   - A shared secret is generated once per user and shown as a Base32 key and
 *     an otpauth:// URI, which is what Google Authenticator, Authy, Aegis and
 *     1Password all read.
 *   - Every 30 seconds both sides derive the same 6-digit code from that secret
 *     and the clock. The server accepts the code for the step before and after
 *     the current one, because phone clocks drift.
 *   - A secret is PENDING until the user proves they can produce a code from
 *     it. Enrolling without that check is how people lock themselves out of
 *     their own account with a mistyped key.
 *   - An accepted step is remembered, so a code that is seen once cannot be
 *     replayed inside its own 30-second window by somebody reading the wire.
 *   - Recovery codes exist because phones are lost. They are stored hashed, the
 *     same as passwords, and each one works once.
 */

const TWO_FACTOR_DIGITS = 6;
const TWO_FACTOR_PERIOD = 30;
const TWO_FACTOR_WINDOW = 1;          // steps either side of now
const TWO_FACTOR_RECOVERY_COUNT = 10;

/** Create the table on first use, the way the rest of the app self-repairs. */
function two_factor_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS `user_two_factor` (
        `user_id` INT UNSIGNED NOT NULL,
        `secret` VARCHAR(64) NOT NULL,
        `confirmed_at` DATETIME DEFAULT NULL,
        `last_step` BIGINT DEFAULT NULL,
        `recovery_codes` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// ---------------------------------------------------------------------------
// Base32, because that is the alphabet every authenticator app reads
// ---------------------------------------------------------------------------

function two_factor_base32_encode(string $binary): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($binary) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }

    return $out;
}

function two_factor_base32_decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
    $bits = '';
    foreach (str_split($secret) as $char) {
        $index = strpos($alphabet, $char);
        if ($index === false) {
            continue;
        }
        $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $out .= chr(bindec($chunk));
        }
    }

    return $out;
}

/** A fresh 160-bit secret, the size RFC 4226 recommends for HMAC-SHA1. */
function two_factor_generate_secret(): string
{
    return two_factor_base32_encode(random_bytes(20));
}

// ---------------------------------------------------------------------------
// The code itself
// ---------------------------------------------------------------------------

/** The 6-digit code for one 30-second step. */
function two_factor_code_at(string $secret, int $step): string
{
    $key = two_factor_base32_decode($secret);
    if ($key === '') {
        return '';
    }
    // The counter is a 64-bit big-endian integer. pack('J') needs PHP 5.6+ and
    // is exactly that, so no manual byte assembly is needed.
    $hash = hash_hmac('sha1', pack('J', $step), $key, true);
    // Dynamic truncation, RFC 4226 section 5.3: the low nibble of the last byte
    // says where to read the 4-byte window from.
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);

    return str_pad((string) ($value % (10 ** TWO_FACTOR_DIGITS)), TWO_FACTOR_DIGITS, '0', STR_PAD_LEFT);
}

/** Which step the given moment falls in. */
function two_factor_current_step(?int $now = null): int
{
    return intdiv($now ?? time(), TWO_FACTOR_PERIOD);
}

/**
 * The step a code belongs to, or null when it is not a code for this secret.
 *
 * Returns the STEP rather than true/false so the caller can refuse a step it
 * has already accepted — without that, a code is reusable for its whole
 * thirty seconds by anyone who reads it over the shoulder or off the wire.
 */
function two_factor_match_step(string $secret, string $code, ?int $now = null): ?int
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== TWO_FACTOR_DIGITS) {
        return null;
    }
    $current = two_factor_current_step($now);
    for ($drift = -TWO_FACTOR_WINDOW; $drift <= TWO_FACTOR_WINDOW; $drift++) {
        $step = $current + $drift;
        // hash_equals, so the comparison takes the same time whatever the code
        // is and cannot be narrowed digit by digit.
        if (hash_equals(two_factor_code_at($secret, $step), $code)) {
            return $step;
        }
    }

    return null;
}

/** The URI an authenticator app scans or accepts pasted. */
function two_factor_provisioning_uri(string $secret, string $email, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email)
        . '?secret=' . $secret
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1'
        . '&digits=' . TWO_FACTOR_DIGITS
        . '&period=' . TWO_FACTOR_PERIOD;
}

// ---------------------------------------------------------------------------
// Per-user state
// ---------------------------------------------------------------------------

function two_factor_row(int $userId): ?array
{
    two_factor_ensure_schema();
    $stmt = db()->prepare('SELECT * FROM user_two_factor WHERE user_id = :uid LIMIT 1');
    $stmt->execute(['uid' => $userId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** True once the user has proved they can produce a code from their secret. */
function two_factor_is_active(int $userId): bool
{
    $row = two_factor_row($userId);

    return $row !== null && $row['confirmed_at'] !== null;
}

/**
 * Start enrolment: a new secret, not yet in force.
 *
 * Re-enrolling replaces any pending secret, which is what makes "start again"
 * work when somebody abandons the setup half way.
 */
function two_factor_begin_enrolment(int $userId): string
{
    two_factor_ensure_schema();
    $secret = two_factor_generate_secret();
    db()->prepare('INSERT INTO user_two_factor (user_id, secret, confirmed_at, last_step, recovery_codes)
            VALUES (:uid, :secret, NULL, NULL, NULL)
        ON DUPLICATE KEY UPDATE secret = VALUES(secret), confirmed_at = NULL, last_step = NULL, recovery_codes = NULL')
        ->execute(['uid' => $userId, 'secret' => $secret]);

    return $secret;
}

/**
 * Finish enrolment by proving the app and the server agree.
 *
 * Returns the recovery codes in PLAIN TEXT, once. They are stored hashed, so
 * this is the only moment they can be shown; if the user does not write them
 * down now, they never can.
 */
function two_factor_confirm_enrolment(int $userId, string $code): array
{
    $row = two_factor_row($userId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Start the setup again — there is no pending key for this account.'];
    }
    if ($row['confirmed_at'] !== null) {
        return ['ok' => false, 'error' => 'Two-factor authentication is already switched on for this account.'];
    }
    $step = two_factor_match_step((string) $row['secret'], $code);
    if ($step === null) {
        return ['ok' => false, 'error' => 'That code is not right. Check your phone\'s clock and try the current code.'];
    }

    $plain = [];
    $hashed = [];
    for ($i = 0; $i < TWO_FACTOR_RECOVERY_COUNT; $i++) {
        // Unambiguous alphabet: no O/0 or I/1 to mistype off a piece of paper.
        $raw = strtoupper(substr(str_replace(['O', 'I', 'L', '0', '1'], '', two_factor_base32_encode(random_bytes(10))), 0, 10));
        $raw = str_pad($raw, 10, (string) random_int(2, 9));
        $plain[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        $hashed[] = password_hash(end($plain), PASSWORD_DEFAULT);
    }

    db()->prepare('UPDATE user_two_factor SET confirmed_at = NOW(), last_step = :step, recovery_codes = :codes
        WHERE user_id = :uid')
        ->execute(['step' => $step, 'codes' => json_encode($hashed), 'uid' => $userId]);

    if (function_exists('security_event')) {
        security_event('two_factor_enabled', 'success', 'Two-factor authentication switched on.', null, $userId);
    }

    return ['ok' => true, 'error' => '', 'recovery_codes' => $plain];
}

/**
 * Check a code at sign-in. Accepts either a 6-digit app code or one recovery
 * code, and burns whichever it used.
 */
function two_factor_verify(int $userId, string $code, ?int $now = null): array
{
    $row = two_factor_row($userId);
    if (!$row || $row['confirmed_at'] === null) {
        return ['ok' => false, 'error' => 'Two-factor authentication is not switched on for this account.'];
    }

    $step = two_factor_match_step((string) $row['secret'], $code, $now);
    if ($step !== null) {
        // A step is accepted once. Without this the same code works for the
        // rest of its thirty seconds for anybody who saw it.
        if ($row['last_step'] !== null && $step <= (int) $row['last_step']) {
            return ['ok' => false, 'error' => 'That code has already been used. Wait for the next one.'];
        }
        db()->prepare('UPDATE user_two_factor SET last_step = :step WHERE user_id = :uid')
            ->execute(['step' => $step, 'uid' => $userId]);

        return ['ok' => true, 'error' => '', 'used_recovery' => false];
    }

    // Not an app code — try the recovery codes.
    $stored = json_decode((string) ($row['recovery_codes'] ?? '[]'), true);
    if (is_array($stored)) {
        $candidate = strtoupper(trim($code));
        foreach ($stored as $index => $hash) {
            if (is_string($hash) && password_verify($candidate, $hash)) {
                unset($stored[$index]);
                db()->prepare('UPDATE user_two_factor SET recovery_codes = :codes WHERE user_id = :uid')
                    ->execute(['codes' => json_encode(array_values($stored)), 'uid' => $userId]);
                if (function_exists('security_event')) {
                    security_event('two_factor_recovery_used', 'success',
                        'Signed in with a recovery code; ' . count($stored) . ' left.', null, $userId);
                }

                return ['ok' => true, 'error' => '', 'used_recovery' => true, 'remaining' => count($stored)];
            }
        }
    }

    return ['ok' => false, 'error' => 'That code is not right.'];
}

/** Switch it off. Requires the caller to have already checked authority. */
function two_factor_disable(int $userId): void
{
    two_factor_ensure_schema();
    db()->prepare('DELETE FROM user_two_factor WHERE user_id = :uid')->execute(['uid' => $userId]);
    if (function_exists('security_event')) {
        security_event('two_factor_disabled', 'warning', 'Two-factor authentication switched off.', null, $userId);
    }
}

/** How many recovery codes the user has left. */
function two_factor_recovery_remaining(int $userId): int
{
    $row = two_factor_row($userId);
    $stored = json_decode((string) ($row['recovery_codes'] ?? '[]'), true);

    return is_array($stored) ? count($stored) : 0;
}

// ---------------------------------------------------------------------------
// The sign-in gate
// ---------------------------------------------------------------------------

/**
 * Hold a freshly authenticated user at the door until they produce a code.
 *
 * The session id is NOT the place to keep "half signed in": current_user()
 * reads $_SESSION['user_id'], so leaving it set would mean the password alone
 * had already granted access and the second factor was decoration. The id is
 * moved to a pending slot instead, and only put back once the code checks out.
 */
function two_factor_hold_pending(int $userId): void
{
    unset($_SESSION['user_id']);
    $_SESSION['2fa_pending_user_id'] = $userId;
    $_SESSION['2fa_pending_since'] = time();
}

function two_factor_pending_user_id(): int
{
    $userId = (int) ($_SESSION['2fa_pending_user_id'] ?? 0);
    if ($userId <= 0) {
        return 0;
    }
    // A challenge left open all day is an unattended half-login. Ten minutes is
    // long enough to fetch a phone.
    if (time() - (int) ($_SESSION['2fa_pending_since'] ?? 0) > 600) {
        two_factor_clear_pending();

        return 0;
    }

    return $userId;
}

function two_factor_clear_pending(): void
{
    unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_since']);
}

/** Let the held user through. */
function two_factor_complete_pending(int $userId): void
{
    two_factor_clear_pending();
    // Rotate the id now that the identity is fully established — the same
    // session-fixation guard login_user() applies. headers_sent() is checked
    // because a CLI harness has already written output and cannot send a new
    // cookie; there is no session to fixate there either.
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = $userId;
    $_SESSION['auth_issued_at'] = time();
}

/**
 * Whether this account must use a second factor.
 *
 * A user who has switched it on always must. Beyond that, the shop can require
 * it of everyone who can reach the admin side, through the existing
 * security_2fa_required setting — customers use their own portal and are left
 * out of it.
 */
function two_factor_required_for(array $user): bool
{
    if (two_factor_is_active((int) $user['id'])) {
        return true;
    }
    if ((string) setting('security_2fa_required', '0') !== '1') {
        return false;
    }

    return in_array((string) ($user['role'] ?? ''), ['admin', 'super_admin', 'staff'], true);
}
