<?php
declare(strict_types=1);

/**
 * Repair for kaligad receipts posted before the receive path was fixed.
 *
 * TWO FAULTS, ONE SCREEN. Both are in the books already; fixing the code stops
 * new ones and corrects nothing that has been posted.
 *
 *   STONES LEFT ON HIS LEDGER (repaired here). A stone-set job hands over gold
 *   and a packet of diamonds on one issue. Both are debited to "metal with
 *   kaligad"; stones total into issued_stone_amount, kept apart from
 *   issued_amount so carats can never leak into a wastage sum over fine gold.
 *   The receipt credited back issued_amount alone, so the stones' value stayed
 *   on the kaligad's ledger after the ring was in the safe — and stayed there,
 *   because nothing revisits a received assignment. The stone item also went on
 *   showing a packet out with him.
 *
 *   WORK ORDERS SETTLED AT ZERO (reported, never repaired). With no issue there
 *   was nothing to divide, the fine rate came out zero, and the piece entered
 *   stock worth nothing while the kaligad was owed nothing for the gold he put
 *   in. Repricing those needs THE RATE THAT WAS AGREED, which is not in the
 *   database and cannot be guessed — a bill somebody has already settled must
 *   not be rewritten from a number this script invented. They are listed, with
 *   what today's ladder would say, for a person to decide.
 *
 * SAFE BY DEFAULT — running it only PRINTS the plan. Flags:
 *   php database/repair_kaligadh_receipts.php                  (preview)
 *   php database/repair_kaligadh_receipts.php --apply          (repair)
 *   php database/repair_kaligadh_receipts.php --apply --ignore-locks
 *
 * Idempotent: the correcting voucher is sourced on the receipt, so a receipt
 * already repaired is recognised and skipped whole — the stock movements never
 * run twice.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/jewellery_reports.php';
require_once __DIR__ . '/../app/jewellery_assign.php';

const KALIGADH_REPAIR_SOURCE = 'jewellery_stone_return_repair';

$apply = in_array('--apply', $argv ?? [], true);
$ignoreLocks = in_array('--ignore-locks', $argv ?? [], true);
echo $apply
    ? "APPLY MODE — correcting vouchers will be posted and stock movements written.\n\n"
    : "PREVIEW MODE — nothing will change (add --apply to execute).\n\n";

$fmt = static fn (float $n): string => number_format($n, 2);
$near = static fn (float $a, float $b): bool => abs($a - $b) < 0.011;

/** The fiscal year covering a date, else the company's default. 0 = none. */
function kal_repair_fiscal_year(int $companyId, string $date): int
{
    $stmt = db()->prepare('SELECT id FROM fiscal_years WHERE company_id = :cid AND :d BETWEEN start_date AND end_date
        ORDER BY is_default DESC, id DESC LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'd' => $date]);
    $fyId = (int) ($stmt->fetchColumn() ?: 0);
    if ($fyId > 0) {
        return $fyId;
    }
    $stmt = db()->prepare('SELECT id FROM fiscal_years WHERE company_id = :cid AND is_active = 1
        ORDER BY is_default DESC, start_date DESC LIMIT 1');
    $stmt->execute(['cid' => $companyId]);

    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * The kaligad's metal ledger AS IT ALREADY STANDS — never opening one.
 *
 * jw_karigar_metal_ledger_id() is the right resolver everywhere else and the
 * wrong one here: it CREATES the ledger when it is missing, renames one whose
 * name has drifted, and stamps the id onto the kaligad's row. All three are
 * writes, and this script prints "nothing will change" before it has been told
 * to change anything. A preview that quietly opened ledgers on a production
 * database would be a liar in the one mode whose whole job is to be trusted.
 *
 * Finding nothing is an answer, not a gap: with no ledger there was no issue
 * posting either, so there is nothing on it to bring back.
 */
function kal_repair_karigar_ledger(int $companyId, array $karigar, int $storedOnAssignment): int
{
    if ($storedOnAssignment > 0) {
        return $storedOnAssignment;
    }
    $stored = (int) ($karigar['metal_ledger_id'] ?? 0);
    if ($stored > 0) {
        return $stored;
    }
    $stmt = db()->prepare("SELECT id FROM ledgers WHERE company_id = :cid AND code = :code
        AND status = 'active' AND type = 'asset' LIMIT 1");
    $stmt->execute(['cid' => $companyId, 'code' => 'MTL-KAR-' . (int) ($karigar['id'] ?? 0)]);

    return (int) ($stmt->fetchColumn() ?: 0);
}

/** What one ledger ended up with on one voucher: + debit, − credit. */
function kal_repair_ledger_net(int $voucherId, int $ledgerId): float
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE -amount END), 0)
        FROM voucher_entries WHERE voucher_id = :vid AND ledger_id = :lid");
    $stmt->execute(['vid' => $voucherId, 'lid' => $ledgerId]);

    return round((float) $stmt->fetchColumn(), 2);
}

if (!table_exists('jewellery_order_receipts')) {
    exit("The jewellery workshop tables are not present here. Nothing to do.\n");
}

// TWO FAULTS, AND ONLY ONE OF THEM NEEDS THIS TABLE.
//
// Components arrived with migration 103. A database that has not run it cannot
// have the stone fault — there is nowhere a stone could have been recorded —
// but it can certainly have work orders settled at a zero rate, which are read
// off columns that have existed all along. Requiring the table for BOTH made
// this script answer "nothing to do" to a shop with the other fault in its
// books, which is the worst thing a diagnostic can say.
$hasComponents = table_exists('jewellery_assignment_components');
if (!$hasComponents) {
    echo "NOTE: jewellery_assignment_components is absent — migration 103 has not run on this database,\n"
        . "      so no stones can have been stranded. Work orders are still checked below.\n\n";
}

$companies = db()->query('SELECT id, name, code FROM companies WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$totalRepaired = 0;
$totalStranded = 0.0;

foreach ($companies as $company) {
    $cid = (int) $company['id'];
    $lines = [];

    // ---- 1. Stones still sitting on a kaligad's ledger ---------------------
    // Skipped whole where migration 103 has not run: issued_stone_amount is one
    // of its columns, so this query would not even parse there.
    $stmt = $hasComponents ? db()->prepare("SELECT r.id AS receipt_id, r.receipt_no, r.receive_date, r.voucher_id,
            r.fiscal_year_id, r.received_item_id, a.id AS assignment_id, a.issue_no,
            a.issued_amount, a.issued_stone_amount, a.metal_ledger_id, a.karigar_id,
            k.code AS karigar_code, k.name AS karigar_name
        FROM jewellery_order_receipts r
        INNER JOIN jewellery_order_assignments a ON a.id = r.assignment_id
        INNER JOIN jewellery_karigars k ON k.id = a.karigar_id
        WHERE r.company_id = :cid AND r.status = 'posted' AND COALESCE(a.issued_stone_amount, 0) > 0
        ORDER BY r.receive_date ASC, r.id ASC") : null;
    if ($stmt !== null) {
        $stmt->execute(['cid' => $cid]);
    }

    foreach (($stmt !== null ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $receiptId = (int) $row['receipt_id'];
        $stoneAmount = round((float) $row['issued_stone_amount'], 2);
        $issuedAmount = round((float) $row['issued_amount'], 2);
        $label = 'receipt ' . $row['receipt_no'] . ' (' . $row['receive_date'] . ', ' . $row['karigar_code']
            . ') — ' . $fmt($stoneAmount) . ' of stones';

        // Already repaired. Checked FIRST and by the voucher, not by the
        // numbers: the whole receipt's repair hangs off this one row existing,
        // which is what stops the stock movements running a second time.
        $doneStmt = db()->prepare('SELECT id FROM vouchers WHERE company_id = :cid AND source_type = :st AND source_id = :sid LIMIT 1');
        $doneStmt->execute(['cid' => $cid, 'st' => KALIGADH_REPAIR_SOURCE, 'sid' => $receiptId]);
        if ((int) ($doneStmt->fetchColumn() ?: 0) > 0) {
            continue;
        }

        $originalVoucherId = (int) ($row['voucher_id'] ?? 0);
        if ($originalVoucherId <= 0) {
            $lines[] = "NOTE  $label — the receipt posted no voucher at all. Reverse it and receive it again.";
            continue;
        }

        $karigar = jewellery_karigar($cid, (int) $row['karigar_id']);
        $sourceLedgerId = $karigar
            ? kal_repair_karigar_ledger($cid, $karigar, (int) ($row['metal_ledger_id'] ?? 0))
            : 0;
        $receivedItem = jewellery_item($cid, (int) $row['received_item_id']);
        $destLedgerId = $receivedItem ? jw_item_stock_ledger_id($cid, $receivedItem) : 0;
        if ($sourceLedgerId <= 0 || $destLedgerId <= 0) {
            $lines[] = "SKIP  $label — the kaligad's metal ledger or the item's stock ledger is not mapped.";
            continue;
        }
        if ($sourceLedgerId === $destLedgerId) {
            $lines[] = "NOTE  $label — the issue and the piece share one ledger, so the original voucher netted "
                . 'them away and there is nothing here this script can read. Check it by hand.';
            continue;
        }

        // The signature of the fault: the original credited the kaligad with
        // the METAL only. Anything else is a receipt this script does not
        // recognise, and an unrecognised voucher is left alone rather than
        // adjusted on a guess.
        $creditFound = -kal_repair_ledger_net($originalVoucherId, $sourceLedgerId);
        if ($near($creditFound, $issuedAmount + $stoneAmount)) {
            continue;
        }
        if (!$near($creditFound, $issuedAmount)) {
            $lines[] = "NOTE  $label — its voucher credits " . $fmt($creditFound) . ' where the metal alone is '
                . $fmt($issuedAmount) . '. Not a shape this repair recognises; look at it by hand.';
            continue;
        }

        $receiveDate = (string) $row['receive_date'];
        $fyId = (int) ($row['fiscal_year_id'] ?? 0) ?: kal_repair_fiscal_year($cid, $receiveDate);
        if ($fyId <= 0) {
            $lines[] = "SKIP  $label — no fiscal year covers $receiveDate.";
            continue;
        }
        $fy = fiscal_year_by_id($fyId);
        $fyStatus = $fy ? fiscal_year_status($fy) : '';
        if ($fyStatus === 'closed' || $fyStatus === 'locked') {
            $lines[] = "SKIP  $label — fiscal year " . (string) ($fy['label'] ?? $fyId) . " is $fyStatus. "
                . 'Reopen it (audited) or post the correction by hand in an open year.';
            continue;
        }
        if (!$ignoreLocks && is_period_locked($cid, $fyId, $receiveDate)) {
            $lines[] = "SKIP  $label — $receiveDate is inside a locked period (re-run with --ignore-locks).";
            continue;
        }

        // Stones the kaligad is still shown as holding. Recomputed from the
        // component's own weight and purity, never read back from its
        // fine_weight column — that one is the assignment header's basis and is
        // zero for a stone, on purpose.
        $components = jewellery_assignment_components($cid, (int) $row['assignment_id']);
        $stones = array_values(array_filter($components,
            static fn (array $c): bool => (string) ($c['component_kind'] ?? 'metal') === 'stone'
                && (float) ($c['gross_weight'] ?? 0) > 0));

        if (!$apply) {
            $lines[] = "POST  $label: Dr " . (string) ($receivedItem['name'] ?? 'stock') . ' stock / Cr metal with '
                . $row['karigar_code'] . ' ' . $fmt($stoneAmount) . " dated $receiveDate"
                . ($stones !== [] ? ', and take ' . count($stones) . ' stone line(s) off his holding.' : '.');
            $totalStranded += $stoneAmount;
            $totalRepaired++;
            continue;
        }

        try {
            db()->beginTransaction();
            $voucherId = create_voucher_with_entries([
                'company_id' => $cid, 'fiscal_year_id' => $fyId,
                'voucher_no' => (string) $row['receipt_no'] . '/STN',
                'voucher_type' => 'journal', 'voucher_date' => $receiveDate,
                'source_type' => KALIGADH_REPAIR_SOURCE, 'source_id' => $receiptId,
                'party_id' => (int) ($karigar['party_id'] ?? 0) ?: null,
                'total_amount' => $stoneAmount,
                'narration' => 'Stones returned with ' . $row['receipt_no'] . ' — value taken off '
                    . (string) $row['karigar_name'] . "'s holding and into the finished piece, which the "
                    . 'original receipt omitted.',
                'status' => 'posted',
            ], [
                ['ledger_id' => $destLedgerId, 'entry_type' => 'debit', 'amount' => $stoneAmount],
                ['ledger_id' => $sourceLedgerId, 'entry_type' => 'credit', 'amount' => $stoneAmount],
            ]);

            foreach ($stones as $stone) {
                $gross = (float) $stone['gross_weight'];
                jw_record_stock_txn($cid, [
                    'item_id' => (int) $stone['item_id'], 'txn_type' => 'receive_karigar', 'direction' => 'out',
                    'txn_date' => $receiveDate, 'ref_no' => (string) $row['receipt_no'],
                    'holder_type' => 'karigar', 'holder_id' => (int) $row['karigar_id'],
                    'purity_id' => (int) $stone['purity_id'], 'unit_id' => (int) $stone['unit_id'],
                    'gross_weight' => $gross,
                    'fine_weight' => jw_fine_weight($gross, (float) ($stone['fineness'] ?? 0)),
                    'amount' => (float) ($stone['amount'] ?? 0),
                    'source_type' => 'jewellery_order_receipt', 'source_id' => $receiptId,
                    'voucher_id' => $voucherId,
                    'notes' => 'Stone holding cleared — repaired ' . date('Y-m-d'),
                    'created_by' => null,
                ]);
            }

            // The register has to move with the ledger. The finished piece is
            // worth the stones set into it, and leaving its movement at the
            // metal-only figure would put the stock valuation and the stock
            // ledger exactly this far apart — swapping one silent disagreement
            // for another.
            $inStmt = db()->prepare("SELECT id, amount FROM jewellery_stock_txns
                WHERE company_id = :cid AND source_type = 'jewellery_order_receipt' AND source_id = :sid
                  AND direction = 'in' AND holder_type = 'stock' ORDER BY id ASC LIMIT 1");
            $inStmt->execute(['cid' => $cid, 'sid' => $receiptId]);
            $inRow = $inStmt->fetch(PDO::FETCH_ASSOC);
            if ($inRow) {
                db()->prepare('UPDATE jewellery_stock_txns SET amount = amount + :add WHERE id = :id')
                    ->execute(['add' => $stoneAmount, 'id' => (int) $inRow['id']]);
                jw_sync_core_inventory_txn($cid, (int) $inRow['id']);
            }

            db()->commit();
            log_activity('company', $cid, 'jewellery_stone_return_repaired',
                'Receipt ' . $row['receipt_no'] . ': ' . $fmt($stoneAmount) . ' of stones taken off '
                . $row['karigar_code'] . "'s holding by the kaligad receipt repair (voucher #" . $voucherId . ').', null);
            $lines[] = "DONE  $label -> voucher #$voucherId"
                . ($stones !== [] ? ', ' . count($stones) . ' stone line(s) off his holding.' : '.');
            $totalStranded += $stoneAmount;
            $totalRepaired++;
        } catch (Throwable $repairException) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $lines[] = "FAIL  $label — " . $repairException->getMessage();
        }
    }

    // ---- 2. Work orders settled at a zero rate (report only) ---------------
    $zeroStmt = db()->prepare("SELECT r.id, r.receipt_no, r.receive_date, r.received_fine_weight,
            r.received_gross_weight, r.making_amount, r.net_payable, r.received_item_id, r.received_purity_id,
            a.unit_id, k.code AS karigar_code
        FROM jewellery_order_receipts r
        INNER JOIN jewellery_order_assignments a ON a.id = r.assignment_id
        INNER JOIN jewellery_karigars k ON k.id = a.karigar_id
        WHERE r.company_id = :cid AND r.status = 'posted'
          AND a.issued_fine_weight <= 0 AND r.avg_fine_rate <= 0 AND r.received_fine_weight > 0
        ORDER BY r.receive_date ASC, r.id ASC");
    $zeroStmt->execute(['cid' => $cid]);
    foreach ($zeroStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // Only ever a suggestion. It is what the fixed code WOULD have found on
        // the day, printed so somebody has a figure to argue from — not a
        // number this script is entitled to post against a settled bill.
        $suggestion = '';
        $item = jewellery_item($cid, (int) $row['received_item_id']);
        $purity = jewellery_purity($cid, (int) $row['received_purity_id']);
        if ($item && $purity) {
            $resolved = jw_own_metal_fine_rate($cid, $item, $purity, (int) $row['unit_id'],
                (float) $row['received_gross_weight'], (string) $row['receive_date']);
            if ((float) $resolved['rate'] > 0) {
                $suggestion = ' Today the ladder would say ' . $fmt((float) $resolved['rate'])
                    . ' per fine (' . $resolved['source'] . '), i.e. about '
                    . $fmt(round((float) $row['received_fine_weight'] * (float) $resolved['rate'], 2))
                    . ' for the metal.';
            }
        }
        $lines[] = 'NOTE  receipt ' . $row['receipt_no'] . ' (' . $row['receive_date'] . ', ' . $row['karigar_code']
            . ') settled a work order at a ZERO rate: ' . $fmt((float) $row['received_fine_weight'])
            . ' fine into stock worth nothing, and he was paid only the making of '
            . $fmt((float) $row['making_amount']) . '. Reverse it and receive it again at the rate agreed.'
            . $suggestion;
    }

    // ---- 3. Metal components cleared off the wrong item (report only) ------
    $wrongStmt = $hasComponents ? db()->prepare("SELECT r.receipt_no, r.receive_date, c.gross_weight,
            ci.sku AS component_sku, ai.sku AS assignment_sku, k.code AS karigar_code
        FROM jewellery_assignment_components c
        INNER JOIN jewellery_order_assignments a ON a.id = c.assignment_id
        INNER JOIN jewellery_order_receipts r ON r.assignment_id = a.id AND r.status = 'posted'
        INNER JOIN jewellery_karigars k ON k.id = a.karigar_id
        INNER JOIN inventory_items ci ON ci.id = c.item_id
        INNER JOIN inventory_items ai ON ai.id = a.item_id
        WHERE c.company_id = :cid AND c.component_kind = 'metal' AND c.item_id <> a.item_id
        ORDER BY r.receive_date ASC") : null;
    if ($wrongStmt !== null) {
        $wrongStmt->execute(['cid' => $cid]);
    }
    foreach (($wrongStmt !== null ? $wrongStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        // Register-only: the value was inside issued_amount all along, so the
        // ledger is right and only the weights sit on the wrong item. Left to a
        // person because it has never been seen in the wild — say so and this
        // repair grows a section that unwinds it.
        $lines[] = 'NOTE  receipt ' . $row['receipt_no'] . ' (' . $row['receive_date'] . ', ' . $row['karigar_code']
            . ') cleared ' . $fmt((float) $row['gross_weight']) . ' of ' . $row['component_sku']
            . ' off ' . $row['assignment_sku'] . " instead. The books are right; the stock register has that weight "
            . 'on the wrong item. Report this — it needs its own repair.';
    }

    if ($lines !== []) {
        echo '=== ' . $company['name'] . ' (' . $company['code'] . ") ===\n";
        foreach ($lines as $line) {
            echo '  ' . $line . "\n";
        }
        echo "\n";
    }
}

echo $apply
    ? "Done — $totalRepaired receipt(s) repaired, " . $fmt($totalStranded) . " of stones brought back.\n"
    : "Preview complete — $totalRepaired receipt(s) to repair, " . $fmt($totalStranded)
      . " of stones stranded. Re-run with --apply to execute.\n";
