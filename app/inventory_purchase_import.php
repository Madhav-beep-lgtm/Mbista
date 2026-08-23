<?php
declare(strict_types=1);

/**
 * The purchase entry form, as a spreadsheet.
 *
 * The form on screen is one row per supplier's bill with its items behind a
 * popup. A sheet has no popups, so the same thing is written flat: ONE ROW PER
 * ITEM, with the bill's own details repeated down its lines. Rows carrying the
 * same bill reference and posting date are one bill, exactly as if they had
 * been typed into one row and its popup.
 *
 * Everything the form asks for, the sheet asks for, in the same order and under
 * the same names. That is deliberate — somebody who has used the form should be
 * able to read the template without being taught it, and a column that exists
 * in one and not the other is a question nobody can answer.
 *
 * Nothing new posts it. The rows are handed to inv_purchase_batch_validate()
 * and inv_purchase_batch_post(), the same pair the form uses, so an imported
 * bill and a typed one become the same movements and the same entries.
 */

require_once __DIR__ . '/inventory_purchase_batch.php';
require_once __DIR__ . '/export_engine.php';
require_once __DIR__ . '/voucher_import.php';

/** Rows a sheet may carry before it is refused as too big. */
const INV_PURCHASE_IMPORT_MAX_ROWS = 3000;

/**
 * The columns, in the order the form shows them.
 *
 * 'aliases' are what a heading may be called; the first is what the template
 * writes. 'need' marks the ones a row cannot do without.
 */
function inv_purchase_import_columns(): array
{
    return [
        'transaction_date' => ['label' => 'Posting Date', 'need' => true,
            'aliases' => ['postingdate', 'date', 'voucherdate', 'entrydate']],
        'supplier_invoice_date' => ['label' => 'Supplier Invoice Date', 'need' => false,
            'aliases' => ['supplierinvoicedate', 'supplierinvdate', 'billdate', 'invoicedate']],
        'movement' => ['label' => 'Movement', 'need' => false,
            'aliases' => ['movement', 'movementtype', 'transactiontype', 'type']],
        'ref_no' => ['label' => 'Bill Reference', 'need' => false,
            'aliases' => ['billreference', 'billno', 'billnumber', 'reference', 'refno', 'invoiceno']],
        'supplier' => ['label' => 'Supplier', 'need' => false,
            'aliases' => ['supplier', 'suppliername', 'party', 'partyname', 'vendor']],
        'item' => ['label' => 'Item', 'need' => true,
            'aliases' => ['item', 'itemcode', 'sku', 'itemname', 'product']],
        'uom' => ['label' => 'UoM', 'need' => false,
            'aliases' => ['uom', 'unit', 'unitofmeasure', 'measure']],
        'quantity' => ['label' => 'Quantity', 'need' => true,
            'aliases' => ['quantity', 'qty', 'units', 'nos']],
        'rate' => ['label' => 'Rate excl. VAT after discount', 'need' => true,
            'aliases' => ['rateexclvatafterdiscount', 'rateexclvat', 'rate', 'unitrate', 'price', 'unitprice']],
        'amount' => ['label' => 'Amount', 'need' => false,
            'aliases' => ['amount', 'lineamount', 'value', 'total']],
        'vat_applicable' => ['label' => 'VAT Applicable', 'need' => false,
            'aliases' => ['vatapplicable', 'vatapplicability', 'vatable', 'vatyn']],
        'vat_amount' => ['label' => 'VAT', 'need' => false,
            'aliases' => ['vat', 'vatamount', 'vatonpurchase', 'tax']],
        'tds_applicable' => ['label' => 'TDS Applicable', 'need' => false,
            'aliases' => ['tdsapplicable', 'tdsapplicability', 'tdsyn', 'withholding']],
        'tds_rate' => ['label' => 'TDS %', 'need' => false,
            'aliases' => ['tds', 'tdsrate', 'tdspercent', 'withholdingrate']],
        'vat_ledger' => ['label' => 'VAT Ledger', 'need' => false,
            'aliases' => ['vatledger', 'vatledgerdr', 'vatpurchaseledger']],
        'tds_ledger' => ['label' => 'TDS Ledger', 'need' => false,
            'aliases' => ['tdsledger', 'tdsledgercr', 'tdsdeductedledger']],
        'mark_ingredient' => ['label' => 'Ingredient', 'need' => false,
            'aliases' => ['ingredient', 'markasingredient', 'recipeingredient', 'kitchen']],
        'notes' => ['label' => 'Notes', 'need' => false,
            'aliases' => ['notes', 'note', 'remarks', 'narration']],
    ];
}

/** The template, with a worked bill showing an exempt line beside a taxed one. */
function inv_purchase_import_template_rows(): array
{
    $header = [];
    foreach (inv_purchase_import_columns() as $column) {
        $header[] = $column['label'];
    }

    // One bill, three items. Milk is exempt, flour is standard rated, and the
    // sugar line has tax withheld — the whole point of per-item treatment, in
    // three rows somebody can copy.
    return [
        $header,
        ['2026-08-20', '2026-08-17', 'Purchase', 'ABC-9001', 'ABC Pvt. Ltd.', 'MILK', 'Litre', 100, 20, 2000, 'No', 0, 'No', '', '', '', 'Yes', 'Exempt supply'],
        ['2026-08-20', '2026-08-17', 'Purchase', 'ABC-9001', 'ABC Pvt. Ltd.', 'FLOUR', 'KG', 50, 60, 3000, 'Yes', 390, 'No', '', '1500', '', 'Yes', ''],
        ['2026-08-20', '2026-08-17', 'Purchase', 'ABC-9001', 'ABC Pvt. Ltd.', 'SUGAR', 'KG', 10, 100, 1000, 'Yes', 130, 'Yes', 1.5, '1500', '2200', 'No', 'Withheld at 1.5%'],
        ['2026-08-21', '2026-08-21', 'Opening stock', 'OPEN-01', '', 'RICE', 'KG', 25, 90, 2250, 'No', 0, 'No', '', '', '', 'Yes', 'Opening position'],
    ];
}

/** The template as a workbook. */
function inv_purchase_import_template_xlsx(): string
{
    return xlsx_build(
        inv_purchase_import_template_rows(),
        'Purchases',
        [16, 18, 16, 16, 22, 16, 10, 12, 22, 14, 14, 12, 14, 10, 14, 14, 12, 24],
        ['styled_table' => true, 'freeze_header' => true]
    );
}

/** The template as CSV, for anyone not working in Excel. */
function inv_purchase_import_template_csv(): string
{
    $handle = fopen('php://temp', 'r+b');
    foreach (inv_purchase_import_template_rows() as $row) {
        fputcsv($handle, $row, ',', '"', '\\');
    }
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);

    return $csv;
}

/**
 * Match a header row to the columns above.
 *
 * Longest alias first, so "rateexclvatafterdiscount" is not claimed by the
 * shorter "rate", and a column already matched is never taken twice.
 */
function inv_purchase_import_headers(array $cells): array
{
    $flat = [];
    foreach (inv_purchase_import_columns() as $field => $column) {
        foreach ($column['aliases'] as $alias) {
            $flat[] = ['field' => $field, 'alias' => $alias, 'len' => strlen($alias)];
        }
    }
    usort($flat, static fn (array $a, array $b): int => $b['len'] <=> $a['len']);

    $map = [];
    $used = [];
    foreach ($flat as $candidate) {
        if (isset($map[$candidate['field']])) {
            continue;
        }
        foreach ($cells as $index => $cell) {
            if (isset($used[$index])) {
                continue;
            }
            $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $cell));
            if ($key !== '' && $key === $candidate['alias']) {
                $map[$candidate['field']] = $index;
                $used[$index] = true;
                break;
            }
        }
    }

    return $map;
}

/** Yes / No / 1 / 0 / true / tick, as a decision. */
function inv_purchase_import_flag(string $raw, bool $default): bool
{
    $key = strtolower(trim($raw));
    if ($key === '') {
        return $default;
    }
    if (in_array($key, ['y', 'yes', '1', 'true', 'applicable', 'taxable', 'standard', 'tick', 'x', '✓'], true)) {
        return true;
    }
    if (in_array($key, ['n', 'no', '0', 'false', 'exempt', 'exempted', 'na', 'n/a', '-', 'not applicable'], true)) {
        return false;
    }

    return $default;
}

/**
 * Everything the sheet can name, read once.
 *
 * A month's purchases run to hundreds of lines and they name the same dozen
 * items and the same handful of suppliers over and over. Looking each one up as
 * it appears would be a query per line.
 */
function inv_purchase_import_lookups(int $companyId): array
{
    $norm = static fn (string $v): string => strtolower(trim((string) preg_replace('/\s+/', ' ', $v)));

    $items = [];
    $stmt = db()->prepare("SELECT id, sku, name, unit FROM inventory_items WHERE company_id = :cid AND status = 'active'");
    $stmt->execute(['cid' => $companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach ([(string) $row['sku'], (string) $row['name']] as $key) {
            $key = $norm($key);
            if ($key !== '' && !isset($items[$key])) {
                $items[$key] = $row;
            }
        }
    }

    $parties = [];
    if (table_exists('accounting_parties')) {
        $stmt = db()->prepare("SELECT id, name, code FROM accounting_parties WHERE company_id = :cid AND status = 'active'");
        $stmt->execute(['cid' => $companyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach ([(string) $row['name'], (string) ($row['code'] ?? '')] as $key) {
                $key = $norm($key);
                if ($key !== '' && !isset($parties[$key])) {
                    $parties[$key] = $row;
                }
            }
        }
    }

    $ledgers = [];
    $stmt = db()->prepare("SELECT id, code, name FROM ledgers WHERE company_id = :cid AND status = 'active'");
    $stmt->execute(['cid' => $companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach ([(string) $row['code'], (string) $row['name']] as $key) {
            $key = $norm($key);
            if ($key !== '' && !isset($ledgers[$key])) {
                $ledgers[$key] = $row;
            }
        }
    }

    return ['items' => $items, 'parties' => $parties, 'ledgers' => $ledgers, 'norm' => $norm];
}

/** A movement named in words, back to the key the engine uses. */
function inv_purchase_import_movement(string $raw): ?string
{
    $key = strtolower((string) preg_replace('/[^a-z]/i', '', $raw));
    if ($key === '') {
        return 'purchase';
    }
    foreach (inv_purchase_batch_types() as $type => $label) {
        if ($key === strtolower((string) preg_replace('/[^a-z]/i', '', $label))
            || $key === strtolower((string) preg_replace('/[^a-z]/i', '', $type))) {
            return $type;
        }
    }

    return null;
}

/**
 * Read a purchase sheet into the rows the engine posts.
 *
 * Returns ['error' => string] on a fatal problem with the sheet itself,
 * otherwise ['rows' => ..., 'bills' => ..., 'problems' => ...] where 'rows' is
 * ready for inv_purchase_batch_validate().
 */
function inv_purchase_import_parse(string $path, string $extension, int $companyId): array
{
    $sheetRows = strtolower($extension) === 'csv'
        ? voucher_import_read_csv($path)
        : spreadsheet_read_xlsx($path, INV_PURCHASE_IMPORT_MAX_ROWS);

    $columns = inv_purchase_import_columns();
    $map = [];
    $headerIndex = null;
    foreach (array_slice($sheetRows, 0, 10, true) as $index => $row) {
        $candidate = inv_purchase_import_headers($row['cells']);
        $missing = false;
        foreach ($columns as $field => $column) {
            if ($column['need'] && !isset($candidate[$field])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            $map = $candidate;
            $headerIndex = $index;
            break;
        }
    }
    if ($headerIndex === null) {
        $needed = [];
        foreach ($columns as $column) {
            if ($column['need']) {
                $needed[] = $column['label'];
            }
        }

        return ['error' => 'No header row found. The sheet needs at least ' . implode(', ', $needed)
            . ' — download the template to see the layout the purchase form expects.'];
    }

    $lookups = inv_purchase_import_lookups($companyId);
    $norm = $lookups['norm'];
    $rows = [];
    $problems = [];
    $bills = [];
    $lineNo = 0;

    foreach (array_slice($sheetRows, $headerIndex + 1) as $sheetRow) {
        if (trim(implode('', $sheetRow['cells'])) === '') {
            continue;
        }
        if (count($rows) >= INV_PURCHASE_IMPORT_MAX_ROWS) {
            return ['error' => 'The sheet has more than ' . number_format(INV_PURCHASE_IMPORT_MAX_ROWS) . ' rows — split it into smaller files.'];
        }
        $cells = $sheetRow['cells'];
        $cell = static fn (string $field): string => trim((string) ($cells[$map[$field] ?? -1] ?? ''));
        $lineNo++;

        // A totals line at the foot is a summary, not a purchase.
        $first = strtolower((string) preg_replace('/[^a-z]/i', '', (string) ($cells[0] ?? '')));
        if (in_array($first, ['total', 'grandtotal', 'subtotal'], true)) {
            continue;
        }

        $where = 'Row ' . (int) $sheetRow['n'];

        $date = voucher_import_date($cell('transaction_date'));
        if ($date === null) {
            $problems[] = $where . ': the posting date "' . $cell('transaction_date') . '" is not a date. Use YYYY-MM-DD (years 2064+ are read as Bikram Sambat).';
            continue;
        }
        $supplierDate = $cell('supplier_invoice_date') !== '' ? voucher_import_date($cell('supplier_invoice_date')) : null;

        $movement = inv_purchase_import_movement($cell('movement'));
        if ($movement === null) {
            $problems[] = $where . ': "' . $cell('movement') . '" is not a movement. Use ' . implode(', ', inv_purchase_batch_types()) . '.';
            continue;
        }

        $itemRaw = $cell('item');
        $item = $lookups['items'][$norm($itemRaw)] ?? null;
        if ($item === null) {
            $problems[] = $where . ': no active item has the code or name "' . $itemRaw . '".';
            continue;
        }
        // The unit is the item's own; a sheet disagreeing about it is usually a
        // row against the wrong item, so it is said rather than ignored.
        $uom = $cell('uom');
        if ($uom !== '' && $norm($uom) !== $norm((string) $item['unit'])) {
            $problems[] = $where . ': the sheet says ' . $itemRaw . ' is measured in "' . $uom
                . '" but the item master says "' . (string) $item['unit'] . '". Check it is the right item.';
        }

        $partyId = 0;
        $supplierRaw = $cell('supplier');
        if ($supplierRaw !== '') {
            $party = $lookups['parties'][$norm($supplierRaw)] ?? null;
            if ($party === null) {
                $problems[] = $where . ': no active supplier is called "' . $supplierRaw . '".';
                continue;
            }
            $partyId = (int) $party['id'];
        }

        $ledgerId = static function (string $raw) use ($lookups, $norm): int {
            $found = $raw !== '' ? ($lookups['ledgers'][$norm($raw)] ?? null) : null;
            return $found !== null ? (int) $found['id'] : 0;
        };
        $vatLedgerRaw = $cell('vat_ledger');
        $vatLedgerId = $ledgerId($vatLedgerRaw);
        if ($vatLedgerRaw !== '' && $vatLedgerId === 0) {
            $problems[] = $where . ': no active ledger has the code or name "' . $vatLedgerRaw . '" for VAT.';
            continue;
        }
        $tdsLedgerRaw = $cell('tds_ledger');
        $tdsLedgerId = $ledgerId($tdsLedgerRaw);
        if ($tdsLedgerRaw !== '' && $tdsLedgerId === 0) {
            $problems[] = $where . ': no active ledger has the code or name "' . $tdsLedgerRaw . '" for withholding.';
            continue;
        }

        $quantity = voucher_import_amount($cell('quantity'));
        $rate = voucher_import_amount($cell('rate'));
        // VAT defaults to applicable, as it does on the form: most lines carry
        // it and the exempt ones are the exception worth saying.
        $vatOn = inv_purchase_import_flag($cell('vat_applicable'), true);
        $tdsOn = inv_purchase_import_flag($cell('tds_applicable'), $cell('tds_rate') !== '' && voucher_import_amount($cell('tds_rate')) > 0);

        // An Amount column is a check, not an input: the engine works it out
        // from quantity and rate, and a sheet that disagrees is saying one of
        // the three is wrong.
        $amountRaw = $cell('amount');
        if ($amountRaw !== '') {
            $stated = voucher_import_amount($amountRaw);
            $computed = round($quantity * $rate, 2);
            if (abs($stated - $computed) > 0.99) {
                $problems[] = $where . ': Amount ' . number_format($stated, 2) . ' is not Quantity x Rate ('
                    . rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.') . ' x ' . number_format($rate, 2)
                    . ' = ' . number_format($computed, 2) . ').';
            }
        }

        $billKey = $norm($cell('ref_no')) . '|' . $date . '|' . $movement . '|' . $partyId;
        $bills[$billKey] = ($bills[$billKey] ?? 0) + 1;

        $rows[] = [
            'transaction_date' => $date,
            'supplier_invoice_date' => $supplierDate ?? '',
            'movement' => $movement,
            'ref_no' => $cell('ref_no'),
            'supplier_party_id' => $partyId,
            'item_id' => (int) $item['id'],
            'quantity' => $quantity,
            'rate' => $rate,
            'vat_applicable' => $vatOn ? '1' : '0',
            'vat_rate' => '',
            'vat_amount' => $cell('vat_amount'),
            'vat_ledger_id' => $vatLedgerId,
            'tds_applicable' => $tdsOn ? '1' : '0',
            'tds_rate' => $cell('tds_rate'),
            'tds_base' => '',
            'tds_ledger_id' => $tdsLedgerId,
            'mark_ingredient' => inv_purchase_import_flag($cell('mark_ingredient'), false) ? '1' : '',
            'notes' => $cell('notes'),
            'sheet_row' => (int) $sheetRow['n'],
        ];
    }

    if ($rows === [] && $problems === []) {
        return ['error' => 'The sheet has no data rows below the header.'];
    }

    return ['rows' => $rows, 'problems' => $problems, 'bill_count' => count($bills)];
}
