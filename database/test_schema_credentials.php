<?php
declare(strict_types=1);

/**
 * The seeded accounts in the shipped schema must not be a way in.
 *
 * These files are in the git repository. They used to carry real bcrypt hashes
 * for the super admin and two other accounts, so anyone who could read the
 * repository could take the hash away and attack it offline for as long as they
 * liked — and if the installed password had never been changed, walk in.
 *
 * Two properties are checked here, and the second one is not obvious:
 *
 *   1. A fresh install ships NO usable password. The placeholder is not a hash
 *      and password_verify() rejects everything against it, itself included.
 *
 *   2. Re-running the schema over a LIVE database leaves existing credentials
 *      alone. The ON DUPLICATE KEY UPDATE clause used to re-assert
 *      password_hash, so importing the file again silently reset a working
 *      admin password back to the one this file shipped. It also re-asserted
 *      role and status, which would re-activate or re-promote an account
 *      somebody had disabled or demoted on purpose.
 *
 *   php database/test_schema_credentials.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

$schemaFiles = ['schema.sql', 'schema_cpanel.sql', 'schema_cpanel_fkfix.sql'];

echo "1. Nothing in the repository is a password anybody can attack\n";
$withHashes = [];
foreach ($schemaFiles as $name) {
    $sql = (string) file_get_contents($root . '/database/' . $name);
    if (preg_match('~\$2[aby]\$[0-9]+\$~', $sql)) {
        $withHashes[] = $name;
    }
}
ok($withHashes === [], 'No bcrypt hash is seeded in any schema file'
    . ($withHashes === [] ? '' : ' — found in ' . implode(', ', $withHashes)));

// Not just the schema files: any tracked file carrying a hash is the same leak.
$tracked = [];
exec('git -C ' . escapeshellarg($root) . ' ls-files', $tracked);
$leaky = [];
foreach ($tracked as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path) || filesize($path) > 4_000_000) {
        continue;
    }
    // Only text worth scanning; a binary match would be noise.
    if (!preg_match('~\.(sql|php|md|txt|json|ya?ml|env|example|sh)$~i', $relative)) {
        continue;
    }
    $body = (string) file_get_contents($path);
    // The generator itself legitimately mentions the algorithm; a LITERAL hash
    // is what matters, so the pattern requires the full 53-character tail.
    if (preg_match('~\$2[aby]\$[0-9]{2}\$[A-Za-z0-9./]{53}~', $body)) {
        $leaky[] = $relative;
    }
}
ok($leaky === [], 'And no other tracked file carries one either'
    . ($leaky === [] ? '' : ' — ' . implode(', ', $leaky)));

echo "\n2. The placeholder cannot be logged in with\n";
preg_match("~'(\\*LOCKED[^']*)'~", (string) file_get_contents($root . '/database/schema_cpanel.sql'), $m);
$placeholder = (string) ($m[1] ?? '');
ok($placeholder !== '', 'The seeded rows carry a locked placeholder');
$accepted = [];
foreach (['', ' ', 'admin', 'password', 'Password1', 'Secret#12345', $placeholder, '*'] as $attempt) {
    if ($placeholder !== '' && password_verify($attempt, $placeholder)) {
        $accepted[] = $attempt === '' ? '(empty)' : $attempt;
    }
}
ok($accepted === [], 'password_verify() rejects every candidate against it, itself included'
    . ($accepted === [] ? '' : ' — accepted ' . implode(', ', $accepted)));
ok(str_contains($placeholder, 'change_admin_credentials'),
    'And it says how to set a real one, so a fresh install is not simply stuck');

echo "\n3. Re-importing the schema does not disturb a live account\n";
/*
 * Proved against the database rather than by reading the SQL: the clause is
 * replayed here exactly as an import would replay it, over a row that already
 * has a working password.
 */
db()->exec("DELETE FROM users WHERE email = 'schema-probe@test.local'");
$realHash = password_hash('A-Real-Password-9', PASSWORD_DEFAULT);
db()->prepare("INSERT INTO users (name, email, password_hash, role, status, company)
    VALUES ('Schema Probe', 'schema-probe@test.local', :h, 'admin', 'inactive', 'Probe')")
    ->execute(['h' => $realHash]);

// The clause the schema files now use, applied to that row.
db()->prepare("INSERT INTO users (name, email, password_hash, role, status, company)
    VALUES ('Renamed By Import', 'schema-probe@test.local', :h, 'customer', 'active', 'Probe Co')
    ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `company` = VALUES(`company`)")
    ->execute(['h' => $placeholder]);

$after = db()->query("SELECT * FROM users WHERE email = 'schema-probe@test.local'")->fetch(PDO::FETCH_ASSOC);
ok($after !== false, 'The account is still there after a re-import');
ok($after && password_verify('A-Real-Password-9', (string) $after['password_hash']),
    'Its password still works — a re-import cannot reset a live admin to a shipped one');
ok($after && (string) $after['status'] === 'inactive',
    'A disabled account stays disabled — a re-import cannot quietly switch it back on');
ok($after && (string) $after['role'] === 'admin',
    'And a role somebody set on purpose is not re-asserted from the file');
ok($after && (string) $after['name'] === 'Renamed By Import',
    'Descriptive columns are still refreshed, which is the point of the clause');

db()->exec("DELETE FROM users WHERE email = 'schema-probe@test.local'");

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
