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
 *     received_amount + exchange_amount + advance_amount
 *         + balance_amount - excess_amount  ==  total_amount
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
// Posting refreshes the AML candidates for the date it lands on, so the rules
// that read those postings have to be loaded alongside them.
require_once __DIR__ . '/jewellery_aml.php';

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
    // A draft can be edited repeatedly.  Tax rows for a deleted/replaced line
    // must never be posted again merely because they still share the document
    // id.  Restrict the tax register to lines that still belong to this exact
    // sale or purchase.
    $lineTable = $docType === 'purchase' ? 'jewellery_purchase_lines' : 'jewellery_sale_lines';
    $documentColumn = $docType === 'purchase' ? 'purchase_id' : 'sale_id';
    $stmt = db()->prepare('SELECT tax_code, tax_name, output_purpose, input_purpose, MIN(sequence) AS sequence,
            MAX(t.rate) AS rate, SUM(t.base_amount) AS base_amount, SUM(t.amount) AS amount
        FROM jewellery_line_taxes t
        INNER JOIN `' . $lineTable . '` line ON line.id = t.line_id AND line.`' . $documentColumn . '` = t.doc_id
        WHERE t.company_id = :cid AND t.doc_type = :dt AND t.doc_id = :did
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
        // One advance can be paid several ways at once — part cash, part
        // Fonepay, part old gold. Where a breakdown exists, the gold in it is
        // gold and the rest is money; only a settlement without one is judged
        // by its single mode.
        $tenderRows = jewellery_settlement_tenders($companyId, (int) $row['id']);
        if ($tenderRows !== []) {
            foreach ($tenderRows as $tenderRow) {
                if ((string) $tenderRow['mode'] === 'metal') {
                    $metal += $sign * (float) $tenderRow['amount'];
                } else {
                    $cash += $sign * (float) $tenderRow['amount'];
                }
            }
        } elseif ((string) $row['mode'] === 'metal') {
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
    // With the allocation table on record (migration 094), what an order's
    // entries have already funded is the sum of their rows — the same answer
    // the old advance_amount join gave, but readable entry by entry and
    // immune to an advance being counted against two orders.
    if (table_exists('jewellery_advance_allocations')) {
        $sql = "SELECT COALESCE(SUM(a.amount), 0)
            FROM jewellery_advance_allocations a
            INNER JOIN jewellery_settlements st ON st.id = a.settlement_id
            INNER JOIN jewellery_sales s ON s.id = a.sale_id
            WHERE a.company_id = :cid AND st.order_id = :oid AND s.status <> 'cancelled'";
        $params = ['cid' => $companyId, 'oid' => $orderId];
        if ($excludeSaleId > 0) {
            $sql .= ' AND a.sale_id <> :sid';
            $params['sid'] = $excludeSaleId;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return jw_round_money($held - (float) $stmt->fetchColumn());
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
 * Every advance the customer still holds, ENTRY BY ENTRY — this order's, a
 * previous order's, all of it — with what each entry has left to give.
 *
 * This is the list the billing screen puts in front of the user, because
 * WHICH advance pays a bill is a decision a person makes, not one the engine
 * may guess at. Per entry:
 *
 *     remaining = amount − its allocation rows − its share of order refunds
 *
 * Refunds are recorded against the ORDER, not against an entry, so each
 * order's refund total is spread across its entries' unallocated money
 * oldest-first — the same deterministic rule the 094 backfill used — which
 * keeps the sum of entry remainders exactly equal to the order-level figure
 * the module has always shown.
 *
 * @return array rows: settlement fields + order_no, mode label parts, and
 *               `remaining`; only rows with remaining > 0 are returned.
 */
function jewellery_open_advances(int $companyId, int $partyId, int $excludeSaleId = 0): array
{
    if ($partyId <= 0 || !column_exists('jewellery_settlements', 'order_id')) {
        return [];
    }
    $allocTable = table_exists('jewellery_advance_allocations');
    $allocJoin = '';
    $params = ['cid' => $companyId, 'pid' => $partyId];
    if ($allocTable) {
        $exclude = '';
        if ($excludeSaleId > 0) {
            $exclude = ' AND a.sale_id <> :xsid';
            $params['xsid'] = $excludeSaleId;
        }
        $allocJoin = "LEFT JOIN (SELECT a.settlement_id, SUM(a.amount) AS allocated
                FROM jewellery_advance_allocations a
                INNER JOIN jewellery_sales sl ON sl.id = a.sale_id
                WHERE a.company_id = :cid2 AND sl.status <> 'cancelled'$exclude
                GROUP BY a.settlement_id) alloc ON alloc.settlement_id = st.id";
        $params['cid2'] = $companyId;
    }
    $stmt = db()->prepare("SELECT st.*, o.order_no,
            COALESCE(alloc.allocated, 0) AS allocated,
            i.sku AS item_code, p.code AS purity_code, u.code AS unit_code
        FROM jewellery_settlements st
        LEFT JOIN jewellery_orders o ON o.id = st.order_id
        LEFT JOIN inventory_items i ON i.id = st.item_id
        LEFT JOIN jewellery_purities p ON p.id = st.purity_id
        LEFT JOIN jewellery_units u ON u.id = st.unit_id
        $allocJoin
        WHERE st.company_id = :cid AND st.party_id = :pid
          AND st.is_advance = 1 AND st.direction = 'received' AND st.status = 'posted'
        ORDER BY st.settlement_date ASC, st.id ASC");
    // A missing alloc table leaves `allocated` unselected — patch it to 0.
    if (!$allocTable) {
        $stmt = db()->prepare("SELECT st.*, o.order_no, 0 AS allocated,
                i.sku AS item_code, p.code AS purity_code, u.code AS unit_code
            FROM jewellery_settlements st
            LEFT JOIN jewellery_orders o ON o.id = st.order_id
            LEFT JOIN inventory_items i ON i.id = st.item_id
            LEFT JOIN jewellery_purities p ON p.id = st.purity_id
            LEFT JOIN jewellery_units u ON u.id = st.unit_id
            WHERE st.company_id = :cid AND st.party_id = :pid
              AND st.is_advance = 1 AND st.direction = 'received' AND st.status = 'posted'
            ORDER BY st.settlement_date ASC, st.id ASC");
        $params = ['cid' => $companyId, 'pid' => $partyId];
    }
    $stmt->execute($params);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($entries === []) {
        return [];
    }

    // Each order's refunds, to be spread across that order's entries.
    $refundStmt = db()->prepare("SELECT COALESCE(order_id, 0) AS order_id, SUM(amount) AS refunded
        FROM jewellery_settlements
        WHERE company_id = :cid AND party_id = :pid
          AND is_advance = 1 AND direction = 'paid' AND status = 'posted'
        GROUP BY COALESCE(order_id, 0)");
    $refundStmt->execute(['cid' => $companyId, 'pid' => $partyId]);
    $refunds = [];
    foreach ($refundStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $refunds[(int) $row['order_id']] = (float) $row['refunded'];
    }

    $open = [];
    foreach ($entries as $entry) {
        $unallocated = jw_round_money((float) $entry['amount'] - (float) $entry['allocated']);
        // The refund walks the order's entries in the same oldest-first order
        // this query returns them, eating unallocated money as it goes.
        $orderKey = (int) ($entry['order_id'] ?? 0);
        if ($unallocated > 0 && ($refunds[$orderKey] ?? 0.0) > 0.005) {
            $bite = min($unallocated, $refunds[$orderKey]);
            $unallocated = jw_round_money($unallocated - $bite);
            $refunds[$orderKey] = jw_round_money($refunds[$orderKey] - $bite);
        }
        if ($unallocated > 0.005) {
            $entry['remaining'] = $unallocated;
            $open[] = $entry;
        }
    }

    return $open;
}

/** The advance entries a sale drew on, with enough context to print them. */
function jewellery_sale_advance_allocations(int $companyId, int $saleId): array
{
    if (!table_exists('jewellery_advance_allocations')) {
        return [];
    }
    $stmt = db()->prepare('SELECT a.*, st.settlement_no, st.settlement_date, st.mode, st.order_id, o.order_no
        FROM jewellery_advance_allocations a
        INNER JOIN jewellery_settlements st ON st.id = a.settlement_id
        LEFT JOIN jewellery_orders o ON o.id = st.order_id
        WHERE a.sale_id = :sid AND a.company_id = :cid
        ORDER BY st.settlement_date ASC, st.id ASC');
    $stmt->execute(['sid' => $saleId, 'cid' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            'order_line_id' => (int) ($post[$prefix . '_order_line_id'][$index] ?? 0),
            'purity_id' => (int) ($post[$prefix . '_purity_id'][$index] ?? 0),
            'unit_id' => (int) ($post[$prefix . '_unit_id'][$index] ?? 0),
            'qty_pieces' => (float) ($post[$prefix . '_qty_pieces'][$index] ?? 0),
            'gross_weight' => (float) ($post[$prefix . '_gross_weight'][$index] ?? 0),
            'stone_weight' => (float) ($post[$prefix . '_stone_weight'][$index] ?? 0),
            'rate' => (float) ($post[$prefix . '_rate'][$index] ?? 0),
            // Wastage can be punched either way round. A weight wins over a
            // percentage because it is what the bill prints, and the engine
            // back-fills whichever of the two was left blank.
            'wastage_pct' => (float) ($post[$prefix . '_wastage_pct'][$index] ?? 0),
            'wastage_weight' => (float) ($post[$prefix . '_wastage_weight'][$index] ?? 0),
            'making_amount' => (float) ($post[$prefix . '_making_amount'][$index] ?? 0),
            // The three stone columns the bill carries. They are separate
            // because only this side of the line is vatable.
            'stone_amount' => (float) ($post[$prefix . '_stone_amount'][$index] ?? 0),
            'stone_carat' => (float) ($post[$prefix . '_stone_carat'][$index] ?? 0),
            'diamond_amount' => (float) ($post[$prefix . '_diamond_amount'][$index] ?? 0),
            'diamond_carat' => (float) ($post[$prefix . '_diamond_carat'][$index] ?? 0),
            'other_diamond_amount' => (float) ($post[$prefix . '_other_diamond_amount'][$index] ?? 0),
            'other_diamond_carat' => (float) ($post[$prefix . '_other_diamond_carat'][$index] ?? 0),
            'notes' => (string) ($post[$prefix . '_notes'][$index] ?? ''),
            // Order-only, and absent from the sale and purchase grids: which
            // kaligad is to make THIS piece and when it is promised. A shop's
            // kaligads specialise, so one order routinely goes to several.
            'karigar_id' => (int) ($post[$prefix . '_karigar_id'][$index] ?? 0),
            'delivery_date' => (string) ($post[$prefix . '_delivery_date'][$index] ?? ''),
            // Order-only: whether this item is to be MADE or is already on the
            // shelf. A piece taken off the Ready to Sale tray names the receipt
            // that put it there — the one physical object, not just its item
            // code — and never goes to a kaligad.
            'source' => (string) ($post[$prefix . '_source'][$index] ?? 'workshop'),
            'stock_receipt_id' => (int) ($post[$prefix . '_stock_receipt_id'][$index] ?? 0),
            'stock_unit_id' => (int) ($post[$prefix . '_stock_unit_id'][$index] ?? 0),
            // The measurement THIS piece is made to — ring size, chain length,
            // bangle diameter. Free text, because sizes are written a dozen ways.
            'size' => (string) ($post[$prefix . '_size'][$index] ?? ''),
            // Which stored line this row IS, when the form is revising one.
            // Position is not identity — two rows can hold the same item — and
            // an order line that already has metal out with a kaligad has to be
            // recognised however the rows are reordered.
            'line_id' => (int) ($post[$prefix . '_line_id'][$index] ?? 0),
        ];
    }

    return $lines;
}

/**
 * Next document number for a jewellery table (JS-00001, JP-00007, ...).
 *
 * A `$series` turns the flat sequence into a per-series one:
 *
 *     jw_next_no(1, 'jewellery_orders', 'order_no', 'ORD')          ORD-00001
 *     jw_next_no(1, 'jewellery_orders', 'order_no', 'ORD', '2083')  ORD-2083-000001
 *
 * The two live side by side without colliding, because each counts only the
 * numbers shaped like itself: a series scans `PREFIX-2083-%`, and the flat form
 * scans `^PREFIX-[0-9]+$` — which is why the flat form uses a REGEXP rather
 * than the LIKE it used to. Without that guard the first series number would be
 * read as the flat sequence's latest (it sorts longest-first), and the flat
 * sequence would jump to the series' count and start colliding with numbers
 * already issued.
 *
 * Series numbers are padded to six digits, not five. A series restarts every
 * year, so its numbers are smaller and would look inconsistent beside the flat
 * ones they replace; six digits also matches the reference format a jewellery
 * house is used to seeing.
 */
function jw_next_no(int $companyId, string $table, string $column, string $prefix, ?string $series = null): string
{
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $prefix) ?: 'JW');
    $series = $series === null ? null : (preg_replace('/[^0-9]/', '', $series) ?: null);
    // An assignment carries two numbers: the work it is (assignment_no) and the
    // metal that may later go out against it (issue_no). Everything else has
    // one. The list stays a whitelist — the column is interpolated below.
    $allowed = ['jewellery_purchases' => ['purchase_no'], 'jewellery_sales' => ['sale_no'],
        'jewellery_settlements' => ['settlement_no'], 'jewellery_orders' => ['order_no'],
        'jewellery_order_assignments' => ['issue_no', 'assignment_no'],
        'jewellery_order_receipts' => ['receipt_no'], 'jewellery_refinery_jobs' => ['job_no']];
    if (!in_array($column, $allowed[$table] ?? [], true)) {
        throw new RuntimeException('Refusing to number an unknown document table.');
    }

    if ($series !== null) {
        $stmt = db()->prepare("SELECT `$column` FROM `$table` WHERE company_id = :cid AND `$column` LIKE :like
            ORDER BY LENGTH(`$column`) DESC, `$column` DESC LIMIT 1");
        $stmt->execute(['cid' => $companyId, 'like' => $prefix . '-' . $series . '-%']);
    } else {
        // $prefix is already reduced to A-Z0-9, so it is safe in the pattern.
        $stmt = db()->prepare("SELECT `$column` FROM `$table` WHERE company_id = :cid AND `$column` REGEXP :re
            ORDER BY LENGTH(`$column`) DESC, `$column` DESC LIMIT 1");
        $stmt->execute(['cid' => $companyId, 're' => '^' . $prefix . '-[0-9]+$']);
    }
    $last = (string) ($stmt->fetchColumn() ?: '');
    $next = 1;
    if ($last !== '' && preg_match('/-(\d+)$/', $last, $m)) {
        $next = (int) $m[1] + 1;
    }

    return $series !== null
        ? $prefix . '-' . $series . '-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT)
        : $prefix . '-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
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
    $sumWastage = 0.0; $sumOtherTax = 0.0; $sumDiamond = 0.0;
    // The bases the bill prints, accumulated from what each tax was actually
    // charged on rather than re-derived afterwards.
    $sumSdTaxable = 0.0; $sumVatable = 0.0; $sumNonSpt = 0.0;

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
        $unit = jewellery_unit($companyId, $unitId);
        if (!$unit) {
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
        // Stones are weighed in CARATS at the counter, but the bill weighs the
        // piece in grams or tola. A carat is 0.2 g everywhere on earth, so the
        // typed carat figures — stones, diamonds and other diamonds alike, all
        // of them rock set into the piece — convert themselves into the line's
        // own unit when no stone weight was typed: 25 ct on a gram line knocks
        // 5.000 g off the metal, and the gold rate is never charged on rock.
        // Ornaments only: a LOOSE stone line (item_type 'stone') is weighed in
        // carats as its gross, has no metal to knock anything off, and its
        // carats already drive its own stock.
        $caratsTyped = jw_round_weight((float) ($line['stone_carat'] ?? 0)
            + (float) ($line['diamond_carat'] ?? 0) + (float) ($line['other_diamond_carat'] ?? 0));
        if ($stoneWeight <= 0 && $caratsTyped > 0 && (string) ($item['item_type'] ?? '') === 'ornament') {
            $unitGrams = (float) ($unit['grams'] ?? 1) ?: 1.0;
            $stoneWeight = jw_round_weight($caratsTyped * 0.2 / $unitGrams);
        }
        if ($stoneWeight < 0) {
            $errors[] = 'Line ' . ($index + 1) . ': stone weight cannot be negative.';
            continue;
        }
        if ($stoneWeight > $gross) {
            $errors[] = 'Line ' . ($index + 1) . ': the stone weight cannot exceed the gross weight.';
            continue;
        }
        $net = jw_round_weight($gross - $stoneWeight);

        // WASTAGE IS A WEIGHT, added to the net BEFORE pricing:
        //     Total Wt = Net Wt + Wastage        2.510 + 0.466 = 2.976
        // The customer is charged on the total, but only the NET metal leaves
        // the shop — wastage compensates for melting loss and labour, it is not
        // gold handed over. So fine weight, which drives the stock ledger and
        // COGS, comes from the NET; only the money comes from the total.
        // A percentage is accepted as a convenience and converted to a weight,
        // because a weight is what the bill prints.
        $wastageWeight = jw_round_weight((float) ($line['wastage_weight'] ?? 0));
        $wastagePct = round((float) ($line['wastage_pct'] ?? 0), 3);
        if ($wastageWeight < 0 || $wastagePct < 0) {
            $errors[] = 'Line ' . ($index + 1) . ': wastage cannot be negative.';
            continue;
        }
        if ($wastageWeight <= 0 && $wastagePct > 0) {
            $wastageWeight = jw_round_weight($net * $wastagePct / 100.0);
        } elseif ($wastageWeight > 0 && $wastagePct <= 0 && $net > 0) {
            $wastagePct = round($wastageWeight / $net * 100.0, 3);
        }
        $totalWeight = jw_round_weight($net + $wastageWeight);

        $fine = jw_fine_weight($net, (float) $purity['fineness']);

        $rate = jw_round_rate((float) ($line['rate'] ?? 0));
        $metalAmount = jw_round_money($totalWeight * $rate);
        // No rate typed: let the daily board price it, then write the
        // equivalent rate back so the document reads the same either way.
        $rateGap = '';
        if ($rate <= 0 && $totalWeight > 0) {
            $valued = jewellery_metal_value($companyId, (int) $item['metal_id'], $purityId, $totalWeight, $unitId, $date, $rateType, $settings);
            if ($valued['ok']) {
                $metalAmount = $valued['amount'];
                $rate = jw_round_rate($metalAmount / $totalWeight);
            } else {
                $rateGap = (string) ($valued['error'] ?: 'no rate is available for this item.');
            }
        }
        // What the wastage is worth, for reporting only. It is ALREADY inside
        // $metalAmount — the total weight was priced as one figure, the way the
        // bill does it — so it must never be added a second time.
        $wastageAmount = jw_round_money($wastageWeight * $rate);

        $making = jw_round_money((float) ($line['making_amount'] ?? 0));
        // Diamond, other diamond and stone are three billed columns: taxed
        // alike, priced and reported apart.
        $stone = jw_round_money((float) ($line['stone_amount'] ?? 0));
        $diamond = jw_round_money((float) ($line['diamond_amount'] ?? 0));
        $otherDiamond = jw_round_money((float) ($line['other_diamond_amount'] ?? 0));
        $stoneCarat = jw_round_weight((float) ($line['stone_carat'] ?? 0));
        $diamondCarat = jw_round_weight((float) ($line['diamond_carat'] ?? 0));
        $otherDiamondCarat = jw_round_weight((float) ($line['other_diamond_carat'] ?? 0));
        if ($making < 0 || $stone < 0 || $diamond < 0 || $otherDiamond < 0) {
            $errors[] = 'Line ' . ($index + 1) . ': making, stone and diamond amounts cannot be negative.';
            continue;
        }
        // Everything on the stone side shares one taxable base.
        $stoneSide = jw_round_money($stone + $diamond + $otherDiamond);

        // A line that has weight but came out worth NOTHING is a wrong invoice,
        // not an empty one — somebody forgot the rate. It is only wrong when
        // the line carries no value at all, though: a diamond's worth sits in
        // its stone amount and there is no metal rate to quote for carats.
        if ($rateGap !== '' && $metalAmount <= 0 && $making <= 0 && $stoneSide <= 0) {
            $errors[] = 'Line ' . ($index + 1) . ': ' . $rateGap
                . ' Enter a rate on the line, or quote one on the Daily Rates board.';
            continue;
        }

        // Taxes. The metal figure already carries the wastage, so a base of
        // 'metal_making' IS the bill's "SD Taxable Amt", and 'stone_diamond'
        // IS its "Vatable Amt".
        $charge = jw_charge_line_taxes(
            ['metal' => $metalAmount, 'wastage' => $wastageAmount, 'making' => $making,
             'stone' => $stone, 'diamond' => $diamond, 'other_diamond' => $otherDiamond],
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
        // The two printed bases, taken from what each tax was actually charged
        // on rather than re-derived — so the bill can never disagree with the
        // tax it carries.
        $lineSdTaxable = 0.0;
        $lineVatable = 0.0;
        foreach ($charge['taxes'] as $chargedTax) {
            if ($chargedTax['is_vat']) {
                $vatBase = match ($chargedTax['base']) {
                    'making' => 'making_only',
                    'stone', 'stone_diamond' => 'stone_only',
                    default => 'full_value',
                };
                $lineVatRate = $chargedTax['rate'];
                $taxable = $chargedTax['base_amount'];
                // More than one VAT rule can be configured, but the same
                // line base belongs in the printed VAT base only once.
                $lineVatable = max($lineVatable, (float) $chargedTax['base_amount']);
            } else {
                // SPT taxable means the value of the item portion subject to
                // SPT — not that value multiplied by the number of configured
                // SPT rules.  Count the line base once.
                $lineSdTaxable = max($lineSdTaxable, (float) $chargedTax['base_amount']);
            }
        }

        // The wastage is INSIDE the metal amount, so the subtotal is simply
        // metal + making + the stone side — the bill's "Total Amount".
        $subtotal = jw_round_money($metalAmount + $making + $stoneSide);
        // “Non Taxable” on this invoice means non-SPT-taxable.  A stone may
        // be VAT-taxable yet still belong here because it has no SPT charge.
        $lineNonSpt = jw_round_money(max(0.0, $subtotal - $lineSdTaxable));
        $computed[] = [
            'item_id' => $itemId,
            'item' => $item,
            'purity_id' => $purityId,
            'unit_id' => $unitId,
            'qty_pieces' => $pieces,
            'gross_weight' => $gross,
            'stone_weight' => $stoneWeight,
            'net_weight' => $net,
            'wastage_weight' => $wastageWeight,
            'total_weight' => $totalWeight,
            'fine_weight' => $fine,
            'rate' => $rate,
            'metal_amount' => $metalAmount,
            'wastage_pct' => $wastagePct,
            'wastage_amount' => $wastageAmount,
            'making_amount' => $making,
            'stone_amount' => $stone,
            'stone_carat' => $stoneCarat,
            'diamond_amount' => $diamond,
            'diamond_carat' => $diamondCarat,
            'other_diamond_amount' => $otherDiamond,
            'other_diamond_carat' => $otherDiamondCarat,
            'stone_side_amount' => $stoneSide,
            'sd_taxable_amount' => jw_round_money($lineSdTaxable),
            'non_spt_amount' => $lineNonSpt,
            'vatable_amount' => jw_round_money($lineVatable),
            'vat_base' => $vatBase,
            'vat_rate' => $lineVatRate,
            'vat_amount' => $charge['vat'],
            'tax_amount' => $charge['other'],
            'taxes' => $charge['taxes'],
            'subtotal' => $subtotal,
            'allocated_adjust' => 0.0,
            'line_total' => jw_round_money($subtotal + $charge['total']),
            'notes' => (string) ($line['notes'] ?? ''),
            // Carried straight through for the ORDER, which assigns each item
            // to its own kaligad, promises each its own date and makes each to
            // its own size. Meaningless on a sale or a purchase, which simply
            // ignore them.
            'karigar_id' => (int) ($line['karigar_id'] ?? 0),
            'delivery_date' => (string) ($line['delivery_date'] ?? ''),
            'size' => trim((string) ($line['size'] ?? '')),
            'line_id' => (int) ($line['line_id'] ?? 0),
            // Order only: 'stock' means the customer picked a finished piece
            // off the Ready to Sale tray, and stock_receipt_id says which one.
            'source' => (string) ($line['source'] ?? 'workshop') === 'stock' ? 'stock' : 'workshop',
            'stock_receipt_id' => (int) ($line['stock_receipt_id'] ?? 0),
        ];

        $sumMetal += $metalAmount;
        $sumWastage += $wastageAmount;
        $sumMaking += $making;
        $sumStone += $stone;
        $sumDiamond += jw_round_money($diamond + $otherDiamond);
        $sumSdTaxable += $lineSdTaxable;
        $sumVatable += $lineVatable;
        $sumNonSpt += $lineNonSpt;
        $sumTaxable += $taxable;
        $sumVat += $charge['vat'];
        $sumOtherTax += $charge['other'];
    }

    $otherCharges = jw_round_money((float) ($header['other_charges'] ?? 0));
    $discount = jw_round_money((float) ($header['discount'] ?? 0));
    if ($otherCharges < 0 || $discount < 0) {
        $errors[] = 'Other charges and discount cannot be negative.';
    }
    // Wastage is already inside the metal figure; adding it again would bill
    // it twice.
    $subtotalAll = jw_round_money($sumMetal + $sumMaking + $sumStone + $sumDiamond);
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
            'diamond_amount' => jw_round_money($sumDiamond),
            'other_charges' => $otherCharges,
            'discount' => $discount,
            'taxable_amount' => jw_round_money($sumTaxable),
            // The printed classifications are tax bases, not balancing plugs.
            // Non Taxable deliberately means non-SPT-taxable; VAT is shown in
            // its own base and may overlap that category.
            'sd_taxable_amount' => jw_round_money($sumSdTaxable),
            'vatable_amount' => jw_round_money($sumVatable),
            'non_taxable_amount' => jw_round_money($sumNonSpt),
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
    $stmt = db()->prepare('SELECT l.*, i.sku AS item_code, i.name AS item_name, i.hs_code,
            jp.jewellery_type AS item_type, i.category, mt.name AS metal_name,
            p.code AS purity_code, p.fineness, u.code AS unit_code
        FROM jewellery_purchase_lines l
        INNER JOIN inventory_items i ON i.id = l.item_id
            INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        INNER JOIN jewellery_metals mt ON mt.id = jp.metal_id
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
    if (trim((string) ($filters['search'] ?? '')) !== '') {
        $sql .= ' AND (p.purchase_no LIKE :q1 OR p.ref_no LIKE :q2 OR ap.name LIKE :q3)';
        $needle = '%' . trim((string) $filters['search']) . '%';
        foreach (['q1', 'q2', 'q3'] as $key) {
            $params[$key] = $needle;
        }
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
        'nontax' => $totals['non_taxable_amount'],
        'sdtaxable' => $totals['sd_taxable_amount'],
        'vatable' => $totals['vatable_amount'],
        'diamond' => $totals['diamond_amount'],
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
                    taxable_amount = :taxable, non_taxable_amount = :nontax, sd_taxable_amount = :sdtaxable,
                    vatable_amount = :vatable, diamond_amount = :diamond,
                    vat_amount = :vat, tax_amount = :tax, manual_tax_amount = :mtax,
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
                    taxable_amount, non_taxable_amount, sd_taxable_amount, vatable_amount, diamond_amount,
                    vat_amount, tax_amount, manual_tax_amount, total_amount, paid_amount, balance_amount,
                    settle_mode, settle_ledger_id, created_by)
                VALUES (:cid, :fy, :no, :date, :party, :source, :ref, :narration, :metal, :wastage, :making, :stone, :other, :discount,
                    :taxable, :nontax, :sdtaxable, :vatable, :diamond,
                    :vat, :tax, :mtax, :total, :paid, :balance, :smode, :sledger, :by)')
                ->execute($params + ['no' => $no, 'by' => $userId ?: null]);
            $purchaseId = (int) db()->lastInsertId();
        }

        $lineStmt = db()->prepare('INSERT INTO jewellery_purchase_lines (purchase_id, company_id, item_id, purity_id, unit_id,
                qty_pieces, gross_weight, stone_weight, net_weight, fine_weight, rate, metal_amount,
                wastage_pct, wastage_amount, wastage_weight, total_weight, making_amount, stone_amount,
                stone_carat, diamond_amount, diamond_carat, other_diamond_amount, other_diamond_carat,
                vat_base, vat_rate, vat_amount, tax_amount, allocated_adjust, line_total, stock_amount, notes)
            VALUES (:pid, :cid, :item, :purity, :unit, :pieces, :gross, :sweight, :net, :fine, :rate, :metal,
                :wpct, :wamount, :wweight, :tweight, :making, :stone,
                :scarat, :diamond, :dcarat, :odiamond, :odcarat, :vbase, :vrate, :vamount, :tamount, :adjust, :ltotal, :stock, :notes)');
        foreach ($computed['lines'] as $row) {
            $lineStmt->execute([
                'pid' => $purchaseId, 'cid' => $companyId, 'item' => $row['item_id'], 'purity' => $row['purity_id'],
                'unit' => $row['unit_id'], 'pieces' => $row['qty_pieces'], 'gross' => $row['gross_weight'],
                'sweight' => $row['stone_weight'], 'net' => $row['net_weight'],
                'fine' => $row['fine_weight'], 'rate' => $row['rate'], 'metal' => $row['metal_amount'],
                'wpct' => $row['wastage_pct'], 'wamount' => $row['wastage_amount'],
                'wweight' => $row['wastage_weight'], 'tweight' => $row['total_weight'],
                'making' => $row['making_amount'], 'stone' => $row['stone_amount'],
                'scarat' => $row['stone_carat'], 'diamond' => $row['diamond_amount'],
                'dcarat' => $row['diamond_carat'], 'odiamond' => $row['other_diamond_amount'],
                'odcarat' => $row['other_diamond_carat'], 'vbase' => $row['vat_base'],
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

        // Compliance sees the day a document lands on, as it lands. The
        // register used to rebuild every candidate from scratch on each GET,
        // which is why that was taken off the page — but nothing was put in
        // its place, so a reportable transaction raised no candidate until
        // somebody remembered to press Scan. This refreshes just the date
        // affected, and only after the commit: a compliance rebuild must never
        // be able to roll the books back.
        jw_aml_scan_posted_date($companyId, (string) $purchase['purchase_date'], $userId);

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
    // hs_code and the metal name are here because the printed bill has an
    // "HS Code" and a "Type" column; without them the invoice would have to
    // look each item up again, row by row.
    $stmt = db()->prepare('SELECT l.*, i.sku AS item_code, i.name AS item_name, i.hs_code,
            jp.jewellery_type AS item_type, i.category, mt.name AS metal_name,
            p.code AS purity_code, p.fineness, u.code AS unit_code
        FROM jewellery_sale_lines l
        INNER JOIN inventory_items i ON i.id = l.item_id
            INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        INNER JOIN jewellery_metals mt ON mt.id = jp.metal_id
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
    $sql = 'SELECT s.*, ap.name AS party_name,
            COALESCE((SELECT GROUP_CONCAT(DISTINCT linked_order.order_no ORDER BY linked_order.order_no SEPARATOR ", ")
                FROM jewellery_sale_lines linked_line
                INNER JOIN jewellery_order_lines linked_order_line
                    ON linked_order_line.id = linked_line.order_line_id
                   AND linked_order_line.company_id = linked_line.company_id
                INNER JOIN jewellery_orders linked_order
                    ON linked_order.id = linked_order_line.order_id
                   AND linked_order.company_id = linked_line.company_id
                WHERE linked_line.company_id = s.company_id AND linked_line.sale_id = s.id), o.order_no) AS order_no
        FROM jewellery_sales s
        LEFT JOIN accounting_parties ap ON ap.id = s.party_id
        LEFT JOIN jewellery_orders o ON o.id = s.order_id
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
    if (($filters['order_no'] ?? '') !== '') {
        $sql .= ' AND (o.order_no = :order_no OR EXISTS (SELECT 1 FROM jewellery_sale_lines filter_line
            INNER JOIN jewellery_order_lines filter_order_line
                ON filter_order_line.id = filter_line.order_line_id AND filter_order_line.company_id = filter_line.company_id
            INNER JOIN jewellery_orders filter_order
                ON filter_order.id = filter_order_line.order_id AND filter_order.company_id = filter_line.company_id
            WHERE filter_line.company_id = s.company_id AND filter_line.sale_id = s.id
              AND filter_order.order_no = :order_no_line))';
        $params['order_no'] = (string) $filters['order_no'];
        $params['order_no_line'] = (string) $filters['order_no'];
    }
    if (($filters['sale_no'] ?? '') !== '') {
        $sql .= ' AND s.sale_no = :sale_no';
        $params['sale_no'] = (string) $filters['sale_no'];
    }
    if (trim((string) ($filters['search'] ?? '')) !== '') {
        $sql .= ' AND (s.sale_no LIKE :q1 OR s.ref_no LIKE :q2
            OR s.customer_name LIKE :q3 OR ap.name LIKE :q4)';
        $needle = '%' . trim((string) $filters['search']) . '%';
        foreach (['q1', 'q2', 'q3', 'q4'] as $key) {
            $params[$key] = $needle;
        }
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

    // Preserve the permanent physical identity beside the calculated money
    // fields. The pricing engine is deliberately concerned with weights and
    // amounts; the trace ID identifies which actual ring or bangle those
    // figures belong to.
    $lineStockUnits = [];
    $claimedStockUnits = [];
    $claimedOrderLines = [];
    foreach ($lines as $lineIndex => $line) {
        $stockUnitId = (int) ($line['stock_unit_id'] ?? 0);
        if ($stockUnitId > 0 && isset($claimedStockUnits[$stockUnitId])) {
            throw new RuntimeException('Sale item ' . ($lineIndex + 1) . ': the same physical trace item is already '
                . 'used on item ' . $claimedStockUnits[$stockUnitId] . '.');
        }
        if ($stockUnitId > 0) {
            $claimedStockUnits[$stockUnitId] = $lineIndex + 1;
        }
        $lineStockUnits[$lineIndex] = $stockUnitId ?: null;

        $orderLineId = (int) ($line['order_line_id'] ?? 0);
        if ($orderLineId > 0 && isset($claimedOrderLines[$orderLineId])) {
            throw new RuntimeException('Sale item ' . ($lineIndex + 1) . ': that ordered item is already used on item '
                . $claimedOrderLines[$orderLineId] . '.');
        }
        if ($orderLineId > 0) {
            $claimedOrderLines[$orderLineId] = $lineIndex + 1;
        }
    }

    // A customer-order line represents one unique promised piece. Enforce
    // this in the engine as well as in the dropdown, so a stale tab cannot
    // sell the same remaining item twice. The draft currently being edited is
    // deliberately excluded from the check.
    if ($claimedOrderLines !== []) {
        $ids = implode(',', array_map('intval', array_keys($claimedOrderLines)));
        $used = db()->prepare("SELECT sl.order_line_id, s.sale_no
            FROM jewellery_sale_lines sl
            INNER JOIN jewellery_sales s ON s.id = sl.sale_id AND s.company_id = sl.company_id
            WHERE sl.company_id = :cid AND sl.order_line_id IN ($ids)
              AND s.status <> 'cancelled' AND sl.sale_id <> :sid");
        $used->execute(['cid' => $companyId, 'sid' => $saleId]);
        foreach ($used->fetchAll(PDO::FETCH_ASSOC) as $usedLine) {
            $lineNo = $claimedOrderLines[(int) $usedLine['order_line_id']] ?? 0;
            throw new RuntimeException('Sale item ' . $lineNo . ': this ordered item is already on sale '
                . (string) $usedLine['sale_no'] . '.');
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
    // A draft is still a promise to sell a real item. Waiting until Post to
    // discover that the shelf is empty lets the same piece be entered on many
    // drafts. Apply the same no-negative-stock rule here, grouping repeated
    // lines for an item before comparing them with the available balance.
    if ((int) ($settings['allow_negative_stock'] ?? 0) !== 1) {
        $required = [];
        foreach ($computed['lines'] as $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            $unit = jewellery_unit($companyId, (int) ($row['unit_id'] ?? 0));
            if (!$unit) {
                throw new RuntimeException('Sale item has an unknown weight unit.');
            }
            if (!isset($required[$itemId])) {
                $required[$itemId] = ['pieces' => 0.0, 'fine_g' => 0.0];
            }
            $required[$itemId]['pieces'] += (float) ($row['qty_pieces'] ?? 0);
            $required[$itemId]['fine_g'] += jw_to_grams((float) ($row['fine_weight'] ?? 0), $unit);
        }
        foreach ($required as $itemId => $demand) {
            $item = jewellery_item($companyId, (int) $itemId);
            $held = jw_item_balance($companyId, (int) $itemId, $date, 'stock');
            $fineRequired = jw_round_weight((float) $demand['fine_g'] / jw_item_unit_grams($companyId, (int) $itemId));
            if ($fineRequired > 0.00005 && jw_round_weight((float) $held['fine_weight'] - $fineRequired) < -0.00005) {
                throw new RuntimeException(sprintf(
                    'Not enough stock for %s: available fine weight is %s but this sale needs %s.',
                    (string) ($item['code'] ?? ('item #' . $itemId)),
                    number_format((float) $held['fine_weight'], 4),
                    number_format($fineRequired, 4)
                ));
            }
            if ((float) $demand['pieces'] > 0.0005
                && ((float) $held['qty_pieces'] - (float) $demand['pieces']) < -0.0005) {
                throw new RuntimeException(sprintf(
                    'Not enough stock for %s: available pieces are %s but this sale needs %s.',
                    (string) ($item['code'] ?? ('item #' . $itemId)),
                    number_format((float) $held['qty_pieces'], 3),
                    number_format((float) $demand['pieces'], 3)
                ));
            }
        }
    }

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
    // The fourth way a sale can be paid for: an advance the customer already
    // put down. WHICH entries fund it is a decision a person makes — the
    // billing screen lists every open advance the customer holds (this
    // order's, a previous order's, an opening advance) and the user ticks
    // entries and types amounts, which arrive here as advance_allocations.
    // Each entry is capped at what it still holds, so the same rupee can
    // never fund two bills.
    // ONE BILL CAN SETTLE SEVERAL ORDERS. A customer collecting a locket and a
    // ring on the same visit expects one bill, and jewellery_orders.
    // delivered_sale_id has always been many-to-one — it was only the caller
    // that could name a single order. deliver_order_id stays understood so
    // every existing caller keeps working.
    $deliverOrderIds = [];
    foreach ((array) ($header['deliver_order_ids'] ?? []) as $rawOrderId) {
        $rawOrderId = (int) $rawOrderId;
        if ($rawOrderId > 0 && !in_array($rawOrderId, $deliverOrderIds, true)) {
            $deliverOrderIds[] = $rawOrderId;
        }
    }
    if ($deliverOrderIds === [] && (int) ($header['deliver_order_id'] ?? 0) > 0) {
        $deliverOrderIds[] = (int) $header['deliver_order_id'];
    }
    // The first is what the single-order paths below still reason about: the
    // legacy advance fallback, and the sale's own convenience order_id column.
    $deliverOrderId = $deliverOrderIds[0] ?? 0;
    $advanceApplied = jw_round_money((float) ($header['advance_amount'] ?? 0));
    if ($advanceApplied < 0) {
        throw new RuntimeException('An advance applied cannot be negative.');
    }
    $advanceAllocations = [];
    foreach ((array) ($header['advance_allocations'] ?? []) as $allocRow) {
        $allocSettlementId = (int) ($allocRow['settlement_id'] ?? 0);
        $allocAmount = jw_round_money((float) ($allocRow['amount'] ?? 0));
        if ($allocSettlementId <= 0 || $allocAmount == 0.0) {
            continue;
        }
        if ($allocAmount < 0) {
            throw new RuntimeException('An advance allocation cannot be negative.');
        }
        // Two rows naming the same entry are one decision written twice.
        $advanceAllocations[$allocSettlementId] = jw_round_money(($advanceAllocations[$allocSettlementId] ?? 0.0) + $allocAmount);
    }

    if ($advanceAllocations !== []) {
        if (!table_exists('jewellery_advance_allocations')) {
            throw new RuntimeException('This database has not been upgraded to record which advances pay a bill. '
                . 'Run the accounting repair, then save again.');
        }
        $openEntries = [];
        foreach (jewellery_open_advances($companyId, (int) $partyId, $saleId) as $openEntry) {
            $openEntries[(int) $openEntry['id']] = $openEntry;
        }
        $allocTotal = 0.0;
        foreach ($advanceAllocations as $allocSettlementId => $allocAmount) {
            $openEntry = $openEntries[$allocSettlementId] ?? null;
            if ($openEntry === null) {
                throw new RuntimeException('An allocation names an advance this customer does not hold — '
                    . 'it may belong to someone else, be unposted, or be used up.');
            }
            if ($allocAmount > (float) $openEntry['remaining'] + 0.005) {
                throw new RuntimeException('Advance ' . $openEntry['settlement_no'] . ' only has '
                    . number_format((float) $openEntry['remaining'], 2) . ' left — cannot take '
                    . number_format($allocAmount, 2) . ' from it.');
            }
            $allocTotal = jw_round_money($allocTotal + $allocAmount);
        }
        if ($advanceApplied <= 0) {
            // Ticking the entries IS the number; no second field to disagree.
            $advanceApplied = $allocTotal;
        } elseif (abs($allocTotal - $advanceApplied) > 0.005) {
            throw new RuntimeException('The advance entries chosen (' . number_format($allocTotal, 2)
                . ') do not add up to the advance applied (' . number_format($advanceApplied, 2) . ').');
        }
    } elseif ($advanceApplied > 0) {
        // The old single-number call, kept for callers that predate the
        // allocation table: capped against the delivering order's own pool,
        // then spread oldest-first across that pool's entries so the invariant
        // — advance_amount is the sum of its rows — holds for every sale. The
        // billing screen never takes this path; it always names the entries.
        if ($deliverOrderId <= 0) {
            throw new RuntimeException('An advance can only be applied to the order it was taken against.');
        }
        $available = jewellery_order_advance_available($companyId, $deliverOrderId, $saleId);
        if ($advanceApplied > $available + 0.005) {
            throw new RuntimeException('Only ' . number_format($available, 2) . ' of advance is still held against this order — '
                . 'cannot apply ' . number_format($advanceApplied, 2) . '.');
        }
        if (table_exists('jewellery_advance_allocations')) {
            $left = $advanceApplied;
            foreach (jewellery_open_advances($companyId, (int) $partyId, $saleId) as $openEntry) {
                if ((int) ($openEntry['order_id'] ?? 0) !== $deliverOrderId || $left <= 0.005) {
                    continue;
                }
                $take = min((float) $openEntry['remaining'], $left);
                $advanceAllocations[(int) $openEntry['id']] = jw_round_money($take);
                $left = jw_round_money($left - $take);
            }
            if ($left > 0.005) {
                throw new RuntimeException('The advance applied could not be traced to this customer\'s own '
                    . 'advance entries on this order. Check the order and the customer match.');
            }
        }
    }

    // The settlement identity, now with five legs.
    //
    //   received + exchange + advance + balance - excess == total
    //
    // EXCESS is the leg this used to refuse. A customer who hands over a chain
    // worth more than the ring they leave with is not a mistake — it is the
    // ordinary shape of a metal-to-metal exchange, and the shop owes them the
    // difference. Refusing it did not protect the books; it pushed the counter
    // into under-stating the gold or inventing a line nobody sold, which is a
    // wrong figure entered to get past a guard meant to prevent wrong figures.
    //
    // What the excess IS, though, only the person at the counter knows, so it
    // is asked rather than assumed: held as that customer's advance against
    // their next bill, or handed back over the counter now.
    $handedOver = jw_round_money($received + $exchangeTotal);
    $excess = jw_round_money(max(0.0, $handedOver - $total));

    // An ADVANCE is not something handed over today — it is a liability the
    // shop has been carrying since the order was taken. Applying more of it
    // than the bill comes to is a typing slip, never an event, so that half of
    // the old guard stays exactly as it was.
    if ($excess > 0.005 && $advanceApplied > 0.005) {
        throw new RuntimeException('Cash and old gold (' . number_format($handedOver, 2)
            . ') already cover this bill of ' . number_format($total, 2)
            . ', so no advance can be applied to it. Leave the advance where it is — it stays available for the next bill.');
    }
    if ($advanceApplied > 0.005 && jw_round_money($handedOver + $advanceApplied) > $total + 0.005) {
        throw new RuntimeException('Cash received, old gold and advance applied ('
            . number_format(jw_round_money($handedOver + $advanceApplied), 2)
            . ') exceed the sale total (' . number_format($total, 2)
            . '). Apply only what this sale comes to and refund the rest of the advance separately.');
    }

    $excessMode = jw_enum($header['excess_mode'] ?? null, ['none', 'advance', 'refund'], 'none');
    $excessLedgerId = (int) ($header['excess_ledger_id'] ?? 0) ?: null;
    if ($excess > 0.005) {
        if ($excessMode === 'none') {
            throw new RuntimeException('The old gold and cash on this bill come to '
                . number_format($handedOver, 2) . ', which is ' . number_format($excess, 2)
                . ' more than the bill of ' . number_format($total, 2)
                . '. Say what happens to the excess: hold it as this customer\'s advance, or refund it.');
        }
        if ($excessMode === 'advance' && $partyId <= 0) {
            throw new RuntimeException('An excess held as an advance belongs to somebody — choose the customer, '
                . 'or refund the ' . number_format($excess, 2) . ' instead.');
        }
        if ($excessMode === 'refund') {
            if ($excessLedgerId === null) {
                throw new RuntimeException('Choose the cash or bank ledger the ' . number_format($excess, 2)
                    . ' refund is paid out of.');
            }
            $refundCheck = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :id AND company_id = :cid');
            $refundCheck->execute(['id' => $excessLedgerId, 'cid' => $companyId]);
            if ((int) $refundCheck->fetchColumn() === 0) {
                throw new RuntimeException('That refund ledger does not belong to this company.');
            }
        } else {
            $excessLedgerId = null; // an advance goes to the customer's own ledger, not a chosen one
        }
    } else {
        $excess = 0.0;
        $excessMode = 'none';
        $excessLedgerId = null;
    }

    // Never negative: what is over the bill is the excess leg, not a balance
    // owing the wrong way round.
    $balance = jw_round_money(max(0.0, $total - $received - $exchangeTotal - $advanceApplied));

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

    // How the received amount was tendered.
    //
    // This used to be a breakdown for the printed bill only — the money still
    // moved through the single settle ledger, so a bill paid half in cash and
    // half by card posted as if it were all cash. It now POSTS the way it
    // prints: jewellery_post_sale() sends each part to the ledger mapped for
    // that tender mode, falling back to the settle ledger for any mode the shop
    // has not mapped yet.
    //
    // Leaving every field blank is still the normal case and still behaves as
    // it always did. Fill any of them and they must add up exactly, otherwise
    // the bill would print a tender row that disagrees with its own total.
    $tender = [];
    $tenderTotal = 0.0;
    foreach (['cash', 'card', 'cheque', 'qr'] as $mode) {
        $amount = jw_round_money((float) ($header['paid_' . $mode] ?? 0));
        if ($amount < 0) {
            throw new RuntimeException('A tender amount cannot be negative.');
        }
        $tender['paid_' . $mode] = $amount;
        $tenderTotal += $amount;
    }
    $tenderTotal = jw_round_money($tenderTotal);
    if ($tenderTotal > 0 && abs($tenderTotal - $received) > 0.005) {
        throw new RuntimeException('The tender split (' . number_format($tenderTotal, 2)
            . ') must add up to the amount received (' . number_format($received, 2) . ').');
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
        // The three bases the bill prints under its totals.
        'nontax' => $totals['non_taxable_amount'], 'sdtaxable' => $totals['sd_taxable_amount'],
        'vatable' => $totals['vatable_amount'], 'diamond' => $totals['diamond_amount'],
        'sperson' => trim((string) ($header['sales_person'] ?? '')) ?: null,
        'cref' => trim((string) ($header['customer_ref'] ?? '')) ?: null,
        'datebs' => trim((string) ($header['tran_date_bs'] ?? '')) ?: null,
        'remarks' => trim((string) ($header['remarks'] ?? '')) ?: null,
        'total' => $total,
        'received' => $received, 'exchange' => $exchangeTotal, 'advance' => $advanceApplied, 'balance' => $balance,
        'excess' => $excess, 'excessmode' => $excessMode, 'excessledger' => $excessLedgerId,
        'pcash' => $tender['paid_cash'], 'pcard' => $tender['paid_card'],
        'pcheque' => $tender['paid_cheque'], 'pqr' => $tender['paid_qr'],
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
                    discount = :discount, taxable_amount = :taxable, non_taxable_amount = :nontax,
                    sd_taxable_amount = :sdtaxable, vatable_amount = :vatable, diamond_amount = :diamond,
                    sales_person = :sperson, customer_ref = :cref, tran_date_bs = :datebs, remarks = :remarks,
                    vat_amount = :vat, tax_amount = :tax,
                    manual_tax_amount = :mtax, total_amount = :total, received_amount = :received,
                    paid_cash = :pcash, paid_card = :pcard, paid_cheque = :pcheque, paid_qr = :pqr,
                    exchange_amount = :exchange, advance_amount = :advance, balance_amount = :balance,
                    excess_amount = :excess, excess_mode = :excessmode, excess_ledger_id = :excessledger,
                    settle_mode = :smode, settle_ledger_id = :sledger
                WHERE id = :id AND company_id = :cid')
                ->execute($params + ['id' => $saleId]);
            db()->prepare('DELETE FROM jewellery_sale_lines WHERE sale_id = :sid AND company_id = :cid')->execute(['sid' => $saleId, 'cid' => $companyId]);
            db()->prepare('DELETE FROM jewellery_sale_exchanges WHERE sale_id = :sid AND company_id = :cid')->execute(['sid' => $saleId, 'cid' => $companyId]);
        } else {
            $no = trim((string) ($header['sale_no'] ?? '')) ?: jw_next_no($companyId, 'jewellery_sales', 'sale_no', (string) ($settings['sale_no_prefix'] ?? 'JS'));
            db()->prepare('INSERT INTO jewellery_sales (company_id, fiscal_year_id, sale_no, sale_date, tran_date_bs, party_id, customer_name,
                    ref_no, narration, remarks, metal_amount, wastage_amount, making_amount, stone_amount, other_charges, discount,
                    taxable_amount, non_taxable_amount, sd_taxable_amount, vatable_amount, diamond_amount, sales_person, customer_ref,
                    vat_amount, tax_amount, manual_tax_amount, total_amount, received_amount,
                    paid_cash, paid_card, paid_cheque, paid_qr, exchange_amount,
                    advance_amount, balance_amount, excess_amount, excess_mode, excess_ledger_id,
                    settle_mode, settle_ledger_id, created_by)
                VALUES (:cid, :fy, :no, :date, :datebs, :party, :cname, :ref, :narration, :remarks, :metal, :wastage, :making, :stone, :other, :discount,
                    :taxable, :nontax, :sdtaxable, :vatable, :diamond, :sperson, :cref,
                    :vat, :tax, :mtax, :total, :received, :pcash, :pcard, :pcheque, :pqr,
                    :exchange, :advance, :balance, :excess, :excessmode, :excessledger, :smode, :sledger, :by)')
                ->execute($params + ['no' => $no, 'by' => $userId ?: null]);
            $saleId = (int) db()->lastInsertId();
        }

        $lineStmt = db()->prepare('INSERT INTO jewellery_sale_lines (sale_id, company_id, order_line_id, item_id, purity_id, unit_id, stock_unit_id,
                qty_pieces, gross_weight, stone_weight, net_weight, fine_weight, rate, metal_amount,
                wastage_pct, wastage_amount, wastage_weight, total_weight, making_amount, stone_amount,
                stone_carat, diamond_amount, diamond_carat, other_diamond_amount, other_diamond_carat,
                vat_base, vat_rate, vat_amount, tax_amount, allocated_adjust, line_total, notes)
            VALUES (:sid, :cid, :orderline, :item, :purity, :unit, :stockunit, :pieces, :gross, :sweight, :net, :fine, :rate, :metal,
                :wpct, :wamount, :wweight, :tweight, :making, :stone,
                :scarat, :diamond, :dcarat, :odiamond, :odcarat, :vbase, :vrate, :vamount, :tamount, :adjust, :ltotal, :notes)');
        foreach ($computed['lines'] as $lineIndex => $row) {
            $lineStmt->execute([
                'sid' => $saleId, 'cid' => $companyId,
                'orderline' => (int) ($lines[$lineIndex]['order_line_id'] ?? 0) ?: null,
                'item' => $row['item_id'], 'purity' => $row['purity_id'],
                'unit' => $row['unit_id'], 'stockunit' => $lineStockUnits[$lineIndex] ?? null,
                'pieces' => $row['qty_pieces'], 'gross' => $row['gross_weight'],
                'sweight' => $row['stone_weight'], 'net' => $row['net_weight'],
                'fine' => $row['fine_weight'], 'rate' => $row['rate'], 'metal' => $row['metal_amount'],
                'wpct' => $row['wastage_pct'], 'wamount' => $row['wastage_amount'],
                'wweight' => $row['wastage_weight'], 'tweight' => $row['total_weight'],
                'making' => $row['making_amount'], 'stone' => $row['stone_amount'],
                'scarat' => $row['stone_carat'], 'diamond' => $row['diamond_amount'],
                'dcarat' => $row['diamond_carat'], 'odiamond' => $row['other_diamond_amount'],
                'odcarat' => $row['other_diamond_carat'], 'vbase' => $row['vat_base'],
                'vrate' => $row['vat_rate'], 'vamount' => $row['vat_amount'], 'tamount' => $row['tax_amount'],
                'adjust' => $row['allocated_adjust'],
                'ltotal' => $row['line_total'], 'notes' => $row['notes'] !== '' ? $row['notes'] : null,
            ]);
            $saleLineId = (int) db()->lastInsertId();
            jw_save_line_taxes($companyId, 'sale', $saleId, $saleLineId, $row);
            $stockUnitId = (int) ($lineStockUnits[$lineIndex] ?? 0);
            if ($stockUnitId > 0) {
                $reserved = jewellery_trace_reserve_for_sale(
                    $companyId,
                    $stockUnitId,
                    $saleId,
                    $saleLineId,
                    $deliverOrderIds,
                    $userId
                );
                if (!($reserved['ok'] ?? false)) {
                    throw new RuntimeException('Sale item ' . ($lineIndex + 1) . ': '
                        . (string) ($reserved['error'] ?? 'The trace item could not be reserved.'));
                }
            }
        }
        jewellery_trace_release_sale_reservations(
            $companyId,
            $saleId,
            array_values(array_filter(array_map('intval', $lineStockUnits))),
            $userId
        );

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

        // WHICH advance entries fund this bill — rewritten wholesale on every
        // revision of the draft, like the lines above; posting freezes them
        // because a posted sale cannot be saved. The sale's advance_amount is
        // the sum of these rows, and jewellery_open_advances() subtracts them
        // entry by entry, which is what stops one rupee funding two bills.
        if (table_exists('jewellery_advance_allocations')) {
            db()->prepare('DELETE FROM jewellery_advance_allocations WHERE sale_id = :sid AND company_id = :cid')
                ->execute(['sid' => $saleId, 'cid' => $companyId]);
            if ($advanceAllocations !== []) {
                // The friendly per-entry check ran OUTSIDE this transaction, so
                // two counters ticking the same entry at the same moment would
                // both have passed it. Lock the entries themselves — the second
                // writer waits here until the first commits — then re-check
                // against what the entries now hold. This check is terse where
                // the earlier one explains; by the time it fires, the polite
                // answer has already been given to whoever was second.
                $lockedIds = implode(',', array_map('intval', array_keys($advanceAllocations)));
                $lockStmt = db()->query("SELECT id, amount, order_id FROM jewellery_settlements
                    WHERE id IN ($lockedIds) AND company_id = " . (int) $companyId . " FOR UPDATE");
                $lockedEntries = [];
                foreach ($lockStmt->fetchAll(PDO::FETCH_ASSOC) as $lockedRow) {
                    $lockedEntries[(int) $lockedRow['id']] = $lockedRow;
                }
                $othersStmt = db()->prepare("SELECT a.settlement_id, COALESCE(SUM(a.amount), 0) AS total
                    FROM jewellery_advance_allocations a
                    INNER JOIN jewellery_sales s2 ON s2.id = a.sale_id
                    WHERE a.company_id = :cid AND a.settlement_id IN ($lockedIds)
                      AND s2.status <> 'cancelled' AND a.sale_id <> :sid
                    GROUP BY a.settlement_id");
                $othersStmt->execute(['cid' => $companyId, 'sid' => $saleId]);
                $othersByEntry = [];
                foreach ($othersStmt->fetchAll(PDO::FETCH_ASSOC) as $otherRow) {
                    $othersByEntry[(int) $otherRow['settlement_id']] = (float) $otherRow['total'];
                }
                $drawByOrder = [];
                foreach ($advanceAllocations as $allocSettlementId => $allocAmount) {
                    $lockedEntry = $lockedEntries[$allocSettlementId] ?? null;
                    if ($lockedEntry === null) {
                        throw new RuntimeException('An advance entry on this bill no longer exists.');
                    }
                    if ($allocAmount > (float) $lockedEntry['amount'] - ($othersByEntry[$allocSettlementId] ?? 0.0) + 0.005) {
                        throw new RuntimeException('Another bill drew on the same advance while this one was being saved. '
                            . 'Reopen the advance picker and choose again.');
                    }
                    $orderKey = (int) ($lockedEntry['order_id'] ?? 0);
                    $drawByOrder[$orderKey] = jw_round_money(($drawByOrder[$orderKey] ?? 0.0) + $allocAmount);
                }
                // And per order: entry room alone cannot see refunds, which are
                // recorded against the order. With the entries locked above,
                // nobody else can move this pool until we commit.
                foreach ($drawByOrder as $orderKey => $draw) {
                    if ($orderKey > 0 && $draw > jewellery_order_advance_available($companyId, $orderKey, $saleId) + 0.005) {
                        throw new RuntimeException('A refund or another bill consumed part of this advance while '
                            . 'this bill was being saved. Reopen the advance picker and choose again.');
                    }
                }
            }
            $advAllocStmt = db()->prepare('INSERT INTO jewellery_advance_allocations
                    (company_id, sale_id, settlement_id, amount, created_by)
                VALUES (:cid, :sid, :stid, :amount, :by)');
            foreach ($advanceAllocations as $allocSettlementId => $allocAmount) {
                $advAllocStmt->execute(['cid' => $companyId, 'sid' => $saleId,
                    'stid' => $allocSettlementId, 'amount' => $allocAmount, 'by' => $userId ?: null]);
            }
        }

        // Record WHICH sale is billing this order, here in the engine rather
        // than in the page that happened to call it. The advance cap reads this
        // link to work out what has already been applied, so leaving it to the
        // caller would mean an advance looked unused — and could be applied a
        // second time — whenever a sale was saved by any other route.
        // This is the LINK only; marking the order delivered stays a separate,
        // deliberate act.
        if ($deliverOrderIds !== []) {
            // Cleared first, so an order dropped from the tick list on a re-save
            // stops claiming this bill. Without it, un-ticking an order left it
            // pointing at a sale that no longer carries its goods, and posting
            // would have delivered something nobody billed.
            db()->prepare("UPDATE jewellery_orders SET delivered_sale_id = NULL
                WHERE company_id = :cid AND delivered_sale_id = :sale AND status <> 'delivered' AND status <> 'closed'")
                ->execute(['cid' => $companyId, 'sale' => $saleId]);
            $linkStmt = db()->prepare("UPDATE jewellery_orders SET delivered_sale_id = :sale
                WHERE id = :id AND company_id = :cid AND status <> 'cancelled'");
            foreach ($deliverOrderIds as $linkOrderId) {
                $linkStmt->execute(['sale' => $saleId, 'id' => $linkOrderId, 'cid' => $companyId]);
            }
            // And on the sale's own side, so posting — a different request,
            // long after the POST field that carried the order id has died —
            // can still finish what the save started. One column, so it holds
            // the first; the authoritative link is delivered_sale_id, which
            // every order on this bill carries.
            if (column_exists('jewellery_sales', 'order_id')) {
                db()->prepare('UPDATE jewellery_sales SET order_id = :oid WHERE id = :id AND company_id = :cid')
                    ->execute(['oid' => $deliverOrderId, 'id' => $saleId, 'cid' => $companyId]);
            }
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
 *       Cr  customer advance / cash    excess_amount   <- old gold over the bill
 *   Dr  COGS  /  Cr stock    at the weighted-average cost in force NOW
 *
 * The EXCESS leg is the one that makes a metal-to-metal exchange work in both
 * directions. Old gold worth more than the ring it bought leaves the shop
 * owing the difference: credited to that customer's own advance ledger when it
 * is being kept for their next bill, or to cash when it is handed back over
 * the counter. Which of the two is the counter's answer, recorded on the sale.
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
            // NOTE the absence of wastage. It is already inside metal_amount —
            // the bill prices the total weight (net + wastage) as one figure —
            // so posting it again would credit revenue twice and leave the
            // voucher out of balance by exactly the wastage.
            foreach ([
                ['metal_amount', 'sales_metal', 'Metal value'],
                ['making_amount', 'sales_making', 'Making charge'],
                ['stone_amount', 'sales_stone', 'Stone value'],
                ['diamond_amount', 'sales_stone', 'Diamond value'],
                ['other_diamond_amount', 'sales_stone', 'Other diamond value'],
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
        // The invoice is the source of truth: its CURRENT saved line totals
        // include any manual tax adjustment.  Historical tax-detail rows are
        // retained for reporting/mapping only and must not alter the amount
        // being credited to the tax payable accounts.
        $lineVatTotal = jw_round_money(array_sum(array_map(static fn (array $line): float => (float) ($line['vat_amount'] ?? 0), $lines)));
        // Older drafts can carry the same SPT rule more than once. Their saved
        // line tax total is then a duplicate of the same levy, not a genuine
        // amount owed. SPT is always metal plus making, charged once at its
        // saved rate; a deliberately entered manual amount still wins.
        $sptRate = 0.0;
        foreach ($documentTaxes as $tax) {
            if (strtoupper(trim((string) ($tax['tax_code'] ?? ''))) !== 'VAT') {
                $sptRate = max($sptRate, (float) ($tax['rate'] ?? 0));
            }
        }
        $sptBase = jw_round_money(array_sum(array_map(static fn (array $line): float =>
            (float) ($line['metal_amount'] ?? 0) + (float) ($line['making_amount'] ?? 0), $lines)));
        $lineOtherTaxTotal = $sale['manual_tax_amount'] !== null
            ? jw_round_money((float) $sale['manual_tax_amount'])
            : jw_round_money($sptBase * $sptRate / 100);
        $postedTax = 0.0;
        $otherTaxAssigned = false;
        foreach ($documentTaxes as $tax) {
            $isVat = strtoupper(trim((string) $tax['tax_code'])) === 'VAT';
            $amount = $isVat ? $lineVatTotal : ($otherTaxAssigned ? 0.0 : $lineOtherTaxTotal);
            $otherTaxAssigned = $otherTaxAssigned || !$isVat;
            if (abs($amount) < 0.005) {
                continue;
            }
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, (string) $tax['output_purpose']),
                'amount' => -$amount, 'memo' => $tax['tax_code'] . ' ' . $sale['sale_no']];
            $postedTax = jw_round_money($postedTax + $amount);
        }
        if ($lineVatTotal > 0 && !array_filter($documentTaxes, static fn (array $tax): bool => strtoupper(trim((string) $tax['tax_code'])) === 'VAT')) {
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, 'vat_output'), 'amount' => -$lineVatTotal, 'memo' => 'VAT ' . $sale['sale_no']];
        }
        if ($lineOtherTaxTotal > 0 && !$otherTaxAssigned) {
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, 'spt_output'), 'amount' => -$lineOtherTaxTotal, 'memo' => 'SPT ' . $sale['sale_no']];
        }
        if ((float) $sale['other_charges'] > 0) {
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, 'other_charges'), 'amount' => -(float) $sale['other_charges'], 'memo' => 'Other charges ' . $sale['sale_no']];
        }
        if ((float) $sale['discount'] > 0) {
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, 'sales_discount'), 'amount' => (float) $sale['discount'], 'memo' => 'Discount ' . $sale['sale_no']];
        }

        // Settlement side.
        //
        // A bill settled part cash, part card and part QR used to land entirely
        // in ONE ledger: the breakdown was printed but never posted, so the cash
        // book claimed the shop took the whole amount in cash and the till could
        // never be counted against it at closing time.
        //
        // Each part now goes where the money actually went — but only as far as
        // the shop has said where that is. A tender mode with no ledger mapped
        // falls back to the settlement ledger, so this can be adopted one mode
        // at a time, and a shop that maps none of them is unaffected.
        if ((float) $sale['received_amount'] > 0) {
            $settleLedgerId = (int) $sale['settle_ledger_id'];
            $tenderLegs = [];
            $tenderPosted = 0.0;
            foreach (['cash', 'card', 'cheque', 'qr'] as $tenderMode) {
                $tenderPart = jw_round_money((float) ($sale['paid_' . $tenderMode] ?? 0));
                if ($tenderPart <= 0) {
                    continue;
                }
                $tenderLedger = jewellery_resolve_mapping($companyId, 'tender_' . $tenderMode);
                $tenderLegs[] = [
                    'ledger_id' => $tenderLedger ? (int) $tenderLedger['id'] : $settleLedgerId,
                    'amount' => $tenderPart,
                    'memo' => 'Received by ' . $tenderMode . ' ' . $sale['sale_no'],
                ];
                $tenderPosted = jw_round_money($tenderPosted + $tenderPart);
            }
            // Saving validates that a filled-in split equals the amount
            // received, but a bill written before that rule — or one left
            // unsplit — would post short. Whatever the breakdown does not
            // account for goes to the settlement ledger, so the voucher can
            // never fall out of balance over how the tender boxes were filled.
            $tenderRemainder = jw_round_money((float) $sale['received_amount'] - $tenderPosted);
            foreach ($tenderLegs as $tenderLeg) {
                $legs[] = $tenderLeg;
            }
            if ($tenderLegs === [] || $tenderRemainder > 0.004) {
                $legs[] = ['ledger_id' => $settleLedgerId,
                    'amount' => $tenderLegs === [] ? (float) $sale['received_amount'] : $tenderRemainder,
                    'memo' => 'Received ' . $sale['sale_no']];
            }
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
        // What the old gold was worth over and above the bill. A CREDIT, in
        // the leg convention where positive is a debit: the shop is the one
        // that owes now.
        $saleExcess = jw_round_money((float) ($sale['excess_amount'] ?? 0));
        $saleExcessMode = (string) ($sale['excess_mode'] ?? 'none');
        if ($saleExcess > 0.004 && $saleExcessMode !== 'none') {
            if ($saleExcessMode === 'refund') {
                $refundLedgerId = (int) ($sale['excess_ledger_id'] ?? 0);
                if ($refundLedgerId <= 0) {
                    throw new RuntimeException('This bill refunds ' . number_format($saleExcess, 2)
                        . ' of old gold but no cash or bank ledger was chosen to pay it out of.');
                }
                $legs[] = ['ledger_id' => $refundLedgerId, 'amount' => -$saleExcess,
                    'memo' => 'Refund of old gold over the bill ' . $sale['sale_no']];
            } else {
                $legs[] = ['ledger_id' => jw_party_ledger($companyId, (int) $sale['party_id'], 'advance'),
                    'amount' => -$saleExcess,
                    'memo' => 'Old gold over the bill held as advance ' . $sale['sale_no']];
            }
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

        // An excess held as an advance has to be FINDABLE, or it is money the
        // shop owes that only the sale it came off knows about. It goes in the
        // advance register the same way any other advance does, so it appears
        // in that customer's open advances and the next bill can apply it.
        //
        // It carries THIS sale's voucher rather than raising one of its own.
        // The credit to the advance ledger is a leg of the sale entry above;
        // a second voucher would credit it twice and leave the customer owed
        // double what they handed over. One fact, one posting, two registers
        // pointing at it.
        if ($saleExcess > 0.004 && $saleExcessMode === 'advance' && table_exists('jewellery_settlements')) {
            db()->prepare("INSERT INTO jewellery_settlements (company_id, fiscal_year_id, settlement_no, settlement_date,
                    party_id, order_id, is_advance, direction, mode, amount, ledger_id,
                    status, voucher_id, notes, posted_by, posted_at, created_by)
                VALUES (:cid, :fy, :no, :date, :party, :order, 1, 'received', 'metal', :amount, :ledger,
                    'posted', :voucher, :notes, :by, NOW(), :by2)")
                ->execute([
                    'cid' => $companyId,
                    'fy' => (int) ($sale['fiscal_year_id'] ?? 0) ?: null,
                    'no' => jw_next_no($companyId, 'jewellery_settlements', 'settlement_no', 'JST'),
                    'date' => $saleDate,
                    'party' => (int) $sale['party_id'],
                    'order' => (int) ($sale['order_id'] ?? 0) ?: null,
                    'amount' => $saleExcess,
                    'ledger' => jw_party_ledger($companyId, (int) $sale['party_id'], 'advance'),
                    'voucher' => $voucherId,
                    'notes' => 'Old gold over the bill ' . $sale['sale_no'] . ' held as advance',
                    'by' => $userId ?: null,
                    'by2' => $userId ?: null,
                ]);
        }

        // Metal out at cost — the stock ledger must fall by cost, not by price.
        foreach ($lines as $line) {
            $cost = $lineCogs[(int) $line['id']] ?? 0.0;
            $stockUnitId = (int) ($line['stock_unit_id'] ?? 0);
            $txnId = jw_record_stock_txn($companyId, [
                'item_id' => (int) $line['item_id'],
                'stock_unit_id' => $stockUnitId ?: null,
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
            if ($stockUnitId > 0) {
                $sold = jewellery_trace_mark_sold(
                    $companyId,
                    $stockUnitId,
                    $saleId,
                    (int) $line['id'],
                    (int) ($sale['party_id'] ?? 0),
                    (string) $sale['sale_no'],
                    $saleDate,
                    $userId
                );
                if (!($sold['ok'] ?? false)) {
                    throw new RuntimeException((string) ($sold['error'] ?? 'The physical trace item could not be marked sold.'));
                }
            }
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
            // The old chain the customer handed over the counter is a physical
            // object like any other on the shelf, so it gets its own trace the
            // moment it is taken in. Without this the metal was in the stock
            // ledger by weight but the piece itself existed nowhere, and could
            // not be picked, reserved or sold as an object.
            jewellery_trace_create_sale_exchange($companyId, $sale, $exchange, $userId);
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

        // The order this sale bills, if any, is now INVOICED — the bill is in
        // the books, whatever the workshop was doing. The word marks the
        // boundary: from here on the order's status belongs to the billing
        // machinery, and jewellery_sync_order_status() will not touch it.
        // Delivery — the goods actually changing hands — remains its own act,
        // jewellery_deliver_order(), which the sale screen performs on posting.
        // EVERY order on this bill, not just the one in the sale's convenience
        // column. A bill that settled three orders and invoiced one left the
        // other two looking unbilled, so the counter would have raised a second
        // bill for goods already sold and paid for.
        if (jw_order_status_storable('invoiced')) {
            db()->prepare("UPDATE jewellery_orders SET status = 'invoiced'
                    WHERE company_id = :cid AND delivered_sale_id = :sid
                      AND status IN ('draft', 'confirmed', 'assigned', 'partially_received', 'received')")
                ->execute(['sid' => $saleId, 'cid' => $companyId]);
        }

        if ($ownsTransaction) {
            db()->commit();
        }

        jw_aml_scan_posted_date($companyId, $saleDate, $userId);

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
    $sale = jewellery_sale($companyId, $saleId);
    // An excess held as an advance was recorded in the advance register against
    // this sale's voucher. Unposting removes the voucher, so leaving the
    // register row would offer the next bill an advance the books no longer
    // carry — the customer credited twice for gold handed over once.
    $advanceRowId = 0;
    if ($sale !== null && (int) ($sale['voucher_id'] ?? 0) > 0 && table_exists('jewellery_settlements')) {
        $advStmt = db()->prepare("SELECT id FROM jewellery_settlements
            WHERE company_id = :cid AND voucher_id = :vid AND is_advance = 1 AND direction = 'received' LIMIT 1");
        $advStmt->execute(['cid' => $companyId, 'vid' => (int) $sale['voucher_id']]);
        $advanceRowId = (int) ($advStmt->fetchColumn() ?: 0);
    }
    $result = jw_unpost_document($companyId, 'jewellery_sales', 'jewellery_sale', $saleId, $userId);
    if ($result['ok'] && $sale !== null) {
        if ($advanceRowId > 0) {
            db()->prepare('DELETE FROM jewellery_settlements WHERE id = :id AND company_id = :cid')
                ->execute(['id' => $advanceRowId, 'cid' => $companyId]);
        }
        jewellery_trace_release_sale($companyId, $saleId, $userId);
        // The bill is out of the books, so the order it stood on goes back to
        // the workshop's answer. Delivery is undone with it: 'delivered' was
        // this sale's doing, and a draft is evidence of nothing — but the
        // LINK (order_id, delivered_sale_id) survives, so posting again picks
        // the same order back up.
        // Every order the bill carried, so reversing a bill for three orders
        // does not leave two of them delivered against a document that is no
        // longer in the books.
        $linked = db()->prepare('SELECT id FROM jewellery_orders WHERE delivered_sale_id = :sid AND company_id = :cid');
        $linked->execute(['sid' => $saleId, 'cid' => $companyId]);
        $orderIds = array_map('intval', $linked->fetchAll(PDO::FETCH_COLUMN));
        $saleOrderId = (int) ($sale['order_id'] ?? 0);
        if ($saleOrderId > 0 && !in_array($saleOrderId, $orderIds, true)) {
            $orderIds[] = $saleOrderId;
        }
        foreach ($orderIds as $orderId) {
            db()->prepare("UPDATE jewellery_orders SET status = 'received', delivered_at = NULL
                    WHERE id = :oid AND company_id = :cid AND status IN ('invoiced', 'delivered', 'closed')")
                ->execute(['oid' => $orderId, 'cid' => $companyId]);
            // 'received' was only a guess to land on a workshop-owned status;
            // the sync derives the true one from the items themselves.
            jewellery_sync_order_status($companyId, $orderId);
        }
    }

    return $result;
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

    // A delivered order is CLOSED the moment its bill is paid off — that is
    // what closed means: goods gone AND money in. This runs on every bill
    // refresh so the day-30 cheque closes the order the same way day-one cash
    // would have. Symmetric on reversal: unposting the settlement reopens the
    // bill, and the order steps back to delivered.
    if (jw_order_status_storable('closed')) {
        $srcStmt = db()->prepare("SELECT source_type, source_id FROM jewellery_bills WHERE id = :id AND company_id = :cid LIMIT 1");
        $srcStmt->execute(['id' => $billId, 'cid' => $companyId]);
        $src = $srcStmt->fetch(PDO::FETCH_ASSOC);
        if ($src && (string) $src['source_type'] === 'jewellery_sale') {
            if ($status === 'settled') {
                db()->prepare("UPDATE jewellery_orders SET status = 'closed'
                        WHERE company_id = :cid AND delivered_sale_id = :sid AND status = 'delivered'")
                    ->execute(['cid' => $companyId, 'sid' => (int) $src['source_id']]);
            } else {
                db()->prepare("UPDATE jewellery_orders SET status = 'delivered'
                        WHERE company_id = :cid AND delivered_sale_id = :sid AND status = 'closed'")
                    ->execute(['cid' => $companyId, 'sid' => (int) $src['source_id']]);
            }
        }
    }
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
    // The bills report asks for a page at a time. It was passing limit and
    // offset into this function, which ignored them and returned everything —
    // so its pager moved but the rows never did.
    $limit = (int) ($filters['limit'] ?? 0);
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . max(0, (int) ($filters['offset'] ?? 0));
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * What the open bills come to, without listing them.
 *
 * The dashboard and the bills page wanted three numbers — money owed to the
 * shop, money the shop owes, and how many bills that is — and were getting
 * them by fetching every open bill and adding the column up in PHP. On a shop
 * with a long ledger that is a large result set read to produce three
 * integers. The sums are the database's job.
 *
 * Kept deliberately identical to the loop it replaced: the same
 * 'open' + 'part_settled' statuses, the same outstanding expression, and sale
 * bills counted as receivable with everything else as payable.
 */
function jewellery_open_bill_totals(int $companyId): array
{
    $stmt = db()->prepare("SELECT
            COALESCE(SUM(CASE WHEN b.bill_type = 'sale' THEN b.bill_amount - b.settled_amount ELSE 0 END), 0) AS receivable,
            COALESCE(SUM(CASE WHEN b.bill_type <> 'sale' THEN b.bill_amount - b.settled_amount ELSE 0 END), 0) AS payable,
            COUNT(*) AS bill_count
        FROM jewellery_bills b
        INNER JOIN accounting_parties ap ON ap.id = b.party_id
        WHERE b.company_id = :cid AND b.status IN ('open', 'part_settled')");
    $stmt->execute(['cid' => $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'receivable' => jw_round_money((float) ($row['receivable'] ?? 0)),
        'payable' => jw_round_money((float) ($row['payable'] ?? 0)),
        'bill_count' => (int) ($row['bill_count'] ?? 0),
    ];
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
/** The ways one settlement was paid, in the order they were entered. */
function jewellery_settlement_tenders(int $companyId, int $settlementId): array
{
    if (!table_exists('jewellery_settlement_tenders')) {
        return [];
    }
    $stmt = db()->prepare('SELECT t.*, l.name AS ledger_name, i.sku AS item_code, i.name AS item_name,
            p.code AS purity_code, p.fineness, u.code AS unit_code
        FROM jewellery_settlement_tenders t
        LEFT JOIN ledgers l ON l.id = t.ledger_id
        LEFT JOIN inventory_items i ON i.id = t.item_id
        LEFT JOIN jewellery_purities p ON p.id = t.purity_id
        LEFT JOIN jewellery_units u ON u.id = t.unit_id
        WHERE t.settlement_id = :sid AND t.company_id = :cid
        ORDER BY t.line_no ASC, t.id ASC');
    $stmt->execute(['sid' => $settlementId, 'cid' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Whether this database's settlements.mode column can hold $mode. The wider
 * enum arrives with migration 092 / the repair step; until that has run, only
 * the original four are safe to write, and trying to store 'cheque' would be
 * truncated to '' by MySQL rather than refused.
 */
function jw_settlement_mode_storable(string $mode): bool
{
    static $cache = null;
    if ($cache === null) {
        $col = db()->query("SHOW COLUMNS FROM `jewellery_settlements` LIKE 'mode'")->fetch(PDO::FETCH_ASSOC);
        $cache = (string) ($col['Type'] ?? '');
    }

    return stripos($cache, "'" . $mode . "'") !== false;
}

/** How a tender mode is shown to a person: the shop's own word for it, else ours. */
function jw_tender_mode_label(string $mode, ?string $customLabel = null): string
{
    $custom = trim((string) $customLabel);
    if ($custom !== '') {
        return $custom;
    }

    return ['cash' => 'Cash', 'bank' => 'Bank', 'card' => 'Card', 'cheque' => 'Cheque',
        'qr' => 'QR / Fonepay', 'wallet' => 'Mobile wallet', 'metal' => 'Old gold',
        'adjustment' => 'Adjustment', 'other' => 'Other', 'mixed' => 'Mixed'][$mode] ?? ucfirst($mode);
}

/**
 * Check and normalise the ways one payment was tendered.
 *
 * Returns [] when the caller gave none, which is the ordinary single-mode
 * settlement and leaves every existing behaviour alone. Anything it does
 * return has been proved to belong to this company — ledger, item, purity and
 * unit alike — so the save and the posting can use it without asking again.
 */
function jw_clean_settlement_tenders(int $companyId, array $tenders): array
{
    $clean = [];
    $lineNo = 0;
    foreach ($tenders as $row) {
        $amount = jw_round_money((float) ($row['amount'] ?? 0));
        $mode = jw_enum($row['mode'] ?? null,
            ['cash', 'bank', 'card', 'cheque', 'qr', 'wallet', 'metal', 'adjustment', 'other'], 'cash');
        // A blank row is how a fixed-size grid says "nothing here", so it is
        // skipped rather than rejected. A NEGATIVE one is a typo that would
        // quietly reduce the payment, so it is not.
        if ($amount <= 0 && (float) ($row['gross_weight'] ?? 0) <= 0) {
            if ((float) ($row['amount'] ?? 0) < 0) {
                throw new RuntimeException('A tender amount cannot be negative.');
            }
            continue;
        }
        if ($amount <= 0) {
            throw new RuntimeException('Tender ' . ($lineNo + 1) . ' (' . jw_tender_mode_label($mode)
                . ') has a weight but no value. Price the metal before taking it in.');
        }

        $ledgerId = (int) ($row['ledger_id'] ?? 0) ?: null;
        if ($ledgerId !== null) {
            $lCheck = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :id AND company_id = :cid');
            $lCheck->execute(['id' => $ledgerId, 'cid' => $companyId]);
            if ((int) $lCheck->fetchColumn() === 0) {
                throw new RuntimeException('A tender points at a ledger that does not belong to this company.');
            }
        }
        // Cash and bank have no mapped fallback to fall back ON — a shop keeps
        // several tills and several bank accounts, and only the person taking
        // the money knows which one it went into.
        if (in_array($mode, ['cash', 'bank'], true) && $ledgerId === null) {
            throw new RuntimeException('Choose the ' . $mode . ' ledger for the '
                . jw_tender_mode_label($mode) . ' part of this payment.');
        }
        $modeLabel = trim((string) ($row['mode_label'] ?? '')) ?: null;
        if ($mode === 'other') {
            if ($ledgerId === null) {
                throw new RuntimeException('An "other" tender needs the ledger it lands in.');
            }
            if ($modeLabel === null) {
                throw new RuntimeException('Name the "other" way this payment was made.');
            }
        }

        $itemId = null; $purityId = null; $unitId = null; $gross = 0.0; $fine = 0.0;
        if ($mode === 'metal') {
            // Old gold across the counter. The weight moves as well as the
            // value, so it needs the same full item context a metal settlement
            // has always needed.
            $itemId = (int) ($row['item_id'] ?? 0);
            $item = jewellery_item($companyId, $itemId);
            if (!$item) {
                throw new RuntimeException('The old-gold part of this payment needs an item that belongs to this company.');
            }
            $purityId = (int) ($row['purity_id'] ?? $item['purity_id']);
            $purity = jewellery_purity($companyId, $purityId);
            if (!$purity || (int) $purity['metal_id'] !== (int) $item['metal_id']) {
                throw new RuntimeException('The old-gold purity must belong to the item\'s metal.');
            }
            $unitId = (int) ($row['unit_id'] ?? $item['unit_id']);
            if (!jewellery_unit($companyId, $unitId)) {
                throw new RuntimeException('The old-gold unit must belong to this company.');
            }
            $gross = jw_round_weight((float) ($row['gross_weight'] ?? 0));
            if ($gross <= 0) {
                throw new RuntimeException('Enter the weight of the old gold taken in.');
            }
            $fine = jw_fine_weight($gross, (float) $purity['fineness']);
        }

        $clean[] = [
            'line_no' => ++$lineNo,
            'mode' => $mode,
            'mode_label' => $modeLabel,
            'reference' => trim((string) ($row['reference'] ?? '')) ?: null,
            'amount' => $amount,
            'ledger_id' => $ledgerId,
            'item_id' => $itemId,
            'purity_id' => $purityId,
            'unit_id' => $unitId,
            'gross_weight' => $gross,
            'fine_weight' => $fine,
            'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
        ];
    }

    return $clean;
}

/**
 * Save a settlement — money or metal moving between the shop and a party.
 *
 * ONE PAYMENT CAN BE MADE SEVERAL WAYS AT ONCE. A customer settling 50,000
 * hands over cash, taps Fonepay and puts down an old chain, all at the same
 * counter in the same minute. Pass those as `$header['tenders']`, one row per
 * way, and they are stored as a breakdown of this single settlement rather
 * than as three settlements with three numbers that nothing ties together.
 *
 * The rows must add up to the settlement's own amount — they are a breakdown,
 * not a second source of truth, exactly as the sale's tender columns are.
 * Sending none keeps the old single-mode behaviour, which is what every
 * settlement recorded before this did.
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

    // How the payment was actually made. Present, these ARE the counter side:
    // the header's own mode and metal columns step aside, because a payment
    // that is part cash and part old gold cannot be described by one of each.
    $tenders = jw_clean_settlement_tenders($companyId, (array) ($header['tenders'] ?? []));
    if ($tenders !== []) {
        if (!table_exists('jewellery_settlement_tenders')) {
            throw new RuntimeException('This database has not been upgraded to record a split payment yet. '
                . 'Run the accounting repair, then enter it again.');
        }
        $tenderTotal = jw_round_money(array_sum(array_column($tenders, 'amount')));
        if (abs($tenderTotal - $amount) > 0.005) {
            throw new RuntimeException('The payment breakdown (' . number_format($tenderTotal, 2)
                . ') does not add up to the amount taken (' . number_format($amount, 2) . ').');
        }
        // One way of paying is not a split; it is an ordinary settlement that
        // happens to have been entered on the grid, so the header says so
        // plainly. Several ways read 'mixed', which tells any list view to go
        // and look at the breakdown rather than name a mode it cannot.
        $mode = count($tenders) === 1 ? (string) $tenders[0]['mode'] : 'mixed';
        if (!jw_settlement_mode_storable($mode)) {
            throw new RuntimeException('This database cannot store a ' . jw_tender_mode_label($mode)
                . ' settlement yet. Run the accounting repair, then enter it again.');
        }
    }

    $ledgerId = (int) ($header['ledger_id'] ?? 0) ?: null;
    if ($tenders !== []) {
        // The breakdown carries its own ledgers, item and weights, so the
        // header keeps none of them. Leaving them behind would give posting two
        // counter sides to choose between.
        $ledgerId = null;
    } elseif (in_array($mode, ['cash', 'bank'], true)) {
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
    // With a breakdown the context lives on the metal TENDER ROW instead — a
    // payment can carry old gold beside cash, and the header cannot describe
    // half of it.
    $itemId = null; $purityId = null; $unitId = null; $gross = 0.0; $fine = 0.0;
    if ($mode === 'metal' && $tenders === []) {
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
        // What has ALREADY REACHED THE BOOKS against this bill — posted
        // settlements only, and never this settlement's own prior rows.
        //
        // It has to agree with jw_refresh_bill(), which also counts posted rows
        // and nothing else. Counting drafts here made the two disagree:
        // reversing a settlement reopened the bill on screen, while the draft
        // it left behind went on silently reserving the room, so every attempt
        // to settle that reopened bill was refused with "0.00 outstanding".
        $priorStmt = db()->prepare("SELECT COALESCE(SUM(a.amount), 0)
            FROM jewellery_settlement_allocations a
            INNER JOIN jewellery_settlements s ON s.id = a.settlement_id
            WHERE a.company_id = :cid AND a.bill_id = :bid AND a.settlement_id <> :sid
              AND s.status = 'posted'");
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

    // A REFUND CAN ONLY HAND BACK WHAT IS STILL FREE. What a bill has drawn
    // (posted or draft — a draft is a bill being written) is spoken for; the
    // unpost guard already refuses to pull an entry out from under a bill,
    // and an uncapped refund was the same money leaving by the other door:
    // the customer keeps the advance on their bill AND takes it home in cash.
    // Checked again at posting, which is when the money actually moves.
    if ($isAdvance === 1 && $direction === 'paid') {
        // A posted settlement cannot reach here (revision is refused above),
        // and a draft never reduced the pool — so the available figure needs
        // no adding-back for the row being revised.
        $refundable = jewellery_order_advance_available($companyId, $orderId);
        if ($amount > $refundable + 0.005) {
            throw new RuntimeException('Only ' . number_format(max(0.0, $refundable), 2)
                . ' of this order\'s advance is still free to refund — the rest is already applied to a bill.');
        }
    }

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

        // The ways this one payment was made. Rewritten wholesale on revision,
        // like the allocations above: a draft's breakdown is still being
        // decided, and only posting freezes it.
        if (table_exists('jewellery_settlement_tenders')) {
            db()->prepare('DELETE FROM jewellery_settlement_tenders WHERE settlement_id = :sid AND company_id = :cid')
                ->execute(['sid' => $settlementId, 'cid' => $companyId]);
            $tenderStmt = db()->prepare('INSERT INTO jewellery_settlement_tenders (company_id, settlement_id, line_no,
                    mode, mode_label, reference, amount, ledger_id, item_id, purity_id, unit_id, gross_weight, fine_weight, notes)
                VALUES (:cid, :sid, :line, :mode, :label, :ref, :amount, :ledger, :item, :purity, :unit, :gross, :fine, :notes)');
            foreach ($tenders as $row) {
                $tenderStmt->execute([
                    'cid' => $companyId, 'sid' => $settlementId, 'line' => $row['line_no'],
                    'mode' => $row['mode'], 'label' => $row['mode_label'], 'ref' => $row['reference'],
                    'amount' => $row['amount'], 'ledger' => $row['ledger_id'], 'item' => $row['item_id'],
                    'purity' => $row['purity_id'], 'unit' => $row['unit_id'],
                    'gross' => $row['gross_weight'], 'fine' => $row['fine_weight'], 'notes' => $row['notes'],
                ]);
            }
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
    // The refund cap, checked again HERE because this is when the money
    // actually leaves. Between saving the draft and posting it, a bill may
    // have drawn on the same advance — the save-time check cannot know that.
    if ((int) ($settlement['is_advance'] ?? 0) === 1 && (string) $settlement['direction'] === 'paid'
        && (int) ($settlement['order_id'] ?? 0) > 0) {
        $refundable = jewellery_order_advance_available($companyId, (int) $settlement['order_id']);
        if ((float) $settlement['amount'] > $refundable + 0.005) {
            return ['ok' => false, 'error' => 'Only ' . number_format(max(0.0, $refundable), 2)
                . ' of this order\'s advance is still free to refund — a bill has drawn on it since this refund was written.'];
        }
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

        // One payment, possibly made several ways at once. With a breakdown on
        // record, EACH way is its own counter leg, so the cash book gains only
        // the cash, the bank only the Fonepay, and the stock only the old
        // gold — the till can be counted against the books at closing time.
        // Without one, the single counter leg is built exactly as it always
        // was.
        $tenderRows = jewellery_settlement_tenders($companyId, $settlementId);
        if ($tenderRows !== []) {
            $tenderSum = 0.0;
            foreach ($tenderRows as $tenderRow) {
                $tenderSum += (float) $tenderRow['amount'];
            }
            if (abs(jw_round_money($tenderSum) - $amount) > 0.005) {
                throw new RuntimeException('The payment breakdown no longer adds up to the settlement amount. Revise it before posting.');
            }
            foreach ($tenderRows as $tenderRow) {
                $tenderMode = (string) $tenderRow['mode'];
                $tenderAmount = jw_round_money((float) $tenderRow['amount']);
                $tenderLedgerId = (int) ($tenderRow['ledger_id'] ?? 0);
                if ($tenderMode === 'metal') {
                    $item = jewellery_item($companyId, (int) $tenderRow['item_id']);
                    $tenderLedgerId = jw_item_stock_ledger_id($companyId, $item);
                    if ($tenderLedgerId <= 0) {
                        throw new RuntimeException('No stock ledger is mapped for item ' . $item['code'] . '.');
                    }
                } elseif ($tenderMode === 'adjustment') {
                    $tenderLedgerId = $tenderLedgerId ?: jw_require_ledger($companyId, 'rounding');
                } elseif ($tenderLedgerId <= 0) {
                    // Card, cheque and QR fall back to the shop's mapped tender
                    // ledgers — the same accounts the sale's own tender split
                    // posts to, because the same rupee taken the same way
                    // belongs in the same place either side of a bill.
                    $mapped = jewellery_resolve_mapping($companyId, 'tender_' . $tenderMode);
                    $tenderLedgerId = $mapped ? (int) $mapped['id'] : 0;
                    if ($tenderLedgerId <= 0) {
                        throw new RuntimeException('No ledger is chosen or mapped for the '
                            . jw_tender_mode_label($tenderMode, $tenderRow['mode_label'] ?? null)
                            . ' part of this payment.');
                    }
                }
                $legs[] = ['ledger_id' => $tenderLedgerId,
                    'amount' => $direction === 'paid' ? -$tenderAmount : $tenderAmount,
                    'memo' => jw_tender_mode_label($tenderMode, $tenderRow['mode_label'] ?? null)
                        . ($tenderRow['reference'] ? ' ' . $tenderRow['reference'] : '')
                        . ' — settlement ' . $settlement['settlement_no']];
            }
        } else {
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
        }

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
        if ($tenderRows !== []) {
            // Old gold in the breakdown moves weight as well as money — one
            // stock movement per metal row, remembered on the row so unposting
            // can reverse each one.
            foreach ($tenderRows as $tenderRow) {
                if ((string) $tenderRow['mode'] !== 'metal') {
                    continue;
                }
                $tenderTxnId = jw_record_stock_txn($companyId, [
                    'item_id' => (int) $tenderRow['item_id'],
                    'txn_type' => 'adjustment',
                    'direction' => $direction === 'paid' ? 'out' : 'in',
                    'txn_date' => (string) $settlement['settlement_date'],
                    'ref_no' => (string) $settlement['settlement_no'],
                    'holder_type' => 'stock',
                    'purity_id' => (int) $tenderRow['purity_id'],
                    'unit_id' => (int) $tenderRow['unit_id'],
                    'gross_weight' => (float) $tenderRow['gross_weight'],
                    'fine_weight' => (float) $tenderRow['fine_weight'],
                    'amount' => jw_round_money((float) $tenderRow['amount']),
                    'source_type' => 'jewellery_settlement',
                    'source_id' => $settlementId,
                    'voucher_id' => $voucherId,
                    'party_id' => (int) $settlement['party_id'],
                    'notes' => 'Old gold taken in payment',
                    'created_by' => $userId,
                ]);
                db()->prepare('UPDATE jewellery_settlement_tenders SET stock_txn_id = :t WHERE id = :id AND company_id = :cid')
                    ->execute(['t' => $tenderTxnId, 'id' => (int) $tenderRow['id'], 'cid' => $companyId]);
                // The settlement's own pointer keeps the FIRST metal movement,
                // so everything reading it still finds one, as it always has.
                $stockTxnId = $stockTxnId ?? $tenderTxnId;
            }
        } elseif ($mode === 'metal') {
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

        jw_aml_scan_posted_date($companyId, (string) $settlement['settlement_date'], $userId);

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
    // An advance that has already paid part of a bill cannot be pulled out
    // from under it — the bill's settlement identity would be standing on
    // money that no longer exists. Unwind the sale (or revise its advance
    // entries) first; then the entry is free to reverse.
    if (table_exists('jewellery_advance_allocations')) {
        $appliedStmt = db()->prepare("SELECT COALESCE(SUM(a.amount), 0)
            FROM jewellery_advance_allocations a
            INNER JOIN jewellery_sales s ON s.id = a.sale_id
            WHERE a.company_id = :cid AND a.settlement_id = :stid AND s.status <> 'cancelled'");
        $appliedStmt->execute(['cid' => $companyId, 'stid' => $settlementId]);
        $applied = jw_round_money((float) $appliedStmt->fetchColumn());
        if ($applied > 0.005) {
            return ['ok' => false, 'error' => number_format($applied, 2)
                . ' of this advance has already been applied to a bill. Unwind that sale first.'];
        }
    }
    $allocations = jewellery_settlement_allocations($companyId, $settlementId);
    $result = jw_unpost_document($companyId, 'jewellery_settlements', 'jewellery_settlement', $settlementId, $userId);
    if ($result['ok']) {
        foreach ($allocations as $allocation) {
            jw_refresh_bill($companyId, (int) $allocation['bill_id']);
        }
        // The stock movements the metal tenders made are gone with the
        // voucher; the rows must not go on pointing at them.
        if (table_exists('jewellery_settlement_tenders')) {
            db()->prepare('UPDATE jewellery_settlement_tenders SET stock_txn_id = NULL
                    WHERE settlement_id = :sid AND company_id = :cid')
                ->execute(['sid' => $settlementId, 'cid' => $companyId]);
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

// ---------------------------------------------------------------------------
// Posting preview — the mapping shown before it is committed
// ---------------------------------------------------------------------------

/**
 * What posting this draft WOULD write, without writing it.
 *
 * Nothing here re-implements the posting rules. The document is posted for
 * real inside a transaction, the voucher legs and stock movements it produced
 * are read back, and the transaction is ROLLED BACK — so the preview a user
 * confirms and the posting that then happens are the same code path, and the
 * two can never drift apart. A preview function that rebuilt the legs by hand
 * would agree with the posting only until someone changed one of them.
 *
 * This is what makes the Post button honest: the proposed mapping — which
 * ledger is debited, which credited, what moves in stock — is on the screen
 * before the user commits, and confirming it is the manual step the workflow
 * requires. Nothing posts sight unseen.
 *
 * The rollback also discards the activity log line and the id the voucher
 * briefly held; an id gap in AUTO_INCREMENT is the whole cost of the honesty.
 */
function jewellery_preview_posting(int $companyId, string $docType, int $docId): array
{
    $posters = [
        'sale' => 'jewellery_post_sale',
        'purchase' => 'jewellery_post_purchase',
        'settlement' => 'jewellery_post_settlement',
    ];
    $sources = [
        'sale' => 'jewellery_sale',
        'purchase' => 'jewellery_purchase',
        'settlement' => 'jewellery_settlement',
    ];
    $poster = $posters[$docType] ?? null;
    if ($poster === null) {
        return ['ok' => false, 'error' => 'Unknown document type.'];
    }
    if (db()->inTransaction()) {
        // Joining a caller's transaction would make their work part of the
        // rollback. A preview inside other writes has no honest meaning.
        return ['ok' => false, 'error' => 'A posting preview cannot run inside another transaction.'];
    }

    db()->beginTransaction();
    try {
        $GLOBALS['jw_allow_unbalanced_preview'] = true;
        $result = $poster($companyId, $docId, 0);
        unset($GLOBALS['jw_allow_unbalanced_preview']);
        if (!($result['ok'] ?? false)) {
            db()->rollBack();

            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'This document cannot be posted.')];
        }

        $voucherId = (int) ($result['voucher_id'] ?? 0);
        $legs = [];
        $debitTotal = 0.0;
        $creditTotal = 0.0;
        if ($voucherId > 0) {
            $legStmt = db()->prepare('SELECT e.entry_type, e.amount, e.memo,
                    l.name AS ledger_name, l.code AS ledger_code
                FROM voucher_entries e
                INNER JOIN ledgers l ON l.id = e.ledger_id
                WHERE e.voucher_id = :vid
                ORDER BY e.entry_type = \'credit\', e.id');
            $legStmt->execute(['vid' => $voucherId]);
            foreach ($legStmt->fetchAll(PDO::FETCH_ASSOC) as $leg) {
                $legs[] = $leg;
                if ((string) $leg['entry_type'] === 'debit') {
                    $debitTotal += (float) $leg['amount'];
                } else {
                    $creditTotal += (float) $leg['amount'];
                }
            }
        }

        $stockStmt = db()->prepare('SELECT t.direction, t.txn_type, t.gross_weight, t.fine_weight, t.amount,
                t.holder_type, i.sku AS item_code, i.name AS item_name, p.code AS purity_code, u.code AS unit_code
            FROM jewellery_stock_txns t
            INNER JOIN inventory_items i ON i.id = t.item_id
            LEFT JOIN jewellery_purities p ON p.id = t.purity_id
            LEFT JOIN jewellery_units u ON u.id = t.unit_id
            WHERE t.company_id = :cid AND t.source_type = :src AND t.source_id = :sid
            ORDER BY t.id');
        $stockStmt->execute(['cid' => $companyId, 'src' => $sources[$docType], 'sid' => $docId]);
        $stock = $stockStmt->fetchAll(PDO::FETCH_ASSOC);

        db()->rollBack();

        return [
            'ok' => true,
            'error' => '',
            'legs' => $legs,
            'stock' => $stock,
            'debit_total' => jw_round_money($debitTotal),
            'credit_total' => jw_round_money($creditTotal),
        ];
    } catch (Throwable $previewException) {
        unset($GLOBALS['jw_allow_unbalanced_preview']);
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $previewException->getMessage()];
    }
}
