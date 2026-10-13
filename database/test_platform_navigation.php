<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$header = (string) file_get_contents($root . '/app/views/partials/admin_header.php');
$clients = (string) file_get_contents($root . '/public_html/platform/clients.php');
$failures = 0;

$ok = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$condition) { $failures++; }
};

$platformStart = strpos($header, '<?php if (!empty($platformShell)): ?>');
$companyStart = strpos($header, '<?php elseif ($headerIsCustomer): ?>');
$platformNav = $platformStart !== false && $companyStart !== false
    ? substr($header, $platformStart, $companyStart - $platformStart)
    : '';

$ok(str_contains($platformNav, 'platform/index.php') && str_contains($platformNav, '>Companies</a>'),
    'Platform navigation owns the company directory');
$ok(str_contains($platformNav, 'platform/clients.php') && str_contains($platformNav, '>Clients</a>'),
    'Platform navigation owns the client directory');
$ok(str_contains($platformNav, 'platform/work.php') && str_contains($platformNav, '>Work Portal</a>'),
    'Platform navigation owns the Work Portal');
$ok(substr_count($header, '>All companies</a>') === 1
    && strpos($header, '>All companies</a>') < strpos($header, '>Admin Overview</a>'),
    'All companies is the first company-shell destination for Super Admin');
$ok(!str_contains($header, '>Client Directory</a>')
    && !str_contains($header, 'admin/workspace.php?view=home'),
    'Company-book navigation does not repeat platform directories');
$ok(!str_contains($header, 'Open M.Bista superadmin')
    && !str_contains($header, 'admin-profile-identity'),
    'The top bar keeps only the user avatar and no duplicate Super Admin control');
$ok(str_contains($clients, '$platformShell = true;')
    && str_contains($clients, "../admin/manage-clients.php"),
    'The client directory renders as an independent platform page');

echo PHP_EOL . ($failures === 0 ? 'PASS' : 'FAIL') . ": platform navigation\n";
exit($failures === 0 ? 0 : 1);
