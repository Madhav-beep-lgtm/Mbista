<?php
declare(strict_types=1);

// Simple, safe migration runner for M.Bista. It shares the application's .env
// configuration so it cannot accidentally upgrade a different local database.
require_once __DIR__ . '/app/config.php';

$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET), DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration_file VARCHAR(190) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_migration_file (migration_file)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$mode = $argv[1] ?? 'run';
if (!in_array($mode, ['run', 'status', 'baseline'], true)) {
    fwrite(STDERR, "Usage: php migrate.php [run|status|baseline]\n");
    exit(2);
}

$applied = $pdo->query("SELECT migration_file FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$dir = __DIR__ . '/database/migrations';
$files = glob($dir . '/*.sql');
sort($files);

if ($mode === 'status') {
    foreach ($files as $path) {
        $name = basename($path);
        echo (isset($applied[$name]) ? "[applied]  " : "[PENDING]  ") . $name . "\n";
    }
    exit(0);
}

foreach ($files as $path) {
    $name = basename($path);
    if (isset($applied[$name])) {
        continue;
    }

    if ($mode === 'baseline') {
        $pdo->prepare("INSERT INTO migrations (migration_file) VALUES (?)")->execute([$name]);
        echo "Marked as already applied: $name\n";
        continue;
    }

    echo "Applying: $name ... ";
    $sql = file_get_contents($path);
    try {
        $pdo->exec($sql);
        $pdo->prepare("INSERT INTO migrations (migration_file) VALUES (?)")->execute([$name]);
        echo "OK\n";
    } catch (Throwable $e) {
        echo "FAILED\n";
        echo "Error: " . $e->getMessage() . "\n";
        echo "Stopped here. Fix this file before running again.\n";
        exit(1);
    }
}

echo "Done.\n";
