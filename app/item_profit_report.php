<?php
declare(strict_types=1);
require_once __DIR__ . '/stock_report_engine.php';

function item_profit_replay(array $item, array $transactions): array
{
    $state = sr_replay_new((string)($item['valuation_method'] ?? 'weighted_average'));
    sr_replay_in($state, (float)($item['opening_qty'] ?? 0), inv_item_opening_unit_cost($item));
    $costs = [];
    foreach ($transactions as $txn) {
        if (in_array($txn['transaction_type'], SR_LOCATION_TYPES, true)) continue;
        $qtyIn = (float)$txn['qty_in']; $qtyOut = (float)$txn['qty_out'];
        if ($qtyIn > INV_EPSILON) {
            sr_replay_in($state, $qtyIn, (float)$txn['rate']);
            $costs[(int)$txn['id']] = ['value'=>round($qtyIn * (float)$txn['rate'], 2), 'missing'=>false];
        } elseif ($qtyOut > INV_EPSILON) {
            $available = array_sum(array_column($state['layers'], 'qty'));
            $costs[(int)$txn['id']] = ['value'=>sr_replay_out($state, $qtyOut), 'missing'=>$available + INV_EPSILON < $qtyOut];
        }
    }
    return $costs;
}

/** Read-only item margins: stock cost is analytical, never a GL posting. */
function item_profit_totals(array $rows): array
{
    $total = ['revenue' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'margin' => null, 'missing' => 0];
    foreach ($rows as $row) {
        $total['revenue'] += $row['revenue'];
        $total['cost'] += $row['cost'];
        $total['missing'] += $row['missing'];
    }
    $total['profit'] = $total['missing'] ? null : round($total['revenue'] - $total['cost'], 2);
    $total['margin'] = !$total['missing'] && $total['revenue'] > 0 ? round($total['profit'] / $total['revenue'] * 100, 2) : null;
    return $total;
}

function item_profit_report(int $companyId, string $from, string $to): array
{
    $stmt = db()->prepare("SELECT e.item_id, v.id AS voucher_id,
        SUM(CASE WHEN e.entry_type = 'credit' THEN e.amount ELSE -e.amount END) AS revenue,
        SUM(CASE WHEN e.entry_type = 'credit' THEN e.quantity ELSE -e.quantity END) AS quantity
        FROM voucher_entries e INNER JOIN vouchers v ON v.id = e.voucher_id
        INNER JOIN inventory_items i ON i.id = e.item_id AND i.company_id = v.company_id
        WHERE v.company_id = ? AND v.status = 'posted' AND v.voucher_type IN ('sales','credit_note')
        AND v.voucher_date BETWEEN ? AND ? AND COALESCE(v.source_type,'') NOT LIKE 'jewellery%'
        GROUP BY e.item_id, v.id");
    $stmt->execute([$companyId, $from, $to]);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $itemStmt = db()->prepare('SELECT * FROM inventory_items WHERE company_id = ? ORDER BY sku, name');
    $itemStmt->execute([$companyId]);
    $items = [];
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) $items[(int)$item['id']] = $item;
    $rows = []; $costs = [];
    $movement = db()->prepare("SELECT id, transaction_type, qty_in, qty_out FROM inventory_transactions
        WHERE company_id = ? AND source_voucher_id = ? AND item_id = ? AND transaction_type IN ('sale','sales_return')");
    foreach ($sales as $sale) {
        $id = (int)$sale['item_id'];
        $item = $items[$id];
        $rows[$id] ??= ['id'=>$id,'code'=>$item['sku'],'name'=>$item['name'],'unit'=>$item['unit'], 'quantity'=>0.0,'revenue'=>0.0,'cost'=>0.0,'missing'=>0];
        if (!isset($costs[$id])) {
            $txns = db()->prepare('SELECT id, transaction_type, qty_in, qty_out, rate FROM inventory_transactions WHERE company_id = ? AND item_id = ? ORDER BY transaction_date, id');
            $txns->execute([$companyId, $id]);
            $costs[$id] = item_profit_replay($item, $txns->fetchAll(PDO::FETCH_ASSOC));
        }
        $movement->execute([$companyId, (int)$sale['voucher_id'], $id]);
        $moves = $movement->fetchAll(PDO::FETCH_ASSOC);
        $matchedQty = 0.0;
        foreach ($moves as $move) {
            $sign = $move['transaction_type'] === 'sale' ? 1 : -1;
            $matchedQty += $sign * ((float)$move['qty_out'] + (float)$move['qty_in']);
            if (!isset($costs[$id][(int)$move['id']]) || $costs[$id][(int)$move['id']]['missing']) { $rows[$id]['missing']++; continue; }
            $rows[$id]['cost'] += $sign * $costs[$id][(int)$move['id']]['value'];
        }
        if (!$moves || abs($matchedQty - (float)$sale['quantity']) > 0.0005) $rows[$id]['missing']++;
        $rows[$id]['quantity'] += (float)$sale['quantity'];
        $rows[$id]['revenue'] += (float)$sale['revenue'];
    }
    // Jewellery stores its weight/specific-item cost on the posted sales line.
    if (table_exists('jewellery_sale_lines') && table_exists('jewellery_sales')) {
        $stmt = db()->prepare("SELECT l.item_id, SUM(l.qty_pieces) AS quantity,
            SUM(l.metal_amount + l.making_amount + l.stone_amount + l.diamond_amount + l.other_diamond_amount + l.allocated_adjust) AS revenue,
            SUM(l.cogs_amount) AS cost, SUM(CASE WHEN l.stock_txn_id IS NULL OR l.stock_txn_id = 0 THEN 1 ELSE 0 END) AS missing
            FROM jewellery_sale_lines l INNER JOIN jewellery_sales s ON s.id = l.sale_id
            WHERE s.company_id = ? AND s.status = 'posted' AND s.sale_date BETWEEN ? AND ? GROUP BY l.item_id");
        $stmt->execute([$companyId, $from, $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sale) {
            $id = (int)$sale['item_id'];
            if (!isset($items[$id])) continue;
            $item = $items[$id];
            $rows[$id] ??= ['id'=>$id,'code'=>$item['sku'],'name'=>$item['name'],'unit'=>$item['unit'],'quantity'=>0.0,'revenue'=>0.0,'cost'=>0.0,'missing'=>0];
            foreach (['quantity','revenue','cost','missing'] as $field) $rows[$id][$field] += (float)$sale[$field];
        }
    }
    foreach ($rows as &$row) {
        $row['revenue'] = round($row['revenue'], 2); $row['cost'] = round($row['cost'], 2);
        $row['profit'] = $row['missing'] ? null : round($row['revenue'] - $row['cost'], 2);
        $row['margin'] = !$row['missing'] && $row['revenue'] > 0 ? round($row['profit'] / $row['revenue'] * 100, 2) : null;
    }
    unset($row);
    return ['rows'=>array_values($rows), 'totals'=>item_profit_totals($rows)];
}
