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

$argument = $argv[1] ?? '';

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

fwrite(STDOUT, 'apply-migration: applying ' . $name . " ... ");

try {
    $pdo->exec((string) file_get_contents($path));
    $pdo->prepare('INSERT IGNORE INTO migrations (migration_file) VALUES (?)')->execute([$name]);
    fwrite(STDOUT, "OK\n");
    if (isset($recorded[$name])) {
        fwrite(STDOUT, "(already recorded; re-running was harmless because this migration is written to be)\n");
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, "FAILED\n");
    $fail('nothing was recorded: ' . $e->getMessage(), 1);
}
