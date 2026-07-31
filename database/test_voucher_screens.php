<?php
declare(strict_types=1);

/**
 * Renders all eight voucher screens against a real company, then posts through
 * one of them for real.
 *
 * test_voucher_types.php proves the arithmetic of each type. This proves the
 * TEMPLATES — that a contra screen offers two bank accounts and no party, that
 * a purchase asks for the supplier's own bill number, that a debit note will
 * not open without a reason box — and that the numbering series actually
 * increments in the database. Any PHP notice, warning or deprecation is a
 * failure.
 *
 *   php database/test_voucher_screens.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require_once $root . '/app/accounting_module_repair.php';
require_once $root . '/app/voucher_types.php';
accounting_module_repair_database();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin/voucher-form.php';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = '127.0.0.1';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

// ---------------------------------------------------------------------------
// A company with a chart of accounts, two parties, and nothing else
// ---------------------------------------------------------------------------
function vsc_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'VCHSCR'")->fetchAll(PDO::FETCH_COLUMN) as $companyId) {
        $companyId = (int) $companyId;
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$companyId)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$companyId");
        db()->exec("DELETE FROM inventory_cost_layers WHERE company_id=$companyId");
        db()->exec("DELETE FROM inventory_transactions WHERE company_id=$companyId");
        db()->exec("DELETE FROM inventory_items WHERE company_id=$companyId");
        db()->exec("DELETE FROM accounting_parties WHERE company_id=$companyId");
        db()->exec("DELETE FROM company_ledger_mappings WHERE company_id=$companyId");
        db()->exec("DELETE FROM ledgers WHERE company_id=$companyId");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$companyId");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$companyId");
        db()->exec("DELETE FROM companies WHERE id=$companyId");
    }
}
vsc_cleanup();

db()->prepare('INSERT INTO companies (name, code, is_active) VALUES (:n, :c, 1)')
    ->execute(['n' => 'Voucher Screen Test Co', 'c' => 'VCHSCR']);
$cid = (int) db()->lastInsertId();
$fy = create_fiscal_year($cid, 'VCH 2026-27', '2026-07-16', '2027-07-15', true);
$fyId = (int) $fy['id'];
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fyId]);

$groupInsert = db()->prepare('INSERT INTO ledger_groups (company_id, code, name, master_key, is_cash_or_bank, is_system) VALUES (:cid,:code,:name,:mk,:cb,1)');
$groups = [];
foreach ([
    ['BANK', 'Bank', 'current_asset', 1],
    ['CASH_GRP', 'Cash in Hand', 'current_asset', 1],
    ['RECEIVABLE', 'Trade Receivables', 'current_asset', 0],
    ['PAYABLE', 'Trade Payables', 'current_liability', 0],
    ['DUTIES_TAXES', 'Duties and Taxes', 'current_liability', 0],
    ['DIRECT_INCOME_GRP', 'Sales / Service Income', 'direct_income', 0],
    ['ADMIN_EXP', 'Administrative Expenses', 'indirect_expense', 0],
] as [$code, $name, $master, $cashBank]) {
    $groupInsert->execute(['cid' => $cid, 'code' => $code, 'name' => $name, 'mk' => $master, 'cb' => $cashBank]);
    $groups[$code] = (int) db()->lastInsertId();
}

$ledgerInsert = db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, is_system, status) VALUES (:cid,:gid,:code,:name,:type,0,'active')");
$ledgers = [];
foreach ([
    ['CASH', 'Cash in hand', 'asset', 'CASH_GRP'],
    ['BANK1', 'Nabil Bank', 'asset', 'BANK'],
    ['VAT', 'VAT payable', 'liability', 'DUTIES_TAXES'],
    ['INC1', 'Consultancy income', 'revenue', 'DIRECT_INCOME_GRP'],
    ['EXP1', 'Office rent', 'expense', 'ADMIN_EXP'],
] as [$code, $name, $type, $group]) {
    $ledgerInsert->execute(['cid' => $cid, 'gid' => $groups[$group], 'code' => $code, 'name' => $name, 'type' => $type]);
    $ledgers[$code] = (int) db()->lastInsertId();
}

$partyInsert = db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:cid,:code,:name,:type,'active')");
$partyInsert->execute(['cid' => $cid, 'code' => 'C-001', 'name' => 'Altiora Pvt Ltd', 'type' => 'customer']);
$customerId = (int) db()->lastInsertId();
$partyInsert->execute(['cid' => $cid, 'code' => 'S-001', 'name' => 'Nepal Traders', 'type' => 'supplier']);
$supplierId = (int) db()->lastInsertId();

// One stock item, so the trade screens show their item column.
db()->prepare("INSERT INTO inventory_items (company_id, sku, name, item_type, valuation_method, unit, tax_rate, sales_rate, purchase_rate, opening_qty, opening_amount, status)
    VALUES (:cid, 'SKU-1', 'Ceiling fan', 'trading_good', 'fifo', 'pcs', 13, 4000, 2500, 0, 0, 'active')")->execute(['cid' => $cid]);
$stockItemId = (int) db()->lastInsertId();

$adminId = (int) db()->query("SELECT id FROM users WHERE role IN ('admin','super_admin') ORDER BY id LIMIT 1")->fetchColumn();
if ($adminId <= 0) {
    echo "No admin user in this database — cannot render admin pages.\n";
    vsc_cleanup();
    exit(1);
}
$_SESSION['user_id'] = $adminId;
set_context($cid, $fyId);
mark_company_pin_verified($cid);
set_selected_company($cid);

// ---------------------------------------------------------------------------
echo "1. Every type renders its own screen\n";
// ---------------------------------------------------------------------------
/** What each screen must have on it for it to be that screen and not another. */
$expectations = [
    'contra' => ['name="contra_from_ledger"', 'name="contra_to_ledger"', 'name="contra_amount"'],
    'payment' => ['name="tender_ledger[]"', 'name="line_ledger[]"', 'Paid from', 'Paid towards'],
    'receipt' => ['name="tender_ledger[]"', 'name="line_ledger[]"', 'Received into', 'Received against'],
    'journal' => ['name="ledger_id[]"', 'vch-dr', 'vch-cr'],
    'sales' => ['name="value_ledger[]"', 'name="tax_ledger_id"', 'name="settlement_mode"', 'Customer'],
    'purchase' => ['name="value_ledger[]"', 'Supplier bill no.', 'name="reference_date"'],
    'debit_note' => ['name="return_reason"', 'Against supplier bill no.'],
    'credit_note' => ['name="return_reason"', 'Against invoice no.'],
];
/** And what has no business being there. */
$forbidden = [
    'contra' => ['name="value_ledger[]"', 'name="tax_ledger_id"', 'name="party_id"'],
    'journal' => ['name="tender_ledger[]"', 'name="value_ledger[]"'],
    'payment' => ['name="value_ledger[]"', 'name="contra_amount"'],
    'sales' => ['name="tender_ledger[]"', 'name="contra_amount"'],
];

// The page is included into this scope, so it owns names like $type, $spec and
// $typeKey once it runs. Everything the loop needs afterwards is read back out
// of $vscRendered, which the page has no reason to touch.
$vscRendered = [];
$vscTypeList = array_keys(voucher_type_catalog());
for ($vscIndex = 0; $vscIndex < count($vscTypeList); $vscIndex++) {
    $_GET = ['type' => $vscTypeList[$vscIndex]];
    $_POST = [];
    ob_start();
    $vscError = null;
    try {
        include $root . '/public_html/admin/voucher-form.php';
    } catch (Throwable $exception) {
        $vscError = get_class($exception) . ': ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine();
    }
    $vscHtml = (string) ob_get_clean();
    $vscRendered[$vscTypeList[$vscIndex]] = $vscHtml;

    $vscProblems = [];
    if ($vscError !== null) { $vscProblems[] = $vscError; }
    foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $needle) {
        $position = stripos($vscHtml, $needle);
        if ($position !== false) { $vscProblems[] = trim(substr($vscHtml, $position, 200)); }
    }
    if (strlen($vscHtml) < 3000) { $vscProblems[] = 'suspiciously short output (' . strlen($vscHtml) . ' bytes)'; }
    ok($vscProblems === [], str_pad('voucher-form.php?type=' . $vscTypeList[$vscIndex], 40) . str_pad((string) strlen($vscHtml), 8, ' ', STR_PAD_LEFT) . ' bytes');
    foreach ($vscProblems as $problem) { echo '        ' . $problem . "\n"; }
}
$rendered = $vscRendered;

// ---------------------------------------------------------------------------
echo "\n2. Each screen asks the questions its own type needs\n";
// ---------------------------------------------------------------------------
foreach ($expectations as $typeKey => $needles) {
    foreach ($needles as $needle) {
        ok(str_contains($rendered[$typeKey], $needle), voucher_type_label($typeKey) . ' shows "' . $needle . '"');
    }
}
foreach ($forbidden as $typeKey => $needles) {
    foreach ($needles as $needle) {
        ok(!str_contains($rendered[$typeKey], $needle), voucher_type_label($typeKey) . ' has no business with "' . $needle . '"');
    }
}

// ---------------------------------------------------------------------------
echo "\n3. Dropdowns offer only what the slot can hold\n";
// ---------------------------------------------------------------------------
/** The <option value="…"> ids inside the first select of a given name. */
function vsc_options(string $html, string $selectName): array
{
    $start = strpos($html, 'name="' . $selectName . '"');
    if ($start === false) { return []; }
    $end = strpos($html, '</select>', $start);
    if ($end === false) { return []; }
    preg_match_all('~<option value="(\d+)"~', substr($html, $start, $end - $start), $matches);

    return array_map('intval', $matches[1]);
}

$contraOptions = vsc_options($rendered['contra'], 'contra_from_ledger');
ok($contraOptions !== [] && !in_array($ledgers['INC1'], $contraOptions, true) && !in_array($ledgers['EXP1'], $contraOptions, true),
    'A contra offers no income or expense ledger to move money out of');
ok(in_array($ledgers['CASH'], $contraOptions, true) && in_array($ledgers['BANK1'], $contraOptions, true),
    'But it does offer both real cash accounts');

$tenderOptions = vsc_options($rendered['payment'], 'tender_ledger[]');
ok(count($tenderOptions) === 2, 'A payment can only leave one of the two cash accounts');

$salesValueOptions = vsc_options($rendered['sales'], 'value_ledger[]');
ok(in_array($ledgers['INC1'], $salesValueOptions, true) && !in_array($ledgers['EXP1'], $salesValueOptions, true),
    'A sale credits income, not expenses');

$purchaseValueOptions = vsc_options($rendered['purchase'], 'value_ledger[]');
ok(in_array($ledgers['EXP1'], $purchaseValueOptions, true) && !in_array($ledgers['INC1'], $purchaseValueOptions, true),
    'A purchase debits expenses, not income');

$salesParties = vsc_options($rendered['sales'], 'party_id');
ok(in_array($customerId, $salesParties, true) && !in_array($supplierId, $salesParties, true),
    'The sales screen lists customers only');
$purchaseParties = vsc_options($rendered['purchase'], 'party_id');
ok(in_array($supplierId, $purchaseParties, true) && !in_array($customerId, $purchaseParties, true),
    'And the purchase screen lists suppliers only');

// One "vch-type-key" span per chip, and nowhere else on the page.
ok(substr_count($rendered['journal'], 'vch-type-key') === 8, 'The type bar offers all eight types from every screen');
ok(substr_count($rendered['journal'], 'Tally F7') === 1, 'And names the Tally key each one answers to');

// ---------------------------------------------------------------------------
echo "\n3b. Only the four types that move goods ask about them\n";
// ---------------------------------------------------------------------------
foreach (['sales', 'purchase', 'debit_note', 'credit_note'] as $goodsType) {
    ok(str_contains($rendered[$goodsType], 'name="value_item[]"'), voucher_type_label($goodsType) . ' offers a stock item on its lines');
}
foreach (['contra', 'payment', 'receipt', 'journal'] as $moneyType) {
    ok(!str_contains($rendered[$moneyType], 'name="value_item[]"'), voucher_type_label($moneyType) . ' does not, because it moves money and not goods');
}
$saleItems = vsc_options($rendered['sales'], 'value_item[]');
ok(in_array($stockItemId, $saleItems, true), 'The item on file is offered for sale');
ok(str_contains($rendered['purchase'], 'data-rate="2500.00"'), 'A purchase line offers the item at its purchase rate');
ok(str_contains($rendered['sales'], 'data-rate="4000.00"'), 'And a sales line at its selling rate');
ok(str_contains($rendered['payment'], 'PMT/VCH-2026-27/0001'), 'The screen shows the number the voucher is about to take');

// ---------------------------------------------------------------------------
echo "\n4. Posting through the engine numbers the series and balances the books\n";
// ---------------------------------------------------------------------------
// The customer's own ledger is created the first time they are billed, so it
// has to exist before the directory the composer reads is built — exactly the
// order voucher-form.php follows.
$customerLedgerId = ensure_party_ledger($cid, $customerId, 'receivable');
ok($customerLedgerId > 0, "A customer's receivable ledger is opened on first use");

$directory = voucher_ledger_directory($cid);
ok(isset($directory[$ledgers['BANK1']]['roles']['cash_bank']) && $directory[$ledgers['BANK1']]['roles']['cash_bank'],
    'The live directory tags the bank ledger from its group');
ok(!empty($directory[$ledgers['VAT']]['roles']['tax']), 'And finds the tax group by name on a fresh chart');

function vsc_post(int $cid, int $fyId, string $type, array $input, array $directory, int $adminId): array
{
    $composed = voucher_compose($type, $input, $directory);
    if ($composed['errors'] !== []) {
        return ['id' => 0, 'no' => '', 'errors' => $composed['errors']];
    }
    $voucherNo = voucher_next_number($cid, $fyId, $type);
    $id = create_voucher_with_entries([
        'company_id' => $cid, 'fiscal_year_id' => $fyId, 'voucher_no' => $voucherNo,
        'voucher_type' => $type, 'source_type' => 'voucher_form', 'source_id' => null,
        'voucher_date' => '2026-08-01', 'narration' => 'Screen test ' . $type,
        'total_amount' => $composed['total'], 'status' => 'posted', 'approval_state' => 'approved',
        'posted_by' => $adminId, 'posted_at' => date('Y-m-d H:i:s'),
    ], $composed['entries']);

    return ['id' => $id, 'no' => $voucherNo, 'errors' => []];
}

$first = vsc_post($cid, $fyId, 'payment', [
    'tender_ledger' => [$ledgers['BANK1']], 'tender_amount' => ['45000'], 'tender_mode' => ['cheque'],
    'line_ledger' => [$ledgers['EXP1']], 'line_amount' => ['45000'],
], $directory, $adminId);
ok($first['id'] > 0, 'A payment posts');
ok($first['no'] === 'PMT/VCH-2026-27/0001', 'And takes the first number in its own series (' . $first['no'] . ')');

$second = vsc_post($cid, $fyId, 'payment', [
    'tender_ledger' => [$ledgers['CASH']], 'tender_amount' => ['500'], 'tender_mode' => ['cash'],
    'line_ledger' => [$ledgers['EXP1']], 'line_amount' => ['500'],
], $directory, $adminId);
ok($second['no'] === 'PMT/VCH-2026-27/0002', 'The next payment takes the next number (' . $second['no'] . ')');

$sale = vsc_post($cid, $fyId, 'sales', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => $customerLedgerId,
    'value_ledger' => [$ledgers['INC1']], 'value_amount' => ['100000'],
    'tax_mode' => 'exclusive', 'tax_rate' => '13', 'tax_ledger_id' => $ledgers['VAT'],
    'reference_no' => 'INV-0143',
], $directory, $adminId);
ok($sale['id'] > 0 && $sale['no'] === 'SAL/VCH-2026-27/0001',
    'A sale numbers in its own series, not the payments one (' . ($sale['no'] !== '' ? $sale['no'] : implode('; ', $sale['errors'])) . ')');

$balanceStmt = db()->prepare("SELECT ROUND(SUM(CASE WHEN entry_type='debit' THEN amount ELSE -amount END), 2)
    FROM voucher_entries WHERE voucher_id = :id");
foreach ([$first['id'], $second['id'], $sale['id']] as $postedId) {
    $balanceStmt->execute(['id' => $postedId]);
    ok(abs((float) $balanceStmt->fetchColumn()) < 0.005, 'Voucher #' . $postedId . ' balances in the database');
}

$partyLedgerName = db()->query('SELECT l.name FROM voucher_entries ve INNER JOIN ledgers l ON l.id = ve.ledger_id
    WHERE ve.voucher_id = ' . (int) $sale['id'] . " AND ve.entry_type = 'debit' LIMIT 1")->fetchColumn();
ok((string) $partyLedgerName === 'Altiora Pvt Ltd', 'The sale was debited to the customer by name, not to a generic receivable');

// ---------------------------------------------------------------------------
echo "\n5. A posted voucher reopens on the screen that made it\n";
// ---------------------------------------------------------------------------
$vscEdits = [['payment', $first['id']], ['sales', $sale['id']]];
for ($vscIndex = 0; $vscIndex < count($vscEdits); $vscIndex++) {
    $_GET = ['edit' => $vscEdits[$vscIndex][1]];
    $_POST = [];
    ob_start();
    $vscError = null;
    try {
        include $root . '/public_html/admin/voucher-form.php';
    } catch (Throwable $exception) {
        $vscError = get_class($exception) . ': ' . $exception->getMessage();
    }
    $vscHtml = (string) ob_get_clean();
    $vscProblems = $vscError !== null ? [$vscError] : [];
    foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $needle) {
        $position = stripos($vscHtml, $needle);
        if ($position !== false) { $vscProblems[] = trim(substr($vscHtml, $position, 200)); }
    }
    $vscEditType = $vscEdits[$vscIndex][0];
    ok($vscProblems === [], 'Editing the ' . $vscEditType . ' renders cleanly');
    foreach ($vscProblems as $problem) { echo '        ' . $problem . "\n"; }
    ok(str_contains($vscHtml, $expectations[$vscEditType][0]), 'And opens on the ' . $vscEditType . ' screen, not a generic grid');
    ok(!str_contains($vscHtml, 'class="vch-typebar"'), 'The type bar is gone — a numbered voucher cannot change type');
}

// ---------------------------------------------------------------------------
vsc_cleanup();
echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
