<?php
// Groups the code meant to file under a head, and MySQL filed under nothing.
//
// `purchases` became a head of its own in migration 132. Before that ALTER
// ran, inventory_mapping_autocreate() was already calling
//
//     jw_ledger_group_id($companyId, 'Purchase Accounts', 'purchases')
//
// and `purchases` was not yet a member of the master_key ENUM. Outside strict
// mode MySQL does not refuse an invalid ENUM value; it stores the empty
// string. So the group was created with no head at all — and the lookup on
// the next run searched for master_key = 'purchases', did not find the ''
// row, and created ANOTHER one. One company has four.
//
// An unclassified group cannot be numbered, which is why the account-code
// migration refuses the whole installation until these are cleared.
//
// This does not guess. It only restores the value the calling code passed:
// a group is repaired when its name is one of a master's `suggested_groups`,
// which is where that name came from. Anything else is listed and left alone,
// because what head a hand-made group belongs under is its owner's decision.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$base = $argv[1] ?? dirname(__DIR__);
$apply = in_array('--apply', $argv, true);
require $base . '/app/bootstrap.php';

// --set=ID:master files ONE group by hand: the answer for a group a person
// made, which this script will not guess at. It still refuses a head that is
// not a head, and it still will not touch a group that already has one.
foreach ($argv as $arg) {
    if (!preg_match('/^--set=(\d+):([a-z_]+)$/D', $arg, $m)) { continue; }
    [$id, $master] = [(int) $m[1], $m[2]];
    if (!isset(LEDGER_MASTERS[$master])) {
        fwrite(STDERR, 'Unknown head "' . $master . '". One of: ' . implode(', ', array_keys(LEDGER_MASTERS)) . "\n");
        exit(2);
    }
    $stmt = db()->prepare('SELECT company_id, name, master_key FROM ledger_groups WHERE id = ?');
    $stmt->execute([$id]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) { fwrite(STDERR, "Group {$id} not found.\n"); exit(1); }
    if ((string) $group['master_key'] !== '') {
        fwrite(STDERR, "Group {$id} \"{$group['name']}\" is already filed under {$group['master_key']}. Nothing changed.\n");
        exit(1);
    }
    printf("%-6s group %-6s company %-4s %-26s -> %s\n", $apply ? 'SET' : 'WOULD',
        $id, $group['company_id'], substr((string) $group['name'], 0, 26), $master);
    if ($apply) {
        db()->prepare('UPDATE ledger_groups SET master_key = :mk WHERE id = :id')->execute(['mk' => $master, 'id' => $id]);
        echo "Done.\n";
    } else {
        echo "Re-run with --apply to write.\n";
    }
    exit;
}

// name (lower-cased) => master key, taken from LEDGER_MASTERS itself.
$known = [];
foreach (ledger_masters() as $masterKey => $meta) {
    foreach ($meta['suggested_groups'] ?? [] as $suggested) {
        $known[mb_strtolower($suggested)] = $masterKey;
    }
}

$rows = db()->query('SELECT g.id, g.company_id, c.name AS company, g.name,
        (SELECT COUNT(*) FROM ledgers l WHERE l.group_id = g.id) AS ledgers
    FROM ledger_groups g LEFT JOIN companies c ON c.id = g.company_id
    WHERE COALESCE(g.master_key, \'\') = \'\'
    ORDER BY g.company_id, g.id')->fetchAll(PDO::FETCH_ASSOC);

$fix = db()->prepare('UPDATE ledger_groups SET master_key = :mk WHERE id = :id');
$repaired = 0;
$left = [];
foreach ($rows as $row) {
    $master = $known[mb_strtolower((string) $row['name'])] ?? null;
    if ($master === null) {
        $left[] = $row;
        continue;
    }
    printf("%-6s group %-6s company %-4s %-26s -> %s\n",
        $apply ? 'SET' : 'WOULD', $row['id'], $row['company_id'],
        substr((string) $row['name'], 0, 26), $master);
    if ($apply) { $fix->execute(['mk' => $master, 'id' => $row['id']]); }
    $repaired++;
}

foreach ($left as $row) {
    printf("LEAVE  group %-6s company %-4s %-26s (%s, %s ledger(s)) — somebody made this one; it needs a head choosing.\n",
        $row['id'], $row['company_id'], substr((string) $row['name'], 0, 26),
        substr((string) $row['company'], 0, 24), $row['ledgers']);
}

// The duplicates are a symptom, not a second fault: once the first group
// carries its head again the lookup finds it and stops making more. Merging
// what is already there moves posted ledgers between groups, so it is said
// rather than done.
$touched = [];
foreach (array_merge($rows) as $row) { $touched[$row['company_id'] . "\0" . $row['name']] = true; }
$dupes = db()->query('SELECT company_id, name, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids
    FROM ledger_groups GROUP BY company_id, name HAVING n > 1')->fetchAll(PDO::FETCH_ASSOC);
foreach ($dupes as $dupe) {
    if (!isset($touched[$dupe['company_id'] . "\0" . $dupe['name']])) { continue; }
    printf("NOTE   company %-4s has %s groups named \"%s\" (IDs %s). Merge or rename them when convenient.\n",
        $dupe['company_id'], $dupe['n'], $dupe['name'], $dupe['ids']);
}

printf("\n%d group(s) %s, %d left for somebody to classify.%s\n",
    $repaired, $apply ? 'repaired' : 'would be repaired', count($left),
    $apply ? '' : ' Re-run with --apply to write.');
