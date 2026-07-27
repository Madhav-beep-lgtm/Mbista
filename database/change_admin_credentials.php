<?php
declare(strict_types=1);

/**
 * Change an admin account's email address and/or password.
 *
 *   php database/change_admin_credentials.php                 list the admins
 *   php database/change_admin_credentials.php old@example.com change that one
 *
 * WHY THIS IS A SCRIPT AND NOT A ONE-LINE UPDATE
 *
 * A password typed on the command line goes into the shell's history file and
 * is visible in `ps` to every other account on the server for as long as the
 * command runs. This asks for it on stdin instead, with terminal echo off
 * where the platform allows it, and never prints it back.
 *
 * It also refuses to write anything until the whole change is valid, so a
 * failed run cannot leave an account with a new email and its old password —
 * or worse, a password nobody knows.
 *
 * WHICH DATABASE IT TOUCHES
 *
 * Whichever one .env points at. Run it ON the production server to change a
 * production password; running it on a laptop changes the laptop's copy and
 * nothing else.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is for the command line only.\n");
}

require __DIR__ . '/../app/bootstrap.php';

/** Read a line from stdin, hiding it if the terminal supports that. */
function prompt(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);
    if ($hidden && DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec')) {
        // POSIX: turn echo off around the read so the password never appears.
        @shell_exec('stty -echo 2>/dev/null');
        $value = (string) fgets(STDIN);
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    } else {
        if ($hidden) {
            fwrite(STDOUT, "\n  (this terminal cannot hide what you type — make sure nobody is looking)\n  > ");
        }
        $value = (string) fgets(STDIN);
    }

    return trim($value);
}

function password_policy_error(string $password): ?string
{
    if (strlen($password) < 12) {
        return 'Use at least 12 characters. This is an administrator account.';
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)
        || !preg_match('/[0-9]/', $password)) {
        return 'Include an uppercase letter, a lowercase letter and a number.';
    }

    return null;
}

// ---------------------------------------------------------------------------
// Which account?
// ---------------------------------------------------------------------------
$admins = db()->query("SELECT id, name, email, role, status FROM users
    WHERE role IN ('admin', 'super_admin') ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($admins === []) {
    exit("There are no admin accounts in this database.\n");
}

$targetEmail = trim((string) ($argv[1] ?? ''));
if ($targetEmail === '') {
    fwrite(STDOUT, "Admin accounts in " . DB_NAME . ":\n\n");
    foreach ($admins as $row) {
        fwrite(STDOUT, sprintf("  %-4s %-34s %-12s %s\n",
            $row['id'], $row['email'], $row['role'], $row['status']));
    }
    fwrite(STDOUT, "\nRun again with the email of the one to change:\n");
    fwrite(STDOUT, "  php database/change_admin_credentials.php " . $admins[0]['email'] . "\n");
    exit(0);
}

$user = null;
foreach ($admins as $row) {
    if (strcasecmp((string) $row['email'], $targetEmail) === 0) {
        $user = $row;
        break;
    }
}
if (!$user) {
    exit("No admin account with the email '$targetEmail' in " . DB_NAME . ".\n");
}

fwrite(STDOUT, "\nChanging: {$user['name']} <{$user['email']}>  (id {$user['id']}, {$user['role']})\n");
fwrite(STDOUT, "Database: " . DB_NAME . " on " . DB_HOST . "\n");
fwrite(STDOUT, "Leave a field empty to keep it as it is.\n\n");

// ---------------------------------------------------------------------------
// New email
// ---------------------------------------------------------------------------
$newEmail = prompt('New email address: ');
if ($newEmail !== '') {
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        exit("\nThat is not a valid email address. Nothing was changed.\n");
    }
    $clash = db()->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
    $clash->execute(['email' => $newEmail, 'id' => (int) $user['id']]);
    if ($clash->fetchColumn() !== false) {
        exit("\nAnother account already uses that email. Nothing was changed.\n");
    }
}

// ---------------------------------------------------------------------------
// New password
// ---------------------------------------------------------------------------
$newPassword = prompt('New password: ', true);
if ($newPassword !== '') {
    if (($policyError = password_policy_error($newPassword)) !== null) {
        exit("\n$policyError\nNothing was changed.\n");
    }
    $again = prompt('Repeat the password: ', true);
    if (!hash_equals($newPassword, $again)) {
        exit("\nThe two passwords do not match. Nothing was changed.\n");
    }
}

if ($newEmail === '' && $newPassword === '') {
    exit("\nNothing to change.\n");
}

// ---------------------------------------------------------------------------
// Write it, both halves together or neither
// ---------------------------------------------------------------------------
$fields = [];
$params = ['id' => (int) $user['id']];
if ($newEmail !== '') {
    $fields[] = 'email = :email';
    $params['email'] = $newEmail;
}
if ($newPassword !== '') {
    $fields[] = 'password_hash = :hash';
    $params['hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    if (column_exists('users', 'must_change_password')) {
        // The person typing this IS the account holder, so they are not being
        // handed a temporary password to change at first sign-in.
        $fields[] = 'must_change_password = 0';
    }
}

db()->beginTransaction();
try {
    db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);

    if (function_exists('security_event')) {
        security_event('admin_credentials_changed', 'success',
            'Changed from the command line: '
            . ($newEmail !== '' ? 'email' : '')
            . ($newEmail !== '' && $newPassword !== '' ? ' and ' : '')
            . ($newPassword !== '' ? 'password' : '') . '.',
            (int) ($user['company_id'] ?? 0) ?: null, (int) $user['id'],
            $newEmail !== '' ? $newEmail : (string) $user['email']);
    }
    if (function_exists('log_activity')) {
        log_activity('user', (int) $user['id'], 'credentials_changed',
            'Admin credentials changed from the command line.', (int) $user['id']);
    }

    db()->commit();
} catch (Throwable $exception) {
    db()->rollBack();
    exit("\nFailed, nothing was changed: " . $exception->getMessage() . "\n");
}

fwrite(STDOUT, "\nDone.\n");
if ($newEmail !== '') {
    fwrite(STDOUT, "  Email is now: $newEmail\n");
}
if ($newPassword !== '') {
    fwrite(STDOUT, "  Password changed. It is not shown here and is not recoverable — store it in a password manager.\n");
}
if ($newPassword !== '' && function_exists('two_factor_is_active')) {
    require_once __DIR__ . '/../app/two_factor.php';
    if (two_factor_is_active((int) $user['id'])) {
        fwrite(STDOUT, "  Two-factor authentication stays ON for this account.\n");
    }
}
fwrite(STDOUT, "\nAnyone signed in as this account keeps their session until it expires.\n");
exit(0);
