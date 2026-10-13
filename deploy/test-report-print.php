<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/pdf_engine.php';
foreach (pdf_palette() as $name => $rgb) {
    $expected = in_array($name, ['head', 'total'], true) ? [1, 1, 1] : [0, 0, 0];
    if ($rgb !== $expected) { throw new RuntimeException('Non-monochrome palette: ' . $name); }
}
$rows = [];
for ($i = 1; $i <= 100; $i++) { $rows[] = ['code' => (string) $i, 'name' => 'Test ledger account ' . $i, 'debit' => 100.25]; }
$pages = [];
pdf_render_section([
    'title' => 'General Ledger Report',
    'columns' => [['code', 'Code'], ['name', 'Account'], ['debit', 'Debit', 'right']],
    'rows' => $rows,
    'totals' => ['debit' => 10025],
], ['company' => 'TEST COMPANY', 'period' => 'FY 2026-2027', 'cell' => 'pdf_default_cell'], $pages);
if (count($pages) < 2) { throw new RuntimeException('Pagination fixture failed'); }
foreach ($pages as $page) {
    if (!str_contains($page['ops'], '(Account)') || !str_contains($page['ops'], '0.000 0.000 0.000 RG')) {
        throw new RuntimeException('Missing repeated header or black grid');
    }
    preg_match_all('/([\d.]+) ([\d.]+) ([\d.]+) (?:rg|RG)/', $page['ops'], $colors, PREG_SET_ORDER);
    foreach ($colors as $color) {
        if ((float) $color[1] !== (float) $color[2] || (float) $color[2] !== (float) $color[3]) { throw new RuntimeException('Colored drawing operation'); }
    }
}
$css = file_get_contents(__DIR__ . '/../public_html/assets/css/report-print.css');
if (!str_contains($css, 'border:1px solid #000 !important') || !str_contains($css, 'display:table-header-group')) { throw new RuntimeException('Print CSS contract missing'); }
echo "Monochrome palette, multi-page headers, black grids and shared print CSS checks passed.\n";
