<?php
declare(strict_types=1);

/**
 * Jewellery Accounting — purchases, sales, old-gold exchange, per-item VAT and
 * bill-wise party accounting.
 *
 * ONE SETTLEMENT RULE RUNS THROUGH EVERYTHING. A counter sale is rarely paid
 * in one way; it is cash plus old gold plus credit, in whatever mix the
 * customer brings. Every document therefore balances three legs against its
 * total:
 *
 *     received_amount  +  exchange_amount  +  balance_amount  ==  total_amount
 *
 * Metal-to-metal and metal-to-cash are not special cases in this model, they
 * are corners of it: a sale settled entirely in old gold is exchange == total,
 * and metal-to-cash is an old-gold PURCHASE paid from the cash ledger.
 *
 * COST IS STAMPED AT POSTING. cogs is computed from the weighted-average fine
 * rate in force at the moment of posting and written onto the sale, so a
 * purchase recorded next week can never quietly restate last week's margin.
 *
 * Every voucher is built as a list of SIGNED legs (positive = debit) and
 * converted once, at the end, by jw_build_entries(). That is what lets a
 * karigar recovery larger than the wages owed flip a credit into a debit
 * without any caller writing a special case.
 */

require_once __DIR__ . '/jewellery_stock.php';

// ---------------------------------------------------------------------------
// Shared posting helpers
// ---------------------------------------------------------------------------

/**
 * Convert signed legs into voucher entries, netting repeats on the same
 * ledger first (a document can touch one ledger from several directions).
 * Positive = debit, negative = credit. Zero legs are dropped.
 *
 * @param array<int, array{ledger_id: int, amount: float, memo?: string}> $legs
 */
function jw_build_entries(array $legs): array
{
    $byLedger = [];
    $memos = [];
    foreach ($legs as $leg) {
        $ledgerId = (int) ($leg['ledger_id'] ?? 0);
        $amount = (float) ($leg['amount'] ?? 0);
        if ($ledgerId <= 0 || abs($amount) < 0.005) {
            continue;
        }
        $byLedger[$ledgerId] = ($byLedger[$ledgerId] ?? 0.0) + $amount;
        $memos[$ledgerId] = $memos[$ledgerId] ?? (string) ($leg['memo'] ?? '');
    }

    $entries = [];
    foreach ($byLedger as $ledgerId => $amount) {
        $amount = jw_round_money($amount);
        if (abs($amount) < 0.005) {
            continue;
        }
        $entries[] = [
            'ledger_id' => $ledgerId,
            'entry_type' => $amount > 0 ? 'debit' : 'credit',
            'amount' => abs($amount),
            'memo' => $memos[$ledgerId] !== '' ? $memos[$ledgerId] : null,
        ];
    }

    return $entries;
}

/** Resolve a mapped purpose to a ledger id, or throw naming the gap. */
function jw_require_ledger(int $companyId, string $purpose, ?int $itemId = null, ?string $category = null): int
{
    $ledger = jewellery_resolve_mapping($companyId, $purpose, $itemId, $category);
    if (!$ledger) {
        $labels = jewellery_mapping_purposes();
        throw new RuntimeException('No ledger is mapped for "' . ($labels[$purpose][0] ?? $purpose)
            . '". Set it under Jewellery → Settings → Posting Ledgers.');
    }

    return (int) $ledger['id'];
}

/**
 * Store the per-line tax breakdown a document was priced with.
 *
 * Kept as rows rather than re-derived on demand, because the formula behind a
 * tax is expected to change: the government revises a rate, a base or an
 * exemption, and an invoice from before the change must still show what was
 * actually charged on it. Reprinting last year's bill under this year's rules
 * would be a different document.
 */
function jw_save_line_taxes(int $companyId, string $docType, int $docId, int $lineId, array $line): void
{
    if ($lineId <= 0 || !table_exists('jewellery_line_taxes')) {
        return;
    }
    db()->prepare('DELETE FROM jewellery_line_taxes WHERE company_id = :cid AND doc_type = :dt AND line_id = :lid')
        ->execute(['cid' => $companyId, 'dt' => $docType, 'lid' => $lineId]);

    $charged = (array) ($line['taxes'] ?? []);
    if ($charged === []) {
        return;
    }
    $stmt = db()->prepare('INSERT INTO jewellery_line_taxes (company_id, doc_type, doc_id, line_id, tax_id,
            tax_code, tax_name, base_amount, rate, amount, sequence, output_purpose, input_purpose)
        VALUES (:cid, :dt, :did, :lid, :tid, :code, :name, :base, :rate, :amount, :seq, :outp, :inp)');
    foreach ($charged as $tax) {
        $stmt->execute([
            'cid' => $companyId, 'dt' => $docType, 'did' => $docId, 'lid' => $lineId,
            'tid' => (int) ($tax['tax_id'] ?? 0) ?: null,
            'code' => (string) ($tax['tax_code'] ?? ''), 'name' => (string) ($tax['tax_name'] ?? ''),
            'base' => (float) ($tax['base_amount'] ?? 0), 'rate' => (float) ($tax['rate'] ?? 0),
            'amount' => (float) ($tax['amount'] ?? 0), 'seq' => (int) ($tax['sequence'] ?? 100),
            'outp' => (string) ($tax['output_purpose'] ?? 'vat_output'),
            'inp' => (string) ($tax['input_purpose'] ?? 'vat_input'),
        ]);
    }
}

/** Every tax charged on a document, totalled per tax, for posting and reporting. */
function jw_document_taxes(int $companyId, string $docType, int $docId): array
{
    if (!table_exists('jewellery_line_taxes')) {
        return [];
    }
    $stmt = db()->prepare('SELECT tax_code, tax_name, output_purpose, input_purpose, MIN(sequence) AS sequence,
            SUM(base_amount) AS base_amount, SUM(amount) AS amount
        FROM jewellery_line_taxes
        WHERE company_id = :cid AND doc_type = :dt AND doc_id = :did
        GROUP BY tax_code, tax_name, output_purpose, input_purpose
        ORDER BY sequence ASC, tax_code ASC');
    $stmt->execute(['cid' => $companyId, 'dt' => $docType, 'did' => $docId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The ledger a purpose resolves to FOR ONE ITEM.
 *
 * Sales and purchases must never all land in one lump ledger. A shop that maps
 * "Sales — metal value" once and posts every ornament to it cannot read its own
 * profit and loss: chains, rings and bangles are one line. So every revenue and
 * cost leg is resolved through the item ladder — item mapping, then its
 * category, then the company default — and the legs are netted per resolved
 * ledger afterwards. Map nothing and you get today's single-ledger behaviour;
 * map one category and that category splits out on its own, with no change to
 * anything else.
 *
 * The error names the item, because "no ledger is mapped for Sales" on a
 * twelve-line invoice is not a message anyone can act on.
 */
function jw_item_ledger(int $companyId, string $purpose, ?array $item): int
{
    $itemId = (int) ($item['id'] ?? 0) ?: null;
    $category = trim((string) ($item['category'] ?? '')) ?: null;
    $ledger = jewellery_resolve_mapping($companyId, $purpose, $itemId, $category);
    if (!$ledger) {
        $labels = jewellery_mapping_purposes();
        throw new RuntimeException('No ledger is mapped for "' . ($labels[$purpose][0] ?? $purpose)
            . '"' . ($item ? ' on item ' . (string) ($item['code'] ?? $itemId) : '')
            . '. Set it under Jewellery → Settings → Posting Ledgers — either as the company default, '
            . 'or against this item or its category to report it separately.');
    }

    return (int) $ledger['id'];
}

/**
 * Resolve the party a document belongs to, creating one from a typed name when
 * it does not exist yet.
 *
 * THERE IS NO ANONYMOUS "WALK-IN" IN THE BOOKS. A counter customer and a named
 * customer are the same thing: a party with its own ledger under Trade
 * Receivables / Payables. That is the only way a customer statement, an
 * outstanding balance, a bill-wise settlement or an order history can exist for
 * them at all. Someone who pays cash and leaves still gets a party — it simply
 * carries no balance afterwards.
 *
 * Accepts either an existing party_id or a party_name (plus optional phone and
 * address, which are filled in on an existing party when it has none). Matching
 * an existing party is by name, case-insensitively, so the same walk-in typed
 * twice does not end up with two ledgers.
 *
 * @return int the party id, or 0 when neither an id nor a name was supplied
 */
function jw_resolve_party(int $companyId, array $input, string $partyType = 'customer'): int
{
    $partyId = (int) ($input['party_id'] ?? 0);
    $name = trim((string) ($input['party_name'] ?? ''));
    $phone = trim((string) ($input['party_phone'] ?? ''));
    $address = trim((string) ($input['party_address'] ?? ''));

    if ($partyId > 0) {
        $check = db()->prepare('SELECT id FROM accounting_parties WHERE id = :id AND company_id = :cid LIMIT 1');
        $check->execute(['id' => $partyId, 'cid' => $companyId]);
        if ((int) ($check->fetchColumn() ?: 0) !== $partyId) {
            throw new RuntimeException('Choose a party that belongs to this company.');
        }
        // Enrich rather than overwrite: a blank field on the form must never
        // wipe contact details someone entered earlier.
        if ($phone !== '' || $address !== '') {
            db()->prepare('UPDATE accounting_parties
                    SET phone = COALESCE(NULLIF(phone, \'\'), :phone),
                        billing_address = COALESCE(NULLIF(billing_address, \'\'), :address)
                WHERE id = :id AND company_id = :cid')
                ->execute(['phone' => $phone ?: null, 'address' => $address ?: null, 'id' => $partyId, 'cid' => $companyId]);
        }
        ensure_party_ledger($companyId, $partyId, $partyType === 'supplier' ? 'payable' : 'receivable');

        return $partyId;
    }

    if ($name === '') {
        return 0;
    }

    $existing = db()->prepare('SELECT id FROM accounting_parties WHERE company_id = :cid AND LOWER(name) = LOWER(:n) LIMIT 1');
    $existing->execute(['cid' => $companyId, 'n' => $name]);
    $found = (int) ($existing->fetchColumn() ?: 0);
    if ($found > 0) {
        return jw_resolve_party($companyId, ['party_id' => $found, 'party_phone' => $phone, 'party_address' => $address], $partyType);
    }

    $code = jw_next_party_code($companyId, $partyType === 'supplier' ? 'SUP' : 'CUS');
    db()->prepare('INSERT INTO accounting_parties (company_id, code, name, party_type, phone, billing_address, status)
        VALUES (:cid, :code, :name, :type, :phone, :address, \'active\')')
        ->execute([
            'cid' => $companyId, 'code' => $code, 'name' => $name,
            'type' => in_array($partyType, ['customer', 'supplier', 'both', 'other'], true) ? $partyType : 'customer',
            'phone' => $phone ?: null, 'address' => $address ?: null,
        ]);
    $newId = (int) db()->lastInsertId();
    // Open the ledger straight away so the party shows in the chart of
    // accounts even before their first posting.
    ensure_party_ledger($companyId, $newId, $partyType === 'supplier' ? 'payable' : 'receivable');

    return $newId;
}

/** Next free party code for a prefix, scanning the existing maximum. */
function jw_next_party_code(int $companyId, string $prefix): string
{
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $prefix) ?: 'PTY');
    $stmt = db()->prepare('SELECT code FROM accounting_parties WHERE company_id = :cid AND code LIKE :like
        ORDER BY LENGTH(code) DESC, code DESC LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'like' => $prefix . '-%']);
    $last = (string) ($stmt->fetchColumn() ?: '');
    $next = 1;
    if ($last !== '' && preg_match('/-(\d+)$/', $last, $m)) {
        $next = (int) $m[1] + 1;
    }

    return $prefix . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

/** The party's own receivable/payable ledger, created on demand. */
function jw_party_ledger(int $companyId, int $partyId, string $side): int
{
    if ($side === 'advance') {
        return jw_party_advance_ledger_id($companyId, $partyId);
    }
    $ledgerId = ensure_party_ledger($companyId, $partyId, $side);
    if ($ledgerId <= 0) {
        throw new RuntimeException('Could not resolve a ledger for this party.');
    }

    return $ledgerId;
}

/**
 * One customer's ADVANCE ledger, created on demand.
 *
 * An advance is not a smaller receivable. Until the piece is handed over the
 * shop is holding the customer's money (or their gold) and owes it back — a
 * liability. Netting it against a receivable that does not exist yet hides
 * exactly the number a jeweller is asked for at the counter: "how much have I
 * already given you?"
 *
 * Per customer, for the same reason every party has its own receivable: one
 * lump "Advances" account answers how much is held in total and nothing else.
 * Identity is a stable code, so renaming the customer never strands the ledger.
 */
function jw_party_advance_ledger_id(int $companyId, int $partyId): int
{
    if ($partyId <= 0 || !table_exists('ledgers')) {
        return 0;
    }

    $partyStmt = db()->prepare('SELECT * FROM accounting_parties WHERE id = :id AND company_id = :cid LIMIT 1');
    $partyStmt->execute(['id' => $partyId, 'cid' => $companyId]);
    $party = $partyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$party) {
        throw new RuntimeException('That party does not belong to this company.');
    }

    // Honour a stored ledger only while it is still an active liability ledger
    // of this company — a re-pointed one must not receive advances.
    $stored = column_exists('accounting_parties', 'advance_ledger_id') ? (int) ($party['advance_ledger_id'] ?? 0) : 0;
    if ($stored > 0) {
        $check = db()->prepare("SELECT id FROM ledgers WHERE id = :id AND company_id = :cid AND status = 'active' AND type = 'liability' LIMIT 1");
        $check->execute(['id' => $stored, 'cid' => $companyId]);
        if ((int) ($check->fetchColumn() ?: 0) > 0) {
            return $stored;
        }
    }

    // ONE definition of where a customer advance sits, shared with the client
    // task advances in advance_engine.php — the same idea must not end up in two
    // corners of the chart of accounts. A jewellery shop can still point the
    // 'customer_advance' mapping somewhere of its own, and that wins.
    require_once __DIR__ . '/advance_engine.php';
    $mapped = jewellery_resolve_mapping($companyId, 'customer_advance');
    $groupId = $mapped ? (int) ($mapped['group_id'] ?? 0) : customer_advance_group_id($companyId);
    if ($groupId <= 0) {
        throw new RuntimeException('No ledger is mapped for "Customer advances (orders)", and no Current '
            . 'Liabilities group could be opened. Set the mapping under Jewellery → Settings → Posting '
            . 'Ledgers — it supplies the group each customer\'s advance ledger is opened in.');
    }

    $ledgerId = ensure_customer_advance_ledger($companyId, $partyId);
    if ($ledgerId <= 0) {
        throw new RuntimeException('Could not open an advance ledger for this customer.');
    }
    // Honour the jewellery mapping's group when it differs from the default the
    // shared helper picked, and keep the display name in step with the party.
    $name = 'Advance from ' . (string) $party['name'];
    db()->prepare('UPDATE ledgers SET group_id = :gid, name = :name WHERE id = :id AND company_id = :cid')
        ->execute(['gid' => $groupId, 'name' => $name, 'id' => $ledgerId, 'cid' => $companyId]);

    if ($ledgerId > 0 && column_exists('accounting_parties', 'advance_ledger_id')) {
        db()->prepare('UPDATE accounting_parties SET advance_ledger_id = :lid WHERE id = :id AND company_id = :cid')
            ->execute(['lid' => $ledgerId, 'id' => $partyId, 'cid' => $companyId]);
    }

    return $ledgerId;
}

/** Posted advances against one order, and what is left after any delivery. */
function jewellery_order_advances(int $companyId, int $orderId): array
{
    if ($orderId <= 0 || !column_exists('jewellery_settlements', 'order_id')) {
        return ['rows' => [], 'cash_total' => 0.0, 'metal_total' => 0.0, 'total' => 0.0];
    }
    $stmt = db()->prepare("SELECT s.*, i.sku AS item_code, i.name AS item_name, u.code AS unit_code, p.code AS purity_code
        FROM jewellery_settlements s
        LEFT JOIN inventory_items i ON i.id = s.item_id
        LEFT JOIN jewellery_units u ON u.id = s.unit_id
        LEFT JOIN jewellery_purities p ON p.id = s.purity_id
        WHERE s.company_id = :cid AND s.order_id = :oid AND s.is_advance = 1 AND s.status = 'posted'
        ORDER BY s.settlement_date ASC, s.id ASC");
    $stmt->execute(['cid' => $companyId, 'oid' => $orderId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cash = 0.0;
    $metal = 0.0;
    foreach ($rows as $row) {
        // A refund is an advance handed BACK, so it reduces what is held.
        $sign = (string) $row['direction'] === 'received' ? 1 : -1;
        if ((string) $row['mode'] === 'metal') {
            $metal += $sign * (float) $row['amount'];
        } else {
            $cash += $sign * (float) $row['amount'];
        }
    }

    return [
        'rows' => $rows,
        'cash_total' => jw_round_money($cash),
        'metal_total' => jw_round_money($metal),
        'total' => jw_round_money($cash + $metal),
    ];
}

/** What is still held against an order after the sales that have already applied some of it. */
function jewellery_order_advance_available(int $companyId, int $orderId, int $excludeSaleId = 0): float
{
    $held = jewellery_order_advances($companyId, $orderId)['total'];
    if (!column_exists('jewellery_sales', 'advance_amount')) {
        return $held;
    }
    $sql = "SELECT COALESCE(SUM(s.advance_amount), 0) FROM jewellery_sales s
        INNER JOIN jewellery_orders o ON o.delivered_sale_id = s.id
        WHERE s.company_id = :cid AND o.id = :oid AND s.status <> 'cancelled'";
    $params = ['cid' => $companyId, 'oid' => $orderId];
    if ($excludeSaleId > 0) {
        $sql .= ' AND s.id <> :sid';
        $params['sid'] = $excludeSaleId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return jw_round_money($held - (float) $stmt->fetchColumn());
}

/**
 * Collapse the parallel arrays a line-entry form posts into a list of line
 * rows, dropping untouched blank slots (an empty row is not an error).
 *
 * Lives in the engine rather than the page because a page-level function is
 * redeclared if the file is ever included twice in one process — which is
 * exactly what the render test does.
 */
function jw_posted_lines(array $post, string $prefix): array
{
    $lines = [];
    foreach ((array) ($post[$prefix . '_item_id'] ?? []) as $index => $itemId) {
        if ((int) $itemId <= 0) {
            continue;
        }
        $lines[] = [
            'item_id' => (int) $itemId,
            'purity_id' => (int) ($post[$prefix . '_purity_id'][$index] ?? 0),
            'unit_id' => (int) ($post[$prefix . '_unit_id'][$index] ?? 0),
            'qty_pieces' => (float) ($post[$prefix . '_qty_pieces'][$index] ?? 0),
            'gross_weight' => (float) ($post[$prefix . '_gross_weight'][$index] ?? 0),
            'stone_weight' => (float) ($post[$prefix . '_stone_weight'][$index] ?? 0),
            'rate' => (float) ($post[$prefix . '_rate'][$index] ?? 0),
            'wastage_pct' => (float) ($post[$prefix . '_wastage_pct'][$index] ?? 0),
            'making_amount' => (float) ($post[$prefix . '_making_amount'][$index] ?? 0),
            'stone_amount' => (float) ($post[$prefix . '_stone_amount'][$index] ?? 0),
            'notes' => (string) ($post[$prefix . '_notes'][$index] ?? ''),
        ];
    }

    return $lines;
}

/** Next document number for a jewellery table (JS-00001, JP-00007, ...). */
function jw_next_no(int $companyId, string $table, string $column, string $prefix): string
{
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $prefix) ?: 'JW');
    $allowed = ['jewellery_purchases' => 'purchase_no', 'jewellery_sales' => 'sale_no',
        'jewellery_settlements' => 'settlement_no', 'jewellery_orders' => 'order_no',
        'jewellery_order_assignments' => 'issue_no', 'jewellery_order_receipts' => 'receipt_no',
        'jewellery_refinery_jobs' => 'job_no'];
    if (($allowed[$table] ?? '') !== $column) {
        throw new RuntimeException('Refusing to number an unknown document table.');
    }

    $stmt = db()->prepare("SELECT `$column` FROM `$table` WHERE company_id = :cid AND `$column` LIKE :like
        ORDER BY LENGTH(`$column`) DESC, `$column` DESC LIMIT 1");
    $stmt->execute(['cid' => $companyId, 'like' => $prefix . '-%']);
    $last = (string) ($stmt->fetchColumn() ?: '');
    $next = 1;
    if ($last !== '' && preg_match('/-(\d+)$/', $last, $m)) {
        $next = (int) $m[1] + 1;
    }

    return $prefix . '-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
}

// ---------------------------------------------------------------------------
// Document arithmetic (pure — no database writes, so it is directly testable)
// ---------------------------------------------------------------------------

/**
 * Price and tax a document's lines, then total the header.
 *
 * Each line's `rate` is money per ONE unit of GROSS weight at that line's own
 * purity, which is how a shop actually quotes ("22K at 139,000 per tola").
 * When a line arrives with no rate, the daily rate board prices it through
 * fine weight instead and the equivalent gross rate is written back, so both
 * ways of working produce the same stored figures.
 *
 * VAT follows the ITEM: exempt items are never taxed, and a taxable item is
 * taxed on whichever base it (or the company default) specifies.
 *
 * Header discount and other charges are applied AFTER VAT and allocated back
 * across lines pro rata, so a purchase still capitalises a true landed cost.
 *
 * @return array{lines: array, totals: array, errors: array}
 */
function jw_compute_document(int $companyId, array $header, array $lines, ?array $settings = null): array
{
    $settings = $settings ?? jewellery_settings($companyId);
    $date = (string) ($header['document_date'] ?? date('Y-m-d'));
    $rateType = (string) ($header['rate_type'] ?? 'market');
    $docType = (string) ($header['doc_type'] ?? 'sale') === 'purchase' ? 'purchase' : 'sale';

    // The taxes in force ON THE DOCUMENT'S DATE, in charging order. Reading
    // them per document is what lets last year's invoices reprice under last
    // year's rules after the government changes them.
    $taxes = jewellery_taxes_list($companyId, $docType, $date);

    $computed = [];
    $errors = [];
    $sumMetal = 0.0; $sumMaking = 0.0; $sumStone = 0.0; $sumTaxable = 0.0; $sumVat = 0.0;
    $sumWastage = 0.0; $sumOtherTax = 0.0;

    foreach ($lines as $index => $line) {
        $itemId = (int) ($line['item_id'] ?? 0);
        $item = jewellery_item($companyId, $itemId);
        if (!$item) {
            $errors[] = 'Line ' . ($index + 1) . ': unknown item.';
            continue;
        }

        $purityId = (int) ($line['purity_id'] ?? $item['purity_id']);
        $purity = jewellery_purity($companyId, $purityId);
        if (!$purity || (int) $purity['metal_id'] !== (int) $item['metal_id']) {
            $errors[] = 'Line ' . ($index + 1) . ': the purity must belong to the item\'s metal.';
            continue;
        }
        $unitId = (int) ($line['unit_id'] ?? $item['unit_id']);
        if (!jewellery_unit($companyId, $unitId)) {
            $errors[] = 'Line ' . ($index + 1) . ': unknown weight unit.';
            continue;
        }

        $gross = jw_round_weight((float) ($line['gross_weight'] ?? 0));
        $pieces = round((float) ($line['qty_pieces'] ?? 0), 3);
        if ($gross < 0 || $pieces < 0) {
            $errors[] = 'Line ' . ($index + 1) . ': weight and pieces cannot be negative.';
            continue;
        }
        if ($gross <= 0 && $pieces <= 0) {
            $errors[] = 'Line ' . ($index + 1) . ': enter a weight or a piece count.';
            continue;
        }

        // GROSS is what the scale says: metal AND stones. NET is the metal.
        // Charging the gold rate on a diamond's weight overstates the metal —
        // and the Skills Promotion Tax base is explicitly the weight less
        // anything that is not gold or silver, so the distinction has to be
        // carried, not assumed away. Leave stone weight at zero and net is
        // gross, which is exactly how every document written so far behaves.
        $stoneWeight = jw_round_weight((float) ($line['stone_weight'] ?? 0));
        if ($stoneWeight < 0) {
            $errors[] = 'Line ' . ($index + 1) . ': stone weight cannot be negative.';
            continue;
        }
        if ($stoneWeight > $gross) {
            $errors[] = 'Line ' . ($index + 1) . ': the stone weight cannot exceed the gross weight.';
            continue;
        }
        $net = jw_round_weight($gross - $stoneWeight);
        $fine = jw_fine_weight($net, (float) $purity['fineness']);

        $rate = jw_round_rate((float) ($line['rate'] ?? 0));
        $metalAmount = jw_round_money($net * $rate);
        // No rate typed: let the daily board price it through fine weight, then
        // write the equivalent rate back so the document reads the same either
        // way. Priced on NET, because that is the metal being sold.
        $rateGap = '';
        if ($rate <= 0 && $net > 0) {
            $valued = jewellery_metal_value($companyId, (int) $item['metal_id'], $purityId, $net, $unitId, $date, $rateType, $settings);
            if ($valued['ok']) {
                $metalAmount = $valued['amount'];
                $rate = jw_round_rate($metalAmount / $net);
            } else {
                $rateGap = (string) ($valued['error'] ?: 'no rate is available for this item.');
            }
        }

        $making = jw_round_money((float) ($line['making_amount'] ?? 0));
        $stone = jw_round_money((float) ($line['stone_amount'] ?? 0));
        if ($making < 0 || $stone < 0) {
            $errors[] = 'Line ' . ($index + 1) . ': making and stone amounts cannot be negative.';
            continue;
        }

        // A line that has weight but came out worth NOTHING is a wrong invoice,
        // not an empty one — somebody forgot the rate. It is only wrong when
        // the line carries no value at all, though: a diamond's worth sits in
        // its stone amount and there is no metal rate to quote for carats.
        if ($rateGap !== '' && $metalAmount <= 0 && $making <= 0 && $stone <= 0) {
            $errors[] = 'Line ' . ($index + 1) . ': ' . $rateGap
                . ' Enter a rate on the line, or quote one on the Daily Rates board.';
            continue;
        }

        // Wastage is quoted as a percentage and settled in money. It is metal,
        // so it is valued at the metal rate — which makes it simply a
        // percentage of the metal amount — and it forms part of the Skills
        // Promotion Tax base.
        $wastagePct = round((float) ($line['wastage_pct'] ?? 0), 3);
        if ($wastagePct < 0) {
            $errors[] = 'Line ' . ($index + 1) . ': wastage cannot be negative.';
            continue;
        }
        $wastageAmount = jw_round_money($metalAmount * $wastagePct / 100.0);

        // Taxes, in charging order, each one seeing the ones before it.
        $charge = jw_charge_line_taxes(
            ['metal' => $metalAmount, 'wastage' => $wastageAmount, 'making' => $making, 'stone' => $stone],
            $taxes,
            jw_item_tax_ids($companyId, $itemId),
            (int) $item['vat_applicable'] === 1,
            jw_item_vat_base($item, $settings)
        );

        // vat_base / vat_rate stay on the line for the VAT register, which
        // reports the one tax the office actually asks about.
        $vatBase = 'none';
        $lineVatRate = 0.0;
        $taxable = 0.0;
        foreach ($charge['taxes'] as $chargedTax) {
            if ($chargedTax['is_vat']) {
                $vatBase = match ($chargedTax['base']) {
                    'making' => 'making_only',
                    'stone' => 'stone_only',
                    default => 'full_value',
                };
                $lineVatRate = $chargedTax['rate'];
                $taxable = $chargedTax['base_amount'];
            }
        }

        $subtotal = jw_round_money($metalAmount + $wastageAmount + $making + $stone);
        $computed[] = [
            'item_id' => $itemId,
            'item' => $item,
            'purity_id' => $purityId,
            'unit_id' => $unitId,
            'qty_pieces' => $pieces,
            'gross_weight' => $gross,
            'stone_weight' => $stoneWeight,
            'net_weight' => $net,
            'fine_weight' => $fine,
            'rate' => $rate,
            'metal_amount' => $metalAmount,
            'wastage_pct' => $wastagePct,
            'wastage_amount' => $wastageAmount,
            'making_amount' => $making,
            'stone_amount' => $stone,
            'vat_base' => $vatBase,
            'vat_rate' => $lineVatRate,
            'vat_amount' => $charge['vat'],
            'tax_amount' => $charge['other'],
            'taxes' => $charge['taxes'],
            'subtotal' => $subtotal,
            'allocated_adjust' => 0.0,
            'line_total' => jw_round_money($subtotal + $charge['total']),
            'notes' => (string) ($line['notes'] ?? ''),
        ];

        $sumMetal += $metalAmount;
        $sumWastage += $wastageAmount;
        $sumMaking += $making;
        $sumStone += $stone;
        $sumTaxable += $taxable;
        $sumVat += $charge['vat'];
        $sumOtherTax += $charge['other'];
    }

    $otherCharges = jw_round_money((float) ($header['other_charges'] ?? 0));
    $discount = jw_round_money((float) ($header['discount'] ?? 0));
    if ($otherCharges < 0 || $discount < 0) {
        $errors[] = 'Other charges and discount cannot be negative.';
    }
    $subtotalAll = jw_round_money($sumMetal + $sumWastage + $sumMaking + $sumStone);
    if ($discount > $subtotalAll + $otherCharges) {
        $errors[] = 'The discount cannot exceed the document value.';
    }

    // A manually punched non-VAT tax total wins over the computed one. The
    // shop totals the Skills Promotion Tax itself and enters the figure; the
    // computed number stays visible beside it so the two can be compared, and
    // a NULL — as opposed to a punched zero — means "use what was computed".
    $computedOtherTax = jw_round_money($sumOtherTax);
    $manualTax = ($header['manual_tax_amount'] ?? null) === null || $header['manual_tax_amount'] === ''
        ? null
        : jw_round_money((float) $header['manual_tax_amount']);
    if ($manualTax !== null && $manualTax < 0) {
        $errors[] = 'A punched tax total cannot be negative.';
        $manualTax = null;
    }
    $otherTax = $manualTax ?? $computedOtherTax;

    // When the punched figure differs, the difference is carried on the LAST
    // taxed line so the lines still re-add to the header exactly.
    if ($manualTax !== null && abs($manualTax - $computedOtherTax) >= 0.005) {
        for ($i = count($computed) - 1; $i >= 0; $i--) {
            if ((float) $computed[$i]['tax_amount'] > 0 || $i === 0) {
                $delta = jw_round_money($manualTax - $computedOtherTax);
                $computed[$i]['tax_amount'] = jw_round_money((float) $computed[$i]['tax_amount'] + $delta);
                $computed[$i]['line_total'] = jw_round_money((float) $computed[$i]['line_total'] + $delta);
                $computed[$i]['tax_manual_adjust'] = $delta;
                break;
            }
        }
    }

    // Allocate the net header adjustment pro rata, then force the last line to
    // absorb any rounding so the parts always re-add to the whole.
    $netAdjust = jw_round_money($otherCharges - $discount);
    if ($computed !== [] && abs($netAdjust) >= 0.005) {
        $allocated = 0.0;
        $lastIndex = count($computed) - 1;
        foreach ($computed as $i => $row) {
            if ($i === $lastIndex) {
                $computed[$i]['allocated_adjust'] = jw_round_money($netAdjust - $allocated);
                break;
            }
            $share = $subtotalAll > 0 ? jw_round_money($netAdjust * $row['subtotal'] / $subtotalAll) : 0.0;
            $computed[$i]['allocated_adjust'] = $share;
            $allocated += $share;
        }
    }
    foreach ($computed as $i => $row) {
        $computed[$i]['stock_amount'] = jw_round_money($row['subtotal'] + $computed[$i]['allocated_adjust']);
    }

    $total = jw_round_money($subtotalAll + $otherCharges - $discount + $otherTax + $sumVat);

    return [
        'lines' => $computed,
        'errors' => $errors,
        'taxes_in_force' => $taxes,
        'totals' => [
            'metal_amount' => jw_round_money($sumMetal),
            'wastage_amount' => jw_round_money($sumWastage),
            'making_amount' => jw_round_money($sumMaking),
            'stone_amount' => jw_round_money($sumStone),
            'other_charges' => $otherCharges,
            'discount' => $discount,
            'taxable_amount' => jw_round_money($sumTaxable),
            'tax_amount' => $otherTax,
            'computed_tax_amount' => $computedOtherTax,
            'manual_tax_amount' => $manualTax,
            'vat_amount' => jw_round_money($sumVat),
            'subtotal' => $subtotalAll,
            'total_amount' => $total,
        ],
    ];
}

// ---------------------------------------------------------------------------
// Purchases
// ---------------------------------------------------------------------------

function jewellery_purchase(int $companyId, int $purchaseId): ?array
{
    $stmt = db()->prepare('SELECT p.*, ap.name AS party_name, ap.code AS party_code
        FROM jewellery_purchases p
        LEFT JOIN accounting_parties ap ON ap.id = p.party_id
        WHERE p.id = :id AND p.company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $purchaseId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function jewellery_purchase_line_rows(int $companyId, int $purchaseId): array
{
    $stmt = db()->prepare('SELECT l.*, i.sku AS item_code, i.name AS item_name, jp.jewellery_type AS item_type, i.category,
            p.code AS purity_code, p.fineness, u.code AS unit_code
        FROM jewellery_purchase_lines l
        INNER JOIN inventory_items i ON i.id = l.item_id
            INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        INNER JOIN jewellery_purities p ON p.id = l.purity_id
        INNER JOIN jewellery_units u ON u.id = l.unit_id
        WHERE l.company_id = :cid AND l.purchase_id = :pid ORDER BY l.id ASC');
    $stmt->execute(['cid' => $companyId, 'pid' => $purchaseId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jewellery_purchases_list(int $companyId, array $filters = []): array
{
    $sql = 'SELECT p.*, ap.name AS party_name FROM jewellery_purchases p
        LEFT JOIN accounting_parties ap ON ap.id = p.party_id
        WHERE p.company_id = :cid';
    $params = ['cid' => $companyId];
    if (($filters['from'] ?? '') !== '' && ($filters['to'] ?? '') !== '') {
        $sql .= ' AND p.purchase_date BETWEEN :from AND :to';
        $params['from'] = (string) $filters['from'];
        $params['to'] = (string) $filters['to'];
    }
    if (($filters['status'] ?? '') !== '') {
        $sql .= ' AND p.status = :st';
        $params['st'] = (string) $filters['status'];
    }
    if (($filters['source'] ?? '') !== '') {
        $sql .= ' AND p.source = :src';
        $params['src'] = (string) $filters['source'];
    }
    if (!empty($filters['party_id'])) {
        $sql .= ' AND p.party_id = :pid';
        $params['pid'] = (int) $filters['party_id'];
    }
    $sql .= ' ORDER BY p.purchase_date DESC, p.id DESC LIMIT ' . max(1, min(1000, (int) ($filters['limit'] ?? 200)));

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create or revise a DRAFT purchase with its lines. A posted purchase is
 * immutable — unpost it first.
 */
function jewellery_save_purchase(int $companyId, int $fiscalYearId, array $header, array $lines, int $userId = 0): int
{
    $purchaseId = (int) ($header['id'] ?? 0);
    if ($purchaseId > 0) {
        $existing = jewellery_purchase($companyId, $purchaseId);
        if (!$existing) {
            throw new RuntimeException('Purchase not found for this company.');
        }
        if ((string) $existing['status'] !== 'draft') {
            throw new RuntimeException('This purchase is already posted. Unpost it before revising.');
        }
    }

    $settings = jewellery_settings($companyId);
    $date = (string) ($header['purchase_date'] ?? date('Y-m-d'));
    $computed = jw_compute_document($companyId, [
        'document_date' => $date,
        'doc_type' => 'purchase',
        'rate_type' => 'purchase',
        'other_charges' => $header['other_charges'] ?? 0,
        'discount' => $header['discount'] ?? 0,
        'manual_tax_amount' => $header['manual_tax_amount'] ?? null,
    ], $lines, $settings);
    if ($computed['errors'] !== []) {
        throw new RuntimeException(implode(' ', $computed['errors']));
    }
    if ($computed['lines'] === []) {
        throw new RuntimeException('A purchase needs at least one line.');
    }

    // A supplier — or the walk-in customer selling old gold over the counter —
    // is always a named party with its own ledger, created here if new.
    $partyId = jw_resolve_party($companyId, $header,
        (string) ($header['source'] ?? '') === 'customer_old_gold' ? 'customer' : 'supplier') ?: null;
    if ($partyId === null) {
        throw new RuntimeException('Enter who this was bought from — a name is enough, the party and its ledger are created automatically.');
    }

    $settleMode = jw_enum($header['settle_mode'] ?? null, ['credit', 'cash', 'bank'], 'credit');
    $settleLedgerId = (int) ($header['settle_ledger_id'] ?? 0) ?: null;
    if ($settleMode !== 'credit') {
        if ($settleLedgerId === null) {
            throw new RuntimeException('Choose the cash or bank ledger this purchase is paid from.');
        }
        $check = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :id AND company_id = :cid');
        $check->execute(['id' => $settleLedgerId, 'cid' => $companyId]);
        if ((int) $check->fetchColumn() === 0) {
            throw new RuntimeException('That settlement ledger does not belong to this company.');
        }
    } else {
        $settleLedgerId = null;
    }

    $totals = $computed['totals'];
    $paid = $settleMode === 'credit' ? 0.0 : $totals['total_amount'];
    $params = [
        'cid' => $companyId,
        'fy' => $fiscalYearId ?: null,
        'date' => $date,
        'party' => $partyId,
        'source' => (string) ($header['source'] ?? 'supplier') === 'customer_old_gold' ? 'customer_old_gold' : 'supplier',
        'ref' => trim((string) ($header['ref_no'] ?? '')) ?: null,
        'narration' => trim((string) ($header['narration'] ?? '')) ?: null,
        'metal' => $totals['metal_amount'],
        'wastage' => $totals['wastage_amount'],
        'making' => $totals['making_amount'],
        'stone' => $totals['stone_amount'],
        'other' => $totals['other_charges'],
        'discount' => $totals['discount'],
        'taxable' => $totals['taxable_amount'],
        'vat' => $totals['vat_amount'],
        'tax' => $totals['tax_amount'],
        'mtax' => $totals['manual_tax_amount'],
        'total' => $totals['total_amount'],
        'paid' => $paid,
        'balance' => jw_round_money($totals['total_amount'] - $paid),
        'smode' => $settleMode,
        'sledger' => $settleLedgerId,
    ];

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        if ($purchaseId > 0) {
            db()->prepare('UPDATE jewellery_purchases SET fiscal_year_id = :fy, purchase_date = :date, party_id = :party,
                    source = :source, ref_no = :ref, narration = :narration, metal_amount = :metal, making_amount = :making,
                    wastage_amount = :wastage, stone_amount = :stone, other_charges = :other, discount = :discount,
                    taxable_amount = :taxable, vat_amount = :vat, tax_amount = :tax, manual_tax_amount = :mtax,
                    total_amount = :total, paid_amount = :paid, balance_amount = :balance,
                    settle_mode = :smode, settle_ledger_id = :sledger
                WHERE id = :id AND company_id = :cid')
                ->execute($params + ['id' => $purchaseId]);
            db()->prepare('DELETE FROM jewellery_purchase_lines WHERE purchase_id = :pid AND company_id = :cid')
                ->execute(['pid' => $purchaseId, 'cid' => $companyId]);
        } else {
            $no = trim((string) ($header['purchase_no'] ?? '')) ?: jw_next_no($companyId, 'jewellery_purchases', 'purchase_no', (string) ($settings['purchase_no_prefix'] ?? 'JP'));
            db()->prepare('INSERT INTO jewellery_purchases (company_id, fiscal_year_id, purchase_no, purchase_date, party_id,
                    source, ref_no, narration, metal_amount, wastage_amount, making_amount, stone_amount, other_charges, discount,
                    taxable_amount, vat_amount, tax_amount, manual_tax_amount, total_amount, paid_amount, balance_amount,
                    settle_mode, settle_ledger_id, created_by)
                VALUES (:cid, :fy, :no, :date, :party, :source, :ref, :narration, :metal, :wastage, :making, :stone, :other, :discount,
                    :taxable, :vat, :tax, :mtax, :total, :paid, :balance, :smode, :sledger, :by)')
                ->execute($params + ['no' => $no, 'by' => $userId ?: null]);
            $purchaseId = (int) db()->lastInsertId();
        }

        $lineStmt = db()->prepare('INSERT INTO jewellery_purchase_lines (purchase_id, company_id, item_id, purity_id, unit_id,
                qty_pieces, gross_weight, stone_weight, net_weight, fine_weight, rate, metal_amount,
                wastage_pct, wastage_amount, making_amount, stone_amount,
                vat_base, vat_rate, vat_amount, tax_amount, allocated_adjust, line_total, stock_amount, notes)
            VALUES (:pid, :cid, :item, :purity, :unit, :pieces, :gross, :sweight, :net, :fine, :rate, :metal,
                :wpct, :wamount, :making, :stone, :vbase, :vrate, :vamount, :tamount, :adjust, :ltotal, :stock, :notes)');
        foreach ($computed['lines'] as $row) {
            $lineStmt->execute([
                'pid' => $purchaseId, 'cid' => $companyId, 'item' => $row['item_id'], 'purity' => $row['purity_id'],
                'unit' => $row['unit_id'], 'pieces' => $row['qty_pieces'], 'gross' => $row['gross_weight'],
                'sweight' => $row['stone_weight'], 'net' => $row['net_weight'],
                'fine' => $row['fine_weight'], 'rate' => $row['rate'], 'metal' => $row['metal_amount'],
                'wpct' => $row['wastage_pct'], 'wamount' => $row['wastage_amount'],
                'making' => $row['making_amount'], 'stone' => $row['stone_amount'], 'vbase' => $row['vat_base'],
                'vrate' => $row['vat_rate'], 'vamount' => $row['vat_amount'], 'tamount' => $row['tax_amount'],
                'adjust' => $row['allocated_adjust'],
                'ltotal' => $row['line_total'], 'stock' => $row['stock_amount'],
                'notes' => $row['notes'] !== '' ? $row['notes'] : null,
            ]);
            jw_save_line_taxes($companyId, 'purchase', $purchaseId, (int) db()->lastInsertId(), $row);
        }

        if ($ownsTransaction) {
            db()->commit();
        }
    } catch (Throwable $saveException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $saveException;
    }

    return $purchaseId;
}

/**
 * Post a purchase: stock movements + the purchase voucher + (on credit) the
 * bill, all in one transaction.
 *
 *   Dr  stock ledger per line   (landed cost: value + allocated adjustment)
 *   Dr  VAT input               (recoverable, so it never enters stock)
 *       Cr  party ledger        (credit) or cash/bank ledger (paid)
 */
function jewellery_post_purchase(int $companyId, int $purchaseId, int $userId = 0): array
{
    $purchase = jewellery_purchase($companyId, $purchaseId);
    if (!$purchase) {
        return ['ok' => false, 'error' => 'Purchase not found for this company.'];
    }
    if ((string) $purchase['status'] !== 'draft') {
        return ['ok' => false, 'error' => 'This purchase is already posted.'];
    }
    $lines = jewellery_purchase_line_rows($companyId, $purchaseId);
    if ($lines === []) {
        return ['ok' => false, 'error' => 'This purchase has no lines.'];
    }

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        $legs = [];
        foreach ($lines as $line) {
            $item = jewellery_item($companyId, (int) $line['item_id']);
            $stockLedgerId = jw_item_stock_ledger_id($companyId, $item);
            if ($stockLedgerId <= 0) {
                throw new RuntimeException('No stock ledger is mapped for item ' . $item['code']
                    . '. Set it under Jewellery → Settings → Posting Ledgers.');
            }
            $legs[] = ['ledger_id' => $stockLedgerId, 'amount' => (float) $line['stock_amount'], 'memo' => 'Purchase ' . $purchase['purchase_no']];
        }
        // Recoverable taxes stay OUT of stock — each to the account it is
        // reclaimed from, named by the tax row itself.
        $documentTaxes = jw_document_taxes($companyId, 'purchase', $purchaseId);
        $postedTax = 0.0;
        foreach ($documentTaxes as $tax) {
            $amount = jw_round_money((float) $tax['amount']);
            if (abs($amount) < 0.005) {
                continue;
            }
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, (string) $tax['input_purpose']),
                'amount' => $amount, 'memo' => $tax['tax_code'] . ' ' . $purchase['purchase_no']];
            $postedTax = jw_round_money($postedTax + $amount);
        }
        $headerTax = jw_round_money((float) $purchase['vat_amount'] + (float) ($purchase['tax_amount'] ?? 0));
        $taxGap = jw_round_money($headerTax - $postedTax);
        if (abs($taxGap) >= 0.005) {
            $gapPurpose = $documentTaxes !== [] ? (string) $documentTaxes[0]['input_purpose'] : 'vat_input';
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, $gapPurpose),
                'amount' => $taxGap, 'memo' => 'Tax adjustment ' . $purchase['purchase_no']];
        }

        $total = (float) $purchase['total_amount'];
        if ((string) $purchase['settle_mode'] === 'credit') {
            $legs[] = ['ledger_id' => jw_party_ledger($companyId, (int) $purchase['party_id'], 'payable'), 'amount' => -$total, 'memo' => 'Purchase ' . $purchase['purchase_no']];
        } else {
            $legs[] = ['ledger_id' => (int) $purchase['settle_ledger_id'], 'amount' => -$total, 'memo' => 'Purchase ' . $purchase['purchase_no']];
        }

        $voucherId = create_voucher_with_entries([
            'company_id' => $companyId,
            'fiscal_year_id' => (int) $purchase['fiscal_year_id'],
            'voucher_no' => (string) $purchase['purchase_no'],
            'voucher_type' => 'purchase',
            'voucher_date' => (string) $purchase['purchase_date'],
            'source_type' => 'jewellery_purchase',
            'source_id' => $purchaseId,
            'party_id' => $purchase['party_id'],
            'reference_no' => $purchase['ref_no'],
            'narration' => (string) ($purchase['narration'] ?? ('Jewellery purchase ' . $purchase['purchase_no'])),
            'total_amount' => $total,
            'status' => 'posted',
            'posted_by' => $userId ?: null,
        ], jw_build_entries($legs));

        foreach ($lines as $line) {
            $txnId = jw_record_stock_txn($companyId, [
                'item_id' => (int) $line['item_id'],
                'txn_type' => 'purchase',
                'direction' => 'in',
                'txn_date' => (string) $purchase['purchase_date'],
                'ref_no' => (string) $purchase['purchase_no'],
                'holder_type' => 'stock',
                'purity_id' => (int) $line['purity_id'],
                'unit_id' => (int) $line['unit_id'],
                'qty_pieces' => (float) $line['qty_pieces'],
                'gross_weight' => (float) $line['gross_weight'],
                'fine_weight' => (float) $line['fine_weight'],
                'rate' => (float) $line['rate'],
                'amount' => (float) $line['stock_amount'],
                'source_type' => 'jewellery_purchase',
                'source_id' => $purchaseId,
                'voucher_id' => $voucherId,
                'party_id' => (int) ($purchase['party_id'] ?? 0) ?: null,
                'created_by' => $userId,
            ]);
            db()->prepare('UPDATE jewellery_purchase_lines SET stock_txn_id = :t WHERE id = :id')
                ->execute(['t' => $txnId, 'id' => (int) $line['id']]);
        }

        // A credit purchase opens a bill so the payable can be settled bill by bill.
        if ((string) $purchase['settle_mode'] === 'credit' && (float) $purchase['balance_amount'] > 0) {
            jw_open_bill($companyId, [
                'fiscal_year_id' => (int) $purchase['fiscal_year_id'],
                'party_id' => (int) $purchase['party_id'],
                'bill_type' => 'purchase',
                'source_type' => 'jewellery_purchase',
                'source_id' => $purchaseId,
                'bill_no' => (string) $purchase['purchase_no'],
                'bill_date' => (string) $purchase['purchase_date'],
                'bill_amount' => (float) $purchase['balance_amount'],
                'voucher_id' => $voucherId,
            ]);
        }

        db()->prepare("UPDATE jewellery_purchases SET status = 'posted', voucher_id = :v, posted_by = :by, posted_at = NOW()
            WHERE id = :id AND company_id = :cid")
            ->execute(['v' => $voucherId, 'by' => $userId ?: null, 'id' => $purchaseId, 'cid' => $companyId]);

        if ($ownsTransaction) {
            db()->commit();
        }

        return ['ok' => true, 'error' => '', 'voucher_id' => $voucherId];
    } catch (Throwable $postingException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $postingException->getMessage()];
    }
}

/** Reverse a posted purchase back to draft. */
function jewellery_unpost_purchase(int $companyId, int $purchaseId, int $userId = 0): array
{
    return jw_unpost_document($companyId, 'jewellery_purchases', 'jewellery_purchase', $purchaseId, $userId);
}

function jewellery_delete_purchase(int $companyId, int $purchaseId): bool
{
    $stmt = db()->prepare("DELETE FROM jewellery_purchases WHERE id = :id AND company_id = :cid AND status = 'draft'");
    $stmt->execute(['id' => $purchaseId, 'cid' => $companyId]);

    return $stmt->rowCount() > 0;
}

// ---------------------------------------------------------------------------
// Sales
// ---------------------------------------------------------------------------

function jewellery_sale(int $companyId, int $saleId): ?array
{
    $stmt = db()->prepare('SELECT s.*, ap.name AS party_name, ap.code AS party_code
        FROM jewellery_sales s
        LEFT JOIN accounting_parties ap ON ap.id = s.party_id
        WHERE s.id = :id AND s.company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $saleId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function jewellery_sale_line_rows(int $companyId, int $saleId): array
{
    $stmt = db()->prepare('SELECT l.*, i.sku AS item_code, i.name AS item_name, jp.jewellery_type AS item_type, i.category,
            p.code AS purity_code, p.fineness, u.code AS unit_code
        FROM jewellery_sale_lines l
        INNER JOIN inventory_items i ON i.id = l.item_id
            INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        INNER JOIN jewellery_purities p ON p.id = l.purity_id
        INNER JOIN jewellery_units u ON u.id = l.unit_id
        WHERE l.company_id = :cid AND l.sale_id = :sid ORDER BY l.id ASC');
    $stmt->execute(['cid' => $companyId, 'sid' => $saleId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jewellery_sale_exchange_rows(int $companyId, int $saleId): array
{
    $stmt = db()->prepare('SELECT x.*, i.sku AS item_code, i.name AS item_name,
            p.code AS purity_code, p.fineness, u.code AS unit_code
        FROM jewellery_sale_exchanges x
        INNER JOIN inventory_items i ON i.id = x.item_id
            INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        INNER JOIN jewellery_purities p ON p.id = x.purity_id
        INNER JOIN jewellery_units u ON u.id = x.unit_id
        WHERE x.company_id = :cid AND x.sale_id = :sid ORDER BY x.id ASC');
    $stmt->execute(['cid' => $companyId, 'sid' => $saleId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jewellery_sales_list(int $companyId, array $filters = []): array
{
    $sql = 'SELECT s.*, ap.name AS party_name FROM jewellery_sales s
        LEFT JOIN accounting_parties ap ON ap.id = s.party_id
        WHERE s.company_id = :cid';
    $params = ['cid' => $companyId];
    if (($filters['from'] ?? '') !== '' && ($filters['to'] ?? '') !== '') {
        $sql .= ' AND s.sale_date BETWEEN :from AND :to';
        $params['from'] = (string) $filters['from'];
        $params['to'] = (string) $filters['to'];
    }
    if (($filters['status'] ?? '') !== '') {
        $sql .= ' AND s.status = :st';
        $params['st'] = (string) $filters['status'];
    }
    if (!empty($filters['party_id'])) {
        $sql .= ' AND s.party_id = :pid';
        $params['pid'] = (int) $filters['party_id'];
    }
    $sql .= ' ORDER BY s.sale_date DESC, s.id DESC LIMIT ' . max(1, min(1000, (int) ($filters['limit'] ?? 200)));

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create or revise a DRAFT sale with its lines and any old gold taken in
 * exchange. The three settlement legs must add up to the total, and that is
 * enforced here rather than left to the caller.
 */
function jewellery_save_sale(int $companyId, int $fiscalYearId, array $header, array $lines, array $exchanges = [], int $userId = 0): int
{
    $saleId = (int) ($header['id'] ?? 0);
    if ($saleId > 0) {
        $existing = jewellery_sale($companyId, $saleId);
        if (!$existing) {
            throw new RuntimeException('Sale not found for this company.');
        }
        if ((string) $existing['status'] !== 'draft') {
            throw new RuntimeException('This sale is already posted. Unpost it before revising.');
        }
    }

    $settings = jewellery_settings($companyId);
    $date = (string) ($header['sale_date'] ?? date('Y-m-d'));
    $computed = jw_compute_document($companyId, [
        'document_date' => $date,
        'doc_type' => 'sale',
        'rate_type' => 'sale',
        'other_charges' => $header['other_charges'] ?? 0,
        'discount' => $header['discount'] ?? 0,
        'manual_tax_amount' => $header['manual_tax_amount'] ?? null,
    ], $lines, $settings);
    if ($computed['errors'] !== []) {
        throw new RuntimeException(implode(' ', $computed['errors']));
    }
    if ($computed['lines'] === []) {
        throw new RuntimeException('A sale needs at least one line.');
    }

    // Every buyer is a party with a ledger — a counter customer typed by name
    // is created on the spot rather than being left anonymous, so they get a
    // statement, an outstanding balance and an order history like anyone else.
    $partyId = jw_resolve_party($companyId, $header + ['party_name' => $header['customer_name'] ?? ''], 'customer') ?: null;
    if ($partyId === null) {
        throw new RuntimeException('Enter the customer — a name is enough, the party and its ledger are created automatically.');
    }

    // Value the old gold coming back across the counter.
    $computedExchanges = [];
    $exchangeTotal = 0.0;
    foreach ($exchanges as $index => $row) {
        $itemId = (int) ($row['item_id'] ?? 0);
        $item = jewellery_item($companyId, $itemId);
        if (!$item) {
            throw new RuntimeException('Exchange line ' . ($index + 1) . ': unknown item.');
        }
        $purityId = (int) ($row['purity_id'] ?? $item['purity_id']);
        $purity = jewellery_purity($companyId, $purityId);
        if (!$purity || (int) $purity['metal_id'] !== (int) $item['metal_id']) {
            throw new RuntimeException('Exchange line ' . ($index + 1) . ': the purity must belong to the item\'s metal.');
        }
        $unitId = (int) ($row['unit_id'] ?? $item['unit_id']);
        if (!jewellery_unit($companyId, $unitId)) {
            throw new RuntimeException('Exchange line ' . ($index + 1) . ': unknown weight unit.');
        }
        $gross = jw_round_weight((float) ($row['gross_weight'] ?? 0));
        if ($gross <= 0) {
            throw new RuntimeException('Exchange line ' . ($index + 1) . ': enter the weight taken in.');
        }
        $rate = jw_round_rate((float) ($row['rate'] ?? 0));
        $amount = jw_round_money($gross * $rate);
        if ($rate <= 0) {
            // Old gold is bought at the PURCHASE quote, not the sale quote.
            $valued = jewellery_metal_value($companyId, (int) $item['metal_id'], $purityId, $gross, $unitId, $date, 'purchase', $settings);
            if ($valued['ok']) {
                $amount = $valued['amount'];
                $rate = jw_round_rate($amount / $gross);
            }
        }
        $computedExchanges[] = [
            'item_id' => $itemId, 'purity_id' => $purityId, 'unit_id' => $unitId,
            'qty_pieces' => round((float) ($row['qty_pieces'] ?? 0), 3),
            'gross_weight' => $gross,
            'fine_weight' => jw_fine_weight($gross, (float) $purity['fineness']),
            'rate' => $rate, 'amount' => $amount,
            'notes' => trim((string) ($row['notes'] ?? '')),
        ];
        $exchangeTotal += $amount;
    }
    $exchangeTotal = jw_round_money($exchangeTotal);

    $totals = $computed['totals'];
    $total = $totals['total_amount'];
    $received = jw_round_money((float) ($header['received_amount'] ?? 0));
    if ($received < 0) {
        throw new RuntimeException('The amount received cannot be negative.');
    }
    // The fourth way a sale can be paid for: an advance already taken against
    // the order being delivered. It is capped at what is actually still held,
    // so the same advance can never be applied to two sales.
    $deliverOrderId = (int) ($header['deliver_order_id'] ?? 0);
    $advanceApplied = jw_round_money((float) ($header['advance_amount'] ?? 0));
    if ($advanceApplied < 0) {
        throw new RuntimeException('An advance applied cannot be negative.');
    }
    if ($advanceApplied > 0) {
        if ($deliverOrderId <= 0) {
            throw new RuntimeException('An advance can only be applied to the order it was taken against.');
        }
        $available = jewellery_order_advance_available($companyId, $deliverOrderId, $saleId);
        if ($advanceApplied > $available + 0.005) {
            throw new RuntimeException('Only ' . number_format($available, 2) . ' of advance is still held against this order — '
                . 'cannot apply ' . number_format($advanceApplied, 2) . '.');
        }
    }

    // The settlement identity, now with four legs. Over-paying is still
    // refused: an excess advance is not applied here and quietly turned into a
    // negative balance — it stays in the customer's advance account, where it
    // is refunded in cash or in gold as its own settlement, which is the only
    // treatment that leaves a trail of what was actually handed back.
    $settledSoFar = jw_round_money($received + $exchangeTotal + $advanceApplied);
    if ($settledSoFar > $total + 0.005) {
        throw new RuntimeException('Cash received, old gold and advance applied (' . number_format($settledSoFar, 2)
            . ') exceed the sale total (' . number_format($total, 2) . ').'
            . ($advanceApplied > 0 ? ' Apply only what this sale comes to and refund the rest of the advance separately.' : ''));
    }

    $balance = jw_round_money($total - $received - $exchangeTotal - $advanceApplied);

    $settleMode = jw_enum($header['settle_mode'] ?? null, ['credit', 'cash', 'bank'], 'cash');
    $settleLedgerId = (int) ($header['settle_ledger_id'] ?? 0) ?: null;
    if ($received > 0) {
        if ($settleLedgerId === null) {
            throw new RuntimeException('Choose the cash or bank ledger the money was received into.');
        }
        $check = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :id AND company_id = :cid');
        $check->execute(['id' => $settleLedgerId, 'cid' => $companyId]);
        if ((int) $check->fetchColumn() === 0) {
            throw new RuntimeException('That settlement ledger does not belong to this company.');
        }
    }

    $params = [
        'cid' => $companyId, 'fy' => $fiscalYearId ?: null, 'date' => $date, 'party' => $partyId,
        'cname' => trim((string) ($header['customer_name'] ?? '')) ?: null,
        'ref' => trim((string) ($header['ref_no'] ?? '')) ?: null,
        'narration' => trim((string) ($header['narration'] ?? '')) ?: null,
        'metal' => $totals['metal_amount'], 'wastage' => $totals['wastage_amount'],
        'making' => $totals['making_amount'], 'stone' => $totals['stone_amount'],
        'other' => $totals['other_charges'], 'discount' => $totals['discount'], 'taxable' => $totals['taxable_amount'],
        'vat' => $totals['vat_amount'], 'tax' => $totals['tax_amount'], 'mtax' => $totals['manual_tax_amount'],
        'total' => $total,
        'received' => $received, 'exchange' => $exchangeTotal, 'advance' => $advanceApplied, 'balance' => $balance,
        'smode' => $settleMode, 'sledger' => $settleLedgerId,
    ];

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        if ($saleId > 0) {
            db()->prepare('UPDATE jewellery_sales SET fiscal_year_id = :fy, sale_date = :date, party_id = :party,
                    customer_name = :cname, ref_no = :ref, narration = :narration, metal_amount = :metal,
                    wastage_amount = :wastage, making_amount = :making, stone_amount = :stone, other_charges = :other,
                    discount = :discount, taxable_amount = :taxable, vat_amount = :vat, tax_amount = :tax,
                    manual_tax_amount = :mtax, total_amount = :total, received_amount = :received,
                    exchange_amount = :exchange, advance_amount = :advance, balance_amount = :balance,
                    settle_mode = :smode, settle_ledger_id = :sledger
                WHERE id = :id AND company_id = :cid')
                ->execute($params + ['id' => $saleId]);
            db()->prepare('DELETE FROM jewellery_sale_lines WHERE sale_id = :sid AND company_id = :cid')->execute(['sid' => $saleId, 'cid' => $companyId]);
            db()->prepare('DELETE FROM jewellery_sale_exchanges WHERE sale_id = :sid AND company_id = :cid')->execute(['sid' => $saleId, 'cid' => $companyId]);
        } else {
            $no = trim((string) ($header['sale_no'] ?? '')) ?: jw_next_no($companyId, 'jewellery_sales', 'sale_no', (string) ($settings['sale_no_prefix'] ?? 'JS'));
            db()->prepare('INSERT INTO jewellery_sales (company_id, fiscal_year_id, sale_no, sale_date, party_id, customer_name,
                    ref_no, narration, metal_amount, wastage_amount, making_amount, stone_amount, other_charges, discount,
                    taxable_amount, vat_amount, tax_amount, manual_tax_amount, total_amount, received_amount, exchange_amount,
                    advance_amount, balance_amount, settle_mode, settle_ledger_id, created_by)
                VALUES (:cid, :fy, :no, :date, :party, :cname, :ref, :narration, :metal, :wastage, :making, :stone, :other, :discount,
                    :taxable, :vat, :tax, :mtax, :total, :received, :exchange, :advance, :balance, :smode, :sledger, :by)')
                ->execute($params + ['no' => $no, 'by' => $userId ?: null]);
            $saleId = (int) db()->lastInsertId();
        }

        $lineStmt = db()->prepare('INSERT INTO jewellery_sale_lines (sale_id, company_id, item_id, purity_id, unit_id,
                qty_pieces, gross_weight, stone_weight, net_weight, fine_weight, rate, metal_amount,
                wastage_pct, wastage_amount, making_amount, stone_amount,
                vat_base, vat_rate, vat_amount, tax_amount, allocated_adjust, line_total, notes)
            VALUES (:sid, :cid, :item, :purity, :unit, :pieces, :gross, :sweight, :net, :fine, :rate, :metal,
                :wpct, :wamount, :making, :stone, :vbase, :vrate, :vamount, :tamount, :adjust, :ltotal, :notes)');
        foreach ($computed['lines'] as $row) {
            $lineStmt->execute([
                'sid' => $saleId, 'cid' => $companyId, 'item' => $row['item_id'], 'purity' => $row['purity_id'],
                'unit' => $row['unit_id'], 'pieces' => $row['qty_pieces'], 'gross' => $row['gross_weight'],
                'sweight' => $row['stone_weight'], 'net' => $row['net_weight'],
                'fine' => $row['fine_weight'], 'rate' => $row['rate'], 'metal' => $row['metal_amount'],
                'wpct' => $row['wastage_pct'], 'wamount' => $row['wastage_amount'],
                'making' => $row['making_amount'], 'stone' => $row['stone_amount'], 'vbase' => $row['vat_base'],
                'vrate' => $row['vat_rate'], 'vamount' => $row['vat_amount'], 'tamount' => $row['tax_amount'],
                'adjust' => $row['allocated_adjust'],
                'ltotal' => $row['line_total'], 'notes' => $row['notes'] !== '' ? $row['notes'] : null,
            ]);
            jw_save_line_taxes($companyId, 'sale', $saleId, (int) db()->lastInsertId(), $row);
        }

        $exStmt = db()->prepare('INSERT INTO jewellery_sale_exchanges (sale_id, company_id, item_id, purity_id, unit_id,
                qty_pieces, gross_weight, fine_weight, rate, amount, notes)
            VALUES (:sid, :cid, :item, :purity, :unit, :pieces, :gross, :fine, :rate, :amount, :notes)');
        foreach ($computedExchanges as $row) {
            $exStmt->execute([
                'sid' => $saleId, 'cid' => $companyId, 'item' => $row['item_id'], 'purity' => $row['purity_id'],
                'unit' => $row['unit_id'], 'pieces' => $row['qty_pieces'], 'gross' => $row['gross_weight'],
                'fine' => $row['fine_weight'], 'rate' => $row['rate'], 'amount' => $row['amount'],
                'notes' => $row['notes'] !== '' ? $row['notes'] : null,
            ]);
        }

        // Record WHICH sale is billing this order, here in the engine rather
        // than in the page that happened to call it. The advance cap reads this
        // link to work out what has already been applied, so leaving it to the
        // caller would mean an advance looked unused — and could be applied a
        // second time — whenever a sale was saved by any other route.
        // This is the LINK only; marking the order delivered stays a separate,
        // deliberate act.
        if ($deliverOrderId > 0) {
            db()->prepare("UPDATE jewellery_orders SET delivered_sale_id = :sale
                WHERE id = :id AND company_id = :cid AND status <> 'cancelled'")
                ->execute(['sale' => $saleId, 'id' => $deliverOrderId, 'cid' => $companyId]);
        }

        if ($ownsTransaction) {
            db()->commit();
        }
    } catch (Throwable $saveException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $saveException;
    }

    return $saleId;
}

/**
 * Post a sale.
 *
 *   Dr  cash/bank            received_amount
 *   Dr  stock (old gold)     exchange_amount        <- the metal-to-metal leg
 *   Dr  party                balance_amount
 *   Dr  sales discount       discount
 *       Cr  sales — metal / making / stone
 *       Cr  VAT output
 *       Cr  other charges recovered
 *   Dr  COGS  /  Cr stock    at the weighted-average cost in force NOW
 */
function jewellery_post_sale(int $companyId, int $saleId, int $userId = 0): array
{
    $sale = jewellery_sale($companyId, $saleId);
    if (!$sale) {
        return ['ok' => false, 'error' => 'Sale not found for this company.'];
    }
    if ((string) $sale['status'] !== 'draft') {
        return ['ok' => false, 'error' => 'This sale is already posted.'];
    }
    $lines = jewellery_sale_line_rows($companyId, $saleId);
    if ($lines === []) {
        return ['ok' => false, 'error' => 'This sale has no lines.'];
    }
    $exchanges = jewellery_sale_exchange_rows($companyId, $saleId);

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        $legs = [];
        $saleDate = (string) $sale['sale_date'];

        // Revenue side, resolved PER LINE so each item (or category) can carry
        // its own sales ledger. jw_build_entries nets the legs per ledger at
        // the end, so an unmapped shop still gets exactly one metal-sales line.
        $itemCache = [];
        $itemOf = static function (int $itemId) use ($companyId, &$itemCache): ?array {
            if (!array_key_exists($itemId, $itemCache)) {
                $itemCache[$itemId] = jewellery_item($companyId, $itemId);
            }

            return $itemCache[$itemId];
        };

        foreach ($lines as $line) {
            $lineItem = $itemOf((int) $line['item_id']);
            $label = ' ' . $sale['sale_no'] . ' · ' . (string) ($lineItem['code'] ?? '');
            foreach ([
                ['metal_amount', 'sales_metal', 'Metal value'],
                // Wastage is metal sold, so it belongs with the metal revenue.
                ['wastage_amount', 'sales_metal', 'Wastage'],
                ['making_amount', 'sales_making', 'Making charge'],
                ['stone_amount', 'sales_stone', 'Stone value'],
            ] as [$column, $purpose, $memo]) {
                $amount = jw_round_money((float) ($line[$column] ?? 0));
                if ($amount > 0) {
                    $legs[] = ['ledger_id' => jw_item_ledger($companyId, $purpose, $lineItem),
                        'amount' => -$amount, 'memo' => $memo . $label];
                }
            }
        }

        // Taxes are the deliberate exception to item-wise posting: one payable
        // per TAX for the whole shop, because that is the figure each office is
        // owed. Each tax names the purpose it credits, so a new tax posts to
        // its own account without touching this code.
        $documentTaxes = jw_document_taxes($companyId, 'sale', $saleId);
        $postedTax = 0.0;
        foreach ($documentTaxes as $tax) {
            $amount = jw_round_money((float) $tax['amount']);
            if (abs($amount) < 0.005) {
                continue;
            }
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, (string) $tax['output_purpose']),
                'amount' => -$amount, 'memo' => $tax['tax_code'] . ' ' . $sale['sale_no']];
            $postedTax = jw_round_money($postedTax + $amount);
        }
        // A punched tax total that differs from the computed one still has to
        // reach the ledger, or the voucher will not balance against the total.
        $headerTax = jw_round_money((float) $sale['vat_amount'] + (float) ($sale['tax_amount'] ?? 0));
        $taxGap = jw_round_money($headerTax - $postedTax);
        if (abs($taxGap) >= 0.005) {
            $gapPurpose = $documentTaxes !== [] ? (string) $documentTaxes[0]['output_purpose'] : 'vat_output';
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, $gapPurpose),
                'amount' => -$taxGap, 'memo' => 'Tax adjustment ' . $sale['sale_no']];
        }
        if ((float) $sale['other_charges'] > 0) {
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, 'other_charges'), 'amount' => -(float) $sale['other_charges'], 'memo' => 'Other charges ' . $sale['sale_no']];
        }
        if ((float) $sale['discount'] > 0) {
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, 'sales_discount'), 'amount' => (float) $sale['discount'], 'memo' => 'Discount ' . $sale['sale_no']];
        }

        // Settlement side.
        if ((float) $sale['received_amount'] > 0) {
            $legs[] = ['ledger_id' => (int) $sale['settle_ledger_id'], 'amount' => (float) $sale['received_amount'], 'memo' => 'Received ' . $sale['sale_no']];
        }
        // Applying an advance CLEARS the liability the shop has been carrying
        // since the order was taken — it does not touch cash, because the money
        // (or the gold) arrived weeks ago and was posted then.
        if ((float) ($sale['advance_amount'] ?? 0) > 0) {
            $legs[] = ['ledger_id' => jw_party_ledger($companyId, (int) $sale['party_id'], 'advance'),
                'amount' => (float) $sale['advance_amount'], 'memo' => 'Advance applied ' . $sale['sale_no']];
        }
        if ((float) $sale['balance_amount'] > 0) {
            $legs[] = ['ledger_id' => jw_party_ledger($companyId, (int) $sale['party_id'], 'receivable'), 'amount' => (float) $sale['balance_amount'], 'memo' => 'Balance ' . $sale['sale_no']];
        }
        foreach ($exchanges as $exchange) {
            $exItem = jewellery_item($companyId, (int) $exchange['item_id']);
            $exLedgerId = jw_item_stock_ledger_id($companyId, $exItem);
            if ($exLedgerId <= 0) {
                throw new RuntimeException('No stock ledger is mapped for exchange item ' . $exItem['code'] . '.');
            }
            $legs[] = ['ledger_id' => $exLedgerId, 'amount' => (float) $exchange['amount'], 'memo' => 'Old gold in exchange ' . $sale['sale_no']];
        }

        // Cost of sales, priced at the weighted average in force right now.
        $cogsTotal = 0.0;
        $lineCogs = [];
        foreach ($lines as $line) {
            $item = jewellery_item($companyId, (int) $line['item_id']);
            // Cost pool = every holder, as at the sale date. Same pool the
            // karigar and refinery issues use, so one item has one cost.
            $balance = jw_item_balance($companyId, (int) $line['item_id'], $saleDate, '');
            $cost = jw_round_money((float) $line['fine_weight'] * $balance['avg_fine_rate']);
            $lineCogs[(int) $line['id']] = $cost;
            $cogsTotal += $cost;
            if ($cost > 0) {
                $stockLedgerId = jw_item_stock_ledger_id($companyId, $item);
                if ($stockLedgerId <= 0) {
                    throw new RuntimeException('No stock ledger is mapped for item ' . $item['code'] . '.');
                }
                $legs[] = ['ledger_id' => $stockLedgerId, 'amount' => -$cost, 'memo' => 'COGS ' . $sale['sale_no']];
            }
        }
        $cogsTotal = jw_round_money($cogsTotal);
        if ($cogsTotal > 0) {
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, 'cogs'), 'amount' => $cogsTotal, 'memo' => 'COGS ' . $sale['sale_no']];
        }

        $voucherId = create_voucher_with_entries([
            'company_id' => $companyId,
            'fiscal_year_id' => (int) $sale['fiscal_year_id'],
            'voucher_no' => (string) $sale['sale_no'],
            'voucher_type' => 'sales',
            'voucher_date' => $saleDate,
            'source_type' => 'jewellery_sale',
            'source_id' => $saleId,
            'party_id' => $sale['party_id'],
            'reference_no' => $sale['ref_no'],
            'narration' => (string) ($sale['narration'] ?? ('Jewellery sale ' . $sale['sale_no'])),
            'total_amount' => (float) $sale['total_amount'],
            'status' => 'posted',
            'posted_by' => $userId ?: null,
        ], jw_build_entries($legs));

        // Metal out at cost — the stock ledger must fall by cost, not by price.
        foreach ($lines as $line) {
            $cost = $lineCogs[(int) $line['id']] ?? 0.0;
            $txnId = jw_record_stock_txn($companyId, [
                'item_id' => (int) $line['item_id'],
                'txn_type' => 'sale',
                'direction' => 'out',
                'txn_date' => $saleDate,
                'ref_no' => (string) $sale['sale_no'],
                'holder_type' => 'stock',
                'purity_id' => (int) $line['purity_id'],
                'unit_id' => (int) $line['unit_id'],
                'qty_pieces' => (float) $line['qty_pieces'],
                'gross_weight' => (float) $line['gross_weight'],
                'fine_weight' => (float) $line['fine_weight'],
                'rate' => (float) $line['rate'],
                'amount' => $cost,
                'source_type' => 'jewellery_sale',
                'source_id' => $saleId,
                'voucher_id' => $voucherId,
                'party_id' => (int) ($sale['party_id'] ?? 0) ?: null,
                'created_by' => $userId,
            ]);
            db()->prepare('UPDATE jewellery_sale_lines SET cogs_amount = :c, stock_txn_id = :t WHERE id = :id')
                ->execute(['c' => $cost, 't' => $txnId, 'id' => (int) $line['id']]);
        }

        // Old gold in, at the value it was allowed against the sale.
        foreach ($exchanges as $exchange) {
            $txnId = jw_record_stock_txn($companyId, [
                'item_id' => (int) $exchange['item_id'],
                'txn_type' => 'purchase',
                'direction' => 'in',
                'txn_date' => $saleDate,
                'ref_no' => (string) $sale['sale_no'],
                'holder_type' => 'stock',
                'purity_id' => (int) $exchange['purity_id'],
                'unit_id' => (int) $exchange['unit_id'],
                'qty_pieces' => (float) $exchange['qty_pieces'],
                'gross_weight' => (float) $exchange['gross_weight'],
                'fine_weight' => (float) $exchange['fine_weight'],
                'rate' => (float) $exchange['rate'],
                'amount' => (float) $exchange['amount'],
                'source_type' => 'jewellery_sale_exchange',
                'source_id' => $saleId,
                'voucher_id' => $voucherId,
                'party_id' => (int) ($sale['party_id'] ?? 0) ?: null,
                'notes' => 'Old gold taken in exchange',
                'created_by' => $userId,
            ]);
            db()->prepare('UPDATE jewellery_sale_exchanges SET stock_txn_id = :t WHERE id = :id')
                ->execute(['t' => $txnId, 'id' => (int) $exchange['id']]);
        }

        if ((float) $sale['balance_amount'] > 0 && (int) ($sale['party_id'] ?? 0) > 0) {
            jw_open_bill($companyId, [
                'fiscal_year_id' => (int) $sale['fiscal_year_id'],
                'party_id' => (int) $sale['party_id'],
                'bill_type' => 'sale',
                'source_type' => 'jewellery_sale',
                'source_id' => $saleId,
                'bill_no' => (string) $sale['sale_no'],
                'bill_date' => $saleDate,
                'bill_amount' => (float) $sale['balance_amount'],
                'voucher_id' => $voucherId,
            ]);
        }

        db()->prepare("UPDATE jewellery_sales SET status = 'posted', voucher_id = :v, cogs_amount = :c,
                posted_by = :by, posted_at = NOW() WHERE id = :id AND company_id = :cid")
            ->execute(['v' => $voucherId, 'c' => $cogsTotal, 'by' => $userId ?: null, 'id' => $saleId, 'cid' => $companyId]);

        if ($ownsTransaction) {
            db()->commit();
        }

        return ['ok' => true, 'error' => '', 'voucher_id' => $voucherId, 'cogs' => $cogsTotal];
    } catch (Throwable $postingException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $postingException->getMessage()];
    }
}

function jewellery_unpost_sale(int $companyId, int $saleId, int $userId = 0): array
{
    return jw_unpost_document($companyId, 'jewellery_sales', 'jewellery_sale', $saleId, $userId);
}

function jewellery_delete_sale(int $companyId, int $saleId): bool
{
    $stmt = db()->prepare("DELETE FROM jewellery_sales WHERE id = :id AND company_id = :cid AND status = 'draft'");
    $stmt->execute(['id' => $saleId, 'cid' => $companyId]);

    return $stmt->rowCount() > 0;
}

/**
 * Shared reversal for purchases, sales and settlements: drop the voucher, the
 * stock movements and the bill, and put the document back to draft.
 *
 * Refuses when the bill has already been part-settled, because the money that
 * settled it would be left pointing at nothing.
 */
function jw_unpost_document(int $companyId, string $table, string $sourceType, int $documentId, int $userId = 0): array
{
    $allowedTables = ['jewellery_purchases', 'jewellery_sales', 'jewellery_settlements'];
    if (!in_array($table, $allowedTables, true)) {
        return ['ok' => false, 'error' => 'Unknown jewellery document type.'];
    }

    $stmt = db()->prepare("SELECT * FROM `$table` WHERE id = :id AND company_id = :cid LIMIT 1");
    $stmt->execute(['id' => $documentId, 'cid' => $companyId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$document) {
        return ['ok' => false, 'error' => 'Document not found for this company.'];
    }
    if ((string) $document['status'] !== 'posted') {
        return ['ok' => false, 'error' => 'This document is not posted.'];
    }

    $billStmt = db()->prepare('SELECT * FROM jewellery_bills WHERE company_id = :cid AND source_type = :st AND source_id = :sid LIMIT 1');
    $billStmt->execute(['cid' => $companyId, 'st' => $sourceType, 'sid' => $documentId]);
    $bill = $billStmt->fetch(PDO::FETCH_ASSOC);
    if ($bill && (float) $bill['settled_amount'] > 0.005) {
        return ['ok' => false, 'error' => 'This document\'s bill has already been part settled. Reverse the settlement first.'];
    }

    $voucherId = (int) ($document['voucher_id'] ?? 0);
    if ($voucherId > 0) {
        $vStmt = db()->prepare('SELECT * FROM vouchers WHERE id = :id AND company_id = :cid LIMIT 1');
        $vStmt->execute(['id' => $voucherId, 'cid' => $companyId]);
        $voucher = $vStmt->fetch(PDO::FETCH_ASSOC);
        if ($voucher) {
            $blocker = voucher_mutation_blocker($voucher, [$sourceType]);
            if ($blocker !== null) {
                return ['ok' => false, 'error' => $blocker];
            }
        }
    }

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        if ($voucherId > 0) {
            db()->prepare('DELETE FROM vouchers WHERE id = :id AND company_id = :cid')->execute(['id' => $voucherId, 'cid' => $companyId]);
        }
        // A sale also writes exchange movements under their own source type.
        $sourceTypes = $sourceType === 'jewellery_sale' ? [$sourceType, 'jewellery_sale_exchange'] : [$sourceType];
        $placeholders = implode(',', array_fill(0, count($sourceTypes), '?'));
        db()->prepare("DELETE FROM jewellery_stock_txns WHERE company_id = ? AND source_id = ? AND source_type IN ($placeholders)")
            ->execute(array_merge([$companyId, $documentId], $sourceTypes));
        if ($bill) {
            db()->prepare('DELETE FROM jewellery_bills WHERE id = :id AND company_id = :cid')->execute(['id' => (int) $bill['id'], 'cid' => $companyId]);
        }
        db()->prepare("UPDATE `$table` SET status = 'draft', voucher_id = NULL, posted_by = NULL, posted_at = NULL
            WHERE id = :id AND company_id = :cid")->execute(['id' => $documentId, 'cid' => $companyId]);

        if ($ownsTransaction) {
            db()->commit();
        }
        log_activity('company', $companyId, 'jewellery_unpost',
            ucfirst(str_replace('jewellery_', '', $sourceType)) . ' #' . $documentId . ' unposted; its voucher and stock movements were removed.', $userId);

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $unpostException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $unpostException->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// Bills and settlements (bill-wise party accounting)
// ---------------------------------------------------------------------------

/** Open (or refresh) the bill a posted document leaves behind. */
function jw_open_bill(int $companyId, array $bill): int
{
    $existing = db()->prepare('SELECT id FROM jewellery_bills WHERE company_id = :cid AND source_type = :st AND source_id = :sid LIMIT 1');
    $existing->execute(['cid' => $companyId, 'st' => $bill['source_type'], 'sid' => $bill['source_id']]);
    $billId = (int) ($existing->fetchColumn() ?: 0);

    $params = [
        'cid' => $companyId,
        'fy' => (int) ($bill['fiscal_year_id'] ?? 0) ?: null,
        'party' => (int) $bill['party_id'],
        'type' => (string) $bill['bill_type'],
        'st' => (string) $bill['source_type'],
        'sid' => (int) $bill['source_id'],
        'no' => (string) $bill['bill_no'],
        'date' => (string) $bill['bill_date'],
        'due' => ($bill['due_date'] ?? null) ?: null,
        'amount' => jw_round_money((float) $bill['bill_amount']),
        'voucher' => (int) ($bill['voucher_id'] ?? 0) ?: null,
    ];

    if ($billId > 0) {
        db()->prepare("UPDATE jewellery_bills SET bill_amount = :amount, bill_date = :date, due_date = :due,
                bill_no = :no, voucher_id = :voucher, status = 'open'
            WHERE id = :id AND company_id = :cid")
            ->execute(['amount' => $params['amount'], 'date' => $params['date'], 'due' => $params['due'],
                'no' => $params['no'], 'voucher' => $params['voucher'], 'id' => $billId, 'cid' => $companyId]);

        return $billId;
    }

    db()->prepare('INSERT INTO jewellery_bills (company_id, fiscal_year_id, party_id, bill_type, source_type, source_id,
            bill_no, bill_date, due_date, bill_amount, voucher_id)
        VALUES (:cid, :fy, :party, :type, :st, :sid, :no, :date, :due, :amount, :voucher)')
        ->execute($params);

    return (int) db()->lastInsertId();
}

/** Recompute a bill's settled amount and status from its allocations. */
function jw_refresh_bill(int $companyId, int $billId): void
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(a.amount), 0) FROM jewellery_settlement_allocations a
        INNER JOIN jewellery_settlements s ON s.id = a.settlement_id
        WHERE a.company_id = :cid AND a.bill_id = :bid AND s.status = 'posted'");
    $stmt->execute(['cid' => $companyId, 'bid' => $billId]);
    $settled = jw_round_money((float) $stmt->fetchColumn());

    $billStmt = db()->prepare('SELECT bill_amount FROM jewellery_bills WHERE id = :id AND company_id = :cid LIMIT 1');
    $billStmt->execute(['id' => $billId, 'cid' => $companyId]);
    $billAmount = jw_round_money((float) ($billStmt->fetchColumn() ?: 0));

    $status = 'open';
    if ($settled >= $billAmount - 0.005 && $billAmount > 0) {
        $status = 'settled';
    } elseif ($settled > 0.005) {
        $status = 'part_settled';
    }

    db()->prepare('UPDATE jewellery_bills SET settled_amount = :s, status = :st WHERE id = :id AND company_id = :cid')
        ->execute(['s' => $settled, 'st' => $status, 'id' => $billId, 'cid' => $companyId]);
}

/** Bills still carrying a balance, newest first — the allocation picker. */
function jewellery_open_bills(int $companyId, int $partyId = 0, string $billType = ''): array
{
    $sql = "SELECT b.*, ap.name AS party_name, (b.bill_amount - b.settled_amount) AS outstanding
        FROM jewellery_bills b
        INNER JOIN accounting_parties ap ON ap.id = b.party_id
        WHERE b.company_id = :cid AND b.status IN ('open', 'part_settled')";
    $params = ['cid' => $companyId];
    if ($partyId > 0) {
        $sql .= ' AND b.party_id = :pid';
        $params['pid'] = $partyId;
    }
    if ($billType !== '') {
        $sql .= ' AND b.bill_type = :bt';
        $params['bt'] = $billType;
    }
    $sql .= ' ORDER BY b.bill_date ASC, b.id ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Every bill with its outstanding, for the bill-wise report. */
function jewellery_bills_list(int $companyId, array $filters = []): array
{
    $sql = 'SELECT b.*, ap.name AS party_name, ap.code AS party_code,
            (b.bill_amount - b.settled_amount) AS outstanding
        FROM jewellery_bills b
        INNER JOIN accounting_parties ap ON ap.id = b.party_id
        WHERE b.company_id = :cid';
    $params = ['cid' => $companyId];
    if (($filters['bill_type'] ?? '') !== '') {
        $sql .= ' AND b.bill_type = :bt';
        $params['bt'] = (string) $filters['bill_type'];
    }
    if (($filters['status'] ?? '') !== '') {
        $sql .= ' AND b.status = :st';
        $params['st'] = (string) $filters['status'];
    }
    if (!empty($filters['party_id'])) {
        $sql .= ' AND b.party_id = :pid';
        $params['pid'] = (int) $filters['party_id'];
    }
    if (!empty($filters['open_only'])) {
        $sql .= " AND b.status IN ('open', 'part_settled')";
    }
    $sql .= ' ORDER BY ap.name ASC, b.bill_date ASC, b.id ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jewellery_settlement(int $companyId, int $settlementId): ?array
{
    $stmt = db()->prepare('SELECT s.*, ap.name AS party_name FROM jewellery_settlements s
        INNER JOIN accounting_parties ap ON ap.id = s.party_id
        WHERE s.id = :id AND s.company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $settlementId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function jewellery_settlements_list(int $companyId, array $filters = []): array
{
    $sql = 'SELECT s.*, ap.name AS party_name FROM jewellery_settlements s
        INNER JOIN accounting_parties ap ON ap.id = s.party_id
        WHERE s.company_id = :cid';
    $params = ['cid' => $companyId];
    if (!empty($filters['party_id'])) {
        $sql .= ' AND s.party_id = :pid';
        $params['pid'] = (int) $filters['party_id'];
    }
    if (($filters['status'] ?? '') !== '') {
        $sql .= ' AND s.status = :st';
        $params['st'] = (string) $filters['status'];
    }
    $sql .= ' ORDER BY s.settlement_date DESC, s.id DESC LIMIT ' . max(1, min(1000, (int) ($filters['limit'] ?? 200)));

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jewellery_settlement_allocations(int $companyId, int $settlementId): array
{
    $stmt = db()->prepare('SELECT a.*, b.bill_no, b.bill_type, b.bill_date, b.bill_amount, b.settled_amount
        FROM jewellery_settlement_allocations a
        INNER JOIN jewellery_bills b ON b.id = a.bill_id
        WHERE a.company_id = :cid AND a.settlement_id = :sid ORDER BY b.bill_date ASC');
    $stmt->execute(['cid' => $companyId, 'sid' => $settlementId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Save a DRAFT settlement and its bill allocations.
 *
 * Allocations may not exceed the settlement amount, and no single bill may be
 * allocated more than it still owes — otherwise a party ledger could look
 * settled while the bills behind it disagree.
 */
function jewellery_save_settlement(int $companyId, int $fiscalYearId, array $header, array $allocations, int $userId = 0): int
{
    $settlementId = (int) ($header['id'] ?? 0);
    if ($settlementId > 0) {
        $existing = jewellery_settlement($companyId, $settlementId);
        if (!$existing) {
            throw new RuntimeException('Settlement not found for this company.');
        }
        if ((string) $existing['status'] !== 'draft') {
            throw new RuntimeException('This settlement is already posted. Unpost it before revising.');
        }
    }

    $settings = jewellery_settings($companyId);
    $partyId = (int) ($header['party_id'] ?? 0);
    $check = db()->prepare('SELECT COUNT(*) FROM accounting_parties WHERE id = :id AND company_id = :cid');
    $check->execute(['id' => $partyId, 'cid' => $companyId]);
    if ((int) $check->fetchColumn() === 0) {
        throw new RuntimeException('Choose a party that belongs to this company.');
    }

    $direction = (string) ($header['direction'] ?? 'paid') === 'received' ? 'received' : 'paid';
    $mode = jw_enum($header['mode'] ?? null, ['cash', 'bank', 'metal', 'adjustment'], 'cash');
    $amount = jw_round_money((float) ($header['amount'] ?? 0));
    if ($amount <= 0) {
        throw new RuntimeException('Enter the settlement amount.');
    }

    $ledgerId = (int) ($header['ledger_id'] ?? 0) ?: null;
    if (in_array($mode, ['cash', 'bank'], true)) {
        if ($ledgerId === null) {
            throw new RuntimeException('Choose the cash or bank ledger for this settlement.');
        }
        $lCheck = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :id AND company_id = :cid');
        $lCheck->execute(['id' => $ledgerId, 'cid' => $companyId]);
        if ((int) $lCheck->fetchColumn() === 0) {
            throw new RuntimeException('That ledger does not belong to this company.');
        }
    } else {
        $ledgerId = null;
    }

    // Metal settlement: the weight moves too, so it needs a full item context.
    $itemId = null; $purityId = null; $unitId = null; $gross = 0.0; $fine = 0.0;
    if ($mode === 'metal') {
        $itemId = (int) ($header['item_id'] ?? 0);
        $item = jewellery_item($companyId, $itemId);
        if (!$item) {
            throw new RuntimeException('A metal settlement needs an item that belongs to this company.');
        }
        $purityId = (int) ($header['purity_id'] ?? $item['purity_id']);
        $purity = jewellery_purity($companyId, $purityId);
        if (!$purity || (int) $purity['metal_id'] !== (int) $item['metal_id']) {
            throw new RuntimeException('The settlement purity must belong to the item\'s metal.');
        }
        $unitId = (int) ($header['unit_id'] ?? $item['unit_id']);
        if (!jewellery_unit($companyId, $unitId)) {
            throw new RuntimeException('The settlement unit must belong to this company.');
        }
        $gross = jw_round_weight((float) ($header['gross_weight'] ?? 0));
        if ($gross <= 0) {
            throw new RuntimeException('Enter the metal weight for this settlement.');
        }
        $fine = jw_fine_weight($gross, (float) $purity['fineness']);
    }

    // Validate allocations against what each bill still owes.
    $cleanAllocations = [];
    $allocatedTotal = 0.0;
    foreach ($allocations as $row) {
        $billId = (int) ($row['bill_id'] ?? 0);
        $allocAmount = jw_round_money((float) ($row['amount'] ?? 0));
        if ($billId <= 0 || $allocAmount <= 0) {
            continue;
        }
        $bStmt = db()->prepare('SELECT * FROM jewellery_bills WHERE id = :id AND company_id = :cid LIMIT 1');
        $bStmt->execute(['id' => $billId, 'cid' => $companyId]);
        $bill = $bStmt->fetch(PDO::FETCH_ASSOC);
        if (!$bill) {
            throw new RuntimeException('An allocation refers to a bill that does not belong to this company.');
        }
        if ((int) $bill['party_id'] !== $partyId) {
            throw new RuntimeException('Bill ' . $bill['bill_no'] . ' belongs to a different party.');
        }
        // What this settlement already contributes must not be double-counted.
        $priorStmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM jewellery_settlement_allocations
            WHERE company_id = :cid AND bill_id = :bid AND settlement_id <> :sid');
        $priorStmt->execute(['cid' => $companyId, 'bid' => $billId, 'sid' => $settlementId]);
        $otherAllocations = jw_round_money((float) $priorStmt->fetchColumn());
        $room = jw_round_money((float) $bill['bill_amount'] - $otherAllocations);
        if ($allocAmount > $room + 0.005) {
            throw new RuntimeException('Bill ' . $bill['bill_no'] . ' only has ' . number_format($room, 2)
                . ' outstanding — cannot allocate ' . number_format($allocAmount, 2) . '.');
        }
        $cleanAllocations[] = ['bill_id' => $billId, 'amount' => $allocAmount];
        $allocatedTotal += $allocAmount;
    }
    if (jw_round_money($allocatedTotal) > $amount + 0.005) {
        throw new RuntimeException('Allocations (' . number_format($allocatedTotal, 2)
            . ') exceed the settlement amount (' . number_format($amount, 2) . ').');
    }

    // An advance is tied to the ORDER it was taken against, and is flagged so
    // it posts to the customer's advance liability rather than their receivable.
    // An advance with no order would be an unattached credit nobody can find,
    // so the flag only means anything when the order is there too.
    $orderId = (int) ($header['order_id'] ?? 0);
    if ($orderId > 0) {
        $orderCheck = db()->prepare('SELECT party_id FROM jewellery_orders WHERE id = :id AND company_id = :cid LIMIT 1');
        $orderCheck->execute(['id' => $orderId, 'cid' => $companyId]);
        $orderParty = $orderCheck->fetch(PDO::FETCH_ASSOC);
        if (!$orderParty) {
            throw new RuntimeException('That order does not belong to this company.');
        }
        if ((int) $orderParty['party_id'] > 0 && (int) $orderParty['party_id'] !== $partyId) {
            throw new RuntimeException('That order belongs to a different customer.');
        }
    }
    $isAdvance = !empty($header['is_advance']) && $orderId > 0 ? 1 : 0;

    $params = [
        'cid' => $companyId, 'fy' => $fiscalYearId ?: null,
        'date' => (string) ($header['settlement_date'] ?? date('Y-m-d')),
        'party' => $partyId, 'dir' => $direction, 'mode' => $mode, 'amount' => $amount, 'ledger' => $ledgerId,
        'item' => $itemId, 'purity' => $purityId, 'unit' => $unitId, 'gross' => $gross, 'fine' => $fine,
        'order' => $orderId ?: null, 'advance' => $isAdvance,
        'notes' => trim((string) ($header['notes'] ?? '')) ?: null,
    ];

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        if ($settlementId > 0) {
            db()->prepare('UPDATE jewellery_settlements SET fiscal_year_id = :fy, settlement_date = :date, party_id = :party,
                    direction = :dir, mode = :mode, amount = :amount, ledger_id = :ledger, item_id = :item,
                    purity_id = :purity, unit_id = :unit, gross_weight = :gross, fine_weight = :fine,
                    order_id = :order, is_advance = :advance, notes = :notes
                WHERE id = :id AND company_id = :cid')
                ->execute($params + ['id' => $settlementId]);
            db()->prepare('DELETE FROM jewellery_settlement_allocations WHERE settlement_id = :sid AND company_id = :cid')
                ->execute(['sid' => $settlementId, 'cid' => $companyId]);
        } else {
            $no = trim((string) ($header['settlement_no'] ?? '')) ?: jw_next_no($companyId, 'jewellery_settlements', 'settlement_no', 'JST');
            db()->prepare('INSERT INTO jewellery_settlements (company_id, fiscal_year_id, settlement_no, settlement_date,
                    party_id, order_id, is_advance, direction, mode, amount, ledger_id, item_id, purity_id, unit_id,
                    gross_weight, fine_weight, notes, created_by)
                VALUES (:cid, :fy, :no, :date, :party, :order, :advance, :dir, :mode, :amount, :ledger, :item, :purity, :unit,
                    :gross, :fine, :notes, :by)')
                ->execute($params + ['no' => $no, 'by' => $userId ?: null]);
            $settlementId = (int) db()->lastInsertId();
        }

        $allocStmt = db()->prepare('INSERT INTO jewellery_settlement_allocations (company_id, settlement_id, bill_id, amount)
            VALUES (:cid, :sid, :bid, :amount)');
        foreach ($cleanAllocations as $row) {
            $allocStmt->execute(['cid' => $companyId, 'sid' => $settlementId, 'bid' => $row['bill_id'], 'amount' => $row['amount']]);
        }

        if ($ownsTransaction) {
            db()->commit();
        }
    } catch (Throwable $saveException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $saveException;
    }

    return $settlementId;
}

/**
 * Post a settlement.
 *
 *   paid to a supplier:     Dr party            Cr cash/bank (or stock, if metal)
 *   received from a buyer:  Dr cash/bank/stock  Cr party
 */
function jewellery_post_settlement(int $companyId, int $settlementId, int $userId = 0): array
{
    $settlement = jewellery_settlement($companyId, $settlementId);
    if (!$settlement) {
        return ['ok' => false, 'error' => 'Settlement not found for this company.'];
    }
    if ((string) $settlement['status'] !== 'draft') {
        return ['ok' => false, 'error' => 'This settlement is already posted.'];
    }

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        $amount = (float) $settlement['amount'];
        $direction = (string) $settlement['direction'];
        $mode = (string) $settlement['mode'];
        // An ADVANCE is money (or gold) held before delivery, so it belongs in
        // the customer's own advance liability — not netted into a receivable
        // that does not exist until the piece is handed over. Refunding one
        // debits the same account, which is why both directions use it.
        $isAdvance = (int) ($settlement['is_advance'] ?? 0) === 1;
        $partySide = $isAdvance ? 'advance' : ($direction === 'paid' ? 'payable' : 'receivable');
        $partyLedgerId = jw_party_ledger($companyId, (int) $settlement['party_id'], $partySide);

        // Paying a supplier clears a payable (debit); receiving from a customer
        // clears a receivable (credit). An advance received raises the
        // liability; an advance refunded clears it.
        $legs = [['ledger_id' => $partyLedgerId, 'amount' => $direction === 'paid' ? $amount : -$amount, 'memo' => 'Settlement ' . $settlement['settlement_no']]];

        $counterLedgerId = 0;
        if ($mode === 'metal') {
            $item = jewellery_item($companyId, (int) $settlement['item_id']);
            $counterLedgerId = jw_item_stock_ledger_id($companyId, $item);
            if ($counterLedgerId <= 0) {
                throw new RuntimeException('No stock ledger is mapped for item ' . $item['code'] . '.');
            }
        } elseif ($mode === 'adjustment') {
            $counterLedgerId = jw_require_ledger($companyId, 'rounding');
        } else {
            $counterLedgerId = (int) $settlement['ledger_id'];
        }
        // Metal paid AWAY leaves stock; metal received comes IN. Cash mirrors it.
        $legs[] = ['ledger_id' => $counterLedgerId, 'amount' => $direction === 'paid' ? -$amount : $amount, 'memo' => 'Settlement ' . $settlement['settlement_no']];

        $voucherId = create_voucher_with_entries([
            'company_id' => $companyId,
            'fiscal_year_id' => (int) $settlement['fiscal_year_id'],
            'voucher_no' => (string) $settlement['settlement_no'],
            'voucher_type' => $direction === 'paid' ? 'payment' : 'receipt',
            'voucher_date' => (string) $settlement['settlement_date'],
            'source_type' => 'jewellery_settlement',
            'source_id' => $settlementId,
            'party_id' => (int) $settlement['party_id'],
            'narration' => (string) ($settlement['notes'] ?? ('Jewellery settlement ' . $settlement['settlement_no'])),
            'total_amount' => $amount,
            'status' => 'posted',
            'posted_by' => $userId ?: null,
        ], jw_build_entries($legs));

        $stockTxnId = null;
        if ($mode === 'metal') {
            $stockTxnId = jw_record_stock_txn($companyId, [
                'item_id' => (int) $settlement['item_id'],
                'txn_type' => 'adjustment',
                'direction' => $direction === 'paid' ? 'out' : 'in',
                'txn_date' => (string) $settlement['settlement_date'],
                'ref_no' => (string) $settlement['settlement_no'],
                'holder_type' => 'stock',
                'purity_id' => (int) $settlement['purity_id'],
                'unit_id' => (int) $settlement['unit_id'],
                'gross_weight' => (float) $settlement['gross_weight'],
                'fine_weight' => (float) $settlement['fine_weight'],
                'amount' => $amount,
                'source_type' => 'jewellery_settlement',
                'source_id' => $settlementId,
                'voucher_id' => $voucherId,
                'party_id' => (int) $settlement['party_id'],
                'notes' => 'Settled in metal',
                'created_by' => $userId,
            ]);
        }

        db()->prepare("UPDATE jewellery_settlements SET status = 'posted', voucher_id = :v, stock_txn_id = :t,
                posted_by = :by, posted_at = NOW() WHERE id = :id AND company_id = :cid")
            ->execute(['v' => $voucherId, 't' => $stockTxnId, 'by' => $userId ?: null, 'id' => $settlementId, 'cid' => $companyId]);

        foreach (jewellery_settlement_allocations($companyId, $settlementId) as $allocation) {
            jw_refresh_bill($companyId, (int) $allocation['bill_id']);
        }

        if ($ownsTransaction) {
            db()->commit();
        }

        return ['ok' => true, 'error' => '', 'voucher_id' => $voucherId];
    } catch (Throwable $postingException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $postingException->getMessage()];
    }
}

/** Reverse a posted settlement and re-open the bills it had settled. */
function jewellery_unpost_settlement(int $companyId, int $settlementId, int $userId = 0): array
{
    $allocations = jewellery_settlement_allocations($companyId, $settlementId);
    $result = jw_unpost_document($companyId, 'jewellery_settlements', 'jewellery_settlement', $settlementId, $userId);
    if ($result['ok']) {
        foreach ($allocations as $allocation) {
            jw_refresh_bill($companyId, (int) $allocation['bill_id']);
        }
    }

    return $result;
}

function jewellery_delete_settlement(int $companyId, int $settlementId): bool
{
    $stmt = db()->prepare("DELETE FROM jewellery_settlements WHERE id = :id AND company_id = :cid AND status = 'draft'");
    $stmt->execute(['id' => $settlementId, 'cid' => $companyId]);

    return $stmt->rowCount() > 0;
}
