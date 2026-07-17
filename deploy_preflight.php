<?php
declare(strict_types=1);

$root = __DIR__;

$checks = [
    ['type' => 'dir', 'path' => $root . '/public_html', 'label' => 'public_html directory'],
    ['type' => 'dir', 'path' => $root . '/app', 'label' => 'app directory'],
    ['type' => 'dir', 'path' => $root . '/database', 'label' => 'database directory'],
    ['type' => 'file', 'path' => $root . '/database/schema.sql', 'label' => 'database schema'],
    ['type' => 'file', 'path' => $root . '/public_html/.htaccess', 'label' => 'public .htaccess'],
    ['type' => 'file', 'path' => $root . '/public_html/uploads/.htaccess', 'label' => 'uploads .htaccess'],
    ['type' => 'file', 'path' => $root . '/.env.example', 'label' => '.env.example'],
    ['type' => 'file', 'path' => $root . '/public_html/setup.php', 'label' => 'setup.php'],
    ['type' => 'file', 'path' => $root . '/public_html/admin/export-payment-receipt.php', 'label' => 'receipt export endpoint'],
];

$migrationFiles = [];
$migrationDir = $root . '/database/migrations';
if (is_dir($migrationDir)) {
    $files = scandir($migrationDir);
    if (is_array($files)) {
        foreach ($files as $file) {
            if (preg_match('/^\d+_.*\.sql$/', $file) === 1) {
                $migrationFiles[] = $file;
            }
        }
    }
}
sort($migrationFiles, SORT_NATURAL);

$expectedLatest = '052_voucher_entries_restrict_ledger_delete.sql';
$hasLatest = in_array($expectedLatest, $migrationFiles, true);

$passed = 0;
$failed = 0;

function result_line(bool $ok, string $label, string $extra = ''): void
{
    $status = $ok ? '[PASS]' : '[FAIL]';
    echo $status . ' ' . $label;
    if ($extra !== '') {
        echo ' - ' . $extra;
    }
    echo PHP_EOL;
}

echo "Deployment preflight check\n";
echo str_repeat('=', 28) . "\n\n";

foreach ($checks as $check) {
    $ok = false;
    if ($check['type'] === 'dir') {
        $ok = is_dir($check['path']);
    } elseif ($check['type'] === 'file') {
        $ok = is_file($check['path']);
    }

    if ($ok) {
        $passed++;
    } else {
        $failed++;
    }

    result_line($ok, $check['label'], str_replace($root . '/', '', $check['path']));
}

if ($hasLatest) {
    $passed++;
    result_line(true, 'latest migration present', $expectedLatest);
} else {
    $failed++;
    result_line(false, 'latest migration present', $expectedLatest . ' missing');
}

if (count($migrationFiles) > 0) {
    $passed++;
    result_line(true, 'migration file count', (string) count($migrationFiles));
} else {
    $failed++;
    result_line(false, 'migration file count', 'no migration files found');
}

echo "\nSummary\n";
echo str_repeat('-', 7) . "\n";
echo 'Passed: ' . $passed . PHP_EOL;
echo 'Failed: ' . $failed . PHP_EOL;

echo "\nMigrations detected\n";
echo str_repeat('-', 18) . "\n";
foreach ($migrationFiles as $file) {
    echo '- ' . $file . PHP_EOL;
}

exit($failed > 0 ? 1 : 0);
