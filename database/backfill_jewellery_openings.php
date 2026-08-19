<?php
declare(strict_types=1);

/**
 * Write the year-to-year carry for jewellery stock that already exists.
 *
 * Every fiscal year after a company's first one should hold a statement of
 * what it opened with — the previous year's closing, per item and per holder.
 * Books that predate that store have none, so this walks each jewellery
 * company's years in order and fills them in.
 *
 *   php database/backfill_jewellery_openings.php            (preview only)
 *   php database/backfill_jewellery_openings.php --apply    (write them)
 *
 * SAFE BY CONSTRUCTION. It writes to jewellery_opening_balances and nothing
 * else: no voucher, no ledger, no existing opening, no item master. The value
 * side has already carried through the Opening Balances batch, so a carry that
 * posted as well would count the same gold twice — see app/jewellery_opening.php.
 * The worst a wrong carry can do is print a wrong statement.
 *
 * Re-runnable. A line somebody has already adjusted against a physical count is
 * kept exactly as it is; everything else is recomputed from the movements.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/jewellery_stock.php';

$apply = in_array('--apply', $argv, true);
$self = 'database/backfill_jewellery_openings.php' . ($apply ? ' --apply' : '');

// WHICH database this is talking to has to be settled before anything is
// reported as missing from it.
//
// config.php reads the .env sitting beside app/, and .env is deliberately not
// committed. Run out of the REPOSITORY there is therefore no .env to find, and
// the connection quietly falls back to root@localhost with no password — which
// on a server is nobody's database. table_exists() catches the failure and
// answers "no", so the table then looks absent when the truth is that nothing
// was ever asked. That is a misleading thing for a script like this to say, so
// it asks plainly first.
try {
    db()->query('SELECT 1');
} catch (Throwable $exception) {
    fwrite(STDERR, 'Cannot reach the database configured for this copy (' . DB_NAME . ' as ' . DB_USER . ").\n\n"
        . '  ' . $exception->getMessage() . "\n\n"
        . "This normally means the script is being run from the repository, which has no .env.\n"
        . "Run the DEPLOYED copy instead — the one that sits beside your .env:\n\n"
        . '  php ~/' . $self . "\n");
    exit(2);
}

if (!jw_ob_ready()) {
    fwrite(STDERR, 'The jewellery opening store does not exist in ' . DB_NAME . " yet.\n\n"
        . "The schema is created by the DEPLOY, not by this script. Deploy first:\n\n"
        . "  cd ~/repositories/Mbista && git pull origin main && /bin/bash deploy/tasks.sh\n\n"
        . "then re-run:\n\n"
        . '  php ~/' . $self . "\n");
    exit(2);
}

// A jewellery company is one with jewellery items on its master. A company
// that has never kept a bangle has no opening to carry.
$companies = db()->query("SELECT c.id, c.name, c.code, COUNT(*) AS items
    FROM companies c
    INNER JOIN inventory_items i ON i.company_id = c.id
    INNER JOIN jewellery_item_profiles j ON j.inventory_item_id = i.id
    GROUP BY c.id, c.name, c.code
    ORDER BY c.id ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($companies === []) {
    echo "No company keeps jewellery items — nothing to carry.\n";
    exit(0);
}

echo $apply
    ? "Writing the jewellery year-to-year carry.\n\n"
    : "PREVIEW — nothing is written. Re-run with --apply to write.\n\n";
printf("%-6s %-34s %-22s %9s %9s %8s\n", 'CO', 'COMPANY', 'FISCAL YEAR', 'LINES', 'KEPT', 'MODE');
echo str_repeat('-', 94) . "\n";

$totalLines = 0;
$totalYears = 0;
$problems = [];

foreach ($companies as $company) {
    $companyId = (int) $company['id'];
    $years = db()->prepare('SELECT id, label, start_date, end_date FROM fiscal_years
        WHERE company_id = :cid ORDER BY start_date ASC');
    $years->execute(['cid' => $companyId]);

    foreach ($years->fetchAll(PDO::FETCH_ASSOC) as $year) {
        $fiscalYearId = (int) $year['id'];
        $carried = jw_ob_is_carried_year($companyId, $fiscalYearId);
        // The first year on these books is where an opening is TYPED, and it is
        // already on the item master. Seeding it here would say the same thing
        // twice in two stores.
        if (!$carried) {
            continue;
        }
        $totalYears++;

        if (!$apply) {
            $previous = jw_ob_previous_fiscal_year($companyId, $fiscalYearId);
            $lines = jw_ob_lines_carried($companyId, (string) ($previous['end_date'] ?? ''));
            $keptStmt = db()->prepare("SELECT COUNT(*) FROM jewellery_opening_balances
                WHERE company_id = :cid AND fiscal_year_id = :fy AND source = 'adjusted'");
            $keptStmt->execute(['cid' => $companyId, 'fy' => $fiscalYearId]);
            $kept = (int) $keptStmt->fetchColumn();
            $totalLines += count($lines);
            printf("%-6d %-34s %-22s %9d %9d %8s\n", $companyId,
                mb_substr((string) $company['name'], 0, 34), mb_substr((string) $year['label'], 0, 22),
                count($lines), $kept, 'would');
            continue;
        }

        $result = jw_ob_generate($companyId, $fiscalYearId, 0);
        if (!$result['ok']) {
            $problems[] = $company['code'] . ' / ' . $year['label'] . ': ' . $result['error'];
            printf("%-6d %-34s %-22s %9s %9s %8s\n", $companyId,
                mb_substr((string) $company['name'], 0, 34), mb_substr((string) $year['label'], 0, 22),
                '-', '-', 'FAILED');
            continue;
        }
        $totalLines += (int) $result['written'];
        printf("%-6d %-34s %-22s %9d %9d %8s\n", $companyId,
            mb_substr((string) $company['name'], 0, 34), mb_substr((string) $year['label'], 0, 22),
            (int) $result['written'], (int) $result['kept'], 'written');
    }
}

echo str_repeat('-', 94) . "\n";
printf("%d carried year(s), %d line(s)%s.\n", $totalYears, $totalLines,
    $apply ? '' : ' — nothing written yet');

if ($problems !== []) {
    echo "\nRefused:\n";
    foreach ($problems as $problem) {
        echo '  - ' . $problem . "\n";
    }
    echo "\nA locked opening-balance batch is the usual reason. Unlock the year and re-run.\n";
    exit(1);
}
if (!$apply && $totalYears > 0) {
    echo "\nRe-run with --apply to write these. It touches no voucher and no ledger.\n";
}
exit(0);
