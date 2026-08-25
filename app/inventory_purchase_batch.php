<?php
declare(strict_types=1);

/**
 * Recording several purchases at once.
 *
 * A supplier's bill is rarely one line. Entering it one item at a time meant
 * re-choosing the supplier, the date and the VAT treatment for every row of the
 * same invoice, and there was nothing tying those rows together afterwards.
 *
 * This takes the whole grid and posts it in ONE transaction: every row becomes
 * the same stock movement and the same GL voucher the single-row form has
 * always made — the engine calls below are the identical ones — but either all
 * of them land or none of them do. A bill half-entered is worse than a bill not
 * entered, because the half that got in looks like a complete record.
 *
 * Rows are validated as a set BEFORE anything is written, so a mistake on the
 * last line is reported while the first is still on the screen rather than
 * after eleven of them have already posted.
 */

require_once __DIR__ . '/inventory_valuation.php';

/** The movement types this grid records — stock coming IN, and what it cost. */
function inv_purchase_batch_types(): array
{
    return [
        'purchase' => 'Purchase',
        'opening' => 'Opening stock',
        'purchase_return' => 'Purchase return',
    ];
}

/**
 * How VAT is treated on a purchase line.
 *
 * "Custom" is the escape hatch for the rates that turn up on a real bill
 * without being anybody's standard — a partial exemption, a rounded figure the
 * supplier actually charged.
 */
function inv_purchase_vat_modes(): array
{
    return [
        'standard' => ['label' => '13% (standard)', 'rate' => 13.0],
        'zero' => ['label' => '0% (zero-rated)', 'rate' => 0.0],
        'exempt' => ['label' => 'Exempted', 'rate' => 0.0],
        'custom' => ['label' => 'Custom rate…', 'rate' => null],
    ];
}

/**
 * A bill, flattened into the per-item rows the engine posts.
 *
 * A supplier's invoice is one date, one movement, one bill number and one
 * supplier, with several items under it. The form is built that way -- header
 * once, items in a popup -- because that is how the paper reads, and asking for
 * the supplier again on every line is how a bill ends up split across two
 * accounts by a mis-click.
 *
 * What VARIES per item is what genuinely varies: quantity, rate, whether it
 * carries VAT, whether tax is withheld on it, and whether it is also a kitchen
 * ingredient. A bill of milk and mobile data has one exempt line and one
 * standard line, and no amount of header-level VAT would describe it.
 *
 * The engine below is untouched: it still receives flat rows, each carrying its
 * own copy of the header. This is only the fold-out.
 */
function inv_purchase_bills_to_rows(array $bills): array
{
    $rows = [];
    foreach ($bills as $billIndex => $bill) {
        $items = (array) ($bill['items'] ?? []);
        if ($items === []) {
            continue;
        }
        // Everything the whole bill shares, copied onto each of its lines.
        $header = [
            // Which bill on the form this line came off. Carried all the way
            // through to posting, where it is what puts every line of one
            // invoice into ONE entry — the form already knows the boundary, so
            // nothing downstream has to guess it back out of the reference.
            'bill_index' => (string) $billIndex,
            'transaction_date' => (string) ($bill['transaction_date'] ?? ''),
            'supplier_invoice_date' => (string) ($bill['supplier_invoice_date'] ?? ''),
            'movement' => (string) ($bill['movement'] ?? 'purchase'),
            'ref_no' => (string) ($bill['ref_no'] ?? ''),
            'supplier_party_id' => (int) ($bill['supplier_party_id'] ?? 0),
            'vat_ledger_id' => (int) ($bill['vat_ledger_id'] ?? 0),
            'tds_ledger_id' => (int) ($bill['tds_ledger_id'] ?? 0),
            'warehouse_id' => (int) ($bill['warehouse_id'] ?? 0),
        ];
        foreach ($items as $item) {
            // A line nobody filled in is a spare, not a mistake.
            if ((int) ($item['item_id'] ?? 0) <= 0
                && (float) ($item['quantity'] ?? 0) <= 0
                && (float) ($item['rate'] ?? 0) <= 0) {
                continue;
            }
            $rows[] = $header + [
                'item_id' => (int) ($item['item_id'] ?? 0),
                'quantity' => $item['quantity'] ?? 0,
                'rate' => $item['rate'] ?? 0,
                'vat_applicable' => $item['vat_applicable'] ?? '0',
                'vat_rate' => (string) ($item['vat_rate'] ?? ''),
                'vat_amount' => (string) ($item['vat_amount'] ?? ''),
                'tds_applicable' => $item['tds_applicable'] ?? '0',
                'tds_rate' => (string) ($item['tds_rate'] ?? ''),
                'tds_base' => (string) ($item['tds_base'] ?? ''),
                'mark_ingredient' => $item['mark_ingredient'] ?? '',
                // The line's own note if it has one, else the bill's.
                'notes' => trim((string) ($item['notes'] ?? '')) !== ''
                    ? (string) $item['notes']
                    : (string) ($bill['notes'] ?? ''),
            ];
        }
    }

    return $rows;
}

/**
 * Check every row of the grid, resolving items and suppliers in two queries
 * rather than two per row.
 *
 * Returns ['rows' => [...], 'errors' => [...], 'valid' => int]. A row carries
 * its own 'errors' list; the top-level one holds what stops the whole batch.
 */
function inv_purchase_batch_validate(int $companyId, int $fiscalYearId, array $rawRows): array
{
    $errors = [];
    $rows = [];

    // Everything the grid refers to, read once.
    $itemIds = [];
    $partyIds = [];
    foreach ($rawRows as $raw) {
        $itemId = (int) ($raw['item_id'] ?? 0);
        if ($itemId > 0) {
            $itemIds[$itemId] = true;
        }
        $partyId = (int) ($raw['supplier_party_id'] ?? 0);
        if ($partyId > 0) {
            $partyIds[$partyId] = true;
        }
    }
    $items = [];
    if ($itemIds !== []) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = db()->prepare("SELECT * FROM inventory_items WHERE company_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$companyId], array_keys($itemIds)));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $items[(int) $item['id']] = $item;
        }
    }
    $parties = [];
    if ($partyIds !== []) {
        $placeholders = implode(',', array_fill(0, count($partyIds), '?'));
        $stmt = db()->prepare("SELECT id, name FROM accounting_parties WHERE company_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$companyId], array_keys($partyIds)));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $party) {
            $parties[(int) $party['id']] = $party;
        }
    }

    $types = inv_purchase_batch_types();
    $vatModes = inv_purchase_vat_modes();
    $valid = 0;
    // A bill is nearly always one date across every line, so the fiscal year
    // and its posting lock are resolved per DATE rather than per row. Asking
    // per row cost a query a line — a sixty-line bill spent sixty round trips
    // establishing the same answer sixty times.
    $periodByDate = [];

    foreach (array_values($rawRows) as $index => $raw) {
        $rowErrors = [];
        $lineNo = $index + 1;

        $itemId = (int) ($raw['item_id'] ?? 0);
        $qty = round(abs((float) ($raw['quantity'] ?? 0)), 3);
        $rate = round((float) ($raw['rate'] ?? 0), 2);

        // A row nobody filled in is not an error — the grid ships with spare
        // lines and most bills do not use all of them.
        if ($itemId <= 0 && $qty <= 0 && $rate <= 0) {
            continue;
        }

        $item = $items[$itemId] ?? null;
        if ($item === null) {
            $rowErrors[] = 'Choose an item.';
        }
        $type = (string) ($raw['movement'] ?? 'purchase');
        if (!isset($types[$type])) {
            $rowErrors[] = 'Choose a movement type.';
            $type = 'purchase';
        }
        if ($qty <= 0) {
            $rowErrors[] = 'Quantity must be greater than zero.';
        }
        if ($rate < 0) {
            $rowErrors[] = 'Rate cannot be negative.';
        }

        $date = inventory_valid_date((string) ($raw['transaction_date'] ?? '')) ?? '';
        if ($date === '') {
            $rowErrors[] = 'Give a posting date (YYYY-MM-DD).';
        } elseif (table_exists('fiscal_years')) {
            if (!array_key_exists($date, $periodByDate)) {
                $rowFiscalYear = fiscal_year_for_date($companyId, $date);
                $periodByDate[$date] = $rowFiscalYear
                    ? fiscal_year_posting_blocker($rowFiscalYear, $date)
                    : 'No fiscal year covers ' . $date . '.';
            }
            if ($periodByDate[$date] !== null) {
                $rowErrors[] = (string) $periodByDate[$date];
            }
        }
        // The supplier's own invoice date is a record of THEIR paperwork; it is
        // not what the entry posts on, so it is allowed to sit outside the
        // period without stopping anything.
        $supplierDate = inventory_valid_date((string) ($raw['supplier_invoice_date'] ?? '')) ?? null;

        $amount = round($qty * $rate, 2);

        // The grid asks the question as a tick, because that is how a bill
        // reads: nearly every line carries VAT, and the exempt ones are the
        // exception worth un-ticking. A rate typed beside the tick overrides
        // the standard one, which is what a partial exemption needs.
        if (array_key_exists('vat_applicable', $raw)) {
            $typedRate = trim((string) ($raw['vat_rate'] ?? ''));
            if (empty($raw['vat_applicable'])) {
                $vatMode = 'exempt';
            } elseif ($typedRate !== '') {
                $vatMode = 'custom';
            } else {
                $vatMode = 'standard';
            }
        } else {
            $vatMode = (string) ($raw['vat_mode'] ?? 'standard');
        }
        if (!isset($vatModes[$vatMode])) {
            $vatMode = 'standard';
        }
        if ($vatMode === 'custom') {
            $vatRate = max(0.0, min(100.0, (float) ($raw['vat_rate'] ?? 0)));
        } else {
            $vatRate = (float) $vatModes[$vatMode]['rate'];
        }
        // A figure typed straight into the VAT column wins over the rate: it is
        // what the supplier actually charged, rounding and all.
        $vatTyped = trim((string) ($raw['vat_amount'] ?? ''));
        $vat = $vatTyped !== ''
            ? max(0.0, round((float) $vatTyped, 2))
            : round($amount * $vatRate / 100, 2);
        if ($vatMode === 'exempt' && $vat > 0) {
            $rowErrors[] = 'An exempted line cannot carry VAT.';
        }

        $partyId = (int) ($raw['supplier_party_id'] ?? 0);
        if ($partyId > 0 && !isset($parties[$partyId])) {
            $rowErrors[] = 'That supplier is not in this company.';
            $partyId = 0;
        }
        if ($partyId > 0 && !in_array($type, ['purchase', 'purchase_return'], true)) {
            // Opening stock is not owed to anybody; it is the position the
            // company started from.
            $partyId = 0;
        }

        $tdsBase = max(0.0, round((float) ($raw['tds_base'] ?? 0), 2));
        $tdsRate = max(0.0, min(100.0, (float) ($raw['tds_rate'] ?? 0)));
        // TDS is a tick too, but the other way round: most lines have none, so
        // it is off unless somebody says otherwise. Un-ticked means no
        // withholding whatever rate is sitting in the box.
        if (array_key_exists('tds_applicable', $raw) && empty($raw['tds_applicable'])) {
            $tdsRate = 0.0;
            $tdsBase = 0.0;
        }
        if ($tdsRate > 0 && $tdsBase <= 0) {
            // The whole line is the usual base, so an omitted one is filled in
            // rather than refused.
            $tdsBase = $amount;
        }

        if ($rowErrors !== []) {
            $rows[] = ['line' => $lineNo, 'errors' => $rowErrors] + $raw;
            continue;
        }

        $valid++;
        $rows[] = [
            'line' => $lineNo,
            'errors' => [],
            'bill_index' => (string) ($raw['bill_index'] ?? ''),
            'item_id' => $itemId,
            'item' => $item,
            'movement' => $type,
            'transaction_date' => $date,
            'supplier_invoice_date' => $supplierDate,
            'warehouse_id' => inventory_company_warehouse_id((int) ($raw['warehouse_id'] ?? 0), $companyId),
            'ref_no' => mb_substr(trim((string) ($raw['ref_no'] ?? '')), 0, 80) ?: null,
            'quantity' => $qty,
            'rate' => $rate,
            'amount' => $amount,
            'vat_mode' => $vatMode,
            'vat_rate' => $vatRate,
            'vat_amount' => $vat,
            'supplier_party_id' => $partyId,
            'vat_ledger_id' => (int) ($raw['vat_ledger_id'] ?? 0),
            'tds_base' => $tdsBase,
            'tds_rate' => $tdsRate,
            'tds_ledger_id' => (int) ($raw['tds_ledger_id'] ?? 0),
            'notes' => mb_substr(trim((string) ($raw['notes'] ?? '')), 0, 255) ?: null,
            'mark_ingredient' => !empty($raw['mark_ingredient']),
        ];
    }

    if ($valid === 0 && $errors === []) {
        $errors[] = 'Nothing to record — fill in at least one line.';
    }

    return ['rows' => $rows, 'errors' => $errors, 'valid' => $valid];
}

/**
 * Which BILL a validated row belongs to.
 *
 * One supplier invoice is one entry. The form knows where each bill ends, so
 * `bill_index` is the answer whenever it is there; the older flat `rows[]`
 * submission has no such marker, and for that the invoice number, the
 * supplier, the movement and the date together are what make a bill a bill.
 *
 * A blank reference does NOT collapse two different suppliers' bills into one:
 * the supplier is part of the key, and the date with it.
 */
function inv_purchase_bill_key(array $row): string
{
    if ((string) ($row['bill_index'] ?? '') !== '') {
        return 'form:' . (string) $row['bill_index'];
    }

    return 'ref:' . mb_strtolower(trim((string) ($row['ref_no'] ?? '')))
        . '|' . (int) ($row['supplier_party_id'] ?? 0)
        . '|' . (string) ($row['movement'] ?? '')
        . '|' . (string) ($row['transaction_date'] ?? '');
}

/**
 * One bill, as the balanced entry it should always have been.
 *
 * A supplier's invoice for twelve items used to become twelve vouchers, each
 * Dr Inventory / Cr GRNI, because the voucher was raised per stock movement
 * and keyed to it. That is not what the paper says and it is not what the
 * supplier is owed: the bill is ONE liability, and the register should show
 * one entry against it that a person can read and tie to the invoice.
 *
 * So the stock side keeps its per-item detail — one debit line per item,
 * memo-ed with the code, the quantity and the rate, each posting to that
 * item's OWN inventory ledger, which is why they are not summed — while VAT,
 * the withholding and what the supplier is owed appear once, as they do on the
 * invoice.
 *
 *   Dr  Inventory Asset   750.00   K15 Spring Garlic 3.000 @ 250.00
 *   Dr  Inventory Asset   540.00   K14 Spring Onion  2.700 @ 200.00
 *   Dr  VAT on purchase   167.70   recoverable, not part of cost
 *     Cr  Anaya Suppliers        1,457.70
 *
 * A purchase RETURN is the same entry with every side swapped, which is why
 * the sides are read off the posting matrix rather than written out twice.
 *
 * @return array{lines:array, total:float, net:float, vat:float, tds:float}
 * @throws RuntimeException MAP_MISSING:<purposes> when a ledger is not mapped.
 */
function inv_purchase_bill_entry_lines(int $companyId, array $group, int $partyId): array
{
    $first = reset($group);
    $type = (string) $first['movement'];
    $direction = inventory_direction($type);
    $plan = inv_movement_posting_plan($type, $direction);
    if ($plan === null) {
        throw new RuntimeException('There is no posting rule for a ' . $type . '.');
    }
    // The stock leg is whichever side of the plan inventory sits on; the
    // counterparty is the other one. Reading them off the matrix is what makes
    // a return the mirror of a purchase without a second code path.
    $stockSide = $direction === 'in' ? 'debit' : 'credit';
    $counterSide = $direction === 'in' ? 'credit' : 'debit';
    $stockPurpose = $direction === 'in' ? $plan['debit'] : $plan['credit'];
    $counterPurpose = $direction === 'in' ? $plan['credit'] : $plan['debit'];

    $missing = [];
    $lines = [];
    $net = 0.0;
    $vat = 0.0;
    $tds = 0.0;
    $vatLedgerId = 0;
    $tdsLedgerId = 0;

    foreach ($group as $row) {
        $item = $row['item'];
        $stock = inv_resolve_mapping($companyId, $stockPurpose, (int) $row['item_id'], $item['category'] ?? null);
        if (!$stock) {
            $missing[$stockPurpose] = true;
            continue;
        }
        $amount = inv_round_money((float) $row['amount']);
        $net += $amount;
        $vat += inv_round_money((float) $row['vat_amount']);
        $tds += tds_from_rate((float) $row['tds_base'] > 0 ? (float) $row['tds_base'] : $amount, (float) $row['tds_rate']);
        $vatLedgerId = $vatLedgerId ?: (int) $row['vat_ledger_id'];
        $tdsLedgerId = $tdsLedgerId ?: (int) $row['tds_ledger_id'];
        $lines[] = [
            'ledger_id' => (int) $stock['id'],
            'entry_type' => $stockSide,
            'amount' => $amount,
            'memo' => trim((string) $item['sku'] . ' ' . (string) $item['name']) . ' — '
                . number_format((float) $row['quantity'], 3) . ' @ ' . number_format((float) $row['rate'], 2),
        ];
    }

    // The counterparty: this supplier's own payable when there is one, because
    // every bill can owe a different supplier, and the shared clearing account
    // only when there is not.
    $counter = null;
    if ($partyId > 0 && $counterPurpose === 'purchase_clearing') {
        $partyLedgerId = ensure_party_ledger($companyId, $partyId, 'payable');
        if ($partyLedgerId > 0) {
            $partyStmt = db()->prepare('SELECT * FROM ledgers WHERE id = :id AND company_id = :cid LIMIT 1');
            $partyStmt->execute(['id' => $partyLedgerId, 'cid' => $companyId]);
            $counter = $partyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
    if (!$counter) {
        $counter = inv_resolve_mapping($companyId, $counterPurpose, (int) $first['item_id'], $first['item']['category'] ?? null);
    }
    if (!$counter) {
        $missing[$counterPurpose] = true;
    }
    if ($missing !== []) {
        throw new RuntimeException('MAP_MISSING:' . implode(',', array_keys($missing)));
    }

    $net = inv_round_money($net);
    $vat = inv_round_money($vat);
    $tds = inv_round_money($tds);
    if ($vat > 0 && $vatLedgerId <= 0) {
        throw new RuntimeException('This bill carries VAT but no VAT-on-purchase ledger was chosen.');
    }
    if ($tds > 0 && $tdsLedgerId <= 0) {
        throw new RuntimeException('This bill withholds tax but no TDS-deducted ledger was chosen.');
    }
    if ($tds > inv_round_money($net + $vat)) {
        throw new RuntimeException('The tax withheld on this bill is more than the bill itself.');
    }

    // VAT rides with the stock side (a purchase reclaims it, a return gives it
    // back); the withholding rides with the supplier's side, because it is
    // money kept back OUT of what is owed.
    if ($vat > 0) {
        $lines[] = ['ledger_id' => $vatLedgerId, 'entry_type' => $stockSide, 'amount' => $vat,
            'memo' => 'VAT on purchase — recoverable, not part of cost'];
    }
    $lines[] = ['ledger_id' => (int) $counter['id'], 'entry_type' => $counterSide, 'amount' => inv_round_money($net + $vat - $tds)];
    if ($tds > 0) {
        $lines[] = ['ledger_id' => $tdsLedgerId, 'entry_type' => $counterSide, 'amount' => $tds,
            'memo' => 'TDS withheld on purchase'];
    }

    return ['lines' => $lines, 'total' => inv_round_money($net + $vat), 'net' => $net, 'vat' => $vat, 'tds' => $tds];
}

/**
 * Post a validated grid.
 *
 * Stock is still recorded one movement per item — that is what an item ledger
 * is — but the ACCOUNTING is raised once per bill. Twelve items off one
 * invoice used to leave twelve vouchers in the register, each Dr Inventory /
 * Cr GRNI, and nothing tying them together except a reference typed on all of
 * them; the supplier was owed twelve times over in twelve places.
 *
 * Now every movement of a bill carries the same `voucher_id`, and that one
 * voucher holds a debit line per item. The link that matters is
 * inventory_transactions.voucher_id, which is many-to-one and which the
 * voucher-deletion cascade already unlinks by voucher; the voucher's own
 * `source_id` names the movement the bill was opened with, so the unique key
 * on (source_type, source_id) still holds one row per bill.
 *
 * Either the whole grid lands or none of it does. A bill half-entered is worse
 * than a bill not entered, because the half that got in looks complete.
 *
 * Returns ['ok' => bool, 'error' => ?string, 'posted' => int, 'bills' => int,
 *          'lines' => [...]].
 */
function inv_purchase_batch_post(int $companyId, int $fiscalYearId, array $validated, int $userId): array
{
    if ($validated['errors'] !== []) {
        return ['ok' => false, 'error' => implode(' ', $validated['errors']), 'posted' => 0, 'bills' => 0, 'lines' => []];
    }
    $bad = array_filter($validated['rows'], static fn (array $r): bool => $r['errors'] !== []);
    if ($bad !== []) {
        $first = reset($bad);

        return [
            'ok' => false,
            'posted' => 0,
            'bills' => 0,
            'lines' => [],
            'error' => count($bad) . ' line(s) still have errors — line ' . $first['line'] . ': ' . implode(' ', $first['errors']),
        ];
    }
    $rows = array_values(array_filter($validated['rows'], static fn (array $r): bool => $r['errors'] === []));
    if ($rows === []) {
        return ['ok' => false, 'error' => 'Nothing to record.', 'posted' => 0, 'bills' => 0, 'lines' => []];
    }

    // One supplier invoice, one entry. The form already knows where each bill
    // ends, so the lines are gathered back into bills before anything is
    // written rather than being guessed at afterwards.
    $bills = [];
    foreach ($rows as $row) {
        $bills[inv_purchase_bill_key($row)][] = $row;
    }

    $lines = [];
    $ingredientItems = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $insertTxn = $pdo->prepare('
            INSERT INTO inventory_transactions (
                company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date,
                warehouse_id, qty_in, qty_out, rate, amount, notes
            ) VALUES (
                :company_id, :fiscal_year_id, :item_id, :transaction_type, :ref_no, :transaction_date,
                :warehouse_id, :qty_in, :qty_out, :rate, :amount, :notes
            )
        ');
        $linkVoucher = $pdo->prepare('UPDATE inventory_transactions SET voucher_id = :vid WHERE id = :id AND company_id = :cid');

        foreach ($bills as $group) {
            $head = reset($group);
            $type = (string) $head['movement'];
            $direction = inventory_direction($type);
            $partyId = (int) ($head['supplier_party_id'] ?? 0);
            $billDate = (string) $head['transaction_date'];
            $refNo = (string) ($head['ref_no'] ?? '');

            // Every item of the bill moves stock first, exactly as it always
            // did — the item ledger is per item and stays that way.
            $billTxnIds = [];
            foreach ($group as $row) {
                $item = $row['item'];
                $qtyIn = $direction === 'in' ? $row['quantity'] : 0.0;
                $qtyOut = $direction === 'in' ? 0.0 : $row['quantity'];

                $insertTxn->execute([
                    'company_id' => $companyId,
                    'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                    'item_id' => (int) $row['item_id'],
                    'transaction_type' => $type,
                    'ref_no' => $row['ref_no'],
                    'transaction_date' => $row['transaction_date'],
                    'warehouse_id' => $row['warehouse_id'],
                    'qty_in' => $qtyIn,
                    'qty_out' => $qtyOut,
                    'rate' => $row['rate'],
                    'amount' => $row['amount'],
                    'notes' => $row['notes'],
                ]);
                $txnId = (int) $pdo->lastInsertId();
                $billTxnIds[] = $txnId;

                inv_apply_movement($companyId, (int) $row['item_id'], $qtyIn, $qtyOut, $row['rate'],
                    $row['transaction_date'], (string) ($item['valuation_method'] ?? 'weighted_average'),
                    $txnId, $row['warehouse_id']);

                if ($row['mark_ingredient'] && column_exists('inventory_items', 'is_ingredient')) {
                    $ingredientItems[] = (int) $row['item_id'];
                }
            }

            // Then ONE entry for the whole bill.
            $voucherId = 0;
            $mapMissing = [];
            try {
                $built = inv_purchase_bill_entry_lines($companyId, $group, $partyId);
                // Bought-in stock is prepared as a draft so the entry can be
                // read before it counts. A draft holds no number out of the
                // series, so one never posted leaves no gap in it.
                $isDraft = in_array($type, ['purchase', 'opening'], true) && $direction === 'in';
                $sourceTxnId = $billTxnIds[0];
                $voucherId = (int) create_voucher_with_entries([
                    'company_id' => $companyId,
                    'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                    'voucher_no' => ($isDraft ? 'INV-DRAFT-' : 'INV-' . strtoupper($type) . '-')
                        . str_pad((string) $sourceTxnId, 6, '0', STR_PAD_LEFT),
                    'voucher_type' => $isDraft ? 'purchase' : 'journal',
                    'voucher_date' => $billDate,
                    'reference_no' => $refNo !== '' ? $refNo : null,
                    'source_type' => 'inventory_movement',
                    'source_id' => $sourceTxnId,
                    'party_id' => $partyId ?: null,
                    'total_amount' => $built['total'],
                    'narration' => ucfirst(str_replace('_', ' ', $type))
                        . ($refNo !== '' ? ' — bill ' . $refNo : '')
                        . ' — ' . count($group) . ' item(s)',
                    'status' => $isDraft ? 'draft' : 'posted',
                    'posted_by' => $isDraft ? null : $userId,
                ], $built['lines']);
                if ($voucherId > 0) {
                    $pdo->prepare('UPDATE vouchers SET posting_date = :d'
                        . ($isDraft ? ', posted_by = NULL, posted_at = NULL' : '') . ' WHERE id = :id')
                        ->execute(['d' => $billDate, 'id' => $voucherId]);
                }
            } catch (RuntimeException $mapEx) {
                if (str_starts_with($mapEx->getMessage(), 'MAP_MISSING:')) {
                    // Stock is recorded and the gap is reported; the bill can
                    // be given its entry once the ledgers are mapped.
                    $mapMissing = explode(',', substr($mapEx->getMessage(), 12));
                } else {
                    throw $mapEx;
                }
            }
            if ($voucherId > 0) {
                foreach ($billTxnIds as $txnId) {
                    $linkVoucher->execute(['vid' => $voucherId, 'id' => $txnId, 'cid' => $companyId]);
                }
            }

            foreach ($group as $index => $row) {
                $lines[] = [
                    'line' => $row['line'],
                    'txn_id' => $billTxnIds[$index] ?? 0,
                    'voucher_id' => $voucherId,
                    'item_name' => (string) $row['item']['name'],
                    'amount' => $row['amount'],
                    'vat' => $row['vat_amount'],
                    'map_missing' => $mapMissing,
                ];
            }
        }

        // One statement for the whole grid, not one per ticked row.
        if ($ingredientItems !== []) {
            $placeholders = implode(',', array_fill(0, count($ingredientItems), '?'));
            $pdo->prepare("UPDATE inventory_items SET is_ingredient = 1 WHERE company_id = ? AND id IN ($placeholders)")
                ->execute(array_merge([$companyId], $ingredientItems));
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => 'Nothing was recorded: ' . $exception->getMessage(), 'posted' => 0, 'bills' => 0, 'lines' => []];
    }

    // The kitchen list is refreshed after the commit — failing to do so must
    // not undo a bill that is already correctly in the books.
    $ingredientsAdded = 0;
    if ($ingredientItems !== [] && function_exists('hospitality_sync_ingredients_from_inventory')) {
        try {
            $sync = hospitality_sync_ingredients_from_inventory($companyId, $userId);
            $ingredientsAdded = (int) $sync['created'];
        } catch (Throwable $ignored) {
            $ingredientsAdded = 0;
        }
    }

    log_activity('inventory_item', $companyId, 'purchase_batch',
        count($lines) . ' purchase line(s) recorded as ' . count($bills) . ' bill entry(ies).', $userId);
    if (function_exists('security_event')) {
        security_event('inventory_movement_posted', 'success',
            count($lines) . ' inventory movement(s) posted as ' . count($bills) . ' purchase entry(ies).', $companyId, $userId);
    }

    return [
        'ok' => true,
        'error' => null,
        'posted' => count($lines),
        'bills' => count($bills),
        'lines' => $lines,
        'ingredients_added' => $ingredientsAdded,
    ];
}

// ---------------------------------------------------------------------------
// A bill after it is entered: reading it back, removing it, and gathering up
// the ones that were split before this file knew how to keep them together.
// ---------------------------------------------------------------------------

/** The movement types that make a purchase entry. */
function inv_purchase_bill_movement_types(): array
{
    return ['purchase', 'opening', 'purchase_return'];
}

/**
 * The purchase entries this company has prepared or posted, one row per BILL,
 * drafts first because they are the ones still waiting on somebody.
 *
 * Each bill carries the items it bought and the entry it raised, so the
 * register can be read against the invoice without opening anything.
 */
function inv_purchase_bill_list(int $companyId, int $limit = 25): array
{
    $types = inv_purchase_bill_movement_types();
    $typePh = implode(',', array_fill(0, count($types), '?'));
    $stmt = db()->prepare("
        SELECT v.id, v.voucher_no, v.status, v.voucher_date, v.posting_date, v.total_amount,
               v.reference_no, v.party_id, v.narration, p.name AS party_name
        FROM vouchers v
        LEFT JOIN accounting_parties p ON p.id = v.party_id AND p.company_id = v.company_id
        WHERE v.company_id = ? AND v.source_type = 'inventory_movement'
          AND EXISTS (
              SELECT 1 FROM inventory_transactions t
              WHERE t.voucher_id = v.id AND t.company_id = v.company_id
                AND t.transaction_type IN ($typePh)
          )
        ORDER BY (v.status = 'draft') DESC, v.id DESC
        LIMIT " . max(1, min(200, $limit)));
    $stmt->execute(array_merge([$companyId], $types));
    $bills = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['items'] = [];
        $row['lines'] = [];
        $row['item_count'] = 0;
        $bills[(int) $row['id']] = $row;
    }
    if ($bills === []) {
        return [];
    }
    $ids = array_keys($bills);
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $itemStmt = db()->prepare("SELECT t.id, t.voucher_id, t.item_id, t.transaction_type, t.transaction_date,
            t.qty_in, t.qty_out, t.rate, t.amount, t.ref_no, i.sku, i.name AS item_name, i.unit
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id
        WHERE t.company_id = ? AND t.voucher_id IN ($ph) ORDER BY t.id ASC");
    $itemStmt->execute(array_merge([$companyId], $ids));
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $bills[(int) $item['voucher_id']]['items'][] = $item;
    }

    $lineStmt = db()->prepare("SELECT e.voucher_id, e.entry_type, e.amount, e.memo, l.code AS ledger_code, l.name AS ledger_name
        FROM voucher_entries e INNER JOIN ledgers l ON l.id = e.ledger_id
        WHERE e.voucher_id IN ($ph) ORDER BY e.id ASC");
    $lineStmt->execute($ids);
    foreach ($lineStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $bills[(int) $line['voucher_id']]['lines'][] = $line;
    }
    foreach ($bills as $id => $bill) {
        $bills[$id]['item_count'] = count($bill['items']);
    }

    return array_values($bills);
}

/** One bill by its voucher id, or null when it is not this company's. */
function inv_purchase_bill_load(int $companyId, int $voucherId): ?array
{
    if ($voucherId <= 0) {
        return null;
    }
    $stmt = db()->prepare("SELECT v.*, p.name AS party_name FROM vouchers v
        LEFT JOIN accounting_parties p ON p.id = v.party_id AND p.company_id = v.company_id
        WHERE v.id = :id AND v.company_id = :cid AND v.source_type = 'inventory_movement' LIMIT 1");
    $stmt->execute(['id' => $voucherId, 'cid' => $companyId]);
    $bill = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bill) {
        return null;
    }
    $itemStmt = db()->prepare("SELECT t.*, i.sku, i.name AS item_name, i.unit, i.category
        FROM inventory_transactions t INNER JOIN inventory_items i ON i.id = t.item_id
        WHERE t.company_id = :cid AND t.voucher_id = :vid ORDER BY t.id ASC");
    $itemStmt->execute(['cid' => $companyId, 'vid' => $voucherId]);
    $bill['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($bill['items'] === []) {
        return null; // not a purchase entry — some other module's voucher
    }
    $lineStmt = db()->prepare('SELECT e.*, l.code AS ledger_code, l.name AS ledger_name
        FROM voucher_entries e INNER JOIN ledgers l ON l.id = e.ledger_id
        WHERE e.voucher_id = :vid ORDER BY e.id ASC');
    $lineStmt->execute(['vid' => $voucherId]);
    $bill['lines'] = $lineStmt->fetchAll(PDO::FETCH_ASSOC);

    return $bill;
}

/**
 * Remove a bill outright: its entry, its lines, its stock, and the value the
 * stock was carrying.
 *
 * A posted bill is unposted on the way out rather than being reversed. These
 * are draft-then-post purchase entries, and the thing that goes wrong with
 * them is a typo — a reversal would leave three vouchers behind where the
 * honest answer is none. What is removed is recorded in the activity log and
 * the security log, with the voucher number it used to carry, so the gap in
 * the series has a reason attached to it.
 *
 * The cost layers of every item on the bill are rebuilt afterwards, so the
 * valuation is what it would have been had the bill never been entered.
 *
 * @return array{ok:bool, error:?string, voucher_no:string, items:int, amount:float}
 */
function inv_purchase_bill_delete(int $companyId, int $voucherId, int $userId): array
{
    $bill = inv_purchase_bill_load($companyId, $voucherId);
    if (!$bill) {
        return ['ok' => false, 'error' => 'That purchase entry was not found for this company.', 'voucher_no' => '', 'items' => 0, 'amount' => 0.0];
    }
    $billDate = (string) ($bill['voucher_date'] ?? date('Y-m-d'));
    if (function_exists('is_period_locked') && is_period_locked($companyId, (int) ($bill['fiscal_year_id'] ?? 0), $billDate)) {
        return ['ok' => false, 'error' => 'This bill is dated ' . $billDate . ', which is inside a locked accounting period.',
            'voucher_no' => (string) $bill['voucher_no'], 'items' => count($bill['items']), 'amount' => (float) $bill['total_amount']];
    }
    // Stock that has already been drawn on cannot be un-bought: taking the
    // purchase away would leave the issues that consumed it costed against a
    // layer that no longer exists. Say so rather than quietly rebuilding into
    // a negative position.
    foreach ($bill['items'] as $item) {
        if (in_array((string) $item['transaction_type'], ['purchase', 'opening'], true)) {
            $laterStmt = db()->prepare("SELECT COUNT(*) FROM inventory_transactions
                WHERE company_id = :cid AND item_id = :iid AND qty_out > 0
                  AND (transaction_date > :d OR (transaction_date = :d2 AND id > :tid))");
            $laterStmt->execute(['cid' => $companyId, 'iid' => (int) $item['item_id'],
                'd' => (string) $item['transaction_date'], 'd2' => (string) $item['transaction_date'], 'tid' => (int) $item['id']]);
            if ((int) $laterStmt->fetchColumn() > 0) {
                $onHand = inv_item_warehouse_qty($companyId, (int) $item['item_id'], null);
                if ($onHand + 0.0005 < (float) $item['qty_in']) {
                    return ['ok' => false, 'voucher_no' => (string) $bill['voucher_no'],
                        'items' => count($bill['items']), 'amount' => (float) $bill['total_amount'],
                        'error' => $item['sku'] . ' has already been issued out of this purchase — only '
                            . number_format($onHand, 3) . ' of the ' . number_format((float) $item['qty_in'], 3)
                            . ' bought is still on hand. Reverse the issues first, or record a purchase return instead of deleting the bill.'];
                }
            }
        }
    }

    $itemIds = array_values(array_unique(array_map(static fn (array $i): int => (int) $i['item_id'], $bill['items'])));
    $voucherNo = (string) $bill['voucher_no'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($bill['items'] as $item) {
            // A deleted movement whose allowance row survived would keep
            // counting toward the standing allowance forever.
            inv_void_allowance_rows_for_txn($companyId, (int) $item['id'], (int) ($bill['fiscal_year_id'] ?? 0) ?: null, date('Y-m-d'), $userId);
        }
        $pdo->prepare('DELETE FROM inventory_transactions WHERE company_id = :cid AND voucher_id = :vid')
            ->execute(['cid' => $companyId, 'vid' => $voucherId]);
        $pdo->prepare('DELETE FROM voucher_entries WHERE voucher_id = :vid')->execute(['vid' => $voucherId]);
        $pdo->prepare('DELETE FROM vouchers WHERE id = :vid AND company_id = :cid')->execute(['vid' => $voucherId, 'cid' => $companyId]);
        foreach ($itemIds as $itemId) {
            inv_rebuild_item($companyId, $itemId);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => 'Nothing was removed: ' . $exception->getMessage(),
            'voucher_no' => $voucherNo, 'items' => count($bill['items']), 'amount' => (float) $bill['total_amount']];
    }

    $detail = 'Purchase entry ' . ($voucherNo !== '' ? $voucherNo : '(draft)')
        . ($bill['reference_no'] ? ' — bill ' . (string) $bill['reference_no'] : '')
        . ' deleted: ' . count($bill['items']) . ' item(s), ' . number_format((float) $bill['total_amount'], 2)
        . '. Stock and cost layers rolled back.';
    log_activity('inventory_item', $companyId, 'purchase_bill_deleted', $detail, $userId);
    if (function_exists('security_event')) {
        security_event('inventory_movement_deleted', 'success', $detail, $companyId, $userId);
    }

    return ['ok' => true, 'error' => null, 'voucher_no' => $voucherNo,
        'items' => count($bill['items']), 'amount' => (float) $bill['total_amount']];
}

/**
 * Bills that were entered one voucher per item, and what merging them would do.
 *
 * The grouping is deliberately strict: the same bill number, the same
 * supplier, the same movement, the same date and the same posting state. A
 * blank bill number groups nothing — without one there is no evidence two
 * entries came off the same invoice, and guessing would fold unrelated
 * purchases into one liability.
 *
 * Read-only. Nothing is written until inv_purchase_bill_merge() is called with
 * a group this returned.
 *
 * @return array<int,array{ref_no:string, party_id:int, party_name:string, movement:string,
 *   date:string, status:string, keep:int, keep_no:string, absorb:array<int,int>,
 *   absorb_nos:array<int,string>, vouchers:int, items:int, total:float}>
 */
function inv_purchase_bill_merge_plan(int $companyId): array
{
    $types = inv_purchase_bill_movement_types();
    $typePh = implode(',', array_fill(0, count($types), '?'));
    $stmt = db()->prepare("
        SELECT v.id, v.voucher_no, v.status, v.voucher_date, v.total_amount, v.reference_no, v.party_id,
               p.name AS party_name, t.transaction_type, COUNT(t.id) AS item_count
        FROM vouchers v
        INNER JOIN inventory_transactions t ON t.voucher_id = v.id AND t.company_id = v.company_id
        LEFT JOIN accounting_parties p ON p.id = v.party_id AND p.company_id = v.company_id
        WHERE v.company_id = ? AND v.source_type = 'inventory_movement'
          AND t.transaction_type IN ($typePh)
          AND v.reference_no IS NOT NULL AND v.reference_no <> ''
        GROUP BY v.id
        ORDER BY v.id ASC");
    $stmt->execute(array_merge([$companyId], $types));

    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = mb_strtolower(trim((string) $row['reference_no'])) . '|' . (int) $row['party_id']
            . '|' . (string) $row['transaction_type'] . '|' . (string) $row['voucher_date'] . '|' . (string) $row['status'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'ref_no' => (string) $row['reference_no'],
                'party_id' => (int) $row['party_id'],
                'party_name' => (string) ($row['party_name'] ?? ''),
                'movement' => (string) $row['transaction_type'],
                'date' => (string) $row['voucher_date'],
                'status' => (string) $row['status'],
                'keep' => (int) $row['id'],
                'keep_no' => (string) $row['voucher_no'],
                'absorb' => [],
                'absorb_nos' => [],
                'vouchers' => 0,
                'items' => 0,
                'total' => 0.0,
            ];
        } else {
            $groups[$key]['absorb'][] = (int) $row['id'];
            $groups[$key]['absorb_nos'][] = (string) $row['voucher_no'];
        }
        $groups[$key]['vouchers']++;
        $groups[$key]['items'] += (int) $row['item_count'];
        $groups[$key]['total'] += (float) $row['total_amount'];
    }

    // A bill already in one piece is not a plan.
    return array_values(array_filter($groups, static fn (array $g): bool => $g['vouchers'] > 1));
}

/**
 * Gather one bill's vouchers back into a single entry.
 *
 * The figures are NOT recomputed — they are carried across. Every line of
 * every absorbed voucher survives: the per-item stock lines stay one per item,
 * and everything else (the supplier's credit, the VAT, the withholding) is
 * summed per ledger and per side, because that is what one invoice states
 * once. Total in must equal total out, and the merge refuses rather than
 * writing an entry that changes what the GL carries.
 *
 * The absorbed voucher numbers go into the surviving entry's narration, so the
 * gap they leave in the series can be explained by reading the entry.
 *
 * @return array{ok:bool, error:?string, voucher_no:string, absorbed:int, items:int, total:float}
 */
function inv_purchase_bill_merge(int $companyId, int $keepVoucherId, array $absorbVoucherIds, int $userId): array
{
    $fail = static fn (string $why): array => ['ok' => false, 'error' => $why, 'voucher_no' => '', 'absorbed' => 0, 'items' => 0, 'total' => 0.0];
    $keep = inv_purchase_bill_load($companyId, $keepVoucherId);
    if (!$keep) {
        return $fail('The entry to merge into was not found for this company.');
    }
    $absorbVoucherIds = array_values(array_unique(array_filter(array_map('intval', $absorbVoucherIds),
        static fn (int $id): bool => $id > 0 && $id !== $keepVoucherId)));
    if ($absorbVoucherIds === []) {
        return $fail('Nothing to merge into that entry.');
    }
    $billDate = (string) ($keep['voucher_date'] ?? '');
    if (function_exists('is_period_locked') && is_period_locked($companyId, (int) ($keep['fiscal_year_id'] ?? 0), $billDate)) {
        return $fail('This bill is dated ' . $billDate . ', which is inside a locked accounting period.');
    }

    $bills = [$keep];
    foreach ($absorbVoucherIds as $absorbId) {
        $other = inv_purchase_bill_load($companyId, $absorbId);
        if (!$other) {
            return $fail('Entry #' . $absorbId . ' was not found for this company.');
        }
        // Only ever fold together what is genuinely the same invoice. A
        // different supplier, date or posting state is a different liability.
        if (mb_strtolower(trim((string) $other['reference_no'])) !== mb_strtolower(trim((string) $keep['reference_no']))
            || (int) $other['party_id'] !== (int) $keep['party_id']
            || (string) $other['voucher_date'] !== $billDate
            || (string) $other['status'] !== (string) $keep['status']) {
            return $fail('Entry ' . ($other['voucher_no'] ?: '#' . $absorbId)
                . ' is not the same bill — the reference, supplier, date and posting state must all match.');
        }
        $bills[] = $other;
    }

    // Which ledger each voucher's stock sits on, so a stock line can be told
    // apart from the supplier's credit and kept per item.
    $itemLines = [];
    $pooled = [];
    $sourceTotal = 0.0;
    foreach ($bills as $bill) {
        $sourceTotal += (float) $bill['total_amount'];
        $stockLedgerIds = [];
        $itemByLedger = [];
        foreach ($bill['items'] as $item) {
            $type = (string) $item['transaction_type'];
            $direction = inventory_direction($type);
            $plan = inv_movement_posting_plan($type, $direction);
            $stockPurpose = $direction === 'in' ? $plan['debit'] : $plan['credit'];
            $stockSide = $direction === 'in' ? 'debit' : 'credit';
            $mapped = inv_resolve_mapping($companyId, $stockPurpose, (int) $item['item_id'], $item['category'] ?? null);
            if ($mapped) {
                $stockLedgerIds[(int) $mapped['id'] . '|' . $stockSide] = true;
                $itemByLedger[(int) $mapped['id'] . '|' . $stockSide][] = $item;
            }
        }
        foreach ($bill['lines'] as $line) {
            $key = (int) $line['ledger_id'] . '|' . (string) $line['entry_type'];
            $item = null;
            if (isset($itemByLedger[$key]) && $itemByLedger[$key] !== []) {
                // Match this stock line to the item whose amount it carries;
                // otherwise take them in order.
                foreach ($itemByLedger[$key] as $index => $candidate) {
                    if (abs((float) $candidate['amount'] - (float) $line['amount']) < 0.011) {
                        $item = $candidate;
                        unset($itemByLedger[$key][$index]);
                        break;
                    }
                }
                if ($item === null) {
                    $item = array_shift($itemByLedger[$key]);
                }
            }
            if ($item !== null) {
                $itemLines[] = [
                    'ledger_id' => (int) $line['ledger_id'],
                    'entry_type' => (string) $line['entry_type'],
                    'amount' => round((float) $line['amount'], 2),
                    'memo' => trim((string) $item['sku'] . ' ' . (string) $item['item_name']) . ' — '
                        . number_format((float) $item['qty_in'] + (float) $item['qty_out'], 3)
                        . ' @ ' . number_format((float) $item['rate'], 2),
                ];
                continue;
            }
            if (!isset($pooled[$key])) {
                $pooled[$key] = ['ledger_id' => (int) $line['ledger_id'], 'entry_type' => (string) $line['entry_type'],
                    'amount' => 0.0, 'memo' => (string) ($line['memo'] ?? '')];
            }
            $pooled[$key]['amount'] = round($pooled[$key]['amount'] + (float) $line['amount'], 2);
        }
    }

    $lines = array_merge($itemLines, array_values($pooled));
    $debit = 0.0;
    $credit = 0.0;
    foreach ($lines as $line) {
        if ((string) $line['entry_type'] === 'debit') { $debit += (float) $line['amount']; } else { $credit += (float) $line['amount']; }
    }
    if (abs(round($debit - $credit, 2)) > 0.005) {
        return $fail('Refusing to merge: the combined entry would be out by ' . number_format(abs($debit - $credit), 2) . '.');
    }
    if (abs(round($debit - $sourceTotal, 2)) > 0.005) {
        return $fail('Refusing to merge: the combined entry comes to ' . number_format($debit, 2)
            . ' where the entries it replaces come to ' . number_format($sourceTotal, 2) . '.');
    }

    $absorbedNos = [];
    foreach ($bills as $bill) {
        if ((int) $bill['id'] !== $keepVoucherId && (string) $bill['voucher_no'] !== '') {
            $absorbedNos[] = (string) $bill['voucher_no'];
        }
    }
    $itemCount = 0;
    foreach ($bills as $bill) {
        $itemCount += count($bill['items']);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Every movement of the bill now points at the surviving entry.
        $ph = implode(',', array_fill(0, count($absorbVoucherIds), '?'));
        $pdo->prepare("UPDATE inventory_transactions SET voucher_id = ?
            WHERE company_id = ? AND voucher_id IN ($ph)")
            ->execute(array_merge([$keepVoucherId, $companyId], $absorbVoucherIds));

        $pdo->prepare('DELETE FROM voucher_entries WHERE voucher_id = :vid')->execute(['vid' => $keepVoucherId]);
        $insertLine = $pdo->prepare('INSERT INTO voucher_entries (voucher_id, ledger_id, entry_type, amount, memo)
            VALUES (:vid, :lid, :type, :amt, :memo)');
        foreach ($lines as $line) {
            $insertLine->execute([
                'vid' => $keepVoucherId, 'lid' => (int) $line['ledger_id'], 'type' => (string) $line['entry_type'],
                'amt' => round((float) $line['amount'], 2), 'memo' => ($line['memo'] ?? '') !== '' ? (string) $line['memo'] : null,
            ]);
        }

        $narration = ucfirst(str_replace('_', ' ', (string) ($keep['items'][0]['transaction_type'] ?? 'purchase')))
            . ' — bill ' . (string) $keep['reference_no'] . ' — ' . $itemCount . ' item(s)'
            . ($absorbedNos !== [] ? '. Merged from ' . implode(', ', $absorbedNos) . '.' : '');
        $pdo->prepare('UPDATE vouchers SET total_amount = :t, narration = :n WHERE id = :id AND company_id = :cid')
            ->execute(['t' => round($debit, 2), 'n' => mb_substr($narration, 0, 500), 'id' => $keepVoucherId, 'cid' => $companyId]);

        $pdo->prepare("DELETE FROM voucher_entries WHERE voucher_id IN ($ph)")->execute($absorbVoucherIds);
        $pdo->prepare("DELETE FROM vouchers WHERE company_id = ? AND id IN ($ph)")
            ->execute(array_merge([$companyId], $absorbVoucherIds));
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return $fail('Nothing was merged: ' . $exception->getMessage());
    }

    $detail = 'Bill ' . (string) $keep['reference_no'] . ': ' . count($bills) . ' item-wise entries merged into '
        . ($keep['voucher_no'] ?: 'one draft') . ' — ' . $itemCount . ' item(s), ' . number_format($debit, 2)
        . ($absorbedNos !== [] ? '. Absorbed ' . implode(', ', $absorbedNos) : '') . '.';
    log_activity('inventory_item', $companyId, 'purchase_bill_merged', $detail, $userId);
    if (function_exists('security_event')) {
        security_event('inventory_movement_posted', 'success', $detail, $companyId, $userId);
    }

    return ['ok' => true, 'error' => null, 'voucher_no' => (string) $keep['voucher_no'],
        'absorbed' => count($absorbVoucherIds), 'items' => $itemCount, 'total' => round($debit, 2)];
}
