<?php
declare(strict_types=1);

/**
 * The two-sheet hospitality sales upload: template round trip, both parsers,
 * the cross-sheet reconciliation that makes the arrangement safe, posting
 * (debits from the invoice sheet, credits from the item sheet), menu items
 * built from what was sold, and the shape guards that keep a month's sheet
 * from turning into a query per row.
 *   php database/test_hospitality_sales_workbook.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/hospitality_engine.php';
require_once __DIR__ . '/../app/hospitality_sales_posting.php';
require_once __DIR__ . '/../app/hospitality_sales_workbook.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.011; }
function questions(): int { return (int) db()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value']; }

function hwb_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('HWBK1','HWBK2')")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE e FROM voucher_entries e JOIN vouchers v ON v.id = e.voucher_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        // Recipe lines hang off recipes and carry no company of their own.
        if (table_exists('hospitality_recipe_lines')) {
            db()->exec("DELETE l FROM hospitality_recipe_lines l JOIN hospitality_recipes r ON r.id = l.recipe_id WHERE r.company_id=$s");
        }
        foreach (['hospitality_sales_invoice_lines', 'hospitality_sales_upload_lines', 'hospitality_sales_uploads',
                  'hospitality_sales_ledger_maps', 'hospitality_sales_mappings',
                  'hospitality_recipes', 'hospitality_menu_items', 'hospitality_ingredients', 'hospitality_settings'] as $t) {
            if (table_exists($t) && column_exists($t, 'company_id')) { db()->exec("DELETE FROM `$t` WHERE company_id=$s"); }
        }
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email IN ('hwbk1@test.local','hwbk2@test.local')")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
hwb_cleanup();

/**
 * A hospitality company with ledgers, settings and category maps.
 *
 * $prefix is what goes in front of the ledger codes. The first company uses
 * none, so its codes are the ones the shipped template names (1100, 1200) and
 * the template can be posted straight into it; the second uses a prefix so the
 * isolation check is asking a real question.
 */
function hwb_company(string $code, string $email, string $name, string $prefix = ''): array
{
    db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n, :c, 1, 1)')
        ->execute(['n' => $name, 'c' => $code]);
    $cid = (int) db()->lastInsertId();
    $uid = create_user(['name' => $name . ' Owner', 'email' => $email, 'password' => 'Secret#12345', 'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
    db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, hospitality_accounting_enabled)
            VALUES (:uid, :cid, :books, :org, :code, 1, 1)')
        ->execute(['uid' => $uid, 'cid' => $cid, 'books' => $cid, 'org' => $name, 'code' => $code . '-C']);
    $fy = create_fiscal_year($cid, $code . ' 2026/27', '2026-04-01', '2027-03-31', true);
    db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);

    $mk = static function (string $lcode, string $lname, string $type) use ($cid, $prefix): int {
        db()->prepare("INSERT INTO ledgers (company_id, code, name, type, status) VALUES (:cid,:code,:name,:type,'active')")
            ->execute(['cid' => $cid, 'code' => $prefix . $lcode, 'name' => $lname, 'type' => $type]);
        return (int) db()->lastInsertId();
    };
    $l = [
        'food' => $mk('4001', 'Sales — Food', 'revenue'),
        'bev' => $mk('4002', 'Sales — Beverage', 'revenue'),
        'bar' => $mk('4003', 'Sales — Bar', 'revenue'),
        'vat' => $mk('2101', 'VAT Payable', 'liability'),
        'cash' => $mk('1100', 'Cash in Hand', 'asset'),
        'debt' => $mk('1200', 'Sundry Debtors', 'asset'),
        'recv' => $mk('1300', 'Sales Receivable', 'asset'),
        'disc' => $mk('5101', 'Discount Allowed', 'expense'),
    ];
    db()->prepare('INSERT INTO hospitality_settings (company_id, post_sales_ledger_id, post_vat_ledger_id, post_discount_ledger_id, post_receivable_ledger_id, post_vat_rate, post_amount_includes_vat)
        VALUES (:cid,:s,:v,:d,:r,13.00,0)')->execute(['cid' => $cid, 's' => $l['food'], 'v' => $l['vat'], 'd' => $l['disc'], 'r' => $l['recv']]);
    foreach ([['Food', $l['food']], ['Beverage', $l['bev']], ['Bar', $l['bar']]] as [$cat, $lid]) {
        db()->prepare("INSERT INTO hospitality_sales_ledger_maps (company_id, map_type, display_value, match_value, sales_ledger_id, receivable_ledger_id, discount_ledger_id, active)
            VALUES (:cid,'category',:d,:m,:l,:r,:dd,1)")
            ->execute(['cid' => $cid, 'd' => $cat, 'm' => hospitality_sales_norm($cat), 'l' => $lid, 'r' => $l['recv'], 'dd' => $l['disc']]);
    }

    return ['cid' => $cid, 'uid' => $uid, 'fy' => (int) $fy['id'], 'ledgers' => $l, 'code' => $code, 'prefix' => $prefix];
}

$A = hwb_company('HWBK1', 'hwbk1@test.local', 'Workbook Cafe One');
$B = hwb_company('HWBK2', 'hwbk2@test.local', 'Workbook Cafe Two', 'X');
$_SESSION['company_id'] = $A['cid'];
$_SESSION['user_id'] = (int) db()->query("SELECT id FROM users WHERE role IN ('admin','super_admin') ORDER BY id LIMIT 1")->fetchColumn();
set_context($A['cid'], $A['fy']);

echo "\n== Template ==\n";
$sheets = hospitality_workbook_template_sheets();
ok(count($sheets) === 2, 'The template carries two sheets');
ok(isset($sheets[HOSPITALITY_SHEET_ITEMS], $sheets[HOSPITALITY_SHEET_INVOICES]), '  ...one item-wise, one invoice-wise');

$tmp = tempnam(sys_get_temp_dir(), 'hwb') . '.xlsx';
file_put_contents($tmp, hospitality_workbook_template_xlsx());
$readBack = spreadsheet_read_xlsx_all($tmp);
ok(count($readBack) === 2, 'The template workbook reads back as two sheets');
ok(array_keys($readBack) === [HOSPITALITY_SHEET_ITEMS, HOSPITALITY_SHEET_INVOICES], '  ...with the names it was written with');

echo "\n== Header detection ==\n";
ok(hospitality_sheet_is_items($sheets[HOSPITALITY_SHEET_ITEMS][0]), 'The item sheet header is recognised');
ok(hospitality_sheet_is_invoices($sheets[HOSPITALITY_SHEET_INVOICES][0]), 'The invoice sheet header is recognised');
ok(!hospitality_sheet_is_invoices($sheets[HOSPITALITY_SHEET_ITEMS][0]), 'An item header is not mistaken for an invoice header');
$itemMap = hospitality_item_sheet_headers($sheets[HOSPITALITY_SHEET_ITEMS][0]);
ok(($itemMap['amount'] ?? -1) === 4, '"Total Sales Amount (without VAT)" maps to the amount column, not a shorter alias');
ok(($itemMap['taxable'] ?? -1) === 6 && ($itemMap['total'] ?? -1) === 8, '  ...and Taxable Sales and Sales with VAT map to their own columns');

echo "\n== Parsing the worked example ==\n";
$settings = hospitality_settings($A['cid']);
$parsed = hospitality_workbook_parse($tmp, 'xlsx', null, null, $A['cid'], $A['fy'], $settings);
ok(!isset($parsed['error']), 'The template parses without a fatal error' . (isset($parsed['error']) ? ': ' . $parsed['error'] : ''));
ok(count($parsed['items']['rows']) === 5 && $parsed['items']['errors'] === 0, 'Five item rows, none in error');
ok(count($parsed['invoices']['rows']) === 2 && $parsed['invoices']['errors'] === 0, 'Two invoice rows, none in error');
$it = $parsed['items']['totals'];
ok(near((float) $it['amount'], 36850.00), 'Item sheet totals Sales Amount 36,850.00');
ok(near((float) $it['discount'], 200.00), '  ...Discount 200.00');
ok(near((float) $it['taxable'], 36650.00), '  ...Taxable Sales 36,650.00');
ok(near((float) $it['vat'], 4764.50), '  ...VAT 4,764.50');
ok(near((float) $it['total'], 41414.50), '  ...Sales with VAT 41,414.50');
ok($parsed['reconciliation']['ok'] === true, 'The two sheets reconcile');
ok((string) $parsed['items']['rows'][0]['date'] === '2026-07-08', 'A BS date on the sheet is stored as its AD equivalent');
ok((string) $parsed['invoices']['rows'][0]['ledger_name'] === 'Cash in Hand', 'A ledger CODE on the sheet resolves to the ledger NAME');

echo "\n== Reconciliation refuses a bad pair ==\n";
$IH = ['Date', 'Category', 'Item', 'Qty', 'Total Sales Amount (without VAT)', 'Discount', 'Taxable Sales', 'VAT', 'Sales with VAT'];
$VH = ['Date', 'Invoice No', 'Payment Type', 'Party Ledger Code', 'Sales Amount', 'Less: Discount', 'Taxable Sales', 'VAT', 'Sales with VAT'];
$wrap = static function (array $rows): array {
    $out = [];
    foreach ($rows as $i => $cells) { $out[] = ['n' => $i + 1, 'cells' => array_map('strval', $cells)]; }
    return $out;
};
$ctx = static function (int $cid, int $fy, array $settings): array {
    $f = fiscal_year_by_id($fy);
    return [
        'fy_start' => (string) $f['start_date'], 'fy_end' => (string) $f['end_date'],
        'maps' => hospitality_sales_ledger_maps($cid),
        'default_ledger' => hospitality_posting_ledger($cid, (int) $settings['post_sales_ledger_id']),
        'vat_ledger' => hospitality_posting_ledger($cid, (int) $settings['post_vat_ledger_id']),
        'discount_ledger' => hospitality_posting_ledger($cid, (int) $settings['post_discount_ledger_id']),
        'receivable_ledger' => hospitality_posting_ledger($cid, (int) $settings['post_receivable_ledger_id']),
        'ledger_lookup' => hospitality_workbook_ledger_lookup($cid),
        'locked_through' => null,
    ];
};
$context = $ctx($A['cid'], $A['fy'], $settings);
$pairOf = static function (array $i, array $v) use ($wrap, $A, $settings, $context): array {
    $items = hospitality_workbook_parse_items($wrap($i), $A['cid'], $A['fy'], $settings, $context);
    $invoices = hospitality_workbook_parse_invoices($wrap($v), $A['cid'], $A['fy'], $settings, $context);
    return ['items' => $items, 'invoices' => $invoices, 'recon' => hospitality_workbook_reconcile($items, $invoices)];
};
$goodI = [$IH, ['2026-07-08', 'Food', 'Momo', 10, '1000', '0', '1000', '130', '1130']];
$goodV = [$VH, ['2026-07-08', 'INV-1', 'Cash', '1100', '1000', '0', '1000', '130', '1130']];

ok($pairOf($goodI, $goodV)['recon']['ok'] === true, 'A matched pair reconciles');
$r = $pairOf($goodI, [$VH, ['2026-07-08', 'INV-1', 'Cash', '1100', '900', '0', '900', '117', '1017']]);
ok($r['recon']['ok'] === false, 'A pair whose money differs is refused');
ok(count(array_filter($r['recon']['problems'], static fn ($p) => str_contains($p, 'Sales with VAT'))) > 0, '  ...naming the column that differs');
$r = $pairOf($goodI, [$VH, ['2026-07-09', 'INV-1', 'Cash', '1100', '1000', '0', '1000', '130', '1130']]);
ok($r['recon']['ok'] === false, 'A pair covering different days is refused');
$r = $pairOf(
    [$IH, ['2026-07-08', 'Food', 'Momo', 10, '1000', '0', '1000', '130', '1130'], ['2026-07-10', 'Food', 'Momo', 10, '1000', '0', '1000', '130', '1130']],
    [$VH, ['2026-07-08', 'INV-1', 'Cash', '1100', '1000', '0', '1000', '130', '1130'], ['2026-07-09', 'INV-2', 'Cash', '1100', '1000', '0', '1000', '130', '1130']]
);
ok($r['recon']['ok'] === false, 'A pair with equal totals but different days is refused (the dangerous case)');

echo "\n== Row-level checks ==\n";
$r = $pairOf([$IH, ['2026-07-08', 'Food', 'Momo', 10, '1000', '0', '950', '130', '1080']], $goodV);
ok(count(array_filter($r['items']['rows'][0]['errors'], static fn ($e) => str_contains($e, 'Taxable Sales'))) > 0, 'Taxable that is not amount less discount is caught');
$r = $pairOf([$IH, ['2026-07-08', 'Food', 'Momo', 10, '1000', '0', '1000', '130', '1200']], $goodV);
ok(count(array_filter($r['items']['rows'][0]['errors'], static fn ($e) => str_contains($e, 'Sales with VAT'))) > 0, 'A total that is not taxable plus VAT is caught');
$r = $pairOf($goodI, [$VH, ['2026-07-08', 'INV-1', 'Cash', 'NO-SUCH-CODE', '1000', '0', '1000', '130', '1130']]);
ok(count(array_filter($r['invoices']['rows'][0]['errors'], static fn ($e) => str_contains($e, 'NO-SUCH-CODE'))) > 0, 'An unknown ledger code is an error naming the code');
$r = $pairOf([$IH, ['2026-07-08', 'Food', 'Momo', 10, '1000', '0', '1000', '130', '1130'], ['Total', '', '', '', '1000', '0', '1000', '130', '1130']], $goodV);
ok(count($r['items']['rows']) === 1, "A sheet's own Total line is skipped, not read as a sale");
$short = ['Date', 'Category', 'Item', 'Qty', 'Total Sales Amount', 'Discount', 'VAT'];
$shortV = ['Date', 'Invoice No', 'Payment Type', 'Party Ledger Code', 'Sales Amount', 'Less: Discount', 'VAT'];
$r = $pairOf([$short, ['2026-07-08', 'Food', 'Momo', 10, '1000', '0', '130']], [$shortV, ['2026-07-08', 'INV-1', 'Cash', '1100', '1000', '0', '130']]);
ok($r['recon']['ok'] === true, 'A sheet without Taxable/Total columns computes them and still reconciles');

echo "\n== Posting ==\n";
$before = (int) db()->query('SELECT COUNT(*) FROM vouchers WHERE company_id=' . $A['cid'])->fetchColumn();
$result = hospitality_post_sales_workbook($A['cid'], $A['fy'], $parsed, 'template.xlsx', $A['uid']);
ok($result['ok'] === true, 'The reconciled pair posts' . ($result['ok'] ? '' : ': ' . $result['error']));
ok((int) $result['vouchers'] === 2, 'One voucher per sale date');
ok((int) $result['rows'] === 5 && (int) $result['invoices'] === 2, 'Both sets of lines are stored');

$vouchers = db()->query('SELECT id, voucher_date, total_amount FROM vouchers WHERE company_id=' . $A['cid'] . " AND source_type='hospitality_sales_upload' ORDER BY voucher_date")->fetchAll(PDO::FETCH_ASSOC);
ok(count($vouchers) === 2, 'Two vouchers reached the books');
$allBalanced = true;
foreach ($vouchers as $v) {
    $sums = db()->query("SELECT SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END) dr, SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END) cr
        FROM voucher_entries WHERE voucher_id=" . (int) $v['id'])->fetch(PDO::FETCH_ASSOC);
    if (!near((float) $sums['dr'], (float) $sums['cr'])) { $allBalanced = false; }
}
ok($allBalanced, 'Every voucher balances');

// Day one: Dr Cash 28,532.50 / Cr Food 16,000 + Beverage 3,250 + Bar 6,000 + VAT 3,282.50
$day1 = (int) $vouchers[0]['id'];
$legs = [];
foreach (db()->query("SELECT e.entry_type, e.amount, l.name FROM voucher_entries e JOIN ledgers l ON l.id=e.ledger_id WHERE e.voucher_id=$day1")->fetchAll(PDO::FETCH_ASSOC) as $leg) {
    $legs[$leg['entry_type'] . '|' . $leg['name']] = (float) $leg['amount'];
}
ok(near($legs['debit|Cash in Hand'] ?? 0, 28532.50), 'The debit is the ledger the invoice sheet named, at the billed amount');
ok(near($legs['credit|Sales — Food'] ?? 0, 16000.00), 'Food is credited net of its discount (12,600 + 3,400)');
ok(near($legs['credit|Sales — Beverage'] ?? 0, 3250.00), 'Beverage is credited on its own ledger');
ok(near($legs['credit|Sales — Bar'] ?? 0, 6000.00), 'Bar is credited on its own ledger');
ok(near($legs['credit|VAT Payable'] ?? 0, 3282.50), 'VAT is credited once for the day');
ok(!isset($legs['debit|Discount Allowed']), 'Discount is netted into sales, not posted to a discount ledger');

$day2 = (int) $vouchers[1]['id'];
$legs2 = [];
foreach (db()->query("SELECT e.entry_type, e.amount, l.name FROM voucher_entries e JOIN ledgers l ON l.id=e.ledger_id WHERE e.voucher_id=$day2")->fetchAll(PDO::FETCH_ASSOC) as $leg) {
    $legs2[$leg['entry_type'] . '|' . $leg['name']] = (float) $leg['amount'];
}
ok(near($legs2['debit|Sundry Debtors'] ?? 0, 12882.00), 'A credit sale debits the customer ledger the sheet named');

echo "\n== The menu builds itself ==\n";
$menu = db()->query('SELECT code, name, category, standard_price FROM hospitality_menu_items WHERE company_id=' . $A['cid'] . ' ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
ok(count($menu) === 4, 'A menu item exists for each distinct item on the sheet');
$byName = [];
foreach ($menu as $m) { $byName[$m['name']] = $m; }
ok(isset($byName['Chicken Momo (10 pcs)']), '  ...named as the sheet named it');
ok(near((float) ($byName['Chicken Momo (10 pcs)']['standard_price'] ?? 0), 300.00), '  ...with a standard price taken from the sheet (12,600 over 42)');
ok((string) ($byName['Local Beer 650ml']['category'] ?? '') === 'Bar', '  ...and the category it was sold under');
$mapped = (int) db()->query("SELECT COUNT(*) FROM hospitality_sales_mappings WHERE company_id=" . $A['cid'] . " AND match_type='description'")->fetchColumn();
ok($mapped === 4, 'Each new menu item gets a sales mapping, so costing can find it');

// A second upload of the same items must not duplicate the menu.
$parsed2 = hospitality_workbook_parse($tmp, 'xlsx', null, null, $A['cid'], $A['fy'], $settings);
$again = hospitality_post_sales_workbook($A['cid'], $A['fy'], $parsed2, 'template.xlsx', $A['uid'], true);
ok($again['ok'] === true, 'The same sheet can be posted again when duplicates are allowed');
ok((int) $again['menu_created'] === 0, '  ...without creating the menu items a second time');
ok((int) db()->query('SELECT COUNT(*) FROM hospitality_menu_items WHERE company_id=' . $A['cid'])->fetchColumn() === 4, '  ...the menu still holds four items');

echo "\n== Duplicate dates are guarded ==\n";
$parsed3 = hospitality_workbook_parse($tmp, 'xlsx', null, null, $A['cid'], $A['fy'], $settings);
ok($parsed3['duplicate_dates'] !== [], 'A re-upload of posted days reports the duplicate dates');
$refused = hospitality_post_sales_workbook($A['cid'], $A['fy'], $parsed3, 'template.xlsx', $A['uid']);
ok($refused['ok'] === false, '  ...and is refused unless it is allowed explicitly');

echo "\n== A mismatched pair cannot be posted ==\n";
$badParsed = $parsed;
$badParsed['reconciliation'] = ['ok' => false, 'problems' => ['VAT does not agree.'], 'differences' => [], 'item_range' => ['', ''], 'invoice_range' => ['', '']];
$badParsed['duplicate_dates'] = [];
$badResult = hospitality_post_sales_workbook($A['cid'], $A['fy'], $badParsed, 'bad.xlsx', $A['uid'], true);
ok($badResult['ok'] === false, 'Posting refuses a pair that failed reconciliation');
ok(str_contains((string) $badResult['error'], 'do not agree'), '  ...saying so plainly');

echo "\n== A receivable ledger is not required ==\n";
// The debit comes from the invoice sheet's party ledger, so a receivable is
// neither read nor needed. It was demanded anyway, which refused the upload
// over a setting that posts nothing.
db()->exec('UPDATE hospitality_settings SET post_receivable_ledger_id = NULL WHERE company_id=' . $A['cid']);
db()->exec('UPDATE hospitality_sales_ledger_maps SET receivable_ledger_id = NULL, discount_ledger_id = NULL WHERE company_id=' . $A['cid']);
$noRecvSettings = hospitality_settings($A['cid']);
ok(hospitality_posting_config_errors($A['cid'], $noRecvSettings, false) === [],
    'With no receivable ledger anywhere, the two-sheet upload reports no setup problem');
ok(hospitality_posting_config_errors($A['cid'], $noRecvSettings) !== [],
    '  ...while the single-sheet path, which does post to one, still asks for it');
$noRecvParsed = hospitality_workbook_parse($tmp, 'xlsx', null, null, $A['cid'], $A['fy'], $noRecvSettings);
ok(($noRecvParsed['config_errors'] ?? ['x']) === [], '  ...and a sheet parses without complaint');
$noRecvResult = hospitality_post_sales_workbook($A['cid'], $A['fy'], $noRecvParsed, 'no-receivable.xlsx', $A['uid'], true);
ok($noRecvResult['ok'] === true, '  ...and posts' . (($noRecvResult['ok'] ?? false) ? '' : ': ' . ($noRecvResult['error'] ?? '')));
$noRecvVoucher = (int) db()->query('SELECT id FROM vouchers WHERE company_id=' . $A['cid'] . " AND source_type='hospitality_sales_upload' ORDER BY id DESC LIMIT 1")->fetchColumn();
$noRecvLegs = [];
foreach (db()->query("SELECT e.entry_type, e.amount, l.name FROM voucher_entries e JOIN ledgers l ON l.id=e.ledger_id WHERE e.voucher_id=$noRecvVoucher")->fetchAll(PDO::FETCH_ASSOC) as $leg) {
    $noRecvLegs[$leg['entry_type'] . '|' . $leg['name']] = (float) $leg['amount'];
}
ok(!isset($noRecvLegs['debit|Sales Receivable']), '  ...debiting the party ledger, never a receivable');
ok(isset($noRecvLegs['debit|Cash in Hand']) || isset($noRecvLegs['debit|Sundry Debtors']),
    '  ...which is the ledger the invoice sheet named');

echo "\n== Tenant isolation ==\n";
$settingsB = hospitality_settings($B['cid']);
set_context($B['cid'], $B['fy']);
$_SESSION['company_id'] = $B['cid'];
$lookupB = hospitality_workbook_ledger_lookup($B['cid']);
ok(hospitality_workbook_resolve_ledger($lookupB, '1100') === null, "One tenant's ledger code does not resolve inside another's books");
ok(hospitality_workbook_resolve_ledger($lookupB, 'X1100') !== null, '  ...while its own does');
ok((int) db()->query('SELECT COUNT(*) FROM hospitality_menu_items WHERE company_id=' . $B['cid'])->fetchColumn() === 0, "The other tenant's menu is untouched");
set_context($A['cid'], $A['fy']);
$_SESSION['company_id'] = $A['cid'];

echo "\n== Shape: a month must not cost a query per row ==\n";
$IHs = $IH; $VHs = $VH;
$bigItems = [$IHs]; $bigInvoices = [$VHs];
$start = new DateTimeImmutable('2026-08-01');
for ($d = 0; $d < 20; $d++) {
    $date = $start->modify("+$d day")->format('Y-m-d');
    $dayTax = 0.0; $dayVat = 0.0; $dayTot = 0.0;
    for ($n = 0; $n < 20; $n++) {
        $amount = 100.0 + $n; $tax = $amount; $vat = round($tax * 0.13, 2); $tot = round($tax + $vat, 2);
        $bigItems[] = [$date, ['Food', 'Beverage', 'Bar'][$n % 3], 'Dish ' . $n, 1, $amount, 0, $tax, $vat, $tot];
        $dayTax += $tax; $dayVat += $vat; $dayTot += $tot;
    }
    $bigInvoices[] = [$date, 'B-' . $d, 'Cash', '1100', $dayTax, 0, $dayTax, $dayVat, $dayTot];
}
$bigFile = tempnam(sys_get_temp_dir(), 'hwbig') . '.xlsx';
file_put_contents($bigFile, xlsx_build_sheets([HOSPITALITY_SHEET_ITEMS => $bigItems, HOSPITALITY_SHEET_INVOICES => $bigInvoices]));

$q0 = questions();
$bigParsed = hospitality_workbook_parse($bigFile, 'xlsx', null, null, $A['cid'], $A['fy'], $settings);
$parseCost = questions() - $q0 - 2;
ok(!isset($bigParsed['error']), '400 item rows over 20 days parse' . (isset($bigParsed['error']) ? ': ' . $bigParsed['error'] : ''));
ok($bigParsed['reconciliation']['ok'] === true, '  ...and reconcile');
ok($parseCost < 40, "  ...in $parseCost queries, not one per row (400 rows)");

$q1 = questions();
$bigResult = hospitality_post_sales_workbook($A['cid'], $A['fy'], $bigParsed, 'big.xlsx', $A['uid'], true);
$postCost = questions() - $q1 - 2;
ok($bigResult['ok'] === true, 'The month posts' . ($bigResult['ok'] ? '' : ': ' . $bigResult['error']));
ok($postCost < 400, "  ...in $postCost queries for 400 lines — the line writes are batched, not one statement each");

@unlink($tmp);
@unlink($bigFile);
hwb_cleanup();

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass   FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
