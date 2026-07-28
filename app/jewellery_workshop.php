<?php
declare(strict_types=1);

/**
 * Jewellery Accounting — kaligad (karigar) masters, daily order management,
 * order assignment and receipt, and refinery jobs.
 *
 * WASTAGE IS THE WHOLE PROBLEM. Issue a karigar 10 tola of 22K and you do not
 * get 10 tola back — metal is genuinely consumed in the making. The shop
 * agrees an ALLOWED wastage percentage up front; everything beyond it is the
 * karigar's loss and comes out of their wages. So one receipt settles three
 * things at once, and jewellery_post_order_receipt() does all three atomically:
 *
 *   metal   the finished piece comes back IN, and the wastage is written off
 *           the karigar's holding so it lands back at exactly zero
 *   wages   the making charge is earned
 *   excess  wastage over the allowance is recovered from those wages
 *
 * The recovery can legitimately exceed the wages (a karigar who lost a lot on
 * a small job ends up owing the shop). Nothing special-cases that: the voucher
 * is assembled as signed legs and jw_build_entries() flips the sign, turning
 * the payable into a receivable on its own.
 *
 * A refinery job is the same round trip with different names — the loss is a
 * refining loss rather than making wastage, and the refiner charges a fee.
 */

require_once __DIR__ . '/jewellery_trade.php';

// ---------------------------------------------------------------------------
// Karigar master
// ---------------------------------------------------------------------------

function jewellery_karigars_list(int $companyId, bool $activeOnly = false): array
{
    $sql = 'SELECT k.*, ap.name AS party_name FROM jewellery_karigars k
        LEFT JOIN accounting_parties ap ON ap.id = k.party_id
        WHERE k.company_id = :cid'
        . ($activeOnly ? " AND k.status = 'active'" : '')
        . ' ORDER BY k.name ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute(['cid' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jewellery_karigar(int $companyId, int $karigarId): ?array
{
    if ($karigarId <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT k.*, ap.name AS party_name FROM jewellery_karigars k
        LEFT JOIN accounting_parties ap ON ap.id = k.party_id
        WHERE k.id = :id AND k.company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $karigarId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Create or update a karigar.
 *
 * A CONTRACTOR needs an accounting_parties row so wages become a bill-wise
 * trade payable; one is created automatically if not supplied. An EMPLOYEE
 * instead points at payroll_employees and their wages flow through payroll,
 * so no party ledger is opened for them.
 */
function jewellery_save_karigar(int $companyId, array $input, int $userId = 0): int
{
    $karigarId = (int) ($input['id'] ?? 0);
    $code = strtoupper(trim((string) ($input['code'] ?? '')));
    $name = trim((string) ($input['name'] ?? ''));
    if ($code === '' || $name === '') {
        throw new RuntimeException('Karigar code and name are required.');
    }

    $engagement = (string) ($input['engagement_type'] ?? 'contractor') === 'employee' ? 'employee' : 'contractor';
    $wastage = round((float) ($input['wastage_allowed_pct'] ?? 0), 3);
    if ($wastage < 0 || $wastage >= 100) {
        throw new RuntimeException('Allowed wastage must be between 0% and below 100%.');
    }

    $partyId = (int) ($input['party_id'] ?? 0) ?: null;
    if ($partyId !== null) {
        $check = db()->prepare('SELECT COUNT(*) FROM accounting_parties WHERE id = :id AND company_id = :cid');
        $check->execute(['id' => $partyId, 'cid' => $companyId]);
        if ((int) $check->fetchColumn() === 0) {
            throw new RuntimeException('Choose a party that belongs to this company.');
        }
    }

    $employeeId = (int) ($input['payroll_employee_id'] ?? 0) ?: null;
    if ($engagement === 'employee') {
        if ($employeeId === null) {
            throw new RuntimeException('An employee karigar must be linked to a payroll employee.');
        }
        if (table_exists('payroll_employees')) {
            $check = db()->prepare('SELECT COUNT(*) FROM payroll_employees WHERE id = :id AND company_id = :cid');
            $check->execute(['id' => $employeeId, 'cid' => $companyId]);
            if ((int) $check->fetchColumn() === 0) {
                throw new RuntimeException('Choose a payroll employee that belongs to this company.');
            }
        }
        // Employees are paid through payroll, so no trade-payable party.
        $partyId = null;
    } else {
        $employeeId = null;
        if ($partyId === null && table_exists('accounting_parties')) {
            $partyId = jw_ensure_karigar_party($companyId, $code, $name);
        }
    }

    $params = [
        'cid' => $companyId, 'code' => $code, 'name' => $name,
        'phone' => trim((string) ($input['phone'] ?? '')) ?: null,
        'address' => trim((string) ($input['address'] ?? '')) ?: null,
        'engagement' => $engagement, 'party' => $partyId, 'employee' => $employeeId,
        'basis' => jw_enum($input['default_making_basis'] ?? null, ['per_unit_weight', 'percent_of_metal', 'flat'], 'per_unit_weight'),
        'rate' => max(0.0, jw_round_rate((float) ($input['default_making_rate'] ?? 0))),
        'wastage' => $wastage,
        'status' => (string) ($input['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        'by' => $userId ?: null,
    ];

    if ($karigarId > 0) {
        if (!jewellery_karigar($companyId, $karigarId)) {
            throw new RuntimeException('Karigar not found for this company.');
        }
        db()->prepare('UPDATE jewellery_karigars SET code = :code, name = :name, phone = :phone, address = :address,
                engagement_type = :engagement, party_id = :party, payroll_employee_id = :employee,
                default_making_basis = :basis, default_making_rate = :rate, wastage_allowed_pct = :wastage,
                status = :status, notes = :notes
            WHERE id = :id AND company_id = :cid')
            ->execute([
                'code' => $params['code'], 'name' => $params['name'], 'phone' => $params['phone'],
                'address' => $params['address'], 'engagement' => $params['engagement'], 'party' => $params['party'],
                'employee' => $params['employee'], 'basis' => $params['basis'], 'rate' => $params['rate'],
                'wastage' => $params['wastage'], 'status' => $params['status'], 'notes' => $params['notes'],
                'id' => $karigarId, 'cid' => $companyId,
            ]);

        return $karigarId;
    }

    db()->prepare('INSERT INTO jewellery_karigars (company_id, code, name, phone, address, engagement_type, party_id,
            payroll_employee_id, default_making_basis, default_making_rate, wastage_allowed_pct, status, notes, created_by)
        VALUES (:cid, :code, :name, :phone, :address, :engagement, :party, :employee, :basis, :rate, :wastage, :status, :notes, :by)')
        ->execute($params);

    return (int) db()->lastInsertId();
}

/**
 * The ledger GROUP that per-holder metal ledgers are created under — the group
 * of whatever ledger the company mapped for that purpose. Returns 0 when the
 * purpose is unmapped, which the caller reports as a gap rather than guessing.
 */
function jw_metal_holding_group_id(int $companyId, string $purpose): int
{
    $mapped = jewellery_resolve_mapping($companyId, $purpose);

    return $mapped ? (int) ($mapped['group_id'] ?? 0) : 0;
}

/**
 * The dedicated metal ledger for ONE kaligad, created on demand.
 *
 * Metal out for making is still the shop's asset; it has only changed hands.
 * Giving each kaligad their own ledger is what lets the trial balance answer
 * "how much metal is with Ram?" rather than only "how much is out?" — the same
 * reason every party has its own receivable ledger instead of one lump AR.
 *
 * It also makes the issue voucher structurally safe: a per-kaligad ledger can
 * never BE the item's stock ledger, so the two legs can no longer cancel each
 * other out and leave an empty voucher.
 */
function jw_karigar_metal_ledger_id(int $companyId, array $karigar): int
{
    $karigarId = (int) ($karigar['id'] ?? 0);
    if ($karigarId <= 0 || !table_exists('ledgers')) {
        return 0;
    }

    // Honour a stored ledger only while it is still an active asset ledger of
    // this company — a re-pointed or deleted one must not receive metal.
    $stored = (int) ($karigar['metal_ledger_id'] ?? 0);
    if ($stored > 0) {
        $check = db()->prepare("SELECT id FROM ledgers WHERE id = :id AND company_id = :cid AND status = 'active' AND type = 'asset' LIMIT 1");
        $check->execute(['id' => $stored, 'cid' => $companyId]);
        if ((int) ($check->fetchColumn() ?: 0) > 0) {
            return $stored;
        }
    }

    $ledgerId = jw_holder_ledger($companyId, 'stock_karigar', 'MTL-KAR-' . $karigarId,
        'Metal with ' . (string) ($karigar['name'] ?? ('Kaligad #' . $karigarId)));

    if ($ledgerId > 0 && column_exists('jewellery_karigars', 'metal_ledger_id')) {
        db()->prepare('UPDATE jewellery_karigars SET metal_ledger_id = :lid WHERE id = :id AND company_id = :cid')
            ->execute(['lid' => $ledgerId, 'id' => $karigarId, 'cid' => $companyId]);
    }

    return $ledgerId;
}

/** The same, per refiner — refiners are parties, so this is keyed off the party. */
function jw_refiner_metal_ledger_id(int $companyId, int $partyId): int
{
    $name = 'Metal with refinery';
    if ($partyId > 0) {
        $partyStmt = db()->prepare('SELECT name FROM accounting_parties WHERE id = :id AND company_id = :cid LIMIT 1');
        $partyStmt->execute(['id' => $partyId, 'cid' => $companyId]);
        $partyName = (string) ($partyStmt->fetchColumn() ?: '');
        if ($partyName !== '') {
            $name = 'Metal with ' . $partyName;
        }
    }

    return jw_holder_ledger($companyId, 'stock_refinery',
        $partyId > 0 ? 'MTL-REF-' . $partyId : 'MTL-REF-GEN', $name);
}

/**
 * Find-or-create one holder's metal ledger, identified by a STABLE CODE.
 *
 * Identity is the code, never the name. Names are display text: renaming a
 * refiner from "Nepal Refinery" to "Nepal Refinery Pvt. Ltd." used to miss the
 * name lookup, create a second ledger, and leave the first holding a stranded
 * debit for a job that had completed cleanly. Since (company_id, code) is
 * unique, keying off the holder's id makes that impossible; the display name is
 * refreshed in passing so the chart of accounts still reads correctly.
 *
 * A ledger created under the old name-based scheme is adopted rather than
 * duplicated, so existing books keep their history.
 */
function jw_holder_ledger(int $companyId, string $groupPurpose, string $code, string $name): int
{
    if (!table_exists('ledgers')) {
        return 0;
    }
    $groupId = jw_metal_holding_group_id($companyId, $groupPurpose);
    if ($groupId <= 0) {
        return 0;
    }

    $byCode = db()->prepare('SELECT id, name FROM ledgers WHERE company_id = :cid AND code = :code LIMIT 1');
    $byCode->execute(['cid' => $companyId, 'code' => $code]);
    $row = $byCode->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if ((string) $row['name'] !== $name) {
            db()->prepare('UPDATE ledgers SET name = :name WHERE id = :id AND company_id = :cid')
                ->execute(['name' => $name, 'id' => (int) $row['id'], 'cid' => $companyId]);
        }

        return (int) $row['id'];
    }

    // Adopt a ledger opened by the earlier name-keyed version of this code,
    // and stamp the stable code onto it so it is never orphaned again.
    $byName = db()->prepare("SELECT id FROM ledgers WHERE company_id = :cid AND group_id = :gid AND name = :name AND status = 'active' LIMIT 1");
    $byName->execute(['cid' => $companyId, 'gid' => $groupId, 'name' => $name]);
    $ledgerId = (int) ($byName->fetchColumn() ?: 0);
    if ($ledgerId > 0) {
        db()->prepare('UPDATE ledgers SET code = :code WHERE id = :id AND company_id = :cid')
            ->execute(['code' => $code, 'id' => $ledgerId, 'cid' => $companyId]);

        return $ledgerId;
    }

    db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status) VALUES (:cid, :gid, :code, :name, 'asset', 'active')")
        ->execute(['cid' => $companyId, 'gid' => $groupId, 'code' => $code, 'name' => $name]);

    return (int) db()->lastInsertId();
}

/** Find or create the supplier party a contractor karigar's wages accrue to. */
function jw_ensure_karigar_party(int $companyId, string $code, string $name): int
{
    $partyCode = 'KAR-' . $code;
    $stmt = db()->prepare('SELECT id FROM accounting_parties WHERE company_id = :cid AND code = :code LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'code' => $partyCode]);
    $partyId = (int) ($stmt->fetchColumn() ?: 0);
    if ($partyId > 0) {
        return $partyId;
    }

    db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status)
        VALUES (:cid, :code, :name, 'supplier', 'active')")
        ->execute(['cid' => $companyId, 'code' => $partyCode, 'name' => $name . ' (Karigar)']);

    return (int) db()->lastInsertId();
}

/**
 * A karigar's metal position (fine weight still with them) and money position
 * (unsettled wage bills) — the two halves of "what does this karigar owe us,
 * and what do we owe them".
 */
function jewellery_karigar_position(int $companyId, int $karigarId, string $asOf = ''): array
{
    $metal = jewellery_holder_metal_position($companyId, 'karigar', $karigarId, $asOf);

    $karigar = jewellery_karigar($companyId, $karigarId);
    $payable = 0.0;
    if ($karigar && (int) ($karigar['party_id'] ?? 0) > 0) {
        $billStmt = db()->prepare("SELECT COALESCE(SUM(bill_amount - settled_amount), 0) FROM jewellery_bills
            WHERE company_id = :cid AND party_id = :pid AND status IN ('open', 'part_settled')");
        $billStmt->execute(['cid' => $companyId, 'pid' => (int) $karigar['party_id']]);
        $payable = jw_round_money((float) $billStmt->fetchColumn());
    }

    return [
        'fine_weight' => $metal['fine_weight'],
        'metal_value' => $metal['metal_value'],
        'wages_payable' => $payable,
    ];
}

/**
 * Fine weight and carrying value held by ONE holder, in the reporting unit.
 *
 * The sum is done in PHP, not SQL, on purpose: every row carries the unit its
 * document was written in, and only the unit table knows how to add a tola row
 * to a gram row. Summing the raw column — which is what a single SUM() would
 * do — silently reports 10 tola + 5 g as 15 of nothing.
 */
function jewellery_holder_metal_position(int $companyId, string $holderType, int $holderId, string $asOf = ''): array
{
    $sql = "SELECT direction, unit_id, fine_weight, amount FROM jewellery_stock_txns
        WHERE company_id = :cid AND holder_type = :ht AND holder_id = :hid";
    $params = ['cid' => $companyId, 'ht' => $holderType, 'hid' => $holderId];
    if ($asOf !== '') {
        $sql .= ' AND txn_date <= :d';
        $params['d'] = $asOf;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $unitMap = jw_unit_map($companyId);
    $baseUnit = jewellery_base_unit($companyId);
    $fine = 0.0;
    $value = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sign = (string) $row['direction'] === 'in' ? 1 : -1;
        $fine += $sign * jw_weight_in_base((float) $row['fine_weight'], (int) $row['unit_id'], $unitMap, $baseUnit);
        $value += $sign * (float) $row['amount'];
    }

    return [
        'fine_weight' => jw_round_weight($fine),
        'metal_value' => jw_round_money($value),
        'base_unit' => $baseUnit,
    ];
}

// ---------------------------------------------------------------------------
// Making-charge arithmetic (pure)
// ---------------------------------------------------------------------------

/**
 * The making charge for a piece of work.
 *   per_unit_weight  rate x gross weight   (the common piece-rate)
 *   percent_of_metal rate% of metal value
 *   flat             the rate itself
 */
function jw_making_charge(string $basis, float $rate, float $grossWeight, float $metalValue): float
{
    return jw_round_money(match ($basis) {
        'percent_of_metal' => $metalValue * $rate / 100.0,
        'flat' => $rate,
        default => $grossWeight * $rate,
    });
}

/**
 * Split a round trip's metal loss into the allowed part and the excess.
 * Returns ['wastage_fine', 'allowed_fine', 'excess_fine'] — never negative,
 * so a karigar who returns MORE than issued (metal added) is not charged.
 */
function jw_wastage_split(float $issuedFine, float $receivedFine, float $allowedPct, ?float $grantedFine = null): array
{
    $wastage = jw_round_weight(max(0.0, $issuedFine - $receivedFine));

    // NOBODY IS ALLOWED TO LOSE GOLD.
    //
    // The kaligad is paid a percentage for the work; the metal is the shop's
    // throughout. So a piece that comes back light is short by his doing and he
    // bears it — the default allowance is nothing at all, and every grain
    // missing is recovered from his wages.
    //
    // An allowance is a CONCESSION, granted by a person who has looked at the
    // actual loss and decided to let it go. It is never a standing rate that
    // forgives the loss before anyone knows there was one, which is why nothing
    // here reads a default from the kaligad's record any more.
    $allowed = $grantedFine !== null
        ? jw_round_weight(max(0.0, $grantedFine))
        : jw_round_weight($issuedFine * max(0.0, $allowedPct) / 100.0);

    return [
        'wastage_fine' => $wastage,
        'allowed_fine' => min($allowed, $wastage),
        'excess_fine' => jw_round_weight(max(0.0, $wastage - $allowed)),
        // The other direction. A kaligad short of metal tops up from his own
        // and hands back a heavier piece; that gold is his, and the shop owes
        // him for it. Wastage and surplus are mutually exclusive — one is
        // always zero — so nothing downstream has to choose between them.
        'surplus_fine' => jw_round_weight(max(0.0, $receivedFine - $issuedFine)),
    ];
}

// ---------------------------------------------------------------------------
// Orders
// ---------------------------------------------------------------------------

function jewellery_order(int $companyId, int $orderId): ?array
{
    $stmt = db()->prepare('SELECT o.*, ap.name AS party_name, m.name AS metal_name,
            p.code AS purity_code, p.fineness, u.code AS unit_code, i.sku AS item_code, i.name AS item_name
        FROM jewellery_orders o
        LEFT JOIN accounting_parties ap ON ap.id = o.party_id
        INNER JOIN jewellery_metals m ON m.id = o.metal_id
        INNER JOIN jewellery_purities p ON p.id = o.purity_id
        INNER JOIN jewellery_units u ON u.id = o.unit_id
        LEFT JOIN inventory_items i ON i.id = o.item_id
        LEFT JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        WHERE o.id = :id AND o.company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $orderId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function jewellery_orders_list(int $companyId, array $filters = []): array
{
    $sql = 'SELECT o.*, ap.name AS party_name, m.name AS metal_name, p.code AS purity_code, u.code AS unit_code,
            i.sku AS item_code
        FROM jewellery_orders o
        LEFT JOIN accounting_parties ap ON ap.id = o.party_id
        INNER JOIN jewellery_metals m ON m.id = o.metal_id
        INNER JOIN jewellery_purities p ON p.id = o.purity_id
        INNER JOIN jewellery_units u ON u.id = o.unit_id
        LEFT JOIN inventory_items i ON i.id = o.item_id
        LEFT JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        WHERE o.company_id = :cid';
    $params = ['cid' => $companyId];
    if (($filters['status'] ?? '') !== '') {
        $sql .= ' AND o.status = :st';
        $params['st'] = (string) $filters['status'];
    }
    if (($filters['from'] ?? '') !== '' && ($filters['to'] ?? '') !== '') {
        $sql .= ' AND o.order_date BETWEEN :from AND :to';
        $params['from'] = (string) $filters['from'];
        $params['to'] = (string) $filters['to'];
    }
    if (($filters['due_before'] ?? '') !== '') {
        $sql .= ' AND o.delivery_date IS NOT NULL AND o.delivery_date <= :due';
        $params['due'] = (string) $filters['due_before'];
    }
    if (!empty($filters['party_id'])) {
        $sql .= ' AND o.party_id = :pid';
        $params['pid'] = (int) $filters['party_id'];
    }
    if (!empty($filters['karigar_id'])) {
        $sql .= ' AND EXISTS (SELECT 1 FROM jewellery_order_lines ol
            WHERE ol.order_id = o.id AND ol.karigar_id = :kid)';
        $params['kid'] = (int) $filters['karigar_id'];
    }
    if (!empty($filters['overdue_only'])) {
        // Promised, past due, and still nobody has come in for it.
        $sql .= " AND o.delivery_date IS NOT NULL AND o.delivery_date < :today
            AND o.status NOT IN ('delivered', 'cancelled')";
        $params['today'] = date('Y-m-d');
    }
    if (trim((string) ($filters['search'] ?? '')) !== '') {
        $sql .= ' AND (o.order_no LIKE :q1 OR o.customer_name LIKE :q2
            OR o.design_no LIKE :q3 OR ap.name LIKE :q4)';
        $needle = '%' . trim((string) $filters['search']) . '%';
        foreach (['q1', 'q2', 'q3', 'q4'] as $key) {
            $params[$key] = $needle;
        }
    }
    $sql .= ' ORDER BY o.order_date DESC, o.id DESC LIMIT ' . max(1, min(1000, (int) ($filters['limit'] ?? 300)));

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** The items on an order, in the same shape a sale's lines come back in. */
function jewellery_order_line_rows(int $companyId, int $orderId): array
{
    $stmt = db()->prepare('SELECT l.*, i.sku AS item_code, i.name AS item_name, i.hs_code,
            jp.jewellery_type AS item_type, i.category, mt.name AS metal_name, mt.id AS metal_id,
            p.code AS purity_code, p.fineness, u.code AS unit_code,
            k.code AS karigar_code, k.name AS karigar_name,
            a.issue_no, a.issue_date, a.status AS assignment_status
        FROM jewellery_order_lines l
        INNER JOIN inventory_items i ON i.id = l.item_id
        INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        INNER JOIN jewellery_metals mt ON mt.id = jp.metal_id
        INNER JOIN jewellery_purities p ON p.id = l.purity_id
        INNER JOIN jewellery_units u ON u.id = l.unit_id
        LEFT JOIN jewellery_karigars k ON k.id = l.karigar_id
        LEFT JOIN jewellery_order_assignments a ON a.id = l.assignment_id
        WHERE l.company_id = :cid AND l.order_id = :oid ORDER BY l.id ASC');
    $stmt->execute(['cid' => $companyId, 'oid' => $orderId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create or revise an order, with as many items on it as the customer asked
 * for, priced the way the bill will price it.
 *
 * The quote goes through jw_compute_document under doc_type 'sale'. That is
 * deliberate and it is the whole point: an order is a sale that has not
 * happened yet, so the Skills Development levy, the VAT on the stone side and
 * the disjoint bases between them are worked out by the SAME code that will
 * raise the bill. A separate "estimate" formula is how a shop ends up quoting
 * one figure and charging another.
 *
 * The rate and the tax rules are those of the ORDER date. On delivery the
 * metal rate is honoured from that day (the shop's promise) but the taxes are
 * restated at the sale date, because a statutory rate follows the day of
 * supply. So this total is exact on the day it is given, and the bill says so.
 *
 * @param array $lines line rows as jw_posted_lines() returns them
 */
function jewellery_save_order(int $companyId, int $fiscalYearId, array $input, array $lines = [], int $userId = 0): int
{
    $orderId = (int) ($input['id'] ?? 0);
    $settings = jewellery_settings($companyId);
    $orderDate = (string) ($input['order_date'] ?? date('Y-m-d'));

    // A caller that does not mention a field leaves it as it was, so removing
    // a field from the order form can never make the SCREEN decide what the
    // database forgets. A caller that does send it still overwrites it.
    $existingOrder = $orderId > 0 ? jewellery_order($companyId, $orderId) : null;
    $keep = static function (string $field, $fallback) use ($input, $existingOrder) {
        if (array_key_exists($field, $input)) {
            return $input[$field];
        }

        return $existingOrder !== null && ($existingOrder[$field] ?? null) !== null
            ? $existingOrder[$field] : $fallback;
    };

    // An order is the start of a customer relationship, so it opens the party
    // and its ledger immediately — name, phone and address all live there
    // rather than as loose text on the order.
    $customerName = trim((string) ($input['customer_name'] ?? ''));
    $partyId = jw_resolve_party($companyId, $input + [
        'party_name' => $customerName,
        'party_phone' => $input['customer_phone'] ?? '',
        'party_address' => $input['customer_address'] ?? '',
    ], 'customer') ?: null;
    if ($partyId === null) {
        throw new RuntimeException('Enter the customer — a name is enough, the party and its ledger are created automatically.');
    }

    // An order taken the old way — one item described on the header — is still
    // a valid order. It becomes a single line here rather than being refused,
    // so every caller written before orders had lines keeps working and the
    // arithmetic below has exactly one shape to deal with.
    if ($lines === [] && (int) ($input['item_id'] ?? 0) > 0) {
        $lines = [[
            'item_id' => (int) $input['item_id'],
            'purity_id' => (int) ($input['purity_id'] ?? 0),
            'unit_id' => (int) ($input['unit_id'] ?? 0),
            'qty_pieces' => 1,
            'gross_weight' => (float) ($input['expected_gross_weight'] ?? 0),
            'rate' => (float) ($input['rate'] ?? 0),
            'making_amount' => jw_making_charge(
                (string) ($input['making_basis'] ?? 'per_unit_weight'),
                (float) ($input['making_rate'] ?? 0),
                (float) ($input['expected_gross_weight'] ?? 0),
                jw_round_money((float) ($input['expected_gross_weight'] ?? 0) * (float) ($input['rate'] ?? 0))
            ),
        ]];
    }

    $computed = jw_compute_document($companyId, [
        'document_date' => $orderDate,
        'doc_type' => 'sale',
        'rate_type' => 'sale',
        'other_charges' => $input['other_charges'] ?? 0,
        'discount' => $input['discount'] ?? 0,
        'manual_tax_amount' => $input['manual_tax_amount'] ?? null,
    ], $lines, $settings);
    if ($computed['errors'] !== []) {
        throw new RuntimeException(implode(' ', $computed['errors']));
    }

    if ($computed['lines'] !== []) {
        // The header keeps mirroring the FIRST line. Every karigar issue,
        // receipt and refinery job values the metal it moves against the
        // order's purity and unit, so those columns have to stay meaningful.
        $first = $computed['lines'][0];
        $firstItem = jewellery_item($companyId, (int) $first['item_id']);
        $firstItemId = (int) $first['item_id'];
        $metalId = (int) $firstItem['metal_id'];
        $purityId = (int) $first['purity_id'];
        $unitId = (int) $first['unit_id'];

        // Weight is summed across the lines so the workshop board still shows
        // what the whole order weighs, not just its first piece.
        $grossTotal = 0.0;
        $fineTotal = 0.0;
        foreach ($computed['lines'] as $lineRow) {
            $grossTotal += (float) $lineRow['gross_weight'];
            $fineTotal += (float) $lineRow['fine_weight'];
        }
    } else {
        // No lines. The order form always sends them, so this is an order
        // already in the books from before orders had lines, or a caller that
        // describes the piece on the header. It stays supported so the karigar
        // can still be issued metal against such an order, but it quotes
        // nothing — an unquoted order shows no total rather than a wrong one.
        $metalId = (int) ($input['metal_id'] ?? 0);
        if ($metalId <= 0) {
            throw new RuntimeException('An order needs at least one item.');
        }
        if (!jewellery_metal($companyId, $metalId)) {
            throw new RuntimeException('Choose a metal that belongs to this company.');
        }
        $purityId = (int) ($input['purity_id'] ?? 0);
        $headerPurity = jewellery_purity($companyId, $purityId);
        if (!$headerPurity || (int) $headerPurity['metal_id'] !== $metalId) {
            throw new RuntimeException('Choose a purity that belongs to the selected metal.');
        }
        $unitId = (int) ($input['unit_id'] ?? 0);
        if (!jewellery_unit($companyId, $unitId)) {
            throw new RuntimeException('Choose a weight unit that belongs to this company.');
        }
        $firstItemId = 0;
        $grossTotal = jw_round_weight((float) ($input['expected_gross_weight'] ?? 0));
        if ($grossTotal < 0) {
            throw new RuntimeException('The expected weight cannot be negative.');
        }
        $fineTotal = jw_fine_weight($grossTotal, (float) $headerPurity['fineness']);
    }

    // Each item goes to its own kaligad, on its own promised date. Kaligads
    // specialise — the one who makes chains does not set stones — so an order
    // for a chain and a diamond ring is routinely two craftsmen and two dates.
    $lineKarigars = [];
    $lineDates = [];
    foreach ($computed['lines'] as $index => $lineRow) {
        $lineKarigarId = (int) ($lineRow['karigar_id'] ?? 0);
        if ($lineKarigarId > 0 && !jewellery_karigar($companyId, $lineKarigarId)) {
            throw new RuntimeException('Item ' . ($index + 1) . ': choose a kaligad that belongs to this company.');
        }
        $lineKarigars[$index] = $lineKarigarId ?: null;

        $lineDate = trim((string) ($lineRow['delivery_date'] ?? ''));
        if ($lineDate !== '' && strtotime($lineDate) === false) {
            throw new RuntimeException('Item ' . ($index + 1) . ': that promised date is not a date.');
        }
        if ($lineDate !== '' && $lineDate < $orderDate) {
            throw new RuntimeException('Item ' . ($index + 1) . ': it cannot be promised before the order was taken.');
        }
        $lineDates[$index] = $lineDate !== '' ? $lineDate : null;
    }

    // The order's own promise is the LAST of the item dates — the day the whole
    // order can be collected. A customer collecting one order makes one journey,
    // so promising them the earliest item would be a promise the shop breaks.
    $headerDelivery = ($input['delivery_date'] ?? '') !== '' ? (string) $input['delivery_date'] : null;
    $latestLineDate = null;
    foreach ($lineDates as $lineDate) {
        if ($lineDate !== null && ($latestLineDate === null || $lineDate > $latestLineDate)) {
            $latestLineDate = $lineDate;
        }
    }
    if ($latestLineDate !== null) {
        $headerDelivery = $headerDelivery === null || $latestLineDate > $headerDelivery
            ? $latestLineDate : $headerDelivery;
    }

    $totals = $computed['totals'];
    $status = jw_enum($input['status'] ?? null, ['draft', 'confirmed', 'assigned', 'received', 'delivered', 'cancelled'], 'draft');
    $advance = max(0.0, jw_round_money((float) ($input['advance_amount'] ?? 0)));
    if ($advance > $totals['total_amount'] + 0.005) {
        throw new RuntimeException('The advance (' . number_format($advance, 2)
            . ') cannot exceed what the order comes to (' . number_format($totals['total_amount'], 2) . ').');
    }

    $params = [
        'cid' => $companyId, 'fy' => $fiscalYearId ?: null,
        'date' => $orderDate,
        'delivery' => $headerDelivery,
        'party' => $partyId, 'cname' => $customerName ?: null,
        'cphone' => trim((string) ($input['customer_phone'] ?? '')) ?: null,
        'item' => $firstItemId ?: null, 'metal' => $metalId, 'purity' => $purityId, 'unit' => $unitId,
        'gross' => jw_round_weight($grossTotal), 'fine' => jw_round_weight($fineTotal),
        'design' => trim((string) ($input['design_no'] ?? '')) ?: null,
        'description' => trim((string) ($input['description'] ?? '')) ?: null,
        'basis' => jw_enum($keep('making_basis', null), ['per_unit_weight', 'percent_of_metal', 'flat'], 'per_unit_weight'),
        'rate' => max(0.0, jw_round_rate((float) $keep('making_rate', 0))),
        'advance' => $advance,
        'status' => $status,
        'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        'metalamt' => $totals['metal_amount'], 'wastageamt' => $totals['wastage_amount'],
        'makingamt' => $totals['making_amount'], 'stoneamt' => $totals['stone_amount'],
        'diamondamt' => $totals['diamond_amount'],
        'other' => $totals['other_charges'], 'discount' => $totals['discount'],
        'taxable' => $totals['taxable_amount'], 'nontax' => $totals['non_taxable_amount'],
        'sdtaxable' => $totals['sd_taxable_amount'], 'vatable' => $totals['vatable_amount'],
        'vat' => $totals['vat_amount'], 'tax' => $totals['tax_amount'],
        'mtax' => $totals['manual_tax_amount'], 'total' => $totals['total_amount'],
    ];

    $priorAssignments = [];
    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        if ($orderId > 0) {
            $existing = jewellery_order($companyId, $orderId);
            if (!$existing) {
                throw new RuntimeException('Order not found for this company.');
            }
            // Once metal is out with a karigar the order's specification is what
            // the issue was measured against — changing it would rewrite history.
            if (in_array((string) $existing['status'], ['assigned', 'received', 'delivered'], true)
                && ((int) $existing['purity_id'] !== $purityId || (int) $existing['unit_id'] !== $unitId)) {
                throw new RuntimeException('This order already has metal issued against it — its purity and unit can no longer be changed.');
            }
            db()->prepare('UPDATE jewellery_orders SET order_date = :date, delivery_date = :delivery, party_id = :party,
                    customer_name = :cname, customer_phone = :cphone, item_id = :item, metal_id = :metal, purity_id = :purity,
                    unit_id = :unit, expected_gross_weight = :gross, expected_fine_weight = :fine, design_no = :design,
                    description = :description, making_basis = :basis, making_rate = :rate, advance_amount = :advance,
                    status = :status, notes = :notes, fiscal_year_id = :fy,
                    metal_amount = :metalamt, wastage_amount = :wastageamt, making_amount = :makingamt,
                    stone_amount = :stoneamt, diamond_amount = :diamondamt, other_charges = :other, discount = :discount,
                    taxable_amount = :taxable, non_taxable_amount = :nontax, sd_taxable_amount = :sdtaxable,
                    vatable_amount = :vatable, vat_amount = :vat, tax_amount = :tax, manual_tax_amount = :mtax,
                    total_amount = :total
                WHERE id = :id AND company_id = :cid')
                ->execute($params + ['id' => $orderId]);

            // Revising an order replaces its lines. Any line that already has
            // metal out with a kaligad must survive that intact: its issue
            // points back at it, and dropping the line would leave issued metal
            // attached to nothing.
            //
            // Rows are matched by their stored id, NOT by position. Two rows can
            // hold the same item, and the shop can reorder them, so position
            // says nothing about which line is which.
            $priorStmt = db()->prepare('SELECT id, item_id, assignment_id FROM jewellery_order_lines
                WHERE order_id = :oid AND company_id = :cid ORDER BY id ASC');
            $priorStmt->execute(['oid' => $orderId, 'cid' => $companyId]);
            $submittedLineIds = [];
            foreach ($computed['lines'] as $index => $submitted) {
                if ((int) ($submitted['line_id'] ?? 0) > 0) {
                    $submittedLineIds[(int) $submitted['line_id']] = $index;
                }
            }
            foreach ($priorStmt->fetchAll(PDO::FETCH_ASSOC) as $priorLine) {
                if ((int) ($priorLine['assignment_id'] ?? 0) <= 0) {
                    continue;
                }
                $priorLineId = (int) $priorLine['id'];
                if (!isset($submittedLineIds[$priorLineId])) {
                    throw new RuntimeException('An item on this order already has metal out with a kaligad, '
                        . 'so it cannot be removed. Cancel that issue first.');
                }
                $index = $submittedLineIds[$priorLineId];
                if ((int) $computed['lines'][$index]['item_id'] !== (int) $priorLine['item_id']) {
                    throw new RuntimeException('Item ' . ($index + 1) . ' already has metal out with a kaligad, '
                        . 'so it cannot be changed to a different item. Cancel that issue first.');
                }
                $priorAssignments[$index] = (int) $priorLine['assignment_id'];
            }

            db()->prepare('DELETE FROM jewellery_order_lines WHERE order_id = :oid AND company_id = :cid')
                ->execute(['oid' => $orderId, 'cid' => $companyId]);
        } else {
            $no = trim((string) ($input['order_no'] ?? '')) ?: jw_next_no($companyId, 'jewellery_orders', 'order_no', (string) ($settings['order_no_prefix'] ?? 'JO'));
            db()->prepare('INSERT INTO jewellery_orders (company_id, fiscal_year_id, order_no, order_date, delivery_date, party_id,
                    customer_name, customer_phone, item_id, metal_id, purity_id, unit_id, expected_gross_weight, expected_fine_weight,
                    design_no, description, making_basis, making_rate, advance_amount, status, notes,
                    metal_amount, wastage_amount, making_amount, stone_amount, diamond_amount, other_charges, discount,
                    taxable_amount, non_taxable_amount, sd_taxable_amount, vatable_amount, vat_amount, tax_amount,
                    manual_tax_amount, total_amount, created_by)
                VALUES (:cid, :fy, :no, :date, :delivery, :party, :cname, :cphone, :item, :metal, :purity, :unit, :gross, :fine,
                    :design, :description, :basis, :rate, :advance, :status, :notes,
                    :metalamt, :wastageamt, :makingamt, :stoneamt, :diamondamt, :other, :discount,
                    :taxable, :nontax, :sdtaxable, :vatable, :vat, :tax, :mtax, :total, :by)')
                ->execute($params + ['no' => $no, 'by' => $userId ?: null]);
            $orderId = (int) db()->lastInsertId();
        }

        $lineStmt = db()->prepare('INSERT INTO jewellery_order_lines (order_id, company_id, item_id, karigar_id,
                delivery_date, assignment_id, purity_id, unit_id,
                qty_pieces, gross_weight, stone_weight, net_weight, fine_weight, rate, metal_amount,
                wastage_pct, wastage_weight, total_weight, wastage_amount, making_amount, stone_amount,
                stone_carat, diamond_amount, diamond_carat, other_diamond_amount, other_diamond_carat,
                vat_base, vat_rate, vat_amount, tax_amount, allocated_adjust, line_total, notes)
            VALUES (:oid, :cid, :item, :karigar, :ldelivery, :assignment, :purity, :unit,
                :pieces, :gross, :sweight, :net, :fine, :rate, :metal,
                :wpct, :wweight, :tweight, :wamount, :making, :stone,
                :scarat, :diamond, :dcarat, :odiamond, :odcarat, :vbase, :vrate, :vamount, :tamount, :adjust, :ltotal, :notes)');
        $insertedLineIds = [];
        foreach ($computed['lines'] as $lineIndex => $row) {
            $lineStmt->execute([
                'karigar' => $lineKarigars[$lineIndex] ?? null,
                'ldelivery' => $lineDates[$lineIndex] ?? null,
                'assignment' => $priorAssignments[$lineIndex] ?? null,
                'oid' => $orderId, 'cid' => $companyId, 'item' => $row['item_id'], 'purity' => $row['purity_id'],
                'unit' => $row['unit_id'], 'pieces' => $row['qty_pieces'], 'gross' => $row['gross_weight'],
                'sweight' => $row['stone_weight'], 'net' => $row['net_weight'],
                'fine' => $row['fine_weight'], 'rate' => $row['rate'], 'metal' => $row['metal_amount'],
                'wpct' => $row['wastage_pct'], 'wweight' => $row['wastage_weight'],
                'tweight' => $row['total_weight'], 'wamount' => $row['wastage_amount'],
                'making' => $row['making_amount'], 'stone' => $row['stone_amount'],
                'scarat' => $row['stone_carat'], 'diamond' => $row['diamond_amount'],
                'dcarat' => $row['diamond_carat'], 'odiamond' => $row['other_diamond_amount'],
                'odcarat' => $row['other_diamond_carat'], 'vbase' => $row['vat_base'],
                'vrate' => $row['vat_rate'], 'vamount' => $row['vat_amount'], 'tamount' => $row['tax_amount'],
                'adjust' => $row['allocated_adjust'],
                'ltotal' => $row['line_total'], 'notes' => $row['notes'] !== '' ? $row['notes'] : null,
            ]);
            $insertedLineIds[$lineIndex] = (int) db()->lastInsertId();
        }

        // The assignment points back at the line it covers. The line ids just
        // changed, so any issue carried across has to be re-pointed or the
        // workshop board would show issued metal against a line that is gone.
        foreach ($priorAssignments as $lineIndex => $assignmentId) {
            if (isset($insertedLineIds[$lineIndex])) {
                db()->prepare('UPDATE jewellery_order_assignments SET order_line_id = :lid
                    WHERE id = :aid AND company_id = :cid')
                    ->execute(['lid' => $insertedLineIds[$lineIndex], 'aid' => $assignmentId, 'cid' => $companyId]);
            }
        }

        if ($ownsTransaction) {
            db()->commit();
        }
    } catch (Throwable $orderException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $orderException;
    }

    return $orderId;
}

/**
 * Cancel an order the customer walked away from.
 *
 * Cancelling is not deleting: the order stays in the books with its number, its
 * quote and whatever advance was taken against it, because all of that
 * happened. What it stops is the work. So an order with metal still out with a
 * kaligad refuses to cancel — that metal is real and has to come back first.
 */
function jewellery_cancel_order(int $companyId, int $orderId, string $reason = '', int $userId = 0): array
{
    $order = jewellery_order($companyId, $orderId);
    if (!$order) {
        return ['ok' => false, 'error' => 'Order not found for this company.'];
    }
    if ((string) $order['status'] === 'cancelled') {
        return ['ok' => false, 'error' => 'This order is already cancelled.'];
    }
    if ((string) $order['status'] === 'delivered') {
        return ['ok' => false, 'error' => 'This order has already been delivered.'];
    }

    $outStmt = db()->prepare("SELECT COUNT(*) FROM jewellery_order_assignments
        WHERE company_id = :cid AND order_id = :oid AND status = 'issued'");
    $outStmt->execute(['cid' => $companyId, 'oid' => $orderId]);
    if ((int) $outStmt->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'This order still has metal out with a kaligad. '
            . 'Take it back or cancel the issue first.'];
    }

    $note = trim($reason);
    db()->prepare("UPDATE jewellery_orders SET status = 'cancelled',
            notes = TRIM(CONCAT(COALESCE(notes, ''), :note))
        WHERE id = :id AND company_id = :cid")
        ->execute([
            'note' => $note !== '' ? "\nCancelled: " . $note : '',
            'id' => $orderId, 'cid' => $companyId,
        ]);
    log_activity('company', $companyId, 'jewellery_order_cancel',
        'Order ' . $order['order_no'] . ' cancelled.' . ($note !== '' ? ' ' . $note : ''), $userId);

    $held = jewellery_order_advance_available($companyId, $orderId);

    return ['ok' => true, 'error' => '', 'advance_held' => $held];
}

/**
 * Push an order's promised date back, on every item that has not already gone
 * out to a kaligad.
 *
 * An item already being made keeps the date its issue was measured against —
 * the kaligad was told a day and his wastage allowance runs to it — so only the
 * items still waiting move. The order's own promise follows the last of them.
 */
function jewellery_postpone_order(int $companyId, int $orderId, string $newDate, string $reason = '', int $userId = 0): array
{
    $order = jewellery_order($companyId, $orderId);
    if (!$order) {
        return ['ok' => false, 'error' => 'Order not found for this company.'];
    }
    if (in_array((string) $order['status'], ['delivered', 'cancelled'], true)) {
        return ['ok' => false, 'error' => 'A ' . $order['status'] . ' order cannot be rescheduled.'];
    }
    if ($newDate === '' || strtotime($newDate) === false) {
        return ['ok' => false, 'error' => 'Enter the new date.'];
    }
    if ($newDate < (string) $order['order_date']) {
        return ['ok' => false, 'error' => 'The new date cannot fall before the order was taken.'];
    }

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        db()->prepare('UPDATE jewellery_order_lines SET delivery_date = :d
            WHERE order_id = :oid AND company_id = :cid AND assignment_id IS NULL')
            ->execute(['d' => $newDate, 'oid' => $orderId, 'cid' => $companyId]);

        // The order's promise is the last of its items, so an item already out
        // with a kaligad on a later date still governs when the whole order can
        // be collected.
        $maxStmt = db()->prepare('SELECT MAX(delivery_date) FROM jewellery_order_lines
            WHERE order_id = :oid AND company_id = :cid');
        $maxStmt->execute(['oid' => $orderId, 'cid' => $companyId]);
        $latest = (string) ($maxStmt->fetchColumn() ?: $newDate);

        $note = trim($reason);
        db()->prepare("UPDATE jewellery_orders SET delivery_date = :d,
                notes = TRIM(CONCAT(COALESCE(notes, ''), :note))
            WHERE id = :id AND company_id = :cid")
            ->execute([
                'd' => $latest,
                'note' => "\nPostponed to " . $latest . ($note !== '' ? ': ' . $note : ''),
                'id' => $orderId, 'cid' => $companyId,
            ]);

        if ($ownsTransaction) {
            db()->commit();
        }
    } catch (Throwable $postponeException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $postponeException->getMessage()];
    }
    log_activity('company', $companyId, 'jewellery_order_postpone',
        'Order ' . $order['order_no'] . ' rescheduled to ' . $newDate . '.' . ($reason !== '' ? ' ' . $reason : ''), $userId);

    return ['ok' => true, 'error' => '', 'delivery_date' => $newDate];
}

function jewellery_delete_order(int $companyId, int $orderId): bool
{
    // Only an order that never reached a karigar may be removed outright.
    $stmt = db()->prepare("DELETE FROM jewellery_orders WHERE id = :id AND company_id = :cid
        AND status IN ('draft', 'confirmed', 'cancelled')
        AND NOT EXISTS (SELECT 1 FROM jewellery_order_assignments a WHERE a.order_id = jewellery_orders.id)");
    $stmt->execute(['id' => $orderId, 'cid' => $companyId]);

    return $stmt->rowCount() > 0;
}

// ---------------------------------------------------------------------------
// Assignment — metal out to a karigar
// ---------------------------------------------------------------------------

function jewellery_assignment(int $companyId, int $assignmentId): ?array
{
    $stmt = db()->prepare('SELECT a.*, k.name AS karigar_name, k.code AS karigar_code, k.engagement_type, k.party_id,
            o.order_no, i.sku AS item_code, i.name AS item_name, p.code AS purity_code, p.fineness, u.code AS unit_code
        FROM jewellery_order_assignments a
        INNER JOIN jewellery_karigars k ON k.id = a.karigar_id
        LEFT JOIN jewellery_orders o ON o.id = a.order_id
        INNER JOIN inventory_items i ON i.id = a.item_id
            INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        INNER JOIN jewellery_purities p ON p.id = a.purity_id
        INNER JOIN jewellery_units u ON u.id = a.unit_id
        WHERE a.id = :id AND a.company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $assignmentId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function jewellery_assignments_list(int $companyId, array $filters = []): array
{
    $sql = 'SELECT a.*, k.name AS karigar_name, k.code AS karigar_code, o.order_no,
            i.sku AS item_code, p.code AS purity_code, u.code AS unit_code
        FROM jewellery_order_assignments a
        INNER JOIN jewellery_karigars k ON k.id = a.karigar_id
        LEFT JOIN jewellery_orders o ON o.id = a.order_id
        INNER JOIN inventory_items i ON i.id = a.item_id
            INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        INNER JOIN jewellery_purities p ON p.id = a.purity_id
        INNER JOIN jewellery_units u ON u.id = a.unit_id
        WHERE a.company_id = :cid';
    $params = ['cid' => $companyId];
    if (($filters['status'] ?? '') !== '') {
        $sql .= ' AND a.status = :st';
        $params['st'] = (string) $filters['status'];
    }
    if (!empty($filters['karigar_id'])) {
        $sql .= ' AND a.karigar_id = :kid';
        $params['kid'] = (int) $filters['karigar_id'];
    }
    if (($filters['from'] ?? '') !== '' && ($filters['to'] ?? '') !== '') {
        $sql .= ' AND a.issue_date BETWEEN :from AND :to';
        $params['from'] = (string) $filters['from'];
        $params['to'] = (string) $filters['to'];
    }
    if (trim((string) ($filters['search'] ?? '')) !== '') {
        $sql .= ' AND (a.issue_no LIKE :q1 OR k.name LIKE :q2 OR k.code LIKE :q3
            OR i.sku LIKE :q4 OR o.order_no LIKE :q5)';
        $needle = '%' . trim((string) $filters['search']) . '%';
        foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $key) {
            $params[$key] = $needle;
        }
    }
    $sql .= ' ORDER BY a.issue_date DESC, a.id DESC LIMIT ' . max(1, min(1000, (int) ($filters['limit'] ?? 300)));

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Issue metal to a karigar against an order.
 *
 * The metal leaves own stock and enters that karigar's holding — the total
 * position is unchanged, only its location. A money voucher is posted ONLY if
 * a "Metal with karigar" ledger is mapped; when it is not, the value simply
 * stays in the main stock ledger, which is equally correct, just less granular.
 */
/**
 * Order items still waiting to go out to a kaligad — one row per item, not per
 * order, because an order for a chain and a ring is two separate jobs going to
 * two separate craftsmen on two separate dates.
 */
function jewellery_pending_order_lines(int $companyId): array
{
    $stmt = db()->prepare("SELECT l.id, l.order_id, l.karigar_id, l.delivery_date, l.gross_weight,
            l.purity_id, l.unit_id, l.item_id,
            o.order_no, o.order_date, COALESCE(ap.name, o.customer_name, 'Walk-in') AS party_label,
            i.sku AS item_code, i.name AS item_name,
            k.code AS karigar_code, k.name AS karigar_name,
            u.code AS unit_code, p.code AS purity_code
        FROM jewellery_order_lines l
        INNER JOIN jewellery_orders o ON o.id = l.order_id
        INNER JOIN inventory_items i ON i.id = l.item_id
        INNER JOIN jewellery_units u ON u.id = l.unit_id
        INNER JOIN jewellery_purities p ON p.id = l.purity_id
        LEFT JOIN jewellery_karigars k ON k.id = l.karigar_id
        LEFT JOIN accounting_parties ap ON ap.id = o.party_id
        WHERE l.company_id = :cid AND l.assignment_id IS NULL
          AND o.status IN ('draft', 'confirmed', 'assigned')
        ORDER BY COALESCE(l.delivery_date, '9999-12-31') ASC, o.order_no ASC, l.id ASC");
    $stmt->execute(['cid' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * What a kaligad is holding against what the work in his hands actually needs.
 *
 * Metal is issued in the weights a shop can hand over — a bar, a coil, a round
 * figure — not in the exact sum of the pieces it will become. So the issued
 * weight and the ordered weight are not meant to agree, and forcing them to
 * would make the shop lie about one or the other. What matters is the
 * DIFFERENCE, watched until the work comes back:
 *
 *     held        the fine metal physically with him right now
 *     committed   the fine metal the outstanding items need
 *     difference  held − committed
 *                 positive → he is holding more than the work needs (excess)
 *                 negative → he has not been given enough for it (shortfall)
 *
 * Both figures are in the company's base unit, so a shop issuing in tola and
 * ordering in grams still gets one comparable number.
 */
function jewellery_karigar_metal_balance(int $companyId, int $karigarId, string $asOf = ''): array
{
    $position = jewellery_holder_metal_position($companyId, 'karigar', $karigarId, $asOf);

    // What the still-outstanding items need. Only issues still 'issued' count:
    // once a piece is received back the metal for it is no longer committed.
    $stmt = db()->prepare("SELECT l.fine_weight, l.unit_id
        FROM jewellery_order_lines l
        INNER JOIN jewellery_order_assignments a ON a.id = l.assignment_id
        WHERE l.company_id = :cid AND a.karigar_id = :kid AND a.status = 'issued'");
    $stmt->execute(['cid' => $companyId, 'kid' => $karigarId]);

    $unitMap = jw_unit_map($companyId);
    $baseUnit = jewellery_base_unit($companyId);
    $committed = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $committed += jw_weight_in_base((float) $row['fine_weight'], (int) $row['unit_id'], $unitMap, $baseUnit);
    }
    $committed = jw_round_weight($committed);
    $held = (float) $position['fine_weight'];
    $difference = jw_round_weight($held - $committed);

    return [
        'held_fine' => $held,
        'committed_fine' => $committed,
        'difference_fine' => $difference,
        'excess_fine' => max(0.0, $difference),
        'shortfall_fine' => max(0.0, -$difference),
        'metal_value' => (float) $position['metal_value'],
        'base_unit' => $baseUnit,
    ];
}

/**
 * Orders whose promised date has passed and which the customer has not come in
 * for. The piece is finished and paid for in metal, sitting in the safe, and
 * nobody has collected it — money the shop cannot use and gold it is insuring
 * for someone else.
 *
 * Cancelled and delivered orders are out by definition; an order with no date
 * promised was never late.
 */
function jewellery_overdue_orders(int $companyId, string $asOf = ''): array
{
    $asOf = $asOf !== '' ? $asOf : date('Y-m-d');
    $stmt = db()->prepare("SELECT o.id, o.order_no, o.order_date, o.delivery_date, o.status,
            o.expected_gross_weight, o.total_amount, o.advance_amount, o.customer_phone,
            COALESCE(ap.name, o.customer_name, 'Walk-in') AS party_label,
            ap.phone AS party_phone,
            u.code AS unit_code,
            DATEDIFF(:asof, o.delivery_date) AS days_late
        FROM jewellery_orders o
        LEFT JOIN accounting_parties ap ON ap.id = o.party_id
        INNER JOIN jewellery_units u ON u.id = o.unit_id
        WHERE o.company_id = :cid
          AND o.delivery_date IS NOT NULL AND o.delivery_date < :asof2
          AND o.status NOT IN ('delivered', 'cancelled')
        ORDER BY o.delivery_date ASC, o.order_no ASC");
    $stmt->execute(['cid' => $companyId, 'asof' => $asOf, 'asof2' => $asOf]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totals = ['orders' => count($rows), 'value' => 0.0, 'advance' => 0.0, 'balance' => 0.0];
    foreach ($rows as $index => $row) {
        $value = (float) $row['total_amount'];
        $advance = (float) $row['advance_amount'];
        $rows[$index]['balance_due'] = jw_round_money($value - $advance);
        $rows[$index]['phone'] = (string) ($row['party_phone'] ?? $row['customer_phone'] ?? '');
        $totals['value'] += $value;
        $totals['advance'] += $advance;
        $totals['balance'] += $value - $advance;
    }
    foreach (['value', 'advance', 'balance'] as $key) {
        $totals[$key] = jw_round_money($totals[$key]);
    }

    return ['rows' => $rows, 'totals' => $totals, 'as_of' => $asOf];
}

/** One line of an order, with its item, kaligad and promised date. */
function jewellery_order_line(int $companyId, int $orderLineId): ?array
{
    $stmt = db()->prepare('SELECT * FROM jewellery_order_lines WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $orderLineId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function jewellery_issue_to_karigar(int $companyId, int $fiscalYearId, array $input, int $userId = 0): array
{
    // One issue can cover SEVERAL items. A kaligad given a bar of gold makes
    // three chains out of it; the metal handed over is a round weight the shop
    // has to hand, not the exact sum of what the pieces will come to. So the
    // items are a list, the weight is the issuer's to state, and the difference
    // between what went out and what the work needs is a balance to be watched
    // rather than an error to be prevented — see jewellery_karigar_metal_balance().
    $orderLineIds = [];
    foreach ((array) ($input['order_line_ids'] ?? []) as $candidateId) {
        if ((int) $candidateId > 0) {
            $orderLineIds[(int) $candidateId] = (int) $candidateId;
        }
    }
    if ((int) ($input['order_line_id'] ?? 0) > 0) {
        $orderLineIds[(int) $input['order_line_id']] = (int) $input['order_line_id'];
    }
    $orderLineIds = array_values($orderLineIds);

    $orderLines = [];
    $lineGrossTotal = 0.0;
    foreach ($orderLineIds as $candidateId) {
        $candidate = jewellery_order_line($companyId, $candidateId);
        if (!$candidate) {
            return ['ok' => false, 'error' => 'One of those order items does not belong to this company.'];
        }
        if ((int) ($candidate['assignment_id'] ?? 0) > 0) {
            return ['ok' => false, 'error' => 'One of those items already has metal out with a kaligad.'];
        }
        $orderLines[] = $candidate;
        $lineGrossTotal += (float) $candidate['gross_weight'];
    }

    if ($orderLines !== []) {
        // Every item on one issue has to be the same metal at the same purity —
        // a single handful of gold cannot be issued as both 22K and 24K, and
        // the receipt settles wastage against one fineness.
        $first = $orderLines[0];
        foreach ($orderLines as $candidate) {
            if ((int) $candidate['purity_id'] !== (int) $first['purity_id']
                || (int) $candidate['unit_id'] !== (int) $first['unit_id']) {
                return ['ok' => false, 'error' => 'Items issued together must share one purity and one weight unit. '
                    . 'Issue the others separately.'];
            }
            if ((int) $candidate['order_id'] !== (int) $first['order_id']) {
                return ['ok' => false, 'error' => 'Items issued together must be from the same order.'];
            }
        }
        // The lines are AUTHORITATIVE for what they describe — which piece, at
        // what purity, in what unit, on which order. Those are not the issuer's
        // to retype: handing over something other than what the customer
        // ordered is the mistake this prevents. The kaligad and the WEIGHT stay
        // the issuer's call.
        $input['order_id'] = (int) $first['order_id'];
        $input['item_id'] = (int) $first['item_id'];
        $input['purity_id'] = (int) $first['purity_id'];
        $input['unit_id'] = (int) $first['unit_id'];
        if ((int) ($input['karigar_id'] ?? 0) <= 0) {
            $input['karigar_id'] = (int) ($first['karigar_id'] ?? 0);
        }
        if ((float) ($input['issued_gross_weight'] ?? 0) <= 0) {
            $input['issued_gross_weight'] = jw_round_weight($lineGrossTotal);
        }
        if (trim((string) ($input['expected_return_date'] ?? '')) === '') {
            // The soonest of the promised dates — the kaligad has to be back by
            // the first one, not the last.
            $earliest = null;
            foreach ($orderLines as $candidate) {
                $candidateDate = (string) ($candidate['delivery_date'] ?? '');
                if ($candidateDate !== '' && ($earliest === null || $candidateDate < $earliest)) {
                    $earliest = $candidateDate;
                }
            }
            $input['expected_return_date'] = $earliest ?? '';
        }
    }
    $orderLineId = (int) ($orderLines[0]['id'] ?? 0);

    $karigarId = (int) ($input['karigar_id'] ?? 0);
    $karigar = jewellery_karigar($companyId, $karigarId);
    if (!$karigar) {
        return ['ok' => false, 'error' => 'Choose a karigar that belongs to this company.'];
    }
    $itemId = (int) ($input['item_id'] ?? 0);
    $item = jewellery_item($companyId, $itemId);
    if (!$item) {
        return ['ok' => false, 'error' => 'Choose an item that belongs to this company.'];
    }
    $purityId = (int) ($input['purity_id'] ?? $item['purity_id']);
    $purity = jewellery_purity($companyId, $purityId);
    if (!$purity || (int) $purity['metal_id'] !== (int) $item['metal_id']) {
        return ['ok' => false, 'error' => 'The purity must belong to the item\'s metal.'];
    }
    $unitId = (int) ($input['unit_id'] ?? $item['unit_id']);
    if (!jewellery_unit($companyId, $unitId)) {
        return ['ok' => false, 'error' => 'The weight unit must belong to this company.'];
    }
    $gross = jw_round_weight((float) ($input['issued_gross_weight'] ?? 0));
    if ($gross <= 0) {
        return ['ok' => false, 'error' => 'Enter the weight being issued.'];
    }
    $orderId = (int) ($input['order_id'] ?? 0) ?: null;
    if ($orderId !== null && !jewellery_order($companyId, $orderId)) {
        return ['ok' => false, 'error' => 'Choose an order that belongs to this company.'];
    }

    $fine = jw_fine_weight($gross, (float) $purity['fineness']);
    $settings = jewellery_settings($companyId);
    $issueDate = (string) ($input['issue_date'] ?? date('Y-m-d'));

    // Issue at the weighted-average cost, so no gain or loss is recognised
    // merely by handing metal to a karigar. The cost pool is EVERY holder ('',
    // not 'stock'): metal sitting with a karigar is still ours, and one item
    // must carry one cost — the sale path averages over the same pool, so a
    // narrower pool here would give the same item two different costs at once.
    $balance = jw_item_balance($companyId, $itemId, $issueDate, '');
    $issuedAmount = jw_round_money($fine * $balance['avg_fine_rate']);

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        $no = trim((string) ($input['issue_no'] ?? '')) ?: jw_next_no($companyId, 'jewellery_order_assignments', 'issue_no', (string) ($settings['issue_no_prefix'] ?? 'JI'));
        db()->prepare('INSERT INTO jewellery_order_assignments (company_id, fiscal_year_id, order_id, order_line_id,
                karigar_id, issue_no,
                issue_date, expected_return_date, item_id, purity_id, unit_id, issued_gross_weight, issued_fine_weight,
                issued_amount, wastage_allowed_pct, making_basis, making_rate, notes, created_by)
            VALUES (:cid, :fy, :order, :oline, :kid, :no, :date, :expected, :item, :purity, :unit, :gross, :fine, :amount,
                :wastage, :basis, :rate, :notes, :by)')
            ->execute([
                'cid' => $companyId, 'fy' => $fiscalYearId ?: null, 'order' => $orderId,
                'oline' => $orderLineId ?: null, 'kid' => $karigarId,
                'no' => $no, 'date' => $issueDate,
                'expected' => ($input['expected_return_date'] ?? '') !== '' ? (string) $input['expected_return_date'] : null,
                'item' => $itemId, 'purity' => $purityId, 'unit' => $unitId, 'gross' => $gross, 'fine' => $fine,
                'amount' => $issuedAmount,
                // Nothing is forgiven in advance. This used to fall back to the
                // kaligad's own record, so a percentage typed there once quietly
                // wrote off metal on every issue afterwards, for work nobody had
                // seen yet. An allowance is now granted on the RECEIPT, by
                // somebody looking at what actually came back.
                'wastage' => round((float) ($input['wastage_allowed_pct'] ?? 0), 3),
                'basis' => (string) ($input['making_basis'] ?? $karigar['default_making_basis']),
                'rate' => jw_round_rate((float) ($input['making_rate'] ?? $karigar['default_making_rate'])),
                'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
                'by' => $userId ?: null,
            ]);
        $assignmentId = (int) db()->lastInsertId();

        // The money leg:
        //     Dr  Metal with <this kaligad>     the kaligad's OWN ledger
        //         Cr  <this item's> stock       the item's OWN ledger
        // Debiting a ledger dedicated to the kaligad — rather than one shared
        // "metal out" account — is what makes the trial balance show each
        // kaligad's holding, and is why the two legs can never be the same
        // ledger and cancel to an empty voucher.
        $voucherId = null;
        $karigarLedgerId = jw_karigar_metal_ledger_id($companyId, $karigar);
        $ownStockLedgerId = jw_item_stock_ledger_id($companyId, $item);
        if ($issuedAmount > 0 && $karigarLedgerId > 0 && $ownStockLedgerId > 0) {
            $entries = jw_build_entries([
                ['ledger_id' => $karigarLedgerId, 'amount' => $issuedAmount, 'memo' => 'Metal with ' . $karigar['code']],
                ['ledger_id' => $ownStockLedgerId, 'amount' => -$issuedAmount, 'memo' => 'Issued to karigar ' . $karigar['code']],
            ]);
            // Belt and braces: a null transfer is a no-op, never an exception.
            if ($entries !== []) {
                $voucherId = create_voucher_with_entries([
                    'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                    'voucher_no' => $no, 'voucher_type' => 'journal', 'voucher_date' => $issueDate,
                    'source_type' => 'jewellery_karigar_issue', 'source_id' => $assignmentId,
                    'party_id' => (int) ($karigar['party_id'] ?? 0) ?: null,
                    'narration' => 'Metal issued to karigar ' . $karigar['name'] . ' (' . $no . ')',
                    'total_amount' => $issuedAmount, 'status' => 'posted', 'posted_by' => $userId ?: null,
                ], $entries);
            }
        }

        $common = [
            'item_id' => $itemId, 'txn_type' => 'issue_karigar', 'txn_date' => $issueDate, 'ref_no' => $no,
            'purity_id' => $purityId, 'unit_id' => $unitId, 'gross_weight' => $gross, 'fine_weight' => $fine,
            'amount' => $issuedAmount, 'source_type' => 'jewellery_karigar_issue', 'source_id' => $assignmentId,
            'voucher_id' => $voucherId, 'created_by' => $userId,
        ];
        $outId = jw_record_stock_txn($companyId, $common + ['direction' => 'out', 'holder_type' => 'stock']);
        $inId = jw_record_stock_txn($companyId, $common + ['direction' => 'in', 'holder_type' => 'karigar', 'holder_id' => $karigarId]);

        // REMEMBER the ledger this issue debited. The receipt must credit THIS
        // ledger, not whatever the kaligad's name and the mappings resolve to
        // weeks later — see migration 078 for the three ways those diverge.
        // NULL is meaningful: it says the value never left the item's stock.
        db()->prepare('UPDATE jewellery_order_assignments SET issue_stock_txn_out = :o, issue_stock_txn_in = :i,
                issue_voucher_id = :v, metal_ledger_id = :ml WHERE id = :id')
            ->execute(['o' => $outId, 'i' => $inId, 'v' => $voucherId,
                'ml' => $voucherId ? $karigarLedgerId : null, 'id' => $assignmentId]);

        // Every item this issue covers now knows which issue covers it, so the
        // workshop board can say "the chain is with Ram, the ring is with
        // Shyam" rather than only "this order has metal out somewhere". Several
        // items can share one issue — a bar of gold makes three chains.
        $linkStmt = db()->prepare('UPDATE jewellery_order_lines SET assignment_id = :aid, karigar_id = :kid
            WHERE id = :id AND company_id = :cid');
        foreach ($orderLines as $coveredLine) {
            $linkStmt->execute(['aid' => $assignmentId, 'kid' => $karigarId,
                'id' => (int) $coveredLine['id'], 'cid' => $companyId]);
        }

        if ($orderId !== null) {
            jewellery_sync_order_status($companyId, $orderId);
        }

        if ($ownsTransaction) {
            db()->commit();
        }

        return ['ok' => true, 'error' => '', 'assignment_id' => $assignmentId, 'voucher_id' => (int) $voucherId];
    } catch (Throwable $issueException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $issueException->getMessage()];
    }
}

/**
 * Work out an order's status from ALL of its items, and store it.
 *
 * An order for five pieces goes to five different benches and comes back over
 * five different days. Every place that used to touch the status did so on the
 * FIRST event of its kind — the first issue made the order "assigned", the
 * first piece back made it "received" — so an order with one ring returned and
 * four bangles still at the karigar's read as fully received, and turned up on
 * the ready-to-deliver list. Somebody would have gone to fetch it, and it would
 * not have been there.
 *
 * So the status is no longer nudged from four places. It is DERIVED, here, from
 * what the items actually say, and every one of those places calls this instead:
 *
 *   received  every item is back
 *   assigned  at least one item is out for making, but not all are back
 *   confirmed nothing is out at the moment
 *
 * "delivered" and "cancelled" are decisions a person made about the whole order
 * and are left exactly alone — handing the goods over is not something the
 * workshop can undo by cancelling a receipt.
 *
 * Orders written before per-item ordering, and one-line quick orders, carry no
 * item rows at all. Those fall back to counting the issues directly, which for
 * a single-item order is the same answer the old code gave.
 *
 * @return string the status now stored against the order
 */
function jewellery_sync_order_status(int $companyId, int $orderId): string
{
    if ($orderId <= 0) {
        return '';
    }
    $stmt = db()->prepare('SELECT status FROM jewellery_orders WHERE id = :id AND company_id = :cid');
    $stmt->execute(['id' => $orderId, 'cid' => $companyId]);
    $current = (string) ($stmt->fetchColumn() ?: '');
    if ($current === '') {
        return '';
    }
    // A delivered or cancelled order is finished with. Nothing the workshop
    // does afterwards may quietly reopen it.
    if (in_array($current, ['delivered', 'cancelled'], true)) {
        return $current;
    }

    // Per item: is it out with somebody, and has it come back? A cancelled
    // issue releases its items (assignment_id is cleared), so they read as
    // not-yet-issued again, which is exactly right.
    $count = db()->prepare("SELECT COUNT(*) AS total,
            SUM(CASE WHEN a.id IS NOT NULL AND a.status <> 'cancelled' THEN 1 ELSE 0 END) AS out_now,
            SUM(CASE WHEN a.status = 'received' THEN 1 ELSE 0 END) AS back
        FROM jewellery_order_lines l
        LEFT JOIN jewellery_order_assignments a
               ON a.id = l.assignment_id AND a.company_id = l.company_id
        WHERE l.order_id = :oid AND l.company_id = :cid");
    $count->execute(['oid' => $orderId, 'cid' => $companyId]);
    $row = $count->fetch(PDO::FETCH_ASSOC) ?: [];
    $total = (int) ($row['total'] ?? 0);
    $outNow = (int) ($row['out_now'] ?? 0);
    $back = (int) ($row['back'] ?? 0);

    if ($total === 0) {
        // No item rows to go on, so count the issues themselves.
        $legacy = db()->prepare("SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) AS back
            FROM jewellery_order_assignments
            WHERE order_id = :oid AND company_id = :cid AND status <> 'cancelled'");
        $legacy->execute(['oid' => $orderId, 'cid' => $companyId]);
        $legacyRow = $legacy->fetch(PDO::FETCH_ASSOC) ?: [];
        $outNow = (int) ($legacyRow['total'] ?? 0);
        $back = (int) ($legacyRow['back'] ?? 0);
        $total = $outNow;
    }

    if ($total > 0 && $back >= $total) {
        $next = 'received';
    } elseif ($outNow > 0) {
        $next = 'assigned';
    } else {
        // Nothing is out. An order that had reached the workshop goes back to
        // confirmed; one still being written up keeps the status it has.
        $next = in_array($current, ['assigned', 'received'], true) ? 'confirmed' : $current;
    }

    if ($next !== $current) {
        db()->prepare('UPDATE jewellery_orders SET status = :s WHERE id = :id AND company_id = :cid')
            ->execute(['s' => $next, 'id' => $orderId, 'cid' => $companyId]);
    }

    return $next;
}

/** Cancel an issued assignment, pulling the metal back out of the karigar. */
function jewellery_cancel_assignment(int $companyId, int $assignmentId, int $userId = 0): array
{
    $assignment = jewellery_assignment($companyId, $assignmentId);
    if (!$assignment) {
        return ['ok' => false, 'error' => 'Assignment not found for this company.'];
    }
    if ((string) $assignment['status'] !== 'issued') {
        return ['ok' => false, 'error' => 'Only an outstanding assignment can be cancelled.'];
    }

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        $voucherId = (int) ($assignment['issue_voucher_id'] ?? 0);
        if ($voucherId > 0) {
            $vStmt = db()->prepare('SELECT * FROM vouchers WHERE id = :id AND company_id = :cid LIMIT 1');
            $vStmt->execute(['id' => $voucherId, 'cid' => $companyId]);
            $voucher = $vStmt->fetch(PDO::FETCH_ASSOC);
            if ($voucher) {
                $blocker = voucher_mutation_blocker($voucher, ['jewellery_karigar_issue']);
                if ($blocker !== null) {
                    throw new RuntimeException($blocker);
                }
            }
            db()->prepare('DELETE FROM vouchers WHERE id = :id AND company_id = :cid')->execute(['id' => $voucherId, 'cid' => $companyId]);
        }
        db()->prepare("DELETE FROM jewellery_stock_txns WHERE company_id = :cid AND source_type = 'jewellery_karigar_issue' AND source_id = :sid")
            ->execute(['cid' => $companyId, 'sid' => $assignmentId]);
        db()->prepare("UPDATE jewellery_order_assignments SET status = 'cancelled', issue_voucher_id = NULL,
                issue_stock_txn_out = NULL, issue_stock_txn_in = NULL, metal_ledger_id = NULL
            WHERE id = :id AND company_id = :cid")
            ->execute(['id' => $assignmentId, 'cid' => $companyId]);
        // Free the order item again, or it could never be re-issued: the issue
        // path refuses a line that already has metal out with a kaligad.
        db()->prepare('UPDATE jewellery_order_lines SET assignment_id = NULL
            WHERE assignment_id = :aid AND company_id = :cid')
            ->execute(['aid' => $assignmentId, 'cid' => $companyId]);
        if ((int) ($assignment['order_id'] ?? 0) > 0) {
            // Items may still be out with OTHER karigars, so the order leaves
            // the workshop only when the last issue is cancelled.
            jewellery_sync_order_status($companyId, (int) $assignment['order_id']);
        }

        if ($ownsTransaction) {
            db()->commit();
        }
        log_activity('company', $companyId, 'jewellery_assignment_cancel',
            'Karigar assignment ' . $assignment['issue_no'] . ' cancelled; the issued metal returned to own stock.', $userId);

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $cancelException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $cancelException->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// Receipt — the finished piece back, with wages and wastage settled
// ---------------------------------------------------------------------------

function jewellery_receipt(int $companyId, int $receiptId): ?array
{
    $stmt = db()->prepare('SELECT r.*, a.issue_no, a.karigar_id, a.issued_fine_weight, a.wastage_allowed_pct,
            a.making_basis, a.making_rate, k.name AS karigar_name, k.engagement_type, k.party_id
        FROM jewellery_order_receipts r
        INNER JOIN jewellery_order_assignments a ON a.id = r.assignment_id
        INNER JOIN jewellery_karigars k ON k.id = a.karigar_id
        WHERE r.id = :id AND r.company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $receiptId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Preview the settlement of a return WITHOUT writing anything — the numbers
 * the receive screen shows before the user commits.
 */
function jewellery_preview_receipt(int $companyId, int $assignmentId, float $receivedGross, ?int $receivedPurityId = null, ?float $grantedFine = null): array
{
    $assignment = jewellery_assignment($companyId, $assignmentId);
    if (!$assignment) {
        return ['ok' => false, 'error' => 'Assignment not found for this company.'];
    }
    $purityId = $receivedPurityId ?: (int) $assignment['purity_id'];
    $purity = jewellery_purity($companyId, $purityId);
    if (!$purity) {
        return ['ok' => false, 'error' => 'Unknown purity.'];
    }

    $issuedFine = (float) $assignment['issued_fine_weight'];
    $receivedFine = jw_fine_weight(jw_round_weight($receivedGross), (float) $purity['fineness']);
    // The allowance, if there is one, is what somebody granted on this receipt
    // after seeing the shortfall — not a rate agreed before the work started.
    $split = jw_wastage_split($issuedFine, $receivedFine, (float) $assignment['wastage_allowed_pct'], $grantedFine);

    // Value the metal at what it was issued at, so the wastage charge does not
    // silently move with the day's rate.
    $avgRate = $issuedFine > 0 ? jw_round_rate((float) $assignment['issued_amount'] / $issuedFine) : 0.0;
    $metalValue = jw_round_money($receivedFine * $avgRate);
    $making = jw_making_charge((string) $assignment['making_basis'], (float) $assignment['making_rate'], $receivedGross, $metalValue);
    $wastageAmount = jw_round_money($split['wastage_fine'] * $avgRate);
    $recovery = jw_round_money($split['excess_fine'] * $avgRate);
    // Metal the kaligad put in himself, valued at the rate the issue was valued
    // at so no gain or loss is invented by the day's rate. It is bought from
    // him: it joins his wages, and the shop's stock rises by the same figure.
    $surplusAmount = jw_round_money($split['surplus_fine'] * $avgRate);

    return [
        'ok' => true,
        'error' => '',
        'assignment' => $assignment,
        'issued_fine' => $issuedFine,
        'received_fine' => $receivedFine,
        'wastage_fine' => $split['wastage_fine'],
        'allowed_fine' => $split['allowed_fine'],
        'excess_fine' => $split['excess_fine'],
        'surplus_fine' => $split['surplus_fine'],
        'avg_fine_rate' => $avgRate,
        'received_value' => $metalValue,
        'making_amount' => $making,
        'wastage_amount' => $wastageAmount,
        'recovery_amount' => $recovery,
        'surplus_amount' => $surplusAmount,
        'net_payable' => jw_round_money($making - $recovery + $surplusAmount),
    ];
}

/**
 * Receive a finished piece back and settle it.
 *
 *   metal   received fine comes IN to own stock; the wastage is written OFF
 *           the karigar holding, leaving that karigar at exactly zero
 *   money   Dr making expense            wages
 *           Dr wastage loss              wastage value less recovery
 *               Cr karigar payable       wages less recovery  (flips to a
 *                                        DEBIT when recovery exceeds wages)
 *               Cr stock                 wastage value
 */
function jewellery_receive_from_karigar(int $companyId, int $fiscalYearId, array $input, int $userId = 0): array
{
    $assignmentId = (int) ($input['assignment_id'] ?? 0);
    $assignment = jewellery_assignment($companyId, $assignmentId);
    if (!$assignment) {
        return ['ok' => false, 'error' => 'Assignment not found for this company.'];
    }
    if ((string) $assignment['status'] !== 'issued') {
        return ['ok' => false, 'error' => 'This assignment has already been received or cancelled.'];
    }

    $receivedItemId = (int) ($input['received_item_id'] ?? $assignment['item_id']);
    $receivedItem = jewellery_item($companyId, $receivedItemId);
    if (!$receivedItem) {
        return ['ok' => false, 'error' => 'Choose a received item that belongs to this company.'];
    }
    $receivedPurityId = (int) ($input['received_purity_id'] ?? $assignment['purity_id']);
    $receivedPurity = jewellery_purity($companyId, $receivedPurityId);
    if (!$receivedPurity || (int) $receivedPurity['metal_id'] !== (int) $receivedItem['metal_id']) {
        return ['ok' => false, 'error' => 'The received purity must belong to the received item\'s metal.'];
    }
    $receivedGross = jw_round_weight((float) ($input['received_gross_weight'] ?? 0));
    if ($receivedGross <= 0) {
        return ['ok' => false, 'error' => 'Enter the weight received back.'];
    }

    // Wastage the shop has decided to let go on THIS return, in fine weight,
    // after seeing what came back. Absent means none — which is the rule, not a
    // default someone forgot to change.
    $grantedFine = null;
    if (array_key_exists('allow_wastage_fine', $input) && trim((string) $input['allow_wastage_fine']) !== '') {
        $grantedFine = jw_round_weight((float) $input['allow_wastage_fine']);
        if ($grantedFine < 0) {
            return ['ok' => false, 'error' => 'A wastage allowance cannot be negative.'];
        }
    }

    $preview = jewellery_preview_receipt($companyId, $assignmentId, $receivedGross, $receivedPurityId, $grantedFine);
    if (!$preview['ok']) {
        return $preview;
    }
    // More fine metal coming back than went out used to be refused outright,
    // with the advice to record the extra as a separate purchase. It IS a
    // purchase — a kaligad short of metal tops up from his own — so the receipt
    // records it as one instead of sending the shop away to do it by hand. The
    // surplus is bought at the rate the issue was valued at and added to his
    // wages; see the money legs below.

    $settings = jewellery_settings($companyId);
    $receiveDate = (string) ($input['receive_date'] ?? date('Y-m-d'));
    $karigar = jewellery_karigar($companyId, (int) $assignment['karigar_id']);

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        $no = trim((string) ($input['receipt_no'] ?? '')) ?: jw_next_no($companyId, 'jewellery_order_receipts', 'receipt_no', 'JRC');
        db()->prepare('INSERT INTO jewellery_order_receipts (company_id, fiscal_year_id, assignment_id, receipt_no, receive_date,
                received_item_id, received_purity_id, unit_id, qty_pieces, received_gross_weight, received_fine_weight,
                wastage_fine_weight, wastage_allowed_fine, excess_wastage_fine, avg_fine_rate, wastage_amount,
                recovery_amount, making_amount, net_payable, notes, created_by)
            VALUES (:cid, :fy, :aid, :no, :date, :item, :purity, :unit, :pieces, :gross, :fine, :wfine, :afine, :efine,
                :rate, :wamount, :recovery, :making, :net, :notes, :by)')
            ->execute([
                'cid' => $companyId, 'fy' => $fiscalYearId ?: null, 'aid' => $assignmentId, 'no' => $no,
                'date' => $receiveDate, 'item' => $receivedItemId, 'purity' => $receivedPurityId,
                'unit' => (int) $assignment['unit_id'],
                'pieces' => round((float) ($input['qty_pieces'] ?? 0), 3),
                'gross' => $receivedGross, 'fine' => $preview['received_fine'],
                'wfine' => $preview['wastage_fine'], 'afine' => $preview['allowed_fine'], 'efine' => $preview['excess_fine'],
                'rate' => $preview['avg_fine_rate'], 'wamount' => $preview['wastage_amount'],
                'recovery' => $preview['recovery_amount'], 'making' => $preview['making_amount'],
                'net' => $preview['net_payable'],
                'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
                'by' => $userId ?: null,
            ]);
        $receiptId = (int) db()->lastInsertId();

        // --- money leg -----------------------------------------------------
        $legs = [];
        $making = $preview['making_amount'];
        $recovery = $preview['recovery_amount'];
        $wastageAmount = $preview['wastage_amount'];
        $netPayable = $preview['net_payable'];

        if ($making > 0) {
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, 'making_expense'), 'amount' => $making, 'memo' => 'Making charge ' . $no];
        }

        // THE FULL ISSUED VALUE MUST COME BACK. The issue debited "metal with
        // karigar" with the whole issued cost; crediting only the wastage here
        // would strand the rest in that ledger forever while the metal register
        // shows the karigar holding nothing. So: clear the source ledger for
        // the entire issued amount, and land the difference back in own stock.
        //
        // When the issue posted no voucher — stock_karigar was unmapped at the
        // time — the value never left own stock. The same three legs still
        // hold: source and destination are then the same ledger and
        // jw_build_entries nets them down to just the wastage write-off.
        // Stock rises by what the issue was worth, less anything lost as
        // wastage and PLUS anything the kaligad added out of his own metal.
        // That surplus is credited to him below as part of his wages, so the
        // two sides move together and the voucher balances either way round.
        $issuedAmount = jw_round_money((float) $assignment['issued_amount']);
        $surplusAmount = (float) ($preview['surplus_amount'] ?? 0);
        $returnedValue = jw_round_money($issuedAmount - $wastageAmount + $surplusAmount);
        // Credit the LEDGER THE ISSUE ACTUALLY DEBITED, recorded on the
        // assignment at issue time. Re-deriving it here from the kaligad's
        // current name and current mappings is what created stranded debits
        // and credit-balance asset ledgers — see migration 078. Only fall back
        // to deriving it for assignments issued before 078 whose backfill found
        // no voucher to read.
        $storedLedgerId = (int) ($assignment['metal_ledger_id'] ?? 0);
        $karigarLedgerId = $storedLedgerId > 0
            ? $storedLedgerId
            : ((int) ($assignment['issue_voucher_id'] ?? 0) > 0 ? jw_karigar_metal_ledger_id($companyId, $karigar) : 0);
        $sourceLedgerId = $karigarLedgerId > 0
            ? $karigarLedgerId
            : jw_item_stock_ledger_id($companyId, jewellery_item($companyId, (int) $assignment['item_id']));
        $destLedgerId = jw_item_stock_ledger_id($companyId, $receivedItem);
        if ($sourceLedgerId <= 0 || $destLedgerId <= 0) {
            throw new RuntimeException('No stock ledger is mapped for the issued or received item. Set it under Jewellery → Settings → Posting Ledgers.');
        }
        if ($returnedValue > 0) {
            $legs[] = ['ledger_id' => $destLedgerId, 'amount' => $returnedValue, 'memo' => 'Finished piece back in stock ' . $no];
        }
        if ($issuedAmount > 0) {
            $legs[] = ['ledger_id' => $sourceLedgerId, 'amount' => -$issuedAmount, 'memo' => 'Metal returned from karigar ' . $no];
        }
        if ($wastageAmount > 0) {
            // The wastage is a real loss, less whatever is recovered from wages.
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, 'wastage_loss'), 'amount' => jw_round_money($wastageAmount - $recovery), 'memo' => 'Wastage ' . $no];
        }
        // A contractor's wages become a bill-wise payable; an employee's flow
        // through payroll, so they accrue to the generic payable instead.
        if (abs($netPayable) >= 0.005) {
            $wagesLedgerId = ((string) $karigar['engagement_type'] === 'contractor' && (int) ($karigar['party_id'] ?? 0) > 0)
                ? jw_party_ledger($companyId, (int) $karigar['party_id'], 'payable')
                : jw_require_ledger($companyId, 'karigar_payable');
            $legs[] = ['ledger_id' => $wagesLedgerId, 'amount' => -$netPayable, 'memo' => 'Karigar wages ' . $no];
        }

        $voucherId = null;
        $entries = jw_build_entries($legs);
        if ($entries !== []) {
            $voucherId = create_voucher_with_entries([
                'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                'voucher_no' => $no, 'voucher_type' => 'journal', 'voucher_date' => $receiveDate,
                'source_type' => 'jewellery_order_receipt', 'source_id' => $receiptId,
                'party_id' => (int) ($karigar['party_id'] ?? 0) ?: null,
                'narration' => 'Received from karigar ' . $karigar['name'] . ' (' . $no . ')',
                // The header total should read like the voucher: the debit side.
                'total_amount' => jw_round_money($returnedValue + max(0.0, $wastageAmount - $recovery) + $making),
                'status' => 'posted', 'posted_by' => $userId ?: null,
            ], $entries);
        }

        // --- metal leg -----------------------------------------------------
        // Everything issued leaves the karigar: what came back plus what was lost.
        jw_record_stock_txn($companyId, [
            'item_id' => (int) $assignment['item_id'], 'txn_type' => 'receive_karigar', 'direction' => 'out',
            'txn_date' => $receiveDate, 'ref_no' => $no, 'holder_type' => 'karigar', 'holder_id' => (int) $assignment['karigar_id'],
            'purity_id' => (int) $assignment['purity_id'], 'unit_id' => (int) $assignment['unit_id'],
            'gross_weight' => (float) $assignment['issued_gross_weight'], 'fine_weight' => $preview['issued_fine'],
            'amount' => (float) $assignment['issued_amount'],
            'source_type' => 'jewellery_order_receipt', 'source_id' => $receiptId, 'voucher_id' => $voucherId,
            'notes' => 'Karigar holding cleared', 'created_by' => $userId,
        ]);
        // The finished piece arrives in own stock at issued cost less wastage.
        jw_record_stock_txn($companyId, [
            'item_id' => $receivedItemId, 'txn_type' => 'receive_karigar', 'direction' => 'in',
            'txn_date' => $receiveDate, 'ref_no' => $no, 'holder_type' => 'stock',
            'purity_id' => $receivedPurityId, 'unit_id' => (int) $assignment['unit_id'],
            'qty_pieces' => round((float) ($input['qty_pieces'] ?? 0), 3),
            'gross_weight' => $receivedGross, 'fine_weight' => $preview['received_fine'],
            'rate' => $preview['avg_fine_rate'],
            'amount' => $returnedValue,
            'source_type' => 'jewellery_order_receipt', 'source_id' => $receiptId, 'voucher_id' => $voucherId,
            'created_by' => $userId,
        ]);

        // A contractor's wages open a bill so they can be paid bill by bill.
        if ((string) $karigar['engagement_type'] === 'contractor' && (int) ($karigar['party_id'] ?? 0) > 0 && $netPayable > 0.005) {
            jw_open_bill($companyId, [
                'fiscal_year_id' => $fiscalYearId, 'party_id' => (int) $karigar['party_id'], 'bill_type' => 'karigar',
                'source_type' => 'jewellery_order_receipt', 'source_id' => $receiptId,
                'bill_no' => $no, 'bill_date' => $receiveDate, 'bill_amount' => $netPayable, 'voucher_id' => $voucherId,
            ]);
        }

        db()->prepare("UPDATE jewellery_order_receipts SET status = 'posted', voucher_id = :v, posted_by = :by, posted_at = NOW()
            WHERE id = :id AND company_id = :cid")
            ->execute(['v' => $voucherId, 'by' => $userId ?: null, 'id' => $receiptId, 'cid' => $companyId]);
        db()->prepare("UPDATE jewellery_order_assignments SET status = 'received' WHERE id = :id AND company_id = :cid")
            ->execute(['id' => $assignmentId, 'cid' => $companyId]);
        if ((int) ($assignment['order_id'] ?? 0) > 0) {
            // Only the LAST piece coming back makes the order ready to hand over.
            jewellery_sync_order_status($companyId, (int) $assignment['order_id']);
        }

        if ($ownsTransaction) {
            db()->commit();
        }

        return ['ok' => true, 'error' => '', 'receipt_id' => $receiptId, 'voucher_id' => (int) $voucherId] + $preview;
    } catch (Throwable $receiveException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $receiveException->getMessage()];
    }
}

/**
 * Orders finished by the karigar but still sitting in the shop — the
 * "received but not delivered" board.
 */
function jewellery_pending_delivery(int $companyId): array
{
    $stmt = db()->prepare("SELECT o.*, ap.name AS party_name, p.code AS purity_code, u.code AS unit_code,
            r.receive_date, r.received_gross_weight, r.received_fine_weight, r.receipt_no,
            DATEDIFF(CURDATE(), r.receive_date) AS days_waiting
        FROM jewellery_orders o
        LEFT JOIN accounting_parties ap ON ap.id = o.party_id
        INNER JOIN jewellery_purities p ON p.id = o.purity_id
        INNER JOIN jewellery_units u ON u.id = o.unit_id
        LEFT JOIN jewellery_order_assignments a ON a.order_id = o.id AND a.status = 'received'
        LEFT JOIN jewellery_order_receipts r ON r.assignment_id = a.id
        WHERE o.company_id = :cid AND o.status = 'received'
        ORDER BY r.receive_date ASC, o.id ASC");
    $stmt->execute(['cid' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * A customer's orders that have not been delivered yet, ready to be sold.
 *
 * Shown when the customer is picked on a sale, because the person at the
 * counter collecting their ring should not have to be asked which order it was.
 */
function jewellery_open_orders_for_party(int $companyId, int $partyId): array
{
    if ($partyId <= 0) {
        return [];
    }
    $stmt = db()->prepare("SELECT o.*, i.sku AS item_code, i.name AS item_name,
            p.code AS purity_code, p.fineness, u.code AS unit_code, m.code AS metal_code
        FROM jewellery_orders o
        LEFT JOIN inventory_items i ON i.id = o.item_id
        INNER JOIN jewellery_purities p ON p.id = o.purity_id
        INNER JOIN jewellery_units u ON u.id = o.unit_id
        INNER JOIN jewellery_metals m ON m.id = o.metal_id
        WHERE o.company_id = :cid AND o.party_id = :pid
          AND o.status IN ('confirmed', 'assigned', 'received')
        ORDER BY o.status = 'received' DESC, o.order_date ASC, o.id ASC");
    $stmt->execute(['cid' => $companyId, 'pid' => $partyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The sale line an order becomes, priced AT THE ORDER DATE.
 *
 * A customer who ordered a chain in Shrawan agreed the Shrawan rate. Gold has
 * moved since; billing them at today's board would be repricing a deal that was
 * already struck. So the rate comes from the board as it stood on the order
 * date, and the actual weight received from the kaligad — not the estimate —
 * is what gets billed.
 *
 * The rate is returned as money per unit of GROSS weight, which is what a sale
 * line takes, so the sale prices identically whether it came from an order or
 * was keyed by hand.
 */
function jewellery_order_sale_prefill(int $companyId, int $orderId): array
{
    $order = jewellery_order($companyId, $orderId);
    if (!$order) {
        return ['ok' => false, 'error' => 'Order not found for this company.'];
    }

    // Bill what actually came back from the kaligad; fall back to the estimate
    // when nothing has been received yet.
    $receipt = db()->prepare("SELECT r.* FROM jewellery_order_receipts r
        INNER JOIN jewellery_order_assignments a ON a.id = r.assignment_id
        WHERE a.order_id = :oid AND r.company_id = :cid AND r.status = 'posted'
        ORDER BY r.id DESC LIMIT 1");
    $receipt->execute(['oid' => $orderId, 'cid' => $companyId]);
    $received = $receipt->fetch(PDO::FETCH_ASSOC) ?: null;

    $gross = jw_round_weight((float) ($received['received_gross_weight'] ?? $order['expected_gross_weight']));
    $itemId = (int) ($received['received_item_id'] ?? $order['item_id']);
    $purityId = (int) ($received['received_purity_id'] ?? $order['purity_id']);

    $orderDate = (string) $order['order_date'];
    $rate = 0.0;
    $rateNote = '';
    if ($gross > 0) {
        $valued = jewellery_metal_value($companyId, (int) $order['metal_id'], $purityId,
            $gross, (int) $order['unit_id'], $orderDate, 'sale');
        if ($valued['ok']) {
            $rate = jw_round_rate($valued['amount'] / $gross);
            $rateNote = 'Metal priced at the rate board of ' . $orderDate . ', the day the order was taken. '
                . 'Taxes are charged at the rates in force on the SALE date — a statutory rate follows the day of supply, '
                . 'not the day the order was agreed.';
        } else {
            $rateNote = 'No rate was quoted on or before ' . $orderDate . ', so the line needs a rate typed in.';
        }
    }

    // The making charge the order was agreed at, applied to the weight actually
    // delivered.
    $making = jw_making_charge((string) $order['making_basis'], (float) $order['making_rate'],
        $gross, jw_round_money($gross * $rate));

    // Every item the customer ordered, priced as the order priced it. A shop
    // that took an order for a ring AND a chain must get both back on the bill;
    // returning only the first would quietly drop what it agreed to sell.
    $orderLines = jewellery_order_line_rows($companyId, $orderId);
    $lines = [];
    foreach ($orderLines as $index => $orderLine) {
        // The first line is the one the karigar worked to, so it takes the
        // weight and rate actually received. The rest stand as ordered.
        $isFirst = $index === 0;
        $lineGross = $isFirst && $received !== null ? $gross : jw_round_weight((float) $orderLine['gross_weight']);
        $lineRate = (float) $orderLine['rate'];
        if ($isFirst && $rate > 0) {
            $lineRate = $rate;
        }
        $lines[] = [
            'item_id' => $isFirst ? $itemId : (int) $orderLine['item_id'],
            'purity_id' => $isFirst ? $purityId : (int) $orderLine['purity_id'],
            'unit_id' => (int) $orderLine['unit_id'],
            'qty_pieces' => (float) $orderLine['qty_pieces'] ?: 1,
            'gross_weight' => $lineGross,
            'stone_weight' => (float) $orderLine['stone_weight'],
            'wastage_pct' => (float) $orderLine['wastage_pct'],
            'wastage_weight' => $isFirst ? 0.0 : (float) $orderLine['wastage_weight'],
            'rate' => $lineRate,
            'making_amount' => $isFirst ? $making : (float) $orderLine['making_amount'],
            'stone_amount' => (float) $orderLine['stone_amount'],
            'stone_carat' => (float) $orderLine['stone_carat'],
            'diamond_amount' => (float) $orderLine['diamond_amount'],
            'diamond_carat' => (float) $orderLine['diamond_carat'],
            'other_diamond_amount' => (float) $orderLine['other_diamond_amount'],
            'other_diamond_carat' => (float) $orderLine['other_diamond_carat'],
            'notes' => (string) ($orderLine['notes'] ?? ''),
        ];
    }
    if ($lines === []) {
        // An order taken before orders had lines. Fall back to what the header
        // knows, so the bill can still be raised from it.
        $lines[] = [
            'item_id' => $itemId,
            'purity_id' => $purityId,
            'unit_id' => (int) $order['unit_id'],
            'qty_pieces' => 1,
            'gross_weight' => $gross,
            'rate' => $rate,
            'making_amount' => $making,
        ];
    }

    return [
        'ok' => true,
        'error' => '',
        'order' => $order,
        'received' => $received,
        'rate_note' => $rateNote,
        'advance_amount' => jw_round_money((float) $order['advance_amount']),
        'order_total' => jw_round_money((float) ($order['total_amount'] ?? 0)),
        'lines' => $lines,
        // Kept so callers written against the single-item order keep working.
        'line' => $lines[0],
    ];
}

/** Mark a received order delivered, optionally tying it to the sale raised. */
function jewellery_deliver_order(int $companyId, int $orderId, int $saleId = 0, int $userId = 0): array
{
    $order = jewellery_order($companyId, $orderId);
    if (!$order) {
        return ['ok' => false, 'error' => 'Order not found for this company.'];
    }
    // Not every order goes out to a kaligad. A piece made in-house, or simply
    // picked off the shelf against an order, is delivered without ever having
    // been "received" from anyone — and the bill raised for it is the evidence
    // that it changed hands. What is refused is delivering twice, or delivering
    // something that was cancelled.
    if (in_array((string) $order['status'], ['delivered', 'cancelled'], true)) {
        return ['ok' => false, 'error' => 'This order is already ' . $order['status'] . '.'];
    }
    if ($saleId > 0 && !jewellery_sale($companyId, $saleId)) {
        return ['ok' => false, 'error' => 'That sale does not belong to this company.'];
    }

    db()->prepare("UPDATE jewellery_orders SET status = 'delivered', delivered_sale_id = :sale, delivered_at = NOW()
        WHERE id = :id AND company_id = :cid")
        ->execute(['sale' => $saleId ?: null, 'id' => $orderId, 'cid' => $companyId]);
    log_activity('company', $companyId, 'jewellery_order_delivered',
        'Order ' . $order['order_no'] . ' delivered to the customer.', $userId);

    return ['ok' => true, 'error' => ''];
}

// ---------------------------------------------------------------------------
// Refinery
// ---------------------------------------------------------------------------

function jewellery_refinery_job(int $companyId, int $jobId): ?array
{
    $stmt = db()->prepare('SELECT j.*, ap.name AS party_name, i.sku AS item_code, i.name AS item_name,
            p.code AS purity_code, p.fineness, u.code AS unit_code
        FROM jewellery_refinery_jobs j
        LEFT JOIN accounting_parties ap ON ap.id = j.party_id
        INNER JOIN inventory_items i ON i.id = j.item_id
            INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        INNER JOIN jewellery_purities p ON p.id = j.purity_id
        INNER JOIN jewellery_units u ON u.id = j.unit_id
        WHERE j.id = :id AND j.company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $jobId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function jewellery_refinery_jobs_list(int $companyId, array $filters = []): array
{
    $sql = 'SELECT j.*, ap.name AS party_name, i.sku AS item_code, p.code AS purity_code, u.code AS unit_code
        FROM jewellery_refinery_jobs j
        LEFT JOIN accounting_parties ap ON ap.id = j.party_id
        INNER JOIN inventory_items i ON i.id = j.item_id
            INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        INNER JOIN jewellery_purities p ON p.id = j.purity_id
        INNER JOIN jewellery_units u ON u.id = j.unit_id
        WHERE j.company_id = :cid';
    $params = ['cid' => $companyId];
    if (($filters['status'] ?? '') !== '') {
        $sql .= ' AND j.status = :st';
        $params['st'] = (string) $filters['status'];
    }
    $sql .= ' ORDER BY j.issue_date DESC, j.id DESC LIMIT ' . max(1, min(1000, (int) ($filters['limit'] ?? 300)));

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Send impure or old metal out to a refiner. */
function jewellery_issue_to_refinery(int $companyId, int $fiscalYearId, array $input, int $userId = 0): array
{
    $itemId = (int) ($input['item_id'] ?? 0);
    $item = jewellery_item($companyId, $itemId);
    if (!$item) {
        return ['ok' => false, 'error' => 'Choose an item that belongs to this company.'];
    }
    $purityId = (int) ($input['purity_id'] ?? $item['purity_id']);
    $purity = jewellery_purity($companyId, $purityId);
    if (!$purity || (int) $purity['metal_id'] !== (int) $item['metal_id']) {
        return ['ok' => false, 'error' => 'The purity must belong to the item\'s metal.'];
    }
    $unitId = (int) ($input['unit_id'] ?? $item['unit_id']);
    if (!jewellery_unit($companyId, $unitId)) {
        return ['ok' => false, 'error' => 'The weight unit must belong to this company.'];
    }
    $gross = jw_round_weight((float) ($input['issued_gross_weight'] ?? 0));
    if ($gross <= 0) {
        return ['ok' => false, 'error' => 'Enter the weight being sent for refining.'];
    }
    $partyId = (int) ($input['party_id'] ?? 0) ?: null;
    if ($partyId !== null) {
        $check = db()->prepare('SELECT COUNT(*) FROM accounting_parties WHERE id = :id AND company_id = :cid');
        $check->execute(['id' => $partyId, 'cid' => $companyId]);
        if ((int) $check->fetchColumn() === 0) {
            return ['ok' => false, 'error' => 'Choose a refiner that belongs to this company.'];
        }
    }

    $fine = jw_fine_weight($gross, (float) $purity['fineness']);
    $settings = jewellery_settings($companyId);
    $issueDate = (string) ($input['issue_date'] ?? date('Y-m-d'));
    // Same all-holder cost pool as the karigar issue and the sale path.
    $balance = jw_item_balance($companyId, $itemId, $issueDate, '');
    $issuedAmount = jw_round_money($fine * $balance['avg_fine_rate']);

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        $no = trim((string) ($input['job_no'] ?? '')) ?: jw_next_no($companyId, 'jewellery_refinery_jobs', 'job_no', (string) ($settings['refinery_no_prefix'] ?? 'JR'));
        db()->prepare('INSERT INTO jewellery_refinery_jobs (company_id, fiscal_year_id, job_no, party_id, issue_date,
                item_id, purity_id, unit_id, issued_gross_weight, issued_fine_weight, issued_amount, notes, created_by)
            VALUES (:cid, :fy, :no, :party, :date, :item, :purity, :unit, :gross, :fine, :amount, :notes, :by)')
            ->execute([
                'cid' => $companyId, 'fy' => $fiscalYearId ?: null, 'no' => $no, 'party' => $partyId, 'date' => $issueDate,
                'item' => $itemId, 'purity' => $purityId, 'unit' => $unitId, 'gross' => $gross, 'fine' => $fine,
                'amount' => $issuedAmount, 'notes' => trim((string) ($input['notes'] ?? '')) ?: null, 'by' => $userId ?: null,
            ]);
        $jobId = (int) db()->lastInsertId();

        // Same rule as the kaligad issue: debit the ledger belonging to THIS
        // refiner, credit the item's own stock ledger.
        $voucherId = null;
        $refineryLedgerId = jw_refiner_metal_ledger_id($companyId, (int) $partyId);
        $ownStockLedgerId = jw_item_stock_ledger_id($companyId, $item);
        if ($issuedAmount > 0 && $refineryLedgerId > 0 && $ownStockLedgerId > 0) {
            $entries = jw_build_entries([
                ['ledger_id' => $refineryLedgerId, 'amount' => $issuedAmount, 'memo' => 'Metal with refinery ' . $no],
                ['ledger_id' => $ownStockLedgerId, 'amount' => -$issuedAmount, 'memo' => 'Issued for refining ' . $no],
            ]);
            if ($entries !== []) {
                $voucherId = create_voucher_with_entries([
                    'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                    'voucher_no' => $no, 'voucher_type' => 'journal', 'voucher_date' => $issueDate,
                    'source_type' => 'jewellery_refinery_issue', 'source_id' => $jobId,
                    'party_id' => $partyId,
                    'narration' => 'Metal issued for refining (' . $no . ')',
                    'total_amount' => $issuedAmount, 'status' => 'posted', 'posted_by' => $userId ?: null,
                ], $entries);
            }
        }

        $common = [
            'item_id' => $itemId, 'txn_type' => 'issue_refinery', 'txn_date' => $issueDate, 'ref_no' => $no,
            'purity_id' => $purityId, 'unit_id' => $unitId, 'gross_weight' => $gross, 'fine_weight' => $fine,
            'amount' => $issuedAmount, 'source_type' => 'jewellery_refinery_issue', 'source_id' => $jobId,
            'voucher_id' => $voucherId, 'party_id' => $partyId, 'created_by' => $userId,
        ];
        $outId = jw_record_stock_txn($companyId, $common + ['direction' => 'out', 'holder_type' => 'stock']);
        $inId = jw_record_stock_txn($companyId, $common + ['direction' => 'in', 'holder_type' => 'refinery', 'holder_id' => $partyId]);

        // As for the kaligad: remember the ledger, do not re-derive it. A refiner
        // ledger is keyed off the party NAME, so an ordinary rename between
        // issue and receive would otherwise create a second ledger and strand
        // the first one's debit forever.
        db()->prepare('UPDATE jewellery_refinery_jobs SET issue_stock_txn_out = :o, issue_stock_txn_in = :i,
                issue_voucher_id = :v, metal_ledger_id = :ml WHERE id = :id')
            ->execute(['o' => $outId, 'i' => $inId, 'v' => $voucherId,
                'ml' => $voucherId ? $refineryLedgerId : null, 'id' => $jobId]);

        if ($ownsTransaction) {
            db()->commit();
        }

        return ['ok' => true, 'error' => '', 'job_id' => $jobId, 'voucher_id' => (int) $voucherId];
    } catch (Throwable $issueException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $issueException->getMessage()];
    }
}

/**
 * Take refined metal back.
 *
 *   Dr  stock (refined item)   issued cost, less the refining loss,
 *                              plus any metal the refiner added of his own
 *   Dr  refining loss          value of the fine metal that did not come back
 *   Dr  refinery charges       the refiner's fee
 *       Cr  stock (with refinery)
 *       Cr  party / cash-bank  the fee, and the metal he supplied
 *
 * Loss and surplus are mutually exclusive, so only one of those two ever
 * carries a figure on a given job.
 */
function jewellery_receive_from_refinery(int $companyId, int $fiscalYearId, array $input, int $userId = 0): array
{
    $jobId = (int) ($input['job_id'] ?? 0);
    $job = jewellery_refinery_job($companyId, $jobId);
    if (!$job) {
        return ['ok' => false, 'error' => 'Refinery job not found for this company.'];
    }
    if ((string) $job['status'] !== 'issued') {
        return ['ok' => false, 'error' => 'This job has already been received or cancelled.'];
    }

    $receivedItemId = (int) ($input['received_item_id'] ?? $job['item_id']);
    $receivedItem = jewellery_item($companyId, $receivedItemId);
    if (!$receivedItem) {
        return ['ok' => false, 'error' => 'Choose a received item that belongs to this company.'];
    }
    $receivedPurityId = (int) ($input['received_purity_id'] ?? $job['purity_id']);
    $receivedPurity = jewellery_purity($companyId, $receivedPurityId);
    if (!$receivedPurity || (int) $receivedPurity['metal_id'] !== (int) $receivedItem['metal_id']) {
        return ['ok' => false, 'error' => 'The received purity must belong to the received item\'s metal.'];
    }
    $receivedGross = jw_round_weight((float) ($input['received_gross_weight'] ?? 0));
    if ($receivedGross <= 0) {
        return ['ok' => false, 'error' => 'Enter the weight received back.'];
    }

    $issuedFine = (float) $job['issued_fine_weight'];
    $receivedFine = jw_fine_weight($receivedGross, (float) $receivedPurity['fineness']);
    // A furnace cannot make gold, so fine weight normally comes back lower than
    // it went out. More coming back means the refiner put some of his own in —
    // usually to settle on a round bar rather than an awkward fraction. That
    // used to be refused, with the advice to "record the extra as a separate
    // purchase". It IS a purchase, so the receipt records it as one instead of
    // sending the shop away to key it in by hand.
    //
    // Loss and surplus are mutually exclusive — one of the two is always zero —
    // so nothing downstream has to choose between them.
    $lossFine = jw_round_weight(max(0.0, $issuedFine - $receivedFine));
    $surplusFine = jw_round_weight(max(0.0, $receivedFine - $issuedFine));
    $avgRate = $issuedFine > 0 ? jw_round_rate((float) $job['issued_amount'] / $issuedFine) : 0.0;
    $lossAmount = jw_round_money($lossFine * $avgRate);
    // Bought at what the issue was valued at. Using today's market rate instead
    // would book a profit or loss on metal that only passed through a furnace.
    $surplusAmount = jw_round_money($surplusFine * $avgRate);
    $charges = jw_round_money((float) ($input['charges_amount'] ?? 0));
    if ($charges < 0) {
        return ['ok' => false, 'error' => 'Refinery charges cannot be negative.'];
    }

    $chargesMode = jw_enum($input['charges_settle_mode'] ?? null, ['credit', 'cash', 'bank'], 'credit');
    $chargesLedgerId = (int) ($input['charges_ledger_id'] ?? 0) ?: null;
    // Metal the refiner supplied is settled the same way his fee is, so the
    // check covers both: something is owed, and it needs somewhere to go.
    if ($charges > 0 || $surplusAmount > 0) {
        if ($chargesMode === 'credit' && (int) ($job['party_id'] ?? 0) <= 0) {
            return ['ok' => false, 'error' => $surplusAmount > 0 && $charges <= 0
                ? 'The refiner supplied metal of his own, so the job needs a refiner party to owe it to.'
                : 'Charges on credit need a refiner party on the job.'];
        }
        if ($chargesMode !== 'credit') {
            if ($chargesLedgerId === null) {
                return ['ok' => false, 'error' => 'Choose the cash or bank ledger the refinery charges are paid from.'];
            }
            $check = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :id AND company_id = :cid');
            $check->execute(['id' => $chargesLedgerId, 'cid' => $companyId]);
            if ((int) $check->fetchColumn() === 0) {
                return ['ok' => false, 'error' => 'That ledger does not belong to this company.'];
            }
        }
    }

    $receiveDate = (string) ($input['receive_date'] ?? date('Y-m-d'));

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        $legs = [];
        // Credit the ledger THE ISSUE RECORDED, so this refiner's metal account
        // closes back to nil on a completed job even if the party has since
        // been renamed or deleted (migration 078). NULL means the issue posted
        // no money leg, so the value is still in the item's own stock ledger.
        $storedLedgerId = (int) ($job['metal_ledger_id'] ?? 0);
        $refineryLedgerId = $storedLedgerId > 0
            ? $storedLedgerId
            : ((int) ($job['issue_voucher_id'] ?? 0) > 0 ? jw_refiner_metal_ledger_id($companyId, (int) ($job['party_id'] ?? 0)) : 0);
        $sourceLedgerId = $refineryLedgerId > 0
            ? $refineryLedgerId
            : jw_item_stock_ledger_id($companyId, jewellery_item($companyId, (int) $job['item_id']));
        $destLedgerId = jw_item_stock_ledger_id($companyId, $receivedItem);
        if ($sourceLedgerId <= 0 || $destLedgerId <= 0) {
            throw new RuntimeException('No stock ledger is mapped for the refined item.');
        }

        // What lands in stock is what went out, less anything the furnace ate,
        // PLUS anything the refiner added from his own metal. That surplus is
        // credited to him below, so the two sides move together and the voucher
        // balances whichever way the weight went.
        $returnedValue = jw_round_money((float) $job['issued_amount'] - $lossAmount + $surplusAmount);
        if ($returnedValue > 0) {
            $legs[] = ['ledger_id' => $destLedgerId, 'amount' => $returnedValue, 'memo' => 'Refined metal in ' . $job['job_no']];
        }
        if ($lossAmount > 0) {
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, 'refinery_loss'), 'amount' => $lossAmount, 'memo' => 'Refining loss ' . $job['job_no']];
        }
        if ((float) $job['issued_amount'] > 0) {
            $legs[] = ['ledger_id' => $sourceLedgerId, 'amount' => -(float) $job['issued_amount'], 'memo' => 'Metal returned from refinery ' . $job['job_no']];
        }
        // The refiner is settled once, for his fee and for any metal of his own
        // that came back in the bar — both down the route the job chose.
        $creditLedgerId = $chargesMode === 'credit'
            ? jw_party_ledger($companyId, (int) ($job['party_id'] ?? 0), 'payable')
            : (int) $chargesLedgerId;
        if ($charges > 0) {
            $legs[] = ['ledger_id' => jw_require_ledger($companyId, 'refinery_charges'), 'amount' => $charges, 'memo' => 'Refinery charges ' . $job['job_no']];
            $legs[] = ['ledger_id' => $creditLedgerId, 'amount' => -$charges, 'memo' => 'Refinery charges ' . $job['job_no']];
        }
        if ($surplusAmount > 0) {
            $legs[] = ['ledger_id' => $creditLedgerId, 'amount' => -$surplusAmount,
                'memo' => 'Metal supplied by refiner ' . $job['job_no']];
        }

        $voucherId = null;
        $entries = jw_build_entries($legs);
        if ($entries !== []) {
            $voucherId = create_voucher_with_entries([
                'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                'voucher_no' => $job['job_no'] . '-R', 'voucher_type' => 'journal', 'voucher_date' => $receiveDate,
                'source_type' => 'jewellery_refinery_receive', 'source_id' => $jobId,
                'party_id' => (int) ($job['party_id'] ?? 0) ?: null,
                'narration' => 'Refined metal received (' . $job['job_no'] . ')',
                'total_amount' => jw_round_money($returnedValue + $lossAmount + $charges),
                'status' => 'posted', 'posted_by' => $userId ?: null,
            ], $entries);
        }

        // Everything issued leaves the refinery holding; the refined metal lands.
        jw_record_stock_txn($companyId, [
            'item_id' => (int) $job['item_id'], 'txn_type' => 'receive_refinery', 'direction' => 'out',
            'txn_date' => $receiveDate, 'ref_no' => (string) $job['job_no'], 'holder_type' => 'refinery',
            'holder_id' => (int) ($job['party_id'] ?? 0) ?: null,
            'purity_id' => (int) $job['purity_id'], 'unit_id' => (int) $job['unit_id'],
            'gross_weight' => (float) $job['issued_gross_weight'], 'fine_weight' => $issuedFine,
            'amount' => (float) $job['issued_amount'],
            'source_type' => 'jewellery_refinery_receive', 'source_id' => $jobId, 'voucher_id' => $voucherId,
            'notes' => 'Refinery holding cleared', 'created_by' => $userId,
        ]);
        jw_record_stock_txn($companyId, [
            'item_id' => $receivedItemId, 'txn_type' => 'receive_refinery', 'direction' => 'in',
            'txn_date' => $receiveDate, 'ref_no' => (string) $job['job_no'], 'holder_type' => 'stock',
            'purity_id' => $receivedPurityId, 'unit_id' => (int) $job['unit_id'],
            'gross_weight' => $receivedGross, 'fine_weight' => $receivedFine, 'rate' => $avgRate,
            'amount' => $returnedValue,
            'source_type' => 'jewellery_refinery_receive', 'source_id' => $jobId, 'voucher_id' => $voucherId,
            'created_by' => $userId,
        ]);

        db()->prepare("UPDATE jewellery_refinery_jobs SET status = 'received', receive_date = :date,
                received_item_id = :item, received_purity_id = :purity, received_gross_weight = :gross,
                received_fine_weight = :fine, loss_fine_weight = :lfine, loss_amount = :lamount,
                surplus_fine_weight = :sfine, surplus_amount = :samount,
                charges_amount = :charges, charges_settle_mode = :cmode, charges_ledger_id = :cledger,
                receive_voucher_id = :v
            WHERE id = :id AND company_id = :cid")
            ->execute([
                'date' => $receiveDate, 'item' => $receivedItemId, 'purity' => $receivedPurityId,
                'gross' => $receivedGross, 'fine' => $receivedFine, 'lfine' => $lossFine, 'lamount' => $lossAmount,
                'sfine' => $surplusFine, 'samount' => $surplusAmount,
                'charges' => $charges, 'cmode' => $chargesMode, 'cledger' => $chargesLedgerId, 'v' => $voucherId,
                'id' => $jobId, 'cid' => $companyId,
            ]);

        // One bill for what the refiner is owed: his fee, and the metal of his
        // own that came back in the bar. Billing them separately would have the
        // shop paying one refiner twice for one job.
        $owedOnCredit = jw_round_money($charges + $surplusAmount);
        if ($owedOnCredit > 0 && $chargesMode === 'credit' && (int) ($job['party_id'] ?? 0) > 0) {
            jw_open_bill($companyId, [
                'fiscal_year_id' => $fiscalYearId, 'party_id' => (int) $job['party_id'], 'bill_type' => 'purchase',
                'source_type' => 'jewellery_refinery_receive', 'source_id' => $jobId,
                'bill_no' => $job['job_no'] . '-R', 'bill_date' => $receiveDate, 'bill_amount' => $owedOnCredit,
                'voucher_id' => $voucherId,
            ]);
        }

        if ($ownsTransaction) {
            db()->commit();
        }

        return ['ok' => true, 'error' => '', 'voucher_id' => (int) $voucherId,
            'loss_fine' => $lossFine, 'loss_amount' => $lossAmount,
            'surplus_fine' => $surplusFine, 'surplus_amount' => $surplusAmount];
    } catch (Throwable $receiveException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $receiveException->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// Metal revaluation
//
// A kaligad's metal ledger carries what the metal COST when it went out. That
// is correct historical accounting, but it is not the number a shop wants on
// the balance sheet when gold has moved: "Metal with Ram" showing last year's
// rate understates a real asset.
//
// Revaluing posts the difference — and only the difference — between the
// carrying value and the same weight at a chosen rate. Once posted, the trial
// balance and the balance sheet show the metal at that rate with no special
// case anywhere in the core reports: it is an ordinary journal like any other.
// ---------------------------------------------------------------------------

/** The voucher number a revaluation always carries, so re-running replaces rather than duplicates. */
function jw_revaluation_no(string $holderType, int $holderId, string $asOf): string
{
    return 'JREV-' . strtoupper(substr($holderType, 0, 3)) . '-'
        . str_pad((string) $holderId, 4, '0', STR_PAD_LEFT) . '-' . str_replace('-', '', $asOf);
}

/**
 * Restate a kaligad's metal holding to $fineRate as at $asOf.
 *
 * Idempotent by construction: the previous revaluation for the same kaligad
 * and date is removed first, so the gap is always measured against the ORIGINAL
 * cost basis and running it twice does not double the adjustment.
 *
 * $options are passed to the rate ladder — pass fine_rate to force a rate, or
 * metal_id / purity_id to take the rate board's quote on $asOf.
 */
function jewellery_revalue_karigar_metal(
    int $companyId,
    int $fiscalYearId,
    int $karigarId,
    string $asOf,
    array $options = [],
    int $userId = 0
): array {
    $karigar = jewellery_karigar($companyId, $karigarId);
    if (!$karigar) {
        return ['ok' => false, 'error' => 'That kaligad does not belong to this company.'];
    }
    if ($asOf === '' || strtotime($asOf) === false) {
        return ['ok' => false, 'error' => 'Choose the date to value the metal on.'];
    }
    $asOf = date('Y-m-d', (int) strtotime($asOf));

    $metalLedgerId = jw_karigar_metal_ledger_id($companyId, $karigar);
    if ($metalLedgerId <= 0) {
        return ['ok' => false, 'error' => 'This kaligad has no metal ledger yet. Map "Metal with karigar" under '
            . 'Jewellery → Settings → Posting Ledgers — that mapping supplies the group each kaligad ledger is created in.'];
    }

    $ownsTransaction = !db()->inTransaction();
    try {
        if ($ownsTransaction) {
            db()->beginTransaction();
        }

        // Drop any earlier revaluation for this date FIRST, so what follows is
        // measured against cost, not against cost-plus-yesterday's-adjustment.
        $voucherNo = jw_revaluation_no('karigar', $karigarId, $asOf);
        $prior = db()->prepare("SELECT id FROM vouchers WHERE company_id = :cid
            AND source_type = 'jewellery_metal_revaluation' AND voucher_no = :no");
        $prior->execute(['cid' => $companyId, 'no' => $voucherNo]);
        foreach ($prior->fetchAll(PDO::FETCH_COLUMN) as $priorId) {
            db()->prepare('DELETE FROM voucher_entries WHERE voucher_id = :v')->execute(['v' => (int) $priorId]);
            db()->prepare('DELETE FROM vouchers WHERE id = :v AND company_id = :cid')
                ->execute(['v' => (int) $priorId, 'cid' => $companyId]);
        }

        $position = jewellery_holder_metal_position($companyId, 'karigar', $karigarId, $asOf);
        $fine = (float) $position['fine_weight'];
        $carrying = (float) $position['metal_value'];
        $rate = jw_statement_fine_rate($companyId, $options, $asOf, $fine, $carrying);
        $fineRate = (float) $rate['fine_rate'];
        if ($fineRate <= 0) {
            throw new RuntimeException('No rate is available to value this metal on ' . $asOf
                . '. Enter a rate, or quote one on the Daily Rates board.');
        }

        $valued = jw_round_money($fine * $fineRate);
        $gap = jw_round_money($valued - $carrying);
        if (abs($gap) < 0.005) {
            if ($ownsTransaction) {
                db()->commit();
            }

            return ['ok' => true, 'error' => '', 'voucher_id' => 0, 'gap' => 0.0,
                'fine_weight' => $fine, 'carrying_value' => $carrying, 'valued' => $valued,
                'fine_rate' => $fineRate, 'rate_source' => $rate['source'],
                'note' => 'Already carried at this rate — nothing to post.'];
        }

        // A gain lands in stock gain, a loss in stock loss; the metal ledger
        // takes the other side and ends the day at the chosen valuation.
        $counterPurpose = $gap > 0 ? 'stock_gain' : 'stock_loss';
        $counterLedgerId = jw_require_ledger($companyId, $counterPurpose);

        $entries = jw_build_entries([
            ['ledger_id' => $metalLedgerId, 'amount' => $gap,
                'memo' => 'Metal revalued at ' . number_format($fineRate, JW_RATE_SCALE)],
            ['ledger_id' => $counterLedgerId, 'amount' => -$gap,
                'memo' => ($gap > 0 ? 'Gain on ' : 'Loss on ') . 'metal held by ' . $karigar['code']],
        ]);
        if ($entries === []) {
            throw new RuntimeException('The revaluation nets to nothing — check that stock gain and stock loss '
                . 'are not mapped to the metal ledger itself.');
        }

        $voucherId = create_voucher_with_entries([
            'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
            'voucher_no' => $voucherNo, 'voucher_type' => 'journal', 'voucher_date' => $asOf,
            'source_type' => 'jewellery_metal_revaluation', 'source_id' => $karigarId,
            'party_id' => (int) ($karigar['party_id'] ?? 0) ?: null,
            'narration' => 'Metal with ' . $karigar['name'] . ' revalued to '
                . number_format($fineRate, JW_RATE_SCALE) . ' per fine unit as at ' . $asOf,
            'total_amount' => abs($gap), 'status' => 'posted', 'posted_by' => $userId ?: null,
        ], $entries);

        if ($ownsTransaction) {
            db()->commit();
        }

        return ['ok' => true, 'error' => '', 'voucher_id' => (int) $voucherId, 'gap' => $gap,
            'fine_weight' => $fine, 'carrying_value' => $carrying, 'valued' => $valued,
            'fine_rate' => $fineRate, 'rate_source' => $rate['source'], 'note' => ''];
    } catch (Throwable $revalueException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $revalueException->getMessage()];
    }
}

/**
 * Undo a posted kaligad receipt, putting the metal back with the kaligad.
 *
 * Weights are mis-keyed constantly in this trade — a receipt entered at 4.9
 * instead of 9.4 posts wages, a wastage loss, two stock movements and a bill,
 * and without this there was no way back short of editing the database. The
 * module's own design note says a jewellery house "backdates and corrects
 * constantly"; a one-way receipt contradicted that.
 *
 * REFUSED when the wage bill has already been settled, for the same reason a
 * sale is: reversing would strand the payment against a bill that no longer
 * exists. Reverse the settlement first.
 */
function jewellery_unpost_receipt(int $companyId, int $receiptId, int $userId = 0): array
{
    $stmt = db()->prepare('SELECT r.*, a.id AS assignment_id, a.order_id
        FROM jewellery_order_receipts r
        INNER JOIN jewellery_order_assignments a ON a.id = r.assignment_id
        WHERE r.id = :id AND r.company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $receiptId, 'cid' => $companyId]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$receipt) {
        return ['ok' => false, 'error' => 'Receipt not found for this company.'];
    }
    if ((string) $receipt['status'] !== 'posted') {
        return ['ok' => false, 'error' => 'Only a posted receipt can be reversed.'];
    }

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        // The wage bill first: if any of it has been paid, stop before anything
        // is undone rather than half way through.
        $billStmt = db()->prepare("SELECT id, bill_no, settled_amount FROM jewellery_bills
            WHERE company_id = :cid AND source_type = 'jewellery_order_receipt' AND source_id = :sid LIMIT 1");
        $billStmt->execute(['cid' => $companyId, 'sid' => $receiptId]);
        $bill = $billStmt->fetch(PDO::FETCH_ASSOC);
        if ($bill && (float) $bill['settled_amount'] > 0.005) {
            throw new RuntimeException('Wage bill ' . $bill['bill_no'] . ' has already been part settled. '
                . 'Reverse that settlement before reversing this receipt.');
        }

        $voucherId = (int) ($receipt['voucher_id'] ?? 0);
        if ($voucherId > 0) {
            $vStmt = db()->prepare('SELECT * FROM vouchers WHERE id = :id AND company_id = :cid LIMIT 1');
            $vStmt->execute(['id' => $voucherId, 'cid' => $companyId]);
            $voucher = $vStmt->fetch(PDO::FETCH_ASSOC);
            if ($voucher) {
                $blocker = voucher_mutation_blocker($voucher, ['jewellery_order_receipt']);
                if ($blocker !== null) {
                    throw new RuntimeException($blocker);
                }
            }
            db()->prepare('DELETE FROM vouchers WHERE id = :id AND company_id = :cid')
                ->execute(['id' => $voucherId, 'cid' => $companyId]);
        }
        if ($bill) {
            db()->prepare('DELETE FROM jewellery_bills WHERE id = :id AND company_id = :cid')
                ->execute(['id' => (int) $bill['id'], 'cid' => $companyId]);
        }
        db()->prepare("DELETE FROM jewellery_stock_txns
            WHERE company_id = :cid AND source_type = 'jewellery_order_receipt' AND source_id = :sid")
            ->execute(['cid' => $companyId, 'sid' => $receiptId]);
        db()->prepare('DELETE FROM jewellery_order_receipts WHERE id = :id AND company_id = :cid')
            ->execute(['id' => $receiptId, 'cid' => $companyId]);

        // The metal is with the kaligad again, so the assignment is outstanding
        // again — and the order is back to being out for making.
        db()->prepare("UPDATE jewellery_order_assignments SET status = 'issued' WHERE id = :id AND company_id = :cid")
            ->execute(['id' => (int) $receipt['assignment_id'], 'cid' => $companyId]);
        if ((int) ($receipt['order_id'] ?? 0) > 0) {
            jewellery_sync_order_status($companyId, (int) $receipt['order_id']);
        }

        if ($ownsTransaction) {
            db()->commit();
        }
        log_activity('company', $companyId, 'jewellery_receipt_reversed',
            'Kaligad receipt ' . $receipt['receipt_no'] . ' reversed; the metal is with the kaligad again.', $userId);

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $reverseException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $reverseException->getMessage()];
    }
}

/**
 * Cancel a refinery job that is still out, bringing the metal back into stock.
 *
 * The mirror of jewellery_cancel_assignment for the refinery side, which had
 * no reversal at all: metal sent for refining could never be recalled in the
 * books even if the job was entered against the wrong refiner or the wrong bar.
 */
function jewellery_cancel_refinery_job(int $companyId, int $jobId, int $userId = 0): array
{
    $job = jewellery_refinery_job($companyId, $jobId);
    if (!$job) {
        return ['ok' => false, 'error' => 'Refinery job not found for this company.'];
    }
    if ((string) $job['status'] !== 'issued') {
        return ['ok' => false, 'error' => 'Only a job still out at the refinery can be cancelled.'];
    }

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        $voucherId = (int) ($job['issue_voucher_id'] ?? 0);
        if ($voucherId > 0) {
            $vStmt = db()->prepare('SELECT * FROM vouchers WHERE id = :id AND company_id = :cid LIMIT 1');
            $vStmt->execute(['id' => $voucherId, 'cid' => $companyId]);
            $voucher = $vStmt->fetch(PDO::FETCH_ASSOC);
            if ($voucher) {
                $blocker = voucher_mutation_blocker($voucher, ['jewellery_refinery_issue']);
                if ($blocker !== null) {
                    throw new RuntimeException($blocker);
                }
            }
            db()->prepare('DELETE FROM vouchers WHERE id = :id AND company_id = :cid')
                ->execute(['id' => $voucherId, 'cid' => $companyId]);
        }
        db()->prepare("DELETE FROM jewellery_stock_txns
            WHERE company_id = :cid AND source_type = 'jewellery_refinery_issue' AND source_id = :sid")
            ->execute(['cid' => $companyId, 'sid' => $jobId]);
        db()->prepare("UPDATE jewellery_refinery_jobs SET status = 'cancelled', issue_voucher_id = NULL,
                issue_stock_txn_out = NULL, issue_stock_txn_in = NULL, metal_ledger_id = NULL
            WHERE id = :id AND company_id = :cid")
            ->execute(['id' => $jobId, 'cid' => $companyId]);

        if ($ownsTransaction) {
            db()->commit();
        }
        log_activity('company', $companyId, 'jewellery_refinery_cancelled',
            'Refinery job ' . $job['job_no'] . ' cancelled; the metal returned to own stock.', $userId);

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $cancelException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $cancelException->getMessage()];
    }
}
