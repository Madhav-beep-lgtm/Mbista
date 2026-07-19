<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/inventory_valuation.php';
$cid = 6;
echo "=== inventory movements WITHOUT a GL voucher (company 6) ===\n";
$rows = db()->query("SELECT t.id, t.transaction_type, t.transaction_date, t.qty_in, t.qty_out, t.rate, t.amount, i.sku
    FROM inventory_transactions t JOIN inventory_items i ON i.id=t.item_id
    WHERE t.company_id=$cid AND t.voucher_id IS NULL ORDER BY t.transaction_date")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $plan = inv_movement_posting_plan((string) $r['transaction_type'], (float) $r['qty_in'] > 0 ? 'in' : 'out');
    printf("  #%-4s %-12s %-20s in %8.2f out %8.2f rate %8.2f  plan:%s\n", $r['id'], $r['sku'], $r['transaction_type'], $r['qty_in'], $r['qty_out'], $r['rate'], $plan ? 'YES' : 'no-GL');
}
echo "total unposted with GL plan: " . count(array_filter($rows, fn($r) => inv_movement_posting_plan((string)$r['transaction_type'], (float)$r['qty_in']>0?'in':'out') !== null)) . " of " . count($rows) . "\n";
echo "\n=== GL inventory-ish ledger balances ===\n";
foreach (db()->query("SELECT l.id, l.code, l.name,
    COALESCE((SELECT SUM(CASE WHEN ve.entry_type='debit' THEN ve.amount ELSE -ve.amount END) FROM voucher_entries ve JOIN vouchers v ON v.id=ve.voucher_id WHERE ve.ledger_id=l.id AND v.status='posted'),0) AS bal
    FROM ledgers l WHERE l.company_id=$cid AND (l.code LIKE '%STOCK%' OR l.code LIKE 'SMP-FIN%' OR l.code LIKE 'SMP-RAW%' OR LOWER(l.name) LIKE '%inventor%' OR LOWER(l.name) LIKE '%stock%')")->fetchAll(PDO::FETCH_ASSOC) as $l) {
    printf("  ledger #%-4s %-14s %-28s %12.2f\n", $l['id'], $l['code'], $l['name'], $l['bal']);
}
echo "\n=== opening-stock vouchers posted? ===\n";
echo "INV-OPEN vouchers: " . (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id=$cid AND source_type='inventory_opening'")->fetchColumn() . "\n";
echo "items with master opening_qty>0: " . (int) db()->query("SELECT COUNT(*) FROM inventory_items WHERE company_id=$cid AND opening_qty>0")->fetchColumn() . "\n";
