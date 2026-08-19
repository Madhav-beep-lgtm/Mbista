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

exit(0);
