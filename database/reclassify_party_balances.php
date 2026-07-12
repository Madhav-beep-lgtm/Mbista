<?php
declare(strict_types=1);

/**
 * One-time transition tool for the party-ledger structure (migration 029).
 *
 * For every active company it:
 *   1. Posts opening-balance journals for parties that carry an opening
 *      balance but have no party_opening voucher yet.
 *   2. Reclassifies the CURRENT fiscal year's attributable balance sitting
 *      on the generic AR / AP ledgers onto each party's own ledger, via one
 *      balanced journal voucher per side (Dr party / Cr generic AR, and the
 *      mirror for AP). Entries that cannot be attributed to a party stay on
 *      the generic ledgers.
 *
 * Attribution: the voucher's party_id, else the source invoice's party,
 * else the work-portal client behind the invoice's task.
 *
 * SAFE BY DEFAULT — running it only PRINTS the plan. Add --apply to post:
 *   php database/reclassify_party_balances.php            (preview)
 *   php database/reclassify_party_balances.php --apply    (post journals)
 *
 * Idempotent: each side posts at most once per company
 * (source party_reclass_ar / party_reclass_ap, source_id = company id).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require __DIR__ . '/../app/bootstrap.php';

$apply = in_array('--apply', $argv ?? [], true);
echo $apply ? "APPLY MODE — journals will be posted.\n\n" : "PREVIEW MODE — nothing will be posted (add --apply to execute).\n\n";

$fmt = static fn (float $n): string => number_format($n, 2);

$companies = db()->query('SELECT id, name, code FROM companies WHERE is_active = 1 ORDER BY id')->fetchAll();
foreach ($companies as $company) {
    $cid = (int) $company['id'];
    echo '=== ' . $company['name'] . ' (' . $company['code'] . ") ===\n";

    $fyStmt = db()->prepare('SELECT id, label FROM fiscal_years WHERE company_id = :cid ORDER BY is_default DESC, is_active DESC, id DESC LIMIT 1');
    $fyStmt->execute(['cid' => $cid]);
    $fy = $fyStmt->fetch();
    if (!$fy) {
        echo "  no fiscal year — skipped\n\n";
        continue;
    }
    $fyId = (int) $fy['id'];

    // ---- 1. Opening balances -------------------------------------------
    $partiesStmt = db()->prepare("SELECT id, name, opening_balance, opening_balance_type FROM accounting_parties
        WHERE company_id = :cid AND status = 'active' AND opening_balance > 0
          AND NOT EXISTS (SELECT 1 FROM vouchers v WHERE v.source_type = 'party_opening' AND v.source_id = accounting_parties.id)");
    $partiesStmt->execute(['cid' => $cid]);
    foreach ($partiesStmt->fetchAll() as $party) {
        if ($apply) {
            $vid = post_party_opening_balance($cid, (int) $party['id']);
            echo '  opening: ' . $party['name'] . ' ' . $party['opening_balance_type'] . ' ' . $fmt((float) $party['opening_balance'])
                . ($vid > 0 ? " -> voucher #{$vid}\n" : " -> SKIPPED (could not post)\n");
        } else {
            echo '  opening (planned): ' . $party['name'] . ' ' . $party['opening_balance_type'] . ' ' . $fmt((float) $party['opening_balance']) . "\n";
        }
    }

    // ---- 2. Reclassify generic AR / AP ----------------------------------
    foreach ([
        ['side' => 'receivable', 'map' => 'default_accounts_receivable', 'source' => 'party_reclass_ar', 'label' => 'AR'],
        ['side' => 'payable', 'map' => 'default_accounts_payable', 'source' => 'party_reclass_ap', 'label' => 'AP'],
    ] as $conf) {
        $generic = get_mapped_ledger($cid, $conf['map']);
        $genericId = (int) ($generic['id'] ?? 0);
        if ($genericId <= 0) {
            continue;
        }

        $doneStmt = db()->prepare('SELECT id FROM vouchers WHERE source_type = :st AND source_id = :cid LIMIT 1');
        $doneStmt->execute(['st' => $conf['source'], 'cid' => $cid]);
        if ($doneStmt->fetchColumn()) {
            echo '  ' . $conf['label'] . ": already reclassified — skipped\n";
            continue;
        }

        $entriesStmt = db()->prepare("SELECT ve.entry_type, ve.amount, v.party_id, v.source_type, v.source_id
            FROM voucher_entries ve
            INNER JOIN vouchers v ON v.id = ve.voucher_id
            WHERE ve.ledger_id = :lid AND v.company_id = :cid AND v.fiscal_year_id = :fy AND v.status = 'posted'");
        $entriesStmt->execute(['lid' => $genericId, 'cid' => $cid, 'fy' => $fyId]);

        $nets = [];
        $unattributed = 0.0;
        foreach ($entriesStmt->fetchAll() as $entry) {
            $signed = (float) $entry['amount'] * ((string) $entry['entry_type'] === 'debit' ? 1 : -1);

            $partyId = (int) ($entry['party_id'] ?? 0);
            if ($partyId <= 0) {
                $invoiceRow = null;
                if ((string) $entry['source_type'] === 'task_invoice' && (int) $entry['source_id'] > 0) {
                    $invStmt = db()->prepare('SELECT party_id, task_id FROM task_invoices WHERE id = :id LIMIT 1');
                    $invStmt->execute(['id' => (int) $entry['source_id']]);
                    $invoiceRow = $invStmt->fetch() ?: null;
                } elseif ((string) $entry['source_type'] === 'invoice_payment_request' && (int) $entry['source_id'] > 0) {
                    $invStmt = db()->prepare('SELECT ti.party_id, ti.task_id FROM invoice_payment_requests pr INNER JOIN task_invoices ti ON ti.id = pr.invoice_id WHERE pr.id = :id LIMIT 1');
                    $invStmt->execute(['id' => (int) $entry['source_id']]);
                    $invoiceRow = $invStmt->fetch() ?: null;
                }
                if ($invoiceRow) {
                    if ($apply) {
                        $partyId = invoice_party_id($invoiceRow, $cid);
                    } else {
                        // Preview must not create parties: match existing only.
                        $partyId = (int) ($invoiceRow['party_id'] ?? 0);
                        if ($partyId <= 0 && (int) ($invoiceRow['task_id'] ?? 0) > 0) {
                            $probe = db()->prepare('SELECT ap.id FROM client_tasks ct
                                INNER JOIN client_profiles cp ON cp.id = ct.client_id
                                INNER JOIN accounting_parties ap ON ap.company_id = :cid AND ap.name = cp.organization_name
                                WHERE ct.id = :tid LIMIT 1');
                            $probe->execute(['cid' => $cid, 'tid' => (int) $invoiceRow['task_id']]);
                            $partyId = (int) ($probe->fetchColumn() ?: -1); // -1 = attributable, party pending creation
                        }
                    }
                }
            }

            if ($partyId !== 0) {
                $nets[$partyId] = ($nets[$partyId] ?? 0.0) + $signed;
            } else {
                $unattributed += $signed;
            }
        }

        $nets = array_filter($nets, static fn (float $n): bool => abs($n) >= 0.005);
        if ($nets === []) {
            echo '  ' . $conf['label'] . ": nothing attributable to parties\n";
            continue;
        }

        $entries = [];
        $movedTotal = 0.0;
        foreach ($nets as $partyId => $net) {
            $nameRow = $partyId > 0
                ? db()->query('SELECT name FROM accounting_parties WHERE id = ' . (int) $partyId)->fetchColumn()
                : '(party will be created on apply)';
            echo '  ' . $conf['label'] . ' move: ' . $nameRow . ' net ' . $fmt($net) . "\n";
            if (!$apply) {
                continue;
            }
            $partyLedgerId = ensure_party_ledger($cid, (int) $partyId, $conf['side']);
            if ($partyLedgerId <= 0) {
                echo "    !! could not create ledger — left on generic {$conf['label']}\n";
                continue;
            }
            // Receivable: positive net = party owes us = debit party ledger.
            // Payable: negative net (credit balance) = we owe = credit party ledger.
            $entries[] = [
                'ledger_id' => $partyLedgerId,
                'entry_type' => $net > 0 ? 'debit' : 'credit',
                'amount' => round(abs($net), 2),
                'memo' => 'Balance reclassified from generic ' . $conf['label'],
            ];
            $movedTotal += $net;
        }

        if ($apply && $entries !== []) {
            // Single balancing leg on the generic ledger.
            $entries[] = [
                'ledger_id' => $genericId,
                'entry_type' => $movedTotal > 0 ? 'credit' : 'debit',
                'amount' => round(abs($movedTotal), 2),
                'memo' => 'Reclassified to party ledgers',
            ];
            $voucherId = create_voucher_with_entries([
                'company_id' => $cid,
                'fiscal_year_id' => $fyId,
                'voucher_no' => 'RCL-' . $conf['label'] . '-' . str_pad((string) $cid, 4, '0', STR_PAD_LEFT),
                'voucher_type' => 'journal',
                'source_type' => $conf['source'],
                'source_id' => $cid,
                'narration' => 'One-time reclassification of ' . $conf['label'] . ' balances to party ledgers (' . $fy['label'] . ').',
                'total_amount' => round(array_sum(array_map(static fn (array $e): float => $e['entry_type'] === 'debit' ? $e['amount'] : 0.0, $entries)), 2),
                'status' => 'posted',
            ], $entries);
            echo '  ' . $conf['label'] . " reclassification voucher #{$voucherId} posted\n";
        }
    }
    echo "\n";
}

echo $apply ? "Done.\n" : "Preview complete. Re-run with --apply to post the journals.\n";
