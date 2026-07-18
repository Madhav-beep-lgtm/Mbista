<?php
declare(strict_types=1);

/**
 * Manufacturing / opening-stock GL integrity suite.
 *
 * Pins the fixes for the deferred accounting gaps:
 *   1. item-master opening stock posts a GL voucher (Dr stock / Cr opening equity)
 *   2. MO start posts the WIP journal; completion credits WIP, not materials twice
 *   3. stock ledgers resolve mapping-first (item -> category -> global -> legacy)
 *   5. material issues are valued at ACTUAL layer cost, never the typed rate
 *   php database/test_manufacturing_wip.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/inventory_valuation.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.01; }

function mw_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code='WIPTESTA'")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM inventory_cost_layers WHERE company_id=$s");
        db()->exec("DELETE moi FROM manufacturing_order_inputs moi JOIN manufacturing_orders mo ON mo.id=moi.manufacturing_order_id WHERE mo.company_id=$s");
        db()->exec("DELETE FROM manufacturing_orders WHERE company_id=$s");
        db()->exec("DELETE FROM inventory_transactions WHERE company_id=$s");
        db()->exec("DELETE FROM inventory_ledger_mappings WHERE company_id=$s");
        db()->exec("DELETE FROM inventory_items WHERE company_id=$s");
        db()->exec("DELETE ve FROM voucher_entries ve JOIN vouchers v ON v.id=ve.voucher_id WHERE v.company_id=$s");
        db()->exec("DELETE FROM vouchers WHERE company_id=$s");
        db()->exec("DELETE FROM ledgers WHERE company_id=$s");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
}
mw_cleanup();

db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('WIP Test Co','WIPTESTA',1)")->execute();
$cid = (int) db()->lastInsertId();
$_SESSION['company_id'] = $cid;
$mkG = static function (string $m, string $c, string $n) use ($cid): int { db()->prepare('INSERT INTO ledger_groups (company_id,master_key,code,name) VALUES (?,?,?,?)')->execute([$cid,$m,$c,$n]); return (int) db()->lastInsertId(); };
$mkL = static function (int $g, string $c, string $n, string $t) use ($cid): int { db()->prepare("INSERT INTO ledgers (company_id,group_id,code,name,type,status) VALUES (?,?,?,?,?, 'active')")->execute([$cid,$g,$c,$n,$t]); return (int) db()->lastInsertId(); };
$gA = $mkG('current_asset','MW-INV','Inventories'); $gE = $mkG('equity','MW-EQ','Equity'); $gX = $mkG('direct_expense','MW-EXP','Production');
$lRM  = $mkL($gA,'MW-RM','Raw Material Inventory','asset');
$lWIP = $mkL($gA,'MW-WIP','Work in Progress','asset');
$lFG  = $mkL($gA,'MW-FG','Finished Goods Inventory','asset');
$lGEN = $mkL($gA,'MW-GEN','Inventory Asset (generic)','asset');
$lOEQ = $mkL($gE,'MW-OEQ','Opening Balance Equity','equity');
$fyStart = date('Y-m-d', strtotime('-30 days'));
$fyEnd = date('Y-m-d', strtotime('+335 days'));
$fy = create_fiscal_year($cid, 'MW FY', $fyStart, $fyEnd, true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId = (int) $fy['id']; $_SESSION['fiscal_year_id'] = $fyId;
$mapStmt = db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, category, item_id, purpose, ledger_id) VALUES (?, 'global', NULL, NULL, ?, ?)");
foreach (['raw_material' => $lRM, 'wip' => $lWIP, 'finished_goods' => $lFG, 'inventory_asset' => $lGEN, 'opening_equity' => $lOEQ] as $purpose => $lid) {
    $mapStmt->execute([$cid, $purpose, $lid]);
}
$mkItem = static function (string $sku, string $type, float $openQty, float $rate, ?int $legacyLedger = null) use ($cid): array {
    db()->prepare("INSERT INTO inventory_items (company_id, ledger_id, sku, name, item_type, valuation_method, unit, purchase_rate, opening_qty, status) VALUES (:cid,:led,:sku,:n,:t,'fifo','pcs',:r,:oq,'active')")
        ->execute(['cid'=>$cid,'led'=>$legacyLedger,'sku'=>$sku,'n'=>$sku.' item','t'=>$type,'r'=>$rate,'oq'=>$openQty]);
    $id = (int) db()->lastInsertId();
    $s = db()->prepare('SELECT * FROM inventory_items WHERE id = ?'); $s->execute([$id]);
    return (array) $s->fetch(PDO::FETCH_ASSOC);
};

echo "Gap 3: stock ledger resolves mapping-first, legacy last\n";
$rm = $mkItem('MW-RAW', 'raw_material', 0, 100);
$fg = $mkItem('MW-FIN', 'finished_good', 0, 0);
$legacyOnly = $mkItem('MW-LEG', 'stock', 0, 50, $lGEN);
ok(inv_item_stock_ledger_id($cid, $rm) === $lRM, 'raw_material item resolves the Raw Material mapping');
ok(inv_item_stock_ledger_id($cid, $fg) === $lFG, 'finished_good item resolves the Finished Goods mapping');
db()->exec("DELETE FROM inventory_ledger_mappings WHERE company_id=$cid AND purpose IN ('raw_material')");
ok(inv_item_stock_ledger_id($cid, $rm) === $lGEN, 'falls through to the generic Inventory Asset mapping');
db()->exec("DELETE FROM inventory_ledger_mappings WHERE company_id=$cid AND purpose IN ('inventory_asset')");
ok(inv_item_stock_ledger_id($cid, $legacyOnly) === $lGEN, 'falls back to the legacy inventory_items.ledger_id');
ok(inv_item_stock_ledger_id($cid, $rm) === 0, 'returns 0 when nothing resolves (never guesses)');
$mapStmt->execute([$cid, 'raw_material', $lRM]);
$mapStmt->execute([$cid, 'inventory_asset', $lGEN]);

echo "\nGap 1: item-master opening stock posts / replaces / clears its GL voucher\n";
$open = $mkItem('MW-OPEN', 'stock', 100, 500);
inv_add_layer($cid, (int) $open['id'], 100, 500, '2000-01-01');
$ov = inv_post_item_opening_voucher($cid, $open, null);
ok((int) $ov['voucher_id'] > 0 && $ov['note'] === '', 'Opening voucher posts (100 @ 500 = 50,000)');
$ovId = (int) $ov['voucher_id'];
$vRow = db()->query("SELECT * FROM vouchers WHERE id=$ovId")->fetch(PDO::FETCH_ASSOC);
ok((string) $vRow['source_type'] === 'inventory_opening' && near((float) $vRow['total_amount'], 50000), 'Voucher source inventory_opening, amount 50,000');
$legs = db()->query("SELECT entry_type, ledger_id, amount FROM voucher_entries WHERE voucher_id=$ovId ORDER BY entry_type")->fetchAll(PDO::FETCH_ASSOC);
$drLeg = null; $crLeg = null;
foreach ($legs as $leg) { if ($leg['entry_type']==='debit') { $drLeg = $leg; } else { $crLeg = $leg; } }
ok($drLeg && (int) $drLeg['ledger_id'] === $lGEN && near((float) $drLeg['amount'], 50000), 'Dr the item stock ledger 50,000');
ok($crLeg && (int) $crLeg['ledger_id'] === $lOEQ && near((float) $crLeg['amount'], 50000), 'Cr Opening Balance Equity 50,000');
$ov2 = inv_post_item_opening_voucher($cid, $open, null);
ok((int) $ov2['voucher_id'] === $ovId, 'Unchanged opening keeps the SAME voucher (no churn)');
$open['opening_qty'] = 80; // 80 @ 500 = 40,000
$ov3 = inv_post_item_opening_voucher($cid, $open, null);
ok((int) $ov3['voucher_id'] > 0 && (int) $ov3['voucher_id'] !== $ovId, 'Changed opening REPLACES the voucher');
ok(near((float) db()->query("SELECT total_amount FROM vouchers WHERE id={$ov3['voucher_id']}")->fetchColumn(), 40000), 'Replacement voucher carries the new 40,000');
$open['opening_qty'] = 0;
$ov4 = inv_post_item_opening_voucher($cid, $open, null);
ok((int) $ov4['voucher_id'] === 0
    && (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE source_type='inventory_opening' AND source_id={$open['id']}")->fetchColumn() === 0,
    'Cleared opening DELETES the voucher');

echo "\nGap 5 + 2: MO issues at actual FIFO cost; WIP journal start -> completion\n";
// Raw stock: 10 @ 100 then 10 @ 140 (FIFO). Issue 15 -> actual cost 10x100 + 5x140 = 1,700.
$rmId = (int) $rm['id'];
$fgId = (int) $fg['id'];
$seed = db()->prepare('INSERT INTO inventory_transactions (company_id, fiscal_year_id, item_id, transaction_type, transaction_date, qty_in, qty_out, rate, amount) VALUES (?,?,?,?,?,?,?,?,?)');
$seed->execute([$cid, $fyId, $rmId, 'purchase', date('Y-m-d', strtotime('-20 days')), 10, 0, 100, 1000]);
inv_apply_movement($cid, $rmId, 10, 0, 100, date('Y-m-d', strtotime('-20 days')), 'fifo', (int) db()->lastInsertId());
$seed->execute([$cid, $fyId, $rmId, 'purchase', date('Y-m-d', strtotime('-10 days')), 10, 0, 140, 1400]);
inv_apply_movement($cid, $rmId, 10, 0, 140, date('Y-m-d', strtotime('-10 days')), 'fifo', (int) db()->lastInsertId());

// --- Simulate the MO-start flow with the page's exact building blocks ---
$orderNo = 'MO-TEST-1';
db()->prepare("INSERT INTO manufacturing_orders (company_id, fiscal_year_id, order_no, finished_item_id, quantity, labour_cost, overhead_absorbed, byproduct_value, abnormal_waste_cost, status, started_on) VALUES (?,?,?,?,?,?,?,?,?,'in_progress',?)")
    ->execute([$cid, $fyId, $orderNo, $fgId, 5, 300, 200, 0, 0, date('Y-m-d')]);
$orderId = (int) db()->lastInsertId();
$seed->execute([$cid, $fyId, $rmId, 'consume', date('Y-m-d'), 0, 15, 999, 14985]); // typed rate 999 deliberately wrong
$consumeTxn = (int) db()->lastInsertId();
$issueValue = inv_apply_movement($cid, $rmId, 0, 15, 999, date('Y-m-d'), 'fifo', $consumeTxn);
ok(near($issueValue, 1700), "Issue of 15 units costs the ACTUAL FIFO 1,700, not typed 999/unit (got $issueValue)");
$actualRate = round($issueValue / 15, 6);
db()->prepare('UPDATE inventory_transactions SET rate=:r, amount=:a WHERE id=:id')->execute(['r'=>$actualRate,'a'=>$issueValue,'id'=>$consumeTxn]);
db()->prepare('INSERT INTO manufacturing_order_inputs (manufacturing_order_id, item_id, quantity, rate) VALUES (?,?,?,?)')->execute([$orderId, $rmId, 15, $actualRate]);
$wipVoucherId = (int) create_voucher_with_entries([
    'company_id' => $cid, 'fiscal_year_id' => $fyId, 'voucher_no' => 'MFG-WIP-' . $orderNo,
    'voucher_type' => 'journal', 'voucher_date' => date('Y-m-d'),
    'source_type' => 'manufacturing_order_start', 'source_id' => $orderId,
    'total_amount' => $issueValue, 'narration' => 'test WIP', 'status' => 'posted',
], [
    ['ledger_id' => $lWIP, 'entry_type' => 'debit', 'amount' => $issueValue],
    ['ledger_id' => $lRM, 'entry_type' => 'credit', 'amount' => $issueValue],
]);
ok($wipVoucherId > 0, 'WIP journal posts: Dr WIP 1,700 / Cr Raw Material 1,700');
$balance = inv_layer_balance($cid, $rmId);
ok(near($balance['value'], 700) && near($balance['qty'], 5), 'Raw layers after issue: 5 @ 140 = 700 (subledger == GL movement)');

// --- Completion from WIP: credits the WIP ledger, absorbed FG cost 1,700+300+200 = 2,200 ---
$materialTotal = round(15 * $actualRate, 2);
ok(near($materialTotal, 1700), 'Stored input rate reproduces the actual material cost at completion');
$fgDebit = round($materialTotal + 300 + 200, 2);
$mfgVoucherId = (int) create_voucher_with_entries([
    'company_id' => $cid, 'fiscal_year_id' => $fyId, 'voucher_no' => 'MFG-' . $orderNo,
    'voucher_type' => 'journal', 'voucher_date' => date('Y-m-d'),
    'source_type' => 'manufacturing_order', 'source_id' => $orderId,
    'total_amount' => $fgDebit, 'narration' => 'test completion', 'status' => 'posted',
], [
    ['ledger_id' => $lFG, 'entry_type' => 'debit', 'amount' => $fgDebit],
    ['ledger_id' => $lWIP, 'entry_type' => 'credit', 'amount' => $materialTotal],
    ['ledger_id' => $mkL($gX,'MW-LAB','Labour Clearing','liability'), 'entry_type' => 'credit', 'amount' => 300.0],
    ['ledger_id' => $mkL($gX,'MW-OH','Overhead Absorbed','expense'), 'entry_type' => 'credit', 'amount' => 200.0],
]);
ok($mfgVoucherId > 0, 'Completion voucher posts: Dr FG 2,200 / Cr WIP 1,700 + conversion 500');
// WIP ledger nets to zero across start + completion — nothing stranded.
$wipNet = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN ve.entry_type='debit' THEN ve.amount ELSE -ve.amount END),0)
    FROM voucher_entries ve JOIN vouchers v ON v.id=ve.voucher_id WHERE v.company_id=$cid AND ve.ledger_id=$lWIP")->fetchColumn();
ok(near($wipNet, 0), 'WIP ledger nets to ZERO after completion (no double-credit of materials)');

echo "\nMutation guard covers the new sources\n";
$wipV = db()->query("SELECT * FROM vouchers WHERE id=$wipVoucherId")->fetch(PDO::FETCH_ASSOC);
$blocked = voucher_mutation_blocker((array) $wipV);
ok($blocked !== null && str_contains((string) $blocked, 'Manufacturing'), 'manufacturing_order_start voucher is guarded from ad-hoc deletion');
$openV = $ov3['voucher_id'] > 0 ? db()->query("SELECT * FROM vouchers WHERE id={$ov3['voucher_id']}")->fetch(PDO::FETCH_ASSOC) : null;
ok($openV === null || voucher_mutation_blocker((array) $openV) !== null, 'inventory_opening voucher is guarded from ad-hoc deletion');

echo "\nHandler wiring pins (source-level)\n";
$pageSrc = (string) file_get_contents(__DIR__ . '/../public_html/admin/accounting-inventory.php');
ok(substr_count($pageSrc, 'inv_item_stock_ledger_id') >= 4, 'MO + opening paths resolve ledgers via inv_item_stock_ledger_id');
ok(str_contains($pageSrc, "'manufacturing_order_start'"), 'MO start posts the WIP voucher (source manufacturing_order_start)');
ok(str_contains($pageSrc, 'inv_post_item_opening_voucher'), 'save_item posts the opening-stock voucher');
ok(substr_count($pageSrc, 'inv_apply_movement') >= 3, 'MO consume/produce apply real cost-flow layers');
ok(!str_contains($pageSrc, "\$inputLedgerId = (int) (\$input['item']['ledger_id']"), 'Legacy raw ledger_id read is gone from the MO input loop');
ok(str_contains($pageSrc, 'fiscal_year_posting_blocker($moFy'), 'MO paths enforce the fiscal-period lock');

mw_cleanup();
echo "\n----------------------------------------\nPASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
