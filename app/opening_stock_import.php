<?php
declare(strict_types=1);

/**
 * Opening stock from a spreadsheet — upload, preview, edit, delete, commit.
 *
 * The rule this file exists to enforce: AN UPLOAD POSTS NOTHING. A sheet is
 * parsed into staged rows that can be looked at and corrected first, and only a
 * separate, deliberate commit turns them into opening stock. Opening balances
 * are the hardest thing in the books to unpick after the fact, and a shop's own
 * spreadsheet is never as clean as it looks — items spelled differently,
 * quantities in the wrong unit, a total row at the bottom that is not an item
 * at all.
 *
 * A row that cannot be imported is KEPT, with the reason on it. Dropping bad
 * rows silently is how "I uploaded 47 items and only 44 arrived" becomes
 * impossible to explain.
 *
 * Reading the file reuses voucher_import.php's .xlsx/.csv reader — the app has
 * one spreadsheet reader and this is not going to be the second.
 */

require_once __DIR__ . '/voucher_import.php';
require_once __DIR__ . '/export_engine.php';

const OPENING_IMPORT_MAX_ROWS = 5000;

/** The columns the sheet may use, in the order the template writes them. */
function opening_import_columns(): array
{
    return [
        'code' => ['Item code', ['item code', 'code', 'sku', 'item', 'item_code']],
        'name' => ['Item name', ['item name', 'name', 'description', 'particulars']],
        'purity' => ['Purity', ['purity', 'karat', 'carat', 'fineness']],
        'unit' => ['Unit', ['unit', 'uom', 'weight unit']],
        'qty_pieces' => ['Pieces', ['pieces', 'qty', 'quantity', 'pcs', 'nos']],
        'gross_weight' => ['Gross weight', ['gross weight', 'weight', 'gross', 'gross wt']],
        'stone_weight' => ['Stone weight', ['stone weight', 'stone wt', 'stone']],
        'diamond_weight' => ['Diamond weight', ['diamond weight', 'diamond wt', 'diamond']],
        'rate' => ['Rate', ['rate', 'unit cost', 'cost', 'price']],
        'amount' => ['Amount', ['amount', 'value', 'total', 'opening value']],
    ];
}

/** Map a sheet's header row onto our column keys. Unknown headers are ignored. */
function opening_import_map_headers(array $headerCells): array
{
    $map = [];
    foreach (opening_import_columns() as $key => [, $aliases]) {
        foreach ($headerCells as $index => $cell) {
            $needle = strtolower(trim(preg_replace('/\s+/', ' ', (string) $cell) ?? ''));
            if ($needle !== '' && in_array($needle, $aliases, true)) {
                $map[$key] = (int) $index;
                break;
            }
        }
    }

    return $map;
}

/** A downloadable template, so nobody has to guess the column names. */
function opening_import_template_rows(bool $jewellery): array
{
    $header = [];
    foreach (opening_import_columns() as $key => [$label]) {
        if (!$jewellery && in_array($key, ['purity', 'gross_weight'], true)) {
            continue;
        }
        $header[] = $label;
    }

    $sample = $jewellery
        ? [['G-01', 'Gold Chain 22K', '22K', 'TOLA', 2, 10.5, 0.3, 0.1, 139000, ''],
           ['G-02', 'Gold Ring 22K', '22K', 'GM', 5, 40.0, 0, 0, 11900, ''],
           ['', 'Leave Amount blank to let Rate x Weight price it, or fill Amount and leave Rate blank.', '', '', '', '', '', '', '', '']]
        : [['ITEM-01', 'Sample Item', 'PCS', 10, 250, ''],
           ['', 'Fill either Rate or Amount. Rows with neither are flagged, not dropped.', '', '', '', '']];

    return array_merge([$header], $sample);
}

/** Number out of a spreadsheet cell: strips thousands separators and stray text. */
function opening_import_number(string $raw): float
{
    $clean = str_replace([',', ' ', "\xc2\xa0"], '', trim($raw));
    if ($clean === '' || !is_numeric($clean)) {
        return 0.0;
    }

    return (float) $clean;
}

/**
 * Parse an uploaded sheet into staged rows.
 *
 * Nothing is written to the books here. Every row comes back with either a
 * resolved item or an error naming what could not be matched.
 */
function opening_import_stage(int $companyId, int $fiscalYearId, string $path, string $extension,
    string $originalName, string $module, int $userId = 0): array
{
    $rows = voucher_import_read_rows($path, $extension);
    if ($rows === []) {
        throw new RuntimeException('That file has no rows in it.');
    }
    if (count($rows) > OPENING_IMPORT_MAX_ROWS) {
        throw new RuntimeException('That sheet has ' . count($rows) . ' rows. Split it into files of '
            . OPENING_IMPORT_MAX_ROWS . ' rows or fewer.');
    }

    // The shared reader hands back ['n' => sheet row number, 'cells' => [...]],
    // so the row number the user sees in the preview is the row number in their
    // own file — not an offset we counted ourselves and could get wrong.
    $headerRow = array_shift($rows);
    $map = opening_import_map_headers((array) ($headerRow['cells'] ?? $headerRow));
    if (!isset($map['code']) && !isset($map['name'])) {
        throw new RuntimeException('The first row must be a header row containing at least "Item code" or "Item name". '
            . 'Download the template to see the column names this expects.');
    }

    $jewellery = $module === 'jewellery';
    if ($jewellery) {
        require_once __DIR__ . '/jewellery_stock.php';
    }

    // Look-ups built once, not once per row.
    $itemsByCode = [];
    $itemsByName = [];
    $itemStmt = db()->prepare('SELECT id, sku, name FROM inventory_items WHERE company_id = :cid');
    $itemStmt->execute(['cid' => $companyId]);
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $itemsByCode[strtolower(trim((string) $item['sku']))] = (int) $item['id'];
        $itemsByName[strtolower(trim((string) $item['name']))] = (int) $item['id'];
    }

    $unitsByCode = [];
    $puritiesByCode = [];
    if ($jewellery) {
        foreach (jewellery_units_list($companyId, false) as $unit) {
            $unitsByCode[strtolower(trim((string) $unit['code']))] = (int) $unit['id'];
            $unitsByCode[strtolower(trim((string) $unit['name']))] = (int) $unit['id'];
        }
        foreach (jewellery_purities_list($companyId, 0, false) as $purity) {
            $puritiesByCode[strtolower(trim((string) $purity['code']))] = (int) $purity['id'];
        }
    }

    db()->prepare('INSERT INTO inventory_opening_imports (company_id, fiscal_year_id, module, original_name, created_by)
        VALUES (:cid, :fy, :mod, :name, :by)')
        ->execute(['cid' => $companyId, 'fy' => $fiscalYearId ?: null, 'mod' => $module,
            'name' => mb_substr($originalName, 0, 255), 'by' => $userId ?: null]);
    $importId = (int) db()->lastInsertId();

    $insert = db()->prepare('INSERT INTO inventory_opening_import_rows (import_id, company_id, source_row_no,
            raw_code, raw_name, raw_unit, raw_purity, item_id, purity_id, unit_id,
            qty_pieces, gross_weight, stone_weight, diamond_weight, rate, amount, status, error_text)
        VALUES (:imp, :cid, :rowno, :rcode, :rname, :runit, :rpurity, :item, :purity, :unit,
            :pieces, :gross, :stone, :diamond, :rate, :amount, :status, :err)');

    $total = 0;
    $valid = 0;
    foreach ($rows as $offset => $row) {
        $cells = (array) ($row['cells'] ?? $row);
        $sheetRowNo = (int) ($row['n'] ?? ((int) $offset + 2));
        $cell = static function (string $key) use ($map, $cells): string {
            return isset($map[$key]) ? trim((string) ($cells[$map[$key]] ?? '')) : '';
        };
        $code = $cell('code');
        $name = $cell('name');
        // A row with nothing in it is a blank line in the sheet, not an error.
        if ($code === '' && $name === '' && opening_import_number($cell('amount')) === 0.0
            && opening_import_number($cell('gross_weight')) === 0.0
            && opening_import_number($cell('stone_weight')) === 0.0
            && opening_import_number($cell('diamond_weight')) === 0.0
            && opening_import_number($cell('qty_pieces')) === 0.0) {
            continue;
        }

        $total++;
        $errors = [];
        $itemId = $itemsByCode[strtolower($code)] ?? ($itemsByName[strtolower($name)] ?? 0);
        if ($itemId <= 0) {
            $errors[] = $code !== ''
                ? 'No item with code "' . $code . '".'
                : 'No item named "' . $name . '".';
        }

        $unitRaw = $cell('unit');
        $purityRaw = $cell('purity');
        $unitId = $unitRaw !== '' ? ($unitsByCode[strtolower($unitRaw)] ?? 0) : 0;
        $purityId = $purityRaw !== '' ? ($puritiesByCode[strtolower($purityRaw)] ?? 0) : 0;
        if ($jewellery && $unitRaw !== '' && $unitId <= 0) {
            $errors[] = 'Unknown weight unit "' . $unitRaw . '".';
        }
        if ($jewellery && $purityRaw !== '' && $purityId <= 0) {
            $errors[] = 'Unknown purity "' . $purityRaw . '".';
        }

        $pieces = opening_import_number($cell('qty_pieces'));
        $gross = opening_import_number($cell('gross_weight'));
        $stone = opening_import_number($cell('stone_weight'));
        $diamond = opening_import_number($cell('diamond_weight'));
        $rate = opening_import_number($cell('rate'));
        $amount = opening_import_number($cell('amount'));
        if ($pieces < 0 || $gross < 0 || $stone < 0 || $diamond < 0 || $rate < 0 || $amount < 0) {
            $errors[] = 'Negative figures are not an opening balance.';
        }
        if (($stone + $diamond) > $gross + 0.00005) {
            $errors[] = 'Stone and diamond weight cannot exceed gross weight.';
        }
        // Rate x quantity when only a rate was given, and the implied rate when
        // only an amount was. Either way both end up on the row, so the preview
        // shows exactly what will be posted.
        $basis = $jewellery ? ($gross > 0 ? $gross : $pieces) : ($pieces > 0 ? $pieces : $gross);
        if ($amount <= 0 && $rate > 0 && $basis > 0) {
            $amount = round($rate * $basis, 2);
        } elseif ($rate <= 0 && $amount > 0 && $basis > 0) {
            $rate = round($amount / $basis, 4);
        }
        if ($amount <= 0) {
            $errors[] = 'No value on this row — fill in either a Rate or an Amount.';
        }
        if ($basis <= 0) {
            $errors[] = $jewellery ? 'No weight and no piece count.' : 'No quantity.';
        }

        $status = $errors === [] ? 'ready' : 'error';
        if ($status === 'ready') {
            $valid++;
        }
        $insert->execute([
            'imp' => $importId, 'cid' => $companyId, 'rowno' => $sheetRowNo,
            'rcode' => mb_substr($code, 0, 120), 'rname' => mb_substr($name, 0, 255),
            'runit' => mb_substr($unitRaw, 0, 60), 'rpurity' => mb_substr($purityRaw, 0, 60),
            'item' => $itemId ?: null, 'purity' => $purityId ?: null, 'unit' => $unitId ?: null,
            'pieces' => $pieces, 'gross' => $gross, 'stone' => $stone, 'diamond' => $diamond,
            'rate' => $rate, 'amount' => $amount,
            'status' => $status, 'err' => $errors === [] ? null : mb_substr(implode(' ', $errors), 0, 500),
        ]);
    }

    db()->prepare('UPDATE inventory_opening_imports SET row_count = :n, valid_count = :v WHERE id = :id')
        ->execute(['n' => $total, 'v' => $valid, 'id' => $importId]);

    return ['import_id' => $importId, 'row_count' => $total, 'valid_count' => $valid];
}

function opening_import_batch(int $companyId, int $importId): ?array
{
    if ($importId <= 0 || !table_exists('inventory_opening_imports')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM inventory_opening_imports WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $importId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The most recent batch still waiting to be looked at. */
function opening_import_latest_staged(int $companyId, string $module = ''): ?array
{
    if (!table_exists('inventory_opening_imports')) {
        return null;
    }
    $sql = "SELECT * FROM inventory_opening_imports WHERE company_id = :cid AND status = 'staged'";
    $params = ['cid' => $companyId];
    if ($module !== '') {
        $sql .= ' AND module = :mod';
        $params['mod'] = $module;
    }
    $sql .= ' ORDER BY id DESC LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Staged rows with the item they resolved to, for the preview table. */
function opening_import_rows(int $companyId, int $importId): array
{
    if ($importId <= 0 || !table_exists('inventory_opening_import_rows')) {
        return [];
    }
    $stmt = db()->prepare('SELECT r.*, i.sku AS item_code, i.name AS item_name,
            p.code AS purity_code, u.code AS unit_code
        FROM inventory_opening_import_rows r
        LEFT JOIN inventory_items i ON i.id = r.item_id
        LEFT JOIN jewellery_purities p ON p.id = r.purity_id
        LEFT JOIN jewellery_units u ON u.id = r.unit_id
        WHERE r.import_id = :imp AND r.company_id = :cid
        ORDER BY r.source_row_no ASC, r.id ASC');
    $stmt->execute(['imp' => $importId, 'cid' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Correct one staged row in place, and re-validate it. */
function opening_import_update_row(int $companyId, int $rowId, array $input): array
{
    $stmt = db()->prepare('SELECT r.*, b.module FROM inventory_opening_import_rows r
        INNER JOIN inventory_opening_imports b ON b.id = r.import_id
        WHERE r.id = :id AND r.company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $rowId, 'cid' => $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'That row is not part of an import in this company.'];
    }
    if ((string) $row['status'] === 'committed') {
        return ['ok' => false, 'error' => 'That row has already been committed.'];
    }

    $itemId = (int) ($input['item_id'] ?? $row['item_id']);
    if ($itemId > 0) {
        $check = db()->prepare('SELECT COUNT(*) FROM inventory_items WHERE id = :id AND company_id = :cid');
        $check->execute(['id' => $itemId, 'cid' => $companyId]);
        if ((int) $check->fetchColumn() === 0) {
            return ['ok' => false, 'error' => 'That item does not belong to this company.'];
        }
    }

    $pieces = round((float) ($input['qty_pieces'] ?? $row['qty_pieces']), 3);
    $gross = round((float) ($input['gross_weight'] ?? $row['gross_weight']), 4);
    $stone = round((float) ($input['stone_weight'] ?? $row['stone_weight']), 4);
    $diamond = round((float) ($input['diamond_weight'] ?? $row['diamond_weight']), 4);
    $rate = round((float) ($input['rate'] ?? $row['rate']), 4);
    $amount = round((float) ($input['amount'] ?? $row['amount']), 2);
    $jewellery = (string) $row['module'] === 'jewellery';
    $basis = $jewellery ? ($gross > 0 ? $gross : $pieces) : ($pieces > 0 ? $pieces : $gross);
    if ($amount <= 0 && $rate > 0 && $basis > 0) {
        $amount = round($rate * $basis, 2);
    } elseif ($rate <= 0 && $amount > 0 && $basis > 0) {
        $rate = round($amount / $basis, 4);
    }

    $errors = [];
    if ($itemId <= 0) {
        $errors[] = 'Choose the item this row is for.';
    }
    if ($pieces < 0 || $gross < 0 || $stone < 0 || $diamond < 0 || $rate < 0 || $amount < 0) {
        $errors[] = 'Negative figures are not an opening balance.';
    }
    if (($stone + $diamond) > $gross + 0.00005) {
        $errors[] = 'Stone and diamond weight cannot exceed gross weight.';
    }
    if ($amount <= 0) {
        $errors[] = 'No value on this row.';
    }
    if ($basis <= 0) {
        $errors[] = $jewellery ? 'No weight and no piece count.' : 'No quantity.';
    }

    db()->prepare('UPDATE inventory_opening_import_rows SET item_id = :item, purity_id = :purity, unit_id = :unit,
            qty_pieces = :pieces, gross_weight = :gross, stone_weight = :stone, diamond_weight = :diamond,
            rate = :rate, amount = :amount,
            status = :status, error_text = :err
        WHERE id = :id AND company_id = :cid')
        ->execute([
            'item' => $itemId ?: null,
            'purity' => (int) ($input['purity_id'] ?? $row['purity_id']) ?: null,
            'unit' => (int) ($input['unit_id'] ?? $row['unit_id']) ?: null,
            'pieces' => $pieces, 'gross' => $gross, 'stone' => $stone, 'diamond' => $diamond,
            'rate' => $rate, 'amount' => $amount,
            'status' => $errors === [] ? 'ready' : 'error',
            'err' => $errors === [] ? null : implode(' ', $errors),
            'id' => $rowId, 'cid' => $companyId,
        ]);
    opening_import_refresh_counts($companyId, (int) $row['import_id']);

    return ['ok' => true, 'error' => '', 'status' => $errors === [] ? 'ready' : 'error'];
}

/** Drop one staged row. Committed rows are never removed. */
function opening_import_delete_row(int $companyId, int $rowId): array
{
    $stmt = db()->prepare('SELECT import_id, status FROM inventory_opening_import_rows
        WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $rowId, 'cid' => $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'That row is not part of an import in this company.'];
    }
    if ((string) $row['status'] === 'committed') {
        return ['ok' => false, 'error' => 'That row is already in the books. Clear the opening on the item instead.'];
    }
    db()->prepare('DELETE FROM inventory_opening_import_rows WHERE id = :id AND company_id = :cid')
        ->execute(['id' => $rowId, 'cid' => $companyId]);
    opening_import_refresh_counts($companyId, (int) $row['import_id']);

    return ['ok' => true, 'error' => ''];
}

function opening_import_refresh_counts(int $companyId, int $importId): void
{
    $stmt = db()->prepare("SELECT COUNT(*) AS total, SUM(status = 'ready') AS ready
        FROM inventory_opening_import_rows WHERE import_id = :imp AND company_id = :cid");
    $stmt->execute(['imp' => $importId, 'cid' => $companyId]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'ready' => 0];
    db()->prepare('UPDATE inventory_opening_imports SET row_count = :n, valid_count = :v WHERE id = :id AND company_id = :cid')
        ->execute(['n' => (int) $counts['total'], 'v' => (int) $counts['ready'], 'id' => $importId, 'cid' => $companyId]);
}

/** Throw the whole staged batch away. Nothing reached the books, so nothing is reversed. */
function opening_import_discard(int $companyId, int $importId): array
{
    $batch = opening_import_batch($companyId, $importId);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Import not found for this company.'];
    }
    if ((string) $batch['status'] === 'committed') {
        return ['ok' => false, 'error' => 'That import has already been committed.'];
    }
    db()->prepare('DELETE FROM inventory_opening_imports WHERE id = :id AND company_id = :cid')
        ->execute(['id' => $importId, 'cid' => $companyId]);

    return ['ok' => true, 'error' => ''];
}

/**
 * Turn the READY rows into opening stock. Rows with errors are left behind,
 * still staged, so the batch can be corrected and committed again.
 *
 * Every row goes through the SAME opening routine the item screen uses, so an
 * opening created here and one typed by hand are the same thing in the books.
 */
function opening_import_commit(int $companyId, int $importId, int $fiscalYearId, int $userId = 0): array
{
    $batch = opening_import_batch($companyId, $importId);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Import not found for this company.'];
    }
    if ((string) $batch['status'] !== 'staged') {
        return ['ok' => false, 'error' => 'That import is already ' . $batch['status'] . '.'];
    }

    $jewellery = (string) $batch['module'] === 'jewellery';
    if ($jewellery) {
        require_once __DIR__ . '/jewellery_stock.php';
    } else {
        require_once __DIR__ . '/inventory_valuation.php';
    }

    $rows = opening_import_rows($companyId, $importId);
    $committed = 0;
    $failures = [];

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        foreach ($rows as $row) {
            if ((string) $row['status'] !== 'ready') {
                continue;
            }
            try {
                if ($jewellery) {
                    jewellery_save_opening($companyId, $fiscalYearId, [
                        'item_id' => (int) $row['item_id'],
                        'purity_id' => (int) ($row['purity_id'] ?? 0),
                        'unit_id' => (int) ($row['unit_id'] ?? 0),
                        'qty_pieces' => (float) $row['qty_pieces'],
                        'gross_weight' => (float) $row['gross_weight'],
                        'stone_weight' => (float) $row['stone_weight'],
                        'diamond_weight' => (float) $row['diamond_weight'],
                        'amount' => (float) $row['amount'],
                        'notes' => 'Imported from ' . (string) $batch['original_name'],
                    ], $userId);
                } else {
                    $qty = (float) $row['qty_pieces'] > 0 ? (float) $row['qty_pieces'] : (float) $row['gross_weight'];
                    db()->prepare('UPDATE inventory_items SET opening_qty = :q, opening_amount = :a
                        WHERE id = :id AND company_id = :cid')
                        ->execute(['q' => $qty, 'a' => (float) $row['amount'],
                            'id' => (int) $row['item_id'], 'cid' => $companyId]);
                    $itemStmt = db()->prepare('SELECT * FROM inventory_items WHERE id = :id AND company_id = :cid LIMIT 1');
                    $itemStmt->execute(['id' => (int) $row['item_id'], 'cid' => $companyId]);
                    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
                    if ($item) {
                        inv_post_item_opening_voucher($companyId, $item, $userId);
                    }
                }
                db()->prepare("UPDATE inventory_opening_import_rows SET status = 'committed', error_text = NULL
                    WHERE id = :id AND company_id = :cid")
                    ->execute(['id' => (int) $row['id'], 'cid' => $companyId]);
                $committed++;
            } catch (Throwable $rowException) {
                // One bad row must not lose the other forty-six. It is marked
                // and left staged so it can be fixed and committed after.
                db()->prepare("UPDATE inventory_opening_import_rows SET status = 'error', error_text = :err
                    WHERE id = :id AND company_id = :cid")
                    ->execute(['err' => mb_substr($rowException->getMessage(), 0, 500),
                        'id' => (int) $row['id'], 'cid' => $companyId]);
                $failures[] = 'Row ' . (int) $row['source_row_no'] . ': ' . $rowException->getMessage();
            }
        }

        $stillStaged = db()->prepare("SELECT COUNT(*) FROM inventory_opening_import_rows
            WHERE import_id = :imp AND company_id = :cid AND status <> 'committed'");
        $stillStaged->execute(['imp' => $importId, 'cid' => $companyId]);
        $remaining = (int) $stillStaged->fetchColumn();

        db()->prepare('UPDATE inventory_opening_imports SET status = :st, committed_rows = committed_rows + :n,
                committed_at = NOW() WHERE id = :id AND company_id = :cid')
            ->execute(['st' => $remaining > 0 ? 'staged' : 'committed', 'n' => $committed,
                'id' => $importId, 'cid' => $companyId]);
        opening_import_refresh_counts($companyId, $importId);

        if ($ownsTransaction) {
            db()->commit();
        }
    } catch (Throwable $commitException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $commitException->getMessage(), 'committed' => 0, 'failures' => $failures];
    }

    return ['ok' => true, 'error' => '', 'committed' => $committed, 'failures' => $failures];
}
