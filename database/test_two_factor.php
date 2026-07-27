<?php
declare(strict_types=1);

/**
 * Two-factor authentication.
 *
 * The arithmetic is checked against RFC 6238's own published test vectors, so
 * "it works" means "every authenticator app in the world agrees with it",
 * not "it agrees with itself".
 *
 *   php database/test_two_factor.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/two_factor.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

function tfa_cleanup(): void
{
    foreach (db()->query("SELECT id FROM users WHERE email LIKE 'tfa-%@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM user_two_factor WHERE user_id = ' . (int) $u);
        db()->exec('DELETE FROM client_profiles WHERE user_id = ' . (int) $u);
        db()->exec('DELETE FROM users WHERE id = ' . (int) $u);
    }
}
two_factor_ensure_schema();
tfa_cleanup();

echo "1. The algorithm, against RFC 6238's published vectors\n";
// Appendix B of the RFC. The seed is the ASCII "12345678901234567890".
$rfcSecret = two_factor_base32_encode('12345678901234567890');
foreach ([
    [59, '287082'],
    [1111111109, '081804'],
    [1111111111, '050471'],
    [1234567890, '005924'],
    [2000000000, '279037'],
    [20000000000, '353130'],
] as [$time, $expected]) {
    ok(two_factor_code_at($rfcSecret, intdiv($time, 30)) === $expected,
        'T=' . $time . ' gives ' . $expected);
}

echo "\n2. Base32 round trip\n";
$raw = random_bytes(20);
ok(two_factor_base32_decode(two_factor_base32_encode($raw)) === $raw, 'Encoding and decoding return the original bytes');
ok(strlen(two_factor_generate_secret()) === 32, 'A generated secret is 160 bits, the size RFC 4226 asks for');
ok(two_factor_generate_secret() !== two_factor_generate_secret(), 'And two secrets are never the same');

echo "\n3. Clock drift is tolerated, but not without limit\n";
$secret = two_factor_generate_secret();
$now = time();
$step = two_factor_current_step($now);
ok(two_factor_match_step($secret, two_factor_code_at($secret, $step), $now) === $step, "This moment's code is accepted");
ok(two_factor_match_step($secret, two_factor_code_at($secret, $step - 1), $now) === $step - 1,
    'A phone 30 seconds slow still works');
ok(two_factor_match_step($secret, two_factor_code_at($secret, $step + 1), $now) === $step + 1,
    'And one 30 seconds fast');
ok(two_factor_match_step($secret, two_factor_code_at($secret, $step - 3), $now) === null,
    'A phone 90 seconds out does NOT — the window is deliberately narrow');
ok(two_factor_match_step($secret, '000000', $now) === null || two_factor_code_at($secret, $step) === '000000',
    'A guessed code is refused');
ok(two_factor_match_step($secret, '12345', $now) === null, 'So is one of the wrong length');
ok(two_factor_match_step($secret, 'abcdef', $now) === null, 'And one that is not digits at all');

echo "\n4. Enrolment has to be proved before it takes effect\n";
$uid = create_user(['name' => 'TFA Tester', 'email' => 'tfa-a@test.local', 'password' => 'Secret#12345',
    'role' => 'admin', 'status' => 'active']);
ok(!two_factor_is_active($uid), 'A new account has no second factor');

$enrolSecret = two_factor_begin_enrolment($uid);
ok($enrolSecret !== '' && !two_factor_is_active($uid),
    'Starting the setup issues a key but does NOT switch anything on');

$bad = two_factor_confirm_enrolment($uid, '000000');
ok(!$bad['ok'] && !two_factor_is_active($uid),
    'A wrong code leaves it off — this is what stops a mistyped key locking somebody out');

$good = two_factor_confirm_enrolment($uid, two_factor_code_at($enrolSecret, two_factor_current_step()));
ok($good['ok'], 'The right code switches it on');
ok(two_factor_is_active($uid), 'And it reads as active');
ok(count($good['recovery_codes']) === TWO_FACTOR_RECOVERY_COUNT, 'Ten recovery codes come back');
ok(!two_factor_confirm_enrolment($uid, two_factor_code_at($enrolSecret, two_factor_current_step()))['ok'],
    'Enrolling twice over an active factor is refused');

echo "\n5. Recovery codes are stored the way passwords are\n";
$storedRow = two_factor_row($uid);
$storedCodes = json_decode((string) $storedRow['recovery_codes'], true);
ok(is_array($storedCodes) && count($storedCodes) === TWO_FACTOR_RECOVERY_COUNT, 'All ten are stored');
$plainFirst = $good['recovery_codes'][0];
ok(!in_array($plainFirst, $storedCodes, true),
    'The plain text is NOT among them — a database leak does not hand over the second factor');
ok(str_starts_with((string) $storedCodes[0], '$'), 'They are password hashes');

echo "\n6. A code cannot be used twice\n";
// This is the property that matters when somebody reads a code over your
// shoulder, or off an unencrypted connection: it is only good once.
// Enrolment consumed the step it was confirmed on, so the next code is the
// first one that can legitimately sign in. $now is passed so the test drives
// the clock rather than sleeping thirty seconds.
$laterTime = time() + TWO_FACTOR_PERIOD;
$liveStep = two_factor_current_step($laterTime);
$liveCode = two_factor_code_at((string) two_factor_row($uid)['secret'], $liveStep);
$first = two_factor_verify($uid, $liveCode, $laterTime);
ok($first['ok'], 'The next code is accepted' . ($first['ok'] ? '' : ' — ' . $first['error']));
$second = two_factor_verify($uid, $liveCode, $laterTime);
ok(!$second['ok'] && stripos($second['error'], 'already been used') !== false,
    'And refused the second, inside its own thirty seconds');

echo "\n7. Recovery codes work once each\n";
$rec = two_factor_verify($uid, $plainFirst);
ok($rec['ok'] && !empty($rec['used_recovery']), 'A recovery code signs you in');
ok((int) $rec['remaining'] === TWO_FACTOR_RECOVERY_COUNT - 1, 'And is spent — nine left');
ok(!two_factor_verify($uid, $plainFirst)['ok'], 'The same one does not work again');
ok(two_factor_recovery_remaining($uid) === TWO_FACTOR_RECOVERY_COUNT - 1, 'The count agrees');
ok(two_factor_verify($uid, 'ZZZZZ-ZZZZZ')['ok'] === false, 'An invented recovery code is refused');

echo "\n8. The sign-in gate holds the user at the door\n";
// The whole point: a right password must not, by itself, produce a session.
$_SESSION = [];
two_factor_hold_pending($uid);
ok(!isset($_SESSION['user_id']),
    'While the challenge is pending there is NO user_id — nothing treats the visitor as signed in');
ok(two_factor_pending_user_id() === $uid, 'The pending user is remembered separately');

$_SESSION['2fa_pending_since'] = time() - 601;
ok(two_factor_pending_user_id() === 0, 'A challenge left open past ten minutes expires');
ok(!isset($_SESSION['2fa_pending_user_id']), 'And is cleared, so it cannot be resumed');

two_factor_hold_pending($uid);
two_factor_complete_pending($uid);
ok((int) ($_SESSION['user_id'] ?? 0) === $uid, 'Passing the challenge grants the session');
ok(!isset($_SESSION['2fa_pending_user_id']), 'And clears the pending state');
$_SESSION = [];

echo "\n9. Who is required to use it\n";
$plainUser = ['id' => $uid, 'role' => 'admin'];
ok(two_factor_required_for($plainUser), 'A user who switched it on always has to use it');
$uid2 = create_user(['name' => 'TFA Two', 'email' => 'tfa-b@test.local', 'password' => 'Secret#12345',
    'role' => 'admin', 'status' => 'active']);
$before = (string) setting('security_2fa_required', '0');
update_settings(['security_2fa_required' => '0']);
ok(!two_factor_required_for(['id' => $uid2, 'role' => 'admin']),
    'With the policy off, someone who has not set it up is not forced');
update_settings(['security_2fa_required' => '1']);
ok(two_factor_required_for(['id' => $uid2, 'role' => 'admin']), 'With it on, an admin is');
ok(two_factor_required_for(['id' => $uid2, 'role' => 'staff']), 'So is a staff member');
ok(!two_factor_required_for(['id' => $uid2, 'role' => 'customer']),
    'A customer is not — they use their own portal, and the policy is about the admin side');
update_settings(['security_2fa_required' => $before]);

echo "\n10. Switching off\n";
two_factor_disable($uid);
ok(!two_factor_is_active($uid), 'It goes off');
ok(two_factor_row($uid) === null, 'And the secret is gone, not merely flagged');
ok(!two_factor_verify($uid, '123456')['ok'], 'Verification against a disabled account fails');

tfa_cleanup();
echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
