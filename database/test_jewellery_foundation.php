<?php
declare(strict_types=1);

/**
 * Jewellery Accounting — Phase 1 foundation.
 *
 * Proves activation gating, tenant isolation of every master, the gram-pivot
 * unit conversion, fine-weight and fine-rate arithmetic, cross-purity
 * valuation off a single quote, the daily-rate upsert and carry-forward rules,
 * and the ledger-mapping resolution ladder with its cross-tenant guard.
 *   php database/test_jewellery_foundation.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/jewellery_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $tol = 0.011): bool { return abs($a - $b) < $tol; }

function jw_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('JWTA','JWTB')")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        // Children first: rates and purities reference metals/units.
        foreach (['jewellery_daily_rates', 'inventory_ledger_mappings', 'jewellery_settings',
                  'jewellery_purities', 'jewellery_metals', 'jewellery_units'] as $t) {
            db()->exec("DELETE FROM `$t` WHERE company_id=$s");
        }
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$s)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email LIKE 'jwtest-%@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
jw_cleanup();

// ---------------------------------------------------------------------------
// Fixture: two independent jewellery clients, each with its own books company.
// ---------------------------------------------------------------------------
$mkClient = static function (string $code, string $org, string $email): array {
    db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n, :c, 1, 1)')
        ->execute(['n' => $org . ' (Books)', 'c' => $code]);
    $companyId = (int) db()->lastInsertId();
    $clientUserId = create_user(['name' => $org . ' Owner', 'email' => $email, 'password' => 'Secret#12345', 'role' => 'customer', 'status' => 'active', 'company_id' => $companyId]);
    db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active)
            VALUES (:uid, :cid, :books, :org, :code, 1)')
        ->execute(['uid' => $clientUserId, 'cid' => $companyId, 'books' => $companyId, 'org' => $org, 'code' => $code . '-C']);
    $clientId = (int) db()->lastInsertId();
    $fy = create_fiscal_year($companyId, $code . ' 2026/27', '2026-07-16', '2027-07-15', true);
    db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);

    return [$companyId, $clientId, (int) $fy['id'], $clientUserId];
};
[$cidA, $clientA, $fyA, $userA] = $mkClient('JWTA', 'Kantipur Jewellers', 'jwtest-a@test.local');
[$cidB, $clientB, $fyB, $userB] = $mkClient('JWTB', 'Himalaya Gold House', 'jwtest-b@test.local');
$_SESSION['company_id'] = $cidA;

echo "1. Activation gating\n";
ok((int) db()->query("SELECT jewellery_accounting_enabled FROM client_profiles WHERE id=$clientA")->fetchColumn() === 0,
    'New client defaults to jewellery DISABLED');
ok(!jewellery_enabled_for_company($cidA), 'Module gate closed while the flag is off');
db()->prepare('UPDATE client_profiles SET jewellery_accounting_enabled = 1 WHERE id IN (:a, :b)')->execute(['a' => $clientA, 'b' => $clientB]);
ok(jewellery_enabled_for_company($cidA), 'Gate opens after Super Admin activates the client');
ok(!jewellery_enabled_for_company(1), "The firm's own workspace (company 1, not client books) is NEVER eligible");
db()->prepare('UPDATE client_profiles SET is_active = 0 WHERE id = :a')->execute(['a' => $clientA]);
ok(!jewellery_enabled_for_company($cidA), 'Deactivating the client closes the gate even with the feature flag on');
db()->prepare('UPDATE client_profiles SET is_active = 1 WHERE id = :a')->execute(['a' => $clientA]);
ok(array_key_exists('jewellery', rbac_modules()), 'jewellery is a registered RBAC module');
ok(in_array('post', rbac_modules()['jewellery']['actions'], true), 'jewellery exposes a post action for the maker/checker split');

echo "\n2. Master seeding is automatic and idempotent\n";
$settingsA = jewellery_settings($cidA);
ok((int) $settingsA['masters_seeded'] === 1, 'First settings read seeds the masters');
$unitsA = jewellery_units_list($cidA);
$metalsA = jewellery_metals_list($cidA);
ok(count($unitsA) === 5, 'Five weight units seeded (gram, tola, aana, laal, carat), got ' . count($unitsA));
ok(count($metalsA) === 5, 'Five metals/stones seeded, got ' . count($metalsA));
$goldA = null;
foreach ($metalsA as $m) { if ($m['code'] === 'GOLD') { $goldA = $m; } }
ok($goldA !== null, 'Gold is seeded');
ok(count(jewellery_purities_list($cidA, (int) $goldA['id'])) === 4, 'Gold seeds 24K/22K/18K/14K');
$before = count($unitsA);
jewellery_seed_masters($cidA);
jewellery_seed_masters($cidA);
ok(count(jewellery_units_list($cidA)) === $before, 'Re-seeding creates no duplicates');
// A company that tuned a seeded master must keep its edit through a re-seed.
db()->prepare("UPDATE jewellery_units SET grams = 11.5 WHERE company_id = :cid AND code = 'TOLA'")->execute(['cid' => $cidA]);
jewellery_seed_masters($cidA);
$tolaCheck = db()->prepare("SELECT grams FROM jewellery_units WHERE company_id = :cid AND code = 'TOLA'");
$tolaCheck->execute(['cid' => $cidA]);
ok(near((float) $tolaCheck->fetchColumn(), 11.5), 'Re-seeding never overwrites a tuned conversion factor');
db()->prepare("UPDATE jewellery_units SET grams = 11.6638 WHERE company_id = :cid AND code = 'TOLA'")->execute(['cid' => $cidA]);

echo "\n3. Tenant isolation of masters\n";
jewellery_settings($cidB);
$unitAId = (int) $unitsA[0]['id'];
ok(jewellery_unit($cidA, $unitAId) !== null, "Company A can read its own unit");
ok(jewellery_unit($cidB, $unitAId) === null, "Company B cannot read company A's unit");
ok(jewellery_metal($cidB, (int) $goldA['id']) === null, "Company B cannot read company A's metal");
$goldB = null;
foreach (jewellery_metals_list($cidB) as $m) { if ($m['code'] === 'GOLD') { $goldB = $m; } }
ok($goldB !== null && (int) $goldB['id'] !== (int) $goldA['id'], 'Each company gets its OWN gold row, not a shared one');

echo "\n4. Gram-pivot weight conversion\n";
$unitBy = static function (int $companyId, string $code): array {
    foreach (jewellery_units_list($companyId) as $u) { if ($u['code'] === $code) { return $u; } }
    throw new RuntimeException('missing unit ' . $code);
};
$gram = $unitBy($cidA, 'GM');
$tola = $unitBy($cidA, 'TOLA');
$aana = $unitBy($cidA, 'AANA');
$laal = $unitBy($cidA, 'LAAL');
ok(near(jw_to_grams(1.0, $tola), 11.6638, 0.0001), '1 tola = 11.6638 g');
ok(near(jw_convert_weight(11.6638, $gram, $tola), 1.0, 0.0001), '11.6638 g converts back to 1 tola');
ok(near(jw_convert_weight(1.0, $tola, $aana), 16.0, 0.001), '1 tola = 16 aana');
ok(near(jw_convert_weight(1.0, $aana, $laal), 4.0, 0.001), '1 aana = 4 laal');
ok(near(jw_convert_weight(1.0, $tola, $laal), 64.0, 0.01), '1 tola = 64 laal (round trip through grams)');
// A zero factor would divide by zero; the helper must fall back, not crash.
ok(jw_convert_weight(5.0, $tola, ['grams' => 0]) > 0, 'A zero target factor falls back instead of dividing by zero');

echo "\n5. Fine weight and fine rate\n";
ok(near(jw_fine_weight(10.0, 916.0), 9.16), '10 tola of 22K (916) = 9.16 tola fine');
ok(near(jw_fine_weight(10.0, 999.9), 9.999), '10 tola of 24K (999.9) = 9.999 tola fine');
ok(near(jw_gross_from_fine(9.16, 916.0), 10.0), 'Inverse: 9.16 fine at 916 needs 10 gross');
ok(near(jw_fine_rate(150000.0, 999.9), 150015.0015, 0.01), 'A 24K quote of 150,000 is 150,015.00 per tola of PURE gold');
ok(near(jw_fine_rate(0.0, 0.0), 0.0), 'A zero fineness yields a zero fine rate rather than dividing by zero');

echo "\n6. Daily rate save, upsert and lookup\n";
$p24 = null; $p22 = null; $p18 = null;
foreach (jewellery_purities_list($cidA, (int) $goldA['id']) as $p) {
    if ($p['code'] === '24K') { $p24 = $p; }
    if ($p['code'] === '22K') { $p22 = $p; }
    if ($p['code'] === '18K') { $p18 = $p; }
}
$rateId = jewellery_save_rate($cidA, [
    'rate_date' => '2026-08-01', 'metal_id' => (int) $goldA['id'], 'purity_id' => (int) $p24['id'],
    'unit_id' => (int) $tola['id'], 'rate_type' => 'market', 'rate' => 150000,
], $userA);
ok($rateId > 0, 'Rate saved');
$again = jewellery_save_rate($cidA, [
    'rate_date' => '2026-08-01', 'metal_id' => (int) $goldA['id'], 'purity_id' => (int) $p24['id'],
    'unit_id' => (int) $tola['id'], 'rate_type' => 'market', 'rate' => 152000,
], $userA);
ok($again === $rateId, 'Re-saving the same date/metal/purity/type CORRECTS the row rather than duplicating it');
$countStmt = db()->prepare("SELECT COUNT(*) FROM jewellery_daily_rates WHERE company_id = :cid AND rate_date = '2026-08-01'");
$countStmt->execute(['cid' => $cidA]);
ok((int) $countStmt->fetchColumn() === 1, 'Exactly one row survives the correction');
$fetched = jewellery_rate_on($cidA, (int) $goldA['id'], (int) $p24['id'], '2026-08-01');
ok(near((float) $fetched['rate'], 152000.0), 'The corrected rate is what reads back');

// Cross-tenant guard: company B's purity must never attach to company A's quote.
$crossTenantRejected = false;
$p24B = null;
foreach (jewellery_purities_list($cidB, (int) $goldB['id']) as $p) { if ($p['code'] === '24K') { $p24B = $p; } }
try {
    jewellery_save_rate($cidA, [
        'rate_date' => '2026-08-02', 'metal_id' => (int) $goldA['id'], 'purity_id' => (int) $p24B['id'],
        'unit_id' => (int) $tola['id'], 'rate_type' => 'market', 'rate' => 1,
    ], $userA);
} catch (Throwable $e) { $crossTenantRejected = true; }
ok($crossTenantRejected, "A purity belonging to another tenant is REJECTED on the rate form");

// A purity of the wrong metal (same tenant) is rejected too.
$wrongMetalRejected = false;
$silverA = null;
foreach ($metalsA as $m) { if ($m['code'] === 'SILVER') { $silverA = $m; } }
try {
    jewellery_save_rate($cidA, [
        'rate_date' => '2026-08-02', 'metal_id' => (int) $silverA['id'], 'purity_id' => (int) $p24['id'],
        'unit_id' => (int) $tola['id'], 'rate_type' => 'market', 'rate' => 1,
    ], $userA);
} catch (Throwable $e) { $wrongMetalRejected = true; }
ok($wrongMetalRejected, 'A purity that does not belong to the chosen metal is rejected');

$negativeRejected = false;
try {
    jewellery_save_rate($cidA, [
        'rate_date' => '2026-08-02', 'metal_id' => (int) $goldA['id'], 'purity_id' => (int) $p24['id'],
        'unit_id' => (int) $tola['id'], 'rate_type' => 'market', 'rate' => -5,
    ], $userA);
} catch (Throwable $e) { $negativeRejected = true; }
ok($negativeRejected, 'A negative rate is rejected');

echo "\n7. Rate carry-forward vs exact-date modes\n";
$carried = jewellery_rate_on($cidA, (int) $goldA['id'], (int) $p24['id'], '2026-08-05');
ok($carried !== null && (bool) $carried['rate_is_carried'] === true, 'A missing day carries the last known rate forward');
ok(near((float) $carried['rate'], 152000.0), 'The carried rate is the 1 Aug quote');
$backwards = jewellery_rate_on($cidA, (int) $goldA['id'], (int) $p24['id'], '2026-07-20');
ok($backwards === null, 'A FUTURE quote never prices an earlier date');
jewellery_save_settings($cidA, ['rate_source' => 'manual'], $userA);
$manual = jewellery_rate_on($cidA, (int) $goldA['id'], (int) $p24['id'], '2026-08-05');
ok($manual === null, 'In manual mode a missing exact-date rate is a visible gap, not a stale price');
jewellery_save_settings($cidA, ['rate_source' => 'last_known'], $userA);

echo "\n8. Cross-purity valuation off a single quote\n";
// 10 tola of 22K, priced from the 24K (999.9) quote of 152,000/tola.
//   fine       = 10 x 916/1000            = 9.16 tola pure
//   fine_rate  = 152000 x 1000/999.9      = 152,015.20 per tola pure
//   amount     = 9.16 x 152015.20         = 1,392,459.24
$val = jewellery_metal_value($cidA, (int) $goldA['id'], (int) $p22['id'], 10.0, (int) $tola['id'], '2026-08-01');
ok($val['ok'], '22K valuation succeeds against a 24K-only rate table');
ok(near($val['fine_qty'], 9.16), 'Fine weight is 9.16 tola');
ok(near($val['fine_rate'], 152015.2015, 0.02), 'Fine rate is 152,015.20 per tola of pure gold');
ok(near($val['amount'], 1392459.25, 0.5), 'Amount is ~1,392,459 — got ' . number_format($val['amount'], 2));
// The same metal valued at 24K must come out higher than at 22K for equal gross.
$val24 = jewellery_metal_value($cidA, (int) $goldA['id'], (int) $p24['id'], 10.0, (int) $tola['id'], '2026-08-01');
ok($val24['amount'] > $val['amount'], 'Equal gross weight is worth MORE at 24K than at 22K');
ok(near($val24['amount'], 1520000.0, 1.0), '10 tola of 24K off its own quote is exactly 10 x 152,000');
// Unit independence: 116.638 g is 10 tola, so it must value identically.
$valGram = jewellery_metal_value($cidA, (int) $goldA['id'], (int) $p22['id'], 116.638, (int) $gram['id'], '2026-08-01');
ok(near($valGram['amount'], $val['amount'], 1.0), 'The same weight expressed in grams values identically');
// An 18K item off the same quote scales linearly with fineness.
$val18 = jewellery_metal_value($cidA, (int) $goldA['id'], (int) $p18['id'], 10.0, (int) $tola['id'], '2026-08-01');
ok(near($val18['amount'] / $val['amount'], 750.0 / 916.0, 0.001), '18K:22K value ratio equals their fineness ratio');

echo "\n9. Valuation failure modes are visible, never silent\n";
$noRate = jewellery_metal_value($cidB, (int) $goldB['id'], (int) $p24B['id'], 10.0, (int) $unitBy($cidB, 'TOLA')['id'], '2026-08-01');
ok(!$noRate['ok'] && $noRate['amount'] === 0.0, 'A company with no rates gets ok=false and amount 0, not a guess');
ok(str_contains($noRate['error'], 'rate'), 'The gap is reported with an explanatory error');
$badUnit = jewellery_metal_value($cidA, (int) $goldA['id'], (int) $p22['id'], 10.0, 0, '2026-08-01');
ok(!$badUnit['ok'], 'An unknown unit fails cleanly');
$badPurity = jewellery_metal_value($cidA, (int) $goldA['id'], 0, 10.0, (int) $tola['id'], '2026-08-01');
ok(!$badPurity['ok'], 'An unknown purity fails cleanly');

echo "\n10. Rate type fallback ladder\n";
jewellery_save_rate($cidA, [
    'rate_date' => '2026-08-01', 'metal_id' => (int) $goldA['id'], 'purity_id' => (int) $p24['id'],
    'unit_id' => (int) $tola['id'], 'rate_type' => 'sale', 'rate' => 153000,
], $userA);
$sale = jewellery_effective_rate($cidA, (int) $goldA['id'], (int) $p24['id'], '2026-08-01', 'sale');
ok(near((float) $sale['rate'], 153000.0) && $sale['rate_type_used'] === 'sale', 'A sale quote is used when one exists');
$purchase = jewellery_effective_rate($cidA, (int) $goldA['id'], (int) $p24['id'], '2026-08-01', 'purchase');
ok($purchase !== null && $purchase['rate_type_used'] === 'market', 'With no purchase quote the market rate is used');
ok(near((float) $purchase['rate'], 152000.0), 'The fallback carries the market value, not the sale value');

echo "\n11. Ledger mapping ladder and cross-tenant guard\n";
// Two ledgers per company, created through the same path the app uses.
$mkLedger = static function (int $companyId, string $code, string $name): int {
    db()->prepare('INSERT INTO ledger_groups (company_id, name, code, master_key) VALUES (:cid, :n, :c, :m)')
        ->execute(['cid' => $companyId, 'n' => 'JW ' . $name, 'c' => 'JWG' . $code, 'm' => 'assets']);
    $groupId = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO ledgers (company_id, group_id, name, code) VALUES (:cid, :g, :n, :c)')
        ->execute(['cid' => $companyId, 'g' => $groupId, 'n' => $name, 'c' => $code]);

    return (int) db()->lastInsertId();
};
$ledgerA = $mkLedger($cidA, 'JWSTK', 'Jewellery Stock');
$ledgerB = $mkLedger($cidB, 'JWSTK', 'Jewellery Stock B');
ok(jewellery_resolve_mapping($cidA, 'stock_metal') === null, 'An unmapped purpose resolves to null — nothing is guessed');
ok(jewellery_missing_mappings($cidA, ['stock_metal', 'cogs']) === ['stock_metal', 'cogs'], 'Missing mappings are reported as a gap list');
jewellery_save_mapping($cidA, 'stock_metal', $ledgerA, $userA);
$resolved = jewellery_resolve_mapping($cidA, 'stock_metal');
ok($resolved !== null && (int) $resolved['id'] === $ledgerA, 'A saved mapping resolves to its ledger');
ok($resolved['mapping_source'] === 'global', 'It resolves at the global scope');
ok(jewellery_resolve_mapping($cidB, 'stock_metal') === null, "Company A's mapping is invisible to company B");
$foreignRejected = false;
try { jewellery_save_mapping($cidA, 'stock_metal', $ledgerB, $userA); } catch (Throwable $e) { $foreignRejected = true; }
ok($foreignRejected, "Mapping another tenant's ledger into these books is REJECTED");
$unknownRejected = false;
try { jewellery_save_mapping($cidA, 'not_a_real_purpose', $ledgerA, $userA); } catch (Throwable $e) { $unknownRejected = true; }
ok($unknownRejected, 'An unknown posting purpose is rejected');
jewellery_save_mapping($cidA, 'stock_metal', 0, $userA);
ok(jewellery_resolve_mapping($cidA, 'stock_metal') === null, 'Saving ledger 0 clears the mapping');

echo "\n11b. Mappings live in the SHARED table, not a jewellery copy\n";
ok(!table_exists('jewellery_ledger_mappings'),
    'There is no separate jewellery mapping table — the duplicate store is gone');
// A jewellery purpose that is really a core purpose must resolve through the
// core name, so mapping it on either screen answers for both.
jewellery_save_mapping($cidA, 'cogs', $ledgerA, $userA);
$sharedRow = db()->prepare("SELECT purpose FROM inventory_ledger_mappings
    WHERE company_id = :cid AND scope = 'global' AND ledger_id = :lid");
$sharedRow->execute(['cid' => $cidA, 'lid' => $ledgerA]);
ok((string) ($sharedRow->fetchColumn() ?: '') === 'cogs',
    'A jewellery mapping is written into inventory_ledger_mappings');
ok(jw_canonical_purpose('vat_output') === 'tax_output', "Jewellery 'VAT payable' is an alias of the core tax_output");
ok(jw_canonical_purpose('stock_finished') === 'finished_goods', "'Finished ornament stock' is an alias of core finished_goods");
ok(jw_canonical_purpose('making_expense') === 'making_expense', 'A jewellery-only purpose keeps its own name');
// The decisive test: a ledger mapped the CORE way is already mapped for
// jewellery, which is the whole point of sharing the table.
jewellery_save_mapping($cidA, 'cogs', 0, $userA);
db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, purpose, ledger_id)
    VALUES (:cid, 'global', 'tax_output', :lid)")->execute(['cid' => $cidA, 'lid' => $ledgerA]);
$viaCore = jewellery_resolve_mapping($cidA, 'vat_output');
ok($viaCore !== null && (int) $viaCore['id'] === $ledgerA,
    'A ledger mapped on the INVENTORY screen resolves for jewellery too — one answer, not two');
ok(array_key_exists('vat_output', jewellery_mappings_by_purpose($cidA)),
    'And it reads back under the jewellery name on the jewellery settings screen');

echo "\n12. Settings persistence\n";
jewellery_save_settings($cidA, ['vat_rate' => 13.00, 'default_vat_base' => 'making_only', 'auto_post' => 0], $userA);
$reread = jewellery_settings($cidA);
ok(near((float) $reread['vat_rate'], 13.0), 'VAT rate persists');
ok((string) $reread['default_vat_base'] === 'making_only', 'Default VAT base persists');
ok((int) $reread['auto_post'] === 0, 'Auto-post flag persists');
$settingsB = jewellery_settings($cidB);
ok((string) $settingsB['default_vat_base'] === 'full_value', "Company B's settings are untouched by company A's edit");

echo "\n13. Phase 1 posts NOTHING to the ledger\n";
// The foundation is masters only: no voucher may exist for either company.
$vCount = db()->prepare('SELECT COUNT(*) FROM vouchers WHERE company_id IN (:a, :b)');
$vCount->execute(['a' => $cidA, 'b' => $cidB]);
ok((int) $vCount->fetchColumn() === 0, 'No vouchers were created by any Phase 1 operation');

jw_cleanup();
echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail === 0 ? 0 : 1);
