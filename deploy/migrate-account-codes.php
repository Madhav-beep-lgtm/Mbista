<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = $argv[1] ?? dirname(__DIR__);
$mode = $argv[2] ?? '--dry-run';
if (!in_array($mode, ['--dry-run', '--apply', '--list-unclassified'], true) && !preg_match('/^--inspect-group=\d+$/D', $mode)) { fwrite(STDERR, "Use APP_BASE --dry-run, --apply, --list-unclassified or --inspect-group=ID\n"); exit(2); }
require $base . '/app/bootstrap.php';
require_once $base . '/app/coa_codes.php';
try {
    if (str_starts_with($mode, '--inspect-group=')) {
        $id = (int)substr($mode, strlen('--inspect-group='));
        $stmt = db()->prepare('SELECT id,company_id,code,name,master_key,parent_group_id FROM ledger_groups WHERE id = ?');
        $stmt->execute([$id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) throw new RuntimeException('Group not found.');
        echo json_encode($group, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $stmt = db()->prepare('SELECT type,COUNT(*) AS ledger_count FROM ledgers WHERE group_id = ? AND company_id = ? GROUP BY type');
        $stmt->execute([$id,$group['company_id']]);
        echo "Ledger classifications: " . json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR) . "\nNo data changed.\n";
        exit;
    }
    // The migration refuses on the FIRST group it cannot number, so fixing a
    // chart one group per deploy run is the only thing that error supports.
    // This names every one of them, with what their ledgers say they are, so a
    // whole chart can be classified in one sitting.
    if ($mode === '--list-unclassified') {
        $rows = db()->query('SELECT g.id, g.company_id, c.name AS company, g.name,
                (SELECT GROUP_CONCAT(DISTINCT l.type ORDER BY l.type) FROM ledgers l
                   WHERE l.group_id = g.id AND l.company_id = g.company_id) AS natures,
                (SELECT COUNT(*) FROM ledgers l WHERE l.group_id = g.id) AS ledgers
            FROM ledger_groups g LEFT JOIN companies c ON c.id = g.company_id
            WHERE COALESCE(g.master_key, \'\') = \'\'
            ORDER BY g.company_id, g.name')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            printf("group %-7s company %-4s %-26s %-30s ledgers=%-4s natures=%s\n",
                $r['id'], $r['company_id'], substr((string) $r['company'], 0, 26),
                substr((string) $r['name'], 0, 30), $r['ledgers'], $r['natures'] ?? '(none)');
        }
        echo count($rows) . " group(s) have no accounting head. No data changed.\n";
        exit;
    }
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
