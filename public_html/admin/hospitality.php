<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/hospitality_engine.php';
require_once __DIR__ . '/../../app/hospitality_sales_posting.php';
require_once __DIR__ . '/../../app/hospitality_sales_workbook.php';
// The inventory ledger mapping is ONE shared table; this page shows it rather
// than keeping a restaurant copy of the same setting.
require_once __DIR__ . '/../../app/inventory_mapping.php';

// Server-side gate: books access + company context + client feature flag +
// hospitality.view. Direct URLs are denied when the flag is off — the hidden
// menu is never the only protection.
accounting_module_repair_database();
require_hospitality();

$company = current_company();
$fiscalYear = current_fiscal_year();
if (!$company || !$fiscalYear) {
    flash('error', 'Company and fiscal year context required.');
    redirect('admin/accounting-dashboard.php');
}
$companyId = (int) $company['id'];
$fiscalYearId = (int) $fiscalYear['id'];
$fyStart = (string) $fiscalYear['start_date'];
$fyEnd = (string) $fiscalYear['end_date'];
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$sym = site_currency_symbol();
$settings = hospitality_settings($companyId);
$canEdit = user_can_do('hospitality', 'edit');
$canCost = user_can_do('hospitality', 'create');
$canRecalc = user_can_do('hospitality', 'adjust');
$canExport = user_can_do('hospitality', 'export');

// Simplified layout: the Excel sales upload drives both posting and costing,
// so the invoice-mapping flow keeps working under the hood but its tabs are
// folded away. Old links keep landing somewhere sensible.
$allowedViews = ['dashboard', 'ingredients', 'menu-items', 'recipes', 'reports', 'sales-upload', 'sheet-editor', 'settings'];
$legacyViews = ['mapping' => 'sales-upload', 'costing' => 'sales-upload', 'gp' => 'reports'];
$view = (string) ($_GET['view'] ?? 'dashboard');
if (isset($legacyViews[$view]) && !isset($_GET['export'])) {
    redirect('admin/hospitality.php?view=' . $legacyViews[$view]);
}
if (!in_array($view, $allowedViews, true) && !isset($legacyViews[$view])) {
    $view = 'dashboard';
}
$canPost = user_can_do('hospitality', 'create');
$hasXlsx = class_exists('ZipArchive');

/** One of a fixed set, or the fallback — a query string never picks its own value. */
function jw_enum_like(?string $value, array $allowed, string $fallback): string
{
    $value = (string) $value;

    return in_array($value, $allowed, true) ? $value : $fallback;
}

// Uploaded day-sheets are held (web-inaccessible) between preview and post.
$salesUploadDir = __DIR__ . '/../uploads/hospitality-sales';

function hospitality_sales_prepare_dir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }
    foreach (glob($dir . '/*.{csv,xlsx}', GLOB_BRACE) ?: [] as $stale) {
        if (filemtime($stale) < time() - 6 * 3600) {
            @unlink($stale);
        }
    }
}

/**
 * One held sheet.
 *
 * An upload is now a PAIR — what was sold and how it was paid — so each token
 * can carry two files. $slot picks which: '' is the workbook (or the item-wise
 * sheet when the pair arrived as two CSVs), '-b' the second file.
 */
function hospitality_sales_stored_file(string $dir, string $token, string $slot = ''): ?array
{
    if (!preg_match('/^[a-f0-9]{40}$/', $token) || !in_array($slot, ['', '-b'], true)) {
        return null;
    }
    foreach (['xlsx', 'csv'] as $extension) {
        $path = $dir . '/' . $token . $slot . '.' . $extension;
        if (is_file($path)) {
            return ['path' => $path, 'extension' => $extension];
        }
    }

    return null;
}

/** Everything held under one token, removed together once it has been posted. */
function hospitality_sales_forget_upload(string $dir, string $token): void
{
    if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
        return;
    }
    foreach (['', '-b'] as $slot) {
        foreach (['xlsx', 'csv'] as $extension) {
            @unlink($dir . '/' . $token . $slot . '.' . $extension);
        }
    }
    @unlink($dir . '/' . $token . '.name');
}

// Template downloads for the daily sales sheet.
if ($view === 'sales-upload' && isset($_GET['template'])) {
    $template = (string) $_GET['template'];
    // One workbook holding BOTH sheets is the template worth handing out: the
    // pair is reconciled against each other, and two loose files invite
    // uploading a mismatched set.
    if ($template === 'xlsx' && $hasXlsx) {
        $bytes = hospitality_workbook_template_xlsx();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="daily-sales-template.xlsx"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }
    if ($template === 'csv' || $template === 'csv-invoices') {
        $which = $template === 'csv-invoices' ? 'invoices' : 'items';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="daily-sales-' . $which . '.csv"');
        echo hospitality_workbook_template_csv($which);
        exit;
    }
    redirect('admin/hospitality.php?view=sales-upload');
}

// Date filters are clamped into the selected fiscal year (rule 13).
$clampDate = static function (string $date) use ($fyStart, $fyEnd): string {
    if ($date === '' || $date < $fyStart) {
        return $fyStart;
    }
    return $date > $fyEnd ? $fyEnd : $date;
};
$todayInFy = $clampDate(date('Y-m-d'));
$rangeFrom = $clampDate(trim((string) ($_GET['from'] ?? date('Y-m-01'))));
$rangeTo = $clampDate(trim((string) ($_GET['to'] ?? $todayInFy)));
if ($rangeFrom > $rangeTo) {
    $rangeFrom = $rangeTo;
}

$disclaimer = 'Estimated and for management reference only. No accounting entry has been posted.';

// ---------------------------------------------------------------------------
// POST actions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $back = 'admin/hospitality.php?view=' . urlencode((string) ($_POST['back_view'] ?? $view));

    // Only the recipe side of an ingredient is editable here. Its code, name,
    // category, purchase unit and cost belong to the inventory item and are
    // maintained there; this screen holds what inventory has no opinion about.
    if ($action === 'save_ingredient_recipe') {
        require_permission('hospitality', 'edit');
        $rows = (array) ($_POST['recipe'] ?? []);
        $saved = 0;
        $problems = [];
        $stmt = db()->prepare('UPDATE hospitality_ingredients
            SET recipe_unit = :runit, conversion_factor = :conv, wastage_pct = :wastage, yield_pct = :yield, updated_by = :by
            WHERE id = :id AND company_id = :cid');
        foreach ($rows as $rowId => $row) {
            $rowId = (int) $rowId;
            if ($rowId <= 0) {
                continue;
            }
            $recipeUnit = trim((string) ($row['recipe_unit'] ?? '')) ?: 'Unit';
            $conversion = round((float) ($row['conversion_factor'] ?? 0), 4);
            $wastage = round((float) ($row['wastage_pct'] ?? 0), 2);
            $yield = round((float) ($row['yield_pct'] ?? 100), 2);
            $label = trim((string) ($row['label'] ?? ('#' . $rowId)));
            if ($conversion <= 0) {
                $problems[] = $label . ': the conversion must be greater than zero.';
                continue;
            }
            if ($wastage < 0 || $wastage >= 100) {
                $problems[] = $label . ': wastage must be between 0% and below 100%.';
                continue;
            }
            if ($yield <= 0 || $yield > 100) {
                $problems[] = $label . ': yield must be above 0% and at most 100%.';
                continue;
            }
            $stmt->execute([
                'id' => $rowId, 'cid' => $companyId,
                'runit' => mb_substr($recipeUnit, 0, 40), 'conv' => $conversion,
                'wastage' => $wastage, 'yield' => $yield, 'by' => $userId,
            ]);
            $saved++;
        }
        if ($problems !== []) {
            flash('error', implode(' ', array_slice($problems, 0, 5)));
        } else {
            log_activity('hospitality_ingredient', $companyId, 'recipe_fields_updated', $saved . ' ingredient recipe setting(s) updated.', $userId);
            flash('success', $saved . ' ingredient' . ($saved === 1 ? '' : 's') . ' updated.');
        }
        redirect($back);
    }

    if ($action === 'sync_ingredients') {
        require_permission('hospitality', 'edit');
        $syncResult = hospitality_sync_ingredients_from_inventory($companyId, $userId);
        flash('success', 'Ingredient list refreshed from inventory — '
            . $syncResult['created'] . ' added, ' . $syncResult['updated'] . ' updated, '
            . $syncResult['restored'] . ' restored, ' . $syncResult['retired'] . ' retired.');
        redirect($back);
    }

    if ($action === 'save_ingredient') {
        require_permission('hospitality', 'edit');
        $ingredientId = (int) ($_POST['ingredient_id'] ?? 0);
        // Ingredients come from inventory items now; this path only still
        // exists to edit one typed in before that was true.
        if ($ingredientId <= 0) {
            flash('error', 'Ingredients are created by ticking "Use as a recipe ingredient" on an inventory item, not here.');
            redirect($back);
        }
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $conversion = round((float) ($_POST['conversion_factor'] ?? 0), 4);
        $cost = round((float) ($_POST['purchase_cost'] ?? 0), 4);
        $wastage = round((float) ($_POST['wastage_pct'] ?? 0), 2);
        $yield = round((float) ($_POST['yield_pct'] ?? 100), 2);
        $effectiveDate = trim((string) ($_POST['effective_date'] ?? ''));
        if ($code === '' || $name === '' || $effectiveDate === '') {
            flash('error', 'Code, name, and effective date are required.');
            redirect($back);
        }
        if ($conversion <= 0) {
            flash('error', 'The unit-conversion factor must be greater than zero.');
            redirect($back);
        }
        if ($cost < 0) {
            flash('error', 'The reference cost cannot be negative.');
            redirect($back);
        }
        if ($wastage < 0 || $wastage >= 100) {
            flash('error', 'Wastage must be between 0% and below 100%.');
            redirect($back);
        }
        if ($yield <= 0 || $yield > 100) {
            flash('error', 'Yield must be above 0% and at most 100%.');
            redirect($back);
        }
        $params = [
            'company_id' => $companyId, 'code' => $code, 'name' => $name,
            'name_np' => trim((string) ($_POST['name_np'] ?? '')) ?: null,
            'category' => trim((string) ($_POST['category'] ?? '')) ?: null,
            'purchase_unit' => trim((string) ($_POST['purchase_unit'] ?? 'unit')) ?: 'unit',
            'recipe_unit' => trim((string) ($_POST['recipe_unit'] ?? 'unit')) ?: 'unit',
            'conversion_factor' => $conversion,
            'purchase_cost' => $cost,
            'cost_source' => (string) ($_POST['cost_source'] ?? '') === 'latest_purchase' ? 'latest_purchase' : 'manual',
            'effective_date' => $effectiveDate,
            'wastage_pct' => $wastage, 'yield_pct' => $yield,
            'active' => isset($_POST['active']) ? 1 : 0,
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];
        try {
            if ($ingredientId > 0) {
                $oldStmt = db()->prepare('SELECT purchase_cost FROM hospitality_ingredients WHERE id = :id AND company_id = :cid');
                $oldStmt->execute(['id' => $ingredientId, 'cid' => $companyId]);
                $oldCost = $oldStmt->fetchColumn();
                if ($oldCost === false) {
                    flash('error', 'Ingredient not found for this client.');
                    redirect($back);
                }
                $params['id'] = $ingredientId;
                $params['updated_by'] = $userId;
                db()->prepare('UPDATE hospitality_ingredients SET code = :code, name = :name, name_np = :name_np, category = :category,
                        purchase_unit = :purchase_unit, recipe_unit = :recipe_unit, conversion_factor = :conversion_factor,
                        purchase_cost = :purchase_cost, cost_source = :cost_source, effective_date = :effective_date,
                        wastage_pct = :wastage_pct, yield_pct = :yield_pct, active = :active, notes = :notes, updated_by = :updated_by
                    WHERE id = :id AND company_id = :company_id')->execute($params);
                if (abs((float) $oldCost - $cost) > 0.00004) {
                    db()->prepare('INSERT INTO hospitality_ingredient_costs (company_id, ingredient_id, purchase_cost, effective_date, source, created_by)
                            VALUES (:cid, :iid, :cost, :d, :src, :by)')
                        ->execute(['cid' => $companyId, 'iid' => $ingredientId, 'cost' => $cost, 'd' => $effectiveDate, 'src' => $params['cost_source'], 'by' => $userId]);
                    log_activity('hospitality_ingredient', $ingredientId, 'cost_changed', $code . ' reference cost changed to ' . number_format($cost, 4) . ' effective ' . $effectiveDate . '.', $userId);
                }
                log_activity('hospitality_ingredient', $ingredientId, 'updated', 'Ingredient ' . $code . ' updated.', $userId);
            } else {
                $params['created_by'] = $userId;
                db()->prepare('INSERT INTO hospitality_ingredients (company_id, code, name, name_np, category, purchase_unit, recipe_unit,
                        conversion_factor, purchase_cost, cost_source, effective_date, wastage_pct, yield_pct, active, notes, created_by)
                    VALUES (:company_id, :code, :name, :name_np, :category, :purchase_unit, :recipe_unit,
                        :conversion_factor, :purchase_cost, :cost_source, :effective_date, :wastage_pct, :yield_pct, :active, :notes, :created_by)')->execute($params);
                $ingredientId = (int) db()->lastInsertId();
                db()->prepare('INSERT INTO hospitality_ingredient_costs (company_id, ingredient_id, purchase_cost, effective_date, source, created_by)
                        VALUES (:cid, :iid, :cost, :d, :src, :by)')
                    ->execute(['cid' => $companyId, 'iid' => $ingredientId, 'cost' => $cost, 'd' => $effectiveDate, 'src' => $params['cost_source'], 'by' => $userId]);
                log_activity('hospitality_ingredient', $ingredientId, 'created', 'Ingredient ' . $code . ' created.', $userId);
            }
            flash('success', 'Ingredient saved (reference costing only — inventory is untouched).');
        } catch (Throwable $exception) {
            flash('error', (string) $exception->getCode() === '23000' ? 'That ingredient code already exists for this client.' : 'Could not save the ingredient.');
        }
        redirect($back);
    }

    if ($action === 'save_menu_item') {
        require_permission('hospitality', 'edit');
        $menuItemId = (int) ($_POST['menu_item_id'] ?? 0);
        // The menu is whatever the tills have sold. Adding one by hand creates
        // an item no sale will ever match, which then sits in the costing
        // exceptions forever looking like a mapping fault.
        if ($menuItemId <= 0) {
            flash('error', 'Menu items are created from the daily sales upload, not by hand — upload a sheet that sells the item and it will appear here.');
            redirect($back);
        }
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($code === '' || $name === '') {
            flash('error', 'Menu item code and name are required.');
            redirect($back);
        }
        $categories = ['Food', 'Beverage', 'Bakery', 'Bar', 'Catering', 'Accommodation Add-ons', 'Other'];
        $params = [
            'company_id' => $companyId, 'code' => $code, 'name' => $name,
            'name_np' => trim((string) ($_POST['name_np'] ?? '')) ?: null,
            'category' => in_array((string) ($_POST['category'] ?? ''), $categories, true) ? (string) $_POST['category'] : 'Food',
            'standard_price' => max(0.0, round((float) ($_POST['standard_price'] ?? 0), 2)),
            'unit_of_sale' => trim((string) ($_POST['unit_of_sale'] ?? 'plate')) ?: 'plate',
            'tax_inclusive' => isset($_POST['tax_inclusive']) ? 1 : 0,
            'active' => isset($_POST['active']) ? 1 : 0,
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];
        try {
            if ($menuItemId > 0) {
                $params['id'] = $menuItemId;
                $params['updated_by'] = $userId;
                db()->prepare('UPDATE hospitality_menu_items SET code = :code, name = :name, name_np = :name_np, category = :category,
                        standard_price = :standard_price, unit_of_sale = :unit_of_sale, tax_inclusive = :tax_inclusive,
                        active = :active, notes = :notes, updated_by = :updated_by
                    WHERE id = :id AND company_id = :company_id')->execute($params);
                log_activity('hospitality_menu_item', $menuItemId, 'updated', 'Menu item ' . $code . ' updated.', $userId);
            } else {
                $params['created_by'] = $userId;
                db()->prepare('INSERT INTO hospitality_menu_items (company_id, code, name, name_np, category, standard_price, unit_of_sale, tax_inclusive, active, notes, created_by)
                    VALUES (:company_id, :code, :name, :name_np, :category, :standard_price, :unit_of_sale, :tax_inclusive, :active, :notes, :created_by)')->execute($params);
                log_activity('hospitality_menu_item', (int) db()->lastInsertId(), 'created', 'Menu item ' . $code . ' created.', $userId);
            }
            flash('success', 'Menu item saved.');
        } catch (Throwable $exception) {
            flash('error', (string) $exception->getCode() === '23000' ? 'That menu item code already exists for this client.' : 'Could not save the menu item.');
        }
        redirect($back);
    }

    if ($action === 'save_recipe') {
        require_permission('hospitality', 'edit');
        $recipeId = (int) ($_POST['recipe_id'] ?? 0);
        $menuItemId = (int) ($_POST['menu_item_id'] ?? 0);
        $miStmt = db()->prepare('SELECT id FROM hospitality_menu_items WHERE id = :id AND company_id = :cid');
        $miStmt->execute(['id' => $menuItemId, 'cid' => $companyId]);
        if (!$miStmt->fetchColumn()) {
            flash('error', 'Pick a menu item of this client.');
            redirect($back);
        }
        $recipeName = trim((string) ($_POST['name'] ?? ''));
        $effectiveFrom = trim((string) ($_POST['effective_from'] ?? ''));
        $portions = round((float) ($_POST['portions'] ?? 0), 3);
        $prepWastage = round((float) ($_POST['prep_wastage_pct'] ?? 0), 2);
        if ($recipeName === '' || $effectiveFrom === '') {
            flash('error', 'Recipe name and effective-from date are required.');
            redirect($back);
        }
        if ($prepWastage < 0 || $prepWastage >= 100) {
            flash('error', 'Preparation wastage must be between 0% and below 100%.');
            redirect($back);
        }
        $params = [
            'company_id' => $companyId, 'menu_item_id' => $menuItemId,
            'code' => trim((string) ($_POST['code'] ?? '')) ?: null,
            'name' => $recipeName,
            'effective_from' => $effectiveFrom,
            'effective_to' => trim((string) ($_POST['effective_to'] ?? '')) ?: null,
            'yield_qty' => max(0.0, round((float) ($_POST['yield_qty'] ?? 1), 3)),
            'portions' => max(0.0, $portions),
            'portion_size' => trim((string) ($_POST['portion_size'] ?? '')) ?: null,
            'prep_wastage_pct' => $prepWastage,
            'packaging_cost' => max(0.0, round((float) ($_POST['packaging_cost'] ?? 0), 2)),
            'other_cost' => max(0.0, round((float) ($_POST['other_cost'] ?? 0), 2)),
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($recipeId > 0) {
                $lockStmt = $pdo->prepare('SELECT status FROM hospitality_recipes WHERE id = :id AND company_id = :cid LIMIT 1');
                $lockStmt->execute(['id' => $recipeId, 'cid' => $companyId]);
                $recipeStatus = (string) ($lockStmt->fetchColumn() ?: '');
                if ($recipeStatus === '') {
                    throw new RuntimeException('Recipe not found for this client.');
                }
                if ($recipeStatus !== 'draft') {
                    throw new RuntimeException('Only DRAFT recipes can be edited. Create a new version instead — history stays intact.');
                }
                $params['id'] = $recipeId;
                $params['updated_by'] = $userId;
                $pdo->prepare('UPDATE hospitality_recipes SET menu_item_id = :menu_item_id, code = :code, name = :name,
                        effective_from = :effective_from, effective_to = :effective_to, yield_qty = :yield_qty,
                        portions = :portions, portion_size = :portion_size, prep_wastage_pct = :prep_wastage_pct,
                        packaging_cost = :packaging_cost, other_cost = :other_cost, notes = :notes, updated_by = :updated_by
                    WHERE id = :id AND company_id = :company_id')->execute($params);
                $pdo->prepare('DELETE FROM hospitality_recipe_lines WHERE recipe_id = :rid')->execute(['rid' => $recipeId]);
            } else {
                $verStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM hospitality_recipes WHERE menu_item_id = :mid');
                $verStmt->execute(['mid' => $menuItemId]);
                $params['version'] = (int) $verStmt->fetchColumn();
                $params['created_by'] = $userId;
                $pdo->prepare('INSERT INTO hospitality_recipes (company_id, menu_item_id, code, name, version, effective_from, effective_to,
                        yield_qty, portions, portion_size, prep_wastage_pct, packaging_cost, other_cost, status, notes, created_by)
                    VALUES (:company_id, :menu_item_id, :code, :name, :version, :effective_from, :effective_to,
                        :yield_qty, :portions, :portion_size, :prep_wastage_pct, :packaging_cost, :other_cost, \'draft\', :notes, :created_by)')->execute($params);
                $recipeId = (int) $pdo->lastInsertId();
            }
            $ingredientIds = (array) ($_POST['line_ingredient'] ?? []);
            $quantities = (array) ($_POST['line_qty'] ?? []);
            $lineNotes = (array) ($_POST['line_notes'] ?? []);
            $insertLine = $pdo->prepare('INSERT INTO hospitality_recipe_lines (recipe_id, ingredient_id, quantity, unit, notes)
                SELECT :rid, i.id, :qty, i.recipe_unit, :notes FROM hospitality_ingredients i WHERE i.id = :iid AND i.company_id = :cid');
            foreach ($ingredientIds as $index => $rawIngredientId) {
                $iid = (int) $rawIngredientId;
                $qty = round((float) ($quantities[$index] ?? 0), 4);
                if ($iid <= 0 || $qty <= 0) {
                    continue;
                }
                $insertLine->execute(['rid' => $recipeId, 'qty' => $qty, 'notes' => trim((string) ($lineNotes[$index] ?? '')) ?: null, 'iid' => $iid, 'cid' => $companyId]);
            }
            $pdo->commit();
            log_activity('hospitality_recipe', $recipeId, 'saved', 'Recipe draft saved for menu item #' . $menuItemId . '.', $userId);
            flash('success', 'Recipe saved as draft. Review the cost sheet, then activate it.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Could not save the recipe: ' . $exception->getMessage());
        }
        redirect('admin/hospitality.php?view=recipes&recipe=' . $recipeId);
    }

    if ($action === 'recipe_status') {
        require_permission('hospitality', 'edit');
        $recipeId = (int) ($_POST['recipe_id'] ?? 0);
        $target = (string) ($_POST['target'] ?? '');
        $recipe = hospitality_recipe($recipeId);
        if (!$recipe || (int) $recipe['company_id'] !== $companyId) {
            flash('error', 'Recipe not found for this client.');
            redirect($back);
        }
        if ($target === 'activate') {
            $costCheck = hospitality_recipe_cost($recipeId, (string) $recipe['effective_from'], $settings);
            if ((float) $recipe['portions'] <= 0) {
                flash('error', 'A recipe with zero portions cannot be activated.');
                redirect('admin/hospitality.php?view=recipes&recipe=' . $recipeId);
            }
            if (!$costCheck['ok']) {
                flash('error', 'Fix these before activation: ' . implode(' | ', array_slice($costCheck['errors'], 0, 4)));
                redirect('admin/hospitality.php?view=recipes&recipe=' . $recipeId);
            }
            $pdo = db();
            $pdo->beginTransaction();
            try {
                // Supersede any overlapping active version: its effective_to is
                // closed the day before this one starts (history preserved).
                $pdo->prepare("UPDATE hospitality_recipes SET effective_to = DATE_SUB(:from, INTERVAL 1 DAY), updated_by = :by
                        WHERE menu_item_id = :mid AND status = 'active' AND id <> :id
                          AND (effective_to IS NULL OR effective_to >= :from2)")
                    ->execute(['from' => $recipe['effective_from'], 'by' => $userId, 'mid' => (int) $recipe['menu_item_id'], 'id' => $recipeId, 'from2' => $recipe['effective_from']]);
                $pdo->prepare("UPDATE hospitality_recipes SET status = 'active', updated_by = :by WHERE id = :id")
                    ->execute(['by' => $userId, 'id' => $recipeId]);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('error', 'Could not activate: ' . $exception->getMessage());
                redirect('admin/hospitality.php?view=recipes&recipe=' . $recipeId);
            }
            log_activity('hospitality_recipe', $recipeId, 'activated', 'Recipe v' . $recipe['version'] . ' activated for ' . $recipe['menu_item_name'] . ' from ' . $recipe['effective_from'] . ' (previous versions superseded, history preserved).', $userId);
            flash('success', 'Recipe activated. Old reports keep their snapshots.');
        } elseif ($target === 'archive') {
            db()->prepare("UPDATE hospitality_recipes SET status = 'archived', updated_by = :by WHERE id = :id AND company_id = :cid")
                ->execute(['by' => $userId, 'id' => $recipeId, 'cid' => $companyId]);
            log_activity('hospitality_recipe', $recipeId, 'archived', 'Recipe v' . $recipe['version'] . ' archived.', $userId);
            flash('success', 'Recipe archived (kept for history — never deleted).');
        } elseif ($target === 'duplicate') {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $verStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM hospitality_recipes WHERE menu_item_id = :mid');
                $verStmt->execute(['mid' => (int) $recipe['menu_item_id']]);
                $newVersion = (int) $verStmt->fetchColumn();
                $pdo->prepare("INSERT INTO hospitality_recipes (company_id, menu_item_id, code, name, version, effective_from, effective_to,
                        yield_qty, portions, portion_size, prep_wastage_pct, packaging_cost, other_cost, status, notes, created_by)
                    SELECT company_id, menu_item_id, code, name, :v, effective_from, NULL, yield_qty, portions, portion_size,
                        prep_wastage_pct, packaging_cost, other_cost, 'draft', notes, :by
                    FROM hospitality_recipes WHERE id = :id")->execute(['v' => $newVersion, 'by' => $userId, 'id' => $recipeId]);
                $newId = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO hospitality_recipe_lines (recipe_id, ingredient_id, quantity, unit, notes)
                    SELECT :new, ingredient_id, quantity, unit, notes FROM hospitality_recipe_lines WHERE recipe_id = :old')
                    ->execute(['new' => $newId, 'old' => $recipeId]);
                $pdo->commit();
                log_activity('hospitality_recipe', $newId, 'created', 'Recipe duplicated from v' . $recipe['version'] . ' as draft v' . $newVersion . '.', $userId);
                flash('success', 'New draft version v' . $newVersion . ' created from this recipe.');
                redirect('admin/hospitality.php?view=recipes&recipe=' . $newId);
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('error', 'Could not duplicate: ' . $exception->getMessage());
            }
        }
        redirect('admin/hospitality.php?view=recipes&recipe=' . $recipeId);
    }

    if ($action === 'save_mapping') {
        require_permission('hospitality', 'edit');
        $matchType = (string) ($_POST['match_type'] ?? 'description') === 'item' ? 'item' : 'description';
        $itemId = (int) ($_POST['sales_item_id'] ?? 0);
        $description = trim((string) ($_POST['sales_description'] ?? ''));
        $menuItemId = (int) ($_POST['menu_item_id'] ?? 0);
        $miStmt = db()->prepare('SELECT id FROM hospitality_menu_items WHERE id = :id AND company_id = :cid');
        $miStmt->execute(['id' => $menuItemId, 'cid' => $companyId]);
        if (!$miStmt->fetchColumn() || ($matchType === 'item' && $itemId <= 0) || ($matchType === 'description' && $description === '')) {
            flash('error', 'Pick a valid menu item and a sales item id or description.');
            redirect($back);
        }
        db()->prepare("INSERT INTO hospitality_sales_mappings (company_id, match_type, item_id, description_norm, menu_item_id, status, effective_from, effective_to, active, notes, created_by)
                VALUES (:cid, :mt, :item, :norm, :mid, 'mapped', :ef, :et, 1, :notes, :by)")
            ->execute([
                'cid' => $companyId, 'mt' => $matchType,
                'item' => $matchType === 'item' ? $itemId : null,
                'norm' => $matchType === 'description' ? hospitality_normalize_desc($description) : null,
                'mid' => $menuItemId,
                'ef' => trim((string) ($_POST['effective_from'] ?? '')) ?: null,
                'et' => trim((string) ($_POST['effective_to'] ?? '')) ?: null,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                'by' => $userId,
            ]);
        log_activity('hospitality_mapping', (int) db()->lastInsertId(), 'created', 'Sales mapping approved (' . ($matchType === 'item' ? 'item #' . $itemId : '"' . $description . '"') . ' → menu item #' . $menuItemId . '). Original sale untouched.', $userId);
        flash('success', 'Mapping saved. Recalculate affected dates (with a reason) to apply it to past costing.');
        redirect($back);
    }

    if ($action === 'ignore_sales_item') {
        require_permission('hospitality', 'edit');
        $description = trim((string) ($_POST['sales_description'] ?? ''));
        $itemId = (int) ($_POST['sales_item_id'] ?? 0);
        if ($description === '' && $itemId <= 0) {
            flash('error', 'Nothing to ignore.');
            redirect($back);
        }
        db()->prepare("INSERT INTO hospitality_sales_mappings (company_id, match_type, item_id, description_norm, menu_item_id, status, ignore_reason, active, created_by)
                VALUES (:cid, :mt, :item, :norm, NULL, 'ignored', :reason, 1, :by)")
            ->execute([
                'cid' => $companyId,
                'mt' => $itemId > 0 ? 'item' : 'description',
                'item' => $itemId > 0 ? $itemId : null,
                'norm' => $itemId > 0 ? null : hospitality_normalize_desc($description),
                'reason' => trim((string) ($_POST['ignore_reason'] ?? '')) ?: null,
                'by' => $userId,
            ]);
        log_activity('hospitality_mapping', (int) db()->lastInsertId(), 'ignored', 'Sales item ignored for costing (' . ($itemId > 0 ? 'item #' . $itemId : '"' . $description . '"') . '). Original sale untouched.', $userId);
        flash('success', 'Sales item marked as ignored for costing (the sale itself is unchanged).');
        redirect($back);
    }

    if ($action === 'generate_costing') {
        require_permission('hospitality', 'create');
        $date = $clampDate(trim((string) ($_POST['costing_date'] ?? '')));
        $result = hospitality_generate_costing($companyId, $fiscalYearId, $date, $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Costing generated for ' . $date . ': ' . (int) $result['costed'] . ' of ' . (int) $result['lines'] . ' sales lines costed. ' . $disclaimer
            : (string) ($result['error'] ?? 'Could not generate costing.'));
        redirect('admin/hospitality.php?view=costing&from=' . $date . '&to=' . $date);
    }

    if ($action === 'recalculate_costing') {
        require_permission('hospitality', 'adjust');
        $date = $clampDate(trim((string) ($_POST['costing_date'] ?? '')));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $result = hospitality_generate_costing($companyId, $fiscalYearId, $date, $userId, true, $reason);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Costing recalculated for ' . $date . ' — the old and new totals are kept in the audit history. ' . $disclaimer
            : (string) ($result['error'] ?? 'Could not recalculate.'));
        redirect('admin/hospitality.php?view=costing&from=' . $date . '&to=' . $date);
    }

    if ($action === 'save_settings') {
        require_permission('hospitality', 'edit');
        $old = $settings;
        db()->prepare('UPDATE hospitality_settings SET cost_source = :src, costing_basis = :basis,
                include_invoice_discount = :disc, net_of_vat = :vat, include_packaging = :pack, packaging_pct = :packp,
                include_kitchen_wastage = :kw, kitchen_wastage_pct = :kwp, include_other_variable = :ov, other_variable_pct = :ovp,
                cost_precision = :cp, display_precision = :dp, dashboard_range = :dr, updated_by = :by
            WHERE company_id = :cid')
            ->execute([
                'src' => (string) ($_POST['cost_source'] ?? '') === 'latest_purchase' ? 'latest_purchase' : 'manual',
                'basis' => (string) ($_POST['costing_basis'] ?? '') === 'calculation_date' ? 'calculation_date' : 'sale_date',
                'disc' => isset($_POST['include_invoice_discount']) ? 1 : 0,
                'vat' => isset($_POST['net_of_vat']) ? 1 : 0,
                'pack' => isset($_POST['include_packaging']) ? 1 : 0,
                'packp' => max(0.0, min(99.99, round((float) ($_POST['packaging_pct'] ?? 0), 2))),
                'kw' => isset($_POST['include_kitchen_wastage']) ? 1 : 0,
                'kwp' => max(0.0, min(99.99, round((float) ($_POST['kitchen_wastage_pct'] ?? 0), 2))),
                'ov' => isset($_POST['include_other_variable']) ? 1 : 0,
                'ovp' => max(0.0, min(99.99, round((float) ($_POST['other_variable_pct'] ?? 0), 2))),
                'cp' => max(0, min(4, (int) ($_POST['cost_precision'] ?? 2))),
                'dp' => max(0, min(4, (int) ($_POST['display_precision'] ?? 2))),
                'dr' => in_array((string) ($_POST['dashboard_range'] ?? ''), ['today', 'week', 'month', 'fiscal_year'], true) ? (string) $_POST['dashboard_range'] : 'month',
                'by' => $userId, 'cid' => $companyId,
            ]);
        log_activity('hospitality_settings', $companyId, 'updated', 'Hospitality settings updated.', $userId);
        log_field_changes('hospitality_settings', $companyId, $old, hospitality_settings($companyId), $companyId, $userId);
        flash('success', 'Hospitality settings saved (tenant-specific, audited).');
        redirect('admin/hospitality.php?view=settings');
    }

    // ONE form saves the whole ledger mapping: the default/fallback row, the
    // VAT settings, every existing category row (inline edits + active flag)
    // and an optional new category row — all together, all validated first.
    if ($action === 'save_inventory_mapping') {
        require_permission('hospitality', 'edit');
        try {
            inventory_mapping_save($companyId, (string) ($_POST['purpose'] ?? ''), (int) ($_POST['ledger_id'] ?? 0), $userId);
            flash('success', 'Inventory posting ledger saved.');
        } catch (Throwable $mapException) {
            flash('error', $mapException->getMessage());
        }
        redirect('admin/hospitality.php?view=settings');
    }

    if ($action === 'autocreate_inventory_mapping') {
        require_permission('hospitality', 'edit');
        $res = inventory_mapping_autocreate($companyId, $userId);
        if ($res['errors'] !== []) { flash('error', implode(' ', $res['errors'])); }
        flash($res['mapped'] === [] ? 'info' : 'success', $res['mapped'] === []
            ? 'Every inventory purpose was already mapped.'
            : count($res['created']) . ' opened, ' . count($res['mapped']) . ' mapped, ' . count($res['skipped']) . ' left as set.');
        redirect('admin/hospitality.php?view=settings');
    }

    if ($action === 'save_ledger_mapping') {
        require_permission('hospitality', 'edit');

        $checkLedger = static function (int $ledgerId, string $label) use ($companyId): ?int {
            if ($ledgerId <= 0) {
                return null;
            }
            if (hospitality_posting_ledger($companyId, $ledgerId) === null) {
                flash('error', $label . ' must be an active ledger of this company.');
                redirect($back);
            }
            return $ledgerId;
        };

        $defaultSales = $checkLedger((int) ($_POST['post_sales_ledger_id'] ?? 0), 'The default Sales ledger');
        $vatLedger = $checkLedger((int) ($_POST['post_vat_ledger_id'] ?? 0), 'The VAT payable ledger');
        // The receivable and discount ledgers are no longer on the form: the
        // two-sheet upload takes its debit from the invoice sheet and nets
        // discount into the sales credit. A field the form does not send KEEPS
        // what is stored rather than being cleared — the single-sheet path
        // still reads them, and silently wiping a tenant's setup because a
        // column left the screen would be its own kind of bug.
        $defaultReceivable = array_key_exists('post_receivable_ledger_id', $_POST)
            ? $checkLedger((int) $_POST['post_receivable_ledger_id'], 'The default Receivable ledger')
            : ((int) ($settings['post_receivable_ledger_id'] ?? 0) ?: null);
        $defaultDiscount = array_key_exists('post_discount_ledger_id', $_POST)
            ? $checkLedger((int) $_POST['post_discount_ledger_id'], 'The default Discount ledger')
            : ((int) ($settings['post_discount_ledger_id'] ?? 0) ?: null);

        // Validate every category row before anything is written.
        $mapIds = (array) ($_POST['map_id'] ?? []);
        $mapNames = (array) ($_POST['map_category'] ?? []);
        $mapSales = (array) ($_POST['map_sales_ledger'] ?? []);
        $mapReceivable = (array) ($_POST['map_receivable_ledger'] ?? []);
        $mapDiscount = (array) ($_POST['map_discount_ledger'] ?? []);
        $mapNotes = (array) ($_POST['map_notes'] ?? []);
        $mapActive = (array) ($_POST['map_active'] ?? []);
        // One read, so a row whose receivable/discount is not on the form can
        // keep the one it already has.
        $existingMapLedgers = [];
        if ($mapReceivable === [] || $mapDiscount === []) {
            $existingStmt = db()->prepare('SELECT id, receivable_ledger_id, discount_ledger_id
                FROM hospitality_sales_ledger_maps WHERE company_id = :cid');
            $existingStmt->execute(['cid' => $companyId]);
            foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $existingRow) {
                $existingMapLedgers[(int) $existingRow['id']] = $existingRow;
            }
        }
        $rowsToSave = [];
        $seenNames = [];
        foreach ($mapIds as $index => $rawMapId) {
            $mapId = (int) $rawMapId;
            $name = trim((string) ($mapNames[$index] ?? ''));
            if ($mapId <= 0 && $name === '') {
                continue; // untouched blank "add" row
            }
            if ($name === '') {
                flash('error', 'A category row is missing its name — every row needs the category exactly as written in the sheet.');
                redirect($back);
            }
            $norm = hospitality_sales_norm($name);
            if (isset($seenNames[$norm])) {
                flash('error', 'Two rows use the same category name "' . $name . '" — merge them into one row.');
                redirect($back);
            }
            $seenNames[$norm] = true;
            $salesId = $checkLedger((int) ($mapSales[$index] ?? 0), 'The sales ledger for "' . $name . '"');
            if ($salesId === null) {
                flash('error', 'Category "' . $name . '" needs a sales ledger.');
                redirect($back);
            }
            $rowsToSave[] = [
                'id' => $mapId,
                'name' => $name,
                'norm' => $norm,
                'sales' => $salesId,
                // Absent from the form: keep whatever the row already holds.
                'recv' => array_key_exists($index, $mapReceivable)
                    ? $checkLedger((int) $mapReceivable[$index], 'The receivable ledger for "' . $name . '"')
                    : ((int) ($existingMapLedgers[$mapId]['receivable_ledger_id'] ?? 0) ?: null),
                'disc' => array_key_exists($index, $mapDiscount)
                    ? $checkLedger((int) $mapDiscount[$index], 'The discount ledger for "' . $name . '"')
                    : ((int) ($existingMapLedgers[$mapId]['discount_ledger_id'] ?? 0) ?: null),
                'notes' => trim((string) ($mapNotes[$index] ?? '')) ?: null,
                'active' => isset($mapActive[$index]) ? 1 : 0,
            ];
        }

        $old = $settings;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE hospitality_settings SET post_sales_ledger_id = :sales, post_vat_ledger_id = :vat,
                    post_discount_ledger_id = :disc, post_receivable_ledger_id = :recv,
                    post_vat_rate = :rate, post_amount_includes_vat = :incl, updated_by = :by
                WHERE company_id = :cid')
                ->execute([
                    'sales' => $defaultSales, 'vat' => $vatLedger, 'disc' => $defaultDiscount, 'recv' => $defaultReceivable,
                    'rate' => max(0.0, min(99.99, round((float) ($_POST['post_vat_rate'] ?? 13), 2))),
                    // Not on the form any more: the two-sheet upload reads the
                    // Taxable Sales and VAT columns the sheet actually carries,
                    // so there is nothing to infer. Kept as stored for the
                    // single-sheet path, which still extracts VAT itself.
                    'incl' => (int) ($settings['post_amount_includes_vat'] ?? 1),
                    'by' => $userId, 'cid' => $companyId,
                ]);

            $updateRow = $pdo->prepare('UPDATE hospitality_sales_ledger_maps
                    SET match_value = :norm, display_value = :disp, sales_ledger_id = :sales,
                        receivable_ledger_id = :recv, discount_ledger_id = :disc, notes = :notes, active = :active
                    WHERE id = :id AND company_id = :cid');
            $insertRow = $pdo->prepare('INSERT INTO hospitality_sales_ledger_maps
                        (company_id, map_type, match_value, display_value, sales_ledger_id, receivable_ledger_id, discount_ledger_id, active, notes, created_by)
                    VALUES (:cid, \'category\', :norm, :disp, :sales, :recv, :disc, :active, :notes, :by)
                    ON DUPLICATE KEY UPDATE display_value = VALUES(display_value), sales_ledger_id = VALUES(sales_ledger_id),
                        receivable_ledger_id = VALUES(receivable_ledger_id), discount_ledger_id = VALUES(discount_ledger_id),
                        active = VALUES(active), notes = VALUES(notes)');
            foreach ($rowsToSave as $row) {
                $params = [
                    'norm' => $row['norm'], 'disp' => mb_substr($row['name'], 0, 160), 'sales' => $row['sales'],
                    'recv' => $row['recv'], 'disc' => $row['disc'], 'notes' => $row['notes'], 'active' => $row['active'],
                ];
                if ($row['id'] > 0) {
                    $updateRow->execute($params + ['id' => $row['id'], 'cid' => $companyId]);
                } else {
                    $insertRow->execute($params + ['cid' => $companyId, 'by' => $userId]);
                }
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', (string) $exception->getCode() === '23000'
                ? 'Two rows ended up with the same category name — rename one of them and save again.'
                : 'Could not save the ledger mapping: ' . $exception->getMessage());
            redirect($back);
        }
        log_activity('hospitality_ledger_map', $companyId, 'saved',
            'Hospitality ledger mapping saved: defaults + VAT + ' . count($rowsToSave) . ' category row(s).', $userId);
        log_field_changes('hospitality_settings', $companyId, $old, hospitality_settings($companyId), $companyId, $userId);
        flash('success', 'Ledger mapping saved (' . count($rowsToSave) . ' category row(s) + defaults & VAT). Uploaded day-sheets will post with it.');
        redirect('admin/hospitality.php?view=sales-upload');
    }

    // Reference-only costing generated from an uploaded sheet's rows: one
    // daily run per sale date. Re-running snapshots the previous totals into
    // hospitality_recalc_history first, so the cost history is never lost.
    if ($action === 'run_upload_costing') {
        require_permission('hospitality', 'create');
        $uploadId = (int) ($_POST['upload_id'] ?? 0);
        $result = hospitality_upload_costing($companyId, $fiscalYearId, $uploadId, $userId, trim((string) ($_POST['reason'] ?? '')));
        if ($result['ok']) {
            $message = 'Costing saved: ' . count($result['runs']) . ' day(s), ' . (int) $result['costed'] . '/' . (int) $result['lines'] . ' rows costed.';
            if ($result['skipped_dates'] !== []) {
                $message .= ' Skipped dates outside the fiscal year: ' . implode(', ', $result['skipped_dates']) . '.';
            }
            if ((int) $result['costed'] < (int) $result['lines']) {
                $message .= ' Rows that could not be costed are listed under Reports → exceptions with the reason (missing item or recipe).';
            }
            flash('success', $message);
        } else {
            flash('error', (string) $result['error']);
        }
        redirect('admin/hospitality.php?view=sales-upload');
    }

    if ($action === 'delete_costing_run') {
        require_permission('hospitality', 'adjust');
        $runId = (int) ($_POST['run_id'] ?? 0);
        if (hospitality_run_delete($companyId, $runId, $userId, trim((string) ($_POST['reason'] ?? '')))) {
            flash('success', 'Costing run deleted — its last totals stay in the recalculation history.');
        } else {
            flash('error', 'Costing run not found for this company.');
        }
        redirect('admin/hospitality.php?view=sales-upload');
    }

    if ($action === 'sales_upload_file') {
        require_permission('hospitality', 'create');
        hospitality_sales_prepare_dir($salesUploadDir);
        $token = bin2hex(random_bytes(20));

        // The pair may arrive as one workbook holding both sheets, or as two
        // files. The second input is optional for exactly that reason.
        $checkFile = static function (?array $file, bool $required) use ($hasXlsx): array {
            $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($errorCode === UPLOAD_ERR_NO_FILE || (int) ($file['size'] ?? 0) <= 0) {
                return $required
                    ? ['error' => 'Choose an Excel (.xlsx) or CSV sales sheet to upload.']
                    : ['skip' => true];
            }
            if ($errorCode !== UPLOAD_ERR_OK) {
                return ['error' => 'The file did not upload cleanly. Try again.'];
            }
            if ((int) $file['size'] > 10 * 1024 * 1024) {
                return ['error' => 'The file is larger than 10 MB.'];
            }
            $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($extension, ['xlsx', 'csv'], true)) {
                return ['error' => $extension === 'xls'
                    ? 'Legacy .xls files are not supported — open the file in Excel and save it as .xlsx or .csv.'
                    : 'Only .xlsx and .csv files can be uploaded.'];
            }
            if ($extension === 'xlsx' && !$hasXlsx) {
                return ['error' => 'This server cannot read .xlsx files (PHP zip extension missing). Save the sheets as .csv and upload those instead.'];
            }

            return ['extension' => $extension];
        };

        foreach ([['sales_file', '', true], ['sales_file_2', '-b', false]] as [$field, $slot, $required]) {
            $file = $_FILES[$field] ?? null;
            $checked = $checkFile($file, $required);
            if (isset($checked['skip'])) {
                continue;
            }
            if (isset($checked['error'])) {
                hospitality_sales_forget_upload($salesUploadDir, $token);
                flash('error', (string) $checked['error']);
                redirect('admin/hospitality.php?view=sales-upload');
            }
            if (!move_uploaded_file((string) $file['tmp_name'], $salesUploadDir . '/' . $token . $slot . '.' . $checked['extension'])) {
                hospitality_sales_forget_upload($salesUploadDir, $token);
                flash('error', 'The uploaded file could not be stored. Try again.');
                redirect('admin/hospitality.php?view=sales-upload');
            }
            if ($slot === '') {
                // Original file name is kept beside the sheet for the audit batch.
                @file_put_contents($salesUploadDir . '/' . $token . '.name', mb_substr((string) $file['name'], 0, 255));
            }
        }
        redirect('admin/hospitality.php?view=sales-upload&token=' . $token);
    }

    // The sheet editor works on rows, not on the file. A correction typed here
    // is checked by the very code that rejected it on the way in, and posted
    // from what is on screen -- the held file is never rewritten, because a
    // spreadsheet that no longer matches what was uploaded is its own problem.
    if ($action === 'sheet_editor_check' || $action === 'sheet_editor_post') {
        require_permission('hospitality', 'create');
        $editorItems = hospitality_workbook_editor_to_cells((array) ($_POST['item_rows'] ?? []), 'items');
        $editorInvoices = hospitality_workbook_editor_to_cells((array) ($_POST['invoice_rows'] ?? []), 'invoices');
        $_SESSION['hospitality_sheet_editor'] = [
            'items' => array_values((array) ($_POST['item_rows'] ?? [])),
            'invoices' => array_values((array) ($_POST['invoice_rows'] ?? [])),
            'file_name' => mb_substr(trim((string) ($_POST['file_name'] ?? '')), 0, 255),
        ];
        if ($action === 'sheet_editor_check') {
            flash('success', 'Checked. Anything still wrong is marked on its own row.');
            redirect('admin/hospitality.php?view=sheet-editor');
        }

        $editorParsed = hospitality_workbook_parse_rows($editorItems, $editorInvoices, $companyId, $fiscalYearId, hospitality_settings($companyId));
        if (isset($editorParsed['error'])) {
            flash('error', (string) $editorParsed['error']);
            redirect('admin/hospitality.php?view=sheet-editor');
        }
        $editorFileName = (string) ($_SESSION['hospitality_sheet_editor']['file_name'] ?? '') ?: 'corrected sheet';
        $editorResult = hospitality_post_sales_workbook($companyId, $fiscalYearId, $editorParsed, $editorFileName, $userId,
            (string) ($_POST['allow_duplicate_dates'] ?? '') === '1');
        if (!$editorResult['ok']) {
            flash('error', (string) $editorResult['error']);
            redirect('admin/hospitality.php?view=sheet-editor');
        }
        unset($_SESSION['hospitality_sheet_editor']);
        flash('success', (int) $editorResult['rows'] . ' item line(s) and ' . (int) $editorResult['invoices'] . ' invoice(s) '
            . ($editorResult['needs_approval']
                ? 'submitted — ' . (int) $editorResult['vouchers'] . ' daily voucher(s) awaiting approval.'
                : 'posted as ' . (int) $editorResult['vouchers'] . ' daily sales voucher(s).'));
        redirect('admin/hospitality.php?view=sales-upload');
    }

    // Load a held upload into the editor, so a sheet that would not go in can
    // be fixed here instead of in Excel and uploaded all over again.
    if ($action === 'sheet_editor_load') {
        require_permission('hospitality', 'create');
        $loadToken = (string) ($_POST['token'] ?? '');
        $loadPrimary = hospitality_sales_stored_file($salesUploadDir, $loadToken);
        if ($loadPrimary === null) {
            flash('error', 'That upload has expired. Upload the sheets again.');
            redirect('admin/hospitality.php?view=sales-upload');
        }
        $loadSecond = hospitality_sales_stored_file($salesUploadDir, $loadToken, '-b');
        try {
            $loaded = hospitality_workbook_parse(
                $loadPrimary['path'], $loadPrimary['extension'],
                $loadSecond['path'] ?? null, $loadSecond['extension'] ?? null,
                $companyId, $fiscalYearId, hospitality_settings($companyId)
            );
        } catch (Throwable $loadError) {
            flash('error', 'Could not read the sheets: ' . $loadError->getMessage());
            redirect('admin/hospitality.php?view=sales-upload');
        }
        if (isset($loaded['error'])) {
            flash('error', (string) $loaded['error']);
            redirect('admin/hospitality.php?view=sales-upload&token=' . $loadToken);
        }
        $_SESSION['hospitality_sheet_editor'] = [
            'items' => hospitality_workbook_rows_to_editor($loaded['items']['rows'], 'items'),
            'invoices' => hospitality_workbook_rows_to_editor($loaded['invoices']['rows'], 'invoices'),
            'file_name' => trim((string) @file_get_contents($salesUploadDir . '/' . $loadToken . '.name')),
        ];
        redirect('admin/hospitality.php?view=sheet-editor');
    }

    if ($action === 'sheet_editor_clear') {
        unset($_SESSION['hospitality_sheet_editor']);
        redirect('admin/hospitality.php?view=sheet-editor');
    }

    if ($action === 'sales_upload_commit') {
        require_permission('hospitality', 'create');
        $token = (string) ($_POST['token'] ?? '');
        $stored = hospitality_sales_stored_file($salesUploadDir, $token);
        if ($stored === null) {
            flash('error', 'The uploaded sheets have expired. Upload them again.');
            redirect('admin/hospitality.php?view=sales-upload');
        }
        $storedSecond = hospitality_sales_stored_file($salesUploadDir, $token, '-b');
        try {
            $parsed = hospitality_workbook_parse(
                $stored['path'], $stored['extension'],
                $storedSecond['path'] ?? null, $storedSecond['extension'] ?? null,
                $companyId, $fiscalYearId, hospitality_settings($companyId)
            );
        } catch (Throwable $exception) {
            flash('error', 'Could not read the sheets: ' . $exception->getMessage());
            redirect('admin/hospitality.php?view=sales-upload');
        }
        if (isset($parsed['error'])) {
            flash('error', (string) $parsed['error']);
            redirect('admin/hospitality.php?view=sales-upload&token=' . $token);
        }
        $fileName = trim((string) @file_get_contents($salesUploadDir . '/' . $token . '.name')) ?: ('sales-sheet.' . $stored['extension']);
        $result = hospitality_post_sales_workbook($companyId, $fiscalYearId, $parsed, $fileName, $userId,
            (string) ($_POST['allow_duplicate_dates'] ?? '') === '1');
        if (!$result['ok']) {
            flash('error', (string) $result['error']);
            redirect('admin/hospitality.php?view=sales-upload&token=' . $token);
        }
        hospitality_sales_forget_upload($salesUploadDir, $token);
        flash('success', (int) $result['rows'] . ' item line(s) and ' . (int) $result['invoices'] . ' invoice(s) '
            . ($result['needs_approval']
                ? 'submitted — ' . (int) $result['vouchers'] . ' daily voucher(s) are awaiting approval.'
                : 'posted as ' . (int) $result['vouchers'] . ' daily sales voucher(s). See them in the Voucher Register.')
            . ((int) $result['menu_created'] > 0 ? ' ' . (int) $result['menu_created'] . ' new menu item(s) were added from the sheet.' : ''));
        redirect('admin/hospitality.php?view=sales-upload');
    }

    flash('error', 'Unsupported hospitality action.');
    redirect($back);
}

// ---------------------------------------------------------------------------
// CSV export (reference reports only)
// ---------------------------------------------------------------------------
// The Sales Reports on the upload screen are readings of what was uploaded, and
// they leave the building the same three ways every other list here does.
$salesReportExport = jw_enum_like($_GET['export'] ?? null, ['csv', 'xlsx', 'print'], '');
if ($view === 'sales-upload' && $salesReportExport !== '' && isset($_GET['report'])) {
    require_permission('hospitality', 'export');
    require_once __DIR__ . '/../../app/export_engine.php';
    $exportReportKey = (string) $_GET['report'];
    if (!isset(hospitality_sales_report_options()[$exportReportKey])) {
        $exportReportKey = 'sheet';
    }
    [$exportFromDefault, $exportToDefault] = hospitality_sales_report_default_range($companyId);
    $exportFrom = $clampDate(trim((string) ($_GET['rfrom'] ?? $exportFromDefault)));
    $exportTo = $clampDate(trim((string) ($_GET['rto'] ?? $exportToDefault)));
    if ($exportTo < $exportFrom) {
        $exportTo = $exportFrom;
    }
    // perPage huge: an export is the whole report, never the page on screen.
    $exportReport = hospitality_sales_report(
        $companyId, $exportFrom, $exportTo, $exportReportKey, 1, PHP_INT_MAX,
        (string) ($_GET['rsort'] ?? ''), (string) ($_GET['rdir'] ?? 'asc')
    );
    log_activity('hospitality_sales_upload', $companyId, 'exported',
        'Sales report "' . $exportReportKey . '" exported (' . $exportFrom . ' to ' . $exportTo . ').', $userId);
    export_dispatch(
        $salesReportExport,
        'sales-' . $exportReportKey . '-' . $exportFrom . '-to-' . $exportTo,
        hospitality_sales_report_export_rows($exportReport),
        mb_substr((string) hospitality_sales_report_options()[$exportReportKey], 0, 31),
        ['Company' => (string) ($company['name'] ?? ''), 'Period' => $exportFrom . ' to ' . $exportTo]
    );
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_permission('hospitality', 'export');
    $reportKey = (string) ($_GET['report'] ?? 'item');
    $groupBy = match ($reportKey) {
        'category' => 'category',
        'daily' => 'period_day',
        'monthly' => 'period_month',
        default => 'menu_item',
    };
    $rows = hospitality_grouped($companyId, $rangeFrom, $rangeTo, $groupBy);
    log_activity('hospitality_report', $companyId, 'exported', 'Hospitality ' . $reportKey . ' GP report exported (' . $rangeFrom . ' to ' . $rangeTo . ').', $userId);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hospitality-' . $reportKey . '-gp-' . $rangeFrom . '-' . $rangeTo . '.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, [$company['name'] . ' — Estimated Gross Profit (' . $reportKey . ')', $fiscalYear['label'] ?? '', $rangeFrom . ' to ' . $rangeTo, 'Generated ' . date('Y-m-d H:i') . ' by ' . ($currentUser['name'] ?? '')], ',', '"', '\\');
    fputcsv($out, ['Group', 'Net Qty', 'Gross Sales', 'Discount', 'VAT', 'Net Sales (ex VAT)', 'Estimated Cost', 'Estimated GP', 'GP %', 'Sales Contribution %', 'GP Contribution %'], ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['group'], $row['net_qty'], $row['gross_sales'], $row['discount'], $row['vat'], $row['net_sales'],
            $row['est_cost'], $row['est_gp'], $row['gp_pct'] ?? 'N/A', $row['sales_contribution_pct'] ?? 'N/A', $row['gp_contribution_pct'] ?? 'N/A',
        ], ',', '"', '\\');
    }
    fputcsv($out, ['Reference estimate only. This report does not represent posted Cost of Goods Sold and does not create or modify accounting entries.'], ',', '"', '\\');
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Page data per view
// ---------------------------------------------------------------------------
$pageTitle = 'Hospitality Accounting';
$pageSubtitle = 'Recipe costing and estimated gross profit (reference-only), plus daily sales sheet upload that auto-posts to accounting.';
$pageHero = ['icon' => 'services'];
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';

$fmt = static fn (?float $n, int $p = 2): string => $n === null ? 'N/A' : number_format($n, $p);
?>
<?php if ($view === 'sales-upload'): ?>
    <div class="notice" style="margin-bottom:14px"><strong>Posts to accounting:</strong> unlike the costing tabs, sheets posted here create real daily sales vouchers (receivable, sales per category ledger, VAT, discount) in the Voucher Register.</div>
<?php else: ?>
    <div class="notice" style="margin-bottom:14px"><strong>Reference only:</strong> <?= e($disclaimer) ?> Hospitality costing is an estimate based on configured recipes and reference ingredient costs. It does not post to accounting or inventory (daily sales posting lives in the Sales Upload tab).</div>
<?php endif; ?>

<nav class="mbw-tabbar" aria-label="Hospitality sections" style="flex-wrap:wrap">
    <?php foreach ([
        'dashboard' => ['Dashboard', 'home'],
        'ingredients' => ['Ingredients', 'box'],
        'menu-items' => ['Menu Items', 'receipt-voucher'],
        'recipes' => ['Recipes', 'layers'],
        'sales-upload' => ['Sales Upload', 'documents'],
        'sheet-editor' => ['Sheet Editor', 'edit'],
        'reports' => ['Reports', 'reports'],
        'settings' => ['Settings', 'sliders'],
    ] as $tabView => [$tabLabel, $tabIcon]): ?>
        <a class="mbw-tab <?= $view === $tabView || (($legacyViews[$view] ?? '') === $tabView) ? 'is-active' : '' ?>" href="<?= e(url('admin/hospitality.php?view=' . $tabView)) ?>"><?= icon($tabIcon) ?> <?= e($tabLabel) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($view === 'dashboard'): ?>
    <?php
    $today = hospitality_summary($companyId, $todayInFy, $todayInFy);
    $mtdFrom = $clampDate(date('Y-m-01', strtotime($todayInFy)));
    $mtd = hospitality_summary($companyId, $mtdFrom, $todayInFy);
    $topItems = array_slice(hospitality_grouped($companyId, $mtdFrom, $todayInFy, 'menu_item'), 0, 8);
    $categories = hospitality_grouped($companyId, $mtdFrom, $todayInFy, 'category');
    $lowItems = $topItems;
    usort($lowItems, static fn (array $a, array $b): int => ($a['gp_pct'] ?? 999) <=> ($b['gp_pct'] ?? 999));
    ?>
    <section class="mbw-kpi-grid" aria-label="Hospitality summary">
        <?php foreach ([
            ['Today Net Sales', $sym . $fmt($today['costed_net_sales']), 'wallet', 'tone-blue'],
            ['Today Est. Cost', $sym . $fmt($today['est_cost']), 'box', 'tone-amber'],
            ['Today Est. GP', $sym . $fmt($today['est_gp']), 'analytics', 'tone-green'],
            ['Today Est. GP %', $fmt($today['weighted_gp_pct']) . ($today['weighted_gp_pct'] !== null ? '%' : ''), 'pie', 'tone-green'],
            ['MTD Net Sales', $sym . $fmt($mtd['costed_net_sales']), 'wallet', 'tone-blue'],
            ['MTD Est. GP', $sym . $fmt($mtd['est_gp']), 'analytics', 'tone-green'],
            ['Weighted GP % (MTD)', $fmt($mtd['weighted_gp_pct']) . ($mtd['weighted_gp_pct'] !== null ? '%' : ''), 'pie', 'tone-green'],
            ['Costed Sales % (MTD)', $fmt($mtd['completeness_pct']) . ($mtd['completeness_pct'] !== null ? '%' : ''), 'reconcile', 'tone-teal'],
            ['Unmapped Sales', (string) $mtd['unmapped_count'] . ' (' . $sym . $fmt($mtd['unmapped_value']) . ')', 'search', 'tone-amber'],
            ['Missing Recipes', (string) $mtd['missing_recipe_count'], 'journal', 'tone-red'],
            ['Missing Costs', (string) $mtd['missing_cost_count'], 'tag', 'tone-red'],
            ['Simple Avg Item GP %', $fmt($mtd['simple_avg_item_gp_pct']) . ($mtd['simple_avg_item_gp_pct'] !== null ? '%' : ''), 'layers', 'tone-gray'],
        ] as [$kpiLabel, $kpiValue, $kpiIcon, $kpiTone]): ?>
            <article class="mbw-kpi"><div><span class="mbw-kpi-label"><?= e($kpiLabel) ?></span><div class="mbw-kpi-value" style="font-size:1.02rem"><?= e($kpiValue) ?></div></div><span class="mbw-chip <?= e($kpiTone) ?>"><?= icon($kpiIcon) ?></span></article>
        <?php endforeach; ?>
    </section>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px">
        <section class="mbw-card" data-collapsible>
            <div class="mbw-card-head"><h2>Top Menu Items (month to date)</h2><a class="mbw-view-all" href="<?= e(url('admin/hospitality.php?view=gp')) ?>">All items →</a></div>
            <div style="overflow-x:auto"><table>
                <thead><tr><th>Item</th><th class="is-numeric">Net Sales</th><th class="is-numeric">Est. GP</th><th class="is-numeric">GP %</th></tr></thead>
                <tbody>
                    <?php if ($topItems === []): ?><tr><td colspan="4">No costed sales yet — generate Daily Costing first.</td></tr><?php endif; ?>
                    <?php foreach ($topItems as $row): ?>
                        <tr><td><?= e($row['group']) ?></td><td class="is-numeric"><?= $fmt($row['net_sales']) ?></td><td class="is-numeric"><?= $fmt($row['est_gp']) ?></td><td class="is-numeric"><?= $fmt($row['gp_pct']) ?><?= $row['gp_pct'] !== null ? '%' : '' ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
        <section class="mbw-card" data-collapsible>
            <div class="mbw-card-head"><h2>Category-wise (month to date)</h2></div>
            <div style="overflow-x:auto"><table>
                <thead><tr><th>Category</th><th class="is-numeric">Net Sales</th><th class="is-numeric">Est. Cost</th><th class="is-numeric">Est. GP</th><th class="is-numeric">Weighted GP %</th><th class="is-numeric">Sales Contrib.</th></tr></thead>
                <tbody>
                    <?php if ($categories === []): ?><tr><td colspan="6">No costed sales yet.</td></tr><?php endif; ?>
                    <?php foreach ($categories as $row): ?>
                        <tr><td><?= e($row['group']) ?></td><td class="is-numeric"><?= $fmt($row['net_sales']) ?></td><td class="is-numeric"><?= $fmt($row['est_cost']) ?></td><td class="is-numeric"><?= $fmt($row['est_gp']) ?></td><td class="is-numeric"><?= $fmt($row['gp_pct']) ?><?= $row['gp_pct'] !== null ? '%' : '' ?></td><td class="is-numeric"><?= $fmt($row['sales_contribution_pct']) ?><?= $row['sales_contribution_pct'] !== null ? '%' : '' ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <p style="margin:8px 0 0;color:var(--mbw-muted);font-size:12px">Weighted GP % = category GP ÷ category net sales (never a plain average of item percentages).</p>
        </section>
    </div>

<?php elseif ($view === 'ingredients'): ?>
    <?php
    // The list is brought in line with inventory on the way in, so an item
    // ticked as an ingredient a moment ago is already here. It writes nothing
    // when nothing has changed, which is the usual case.
    $ingredientSync = ['created' => 0, 'updated' => 0, 'retired' => 0, 'restored' => 0];
    if ($canEdit && hospitality_ingredients_linked()) {
        $ingredientSync = hospitality_sync_ingredients_from_inventory($companyId, $userId);
    }
    $hasItemLink = hospitality_ingredients_linked();
    $ingredientsStmt = db()->prepare('SELECT i.*, ' . ($hasItemLink ? 'it.sku AS item_sku, it.status AS item_status,' : 'NULL AS item_sku, NULL AS item_status,') . '
            (SELECT COUNT(*) FROM hospitality_recipe_lines l WHERE l.ingredient_id = i.id) AS used_in
        FROM hospitality_ingredients i
        ' . ($hasItemLink ? 'LEFT JOIN inventory_items it ON it.id = i.inventory_item_id AND it.company_id = i.company_id' : '') . '
        WHERE i.company_id = :cid ORDER BY i.active DESC, i.code ASC');
    $ingredientsStmt->execute(['cid' => $companyId]);
    $ingredients = $ingredientsStmt->fetchAll();
    $unlinkedCount = 0;
    foreach ($ingredients as $ingredientRow) {
        if (($ingredientRow['inventory_item_id'] ?? null) === null) {
            $unlinkedCount++;
        }
    }
    ?>
    <div class="notice" style="margin-bottom:14px">
        <strong>Ingredients come from the item master.</strong>
        The kitchen buys rice once, so it is described once: tick <em>Use as a recipe ingredient</em> on an inventory item and it appears here.
        Its code, name, category, purchase unit and cost stay with the item, where they are bought and valued.
        What this screen holds is only what the item master has no opinion about — the unit a recipe measures it in,
        how many of those are in a purchase unit, and the wastage and yield of preparing it.
        <a href="<?= e(url('admin/accounting-inventory.php?view=items')) ?>">Open the item master →</a>
        <?php if ($ingredientSync['created'] > 0 || $ingredientSync['retired'] > 0 || $ingredientSync['restored'] > 0): ?>
            <br><span class="mbw-pill tone-green" style="margin-top:6px;display:inline-block">Just refreshed:
                <?= (int) $ingredientSync['created'] ?> added,
                <?= (int) $ingredientSync['restored'] ?> restored,
                <?= (int) $ingredientSync['retired'] ?> retired</span>
        <?php endif; ?>
    </div>

    <section class="mbw-card">
        <div class="mbw-card-head">
            <h2>Ingredient Master (<?= count($ingredients) ?>)</h2>
            <?php if ($canEdit): ?>
                <form method="post" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="sync_ingredients">
                    <input type="hidden" name="back_view" value="ingredients">
                    <button type="submit" class="secondary" style="min-height:30px;padding:3px 12px"><?= icon('reconcile') ?>Refresh from inventory</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if (!$canEdit): ?><div class="notice">You have view-only access to this list.</div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_ingredient_recipe">
            <input type="hidden" name="back_view" value="ingredients">
            <div style="overflow-x:auto"><table>
                <thead><tr>
                    <th>Item</th><th>Category</th>
                    <th class="is-numeric">Cost / purchase unit</th>
                    <th>Recipe unit</th><th class="is-numeric">Recipe units per purchase unit</th>
                    <th class="is-numeric">Wastage %</th><th class="is-numeric">Yield %</th>
                    <th class="is-numeric">Cost / recipe unit</th>
                    <th>Used in</th><th>Status</th>
                </tr></thead>
                <tbody>
                    <?php if ($ingredients === []): ?>
                        <tr><td colspan="10">No ingredients yet — tick <em>Use as a recipe ingredient</em> on an inventory item and it will appear here.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($ingredients as $ingredient): ?>
                        <?php
                        $unitCost = hospitality_ingredient_unit_cost($ingredient);
                        $rowId = (int) $ingredient['id'];
                        $isLinked = ($ingredient['inventory_item_id'] ?? null) !== null;
                        ?>
                        <tr<?= (int) $ingredient['active'] === 1 ? '' : ' style="opacity:.6"' ?>>
                            <td>
                                <strong><?= e((string) $ingredient['code']) ?></strong> — <?= e((string) $ingredient['name']) ?>
                                <?php if ($isLinked): ?>
                                    <br><a style="font-size:12px" href="<?= e(url('admin/accounting-inventory.php?view=items&item=' . (int) $ingredient['inventory_item_id'])) ?>">Inventory item <?= e((string) ($ingredient['item_sku'] ?? '')) ?> →</a>
                                <?php else: ?>
                                    <br><span class="mbw-pill tone-amber">Not linked to an item</span>
                                <?php endif; ?>
                                <?php if ($canEdit): ?>
                                    <input type="hidden" name="recipe[<?= $rowId ?>][label]" value="<?= e((string) $ingredient['code']) ?>">
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($ingredient['category'] ?? '—')) ?></td>
                            <td class="is-numeric"><?= $fmt((float) $ingredient['purchase_cost'], 4) ?><br><small style="color:var(--mbw-muted)">per <?= e((string) $ingredient['purchase_unit']) ?></small></td>
                            <td><?php if ($canEdit): ?>
                                <input type="text" name="recipe[<?= $rowId ?>][recipe_unit]" maxlength="40" value="<?= e((string) $ingredient['recipe_unit']) ?>" style="width:90px">
                            <?php else: ?><?= e((string) $ingredient['recipe_unit']) ?><?php endif; ?></td>
                            <td class="is-numeric"><?php if ($canEdit): ?>
                                <input type="number" step="0.0001" min="0.0001" name="recipe[<?= $rowId ?>][conversion_factor]" value="<?= e(number_format((float) $ingredient['conversion_factor'], 4, '.', '')) ?>" style="width:110px;text-align:right">
                            <?php else: ?><?= e(rtrim(rtrim(number_format((float) $ingredient['conversion_factor'], 4, '.', ''), '0'), '.')) ?><?php endif; ?></td>
                            <td class="is-numeric"><?php if ($canEdit): ?>
                                <input type="number" step="0.01" min="0" max="99.99" name="recipe[<?= $rowId ?>][wastage_pct]" value="<?= e(number_format((float) $ingredient['wastage_pct'], 2, '.', '')) ?>" style="width:80px;text-align:right">
                            <?php else: ?><?= e(number_format((float) $ingredient['wastage_pct'], 2)) ?><?php endif; ?></td>
                            <td class="is-numeric"><?php if ($canEdit): ?>
                                <input type="number" step="0.01" min="0.01" max="100" name="recipe[<?= $rowId ?>][yield_pct]" value="<?= e(number_format((float) $ingredient['yield_pct'], 2, '.', '')) ?>" style="width:80px;text-align:right">
                            <?php else: ?><?= e(number_format((float) $ingredient['yield_pct'], 2)) ?><?php endif; ?></td>
                            <td class="is-numeric"><?= $unitCost['ok']
                                ? $fmt($unitCost['unit_cost'], 4)
                                : '<span class="mbw-pill tone-red">' . e((string) $unitCost['error']) . '</span>' ?></td>
                            <td><?= (int) $ingredient['used_in'] > 0 ? '<span class="mbw-pill tone-blue">' . (int) $ingredient['used_in'] . ' recipe(s)</span>' : '—' ?></td>
                            <td><span class="mbw-pill <?= (int) $ingredient['active'] === 1 ? 'tone-green' : 'tone-gray' ?>"><?= (int) $ingredient['active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ($canEdit && $ingredients !== []): ?>
                <div style="margin-top:12px"><button type="submit"><?= icon('badge-check') ?>Save recipe settings</button></div>
            <?php endif; ?>
        </form>
        <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12px">
            Cost per recipe unit is the purchase cost divided by the conversion, adjusted for wastage and yield — it is what a recipe is costed at.
            Un-ticking an item on the item master makes its ingredient inactive rather than deleting it, because recipes may already quote it and
            their costed history must not move.
            <?php if ($unlinkedCount > 0): ?>
                <br><?= (int) $unlinkedCount ?> ingredient<?= $unlinkedCount === 1 ? ' was' : 's were' ?> entered before ingredients came from the item master.
                <?= $unlinkedCount === 1 ? 'It keeps' : 'They keep' ?> working; link <?= $unlinkedCount === 1 ? 'it' : 'them' ?> by creating the matching inventory item and ticking the box there.
            <?php endif; ?>
        </p>
    </section>


<?php elseif ($view === 'menu-items'): ?>
    <?php
    $editMenuItem = null;
    $editMenuItemId = (int) ($_GET['item'] ?? 0);
    if ($editMenuItemId > 0) {
        $emStmt = db()->prepare('SELECT * FROM hospitality_menu_items WHERE id = :id AND company_id = :cid');
        $emStmt->execute(['id' => $editMenuItemId, 'cid' => $companyId]);
        $editMenuItem = $emStmt->fetch() ?: null;
    }
    $menuItemsStmt = db()->prepare("SELECT m.*,
            (SELECT COUNT(*) FROM hospitality_recipes r WHERE r.menu_item_id = m.id) AS recipe_count,
            (SELECT r2.version FROM hospitality_recipes r2 WHERE r2.menu_item_id = m.id AND r2.status = 'active' ORDER BY r2.effective_from DESC LIMIT 1) AS active_version
        FROM hospitality_menu_items m WHERE m.company_id = :cid ORDER BY m.code ASC");
    $menuItemsStmt->execute(['cid' => $companyId]);
    $menuItems = $menuItemsStmt->fetchAll();
    ?>
    <?php if ($canEdit && $editMenuItem): ?>
    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Edit Menu Item</h2><a class="mbw-view-all" href="<?= e(url('admin/hospitality.php?view=menu-items')) ?>">Cancel</a></div>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_menu_item">
            <input type="hidden" name="back_view" value="menu-items">
            <input type="hidden" name="menu_item_id" value="<?= e((int) ($editMenuItem['id'] ?? 0)) ?>">
            <label>Code<input type="text" name="code" maxlength="40" required value="<?= e($editMenuItem['code'] ?? '') ?>" placeholder="CHK-MOMO"></label>
            <label>Name<input type="text" name="name" maxlength="160" required value="<?= e($editMenuItem['name'] ?? '') ?>" placeholder="Chicken Momo (10 pcs)"></label>
            <label>Name (Nepali)<input type="text" name="name_np" maxlength="160" value="<?= e($editMenuItem['name_np'] ?? '') ?>"></label>
            <label>Category
                <select name="category">
                    <?php foreach (['Food', 'Beverage', 'Bakery', 'Bar', 'Catering', 'Accommodation Add-ons', 'Other'] as $categoryOption): ?>
                        <option value="<?= e($categoryOption) ?>" <?= (string) ($editMenuItem['category'] ?? 'Food') === $categoryOption ? 'selected' : '' ?>><?= e($categoryOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Standard selling price (fallback only)<input type="number" step="0.01" min="0" name="standard_price" value="<?= e(number_format((float) ($editMenuItem['standard_price'] ?? 0), 2, '.', '')) ?>"></label>
            <label>Unit of sale<input type="text" name="unit_of_sale" maxlength="40" value="<?= e($editMenuItem['unit_of_sale'] ?? 'plate') ?>"></label>
            <label class="checkbox-line" style="align-self:end"><input type="checkbox" name="tax_inclusive" <?= (int) ($editMenuItem['tax_inclusive'] ?? 0) === 1 ? 'checked' : '' ?>> Price is tax-inclusive</label>
            <label class="checkbox-line" style="align-self:end"><input type="checkbox" name="active" <?= (int) ($editMenuItem['active'] ?? 1) === 1 ? 'checked' : '' ?>> Active</label>
            <label class="workspace-span-2">Notes<input type="text" name="notes" maxlength="255" value="<?= e($editMenuItem['notes'] ?? '') ?>"></label>
            <div class="workspace-span-2"><button type="submit"><?= icon('badge-check') ?>Save Menu Item</button></div>
        </form>
    </section>
    <?php endif; ?>
    <?php if ($canEdit && !$editMenuItem): ?>
        <div class="notice" style="margin-bottom:14px">
            The menu is built from the daily sales upload — every distinct item on a sheet becomes a menu item the first time it is sold,
            with a standard price worked out from the busiest day it appears on. That keeps the menu matching what the kitchen is
            actually selling, and it means every item here has sales behind it to cost.
            <strong>Edit</strong> an item below to correct its price, unit or category.
            <a href="<?= e(url('admin/hospitality.php?view=sales-upload')) ?>">Upload a sales sheet →</a>
        </div>
    <?php endif; ?>
    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Menu Items (<?= count($menuItems) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Code</th><th>Name</th><th>Category</th><th class="is-numeric">Std. price</th><th>Unit</th><th>Recipes</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if ($menuItems === []): ?><tr><td colspan="8">No menu items yet — they arrive with the first daily sales sheet you upload.</td></tr><?php endif; ?>
                <?php foreach ($menuItems as $menuItem): ?>
                    <tr>
                        <td><strong><?= e($menuItem['code']) ?></strong></td>
                        <td><?= e($menuItem['name']) ?></td>
                        <td><?= e($menuItem['category']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $menuItem['standard_price']) ?></td>
                        <td><?= e($menuItem['unit_of_sale']) ?></td>
                        <td><?= (int) $menuItem['recipe_count'] > 0
                            ? '<span class="mbw-pill ' . ($menuItem['active_version'] !== null ? 'tone-green">v' . (int) $menuItem['active_version'] . ' active' : 'tone-amber">draft only') . '</span> (' . (int) $menuItem['recipe_count'] . ')'
                            : '<span class="mbw-pill tone-red">No recipe</span>' ?></td>
                        <td><span class="mbw-pill <?= (int) $menuItem['active'] === 1 ? 'tone-green' : 'tone-gray' ?>"><?= (int) $menuItem['active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                        <td style="white-space:nowrap">
                            <?php if ($canEdit): ?><a class="button secondary" style="min-height:30px;padding:3px 10px" href="<?= e(url('admin/hospitality.php?view=menu-items&item=' . (int) $menuItem['id'])) ?>">Edit</a><?php endif; ?>
                            <a class="button secondary" style="min-height:30px;padding:3px 10px" href="<?= e(url('admin/hospitality.php?view=recipes&menu_item=' . (int) $menuItem['id'])) ?>">Recipes</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'recipes'): ?>
    <?php
    $menuFilter = (int) ($_GET['menu_item'] ?? 0);
    $recipeId = (int) ($_GET['recipe'] ?? 0);
    $openRecipe = $recipeId > 0 ? hospitality_recipe($recipeId) : null;
    if ($openRecipe && (int) $openRecipe['company_id'] !== $companyId) {
        $openRecipe = null;
    }
    $recipeCostSheet = $openRecipe ? hospitality_recipe_cost((int) $openRecipe['id'], '', $settings) : null;
    $openLines = [];
    if ($openRecipe) {
        $olStmt = db()->prepare('SELECT * FROM hospitality_recipe_lines WHERE recipe_id = :rid ORDER BY id ASC');
        $olStmt->execute(['rid' => (int) $openRecipe['id']]);
        $openLines = $olStmt->fetchAll();
    }
    $recipesStmt = db()->prepare('SELECT r.*, m.code AS mi_code, m.name AS mi_name FROM hospitality_recipes r
        INNER JOIN hospitality_menu_items m ON m.id = r.menu_item_id
        WHERE r.company_id = :cid' . ($menuFilter > 0 ? ' AND r.menu_item_id = :mid' : '') . '
        ORDER BY m.code ASC, r.version DESC');
    $recipeParams = ['cid' => $companyId];
    if ($menuFilter > 0) {
        $recipeParams['mid'] = $menuFilter;
    }
    $recipesStmt->execute($recipeParams);
    $recipes = $recipesStmt->fetchAll();
    $allMenuItems = db()->prepare('SELECT id, code, name FROM hospitality_menu_items WHERE company_id = :cid AND active = 1 ORDER BY code');
    $allMenuItems->execute(['cid' => $companyId]);
    $allMenuItems = $allMenuItems->fetchAll();
    $allIngredients = db()->prepare('SELECT id, code, name, recipe_unit, active FROM hospitality_ingredients WHERE company_id = :cid AND active = 1 ORDER BY code');
    $allIngredients->execute(['cid' => $companyId]);
    $allIngredients = $allIngredients->fetchAll();
    ?>
    <?php if ($canEdit && (!$openRecipe || (string) $openRecipe['status'] === 'draft')): ?>
    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2><?= $openRecipe ? 'Edit Draft Recipe — ' . e($openRecipe['mi_code'] ?? $openRecipe['menu_item_code']) . ' v' . (int) $openRecipe['version'] : 'New Recipe (saved as draft)' ?></h2>
            <?php if ($openRecipe): ?><a class="mbw-view-all" href="<?= e(url('admin/hospitality.php?view=recipes')) ?>">Close</a><?php endif; ?></div>
        <form method="post" class="workspace-form-grid" id="recipe-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_recipe">
            <input type="hidden" name="back_view" value="recipes">
            <input type="hidden" name="recipe_id" value="<?= e((int) ($openRecipe['id'] ?? 0)) ?>">
            <label>Menu item
                <select name="menu_item_id" required>
                    <option value="">Select…</option>
                    <?php foreach ($allMenuItems as $mi): ?>
                        <option value="<?= (int) $mi['id'] ?>" <?= (int) ($openRecipe['menu_item_id'] ?? $menuFilter) === (int) $mi['id'] ? 'selected' : '' ?>><?= e($mi['code'] . ' — ' . $mi['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Recipe name<input type="text" name="name" maxlength="160" required value="<?= e($openRecipe['name'] ?? '') ?>" placeholder="Standard batch"></label>
            <label>Recipe code<input type="text" name="code" maxlength="60" value="<?= e($openRecipe['code'] ?? '') ?>"></label>
            <label>Effective from<input type="date" name="effective_from" required value="<?= e($openRecipe['effective_from'] ?? date('Y-m-d')) ?>"></label>
            <label>Effective to (optional)<input type="date" name="effective_to" value="<?= e($openRecipe['effective_to'] ?? '') ?>"></label>
            <label>Batch yield qty<input type="number" step="0.001" min="0" name="yield_qty" value="<?= e(number_format((float) ($openRecipe['yield_qty'] ?? 1), 3, '.', '')) ?>"></label>
            <label>Portions produced<input type="number" step="0.001" min="0.001" name="portions" required value="<?= e(number_format((float) ($openRecipe['portions'] ?? 1), 3, '.', '')) ?>"></label>
            <label>Portion size<input type="text" name="portion_size" maxlength="60" value="<?= e($openRecipe['portion_size'] ?? '') ?>" placeholder="1 plate / 10 pcs"></label>
            <label>Preparation wastage % (optional)<input type="number" step="0.01" min="0" max="99.99" name="prep_wastage_pct" value="<?= e(number_format((float) ($openRecipe['prep_wastage_pct'] ?? 0), 2, '.', '')) ?>"></label>
            <label>Packaging cost per batch<input type="number" step="0.01" min="0" name="packaging_cost" value="<?= e(number_format((float) ($openRecipe['packaging_cost'] ?? 0), 2, '.', '')) ?>"></label>
            <label>Other direct cost per batch<input type="number" step="0.01" min="0" name="other_cost" value="<?= e(number_format((float) ($openRecipe['other_cost'] ?? 0), 2, '.', '')) ?>"></label>
            <label class="workspace-span-2">Notes<input type="text" name="notes" maxlength="255" value="<?= e($openRecipe['notes'] ?? '') ?>"></label>
            <div class="workspace-span-2">
                <strong style="font-size:13px;color:var(--mbw-heading)">Ingredient lines</strong>
                <table id="recipe-lines" style="margin-top:6px">
                    <thead><tr><th>Ingredient</th><th class="is-numeric" style="width:140px">Quantity (recipe unit)</th><th style="width:200px">Notes</th></tr></thead>
                    <tbody>
                        <?php $lineRows = $openLines !== [] ? $openLines : array_fill(0, 3, null); ?>
                        <?php foreach ($lineRows as $lineRow): ?>
                            <tr>
                                <td><select name="line_ingredient[]">
                                    <option value="">—</option>
                                    <?php foreach ($allIngredients as $ing): ?>
                                        <option value="<?= (int) $ing['id'] ?>" <?= $lineRow !== null && (int) $lineRow['ingredient_id'] === (int) $ing['id'] ? 'selected' : '' ?>><?= e($ing['code'] . ' — ' . $ing['name'] . ' (' . $ing['recipe_unit'] . ')') ?></option>
                                    <?php endforeach; ?>
                                </select></td>
                                <td class="is-numeric"><input type="number" step="0.0001" min="0" name="line_qty[]" value="<?= $lineRow !== null ? e(number_format((float) $lineRow['quantity'], 4, '.', '')) : '' ?>"></td>
                                <td><input type="text" name="line_notes[]" maxlength="255" value="<?= e($lineRow['notes'] ?? '') ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="button" class="button secondary" style="margin-top:6px" onclick="var t=document.querySelector('#recipe-lines tbody');var r=t.rows[0].cloneNode(true);r.querySelectorAll('input').forEach(function(i){i.value='';});r.querySelector('select').selectedIndex=0;t.appendChild(r);">+ Ingredient row</button>
            </div>
            <div class="workspace-span-2"><button type="submit"><?= icon('journal') ?>Save Draft</button></div>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($openRecipe && $recipeCostSheet): ?>
    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Recipe Cost Sheet — <?= e(($openRecipe['mi_code'] ?? $openRecipe['menu_item_code']) . ' v' . $openRecipe['version']) ?>
            <span class="mbw-pill <?= $openRecipe['status'] === 'active' ? 'tone-green' : ($openRecipe['status'] === 'draft' ? 'tone-amber' : 'tone-gray') ?>"><?= e(ucfirst((string) $openRecipe['status'])) ?></span></h2>
            <div class="mbw-card-tools">
                <button type="button" class="button secondary" onclick="window.print()">Print / PDF</button>
                <?php if ($canEdit): ?>
                    <?php if ((string) $openRecipe['status'] === 'draft'): ?>
                        <form method="post" style="display:inline" data-confirm="Activate this recipe from <?= e($openRecipe['effective_from']) ?>? Any overlapping active version is end-dated the day before (history preserved).">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="recipe_status"><input type="hidden" name="target" value="activate"><input type="hidden" name="recipe_id" value="<?= (int) $openRecipe['id'] ?>">
                            <button type="submit"><?= icon('badge-check') ?>Activate</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="recipe_status"><input type="hidden" name="target" value="duplicate"><input type="hidden" name="recipe_id" value="<?= (int) $openRecipe['id'] ?>">
                        <button type="submit" class="button secondary">New Version</button>
                    </form>
                    <?php if ((string) $openRecipe['status'] !== 'archived'): ?>
                        <form method="post" style="display:inline" data-confirm="Archive this recipe version? It stays available in history.">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="recipe_status"><input type="hidden" name="target" value="archive"><input type="hidden" name="recipe_id" value="<?= (int) $openRecipe['id'] ?>">
                            <button type="submit" class="button secondary">Archive</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($recipeCostSheet['errors'] !== []): ?><div class="notice error"><?= e(implode(' | ', $recipeCostSheet['errors'])) ?></div><?php endif; ?>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Ingredient</th><th class="is-numeric">Qty</th><th>Unit</th><th class="is-numeric">Ref. cost / recipe unit</th><th>Wastage / Yield</th><th class="is-numeric">Line cost</th></tr></thead>
            <tbody>
                <?php foreach ($recipeCostSheet['lines'] as $line): ?>
                    <tr>
                        <td><?= e($line['code'] . ' — ' . $line['name']) ?><?= $line['error'] ? ' <span class="mbw-pill tone-red">' . e($line['error']) . '</span>' : '' ?></td>
                        <td class="is-numeric"><?= $fmt($line['quantity'], 3) ?></td>
                        <td><?= e($line['unit']) ?></td>
                        <td class="is-numeric"><?= $line['unit_cost'] !== null ? $fmt($line['unit_cost'], 4) : 'N/A' ?></td>
                        <td><small><?= e(number_format($line['wastage_pct'], 1)) ?>% / <?= e(number_format($line['yield_pct'], 1)) ?>%</small></td>
                        <td class="is-numeric"><?= $line['line_cost'] !== null ? $fmt($line['line_cost']) : 'N/A' ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="font-weight:700"><td colspan="5">Total ingredient cost<?= $recipeCostSheet['prep_wastage_pct'] > 0 ? ' (+' . e(number_format($recipeCostSheet['prep_wastage_pct'], 1)) . '% prep wastage → ' . $fmt($recipeCostSheet['adjusted_ingredient_total']) . ')' : '' ?></td><td class="is-numeric"><?= $fmt($recipeCostSheet['ingredient_total']) ?></td></tr>
                <tr><td colspan="5">Packaging + other direct cost per batch</td><td class="is-numeric"><?= $fmt($recipeCostSheet['packaging_cost'] + $recipeCostSheet['other_cost']) ?></td></tr>
                <tr style="font-weight:700"><td colspan="5">Total batch cost</td><td class="is-numeric"><?= $fmt($recipeCostSheet['batch_cost']) ?></td></tr>
                <tr style="font-weight:800"><td colspan="5">Estimated cost per portion (<?= $fmt($recipeCostSheet['portions'], 3) ?> portions)</td><td class="is-numeric"><?= $fmt($recipeCostSheet['cost_per_portion'], 4) ?></td></tr>
                <?php foreach (['kitchen_wastage' => 'General kitchen wastage (setting)', 'packaging' => 'Packaging % (setting)', 'other_variable' => 'Other variable % (setting)'] as $ovKey => $ovLabel): ?>
                    <?php if ((float) $recipeCostSheet['overlay'][$ovKey] > 0): ?>
                        <tr><td colspan="5"><?= e($ovLabel) ?> — shown separately</td><td class="is-numeric">+<?= $fmt($recipeCostSheet['overlay'][$ovKey], 4) ?></td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <tr style="font-weight:800"><td colspan="5">Effective cost per portion used for costing</td><td class="is-numeric"><?= $fmt($recipeCostSheet['effective_cost_per_portion'], 4) ?></td></tr>
            </tbody>
        </table></div>
        <p style="margin:8px 0 0;color:var(--mbw-muted);font-size:12px"><?= e($disclaimer) ?></p>
    </section>
    <?php endif; ?>

    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Recipes (<?= count($recipes) ?>)</h2><?php if ($menuFilter > 0): ?><a class="mbw-view-all" href="<?= e(url('admin/hospitality.php?view=recipes')) ?>">Show all</a><?php endif; ?></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Menu item</th><th>Recipe</th><th>Version</th><th>Effective</th><th class="is-numeric">Portions</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if ($recipes === []): ?><tr><td colspan="7">No recipes yet.</td></tr><?php endif; ?>
                <?php foreach ($recipes as $recipe): ?>
                    <tr>
                        <td><strong><?= e($recipe['mi_code']) ?></strong> <?= e($recipe['mi_name']) ?></td>
                        <td><?= e($recipe['name']) ?></td>
                        <td>v<?= (int) $recipe['version'] ?></td>
                        <td><small><?= e($recipe['effective_from']) ?><?= $recipe['effective_to'] ? ' → ' . e($recipe['effective_to']) : ' → open' ?></small></td>
                        <td class="is-numeric"><?= $fmt((float) $recipe['portions'], 3) ?></td>
                        <td><span class="mbw-pill <?= $recipe['status'] === 'active' ? 'tone-green' : ($recipe['status'] === 'draft' ? 'tone-amber' : 'tone-gray') ?>"><?= e(ucfirst((string) $recipe['status'])) ?></span></td>
                        <td><a class="button secondary" style="min-height:30px;padding:3px 10px" href="<?= e(url('admin/hospitality.php?view=recipes&recipe=' . (int) $recipe['id'])) ?>">Cost sheet</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'mapping'): ?>
    <?php
    // Unmapped queue: eligible sales in the range without an approved mapping.
    $queue = [];
    foreach (hospitality_eligible_sales_lines($companyId, $rangeFrom, $rangeTo) as $sale) {
        $mapping = hospitality_resolve_mapping($companyId, $sale['item_id'] !== null ? (int) $sale['item_id'] : null, (string) $sale['description'], (string) $sale['issued_on']);
        if ($mapping === null) {
            $key = ($sale['item_id'] !== null ? 'i' . $sale['item_id'] : 'd' . hospitality_normalize_desc((string) $sale['description']));
            if (!isset($queue[$key])) {
                $queue[$key] = ['item_id' => $sale['item_id'], 'description' => (string) $sale['description'], 'qty' => 0.0, 'value' => 0.0, 'first' => (string) $sale['issued_on'], 'invoice_no' => (string) $sale['invoice_no']];
            }
            $queue[$key]['qty'] += (float) $sale['quantity'];
            $queue[$key]['value'] += (float) $sale['taxable_amount'];
        }
    }
    $mappingsStmt = db()->prepare('SELECT sm.*, m.code AS mi_code, m.name AS mi_name FROM hospitality_sales_mappings sm
        LEFT JOIN hospitality_menu_items m ON m.id = sm.menu_item_id
        WHERE sm.company_id = :cid ORDER BY sm.id DESC LIMIT 200');
    $mappingsStmt->execute(['cid' => $companyId]);
    $mappings = $mappingsStmt->fetchAll();
    $allMenuItems = db()->prepare('SELECT id, code, name FROM hospitality_menu_items WHERE company_id = :cid AND active = 1 ORDER BY code');
    $allMenuItems->execute(['cid' => $companyId]);
    $allMenuItems = $allMenuItems->fetchAll();
    ?>
    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Unmapped Sales Items (<?= count($queue) ?>)</h2>
            <form method="get" style="display:flex;gap:8px;align-items:center">
                <input type="hidden" name="view" value="mapping">
                <input type="date" name="from" value="<?= e($rangeFrom) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>">
                <input type="date" name="to" value="<?= e($rangeTo) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>">
                <button type="submit" class="button secondary" style="min-height:32px">Filter</button>
            </form>
        </div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Sales description</th><th>Item ID</th><th>First sale / invoice</th><th class="is-numeric">Qty</th><th class="is-numeric">Sales value</th><th style="min-width:340px">Map to menu item / Ignore</th></tr></thead>
            <tbody>
                <?php if ($queue === []): ?><tr><td colspan="6">No unmapped sales in this range — everything is mapped, ignored, or there are no sales.</td></tr><?php endif; ?>
                <?php foreach ($queue as $entry): ?>
                    <tr>
                        <td><?= e($entry['description']) ?></td>
                        <td><?= $entry['item_id'] !== null ? '#' . (int) $entry['item_id'] : '—' ?></td>
                        <td><small><?= e($entry['first']) ?> · <?= e($entry['invoice_no']) ?></small></td>
                        <td class="is-numeric"><?= $fmt($entry['qty'], 3) ?></td>
                        <td class="is-numeric"><?= $fmt($entry['value']) ?></td>
                        <td>
                            <?php if ($canEdit): ?>
                            <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="save_mapping">
                                <input type="hidden" name="back_view" value="mapping">
                                <input type="hidden" name="match_type" value="<?= $entry['item_id'] !== null ? 'item' : 'description' ?>">
                                <input type="hidden" name="sales_item_id" value="<?= (int) ($entry['item_id'] ?? 0) ?>">
                                <input type="hidden" name="sales_description" value="<?= e($entry['description']) ?>">
                                <select name="menu_item_id" required style="min-height:32px;max-width:220px">
                                    <option value="">Map to…</option>
                                    <?php foreach ($allMenuItems as $mi): ?><option value="<?= (int) $mi['id'] ?>"><?= e($mi['code'] . ' — ' . $mi['name']) ?></option><?php endforeach; ?>
                                </select>
                                <button type="submit" class="button secondary" style="min-height:32px;padding:4px 10px">Map</button>
                            </form>
                            <form method="post" style="display:flex;gap:6px;align-items:center;margin-top:4px">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="ignore_sales_item">
                                <input type="hidden" name="back_view" value="mapping">
                                <input type="hidden" name="sales_item_id" value="<?= (int) ($entry['item_id'] ?? 0) ?>">
                                <input type="hidden" name="sales_description" value="<?= e($entry['description']) ?>">
                                <input type="text" name="ignore_reason" placeholder="Ignore reason (optional)" maxlength="255" style="min-height:32px">
                                <button type="submit" class="button secondary" style="min-height:32px;padding:4px 10px">Ignore</button>
                            </form>
                            <?php else: ?><span style="color:var(--mbw-muted);font-size:12px">No mapping permission</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <p style="margin:8px 0 0;color:var(--mbw-muted);font-size:12px">Mapping never changes the original sales record. Different sales descriptions can map to the same menu item.</p>
    </section>
    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Approved Mappings &amp; Ignores</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Match</th><th>Menu item</th><th>Status</th><th>Effective</th><th>Notes / reason</th></tr></thead>
            <tbody>
                <?php if ($mappings === []): ?><tr><td colspan="5">No mappings yet.</td></tr><?php endif; ?>
                <?php foreach ($mappings as $mapping): ?>
                    <tr>
                        <td><?= $mapping['match_type'] === 'item' ? 'Item #' . (int) $mapping['item_id'] : '"' . e((string) $mapping['description_norm']) . '"' ?></td>
                        <td><?= $mapping['mi_code'] ? e($mapping['mi_code'] . ' — ' . $mapping['mi_name']) : '—' ?></td>
                        <td><span class="mbw-pill <?= $mapping['status'] === 'mapped' ? 'tone-green' : 'tone-gray' ?>"><?= e(ucfirst((string) $mapping['status'])) ?></span><?= (int) $mapping['active'] !== 1 ? ' <span class="mbw-pill tone-red">inactive</span>' : '' ?></td>
                        <td><small><?= e($mapping['effective_from'] ?? 'always') ?><?= $mapping['effective_to'] ? ' → ' . e($mapping['effective_to']) : '' ?></small></td>
                        <td><small><?= e((string) ($mapping['notes'] ?? $mapping['ignore_reason'] ?? '')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'costing'): ?>
    <?php
    $linesStmt = db()->prepare('SELECT * FROM hospitality_costing_lines WHERE company_id = :cid AND sale_date BETWEEN :f AND :t ORDER BY sale_date DESC, id ASC LIMIT 500');
    $linesStmt->execute(['cid' => $companyId, 'f' => $rangeFrom, 't' => $rangeTo]);
    $costingLines = $linesStmt->fetchAll();
    $runsStmt = db()->prepare('SELECT r.*, u.name AS generated_name FROM hospitality_costing_runs r LEFT JOIN users u ON u.id = r.generated_by
        WHERE r.company_id = :cid AND r.costing_date BETWEEN :f AND :t ORDER BY r.costing_date DESC');
    $runsStmt->execute(['cid' => $companyId, 'f' => $rangeFrom, 't' => $rangeTo]);
    $runs = $runsStmt->fetchAll();
    $statusTones = ['costed' => 'tone-green', 'unmapped' => 'tone-amber', 'ignored' => 'tone-gray', 'missing_recipe' => 'tone-red', 'draft_recipe' => 'tone-amber', 'missing_cost' => 'tone-red', 'invalid_conversion' => 'tone-red', 'no_quantity' => 'tone-gray'];
    ?>
    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Daily Costing</h2>
            <form method="get" style="display:flex;gap:8px;align-items:center">
                <input type="hidden" name="view" value="costing">
                <input type="date" name="from" value="<?= e($rangeFrom) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>">
                <input type="date" name="to" value="<?= e($rangeTo) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>">
                <button type="submit" class="button secondary" style="min-height:32px">Filter</button>
            </form>
        </div>
        <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
            <?php if ($canCost): ?>
            <form method="post" style="display:flex;gap:8px;align-items:flex-end">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="generate_costing">
                <label>Generate for date<input type="date" name="costing_date" value="<?= e($todayInFy) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>" required></label>
                <button type="submit"><?= icon('reconcile') ?>Generate Costing</button>
            </form>
            <?php endif; ?>
            <?php if ($canRecalc): ?>
            <form method="post" style="display:flex;gap:8px;align-items:flex-end" data-confirm="Recalculate this date with CURRENT recipes and costs? The existing snapshot totals are saved to the audit history first, and the original sales records are never touched.">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="recalculate_costing">
                <label>Recalculate date<input type="date" name="costing_date" value="<?= e($todayInFy) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>" required></label>
                <label>Reason (required)<input type="text" name="reason" maxlength="255" required placeholder="e.g. mapping added for momo"></label>
                <button type="submit" class="button secondary">Recalculate</button>
            </form>
            <?php endif; ?>
        </div>
        <?php if ($runs !== []): ?>
            <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12px">
                Runs in range: <?php foreach ($runs as $i => $run): ?><?= $i > 0 ? ' · ' : '' ?><?= e($run['costing_date']) ?> (<?= e($run['status']) ?><?= $run['recalculated_at'] ? ', recalculated' : '' ?>)<?php endforeach; ?>
            </p>
        <?php endif; ?>
    </section>
    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Costing Lines (<?= count($costingLines) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Date</th><th>Invoice</th><th>Sales item</th><th>Menu item</th><th class="is-numeric">Net qty</th><th class="is-numeric">Gross</th><th class="is-numeric">Disc.</th><th class="is-numeric">VAT</th><th class="is-numeric">Net sales</th><th>Recipe</th><th class="is-numeric">Unit cost</th><th class="is-numeric">Est. cost</th><th class="is-numeric">Est. GP</th><th class="is-numeric">GP %</th><th>Status</th></tr></thead>
            <tbody>
                <?php if ($costingLines === []): ?><tr><td colspan="15">No costing snapshots in this range. Generate costing for a date with sales.</td></tr><?php endif; ?>
                <?php foreach ($costingLines as $line): ?>
                    <tr>
                        <td><small><?= e($line['sale_date']) ?></small></td>
                        <td><small><?= e($line['invoice_no'] ?? ('#' . $line['invoice_id'])) ?></small></td>
                        <td><?= e($line['description'] ?? '') ?></td>
                        <td><?= $line['menu_item_code'] ? e($line['menu_item_code']) : '—' ?></td>
                        <td class="is-numeric"><?= $fmt((float) $line['net_qty'], 3) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $line['gross_sales']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $line['discount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $line['vat']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $line['net_sales']) ?></td>
                        <td><?= $line['recipe_version'] !== null ? 'v' . (int) $line['recipe_version'] : '—' ?></td>
                        <td class="is-numeric"><?= $line['unit_cost'] !== null ? $fmt((float) $line['unit_cost'], 4) : 'N/A' ?></td>
                        <td class="is-numeric"><?= $line['total_cost'] !== null ? $fmt((float) $line['total_cost']) : 'N/A' ?></td>
                        <td class="is-numeric"><?= $line['gross_profit'] !== null ? $fmt((float) $line['gross_profit']) : 'N/A' ?></td>
                        <td class="is-numeric"><?= $line['gp_pct'] !== null ? $fmt((float) $line['gp_pct']) . '%' : 'N/A' ?></td>
                        <td><span class="mbw-pill <?= e($statusTones[(string) $line['status']] ?? 'tone-gray') ?>"><?= e(str_replace('_', ' ', (string) $line['status'])) ?></span><?= $line['warning'] ? '<br><small style="color:var(--mbw-muted)">' . e($line['warning']) . '</small>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <p style="margin:8px 0 0;color:var(--mbw-muted);font-size:12px"><?= e($disclaimer) ?> Snapshots keep the recipe and costs used on the sale date; later changes never rewrite them.</p>
    </section>

<?php elseif ($view === 'gp' || $view === 'reports'): ?>
    <?php
    $reportKey = (string) ($_GET['report'] ?? ($view === 'gp' ? 'item' : 'consolidated'));
    $summary = hospitality_summary($companyId, $rangeFrom, $rangeTo);
    $categoryRows = hospitality_grouped($companyId, $rangeFrom, $rangeTo, 'category');
    $itemRows = hospitality_grouped($companyId, $rangeFrom, $rangeTo, 'menu_item');
    $periodRows = hospitality_grouped($companyId, $rangeFrom, $rangeTo, 'period_day');
    $exceptions = hospitality_exceptions($companyId, $rangeFrom, $rangeTo);
    // Previous equivalent period (immediately preceding, same length).
    $spanDays = (int) ((strtotime($rangeTo) - strtotime($rangeFrom)) / 86400) + 1;
    $prevTo = date('Y-m-d', strtotime($rangeFrom . ' -1 day'));
    $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($spanDays - 1) . ' days'));
    $prevComparable = $prevFrom >= $fyStart;
    $prevSummary = $prevComparable ? hospitality_summary($companyId, $prevFrom, $prevTo) : null;
    ?>
    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2><?= $view === 'gp' ? 'Estimated Gross Profit' : 'Hospitality Reports' ?> — <?= e(($fiscalYear['label'] ?? '') . ' · ' . $rangeFrom . ' → ' . $rangeTo) ?></h2>
            <div class="mbw-card-tools">
                <form method="get" style="display:flex;gap:8px;align-items:center">
                    <input type="hidden" name="view" value="<?= e($view) ?>">
                    <input type="hidden" name="report" value="<?= e($reportKey) ?>">
                    <input type="date" name="from" value="<?= e($rangeFrom) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>">
                    <input type="date" name="to" value="<?= e($rangeTo) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>">
                    <button type="submit" class="button secondary" style="min-height:32px">Apply</button>
                </form>
                <button type="button" class="button secondary" onclick="window.print()">Print / PDF</button>
                <?php if ($canExport): ?>
                    <a class="button secondary" href="<?= e(url('admin/hospitality.php?view=' . $view . '&report=item&export=csv&from=' . $rangeFrom . '&to=' . $rangeTo)) ?>">Items CSV</a>
                    <a class="button secondary" href="<?= e(url('admin/hospitality.php?view=' . $view . '&report=category&export=csv&from=' . $rangeFrom . '&to=' . $rangeTo)) ?>">Categories CSV</a>
                    <a class="button secondary" href="<?= e(url('admin/hospitality.php?view=' . $view . '&report=daily&export=csv&from=' . $rangeFrom . '&to=' . $rangeTo)) ?>">Daily CSV</a>
                <?php endif; ?>
            </div>
        </div>
        <p style="margin:0;color:var(--mbw-muted);font-size:12px">Generated <?= e(date('Y-m-d H:i')) ?> by <?= e((string) ($currentUser['name'] ?? '')) ?> · <?= e($company['name']) ?> · Estimated management report based on configured recipes and reference ingredient costs. No accounting or inventory entry has been posted.</p>
    </section>

    <section class="mbw-kpi-grid" aria-label="Consolidated summary">
        <?php foreach ([
            ['Net Sales (costed)', $sym . $fmt($summary['costed_net_sales']), 'wallet', 'tone-blue'],
            ['Estimated Recipe Cost', $sym . $fmt($summary['est_cost']), 'box', 'tone-amber'],
            ['Estimated Gross Profit', $sym . $fmt($summary['est_gp']), 'analytics', 'tone-green'],
            ['Consolidated Weighted GP %', $fmt($summary['weighted_gp_pct']) . ($summary['weighted_gp_pct'] !== null ? '%' : ''), 'pie', 'tone-green'],
            ['Simple Avg Item GP % (secondary)', $fmt($summary['simple_avg_item_gp_pct']) . ($summary['simple_avg_item_gp_pct'] !== null ? '%' : ''), 'layers', 'tone-gray'],
            ['Successfully Costed Sales %', $fmt($summary['completeness_pct']) . ($summary['completeness_pct'] !== null ? '%' : ''), 'reconcile', 'tone-teal'],
            ['Est. GP per Sale', $sym . $fmt($summary['gp_per_sale']), 'receipt-voucher', 'tone-blue'],
            ['Est. GP per Unit', $sym . $fmt($summary['gp_per_unit']), 'tag', 'tone-blue'],
            ['Uncosted Sales (shown apart)', $sym . $fmt($summary['uncosted_value']), 'search', 'tone-amber'],
        ] as [$kpiLabel, $kpiValue, $kpiIcon, $kpiTone]): ?>
            <article class="mbw-kpi"><div><span class="mbw-kpi-label"><?= e($kpiLabel) ?></span><div class="mbw-kpi-value" style="font-size:1.0rem"><?= e($kpiValue) ?></div></div><span class="mbw-chip <?= e($kpiTone) ?>"><?= icon($kpiIcon) ?></span></article>
        <?php endforeach; ?>
    </section>

    <?php if ($prevSummary !== null): ?>
    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Comparison with previous equivalent period (<?= e($prevFrom . ' → ' . $prevTo) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Measure</th><th class="is-numeric">Current</th><th class="is-numeric">Previous</th><th class="is-numeric">Change</th><th class="is-numeric">Change %</th></tr></thead>
            <tbody>
                <?php foreach ([
                    ['Net sales (costed)', $summary['costed_net_sales'], $prevSummary['costed_net_sales']],
                    ['Estimated cost', $summary['est_cost'], $prevSummary['est_cost']],
                    ['Estimated GP', $summary['est_gp'], $prevSummary['est_gp']],
                ] as [$measureLabel, $cur, $prev]): ?>
                    <tr><td><?= e($measureLabel) ?></td><td class="is-numeric"><?= $fmt($cur) ?></td><td class="is-numeric"><?= $fmt($prev) ?></td>
                        <td class="is-numeric"><?= $fmt($cur - $prev) ?></td>
                        <td class="is-numeric"><?= $prev != 0.0 ? $fmt(($cur - $prev) / abs($prev) * 100) . '%' : 'N/A' ?></td></tr>
                <?php endforeach; ?>
                <tr><td>Weighted GP ratio</td>
                    <td class="is-numeric"><?= $fmt($summary['weighted_gp_pct']) ?><?= $summary['weighted_gp_pct'] !== null ? '%' : '' ?></td>
                    <td class="is-numeric"><?= $fmt($prevSummary['weighted_gp_pct']) ?><?= $prevSummary['weighted_gp_pct'] !== null ? '%' : '' ?></td>
                    <td class="is-numeric" colspan="2"><?= ($summary['weighted_gp_pct'] !== null && $prevSummary['weighted_gp_pct'] !== null)
                        ? $fmt($summary['weighted_gp_pct'] - $prevSummary['weighted_gp_pct']) . ' percentage-point movement' : 'N/A — comparison data incomplete' ?></td></tr>
            </tbody>
        </table></div>
    </section>
    <?php else: ?>
        <div class="notice">Previous-period comparison: N/A — the preceding equivalent period falls outside the selected fiscal year.</div>
    <?php endif; ?>

    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Category-Wise Estimated GP</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Category</th><th class="is-numeric">Items</th><th class="is-numeric">Net qty</th><th class="is-numeric">Gross</th><th class="is-numeric">Disc.</th><th class="is-numeric">VAT</th><th class="is-numeric">Net sales</th><th class="is-numeric">Est. cost</th><th class="is-numeric">Est. GP</th><th class="is-numeric">Weighted GP %</th><th class="is-numeric">Sales contrib.</th><th class="is-numeric">GP contrib.</th></tr></thead>
            <tbody>
                <?php if ($categoryRows === []): ?><tr><td colspan="12">No valid costed sales in this range.</td></tr><?php endif; ?>
                <?php $catTotals = ['net' => 0.0, 'cost' => 0.0, 'gp' => 0.0]; ?>
                <?php foreach ($categoryRows as $row): ?>
                    <?php $catTotals['net'] += $row['net_sales']; $catTotals['cost'] += $row['est_cost']; $catTotals['gp'] += $row['est_gp']; ?>
                    <tr>
                        <td><a href="<?= e(url('admin/hospitality.php?view=gp&report=item&from=' . $rangeFrom . '&to=' . $rangeTo)) ?>"><?= e($row['group']) ?></a></td>
                        <td class="is-numeric"><?= (int) $row['item_count'] ?></td>
                        <td class="is-numeric"><?= $fmt($row['net_qty'], 3) ?></td>
                        <td class="is-numeric"><?= $fmt($row['gross_sales']) ?></td>
                        <td class="is-numeric"><?= $fmt($row['discount']) ?></td>
                        <td class="is-numeric"><?= $fmt($row['vat']) ?></td>
                        <td class="is-numeric"><?= $fmt($row['net_sales']) ?></td>
                        <td class="is-numeric"><?= $fmt($row['est_cost']) ?></td>
                        <td class="is-numeric"><?= $fmt($row['est_gp']) ?></td>
                        <td class="is-numeric"><?= $fmt($row['gp_pct']) ?><?= $row['gp_pct'] !== null ? '%' : '' ?></td>
                        <td class="is-numeric"><?= $fmt($row['sales_contribution_pct']) ?><?= $row['sales_contribution_pct'] !== null ? '%' : '' ?></td>
                        <td class="is-numeric"><?= $fmt($row['gp_contribution_pct']) ?><?= $row['gp_contribution_pct'] !== null ? '%' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($categoryRows !== []): ?>
                    <tr style="font-weight:700"><td colspan="6">Total (reconciles with consolidated costed figures)</td><td class="is-numeric"><?= $fmt($catTotals['net']) ?></td><td class="is-numeric"><?= $fmt($catTotals['cost']) ?></td><td class="is-numeric"><?= $fmt($catTotals['gp']) ?></td><td class="is-numeric"><?= $catTotals['net'] != 0.0 ? $fmt($catTotals['gp'] / $catTotals['net'] * 100) . '%' : 'N/A' ?></td><td colspan="2"></td></tr>
                <?php endif; ?>
            </tbody>
        </table></div>
        <p style="margin:8px 0 0;color:var(--mbw-muted);font-size:12px" title="Weighted = total GP ÷ total net sales. A simple average of item percentages would treat a tiny item like a large one.">Weighted GP % = category estimated GP ÷ category net sales × 100 (never a plain average of item GP percentages).</p>
    </section>

    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Item-Wise Estimated GP</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Menu item</th><th class="is-numeric">Qty</th><th class="is-numeric">Ret.</th><th class="is-numeric">Net qty</th><th class="is-numeric">Net sales</th><th class="is-numeric">Avg price</th><th class="is-numeric">Avg cost</th><th class="is-numeric">Est. cost</th><th class="is-numeric">Est. GP</th><th class="is-numeric">GP %</th><th class="is-numeric">Sales contrib.</th><th class="is-numeric">GP contrib.</th><th class="is-numeric">Txns</th><th>Last sale</th></tr></thead>
            <tbody>
                <?php if ($itemRows === []): ?><tr><td colspan="14">No valid costed sales in this range.</td></tr><?php endif; ?>
                <?php foreach ($itemRows as $row): ?>
                    <tr>
                        <td><a href="<?= e(url('admin/hospitality.php?view=costing&from=' . $rangeFrom . '&to=' . $rangeTo)) ?>"><?= e($row['group']) ?></a></td>
                        <td class="is-numeric"><?= $fmt($row['qty_sold'], 3) ?></td>
                        <td class="is-numeric"><?= $fmt($row['qty_returned'], 3) ?></td>
                        <td class="is-numeric"><?= $fmt($row['net_qty'], 3) ?></td>
                        <td class="is-numeric"><?= $fmt($row['net_sales']) ?></td>
                        <td class="is-numeric"><?= $fmt($row['avg_price']) ?></td>
                        <td class="is-numeric"><?= $fmt($row['avg_cost'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt($row['est_cost']) ?></td>
                        <td class="is-numeric"><?= $fmt($row['est_gp']) ?></td>
                        <td class="is-numeric"><?= $fmt($row['gp_pct']) ?><?= $row['gp_pct'] !== null ? '%' : '' ?></td>
                        <td class="is-numeric"><?= $fmt($row['sales_contribution_pct']) ?><?= $row['sales_contribution_pct'] !== null ? '%' : '' ?></td>
                        <td class="is-numeric"><?= $fmt($row['gp_contribution_pct']) ?><?= $row['gp_contribution_pct'] !== null ? '%' : '' ?></td>
                        <td class="is-numeric"><?= (int) $row['txn_count'] ?></td>
                        <td><small><?= e($row['last_sale']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Period-Wise Trend (daily)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Date</th><th class="is-numeric">Net sales</th><th class="is-numeric">Est. cost</th><th class="is-numeric">Est. GP</th><th class="is-numeric">Weighted GP %</th></tr></thead>
            <tbody>
                <?php if ($periodRows === []): ?><tr><td colspan="5">No costed days in this range.</td></tr><?php endif; ?>
                <?php $sortedPeriod = $periodRows; usort($sortedPeriod, static fn (array $a, array $b): int => strcmp($a['group'], $b['group'])); ?>
                <?php foreach ($sortedPeriod as $row): ?>
                    <tr><td><?= e($row['group']) ?></td><td class="is-numeric"><?= $fmt($row['net_sales']) ?></td><td class="is-numeric"><?= $fmt($row['est_cost']) ?></td><td class="is-numeric"><?= $fmt($row['est_gp']) ?></td><td class="is-numeric"><?= $fmt($row['gp_pct']) ?><?= $row['gp_pct'] !== null ? '%' : '' ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Exceptions (<?= count($exceptions) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Date</th><th>Invoice</th><th>Sales item</th><th class="is-numeric">Net sales</th><th>Status</th><th>Detail</th></tr></thead>
            <tbody>
                <?php if ($exceptions === []): ?><tr><td colspan="6">No exceptions — every eligible sales line is costed or deliberately ignored.</td></tr><?php endif; ?>
                <?php foreach ($exceptions as $line): ?>
                    <tr>
                        <td><small><?= e($line['sale_date']) ?></small></td>
                        <td><small><?= e($line['invoice_no'] ?? ('#' . $line['invoice_id'])) ?></small></td>
                        <td><?= e($line['description'] ?? '') ?></td>
                        <td class="is-numeric"><?= $fmt((float) $line['net_sales']) ?></td>
                        <td><span class="mbw-pill tone-amber"><?= e(str_replace('_', ' ', (string) $line['status'])) ?></span></td>
                        <td><small><?= e($line['warning'] ?? '') ?></small></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Method &amp; Disclaimer</h2></div>
        <p style="font-size:12.5px;color:var(--mbw-muted);line-height:1.7;margin:0">
            Costing basis: <?= e((string) $settings['costing_basis'] === 'sale_date' ? 'recipe and ingredient costs in force on the SALE date' : 'costs at calculation date') ?>.
            Ingredient cost source: <?= e((string) $settings['cost_source']) ?>.
            VAT: sales are analysed <?= (int) $settings['net_of_vat'] === 1 ? 'net of VAT/tax' : 'inclusive of VAT/tax' ?>.
            Invoice discount: <?= (int) $settings['include_invoice_discount'] === 1 ? 'allocated to lines in proportion to line taxable value' : 'not allocated' ?>.
            Returns: negative-quantity lines reverse consistently; cancelled and draft sales are excluded.
            Uncosted sales are shown separately and are NEVER treated as zero-cost.
            Recipe effective dating: the active version covering the sale date is snapshotted; later changes never rewrite history (use audited Recalculate).
            <strong>Reference estimate only. This report does not represent posted Cost of Goods Sold and does not create or modify accounting entries.</strong>
        </p>
    </section>

<?php elseif ($view === 'sales-upload'): ?>
    <?php
    // false: this screen posts the two-sheet upload, whose debit comes from the
    // invoice sheet rather than a receivable ledger.
    $configErrors = hospitality_posting_config_errors($companyId, $settings, false);

    // Preview the held pair when a token is present. Problems render inline
    // (the page header is already out, so flash() would only show next load).
    $salesPreview = null;
    $salesPreviewProblem = null;
    $salesToken = (string) ($_GET['token'] ?? '');
    if ($salesToken !== '') {
        $storedSheet = hospitality_sales_stored_file($salesUploadDir, $salesToken);
        if ($storedSheet === null) {
            $salesToken = '';
            $salesPreviewProblem = 'The uploaded sheets have expired. Upload them again.';
        } else {
            $storedSecond = hospitality_sales_stored_file($salesUploadDir, $salesToken, '-b');
            try {
                $salesPreview = hospitality_workbook_parse(
                    $storedSheet['path'], $storedSheet['extension'],
                    $storedSecond['path'] ?? null, $storedSecond['extension'] ?? null,
                    $companyId, $fiscalYearId, $settings
                );
            } catch (Throwable $exception) {
                $salesPreviewProblem = 'Could not read the sheets: ' . $exception->getMessage();
            }
            if ($salesPreview !== null && isset($salesPreview['error'])) {
                // The files are deliberately KEPT: the usual reason to land
                // here is a missing second sheet, and the fix is to add it
                // rather than to upload the first one all over again.
                $salesPreviewProblem = (string) $salesPreview['error'];
                $salesPreview = null;
            }
        }
    }

    ?>

    <?php if ($salesPreview === null): ?>
    <?php if ($salesPreviewProblem !== null): ?>
        <div class="notice error" style="margin-bottom:14px"><?= e($salesPreviewProblem) ?></div>
    <?php endif; ?>
    <?php if ($configErrors !== []): ?>
        <div class="notice error" style="margin-bottom:14px">
            <strong>Before posting:</strong> <?= e(implode(' ', $configErrors)) ?>
            <a href="<?= e(url('admin/hospitality.php?view=settings')) ?>">Open Settings →</a>
        </div>
    <?php endif; ?>

    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Upload Daily Sales Sheet</h2></div>
        <?php if (!$canPost): ?>
            <div class="notice error">You do not have permission to post sales (hospitality create permission needed).</div>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="sales_upload_file">
            <input type="hidden" name="back_view" value="sales-upload">
            <label class="workspace-span-2">Sales workbook — both sheets (.xlsx, max 10 MB)
                <input type="file" name="sales_file" accept=".xlsx,.csv" required>
                <small style="color:var(--mbw-muted)">One Excel file holding the <strong>item-wise</strong> sheet (what was sold) and the <strong>invoice-wise</strong> sheet (how it was settled). Working in CSV? Put the item-wise sheet here and the invoice-wise one below.</small>
            </label>
            <label class="workspace-span-2">Second sheet — only if you are uploading two files
                <input type="file" name="sales_file_2" accept=".xlsx,.csv">
                <small style="color:var(--mbw-muted)">Leave this empty when your workbook already carries both sheets. Either order is fine — they are told apart by their column headings.</small>
            </label>
            <div class="workspace-span-2">
                <button type="submit"><?= icon('upload') ?>Upload &amp; Preview</button>
                <?php if ($hasXlsx): ?><a class="button secondary" href="<?= e(url('admin/hospitality.php?view=sales-upload&template=xlsx')) ?>">Excel template (both sheets)</a><?php endif; ?>
                <a class="button secondary" href="<?= e(url('admin/hospitality.php?view=sales-upload&template=csv')) ?>">CSV — item-wise</a>
                <a class="button secondary" href="<?= e(url('admin/hospitality.php?view=sales-upload&template=csv-invoices')) ?>">CSV — invoice-wise</a>
            </div>
        </form>
        <?php endif; ?>
        <div style="overflow-x:auto;margin-top:10px"><table>
            <thead><tr><th>Column</th><th>Required</th><th>What it takes</th></tr></thead>
            <tbody>
                <tr><td>Date (AD or BS)</td><td>Yes</td><td>YYYY-MM-DD — years 2064+ are read as Bikram Sambat; Excel date cells also work. Rows are grouped into one voucher per date.</td></tr>
                <tr><td>Category</td><td>Yes</td><td>Sales category (Food, Beverage, Bar…). Decides the sales ledger via the mapping above.</td></tr>
                <tr><td>Item</td><td>Yes</td><td>Item name. An item-level mapping, when present, overrides the category ledger.</td></tr>
                <tr><td>Qty</td><td>Optional</td><td>Units sold (kept for the record; the amounts drive the posting).</td></tr>
                <tr><td>Total Sales Amount</td><td>Yes</td><td>Row's sales value before discount<?= (int) ($settings['post_amount_includes_vat'] ?? 1) === 1 ? ', including VAT (extracted at ' . e(number_format((float) ($settings['post_vat_rate'] ?? 13), 2)) . '%)' : ' excluding VAT (added at ' . e(number_format((float) ($settings['post_vat_rate'] ?? 13), 2)) . '%)' ?>.</td></tr>
                <tr><td>Discount</td><td>Optional</td><td>Discount given on the row — debited to the Discount ledger and deducted from the receivable.</td></tr>
                <tr><td>VAT</td><td>Optional</td><td>Explicit VAT amount; when filled it overrides the rate-based calculation for that row.</td></tr>
            </tbody>
        </table></div>
    </section>

    <?php
    // What used to be an Upload History is now a set of readings OF what was
    // uploaded. The sheets are the record, so a report here can only ever be a
    // view of them -- there is no second accumulation to drift out of step.
    $reportKey = (string) ($_GET['report'] ?? 'sheet');
    $reportOptions = hospitality_sales_report_options();
    if (!isset($reportOptions[$reportKey])) {
        $reportKey = 'sheet';
    }
    [$defaultFrom, $defaultTo] = hospitality_sales_report_default_range($companyId);
    $reportFrom = $clampDate(trim((string) ($_GET['rfrom'] ?? $defaultFrom)));
    $reportTo = $clampDate(trim((string) ($_GET['rto'] ?? $defaultTo)));
    if ($reportTo < $reportFrom) {
        $reportTo = $reportFrom;
    }
    $reportPage = max(1, (int) ($_GET['rpage'] ?? 1));
    $reportSort = (string) ($_GET['rsort'] ?? '');
    $reportDir = jw_enum_like($_GET['rdir'] ?? null, ['asc', 'desc'], 'asc');
    $report = hospitality_sales_report($companyId, $reportFrom, $reportTo, $reportKey, $reportPage, 200, $reportSort, $reportDir);
    $reportPages = (int) ceil($report['total_rows'] / 200);
    /** The query behind every link on this card, so filter and sort travel together. */
    $reportQuery = static function (array $overrides = []) use ($reportKey, $reportFrom, $reportTo, $reportSort, $reportDir, $reportPage): string {
        return http_build_query(array_merge([
            'view' => 'sales-upload', 'report' => $reportKey,
            'rfrom' => $reportFrom, 'rto' => $reportTo,
            'rsort' => $reportSort, 'rdir' => $reportDir, 'rpage' => $reportPage,
        ], $overrides));
    };
    ?>
    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head">
            <h2><?= icon('reports') ?> Sales Reports</h2>
            <?php if (user_can_do('hospitality', 'export') && $report['rows'] !== []): ?>
                <span><?php // The file holds the whole report, sorted as it is on screen. ?>
                    <?php foreach (['csv' => 'CSV', 'xlsx' => 'Excel', 'print' => 'PDF'] as $exportFormat => $exportLabel): ?>
                        <a class="mbw-view-all" style="margin-left:10px"<?= $exportFormat === 'print' ? ' target="_blank" rel="noopener"' : '' ?>
                           href="<?= e(url('admin/hospitality.php?' . $reportQuery(['export' => $exportFormat]))) ?>"><?= e($exportLabel) ?></a>
                    <?php endforeach; ?>
                </span>
            <?php endif; ?>
        </div>
        <form method="get" class="workspace-form-grid" style="margin-bottom:12px">
            <input type="hidden" name="view" value="sales-upload">
            <input type="hidden" name="rsort" value="<?= e($reportSort) ?>">
            <input type="hidden" name="rdir" value="<?= e($reportDir) ?>">
            <label>Report
                <select name="report" onchange="this.form.submit()">
                    <?php foreach ($reportOptions as $optionKey => $optionLabel): ?>
                        <option value="<?= e($optionKey) ?>" <?= $reportKey === $optionKey ? 'selected' : '' ?>><?= e($optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>From<input type="date" name="rfrom" value="<?= e($reportFrom) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>"></label>
            <label>To<input type="date" name="rto" value="<?= e($reportTo) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>"></label>
            <div style="align-self:end"><button type="submit"><?= icon('search') ?>Show</button></div>
        </form>
        <?php if (isset($report['note'])): ?>
            <div class="notice"><?= e((string) $report['note']) ?></div>
        <?php endif; ?>
        <div style="overflow-x:auto"><table>
            <thead><tr>
                <?php foreach ($report['columns'] as [$columnKey, $columnLabel, $columnNumeric]): ?>
                    <?php
                    // Every heading sorts, and clicking the active one reverses it.
                    $isSorted = $reportSort === $columnKey;
                    $nextDir = ($isSorted && $reportDir === 'asc') ? 'desc' : 'asc';
                    $arrow = $isSorted ? ($reportDir === 'asc' ? ' ▲' : ' ▼') : ' ⇅';
                    ?>
                    <th<?= $columnNumeric ? ' class="is-numeric"' : '' ?>>
                        <a href="<?= e(url('admin/hospitality.php?' . $reportQuery(['rsort' => $columnKey, 'rdir' => $nextDir, 'rpage' => 1]))) ?>"
                           style="color:inherit;text-decoration:none;white-space:nowrap"
                           title="Sort by <?= e($columnLabel) ?> (<?= $nextDir === 'asc' ? 'ascending' : 'descending' ?>)"><?= e($columnLabel) ?><span style="opacity:<?= $isSorted ? '1' : '.35' ?>"><?= $arrow ?></span></a>
                    </th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
                <?php if ($report['rows'] === []): ?>
                    <tr><td colspan="<?= max(1, count($report['columns'])) ?>">Nothing uploaded for <?= e($reportFrom) ?> to <?= e($reportTo) ?>.</td></tr>
                <?php endif; ?>
                <?php foreach ($report['rows'] as $reportRow): ?>
                    <tr>
                        <?php foreach ($report['columns'] as [$columnKey, $columnLabel, $columnNumeric]): ?>
                            <td<?= $columnNumeric ? ' class="is-numeric"' : '' ?>><?php
                                $cellValue = $reportRow[$columnKey] ?? '';
                                if (!$columnNumeric) {
                                    echo e((string) $cellValue);
                                } elseif ($columnKey === 'qty') {
                                    echo e(rtrim(rtrim(number_format((float) $cellValue, 3, '.', ','), '0'), '.'));
                                } elseif (in_array($columnKey, ['line_count', 'invoices'], true)) {
                                    echo (int) $cellValue;
                                } else {
                                    echo $fmt((float) $cellValue);
                                }
                            ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($report['rows'] !== []): ?>
            <tfoot><tr>
                <?php foreach ($report['columns'] as $columnIndex => [$columnKey, $columnLabel, $columnNumeric]): ?>
                    <?php if ($columnIndex === 0): ?>
                        <th>Total (<?= (int) $report['total_rows'] ?> row<?= $report['total_rows'] === 1 ? '' : 's' ?>)</th>
                    <?php elseif ($columnNumeric && isset($report['totals'][$columnKey])): ?>
                        <th class="is-numeric"><?= in_array($columnKey, ['line_count', 'invoices'], true)
                            ? (int) $report['totals'][$columnKey]
                            : ($columnKey === 'qty'
                                ? e(rtrim(rtrim(number_format((float) $report['totals'][$columnKey], 3, '.', ','), '0'), '.'))
                                : $fmt((float) $report['totals'][$columnKey])) ?></th>
                    <?php else: ?>
                        <th></th>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tr></tfoot>
            <?php endif; ?>
        </table></div>
        <?php if ($reportPages > 1): ?>
            <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12.5px">
                Page <?= $reportPage ?> of <?= $reportPages ?>
                <?php if ($reportPage > 1): ?>
                    · <a href="<?= e(url('admin/hospitality.php?' . $reportQuery(['rpage' => $reportPage - 1]))) ?>">Previous</a>
                <?php endif; ?>
                <?php if ($reportPage < $reportPages): ?>
                    · <a href="<?= e(url('admin/hospitality.php?' . $reportQuery(['rpage' => $reportPage + 1]))) ?>">Next</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </section>

    <?php
    $savedRunsStmt = db()->prepare("SELECT r.id, r.costing_date, r.status, r.source, r.upload_id, r.generated_at, r.recalculated_at,
            COUNT(l.id) AS line_count,
            SUM(CASE WHEN l.status = 'costed' THEN 1 ELSE 0 END) AS costed_count,
            COALESCE(SUM(CASE WHEN l.status = 'costed' THEN l.net_sales END), 0) AS net_sales,
            COALESCE(SUM(CASE WHEN l.status = 'costed' THEN l.total_cost END), 0) AS est_cost,
            COALESCE(SUM(CASE WHEN l.status = 'costed' THEN l.gross_profit END), 0) AS est_gp
        FROM hospitality_costing_runs r
        LEFT JOIN hospitality_costing_lines l ON l.run_id = r.id
        WHERE r.company_id = :cid
        GROUP BY r.id ORDER BY r.costing_date DESC LIMIT 30");
    $savedRunsStmt->execute(['cid' => $companyId]);
    $savedRuns = $savedRunsStmt->fetchAll();
    ?>
    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2><?= icon('reports') ?> Saved Costing Runs (<?= count($savedRuns) ?>)</h2>
            <a class="mbw-view-all" href="<?= e(url('admin/hospitality.php?view=reports')) ?>">Open Reports →</a>
        </div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Date</th><th>Source</th><th>Status</th><th class="is-numeric">Rows costed</th><th class="is-numeric">Net sales</th><th class="is-numeric">Est. cost</th><th class="is-numeric">Est. GP</th><th class="is-numeric">GP %</th><th>Generated</th><th></th></tr></thead>
            <tbody>
                <?php if ($savedRuns === []): ?><tr><td colspan="10">No costing runs yet — upload a sheet above and press Run costing.</td></tr><?php endif; ?>
                <?php foreach ($savedRuns as $savedRun): ?>
                    <?php $runNet = (float) $savedRun['net_sales']; ?>
                    <tr>
                        <td><strong><?= e((string) $savedRun['costing_date']) ?></strong></td>
                        <td><span class="mbw-pill <?= (string) $savedRun['source'] === 'upload' ? 'tone-teal' : 'tone-blue' ?>"><?= (string) $savedRun['source'] === 'upload' ? 'Sheet #' . (int) $savedRun['upload_id'] : 'Invoices' ?></span></td>
                        <td><span class="mbw-pill <?= (string) $savedRun['status'] === 'costed' ? 'tone-green' : ((string) $savedRun['status'] === 'partial' ? 'tone-amber' : 'tone-gray') ?>"><?= e(ucfirst((string) $savedRun['status'])) ?></span></td>
                        <td class="is-numeric"><?= (int) $savedRun['costed_count'] ?>/<?= (int) $savedRun['line_count'] ?></td>
                        <td class="is-numeric"><?= $fmt($runNet) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $savedRun['est_cost']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $savedRun['est_gp']) ?></td>
                        <td class="is-numeric"><?= $runNet != 0.0 ? $fmt((float) $savedRun['est_gp'] / $runNet * 100) . '%' : '—' ?></td>
                        <td><small><?= e((string) ($savedRun['recalculated_at'] ?? '') !== '' ? $savedRun['recalculated_at'] . ' (recalc)' : (string) $savedRun['generated_at']) ?></small></td>
                        <td style="white-space:nowrap">
                            <a class="button secondary" style="min-height:28px;padding:2px 8px" href="<?= e(url('admin/hospitality.php?view=reports&from=' . $savedRun['costing_date'] . '&to=' . $savedRun['costing_date'])) ?>"><?= icon('reports') ?>Report</a>
                            <?php if ($canRecalc): ?>
                                <form method="post" style="display:inline" data-confirm="Delete the costing run for <?= e((string) $savedRun['costing_date']) ?>? Its last totals stay in the recalculation history.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_costing_run">
                                    <input type="hidden" name="run_id" value="<?= (int) $savedRun['id'] ?>">
                                    <button type="submit" class="button secondary" style="min-height:28px;padding:2px 8px;color:var(--mbw-red, #a33)">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <?php else: ?>
    <?php
    $pvItems = $salesPreview['items'];
    $pvInvoices = $salesPreview['invoices'];
    $pvRecon = $salesPreview['reconciliation'];
    $pvTotals = $pvItems['totals'];
    $pvBlocked = !$pvRecon['ok'] || $pvItems['errors'] > 0 || $pvInvoices['errors'] > 0 || $salesPreview['config_errors'] !== [];
    ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Preview — check before posting</h2><a class="mbw-view-all" href="<?= e(url('admin/hospitality.php?view=sales-upload')) ?>">Upload different sheets</a></div>
        <section class="mbw-kpi-grid" aria-label="Sheet summary">
            <?php foreach ([
                ['Item lines', (string) $pvItems['valid'] . ($pvItems['errors'] > 0 ? ' (' . $pvItems['errors'] . ' in error)' : ''), 'tasks', $pvItems['errors'] > 0 ? 'tone-red' : 'tone-green'],
                ['Invoices', (string) $pvInvoices['valid'] . ($pvInvoices['errors'] > 0 ? ' (' . $pvInvoices['errors'] . ' in error)' : ''), 'wallet', $pvInvoices['errors'] > 0 ? 'tone-red' : 'tone-green'],
                ['Days (one voucher each)', (string) count($pvItems['days']), 'calendar', 'tone-blue'],
                ['Taxable sales', $sym . $fmt((float) $pvTotals['taxable']), 'wallet', 'tone-blue'],
                ['VAT', $sym . $fmt((float) $pvTotals['vat']), 'tag', 'tone-amber'],
                ['Billed (with VAT)', $sym . $fmt((float) $pvTotals['total']), 'reports', 'tone-purple'],
            ] as [$kpiLabel, $kpiValue, $kpiIcon, $kpiTone]): ?>
                <article class="mbw-kpi <?= e($kpiTone) ?>"><span class="mbw-kpi-icon"><?= icon($kpiIcon) ?></span>
                    <div><p class="mbw-kpi-label"><?= e($kpiLabel) ?></p><p class="mbw-kpi-value"><?= e($kpiValue) ?></p></div>
                </article>
            <?php endforeach; ?>
        </section>

        <!-- The reconciliation is the whole point of taking two sheets, so it
             is the first thing on the page rather than a footnote. -->
        <div class="notice <?= $pvRecon['ok'] ? '' : 'error' ?>" style="margin-top:12px">
            <strong><?= $pvRecon['ok'] ? 'The two sheets agree.' : 'The two sheets do not agree — nothing can be posted until they do.' ?></strong>
            <?php if ($pvRecon['ok']): ?>
                Both cover <?= e((string) $pvRecon['item_range'][0]) ?> to <?= e((string) $pvRecon['item_range'][1]) ?>, and every money column matches.
            <?php else: ?>
                <ul style="margin:8px 0 0;padding-left:18px">
                    <?php foreach ($pvRecon['problems'] as $problem): ?><li><?= e($problem) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div style="overflow-x:auto;margin-top:10px"><table>
            <thead><tr><th>Checked</th><th class="is-numeric">Item-wise sheet</th><th class="is-numeric">Invoice-wise sheet</th><th class="is-numeric">Difference</th></tr></thead>
            <tbody>
                <tr>
                    <td>Trading period</td>
                    <td class="is-numeric"><?= e((string) $pvRecon['item_range'][0]) ?> → <?= e((string) $pvRecon['item_range'][1]) ?></td>
                    <td class="is-numeric"><?= e((string) $pvRecon['invoice_range'][0]) ?> → <?= e((string) $pvRecon['invoice_range'][1]) ?></td>
                    <td class="is-numeric"><?= $pvRecon['item_range'] === $pvRecon['invoice_range']
                        ? '<span class="mbw-pill tone-green">Same</span>'
                        : '<span class="mbw-pill tone-red">Different</span>' ?></td>
                </tr>
                <?php foreach ($pvRecon['differences'] as $difference): ?>
                    <tr>
                        <td><?= e((string) $difference['label']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $difference['items']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $difference['invoices']) ?></td>
                        <td class="is-numeric"><?= abs((float) $difference['gap']) < 0.011
                            ? '<span class="mbw-pill tone-green">Agrees</span>'
                            : '<span class="mbw-pill tone-red">' . $fmt((float) $difference['gap']) . '</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>

        <?php if ($salesPreview['config_errors'] !== []): ?>
            <div class="notice error" style="margin-top:10px"><strong>Posting setup incomplete:</strong> <?= e(implode(' ', $salesPreview['config_errors'])) ?> Save the posting ledgers above, then re-open this preview.</div>
        <?php endif; ?>
        <?php if ($salesPreview['duplicate_dates'] !== []): ?>
            <div class="notice error" style="margin-top:10px"><strong>Already posted dates:</strong> <?= e(implode(', ', $salesPreview['duplicate_dates'])) ?> — an earlier upload already posted sales for these days. Post anyway only if these sheets hold ADDITIONAL sales for them.</div>
        <?php endif; ?>
    </section>

    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>The entry each day will post</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Date</th><th>Debit — settled to</th><th>Credit — sales by category</th><th class="is-numeric">VAT</th><th class="is-numeric">Voucher total</th></tr></thead>
            <tbody>
                <?php if ($pvItems['days'] === []): ?><tr><td colspan="5">No valid rows — fix the errors below.</td></tr><?php endif; ?>
                <?php foreach ($pvItems['days'] as $dayDate => $day): ?>
                    <?php $dayInvoice = $pvInvoices['days'][$dayDate] ?? null; ?>
                    <tr>
                        <td><strong><?= e((string) $dayDate) ?></strong></td>
                        <td>
                            <?php if ($dayInvoice === null): ?>
                                <span class="mbw-pill tone-red">Nothing on the invoice sheet for this day</span>
                            <?php else: ?>
                                <?php foreach ($dayInvoice['ledgers'] as $legLedger): ?>
                                    <div><?= e((string) $legLedger['name']) ?> <span style="color:var(--mbw-muted)"><?= $fmt((float) $legLedger['total']) ?></span></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php foreach ($day['ledgers'] as $legLedger): ?>
                                <div><?= e((string) $legLedger['label']) ?> <span style="color:var(--mbw-muted)"><?= $fmt((float) $legLedger['taxable']) ?></span></div>
                            <?php endforeach; ?>
                        </td>
                        <td class="is-numeric"><?= $fmt((float) $day['vat']) ?></td>
                        <td class="is-numeric"><strong><?= $fmt((float) $day['total']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <p style="margin:8px 0 0;color:var(--mbw-muted);font-size:12px">
            Discount is already taken off the credit — the sheets carry Taxable Sales net of it, and that is what the VAT was worked out on.
        </p>
    </section>

    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Item-wise sheet (<?= count($pvItems['rows']) ?> row<?= count($pvItems['rows']) === 1 ? '' : 's' ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>#</th><th>Date</th><th>Category</th><th>Item</th><th class="is-numeric">Qty</th><th class="is-numeric">Amount</th><th class="is-numeric">Discount</th><th class="is-numeric">Taxable</th><th class="is-numeric">VAT</th><th class="is-numeric">With VAT</th><th>Sales ledger</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($pvItems['rows'], 0, 300) as $previewRow): ?>
                    <tr<?= $previewRow['errors'] !== [] ? ' style="background:rgba(220,53,69,.08)"' : '' ?>>
                        <td><?= (int) $previewRow['n'] ?></td>
                        <td><?= e((string) ($previewRow['date'] ?? $previewRow['date_raw'])) ?></td>
                        <td><?= e((string) $previewRow['category']) ?></td>
                        <td><?= e((string) $previewRow['item']) ?></td>
                        <td class="is-numeric"><?= e(rtrim(rtrim(number_format((float) $previewRow['qty'], 3, '.', ','), '0'), '.')) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $previewRow['amount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $previewRow['discount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $previewRow['taxable']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $previewRow['vat']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $previewRow['total']) ?></td>
                        <td><?php if ($previewRow['errors'] !== []): ?>
                            <span class="mbw-pill tone-red"><?= e(implode(' ', $previewRow['errors'])) ?></span>
                        <?php else: ?><?= e((string) $previewRow['ledger_label']) ?><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if (count($pvItems['rows']) > 300): ?>
            <p style="margin:8px 0 0;color:var(--mbw-muted);font-size:12px">Showing the first 300 of <?= count($pvItems['rows']) ?> rows. All of them will post.</p>
        <?php endif; ?>
    </section>

    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Invoice-wise sheet (<?= count($pvInvoices['rows']) ?> row<?= count($pvInvoices['rows']) === 1 ? '' : 's' ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>#</th><th>Date</th><th>Invoice No</th><th>Payment type</th><th>Ledger code</th><th>Resolved ledger</th><th class="is-numeric">Amount</th><th class="is-numeric">Discount</th><th class="is-numeric">Taxable</th><th class="is-numeric">VAT</th><th class="is-numeric">With VAT</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($pvInvoices['rows'], 0, 300) as $previewRow): ?>
                    <tr<?= $previewRow['errors'] !== [] ? ' style="background:rgba(220,53,69,.08)"' : '' ?>>
                        <td><?= (int) $previewRow['n'] ?></td>
                        <td><?= e((string) ($previewRow['date'] ?? $previewRow['date_raw'])) ?></td>
                        <td><?= e((string) $previewRow['invoice_no']) ?></td>
                        <td><?= e((string) $previewRow['payment_type']) ?></td>
                        <td><?= e((string) $previewRow['ledger_code']) ?></td>
                        <td><?php if ($previewRow['errors'] !== []): ?>
                            <span class="mbw-pill tone-red"><?= e(implode(' ', $previewRow['errors'])) ?></span>
                        <?php else: ?><span class="mbw-pill tone-green"><?= e((string) $previewRow['ledger_name']) ?></span><?php endif; ?></td>
                        <td class="is-numeric"><?= $fmt((float) $previewRow['amount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $previewRow['discount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $previewRow['taxable']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $previewRow['vat']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $previewRow['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if (count($pvInvoices['rows']) > 300): ?>
            <p style="margin:8px 0 0;color:var(--mbw-muted);font-size:12px">Showing the first 300 of <?= count($pvInvoices['rows']) ?> rows. All of them will post.</p>
        <?php endif; ?>
    </section>

    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Post</h2></div>
        <?php if (!$canPost): ?>
            <div class="notice error">You do not have permission to post sales.</div>
        <?php elseif ($pvBlocked): ?>
            <div class="notice error">
                Nothing can be posted while the sheets disagree or rows carry errors —
                posting part of a day would leave the two sheets out of step permanently.
                <br><br>Fix them <strong>here</strong> rather than going back to Excel for one cell:
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="sheet_editor_load">
                    <input type="hidden" name="token" value="<?= e($salesToken) ?>">
                    <button type="submit" class="secondary" style="min-height:30px;padding:3px 12px"><?= icon('edit') ?> Open in Sheet Editor</button>
                </form>
            </div>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="sales_upload_commit">
                <input type="hidden" name="back_view" value="sales-upload">
                <input type="hidden" name="token" value="<?= e($salesToken) ?>">
                <?php if ($salesPreview['duplicate_dates'] !== []): ?>
                    <label class="checkbox-line"><input type="checkbox" name="allow_duplicate_dates" value="1"> Post anyway — these sheets hold additional sales for days already posted</label>
                <?php endif; ?>
                <div style="margin-top:10px">
                    <button type="submit"><?= icon('badge-check') ?>Post <?= count($pvItems['days']) ?> daily voucher<?= count($pvItems['days']) === 1 ? '' : 's' ?></button>
                </div>
            </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>


<?php elseif ($view === 'sheet-editor'): ?>
    <?php
    // What was uploaded, laid out so it can be corrected here.
    //
    // A sheet that will not go in used to mean opening Excel, finding the row,
    // fixing it, saving, and uploading the whole thing again -- for one cell.
    // The rows are held as typed and checked by the same parser that rejected
    // them, so a correction is tested by the code that objected to it.
    //
    // The uploaded FILE is never rewritten. A spreadsheet on disk that no
    // longer says what was uploaded is a worse problem than the one being
    // solved; what posts is what is on this screen.
    $editorState = $_SESSION['hospitality_sheet_editor'] ?? null;
    $editorItemColumns = hospitality_workbook_editor_columns('items');
    $editorInvoiceColumns = hospitality_workbook_editor_columns('invoices');
    $editorChecked = null;
    if ($editorState !== null) {
        $editorChecked = hospitality_workbook_parse_rows(
            hospitality_workbook_editor_to_cells((array) $editorState['items'], 'items'),
            hospitality_workbook_editor_to_cells((array) $editorState['invoices'], 'invoices'),
            $companyId, $fiscalYearId, $settings
        );
    }
    /** Errors for the nth filled row of a sheet, keyed so a row can show its own. */
    $editorErrorsFor = static function (?array $parsed, string $which): array {
        if ($parsed === null || isset($parsed['error'])) {
            return [];
        }
        $byIndex = [];
        foreach ($parsed[$which]['rows'] ?? [] as $offset => $parsedRow) {
            $byIndex[$offset] = $parsedRow['errors'];
        }
        return $byIndex;
    };
    $editorItemErrors = $editorErrorsFor($editorChecked, 'items');
    $editorInvoiceErrors = $editorErrorsFor($editorChecked, 'invoices');
    // The editor drops blank rows before parsing, so the nth NON-BLANK row on
    // screen is the nth parsed row. Anything else would put a message on the
    // wrong line.
    $editorFilledIndex = static function (array $rows, int $upTo, array $columns): int {
        $filled = 0;
        foreach ($rows as $index => $row) {
            if ($index >= $upTo) {
                break;
            }
            foreach (array_keys($columns) as $field) {
                if (trim((string) ($row[$field] ?? '')) !== '') {
                    $filled++;
                    break;
                }
            }
        }
        return $filled;
    };
    $editorHasRows = $editorState !== null;
    $editorClean = $editorChecked !== null && !isset($editorChecked['error'])
        && $editorChecked['items']['errors'] === 0 && $editorChecked['invoices']['errors'] === 0
        && $editorChecked['reconciliation']['ok'];
    ?>

    <?php if (!$editorHasRows): ?>
        <?php
        $heldUploads = [];
        foreach (glob($salesUploadDir . '/*.name') ?: [] as $heldName) {
            $heldToken = basename($heldName, '.name');
            if (hospitality_sales_stored_file($salesUploadDir, $heldToken) === null) {
                continue;
            }
            $heldUploads[] = ['token' => $heldToken,
                'name' => trim((string) @file_get_contents($heldName)) ?: 'sales sheet',
                'when' => (int) @filemtime($heldName)];
        }
        usort($heldUploads, static fn (array $a, array $b): int => $b['when'] <=> $a['when']);
        ?>
        <section class="mbw-card">
            <div class="mbw-card-head"><h2>Sheet Editor</h2></div>
            <p style="margin:0 0 12px;color:var(--mbw-muted);font-size:13px">
                Open an uploaded sheet here to correct it. A sheet that will not go in — a date typed wrong, a ledger code that
                does not exist, a row whose VAT does not add up — can be fixed on this screen and posted, instead of going back to
                Excel and uploading the whole file again for one cell.
                <br>The uploaded file itself is left alone; what posts is what you see here.
            </p>
            <?php if ($heldUploads === []): ?>
                <div class="notice">
                    Nothing is waiting to be edited. Upload a sheet on
                    <a href="<?= e(url('admin/hospitality.php?view=sales-upload')) ?>">Sales Upload</a> first — if it has problems,
                    come back here and fix them.
                </div>
            <?php else: ?>
                <div style="overflow-x:auto"><table>
                    <thead><tr><th>File</th><th>Uploaded</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($heldUploads as $held): ?>
                            <tr>
                                <td><?= e($held['name']) ?></td>
                                <td><small><?= e(date('Y-m-d H:i', $held['when'])) ?></small></td>
                                <td>
                                    <form method="post" style="margin:0">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="sheet_editor_load">
                                        <input type="hidden" name="token" value="<?= e($held['token']) ?>">
                                        <button type="submit" class="secondary" style="min-height:30px;padding:3px 12px"><?= icon('edit') ?> Open in editor</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12px">Uploads are held for six hours, then cleared.</p>
            <?php endif; ?>
        </section>

    <?php else: ?>
        <?php if ($editorChecked !== null && isset($editorChecked['error'])): ?>
            <div class="notice error" style="margin-bottom:14px"><?= e((string) $editorChecked['error']) ?></div>
        <?php elseif ($editorClean): ?>
            <div class="notice" style="margin-bottom:14px">
                <strong>Everything checks out.</strong> Both sheets agree and no row has an error — this can be posted.
            </div>
        <?php elseif ($editorChecked !== null): ?>
            <div class="notice error" style="margin-bottom:14px">
                <strong>Still to fix:</strong>
                <?= (int) $editorChecked['items']['errors'] ?> item row(s) and <?= (int) $editorChecked['invoices']['errors'] ?> invoice row(s) have errors.
                <?php if (!$editorChecked['reconciliation']['ok']): ?>
                    <ul style="margin:8px 0 0;padding-left:18px">
                        <?php foreach ($editorChecked['reconciliation']['problems'] as $editorProblem): ?><li><?= e($editorProblem) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="file_name" value="<?= e((string) ($editorState['file_name'] ?? '')) ?>">

            <?php foreach ([
                ['items', 'Item-wise sheet — what was sold', $editorItemColumns, (array) $editorState['items'], $editorItemErrors, 'item_rows'],
                ['invoices', 'Invoice-wise sheet — how it was settled', $editorInvoiceColumns, (array) $editorState['invoices'], $editorInvoiceErrors, 'invoice_rows'],
            ] as [$editorSheet, $editorTitle, $editorColumns, $editorRows, $editorErrors, $editorField]): ?>
                <section class="mbw-card" data-collapsible style="margin-bottom:14px">
                    <div class="mbw-card-head"><h2><?= e($editorTitle) ?> (<?= count($editorRows) ?>)</h2></div>
                    <div style="overflow-x:auto"><table class="mbw-grid-table">
                        <thead><tr>
                            <th style="width:44px">#</th>
                            <?php foreach ($editorColumns as $editorKey => $editorLabel): ?>
                                <th><?= e($editorLabel) ?></th>
                            <?php endforeach; ?>
                            <th>Problem</th>
                        </tr></thead>
                        <tbody>
                            <?php
                            // Two spare rows at the foot, so a line the sheet
                            // missed can be added without another upload.
                            $editorDrawn = array_values($editorRows);
                            $editorDrawn[] = [];
                            $editorDrawn[] = [];
                            ?>
                            <?php foreach ($editorDrawn as $editorIndex => $editorRow): ?>
                                <?php
                                $filledBefore = $editorFilledIndex($editorDrawn, $editorIndex, $editorColumns);
                                $rowIsBlank = true;
                                foreach (array_keys($editorColumns) as $editorCheckKey) {
                                    if (trim((string) ($editorRow[$editorCheckKey] ?? '')) !== '') {
                                        $rowIsBlank = false;
                                        break;
                                    }
                                }
                                $rowErrors = $rowIsBlank ? [] : ($editorErrors[$filledBefore] ?? []);
                                ?>
                                <tr<?= $rowErrors !== [] ? ' style="background:rgba(220,53,69,.08)"' : '' ?>>
                                    <td><?= $editorIndex + 1 ?></td>
                                    <?php foreach ($editorColumns as $editorKey => $editorLabel): ?>
                                        <td><input type="text" name="<?= e($editorField) ?>[<?= $editorIndex ?>][<?= e($editorKey) ?>]"
                                                   value="<?= e((string) ($editorRow[$editorKey] ?? '')) ?>"
                                                   style="width:<?= in_array($editorKey, ['item', 'category', 'payment_type'], true) ? '150' : '110' ?>px"></td>
                                    <?php endforeach; ?>
                                    <td><?= $rowErrors !== []
                                        ? '<span class="mbw-pill tone-red">' . e(implode(' ', $rowErrors)) . '</span>'
                                        : ($rowIsBlank ? '<small style="color:var(--mbw-muted)">spare</small>' : '<span class="mbw-pill tone-green">OK</span>') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </section>
            <?php endforeach; ?>

            <section class="mbw-card">
                <div class="mbw-card-head"><h2>Check and post</h2></div>
                <?php if ($editorChecked !== null && !isset($editorChecked['error']) && $editorChecked['duplicate_dates'] !== []): ?>
                    <div class="notice error" style="margin-bottom:10px">
                        <strong>Already posted dates:</strong> <?= e(implode(', ', $editorChecked['duplicate_dates'])) ?>.
                        <label class="checkbox-line" style="margin-top:6px"><input type="checkbox" name="allow_duplicate_dates" value="1"> Post anyway — these are additional sales for those days</label>
                    </div>
                <?php endif; ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button type="submit" name="action" value="sheet_editor_check" class="secondary"><?= icon('search') ?> Check again</button>
                    <?php if ($editorClean && $canPost): ?>
                        <button type="submit" name="action" value="sheet_editor_post"><?= icon('check') ?> Post <?= count($editorChecked['items']['days']) ?> daily voucher(s)</button>
                    <?php else: ?>
                        <button type="button" disabled title="Fix the rows marked above first"><?= icon('check') ?> Post</button>
                    <?php endif; ?>
                    <button type="submit" name="action" value="sheet_editor_clear" class="secondary" formnovalidate>Discard</button>
                </div>
                <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12px">
                    <strong>Check again</strong> re-reads every row exactly as an upload would. Dates may be typed in AD or BS.
                    Posting uses what is on this screen, not the file that was uploaded.
                </p>
            </section>
        </form>
    <?php endif; ?>

<?php elseif ($view === 'settings'): ?>
    <?php
    // Where a sales category posts. This used to sit on the upload screen, but
    // it is set up once and then left alone, and the upload screen is a place
    // people visit daily — it belongs here.
    //
    // It carries the CREDIT side only. The debit comes from the Party Ledger
    // Code on the invoice sheet, and discount is netted into the sales credit
    // (the sheet's Taxable Sales is already net of it), so there is no
    // receivable or discount ledger to choose. Existing values for those are
    // left untouched in the database.
    $ledgerOptions = hospitality_posting_ledger_options($companyId);
    $configErrors = hospitality_posting_config_errors($companyId, $settings, false);
    $allMapsStmt = db()->prepare('SELECT m.*, l.name AS ledger_name, l.code AS ledger_code, l.status AS ledger_status
        FROM hospitality_sales_ledger_maps m
        LEFT JOIN ledgers l ON l.id = m.sales_ledger_id AND l.company_id = m.company_id
        WHERE m.company_id = :cid ORDER BY m.map_type ASC, m.active DESC, m.display_value ASC');
    $allMapsStmt->execute(['cid' => $companyId]);
    $ledgerMapRows = $allMapsStmt->fetchAll();
    $ledgerSelect = static function (string $name, ?int $selected, bool $required = false) use ($ledgerOptions): string {
        $html = '<select name="' . e($name) . '"' . ($required ? ' required' : '') . '>';
        $html .= '<option value="">— not set —</option>';
        foreach ($ledgerOptions as $ledgerOption) {
            $html .= '<option value="' . (int) $ledgerOption['id'] . '"' . ((int) $ledgerOption['id'] === (int) $selected ? ' selected' : '') . '>'
                . e($ledgerOption['name'] . ' (' . $ledgerOption['code'] . ') — ' . $ledgerOption['type']) . '</option>';
        }
        return $html . '</select>';
    };
    ?>
    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Sales Category Ledgers &amp; VAT (<?= count($ledgerMapRows) ?> categor<?= count($ledgerMapRows) === 1 ? 'y' : 'ies' ?>)</h2></div>
        <p style="margin:0 0 10px;color:var(--mbw-muted);font-size:12.5px">
            One row per category, exactly as written in the sheet (Food, Beverage, Bar…). Each category's taxable sales are
            <strong>credited</strong> to its own ledger, and VAT to the one below. The <strong>Default</strong> row covers any category without a row of its own.
            <br>The debit side is not set here — it comes from the <strong>Party Ledger Code</strong> on the invoice sheet, so a day settled across cash,
            wallet and credit posts a debit to each. Discount is netted into the sales credit, which is what the sheet's Taxable Sales column already is.
        </p>
        <?php if (!$canEdit): ?><div class="notice">You have view-only access to this setup.</div><?php endif; ?>
        <form method="post" <?= $canEdit ? '' : 'style="pointer-events:none;opacity:.7"' ?>>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_ledger_mapping">
            <input type="hidden" name="back_view" value="settings">
            <div style="overflow-x:auto"><table>
                <thead><tr><th>Category (as written in the sheet)</th><th>Sales ledger (credit)</th><th>Notes</th><th>Active</th></tr></thead>
                <tbody>
                    <tr style="background:var(--mbw-soft,rgba(0,0,0,.03))">
                        <td><strong>Default / all other categories</strong><br><small style="color:var(--mbw-muted)">Fallback when a category has no row below.</small></td>
                        <td><?= $ledgerSelect('post_sales_ledger_id', (int) ($settings['post_sales_ledger_id'] ?? 0)) ?></td>
                        <td><small style="color:var(--mbw-muted)">—</small></td>
                        <td><span class="mbw-pill tone-blue">Always</span></td>
                    </tr>
                    <?php foreach ($ledgerMapRows as $index => $mapRow): ?>
                        <tr>
                            <td>
                                <input type="hidden" name="map_id[<?= $index ?>]" value="<?= (int) $mapRow['id'] ?>">
                                <input type="text" name="map_category[<?= $index ?>]" maxlength="160" value="<?= e($mapRow['display_value']) ?>" style="min-width:160px">
                                <?= (string) $mapRow['map_type'] === 'item' ? '<br><span class="mbw-pill tone-purple">Item override</span>' : '' ?>
                            </td>
                            <td><?= $ledgerSelect('map_sales_ledger[' . $index . ']', (int) $mapRow['sales_ledger_id']) ?><?= $mapRow['ledger_name'] === null ? '<br><span class="mbw-pill tone-red">Ledger missing</span>' : '' ?></td>
                            <td><input type="text" name="map_notes[<?= $index ?>]" maxlength="255" value="<?= e($mapRow['notes'] ?? '') ?>" style="min-width:140px"></td>
                            <td style="text-align:center"><input type="checkbox" name="map_active[<?= $index ?>]" <?= (int) $mapRow['active'] === 1 ? 'checked' : '' ?>></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td>
                            <input type="hidden" name="map_id[new]" value="0">
                            <input type="text" name="map_category[new]" maxlength="160" placeholder="Add category… e.g. Beverage" style="min-width:160px">
                        </td>
                        <td><?= $ledgerSelect('map_sales_ledger[new]', 0) ?></td>
                        <td><input type="text" name="map_notes[new]" maxlength="255" style="min-width:140px"></td>
                        <td style="text-align:center"><input type="checkbox" name="map_active[new]" checked></td>
                    </tr>
                </tbody>
            </table></div>
            <div class="workspace-form-grid" style="margin-top:12px">
                <label>VAT payable ledger (credit — common for all categories)<?= $ledgerSelect('post_vat_ledger_id', (int) ($settings['post_vat_ledger_id'] ?? 0)) ?></label>
                <label>VAT rate %<input type="number" step="0.01" min="0" max="99.99" name="post_vat_rate" value="<?= e(number_format((float) ($settings['post_vat_rate'] ?? 13), 2, '.', '')) ?>">
                    <small style="color:var(--mbw-muted)">Only used when a sheet has no VAT column of its own.</small></label>
                <?php if ($canEdit): ?><div style="align-self:end"><button type="submit"><?= icon('settings') ?>Save Category Ledgers</button></div><?php endif; ?>
            </div>
        </form>
        <?php if ($configErrors !== []): ?>
            <div class="notice error" style="margin-top:10px"><strong>Before a sheet can post:</strong> <?= e(implode(' ', $configErrors)) ?></div>
        <?php endif; ?>
        <p style="margin:8px 0 0;color:var(--mbw-muted);font-size:12px">
            Matching ignores letter case and extra spaces; an item override (from earlier setups) wins over its category row.
            Untick Active to stop a row matching without losing it.
        </p>
    </section>

    <?php
        $invMapRows = inventory_mapping_rows($companyId);
        $invMapGaps = inventory_mapping_gaps($companyId);
        $invLedgerList = [];
        if (table_exists('ledgers')) {
            $invLedgerStmt = db()->prepare('SELECT id, code, name FROM ledgers WHERE company_id = :cid ORDER BY code ASC, name ASC');
            $invLedgerStmt->execute(['cid' => $companyId]);
            $invLedgerList = $invLedgerStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    ?>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Inventory Posting Ledgers</h2></div>
        <p class="frm-optional" style="margin:0 0 12px">
            The same rows the Inventory module reads — set here or there, it is one setting.
        </p>
        <?php if ($canEdit && $invMapGaps !== []): ?>
        <div class="mbw-note tone-amber" style="margin:0 0 12px">
            <p style="margin:0 0 8px"><strong><?= count($invMapGaps) ?> unmapped:</strong>
                <?= e(implode(', ', array_slice($invMapGaps, 0, 6))) ?><?= count($invMapGaps) > 6 ? ' +' . (count($invMapGaps) - 6) . ' more' : '' ?>.</p>
            <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="autocreate_inventory_mapping">
                <button type="submit" class="button">Open and map the standard ledgers</button>
            </form>
        </div>
        <?php endif; ?>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Purpose</th><th>Ledger</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
                <?php foreach (inventory_mapping_purposes() as $invPurpose => $invMeta): ?>
                    <?php $invMapped = $invMapRows[$invPurpose] ?? null; ?>
                    <tr>
                        <td><?= e((string) $invMeta['label']) ?></td>
                        <td>
                            <?php if ($invMapped): ?>
                                <span class="mbw-pill tone-green"><?= e((($invMapped['ledger_code'] ?? '') ? $invMapped['ledger_code'] . ' — ' : '') . $invMapped['ledger_name']) ?></span>
                            <?php else: ?>
                                <span class="mbw-pill tone-gray">Not set</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($canEdit): ?>
                        <td>
                            <form method="post" style="display:flex;gap:6px;align-items:center">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="save_inventory_mapping">
                                <input type="hidden" name="purpose" value="<?= e((string) $invPurpose) ?>">
                                <select name="ledger_id">
                                    <option value="0">— not set —</option>
                                    <?php foreach ($invLedgerList as $invLedger): ?>
                                        <option value="<?= (int) $invLedger['id'] ?>" <?= (int) ($invMapped['ledger_id'] ?? 0) === (int) $invLedger['id'] ? 'selected' : '' ?>><?= e((($invLedger['code'] ?? '') ? $invLedger['code'] . ' — ' : '') . $invLedger['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="button secondary" style="min-height:32px;padding:4px 10px">Save</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head"><h2>Hospitality Settings (this client only)</h2></div>
        <?php if (!$canEdit): ?><div class="notice">You have view-only access to these settings.</div><?php endif; ?>
        <form method="post" class="workspace-form-grid" <?= $canEdit ? '' : 'style="pointer-events:none;opacity:.7"' ?>>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_settings">
            <label>Ingredient cost source
                <select name="cost_source">
                    <option value="manual" <?= (string) $settings['cost_source'] === 'manual' ? 'selected' : '' ?>>Manual reference cost</option>
                    <option value="latest_purchase" <?= (string) $settings['cost_source'] === 'latest_purchase' ? 'selected' : '' ?>>Latest purchase (maintained manually)</option>
                </select>
            </label>
            <label>Costing basis
                <select name="costing_basis">
                    <option value="sale_date" <?= (string) $settings['costing_basis'] === 'sale_date' ? 'selected' : '' ?>>Costs in force on the sale date</option>
                    <option value="calculation_date" <?= (string) $settings['costing_basis'] === 'calculation_date' ? 'selected' : '' ?>>Costs at calculation date</option>
                </select>
            </label>
            <label class="checkbox-line"><input type="checkbox" name="include_invoice_discount" <?= (int) $settings['include_invoice_discount'] === 1 ? 'checked' : '' ?>> Allocate invoice-level discount to lines</label>
            <label class="checkbox-line"><input type="checkbox" name="net_of_vat" <?= (int) $settings['net_of_vat'] === 1 ? 'checked' : '' ?>> Analyse sales net of VAT/tax (recommended)</label>
            <label class="checkbox-line"><input type="checkbox" name="include_packaging" <?= (int) $settings['include_packaging'] === 1 ? 'checked' : '' ?>> Add packaging % to portion cost</label>
            <label>Packaging %<input type="number" step="0.01" min="0" max="99.99" name="packaging_pct" value="<?= e(number_format((float) $settings['packaging_pct'], 2, '.', '')) ?>"></label>
            <label class="checkbox-line"><input type="checkbox" name="include_kitchen_wastage" <?= (int) $settings['include_kitchen_wastage'] === 1 ? 'checked' : '' ?>> Add general kitchen wastage %</label>
            <label>Kitchen wastage %<input type="number" step="0.01" min="0" max="99.99" name="kitchen_wastage_pct" value="<?= e(number_format((float) $settings['kitchen_wastage_pct'], 2, '.', '')) ?>"></label>
            <label class="checkbox-line"><input type="checkbox" name="include_other_variable" <?= (int) $settings['include_other_variable'] === 1 ? 'checked' : '' ?>> Add other variable cost %</label>
            <label>Other variable %<input type="number" step="0.01" min="0" max="99.99" name="other_variable_pct" value="<?= e(number_format((float) $settings['other_variable_pct'], 2, '.', '')) ?>"></label>
            <label>Cost precision (decimals)<input type="number" min="0" max="4" name="cost_precision" value="<?= e((int) $settings['cost_precision']) ?>"></label>
            <label>Display precision (decimals)<input type="number" min="0" max="4" name="display_precision" value="<?= e((int) $settings['display_precision']) ?>"></label>
            <label>Default dashboard range
                <select name="dashboard_range">
                    <?php foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'fiscal_year' => 'Fiscal year'] as $rangeValue => $rangeLabel): ?>
                        <option value="<?= e($rangeValue) ?>" <?= (string) $settings['dashboard_range'] === $rangeValue ? 'selected' : '' ?>><?= e($rangeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($canEdit): ?><div class="workspace-span-2"><button type="submit"><?= icon('settings') ?>Save Settings</button></div><?php endif; ?>
        </form>
        <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12px">
            Branch/outlet analysis is not available because this application has no branch dimension on sales.
            Labour, rent, depreciation, electricity, and administrative overheads are NOT recipe costs and are never added here.
            All settings are tenant-specific and audited. Recalculation additionally requires the hospitality adjust permission.
        </p>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
