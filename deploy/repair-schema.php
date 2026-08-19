<?php
declare(strict_types=1);

/**
 * Applies the idempotent schema upgrades, once per deployment.
 *
 *   php deploy/repair-schema.php /path/to/app/base
 *
 * This exists as a FILE rather than as the `php -r '...'` it used to be. cPanel
 * does not run the real php binary directly: it runs a Perl wrapper
 * (/var/cpanel/ea4/ea_php_cli.pm), and a -r argument spanning several lines
 * reaches it with newlines in argv. The wrapper then tries to stat one of them
 * as a filename - "Unsuccessful stat on filename containing newline" - and hands
 * PHP something it cannot parse, so PHP prints its usage text and exits
 * non-zero. The deploy reads that as "schema repair failed" and refuses to
 * record the commit, which is correct behaviour on top of a failure that never
 * happened.
 *
 * It did not fail every time, which is worse than failing every time: deploys
 * would land or not land depending on the run. A script file has no newlines in
 * argv at all, so the wrapper has nothing to choke on.
 *
 * accounting_module_repair_database() deliberately does nothing during web
 * requests, so ordinary page loads never pay for hundreds of information_schema
 * checks - which is exactly why the deploy has to call it here.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

$appBase = (string) ($argv[1] ?? '');
if ($appBase === '' || !is_dir($appBase)) {
    fwrite(STDERR, "repair-schema: pass the application base directory as the first argument.\n");
    exit(2);
}

require $appBase . '/app/bootstrap.php';
require $appBase . '/app/accounting_module_repair.php';

$errors = accounting_module_repair_database();
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

// Adopt on-hand jewellery stock into traced pieces, for shops that predate
// piece tracing. This is a one-time migration per company and it used to run
// inside the sales page, where on a two-thousand-item shop it was fourteen
// thousand statements and a timed-out request. It belongs here, with the other
// once-per-deployment work.
//
// Non-fatal on purpose: a shop that cannot be adopted still sells, by typing
// the item in as it always did. A deploy must not be refused over it.
if (is_file($appBase . '/app/jewellery_stock.php')) {
    require_once $appBase . '/app/jewellery_stock.php';
    if (function_exists('jewellery_trace_ready') && jewellery_trace_ready()) {
        $adopted = 0;
        $companies = db()->query('SELECT DISTINCT i.company_id
            FROM inventory_items i
            INNER JOIN jewellery_item_profiles j ON j.inventory_item_id = i.id')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($companies as $jwCompanyId) {
            try {
                jewellery_trace_backfill_legacy_balance((int) $jwCompanyId, 0);
                $adopted++;
            } catch (Throwable $exception) {
                fwrite(STDERR, 'jewellery trace adoption skipped for company #' . (int) $jwCompanyId
                    . ': ' . $exception->getMessage() . PHP_EOL);
            }
        }
        if ($adopted > 0) {
            echo 'jewellery: on-hand stock adopted into traced pieces for ' . $adopted . " company(ies).
";
        }
    }
}

exit(0);
