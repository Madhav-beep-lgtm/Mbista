<?php
declare(strict_types=1);

/**
 * One-time repair for task_invoices rows created by amount-only insert paths
 * (Work Portal issue before the 2026-07-15 fix, pre-VAT-migration rows).
 * Those rows kept taxable_amount / vat_amount / total_amount at the column
 * default 0.00, so the printed invoice showed NPR 0.00 and
 * auto_post_task_invoice_voucher() silently skipped the sales voucher.
 *
 * For every active company it:
 *   1. Rebuilds taxable/VAT/total from `amount` using the row's own vat_rate
 *      (same maths as migration 050 — running both is harmless).
 *   2. Posts the missing sales voucher for issued/paid invoices that have
 *      no task_invoice voucher yet, under the invoice's own company and the
 *      fiscal year covering its issue date.
 *
 * SAFE BY DEFAULT — running it only PRINTS the plan. Add --apply to execute:
 *   php database/repair_task_invoice_vat_totals.php            (preview)
 *   php database/repair_task_invoice_vat_totals.php --apply    (repair)
 *
 * Idempotent: the backfill only touches zero-total rows, and the voucher
 * poster refuses to double-post (source_type task_invoice, source_id = id).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require __DIR__ . '/../app/bootstrap.php';

$apply = in_array('--apply', $argv ?? [], true);
echo $apply ? "APPLY MODE — rows will be updated and vouchers posted.\n\n" : "PREVIEW MODE — nothing will change (add --apply to execute).\n\n";

$fmt = static fn (float $n): string => number_format($n, 2);

$companies = db()->query('SELECT id, name, code FROM companies WHERE is_active = 1 ORDER BY id')->fetchAll();
foreach ($companies as $company) {
    $cid = (int) $company['id'];
    echo '=== ' . $company['name'] . ' (' . $company['code'] . ") ===\n";

    // ---- 1. Backfill zero VAT figures -----------------------------------
    $zeroStmt = db()->prepare('SELECT id, invoice_no, amount, COALESCE(vat_rate, 13.00) AS vat_rate FROM task_invoices
        WHERE company_id = :cid AND amount > 0 AND COALESCE(total_amount, 0) = 0');
    $zeroStmt->execute(['cid' => $cid]);
    foreach ($zeroStmt->fetchAll() as $row) {
        $amount = (float) $row['amount'];
        $vatRate = (float) $row['vat_rate'];
        $vatAmount = round($amount * ($vatRate / 100), 2);
        $totalAmount = round($amount + $vatAmount, 2);
        echo '  backfill' . ($apply ? '' : ' (planned)') . ': ' . $row['invoice_no']
            . ' taxable ' . $fmt($amount) . ' + VAT@' . $fmt($vatRate) . '% ' . $fmt($vatAmount)
            . ' = total ' . $fmt($totalAmount) . "\n";
        if ($apply) {
            db()->prepare('UPDATE task_invoices SET taxable_amount = :taxable, vat_amount = :vat, total_amount = :total WHERE id = :id')
                ->execute(['taxable' => $amount, 'vat' => $vatAmount, 'total' => $totalAmount, 'id' => (int) $row['id']]);
        }
    }

    // ---- 2. Post missing sales vouchers ----------------------------------
    $missingStmt = db()->prepare("SELECT id, invoice_no, issued_on, amount FROM task_invoices ti
        WHERE company_id = :cid AND status IN ('issued', 'paid') AND amount > 0
          AND NOT EXISTS (SELECT 1 FROM vouchers v WHERE v.source_type = 'task_invoice' AND v.source_id = ti.id)");
    $missingStmt->execute(['cid' => $cid]);
    foreach ($missingStmt->fetchAll() as $row) {
        if (!$apply) {
            echo '  voucher (planned): ' . $row['invoice_no'] . ' amount ' . $fmt((float) $row['amount']) . "\n";
            continue;
        }

        // The poster reads company/fiscal-year context from the session;
        // pin it to the invoice's own company and the FY covering issue date.
        $fyStmt = db()->prepare('SELECT id FROM fiscal_years WHERE company_id = :cid AND :d BETWEEN start_date AND end_date ORDER BY is_default DESC, id DESC LIMIT 1');
        $fyStmt->execute(['cid' => $cid, 'd' => (string) $row['issued_on']]);
        $fyId = (int) ($fyStmt->fetchColumn() ?: 0);
        if ($fyId <= 0) {
            $fyStmt = db()->prepare('SELECT id FROM fiscal_years WHERE company_id = :cid AND is_active = 1 ORDER BY is_default DESC, start_date DESC LIMIT 1');
            $fyStmt->execute(['cid' => $cid]);
            $fyId = (int) ($fyStmt->fetchColumn() ?: 0);
        }
        if ($fyId <= 0) {
            echo '  voucher: ' . $row['invoice_no'] . " -> SKIPPED (no fiscal year for company)\n";
            continue;
        }
        $_SESSION['company_id'] = $cid;
        $_SESSION['fiscal_year_id'] = $fyId;

        auto_post_task_invoice_voucher((int) $row['id']);

        $checkStmt = db()->prepare("SELECT id FROM vouchers WHERE source_type = 'task_invoice' AND source_id = :id LIMIT 1");
        $checkStmt->execute(['id' => (int) $row['id']]);
        $voucherId = (int) ($checkStmt->fetchColumn() ?: 0);
        echo '  voucher: ' . $row['invoice_no']
            . ($voucherId > 0 ? " -> voucher #{$voucherId} posted\n" : " -> SKIPPED (ledger mapping missing?)\n");
    }
    echo "\n";
}

echo $apply ? "Done.\n" : "Preview complete. Re-run with --apply to execute.\n";
