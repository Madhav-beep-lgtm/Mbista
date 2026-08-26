<?php
declare(strict_types=1);

/**
 * Query shapes that quietly stop an index being used.
 *
 * A column wrapped in a function cannot be looked up in an index. Every
 * date-ranged query in the accounting system filtered on
 *
 *     COALESCE(v.voucher_date, DATE(v.created_at)) BETWEEN :from AND :to
 *
 * which meant the reports engine, the dashboard, the day book, the ledgers,
 * banking, reconciliation and the party statements each examined every posted
 * voucher a company had and applied the date test row by row afterwards. The
 * indexes were never missing -- idx_vouchers_date already leads on
 * (company_id, fiscal_year_id, voucher_date). The wrapper made them unusable.
 *
 * Measured on 200,000 vouchers spread over three fiscal years, one report-shaped
 * count took 80ms wrapped and 3.7ms unwrapped.
 *
 * The wrapper was guarding rows written before voucher_date existed as a
 * column, so migration 129 moved the guard to the data: those rows were given
 * the date they were created on and the column was closed behind them. This
 * suite is what stops the wrapper coming back, and what stops the column being
 * reopened underneath it -- either one on its own is quietly wrong.
 *
 *   php database/test_query_shape.php
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require_once $root . '/app/accounting_module_repair.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

/** Every .php under a directory, ignoring local backups. */
function qs_php_files(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && !str_contains($file->getPathname(), '.bak')) {
            $out[] = $file->getPathname();
        }
    }

    return $out;
}

echo "\n1. The date column is never wrapped in a function to filter on it\n";
$sources = array_merge(
    qs_php_files($root . '/app'),
    qs_php_files($root . '/public_html'),
    qs_php_files($root . '/database')
);
$wrapped = [];
foreach ($sources as $path) {
    if (basename($path) === basename(__FILE__)) {
        continue;
    }
    $src = (string) file_get_contents($path);
    // The exact shape that was there, in any alias: COALESCE(x.voucher_date,
    // DATE(x.created_at)). The backreference keeps it to a pair naming the
    // same table, so nothing else is caught by accident.
    if (preg_match_all('/COALESCE\((\w*\.?)voucher_date,\s*DATE\(\1created_at\)\)/', $src, $matches)) {
        $wrapped[] = basename($path) . ' x' . count($matches[0]);
    }
}
ok($wrapped === [], 'No query wraps voucher_date in COALESCE(..., DATE(created_at))'
    . ($wrapped === [] ? '' : ' — ' . implode(', ', $wrapped)));

// The broader shape, in case somebody reaches for a different function next
// time. DATE(), YEAR() and MONTH() over the column all defeat the index the
// same way; the fix is always to compare the column against a computed range.
$otherWrappers = [];
foreach ($sources as $path) {
    if (basename($path) === basename(__FILE__)) {
        continue;
    }
    $src = (string) file_get_contents($path);
    if (preg_match_all('/\b(?:DATE|YEAR|MONTH|DAY)\(\s*\w*\.?voucher_date\s*\)\s*(?:BETWEEN|<|>|<=|>=|=)/i', $src, $matches)) {
        $otherWrappers[] = basename($path) . ': ' . $matches[0][0];
    }
}
ok($otherWrappers === [], 'And none filters on DATE()/YEAR()/MONTH() OF voucher_date either'
    . ($otherWrappers === [] ? '' : ' — ' . implode('; ', array_slice($otherWrappers, 0, 3))));

echo "\n2. Which is only safe because the column cannot be null\n";
// Dropping the COALESCE without closing the column would silently drop every
// legacy voucher out of every report -- far worse than the slowness it fixed.
$nullable = (string) db()->query("SELECT IS_NULLABLE FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'vouchers' AND column_name = 'voucher_date'")->fetchColumn();
ok(strtoupper($nullable) === 'NO', 'vouchers.voucher_date is NOT NULL, so no row can hide from a date range');
ok((int) db()->query('SELECT COUNT(*) FROM vouchers WHERE voucher_date IS NULL')->fetchColumn() === 0,
    'And no row is carrying a null date today');

echo "\n3. The indexes the unwrapped filter can now actually reach\n";
$indexed = static function (string $table, string $name): bool {
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i');
    $stmt->execute(['t' => $table, 'i' => $name]);
    return (int) $stmt->fetchColumn() > 0;
};
ok($indexed('vouchers', 'idx_vouchers_date'), 'idx_vouchers_date (company, fiscal year, date) is present');
ok($indexed('vouchers', 'idx_vouchers_company_date'),
    'And idx_vouchers_company_date (company, date) — the shape a report asks for, with nothing in between');

echo "\n4. The optimiser really does use it\n";
// A plan, not a stopwatch: on a near-empty table a scan is correctly cheapest,
// so what is asserted is that the index is REACHABLE for the range, which is
// what the wrapper made impossible.
$fy = db()->query('SELECT id, company_id, start_date, end_date FROM fiscal_years ORDER BY id ASC LIMIT 1')->fetch();
if (!$fy) {
    ok(true, 'No fiscal year on this database — plan check skipped');
} else {
    $plan = db()->query("EXPLAIN SELECT COUNT(*) FROM vouchers v
        FORCE INDEX (idx_vouchers_company_date)
        WHERE v.company_id = " . (int) $fy['company_id'] . "
          AND v.voucher_date BETWEEN '" . $fy['start_date'] . "' AND '" . $fy['end_date'] . "'")->fetch();
    // key_len 4 is company_id alone; the date adds 3 more bytes (a DATE), so a
    // plan that reaches 7 is one where the range itself is being served by the
    // index rather than filtered afterwards.
    ok((int) ($plan['key_len'] ?? 0) >= 7,
        'The date range is served BY the index, not filtered after it (key_len '
            . (string) ($plan['key_len'] ?? '?') . ', want >= 7)');
}

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
