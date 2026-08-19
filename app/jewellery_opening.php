<?php
declare(strict_types=1);

/**
 * Perpetual succession for jewellery stock: what one fiscal year closed with,
 * recorded as what the next one opened with.
 *
 * Accounting does not stop at a year end; it is only reported in years. So the
 * closing position of year N IS the opening position of year N+1 — it is not
 * something to be counted and typed again, and re-typing it is how the two
 * stop agreeing. This file computes the carry, records it per year, and lets a
 * physical count differ from it only deliberately and with a reason.
 *
 * THE RULE THAT GOVERNS EVERYTHING HERE: generating a carry posts NOTHING to
 * the general ledger. The value side has already carried — the stock ledgers
 * and each "Metal with <kaligad>" ledger are ordinary assets, brought forward
 * by the Opening Balances batch like every other asset. Writing a voucher here
 * as well would count the same gold twice. Only an ADJUSTMENT posts, and only
 * the difference it represents.
 *
 * This mirrors inv_ob_generate() / inv_ob_adjust() in stock_report_engine.php
 * deliberately: one opening discipline for the whole system, whether the shop
 * keeps bolts or bangles.
 */

require_once __DIR__ . '/jewellery_stock.php';

/** Whether the per-year carry store exists yet. */
function jw_ob_ready(): bool
{
    return table_exists('jewellery_opening_balances') && table_exists('jewellery_stock_txns');
}

/**
 * The fiscal year immediately before this one, or null in the first year.
 *
 * Same shape as inv_ob_generate() uses: the latest year that ENDED before this
 * one began, so a gap in the calendar (a company that skipped a year) still
 * carries from the last year it actually traded.
 */
function jw_ob_previous_fiscal_year(int $companyId, int $fiscalYearId): ?array
{
    $fyStmt = db()->prepare('SELECT * FROM fiscal_years WHERE id = :id AND company_id = :cid LIMIT 1');
    $fyStmt->execute(['id' => $fiscalYearId, 'cid' => $companyId]);
    $fy = $fyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$fy) {
        return null;
    }
    $prevStmt = db()->prepare('SELECT * FROM fiscal_years WHERE company_id = :cid AND end_date < :start
        ORDER BY end_date DESC LIMIT 1');
    $prevStmt->execute(['cid' => $companyId, 'start' => (string) $fy['start_date']]);

    return $prevStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * True when this year's opening is CARRIED rather than keyed.
 *
 * Not simply "a previous fiscal year row exists". A shop that adopts this
 * system in the middle of its life often has earlier years created and empty —
 * opened so the calendar looks right, never traded on. Those years have no
 * closing to carry, so the year being started is the first year on THESE books
 * and its opening still has to be typed.
 *
 * The test is therefore whether anything actually moved before this year began.
 */
function jw_ob_is_carried_year(int $companyId, int $fiscalYearId): bool
{
    $previous = jw_ob_previous_fiscal_year($companyId, $fiscalYearId);
    if ($previous === null || !table_exists('jewellery_stock_txns')) {
        return false;
    }
    $stmt = db()->prepare("SELECT 1 FROM jewellery_stock_txns
        WHERE company_id = :cid AND txn_date <= :end LIMIT 1");
    $stmt->execute(['cid' => $companyId, 'end' => (string) $previous['end_date']]);

    return $stmt->fetchColumn() !== false;
}

/** The holders a carried line can name, in the order a statement reads best. */
function jw_ob_holder_labels(): array
{
    return [
        'stock' => 'Showroom',
        'karigar' => 'With kaligad',
        'refinery' => 'With refinery',
        'customer' => 'With customer',
    ];
}

/**
 * Generate — or refresh — this year's carried opening.
 *
 * Previous year exists: replay its closing, per item and per holder, and write
 * it as 'carried'. First year: seed 'initial' from the item master, which is
 * where a first-year opening is keyed.
 *
 * Rows somebody adjusted are left exactly as they are, so a refresh after a
 * late entry in the old year never silently discards a physical count. Every
 * other row for the year is rewritten, which is what makes a stale line — a
 * kaligad who has since returned everything — disappear rather than linger.
 *
 * Posts nothing. See the file header for why.
 */
function jw_ob_generate(int $companyId, int $fiscalYearId, int $userId = 0): array
{
    if (!jw_ob_ready()) {
        return ['ok' => false, 'error' => 'The jewellery opening store is not installed yet. Run the schema repair and try again.',
            'written' => 0, 'kept' => 0, 'carried' => false];
    }
    if (function_exists('inv_ob_batch_status') && inv_ob_batch_status($companyId, $fiscalYearId) === 'locked') {
        return ['ok' => false, 'error' => 'Opening balances for this year are locked. Unlock them first — the jewellery opening follows the same lock.',
            'written' => 0, 'kept' => 0, 'carried' => false];
    }
    $fyStmt = db()->prepare('SELECT * FROM fiscal_years WHERE id = :id AND company_id = :cid LIMIT 1');
    $fyStmt->execute(['id' => $fiscalYearId, 'cid' => $companyId]);
    $fy = $fyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$fy) {
        return ['ok' => false, 'error' => 'Fiscal year not found for this company.', 'written' => 0, 'kept' => 0, 'carried' => false];
    }
    $prevFy = jw_ob_previous_fiscal_year($companyId, $fiscalYearId);

    $lines = $prevFy !== null
        ? jw_ob_lines_carried($companyId, (string) $prevFy['end_date'])
        : jw_ob_lines_initial($companyId);

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        // What somebody counted and corrected outranks what the replay says.
        $keptStmt = db()->prepare("SELECT item_id, holder_type, holder_id, reserved
            FROM jewellery_opening_balances
            WHERE company_id = :cid AND fiscal_year_id = :fy AND source = 'adjusted'");
        $keptStmt->execute(['cid' => $companyId, 'fy' => $fiscalYearId]);
        $kept = [];
        foreach ($keptStmt->fetchAll(PDO::FETCH_ASSOC) as $keptRow) {
            $kept[jw_ob_line_key($keptRow)] = true;
        }

        db()->prepare("DELETE FROM jewellery_opening_balances
            WHERE company_id = :cid AND fiscal_year_id = :fy AND source <> 'adjusted'")
            ->execute(['cid' => $companyId, 'fy' => $fiscalYearId]);

        $insert = db()->prepare('INSERT INTO jewellery_opening_balances
                (company_id, fiscal_year_id, item_id, holder_type, holder_id, reserved,
                 qty_pieces, gross_grams, stone_grams, fine_grams, amount,
                 carried_gross_grams, carried_amount, source)
            VALUES (:cid, :fy, :item, :ht, :hid, :res, :pieces, :gross, :stone, :fine, :amount,
                 :cgross, :camount, :src)');
        $source = $prevFy !== null ? 'carried' : 'initial';
        $written = 0;
        foreach ($lines as $line) {
            if (isset($kept[jw_ob_line_key($line)])) {
                continue;
            }
            $insert->execute([
                'cid' => $companyId, 'fy' => $fiscalYearId,
                'item' => (int) $line['item_id'], 'ht' => (string) $line['holder_type'],
                'hid' => (int) $line['holder_id'], 'res' => (int) $line['reserved'],
                'pieces' => round((float) $line['qty_pieces'], 3),
                'gross' => round((float) $line['gross_grams'], 6),
                'stone' => round((float) $line['stone_grams'], 6),
                'fine' => round((float) $line['fine_grams'], 6),
                'amount' => jw_round_money((float) $line['amount']),
                // Frozen here and never written again: what the replay said.
                'cgross' => round((float) $line['gross_grams'], 6),
                'camount' => jw_round_money((float) $line['amount']),
                'src' => $source,
            ]);
            $written++;
        }
        if ($ownsTransaction) {
            db()->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $exception->getMessage(), 'written' => 0, 'kept' => 0, 'carried' => $prevFy !== null];
    }

    // Noted only after the carry is safely committed, and never allowed to turn
    // a successful one into a reported failure. A line in the activity log is
    // not worth telling somebody their year did not open — which is exactly
    // what happened when this sat inside the try above and a CLI run, having no
    // signed-in user, tripped the actor foreign key.
    if (function_exists('log_activity')) {
        try {
            log_activity('company', $companyId, 'jewellery_opening_carried',
                'Jewellery opening for ' . (string) ($fy['label'] ?? '#' . $fiscalYearId) . ': ' . $written
                . ' line(s) ' . ($prevFy !== null ? 'carried from ' . (string) ($prevFy['label'] ?? '') : 'seeded from the item master')
                . '. No ledger entry was posted.', $userId ?: null);
        } catch (Throwable $ignored) {
            // Deliberately swallowed: see above.
        }
    }

    return ['ok' => true, 'error' => null, 'written' => $written, 'kept' => count($kept), 'carried' => $prevFy !== null];
}

/** The identity of one carried line: item, who held it, and whether it was spoken for. */
function jw_ob_line_key(array $line): string
{
    return (int) $line['item_id'] . '|' . (string) $line['holder_type']
        . '|' . (int) $line['holder_id'] . '|' . (int) ($line['reserved'] ?? 0);
}

/**
 * The previous year's closing, as the lines this year opens with.
 *
 * Two reads for the whole shop, never one per item: the holder-wise position
 * from the movement ledger, and — inside the showroom — which pieces were
 * already spoken for. A reserved piece is split out of the free figure rather
 * than added to it, so the item's total is unchanged by the split.
 */
function jw_ob_lines_carried(int $companyId, string $asOf): array
{
    $balances = jw_item_holder_balances($companyId, $asOf);
    $reserved = jw_reserved_units_at($companyId, $asOf);

    $lines = [];
    foreach ($balances as $balance) {
        $line = [
            'item_id' => (int) $balance['item_id'],
            'holder_type' => (string) $balance['holder_type'],
            'holder_id' => (int) $balance['holder_id'],
            'reserved' => 0,
            'qty_pieces' => (float) $balance['qty_pieces'],
            'gross_grams' => (float) $balance['gross_grams'],
            'stone_grams' => (float) $balance['stone_grams'],
            'fine_grams' => (float) $balance['fine_grams'],
            'amount' => (float) $balance['value'],
        ];
        $held = $line['holder_type'] === 'stock' ? ($reserved[$line['item_id']] ?? null) : null;
        if ($held === null || (float) $held['gross_grams'] <= 0.00005 || $line['gross_grams'] <= 0.00005) {
            $lines[] = $line;
            continue;
        }
        // Split the showroom line in the proportion actually reserved, capped
        // at the whole of it: the trace layer records a piece's CURRENT weight,
        // so a piece altered since the year end must never make the reserved
        // half exceed what the ledger says was there.
        $share = min(1.0, (float) $held['gross_grams'] / $line['gross_grams']);
        $reservedLine = $line;
        $reservedLine['reserved'] = 1;
        foreach (['qty_pieces', 'gross_grams', 'stone_grams', 'fine_grams', 'amount'] as $field) {
            $reservedLine[$field] = $line[$field] * $share;
            $line[$field] = $line[$field] - $reservedLine[$field];
        }
        if ($reservedLine['gross_grams'] > 0.00005 || $reservedLine['qty_pieces'] > 0.0005) {
            $lines[] = $reservedLine;
        }
        if ($line['gross_grams'] > 0.00005 || $line['qty_pieces'] > 0.0005) {
            $lines[] = $line;
        }
    }

    return $lines;
}

/**
 * The first year's opening, from where a first year's opening is keyed: the
 * shared item master. Everything is in the showroom — a company cannot begin
 * its books with metal already out with a kaligad it has never issued to.
 */
function jw_ob_lines_initial(int $companyId): array
{
    $stmt = db()->prepare("SELECT i.id, i.opening_qty, i.opening_amount, u.grams, p.fineness
        FROM inventory_items i
        INNER JOIN jewellery_item_profiles j ON j.inventory_item_id = i.id
        INNER JOIN jewellery_units u ON u.id = j.unit_id
        INNER JOIN jewellery_purities p ON p.id = j.purity_id
        WHERE i.company_id = :cid");
    $stmt->execute(['cid' => $companyId]);

    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $gross = (float) $row['opening_qty'];
        $amount = (float) $row['opening_amount'];
        if (abs($gross) < 0.00005 && abs($amount) < 0.005) {
            continue;
        }
        $perUnit = (float) $row['grams'] > 0 ? (float) $row['grams'] : 1.0;
        $grossGrams = $gross * $perUnit;
        $lines[] = [
            'item_id' => (int) $row['id'],
            'holder_type' => 'stock',
            'holder_id' => 0,
            'reserved' => 0,
            'qty_pieces' => 0.0,
            'gross_grams' => $grossGrams,
            'stone_grams' => 0.0,
            'fine_grams' => $grossGrams * ((float) $row['fineness'] / 1000),
            'amount' => $amount,
        ];
    }

    return $lines;
}

/**
 * This year's carried opening, ready to show: names joined on, weights turned
 * back into each item's own unit, and the kaligad or refiner named rather than
 * left as a number.
 */
function jw_ob_rows(int $companyId, int $fiscalYearId): array
{
    if (!jw_ob_ready()) {
        return [];
    }
    $stmt = db()->prepare("SELECT ob.*, i.sku AS item_code, i.name AS item_name, i.category,
            p.code AS purity_code, p.fineness, u.code AS unit_code, u.grams AS unit_grams,
            j.stock_kind
        FROM jewellery_opening_balances ob
        INNER JOIN inventory_items i ON i.id = ob.item_id
        INNER JOIN jewellery_item_profiles j ON j.inventory_item_id = i.id
        INNER JOIN jewellery_purities p ON p.id = j.purity_id
        INNER JOIN jewellery_units u ON u.id = j.unit_id
        WHERE ob.company_id = :cid AND ob.fiscal_year_id = :fy
        ORDER BY i.sku ASC, ob.holder_type ASC, ob.reserved ASC");
    $stmt->execute(['cid' => $companyId, 'fy' => $fiscalYearId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return [];
    }

    // The holders, named. Read once for the whole statement rather than per
    // line — a year end spans every kaligad the shop has ever issued to.
    $karigarNames = [];
    if (table_exists('jewellery_karigars')) {
        $kStmt = db()->prepare('SELECT id, code, name FROM jewellery_karigars WHERE company_id = :cid');
        $kStmt->execute(['cid' => $companyId]);
        foreach ($kStmt->fetchAll(PDO::FETCH_ASSOC) as $karigar) {
            $karigarNames[(int) $karigar['id']] = (string) $karigar['name'] . ' (' . (string) $karigar['code'] . ')';
        }
    }
    $holderLabels = jw_ob_holder_labels();

    $out = [];
    foreach ($rows as $row) {
        $perUnit = (float) $row['unit_grams'] > 0 ? (float) $row['unit_grams'] : 1.0;
        $holderType = (string) $row['holder_type'];
        $holderName = $holderLabels[$holderType] ?? ucfirst($holderType);
        if ($holderType === 'karigar' && isset($karigarNames[(int) $row['holder_id']])) {
            $holderName = $karigarNames[(int) $row['holder_id']];
        }
        if ($holderType === 'stock' && (int) $row['reserved'] === 1) {
            $holderName = 'Showroom — reserved';
        }
        $row['holder_label'] = $holderName;
        $row['gross_weight'] = jw_round_weight((float) $row['gross_grams'] / $perUnit);
        $row['stone_weight'] = jw_round_weight((float) $row['stone_grams'] / $perUnit);
        $row['fine_weight'] = jw_round_weight((float) $row['fine_grams'] / $perUnit);
        $row['rate'] = (float) $row['gross_weight'] > 0
            ? jw_round_rate((float) $row['amount'] / (float) $row['gross_weight'])
            : 0.0;
        $out[] = $row;
    }

    return $out;
}

/**
 * Correct one carried line against a physical count, and post the difference.
 *
 * The carry itself posted nothing, so this is the only thing on this path that
 * reaches the books — and it posts the DIFFERENCE, never the whole figure. The
 * journal is replaceable: adjusting the same line twice corrects the first
 * entry rather than stacking a second, exactly as inv_ob_adjust() does.
 *
 * The metal register is corrected in step with the money, by an adjustment
 * movement dated on the first day of the year, or the two would disagree from
 * the moment the year opened.
 */
function jw_ob_adjust(int $companyId, int $fiscalYearId, int $rowId, array $figures, string $reason, int $userId = 0): array
{
    if (!jw_ob_ready()) {
        return ['ok' => false, 'error' => 'The jewellery opening store is not installed yet.', 'note' => ''];
    }
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Say why the opening is being corrected — an adjustment without a reason cannot be reviewed later.', 'note' => ''];
    }
    if (function_exists('inv_ob_batch_status') && inv_ob_batch_status($companyId, $fiscalYearId) === 'locked') {
        return ['ok' => false, 'error' => 'Opening balances for this year are locked. Unlock them first.', 'note' => ''];
    }
    $rowStmt = db()->prepare('SELECT ob.*, i.sku AS item_code, u.grams AS unit_grams, j.purity_id, j.unit_id, p.fineness
        FROM jewellery_opening_balances ob
        INNER JOIN inventory_items i ON i.id = ob.item_id
        INNER JOIN jewellery_item_profiles j ON j.inventory_item_id = i.id
        INNER JOIN jewellery_purities p ON p.id = j.purity_id
        INNER JOIN jewellery_units u ON u.id = j.unit_id
        WHERE ob.id = :id AND ob.company_id = :cid AND ob.fiscal_year_id = :fy LIMIT 1');
    $rowStmt->execute(['id' => $rowId, 'cid' => $companyId, 'fy' => $fiscalYearId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'That opening line does not belong to this company and year.', 'note' => ''];
    }
    $fyStmt = db()->prepare('SELECT * FROM fiscal_years WHERE id = :id AND company_id = :cid LIMIT 1');
    $fyStmt->execute(['id' => $fiscalYearId, 'cid' => $companyId]);
    $fy = $fyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$fy) {
        return ['ok' => false, 'error' => 'Fiscal year not found for this company.', 'note' => ''];
    }

    $perUnit = (float) $row['unit_grams'] > 0 ? (float) $row['unit_grams'] : 1.0;
    $newGrossGrams = max(0.0, round((float) ($figures['gross_weight'] ?? 0) * $perUnit, 6));
    $newPieces = max(0.0, round((float) ($figures['qty_pieces'] ?? 0), 3));
    $newAmount = max(0.0, jw_round_money((float) ($figures['amount'] ?? 0)));
    $newFineGrams = round($newGrossGrams * ((float) $row['fineness'] / 1000), 6);

    // Measured from what was CARRIED, not from whatever the line says now.
    // Correcting the same line twice replaces the first adjustment, so the
    // second difference has to be the whole distance from the replayed figure
    // or the books drift by the amount of the first correction.
    $baseAmount = (float) $row['carried_amount'];
    $baseGrossGrams = (float) $row['carried_gross_grams'];
    $deltaAmount = jw_round_money($newAmount - $baseAmount);
    $deltaGrossGrams = round($newGrossGrams - $baseGrossGrams, 6);
    $openingDate = (string) $fy['start_date'];

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        db()->prepare("UPDATE jewellery_opening_balances
                SET qty_pieces = :pieces, gross_grams = :gross, fine_grams = :fine, amount = :amount,
                    source = 'adjusted', adjust_reason = :reason, adjusted_by = :by, adjusted_at = NOW()
                WHERE id = :id AND company_id = :cid")
            ->execute([
                'pieces' => $newPieces, 'gross' => $newGrossGrams, 'fine' => $newFineGrams,
                'amount' => $newAmount, 'reason' => mb_substr($reason, 0, 255),
                'by' => $userId ?: null, 'id' => $rowId, 'cid' => $companyId,
            ]);

        // Replace rather than stack: one adjustment per line, whatever the
        // number of times somebody corrects it.
        $existingStmt = db()->prepare("SELECT * FROM vouchers
            WHERE company_id = :cid AND source_type = 'jewellery_opening_adj' AND source_id = :sid LIMIT 1");
        $existingStmt->execute(['cid' => $companyId, 'sid' => $rowId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $blocker = function_exists('voucher_mutation_blocker')
                ? voucher_mutation_blocker($existing, ['jewellery_opening_adj']) : null;
            if ($blocker !== null) {
                throw new RuntimeException('The previous opening adjustment cannot be replaced: ' . $blocker);
            }
            db()->prepare('DELETE FROM vouchers WHERE id = :id AND company_id = :cid')
                ->execute(['id' => (int) $existing['id'], 'cid' => $companyId]);
        }
        db()->prepare("DELETE FROM jewellery_stock_txns
            WHERE company_id = :cid AND source_type = 'jewellery_opening_adj' AND source_id = :sid")
            ->execute(['cid' => $companyId, 'sid' => $rowId]);

        $note = '';
        $voucherId = 0;
        if (abs($deltaGrossGrams) > 0.00005) {
            jw_record_stock_txn($companyId, [
                'item_id' => (int) $row['item_id'],
                'txn_type' => 'adjustment',
                'direction' => $deltaGrossGrams > 0 ? 'in' : 'out',
                'txn_date' => $openingDate,
                'ref_no' => 'OB-ADJ',
                'holder_type' => (string) $row['holder_type'],
                'holder_id' => (int) $row['holder_id'] ?: null,
                'purity_id' => (int) $row['purity_id'],
                'unit_id' => (int) $row['unit_id'],
                'qty_pieces' => 0.0,
                'gross_weight' => abs($deltaGrossGrams) / $perUnit,
                'fine_weight' => abs(round($deltaGrossGrams * ((float) $row['fineness'] / 1000), 6)) / $perUnit,
                'amount' => abs($deltaAmount),
                'source_type' => 'jewellery_opening_adj',
                'source_id' => $rowId,
                'notes' => 'Opening adjusted: ' . mb_substr($reason, 0, 180),
                // The fiscal year is not passed: jw_record_stock_txn() derives
                // it from the movement date, which is the only figure that can
                // be right when a year boundary is what is being recorded.
                'created_by' => $userId,
            ]);
        }
        if (abs($deltaAmount) > 0.004) {
            $itemStmt = db()->prepare('SELECT * FROM inventory_items WHERE id = :id AND company_id = :cid LIMIT 1');
            $itemStmt->execute(['id' => (int) $row['item_id'], 'cid' => $companyId]);
            $item = $itemStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $stockLedgerId = jw_item_stock_ledger_id($companyId, $item);
            $contraId = function_exists('opening_balance_ledger_id') ? opening_balance_ledger_id($companyId) : 0;
            if ($stockLedgerId <= 0 || $contraId <= 0) {
                throw new RuntimeException('Map the item stock ledger and Opening Balance Adjustments before correcting a carried opening.');
            }
            $voucherId = (int) create_voucher_with_entries([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId,
                'voucher_no' => 'JW-OB-ADJ-' . $rowId,
                'voucher_type' => 'journal',
                'voucher_date' => $openingDate,
                'source_type' => 'jewellery_opening_adj',
                'source_id' => $rowId,
                'total_amount' => abs($deltaAmount),
                'narration' => 'Jewellery opening adjustment — ' . (string) $row['item_code'] . ' (' . $reason . ')',
                'status' => 'posted',
                'posted_by' => $userId ?: null,
            ], [
                ['ledger_id' => $stockLedgerId, 'entry_type' => $deltaAmount > 0 ? 'debit' : 'credit',
                    'amount' => abs($deltaAmount), 'memo' => 'Opening stock adjusted'],
                ['ledger_id' => $contraId, 'entry_type' => $deltaAmount > 0 ? 'credit' : 'debit',
                    'amount' => abs($deltaAmount), 'memo' => 'Opening adjustment contra — ' . (string) $row['item_code']],
            ]);
            $note = 'Adjustment journal of ' . number_format(abs($deltaAmount), 2) . ' posted on ' . $openingDate . '.';
        }
        db()->prepare('UPDATE jewellery_opening_balances SET adjustment_voucher_id = :vid WHERE id = :id AND company_id = :cid')
            ->execute(['vid' => $voucherId ?: null, 'id' => $rowId, 'cid' => $companyId]);

        if ($ownsTransaction) {
            db()->commit();
        }

        return ['ok' => true, 'error' => null, 'note' => $note];
    } catch (Throwable $exception) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $exception->getMessage(), 'note' => ''];
    }
}

/**
 * The brought-forward totals, per holder, for the reconciliation strip.
 *
 * The point of showing these apart is that each has a ledger behind it: the
 * showroom against the stock ledgers, a kaligad against his own "Metal with"
 * account. A boundary that does not reconcile is worth seeing on the day it is
 * opened, not at audit.
 */
function jw_ob_totals(array $rows): array
{
    $totals = [];
    foreach ($rows as $row) {
        $key = (string) $row['holder_type'] . ((int) ($row['reserved'] ?? 0) === 1 ? ':reserved' : '');
        if (!isset($totals[$key])) {
            $totals[$key] = ['label' => (string) $row['holder_label'], 'lines' => 0, 'fine_grams' => 0.0, 'amount' => 0.0];
        }
        $totals[$key]['lines']++;
        $totals[$key]['fine_grams'] += (float) $row['fine_grams'];
        $totals[$key]['amount'] += (float) $row['amount'];
    }
    foreach ($totals as $key => $total) {
        $totals[$key]['amount'] = jw_round_money($total['amount']);
    }

    return $totals;
}
