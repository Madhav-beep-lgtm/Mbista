<?php
declare(strict_types=1);

/**
 * The hospitality management pack as a print view.
 *
 * A page of its own rather than a branch inside hospitality.php, for the same
 * reason every other print view in this app has one: a printed document is ink
 * on paper and its colours are fixed, while the screen it came from follows
 * whoever is looking at it. Keeping the two in one file means either the
 * document follows the dark theme -- which is what comes out of the printer --
 * or the theme check has to be switched off for a whole screen to let one
 * document through.
 *
 * There is no PDF library in this project and adding one on a report's behalf
 * is not a decision to make here. Every browser turns this into a real PDF
 * through "Save as PDF", which is the same file with no dependency.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/hospitality_engine.php';
require_once __DIR__ . '/../../app/hospitality_management_report.php';

require_staff_admin_or_client_books();
require_company_context();
require_permission('hospitality', 'export');
accounting_module_repair_database();

$company = current_company();
$companyId = (int) ($company['id'] ?? 0);
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$fiscalYear = current_fiscal_year();
$fyStart = (string) ($fiscalYear['start_date'] ?? date('Y-01-01'));
$fyEnd = (string) ($fiscalYear['end_date'] ?? date('Y-12-31'));
$clampDate = static function (string $date) use ($fyStart, $fyEnd): string {
    $date = trim($date);
    if ($date === '' || strtotime($date) === false) {
        return $fyStart;
    }
    return max($fyStart, min($fyEnd, date('Y-m-d', (int) strtotime($date))));
};

    require_once __DIR__ . '/../../app/hospitality_management_report.php';
    $printFrom = $clampDate(trim((string) ($_GET['from'] ?? $fyStart)));
    $printTo = $clampDate(trim((string) ($_GET['to'] ?? $fyEnd)));
    $printWanted = array_values(array_filter(array_map('strval', (array) ($_GET['section'] ?? []))));
    $printPack = hospitality_pack_build($companyId, $printFrom, $printTo, $printWanted);
    $printFmt = static fn ($value, int $dp = 2): string => number_format((float) $value, $dp);
    log_activity('hospitality_report', $companyId, 'exported',
        'Management pack printed (' . count($printPack) . ' section(s)).', $userId);
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
    <html lang="en"><head><meta charset="utf-8">
    <title>Management pack — <?= e((string) ($company['name'] ?? '')) ?></title>
    <style>
        body { font: 12px/1.45 "Inter", "Segoe UI", system-ui, sans-serif; color: #16263e; margin: 22px; background: #fff; }
        h1 { font-size: 17px; margin: 0 0 2px; }
        h2 { font-size: 14px; margin: 0 0 4px; }
        .meta { color: #55657e; font-size: 11.5px; margin: 0 0 14px; }
        .note { color: #55657e; font-size: 11px; margin: 0 0 8px; }
        section { margin: 0 0 22px; break-inside: avoid-page; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { border: 1px solid #cdd7e4; padding: 5px 7px; text-align: left; }
        thead th { background: #f1f5fb; color: #16325d; font-size: 10.5px; }
        td.n, th.n { text-align: right; white-space: nowrap; }
        tfoot th { background: #eef3fa; font-weight: 700; }
        tr.tot td { font-weight: 700; background: #f6f8fc; }
        .bar { margin: 0 0 16px; }
        .bar button { font: inherit; padding: 7px 14px; margin-right: 8px; cursor: pointer; }
        @page { size: A4 landscape; margin: 9mm; }
        @media print { .bar { display: none; } body { margin: 0; } thead { display: table-header-group; } }
    </style></head><body>
    <div class="bar"><button onclick="window.print()">Print / Save as PDF</button>
        <button onclick="window.close()">Close</button></div>
    <h1><?= e(mb_strtoupper((string) ($company['name'] ?? ''))) ?></h1>
    <p class="meta">Management pack · <?= e($printFrom) ?> → <?= e($printTo) ?> ·
        Generated <?= e(date('Y-m-d H:i')) ?> by <?= e((string) ($currentUser['name'] ?? '')) ?></p>
    <?php foreach ($printPack as $printSection): ?>
        <section>
            <h2><?= e((string) $printSection['title']) ?></h2>
            <?php if ((string) $printSection['note'] !== ''): ?>
                <p class="note"><?= e((string) $printSection['note']) ?></p>
            <?php endif; ?>
            <?php
            // The same renderer the screen uses, so a printed pack and the page
            // it came from cannot show different figures.
            ob_start();
            hospitality_pack_render_table($printSection, $printFmt);
            echo str_replace(['class="is-numeric"', 'style="font-weight:700;background:var(--mbw-soft,#eef5f0)"'],
                ['class="n"', 'class="tot"'], (string) ob_get_clean());
            ?>
        </section>
    <?php endforeach; ?>
    </body></html><?php
    