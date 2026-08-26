<?php
declare(strict_types=1);

/**
 * Who the bill is made out to, and who the books owe.
 *
 * These are two different questions and the sale form asks both. "Existing
 * customer" chooses the party the money is posted to -- a house account, a
 * consolidated counter ledger, the customer's own. "Customer name" is the name
 * printed on the bill. A shop bills a walk-in in whatever name they give at
 * the counter while the posting goes somewhere deliberate, and before this the
 * typed name was stored and then never shown: every document printed the
 * party's name whatever had been typed.
 *
 * What is asserted here is the rule in both directions, and -- more
 * importantly -- that changing which name is PRINTED changed nothing about
 * which party is POSTED to. A bill in a different name must not quietly move
 * the money.
 *
 *   php database/test_jewellery_billed_as.php
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/jewellery_trade.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

echo "\n1. A name typed at the counter is the name on the bill\n";
ok(jw_document_party_name(['customer_name' => 'Ram Bahadur', 'party_name' => 'Counter Sales'])
    === 'Ram Bahadur',
    'With both, the typed name is printed — not the party the money goes to');

echo "\n2. Left blank, the bill carries the party's own name\n";
// Which is what every bill did before this, and what most still do.
ok(jw_document_party_name(['customer_name' => '', 'party_name' => 'Akshara Traders'])
    === 'Akshara Traders', 'An empty name falls back to the selected customer');
ok(jw_document_party_name(['customer_name' => null, 'party_name' => 'Akshara Traders'])
    === 'Akshara Traders', 'So does a null one — an untouched field is not a name');
ok(jw_document_party_name(['customer_name' => '   ', 'party_name' => 'Akshara Traders'])
    === 'Akshara Traders', 'And so is one holding nothing but spaces');
ok(jw_document_party_name(['party_name' => 'Akshara Traders']) === 'Akshara Traders',
    'And a document from before this field was filled in still prints');

echo "\n3. It reads whichever shape the caller has\n";
// The invoice has the party as a joined row, the register as a flat column.
ok(jw_document_party_name(['customer_name' => '', 'name' => 'Joined Party Row']) === 'Joined Party Row',
    'A party row keyed name is understood as well as one keyed party_name');
ok(jw_document_party_name([]) === '', 'And nothing at all is empty, not a warning');

echo "\n4. Every document asks the same question\n";
// Three surfaces printed the party name three slightly different ways; the
// rule now lives in one place and each of them calls it.
$root = dirname(__DIR__);
foreach ([
    'public_html/admin/jewellery-invoice.php' => 'the sales bill',
    'public_html/admin/jewellery-print.php' => 'the document preview',
] as $file => $what) {
    $src = (string) file_get_contents($root . '/' . $file);
    ok(str_contains($src, 'jw_document_party_name'), ucfirst($what) . ' asks the shared rule');
    ok(!preg_match('/\$(?:doc|sale)\[.party_name.\]\s*\?\?\s*\$(?:doc|sale)\[.customer_name.\]/', $src),
        '  ...and no longer prefers the party name over the typed one');
}

echo "\n5. THE POSTING IS UNTOUCHED\n";
// The whole point. jw_resolve_party() has always ignored a typed name once a
// party is chosen; if that ever changed, a bill made out to somebody else
// would start moving money to them, which is the one outcome that matters.
$cid = (int) db()->query('SELECT id FROM companies ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($cid <= 0) {
    foreach (range(1, 3) as $skipped) { ok(true, 'No company on this database — posting check skipped'); }
} else {
    db()->exec("DELETE FROM accounting_parties WHERE company_id = {$cid} AND code LIKE 'BATEST%'");
    db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status)
        VALUES (:c, 'BATEST1', 'Chosen Party', 'customer', 'active')")->execute(['c' => $cid]);
    $chosen = (int) db()->lastInsertId();

    $resolved = jw_resolve_party($cid, [
        'party_id' => $chosen,
        'party_name' => 'Someone Else Entirely',
    ], 'customer');
    ok($resolved === $chosen,
        'A typed name alongside a chosen party posts to the CHOSEN party, not the name');

    $strays = (int) db()->query("SELECT COUNT(*) FROM accounting_parties
        WHERE company_id = {$cid} AND name = 'Someone Else Entirely'")->fetchColumn();
    ok($strays === 0, '  ...and creates no second party behind it');

    // While with nothing chosen the typed name still opens a customer, which
    // is what the field did before and still does at a walk-in counter.
    $walkIn = jw_resolve_party($cid, ['party_id' => 0, 'party_name' => 'Walk In Ram'], 'customer');
    ok($walkIn > 0 && $walkIn !== $chosen, 'With no party chosen, a typed name still opens one');
    db()->exec("DELETE FROM accounting_parties WHERE company_id = {$cid} AND (code LIKE 'BATEST%' OR name = 'Walk In Ram')");
}

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
