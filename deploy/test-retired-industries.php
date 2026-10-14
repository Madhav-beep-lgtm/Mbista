<?php
declare(strict_types=1);
$workspace = file_get_contents(__DIR__ . '/../public_html/admin/workspace.php');
$dashboard = file_get_contents(__DIR__ . '/../public_html/dashboard.php');
foreach (['view=industries','<h2>Industry Management</h2>','<label>Industry','industry_id = :industry_id','JOIN industries','active_industries()'] as $removed) {
    if (str_contains($workspace,$removed)) throw new RuntimeException('Industry UI or mutation remains: ' . $removed);
}
if (!str_contains($workspace,"['save_industry', 'delete_industry'], true)) deny_access")) throw new RuntimeException('Retired POST actions must reject requests.');
if (str_contains($dashboard,'industry_name') || str_contains($dashboard,'>Industry</span>')) throw new RuntimeException('Dashboard industry remains.');
echo "Retired industries: navigation, forms, dashboard, obsolete queries and write guards passed.\n";
