<?php
declare(strict_types=1);

/**
 * Hospitality daily sales upload — Excel day-sheets POSTED to accounting.
 *
 * Unlike hospitality_engine.php (reference-only costing), this file is the
 * module's posting path: a daywise sales report (Date, Category, Item, Qty,
 * Total Sales Amount, optional Discount / VAT) is parsed, previewed, and
 * auto-posted through create_voucher_with_entries() as ONE balanced sales
 * voucher per sale date:
 *     Dr  Receivable ledger        (gross - discount)
 *     Dr  Discount ledger          (discount, when any)
 *     Cr  Sales ledger(s)          (taxable, split per category/item mapping)
 *     Cr  VAT payable ledger       (VAT, when any)
 *
 * Ledger mapping is tenant-specific and lives inside Hospitality: default
 * Sales / VAT / Discount / Receivable ledgers on hospitality_settings, plus
 * hospitality_sales_ledger_maps rows so each CATEGORY (or a specific item —
 * the item override wins) posts to its own sales ledger.
 */

require_once __DIR__ . '/hospitality_engine.php'; // settings + gating (reference-only file)
require_once __DIR__ . '/voucher_import.php'; // sheet readers, date + amount parsing

const HOSPITALITY_SALES_MAX_ROWS = 5000;

/** Normalized match key for categories / item names (lowercase, single spaces). */
function hospitality_sales_norm(string $value): string
{
    return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
}

/** Active ledgers of the company for the mapping dropdowns, ordered by name. */
function hospitality_posting_ledger_options(int $companyId): array
{
    $stmt = db()->prepare("SELECT l.id, l.code, l.name, l.type FROM ledgers l
        WHERE l.company_id = :cid AND l.status = 'active' ORDER BY l.name ASC");
    $stmt->execute(['cid' => $companyId]);
    return $stmt->fetchAll();
}

/** Ledger row if it is active and belongs to the company, else null. */
function hospitality_posting_ledger(int $companyId, int $ledgerId): ?array
{
    if ($ledgerId <= 0) {
        return null;
    }
    $stmt = db()->prepare("SELECT id, code, name, type FROM ledgers
        WHERE id = :id AND company_id = :cid AND status = 'active' LIMIT 1");
    $stmt->execute(['id' => $ledgerId, 'cid' => $companyId]);
    return $stmt->fetch() ?: null;
}

/**
 * What is still missing before sheets can be posted. The discount ledger is
 * only demanded once a sheet actually carries discounts, and the VAT ledger
 * once VAT is being extracted, so those are checked at parse time too.
 */
function hospitality_posting_config_errors(int $companyId, array $settings): array
{
    $errors = [];
    if (hospitality_posting_ledger($companyId, (int) ($settings['post_sales_ledger_id'] ?? 0)) === null) {
        $errors[] = 'Default Sales ledger is not set (used for categories without their own mapping).';
    }
    if (hospitality_posting_ledger($companyId, (int) ($settings['post_receivable_ledger_id'] ?? 0)) === null) {
        $errors[] = 'Receivable ledger is not set (debited with the day\'s collectable amount).';
    }
    return $errors;
}

/** Mapping rows keyed for resolution: ['item' => [norm => row], 'category' => [norm => row]]. */
function hospitality_sales_ledger_maps(int $companyId): array
{
    $stmt = db()->prepare('SELECT m.*, l.name AS ledger_name, l.code AS ledger_code
        FROM hospitality_sales_ledger_maps m
        INNER JOIN ledgers l ON l.id = m.sales_ledger_id AND l.company_id = m.company_id
        WHERE m.company_id = :cid AND m.active = 1 AND l.status = \'active\'
        ORDER BY m.map_type ASC, m.display_value ASC');
    $stmt->execute(['cid' => $companyId]);
    $maps = ['item' => [], 'category' => []];
    foreach ($stmt->fetchAll() as $row) {
        $maps[(string) $row['map_type']][(string) $row['match_value']] = (array) $row;
    }
    return $maps;
}

/**
 * Sales ledger for one sheet row. Priority: exact ITEM mapping, then the
 * row's CATEGORY mapping, then the default sales ledger. Returns
 * ['ledger_id', 'ledger_label', 'source' => 'item'|'category'|'default'] or
 * nulls when even the default is missing.
 */
function hospitality_resolve_sales_ledger(array $maps, ?array $defaultLedger, string $category, string $item): array
{
    $itemMap = $maps['item'][hospitality_sales_norm($item)] ?? null;
    if ($itemMap !== null) {
        return [
            'ledger_id' => (int) $itemMap['sales_ledger_id'],
            'ledger_label' => $itemMap['ledger_name'] . ' (' . $itemMap['ledger_code'] . ')',
            'source' => 'item',
        ];
    }
    $categoryMap = $maps['category'][hospitality_sales_norm($category)] ?? null;
    if ($categoryMap !== null) {
        return [
            'ledger_id' => (int) $categoryMap['sales_ledger_id'],
            'ledger_label' => $categoryMap['ledger_name'] . ' (' . $categoryMap['ledger_code'] . ')',
            'source' => 'category',
        ];
    }
    if ($defaultLedger !== null) {
        return [
            'ledger_id' => (int) $defaultLedger['id'],
            'ledger_label' => $defaultLedger['name'] . ' (' . $defaultLedger['code'] . ')',
            'source' => 'default',
        ];
    }
    return ['ledger_id' => null, 'ledger_label' => null, 'source' => null];
}

/** Map the sheet's header row to column indexes for the sales layout. */
function hospitality_sales_map_headers(array $headerCells): array
{
    $aliases = [
        'date' => ['date', 'salesdate', 'billdate', 'miti', 'dateadorbs', 'dateadbs', 'day'],
        'category' => ['category', 'cat', 'group', 'salescategory', 'itemcategory', 'department'],
        'item' => ['item', 'itemname', 'menuitem', 'particulars', 'description', 'product', 'itemdescription'],
        'qty' => ['qty', 'quantity', 'units', 'nos', 'qtysold', 'noofunits'],
        'amount' => ['totalsalesamount', 'totalsales', 'salesamount', 'amount', 'total', 'totalamount', 'grossamount', 'grosssales', 'sales'],
        'discount' => ['discount', 'disc', 'discountamount', 'less'],
        'vat' => ['vat', 'vatamount', 'tax', 'taxamount'],
    ];
    $map = [];
    foreach ($headerCells as $index => $cell) {
        $key = strtolower((string) preg_replace('/[^a-z]/i', '', (string) $cell));
        if ($key === '') {
            continue;
        }
        foreach ($aliases as $field => $names) {
            if (in_array($key, $names, true) && !isset($map[$field])) {
                $map[$field] = $index;
                continue 2;
            }
        }
    }
    return $map;
}

/** Sale dates already covered by an earlier posted upload (duplicate guard). */
function hospitality_sales_posted_dates(int $companyId, array $dates): array
{
    if ($dates === [] || !table_exists('hospitality_sales_upload_lines')) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($dates), '?'));
    $stmt = db()->prepare('SELECT DISTINCT sale_date FROM hospitality_sales_upload_lines
        WHERE company_id = ? AND sale_date IN (' . $placeholders . ')');
    $stmt->execute(array_merge([$companyId], array_values($dates)));
    $posted = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    sort($posted);
    return $posted;
}

/**
 * Parse an uploaded daywise sales sheet and price out every row.
 *
 * Amount semantics (documented in the upload screen): Total Sales Amount is
 * the amount charged for the row BEFORE discount; it includes VAT when the
 * "amounts include VAT" setting is on. A VAT column, when present with a
 * value, overrides the extraction. Discount is a separate optional column
 * that reduces the receivable and is debited to the discount ledger.
 *
 * Returns ['error' => string] on a fatal sheet problem, otherwise:
 * ['rows' => [...], 'days' => [...], 'totals' => [...], 'valid_count', 'error_count',
 *  'duplicate_dates' => [...], 'config_errors' => [...]]
 */
function hospitality_sales_upload_parse(string $path, string $extension, int $companyId, int $fiscalYearId, array $settings): array
{
    $sheetRows = voucher_import_read_rows($path, $extension);

    $headerMap = [];
    $headerRowIndex = null;
    foreach ($sheetRows as $index => $row) {
        $candidate = hospitality_sales_map_headers($row['cells']);
        if (isset($candidate['date'], $candidate['category'], $candidate['item'], $candidate['amount'])) {
            $headerMap = $candidate;
            $headerRowIndex = $index;
            break;
        }
    }
    if ($headerRowIndex === null) {
        return ['error' => 'No header row found. The sheet needs at least the columns Date, Category, Item, and Total Sales Amount — download the template to see the expected layout.'];
    }

    $fy = fiscal_year_by_id($fiscalYearId);
    $fyStart = (string) ($fy['start_date'] ?? '');
    $fyEnd = (string) ($fy['end_date'] ?? '');

    $defaultLedger = hospitality_posting_ledger($companyId, (int) ($settings['post_sales_ledger_id'] ?? 0));
    $vatLedger = hospitality_posting_ledger($companyId, (int) ($settings['post_vat_ledger_id'] ?? 0));
    $discountLedger = hospitality_posting_ledger($companyId, (int) ($settings['post_discount_ledger_id'] ?? 0));
    $receivableLedger = hospitality_posting_ledger($companyId, (int) ($settings['post_receivable_ledger_id'] ?? 0));
    $maps = hospitality_sales_ledger_maps($companyId);
    $vatRate = max(0.0, (float) ($settings['post_vat_rate'] ?? 13.00));
    $includesVat = (int) ($settings['post_amount_includes_vat'] ?? 1) === 1;

    $cell = static fn (array $row, string $field): string => trim((string) ($row['cells'][$headerMap[$field] ?? -1] ?? ''));

    $rows = [];
    $days = [];
    $totals = ['qty' => 0.0, 'gross' => 0.0, 'discount' => 0.0, 'vat' => 0.0, 'taxable' => 0.0, 'receivable' => 0.0];
    $validCount = 0;
    $errorCount = 0;

    foreach (array_slice($sheetRows, $headerRowIndex + 1) as $row) {
        if (trim(implode('', $row['cells'])) === '') {
            continue;
        }
        if (count($rows) >= HOSPITALITY_SALES_MAX_ROWS) {
            return ['error' => 'The sheet has more than ' . number_format(HOSPITALITY_SALES_MAX_ROWS) . ' data rows — split it into smaller files.'];
        }

        $errors = [];
        $dateRaw = $cell($row, 'date');
        $date = voucher_import_date($dateRaw);
        if ($date === null) {
            $errors[] = $dateRaw === ''
                ? 'Date is missing.'
                : 'Date "' . $dateRaw . '" is not valid — use YYYY-MM-DD in AD or BS (years 2064+ are read as BS).';
        } elseif ($fyStart !== '' && ($date < $fyStart || $date > $fyEnd)) {
            $errors[] = 'Date ' . $date . ' falls outside the selected fiscal year (' . $fyStart . ' to ' . $fyEnd . ').';
        } elseif (function_exists('is_period_locked') && is_period_locked($companyId, $fiscalYearId, $date)) {
            $errors[] = 'Date ' . $date . ' falls inside a locked accounting period.';
        }

        $category = $cell($row, 'category');
        if ($category === '') {
            $errors[] = 'Category is missing.';
        }
        $item = $cell($row, 'item');
        if ($item === '') {
            $errors[] = 'Item is missing.';
        }

        $qty = isset($headerMap['qty']) ? voucher_import_amount($cell($row, 'qty')) : 0.0;
        if ($qty < 0) {
            $errors[] = 'Quantity cannot be negative.';
        }
        $gross = voucher_import_amount($cell($row, 'amount'));
        if ($gross <= 0) {
            $errors[] = 'Total Sales Amount must be greater than zero.';
        }
        $discount = isset($headerMap['discount']) ? voucher_import_amount($cell($row, 'discount')) : 0.0;
        if ($discount < 0) {
            $errors[] = 'Discount cannot be negative.';
        }
        if ($discount > $gross) {
            $errors[] = 'Discount is larger than the sales amount.';
        }

        // VAT: an explicit sheet value wins; otherwise extract from / add on
        // top of the amount according to the tenant's posting settings.
        $vatCellRaw = isset($headerMap['vat']) ? $cell($row, 'vat') : '';
        if ($vatCellRaw !== '') {
            $vat = voucher_import_amount($vatCellRaw);
            if ($vat < 0) {
                $errors[] = 'VAT cannot be negative.';
                $vat = 0.0;
            }
            $taxable = $includesVat ? round($gross - $vat, 2) : $gross;
        } elseif ($vatRate > 0) {
            if ($includesVat) {
                $taxable = round($gross / (1 + $vatRate / 100), 2);
                $vat = round($gross - $taxable, 2);
            } else {
                $taxable = $gross;
                $vat = round($gross * $vatRate / 100, 2);
            }
        } else {
            $taxable = $gross;
            $vat = 0.0;
        }
        if ($taxable <= 0 && $gross > 0) {
            $errors[] = 'VAT leaves no taxable amount on this row.';
        }
        $lineTotal = round($taxable + $vat, 2);

        $resolved = hospitality_resolve_sales_ledger($maps, $defaultLedger, $category, $item);
        if ($resolved['ledger_id'] === null) {
            $errors[] = 'No sales ledger: map the category "' . $category . '" (or the item) to a ledger, or set the default Sales ledger.';
        }
        if ($vat > 0 && $vatLedger === null) {
            $errors[] = 'VAT ledger is not set in the posting setup.';
        }
        if ($discount > 0 && $discountLedger === null) {
            $errors[] = 'Discount ledger is not set in the posting setup.';
        }
        if ($receivableLedger === null) {
            $errors[] = 'Receivable ledger is not set in the posting setup.';
        }

        $rows[] = [
            'n' => $row['n'],
            'date' => $date,
            'date_raw' => $dateRaw,
            'category' => $category,
            'item' => $item,
            'qty' => round($qty, 3),
            'gross' => $gross,
            'discount' => round($discount, 2),
            'vat' => $vat,
            'taxable' => $taxable,
            'line_total' => $lineTotal,
            'receivable' => round($lineTotal - $discount, 2),
            'ledger_id' => $resolved['ledger_id'],
            'ledger_label' => $resolved['ledger_label'],
            'ledger_source' => $resolved['source'],
            'errors' => $errors,
        ];

        if ($errors === []) {
            $validCount++;
            $totals['qty'] += $qty;
            $totals['gross'] = round($totals['gross'] + $lineTotal, 2);
            $totals['discount'] = round($totals['discount'] + $discount, 2);
            $totals['vat'] = round($totals['vat'] + $vat, 2);
            $totals['taxable'] = round($totals['taxable'] + $taxable, 2);
            $totals['receivable'] = round($totals['receivable'] + $lineTotal - $discount, 2);
            $day = &$days[$date];
            if (!isset($day)) {
                $day = ['date' => $date, 'rows' => 0, 'qty' => 0.0, 'gross' => 0.0, 'discount' => 0.0, 'vat' => 0.0, 'taxable' => 0.0, 'receivable' => 0.0, 'ledgers' => []];
            }
            $day['rows']++;
            $day['qty'] += $qty;
            $day['gross'] = round($day['gross'] + $lineTotal, 2);
            $day['discount'] = round($day['discount'] + $discount, 2);
            $day['vat'] = round($day['vat'] + $vat, 2);
            $day['taxable'] = round($day['taxable'] + $taxable, 2);
            $day['receivable'] = round($day['receivable'] + $lineTotal - $discount, 2);
            $ledgerKey = (int) $resolved['ledger_id'];
            $day['ledgers'][$ledgerKey]['label'] = (string) $resolved['ledger_label'];
            $day['ledgers'][$ledgerKey]['taxable'] = round(($day['ledgers'][$ledgerKey]['taxable'] ?? 0) + $taxable, 2);
            unset($day);
        } else {
            $errorCount++;
        }
    }

    if ($rows === []) {
        return ['error' => 'The sheet has no data rows below the header.'];
    }
    ksort($days);

    return [
        'rows' => $rows,
        'days' => array_values($days),
        'totals' => $totals,
        'valid_count' => $validCount,
        'error_count' => $errorCount,
        'duplicate_dates' => hospitality_sales_posted_dates($companyId, array_keys($days)),
        'config_errors' => hospitality_posting_config_errors($companyId, $settings),
    ];
}

/**
 * Post a parsed sheet: one sales voucher per sale date, plus the audit batch.
 * Rows with errors must already be excluded by the caller ($parsed comes from
 * hospitality_sales_upload_parse on the same stored file). All-or-nothing:
 * the batch, its lines, and every voucher commit in one transaction.
 *
 * Returns ['ok' => bool, 'error' => ?, 'upload_id' => ?, 'vouchers' => int,
 *          'rows' => int, 'needs_approval' => bool].
 */
function hospitality_post_sales_upload(int $companyId, int $fiscalYearId, array $parsed, string $fileName, int $userId, bool $allowDuplicateDates = false): array
{
    if (isset($parsed['error'])) {
        return ['ok' => false, 'error' => (string) $parsed['error']];
    }
    if ($parsed['config_errors'] !== []) {
        return ['ok' => false, 'error' => 'Posting setup incomplete: ' . implode(' ', $parsed['config_errors'])];
    }
    $validRows = array_values(array_filter($parsed['rows'], static fn (array $r): bool => $r['errors'] === []));
    if ($validRows === []) {
        return ['ok' => false, 'error' => 'No valid rows to post — fix the errors shown in the preview and upload again.'];
    }
    if ($parsed['duplicate_dates'] !== [] && !$allowDuplicateDates) {
        return ['ok' => false, 'error' => 'These dates were already posted from an earlier upload: '
            . implode(', ', $parsed['duplicate_dates']) . '. Tick "Post anyway" only if this sheet holds additional sales for those days.'];
    }

    $settings = hospitality_settings($companyId);
    $vatLedger = hospitality_posting_ledger($companyId, (int) ($settings['post_vat_ledger_id'] ?? 0));
    $discountLedger = hospitality_posting_ledger($companyId, (int) ($settings['post_discount_ledger_id'] ?? 0));
    $receivableLedger = hospitality_posting_ledger($companyId, (int) ($settings['post_receivable_ledger_id'] ?? 0));
    if ($receivableLedger === null) {
        return ['ok' => false, 'error' => 'Receivable ledger is not set in the posting setup.'];
    }

    // Staff accountants working in a client's books never self-post — the
    // same control the voucher import applies (vouchers go for approval).
    $hasApprovals = column_exists('vouchers', 'approval_state');
    $needsApproval = $hasApprovals && (
        (function_exists('staff_accountant_forces_approval') && staff_accountant_forces_approval())
        || ((approvals_enabled() || (function_exists('client_portal_forces_approval') && client_portal_forces_approval())) && !user_can('approve'))
    );

    // Regroup the valid rows by date (the preview's day grouping, rebuilt
    // server-side so tampered previews cannot change what is posted).
    $byDate = [];
    foreach ($validRows as $row) {
        $byDate[(string) $row['date']][] = $row;
    }
    ksort($byDate);
    $dates = array_keys($byDate);

    $now = date('Y-m-d H:i:s');
    $batchRef = strtoupper(bin2hex(random_bytes(2)));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO hospitality_sales_uploads
                (company_id, fiscal_year_id, file_name, date_from, date_to, row_count, voucher_count,
                 gross_amount, discount_amount, vat_amount, taxable_amount, receivable_amount, status, posted_by, posted_at)
            VALUES (:cid, :fy, :file, :df, :dt, :rows, 0, :gross, :disc, :vat, :taxable, :recv, :status, :by, :at)')
            ->execute([
                'cid' => $companyId, 'fy' => $fiscalYearId,
                'file' => mb_substr($fileName, 0, 255) ?: null,
                'df' => $dates[0], 'dt' => $dates[count($dates) - 1],
                'rows' => count($validRows),
                'gross' => $parsed['totals']['gross'], 'disc' => $parsed['totals']['discount'],
                'vat' => $parsed['totals']['vat'], 'taxable' => $parsed['totals']['taxable'],
                'recv' => $parsed['totals']['receivable'],
                'status' => $needsApproval ? 'pending_approval' : 'posted',
                'by' => $userId, 'at' => $now,
            ]);
        $uploadId = (int) $pdo->lastInsertId();

        $lineStmt = $pdo->prepare('INSERT INTO hospitality_sales_upload_lines
                (upload_id, company_id, sale_date, category, item_name, qty, gross_amount, discount, vat_amount, taxable_amount, sales_ledger_id, ledger_source, voucher_id)
            VALUES (:up, :cid, :d, :cat, :item, :qty, :gross, :disc, :vat, :taxable, :ledger, :src, :vch)');

        $voucherCount = 0;
        foreach ($byDate as $date => $dayRows) {
            $dayTaxableByLedger = [];
            $dayVat = 0.0;
            $dayDiscount = 0.0;
            $dayGross = 0.0;
            foreach ($dayRows as $row) {
                $ledgerId = (int) $row['ledger_id'];
                $dayTaxableByLedger[$ledgerId] = round(($dayTaxableByLedger[$ledgerId] ?? 0) + $row['taxable'], 2);
                $dayVat = round($dayVat + $row['vat'], 2);
                $dayDiscount = round($dayDiscount + $row['discount'], 2);
                $dayGross = round($dayGross + $row['line_total'], 2);
            }
            $dayReceivable = round($dayGross - $dayDiscount, 2);
            if ($dayVat > 0 && $vatLedger === null) {
                throw new RuntimeException('VAT ledger is not set in the posting setup.');
            }
            if ($dayDiscount > 0 && $discountLedger === null) {
                throw new RuntimeException('Discount ledger is not set in the posting setup.');
            }

            $entries = [[
                'ledger_id' => (int) $receivableLedger['id'],
                'entry_type' => 'debit',
                'amount' => $dayReceivable,
                'memo' => 'Daily hospitality sales receivable — ' . $date,
            ]];
            if ($dayDiscount > 0) {
                $entries[] = [
                    'ledger_id' => (int) $discountLedger['id'],
                    'entry_type' => 'debit',
                    'amount' => $dayDiscount,
                    'memo' => 'Discount allowed on daily sales — ' . $date,
                ];
            }
            foreach ($dayTaxableByLedger as $ledgerId => $taxableAmount) {
                if ($taxableAmount <= 0) {
                    continue;
                }
                $entries[] = [
                    'ledger_id' => (int) $ledgerId,
                    'entry_type' => 'credit',
                    'amount' => $taxableAmount,
                    'memo' => 'Daily hospitality sales — ' . $date,
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
                // (voucher-import pattern) and the batch linkage lives in
                // hospitality_sales_upload_lines.voucher_id.
                'source_type' => 'hospitality_sales_upload',
                'source_id' => null,
                'reference_no' => mb_substr('Day sheet ' . $date . ' (' . $fileName . ')', 0, 120),
                'voucher_date' => $date,
                'narration' => 'Hospitality daily sales for ' . $date . ' — ' . count($dayRows) . ' line(s) uploaded from ' . ($fileName !== '' ? $fileName : 'sales sheet') . '.',
                'total_amount' => round($dayReceivable + $dayDiscount, 2),
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

            foreach ($dayRows as $row) {
                $lineStmt->execute([
                    'up' => $uploadId, 'cid' => $companyId, 'd' => $date,
                    'cat' => mb_substr((string) $row['category'], 0, 160),
                    'item' => mb_substr((string) $row['item'], 0, 255),
                    'qty' => $row['qty'], 'gross' => $row['line_total'], 'disc' => $row['discount'],
                    'vat' => $row['vat'], 'taxable' => $row['taxable'],
                    'ledger' => (int) $row['ledger_id'], 'src' => $row['ledger_source'],
                    'vch' => $voucherId,
                ]);
            }
        }

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
        'Daily sales sheet ' . ($fileName !== '' ? '"' . $fileName . '" ' : '') . 'for ' . $dates[0] . ' to ' . $dates[count($dates) - 1]
        . ': ' . count($validRows) . ' row(s), ' . $voucherCount . ' voucher(s) '
        . ($needsApproval ? 'submitted for approval' : 'auto-posted') . ' (receivable '
        . number_format((float) $parsed['totals']['receivable'], 2) . ').', $userId);
    if (function_exists('security_event')) {
        security_event('voucher_posted', 'success', $voucherCount . ' hospitality daily sales voucher(s) '
            . ($needsApproval ? 'submitted for approval' : 'posted') . ' from upload #' . $uploadId . '.', $companyId, $userId);
    }

    return [
        'ok' => true,
        'upload_id' => $uploadId,
        'vouchers' => $voucherCount,
        'rows' => count($validRows),
        'needs_approval' => $needsApproval,
    ];
}

/** Recent upload batches with the poster's name for the history table. */
function hospitality_sales_uploads_history(int $companyId, int $limit = 20): array
{
    $stmt = db()->prepare('SELECT u.*, usr.name AS posted_by_name
        FROM hospitality_sales_uploads u LEFT JOIN users usr ON usr.id = u.posted_by
        WHERE u.company_id = :cid ORDER BY u.id DESC LIMIT ' . max(1, $limit));
    $stmt->execute(['cid' => $companyId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Templates.
// ---------------------------------------------------------------------------

function hospitality_sales_template_rows(): array
{
    return [
        ['Date (AD or BS)', 'Category', 'Item', 'Qty', 'Total Sales Amount', 'Discount', 'VAT'],
        ['2083-03-24', 'Food', 'Chicken Momo (10 pcs)', 42, 12600, 0, ''],
        ['2083-03-24', 'Food', 'Veg Chowmein', 18, 3600, 200, ''],
        ['2083-03-24', 'Beverage', 'Masala Tea', 65, 3250, 0, ''],
        ['2083-03-24', 'Bar', 'Local Beer 650ml', 12, 6000, 0, ''],
        ['2083-03-25', 'Food', 'Chicken Momo (10 pcs)', 38, 11400, 0, ''],
        ['2083-03-25', 'Beverage', 'Cold Coffee', 20, 5000, 0, ''],
    ];
}

function hospitality_sales_template_csv(): string
{
    $handle = fopen('php://temp', 'r+b');
    foreach (hospitality_sales_template_rows() as $row) {
        fputcsv($handle, $row);
    }
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);
    return $csv;
}

/** Minimal single-sheet .xlsx with inline strings (same writer style as the voucher import). */
function hospitality_sales_template_xlsx(): string
{
    $xml = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $rowsXml = '';
    foreach (hospitality_sales_template_rows() as $rowIndex => $row) {
        $cellsXml = '';
        foreach ($row as $columnIndex => $value) {
            $letters = '';
            $n = $columnIndex + 1;
            while ($n > 0) {
                $letters = chr(65 + (($n - 1) % 26)) . $letters;
                $n = intdiv($n - 1, 26);
            }
            $ref = $letters . ($rowIndex + 1);
            if (is_int($value) || is_float($value)) {
                $cellsXml .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
            } elseif ((string) $value !== '') {
                $cellsXml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $xml((string) $value) . '</t></is></c>';
            }
        }
        $rowsXml .= '<row r="' . ($rowIndex + 1) . '">' . $cellsXml . '</row>';
    }

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Daily Sales" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="1"><xf xfId="0"/></cellXfs>'
            . '</styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<cols><col min="1" max="1" width="16" customWidth="1"/><col min="2" max="3" width="24" customWidth="1"/><col min="4" max="7" width="14" customWidth="1"/></cols>'
            . '<sheetData>' . $rowsXml . '</sheetData></worksheet>',
    ];

    $temp = tempnam(sys_get_temp_dir(), 'hsls');
    $zip = new ZipArchive();
    $zip->open($temp, ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $bytes = (string) file_get_contents($temp);
    @unlink($temp);
    return $bytes;
}
