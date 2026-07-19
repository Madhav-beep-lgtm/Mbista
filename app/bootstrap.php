<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/i18n.php';
// Resolve the language NOW — a ?lang= switch must set its session/cookie
// before the first byte of output, or setcookie() warns mid-page.
app_lang();
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/inventory_valuation.php';
require_once __DIR__ . '/fixed_asset_engine.php';
require_once __DIR__ . '/manufacturing_engine.php';
