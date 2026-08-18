<?php
declare(strict_types=1);

/**
 * Applies ONE named migration file, and records that it was applied.
 *
 *   php deploy/apply-migration.php 118_fixed_asset_tax_pool_and_disposal.sql
 *   php deploy/apply-migration.php --list
 *
 * Why this exists rather than `php migrate.php run`:
 *
 * The migrations ledger on this project records one row against 118 migration
 * files, because the schema was built by other means and the ledger was never
 * back-filled. `migrate.php run` therefore tries to replay 117 migrations
 * against a database that already has them, fails on the first conflict, and
 * leaves you guessing. Baselining the ledger would fix that, but it asserts that
 * all 118 really were applied - which nobody can currently verify.
 *
 * So: name the one file you mean. It runs, it is recorded, and nothing else is
 * touched. Safe to re-run when the migration itself is written to be re-runnable
 * (everything under database/migrations that uses IF NOT EXISTS is).
 *
 * Reads the same .env as the application, so it cannot upgrade the wrong
 * database by accident.
 */

// Say something before doing anything. A shared host with display_errors off
// turns any fatal - a missing .env, a refused database connection - into a
// completely silent command, which is indistinguishable from the file not being
// deployed yet. One line of output proves the script at least started.
fwrite(STDOUT, "apply-migration: starting (PHP " . PHP_VERSION . ")\n");

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Errors go to the terminal whatever the host's php.ini says, or the operator is
// left reading a blank prompt.
ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');
error_reporting(E_ALL);

$fail = static function (string $message, int $code = 1): never {
    fwrite(STDERR, 'apply-migration: ' . $message . "\n");
    exit($code);
};

// Find the .env this SERVER actually uses.
//
// .env is gitignored, so the repository checkout has none: app/config.php looks
// for one beside itself, finds nothing, and falls back to its development
// defaults - root, no password, the developer's database name. On a live server
// that produced "Access denied for user 'root'@'localhost'", which is a true
// message about entirely the wrong credentials.
//
// The deploy puts the real .env one level ABOVE the document root, alongside the
// app/ it copies there, so that is where to look. The candidates mirror
// deploy/tasks.sh exactly, including the two ways an account can name its own
// docroot, so the two can never disagree about which install is being touched.
$envArgument = '';
$positional = [];
foreach (array_slice($argv, 1) as $rawArgument) {
    if (str_starts_with($rawArgument, '--env=')) {
        $envArgument = substr($rawArgument, 6);
        continue;
    }
    $positional[] = $rawArgument;
}

$home = (string) (getenv('HOME') ?: '');
$docroots = [];
if ((string) getenv('DEPLOY_DOCROOT') !== '') {
    $docroots[] = (string) getenv('DEPLOY_DOCROOT');
}
if ($home !== '' && is_file($home . '/.deploy-docroot')) {
    $named = trim((string) @file_get_contents($home . '/.deploy-docroot'));
    if ($named !== '') {
        $docroots[] = strtok($named, "\r\n");
    }
}
if ($home !== '') {
    $docroots[] = $home . '/public_html';
    $docroots[] = $home . '/mbca.com.np';
    $docroots[] = $home . '/public_html/mbca.com.np';
}

// An explicitly named file is authoritative. Searching on after a typo in it
// would connect to a DIFFERENT database than the operator named, which is the
// same class of mistake as silently falling back to the dev defaults.
if ($envArgument !== '') {
    if (!is_file($envArgument) || !is_readable($envArgument)) {
        $fail('--env=' . $envArgument . ' is not a readable file.', 2);
    }
    if (!str_contains((string) @file_get_contents($envArgument), 'DB_NAME')) {
        $fail('--env=' . $envArgument . ' does not name a DB_NAME.', 2);
    }
}

$envCandidates = [];
if ($envArgument !== '') {
    $envCandidates[] = $envArgument;
}
foreach ($docroots as $docroot) {
    $envCandidates[] = dirname($docroot) . '/.env';
}
$envCandidates[] = __DIR__ . '/../.env';

$envPath = '';
foreach ($envCandidates as $candidate) {
    if (is_file($candidate) && is_readable($candidate) && str_contains((string) @file_get_contents($candidate), 'DB_NAME')) {
        $envPath = $candidate;
        break;
    }
}
if ($envPath === '') {
    fwrite(STDERR, "apply-migration: no .env naming a DB_NAME was found. Looked in:\n");
    foreach (array_unique($envCandidates) as $candidate) {
        fwrite(STDERR, '  - ' . $candidate . "\n");
    }
    $fail('point at it explicitly with --env=/full/path/to/.env rather than let the dev defaults be used.', 2);
}
fwrite(STDOUT, 'apply-migration: using ' . $envPath . "\n");

// Put them in the environment BEFORE config.php runs. load_env_file() skips any
// key already set, so these win over the repository's absent-or-example file
// instead of racing it.
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    $value = trim($value);
    if (strlen($value) > 1 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
        $value = substr($value, 1, -1);
    }
    if ($key !== '') {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

$configPath = __DIR__ . '/../app/config.php';
if (!is_file($configPath)) {
    $fail('cannot find app/config.php next to this script - is the repository complete?', 2);
}

try {
    require_once $configPath;
} catch (Throwable $e) {
    $fail('app/config.php could not be loaded: ' . $e->getMessage(), 2);
}

foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_CHARSET'] as $required) {
    if (!defined($required)) {
        $fail($required . ' is not defined - check the .env this server is using.', 2);
    }
}

fwrite(STDOUT, 'apply-migration: database ' . DB_NAME . ' on ' . DB_HOST . ' as ' . DB_USER . "\n");

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    $fail('could not connect: ' . $e->getMessage(), 3);
}

$dir = __DIR__ . '/../database/migrations';
if (!is_dir($dir)) {
    $fail('cannot find database/migrations - expected at ' . $dir, 2);
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        migration_file VARCHAR(190) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_migration_file (migration_file)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $recorded = array_flip($pdo->query('SELECT migration_file FROM migrations')->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {
    $fail('could not read the migrations ledger: ' . $e->getMessage(), 3);
}

$argument = $positional[0] ?? '';

if ($argument === '' || $argument === '--list' || $argument === '-l') {
    $files = glob($dir . '/*.sql') ?: [];
    fwrite(STDOUT, "\n" . count($files) . " migration file(s):\n\n");
    foreach ($files as $path) {
        $name = basename($path);
        fwrite(STDOUT, (isset($recorded[$name]) ? '  [recorded] ' : '  [   -    ] ') . $name . "\n");
    }
    fwrite(STDOUT, "\nRun one with:  php deploy/apply-migration.php <filename.sql>\n");
    fwrite(STDOUT, "[recorded] only means the ledger has a row for it, not that the schema was\n");
    fwrite(STDOUT, "necessarily built by it - see the note at the top of this file.\n");
    exit(0);
}

// A basename only: no path, no traversal, and it has to be a file that is really
// in database/migrations.
$name = basename($argument);
if ($name !== $argument || !preg_match('/^[A-Za-z0-9._-]+\.sql$/', $name)) {
    $fail('give just the file name, e.g. 118_fixed_asset_tax_pool_and_disposal.sql', 2);
}
$path = $dir . '/' . $name;
if (!is_file($path)) {
    $fail('no such migration: ' . $name . ' (run with --list to see what is available)', 2);
}

fwrite(STDOUT, 'apply-migration: applying ' . $name . ": ");

// One statement at a time, NOT one exec() of the whole file. PDO::exec() with
// several statements in it can run the first and quietly drop the rest depending
// on the driver and server, which leaves a migration half applied and a schema
// that looks fine in the ledger while the application falls over on the columns
// that never arrived. Statement by statement, a failure names the statement that
// failed and everything before it stands.
$sql = (string) file_get_contents($path);
// Strip comments before splitting, so a semicolon inside one cannot cut a
// statement in half.
$sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
$statements = [];
foreach (explode(';', $sql) as $fragment) {
    $fragment = trim($fragment);
    if ($fragment !== '') {
        $statements[] = $fragment;
    }
}
if ($statements === []) {
    fwrite(STDOUT, "SKIPPED\n");
    $fail('that file contains no SQL statement.', 2);
}

fwrite(STDOUT, count($statements) . " statement(s)\n");
$done = 0;
foreach ($statements as $index => $statement) {
    $label = '  [' . ($index + 1) . '/' . count($statements) . '] ' . preg_replace('/\s+/', ' ', substr($statement, 0, 68));
    try {
        $pdo->exec($statement);
        fwrite(STDOUT, $label . " ... ok\n");
        $done++;
    } catch (Throwable $e) {
        fwrite(STDOUT, $label . " ... FAILED\n");
        fwrite(STDERR, 'apply-migration: statement ' . ($index + 1) . ' failed: ' . $e->getMessage() . "\n");
        fwrite(STDERR, 'apply-migration: ' . $done . ' statement(s) before it did apply; nothing was recorded.' . "\n");
        exit(1);
    }
}

$pdo->prepare('INSERT IGNORE INTO migrations (migration_file) VALUES (?)')->execute([$name]);
fwrite(STDOUT, 'apply-migration: all ' . $done . " statement(s) applied and recorded.\n");
if (isset($recorded[$name])) {
    fwrite(STDOUT, "(it was already recorded; re-running was harmless because this migration is written to be)\n");
}
exit(0);
