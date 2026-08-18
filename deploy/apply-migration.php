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

require_once __DIR__ . '/../app/config.php';

$dir = __DIR__ . '/../database/migrations';
$argument = $argv[1] ?? '';

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration_file VARCHAR(190) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_migration_file (migration_file)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$recorded = array_flip($pdo->query('SELECT migration_file FROM migrations')->fetchAll(PDO::FETCH_COLUMN));

if ($argument === '' || $argument === '--list' || $argument === '-l') {
    echo 'Database: ' . DB_NAME . ' on ' . DB_HOST . "\n\n";
    foreach (glob($dir . '/*.sql') ?: [] as $path) {
        $name = basename($path);
        echo (isset($recorded[$name]) ? '  [recorded] ' : '  [   -    ] ') . $name . "\n";
    }
    echo "\nRun one with:  php deploy/apply-migration.php <filename.sql>\n";
    echo "[recorded] only means the ledger has a row for it, not that the schema was\n";
    echo "necessarily built by it - see the note at the top of this file.\n";
    exit(0);
}

// A basename only: no path, no traversal, and it has to be a file that is really
// in database/migrations.
$name = basename($argument);
if ($name !== $argument || !preg_match('/^[A-Za-z0-9._-]+\.sql$/', $name)) {
    fwrite(STDERR, "Give just the file name, e.g. 118_fixed_asset_tax_pool_and_disposal.sql\n");
    exit(2);
}
$path = $dir . '/' . $name;
if (!is_file($path)) {
    fwrite(STDERR, 'No such migration: ' . $name . "\nRun with --list to see what is available.\n");
    exit(2);
}

echo 'Database: ' . DB_NAME . ' on ' . DB_HOST . "\n";
echo 'Applying: ' . $name . ' ... ';

try {
    $pdo->exec((string) file_get_contents($path));
    $pdo->prepare('INSERT IGNORE INTO migrations (migration_file) VALUES (?)')->execute([$name]);
    echo "OK\n";
    if (isset($recorded[$name])) {
        echo "(it was already recorded; re-running was harmless because this migration is written to be)\n";
    }
    exit(0);
} catch (Throwable $e) {
    echo "FAILED\n";
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Nothing was recorded. Fix the cause and run it again.\n");
    exit(1);
}
