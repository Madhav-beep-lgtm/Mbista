<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/inventory_valuation.php';
require_once __DIR__ . '/../app/manufacturing_engine.php';
require_once __DIR__ . '/../app/production_voucher.php';
if (!in_array(DB_HOST, ['localhost', '127.0.0.1'], true)) throw new RuntimeException('Local fixture database only');
function stock_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$fy = db()->query('SELECT * FROM fiscal_years ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$fy) throw new RuntimeException('Local test requires a company and financial year');
$cid = (int) $fy['company_id'];
$suffix = bin2hex(random_bytes(4));
$before = (int) db()->query('SELECT COUNT(*) FROM vouchers')->fetchColumn();
db()->beginTransaction();
try {
    $insert = db()->prepare("INSERT INTO inventory_items (company_id, sku, name, item_type, unit, status, opening_qty, opening_amount, valuation_method) VALUES (?, ?, 'Production fixture', 'stock', 'pcs', 'active', ?, ?, 'fifo')");
    $insert->execute([$cid, 'PM-' . $suffix, 10, 100]);
    $materialId = (int) db()->lastInsertId();
    $insert->execute([$cid, 'PF-' . $suffix, 0, 0]);
    $outputId = (int) db()->lastInsertId();
    $itemQuery = db()->prepare('SELECT * FROM inventory_items WHERE id = ?');
    foreach ([$materialId, $outputId] as $id) {
        $itemQuery->execute([$id]);
        inv_ensure_layers($cid, $itemQuery->fetch(PDO::FETCH_ASSOC));
    }
    $move = db()->prepare('INSERT INTO inventory_transactions (company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date, qty_in, qty_out, rate, amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $date = $fy['start_date'];
    $move->execute([$cid,$fy['id'],$materialId,'consume','TEST-' . $suffix,$date,0,4,10,40]);
    $materialCost = inv_apply_movement($cid,$materialId,0,4,10,$date,'fifo',(int)db()->lastInsertId());
    stock_check(abs($materialCost - 40) < 0.001, 'Materials must use recorded FIFO cost');
    $cost = mfg_order_cost($materialCost,0,20,0,0,2);
    $move->execute([$cid,$fy['id'],$outputId,'produce','TEST-' . $suffix,$date,2,0,$cost['unit_cost'],$cost['inventoriable']]);
    inv_apply_movement($cid,$outputId,2,0,$cost['unit_cost'],$date,'fifo',(int)db()->lastInsertId());
    stock_check($cost['unit_cost'] === 30.0, 'Additional cost not allocated to output');
    $quantity = db()->prepare('SELECT i.opening_qty + COALESCE(SUM(t.qty_in-t.qty_out),0) FROM inventory_items i LEFT JOIN inventory_transactions t ON t.item_id=i.id WHERE i.id=? GROUP BY i.id');
    $quantity->execute([$materialId]); stock_check((float)$quantity->fetchColumn() === 6.0, 'Material quantity not reduced');
    $quantity->execute([$outputId]); stock_check((float)$quantity->fetchColumn() === 2.0, 'Output quantity not increased');
    stock_check(production_post_costs($cid,(int)$fy['id'],0,'TEST',$date,0,[['mode'=>'allocated','amount'=>20]]) === 0, 'Allocated cost must not create GL');
    stock_check((int)db()->query('SELECT COUNT(*) FROM vouchers')->fetchColumn() === $before, 'Stock conversion created financial journal');
    echo "Local stock fixture: FIFO consumption, finished output, allocated cost and no material GL passed.\n";
} finally { db()->rollBack(); }
