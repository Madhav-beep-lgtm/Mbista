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
    foreach ($rows as $row) { fputcsv($h, $row); }
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

@unlink($xlsxPath);
coaimp_cleanup();

echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail > 0 ? 1 : 0);
