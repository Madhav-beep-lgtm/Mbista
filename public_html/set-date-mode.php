<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $mode = (string) ($_POST['date_mode'] ?? 'both');
    if (in_array($mode, ['ad', 'bs', 'both'], true)) {
        $_SESSION['app_date_mode'] = $mode;
    }
}

// Return to the page the user came from (local paths only).
$return = (string) ($_POST['return'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
$path = parse_url($return, PHP_URL_PATH) ?: 'admin/index.php';
$query = parse_url($return, PHP_URL_QUERY);
$path = ltrim((string) $path, '/');
if ($path === '' || str_contains($path, '..') || str_contains($path, '://')) {
    $path = 'admin/index.php';
}
redirect($path . ($query ? '?' . $query : ''));
