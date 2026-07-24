<?php
declare(strict_types=1);

/**
 * Costing runs generated from uploaded daily sales sheets: item matching,
 * per-date runs, GP math, unmatched/missing-recipe exceptions, cost history
 * on re-cost, daily/category/item reports, run deletion with audit, tenant
 * isolation, and the activation flag staying intact.
 *   php database/test_hospitality_upload_costing.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/hospitality_engine.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.011; }

function hupc_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('HUPCA','HUPCB')")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        foreach (['hospitality_recalc_history', 'hospitality_costing_lines', 'hospitality_costing_runs',
                  'hospitality_sales_upload_lines', 'hospitality_sales_uploads',
                  'hospitality_recipe_lines' => 'special', 'hospitality_recipes', 'hospitality_menu_items',
                  'hospitality_ingredient_costs', 'hospitality_ingredients', 'hospitality_settings'] as $k => $t) {
            if ($t === 'special') {
                db()->exec("DELETE l FROM hospitality_recipe_lines l JOIN hospitality_recipes r ON r.id = l.recipe_id WHERE r.company_id=$s");
                continue;
            }
            $table = is_int($k) ? $t : $k;
            db()->exec("DELETE FROM `$table` WHERE company_id=$s");
        }
        db()->exec("DELETE FROM client_profiles WHERE books_company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
    db()->exec("DELETE FROM users WHERE email LIKE 'hupctest-%@test.local'");
}
hupc_cleanup();

// Fixture: one hospitality client with books company (same shape as the UI).
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
    return [$companyId, $clientId, (int) $fy['id']];
};
[$cidA, $clientA, $fyA] = $mkClient('HUPCA', 'Upload Costing House', 'hupctest-a@test.local');
[$cidB, $clientB, $fyB] = $mkClient('HUPCB', 'Upload Costing Cafe', 'hupctest-b@test.local');
$actorId = (int) db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();

echo "== Activation feature intact ==\n";
ok((int) db()->query("SELECT hospitality_accounting_enabled FROM client_profiles WHERE id=$clientA")->fetchColumn() === 0, 'new client still defaults to hospitality DISABLED');
db()->prepare('UPDATE client_profiles SET hospitality_accounting_enabled = 1 WHERE id = :id')->execute(['id' => $clientA]);
ok(hospitality_enabled_for_company($cidA), 'Super-Admin flag still opens the gate');
ok(!hospitality_enabled_for_company($cidB), 'unflagged client stays gated');

// Ingredient 100/KG -> 0.1/Gram; recipe 200 g per single portion -> 20/portion.
db()->prepare("INSERT INTO hospitality_ingredients (company_id, code, name, purchase_unit, recipe_unit, conversion_factor, purchase_cost, effective_date, wastage_pct, yield_pct, active)
        VALUES (:cid, 'CHK', 'Chicken', 'KG', 'Gram', 1000, 100, '2026-07-16', 0, 100, 1)")->execute(['cid' => $cidA]);
$iChicken = (int) db()->lastInsertId();
db()->prepare("INSERT INTO hospitality_ingredient_costs (company_id, ingredient_id, purchase_cost, effective_date, source) VALUES (:cid, :iid, 100, '2026-07-16', 'manual')")
    ->execute(['cid' => $cidA, 'iid' => $iChicken]);
db()->prepare("INSERT INTO hospitality_menu_items (company_id, code, name, category, standard_price, active) VALUES (:cid, 'MOMO', 'Chicken Momo', 'Food', 300, 1)")->execute(['cid' => $cidA]);
$miMomo = (int) db()->lastInsertId();
db()->prepare("INSERT INTO hospitality_menu_items (company_id, code, name, category, active) VALUES (:cid, 'NORCP', 'No Recipe Item', 'Beverage', 1)")->execute(['cid' => $cidA]);
db()->prepare("INSERT INTO hospitality_recipes (company_id, menu_item_id, name, version, effective_from, portions, status)
        VALUES (:cid, :mid, 'Momo single', 1, '2026-07-16', 1, 'active')")->execute(['cid' => $cidA, 'mid' => $miMomo]);
$rMomo = (int) db()->lastInsertId();
db()->prepare('INSERT INTO hospitality_recipe_lines (recipe_id, ingredient_id, quantity, unit) VALUES (?,?,?,?)')->execute([$rMomo, $iChicken, 200, 'Gram']);

$settingsA = hospitality_settings($cidA);
$expectedUnit = (float) hospitality_recipe_cost($rMomo, '2026-08-01', $settingsA)['effective_cost_per_portion'];
ok(near((float) hospitality_recipe_cost($rMomo, '2026-08-01', $settingsA)['cost_per_portion'], 20.0), 'recipe base cost = 200 g × 0.1 = 20 per finished unit');

echo "== Match helper ==\n";
ok((int) (hospitality_match_menu_item($cidA, 'chicken momo')['id'] ?? 0) === $miMomo, 'item matches by name, case-insensitive');
ok((int) (hospitality_match_menu_item($cidA, 'MOMO')['id'] ?? 0) === $miMomo, 'item matches by code');
ok(hospitality_match_menu_item($cidA, 'Mystery Dish') === null, 'unknown names do not match');

// Upload fixture: two days, four rows (as the posting pipeline stores them).
db()->prepare("INSERT INTO hospitality_sales_uploads (company_id, fiscal_year_id, file_name, date_from, date_to, row_count, status, posted_by, posted_at)
        VALUES (:cid, :fy, 'aug-sales.xlsx', '2026-08-01', '2026-08-02', 4, 'posted', :by, NOW())")->execute(['cid' => $cidA, 'fy' => $fyA, 'by' => $actorId]);
$uploadA = (int) db()->lastInsertId();
$mkLine = static function (string $date, string $category, string $item, float $qty, float $gross, float $discount, float $vat, float $taxable) use ($uploadA, $cidA): void {
    db()->prepare('INSERT INTO hospitality_sales_upload_lines (upload_id, company_id, sale_date, category, item_name, qty, gross_amount, discount, vat_amount, taxable_amount)
            VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$uploadA, $cidA, $date, $category, $item, $qty, $gross, $discount, $vat, $taxable]);
};
$mkLine('2026-08-01', 'Food', 'Chicken Momo', 10, 3000, 100, 400, 2500);
$mkLine('2026-08-01', 'Food', 'Mystery Dish', 2, 500, 0, 57.52, 442.48);
$mkLine('2026-08-01', 'Beverage', 'No Recipe Item', 3, 300, 0, 34.51, 265.49);
$mkLine('2026-08-02', 'Food', 'Chicken Momo', 5, 1500, 0, 172.57, 1250);

echo "== Upload costing ==\n";
$result = hospitality_upload_costing($cidA, $fyA, $uploadA, $actorId);
ok($result['ok'] === true, 'upload costing runs');
ok(count($result['runs']) === 2, 'one daily run per sale date');
ok((int) $result['costed'] === 2 && (int) $result['lines'] === 4, 'two of four rows costed (momo only)');
$run1 = db()->query("SELECT * FROM hospitality_costing_runs WHERE company_id=$cidA AND costing_date='2026-08-01'")->fetch();
$run2 = db()->query("SELECT * FROM hospitality_costing_runs WHERE company_id=$cidA AND costing_date='2026-08-02'")->fetch();
ok($run1 && (string) $run1['source'] === 'upload' && (int) $run1['upload_id'] === $uploadA, 'run carries source=upload and the upload id');
ok((string) $run1['status'] === 'partial' && (string) $run2['status'] === 'costed', 'run status reflects partial vs fully costed days');

$momoLine = db()->query("SELECT * FROM hospitality_costing_lines WHERE run_id={$run1['id']} AND menu_item_id=$miMomo")->fetch();
ok($momoLine !== false && near((float) $momoLine['unit_cost'], $expectedUnit), 'line unit cost comes from the recipe as of the sale date');
ok(near((float) $momoLine['total_cost'], round(10 * $expectedUnit, 2)), 'total cost = qty × unit cost');
ok(near((float) $momoLine['net_sales'], 2500.0), 'net sales taken from the uploaded row (taxable)');
ok(near((float) $momoLine['gross_profit'], round(2500 - 10 * $expectedUnit, 2)), 'gross profit = net sales − total cost');
ok((int) $momoLine['sales_row_id'] > 0, 'costing line links back to the uploaded row');

$mysteryLine = db()->query("SELECT * FROM hospitality_costing_lines WHERE run_id={$run1['id']} AND description='Mystery Dish'")->fetch();
ok($mysteryLine !== false && (string) $mysteryLine['status'] === 'unmapped' && str_contains((string) $mysteryLine['warning'], 'Mystery Dish'), 'unmatched item flagged with a clear warning');
$noRecipeLine = db()->query("SELECT * FROM hospitality_costing_lines WHERE run_id={$run1['id']} AND description='No Recipe Item'")->fetch();
ok($noRecipeLine !== false && (string) $noRecipeLine['status'] === 'missing_recipe', 'item without an active recipe flagged missing_recipe');
ok((string) $mysteryLine['category'] === 'Food' && (string) $noRecipeLine['category'] === 'Beverage', 'sheet category kept for unmatched rows');

echo "== Reports (daily / category / item) ==\n";
$daily = hospitality_grouped($cidA, '2026-08-01', '2026-08-31', 'period_day');
ok(count($daily) === 2, 'daily profit report shows both days');
$day1 = null;
foreach ($daily as $row) { if ((string) $row['group'] === '2026-08-01') { $day1 = $row; } }
ok($day1 !== null && near((float) $day1['net_sales'], 2500.0), 'daily report totals only the costed sales');
$byCategory = hospitality_grouped($cidA, '2026-08-01', '2026-08-31', 'category');
ok(count($byCategory) === 1 && (string) $byCategory[0]['category'] === 'Food', 'category report groups costed lines by category');
$byItem = hospitality_grouped($cidA, '2026-08-01', '2026-08-31', 'menu_item');
ok(count($byItem) === 1 && near((float) $byItem[0]['net_sales'], 3750.0), 'item report consolidates both days of the item');

$summary = hospitality_upload_costing_summary($cidA, $uploadA);
ok((int) $summary['run_count'] === 2 && (int) $summary['costed_lines'] === 2 && (int) $summary['total_lines'] === 4, 'upload costing summary counts runs and lines');

echo "== Cost update + history ==\n";
db()->prepare("INSERT INTO hospitality_ingredient_costs (company_id, ingredient_id, purchase_cost, effective_date, source) VALUES (:cid, :iid, 120, '2026-08-01', 'manual')")
    ->execute(['cid' => $cidA, 'iid' => $iChicken]);
db()->prepare('UPDATE hospitality_ingredients SET purchase_cost = 120 WHERE id = :id')->execute(['id' => $iChicken]);
$expectedUnit2 = (float) hospitality_recipe_cost($rMomo, '2026-08-01', $settingsA)['effective_cost_per_portion'];
ok($expectedUnit2 > $expectedUnit, 'ingredient rate rise raises the per-unit recipe cost');
$historyBefore = (int) db()->query("SELECT COUNT(*) FROM hospitality_recalc_history WHERE company_id=$cidA")->fetchColumn();
$recost = hospitality_upload_costing($cidA, $fyA, $uploadA, $actorId, 'Chicken price revised');
ok($recost['ok'] === true, 're-costing the same upload succeeds');
$historyAfter = (int) db()->query("SELECT COUNT(*) FROM hospitality_recalc_history WHERE company_id=$cidA")->fetchColumn();
ok($historyAfter === $historyBefore + 2, 'previous totals of BOTH replaced days recorded in the cost history');
$reason = (string) db()->query("SELECT reason FROM hospitality_recalc_history WHERE company_id=$cidA ORDER BY id DESC LIMIT 1")->fetchColumn();
ok(str_contains($reason, 'Chicken price revised'), 'the recalculation reason is kept');
$momoLine2 = db()->query("SELECT * FROM hospitality_costing_lines WHERE company_id=$cidA AND sale_date='2026-08-01' AND menu_item_id=$miMomo")->fetch();
ok(near((float) $momoLine2['unit_cost'], $expectedUnit2), 'new costing uses the updated ingredient rate');
ok((int) db()->query("SELECT COUNT(*) FROM hospitality_costing_runs WHERE company_id=$cidA")->fetchColumn() === 2, 're-costing replaces runs instead of duplicating them');

echo "== Tenant isolation + deletes ==\n";
$foreign = hospitality_upload_costing($cidB, $fyB, $uploadA, $actorId);
ok($foreign['ok'] === false, "another company cannot cost this company's upload");
ok(hospitality_run_delete($cidB, (int) $run2['id'], $actorId) === false, "another company cannot delete this company's run");
ok(hospitality_run_delete($cidA, (int) $run2['id'], $actorId) === true, 'run delete works for the owning company');
ok((int) db()->query("SELECT COUNT(*) FROM hospitality_costing_runs WHERE id={$run2['id']}")->fetchColumn() === 0, 'deleted run is gone');
$deleteNote = (string) db()->query("SELECT reason FROM hospitality_recalc_history WHERE company_id=$cidA AND costing_date='2026-08-02' AND run_id IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
ok(str_contains($deleteNote, 'Run deleted'), 'deletion leaves the final totals in the history (run_id detached, not cascaded)');

hupc_cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
