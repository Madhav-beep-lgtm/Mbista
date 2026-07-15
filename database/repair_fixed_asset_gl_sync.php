<?php
declare(strict_types=1);

/**
 * Register ↔ ledger synchronization repair for the Fixed Assets module.
 *
 * Historic versions of fixed-assets.php could record a register event while
 * silently skipping its voucher (missing mappings), and test cleanups could
 * delete vouchers whose schedule rows still claim "posted". Both leave the
 * asset register disagreeing with the books. For every active company this
 * script:
 *
 *   1. Posts the missing voucher for impairment / reversal / held-for-sale
 *      events whose amount is recorded but whose voucher is absent, dated on
 *      the event's own test date (skipped when the asset is disposed or the
 *      period is locked — see --ignore-locks).
 *   2. Posts the missing voucher for depreciation schedule rows that claim
 *      "posted" without a live voucher, dated on the row's period date.
 *   3. Resets lease schedule lines that claim "posted" without a live
 *      voucher back to unposted so the Lease page can re-post them.
 *   4. Reports (never auto-fixes): assets whose acquisition / lease
 *      commencement never reached the ledger, and disposed assets whose
 *      disposal posted against a never-recognized asset.
 *
 * SAFE BY DEFAULT — running it only PRINTS the plan. Flags:
 *   php database/repair_fixed_asset_gl_sync.php                 (preview)
 *   php database/repair_fixed_asset_gl_sync.php --apply         (repair)
 *   php database/repair_fixed_asset_gl_sync.php --apply --ignore-locks
 *
 * Idempotent: only rows still missing their voucher are touched.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/fixed_asset_engine.php';

$apply = in_array('--apply', $argv ?? [], true);
$ignoreLocks = in_array('--ignore-locks', $argv ?? [], true);
echo $apply ? "APPLY MODE — vouchers will be posted and phantom rows reset.\n\n" : "PREVIEW MODE — nothing will change (add --apply to execute).\n\n";

$fmt = static fn (float $n): string => number_format($n, 2);

/** Fiscal year covering the date, else the company default. 0 = none. */
function fa_sync_fiscal_year(int $companyId, string $date): int
{
    $stmt = db()->prepare('SELECT id FROM fiscal_years WHERE company_id = :cid AND :d BETWEEN start_date AND end_date ORDER BY is_default DESC, id DESC LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'd' => $date]);
    $fyId = (int) ($stmt->fetchColumn() ?: 0);
    if ($fyId > 0) {
        return $fyId;
    }
    $stmt = db()->prepare('SELECT id FROM fiscal_years WHERE company_id = :cid AND is_active = 1 ORDER BY is_default DESC, start_date DESC LIMIT 1');
    $stmt->execute(['cid' => $companyId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

$companies = db()->query('SELECT id, name, code FROM companies WHERE is_active = 1 ORDER BY id')->fetchAll();
foreach ($companies as $company) {
    $cid = (int) $company['id'];
    $lines = [];

    // ---- 1. Impairment-type events without a live voucher ------------------
    $evStmt = db()->prepare("SELECT i.*, fa.asset_code, fa.name AS asset_name, fa.status AS asset_status
        FROM asset_impairments i INNER JOIN fixed_assets fa ON fa.id = i.asset_id
        WHERE i.company_id = :cid AND GREATEST(i.impairment_loss, i.reversal) > 0
          AND (i.voucher_id IS NULL OR NOT EXISTS (SELECT 1 FROM vouchers v WHERE v.id = i.voucher_id))");
    $evStmt->execute(['cid' => $cid]);
    foreach ($evStmt->fetchAll(PDO::FETCH_ASSOC) as $event) {
        $kind = (string) $event['kind'];
        $assetId = (int) $event['asset_id'];
        $amount = round(max((float) $event['impairment_loss'], (float) $event['reversal']), 2);
        $label = $kind . ' ' . $fmt($amount) . ' on ' . $event['asset_code'] . ' (event #' . (int) $event['id'] . ')';

        if ((string) $event['asset_status'] === 'disposed') {
            $lines[] = "SKIP  $label — the asset is already disposed; delete the asset if it was a wrong entry, or post manually.";
            continue;
        }
        if ($kind === 'revaluation') {
            $lines[] = "SKIP  $label — revaluations post through the batch workflow; reject/re-approve the batch instead.";
            continue;
        }
        [$drPurpose, $crPurpose, $prefix] = $kind === 'reversal'
            ? ['accumulated_impairment', 'impairment_reversal_income', 'FA-IMPR-']
            : ['impairment_loss', 'accumulated_impairment', $kind === 'held_for_sale' ? 'FA-HFS-' : 'FA-IMP-'];
        $drL = fa_resolve_mapping($cid, $drPurpose, $assetId);
        $crL = fa_resolve_mapping($cid, $crPurpose, $assetId);
        if (!$drL || !$crL) {
            $lines[] = "SKIP  $label — set the $drPurpose / $crPurpose ledgers on the asset's page first.";
            continue;
        }
        $voucherDate = (string) ($event['test_date'] ?? '') ?: date('Y-m-d');
        $fyId = fa_sync_fiscal_year($cid, $voucherDate);
        if ($fyId <= 0) {
            $lines[] = "SKIP  $label — no fiscal year found for $voucherDate.";
            continue;
        }
        if (!$ignoreLocks && is_period_locked($cid, $fyId, $voucherDate)) {
            $lines[] = "SKIP  $label — $voucherDate is inside a locked period (re-run with --ignore-locks to post anyway).";
            continue;
        }
        if (!$apply) {
            $lines[] = "POST  $label: Dr {$drL['name']} / Cr {$crL['name']} dated $voucherDate.";
            continue;
        }
        try {
            $sourceType = $kind === 'reversal' ? 'asset_impairment_reversal' : ($kind === 'held_for_sale' ? 'asset_held_for_sale' : 'asset_impairment');
            $sourceId = $kind === 'held_for_sale' ? $assetId : (int) $event['id'];
            $vid = create_voucher_with_entries([
                'company_id' => $cid, 'fiscal_year_id' => $fyId,
                'voucher_no' => $prefix . $event['asset_code'] . '-' . (int) $event['id'],
                'voucher_type' => 'journal', 'voucher_date' => $voucherDate,
                'source_type' => $sourceType, 'source_id' => $sourceId,
                'total_amount' => $amount,
                'narration' => ucfirst(str_replace('_', ' ', $kind)) . ' of ' . $event['asset_name'] . ' — posted by the GL sync repair.',
                'status' => 'posted',
            ], [
                ['ledger_id' => (int) $drL['id'], 'entry_type' => 'debit', 'amount' => $amount],
                ['ledger_id' => (int) $crL['id'], 'entry_type' => 'credit', 'amount' => $amount],
            ]);
            db()->prepare('UPDATE asset_impairments SET voucher_id = :vid WHERE id = :id')->execute(['vid' => $vid, 'id' => (int) $event['id']]);
            log_activity('fixed_asset', $assetId, 'gl_sync_repair', ucfirst($kind) . ' ' . $fmt($amount) . ' voucher #' . $vid . ' posted retrospectively.', null);
            $lines[] = "DONE  $label -> voucher #$vid (Dr {$drL['name']} / Cr {$crL['name']}).";
        } catch (Throwable $e) {
            $lines[] = "FAIL  $label — " . $e->getMessage();
        }
    }

    // ---- 2. Depreciation schedule rows claiming a posting that is gone -----
    $depStmt = db()->prepare("SELECT s.*, fa.asset_code, fa.name AS asset_name FROM asset_depreciation_schedule s
        INNER JOIN fixed_assets fa ON fa.id = s.asset_id
        WHERE s.company_id = :cid AND s.posted = 1
          AND (s.voucher_id IS NULL OR NOT EXISTS (SELECT 1 FROM vouchers v WHERE v.id = s.voucher_id))");
    $depStmt->execute(['cid' => $cid]);
    foreach ($depStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $assetId = (int) $row['asset_id'];
        $charge = round((float) $row['depreciation'], 2);
        $label = 'depreciation period ' . (int) $row['period_no'] . ' (' . $fmt($charge) . ') on ' . $row['asset_code'];
        $expL = fa_resolve_mapping($cid, 'depreciation_expense', $assetId);
        $accL = fa_resolve_mapping($cid, 'accumulated_depreciation', $assetId);
        if (!$expL || !$accL || $charge <= 0) {
            $lines[] = "SKIP  $label — depreciation ledgers not set on the asset's page.";
            continue;
        }
        $voucherDate = (string) ($row['period_date'] ?? '') ?: date('Y-m-d');
        $fyId = fa_sync_fiscal_year($cid, $voucherDate);
        if ($fyId <= 0 || (!$ignoreLocks && is_period_locked($cid, $fyId, $voucherDate))) {
            $lines[] = "SKIP  $label — $voucherDate is inside a locked period or has no fiscal year (--ignore-locks to force).";
            continue;
        }
        if (!$apply) {
            $lines[] = "POST  $label: Dr {$expL['name']} / Cr {$accL['name']} dated $voucherDate.";
            continue;
        }
        try {
            $vid = create_voucher_with_entries([
                'company_id' => $cid, 'fiscal_year_id' => $fyId,
                'voucher_no' => 'FA-DEP-' . $row['asset_code'] . '-' . str_pad((string) (int) $row['period_no'], 3, '0', STR_PAD_LEFT) . 'R',
                'voucher_type' => 'journal', 'voucher_date' => $voucherDate,
                'source_type' => 'asset_depreciation', 'source_id' => null,
                'total_amount' => $charge,
                'narration' => 'Depreciation period ' . (int) $row['period_no'] . ' — ' . $row['asset_name'] . ' (re-posted by the GL sync repair).',
                'status' => 'posted',
            ], [
                ['ledger_id' => (int) $expL['id'], 'entry_type' => 'debit', 'amount' => $charge],
                ['ledger_id' => (int) $accL['id'], 'entry_type' => 'credit', 'amount' => $charge],
            ]);
            db()->prepare('UPDATE asset_depreciation_schedule SET voucher_id = :vid WHERE id = :id')->execute(['vid' => $vid, 'id' => (int) $row['id']]);
            log_activity('fixed_asset', $assetId, 'gl_sync_repair', 'Depreciation period ' . (int) $row['period_no'] . ' voucher #' . $vid . ' re-posted.', null);
            $lines[] = "DONE  $label -> voucher #$vid.";
        } catch (Throwable $e) {
            $lines[] = "FAIL  $label — " . $e->getMessage();
        }
    }

    // ---- 3. Lease lines claiming a posting that is gone ---------------------
    $lseStmt = db()->prepare("SELECT lsl.id, lsl.period_no, lsl.interest, lsl.payment, ll.contract_ref
        FROM lease_schedule_lines lsl INNER JOIN lease_liabilities ll ON ll.id = lsl.lease_id
        WHERE ll.company_id = :cid AND lsl.posted = 1
          AND (lsl.voucher_id IS NULL OR NOT EXISTS (SELECT 1 FROM vouchers v WHERE v.id = lsl.voucher_id))");
    $lseStmt->execute(['cid' => $cid]);
    foreach ($lseStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $label = 'lease ' . $row['contract_ref'] . ' period ' . (int) $row['period_no'] . ' (interest ' . $fmt((float) $row['interest']) . ' + payment ' . $fmt((float) $row['payment']) . ')';
        if (!$apply) {
            $lines[] = "RESET $label — will be marked unposted so the Lease page can re-post it.";
            continue;
        }
        db()->prepare('UPDATE lease_schedule_lines SET posted = 0, voucher_id = NULL WHERE id = :id')->execute(['id' => (int) $row['id']]);
        $lines[] = "DONE  $label reset to unposted — re-post it from the Lease page.";
    }

    // ---- 4. Report-only anomalies -------------------------------------------
    $acqStmt = db()->prepare("SELECT fa.asset_code, fa.name, fa.cost, fa.status, fa.asset_class FROM fixed_assets fa
        WHERE fa.company_id = :cid AND fa.cost > 0 AND fa.asset_class <> 'rou'
          AND NOT EXISTS (SELECT 1 FROM vouchers v WHERE v.source_type = 'fixed_asset_acquisition' AND v.source_id = fa.id)");
    $acqStmt->execute(['cid' => $cid]);
    foreach ($acqStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $hasDisposal = db()->prepare("SELECT COUNT(*) FROM vouchers v INNER JOIN fixed_assets fa2 ON fa2.id = v.source_id WHERE v.company_id = :cid AND v.source_type = 'asset_disposal' AND fa2.asset_code = :code");
        $hasDisposal->execute(['cid' => $cid, 'code' => $row['asset_code']]);
        $oneSided = (string) $row['status'] === 'disposed' && (int) $hasDisposal->fetchColumn() > 0;
        $lines[] = $oneSided
            ? 'NOTE  ' . $row['asset_code'] . ' (' . $row['name'] . ') was disposed but its acquisition never posted — the disposal voucher is one-sided. Recommended: delete the asset (removes the disposal voucher too).'
            : 'NOTE  ' . $row['asset_code'] . ' (' . $row['name'] . ', ' . $fmt((float) $row['cost']) . ') has no acquisition voucher — use "Post acquisition to ledger" on the asset page.';
    }
    $rouStmt = db()->prepare("SELECT fa.asset_code, fa.name, fa.status FROM fixed_assets fa
        WHERE fa.company_id = :cid AND fa.cost > 0 AND fa.asset_class = 'rou'
          AND NOT EXISTS (SELECT 1 FROM vouchers v INNER JOIN lease_liabilities ll ON ll.id = v.source_id AND ll.asset_id = fa.id WHERE v.source_type = 'lease_commencement' AND v.company_id = :cid2)");
    $rouStmt->execute(['cid' => $cid, 'cid2' => $cid]);
    foreach ($rouStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lines[] = 'NOTE  RoU asset ' . $row['asset_code'] . ' (' . $row['name'] . ', ' . $row['status'] . ') has no lease commencement voucher' . ((string) $row['status'] === 'disposed' ? ' and is disposed — its disposal voucher is one-sided. Recommended: delete the asset.' : ' — its lease never posted; recreate the lease or delete the asset.');
    }

    if ($lines !== []) {
        echo '=== ' . $company['name'] . ' (' . $company['code'] . ") ===\n";
        foreach ($lines as $line) {
            echo '  ' . $line . "\n";
        }
        echo "\n";
    }
}

echo $apply ? "Done.\n" : "Preview complete. Re-run with --apply to execute.\n";
