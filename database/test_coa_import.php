<?php
declare(strict_types=1);

/**
 * Chart of accounts spreadsheet import — staging, validation and commit.
 *
 * Builds a throw-away company, writes a REAL .xlsx with the app's own writer,
 * reads it back through the shared reader, and proves the three things that
 * make a bulk import safe to hand to someone:
 *
 *   - uploading changes nothing until commit,
 *   - every row that will not import says why, against its own row number,
 *   - openings only post when the sheet balances.
 *
 *   php database/test_coa_import.php
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/export_engine.php';
require_once __DIR__ . '/../app/coa_import.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.005; }

function coaimp_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'COAIMP'")->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $cid = (int) $cid;
        db()->exec("DELETE ve FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id WHERE v.company_id = $cid");
        db()->exec("DELETE FROM vouchers WHERE company_id = $cid");
        foreach (['coa_import_rows', 'coa_imports', 'company_ledger_mappings', 'fiscal_period_locks', 'fiscal_years'] as $t) {
            if (table_exists($t)) { db()->exec("DELETE FROM `$t` WHERE company_id = $cid"); }
        }
        db()->exec("DELETE FROM ledgers WHERE company_id = $cid");
        db()->exec("DELETE FROM ledger_groups WHERE company_id = $cid");
        db()->exec("DELETE FROM companies WHERE id = $cid");
    }
}

/** Write rows as a real .xlsx into a temp file and return its path. */
function coaimp_xlsx(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'coa') . '.xlsx';
    file_put_contents($path, xlsx_build($rows, 'Chart'));

    return $path;
}

function coaimp_csv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'coa') . '.csv';
    $h = fopen($path, 'w');
    foreach ($rows as $row) { fputcsv($h, $row, ',', '"', '\\'); }
    fclose($h);

    return $path;
}

/** Net debit-positive movement on a ledger, straight from the postings. */
function coaimp_ledger_net(int $companyId, int $ledgerId): float
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN ve.entry_type = 'debit' THEN ve.amount ELSE -ve.amount END), 0)
        FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id
        WHERE v.company_id = :cid AND ve.ledger_id = :lid");
    $stmt->execute(['cid' => $companyId, 'lid' => $ledgerId]);

    return round((float) $stmt->fetchColumn(), 2);
}

function coaimp_ledger_id(int $companyId, string $name): int
{
    $stmt = db()->prepare('SELECT id FROM ledgers WHERE company_id = :cid AND name = :n LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'n' => $name]);

    return (int) ($stmt->fetchColumn() ?: 0);
}

coaimp_cleanup();

echo "Setting up fixture...\n";
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('COA Import Test', 'COAIMP', 1)")->execute();
$cid = (int) db()->lastInsertId();
$fy = create_fiscal_year($cid, 'FY 2026/27', '2026-07-16', '2027-07-15', true);
$fyId = (int) ($fy['id'] ?? 0);
ok($fy['ok'] && $fyId > 0, 'Fixture company and default fiscal year created');

// One group and one ledger already on file, so the "already exists" path is
// exercised against real data rather than a row the same sheet just made.
db()->prepare("INSERT INTO ledger_groups (company_id, master_key, code, name) VALUES (:cid, 'current_asset', 'EXIST-G', 'Existing Assets')")
    ->execute(['cid' => $cid]);
db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status)
    VALUES (:cid, (SELECT id FROM ledger_groups WHERE company_id = :cid2 AND code = 'EXIST-G'), 'EXIST-L', 'Existing Cash', 'asset', 'active')")
    ->execute(['cid' => $cid, 'cid2' => $cid]);

$header = COA_IMPORT_COLUMNS;

echo "\n1. A sheet is judged row by row and NOTHING is created\n";
$sheet = [
    $header,
    // A master row, as an exported chart carries — reported, not treated as a fault.
    ['Master', 'current_asset', 'Current Assets', 'current_asset', 'asset', '', '', '', 'Active'],
    ['Group', '', 'Current Assets', 'current_asset', '', '', '', '', ''],
    ['Ledger', '', 'Cash in Hand', '', 'asset', 'Current Assets', '150,000.00', '', ''],
    ['Ledger', '', 'Bank Current A/c', '', 'asset', 'Current Assets', '45000', '', ''],
    // Names a group defined BELOW it — must still resolve.
    ['Ledger', '', 'Proprietor Capital', '', 'equity', 'Capital Account', '', '195000.00', ''],
    ['Group', '', 'Capital Account', 'equity', '', '', '', '', ''],
    ['Group', '', 'Indirect Expenses', 'indirect_expense', '', '', '', '', ''],
    ['Ledger', '', 'Office Rent', '', 'expense', 'Indirect Expenses', '', '', ''],
    // --- rows that must be refused, each for its own reason ---
    ['Ledger', '', 'Ghost Account', '', 'asset', 'No Such Group', '', '', ''],
    ['Ledger', '', 'Bad Type', '', 'expence', 'Current Assets', '', '', ''],
    ['Ledger', '', 'Wrong Nature', '', 'revenue', 'Current Assets', '', '', ''],
    ['Ledger', '', 'Rent With Opening', '', 'expense', 'Indirect Expenses', '5000', '', ''],
    ['Ledger', '', 'Two Sided', '', 'asset', 'Current Assets', '10', '10', ''],
    ['Ledger', '', 'Not A Number', '', 'asset', 'Current Assets', 'abc', '', ''],
    ['Ledger', 'EXIST-L', 'Existing Cash Again', '', 'asset', 'Current Assets', '', '', ''],
    ['Group', '', 'No Master Here', '', '', '', '', '', ''],
    ['Sideways', '', 'Unknown Level', '', '', '', '', '', ''],
    ['Ledger', '', '', '', 'asset', 'Current Assets', '', '', ''],
];

$groupsBefore = (int) db()->query("SELECT COUNT(*) FROM ledger_groups WHERE company_id = $cid")->fetchColumn();
$ledgersBefore = (int) db()->query("SELECT COUNT(*) FROM ledgers WHERE company_id = $cid")->fetchColumn();

$xlsxPath = coaimp_xlsx($sheet);
$importId = coa_import_stage($cid, $fyId, 0, $xlsxPath, 'chart.xlsx');
ok($importId > 0, 'A real .xlsx written by the app is read back and staged');

ok((int) db()->query("SELECT COUNT(*) FROM ledger_groups WHERE company_id = $cid")->fetchColumn() === $groupsBefore
    && (int) db()->query("SELECT COUNT(*) FROM ledgers WHERE company_id = $cid")->fetchColumn() === $ledgersBefore,
    'Uploading created no group and no ledger — staging only');

$batch = coa_import_batch($cid, $importId);
$rows = coa_import_rows($cid, $importId);
$byName = [];
$byRow = [];
foreach ($rows as $r) {
    // "Current Assets" is both a master row and a group row, so the name index
    // alone cannot address either of them — the file's row number can.
    $byName[(string) $r['raw_name']] = $r;
    $byRow[(int) $r['source_row_no']] = $r;
}

ok((int) $batch['ready_count'] === 7, 'Seven rows are ready: 3 groups + 4 ledgers (got ' . $batch['ready_count'] . ')');
ok((int) $batch['skipped_count'] === 2, 'Two rows skipped: the master row and the existing ledger code (got ' . $batch['skipped_count'] . ')');
ok((int) $batch['error_count'] === 9, 'Nine rows refused (got ' . $batch['error_count'] . ')');
ok((int) $batch['row_count'] === 18, 'Every data row is staged and the header is not (got ' . $batch['row_count'] . ')');

echo "\n2. Every refusal names its own reason\n";
ok((string) $byName['Ghost Account']['status'] === 'error'
    && str_contains((string) $byName['Ghost Account']['error_text'], 'was not found'),
    'A ledger naming a group that does not exist is refused by name');
ok(str_contains((string) $byName['Bad Type']['error_text'], 'not one of'), 'A misspelt type is refused and the valid ones listed');
ok(str_contains((string) $byName['Wrong Nature']['error_text'], 'same nature as the group'),
    'A revenue ledger filed under an asset group is refused — reports and postings would disagree');
ok(str_contains((string) $byName['Rent With Opening']['error_text'], 'open at zero'),
    'An expense account cannot carry an opening balance');
ok(str_contains((string) $byName['Two Sided']['error_text'], 'one side'), 'A row with both Dr and Cr is refused');
ok(str_contains((string) $byName['Not A Number']['error_text'], 'not a number'), 'A non-numeric opening is refused');
ok(str_contains((string) $byName['No Master Here']['error_text'], 'needs a Master'), 'A group with no master is refused');
ok(str_contains((string) $byName['Unknown Level']['error_text'], 'not understood'), 'An unknown Level is refused');
ok((string) $byName['Existing Cash Again']['status'] === 'skipped'
    && str_contains((string) $byName['Existing Cash Again']['error_text'], 'already exists'),
    'An account whose code is already on file is skipped and said so, not rewritten');
ok((string) $byRow[2]['status'] === 'skipped'
    && str_contains((string) $byRow[2]['error_text'], 'built into the system'),
    'The master rows an exported chart carries are reported, not treated as faults');
ok((int) $byName['Ghost Account']['source_row_no'] === 10,
    'A refusal carries the row number of the FILE (got ' . $byName['Ghost Account']['source_row_no'] . ')');

echo "\n3. The openings must balance before anything posts\n";
ok(near((float) $batch['opening_dr_total'], 195000.00), 'Debits total 195,000 — the thousands separator was read, the refused rows were not counted');
ok(near((float) $batch['opening_cr_total'], 195000.00), 'Credits total 195,000');
ok(coa_import_balance_error($batch) === null, 'A balanced sheet passes the balance test');

$unbalanced = [$header,
    ['Group', '', 'Odd Assets', 'current_asset', '', '', '', '', ''],
    ['Ledger', '', 'Odd Cash', '', 'asset', 'Odd Assets', '5000', '', ''],
];
$badId = coa_import_stage($cid, $fyId, 0, coaimp_csv($unbalanced), 'odd.csv');
$badBatch = coa_import_batch($cid, $badId);
ok(coa_import_balance_error($badBatch) !== null, 'An unbalanced sheet fails the balance test');
$badCommit = coa_import_commit($cid, $badId, 0);
ok(!$badCommit['ok'] && str_contains((string) $badCommit['error'], 'do not balance'),
    'Committing an unbalanced sheet is refused, naming both totals');
ok(coaimp_ledger_id($cid, 'Odd Cash') === 0, 'And it created nothing');
coa_import_discard($cid, $badId);

echo "\n4. Commit creates the chart and posts the openings\n";
$result = coa_import_commit($cid, $importId, 0);
ok($result['ok'], 'The balanced sheet commits: ' . (string) $result['error']);
ok($result['groups'] === 3, 'Three groups created (got ' . $result['groups'] . ')');
ok($result['ledgers'] === 4, 'Four ledgers created (got ' . $result['ledgers'] . ')');
ok($result['openings'] === 3, 'Three openings posted (got ' . $result['openings'] . ')');

$capitalId = coaimp_ledger_id($cid, 'Proprietor Capital');
$capitalGroup = (int) db()->query("SELECT g.id FROM ledger_groups g WHERE g.company_id = $cid AND g.name = 'Capital Account'")->fetchColumn();
ok($capitalId > 0, 'A ledger naming a group defined further down the sheet was still created');
ok((int) db()->query("SELECT group_id FROM ledgers WHERE id = $capitalId")->fetchColumn() === $capitalGroup,
    'And it landed in that group, because commit creates every group before any ledger');

ok(near(coaimp_ledger_net($cid, coaimp_ledger_id($cid, 'Cash in Hand')), 150000.00), 'Cash opens 150,000 debit');
ok(near(coaimp_ledger_net($cid, coaimp_ledger_id($cid, 'Bank Current A/c')), 45000.00), 'Bank opens 45,000 debit');
ok(near(coaimp_ledger_net($cid, $capitalId), -195000.00), 'Capital opens 195,000 credit');
ok(near(coaimp_ledger_net($cid, opening_balance_ledger_id($cid)), 0.00),
    'Opening Balance Adjustments nets to zero — a balanced sheet leaves nothing stranded in the contra');

$rentId = coaimp_ledger_id($cid, 'Office Rent');
ok($rentId > 0 && near(coaimp_ledger_net($cid, $rentId), 0.00), 'An expense ledger is created and opens at zero');
ok((string) db()->query("SELECT code FROM ledgers WHERE id = $rentId")->fetchColumn() !== '',
    'A row that left the Code column blank was given a generated code');

ok(coaimp_ledger_id($cid, 'Ghost Account') === 0 && coaimp_ledger_id($cid, 'Wrong Nature') === 0,
    'None of the refused rows reached the chart');
ok((int) db()->query("SELECT COUNT(*) FROM ledgers WHERE company_id = $cid AND name = 'Existing Cash'")->fetchColumn() === 1,
    'The account that already existed is still there exactly once, untouched');

echo "\n5. A committed batch is finished with\n";
$again = coa_import_commit($cid, $importId, 0);
ok(!$again['ok'] && str_contains((string) $again['error'], 'already been committed'),
    'Committing the same upload twice is refused');
$after = coa_import_batch($cid, $importId);
ok((string) $after['status'] === 'committed' && (int) $after['committed_ledgers'] === 4
    && (int) $after['committed_groups'] === 3 && (int) $after['committed_openings'] === 3,
    'The batch records what it created');
ok(coa_import_latest_staged($cid) === null, 'Nothing is left waiting for a decision');

echo "\n6. The same sheet uploaded twice adds nothing the second time\n";
$secondId = coa_import_stage($cid, $fyId, 0, coaimp_xlsx($sheet), 'chart.xlsx');
$secondBatch = coa_import_batch($cid, $secondId);
ok((int) $secondBatch['ready_count'] === 0,
    'Re-uploading a chart already imported leaves nothing to create (got ' . $secondBatch['ready_count'] . ')');
$secondCommit = coa_import_commit($cid, $secondId, 0);
ok(!$secondCommit['ok'] && str_contains((string) $secondCommit['error'], 'nothing to import'),
    'And committing it is refused rather than silently doing nothing');
coa_import_discard($cid, $secondId);

echo "\n7. A wrong row is fixed in the preview, not in Excel\n";
$editSheet = [$header,
    ['Group', '', 'Fixtures', 'non_current_asset', '', '', '', '', ''],
    ['Ledger', '', 'Display Case', '', 'asset', 'Fixtures', '20000', '', ''],
    ['Ledger', '', 'Showroom Lamp', '', 'asset', 'Lighting', '', '', ''],
    ['Ledger', '', 'Capital Top-up', '', 'equity', 'Capital Account', '', '20000', ''],
];
$editId = coa_import_stage($cid, $fyId, 0, coaimp_xlsx($editSheet), 'edit.xlsx');
$editRowsById = [];
foreach (coa_import_rows($cid, $editId) as $r) { $editRowsById[(string) $r['raw_name']] = (int) $r['id']; }
$statusOf = static function (int $cid, int $importId, string $name): string {
    foreach (coa_import_rows($cid, $importId) as $r) {
        if ((string) $r['raw_name'] === $name) { return (string) $r['status']; }
    }
    return 'gone';
};

ok($statusOf($cid, $editId, 'Showroom Lamp') === 'error', 'The row naming a group that does not exist starts rejected');
ok((int) coa_import_batch($cid, $editId)['ready_count'] === 3, 'Three of the four rows are ready');

$fix = coa_import_update_row($cid, $editRowsById['Showroom Lamp'], ['group_code' => 'Fixtures']);
ok($fix['ok'] && $fix['status'] === 'ready', 'Correcting the group in the preview turns that row green');
ok((int) coa_import_batch($cid, $editId)['ready_count'] === 4, 'And the batch count follows the correction');

// The reason an edit re-judges the whole batch rather than the row alone.
coa_import_update_row($cid, $editRowsById['Fixtures'], ['master' => 'not_a_master']);
ok($statusOf($cid, $editId, 'Fixtures') === 'error', 'Breaking the group row rejects it');
ok($statusOf($cid, $editId, 'Display Case') === 'error',
    'And the ledgers that named it are re-judged too — a row\'s verdict is not its own business');
coa_import_update_row($cid, $editRowsById['Fixtures'], ['master' => 'non_current_asset']);
ok((int) coa_import_batch($cid, $editId)['ready_count'] === 4, 'Putting the group back makes them all valid again');

ok(coa_import_balance_error(coa_import_batch($cid, $editId)) === null, 'The batch balances, 20,000 against 20,000');
coa_import_update_row($cid, $editRowsById['Display Case'], ['opening_dr' => '25000']);
ok(coa_import_balance_error(coa_import_batch($cid, $editId)) !== null,
    'Editing an amount re-tests the balance, which now fails');
coa_import_update_row($cid, $editRowsById['Display Case'], ['opening_dr' => '20,000.00']);
ok(coa_import_balance_error(coa_import_batch($cid, $editId)) === null, 'And correcting it clears the failure');

coa_import_update_row($cid, $editRowsById['Capital Top-up'], ['level' => '', 'name' => '', 'code' => '']);
ok($statusOf($cid, $editId, '') === 'error',
    'A row edited down to nothing says so rather than vanishing from the preview');

$removed = coa_import_delete_row($cid, $editRowsById['Showroom Lamp']);
$afterDelete = coa_import_batch($cid, $editId);
ok($removed['ok'] && (int) $afterDelete['row_count'] === 3, 'Removing a row drops it from the counts');
ok(count(coa_import_rows($cid, $editId)) === 3 && $statusOf($cid, $editId, 'Showroom Lamp') === 'gone',
    'And from the preview');

$committedRows = coa_import_rows($cid, $importId);
$lockedEdit = coa_import_update_row($cid, (int) $committedRows[0]['id'], ['name' => 'Nope']);
ok(!$lockedEdit['ok'] && str_contains((string) $lockedEdit['error'], 'already been committed'),
    'Rows of an upload that has been committed can no longer be edited');
ok(!coa_import_delete_row($cid, (int) $committedRows[0]['id'])['ok'], 'Nor removed');
coa_import_discard($cid, $editId);

echo "\n8. One spreadsheet reader, shared by both importers\n";
// The .xlsx parser was lifted out of voucher_import.php so this importer and the
// voucher one read a workbook the same way. Nothing else covers that move, and a
// drift between the two would surface as a parsing bug on one screen only —
// the kind that gets blamed on the file rather than the code.
require_once __DIR__ . '/../app/voucher_import.php';
$readerRows = [
    ['Date', 'Voucher No', 'Ledger', 'Debit', 'Credit', 'Narration'],
    ['2026-08-01', 'JV-001', 'Cash', '1500.50', '', 'Opening float for the counter'],
    ['2026-08-02', 'JV-002', 'Bank', '', '1500.50', ''],
    ['2026-08-03', 'JV-003'],
];
$readerPath = coaimp_xlsx($readerRows);
$viaShared = spreadsheet_read_xlsx($readerPath, 5000);
ok(voucher_import_read_xlsx($readerPath) === $viaShared,
    'The voucher importer reads a workbook through the same parser, cell for cell');
ok(count($viaShared) === 4 && $viaShared[0]['cells'][0] === 'Date'
    && $viaShared[1]['cells'][3] === '1500.50',
    'Text and numbers come back as the sheet wrote them');
ok(($viaShared[1]['cells'][5] ?? '') === 'Opening float for the counter'
    && ($viaShared[2]['cells'][4] ?? '') === '1500.50',
    'A blank cell mid-row does not shift the columns after it');
ok(array_column($viaShared, 'n') === [1, 2, 3, 4], 'Each row carries its own row number from the file');

$readerCsv = coaimp_csv($readerRows);
ok(spreadsheet_read_rows($readerCsv, 'x.csv', 5000)[0]['cells'][0] === 'Date',
    'The csv path returns the same shape as the xlsx path');
$xlsGuard = false;
try {
    spreadsheet_read_rows($readerCsv, 'legacy.xls', 5000);
} catch (Throwable $e) {
    $xlsGuard = str_contains($e->getMessage(), '.xls');
}
ok($xlsGuard, 'A legacy .xls is refused with an explanation, not a parse error');
@unlink($readerPath);
@unlink($readerCsv);

@unlink($xlsxPath);
coaimp_cleanup();

echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail > 0 ? 1 : 0);
