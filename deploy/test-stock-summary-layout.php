<?php
declare(strict_types=1);
$source = file_get_contents(__DIR__ . '/../public_html/admin/stock-summary-report.php');
$checks = [
    'Single page heading' => str_contains($source, '$pageHero = false;'),
    'Profile-specific filter' => str_contains($source, '<?php if ($isJewellery): ?><label>Jewellery stock type'),
    'Ignore irrelevant jewellery query' => str_contains($source, "\$filters['jewellery_stock_kind'] = '';"),
    'Help is collapsed' => str_contains($source, '<details class="ssr-instructions">'),
    'Settings are collapsed' => str_contains($source, '<details class="mbw-card ssr-location-settings"'),
    'Readable pagination' => str_contains($source, '<?= $showingFrom ?>&ndash;<?= $showingTo ?>'),
    'Date mode respected' => str_contains($source, 'e(app_date($to))'),
    'CSRF preserved' => str_contains($source, 'verify_csrf();'),
    'Posting permission preserved' => str_contains($source, "require_permission('accounting', 'post');"),
    'Stock-count actions preserved' => str_contains($source, 'value="post_stock_counts"') && str_contains($source, 'value="unpost_stock_count"'),
];
foreach ($checks as $label => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
}
echo "Stock summary layout and retained safeguards passed.\n";
