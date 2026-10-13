<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/production_voucher.php';
require_once __DIR__ . '/../app/manufacturing_engine.php';
function db(): PDO { global $pdo; return $pdo; }
function create_voucher_with_entries(array $voucher, array $entries): int { $GLOBALS['posted'][] = [$voucher, $entries]; return 12; }
function check_production(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE ledgers (id INT, company_id INT, type TEXT, status TEXT);
INSERT INTO ledgers VALUES (1,1,'expense','active'),(2,1,'asset','active'),(3,2,'expense','active'),(4,1,'liability','inactive')");
$data = ['cost_amount'=>[40,10], 'cost_mode'=>['new','allocated'], 'cost_description'=>['Labour','Electricity'], 'cost_expense'=>[1,0], 'cost_credit'=>[2,0], 'cost_centre'=>['Factory',''], 'cost_reference'=>['','JV-1']];
$lines = production_cost_lines($data, 1);
$entries = production_cost_entries($lines);
check_production(count($entries) === 2 && $entries[0]['amount'] === 40.0 && $entries[1]['amount'] === 40.0, 'Only new cost should hit the GL');
check_production($entries[0]['ledger_id'] === 1 && $entries[1]['ledger_id'] === 2, 'Expense/payment accounts incorrect');
check_production($entries[0]['cost_centre'] === 'Factory', 'Missing cost centre');
$cost = mfg_order_cost(100, 0, array_sum(array_column($lines, 'amount')), 0, 0, 5);
check_production($cost['inventoriable'] === 150.0 && $cost['unit_cost'] === 30.0, 'Output must include materials plus both cost treatments');
$GLOBALS['posted'] = [];
check_production(production_post_costs(1, 2, 3, 'PRD-1', '2026-10-14', 4, []) === 0, 'Stock-only production must not post GL');
check_production(production_post_costs(1, 2, 3, 'PRD-1', '2026-10-14', 4, [$lines[1]]) === 0, 'Already recorded cost must not repost GL');
check_production($GLOBALS['posted'] === [], 'Unexpected financial journal');
production_post_costs(1, 2, 3, 'PRD-1', '2026-10-14', 4, $lines);
check_production($GLOBALS['posted'][0][0]['total_amount'] === 40.0, 'Journal must exclude material and allocated costs');
foreach ([['cost_expense',3], ['cost_credit',4], ['cost_amount',-1], ['cost_mode','invalid'], ['cost_centre','']] as [$field,$value]) {
    $bad = $data; $bad[$field][0] = $value;
    try { production_cost_lines($bad, 1); throw new LogicException('Invalid input accepted: ' . $field); }
    catch (RuntimeException $e) { if ($e instanceof LogicException) throw $e; }
}
$source = file_get_contents(__DIR__ . '/../public_html/admin/accounting-inventory.php');
$start = strpos($source, "if (\$action === 'create_manufacturing_order')");
$end = strpos($source, "if (\$action === 'complete_manufacturing_order'", $start);
$handler = substr($source, $start, $end - $start);
check_production(str_contains($handler, 'production_post_costs(') && !str_contains($handler, 'inventory_post_production_voucher('), 'New production must not call stock GL transfer');
foreach (['FOR UPDATE', 'requiredStock', 'context_fiscal_year_id', 'staff_accountant_forces_approval', 'db()->rollBack()', 'inv_apply_movement'] as $guard) check_production(str_contains($handler, $guard), 'Missing posting safeguard: ' . $guard);
check_production(!str_contains($source, '<strong><?= icon(\'settings\') ?>Production Order</strong>'), 'Legacy production form remains');
echo "Production costs, company validation, stock-only journal rules and posting safeguards passed.\n";
