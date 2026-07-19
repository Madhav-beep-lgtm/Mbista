<?php
declare(strict_types=1);
// Serves the active language dictionary as JS (single source of truth:
// app/i18n/<lang>.php). English (or unknown) => empty dictionary.
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: private, max-age=3600');

$lang = app_lang();
$dict = [];
if ($lang !== 'en') {
    $file = __DIR__ . '/../app/i18n/' . basename($lang) . '.php';
    if (is_file($file)) {
        $dict = (array) require $file;
    }
}
echo 'window.MBW_LANG=' . json_encode($lang) . ';window.MBW_I18N=' . json_encode($dict, JSON_UNESCAPED_UNICODE) . ';';
