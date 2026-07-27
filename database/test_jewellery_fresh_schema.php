<?php
declare(strict_types=1);

/**
 * Proves the jewellery schema installs on a FRESH database, not just on the
 * developer's already-migrated one.
 *
 * This matters more than usual here: migrations 072 and 073 are applied in
 * production by accounting_repair_run_migration_file(), which reads the .sql
 * file and splits it on ';'. That splitter, the order the repair steps run in
 * (FKs must resolve), and the idempotency of a replay are all things a test on
 * an existing database silently skips.
 *
 * Creates a throwaway database, imports database/schema.sql, runs the real
 * repair layer twice, asserts the whole jewellery schema is present and
 * correct, then drops it.
 *   php database/test_jewellery_fresh_schema.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

$root = dirname(__DIR__);
$testDb = 'mbista_jewellery_fresh_test';

// Point the app at the throwaway database BEFORE bootstrap reads the env.
putenv('DB_NAME=' . $testDb);
$_ENV['DB_NAME'] = $testDb;
$_SERVER['DB_NAME'] = $testDb;

// Build the database with a server-level connection first.
require $root . '/app/config.php';
$dsn = 'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET;
$server = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `$testDb`");
$server->exec("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

function drop_test_db(string $testDb): void
{
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET;
        (new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))
            ->exec("DROP DATABASE IF EXISTS `$testDb`");
    } catch (Throwable $e) {
        echo "  WARN  could not drop $testDb: " . $e->getMessage() . "\n";
    }
}

// Nothing may reach stdout before bootstrap starts the session, or PHP warns
// that headers are already sent — so the first section is buffered and printed
// once the app is loaded.
ob_start();
echo "1. Fresh schema import\n";
$schema = (string) file_get_contents($root . '/database/schema.sql');
$fresh = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $testDb . ';charset=' . DB_CHARSET,
    DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    $fresh->exec($schema);
    while ($fresh->query('SELECT 1') === false) { break; }
} catch (Throwable $e) {
    echo '  FAIL  schema.sql import: ' . $e->getMessage() . "\n";
    drop_test_db($testDb);
    exit(1);
}
$baseTables = (int) $fresh->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$testDb'")->fetchColumn();
ok($baseTables > 40, "schema.sql imported ($baseTables tables)");
ok($baseTables > 0 && (int) $fresh->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$testDb' AND table_name='companies'")->fetchColumn() === 1,
    'The companies table exists, so FKs have something to point at');
unset($fresh);

echo "\n2. The real repair layer builds the rest\n";
$buffered = (string) ob_get_clean();
require $root . '/app/bootstrap.php';
require_once $root . '/app/accounting_module_repair.php';
echo $buffered;
ok(DB_NAME === $testDb, 'The app is pointed at the throwaway database, not the real one');

$errors = accounting_module_repair_database();
$jewelleryErrors = array_values(array_filter($errors, static fn ($e) => stripos((string) $e, 'jewellery') !== false));
ok($jewelleryErrors === [], 'No jewellery repair step reported an error'
    . ($jewelleryErrors === [] ? '' : ' — ' . implode(' | ', $jewelleryErrors)));
if ($errors !== [] && $jewelleryErrors === []) {
    // Pre-existing steps may complain on a bare schema; that is not this
    // module's business, but say so rather than hide it.
    echo '  NOTE  ' . count($errors) . " non-jewellery repair step(s) reported an error on a bare schema:\n";
    foreach (array_slice($errors, 0, 5) as $e) {
        echo '        ' . substr((string) $e, 0, 160) . "\n";
    }
}

echo "\n3. Every jewellery table was created\n";
$expected = [
    'jewellery_units', 'jewellery_metals', 'jewellery_purities', 'jewellery_settings',
    'jewellery_daily_rates', 'inventory_ledger_mappings', 'jewellery_item_profiles', 'jewellery_stock_txns',
    'jewellery_purchases', 'jewellery_purchase_lines', 'jewellery_sales',
    'jewellery_sale_lines', 'jewellery_sale_exchanges', 'jewellery_bills', 'jewellery_settlements',
    'jewellery_settlement_allocations', 'jewellery_karigars', 'jewellery_orders',
    'jewellery_order_assignments', 'jewellery_order_receipts', 'jewellery_refinery_jobs',
];
$missing = [];
foreach ($expected as $table) {
    if (!accounting_repair_table_exists($table)) {
        $missing[] = $table;
    }
}
ok($missing === [], 'All ' . count($expected) . ' jewellery tables exist'
    . ($missing === [] ? '' : ' — missing: ' . implode(', ', $missing)));
ok(accounting_repair_column_exists('client_profiles', 'jewellery_accounting_enabled'),
    'The client activation flag was added to client_profiles');

echo "\n4. Foreign keys actually resolved\n";
// The repair steps must run in an order where every referenced table already
// exists, or MySQL silently ends up with no constraint.
$fkCount = (int) db()->query("SELECT COUNT(*) FROM information_schema.referential_constraints
    WHERE constraint_schema = '$testDb' AND table_name LIKE 'jewellery_%'")->fetchColumn();
ok($fkCount >= 50, "Jewellery foreign keys are in place ($fkCount)");
ok(!accounting_repair_table_exists('jewellery_items'),
    'The separate jewellery item table is GONE — there is one shared item master');
ok(!accounting_repair_table_exists('jewellery_ledger_mappings'),
    'The separate jewellery mapping table is GONE — there is one shared mapping store');
$sharedFk = (int) db()->query("SELECT COUNT(*) FROM information_schema.referential_constraints
    WHERE constraint_schema = '$testDb' AND table_name LIKE 'jewellery_%' AND referenced_table_name = 'inventory_items'")->fetchColumn();
ok($sharedFk >= 11, "Every jewellery item reference points at inventory_items ($sharedFk)");
$saleFk = (int) db()->query("SELECT COUNT(*) FROM information_schema.referential_constraints
    WHERE constraint_schema = '$testDb' AND constraint_name = 'fk_jw_orders_sale'")->fetchColumn();
ok($saleFk === 1, 'Orders reference sales, so migration 073 correctly ran after 072');

echo "\n5. Column shapes survived the file-replay splitter\n";
$colType = static function (string $table, string $column) use ($testDb): string {
    $stmt = db()->prepare('SELECT COLUMN_TYPE FROM information_schema.columns
        WHERE table_schema = :db AND table_name = :t AND column_name = :c LIMIT 1');
    $stmt->execute(['db' => $testDb, 't' => $table, 'c' => $column]);

    return strtolower((string) ($stmt->fetchColumn() ?: ''));
};
ok($colType('jewellery_stock_txns', 'fine_weight') === 'decimal(18,4)', 'fine_weight is DECIMAL(18,4) — matches JW_WEIGHT_SCALE');
ok($colType('jewellery_sales', 'total_amount') === 'decimal(18,2)', 'Money is DECIMAL(18,2) — matches JW_MONEY_SCALE');
ok($colType('jewellery_daily_rates', 'rate') === 'decimal(18,4)', 'Rates are DECIMAL(18,4) — matches JW_RATE_SCALE');
ok(str_contains($colType('jewellery_stock_txns', 'holder_type'), "'karigar'"), 'The holder_type enum survived the splitter intact');
ok(str_contains($colType('jewellery_bills', 'bill_type'), "'karigar'"), 'bill_type includes karigar wage bills');
ok(str_contains($colType('jewellery_item_profiles', 'vat_base'), "'making_only'"), 'The per-item vat_base enum is complete');
// The ENUM in 073 spans two source lines; a naive splitter could truncate it.
ok(str_contains($colType('jewellery_stock_txns', 'txn_type'), "'receive_refinery'"),
    'The multi-line txn_type enum survived — the splitter did not cut it short');

echo "\n6. Replay is idempotent\n";
$before = (int) db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$testDb'")->fetchColumn();
$errors2 = accounting_module_repair_database();
$jewelleryErrors2 = array_values(array_filter($errors2, static fn ($e) => stripos((string) $e, 'jewellery') !== false));
ok($jewelleryErrors2 === [], 'A second repair run reports no jewellery error'
    . ($jewelleryErrors2 === [] ? '' : ' — ' . implode(' | ', $jewelleryErrors2)));
$after = (int) db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$testDb'")->fetchColumn();
ok($before === $after, "Re-running created no duplicate tables ($before then $after)");
$fkAfter = (int) db()->query("SELECT COUNT(*) FROM information_schema.referential_constraints
    WHERE constraint_schema = '$testDb' AND table_name LIKE 'jewellery_%'")->fetchColumn();
ok($fkCount === $fkAfter, "And no duplicate foreign keys ($fkCount then $fkAfter)");

echo "\n7. The module actually works on the fresh schema\n";
require_once $root . '/app/jewellery_reports.php';
db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,1)')
    ->execute(['n' => 'Fresh Jewellers', 'c' => 'FRESH']);
$cid = (int) db()->lastInsertId();
$settings = jewellery_settings($cid);
ok((int) $settings['masters_seeded'] === 1, 'Master seeding runs on a fresh install');
ok(count(jewellery_units_list($cid)) === 5, 'The five weight units seeded');
ok(count(jewellery_metals_list($cid)) === 5, 'The five metals seeded');
$gold = (int) db()->query("SELECT id FROM jewellery_metals WHERE company_id=$cid AND code='GOLD'")->fetchColumn();
$p22 = (int) db()->query("SELECT id FROM jewellery_purities WHERE company_id=$cid AND metal_id=$gold AND code='22K'")->fetchColumn();
$tola = (int) db()->query("SELECT id FROM jewellery_units WHERE company_id=$cid AND code='TOLA'")->fetchColumn();
$item = jewellery_save_item($cid, ['code' => 'T1', 'name' => 'Test Chain', 'metal_id' => $gold,
    'purity_id' => $p22, 'unit_id' => $tola, 'gross_weight' => 10], 0);
ok($item > 0, 'An item saves against the fresh schema');
$txn = jw_record_stock_txn($cid, ['item_id' => $item, 'txn_type' => 'purchase', 'direction' => 'in',
    'txn_date' => date('Y-m-d'), 'gross_weight' => 10, 'amount' => 1374000]);
ok($txn > 0, 'A stock movement records against the fresh schema');
ok(abs(jw_item_balance($cid, $item)['fine_weight'] - 9.16) < 0.011, 'And the fine-weight balance computes correctly');

echo "\n8. The merge actually merged — one item master, not two\n";
// This is the whole point of migration 074: a gold chain must be visible to
// the CORE Inventory module, not hidden inside the jewellery one.
$coreRow = db()->prepare('SELECT * FROM inventory_items WHERE id = :id AND company_id = :cid');
$coreRow->execute(['id' => $item, 'cid' => $cid]);
$core = $coreRow->fetch(PDO::FETCH_ASSOC);
ok($core !== false, 'The jewellery item IS an inventory_items row');
ok((string) ($core['sku'] ?? '') === 'T1', 'It carries the item code as its SKU');
ok((string) ($core['item_type'] ?? '') === 'finished_good', 'An ornament maps onto the core finished_good classification');
ok((string) ($core['unit'] ?? '') === 'TOLA', 'And the weight unit reaches the shared master for display');
$profile = db()->prepare('SELECT * FROM jewellery_item_profiles WHERE inventory_item_id = :id');
$profile->execute(['id' => $item]);
$prof = $profile->fetch(PDO::FETCH_ASSOC);
ok($prof !== false && (string) $prof['jewellery_type'] === 'ornament',
    'The precise jewellery classification is preserved on the profile');
ok((int) $prof['metal_id'] === (int) $gold, 'With its metal, purity and unit as real foreign keys');
// ob_inventory_opening() reads inventory_items, so opening stock now lands in
// the SHARED Opening Balances reconciliation rather than a jewellery-only one.
require_once $root . '/app/opening_balance_engine.php';
ok(function_exists('ob_inventory_opening'), 'The shared Opening Balances inventory section exists to receive it');

drop_test_db($testDb);
echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail === 0 ? 0 : 1);
