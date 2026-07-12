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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $sku = strtoupper(trim((string) ($_POST['sku'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $itemType = (string) ($_POST['item_type'] ?? 'stock');
        $status = (string) ($_POST['status'] ?? 'active');
        if ($sku === '' || $name === '' || !in_array($itemType, $itemTypes, true) || !in_array($status, ['active', 'inactive'], true)) {
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
            'tax_rate' => round((float) ($_POST['tax_rate'] ?? 13), 2),
            'sales_rate' => round((float) ($_POST['sales_rate'] ?? 0), 2),
            'purchase_rate' => round((float) ($_POST['purchase_rate'] ?? 0), 2),
            'opening_qty' => round((float) ($_POST['opening_qty'] ?? 0), 3),
            'reorder_level' => round((float) ($_POST['reorder_level'] ?? 0), 3),
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
            flash('error', 'Could not save item. The SKU may already exist.');
        }
        redirect('admin/accounting-inventory.php');
    }

    if ($action === 'record_movement') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $type = (string) ($_POST['transaction_type'] ?? 'adjustment');
        $qty = round(abs((float) ($_POST['quantity'] ?? 0)), 3);
        $rate = round((float) ($_POST['rate'] ?? 0), 2);
        $date = (string) ($_POST['transaction_date'] ?? date('Y-m-d'));
        if ($itemId <= 0 || $qty <= 0 || !in_array($type, $movementTypes, true)) {
            flash('error', 'Select an item, movement type, and positive quantity.');
            redirect('admin/accounting-inventory.php');
        }
        $direction = inventory_direction($type);
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
        flash('success', 'Inventory movement recorded.');
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
        $inputItemIds = $_POST['input_item_id'] ?? [];
        $inputQuantities = $_POST['input_quantity'] ?? [];
        $inputRates = $_POST['input_rate'] ?? [];

        if ($orderNo === '') {
            $orderNo = 'MO-' . date('Ymd-His');
        }
        if ($finishedItemId <= 0 || $quantity <= 0) {
            flash('error', 'Finished item and quantity are required.');
            redirect('admin/accounting-inventory.php');
        }

        try {
            db()->beginTransaction();
            $stmt = db()->prepare('
                INSERT INTO manufacturing_orders (company_id, fiscal_year_id, order_no, finished_item_id, quantity, status, started_on, completed_on, notes)
                VALUES (:company_id, :fiscal_year_id, :order_no, :finished_item_id, :quantity, :status, :started_on, :completed_on, :notes)
            ');
            $completedOn = (string) ($_POST['completed_on'] ?? date('Y-m-d'));
            $stmt->execute([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                'order_no' => $orderNo,
                'finished_item_id' => $finishedItemId,
                'quantity' => $quantity,
                'status' => 'completed',
                'started_on' => $_POST['started_on'] !== '' ? $_POST['started_on'] : $completedOn,
                'completed_on' => $completedOn,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);
            $orderId = (int) db()->lastInsertId();
            $inputStmt = db()->prepare('INSERT INTO manufacturing_order_inputs (manufacturing_order_id, item_id, quantity, rate) VALUES (:order_id, :item_id, :quantity, :rate)');
            $movementStmt = db()->prepare('
                INSERT INTO inventory_transactions (company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date, qty_in, qty_out, rate, amount, notes)
                VALUES (:company_id, :fiscal_year_id, :item_id, :transaction_type, :ref_no, :transaction_date, :qty_in, :qty_out, :rate, :amount, :notes)
            ');
            $totalInputCost = 0.0;
            foreach ($inputItemIds as $index => $inputItemIdRaw) {
                $inputItemId = (int) $inputItemIdRaw;
                $inputQty = round((float) ($inputQuantities[$index] ?? 0), 3);
                $inputRate = round((float) ($inputRates[$index] ?? 0), 2);
                if ($inputItemId <= 0 || $inputQty <= 0) {
                    continue;
                }
                $inputStmt->execute(['order_id' => $orderId, 'item_id' => $inputItemId, 'quantity' => $inputQty, 'rate' => $inputRate]);
                $amount = round($inputQty * $inputRate, 2);
                $totalInputCost += $amount;
                $movementStmt->execute([
                    'company_id' => $companyId,
                    'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                    'item_id' => $inputItemId,
                    'transaction_type' => 'consume',
                    'ref_no' => $orderNo,
                    'transaction_date' => $completedOn,
                    'qty_in' => 0,
                    'qty_out' => $inputQty,
                    'rate' => $inputRate,
                    'amount' => $amount,
                    'notes' => 'Manufacturing input for ' . $orderNo,
                ]);
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
            db()->commit();
            log_activity('manufacturing_order', $orderId, 'completed', 'Manufacturing order completed.', $userId);
            flash('success', 'Manufacturing order completed and stock updated.');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not create manufacturing order. Check order number and item selections.');
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
    SELECT mo.*, i.sku, i.name AS finished_item_name
    FROM manufacturing_orders mo
    INNER JOIN inventory_items i ON i.id = mo.finished_item_id
    WHERE mo.company_id = :company_id
    ORDER BY mo.created_at DESC
    LIMIT 50
');
$orderStmt->execute(['company_id' => $companyId]);
$manufacturingOrders = $orderStmt->fetchAll();

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
    <article class="mbw-kpi">
        <div>
            <span class="mbw-kpi-label">Items</span>
            <div class="mbw-kpi-value"><?= e((string) count($items)) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= e(implode(', ', array_map(static fn (array $card): string => $card[0], $inventoryTypeCards))) ?></span></span>
        </div>
        <span class="mbw-chip tone-blue"><?= icon('cart') ?></span>
    </article>
    <article class="mbw-kpi">
        <div>
            <span class="mbw-kpi-label">Estimated Stock Value</span>
            <div class="mbw-kpi-value"><?= e(site_currency_symbol()) ?><?= e(number_format($stockValue, 2)) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Based on purchase rates</span></span>
        </div>
        <span class="mbw-chip tone-green"><?= icon('wallet') ?></span>
    </article>
    <article class="mbw-kpi">
        <div>
            <span class="mbw-kpi-label">Low Stock</span>
            <div class="mbw-kpi-value"><?= e((string) $lowStockCount) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">At or below reorder level</span></span>
        </div>
        <span class="mbw-chip tone-amber"><?= icon('tag') ?></span>
    </article>
    <?php if ($inventoryProfile['show_manufacturing']): ?>
        <article class="mbw-kpi">
            <div>
                <span class="mbw-kpi-label">Manufacturing Orders</span>
                <div class="mbw-kpi-value"><?= e((string) count($manufacturingOrders)) ?></div>
                <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Recent production records</span></span>
            </div>
            <span class="mbw-chip tone-purple"><?= icon('layers') ?></span>
        </article>
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
            <label>Type<select name="item_type"><?php foreach ($itemTypes as $type): ?><option value="<?= e($type) ?>" <?= ($editItem['item_type'] ?? 'stock') === $type ? 'selected' : '' ?>><?= e(str_replace('_', ' ', ucfirst($type))) ?></option><?php endforeach; ?></select></label>
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

    <details class="feature-disclosure" id="stock-movement">
        <summary><span><strong><?= icon('tasks') ?>Inventory Transaction / Stock Movement</strong><small>Post opening, purchase, sale, return, or adjustment quantities.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="record_movement">
            <label>Item<select name="item_id" required><option value="">Select item</option><?php foreach ($items as $item): ?><option value="<?= e((int) $item['id']) ?>"><?= e($item['sku'] . ' - ' . $item['name']) ?></option><?php endforeach; ?></select></label>
            <label>Movement<select name="transaction_type"><?php foreach ($movementTypes as $type): ?><option value="<?= e($type) ?>"><?= e(str_replace('_', ' ', ucfirst($type))) ?></option><?php endforeach; ?></select></label>
            <label>Date<input type="date" name="transaction_date" value="<?= e(date('Y-m-d')) ?>" required></label>
            <label>Reference<input type="text" name="ref_no" maxlength="120"></label>
            <label>Quantity<input type="number" step="0.001" min="0.001" name="quantity" required></label>
            <label>Rate<input type="number" step="0.01" min="0" name="rate"></label>
            <label class="workspace-span-2">Notes<textarea name="notes"></textarea></label>
            <button type="submit"><?= icon('tasks') ?>Record movement</button>
        </form>
    </details>

    <?php endif; ?>
    <?php if ($inventoryProfile['show_manufacturing'] && $invView === 'manufacturing'): ?>
        <details class="feature-disclosure" id="manufacturing">
            <summary><span><strong><?= icon('settings') ?>Production Completion</strong><small>Consume input items and produce finished goods in one step.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
            <form method="post" class="workspace-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_manufacturing_order">
                <label>Order no<input type="text" name="order_no" placeholder="Leave blank for auto"></label>
                <label>Finished item<select name="finished_item_id" required><option value="">Select finished item</option><?php foreach ($items as $item): ?><option value="<?= e((int) $item['id']) ?>"><?= e($item['sku'] . ' - ' . $item['name']) ?></option><?php endforeach; ?></select></label>
                <label>Quantity produced<input type="number" step="0.001" min="0.001" name="quantity" required></label>
                <label>Completed on<input type="date" name="completed_on" value="<?= e(date('Y-m-d')) ?>"></label>
                <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="workspace-span-2 workspace-form-grid">
                        <label>Input item<select name="input_item_id[]"><option value="">Select input</option><?php foreach ($items as $item): ?><option value="<?= e((int) $item['id']) ?>"><?= e($item['sku'] . ' - ' . $item['name']) ?></option><?php endforeach; ?></select></label>
                        <label>Input qty<input type="number" step="0.001" min="0" name="input_quantity[]"></label>
                        <label>Input rate<input type="number" step="0.01" min="0" name="input_rate[]"></label>
                    </div>
                <?php endfor; ?>
                <label class="workspace-span-2">Notes<textarea name="notes"></textarea></label>
                <button type="submit"><?= icon('settings') ?>Complete order</button>
            </form>
        </details>
    <?php endif; ?>
</div>
</section>

<?php if ($inventoryProfile['show_manufacturing']): ?>
    <section class="mbw-flow-panel manufacturing-flow" aria-label="Manufacturing production workflow">
        <?php foreach (['Create Production Order', 'Select Finished Item & BOM', 'Issue Materials', 'Record Work in Progress', 'Complete Production', 'Finished Goods Receipt', 'Update Stock & Accounting'] as $stepIndex => $stepLabel): ?>
            <div><b><?= e((string) ($stepIndex + 1)) ?></b><span><?= e($stepLabel) ?></span></div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($invView === 'inventory'): ?>
<section class="mbw-card">
    <div class="mbw-card-head"><h2>Item Stock Summary</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>SKU</th><th>Name</th><th>Type</th><th>Unit</th><th>HS</th><th class="is-numeric">On hand</th><th class="is-numeric">Sales rate</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if ($items === []): ?><tr><td colspan="9">No items yet.</td></tr><?php endif; ?>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['sku']) ?></td><td><?= e($item['name']) ?></td><td><?= e(str_replace('_', ' ', $item['item_type'])) ?></td><td><?= e($item['unit']) ?></td><td><?= e($item['hs_code'] ?? '-') ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $item['on_hand'], 3)) ?></td><td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $item['sales_rate'], 2)) ?></td><td><span class="mbw-pill <?= $item['status'] === 'active' ? 'tone-green' : 'tone-red' ?>"><?= e(ucfirst($item['status'])) ?></span></td>
                    <td><a class="button secondary" href="<?= e(url('admin/accounting-inventory.php?edit_id=' . (int) $item['id'])) ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="mbw-card">
    <div class="mbw-card-head"><h2>Recent Stock Movements</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Date</th><th>Item</th><th>Type</th><th class="is-numeric">In</th><th class="is-numeric">Out</th><th class="is-numeric">Rate</th><th class="is-numeric">Amount</th><th>Ref</th></tr></thead>
        <tbody>
            <?php if ($movements === []): ?><tr><td colspan="8">No stock movements yet.</td></tr><?php endif; ?>
            <?php foreach ($movements as $movement): ?>
                <tr><td><?= e($movement['transaction_date']) ?></td><td><?= e($movement['sku'] . ' - ' . $movement['item_name']) ?></td><td><span class="mbw-pill <?= inventory_direction($movement['transaction_type']) === 'in' ? 'tone-blue' : 'tone-gray' ?>"><?= e(str_replace('_', ' ', ucfirst($movement['transaction_type']))) ?></span></td><td class="is-numeric"><?= e(number_format((float) $movement['qty_in'], 3)) ?></td><td class="is-numeric"><?= e(number_format((float) $movement['qty_out'], 3)) ?></td><td class="is-numeric"><?= e(number_format((float) $movement['rate'], 2)) ?></td><td class="is-numeric"><?= e(number_format((float) $movement['amount'], 2)) ?></td><td><?= e($movement['ref_no'] ?? '-') ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<?php endif; ?>
<?php if ($inventoryProfile['show_manufacturing'] && $invView === 'manufacturing'): ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Manufacturing Orders</h2></div>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Order</th><th>Finished item</th><th class="is-numeric">Quantity</th><th>Status</th><th>Completed</th></tr></thead>
            <tbody>
                <?php if ($manufacturingOrders === []): ?><tr><td colspan="5">No manufacturing orders yet.</td></tr><?php endif; ?>
                <?php foreach ($manufacturingOrders as $order): ?>
                    <tr><td><?= e($order['order_no']) ?></td><td><?= e($order['sku'] . ' - ' . $order['finished_item_name']) ?></td><td class="is-numeric"><?= e(number_format((float) $order['quantity'], 3)) ?></td><td><span class="mbw-pill <?= $order['status'] === 'completed' ? 'tone-green' : 'tone-amber' ?>"><?= e(ucfirst($order['status'])) ?></span></td><td><?= e($order['completed_on'] ?? '-') ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
