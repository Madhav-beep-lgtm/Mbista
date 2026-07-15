<?php
declare(strict_types=1);

/**
 * Fiscal-year data diagnostics — READ-ONLY by default.
 *
 * For every company it reports:
 *   1. Overlapping or duplicate fiscal-year periods.
 *   2. Zero or multiple default fiscal years.
 *   3. Gaps between consecutive fiscal years (blocks clean carry-forward).
 *   4. Vouchers whose stored fiscal_year_id does not match the fiscal year
 *      containing their voucher_date (misfiled postings).
 *   5. Vouchers dated outside EVERY fiscal year of their company.
 *   6. Period locks whose locked_through lies outside their fiscal year.
 *
 * php database/diagnose_fiscal_years.php                   (report only)
 * php database/diagnose_fiscal_years.php --relink          (repair type 4:
 *      relink vouchers to the fiscal year containing their date, with an
 *      activity-log entry per batch; unambiguous cases only — a voucher
 *      whose date matches no year, or more than one overlapping year, is
 *      reported and left alone. Voucher dates are NEVER changed.)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require __DIR__ . '/../app/bootstrap.php';

$relink = in_array('--relink', $argv ?? [], true);
echo $relink ? "RELINK MODE — misfiled vouchers will be re-linked to the year containing their date.\n\n" : "REPORT MODE — nothing will change (add --relink to repair misfiled vouchers).\n\n";

$totalIssues = 0;
$companies = db()->query('SELECT id, name, code FROM companies ORDER BY id')->fetchAll();
foreach ($companies as $company) {
    $cid = (int) $company['id'];
    $lines = [];

    $fyStmt = db()->prepare('SELECT * FROM fiscal_years WHERE company_id = :cid ORDER BY start_date ASC');
    $fyStmt->execute(['cid' => $cid]);
    $years = $fyStmt->fetchAll();

    // 1. Overlaps / duplicates.
    for ($i = 0; $i < count($years); $i++) {
        for ($j = $i + 1; $j < count($years); $j++) {
            $a = $years[$i];
            $b = $years[$j];
            if ($a['start_date'] <= $b['end_date'] && $a['end_date'] >= $b['start_date']) {
                $lines[] = 'OVERLAP  "' . $a['label'] . '" (' . $a['start_date'] . '..' . $a['end_date'] . ') overlaps "' . $b['label'] . '" (' . $b['start_date'] . '..' . $b['end_date'] . '). Adjust one period (Settings > Fiscal years).';
            }
        }
    }

    // 2. Default count.
    $defaults = array_values(array_filter($years, static fn (array $y): bool => (int) $y['is_default'] === 1));
    if ($years !== [] && count($defaults) === 0) {
        $lines[] = 'DEFAULT  No default fiscal year — set one in Settings so new sessions get a deterministic year.';
    } elseif (count($defaults) > 1) {
        $lines[] = 'DEFAULT  ' . count($defaults) . ' default fiscal years (' . implode(', ', array_map(static fn (array $y): string => (string) $y['label'], $defaults)) . ') — keep exactly one.';
    }

    // 3. Gaps between consecutive years (only when both sides have postings
    //    is a gap a carry-forward risk, but report all).
    $nonOverlapping = $years;
    for ($i = 1; $i < count($nonOverlapping); $i++) {
        $prevEnd = new DateTimeImmutable((string) $nonOverlapping[$i - 1]['end_date']);
        $nextStart = new DateTimeImmutable((string) $nonOverlapping[$i]['start_date']);
        $expected = $prevEnd->modify('+1 day');
        if ($nextStart > $expected) {
            $lines[] = 'GAP      ' . $expected->format('Y-m-d') . ' to ' . $nextStart->modify('-1 day')->format('Y-m-d') . ' is covered by no fiscal year (between "' . $nonOverlapping[$i - 1]['label'] . '" and "' . $nonOverlapping[$i]['label'] . '"). Postings in this range are impossible and carry-forward across it is unreliable.';
        }
    }

    // 4 & 5. Voucher <-> fiscal-year consistency.
    $voucherStmt = db()->prepare('SELECT v.id, v.voucher_no, v.fiscal_year_id, COALESCE(v.voucher_date, DATE(v.created_at)) AS vdate FROM vouchers v WHERE v.company_id = :cid');
    $voucherStmt->execute(['cid' => $cid]);
    $misfiled = [];
    $orphaned = [];
    foreach ($voucherStmt->fetchAll() as $voucher) {
        $vdate = (string) $voucher['vdate'];
        $matches = array_values(array_filter($years, static fn (array $y): bool => $vdate >= (string) $y['start_date'] && $vdate <= (string) $y['end_date']));
        if ($matches === []) {
            $orphaned[] = $voucher;
            continue;
        }
        if (count($matches) > 1) {
            continue; // ambiguous (overlapping years) — fix the overlap first
        }
        if ((int) $voucher['fiscal_year_id'] !== (int) $matches[0]['id']) {
            $misfiled[] = ['voucher' => $voucher, 'target' => $matches[0]];
        }
    }
    if ($orphaned !== []) {
        $lines[] = 'NO-YEAR  ' . count($orphaned) . ' voucher(s) dated outside every fiscal year (e.g. ' . $orphaned[0]['voucher_no'] . ' on ' . $orphaned[0]['vdate'] . '). Create a fiscal year covering those dates.';
    }
    if ($misfiled !== []) {
        $sample = $misfiled[0];
        $lines[] = 'MISFILED ' . count($misfiled) . ' voucher(s) stored under the wrong fiscal year (e.g. ' . $sample['voucher']['voucher_no'] . ' dated ' . $sample['voucher']['vdate'] . ' should be in "' . $sample['target']['label'] . '").';
        if ($relink) {
            $relinked = 0;
            $update = db()->prepare('UPDATE vouchers SET fiscal_year_id = :fy WHERE id = :id');
            foreach ($misfiled as $fix) {
                $update->execute(['fy' => (int) $fix['target']['id'], 'id' => (int) $fix['voucher']['id']]);
                $relinked++;
            }
            log_activity('fiscal_year', $cid, 'vouchers_relinked', $relinked . ' voucher(s) of company #' . $cid . ' re-linked to the fiscal year containing their voucher date (diagnose_fiscal_years --relink). Dates were not changed.', null);
            $lines[] = 'FIXED    ' . $relinked . ' voucher(s) re-linked to the year containing their date (dates unchanged, audit logged).';
        }
    }

    // 6. Lock rows outside their fiscal year.
    if (table_exists('fiscal_period_locks')) {
        $lockStmt = db()->prepare('SELECT pl.locked_through, fy.label, fy.start_date, fy.end_date FROM fiscal_period_locks pl INNER JOIN fiscal_years fy ON fy.id = pl.fiscal_year_id WHERE pl.company_id = :cid');
        $lockStmt->execute(['cid' => $cid]);
        foreach ($lockStmt->fetchAll() as $lock) {
            if ((string) $lock['locked_through'] < (string) $lock['start_date'] || (string) $lock['locked_through'] > (string) $lock['end_date']) {
                $lines[] = 'CUTOFF   Lock date ' . $lock['locked_through'] . ' lies outside fiscal year "' . $lock['label'] . '" (' . $lock['start_date'] . '..' . $lock['end_date'] . ') — review it in Settings.';
            }
        }
    }

    if ($lines !== []) {
        $totalIssues += count($lines);
        echo '=== ' . $company['name'] . ' (' . $company['code'] . ") ===\n";
        foreach ($lines as $line) {
            echo '  ' . $line . "\n";
        }
        echo "\n";
    }
}

echo $totalIssues === 0 ? "No fiscal-year issues found.\n" : "$totalIssues finding(s). Overlaps, defaults, gaps and cutoff issues are fixed in Settings > Fiscal & accounting controls; NO-YEAR vouchers need a covering fiscal year; MISFILED vouchers can be repaired with --relink.\n";
