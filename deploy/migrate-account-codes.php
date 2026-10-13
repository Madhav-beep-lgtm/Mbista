<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = $argv[1] ?? dirname(__DIR__);
$mode = $argv[2] ?? '--dry-run';
if (!in_array($mode, ['--dry-run', '--apply'], true)) { fwrite(STDERR, "Use APP_BASE --dry-run or APP_BASE --apply\n"); exit(2); }
require $base . '/app/bootstrap.php';
require_once $base . '/app/coa_codes.php';
try {
    $preview = coa_migrate_codes(false);
    if ($mode === '--apply' && $preview) {
        foreach (['ledger_groups', 'ledgers'] as $table) {
            if (!column_exists($table, 'legacy_code')) db()->exec("ALTER TABLE {$table} ADD COLUMN legacy_code VARCHAR(80) DEFAULT NULL");
        }
        // The mapping is outside public_html; keep it as a migration audit.
        $path = $base . '/coa-code-migration-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
        $handle = fopen($path, 'x');
        if (!$handle) throw new RuntimeException('Cannot create migration audit file.');
        $json = json_encode($preview, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (fwrite($handle, $json) !== strlen($json)) throw new RuntimeException('Cannot write migration audit file.');
        fclose($handle);
        chmod($path, 0600);
        $applied = coa_migrate_codes(true, $preview);
        echo count($applied) . " account codes updated; IDs and transaction links unchanged. Audit: {$path}\n";
    } else {
        echo count($preview) . " codes require migration. No data changed.\n";
        foreach (array_slice($preview, 0, 12) as $row) echo implode(' | ', $row) . "\n";
    }
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
