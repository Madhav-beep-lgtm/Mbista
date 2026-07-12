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
$movementTypes = ['opening', 'purchase', 'sale', 'sales_return', 'purchase_return', 'adjustment'];

function inventory_direction(string $type): string
{
    return in_array($type, ['opening', 'purchase', 'sales_return', 'produce'], true) ? 'in' : 'out';
}

function inventory_valid_date(string $value): ?string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return ($parsed && $parsed->format('Y-m-d') === $value) ? $value : null;
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
 * Post the production journal (Dr finished-goods ledger / Cr input ledgers)
 * for a completed order, when the items involved have linked ledgers.
 * Idempotent via the vouchers UNIQUE(source_type, source_id) key.
 * Returns the voucher id, or 0 when ledger links are missing (stock-only).
 */
function inventory_post_production_voucher(int $companyId, int $fiscalYearId, int $orderId, string $orderNo, string $date, int $finishedLedgerId, array $inputCostByLedger, int $userId): int
{
    $total = round(array_sum($inputCostByLedger), 2);
    if ($finishedLedgerId <= 0 || $total <= 0 || $inputCostByLedger === [] || in_array(0, array_map('intval', array_keys($inputCostByLedger)), true)) {
        return 0;
    }
    $entries = [['ledger_id' => $finishedLedgerId, 'entry_type' => 'debit', 'amount' => $total]];
    foreach ($inputCostByLedger as $ledgerId => $amount) {
        $entries[] = ['ledger_id' => (int) $ledgerId, 'entry_type' => 'credit', 'amount' => round((float) $amount, 2)];
    }
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

        $params = [
            'company_id' => $companyId,
            'ledger_id' => (int) ($_POST['ledger_id'] ?? 0) ?: null,
            'sku' => $sku,
            'name' => $name,
            'item_type' => $itemType,
            'unit' => trim((string) ($_POST['unit'] ?? 'pcs')) ?: 'pcs',
            'hs_code' => trim((string) ($_POST['hs_code'] ?? '')) ?: null,
            'tax_rate' => max(0.0, round((float) ($_POST['tax_rate'] ?? 13), 2)),
            'sales_rate' => max(0.0, round((float) ($_POST['sales_rate'] ?? 0), 2)),
            'purchase_rate' => max(0.0, round((float) ($_POST['purchase_rate'] ?? 0), 2)),
            'opening_qty' => max(0.0, round((float) ($_POST['opening_qty'] ?? 0), 3)),
            'reorder_level' => max(0.0, round((float) ($_POST['reorder_level'] ?? 0), 3)),
            'status' => $status,
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];

        try {
            if ($itemId > 0) {
                $params['id'] = $itemId;
                db()->prepare('
                    UPDATE inventory_items
                    SET ledger_id = :ledger_id, sku = :sku, name = :name, item_type = :item_type, unit = :unit,
                        hs_code = :hs_code, tax_rate = :tax_rate, sales_rate = :sales_rate, purchase_rate = :purchase_rate,
                        opening_qty = :opening_qty, reorder_level = :reorder_level, status = :status, notes = :notes
                    WHERE id = :id AND company_id = :company_id
                ')->execute($params);
                log_activity('inventory_item', $itemId, 'updated', 'Inventory item updated.', $userId);
                flash('success', 'Item updated.');
            } else {
                db()->prepare('
                    INSERT INTO inventory_items (
                        company_id, ledger_id, sku, name, item_type, unit, hs_code, tax_rate,
                        sales_rate, purchase_rate, opening_qty, reorder_level, status, notes
                    ) VALUES (
                        :company_id, :ledger_id, :sku, :name, :item_type, :unit, :hs_code, :tax_rate,
                        :sales_rate, :purchase_rate, :opening_qty, :reorder_level, :status, :notes
                    )
                ')->execute($params);
                log_activity('inventory_item', (int) db()->lastInsertId(), 'created', 'Inventory item created.', $userId);
                flash('success', 'Item created.');
            }
        } catch (Throwable $exception) {
            flash('error', (string) $exception->getCode() === '23000'
                ? 'Could not save item: SKU "' . $sku . '" already exists in this company.'
                : 'Could not save item: ' . $exception->getMessage());
        }
        redirect('admin/accounting-inventory.php');
    }

    if ($action === 'record_movement') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $type = (string) ($_POST['transaction_type'] ?? 'adjustment');
        $qty = round(abs((float) ($_POST['quantity'] ?? 0)), 3);
        $rate = round((float) ($_POST['rate'] ?? 0), 2);
        $date = inventory_valid_date((string) ($_POST['transaction_date'] ?? '')) ?? date('Y-m-d');
        if ($qty <= 0 || !in_array($type, $movementTypes, true)) {
            flash('error', 'Select an item, movement type, and positive quantity.');
            redirect('admin/accounting-inventory.php');
        }
        $item = inventory_company_item($itemId, $companyId);
        if (!$item) {
            flash('error', 'Item not found for this company.');
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
        db()->prepare('
            INSERT INTO inventory_transactions (
                company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date,
                qty_in, qty_out, rate, amount, notes
            ) VALUES (
                :company_id, :fiscal_year_id, :item_id, :transaction_type, :ref_no, :transaction_date,
                :qty_in, :qty_out, :rate, :amount, :notes
            )
        ')->execute([
            'company_id' => $companyId,
            'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
            'item_id' => $itemId,
            'transaction_type' => $type,
            'ref_no' => trim((string) ($_POST['ref_no'] ?? '')) ?: null,
            'transaction_date' => $date,
            'qty_in' => $direction === 'in' ? $qty : 0,
            'qty_out' => $direction === 'out' ? $qty : 0,
            'rate' => $rate,
            'amount' => round($qty * $rate, 2),
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ]);
        flash('success', 'Inventory movement recorded: ' . ($direction === 'in' ? '+' : '−') . number_format($qty, 3) . ' ' . $item['unit'] . ' ' . $item['sku'] . '.');
        redirect('admin/accounting-inventory.php');
    }

    if ($action === 'delete_movement') {
        if ((string) ($currentUser['role'] ?? '') !== 'admin') {
            flash('error', 'Only an administrator can delete stock movements.');
            redirect('admin/accounting-inventory.php');
        }
        $movementId = (int) ($_POST['movement_id'] ?? 0);
        // Production rows (consume/produce) belong to manufacturing orders —
        // cancel the order instead of deleting its stock trail.
        $deleted = db()->prepare("
            DELETE FROM inventory_transactions
            WHERE id = :id AND company_id = :company_id AND transaction_type NOT IN ('consume', 'produce')
        ");
        $deleted->execute(['id' => $movementId, 'company_id' => $companyId]);
        if ($deleted->rowCount() > 0) {
            log_activity('inventory_transaction', $movementId, 'deleted', 'Stock movement deleted.', $userId);
            flash('success', 'Stock movement deleted and quantities recalculated.');
        } else {
            flash('error', 'Movement not found, or it belongs to a manufacturing order (cancel the order instead).');
        }
        redirect('admin/accounting-inventory.php');
    }

    if ($action === 'create_manufacturing_order') {
        if (!($inventoryProfile['show_manufacturing'] ?? false)) {
            flash('error', 'Manufacturing orders are available only for manufacturing companies.');
            redirect('admin/accounting-inventory.php');
        }
        $orderNo = strtoupper(trim((string) ($_POST['order_no'] ?? '')));
        $finishedItemId = (int) ($_POST['finished_item_id'] ?? 0);
        $quantity = round((float) ($_POST['quantity'] ?? 0), 3);
        $mode = (string) ($_POST['production_mode'] ?? 'complete') === 'start' ? 'start' : 'complete';
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
                INSERT INTO manufacturing_orders (company_id, fiscal_year_id, order_no, finished_item_id, quantity, status, started_on, completed_on, notes)
                VALUES (:company_id, :fiscal_year_id, :order_no, :finished_item_id, :quantity, :status, :started_on, :completed_on, :notes)
            ');
            $stmt->execute([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                'order_no' => $orderNo,
                'finished_item_id' => $finishedItemId,
                'quantity' => $quantity,
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
                db()->commit();
                log_activity('manufacturing_order', $orderId, 'started', 'Production started (WIP).', $userId);
                flash('success', 'Production order ' . $orderNo . ' started: materials issued, value now sits in Work in Progress. Complete it from the orders table below.');
                redirect('admin/accounting-inventory.php?view=manufacturing');
            }

            $finishedRate = $quantity > 0 ? round($totalInputCost / $quantity, 2) : 0.0;
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
                'amount' => round($quantity * $finishedRate, 2),
                'notes' => 'Finished goods from ' . $orderNo,
            ]);
            $voucherId = inventory_post_production_voucher($companyId, $fiscalYearId, $orderId, $orderNo, $completedOn, (int) ($finishedItem['ledger_id'] ?? 0), $inputCostByLedger, $userId);
            if ($voucherId > 0) {
                db()->prepare("UPDATE inventory_transactions SET voucher_id = :vid WHERE company_id = :cid AND ref_no = :ref AND transaction_type IN ('consume', 'produce')")
                    ->execute(['vid' => $voucherId, 'cid' => $companyId, 'ref' => $orderNo]);
            }
            db()->commit();
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
                db()->commit();
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
            $finishedRate = $quantity > 0 ? round($totalInputCost / $quantity, 2) : 0.0;
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
                'amount' => round($quantity * $finishedRate, 2),
                'notes' => 'Finished goods from ' . $orderNo,
            ]);
            db()->prepare("UPDATE manufacturing_orders SET status = 'completed', completed_on = :done WHERE id = :id")
                ->execute(['done' => $today, 'id' => $orderId]);
            $finishedLedgerStmt = db()->prepare('SELECT ledger_id FROM inventory_items WHERE id = :id');
            $finishedLedgerStmt->execute(['id' => (int) $order['finished_item_id']]);
            $voucherId = inventory_post_production_voucher($companyId, $fiscalYearId, $orderId, $orderNo, $today, (int) ($finishedLedgerStmt->fetchColumn() ?: 0), $inputCostByLedger, $userId);
            if ($voucherId > 0) {
                db()->prepare("UPDATE inventory_transactions SET voucher_id = :vid WHERE company_id = :cid AND ref_no = :ref AND transaction_type IN ('consume', 'produce')")
                    ->execute(['vid' => $voucherId, 'cid' => $companyId, 'ref' => $orderNo]);
            }
            db()->commit();
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

$stockValue = array_sum(array_map(static fn (array $item): float => (float) $item['on_hand'] * (float) $item['purchase_rate'], $items));
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
if ($invView !== 'manufacturing' || !($inventoryProfile['show_manufacturing'] ?? false)) {
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
            <span class="mbw-kpi-label">Items</span>
            <div class="mbw-kpi-value"><?= e((string) count($items)) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= e(implode(', ', array_map(static fn (array $card): string => $card[0], $inventoryTypeCards))) ?></span></span>
        </div>
        <span class="mbw-chip tone-blue"><?= icon('cart') ?></span>
    </a>
    <a class="mbw-kpi" href="<?= e(url('admin/reports-center.php?report=stock-valuation')) ?>" title="Open the Stock Valuation report">
        <div>
            <span class="mbw-kpi-label">Estimated Stock Value</span>
            <div class="mbw-kpi-value"><?= e(site_currency_symbol()) ?><?= e(number_format($stockValue, 2)) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Based on purchase rates &#8594; valuation report</span></span>
        </div>
        <span class="mbw-chip tone-green"><?= icon('wallet') ?></span>
    </a>
    <a class="mbw-kpi" href="<?= e(url('admin/accounting-inventory.php?low=1#item-stock-summary')) ?>" title="Show only items at or below their reorder level">
        <div>
            <span class="mbw-kpi-label">Low Stock</span>
            <div class="mbw-kpi-value"><?= e((string) $lowStockCount) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">At or below reorder level<?= $lowStockCount > 0 ? ' — click to filter' : '' ?></span></span>
        </div>
        <span class="mbw-chip tone-amber"><?= icon('tag') ?></span>
    </a>
    <?php if ($inventoryProfile['show_manufacturing']): ?>
        <a class="mbw-kpi" href="<?= e(url('admin/accounting-inventory.php?view=manufacturing#manufacturing-orders')) ?>" title="Open the manufacturing workspace">
            <div>
                <span class="mbw-kpi-label">Manufacturing Orders</span>
                <div class="mbw-kpi-value"><?= e((string) count($manufacturingOrders)) ?></div>
                <span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= $openOrderCount > 0 ? e($openOrderCount . ' in progress (WIP)') : 'Recent production records' ?></span></span>
            </div>
            <span class="mbw-chip tone-purple"><?= icon('layers') ?></span>
        </a>
    <?php endif; ?>
</section>

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
            <label>Status<select name="status"><option value="active" <?= ($editItem['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= ($editItem['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label>
            <label>Unit<input type="text" name="unit" value="<?= e($editItem['unit'] ?? 'pcs') ?>" required></label>
            <label>HS code<input type="text" name="hs_code" value="<?= e($editItem['hs_code'] ?? '') ?>"></label>
            <label>Tax rate %<input type="number" step="0.01" name="tax_rate" value="<?= e($editItem['tax_rate'] ?? '13.00') ?>"></label>
            <label>Sales rate<input type="number" step="0.01" name="sales_rate" value="<?= e($editItem['sales_rate'] ?? '0.00') ?>"></label>
            <label>Purchase rate<input type="number" step="0.01" name="purchase_rate" value="<?= e($editItem['purchase_rate'] ?? '0.00') ?>"></label>
            <label>Opening qty<input type="number" step="0.001" name="opening_qty" value="<?= e($editItem['opening_qty'] ?? '0.000') ?>"></label>
            <label>Reorder level<input type="number" step="0.001" name="reorder_level" value="<?= e($editItem['reorder_level'] ?? '0.000') ?>"></label>
            <label>Linked ledger<select name="ledger_id"><option value="0">No linked ledger</option><?php foreach ($ledgers as $ledger): ?><option value="<?= e((int) $ledger['id']) ?>" <?= (int) ($editItem['ledger_id'] ?? 0) === (int) $ledger['id'] ? 'selected' : '' ?>><?= e($ledger['code'] . ' - ' . $ledger['name']) ?></option><?php endforeach; ?></select></label>
            <label class="workspace-span-2">Notes<textarea name="notes"><?= e($editItem['notes'] ?? '') ?></textarea></label>
            <div class="workspace-span-2"><button type="submit"><?= icon('services') ?>Save item</button><?php if ($editItem): ?> <a class="button secondary" href="<?= e(url('admin/accounting-inventory.php')) ?>">Cancel edit</a><?php endif; ?></div>
        </form>
    </details>

    <details class="feature-disclosure" id="stock-movement" <?= $moveItemId > 0 ? 'open' : '' ?>>
        <summary><span><strong><?= icon('tasks') ?>Inventory Transaction / Stock Movement</strong><small>Post opening, purchase, sale, return, or adjustment quantities.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
        <form method="post" class="workspace-form-grid" id="movementForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="record_movement">
            <label>Item<select name="item_id" id="movItem" required><option value="">Select item</option><?php foreach ($items as $item): ?><option value="<?= e((int) $item['id']) ?>" data-purchase-rate="<?= e(number_format((float) $item['purchase_rate'], 2, '.', '')) ?>" data-sales-rate="<?= e(number_format((float) $item['sales_rate'], 2, '.', '')) ?>" data-on-hand="<?= e(number_format((float) $item['on_hand'], 3, '.', '')) ?>" <?= $moveItemId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['sku'] . ' - ' . $item['name']) ?></option><?php endforeach; ?></select></label>
            <label>Movement<select name="transaction_type" id="movType"><?php foreach ($movementTypes as $type): ?><option value="<?= e($type) ?>"><?= e(str_replace('_', ' ', ucfirst($type))) ?></option><?php endforeach; ?></select></label>
            <label id="movDirectionWrap" hidden>Direction<select name="direction"><option value="in">Stock in (+)</option><option value="out">Stock out (&#8722;)</option></select></label>
            <label>Date<input type="date" name="transaction_date" value="<?= e(date('Y-m-d')) ?>" required></label>
            <label>Reference<input type="text" name="ref_no" maxlength="120"></label>
            <label>Quantity<input type="number" step="0.001" min="0.001" name="quantity" required><small id="movOnHand" style="color:var(--mbw-muted)"></small></label>
            <label>Rate<input type="number" step="0.01" min="0" name="rate" id="movRate" placeholder="Auto from item"></label>
            <label class="workspace-span-2">Notes<textarea name="notes"></textarea></label>
            <button type="submit"><?= icon('tasks') ?>Record movement</button>
        </form>
        <script>
        (function () {
            var item = document.getElementById('movItem');
            var type = document.getElementById('movType');
            var rate = document.getElementById('movRate');
            var onHand = document.getElementById('movOnHand');
            var directionWrap = document.getElementById('movDirectionWrap');
            function sync() {
                var opt = item.options[item.selectedIndex];
                directionWrap.hidden = type.value !== 'adjustment';
                if (!opt || !opt.value) { onHand.textContent = ''; return; }
                onHand.textContent = 'On hand: ' + (opt.getAttribute('data-on-hand') || '0');
                if (!rate.value || parseFloat(rate.value) === 0) {
                    rate.value = type.value === 'sale' ? opt.getAttribute('data-sales-rate') : opt.getAttribute('data-purchase-rate');
                }
            }
            item.addEventListener('change', function () { rate.value = ''; sync(); });
            type.addEventListener('change', function () { rate.value = ''; sync(); });
            sync();
        })();
        </script>
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
    <?php endif; ?>
</div>
</section>

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
        <thead><tr><th>SKU</th><th>Name</th><th>Type</th><th>Unit</th><th>HS</th><th class="is-numeric">On hand</th><th class="is-numeric">Reorder</th><th class="is-numeric">Sales rate</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if ($visibleItems === []): ?><tr><td colspan="10"><?= $lowOnly ? 'No items are at or below their reorder level.' : 'No items yet.' ?></td></tr><?php endif; ?>
            <?php foreach ($visibleItems as $item): ?>
                <?php $low = $isLowStock($item); ?>
                <tr>
                    <td><?= e($item['sku']) ?></td><td><?= e($item['name']) ?></td><td><?= e(str_replace('_', ' ', $item['item_type'])) ?></td><td><?= e($item['unit']) ?></td><td><?= e($item['hs_code'] ?? '-') ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $item['on_hand'], 3)) ?><?php if ($low): ?> <span class="mbw-pill tone-amber">Low</span><?php endif; ?></td>
                    <td class="is-numeric"><?= (float) $item['reorder_level'] > 0 ? e(number_format((float) $item['reorder_level'], 3)) : '–' ?></td>
                    <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $item['sales_rate'], 2)) ?></td>
                    <td><span class="mbw-pill <?= $item['status'] === 'active' ? 'tone-green' : 'tone-red' ?>"><?= e(ucfirst($item['status'])) ?></span></td>
                    <td style="white-space:nowrap">
                        <a class="button secondary" href="<?= e(url('admin/accounting-inventory.php?edit_id=' . (int) $item['id'] . '#create-item')) ?>">Edit</a>
                        <a class="button secondary" href="<?= e(url('admin/accounting-inventory.php?move_item=' . (int) $item['id'] . '#stock-movement')) ?>" title="Record a stock movement for this item">Move</a>
                        <a class="button secondary" href="<?= e(url('admin/reports-center.php?report=stock-ledger&item_id=' . (int) $item['id'])) ?>" title="Open this item's stock ledger (running balance)">Ledger</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

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
                        <td>
                            <?php if (!in_array((string) $movement['transaction_type'], ['consume', 'produce'], true)): ?>
                                <form method="post" style="display:inline" data-confirm="Delete this <?= e(str_replace('_', ' ', $movement['transaction_type'])) ?> movement of <?= e($movement['sku']) ?> dated <?= e($movement['transaction_date']) ?>? Stock on hand recalculates immediately.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_movement">
                                    <input type="hidden" name="movement_id" value="<?= e((int) $movement['id']) ?>">
                                    <button type="submit" class="button secondary" title="Delete this movement">&times;</button>
                                </form>
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
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
