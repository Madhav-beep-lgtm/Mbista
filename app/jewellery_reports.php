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
    // Every component of the bill total, at the level it was computed. The
    // three that were never selected -- wastage, the Skills Promotion Tax and
    // the allocated charge -- are the difference between a report that adds up
    // to the bill and one that quietly does not.
    $sql = "SELECT s.sale_no, s.sale_date, s.party_id, s.customer_name, s.ref_no, s.status,
                COALESCE(NULLIF(TRIM(s.customer_name), ''), ap.name, 'Walk-in') AS bill_name,
                COALESCE(ap.name, s.customer_name, 'Walk-in') AS party_label,
                l.id AS line_id, l.qty_pieces, l.gross_weight, l.fine_weight, l.rate,
                l.metal_amount, l.wastage_amount, l.making_amount,
                l.stone_amount, l.diamond_amount, l.other_diamond_amount,
                l.vat_base, l.vat_rate, l.vat_amount, l.tax_amount,
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
        'diamond_amount' => 0.0, 'other_diamond_amount' => 0.0, 'stone_side' => 0.0,
        'vat_amount' => 0.0, 'line_total' => 0.0, 'cogs_amount' => 0.0, 'gross_profit' => 0.0];
    foreach ($rows as $index => $row) {
        // The three stone columns are one revenue side — posting credits them to
        // a single account, so a report that counted only `stone_amount` would
        // understate a diamond bill by the whole diamond value.
        $stoneSide = (float) $row['stone_amount'] + (float) $row['diamond_amount'] + (float) $row['other_diamond_amount'];
        // Margin is measured against the revenue side only — VAT is collected
        // for the government, never earned, so it stays out of gross profit.
        $revenue = (float) $row['metal_amount'] + (float) $row['making_amount'] + $stoneSide + (float) $row['allocated_adjust'];
        $profit = jw_round_money($revenue - (float) $row['cogs_amount']);
        $rows[$index]['stone_side'] = jw_round_money($stoneSide);
        $rows[$index]['revenue'] = jw_round_money($revenue);
        $rows[$index]['gross_profit'] = $profit;
        $rows[$index]['gp_pct'] = $revenue > 0 ? round($profit / $revenue * 100, 2) : null;

        $totals['fine_weight'] += (float) $row['fine_weight'];
        $totals['metal_amount'] += (float) $row['metal_amount'];
        $totals['making_amount'] += (float) $row['making_amount'];
        $totals['stone_amount'] += (float) $row['stone_amount'];
        $totals['diamond_amount'] += (float) $row['diamond_amount'];
        $totals['other_diamond_amount'] += (float) $row['other_diamond_amount'];
        $totals['stone_side'] += $stoneSide;
        $totals['vat_amount'] += (float) $row['vat_amount'];
        $totals['line_total'] += (float) $row['line_total'];
        $totals['cogs_amount'] += (float) $row['cogs_amount'];
        $totals['gross_profit'] += $profit;
    }
    foreach ($totals as $key => $value) {
        $totals[$key] = $key === 'fine_weight' ? jw_round_weight($value) : jw_round_money($value);
    }
    $totals['revenue'] = jw_round_money($totals['metal_amount'] + $totals['making_amount'] + $totals['stone_side']);
    $totals['gp_pct'] = $totals['revenue'] > 0 ? round($totals['gross_profit'] / $totals['revenue'] * 100, 2) : null;

    return ['rows' => $rows, 'totals' => $totals];
}

/** Sales rolled up by item, category, metal, party or day. */
/**
 * The ways a shop asks to see its sales.
 *
 * "Bill" is the register a counter recognises, one row per invoice. The rest
 * cut the same sales a different way, and every one of them foots to the same
 * total, because they are all built from the same lines.
 */
function jw_sales_group_options(): array
{
    return [
        'invoice' => 'Bill-wise (one row per invoice)',
        'day' => 'Day-wise',
        'category' => 'Category-wise',
        'purity' => 'Purity-wise',
        'item' => 'Item-wise',
        'metal' => 'Metal-wise',
        'party' => 'Customer-wise',
    ];
}

/**
 * WHAT THE TOTAL ON A BILL IS MADE OF.
 *
 * The register showed one figure called Total and no way to see inside it,
 * which is a figure nobody can check against the bill in their hand. It is:
 *
 *     metal + making + stone and diamond
 *       + other charges - discount            (allocated across the lines)
 *     = NET, which is the figure that reaches the profit and loss
 *       + Skills Promotion Tax
 *       + VAT
 *     = TOTAL, which is what the customer hands over
 *
 * WASTAGE IS NOT A TERM OF THAT SUM. The metal is priced on a weight that
 * already includes the wastage weight, so its value is inside the metal
 * amount; adding the wastage column as well counts it twice, and on a shop
 * that charges wastage the report then fails to add up. It is carried as a
 * memo -- "of which wastage" -- because a jeweller still wants to see it.
 *
 * Every one of those is stored per LINE, which is why one grouping can serve
 * all of them: group by day, by category, by purity, by item, by metal, by
 * customer or by bill, and the columns are the same and still add up to the
 * same money. Weight and purity are not components of the total -- they are
 * what the metal was measured in -- so they stay beside it as context rather
 * than inside the bifurcation.
 *
 * Other charges and discount arrive here already netted into one allocated
 * figure per line. They are a bill-level amount, and the only honest way to
 * attribute a bill-level discount to one item on it is the share the sale
 * itself allocated -- so that is the column, named for what it holds.
 *
 * @return array{columns: array<int, array{0:string,1:string,2:bool}>,
 *               rows: array<int, array<string, mixed>>, totals: array<string, float>}
 */
function jw_report_sales_bifurcated(int $companyId, string $from, string $to, string $groupBy = 'invoice', array $filters = []): array
{
    if (!array_key_exists($groupBy, jw_sales_group_options())) {
        $groupBy = 'invoice';
    }
    $detail = jw_report_sales_detail($companyId, $from, $to, $filters);

    // What identifies a row, and what else that row should carry with it. A
    // bill row shows who it was billed to and against which reference; a
    // category row has no such thing and does not pretend to.
    $keyFor = static function (array $row) use ($groupBy): string {
        return match ($groupBy) {
            'day' => (string) $row['sale_date'],
            'category' => trim((string) ($row['category'] ?? '')) !== '' ? (string) $row['category'] : 'Uncategorised',
            'purity' => trim((string) ($row['purity_code'] ?? '')) !== '' ? (string) $row['purity_code'] : 'Unstated',
            'item' => (string) $row['item_code'] . ' — ' . (string) $row['item_name'],
            'metal' => (string) $row['metal_name'],
            'party' => (string) $row['party_label'],
            default => (string) $row['sale_no'],
        };
    };

    // wastage_amount is summed with the rest but is NOT one of the terms that
    // makes up the total -- see the note above. It is reported beside metal,
    // never added to it.
    $money = ['metal_amount', 'wastage_amount', 'making_amount', 'stone_side', 'allocated_adjust',
        'tax_amount', 'vat_amount', 'line_total', 'cogs_amount', 'gross_profit'];
    $additive = ['metal_amount', 'making_amount', 'stone_side', 'allocated_adjust'];
    $groups = [];
    foreach ($detail['rows'] as $row) {
        $key = $keyFor($row);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'group' => $key,
                // Carried for a bill row and left empty elsewhere, so the
                // screen can show them without a second query or a guess.
                'sale_date' => (string) $row['sale_date'],
                'bill_name' => (string) ($row['bill_name'] ?? ''),
                'ref_no' => (string) ($row['ref_no'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'lines' => 0,
                'pieces' => 0.0,
                'gross_weight' => 0.0,
                'fine_weight' => 0.0,
            ];
            foreach ($money as $field) {
                $groups[$key][$field] = 0.0;
            }
        }
        $groups[$key]['lines']++;
        $groups[$key]['pieces'] += (float) $row['qty_pieces'];
        $groups[$key]['gross_weight'] += (float) $row['gross_weight'];
        $groups[$key]['fine_weight'] += (float) $row['fine_weight'];
        foreach ($money as $field) {
            $groups[$key][$field] += (float) ($row[$field] ?? 0);
        }
    }

    $totals = ['lines' => 0, 'pieces' => 0.0, 'gross_weight' => 0.0, 'fine_weight' => 0.0];
    foreach ($money as $field) {
        $totals[$field] = 0.0;
    }
    foreach ($groups as $key => $group) {
        foreach (['gross_weight', 'fine_weight'] as $field) {
            $groups[$key][$field] = jw_round_weight($group[$field]);
        }
        foreach ($money as $field) {
            $groups[$key][$field] = jw_round_money($group[$field]);
        }
        // THE FIGURE THAT REACHES THE PROFIT AND LOSS. VAT and the Skills
        // Promotion Tax are collected for the government and never earned, so
        // revenue is the total before both -- which is also the base gross
        // profit is measured against.
        $revenue = 0.0;
        foreach ($additive as $field) {
            $revenue += $groups[$key][$field];
        }
        $groups[$key]['revenue'] = jw_round_money($revenue);
        $groups[$key]['net_before_tax'] = $groups[$key]['revenue'];
        $groups[$key]['gp_pct'] = $revenue > 0
            ? round($groups[$key]['gross_profit'] / $revenue * 100, 2)
            : null;

        $totals['lines'] += $groups[$key]['lines'];
        $totals['pieces'] += $groups[$key]['pieces'];
        $totals['gross_weight'] += $groups[$key]['gross_weight'];
        $totals['fine_weight'] += $groups[$key]['fine_weight'];
        foreach ($money as $field) {
            $totals[$field] += $groups[$key][$field];
        }
    }
    foreach (['gross_weight', 'fine_weight'] as $field) {
        $totals[$field] = jw_round_weight($totals[$field]);
    }
    foreach ($money as $field) {
        $totals[$field] = jw_round_money($totals[$field]);
    }
    $totals['revenue'] = 0.0;
    foreach ($additive as $field) {
        $totals['revenue'] += $totals[$field];
    }
    $totals['revenue'] = jw_round_money($totals['revenue']);
    $totals['net_before_tax'] = $totals['revenue'];
    $totals['gp_pct'] = $totals['revenue'] > 0
        ? round($totals['gross_profit'] / $totals['revenue'] * 100, 2)
        : null;

    // A bill register reads in date order, the way it was written. Every other
    // cut is a ranking, and the biggest line is the one being looked for.
    if ($groupBy === 'invoice' || $groupBy === 'day') {
        uasort($groups, static fn (array $a, array $b): int => [$a['sale_date'], $a['group']] <=> [$b['sale_date'], $b['group']]);
    } else {
        uasort($groups, static fn (array $a, array $b): int => $b['line_total'] <=> $a['line_total']);
    }

    // The label the first column carries, and whether the bill-only columns
    // are worth drawing at all.
    $groupLabel = match ($groupBy) {
        'day' => 'Date',
        'category' => 'Category',
        'purity' => 'Purity',
        'item' => 'Item',
        'metal' => 'Metal',
        'party' => 'Customer',
        default => 'Bill no.',
    };

    return [
        'group_by' => $groupBy,
        'group_label' => $groupLabel,
        'per_bill' => $groupBy === 'invoice',
        'rows' => array_values($groups),
        'totals' => $totals,
    ];
}

/**
 * The same report flattened for a spreadsheet, headings and totals included.
 *
 * Built from the same rows the screen draws, so the file and the page cannot
 * disagree about what was sold.
 */
function jw_report_sales_bifurcated_rows(array $report, string $currency = ''): array
{
    $head = [$report['group_label']];
    if ($report['per_bill']) {
        $head = array_merge($head, ['Date', 'Customer (billed as)', 'Invoice ref.', 'Status']);
    }
    $head = array_merge($head, ['Lines', 'Pieces', 'Gross wt', 'Fine wt',
        'Metal (incl. wastage)', 'of which wastage', 'Making', 'Stone / diamond', 'Charges less discount',
        'Net before SPT and VAT', 'Skills Promotion Tax', 'VAT', 'TOTAL',
        'COGS', 'Gross profit', 'GP %']);
    $out = [$head];

    foreach ($report['rows'] as $row) {
        $line = [$row['group']];
        if ($report['per_bill']) {
            $line = array_merge($line, [$row['sale_date'], $row['bill_name'], $row['ref_no'], $row['status']]);
        }
        $out[] = array_merge($line, [
            $row['lines'], $row['pieces'], $row['gross_weight'], $row['fine_weight'],
            $row['metal_amount'], $row['wastage_amount'], $row['making_amount'], $row['stone_side'],
            $row['allocated_adjust'], $row['net_before_tax'], $row['tax_amount'], $row['vat_amount'],
            $row['line_total'], $row['cogs_amount'], $row['gross_profit'],
            $row['gp_pct'] === null ? '' : $row['gp_pct'],
        ]);
    }

    $totals = $report['totals'];
    $totalLine = ['TOTAL'];
    if ($report['per_bill']) {
        $totalLine = array_merge($totalLine, ['', '', '', '']);
    }
    $out[] = array_merge($totalLine, [
        $totals['lines'], $totals['pieces'], $totals['gross_weight'], $totals['fine_weight'],
        $totals['metal_amount'], $totals['wastage_amount'], $totals['making_amount'], $totals['stone_side'],
        $totals['allocated_adjust'], $totals['net_before_tax'], $totals['tax_amount'], $totals['vat_amount'],
        $totals['line_total'], $totals['cogs_amount'], $totals['gross_profit'],
        $totals['gp_pct'] === null ? '' : $totals['gp_pct'],
    ]);

    return $out;
}

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

/**
 * Purchase detail: every gram of metal the shop took IN over the period.
 *
 * Old gold arrives by three different doors and only one of them used to be
 * counted here:
 *
 *   invoice        a purchase document — from a supplier, or raised against a
 *                  customer walking in to sell metal (source customer_old_gold).
 *   sale_exchange  metal handed over against a bill. The customer pays part of
 *                  the price in gold; the shop acquires that gold.
 *   settlement     metal taken in settlement of a bill, or left as an ADVANCE
 *                  before any bill exists. Same event either way: gold in.
 *
 * The last two are purchases of old gold in every sense that matters — the
 * shop ends up holding metal it did not hold before, at an agreed rate, and
 * that metal goes on to be sold or refined. Leaving them out understated the
 * period's buying by however much old gold came over the counter, which in a
 * jewellery shop is most of it.
 *
 * They are marked rather than merged. An old-gold leg carries no supplier
 * invoice, no making charge and NO INPUT VAT — a customer selling a chain is
 * not a registered vendor — so a total that mixed the two would misstate the
 * VAT-bearing purchase figure. Every row says which door it came through, and
 * the totals are footed by origin as well as overall.
 *
 * No double count: an advance moves metal once, when it is taken. Applying it
 * to a later bill moves MONEY between that customer's advance and receivable
 * ledgers, and touches no weight at all.
 */
function jw_report_purchase_detail(int $companyId, string $from, string $to, array $filters = []): array
{
    $status = (string) ($filters['status'] ?? 'posted');
    $partyId = (int) ($filters['party_id'] ?? 0);
    $metalId = (int) ($filters['metal_id'] ?? 0);
    $source = (string) ($filters['source'] ?? '');
    $origin = (string) ($filters['origin'] ?? '');

    $rows = [];

    // --- 1. purchase documents ---------------------------------------------
    if ($origin === '' || $origin === 'invoice') {
        $sql = "SELECT pu.purchase_no, pu.purchase_date, pu.party_id, pu.source, pu.status,
                    COALESCE(ap.name, 'Walk-in') AS party_label,
                    l.id AS line_id, l.qty_pieces, l.gross_weight, l.fine_weight, l.rate,
                    l.metal_amount, l.making_amount, l.stone_amount, l.diamond_amount, l.other_diamond_amount,
                    l.vat_base, l.vat_rate, l.vat_amount,
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
        $params = ['cid' => $companyId, 'from' => $from, 'to' => $to, 'status' => $status];
        if ($partyId > 0) {
            $sql .= ' AND pu.party_id = :pid';
            $params['pid'] = $partyId;
        }
        if ($source !== '') {
            $sql .= ' AND pu.source = :src';
            $params['src'] = $source;
        }
        if ($metalId > 0) {
            $sql .= ' AND jp.metal_id = :mid';
            $params['mid'] = $metalId;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['origin'] = 'invoice';
            $row['origin_label'] = (string) $row['source'] === 'customer_old_gold'
                ? 'Old gold bill' : 'Purchase bill';
            $rows[] = $row;
        }
    }

    // Old gold is bought FROM a customer, so a filter for supplier purchases
    // excludes it and a filter for old gold is exactly what it is.
    $wantsOldGold = $source === '' || $source === 'customer_old_gold';

    // --- 2. old gold taken against a bill -----------------------------------
    if ($wantsOldGold && ($origin === '' || $origin === 'sale_exchange')) {
        $sql = "SELECT s.sale_no AS purchase_no, s.sale_date AS purchase_date, s.party_id, s.status,
                    COALESCE(ap.name, 'Walk-in') AS party_label,
                    e.id AS line_id, e.qty_pieces, e.gross_weight, e.fine_weight, e.rate, e.amount,
                    i.sku AS item_code, i.name AS item_name, i.category, jp.jewellery_type AS item_type,
                    m.name AS metal_name, p.code AS purity_code, u.code AS unit_code
                FROM jewellery_sale_exchanges e
                INNER JOIN jewellery_sales s ON s.id = e.sale_id
                INNER JOIN inventory_items i ON i.id = e.item_id
                INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
                INNER JOIN jewellery_metals m ON m.id = jp.metal_id
                INNER JOIN jewellery_purities p ON p.id = e.purity_id
                INNER JOIN jewellery_units u ON u.id = e.unit_id
                LEFT JOIN accounting_parties ap ON ap.id = s.party_id
                WHERE e.company_id = :cid AND s.sale_date BETWEEN :from AND :to
                  AND s.status = :status";
        $params = ['cid' => $companyId, 'from' => $from, 'to' => $to, 'status' => $status];
        if ($partyId > 0) {
            $sql .= ' AND s.party_id = :pid';
            $params['pid'] = $partyId;
        }
        if ($metalId > 0) {
            $sql .= ' AND jp.metal_id = :mid';
            $params['mid'] = $metalId;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = jw_purchase_old_gold_row($row, 'sale_exchange', 'Old gold on a sale');
        }
    }

    // --- 3. old gold taken in settlement, or left as an advance -------------
    if ($wantsOldGold && ($origin === '' || $origin === 'settlement')) {
        // A settlement records its metal in ONE of two shapes: a tender
        // breakdown when the counter split the payment, or the settlement's own
        // columns when it did not. Reading both without a guard would count a
        // split payment twice, so each side excludes the other.
        $common = "st.settlement_no AS purchase_no, st.settlement_date AS purchase_date, st.party_id, st.status,
                    st.is_advance, COALESCE(ap.name, 'Walk-in') AS party_label,
                    i.sku AS item_code, i.name AS item_name, i.category, jp.jewellery_type AS item_type,
                    m.name AS metal_name, p.code AS purity_code, u.code AS unit_code";
        $where = "st.company_id = :cid AND st.settlement_date BETWEEN :from AND :to
                  AND st.status = :status AND st.direction = 'received'";
        $params = ['cid' => $companyId, 'from' => $from, 'to' => $to, 'status' => $status];
        if ($partyId > 0) {
            $where .= ' AND st.party_id = :pid';
            $params['pid'] = $partyId;
        }
        $metalWhere = $metalId > 0 ? ' AND jp.metal_id = :mid' : '';
        if ($metalId > 0) {
            $params['mid'] = $metalId;
        }

        $tenderSql = "SELECT {$common}, t.id AS line_id, 0 AS qty_pieces,
                    t.gross_weight, t.fine_weight, t.amount,
                    CASE WHEN t.fine_weight > 0 THEN t.amount / t.fine_weight ELSE 0 END AS rate
                FROM jewellery_settlement_tenders t
                INNER JOIN jewellery_settlements st ON st.id = t.settlement_id
                INNER JOIN inventory_items i ON i.id = t.item_id
                INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
                INNER JOIN jewellery_metals m ON m.id = jp.metal_id
                INNER JOIN jewellery_purities p ON p.id = t.purity_id
                INNER JOIN jewellery_units u ON u.id = t.unit_id
                LEFT JOIN accounting_parties ap ON ap.id = st.party_id
                WHERE {$where} AND t.mode = 'metal'{$metalWhere}";
        $stmt = db()->prepare($tenderSql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = jw_purchase_old_gold_row($row, 'settlement',
                (int) $row['is_advance'] === 1 ? 'Old gold advance' : 'Old gold in settlement');
        }

        $plainSql = "SELECT {$common}, st.id AS line_id, 0 AS qty_pieces,
                    st.gross_weight, st.fine_weight, st.amount,
                    CASE WHEN st.fine_weight > 0 THEN st.amount / st.fine_weight ELSE 0 END AS rate
                FROM jewellery_settlements st
                INNER JOIN inventory_items i ON i.id = st.item_id
                INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
                INNER JOIN jewellery_metals m ON m.id = jp.metal_id
                INNER JOIN jewellery_purities p ON p.id = st.purity_id
                INNER JOIN jewellery_units u ON u.id = st.unit_id
                LEFT JOIN accounting_parties ap ON ap.id = st.party_id
                WHERE {$where} AND st.mode = 'metal'{$metalWhere}
                  AND NOT EXISTS (SELECT 1 FROM jewellery_settlement_tenders t2
                        WHERE t2.settlement_id = st.id AND t2.mode = 'metal')";
        $stmt = db()->prepare($plainSql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = jw_purchase_old_gold_row($row, 'settlement',
                (int) $row['is_advance'] === 1 ? 'Old gold advance' : 'Old gold in settlement');
        }
    }

    // One register, in date order, whichever door each line came through.
    usort($rows, static function (array $a, array $b): int {
        return [(string) $a['purchase_date'], (string) $a['purchase_no']]
            <=> [(string) $b['purchase_date'], (string) $b['purchase_no']];
    });

    $blank = ['fine_weight' => 0.0, 'gross_weight' => 0.0, 'metal_amount' => 0.0, 'making_amount' => 0.0,
        'stone_amount' => 0.0, 'diamond_amount' => 0.0, 'other_diamond_amount' => 0.0, 'stone_side' => 0.0,
        'vat_amount' => 0.0, 'line_total' => 0.0, 'stock_amount' => 0.0, 'lines' => 0];
    $totals = $blank;
    $byOrigin = [];
    foreach ($rows as $index => $row) {
        $stoneSide = (float) $row['stone_amount'] + (float) $row['diamond_amount'] + (float) $row['other_diamond_amount'];
        $rows[$index]['stone_side'] = jw_round_money($stoneSide);
        $key = (string) $row['origin'];
        $byOrigin[$key] = $byOrigin[$key] ?? $blank;
        foreach (['fine_weight', 'gross_weight', 'metal_amount', 'making_amount', 'stone_amount',
            'diamond_amount', 'other_diamond_amount', 'vat_amount', 'line_total', 'stock_amount'] as $field) {
            $totals[$field] += (float) $row[$field];
            $byOrigin[$key][$field] += (float) $row[$field];
        }
        $totals['stone_side'] += $stoneSide;
        $byOrigin[$key]['stone_side'] += $stoneSide;
        $totals['lines']++;
        $byOrigin[$key]['lines']++;
    }
    $round = static function (array $set): array {
        foreach ($set as $field => $value) {
            if ($field === 'lines') {
                continue;
            }
            $set[$field] = in_array($field, ['fine_weight', 'gross_weight'], true)
                ? jw_round_weight((float) $value) : jw_round_money((float) $value);
        }

        return $set;
    };
    $totals = $round($totals);
    foreach ($byOrigin as $key => $set) {
        $byOrigin[$key] = $round($set);
    }

    return ['rows' => $rows, 'totals' => $totals, 'by_origin' => $byOrigin];
}

/**
 * One old-gold acquisition in the shape a purchase line has.
 *
 * Everything a purchase invoice carries and old gold does not is explicitly
 * nought rather than absent — most of all the VAT, which must stay out of the
 * input-tax total. A customer selling a chain issues no tax invoice.
 */
function jw_purchase_old_gold_row(array $row, string $origin, string $label): array
{
    $amount = jw_round_money((float) $row['amount']);

    return [
        'purchase_no' => (string) $row['purchase_no'],
        'purchase_date' => (string) $row['purchase_date'],
        'party_id' => $row['party_id'] ?? null,
        'party_label' => (string) $row['party_label'],
        'source' => 'customer_old_gold',
        'status' => (string) $row['status'],
        'origin' => $origin,
        'origin_label' => $label,
        'line_id' => $row['line_id'] ?? null,
        'qty_pieces' => (float) ($row['qty_pieces'] ?? 0),
        'gross_weight' => (float) $row['gross_weight'],
        'fine_weight' => (float) $row['fine_weight'],
        'rate' => (float) ($row['rate'] ?? 0),
        'metal_amount' => $amount,
        'making_amount' => 0.0,
        'stone_amount' => 0.0,
        'diamond_amount' => 0.0,
        'other_diamond_amount' => 0.0,
        'vat_base' => 0.0,
        'vat_rate' => 0.0,
        'vat_amount' => 0.0,
        'allocated_adjust' => 0.0,
        'line_total' => $amount,
        'stock_amount' => $amount,
        'item_code' => (string) $row['item_code'],
        'item_name' => (string) $row['item_name'],
        'category' => $row['category'] ?? null,
        'item_type' => $row['item_type'] ?? null,
        'metal_name' => (string) $row['metal_name'],
        'purity_code' => (string) $row['purity_code'],
        'unit_code' => (string) $row['unit_code'],
    ];
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

    // One grouped pass replaces five balance/movement queries per item. All
    // weights remain in grams until they are converted to each item's unit.
    $balanceStmt = db()->prepare("SELECT item_id,
        SUM(CASE WHEN txn_date <= bounds.opening_on THEN IF(direction='in',qty_pieces,-qty_pieces) ELSE 0 END) opening_pieces,
        SUM(CASE WHEN txn_date <= bounds.opening_on THEN IF(direction='in',fine_grams,-fine_grams) ELSE 0 END) opening_fine_g,
        SUM(CASE WHEN txn_date <= bounds.opening_on THEN IF(direction='in',amount,-amount) ELSE 0 END) opening_value,
        SUM(CASE WHEN txn_date <= bounds.closing_on THEN IF(direction='in',qty_pieces,-qty_pieces) ELSE 0 END) closing_pieces,
        SUM(CASE WHEN txn_date <= bounds.closing_on THEN IF(direction='in',gross_grams,-gross_grams) ELSE 0 END) closing_gross_g,
        SUM(CASE WHEN txn_date <= bounds.closing_on THEN IF(direction='in',fine_grams,-fine_grams) ELSE 0 END) closing_fine_g,
        SUM(CASE WHEN txn_date <= bounds.closing_on THEN IF(direction='in',amount,-amount) ELSE 0 END) closing_value,
        SUM(CASE WHEN txn_date <= bounds.closing_on AND direction='in' THEN fine_grams ELSE 0 END) closing_fine_in_g,
        SUM(CASE WHEN txn_date <= bounds.closing_on AND direction='in' THEN amount ELSE 0 END) closing_value_in,
        SUM(CASE WHEN txn_date <= bounds.closing_on THEN 1 ELSE 0 END) closing_movements,
        SUM(CASE WHEN txn_date <= bounds.closing_on AND holder_type='stock' THEN IF(direction='in',fine_grams,-fine_grams) ELSE 0 END) own_fine_g,
        SUM(CASE WHEN txn_date BETWEEN bounds.period_from AND bounds.closing_on AND direction='in' THEN fine_grams ELSE 0 END) in_fine_g,
        SUM(CASE WHEN txn_date BETWEEN bounds.period_from AND bounds.closing_on AND direction='in' THEN amount ELSE 0 END) in_value,
        SUM(CASE WHEN txn_date BETWEEN bounds.period_from AND bounds.closing_on AND direction='out' THEN fine_grams ELSE 0 END) out_fine_g,
        SUM(CASE WHEN txn_date BETWEEN bounds.period_from AND bounds.closing_on AND direction='out' THEN amount ELSE 0 END) out_value
      FROM jewellery_stock_txns
      CROSS JOIN (SELECT CAST(:opening AS DATE) opening_on, CAST(:closing AS DATE) closing_on,
                         CAST(:period_from AS DATE) period_from) bounds
      WHERE company_id=:cid AND txn_date <= bounds.closing_on
      GROUP BY item_id");
    $balanceStmt->execute(['cid'=>$companyId, 'opening'=>$dayBefore, 'closing'=>$to, 'period_from'=>$from]);
    $balances = [];
    foreach ($balanceStmt->fetchAll(PDO::FETCH_ASSOC) as $balanceRow) {
        $balances[(int) $balanceRow['item_id']] = $balanceRow;
    }

    foreach ($items as $item) {
        $itemId = (int) $item['id'];
        $perUnit = max(0.0000001, (float) ($item['grams'] ?? 1));
        $movement = $balances[$itemId] ?? [];
        $openingFine = jw_round_weight((float) ($movement['opening_fine_g'] ?? 0) / $perUnit);
        $openingValue = jw_round_money((float) ($movement['opening_value'] ?? 0));
        $closingFine = jw_round_weight((float) ($movement['closing_fine_g'] ?? 0) / $perUnit);
        $closingValue = jw_round_money((float) ($movement['closing_value'] ?? 0));
        $closingPieces = round((float) ($movement['closing_pieces'] ?? 0), 3);
        $ownFine = jw_round_weight((float) ($movement['own_fine_g'] ?? 0) / $perUnit);
        $fineIn = (float) ($movement['closing_fine_in_g'] ?? 0) / $perUnit;
        $avgFineRate = $closingFine > 0.00005
            ? jw_round_rate($closingValue / $closingFine)
            : ($fineIn > 0.00005 ? jw_round_rate((float) ($movement['closing_value_in'] ?? 0) / $fineIn) : 0.0);

        // Skip items that were flat all period and hold nothing — a stock
        // report of a thousand untouched codes helps nobody. The test covers
        // pieces and value as well as weight: a piece-tracked item (a fixed
        // lot, a loose stone) can move real quantity and money while its fine
        // weight stays at zero, and must not be silently omitted.
        $isQuiet = abs($openingFine) < 0.00005 && abs($closingFine) < 0.00005
            && abs((float) ($movement['opening_pieces'] ?? 0)) < 0.0005 && abs($closingPieces) < 0.0005
            && abs($openingValue) < 0.005 && abs($closingValue) < 0.005
            && (float) ($movement['in_fine_g'] ?? 0) < 0.00005 && (float) ($movement['out_fine_g'] ?? 0) < 0.00005
            && (float) ($movement['in_value'] ?? 0) < 0.005 && (float) ($movement['out_value'] ?? 0) < 0.005;
        if ($isQuiet) {
            continue;
        }

        $row = $item + [
            'opening_fine' => $openingFine, 'opening_value' => $openingValue,
            // Grams out of SQL, restated in the item's own unit (migration 082).
            'in_fine' => jw_round_weight((float) ($movement['in_fine_g'] ?? 0) / $perUnit),
            'in_value' => jw_round_money((float) ($movement['in_value'] ?? 0)),
            'out_fine' => jw_round_weight((float) ($movement['out_fine_g'] ?? 0) / $perUnit),
            'out_value' => jw_round_money((float) ($movement['out_value'] ?? 0)),
            'closing_fine' => $closingFine, 'closing_value' => $closingValue,
            'closing_pieces' => $closingPieces,
            'avg_fine_rate' => $avgFineRate,
            'own_fine' => $ownFine,
            'with_others_fine' => jw_round_weight($closingFine - $ownFine),
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
            l.metal_amount, l.making_amount, l.stone_amount, l.diamond_amount, l.other_diamond_amount
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
            l.metal_amount, l.making_amount, l.stone_amount, l.diamond_amount, l.other_diamond_amount
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

    // The base a line was taxed on. Getting this wrong is a filing error, not a
    // display one: 'stone_diamond' is the base a jewellery bill actually uses
    // and it must NOT fall through to the whole line value — on a bill where
    // only the stone is vatable, that would declare the gold as taxable too.
    $baseOf = static function (array $row): float {
        return match ((string) $row['vat_base']) {
            'making_only' => (float) $row['making_amount'],
            'stone_only' => (float) $row['stone_amount'],
            'stone_diamond' => (float) $row['stone_amount'] + (float) $row['diamond_amount']
                + (float) $row['other_diamond_amount'],
            default => (float) $row['metal_amount'] + (float) $row['making_amount'] + (float) $row['stone_amount']
                + (float) $row['diamond_amount'] + (float) $row['other_diamond_amount'],
        };
    };
    $sum = static function (array $rows) use ($baseOf): array {
        $taxable = 0.0; $vat = 0.0;
        foreach ($rows as $row) {
            $taxable += $baseOf($row);
            $vat += (float) $row['vat_amount'];
        }

        return ['taxable' => jw_round_money($taxable), 'vat' => jw_round_money($vat)];
    };
    foreach ($outputRows as $i => $row) { $outputRows[$i]['taxable_amount'] = jw_round_money($baseOf($row)); }
    foreach ($inputRows as $i => $row) { $inputRows[$i]['taxable_amount'] = jw_round_money($baseOf($row)); }

    $output = $sum($outputRows);
    $input = $sum($inputRows);

    return [
        'output_rows' => $outputRows,
        'input_rows' => $inputRows,
        'output' => $output,
        'input' => $input,
        // Positive = payable to the tax office, negative = credit carried forward.
        'net_payable' => jw_round_money($output['vat'] - $input['vat']),
        'by_tax' => jw_report_tax_register($companyId, $from, $to),
    ];
}

/**
 * Every tax the shop charged in a period, one row per tax, output against
 * input. Driven by jewellery_line_taxes rather than the VAT columns on the
 * line, because that is the only place a SECOND tax is recorded — the Skills
 * Development levy sits beside VAT on the same bill, on a different base, and a
 * register that knows only about VAT cannot be filed against it.
 *
 * Any tax added to the register later appears here without a code change,
 * which is the point: the rates and the bases are the shop's to set.
 */
function jw_report_tax_register(int $companyId, string $from, string $to): array
{
    $sql = "SELECT t.tax_code, t.tax_name, :dir AS direction,
            COALESCE(SUM(t.base_amount), 0) AS base_amount, COALESCE(SUM(t.amount), 0) AS amount,
            COUNT(DISTINCT t.doc_id) AS doc_count
        FROM jewellery_line_taxes t
        INNER JOIN %s d ON d.id = t.doc_id AND d.company_id = t.company_id
        WHERE t.company_id = :cid AND t.doc_type = :doc AND d.status = 'posted'
          AND d.%s BETWEEN :from AND :to
        GROUP BY t.tax_code, t.tax_name";

    $taxes = [];
    foreach ([
        ['output', 'sale', 'jewellery_sales', 'sale_date'],
        ['input', 'purchase', 'jewellery_purchases', 'purchase_date'],
    ] as [$direction, $docType, $table, $dateColumn]) {
        $stmt = db()->prepare(sprintf($sql, $table, $dateColumn));
        $stmt->execute(['cid' => $companyId, 'doc' => $docType, 'dir' => $direction, 'from' => $from, 'to' => $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = (string) $row['tax_code'];
            $taxes[$code] ??= ['tax_code' => $code, 'tax_name' => (string) $row['tax_name'],
                'output_base' => 0.0, 'output_amount' => 0.0, 'output_docs' => 0,
                'input_base' => 0.0, 'input_amount' => 0.0, 'input_docs' => 0, 'net_payable' => 0.0];
            $taxes[$code][$direction . '_base'] = jw_round_money((float) $row['base_amount']);
            $taxes[$code][$direction . '_amount'] = jw_round_money((float) $row['amount']);
            $taxes[$code][$direction . '_docs'] = (int) $row['doc_count'];
        }
    }
    foreach ($taxes as $code => $row) {
        $taxes[$code]['net_payable'] = jw_round_money($row['output_amount'] - $row['input_amount']);
    }
    ksort($taxes);

    return array_values($taxes);
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
/**
 * THE METAL BEHIND A SET OF BILLS, batched into four queries.
 *
 * A kaligad's bill is money, and money is the wrong unit to argue with a
 * goldsmith in. What he wants to know — and what the shop needs beside the
 * figure before it pays — is the metal: how much went out for the job, how much
 * came back, how much of the bill has been settled in gold rather than in cash,
 * and what is still owed said as a weight and not only as rupees.
 *
 * TWO DIFFERENT QUESTIONS, and they do not have the same scope.
 *
 *   THE JOB — ordered against received — exists only where an assignment sits
 *   behind the bill, which means kaligad bills and nothing else. A purchase or
 *   a sale bill comes back with has_job false and no weights.
 *
 *   HOW IT WAS SETTLED is asked of EVERY bill, because any of them can be paid
 *   in old gold. Restricting the split to kaligad bills was a real fault while
 *   it lasted: a supplier paid in metal had that metal reported in the cash
 *   column, which is the one place the distinction actually matters.
 *
 * ORDERED vs RECEIVED. The bill is raised on what CAME BACK, never on what went
 * out — the two differ by the wastage, and by whatever metal the kaligad put in
 * out of his own. Both are reported, so that difference is visible rather than
 * left to be inferred from a figure that only ever shows one of them.
 *
 * SETTLED IN METAL vs IN CASH is APPORTIONED, because a settlement is allocated
 * across bills while its tenders are recorded against the settlement as a
 * whole. One payment of 100,000 — half gold, half cash — spread over two bills
 * settles each of them half in gold. Anything else would have to pretend it
 * knows which rupee paid which bill, and it does not.
 *
 * WEIGHTS ARE SUMMED IN THE BASE UNIT, one row at a time. Every document
 * carries the unit it was written in, and adding a tola row to a gram row with
 * SUM() reports 10 + 5 as 15 of nothing.
 *
 * @param  array<int, int> $billIds
 * @return array<int, array<string, mixed>> keyed by bill id
 */
function jw_report_bill_metal(int $companyId, array $billIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $billIds), static fn (int $id): bool => $id > 0)));
    if ($companyId <= 0 || $ids === []) {
        return [];
    }
    $unitMap = jw_unit_map($companyId);
    $baseUnit = jewellery_base_unit($companyId);
    $baseGrams = (float) ($baseUnit['grams'] ?? 0) ?: 1.0;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // --- 1. every bill asked about, job or no job --------------------------
    $billStmt = db()->prepare("SELECT id, bill_amount, settled_amount,
            (bill_amount - settled_amount) AS outstanding
        FROM jewellery_bills WHERE company_id = ? AND id IN ($placeholders)");
    $billStmt->execute(array_merge([$companyId], $ids));
    $metal = [];
    foreach ($billStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $metal[(int) $row['id']] = [
            'has_job' => false,
            'receipt_no' => '',
            'ordered_fine' => 0.0,
            'received_fine' => 0.0,
            'qty_pieces' => 0.0,
            'bill_rate' => 0.0,
            'settled_metal_amount' => 0.0,
            'settled_metal_fine' => 0.0,
            'settled_cash_amount' => 0.0,
            'outstanding_amount' => jw_round_money((float) $row['outstanding']),
            'outstanding_fine' => 0.0,
            'base_unit' => $baseUnit,
        ];
    }
    if ($metal === []) {
        return [];
    }

    // --- 2. the job behind the kaligad ones --------------------------------
    $jobStmt = db()->prepare("SELECT b.id AS bill_id,
            r.receipt_no, r.received_fine_weight, r.avg_fine_rate, r.qty_pieces, r.unit_id AS receipt_unit_id,
            a.issued_fine_weight, a.unit_id AS assignment_unit_id
        FROM jewellery_bills b
        INNER JOIN jewellery_order_receipts r ON r.id = b.source_id AND r.company_id = b.company_id
        INNER JOIN jewellery_order_assignments a ON a.id = r.assignment_id AND a.company_id = b.company_id
        WHERE b.company_id = ? AND b.bill_type = 'karigar'
          AND b.source_type = 'jewellery_order_receipt' AND b.id IN ($placeholders)");
    $jobStmt->execute(array_merge([$companyId], $ids));
    foreach ($jobStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $billId = (int) $row['bill_id'];
        if (!isset($metal[$billId])) {
            continue;
        }
        $receiptUnitId = (int) $row['receipt_unit_id'];
        // The rate the bill was actually struck at, restated per fine BASE unit
        // so it can divide an outstanding expressed in those same terms.
        $receiptUnitGrams = (float) ($unitMap[$receiptUnitId]['grams'] ?? 0) ?: 1.0;
        $metal[$billId]['has_job'] = true;
        $metal[$billId]['receipt_no'] = (string) $row['receipt_no'];
        $metal[$billId]['ordered_fine'] = jw_weight_in_base((float) $row['issued_fine_weight'], (int) $row['assignment_unit_id'], $unitMap, $baseUnit);
        $metal[$billId]['received_fine'] = jw_weight_in_base((float) $row['received_fine_weight'], $receiptUnitId, $unitMap, $baseUnit);
        $metal[$billId]['qty_pieces'] = round((float) $row['qty_pieces'], 3);
        $metal[$billId]['bill_rate'] = jw_round_rate((float) $row['avg_fine_rate'] * ($baseGrams / $receiptUnitGrams));
    }

    // --- 3. what each posted settlement was tendered in ---------------------
    $allocStmt = db()->prepare("SELECT al.bill_id, al.amount AS allocated, s.id AS settlement_id,
            s.amount AS settlement_amount, s.mode AS settlement_mode,
            s.fine_weight AS settlement_fine, s.unit_id AS settlement_unit_id
        FROM jewellery_settlement_allocations al
        INNER JOIN jewellery_settlements s ON s.id = al.settlement_id
        WHERE al.company_id = ? AND s.status = 'posted' AND al.bill_id IN ($placeholders)");
    $allocStmt->execute(array_merge([$companyId], $ids));
    $allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($allocations !== []) {
        $settlementIds = array_values(array_unique(array_map(
            static fn (array $a): int => (int) $a['settlement_id'], $allocations)));
        $tenderPlaceholders = implode(',', array_fill(0, count($settlementIds), '?'));
        $tenderStmt = db()->prepare("SELECT settlement_id, mode, amount, fine_weight, unit_id
            FROM jewellery_settlement_tenders
            WHERE company_id = ? AND settlement_id IN ($tenderPlaceholders)");
        $tenderStmt->execute(array_merge([$companyId], $settlementIds));

        $bySettlement = [];
        foreach ($tenderStmt->fetchAll(PDO::FETCH_ASSOC) as $tender) {
            $settlementId = (int) $tender['settlement_id'];
            if (!isset($bySettlement[$settlementId])) {
                $bySettlement[$settlementId] = ['total' => 0.0, 'metal_amount' => 0.0, 'metal_fine' => 0.0];
            }
            $amount = (float) $tender['amount'];
            $bySettlement[$settlementId]['total'] += $amount;
            if ((string) $tender['mode'] === 'metal') {
                $bySettlement[$settlementId]['metal_amount'] += $amount;
                $bySettlement[$settlementId]['metal_fine'] += jw_weight_in_base(
                    (float) $tender['fine_weight'], (int) $tender['unit_id'], $unitMap, $baseUnit);
            }
        }

        // --- 4. apportion each allocation over its settlement's tenders -----
        foreach ($allocations as $allocation) {
            $billId = (int) $allocation['bill_id'];
            if (!isset($metal[$billId])) {
                continue;
            }
            $allocated = (float) $allocation['allocated'];
            $tenders = $bySettlement[(int) $allocation['settlement_id']] ?? null;

            if ($tenders !== null && $tenders['total'] > 0.005) {
                $share = $allocated / $tenders['total'];
                $metalAmount = $tenders['metal_amount'] * $share;
                $metalFine = $tenders['metal_fine'] * $share;
            } else {
                // A settlement written before tenders existed carries its way
                // of paying on its own header, and that answers just as well.
                $isMetal = (string) $allocation['settlement_mode'] === 'metal';
                $settlementAmount = (float) $allocation['settlement_amount'];
                $metalAmount = $isMetal ? $allocated : 0.0;
                $metalFine = ($isMetal && $settlementAmount > 0.005)
                    ? jw_weight_in_base((float) $allocation['settlement_fine'],
                        (int) $allocation['settlement_unit_id'], $unitMap, $baseUnit)
                        * ($allocated / $settlementAmount)
                    : 0.0;
            }

            $metal[$billId]['settled_metal_amount'] += $metalAmount;
            $metal[$billId]['settled_metal_fine'] += $metalFine;
            $metal[$billId]['settled_cash_amount'] += max(0.0, $allocated - $metalAmount);
        }
    }

    foreach ($metal as $billId => $row) {
        $metal[$billId]['settled_metal_amount'] = jw_round_money($row['settled_metal_amount']);
        $metal[$billId]['settled_metal_fine'] = jw_round_weight($row['settled_metal_fine']);
        $metal[$billId]['settled_cash_amount'] = jw_round_money($row['settled_cash_amount']);
        // The same outstanding the money column shows, said in metal — at the
        // rate the bill was struck at, which is the one rate both sides have
        // already agreed to. Only a bill with a job behind it has such a rate.
        $metal[$billId]['outstanding_fine'] = $row['bill_rate'] > 0
            ? jw_round_weight($row['outstanding_amount'] / $row['bill_rate']) : 0.0;
    }

    return $metal;
}
/**
 * Does one bill survive the column filters?
 *
 * ONE TEST PER COLUMN, in the order the table shows them, so the filter row
 * under the headings and this function can be read against each other. Text
 * matches loosely (a substring, case-insensitively) because somebody typing
 * "JRC-1" means every bill that starts that way; a numeric filter is a FLOOR —
 * "show me what is at least this big" — which is the question actually asked of
 * a money or weight column.
 *
 * An absent or blank filter is not a filter. It never narrows anything, so a
 * page with an empty filter row shows the same rows it always did.
 */
function jw_bill_matches_filters(array $bill, array $filters): bool
{
    $metal = $bill['metal'] ?? null;
    $text = static function (string $key, string $haystack) use ($filters): bool {
        $needle = trim((string) ($filters[$key] ?? ''));
        return $needle === '' || stripos($haystack, $needle) !== false;
    };
    $atLeast = static function (string $key, float $value) use ($filters): bool {
        $raw = trim((string) ($filters[$key] ?? ''));
        return $raw === '' || $value + 0.00005 >= (float) $raw;
    };

    if (!$text('party', (string) ($bill['party_name'] ?? ''))) { return false; }
    if (!$text('bill_no', (string) ($bill['bill_no'] ?? ''))) { return false; }
    $type = trim((string) ($filters['bill_type'] ?? ''));
    if ($type !== '' && (string) $bill['bill_type'] !== $type) { return false; }
    $from = trim((string) ($filters['from'] ?? ''));
    $to = trim((string) ($filters['to'] ?? ''));
    if ($from !== '' && (string) $bill['bill_date'] < $from) { return false; }
    if ($to !== '' && (string) $bill['bill_date'] > $to) { return false; }

    // The metal floors answer "nothing here" rather than "zero" on a bill with
    // no job behind it: a purchase bill is not a kaligad job that ordered 0.000
    // fine, and asking for at least some metal must not drag it into the list.
    foreach ([['ordered_min', 'ordered_fine'], ['received_min', 'received_fine']] as [$filterKey, $metalKey]) {
        if (trim((string) ($filters[$filterKey] ?? '')) === '') { continue; }
        if ($metal === null || !$metal['has_job']) { return false; }
        if (!$atLeast($filterKey, (float) $metal[$metalKey])) { return false; }
    }
    if (!$atLeast('billed_min', (float) $bill['bill_amount'])) { return false; }
    if (!$atLeast('settled_metal_min', (float) ($metal['settled_metal_amount'] ?? 0))) { return false; }
    $cash = $metal === null ? (float) $bill['settled_amount'] : (float) $metal['settled_cash_amount'];
    if (!$atLeast('settled_cash_min', $cash)) { return false; }
    if (!$atLeast('outstanding_min', (float) $bill['outstanding'])) { return false; }
    if (trim((string) ($filters['outstanding_fine_min'] ?? '')) !== '') {
        if ($metal === null || !$metal['has_job']) { return false; }
        if (!$atLeast('outstanding_fine_min', (float) $metal['outstanding_fine'])) { return false; }
    }
    $status = trim((string) ($filters['status'] ?? ''));
    if ($status !== '' && (string) $bill['status'] !== $status) { return false; }

    return true;
}

function jw_report_bill_outstanding(int $companyId, string $billType = '', int $limit = 500, int $offset = 0, array $filters = []): array
{
    $bills = jewellery_bills_list($companyId, [
        'bill_type' => $billType,
        'open_only' => true,
        'limit' => $limit,
        'offset' => $offset,
    ]);

    // The metal behind the kaligad bills, fetched once for the whole page
    // rather than per row — a party with thirty open bills was thirty round
    // trips waiting to happen.
    $metalByBill = jw_report_bill_metal($companyId, array_map('intval', array_column($bills, 'id')));

    $parties = [];
    foreach ($bills as $bill) {
        $bill['metal'] = $metalByBill[(int) $bill['id']] ?? null;
        // Filtered AFTER enrichment, because half the columns being filtered on
        // do not exist until the metal is attached. A party whose every bill is
        // filtered out never opens a group, so no empty headings are left over.
        if ($filters !== [] && !jw_bill_matches_filters($bill, $filters)) {
            continue;
        }
        $partyId = (int) $bill['party_id'];
        if (!isset($parties[$partyId])) {
            $parties[$partyId] = [
                'party_id' => $partyId, 'party_name' => (string) $bill['party_name'],
                'party_code' => (string) ($bill['party_code'] ?? ''),
                'bills' => [], 'total_billed' => 0.0, 'total_settled' => 0.0, 'outstanding' => 0.0,
                // Zeroed for every party, kaligad or not, so a caller can add
                // the columns up without first asking what kind of party it is.
                'ordered_fine' => 0.0, 'received_fine' => 0.0, 'settled_metal_amount' => 0.0,
                'settled_metal_fine' => 0.0, 'settled_cash_amount' => 0.0, 'outstanding_fine' => 0.0,
            ];
        }
        $parties[$partyId]['bills'][] = $bill;
        $parties[$partyId]['total_billed'] += (float) $bill['bill_amount'];
        $parties[$partyId]['total_settled'] += (float) $bill['settled_amount'];
        $parties[$partyId]['outstanding'] += (float) $bill['outstanding'];
        if ($bill['metal'] !== null) {
            $parties[$partyId]['ordered_fine'] += (float) $bill['metal']['ordered_fine'];
            $parties[$partyId]['received_fine'] += (float) $bill['metal']['received_fine'];
            $parties[$partyId]['settled_metal_amount'] += (float) $bill['metal']['settled_metal_amount'];
            $parties[$partyId]['settled_metal_fine'] += (float) $bill['metal']['settled_metal_fine'];
            $parties[$partyId]['settled_cash_amount'] += (float) $bill['metal']['settled_cash_amount'];
            $parties[$partyId]['outstanding_fine'] += (float) $bill['metal']['outstanding_fine'];
        }
    }
    foreach ($parties as $partyId => $party) {
        $parties[$partyId]['total_billed'] = jw_round_money($party['total_billed']);
        $parties[$partyId]['total_settled'] = jw_round_money($party['total_settled']);
        $parties[$partyId]['outstanding'] = jw_round_money($party['outstanding']);
        foreach (['settled_metal_amount', 'settled_cash_amount'] as $moneyKey) {
            $parties[$partyId][$moneyKey] = jw_round_money($party[$moneyKey]);
        }
        foreach (['ordered_fine', 'received_fine', 'settled_metal_fine', 'outstanding_fine'] as $weightKey) {
            $parties[$partyId][$weightKey] = jw_round_weight($party[$weightKey]);
        }
    }
    uasort($parties, static fn (array $a, array $b): int => $b['outstanding'] <=> $a['outstanding']);

    return array_values($parties);
}

// ---------------------------------------------------------------------------
// Dashboard summary
// ---------------------------------------------------------------------------

/**
 * THE WHOLE SHOP ON ONE PAGE — the five questions an owner actually opens the
 * books to ask, each with the handful of figures that answer it.
 *
 * The summary this replaces was seventeen tiles in a row: true, complete, and
 * no help, because reading it meant knowing which four of the seventeen went
 * together. These are grouped, and each group SAYS WHAT DATE IT IS TRUE ON,
 * which is the thing a consolidated figure most often gets wrong.
 *
 * Two bases live here, and mixing them silently is how a summary starts lying:
 *
 *   AS AT a date — stock and advances. Both are built from movements, so both
 *   can be asked honestly about any day: what was on the shelf then, what was
 *   held then. Rolling them back is real, not an estimate.
 *
 *   AS IT STANDS — receivables. A bill's settled_amount is a live figure, not a
 *   history, so pretending to date-scope it would produce something that is
 *   neither today's position nor that day's. It is reported as it stands, and
 *   says so, and agrees with the Bills screen because it is the same question.
 *
 * The period ones — sales, profit, orders taken — are simply what happened
 * between the two dates.
 *
 * Every row carries its own 'kind' so the screen and the export format it the
 * same way, from the same call. A summary whose PDF rounds differently from the
 * page it was printed from is a summary nobody trusts twice.
 *
 * @return array{from:string,to:string,sections:array<int,array<string,mixed>>}
 */
function jw_report_consolidated(int $companyId, string $from, string $to): array
{
    $money = static fn (string $label, float $value, bool $strong = false): array
        => ['label' => $label, 'value' => jw_round_money($value), 'kind' => 'money', 'strong' => $strong];
    $weight = static fn (string $label, float $value, bool $strong = false): array
        => ['label' => $label, 'value' => jw_round_weight($value), 'kind' => 'weight', 'strong' => $strong];
    $count = static fn (string $label, float $value, bool $strong = false): array
        => ['label' => $label, 'value' => $value, 'kind' => 'count', 'strong' => $strong];

    // --- 1. total stock, as at $to -----------------------------------------
    // Own shelf and other people's hands are kept apart on purpose. Metal with
    // a kaligad is the shop's asset and is NOT stock it can sell this morning,
    // and one figure covering both has answered "what have I got?" wrongly in
    // every shop that ever added them up.
    $ownFine = 0.0; $ownPieces = 0.0; $ownValue = 0.0;
    $outFine = 0.0; $outPieces = 0.0; $outValue = 0.0;
    foreach (jewellery_metal_position($companyId, $to) as $row) {
        if ((string) $row['holder_type'] === 'stock') {
            $ownFine += (float) $row['fine'];
            $ownPieces += (float) $row['pieces'];
            $ownValue += (float) $row['value'];
            continue;
        }
        $outFine += (float) $row['fine'];
        $outPieces += (float) $row['pieces'];
        $outValue += (float) $row['value'];
    }
    $baseUnit = jewellery_base_unit($companyId);
    $unitCode = (string) ($baseUnit['code'] ?? '');

    // --- 2. sales and profit, over the period ------------------------------
    $sales = jw_report_sales_detail($companyId, $from, $to);
    $billsRaised = db()->prepare("SELECT COUNT(*) FROM jewellery_sales
        WHERE company_id = :cid AND status = 'posted' AND sale_date BETWEEN :from AND :to");
    $billsRaised->execute(['cid' => $companyId, 'from' => $from, 'to' => $to]);

    // --- 3. advance outstanding, as at $to ---------------------------------
    // Taken in, less handed back, less what has already paid for a bill. The
    // remainder is money the shop is holding and still owes goods against — a
    // liability, however comfortable the bank balance looks.
    $advanceReceived = 0.0; $advanceRefunded = 0.0; $advanceApplied = 0.0;
    if (column_exists('jewellery_settlements', 'is_advance')) {
        $advStmt = db()->prepare("SELECT
                COALESCE(SUM(CASE WHEN direction = 'received' THEN amount ELSE 0 END), 0) AS taken,
                COALESCE(SUM(CASE WHEN direction = 'paid' THEN amount ELSE 0 END), 0) AS refunded
            FROM jewellery_settlements
            WHERE company_id = :cid AND is_advance = 1 AND status = 'posted' AND settlement_date <= :to");
        $advStmt->execute(['cid' => $companyId, 'to' => $to]);
        $advRow = $advStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $advanceReceived = (float) ($advRow['taken'] ?? 0);
        $advanceRefunded = (float) ($advRow['refunded'] ?? 0);

        if (table_exists('jewellery_advance_allocations')) {
            // Dated by the BILL it funded, not by when the advance arrived —
            // that is the day it stopped being money held and became money
            // earned, and it is the only date that makes the three figures add
            // up on any given morning.
            $usedStmt = db()->prepare("SELECT COALESCE(SUM(a.amount), 0)
                FROM jewellery_advance_allocations a
                INNER JOIN jewellery_sales s ON s.id = a.sale_id
                WHERE a.company_id = :cid AND s.status <> 'cancelled' AND s.sale_date <= :to");
            $usedStmt->execute(['cid' => $companyId, 'to' => $to]);
            $advanceApplied = (float) $usedStmt->fetchColumn();
        }
    }
    $advanceHeld = $advanceReceived - $advanceRefunded - $advanceApplied;

    // --- 4. customer receivables, as they stand ----------------------------
    $billTotals = jewellery_open_bill_totals($companyId);
    $recvStmt = db()->prepare("SELECT COUNT(*) AS bills, COUNT(DISTINCT party_id) AS parties,
            MIN(bill_date) AS oldest
        FROM jewellery_bills
        WHERE company_id = :cid AND bill_type = 'sale' AND status IN ('open', 'part_settled')");
    $recvStmt->execute(['cid' => $companyId]);
    $recvRow = $recvStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $oldest = (string) ($recvRow['oldest'] ?? '');
    $oldestDays = $oldest !== '' ? (int) floor((strtotime($to) - strtotime($oldest)) / 86400) : 0;

    // --- 5. orders received, over the period -------------------------------
    $orderStmt = db()->prepare("SELECT COUNT(*) AS orders,
            COALESCE(SUM(total_amount), 0) AS value,
            COALESCE(SUM(advance_amount), 0) AS advance
        FROM jewellery_orders
        WHERE company_id = :cid AND status <> 'cancelled' AND order_date BETWEEN :from AND :to");
    $orderStmt->execute(['cid' => $companyId, 'from' => $from, 'to' => $to]);
    $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $orderValue = (float) ($orderRow['value'] ?? 0);
    $orderAdvance = (float) ($orderRow['advance'] ?? 0);
    $openStmt = db()->prepare("SELECT COUNT(*) FROM jewellery_orders
        WHERE company_id = :cid AND status IN ('draft', 'confirmed', 'assigned')");
    $openStmt->execute(['cid' => $companyId]);

    return [
        'from' => $from,
        'to' => $to,
        'base_unit' => $unitCode,
        'sections' => [
            [
                'key' => 'stock', 'title' => 'Total stock', 'icon' => 'box',
                'note' => 'As at ' . $to,
                'headline' => jw_round_money($ownValue + $outValue),
                'rows' => [
                    $weight('Fine on own shelf' . ($unitCode !== '' ? ' (' . $unitCode . ')' : ''), $ownFine, true),
                    $count('Pieces on own shelf', round($ownPieces, 3)),
                    $money('Value of own stock', $ownValue, true),
                    $weight('Fine out with kaligads and refiners', $outFine),
                    $count('Pieces out with them', round($outPieces, 3)),
                    $money('Value out with them', $outValue),
                    $money('Total metal the shop owns', $ownValue + $outValue, true),
                ],
            ],
            [
                'key' => 'sales', 'title' => 'Total sales and profit', 'icon' => 'wallet',
                'note' => $from . ' to ' . $to,
                'headline' => jw_round_money((float) $sales['totals']['revenue']),
                'rows' => [
                    $money('Sales revenue', (float) $sales['totals']['revenue'], true),
                    $money('Cost of goods sold', (float) $sales['totals']['cogs_amount']),
                    $money('Gross profit', (float) $sales['totals']['gross_profit'], true),
                    [
                        'label' => 'Gross profit %',
                        'value' => $sales['totals']['gp_pct'],
                        'kind' => 'percent', 'strong' => false,
                    ],
                    $weight('Fine weight sold' . ($unitCode !== '' ? ' (' . $unitCode . ')' : ''), (float) $sales['totals']['fine_weight']),
                    $count('Bills posted', (int) $billsRaised->fetchColumn()),
                ],
            ],
            [
                'key' => 'advances', 'title' => 'Advance outstanding', 'icon' => 'card',
                'note' => 'As at ' . $to,
                'headline' => jw_round_money($advanceHeld),
                'rows' => [
                    $money('Advances taken in', $advanceReceived),
                    $money('Applied to bills', $advanceApplied),
                    $money('Refunded', $advanceRefunded),
                    $money('Still held — goods owed against it', $advanceHeld, true),
                ],
            ],
            [
                'key' => 'receivables', 'title' => 'Customer receivables', 'icon' => 'handshake',
                'note' => 'As it stands',
                'headline' => jw_round_money((float) $billTotals['receivable']),
                'rows' => [
                    $money('Owed by customers', (float) $billTotals['receivable'], true),
                    $count('Bills still open', (int) ($recvRow['bills'] ?? 0)),
                    $count('Customers owing', (int) ($recvRow['parties'] ?? 0)),
                    [
                        'label' => 'Oldest unpaid bill',
                        'value' => $oldest !== '' ? ($oldest . ' (' . $oldestDays . ' days)') : '—',
                        'kind' => 'text', 'strong' => false,
                    ],
                    $money('Owed BY the shop (suppliers and kaligads)', (float) $billTotals['payable']),
                ],
            ],
            [
                'key' => 'orders', 'title' => 'Orders received', 'icon' => 'journal',
                'note' => $from . ' to ' . $to,
                'headline' => jw_round_money($orderValue),
                'rows' => [
                    $count('Orders taken', (int) ($orderRow['orders'] ?? 0), true),
                    $money('Value ordered', $orderValue, true),
                    $money('Advance held against them', $orderAdvance),
                    $money('Balance still to collect', $orderValue - $orderAdvance),
                    $count('Orders open right now', (int) $openStmt->fetchColumn()),
                    $count('Finished, awaiting collection', count(jewellery_pending_delivery($companyId))),
                ],
            ],
        ],
    ];
}
/**
 * The consolidated summary as export rows — the file's version of the screen.
 *
 * Built here rather than in the page so the CSV, the Excel and the PDF cannot
 * drift from the table somebody printed them off. A summary whose file omits a
 * line the page shows is the worst kind of wrong: nobody notices until the
 * figure that was left out is the one being argued about.
 *
 * SECTION AND BASIS RIDE ON EVERY ROW rather than being written once as a
 * heading. A spreadsheet can then pivot on them, and a row read on its own —
 * pasted into an email, quoted in a meeting — still says what it is and what
 * date it is true on. On screen the same facts are a heading, because there the
 * eye can see the group.
 *
 * FOUR VALUE COLUMNS, NOT ONE, and each row fills exactly one of them. A single
 * Value column held rupees, fine weights, piece counts and a percentage one
 * under the other, and no spreadsheet format fits all four: money wants two
 * decimal places and a thousands separator, a fine weight wants four and loses
 * metal at two, a count wants none. One column meant every figure on the sheet
 * was written wrong except by accident. Split, each column is typed, formats
 * itself, and can be summed or pivoted without first being cleaned by hand.
 *
 * @return array<int, array<int, mixed>> first row is the header
 */
function jw_report_consolidated_export_rows(array $consolidated): array
{
    $rows = [['Section', 'Basis', 'Figure', 'Amount', 'Fine weight', 'Count', 'Percent', 'Note']];
    foreach (($consolidated['sections'] ?? []) as $section) {
        foreach (($section['rows'] ?? []) as $row) {
            $kind = (string) $row['kind'];
            $value = $row['value'];
            // A TEXT ROW GETS ITS OWN COLUMN. The oldest unpaid bill reads
            // "2026-07-21 (34 days)", and putting it in Amount cost that whole
            // column its money format: one value a spreadsheet cannot parse makes
            // every figure under it text. Kept apart, Amount stays money.
            $rows[] = [
                (string) $section['title'],
                (string) $section['note'],
                (string) $row['label'],
                $kind === 'money' ? ($value ?? '') : '',
                $kind === 'weight' ? ($value ?? '') : '',
                $kind === 'count' ? ($value ?? '') : '',
                $kind === 'percent' ? ($value ?? '') : '',
                $kind === 'text' ? (string) ($value ?? '') : '',
            ];
        }
    }

    return $rows;
}
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

    $billTotals = jewellery_open_bill_totals($companyId);
    $receivable = (float) $billTotals['receivable'];
    $payable = (float) $billTotals['payable'];

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

// ---------------------------------------------------------------------------
// Order workflow reports — the order's whole life on one line
// ---------------------------------------------------------------------------

/**
 * One row per order: where it stands, what it weighs (actual AND fine), what
 * it comes to, and how the money against it looks.
 *
 * This single query IS several of the reports a shop asks for — they differ
 * only in the status filter:
 *
 *     Order Status            no filter
 *     Pending Manufacturing   confirmed / assigned / partially_received
 *     Completed Orders        received onwards
 *     Pending Delivery        received, invoiced
 *     Customer Order History  party filter
 */
function jw_report_order_status(int $companyId, string $from, string $to, array $filters = []): array
{
    $hasAlloc = table_exists('jewellery_advance_allocations');
    $advanceSelect = $hasAlloc
        ? "COALESCE((SELECT SUM(a.amount) FROM jewellery_advance_allocations a
                INNER JOIN jewellery_sales sl ON sl.id = a.sale_id
                INNER JOIN jewellery_settlements st2 ON st2.id = a.settlement_id
                WHERE st2.order_id = o.id AND a.company_id = o.company_id AND sl.status <> 'cancelled'), 0)"
        : '0';
    $sql = "SELECT o.id, o.order_no, o.order_date, o.delivery_date, o.status,
                COALESCE(ap.name, o.customer_name, 'Walk-in') AS party_label, o.party_id,
                m.name AS metal_name, p.code AS purity_code, u.code AS unit_code,
                o.expected_gross_weight, o.expected_fine_weight, o.total_amount,
                (SELECT COUNT(*) FROM jewellery_order_lines l WHERE l.order_id = o.id) AS item_count,
                COALESCE((SELECT SUM(st.amount * IF(st.direction = 'received', 1, -1))
                    FROM jewellery_settlements st
                    WHERE st.order_id = o.id AND st.company_id = o.company_id
                      AND st.is_advance = 1 AND st.status = 'posted'), 0) AS advance_held,
                $advanceSelect AS advance_applied,
                s.sale_no, s.sale_date, s.total_amount AS billed_amount, s.balance_amount
            FROM jewellery_orders o
            INNER JOIN jewellery_metals m ON m.id = o.metal_id
            INNER JOIN jewellery_purities p ON p.id = o.purity_id
            INNER JOIN jewellery_units u ON u.id = o.unit_id
            LEFT JOIN accounting_parties ap ON ap.id = o.party_id
            LEFT JOIN jewellery_sales s ON s.id = o.delivered_sale_id
            WHERE o.company_id = :cid AND o.order_date BETWEEN :from AND :to";
    $params = ['cid' => $companyId, 'from' => $from, 'to' => $to];
    if (($filters['status'] ?? '') !== '') {
        $sql .= ' AND o.status = :status';
        $params['status'] = (string) $filters['status'];
    }
    if (!empty($filters['party_id'])) {
        $sql .= ' AND o.party_id = :pid';
        $params['pid'] = (int) $filters['party_id'];
    }
    $sql .= ' ORDER BY o.order_date ASC, o.id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totals = ['orders' => count($rows), 'expected_fine' => 0.0, 'total_amount' => 0.0,
        'advance_held' => 0.0, 'advance_applied' => 0.0, 'by_status' => []];
    foreach ($rows as $index => $row) {
        $rows[$index]['advance_unapplied'] = jw_round_money((float) $row['advance_held'] - (float) $row['advance_applied']);
        $totals['expected_fine'] += (float) $row['expected_fine_weight'];
        $totals['total_amount'] += (float) $row['total_amount'];
        $totals['advance_held'] += (float) $row['advance_held'];
        $totals['advance_applied'] += (float) $row['advance_applied'];
        $totals['by_status'][(string) $row['status']] = ($totals['by_status'][(string) $row['status']] ?? 0) + 1;
    }
    $totals['expected_fine'] = jw_round_weight($totals['expected_fine']);
    foreach (['total_amount', 'advance_held', 'advance_applied'] as $key) {
        $totals[$key] = jw_round_money($totals[$key]);
    }

    return ['rows' => $rows, 'totals' => $totals];
}

/**
 * The workshop register: every issue in the period and what has come back
 * against it. An issue with no receipt IS the metal still out — so this one
 * table, filtered and grouped, answers Gold Issued to Kaligad, Gold Pending
 * Return, Kaligad-wise Production and Purity-wise Manufacturing.
 */
function jw_report_workshop(int $companyId, string $from, string $to, array $filters = []): array
{
    $sql = "SELECT a.id, a.issue_no, a.issue_date, a.expected_return_date, a.status,
                a.issued_gross_weight, a.issued_fine_weight, a.issued_amount,
                k.code AS karigar_code, k.name AS karigar_name,
                o.order_no, i.sku AS item_code, p.code AS purity_code, u.code AS unit_code,
                r.receipt_no, r.receive_date, r.received_gross_weight, r.received_fine_weight,
                r.wastage_fine_weight, r.excess_wastage_fine, r.making_amount, r.net_payable
            FROM jewellery_order_assignments a
            INNER JOIN jewellery_karigars k ON k.id = a.karigar_id
            INNER JOIN inventory_items i ON i.id = a.item_id
            INNER JOIN jewellery_purities p ON p.id = a.purity_id
            INNER JOIN jewellery_units u ON u.id = a.unit_id
            LEFT JOIN jewellery_orders o ON o.id = a.order_id
            LEFT JOIN jewellery_order_receipts r ON r.assignment_id = a.id AND r.status = 'posted'
            WHERE a.company_id = :cid AND a.issue_date BETWEEN :from AND :to
              AND a.status <> 'cancelled'";
    $params = ['cid' => $companyId, 'from' => $from, 'to' => $to];
    if (!empty($filters['karigar_id'])) {
        $sql .= ' AND a.karigar_id = :kid';
        $params['kid'] = (int) $filters['karigar_id'];
    }
    if (!empty($filters['pending_only'])) {
        $sql .= " AND a.status = 'issued'";
    }
    $sql .= ' ORDER BY a.issue_date ASC, a.id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totals = ['issued_fine' => 0.0, 'received_fine' => 0.0, 'pending_fine' => 0.0,
        'wastage_fine' => 0.0, 'making_amount' => 0.0];
    $byKarigar = [];
    $byPurity = [];
    $tally = static function (array &$bucket, string $key, array $row, float $pending): void {
        $bucket[$key] = $bucket[$key] ?? ['label' => $key, 'issues' => 0, 'issued_fine' => 0.0,
            'received_fine' => 0.0, 'pending_fine' => 0.0, 'wastage_fine' => 0.0, 'making_amount' => 0.0];
        $bucket[$key]['issues']++;
        $bucket[$key]['issued_fine'] += (float) $row['issued_fine_weight'];
        $bucket[$key]['received_fine'] += (float) ($row['received_fine_weight'] ?? 0);
        $bucket[$key]['pending_fine'] += $pending;
        $bucket[$key]['wastage_fine'] += (float) ($row['wastage_fine_weight'] ?? 0);
        $bucket[$key]['making_amount'] += (float) ($row['making_amount'] ?? 0);
    };
    foreach ($rows as $index => $row) {
        $issuedFine = (float) $row['issued_fine_weight'];
        $pending = (string) $row['status'] === 'issued' ? $issuedFine : 0.0;
        $rows[$index]['pending_fine'] = jw_round_weight($pending);
        $totals['issued_fine'] += $issuedFine;
        $totals['received_fine'] += (float) ($row['received_fine_weight'] ?? 0);
        $totals['pending_fine'] += $pending;
        $totals['wastage_fine'] += (float) ($row['wastage_fine_weight'] ?? 0);
        $totals['making_amount'] += (float) ($row['making_amount'] ?? 0);
        $tally($byKarigar, $row['karigar_code'] . ' — ' . $row['karigar_name'], $row, $pending);
        $tally($byPurity, (string) $row['purity_code'], $row, $pending);
    }
    foreach (['issued_fine', 'received_fine', 'pending_fine', 'wastage_fine'] as $key) {
        $totals[$key] = jw_round_weight($totals[$key]);
    }
    $totals['making_amount'] = jw_round_money($totals['making_amount']);

    return ['rows' => $rows, 'totals' => $totals,
        'by_karigar' => array_values($byKarigar), 'by_purity' => array_values($byPurity)];
}

/**
 * The advance register and its adjustments — both sides of the same rows.
 *
 * Every advance ENTRY in the period, with what it has funded and what it
 * still holds; and every ADJUSTMENT — an allocation row saying "this bill
 * took this much from that entry", the record migration 094 made possible.
 * History is never netted away: a fully-consumed entry still lists, showing
 * where every rupee of it went.
 */
function jw_report_advance_register(int $companyId, string $from, string $to, array $filters = []): array
{
    if (!column_exists('jewellery_settlements', 'is_advance')) {
        return ['rows' => [], 'adjustments' => [],
            'totals' => ['received' => 0.0, 'refunded' => 0.0, 'allocated' => 0.0, 'remaining' => 0.0]];
    }
    $sql = "SELECT st.id, st.settlement_no, st.settlement_date, st.direction, st.mode, st.amount,
                st.gross_weight, st.fine_weight, st.status,
                COALESCE(ap.name, 'Unknown') AS party_label, st.party_id,
                o.order_no, i.sku AS item_code, p.code AS purity_code, u.code AS unit_code
            FROM jewellery_settlements st
            LEFT JOIN accounting_parties ap ON ap.id = st.party_id
            LEFT JOIN jewellery_orders o ON o.id = st.order_id
            LEFT JOIN inventory_items i ON i.id = st.item_id
            LEFT JOIN jewellery_purities p ON p.id = st.purity_id
            LEFT JOIN jewellery_units u ON u.id = st.unit_id
            WHERE st.company_id = :cid AND st.is_advance = 1 AND st.status = 'posted'
              AND st.settlement_date BETWEEN :from AND :to";
    $params = ['cid' => $companyId, 'from' => $from, 'to' => $to];
    if (!empty($filters['party_id'])) {
        $sql .= ' AND st.party_id = :pid';
        $params['pid'] = (int) $filters['party_id'];
    }
    $sql .= ' ORDER BY st.settlement_date ASC, st.id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $allocated = [];
    $adjustments = [];
    if (table_exists('jewellery_advance_allocations') && $rows !== []) {
        $ids = implode(',', array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $aStmt = db()->prepare("SELECT a.settlement_id, a.amount, a.created_at,
                s.sale_no, s.sale_date, s.status AS sale_status, st.settlement_no,
                COALESCE(ap.name, s.customer_name, 'Walk-in') AS party_label
            FROM jewellery_advance_allocations a
            INNER JOIN jewellery_sales s ON s.id = a.sale_id
            INNER JOIN jewellery_settlements st ON st.id = a.settlement_id
            LEFT JOIN accounting_parties ap ON ap.id = s.party_id
            WHERE a.company_id = :cid AND a.settlement_id IN ($ids) AND s.status <> 'cancelled'
            ORDER BY s.sale_date ASC, a.id ASC");
        $aStmt->execute(['cid' => $companyId]);
        foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $alloc) {
            $allocated[(int) $alloc['settlement_id']] = ($allocated[(int) $alloc['settlement_id']] ?? 0.0) + (float) $alloc['amount'];
            $adjustments[] = $alloc;
        }
    }

    $totals = ['received' => 0.0, 'refunded' => 0.0, 'allocated' => 0.0, 'remaining' => 0.0];
    foreach ($rows as $index => $row) {
        $isReceipt = (string) $row['direction'] === 'received';
        $used = jw_round_money($allocated[(int) $row['id']] ?? 0.0);
        $rows[$index]['allocated'] = $isReceipt ? $used : 0.0;
        $rows[$index]['remaining'] = $isReceipt ? jw_round_money((float) $row['amount'] - $used) : 0.0;
        if ($isReceipt) {
            $totals['received'] += (float) $row['amount'];
            $totals['allocated'] += $used;
        } else {
            $totals['refunded'] += (float) $row['amount'];
        }
    }
    // What the period's entries still hold, after what they funded and what
    // was handed back. Refunds are period-scoped like everything else here.
    $totals['remaining'] = jw_round_money($totals['received'] - $totals['allocated'] - $totals['refunded']);
    foreach (['received', 'refunded', 'allocated'] as $key) {
        $totals[$key] = jw_round_money($totals[$key]);
    }

    return ['rows' => $rows, 'adjustments' => $adjustments, 'totals' => $totals];
}

/**
 * What each delivered order actually made, all the costs on one line: the
 * bill's revenue and its cost of metal sold, and the workshop's wages and
 * unrecovered wastage for THAT order's assignments. The making charge on the
 * bill was meant to cover the wages — this puts the two side by side so the
 * shop can see whether it did.
 */
function jw_report_order_profitability(int $companyId, string $from, string $to): array
{
    $sql = "SELECT o.id, o.order_no, o.order_date, o.status,
                COALESCE(ap.name, o.customer_name, 'Walk-in') AS party_label,
                s.sale_no, s.sale_date,
                COALESCE((SELECT SUM(l.metal_amount + l.making_amount + l.stone_amount
                        + l.diamond_amount + l.other_diamond_amount + l.allocated_adjust)
                    FROM jewellery_sale_lines l WHERE l.sale_id = s.id), 0) AS revenue,
                COALESCE((SELECT SUM(l.cogs_amount) FROM jewellery_sale_lines l WHERE l.sale_id = s.id), 0) AS cogs,
                COALESCE((SELECT SUM(r.making_amount)
                    FROM jewellery_order_receipts r
                    INNER JOIN jewellery_order_assignments a ON a.id = r.assignment_id
                    WHERE a.order_id = o.id AND r.status = 'posted'), 0) AS karigar_wages,
                COALESCE((SELECT SUM(r.wastage_amount - r.recovery_amount)
                    FROM jewellery_order_receipts r
                    INNER JOIN jewellery_order_assignments a ON a.id = r.assignment_id
                    WHERE a.order_id = o.id AND r.status = 'posted'), 0) AS wastage_borne
            FROM jewellery_orders o
            INNER JOIN jewellery_sales s ON s.id = o.delivered_sale_id AND s.status = 'posted'
            LEFT JOIN accounting_parties ap ON ap.id = o.party_id
            WHERE o.company_id = :cid AND s.sale_date BETWEEN :from AND :to
            ORDER BY s.sale_date ASC, o.id ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute(['cid' => $companyId, 'from' => $from, 'to' => $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totals = ['revenue' => 0.0, 'cogs' => 0.0, 'karigar_wages' => 0.0, 'wastage_borne' => 0.0, 'profit' => 0.0];
    foreach ($rows as $index => $row) {
        // COGS carries the metal at cost; the wages and the wastage the shop
        // bore are FURTHER real costs of getting this order made. The metal
        // already counted once in COGS is not counted again here — wages and
        // wastage-borne are the workshop's money legs, not its metal legs.
        $profit = jw_round_money((float) $row['revenue'] - (float) $row['cogs']
            - (float) $row['karigar_wages'] - (float) $row['wastage_borne']);
        $rows[$index]['profit'] = $profit;
        $rows[$index]['margin_pct'] = (float) $row['revenue'] > 0
            ? round($profit / (float) $row['revenue'] * 100, 2) : null;
        $totals['revenue'] += (float) $row['revenue'];
        $totals['cogs'] += (float) $row['cogs'];
        $totals['karigar_wages'] += (float) $row['karigar_wages'];
        $totals['wastage_borne'] += (float) $row['wastage_borne'];
        $totals['profit'] += $profit;
    }
    foreach ($totals as $key => $value) {
        $totals[$key] = jw_round_money($value);
    }
    $totals['margin_pct'] = $totals['revenue'] > 0 ? round($totals['profit'] / $totals['revenue'] * 100, 2) : null;

    return ['rows' => $rows, 'totals' => $totals];
}
