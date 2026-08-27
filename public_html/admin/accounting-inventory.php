<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/manufacturing_engine.php';
// The item master is shared with the Jewellery module, so this page has to be
// able to read and complete the jewellery half of an item. Pure function
// definitions — it does nothing unless the client has the module switched on.
require_once __DIR__ . '/../../app/jewellery_stock.php';
// The ledger-mapping catalogue lives in app/ so Hospitality and Jewellery can
// show the SAME table rather than each keeping a copy of the list.
require_once __DIR__ . '/../../app/inventory_mapping.php';
require_once __DIR__ . '/../../app/opening_stock_import.php';
// An inventory item can also BE a recipe ingredient. Pure definitions — the
// kitchen list is only touched for a company that has hospitality switched on
// and an item actually marked.
require_once __DIR__ . '/../../app/hospitality_engine.php';
// A supplier's bill is entered as a grid and posted as one transaction.
require_once __DIR__ . '/../../app/inventory_purchase_batch.php';

require_staff_admin_or_client_books();
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
    $tasks = ['item', 'warehouses', 'purchase', 'sale', 'adjust', 'transfer', 'manufacturing', 'bom'];
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

/**
 * The inventory posting purposes (chosen per item on the item form and its
 * human label and the account type each SHOULD point at (used for the
 * "wrong-type" warning so an asset is not mapped to income, etc.).
 */

/**
 * Sets (or with ledger id 0 clears) ONE item-scope ledger mapping — the same
 * per-record arrangement as fixed assets: ledgers are chosen on the item form
 * and the item's "This item posts to" panel; inv_resolve_mapping still walks
 * item -> category -> global, so old global rows keep working as defaults.
 */
function inventory_set_item_ledger(int $companyId, int $itemId, string $purpose, int $ledgerId, ?int $userId = null): void
{
    if ($itemId <= 0 || !array_key_exists($purpose, inventory_mapping_purposes())) {
        return;
    }
    if ($ledgerId > 0) {
        $own = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :id AND company_id = :cid');
        $own->execute(['id' => $ledgerId, 'cid' => $companyId]);
        if ((int) $own->fetchColumn() === 0) {
            return; // never map a foreign company's ledger
        }
        // The purpose says what kind of account it needs, and an item-scoped
        // mapping is held to it exactly as the company-wide one is. Stock
        // pointed at an expense ledger charges every purchase to the profit
        // and loss and leaves the balance sheet with no inventory on it.
        $expected = (string) (inventory_mapping_purposes()[$purpose]['expect'] ?? '');
        $actual = inv_ledger_nature($companyId, $ledgerId);
        if ($expected !== '' && $actual !== '' && $actual !== $expected) {
            throw new RuntimeException((string) (inventory_mapping_purposes()[$purpose]['label'] ?? $purpose)
                . ' has to be ' . inv_nature_article($expected) . ' ledger, and that one is '
                . inv_nature_article($actual) . ' ledger.');
        }
    }
    db()->prepare("DELETE FROM inventory_ledger_mappings WHERE company_id = :cid AND scope = 'item' AND item_id = :iid AND purpose = :p AND category IS NULL")
        ->execute(['cid' => $companyId, 'iid' => $itemId, 'p' => $purpose]);
    // These mappings just changed; forget what was read of them.
    inv_mapping_forget();
    if ($ledgerId > 0) {
        db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, category, item_id, purpose, ledger_id, created_by) VALUES (:cid, 'item', NULL, :iid, :p, :lid, :uid)")
            ->execute(['cid' => $companyId, 'iid' => $itemId, 'p' => $purpose, 'lid' => $ledgerId, 'uid' => $userId ?: null]);
        // These mappings just changed; forget what was read of them.
        inv_mapping_forget();
    }
}

/** The posting purposes that apply to ONE item, by its type (FA-style filter). */
function inventory_purposes_for_item(array $item): array
{
    $base = ['inventory_asset', 'opening_equity', 'purchase_clearing', 'cogs', 'sales_revenue', 'inventory_gain', 'inventory_loss', 'write_down_expense', 'write_down_allowance', 'write_down_reversal', 'tax_input', 'tax_output'];
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

    if ($action === 'save_inventory_method') {
        // Which of the two systems the books are kept on. Deliberately behind
        // the same right as posting a voucher: this decides what every future
        // purchase debits, which is a bookkeeping decision and not a display
        // preference.
        require_permission('accounting', 'post');
        require_once __DIR__ . '/../../app/inventory_valuation.php';
        $wanted = (string) ($_POST['inventory_accounting'] ?? '') === 'periodic' ? 'periodic' : 'perpetual';
        $current = inv_accounting_method();
        if ($wanted !== $current) {
            db()->prepare('REPLACE INTO settings (setting_key, setting_value) VALUES (:k, :v)')
                ->execute(['k' => 'inventory_accounting', 'v' => $wanted]);
            setting('inventory_accounting', '', true);
            log_activity('inventory', $companyId, 'updated',
                'Inventory accounting switched from ' . $current . ' to ' . $wanted . '.', $userId);
            flash('success', $wanted === 'periodic'
                ? 'Now on the PERIODIC system. New purchases will debit Purchases, and sales will post no cost'
                    . ' entry. Books already posted the other way are unchanged until they are converted —'
                    . ' run deploy/convert-to-periodic.php, earliest year first.'
                : 'Back on the PERPETUAL system. New purchases will debit Inventory and each sale will post'
                    . ' its own cost of sales.');
        }
        redirect('admin/accounting-inventory.php?view=valuation#inventory-method');
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
                redirect(inv_back_url());
            }
            if (!in_array((string) $existing['item_type'], $allowedTypes, true)) {
                $allowedTypes[] = (string) $existing['item_type'];
            }
        }
        if ($sku === '' || $name === '' || !in_array($itemType, $allowedTypes, true) || !in_array($status, ['active', 'inactive'], true)) {
            flash('error', 'SKU, item name, type, and status are required.');
            redirect(inv_back_url());
        }

        $validMethods = ['fifo', 'weighted_average', 'specific'];
        $valuationMethod = (string) ($_POST['valuation_method'] ?? 'weighted_average');
        if (!in_array($valuationMethod, $validMethods, true)) {
            $valuationMethod = 'weighted_average';
        }
        $params = [
            'company_id' => $companyId,
            // The legacy linked-ledger column follows the item's chosen
            // Inventory Asset ledger so older reads stay consistent.
            'ledger_id' => (int) ($_POST['item_map']['inventory_asset'] ?? ($_POST['ledger_id'] ?? 0)) ?: null,
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
            // Opening VALUE is stored, never derived from the current purchase
            // rate later — like the accounting opening balances (qty + amount).
            'opening_amount' => max(0.0, round((float) ($_POST['opening_amount'] ?? 0), 2)),
            'reorder_level' => max(0.0, round((float) ($_POST['reorder_level'] ?? 0), 3)),
            'default_warehouse_id' => inventory_company_warehouse_id((int) ($_POST['default_warehouse_id'] ?? 0), $companyId),
            'status' => $status,
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
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
                        valuation_method = :valuation_method, unit = :unit,
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
                        company_id, ledger_id, sku, name, category, item_type, ' . ($marksIngredient ? 'is_ingredient, ' : '') . 'valuation_method, unit, hs_code, tax_rate,
                        sales_rate, purchase_rate, opening_qty, opening_amount, reorder_level, default_warehouse_id, status, notes
                    ) VALUES (
                        :company_id, :ledger_id, :sku, :name, :category, :item_type, ' . ($marksIngredient ? ':is_ingredient, ' : '') . ':valuation_method, :unit, :hs_code, :tax_rate,
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
        // Did the whole form arrive? PHP reads at most max_input_vars fields
        // and throws the remainder away silently, so a bill long enough to
        // cross that line posts with its last items missing and nothing
        // anywhere says so. The sentinel is the last field in the form; if it
        // is not here, neither is everything after the cut.
        if (($_POST['grid_end'] ?? '') !== '1') {
            $inputCap = (int) ini_get('max_input_vars');
            $_SESSION['inv_purchase_bills'] = array_values((array) ($_POST['bills'] ?? []));
            $_SESSION['inv_purchase_grid_errors'] = [
                'This bill is longer than the server will accept in one submission'
                    . ($inputCap > 0 ? ' (max_input_vars is ' . $inputCap . ', which is about ' . max(1, intdiv($inputCap - 20, 12)) . ' item lines)' : '')
                    . '. Nothing was recorded — recording part of a bill would be worse than recording none of it.',
                'Split it across two bills with the same bill number (they post as one entry), or raise max_input_vars in public_html/.user.ini — it is deployed with the site, so an edit made on the server by hand is overwritten by the next deploy.',
            ];
            flash('error', 'Nothing was recorded: the bill was too long for the server to accept in one go, and only part of it arrived. '
                . 'Everything that did arrive is still on the form below.');
            redirect(inv_back_url('#movement-purchase'));
        }
        // The form is bills-with-items; the engine posts flat lines. Folding
        // out here keeps the tested engine untouched and means a bill's header
        // is copied onto its lines by code rather than re-typed by a person.
        $gridBills = (array) ($_POST['bills'] ?? []);
        $gridRows = $gridBills !== []
            ? inv_purchase_bills_to_rows($gridBills)
            : (array) ($_POST['rows'] ?? []);
        $checked = inv_purchase_batch_validate($companyId, $fiscalYearId, $gridRows);
        // Editing a bill is entering it again over the top of the old one. The
        // grid is checked FIRST — validation writes nothing — so a bad line
        // sends the person back to the form with the original bill still
        // standing, and only a grid that will certainly post gets to remove it.
        $replaceBillId = (int) ($_POST['replace_bill_id'] ?? 0);
        $replaced = null;
        if ($replaceBillId > 0 && $checked['errors'] === []
            && array_filter($checked['rows'], static fn (array $r): bool => $r['errors'] !== []) === []) {
            if ((string) ($currentUser['role'] ?? '') !== 'admin') {
                flash('error', 'Only an administrator can replace a purchase entry that is already recorded.');
                redirect(inv_back_url('#movement-purchase-entries'));
            }
            $replaced = inv_purchase_bill_delete($companyId, $replaceBillId, $userId);
            if (!$replaced['ok']) {
                $_SESSION['inv_purchase_bills'] = array_values($gridBills);
                $_SESSION['inv_purchase_grid_errors'] = [(string) $replaced['error']];
                flash('error', 'The bill was left exactly as it was: ' . (string) $replaced['error']);
                redirect(inv_back_url('#movement-purchase'));
            }
        }
        $result = inv_purchase_batch_post($companyId, $fiscalYearId, $checked, $userId);
        if (!$result['ok'] && $replaced !== null) {
            // Validation passed and the old bill is already gone, so this is
            // the one case where saying so plainly matters more than anything.
            $_SESSION['inv_purchase_bills'] = array_values($gridBills);
            $_SESSION['inv_purchase_grid_errors'] = [(string) $result['error']];
            flash('error', 'The old entry ' . ($replaced['voucher_no'] !== '' ? $replaced['voucher_no'] : '(draft)')
                . ' was removed but the replacement did not post: ' . (string) $result['error']
                . ' Everything typed is still on the form below — correct it and record it again.');
            redirect(inv_back_url('#movement-purchase'));
        }
        if (!$result['ok']) {
            // Everything typed is handed back, along with the per-line reasons,
            // so a long bill does not have to be keyed in twice.
            $problems = $checked['errors'];
            foreach ($checked['rows'] as $checkedRow) {
                foreach ($checkedRow['errors'] as $rowProblem) {
                    $problems[] = 'Item ' . $checkedRow['line'] . ': ' . $rowProblem;
                }
            }
            if ($problems === []) {
                $problems[] = (string) $result['error'];
            }
            // Everything typed is handed back in the shape the FORM uses, so a
            // long bill is not re-keyed over one bad line.
            $_SESSION['inv_purchase_bills'] = array_values($gridBills);
            $_SESSION['inv_purchase_grid'] = array_values($gridRows);
            $_SESSION['inv_purchase_grid_errors'] = array_slice($problems, 0, 20);
            flash('error', (string) $result['error']);
            redirect(inv_back_url('#movement-purchase'));
        }
        $unmapped = 0;
        foreach ($result['lines'] as $resultLine) {
            if ($resultLine['map_missing'] !== []) {
                $unmapped++;
            }
        }
        flash('success', (int) $result['posted'] . ' purchase line(s) recorded as '
            . (int) ($result['bills'] ?? 1) . ' bill entry(ies) — one entry per invoice, not one per item'
            . ((int) ($result['ingredients_added'] ?? 0) > 0 ? ', ' . (int) $result['ingredients_added'] . ' added to the kitchen ingredient list' : '')
            . ($replaced !== null ? '. This replaces ' . ($replaced['voucher_no'] !== '' ? $replaced['voucher_no'] : 'the earlier draft') : '')
            . '. Bought-in stock is prepared as a draft entry — approve it in Purchase entries.'
            . ($unmapped > 0 ? ' ' . $unmapped . ' line(s) recorded stock only: map their ledgers on the item to post the accounting entry.' : ''));
        redirect(inv_back_url('#movement-purchase-entries'));
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
            redirect('admin/accounting-inventory.php?view=valuation');
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
        redirect('admin/accounting-inventory.php?view=valuation');
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
            ? 'admin/accounting-inventory.php?edit_id=' . $mapItemId . '#create-item'
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
        require_permission('inventory', 'create');
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
        if (!$finishedItem || $quantity <= 0) {
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
            if ($inputItemId <= 0 || $inputQty <= 0) {
                continue;
            }
            if ($inputItemId === $finishedItemId) {
                flash('error', 'The finished item cannot also be one of its own input materials.');
                redirect(inv_back_url());
            }
            $inputItem = inventory_company_item($inputItemId, $companyId);
            if (!$inputItem) {
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
            $voucherId = inventory_post_production_voucher($companyId, $fiscalYearId, $orderId, $orderNo, $completedOn, inv_item_stock_ledger_id($companyId, $finishedItem), $inputCostByLedger, $userId, [
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
    ORDER BY i.status ASC, i.name ASC
');
$itemStmt->execute(['company_id' => $companyId]);
$items = $itemStmt->fetchAll();

// The two lists the purchase grid picks from, read once for the page. Every
// row of the grid shares one copy of each (see shared_options below), so ten
// rows do not mean ten copies of the supplier list on the wire.
$purchaseParties = [];
if (table_exists('accounting_parties')) {
    $partyStmt = db()->prepare("SELECT id, name FROM accounting_parties
        WHERE company_id = :cid AND status = 'active' ORDER BY name ASC");
    $partyStmt->execute(['cid' => $companyId]);
    $purchaseParties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);
}
$purchaseLedgers = [];
if (table_exists('ledgers')) {
    $purchaseLedgerStmt = db()->prepare("SELECT id, code, name FROM ledgers
        WHERE company_id = :cid AND status = 'active' ORDER BY code ASC, name ASC");
    $purchaseLedgerStmt->execute(['cid' => $companyId]);
    $purchaseLedgers = $purchaseLedgerStmt->fetchAll(PDO::FETCH_ASSOC);
}
// Filled back in when a batch is refused, so nothing typed is lost.
$purchaseGridRows = $_SESSION['inv_purchase_grid'] ?? [];
$purchaseBills = $_SESSION['inv_purchase_bills'] ?? [];
$purchaseGridErrors = $_SESSION['inv_purchase_grid_errors'] ?? [];
unset($_SESSION['inv_purchase_grid'], $_SESSION['inv_purchase_bills'], $_SESSION['inv_purchase_grid_errors']);

// Editing a recorded bill is the same form, filled in from what was recorded.
// Saving it replaces the old entry outright rather than leaving a correction
// beside it, which is why the id travels with the form.
$editBill = null;
$editBillId = (int) ($_GET['edit_bill'] ?? 0);
if ($editBillId > 0 && $purchaseBills === []) {
    $editBill = inv_purchase_bill_load($companyId, $editBillId);
    if ($editBill === null) {
        flash('error', 'That purchase entry was not found for this company.');
        $editBillId = 0;
    } else {
        // Back into the shape the form posts: one bill, its header repeated
        // from the entry, its items from the movements underneath it.
        $editItems = [];
        foreach ($editBill['items'] as $editItem) {
            $editItems[] = [
                'item_id' => (int) $editItem['item_id'],
                'quantity' => rtrim(rtrim(number_format((float) $editItem['qty_in'] + (float) $editItem['qty_out'], 3, '.', ''), '0'), '.'),
                'rate' => number_format((float) $editItem['rate'], 2, '.', ''),
                // VAT and withholding live on the ENTRY, not the movement, so
                // they come back as the bill's own figures below rather than
                // being invented per line.
                'vat_applicable' => '0',
                'notes' => (string) ($editItem['notes'] ?? ''),
            ];
        }
        $editVatTotal = 0.0;
        $editVatLedgerId = 0;
        $editTdsLedgerId = 0;
        foreach ($editBill['lines'] as $editLine) {
            if (stripos((string) ($editLine['memo'] ?? ''), 'VAT on purchase') === 0) {
                $editVatTotal += (float) $editLine['amount'];
                $editVatLedgerId = (int) $editLine['ledger_id'];
            } elseif (stripos((string) ($editLine['memo'] ?? ''), 'TDS withheld') === 0) {
                $editTdsLedgerId = (int) $editLine['ledger_id'];
            }
        }
        // The bill's VAT sits on its first line, where the person can see it and
        // move it; splitting one recorded figure back across the items would be
        // a guess at how it was arrived at.
        if ($editVatTotal > 0 && $editItems !== []) {
            $editItems[0]['vat_applicable'] = '1';
            $editItems[0]['vat_amount'] = number_format($editVatTotal, 2, '.', '');
        }
        $purchaseBills = [[
            'transaction_date' => (string) ($editBill['items'][0]['transaction_date'] ?? $editBill['voucher_date']),
            'supplier_invoice_date' => '',
            'movement' => (string) ($editBill['items'][0]['transaction_type'] ?? 'purchase'),
            'ref_no' => (string) ($editBill['reference_no'] ?? ''),
            'supplier_party_id' => (int) ($editBill['party_id'] ?? 0),
            'vat_ledger_id' => $editVatLedgerId,
            'tds_ledger_id' => $editTdsLedgerId,
            'items' => $editItems,
        ]];
    }
}

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
        'warehouses' => ['warehouses'],
        'purchase' => ['movement-purchase', 'movement-purchase-entries'],
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
if ($invView === 'inventory' && ($_GET['task'] ?? '') === '' && !empty($purchaseGridErrors)) {
    $invTask = 'purchase';
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
$visibleItems = $lowOnly ? array_values(array_filter($items, $isLowStock)) : $items;
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
$invPageUrl = static function (array $overrides) use ($lowOnly, $invPerPage): string {
    $query = array_filter([
        'low' => $lowOnly ? '1' : '',
        'per_page' => (string) $invPerPage,
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

include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<nav class="mbw-tabbar inventory-module-tabs" aria-label="Inventory modules">
    <a class="mbw-tab <?= $invView === 'inventory' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-inventory.php')) ?>"><?= icon('layers') ?> Inventory</a>
    <?php if (($inventoryProfile['show_manufacturing'] ?? false)): ?>
        <a class="mbw-tab <?= $invView === 'manufacturing' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-inventory.php?view=manufacturing')) ?>"><?= icon('services') ?> Manufacturing</a>
    <?php endif; ?>
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

<nav class="mbw-tabbar inventory-workspace-tabs" aria-label="Inventory workspace">
    <a class="mbw-tab <?= $invView === 'inventory' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-inventory.php')) ?>"><?= icon('cart') ?>Items &amp; Transactions</a>
    <a class="mbw-tab <?= $invView === 'valuation' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-inventory.php?view=valuation')) ?>"><?= icon('reports') ?>Valuation &amp; NRV</a>
    <?php if ($inventoryProfile['show_manufacturing']): ?><a class="mbw-tab <?= $invView === 'manufacturing' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-inventory.php?view=manufacturing')) ?>"><?= icon('layers') ?>Manufacturing</a><?php endif; ?>
    <a class="mbw-tab" href="<?= e(url('admin/stock-summary-report.php')) ?>" title="Item-wise movement and valuation, wired to the GL ledgers"><?= icon('reports') ?>Stock Summary Report</a>
</nav>
<?php
$sampleCountStmt = db()->prepare("SELECT COUNT(*) FROM inventory_items WHERE company_id = :cid AND sku LIKE 'SMP-%'");
$sampleCountStmt->execute(['cid' => $companyId]);
$sampleCount = (int) $sampleCountStmt->fetchColumn();
if ($sampleCount > 0 && (string) (current_user()['role'] ?? '') === 'admin' && user_can_do('accounting', 'post')): ?>
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
        return url('admin/accounting-inventory.php?' . http_build_query(array_merge([
            'view' => 'valuation',
            'per_page' => (string) $valPerPage,
        ], $overrides))) . '#valuation-nrv';
    };
    ?>
    <?php
    require_once __DIR__ . '/../../app/inventory_valuation.php';
    $invMethod = inv_accounting_method();
    $methodMapped = [];
    foreach (['purchases' => 'Purchases', 'inventory_change' => 'Change in Inventory',
        'inventory_asset' => 'Inventory Asset'] as $methodPurpose => $methodLabel) {
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
        <p style="margin:0 0 14px;color:var(--mbw-muted);font-size:13px">
            Both are accepted practice — IAS 2 governs how inventory is <em>measured</em>, not which system records
            it. The difference shows on the face of the trial balance.
        </p>
        <form method="post" class="workspace-form-grid" style="grid-template-columns:1fr;gap:14px">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_inventory_method">
            <label style="display:flex;gap:12px;align-items:flex-start;padding:12px;border:1px solid var(--mbw-border);border-radius:10px;cursor:pointer">
                <input type="radio" name="inventory_accounting" value="perpetual" <?= $invMethod === 'perpetual' ? 'checked' : '' ?> style="margin-top:4px">
                <span>
                    <strong>Perpetual</strong><br>
                    <small style="color:var(--mbw-muted)">
                        A purchase debits Inventory; every sale posts its own cost of sales. The ledger always knows
                        what stock is worth, and the trial balance carries Inventory and Cost of Goods Sold.
                    </small>
                </span>
            </label>
            <label style="display:flex;gap:12px;align-items:flex-start;padding:12px;border:1px solid var(--mbw-border);border-radius:10px;cursor:pointer">
                <input type="radio" name="inventory_accounting" value="periodic" <?= $invMethod === 'periodic' ? 'checked' : '' ?> style="margin-top:4px">
                <span>
                    <strong>Periodic — Opening + Purchases − Closing</strong><br>
                    <small style="color:var(--mbw-muted)">
                        A purchase debits <strong>Purchases</strong>; a sale posts no cost entry at all. The trial
                        balance carries opening stock and purchases and carries <strong>neither closing stock nor
                        cost of sales</strong>, because neither is a ledger balance. Cost of sales is worked out
                        when the profit and loss is drawn, and closing stock reaches the balance sheet through the
                        one year-end journal.
                    </small>
                </span>
            </label>
            <?php if ($methodMapped !== []): ?>
                <div class="notice">
                    <strong>Map these before switching:</strong> <?= e(implode(', ', $methodMapped)) ?>.
                    The periodic system posts to them, and a purchase that cannot find its account will refuse to
                    post rather than guess.
                </div>
            <?php endif; ?>
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
                <small style="color:var(--mbw-muted)">
                    Switching changes what happens NEXT. Books already posted the other way are untouched until
                    they are converted, earliest year first.
                </small>
                <button type="submit" class="button" data-confirm="Change how every future purchase and sale posts to the ledger?">Save system</button>
            </div>
        </form>
    </section>

    <section class="mbw-card" id="valuation-nrv" data-collapsible aria-label="Valuation and NRV">
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
            <div class="mbw-card-tools"><span style="color:var(--mbw-muted);font-size:12.5px">Computes lower of cost and net realisable value (IAS 2.28-33) and posts a write-down or a capped reversal.</span></div>
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

<?php if (in_array($invView, ['inventory', 'manufacturing'], true)): ?>
<section class="mbw-card" data-collapsible aria-label="Inventory workbench">
    <div class="mbw-card-head inventory-workbench-head">
    <div>
        <h2><?= $invView === 'manufacturing' ? 'Manufacturing Workspace' : 'Inventory Workspace' ?></h2>
    </div>
</div>

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

<nav class="inventory-action-tabs" aria-label="Inventory tasks">
    <?php
    // Links, not buttons: each task is its own page, so it can be bookmarked,
    // opened in a tab, and reached with the back button.
    $invTaskLabels = $invView === 'inventory'
        ? ['item' => ['Item Master', 'services'], 'warehouses' => ['Warehouses', 'companies'],
           'purchase' => ['Purchase Stock', 'cart'], 'sale' => ['Sales & Returns', 'invoices'],
           'adjust' => ['Adjustments', 'settings'], 'transfer' => ['Transfers', 'services']]
        : ['manufacturing' => ['Production Order', 'settings'], 'bom' => ['Bill of Materials', 'documents']];
    ?>
    <?php foreach ($invTaskLabels as $taskKey => [$taskLabel, $taskIcon]): ?>
        <a class="inventory-action-tab <?= $invTask === $taskKey ? 'is-active' : '' ?>"
           href="<?= e(url('admin/accounting-inventory.php?view=' . $invView . '&task=' . $taskKey)) ?>"><?= icon($taskIcon) ?><span><?= e($taskLabel) ?></span></a>
    <?php endforeach; ?>
</nav>
<div class="workspace-feature-stack">
    <?php if ($invView === 'inventory'): ?>
    <?php if ($invShows('opening-import')): ?>
    <details class="feature-disclosure" id="opening-import">
        <summary><span><strong><?= icon('documents') ?>Opening stock from a spreadsheet</strong></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
        <p class="frm-optional" style="margin:0 0 12px">
            <strong>Uploading posts nothing.</strong> Check the preview, fix or remove rows, then Commit.
            <a href="<?= e(url('admin/accounting-inventory.php?opening_template=xlsx')) ?>">Download template</a>
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
    <details class="feature-disclosure" id="create-item" open>
        <summary><span><strong><?= icon('services') ?><?= $editItem ? 'Edit item' : 'Create item' ?></strong></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
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
            <?php if (column_exists('inventory_items', 'is_ingredient') && function_exists('hospitality_enabled_for_company') && hospitality_enabled_for_company($companyId)): ?>
                <?php // What it does is on the tickbox itself. Three lines of prose
                      // across the middle of a field grid broke the run of inputs in
                      // half and pushed everything after it onto a new row. ?>
                <label class="checkbox-line">
                    <input type="checkbox" name="is_ingredient" value="1" <?= (int) ($editItem['is_ingredient'] ?? 0) === 1 ? 'checked' : '' ?>
                           title="Puts this item in the kitchen&#39;s ingredient list so recipes can quote it. Name, code, category, purchase unit and cost keep coming from here; the ingredient screen only adds the unit a recipe measures it in and the wastage and yield of preparing it.">
                    Use as a recipe ingredient
                </label>
            <?php endif; ?>
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
            <label>Opening value <span class="frm-optional">total amount — frozen, like an accounting opening balance</span>
                <input type="number" step="0.01" min="0" name="opening_amount" value="<?= e($editItem['opening_amount'] ?? '0.00') ?>" placeholder="qty × cost at opening"></label>
            <label>Reorder level<input type="number" step="0.001" name="reorder_level" value="<?= e($editItem['reorder_level'] ?? '0.000') ?>"></label>
            <label>Default warehouse<select name="default_warehouse_id"><option value="0">— none —</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= e((int) $warehouse['id']) ?>" <?= (int) ($editItem['default_warehouse_id'] ?? 0) === (int) $warehouse['id'] ? 'selected' : '' ?>><?= e($warehouse['name'] . ($warehouse['code'] ? ' (' . $warehouse['code'] . ')' : '')) ?></option><?php endforeach; ?></select></label>
            <?php
            // Per-item ledgers, exactly like the fixed-asset register form:
            // the four everyday purposes are chosen here and belong to THIS
            // item only; the full lifecycle list lives in the panel below
            // when editing. 0 = inherit the category/global default.
            $itemMapCurrent = [];
            if ($editItem) {
                $itemMapStmt = db()->prepare("SELECT purpose, ledger_id FROM inventory_ledger_mappings WHERE company_id = :cid AND scope = 'item' AND item_id = :iid AND category IS NULL");
                $itemMapStmt->execute(['cid' => $companyId, 'iid' => (int) $editItem['id']]);
                foreach ($itemMapStmt->fetchAll(PDO::FETCH_ASSOC) as $imRow) {
                    $itemMapCurrent[(string) $imRow['purpose']] = (int) $imRow['ledger_id'];
                }
            }
            // The ledger list, built once for the page.
            //
            // Eighteen selects on this screen each carried their own copy of a
            // hundred and eighty ledgers — 335 KB of the same list, repeated.
            // One copy goes into the template below and each select takes it on
            // load; a select that already has a choice keeps that choice inline
            // so its value is right before the script has run.
            if (!isset($invLedgerOptionsHtml)) {
                $invLedgerOptionsHtml = '';
                $invLedgerOptionById = [];
                foreach ($ledgers as $invLedgerRow) {
                    $invLedgerLabel = $invLedgerRow['code'] . ' - ' . $invLedgerRow['name'];
                    $invLedgerOption = '<option value="' . (int) $invLedgerRow['id'] . '">' . e($invLedgerLabel) . '</option>';
                    $invLedgerOptionsHtml .= $invLedgerOption;
                    $invLedgerOptionById[(int) $invLedgerRow['id']] = $invLedgerOption;
                }
                echo '<template id="inv-ledger-options">' . $invLedgerOptionsHtml . '</template>';
            }
            /** The one option a select already holds, so its value survives until the fill. */
            $invLedgerChosen = static function (int $ledgerId) use (&$invLedgerOptionById): string {
                return $ledgerId > 0 && isset($invLedgerOptionById[$ledgerId])
                    ? str_replace('<option ', '<option selected ', $invLedgerOptionById[$ledgerId])
                    : '';
            };
            $itemFormPurposes = ['inventory_asset', 'purchase_clearing', 'cogs', 'opening_equity'];
            $itemPurposeMeta = inventory_mapping_purposes();
            ?>
            <?php foreach ($itemFormPurposes as $itemFormPurpose): ?>
                <?php
                $inheritLedger = inv_resolve_mapping($companyId, $itemFormPurpose, null, trim((string) ($editItem['category'] ?? '')) ?: null);
                $ownLedgerId = $itemMapCurrent[$itemFormPurpose] ?? 0;
                ?>
                <label><?= e($itemPurposeMeta[$itemFormPurpose]['label'] ?? $itemFormPurpose) ?> ledger (this item only)
                    <select name="item_map[<?= e($itemFormPurpose) ?>]" data-fill-from="inv-ledger-options">
                        <option value="0">— inherit default<?= $inheritLedger ? ': ' . e($inheritLedger['name']) : ' (not set)' ?> —</option>
                        <?= $invLedgerChosen((int) $ownLedgerId) ?>
                    </select>
                </label>
            <?php endforeach; ?>
            <?php
            // Jewellery half of the SHARED item master. Only rendered where the
            // module is on; leaving Metal blank keeps this a plain stock item.
            $jwOn = function_exists('jewellery_enabled_for_company') && jewellery_enabled_for_company($companyId);
            $jwProfile = null;
            if ($jwOn && $editItem) {
                $jwProfile = jewellery_item($companyId, (int) $editItem['id']);
            }
            ?>
            <?php if ($jwOn): ?>
                <input type="hidden" name="jw_enabled" value="1">
                <label>Metal <span class="frm-optional">leave blank for a plain stock item</span>
                    <select name="jw_metal_id" id="jwMetal">
                        <option value="0">— not a jewellery item —</option>
                        <?php foreach (jewellery_metals_list($companyId) as $jwMetal): ?>
                            <option value="<?= (int) $jwMetal['id'] ?>" <?= (int) ($jwProfile['metal_id'] ?? 0) === (int) $jwMetal['id'] ? 'selected' : '' ?>><?= e($jwMetal['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Purity
                    <select name="jw_purity_id" id="jwPurity">
                        <?php foreach (jewellery_purities_list($companyId) as $jwPurity): ?>
                            <option value="<?= (int) $jwPurity['id'] ?>" data-metal="<?= (int) $jwPurity['metal_id'] ?>" <?= (int) ($jwProfile['purity_id'] ?? 0) === (int) $jwPurity['id'] ? 'selected' : '' ?>><?= e($jwPurity['metal_code'] . ' · ' . $jwPurity['code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Weight unit
                    <select name="jw_unit_id">
                        <?php foreach (jewellery_units_list($companyId) as $jwUnit): ?>
                            <option value="<?= (int) $jwUnit['id'] ?>" <?= (int) ($jwProfile['unit_id'] ?? 0) === (int) $jwUnit['id'] ? 'selected' : '' ?>><?= e($jwUnit['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Jewellery type
                    <select name="jw_type">
                        <?php foreach (['ornament' => 'Ornament', 'bullion' => 'Bullion / raw metal', 'stone' => 'Stone', 'other' => 'Other'] as $jwK => $jwV): ?>
                            <option value="<?= e($jwK) ?>" <?= (string) ($jwProfile['item_type'] ?? 'ornament') === $jwK ? 'selected' : '' ?>><?= e($jwV) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Gross weight<input type="number" step="0.0001" min="0" name="jw_gross_weight" value="<?= e((string) ($jwProfile['gross_weight'] ?? '0')) ?>"></label>
                <label>Stone weight<input type="number" step="0.0001" min="0" name="jw_stone_weight" value="<?= e((string) ($jwProfile['stone_weight'] ?? '0')) ?>"></label>
                <label>Making charge rate<input type="number" step="0.0001" min="0" name="jw_making_rate" value="<?= e((string) ($jwProfile['making_charge_rate'] ?? '0')) ?>"></label>
                <label>VAT base
                    <select name="jw_vat_base">
                        <?php foreach (['default' => 'Company default', 'full_value' => 'Full line value', 'making_only' => 'Making charge only', 'stone_only' => 'Stone value only'] as $jwK => $jwV): ?>
                            <option value="<?= e($jwK) ?>" <?= (string) ($jwProfile['vat_base'] ?? 'default') === $jwK ? 'selected' : '' ?>><?= e($jwV) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="frm-check"><input type="checkbox" name="jw_vat_applicable" <?= (int) ($jwProfile['vat_applicable'] ?? 0) === 1 ? 'checked' : '' ?>> VAT applicable (e.g. diamond)</label>
                <script>
                (function () {
                    var metal = document.getElementById('jwMetal');
                    var purity = document.getElementById('jwPurity');
                    if (!metal || !purity) { return; }
                    var all = Array.prototype.slice.call(purity.options);
                    var preferred = purity.value;
                    function sync() {
                        purity.innerHTML = '';
                        all.forEach(function (opt) {
                            if (opt.getAttribute('data-metal') === metal.value) { purity.appendChild(opt); }
                        });
                        if (preferred) { purity.value = preferred; preferred = ''; }
                    }
                    metal.addEventListener('change', sync);
                    sync();
                })();
                </script>
            <?php endif; ?>
            <label class="workspace-span-2">Notes<textarea name="notes"><?= e($editItem['notes'] ?? '') ?></textarea></label>
            <div class="workspace-span-2"><button type="submit"><?= icon('services') ?>Save item</button><?php if ($editItem): ?> <a class="button secondary" href="<?= e(url('admin/accounting-inventory.php')) ?>">Cancel edit</a><?php endif; ?></div>
        </form>
    </details>
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
    <details class="feature-disclosure" id="warehouses">
        <summary><span><strong><?= icon('services') ?>Warehouses / Locations</strong></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
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
    </details>
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
    <?php if ($invShows('movement-purchase')): ?>
    <details class="feature-disclosure" id="movement-purchase" <?= $moveItemId > 0 || !empty($purchaseGridRows) ? 'open' : '' ?>>
        <summary><span><strong><?= icon('tasks') ?>Record Purchase / Opening Stock</strong></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
        <?php
        // A supplier's bill is ONE date, ONE movement, ONE bill number and ONE
        // supplier, with several items under it. The form is shaped that way --
        // the header on the row, the items behind a button -- because that is
        // how the paper reads, and asking for the supplier again on every line
        // is how a bill gets split across two accounts by a mis-click.
        //
        // What varies per item is what genuinely varies: quantity, rate,
        // whether it carries VAT, whether tax is withheld, and whether it is
        // also a kitchen ingredient. A bill of milk and mobile data has one
        // exempt line and one standard line on the same invoice.
        $billCount = max(3, count($purchaseBills ?? []) + 1);
        $itemRowsPerBill = 6;
        $gridDefaultDate = $todayInFy ?? date('Y-m-d');
        $marksIngredients = column_exists('inventory_items', 'is_ingredient');
        $gridItemOptions = static function () use ($items): string {
            $html = '<option value="">Select item…</option>';
            foreach ($items as $gridItem) {
                $html .= '<option value="' . (int) $gridItem['id'] . '"'
                    . ' data-unit="' . e((string) $gridItem['unit']) . '"'
                    . ' data-rate="' . e(number_format((float) $gridItem['purchase_rate'], 2, '.', '')) . '">'
                    . e($gridItem['sku'] . ' — ' . $gridItem['name']) . '</option>';
            }

            return $html;
        };
        $gridSupplierOptions = static function () use ($purchaseParties): string {
            $html = '<option value="0">— none —</option>';
            foreach ($purchaseParties as $gridParty) {
                $html .= '<option value="' . (int) $gridParty['id'] . '">' . e((string) $gridParty['name']) . '</option>';
            }

            return $html;
        };
        $gridLedgerOptions = static function () use ($purchaseLedgers): string {
            $html = '<option value="0">— not set —</option>';
            foreach ($purchaseLedgers as $gridLedger) {
                $html .= '<option value="' . (int) $gridLedger['id'] . '">' . e($gridLedger['code'] . ' — ' . $gridLedger['name']) . '</option>';
            }

            return $html;
        };
        ?>
        <?php if (!empty($purchaseGridErrors)): ?>
            <div class="notice error" style="margin-bottom:12px">
                <strong>Nothing was recorded.</strong> Every line has to be right before any of them post:
                <ul style="margin:8px 0 0;padding-left:18px">
                    <?php foreach ($purchaseGridErrors as $gridProblem): ?><li><?= e((string) $gridProblem) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form method="post" id="purchaseBillForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
            <input type="hidden" name="action" value="record_purchase_batch">
            <?php if ($editBill !== null): ?>
                <input type="hidden" name="replace_bill_id" value="<?= (int) $editBillId ?>">
                <p class="mbw-pill tone-amber" style="display:block;margin:0 0 10px;padding:10px 12px;line-height:1.5">
                    <?= icon('tasks') ?> Editing <strong><?= e((string) ($editBill['voucher_no'] ?: 'a draft entry')) ?></strong><?= (string) ($editBill['reference_no'] ?? '') !== '' ? ' — bill ' . e((string) $editBill['reference_no']) : '' ?>.
                    Recording it <strong>replaces</strong> that entry: the old stock movements and its accounting entry are removed and these are written in their place.
                    <a href="<?= e(url(inv_back_url('#movement-purchase-entries'))) ?>" style="margin-left:6px">Cancel and leave it as it is</a>
                </p>
            <?php endif; ?>
            <div style="overflow-x:auto"><table class="mbw-grid-table" id="purchaseBills">
                <thead><tr>
                    <th>Posting date</th>
                    <th>Supplier inv. date</th>
                    <th>Movement</th>
                    <th>Bill reference</th>
                    <th>Supplier</th>
                    <th style="text-align:center">Items</th>
                    <th class="is-numeric">Qty</th>
                    <th class="is-numeric">Amount</th>
                    <th class="is-numeric">VAT</th>
                    <th class="is-numeric">TDS</th>
                    <th>VAT ledger (Dr)</th>
                    <th>TDS ledger (Cr)</th>
                    <th>Notes</th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php for ($billIndex = 0; $billIndex < $billCount; $billIndex++): ?>
                    <?php $bill = $purchaseBills[$billIndex] ?? []; ?>
                    <tr class="inv-bill-row" data-bill="<?= $billIndex ?>">
                        <td><input type="date" name="bills[<?= $billIndex ?>][transaction_date]" value="<?= e((string) ($bill['transaction_date'] ?? ($billIndex === 0 ? $gridDefaultDate : ''))) ?>" style="width:150px"></td>
                        <td><input type="date" name="bills[<?= $billIndex ?>][supplier_invoice_date]" value="<?= e((string) ($bill['supplier_invoice_date'] ?? '')) ?>" style="width:150px"></td>
                        <td><select name="bills[<?= $billIndex ?>][movement]" style="min-width:130px">
                            <?php foreach (inv_purchase_batch_types() as $billType => $billTypeLabel): ?>
                                <option value="<?= e($billType) ?>" <?= (string) ($bill['movement'] ?? 'purchase') === $billType ? 'selected' : '' ?>><?= e($billTypeLabel) ?></option>
                            <?php endforeach; ?>
                        </select></td>
                        <td><input type="text" name="bills[<?= $billIndex ?>][ref_no]" maxlength="80" value="<?= e((string) ($bill['ref_no'] ?? '')) ?>" placeholder="Bill no." style="width:120px"></td>
                        <td><?php $billSupplier = shared_options('inv-purchase-suppliers', $gridSupplierOptions, (string) ($bill['supplier_party_id'] ?? '')); ?>
                            <select name="bills[<?= $billIndex ?>][supplier_party_id]"<?= $billSupplier['fill'] ? ' data-fill-from="inv-purchase-suppliers"' : '' ?> style="min-width:160px"><?= $billSupplier['html'] ?></select></td>
                        <td style="text-align:center">
                            <button type="button" class="button secondary inv-bill-open" data-bill="<?= $billIndex ?>" style="min-height:30px;padding:3px 12px">
                                <?= icon('box') ?> <span class="inv-bill-count">0</span> item(s)
                            </button>
                        </td>
                        <td class="is-numeric inv-bill-qty" style="color:var(--mbw-muted)">—</td>
                        <td class="is-numeric inv-bill-amount" style="color:var(--mbw-muted)">—</td>
                        <td class="is-numeric inv-bill-vat" style="color:var(--mbw-muted)">—</td>
                        <td class="is-numeric inv-bill-tds" style="color:var(--mbw-muted)">—</td>
                        <td><?php $billVatLedger = shared_options('inv-purchase-ledgers', $gridLedgerOptions, (string) ($bill['vat_ledger_id'] ?? '')); ?>
                            <select name="bills[<?= $billIndex ?>][vat_ledger_id]"<?= $billVatLedger['fill'] ? ' data-fill-from="inv-purchase-ledgers"' : '' ?> style="min-width:160px"><?= $billVatLedger['html'] ?></select></td>
                        <td><?php $billTdsLedger = shared_options('inv-purchase-ledgers', $gridLedgerOptions, (string) ($bill['tds_ledger_id'] ?? '')); ?>
                            <select name="bills[<?= $billIndex ?>][tds_ledger_id]"<?= $billTdsLedger['fill'] ? ' data-fill-from="inv-purchase-ledgers"' : '' ?> style="min-width:160px"><?= $billTdsLedger['html'] ?></select></td>
                        <td><input type="text" name="bills[<?= $billIndex ?>][notes]" maxlength="255" value="<?= e((string) ($bill['notes'] ?? '')) ?>" style="width:130px"></td>
                        <td><button type="button" class="button secondary jw-line-remove mbw-delete-action inv-bill-clear" title="Delete this bill and its items" aria-label="Delete this bill and its items"><?= icon('trash') ?></button></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
                <tfoot><tr>
                    <th colspan="6" style="text-align:right">All bills</th>
                    <th class="is-numeric" id="purchaseAllQty">0</th>
                    <th class="is-numeric" id="purchaseAllAmount">0.00</th>
                    <th class="is-numeric" id="purchaseAllVat">0.00</th>
                    <th class="is-numeric" id="purchaseAllTds">0.00</th>
                    <th colspan="4"><span id="purchaseAllPayable" style="color:var(--mbw-muted)"></span></th>
                </tr></tfoot>
            </table></div>

            <?php // One popup per bill. The fields live in the form the whole time,
                  // so opening and closing changes nothing about what will post. ?>
            <?php for ($billIndex = 0; $billIndex < $billCount; $billIndex++): ?>
                <?php $billItems = (array) ($purchaseBills[$billIndex]['items'] ?? []); ?>
                <dialog class="inv-bill-dialog" id="invBillDialog<?= $billIndex ?>">
                    <div class="inv-bill-dialog-head">
                        <div class="inv-bill-dialog-title">
                            <span class="inv-bill-dialog-icon" aria-hidden="true"><?= icon('box') ?></span>
                            <span><strong>Bill items <span class="inv-dialog-ref"></span></strong><small>Add the products and tax treatment shown on the supplier's invoice.</small></span>
                        </div>
                        <button type="button" class="button inv-bill-close"><?= icon('badge-check') ?> Done</button>
                    </div>
                    <div class="inv-bill-dialog-body">
                        <div class="inv-item-grid-scroll"><table class="mbw-grid-table inv-item-grid<?= $marksIngredients ? ' has-ingredient' : '' ?>" data-bill="<?= $billIndex ?>">
                            <colgroup>
                                <col class="inv-col-item"><col class="inv-col-uom"><col class="inv-col-qty"><col class="inv-col-rate">
                                <col class="inv-col-amount"><col class="inv-col-vat-control"><col class="inv-col-vat-amount">
                                <col class="inv-col-tds-control"><col class="inv-col-tds-rate">
                                <?php if ($marksIngredients): ?><col class="inv-col-ingredient"><?php endif; ?>
                                <col class="inv-col-notes"><col class="inv-col-action">
                            </colgroup>
                            <thead><tr>
                                <th>Item</th>
                                <th>UoM</th>
                                <th class="is-numeric">Quantity</th>
                                <th class="is-numeric">Rate</th>
                                <th class="is-numeric">Amount</th>
                                <th><span class="inv-grid-head-stack"><span>VAT</span><label class="inv-grid-check-all"><input type="checkbox" class="inv-item-vatall" checked><span>All</span></label></span></th>
                                <th class="is-numeric">VAT</th>
                                <th><span class="inv-grid-head-stack"><span>TDS</span><label class="inv-grid-check-all"><input type="checkbox" class="inv-item-tdsall"><span>All</span></label></span></th>
                                <th class="is-numeric">TDS %</th>
                                <?php if ($marksIngredients): ?><th class="inv-grid-ingredient">Ingredient</th><?php endif; ?>
                                <th class="inv-grid-notes">Notes</th>
                                <th></th>
                            </tr></thead>
                            <tbody>
                            <?php for ($itemIndex = 0; $itemIndex < max($itemRowsPerBill, count($billItems) + 1); $itemIndex++): ?>
                                <?php
                                $line = $billItems[$itemIndex] ?? [];
                                $lineVatOn = array_key_exists('vat_applicable', $line) ? !empty($line['vat_applicable']) : true;
                                $lineTdsOn = !empty($line['tds_applicable']);
                                $lineName = 'bills[' . $billIndex . '][items][' . $itemIndex . ']';
                                ?>
                                <tr class="inv-item-row">
                                    <td><?php $lineItemField = shared_options('inv-purchase-items', $gridItemOptions, (string) ($line['item_id'] ?? '')); ?>
                                        <select name="<?= e($lineName) ?>[item_id]" class="inv-grid-item"<?= $lineItemField['fill'] ? ' data-fill-from="inv-purchase-items"' : '' ?> style="min-width:220px"><?= $lineItemField['html'] ?></select></td>
                                    <td><input type="text" class="inv-grid-uom" value="" readonly tabindex="-1" style="width:70px;background:transparent;border:0;color:var(--mbw-muted)"></td>
                                    <td class="is-numeric"><input type="number" step="0.001" min="0" name="<?= e($lineName) ?>[quantity]" class="inv-grid-qty" value="<?= e((string) ($line['quantity'] ?? '')) ?>" style="width:90px;text-align:right"></td>
                                    <td class="is-numeric"><input type="number" step="0.01" min="0" name="<?= e($lineName) ?>[rate]" class="inv-grid-rate" value="<?= e((string) ($line['rate'] ?? '')) ?>" style="width:100px;text-align:right"></td>
                                    <td class="is-numeric"><input type="text" class="inv-grid-amount" value="" readonly tabindex="-1" style="width:110px;text-align:right;background:transparent;border:0"></td>
                                    <td style="text-align:center">
                                        <input type="hidden" name="<?= e($lineName) ?>[vat_applicable]" value="0">
                                        <input type="checkbox" class="inv-grid-vaton" name="<?= e($lineName) ?>[vat_applicable]" value="1" <?= $lineVatOn ? 'checked' : '' ?> title="Untick if this item is VAT exempt">
                                        <input type="number" step="0.01" min="0" max="100" name="<?= e($lineName) ?>[vat_rate]" class="inv-grid-vatrate" value="<?= e((string) ($line['vat_rate'] ?? '')) ?>" placeholder="<?= e(number_format((float) default_vat_rate(), 2, '.', '')) ?>%" title="Leave blank for the standard rate" style="width:70px;margin-top:4px;<?= $lineVatOn ? '' : 'display:none' ?>">
                                    </td>
                                    <td class="is-numeric"><input type="number" step="0.01" min="0" name="<?= e($lineName) ?>[vat_amount]" class="inv-grid-vat" value="<?= e((string) ($line['vat_amount'] ?? '')) ?>" style="width:100px;text-align:right" placeholder="auto"></td>
                                    <td style="text-align:center">
                                        <input type="hidden" name="<?= e($lineName) ?>[tds_applicable]" value="0">
                                        <input type="checkbox" class="inv-grid-tdson" name="<?= e($lineName) ?>[tds_applicable]" value="1" <?= $lineTdsOn ? 'checked' : '' ?> title="Tick if tax is withheld on this item">
                                    </td>
                                    <td class="is-numeric"><input type="number" step="0.01" min="0" max="100" name="<?= e($lineName) ?>[tds_rate]" class="inv-grid-tdsrate" value="<?= e((string) ($line['tds_rate'] ?? '')) ?>" style="width:80px;text-align:right"></td>
                                    <?php if ($marksIngredients): ?>
                                        <td class="inv-grid-ingredient"><input type="checkbox" name="<?= e($lineName) ?>[mark_ingredient]" value="1" <?= !empty($line['mark_ingredient']) ? 'checked' : '' ?> title="Also make this item available to recipes"></td>
                                    <?php endif; ?>
                                    <td class="inv-grid-notes"><input type="text" name="<?= e($lineName) ?>[notes]" maxlength="255" value="<?= e((string) ($line['notes'] ?? '')) ?>"></td>
                                    <td><button type="button" class="button secondary jw-line-remove mbw-delete-action inv-item-clear" title="Delete this item from the bill" aria-label="Delete this item from the bill"><?= icon('trash') ?></button></td>
                                </tr>
                            <?php endfor; ?>
                            </tbody>
                            <tfoot><tr>
                                <th colspan="4" style="text-align:right">Bill total</th>
                                <th class="is-numeric inv-dialog-amount">0.00</th>
                                <th></th>
                                <th class="is-numeric inv-dialog-vat">0.00</th>
                                <th colspan="<?= $marksIngredients ? 5 : 4 ?>"></th>
                            </tr></tfoot>
                        </table></div>
                        <div class="inv-bill-dialog-actions">
                            <button type="button" class="button secondary inv-item-add" data-bill="<?= $billIndex ?>"><?= icon('plus') ?>Add item</button>
                            <button type="button" class="button inv-bill-close"><?= icon('badge-check') ?>Done</button>
                        </div>
                    </div>
                </dialog>
            <?php endfor; ?>

            <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
                <button type="submit"><?= icon('badge-check') ?>Record all bills</button>
                <button type="button" class="secondary" id="purchaseAddBill"><?= icon('plus') ?>Add bill</button>
            </div>
            <?php // The last field in the form, and the only reason it exists.
                  // PHP stops reading a POST at max_input_vars and DISCARDS the
                  // rest without a word -- no error, no warning, no entry in
                  // any log. A long bill would post with its tail simply
                  // missing, which reads as "it will not let me add more
                  // items". Being last, this arrives only if everything before
                  // it did; the handler refuses the whole grid when it does
                  // not, so a truncated bill is never half-recorded.
                  // The submit handler re-appends it, because a bill added
                  // after page load is cloned onto the end of the form. ?>
            <input type="hidden" name="grid_end" id="purchaseGridEnd" value="1">
        </form>
    </details>
    <?php endif; ?>


    <?php
    // Purchase and opening entries this company has prepared or posted, ONE
    // ROW PER BILL, drafts first because they are the ones still waiting on
    // somebody. A supplier's invoice for twelve items is one entry with twelve
    // item lines, not twelve entries, so this reads against the paper.
    //
    // Read only on the page that shows them. Every task used to pay for this,
    // including the ones that never mention a purchase.
    $purBills = [];
    $purMergePlan = [];
    if ($invShows('movement-purchase-entries')) {
        $purBills = inv_purchase_bill_list($companyId, 25);
        // Bills that were entered one voucher per item, before a bill knew how
        // to stay in one piece. Read-only: nothing moves until somebody asks.
        $purMergePlan = inv_purchase_bill_merge_plan($companyId);
    }
    ?>
    <?php if ($invShows('movement-purchase-entries')): ?>
    <details class="feature-disclosure" id="movement-purchase-entries" <?= array_filter($purBills, static fn (array $r): bool => (string) $r['status'] === 'draft') !== [] ? 'open' : '' ?>>
        <summary><span><strong><?= icon('tasks') ?>Purchase entries</strong></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>

        <?php if ($purMergePlan !== []): ?>
            <?php
            $mergeVouchers = 0;
            $mergeItems = 0;
            foreach ($purMergePlan as $mergeRow) { $mergeVouchers += (int) $mergeRow['vouchers']; $mergeItems += (int) $mergeRow['items']; }
            ?>
            <div class="inv-merge-notice">
                <strong><?= icon('tasks') ?> <?= count($purMergePlan) ?> bill(s) were entered one voucher per item.</strong>
                <p style="margin:6px 0 0;font-size:12.5px;color:var(--mbw-muted)">
                    <?= (int) $mergeVouchers ?> vouchers carry <?= (int) $mergeItems ?> items that belong to <?= count($purMergePlan) ?> invoice(s).
                    Merging gathers each bill into a single entry: the item lines stay one per item, the supplier's credit and the VAT are stated once, and
                    <strong>the figures are carried across rather than recalculated</strong> — the totals in the ledger do not move. The absorbed voucher numbers are written into the surviving entry's narration, so the gap they leave in the series can be read back.
                </p>
                <details style="margin-top:10px">
                    <summary style="cursor:pointer;font-weight:600;font-size:12.5px">Preview what would be merged</summary>
                    <div class="rc-table-scroll" style="margin-top:8px"><table class="rc-table">
                        <thead><tr><th>Bill</th><th>Supplier</th><th>Date</th><th>Status</th><th class="align-right">Vouchers</th><th class="align-right">Items</th><th class="align-right">Total</th><th>Keeps</th><th>Absorbs</th></tr></thead>
                        <tbody>
                        <?php foreach ($purMergePlan as $mergeRow): ?>
                            <tr>
                                <td><strong><?= e((string) $mergeRow['ref_no']) ?></strong></td>
                                <td><?= e((string) ($mergeRow['party_name'] ?: '—')) ?></td>
                                <td><?= e((string) $mergeRow['date']) ?></td>
                                <td><span class="mbw-pill <?= (string) $mergeRow['status'] === 'draft' ? 'tone-gray' : 'tone-blue' ?>"><?= e((string) $mergeRow['status']) ?></span></td>
                                <td class="align-right"><?= (int) $mergeRow['vouchers'] ?> &rarr; 1</td>
                                <td class="align-right"><?= (int) $mergeRow['items'] ?></td>
                                <td class="align-right"><?= e(number_format((float) $mergeRow['total'], 2)) ?></td>
                                <td><?= e((string) ($mergeRow['keep_no'] ?: 'the first draft')) ?></td>
                                <td style="max-width:280px"><span style="color:var(--mbw-muted);font-size:11.5px"><?= e(implode(', ', array_filter($mergeRow['absorb_nos'])) ?: 'the other drafts') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </details>
                <?php if (user_can_do('accounting', 'post')): ?>
                    <form method="post" style="margin-top:10px" onsubmit="return confirm('Merge these bills into one entry each? The figures are carried across, so nothing in the ledger changes value \u2014 the duplicate vouchers are absorbed and removed.');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
                        <input type="hidden" name="action" value="merge_purchase_bills">
                        <button type="submit"><?= icon('badge-check') ?>Merge <?= count($purMergePlan) ?> bill(s) into one entry each</button>
                    </form>
                <?php else: ?>
                    <p style="margin:8px 0 0;font-size:12px;color:var(--mbw-muted)">Merging them needs the <strong>accounting · post</strong> permission.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($purBills === []): ?>
            <p style="color:var(--mbw-muted);padding:12px">No purchase or opening entries yet. Record one above and it appears here.</p>
        <?php else: ?>
        <div class="rc-table-scroll"><table class="rc-table">
            <thead><tr><th>Voucher</th><th>Items</th><th>Dates</th><th>Entry</th><th class="align-right">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($purBills as $pb): ?>
                    <?php
                    $peDraft = (string) $pb['status'] === 'draft';
                    $pbId = (int) $pb['id'];
                    ?>
                    <tr>
                        <td><?= $peDraft ? '<span style="color:var(--mbw-muted)">not numbered yet</span>' : e((string) $pb['voucher_no']) ?>
                            <?php if (($pb['reference_no'] ?? '') !== ''): ?><br><strong>Bill <?= e((string) $pb['reference_no']) ?></strong><?php endif; ?>
                            <?php if (($pb['party_name'] ?? '') !== ''): ?><br><span style="color:var(--mbw-muted)"><?= e((string) $pb['party_name']) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <span class="mbw-pill tone-blue"><?= (int) $pb['item_count'] ?> item<?= (int) $pb['item_count'] === 1 ? '' : 's' ?></span>
                            <?php foreach ($pb['items'] as $pbItem): ?>
                                <div style="margin-top:4px"><?= e((string) $pbItem['sku']) ?>
                                    <span style="color:var(--mbw-muted)"><?= e((string) $pbItem['item_name']) ?></span><br>
                                    <span style="color:var(--mbw-muted)"><?= e(number_format((float) $pbItem['qty_in'] + (float) $pbItem['qty_out'], 3)) ?>
                                        @ <?= e(number_format((float) $pbItem['rate'], 2)) ?> (<?= e((string) $pbItem['transaction_type']) ?>)</span>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td><span style="color:var(--mbw-muted)">Bought</span> <?= e((string) ($pb['voucher_date'] ?? '—')) ?><br>
                            <span style="color:var(--mbw-muted)">Posting</span> <?= e((string) ($pb['posting_date'] ?? '—')) ?>
                        </td>
                        <td>
                            <details class="inv-entry-preview">
                                <summary><?= icon('documents') ?>Preview entry (<?= count($pb['lines']) ?> line<?= count($pb['lines']) === 1 ? '' : 's' ?>)</summary>
                                <div class="inv-entry-preview-body">
                                    <?php $prevDr = 0.0; $prevCr = 0.0; ?>
                                    <?php foreach ($pb['lines'] as $pl): ?>
                                        <?php if ((string) $pl['entry_type'] === 'debit') { $prevDr += (float) $pl['amount']; } else { $prevCr += (float) $pl['amount']; } ?>
                                        <div class="inv-entry-line<?= (string) $pl['entry_type'] === 'credit' ? ' is-credit' : '' ?>">
                                            <span class="inv-entry-side"><?= (string) $pl['entry_type'] === 'debit' ? 'Dr' : 'Cr' ?></span>
                                            <span class="inv-entry-ledger"><?= e((string) $pl['ledger_name']) ?></span>
                                            <span class="inv-entry-amount"><?= e(number_format((float) $pl['amount'], 2)) ?></span>
                                            <?php if (($pl['memo'] ?? '') !== ''): ?><span class="inv-entry-memo"><?= e((string) $pl['memo']) ?></span><?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="inv-entry-line inv-entry-foot">
                                        <span class="inv-entry-side"></span>
                                        <span class="inv-entry-ledger"><?= abs($prevDr - $prevCr) < 0.005 ? 'Balanced' : 'OUT BY ' . e(number_format(abs($prevDr - $prevCr), 2)) ?></span>
                                        <span class="inv-entry-amount"><?= e(number_format($prevDr, 2)) ?></span>
                                        <span class="inv-entry-memo"><?= e((string) ($pb['narration'] ?? '')) ?></span>
                                    </div>
                                </div>
                            </details>
                        </td>
                        <td class="align-right"><?= e(number_format((float) $pb['total_amount'], 2)) ?></td>
                        <td><span class="mbw-pill <?= $peDraft ? 'tone-gray' : 'tone-blue' ?>"><?= $peDraft ? 'draft' : 'posted' ?></span>
                            <?php if (!$peDraft): ?><br><span style="color:var(--mbw-muted);font-size:11.5px">in the books</span><?php endif; ?>
                        </td>
                        <td>
                            <div class="inv-bill-actions">
                                <?php if ($peDraft): ?>
                                    <form method="post" onsubmit="return confirm('Post this entry to the ledger? It will be given a voucher number.');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
                                        <input type="hidden" name="action" value="post_movement_draft">
                                        <input type="hidden" name="voucher_id" value="<?= $pbId ?>">
                                        <button type="submit">Post it</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (user_can_do('inventory', 'create')): ?>
                                    <a class="button secondary" href="<?= e(url(inv_back_url('edit_bill=' . $pbId) . '#movement-purchase')) ?>"
                                       title="Open this bill in the purchase form; saving replaces it">Edit</a>
                                <?php endif; ?>
                                <?php if ((string) ($currentUser['role'] ?? '') === 'admin'): ?>
                                    <form method="post" onsubmit="return confirm('Delete this whole bill? Its accounting entry, its <?= (int) $pb['item_count'] ?> stock movement(s) and the value they carried are removed, and the cost layers are rebuilt as if the bill had never been entered. This cannot be undone.');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
                                        <input type="hidden" name="action" value="delete_purchase_bill">
                                        <input type="hidden" name="voucher_id" value="<?= $pbId ?>">
                                        <button type="submit" class="secondary mbw-delete-action">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </details>
    <?php endif; ?>

    <?php if ($invShows('movement-sale')): ?>
    <details class="feature-disclosure" id="movement-sale">
        <summary><span><strong><?= icon('tasks') ?>Record Sale / Sales Return</strong><small>Manual, non-invoiced stock-outs — inventory-sourced invoices auto-post their own sale movement via invoice.php.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
        <form method="post" class="workspace-form-grid" id="saleMovementForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
            <input type="hidden" name="action" value="record_movement">
            <?php $moveOpts = shared_options('inv-move-items', $invMoveItemOptions, (string) ($moveItemId ?: '')); ?>
            <label>Item<select name="item_id" id="saleMovItem" required<?= $moveOpts['fill'] ? ' data-fill-from="inv-move-items"' : '' ?>><?= $moveOpts['html'] ?></select></label>
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
    <?php endif; ?>

    <?php if ($invShows('movement-adjust')): ?>
    <details class="feature-disclosure" id="movement-adjust">
        <summary><span><strong><?= icon('tasks') ?>Adjustments &amp; Write-offs</strong><small>Stock count corrections, write-offs, damage, and expiry.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
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
    <details class="feature-disclosure" id="movement-transfer">
        <summary><span><strong><?= icon('tasks') ?>Warehouse / Departmental Transfer</strong><small>Move stock between locations — quantity only, no GL impact.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
            <input type="hidden" name="action" value="record_movement">
            <?php $moveOpts = shared_options('inv-move-items', $invMoveItemOptions, (string) ($moveItemId ?: '')); ?>
            <label>Item<select name="item_id" required<?= $moveOpts['fill'] ? ' data-fill-from="inv-move-items"' : '' ?>><?= $moveOpts['html'] ?></select></label>
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

    <?php endif; ?>
    <?php if ($inventoryProfile['show_manufacturing'] && $invView === 'manufacturing'): ?>
        <?php if ($invShows('manufacturing')): ?>
        <details class="feature-disclosure" id="manufacturing" open>
            <summary><span><strong><?= icon('settings') ?>Production Order</strong><small>Start production (materials move to Work in Progress) or complete it in one step.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
            <form method="post" class="workspace-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?= $invTaskField ?? '' ?>
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
                <div class="workspace-span-2">
                    <strong style="font-size:13px;color:var(--mbw-heading)">Input materials</strong>
                    <p style="margin:4px 0 8px;color:var(--mbw-muted);font-size:12px">Add as many input lines as the order needs. Leave the rate blank to use the item's purchase rate automatically. Rows without an item are ignored.</p>
                    <div style="overflow-x:auto">
                    <table id="mo-input-lines">
                        <thead><tr><th>Input item</th><th class="is-numeric" style="width:150px">Quantity</th><th class="is-numeric" style="width:170px">Rate</th><th style="width:44px"></th></tr></thead>
                        <tbody>
                            <?php for ($i = 0; $i < 4; $i++): ?>
                                <tr>
                                    <?php // Four rows once meant four copies of the whole item master.
                                          // One list now, shared. See shared_options(). ?>
                                    <?php $inputOpts = shared_options('inv-input-items', $invItemOptions); ?>
                                    <td><select name="input_item_id[]"<?= $inputOpts['fill'] ? ' data-fill-from="inv-input-items"' : '' ?>><?= $inputOpts['html'] ?></select></td>
                                    <td class="is-numeric"><input type="number" step="0.001" min="0" name="input_quantity[]"></td>
                                    <td class="is-numeric"><input type="number" step="0.01" min="0" name="input_rate[]" placeholder="Auto: purchase rate"></td>
                                    <td><button type="button" class="button secondary mbw-delete-action" title="Delete this row" aria-label="Delete this row" onclick="var b=this.closest('tbody');if(b.rows.length>1){this.closest('tr').remove();}else{this.closest('tr').querySelectorAll('input').forEach(function(i){i.value='';});this.closest('tr').querySelector('select').selectedIndex=0;}"><?= icon('trash') ?></button></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                    </div>
                    <button type="button" class="button secondary" style="margin-top:6px" onclick="var t=document.querySelector('#mo-input-lines tbody');var r=t.rows[0].cloneNode(true);r.querySelectorAll('input').forEach(function(i){i.value='';});r.querySelector('select').selectedIndex=0;t.appendChild(r);">+ Add input row</button>
                </div>
                <label class="workspace-span-2">Notes<textarea name="notes"></textarea></label>
                <button type="submit"><?= icon('settings') ?>Save production order</button>
            </form>
        </details>
        <?php endif; ?>

        <?php if ($invShows('bom')): ?>
        <details class="feature-disclosure" id="bom">
            <summary><span><strong><?= icon('documents') ?>Bill of Materials</strong><small>Define the standard recipe (components, expected waste, standard costs) for variance reporting.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open / New</span></summary>
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
                    <p style="margin:4px 0 8px;color:var(--mbw-muted);font-size:12px">Add as many component lines as the recipe needs. Rows without a component are ignored.</p>
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
<?php if ($invShows('item-stock-summary')): ?>
<section class="mbw-card" data-collapsible id="item-stock-summary">
    <div class="mbw-card-head">
        <h2>Item Stock Summary<?= $lowOnly ? ' — low stock only' : '' ?>
            <small style="font-weight:400;color:var(--mbw-muted,#64748b)">(<?= (int) $invTotalItems ?> item<?= $invTotalItems === 1 ? '' : 's' ?>)</small></h2>
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
                        <a class="button secondary" href="<?= e(url('admin/accounting-inventory.php?edit_id=' . (int) $item['id'] . '#create-item')) ?>">Edit</a>
                        <a class="button secondary" href="<?= e(url('admin/accounting-inventory.php?move_item=' . (int) $item['id'] . '#movement-purchase')) ?>" title="Record a stock movement for this item">Move</a>
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
                    <td><?= e($movement['transaction_date']) ?></td><td><?= e($movement['sku'] . ' - ' . $movement['item_name']) ?></td>
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
                            <span class="mbw-pill tone-amber" title="Stock was recorded but no accounting entry was raised — the ledgers for this item are not mapped, so there is nothing to reverse. Map them in Ledger mapping, then post the gap from Stock Summary → Reconcile Stock ↔ General Ledger.">stock only</span>
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
<?php if ($inventoryProfile['show_manufacturing'] && $invView === 'manufacturing'): ?>
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

    <?php
    $varianceRows = table_exists('production_variances')
        ? db()->query("SELECT pv.*, mo.order_no FROM production_variances pv JOIN manufacturing_orders mo ON mo.id = pv.manufacturing_order_id WHERE pv.company_id = " . $companyId . " ORDER BY pv.id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC)
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
<?php // The purchase grid draws the same three lists on every row; one copy of
      // each goes down here and the rows take it on load. ?>
<?php if (isset($gridItemOptions)): ?>
<?= shared_options_template('inv-purchase-items', $gridItemOptions) ?>
<?= shared_options_template('inv-purchase-suppliers', $gridSupplierOptions) ?>
<?= shared_options_template('inv-purchase-ledgers', $gridLedgerOptions) ?>
<?php endif; ?>
<?php // Unguarded: it emits itself only when a stub on this page needs it. ?>
<?= shared_options_script() ?>
<?php if (isset($gridItemOptions)): ?>
<script>
// The purchase form is bills with items behind a popup, so the arithmetic runs
// in two places: inside a dialog, where a line's own amount and tax are worked
// out, and on the bill row behind it, which shows what the popup adds up to.
(function () {
    var purchaseForm = document.getElementById('purchaseBillForm');
    var billTable = document.getElementById('purchaseBills');
    if (!billTable) { return; }

    // The company's own rate, so a tenant on something other than 13% is not
    // quietly given 13 anyway.
    var STANDARD_VAT = <?= (float) default_vat_rate() ?>;
    var money = function (n) { return (Math.round(n * 100) / 100).toFixed(2); };

    function itemGridFor(billIndex) {
        return document.querySelector('.inv-item-grid[data-bill="' + billIndex + '"]');
    }
    function billRowFor(billIndex) {
        return billTable.querySelector('.inv-bill-row[data-bill="' + billIndex + '"]');
    }

    // Keep a blank line available even when the user removes every currently
    // shown item before choosing to add another.
    Array.prototype.forEach.call(document.querySelectorAll('.inv-item-grid'), function (grid) {
        var first = grid.tBodies[0] && grid.tBodies[0].rows[0];
        if (first) { grid._invItemTemplate = first.cloneNode(true); }
    });

    // One item line: its amount, its VAT, and what it withholds.
    function recalcItem(row) {
        var itemSelect = row.querySelector('.inv-grid-item');
        var qty = parseFloat((row.querySelector('.inv-grid-qty') || {}).value) || 0;
        var rate = parseFloat((row.querySelector('.inv-grid-rate') || {}).value) || 0;
        var amount = qty * rate;
        var amountCell = row.querySelector('.inv-grid-amount');
        if (amountCell) { amountCell.value = amount > 0 ? money(amount) : ''; }

        // The unit is the item's own, shown so a quantity is not typed against
        // the wrong measure.
        var chosen = itemSelect && itemSelect.selectedIndex >= 0 ? itemSelect.options[itemSelect.selectedIndex] : null;
        var uom = row.querySelector('.inv-grid-uom');
        if (uom) { uom.value = chosen ? (chosen.getAttribute('data-unit') || '') : ''; }

        // VAT is a tick: on means the line carries it, at the standard rate
        // unless a rate is typed beside the box. Off means exempt, and the rate
        // and the figure go with it.
        var vatOn = row.querySelector('.inv-grid-vaton');
        var rateInput = row.querySelector('.inv-grid-vatrate');
        var vatInput = row.querySelector('.inv-grid-vat');
        var applies = !vatOn || vatOn.checked;
        if (rateInput) { rateInput.style.display = applies ? '' : 'none'; }
        if (vatInput) {
            vatInput.readOnly = !applies;
            if (!applies) {
                vatInput.value = '';
            } else if (!vatInput.dataset.touched) {
                var typed = parseFloat(rateInput ? rateInput.value : '') || 0;
                var vatRate = typed > 0 ? typed : STANDARD_VAT;
                vatInput.value = amount > 0 && vatRate > 0 ? money(amount * vatRate / 100) : '';
            }
        }

        // TDS the other way round: off unless somebody ticks it.
        var tdsOn = row.querySelector('.inv-grid-tdson');
        var tdsRateInput = row.querySelector('.inv-grid-tdsrate');
        var tdsApplies = tdsOn ? tdsOn.checked : false;
        if (tdsRateInput) {
            tdsRateInput.readOnly = !tdsApplies;
            tdsRateInput.style.opacity = tdsApplies ? '' : '.45';
            if (!tdsApplies) { tdsRateInput.value = ''; }
        }

        return {
            qty: qty,
            amount: amount,
            vat: applies ? (parseFloat(vatInput ? vatInput.value : 0) || 0) : 0,
            tds: tdsApplies ? amount * (parseFloat(tdsRateInput ? tdsRateInput.value : 0) || 0) / 100 : 0,
            filled: (itemSelect && itemSelect.value !== '') || qty > 0 || rate > 0
        };
    }

    // A whole bill: every item in its popup, and the summary on the row behind.
    function recalcBill(billIndex) {
        var grid = itemGridFor(billIndex);
        var row = billRowFor(billIndex);
        if (!grid || !row) { return { amount: 0, vat: 0, tds: 0, qty: 0, count: 0 }; }

        var totals = { qty: 0, amount: 0, vat: 0, tds: 0, count: 0 };
        Array.prototype.forEach.call(grid.tBodies[0].rows, function (itemRow) {
            var sums = recalcItem(itemRow);
            if (!sums.filled) { return; }
            totals.count++;
            totals.qty += sums.qty;
            totals.amount += sums.amount;
            totals.vat += sums.vat;
            totals.tds += sums.tds;
        });

        var dialogAmount = grid.querySelector('.inv-dialog-amount');
        var dialogVat = grid.querySelector('.inv-dialog-vat');
        if (dialogAmount) { dialogAmount.textContent = money(totals.amount); }
        if (dialogVat) { dialogVat.textContent = money(totals.vat); }

        // What the row shows once the popup is shut: how many items are on the
        // bill, and what they come to.
        var countLabel = row.querySelector('.inv-bill-count');
        if (countLabel) { countLabel.textContent = String(totals.count); }
        [['inv-bill-qty', totals.qty], ['inv-bill-amount', totals.amount],
         ['inv-bill-vat', totals.vat], ['inv-bill-tds', totals.tds]].forEach(function (pair) {
            var cell = row.querySelector('.' + pair[0]);
            if (!cell) { return; }
            cell.textContent = totals.count === 0 ? '—' : money(pair[1]);
            cell.style.color = totals.count === 0 ? 'var(--mbw-muted)' : '';
        });
        return totals;
    }

    function recalcAll() {
        var all = { qty: 0, amount: 0, vat: 0, tds: 0 };
        Array.prototype.forEach.call(billTable.querySelectorAll('.inv-bill-row'), function (row) {
            var totals = recalcBill(row.getAttribute('data-bill'));
            all.qty += totals.qty;
            all.amount += totals.amount;
            all.vat += totals.vat;
            all.tds += totals.tds;
        });
        var set = function (id, value) {
            var cell = document.getElementById(id);
            if (cell) { cell.textContent = value; }
        };
        set('purchaseAllQty', money(all.qty));
        set('purchaseAllAmount', money(all.amount));
        set('purchaseAllVat', money(all.vat));
        set('purchaseAllTds', money(all.tds));
        var payable = document.getElementById('purchaseAllPayable');
        if (payable) {
            payable.textContent = all.amount > 0
                ? 'Including VAT: ' + money(all.amount + all.vat)
                + (all.tds > 0 ? ' · payable after withholding: ' + money(all.amount + all.vat - all.tds) : '')
                : '';
        }
    }

    // ------------------------------------------------------------ the popup
    document.addEventListener('click', function (event) {
        var open = event.target.closest ? event.target.closest('.inv-bill-open') : null;
        if (open) {
            var billIndex = open.getAttribute('data-bill');
            var dialog = document.getElementById('invBillDialog' + billIndex);
            if (!dialog) { return; }
            var row = billRowFor(billIndex);
            var reference = row ? row.querySelector('[name$="[ref_no]"]') : null;
            var label = dialog.querySelector('.inv-dialog-ref');
            if (label) { label.textContent = reference && reference.value ? reference.value : '(no reference yet)'; }
            recalcBill(billIndex);
            if (typeof dialog.showModal === 'function') { dialog.showModal(); } else { dialog.setAttribute('open', 'open'); }
            return;
        }
        var close = event.target.closest ? event.target.closest('.inv-bill-close') : null;
        if (close) {
            var owner = close.closest('dialog');
            if (!owner) { return; }
            if (typeof owner.close === 'function') { owner.close(); } else { owner.removeAttribute('open'); }
            recalcAll();
            return;
        }
        var addItem = event.target.closest ? event.target.closest('.inv-item-add') : null;
        if (addItem) {
            var grid = itemGridFor(addItem.getAttribute('data-bill'));
            if (!grid) { return; }
            var body = grid.tBodies[0];
            var last = body.rows[body.rows.length - 1];
            if (!grid._invItemTemplate && last) { grid._invItemTemplate = last.cloneNode(true); }
            var source = last || grid._invItemTemplate;
            if (!source) { return; }
            var copy = source.cloneNode(true);
            var nextItem = 0;
            Array.prototype.forEach.call(body.querySelectorAll('.inv-item-row [name*="[items]"]'), function (field) {
                var match = field.name.match(/\[items\]\[(\d+)\]/);
                if (match) { nextItem = Math.max(nextItem, parseInt(match[1], 10) + 1); }
            });
            Array.prototype.forEach.call(copy.querySelectorAll('[name]'), function (field) {
                field.name = field.name.replace(/\[items\]\[\d+\]/, '[items][' + nextItem + ']');
                if (field.type === 'checkbox') { field.checked = field.classList.contains('inv-grid-vaton'); }
                else if (field.type === 'hidden') { /* the tick's "no" answer — leave it */ }
                else if (field.tagName === 'SELECT') { field.selectedIndex = 0; }
                else { field.value = ''; }
                delete field.dataset.touched;
            });
            body.appendChild(copy);
            return;
        }
        var clearItem = event.target.closest ? event.target.closest('.inv-item-clear') : null;
        if (clearItem) {
            var itemRow = clearItem.closest('tr');
            if (itemRow) {
                itemRow.remove();
                recalcAll();
                return;
            }
            recalcAll();
            return;
        }
        var clearBill = event.target.closest ? event.target.closest('.inv-bill-clear') : null;
        if (clearBill) {
            // A bill and everything on it. The items live in the dialog, so
            // clearing the row alone would leave them behind to post.
            var billRow = clearBill.closest('tr');
            var index = billRow.getAttribute('data-bill');
            Array.prototype.forEach.call(billRow.querySelectorAll('input, select'), function (field) {
                if (field.tagName === 'SELECT') { field.selectedIndex = 0; } else { field.value = ''; }
            });
            var itemGrid = itemGridFor(index);
            if (itemGrid) {
                Array.prototype.forEach.call(itemGrid.querySelectorAll('input, select'), function (field) {
                    if (field.type === 'checkbox') { field.checked = field.classList.contains('inv-grid-vaton'); }
                    else if (field.type === 'hidden') { /* the tick's "no" answer — leave it */ }
                    else if (field.tagName === 'SELECT') { field.selectedIndex = 0; }
                    else if (!field.readOnly) { field.value = ''; }
                    delete field.dataset.touched;
                });
            }
            recalcAll();
        }
    });

    // Tick every VAT or TDS box on a bill, or clear them all.
    document.addEventListener('change', function (event) {
        var master = event.target;
        var isVat = master.classList && master.classList.contains('inv-item-vatall');
        var isTds = master.classList && master.classList.contains('inv-item-tdsall');
        if (!isVat && !isTds) { return; }
        var grid = master.closest('.inv-item-grid');
        if (!grid) { return; }
        Array.prototype.forEach.call(grid.querySelectorAll(isVat ? '.inv-grid-vaton' : '.inv-grid-tdson'), function (box) {
            box.checked = master.checked;
        });
        recalcAll();
    });

    document.addEventListener('input', function (event) {
        if (!event.target.closest || !event.target.closest('.inv-item-grid')) { return; }
        // A VAT figure typed by hand is the supplier's, and must not be
        // overwritten by the rate the next time anything else changes.
        if (event.target.classList.contains('inv-grid-vat')) {
            event.target.dataset.touched = event.target.value === '' ? '' : '1';
        }
        recalcAll();
    });
    document.addEventListener('change', function (event) {
        if (event.target.closest && event.target.closest('.inv-item-grid')) { recalcAll(); }
    });

    // Blank rows are not worth a submission slot.
    //
    // The grid always shows spares -- three bills, six item lines each -- and
    // every one of them used to be posted whether or not anybody typed in it:
    // roughly 240 fields spent on nothing, out of a server budget that is
    // commonly 1,000. The engine already treats an untouched line as a spare
    // rather than a mistake, so leaving them behind changes no outcome and
    // roughly quadruples how long a bill can be.
    //
    // Disabled fields are not submitted, and the disabling happens at submit
    // time, so nothing on screen changes and a refused grid comes back whole.
    if (purchaseForm) {
        purchaseForm.addEventListener('submit', function () {
            Array.prototype.forEach.call(purchaseForm.querySelectorAll('.inv-item-grid tbody tr'), function (row) {
                var picked = row.querySelector('.inv-grid-item');
                var qty = row.querySelector('.inv-grid-qty');
                var rate = row.querySelector('.inv-grid-rate');
                var empty = (!picked || !picked.value)
                    && (!qty || !parseFloat(qty.value))
                    && (!rate || !parseFloat(rate.value));
                if (!empty) { return; }
                Array.prototype.forEach.call(row.querySelectorAll('[name]'), function (field) { field.disabled = true; });
            });
            // A bill with no items left is a spare too, header and all.
            Array.prototype.forEach.call(purchaseForm.querySelectorAll('.inv-bill-row'), function (billRow) {
                var grid = itemGridFor(billRow.getAttribute('data-bill'));
                if (!grid) { return; }
                var live = grid.querySelectorAll('tbody tr [name]:not([disabled])');
                if (live.length > 0) { return; }
                Array.prototype.forEach.call(billRow.querySelectorAll('[name]'), function (field) { field.disabled = true; });
            });
            // Whatever was cloned on after it, the sentinel goes last.
            var sentinel = document.getElementById('purchaseGridEnd');
            if (sentinel) { purchaseForm.appendChild(sentinel); }
        });
    }

    var addBill = document.getElementById('purchaseAddBill');
    if (addBill) {
        addBill.addEventListener('click', function () {
            // A new bill needs its own popup as well as its own row, so the
            // pair is cloned together and renumbered.
            var rows = billTable.querySelectorAll('.inv-bill-row');
            var lastRow = rows[rows.length - 1];
            var nextIndex = rows.length;
            var rowCopy = lastRow.cloneNode(true);
            rowCopy.setAttribute('data-bill', String(nextIndex));
            Array.prototype.forEach.call(rowCopy.querySelectorAll('[name]'), function (field) {
                field.name = field.name.replace(/bills\[\d+\]/, 'bills[' + nextIndex + ']');
                if (field.tagName === 'SELECT') { field.selectedIndex = 0; } else { field.value = ''; }
            });
            var opener = rowCopy.querySelector('.inv-bill-open');
            if (opener) { opener.setAttribute('data-bill', String(nextIndex)); }
            lastRow.parentNode.appendChild(rowCopy);

            var lastDialog = document.getElementById('invBillDialog' + (nextIndex - 1));
            if (lastDialog) {
                var dialogCopy = lastDialog.cloneNode(true);
                dialogCopy.id = 'invBillDialog' + nextIndex;
                dialogCopy.removeAttribute('open');
                var copiedGrid = dialogCopy.querySelector('.inv-item-grid');
                if (copiedGrid) { copiedGrid.setAttribute('data-bill', String(nextIndex)); }
                Array.prototype.forEach.call(dialogCopy.querySelectorAll('[data-bill]'), function (el) {
                    el.setAttribute('data-bill', String(nextIndex));
                });
                Array.prototype.forEach.call(dialogCopy.querySelectorAll('[name]'), function (field) {
                    field.name = field.name.replace(/bills\[\d+\]/, 'bills[' + nextIndex + ']');
                    if (field.type === 'checkbox') { field.checked = field.classList.contains('inv-grid-vaton'); }
                    else if (field.type === 'hidden') { /* the tick's "no" answer — leave it */ }
                    else if (field.tagName === 'SELECT') { field.selectedIndex = 0; }
                    else { field.value = ''; }
                    delete field.dataset.touched;
                });
                lastDialog.parentNode.appendChild(dialogCopy);
            }
            recalcAll();
        });
    }

    recalcAll();
})();
</script>
<?php endif; ?>
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
