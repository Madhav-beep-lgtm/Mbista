<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/inventory_pages.php';
if (defined('INVENTORY_PAGE')) {
    $inventoryPage = inventory_pages()[INVENTORY_PAGE];
    $_GET['view'] = $inventoryPage['view'];
    $_GET['task'] = INVENTORY_PAGE;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_POST['view'] = $inventoryPage['view'];
        $_POST['task'] = INVENTORY_PAGE;
    }
}
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/manufacturing_engine.php';
require_once __DIR__ . '/../../app/production_voucher.php';
// The item master is shared with the Jewellery module, so this page has to be
// able to read and complete the jewellery half of an item. Pure function
// definitions — it does nothing unless the client has the module switched on.
require_once __DIR__ . '/../../app/jewellery_stock.php';
// The ledger-mapping catalogue lives in app/ so Hospitality and Jewellery can
// show the SAME table rather than each keeping a copy of the list.
require_once __DIR__ . '/../../app/inventory_mapping.php';
require_once __DIR__ . '/../../app/inventory_coding.php';
require_once __DIR__ . '/../../app/inventory_stock_types.php';
require_once __DIR__ . '/../../app/opening_stock_import.php';
// An inventory item can also BE a recipe ingredient. Pure definitions — the
// kitchen list is only touched for a company that has hospitality switched on
// and an item actually marked.
require_once __DIR__ . '/../../app/hospitality_engine.php';
// A supplier's bill is entered as a grid and posted as one transaction.
require_once __DIR__ . '/../../app/inventory_purchase_batch.php';

require_staff_admin_or_client_books();
require_company_context();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !defined('INVENTORY_PAGE') && !defined('ITEMS_WORKSPACE') && !defined('PRODUCTION_VOUCHER') && !defined('STOCK_TRANSFER_VOUCHER')) {
    $legacyDestination = inventory_legacy_page($_GET);
    if ($legacyDestination !== null) redirect($legacyDestination);
}
if (in_array((string) ($_GET['task'] ?? ''), ['purchase', 'sale'], true)) {
    require_permission('accounting', 'view');
}
if (!defined('ITEMS_WORKSPACE') && $_SERVER['REQUEST_METHOD'] === 'GET' && $_GET === []) {
    redirect('admin/items.php');
}

$repairErrors = accounting_module_repair_database();
$pageTitle = 'Inventory Management';
$company = current_company();
$fiscalYear = current_fiscal_year();
$currentUser = current_user();
$companyId = (int) ($company['id'] ?? 0);
$fiscalYearId = (int) ($fiscalYear['id'] ?? 0);
$userId = (int) ($currentUser['id'] ?? 0);
$inventoryBusinessType = company_accounting_business_type($companyId);
$inventoryProfile = accounting_business_profile($inventoryBusinessType);
require_company_module('inventory',$companyId);
if (defined('PRODUCTION_VOUCHER') && !$inventoryProfile['show_manufacturing']) deny_access('Production belongs to the trading and manufacturing profile.');
if (defined('INVENTORY_PAGE') && $inventoryPage['view'] === 'manufacturing' && empty($inventoryProfile['show_manufacturing'])) {
    flash('error', 'Manufacturing is not enabled for this company.');
    redirect('admin/items.php');
}

if (!($inventoryProfile['show_inventory'] ?? false)) {
    flash('error', 'Inventory and manufacturing tools are available only for trading and manufacturing companies.');
    redirect('admin/accounting-dashboard.php');
}

$itemTypes = $inventoryProfile['show_manufacturing']
    ? ['stock', 'service', 'raw_material', 'finished_good', 'consumable']
    : ['stock', 'service', 'consumable'];
// inventory_direction(), inventory_valid_date() and
// inventory_company_warehouse_id() moved to app/inventory_valuation.php when
// the multi-line purchase entry needed them too. They are loaded by bootstrap.
/**
 * Back to the page the form was submitted from.
 *
 * Each task is its own page now, so a redirect to the bare URL would drop
 * somebody who just saved a warehouse onto Item Master. Every form on this
 * screen carries the task it belongs to, and this hands it back.
 */
function inv_back_url(string $extra = ''): string
{
    if (defined('STOCK_TRANSFER_VOUCHER')) return 'admin/voucher-form.php?type=stock_transfer';
    if (defined('PRODUCTION_VOUCHER')) return 'admin/voucher-form.php?type=production';
    if (defined('INVENTORY_PAGE')) {
        return 'admin/' . inventory_pages()[INVENTORY_PAGE]['file']
            . ($extra === '' ? '' : (str_starts_with($extra, '#') ? $extra : '?' . ltrim($extra, '&?')));
    }
    if (defined('ITEMS_WORKSPACE')) {
        return 'admin/items.php';
    }
    $tasks = ['item', 'opening', 'warehouses', 'purchase', 'sale', 'adjust', 'transfer', 'manufacturing', 'bom'];
    $task = (string) ($_POST['task'] ?? $_GET['task'] ?? '');
    $views = ['inventory', 'valuation', 'manufacturing'];
    $view = (string) ($_POST['view'] ?? $_GET['view'] ?? '');

    $query = [];
    if (in_array($view, $views, true) && $view !== 'inventory') {
        $query[] = 'view=' . $view;
    }
    if (in_array($task, $tasks, true)) {
        $query[] = 'task=' . $task;
    }
    // $extra is either a #fragment or further query pairs the caller already
    // built, so it is appended rather than merged.
    $fragment = '';
    if (str_starts_with($extra, '#')) {
        $fragment = $extra;
    } elseif ($extra !== '') {
        $query[] = ltrim($extra, '&?');
    }

    return 'admin/accounting-inventory.php'
        . ($query !== [] ? '?' . implode('&', $query) : '')
        . $fragment;
}

$movementTypes = [
    'opening', 'purchase', 'sale', 'sales_return', 'purchase_return', 'adjustment',
    'write_off', 'damage', 'expiry', 'warehouse_transfer', 'departmental_transfer',
];

/** The posting purposes that apply to ONE item, by its type (FA-style filter). */
function inventory_purposes_for_item(array $item): array
{
    // 'purchases' and 'purchase_returns' are where bought-in goods land when
    // the books are kept PERIODIC. They are per-item like everything else here:
    // a shop that wants gold purchases in one account and packing material in
    // another says so on the item, not by keeping one Purchases account and
    // sorting it out afterwards.
    $base = ['inventory_asset', 'purchases', 'purchase_returns', 'opening_equity', 'purchase_clearing', 'cogs', 'sales_revenue', 'inventory_gain', 'inventory_loss', 'write_down_expense', 'write_down_allowance', 'write_down_reversal', 'tax_input', 'tax_output'];
    return match ((string) ($item['item_type'] ?? 'stock')) {
        'raw_material' => array_merge($base, ['raw_material', 'wip']),
        'finished_good' => array_merge($base, ['finished_goods', 'wip', 'labour_clearing', 'overhead_absorbed']),
        'wip' => array_merge($base, ['wip', 'raw_material', 'finished_goods']),
        'scrap', 'by_product' => array_merge($base, ['scrap_inventory', 'wip']),
        default => $base,
    };
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
 * Turn the engine's ALLOWANCE_CONSUMED guard into something a human can act on,
 * or null when the throwable is some other failure.
 */
function inventory_allowance_block_message(Throwable $e): ?string
{
    if (!str_starts_with($e->getMessage(), 'ALLOWANCE_CONSUMED:')) {
        return null;
    }
    $consumed = (float) substr($e->getMessage(), 19);

    return 'This NRV write-down can no longer be undone: ' . site_currency_symbol() . number_format($consumed, 2)
        . ' of the allowance it raised has already been released as the written-down stock left (sold, written off or issued). '
        . 'Reverse those outward movements first, then reverse the write-down.';
}

/**
 * A POSTed warehouse id, but only if it belongs to this company — otherwise
 * null. The warehouse FKs reference warehouses(id) with no company predicate,
 * so a tampered id from another tenant would otherwise insert cleanly and tag
 * this company's stock with a foreign location.
 */
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
    if (defined('STOCK_TRANSFER_VOUCHER') && ($action !== 'record_movement' || !inv_movement_is_location_only((string) ($_POST['transaction_type'] ?? '')))) {
        http_response_code(400);
        exit('Unsupported stock transfer action.');
    }
    if (defined('STOCK_TRANSFER_VOUCHER')) {
        $_SESSION['stock_transfer_retry'][$companyId . ':' . $fiscalYearId] = array_intersect_key($_POST, array_flip([
            'ref_no', 'transaction_date', 'transaction_type', 'warehouse_id', 'to_warehouse_id', 'item_id', 'quantity', 'notes',
        ]));
    }
    if (defined('PRODUCTION_VOUCHER') && $action !== 'create_manufacturing_order') {
        http_response_code(400);
        exit('Unsupported production action.');
    }
    if (defined('ITEMS_WORKSPACE') && !in_array($action, ['save_item', 'save_item_unit', 'save_stock_type'], true)) {
        http_response_code(400);
        exit('Unsupported Items action.');
    }
    if (defined('ITEMS_WORKSPACE') && $action === 'save_stock_type') {
        require_permission('inventory', 'create');
        try {
            inv_stock_type_create($companyId, (string)($_POST['stock_type_name'] ?? ''));
            flash('success', 'Stock type created.');
        } catch (Throwable $error) {
            flash('error', (string)$error->getCode() === '23000' ? 'That stock type already exists.' : $error->getMessage());
        }
        redirect('admin/items.php?section=types');
    }
    if (defined('ITEMS_WORKSPACE') && $action === 'save_item_unit') {
        require_permission('inventory', 'create');
        require_once __DIR__ . '/../../app/inventory_unit_creation.php';
        try {
            $values = inventory_unit_creation_values($_POST, inv_units_list($companyId, false));
            db()->prepare('INSERT INTO inventory_units (company_id, name, base_unit_id, factor) VALUES (:company_id, :name, :base_unit_id, :factor)')
                ->execute(['company_id' => $companyId] + $values);
            flash('success', 'Unit of measurement created. It is now available when creating items.');
        } catch (Throwable $error) {
            flash('error', (string) $error->getCode() === '23000' ? 'That unit already exists.' : $error->getMessage());
        }
        redirect('admin/items.php?section=units');
    }

    if ($action === 'save_inventory_method') {
        // Which of the two systems the books are kept on. Deliberately behind
        // the same right as posting a voucher: this decides what every future
        // purchase debits, which is a bookkeeping decision and not a display
        // preference.
        require_permission('accounting', 'post');
        require_once __DIR__ . '/../../app/inventory_valuation.php';
        $wanted = (string) ($_POST['inventory_accounting'] ?? '') === 'periodic' ? 'periodic' : 'perpetual';
        $current = inv_accounting_method($companyId);
        if ($wanted !== $current) {
            // THIS COMPANY's books, not the server's. It used to write one row
            // in `settings`, whose key is the setting name alone, so choosing
            // periodic for a jewellery shop chose it for every other client on
            // the installation at the same instant -- the cafe's purchases
            // stopped debiting stock and nothing on the cafe's screen said so.
            inv_set_accounting_method($companyId, $wanted);
            log_activity('inventory', $companyId, 'updated',
                'Inventory accounting for this company switched from ' . $current . ' to ' . $wanted . '.', $userId);
            flash('success', $wanted === 'periodic'
                ? 'Now on the PERIODIC system. New purchases will debit Purchases, and sales will post no cost'
                    . ' entry. Books already posted the other way are unchanged until they are converted —'
                    . ' run deploy/convert-to-periodic.php, earliest year first.'
                : 'Back on the PERPETUAL system. New purchases will debit Inventory and each sale will post'
                    . ' its own cost of sales.');
        }
        redirect('admin/inventory-valuation.php#inventory-method');
    }

    if ($action === 'purge_sample_inventory') {
        // Remove the seeded SMP-* demo inventory (items, movements, layers,
        // their vouchers, sample manufacturing orders) in one consistent
        // sweep so reports show only stock recorded through this module.
        require_permission('accounting', 'post');
        if ((string) (current_user()['role'] ?? '') !== 'admin') {
            flash('error', 'Only an admin can remove the sample data.');
            redirect(inv_back_url());
        }
        require_once __DIR__ . '/../../app/stock_report_engine.php';
        $purge = sr_purge_sample_inventory($companyId, $userId);
        log_activity('inventory_item', $companyId, 'sample_purged', 'Sample inventory removed: ' . $purge['items'] . ' items, ' . $purge['transactions'] . ' movements, ' . $purge['vouchers'] . ' vouchers, ' . $purge['orders'] . ' orders.', $userId);
        flash('success', 'Sample data removed: ' . $purge['items'] . ' item(s), ' . $purge['transactions'] . ' movement(s), ' . $purge['vouchers'] . ' voucher(s), ' . $purge['orders'] . ' manufacturing order(s). The Stock Summary now shows only stock recorded through Inventory & Manufacturing.');
        redirect(inv_back_url());
    }

    if ($action === 'save_item') {
        require_permission('inventory', 'create');
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $sku = strtoupper(trim((string) ($_POST['sku'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $itemType = 'stock';
        $existing = null;
        $stockTypeId = (int)($_POST['stock_type_id'] ?? 0);
        try { inv_stock_type_validate($companyId, $stockTypeId); }
        catch (InvalidArgumentException $error) { flash('error', $error->getMessage()); redirect(inv_back_url()); }
        $status = (string) ($_POST['status'] ?? 'active');
        // When editing, the item may carry a type outside this business
        // profile's list (e.g. finished_good in a trading company): keep it
        // selectable instead of silently converting the item.
        $allowedTypes = $itemTypes;
        if ($itemId > 0) {
            $existing = inventory_company_item($itemId, $companyId);
            if (!$existing) {
                flash('error', 'Item not found for this company.');
                redirect(inv_back_url());
            }
            if (!in_array((string) $existing['item_type'], $allowedTypes, true)) {
                $allowedTypes[] = (string) $existing['item_type'];
            }
            $itemType = (string)$existing['item_type'];
        }
        if (!in_array($itemType, $allowedTypes, true) || !in_array($status, ['active', 'inactive'], true)) {
            flash('error', 'Item type and status are required.');
            redirect(inv_back_url());
        }
        // The code goes on to be a barcode payload, a spreadsheet column and a
        // URL parameter, so what may be in it is settled in one place rather
        // than by whatever each of those happens to tolerate.
        // A unit and a purchase category are chosen from the company's own
        // master lists. The id arrives on a posted form as whatever it was
        // sent with, so membership is checked rather than assumed: an item
        // pointed at another company's unit would convert its stock by that
        // company's factor.
        $unitId = (int) ($_POST['unit_id'] ?? 0);
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        foreach ([inv_unit_choice_error($companyId, $unitId),
                  inv_category_choice_error($companyId, $categoryId)] as $masterError) {
            if ($masterError !== null) {
                flash('error', $masterError);
                redirect(inv_back_url());
            }
        }
        $unitName = (string) (inv_units_list($companyId)[$unitId]['name'] ?? '');
        $categoryName = (string) (inv_categories_list($companyId)[$categoryId]['name'] ?? '');

        foreach ([inv_item_code_error($sku), inv_item_name_error($name),
                  inv_hs_code_error((string) ($_POST['hs_code'] ?? ''))] as $codingError) {
            if ($codingError !== null) {
                flash('error', $codingError);
                redirect(inv_back_url());
            }
        }

        $validMethods = ['fifo', 'weighted_average', 'specific'];
        $existingStockLedgerId = 0;
        if ($itemId > 0) {
            $stockLink = db()->prepare('SELECT ledger_id FROM inventory_items WHERE id = ? AND company_id = ?');
            $stockLink->execute([$itemId, $companyId]);
            $existingStockLedgerId = (int)$stockLink->fetchColumn();
        }
        $valuationMethod = (string) ($_POST['valuation_method'] ?? 'weighted_average');
        if (!in_array($valuationMethod, $validMethods, true)) {
            $valuationMethod = 'weighted_average';
        }
        $params = [
            'company_id' => $companyId,
            // The legacy linked-ledger column follows the item's chosen
            // Inventory Asset ledger so older reads stay consistent.
            'ledger_id' => (int) ($_POST['item_map']['inventory_asset'] ?? ($_POST['ledger_id'] ?? $existingStockLedgerId)) ?: null,
            'sku' => $sku,
            'name' => $name,
            'category' => $categoryName !== '' ? $categoryName : null,
            'category_id' => $categoryId ?: null,
            'item_type' => $itemType,
            'valuation_method' => $valuationMethod,
            'unit' => $unitName !== '' ? $unitName : 'pcs',
            'unit_id' => $unitId ?: null,
            'hs_code' => trim((string) ($_POST['hs_code'] ?? '')) ?: null,
            'tax_rate' => (float)($existing['tax_rate'] ?? 0),
            'sales_rate' => max(0.0, round((float) ($_POST['sales_rate'] ?? 0), 2)),
            'purchase_rate' => max(0.0, round((float) ($_POST['purchase_rate'] ?? 0), 2)),
            'opening_qty' => max(0.0, round((float) ($_POST['opening_qty'] ?? 0), 3)),
            // Opening VALUE is stored, never derived from the current purchase
            // rate later — like the accounting opening balances (qty + amount).
            'opening_amount' => max(0.0, round((float) ($_POST['opening_amount'] ?? 0), 2)),
            'reorder_level' => max(0.0, round((float) ($_POST['reorder_level'] ?? 0), 3)),
            'default_warehouse_id' => $existing['default_warehouse_id'] ?? null,
            'status' => $status,
            'notes' => $existing['notes'] ?? null,
        ];
        // Ticking this is what puts the item in the kitchen's ingredient list.
        // The column is only written when the database has it, so an install
        // that has not run the repair pass yet saves exactly as before.
        $marksIngredient = column_exists('inventory_items', 'is_ingredient');
        if ($marksIngredient) {
            $params['is_ingredient'] = isset($_POST['is_ingredient']) ? 1 : 0;
        }

        if ($params['opening_amount'] <= 0 && $params['opening_qty'] > 0) {
            // Legacy convenience: an omitted value defaults ONCE to qty x the
            // purchase rate typed NOW — after that it stays frozen.
            $params['opening_amount'] = round($params['opening_qty'] * $params['purchase_rate'], 2);
        }
        $openingUnitCost = $params['opening_qty'] > 0 ? round($params['opening_amount'] / $params['opening_qty'], 6) : 0.0;
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
                        ' . ($marksIngredient ? 'is_ingredient = :is_ingredient,' : '') . '
                        valuation_method = :valuation_method, unit = :unit, unit_id = :unit_id, category_id = :category_id,
                        hs_code = :hs_code, tax_rate = :tax_rate, sales_rate = :sales_rate, purchase_rate = :purchase_rate,
                        opening_qty = :opening_qty, opening_amount = :opening_amount, reorder_level = :reorder_level, default_warehouse_id = :default_warehouse_id,
                        status = :status, notes = :notes
                    WHERE id = :id AND company_id = :company_id
                ')->execute($params);
                // Opening qty/rate or method may have changed — rebuild layers.
                inv_rebuild_layers($companyId, $itemId, $valuationMethod, (float) $params['opening_qty'], $openingUnitCost);
                log_activity('inventory_item', $itemId, 'updated', 'Inventory item updated.', $userId);
                $savedItemId = $itemId;
                flash('success', 'Item updated.');
            } else {
                db()->prepare('
                    INSERT INTO inventory_items (
                        company_id, ledger_id, sku, name, category, category_id, unit_id, item_type, ' . ($marksIngredient ? 'is_ingredient, ' : '') . 'valuation_method, unit, hs_code, tax_rate,
                        sales_rate, purchase_rate, opening_qty, opening_amount, reorder_level, default_warehouse_id, status, notes
                    ) VALUES (
                        :company_id, :ledger_id, :sku, :name, :category, :category_id, :unit_id, :item_type, ' . ($marksIngredient ? ':is_ingredient, ' : '') . ':valuation_method, :unit, :hs_code, :tax_rate,
                        :sales_rate, :purchase_rate, :opening_qty, :opening_amount, :reorder_level, :default_warehouse_id, :status, :notes
                    )
                ')->execute($params);
                $newItemId = (int) db()->lastInsertId();
                // Seed the opening cost layer so valuation is correct from day one.
                if ((float) $params['opening_qty'] > 0) {
                    inv_add_layer($companyId, $newItemId, (float) $params['opening_qty'], $openingUnitCost, '2000-01-01');
                }
                log_activity('inventory_item', $newItemId, 'created', 'Inventory item created.', $userId);
                $savedItemId = $newItemId;
                flash('success', 'Item created.');
            }
            // The ledgers chosen on the form belong to THIS item only — saved
            // as item-scope mappings so every movement (purchase, sale,
            // adjustment, NRV, manufacturing) posts to them without a
            // separate mapping step. 0 = inherit the category/global default.
            db()->prepare('UPDATE inventory_items SET stock_type_id = ? WHERE id = ? AND company_id = ?')->execute([$stockTypeId, $savedItemId, $companyId]);
            foreach ((array) ($_POST['item_map'] ?? []) as $mapPurpose => $mapLedgerId) {
                inventory_set_item_ledger($companyId, $savedItemId, (string) $mapPurpose, (int) $mapLedgerId, $userId);
            }
            // The item master is shared with the Jewellery module, so this form
            // completes the jewellery half too — an item created here must not
            // be invisible over there. Blank metal = a plain inventory item.
            if (function_exists('jw_save_item_profile') && !empty($_POST['jw_enabled'])) {
                jw_save_item_profile($companyId, $savedItemId, [
                    'metal_id' => (int) ($_POST['jw_metal_id'] ?? 0),
                    'purity_id' => (int) ($_POST['jw_purity_id'] ?? 0),
                    'unit_id' => (int) ($_POST['jw_unit_id'] ?? 0),
                    'jewellery_type' => (string) ($_POST['jw_type'] ?? 'ornament'),
                    'gross_weight' => (float) ($_POST['jw_gross_weight'] ?? 0),
                    'stone_weight' => (float) ($_POST['jw_stone_weight'] ?? 0),
                    'making_charge_rate' => (float) ($_POST['jw_making_rate'] ?? 0),
                    'vat_applicable' => isset($_POST['jw_vat_applicable']) ? 1 : 0,
                    'vat_base' => (string) ($_POST['jw_vat_base'] ?? 'default'),
                ]);
            }
            // Master opening stock must reach the BOOKS too (Dr stock ledger /
            // Cr opening equity), or the balance sheet starts life missing the
            // opening inventory the layers already carry. Resolved AFTER the
            // mapping loop so ledgers chosen on this very form are honoured;
            // replaced/cleared automatically when the opening changes.
            $openingResult = inv_post_item_opening_voucher($companyId, array_merge($params, ['id' => $savedItemId]), $userId);
            db()->commit();
            if (($openingResult['note'] ?? '') !== '') {
                flash('error', $openingResult['note']);
            }
            // An item marked as an ingredient appears in the kitchen's list
            // straight away. Done after the commit rather than inside it: the
            // ingredient master is a convenience view of this item, and failing
            // to refresh it must never roll back the item itself.
            if ($marksIngredient && function_exists('hospitality_sync_ingredients_from_inventory')) {
                try {
                    hospitality_sync_ingredients_from_inventory($companyId, $userId);
                } catch (Throwable $ingredientError) {
                    flash('error', 'The item saved, but the kitchen ingredient list could not be refreshed: ' . $ingredientError->getMessage());
                }
            }
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', (string) $exception->getCode() === '23000'
                ? 'Could not save item: SKU "' . $sku . '" already exists in this company.'
                : 'Could not save item: ' . $exception->getMessage());
        }
        redirect(inv_back_url());
    }

    // --- Opening stock from a spreadsheet --------------------------------
    // Same staged flow the Jewellery screen uses, on the same engine: an
    // upload posts nothing until a deliberate commit.
    if ($action === 'upload_opening') {
        try {
            $file = $_FILES['opening_file'] ?? null;
            if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a .xlsx or .csv file to upload.');
            }
            $originalName = (string) ($file['name'] ?? 'sheet');
            $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, ['xlsx', 'csv'], true)) {
                throw new RuntimeException('Upload a .xlsx or .csv file.');
            }
            $fy = function_exists('current_fiscal_year') ? current_fiscal_year() : null;
            $staged = opening_import_stage($companyId, (int) ($fy['id'] ?? 0), (string) $file['tmp_name'],
                $extension, $originalName, 'inventory', $userId);
            flash($staged['valid_count'] === $staged['row_count'] ? 'success' : 'info',
                $staged['row_count'] . ' row(s) read, ' . $staged['valid_count'] . ' ready. Nothing posted yet.');
            redirect(inv_back_url('import=' . $staged['import_id'] . '#opening-import'));
        } catch (Throwable $uploadException) {
            flash('error', $uploadException->getMessage());
        }
        redirect(inv_back_url('#opening-import'));
    }

    if ($action === 'update_opening_import_row') {
        $res = opening_import_update_row($companyId, (int) ($_POST['row_id'] ?? 0), [
            'item_id' => (int) ($_POST['item_id'] ?? 0),
            'qty_pieces' => (float) ($_POST['qty_pieces'] ?? 0),
            'rate' => (float) ($_POST['rate'] ?? 0),
            'amount' => (float) ($_POST['amount'] ?? 0),
        ]);
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Row updated.' : $res['error']);
        redirect(inv_back_url('import=' . (int) ($_POST['import_id'] ?? 0) . '#opening-import'));
    }

    if ($action === 'delete_opening_import_row') {
        $res = opening_import_delete_row($companyId, (int) ($_POST['row_id'] ?? 0));
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Row removed.' : $res['error']);
        redirect(inv_back_url('import=' . (int) ($_POST['import_id'] ?? 0) . '#opening-import'));
    }

    if ($action === 'discard_opening_import') {
        $res = opening_import_discard($companyId, (int) ($_POST['import_id'] ?? 0));
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Import discarded.' : $res['error']);
        redirect(inv_back_url('#opening-import'));
    }

    if ($action === 'commit_opening_import') {
        $importId = (int) ($_POST['import_id'] ?? 0);
        $fy = function_exists('current_fiscal_year') ? current_fiscal_year() : null;
        $res = opening_import_commit($companyId, $importId, (int) ($fy['id'] ?? 0), $userId);
        if ($res['ok']) {
            $msg = $res['committed'] . ' opening row(s) committed.';
            if ($res['failures'] !== []) {
                $msg .= ' ' . count($res['failures']) . ' could not be — they are still in the import.';
            }
            flash($res['failures'] === [] ? 'success' : 'info', $msg);
        } else {
            flash('error', $res['error']);
        }
        redirect(inv_back_url('import=' . $importId . '#opening-import'));
    }
    if ($action === 'save_warehouse') {
        require_permission('inventory', 'create');
        $warehouseName = trim((string) ($_POST['name'] ?? ''));
        $warehouseCode = trim((string) ($_POST['code'] ?? '')) ?: null;
        if ($warehouseName === '') {
            flash('error', 'Warehouse name is required.');
            redirect(inv_back_url());
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
        redirect(inv_back_url());
    }

    if ($action === 'toggle_warehouse') {
        require_permission('inventory', 'edit');
        $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
        $warehouseStmt = db()->prepare('SELECT * FROM warehouses WHERE id = :id AND company_id = :company_id LIMIT 1');
        $warehouseStmt->execute(['id' => $warehouseId, 'company_id' => $companyId]);
        $warehouse = $warehouseStmt->fetch();
        if (!$warehouse) {
            flash('error', 'Warehouse not found for this company.');
            redirect(inv_back_url());
        }
        $newActive = (int) $warehouse['is_active'] === 1 ? 0 : 1;
        db()->prepare('UPDATE warehouses SET is_active = :is_active WHERE id = :id AND company_id = :company_id')
            ->execute(['is_active' => $newActive, 'id' => $warehouseId, 'company_id' => $companyId]);
        security_event('warehouse_toggled', 'success', 'Warehouse #' . $warehouseId . ' ' . ($newActive ? 'activated' : 'deactivated') . '.', $companyId, $userId);
        flash('success', 'Warehouse "' . $warehouse['name'] . '" ' . ($newActive ? 'activated' : 'deactivated') . '.');
        redirect(inv_back_url());
    }

    if ($action === 'post_movement_draft') {
        // Stock moved when the movement was recorded; this is the accounting
        // entry catching up. Posting is what hands out the voucher number, so a
        // draft that is never posted leaves no gap in the series.
        require_permission('inventory', 'create');
        $draftId = (int) ($_POST['voucher_id'] ?? 0);
        $draftStmt = db()->prepare("SELECT id, voucher_no, status, voucher_date, fiscal_year_id, total_amount FROM vouchers WHERE id = :id AND company_id = :cid AND source_type = 'inventory_movement' LIMIT 1");
        $draftStmt->execute(['id' => $draftId, 'cid' => $companyId]);
        $draft = $draftStmt->fetch(PDO::FETCH_ASSOC);
        if (!$draft) {
            flash('error', 'That entry was not found for this company.');
            redirect(inv_back_url());
        }
        if ((string) $draft['status'] !== 'draft') {
            flash('error', 'This entry is already posted as ' . (string) $draft['voucher_no'] . '.');
            redirect(inv_back_url());
        }
        $draftDate = (string) ($draft['voucher_date'] ?? date('Y-m-d'));
        if (is_period_locked($companyId, (int) $draft['fiscal_year_id'], $draftDate)) {
            flash('error', 'The entry is dated ' . $draftDate . ', which is inside a locked accounting period.');
            redirect(inv_back_url());
        }
        // A draft may be saved unbalanced; the books may not. The shared writer
        // only checks this when a voucher is CREATED as posted, and posting
        // here is an update, so the guard has to be applied again.
        $lineStmt = db()->prepare('SELECT entry_type, amount FROM voucher_entries WHERE voucher_id = :v');
        $lineStmt->execute(['v' => (int) $draft['id']]);
        $balance = 0.0;
        foreach ($lineStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $balance += (string) $line['entry_type'] === 'debit' ? (float) $line['amount'] : -(float) $line['amount'];
        }
        if (abs(round($balance, 2)) > 0.005) {
            flash('error', 'Refusing to post: this entry is out by ' . number_format(abs($balance), 2) . '.');
            redirect(inv_back_url());
        }
        $voucherNo = '';
        $posted = false;
        $taken = false;
        for ($attempt = 0; $attempt < 5 && !$posted; $attempt++) {
            $voucherNo = next_voucher_no($companyId, 'INV-PUR-');
            try {
                $upd = db()->prepare("UPDATE vouchers SET voucher_no = :no, status = 'posted', approval_state = 'approved', posted_by = :uid, posted_at = NOW() WHERE id = :id AND status = 'draft'");
                $upd->execute(['no' => $voucherNo, 'uid' => $userId, 'id' => (int) $draft['id']]);
                if ($upd->rowCount() > 0) {
                    $posted = true;
                } else {
                    $taken = true;
                    break;
                }
            } catch (PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
        if ($taken) {
            flash('error', 'Somebody else posted this entry a moment ago.');
        } elseif (!$posted) {
            flash('error', 'Could not allocate a voucher number for this entry. Try again.');
        } else {
            security_event('inventory_movement_posted', 'success', 'Purchase voucher ' . $voucherNo . ' posted.', $companyId, $userId);
            flash('success', 'Posted as voucher ' . $voucherNo . ' — ' . site_currency_symbol() . number_format((float) $draft['total_amount'], 2) . ' now in the ledger.');
        }
        redirect(inv_back_url());
    }

    if ($action === 'delete_purchase_bill') {
        // Removing a bill removes it: the entry, its lines, the stock it
        // brought in, and the value that stock was carrying. A posted one is
        // unposted on the way out rather than reversed, because what goes
        // wrong with a purchase entry is a typo, and a reversal would leave
        // three vouchers where the honest answer is none. Admin only, and both
        // logs record what the voucher number used to be.
        if ((string) ($currentUser['role'] ?? '') !== 'admin') {
            flash('error', 'Only an administrator can delete a purchase entry.');
            redirect(inv_back_url('#movement-purchase-entries'));
        }
        require_permission('inventory', 'create');
        $removed = inv_purchase_bill_delete($companyId, (int) ($_POST['voucher_id'] ?? 0), $userId);
        if ($removed['ok']) {
            flash('success', 'Purchase entry ' . ($removed['voucher_no'] !== '' ? $removed['voucher_no'] : '(draft)')
                . ' deleted — ' . $removed['items'] . ' item(s) and ' . site_currency_symbol()
                . number_format($removed['amount'], 2) . ' taken back out of the stock and the books.');
        } else {
            flash('error', (string) $removed['error']);
        }
        redirect(inv_back_url('#movement-purchase-entries'));
    }

    if ($action === 'merge_purchase_bills') {
        // Entries raised one voucher per item, before a bill knew how to stay
        // in one piece. The figures are carried across rather than recomputed,
        // so the GL is unchanged by the gathering-up.
        require_permission('accounting', 'post');
        $merged = 0;
        $absorbed = 0;
        $problems = [];
        foreach (inv_purchase_bill_merge_plan($companyId) as $plan) {
            $result = inv_purchase_bill_merge($companyId, (int) $plan['keep'], $plan['absorb'], $userId);
            if ($result['ok']) {
                $merged++;
                $absorbed += (int) $result['absorbed'];
            } else {
                $problems[] = 'bill ' . $plan['ref_no'] . ': ' . (string) $result['error'];
            }
        }
        if ($merged === 0 && $problems === []) {
            flash('success', 'Every bill is already a single entry — nothing to merge.');
        } else {
            $msg = $merged . ' bill(s) gathered back into one entry each, absorbing ' . $absorbed . ' duplicate voucher(s).';
            if ($problems !== []) {
                $msg .= ' Left alone: ' . implode(' | ', array_slice($problems, 0, 4))
                    . (count($problems) > 4 ? ' +' . (count($problems) - 4) . ' more' : '') . '.';
            }
            flash($problems === [] ? 'success' : 'error', $msg);
        }
        redirect(inv_back_url('#movement-purchase-entries'));
    }

    if ($action === 'record_purchase_batch') {
        require_permission('inventory', 'create');
        flash('error', 'This purchase entry form has been retired. Use Purchase vouchers; opening stock is maintained separately.');
        redirect('admin/voucher-form.php?type=purchase');
    }

    if ($action === 'record_movement') {
        require_permission('inventory', 'create');
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $type = (string) ($_POST['transaction_type'] ?? 'adjustment');
        if ($type === 'purchase') {
            flash('error', 'Record item purchases through a Purchase voucher.');
            redirect('admin/voucher-form.php?type=purchase');
        }
        if (in_array($type, ['sale', 'sales_return', 'purchase_return'], true)) {
            $voucherType = ['sale' => 'sales', 'sales_return' => 'credit_note', 'purchase_return' => 'debit_note'][$type];
            flash('error', 'Record sales and returns through vouchers.');
            redirect('admin/voucher-form.php?type=' . $voucherType);
        }
        $qty = round(abs((float) ($_POST['quantity'] ?? 0)), 3);
        $rate = round((float) ($_POST['rate'] ?? 0), 2);
        $date = inventory_valid_date((string) ($_POST['transaction_date'] ?? '')) ?? date('Y-m-d');
        $warehouseId = inventory_company_warehouse_id((int) ($_POST['warehouse_id'] ?? 0), $companyId);
        $toWarehouseId = inventory_company_warehouse_id((int) ($_POST['to_warehouse_id'] ?? 0), $companyId);
        $refNo = trim((string) ($_POST['ref_no'] ?? '')) ?: null;
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        // A bought-in movement is somebody's bill: it carries VAT, it may have
        // tax withheld from it, and the day it is entered is not the day it was
        // bought. None of that applies to a sale, an issue or a transfer, so
        // these are read here but only used for the inward purchase types.
        $movePostingDate = inventory_valid_date((string) ($_POST['posting_date'] ?? '')) ?? $date;
        $moveVat = max(0.0, round((float) ($_POST['vat_amount'] ?? 0), 2));
        $moveTdsRate = max(0.0, min(100.0, (float) ($_POST['tds_rate_pct'] ?? 0)));
        $moveVatLedgerId = (int) ($_POST['vat_ledger_id'] ?? 0);
        $moveTdsLedgerId = (int) ($_POST['tds_ledger_id'] ?? 0);
        if ($qty <= 0 || !in_array($type, $movementTypes, true)) {
            flash('error', 'Select an item, movement type, and positive quantity.');
            redirect(inv_back_url());
        }
        $item = inventory_company_item($itemId, $companyId);
        if (!$item) {
            flash('error', 'Item not found for this company.');
            redirect(inv_back_url());
        }
        $method = (string) ($item['valuation_method'] ?? 'weighted_average');

        // Enforce the fiscal-period lock HERE, before any stock row is written.
        // Transfers post no GL voucher, and a normal movement whose ledgers are
        // unmapped records stock but catches the voucher's MAP_MISSING — both
        // paths skip create_voucher_with_entries (the usual lock choke point), so
        // without this check stock could be dated into a closed period, silently
        // changing on-hand and valuation behind the lock.
        if (table_exists('fiscal_years')) {
            $moveFiscalYear = fiscal_year_for_date($companyId, $date);
            if (!$moveFiscalYear) {
                flash('error', 'No fiscal year covers ' . $date . '. Open a fiscal year for that period before recording stock.');
                redirect(inv_back_url());
            }
            $moveBlocker = fiscal_year_posting_blocker($moveFiscalYear, $date);
            if ($moveBlocker !== null) {
                flash('error', $moveBlocker);
                redirect(inv_back_url());
            }
        }

        // Warehouse / departmental transfers relocate stock inside the entity:
        // two linked rows (out of the source, in to the destination) and nothing
        // else. The company still owns the same units at the same cost, so the
        // cost layers are deliberately NOT touched (consuming them on the way out
        // and re-adding on the way in would re-order the FIFO queue and mis-state
        // later COGS) and no GL voucher is posted. Cost here is informational
        // only, stamped from the item's current carrying cost.
        if (inv_movement_is_location_only($type)) {
            if (!defined('STOCK_TRANSFER_VOUCHER')) {
                flash('error', 'Record stock transfers through the Stock Transfer voucher.');
                redirect('admin/voucher-form.php?type=stock_transfer');
            }
            if (!inventory_valid_date((string) ($_POST['transaction_date'] ?? '')) || (int) ($_POST['context_fiscal_year_id'] ?? 0) !== $fiscalYearId || (int) ($moveFiscalYear['id'] ?? 0) !== $fiscalYearId) {
                flash('error', 'Select a transfer date in the active financial year.');
                redirect(inv_back_url());
            }
            if (!is_finite($qty) || (float) ($_POST['quantity'] ?? 0) <= 0 || $item['status'] !== 'active' || !preg_match('/^STF-[A-Z0-9-]{1,50}$/', (string) $refNo)) {
                flash('error', 'Valid transfer number and quantity required.');
                redirect(inv_back_url());
            }
            if (trim((string)$notes) === '') {
                flash('error', 'Enter a narration for the stock transfer.');
                redirect(inv_back_url());
            }
            if ($warehouseId === null || $toWarehouseId === null || $warehouseId === $toWarehouseId) {
                flash('error', 'Select two different warehouses for a transfer (from and to).');
                redirect(inv_back_url());
            }
            // Availability must be checked at the SOURCE warehouse, not company-
            // wide: the company can hold plenty of an item while the warehouse
            // being transferred out of holds none, which would otherwise drive
            // that location negative and invent stock at the destination.
            $sourceQty = inv_item_warehouse_qty($companyId, $itemId, $warehouseId);
            if ($qty > $sourceQty + 0.0005) {
                flash('error', 'Insufficient stock at the source warehouse: it holds ' . number_format($sourceQty, 3) . ' ' . $item['unit'] . ' of ' . $item['sku'] . ' (company-wide on hand is ' . number_format((float) $item['on_hand'], 3) . ', but stock can only move out of the location that actually holds it).');
                redirect(inv_back_url());
            }
            // Informational unit cost: the item's current carrying cost per unit.
            // The layers are not consumed, so nothing is actually drawn down here.
            $balance = inv_layer_balance($companyId, $itemId);
            $unitCostAtIssue = $balance['qty'] > 0.00005 ? round($balance['value'] / $balance['qty'], 6) : 0.0;
            $transferValue = round($qty * $unitCostAtIssue, 2);
            try {
                db()->beginTransaction();
                $transferCompanyLock = db()->prepare('SELECT id FROM companies WHERE id = ? FOR UPDATE');
                $transferCompanyLock->execute([$companyId]);
                $activeLocations = db()->prepare('SELECT COUNT(*) FROM warehouses WHERE company_id = ? AND is_active = 1 AND id IN (?, ?)');
                $activeLocations->execute([$companyId, $warehouseId, $toWarehouseId]);
                if ((int)$activeLocations->fetchColumn() !== 2) throw new RuntimeException('Select two active company warehouses.');
                $transferLock = db()->prepare('SELECT id FROM inventory_items WHERE id = ? AND company_id = ? FOR UPDATE');
                $transferLock->execute([$itemId, $companyId]);
                if (!$transferLock->fetchColumn()) throw new RuntimeException('Transfer item not found.');
                $duplicate = db()->prepare("SELECT id FROM inventory_transactions WHERE company_id = ? AND ref_no = ? AND transaction_type IN ('warehouse_transfer','departmental_transfer') LIMIT 1");
                $duplicate->execute([$companyId, $refNo]);
                if ($duplicate->fetchColumn()) throw new RuntimeException('This stock transfer voucher has already been posted.');
                if ($qty > inv_item_warehouse_qty($companyId, $itemId, $warehouseId) + 0.0005) throw new RuntimeException('Insufficient stock at the source warehouse.');
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
                unset($_SESSION['stock_transfer_retry'][$companyId . ':' . $fiscalYearId]);
                security_event('inventory_movement_posted', 'success', 'Transfer #' . $outTxnId . '/' . $inTxnId . ' (' . $type . ') posted for item #' . $itemId . '.', $companyId, $userId);
                flash('success', 'Transfer recorded: ' . number_format($qty, 3) . ' ' . $item['unit'] . ' ' . $item['sku'] . ' moved. No GL entry — quantity relocated only (IAS 2 recognition unaffected).');
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                flash('error', 'Could not record transfer: ' . $exception->getMessage());
            }
            redirect(inv_back_url());
        }

        // Adjustments choose their own direction (stock count corrections go
        // both ways); every other type has a fixed one.
        $direction = $type === 'adjustment' && in_array((string) ($_POST['direction'] ?? ''), ['in', 'out'], true)
            ? (string) $_POST['direction']
            : inventory_direction($type);
        if ($type === 'opening' && (float) $item['opening_qty'] > 0) {
            flash('error', 'This item already has an opening quantity (' . number_format((float) $item['opening_qty'], 3) . ') on its master record — edit the item instead of recording a second opening, or stock would be double-counted.');
            redirect(inv_back_url());
        }
        if ($direction === 'out') {
            // When a warehouse is chosen, availability must be checked at THAT
            // location, not company-wide: the company can hold plenty overall
            // while the chosen warehouse holds none, which would otherwise drive
            // that location's stock negative (the row is stamped with warehouse_id).
            if ($warehouseId !== null) {
                $sourceWarehouseQty = inv_item_warehouse_qty($companyId, $itemId, $warehouseId);
                if ($qty > $sourceWarehouseQty + 0.0005) {
                    flash('error', 'Insufficient stock at the selected warehouse: it holds ' . number_format($sourceWarehouseQty, 3) . ' ' . $item['unit'] . ' of ' . $item['sku'] . ' (company-wide on hand is ' . number_format((float) $item['on_hand'], 3) . ', but stock can only leave the location that actually holds it).');
                    redirect(inv_back_url());
                }
            } elseif ($qty > (float) $item['on_hand'] + 0.0005) {
                flash('error', 'Insufficient stock: only ' . number_format((float) $item['on_hand'], 3) . ' ' . $item['unit'] . ' of ' . $item['sku'] . ' on hand. Record a purchase or an inward adjustment first.');
                redirect(inv_back_url());
            }
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
            // Optional supplier on purchase movements: the counterparty leg then
            // hits that party's payable ledger instead of purchase clearing.
            $movementPartyId = (int) ($_POST['supplier_party_id'] ?? 0);
            if ($movementPartyId > 0) {
                $partyChk = db()->prepare('SELECT COUNT(*) FROM accounting_parties WHERE id = :id AND company_id = :cid');
                $partyChk->execute(['id' => $movementPartyId, 'cid' => $companyId]);
                if ((int) $partyChk->fetchColumn() === 0 || !in_array($type, ['purchase', 'purchase_receipt', 'purchase_return'], true)) {
                    $movementPartyId = 0;
                }
            }
            $movementVoucherId = 0;
            $mapMissing = [];
            try {
                // Bought-in stock is prepared as a draft so the entry can be
                // read before it counts, and so the VAT and the withholding on
                // the supplier's bill are visible next to the stock value they
                // are deliberately kept out of. Every other movement type posts
                // exactly as it did before.
                $movementExtra = [];
                if (in_array($type, ['purchase', 'opening'], true) && $direction === 'in') {
                    $movementExtra = [
                        'draft' => true,
                        'vat' => $moveVat,
                        'tds' => tds_from_rate($postingValue, $moveTdsRate),
                        'vat_ledger_id' => $moveVatLedgerId,
                        'tds_ledger_id' => $moveTdsLedgerId,
                        'posting_date' => $movePostingDate,
                        'reference_no' => (string) ($refNo ?? ''),
                    ];
                }
                $movementVoucherId = inv_post_movement_voucher($companyId, $fiscalYearId, $txnId, $type, $item, $direction, $postingValue, $date, $userId, $movementPartyId ?: null, $movementExtra);
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
            // Written-down stock leaving must carry its share of the allowance out
            // with it, or COGS is struck at full cost while the allowance strands
            // on the balance sheet forever (IAS 2.34).
            [$allowanceReleased, ] = inv_post_allowance_release(
                $companyId, $fiscalYearId, $txnId, $item, $type, $direction,
                $qtyOut, (float) $item['on_hand'], $date, $userId,
                $movementVoucherId, $issueValue
            );
            db()->commit();
            $costNote = $qtyOut > 0 ? ' Issue cost (' . strtoupper(str_replace('_', ' ', $method)) . '): ' . site_currency_symbol() . number_format($issueValue, 2) . '.' : '';
            $glNote = '';
            if ($movementVoucherId > 0) {
                $glNote = ' GL voucher posted (' . site_currency_symbol() . number_format($postingValue, 2) . ').';
            } elseif ($mapMissing !== []) {
                $labels = array_map(static fn (string $p): string => inventory_mapping_purposes()[$p]['label'] ?? $p, $mapMissing);
                $glNote = ' Stock recorded — map ' . implode(' & ', $labels) . ' in the item\'s "This item posts to" panel (edit the item) to auto-post the accounting voucher.';
            }
            $relNote = $allowanceReleased > 0
                ? ' NRV allowance released: ' . site_currency_symbol() . number_format($allowanceReleased, 2) . ' (expense reduced to the written-down carrying amount, IAS 2.34).'
                : '';
            security_event('inventory_movement_posted', 'success', 'Movement #' . $txnId . ' (' . $type . ') posted for item #' . $itemId . '.', $companyId, $userId);
            flash('success', 'Inventory movement recorded: ' . ($direction === 'in' ? '+' : '−') . number_format($qty, 3) . ' ' . $item['unit'] . ' ' . $item['sku'] . '.' . $costNote . $glNote . $relNote);
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not record movement: ' . $exception->getMessage());
        }
        redirect(inv_back_url());
    }

    if ($action === 'post_nrv_assessment') {
        require_permission('inventory', 'edit');
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $item = inventory_company_item($itemId, $companyId);
        if (!$item) {
            flash('error', 'Item not found for this company.');
            redirect('admin/inventory-valuation.php');
        }
        $sellingPrice = round((float) ($_POST['selling_price'] ?? 0), 2);
        $completionCost = round((float) ($_POST['completion_cost'] ?? 0), 2);
        $sellingCost = round((float) ($_POST['selling_cost'] ?? 0), 2);

        $valuation = inv_item_valuation($companyId, $item);
        $qty = (float) $valuation['qty'];
        $unitCost = (float) $valuation['unit_cost'];

        // The allowance STANDING against the item — net of what has already been
        // reversed (NRV recovered) and released (the stock left), and ignoring
        // assessments whose movement was reversed. Summing write_down - reversal
        // alone would keep counting allowance that is no longer on the books, and
        // would silently block every later write-down on this item.
        $priorWriteDown = inv_standing_allowance($companyId, $itemId);

        $nrv = inv_nrv($qty, $unitCost, $sellingPrice, $completionCost, $sellingCost, $priorWriteDown);

        if ($nrv['write_down'] <= 0 && $nrv['reversal'] <= 0) {
            flash('info', 'No write-down or reversal needed — carrying value already at lower of cost and NRV.');
            redirect('admin/inventory-valuation.php');
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
            // Link the assessment to the movement it posted, so reversing that
            // movement can void the allowance it raised.
            db()->prepare('UPDATE inventory_nrv_assessments SET source_txn_id = :txn WHERE id = :id AND company_id = :cid')
                ->execute(['txn' => $txnId, 'id' => $assessmentId, 'cid' => $companyId]);

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
                $glNote = ' Stock-only recorded — map ' . implode(' & ', $labels) . ' in the item\'s "This item posts to" panel (edit the item) to auto-post the accounting voucher.';
            }
            security_event('inventory_nrv_posted', 'success', ($isWriteDown ? 'NRV write-down ' : 'NRV reversal ') . number_format($postedValue, 2) . ' posted for item #' . $itemId . '.', $companyId, $userId);
            flash('success', ($isWriteDown ? 'NRV write-down of ' : 'NRV reversal of ') . site_currency_symbol() . number_format($postedValue, 2) . ' posted for ' . $item['sku'] . '.' . $glNote);
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not post NRV assessment: ' . $exception->getMessage());
        }
        redirect('admin/inventory-valuation.php');
    }

    if ($action === 'delete_movement') {
        if ((string) ($currentUser['role'] ?? '') !== 'admin') {
            flash('error', 'Only an administrator can delete stock movements.');
            redirect(inv_back_url());
        }
        $movementId = (int) ($_POST['movement_id'] ?? 0);
        $linkedGuard = column_exists('inventory_transactions', 'jewellery_stock_txn_id')
            ? ' AND jewellery_stock_txn_id IS NULL'
            : '';
        $mvStmt = db()->prepare("SELECT * FROM inventory_transactions WHERE id = :id AND company_id = :cid AND transaction_type NOT IN ('consume', 'produce')"
            . $linkedGuard . ' LIMIT 1');
        $mvStmt->execute(['id' => $movementId, 'cid' => $companyId]);
        $movement = $mvStmt->fetch(PDO::FETCH_ASSOC);
        if (!$movement) {
            flash('error', 'Movement not found, or it is controlled by Manufacturing/Jewellery (reverse it from its source document instead).');
            redirect(inv_back_url());
        }
        // A transfer is a PAIR of rows (out of the source, in to the destination).
        // Deleting or reversing one leg on its own would leave the other standing,
        // inventing stock at one location and destroying it at the other. Move it
        // back with a transfer in the opposite direction instead.
        if (inv_movement_is_location_only((string) $movement['transaction_type'])) {
            flash('error', 'This is one leg of a transfer. Deleting a single leg would leave stock stranded at the other location — record a transfer in the opposite direction instead.');
            redirect(inv_back_url());
        }
        // A movement that posted a GL voucher must not be silently deleted —
        // that would orphan a posted voucher. Reverse it instead (spec E).
        if ((int) ($movement['voucher_id'] ?? 0) > 0) {
            flash('error', 'This movement posted an accounting voucher — use "Reverse" instead of delete, so both the stock and the voucher are reversed and the audit trail is preserved.');
            redirect(inv_back_url());
        }
        try {
            db()->beginTransaction();
            // Void any allowance this movement raised or released. A deleted NRV
            // write-down whose assessment row survived would keep counting toward
            // the standing allowance and silently block every later write-down.
            inv_void_allowance_rows_for_txn($companyId, $movementId, $fiscalYearId, date('Y-m-d'), $userId);
            db()->prepare('DELETE FROM inventory_transactions WHERE id = :id AND company_id = :cid')->execute(['id' => $movementId, 'cid' => $companyId]);
            inv_rebuild_item($companyId, (int) $movement['item_id']); // recompute cost layers
            db()->commit();
            security_event('inventory_movement_deleted', 'success', 'Movement #' . $movementId . ' deleted.', $companyId, $userId);
            log_activity('inventory_transaction', $movementId, 'deleted', 'Stock-only movement deleted.', $userId);
            flash('success', 'Stock movement deleted and cost layers recalculated.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) { db()->rollBack(); }
            flash('error', inventory_allowance_block_message($e) ?? 'Could not delete movement: ' . $e->getMessage());
        }
        redirect(inv_back_url());
    }

    if ($action === 'reverse_movement') {
        if ((string) ($currentUser['role'] ?? '') !== 'admin') {
            flash('error', 'Only an administrator can reverse stock movements.');
            redirect(inv_back_url());
        }
        $movementId = (int) ($_POST['movement_id'] ?? 0);
        $linkedGuard = column_exists('inventory_transactions', 'jewellery_stock_txn_id')
            ? ' AND jewellery_stock_txn_id IS NULL'
            : '';
        $mvStmt = db()->prepare("SELECT * FROM inventory_transactions WHERE id = :id AND company_id = :cid AND transaction_type NOT IN ('consume', 'produce')"
            . $linkedGuard . ' LIMIT 1');
        $mvStmt->execute(['id' => $movementId, 'cid' => $companyId]);
        $movement = $mvStmt->fetch(PDO::FETCH_ASSOC);
        if (!$movement) {
            flash('error', 'Movement not found, or it is controlled by Manufacturing/Jewellery (reverse it from its source document instead).');
            redirect(inv_back_url());
        }
        if (inv_movement_is_location_only((string) $movement['transaction_type'])) {
            flash('error', 'This is one leg of a transfer. Reversing a single leg would leave stock stranded at the other location — record a transfer in the opposite direction instead.');
            redirect(inv_back_url());
        }
        // One reversal per movement: the mirror row is keyed REV-<id>, so a
        // re-submit (or double click) must not duplicate stock and GL again.
        $revExistsStmt = db()->prepare('SELECT id FROM inventory_transactions WHERE company_id = :cid AND ref_no = :ref LIMIT 1');
        $revExistsStmt->execute(['cid' => $companyId, 'ref' => 'REV-' . $movementId]);
        if ($revExistsStmt->fetchColumn()) {
            flash('error', 'Movement #' . $movementId . ' has already been reversed — reversing it again would duplicate the stock and the accounting entries.');
            redirect(inv_back_url());
        }
        $revItem = inventory_company_item((int) $movement['item_id'], $companyId);
        try {
            db()->beginTransaction();
            // Void the allowance rows this movement raised or released, so a
            // reversed NRV write-down stops standing against the item (otherwise
            // prior_write_down stays overstated forever and future write-downs
            // are silently blocked) and a reversed sale gives its released
            // allowance back.
            [$voidedAllowanceRows, $voidedAllowanceNet] = inv_void_allowance_rows_for_txn($companyId, $movementId, $fiscalYearId, date('Y-m-d'), $userId);
            // Post a mirror stock movement (opposite direction).
            $revIn = (float) $movement['qty_out'];
            $revOut = (float) $movement['qty_in'];
            $today = date('Y-m-d');

            // Read the original voucher's legs up-front: we both reverse them
            // (Dr/Cr swap) and use the inventory cost they carry to re-inject
            // stock at COST rather than the stored rate.
            $origVoucherId = (int) ($movement['voucher_id'] ?? 0);
            $origEntries = [];
            $origDebitTotal = 0.0;
            if ($origVoucherId > 0) {
                $entries = db()->prepare('SELECT ledger_id, entry_type, amount FROM voucher_entries WHERE voucher_id = :vid');
                $entries->execute(['vid' => $origVoucherId]);
                $origEntries = $entries->fetchAll(PDO::FETCH_ASSOC);
                foreach ($origEntries as $en) {
                    if ($en['entry_type'] === 'debit') { $origDebitTotal += (float) $en['amount']; }
                }
            }

            // Reversing an OUTWARD movement re-adds stock: value it at the cost
            // that was removed, never the stored rate. For a sale the stored rate
            // is the SELLING price, so re-injecting at it would inflate the cost
            // layers (inv_rebuild_layers values an inward row at its `rate`) and
            // permanently push the perpetual subledger above the GL. The GL swap
            // restores inventory by exactly the original voucher's debit total, so
            // re-inject at that same cost/unit to keep subledger == GL. Fall back
            // to the item's current unit cost when the movement had no voucher.
            $revRate = (float) $movement['rate'];
            if ($revIn > 0) {
                if ($origDebitTotal > 0) {
                    $revRate = round($origDebitTotal / $revIn, 6);
                } else {
                    $bal = inv_layer_balance($companyId, (int) $movement['item_id']);
                    if ((float) ($bal['qty'] ?? 0) > 0) {
                        $revRate = round(((float) $bal['value']) / (float) $bal['qty'], 6);
                    }
                }
            }

            db()->prepare('INSERT INTO inventory_transactions (company_id, fiscal_year_id, item_id, transaction_type, ref_no, transaction_date, qty_in, qty_out, rate, amount, notes)
                VALUES (:cid, :fy, :iid, :type, :ref, :d, :qin, :qout, :rate, :amt, :notes)')
                ->execute([
                    'cid' => $companyId, 'fy' => $fiscalYearId > 0 ? $fiscalYearId : null, 'iid' => (int) $movement['item_id'],
                    'type' => (string) $movement['transaction_type'], 'ref' => 'REV-' . $movementId,
                    'd' => $today, 'qin' => $revIn, 'qout' => $revOut, 'rate' => $revRate,
                    'amt' => round(($revIn + $revOut) * $revRate, 2),
                    'notes' => 'Reversal of movement #' . $movementId,
                ]);
            $revTxnId = (int) db()->lastInsertId();
            inv_rebuild_item($companyId, (int) $movement['item_id']); // net the layers

            // Reverse the original voucher by swapping its Dr/Cr, never deleting it.
            $reversalVoucherId = 0;
            if ($origEntries !== []) {
                $reversed = [];
                $total = 0.0;
                foreach ($origEntries as $en) {
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
            flash('error', inventory_allowance_block_message($e) ?? 'Could not reverse movement: ' . $e->getMessage());
        }
        redirect(inv_back_url());
    }

    if ($action === 'save_inventory_mappings') {
        require_permission('inventory', 'edit');
        // Global-default ledger mappings for inventory posting purposes. Each
        // ledger is validated to belong to this company before it is stored.
        // Scope: global defaults, per-category overrides (category is the
        // items' free-text category), or per-item overrides. Resolution walks
        // item -> category -> global (inv_resolve_mapping).
        $mapScopeRaw = (string) ($_POST['map_scope'] ?? 'global');
        $mapScope = in_array($mapScopeRaw, ['global', 'category', 'item'], true) ? $mapScopeRaw : 'global';
        $mapCategory = $mapScope === 'category' ? trim((string) ($_POST['map_category'] ?? '')) : '';
        $mapItemId = $mapScope === 'item' ? (int) ($_POST['map_item_id'] ?? 0) : 0;
        if ($mapScope === 'category' && $mapCategory === '') {
            flash('error', 'Select an item category for the override.');
            redirect(inv_back_url());
        }
        if ($mapScope === 'item') {
            $chk = db()->prepare('SELECT COUNT(*) FROM inventory_items WHERE id = :id AND company_id = :cid');
            $chk->execute(['id' => $mapItemId, 'cid' => $companyId]);
            if ($mapItemId <= 0 || (int) $chk->fetchColumn() === 0) {
                flash('error', 'Select a valid inventory item for the override.');
                redirect(inv_back_url());
            }
        }
        $scopeWhere = 'company_id = :cid AND scope = :scope AND purpose = :p AND '
            . ($mapScope === 'category' ? 'category = :sid AND item_id IS NULL' : ($mapScope === 'item' ? 'item_id = :sid AND category IS NULL' : 'category IS NULL AND item_id IS NULL'));
        // The per-item panel drives this action now (the mapping tab is gone);
        // return to the item being edited so the panel re-renders in place.
        $backTo = $mapScope === 'item'
            ? 'admin/items.php?edit_id=' . $mapItemId . '#create-item'
            : 'admin/accounting-inventory.php';

        $purposes = array_keys(inventory_mapping_purposes());
        $saved = 0;
        foreach ($purposes as $purpose) {
            // Only purposes present in the submission are touched: the panel
            // shows a type-filtered subset, and an unsubmitted purpose must
            // keep its existing row instead of being wiped.
            if (!array_key_exists($purpose, (array) ($_POST['map'] ?? []))) {
                continue;
            }
            $ledgerId = (int) ($_POST['map'][$purpose] ?? 0);
            $deleteParams = ['cid' => $companyId, 'scope' => $mapScope, 'p' => $purpose];
            if ($mapScope !== 'global') {
                $deleteParams['sid'] = $mapScope === 'category' ? $mapCategory : $mapItemId;
            }
            // Delete-then-insert: the unique key treats NULL scope columns as
            // distinct, so ON DUPLICATE KEY cannot dedupe override rows.
            db()->prepare('DELETE FROM inventory_ledger_mappings WHERE ' . $scopeWhere)->execute($deleteParams);
            // These mappings just changed; forget what was read of them.
            inv_mapping_forget();
            if ($ledgerId <= 0) {
                continue;
            }
            $own = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :id AND company_id = :cid');
            $own->execute(['id' => $ledgerId, 'cid' => $companyId]);
            if ((int) $own->fetchColumn() === 0) {
                continue; // never map a foreign company's ledger
            }
            db()->prepare('
                INSERT INTO inventory_ledger_mappings (company_id, scope, category, item_id, purpose, ledger_id, created_by)
                VALUES (:cid, :scope, :cat, :iid, :p, :lid, :uid)
            ')->execute([
                'cid' => $companyId,
                'scope' => $mapScope,
                'cat' => $mapCategory !== '' ? $mapCategory : null,
                'iid' => $mapItemId > 0 ? $mapItemId : null,
                'p' => $purpose,
                'lid' => $ledgerId,
                'uid' => $userId,
            ]);
                // These mappings just changed; forget what was read of them.
                inv_mapping_forget();
            $saved++;
        }
        log_activity('inventory_mapping', $companyId, 'updated', ucfirst($mapScope) . ' inventory ledger mappings updated (' . $saved . ' set).', $userId);
        flash('success', ucfirst($mapScope) . ' inventory ledger mappings saved (' . $saved . ' purpose' . ($saved === 1 ? '' : 's') . ' mapped).');
        redirect($backTo);
    }

    if (in_array($action,['save_bom','create_manufacturing_order','complete_manufacturing_order','cancel_manufacturing_order'],true) && !$inventoryProfile['show_manufacturing']) {
        deny_access('Manufacturing is not available for this business profile.');
    }
    if ($action === 'save_bom') {
        require_permission('inventory', 'create');
        if (!($inventoryProfile['show_manufacturing'] ?? false)) {
            flash('error', 'BOMs are available only for manufacturing companies.');
            redirect(inv_back_url());
        }
        $bomNo = strtoupper(trim((string) ($_POST['bom_no'] ?? ''))) ?: ('BOM-' . date('ymdHis'));
        $finishedItemId = (int) ($_POST['finished_item_id'] ?? 0);
        $outputQty = max(0.001, round((float) ($_POST['output_qty'] ?? 1), 3));
        $stdLabour = max(0.0, round((float) ($_POST['std_labour_cost'] ?? 0), 2));
        $stdOverhead = max(0.0, round((float) ($_POST['std_overhead_cost'] ?? 0), 2));
        $finishedItem = inventory_company_item($finishedItemId, $companyId);
        if (!$finishedItem) {
            flash('error', 'Select the finished product for this BOM.');
            redirect(inv_back_url());
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
            redirect(inv_back_url());
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
        redirect(inv_back_url());
    }

    if ($action === 'create_manufacturing_order') {
        if (!defined('PRODUCTION_VOUCHER')) {
            flash('error', 'Create production through the Production voucher.');
            redirect('admin/voucher-form.php?type=production');
        }
        require_permission('inventory', 'create');
        require_permission('accounting', 'post');
        $_SESSION['production_retry'][$companyId . ':' . $fiscalYearId] = array_intersect_key($_POST, array_flip([
            'order_no', 'voucher_date', 'finished_item_id', 'quantity', 'input_item_id', 'input_quantity',
            'cost_description', 'cost_amount', 'cost_mode', 'cost_expense', 'cost_credit', 'cost_centre', 'cost_reference', 'notes',
        ]));
        if (staff_accountant_forces_approval()) {
            flash('error', 'Production posting requires an authorised posting user.');
            redirect(inv_back_url());
        }
        if (inv_accounting_method($companyId) !== 'periodic') {
            flash('error', 'This production voucher requires periodic inventory accounting.');
            redirect(inv_back_url());
        }
        try {
            $productionCosts = production_cost_lines($_POST, $companyId);
            if (trim((string) ($_POST['notes'] ?? '')) === '') throw new RuntimeException('Enter a narration for production.');
            $productionDate = inventory_valid_date((string) ($_POST['voucher_date'] ?? ''));
            $productionFy = $productionDate ? fiscal_year_for_date($companyId, $productionDate) : null;
            if (!$productionFy || (int) $productionFy['id'] !== $fiscalYearId || (int) ($_POST['context_fiscal_year_id'] ?? 0) !== $fiscalYearId) {
                throw new RuntimeException('Select a production date in the active financial year. Refresh if the year changed.');
            }
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\/-]{0,59}$/', (string) ($_POST['order_no'] ?? ''))) throw new RuntimeException('Enter a valid production voucher number (up to 60 characters).');
            $_POST['production_mode'] = 'complete';
            $_POST['started_on'] = $_POST['completed_on'] = $productionDate;
            $_POST['labour_cost'] = 0;
            $_POST['overhead_absorbed'] = array_sum(array_column($productionCosts, 'amount'));
            $_POST['byproduct_value'] = $_POST['abnormal_waste_cost'] = 0;
            $_POST['bom_id'] = 0;
            $_POST['notes'] = json_encode(['narration' => trim((string) ($_POST['notes'] ?? '')), 'additional_costs' => $productionCosts], JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            flash('error', $exception->getMessage());
            redirect(inv_back_url());
        }
        if (!($inventoryProfile['show_manufacturing'] ?? false)) {
            flash('error', 'Manufacturing orders are available only for manufacturing companies.');
            redirect(inv_back_url());
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
        if (!$finishedItem || $quantity <= 0 || !is_finite($quantity) || $finishedItem['status'] !== 'active') {
            flash('error', 'Finished item and quantity are required.');
            redirect(inv_back_url());
        }

        // Validate the input lines up front: company ownership, no self-
        // consumption, and enough stock to issue the materials.
        $inputs = [];
        foreach ($inputItemIds as $index => $inputItemIdRaw) {
            $inputItemId = (int) $inputItemIdRaw;
            $inputQty = round((float) ($inputQuantities[$index] ?? 0), 3);
            $inputRate = round((float) ($inputRates[$index] ?? 0), 2);
            if ($inputItemId <= 0 && $inputQty == 0) {
                continue;
            }
            if ($inputItemId <= 0 || $inputQty <= 0 || !is_finite($inputQty)) {
                flash('error', 'Each material line requires an item and positive quantity.');
                redirect(inv_back_url());
            }
            if ($inputItemId === $finishedItemId) {
                flash('error', 'The finished item cannot also be one of its own input materials.');
                redirect(inv_back_url());
            }
            $inputItem = inventory_company_item($inputItemId, $companyId);
            if (!$inputItem || $inputItem['status'] !== 'active') {
                flash('error', 'Input item not found for this company.');
                redirect(inv_back_url());
            }
            if ($inputQty > (float) $inputItem['on_hand'] + 0.0005) {
                flash('error', 'Insufficient stock of ' . $inputItem['sku'] . ': ' . number_format((float) $inputItem['on_hand'], 3) . ' ' . $inputItem['unit'] . ' on hand, ' . number_format($inputQty, 3) . ' required.');
                redirect(inv_back_url());
            }
            if ($inputRate <= 0) {
                $inputRate = round((float) $inputItem['purchase_rate'], 2);
            }
            $inputs[] = ['item' => $inputItem, 'qty' => $inputQty, 'rate' => $inputRate];
        }
        if ($inputs === []) {
            flash('error', 'Add at least one input material line (item and quantity).');
            redirect(inv_back_url());
        }

        // Same fiscal-period gate as record_movement: material issues and the
        // finished receipt are stock movements too, and the stock-only path
        // (unmapped ledgers) never reaches the voucher engine's lock check.
        foreach (array_unique([$startedOn, $mode === 'start' ? $startedOn : $completedOn]) as $moDate) {
            $moFy = fiscal_year_for_date($companyId, $moDate);
            if (!$moFy) {
                flash('error', 'No fiscal year covers ' . $moDate . '. Open a fiscal year for that period before recording production.');
                redirect(inv_back_url());
            }
            $moBlocker = fiscal_year_posting_blocker($moFy, $moDate);
            if ($moBlocker !== null) {
                flash('error', $moBlocker);
                redirect(inv_back_url());
            }
        }

        try {
            db()->beginTransaction();
            // Lock in stable order, then recheck aggregated quantities inside the transaction.
            $requiredStock = [];
            foreach ($inputs as $input) {
                $id = (int) $input['item']['id'];
                $requiredStock[$id] = ($requiredStock[$id] ?? 0.0) + $input['qty'];
            }
            $lockIds = array_unique([...array_keys($requiredStock), $finishedItemId]);
            sort($lockIds);
            foreach ($lockIds as $id) {
                $lock = db()->prepare('SELECT id FROM inventory_items WHERE company_id = ? AND id = ? FOR UPDATE');
                $lock->execute([$companyId, $id]);
                if (!$lock->fetchColumn()) throw new RuntimeException('Production item no longer exists.');
                $freshItem = inventory_company_item($id, $companyId);
                if (($requiredStock[$id] ?? 0) > (float) $freshItem['on_hand'] + 0.0005) throw new RuntimeException('Insufficient stock for ' . $freshItem['sku']);
            }
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
            // Materials are issued at their ACTUAL cost-flow cost (FIFO / moving
            // average from the item's layers), never the typed/purchase rate —
            // the typed rate is only the layer seed default when a legacy item
            // has no layers yet. The actual unit cost is stamped back onto both
            // the movement row and the order input, so the GL credit, the
            // subledger draw-down, and a later completion-from-WIP all carry
            // the same rupees.
            $totalInputCost = 0.0;
            $inputCostByLedger = [];
            $unmappedInputs = [];
            foreach ($inputs as $inputIndex => $input) {
                $inputItem = $input['item'];
                inv_ensure_layers($companyId, $inputItem);
                $movementStmt->execute([
                    'company_id' => $companyId,
                    'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                    'item_id' => (int) $inputItem['id'],
                    'transaction_type' => 'consume',
                    'ref_no' => $orderNo,
                    'transaction_date' => $startedOn,
                    'qty_in' => 0,
                    'qty_out' => $input['qty'],
                    'rate' => $input['rate'],
                    'amount' => round($input['qty'] * $input['rate'], 2),
                    'notes' => 'Materials issued to ' . $orderNo,
                ]);
                $consumeTxnId = (int) db()->lastInsertId();
                $issueValue = inv_apply_movement($companyId, (int) $inputItem['id'], 0.0, $input['qty'], $input['rate'], $startedOn, (string) ($inputItem['valuation_method'] ?? 'weighted_average'), $consumeTxnId);
                $actualRate = $input['qty'] > INV_EPSILON ? round($issueValue / $input['qty'], 6) : 0.0;
                db()->prepare('UPDATE inventory_transactions SET rate = :rate, amount = :amount WHERE id = :id AND company_id = :cid')
                    ->execute(['rate' => $actualRate, 'amount' => $issueValue, 'id' => $consumeTxnId, 'cid' => $companyId]);
                $inputStmt->execute(['order_id' => $orderId, 'item_id' => (int) $inputItem['id'], 'quantity' => $input['qty'], 'rate' => $actualRate]);
                $inputs[$inputIndex]['issue_value'] = $issueValue;
                $totalInputCost = round($totalInputCost + $issueValue, 2);
                $inputLedgerId = inv_item_stock_ledger_id($companyId, $inputItem);
                if ($inputLedgerId <= 0) {
                    $unmappedInputs[] = (string) $inputItem['sku'];
                }
                $inputCostByLedger[$inputLedgerId] = round(($inputCostByLedger[$inputLedgerId] ?? 0.0) + $issueValue, 2);
            }

            if ($mode === 'start') {
                // Dr WIP / Cr each material's stock ledger at actual issue cost,
                // so the GL moves the value into Work in Progress the moment the
                // physical stock does (the flash used to CLAIM this happened).
                $wipVoucherId = 0;
                $wipRow = inv_resolve_mapping($companyId, 'wip', $finishedItemId, ($finishedItem['category'] ?? null) ?: null);
                $wipNote = '';
                if ($totalInputCost > 0 && $wipRow && $unmappedInputs === []) {
                    $wipVoucherId = (int) create_voucher_with_entries([
                        'company_id' => $companyId,
                        'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                        'voucher_no' => 'MFG-WIP-' . $orderNo,
                        'voucher_type' => 'journal',
                        'voucher_date' => $startedOn,
                        'source_type' => 'manufacturing_order_start',
                        'source_id' => $orderId,
                        'total_amount' => $totalInputCost,
                        'narration' => 'Production ' . $orderNo . ' started: materials issued to Work in Progress.',
                        'status' => 'posted',
                        'posted_by' => $userId,
                    ], array_merge(
                        [['ledger_id' => (int) $wipRow['id'], 'entry_type' => 'debit', 'amount' => $totalInputCost, 'memo' => 'Materials into WIP']],
                        array_map(static fn (int $lid, float $amt): array => ['ledger_id' => $lid, 'entry_type' => 'credit', 'amount' => $amt, 'memo' => 'Materials issued'],
                            array_keys($inputCostByLedger), array_values($inputCostByLedger))
                    ));
                    db()->prepare("UPDATE inventory_transactions SET voucher_id = :vid WHERE company_id = :cid AND ref_no = :ref AND transaction_type = 'consume'")
                        ->execute(['vid' => $wipVoucherId, 'cid' => $companyId, 'ref' => $orderNo]);
                } elseif ($totalInputCost > 0) {
                    $wipNote = !$wipRow
                        ? ' Stock issued WITHOUT a GL entry — map "Work in Progress" (Inventory → Ledger Mappings), then complete the order to post everything at completion.'
                        : ' Stock issued WITHOUT a GL entry — map Inventory Asset for ' . implode(', ', $unmappedInputs) . ' to post the WIP journal.';
                }
                db()->commit();
                log_activity('manufacturing_order', $orderId, 'started', 'Production started (WIP)' . ($wipVoucherId > 0 ? ' — voucher MFG-WIP-' . $orderNo . ' posted.' : '.'), $userId);
                flash('success', 'Production order ' . $orderNo . ' started: materials issued at actual cost ' . site_currency_symbol() . number_format($totalInputCost, 2) . '.'
                    . ($wipVoucherId > 0 ? ' WIP journal posted (Dr Work in Progress / Cr materials).' : $wipNote)
                    . ' Complete it from the orders table below.');
                redirect(inv_back_url());
            }

            // IAS 2 absorbed cost: materials (net of abnormal waste) + labour +
            // overhead - by-product value — with materials at their ACTUAL
            // layer cost. Abnormal waste never enters FG cost.
            $orderCost = mfg_order_cost($totalInputCost, $labourCost, $overheadAbsorbed, $byproductValue, $abnormalWaste, $quantity);
            $finishedRate = $orderCost['unit_cost'];
            // Backfill legacy layers BEFORE inserting the produce row — ensure
            // replays existing transactions, so running it after would replay
            // the new produce txn AND apply it again below (double stock).
            inv_ensure_layers($companyId, $finishedItem);
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
            $produceTxnId = (int) db()->lastInsertId();
            inv_apply_movement($companyId, $finishedItemId, $quantity, 0.0, $finishedRate, $completedOn, (string) ($finishedItem['valuation_method'] ?? 'weighted_average'), $produceTxnId);
            $voucherId = production_post_costs($companyId, $fiscalYearId, $orderId, $orderNo, $completedOn, $userId, $productionCosts);
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
            db()->commit();
            security_event('inventory_movement_posted', 'success', 'Manufacturing order #' . $orderId . ' created.', $companyId, $userId);
            log_activity('manufacturing_order', $orderId, 'completed', 'Manufacturing order completed.', $userId);
            unset($_SESSION['production_retry'][$companyId . ':' . $fiscalYearId]);
            flash('success', 'Production voucher ' . $orderNo . ' posted and stock updated.'
                . ($voucherId > 0 ? ' Additional costs posted to the accounts.' : ' No financial entries required.'));
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', (string) $exception->getCode() === '23000'
                ? 'Could not create manufacturing order: order number ' . $orderNo . ' already exists.'
                : 'Could not create manufacturing order: ' . $exception->getMessage());
        }
        redirect(inv_back_url());
    }

    if ($action === 'complete_manufacturing_order' || $action === 'cancel_manufacturing_order') {
        require_permission('inventory', 'edit');
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $orderStmt = db()->prepare("SELECT * FROM manufacturing_orders WHERE id = :id AND company_id = :company_id AND status IN ('draft', 'in_progress') LIMIT 1");
        $orderStmt->execute(['id' => $orderId, 'company_id' => $companyId]);
        $order = $orderStmt->fetch();
        if (!$order) {
            flash('error', 'Open production order not found (it may already be completed or cancelled).');
            redirect(inv_back_url());
        }
        $orderNo = (string) $order['order_no'];
        // Full item rows so the stock ledger resolves through the mapping chain
        // (item -> category -> global -> legacy ledger_id), not the raw column.
        $inputRowsStmt = db()->prepare('SELECT moi.item_id, moi.quantity, moi.rate, i.id, i.ledger_id, i.sku, i.name, i.item_type, i.category, i.valuation_method
            FROM manufacturing_order_inputs moi INNER JOIN inventory_items i ON i.id = moi.item_id WHERE moi.manufacturing_order_id = :id');
        $inputRowsStmt->execute(['id' => $orderId]);
        $inputRows = $inputRowsStmt->fetchAll();
        $today = date('Y-m-d');
        // Completion/cancellation moves stock today — enforce the period lock
        // exactly like record_movement (the stock-only path bypasses the
        // voucher engine's own check).
        $moFy = fiscal_year_for_date($companyId, $today);
        $moBlocker = $moFy ? fiscal_year_posting_blocker($moFy, $today) : ('No fiscal year covers ' . $today . '. Open a fiscal year for this period first.');
        if ($moBlocker !== null) {
            flash('error', $moBlocker);
            redirect(inv_back_url());
        }
        // The WIP journal posted when this order STARTED (if any): completion
        // must credit Work in Progress — crediting the material ledgers again
        // would double-relieve them; cancellation must reverse it.
        $startVoucherStmt = db()->prepare("SELECT * FROM vouchers WHERE source_type = 'manufacturing_order_start' AND source_id = :oid AND company_id = :cid LIMIT 1");
        $startVoucherStmt->execute(['oid' => $orderId, 'cid' => $companyId]);
        $startVoucher = $startVoucherStmt->fetch() ?: null;

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
                // Reverse the start-time WIP journal: deleting it (entries
                // cascade) returns the value from WIP to the material ledgers,
                // matching the physical return above. Guards still apply.
                if ($startVoucher) {
                    $svBlocker = voucher_mutation_blocker((array) $startVoucher, ['manufacturing_order_start']);
                    if ($svBlocker !== null) {
                        throw new RuntimeException('The WIP journal of this order cannot be reversed: ' . $svBlocker);
                    }
                    db()->prepare('DELETE FROM vouchers WHERE id = :id AND company_id = :cid')
                        ->execute(['id' => (int) $startVoucher['id'], 'cid' => $companyId]);
                }
                foreach ($inputRows as $inputRow) {
                    inv_rebuild_item($companyId, (int) $inputRow['item_id']);
                }
                db()->commit();
                security_event('inventory_movement_reversed', 'success', 'Manufacturing order #' . $orderId . ' cancelled.', $companyId, $userId);
                log_activity('manufacturing_order', $orderId, 'cancelled', 'Production order cancelled, materials returned.', $userId);
                flash('success', 'Order ' . $orderNo . ' cancelled and issued materials returned to stock.');
                redirect(inv_back_url());
            }

            $quantity = (float) $order['quantity'];
            // Input rates were stamped with the ACTUAL issue cost when the
            // materials left stock at start, so qty x rate here is the true
            // cost-flow value sitting in WIP (legacy pre-fix orders fall back
            // to their typed rate — the best record that exists for them).
            $totalInputCost = 0.0;
            $inputCostByLedger = [];
            foreach ($inputRows as $inputRow) {
                $amount = round((float) $inputRow['quantity'] * (float) $inputRow['rate'], 2);
                $totalInputCost = round($totalInputCost + $amount, 2);
                $ledgerId = inv_item_stock_ledger_id($companyId, (array) $inputRow);
                $inputCostByLedger[$ledgerId] = round(($inputCostByLedger[$ledgerId] ?? 0.0) + $amount, 2);
            }
            // Order was started with a WIP journal: the whole material value now
            // sits in the ledger that journal DEBITED, so completion credits
            // that ledger (not the materials again) — and for EXACTLY the amount
            // the journal put there. qty x 2dp-stored-rate can drift by paisa
            // (e.g. 15 x 113.33 = 1,699.95 vs the true 1,700.00), which would
            // strand the difference in WIP forever.
            if ($startVoucher && $totalInputCost > 0) {
                $wipLegStmt = db()->prepare("SELECT ledger_id FROM voucher_entries WHERE voucher_id = :vid AND entry_type = 'debit' LIMIT 1");
                $wipLegStmt->execute(['vid' => (int) $startVoucher['id']]);
                $wipLedgerId = (int) ($wipLegStmt->fetchColumn() ?: 0);
                if ($wipLedgerId > 0) {
                    $totalInputCost = round((float) $startVoucher['total_amount'], 2) ?: $totalInputCost;
                    $inputCostByLedger = [$wipLedgerId => $totalInputCost];
                }
            }
            // Absorb the conversion costs stored on the order (IAS 2).
            $ordLabour = (float) ($order['labour_cost'] ?? 0);
            $ordOverhead = (float) ($order['overhead_absorbed'] ?? 0);
            $ordByproduct = (float) ($order['byproduct_value'] ?? 0);
            $ordAbnormal = (float) ($order['abnormal_waste_cost'] ?? 0);
            $orderCost = mfg_order_cost($totalInputCost, $ordLabour, $ordOverhead, $ordByproduct, $ordAbnormal, $quantity);
            $finishedRate = $orderCost['unit_cost'];
            // Backfill legacy layers BEFORE inserting the produce row — ensure
            // replays existing transactions, so running it after would replay
            // the new produce txn AND apply it again below (double stock).
            $finishedItemRow = inventory_company_item((int) $order['finished_item_id'], $companyId) ?: [];
            inv_ensure_layers($companyId, $finishedItemRow + ['id' => (int) $order['finished_item_id']]);
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
            $produceTxnId = (int) db()->lastInsertId();
            db()->prepare("UPDATE manufacturing_orders SET status = 'completed', completed_on = :done WHERE id = :id")
                ->execute(['done' => $today, 'id' => $orderId]);
            inv_apply_movement($companyId, (int) $order['finished_item_id'], $quantity, 0.0, $finishedRate, $today, (string) ($finishedItemRow['valuation_method'] ?? 'weighted_average'), $produceTxnId);
            $voucherId = inventory_post_production_voucher($companyId, $fiscalYearId, $orderId, $orderNo, $today, inv_item_stock_ledger_id($companyId, $finishedItemRow + ['id' => (int) $order['finished_item_id']]), $inputCostByLedger, $userId, [
                'labour' => $ordLabour, 'overhead' => $ordOverhead,
                'byproduct' => $ordByproduct, 'abnormal' => $ordAbnormal,
            ]);
            if ($voucherId > 0) {
                db()->prepare("UPDATE inventory_transactions SET voucher_id = :vid WHERE company_id = :cid AND ref_no = :ref AND transaction_type = 'produce'")
                    ->execute(['vid' => $voucherId, 'cid' => $companyId, 'ref' => $orderNo]);
            }
            db()->commit();
            security_event('inventory_movement_posted', 'success', 'Manufacturing order #' . $orderId . ' completed.', $companyId, $userId);
            log_activity('manufacturing_order', $orderId, 'completed', 'Production completed from WIP.', $userId);
            flash('success', 'Order ' . $orderNo . ' completed: ' . number_format($quantity, 3) . ' finished goods received into stock at ' . number_format($finishedRate, 2) . ' each.'
                . ($voucherId > 0 ? ' Journal voucher MFG-' . $orderNo . ' posted.' : ' Stock updated WITHOUT a GL entry — map the finished/material item ledgers (edit the items) to post the production journal.'));
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not update order ' . $orderNo . ': ' . $exception->getMessage());
        }
        redirect(inv_back_url());
    }
}

// History pages need only their paged registers, not item balances or cost-layer replay.
if (defined('INVENTORY_PAGE') && in_array(INVENTORY_PAGE, ['purchase', 'sale', 'transfer'], true)) {
    $invTask = INVENTORY_PAGE;
    require_permission($invTask === 'transfer' ? 'inventory' : 'accounting', 'view');
    $pageTitle = $inventoryPage['title'];
    $pageHero = false;
    $bodyClass = 'admin-layout accounting-module-page';
    include __DIR__ . '/../../app/views/partials/admin_header.php';
    echo '<section class="mbw-card inventory-history-card">';
    if ($invTask === 'transfer') require __DIR__ . '/../../app/views/partials/stock_transfer_history.php';
    else require __DIR__ . '/../../app/views/partials/inventory_voucher_history.php';
    echo '</section>';
    include __DIR__ . '/../../app/views/partials/admin_footer.php';
    return;
}

// Voucher selectors do not display stock balances; validate availability only on posting.
if (defined('STOCK_TRANSFER_VOUCHER') || defined('PRODUCTION_VOUCHER')) {
    require_permission('inventory', 'create');
    if (defined('PRODUCTION_VOUCHER')) {
        require_permission('accounting', 'post');
        if (empty($inventoryProfile['show_manufacturing'])) {
            flash('error', 'Production is available for manufacturing companies.');
            redirect('admin/items.php');
        }
    }
    $selectorItems = db()->prepare("SELECT id, sku, name, unit, status FROM inventory_items WHERE company_id = ? AND status = 'active' ORDER BY sku");
    $selectorItems->execute([$companyId]);
    $items = $selectorItems->fetchAll(PDO::FETCH_ASSOC);
    $warehouses = inv_company_warehouses($companyId);
    $ledgers = [];
    if (defined('PRODUCTION_VOUCHER')) {
        $selectorLedgers = db()->prepare("SELECT id, code, name, type FROM ledgers WHERE company_id = ? AND status = 'active' AND type IN ('expense','asset','liability') ORDER BY code");
        $selectorLedgers->execute([$companyId]);
        $ledgers = $selectorLedgers->fetchAll(PDO::FETCH_ASSOC);
    }
    require __DIR__ . '/../../app/views/vouchers/' . (defined('PRODUCTION_VOUCHER') ? 'production.php' : 'stock_transfer.php');
    return;
}

$editItem = null;
$editId = (int) ($_GET['edit_id'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM inventory_items WHERE id = :id AND company_id = :company_id LIMIT 1');
    $stmt->execute(['id' => $editId, 'company_id' => $companyId]);
    $editItem = $stmt->fetch() ?: null;
}

$ledgerStmt = db()->prepare("SELECT id, code, name, type FROM ledgers WHERE company_id = :company_id AND status = 'active' ORDER BY code ASC");
$ledgerStmt->execute(['company_id' => $companyId]);
$ledgers = $ledgerStmt->fetchAll();

// Active warehouses (for select dropdowns) and the full list (for the
// warehouse management table, which also needs to show inactive ones).
$warehouses = inv_company_warehouses($companyId);
if (defined('ITEMS_WORKSPACE')) {
    $pageTitle = 'Items';
    $pageHero = false;
    $invTaskField = '';
    include __DIR__ . '/../../app/views/partials/items_workspace.php';
    return;
}
$allWarehousesStmt = db()->prepare('SELECT * FROM warehouses WHERE company_id = :company_id ORDER BY name ASC');
$allWarehousesStmt->execute(['company_id' => $companyId]);
$allWarehouses = $allWarehousesStmt->fetchAll();

$itemStmt = db()->prepare('
    SELECT i.*, l.code AS ledger_code,
           i.opening_qty + COALESCE(SUM(t.qty_in - t.qty_out), 0) AS on_hand,
           COALESCE(SUM(t.amount), 0) AS movement_value,
           ' . (table_exists('jewellery_item_profiles') ? 'jwp.jewellery_type' : 'NULL') . ' AS jewellery_type
    FROM inventory_items i
    LEFT JOIN ledgers l ON l.id = i.ledger_id
    LEFT JOIN inventory_transactions t ON t.item_id = i.id
    ' . (table_exists('jewellery_item_profiles')
        // The item master is SHARED with the Jewellery module, so a gold chain
        // is an inventory item like any other. Flag them here rather than hide
        // them: their weight/purity detail is edited in Jewellery, and the list
        // should say so instead of silently offering a half-usable edit form.
        ? 'LEFT JOIN jewellery_item_profiles jwp ON jwp.inventory_item_id = i.id' : '') . '
    WHERE i.company_id = :company_id
    GROUP BY i.id
    ORDER BY i.status ASC, i.sku ASC
');
$itemStmt->execute(['company_id' => $companyId]);
$items = $itemStmt->fetchAll();

// Discard retry data from the retired purchase grid without changing saved records.
unset($_SESSION['inv_purchase_grid'], $_SESSION['inv_purchase_bills'], $_SESSION['inv_purchase_grid_errors']);

$pageMovementTypes = defined('INVENTORY_PAGE') ? (match (INVENTORY_PAGE) {
    'opening' => ['opening'],
    'adjust' => ['adjustment', 'write_off', 'damage', 'expiry'],
    'transfer' => ['warehouse_transfer', 'departmental_transfer'],
    default => [],
}) : null;
$movementParams = ['company_id' => $companyId];
$movementFilter = '';
if ($pageMovementTypes !== null) {
    $placeholders = [];
    foreach ($pageMovementTypes as $index => $type) {
        $placeholders[] = ':movement_type_' . $index;
        $movementParams['movement_type_' . $index] = $type;
    }
    $movementFilter = $placeholders === [] ? ' AND 1 = 0' : ' AND t.transaction_type IN (' . implode(',', $placeholders) . ')';
}
$movementStmt = db()->prepare('
    SELECT t.*, i.sku, i.name AS item_name, i.unit
    FROM inventory_transactions t
    INNER JOIN inventory_items i ON i.id = t.item_id
    WHERE t.company_id = :company_id' . $movementFilter . '
    ORDER BY t.transaction_date DESC, t.id DESC
    LIMIT 80
');
$movementStmt->execute($movementParams);
$movements = $movementStmt->fetchAll();

$orderCountStmt = db()->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(status IN ('draft','in_progress')),0) AS open_count FROM manufacturing_orders WHERE company_id = ? AND fiscal_year_id = ?");
$orderCountStmt->execute([$companyId, $fiscalYearId]);
$orderCounts = $orderCountStmt->fetch(PDO::FETCH_ASSOC);
$orderPages = max(1, (int) ceil((int)$orderCounts['total'] / 50));
$orderPage = max(1, min($orderPages, (int)($_GET['page'] ?? 1)));
$orderStmt = db()->prepare('
    SELECT mo.*, i.sku, i.name AS finished_item_name,
           (SELECT COUNT(*) FROM manufacturing_order_inputs mi WHERE mi.manufacturing_order_id = mo.id) AS input_lines,
           (SELECT COALESCE(SUM(mi.quantity * mi.rate), 0) FROM manufacturing_order_inputs mi WHERE mi.manufacturing_order_id = mo.id) AS input_cost
    FROM manufacturing_orders mo
    INNER JOIN inventory_items i ON i.id = mo.finished_item_id
    WHERE mo.company_id = :company_id AND mo.fiscal_year_id = :fiscal_year_id
    ORDER BY FIELD(mo.status, \'in_progress\', \'draft\', \'completed\', \'cancelled\'), mo.created_at DESC
    LIMIT 50 OFFSET ' . (($orderPage - 1) * 50));
$orderStmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);
$manufacturingOrders = $orderStmt->fetchAll();
$openOrderCount = (int)$orderCounts['open_count'];

// Real IAS 2 valuation from the perpetual cost layers (backfills legacy items).
$inventoryValuation = !defined('INVENTORY_PAGE') || INVENTORY_PAGE === 'valuation'
    ? inv_company_valuation($companyId, $items)
    : ['cost' => 0.0, 'lower' => 0.0, 'write_down' => 0.0];
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
$invView = (string) ($_GET['view'] ?? 'inventory');

// 'mapping' is gone on purpose: ledgers are chosen per item on the item form
// and each item's "This item posts to" panel — same arrangement as fixed
// assets. Old global/category rows keep working as inherited defaults.
$allowedViews = ['inventory', 'valuation'];
if ($inventoryProfile['show_manufacturing'] ?? false) {
    $allowedViews[] = 'manufacturing';
}
if (!in_array($invView, $allowedViews, true)) {
    $invView = 'inventory';
}

// ---------------------------------------------------------------------------
// One task per page
// ---------------------------------------------------------------------------
// These used to be six forms stacked on one page with script showing one at a
// time. Every visit paid for all of them: the purchase grid with its bills and
// popups, the warehouse list, the sale, adjustment and transfer forms, and the
// priced item list — to look at one.
//
// Each is its own page now. The tabs are links, and only the chosen task is
// built, both its markup and the reads behind it.
$invTaskSections = $invView === 'inventory'
    ? [
        'item' => ['opening-import', 'create-item', 'item-ledgers', 'item-stock-summary'],
        'opening' => ['opening-import'],
        'warehouses' => ['warehouses'],
        'purchase' => ['movement-purchase-entries'],
        'sale' => ['movement-sale'],
        'adjust' => ['movement-adjust'],
        'transfer' => ['movement-transfer'],
    ]
    : [
        'manufacturing' => ['manufacturing'],
        'bom' => ['bom'],
    ];
$invTask = (string) ($_GET['task'] ?? '');
if (!isset($invTaskSections[$invTask])) {
    $invTask = (string) array_key_first($invTaskSections);
}
// Editing an item, or coming back from a purchase that would not post, has to
// land on the task that owns the form — otherwise the work is on a page nobody
// is looking at.
if (($editItem ?? null) !== null || ($moveItemId ?? 0) > 0) {
    $invTask = ($moveItemId ?? 0) > 0 && ($_GET['task'] ?? '') === '' ? $invTask : $invTask;
}
$invShownSections = array_flip($invTaskSections[$invTask]);
/** Is this section part of the task being looked at? */
$invShows = static function (string $sectionId) use ($invShownSections): bool {
    return isset($invShownSections[$sectionId]);
};
// Dropped into every form on the page, so a save comes back to the page it was
// made on rather than to whichever task happens to be first.
$invTaskField = '<input type="hidden" name="task" value="' . e($invTask) . '">'
    . '<input type="hidden" name="view" value="' . e($invView) . '">';
$lowOnly = (string) ($_GET['low'] ?? '') === '1';
$isLowStock = static fn (array $item): bool => (float) $item['reorder_level'] > 0 && (float) $item['on_hand'] <= (float) $item['reorder_level'];

// Finding one item among a hundred and sixty three meant reading every page.
// Filtered in PHP rather than SQL because $items is already loaded and shared
// with the movement forms and the BOM pickers on this page; querying again to
// narrow a list already in memory would be a second trip for no gain.
// SKU, name and category are picked from what the company actually has, not
// typed. One box searching all three could not tell the reader WHICH field
// matched, and offered no way to see what values exist -- and a category is a
// short closed list somebody either knows or does not.
$itemQuery = trim((string) ($_GET['q'] ?? ''));
$itemSkuFilter = trim((string) ($_GET['sku'] ?? ''));
$itemNameFilter = trim((string) ($_GET['item_name'] ?? ''));
$itemCategoryFilter = trim((string) ($_GET['category'] ?? ''));
$itemTypeFilter = (string) ($_GET['item_type'] ?? '');
$itemStatusFilter = (string) ($_GET['item_status'] ?? '');
$itemStockFilter = (string) ($_GET['stock'] ?? '');

// The choices, from this company's own items, each listed once and in order.
$itemSkuOptions = [];
$itemNameOptions = [];
$itemCategoryOptions = [];
foreach ($items as $optionRow) {
    $itemSkuOptions[(string) $optionRow['sku']] = true;
    $itemNameOptions[(string) $optionRow['name']] = true;
    if (trim((string) ($optionRow['category'] ?? '')) !== '') {
        $itemCategoryOptions[trim((string) $optionRow['category'])] = true;
    }
}
$itemSkuOptions = array_keys($itemSkuOptions);
$itemNameOptions = array_keys($itemNameOptions);
$itemCategoryOptions = array_keys($itemCategoryOptions);
sort($itemSkuOptions, SORT_NATURAL | SORT_FLAG_CASE);
sort($itemNameOptions, SORT_NATURAL | SORT_FLAG_CASE);
sort($itemCategoryOptions, SORT_NATURAL | SORT_FLAG_CASE);

$visibleItems = array_values(array_filter($items, static function (array $item) use (
    $lowOnly, $isLowStock, $itemQuery, $itemSkuFilter, $itemNameFilter, $itemCategoryFilter,
    $itemTypeFilter, $itemStatusFilter, $itemStockFilter
): bool {
    if ($lowOnly && !$isLowStock($item)) {
        return false;
    }
    // Chosen from a list, so these match exactly. A dropdown that then matched
    // loosely would return rows the reader did not pick.
    if ($itemSkuFilter !== '' && (string) $item['sku'] !== $itemSkuFilter) {
        return false;
    }
    if ($itemNameFilter !== '' && (string) $item['name'] !== $itemNameFilter) {
        return false;
    }
    if ($itemCategoryFilter !== '' && trim((string) ($item['category'] ?? '')) !== $itemCategoryFilter) {
        return false;
    }
    // The free-text box stays as well, for the half-remembered word that is not
    // worth scrolling a list of a hundred to find.
    if ($itemQuery !== '') {
        $haystack = mb_strtolower((string) $item['sku'] . ' ' . (string) $item['name']
            . ' ' . (string) ($item['category'] ?? ''));
        if (!str_contains($haystack, mb_strtolower($itemQuery))) {
            return false;
        }
    }
    if ($itemTypeFilter !== '' && (string) $item['item_type'] !== $itemTypeFilter) {
        return false;
    }
    if ($itemStatusFilter !== '' && (string) $item['status'] !== $itemStatusFilter) {
        return false;
    }
    if ($itemStockFilter === 'in' && (float) $item['on_hand'] <= 0) {
        return false;
    }
    if ($itemStockFilter === 'out' && (float) $item['on_hand'] > 0) {
        return false;
    }

    return true;
}));
$itemFilterOn = $lowOnly || $itemQuery !== '' || $itemSkuFilter !== '' || $itemNameFilter !== ''
    || $itemCategoryFilter !== '' || $itemTypeFilter !== '' || $itemStatusFilter !== ''
    || $itemStockFilter !== '';
// A page at a time, for two reasons rather than one. The obvious one is the
// markup: a few thousand items is megabytes of table. The costly one is that
// each row asks inv_item_valuation() what it is worth, and that reads the cost
// layers and the latest NRV assessment for the item — several queries apiece,
// thousands of times, to draw a screen showing fifty.
$invPerPage = (int) ($_GET['per_page'] ?? 50);
if (!in_array($invPerPage, [25, 50, 100, 200], true)) {
    $invPerPage = 50;
}
$invTotalItems = count($visibleItems);
$invPageCount = max(1, (int) ceil($invTotalItems / $invPerPage));
$invPage = max(1, min($invPageCount, (int) ($_GET['page'] ?? 1)));
$pagedItems = array_slice($visibleItems, ($invPage - 1) * $invPerPage, $invPerPage);
// Whatever else is in the URL travels with the pager, so paging a low-stock
// list does not quietly show everything again.
$invPageUrl = static function (array $overrides) use ($lowOnly, $invPerPage, $itemQuery,
    $itemSkuFilter, $itemNameFilter, $itemCategoryFilter,
    $itemTypeFilter, $itemStatusFilter, $itemStockFilter): string {
    // Every filter travels with the pager. Page two of a search that quietly
    // showed everything again would be worse than no pager at all.
    $query = array_filter([
        'low' => $lowOnly ? '1' : '',
        'per_page' => (string) $invPerPage,
        'q' => $itemQuery,
        'sku' => $itemSkuFilter,
        'item_name' => $itemNameFilter,
        'category' => $itemCategoryFilter,
        'item_type' => $itemTypeFilter,
        'item_status' => $itemStatusFilter,
        'stock' => $itemStockFilter,
    ], static fn ($v): bool => (string) $v !== '');

    return url('admin/accounting-inventory.php?' . http_build_query(array_merge($query, $overrides)))
        . '#item-stock-summary';
};
$moveItemId = (int) ($_GET['move_item'] ?? 0);
$pageTitle = $invView === 'manufacturing' ? 'Manufacturing' : 'Inventory';
$pageSubtitle = $inventoryProfile['show_manufacturing']
    ? 'Item master, stock movements, valuation, and production orders'
    : 'Item master, stock movements, and valuation';
$bodyClass = 'admin-layout accounting-module-page';

// Opening-stock import batch being previewed, and the item list its rows pick
// from. Same engine as the Jewellery screen, module = inventory.
$invImportBatch = opening_import_batch($companyId, (int) ($_GET['import'] ?? 0))
    ?: opening_import_latest_staged($companyId, 'inventory');
$invImportRows = $invImportBatch ? opening_import_rows($companyId, (int) $invImportBatch['id']) : [];
$invImportItems = [];
if ($invImportBatch) {
    $invItemStmt = db()->prepare('SELECT id, sku, name FROM inventory_items WHERE company_id = :cid ORDER BY sku ASC');
    $invItemStmt->execute(['cid' => $companyId]);
    $invImportItems = $invItemStmt->fetchAll(PDO::FETCH_ASSOC);
}
if (($_GET['opening_template'] ?? '') !== '') {
    require_once __DIR__ . '/../../app/export_engine.php';
    $tplRows = opening_import_template_rows(false);
    if ((string) $_GET['opening_template'] === 'csv') { export_csv('opening-stock-template.csv', $tplRows); }
    export_xlsx('opening-stock-template.xlsx', $tplRows, 'Opening Stock');
}

// The stock summary, as a file. WHAT IS ON SCREEN GOES IN THE FILE: the same
// filters, the same order, priced the same way. An export that quietly ignored
// the search box would be a different report wearing the same name, and the
// person who filtered to twelve items and got a hundred and sixty three would
// have no way of telling which one was wrong.
if (($_GET['export'] ?? '') !== '' && $invShows('item-stock-summary')) {
    require_permission('inventory', 'export');
    require_once __DIR__ . '/../../app/export_engine.php';
    $exportFormat = in_array((string) $_GET['export'], ['csv', 'xlsx', 'print'], true)
        ? (string) $_GET['export'] : 'csv';
    // Priced in sweeps over the whole filtered set, the same way the table
    // prices its page — not four statements an item.
    $exportPriced = inv_item_valuations($companyId, $visibleItems);
    $exportRows = [['SKU', 'Name', 'Type', 'Category', 'Method', 'Unit', 'On hand', 'Unit cost',
        'Value at cost', 'Reorder level', 'Status']];
    foreach ($visibleItems as $exportItem) {
        $valuation = $exportPriced[(int) $exportItem['id']] ?? ['unit_cost' => 0.0, 'cost_value' => 0.0];
        $exportRows[] = [
            (string) $exportItem['sku'],
            (string) $exportItem['name'],
            str_replace('_', ' ', (string) $exportItem['item_type']),
            (string) ($exportItem['category'] ?? ''),
            str_replace('_', ' ', (string) ($exportItem['valuation_method'] ?? 'weighted_average')),
            (string) $exportItem['unit'],
            (float) $exportItem['on_hand'],
            (float) $valuation['unit_cost'],
            (float) $valuation['cost_value'],
            (float) $exportItem['reorder_level'],
            ucfirst((string) $exportItem['status']),
        ];
    }
    // Footed before it goes out: a stock list without its total value is a list
    // somebody adds up by hand and then disagrees with the person beside them.
    $exportRows = export_totals_row($exportRows);
    $exportFilters = array_filter([
        'Search' => $itemQuery,
        'SKU' => $itemSkuFilter,
        'Name' => $itemNameFilter,
        'Category' => $itemCategoryFilter,
        'Type' => $itemTypeFilter !== '' ? str_replace('_', ' ', $itemTypeFilter) : '',
        'Status' => $itemStatusFilter !== '' ? ucfirst($itemStatusFilter) : '',
        'Stock' => $itemStockFilter === 'in' ? 'In stock only' : ($itemStockFilter === 'out' ? 'Nil stock only' : ''),
        'Low stock' => $lowOnly ? 'At or below reorder level' : '',
    ], static fn (string $v): bool => $v !== '');
    export_dispatch($exportFormat, 'item-stock-summary-' . date('Y-m-d'), $exportRows,
        'Item Stock Summary', [
            'Company' => (string) ($company['name'] ?? ''),
            'Items' => (string) count($visibleItems),
        ] + ($exportFilters === [] ? ['Filter' => 'All items'] : $exportFilters));
}

if (defined('INVENTORY_PAGE')) $pageTitle = $inventoryPage['title'];
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>

<?php if (!defined('INVENTORY_PAGE')): ?>
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
    <a class="mbw-kpi" href="<?= e(url('admin/inventory-valuation.php')) ?>" title="Lower of cost and net realisable value (IAS 2)">
        <div>
            <span class="mbw-kpi-label">Lower of Cost &amp; NRV</span>
            <div class="mbw-kpi-value"><?= e(site_currency_symbol()) ?><?= e(number_format($stockLowerOfCostNrv, 2)) ?></div>
            <span class="mbw-kpi-delta"><span class="mbw-kpi-vs">IAS 2 measurement</span></span>
        </div>
        <span class="mbw-chip tone-teal"><?= icon('reports') ?></span>
    </a>
    <a class="mbw-kpi" href="<?= e(url('admin/inventory-valuation.php')) ?>" title="Cumulative NRV write-down (cost above NRV)">
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
        <a class="mbw-kpi" href="<?= e(url('admin/manufacturing-orders.php')) ?>" title="Open the manufacturing workspace">
            <div>
                <span class="mbw-kpi-label">Open Production Orders</span>
                <div class="mbw-kpi-value"><?= e((string) $openOrderCount) ?></div>
                <span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= $openOrderCount > 0 ? 'In progress (WIP)' : 'None open' ?></span></span>
            </div>
            <span class="mbw-chip tone-purple"><?= icon('layers') ?></span>
        </a>
    <?php endif; ?>
</section>

<?php
endif;
$sampleCountStmt = db()->prepare("SELECT COUNT(*) FROM inventory_items WHERE company_id = :cid AND sku LIKE 'SMP-%'");
$sampleCountStmt->execute(['cid' => $companyId]);
$sampleCount = (int) $sampleCountStmt->fetchColumn();
if (!defined('INVENTORY_PAGE') && $sampleCount > 0 && (string) (current_user()['role'] ?? '') === 'admin' && user_can_do('accounting', 'post')): ?>
<div class="notice" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:10px">
    <span><strong><?= $sampleCount ?> sample item(s)</strong> (SMP-…) from the demo seed are mixed into your inventory and its reports.</span>
    <form method="post" style="margin:0" data-confirm="Remove ALL sample inventory data (SMP-… items, their movements, cost layers, stock vouchers, and sample manufacturing orders)? Real data is not touched. This cannot be undone.">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
        <input type="hidden" name="action" value="purge_sample_inventory">
        <button type="submit" class="button secondary" style="color:var(--mbw-red, #a33)">Remove sample data</button>
    </form>
</div>
<?php endif; ?>

<?php if ($invView === 'valuation'): ?>
    <?php
    // Per-item IAS 2 valuation: cost from layers vs lower of cost and NRV.
    //
    // A PAGE at a time, for the same two reasons the stock list above already
    // pages — and this view had been missed. inv_item_valuation() reads the
    // cost layers and the item's NRV assessment, three statements apiece, and
    // this mapped it over EVERY item rather than the ones on screen: about
    // 6,700 queries and a table of two thousand rows on a shop this size, to
    // show what a person reads fifty lines of. The totals in the foot are
    // computed elsewhere, over the whole list, so they still speak for it all.
    $valPerPage = (int) ($_GET['per_page'] ?? 50);
    if (!in_array($valPerPage, [25, 50, 100, 200], true)) {
        $valPerPage = 50;
    }
    $valPageCount = max(1, (int) ceil(count($items) / $valPerPage));
    $valPage = max(1, min($valPageCount, (int) ($_GET['page'] ?? 1)));
    // Priced in three sweeps for the whole page rather than four statements per
    // row — same arithmetic, see inv_item_valuations().
    $valPageItems = array_slice($items, ($valPage - 1) * $valPerPage, $valPerPage);
    $valPriced = inv_item_valuations($companyId, $valPageItems);
    $valuationRows = array_map(static function (array $item) use ($valPriced): array {
        return $item + ['val' => $valPriced[(int) $item['id']] ?? []];
    }, $valPageItems);
    // Its own link builder rather than the stock list's: that one carries no
    // view and anchors to the table above, so paging from here would have
    // landed the reader on a different screen than the one they were reading.
    $valPageUrl = static function (array $overrides) use ($valPerPage): string {
        return url('admin/inventory-valuation.php?' . http_build_query(array_merge([
            'per_page' => (string) $valPerPage,
        ], $overrides))) . '#valuation-nrv';
    };
    ?>

    <?php
    // What periodic books post to, and whether those accounts are mapped yet.
    // Checked BEFORE the switch rather than at the first refused purchase.
    $invMethod = inv_accounting_method($companyId);
    $methodMapped = [];
    foreach (['purchases' => 'Purchases', 'inventory_change' => 'Change in Inventory'] as $methodPurpose => $methodLabel) {
        if (!inv_resolve_mapping($companyId, $methodPurpose)) {
            $methodMapped[] = $methodLabel;
        }
    }
    ?>
    <section class="mbw-card" id="inventory-method" data-collapsible aria-label="Inventory accounting system">
        <div class="mbw-card-head">
            <h2>Inventory accounting system</h2>
            <div class="mbw-card-tools"><span class="mbw-pill <?= $invMethod === 'periodic' ? 'tone-amber' : 'tone-green' ?>"><?= $invMethod === 'periodic' ? 'Periodic' : 'Perpetual' ?></span></div>
        </div>
        <form method="post" class="workspace-form-grid" style="grid-template-columns:1fr;gap:14px">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_inventory_method">
            <label style="display:flex;gap:12px;align-items:flex-start;padding:12px;border:1px solid var(--mbw-border);border-radius:10px;cursor:pointer">
                <input type="radio" name="inventory_accounting" value="perpetual" <?= $invMethod === 'perpetual' ? 'checked' : '' ?> style="margin-top:4px">
                <span>
                    <strong>Perpetual</strong><br>
                    <small style="color:var(--mbw-muted)">Purchases debit Inventory. Each sale posts its own cost of sales.</small>
                </span>
            </label>
            <label style="display:flex;gap:12px;align-items:flex-start;padding:12px;border:1px solid var(--mbw-border);border-radius:10px;cursor:pointer">
                <input type="radio" name="inventory_accounting" value="periodic" <?= $invMethod === 'periodic' ? 'checked' : '' ?> style="margin-top:4px">
                <span>
                    <strong>Periodic</strong><br>
                    <small style="color:var(--mbw-muted)">Purchases debit Purchases. Cost of sales is derived at period end.</small>
                </span>
            </label>
            <?php if ($methodMapped !== []): ?>
                <div class="notice">Unmapped for periodic: <?= e(implode(', ', $methodMapped)) ?>.</div>
            <?php endif; ?>
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
                <small style="color:var(--mbw-muted)">Applies to future postings only.</small>
                <button type="submit" class="button" data-confirm="Change the inventory accounting system for this company?">Save</button>
            </div>
        </form>
    </section>
    <section class="mbw-card" id="valuation-nrv" data-collapsible aria-label="Valuation and NRV">
        <div class="mbw-card-head">
            <h2>Valuation &amp; NRV (IAS 2)</h2>
            <div class="mbw-card-tools"><span style="color:var(--mbw-muted);font-size:12.5px">Cost from perpetual layers. NRV uses the item assessment, or its sales rate.</span></div>
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
                            <td>
                                <?= e($row['name']) ?>
                                <?php if (($row['jewellery_type'] ?? null) !== null): ?>
                                    <?php // Shared item master: the weight and purity detail lives in Jewellery. ?>
                                    <a class="mbw-pill tone-amber" style="margin-left:6px;text-decoration:none"
                                       href="<?= e(url('admin/jewellery.php?view=items&edit=' . (int) $row['id'])) ?>"
                                       title="This item is tracked by weight and purity in the Jewellery module">
                                        <?= e(ucfirst((string) $row['jewellery_type'])) ?> →
                                    </a>
                                <?php endif; ?>
                            </td>
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
        <?php if ($valPageCount > 1): ?>
            <nav class="actions" style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap" aria-label="Valuation pages">
                <?php if ($valPage > 1): ?><a class="button secondary" href="<?= e($valPageUrl(['page' => $valPage - 1])) ?>">Previous</a><?php endif; ?>
                <span>Page <?= (int) $valPage ?> of <?= (int) $valPageCount ?> · <?= count($items) ?> items</span>
                <?php if ($valPage < $valPageCount): ?><a class="button secondary" href="<?= e($valPageUrl(['page' => $valPage + 1])) ?>">Next</a><?php endif; ?>
                <span style="margin-left:auto;display:flex;gap:6px;align-items:center">Rows
                    <?php foreach ([25, 50, 100, 200] as $size): ?>
                        <a class="button soft" style="<?= $size === $valPerPage ? 'font-weight:700' : '' ?>"
                           href="<?= e($valPageUrl(['per_page' => (string) $size, 'page' => 1])) ?>"><?= $size ?></a>
                    <?php endforeach; ?>
                </span>
            </nav>
        <?php endif; ?>
    </section>

    <section class="mbw-card" data-collapsible aria-label="Post NRV assessment">
        <div class="mbw-card-head">
            <h2>Post NRV Assessment</h2>
            <div class="mbw-card-tools"><span style="color:var(--mbw-muted);font-size:12.5px">Lower of cost and net realisable value (IAS 2). Posts a write-down or capped reversal.</span></div>
        </div>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
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
<?php endif; ?>

<?php if ($invView === 'inventory'): ?>
<?php if ($repairErrors !== []): ?><div class="notice error">Accounting module repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div><?php endif; ?>

<?php endif; ?>

<?php if (in_array($invView, ['inventory', 'manufacturing'], true) && !($invView === 'manufacturing' && $invTask === 'manufacturing')): ?>
<section class="mbw-card" <?= $invShows('warehouses') ? '' : 'data-collapsible' ?> aria-label="Inventory workbench">
    <?php if (!$invShows('warehouses')): ?>
    <div class="mbw-card-head inventory-workbench-head">
    <div>
        <h2><?= e(defined('INVENTORY_PAGE') ? $inventoryPage['title'] : ($invView === 'manufacturing' ? 'Manufacturing Workspace' : 'Inventory Workspace')) ?></h2>
    </div>
</div>
    <?php endif; ?>

<?php // The input-item list, shared by Production Order and Bill of
      // Materials. Defined out here because those are separate pages now
      // and each needs it. ?>
<?php $invItemOptions = static function () use ($items): string {
    // The placeholder belongs in the list, not beside it: filling a select
    // replaces everything inside it, so anything left outside disappears the
    // moment the script runs.
    $html = '<option value="">Select item</option>';
    foreach ($items as $item) {
        if ($item['status'] !== 'active') { continue; }
        $html .= '<option value="' . (int) $item['id'] . '">'
            . e($item['sku'] . ' - ' . $item['name'] . ' (on hand ' . number_format((float) $item['on_hand'], 3) . ')')
            . '</option>';
    }

    return $html;
}; ?>

<div class="workspace-feature-stack">
    <?php if ($invView === 'inventory'): ?>
    <?php if ($invShows('opening-import')): ?>
    <details class="feature-disclosure" id="opening-import">
        <summary><span><strong><?= icon('documents') ?>Opening stock from a spreadsheet</strong></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
        <p class="frm-optional" style="margin:0 0 12px">
            <strong>Uploading posts nothing.</strong> Check the preview, fix or remove rows, then Commit.
            <a href="<?= e(url('admin/inventory-opening.php?opening_template=xlsx')) ?>">Download template</a>
        </p>
        <form method="post" enctype="multipart/form-data" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
            <input type="hidden" name="action" value="upload_opening">
            <label>Spreadsheet<input type="file" name="opening_file" accept=".xlsx,.csv" required></label>
            <div style="align-self:end">
                <button type="submit" class="button">Upload &amp; Preview</button>
                <span class="frm-optional">.xlsx or .csv</span>
            </div>
        </form>

        <?php if ($invImportBatch): ?>
        <?php
            $invReady = 0; $invErrors = 0; $invDone = 0; $invValue = 0.0;
            foreach ($invImportRows as $ir) {
                if ((string) $ir['status'] === 'ready') { $invReady++; $invValue += (float) $ir['amount']; }
                elseif ((string) $ir['status'] === 'committed') { $invDone++; }
                else { $invErrors++; }
            }
        ?>
        <h3 style="margin:16px 0 8px"><?= e((string) $invImportBatch['original_name']) ?></h3>
        <div class="mbw-stat-row" style="margin-bottom:12px">
            <div class="mbw-stat"><span>Rows</span><strong><?= count($invImportRows) ?></strong></div>
            <div class="mbw-stat"><span>Ready</span><strong><?= $invReady ?></strong></div>
            <div class="mbw-stat <?= $invErrors > 0 ? 'tone-amber' : '' ?>"><span>Need attention</span><strong><?= $invErrors ?></strong></div>
            <div class="mbw-stat"><span>Committed</span><strong><?= $invDone ?></strong></div>
            <div class="mbw-stat"><span>Value ready</span><strong><?= number_format($invValue, 2) ?></strong></div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <form method="post" style="display:inline" onsubmit="return confirm('Commit <?= $invReady ?> row(s) as opening stock?');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
                <input type="hidden" name="action" value="commit_opening_import">
                <input type="hidden" name="import_id" value="<?= (int) $invImportBatch['id'] ?>">
                <button type="submit" class="button" <?= $invReady > 0 ? '' : 'disabled' ?>>Commit <?= $invReady ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Discard this import? Nothing has reached the books.');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
                <input type="hidden" name="action" value="discard_opening_import">
                <input type="hidden" name="import_id" value="<?= (int) $invImportBatch['id'] ?>">
                <button type="submit" class="button secondary">Discard</button>
            </form>
        </div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Row</th><th>From the sheet</th><th style="min-width:200px">Item</th><th>Qty</th><th>Rate</th><th>Amount</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($invImportRows as $ir): ?>
                    <tr<?= (string) $ir['status'] === 'error' ? ' style="background:var(--mbw-red-soft,#fdf5ef)"' : '' ?>>
                        <?php if ((string) $ir['status'] === 'committed'): ?>
                            <td><?= (int) $ir['source_row_no'] ?></td>
                            <td><?= e(trim((string) $ir['raw_code'] . ' ' . (string) $ir['raw_name'])) ?></td>
                            <td><?= e((string) ($ir['item_code'] ?? '')) ?></td>
                            <td class="is-numeric"><?= number_format((float) $ir['qty_pieces'], 3) ?></td>
                            <td class="is-numeric"><?= number_format((float) $ir['rate'], 4) ?></td>
                            <td class="is-numeric"><?= number_format((float) $ir['amount'], 2) ?></td>
                            <td><span class="mbw-pill tone-green">Committed</span></td>
                        <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
                            <input type="hidden" name="action" value="update_opening_import_row">
                            <input type="hidden" name="import_id" value="<?= (int) $invImportBatch['id'] ?>">
                            <input type="hidden" name="row_id" value="<?= (int) $ir['id'] ?>">
                            <td><?= (int) $ir['source_row_no'] ?></td>
                            <td class="frm-optional"><?= e(trim((string) $ir['raw_code'] . ' ' . (string) $ir['raw_name'])) ?>
                                <?php if ((string) $ir['error_text'] !== ''): ?>
                                    <br><span class="mbw-pill tone-amber"><?= e((string) $ir['error_text']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select name="item_id">
                                    <option value="0">— not matched —</option>
                                    <?php foreach ($invImportItems as $ii): ?>
                                        <option value="<?= (int) $ii['id'] ?>" <?= (int) $ir['item_id'] === (int) $ii['id'] ? 'selected' : '' ?>><?= e($ii['sku'] . ' — ' . $ii['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="number" name="qty_pieces" step="0.001" min="0" style="width:90px" value="<?= e((string) $ir['qty_pieces']) ?>"></td>
                            <td><input type="number" name="rate" step="0.0001" min="0" style="width:110px" value="<?= e((string) $ir['rate']) ?>"></td>
                            <td><input type="number" name="amount" step="0.01" min="0" style="width:120px" value="<?= e((string) $ir['amount']) ?>"></td>
                            <td style="white-space:nowrap">
                                <button type="submit" class="button secondary" style="min-height:30px;padding:3px 9px">Save</button>
                        </form>
                                <form method="post" style="display:inline" onsubmit="return confirm('Remove row <?= (int) $ir['source_row_no'] ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
                                    <input type="hidden" name="action" value="delete_opening_import_row">
                                    <input type="hidden" name="import_id" value="<?= (int) $invImportBatch['id'] ?>">
                                    <input type="hidden" name="row_id" value="<?= (int) $ir['id'] ?>">
                                    <button type="submit" class="button secondary" style="min-height:30px;padding:3px 9px">Delete</button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </details>
    <?php endif; ?>

    <?php if ($invShows('create-item')): ?>
    <dialog class="mbw-item-dialog" id="create-item" aria-label="Item">
        <div class="mbw-item-dialog-head">
            <h2><?= icon('services') ?><?= $editItem ? 'Edit item' : 'Create item' ?></h2>
            <button type="button" class="button secondary" data-close-item-dialog>Close</button>
        </div>
        <?php include __DIR__ . '/../../app/views/partials/item_creation_form.php'; ?>
    </dialog>
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        var dialog = document.getElementById("create-item");
        if (!dialog || typeof dialog.showModal !== "function") { return; }
        function openItemDialog() { if (!dialog.open) { dialog.showModal(); } }
        document.querySelectorAll("[data-open-item-dialog]").forEach(function (button) {
            button.addEventListener("click", openItemDialog);
        });
        dialog.querySelectorAll("[data-close-item-dialog]").forEach(function (button) {
            button.addEventListener("click", function () { dialog.close(); });
        });
        // Editing arrives as ?edit_id=... from the table, so the form has to
        // be open already or Edit would appear to do nothing at all.
        <?php if ($editItem): ?>openItemDialog();<?php endif; ?>
        // Closing drops edit_id, so a refresh does not reopen a form the
        // reader has finished with.
        dialog.addEventListener("close", function () {
            if (window.history && window.history.replaceState && window.location.search.indexOf("edit_id=") !== -1) {
                window.history.replaceState({}, "", <?= json_encode(url('admin/accounting-inventory.php'), JSON_UNESCAPED_SLASHES) ?>);
            }
        });
    });
    </script>
    <?php endif; ?>

    <?php if ($editItem): ?>
    <?php
    // "This item posts to" — every posting purpose this item's movements use
    // (purchases, sales, adjustments, NRV, manufacturing), filtered by item
    // type. Same arrangement as the fixed-asset panel: rows set here apply to
    // THIS item only; blank rows inherit the category/global default, which
    // is shown so the effective ledger is never a mystery.
    $panelPurposeMeta = inventory_mapping_purposes();
    ?>
    <?php if ($invShows('item-ledgers')): ?>
    <details class="feature-disclosure" id="item-ledgers" open>
        <summary><span><strong><?= icon('accounting') ?>This item posts to (ledgers)</strong><small><?= e($editItem['sku']) ?> — <?= e($editItem['name']) ?>: acquisition, sales, adjustment, NRV and production ledgers. Applies to this item only.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
            <input type="hidden" name="action" value="save_inventory_mappings">
            <input type="hidden" name="map_scope" value="item">
            <input type="hidden" name="map_item_id" value="<?= (int) $editItem['id'] ?>">
            <div class="rc-table-scroll"><table class="rc-table">
                <thead><tr><th>Used for</th><th>Expected type</th><th>Ledger (this item only)</th><th>Currently posting to</th></tr></thead>
                <tbody>
                    <?php foreach (inventory_purposes_for_item($editItem) as $panelPurpose): ?>
                        <?php
                        $panelOwnId = $itemMapCurrent[$panelPurpose] ?? 0;
                        $panelEffective = inv_resolve_mapping($companyId, $panelPurpose, (int) $editItem['id'], trim((string) ($editItem['category'] ?? '')) ?: null);
                        ?>
                        <tr>
                            <td><strong><?= e($panelPurposeMeta[$panelPurpose]['label'] ?? $panelPurpose) ?></strong></td>
                            <td><span class="mbw-pill tone-gray"><?= e(ucfirst($panelPurposeMeta[$panelPurpose]['expect'] ?? '')) ?></span></td>
                            <td><select name="map[<?= e($panelPurpose) ?>]" style="min-width:230px" data-fill-from="inv-ledger-options">
                                <option value="0">— use inherited default —</option>
                                <?= $invLedgerChosen((int) $panelOwnId) ?>
                            </select></td>
                            <td><?php if ($panelEffective): ?><span class="mbw-pill <?= $panelOwnId > 0 ? 'tone-green' : 'tone-gray' ?>"><?= e($panelEffective['name']) ?><?= $panelOwnId > 0 ? '' : ' (inherited)' ?></span><?php else: ?><span class="mbw-pill tone-red">Not set — postings needing it will be blocked</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <div style="margin-top:12px"><button type="submit"><?= icon('accounting') ?>Save this item's ledgers</button></div>
        </form>
    </details>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($invShows('warehouses')): ?>
    <div id="warehouses">
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
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
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
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
            <input type="hidden" name="action" value="save_warehouse">
            <label>Name<input type="text" name="name" maxlength="120" required></label>
            <label>Code<input type="text" name="code" maxlength="40" placeholder="Optional"></label>
            <div class="workspace-span-2"><button type="submit"><?= icon('services') ?>Add warehouse</button></div>
        </form>
    </div>
    <?php endif; ?>

<?php
// The item list the sale / issue / transfer forms further down share. It lived
// inside the purchase form until that became a grid with a list of its own.
$invMoveItemOptions = static function () use ($items): string {
    $html = '<option value="">Select item</option>';
    foreach ($items as $item) {
        $html .= '<option value="' . (int) $item['id'] . '"'
            . ' data-purchase-rate="' . e(number_format((float) $item['purchase_rate'], 2, '.', '')) . '"'
            . ' data-sales-rate="' . e(number_format((float) $item['sales_rate'], 2, '.', '')) . '">'
            . e($item['sku'] . ' - ' . $item['name']) . '</option>';
    }

    return $html;
};
?>


    <?php if ($invShows('movement-purchase-entries') || $invShows('movement-sale')):
        require __DIR__ . '/../../app/views/partials/inventory_voucher_history.php';
    endif; ?>

    <?php if ($invShows('movement-adjust')): ?>
    <details class="feature-disclosure" id="movement-adjust">
        <summary><span><strong><?= icon('tasks') ?>Adjustments &amp; Write-offs</strong></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
        <form method="post" class="workspace-form-grid" id="adjustForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
            <input type="hidden" name="action" value="record_movement">
            <?php $moveOpts = shared_options('inv-move-items', $invMoveItemOptions, (string) ($moveItemId ?: '')); ?>
            <label>Item<select name="item_id" id="adjItem" required<?= $moveOpts['fill'] ? ' data-fill-from="inv-move-items"' : '' ?>><?= $moveOpts['html'] ?></select></label>
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
    <?php endif; ?>

    <?php if ($invShows('movement-transfer')): ?>
    <?php require __DIR__ . '/../../app/views/partials/stock_transfer_history.php'; ?>
    <?php endif; ?>

    <?php endif; ?>
    <?php if ($inventoryProfile['show_manufacturing'] && $invView === 'manufacturing'): ?>

        <?php if ($invShows('bom')): ?>
        <details class="feature-disclosure" id="bom">
            <summary><span><strong><?= icon('documents') ?>Bill of Materials</strong></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
            <form method="post" class="workspace-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
                <input type="hidden" name="action" value="save_bom">
                <label>BOM no<input type="text" name="bom_no" placeholder="Leave blank for auto"></label>
                <label>Finished product<select name="finished_item_id" required><option value="">Select finished item</option><?php foreach ($items as $item): ?><?php if ($item['status'] !== 'active') { continue; } ?><option value="<?= e((int) $item['id']) ?>"><?= e($item['sku'] . ' - ' . $item['name']) ?></option><?php endforeach; ?></select></label>
                <label>Output qty per batch<input type="number" step="0.001" min="0.001" name="output_qty" value="1.000" required></label>
                <label>Std labour cost / batch<input type="number" step="0.01" min="0" name="std_labour_cost" value="0.00"></label>
                <label>Std overhead / batch<input type="number" step="0.01" min="0" name="std_overhead_cost" value="0.00"></label>
                <div class="workspace-span-2">
                    <strong style="font-size:13px;color:var(--mbw-heading)">Components</strong>
                    
                    <div style="overflow-x:auto">
                    <table id="bom-component-lines">
                        <thead><tr><th>Component</th><th class="is-numeric" style="width:150px">Std qty / batch</th><th class="is-numeric" style="width:150px">Expected waste %</th><th class="is-numeric" style="width:170px">Std rate</th><th style="width:44px"></th></tr></thead>
                        <tbody>
                            <?php for ($i = 0; $i < 4; $i++): ?>
                                <tr>
                                    <?php $bomOpts = shared_options('inv-bom-items', $invItemOptions); ?>
                                    <td><select name="bom_item_id[]"<?= $bomOpts['fill'] ? ' data-fill-from="inv-bom-items"' : '' ?>><?= $bomOpts['html'] ?></select></td>
                                    <td class="is-numeric"><input type="number" step="0.0001" min="0" name="bom_qty[]"></td>
                                    <td class="is-numeric"><input type="number" step="0.001" min="0" name="bom_waste[]" value="0"></td>
                                    <td class="is-numeric"><input type="number" step="0.000001" min="0" name="bom_rate[]" placeholder="Auto: purchase rate"></td>
                                    <td><button type="button" class="button secondary mbw-delete-action" title="Delete this row" aria-label="Delete this row" onclick="var b=this.closest('tbody');if(b.rows.length>1){this.closest('tr').remove();}else{this.closest('tr').querySelectorAll('input').forEach(function(i){i.value=i.name==='bom_waste[]'?'0':'';});this.closest('tr').querySelector('select').selectedIndex=0;}"><?= icon('trash') ?></button></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                    </div>
                    <button type="button" class="button secondary" style="margin-top:6px" onclick="var t=document.querySelector('#bom-component-lines tbody');var r=t.rows[0].cloneNode(true);r.querySelectorAll('input').forEach(function(i){i.value=i.name==='bom_waste[]'?'0':'';});r.querySelector('select').selectedIndex=0;t.appendChild(r);">+ Add component row</button>
                </div>
                <button type="submit"><?= icon('documents') ?>Save BOM</button>
            </form>
        </details>
        <?php endif; ?>
    <?php endif; ?>
</div>
</section>
<?php endif; ?>


<?php if ($invView === 'inventory'): ?>
<?php if ($invShows('item-stock-summary')): ?>
<section class="mbw-card" data-collapsible id="item-stock-summary">
    <div class="mbw-card-head">
        <h2>Item Stock Summary<?= $lowOnly ? ' — low stock only' : '' ?>
            <small style="font-weight:400;color:var(--mbw-muted,#64748b)">(<?= (int) $invTotalItems ?> item<?= $invTotalItems === 1 ? '' : 's' ?>)</small></h2>
        <div class="mbw-card-tools">
            <?php if ($itemFilterOn): ?><a class="mbw-view-all" href="<?= e(url('admin/accounting-inventory.php#item-stock-summary')) ?>">Clear filters</a><?php endif; ?>
            <?php if (user_can_do('inventory', 'export')): ?>
                <?php // The file carries whatever the filters left on screen. ?>
                <a class="mbw-view-all" href="<?= e($invPageUrl(['export' => 'csv'])) ?>" aria-label="Export CSV" title="Export CSV — the filtered list"><?= icon('download') ?></a>
                <a class="mbw-view-all" href="<?= e($invPageUrl(['export' => 'xlsx'])) ?>" aria-label="Export Excel" title="Export Excel — the filtered list"><?= icon('analytics') ?></a>
                <a class="mbw-view-all" target="_blank" rel="noopener" href="<?= e($invPageUrl(['export' => 'print'])) ?>" aria-label="Export PDF" title="Print / PDF — the filtered list"><?= icon('documents') ?></a>
            <?php endif; ?>
            <a class="mbw-view-all" href="<?= e(url('admin/reports-center.php?report=stock-valuation')) ?>">Valuation report &#8594;</a>
        </div>
    </div>
    <?php // Finding one item among a hundred and sixty three used to mean reading
          // every page. The filters narrow the list, the count above follows them,
          // and the export takes whatever they left. ?>
    <form method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;padding:2px 0 14px">
        <input type="hidden" name="view" value="<?= e($invView) ?>">
        <?php if ($invPerPage !== 50): ?><input type="hidden" name="per_page" value="<?= (int) $invPerPage ?>"><?php endif; ?>
        <label style="display:grid;gap:4px;flex:1 1 180px;margin:0">
            <span style="font-size:12.5px">SKU</span>
            <select name="sku" class="js-searchable">
                <option value="">All SKUs</option>
                <?php foreach ($itemSkuOptions as $skuOption): ?>
                    <option value="<?= e($skuOption) ?>" <?= $itemSkuFilter === $skuOption ? 'selected' : '' ?>><?= e($skuOption) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:grid;gap:4px;flex:1 1 200px;margin:0">
            <span style="font-size:12.5px">Name</span>
            <select name="item_name" class="js-searchable">
                <option value="">All names</option>
                <?php foreach ($itemNameOptions as $nameOption): ?>
                    <option value="<?= e($nameOption) ?>" <?= $itemNameFilter === $nameOption ? 'selected' : '' ?>><?= e($nameOption) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:grid;gap:4px;flex:1 1 170px;margin:0">
            <span style="font-size:12.5px">Category</span>
            <select name="category" class="js-searchable">
                <option value="">All categories</option>
                <?php foreach ($itemCategoryOptions as $categoryOption): ?>
                    <option value="<?= e($categoryOption) ?>" <?= $itemCategoryFilter === $categoryOption ? 'selected' : '' ?>><?= e($categoryOption) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:grid;gap:4px;flex:1 1 150px;margin:0">
            <span style="font-size:12.5px">Contains</span>
            <input type="search" name="q" value="<?= e($itemQuery) ?>" placeholder="any word">
        </label>
        <label style="display:grid;gap:4px;flex:0 1 170px;margin:0">
            <span style="font-size:12.5px">Type</span>
            <select name="item_type">
                <option value="">All types</option>
                <?php foreach ($itemTypes as $typeOption): ?>
                    <option value="<?= e($typeOption) ?>" <?= $itemTypeFilter === $typeOption ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $typeOption))) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:grid;gap:4px;flex:0 1 140px;margin:0">
            <span style="font-size:12.5px">Status</span>
            <select name="item_status">
                <option value="">All</option>
                <option value="active" <?= $itemStatusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $itemStatusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </label>
        <label style="display:grid;gap:4px;flex:0 1 160px;margin:0">
            <span style="font-size:12.5px">Stock</span>
            <select name="stock">
                <option value="">Any quantity</option>
                <option value="in" <?= $itemStockFilter === 'in' ? 'selected' : '' ?>>In stock</option>
                <option value="out" <?= $itemStockFilter === 'out' ? 'selected' : '' ?>>Nil stock</option>
            </select>
        </label>
        <label style="display:flex;gap:6px;align-items:center;margin:0 0 6px">
            <input type="checkbox" name="low" value="1" <?= $lowOnly ? 'checked' : '' ?>>
            <span style="font-size:12.5px">At or below reorder</span>
        </label>
        <button type="submit" class="button secondary">Filter</button>
        <a class="button secondary" href="<?= e(url('admin/accounting-inventory.php#item-stock-summary')) ?>">Clear</a>
    </form>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>SKU</th><th>Name</th><th>Type</th><th>Method</th><th>Unit</th><th class="is-numeric">On hand</th><th class="is-numeric">Unit cost</th><th class="is-numeric">Value at cost</th><th class="is-numeric">Reorder</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if ($visibleItems === []): ?><tr><td colspan="11"><?= $itemFilterOn
                ? 'No item matches these filters. Clear them to see the rest.'
                : 'No items yet.' ?></td></tr><?php endif; ?>
            <?php // Priced for the whole page in three sweeps, not four statements
                  // a row — same arithmetic, see inv_item_valuations().
                  $pagedPriced = inv_item_valuations($companyId, $pagedItems); ?>
            <?php foreach ($pagedItems as $item): ?>
                <?php $low = $isLowStock($item); $iv = $pagedPriced[(int) $item['id']] ?? []; ?>
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
                        <a class="button secondary" href="<?= e(url('admin/items.php?edit_id=' . (int) $item['id'] . '#create-item')) ?>">Edit</a>
                        <a class="button secondary" href="<?= e(url('admin/inventory-adjustments.php?move_item=' . (int) $item['id'] . '#movement-adjust')) ?>" title="Record a stock adjustment for this item">Move</a>
                        <a class="button secondary" href="<?= e(url('admin/reports-center.php?report=stock-ledger&item_id=' . (int) $item['id'])) ?>" title="Open this item's stock ledger (running balance)">Ledger</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($invPageCount > 1): ?>
        <nav class="actions" style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap" aria-label="Stock summary pages">
            <?php if ($invPage > 1): ?><a class="button secondary" href="<?= e($invPageUrl(['page' => $invPage - 1])) ?>">Previous</a><?php endif; ?>
            <span>Page <?= (int) $invPage ?> of <?= (int) $invPageCount ?> · <?= (int) $invTotalItems ?> items</span>
            <?php if ($invPage < $invPageCount): ?><a class="button secondary" href="<?= e($invPageUrl(['page' => $invPage + 1])) ?>">Next</a><?php endif; ?>
            <span style="margin-left:auto;display:flex;gap:6px;align-items:center">Rows
                <?php foreach ([25, 50, 100, 200] as $size): ?>
                    <a class="button soft" style="<?= $size === $invPerPage ? 'font-weight:700' : '' ?>"
                       href="<?= e($invPageUrl(['per_page' => (string) $size, 'page' => 1])) ?>"><?= $size ?></a>
                <?php endforeach; ?>
            </span>
        </nav>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php // Stock by warehouse answers "what is where", which is the warehouse
      // task's question. It is built only on that page. ?>
<?php if ($showWarehouseStockCard && $invShows('warehouses')): ?>
<section class="mbw-card" data-collapsible aria-label="Stock by warehouse">
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

<?php if (!in_array($invTask, ['purchase', 'sale', 'transfer'], true) && ($pageMovementTypes === null || $pageMovementTypes !== [])): ?>
<section class="mbw-card" data-collapsible>
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
                    <td><?= e(app_date($movement['transaction_date'])) ?></td><td><?= e($movement['sku'] . ' - ' . $movement['item_name']) ?></td>
                    <td><span class="mbw-pill <?= $movementIn ? 'tone-blue' : 'tone-gray' ?>"><?= e(str_replace('_', ' ', ucfirst($movement['transaction_type']))) ?><?= $movement['transaction_type'] === 'adjustment' ? ($movementIn ? ' +' : ' −') : '' ?></span>
                        <?php
                        // A MOVEMENT WITH NO VOUCHER NEVER REACHED THE LEDGER.
                        // Until now the only sign of it was the shape of the
                        // button at the end of the row -- a bin where other
                        // companies have "Reverse" -- which reads as two
                        // screens behaving differently rather than as one
                        // company missing its ledger mapping. It says so now.
                        $movementUnposted = (int) ($movement['voucher_id'] ?? 0) <= 0
                            && empty($movement['jewellery_stock_txn_id'])
                            && !in_array((string) $movement['transaction_type'], ['consume', 'produce'], true);
                        ?>
                        <?php if ($movementUnposted): ?>
                            <span class="mbw-pill tone-amber" title="Stock was recorded but no accounting entry was raised, because this item has no ledgers mapped. Map them in Ledger mapping, then reconcile from Stock Summary.">stock only</span>
                        <?php endif; ?>
                    </td>
                    <td class="is-numeric"><?= e(number_format((float) $movement['qty_in'], 3)) ?></td><td class="is-numeric"><?= e(number_format((float) $movement['qty_out'], 3)) ?></td><td class="is-numeric"><?= e(number_format((float) $movement['rate'], 2)) ?></td><td class="is-numeric"><?= e(number_format((float) $movement['amount'], 2)) ?></td><td><?= e($movement['ref_no'] ?? '-') ?></td>
                    <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                        <td style="white-space:nowrap">
                            <?php if (empty($movement['jewellery_stock_txn_id'])
                                && !in_array((string) $movement['transaction_type'], ['consume', 'produce'], true)): ?>
                                <?php if ((int) ($movement['voucher_id'] ?? 0) > 0): ?>
                                    <form method="post" style="display:inline" data-confirm="Reverse this <?= e(str_replace('_', ' ', $movement['transaction_type'])) ?> of <?= e($movement['sku']) ?>? A mirror stock entry and a reversing voucher (Dr/Cr swapped) are posted; the originals are kept for audit.">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
                                        <input type="hidden" name="action" value="reverse_movement">
                                        <input type="hidden" name="movement_id" value="<?= e((int) $movement['id']) ?>">
                                        <button type="submit" class="button secondary" title="Reverse this posted movement">Reverse</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="display:inline" data-confirm="Delete this <?= e(str_replace('_', ' ', $movement['transaction_type'])) ?> movement of <?= e($movement['sku']) ?> dated <?= e($movement['transaction_date']) ?>? Stock on hand recalculates immediately.">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
                                        <input type="hidden" name="action" value="delete_movement">
                                        <input type="hidden" name="movement_id" value="<?= e((int) $movement['id']) ?>">
                                        <button type="submit" class="button secondary" title="Delete this stock-only movement"><?= icon('trash') ?></button>
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
<?php endif; ?>
<?php if ($inventoryProfile['show_manufacturing'] && $invView === 'manufacturing' && $invTask === 'manufacturing'): ?>
    <section class="mbw-card" data-collapsible id="manufacturing-orders">
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
                <?php if ($manufacturingOrders === []): ?><tr><td colspan="8">No manufacturing orders yet.</td></tr><?php endif; ?>
                <?php foreach ($manufacturingOrders as $order): ?>
                    <?php $orderOpen = in_array((string) $order['status'], ['draft', 'in_progress'], true); ?>
                    <tr>
                        <td><a href="<?= e(url('admin/production-voucher-view.php?id=' . (int) $order['id'])) ?>"><?= e($order['order_no']) ?></a></td>
                        <td><?= e($order['sku'] . ' - ' . $order['finished_item_name']) ?></td>
                        <td class="is-numeric"><?= e(number_format((float) $order['quantity'], 3)) ?></td>
                        <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $order['input_cost'], 2)) ?> <small style="color:var(--mbw-muted)">(<?= e((int) $order['input_lines']) ?> lines)</small></td>
                        <td><span class="mbw-pill <?= $order['status'] === 'completed' ? 'tone-green' : ($order['status'] === 'cancelled' ? 'tone-red' : 'tone-amber') ?>"><?= e(str_replace('_', ' ', ucfirst($order['status']))) ?></span></td>
                        <td><?= e(app_date($order['started_on'] ?? '')) ?></td>
                        <td><?= e(app_date($order['completed_on'] ?? '')) ?></td>
                        <td style="white-space:nowrap">
                            <?php if ($orderOpen): ?>
                                <form method="post" style="display:inline" data-confirm="Complete <?= e($order['order_no']) ?>? <?= e(number_format((float) $order['quantity'], 3)) ?> finished goods will be received into stock at the material cost.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
                                    <input type="hidden" name="action" value="complete_manufacturing_order">
                                    <input type="hidden" name="order_id" value="<?= e((int) $order['id']) ?>">
                                    <button type="submit">Complete</button>
                                </form>
                                <form method="post" style="display:inline" data-confirm="Cancel <?= e($order['order_no']) ?>? Issued materials will be returned to stock.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
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

    <?php if ($orderPages > 1): ?><nav class="actions" aria-label="Production register pages">
        <?php if ($orderPage > 1): ?><a class="button secondary" href="<?= e(url(inv_back_url('page=' . ($orderPage - 1)))) ?>">Previous</a><?php endif; ?>
        <span><?= $orderPage ?> / <?= $orderPages ?></span>
        <?php if ($orderPage < $orderPages): ?><a class="button secondary" href="<?= e(url(inv_back_url('page=' . ($orderPage + 1)))) ?>">Next</a><?php endif; ?>
    </nav><?php endif; ?>
    <?php
    $varianceRows = table_exists('production_variances')
        ? db()->query("SELECT pv.*, mo.order_no FROM production_variances pv JOIN manufacturing_orders mo ON mo.id = pv.manufacturing_order_id WHERE pv.company_id = " . $companyId . " AND mo.fiscal_year_id = " . $fiscalYearId . " ORDER BY pv.id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC)
        : [];
    ?>
    <?php if ($varianceRows !== []): ?>
    <section class="mbw-card" data-collapsible id="production-variances">
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
<?php // The tab script that used to show one panel and hide the rest has gone.
      // Each task is its own page now, so there is only ever one panel to show —
      // and left in place it would have hidden that one whenever the hash did not
      // match. ?>
<?php // Before the footer on purpose: the footer loads searchable-select.js,
      // which decides whether to take a dropdown over by counting its options.
      // It has to find the real list here, not the stub. ?>
<?php // Guarded: these grids are only drawn on the views that build recipes,
      // and the builder is defined with them. ?>
<?php if (isset($invItemOptions)): ?>
<?= shared_options_template('inv-input-items', $invItemOptions) ?>
<?= shared_options_template('inv-bom-items', $invItemOptions) ?>
<?php endif; ?>
<?php if (isset($invMoveItemOptions)): ?><?= shared_options_template('inv-move-items', $invMoveItemOptions) ?><?php endif; ?>
<?php // Unguarded: it emits itself only when a stub on this page needs it. ?>
<?= shared_options_script() ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>

<script>
// Each ledger picker takes its own copy of the list from the template above.
// A select that already carries its choice keeps it: the copy is appended, and
// the duplicate of the chosen one is dropped rather than shown twice.
(function () {
    var source = document.getElementById('inv-ledger-options');
    if (!source) { return; }
    document.querySelectorAll('select[data-fill-from="inv-ledger-options"]').forEach(function (select) {
        var chosen = select.value;
        var addition = document.createElement('select');
        addition.innerHTML = source.innerHTML;
        Array.prototype.forEach.call(addition.querySelectorAll('option'), function (option) {
            if (chosen && option.value === chosen) { return; }
            select.appendChild(option.cloneNode(true));
        });
        if (chosen && select.querySelector('option[value="' + chosen + '"]')) { select.value = chosen; }
        select.removeAttribute('data-fill-from');
    });
})();
</script>
