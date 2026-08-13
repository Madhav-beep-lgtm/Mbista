<?php
declare(strict_types=1);

/**
 * Staff are the firm's own people; clients are not.
 *
 * A staff user belongs to whichever company created them, but the firm works as
 * one: the same accountant posts for the parent, for a subsidiary and for a
 * client's books in the same afternoon. So staff reach the whole group, and
 * company_memberships became a RESTRICTION rather than the only grant — the
 * moment an admin names companies for a staff member, those are the only ones
 * they reach.
 *
 * That is a widening of access, which is why it is asserted from both sides:
 * what staff gained, and what clients still cannot do.
 *
 *   php database/test_staff_company_reach.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

function scr_cleanup(): void
{
    foreach (db()->query("SELECT id FROM users WHERE email LIKE 'scr-%@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM company_memberships WHERE user_id = ' . (int) $u);
        db()->exec('DELETE FROM client_profiles WHERE user_id = ' . (int) $u);
        db()->exec('DELETE FROM users WHERE id = ' . (int) $u);
    }
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('SCRA','SCRB','SCRC')")->fetchAll(PDO::FETCH_COLUMN) as $c) {
        db()->exec('DELETE FROM company_memberships WHERE company_id = ' . (int) $c);
        db()->exec('DELETE FROM client_profiles WHERE books_company_id = ' . (int) $c . ' OR company_id = ' . (int) $c);
        db()->exec('DELETE FROM companies WHERE id = ' . (int) $c);
    }
}
scr_cleanup();

// Three companies in one group; the third is a client's books.
$mk = static function (string $code, string $name, int $isClient = 0): int {
    db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,:ic)')
        ->execute(['n' => $name, 'c' => $code, 'ic' => $isClient]);

    return (int) db()->lastInsertId();
};
$companyA = $mk('SCRA', 'Reach Co A');
$companyB = $mk('SCRB', 'Reach Co B');
$clientBooks = $mk('SCRC', 'Reach Client (Books)', 1);

$staffFree = create_user(['name' => 'Free Staff', 'email' => 'scr-free@test.local',
    'password' => 'Secret#12345', 'role' => 'staff', 'status' => 'active', 'company_id' => $companyA]);
$staffTied = create_user(['name' => 'Tied Staff', 'email' => 'scr-tied@test.local',
    'password' => 'Secret#12345', 'role' => 'staff', 'status' => 'active', 'company_id' => $companyA]);
$clientUser = create_user(['name' => 'A Client', 'email' => 'scr-client@test.local',
    'password' => 'Secret#12345', 'role' => 'customer', 'status' => 'active', 'company_id' => $clientBooks]);
db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active)
    VALUES (:u,:c,:b,:o,:k,1)')->execute(['u' => $clientUser, 'c' => $clientBooks, 'b' => $clientBooks,
    'o' => 'Reach Client', 'k' => 'SCRC-1']);

$reach = static function (int $userId): array {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    // The function caches per user for the request; a fresh process each call
    // is not available here, so the cache is what a real page load would see.
    return authorized_company_ids($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
};

// ---------------------------------------------------------------------------
echo "1. Unassigned staff reach their internal work company only\n";
// ---------------------------------------------------------------------------
$freeReach = $reach($staffFree);
ok(in_array($companyA, $freeReach, true), 'Their own company, as before');
ok(!in_array($companyB, $freeReach, true), 'Not an unrelated sister company');
ok(!in_array($clientBooks, $freeReach, true), "Not an unassigned client's books");

// ---------------------------------------------------------------------------
echo "\n2. Naming companies for a staff member narrows them to those\n";
// ---------------------------------------------------------------------------
db()->prepare('INSERT INTO company_memberships (user_id, company_id, access_level, is_active) VALUES (:u,:c,:a,1)')
    ->execute(['u' => $staffTied, 'c' => $companyB, 'a' => 'accountant']);
$tiedReach = $reach($staffTied);
ok(in_array($companyB, $tiedReach, true), 'The company the admin named');
ok(!in_array($clientBooks, $tiedReach, true), "And nothing else — the client's books are out");
ok(!in_array($companyA, $tiedReach, true),
    'Not even the company that created them: a restriction that leaked the home company would not be one');
ok(count($tiedReach) === 1, 'Exactly the one company named, and no more');

// ---------------------------------------------------------------------------
echo "\n2b. Explicit client assignment adds only that client's books\n";
// ---------------------------------------------------------------------------
$staffGranted = create_user(['name' => 'Granted Staff', 'email' => 'scr-granted@test.local',
    'password' => 'Secret#12345', 'role' => 'staff', 'status' => 'active', 'company_id' => $companyA]);
$clientProfileId = (int) db()->query("SELECT id FROM client_profiles WHERE client_code = 'SCRC-1'")->fetchColumn();
set_staff_accounting_clients($staffGranted, [$clientProfileId]);
$grantedReach = $reach($staffGranted);
ok(in_array($companyA, $grantedReach, true), 'The internal work company remains available');
ok(in_array($clientBooks, $grantedReach, true), "The assigned client's books are available");
ok(!in_array($companyB, $grantedReach, true), 'An unrelated company remains unavailable');

// ---------------------------------------------------------------------------
echo "\n3. A client is untouched by any of it\n";
// ---------------------------------------------------------------------------
$clientReach = $reach($clientUser);
ok($clientReach === [$clientBooks], 'A client login still reaches exactly its own books');
ok(!in_array($companyA, $clientReach, true) && !in_array($companyB, $clientReach, true),
    "And cannot see the firm's own companies");

// ---------------------------------------------------------------------------
echo "\n4. A suspended company is never reachable, restricted or not\n";
// ---------------------------------------------------------------------------
db()->exec('UPDATE companies SET is_active = 0 WHERE id = ' . $companyB);
// The per-request cache holds the earlier answer, so this is asked of a name
// that has not been looked up yet.
$staffFresh = create_user(['name' => 'Fresh Staff', 'email' => 'scr-fresh@test.local',
    'password' => 'Secret#12345', 'role' => 'staff', 'status' => 'active', 'company_id' => $companyA]);
ok(!in_array($companyB, $reach($staffFresh), true), 'A suspended company drops out of an unrestricted reach');

// ---------------------------------------------------------------------------
echo "\n5. The assignee list offers the same people it will accept\n";
// ---------------------------------------------------------------------------
db()->exec('UPDATE companies SET is_active = 1 WHERE id = ' . $companyB);
$idsIn = static fn (array $rows): array => array_map(static fn (array $r): int => (int) $r['id'], $rows);

$offeredInA = $idsIn(assignable_staff_users($companyA));
ok(in_array($staffFree, $offeredInA, true), 'An unrestricted staff member is offered by their own company');
ok(!in_array($staffTied, $offeredInA, true), 'A staff member tied to B is NOT offered by A');

$offeredInB = $idsIn(assignable_staff_users($companyB));
ok(in_array($staffFree, $offeredInB, true), 'And is offered by a company they never belonged to');
ok(in_array($staffTied, $offeredInB, true), 'While the tied one is offered by the company they were tied to');

$offeredInClient = $idsIn(assignable_staff_users($clientBooks));
ok(in_array($staffFree, $offeredInClient, true), "Unrestricted staff can be given a client's work");
ok(!in_array($clientUser, $offeredInClient, true), 'A client is never offered as staff');

scr_cleanup();
echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
