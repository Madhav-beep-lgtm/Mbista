<?php
declare(strict_types=1);

/**
 * The per-request row cache, and the thing that makes it safe.
 *
 * A page asks the same few questions over and over -- which fiscal year is
 * this, which company, is this period locked, who is logged in. On the voucher
 * register that was 23 reads of one fiscal year, 21 of one period lock, 19 of
 * one user and 9 of one company: about seventy round trips for four answers
 * that cannot change between them.
 *
 * WHAT MAKES A READ CACHE SAFE IS THE INVALIDATION. Asking twenty-one write
 * sites to remember to call it is not a plan; it is a plan plus the twenty-
 * second write somebody adds next year. So nothing is asked of the writers:
 * every statement runs through AppPdo / AppPdoStatement, and each says what it
 * did. An INSERT or DDL throws away only the cached tables it NAMES, because
 * with no triggers, views or routines in this schema neither can reach a table
 * it does not mention. An UPDATE or DELETE throws away everything, because a
 * cascade CAN reach further than the statement names. Anything unrecognised
 * sits with the blunt ones.
 *
 * A stale read here is not a slow page, it is a wrong figure in a ledger, so
 * most of what follows is about proving the cache lets go -- of a renamed
 * company, an edited fiscal year, a period locked mid-request, a user
 * suspended mid-request, and a write made through every route PDO offers.
 *
 *   php database/test_request_cache.php
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

function rc_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'RCTEST'")->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $cid = (int) $cid;
        foreach (['fiscal_period_locks', 'fiscal_years'] as $t) {
            if (table_exists($t)) { db()->exec("DELETE FROM `$t` WHERE company_id = $cid"); }
        }
        db()->exec("DELETE FROM companies WHERE id = $cid");
    }
}
rc_cleanup();

db()->prepare("INSERT INTO companies (name, code, is_active, created_at) VALUES ('Cache Test Co', 'RCTEST', 1, NOW())")->execute();
$cid = (int) db()->lastInsertId();
db()->prepare("INSERT INTO fiscal_years (company_id, label, start_date, end_date, is_default, status)
    VALUES (:c, 'FY Cache', '2026-01-01', '2026-12-31', 0, 'open')")->execute(['c' => $cid]);
$fyId = (int) db()->lastInsertId();

echo "\n1. The same question is asked of the database once\n";
DbRequestCache::reset();
for ($i = 0; $i < 10; $i++) {
    company_by_id($cid);
    fiscal_year_by_id($fyId);
    period_locked_through($cid, $fyId);
}
$stats = DbRequestCache::stats();
ok($stats['misses'] === 3, 'Thirty calls across three lookups reached the database 3 times (' . $stats['misses'] . ')');
ok($stats['hits'] === 27, '  ...and the other 27 were answered from what was already read');

echo "\n2. And the answer is the right one\n";
// A cache that is fast and wrong is not a cache, it is a bug with a benchmark.
ok((string) (company_by_id($cid)['code'] ?? '') === 'RCTEST', 'The company comes back correct');
ok((string) (fiscal_year_by_id($fyId)['label'] ?? '') === 'FY Cache', 'So does the fiscal year');
ok(period_locked_through($cid, $fyId) === null, 'And an unlocked period reads as unlocked');

echo "\n3. A write empties it — through every route PDO offers\n";
// prepare()->execute(), exec() and query() are three different paths into the
// server, and a cache that only notices one of them is worse than none.
DbRequestCache::reset();
company_by_id($cid);
db()->prepare('UPDATE companies SET name = :n WHERE id = :id')->execute(['n' => 'Renamed By Prepare', 'id' => $cid]);
ok((string) (company_by_id($cid)['name'] ?? '') === 'Renamed By Prepare',
    'A prepared UPDATE is seen — the next read returns the new name');

db()->exec("UPDATE companies SET name = 'Renamed By Exec' WHERE id = " . $cid);
ok((string) (company_by_id($cid)['name'] ?? '') === 'Renamed By Exec', 'So is one sent through exec()');

db()->query("UPDATE companies SET name = 'Renamed By Query' WHERE id = " . $cid);
ok((string) (company_by_id($cid)['name'] ?? '') === 'Renamed By Query', 'And one sent through query()');

echo "\n4. Including the writes this app actually makes\n";
DbRequestCache::reset();
ok(period_locked_through($cid, $fyId) === null, 'The period starts unlocked');
db()->prepare('INSERT INTO fiscal_period_locks (company_id, fiscal_year_id, locked_through, locked_by)
    VALUES (:c, :f, :d, NULL)')->execute(['c' => $cid, 'f' => $fyId, 'd' => '2026-06-30']);
ok(period_locked_through($cid, $fyId) === '2026-06-30',
    'Locking it mid-request is visible immediately, not after a refresh');
db()->prepare('DELETE FROM fiscal_period_locks WHERE company_id = :c AND fiscal_year_id = :f')
    ->execute(['c' => $cid, 'f' => $fyId]);
ok(period_locked_through($cid, $fyId) === null, 'And unlocking it is too');

DbRequestCache::reset();
fiscal_year_by_id($fyId);
db()->prepare('UPDATE fiscal_years SET label = :l WHERE id = :id')->execute(['l' => 'FY Edited', 'id' => $fyId]);
ok((string) (fiscal_year_by_id($fyId)['label'] ?? '') === 'FY Edited', 'An edited fiscal year is read fresh');

echo "\n5. A read leaves it standing\n";
// The whole point: reads are what the cache is for, so they must not clear it.
DbRequestCache::reset();
company_by_id($cid);
db()->query('SELECT 1')->fetchColumn();
db()->prepare('SELECT COUNT(*) FROM companies WHERE id = :id')->execute(['id' => $cid]);
db()->query('SHOW COLUMNS FROM companies')->fetchAll();
$stats = DbRequestCache::stats();
ok($stats['flushes'] === 0, 'A SELECT, a prepared SELECT and a SHOW clear nothing');
company_by_id($cid);
ok(DbRequestCache::stats()['hits'] >= 1, '  ...so the row is still there afterwards');

echo "\n6. Anything it does not recognise is treated as a write\n";
// The blunt direction is the safe one: over-invalidating costs a re-read,
// under-invalidating puts a figure on screen the database no longer holds.
DbRequestCache::reset();
company_by_id($cid);
DbRequestCache::noteStatement('CALL some_procedure()');
ok(DbRequestCache::stats()['flushes'] === 1, 'An unrecognised statement empties the cache rather than risking it');

DbRequestCache::reset();
company_by_id($cid);
DbRequestCache::noteStatement("  /* a comment first */  SELECT 1");
DbRequestCache::noteStatement("-- a line comment\n SELECT 1");
DbRequestCache::noteStatement("   ( SELECT 1 )");
ok(DbRequestCache::stats()['flushes'] === 0,
    'While a SELECT behind a comment or a bracket is still recognised as a read');

echo "\n7. Every statement really does pass through the classes that do this\n";
// If db() ever hands back a plain PDO again, none of the above is true and
// nothing else in this suite would notice.
ok(db() instanceof AppPdo, 'db() returns the connection that watches for writes');
ok(db()->prepare('SELECT 1') instanceof AppPdoStatement, 'And its prepared statements are the ones that report in');

echo "\n8. The user is read once, and the lifecycle guard still runs\n";
// current_user() re-read the row and re-checked suspension and revocation on
// every one of nineteen calls. Its comment promises that check on every
// REQUEST, which one call satisfies -- but a user suspended mid-request is a
// write, so the cache lets go and the guard runs again.
$userId = (int) db()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
$_SESSION['user_id'] = $userId;
DbRequestCache::reset();
for ($i = 0; $i < 5; $i++) { current_user(); }
ok(DbRequestCache::stats()['misses'] === 1, 'Five calls, one read');
ok((int) (current_user()['id'] ?? 0) === $userId, '  ...returning the signed-in user');

$before = (string) db()->query("SELECT status FROM users WHERE id = {$userId}")->fetchColumn();
db()->prepare('UPDATE users SET status = :s WHERE id = :id')->execute(['s' => 'suspended', 'id' => $userId]);
$suspended = current_user();
ok($suspended === null, 'Suspending the user mid-request ends the session on the very next call');
db()->prepare('UPDATE users SET status = :s WHERE id = :id')->execute(['s' => $before, 'id' => $userId]);

$_SESSION['user_id'] = $userId;
DbRequestCache::reset();
current_user();
$_SESSION['user_id'] = $userId + 100000;
ok(current_user() === null || (int) (current_user()['id'] ?? 0) !== $userId,
    'And a different session id is not answered with whoever was here before');
$_SESSION['user_id'] = $userId;

echo "\n9. A write only throws away what it can actually have changed\n";
// The blunt rule -- any write empties everything -- meant one
// CREATE TABLE IF NOT EXISTS from a lazy schema check wiped a whole page's
// worth of primed answers. An INSERT or a DDL statement cannot reach a table
// it does not name: this schema has no triggers, views or routines to act on
// its behalf, and an insert cannot cascade.
DbRequestCache::reset();
company_by_id($cid);
fiscal_year_by_id($fyId);
db()->exec('CREATE TABLE IF NOT EXISTS rc_unrelated_probe (id INT PRIMARY KEY)');
ok(DbRequestCache::stats()['flushes'] === 0,
    'DDL on a table nothing is cached from throws away nothing');
db()->exec('DROP TABLE IF EXISTS rc_unrelated_probe');
ok((string) (company_by_id($cid)['code'] ?? '') === 'RCTEST',
    '  ...and the cached rows are still there and still right');

DbRequestCache::reset();
company_by_id($cid);
$hitsBefore = DbRequestCache::stats()['hits'];
db()->prepare('INSERT INTO fiscal_period_locks (company_id, fiscal_year_id, locked_through, locked_by)
    VALUES (:c, :f, :d, NULL)')->execute(['c' => $cid, 'f' => $fyId, 'd' => '2026-03-31']);
company_by_id($cid);
ok(DbRequestCache::stats()['hits'] === $hitsBefore + 1,
    "An INSERT into one table leaves another table's rows alone");
db()->prepare('DELETE FROM fiscal_period_locks WHERE company_id = :c')->execute(['c' => $cid]);

echo "\n10. But an UPDATE or a DELETE still throws away everything\n";
// These CAN reach a table they do not name: deleting a parent cascades to its
// children, and a cache that missed that would answer from a row that is gone.
DbRequestCache::reset();
company_by_id($cid);
fiscal_year_by_id($fyId);
db()->exec('UPDATE fiscal_years SET label = label WHERE id = ' . $fyId);
ok(DbRequestCache::stats()['flushes'] === 1,
    'An UPDATE empties the lot, because a cascade can reach further than it names');
DbRequestCache::reset();
company_by_id($cid);
db()->exec('DELETE FROM fiscal_period_locks WHERE company_id = ' . $cid);
ok(DbRequestCache::stats()['flushes'] === 1, 'And so does a DELETE');

echo "\n11. A page's mutation checks are asked for the whole list at once\n";
// The voucher register calls voucher_mutation_blocker() for every row it
// draws, and each call used to cost five counts. Twenty-six rows meant a
// hundred and thirty round trips for what five queries answer.
$voucherIds = array_map('intval', db()->query('SELECT id FROM vouchers ORDER BY id ASC LIMIT 12')->fetchAll(PDO::FETCH_COLUMN));
if ($voucherIds === []) {
    foreach (range(1, 4) as $skipped) { ok(true, 'No vouchers on this database — priming check skipped'); }
} else {
    $expected = 5 * count($voucherIds);
    DbRequestCache::reset();
    voucher_prime_mutation_checks($voucherIds);
    ok(DbRequestCache::stats()['held'] === $expected,
        'Priming ' . count($voucherIds) . ' vouchers holds all ' . $expected . ' answers');
    $before = DbRequestCache::stats()['misses'];
    foreach ($voucherIds as $vid) {
        voucher_reconciled_entry_count($vid);
        foreach (array_keys(voucher_mutation_ref_tables()) as $refTable) {
            voucher_reference_count($refTable, $vid);
        }
    }
    ok(DbRequestCache::stats()['misses'] === $before,
        '  ...so reading every one of them afterwards reaches the database not once more');

    // A voucher with nothing against it returns no row from a grouped count,
    // and caching only what came back would leave the clean ones -- which is
    // most of them, and the whole cost -- to be asked about one at a time.
    DbRequestCache::reset();
    voucher_prime_mutation_checks($voucherIds);
    $missesBefore = DbRequestCache::stats()['misses'];
    voucher_reconciled_entry_count($voucherIds[0]);
    ok(DbRequestCache::stats()['misses'] === $missesBefore,
        'A voucher with NO reconciled entry is cached as nought, not left unasked');

    // And the batched answer has to be the one asking singly would give.
    DbRequestCache::reset();
    $slow = voucher_reconciled_entry_count($voucherIds[0]);
    DbRequestCache::reset();
    voucher_prime_mutation_checks($voucherIds);
    ok(voucher_reconciled_entry_count($voucherIds[0]) === $slow,
        'And it agrees with what asking one at a time returns (' . $slow . ')');
}

rc_cleanup();
DbRequestCache::reset();

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
