<?php
declare(strict_types=1);

/**
 * The "forgot my password" flow, end to end.
 *
 * A reset link is a bearer token that hands over an account, so the questions
 * that matter are not "does it work" but "when does it STOP working": after it
 * is used, after it expires, after a newer one is issued, and for an account
 * that is not allowed to sign in at all.
 *
 *   php database/test_password_reset.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

function pwr_cleanup(): void
{
    foreach (db()->query("SELECT id FROM users WHERE email LIKE 'pwreset-%@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM password_resets WHERE user_id = ' . (int) $u);
        db()->exec('DELETE FROM users WHERE id = ' . (int) $u);
    }
}
pwr_cleanup();

$makeUser = static function (string $slug, string $status = 'active'): int {
    $id = create_user(['name' => 'Reset ' . $slug, 'email' => 'pwreset-' . $slug . '@test.local',
        'password' => 'OriginalPass#123', 'role' => 'customer', 'status' => 'active']);
    if ($status !== 'active') {
        db()->prepare('UPDATE users SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $id]);
    }

    return $id;
};

echo "1. Requesting a link\n";
$uid = $makeUser('a');
$token = request_password_reset('pwreset-a@test.local', '203.0.113.5');
ok(is_string($token) && $token !== '', 'A request returns a token');
ok(strlen((string) $token) === 64, 'It is 32 random bytes as hex — 256 bits, not guessable');
ok(ctype_xdigit((string) $token), 'And it is hex, so it survives a URL intact');

$stored = db()->query('SELECT * FROM password_resets WHERE user_id = ' . $uid . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ok($stored !== false, 'A row is stored for it');
ok((string) $stored['token_hash'] !== (string) $token,
    'The RAW token is not in the database — a leaked table does not hand over every account');
ok((string) $stored['token_hash'] === hash('sha256', (string) $token), 'It is stored as its SHA-256');
ok((string) $stored['requested_ip'] === '203.0.113.5', 'The requesting IP is recorded');
ok($stored['used_at'] === null, 'And it starts unused');

// Measured on the DATABASE's clock, because both the expiry and the check that
// enforces it are written there. Comparing a stored SQL timestamp against PHP's
// time() measures the gap between two clocks, not the life of the token — which
// is how this assertion first reported a one-hour window as nearly seven.
$expirySeconds = (int) db()->query('SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at)
    FROM password_resets WHERE user_id = ' . $uid . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
ok($expirySeconds > 3300 && $expirySeconds <= 3600,
    'It expires in an hour, not a day (' . (int) round($expirySeconds / 60) . ' min)');

// And the two clocks now agree, which is what lets any PHP-side date be
// compared against a SQL one at all.
$driftStmt = db()->prepare('SELECT ABS(TIMESTAMPDIFF(SECOND, NOW(), :phpnow))');
$driftStmt->execute(['phpnow' => date('Y-m-d H:i:s')]);
ok((int) $driftStmt->fetchColumn() <= 2, 'PHP and MySQL agree on what time it is');

echo "\n2. The link is emailed\n";
// With no SMTP configured the mailer writes the message to storage/mail
// instead of sending, which is what makes this checkable at all.
$mailDir = dirname(__DIR__) . '/storage/mail';
$before = is_dir($mailDir) ? glob($mailDir . '/*') : [];
$uidMail = $makeUser('mail');
$mailToken = request_password_reset('pwreset-mail@test.local');
clearstatcache();
$after = is_dir($mailDir) ? glob($mailDir . '/*') : [];
$newFiles = array_values(array_diff($after, $before));
ok($newFiles !== [], 'A message is produced (' . count($newFiles) . ' new file in storage/mail)');

$body = $newFiles !== [] ? (string) file_get_contents($newFiles[0]) : '';
ok(strpos($body, 'pwreset-mail@test.local') !== false, 'Addressed to the person who asked');
ok(stripos($body, 'reset') !== false, 'And it is the reset message');
// The body is base64 inside a MIME part, so it has to be decoded before any of
// this can be looked for — reading the raw .eml finds nothing and says so
// convincingly.
preg_match('~Content-Transfer-Encoding: base64\r?\n\r?\n(.*?)\r?\n--~s', $body, $partMatch);
$decoded = base64_decode(preg_replace('/\s+/', '', $partMatch[1] ?? ''), true) ?: '';
ok(strpos($decoded, (string) $mailToken) !== false, 'The email carries the actual token');
ok(strpos($decoded, 'reset-password.php') !== false, 'Pointing at reset-password.php');

// A link built on the wrong host is a dead link, and nobody finds out until a
// customer cannot get in.
preg_match('~href="([^"]*reset-password[^"]*)"~', $decoded, $linkMatch);
$resetLink = $linkMatch[1] ?? '';
ok($resetLink !== '' && filter_var($resetLink, FILTER_VALIDATE_URL) !== false,
    'The link is an absolute URL, so it survives being clicked out of an inbox');
ok(strpos($resetLink, '?token=' . $mailToken) !== false, 'And carries the token as its query string');
foreach ($newFiles as $f) { @unlink($f); }

echo "\n3. The link works, once\n";
ok(password_reset_user_by_token((string) $token) !== null, 'The token resolves to its account');
ok(reset_password_by_token((string) $token, 'BrandNewPass#456'), 'It sets the new password');

$account = db()->query('SELECT password_hash FROM users WHERE id = ' . $uid)->fetch(PDO::FETCH_ASSOC);
ok(password_verify('BrandNewPass#456', (string) $account['password_hash']), 'The new password works');
ok(!password_verify('OriginalPass#123', (string) $account['password_hash']), 'And the old one does not');

ok(password_reset_user_by_token((string) $token) === null,
    'The SAME link no longer resolves — it is spent');
ok(!reset_password_by_token((string) $token, 'ThirdPass#789'), 'And cannot set a password again');
$stillNew = db()->query('SELECT password_hash FROM users WHERE id = ' . $uid)->fetch(PDO::FETCH_ASSOC);
ok(password_verify('BrandNewPass#456', (string) $stillNew['password_hash']),
    'A replayed link leaves the password exactly as it was');

echo "\n4. When it stops working\n";
$uidB = $makeUser('b');
$expiredToken = request_password_reset('pwreset-b@test.local');
db()->exec('UPDATE password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE user_id = ' . $uidB);
ok(password_reset_user_by_token((string) $expiredToken) === null, 'An expired link is refused');
ok(!reset_password_by_token((string) $expiredToken, 'Whatever#12345'), 'And cannot change anything');

$uidC = $makeUser('c');
$firstToken = request_password_reset('pwreset-c@test.local');
$secondToken = request_password_reset('pwreset-c@test.local');
ok($firstToken !== $secondToken, 'Asking twice gives two different tokens');
ok(password_reset_user_by_token((string) $firstToken) === null,
    'And the FIRST is dead — asking again is how someone reacts to a suspicious email');
ok(password_reset_user_by_token((string) $secondToken) !== null, 'Only the newest works');

$uidD = $makeUser('d', 'inactive');
ok(request_password_reset('pwreset-d@test.local') === null,
    'A suspended account gets no link at all');
ok(request_password_reset('nobody-at-all@test.local') === null, 'Nor does an address with no account');

echo "\n5. Guessing\n";
ok(password_reset_user_by_token('') === null, 'An empty token is refused');
ok(password_reset_user_by_token('short') === null, 'A short one is refused');
ok(password_reset_user_by_token(str_repeat('a', 64)) === null, 'A well-formed guess is refused');
$uidE = $makeUser('e');
$realToken = (string) request_password_reset('pwreset-e@test.local');
// One character off must fail: this is what proves the comparison is on the
// whole hash and not a prefix.
$nearMiss = substr($realToken, 0, 63) . (substr($realToken, 63, 1) === 'a' ? 'b' : 'a');
ok(password_reset_user_by_token($nearMiss) === null, 'A token wrong in one character is refused');
ok(password_reset_user_by_token($realToken) !== null, 'While the real one still works');

echo "\n6. What the page tells a stranger\n";
// Answering "no such account" turns the form into a way of testing whether an
// address is a customer of yours. The page must say the same thing either way.
$src = (string) file_get_contents(dirname(__DIR__) . '/public_html/forgot-password.php');
ok(substr_count($src, 'request_password_reset') === 1, 'The page asks for a reset in exactly one place');
$revealing = preg_match('~(no account|not found|does not exist|no such user|unknown email)~i', $src) === 1;
ok(!$revealing, 'And never says whether the address is one of yours');

pwr_cleanup();
echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
