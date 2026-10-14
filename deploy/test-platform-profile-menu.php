<?php
declare(strict_types=1);
$source = file_get_contents(__DIR__ . '/../app/views/partials/admin_header.php');
$start = strpos($source, '<div class="admin-profile-dropdown">');
$end = strpos($source, '</details>', $start);
$menu = substr($source, $start, $end - $start);
if (!preg_match("~<\?php if \(user_is_super_admin\(\)\): \?>(.*?)<\?php endif; \?>~s", $menu, $match)) throw new RuntimeException('Missing super-admin-only menu block.');
function user_is_super_admin(): bool { return $GLOBALS['isSuper']; }
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES); }
function url(string $path): string { return '/app/' . $path; }
function icon(string $name): string { return ''; }
foreach ([true, false] as $isSuper) {
    ob_start();
    eval('?>' . $match[0]);
    $html = ob_get_clean();
    if (str_contains($html, '/app/platform/index.php') !== $isSuper) throw new RuntimeException('Platform link visibility is incorrect.');
    if ($isSuper && !str_contains($html, '<strong>Platform</strong>')) throw new RuntimeException('Platform label missing.');
}
echo "Platform profile menu: super admin link and non-super exclusion passed.\n";
