<?php
declare(strict_types=1);

/**
 * The management pack: one period, read several ways, in one workbook.
 *
 * The hospitality reports tab answered one question — what did the recipes say
 * the gross profit was — and answered it in KPI tiles. What a manager brings to
 * a monthly meeting is a different thing: sales as 100% with everything else
 * measured against it, what each category cost to serve, which dishes carry the
 * business and which are carried, how the money came in, what was bought, and
 * whether any of it moved since last period.
 *
 * Every section here is a READING of what is already recorded. Nothing in this
 * file posts, and nothing accumulates its own copy of a figure that exists
 * elsewhere: the costed sales come from the costing runs, the profit and loss
 * from the ledger, the purchases from stock movements, the receipts from the
 * uploaded invoice sheet. A pack that kept its own totals would eventually
 * disagree with the books, and the disagreement is what nobody would notice.
 *
 * WHAT IS ESTIMATED IS SAID TO BE ESTIMATED. Recipe costing is a reference
 * figure built from configured recipes and reference ingredient prices; it is
 * not posted cost of goods sold. Every section built on it carries that on its
 * face, because a management pack that reads as though it came out of the
 * ledger will be quoted as though it did.
 */

/**
 * The sections a pack can carry, in the order they read.
 *
 * key => [label, one-line description of what it answers]
 */
function hospitality_pack_sections(): array
{
    return [
        'pl' => ['Profit & Loss (common size)', 'Sales as 100%, with every other line measured against it.'],
        'category' => ['Category performance', 'What each category sold, cost to serve, and earned.'],
        'items_top' => ['Best and worst sellers', 'The dishes carrying the period, and the ones being carried.'],
        'items_gp' => ['Menu items by GP ratio', 'Ranked by margin rather than by turnover.'],
        'payments' => ['How the money came in', 'Receipts by payment method.'],
        'purchases' => ['Purchase analysis', 'Most and least bought, with what they cost and at what rate.'],
        'service_charge' => ['Service charge', 'Collected, and what went to staff.'],
        'comparison' => ['Period comparison', 'This period against the one before it, and day by day.'],
    ];
}

/** The sections chosen, cleaned — an unknown key is dropped, not guessed at. */
function hospitality_pack_normalise(array $wanted): array
{
    $known = hospitality_pack_sections();
    $out = [];
    foreach ($wanted as $key) {
        $key = (string) $key;
        if (isset($known[$key]) && !in_array($key, $out, true)) {
            $out[] = $key;
        }
    }
    // Nothing chosen is a request for the whole pack rather than an empty file.
    return $out === [] ? array_keys($known) : $out;
}

/** A section in the one shape every consumer of this file expects. */
function hospitality_pack_section(string $title, array $columns, array $rows, array $totals = [], string $note = ''): array
{
    return ['title' => $title, 'columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'note' => $note];
}

/**
 * Build the chosen sections for one period.
 *
 * @param string[] $wanted section keys; empty means all of them
 * @return array<string, array> section key => section
 */
function hospitality_pack_build(int $companyId, string $from, string $to, array $wanted = []): array
{
    $sections = hospitality_pack_normalise($wanted);
    $pack = [];
    foreach ($sections as $key) {
        $built = match ($key) {
            'pl' => hospitality_pack_pl($companyId, $from, $to),
            'category' => hospitality_pack_category($companyId, $from, $to),
            'items_top' => hospitality_pack_items_top($companyId, $from, $to),
            'items_gp' => hospitality_pack_items_gp($companyId, $from, $to),
            'payments' => hospitality_pack_payments($companyId, $from, $to),
            'purchases' => hospitality_pack_purchases($companyId, $from, $to),
            'service_charge' => hospitality_pack_service_charge($companyId, $from, $to),
            'comparison' => hospitality_pack_comparison($companyId, $from, $to),
            default => null,
        };
        if ($built !== null) {
            $pack[$key] = $built;
        }
    }

    return $pack;
}

/** A percentage of sales, or null when there were no sales to be a share of. */
function hospitality_pack_share(float $amount, float $sales): ?float
{
    return abs($sales) > 0.004 ? round($amount / $sales * 100, 2) : null;
}

// ---------------------------------------------------------------------------
// 1. Profit and loss, as a common size statement
// ---------------------------------------------------------------------------
/**
 * SALES AS 100%, AND EVERYTHING ELSE AGAINST IT.
 *
 * A restaurant is run on percentages: food cost at 32% is a different business
 * from food cost at 41%, and the rupee figure alone does not say which one you
 * have. The lines come from the LEDGER rather than from the costing runs, so
 * this is the real statement -- what was actually posted -- with a share column
 * beside it.
 */
function hospitality_pack_pl(int $companyId, string $from, string $to): array
{
    require_once __DIR__ . '/reports_engine.php';
    $figures = rc_pl_figures($companyId, $from, $to);
    $sales = (float) $figures['net_sales'];

    $columns = [
        ['label', 'Particulars', 'left'],
        ['amount', 'Amount', 'right'],
        ['share', '% of net sales', 'right'],
    ];
    $line = static function (string $label, float $amount) use ($sales): array {
        $share = hospitality_pack_share($amount, $sales);

        return ['label' => $label, 'amount' => round($amount, 2), 'share' => $share, 'emphasis' => ''];
    };
    $rows = [];
    $rows[] = $line('Gross sales', (float) $figures['gross_sales']);
    if (abs((float) $figures['sales_returns']) > 0.004) {
        $rows[] = $line('Less: sales returns', -(float) $figures['sales_returns']);
    }
    $rows[] = ['label' => 'NET SALES', 'amount' => round($sales, 2), 'share' => $sales != 0.0 ? 100.0 : null, 'emphasis' => 'total'];
    $rows[] = $line('Cost of goods sold', (float) $figures['cogs']);
    $rows[] = ['label' => 'GROSS PROFIT', 'amount' => round((float) $figures['gross_profit'], 2),
        'share' => hospitality_pack_share((float) $figures['gross_profit'], $sales), 'emphasis' => 'total'];
    if (abs((float) $figures['other_income']) > 0.004) {
        $rows[] = $line('Other operating income', (float) $figures['other_income']);
    }
    // The three a hospitality manager asks for by name, drawn out of operating
    // expenses rather than left inside one lump. What is left keeps the label
    // "other operating expenses" so the column still adds up.
    $namedExpenses = 0.0;
    foreach ([['Employee cost', 'employee_cost'], ['Depreciation', 'depreciation']] as [$label, $key]) {
        $amount = (float) ($figures[$key] ?? 0);
        if (abs($amount) > 0.004) {
            $rows[] = $line($label, $amount);
            $namedExpenses += $amount;
        }
    }
    $otherOperating = (float) $figures['operating_expenses'] - $namedExpenses;
    if (abs($otherOperating) > 0.004) {
        $rows[] = $line('Other operating expenses', $otherOperating);
    }
    $rows[] = ['label' => 'OPERATING PROFIT', 'amount' => round((float) $figures['operating_profit'], 2),
        'share' => hospitality_pack_share((float) $figures['operating_profit'], $sales), 'emphasis' => 'total'];
    if (abs((float) $figures['finance_cost']) > 0.004) {
        $rows[] = $line('Finance cost', (float) $figures['finance_cost']);
    }
    if (abs((float) $figures['income_tax']) > 0.004) {
        $rows[] = $line('Income tax', (float) $figures['income_tax']);
    }
    $rows[] = ['label' => 'PROFIT AFTER TAX', 'amount' => round((float) $figures['pat'], 2),
        'share' => hospitality_pack_share((float) $figures['pat'], $sales), 'emphasis' => 'total'];

    return hospitality_pack_section('Profit & Loss (common size)', $columns, $rows, [],
        'Posted figures from the ledger. Every line is shown as a share of net sales, which is what a food'
        . ' cost or a wage cost is actually judged on. Rent, utilities and the rest sit inside operating'
        . ' expenses at whatever detail the chart of accounts carries.');
}

// ---------------------------------------------------------------------------
// 2. Category performance
// ---------------------------------------------------------------------------
function hospitality_pack_category(int $companyId, string $from, string $to): array
{
    $rows = hospitality_grouped($companyId, $from, $to, 'category');
    $salesTotal = 0.0;
    foreach ($rows as $row) {
        $salesTotal += (float) $row['net_sales'];
    }

    $columns = [
        ['group', 'Category', 'left'],
        ['net_qty', 'Qty', 'right'],
        ['gross_sales', 'Gross sales', 'right'],
        ['discount', 'Discount', 'right'],
        ['net_sales', 'Net sales', 'right'],
        ['share', '% of sales', 'right'],
        ['est_cost', 'Est. recipe cost', 'right'],
        ['est_gp', 'Est. gross profit', 'right'],
        ['gp_pct', 'GP %', 'right'],
    ];
    $out = [];
    $totals = ['net_qty' => 0.0, 'gross_sales' => 0.0, 'discount' => 0.0, 'net_sales' => 0.0,
        'est_cost' => 0.0, 'est_gp' => 0.0];
    foreach ($rows as $row) {
        $out[] = [
            'group' => (string) $row['group'],
            'net_qty' => (float) $row['net_qty'],
            'gross_sales' => (float) $row['gross_sales'],
            'discount' => (float) $row['discount'],
            'net_sales' => (float) $row['net_sales'],
            'share' => hospitality_pack_share((float) $row['net_sales'], $salesTotal),
            'est_cost' => (float) $row['est_cost'],
            'est_gp' => (float) $row['est_gp'],
            'gp_pct' => $row['gp_pct'],
            'emphasis' => '',
        ];
        foreach (array_keys($totals) as $field) {
            $totals[$field] += (float) $row[$field];
        }
    }
    $totals['share'] = $salesTotal != 0.0 ? 100.0 : null;
    $totals['gp_pct'] = hospitality_pack_share($totals['est_gp'], $totals['net_sales']);

    return hospitality_pack_section('Category performance', $columns, $out, $totals,
        'Cost and gross profit are ESTIMATES from configured recipes and reference ingredient prices.'
        . ' They are not posted cost of goods sold; the ledger figure is on the profit and loss sheet.');
}

// ---------------------------------------------------------------------------
// 3. Best and worst sellers
// ---------------------------------------------------------------------------
/**
 * The dishes carrying the period, and the ones being carried.
 *
 * By VALUE and by QUANTITY, because they answer different questions and a shop
 * that only ranks by value never notices the cheap thing everyone orders.
 */
function hospitality_pack_items_top(int $companyId, string $from, string $to, int $limit = 10): array
{
    $items = hospitality_grouped($companyId, $from, $to, 'menu_item');
    $columns = [
        ['rank', 'Rank', 'left'],
        ['basis', 'Ranked by', 'left'],
        ['group', 'Menu item', 'left'],
        ['net_qty', 'Qty sold', 'right'],
        ['net_sales', 'Net sales', 'right'],
        ['est_cost', 'Est. cost', 'right'],
        ['est_gp', 'Est. gross profit', 'right'],
        ['gp_pct', 'GP %', 'right'],
    ];

    $take = static function (array $sorted, string $basis, string $band) use ($limit): array {
        $out = [];
        $rank = 0;
        foreach (array_slice($sorted, 0, $limit) as $row) {
            $rank++;
            $out[] = [
                'rank' => $band . ' ' . $rank,
                'basis' => $basis,
                'group' => (string) $row['group'],
                'net_qty' => (float) $row['net_qty'],
                'net_sales' => (float) $row['net_sales'],
                'est_cost' => (float) $row['est_cost'],
                'est_gp' => (float) $row['est_gp'],
                'gp_pct' => $row['gp_pct'],
                'emphasis' => '',
            ];
        }

        return $out;
    };

    $byValue = $items;
    usort($byValue, static fn (array $a, array $b): int => (float) $b['net_sales'] <=> (float) $a['net_sales']);
    $byQty = $items;
    usort($byQty, static fn (array $a, array $b): int => (float) $b['net_qty'] <=> (float) $a['net_qty']);

    $rows = [];
    $rows = array_merge($rows, $take($byValue, 'Sales value', 'Best'));
    $rows = array_merge($rows, $take(array_reverse($byValue), 'Sales value', 'Worst'));
    $rows = array_merge($rows, $take($byQty, 'Quantity', 'Best'));
    $rows = array_merge($rows, $take(array_reverse($byQty), 'Quantity', 'Worst'));

    return hospitality_pack_section('Best and worst sellers', $columns, $rows, [],
        'Ranked both ways on purpose: by value, and by how many went out of the door. A cheap item everybody'
        . ' orders and an expensive one nobody does look identical on a value ranking alone.'
        . ' Cost and gross profit are recipe estimates.');
}

// ---------------------------------------------------------------------------
// 4. Menu items by GP ratio
// ---------------------------------------------------------------------------
function hospitality_pack_items_gp(int $companyId, string $from, string $to): array
{
    $items = hospitality_grouped($companyId, $from, $to, 'menu_item');
    // An item with no costed sales has no ratio -- not a ratio of nought.
    $rated = array_values(array_filter($items, static fn (array $r): bool => $r['gp_pct'] !== null));
    usort($rated, static fn (array $a, array $b): int => (float) $b['gp_pct'] <=> (float) $a['gp_pct']);

    $columns = [
        ['group', 'Menu item', 'left'],
        ['net_qty', 'Qty sold', 'right'],
        ['net_sales', 'Net sales', 'right'],
        ['est_cost', 'Est. cost', 'right'],
        ['est_gp', 'Est. gross profit', 'right'],
        ['gp_pct', 'GP %', 'right'],
        ['gp_contribution_pct', '% of total GP', 'right'],
    ];
    $rows = [];
    foreach ($rated as $row) {
        $rows[] = [
            'group' => (string) $row['group'],
            'net_qty' => (float) $row['net_qty'],
            'net_sales' => (float) $row['net_sales'],
            'est_cost' => (float) $row['est_cost'],
            'est_gp' => (float) $row['est_gp'],
            'gp_pct' => $row['gp_pct'],
            'gp_contribution_pct' => $row['gp_contribution_pct'] ?? null,
            'emphasis' => '',
        ];
    }
    $uncosted = count($items) - count($rated);

    return hospitality_pack_section('Menu items by GP ratio', $columns, $rows, [],
        'Cost and margin here are ESTIMATES from configured recipes and reference ingredient prices, not posted'
        . ' cost of goods sold. Highest margin first. An item whose sales were never costed has no ratio and is left out rather than'
        . ' shown as nought' . ($uncosted > 0 ? ' — ' . $uncosted . ' item(s) here' : '')
        . '. A high ratio on two covers a month is worth less than a lower one on two hundred, which is what'
        . ' the "% of total GP" column is for.');
}

// ---------------------------------------------------------------------------
// 5. How the money came in
// ---------------------------------------------------------------------------
/**
 * Receipts by payment method, from the uploaded invoice sheet.
 *
 * The sheet carries how each invoice was settled -- cash, card, a wallet, on
 * credit -- and nothing was reading it. A shop that does not know its card
 * share cannot argue about the fee on it.
 */
function hospitality_pack_payments(int $companyId, string $from, string $to): array
{
    $columns = [
        ['method', 'Payment method', 'left'],
        ['ledger', 'Posted to', 'left'],
        ['invoices', 'Invoices', 'right'],
        ['gross', 'Gross', 'right'],
        ['discount', 'Discount', 'right'],
        ['taxable', 'Taxable', 'right'],
        ['vat', 'VAT', 'right'],
        ['total', 'Total received', 'right'],
        ['share', '% of takings', 'right'],
    ];
    if (!table_exists('hospitality_sales_invoice_lines')) {
        return hospitality_pack_section('How the money came in', $columns, [], [],
            'The invoice sheet is what records how a bill was settled, and this company has not uploaded one yet.');
    }
    $stmt = db()->prepare("SELECT i.payment_type AS method,
            COALESCE(l.name, CONCAT('(unmatched code ', i.ledger_code, ')')) AS ledger,
            COUNT(*) AS invoices, SUM(i.gross_amount) AS gross, SUM(i.discount) AS discount,
            SUM(i.taxable_amount) AS taxable, SUM(i.vat_amount) AS vat, SUM(i.total_amount) AS total
        FROM hospitality_sales_invoice_lines i
        LEFT JOIN ledgers l ON l.id = i.ledger_id AND l.company_id = i.company_id
        WHERE i.company_id = :cid AND i.sale_date BETWEEN :from AND :to
        GROUP BY i.payment_type, i.ledger_id, i.ledger_code
        ORDER BY total DESC");
    $stmt->execute(['cid' => $companyId, 'from' => $from, 'to' => $to]);
    $found = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grand = 0.0;
    foreach ($found as $row) {
        $grand += (float) $row['total'];
    }
    $rows = [];
    $totals = ['invoices' => 0, 'gross' => 0.0, 'discount' => 0.0, 'taxable' => 0.0, 'vat' => 0.0, 'total' => 0.0];
    foreach ($found as $row) {
        $rows[] = [
            'method' => (string) ($row['method'] !== '' ? $row['method'] : 'Unstated'),
            'ledger' => (string) $row['ledger'],
            'invoices' => (int) $row['invoices'],
            'gross' => (float) $row['gross'],
            'discount' => (float) $row['discount'],
            'taxable' => (float) $row['taxable'],
            'vat' => (float) $row['vat'],
            'total' => (float) $row['total'],
            'share' => hospitality_pack_share((float) $row['total'], $grand),
            'emphasis' => '',
        ];
        foreach (array_keys($totals) as $field) {
            $totals[$field] += (float) $row[$field];
        }
    }
    $totals['share'] = $grand != 0.0 ? 100.0 : null;

    return hospitality_pack_section('How the money came in', $columns, $rows, $totals,
        'Taken from the uploaded invoice sheet, which records how each bill was settled, and shown against the'
        . ' ledger each method posts to. A method the sheet names but the mapping does not recognise is listed'
        . ' as an unmatched code rather than folded in silently.');
}

// ---------------------------------------------------------------------------
// 6. Purchase analysis
// ---------------------------------------------------------------------------
function hospitality_pack_purchases(int $companyId, string $from, string $to): array
{
    $columns = [
        ['sku', 'Code', 'left'],
        ['name', 'Item', 'left'],
        ['unit', 'Unit', 'left'],
        ['qty', 'Qty bought', 'right'],
        ['amount', 'Cost', 'right'],
        ['rate', 'Avg rate', 'right'],
        ['movements', 'Times bought', 'right'],
        ['share', '% of spend', 'right'],
    ];
    if (!table_exists('inventory_transactions')) {
        return hospitality_pack_section('Purchase analysis', $columns, [], [],
            'Purchases are read from stock movements, and this company records none yet.');
    }
    $stmt = db()->prepare("SELECT i.sku, i.name, i.unit,
            SUM(t.qty_in) AS qty, SUM(t.qty_in * t.rate) AS amount, COUNT(*) AS movements
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id AND i.company_id = t.company_id
        WHERE t.company_id = :cid AND t.transaction_date BETWEEN :from AND :to
          AND t.transaction_type IN ('purchase', 'opening') AND t.qty_in > 0
        GROUP BY t.item_id, i.sku, i.name, i.unit
        ORDER BY amount DESC");
    $stmt->execute(['cid' => $companyId, 'from' => $from, 'to' => $to]);
    $found = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spend = 0.0;
    foreach ($found as $row) {
        $spend += (float) $row['amount'];
    }
    $rows = [];
    $totals = ['qty' => 0.0, 'amount' => 0.0, 'movements' => 0];
    foreach ($found as $row) {
        $qty = (float) $row['qty'];
        $amount = (float) $row['amount'];
        $rows[] = [
            'sku' => (string) $row['sku'],
            'name' => (string) $row['name'],
            'unit' => (string) $row['unit'],
            'qty' => $qty,
            'amount' => $amount,
            // The rate PAID on average across the period, which is the figure a
            // buyer argues with a supplier about -- not the item's list rate.
            'rate' => $qty > 0.0005 ? round($amount / $qty, 2) : 0.0,
            'movements' => (int) $row['movements'],
            'share' => hospitality_pack_share($amount, $spend),
            'emphasis' => '',
        ];
        $totals['qty'] += $qty;
        $totals['amount'] += $amount;
        $totals['movements'] += (int) $row['movements'];
    }
    $totals['share'] = $spend != 0.0 ? 100.0 : null;
    $totals['rate'] = null;

    return hospitality_pack_section('Purchase analysis', $columns, $rows, $totals,
        'Biggest spend first, so the last rows are the least bought. Average rate is what was actually paid'
        . ' across the period rather than the item\'s list rate, because that is the figure worth taking to a'
        . ' supplier. Quantities are not added across different units.');
}

// ---------------------------------------------------------------------------
// 7. Service charge
// ---------------------------------------------------------------------------
function hospitality_pack_service_charge(int $companyId, string $from, string $to): array
{
    $columns = [
        ['run', 'Run', 'left'],
        ['status', 'Status', 'left'],
        ['method', 'Allocation', 'left'],
        ['declared_total', 'Declared', 'right'],
        ['employee_pool', 'Staff pool', 'right'],
        ['employer_share', 'House share', 'right'],
        ['allocated', 'Allocated to staff', 'right'],
        ['headcount', 'Staff', 'right'],
        ['per_head', 'Average per head', 'right'],
    ];
    $note = 'What was declared as service charge, how it split between the staff pool and the house, and what'
        . ' actually reached staff. The run is made in Payroll; this only reports it. A pool that does not'
        . ' equal what was allocated means a run that has not been approved yet.';
    if (!table_exists('payroll_service_charge_runs') || !table_exists('payroll_service_charge_allocations')) {
        return hospitality_pack_section('Service charge', $columns, [], [],
            'Service charge runs live in Payroll, and this company has none.');
    }

    // Tied to the payroll run it belongs to for its dates: the service charge
    // run itself carries none, and dating it by when somebody happened to
    // create the row would put a month's charge in whichever month it was
    // keyed in.
    $stmt = db()->prepare("SELECT r.id, r.status, r.allocation_method, r.declared_total,
            r.employee_pool, r.employer_share,
            p.period_label, p.pay_date,
            (SELECT COUNT(*) FROM payroll_service_charge_allocations a WHERE a.sc_run_id = r.id) AS headcount,
            (SELECT COALESCE(SUM(a.amount), 0) FROM payroll_service_charge_allocations a WHERE a.sc_run_id = r.id) AS allocated
        FROM payroll_service_charge_runs r
        LEFT JOIN payroll_runs p ON p.id = r.run_id AND p.company_id = r.company_id
        WHERE r.company_id = :cid
          AND COALESCE(p.pay_date, DATE(r.created_at)) BETWEEN :from AND :to
        ORDER BY r.id DESC");
    try {
        $stmt->execute(['cid' => $companyId, 'from' => $from, 'to' => $to]);
        $found = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        return hospitality_pack_section('Service charge', $columns, [], [],
            $note . ' (This period could not be read: ' . $exception->getMessage() . ')');
    }

    $rows = [];
    $totals = ['declared_total' => 0.0, 'employee_pool' => 0.0, 'employer_share' => 0.0,
        'allocated' => 0.0, 'headcount' => 0];
    foreach ($found as $row) {
        $allocated = (float) $row['allocated'];
        $headcount = (int) $row['headcount'];
        $rows[] = [
            'run' => (string) ($row['period_label'] ?? '') !== ''
                ? (string) $row['period_label'] : 'Run #' . (int) $row['id'],
            'status' => ucfirst((string) $row['status']),
            'method' => ucwords(str_replace('_', ' ', (string) $row['allocation_method'])),
            'declared_total' => (float) $row['declared_total'],
            'employee_pool' => (float) $row['employee_pool'],
            'employer_share' => (float) $row['employer_share'],
            'allocated' => $allocated,
            'headcount' => $headcount,
            'per_head' => $headcount > 0 ? round($allocated / $headcount, 2) : 0.0,
            'emphasis' => '',
        ];
        foreach (['declared_total', 'employee_pool', 'employer_share'] as $field) {
            $totals[$field] += (float) $row[$field];
        }
        $totals['allocated'] += $allocated;
        $totals['headcount'] += $headcount;
    }
    $totals['per_head'] = $totals['headcount'] > 0 ? round($totals['allocated'] / $totals['headcount'], 2) : 0.0;

    return hospitality_pack_section('Service charge', $columns, $rows, $totals, $note);
}

// ---------------------------------------------------------------------------
// 8. Period comparison
// ---------------------------------------------------------------------------
function hospitality_pack_comparison(int $companyId, string $from, string $to): array
{
    // WHICH PERIOD "THE ONE BEFORE" MEANS depends on what was asked for. A
    // whole calendar month is compared against the whole month before it, which
    // is what anybody reading a June pack expects -- taking "the preceding 30
    // days" instead would compare June against 2-31 May and quietly drop a day
    // of trading out of the comparison. Any other range keeps the
    // same-length-immediately-before rule, because there is nothing else it
    // could sensibly mean.
    $isWholeMonth = $from === date('Y-m-01', strtotime($from))
        && $to === date('Y-m-t', strtotime($from))
        && date('Y-m', strtotime($from)) === date('Y-m', strtotime($to));
    if ($isWholeMonth) {
        $prevFrom = date('Y-m-01', strtotime($from . ' -1 month'));
        $prevTo = date('Y-m-t', strtotime($prevFrom));
    } else {
        $spanDays = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);
        $prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
        $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($spanDays - 1) . ' days'));
    }

    $now = hospitality_summary($companyId, $from, $to);
    $was = hospitality_summary($companyId, $prevFrom, $prevTo);

    $columns = [
        ['label', 'Measure', 'left'],
        ['now', 'This period', 'right'],
        ['was', 'Previous period', 'right'],
        ['change', 'Change', 'right'],
        ['change_pct', 'Change %', 'right'],
    ];
    $rows = [];
    foreach ([
        ['Net sales (costed)', 'costed_net_sales'],
        ['Estimated recipe cost', 'est_cost'],
        ['Estimated gross profit', 'est_gp'],
        ['Uncosted sales', 'uncosted_value'],
    ] as [$label, $key]) {
        $a = (float) ($now[$key] ?? 0);
        $b = (float) ($was[$key] ?? 0);
        $rows[] = [
            'label' => $label,
            'now' => round($a, 2),
            'was' => round($b, 2),
            'change' => round($a - $b, 2),
            // A change FROM nothing is not a percentage, however tempting the
            // arithmetic. It is reported as no comparison rather than as
            // infinity or a misleading 100%.
            'change_pct' => abs($b) > 0.004 ? round(($a - $b) / abs($b) * 100, 2) : null,
            'emphasis' => '',
        ];
    }
    $rows[] = [
        'label' => 'Weighted GP %',
        'now' => $now['weighted_gp_pct'],
        'was' => $was['weighted_gp_pct'],
        'change' => $now['weighted_gp_pct'] !== null && $was['weighted_gp_pct'] !== null
            ? round((float) $now['weighted_gp_pct'] - (float) $was['weighted_gp_pct'], 2) : null,
        'change_pct' => null,
        'emphasis' => 'total',
    ];

    // Day by day underneath, which is where a bad Tuesday shows up.
    $daily = hospitality_grouped($companyId, $from, $to, 'period_day');
    foreach ($daily as $day) {
        $rows[] = [
            'label' => 'Day — ' . (string) $day['group'],
            'now' => round((float) $day['net_sales'], 2),
            'was' => null,
            'change' => round((float) $day['est_gp'], 2),
            'change_pct' => $day['gp_pct'],
            'emphasis' => 'day',
        ];
    }

    return hospitality_pack_section('Period comparison', $columns, $rows, [],
        ($isWholeMonth ? 'The whole month before this one' : 'The period before this one, of the same length')
        . ' (' . $prevFrom . ' to ' . $prevTo . ').'
        . ' Day rows underneath carry that day\'s net sales, its estimated gross profit in the Change column'
        . ' and its GP % beside it — a month that looks steady in total rarely is day by day.');
}

// ---------------------------------------------------------------------------
// The pack as ONE workbook
// ---------------------------------------------------------------------------
/**
 * Every chosen section as its own sheet in a single file.
 *
 * ONE FILE, not eight. A management pack is read as a pack: somebody opens it
 * in a meeting and moves between the profit and loss, the category split and
 * the item ranking while the same period is on the table. Eight separate
 * downloads is eight chances to be looking at a different date range from the
 * person beside you.
 *
 * The dressing is the one the Reports Centre already uses -- letterhead merged
 * across the table, a bold heading band with a rule under it, every cell boxed,
 * a double rule over the total, and figures written as NUMBERS carrying a
 * format so a column can be selected and summed. That styling lives in
 * export_engine.php and is per sheet, which is why each section keeps its own
 * column widths, its own totals row and its own emphasis.
 *
 * @return array{sheets: array<string, array>, widths: array<string, array>, options: array}
 */
function hospitality_pack_workbook(array $pack, array $meta): array
{
    require_once __DIR__ . '/export_engine.php';

    $sheets = [];
    $widths = [];
    $perSheet = [];
    $company = trim((string) ($meta['company_name'] ?? ''));
    if (function_exists('statement_company_name')) {
        $company = statement_company_name($company);
    }
    $period = (string) ($meta['from'] ?? '') . ' to ' . (string) ($meta['to'] ?? '');
    if (function_exists('app_date_range')) {
        $period = app_date_range((string) ($meta['from'] ?? ''), (string) ($meta['to'] ?? ''));
    }

    foreach ($pack as $key => $section) {
        $columns = (array) $section['columns'];
        $columnCount = max(1, count($columns));
        $lastLetter = xlsx_column_letters($columnCount - 1);

        $rows = [];
        $kinds = [];
        $merges = [];
        $widthSource = [];
        $push = static function (array $cells, string $kind) use (&$rows, &$kinds, $columnCount): int {
            $cells = array_slice(array_pad(array_values($cells), $columnCount, ''), 0, $columnCount);
            $rows[] = $cells;
            $index = count($rows) - 1;
            $kinds[$index] = $kind;

            return $index;
        };
        $spread = static function (int $rowIndex) use (&$merges, $lastLetter, $columnCount): void {
            if ($columnCount > 1) {
                $merges[] = 'A' . ($rowIndex + 1) . ':' . $lastLetter . ($rowIndex + 1);
            }
        };

        // --- letterhead ---------------------------------------------------
        if ($company !== '') {
            $spread($push([mb_strtoupper($company)], 'company'));
        }
        $spread($push([(string) $section['title']], 'title'));
        $spread($push([$period], 'meta'));
        if ((string) ($section['note'] ?? '') !== '') {
            // The caveat travels WITH the sheet. Split off into its own file, a
            // recipe estimate reads exactly like a posted cost of sales.
            $spread($push([(string) $section['note']], 'meta'));
        }
        $push([], 'blank');

        // --- heading -------------------------------------------------------
        $headerLabels = [];
        $aligns = [];
        foreach ($columns as $index => [$columnKey, $columnLabel, $columnAlign]) {
            $headerLabels[$index] = (string) $columnLabel;
            $aligns[$index] = $columnAlign === 'right' ? 'right' : 'left';
        }
        $widthSource[] = $headerLabels;
        $headerRow = $push($headerLabels, 'header');

        // --- body ----------------------------------------------------------
        if ((array) $section['rows'] === []) {
            $push(['Nothing recorded for this period.'], 'body');
        }
        foreach ((array) $section['rows'] as $row) {
            $cells = [];
            $display = [];
            foreach ($columns as $index => [$columnKey]) {
                $value = $row[$columnKey] ?? null;
                $cells[$index] = $value === null ? '' : (is_string($value) ? $value : (float) $value);
                $display[$index] = (string) ($value ?? '');
            }
            $widthSource[] = $display;
            $emphasis = (string) ($row['emphasis'] ?? '');
            $push($cells, $emphasis === 'total' ? 'total' : ($emphasis === 'day' ? 'body' : 'body'));
        }

        // --- foot ------------------------------------------------------------
        if ((array) $section['totals'] !== []) {
            $totalCells = [];
            foreach ($columns as $index => [$columnKey]) {
                if ($index === 0) {
                    $totalCells[$index] = 'TOTAL';
                    continue;
                }
                $value = $section['totals'][$columnKey] ?? null;
                $totalCells[$index] = $value === null ? '' : (is_string($value) ? $value : (float) $value);
            }
            $widthSource[] = array_map('strval', $totalCells);
            $push($totalCells, 'total');
        }

        // A number column is one the section declared right-aligned; anything
        // else stays text, so a code with a leading zero survives.
        $formats = [];
        foreach ($aligns as $index => $align) {
            $formats[$index] = $align === 'right' ? 'money' : 'text';
        }
        // Percentages and counts are right-aligned too but are not money.
        foreach ($columns as $index => [$columnKey]) {
            if (preg_match('/pct|share|qty|invoices|movements|headcount|rank|lines/i', (string) $columnKey) === 1
                && ($aligns[$index] ?? '') === 'right') {
                $formats[$index] = 'count';
            }
        }

        $sheetName = mb_substr((string) $section['title'], 0, 31);
        $sheets[$sheetName] = $rows;
        $widths[$sheetName] = export_column_widths($widthSource, 12, 46);
        $perSheet[$sheetName] = [
            'styled_table' => true,
            'freeze_header' => true,
            // A management pack is read, not filtered: several of these sheets
            // carry a totals row that filtering would strand.
            'auto_filter' => false,
            'header_row' => $headerRow,
            'row_kinds' => $kinds,
            'column_formats' => $formats,
            'column_aligns' => $aligns,
            'merges' => $merges,
            'print' => ['landscape' => $columnCount >= 6, 'repeat_rows' => $headerRow + 1],
        ];
    }

    return [
        'sheets' => $sheets,
        'widths' => $widths,
        'options' => ['sheets' => $perSheet],
    ];
}

/** The pack as .xlsx bytes, ready to download or attach to an email. */
function hospitality_pack_xlsx(array $pack, array $meta): string
{
    require_once __DIR__ . '/export_engine.php';
    $book = hospitality_pack_workbook($pack, $meta);

    return xlsx_build_sheets($book['sheets'], $book['widths'], $book['options']);
}
