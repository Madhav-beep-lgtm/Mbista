<?php
declare(strict_types=1);

/**
 * Move books from the perpetual system to the periodic one.
 *
 *   php deploy/convert-to-periodic.php                     every company, dry run
 *   php deploy/convert-to-periodic.php 26                  one company, dry run
 *   php deploy/convert-to-periodic.php 26 --apply          post it
 *   php deploy/convert-to-periodic.php 26 --undo           take it back off
 *
 * IT CHANGES NOTHING UNLESS TOLD TO. Run with no flag as often as you like: it
 * reads the books, works out what the restatement would be, and prints it. Only
 * --apply posts anything, and --undo removes exactly what --apply added.
 *
 * Nothing already posted is edited. The restatement is one journal per fiscal
 * year, which can be read, questioned, and reversed -- a posted voucher is the
 * record of what was decided on a day, and rewriting it later leaves books that
 * reconcile to nothing anyone ever printed.
 *
 * Do the earliest year first and work forward. Each year's closing stock is the
 * next year's opening, so converting 2082 after 2083 restates the opening of a
 * year that has already been closed against a different one.
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/periodic_conversion.php';

$args = array_slice($argv, 1);
$flags = array_values(array_filter($args, static fn (string $a): bool => str_starts_with($a, '--')));
$ids = array_values(array_filter($args, static fn (string $a): bool => ctype_digit($a)));
$apply = in_array('--apply', $flags, true);
$undo = in_array('--undo', $flags, true);
$companyId = $ids === [] ? 0 : (int) $ids[0];

if ($apply && $undo) {
    exit("Choose one of --apply or --undo, not both.\n");
}
if ($apply && $companyId <= 0) {
    exit("Naming the company is required to --apply. Convert one set of books at a time,\n"
        . "read what it did, and only then move to the next.\n");
}

$method = inv_accounting_method();
echo "Books are currently kept on the ", strtoupper($method), " system.\n";
if ($method !== 'periodic') {
    echo "\n  NOTE: the periodic system is not switched on yet, so new postings will still\n";
    echo "  go the perpetual way. Converting history without switching leaves the two\n";
    echo "  halves of a year disagreeing. Switch it first, under Accounting > Inventory >\n";
    echo "  Valuation > Inventory accounting system, then convert.\n";
}
echo "\n";

$sql = "SELECT c.id AS company_id, c.name, fy.id AS fy_id, fy.label, fy.start_date, fy.end_date
    FROM companies c
    INNER JOIN fiscal_years fy ON fy.company_id = c.id
    WHERE c.is_active = 1" . ($companyId > 0 ? ' AND c.id = ' . $companyId : '') . "
    ORDER BY c.id, fy.start_date";
$years = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if ($years === []) {
    exit($companyId > 0 ? "Company {$companyId} has no fiscal years.\n" : "No active companies.\n");
}

$converted = 0;
$skipped = 0;
$refused = 0;
$lastCompany = 0;

foreach ($years as $year) {
    $cid = (int) $year['company_id'];
    $fyId = (int) $year['fy_id'];
    if ($cid !== $lastCompany) {
        printf("\n%s  (company %d)\n%s\n", (string) $year['name'], $cid, str_repeat('=', 76));
        $lastCompany = $cid;
    }

    if ($undo) {
        $result = periodic_conversion_undo($cid, $fyId);
        printf("  %-12s %s\n", (string) $year['label'], $result['note']);
        continue;
    }

    $plan = periodic_conversion_plan($cid, $fyId);
    printf("  %-12s %s .. %s\n", (string) $year['label'], (string) $year['start_date'], (string) $year['end_date']);

    if ($plan['already']) {
        echo "               already converted (voucher #", $plan['voucher_id'], ")\n";
        $skipped++;
        continue;
    }
    if (!$plan['ok']) {
        echo "               ", $plan['note'], "\n";
        $skipped++;
        continue;
    }

    printf("               purchases posted into stock  %14s   -> Purchases\n", number_format($plan['purchases'], 2));
    printf("               cost of sales posted         %14s   -> reversed\n", number_format($plan['cogs'], 2));
    printf("               stock account %s -> %s (opening %s)\n",
        number_format($plan['inventory_before'], 2), number_format($plan['inventory_after'], 2),
        number_format($plan['opening'], 2));

    if (!$apply) {
        $ties = abs($plan['inventory_after'] - $plan['opening']) <= 0.05;
        echo '               ', $ties
            ? 'WOULD CONVERT — the restated stock account lands on the opening figure.'
            : 'WOULD REFUSE — it does not land on the opening figure; something moved that'
                . "\n                              this restatement does not model.", "\n";
        $ties ? $converted++ : $refused++;
        continue;
    }

    $done = periodic_conversion_apply($cid, $fyId, null);
    if ($done['ok']) {
        printf("               DONE. journal #%d%s\n", $done['voucher_id'],
            !empty($done['closing_voucher_id']) ? ', closing stock journal #' . $done['closing_voucher_id'] : '');
        echo "               ", $done['note'], "\n";
        $converted++;
    } else {
        echo "               REFUSED. ", $done['note'], "\n";
        $refused++;
    }
}

echo "\n", str_repeat('=', 76), "\n";
if ($undo) {
    echo "  Undo complete.\n";
} elseif ($apply) {
    printf("  %d year(s) converted, %d refused, %d skipped.\n", $converted, $refused, $skipped);
    echo "\n  Read the Trial Balance and the Profit or Loss for a converted year before\n";
    echo "  moving on. The trial balance should show opening stock and purchases, and\n";
    echo "  no cost of sales at all; the balance sheet should show the closing stock.\n";
    echo "  If it does not, --undo puts it back exactly as it was.\n";
} else {
    printf("  DRY RUN. %d year(s) would convert, %d would be refused, %d skipped.\n",
        $converted, $refused, $skipped);
    echo "\n  Nothing was changed. Add --apply with a company id to post it.\n";
}
