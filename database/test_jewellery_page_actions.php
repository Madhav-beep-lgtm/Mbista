<?php
declare(strict_types=1);
/**
 * Exercises EVERY admin/jewellery.php POST handler end to end, each in its own
 * process (they all end in redirect(), which exits).
 *
 * The engine tests prove the maths; this one proves the WIRING — above all the
 * class of bug where a prepared statement is handed placeholders its SQL never
 * declared, which only ever fires on the real POST path.
 *   php database/test_jewellery_page_actions.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
$root = dirname(__DIR__);
$here = __DIR__;
require $root . '/app/bootstrap.php';
require_once $root . '/app/accounting_module_repair.php';
require_once $root . '/app/jewellery_stock.php';
// The workshop engine, for the order the advance section takes money against.
require_once $root . '/app/jewellery_workshop.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

function cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'JWPOST'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$s)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        foreach (['jewellery_line_taxes', 'jewellery_advance_allocations', 'jewellery_settlement_tenders',
                  'jewellery_settlement_allocations', 'jewellery_settlements', 'jewellery_bills',
                  'jewellery_sale_exchanges', 'jewellery_sale_lines', 'jewellery_sales',
                  'jewellery_purchase_lines', 'jewellery_purchases',
                  'jewellery_order_lines', 'jewellery_orders',
                  'jewellery_stock_txns', 'jewellery_item_profiles', 'inventory_items', 'jewellery_daily_rates',
                  'inventory_ledger_mappings', 'jewellery_settings', 'jewellery_purities', 'jewellery_metals',
                  'jewellery_units'] as $t) {
            db()->exec("DELETE FROM `$t` WHERE company_id=$s");
        }
        db()->exec("DELETE FROM accounting_parties WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email = 'jwpost@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
cleanup();

db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n,:c,1,1)')
    ->execute(['n' => 'POST Jewellers (Books)', 'c' => 'JWPOST']);
$cid = (int) db()->lastInsertId();
$clientUserId = create_user(['name' => 'POST Owner', 'email' => 'jwpost@test.local', 'password' => 'Secret#12345', 'role' => 'customer', 'status' => 'active', 'company_id' => $cid]);
db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active, jewellery_accounting_enabled)
        VALUES (:uid,:cid,:books,:org,:code,1,1)')
    ->execute(['uid' => $clientUserId, 'cid' => $cid, 'books' => $cid, 'org' => 'POST Jewellers', 'code' => 'JWPOST-C']);
$fy = create_fiscal_year($cid, 'JWPOST 2026/27', '2026-07-16', '2027-07-15', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId = (int) $fy['id'];
$adminId = (int) db()->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();

db()->prepare("INSERT INTO ledger_groups (company_id,name,code,master_key) VALUES (:cid,'JW Assets','JWGA','assets')")->execute(['cid' => $cid]);
$ga = (int) db()->lastInsertId();
db()->prepare("INSERT INTO ledgers (company_id,group_id,name,code) VALUES (:cid,:g,'Jewellery Stock','JWSTK')")->execute(['cid' => $cid, 'g' => $ga]);
$ldgStock = (int) db()->lastInsertId();
db()->prepare("INSERT INTO ledger_groups (company_id,name,code,master_key) VALUES (:cid,'JW Equity','JWGE','equity')")->execute(['cid' => $cid]);
$ge = (int) db()->lastInsertId();
db()->prepare("INSERT INTO ledgers (company_id,group_id,name,code) VALUES (:cid,:g,'Opening Equity','JWEQ')")->execute(['cid' => $cid, 'g' => $ge]);
$ldgEquity = (int) db()->lastInsertId();

jewellery_settings($cid);
$q = static fn (string $sql): int => (int) db()->query($sql)->fetchColumn();
$gold = $q("SELECT id FROM jewellery_metals WHERE company_id=$cid AND code='GOLD'");
$p22 = $q("SELECT id FROM jewellery_purities WHERE company_id=$cid AND metal_id=$gold AND code='22K'");
$p24 = $q("SELECT id FROM jewellery_purities WHERE company_id=$cid AND metal_id=$gold AND code='24K'");
$tola = $q("SELECT id FROM jewellery_units WHERE company_id=$cid AND code='TOLA'");

$run = static function (array $post, string $page = 'jewellery.php') use ($cid, $fyId, $adminId, $here): array {
    $payload = json_encode(['company_id' => $cid, 'fy_id' => $fyId, 'user_id' => $adminId, 'post' => $post,
        'page' => $page], JSON_THROW_ON_ERROR);
    // The payload travels via a FILE: Windows' escapeshellarg() strips the
    // double quotes out of a JSON argument, so argv cannot carry it.
    $payloadFile = sys_get_temp_dir() . '/jw_page_action_payload.json';
    file_put_contents($payloadFile, $payload);
    $cmd = 'php ' . escapeshellarg($here . '/jewellery_page_action_runner.php') . ' ' . escapeshellarg($payloadFile) . ' 2>&1';
    $out = trim((string) shell_exec($cmd));
    if (getenv('JW_DEBUG') === '1') { echo "--- CMD: $cmd\n--- RAW: " . substr($out, 0, 900) . "\n"; }
    // The flash line is the last one; anything before it is unexpected noise.
    $lines = array_values(array_filter(explode("\n", $out), static fn ($l) => trim($l) !== ''));
    $last = $lines === [] ? 'NONE|no output' : trim(end($lines));
    [$kind, $msg] = array_pad(explode('|', $last, 2), 2, '');

    return ['kind' => $kind, 'msg' => $msg, 'raw' => $out];
};

echo "POST handler coverage (each action in its own process)\n\n";

// --- Phase 1 handlers -------------------------------------------------------
$r = $run(['action' => 'save_unit', 'back_view' => 'masters', 'code' => 'RATTI', 'name' => 'Ratti', 'grams' => '0.1215', 'active' => '1']);
ok($r['kind'] === 'SUCCESS', 'save_unit (insert) — ' . $r['msg']);
$rattiId = $q("SELECT id FROM jewellery_units WHERE company_id=$cid AND code='RATTI'");
$r = $run(['action' => 'save_unit', 'back_view' => 'masters', 'unit_id' => (string) $rattiId, 'code' => 'RATTI', 'name' => 'Ratti (edited)', 'grams' => '0.1220', 'active' => '1']);
ok($r['kind'] === 'SUCCESS', 'save_unit (update) — ' . $r['msg']);
ok($q("SELECT COUNT(*) FROM jewellery_units WHERE company_id=$cid AND name='Ratti (edited)'") === 1, 'save_unit update actually persisted');

$r = $run(['action' => 'save_unit', 'back_view' => 'masters', 'code' => 'BAD', 'name' => 'Bad', 'grams' => '0']);
ok($r['kind'] === 'ERROR', 'save_unit rejects a zero gram factor — ' . $r['msg']);

$r = $run(['action' => 'save_metal', 'back_view' => 'masters', 'code' => 'PALL', 'name' => 'Palladium', 'metal_kind' => 'metal', 'default_unit_id' => (string) $tola, 'track_purity' => '1', 'active' => '1']);
ok($r['kind'] === 'SUCCESS', 'save_metal (insert) — ' . $r['msg']);
$pallId = $q("SELECT id FROM jewellery_metals WHERE company_id=$cid AND code='PALL'");
$r = $run(['action' => 'save_metal', 'back_view' => 'masters', 'metal_id' => (string) $pallId, 'code' => 'PALL', 'name' => 'Palladium (edited)', 'metal_kind' => 'metal', 'default_unit_id' => (string) $tola, 'active' => '1']);
ok($r['kind'] === 'SUCCESS', 'save_metal (update) — ' . $r['msg']);
ok($q("SELECT COUNT(*) FROM jewellery_metals WHERE company_id=$cid AND name='Palladium (edited)'") === 1, 'save_metal update actually persisted');

$r = $run(['action' => 'save_purity', 'back_view' => 'masters', 'metal_id' => (string) $pallId, 'code' => 'PD950', 'name' => 'Palladium 950', 'fineness' => '950', 'is_default' => '1', 'active' => '1']);
ok($r['kind'] === 'SUCCESS', 'save_purity (insert) — ' . $r['msg']);
$pdId = $q("SELECT id FROM jewellery_purities WHERE company_id=$cid AND code='PD950'");
$r = $run(['action' => 'save_purity', 'back_view' => 'masters', 'purity_id' => (string) $pdId, 'metal_id' => (string) $pallId, 'code' => 'PD950', 'name' => 'Palladium 950 (edited)', 'fineness' => '950', 'active' => '1']);
ok($r['kind'] === 'SUCCESS', 'save_purity (update) — ' . $r['msg']);
$r = $run(['action' => 'save_purity', 'back_view' => 'masters', 'metal_id' => (string) $pallId, 'code' => 'BAD', 'name' => 'Bad', 'fineness' => '1500']);
ok($r['kind'] === 'ERROR', 'save_purity rejects fineness above 1000 — ' . $r['msg']);

$r = $run(['action' => 'save_rate', 'back_view' => 'rates', 'rate_date' => '2026-08-01', 'metal_id' => (string) $gold,
    'purity_id' => (string) $p24, 'unit_id' => (string) $tola, 'rate_type' => 'market', 'rate' => '152000']);
ok($r['kind'] === 'SUCCESS', 'save_rate — ' . $r['msg']);
$rateId = $q("SELECT id FROM jewellery_daily_rates WHERE company_id=$cid LIMIT 1");
$r = $run(['action' => 'delete_rate', 'back_view' => 'rates', 'rate_id' => (string) $rateId, 'rate_date' => '2026-08-01']);
ok($r['kind'] === 'SUCCESS', 'delete_rate — ' . $r['msg']);
$r = $run(['action' => 'delete_rate', 'back_view' => 'rates', 'rate_id' => '999999', 'rate_date' => '2026-08-01']);
ok($r['kind'] === 'ERROR', 'delete_rate on a foreign id reports an error — ' . $r['msg']);

$r = $run(['action' => 'save_settings', 'back_view' => 'settings', 'base_unit_id' => (string) $tola,
    'default_metal_id' => (string) $gold, 'vat_rate' => '13', 'default_vat_base' => 'full_value',
    'making_charge_basis' => 'per_unit_weight', 'default_wastage_pct' => '1.5', 'rate_source' => 'last_known',
    'weight_precision' => '4', 'rate_precision' => '2', 'amount_precision' => '2',
    'sale_no_prefix' => 'JS', 'purchase_no_prefix' => 'JP', 'order_no_prefix' => 'JO',
    'issue_no_prefix' => 'JI', 'refinery_no_prefix' => 'JR', 'auto_post' => '1']);
ok($r['kind'] === 'SUCCESS', 'save_settings — ' . $r['msg']);
$r = $run(['action' => 'save_settings', 'back_view' => 'settings', 'vat_rate' => '150']);
ok($r['kind'] === 'ERROR', 'save_settings rejects a VAT rate above 100% — ' . $r['msg']);

$r = $run(['action' => 'save_mapping', 'back_view' => 'settings', 'purpose' => 'stock_finished', 'ledger_id' => (string) $ldgStock]);
ok($r['kind'] === 'SUCCESS', 'save_mapping — ' . $r['msg']);
$run(['action' => 'save_mapping', 'back_view' => 'settings', 'purpose' => 'opening_equity', 'ledger_id' => (string) $ldgEquity]);
$r = $run(['action' => 'save_mapping', 'back_view' => 'settings', 'purpose' => 'not_real', 'ledger_id' => (string) $ldgStock]);
ok($r['kind'] === 'ERROR', 'save_mapping rejects an unknown purpose — ' . $r['msg']);

// --- Phase 2 handlers -------------------------------------------------------
$r = $run(['action' => 'save_item', 'back_view' => 'items', 'code' => 'RING22', 'name' => '22K Ring',
    'category' => 'Rings', 'item_type' => 'ornament', 'metal_id' => (string) $gold, 'purity_id' => (string) $p22,
    'unit_id' => (string) $tola, 'track_mode' => 'weight', 'gross_weight' => '1.5', 'stone_weight' => '0.2',
    'wastage_pct' => '1', 'making_charge_basis' => 'default', 'making_charge_rate' => '800',
    'stone_value' => '0', 'vat_base' => 'making_only', 'vat_applicable' => '1',
    'hs_code' => '7113', 'hallmark' => 'HM1', 'design_no' => 'D-1', 'reorder_weight' => '0.5',
    'active' => '1', 'notes' => 'test']);
ok($r['kind'] === 'SUCCESS', 'save_item (insert) — ' . $r['msg']);
$itemId = $q("SELECT id FROM inventory_items WHERE company_id=$cid AND sku='RING22'");
ok($itemId > 0, 'The item row exists');

$r = $run(['action' => 'save_item', 'back_view' => 'items', 'item_id' => (string) $itemId, 'code' => 'RING22',
    'name' => '22K Ring (edited)', 'item_type' => 'ornament', 'metal_id' => (string) $gold, 'purity_id' => (string) $p22,
    'unit_id' => (string) $tola, 'gross_weight' => '1.5', 'stone_weight' => '0.2', 'active' => '1']);
ok($r['kind'] === 'SUCCESS', 'save_item (update) — ' . $r['msg']);
ok($q("SELECT COUNT(*) FROM inventory_items WHERE company_id=$cid AND name='22K Ring (edited)'") === 1, 'save_item update actually persisted');

$r = $run(['action' => 'save_item', 'back_view' => 'items', 'code' => 'BAD', 'name' => 'Bad',
    'metal_id' => (string) $gold, 'purity_id' => (string) $pdId, 'unit_id' => (string) $tola, 'gross_weight' => '1']);
ok($r['kind'] === 'ERROR', 'save_item rejects a purity from another metal — ' . $r['msg']);

// Opening stock now writes the SHARED item master and posts in one step.
$r = $run(['action' => 'save_opening', 'back_view' => 'opening', 'item_id' => (string) $itemId,
    'qty_pieces' => '5', 'gross_weight' => '12', 'amount' => '1700000']);
ok($r['kind'] === 'SUCCESS', 'save_opening posts in one step — ' . $r['msg']);
ok($q("SELECT COUNT(*) FROM inventory_items WHERE id=$itemId AND opening_qty=12.000 AND opening_amount=1700000.00") === 1,
    'It wrote the opening onto the shared item master');
ok($q("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid AND source_type='inventory_opening'") === 1,
    'And created ONE shared inventory_opening voucher');

// Re-saving must CORRECT the opening, not stack a second voucher — the whole
// reason the jewellery opening table was merged away.
$r = $run(['action' => 'save_opening', 'back_view' => 'opening', 'item_id' => (string) $itemId,
    'qty_pieces' => '6', 'gross_weight' => '14', 'amount' => '1900000']);
ok($r['kind'] === 'SUCCESS', 'Re-saving corrects the opening — ' . $r['msg']);
ok($q("SELECT COUNT(*) FROM inventory_items WHERE id=$itemId AND opening_qty=14.000") === 1, 'The revision actually persisted');
ok($q("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid AND source_type='inventory_opening'") === 1,
    'Still exactly ONE opening voucher, not two');
ok($q("SELECT COUNT(*) FROM jewellery_stock_txns WHERE company_id=$cid AND item_id=$itemId AND txn_type='opening'") === 1,
    'And exactly ONE opening metal movement');

$r = $run(['action' => 'clear_opening', 'back_view' => 'opening', 'item_id' => (string) $itemId]);
ok($r['kind'] === 'SUCCESS', 'clear_opening — ' . $r['msg']);
ok($q("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid AND source_type='inventory_opening'") === 0, 'Its voucher was removed');
ok($q("SELECT COUNT(*) FROM inventory_items WHERE id=$itemId AND opening_qty=0.000") === 1, 'And the shared master is zeroed');

// --- Workshop: one advance paid several ways at once ------------------------
// The engine test proves the maths; this proves the WIRING — the tender grid's
// parallel arrays crossing the real POST path into one mixed settlement.
db()->prepare("INSERT INTO ledger_groups (company_id,name,code,master_key) VALUES (:cid,'JW Liab','JWGL','current_liability')")->execute(['cid' => $cid]);
$gl = (int) db()->lastInsertId();
db()->prepare("INSERT INTO ledgers (company_id,group_id,name,code,type) VALUES (:cid,:g,'Customer Advances','JWADV','liability')")->execute(['cid' => $cid, 'g' => $gl]);
$ldgAdvance = (int) db()->lastInsertId();
db()->prepare("INSERT INTO ledgers (company_id,group_id,name,code) VALUES (:cid,:g,'Till','JWCSH')")->execute(['cid' => $cid, 'g' => $ga]);
$ldgCash = (int) db()->lastInsertId();
db()->prepare("INSERT INTO ledgers (company_id,group_id,name,code) VALUES (:cid,:g,'Fonepay','JWQR')")->execute(['cid' => $cid, 'g' => $ga]);
$ldgQr = (int) db()->lastInsertId();
jewellery_save_mapping($cid, 'customer_advance', $ldgAdvance, $adminId);
jewellery_save_mapping($cid, 'tender_qr', $ldgQr, $adminId);

db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:c,'PCUS','Counter Customer','customer','active')")
    ->execute(['c' => $cid]);
$postCustomer = (int) db()->lastInsertId();
$postOrder = jewellery_save_order($cid, $fyId, [
    'order_date' => '2026-08-01', 'party_id' => $postCustomer, 'item_id' => $itemId,
    'metal_id' => $gold, 'purity_id' => $p22, 'unit_id' => $tola,
    'expected_gross_weight' => 2, 'making_basis' => 'flat', 'making_rate' => 5000, 'status' => 'confirmed',
], [], $adminId);

$r = $run(['action' => 'save_advance', 'back_view' => 'orders', 'order_id' => (string) $postOrder,
    'advance_date' => '2026-08-01',
    'tender_mode' => ['cash', 'qr', 'metal'],
    'tender_label' => ['', '', ''],
    'tender_reference' => ['', 'FP-1122', ''],
    'tender_amount' => ['20000', '15000', '15000'],
    'tender_ledger_id' => [(string) $ldgCash, '0', '0'],
    'tender_item_id' => ['0', '0', (string) $itemId],
    'tender_purity_id' => ['0', '0', (string) $p22],
    'tender_unit_id' => ['0', '0', (string) $tola],
    'tender_gross_weight' => ['0', '0', '0.1'],
], 'jewellery-workshop.php');
ok($r['kind'] === 'SUCCESS', 'save_advance takes cash + Fonepay + old gold as ONE payment — ' . $r['msg']);
$mixedId = $q("SELECT id FROM jewellery_settlements WHERE company_id=$cid AND order_id=$postOrder AND is_advance=1");
ok($mixedId > 0 && $q("SELECT COUNT(*) FROM jewellery_settlements WHERE id=$mixedId AND mode='mixed' AND amount=50000.00 AND status='posted'") === 1,
    "It is ONE posted settlement of 50,000, reading 'mixed'");
ok($q("SELECT COUNT(*) FROM jewellery_settlement_tenders WHERE settlement_id=$mixedId") === 3,
    'With all three ways of paying on record');
ok($q("SELECT COUNT(*) FROM jewellery_settlement_tenders WHERE settlement_id=$mixedId AND mode='metal' AND stock_txn_id IS NOT NULL") === 1,
    'And the old gold really moved stock');

$r = $run(['action' => 'refund_advance', 'back_view' => 'orders', 'order_id' => (string) $postOrder,
    'advance_date' => '2026-08-02',
    'tender_mode' => ['cash'], 'tender_label' => [''], 'tender_reference' => [''],
    'tender_amount' => ['5000'], 'tender_ledger_id' => [(string) $ldgCash],
    'tender_item_id' => ['0'], 'tender_purity_id' => ['0'], 'tender_unit_id' => ['0'],
    'tender_gross_weight' => ['0'],
], 'jewellery-workshop.php');
ok($r['kind'] === 'SUCCESS', 'refund_advance hands part of it back through the same grid — ' . $r['msg']);
ok($q("SELECT COUNT(*) FROM jewellery_settlements WHERE company_id=$cid AND order_id=$postOrder AND is_advance=1 AND direction='paid' AND mode='cash' AND amount=5000.00") === 1,
    "A single-row grid stores its real mode, not 'mixed'");

$r = $run(['action' => 'save_advance', 'back_view' => 'orders', 'order_id' => (string) $postOrder,
    'advance_date' => '2026-08-03',
    'tender_mode' => ['cash'], 'tender_label' => [''], 'tender_reference' => [''],
    'tender_amount' => ['0'], 'tender_ledger_id' => [(string) $ldgCash],
    'tender_item_id' => ['0'], 'tender_purity_id' => ['0'], 'tender_unit_id' => ['0'],
    'tender_gross_weight' => ['0'],
], 'jewellery-workshop.php');
ok($r['kind'] === 'ERROR', 'An empty grid takes no money — ' . $r['msg']);

$r = $run(['action' => 'save_advance', 'back_view' => 'orders', 'order_id' => (string) $postOrder,
    'advance_date' => '2026-08-03',
    'tender_mode' => ['cash'], 'tender_label' => [''], 'tender_reference' => [''],
    'tender_amount' => ['1000'], 'tender_ledger_id' => ['0'],
    'tender_item_id' => ['0'], 'tender_purity_id' => ['0'], 'tender_unit_id' => ['0'],
    'tender_gross_weight' => ['0'],
], 'jewellery-workshop.php');
ok($r['kind'] === 'ERROR', 'Cash without naming the till is refused on the POST path too — ' . $r['msg']);

// --- Trade: the advance picker's rows cross the POST path -------------------
// The customer above holds the 50,000 mixed advance (5,000 of it refunded)
// and the 25,000 cheque advance. A bill is drafted drawing 10,000 from the
// mixed entry — the parallel arrays the picker posts must arrive as one
// allocation, and the sale's advance figure must be their sum.
$r = $run(['action' => 'save_sale', 'back_view' => 'sales',
    'sale_date' => '2026-08-10', 'party_id' => (string) $postCustomer, 'settle_mode' => 'credit',
    'l_item_id' => [(string) $itemId], 'l_qty_pieces' => ['1'],
    'l_purity_id' => [(string) $p22], 'l_unit_id' => [(string) $tola],
    'l_gross_weight' => ['1'], 'l_rate' => ['100000'],
    'adv_alloc_id' => [(string) $mixedId], 'adv_alloc_amount' => ['10000'],
], 'jewellery-trade.php');
ok($r['kind'] === 'SUCCESS', 'save_sale with a ticked advance entry — ' . $r['msg']);
$pickedSale = $q("SELECT id FROM jewellery_sales WHERE company_id=$cid AND party_id=$postCustomer ORDER BY id DESC LIMIT 1");
ok($q("SELECT COUNT(*) FROM jewellery_advance_allocations WHERE company_id=$cid
        AND sale_id=$pickedSale AND settlement_id=$mixedId AND amount=10000.00") === 1,
    'The ticked entry arrived as one allocation row');
ok($q("SELECT COUNT(*) FROM jewellery_sales WHERE id=$pickedSale AND advance_amount=10000.00") === 1,
    'And the sale\'s advance figure is the sum of what was ticked');

$r = $run(['action' => 'save_sale', 'back_view' => 'sales',
    'sale_date' => '2026-08-10', 'party_id' => (string) $postCustomer, 'settle_mode' => 'credit',
    'l_item_id' => [(string) $itemId], 'l_qty_pieces' => ['1'],
    'l_purity_id' => [(string) $p22], 'l_unit_id' => [(string) $tola],
    'l_gross_weight' => ['1'], 'l_rate' => ['100000'],
    'adv_alloc_id' => [(string) $mixedId], 'adv_alloc_amount' => ['90000'],
], 'jewellery-trade.php');
ok($r['kind'] === 'ERROR' && str_contains($r['msg'], 'left'),
    'Over-drawing an entry is refused on the POST path too — ' . $r['msg']);

// A form that never rendered the advance picker must not strip the draft's
// advance on re-save. No adv_alloc arrays at all means "the picker was not
// there", not "the user cleared it".
$r = $run(['action' => 'save_sale', 'back_view' => 'sales',
    'sale_id' => (string) $pickedSale,
    'sale_date' => '2026-08-10', 'party_id' => (string) $postCustomer, 'settle_mode' => 'credit',
    'l_item_id' => [(string) $itemId], 'l_qty_pieces' => ['1'],
    'l_purity_id' => [(string) $p22], 'l_unit_id' => [(string) $tola],
    'l_gross_weight' => ['1'], 'l_rate' => ['100000'],
], 'jewellery-trade.php');
ok($r['kind'] === 'SUCCESS', 'Re-saving the draft without the picker — ' . $r['msg']);
ok($q("SELECT COUNT(*) FROM jewellery_sales WHERE id=$pickedSale AND advance_amount=10000.00") === 1,
    'The advance survives: no picker on the form is not the user clearing it');
ok($q("SELECT COUNT(*) FROM jewellery_advance_allocations WHERE sale_id=$pickedSale AND amount=10000.00") === 1,
    'And its allocation row survives with it');

cleanup();
echo "\n==================================================\n";
echo "  PASS: $pass    FAIL: $fail\n";
echo "==================================================\n";
exit($fail === 0 ? 0 : 1);
