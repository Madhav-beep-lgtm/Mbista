<?php
declare(strict_types=1);

/**
 * Government interest & fines calculator (Nepal).
 *
 * CALCULATES ONLY — nothing is posted until an admin explicitly posts the
 * voucher from the UI. All month counting is in Bikram Sambat months via
 * app/nepali_date.php, because the tax law charges "for a month or part of a
 * month" on the NEPALI calendar:
 *
 *  - VAT unpaid by its due date:
 *      • 15% p.a. simple interest, per started BS month  (VAT Act interest)
 *      • 10% p.a. additional duty, simple, counted DAILY (thap dastur)
 *  - Social Security Tax and any other ledger under Duties & Taxes:
 *      • 15% p.a. simple interest, per started BS month
 */

require_once __DIR__ . '/nepali_date.php';

/**
 * Started Nepali months between a due date and an as-of date (both AD).
 * "A month or part of a month" counts as a full month, so one day late is
 * already 1 month; the due date itself (or earlier) is 0.
 */
function fines_bs_months_between(string $dueAd, string $asOfAd): int
{
    if ($asOfAd <= $dueAd) {
        return 0;
    }
    $due = ad_to_bs($dueAd);
    $asOf = ad_to_bs($asOfAd);
    if (!$due || !$asOf) {
        // Outside the BS table: approximate with AD months (same rule).
        $d1 = new DateTimeImmutable($dueAd);
        $d2 = new DateTimeImmutable($asOfAd);
        $months = ((int) $d2->format('Y') - (int) $d1->format('Y')) * 12 + ((int) $d2->format('n') - (int) $d1->format('n'));
        return max(1, $months + ((int) $d2->format('j') > (int) $d1->format('j') ? 1 : 0));
    }
    // ad_to_bs returns positional [year, month, day].
    [$dueY, $dueM, $dueD] = $due;
    [$asY, $asM, $asD] = $asOf;
    $months = ((int) $asY - (int) $dueY) * 12 + ((int) $asM - (int) $dueM);
    if ((int) $asD > (int) $dueD) {
        $months++;
    }
    return max(1, $months);
}

/** Calendar days late (AD), 0 when on time. */
function fines_days_between(string $dueAd, string $asOfAd): int
{
    if ($asOfAd <= $dueAd) {
        return 0;
    }
    return (int) (new DateTimeImmutable($dueAd))->diff(new DateTimeImmutable($asOfAd))->days;
}

/**
 * Late-payment charges on unpaid VAT.
 * @return array{months:int, days:int, interest_15:float, additional_10:float, total:float}
 */
function fines_vat_charges(float $unpaid, string $dueAd, string $asOfAd): array
{
    $unpaid = round(max(0.0, $unpaid), 2);
    $months = fines_bs_months_between($dueAd, $asOfAd);
    $days = fines_days_between($dueAd, $asOfAd);
    $interest = round($unpaid * 0.15 * $months / 12, 2);
    $additional = round($unpaid * 0.10 * $days / 365, 2);
    return [
        'months' => $months,
        'days' => $days,
        'interest_15' => $interest,
        'additional_10' => $additional,
        'total' => round($interest + $additional, 2),
    ];
}

/**
 * Late-payment interest on SST / any Duties & Taxes head (15% p.a. simple,
 * per started BS month).
 * @return array{months:int, days:int, interest_15:float, additional_10:float, total:float}
 */
function fines_duties_interest(float $unpaid, string $dueAd, string $asOfAd): array
{
    $unpaid = round(max(0.0, $unpaid), 2);
    $months = fines_bs_months_between($dueAd, $asOfAd);
    $interest = round($unpaid * 0.15 * $months / 12, 2);
    return [
        'months' => $months,
        'days' => fines_days_between($dueAd, $asOfAd),
        'interest_15' => $interest,
        'additional_10' => 0.0,
        'total' => $interest,
    ];
}

/**
 * Ledgers a fine can be computed against: everything under a group whose name
 * mentions duties/tax (VAT payable, TDS, SST, excise …), with current balance.
 * Balance sign: liabilities carry credit balances; returned credit-positive.
 */
function fines_duty_tax_ledgers(int $companyId): array
{
    $stmt = db()->prepare("SELECT l.id, l.code, l.name, g.name AS group_name,
            COALESCE((SELECT SUM(CASE WHEN ve.entry_type = 'credit' THEN ve.amount ELSE -ve.amount END)
                FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id
                WHERE ve.ledger_id = l.id AND v.status = 'posted'), 0) AS credit_balance
        FROM ledgers l INNER JOIN ledger_groups g ON g.id = l.group_id
        WHERE l.company_id = :cid AND l.status = 'active'
          AND (LOWER(g.name) LIKE '%duties%' OR LOWER(g.name) LIKE '%tax%')
          AND l.type = 'liability'
        ORDER BY l.code ASC");
    $stmt->execute(['cid' => $companyId]);
    return array_map(static function (array $r): array {
        $r['credit_balance'] = round((float) $r['credit_balance'], 2);
        return $r;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Post the calculated charge as a journal (ADMIN action — the calculator never
 * posts on its own): Dr Fines & Penalties expense / Cr the tax ledger, so the
 * liability grows by the interest now owed to the government.
 * Returns ['ok'=>bool, 'error'=>?string, 'voucher_id'=>int].
 */
function fines_post_voucher(int $companyId, int $taxLedgerId, float $amount, string $narration, int $userId): array
{
    $amount = round($amount, 2);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Nothing to post — the calculated charge is zero.', 'voucher_id' => 0];
    }
    $ledgerStmt = db()->prepare("SELECT * FROM ledgers WHERE id = :id AND company_id = :cid AND status = 'active' LIMIT 1");
    $ledgerStmt->execute(['id' => $taxLedgerId, 'cid' => $companyId]);
    $taxLedger = $ledgerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$taxLedger) {
        return ['ok' => false, 'error' => 'Tax ledger not found for this company.', 'voucher_id' => 0];
    }
    // Fines & Penalties expense ledger, auto-created once under an indirect
    // expense group (fines are never deductible business costs, but they are
    // still real expenses in the books).
    $fineLedgerStmt = db()->prepare("SELECT id FROM ledgers WHERE company_id = :cid AND code = 'FINES-PEN' LIMIT 1");
    $fineLedgerStmt->execute(['cid' => $companyId]);
    $fineLedgerId = (int) ($fineLedgerStmt->fetchColumn() ?: 0);
    if ($fineLedgerId <= 0) {
        $groupStmt = db()->prepare("SELECT id FROM ledger_groups WHERE company_id = :cid AND master_key LIKE '%expense%' AND is_active = 1 ORDER BY (LOWER(name) LIKE '%indirect%') DESC, id ASC LIMIT 1");
        $groupStmt->execute(['cid' => $companyId]);
        $groupId = (int) ($groupStmt->fetchColumn() ?: 0);
        if ($groupId <= 0) {
            return ['ok' => false, 'error' => 'No expense ledger group exists to hold the Fines & Penalties ledger.', 'voucher_id' => 0];
        }
        db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status) VALUES (:cid, :gid, 'FINES-PEN', 'Government Fines & Interest', 'expense', 'active')")
            ->execute(['cid' => $companyId, 'gid' => $groupId]);
        $fineLedgerId = (int) db()->lastInsertId();
    }
    try {
        $voucherId = (int) create_voucher_with_entries([
            'company_id' => $companyId,
            'fiscal_year_id' => null,
            'voucher_no' => 'FINE-' . $taxLedgerId . '-' . substr((string) time(), -6),
            'voucher_type' => 'journal',
            'voucher_date' => date('Y-m-d'),
            'source_type' => 'government_fine',
            'source_id' => null,
            'total_amount' => $amount,
            'narration' => $narration,
            'status' => 'posted',
            'posted_by' => $userId,
        ], [
            ['ledger_id' => $fineLedgerId, 'entry_type' => 'debit', 'amount' => $amount, 'memo' => 'Interest / additional duty on late payment'],
            ['ledger_id' => (int) $taxLedger['id'], 'entry_type' => 'credit', 'amount' => $amount, 'memo' => 'Payable to the government with the tax'],
        ]);
        return ['ok' => true, 'error' => null, 'voucher_id' => $voucherId];
    } catch (Throwable $exception) {
        return ['ok' => false, 'error' => $exception->getMessage(), 'voucher_id' => 0];
    }
}
