<?php
declare(strict_types=1);

/**
 * Bulk voucher import: parse an uploaded Excel (.xlsx) or CSV sheet into
 * voucher groups, validate them with the same rules as the voucher form,
 * and build downloadable templates. No external libraries — .xlsx files are
 * read with ZipArchive + SimpleXML and written as a minimal SpreadsheetML
 * package with inline strings.
 */

const VOUCHER_IMPORT_TYPES = [
    'journal' => 'Journal Voucher', 'payment' => 'Payment Voucher', 'receipt' => 'Receipt Voucher',
    'sales' => 'Sales Voucher', 'purchase' => 'Purchase Voucher', 'contra' => 'Contra Voucher',
    'debit_note' => 'Debit Note', 'credit_note' => 'Credit Note',
];

const VOUCHER_IMPORT_MAX_ROWS = 5000;

/**
 * Which side of each voucher type must use a ledger under a Bank/Cash group
 * (ledger_groups.is_cash_or_bank = 1). Mirrors the voucher form contract.
 */
function voucher_import_cash_bank_rule(string $type): array
{
    return match ($type) {
        'contra' => ['debit' => true, 'credit' => true, 'label' => 'Every line (both debit and credit) must use a Bank/Cash group ledger.'],
        'payment' => ['debit' => false, 'credit' => true, 'label' => 'Credit lines (money going out) must use a Bank/Cash group ledger.'],
        'receipt' => ['debit' => true, 'credit' => false, 'label' => 'Debit lines (money coming in) must use a Bank/Cash group ledger.'],
        default => ['debit' => false, 'credit' => false, 'label' => 'Any active ledger can be used on either side.'],
    };
}

/** Accepted spreadsheet type labels -> canonical voucher type keys. */
function voucher_import_normalize_type(string $raw): ?string
{
    $key = strtolower((string) preg_replace('/[^a-z]/i', '', $raw));
    $aliases = [
        'journal' => 'journal', 'journalvoucher' => 'journal', 'jv' => 'journal',
        'payment' => 'payment', 'paymentvoucher' => 'payment', 'pv' => 'payment',
        'receipt' => 'receipt', 'receiptvoucher' => 'receipt', 'rv' => 'receipt',
        'sales' => 'sales', 'salesvoucher' => 'sales', 'sv' => 'sales',
        'purchase' => 'purchase', 'purchasevoucher' => 'purchase', 'pu' => 'purchase', 'pur' => 'purchase',
        'contra' => 'contra', 'contravoucher' => 'contra', 'cv' => 'contra',
        'debitnote' => 'debit_note', 'dn' => 'debit_note',
        'creditnote' => 'credit_note', 'cn' => 'credit_note',
    ];
    return $aliases[$key] ?? null;
}

/**
 * Parse a sheet date into an AD Y-m-d string. Accepts AD or BS dates in
 * YYYY-MM-DD / DD-MM-YYYY (also with / or .), and raw Excel date serials.
 * Years >= 2064 are treated as Bikram Sambat (current BS is ~2083 while AD
 * 2064 is decades away, so the ranges cannot collide in practice).
 */
function voucher_import_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (is_numeric($raw)) {
        $serial = (float) $raw;
        if ($serial >= 20000 && $serial <= 80000) {
            $epoch = DateTimeImmutable::createFromFormat('!Y-m-d', '1899-12-30');
            return $epoch ? $epoch->modify('+' . (int) floor($serial) . ' days')->format('Y-m-d') : null;
        }
        return null;
    }
    if (!preg_match('/(\d{1,4})[.\/-](\d{1,2})[.\/-](\d{1,4})/', $raw, $m)) {
        return null;
    }
    if (strlen($m[1]) === 4) {
        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    } elseif (strlen($m[3]) === 4) {
        [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    } else {
        return null;
    }
    if ($year >= 2064) {
        return bs_to_ad($year, $month, $day);
    }
    if (!checkdate($month, $day, $year)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/** "1,25,000.50" / "Rs 500" -> 125000.50 / 500.0 */
function voucher_import_amount(string $raw): float
{
    $clean = (string) preg_replace('/[^0-9.\-]/', '', $raw);
    return $clean === '' || $clean === '-' || $clean === '.' ? 0.0 : round((float) $clean, 2);
}

/** Map a header row to [field => column index]. */
function voucher_import_map_headers(array $headerCells): array
{
    $aliases = [
        'voucher_no' => ['voucherno', 'vno', 'voucher', 'vchno', 'groupno', 'group'],
        'voucher_type' => ['vouchertype', 'type', 'vchtype'],
        'date' => ['date', 'voucherdate', 'transactiondate', 'miti', 'dateadorbs', 'dateadbs'],
        'title' => ['title', 'titledescription'],
        'narration' => ['narration', 'note', 'notes'],
        'ledger' => ['ledger', 'ledgercodeorname', 'ledgername', 'ledgercode', 'account', 'accountname', 'particulars'],
        'debit' => ['debit', 'dr', 'debitamount', 'dramount'],
        'credit' => ['credit', 'cr', 'creditamount', 'cramount'],
        'memo' => ['memo', 'linedescription', 'details', 'remarks'],
        'reference' => ['reference', 'ref', 'linereference', 'chequeno', 'chqno'],
        'party' => ['party', 'partyname', 'partycode'],
        'cost_centre' => ['costcentre', 'costcenter', 'cc'],
        'tax_code' => ['tax', 'taxcode', 'vat'],
        'department' => ['department', 'dept'],
        'location' => ['location'],
    ];
    $prefixes = ['debit' => 'debit', 'credit' => 'credit', 'ledger' => 'ledger', 'date' => 'date', 'voucherno' => 'voucher_no'];

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
        foreach ($prefixes as $prefix => $field) {
            if (str_starts_with($key, $prefix) && !isset($map[$field])) {
                $map[$field] = $index;
                continue 2;
            }
        }
    }
    return $map;
}

/** Read an uploaded sheet into [['n' => sheetRowNo, 'cells' => string[]], ...]. */
function voucher_import_read_rows(string $path, string $extension): array
{
    return $extension === 'csv' ? voucher_import_read_csv($path) : voucher_import_read_xlsx($path);
}

function voucher_import_read_csv(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Could not open the uploaded file.');
    }
    $rows = [];
    $rowNo = 0;
    while (($cells = fgetcsv($handle)) !== false) {
        $rowNo++;
        if ($rowNo > VOUCHER_IMPORT_MAX_ROWS) {
            break;
        }
        if ($rowNo === 1 && isset($cells[0])) {
            $cells[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
        }
        $rows[] = ['n' => $rowNo, 'cells' => array_map(static fn ($c): string => trim((string) $c), $cells)];
    }
    fclose($handle);
    return $rows;
}

function voucher_import_read_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The server is missing the PHP zip extension needed to read .xlsx files. Upload a .csv instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('The file is not a valid Excel (.xlsx) workbook.');
    }

    $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) {
        $zip->close();
        throw new RuntimeException('The workbook structure could not be read. Save the file as .xlsx (not .xls) and retry.');
    }

    $workbook = simplexml_load_string($workbookXml);
    $rels = simplexml_load_string($relsXml);
    if ($workbook === false || $rels === false) {
        $zip->close();
        throw new RuntimeException('The workbook XML could not be parsed.');
    }

    $relTargets = [];
    foreach ($rels->Relationship as $relationship) {
        $relTargets[(string) $relationship['Id']] = (string) $relationship['Target'];
    }
    $sheetPath = null;
    foreach ($workbook->sheets->sheet as $sheet) {
        $rid = (string) ($sheet->attributes($relNs)['id'] ?? '');
        $target = $relTargets[$rid] ?? '';
        if ($target !== '') {
            $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            break;
        }
    }
    if ($sheetPath === null) {
        $zip->close();
        throw new RuntimeException('No worksheet found in the workbook.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sst = simplexml_load_string($sharedXml);
        if ($sst !== false) {
            foreach ($sst->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string) $si->t;
                }
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('The worksheet could not be read from the workbook.');
    }
    $worksheet = simplexml_load_string($sheetXml);
    if ($worksheet === false) {
        throw new RuntimeException('The worksheet XML could not be parsed.');
    }

    $rows = [];
    foreach ($worksheet->sheetData->row as $row) {
        if (count($rows) >= VOUCHER_IMPORT_MAX_ROWS) {
            break;
        }
        $rowNo = (int) $row['r'];
        $cells = [];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            if (!preg_match('/^([A-Z]+)\d+$/', $ref, $m)) {
                continue;
            }
            $columnIndex = 0;
            foreach (str_split($m[1]) as $letter) {
                $columnIndex = $columnIndex * 26 + (ord($letter) - 64);
            }
            $columnIndex--;
            if ($columnIndex > 63) {
                continue;
            }
            $type = (string) $cell['t'];
            $value = '';
            if ($type === 's') {
                $value = $sharedStrings[(int) $cell->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
                foreach ($cell->is->r ?? [] as $run) {
                    $value .= (string) $run->t;
                }
            } else {
                $value = (string) $cell->v;
            }
            $cells[$columnIndex] = trim($value);
        }
        if ($cells === []) {
            continue;
        }
        $padded = array_fill(0, max(array_keys($cells)) + 1, '');
        foreach ($cells as $index => $value) {
            $padded[$index] = $value;
        }
        $rows[] = ['n' => $rowNo > 0 ? $rowNo : count($rows) + 1, 'cells' => $padded];
    }
    return $rows;
}

/** Active company ledgers keyed for lookup by code, name, and "name (code)". */
function voucher_import_ledger_lookup(int $companyId): array
{
    $stmt = db()->prepare("SELECT l.id, l.code, l.name, COALESCE(g.is_cash_or_bank, 0) AS is_cash_or_bank
        FROM ledgers l LEFT JOIN ledger_groups g ON g.id = l.group_id
        WHERE l.company_id = :company_id AND l.status = 'active'");
    $stmt->execute(['company_id' => $companyId]);
    $lookup = [];
    foreach ($stmt->fetchAll() as $ledger) {
        $entry = [
            'id' => (int) $ledger['id'],
            'label' => $ledger['name'] . ' (' . $ledger['code'] . ')',
            'is_cash_or_bank' => (int) $ledger['is_cash_or_bank'] === 1,
        ];
        $lookup[mb_strtolower(trim((string) $ledger['code']))] = $entry;
        $lookup[mb_strtolower(trim((string) $ledger['name']))] ??= $entry;
        $lookup[mb_strtolower(trim((string) $ledger['name']) . ' (' . trim((string) $ledger['code']) . ')')] = $entry;
    }
    return $lookup;
}

function voucher_import_party_lookup(int $companyId): array
{
    if (!table_exists('accounting_parties')) {
        return [];
    }
    $stmt = db()->prepare("SELECT id, code, name FROM accounting_parties WHERE company_id = :company_id AND status = 'active'");
    $stmt->execute(['company_id' => $companyId]);
    $lookup = [];
    foreach ($stmt->fetchAll() as $party) {
        $entry = ['id' => (int) $party['id'], 'label' => $party['name'] . ' (' . $party['code'] . ')'];
        $lookup[mb_strtolower(trim((string) $party['code']))] = $entry;
        $lookup[mb_strtolower(trim((string) $party['name']))] ??= $entry;
    }
    return $lookup;
}

/**
 * Group sheet rows into vouchers and validate each one with the voucher-form
 * rules. Returns ['error' => string] on a fatal sheet problem, otherwise
 * ['vouchers' => [...], 'data_rows' => int] where each voucher carries its
 * own 'errors' and 'warnings' lists.
 */
function voucher_import_parse(array $rows, int $companyId, int $fiscalYearId): array
{
    $headerMap = [];
    $headerRowIndex = null;
    foreach ($rows as $index => $row) {
        $candidate = voucher_import_map_headers($row['cells']);
        if (count($candidate) >= 4) {
            $headerMap = $candidate;
            $headerRowIndex = $index;
            break;
        }
    }
    if ($headerRowIndex === null) {
        return ['error' => 'No header row found. The first row must contain column names — download the template to see the expected layout.'];
    }
    $missing = array_diff(['voucher_no', 'voucher_type', 'date', 'ledger', 'debit', 'credit'], array_keys($headerMap));
    if ($missing !== []) {
        return ['error' => 'Missing required column(s): ' . implode(', ', array_map(static fn (string $f): string => str_replace('_', ' ', $f), $missing)) . '. Download the template to see the expected layout.'];
    }

    $ledgers = voucher_import_ledger_lookup($companyId);
    $parties = voucher_import_party_lookup($companyId);
    if ($ledgers === []) {
        return ['error' => 'This company has no active ledgers yet. Create ledgers in the Chart of Accounts before importing vouchers.'];
    }

    $cell = static fn (array $row, string $field): string => trim((string) ($row['cells'][$headerMap[$field] ?? -1] ?? ''));

    $vouchers = [];
    $order = [];
    $currentKey = null;
    $dataRows = 0;

    foreach (array_slice($rows, $headerRowIndex + 1) as $row) {
        $isEmpty = trim(implode('', $row['cells'])) === '';
        if ($isEmpty) {
            $currentKey = null;
            continue;
        }
        $dataRows++;
        $key = $cell($row, 'voucher_no');
        if ($key !== '') {
            $currentKey = $key;
        }
        if ($currentKey === null) {
            $vouchers['__orphan__']['errors'][] = 'Row ' . $row['n'] . ': no Voucher No — every voucher must start with a row that has a Voucher No (continuation lines may leave it blank).';
            $vouchers['__orphan__'] += ['key' => '(missing voucher no)', 'lines' => [], 'debit_total' => 0.0, 'credit_total' => 0.0, 'warnings' => [], 'first_row' => $row['n']];
            if (!in_array('__orphan__', $order, true)) {
                $order[] = '__orphan__';
            }
            continue;
        }

        if (!isset($vouchers[$currentKey])) {
            $vouchers[$currentKey] = [
                'key' => $currentKey, 'type' => null, 'type_raw' => '', 'date' => null, 'date_raw' => '',
                'title' => '', 'narration' => '', 'party_id' => null, 'party_label' => '', 'party_raw' => '',
                'department' => '', 'location' => '', 'cost_centre' => '',
                'lines' => [], 'debit_total' => 0.0, 'credit_total' => 0.0,
                'errors' => [], 'warnings' => [], 'first_row' => $row['n'],
            ];
            $order[] = $currentKey;
        }
        $voucher = &$vouchers[$currentKey];

        foreach (['type_raw' => 'voucher_type', 'date_raw' => 'date', 'title' => 'title', 'narration' => 'narration', 'party_raw' => 'party', 'department' => 'department', 'location' => 'location', 'cost_centre' => 'cost_centre'] as $target => $field) {
            if ($voucher[$target] === '' && isset($headerMap[$field])) {
                $voucher[$target] = $cell($row, $field);
            }
        }

        $ledgerRaw = $cell($row, 'ledger');
        $debit = voucher_import_amount($cell($row, 'debit'));
        $credit = voucher_import_amount($cell($row, 'credit'));
        if ($ledgerRaw === '' && $debit == 0.0 && $credit == 0.0) {
            unset($voucher);
            continue; // header-only row carrying voucher fields
        }
        if ($ledgerRaw === '') {
            $voucher['errors'][] = 'Row ' . $row['n'] . ': an amount is given but the Ledger cell is empty.';
            unset($voucher);
            continue;
        }
        $ledger = $ledgers[mb_strtolower($ledgerRaw)] ?? null;
        if ($ledger === null) {
            $voucher['errors'][] = 'Row ' . $row['n'] . ': ledger "' . $ledgerRaw . '" was not found among this company\'s active ledgers (match by code or exact name — download the ledger list to check).';
            unset($voucher);
            continue;
        }
        if ($debit < 0 || $credit < 0) {
            $voucher['errors'][] = 'Row ' . $row['n'] . ': amounts cannot be negative — swap the Debit/Credit column instead.';
            unset($voucher);
            continue;
        }
        if (($debit > 0) === ($credit > 0)) {
            $voucher['errors'][] = 'Row ' . $row['n'] . ': each line needs an amount in exactly one of Debit or Credit.';
            unset($voucher);
            continue;
        }

        $voucher['lines'][] = [
            'row' => $row['n'],
            'ledger_id' => $ledger['id'],
            'ledger_label' => $ledger['label'],
            'is_cash_or_bank' => $ledger['is_cash_or_bank'],
            'entry_type' => $debit > 0 ? 'debit' : 'credit',
            'amount' => $debit > 0 ? $debit : $credit,
            'memo' => $cell($row, 'memo'),
            'cost_centre' => $cell($row, 'cost_centre'),
            'tax_code' => $cell($row, 'tax_code'),
            'line_reference' => $cell($row, 'reference'),
        ];
        if ($debit > 0) {
            $voucher['debit_total'] = round($voucher['debit_total'] + $debit, 2);
        } else {
            $voucher['credit_total'] = round($voucher['credit_total'] + $credit, 2);
        }
        unset($voucher);
    }

    // Voucher-level validation.
    $duplicateStmt = null;
    if (column_exists('vouchers', 'reference_no')) {
        $duplicateStmt = db()->prepare('SELECT voucher_no FROM vouchers WHERE company_id = :company_id AND reference_no = :reference_no AND voucher_date = :voucher_date AND total_amount = :total_amount LIMIT 1');
    }
    $result = [];
    foreach ($order as $key) {
        $voucher = $vouchers[$key];
        if ($key === '__orphan__') {
            $result[] = $voucher + ['type' => null, 'type_label' => '—', 'date' => null, 'date_display' => '—', 'title' => '', 'narration' => ''];
            continue;
        }

        $voucher['type'] = voucher_import_normalize_type($voucher['type_raw']);
        if ($voucher['type'] === null) {
            $voucher['errors'][] = $voucher['type_raw'] === ''
                ? 'Voucher Type is missing on the first row of this voucher.'
                : 'Unknown Voucher Type "' . $voucher['type_raw'] . '" — use one of: ' . implode(', ', VOUCHER_IMPORT_TYPES) . '.';
        }
        $voucher['type_label'] = $voucher['type'] !== null ? VOUCHER_IMPORT_TYPES[$voucher['type']] : ($voucher['type_raw'] !== '' ? $voucher['type_raw'] : '—');

        $voucher['date'] = voucher_import_date($voucher['date_raw']);
        if ($voucher['date'] === null) {
            $voucher['errors'][] = $voucher['date_raw'] === ''
                ? 'Date is missing on the first row of this voucher.'
                : 'Date "' . $voucher['date_raw'] . '" is not valid — use YYYY-MM-DD in AD or BS (years 2064+ are read as BS).';
        } elseif (is_period_locked($companyId, $fiscalYearId, $voucher['date'])) {
            $voucher['errors'][] = 'Date ' . $voucher['date'] . ' falls inside a locked accounting period.';
        }
        $bs = $voucher['date'] !== null ? bs_format($voucher['date']) : '';
        $voucher['date_display'] = $voucher['date'] !== null ? $voucher['date'] . ($bs !== '' ? ' · ' . $bs : '') : $voucher['date_raw'];

        if ($voucher['title'] === '') {
            $voucher['title'] = $voucher['narration'] !== ''
                ? mb_substr($voucher['narration'], 0, 180)
                : 'Imported ' . ($voucher['type_label'] === '—' ? 'voucher' : $voucher['type_label']) . ' ' . $voucher['key'];
        }

        if ($voucher['party_raw'] !== '') {
            $party = $parties[mb_strtolower($voucher['party_raw'])] ?? null;
            if ($party === null) {
                $voucher['errors'][] = 'Party "' . $voucher['party_raw'] . '" was not found among this company\'s active parties — fix the name/code or leave the cell blank.';
            } else {
                $voucher['party_id'] = $party['id'];
                $voucher['party_label'] = $party['label'];
            }
        }

        if (count($voucher['lines']) < 2) {
            $voucher['errors'][] = 'A voucher needs at least two ledger lines (one debit, one credit).';
        } elseif (abs($voucher['debit_total'] - $voucher['credit_total']) >= 0.005) {
            $voucher['errors'][] = sprintf('Not balanced: total debit %.2f ≠ total credit %.2f.', $voucher['debit_total'], $voucher['credit_total']);
        }

        if ($voucher['type'] !== null) {
            $rule = voucher_import_cash_bank_rule($voucher['type']);
            foreach ($voucher['lines'] as $line) {
                if ($rule[$line['entry_type']] && !$line['is_cash_or_bank']) {
                    $voucher['errors'][] = 'Row ' . $line['row'] . ': ' . $voucher['type_label'] . ' requires the ' . $line['entry_type'] . ' line to use a Bank/Cash group ledger, but "' . $line['ledger_label'] . '" is not one.';
                }
            }
        }

        if ($voucher['errors'] === [] && $duplicateStmt !== null) {
            $duplicateStmt->execute([
                'company_id' => $companyId,
                'reference_no' => mb_substr($voucher['key'], 0, 120),
                'voucher_date' => $voucher['date'],
                'total_amount' => $voucher['debit_total'],
            ]);
            $existing = $duplicateStmt->fetch();
            if ($existing) {
                $voucher['warnings'][] = 'Possible duplicate: voucher ' . $existing['voucher_no'] . ' already has reference "' . $voucher['key'] . '" with the same date and amount.';
            }
        }

        $result[] = $voucher;
    }

    return ['vouchers' => $result, 'data_rows' => $dataRows];
}

// ---------------------------------------------------------------------------
// Templates.
// ---------------------------------------------------------------------------

function voucher_import_template_rows(): array
{
    return [
        ['Voucher No', 'Voucher Type', 'Date (AD or BS)', 'Title', 'Narration', 'Ledger (Code or Name)', 'Debit', 'Credit', 'Line Description', 'Reference', 'Party', 'Cost Centre'],
        ['JV-001', 'Journal', '2083-03-24', 'Office rent for Ashadh', 'Monthly rent booking', 'Rent Expense', 25000, '', 'Rent for Ashadh 2083', '', '', 'General'],
        ['', '', '', '', '', 'Cash', '', 25000, 'Paid from cash', '', '', ''],
        ['PV-001', 'Payment', '2026-07-08', 'Electricity bill paid', '', 'Electricity Expense', 4500, '', '', 'NEA-1201', '', ''],
        ['', '', '', '', '', 'Bank', '', 4500, 'Cheque payment', 'CHQ-1201', '', ''],
        ['RV-001', 'Receipt', '2026-07-08', 'Advance received from client', '', 'Bank', 15000, '', '', '', '', ''],
        ['', '', '', '', '', 'Client Advances', '', 15000, '', '', '', ''],
    ];
}

function voucher_import_template_csv(): string
{
    $handle = fopen('php://temp', 'r+b');
    foreach (voucher_import_template_rows() as $row) {
        fputcsv($handle, $row);
    }
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);
    return $csv;
}

function voucher_import_ledger_list_csv(int $companyId): string
{
    $stmt = db()->prepare("SELECT l.code, l.name, g.name AS group_name, g.master_key, COALESCE(g.is_cash_or_bank, 0) AS is_cash_or_bank
        FROM ledgers l LEFT JOIN ledger_groups g ON g.id = l.group_id
        WHERE l.company_id = :company_id AND l.status = 'active' ORDER BY l.name ASC");
    $stmt->execute(['company_id' => $companyId]);
    $handle = fopen('php://temp', 'r+b');
    fputcsv($handle, ['Ledger Code', 'Ledger Name', 'Group', 'Master Group', 'Bank/Cash Ledger']);
    foreach ($stmt->fetchAll() as $ledger) {
        fputcsv($handle, [
            $ledger['code'], $ledger['name'], $ledger['group_name'] ?? '',
            ucwords(str_replace('_', ' ', (string) ($ledger['master_key'] ?? ''))),
            ((int) $ledger['is_cash_or_bank'] === 1) ? 'Yes' : 'No',
        ]);
    }
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);
    return $csv;
}

/** Minimal single-sheet .xlsx built with inline strings. */
function voucher_import_template_xlsx(): string
{
    $xml = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $rowsXml = '';
    foreach (voucher_import_template_rows() as $rowIndex => $row) {
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
            . '<sheets><sheet name="Vouchers" sheetId="1" r:id="rId1"/></sheets></workbook>',
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
            . '<cols><col min="1" max="2" width="14" customWidth="1"/><col min="3" max="6" width="22" customWidth="1"/><col min="7" max="8" width="12" customWidth="1"/><col min="9" max="12" width="18" customWidth="1"/></cols>'
            . '<sheetData>' . $rowsXml . '</sheetData></worksheet>',
    ];

    $temp = tempnam(sys_get_temp_dir(), 'vimp');
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
