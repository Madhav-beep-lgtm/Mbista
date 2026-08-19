<?php
declare(strict_types=1);

/**
 * Renders EVERY view of all four jewellery pages against a fully populated
 * client — items, posted purchases and sales with old-gold exchange, an open
 * order, a kaligad round trip and a refinery job.
 *
 * The engine tests prove the arithmetic; this proves the TEMPLATES, which is
 * where undefined keys, bad array shapes and null-format warnings actually
 * live. Any PHP notice, warning or deprecation counts as a failure.
 *   php database/test_jewellery_page_render.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require_once $root . '/app/accounting_module_repair.php';
require_once $root . '/app/jewellery_reports.php';
accounting_module_repair_database();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin/jewellery.php';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = '127.0.0.1';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

function jwr_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'JWREN'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$s)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['jewellery_stock_unit_events', 'jewellery_stock_units', 'jewellery_refinery_jobs', 'jewellery_order_receipts', 'jewellery_order_assignments',
                  'jewellery_orders', 'jewellery_karigars', 'jewellery_settlement_allocations',
                  'jewellery_settlements', 'jewellery_bills', 'jewellery_sale_exchanges', 'jewellery_sale_lines',
                  'jewellery_sales', 'jewellery_purchase_lines', 'jewellery_purchases',                   'jewellery_stock_txns', 'jewellery_item_profiles', 'inventory_items', 'jewellery_daily_rates', 'inventory_ledger_mappings',
                  'jewellery_settings', 'jewellery_purities', 'jewellery_metals', 'jewellery_units'] as $t) {
            db()->exec("DELETE FROM `$t` WHERE company_id=$s");
        }
        db()->exec("DELETE FROM accounting_parties WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email = 'jwrender@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
jwr_cleanup();

// ---------------------------------------------------------------------------
// A shop with real history behind it
// ---------------------------------------------------------------------------
db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,1)')
    ->execute(['n' => 'Render Jewellers (Books)', 'c' => 'JWREN']);
$cid = (int) db()->lastInsertId();
$clientUserId = create_user(['name' => 'Render Owner', 'email' => 'jwrender@test.local', 'password' => 'Secret#12345', 'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, jewellery_accounting_enabled)
        VALUES (:uid,:cid,:books,:org,:code,1,1)')
    ->execute(['uid' => $clientUserId, 'cid' => $cid, 'books' => $cid, 'org' => 'Render Jewellers', 'code' => 'JWREN-C']);
$fy = create_fiscal_year($cid, 'JWREN 2026/27', '2026-07-16', '2027-07-15', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId = (int) $fy['id'];
$adminId = (int) db()->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();

$mkLedger = static function (int $companyId, string $code, string $name, string $master): int {
    db()->prepare('INSERT INTO ledger_groups (company_id,name,code,master_key) VALUES (:cid,:n,:c,:m)')
        ->execute(['cid' => $companyId, 'n' => 'JW ' . $code, 'c' => 'G' . $code, 'm' => $master]);
    $gid = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO ledgers (company_id,group_id,name,code) VALUES (:cid,:g,:n,:c)')
        ->execute(['cid' => $companyId, 'g' => $gid, 'n' => $name, 'c' => $code]);

    return (int) db()->lastInsertId();
};
jewellery_settings($cid);
foreach ([
    ['stock_metal', 'STKM', 'Metal Stock', 'assets'], ['stock_finished', 'STKF', 'Finished Stock', 'assets'],
    ['stock_stone', 'STKS', 'Stone Stock', 'assets'], ['stock_karigar', 'STKK', 'With Karigar', 'assets'],
    ['stock_refinery', 'STKR', 'With Refinery', 'assets'], ['sales_metal', 'SALM', 'Sales Metal', 'income'],
    ['sales_making', 'SALK', 'Sales Making', 'income'], ['sales_stone', 'SALS', 'Sales Stone', 'income'],
    ['sales_discount', 'SALD', 'Sales Discount', 'expenses'], ['other_charges', 'OTHC', 'Other Charges', 'income'],
    ['cogs', 'COGS', 'COGS', 'expenses'], ['vat_input', 'VATI', 'VAT Input', 'assets'],
    ['vat_output', 'VATO', 'VAT Output', 'liabilities'],
    // Without these two the fixture's sales could not actually post — which
    // the posting-confirmation card then reported, honestly, on screen.
    ['spt_input', 'SPTI', 'Skills Promotion Tax Input', 'assets'],
    ['spt_output', 'SPTO', 'Skills Promotion Tax Output', 'liabilities'],
    ['opening_equity', 'OPEQ', 'Opening Equity', 'equity'],
    ['rounding', 'ROUN', 'Rounding', 'expenses'], ['making_expense', 'MAKE', 'Making Charges', 'expenses'],
    ['wastage_loss', 'WAST', 'Wastage Loss', 'expenses'], ['karigar_payable', 'KARP', 'Karigar Payable', 'liabilities'],
    ['refinery_loss', 'RFLS', 'Refining Loss', 'expenses'], ['refinery_charges', 'RFCH', 'Refinery Charges', 'expenses'],
] as [$purpose, $code, $name, $master]) {
    jewellery_save_mapping($cid, $purpose, $mkLedger($cid, $code, $name, $master), $adminId);
}
$cash = $mkLedger($cid, 'CASHJ', 'Cash', 'assets');

db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status, pan_no) VALUES (:c,'SUP1','Bullion Supplier','supplier','active','123456789')")->execute(['c' => $cid]);
$supplier = (int) db()->lastInsertId();
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'CUS1','Retail Customer','customer','active')")->execute(['c' => $cid]);
$customer = (int) db()->lastInsertId();

$q = static fn (string $sql): int => (int) db()->query($sql)->fetchColumn();
$gold = $q("SELECT id FROM jewellery_metals WHERE company_id=$cid AND code='GOLD'");
$diaMetal = $q("SELECT id FROM jewellery_metals WHERE company_id=$cid AND code='DIAMOND'");
$p24 = $q("SELECT id FROM jewellery_purities WHERE company_id=$cid AND metal_id=$gold AND code='24K'");
$p22 = $q("SELECT id FROM jewellery_purities WHERE company_id=$cid AND metal_id=$gold AND code='22K'");
$pDia = $q("SELECT id FROM jewellery_purities WHERE company_id=$cid AND metal_id=$diaMetal AND code='STD'");
$tola = $q("SELECT id FROM jewellery_units WHERE company_id=$cid AND code='TOLA'");
$carat = $q("SELECT id FROM jewellery_units WHERE company_id=$cid AND code='CT'");

jewellery_save_rate($cid, ['rate_date' => '2026-08-01', 'metal_id' => $gold, 'purity_id' => $p24,
    'unit_id' => $tola, 'rate_type' => 'market', 'rate' => 152000], $adminId);

$chain = jewellery_save_item($cid, ['code' => 'CHAIN22', 'name' => '22K Chain', 'category' => 'Chains',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola, 'gross_weight' => 20, 'making_charge_rate' => 1200,
    'vat_applicable' => 1, 'vat_base' => 'making_only'], $adminId);
$oldGold = jewellery_save_item($cid, ['code' => 'OLD22', 'name' => 'Old Gold 22K', 'item_type' => 'bullion',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola], $adminId);
$fine24 = jewellery_save_item($cid, ['code' => 'FINE24', 'name' => 'Refined 24K', 'item_type' => 'bullion',
    'metal_id' => $gold, 'purity_id' => $p24, 'unit_id' => $tola], $adminId);
$diamond = jewellery_save_item($cid, ['code' => 'DIA', 'name' => 'Loose Diamond', 'item_type' => 'stone',
    'metal_id' => $diaMetal, 'purity_id' => $pDia, 'unit_id' => $carat, 'vat_applicable' => 1, 'vat_base' => 'full_value'], $adminId);

// A posted opening, purchases, a sale with exchange and a credit sale.
jewellery_save_opening($cid, $fyId, ['item_id' => $chain, 'gross_weight' => 5, 'qty_pieces' => 2, 'amount' => 687000], $adminId);
$p1 = jewellery_save_purchase($cid, $fyId, ['purchase_date' => '2026-08-01', 'party_id' => $supplier, 'settle_mode' => 'credit'],
    [['item_id' => $chain, 'gross_weight' => 20, 'rate' => 137400, 'making_amount' => 20000]], $adminId);
jewellery_post_purchase($cid, $p1, $adminId);
$p2 = jewellery_save_purchase($cid, $fyId, ['purchase_date' => '2026-08-02', 'settle_mode' => 'cash',
    'settle_ledger_id' => $cash, 'source' => 'customer_old_gold', 'party_name' => 'Gita Counter Seller'],
    [['item_id' => $oldGold, 'gross_weight' => 15, 'rate' => 137400], ['item_id' => $diamond, 'gross_weight' => 3, 'stone_amount' => 150000]], $adminId);
jewellery_post_purchase($cid, $p2, $adminId);
$s1 = jewellery_save_sale($cid, $fyId, ['sale_date' => '2026-08-10', 'party_id' => $customer,
    'received_amount' => 100000, 'settle_ledger_id' => $cash, 'discount' => 500, 'other_charges' => 250],
    [['item_id' => $chain, 'gross_weight' => 5, 'rate' => 160000, 'making_amount' => 15000]],
    [['item_id' => $oldGold, 'gross_weight' => 2, 'rate' => 140000]], $adminId);
jewellery_post_sale($cid, $s1, $adminId);
$s2 = jewellery_save_sale($cid, $fyId, ['sale_date' => '2026-08-12', 'received_amount' => 0, 'customer_name' => 'Walk-in Buyer',
    'party_id' => $customer], [['item_id' => $chain, 'gross_weight' => 2, 'rate' => 160000]], [], $adminId);
jewellery_post_sale($cid, $s2, $adminId);
// A draft left unposted, so the posting-confirmation screen has something to
// show: the Post button leads to the mapping, never straight to the ledger.
$s3 = jewellery_save_sale($cid, $fyId, ['sale_date' => '2026-08-13', 'received_amount' => 0, 'customer_name' => 'Confirm Screen Buyer',
    'party_id' => $customer], [['item_id' => $chain, 'gross_weight' => 1, 'rate' => 160000]], [], $adminId);

// A settlement against the supplier bill, so the bills view has history.
$supBill = $q("SELECT id FROM jewellery_bills WHERE company_id=$cid AND bill_type='purchase' LIMIT 1");
$stId = jewellery_save_settlement($cid, $fyId, ['settlement_date' => '2026-08-15', 'party_id' => $supplier,
    'direction' => 'paid', 'mode' => 'cash', 'amount' => 500000, 'ledger_id' => $cash],
    [['bill_id' => $supBill, 'amount' => 500000]], $adminId);
jewellery_post_settlement($cid, $stId, $adminId);

// A kaligad round trip, an order still awaiting collection, and one still out.
$karigar = jewellery_save_karigar($cid, ['code' => 'RAM', 'name' => 'Ram Shakya', 'engagement_type' => 'contractor',
    'default_making_rate' => 1000, 'wastage_allowed_pct' => 0.5], $adminId);
$order = jewellery_save_order($cid, $fyId, ['order_date' => '2026-08-05', 'delivery_date' => '2026-08-25',
    'party_id' => $customer, 'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola,
    'expected_gross_weight' => 10, 'making_rate' => 1000, 'status' => 'confirmed', 'design_no' => 'D-100'], [], $adminId);
$assign = jewellery_issue_to_karigar($cid, $fyId, ['karigar_id' => $karigar, 'order_id' => $order,
    'item_id' => $chain, 'unit_id' => $tola, 'issued_gross_weight' => 10, 'issue_date' => '2026-08-06',
    'wastage_allowed_pct' => 0.5, 'making_rate' => 1000], $adminId);
jewellery_receive_from_karigar($cid, $fyId, ['assignment_id' => (int) $assign['assignment_id'],
    'received_gross_weight' => 9.9, 'qty_pieces' => 1, 'receive_date' => '2026-08-20'], $adminId);
// A second assignment left OUT, so the receive screen has something to show.
$assign2 = jewellery_issue_to_karigar($cid, $fyId, ['karigar_id' => $karigar, 'item_id' => $chain,
    'unit_id' => $tola, 'issued_gross_weight' => 3, 'issue_date' => '2026-08-21', 'making_rate' => 1000], $adminId);
// Two items on the order, so the printed preview exercises the items table —
// the shape every order takes now — rather than the legacy header-only card.
$order2 = jewellery_save_order($cid, $fyId, ['order_date' => '2026-08-22', 'customer_name' => 'Second Customer',
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola,
    'status' => 'confirmed'], [
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 2, 'rate' => 160000, 'making_amount' => 3000],
    ['item_id' => $chain, 'purity_id' => $p22, 'unit_id' => $tola, 'qty_pieces' => 1,
     'gross_weight' => 2, 'rate' => 160000, 'making_amount' => 2000],
], $adminId);

$job = jewellery_issue_to_refinery($cid, $fyId, ['party_id' => $supplier, 'item_id' => $oldGold,
    'unit_id' => $tola, 'issued_gross_weight' => 5, 'issue_date' => '2026-09-01'], $adminId);
$job2 = jewellery_issue_to_refinery($cid, $fyId, ['party_id' => $supplier, 'item_id' => $oldGold,
    'unit_id' => $tola, 'issued_gross_weight' => 4, 'issue_date' => '2026-09-02'], $adminId);
jewellery_receive_from_refinery($cid, $fyId, ['job_id' => (int) $job['job_id'], 'received_item_id' => $fine24,
    'received_purity_id' => $p24, 'received_gross_weight' => 4.4, 'receive_date' => '2026-09-10',
    'charges_amount' => 3000, 'charges_settle_mode' => 'credit'], $adminId);

// Log in as the platform admin and pin the context to this client's books.
$_SESSION['user_id'] = $adminId;
set_context($cid, $fyId);
mark_company_pin_verified($cid);
set_selected_company($cid);

// ---------------------------------------------------------------------------
// Render every view
// ---------------------------------------------------------------------------
$pages = [
    'jewellery.php' => ['dashboard', 'rates', 'items', 'opening', 'stock', 'masters', 'settings'],
    'jewellery-trade.php' => ['purchases', 'sales', 'bills'],
    'jewellery-workshop.php' => ['orders', 'assignments', 'delivery', 'karigars', 'refinery'],
    'jewellery-reports.php' => ['summary', 'sales', 'purchases', 'inventory', 'vat', 'karigar', 'statement', 'bills', 'uncollected',
        'orders', 'workshop', 'advreg', 'profit'],
    // Shared components live on these two as well now.
    'accounting-inventory.php' => ['inventory'],
];
// Deep links that exercise the edit/drill-down branches, which are where the
// interesting template code lives.
$extraParams = [
    'jewellery.php' => ['items' => ['edit' => $chain], 'stock' => ['item' => $chain], 'rates' => ['date' => '2026-08-01']],
    'jewellery-trade.php' => ['purchases' => ['edit' => $p1],
        // The draft's edit form AND the posting-confirmation card on one page.
        'sales' => ['edit' => $s3, 'confirm_post' => $s3], 'bills' => ['party' => $supplier]],
    'jewellery-workshop.php' => ['orders' => ['edit' => $order2], 'karigars' => ['edit' => $karigar],
        'assignments' => ['receive' => (int) $assign2['assignment_id'], 'wt' => '2.9'],
        'refinery' => ['receive' => (int) $job2['job_id']]],
    'jewellery-reports.php' => ['karigar' => ['karigar' => $karigar], 'sales' => ['group' => 'category'],
        'statement' => ['karigar' => $karigar, 'fine_rate' => 120000],
        // The workshop register grouped kaligad-wise, which is its richest branch.
        'workshop' => ['wgroup' => 'karigar'], 'orders' => ['status' => '']],
];

$renderedHtml = [];
foreach ($pages as $script => $views) {
    $_SERVER['SCRIPT_NAME'] = '/admin/' . $script;
    foreach ($views as $view) {
        $_GET = ['view' => $view] + ($extraParams[$script][$view] ?? []);
        $_POST = [];
        ob_start();
        $error = null;
        try {
            include $root . '/public_html/admin/' . $script;
        } catch (Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
        }
        $html = (string) ob_get_clean();
        $len = strlen($html);
        $problems = [];
        if ($error !== null) {
            $problems[] = $error;
        }
        foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $needle) {
            $pos = stripos($html, $needle);
            if ($pos !== false) {
                $problems[] = trim(substr($html, $pos, 200));
            }
        }
        if ($len < 3000) {
            $problems[] = 'suspiciously short output (' . $len . ' bytes)';
        }
        $label = str_pad($script . '?view=' . $view, 44) . ' ' . str_pad((string) $len, 7, ' ', STR_PAD_LEFT) . ' bytes';
        ok($problems === [], $label);
        foreach ($problems as $problem) {
            echo '        ' . $problem . "\n";
        }
        $renderedHtml[$script . '?view=' . $view] = $html;
    }
}

// ---------------------------------------------------------------------------
// What the browser will find on those pages
// ---------------------------------------------------------------------------
//
// Folding sections and the "+ Add new" option are both applied by JavaScript to
// the markup produced above, so the only honest place to check them is here, on
// the real rendered output rather than on the source that generates it.

echo "\nSections fold, dropdowns offer \"+ Add new\"\n";

// A card marked collapsible but with no heading is silently skipped by the
// script — no chevron, no fold, and nothing anywhere says why.
$headless = [];
$markedTotal = 0;
foreach ($renderedHtml as $page => $html) {
    foreach (explode('data-collapsible', $html) as $i => $chunk) {
        if ($i === 0) { continue; }
        $markedTotal++;
        // The heading is the card's first child, so it is a few dozen bytes in.
        if (!str_contains(substr($chunk, 0, 600), 'mbw-card-head')) {
            $headless[] = $page;
        }
    }
}
ok($markedTotal > 0, "Cards are marked collapsible on the rendered pages ($markedTotal)");
ok($headless === [], 'Every one of them has a heading for the fold to hang off'
    . ($headless === [] ? '' : ' — headless on ' . implode(', ', array_unique($headless))));

/*
 * And the "+ Add new" map is keyed by select NAME. A name that no page actually
 * emits is a dead entry that can never fire, so the map is checked against what
 * the templates really render rather than against what it claims to cover.
 */
$mainJs = (string) file_get_contents($root . '/public_html/assets/js/main.js');
preg_match_all("~^\s*'?([a-z_]+(?:\[\])?)'?:\s*'admin/~m", $mainJs, $mapped);
$allHtml = implode('', $renderedHtml);
$live = 0;
$dead = [];
foreach (array_unique($mapped[1]) as $name) {
    if (str_contains($allHtml, 'name="' . $name . '"')) { $live++; } else { $dead[] = $name; }
}
ok($live >= 8, "The mapped dropdown names are really on the pages ($live live)");
// Names only reachable from a page this harness does not render are reported
// rather than failed — the point is to see them, not to claim the map is perfect.
if ($dead !== []) {
    echo '        not seen in these views: ' . implode(', ', $dead) . "\n";
}

echo "\nEvery icon the app asks for is actually drawn\n";
/*
 * icon() answers an unknown name with a fallback circle rather than an error,
 * which is right — a missing glyph must never take a page down. But it is
 * silent, and eight names went missing that way: the "fine weight" tiles wore
 * a bare ring for months, and nobody could see a bug in a page that rendered.
 *
 * So the check is mechanical: collect every icon('name') in the app, and fail
 * if any of them is not in the registry. Adding a call with a name nobody
 * drew now breaks a test instead of shipping a circle.
 */
$iconSource = (string) file_get_contents($root . '/app/helpers.php');
$iconStart = strpos($iconSource, 'function icon(string $name)');
$iconBody = substr($iconSource, $iconStart, strpos($iconSource, "\n}", $iconStart) - $iconStart);
preg_match_all("/^        '([a-z0-9-]+)' => \[/m", $iconBody, $iconMatches);
$iconRegistry = array_flip($iconMatches[1]);
ok(count($iconRegistry) > 50, 'The icon registry loaded (' . count($iconRegistry) . ' glyphs)');

$iconUsed = [];
foreach (array_merge(
    glob($root . '/public_html/admin/*.php') ?: [],
    glob($root . '/public_html/*.php') ?: [],
    glob($root . '/app/views/partials/*.php') ?: [],
    glob($root . '/app/*.php') ?: []
) as $iconFile) {
    preg_match_all("/icon\(\s*'([a-z0-9-]+)'/", (string) file_get_contents($iconFile), $iconHits);
    foreach ($iconHits[1] as $iconName) {
        $iconUsed[$iconName] = true;
    }
}
$iconMissing = array_diff(array_keys($iconUsed), array_keys($iconRegistry));
ok($iconMissing === [], 'Every icon name used across the app is drawn'
    . ($iconMissing === [] ? ' (' . count($iconUsed) . ' names)' : ' — MISSING: ' . implode(', ', $iconMissing)));

// A glyph that renders as an empty <svg> is as blank as a missing one.
// Case-insensitive on the path command: 'm' is a RELATIVE moveto and just as
// valid as 'M' — demanding the capital failed 'layers', which draws fine.
$iconEmpty = [];
foreach (array_keys($iconUsed) as $iconName) {
    if (preg_match('/<path d="[Mm][\d\s.-]/', icon($iconName)) !== 1) {
        $iconEmpty[] = $iconName;
    }
}
ok($iconEmpty === [], 'And every one of them draws at least one path'
    . ($iconEmpty === [] ? '' : ' — EMPTY: ' . implode(', ', $iconEmpty)));

echo "\nThe reports toolbar says what its buttons do\n";
/*
 * Those three export buttons rendered as empty green rectangles. The labels
 * were in the HTML the whole time — the page simply never loaded the module
 * stylesheet, so .button.soft fell through to a generic rule that painted dark
 * text on a dark green gradient.
 *
 * A screenshot is the only thing that catches "same colour as the background",
 * so what is checked here is the part that CAN be: the page pulls in the skin
 * that styles them, and every button carries a word as well as a glyph.
 */
// ---------------------------------------------------------------------------
echo "\nOpening stock asks its questions on the server\n";
// ---------------------------------------------------------------------------
// This screen used to send every opening row it had and then hide the ones that
// did not match, in the browser. A shop with a couple of thousand items was
// handed a ten-megabyte document to look at fifty rows of. If the filter fields
// ever lose their names, or the row-count chooser goes, that is the road back.
$openingHtml = $renderedHtml['jewellery.php?view=opening'] ?? '';
foreach (['o_q' => 'the search', 'o_group' => 'the stock group', 'o_kind' => 'the stock type',
          'o_purity' => 'the purity', 'o_status' => 'the posting status'] as $field => $what) {
    ok(str_contains($openingHtml, 'name="' . $field . '"'), 'Opening stock submits ' . $what . ' to the server');
}
ok(!str_contains($openingHtml, 'jw-filter-apply') && !str_contains($openingHtml, 'attachOpeningFilters'),
    'And the old hide-rows-in-the-browser filter is gone with it');
ok(str_contains($openingHtml, 'o_per'), 'The rows-per-page choice is on the page');

// Held for whom, chosen off the master rather than typed. The name box stays
// for a walk-in, so losing either control is a regression.
ok(str_contains($openingHtml, 'name="customer_party_id"'), 'Opening stock picks its customer off the master');
ok(str_contains($openingHtml, 'name="customer_name"'), 'And still lets a walk-in be named in words');
ok(str_contains($openingHtml, 'data-customer-party'), 'The edit button carries the chosen customer back into the form');

$reportHtml = $renderedHtml['jewellery-reports.php?view=inventory'] ?? '';
ok($reportHtml !== '', 'The reports page rendered');

/*
 * The posting-confirmation card: a draft's Post button leads to the mapping,
 * and the mapping is on the page — ledger legs, stock movement, and a confirm
 * button that says what it commits. Nothing posts sight unseen.
 */
$confirmHtml = $renderedHtml['jewellery-trade.php?view=sales'] ?? '';
ok(str_contains($confirmHtml, 'confirm where it lands'), 'The confirmation card renders for a draft sale');
ok(str_contains($confirmHtml, 'Confirm &amp; Post'), 'With a confirm button that commits exactly what is shown');
ok(str_contains($confirmHtml, 'Sales Metal') || str_contains($confirmHtml, 'JSALM'),
    'And the derived revenue ledger is named on it before anything posts');
ok(str_contains($confirmHtml, 'Post…'), 'The list buttons lead to the card, not straight to the ledger');
/*
 * Asserted against the SOURCE, not this rendered output. jw_page_styles() emits
 * the link once per process and this harness renders every page in one, so an
 * earlier page has already consumed it by the time we get here. A real request
 * renders one page and gets its own link.
 */
$reportSource = (string) file_get_contents($root . '/public_html/admin/jewellery-reports.php');
ok(str_contains($reportSource, 'jewellery_page_head.php') && str_contains($reportSource, 'jw_page_styles();'),
    'It pulls in the module stylesheet — not doing so is what made the buttons unreadable');
ok(str_contains($reportHtml, 'jw-report-filter'), 'The filter bar uses the module class');
ok(str_contains($reportHtml, 'jw-report-exports'),
    'And the exports have their own class, not .button.soft that five stylesheets argue over');

$missingLabels = [];
foreach (['CSV', 'Excel', 'PDF / Print', 'Apply'] as $label) {
    if (!str_contains($reportHtml, $label)) { $missingLabels[] = $label; }
}
ok($missingLabels === [], 'Every button on the bar is labelled in words'
    . ($missingLabels === [] ? '' : ' — missing ' . implode(', ', $missingLabels)));

// An icon that silently resolves to nothing would leave a button looking bare.
// 2200 bytes: three anchors whose hrefs now carry the order-report filters
// (status, pending, wgroup) as well as the period — the icons sit further in.
$toolbarAt = strpos($reportHtml, 'jw-report-exports');
$toolbar = $toolbarAt === false ? '' : substr($reportHtml, $toolbarAt, 2200);
ok(substr_count($toolbar, '<svg') >= 3, 'And each export button draws its icon ('
    . substr_count($toolbar, '<svg') . ' found)');

// The stylesheet has to state both halves. A rule that sets a background and
// lets the text colour be inherited is how this broke in the first place.
$skinCss = (string) file_get_contents($root . '/public_html/assets/css/jewellery.css');
ok(str_contains($skinCss, '.jw-report-exports .jw-export'), 'The skin styles them');
$exportAt = strpos($skinCss, '.jw-report-exports .jw-export {');
$exportRule = $exportAt === false ? '' : substr($skinCss, $exportAt, 700);
ok(str_contains($exportRule, 'background') && str_contains($exportRule, 'color'),
    'Stating BOTH the background and the text colour, so they cannot end up agreeing');
ok(substr_count($skinCss, 'var(--mbw-') > 20,
    'And in tokens, so the bar follows the light/dark switch rather than being right in one');

// ---------------------------------------------------------------------------
// The print / preview controller, once per document type. It exits via
// export_print(), so each is run in a child process — an exit() in-process
// would take the whole suite with it.
// ---------------------------------------------------------------------------
echo "\nDocument preview (print controller)\n";
$runner = $root . '/database/jewellery_print_probe.php';
file_put_contents($runner, <<<'PROBE'
<?php
// The controller ends in export_print(), which exits — and any refusal ends in
// redirect(), which also exits. So the result is reported from a shutdown
// handler; capturing after the include would never run.
//
// The context has to be established exactly as the parent suite does it, or
// require_jewellery() refuses and the probe measures an empty redirect.
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../app/bootstrap.php';
$probeCompany = (int) $argv[1];
$probeFy = (int) $argv[2];
$_SESSION['user_id'] = (int) $argv[3];
set_context($probeCompany, $probeFy);
mark_company_pin_verified($probeCompany);
set_selected_company($probeCompany);
$probeScript = $argv[6] ?? 'jewellery-print.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin/' . $probeScript;
// jewellery-invoice.php only ever prints a sale, so it takes an id and nothing
// else; the generic preview controller needs to be told which book to look in.
$_GET = $probeScript === 'jewellery-invoice.php'
    ? ['id' => (int) $argv[5]]
    : ['doc' => $argv[4], 'id' => (int) $argv[5], 'format' => 'print'];
$_POST = [];
register_shutdown_function(static function (): void {
    $html = '';
    while (ob_get_level() > 0) { $html = ob_get_clean() . $html; }
    $dirty = stripos($html, 'Fatal error') !== false || stripos($html, 'Warning:') !== false
        || stripos($html, 'Uncaught') !== false;
    // An optional needle the caller expects in the output — how the harness
    // proves a document printed its CONTENT, not merely a page.
    $needle = (string) ($GLOBALS['argv'][7] ?? '');
    $found = $needle === '' ? '' : '|' . (stripos($html, $needle) !== false ? 'HAS' : 'MISSING');
    fwrite(STDOUT, strlen($html) . '|' . ($dirty ? 'DIRTY' : 'CLEAN') . $found);
});
ob_start();
include __DIR__ . '/../public_html/admin/' . $probeScript;
PROBE);

foreach ([
    ['sale', $s2, 'Sale', 'jewellery-print.php', ''],
    ['purchase', $p1, 'Purchase', 'jewellery-print.php', ''],
    // The order prints its ITEMS now — the needle proves the table is there,
    // not merely that a page came back.
    ['order', $order2, 'Order', 'jewellery-print.php', 'Line total'],
    ['sale', $s2, 'Tax invoice', 'jewellery-invoice.php', ''],
] as [$docKind, $docId, $label, $script, $needle]) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' '
        . (int) $cid . ' ' . (int) $fyId . ' ' . (int) $adminId . ' '
        . escapeshellarg($docKind) . ' ' . (int) $docId . ' ' . escapeshellarg($script)
        . ($needle !== '' ? ' ' . escapeshellarg($needle) : '');
    $out = trim((string) shell_exec($cmd . ' 2>&1'));
    [$len, $state, $found] = array_pad(explode('|', $out), 3, '');
    $ok = is_numeric($len) && (int) $len > 400 && $state === 'CLEAN'
        && ($needle === '' || $found === 'HAS');
    ok($ok, str_pad($label . ' preview renders', 44) . ' ' . ($ok ? $len . ' bytes' : $out));
}
@unlink($runner);

// ---------------------------------------------------------------------------
// The line grid's column widths come from its <colgroup>, because under
// table-layout:fixed that is the only place they are read from when the first
// header row carries colspans — as this one does. A colgroup with the wrong
// number of <col> elements does not fail loudly: every width simply lands on
// the wrong column and the grid looks squashed, which is the bug this whole
// arrangement was introduced to fix. So the count is checked per variant.
// ---------------------------------------------------------------------------
echo "\nLine grid column alignment\n";
require_once $root . '/app/views/partials/jewellery_line_grid.php';
foreach ([
    ['l', false, 'Sale / purchase line'],
    ['l', true, 'Order line, with workshop'],
    ['x', false, 'Old-gold exchange line'],
] as [$gridPrefix, $withWorkshop, $gridLabel]) {
    ob_start();
    jw_render_line_grid($gridPrefix, [], 1, 'T', [
        'items' => [], 'purities' => [], 'units' => [], 'base_unit' => null,
        'on_hand' => [], 'karigars' => $withWorkshop ? [] : null,
    ]);
    $gridHtml = (string) ob_get_clean();
    $colCount = substr_count($gridHtml, '<col ');
    preg_match('~<tbody>(.*?)</tbody>~s', $gridHtml, $bodyMatch);
    $cellCount = substr_count($bodyMatch[1] ?? '', '<td');
    ok($colCount > 0 && $colCount === $cellCount,
        str_pad($gridLabel, 44) . ' ' . $colCount . ' cols / ' . $cellCount . ' cells');
}

jwr_cleanup();
echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail === 0 ? 0 : 1);
