<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_or_admin();
require_company_context();

$repairErrors = accounting_module_repair_database();
$pageTitle = 'Inventory & Manufacturing';
$company = current_company();
$fiscalYear = current_fiscal_year();
$currentUser = current_user();
$companyId = (int) ($company['id'] ?? 0);
$fiscalYearId = (int) ($fiscalYear['id'] ?? 0);
$userId = (int) ($currentUser['id'] ?? 0);
$inventoryBusinessType = company_accounting_business_type($companyId);
$inventoryProfile = accounting_business_profile($inventoryBusinessType);

if (!($inventoryProfile['show_inventory'] ?? false)) {
    flash('error', 'Inventory and manufacturing tools are available only for trading and manufacturing companies.');
    redirect('admin/accounting-dashboard.php');
}

$itemTypes = $inventoryProfile['show_manufacturing']
    ? ['stock', 'service', 'raw_material', 'finished_good', 'consumable']
    : ['stock', 'service', 'consumable'];
$movementTypes = [
    'opening', 'purchase', 'sale', 'sales_return', 'purchase_return', 'adjustment',
    'write_off', 'damage', 'expiry', 'warehouse_transfer', 'departmental_transfer',
];

function inventory_direction(string $type): string
{
    return in_array($type, ['opening', 'purchase', 'sales_return', 'produce'], true) ? 'in' : 'out';
}

function inventory_valid_date(string $value): ?string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return ($parsed && $parsed->format('Y-m-d') === $value) ? $value : null;
}

/**
 * The inventory posting purposes shown on the Ledger Mapping tab, with a
 * human label and the account type each SHOULD point at (used for the
 * "wrong-type" warning so an asset is not mapped to income, etc.).
 */
function inventory_mapping_purposes(): array
{
    return [
        'inventory_asset'      => ['label' => 'Inventory Asset', 'expect' => 'asset'],
        'opening_equity'       => ['label' => 'Opening Balance Equity', 'expect' => 'equity'],
        'raw_material'         => ['label' => 'Raw Material Inventory', 'expect' => 'asset'],
        'wip'                  => ['label' => 'Work in Progress', 'expect' => 'asset'],
        'finished_goods'       => ['label' => 'Finished Goods Inventory', 'expect' => 'asset'],
        'cogs'                 => ['label' => 'Cost of Goods Sold', 'expect' => 'expense'],
        'purchase_clearing'    => ['label' => 'Purchase / GRNI Clearing', 'expect' => 'liability'],
        'sales_revenue'        => ['label' => 'Sales Revenue', 'expect' => 'revenue'],
        'inventory_gain'       => ['label' => 'Inventory Gain / Adjustment', 'expect' => 'revenue'],
        'inventory_loss'       => ['label' => 'Inventory Loss / Damage / Expiry', 'expect' => 'expense'],
        'write_down_expense'   => ['label' => 'Inventory Write-down Expense', 'expect' => 'expense'],
        'write_down_allowance' => ['label' => 'Allowance for Write-down', 'expect' => 'liability'],
        'write_down_reversal'  => ['label' => 'Reversal of Write-down', 'expect' => 'revenue'],
        'scrap_inventory'      => ['label' => 'Scrap / By-product Inventory', 'expect' => 'asset'],
        'labour_clearing'      => ['label' => 'Direct Labour Clearing / Wages Payable', 'expect' => 'liability'],
        'overhead_absorbed'    => ['label' => 'Production Overhead Absorbed', 'expect' => 'expense'],
        'tax_input'            => ['label' => 'Recoverable Input Tax', 'expect' => 'asset'],
        'tax_output'           => ['label' => 'Output Tax Payable', 'expect' => 'liability'],
    ];
}

/** Item scoped to the current company, or null — never trust a POSTed item id. */
function inventory_company_item(int $itemId, int $companyId): ?array
{
    if ($itemId <= 0) {
        return null;
    }
    $stmt = db()->prepare('
        SELECT i.*, i.opening_qty + COALESCE((SELECT SUM(t.qty_in - t.qty_out) FROM inventory_transactions t WHERE t.item_id = i.id), 0) AS on_hand
        FROM inventory_items i WHERE i.id = :id AND i.company_id = :company_id LIMIT 1
    ');
    $stmt->execute(['id' => $itemId, 'company_id' => $companyId]);
    return $stmt->fetch() ?: null;
}

/**
 * A POSTed warehouse id, but only if it belongs to this company — otherwise
 * null. The warehouse FKs reference warehouses(id) with no company predicate,
 * so a tampered id from another tenant would otherwise insert cleanly and tag
 * this company's stock with a foreign location.
 */
function inventory_company_warehouse_id(int $warehouseId, int $companyId): ?int
{
    if ($warehouseId <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT id FROM warehouses WHERE id = :id AND company_id = :company_id LIMIT 1');
    $stmt->execute(['id' => $warehouseId, 'company_id' => $companyId]);

    return ($stmt->fetchColumn() !== false) ? $warehouseId : null;
}

/**
 * Post the production journal (Dr finished-goods ledger / Cr input ledgers)
 * for a completed order, when the items involved have linked ledgers.
 * Idempotent via the vouchers UNIQUE(source_type, source_id) key.
 * Returns the voucher id, or 0 when ledger links are missing (stock-only).
 */
function inventory_post_production_voucher(int $companyId, int $fiscalYearId, int $orderId, string $orderNo, string $date, int $finishedLedgerId, array $inputCostByLedger, int $userId, array $conversion = []): int
{
    $materialTotal = round(array_sum($inputCostByLedger), 2);
    if ($finishedLedgerId <= 0 || $materialTotal <= 0 || $inputCostByLedger === [] || in_array(0, array_map('intval', array_keys($inputCostByLedger)), true)) {
        return 0;
    }
    $labour = round((float) ($conversion['labour'] ?? 0), 2);
    $overhead = round((float) ($conversion['overhead'] ?? 0), 2);
    $byproduct = round((float) ($conversion['byproduct'] ?? 0), 2);
    $abnormal = round((float) ($conversion['abnormal'] ?? 0), 2);

    // IAS 2 cost accumulation: FG carries materials (net of abnormal waste)
    // + labour + absorbed overhead - by-product value. Abnormal waste is a
    // period expense; the by-product goes to scrap inventory at its value.
    // Dr FG + Dr scrap + Dr loss = Cr materials + Cr labour + Cr overhead.
    $fgDebit = round($materialTotal - $abnormal + $labour + $overhead - $byproduct, 2);
    $entries = [['ledger_id' => $finishedLedgerId, 'entry_type' => 'debit', 'amount' => $fgDebit]];
    if ($byproduct > 0) {
        $scrapL = inv_resolve_mapping($companyId, 'scrap_inventory');
        if (!$scrapL) { throw new RuntimeException('Map Scrap / By-product Inventory before recording a by-product value.'); }
        $entries[] = ['ledger_id' => (int) $scrapL['id'], 'entry_type' => 'debit', 'amount' => $byproduct];
    }
    if ($abnormal > 0) {
        $lossL = inv_resolve_mapping($companyId, 'inventory_loss');
        if (!$lossL) { throw new RuntimeException('Map Inventory Loss before recording abnormal waste.'); }
        $entries[] = ['ledger_id' => (int) $lossL['id'], 'entry_type' => 'debit', 'amount' => $abnormal];
    }
    foreach ($inputCostByLedger as $ledgerId => $amount) {
        $entries[] = ['ledger_id' => (int) $ledgerId, 'entry_type' => 'credit', 'amount' => round((float) $amount, 2)];
    }
    if ($labour > 0) {
        $labL = inv_resolve_mapping($companyId, 'labour_clearing');
        if (!$labL) { throw new RuntimeException('Map Direct Labour Clearing before adding labour cost.'); }
        $entries[] = ['ledger_id' => (int) $labL['id'], 'entry_type' => 'credit', 'amount' => $labour];
    }
    if ($overhead > 0) {
        $ohL = inv_resolve_mapping($companyId, 'overhead_absorbed');
        if (!$ohL) { throw new RuntimeException('Map Production Overhead Absorbed before absorbing overhead.'); }
        $entries[] = ['ledger_id' => (int) $ohL['id'], 'entry_type' => 'credit', 'amount' => $overhead];
    }
    $total = round($materialTotal + $labour + $overhead, 2);
    return (int) create_voucher_with_entries([
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
        'voucher_no' => 'MFG-' . $orderNo,
        'voucher_type' => 'journal',
        'voucher_date' => $date,
        'source_type' => 'manufacturing_order',
        'source_id' => $orderId,
        'total_amount' => $total,
        'narration' => 'Production ' . $orderNo . ': raw material consumed into finished goods.',
        'status' => 'posted',
        'posted_by' => $userId,
    ], $entries);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_item') {
        require_permission('inventory', 'create');
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $sku = strtoupper(trim((string) ($_POST['sku'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $itemType = (string) ($_POST['item_type'] ?? 'stock');
        $status = (string) ($_POST['status'] ?? 'active');
        // When editing, the item may carry a type outside this business
        // profile's list (e.g. finished_good in a trading company): keep it
        // selectable instead of silently converting the item.
        $allowedTypes = $itemTypes;
        if ($itemId > 0) {
            $existing = inventory_company_item($itemId, $companyId);
            if (!$existing) {
                flash('error', 'Item not found for this company.');
                redirect('admin/accounting-inventory.php');
            }
            if (!in_array((string) $existing['item_type'], $allowedTypes, true)) {
                $allowedTypes[] = (string) $existing['item_type'];
            }
        }
        if ($sku === '' || $name === '' || !in_array($itemType, $allowedTypes, true) || !in_array($status, ['active', 'inactive'], true)) {
            flash('error', 'SKU, item name, type, and status are required.');
            redirect('admin/accounting-inventory.php');
        }

        $validMethods = ['fifo', 'weighted_average', 'specific'];
        $valuationMethod = (string) ($_POST['valuation_method'] ?? 'weighted_average');
        if (!in_array($valuationMethod, $validMethods, true)) {
            $valuationMethod = 'weighted_average';
        }
        $params = [
            'company_id' => $companyId,
            'ledger_id' => (int) ($_POST['ledger_id'] ?? 0) ?: null,
            'sku' => $sku,
            'name' => $name,
            'category' => trim((string) ($_POST['category'] ?? '')) ?: null,
            'item_type' => $itemType,
            'valuation_method' => $valuationMethod,
            'unit' => trim((string) ($_POST['unit'] ?? 'pcs')) ?: 'pcs',
            'hs_code' => trim((string) ($_POST['hs_code'] ?? '')) ?: null,
            'tax_rate' => max(0.0, round((float) ($_POST['tax_rate'] ?? 13), 2)),
            'sales_rate' => max(0.0, round((float) ($_POST['sales_rate'] ?? 0), 2)),
            'purchase_rate' => max(0.0, round((float) ($_POST['purchase_rate'] ?? 0), 2)),
            'opening_qty' => max(0.0, round((float) ($_POST['opening_qty'] ?? 0), 3)),
            'reorder_level' => max(0.0, round((float) ($_POST['reorder_level'] ?? 0), 3)),
            'default_warehouse_id' => inventory_company_warehouse_id((int) ($_POST['default_warehouse_id'] ?? 0), $companyId),
            'status' => $status,
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];

        try {
            // One transaction: inv_rebuild_layers() below DELETEs the item's cost
            // layers before replaying them, so a failure part-way through an
            // untransacted rebuild would leave the item with no layers at all and
            // a valuation of zero.
            db()->beginTransaction();
            if ($itemId > 0) {
                $params['id'] = $itemId;
                db()->prepare('
                    UPDATE inventory_items
                    SET ledger_id = :ledger_id, sku = :sku, name = :name, category = :category, item_type = :item_type,
                        valuation_method = :valuation_method, unit = :unit,
                        hs_code = :hs_code, tax_rate = :tax_rate, sales_rate = :sales_rate, purchase_rate = :purchase_rate,
                        opening_qty = :opening_qty, reorder_level = :reorder_level, default_warehouse_id = :default_warehouse_id,
                        status = :status, notes = :notes
                    WHERE id = :id AND company_id = :company_id
                ')->execute($params);
                // Opening qty/rate or method may have changed — rebuild layers.
                inv_rebuild_layers($companyId, $itemId, $valuationMethod, (float) $params['opening_qty'], (float) $params['purchase_rate']);
                log_activity('inventory_item', $itemId, 'updated', 'Inventory item updated.', $userId);
                flash('success', 'Item updated.');
            } else {
                db()->prepare('
                    INSERT INTO inventory_items (
                        company_id, ledger_id, sku, name, category, item_type, valuation_method, unit, hs_code, tax_rate,
                        sales_rate, purchase_rate, opening_qty, reorder_level, default_warehouse_id, status, notes
                    ) VALUES (
                        :company_id, :ledger_id, :sku, :name, :category, :item_type, :valuation_method, :unit, :hs_code, :tax_rate,
                        :sales_rate, :purchase_rate, :opening_qty, :reorder_level, :default_warehouse_id, :status, :notes
                    )
                ')->execute($params);
                $newItemId = (int) db()->lastInsertId();
                // Seed the opening cost layer so valuation is correct from day one.
                if ((float) $params['opening_qty'] > 0) {
                    inv_add_layer($companyId, $newItemId, (float) $params['opening_qty'], (float) $params['purchase_rate'], '2000-01-01');
                }
                log_activity('inventory_item', $newItemId, 'created', 'Inventory item created.', $userId);
                flash('success', 'Item created.');
            }
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', (string) $exception->getCode() === '23000'
                ? 'Could not save item: SKU "' . $sku . '" already exists in this company.'
                : 'Could not save item: ' . $exception->getMessage());
        }
        redirect('admin/accounting-inventory.php');
    }

    if ($action === 'save_warehouse') {
        require_permission('inventory', 'create');
        $warehouseName = trim((string) ($_POST['name'] ?? ''));
        $warehouseCode = trim((string) ($_POST['code'] ?? '')) ?: null;
        if ($warehouseName === '') {
            flash('error', 'Warehouse name is required.');
            redirect('admin/accounting-inventory.php');
        }
        try {
            db()->prepare('INSERT INTO warehouses (company_id, name, code, is_active) VALUES (:company_id, :name, :code, 1)')
                ->execute(['company_id' => $companyId, 'name' => $warehouseName, 'code' => $warehouseCode]);
            security_event('warehouse_created', 'success', 'Warehouse "' . $warehouseName . '" created.', $companyId, $userId);
            flash('success', 'Warehouse "' . $warehouseName . '" created.');
        } catch (Throwable $exception) {
            flash('error', (string) $exception->getCode() === '23000'
                ? 'Could not save warehouse: a warehouse named "' . $warehouseName . '" already exists in this company.'
                : 'Could not save warehouse: ' . $exception->getMessage());
        }
        redirect('admin/accounting-inventory.php');
    }

    if ($action === 'toggle_warehouse') {
        require_permission('inventory', 'edit');
        $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
        $warehouseStmt = db()->prepare('SELECT * FROM warehouses WHERE id = :id AND company_id = :company_id LIMIT 1');
        $warehouseStmt->execute(['id' => $warehouseId, 'company_id' => $companyId]);
        $warehouse = $warehouseStmt->fetch();
        if (!$warehouse) {
            flash('error', 'Warehouse not found for this company.');
            redirect('admin/accounting-inventory.php');
        }
        $newActive = (int) $warehouse['is_active'] === 1 ? 0 : 1;
        db()->prepare('UPDATE warehouses SET is_active = :is_active WHERE id = :id AND company_id = :company_id')
            ->execute(['is_active' => $newActive, 'id' => $warehouseId, 'company_id' => $companyId]);
        security_event('warehouse_toggled', 'success', 'Warehouse #' . $warehouseId . ' ' . ($newActive ? 'activated' : 'deactivated') . '.', $companyId, $userId);
        flash('success', 'Warehouse "' . $warehouse['name'] . '" ' . ($newActive ? 'activated' : 'deactivated') . '.');
        redirect('admin/accounting-inventory.php');
    }

    if ($action === 'record_movement') {
        require_permission('inventory', 'create');
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $type = (string) ($_POST['transaction_type'] ?? 'adjustment');
        $qty = round(abs((float) ($_POST['quantity'] ?? 0)), 3);
        $rate = round((float) ($_POST['rate'] ?? 0), 2);
        $date = inventory_valid_date((string) ($_POST['transaction_date'] ?? '')) ?? date('Y-m-d');
        $warehouseId = inventory_company_warehouse_id((int) ($_POST['warehouse_id'] ?? 0), $companyId);
        $toWarehouseId = inventory_company_warehouse_id((int) ($_POST['to_warehouse_id'] ?? 0), $companyId);
        $refNo = trim((string) ($_POST['ref_no'] ?? '')) ?: null;
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        if ($qty <= 0 || !in_array($type, $movementTypes, true)) {
            flash('error', 'Select an item, movement type, and positive quantity.');
            redirect('admin/accounting-inventory.php');
        }
        $item = inventory_company_item($itemId, $companyId);
        if (!$item) {
            flash('error', 'Item not found for this company.');
            redirect('admin/accounting-inventory.php');
        }
        $method = (string) ($item['valuation_method'] ?? 'weighted_average');

        // Warehouse / departmental transfers relocate stock inside the entity:
        // two linked rows (out of the source, in to the destination) and nothing
        // else. The company still owns the same units at the same cost, so the
        // cost layers are deliberately NOT touched (consuming them on the way out
        // and re-adding on the way in would re-order the FIFO queue and mis-state
        // later COGS) and no GL voucher is posted. Cost here is informational
        // only, stamped from the item's current carrying cost.
        if (inv_movement_is_location_only($type)) {
            if ($warehouseId === null || $toWarehouseId === null || $warehouseId === $toWarehouseId) {
                flash('error', 'Select two different warehouses for a transfer (from and to).');
                redirect('admin/accounting-inventory.php');
            }
            // Availability must be checked at the SOURCE warehouse, not company-
            // wide: the company can hold plenty of an item while the warehouse
            // being transferred out of holds none, which would otherwise drive
            // that location negative and invent stock at the destination.
            $sourceQty = inv_item_warehouse_qty($companyId, $itemId, $warehouseId);
            if ($qty > $sourceQty + 0.0005) {
                flash('error', 'Insufficient stock at the source warehouse: it holds ' . number_format($sourceQty, 3) . ' ' . $item['unit'] . ' of ' . $item['sku'] . ' (company-wide on hand is ' . number_format((float) $item['on_hand'], 3) . ', but stock can only move out of the location that actually holds it).');
                redirect('admin/accounting-inventory.php');
            }
            // Informational unit cost: the item's current carrying cost per unit.
            // The layers are not consumed, so nothing is actually drawn down here.
            $balance = inv_layer_balance($companyId, $itemId);
            $unitCostAtIssue = $balance['qty'] > 0.00005 ? round($balance['value'] / $balance['qty'], 6) : 0.0;
            $transferValue = round($qty * $unitCostAtIssue, 2);
            try {
                db()->beginTransaction();
                $insertTxn = db()->prepare('
                    INSERT INTO inventory_transactions (
                        company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date,
                        warehouse_id, to_warehouse_id, qty_in, qty_out, rate, amount, notes
                    ) VALUES (
                        :company_id, :fiscal_year_id, :item_id, :transaction_type, :ref_no, :transaction_date,
                        :warehouse_id, :to_warehouse_id, :qty_in, :qty_out, :rate, :amount, :notes
                    )
                ');
                // OUT leg from the source warehouse.
                $insertTxn->execute([
                    'company_id' => $companyId,
                    'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                    'item_id' => $itemId,
                    'transaction_type' => $type,
                    'ref_no' => $refNo,
                    'transaction_date' => $date,
                    'warehouse_id' => $warehouseId,
                    'to_warehouse_id' => $toWarehouseId,
                    'qty_in' => 0,
                    'qty_out' => $qty,
                    'rate' => $unitCostAtIssue,
                    'amount' => $transferValue,
                    'notes' => $notes,
                ]);
                $outTxnId = (int) db()->lastInsertId();

                // IN leg at the destination, at the same carrying cost.
                $insertTxn->execute([
                    'company_id' => $companyId,
                    'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                    'item_id' => $itemId,
                    'transaction_type' => $type,
                    'ref_no' => $refNo,
                    'transaction_date' => $date,
                    'warehouse_id' => $toWarehouseId,
                    'to_warehouse_id' => $warehouseId,
                    'qty_in' => $qty,
                    'qty_out' => 0,
                    'rate' => $unitCostAtIssue,
                    'amount' => round($transferValue, 2),
                    'notes' => 'Transfer in — paired with movement #' . $outTxnId,
                ]);
                $inTxnId = (int) db()->lastInsertId();
                db()->commit();
                security_event('inventory_movement_posted', 'success', 'Transfer #' . $outTxnId . '/' . $inTxnId . ' (' . $type . ') posted for item #' . $itemId . '.', $companyId, $userId);
                flash('success', 'Transfer recorded: ' . number_format($qty, 3) . ' ' . $item['unit'] . ' ' . $item['sku'] . ' moved. No GL entry — quantity relocated only (IAS 2 recognition unaffected).');
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                flash('error', 'Could not record transfer: ' . $exception->getMessage());
            }
            redirect('admin/accounting-inventory.php');
        }

        // Adjustments choose their own direction (stock count corrections go
        // both ways); every other type has a fixed one.
        $direction = $type === 'adjustment' && in_array((string) ($_POST['direction'] ?? ''), ['in', 'out'], true)
            ? (string) $_POST['direction']
            : inventory_direction($type);
        if ($type === 'opening' && (float) $item['opening_qty'] > 0) {
            flash('error', 'This item already has an opening quantity (' . number_format((float) $item['opening_qty'], 3) . ') on its master record — edit the item instead of recording a second opening, or stock would be double-counted.');
            redirect('admin/accounting-inventory.php');
        }
        if ($direction === 'out' && $qty > (float) $item['on_hand'] + 0.0005) {
            flash('error', 'Insufficient stock: only ' . number_format((float) $item['on_hand'], 3) . ' ' . $item['unit'] . ' of ' . $item['sku'] . ' on hand. Record a purchase or an inward adjustment first.');
            redirect('admin/accounting-inventory.php');
        }
        if ($rate <= 0) {
            $rate = $type === 'sale' ? (float) $item['sales_rate'] : (float) $item['purchase_rate'];
        }
        $qtyIn = $direction === 'in' ? $qty : 0.0;
        $qtyOut = $direction === 'out' ? $qty : 0.0;
        try {
            db()->beginTransaction();
            $insertTxn = db()->prepare('
                INSERT INTO inventory_transactions (
                    company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date,
                    warehouse_id, qty_in, qty_out, rate, amount, notes
                ) VALUES (
                    :company_id, :fiscal_year_id, :item_id, :transaction_type, :ref_no, :transaction_date,
                    :warehouse_id, :qty_in, :qty_out, :rate, :amount, :notes
                )
            ');
            $insertTxn->execute([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                'item_id' => $itemId,
                'transaction_type' => $type,
                'ref_no' => $refNo,
                'transaction_date' => $date,
                'warehouse_id' => $warehouseId,
                'qty_in' => $qtyIn,
                'qty_out' => $qtyOut,
                'rate' => $rate,
                'amount' => round($qty * $rate, 2),
                'notes' => $notes,
            ]);
            $txnId = (int) db()->lastInsertId();
            // Maintain the perpetual cost layers so on-hand VALUE is real IAS 2
            // cost (FIFO / moving average / specific), not a rate estimate. An
            // outward issue draws down layers at the item's cost-flow cost.
            $issueValue = inv_apply_movement($companyId, $itemId, $qtyIn, $qtyOut, $rate, $date, $method, $txnId, $warehouseId);
            // Post the balanced GL voucher per the section-E matrix. Inward legs
            // are valued at cost put in (qty*rate); outward legs at the cost-flow
            // COGS drawn from the layers. Missing mappings record stock-only.
            $postingValue = $direction === 'in' ? round($qty * $rate, 2) : $issueValue;
            $movementVoucherId = 0;
            $mapMissing = [];
            try {
                $movementVoucherId = inv_post_movement_voucher($companyId, $fiscalYearId, $txnId, $type, $item, $direction, $postingValue, $date, $userId);
            } catch (RuntimeException $mapEx) {
                if (str_starts_with($mapEx->getMessage(), 'MAP_MISSING:')) {
                    $mapMissing = explode(',', substr($mapEx->getMessage(), 12));
                } else {
                    throw $mapEx;
                }
            }
            if ($movementVoucherId > 0) {
                db()->prepare('UPDATE inventory_transactions SET voucher_id = :vid WHERE id = :id AND company_id = :cid')
                    ->execute(['vid' => $movementVoucherId, 'id' => $txnId, 'cid' => $companyId]);
            }
            db()->commit();
            $costNote = $qtyOut > 0 ? ' Issue cost (' . strtoupper(str_replace('_', ' ', $method)) . '): ' . site_currency_symbol() . number_format($issueValue, 2) . '.' : '';
            $glNote = '';
            if ($movementVoucherId > 0) {
                $glNote = ' GL voucher posted (' . site_currency_symbol() . number_format($postingValue, 2) . ').';
            } elseif ($mapMissing !== []) {
                $labels = array_map(static fn (string $p): string => inventory_mapping_purposes()[$p]['label'] ?? $p, $mapMissing);
                $glNote = ' Stock recorded — map ' . implode(' & ', $labels) . ' on the Ledger Mapping tab to auto-post the accounting voucher.';
            }
            security_event('inventory_movement_posted', 'success', 'Movement #' . $txnId . ' (' . $type . ') posted for item #' . $itemId . '.', $companyId, $userId);
            flash('success', 'Inventory movement recorded: ' . ($direction === 'in' ? '+' : '−') . number_format($qty, 3) . ' ' . $item['unit'] . ' ' . $item['sku'] . '.' . $costNote . $glNote);
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not record movement: ' . $exception->getMessage());
        }
        redirect('admin/accounting-inventory.php');
    }

    if ($action === 'post_nrv_assessment') {
        require_permission('inventory', 'edit');
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $item = inventory_company_item($itemId, $companyId);
        if (!$item) {
            flash('error', 'Item not found for this company.');
            redirect('admin/accounting-inventory.php?view=valuation');
        }
        $sellingPrice = round((float) ($_POST['selling_price'] ?? 0), 2);
        $completionCost = round((float) ($_POST['completion_cost'] ?? 0), 2);
        $sellingCost = round((float) ($_POST['selling_cost'] ?? 0), 2);

        $valuation = inv_item_valuation($companyId, $item);
        $qty = (float) $valuation['qty'];
        $unitCost = (float) $valuation['unit_cost'];

        $priorStmt = db()->prepare('SELECT COALESCE(SUM(write_down), 0) - COALESCE(SUM(reversal), 0) FROM inventory_nrv_assessments WHERE company_id = :company_id AND item_id = :item_id');
        $priorStmt->execute(['company_id' => $companyId, 'item_id' => $itemId]);
        $priorWriteDown = max(0.0, (float) $priorStmt->fetchColumn());

        $nrv = inv_nrv($qty, $unitCost, $sellingPrice, $completionCost, $sellingCost, $priorWriteDown);

        if ($nrv['write_down'] <= 0 && $nrv['reversal'] <= 0) {
            flash('info', 'No write-down or reversal needed — carrying value already at lower of cost and NRV.');
            redirect('admin/accounting-inventory.php?view=valuation');
        }

        $today = date('Y-m-d');
        $isWriteDown = $nrv['write_down'] > 0;
        $movementType = $isWriteDown ? 'nrv_write_down' : 'nrv_reversal';
        $postedValue = $isWriteDown ? $nrv['write_down'] : $nrv['reversal'];

        try {
            db()->beginTransaction();
            $assessStmt = db()->prepare('
                INSERT INTO inventory_nrv_assessments (
                    company_id, fiscal_year_id, item_id, assessment_date, quantity, cost_per_unit,
                    selling_price, completion_cost, selling_cost, nrv_per_unit, lower_per_unit,
                    carrying_cost, prior_write_down, write_down, reversal, final_carrying, created_by
                ) VALUES (
                    :company_id, :fiscal_year_id, :item_id, :assessment_date, :quantity, :cost_per_unit,
                    :selling_price, :completion_cost, :selling_cost, :nrv_per_unit, :lower_per_unit,
                    :carrying_cost, :prior_write_down, :write_down, :reversal, :final_carrying, :created_by
                )
            ');
            $assessStmt->execute([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                'item_id' => $itemId,
                'assessment_date' => $today,
                'quantity' => $qty,
                'cost_per_unit' => $unitCost,
                'selling_price' => $sellingPrice,
                'completion_cost' => $completionCost,
                'selling_cost' => $sellingCost,
                'nrv_per_unit' => $nrv['nrv_per_unit'],
                'lower_per_unit' => $nrv['lower_per_unit'],
                'carrying_cost' => $nrv['carrying_cost'],
                'prior_write_down' => $nrv['prior_write_down'],
                'write_down' => $nrv['write_down'],
                'reversal' => $nrv['reversal'],
                'final_carrying' => $nrv['final_carrying'],
                'created_by' => $userId,
            ]);
            $assessmentId = (int) db()->lastInsertId();

            $movementStmt = db()->prepare('
                INSERT INTO inventory_transactions (
                    company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date,
                    qty_in, qty_out, rate, amount, notes
                ) VALUES (
                    :company_id, :fiscal_year_id, :item_id, :transaction_type, :ref_no, :transaction_date,
                    0, 0, 0, :amount, :notes
                )
            ');
            $movementStmt->execute([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                'item_id' => $itemId,
                'transaction_type' => $movementType,
                'ref_no' => 'NRV-' . date('Ymd'),
                'transaction_date' => $today,
                'amount' => $postedValue,
                'notes' => ($isWriteDown ? 'NRV write-down' : 'NRV reversal') . ' — ' . $item['sku'] . ' ' . $item['name'],
            ]);
            $txnId = (int) db()->lastInsertId();

            $movementVoucherId = 0;
            $mapMissing = [];
            try {
                $movementVoucherId = inv_post_movement_voucher($companyId, $fiscalYearId, $txnId, $movementType, $item, 'out', $postedValue, $today, $userId);
            } catch (RuntimeException $mapEx) {
                if (str_starts_with($mapEx->getMessage(), 'MAP_MISSING:')) {
                    $mapMissing = explode(',', substr($mapEx->getMessage(), 12));
                } else {
                    throw $mapEx;
                }
            }
            if ($movementVoucherId > 0) {
                db()->prepare('UPDATE inventory_transactions SET voucher_id = :vid WHERE id = :id AND company_id = :cid')
                    ->execute(['vid' => $movementVoucherId, 'id' => $txnId, 'cid' => $companyId]);
                db()->prepare('UPDATE inventory_nrv_assessments SET voucher_id = :vid WHERE id = :id AND company_id = :cid')
                    ->execute(['vid' => $movementVoucherId, 'id' => $assessmentId, 'cid' => $companyId]);
            }
            db()->commit();

            $glNote = '';
            if ($movementVoucherId > 0) {
                $glNote = ' GL voucher posted (' . site_currency_symbol() . number_format($postedValue, 2) . ').';
            } elseif ($mapMissing !== []) {
                $labels = array_map(static fn (string $p): string => inventory_mapping_purposes()[$p]['label'] ?? $p, $mapMissing);
                $glNote = ' Stock-only recorded — map ' . implode(' & ', $labels) . ' on the Ledger Mapping tab to auto-post the accounting voucher.';
            }
            security_event('inventory_nrv_posted', 'success', ($isWriteDown ? 'NRV write-down ' : 'NRV reversal ') . number_format($postedValue, 2) . ' posted for item #' . $itemId . '.', $companyId, $userId);
            flash('success', ($isWriteDown ? 'NRV write-down of ' : 'NRV reversal of ') . site_currency_symbol() . number_format($postedValue, 2) . ' posted for ' . $item['sku'] . '.' . $glNote);
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not post NRV assessment: ' . $exception->getMessage());
        }
        redirect('admin/accounting-inventory.php?view=valuation');
    }

    if ($action === 'delete_movement') {
        if ((string) ($currentUser['role'] ?? '') !== 'admin') {
            flash('error', 'Only an administrator can delete stock movements.');
            redirect('admin/accounting-inventory.php');
        }
        $movementId = (int) ($_POST['movement_id'] ?? 0);
        $mvStmt = db()->prepare("SELECT * FROM inventory_transactions WHERE id = :id AND company_id = :cid AND transaction_type NOT IN ('consume', 'produce') LIMIT 1");
        $mvStmt->execute(['id' => $movementId, 'cid' => $companyId]);
        $movement = $mvStmt->fetch(PDO::FETCH_ASSOC);
        if (!$movement) {
            flash('error', 'Movement not found, or it belongs to a manufacturing order (cancel the order instead).');
            redirect('admin/accounting-inventory.php');
        }
        // A transfer is a PAIR of rows (out of the source, in to the destination).
        // Deleting or reversing one leg on its own would leave the other standing,
        // inventing stock at one location and destroying it at the other. Move it
        // back with a transfer in the opposite direction instead.
        if (inv_movement_is_location_only((string) $movement['transaction_type'])) {
            flash('error', 'This is one leg of a transfer. Deleting a single leg would leave stock stranded at the other location — record a transfer in the opposite direction instead.');
            redirect('admin/accounting-inventory.php');
        }
        // A movement that posted a GL voucher must not be silently deleted —
        // that would orphan a posted voucher. Reverse it instead (spec E).
        if ((int) ($movement['voucher_id'] ?? 0) > 0) {
            flash('error', 'This movement posted an accounting voucher — use "Reverse" instead of delete, so both the stock and the voucher are reversed and the audit trail is preserved.');
            redirect('admin/accounting-inventory.php');
        }
        try {
            db()->beginTransaction();
            db()->prepare('DELETE FROM inventory_transactions WHERE id = :id AND company_id = :cid')->execute(['id' => $movementId, 'cid' => $companyId]);
            inv_rebuild_item($companyId, (int) $movement['item_id']); // recompute cost layers
            db()->commit();
            security_event('inventory_movement_deleted', 'success', 'Movement #' . $movementId . ' deleted.', $companyId, $userId);
            log_activity('inventory_transaction', $movementId, 'deleted', 'Stock-only movement deleted.', $userId);
            flash('success', 'Stock movement deleted and cost layers recalculated.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) { db()->rollBack(); }
            flash('error', 'Could not delete movement: ' . $e->getMessage());
        }
        redirect('admin/accounting-inventory.php');
    }

    if ($action === 'reverse_movement') {
        if ((string) ($currentUser['role'] ?? '') !== 'admin') {
            flash('error', 'Only an administrator can reverse stock movements.');
            redirect('admin/accounting-inventory.php');
        }
        $movementId = (int) ($_POST['movement_id'] ?? 0);
        $mvStmt = db()->prepare("SELECT * FROM inventory_transactions WHERE id = :id AND company_id = :cid AND transaction_type NOT IN ('consume', 'produce') LIMIT 1");
        $mvStmt->execute(['id' => $movementId, 'cid' => $companyId]);
        $movement = $mvStmt->fetch(PDO::FETCH_ASSOC);
        if (!$movement) {
            flash('error', 'Movement not found (production rows are reversed by cancelling the order).');
            redirect('admin/accounting-inventory.php');
        }
        if (inv_movement_is_location_only((string) $movement['transaction_type'])) {
            flash('error', 'This is one leg of a transfer. Reversing a single leg would leave stock stranded at the other location — record a transfer in the opposite direction instead.');
            redirect('admin/accounting-inventory.php');
        }
        $revItem = inventory_company_item((int) $movement['item_id'], $companyId);
        try {
            db()->beginTransaction();
            // Post a mirror stock movement (opposite direction, same qty/rate).
            $revIn = (float) $movement['qty_out'];
            $revOut = (float) $movement['qty_in'];
            $today = date('Y-m-d');
            db()->prepare('INSERT INTO inventory_transactions (company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date, qty_in, qty_out, rate, amount, notes)
                VALUES (:cid, :fy, :iid, :type, :ref, :d, :qin, :qout, :rate, :amt, :notes)')
                ->execute([
                    'cid' => $companyId, 'fy' => $fiscalYearId > 0 ? $fiscalYearId : null, 'iid' => (int) $movement['item_id'],
                    'type' => (string) $movement['transaction_type'], 'ref' => 'REV-' . $movementId,
                    'd' => $today, 'qin' => $revIn, 'qout' => $revOut, 'rate' => (float) $movement['rate'],
                    'amt' => round(($revIn + $revOut) * (float) $movement['rate'], 2),
                    'notes' => 'Reversal of movement #' . $movementId,
                ]);
            $revTxnId = (int) db()->lastInsertId();
            inv_rebuild_item($companyId, (int) $movement['item_id']); // net the layers

            // Reverse the original voucher by swapping its Dr/Cr, never deleting it.
            $reversalVoucherId = 0;
            $origVoucherId = (int) ($movement['voucher_id'] ?? 0);
            if ($origVoucherId > 0) {
                $entries = db()->prepare('SELECT ledger_id, entry_type, amount FROM voucher_entries WHERE voucher_id = :vid');
                $entries->execute(['vid' => $origVoucherId]);
                $reversed = [];
                $total = 0.0;
                foreach ($entries->fetchAll(PDO::FETCH_ASSOC) as $en) {
                    $reversed[] = ['ledger_id' => (int) $en['ledger_id'], 'entry_type' => $en['entry_type'] === 'debit' ? 'credit' : 'debit', 'amount' => (float) $en['amount']];
                    if ($en['entry_type'] === 'debit') { $total += (float) $en['amount']; }
                }
                if ($reversed !== []) {
                    $reversalVoucherId = (int) create_voucher_with_entries([
                        'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                        'voucher_no' => 'INV-REV-' . str_pad((string) $revTxnId, 6, '0', STR_PAD_LEFT),
                        'voucher_type' => 'journal', 'voucher_date' => $today,
                        'source_type' => 'inventory_movement', 'source_id' => $revTxnId,
                        'total_amount' => round($total, 2),
                        'narration' => 'Reversal of voucher #' . $origVoucherId . ' (movement #' . $movementId . ').',
                        'status' => 'posted', 'posted_by' => $userId,
                    ], $reversed);
                    db()->prepare('UPDATE inventory_transactions SET voucher_id = :vid WHERE id = :id')->execute(['vid' => $reversalVoucherId, 'id' => $revTxnId]);
                }
            }
            db()->commit();
            security_event('inventory_movement_reversed', 'success', 'Movement #' . $movementId . ' reversed.', $companyId, $userId);
            log_activity('inventory_transaction', $movementId, 'reversed', 'Movement reversed (stock + voucher).', $userId);
            flash('success', 'Movement #' . $movementId . ' reversed: a mirror stock entry was posted' . ($reversalVoucherId > 0 ? ' and the accounting voucher was reversed (Dr/Cr swapped).' : '.') . ' The original records are preserved for audit.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) { db()->rollBack(); }
            flash('error', 'Could not reverse movement: ' . $e->getMessage());
        }
        redirect('admin/accounting-inventory.php');
    }

    if ($action === 'save_inventory_mappings') {
        require_permission('inventory', 'edit');
        // Global-default ledger mappings for inventory posting purposes. Each
        // ledger is validated to belong to this company before it is stored.
        $purposes = array_keys(inventory_mapping_purposes());
        $saved = 0;
        foreach ($purposes as $purpose) {
            $ledgerId = (int) ($_POST['map'][$purpose] ?? 0);
            if ($ledgerId <= 0) {
                db()->prepare('DELETE FROM inventory_ledger_mappings WHERE company_id = :cid AND scope = \'global\' AND category IS NULL AND item_id IS NULL AND purpose = :p')
                    ->execute(['cid' => $companyId, 'p' => $purpose]);
                continue;
            }
            $own = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :id AND company_id = :cid');
            $own->execute(['id' => $ledgerId, 'cid' => $companyId]);
            if ((int) $own->fetchColumn() === 0) {
                continue; // never map a foreign company's ledger
            }
            db()->prepare('
                INSERT INTO inventory_ledger_mappings (company_id, scope, category, item_id, purpose, ledger_id, created_by)
                VALUES (:cid, \'global\', NULL, NULL, :p, :lid, :uid)
                ON DUPLICATE KEY UPDATE ledger_id = VALUES(ledger_id), created_by = VALUES(created_by)
            ')->execute(['cid' => $companyId, 'p' => $purpose, 'lid' => $ledgerId, 'uid' => $userId]);
            $saved++;
        }
        log_activity('inventory_mapping', $companyId, 'updated', 'Inventory ledger mappings updated (' . $saved . ' set).', $userId);
        flash('success', 'Inventory ledger mappings saved (' . $saved . ' purpose' . ($saved === 1 ? '' : 's') . ' mapped).');
        redirect('admin/accounting-inventory.php?view=mapping');
    }

    if ($action === 'save_bom') {
        require_permission('inventory', 'create');
        if (!($inventoryProfile['show_manufacturing'] ?? false)) {
            flash('error', 'BOMs are available only for manufacturing companies.');
            redirect('admin/accounting-inventory.php');
        }
        $bomNo = strtoupper(trim((string) ($_POST['bom_no'] ?? ''))) ?: ('BOM-' . date('ymdHis'));
        $finishedItemId = (int) ($_POST['finished_item_id'] ?? 0);
        $outputQty = max(0.001, round((float) ($_POST['output_qty'] ?? 1), 3));
        $stdLabour = max(0.0, round((float) ($_POST['std_labour_cost'] ?? 0), 2));
        $stdOverhead = max(0.0, round((float) ($_POST['std_overhead_cost'] ?? 0), 2));
        $finishedItem = inventory_company_item($finishedItemId, $companyId);
        if (!$finishedItem) {
            flash('error', 'Select the finished product for this BOM.');
            redirect('admin/accounting-inventory.php?view=manufacturing');
        }
        $lineItems = $_POST['bom_item_id'] ?? [];
        $lineQtys = $_POST['bom_qty'] ?? [];
        $lineWastes = $_POST['bom_waste'] ?? [];
        $lineRates = $_POST['bom_rate'] ?? [];
        $lines = [];
        foreach ($lineItems as $i => $raw) {
            $lid = (int) $raw;
            $lqty = round((float) ($lineQtys[$i] ?? 0), 4);
            if ($lid <= 0 || $lqty <= 0 || $lid === $finishedItemId) {
                continue;
            }
            $component = inventory_company_item($lid, $companyId);
            if (!$component) {
                continue; // never accept a foreign company's item id
            }
            $lrate = round((float) ($lineRates[$i] ?? 0), 6);
            $lines[] = ['item_id' => $lid, 'qty' => $lqty, 'waste' => max(0.0, round((float) ($lineWastes[$i] ?? 0), 3)), 'rate' => $lrate > 0 ? $lrate : (float) $component['purchase_rate']];
        }
        if ($lines === []) {
            flash('error', 'Add at least one component line to the BOM.');
            redirect('admin/accounting-inventory.php?view=manufacturing');
        }
        try {
            db()->beginTransaction();
            db()->prepare('INSERT INTO bom_headers (company_id, bom_no, version, finished_item_id, output_qty, std_labour_cost, std_overhead_cost, status, created_by)
                VALUES (:cid, :no, 1, :fid, :out, :lab, :oh, \'active\', :uid)')
                ->execute(['cid' => $companyId, 'no' => $bomNo, 'fid' => $finishedItemId, 'out' => $outputQty, 'lab' => $stdLabour, 'oh' => $stdOverhead, 'uid' => $userId]);
            $newBomId = (int) db()->lastInsertId();
            $lineStmt = db()->prepare('INSERT INTO bom_lines (bom_id, item_id, std_qty, waste_pct, std_rate) VALUES (:bid, :iid, :q, :w, :r)');
            foreach ($lines as $l) {
                $lineStmt->execute(['bid' => $newBomId, 'iid' => $l['item_id'], 'q' => $l['qty'], 'w' => $l['waste'], 'r' => $l['rate']]);
            }
            db()->commit();
            log_activity('bom', $newBomId, 'created', 'BOM ' . $bomNo . ' created (' . count($lines) . ' lines).', $userId);
            flash('success', 'BOM ' . $bomNo . ' saved for ' . $finishedItem['sku'] . ' (' . count($lines) . ' component lines). Pick it on the production order form to prefill materials and get variance reporting.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) { db()->rollBack(); }
            flash('error', (string) $e->getCode() === '23000' ? 'BOM number ' . $bomNo . ' already exists.' : 'Could not save BOM: ' . $e->getMessage());
        }
        redirect('admin/accounting-inventory.php?view=manufacturing');
    }

    if ($action === 'create_manufacturing_order') {
        require_permission('inventory', 'create');
        if (!($inventoryProfile['show_manufacturing'] ?? false)) {
            flash('error', 'Manufacturing orders are available only for manufacturing companies.');
            redirect('admin/accounting-inventory.php');
        }
        $orderNo = strtoupper(trim((string) ($_POST['order_no'] ?? '')));
        $finishedItemId = (int) ($_POST['finished_item_id'] ?? 0);
        $quantity = round((float) ($_POST['quantity'] ?? 0), 3);
        $mode = (string) ($_POST['production_mode'] ?? 'complete') === 'start' ? 'start' : 'complete';
        // Conversion costs (IAS 2 cost accumulation) + optional BOM link.
        $labourCost = max(0.0, round((float) ($_POST['labour_cost'] ?? 0), 2));
        $overheadAbsorbed = max(0.0, round((float) ($_POST['overhead_absorbed'] ?? 0), 2));
        $byproductValue = max(0.0, round((float) ($_POST['byproduct_value'] ?? 0), 2));
        $abnormalWaste = max(0.0, round((float) ($_POST['abnormal_waste_cost'] ?? 0), 2));
        $bomId = (int) ($_POST['bom_id'] ?? 0);
        $bom = $bomId > 0 ? mfg_load_bom($companyId, $bomId) : null;
        $inputItemIds = $_POST['input_item_id'] ?? [];
        $inputQuantities = $_POST['input_quantity'] ?? [];
        $inputRates = $_POST['input_rate'] ?? [];

        if ($orderNo === '') {
            $orderNo = 'MO-' . date('Ymd-His');
        }
        $startedOn = inventory_valid_date((string) ($_POST['started_on'] ?? '')) ?? date('Y-m-d');
        $completedOn = inventory_valid_date((string) ($_POST['completed_on'] ?? '')) ?? date('Y-m-d');
        $finishedItem = inventory_company_item($finishedItemId, $companyId);
        if (!$finishedItem || $quantity <= 0) {
            flash('error', 'Finished item and quantity are required.');
            redirect('admin/accounting-inventory.php?view=manufacturing');
        }

        // Validate the input lines up front: company ownership, no self-
        // consumption, and enough stock to issue the materials.
        $inputs = [];
        foreach ($inputItemIds as $index => $inputItemIdRaw) {
            $inputItemId = (int) $inputItemIdRaw;
            $inputQty = round((float) ($inputQuantities[$index] ?? 0), 3);
            $inputRate = round((float) ($inputRates[$index] ?? 0), 2);
            if ($inputItemId <= 0 || $inputQty <= 0) {
                continue;
            }
            if ($inputItemId === $finishedItemId) {
                flash('error', 'The finished item cannot also be one of its own input materials.');
                redirect('admin/accounting-inventory.php?view=manufacturing');
            }
            $inputItem = inventory_company_item($inputItemId, $companyId);
            if (!$inputItem) {
                flash('error', 'Input item not found for this company.');
                redirect('admin/accounting-inventory.php?view=manufacturing');
            }
            if ($inputQty > (float) $inputItem['on_hand'] + 0.0005) {
                flash('error', 'Insufficient stock of ' . $inputItem['sku'] . ': ' . number_format((float) $inputItem['on_hand'], 3) . ' ' . $inputItem['unit'] . ' on hand, ' . number_format($inputQty, 3) . ' required.');
                redirect('admin/accounting-inventory.php?view=manufacturing');
            }
            if ($inputRate <= 0) {
                $inputRate = round((float) $inputItem['purchase_rate'], 2);
            }
            $inputs[] = ['item' => $inputItem, 'qty' => $inputQty, 'rate' => $inputRate];
        }
        if ($inputs === []) {
            flash('error', 'Add at least one input material line (item and quantity).');
            redirect('admin/accounting-inventory.php?view=manufacturing');
        }

        try {
            db()->beginTransaction();
            $stmt = db()->prepare('
                INSERT INTO manufacturing_orders (company_id, fiscal_year_id, order_no, finished_item_id, bom_id, quantity, labour_cost, overhead_absorbed, byproduct_value, abnormal_waste_cost, status, started_on, completed_on, notes)
                VALUES (:company_id, :fiscal_year_id, :order_no, :finished_item_id, :bom_id, :quantity, :labour_cost, :overhead_absorbed, :byproduct_value, :abnormal_waste_cost, :status, :started_on, :completed_on, :notes)
            ');
            $stmt->execute([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                'order_no' => $orderNo,
                'finished_item_id' => $finishedItemId,
                'bom_id' => $bom ? $bomId : null,
                'quantity' => $quantity,
                'labour_cost' => $labourCost,
                'overhead_absorbed' => $overheadAbsorbed,
                'byproduct_value' => $byproductValue,
                'abnormal_waste_cost' => $abnormalWaste,
                'status' => $mode === 'start' ? 'in_progress' : 'completed',
                'started_on' => $startedOn,
                'completed_on' => $mode === 'start' ? null : $completedOn,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);
            $orderId = (int) db()->lastInsertId();
            $inputStmt = db()->prepare('INSERT INTO manufacturing_order_inputs (manufacturing_order_id, item_id, quantity, rate) VALUES (:order_id, :item_id, :quantity, :rate)');
            $movementStmt = db()->prepare('
                INSERT INTO inventory_transactions (company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date, qty_in, qty_out, rate, amount, notes)
                VALUES (:company_id, :fiscal_year_id, :item_id, :transaction_type, :ref_no, :transaction_date, :qty_in, :qty_out, :rate, :amount, :notes)
            ');
            $totalInputCost = 0.0;
            $inputCostByLedger = [];
            foreach ($inputs as $input) {
                $inputStmt->execute(['order_id' => $orderId, 'item_id' => (int) $input['item']['id'], 'quantity' => $input['qty'], 'rate' => $input['rate']]);
                $amount = round($input['qty'] * $input['rate'], 2);
                $totalInputCost += $amount;
                $inputLedgerId = (int) ($input['item']['ledger_id'] ?? 0);
                $inputCostByLedger[$inputLedgerId] = ($inputCostByLedger[$inputLedgerId] ?? 0.0) + $amount;
                // Materials are issued to production when the order starts.
                $movementStmt->execute([
                    'company_id' => $companyId,
                    'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                    'item_id' => (int) $input['item']['id'],
                    'transaction_type' => 'consume',
                    'ref_no' => $orderNo,
                    'transaction_date' => $startedOn,
                    'qty_in' => 0,
                    'qty_out' => $input['qty'],
                    'rate' => $input['rate'],
                    'amount' => $amount,
                    'notes' => 'Materials issued to ' . $orderNo,
                ]);
            }

            if ($mode === 'start') {
                foreach ($inputs as $input) {
                    inv_rebuild_item($companyId, (int) $input['item']['id']);
                }
                db()->commit();
                log_activity('manufacturing_order', $orderId, 'started', 'Production started (WIP).', $userId);
                flash('success', 'Production order ' . $orderNo . ' started: materials issued, value now sits in Work in Progress. Complete it from the orders table below.');
                redirect('admin/accounting-inventory.php?view=manufacturing');
            }

            // IAS 2 absorbed cost: materials (net of abnormal waste) + labour +
            // overhead - by-product value. Abnormal waste never enters FG cost.
            $orderCost = mfg_order_cost($totalInputCost, $labourCost, $overheadAbsorbed, $byproductValue, $abnormalWaste, $quantity);
            $finishedRate = $orderCost['unit_cost'];
            $movementStmt->execute([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                'item_id' => $finishedItemId,
                'transaction_type' => 'produce',
                'ref_no' => $orderNo,
                'transaction_date' => $completedOn,
                'qty_in' => $quantity,
                'qty_out' => 0,
                'rate' => $finishedRate,
                'amount' => $orderCost['inventoriable'],
                'notes' => 'Finished goods from ' . $orderNo . ' at absorbed cost',
            ]);
            $voucherId = inventory_post_production_voucher($companyId, $fiscalYearId, $orderId, $orderNo, $completedOn, (int) ($finishedItem['ledger_id'] ?? 0), $inputCostByLedger, $userId, [
                'labour' => $labourCost, 'overhead' => $overheadAbsorbed,
                'byproduct' => $byproductValue, 'abnormal' => $abnormalWaste,
            ]);
            // Variances vs the BOM standard, when this order was built from one.
            if ($bom) {
                $actualLines = array_map(static fn (array $in): array => ['item_id' => (int) $in['item']['id'], 'qty' => $in['qty'], 'rate' => $in['rate']], $inputs);
                $mv = mfg_material_variances($actualLines, $bom['lines'], (float) $bom['output_qty'], $quantity);
                $cv = mfg_conversion_variances($labourCost, $overheadAbsorbed, (float) $bom['std_labour_cost'], (float) $bom['std_overhead_cost'], (float) $bom['output_qty'], $quantity);
                $stdMat = mfg_standard_material_cost($bom['lines'], (float) $bom['output_qty'], $quantity);
                mfg_record_variances($companyId, $orderId, [
                    'material_price' => ['standard' => $stdMat, 'actual' => $totalInputCost, 'variance' => $mv['price']],
                    'material_usage' => ['standard' => $stdMat, 'actual' => $totalInputCost, 'variance' => $mv['usage']],
                    'labour' => ['standard' => (float) $bom['std_labour_cost'] * ($quantity / max(0.001, (float) $bom['output_qty'])), 'actual' => $labourCost, 'variance' => $cv['labour']],
                    'overhead' => ['standard' => (float) $bom['std_overhead_cost'] * ($quantity / max(0.001, (float) $bom['output_qty'])), 'actual' => $overheadAbsorbed, 'variance' => $cv['overhead']],
                ]);
            }
            if ($voucherId > 0) {
                db()->prepare("UPDATE inventory_transactions SET voucher_id = :vid WHERE company_id = :cid AND ref_no = :ref AND transaction_type IN ('consume', 'produce')")
                    ->execute(['vid' => $voucherId, 'cid' => $companyId, 'ref' => $orderNo]);
            }
            foreach ($inputs as $input) {
                inv_rebuild_item($companyId, (int) $input['item']['id']);
            }
            inv_rebuild_item($companyId, $finishedItemId);
            db()->commit();
            security_event('inventory_movement_posted', 'success', 'Manufacturing order #' . $orderId . ' created.', $companyId, $userId);
            log_activity('manufacturing_order', $orderId, 'completed', 'Manufacturing order completed.', $userId);
            flash('success', 'Manufacturing order ' . $orderNo . ' completed and stock updated.'
                . ($voucherId > 0 ? ' Journal voucher MFG-' . $orderNo . ' posted (finished goods Dr / materials Cr).' : ' Link ledgers to the items involved to auto-post the production journal voucher.'));
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', (string) $exception->getCode() === '23000'
                ? 'Could not create manufacturing order: order number ' . $orderNo . ' already exists.'
                : 'Could not create manufacturing order: ' . $exception->getMessage());
        }
        redirect('admin/accounting-inventory.php?view=manufacturing');
    }

    if ($action === 'complete_manufacturing_order' || $action === 'cancel_manufacturing_order') {
        require_permission('inventory', 'edit');
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $orderStmt = db()->prepare("SELECT * FROM manufacturing_orders WHERE id = :id AND company_id = :company_id AND status IN ('draft', 'in_progress') LIMIT 1");
        $orderStmt->execute(['id' => $orderId, 'company_id' => $companyId]);
        $order = $orderStmt->fetch();
        if (!$order) {
            flash('error', 'Open production order not found (it may already be completed or cancelled).');
            redirect('admin/accounting-inventory.php?view=manufacturing');
        }
        $orderNo = (string) $order['order_no'];
        $inputRowsStmt = db()->prepare('SELECT moi.item_id, moi.quantity, moi.rate, i.ledger_id FROM manufacturing_order_inputs moi INNER JOIN inventory_items i ON i.id = moi.item_id WHERE moi.manufacturing_order_id = :id');
        $inputRowsStmt->execute(['id' => $orderId]);
        $inputRows = $inputRowsStmt->fetchAll();
        $today = date('Y-m-d');

        try {
            db()->beginTransaction();
            $movementStmt = db()->prepare('
                INSERT INTO inventory_transactions (company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date, qty_in, qty_out, rate, amount, notes)
                VALUES (:company_id, :fiscal_year_id, :item_id, :transaction_type, :ref_no, :transaction_date, :qty_in, :qty_out, :rate, :amount, :notes)
            ');

            if ($action === 'cancel_manufacturing_order') {
                // Return the issued materials to stock and close the order.
                foreach ($inputRows as $inputRow) {
                    $qty = (float) $inputRow['quantity'];
                    $rate = (float) $inputRow['rate'];
                    $movementStmt->execute([
                        'company_id' => $companyId,
                        'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                        'item_id' => (int) $inputRow['item_id'],
                        'transaction_type' => 'adjustment',
                        'ref_no' => $orderNo,
                        'transaction_date' => $today,
                        'qty_in' => $qty,
                        'qty_out' => 0,
                        'rate' => $rate,
                        'amount' => round($qty * $rate, 2),
                        'notes' => 'Materials returned — order ' . $orderNo . ' cancelled',
                    ]);
                }
                db()->prepare("UPDATE manufacturing_orders SET status = 'cancelled' WHERE id = :id")->execute(['id' => $orderId]);
                foreach ($inputRows as $inputRow) {
                    inv_rebuild_item($companyId, (int) $inputRow['item_id']);
                }
                db()->commit();
                security_event('inventory_movement_reversed', 'success', 'Manufacturing order #' . $orderId . ' cancelled.', $companyId, $userId);
                log_activity('manufacturing_order', $orderId, 'cancelled', 'Production order cancelled, materials returned.', $userId);
                flash('success', 'Order ' . $orderNo . ' cancelled and issued materials returned to stock.');
                redirect('admin/accounting-inventory.php?view=manufacturing');
            }

            $quantity = (float) $order['quantity'];
            $totalInputCost = 0.0;
            $inputCostByLedger = [];
            foreach ($inputRows as $inputRow) {
                $amount = round((float) $inputRow['quantity'] * (float) $inputRow['rate'], 2);
                $totalInputCost += $amount;
                $ledgerId = (int) ($inputRow['ledger_id'] ?? 0);
                $inputCostByLedger[$ledgerId] = ($inputCostByLedger[$ledgerId] ?? 0.0) + $amount;
            }
            // Absorb the conversion costs stored on the order (IAS 2).
            $ordLabour = (float) ($order['labour_cost'] ?? 0);
            $ordOverhead = (float) ($order['overhead_absorbed'] ?? 0);
            $ordByproduct = (float) ($order['byproduct_value'] ?? 0);
            $ordAbnormal = (float) ($order['abnormal_waste_cost'] ?? 0);
            $orderCost = mfg_order_cost($totalInputCost, $ordLabour, $ordOverhead, $ordByproduct, $ordAbnormal, $quantity);
            $finishedRate = $orderCost['unit_cost'];
            $movementStmt->execute([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                'item_id' => (int) $order['finished_item_id'],
                'transaction_type' => 'produce',
                'ref_no' => $orderNo,
                'transaction_date' => $today,
                'qty_in' => $quantity,
                'qty_out' => 0,
                'rate' => $finishedRate,
                'amount' => $orderCost['inventoriable'],
                'notes' => 'Finished goods from ' . $orderNo . ' at absorbed cost',
            ]);
            db()->prepare("UPDATE manufacturing_orders SET status = 'completed', completed_on = :done WHERE id = :id")
                ->execute(['done' => $today, 'id' => $orderId]);
            $finishedLedgerStmt = db()->prepare('SELECT ledger_id FROM inventory_items WHERE id = :id');
            $finishedLedgerStmt->execute(['id' => (int) $order['finished_item_id']]);
            $voucherId = inventory_post_production_voucher($companyId, $fiscalYearId, $orderId, $orderNo, $today, (int) ($finishedLedgerStmt->fetchColumn() ?: 0), $inputCostByLedger, $userId, [
                'labour' => $ordLabour, 'overhead' => $ordOverhead,
                'byproduct' => $ordByproduct, 'abnormal' => $ordAbnormal,
            ]);
            if ($voucherId > 0) {
                db()->prepare("UPDATE inventory_transactions SET voucher_id = :vid WHERE company_id = :cid AND ref_no = :ref AND transaction_type IN ('consume', 'produce')")
                    ->execute(['vid' => $voucherId, 'cid' => $companyId, 'ref' => $orderNo]);
            }
            inv_rebuild_item($companyId, (int) $order['finished_item_id']);
            db()->commit();
            security_event('inventory_movement_posted', 'success', 'Manufacturing order #' . $orderId . ' completed.', $companyId, $userId);
            log_activity('manufacturing_order', $orderId, 'completed', 'Production completed from WIP.', $userId);
            flash('success', 'Order ' . $orderNo . ' completed: ' . number_format($quantity, 3) . ' finished goods received into stock at ' . number_format($finishedRate, 2) . ' each.'
                . ($voucherId > 0 ? ' Journal voucher MFG-' . $orderNo . ' posted.' : ''));
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not update order ' . $orderNo . ': ' . $exception->getMessage());
        }
        redirect('admin/accounting-inventory.php?view=manufacturing');
    }
}

$editItem = null;
$editId = (int) ($_GET['edit_id'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM inventory_items WHERE id = :id AND company_id = :company_id LIMIT 1');
    $stmt->execute(['id' => $editId, 'company_id' => $companyId]);
    $editItem = $stmt->fetch() ?: null;
}

$ledgerStmt = db()->prepare("SELECT id, code, name FROM ledgers WHERE company_id = :company_id AND status = 'active' ORDER BY code ASC");
$ledgerStmt->execute(['company_id' => $companyId]);
$ledgers = $ledgerStmt->fetchAll();

// Active warehouses (for select dropdowns) and the full list (for the
// warehouse management table, which also needs to show inactive ones).
$warehouses = inv_company_warehouses($companyId);
$allWarehousesStmt = db()->prepare('SELECT * FROM warehouses WHERE company_id = :company_id ORDER BY name ASC');
$allWarehousesStmt->execute(['company_id' => $companyId]);
$allWarehouses = $allWarehousesStmt->fetchAll();

$itemStmt = db()->prepare('
    SELECT i.*, l.code AS ledger_code,
           i.opening_qty + COALESCE(SUM(t.qty_in - t.qty_out), 0) AS on_hand,
           COALESCE(SUM(t.amount), 0) AS movement_value
    FROM inventory_items i
    LEFT JOIN ledgers l ON l.id = i.ledger_id
    LEFT JOIN inventory_transactions t ON t.item_id = i.id
    WHERE i.company_id = :company_id
    GROUP BY i.id
    ORDER BY i.status ASC, i.name ASC
');
$itemStmt->execute(['company_id' => $companyId]);
$items = $itemStmt->fetchAll();

$movementStmt = db()->prepare('
    SELECT t.*, i.sku, i.name AS item_name, i.unit
    FROM inventory_transactions t
    INNER JOIN inventory_items i ON i.id = t.item_id
    WHERE t.company_id = :company_id
    ORDER BY t.transaction_date DESC, t.id DESC
    LIMIT 80
');
$movementStmt->execute(['company_id' => $companyId]);
$movements = $movementStmt->fetchAll();

$orderStmt = db()->prepare('
    SELECT mo.*, i.sku, i.name AS finished_item_name,
           COALESCE(mi.input_lines, 0) AS input_lines, COALESCE(mi.input_cost, 0) AS input_cost
    FROM manufacturing_orders mo
    INNER JOIN inventory_items i ON i.id = mo.finished_item_id
    LEFT JOIN (
        SELECT manufacturing_order_id, COUNT(*) AS input_lines, SUM(quantity * rate) AS input_cost
        FROM manufacturing_order_inputs GROUP BY manufacturing_order_id
    ) mi ON mi.manufacturing_order_id = mo.id
    WHERE mo.company_id = :company_id
    ORDER BY FIELD(mo.status, \'in_progress\', \'draft\', \'completed\', \'cancelled\'), mo.created_at DESC
    LIMIT 50
');
$orderStmt->execute(['company_id' => $companyId]);
$manufacturingOrders = $orderStmt->fetchAll();
$openOrderCount = count(array_filter($manufacturingOrders, static fn (array $order): bool => in_array((string) $order['status'], ['draft', 'in_progress'], true)));

// Real IAS 2 valuation from the perpetual cost layers (backfills legacy items).
$inventoryValuation = inv_company_valuation($companyId, $items);
$stockValueAtCost = $inventoryValuation['cost'];
$stockLowerOfCostNrv = $inventoryValuation['lower'];
$stockNrvWriteDown = $inventoryValuation['write_down'];
$stockOnHandUnits = array_sum(array_map(static fn (array $item): float => (float) $item['on_hand'], $items));
$stockValue = $stockValueAtCost; // legacy alias retained for any downstream use

// Stock-by-warehouse aggregate (one query, company-wide — page-specific
// reporting, not reusable valuation logic, so it stays inline here rather
// than in the engine). Cost stays company+item level; this is quantity-only.
$warehouseStockStmt = db()->prepare('
    SELECT t.warehouse_id, w.name AS warehouse_name, SUM(t.qty_in - t.qty_out) AS on_hand
    FROM inventory_transactions t
    LEFT JOIN warehouses w ON w.id = t.warehouse_id
    WHERE t.company_id = :company_id
    GROUP BY t.warehouse_id, w.name
');
$warehouseStockStmt->execute(['company_id' => $companyId]);
$warehouseOnHand = [];
$unassignedOnHand = 0.0;
$anyWarehouseTaggedTxn = false;
foreach ($warehouseStockStmt->fetchAll() as $whRow) {
    if ($whRow['warehouse_id'] === null) {
        $unassignedOnHand = (float) $whRow['on_hand'];
    } else {
        $warehouseOnHand[(int) $whRow['warehouse_id']] = (float) $whRow['on_hand'];
        $anyWarehouseTaggedTxn = true;
    }
}
// Opening quantity lives on the item master, not in inventory_transactions, so
// the aggregate above misses it. Every other on-hand figure on this page counts
// it (opening_qty + SUM(qty_in - qty_out)); without this the card would quietly
// contradict the on-hand column beside it. Opening stock sits at the item's
// default warehouse, or in the unassigned bucket when it has none.
foreach ($items as $stockItem) {
    $openingQty = (float) ($stockItem['opening_qty'] ?? 0);
    if (abs($openingQty) <= 0.00005) {
        continue;
    }
    $defaultWarehouseId = (int) ($stockItem['default_warehouse_id'] ?? 0);
    if ($defaultWarehouseId > 0) {
        $warehouseOnHand[$defaultWarehouseId] = ($warehouseOnHand[$defaultWarehouseId] ?? 0.0) + $openingQty;
        $anyWarehouseTaggedTxn = true;
    } else {
        $unassignedOnHand += $openingQty;
    }
}
$showWarehouseStockCard = $allWarehouses !== [] || $anyWarehouseTaggedTxn;
$lowStockCount = count(array_filter($items, static fn (array $item): bool => (float) $item['reorder_level'] > 0 && (float) $item['on_hand'] <= (float) $item['reorder_level']));
$inventoryProcessSteps = $inventoryProfile['show_manufacturing']
    ? [
        ['Create Item', 'Master data'],
        ['Set Type, Unit & Category', 'Classification'],
        ['Save Item Master', 'Available for transactions'],
        ['Record Stock Movement', 'Receipt / issue / transfer'],
        ['Update Stock Summary', 'Qty on hand'],
        ['Update Valuation', 'Cost and value'],
        ['Update Reports', 'Analytics'],
    ]
    : [
        ['Create Item', 'Master data'],
        ['Set Type, Unit & Category', 'Classification'],
        ['Save Item Master', 'Available for transactions'],
        ['Record Stock Movement', 'Receipt / issue / transfer'],
        ['Update Stock Summary', 'Qty on hand'],
        ['Update Valuation', 'Cost and value'],
    ];
$inventoryTypeCards = $inventoryProfile['show_manufacturing']
    ? [
        ['Stock Item', 'Physical items bought, sold, and inventoried.'],
        ['Service Item', 'Non-physical service lines used in billing.'],
        ['Raw Material', 'Inputs consumed in manufacturing or production.'],
        ['Finished Goods', 'Completed products ready for sale to customers.'],
    ]
    : [
        ['Stock Item', 'Physical items bought, sold, and inventoried.'],
        ['Service Item', 'Non-physical service lines used in billing.'],
        ['Consumable', 'Low-value operational items tracked for stock control.'],
];
$invView = (string) ($_GET['view'] ?? 'inventory');
$allowedViews = ['inventory', 'valuation', 'mapping'];
if ($inventoryProfile['show_manufacturing'] ?? false) {
    $allowedViews[] = 'manufacturing';
}
if (!in_array($invView, $allowedViews, true)) {
    $invView = 'inventory';
}
$lowOnly = (string) ($_GET['low'] ?? '') === '1';
$isLowStock = static fn (array $item): bool => (float) $item['reorder_level'] > 0 && (float) $item['on_hand'] <= (float) $item['reorder_level'];
$visibleItems = $lowOnly ? array_values(array_filter($items, $isLowStock)) : $items;
$moveItemId = (int) ($_GET['move_item'] ?? 0);
$pageTitle = $invView === 'manufacturing' ? 'Manufacturing' : 'Inventory';
$pageSubtitle = $inventoryProfile['show_manufacturing']
    ? 'Item master, stock movements, valuation, and production orders'
    : 'Item master, stock movements, and valuation';
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<nav class="accounting-tabs" aria-label="Accounting sections">
    <a href="<?= e(url('admin/accounting-dashboard.php')) ?>">Dashboard</a>
    <a href="<?= e(url('admin/accounting-parties.php')) ?>">Parties</a>
    <a href="<?= e(url('admin/accounting.php')) ?>">Vouchers</a>
    <a class="<?= $invView === 'inventory' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-inventory.php')) ?>">Inventory</a>
    <?php if ($inventoryProfile['show_manufacturing']): ?><a class="<?= $invView === 'manufacturing' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-inventory.php?view=manufacturing')) ?>">Manufacturing</a><?php endif; ?>
    <a href="<?= e(url('admin/chart-of-accounts.php')) ?>">Chart of Accounts</a>
    <a href="<?= e(url('admin/reports-center.php')) ?>">Reports</a>
</nav>

<section class="mbw-kpi-grid" aria-label="Inventory overview">
    <a class="mbw-kpi" href="<?= e(url('admin/accounting-inventory.php#item-stock-summary')) ?>" title="Jump to the item stock summary">
        <div>
            <span class="mbw-kpi-label">Stock on Hand</span>
            <div class="mbw-kpi-value"><?= e(number_format($stockOnHandUnits, 0)) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= e((string) count($items)) ?> items</span></span>
        </div>
        <span class="mbw-chip tone-blue"><?= icon('cart') ?></span>
    </a>
    <a class="mbw-kpi" href="<?= e(url('admin/reports-center.php?report=stock-valuation')) ?>" title="Inventory value at cost from the perpetual cost layers">
        <div>
            <span class="mbw-kpi-label">Inventory Value at Cost</span>
            <div class="mbw-kpi-value"><?= e(site_currency_symbol()) ?><?= e(number_format($stockValueAtCost, 2)) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">FIFO / weighted avg / specific</span></span>
        </div>
        <span class="mbw-chip tone-green"><?= icon('wallet') ?></span>
    </a>
    <a class="mbw-kpi" href="<?= e(url('admin/accounting-inventory.php?view=valuation')) ?>" title="Lower of cost and net realisable value (IAS 2)">
        <div>
            <span class="mbw-kpi-label">Lower of Cost &amp; NRV</span>
            <div class="mbw-kpi-value"><?= e(site_currency_symbol()) ?><?= e(number_format($stockLowerOfCostNrv, 2)) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">IAS 2 measurement</span></span>
        </div>
        <span class="mbw-chip tone-teal"><?= icon('reports') ?></span>
    </a>
    <a class="mbw-kpi" href="<?= e(url('admin/accounting-inventory.php?view=valuation')) ?>" title="Cumulative NRV write-down (cost above NRV)">
        <div>
            <span class="mbw-kpi-label">NRV Write-down</span>
            <div class="mbw-kpi-value" style="<?= $stockNrvWriteDown > 0 ? 'color:var(--mbw-amber)' : '' ?>"><?= e(site_currency_symbol()) ?><?= e(number_format($stockNrvWriteDown, 2)) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= $stockNrvWriteDown > 0 ? 'Cost exceeds NRV' : 'None' ?></span></span>
        </div>
        <span class="mbw-chip tone-amber"><?= icon('download') ?></span>
    </a>
    <a class="mbw-kpi" href="<?= e(url('admin/accounting-inventory.php?low=1#item-stock-summary')) ?>" title="Show only items at or below their reorder level">
        <div>
            <span class="mbw-kpi-label">Low Stock</span>
            <div class="mbw-kpi-value"><?= e((string) $lowStockCount) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">At or below reorder level</span></span>
        </div>
        <span class="mbw-chip tone-amber"><?= icon('tag') ?></span>
    </a>
    <?php if ($inventoryProfile['show_manufacturing']): ?>
        <a class="mbw-kpi" href="<?= e(url('admin/accounting-inventory.php?view=manufacturing#manufacturing-orders')) ?>" title="Open the manufacturing workspace">
            <div>
                <span class="mbw-kpi-label">Open Production Orders</span>
                <div class="mbw-kpi-value"><?= e((string) $openOrderCount) ?></div>
                <span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= $openOrderCount > 0 ? 'In progress (WIP)' : 'None open' ?></span></span>
            </div>
            <span class="mbw-chip tone-purple"><?= icon('layers') ?></span>
        </a>
    <?php endif; ?>
</section>

<nav class="mbw-tabbar" aria-label="Inventory workspace" style="margin:6px 0 16px">
    <a class="<?= $invView === 'inventory' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-inventory.php')) ?>"><?= icon('cart') ?>Items &amp; Transactions</a>
    <a class="<?= $invView === 'valuation' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-inventory.php?view=valuation')) ?>"><?= icon('reports') ?>Valuation &amp; NRV</a>
    <a class="<?= $invView === 'mapping' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-inventory.php?view=mapping')) ?>"><?= icon('accounting') ?>Ledger Mapping</a>
    <?php if ($inventoryProfile['show_manufacturing']): ?><a class="<?= $invView === 'manufacturing' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-inventory.php?view=manufacturing')) ?>"><?= icon('layers') ?>Manufacturing</a><?php endif; ?>
</nav>

<?php if ($invView === 'valuation'): ?>
    <?php
    // Per-item IAS 2 valuation: cost from layers vs lower of cost and NRV.
    $valuationRows = array_map(static function (array $item) use ($companyId): array {
        $v = inv_item_valuation($companyId, $item);
        return $item + ['val' => $v];
    }, $items);
    ?>
    <section class="mbw-card" aria-label="Valuation and NRV">
        <div class="mbw-card-head">
            <h2>Valuation &amp; NRV (IAS 2)</h2>
            <div class="mbw-card-tools"><span style="color:var(--mbw-muted);font-size:12.5px">Cost from perpetual layers; NRV uses each item's assessment or its sales rate as the selling price.</span></div>
        </div>
        <div class="rc-table-scroll">
            <table class="rc-table">
                <thead><tr>
                    <th>SKU</th><th>Item</th><th>Method</th><th class="align-right">On hand</th>
                    <th class="align-right">Unit cost</th><th class="align-right">Cost value</th>
                    <th class="align-right">NRV / unit</th><th class="align-right">Lower of cost &amp; NRV</th>
                    <th class="align-right">Write-down</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($valuationRows as $row): $v = $row['val']; ?>
                        <tr>
                            <td><?= e($row['sku']) ?></td>
                            <td><?= e($row['name']) ?></td>
                            <td><span class="mbw-pill tone-gray"><?= e(strtoupper(str_replace('_', ' ', (string) ($row['valuation_method'] ?? 'weighted_average')))) ?></span></td>
                            <td class="align-right"><?= e(number_format($v['qty'], 3)) ?></td>
                            <td class="align-right"><?= e(number_format($v['unit_cost'], 2)) ?></td>
                            <td class="align-right"><?= e(number_format($v['cost_value'], 2)) ?></td>
                            <td class="align-right"><?= e(number_format($v['nrv_per_unit'], 2)) ?></td>
                            <td class="align-right"><?= e(number_format($v['lower_value'], 2)) ?></td>
                            <td class="align-right" style="<?= $v['write_down'] > 0 ? 'color:var(--mbw-amber);font-weight:700' : '' ?>"><?= e(number_format($v['write_down'], 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($valuationRows === []): ?><tr><td colspan="9" style="text-align:center;color:var(--mbw-muted)">No items yet.</td></tr><?php endif; ?>
                </tbody>
                <tfoot><tr>
                    <th colspan="5" class="align-right">Totals</th>
                    <th class="align-right"><?= e(site_currency_symbol()) ?><?= e(number_format($stockValueAtCost, 2)) ?></th>
                    <th></th>
                    <th class="align-right"><?= e(site_currency_symbol()) ?><?= e(number_format($stockLowerOfCostNrv, 2)) ?></th>
                    <th class="align-right" style="<?= $stockNrvWriteDown > 0 ? 'color:var(--mbw-amber)' : '' ?>"><?= e(site_currency_symbol()) ?><?= e(number_format($stockNrvWriteDown, 2)) ?></th>
                </tr></tfoot>
            </table>
        </div>
    </section>

    <section class="mbw-card" aria-label="Post NRV assessment">
        <div class="mbw-card-head">
            <h2>Post NRV Assessment</h2>
            <div class="mbw-card-tools"><span style="color:var(--mbw-muted);font-size:12.5px">Computes lower of cost and net realisable value (IAS 2.28-33) and posts a write-down or a capped reversal.</span></div>
        </div>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="post_nrv_assessment">
            <label>Item<select name="item_id" id="nrvItem" required>
                <option value="">Select item</option>
                <?php foreach ($items as $item): ?>
                    <option value="<?= e((int) $item['id']) ?>" data-sales-rate="<?= e(number_format((float) $item['sales_rate'], 2, '.', '')) ?>"><?= e($item['sku'] . ' - ' . $item['name']) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label>Selling price<input type="number" step="0.01" min="0" name="selling_price" id="nrvSellingPrice" required></label>
            <label>Est. cost to complete<input type="number" step="0.01" min="0" name="completion_cost" value="0.00"></label>
            <label>Est. cost to sell<input type="number" step="0.01" min="0" name="selling_cost" value="0.00"></label>
            <div class="workspace-span-2"><button type="submit"><?= icon('reports') ?>Post assessment</button></div>
        </form>
        <script>
        (function () {
            var item = document.getElementById('nrvItem');
            var price = document.getElementById('nrvSellingPrice');
            item.addEventListener('change', function () {
                var opt = item.options[item.selectedIndex];
                if (opt && opt.value && !price.value) {
                    price.value = opt.getAttribute('data-sales-rate');
                }
            });
        })();
        </script>
    </section>
<?php elseif ($invView === 'mapping'): ?>
    <?php
    // Current global mappings + wrong-type detection.
    $currentMappings = [];
    $mapStmt = db()->prepare('SELECT m.purpose, m.ledger_id, l.type AS ledger_type, l.code, l.name FROM inventory_ledger_mappings m JOIN ledgers l ON l.id = m.ledger_id WHERE m.company_id = :cid AND m.scope = \'global\'');
    $mapStmt->execute(['cid' => $companyId]);
    foreach ($mapStmt->fetchAll(PDO::FETCH_ASSOC) as $mr) {
        $currentMappings[$mr['purpose']] = $mr;
    }
    ?>
    <section class="mbw-card" aria-label="Ledger mapping">
        <div class="mbw-card-head">
            <h2>Ledger Mapping (global defaults)</h2>
            <div class="mbw-card-tools"><span style="color:var(--mbw-muted);font-size:12.5px">Precedence: item &rarr; category &rarr; global. Postings are blocked until every required purpose resolves to a ledger.</span></div>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_inventory_mappings">
            <div class="rc-table-scroll">
                <table class="rc-table">
                    <thead><tr><th>Purpose</th><th>Expected type</th><th>Mapped ledger</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach (inventory_mapping_purposes() as $purposeKey => $meta): ?>
                            <?php
                            $cur = $currentMappings[$purposeKey] ?? null;
                            $curId = (int) ($cur['ledger_id'] ?? 0);
                            $typeMismatch = $cur && (string) $cur['ledger_type'] !== $meta['expect'];
                            ?>
                            <tr>
                                <td><strong><?= e($meta['label']) ?></strong></td>
                                <td><span class="mbw-pill tone-gray"><?= e(ucfirst($meta['expect'])) ?></span></td>
                                <td>
                                    <select name="map[<?= e($purposeKey) ?>]" style="min-width:230px">
                                        <option value="0">— not mapped —</option>
                                        <?php foreach ($ledgers as $ledger): ?>
                                            <option value="<?= e((int) $ledger['id']) ?>" <?= $curId === (int) $ledger['id'] ? 'selected' : '' ?>><?= e($ledger['code'] . ' - ' . $ledger['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <?php if (!$cur): ?><span class="mbw-pill tone-amber">Incomplete</span>
                                    <?php elseif ($typeMismatch): ?><span class="mbw-pill tone-amber" title="Mapped ledger type is <?= e((string) $cur['ledger_type']) ?>, expected <?= e($meta['expect']) ?>">Type warning</span>
                                    <?php else: ?><span class="mbw-pill tone-green">Complete</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top:12px"><button type="submit"><?= icon('accounting') ?>Save mappings</button></div>
        </form>
    </section>
<?php endif; ?>

<?php if ($invView === 'inventory'): ?>
<details class="mbw-card" aria-label="Help and workflow">
    <summary class="mbw-card-head" style="cursor:pointer"><h2>Help &amp; Workflow</h2><span class="mbw-card-tools" style="color:var(--mbw-muted);font-size:12.5px">Process flow and item types — click to open</span></summary>
    <div class="inventory-process-grid" style="margin-bottom:14px">
        <?php foreach ($inventoryProcessSteps as $index => $process): ?>
            <article><b><?= e((string) ($index + 1)) ?></b><span><?= icon($index === 0 ? 'services' : ($index === count($inventoryProcessSteps) - 1 ? 'reports' : 'documents')) ?></span><strong><?= e($process[0]) ?></strong><small><?= e($process[1]) ?></small></article>
        <?php endforeach; ?>
    </div>
    <div class="inventory-type-grid">
        <?php foreach ($inventoryTypeCards as [$typeLabel, $typeDescription]): ?>
            <article><strong><?= e($typeLabel) ?></strong><span><?= e($typeDescription) ?></span></article>
        <?php endforeach; ?>
    </div>
</details>

<?php if ($repairErrors !== []): ?><div class="notice error">Accounting module repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div><?php endif; ?>

<?php endif; ?>

<?php if (in_array($invView, ['inventory', 'manufacturing'], true)): ?>
<section class="mbw-card" aria-label="Inventory workbench">
    <div class="mbw-card-head"><h2>Create &amp; Record</h2></div>
<div class="workspace-feature-stack">
    <?php if ($invView === 'inventory'): ?>
    <details class="feature-disclosure" id="create-item" open>
        <summary><span><strong><?= icon('services') ?><?= $editItem ? 'Edit item' : 'Create item' ?></strong><small>Maintain inventory, service, raw material, and finished-goods master data.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_item">
            <input type="hidden" name="item_id" value="<?= e((int) ($editItem['id'] ?? 0)) ?>">
            <label>SKU<input type="text" name="sku" maxlength="80" value="<?= e($editItem['sku'] ?? '') ?>" required></label>
            <label>Name<input type="text" name="name" maxlength="190" value="<?= e($editItem['name'] ?? '') ?>" required></label>
            <?php
            $formItemTypes = $itemTypes;
            if ($editItem && !in_array((string) $editItem['item_type'], $formItemTypes, true)) {
                $formItemTypes[] = (string) $editItem['item_type'];
            }
            ?>
            <label>Type<select name="item_type"><?php foreach ($formItemTypes as $type): ?><option value="<?= e($type) ?>" <?= ($editItem['item_type'] ?? 'stock') === $type ? 'selected' : '' ?>><?= e(str_replace('_', ' ', ucfirst($type))) ?></option><?php endforeach; ?></select></label>
            <label>Valuation method
                <select name="valuation_method">
                    <?php $vm = (string) ($editItem['valuation_method'] ?? 'weighted_average'); ?>
                    <option value="weighted_average" <?= $vm === 'weighted_average' ? 'selected' : '' ?>>Weighted Average (perpetual)</option>
                    <option value="fifo" <?= $vm === 'fifo' ? 'selected' : '' ?>>FIFO</option>
                    <option value="specific" <?= $vm === 'specific' ? 'selected' : '' ?>>Specific Identification</option>
                </select>
            </label>
            <label>Category<input type="text" name="category" maxlength="120" value="<?= e($editItem['category'] ?? '') ?>" placeholder="e.g. Raw Materials"></label>
            <label>Status<select name="status"><option value="active" <?= ($editItem['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= ($editItem['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label>
            <label>Unit<input type="text" name="unit" value="<?= e($editItem['unit'] ?? 'pcs') ?>" required></label>
            <label>HS code<input type="text" name="hs_code" value="<?= e($editItem['hs_code'] ?? '') ?>"></label>
            <label>Tax rate %<input type="number" step="0.01" name="tax_rate" value="<?= e($editItem['tax_rate'] ?? '13.00') ?>"></label>
            <label>Sales rate<input type="number" step="0.01" name="sales_rate" value="<?= e($editItem['sales_rate'] ?? '0.00') ?>"></label>
            <label>Purchase rate<input type="number" step="0.01" name="purchase_rate" value="<?= e($editItem['purchase_rate'] ?? '0.00') ?>"></label>
            <label>Opening qty<input type="number" step="0.001" name="opening_qty" value="<?= e($editItem['opening_qty'] ?? '0.000') ?>"></label>
            <label>Reorder level<input type="number" step="0.001" name="reorder_level" value="<?= e($editItem['reorder_level'] ?? '0.000') ?>"></label>
            <label>Linked ledger<select name="ledger_id"><option value="0">No linked ledger</option><?php foreach ($ledgers as $ledger): ?><option value="<?= e((int) $ledger['id']) ?>" <?= (int) ($editItem['ledger_id'] ?? 0) === (int) $ledger['id'] ? 'selected' : '' ?>><?= e($ledger['code'] . ' - ' . $ledger['name']) ?></option><?php endforeach; ?></select></label>
            <label>Default warehouse<select name="default_warehouse_id"><option value="0">— none —</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= e((int) $warehouse['id']) ?>" <?= (int) ($editItem['default_warehouse_id'] ?? 0) === (int) $warehouse['id'] ? 'selected' : '' ?>><?= e($warehouse['name'] . ($warehouse['code'] ? ' (' . $warehouse['code'] . ')' : '')) ?></option><?php endforeach; ?></select></label>
            <label class="workspace-span-2">Notes<textarea name="notes"><?= e($editItem['notes'] ?? '') ?></textarea></label>
            <div class="workspace-span-2"><button type="submit"><?= icon('services') ?>Save item</button><?php if ($editItem): ?> <a class="button secondary" href="<?= e(url('admin/accounting-inventory.php')) ?>">Cancel edit</a><?php endif; ?></div>
        </form>
    </details>

    <details class="feature-disclosure" id="warehouses">
        <summary><span><strong><?= icon('services') ?>Warehouses / Locations</strong><small>Stock locations for this company — used to track where inventory physically sits.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
        <div class="rc-table-scroll">
            <table class="rc-table">
                <thead><tr><th>Name</th><th>Code</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php if ($allWarehouses === []): ?><tr><td colspan="4" style="text-align:center;color:var(--mbw-muted)">No warehouses yet — add one below.</td></tr><?php endif; ?>
                    <?php foreach ($allWarehouses as $warehouse): ?>
                        <?php $whActive = (int) $warehouse['is_active'] === 1; ?>
                        <tr>
                            <td><?= e($warehouse['name']) ?></td>
                            <td><?= e($warehouse['code'] ?? '-') ?></td>
                            <td><span class="mbw-pill <?= $whActive ? 'tone-green' : 'tone-red' ?>"><?= $whActive ? 'Active' : 'Inactive' ?></span></td>
                            <td>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="toggle_warehouse">
                                    <input type="hidden" name="warehouse_id" value="<?= e((int) $warehouse['id']) ?>">
                                    <button type="submit" class="button secondary"><?= $whActive ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="post" class="workspace-form-grid" style="margin-top:12px">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_warehouse">
            <label>Name<input type="text" name="name" maxlength="120" required></label>
            <label>Code<input type="text" name="code" maxlength="40" placeholder="Optional"></label>
            <div class="workspace-span-2"><button type="submit"><?= icon('services') ?>Add warehouse</button></div>
        </form>
    </details>

    <details class="feature-disclosure" id="movement-purchase" <?= $moveItemId > 0 ? 'open' : '' ?>>
        <summary><span><strong><?= icon('tasks') ?>Record Purchase / Opening Stock</strong><small>Post opening balances, purchases, and purchase returns.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
        <form method="post" class="workspace-form-grid" id="purchaseMovementForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="record_movement">
            <label>Item<select name="item_id" id="purMovItem" required><option value="">Select item</option><?php foreach ($items as $item): ?><option value="<?= e((int) $item['id']) ?>" data-purchase-rate="<?= e(number_format((float) $item['purchase_rate'], 2, '.', '')) ?>" <?= $moveItemId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['sku'] . ' - ' . $item['name']) ?></option><?php endforeach; ?></select></label>
            <label>Movement<select name="transaction_type">
                <option value="opening">Opening</option>
                <option value="purchase">Purchase</option>
                <option value="purchase_return">Purchase return</option>
            </select></label>
            <label>Warehouse<select name="warehouse_id"><option value="0">— unassigned —</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= e((int) $warehouse['id']) ?>"><?= e($warehouse['name']) ?></option><?php endforeach; ?></select></label>
            <label>Date<input type="date" name="transaction_date" value="<?= e(date('Y-m-d')) ?>" required></label>
            <label>Reference<input type="text" name="ref_no" maxlength="120"></label>
            <label>Quantity<input type="number" step="0.001" min="0.001" name="quantity" required></label>
            <label>Rate<input type="number" step="0.01" min="0" name="rate" id="purMovRate" placeholder="Auto from item"></label>
            <label class="workspace-span-2">Notes<textarea name="notes"></textarea></label>
            <button type="submit"><?= icon('tasks') ?>Record</button>
        </form>
        <script>
        (function () {
            var item = document.getElementById('purMovItem');
            var rate = document.getElementById('purMovRate');
            item.addEventListener('change', function () {
                var opt = item.options[item.selectedIndex];
                if (opt && opt.value) { rate.value = opt.getAttribute('data-purchase-rate'); }
            });
        })();
        </script>
    </details>

    <details class="feature-disclosure" id="movement-sale">
        <summary><span><strong><?= icon('tasks') ?>Record Sale / Sales Return</strong><small>Manual, non-invoiced stock-outs — inventory-sourced invoices auto-post their own sale movement via invoice.php.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
        <form method="post" class="workspace-form-grid" id="saleMovementForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="record_movement">
            <label>Item<select name="item_id" id="saleMovItem" required><option value="">Select item</option><?php foreach ($items as $item): ?><option value="<?= e((int) $item['id']) ?>" data-sales-rate="<?= e(number_format((float) $item['sales_rate'], 2, '.', '')) ?>" <?= $moveItemId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['sku'] . ' - ' . $item['name']) ?></option><?php endforeach; ?></select></label>
            <label>Movement<select name="transaction_type">
                <option value="sale">Sale</option>
                <option value="sales_return">Sales return</option>
            </select></label>
            <label>Warehouse<select name="warehouse_id"><option value="0">— unassigned —</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= e((int) $warehouse['id']) ?>"><?= e($warehouse['name']) ?></option><?php endforeach; ?></select></label>
            <label>Date<input type="date" name="transaction_date" value="<?= e(date('Y-m-d')) ?>" required></label>
            <label>Reference<input type="text" name="ref_no" maxlength="120"></label>
            <label>Quantity<input type="number" step="0.001" min="0.001" name="quantity" required></label>
            <label>Rate<input type="number" step="0.01" min="0" name="rate" id="saleMovRate" placeholder="Auto from item"></label>
            <label class="workspace-span-2">Notes<textarea name="notes"></textarea></label>
            <button type="submit"><?= icon('tasks') ?>Record</button>
        </form>
        <script>
        (function () {
            var item = document.getElementById('saleMovItem');
            var rate = document.getElementById('saleMovRate');
            item.addEventListener('change', function () {
                var opt = item.options[item.selectedIndex];
                if (opt && opt.value) { rate.value = opt.getAttribute('data-sales-rate'); }
            });
        })();
        </script>
    </details>

    <details class="feature-disclosure" id="movement-adjust">
        <summary><span><strong><?= icon('tasks') ?>Adjustments &amp; Write-offs</strong><small>Stock count corrections, write-offs, damage, and expiry.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
        <form method="post" class="workspace-form-grid" id="adjustForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="record_movement">
            <label>Item<select name="item_id" id="adjItem" required><option value="">Select item</option><?php foreach ($items as $item): ?><option value="<?= e((int) $item['id']) ?>" data-purchase-rate="<?= e(number_format((float) $item['purchase_rate'], 2, '.', '')) ?>" <?= $moveItemId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['sku'] . ' - ' . $item['name']) ?></option><?php endforeach; ?></select></label>
            <label>Movement<select name="transaction_type" id="adjType">
                <option value="adjustment">Adjustment</option>
                <option value="write_off">Write-off</option>
                <option value="damage">Damage</option>
                <option value="expiry">Expiry</option>
            </select></label>
            <label id="adjDirectionWrap" hidden>Direction<select name="direction"><option value="in">Stock in (+)</option><option value="out">Stock out (&#8722;)</option></select></label>
            <label>Warehouse<select name="warehouse_id"><option value="0">— unassigned —</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= e((int) $warehouse['id']) ?>"><?= e($warehouse['name']) ?></option><?php endforeach; ?></select></label>
            <label>Date<input type="date" name="transaction_date" value="<?= e(date('Y-m-d')) ?>" required></label>
            <label>Reference<input type="text" name="ref_no" maxlength="120"></label>
            <label>Quantity<input type="number" step="0.001" min="0.001" name="quantity" required></label>
            <label>Rate<input type="number" step="0.01" min="0" name="rate" id="adjRate" placeholder="Auto: purchase rate"></label>
            <label class="workspace-span-2">Notes<textarea name="notes"></textarea></label>
            <button type="submit"><?= icon('tasks') ?>Record</button>
        </form>
        <script>
        (function () {
            var item = document.getElementById('adjItem');
            var type = document.getElementById('adjType');
            var rate = document.getElementById('adjRate');
            var directionWrap = document.getElementById('adjDirectionWrap');
            function sync() {
                directionWrap.hidden = type.value !== 'adjustment';
                var opt = item.options[item.selectedIndex];
                if (!opt || !opt.value) { return; }
                if (!rate.value || parseFloat(rate.value) === 0) {
                    rate.value = opt.getAttribute('data-purchase-rate');
                }
            }
            item.addEventListener('change', function () { rate.value = ''; sync(); });
            type.addEventListener('change', sync);
            sync();
        })();
        </script>
    </details>

    <details class="feature-disclosure" id="movement-transfer">
        <summary><span><strong><?= icon('tasks') ?>Warehouse / Departmental Transfer</strong><small>Move stock between locations — quantity only, no GL impact.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="record_movement">
            <label>Item<select name="item_id" required><option value="">Select item</option><?php foreach ($items as $item): ?><option value="<?= e((int) $item['id']) ?>" <?= $moveItemId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['sku'] . ' - ' . $item['name']) ?></option><?php endforeach; ?></select></label>
            <label>Movement<select name="transaction_type">
                <option value="warehouse_transfer">Warehouse transfer</option>
                <option value="departmental_transfer">Departmental transfer</option>
            </select></label>
            <label>From warehouse<select name="warehouse_id" required><option value="">Select source</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= e((int) $warehouse['id']) ?>"><?= e($warehouse['name']) ?></option><?php endforeach; ?></select></label>
            <label>To warehouse<select name="to_warehouse_id" required><option value="">Select destination</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= e((int) $warehouse['id']) ?>"><?= e($warehouse['name']) ?></option><?php endforeach; ?></select></label>
            <label>Date<input type="date" name="transaction_date" value="<?= e(date('Y-m-d')) ?>" required></label>
            <label>Reference<input type="text" name="ref_no" maxlength="120"></label>
            <label>Quantity<input type="number" step="0.001" min="0.001" name="quantity" required></label>
            <label class="workspace-span-2">Notes<textarea name="notes"></textarea></label>
            <div class="workspace-span-2"><small style="color:var(--mbw-muted)">No GL entry — quantity moves between locations only (IAS 2 recognition unaffected).</small></div>
            <button type="submit"><?= icon('tasks') ?>Record transfer</button>
        </form>
    </details>

    <?php endif; ?>
    <?php if ($inventoryProfile['show_manufacturing'] && $invView === 'manufacturing'): ?>
        <details class="feature-disclosure" id="manufacturing" open>
            <summary><span><strong><?= icon('settings') ?>Production Order</strong><small>Start production (materials move to Work in Progress) or complete it in one step.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
            <form method="post" class="workspace-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_manufacturing_order">
                <label>Order no<input type="text" name="order_no" placeholder="Leave blank for auto"></label>
                <label>Mode<select name="production_mode">
                    <option value="complete">Complete immediately (consume + produce)</option>
                    <option value="start">Start production — Work in Progress</option>
                </select></label>
                <label>Finished item<select name="finished_item_id" required><option value="">Select finished item</option><?php foreach ($items as $item): ?><?php if ($item['status'] !== 'active') { continue; } ?><option value="<?= e((int) $item['id']) ?>"><?= e($item['sku'] . ' - ' . $item['name'] . ' (' . str_replace('_', ' ', $item['item_type']) . ')') ?></option><?php endforeach; ?></select></label>
                <label>Quantity produced<input type="number" step="0.001" min="0.001" name="quantity" required></label>
                <label>Started on<input type="date" name="started_on" value="<?= e(date('Y-m-d')) ?>"></label>
                <label>Completed on<input type="date" name="completed_on" value="<?= e(date('Y-m-d')) ?>"></label>
                <?php
                $bomOptions = table_exists('bom_headers')
                    ? db()->query("SELECT bh.id, bh.bom_no, bh.output_qty, i.sku FROM bom_headers bh JOIN inventory_items i ON i.id = bh.finished_item_id WHERE bh.company_id = " . $companyId . " AND bh.status = 'active' ORDER BY bh.bom_no")->fetchAll(PDO::FETCH_ASSOC)
                    : [];
                ?>
                <label>Bill of materials (optional)<select name="bom_id"><option value="0">No BOM — free-form inputs</option><?php foreach ($bomOptions as $b): ?><option value="<?= e((int) $b['id']) ?>"><?= e($b['bom_no'] . ' → ' . $b['sku'] . ' (batch ' . number_format((float) $b['output_qty'], 3) . ')') ?></option><?php endforeach; ?></select></label>
                <label>Direct labour cost<input type="number" step="0.01" min="0" name="labour_cost" value="0.00"></label>
                <label>Overhead absorbed (normal capacity)<input type="number" step="0.01" min="0" name="overhead_absorbed" value="0.00"></label>
                <label>By-product / scrap value<input type="number" step="0.01" min="0" name="byproduct_value" value="0.00"></label>
                <label>Abnormal waste cost <small style="color:var(--mbw-muted)">(expensed, never inventoried)</small><input type="number" step="0.01" min="0" name="abnormal_waste_cost" value="0.00"></label>
                <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="workspace-span-2 workspace-form-grid">
                        <label>Input item<select name="input_item_id[]"><option value="">Select input</option><?php foreach ($items as $item): ?><?php if ($item['status'] !== 'active') { continue; } ?><option value="<?= e((int) $item['id']) ?>"><?= e($item['sku'] . ' - ' . $item['name'] . ' (on hand ' . number_format((float) $item['on_hand'], 3) . ')') ?></option><?php endforeach; ?></select></label>
                        <label>Input qty<input type="number" step="0.001" min="0" name="input_quantity[]"></label>
                        <label>Input rate<input type="number" step="0.01" min="0" name="input_rate[]" placeholder="Auto: purchase rate"></label>
                    </div>
                <?php endfor; ?>
                <label class="workspace-span-2">Notes<textarea name="notes"></textarea></label>
                <button type="submit"><?= icon('settings') ?>Save production order</button>
            </form>
        </details>

        <details class="feature-disclosure" id="bom">
            <summary><span><strong><?= icon('documents') ?>Bill of Materials</strong><small>Define the standard recipe (components, expected waste, standard costs) for variance reporting.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
            <form method="post" class="workspace-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_bom">
                <label>BOM no<input type="text" name="bom_no" placeholder="Leave blank for auto"></label>
                <label>Finished product<select name="finished_item_id" required><option value="">Select finished item</option><?php foreach ($items as $item): ?><?php if ($item['status'] !== 'active') { continue; } ?><option value="<?= e((int) $item['id']) ?>"><?= e($item['sku'] . ' - ' . $item['name']) ?></option><?php endforeach; ?></select></label>
                <label>Output qty per batch<input type="number" step="0.001" min="0.001" name="output_qty" value="1.000" required></label>
                <label>Std labour cost / batch<input type="number" step="0.01" min="0" name="std_labour_cost" value="0.00"></label>
                <label>Std overhead / batch<input type="number" step="0.01" min="0" name="std_overhead_cost" value="0.00"></label>
                <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="workspace-span-2 workspace-form-grid">
                        <label>Component<select name="bom_item_id[]"><option value="">Select component</option><?php foreach ($items as $item): ?><?php if ($item['status'] !== 'active') { continue; } ?><option value="<?= e((int) $item['id']) ?>"><?= e($item['sku'] . ' - ' . $item['name']) ?></option><?php endforeach; ?></select></label>
                        <label>Std qty / batch<input type="number" step="0.0001" min="0" name="bom_qty[]"></label>
                        <label>Expected waste %<input type="number" step="0.001" min="0" name="bom_waste[]" value="0"></label>
                        <label>Std rate<input type="number" step="0.000001" min="0" name="bom_rate[]" placeholder="Auto: purchase rate"></label>
                    </div>
                <?php endfor; ?>
                <button type="submit"><?= icon('documents') ?>Save BOM</button>
            </form>
        </details>
    <?php endif; ?>
</div>
</section>
<?php endif; ?>

<?php if ($inventoryProfile['show_manufacturing'] && $invView === 'manufacturing'): ?>
    <?php
    $flowSteps = [
        ['Create Production Order', url('admin/accounting-inventory.php?view=manufacturing#manufacturing'), 'Open the production order form'],
        ['Select Finished Item & Inputs', url('admin/accounting-inventory.php?view=manufacturing#manufacturing'), 'Pick the finished item and its material lines'],
        ['Issue Materials', url('admin/accounting-inventory.php?view=manufacturing#manufacturing'), 'Saving in Start mode issues the materials to production'],
        ['Work in Progress', url('admin/reports-center.php?report=manufacturing-wip'), 'Open the WIP report — value locked in open orders'],
        ['Complete Production', url('admin/accounting-inventory.php?view=manufacturing#manufacturing-orders'), 'Complete an open order from the orders table'],
        ['Finished Goods Receipt', url('admin/accounting-inventory.php#stock-movement'), 'Completion books the produce movement into stock'],
        ['Stock & Accounting Updated', url('admin/reports-center.php?report=manufacturing-cost'), 'Production cost report; ledger-linked items also post a journal voucher'],
    ];
    ?>
    <section class="mbw-flow-panel manufacturing-flow" aria-label="Manufacturing production workflow">
        <?php foreach ($flowSteps as $stepIndex => [$stepLabel, $stepUrl, $stepTitle]): ?>
            <a href="<?= e($stepUrl) ?>" title="<?= e($stepTitle) ?>"><b><?= e((string) ($stepIndex + 1)) ?></b><span><?= e($stepLabel) ?></span></a>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($invView === 'inventory'): ?>
<section class="mbw-card" id="item-stock-summary">
    <div class="mbw-card-head">
        <h2>Item Stock Summary<?= $lowOnly ? ' — low stock only' : '' ?></h2>
        <div class="mbw-card-tools">
            <?php if ($lowOnly): ?><a class="mbw-view-all" href="<?= e(url('admin/accounting-inventory.php#item-stock-summary')) ?>">Show all items</a><?php endif; ?>
            <a class="mbw-view-all" href="<?= e(url('admin/reports-center.php?report=stock-valuation')) ?>">Valuation report &#8594;</a>
        </div>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>SKU</th><th>Name</th><th>Type</th><th>Method</th><th>Unit</th><th class="is-numeric">On hand</th><th class="is-numeric">Unit cost</th><th class="is-numeric">Value at cost</th><th class="is-numeric">Reorder</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if ($visibleItems === []): ?><tr><td colspan="11"><?= $lowOnly ? 'No items are at or below their reorder level.' : 'No items yet.' ?></td></tr><?php endif; ?>
            <?php foreach ($visibleItems as $item): ?>
                <?php $low = $isLowStock($item); $iv = inv_item_valuation($companyId, $item); ?>
                <tr>
                    <td><?= e($item['sku']) ?></td><td><?= e($item['name']) ?></td><td><?= e(str_replace('_', ' ', $item['item_type'])) ?></td>
                    <td><span class="mbw-pill tone-gray"><?= e(strtoupper(str_replace('_', ' ', (string) ($item['valuation_method'] ?? 'weighted_average')))) ?></span></td>
                    <td><?= e($item['unit']) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $item['on_hand'], 3)) ?><?php if ($low): ?> <span class="mbw-pill tone-amber">Low</span><?php endif; ?></td>
                    <td class="is-numeric"><?= e(number_format($iv['unit_cost'], 2)) ?></td>
                    <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format($iv['cost_value'], 2)) ?></td>
                    <td class="is-numeric"><?= (float) $item['reorder_level'] > 0 ? e(number_format((float) $item['reorder_level'], 3)) : '–' ?></td>
                    <td><span class="mbw-pill <?= $item['status'] === 'active' ? 'tone-green' : 'tone-red' ?>"><?= e(ucfirst($item['status'])) ?></span></td>
                    <td style="white-space:nowrap">
                        <a class="button secondary" href="<?= e(url('admin/accounting-inventory.php?edit_id=' . (int) $item['id'] . '#create-item')) ?>">Edit</a>
                        <a class="button secondary" href="<?= e(url('admin/accounting-inventory.php?move_item=' . (int) $item['id'] . '#movement-purchase')) ?>" title="Record a stock movement for this item">Move</a>
                        <a class="button secondary" href="<?= e(url('admin/reports-center.php?report=stock-ledger&item_id=' . (int) $item['id'])) ?>" title="Open this item's stock ledger (running balance)">Ledger</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<?php if ($showWarehouseStockCard): ?>
<section class="mbw-card" aria-label="Stock by warehouse">
    <div class="mbw-card-head"><h2>Stock by Warehouse</h2></div>
    <div class="rc-table-scroll">
        <table class="rc-table">
            <thead><tr><th>Warehouse</th><th class="align-right">On hand</th></tr></thead>
            <tbody>
                <?php foreach ($warehouses as $warehouse): ?>
                    <tr>
                        <td><?= e($warehouse['name']) ?></td>
                        <td class="align-right"><?= e(number_format($warehouseOnHand[(int) $warehouse['id']] ?? 0.0, 3)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td>Unassigned</td>
                    <td class="align-right"><?= e(number_format($unassignedOnHand, 3)) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="mbw-card">
    <div class="mbw-card-head">
        <h2>Recent Stock Movements</h2>
        <div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('admin/reports-center.php?report=stock-movement')) ?>">Movement report &#8594;</a></div>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Date</th><th>Item</th><th>Type</th><th class="is-numeric">In</th><th class="is-numeric">Out</th><th class="is-numeric">Rate</th><th class="is-numeric">Amount</th><th>Ref</th><?php if (($currentUser['role'] ?? '') === 'admin'): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
            <?php if ($movements === []): ?><tr><td colspan="9">No stock movements yet.</td></tr><?php endif; ?>
            <?php foreach ($movements as $movement): ?>
                <?php $movementIn = (float) $movement['qty_in'] > 0; ?>
                <tr>
                    <td><?= e($movement['transaction_date']) ?></td><td><?= e($movement['sku'] . ' - ' . $movement['item_name']) ?></td>
                    <td><span class="mbw-pill <?= $movementIn ? 'tone-blue' : 'tone-gray' ?>"><?= e(str_replace('_', ' ', ucfirst($movement['transaction_type']))) ?><?= $movement['transaction_type'] === 'adjustment' ? ($movementIn ? ' +' : ' −') : '' ?></span></td>
                    <td class="is-numeric"><?= e(number_format((float) $movement['qty_in'], 3)) ?></td><td class="is-numeric"><?= e(number_format((float) $movement['qty_out'], 3)) ?></td><td class="is-numeric"><?= e(number_format((float) $movement['rate'], 2)) ?></td><td class="is-numeric"><?= e(number_format((float) $movement['amount'], 2)) ?></td><td><?= e($movement['ref_no'] ?? '-') ?></td>
                    <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                        <td style="white-space:nowrap">
                            <?php if (!in_array((string) $movement['transaction_type'], ['consume', 'produce'], true)): ?>
                                <?php if ((int) ($movement['voucher_id'] ?? 0) > 0): ?>
                                    <form method="post" style="display:inline" data-confirm="Reverse this <?= e(str_replace('_', ' ', $movement['transaction_type'])) ?> of <?= e($movement['sku']) ?>? A mirror stock entry and a reversing voucher (Dr/Cr swapped) are posted; the originals are kept for audit.">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="reverse_movement">
                                        <input type="hidden" name="movement_id" value="<?= e((int) $movement['id']) ?>">
                                        <button type="submit" class="button secondary" title="Reverse this posted movement">Reverse</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="display:inline" data-confirm="Delete this <?= e(str_replace('_', ' ', $movement['transaction_type'])) ?> movement of <?= e($movement['sku']) ?> dated <?= e($movement['transaction_date']) ?>? Stock on hand recalculates immediately.">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_movement">
                                        <input type="hidden" name="movement_id" value="<?= e((int) $movement['id']) ?>">
                                        <button type="submit" class="button secondary" title="Delete this stock-only movement">&times;</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<?php endif; ?>
<?php if ($inventoryProfile['show_manufacturing'] && $invView === 'manufacturing'): ?>
    <section class="mbw-card" id="manufacturing-orders">
        <div class="mbw-card-head">
            <h2>Manufacturing Orders<?= $openOrderCount > 0 ? ' — ' . $openOrderCount . ' in progress' : '' ?></h2>
            <div class="mbw-card-tools">
                <a class="mbw-view-all" href="<?= e(url('admin/reports-center.php?report=manufacturing-wip')) ?>">WIP report &#8594;</a>
                <a class="mbw-view-all" href="<?= e(url('admin/reports-center.php?report=manufacturing-cost')) ?>">Cost report &#8594;</a>
            </div>
        </div>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Order</th><th>Finished item</th><th class="is-numeric">Quantity</th><th class="is-numeric">Material cost</th><th>Status</th><th>Started</th><th>Completed</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if ($manufacturingOrders === []): ?><tr><td colspan="8">No manufacturing orders yet. Save one above — use Start mode to track Work in Progress.</td></tr><?php endif; ?>
                <?php foreach ($manufacturingOrders as $order): ?>
                    <?php $orderOpen = in_array((string) $order['status'], ['draft', 'in_progress'], true); ?>
                    <tr>
                        <td><?= e($order['order_no']) ?></td>
                        <td><?= e($order['sku'] . ' - ' . $order['finished_item_name']) ?></td>
                        <td class="is-numeric"><?= e(number_format((float) $order['quantity'], 3)) ?></td>
                        <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $order['input_cost'], 2)) ?> <small style="color:var(--mbw-muted)">(<?= e((int) $order['input_lines']) ?> lines)</small></td>
                        <td><span class="mbw-pill <?= $order['status'] === 'completed' ? 'tone-green' : ($order['status'] === 'cancelled' ? 'tone-red' : 'tone-amber') ?>"><?= e(str_replace('_', ' ', ucfirst($order['status']))) ?></span></td>
                        <td><?= e($order['started_on'] ?? '-') ?></td>
                        <td><?= e($order['completed_on'] ?? '-') ?></td>
                        <td style="white-space:nowrap">
                            <?php if ($orderOpen): ?>
                                <form method="post" style="display:inline" data-confirm="Complete <?= e($order['order_no']) ?>? <?= e(number_format((float) $order['quantity'], 3)) ?> finished goods will be received into stock at the material cost.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="complete_manufacturing_order">
                                    <input type="hidden" name="order_id" value="<?= e((int) $order['id']) ?>">
                                    <button type="submit">Complete</button>
                                </form>
                                <form method="post" style="display:inline" data-confirm="Cancel <?= e($order['order_no']) ?>? Issued materials will be returned to stock.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="cancel_manufacturing_order">
                                    <input type="hidden" name="order_id" value="<?= e((int) $order['id']) ?>">
                                    <button type="submit" class="button secondary">Cancel</button>
                                </form>
                            <?php else: ?>–<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <?php
    $varianceRows = table_exists('production_variances')
        ? db()->query("SELECT pv.*, mo.order_no FROM production_variances pv JOIN manufacturing_orders mo ON mo.id = pv.manufacturing_order_id WHERE pv.company_id = " . $companyId . " ORDER BY pv.id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC)
        : [];
    ?>
    <?php if ($varianceRows !== []): ?>
    <section class="mbw-card" id="production-variances">
        <div class="mbw-card-head"><h2>Production Variances</h2><div class="mbw-card-tools"><span style="color:var(--mbw-muted);font-size:12.5px">Actual vs BOM standard. Positive = unfavourable.</span></div></div>
        <div class="rc-table-scroll"><table class="rc-table">
            <thead><tr><th>Order</th><th>Variance</th><th class="align-right">Standard</th><th class="align-right">Actual</th><th class="align-right">Variance</th></tr></thead>
            <tbody>
                <?php foreach ($varianceRows as $vr): $unfav = (float) $vr['variance'] > 0; ?>
                    <tr>
                        <td><?= e($vr['order_no']) ?></td>
                        <td><?= e(str_replace('_', ' ', ucfirst((string) $vr['variance_type']))) ?></td>
                        <td class="align-right"><?= e(number_format((float) $vr['standard_amount'], 2)) ?></td>
                        <td class="align-right"><?= e(number_format((float) $vr['actual_amount'], 2)) ?></td>
                        <td class="align-right" style="font-weight:700;color:var(<?= $unfav ? '--mbw-amber' : '--mbw-green' ?>)"><?= e(number_format((float) $vr['variance'], 2)) ?> <?= $unfav ? '(U)' : '(F)' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
