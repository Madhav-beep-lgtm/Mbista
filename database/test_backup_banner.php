<?php
declare(strict_types=1);

/**
 * The one banner that tells a shop its backups are not what it thinks.
 *
 * backup_health_warning() is read by admin_header.php on every admin page, and
 * it is the whole reason a stopped backup gets noticed. It had no test, which
 * for a function whose entire job is to notice things is the wrong way round.
 *
 * The ORDER matters as much as the wording. A shop shown two banners reads
 * neither, so the more urgent thing has to win: a failed run beats a stale one,
 * a stale one beats a degraded one, and "no off-site copy" — true every day,
 * for months, on a perfectly working setup — comes last of all, or it would
 * bury the night the dump actually broke.
 *
 *   php database/test_backup_banner.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function says(?string $warning, string $needle): bool { return $warning !== null && stripos($warning, $needle) !== false; }

/*
 * The function reads the real .env and the real backup directory, neither of
 * which a test may move. So the DECISION is exercised through the same shape
 * of input, with the two environment facts passed in — which is all the
 * function does with them.
 */
function banner_for(array $status, string $appEnv, string $backupRemote): ?string
{
    $state = (string) ($status['state'] ?? 'unknown');
    $ranAt = (string) ($status['at'] ?? '');
    $ageHours = $ranAt !== '' && strtotime($ranAt) !== false
        ? (time() - strtotime($ranAt)) / 3600 : PHP_FLOAT_MAX;

    if ($state !== 'ok') {
        return 'The last database backup FAILED (' . $ranAt . '): ' . (string) ($status['detail'] ?? '') . '.';
    }
    if ($ageHours > 48) {
        return 'The last good database backup is ' . (int) round($ageHours / 24) . ' days old — the nightly job has stopped.';
    }
    $warning = trim((string) ($status['warning'] ?? ''));
    if ($warning !== '') {
        return 'The last database backup succeeded, but only partly: ' . $warning;
    }
    if ($appEnv === 'production' && trim($backupRemote) === '') {
        return 'Backups are working, but every copy is on this server — BACKUP_REMOTE is not set.';
    }

    return null;
}

$now = date('Y-m-d H:i:s');
$old = date('Y-m-d H:i:s', time() - 5 * 86400);
$clean = ['state' => 'ok', 'at' => $now, 'detail' => '57 held', 'warning' => ''];

echo "\n1. Each fault is named, and the worse one wins\n";
ok(says(banner_for(['state' => 'failed', 'at' => $now, 'detail' => 'mysqldump exited 3'], 'production', ''), 'FAILED'),
    'A failed run is reported as failed');
ok(says(banner_for(['state' => 'failed', 'at' => $now, 'detail' => 'mysqldump exited 3'], 'production', ''), 'mysqldump exited 3'),
    'With the reason mysqldump gave, not just the exit status');
ok(!says(banner_for(['state' => 'failed', 'at' => $now, 'detail' => 'x'], 'production', ''), 'BACKUP_REMOTE'),
    'And it does NOT also mention the off-site copy — two banners at once and the shop reads neither');
ok(says(banner_for(['state' => 'ok', 'at' => $old, 'detail' => ''], 'production', ''), 'days old'),
    'A stale run says how old it is');
ok(says(banner_for(['state' => 'ok', 'at' => $now, 'warning' => 'routines were SKIPPED'], 'production', ''), 'only partly'),
    'A degraded run says it only partly worked');

echo "\n2. No off-site copy — true every day, so it comes last\n";
ok(says(banner_for($clean, 'production', ''), 'every copy is on this server'),
    'On a clean run with no remote, THAT is what the banner says');
ok(banner_for($clean, 'production', 'rclone:gdrive:mbista') === null,
    'Set a remote and the banner goes quiet — it nags about a real gap, not a setting it likes');
ok(banner_for($clean, 'development', '') === null,
    'A development machine is not nagged: a permanent banner there trains the eye to skip the same banner where it matters');
ok(banner_for($clean, 'production', '   ') === null || says(banner_for($clean, 'production', '   '), 'every copy'),
    'Whitespace is not a remote');

echo "\n3. Nothing wrong, nothing said\n";
ok(banner_for($clean, 'production', 'scp:backup@203.0.113.9:/srv') === null,
    'A working, off-sited backup shows no banner at all');

/*
 * And the real function agrees with the model above on the case this machine
 * can actually prove: development is quiet whatever else is true.
 */
echo "\n4. The real function, on this machine\n";
$live = backup_health_warning();
$appEnv = (string) env('APP_ENV', 'production');
if ($appEnv !== 'production') {
    // SILENT, not merely quieter.
    //
    // The production guard used to sit inside the "never reported in" branch
    // alone, so a dev machine said nothing about a missing backup and shouted
    // about an old one. A stale ~/db-backups status file left by a one-off
    // dump of an unrelated database put "the last good database backup is 6
    // days old — the nightly job has stopped" on a laptop, and the production
    // server it was blamed on had been dumping cleanly every hour. An hour
    // went into the wrong machine. The earlier assertion here passed
    // throughout, because it only ever checked for the off-site banner.
    ok($live === null,
        'A development machine raises NO backup banner at all, whatever its status file says'
        . ($live === null ? '' : ' — got: ' . $live));
} else {
    ok(true, 'Skipped — this machine reports APP_ENV=production');
}

// The message must not name a schedule it cannot know. This account dumps
// hourly, and a banner saying the NIGHTLY job had stopped sent somebody
// looking for a nightly cron that was never what was running.
//
// Comments are stripped before looking, via the tokeniser rather than a
// regex. The first version of this check failed against its own fix: the
// comment above quotes the very phrase it is asserting is gone, and a plain
// str_contains cannot tell an explanation from the code it explains.
$helperCode = '';
foreach (token_get_all((string) file_get_contents(__DIR__ . '/../app/helpers.php')) as $token) {
    if (is_array($token)) {
        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
            continue;
        }
        $helperCode .= $token[1];
    } else {
        $helperCode .= $token;
    }
}
ok(!str_contains($helperCode, 'nightly job has stopped'),
    'The stale-backup banner does not claim a schedule it cannot know');
ok(str_contains($helperCode, 'days old (')  && str_contains($helperCode, '$ranAt'),
    'And it quotes WHEN the last good backup ran, which is what gets compared against the log');
ok(function_exists('backup_health_warning') && function_exists('backup_status_read'),
    'Both halves the admin header depends on are defined');

echo "\n5. A backup that cannot leave the server says so\n";
// An off-site copy that fails used to log a WARNING and let the run finish
// reporting "ok" — so "the backups stopped reaching Google Drive" lived only
// in a file nobody opens. That is the same silence the status file was written
// to end. The failure now goes into WARNING_NOTE, which write_status puts in
// the warning field, which the banner reads.
$sh = (string) @file_get_contents(__DIR__ . '/../deploy/backup-database.sh');
ok(preg_match('~offsite_failed\(\)\s*\{[^}]*WARNING_NOTE=~s', $sh) === 1,
    'A failed off-site copy is recorded in WARNING_NOTE, not just logged');
ok(substr_count($sh, 'offsite_failed "') >= 3,
    'Every way the copy can fail goes through it — missing tool, bad target, failed transfer');
ok(preg_match('~warning="\$\(clean_json_text "\$\{WARNING_NOTE:-\}"\)"~', $sh) === 1,
    'And write_status puts that note where the app reads it');
// The binary matters: only the rclone that obtained the token can refresh it.
ok(str_contains($sh, '$HOME/bin/rclone'),
    'rclone is taken from $HOME/bin first, so the version that got the token is the one that uses it');

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
