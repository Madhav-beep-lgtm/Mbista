<?php
declare(strict_types=1);

/**
 * The two-sheet daily sales upload.
 *
 * A day's restaurant sales are two different facts, and a single sheet could
 * only ever carry one of them properly:
 *
 *   Item-wise    what was sold — the CREDIT side. Sales by category, plus VAT.
 *   Invoice-wise how it was paid — the DEBIT side. Cash, card, FonePay, or a
 *                customer's own ledger when the meal went on credit.
 *
 * Posting them together is what lets one day's takings arrive through several
 * tenders at once:
 *
 *      Dr  Cash                      12,000.00      ] one per ledger named
 *      Dr  FonePay Receivable        21,414.50      ] on the invoice sheet
 *      Dr  Sunrise Traders (credit)   8,000.00      ]
 *          Cr  Food Sales                        30,000.00   ] one per category
 *          Cr  Bar Sales                          6,650.00   ] on the item sheet
 *          Cr  VAT Payable                        4,764.50
 *
 * The two sheets are reconciled against each other before anything posts —
 * same first day, same last day, and the same five money columns — because a
 * mismatched pair is the one error that would otherwise post a balanced but
 * wrong voucher and be nearly impossible to find afterwards.
 *
 * Discount is NETTED into the sales credit rather than debited to a discount
 * ledger: the sheets carry Taxable Sales already net of it, and that is the
 * figure the VAT is computed on and the figure the customer was billed.
 */

require_once __DIR__ . '/voucher_import.php';
require_once __DIR__ . '/export_engine.php';

/** Rows either sheet may carry before the file is rejected as too big. */
const HOSPITALITY_WORKBOOK_MAX_ROWS = 5000;

/**
 * How far a figure may be out and still be treated as rounding.
 *
 * A till rounds every line to the paisa and a day's sheet adds hundreds of them
 * up, so a total arrives a few paisa away from what the parts make. Under a
 * rupee is rounding; a rupee or more is somebody's mistake, and the difference
 * between those two is the whole judgement this constant encodes.
 *
 * The BOOKS are not given this slack. A voucher still has to balance to the
 * half-paisa — see hospitality_workbook_absorb_rounding(), which puts the
 * residual on the largest sales line rather than letting it through.
 */
const HOSPITALITY_WORKBOOK_TOLERANCE = 0.99;

/** The sheet names written into the template, and looked for on the way back in. */
const HOSPITALITY_SHEET_ITEMS = 'Item-wise Sales';
const HOSPITALITY_SHEET_INVOICES = 'Invoice-wise Sales';

// ---------------------------------------------------------------- the template

/**
 * The workbook handed out as the template, as sheet name => rows.
 *
 * The figures are the worked example from the specification: five item rows
 * totalling 36,850.00 before discount, 200.00 of discount, 36,650.00 taxable,
 * 4,764.50 of VAT at 13%, and 41,414.50 billed — settled on the second sheet
 * across two tenders that add back to exactly that.
 */
function hospitality_workbook_template_sheets(): array
{
    return [
        HOSPITALITY_SHEET_ITEMS => [
            ['Date (AD or BS)', 'Category', 'Item', 'Qty', 'Total Sales Amount (without VAT)', 'Discount', 'Taxable Sales', 'VAT', 'Sales with VAT'],
            ['2083-03-24', 'Food', 'Chicken Momo (10 pcs)', 42, 12600, 0, 12600, 1638, 14238],
            ['2083-03-24', 'Food', 'Veg Chowmein', 18, 3600, 200, 3400, 442, 3842],
            ['2083-03-24', 'Beverage', 'Masala Tea', 65, 3250, 0, 3250, 422.5, 3672.5],
            ['2083-03-24', 'Bar', 'Local Beer 650ml', 12, 6000, 0, 6000, 780, 6780],
            ['2083-03-25', 'Food', 'Chicken Momo (10 pcs)', 38, 11400, 0, 11400, 1482, 12882],
        ],
        HOSPITALITY_SHEET_INVOICES => [
            ['Date', 'Invoice No', 'Payment Type', 'Party Ledger Code', 'Sales Amount', 'Less: Discount', 'Taxable Sales', 'VAT', 'Sales with VAT'],
            ['2083-03-24', 'INV-001', 'Cash', '1100', 25450, 200, 25250, 3282.5, 28532.5],
            ['2083-03-25', 'INV-002', 'Credit', '1200', 11400, 0, 11400, 1482, 12882],
        ],
    ];
}

/** The template as a single .xlsx carrying both sheets. */
function hospitality_workbook_template_xlsx(): string
{
    $widths = [22, 16, 30, 8, 30, 14, 16, 12, 16];

    return xlsx_build_sheets(hospitality_workbook_template_sheets(), [
        HOSPITALITY_SHEET_ITEMS => $widths,
        HOSPITALITY_SHEET_INVOICES => [16, 16, 18, 20, 16, 16, 16, 12, 16],
    ], ['styled_table' => true, 'freeze_header' => true]);
}

/**
 * One sheet of the template as CSV.
 *
 * CSV holds one sheet, so somebody working in CSV uploads two files. The
 * Excel template is the recommended path precisely because it keeps the pair
 * together.
 */
function hospitality_workbook_template_csv(string $which): string
{
    $sheets = hospitality_workbook_template_sheets();
    $rows = $sheets[$which === 'invoices' ? HOSPITALITY_SHEET_INVOICES : HOSPITALITY_SHEET_ITEMS];
    $handle = fopen('php://temp', 'r+b');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '\\');
    }
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);

    return $csv;
}

// ------------------------------------------------------------- header mapping

/** Map an item-sheet header row to [field => column index]. */
function hospitality_item_sheet_headers(array $headerCells): array
{
    return hospitality_workbook_map_headers($headerCells, [
        'date' => ['date', 'salesdate', 'billdate', 'miti', 'dateadorbs', 'dateadbs', 'day'],
        'category' => ['category', 'cat', 'group', 'salescategory', 'itemcategory', 'department'],
        'item' => ['item', 'itemname', 'menuitem', 'particulars', 'description', 'product', 'itemdescription'],
        'qty' => ['qty', 'quantity', 'units', 'nos', 'qtysold', 'noofunits'],
        // "Total Sales Amount (without VAT)" is the gross for the row before
        // discount and before VAT. The alias list keeps the older sheets that
        // said only "Total Sales Amount" working.
        'amount' => ['totalsalesamountwithoutvat', 'totalsalesamount', 'totalsales', 'salesamount', 'amount', 'total', 'totalamount', 'grossamount', 'grosssales', 'sales'],
        'discount' => ['discount', 'disc', 'discountamount', 'less', 'lessdiscount'],
        'taxable' => ['taxablesales', 'taxable', 'taxableamount', 'netsales', 'netamount'],
        'vat' => ['vat', 'vatamount', 'tax', 'taxamount'],
        'total' => ['saleswithvat', 'totalwithvat', 'grandtotal', 'billamount', 'amountwithvat', 'invoicetotal'],
    ]);
}

/** Map an invoice-sheet header row to [field => column index]. */
function hospitality_invoice_sheet_headers(array $headerCells): array
{
    return hospitality_workbook_map_headers($headerCells, [
        'date' => ['date', 'salesdate', 'billdate', 'miti', 'dateadorbs', 'dateadbs', 'day'],
        'invoice_no' => ['invoiceno', 'invoice', 'invoicenumber', 'billno', 'billnumber', 'voucherno', 'no'],
        'payment_type' => ['paymenttype', 'paymentmode', 'mode', 'tender', 'paymentmethod', 'cashcredit', 'type'],
        'ledger_code' => ['partyledgercode', 'ledgercode', 'partycode', 'partyledger', 'ledger', 'partyname', 'accountcode', 'code'],
        'amount' => ['salesamount', 'totalsalesamount', 'amount', 'gross', 'grossamount', 'total', 'totalamount'],
        'discount' => ['lessdiscount', 'discount', 'disc', 'discountamount', 'less'],
        'taxable' => ['taxablesales', 'taxable', 'taxableamount', 'netsales', 'netamount'],
        'vat' => ['vat', 'vatamount', 'tax', 'taxamount'],
        'total' => ['saleswithvat', 'totalwithvat', 'grandtotal', 'billamount', 'amountwithvat', 'invoicetotal', 'receivable'],
    ]);
}

/**
 * Match a header row against an alias table.
 *
 * Longest alias first, so "totalsalesamountwithoutvat" is not claimed by the
 * shorter "totalsalesamount" sitting earlier in the list, and a column already
 * matched is never taken twice.
 */
function hospitality_workbook_map_headers(array $headerCells, array $aliases): array
{
    $flat = [];
    foreach ($aliases as $field => $names) {
        foreach ($names as $name) {
            $flat[] = ['field' => $field, 'alias' => $name, 'len' => strlen($name)];
        }
    }
    usort($flat, static fn (array $a, array $b): int => $b['len'] <=> $a['len']);

    $map = [];
    $usedColumns = [];
    foreach ($flat as $candidate) {
        if (isset($map[$candidate['field']])) {
            continue;
        }
        foreach ($headerCells as $index => $cell) {
            if (isset($usedColumns[$index])) {
                continue;
            }
            $key = strtolower((string) preg_replace('/[^a-z]/i', '', (string) $cell));
            if ($key !== '' && $key === $candidate['alias']) {
                $map[$candidate['field']] = $index;
                $usedColumns[$index] = true;
                break;
            }
        }
    }

    return $map;
}

/** True when a sheet's header row looks like the invoice-wise sheet. */
function hospitality_sheet_is_invoices(array $headerCells): bool
{
    $map = hospitality_invoice_sheet_headers($headerCells);

    return isset($map['invoice_no']) || isset($map['payment_type']) || isset($map['ledger_code']);
}

/** True when a sheet's header row looks like the item-wise sheet. */
function hospitality_sheet_is_items(array $headerCells): bool
{
    $map = hospitality_item_sheet_headers($headerCells);

    return isset($map['category'], $map['item']);
}

// --------------------------------------------------------------- reading files

/**
 * The two sheets out of whatever was uploaded, as ['items' => rows, 'invoices' => rows].
 *
 * A workbook carrying both is the normal case. Two files (a CSV pair, or two
 * single-sheet workbooks) are accepted too, and either order works: the sheets
 * are told apart by their headers, not by their position or their names,
 * because renaming a tab is the first thing anybody does to a template.
 *
 * Returns ['error' => string] when the pair cannot be identified.
 */
function hospitality_workbook_read(string $primaryPath, string $primaryExt, ?string $secondPath = null, ?string $secondExt = null): array
{
    $candidates = [];
    foreach ([[$primaryPath, $primaryExt], [$secondPath, $secondExt]] as [$path, $extension]) {
        if ($path === null || $path === '' || !is_file($path)) {
            continue;
        }
        if (strtolower((string) $extension) === 'csv') {
            $candidates[] = voucher_import_read_csv($path);
            continue;
        }
        foreach (spreadsheet_read_xlsx_all($path, HOSPITALITY_WORKBOOK_MAX_ROWS) as $rows) {
            $candidates[] = $rows;
        }
    }
    if ($candidates === []) {
        return ['error' => 'No readable sheet was uploaded.'];
    }

    $items = null;
    $invoices = null;
    foreach ($candidates as $rows) {
        // The header is not always the first row — sheets arrive with a title
        // line above it — so the first few rows are each tried as one.
        $header = null;
        foreach (array_slice($rows, 0, 10) as $row) {
            if (hospitality_sheet_is_invoices($row['cells']) || hospitality_sheet_is_items($row['cells'])) {
                $header = $row['cells'];
                break;
            }
        }
        if ($header === null) {
            continue;
        }
        // Invoice-wise is checked first: it is the more specific shape, and an
        // invoice sheet carrying a "Particulars" column would otherwise look
        // like an item sheet too.
        if ($invoices === null && hospitality_sheet_is_invoices($header)) {
            $invoices = $rows;
            continue;
        }
        if ($items === null && hospitality_sheet_is_items($header)) {
            $items = $rows;
        }
    }

    if ($items === null && $invoices === null) {
        return ['error' => 'Neither sheet could be recognised. The item-wise sheet needs Category and Item columns; the invoice-wise sheet needs Invoice No, Payment Type and Party Ledger Code. Download the template to see the expected layout.'];
    }
    if ($items === null) {
        return ['error' => 'The item-wise sales sheet is missing. It carries what was sold — Date, Category, Item, Qty and the money columns — and is what the sales and VAT credits are built from.'];
    }
    if ($invoices === null) {
        return ['error' => 'The invoice-wise sheet is missing. It carries how the day was settled — Date, Invoice No, Payment Type and Party Ledger Code — and is what the debit side is built from. Both sheets have to be in the one workbook; download the template to see how it is laid out.'];
    }

    return ['items' => $items, 'invoices' => $invoices];
}

// ----------------------------------------------------------- ledgers by code

/**
 * Every posting ledger this company has, keyed for lookup by code AND by name.
 *
 * The sheet is written with ledger CODES because that is what a POS export
 * carries, and the screen shows the resolved NAME back so the person who
 * uploaded it can see what it landed on. Read in ONE query and handed round,
 * rather than a lookup per invoice row — a busy month is a few thousand rows
 * and that would be a few thousand round trips.
 */
function hospitality_workbook_ledger_lookup(int $companyId): array
{
    $stmt = db()->prepare("SELECT id, code, name, type, status FROM ledgers
        WHERE company_id = :cid AND status = 'active' ORDER BY code ASC");
    $stmt->execute(['cid' => $companyId]);

    $byCode = [];
    $byName = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ledger) {
        $code = hospitality_sales_norm((string) $ledger['code']);
        $name = hospitality_sales_norm((string) $ledger['name']);
        if ($code !== '' && !isset($byCode[$code])) {
            $byCode[$code] = $ledger;
        }
        if ($name !== '' && !isset($byName[$name])) {
            $byName[$name] = $ledger;
        }
    }

    return ['by_code' => $byCode, 'by_name' => $byName];
}

/** One ledger from the lookup, by code first and then by name. */
function hospitality_workbook_resolve_ledger(array $lookup, string $raw): ?array
{
    $key = hospitality_sales_norm($raw);
    if ($key === '') {
        return null;
    }

    return $lookup['by_code'][$key] ?? $lookup['by_name'][$key] ?? null;
}

// ------------------------------------------------------------- parsing a sheet

/**
 * Is this row the sheet's own totals line rather than a sale?
 *
 * Sheets come with a Total row at the foot carrying the same money as the rows
 * above it, and reading it as a sale would double the day. The word turns up
 * either in the first cell or in the date cell depending on how the sheet was
 * laid out, so both are checked.
 */
function hospitality_workbook_is_totals_row(array $cells, string $dateCell): bool
{
    $words = ['total', 'grandtotal', 'subtotal', 'sum', 'totals'];
    foreach ([$dateCell, (string) ($cells[0] ?? '')] as $candidate) {
        $key = strtolower((string) preg_replace('/[^a-z]/i', '', $candidate));
        if ($key !== '' && in_array($key, $words, true)) {
            return true;
        }
    }

    return false;
}

/**
 * The money columns of one row, checked against each other.
 *
 * The sheets carry all five figures rather than deriving them, so each row can
 * be checked on its own before the two sheets are checked against one another:
 * taxable must be the amount less the discount, and the billed total must be
 * the taxable plus the VAT. A row that fails these is almost always a hand-
 * edited cell, and catching it here names the row instead of leaving a few
 * rupees adrift in a day's total.
 *
 * A missing Taxable or Sales-with-VAT column is computed rather than refused,
 * which is what makes a shorter POS export still usable.
 */
function hospitality_workbook_money(array $cells, array $map, float $vatRate): array
{
    $read = static function (string $field) use ($cells, $map): ?float {
        if (!isset($map[$field])) {
            return null;
        }
        $raw = trim((string) ($cells[$map[$field]] ?? ''));

        return $raw === '' ? null : voucher_import_amount($raw);
    };

    $errors = [];
    $amount = $read('amount') ?? 0.0;
    $discount = $read('discount') ?? 0.0;
    $taxableGiven = $read('taxable');
    $vatGiven = $read('vat');
    $totalGiven = $read('total');

    if ($amount < 0) {
        $errors[] = 'Sales amount cannot be negative.';
        $amount = 0.0;
    }
    if ($discount < 0) {
        $errors[] = 'Discount cannot be negative.';
        $discount = 0.0;
    }
    if ($discount > $amount) {
        $errors[] = 'Discount (' . number_format($discount, 2) . ') is larger than the sales amount (' . number_format($amount, 2) . ').';
    }

    $taxable = $taxableGiven ?? round($amount - $discount, 2);
    if ($taxableGiven !== null && abs($taxableGiven - round($amount - $discount, 2)) > HOSPITALITY_WORKBOOK_TOLERANCE) {
        $errors[] = 'Taxable Sales ' . number_format($taxableGiven, 2) . ' is not Sales Amount less Discount ('
            . number_format($amount, 2) . ' − ' . number_format($discount, 2) . ' = ' . number_format($amount - $discount, 2) . ').';
    }
    if ($taxable < 0) {
        $errors[] = 'Taxable Sales cannot be negative.';
        $taxable = 0.0;
    }

    $vat = $vatGiven ?? round($taxable * $vatRate / 100, 2);
    if ($vat < 0) {
        $errors[] = 'VAT cannot be negative.';
        $vat = 0.0;
    }

    $total = $totalGiven ?? round($taxable + $vat, 2);
    if ($totalGiven !== null && abs($totalGiven - round($taxable + $vat, 2)) > HOSPITALITY_WORKBOOK_TOLERANCE) {
        $errors[] = 'Sales with VAT ' . number_format($totalGiven, 2) . ' is not Taxable Sales plus VAT ('
            . number_format($taxable, 2) . ' + ' . number_format($vat, 2) . ' = ' . number_format($taxable + $vat, 2) . ').';
    }

    return [
        'amount' => round($amount, 2),
        'discount' => round($discount, 2),
        'taxable' => round($taxable, 2),
        'vat' => round($vat, 2),
        'total' => round($total, 2),
        'errors' => $errors,
    ];
}

/** A running total in the shape both sheets report. */
function hospitality_workbook_zero_totals(): array
{
    return ['rows' => 0, 'qty' => 0.0, 'amount' => 0.0, 'discount' => 0.0, 'taxable' => 0.0, 'vat' => 0.0, 'total' => 0.0];
}

/** Add one row's money into a running total. */
function hospitality_workbook_add(array $totals, array $money, float $qty = 0.0): array
{
    $totals['rows']++;
    $totals['qty'] = round($totals['qty'] + $qty, 3);
    foreach (['amount', 'discount', 'taxable', 'vat', 'total'] as $field) {
        $totals[$field] = round($totals[$field] + $money[$field], 2);
    }

    return $totals;
}

/**
 * Find the header row of a sheet and return [index, map].
 *
 * Returns [null, []] when no row in the first ten looks like a header.
 */
function hospitality_workbook_header(array $rows, callable $mapper, array $required): array
{
    foreach (array_slice($rows, 0, 10, true) as $index => $row) {
        $map = $mapper($row['cells']);
        $missing = false;
        foreach ($required as $field) {
            if (!isset($map[$field])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return [$index, $map];
        }
    }

    return [null, []];
}

/**
 * Parse the item-wise sheet — what was sold, and therefore the credit side.
 *
 * Each row resolves to the sales ledger for its category (an item-level
 * mapping still wins, as it always has), so a day's credits come out one line
 * per ledger rather than one per row.
 */
function hospitality_workbook_parse_items(array $rows, int $companyId, int $fiscalYearId, array $settings, array $context): array
{
    [$headerIndex, $map] = hospitality_workbook_header($rows, 'hospitality_item_sheet_headers', ['date', 'category', 'item']);
    if ($headerIndex === null) {
        return ['error' => 'The item-wise sheet has no header row carrying Date, Category and Item.'];
    }

    $vatRate = max(0.0, (float) ($settings['post_vat_rate'] ?? 13.00));
    $fyStart = (string) ($context['fy_start'] ?? '');
    $fyEnd = (string) ($context['fy_end'] ?? '');
    // The lock boundary is ONE date for the whole fiscal year, so it is read
    // once for the sheet. Asking is_period_locked() per row cost a query per
    // row — a month of a busy kitchen turned a preview into 750 round trips.
    $lockedThrough = $context['locked_through'] ?? null;

    $parsed = [];
    $days = [];
    $totals = hospitality_workbook_zero_totals();
    $valid = 0;
    $errorCount = 0;

    foreach (array_slice($rows, $headerIndex + 1) as $row) {
        if (trim(implode('', $row['cells'])) === '') {
            continue;
        }
        if (count($parsed) >= HOSPITALITY_WORKBOOK_MAX_ROWS) {
            return ['error' => 'The item-wise sheet has more than ' . number_format(HOSPITALITY_WORKBOOK_MAX_ROWS) . ' rows — split it into smaller files.'];
        }
        $cells = $row['cells'];
        $cell = static fn (string $field): string => trim((string) ($cells[$map[$field] ?? -1] ?? ''));

        // A totals line at the foot of the sheet is a summary, not a sale.
        if (hospitality_workbook_is_totals_row($cells, $cell('date'))) {
            continue;
        }

        $errors = [];
        $dateRaw = $cell('date');
        $date = voucher_import_date($dateRaw);
        if ($date === null) {
            $errors[] = $dateRaw === '' ? 'Date is missing.' : 'Date "' . $dateRaw . '" is not valid — use YYYY-MM-DD in AD or BS (years 2064+ are read as BS).';
        } elseif ($fyStart !== '' && ($date < $fyStart || $date > $fyEnd)) {
            $errors[] = 'Date ' . $date . ' falls outside the selected fiscal year (' . $fyStart . ' to ' . $fyEnd . ').';
        } elseif ($lockedThrough !== null && $date <= $lockedThrough) {
            $errors[] = 'Date ' . $date . ' falls inside a locked accounting period (locked through ' . $lockedThrough . ').';
        }

        $category = $cell('category');
        if ($category === '') {
            $errors[] = 'Category is missing.';
        }
        $item = $cell('item');
        if ($item === '') {
            $errors[] = 'Item is missing.';
        }
        $qty = isset($map['qty']) ? voucher_import_amount($cell('qty')) : 0.0;
        if ($qty < 0) {
            $errors[] = 'Quantity cannot be negative.';
            $qty = 0.0;
        }

        $money = hospitality_workbook_money($cells, $map, $vatRate);
        $errors = array_merge($errors, $money['errors']);
        if ($money['amount'] <= 0) {
            $errors[] = 'Total Sales Amount must be greater than zero.';
        }

        $resolved = hospitality_resolve_sales_ledger(
            $context['maps'],
            $context['default_ledger'],
            $category,
            $item,
            $context['receivable_ledger'],
            $context['discount_ledger']
        );
        if ($resolved['ledger_id'] === null) {
            $errors[] = 'No sales ledger: map the category "' . $category . '", or set the default Sales ledger as fallback.';
        }
        if ($money['vat'] > 0 && $context['vat_ledger'] === null) {
            $errors[] = 'VAT ledger is not set in the posting setup.';
        }

        $entry = [
            'n' => $row['n'],
            'date' => $date,
            'date_raw' => $dateRaw,
            'category' => $category,
            'item' => $item,
            'qty' => round($qty, 3),
            'amount' => $money['amount'],
            'discount' => $money['discount'],
            'taxable' => $money['taxable'],
            'vat' => $money['vat'],
            'total' => $money['total'],
            'ledger_id' => $resolved['ledger_id'],
            'ledger_label' => $resolved['ledger_label'],
            'ledger_source' => $resolved['source'],
            'errors' => $errors,
        ];
        $parsed[] = $entry;

        if ($errors !== []) {
            $errorCount++;
            continue;
        }
        $valid++;
        $totals = hospitality_workbook_add($totals, $money, $qty);
        if (!isset($days[$date])) {
            $days[$date] = ['date' => $date, 'ledgers' => []] + hospitality_workbook_zero_totals();
        }
        $days[$date] = hospitality_workbook_add($days[$date], $money, $qty) + ['date' => $date, 'ledgers' => $days[$date]['ledgers']];
        // Credits are accumulated per ledger here, so posting a day is a walk
        // over a handful of ledgers rather than over every row again.
        $ledgerKey = (int) $resolved['ledger_id'];
        $days[$date]['ledgers'][$ledgerKey]['label'] = (string) $resolved['ledger_label'];
        $days[$date]['ledgers'][$ledgerKey]['category'] = $category;
        $days[$date]['ledgers'][$ledgerKey]['taxable'] = round(($days[$date]['ledgers'][$ledgerKey]['taxable'] ?? 0) + $money['taxable'], 2);
    }

    if ($parsed === []) {
        return ['error' => 'The item-wise sheet has no data rows below the header.'];
    }
    ksort($days);

    return ['rows' => $parsed, 'days' => $days, 'totals' => $totals, 'valid' => $valid, 'errors' => $errorCount];
}

/**
 * Parse the invoice-wise sheet — how the day was settled, and therefore the
 * debit side.
 *
 * Every row names a ledger by code. An unknown code is an error rather than a
 * silent fallback: posting somebody's card takings to whatever ledger happened
 * to be the default is exactly the kind of thing nobody notices for a month.
 */
function hospitality_workbook_parse_invoices(array $rows, int $companyId, int $fiscalYearId, array $settings, array $context): array
{
    [$headerIndex, $map] = hospitality_workbook_header($rows, 'hospitality_invoice_sheet_headers', ['date']);
    if ($headerIndex === null) {
        return ['error' => 'The invoice-wise sheet has no header row carrying a Date column.'];
    }
    if (!isset($map['ledger_code'])) {
        return ['error' => 'The invoice-wise sheet has no Party Ledger Code column. That column is what decides which ledger each invoice is debited to.'];
    }

    $vatRate = max(0.0, (float) ($settings['post_vat_rate'] ?? 13.00));
    $lookup = $context['ledger_lookup'];
    $fyStart = (string) ($context['fy_start'] ?? '');
    $fyEnd = (string) ($context['fy_end'] ?? '');

    $parsed = [];
    $days = [];
    $totals = hospitality_workbook_zero_totals();
    $valid = 0;
    $errorCount = 0;

    foreach (array_slice($rows, $headerIndex + 1) as $row) {
        if (trim(implode('', $row['cells'])) === '') {
            continue;
        }
        if (count($parsed) >= HOSPITALITY_WORKBOOK_MAX_ROWS) {
            return ['error' => 'The invoice-wise sheet has more than ' . number_format(HOSPITALITY_WORKBOOK_MAX_ROWS) . ' rows — split it into smaller files.'];
        }
        $cells = $row['cells'];
        $cell = static fn (string $field): string => trim((string) ($cells[$map[$field] ?? -1] ?? ''));

        if (hospitality_workbook_is_totals_row($cells, $cell('date'))) {
            continue;
        }

        $errors = [];
        $dateRaw = $cell('date');
        $date = voucher_import_date($dateRaw);
        if ($date === null) {
            $errors[] = $dateRaw === '' ? 'Date is missing.' : 'Date "' . $dateRaw . '" is not valid — use YYYY-MM-DD in AD or BS (years 2064+ are read as BS).';
        } elseif ($fyStart !== '' && ($date < $fyStart || $date > $fyEnd)) {
            $errors[] = 'Date ' . $date . ' falls outside the selected fiscal year (' . $fyStart . ' to ' . $fyEnd . ').';
        }

        $ledgerRaw = $cell('ledger_code');
        $ledger = hospitality_workbook_resolve_ledger($lookup, $ledgerRaw);
        if ($ledgerRaw === '') {
            $errors[] = 'Party Ledger Code is missing — name the cash, bank, wallet or customer ledger this invoice was settled to.';
        } elseif ($ledger === null) {
            $errors[] = 'No active ledger has the code or name "' . $ledgerRaw . '".';
        }

        $money = hospitality_workbook_money($cells, $map, $vatRate);
        $errors = array_merge($errors, $money['errors']);
        if ($money['total'] <= 0) {
            $errors[] = 'Sales with VAT must be greater than zero.';
        }

        $entry = [
            'n' => $row['n'],
            'date' => $date,
            'date_raw' => $dateRaw,
            'invoice_no' => mb_substr($cell('invoice_no'), 0, 60),
            'payment_type' => mb_substr($cell('payment_type'), 0, 60),
            'ledger_code' => mb_substr($ledgerRaw, 0, 60),
            'ledger_id' => $ledger !== null ? (int) $ledger['id'] : null,
            'ledger_name' => $ledger !== null ? (string) $ledger['name'] : '',
            'amount' => $money['amount'],
            'discount' => $money['discount'],
            'taxable' => $money['taxable'],
            'vat' => $money['vat'],
            'total' => $money['total'],
            'errors' => $errors,
        ];
        $parsed[] = $entry;

        if ($errors !== []) {
            $errorCount++;
            continue;
        }
        $valid++;
        $totals = hospitality_workbook_add($totals, $money);
        if (!isset($days[$date])) {
            $days[$date] = ['date' => $date, 'ledgers' => []] + hospitality_workbook_zero_totals();
        }
        $days[$date] = hospitality_workbook_add($days[$date], $money) + ['date' => $date, 'ledgers' => $days[$date]['ledgers']];
        $ledgerKey = (int) $entry['ledger_id'];
        $days[$date]['ledgers'][$ledgerKey]['name'] = $entry['ledger_name'];
        $days[$date]['ledgers'][$ledgerKey]['total'] = round(($days[$date]['ledgers'][$ledgerKey]['total'] ?? 0) + $money['total'], 2);
    }

    if ($parsed === []) {
        return ['error' => 'The invoice-wise sheet has no data rows below the header.'];
    }
    ksort($days);

    return ['rows' => $parsed, 'days' => $days, 'totals' => $totals, 'valid' => $valid, 'errors' => $errorCount];
}

// ------------------------------------------------------------- reconciliation

/**
 * Check the two sheets describe the same trading period and the same money.
 *
 * This is the guard the whole two-sheet arrangement rests on. The debits come
 * from one sheet and the credits from the other, so a voucher will balance
 * whether or not the pair belongs together — a January invoice sheet against a
 * February item sheet posts perfectly and is completely wrong. Comparing the
 * first day, the last day, and all five money columns is what makes that
 * impossible rather than merely unlikely.
 */
function hospitality_workbook_reconcile(array $items, array $invoices): array
{
    $problems = [];

    // A sheet with nothing usable on it totals zero, and comparing zero against
    // a real figure reports every column as out. That is one problem told seven
    // times, and it buries the row errors that actually caused it. Say what is
    // wrong once, and stop.
    if ($items['valid'] === 0 || $invoices['valid'] === 0) {
        if ($items['valid'] === 0) {
            $problems[] = $items['errors'] > 0
                ? 'Nothing on the item-wise sheet can be read — all ' . (int) $items['errors'] . ' row(s) have errors. Fix those first; until then there is nothing to compare.'
                : 'The item-wise sheet has no rows.';
        }
        if ($invoices['valid'] === 0) {
            $problems[] = $invoices['errors'] > 0
                ? 'Nothing on the invoice-wise sheet can be read — all ' . (int) $invoices['errors'] . ' row(s) have errors. Fix those first; until then there is nothing to compare.'
                : 'The invoice-wise sheet has no rows.';
        }

        return [
            'ok' => false,
            'problems' => $problems,
            'differences' => [],
            'item_range' => ['', ''],
            'invoice_range' => ['', ''],
            'blocked_by_rows' => true,
        ];
    }

    $itemDates = array_keys($items['days']);
    $invoiceDates = array_keys($invoices['days']);
    $itemFirst = $itemDates === [] ? '' : (string) reset($itemDates);
    $itemLast = $itemDates === [] ? '' : (string) end($itemDates);
    $invoiceFirst = $invoiceDates === [] ? '' : (string) reset($invoiceDates);
    $invoiceLast = $invoiceDates === [] ? '' : (string) end($invoiceDates);

    if ($itemFirst !== $invoiceFirst) {
        $problems[] = 'The sheets start on different days — item-wise begins ' . ($itemFirst ?: '—')
            . ', invoice-wise begins ' . ($invoiceFirst ?: '—') . '.';
    }
    if ($itemLast !== $invoiceLast) {
        $problems[] = 'The sheets end on different days — item-wise ends ' . ($itemLast ?: '—')
            . ', invoice-wise ends ' . ($invoiceLast ?: '—') . '.';
    }

    $columns = [
        'amount' => 'Sales Amount',
        'discount' => 'Discount',
        'taxable' => 'Taxable Sales',
        'vat' => 'VAT',
        'total' => 'Sales with VAT',
    ];
    $differences = [];
    foreach ($columns as $field => $label) {
        $itemValue = (float) ($items['totals'][$field] ?? 0);
        $invoiceValue = (float) ($invoices['totals'][$field] ?? 0);
        $gap = round($itemValue - $invoiceValue, 2);
        $differences[$field] = ['label' => $label, 'items' => $itemValue, 'invoices' => $invoiceValue, 'gap' => $gap];
        if (abs($gap) > HOSPITALITY_WORKBOOK_TOLERANCE) {
            $problems[] = $label . ' does not agree — item-wise ' . number_format($itemValue, 2)
                . ', invoice-wise ' . number_format($invoiceValue, 2)
                . ' (out by ' . number_format(abs($gap), 2) . ').';
        }
    }

    // A day present on one sheet and not the other balances in total but posts
    // a day with credits and no debits, so it is named here too.
    $onlyItems = array_values(array_diff($itemDates, $invoiceDates));
    $onlyInvoices = array_values(array_diff($invoiceDates, $itemDates));
    if ($onlyItems !== []) {
        $problems[] = 'These days are on the item-wise sheet but not the invoice-wise sheet: ' . implode(', ', array_slice($onlyItems, 0, 8))
            . (count($onlyItems) > 8 ? ' and ' . (count($onlyItems) - 8) . ' more' : '') . '.';
    }
    if ($onlyInvoices !== []) {
        $problems[] = 'These days are on the invoice-wise sheet but not the item-wise sheet: ' . implode(', ', array_slice($onlyInvoices, 0, 8))
            . (count($onlyInvoices) > 8 ? ' and ' . (count($onlyInvoices) - 8) . ' more' : '') . '.';
    }

    // Each day must balance on its own, because each day posts its own voucher.
    $dayGaps = [];
    foreach ($itemDates as $date) {
        if (!isset($invoices['days'][$date])) {
            continue;
        }
        $gap = round((float) $items['days'][$date]['total'] - (float) $invoices['days'][$date]['total'], 2);
        if (abs($gap) > HOSPITALITY_WORKBOOK_TOLERANCE) {
            $dayGaps[] = $date . ' (out by ' . number_format(abs($gap), 2) . ')';
        }
    }
    if ($dayGaps !== []) {
        $problems[] = 'These days do not balance between the two sheets: ' . implode(', ', array_slice($dayGaps, 0, 8))
            . (count($dayGaps) > 8 ? ' and ' . (count($dayGaps) - 8) . ' more' : '') . '.';
    }

    return [
        'ok' => $problems === [],
        'problems' => $problems,
        'differences' => $differences,
        'item_range' => [$itemFirst, $itemLast],
        'invoice_range' => [$invoiceFirst, $invoiceLast],
        'blocked_by_rows' => false,
    ];
}

/**
 * Put a day's rounding residual on its largest sales line.
 *
 * The two sheets are allowed to disagree by under a rupee, because that is what
 * rounding hundreds of till lines does. A VOUCHER is allowed no such thing: the
 * engine refuses anything off by more than half a paisa, and rightly.
 *
 * So the slack has to land somewhere before posting. It goes on the biggest
 * credit, because that is the figure with the most rounding in it and the one
 * where a few paisa is least material. The DEBIT side is never touched -- it is
 * what the customer actually paid, and that is not an estimate.
 *
 * Returns the adjusted credits, and by how much, so the entry can say so.
 */
function hospitality_workbook_absorb_rounding(array $creditsByLedger, float $debitTotal, float $vat): array
{
    $creditTotal = $vat;
    foreach ($creditsByLedger as $leg) {
        $creditTotal += (float) $leg['taxable'];
    }
    $residual = round($debitTotal - $creditTotal, 2);
    if (abs($residual) < 0.005 || $creditsByLedger === []) {
        return ['credits' => $creditsByLedger, 'residual' => 0.0, 'ledger_id' => 0];
    }

    $largestKey = null;
    $largest = null;
    foreach ($creditsByLedger as $key => $leg) {
        if ($largest === null || (float) $leg['taxable'] > $largest) {
            $largest = (float) $leg['taxable'];
            $largestKey = $key;
        }
    }
    $creditsByLedger[$largestKey]['taxable'] = round((float) $creditsByLedger[$largestKey]['taxable'] + $residual, 2);

    return ['credits' => $creditsByLedger, 'residual' => $residual, 'ledger_id' => (int) $largestKey];
}

// ------------------------------------------------------- menu items from sales

/**
 * A menu-item code from what the sheet called the item.
 *
 * Codes are for the costing screens to hang recipes off, so they only need to
 * be short, stable and unique — the name is what anybody actually reads.
 */
function hospitality_workbook_item_code(string $name, array $taken): string
{
    $base = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name));
    $base = trim((string) preg_replace('/-+/', '-', $base), '-');
    if ($base === '') {
        $base = 'ITEM';
    }
    $base = mb_substr($base, 0, 34);
    $code = $base;
    $suffix = 1;
    while (isset($taken[strtolower($code)])) {
        $suffix++;
        $code = mb_substr($base, 0, 34 - strlen((string) $suffix) - 1) . '-' . $suffix;
    }

    return $code;
}

/**
 * Create a menu item for anything on the sheet that has not been seen before.
 *
 * The menu is no longer typed in by hand — it IS whatever the tills have sold,
 * which is the only list that stays true as the kitchen changes. Every distinct
 * item on the sheet gets a menu item the first time it appears, and a sales
 * mapping pointing at it so the costing run can find it.
 *
 * One read for the existing menu and one for the existing mappings, then
 * inserts only for what is genuinely new; a sheet whose items are all known
 * writes nothing at all.
 */
function hospitality_workbook_sync_menu_items(int $companyId, array $itemRows, int $userId): array
{
    if (!table_exists('hospitality_menu_items')) {
        return ['created' => 0, 'mapped' => 0, 'items' => []];
    }

    $existingStmt = db()->prepare('SELECT id, code, name, category FROM hospitality_menu_items WHERE company_id = :cid');
    $existingStmt->execute(['cid' => $companyId]);
    $byName = [];
    $codesTaken = [];
    foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $menuItem) {
        $byName[hospitality_sales_norm((string) $menuItem['name'])] = $menuItem;
        $codesTaken[strtolower((string) $menuItem['code'])] = true;
    }

    // The distinct items on this sheet, each keeping the category and the
    // busiest day's unit price so a brand-new item lands with a sensible
    // standard price rather than zero.
    $wanted = [];
    foreach ($itemRows as $row) {
        if ($row['errors'] !== [] || (string) $row['item'] === '') {
            continue;
        }
        $key = hospitality_sales_norm((string) $row['item']);
        if ($key === '') {
            continue;
        }
        $qty = (float) $row['qty'];
        $unitPrice = $qty > 0 ? round((float) $row['amount'] / $qty, 2) : 0.0;
        if (!isset($wanted[$key])) {
            $wanted[$key] = ['name' => (string) $row['item'], 'category' => (string) $row['category'], 'price' => $unitPrice, 'qty' => $qty];
            continue;
        }
        if ($qty > $wanted[$key]['qty']) {
            $wanted[$key]['price'] = $unitPrice;
            $wanted[$key]['qty'] = $qty;
        }
    }

    $created = 0;
    $resolved = [];
    if ($wanted !== []) {
        $insert = db()->prepare('INSERT INTO hospitality_menu_items
                (company_id, code, name, category, standard_price, unit_of_sale, tax_inclusive, active, notes, created_by, updated_by)
            VALUES (:cid, :code, :name, :cat, :price, :unit, 0, 1, :notes, :by, :by2)');
        foreach ($wanted as $key => $want) {
            if (isset($byName[$key])) {
                $resolved[$key] = (int) $byName[$key]['id'];
                continue;
            }
            $code = hospitality_workbook_item_code($want['name'], $codesTaken);
            $codesTaken[strtolower($code)] = true;
            $insert->execute([
                'cid' => $companyId,
                'code' => $code,
                'name' => mb_substr($want['name'], 0, 160),
                'cat' => mb_substr($want['category'] !== '' ? $want['category'] : 'Other', 0, 40),
                'price' => $want['price'],
                'unit' => 'plate',
                'notes' => 'Created automatically from a daily sales upload.',
                // Bound twice because created_by and updated_by both take it,
                // and prepared statements here run unemulated: one placeholder
                // per bound value, no reuse.
                'by' => $userId ?: null,
                'by2' => $userId ?: null,
            ]);
            $resolved[$key] = (int) db()->lastInsertId();
            $created++;
        }
    }

    // A mapping per item, so the costing run can go from a sold line to the
    // recipe without matching on the name every time.
    $mapped = 0;
    if ($resolved !== [] && table_exists('hospitality_sales_mappings')) {
        $haveStmt = db()->prepare("SELECT description_norm FROM hospitality_sales_mappings
            WHERE company_id = :cid AND match_type = 'description'");
        $haveStmt->execute(['cid' => $companyId]);
        $have = array_flip(array_map('strval', $haveStmt->fetchAll(PDO::FETCH_COLUMN)));

        $mapStmt = db()->prepare("INSERT INTO hospitality_sales_mappings
                (company_id, match_type, description_norm, menu_item_id, status, active, notes, created_by)
            VALUES (:cid, 'description', :norm, :mid, 'mapped', 1, :notes, :by)");
        foreach ($resolved as $key => $menuItemId) {
            if (isset($have[$key])) {
                continue;
            }
            $mapStmt->execute([
                'cid' => $companyId,
                'norm' => mb_substr($key, 0, 255),
                'mid' => $menuItemId,
                'notes' => 'Mapped automatically from a daily sales upload.',
                'by' => $userId ?: null,
            ]);
            $mapped++;
        }
    }

    return ['created' => $created, 'mapped' => $mapped, 'items' => $resolved];
}


/**
 * Insert many rows a batch at a time instead of one statement each.
 *
 * A month of a busy kitchen is a few thousand item lines, and writing them one
 * statement apiece was most of the cost of posting an upload. Placeholders are
 * positional so nothing can collide the way a reused named one would.
 *
 * The chunk is bounded because a placeholder has a limit too: 200 rows of a
 * thirteen-column table is 2,600 of them, comfortably inside any server's.
 */
function hospitality_workbook_bulk_insert(string $table, array $columns, array $rows, int $chunkSize = 200): void
{
    if ($rows === []) {
        return;
    }
    $columnList = '`' . implode('`, `', $columns) . '`';
    $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    foreach (array_chunk($rows, $chunkSize) as $chunk) {
        $values = [];
        foreach ($chunk as $row) {
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? null;
            }
        }
        db()->prepare('INSERT INTO `' . $table . '` (' . $columnList . ') VALUES '
            . implode(', ', array_fill(0, count($chunk), $rowPlaceholder)))
            ->execute($values);
    }
}

// ----------------------------------------------------------- the whole upload

/**
 * Read, parse and reconcile an uploaded pair, ready for the preview screen.
 *
 * Returns ['error' => string] on anything fatal, otherwise everything the
 * preview and the posting step need.
 */
function hospitality_workbook_parse(string $primaryPath, string $primaryExt, ?string $secondPath, ?string $secondExt, int $companyId, int $fiscalYearId, array $settings): array
{
    $read = hospitality_workbook_read($primaryPath, $primaryExt, $secondPath, $secondExt);
    if (isset($read['error'])) {
        return ['error' => (string) $read['error']];
    }

    return hospitality_workbook_parse_rows($read['items'], $read['invoices'], $companyId, $fiscalYearId, $settings);
}

/**
 * The columns the sheet editor lays out, in the order it shows them.
 *
 * The editor writes rows in THIS shape and hands them straight back to the
 * parsers, so what is on screen and what is checked are the same thing. The
 * order matches the template, because somebody comparing the two should not
 * have to translate.
 */
function hospitality_workbook_editor_columns(string $sheet): array
{
    if ($sheet === 'invoices') {
        return ['date' => 'Date', 'invoice_no' => 'Invoice No', 'payment_type' => 'Payment Type',
            'ledger_code' => 'Party Ledger Code', 'amount' => 'Sales Amount', 'discount' => 'Less: Discount',
            'taxable' => 'Taxable Sales', 'vat' => 'VAT', 'total' => 'Sales with VAT'];
    }

    return ['date' => 'Date', 'category' => 'Category', 'item' => 'Item', 'qty' => 'Qty',
        'amount' => 'Total Sales Amount', 'discount' => 'Discount',
        'taxable' => 'Taxable Sales', 'vat' => 'VAT', 'total' => 'Sales with VAT'];
}

/**
 * Turn the editor's per-field rows back into the header-plus-cells shape the
 * parsers read, so an edited sheet is checked by exactly the same code an
 * uploaded one is.
 *
 * A row with nothing in it at all is dropped rather than reported: the editor
 * always shows a spare line or two, and a blank one is not a mistake.
 */
function hospitality_workbook_editor_to_cells(array $editorRows, string $sheet): array
{
    $columns = hospitality_workbook_editor_columns($sheet);
    $out = [['n' => 1, 'cells' => array_values($columns)]];
    $lineNo = 1;
    foreach ($editorRows as $row) {
        $cells = [];
        $anything = false;
        foreach (array_keys($columns) as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                $anything = true;
            }
            $cells[] = $value;
        }
        if (!$anything) {
            continue;
        }
        $lineNo++;
        $out[] = ['n' => $lineNo, 'cells' => $cells];
    }

    return $out;
}

/**
 * Parsed rows back into the editor's fields.
 *
 * The DATE goes back as the AD date the parser resolved, not the Bikram Sambat
 * the sheet was written in: what is being edited is what the system read, and
 * showing something other than that is how a correction gets made to the wrong
 * cell. A row the parser could not date at all keeps its raw text, because that
 * is exactly the row somebody is here to fix.
 */
function hospitality_workbook_rows_to_editor(array $rows, string $sheet): array
{
    $columns = array_keys(hospitality_workbook_editor_columns($sheet));
    $out = [];
    foreach ($rows as $row) {
        $line = [];
        foreach ($columns as $field) {
            if ($field === 'date') {
                $line['date'] = (string) ($row['date'] ?? '') !== ''
                    ? (string) $row['date']
                    : (string) ($row['date_raw'] ?? '');
                continue;
            }
            $value = $row[$field] ?? '';
            $line[$field] = is_float($value) || is_int($value)
                ? rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.')
                : (string) $value;
        }
        $out[] = $line;
    }

    return $out;
}

/**
 * Parse and reconcile a pair already read into rows.
 *
 * The file path version above goes through here, and so does the sheet editor,
 * which means a corrected row is checked by the same code that rejected it.
 */
function hospitality_workbook_parse_rows(array $itemRows, array $invoiceRows, int $companyId, int $fiscalYearId, array $settings): array
{
    $read = ['items' => $itemRows, 'invoices' => $invoiceRows];

    $fy = fiscal_year_by_id($fiscalYearId);
    $context = [
        'fy_start' => (string) ($fy['start_date'] ?? ''),
        'fy_end' => (string) ($fy['end_date'] ?? ''),
        'maps' => hospitality_sales_ledger_maps($companyId),
        'default_ledger' => hospitality_posting_ledger($companyId, (int) ($settings['post_sales_ledger_id'] ?? 0)),
        'vat_ledger' => hospitality_posting_ledger($companyId, (int) ($settings['post_vat_ledger_id'] ?? 0)),
        'discount_ledger' => hospitality_posting_ledger($companyId, (int) ($settings['post_discount_ledger_id'] ?? 0)),
        'receivable_ledger' => hospitality_posting_ledger($companyId, (int) ($settings['post_receivable_ledger_id'] ?? 0)),
        'ledger_lookup' => hospitality_workbook_ledger_lookup($companyId),
        'locked_through' => function_exists('period_locked_through')
            ? period_locked_through($companyId, $fiscalYearId)
            : null,
    ];

    $items = hospitality_workbook_parse_items($read['items'], $companyId, $fiscalYearId, $settings, $context);
    if (isset($items['error'])) {
        return ['error' => (string) $items['error']];
    }
    $invoices = hospitality_workbook_parse_invoices($read['invoices'], $companyId, $fiscalYearId, $settings, $context);
    if (isset($invoices['error'])) {
        return ['error' => (string) $invoices['error']];
    }

    $reconciliation = hospitality_workbook_reconcile($items, $invoices);

    return [
        'items' => $items,
        'invoices' => $invoices,
        'reconciliation' => $reconciliation,
        'duplicate_dates' => hospitality_sales_posted_dates($companyId, array_keys($items['days'])),
        // false: this upload's debit comes from the invoice sheet, so a
        // receivable ledger is neither used nor required.
        'config_errors' => hospitality_posting_config_errors($companyId, $settings, false),
    ];
}

/**
 * Post a reconciled pair: one sales voucher per day, debits from the invoice
 * sheet and credits from the item sheet.
 *
 * All-or-nothing — the batch, both sets of lines, every voucher and the menu
 * items the sheet introduced all commit together or none of them do.
 */
function hospitality_post_sales_workbook(int $companyId, int $fiscalYearId, array $parsed, string $fileName, int $userId, bool $allowDuplicateDates = false): array
{
    if (isset($parsed['error'])) {
        return ['ok' => false, 'error' => (string) $parsed['error']];
    }
    if ($parsed['config_errors'] !== []) {
        return ['ok' => false, 'error' => 'Posting setup incomplete: ' . implode(' ', $parsed['config_errors'])];
    }
    if ($parsed['items']['errors'] > 0 || $parsed['invoices']['errors'] > 0) {
        return ['ok' => false, 'error' => 'Some rows still have errors — fix them in the sheet and upload again. '
            . 'Posting part of a day would leave the two sheets out of step.'];
    }
    // The reconciliation is not skippable. Debits come from one sheet and
    // credits from the other, so a mismatched pair posts a voucher that
    // balances perfectly and is entirely wrong.
    if (!$parsed['reconciliation']['ok']) {
        return ['ok' => false, 'error' => 'The two sheets do not agree: ' . implode(' ', $parsed['reconciliation']['problems'])];
    }
    if ($parsed['duplicate_dates'] !== [] && !$allowDuplicateDates) {
        return ['ok' => false, 'error' => 'These dates were already posted from an earlier upload: '
            . implode(', ', $parsed['duplicate_dates']) . '. Tick "Post anyway" only if this sheet holds additional sales for those days.'];
    }

    $settings = hospitality_settings($companyId);
    $vatLedger = hospitality_posting_ledger($companyId, (int) ($settings['post_vat_ledger_id'] ?? 0));

    // Staff accountants working in a client's books never self-post — the same
    // control the voucher import applies.
    $hasApprovals = column_exists('vouchers', 'approval_state');
    $needsApproval = $hasApprovals && (
        (function_exists('staff_accountant_forces_approval') && staff_accountant_forces_approval())
        || ((approvals_enabled() || (function_exists('client_portal_forces_approval') && client_portal_forces_approval())) && !user_can('approve'))
    );

    $itemDays = $parsed['items']['days'];
    $invoiceDays = $parsed['invoices']['days'];
    $dates = array_keys($itemDays);
    sort($dates);
    if ($dates === []) {
        return ['ok' => false, 'error' => 'No valid rows to post.'];
    }

    $itemRows = array_values(array_filter($parsed['items']['rows'], static fn (array $r): bool => $r['errors'] === []));
    $invoiceRows = array_values(array_filter($parsed['invoices']['rows'], static fn (array $r): bool => $r['errors'] === []));
    $totals = $parsed['items']['totals'];

    // Bucket both sets of rows by date ONCE. Walking the whole sheet again
    // inside the per-day loop to find that day's rows is fine for a week and
    // silly for a year: a full year of a busy kitchen is a few hundred thousand
    // wasted comparisons to write the same lines.
    $itemRowsByDate = [];
    foreach ($itemRows as $row) {
        $itemRowsByDate[(string) $row['date']][] = $row;
    }
    $invoiceRowsByDate = [];
    foreach ($invoiceRows as $row) {
        $invoiceRowsByDate[(string) $row['date']][] = $row;
    }

    $now = date('Y-m-d H:i:s');
    $batchRef = strtoupper(bin2hex(random_bytes(2)));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO hospitality_sales_uploads
                (company_id, fiscal_year_id, file_name, date_from, date_to, row_count, invoice_count, voucher_count,
                 gross_amount, discount_amount, vat_amount, taxable_amount, receivable_amount, status, posted_by, posted_at)
            VALUES (:cid, :fy, :file, :df, :dt, :rows, :invs, 0, :gross, :disc, :vat, :taxable, :recv, :status, :by, :at)')
            ->execute([
                'cid' => $companyId, 'fy' => $fiscalYearId,
                'file' => mb_substr($fileName, 0, 255) ?: null,
                'df' => $dates[0], 'dt' => $dates[count($dates) - 1],
                'rows' => count($itemRows), 'invs' => count($invoiceRows),
                'gross' => $totals['amount'], 'disc' => $totals['discount'],
                'vat' => $totals['vat'], 'taxable' => $totals['taxable'],
                'recv' => $totals['total'],
                'status' => $needsApproval ? 'pending_approval' : 'posted',
                'by' => $userId, 'at' => $now,
            ]);
        $uploadId = (int) $pdo->lastInsertId();

        // Both sets of lines are collected as the days are posted and written
        // in batches at the end, rather than a statement per line.
        $pendingItemLines = [];
        $pendingInvoiceLines = [];

        $voucherCount = 0;
        foreach ($dates as $date) {
            $dayItems = $itemDays[$date];
            $dayInvoices = $invoiceDays[$date] ?? null;
            if ($dayInvoices === null) {
                throw new RuntimeException('No settlement rows for ' . $date . '.');
            }
            if ((float) $dayItems['vat'] > 0 && $vatLedger === null) {
                throw new RuntimeException('VAT ledger is not set in the posting setup.');
            }

            $entries = [];
            // Debits: one per ledger the day was settled to. This side is what
            // the customer actually paid, so it is never adjusted.
            $dayDebitTotal = 0.0;
            foreach ($dayInvoices['ledgers'] as $ledgerId => $leg) {
                $amount = round((float) $leg['total'], 2);
                if ($amount <= 0) {
                    continue;
                }
                $dayDebitTotal = round($dayDebitTotal + $amount, 2);
                $entries[] = [
                    'ledger_id' => (int) $ledgerId,
                    'entry_type' => 'debit',
                    'amount' => $amount,
                    'memo' => 'Daily sales settled to ' . (string) $leg['name'] . ' — ' . $date,
                ];
            }

            // The two sheets are allowed to disagree by under a rupee, because
            // that is what rounding hundreds of till lines does. A voucher is
            // allowed no such thing, so whatever is left over lands on the
            // largest sales line before any of this is written.
            $dayVat = round((float) $dayItems['vat'], 2);
            $absorbed = hospitality_workbook_absorb_rounding($dayItems['ledgers'], $dayDebitTotal, $dayVat);
            $dayCredits = $absorbed['credits'];
            $dayResidual = (float) $absorbed['residual'];
            $dayResidualLedger = $dayResidual !== 0.0 ? (int) $absorbed['ledger_id'] : 0;

            // Credits: one per category sold, at the taxable figure (net of
            // discount, which the sheet has already taken off).
            foreach ($dayCredits as $ledgerId => $leg) {
                $amount = round((float) $leg['taxable'], 2);
                if ($amount <= 0) {
                    continue;
                }
                $entries[] = [
                    'ledger_id' => (int) $ledgerId,
                    'entry_type' => 'credit',
                    'amount' => $amount,
                    // The line that took the rounding says so, so nobody has to
                    // work out later why a category is a few paisa off the sheet.
                    'memo' => 'Daily sales — ' . (string) $leg['category'] . ' — ' . $date
                        . ((int) $ledgerId === $dayResidualLedger
                            ? ' (includes ' . number_format($dayResidual, 2) . ' rounding)'
                            : ''),
                ];
            }
            if ($dayVat > 0) {
                $entries[] = [
                    'ledger_id' => (int) $vatLedger['id'],
                    'entry_type' => 'credit',
                    'amount' => $dayVat,
                    'memo' => 'VAT on daily sales — ' . $date,
                ];
            }

            $voucherCount++;
            $voucherNo = 'HS-' . str_replace('-', '', $date) . '-' . $batchRef . '-' . str_pad((string) $voucherCount, 3, '0', STR_PAD_LEFT);
            $voucherId = create_voucher_with_entries([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId,
                'voucher_no' => $voucherNo,
                'voucher_type' => 'sales',
                // vouchers has a UNIQUE key on (source_type, source_id); an
                // upload posts several day vouchers, so source_id stays NULL
                // and the batch linkage lives on the line tables.
                'source_type' => 'hospitality_sales_upload',
                'source_id' => null,
                'reference_no' => mb_substr('Day sheet ' . $date . ' (' . $fileName . ')', 0, 120),
                'voucher_date' => $date,
                'narration' => 'Hospitality daily sales for ' . $date . ' — ' . (int) $dayItems['rows'] . ' item line(s) settled across '
                    . count($dayInvoices['ledgers']) . ' ledger(s), from ' . ($fileName !== '' ? $fileName : 'the sales workbook') . '.'
                    . ($dayResidual !== 0.0
                        ? ' Sales carry ' . number_format($dayResidual, 2) . ' of rounding so the entry balances against what was settled.'
                        : ''),
                'total_amount' => round((float) $dayItems['total'], 2),
                'status' => $needsApproval ? 'draft' : 'posted',
                'approval_state' => $needsApproval ? 'pending_approval' : 'approved',
                'submitted_by' => $userId,
                'approved_by' => $needsApproval ? null : $userId,
                'approved_at' => $needsApproval ? null : $now,
                'posted_by' => $needsApproval ? null : $userId,
                'posted_at' => $needsApproval ? null : $now,
            ], $entries);
            if ($voucherId <= 0) {
                throw new RuntimeException('The voucher for ' . $date . ' could not be created.');
            }
            if ($needsApproval && function_exists('mark_voucher_requires_client_approval')
                && function_exists('staff_accountant_forces_approval') && staff_accountant_forces_approval()) {
                mark_voucher_requires_client_approval($voucherId);
            }

            foreach ($itemRowsByDate[$date] ?? [] as $row) {
                $pendingItemLines[] = [
                    'upload_id' => $uploadId, 'company_id' => $companyId, 'sale_date' => $date,
                    'category' => mb_substr((string) $row['category'], 0, 160),
                    'item_name' => mb_substr((string) $row['item'], 0, 255),
                    'qty' => $row['qty'], 'gross_amount' => $row['amount'], 'discount' => $row['discount'],
                    'vat_amount' => $row['vat'], 'taxable_amount' => $row['taxable'],
                    'sales_ledger_id' => (int) $row['ledger_id'], 'ledger_source' => $row['ledger_source'],
                    'voucher_id' => $voucherId,
                ];
            }
            foreach ($invoiceRowsByDate[$date] ?? [] as $row) {
                $pendingInvoiceLines[] = [
                    'upload_id' => $uploadId, 'company_id' => $companyId, 'sale_date' => $date,
                    'invoice_no' => $row['invoice_no'], 'payment_type' => $row['payment_type'],
                    'ledger_code' => $row['ledger_code'], 'ledger_id' => $row['ledger_id'],
                    'gross_amount' => $row['amount'], 'discount' => $row['discount'],
                    'taxable_amount' => $row['taxable'], 'vat_amount' => $row['vat'], 'total_amount' => $row['total'],
                    'voucher_id' => $voucherId,
                ];
            }
        }

        hospitality_workbook_bulk_insert('hospitality_sales_upload_lines', [
            'upload_id', 'company_id', 'sale_date', 'category', 'item_name', 'qty',
            'gross_amount', 'discount', 'vat_amount', 'taxable_amount',
            'sales_ledger_id', 'ledger_source', 'voucher_id',
        ], $pendingItemLines);
        hospitality_workbook_bulk_insert('hospitality_sales_invoice_lines', [
            'upload_id', 'company_id', 'sale_date', 'invoice_no', 'payment_type',
            'ledger_code', 'ledger_id', 'gross_amount', 'discount',
            'taxable_amount', 'vat_amount', 'total_amount', 'voucher_id',
        ], $pendingInvoiceLines);

        // The menu builds itself from what was sold.
        $menu = hospitality_workbook_sync_menu_items($companyId, $itemRows, $userId);

        $pdo->prepare('UPDATE hospitality_sales_uploads SET voucher_count = :vc WHERE id = :id')
            ->execute(['vc' => $voucherCount, 'id' => $uploadId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => 'Posting failed, nothing was saved: ' . $exception->getMessage()];
    }

    log_activity('hospitality_sales_upload', $uploadId,
        $needsApproval ? 'submitted' : 'posted',
        'Daily sales workbook ' . ($fileName !== '' ? '"' . $fileName . '" ' : '') . 'for ' . $dates[0] . ' to ' . $dates[count($dates) - 1]
        . ': ' . count($itemRows) . ' item line(s), ' . count($invoiceRows) . ' invoice(s), ' . $voucherCount . ' voucher(s) '
        . ($needsApproval ? 'submitted for approval' : 'auto-posted') . ' (billed '
        . number_format((float) $totals['total'], 2) . '). ' . $menu['created'] . ' menu item(s) created.', $userId);
    if (function_exists('security_event')) {
        security_event('voucher_posted', 'success', $voucherCount . ' hospitality daily sales voucher(s) '
            . ($needsApproval ? 'submitted for approval' : 'posted') . ' from workbook upload #' . $uploadId . '.', $companyId, $userId);
    }

    return [
        'ok' => true,
        'upload_id' => $uploadId,
        'vouchers' => $voucherCount,
        'rows' => count($itemRows),
        'invoices' => count($invoiceRows),
        'menu_created' => $menu['created'],
        'menu_mapped' => $menu['mapped'],
        'needs_approval' => $needsApproval,
    ];
}

// -------------------------------------------------------------------- reports

/**
 * The sales reports offered on the upload screen.
 *
 * The uploaded sheets ARE the record, so every one of these is a reading of
 * what was uploaded rather than a separate accumulation that could drift away
 * from it. "As uploaded" is the default because it is the sheet the person
 * looking at the screen just sent.
 */
function hospitality_sales_report_options(): array
{
    return [
        'sheet' => 'Item-wise sales (as uploaded)',
        'item' => 'Item-wise sales — totalled',
        'date' => 'Date-wise sales',
        'category' => 'Category-wise sales',
        'party' => 'Party-wise sales',
        'invoice' => 'Invoice-wise sales',
    ];
}

/**
 * One report over a date range.
 *
 * Every one of these is a single grouped query against an indexed date range,
 * so a year of trading costs the same one round trip a week does. The raw
 * "as uploaded" listing is the only one that can run long, and it is paged.
 *
 * Returns ['columns' => [[key, label, numeric]], 'rows' => [...],
 *          'totals' => [...], 'total_rows' => int].
 */
function hospitality_sales_report(int $companyId, string $from, string $to, string $key, int $page = 1, int $perPage = 200, string $sort = '', string $dir = 'asc'): array
{
    if (!table_exists('hospitality_sales_upload_lines')) {
        return ['columns' => [], 'rows' => [], 'totals' => [], 'total_rows' => 0];
    }
    $money = ['gross', 'discount', 'taxable', 'vat', 'total'];
    $bind = ['cid' => $companyId, 'from' => $from, 'to' => $to];

    // The money columns are the same five everywhere, so they are written once.
    $itemSums = 'SUM(l.gross_amount) AS gross, SUM(l.discount) AS discount, SUM(l.taxable_amount) AS taxable,
                 SUM(l.vat_amount) AS vat, SUM(l.taxable_amount + l.vat_amount) AS total';
    $moneyColumns = [
        ['gross', 'Sales Amount', true],
        ['discount', 'Discount', true],
        ['taxable', 'Taxable Sales', true],
        ['vat', 'VAT', true],
        ['total', 'Sales with VAT', true],
    ];

    switch ($key) {
        case 'item':
            $columns = array_merge([['label', 'Item', false], ['category', 'Category', false], ['qty', 'Qty', true]], $moneyColumns);
            $sql = "SELECT l.item_name AS label, MIN(l.category) AS category, SUM(l.qty) AS qty, $itemSums
                FROM hospitality_sales_upload_lines l
                WHERE l.company_id = :cid AND l.sale_date BETWEEN :from AND :to
                GROUP BY l.item_name ORDER BY total DESC, label ASC";
            break;

        case 'date':
            $columns = array_merge([['label', 'Date', false], ['line_count', 'Lines', true], ['qty', 'Qty', true]], $moneyColumns);
            $sql = "SELECT l.sale_date AS label, COUNT(*) AS line_count, SUM(l.qty) AS qty, $itemSums
                FROM hospitality_sales_upload_lines l
                WHERE l.company_id = :cid AND l.sale_date BETWEEN :from AND :to
                GROUP BY l.sale_date ORDER BY label ASC";
            break;

        case 'category':
            $columns = array_merge([['label', 'Category', false], ['line_count', 'Lines', true], ['qty', 'Qty', true]], $moneyColumns);
            $sql = "SELECT l.category AS label, COUNT(*) AS line_count, SUM(l.qty) AS qty, $itemSums
                FROM hospitality_sales_upload_lines l
                WHERE l.company_id = :cid AND l.sale_date BETWEEN :from AND :to
                GROUP BY l.category ORDER BY total DESC, label ASC";
            break;

        case 'party':
            if (!table_exists('hospitality_sales_invoice_lines')) {
                return ['columns' => [], 'rows' => [], 'totals' => [], 'total_rows' => 0, 'note' => 'Party-wise sales need the invoice sheet, which older uploads did not carry.'];
            }
            $columns = array_merge([['label', 'Party ledger', false], ['payment_type', 'Payment type', false], ['invoices', 'Invoices', true]], $moneyColumns);
            $sql = "SELECT COALESCE(led.name, CONCAT('(unmatched code ', i.ledger_code, ')')) AS label,
                    MIN(i.payment_type) AS payment_type, COUNT(*) AS invoices,
                    SUM(i.gross_amount) AS gross, SUM(i.discount) AS discount, SUM(i.taxable_amount) AS taxable,
                    SUM(i.vat_amount) AS vat, SUM(i.total_amount) AS total
                FROM hospitality_sales_invoice_lines i
                LEFT JOIN ledgers led ON led.id = i.ledger_id AND led.company_id = i.company_id
                WHERE i.company_id = :cid AND i.sale_date BETWEEN :from AND :to
                GROUP BY i.ledger_id, i.ledger_code ORDER BY total DESC, label ASC";
            break;

        case 'invoice':
            if (!table_exists('hospitality_sales_invoice_lines')) {
                return ['columns' => [], 'rows' => [], 'totals' => [], 'total_rows' => 0, 'note' => 'Invoice-wise sales need the invoice sheet, which older uploads did not carry.'];
            }
            $columns = array_merge([['label', 'Invoice No', false], ['sale_date', 'Date', false], ['payment_type', 'Payment type', false], ['party', 'Party ledger', false]], $moneyColumns);
            $sql = "SELECT i.invoice_no AS label, i.sale_date, i.payment_type,
                    COALESCE(led.name, i.ledger_code) AS party,
                    i.gross_amount AS gross, i.discount AS discount, i.taxable_amount AS taxable,
                    i.vat_amount AS vat, i.total_amount AS total
                FROM hospitality_sales_invoice_lines i
                LEFT JOIN ledgers led ON led.id = i.ledger_id AND led.company_id = i.company_id
                WHERE i.company_id = :cid AND i.sale_date BETWEEN :from AND :to
                ORDER BY i.sale_date ASC, i.id ASC";
            break;

        case 'sheet':
        default:
            $columns = [
                ['sale_date', 'Date', false], ['category', 'Category', false], ['label', 'Item', false], ['qty', 'Qty', true],
                ['gross', 'Total Sales Amount', true], ['discount', 'Discount', true],
                ['taxable', 'Taxable Sales', true], ['vat', 'VAT', true], ['total', 'Sales with VAT', true],
            ];
            $sql = "SELECT l.sale_date, l.category, l.item_name AS label, l.qty,
                    l.gross_amount AS gross, l.discount, l.taxable_amount AS taxable,
                    l.vat_amount AS vat, (l.taxable_amount + l.vat_amount) AS total
                FROM hospitality_sales_upload_lines l
                WHERE l.company_id = :cid AND l.sale_date BETWEEN :from AND :to
                ORDER BY l.sale_date ASC, l.id ASC";
            break;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Totals are added here rather than asked for again: the rows are already
    // in hand, and a second grouped query to re-add them would be a round trip
    // for arithmetic PHP can do for nothing.
    $totals = ['qty' => 0.0, 'line_count' => 0, 'invoices' => 0];
    foreach ($money as $field) {
        $totals[$field] = 0.0;
    }
    foreach ($rows as $row) {
        foreach ($money as $field) {
            $totals[$field] = round($totals[$field] + (float) ($row[$field] ?? 0), 2);
        }
        $totals['qty'] = round($totals['qty'] + (float) ($row['qty'] ?? 0), 3);
        $totals['line_count'] += (int) ($row['line_count'] ?? 0);
        $totals['invoices'] += (int) ($row['invoices'] ?? 0);
    }
    $totalRows = count($rows);

    // Sorted here rather than in SQL. The rows are already in hand, each report
    // is bounded by what a kitchen actually has, and a second query to put them
    // in a different order would be a round trip to do what usort does for
    // nothing. The key is checked against this report's OWN columns, so it can
    // only ever name a field that exists.
    $sortable = [];
    foreach ($columns as [$columnKey, $columnLabel, $columnNumeric]) {
        $sortable[$columnKey] = $columnNumeric;
    }
    if ($sort !== '' && isset($sortable[$sort])) {
        $numeric = $sortable[$sort];
        $descending = strtolower($dir) === 'desc';
        usort($rows, static function (array $a, array $b) use ($sort, $numeric, $descending): int {
            $left = $a[$sort] ?? null;
            $right = $b[$sort] ?? null;
            $order = $numeric
                ? ((float) $left <=> (float) $right)
                : strcasecmp((string) $left, (string) $right);

            return $descending ? -$order : $order;
        });
    }

    // Only the unaggregated listings can run to thousands of rows; the grouped
    // ones are bounded by how many items or days a kitchen actually has. Paging
    // comes AFTER the sort, or page one would not be the first rows.
    if (in_array($key, ['sheet', 'invoice'], true)) {
        $page = max(1, $page);
        $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
    }

    return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'total_rows' => $totalRows];
}

/**
 * One report flattened for a spreadsheet, headings and totals included.
 *
 * Built from the same columns and rows the screen renders, so the file and the
 * page cannot disagree about what the report says.
 */
function hospitality_sales_report_export_rows(array $report): array
{
    $out = [];
    $header = [];
    foreach ($report['columns'] as [$columnKey, $columnLabel, $columnNumeric]) {
        $header[] = $columnLabel;
    }
    $out[] = $header;

    foreach ($report['rows'] as $row) {
        $line = [];
        foreach ($report['columns'] as [$columnKey, $columnLabel, $columnNumeric]) {
            $value = $row[$columnKey] ?? '';
            // Numbers go in as numbers so the spreadsheet can total them; only
            // the labels are text.
            $line[] = $columnNumeric ? (float) $value : (string) $value;
        }
        $out[] = $line;
    }

    if ($report['rows'] !== []) {
        $totalLine = [];
        foreach ($report['columns'] as $index => [$columnKey, $columnLabel, $columnNumeric]) {
            if ($index === 0) {
                $totalLine[] = 'Total (' . (int) $report['total_rows'] . ' rows)';
                continue;
            }
            $totalLine[] = $columnNumeric && isset($report['totals'][$columnKey])
                ? (float) $report['totals'][$columnKey]
                : '';
        }
        $out[] = $totalLine;
    }

    return $out;
}

/**
 * The date range the reports open on: the last sheet uploaded.
 *
 * Somebody arriving at this screen has almost always just uploaded something,
 * and what they want to see is what they just sent.
 */
function hospitality_sales_report_default_range(int $companyId): array
{
    if (table_exists('hospitality_sales_uploads')) {
        $stmt = db()->prepare('SELECT date_from, date_to FROM hospitality_sales_uploads
            WHERE company_id = :cid ORDER BY id DESC LIMIT 1');
        $stmt->execute(['cid' => $companyId]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($last) {
            return [(string) $last['date_from'], (string) $last['date_to']];
        }
    }

    return [date('Y-m-01'), date('Y-m-d')];
}
