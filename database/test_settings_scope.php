<?php
declare(strict_types=1);

/**
 * A setting belongs to one company, or to the installation.
 *
 * `settings` was keyed on setting_key alone — one row for the whole
 * installation, 53 of them, shared by 19 companies. A client's PAN, VAT
 * number, bank account and invoice prefix were therefore everybody's: setting
 * one set it for every other set of books on the server, and nothing on any
 * screen said so.
 *
 * The same fault was found in `inventory_accounting`, where one switch turned
 * every client's books periodic at once.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';

accounting_module_repair_database();

$pass = 0;
$fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

function setscope_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('SETSCOPEA', 'SETSCOPEB')")->fetchAll(PDO::FETCH_COLUMN) as $old) {
        $old = (int) $old;
        db()->exec("DELETE FROM settings WHERE company_id = $old");
        db()->exec("DELETE FROM companies WHERE id = $old");
    }
    db()->exec("DELETE FROM settings WHERE setting_key LIKE 'setscope_%'");
}
setscope_cleanup();

db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Scope A', 'SETSCOPEA', 1)")->execute();
$coA = (int) db()->lastInsertId();
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Scope B', 'SETSCOPEB', 1)")->execute();
$coB = (int) db()->lastInsertId();

echo "\n1. The schema can hold one key twice\n";
ok(column_exists('settings', 'company_id'), 'settings carries a company_id (migration 136)');

// The old primary key forbade this outright. If the key was added beside the
// old one rather than replacing it, the second insert fails here.
$twice = true;
try {
    update_settings(['setscope_prefix' => 'AAA'], $coA);
    update_settings(['setscope_prefix' => 'BBB'], $coB);
} catch (Throwable $e) {
    $twice = false;
}
ok($twice, 'Two companies can hold the same setting key at once');

echo "\n2. Each company reads its own\n";
ok((string) setting('setscope_prefix', '', true, $coA) === 'AAA', 'Company A reads AAA');
ok((string) setting('setscope_prefix', '', true, $coB) === 'BBB', '  ...and company B reads BBB, in the same request');

echo "\n3. The installation default is what a company falls back to\n";
update_settings(['setscope_fallback' => 'HOUSE'], 0);
ok((string) setting('setscope_fallback', '', true, $coA) === 'HOUSE',
    'A company with no value of its own gets the installation default');
update_settings(['setscope_fallback' => 'MINE'], $coA);
ok((string) setting('setscope_fallback', '', true, $coA) === 'MINE',
    '  ...and its own value once it has one');
ok((string) setting('setscope_fallback', '', true, $coB) === 'HOUSE',
    '  ...while the company beside it still reads the default — this is the whole fault');
ok((string) setting('setscope_fallback', '', true, 0) === 'HOUSE',
    '  ...and the default itself is untouched');

// Clearing a company's value must hand it back, not leave a hole.
db()->prepare('DELETE FROM settings WHERE company_id = :c AND setting_key = :k')
    ->execute(['c' => $coA, 'k' => 'setscope_fallback']);
ok((string) setting('setscope_fallback', '', true, $coA) === 'HOUSE',
    'Clearing a company value returns it to the installation default');

echo "\n4. Nothing moves on the day the column appears\n";
// Every key that existed became an installation default, so a key nobody has
// overridden answers exactly as it did before — which is why none of the 105
// call sites had to change.
$houseKeys = (int) db()->query('SELECT COUNT(*) FROM settings WHERE company_id = 0')->fetchColumn();
ok($houseKeys > 0, 'The existing settings are the installation default (' . $houseKeys . ' keys)');
ok((string) setting('setscope_never_set', 'fallback-arg', true, $coA) === 'fallback-arg',
    'A key nobody has set still answers with the caller\'s own default');

echo "\n5. The company asked about, not the one in the session\n";
// A scheduled report, a CLI conversion, or one client's books opened from
// another's screen all run for a company that is not the session's. Reading
// the session there answers with the wrong client's VAT number.
$sessionWas = $_SESSION['company_id'] ?? null;
$_SESSION['company_id'] = $coB;
ok((string) setting('setscope_prefix', '', true, $coA) === 'AAA',
    'Asked about company A while the session holds company B, it answers for A');
ok((string) setting('setscope_prefix', '', true) === 'BBB',
    '  ...and with no company named it falls back to the session, as callers expect');
$_SESSION['company_id'] = $sessionWas;

echo "\n6. The writer defaults to the installation, as every old caller meant\n";
update_settings(['setscope_legacy' => 'HOUSE2']);
ok((int) db()->query("SELECT company_id FROM settings WHERE setting_key = 'setscope_legacy'")->fetchColumn() === 0,
    'update_settings() with no company writes the installation default');

setscope_cleanup();
setting('', '', true);

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
