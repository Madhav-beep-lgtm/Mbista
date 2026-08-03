<?php
declare(strict_types=1);

/**
 * Why the backup banner says what it says.
 *
 * The banner reported "the last good database backup is 6 days old" on a
 * server whose backup-status.json read "state":"ok" from forty minutes
 * earlier, and whose log showed clean hourly dumps going back days. One of
 * those two is reading a different file from the other, and nothing in either
 * of them says which.
 *
 * That is the whole reason this exists. A backup banner that cries wolf is
 * worse than no banner: the one morning it is right, it looks like the same
 * false alarm as every other morning and gets clicked away. So this prints
 * every candidate path the app searches, in order, with what it found at each
 * one — and then the banner string itself, so the answer is not inferred from
 * a screenshot.
 *
 * Read-only. It writes nothing and changes nothing.
 *
 *   cd ~ && php database/check_backup_banner.php
 *
 * Run it from the DEPLOYED tree (~/database), not the repository checkout —
 * the repo has no .env, so BACKUP_DIR would read empty there and the answer
 * would be about the wrong machine.
 */

require __DIR__ . '/../app/bootstrap.php';

function line(string $s = ''): void { echo $s . "\n"; }
function yn(bool $b): string { return $b ? 'yes' : 'NO'; }

line();
line('Where this is running');
line('---------------------');
line('  app/ directory      : ' . dirname(__DIR__) . '/app');
line('  dirname(__DIR__)    : ' . dirname(__DIR__) . '   <- the app treats this as the account root');
line('  getenv(HOME)        : ' . (getenv('HOME') !== false && getenv('HOME') !== '' ? (string) getenv('HOME') : '(unset — normal under Apache/PHP-FPM)'));
line('  PHP timezone        : ' . date_default_timezone_get());
line('  clock now           : ' . date('Y-m-d H:i:s'));

$configured = (string) (function_exists('env') ? env('BACKUP_DIR', '') : '');
line('  BACKUP_DIR in .env  : ' . ($configured !== '' ? $configured : '(not set — the app falls back to its own location)'));
line('  BACKUP_REMOTE       : ' . (trim((string) env('BACKUP_REMOTE', '')) !== '' ? 'set' : '(not set — every copy is on this server)'));

// The same candidate list backup_status_read() walks, in the same order, so
// the first "found: yes" below is the file the banner is actually reading.
$appBase = dirname(__DIR__);
$candidates = [];
if ($configured !== '') {
    $candidates[] = rtrim($configured, '/\\');
}
$candidates[] = $appBase . '/db-backups';
$home = (string) (getenv('HOME') ?: '');
if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
    $account = @posix_getpwuid(posix_geteuid());
    $home = (string) ($account['dir'] ?? '');
}
if ($home !== '') {
    $candidates[] = rtrim($home, '/\\') . '/db-backups';
}
$candidates[] = dirname($appBase) . '/db-backups';
$candidates[] = $appBase . '/storage/backups';

line();
line('Candidates, in the order the app tries them');
line('-------------------------------------------');
$firstHit = null;
foreach ($candidates as $i => $dir) {
    $path = $dir . '/backup-status.json';
    $found = is_file($path);
    if ($found && $firstHit === null) {
        $firstHit = $path;
    }
    line(sprintf('  %d. %-52s found: %-3s%s', $i + 1, $path, yn($found),
        $found ? '  written ' . date('Y-m-d H:i:s', (int) filemtime($path)) : ''));
    if ($found) {
        $raw = trim((string) @file_get_contents($path));
        line('     ' . ($raw === '' ? '(empty file)' : $raw));
        if ($firstHit !== $path) {
            line('     ^ NOT the one being used — an earlier candidate already matched.');
        }
    }
}

line();
line('What the app concluded');
line('----------------------');
$read = backup_status_read();
$status = $read['status'];
line('  read from           : ' . ($read['path'] !== '' ? $read['path'] : '(nothing found)'));
if ($status === null) {
    line('  parsed              : NOTHING — no readable status at any candidate.');
} else {
    $ranAt = (string) ($status['at'] ?? '');
    $stamp = $ranAt !== '' ? strtotime($ranAt) : false;
    $ageH = $stamp !== false ? (time() - $stamp) / 3600 : null;
    line('  state               : ' . (string) ($status['state'] ?? '(missing)'));
    line('  at                  : ' . ($ranAt !== '' ? $ranAt : '(missing)'));
    line('  parsed as           : ' . ($stamp !== false ? date('Y-m-d H:i:s', $stamp) : 'UNPARSEABLE — this alone makes the age enormous'));
    line('  age                 : ' . ($ageH === null ? 'n/a' : sprintf('%.1f hours (%.1f days)', $ageH, $ageH / 24)));
    line('  warning field       : ' . (trim((string) ($status['warning'] ?? '')) !== '' ? (string) $status['warning'] : '(none)'));
    if ($ageH !== null && $ageH > 48) {
        line('  -> over the 48-hour threshold, so the banner reports a stopped job.');
    }
}

line();
line('The banner itself');
line('-----------------');
$banner = backup_health_warning();
line('  ' . ($banner === null ? '(none — nothing to report)' : $banner));
line();

// A mismatch between the two halves is the whole point of this script, so it
// is stated outright rather than left to be spotted in the lists above.
if ($status !== null && $read['path'] !== '') {
    $expected = ($home !== '' ? $home : $appBase) . '/db-backups/backup-status.json';
    if (realpath($read['path']) !== realpath($expected) && is_file($expected)) {
        line('MISMATCH: the backup script writes ' . $expected);
        line('          the app reads          ' . $read['path']);
        line('          Point them at the same directory with BACKUP_DIR in .env.');
        line();
    }
}
