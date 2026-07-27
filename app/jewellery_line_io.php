<?php
declare(strict_types=1);

/**
 * Getting lines INTO the grid without typing them: saved templates, and a
 * spreadsheet.
 *
 * Both end at the same place — an array shaped like the grid's own rows, handed
 * back to the page, rendered, and then read by jw_posted_lines() exactly as if
 * somebody had typed it. Nothing here posts anything or touches the ledger. A
 * template and an import are ways of FILLING A FORM, and the form is still
 * saved, still validated and still priced by the same engine afterwards.
 *
 * That is the whole design: no second path into the books.
 */

require_once __DIR__ . '/voucher_import.php';

/**
 * A number out of a spreadsheet cell: thousands separators and stray spaces
 * removed, anything that is not a number treated as zero.
 *
 * Deliberately its own copy rather than a require of opening_stock_import.php,
 * which would pull that module's whole staging pipeline in for four lines. It
 * matches that function's behaviour, including the non-breaking space a copy
 * from Excel leaves behind.
 */
function jw_import_number(string $raw): float
{
    $clean = str_replace([',', ' ', "\xc2\xa0"], '', trim($raw));
    if ($clean === '' || !is_numeric($clean)) {
        return 0.0;
    }

    return (float) $clean;
}

/** The columns a template or an import can carry, and how each is cleaned. */
function jw_line_io_fields(): array
{
    return [
        'item_id' => 'int', 'purity_id' => 'int', 'unit_id' => 'int',
        'qty_pieces' => 'float', 'gross_weight' => 'float', 'stone_weight' => 'float',
        'wastage_pct' => 'float', 'wastage_weight' => 'float', 'rate' => 'float',
        'making_amount' => 'float', 'stone_amount' => 'float', 'stone_carat' => 'float',
        'diamond_amount' => 'float', 'diamond_carat' => 'float',
        'other_diamond_amount' => 'float', 'other_diamond_carat' => 'float',
        'notes' => 'string',
    ];
}

/** Keep only the known columns, in the right types. */
function jw_line_io_clean(array $row): array
{
    $out = [];
    foreach (jw_line_io_fields() as $field => $type) {
        $value = $row[$field] ?? null;
        $out[$field] = match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            default => trim((string) $value),
        };
    }

    return $out;
}

function jw_line_templates_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS `jewellery_line_templates` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `company_id` INT UNSIGNED NOT NULL,
        `doc_type` ENUM('sale','purchase') NOT NULL DEFAULT 'sale',
        `name` VARCHAR(120) NOT NULL,
        `lines_json` MEDIUMTEXT NOT NULL,
        `line_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_by` INT UNSIGNED DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_jw_template` (`company_id`, `doc_type`, `name`),
        KEY `idx_jw_template_company` (`company_id`, `doc_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function jw_templates_list(int $companyId, string $docType): array
{
    jw_line_templates_ensure_schema();
    $stmt = db()->prepare('SELECT id, name, line_count FROM jewellery_line_templates
        WHERE company_id = :cid AND doc_type = :dt ORDER BY name ASC');
    $stmt->execute(['cid' => $companyId, 'dt' => $docType === 'purchase' ? 'purchase' : 'sale']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Save the lines currently on the form under a name.
 *
 * Rows with no item are dropped — they are the grid's blank spares, and a
 * template full of empty rows is worse than no template.
 */
function jw_template_save(int $companyId, string $docType, string $name, array $lines, int $userId = 0): array
{
    jw_line_templates_ensure_schema();
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'error' => 'Give the template a name.'];
    }

    $clean = [];
    foreach ($lines as $line) {
        if ((int) ($line['item_id'] ?? 0) > 0) {
            $clean[] = jw_line_io_clean($line);
        }
    }
    if ($clean === []) {
        return ['ok' => false, 'error' => 'There are no items on the form to save.'];
    }

    db()->prepare('INSERT INTO jewellery_line_templates (company_id, doc_type, name, lines_json, line_count, created_by)
            VALUES (:cid, :dt, :name, :json, :n, :by)
        ON DUPLICATE KEY UPDATE lines_json = VALUES(lines_json), line_count = VALUES(line_count)')
        ->execute([
            'cid' => $companyId, 'dt' => $docType === 'purchase' ? 'purchase' : 'sale',
            'name' => $name, 'json' => json_encode($clean), 'n' => count($clean),
            'by' => $userId ?: null,
        ]);

    return ['ok' => true, 'error' => '', 'count' => count($clean)];
}

/** The lines a template holds, ready to render into the grid. */
function jw_template_lines(int $companyId, int $templateId): array
{
    jw_line_templates_ensure_schema();
    $stmt = db()->prepare('SELECT lines_json FROM jewellery_line_templates
        WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $templateId, 'cid' => $companyId]);
    $json = $stmt->fetchColumn();
    if ($json === false) {
        return [];
    }
    $rows = json_decode((string) $json, true);

    return is_array($rows) ? array_map('jw_line_io_clean', $rows) : [];
}

function jw_template_delete(int $companyId, int $templateId): bool
{
    jw_line_templates_ensure_schema();
    $stmt = db()->prepare('DELETE FROM jewellery_line_templates WHERE id = :id AND company_id = :cid');
    $stmt->execute(['id' => $templateId, 'cid' => $companyId]);

    return $stmt->rowCount() > 0;
}

// ---------------------------------------------------------------------------
// Spreadsheet import
// ---------------------------------------------------------------------------

/**
 * The spreadsheet headings this understands, and the field each fills.
 *
 * Matching is on a squashed lower-case form, so "Gross Wt", "gross_weight" and
 * "GROSS WEIGHT" are all the same heading. A shop's own spreadsheet should not
 * have to be reformatted to be readable.
 */
function jw_import_header_map(): array
{
    return [
        'item' => 'item', 'itemcode' => 'item', 'code' => 'item', 'sku' => 'item', 'itemname' => 'item',
        'purity' => 'purity', 'purity code' => 'purity',
        'unit' => 'unit',
        'pcs' => 'qty_pieces', 'pieces' => 'qty_pieces', 'qty' => 'qty_pieces', 'quantity' => 'qty_pieces',
        'gross' => 'gross_weight', 'grosswt' => 'gross_weight', 'grossweight' => 'gross_weight',
        'less' => 'stone_weight', 'stonewt' => 'stone_weight', 'stoneweight' => 'stone_weight',
        'wastagepct' => 'wastage_pct', 'wastage' => 'wastage_pct', 'wast' => 'wastage_pct',
        'wastagewt' => 'wastage_weight', 'wastageweight' => 'wastage_weight', 'wastwt' => 'wastage_weight',
        'rate' => 'rate', 'rategm' => 'rate', 'rateperunit' => 'rate',
        'making' => 'making_amount', 'makingamount' => 'making_amount', 'makingcharge' => 'making_amount',
        'stone' => 'stone_amount', 'stoneamount' => 'stone_amount', 'stoneamt' => 'stone_amount',
        'stonecarat' => 'stone_carat', 'stonecrt' => 'stone_carat',
        'diamond' => 'diamond_amount', 'diamondamount' => 'diamond_amount', 'diamondamt' => 'diamond_amount',
        'diamondcarat' => 'diamond_carat', 'diamondcrt' => 'diamond_carat',
        'otherdiamond' => 'other_diamond_amount', 'otherdiamondamt' => 'other_diamond_amount',
        'otherdiamondcarat' => 'other_diamond_carat', 'otherdiamondcrt' => 'other_diamond_carat',
        'notes' => 'notes', 'remarks' => 'notes',
    ];
}

/** "Gross Wt (gm)" -> "grosswt", so a heading matches however it was typed. */
function jw_import_squash(string $heading): string
{
    $heading = preg_replace('~\(.*?\)~', '', $heading) ?? $heading;

    return strtolower(preg_replace('~[^a-z0-9]~i', '', $heading) ?? '');
}

/**
 * Read a CSV or XLSX of lines into grid rows.
 *
 * Names are resolved to ids against THIS company's masters, so a spreadsheet
 * cannot smuggle in another tenant's item by number. A row that names something
 * unknown is reported by row number and left out, rather than silently becoming
 * a blank line the shop would have to notice for itself.
 *
 * @return array{ok:bool,rows:array,errors:array,matched:int}
 */
function jw_import_lines(int $companyId, string $path, string $extension): array
{
    $raw = voucher_import_read_rows($path, $extension);
    if ($raw === []) {
        return ['ok' => false, 'rows' => [], 'errors' => ['That file has no rows in it.'], 'matched' => 0];
    }

    // voucher_import_read_rows() hands back ['n' => sheet line, 'cells' => [...]]
    // rather than flat rows. Taking the line number from IT rather than counting
    // here means an error names the row the user is actually looking at, blank
    // lines and all.
    $headerRow = array_shift($raw);
    $headerCells = (array) ($headerRow['cells'] ?? $headerRow);
    $map = jw_import_header_map();
    $columns = [];
    foreach ($headerCells as $index => $heading) {
        $key = jw_import_squash((string) $heading);
        if ($key !== '' && isset($map[$key])) {
            $columns[$map[$key]] = $index;
        }
    }
    if (!isset($columns['item'])) {
        return ['ok' => false, 'rows' => [], 'matched' => 0,
            'errors' => ['No "Item" column found. The first row must be the headings.']];
    }

    // This company's masters, looked up by the codes a person would type.
    $items = [];
    foreach (jewellery_items_list($companyId) as $item) {
        $items[strtolower((string) $item['code'])] = $item;
        $items[strtolower((string) $item['name'])] = $item;
    }
    $purities = [];
    foreach (jewellery_purities_list($companyId, 0, false) as $purity) {
        $purities[strtolower((string) $purity['code'])] = $purity;
        $purities[strtolower($purity['metal_code'] . '-' . $purity['code'])] = $purity;
        $purities[strtolower($purity['metal_code'] . '·' . $purity['code'])] = $purity;
    }
    $units = [];
    foreach (jewellery_units_list($companyId, false) as $unit) {
        $units[strtolower((string) $unit['code'])] = $unit;
    }

    $cell = static function (array $row, ?int $index): string {
        return $index === null ? '' : trim((string) ($row[$index] ?? ''));
    };

    $rows = [];
    $errors = [];
    foreach ($raw as $offset => $rawRow) {
        $line = (array) ($rawRow['cells'] ?? $rawRow);
        $lineNo = (int) ($rawRow['n'] ?? ($offset + 2));
        $itemKey = strtolower($cell($line, $columns['item'] ?? null));
        if ($itemKey === '') {
            continue;                               // a blank spacer row
        }
        if (!isset($items[$itemKey])) {
            $errors[] = 'Row ' . $lineNo . ': no item called "' . $cell($line, $columns['item']) . '".';
            continue;
        }
        $item = $items[$itemKey];

        $purityKey = strtolower($cell($line, $columns['purity'] ?? null));
        $purity = $purityKey !== '' ? ($purities[$purityKey] ?? null) : null;
        if ($purityKey !== '' && $purity === null) {
            $errors[] = 'Row ' . $lineNo . ': no purity called "' . $cell($line, $columns['purity']) . '".';
            continue;
        }
        $unitKey = strtolower($cell($line, $columns['unit'] ?? null));
        $unit = $unitKey !== '' ? ($units[$unitKey] ?? null) : null;
        if ($unitKey !== '' && $unit === null) {
            $errors[] = 'Row ' . $lineNo . ': no weight unit called "' . $cell($line, $columns['unit']) . '".';
            continue;
        }

        $row = [
            'item_id' => (int) $item['id'],
            // Fall back to the item's own purity and unit, which is what the
            // grid would have defaulted to anyway.
            'purity_id' => (int) ($purity['id'] ?? $item['purity_id']),
            'unit_id' => (int) ($unit['id'] ?? $item['unit_id']),
        ];
        foreach (jw_line_io_fields() as $field => $type) {
            if (isset($row[$field]) || !isset($columns[$field])) {
                continue;
            }
            $value = $cell($line, $columns[$field]);
            $row[$field] = $type === 'string' ? $value : jw_import_number($value);
        }
        $rows[] = jw_line_io_clean($row);
    }

    if ($rows === [] && $errors === []) {
        $errors[] = 'Nothing in that file matched an item.';
    }

    return ['ok' => $rows !== [], 'rows' => $rows, 'errors' => $errors, 'matched' => count($rows)];
}
