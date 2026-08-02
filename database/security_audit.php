<?php
declare(strict_types=1);

/**
 * What, on THIS server, is still a way in.
 *
 *   php database/security_audit.php            report only, changes nothing
 *   php database/security_audit.php --fix      disable what it finds, with a prompt
 *
 * WHY THIS EXISTS
 *
 * The shipped schema used to seed real password hashes, and the repository they
 * ship in is public. Removing them from the files fixes new installs and does
 * nothing whatever for the databases already running — the hash for
 * admin@mbista.local is still in production exactly as it was, and it is still
 * the one anybody could have taken away and attacked offline.
 *
 * Test accounts are the same story from a different direction. Suites and
 * probes create admins; cleanup usually removes them; sometimes a run is
 * interrupted and one is left behind with a known password and full access.
 *
 * So this reports, against the live database:
 *
 *   - admin accounts whose password still matches one that was ever published
 *   - accounts that look like test or probe leftovers
 *   - accounts still carrying the locked placeholder, i.e. never set up
 *   - whether the .env has the keys that backups and password resets need
 *
 * WHICH DATABASE IT READS
 *
 * Whichever one .env points at. Run it ON the production server to learn about
 * production; on a laptop it tells you about the laptop.
 *
 * WHAT --fix DOES, AND DOES NOT
 *
 * It sets status to 'inactive'. It never deletes: an account can be attached to
 * vouchers, and a shop's audit trail should not develop holes because of a
 * security clean-up. It never touches the account you name as the one to keep,
 * so it cannot lock you out of your own system.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is for the command line only.\n");
}

require __DIR__ . '/../app/bootstrap.php';

$applyFixes = in_array('--fix', array_slice($argv, 1), true);

/**
 * Passwords that were, at some point, readable by anyone who could read the
 * repository — plus the handful any installer is likely to have been given.
 * The list is short on purpose: this is a check for known-published values, not
 * a password cracker.
 */
function audit_known_passwords(): array
{
    return [
        'admin', 'admin123', 'Admin@123', 'password', 'Password1', 'Password@123',
        'changeme', 'ChangeMe123', 'mbista', 'Mbista@123', 'secret', 'Secret#12345',
        'test', 'Test@1234', '12345678', 'letmein',
    ];
}

function audit_looks_like_a_test_account(string $email, string $name): bool
{
    $haystack = strtolower($email . ' ' . $name);
    foreach (['@test.local', '@example.com', '@example.test', '@example.local',
              'smoketest', 'probe', 'sample.', 'testcustomer', 'dummy', 'fixture'] as $marker) {
        if (str_contains($haystack, $marker)) {
            return true;
        }
    }

    return false;
}

$findings = [];
$note = static function (string $severity, string $what, string $detail) use (&$findings): void {
    $findings[] = ['severity' => $severity, 'what' => $what, 'detail' => $detail];
};

// ---------------------------------------------------------------------------
// Accounts
// ---------------------------------------------------------------------------
echo "Accounts\n";
echo str_repeat('-', 72) . "\n";

$users = db()->query('SELECT id, name, email, role, status, password_hash FROM users ORDER BY id')
    ->fetchAll(PDO::FETCH_ASSOC);
$weak = [];
$leftovers = [];
$unset = [];

foreach ($users as $user) {
    $email = (string) $user['email'];
    $name = (string) $user['name'];
    $role = (string) $user['role'];
    $status = (string) $user['status'];
    $hash = (string) $user['password_hash'];
    $flags = [];

    if (str_starts_with($hash, '*LOCKED')) {
        $flags[] = 'never set up';
        $unset[] = $user;
    } else {
        foreach (audit_known_passwords() as $candidate) {
            if (password_verify($candidate, $hash)) {
                // The password itself is NOT printed. Knowing which account is
                // affected is what you act on; printing it just moves the leak
                // into a terminal scrollback and a support email.
                $flags[] = 'PASSWORD IS A PUBLISHED ONE';
                $weak[] = $user;
                break;
            }
        }
    }

    if (audit_looks_like_a_test_account($email, $name)) {
        $flags[] = 'looks like a test account';
        $leftovers[] = $user;
    }

    if ($flags !== []) {
        printf("  #%-4d %-38s %-9s %-9s %s\n", (int) $user['id'], $email, $role, $status,
            implode(' + ', $flags));
    }
}

$activeWeak = array_filter($weak, static fn (array $u): bool => (string) $u['status'] === 'active');
$activeLeftovers = array_filter($leftovers, static fn (array $u): bool => (string) $u['status'] === 'active');

if ($weak === [] && $leftovers === [] && $unset === []) {
    echo "  Nothing flagged.\n";
}

if ($activeWeak !== []) {
    $note('CRITICAL', count($activeWeak) . ' active account(s) use a password that was published',
        'Change them now: php database/change_admin_credentials.php');
}
if ($activeLeftovers !== []) {
    $note('HIGH', count($activeLeftovers) . ' active test-looking account(s)',
        'Disable them: php database/security_audit.php --fix');
}
if ($unset !== []) {
    $note('INFO', count($unset) . ' account(s) have never had a password set',
        'They cannot be logged into. Set one, or leave them locked.');
}

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
echo "\nConfiguration\n";
echo str_repeat('-', 72) . "\n";

$envKeys = [
    'MAIL_HOST' => ['HIGH', 'Password-reset emails cannot leave the server without it'],
    'BACKUP_PASSPHRASE' => ['HIGH', 'Backups are written unencrypted'],
    'BACKUP_REMOTE' => ['HIGH', 'Backups have no off-site copy, so losing the server loses them too'],
    'APP_URL' => ['MEDIUM', 'Reset links are built from it and will point at the wrong host'],
    'APP_TIMEZONE' => ['LOW', 'Falls back to the code default'],
];
$envPath = dirname(__DIR__) . '/.env';
$envBody = is_file($envPath) ? (string) file_get_contents($envPath) : '';
foreach ($envKeys as $key => [$severity, $why]) {
    $set = (bool) preg_match('~^' . preg_quote($key, '~') . '=\s*\S+~m', $envBody);
    printf("  %-20s %s\n", $key, $set ? 'set' : 'NOT SET — ' . $why);
    if (!$set) {
        $note($severity, $key . ' is not set in .env', $why);
    }
}

$appUrl = (string) (function_exists('env') ? env('APP_URL', '') : '');
// One test for "is this a developer's machine", used by both checks below —
// otherwise a laptop configured with 127.0.0.1 gets told it is a live host that
// has forgotten to set APP_ENV, which is noise on the one machine where none of
// this matters.
$looksLocal = $appUrl === '' || (bool) preg_match('~(localhost|127\.0\.0\.1|\.local\b|\.test\b|:\d{4,5})~', $appUrl);
if (!$looksLocal) {
    if (function_exists('env') && (string) env('APP_ENV', '') !== 'production') {
        $note('MEDIUM', 'APP_ENV is not "production" on what looks like a live host',
            'Debug output and error detail may be shown to visitors.');
    }
} else {
    $note('INFO', 'APP_URL is a development address (' . ($appUrl ?: 'unset') . ')',
        'Fine on a laptop. On the live server it must be the real address, or password-reset '
        . 'links will send people to their own machine.');
}

// ---------------------------------------------------------------------------
// Backups
// ---------------------------------------------------------------------------
//
// A backup nobody checks is a belief, not a backup. The nightly job writes how
// it went to backup-status.json; this reads it, so "the backups stopped six
// weeks ago" is something you find out now rather than during a restore.
echo "\nBackups\n";
echo str_repeat('-', 72) . "\n";

// Resolved by the SAME candidate walk the backup script uses (env BACKUP_DIR,
// then $HOME/db-backups, then storage/backups). Looking in only one place
// while the script wrote to another made a working backup report as "never
// ran" — the false alarm that teaches people to ignore alarms.
$read = backup_status_read();
$statusPath = $read['path'];
$status = $read['status'];

if (!is_array($status)) {
    printf("  %-20s %s\n", 'last run', 'NO RECORD at ' . $statusPath);
    $note('HIGH', 'No backup has ever reported in',
        'Either the nightly cron was never set up, or it has never got far enough to say so. '
        . 'Set it in cPanel → Cron Jobs, per docs/SECURITY_RUNBOOK.md.');
} else {
    $ranAt = (string) ($status['at'] ?? '');
    $state = (string) ($status['state'] ?? 'unknown');
    $ageHours = $ranAt !== '' ? (time() - strtotime($ranAt)) / 3600 : PHP_INT_MAX;
    printf("  %-20s %s (%s)\n", 'last run', $ranAt !== '' ? $ranAt : 'unknown', $state);
    printf("  %-20s %s\n", 'detail', (string) ($status['detail'] ?? ''));

    $warning = trim((string) ($status['warning'] ?? ''));
    if ($warning !== '') {
        printf("  %-20s %s\n", 'warning', $warning);
    }

    if ($state !== 'ok') {
        $note('CRITICAL', 'The last backup FAILED (' . $ranAt . ')',
            (string) ($status['detail'] ?? '') . ' — see backup-database.log on the server.');
    } elseif ($warning !== '') {
        // Succeeded, but on the fallback path — the rows are dumped and the
        // rest of the schema is not. It is a backup, so it is not CRITICAL;
        // it is incomplete, so it does not get to pass silently either.
        $note('HIGH', 'The last backup succeeded only partly', $warning);
    } elseif ($ageHours > 48) {
        $note('CRITICAL', 'The last good backup is ' . (int) round($ageHours / 24) . ' days old',
            'A nightly job that has not run for days has stopped. Check the cron entry.');
    } elseif ($ageHours > 26) {
        $note('HIGH', 'No backup in the last ' . (int) round($ageHours) . ' hours',
            'The nightly job may have been skipped.');
    }
}

// Both halves have to be there. A dump restores rows; the files those rows name
// — KYC scans, signed agreements, message attachments — live on disk.
$dumps = glob(rtrim($backupDir, '/\\') . '/*.sql.gz*') ?: [];
$fileSets = glob(rtrim($backupDir, '/\\') . '/*_files_*.tar.gz*') ?: [];
printf("  %-20s %d\n", 'database dumps', count($dumps));
printf("  %-20s %d\n", 'file archives', count($fileSets));
if ($dumps !== [] && $fileSets === []) {
    $note('HIGH', 'There are database dumps but no file archives',
        'Restoring would give you every row with its documents missing. Update deploy/backup-database.sh on the server.');
}
$failed = glob(rtrim($backupDir, '/\\') . '/*.FAILED') ?: [];
if ($failed !== []) {
    $note('HIGH', count($failed) . ' failed backup artefact(s) left on disk',
        'Each one is a night with no backup. They are kept deliberately, as evidence — look at them, then delete.');
}

// ---------------------------------------------------------------------------
// What to do about it
// ---------------------------------------------------------------------------
echo "\nFindings\n";
echo str_repeat('=', 72) . "\n";
if ($findings === []) {
    echo "  Nothing to act on.\n";
} else {
    $order = ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3, 'INFO' => 4];
    usort($findings, static fn (array $a, array $b): int => ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9));
    foreach ($findings as $finding) {
        printf("  %-9s %s\n            %s\n", $finding['severity'], $finding['what'], $finding['detail']);
    }
}

if (!$applyFixes) {
    echo "\n  Report only. Re-run with --fix to disable the test-looking accounts.\n";
    exit($findings === [] ? 0 : 1);
}

// ---------------------------------------------------------------------------
// --fix
// ---------------------------------------------------------------------------
if ($activeLeftovers === []) {
    echo "\n  Nothing to disable.\n";
    exit(0);
}

echo "\nThese would be set to inactive (never deleted — they may be on vouchers):\n";
foreach ($activeLeftovers as $user) {
    printf("  #%-4d %-38s %s\n", (int) $user['id'], (string) $user['email'], (string) $user['role']);
}
fwrite(STDOUT, "\nType YES to disable them: ");
$answer = trim((string) fgets(STDIN));
if ($answer !== 'YES') {
    echo "  Nothing changed.\n";
    exit(0);
}

$stmt = db()->prepare("UPDATE users SET status = 'inactive' WHERE id = :id");
$disabled = 0;
foreach ($activeLeftovers as $user) {
    $stmt->execute(['id' => (int) $user['id']]);
    $disabled++;
}
echo "  Disabled $disabled account(s). Nothing was deleted.\n";
echo "  Any account still listed as using a published password must be changed with\n";
echo "  php database/change_admin_credentials.php — this script will not do it for you,\n";
echo "  because a password chosen by a script is one nobody can be told securely.\n";
exit(0);
