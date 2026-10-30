<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = $argv[1] ?? dirname(__DIR__);
$mode = $argv[2] ?? '--dry-run';
if (!in_array($mode, ['--dry-run', '--apply', '--list-unclassified', '--capacity'], true) && !preg_match('/^--inspect-group=\d+$/D', $mode)) { fwrite(STDERR, "Use APP_BASE --dry-run, --apply, --list-unclassified, --capacity or --inspect-group=ID\n"); exit(2); }
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
    // Every bucket at or near its limit, in one pass.
    //
    // A chart code is M.GG.SS.LLL.UU, so one group has room for 999 ledgers
    // and the ledgers a company never grouped at all share ONE bucket per
    // nature -- which is usually what fills first, because party ledgers go
    // in ungrouped by the hundred.
    if ($mode === '--capacity') {
        $rows = db()->query('SELECT l.company_id, c.name AS company, l.type,
                COALESCE(g.name, CONCAT("(ungrouped ", l.type, ")")) AS bucket,
                COALESCE(l.group_id, 0) AS group_id, COUNT(*) AS n
            FROM ledgers l
            LEFT JOIN ledger_groups g ON g.id = l.group_id
            LEFT JOIN companies c ON c.id = l.company_id
            WHERE COALESCE(l.parent_ledger_id, 0) = 0
            GROUP BY l.company_id, COALESCE(l.group_id, 0), l.type
            HAVING n > 800 ORDER BY n DESC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            printf("%-5s %-6s company %-4s %-26s group %-7s %s ledger(s)\n",
                $r['n'] > 999 ? 'FULL' : 'NEAR', '', $r['company_id'],
                substr((string) $r['bucket'], 0, 26), $r['group_id'] ?: '-', $r['n']);
        }
        echo count($rows) === 0
            ? "No bucket is within 200 of the 999-ledger limit.\n"
            : count($rows) . " bucket(s) at or near the limit. No data changed.\n";
        exit;
    }
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
    // Named on EVERY run, in both modes. A company that does not migrate is a
    // standing fact about the installation, not a one-off warning somebody saw
    // scroll past during a deploy three weeks ago.
    $announce = static function (array $skipped): void {
        foreach ($skipped as $cid => $why) {
            $stmt = db()->prepare('SELECT name FROM companies WHERE id = ?');
            $stmt->execute([$cid]);
            fwrite(STDERR, 'SKIPPED company ' . $cid . ' (' . ($stmt->fetchColumn() ?: '?') . '): ' . $why . "\n");
        }
    };

    $preview = coa_migrate_codes(false, null, $skipped);
    $announce($skipped);
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
        $applied = coa_migrate_codes(true, $preview, $skipped);
        $announce($skipped);
        echo count($applied) . " account codes updated; IDs and transaction links unchanged. Audit: {$path}\n";
    } else {
        echo count($preview) . " codes require migration. No data changed.\n";
        foreach (array_slice($preview, 0, 12) as $row) echo implode(' | ', $row) . "\n";
    }
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
