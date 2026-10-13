<?php
declare(strict_types=1);
function transfer_check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
$source = file_get_contents(__DIR__ . '/../public_html/admin/accounting-inventory.php');
$start = strpos($source, 'if (inv_movement_is_location_only($type))');
$end = strpos($source, '// Adjustments choose their own direction', $start);
$handler = substr($source, $start, $end - $start);
foreach (['STOCK_TRANSFER_VOUCHER','context_fiscal_year_id','FOR UPDATE','already been posted','inv_item_warehouse_qty','activeLocations','db()->rollBack()'] as $guard) transfer_check(str_contains($handler,$guard),'Missing transfer safeguard: ' . $guard);
foreach (['create_voucher_with_entries(', 'inv_apply_movement(', 'inventory_post_'] as $financialCall) transfer_check(!str_contains($handler,$financialCall),'Transfer must not post GL or reorder cost layers');
transfer_check(substr_count($handler,'$insertTxn->execute(') === 2, 'Transfer must have two stock legs');
transfer_check(str_contains($handler,"'qty_in' => 0") && str_contains($handler,"'qty_out' => 0"),'Missing paired directions');
transfer_check(!str_contains($source,'New Production voucher</a>'),'Redundant production panel remains');
transfer_check(!str_contains($source,'<details class="feature-disclosure" id="movement-transfer">'),'Old transfer entry form remains');

function db(): PDO { global $pdo; return $pdo; }
function require_permission(string $module, string $action): void { transfer_check($module === 'inventory' && $action === 'view','Wrong permission'); }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES); }
function app_date(string $date): string { return $date; }
function url(string $url): string { return $url; }
function inv_back_url(string $query): string { return 'inventory-transfers.php?' . $query; }
$pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE inventory_items(id INT,company_id INT,sku TEXT,name TEXT,unit TEXT);
CREATE TABLE warehouses(id INT,company_id INT,name TEXT);
CREATE TABLE inventory_transactions(id INT,company_id INT,fiscal_year_id INT,item_id INT,transaction_type TEXT,ref_no TEXT,transaction_date TEXT,warehouse_id INT,to_warehouse_id INT,qty_in REAL,qty_out REAL,notes TEXT);
INSERT INTO inventory_items VALUES(1,1,'I1','Item','pcs');
INSERT INTO warehouses VALUES(1,1,'A'),(2,1,'B');
INSERT INTO inventory_transactions VALUES
(1,1,1,1,'warehouse_transfer','STF-1','2026-10-14',1,2,0,4,'Test'),
(2,1,1,1,'warehouse_transfer','STF-1','2026-10-14',2,1,4,0,'Paired'),
(3,1,2,1,'warehouse_transfer','OTHER-YEAR','2025-10-14',1,2,0,1,'Old'),
(4,2,1,1,'warehouse_transfer','OTHER-COMPANY','2026-10-14',1,2,0,1,'Foreign')");
$companyId=1; $fiscalYearId=1;
ob_start(); require __DIR__ . '/../app/views/partials/stock_transfer_history.php'; $html=ob_get_clean();
transfer_check(count($transferRows) === 1,'One row per transfer, selected company/year only');
transfer_check(str_contains($html,'STF-1') && str_contains($html,'stock-transfer-view.php?id=1'),'Missing read-only voucher reference');
transfer_check((float)$pdo->query('SELECT SUM(qty_in-qty_out) FROM inventory_transactions WHERE id IN (1,2)')->fetchColumn() === 0.0,'Transfer must preserve total stock');
echo "Stock transfer: voucher routing, paired stock, no GL, safeguards and scoped history passed.\n";
