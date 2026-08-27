<?php
declare(strict_types=1);

/**
 * Restating perpetual books onto the periodic system.
 *
 * The books already posted say a purchase was an asset and every sale carried
 * its own cost. The periodic system says a purchase is a purchase and cost of
 * sales is worked out at the year end. Both are true statements of the same
 * trading; what differs is which account each figure sits in. Moving between
 * them is a RECLASSIFICATION, and this file does it the way an accountant
 * would.
 *
 * BY JOURNAL, NOT BY EDITING HISTORY. Nothing already posted is altered. A
 * posted voucher is a record of what was decided on a day, and this application
 * has a mutation guard specifically to stop that record being rewritten later.
 * Reaching behind it to change ledgers on old entries would leave a set of books
 * nobody could reconcile to anything that was ever printed. So the restatement
 * is one journal per fiscal year, which can be read, questioned and reversed.
 *
 * WHAT THE JOURNAL SAYS. Under perpetual, at the end of a year:
 *
 *     Inventory   = Opening + Purchases - Cost of sales
 *     Cost of sales ledger = Cost of sales
 *
 * and periodic wants Inventory back at Opening, Purchases holding the buying,
 * and cost of sales holding nothing. Two moves do it:
 *
 *     Dr Purchases       P     Cr Inventory       P    the buying leaves stock
 *     Dr Inventory       C     Cr Cost of sales   C    the cost comes back
 *
 * leaving Inventory at Opening exactly, which is where the closing-stock journal
 * then takes over.
 *
 * IT REFUSES RATHER THAN GUESSES. The purchases figure comes from the stock
 * subledger, which knows what was a purchase and what was a transfer, a
 * write-off or a production receipt. If the restated inventory account does not
 * then land on the opening figure, something moved for a reason this arithmetic
 * does not model, and the conversion stops and says so instead of posting a
 * plug.
 */

require_once __DIR__ . '/inventory_valuation.php';
require_once __DIR__ . '/inventory_mapping.php';

const PERIODIC_CONVERSION_SOURCE = 'periodic_conversion';

/**
 * Net movement on one mapped purpose over a window, debits positive.
 *
 * $includeConversion decides whether the restatement journal itself is counted.
 * Measuring what to post has to leave it out; checking what WAS posted has to
 * put it back, or the check reads the books as they were before it ran and
 * concludes its own work never happened.
 */
function periodic_conversion_movement(int $companyId, string $purpose, ?string $from, string $to,
    bool $includeConversion = false): float
{
    $row = inv_resolve_mapping($companyId, $purpose);
    if (!$row) {
        return 0.0;
    }
    $sql = "SELECT COALESCE(SUM(CASE WHEN ve.entry_type = 'debit' THEN ve.amount ELSE -ve.amount END), 0)
        FROM voucher_entries ve
        INNER JOIN vouchers v ON v.id = ve.voucher_id
        WHERE v.company_id = :cid AND ve.ledger_id = :lid AND v.status = 'posted'
          AND v.voucher_date <= :to
          AND v.voucher_date >= COALESCE(:from_guard, v.voucher_date)";
    if (!$includeConversion) {
        // COALESCE, not a plain <>. In SQL, NULL <> 'x' is NULL, which is not
        // true, so every voucher with no source_type -- which is most journals
        // somebody typed by hand -- would be silently left out of the figure
        // this whole conversion is built on.
        $sql .= " AND COALESCE(v.source_type, '') <> '" . PERIODIC_CONVERSION_SOURCE . "'";
    }
    $params = ['cid' => $companyId, 'lid' => (int) $row['id'], 'to' => $to, 'from_guard' => $from];
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return inv_round_money((float) $stmt->fetchColumn());
}

/**
 * What converting one fiscal year would do, without doing any of it.
 *
 * @return array{ok:bool, note:string, company_id:int, fiscal_year_id:int,
 *   label:string, from:string, to:string, opening:float, purchases:float,
 *   cogs:float, inventory_before:float, inventory_after:float,
 *   already:bool, voucher_id:int}
 */
function periodic_conversion_plan(int $companyId, int $fiscalYearId): array
{
    $plan = ['ok' => false, 'note' => '', 'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId,
        'label' => '', 'from' => '', 'to' => '', 'opening' => 0.0, 'purchases' => 0.0, 'cogs' => 0.0,
        'inventory_before' => 0.0, 'inventory_after' => 0.0, 'already' => false, 'voucher_id' => 0];

    $year = fiscal_year_by_id($fiscalYearId);
    if (!$year || (int) ($year['company_id'] ?? 0) !== $companyId) {
        $plan['note'] = 'That fiscal year does not belong to this company.';

        return $plan;
    }
    $plan['label'] = (string) ($year['label'] ?? '');
    $plan['from'] = $from = (string) $year['start_date'];
    $plan['to'] = $to = (string) $year['end_date'];

    foreach (['inventory_asset', 'purchases', 'cogs'] as $purpose) {
        if (!inv_resolve_mapping($companyId, $purpose)) {
            $plan['note'] = 'Cannot convert: no ledger is mapped for ' . $purpose
                . '. Map it under Inventory → Ledger Mapping first.';

            return $plan;
        }
    }

    // Already done? The journal is keyed to the year, so its presence is the
    // answer -- no need to infer it from balances that could look either way.
    $existing = db()->prepare("SELECT id FROM vouchers
        WHERE company_id = :cid AND source_type = :src AND source_id = :fy LIMIT 1");
    $existing->execute(['cid' => $companyId, 'src' => PERIODIC_CONVERSION_SOURCE, 'fy' => $fiscalYearId]);
    $existingId = (int) ($existing->fetchColumn() ?: 0);
    if ($existingId > 0) {
        $plan['already'] = true;
        $plan['voucher_id'] = $existingId;
        $plan['note'] = 'This year has already been converted (voucher #' . $existingId . ').';

        return $plan;
    }

    // The buying, from the stock records -- which know a purchase from a
    // transfer, a write-off or something made in the workshop. The ledger
    // cannot tell those apart on its own.
    require_once __DIR__ . '/stock_report_engine.php';
    try {
        $summary = sr_stock_summary($companyId, ['from' => $from, 'to' => $to,
            'dormant' => true, 'zero_movement' => true, 'zero_closing' => true]);
    } catch (Throwable $exception) {
        $plan['note'] = 'Stock could not be read for this period: ' . $exception->getMessage();

        return $plan;
    }
    $plan['purchases'] = inv_round_money((float) ($summary['totals']['purchase_amount'] ?? 0));
    // Opening from the SAME source as purchases, deliberately. Reading it off
    // the ledger "the day before the period" finds nothing, because an opening
    // voucher here is dated on the year's first day and not its eve. Taking
    // both figures from the stock records also makes the check below mean
    // something: if the restated ledger does not agree with what the stock
    // records say was on the shelf, that disagreement is the thing worth
    // catching.
    $plan['opening'] = inv_round_money((float) ($summary['totals']['opening_amount'] ?? 0));
    $plan['cogs'] = periodic_conversion_movement($companyId, 'cogs', $from, $to);
    $plan['inventory_before'] = periodic_conversion_movement($companyId, 'inventory_asset', null, $to);

    // Where the stock account lands once the buying leaves it and the cost
    // comes back: it should be the opening figure, and nothing else.
    $plan['inventory_after'] = inv_round_money($plan['inventory_before'] - $plan['purchases'] + $plan['cogs']);

    if (abs($plan['purchases']) < 0.005 && abs($plan['cogs']) < 0.005) {
        $plan['note'] = 'Nothing to convert: this year posted no purchases into stock and no cost of sales.';

        return $plan;
    }

    $plan['ok'] = true;

    return $plan;
}

/**
 * Post the restatement for one fiscal year.
 *
 * Runs the closing-stock journal straight after, because a converted year with
 * no closing stock on it is a balance sheet showing last year's inventory --
 * a worse state than either system, and one nobody would notice until the
 * accounts were read.
 */
function periodic_conversion_apply(int $companyId, int $fiscalYearId, ?int $userId = null): array
{
    $plan = periodic_conversion_plan($companyId, $fiscalYearId);
    if (!$plan['ok']) {
        return $plan;
    }

    $stock = inv_resolve_mapping($companyId, 'inventory_asset');
    $purchases = inv_resolve_mapping($companyId, 'purchases');
    $cogs = inv_resolve_mapping($companyId, 'cogs');

    $legs = [];
    if (abs($plan['purchases']) > 0.005) {
        $legs[] = ['ledger_id' => (int) $purchases['id'], 'entry_type' => 'debit',
            'amount' => abs($plan['purchases']), 'memo' => 'Purchases reclassified out of stock'];
        $legs[] = ['ledger_id' => (int) $stock['id'], 'entry_type' => 'credit',
            'amount' => abs($plan['purchases']), 'memo' => 'Purchases reclassified out of stock'];
    }
    if (abs($plan['cogs']) > 0.005) {
        $legs[] = ['ledger_id' => (int) $stock['id'], 'entry_type' => 'debit',
            'amount' => abs($plan['cogs']), 'memo' => 'Cost of sales reversed — derived under periodic'];
        $legs[] = ['ledger_id' => (int) $cogs['id'], 'entry_type' => 'credit',
            'amount' => abs($plan['cogs']), 'memo' => 'Cost of sales reversed — derived under periodic'];
    }

    db()->beginTransaction();
    try {
        $voucherId = (int) create_voucher_with_entries([
            'company_id' => $companyId,
            'fiscal_year_id' => $fiscalYearId,
            'voucher_no' => 'PERIODIC-' . $fiscalYearId,
            'voucher_type' => 'journal',
            'voucher_date' => $plan['to'],
            'source_type' => PERIODIC_CONVERSION_SOURCE,
            'source_id' => $fiscalYearId,
            'total_amount' => abs($plan['purchases']) + abs($plan['cogs']),
            'narration' => 'Restated to the periodic system — purchases ' . number_format($plan['purchases'], 2)
                . ' moved to Purchases, cost of sales ' . number_format($plan['cogs'], 2) . ' reversed ('
                . $plan['label'] . ')',
            'status' => 'posted',
            'posted_by' => $userId,
        ], $legs);

        // Did it land where the arithmetic said it would? If not, this year
        // moved stock for a reason the restatement does not model, and a plug
        // would hide that rather than fix it.
        $after = periodic_conversion_movement($companyId, 'inventory_asset', null, $plan['to'], true);
        $landed = inv_round_money($after);
        if (abs($landed - $plan['opening']) > 0.05) {
            db()->rollBack();
            $plan['ok'] = false;
            $plan['note'] = 'REFUSED and rolled back. After restating, the stock account would hold '
                . number_format($landed, 2) . ' where the opening figure is ' . number_format($plan['opening'], 2)
                . '. Stock moved in this year for a reason this conversion does not model — a write-off,'
                . ' a production receipt or a transfer posted straight to the ledger. Reconcile that first;'
                . ' posting the difference as a plug would bury it.';

            return $plan;
        }

        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $plan['ok'] = false;
        $plan['note'] = 'Conversion failed and nothing was posted: ' . $exception->getMessage();

        return $plan;
    }

    $plan['voucher_id'] = $voucherId;

    // And bring the closing stock on to the balance sheet, which is the other
    // half of being on this system at all.
    $closing = inv_post_closing_stock_voucher($companyId, $fiscalYearId, $userId);
    $plan['closing_voucher_id'] = (int) $closing['voucher_id'];
    $plan['closing_stock'] = (float) $closing['closing'];
    $plan['note'] = 'Converted. ' . ((string) $closing['note'] !== '' ? $closing['note'] : 'Closing stock '
        . number_format((float) $closing['closing'], 2) . ' is on the balance sheet.');

    return $plan;
}

/**
 * Undo the restatement for one fiscal year.
 *
 * Both journals go, and the books are back on the perpetual footing exactly as
 * they were -- which is the property that makes the conversion safe to attempt
 * on real books at all.
 */
function periodic_conversion_undo(int $companyId, int $fiscalYearId): array
{
    $removed = 0;
    db()->beginTransaction();
    try {
        foreach ([PERIODIC_CONVERSION_SOURCE, 'inventory_closing'] as $source) {
            $stmt = db()->prepare("SELECT id FROM vouchers
                WHERE company_id = :cid AND source_type = :src AND source_id = :fy");
            $stmt->execute(['cid' => $companyId, 'src' => $source, 'fy' => $fiscalYearId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $voucherId) {
                db()->prepare('DELETE FROM vouchers WHERE id = :id AND company_id = :cid')
                    ->execute(['id' => (int) $voucherId, 'cid' => $companyId]);
                $removed++;
            }
        }
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'removed' => 0, 'note' => 'Undo failed: ' . $exception->getMessage()];
    }

    return ['ok' => true, 'removed' => $removed,
        'note' => $removed === 0 ? 'Nothing to undo for this year.'
            : $removed . ' journal(s) removed — the year is back on the perpetual footing.'];
}
