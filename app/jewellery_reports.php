<?php
declare(strict_types=1);

/**
 * Jewellery Accounting — the reporting suite.
 *
 * Every figure here is DERIVED from the posted documents and the dual stock
 * ledger; nothing is cached and nothing is recomputed by a different rule than
 * the one that posted it. That is deliberate: a jewellery house backdates and
 * corrects constantly, and a report that disagrees with the ledger it claims
 * to summarise is worse than no report.
 *
 * Read-only by construction — this file never posts, never writes, and never
 * calls create_voucher_with_entries().
 */

require_once __DIR__ . '/jewellery_workshop.php';

// ---------------------------------------------------------------------------
// Sales
// ---------------------------------------------------------------------------

/** Line-level sales detail — the "Sales Detailed" report. */
function jw_report_sales_detail(int $companyId, string $from, string $to, array $filters = []): array
{
    $sql = "SELECT s.sale_no, s.sale_date, s.party_id, s.customer_name, s.status,
                COALESCE(ap.name, s.customer_name, 'Walk-in') AS party_label,
                l.id AS line_id, l.qty_pieces, l.gross_weight, l.fine_weight, l.rate,
                l.metal_amount, l.making_amount, l.stone_amount, l.vat_base, l.vat_rate, l.vat_amount,
                l.allocated_adjust, l.line_total, l.cogs_amount,
                i.sku AS item_code, i.name AS item_name, i.category, jp.jewellery_type AS item_type,
                m.name AS metal_name, p.code AS purity_code, u.code AS unit_code
            FROM jewellery_sale_lines l
            INNER JOIN jewellery_sales s ON s.id = l.sale_id
            INNER JOIN inventory_items i ON i.id = l.item_id
            INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
            INNER JOIN jewellery_metals m ON m.id = jp.metal_id
            INNER JOIN jewellery_purities p ON p.id = l.purity_id
            INNER JOIN jewellery_units u ON u.id = l.unit_id
            LEFT JOIN accounting_parties ap ON ap.id = s.party_id
            WHERE s.company_id = :cid AND s.sale_date BETWEEN :from AND :to
              AND s.status = :status";
    $params = ['cid' => $companyId, 'from' => $from, 'to' => $to, 'status' => (string) ($filters['status'] ?? 'posted')];

    if (!empty($filters['party_id'])) {
        $sql .= ' AND s.party_id = :pid';
        $params['pid'] = (int) $filters['party_id'];
    }
    if (!empty($filters['metal_id'])) {
        $sql .= ' AND jp.metal_id = :mid';
        $params['mid'] = (int) $filters['metal_id'];
    }
    if (($filters['category'] ?? '') !== '') {
        $sql .= ' AND i.category = :cat';
        $params['cat'] = (string) $filters['category'];
    }
    $sql .= ' ORDER BY s.sale_date ASC, s.id ASC, l.id ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totals = ['fine_weight' => 0.0, 'metal_amount' => 0.0, 'making_amount' => 0.0, 'stone_amount' => 0.0,
        'vat_amount' => 0.0, 'line_total' => 0.0, 'cogs_amount' => 0.0, 'gross_profit' => 0.0];
    foreach ($rows as $index => $row) {
        // Margin is measured against the revenue side only — VAT is collected
        // for the government, never earned, so it stays out of gross profit.
        $revenue = (float) $row['metal_amount'] + (float) $row['making_amount'] + (float) $row['stone_amount'] + (float) $row['allocated_adjust'];
        $profit = jw_round_money($revenue - (float) $row['cogs_amount']);
        $rows[$index]['revenue'] = jw_round_money($revenue);
        $rows[$index]['gross_profit'] = $profit;
        $rows[$index]['gp_pct'] = $revenue > 0 ? round($profit / $revenue * 100, 2) : null;

        $totals['fine_weight'] += (float) $row['fine_weight'];
        $totals['metal_amount'] += (float) $row['metal_amount'];
        $totals['making_amount'] += (float) $row['making_amount'];
        $totals['stone_amount'] += (float) $row['stone_amount'];
        $totals['vat_amount'] += (float) $row['vat_amount'];
        $totals['line_total'] += (float) $row['line_total'];
        $totals['cogs_amount'] += (float) $row['cogs_amount'];
        $totals['gross_profit'] += $profit;
    }
    foreach ($totals as $key => $value) {
        $totals[$key] = $key === 'fine_weight' ? jw_round_weight($value) : jw_round_money($value);
    }
    $totals['revenue'] = jw_round_money($totals['metal_amount'] + $totals['making_amount'] + $totals['stone_amount']);
    $totals['gp_pct'] = $totals['revenue'] > 0 ? round($totals['gross_profit'] / $totals['revenue'] * 100, 2) : null;

    return ['rows' => $rows, 'totals' => $totals];
}

/** Sales rolled up by item, category, metal, party or day. */
function jw_report_sales_grouped(int $companyId, string $from, string $to, string $groupBy = 'item'): array
{
    $detail = jw_report_sales_detail($companyId, $from, $to);
    $keyFor = static function (array $row) use ($groupBy): string {
        return match ($groupBy) {
            'category' => (string) ($row['category'] ?? '') !== '' ? (string) $row['category'] : 'Uncategorised',
            'metal' => (string) $row['metal_name'],
            'party' => (string) $row['party_label'],
            'day' => (string) $row['sale_date'],
            default => (string) $row['item_code'] . ' — ' . (string) $row['item_name'],
        };
    };

    $groups = [];
    foreach ($detail['rows'] as $row) {
        $key = $keyFor($row);
        if (!isset($groups[$key])) {
            $groups[$key] = ['group' => $key, 'pieces' => 0.0, 'fine_weight' => 0.0, 'revenue' => 0.0,
                'vat_amount' => 0.0, 'cogs_amount' => 0.0, 'gross_profit' => 0.0];
        }
        $groups[$key]['pieces'] += (float) $row['qty_pieces'];
        $groups[$key]['fine_weight'] += (float) $row['fine_weight'];
        $groups[$key]['revenue'] += (float) $row['revenue'];
        $groups[$key]['vat_amount'] += (float) $row['vat_amount'];
        $groups[$key]['cogs_amount'] += (float) $row['cogs_amount'];
        $groups[$key]['gross_profit'] += (float) $row['gross_profit'];
    }
    foreach ($groups as $key => $group) {
        $groups[$key]['fine_weight'] = jw_round_weight($group['fine_weight']);
        $groups[$key]['revenue'] = jw_round_money($group['revenue']);
        $groups[$key]['vat_amount'] = jw_round_money($group['vat_amount']);
        $groups[$key]['cogs_amount'] = jw_round_money($group['cogs_amount']);
        $groups[$key]['gross_profit'] = jw_round_money($group['gross_profit']);
        $groups[$key]['gp_pct'] = $group['revenue'] > 0 ? round($group['gross_profit'] / $group['revenue'] * 100, 2) : null;
    }
    uasort($groups, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

    return array_values($groups);
}

// ---------------------------------------------------------------------------
// Purchases
// ---------------------------------------------------------------------------

/** Line-level purchase detail — the "Purchase Detailed" report. */
function jw_report_purchase_detail(int $companyId, string $from, string $to, array $filters = []): array
{
    $sql = "SELECT pu.purchase_no, pu.purchase_date, pu.party_id, pu.source, pu.status,
                COALESCE(ap.name, 'Walk-in') AS party_label,
                l.id AS line_id, l.qty_pieces, l.gross_weight, l.fine_weight, l.rate,
                l.metal_amount, l.making_amount, l.stone_amount, l.vat_base, l.vat_rate, l.vat_amount,
                l.allocated_adjust, l.line_total, l.stock_amount,
                i.sku AS item_code, i.name AS item_name, i.category, jp.jewellery_type AS item_type,
                m.name AS metal_name, p.code AS purity_code, u.code AS unit_code
            FROM jewellery_purchase_lines l
            INNER JOIN jewellery_purchases pu ON pu.id = l.purchase_id
            INNER JOIN inventory_items i ON i.id = l.item_id
            INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
            INNER JOIN jewellery_metals m ON m.id = jp.metal_id
            INNER JOIN jewellery_purities p ON p.id = l.purity_id
            INNER JOIN jewellery_units u ON u.id = l.unit_id
            LEFT JOIN accounting_parties ap ON ap.id = pu.party_id
            WHERE pu.company_id = :cid AND pu.purchase_date BETWEEN :from AND :to
              AND pu.status = :status";
    $params = ['cid' => $companyId, 'from' => $from, 'to' => $to, 'status' => (string) ($filters['status'] ?? 'posted')];

    if (!empty($filters['party_id'])) {
        $sql .= ' AND pu.party_id = :pid';
        $params['pid'] = (int) $filters['party_id'];
    }
    if (($filters['source'] ?? '') !== '') {
        $sql .= ' AND pu.source = :src';
        $params['src'] = (string) $filters['source'];
    }
    if (!empty($filters['metal_id'])) {
        $sql .= ' AND jp.metal_id = :mid';
        $params['mid'] = (int) $filters['metal_id'];
    }
    $sql .= ' ORDER BY pu.purchase_date ASC, pu.id ASC, l.id ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totals = ['fine_weight' => 0.0, 'metal_amount' => 0.0, 'making_amount' => 0.0, 'stone_amount' => 0.0,
        'vat_amount' => 0.0, 'line_total' => 0.0, 'stock_amount' => 0.0];
    foreach ($rows as $row) {
        $totals['fine_weight'] += (float) $row['fine_weight'];
        $totals['metal_amount'] += (float) $row['metal_amount'];
        $totals['making_amount'] += (float) $row['making_amount'];
        $totals['stone_amount'] += (float) $row['stone_amount'];
        $totals['vat_amount'] += (float) $row['vat_amount'];
        $totals['line_total'] += (float) $row['line_total'];
        $totals['stock_amount'] += (float) $row['stock_amount'];
    }
    foreach ($totals as $key => $value) {
        $totals[$key] = $key === 'fine_weight' ? jw_round_weight($value) : jw_round_money($value);
    }

    return ['rows' => $rows, 'totals' => $totals];
}

// ---------------------------------------------------------------------------
// Inventory
// ---------------------------------------------------------------------------

/**
 * Inventory detail: opening, in, out and closing per item over a period, in
 * both fine weight and value, plus where the metal currently sits.
 */
function jw_report_inventory_detail(int $companyId, string $from, string $to): array
{
    $items = jewellery_items_list($companyId);
    $dayBefore = date('Y-m-d', strtotime($from . ' -1 day'));

    $rows = [];
    $totals = ['opening_fine' => 0.0, 'opening_value' => 0.0, 'in_fine' => 0.0, 'in_value' => 0.0,
        'out_fine' => 0.0, 'out_value' => 0.0, 'closing_fine' => 0.0, 'closing_value' => 0.0, 'with_others_fine' => 0.0];

    $movementStmt = db()->prepare("SELECT
            COALESCE(SUM(CASE WHEN direction = 'in' THEN fine_grams ELSE 0 END), 0) AS in_fine_g,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) AS in_value,
            COALESCE(SUM(CASE WHEN direction = 'out' THEN fine_grams ELSE 0 END), 0) AS out_fine_g,
            COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) AS out_value
        FROM jewellery_stock_txns
        WHERE company_id = :cid AND item_id = :iid AND txn_date BETWEEN :from AND :to");

    foreach ($items as $item) {
        $itemId = (int) $item['id'];
        $perUnit = jw_item_unit_grams($companyId, $itemId);
        $opening = jw_item_balance($companyId, $itemId, $dayBefore, '');
        $closing = jw_item_balance($companyId, $itemId, $to, '');
        $ownClosing = jw_item_balance($companyId, $itemId, $to, 'stock');
        $movementStmt->execute(['cid' => $companyId, 'iid' => $itemId, 'from' => $from, 'to' => $to]);
        $movement = $movementStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Skip items that were flat all period and hold nothing — a stock
        // report of a thousand untouched codes helps nobody. The test covers
        // pieces and value as well as weight: a piece-tracked item (a fixed
        // lot, a loose stone) can move real quantity and money while its fine
        // weight stays at zero, and must not be silently omitted.
        $isQuiet = abs($opening['fine_weight']) < 0.00005 && abs($closing['fine_weight']) < 0.00005
            && abs($opening['qty_pieces']) < 0.0005 && abs($closing['qty_pieces']) < 0.0005
            && abs($opening['value']) < 0.005 && abs($closing['value']) < 0.005
            && (float) ($movement['in_fine_g'] ?? 0) < 0.00005 && (float) ($movement['out_fine_g'] ?? 0) < 0.00005
            && (float) ($movement['in_value'] ?? 0) < 0.005 && (float) ($movement['out_value'] ?? 0) < 0.005;
        if ($isQuiet) {
            continue;
        }

        $row = $item + [
            'opening_fine' => $opening['fine_weight'], 'opening_value' => $opening['value'],
            // Grams out of SQL, restated in the item's own unit (migration 082).
            'in_fine' => jw_round_weight((float) ($movement['in_fine_g'] ?? 0) / $perUnit),
            'in_value' => jw_round_money((float) ($movement['in_value'] ?? 0)),
            'out_fine' => jw_round_weight((float) ($movement['out_fine_g'] ?? 0) / $perUnit),
            'out_value' => jw_round_money((float) ($movement['out_value'] ?? 0)),
            'closing_fine' => $closing['fine_weight'], 'closing_value' => $closing['value'],
            'closing_pieces' => $closing['qty_pieces'],
            'avg_fine_rate' => $closing['avg_fine_rate'],
            'own_fine' => $ownClosing['fine_weight'],
            'with_others_fine' => jw_round_weight($closing['fine_weight'] - $ownClosing['fine_weight']),
        ];
        $rows[] = $row;

        foreach (['opening_fine', 'opening_value', 'in_fine', 'in_value', 'out_fine', 'out_value',
                  'closing_fine', 'closing_value', 'with_others_fine'] as $key) {
            $totals[$key] += (float) $row[$key];
        }
    }
    foreach ($totals as $key => $value) {
        $totals[$key] = str_contains($key, 'fine') ? jw_round_weight($value) : jw_round_money($value);
    }

    return ['rows' => $rows, 'totals' => $totals];
}

// ---------------------------------------------------------------------------
// VAT register
// ---------------------------------------------------------------------------

/**
 * Output and input VAT for a period, line by line. Only VAT-applicable items
 * appear, and each row states the base it was taxed on — the whole point of
 * the per-item VAT model is being able to prove that choice afterwards.
 */
function jw_report_vat_register(int $companyId, string $from, string $to): array
{
    $outStmt = db()->prepare("SELECT s.sale_no AS doc_no, s.sale_date AS doc_date,
            COALESCE(ap.name, s.customer_name, 'Walk-in') AS party_label, ap.pan_no,
            i.sku AS item_code, i.name AS item_name, l.vat_base, l.vat_rate, l.vat_amount,
            l.metal_amount, l.making_amount, l.stone_amount
        FROM jewellery_sale_lines l
        INNER JOIN jewellery_sales s ON s.id = l.sale_id
        INNER JOIN inventory_items i ON i.id = l.item_id
            INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        LEFT JOIN accounting_parties ap ON ap.id = s.party_id
        WHERE s.company_id = :cid AND s.status = 'posted' AND s.sale_date BETWEEN :from AND :to
          AND l.vat_amount > 0
        ORDER BY s.sale_date ASC, s.id ASC");
    $outStmt->execute(['cid' => $companyId, 'from' => $from, 'to' => $to]);
    $outputRows = $outStmt->fetchAll(PDO::FETCH_ASSOC);

    $inStmt = db()->prepare("SELECT pu.purchase_no AS doc_no, pu.purchase_date AS doc_date,
            COALESCE(ap.name, 'Walk-in') AS party_label, ap.pan_no,
            i.sku AS item_code, i.name AS item_name, l.vat_base, l.vat_rate, l.vat_amount,
            l.metal_amount, l.making_amount, l.stone_amount
        FROM jewellery_purchase_lines l
        INNER JOIN jewellery_purchases pu ON pu.id = l.purchase_id
        INNER JOIN inventory_items i ON i.id = l.item_id
            INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        LEFT JOIN accounting_parties ap ON ap.id = pu.party_id
        WHERE pu.company_id = :cid AND pu.status = 'posted' AND pu.purchase_date BETWEEN :from AND :to
          AND l.vat_amount > 0
        ORDER BY pu.purchase_date ASC, pu.id ASC");
    $inStmt->execute(['cid' => $companyId, 'from' => $from, 'to' => $to]);
    $inputRows = $inStmt->fetchAll(PDO::FETCH_ASSOC);

    $sum = static function (array $rows): array {
        $taxable = 0.0; $vat = 0.0;
        foreach ($rows as $row) {
            $taxable += match ((string) $row['vat_base']) {
                'making_only' => (float) $row['making_amount'],
                'stone_only' => (float) $row['stone_amount'],
                default => (float) $row['metal_amount'] + (float) $row['making_amount'] + (float) $row['stone_amount'],
            };
            $vat += (float) $row['vat_amount'];
        }

        return ['taxable' => jw_round_money($taxable), 'vat' => jw_round_money($vat)];
    };

    $output = $sum($outputRows);
    $input = $sum($inputRows);

    return [
        'output_rows' => $outputRows,
        'input_rows' => $inputRows,
        'output' => $output,
        'input' => $input,
        // Positive = payable to the tax office, negative = credit carried forward.
        'net_payable' => jw_round_money($output['vat'] - $input['vat']),
    ];
}

// ---------------------------------------------------------------------------
// Karigar
// ---------------------------------------------------------------------------

/**
 * A karigar's full ledger: every metal movement with a running fine balance,
 * plus the wage bills raised and what is still owed.
 */
function jw_report_karigar_ledger(int $companyId, int $karigarId, string $from, string $to): array
{
    $karigar = jewellery_karigar($companyId, $karigarId);
    if (!$karigar) {
        return ['karigar' => null, 'rows' => [], 'opening_fine' => 0.0, 'closing_fine' => 0.0, 'bills' => [], 'position' => []];
    }

    // A kaligad holds several items at once, each written in whatever unit its
    // document used, so this ledger crosses units by nature. Summing the stored
    // weights would add tola to gram; the canonical gram figure is summed and
    // shown in the company's reporting unit.
    $baseUnit = jewellery_base_unit($companyId);
    $perUnit = (float) ($baseUnit['grams'] ?? 0) ?: 1.0;

    $dayBefore = date('Y-m-d', strtotime($from . ' -1 day'));
    $openStmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN fine_grams ELSE -fine_grams END), 0)
        FROM jewellery_stock_txns
        WHERE company_id = :cid AND holder_type = 'karigar' AND holder_id = :kid AND txn_date <= :d");
    $openStmt->execute(['cid' => $companyId, 'kid' => $karigarId, 'd' => $dayBefore]);
    $openingFine = jw_round_weight((float) $openStmt->fetchColumn() / $perUnit);

    $stmt = db()->prepare("SELECT t.*, i.sku AS item_code, i.name AS item_name, p.code AS purity_code, u.code AS unit_code
        FROM jewellery_stock_txns t
        INNER JOIN inventory_items i ON i.id = t.item_id
            INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        INNER JOIN jewellery_purities p ON p.id = t.purity_id
        INNER JOIN jewellery_units u ON u.id = t.unit_id
        WHERE t.company_id = :cid AND t.holder_type = 'karigar' AND t.holder_id = :kid
          AND t.txn_date BETWEEN :from AND :to
        ORDER BY t.txn_date ASC, t.id ASC");
    $stmt->execute(['cid' => $companyId, 'kid' => $karigarId, 'from' => $from, 'to' => $to]);

    $running = $openingFine;
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // Each movement restated in the reporting unit before it moves the
        // running balance, so a tola line and a gram line are comparable.
        $movementFine = jw_round_weight((float) $row['fine_grams'] / $perUnit);
        $running = jw_round_weight($running + ((string) $row['direction'] === 'in' ? 1 : -1) * $movementFine);
        $row['base_fine_weight'] = $movementFine;
        $row['balance_fine'] = $running;
        $rows[] = $row;
    }

    $bills = [];
    if ((int) ($karigar['party_id'] ?? 0) > 0) {
        $billStmt = db()->prepare("SELECT * FROM jewellery_bills WHERE company_id = :cid AND party_id = :pid
            AND bill_type = 'karigar' AND bill_date BETWEEN :from AND :to ORDER BY bill_date ASC");
        $billStmt->execute(['cid' => $companyId, 'pid' => (int) $karigar['party_id'], 'from' => $from, 'to' => $to]);
        $bills = $billStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return [
        'karigar' => $karigar,
        'rows' => $rows,
        'opening_fine' => $openingFine,
        'closing_fine' => $running,
        'bills' => $bills,
        'position' => jewellery_karigar_position($companyId, $karigarId),
    ];
}

// ---------------------------------------------------------------------------
// Kaligad statement — metal and money, side by side
//
// The kaligad ledger above answers "what metal moved?". A shop settling up at
// the counter needs more than that: what metal is still out, what wages are
// still owed, and — because the two are argued about together — what the metal
// is WORTH at the rate the two of you have just agreed on.
//
// Everything here is presented at TWO valuations at once:
//   cost    what the books carry, the rate the metal went out at
//   valued  the same weight at the rate you chose for this statement
// The difference between them is not an error; it is the revaluation gap, and
// naming it is the whole point of the statement.
// ---------------------------------------------------------------------------

/** A ledger's posted balance as at a date, debit-positive. Reconciles the statement to the trial balance. */
function jw_ledger_balance_as_of(int $companyId, int $ledgerId, string $asOf): float
{
    if ($ledgerId <= 0 || !table_exists('voucher_entries') || !table_exists('vouchers')) {
        return 0.0;
    }
    $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN e.entry_type = 'debit' THEN e.amount ELSE -e.amount END), 0)
        FROM voucher_entries e
        INNER JOIN vouchers v ON v.id = e.voucher_id
        WHERE e.ledger_id = :lid AND v.company_id = :cid AND v.status = 'posted'
          AND COALESCE(v.voucher_date, v.posting_date) <= :d");
    $stmt->execute(['lid' => $ledgerId, 'cid' => $companyId, 'd' => $asOf]);

    return jw_round_money((float) $stmt->fetchColumn());
}

/**
 * The kaligad statement: metal and money for one kaligad over one period,
 * with the metal position valued at a rate you choose.
 *
 * $options: fine_rate, metal_id, purity_id, rate_type.
 *
 * SIGN CONVENTION, stated once because everything below depends on it:
 *   metal  positive = the kaligad still holds our metal (we are owed weight)
 *          negative = they returned more than went out (we owe them weight)
 *   money  positive = we owe them wages
 *          negative = they owe us
 */
function jw_report_karigar_statement(int $companyId, int $karigarId, string $from, string $to, array $options = []): array
{
    $karigar = jewellery_karigar($companyId, $karigarId);
    $baseUnit = jewellery_base_unit($companyId);
    $blank = [
        'karigar' => null, 'from' => $from, 'to' => $to, 'base_unit' => $baseUnit,
        'metal' => ['rows' => [], 'opening_fine' => 0.0, 'opening_value' => 0.0, 'closing_fine' => 0.0,
            'closing_value' => 0.0, 'in_fine' => 0.0, 'out_fine' => 0.0, 'in_value' => 0.0, 'out_value' => 0.0,
            'ledger_id' => 0, 'ledger_balance' => 0.0],
        'money' => ['rows' => [], 'opening' => 0.0, 'closing' => 0.0, 'billed' => 0.0, 'paid' => 0.0,
            'ledger_id' => 0, 'ledger_balance' => 0.0],
        'rate' => ['fine_rate' => 0.0, 'source' => 'none', 'label' => '', 'rate_row' => null],
        'settlement' => ['wages_payable' => 0.0, 'metal_receivable_fine' => 0.0, 'metal_receivable_value' => 0.0,
            'metal_payable_fine' => 0.0, 'metal_payable_value' => 0.0, 'net_payable' => 0.0,
            'carrying_value' => 0.0, 'revaluation' => 0.0],
        'mismatch' => [],
    ];
    if (!$karigar) {
        return $blank;
    }

    $unitMap = jw_unit_map($companyId);
    $dayBefore = date('Y-m-d', strtotime($from . ' -1 day'));

    // --- Metal ------------------------------------------------------------
    $opening = jewellery_holder_metal_position($companyId, 'karigar', $karigarId, $dayBefore);
    $openingFine = $opening['fine_weight'];
    $openingValue = $opening['metal_value'];

    $stmt = db()->prepare("SELECT t.*, i.sku AS item_code, i.name AS item_name,
            p.code AS purity_code, p.fineness, u.code AS unit_code
        FROM jewellery_stock_txns t
        INNER JOIN inventory_items i ON i.id = t.item_id
            INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
        INNER JOIN jewellery_purities p ON p.id = t.purity_id
        INNER JOIN jewellery_units u ON u.id = t.unit_id
        WHERE t.company_id = :cid AND t.holder_type = 'karigar' AND t.holder_id = :kid
          AND t.txn_date BETWEEN :from AND :to
        ORDER BY t.txn_date ASC, t.id ASC");
    $stmt->execute(['cid' => $companyId, 'kid' => $karigarId, 'from' => $from, 'to' => $to]);
    $metalRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // The rate needs the closing position, and the rows need the rate, so the
    // weights are totalled first and the money is layered on in a second pass.
    $runFine = $openingFine;
    $runValue = $openingValue;
    $inFine = 0.0;
    $outFine = 0.0;
    $inValue = 0.0;
    $outValue = 0.0;
    foreach ($metalRows as $index => $row) {
        $isIn = (string) $row['direction'] === 'in';
        $sign = $isIn ? 1 : -1;
        $fine = jw_weight_in_base((float) $row['fine_weight'], (int) $row['unit_id'], $unitMap, $baseUnit);
        $amount = jw_round_money((float) $row['amount']);
        $runFine = jw_round_weight($runFine + $sign * $fine);
        $runValue = jw_round_money($runValue + $sign * $amount);
        if ($isIn) {
            $inFine += $fine;
            $inValue += $amount;
        } else {
            $outFine += $fine;
            $outValue += $amount;
        }
        $metalRows[$index]['base_fine_weight'] = $fine;
        $metalRows[$index]['balance_fine'] = $runFine;
        $metalRows[$index]['balance_value'] = $runValue;
    }

    $rate = jw_statement_fine_rate($companyId, $options, $to, $runFine, $runValue);
    $fineRate = (float) $rate['fine_rate'];
    foreach ($metalRows as $index => $row) {
        // The same weight restated at the statement's rate — this is the
        // "value the transaction at the given rate" column.
        $metalRows[$index]['valued_amount'] = jw_round_money((float) $row['base_fine_weight'] * $fineRate);
    }

    $metalLedgerId = jw_karigar_metal_ledger_id($companyId, $karigar);

    // --- Money ------------------------------------------------------------
    $partyId = (int) ($karigar['party_id'] ?? 0);
    $moneyRows = [];
    $openingMoney = 0.0;
    $billed = 0.0;
    $paid = 0.0;
    $moneyLedgerId = 0;

    if ($partyId > 0) {
        $moneyLedgerId = jw_party_ledger($companyId, $partyId, 'payable');

        $priorBills = db()->prepare("SELECT COALESCE(SUM(bill_amount), 0) FROM jewellery_bills
            WHERE company_id = :cid AND party_id = :pid AND bill_type = 'karigar'
              AND status <> 'cancelled' AND bill_date < :from");
        $priorBills->execute(['cid' => $companyId, 'pid' => $partyId, 'from' => $from]);
        $openingMoney += (float) $priorBills->fetchColumn();

        $priorSettle = db()->prepare("SELECT COALESCE(SUM(CASE WHEN direction = 'paid' THEN -amount ELSE amount END), 0)
            FROM jewellery_settlements
            WHERE company_id = :cid AND party_id = :pid AND status = 'posted' AND settlement_date < :from");
        $priorSettle->execute(['cid' => $companyId, 'pid' => $partyId, 'from' => $from]);
        $openingMoney += (float) $priorSettle->fetchColumn();
        $openingMoney = jw_round_money($openingMoney);

        $billStmt = db()->prepare("SELECT id, bill_no, bill_date, bill_amount, settled_amount, status, source_type, source_id
            FROM jewellery_bills
            WHERE company_id = :cid AND party_id = :pid AND bill_type = 'karigar'
              AND status <> 'cancelled' AND bill_date BETWEEN :from AND :to");
        $billStmt->execute(['cid' => $companyId, 'pid' => $partyId, 'from' => $from, 'to' => $to]);
        foreach ($billStmt->fetchAll(PDO::FETCH_ASSOC) as $bill) {
            $amount = jw_round_money((float) $bill['bill_amount']);
            $billed += $amount;
            $moneyRows[] = [
                'date' => (string) $bill['bill_date'], 'kind' => 'bill', 'ref' => (string) $bill['bill_no'],
                'particulars' => 'Wages billed', 'amount' => $amount, 'status' => (string) $bill['status'],
                'source_type' => (string) $bill['source_type'], 'source_id' => (int) $bill['source_id'],
                'sort_id' => (int) $bill['id'],
            ];
        }

        $settleStmt = db()->prepare("SELECT id, settlement_no, settlement_date, direction, mode, amount, notes
            FROM jewellery_settlements
            WHERE company_id = :cid AND party_id = :pid AND status = 'posted'
              AND settlement_date BETWEEN :from AND :to");
        $settleStmt->execute(['cid' => $companyId, 'pid' => $partyId, 'from' => $from, 'to' => $to]);
        foreach ($settleStmt->fetchAll(PDO::FETCH_ASSOC) as $settle) {
            $isPaid = (string) $settle['direction'] === 'paid';
            $amount = jw_round_money((float) $settle['amount']);
            if ($isPaid) {
                $paid += $amount;
            }
            $moneyRows[] = [
                'date' => (string) $settle['settlement_date'],
                'kind' => $isPaid ? 'payment' : 'receipt',
                'ref' => (string) $settle['settlement_no'],
                'particulars' => ($isPaid ? 'Paid' : 'Received') . ' — ' . (string) $settle['mode'],
                'amount' => $isPaid ? -$amount : $amount,
                'status' => 'posted',
                'source_type' => 'jewellery_settlement', 'source_id' => (int) $settle['id'],
                'sort_id' => (int) $settle['id'],
            ];
        }

        usort($moneyRows, static function (array $a, array $b): int {
            return [$a['date'], $a['kind'], $a['sort_id']] <=> [$b['date'], $b['kind'], $b['sort_id']];
        });
    }

    $runMoney = $openingMoney;
    foreach ($moneyRows as $index => $row) {
        $runMoney = jw_round_money($runMoney + (float) $row['amount']);
        $moneyRows[$index]['balance'] = $runMoney;
    }
    $billed = jw_round_money($billed);
    $paid = jw_round_money($paid);

    // --- What it comes to -------------------------------------------------
    $metalReceivableFine = $runFine > 0 ? $runFine : 0.0;
    $metalPayableFine = $runFine < 0 ? jw_round_weight(-$runFine) : 0.0;
    $metalReceivableValue = jw_round_money($metalReceivableFine * $fineRate);
    $metalPayableValue = jw_round_money($metalPayableFine * $fineRate);

    $settlement = [
        'wages_payable' => $runMoney,
        'metal_receivable_fine' => $metalReceivableFine,
        'metal_receivable_value' => $metalReceivableValue,
        'metal_payable_fine' => $metalPayableFine,
        'metal_payable_value' => $metalPayableValue,
        // Settle both sides in one number: wages we owe, less the value of the
        // metal they are still holding, plus the value of metal we owe them.
        'net_payable' => jw_round_money($runMoney - $metalReceivableValue + $metalPayableValue),
        'carrying_value' => $runValue,
        // What the trial balance would move by if this rate were posted.
        'revaluation' => jw_round_money(jw_round_money($runFine * $fineRate) - $runValue),
    ];

    // --- Does the module agree with the general ledger? --------------------
    $metalLedgerBalance = jw_ledger_balance_as_of($companyId, $metalLedgerId, $to);
    $moneyLedgerBalance = jw_ledger_balance_as_of($companyId, $moneyLedgerId, $to);
    $mismatch = [];
    if ($metalLedgerId > 0 && abs($metalLedgerBalance - $runValue) > 0.01) {
        $mismatch[] = 'The metal ledger carries ' . number_format($metalLedgerBalance, 2)
            . ' but the metal register values this holding at ' . number_format($runValue, 2)
            . '. A movement was posted to stock without its voucher, or a voucher was edited outside the module.';
    }
    // Payable is a liability: credit-heavy, so the GL balance is negative here.
    if ($moneyLedgerId > 0 && abs(-$moneyLedgerBalance - $runMoney) > 0.01) {
        $mismatch[] = 'The wages ledger carries ' . number_format(-$moneyLedgerBalance, 2)
            . ' but the bills and settlements add up to ' . number_format($runMoney, 2)
            . '. This kaligad may have entries posted straight to the ledger rather than through a bill.';
    }

    return [
        'karigar' => $karigar,
        'from' => $from,
        'to' => $to,
        'base_unit' => $baseUnit,
        'metal' => [
            'rows' => $metalRows,
            'opening_fine' => $openingFine, 'opening_value' => $openingValue,
            'closing_fine' => $runFine, 'closing_value' => $runValue,
            'in_fine' => jw_round_weight($inFine), 'out_fine' => jw_round_weight($outFine),
            'in_value' => jw_round_money($inValue), 'out_value' => jw_round_money($outValue),
            'ledger_id' => $metalLedgerId, 'ledger_balance' => $metalLedgerBalance,
        ],
        'money' => [
            'rows' => $moneyRows,
            'opening' => $openingMoney, 'closing' => $runMoney,
            'billed' => $billed, 'paid' => $paid,
            'ledger_id' => $moneyLedgerId, 'ledger_balance' => jw_round_money(-$moneyLedgerBalance),
        ],
        'rate' => $rate,
        'settlement' => $settlement,
        'mismatch' => $mismatch,
    ];
}

/** Wage and wastage summary across all karigars for a period. */
function jw_report_karigar_wages(int $companyId, string $from, string $to): array
{
    $stmt = db()->prepare("SELECT k.id, k.code, k.name, k.engagement_type,
            COUNT(r.id) AS jobs,
            COALESCE(SUM(r.received_fine_weight), 0) AS received_fine,
            COALESCE(SUM(r.wastage_fine_weight), 0) AS wastage_fine,
            COALESCE(SUM(r.excess_wastage_fine), 0) AS excess_fine,
            COALESCE(SUM(r.making_amount), 0) AS making_amount,
            COALESCE(SUM(r.recovery_amount), 0) AS recovery_amount,
            COALESCE(SUM(r.net_payable), 0) AS net_payable
        FROM jewellery_karigars k
        INNER JOIN jewellery_order_assignments a ON a.karigar_id = k.id
        INNER JOIN jewellery_order_receipts r ON r.assignment_id = a.id AND r.status = 'posted'
        WHERE k.company_id = :cid AND r.receive_date BETWEEN :from AND :to
        GROUP BY k.id, k.code, k.name, k.engagement_type
        ORDER BY net_payable DESC");
    $stmt->execute(['cid' => $companyId, 'from' => $from, 'to' => $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $index => $row) {
        $received = (float) $row['received_fine'];
        $wastage = (float) $row['wastage_fine'];
        $issued = $received + $wastage;
        // Wastage as a share of what actually went out, which is the number a
        // shop compares between karigars.
        $rows[$index]['wastage_pct'] = $issued > 0 ? round($wastage / $issued * 100, 3) : null;
    }

    return $rows;
}

// ---------------------------------------------------------------------------
// Bill-wise outstanding
// ---------------------------------------------------------------------------

/** Outstanding bills grouped by party — the bill-wise seller/buyer statement. */
function jw_report_bill_outstanding(int $companyId, string $billType = ''): array
{
    $bills = jewellery_bills_list($companyId, ['bill_type' => $billType, 'open_only' => true]);

    $parties = [];
    foreach ($bills as $bill) {
        $partyId = (int) $bill['party_id'];
        if (!isset($parties[$partyId])) {
            $parties[$partyId] = [
                'party_id' => $partyId, 'party_name' => (string) $bill['party_name'],
                'party_code' => (string) ($bill['party_code'] ?? ''),
                'bills' => [], 'total_billed' => 0.0, 'total_settled' => 0.0, 'outstanding' => 0.0,
            ];
        }
        $parties[$partyId]['bills'][] = $bill;
        $parties[$partyId]['total_billed'] += (float) $bill['bill_amount'];
        $parties[$partyId]['total_settled'] += (float) $bill['settled_amount'];
        $parties[$partyId]['outstanding'] += (float) $bill['outstanding'];
    }
    foreach ($parties as $partyId => $party) {
        $parties[$partyId]['total_billed'] = jw_round_money($party['total_billed']);
        $parties[$partyId]['total_settled'] = jw_round_money($party['total_settled']);
        $parties[$partyId]['outstanding'] = jw_round_money($party['outstanding']);
    }
    uasort($parties, static fn (array $a, array $b): int => $b['outstanding'] <=> $a['outstanding']);

    return array_values($parties);
}

// ---------------------------------------------------------------------------
// Dashboard summary
// ---------------------------------------------------------------------------

/** Headline numbers for the module dashboard over a period. */
function jw_report_summary(int $companyId, string $from, string $to): array
{
    $sales = jw_report_sales_detail($companyId, $from, $to);
    $purchases = jw_report_purchase_detail($companyId, $from, $to);
    $vat = jw_report_vat_register($companyId, $from, $to);

    $position = jewellery_metal_position($companyId, $to);
    $ownFine = 0.0; $outFine = 0.0; $stockValue = 0.0;
    foreach ($position as $row) {
        if ((string) $row['holder_type'] === 'stock') {
            $ownFine += (float) $row['fine'];
        } else {
            $outFine += (float) $row['fine'];
        }
        $stockValue += (float) $row['value'];
    }

    $receivable = 0.0; $payable = 0.0;
    foreach (jewellery_bills_list($companyId, ['open_only' => true]) as $bill) {
        if ((string) $bill['bill_type'] === 'sale') {
            $receivable += (float) $bill['outstanding'];
        } else {
            $payable += (float) $bill['outstanding'];
        }
    }

    $pendingDelivery = count(jewellery_pending_delivery($companyId));
    $openOrders = db()->prepare("SELECT COUNT(*) FROM jewellery_orders WHERE company_id = :cid
        AND status IN ('draft','confirmed','assigned')");
    $openOrders->execute(['cid' => $companyId]);

    return [
        'sales_revenue' => $sales['totals']['revenue'],
        'sales_cogs' => $sales['totals']['cogs_amount'],
        'gross_profit' => $sales['totals']['gross_profit'],
        'gp_pct' => $sales['totals']['gp_pct'],
        'sales_fine' => $sales['totals']['fine_weight'],
        'purchase_value' => $purchases['totals']['stock_amount'],
        'purchase_fine' => $purchases['totals']['fine_weight'],
        'vat_output' => $vat['output']['vat'],
        'vat_input' => $vat['input']['vat'],
        'vat_net' => $vat['net_payable'],
        'own_fine' => jw_round_weight($ownFine),
        'out_fine' => jw_round_weight($outFine),
        'stock_value' => jw_round_money($stockValue),
        'receivable' => jw_round_money($receivable),
        'payable' => jw_round_money($payable),
        'open_orders' => (int) $openOrders->fetchColumn(),
        'pending_delivery' => $pendingDelivery,
    ];
}
