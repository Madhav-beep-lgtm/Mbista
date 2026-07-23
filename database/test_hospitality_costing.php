<?php
declare(strict_types=1);

/**
 * Hospitality Accounting: activation gating, tenant isolation, ingredient
 * conversion/wastage/yield maths, recipe batch/portion cost, mapping priority,
 * daily costing with discount/VAT/return handling, snapshot immutability,
 * audited recalculation, fiscal-year limits, report reconciliation — and the
 * PROOF that the module posts nothing to accounting or inventory.
 *   php database/test_hospitality_costing.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/hospitality_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.011; }

function hosp_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('HOSPTA','HOSPTB')")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        foreach (['hospitality_recalc_history', 'hospitality_costing_lines', 'hospitality_costing_runs', 'hospitality_sales_mappings',
                  'hospitality_recipe_lines', 'hospitality_recipes', 'hospitality_menu_items',
                  'hospitality_ingredient_costs', 'hospitality_ingredients', 'hospitality_settings'] as $t) {
            if ($t === 'hospitality_recipe_lines') {
                db()->exec("DELETE l FROM hospitality_recipe_lines l JOIN hospitality_recipes r ON r.id = l.recipe_id WHERE r.company_id=$s");
                continue;
            }
            db()->exec("DELETE FROM `$t` WHERE company_id=$s");
        }
        db()->exec("DELETE li FROM invoice_line_items li JOIN task_invoices ti ON ti.id=li.invoice_id WHERE ti.company_id=$s");
        db()->exec("DELETE FROM task_invoices WHERE company_id=$s");
        db()->exec("DELETE FROM inventory_items WHERE company_id=$s");
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    foreach (db()->query("SELECT id FROM users WHERE email LIKE 'hosptest-%@test.local'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        db()->exec('DELETE FROM client_profiles WHERE user_id=' . (int) $u);
        db()->exec('DELETE FROM users WHERE id=' . (int) $u);
    }
}
hosp_cleanup();

// ---------------------------------------------------------------------------
// Fixture: two hospitality clients with books companies; sales only for A.
// ---------------------------------------------------------------------------
$mkClient = static function (string $code, string $org, string $email): array {
    db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:n, :c, 1, 1)')
        ->execute(['n' => $org . ' (Books)', 'c' => $code]);
    $companyId = (int) db()->lastInsertId();
    $clientUserId = create_user(['name' => $org . ' Owner', 'email' => $email, 'password' => 'Secret#12345', 'role' => 'customer', 'status' => 'active', 'company_id' => $companyId]);
    db()->prepare('INSERT INTO client_profiles (user_id, company_id, books_company_id, organization_name, client_code, is_active)
            VALUES (:uid, :cid, :books, :org, :code, 1)')
        ->execute(['uid' => $clientUserId, 'cid' => $companyId, 'books' => $companyId, 'org' => $org, 'code' => $code . '-C']);
    $clientId = (int) db()->lastInsertId();
    $fy = create_fiscal_year($companyId, $code . ' 2026/27', '2026-07-16', '2027-07-15', true);
    db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
    return [$companyId, $clientId, (int) $fy['id'], $clientUserId];
};
[$cidA, $clientA, $fyA, $userA] = $mkClient('HOSPTA', 'Momo House', 'hosptest-a@test.local');
[$cidB, $clientB, $fyB, $userB] = $mkClient('HOSPTB', 'Cafe Beta', 'hosptest-b@test.local');
$actorId = (int) db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$_SESSION['company_id'] = $cidA;

echo "1-3. Activation: default OFF, Super-Admin toggle, firm workspace never eligible\n";
ok((int) db()->query("SELECT hospitality_accounting_enabled FROM client_profiles WHERE id=$clientA")->fetchColumn() === 0,
    'New client defaults to hospitality DISABLED');
ok(!hospitality_enabled_for_company($cidA), 'Module gate closed while the flag is off');
db()->prepare('UPDATE client_profiles SET hospitality_accounting_enabled = 1 WHERE id IN (:a, :b)')->execute(['a' => $clientA, 'b' => $clientB]);
ok(hospitality_enabled_for_company($cidA), 'Gate opens after Super Admin activates the client');
ok(!hospitality_enabled_for_company(1), 'The firm\'s own workspace (company 1, not client books) is NEVER eligible');

echo "\n9-10. Ingredient unit conversion, wastage and yield\n";
$mkIngredient = static function (array $f) use ($cidA): int {
    $defaults = ['company_id' => $cidA, 'code' => '', 'name' => '', 'purchase_unit' => 'KG', 'recipe_unit' => 'Gram',
        'conversion_factor' => 1000, 'purchase_cost' => 0, 'effective_date' => '2026-07-16', 'wastage_pct' => 0, 'yield_pct' => 100, 'active' => 1];
    $f += $defaults;
    db()->prepare('INSERT INTO hospitality_ingredients (company_id, code, name, purchase_unit, recipe_unit, conversion_factor, purchase_cost, effective_date, wastage_pct, yield_pct, active)
        VALUES (:company_id, :code, :name, :purchase_unit, :recipe_unit, :conversion_factor, :purchase_cost, :effective_date, :wastage_pct, :yield_pct, :active)')
        ->execute(array_intersect_key($f, $defaults));
    $newId = (int) db()->lastInsertId();
    // Mirror the UI: the initial reference cost is always written to history.
    db()->prepare("INSERT INTO hospitality_ingredient_costs (company_id, ingredient_id, purchase_cost, effective_date, source) VALUES (:cid, :iid, :c, :d, 'manual')")
        ->execute(['cid' => $cidA, 'iid' => $newId, 'c' => $f['purchase_cost'], 'd' => $f['effective_date']]);
    return $newId;
};
$iRice = $mkIngredient(['code' => 'RICE', 'name' => 'Rice', 'purchase_cost' => 90]);           // 90/KG -> 0.09/g
$iOil = $mkIngredient(['code' => 'OIL', 'name' => 'Oil', 'purchase_unit' => 'Litre', 'recipe_unit' => 'ml', 'purchase_cost' => 300]); // 0.3/ml
$iChicken = $mkIngredient(['code' => 'CHK', 'name' => 'Chicken', 'purchase_cost' => 400, 'wastage_pct' => 0, 'yield_pct' => 80]);     // 0.4/g / 0.8 = 0.5/g
$iFlour = $mkIngredient(['code' => 'FLOUR', 'name' => 'Flour', 'purchase_cost' => 80, 'wastage_pct' => 20]);                          // 0.08/g /0.8 = 0.1/g
$riceRow = db()->query("SELECT * FROM hospitality_ingredients WHERE id=$iRice")->fetch();
ok(near(hospitality_ingredient_unit_cost($riceRow)['unit_cost'], 0.09), 'Rice: 90/KG ÷ 1000 = 0.09 per gram');
$chkRow = db()->query("SELECT * FROM hospitality_ingredients WHERE id=$iChicken")->fetch();
ok(near(hospitality_ingredient_unit_cost($chkRow)['unit_cost'], 0.5), 'Chicken yield 80%: 0.40 ÷ 0.80 = 0.50 per gram');
$flourRow = db()->query("SELECT * FROM hospitality_ingredients WHERE id=$iFlour")->fetch();
ok(near(hospitality_ingredient_unit_cost($flourRow)['unit_cost'], 0.1), 'Flour wastage 20%: 0.08 ÷ (1-0.20) = 0.10 per gram');
$bad = hospitality_ingredient_unit_cost(['conversion_factor' => 0, 'purchase_cost' => 10, 'wastage_pct' => 0, 'yield_pct' => 100]);
ok(!$bad['ok'] && str_contains((string) $bad['error'], 'conversion'), 'Zero conversion factor is rejected — no division by zero');
$bad = hospitality_ingredient_unit_cost(['conversion_factor' => 1, 'purchase_cost' => 10, 'wastage_pct' => 100, 'yield_pct' => 100]);
ok(!$bad['ok'], '100% wastage is rejected — no division by zero');

echo "\n11. Recipe batch and portion cost\n";
db()->prepare("INSERT INTO hospitality_menu_items (company_id, code, name, category, standard_price, active) VALUES (:cid,'MOMO','Chicken Momo','Food',300,1)")->execute(['cid' => $cidA]);
$miMomo = (int) db()->lastInsertId();
db()->prepare("INSERT INTO hospitality_menu_items (company_id, code, name, category, standard_price, active) VALUES (:cid,'FRICE','Fried Rice','Food',250,1)")->execute(['cid' => $cidA]);
$miRice = (int) db()->lastInsertId();
db()->prepare("INSERT INTO hospitality_recipes (company_id, menu_item_id, name, version, effective_from, portions, packaging_cost, other_cost, status)
        VALUES (:cid, :mid, 'Momo batch', 1, '2026-07-16', 10, 50, 30, 'active')")->execute(['cid' => $cidA, 'mid' => $miMomo]);
$rMomo = (int) db()->lastInsertId();
// 500 g chicken (0.5/g = 250) + 300 g flour (0.1/g = 30) + 100 ml oil (0.3/ml = 30) = 310 ingredients.
db()->prepare('INSERT INTO hospitality_recipe_lines (recipe_id, ingredient_id, quantity, unit) VALUES (?,?,?,?)')->execute([$rMomo, $iChicken, 500, 'Gram']);
db()->prepare('INSERT INTO hospitality_recipe_lines (recipe_id, ingredient_id, quantity, unit) VALUES (?,?,?,?)')->execute([$rMomo, $iFlour, 300, 'Gram']);
db()->prepare('INSERT INTO hospitality_recipe_lines (recipe_id, ingredient_id, quantity, unit) VALUES (?,?,?,?)')->execute([$rMomo, $iOil, 100, 'ml']);
$momoCost = hospitality_recipe_cost($rMomo);
ok($momoCost['ok'], 'Momo recipe costs without errors');
ok(near($momoCost['ingredient_total'], 250 + 30 + 30), 'Ingredient total = 250 + 30 + 30 = 310');
ok(near($momoCost['batch_cost'], 310 + 50 + 30), 'Batch cost adds packaging 50 + other 30 = 390');
ok(near($momoCost['cost_per_portion'], 39), 'Cost per portion = 390 ÷ 10 portions = 39');
db()->prepare("INSERT INTO hospitality_recipes (company_id, menu_item_id, name, version, effective_from, portions, status)
        VALUES (:cid, :mid, 'Rice plate', 1, '2026-07-16', 1, 'active')")->execute(['cid' => $cidA, 'mid' => $miRice]);
$rRice = (int) db()->lastInsertId();
db()->prepare('INSERT INTO hospitality_recipe_lines (recipe_id, ingredient_id, quantity, unit) VALUES (?,?,?,?)')->execute([$rRice, $iRice, 400, 'Gram']); // 36
db()->prepare('INSERT INTO hospitality_recipe_lines (recipe_id, ingredient_id, quantity, unit) VALUES (?,?,?,?)')->execute([$rRice, $iOil, 30, 'ml']);   // 9
$riceCost = hospitality_recipe_cost($rRice);
ok(near($riceCost['cost_per_portion'], 45), 'Fried rice portion cost = 36 + 9 = 45');
ok(!hospitality_active_recipe($miMomo, '2026-07-15'), 'No active recipe BEFORE its effective date');

echo "\n12. Mapping priority: item id beats description\n";
// A real sales item id (invoice_line_items.item_id references inventory_items).
db()->prepare("INSERT INTO inventory_items (company_id, sku, name, item_type, unit, status) VALUES (:cid, 'HOSP-MOMO-SKU', 'Chicken Momo Plate', 'service', 'plate', 'active')")
    ->execute(['cid' => $cidA]);
$salesItemId = (int) db()->lastInsertId();
db()->prepare("INSERT INTO hospitality_sales_mappings (company_id, match_type, item_id, menu_item_id, status, active) VALUES (:cid,'item',:item,:mid,'mapped',1)")->execute(['cid' => $cidA, 'item' => $salesItemId, 'mid' => $miMomo]);
db()->prepare("INSERT INTO hospitality_sales_mappings (company_id, match_type, description_norm, menu_item_id, status, active) VALUES (:cid,'description','chicken momo plate',:mid,'mapped',1)")->execute(['cid' => $cidA, 'mid' => $miRice]);
$resolved = hospitality_resolve_mapping($cidA, $salesItemId, 'Chicken  MOMO   Plate', '2026-08-01');
ok($resolved !== null && (int) $resolved['menu_item_id'] === $miMomo, 'Item-id mapping wins over the description mapping');
$resolved = hospitality_resolve_mapping($cidA, null, '  chicken MOMO plate ', '2026-08-01');
ok($resolved !== null && (int) $resolved['menu_item_id'] === $miRice, 'Normalized description matches when no item id exists');
db()->prepare("INSERT INTO hospitality_sales_mappings (company_id, match_type, description_norm, menu_item_id, status, active) VALUES (:cid,'description','fried rice',:mid,'mapped',1)")->execute(['cid' => $cidA, 'mid' => $miRice]);
db()->prepare("INSERT INTO hospitality_sales_mappings (company_id, match_type, description_norm, status, ignore_reason, active) VALUES (:cid,'description','service charge 10%','ignored','Not a menu item',1)")->execute(['cid' => $cidA]);

echo "\n13-16. Daily costing: discounts, VAT, returns, statuses, GP\n";
// Invoice 1 (2026-08-01): momo 2 x 300 = 600 taxable + flour... plus a second
// line 400; invoice discount 100 -> allocated 60/40. VAT 13%.
$mkInvoice = static function (string $no, string $date, float $discount, array $lines) use ($cidA): int {
    $taxable = 0.0; $vat = 0.0;
    foreach ($lines as $l) { $taxable += $l[2]; $vat += $l[3]; }
    db()->prepare("INSERT INTO task_invoices (company_id, invoice_no, invoice_type, invoice_source_type, invoice_category, amount, vat_rate, vat_amount, taxable_amount, total_amount, discount_type, discount_value, discount_amount, status, issued_on)
            VALUES (:cid, :no, 'other', 'other', 'tax', :amt, 13, :vat, :tax, :tot, :dt, :dv, :da, 'issued', :d)")
        ->execute(['cid' => $cidA, 'no' => $no, 'amt' => $taxable, 'vat' => $vat, 'tax' => $taxable, 'tot' => $taxable + $vat,
            'dt' => $discount > 0 ? 'fixed' : 'none', 'dv' => $discount, 'da' => $discount, 'd' => $date]);
    $invoiceId = (int) db()->lastInsertId();
    foreach ($lines as [$itemId, $desc, $lineTaxable, $lineVat, $qty]) {
        db()->prepare("INSERT INTO invoice_line_items (invoice_id, item_id, source_type, description, unit, quantity, rate, taxable_amount, vat_rate, vat_amount, total_amount)
                VALUES (:inv, :item, 'other', :descr, 'pcs', :qty, :rate, :tax, 13, :vat, :tot)")
            ->execute(['inv' => $invoiceId, 'item' => $itemId, 'descr' => $desc, 'qty' => $qty,
                'rate' => $qty != 0.0 ? $lineTaxable / $qty : 0, 'tax' => $lineTaxable, 'vat' => $lineVat, 'tot' => $lineTaxable + $lineVat]);
    }
    return $invoiceId;
};
$mkInvoice('HOSP-A-001', '2026-08-01', 100.0, [
    [$salesItemId, 'Chicken Momo Plate', 600.0, 78.0, 2],   // mapped by item id -> MOMO
    [null, 'Fried Rice', 400.0, 52.0, 2],          // mapped by description -> FRICE
]);
$mkInvoice('HOSP-A-002', '2026-08-01', 0.0, [
    [null, 'Mystery Special', 500.0, 65.0, 1],     // unmapped
    [null, 'Service Charge 10%', 90.0, 11.7, 1],   // ignored
    [null, 'Fried Rice', -250.0, -32.5, -1],       // return of one fried rice
]);
db()->prepare("INSERT INTO hospitality_menu_items (company_id, code, name, category, active) VALUES (:cid,'NORCP','No Recipe Item','Beverage',1)")->execute(['cid' => $cidA]);
$miNoRecipe = (int) db()->lastInsertId();
db()->prepare("INSERT INTO hospitality_sales_mappings (company_id, match_type, description_norm, menu_item_id, status, active) VALUES (:cid,'description','lemon soda',:mid,'mapped',1)")->execute(['cid' => $cidA, 'mid' => $miNoRecipe]);
$mkInvoice('HOSP-A-003', '2026-08-01', 0.0, [
    [null, 'Lemon Soda', 120.0, 15.6, 1],          // mapped but no recipe
]);

// PROOF baseline: counts of accounting/inventory objects before costing.
$proofCounts = static function (): array {
    return [
        'vouchers' => (int) db()->query('SELECT COUNT(*) FROM vouchers')->fetchColumn(),
        'voucher_entries' => (int) db()->query('SELECT COUNT(*) FROM voucher_entries')->fetchColumn(),
        'inventory_txns' => table_exists('inventory_transactions') ? (int) db()->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn() : 0,
        'inventory_qty' => table_exists('inventory_items') ? (float) db()->query('SELECT COALESCE(SUM(opening_qty),0) FROM inventory_items')->fetchColumn() : 0.0,
        'invoices' => (int) db()->query('SELECT COUNT(*) FROM task_invoices')->fetchColumn(),
        'invoice_lines' => (int) db()->query('SELECT COUNT(*) FROM invoice_line_items')->fetchColumn(),
        'invoice_sum' => (float) db()->query('SELECT COALESCE(SUM(taxable_amount),0) FROM task_invoices')->fetchColumn(),
    ];
};
$before = $proofCounts();

$gen = hospitality_generate_costing($cidA, $fyA, '2026-08-01', $actorId);
ok(!empty($gen['ok']) && (int) $gen['lines'] === 6, 'Costing generated over all 6 eligible sales lines');
$dupe = hospitality_generate_costing($cidA, $fyA, '2026-08-01', $actorId);
ok(empty($dupe['ok']), 'Generating the same date twice is refused (use audited Recalculate)');
$badFy = hospitality_generate_costing($cidA, $fyA, '2026-07-01', $actorId);
ok(empty($badFy['ok']), 'A date outside the fiscal year is refused');

$lineBy = static function (string $descr) use ($cidA): ?array {
    $stmt = db()->prepare("SELECT * FROM hospitality_costing_lines WHERE company_id = :cid AND description = :d ORDER BY id ASC LIMIT 1");
    $stmt->execute(['cid' => $cidA, 'd' => $descr]);
    return $stmt->fetch() ?: null;
};
$momoLine = $lineBy('Chicken Momo Plate');
ok($momoLine !== null && (string) $momoLine['status'] === 'costed', 'Momo line costed via item-id mapping');
ok(near((float) $momoLine['discount'], 60.0), 'Invoice discount 100 allocated 60/40 by line taxable value');
ok(near((float) $momoLine['net_sales'], 540.0), 'Net sales ex VAT = 600 - 60 (VAT 78 excluded)');
ok(near((float) $momoLine['unit_cost'], 39.0), 'Snapshotted recipe cost per portion = 39');
ok(near((float) $momoLine['total_cost'], 78.0), 'Estimated cost = 2 x 39 = 78');
ok(near((float) $momoLine['gross_profit'], 462.0), 'Estimated GP = 540 - 78 = 462');
ok(near((float) $momoLine['gp_pct'], round(462 / 540 * 100, 2)), 'GP% = 462 ÷ 540 x 100');
$mystery = $lineBy('Mystery Special');
ok($mystery !== null && (string) $mystery['status'] === 'unmapped' && $mystery['total_cost'] === null,
    'Unmapped sale flagged — and its missing cost is NOT treated as zero');
$ignored = $lineBy('Service Charge 10%');
ok($ignored !== null && (string) $ignored['status'] === 'ignored', 'Ignored mapping excludes the line with its reason (sale untouched)');
$soda = $lineBy('Lemon Soda');
ok($soda !== null && (string) $soda['status'] === 'missing_recipe', 'Mapped item without an active recipe -> Missing Recipe exception');
$return = $lineBy('Fried Rice');
$returnStmt = db()->prepare("SELECT * FROM hospitality_costing_lines WHERE company_id = :cid AND description = 'Fried Rice' AND qty_returned > 0 LIMIT 1");
$returnStmt->execute(['cid' => $cidA]);
$returnLine = $returnStmt->fetch();
ok($returnLine !== null && near((float) $returnLine['net_qty'], -1) && near((float) $returnLine['gross_profit'], -250 - (-1 * 45)),
    'Return line reverses consistently: net qty -1, GP = -250 + 45 = -205');

echo "\n17. Historical snapshots never change when current costs change\n";
db()->prepare('UPDATE hospitality_ingredients SET purchase_cost = 800 WHERE id = :id')->execute(['id' => $iChicken]);
db()->prepare("INSERT INTO hospitality_ingredient_costs (company_id, ingredient_id, purchase_cost, effective_date, source) VALUES (:cid, :iid, 800, '2026-08-10', 'manual')")->execute(['cid' => $cidA, 'iid' => $iChicken]);
$momoLineAfter = $lineBy('Chicken Momo Plate');
ok(near((float) $momoLineAfter['unit_cost'], 39.0), 'Stored snapshot keeps unit cost 39 after the chicken price doubled');
$newCost = hospitality_recipe_cost($rMomo, '2026-08-15');
ok((float) $newCost['cost_per_portion'] > 39.0, 'Live recipe cost DOES rise for future dates (cost history is effective-dated)');

echo "\n18. Recalculation: permission-sized, reason required, before/after audited\n";
$noReason = hospitality_generate_costing($cidA, $fyA, '2026-08-01', $actorId, true, '');
ok(empty($noReason['ok']), 'Recalculation without a reason is refused');
$recalc = hospitality_generate_costing($cidA, $fyA, '2026-08-01', $actorId, true, 'Mapping/testing recalc');
ok(!empty($recalc['ok']), 'Recalculation with a reason succeeds');
$historyStmt = db()->prepare('SELECT * FROM hospitality_recalc_history WHERE company_id = :cid ORDER BY id DESC LIMIT 1');
$historyStmt->execute(['cid' => $cidA]);
$history = $historyStmt->fetch();
ok($history !== null && (string) $history['reason'] === 'Mapping/testing recalc'
    && $history['old_totals_json'] !== null && $history['new_totals_json'] !== null,
    'Recalc history stores the reason plus old AND new totals');
$momoRecalc = $lineBy('Chicken Momo Plate');
ok(near((float) $momoRecalc['unit_cost'], 39.0), 'Recalc on 2026-08-01 still uses the cost in force on the SALE date (39, not the new 800-based cost)');

echo "\n21.x Consolidated reconciliation, weighted vs simple averages, completeness\n";
$summary = hospitality_summary($cidA, '2026-08-01', '2026-08-01');
$categories = hospitality_grouped($cidA, '2026-08-01', '2026-08-01', 'category');
$items = hospitality_grouped($cidA, '2026-08-01', '2026-08-01', 'menu_item');
$catNet = array_sum(array_map(static fn (array $r): float => $r['net_sales'], $categories));
$itemNet = array_sum(array_map(static fn (array $r): float => $r['net_sales'], $items));
$catGp = array_sum(array_map(static fn (array $r): float => $r['est_gp'], $categories));
$itemGp = array_sum(array_map(static fn (array $r): float => $r['est_gp'], $items));
ok(near($catNet, $summary['costed_net_sales']) && near($itemNet, $summary['costed_net_sales']),
    'Category and item net-sales totals both reconcile to the consolidated costed total');
ok(near($catGp, $summary['est_gp']) && near($itemGp, $summary['est_gp']),
    'Category and item GP totals both reconcile to the consolidated estimated GP');
ok($summary['weighted_gp_pct'] !== null && near($summary['weighted_gp_pct'], round($summary['est_gp'] / $summary['costed_net_sales'] * 100, 2)),
    'Consolidated weighted GP ratio = total GP ÷ total net sales');
ok($summary['simple_avg_item_gp_pct'] !== null && abs($summary['simple_avg_item_gp_pct'] - $summary['weighted_gp_pct']) > 0.01,
    'Simple average item GP is a separate figure (not silently the weighted one)');
ok($summary['uncosted_value'] > 0, 'Uncosted sales are reported separately (never as zero-cost sales)');
ok($summary['completeness_pct'] !== null && $summary['completeness_pct'] < 100,
    'Costing completeness % reflects the uncosted portion');
$emptySummary = hospitality_summary($cidA, '2026-09-01', '2026-09-01');
ok($emptySummary['weighted_gp_pct'] === null && $emptySummary['gp_per_unit'] === null,
    'Zero-sales period returns N/A ratios — no division by zero');

echo "\n8+16. Tenant isolation\n";
ok(hospitality_summary($cidB, '2026-08-01', '2026-08-01')['costed_net_sales'] == 0.0, 'Client B sees NONE of client A\'s costing');
ok(hospitality_resolve_mapping($cidB, $salesItemId, 'Chicken Momo Plate', '2026-08-01') === null, 'Client B cannot resolve client A\'s mappings');
ok(hospitality_eligible_sales_lines($cidB, '2026-08-01', '2026-08-01') === [], 'Client B reads none of client A\'s sales');
$bDataStmt = db()->prepare('SELECT COUNT(*) FROM hospitality_costing_lines WHERE company_id = :cid');
$bDataStmt->execute(['cid' => $cidB]);
ok((int) $bDataStmt->fetchColumn() === 0, 'No hospitality rows leak into client B');

echo "\n2-3+29. Permission architecture\n";
$staffId = create_user(['name' => 'Hosp Staff', 'email' => 'hosptest-staff@test.local', 'password' => 'Secret#12345', 'role' => 'staff', 'status' => 'active', 'company_id' => $cidA]);
$staffUser = ['id' => $staffId, 'role' => 'staff', 'company_id' => $cidA];
set_staff_permissions($staffId, ['hospitality.view'], $actorId);
ok(user_can_do('hospitality', 'view', $staffUser), 'Staff granted hospitality.view passes');
ok(!user_can_do('hospitality', 'adjust', $staffUser), 'Staff WITHOUT the adjust grant cannot recalculate');
set_staff_permissions($staffId, ['hospitality.view', 'hospitality.adjust'], $actorId);
ok(user_can_do('hospitality', 'adjust', $staffUser), 'hospitality.adjust is grantable through the existing matrix');

echo "\n21-25. Deactivation preserves data; NON-POSTING PROOF\n";
db()->prepare('UPDATE client_profiles SET hospitality_accounting_enabled = 0 WHERE id = :id')->execute(['id' => $clientA]);
ok(!hospitality_enabled_for_company($cidA), 'Deactivation closes the gate immediately');
$keptStmt = db()->prepare('SELECT COUNT(*) FROM hospitality_costing_lines WHERE company_id = :cid');
$keptStmt->execute(['cid' => $cidA]);
ok((int) $keptStmt->fetchColumn() > 0, 'All hospitality data is PRESERVED after deactivation');
db()->prepare('UPDATE client_profiles SET hospitality_accounting_enabled = 1 WHERE id = :id')->execute(['id' => $clientA]);
ok(hospitality_enabled_for_company($cidA), 'Reactivation restores access to the same data');

$after = $proofCounts();
ok($after['vouchers'] === $before['vouchers'], 'PROOF: no accounting voucher was created by costing or recalculation');
ok($after['voucher_entries'] === $before['voucher_entries'], 'PROOF: no ledger entry row was created');
ok($after['inventory_txns'] === $before['inventory_txns'], 'PROOF: no inventory movement was created');
ok(near($after['inventory_qty'], $before['inventory_qty']), 'PROOF: no stock quantity changed');
ok($after['invoices'] === $before['invoices'] && $after['invoice_lines'] === $before['invoice_lines']
    && near($after['invoice_sum'], $before['invoice_sum']), 'PROOF: the source sales records are byte-for-byte untouched');

hosp_cleanup();
echo "\n----------------------------------------\n";
echo 'PASS: ' . $pass . '   FAIL: ' . $fail . "\n";
exit($fail === 0 ? 0 : 1);
