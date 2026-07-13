<?php
declare(strict_types=1);

/**
 * Manufacturing costing engine (migration 038).
 *
 * IAS 2 cost accumulation for finished goods:
 *   FG cost = direct materials + direct labour + absorbed production overhead
 *             (based on normal capacity) − by-product/scrap value,
 *   EXCLUDING abnormal waste (posted to period expense, never inventoried).
 *
 * Variances compare actual against the BOM standard:
 *   material price  = actual qty × (actual rate − std rate)
 *   material usage  = std rate × (actual qty − std qty allowed for output)
 *   labour          = actual labour − std labour allowed
 *   overhead        = actual absorbed − std overhead allowed
 * Positive variance = unfavourable (actual above standard).
 */

/**
 * Finished-goods cost accumulation.
 *
 * @return array{inventoriable: float, unit_cost: float, excluded_abnormal: float}
 */
function mfg_order_cost(float $materialCost, float $labourCost, float $overheadAbsorbed, float $byproductValue, float $abnormalWaste, float $outputQty): array
{
    // Abnormal waste never enters inventory cost (IAS 2.16); it is carved out
    // of the material pool and expensed.
    $inventoriable = round(max(0.0, $materialCost - $abnormalWaste) + $labourCost + $overheadAbsorbed - $byproductValue, 2);
    return [
        'inventoriable' => $inventoriable,
        'unit_cost' => $outputQty > 0 ? round($inventoriable / $outputQty, 2) : 0.0,
        'excluded_abnormal' => round($abnormalWaste, 2),
    ];
}

/**
 * The standard material cost a BOM allows for a given output quantity,
 * including expected (normal) waste.
 *
 * @param array $bomLines [['std_qty'=>, 'waste_pct'=>, 'std_rate'=>], ...] per one output unit batch (bom output_qty)
 */
function mfg_standard_material_cost(array $bomLines, float $bomOutputQty, float $actualOutputQty): float
{
    if ($bomOutputQty <= 0) {
        return 0.0;
    }
    $scale = $actualOutputQty / $bomOutputQty;
    $std = 0.0;
    foreach ($bomLines as $line) {
        $allowedQty = (float) $line['std_qty'] * (1 + (float) ($line['waste_pct'] ?? 0) / 100.0) * $scale;
        $std += $allowedQty * (float) $line['std_rate'];
    }
    return round($std, 2);
}

/**
 * Material price + usage variances for one order, aggregated across lines.
 *
 * @param array $actuals  [['item_id'=>, 'qty'=>, 'rate'=>], ...]
 * @param array $bomLines [['item_id'=>, 'std_qty'=>, 'waste_pct'=>, 'std_rate'=>], ...]
 * @return array{price: float, usage: float}  positive = unfavourable
 */
function mfg_material_variances(array $actuals, array $bomLines, float $bomOutputQty, float $actualOutputQty): array
{
    $scale = $bomOutputQty > 0 ? $actualOutputQty / $bomOutputQty : 0.0;
    $stdByItem = [];
    foreach ($bomLines as $line) {
        $allowedQty = (float) $line['std_qty'] * (1 + (float) ($line['waste_pct'] ?? 0) / 100.0) * $scale;
        $stdByItem[(int) $line['item_id']] = ['qty' => $allowedQty, 'rate' => (float) $line['std_rate']];
    }

    $price = 0.0;
    $usage = 0.0;
    foreach ($actuals as $a) {
        $itemId = (int) $a['item_id'];
        $aQty = (float) $a['qty'];
        $aRate = (float) $a['rate'];
        $std = $stdByItem[$itemId] ?? ['qty' => 0.0, 'rate' => $aRate]; // unplanned item: all usage variance at actual rate
        $price += $aQty * ($aRate - $std['rate']);
        $usage += ($aQty - $std['qty']) * $std['rate'];
    }
    // Items planned but not used at all count as favourable usage.
    $usedIds = array_map(static fn (array $a): int => (int) $a['item_id'], $actuals);
    foreach ($stdByItem as $itemId => $std) {
        if (!in_array($itemId, $usedIds, true)) {
            $usage -= $std['qty'] * $std['rate'];
        }
    }

    return ['price' => round($price, 2), 'usage' => round($usage, 2)];
}

/**
 * Simple conversion-cost variances vs the BOM standards, scaled to output.
 * @return array{labour: float, overhead: float}  positive = unfavourable
 */
function mfg_conversion_variances(float $actualLabour, float $actualOverhead, float $stdLabourPerBatch, float $stdOverheadPerBatch, float $bomOutputQty, float $actualOutputQty): array
{
    $scale = $bomOutputQty > 0 ? $actualOutputQty / $bomOutputQty : 0.0;
    return [
        'labour' => round($actualLabour - $stdLabourPerBatch * $scale, 2),
        'overhead' => round($actualOverhead - $stdOverheadPerBatch * $scale, 2),
    ];
}

/**
 * Load a company BOM (header + lines) or null.
 */
function mfg_load_bom(int $companyId, int $bomId): ?array
{
    if ($bomId <= 0 || !table_exists('bom_headers')) {
        return null;
    }
    $h = db()->prepare('SELECT * FROM bom_headers WHERE id = :id AND company_id = :cid LIMIT 1');
    $h->execute(['id' => $bomId, 'cid' => $companyId]);
    $header = $h->fetch(PDO::FETCH_ASSOC);
    if (!$header) {
        return null;
    }
    $l = db()->prepare('SELECT bl.*, i.sku, i.name AS item_name, i.unit, i.purchase_rate FROM bom_lines bl JOIN inventory_items i ON i.id = bl.item_id WHERE bl.bom_id = :id');
    $l->execute(['id' => $bomId]);
    $header['lines'] = $l->fetchAll(PDO::FETCH_ASSOC);
    return $header;
}

/**
 * Record the variance rows for a completed order (idempotent per order: the
 * caller deletes prior rows first when re-recording).
 */
function mfg_record_variances(int $companyId, int $orderId, array $variances): void
{
    if (!table_exists('production_variances')) {
        return;
    }
    $stmt = db()->prepare('INSERT INTO production_variances (company_id, manufacturing_order_id, variance_type, standard_amount, actual_amount, variance) VALUES (:cid, :oid, :t, :std, :act, :var)');
    foreach ($variances as $type => $v) {
        $stmt->execute(['cid' => $companyId, 'oid' => $orderId, 't' => $type, 'std' => round((float) ($v['standard'] ?? 0), 2), 'act' => round((float) ($v['actual'] ?? 0), 2), 'var' => round((float) ($v['variance'] ?? 0), 2)]);
    }
}
