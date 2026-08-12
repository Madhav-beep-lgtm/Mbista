<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$helpers = (string) file_get_contents($root . '/app/helpers.php');
$htaccess = (string) file_get_contents($root . '/public_html/.htaccess');
$failures = 0;

$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
};

$check(str_contains($helpers, "preg_replace('/\\.php(?=\$|[?#])/i'"), 'URL generator removes only a terminal PHP suffix');
$check(str_contains($htaccess, '%{THE_REQUEST}') && str_contains($htaccess, '[R=308,L,NE]'), 'Legacy PHP URLs redirect permanently without changing POST to GET');
$check(str_contains($htaccess, '%{REQUEST_FILENAME}.php -f') && str_contains($htaccess, '$1.php [END,QSA]'), 'Clean URLs resolve internally to existing PHP files');
$check(str_contains($htaccess, 'RewriteRule ^adminlogin/?$ /login '), 'Legacy admin login redirects to clean login URL');
$check(strpos($htaccess, '%{REQUEST_FILENAME}.php -f') < strrpos($htaccess, 'RewriteRule ^ index.php'), 'Specific clean routes resolve before the front controller');

exit($failures === 0 ? 0 : 1);
