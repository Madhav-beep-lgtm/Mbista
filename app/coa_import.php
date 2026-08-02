<?php
declare(strict_types=1);

/**
 * Chart of accounts from a spreadsheet — parse, stage, preview, commit.
 *
 * A chart is what every other record in the books points at, so building one by
 * hand at twenty accounts a screen is the slowest part of opening a company —
 * and applying two hundred accounts straight off an uploaded file is the most
 * dangerous. This engine keeps both halves: the whole sheet arrives at once,
 * but nothing exists until someone has read the preview and pressed Commit.
 *
 * The sheet is the same shape the Export COA button produces, so the natural
 * way to prepare one is to export what a company already has, edit it, and
 * upload it back:
 *
 *   Level | Code | Name | Master | Type | Group Code | Opening Dr | Opening Cr
 *
 * Level is what tells a group row from a ledger row. Groups and ledgers travel
 * in ONE file because that is how a chart is actually written down, and because
 * a ledger may name a group that appears further down the sheet — so commit
 * creates every group first and only then hangs ledgers off them.
 *
 * On codes: they are optional. The app generates structured codes (nature digit
 * → group → ledger) and will do so for any row that leaves the column blank, so
 * a shop that has never used codes can upload names alone.
 *
 * On opening balances: this general ledger is PERPETUAL — an opening balance is
 * not a field on the ledger, it is a posted journal against Opening Balance
 * Adjustments (see post_ledger_opening_balance()). The sheet's Dr/Cr columns are
 * therefore an instruction, staged as typed and posted only at commit, and the
 * batch must balance first. An unbalanced sheet would tip its difference into
 * the adjustments ledger and look, from every report, like the books simply did
 * not add up — which is the whole thing the opening-balance engine exists to
 * stop. So it is refused, with the two totals shown.
 */

require_once __DIR__ . '/export_engine.php';

/** A chart is big, but not unbounded — this is a guard against a runaway file. */
const COA_IMPORT_MAX_ROWS = 5000;

/** The column order of both the template and the Export COA file. */
const COA_IMPORT_COLUMNS = ['Level', 'Code', 'Name', 'Master', 'Type', 'Group Code', 'Opening Dr', 'Opening Cr', 'Status'];

const COA_IMPORT_LEDGER_TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

/**
 * The template a user downloads: the header, then one worked example of each
 * kind of row. The examples are real enough to upload as-is, which makes the
 * fastest way to understand the format "download it and look".
 */
function coa_import_template_rows(): array
{
    return [
        COA_IMPORT_COLUMNS,
        ['Group', '', 'Current Assets', 'current_asset', '', '', '', '', ''],
        ['Ledger', '', 'Cash in Hand', '', 'asset', 'Current Assets', '150000.00', '', ''],
        ['Ledger', '', 'Bank — Current A/c', '', 'asset', 'Current Assets', '45000.00', '', ''],
        ['Group', '', 'Capital Account', 'equity', '', '', '', '', ''],
        ['Ledger', '', 'Proprietor Capital', '', 'equity', 'Capital Account', '', '195000.00', ''],
        ['Group', '', 'Indirect Expenses', 'indirect_expense', '', '', '', '', ''],
        ['Ledger', '', 'Office Rent', '', 'expense', 'Indirect Expenses', '', '', ''],
    ];
}

/** Number as the sheet wrote it → float. Blank is 0, not an error. */
function coa_import_amount(string $raw): ?float
{
    $clean = trim($raw);
    if ($clean === '') {
        return 0.0;
    }
    // Sheets export thousands separators and currency symbols; a person typing
    // by hand adds them too. Strip them rather than reject the row over comma.
    $clean = str_replace([',', ' ', "\u{00A0}", 'Rs.', 'Rs', 'NPR', '₹'], '', $clean);
    if ($clean === '' || !is_numeric($clean)) {
        return null;
    }

    return round((float) $clean, 2);
}

/**
 * Existing groups keyed by upper-case code AND by lower-case name.
 *
 * Both, because a sheet written by a person names the group ("Current Assets")
 * while a sheet exported by this app carries the code ("11"). Refusing the
 * human version would make the feature useless for the case it exists to serve.
 */
function coa_import_group_lookup(int $companyId): array
{
    $stmt = db()->prepare('SELECT id, code, name, master_key FROM ledger_groups WHERE company_id = :cid');
    $stmt->execute(['cid' => $companyId]);
    $lookup = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $group) {
        $entry = [
            'id' => (int) $group['id'],
            'code' => (string) $group['code'],
            'name' => (string) $group['name'],
            'master_key' => (string) $group['master_key'],
        ];
        $lookup['code:' . mb_strtoupper(trim((string) $group['code']))] = $entry;
        $lookup['name:' . mb_strtolower(trim((string) $group['name']))] ??= $entry;
    }

    return $lookup;
}

/**
 * What this company already has, indexed both ways an uploaded row can be
 * recognised: by code, and by name within a group.
 *
 * The second index is what makes a blank Code column safe. A chart typed by
 * hand usually has no codes at all — the app generates them — so code alone
 * would recognise nothing, and the same file uploaded twice would build the
 * whole chart again under fresh codes.
 */
function coa_import_ledger_index(int $companyId): array
{
    $stmt = db()->prepare('SELECT code, name, group_id FROM ledgers WHERE company_id = :cid');
    $stmt->execute(['cid' => $companyId]);
    $codes = [];
    $names = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ledger) {
        $codes[mb_strtoupper(trim((string) $ledger['code']))] = true;
        $names[(int) $ledger['group_id'] . '|' . mb_strtolower(trim((string) $ledger['name']))] = true;
    }

    return ['codes' => $codes, 'names' => $names];
}

/**
 * Read the uploaded file, judge every row, and write the whole lot to staging.
 * Nothing in the chart is touched. Returns the new import's id.
 */
function coa_import_stage(int $companyId, ?int $fiscalYearId, int $userId, string $path, string $originalName): int
{
    $rows = spreadsheet_read_rows($path, $originalName, COA_IMPORT_MAX_ROWS);
    if ($rows === []) {
        throw new RuntimeException('The file has no rows in it.');
    }

    $masters = ledger_masters();
    $groupLookup = coa_import_group_lookup($companyId);
    $ledgerIndex = coa_import_ledger_index($companyId);

    // Codes claimed by earlier rows of THIS sheet. Without it a file listing the
    // same code twice would pass validation twice and collide at commit, where
    // the error is a duplicate-key crash instead of a sentence.
    $claimedGroupCodes = [];
    $claimedGroupNames = [];
    $claimedLedgerCodes = [];
    $claimedLedgerNames = [];

    $cellsOf = static function (array $row): array {
        $cells = $row['cells'];

        return [
            'level' => mb_strtolower(trim((string) ($cells[0] ?? ''))),
            'code' => trim((string) ($cells[1] ?? '')),
            'name' => trim((string) ($cells[2] ?? '')),
            'master' => mb_strtolower(trim((string) ($cells[3] ?? ''))),
        ];
    };

    // ---- Pass one: every group the sheet will create ----------------------
    // A ledger is allowed to name a group written further DOWN the file — a
    // chart is commonly typed with the accounts first and the headings filled in
    // after, and commit creates all groups before any ledger anyway. Validation
    // therefore has to see the whole sheet before it can judge any single
    // ledger, so the groups are collected here and only then is anything judged.
    $sheetGroups = [];
    foreach ($rows as $row) {
        $c = $cellsOf($row);
        if ($c['level'] !== 'group' || $c['name'] === '' || !isset($masters[$c['master']])) {
            continue;
        }
        $entry = ['master_key' => $c['master']];
        // First mention wins; a later duplicate is refused in pass two, so it
        // must not be the one a ledger resolves against.
        $sheetGroups['name:' . mb_strtolower($c['name'])] ??= $entry;
        if ($c['code'] !== '') {
            $sheetGroups['code:' . mb_strtoupper($c['code'])] ??= $entry;
        }
    }

    $staged = [];
    foreach ($rows as $row) {
        $cells = $row['cells'];
        $get = static fn (int $i): string => trim((string) ($cells[$i] ?? ''));

        $rawLevel = $get(0);
        $rawCode = $get(1);
        $rawName = $get(2);
        $rawMaster = $get(3);
        $rawType = $get(4);
        $rawGroupCode = $get(5);
        $rawDr = $get(6);
        $rawCr = $get(7);

        // A wholly blank line in the middle of a sheet is padding, not an error.
        if ($rawLevel === '' && $rawCode === '' && $rawName === '') {
            continue;
        }

        $level = mb_strtolower($rawLevel);
        // The header, however it was capitalised, and whether the file came from
        // the export button or was typed from the template.
        if ($level === 'level' || ($rawName !== '' && mb_strtolower($rawName) === 'name' && $level === '')) {
            continue;
        }

        $entry = [
            'n' => (int) $row['n'],
            'level' => 'unknown',
            'raw_level' => $rawLevel, 'raw_code' => $rawCode, 'raw_name' => $rawName,
            'raw_master' => $rawMaster, 'raw_type' => $rawType, 'raw_group_code' => $rawGroupCode,
            'raw_opening_dr' => $rawDr, 'raw_opening_cr' => $rawCr,
            'group_id' => null, 'code' => '', 'name' => $rawName, 'master_key' => '',
            'ledger_type' => '', 'parent_code' => '',
            'opening_dr' => 0.0, 'opening_cr' => 0.0,
            'status' => 'ready', 'error' => null,
        ];

        $fail = static function (array $entry, string $why): array {
            $entry['status'] = 'error';
            $entry['error'] = $why;

            return $entry;
        };

        // The nine master levels are fixed by the system. An exported chart
        // lists them, so a round-tripped file will contain them — they are
        // reported as skipped rather than treated as an error, because the user
        // did nothing wrong by leaving them in.
        if ($level === 'master') {
            $entry['level'] = 'group';
            $entry['status'] = 'skipped';
            $entry['error'] = 'Master levels are built into the system and are never created by an upload.';
            $staged[] = $entry;
            continue;
        }

        if ($level !== 'group' && $level !== 'ledger') {
            $staged[] = $fail($entry, $rawLevel === ''
                ? 'The Level column is empty — it must say Group or Ledger.'
                : 'Level "' . $rawLevel . '" is not understood — it must say Group or Ledger.');
            continue;
        }
        $entry['level'] = $level;

        if ($rawName === '') {
            $staged[] = $fail($entry, 'The Name column is empty.');
            continue;
        }

        $dr = coa_import_amount($rawDr);
        $cr = coa_import_amount($rawCr);
        if ($dr === null || $cr === null) {
            $staged[] = $fail($entry, 'The opening balance is not a number.');
            continue;
        }
        if ($dr < 0 || $cr < 0) {
            $staged[] = $fail($entry, 'An opening balance cannot be negative — put it in the other column instead.');
            continue;
        }
        if ($dr > 0 && $cr > 0) {
            $staged[] = $fail($entry, 'This row has both a debit and a credit opening — an account opens on one side.');
            continue;
        }
        $entry['opening_dr'] = $dr;
        $entry['opening_cr'] = $cr;

        $upperCode = mb_strtoupper($rawCode);

        if ($level === 'group') {
            if ($dr > 0 || $cr > 0) {
                $staged[] = $fail($entry, 'A group cannot carry an opening balance — put it on a ledger inside the group.');
                continue;
            }
            $masterKey = mb_strtolower($rawMaster);
            if ($masterKey === '' || !isset($masters[$masterKey])) {
                $staged[] = $fail($entry, $rawMaster === ''
                    ? 'A group needs a Master — one of: ' . implode(', ', array_keys($masters)) . '.'
                    : 'Master "' . $rawMaster . '" is not one of: ' . implode(', ', array_keys($masters)) . '.');
                continue;
            }
            $entry['master_key'] = $masterKey;
            $entry['code'] = $rawCode;

            $existing = $groupLookup['code:' . $upperCode] ?? null;
            if ($rawCode === '') {
                $existing = $groupLookup['name:' . mb_strtolower($rawName)] ?? null;
            }
            if ($existing !== null) {
                $entry['status'] = 'skipped';
                $entry['group_id'] = $existing['id'];
                $entry['error'] = 'A group with this ' . ($rawCode === '' ? 'name' : 'code')
                    . ' already exists (' . $existing['name'] . ') — it was left as it is.';
                $staged[] = $entry;
                continue;
            }
            if ($rawCode !== '' && isset($claimedGroupCodes[$upperCode])) {
                $staged[] = $fail($entry, 'Code ' . $rawCode . ' is used by another group earlier in this sheet.');
                continue;
            }
            // Two group rows of the same name with no codes would otherwise both
            // be created, leaving the chart with a pair of identical headings and
            // the ledgers under them split arbitrarily between the two.
            $nameKey = mb_strtolower($rawName);
            if (isset($claimedGroupNames[$nameKey])) {
                $staged[] = $fail($entry, 'A group called "' . $rawName . '" appears earlier in this sheet.');
                continue;
            }
            $claimedGroupNames[$nameKey] = true;
            if ($rawCode !== '') {
                $claimedGroupCodes[$upperCode] = true;
            }
            $staged[] = $entry;
            continue;
        }

        // ---- Ledger row --------------------------------------------------
        $type = mb_strtolower($rawType);
        if ($type === '' || !in_array($type, COA_IMPORT_LEDGER_TYPES, true)) {
            $staged[] = $fail($entry, $rawType === ''
                ? 'A ledger needs a Type — one of: ' . implode(', ', COA_IMPORT_LEDGER_TYPES) . '.'
                : 'Type "' . $rawType . '" is not one of: ' . implode(', ', COA_IMPORT_LEDGER_TYPES) . '.');
            continue;
        }
        $entry['ledger_type'] = $type;
        $entry['code'] = $rawCode;

        if ($rawGroupCode === '') {
            $staged[] = $fail($entry, 'A ledger needs a Group Code saying which group it belongs to.');
            continue;
        }
        $entry['parent_code'] = $rawGroupCode;

        $groupKeyCode = 'code:' . mb_strtoupper($rawGroupCode);
        $groupKeyName = 'name:' . mb_strtolower($rawGroupCode);
        $existingGroup = $groupLookup[$groupKeyCode] ?? $groupLookup[$groupKeyName] ?? null;
        $pendingGroup = $sheetGroups[$groupKeyCode] ?? $sheetGroups[$groupKeyName] ?? null;
        if ($existingGroup === null && $pendingGroup === null) {
            $staged[] = $fail($entry, 'Group "' . $rawGroupCode . '" was not found — no group of that code or name '
                . 'exists, and none is created earlier in this sheet.');
            continue;
        }
        $groupMaster = $existingGroup !== null ? $existingGroup['master_key'] : (string) $pendingGroup['master_key'];
        if ($existingGroup !== null) {
            $entry['group_id'] = $existingGroup['id'];
        }

        // A ledger's own type and the nature of the group it sits in must agree.
        // Reports split the balance sheet from the P&L by the GROUP's master,
        // while opening balances and posting rules read the LEDGER's type — so a
        // revenue ledger filed under Current Assets is not a cosmetic mismatch,
        // it is an account that two parts of the system describe differently.
        $groupNature = ledger_master_nature($groupMaster);
        if ($groupNature !== null && $groupNature !== $type) {
            $staged[] = $fail($entry, 'Type "' . $type . '" does not match the group, which is '
                . $groupNature . '. A ledger must be the same nature as the group it sits in.');
            continue;
        }

        if (($dr > 0 || $cr > 0) && !in_array($type, ['asset', 'liability', 'equity'], true)) {
            $staged[] = $fail($entry, 'Income and expense accounts always open at zero, so this row '
                . 'cannot carry an opening balance.');
            continue;
        }

        if ($rawCode !== '' && isset($ledgerIndex['codes'][$upperCode])) {
            $entry['status'] = 'skipped';
            $entry['error'] = 'A ledger with code ' . $rawCode . ' already exists — it was left as it is.';
            $staged[] = $entry;
            continue;
        }
        // Scoped to the group, because the same account name under two different
        // headings is legitimate — "Opening Stock" can sit in more than one place.
        if ($rawCode === '' && $entry['group_id'] !== null
            && isset($ledgerIndex['names'][$entry['group_id'] . '|' . mb_strtolower($rawName)])) {
            $entry['status'] = 'skipped';
            $entry['error'] = 'A ledger called "' . $rawName . '" already exists in this group — it was left as it is.';
            $staged[] = $entry;
            continue;
        }
        if ($rawCode !== '' && isset($claimedLedgerCodes[$upperCode])) {
            $staged[] = $fail($entry, 'Code ' . $rawCode . ' is used by another ledger earlier in this sheet.');
            continue;
        }
        $sheetNameKey = mb_strtolower($entry['parent_code']) . '|' . mb_strtolower($rawName);
        if (isset($claimedLedgerNames[$sheetNameKey])) {
            $staged[] = $fail($entry, 'A ledger called "' . $rawName . '" appears in this group earlier in the sheet.');
            continue;
        }
        $claimedLedgerNames[$sheetNameKey] = true;
        if ($rawCode !== '') {
            $claimedLedgerCodes[$upperCode] = true;
        }
        $staged[] = $entry;
    }

    if ($staged === []) {
        throw new RuntimeException('The file had no account rows in it — only a header, or only blank lines.');
    }

    // ---- Write the batch ---------------------------------------------------
    $readyCount = 0;
    $skippedCount = 0;
    $errorCount = 0;
    $drTotal = 0.0;
    $crTotal = 0.0;
    foreach ($staged as $entry) {
        if ($entry['status'] === 'ready') {
            $readyCount++;
            // Only what will actually post counts toward the balance test.
            $drTotal += $entry['opening_dr'];
            $crTotal += $entry['opening_cr'];
        } elseif ($entry['status'] === 'skipped') {
            $skippedCount++;
        } else {
            $errorCount++;
        }
    }

    db()->prepare('INSERT INTO coa_imports (company_id, fiscal_year_id, original_name, row_count, ready_count,
            skipped_count, error_count, opening_dr_total, opening_cr_total, status, created_by)
        VALUES (:cid, :fy, :name, :rows, :ready, :skipped, :errors, :dr, :cr, \'staged\', :by)')
        ->execute([
            'cid' => $companyId, 'fy' => $fiscalYearId ?: null,
            'name' => mb_substr($originalName, 0, 255),
            'rows' => count($staged), 'ready' => $readyCount,
            'skipped' => $skippedCount, 'errors' => $errorCount,
            'dr' => round($drTotal, 2), 'cr' => round($crTotal, 2),
            'by' => $userId ?: null,
        ]);
    $importId = (int) db()->lastInsertId();

    $insert = db()->prepare('INSERT INTO coa_import_rows (import_id, company_id, source_row_no, level,
            raw_level, raw_code, raw_name, raw_master, raw_type, raw_group_code, raw_opening_dr, raw_opening_cr,
            group_id, code, name, master_key, ledger_type, parent_code, opening_dr, opening_cr, status, error_text)
        VALUES (:imp, :cid, :n, :level, :rlevel, :rcode, :rname, :rmaster, :rtype, :rgroup, :rdr, :rcr,
            :gid, :code, :name, :mk, :ltype, :parent, :dr, :cr, :status, :err)');
    foreach ($staged as $entry) {
        $insert->execute([
            'imp' => $importId, 'cid' => $companyId, 'n' => $entry['n'], 'level' => $entry['level'],
            'rlevel' => mb_substr($entry['raw_level'], 0, 60), 'rcode' => mb_substr($entry['raw_code'], 0, 120),
            'rname' => mb_substr($entry['raw_name'], 0, 255), 'rmaster' => mb_substr($entry['raw_master'], 0, 120),
            'rtype' => mb_substr($entry['raw_type'], 0, 60), 'rgroup' => mb_substr($entry['raw_group_code'], 0, 120),
            'rdr' => mb_substr($entry['raw_opening_dr'], 0, 60), 'rcr' => mb_substr($entry['raw_opening_cr'], 0, 60),
            'gid' => $entry['group_id'], 'code' => mb_substr($entry['code'], 0, 40),
            'name' => mb_substr($entry['name'], 0, 150), 'mk' => $entry['master_key'],
            'ltype' => $entry['ledger_type'], 'parent' => mb_substr($entry['parent_code'], 0, 150),
            'dr' => $entry['opening_dr'], 'cr' => $entry['opening_cr'],
            'status' => $entry['status'], 'err' => $entry['error'] !== null ? mb_substr($entry['error'], 0, 500) : null,
        ]);
    }

    return $importId;
}

/** The batch header, or null when it is not this company's. */
function coa_import_batch(int $companyId, int $importId): ?array
{
    $stmt = db()->prepare('SELECT * FROM coa_imports WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $importId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Staged rows in file order — the preview reads exactly like the sheet. */
function coa_import_rows(int $companyId, int $importId): array
{
    $stmt = db()->prepare('SELECT * FROM coa_import_rows WHERE import_id = :id AND company_id = :cid
        ORDER BY source_row_no ASC, id ASC');
    $stmt->execute(['id' => $importId, 'cid' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** The most recent batch still waiting for a decision, if there is one. */
function coa_import_latest_staged(int $companyId): ?array
{
    $stmt = db()->prepare("SELECT * FROM coa_imports WHERE company_id = :cid AND status = 'staged'
        ORDER BY id DESC LIMIT 1");
    $stmt->execute(['cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Walk away from a staged batch. The rows go with it (ON DELETE CASCADE). */
function coa_import_discard(int $companyId, int $importId): void
{
    db()->prepare("UPDATE coa_imports SET status = 'discarded' WHERE id = :id AND company_id = :cid AND status = 'staged'")
        ->execute(['id' => $importId, 'cid' => $companyId]);
}

/**
 * Whether the openings this batch will post add up. Returns null when they do,
 * or a sentence naming both totals when they do not.
 */
function coa_import_balance_error(array $batch): ?string
{
    $dr = round((float) $batch['opening_dr_total'], 2);
    $cr = round((float) $batch['opening_cr_total'], 2);
    if ($dr <= 0.0 && $cr <= 0.0) {
        return null;
    }
    if (abs($dr - $cr) < 0.005) {
        return null;
    }

    return 'The opening balances do not balance: debits come to ' . number_format($dr, 2)
        . ' and credits to ' . number_format($cr, 2) . ', a difference of '
        . number_format(abs($dr - $cr), 2) . '. Fix the sheet and upload it again.';
}

/**
 * Create everything the batch is ready to create, inside one transaction.
 *
 * Order matters and is the reason this is not a single loop: every group is
 * created first, so a ledger may name a group that appears anywhere in the
 * sheet — including below it — and still find it.
 *
 * @return array{ok: bool, error: ?string, groups: int, ledgers: int, openings: int}
 */
function coa_import_commit(int $companyId, int $importId, int $userId = 0): array
{
    $batch = coa_import_batch($companyId, $importId);
    if ($batch === null) {
        return ['ok' => false, 'error' => 'That upload was not found for this company.', 'groups' => 0, 'ledgers' => 0, 'openings' => 0];
    }
    if ((string) $batch['status'] !== 'staged') {
        return ['ok' => false, 'error' => 'That upload has already been ' . $batch['status'] . '.', 'groups' => 0, 'ledgers' => 0, 'openings' => 0];
    }
    if ((int) $batch['ready_count'] <= 0) {
        return ['ok' => false, 'error' => 'There is nothing to import — no row in this file can be created.', 'groups' => 0, 'ledgers' => 0, 'openings' => 0];
    }
    $balanceError = coa_import_balance_error($batch);
    if ($balanceError !== null) {
        return ['ok' => false, 'error' => $balanceError, 'groups' => 0, 'ledgers' => 0, 'openings' => 0];
    }

    $rows = coa_import_rows($companyId, $importId);
    $groupsMade = 0;
    $ledgersMade = 0;
    $openingsPosted = 0;
    // Filled as groups are created so the ledger pass can resolve by either key.
    $resolved = [];
    foreach (coa_import_group_lookup($companyId) as $key => $group) {
        $resolved[$key] = $group['id'];
    }

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        $markRow = db()->prepare("UPDATE coa_import_rows SET status = 'committed', group_id = :gid, ledger_id = :lid,
                code = :code WHERE id = :id");

        // ---- Pass 1: groups ------------------------------------------------
        $insertGroup = db()->prepare('INSERT INTO ledger_groups (company_id, code, name, master_key, is_cash_or_bank, is_system, is_active)
            VALUES (:cid, :code, :name, :mk, 0, 0, 1)');
        foreach ($rows as $row) {
            if ((string) $row['level'] !== 'group' || (string) $row['status'] !== 'ready') {
                continue;
            }
            $masterKey = (string) $row['master_key'];
            $code = (string) $row['code'] !== '' ? (string) $row['code'] : coa_next_group_code($companyId, $masterKey);
            $insertGroup->execute(['cid' => $companyId, 'code' => $code,
                'name' => (string) $row['name'], 'mk' => $masterKey]);
            $groupId = (int) db()->lastInsertId();
            $groupsMade++;
            $resolved['code:' . mb_strtoupper($code)] = $groupId;
            $resolved['name:' . mb_strtolower((string) $row['name'])] = $groupId;
            $markRow->execute(['gid' => $groupId, 'lid' => null, 'code' => $code, 'id' => (int) $row['id']]);
        }

        // ---- Pass 2: ledgers -----------------------------------------------
        $insertLedger = db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, is_system, status)
            VALUES (:cid, :gid, :code, :name, :type, 0, 'active')");
        $openings = [];
        foreach ($rows as $row) {
            if ((string) $row['level'] !== 'ledger' || (string) $row['status'] !== 'ready') {
                continue;
            }
            $parent = (string) $row['parent_code'];
            $groupId = (int) ($row['group_id'] ?? 0);
            if ($groupId <= 0) {
                $groupId = (int) ($resolved['code:' . mb_strtoupper($parent)]
                    ?? $resolved['name:' . mb_strtolower($parent)] ?? 0);
            }
            if ($groupId <= 0) {
                // Staging proved the group was reachable, so this only happens if
                // the chart changed under the batch between upload and commit.
                throw new RuntimeException('Row ' . $row['source_row_no'] . ': the group "' . $parent
                    . '" no longer exists. Upload the sheet again so it can be re-checked.');
            }
            $code = (string) $row['code'] !== '' ? (string) $row['code'] : coa_next_ledger_code($companyId, $groupId);
            $insertLedger->execute(['cid' => $companyId, 'gid' => $groupId, 'code' => $code,
                'name' => (string) $row['name'], 'type' => (string) $row['ledger_type']]);
            $ledgerId = (int) db()->lastInsertId();
            $ledgersMade++;
            $markRow->execute(['gid' => $groupId, 'lid' => $ledgerId, 'code' => $code, 'id' => (int) $row['id']]);

            $dr = round((float) $row['opening_dr'], 2);
            $cr = round((float) $row['opening_cr'], 2);
            if ($dr > 0.0 || $cr > 0.0) {
                $openings[] = ['ledger_id' => $ledgerId, 'amount' => $dr > 0.0 ? $dr : $cr,
                    'side' => $dr > 0.0 ? 'debit' : 'credit', 'row' => (int) $row['source_row_no']];
            }
        }

        // ---- Pass 3: openings ----------------------------------------------
        // Posted only once the accounts they belong to exist. Each one is a
        // journal against Opening Balance Adjustments; because the batch was
        // checked to balance, the adjustments ledger nets to zero across them.
        foreach ($openings as $opening) {
            $failure = post_ledger_opening_balance($companyId, $opening['ledger_id'],
                $opening['amount'], $opening['side'], $userId ?: null);
            if ($failure !== null) {
                throw new RuntimeException('Row ' . $opening['row'] . ': the opening balance could not be posted — ' . $failure);
            }
            $openingsPosted++;
        }

        db()->prepare("UPDATE coa_imports SET status = 'committed', committed_groups = :g, committed_ledgers = :l,
                committed_openings = :o, committed_at = NOW() WHERE id = :id")
            ->execute(['g' => $groupsMade, 'l' => $ledgersMade, 'o' => $openingsPosted, 'id' => $importId]);

        if ($ownsTransaction) {
            db()->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $e->getMessage(), 'groups' => 0, 'ledgers' => 0, 'openings' => 0];
    }

    return ['ok' => true, 'error' => null, 'groups' => $groupsMade,
        'ledgers' => $ledgersMade, 'openings' => $openingsPosted];
}
