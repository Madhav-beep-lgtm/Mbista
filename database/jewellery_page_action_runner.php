<?php
declare(strict_types=1);

/**
 * Runs ONE admin jewellery-page POST action in its own process and prints the
 * resulting flash as "SUCCESS|msg", "ERROR|msg", "NONE|..." or "FATAL|...".
 *
 * A separate process per action is not laziness: every handler ends in
 * redirect(), which exits, so a single process could only ever test one.
 * Driven by database/test_jewellery_page_actions.php — not run directly.
 *
 * argv[1] is a FILE containing the JSON payload, never the JSON itself:
 * Windows' escapeshellarg() strips the double quotes out of a JSON argument.
 * The payload's optional `page` key names which admin page takes the POST
 * (default jewellery.php), so the workshop's handlers are reachable too.
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

$root = dirname(__DIR__);
$cfg = json_decode((string) file_get_contents((string) ($argv[1] ?? '')), true, 512, JSON_THROW_ON_ERROR);

// The page is a NAME looked up in a fixed list, never a path taken from the
// payload — this script executes what it is pointed at.
$pages = ['jewellery.php', 'jewellery-workshop.php', 'jewellery-trade.php'];
$page = in_array((string) ($cfg['page'] ?? ''), $pages, true) ? (string) $cfg['page'] : 'jewellery.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/admin/' . $page;
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = '127.0.0.1';

require $root . '/app/bootstrap.php';
require_once $root . '/app/accounting_module_repair.php';
require_once $root . '/app/jewellery_stock.php';

$_SESSION['user_id'] = (int) $cfg['user_id'];
set_context((int) $cfg['company_id'], (int) $cfg['fy_id']);
mark_company_pin_verified((int) $cfg['company_id']);
set_selected_company((int) $cfg['company_id']);

$_POST = $cfg['post'];
$_POST['csrf_token'] = csrf_token();
$_GET = ['view' => (string) ($cfg['post']['back_view'] ?? 'dashboard')];

register_shutdown_function(static function (): void {
    $fatal = error_get_last();
    if ($fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo 'FATAL|' . $fatal['message'] . ' @ ' . $fatal['file'] . ':' . $fatal['line'] . "\n";
        return;
    }
    foreach (['success', 'error'] as $key) {
        if (!empty($_SESSION['flash'][$key])) {
            echo strtoupper($key) . '|' . $_SESSION['flash'][$key] . "\n";
            return;
        }
    }
    echo "NONE|no flash was set\n";
});

ob_start();
include $root . '/public_html/admin/' . $page;
ob_end_clean();
