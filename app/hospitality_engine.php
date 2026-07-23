<?php
declare(strict_types=1);

/**
 * Hospitality Accounting engine — recipe costing and ESTIMATED gross profit.
 *
 * REFERENCE-ONLY by architecture: this file contains every hospitality
 * calculation and deliberately never calls (or includes anything that calls)
 * create_voucher_with_entries(), ledger/journal posting, COGS posting,
 * inventory issue/consumption, stock adjustment, or tax-report code. It READS
 * posted sales (task_invoices / invoice_line_items) and writes only to the
 * hospitality_* reference tables. Every figure it produces is an estimate for
 * management and is labelled as such in the UI and exports.
 *
 * Tenant model: all data is scoped to the client's BOOKS company id, and the
 * module unlocks only when the books company belongs to a client whose
 * client_profiles.hospitality_accounting_enabled = 1 (Super Admin controlled).
 * The firm's own workspaces are never client books, so the module can never
 * appear there.
 */

// ---------------------------------------------------------------------------
// Activation and gating
// ---------------------------------------------------------------------------

/** The client profile served by this books company, or null. */
function hospitality_client_profile_for_company(int $companyId): ?array
{
    if ($companyId <= 0 || !table_exists('client_profiles')) {
        return null;
    }
    $stmt = db()->prepare('SELECT cp.* FROM client_profiles cp
        INNER JOIN companies c ON c.id = cp.books_company_id
        WHERE cp.books_company_id = :cid AND c.is_client_company = 1
        LIMIT 1');
    $stmt->execute(['cid' => $companyId]);
    return $stmt->fetch() ?: null;
}

/**
 * Is Hospitality Accounting active for this books company? False for every
 * non-client company (including the firm's own workspaces) and for clients
 * whose flag is off — routes, menus and queries all key off this.
 */
function hospitality_enabled_for_company(int $companyId): bool
{
    if (!column_exists('client_profiles', 'hospitality_accounting_enabled')) {
        return false;
    }
    $profile = hospitality_client_profile_for_company($companyId);
    return $profile !== null
        && (int) ($profile['is_active'] ?? 0) === 1
        && (int) ($profile['hospitality_accounting_enabled'] ?? 0) === 1;
}

/**
 * Page gate: authenticated books access + company context + the client flag +
 * the hospitality.view permission. Direct URL access with the feature off is
 * denied server-side (never rely on the hidden menu alone).
 */
function require_hospitality(): void
{
    require_staff_admin_or_client_books();
    require_company_context();
    if (!hospitality_enabled_for_company(current_company_id())) {
        deny_access('Hospitality Accounting is not enabled for this company.', current_company_id());
    }
    require_permission('hospitality', 'view');
}

/** Tenant-specific settings row (auto-created with safe defaults). */
function hospitality_settings(int $companyId): array
{
    $stmt = db()->prepare('SELECT * FROM hospitality_settings WHERE company_id = :cid LIMIT 1');
    $stmt->execute(['cid' => $companyId]);
    $row = $stmt->fetch();
    if (!$row) {
        db()->prepare('INSERT INTO hospitality_settings (company_id) VALUES (:cid)')->execute(['cid' => $companyId]);
        $stmt->execute(['cid' => $companyId]);
        $row = $stmt->fetch();
    }
    return (array) $row;
}

// ---------------------------------------------------------------------------
// Ingredient costing
// ---------------------------------------------------------------------------

/**
 * Reference cost per RECIPE unit for an ingredient row:
 *   base   = purchase cost / conversion factor
 *   +wastage: cost / (1 - wastage%)   (usable share is smaller)
 *   +yield:   cost / yield%           (only the yield share is usable)
 * Returns ['ok', 'unit_cost', 'error'] — never divides by zero.
 */
function hospitality_ingredient_unit_cost(array $ingredient, ?float $purchaseCost = null): array
{
    $conversion = (float) ($ingredient['conversion_factor'] ?? 0);
    $wastage = (float) ($ingredient['wastage_pct'] ?? 0);
    $yield = (float) ($ingredient['yield_pct'] ?? 100);
    $cost = $purchaseCost !== null ? $purchaseCost : (float) ($ingredient['purchase_cost'] ?? 0);
    if ($conversion <= 0) {
        return ['ok' => false, 'unit_cost' => 0.0, 'error' => 'Unit-conversion factor must be greater than zero.'];
    }
    if ($wastage < 0 || $wastage >= 100) {
        return ['ok' => false, 'unit_cost' => 0.0, 'error' => 'Wastage must be between 0% and below 100%.'];
    }
    if ($yield <= 0 || $yield > 100) {
        return ['ok' => false, 'unit_cost' => 0.0, 'error' => 'Yield must be above 0% and at most 100%.'];
    }
    if ($cost < 0) {
        return ['ok' => false, 'unit_cost' => 0.0, 'error' => 'Cost cannot be negative.'];
    }
    $unit = $cost / $conversion;
    if ($wastage > 0) {
        $unit = $unit / (1 - $wastage / 100);
    }
    if ($yield < 100) {
        $unit = $unit / ($yield / 100);
    }
    return ['ok' => true, 'unit_cost' => round($unit, 6), 'error' => null];
}

/**
 * The purchase-unit reference cost in force on a date: the latest history row
 * effective on/before the date; if the earliest recorded cost is dated AFTER
 * the requested date, that earliest known cost is used (never the possibly
 * newer master value — sale-date snapshots must not drift when later cost
 * changes are recorded). Master cost is the last resort with no history.
 */
function hospitality_ingredient_cost_on(array $ingredient, string $date): float
{
    // Rows may be plain ingredient rows ('id') or recipe-line joins where the
    // ingredient id is aliased ('ingredient_id') and 'id' is the LINE id.
    $ingredientId = (int) ($ingredient['ingredient_id'] ?? $ingredient['id'] ?? 0);
    if ($ingredientId > 0 && $date !== '' && table_exists('hospitality_ingredient_costs')) {
        $stmt = db()->prepare('SELECT purchase_cost FROM hospitality_ingredient_costs
            WHERE ingredient_id = :id AND effective_date <= :d
            ORDER BY effective_date DESC, id DESC LIMIT 1');
        $stmt->execute(['id' => $ingredientId, 'd' => $date]);
        $cost = $stmt->fetchColumn();
        if ($cost !== false && $cost !== null) {
            return (float) $cost;
        }
        $earliest = db()->prepare('SELECT purchase_cost FROM hospitality_ingredient_costs
            WHERE ingredient_id = :id ORDER BY effective_date ASC, id ASC LIMIT 1');
        $earliest->execute(['id' => $ingredientId]);
        $cost = $earliest->fetchColumn();
        if ($cost !== false && $cost !== null) {
            return (float) $cost;
        }
    }
    return (float) ($ingredient['purchase_cost'] ?? 0);
}

// ---------------------------------------------------------------------------
// Recipes
// ---------------------------------------------------------------------------

function hospitality_recipe(int $recipeId): ?array
{
    $stmt = db()->prepare('SELECT r.*, m.name AS menu_item_name, m.code AS menu_item_code, m.category AS menu_item_category
        FROM hospitality_recipes r INNER JOIN hospitality_menu_items m ON m.id = r.menu_item_id
        WHERE r.id = :id LIMIT 1');
    $stmt->execute(['id' => $recipeId]);
    return $stmt->fetch() ?: null;
}

/** The recipe version in force for a menu item on a sale date, or null. */
function hospitality_active_recipe(int $menuItemId, string $date): ?array
{
    $stmt = db()->prepare("SELECT * FROM hospitality_recipes
        WHERE menu_item_id = :mid AND status = 'active'
          AND effective_from <= :d AND (effective_to IS NULL OR effective_to >= :d2)
        ORDER BY effective_from DESC, version DESC LIMIT 1");
    $stmt->execute(['mid' => $menuItemId, 'd' => $date, 'd2' => $date]);
    return $stmt->fetch() ?: null;
}

/**
 * Full recipe costing:
 *   line cost        = quantity x ingredient reference cost per recipe unit
 *   ingredient total = sum of line costs, / (1 - prep wastage%) when set
 *   batch cost       = ingredient total + packaging + other direct cost
 *   per portion      = batch cost / portions
 * Optional tenant-setting overlays (kitchen wastage / packaging / other %)
 * are computed SEPARATELY and only included when explicitly enabled.
 * Returns ok, errors[], lines[], and every intermediate figure.
 */
function hospitality_recipe_cost(int $recipeId, string $onDate = '', ?array $settings = null): array
{
    $recipe = hospitality_recipe($recipeId);
    if (!$recipe) {
        return ['ok' => false, 'errors' => ['Recipe not found.'], 'lines' => []];
    }
    $settings = $settings ?? hospitality_settings((int) $recipe['company_id']);
    $onDate = $onDate !== '' ? $onDate : (string) $recipe['effective_from'];
    $stmt = db()->prepare('SELECT l.*, i.code AS ingredient_code, i.name AS ingredient_name, i.recipe_unit,
            i.purchase_unit, i.conversion_factor, i.wastage_pct, i.yield_pct, i.purchase_cost, i.active AS ingredient_active,
            i.id AS ingredient_id
        FROM hospitality_recipe_lines l
        INNER JOIN hospitality_ingredients i ON i.id = l.ingredient_id
        WHERE l.recipe_id = :rid ORDER BY l.id ASC');
    $stmt->execute(['rid' => $recipeId]);
    $errors = [];
    $lines = [];
    $ingredientTotal = 0.0;
    foreach ($stmt->fetchAll() as $line) {
        $purchaseCost = hospitality_ingredient_cost_on($line, $onDate);
        $unit = hospitality_ingredient_unit_cost($line, $purchaseCost);
        $qty = (float) $line['quantity'];
        $lineCost = $unit['ok'] ? round($qty * $unit['unit_cost'], 4) : null;
        if (!$unit['ok']) {
            $errors[] = $line['ingredient_name'] . ': ' . $unit['error'];
        } elseif ($purchaseCost <= 0) {
            $errors[] = $line['ingredient_name'] . ': reference cost is missing (zero).';
        }
        if ($lineCost !== null) {
            $ingredientTotal += $lineCost;
        }
        $lines[] = [
            'ingredient_id' => (int) $line['ingredient_id'],
            'code' => (string) $line['ingredient_code'],
            'name' => (string) $line['ingredient_name'],
            'quantity' => $qty,
            'unit' => (string) ($line['unit'] ?? $line['recipe_unit']),
            'purchase_cost' => $purchaseCost,
            'conversion_factor' => (float) $line['conversion_factor'],
            'wastage_pct' => (float) $line['wastage_pct'],
            'yield_pct' => (float) $line['yield_pct'],
            'unit_cost' => $unit['ok'] ? $unit['unit_cost'] : null,
            'line_cost' => $lineCost,
            'error' => $unit['ok'] ? ($purchaseCost <= 0 ? 'missing cost' : null) : $unit['error'],
        ];
    }
    $prepWastage = (float) $recipe['prep_wastage_pct'];
    if ($prepWastage < 0 || $prepWastage >= 100) {
        $errors[] = 'Preparation wastage must be between 0% and below 100%.';
        $prepWastage = 0.0;
    }
    $adjustedIngredient = $prepWastage > 0 ? $ingredientTotal / (1 - $prepWastage / 100) : $ingredientTotal;
    $batchCost = $adjustedIngredient + (float) $recipe['packaging_cost'] + (float) $recipe['other_cost'];
    $portions = (float) $recipe['portions'];
    if ($portions <= 0) {
        $errors[] = 'A recipe with zero portions cannot be costed or activated.';
    }
    $perPortion = $portions > 0 ? $batchCost / $portions : 0.0;

    // Setting-based overlays — shown separately, applied only when enabled.
    $overlay = ['kitchen_wastage' => 0.0, 'packaging' => 0.0, 'other_variable' => 0.0];
    if ((int) ($settings['include_kitchen_wastage'] ?? 0) === 1 && (float) $settings['kitchen_wastage_pct'] > 0 && (float) $settings['kitchen_wastage_pct'] < 100) {
        $overlay['kitchen_wastage'] = $perPortion / (1 - (float) $settings['kitchen_wastage_pct'] / 100) - $perPortion;
    }
    if ((int) ($settings['include_packaging'] ?? 0) === 1 && (float) $settings['packaging_pct'] > 0) {
        $overlay['packaging'] = $perPortion * (float) $settings['packaging_pct'] / 100;
    }
    if ((int) ($settings['include_other_variable'] ?? 0) === 1 && (float) $settings['other_variable_pct'] > 0) {
        $overlay['other_variable'] = $perPortion * (float) $settings['other_variable_pct'] / 100;
    }
    $effectivePortion = $perPortion + $overlay['kitchen_wastage'] + $overlay['packaging'] + $overlay['other_variable'];

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'recipe' => $recipe,
        'lines' => $lines,
        'ingredient_total' => round($ingredientTotal, 4),
        'prep_wastage_pct' => $prepWastage,
        'adjusted_ingredient_total' => round($adjustedIngredient, 4),
        'packaging_cost' => (float) $recipe['packaging_cost'],
        'other_cost' => (float) $recipe['other_cost'],
        'batch_cost' => round($batchCost, 4),
        'portions' => $portions,
        'cost_per_portion' => round($perPortion, 4),
        'overlay' => array_map(static fn (float $v): float => round($v, 4), $overlay),
        'effective_cost_per_portion' => round($effectivePortion, 4),
        'costed_on' => $onDate,
    ];
}

// ---------------------------------------------------------------------------
// Sales mapping
// ---------------------------------------------------------------------------

function hospitality_normalize_desc(string $description): string
{
    return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $description)));
}

/**
 * Resolve a sales line to a mapping row. Priority: exact sales item id, then
 * approved normalized description. Returns the mapping (status mapped or
 * ignored) or null = unmapped for manual review. Never guesses silently.
 */
function hospitality_resolve_mapping(int $companyId, ?int $salesItemId, string $description, string $date): ?array
{
    if ($salesItemId !== null && $salesItemId > 0) {
        $stmt = db()->prepare("SELECT * FROM hospitality_sales_mappings
            WHERE company_id = :cid AND match_type = 'item' AND item_id = :item AND active = 1
              AND (effective_from IS NULL OR effective_from <= :d) AND (effective_to IS NULL OR effective_to >= :d2)
            ORDER BY id DESC LIMIT 1");
        $stmt->execute(['cid' => $companyId, 'item' => $salesItemId, 'd' => $date, 'd2' => $date]);
        $row = $stmt->fetch();
        if ($row) {
            return (array) $row;
        }
    }
    $norm = hospitality_normalize_desc($description);
    if ($norm === '') {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM hospitality_sales_mappings
        WHERE company_id = :cid AND match_type = 'description' AND description_norm = :norm AND active = 1
          AND (effective_from IS NULL OR effective_from <= :d) AND (effective_to IS NULL OR effective_to >= :d2)
        ORDER BY id DESC LIMIT 1");
    $stmt->execute(['cid' => $companyId, 'norm' => $norm, 'd' => $date, 'd2' => $date]);
    $row = $stmt->fetch();
    return $row ? (array) $row : null;
}

// ---------------------------------------------------------------------------
// Sales reading (READ ONLY — the source records are never modified)
// ---------------------------------------------------------------------------

/**
 * Eligible sales lines for a date range: issued/paid invoices only, tax
 * invoices plus proformas that were never converted (avoids double counting a
 * converted pair). Cancelled and draft sales are excluded entirely. Includes
 * per-invoice line-taxable totals so the invoice-level discount can be
 * allocated proportionally without double counting on the join.
 */
function hospitality_eligible_sales_lines(int $companyId, string $from, string $to): array
{
    $stmt = db()->prepare("SELECT li.id AS line_id, li.invoice_id, li.item_id, li.description, li.unit,
            li.quantity, li.rate, li.taxable_amount, li.vat_amount, li.total_amount,
            ti.invoice_no, ti.issued_on, ti.status AS invoice_status, ti.invoice_category,
            ti.discount_amount AS invoice_discount,
            inv_tot.line_taxable_total
        FROM invoice_line_items li
        INNER JOIN task_invoices ti ON ti.id = li.invoice_id
        INNER JOIN (
            SELECT invoice_id, SUM(taxable_amount) AS line_taxable_total
            FROM invoice_line_items GROUP BY invoice_id
        ) inv_tot ON inv_tot.invoice_id = li.invoice_id
        WHERE ti.company_id = :cid
          AND ti.status IN ('issued', 'paid')
          AND (ti.invoice_category = 'tax' OR (ti.invoice_category = 'proforma' AND ti.tax_invoice_id IS NULL))
          AND ti.issued_on BETWEEN :f AND :t
        ORDER BY ti.issued_on ASC, li.invoice_id ASC, li.id ASC");
    $stmt->execute(['cid' => $companyId, 'f' => $from, 't' => $to]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Daily costing (snapshots)
// ---------------------------------------------------------------------------

function hospitality_run_for_date(int $companyId, string $date): ?array
{
    $stmt = db()->prepare('SELECT * FROM hospitality_costing_runs WHERE company_id = :cid AND costing_date = :d LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'd' => $date]);
    return $stmt->fetch() ?: null;
}

/** Aggregate totals of one run (for recalc before/after history). */
function hospitality_run_totals(int $runId): array
{
    $stmt = db()->prepare("SELECT COUNT(*) AS line_count,
            COALESCE(SUM(net_sales), 0) AS net_sales,
            COALESCE(SUM(CASE WHEN status = 'costed' THEN total_cost ELSE 0 END), 0) AS est_cost,
            COALESCE(SUM(CASE WHEN status = 'costed' THEN gross_profit ELSE 0 END), 0) AS est_gp,
            COALESCE(SUM(CASE WHEN status = 'costed' THEN net_sales ELSE 0 END), 0) AS costed_net_sales
        FROM hospitality_costing_lines WHERE run_id = :run");
    $stmt->execute(['run' => $runId]);
    $row = (array) $stmt->fetch();
    return [
        'line_count' => (int) ($row['line_count'] ?? 0),
        'net_sales' => round((float) ($row['net_sales'] ?? 0), 2),
        'est_cost' => round((float) ($row['est_cost'] ?? 0), 2),
        'est_gp' => round((float) ($row['est_gp'] ?? 0), 2),
        'costed_net_sales' => round((float) ($row['costed_net_sales'] ?? 0), 2),
    ];
}

/**
 * Generate (or, with $recalc = true, controlled-recalculate) the costing
 * snapshot for one sale date. Reads eligible sales, resolves mappings and the
 * recipe in force on the sale date, snapshots quantities/costs/conversions,
 * and stores per-line estimated cost, GP and status. NEVER writes to any
 * accounting, voucher, ledger, inventory, or stock table.
 */
function hospitality_generate_costing(int $companyId, int $fiscalYearId, string $date, int $userId, bool $recalc = false, string $reason = ''): array
{
    // The date must sit inside the selected fiscal year.
    $fy = fiscal_year_by_id($fiscalYearId);
    if (!$fy || (int) $fy['company_id'] !== $companyId) {
        return ['ok' => false, 'error' => 'Fiscal year not found for this company.'];
    }
    if ($date < (string) $fy['start_date'] || $date > (string) $fy['end_date']) {
        return ['ok' => false, 'error' => 'The costing date must fall inside the selected fiscal year (' . $fy['start_date'] . ' to ' . $fy['end_date'] . ').'];
    }

    $existing = hospitality_run_for_date($companyId, $date);
    if ($existing && !$recalc) {
        return ['ok' => false, 'error' => 'This date is already costed. Use Recalculate (with a reason) to redo it.', 'run_id' => (int) $existing['id']];
    }
    if ($recalc && !$existing) {
        return ['ok' => false, 'error' => 'Nothing to recalculate — this date has not been costed yet.'];
    }
    if ($recalc && trim($reason) === '') {
        return ['ok' => false, 'error' => 'A reason is required for recalculation — it is kept in the audit trail.'];
    }

    $settings = hospitality_settings($companyId);
    $salesLines = hospitality_eligible_sales_lines($companyId, $date, $date);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $oldTotals = null;
        if ($existing) {
            $oldTotals = hospitality_run_totals((int) $existing['id']);
            $pdo->prepare('DELETE FROM hospitality_costing_lines WHERE run_id = :run')->execute(['run' => (int) $existing['id']]);
            $runId = (int) $existing['id'];
        } else {
            $pdo->prepare('INSERT INTO hospitality_costing_runs (company_id, fiscal_year_id, costing_date, generated_by, generated_at)
                    VALUES (:cid, :fy, :d, :by, NOW())')
                ->execute(['cid' => $companyId, 'fy' => $fiscalYearId, 'd' => $date, 'by' => $userId]);
            $runId = (int) $pdo->lastInsertId();
        }

        $insert = $pdo->prepare('INSERT INTO hospitality_costing_lines (
                run_id, company_id, fiscal_year_id, sale_date, invoice_id, invoice_no, line_id, sales_item_id, description,
                menu_item_id, menu_item_code, menu_item_name, category, recipe_id, recipe_version,
                qty_sold, qty_returned, net_qty, gross_sales, discount, vat, net_sales,
                unit_cost, total_cost, gross_profit, gp_pct, status, warning, snapshot_json, calculated_by
            ) VALUES (
                :run, :cid, :fy, :sd, :inv, :inv_no, :line, :item, :descr,
                :mid, :mcode, :mname, :cat, :rid, :rver,
                :qs, :qr, :nq, :gross, :disc, :vat, :net,
                :ucost, :tcost, :gp, :gpp, :status, :warning, :snap, :by
            )');

        $costedCount = 0;
        $lineCount = 0;
        foreach ($salesLines as $sale) {
            $lineCount++;
            $qty = (float) $sale['quantity'];
            $qtySold = $qty > 0 ? $qty : 0.0;
            $qtyReturned = $qty < 0 ? abs($qty) : 0.0;
            $netQty = round($qtySold - $qtyReturned, 3);
            $taxable = (float) $sale['taxable_amount'];
            $vat = (float) $sale['vat_amount'];
            $gross = round($taxable + $vat, 2);
            $discount = 0.0;
            if ((int) $settings['include_invoice_discount'] === 1 && (float) $sale['invoice_discount'] > 0 && (float) $sale['line_taxable_total'] != 0.0) {
                $discount = round((float) $sale['invoice_discount'] * $taxable / (float) $sale['line_taxable_total'], 2);
            }
            $netSales = (int) $settings['net_of_vat'] === 1
                ? round($taxable - $discount, 2)
                : round($gross - $discount, 2);

            $mapping = hospitality_resolve_mapping($companyId, $sale['item_id'] !== null ? (int) $sale['item_id'] : null, (string) $sale['description'], $date);
            $status = 'unmapped';
            $warning = null;
            $menuItem = null;
            $recipe = null;
            $recipeCost = null;
            $unitCost = null;
            $totalCost = null;
            $gp = null;
            $gpPct = null;
            $snapshot = null;

            if ($mapping !== null && (string) $mapping['status'] === 'ignored') {
                $status = 'ignored';
                $warning = (string) ($mapping['ignore_reason'] ?? '');
            } elseif ($mapping !== null && (int) ($mapping['menu_item_id'] ?? 0) > 0) {
                $miStmt = $pdo->prepare('SELECT * FROM hospitality_menu_items WHERE id = :id AND company_id = :cid LIMIT 1');
                $miStmt->execute(['id' => (int) $mapping['menu_item_id'], 'cid' => $companyId]);
                $menuItem = $miStmt->fetch() ?: null;
                if ($menuItem === null) {
                    $status = 'unmapped';
                    $warning = 'Mapping points to a missing menu item.';
                } elseif ($netQty == 0.0 && $qtySold == 0.0 && $qtyReturned == 0.0) {
                    $status = 'no_quantity';
                    $warning = 'The sales line has no quantity.';
                } else {
                    $recipe = hospitality_active_recipe((int) $menuItem['id'], $date);
                    if ($recipe === null) {
                        $draftStmt = $pdo->prepare("SELECT COUNT(*) FROM hospitality_recipes WHERE menu_item_id = :mid AND status = 'draft'");
                        $draftStmt->execute(['mid' => (int) $menuItem['id']]);
                        $status = (int) $draftStmt->fetchColumn() > 0 ? 'draft_recipe' : 'missing_recipe';
                        $warning = $status === 'draft_recipe' ? 'Only a draft recipe exists for the sale date.' : 'No active recipe covers the sale date.';
                    } else {
                        $recipeCost = hospitality_recipe_cost((int) $recipe['id'], $date, $settings);
                        if (!$recipeCost['ok']) {
                            $invalidConversion = false;
                            foreach ($recipeCost['lines'] as $rcLine) {
                                if ((string) ($rcLine['error'] ?? '') !== '' && str_contains((string) $rcLine['error'], 'conversion')) {
                                    $invalidConversion = true;
                                }
                            }
                            $status = $invalidConversion ? 'invalid_conversion' : 'missing_cost';
                            $warning = implode(' | ', array_slice($recipeCost['errors'], 0, 3));
                        } else {
                            $status = 'costed';
                            $costedCount++;
                            $unitCost = (float) $recipeCost['effective_cost_per_portion'];
                            $totalCost = round($netQty * $unitCost, 2);
                            $gp = round($netSales - $totalCost, 2);
                            $gpPct = $netSales != 0.0 ? round($gp / $netSales * 100, 2) : null;
                            $snapshot = json_encode([
                                'recipe' => ['id' => (int) $recipe['id'], 'version' => (int) $recipe['version'], 'name' => (string) $recipe['name']],
                                'lines' => $recipeCost['lines'],
                                'ingredient_total' => $recipeCost['ingredient_total'],
                                'prep_wastage_pct' => $recipeCost['prep_wastage_pct'],
                                'batch_cost' => $recipeCost['batch_cost'],
                                'portions' => $recipeCost['portions'],
                                'cost_per_portion' => $recipeCost['cost_per_portion'],
                                'overlay' => $recipeCost['overlay'],
                                'effective_cost_per_portion' => $recipeCost['effective_cost_per_portion'],
                                'settings' => [
                                    'net_of_vat' => (int) $settings['net_of_vat'],
                                    'include_invoice_discount' => (int) $settings['include_invoice_discount'],
                                    'cost_source' => (string) $settings['cost_source'],
                                ],
                            ], JSON_UNESCAPED_UNICODE);
                            if ($netQty < 0) {
                                $warning = 'Net quantity is negative (returns exceed sales) — reversal applied consistently.';
                            }
                        }
                    }
                }
            }

            $insert->execute([
                'run' => $runId, 'cid' => $companyId, 'fy' => $fiscalYearId, 'sd' => $date,
                'inv' => (int) $sale['invoice_id'], 'inv_no' => (string) $sale['invoice_no'], 'line' => (int) $sale['line_id'],
                'item' => $sale['item_id'] !== null ? (int) $sale['item_id'] : null,
                'descr' => (string) $sale['description'],
                'mid' => $menuItem !== null ? (int) $menuItem['id'] : null,
                'mcode' => $menuItem !== null ? (string) $menuItem['code'] : null,
                'mname' => $menuItem !== null ? (string) $menuItem['name'] : null,
                'cat' => $menuItem !== null ? (string) $menuItem['category'] : null,
                'rid' => $recipe !== null ? (int) $recipe['id'] : null,
                'rver' => $recipe !== null ? (int) $recipe['version'] : null,
                'qs' => $qtySold, 'qr' => $qtyReturned, 'nq' => $netQty,
                'gross' => $gross, 'disc' => $discount, 'vat' => $vat, 'net' => $netSales,
                'ucost' => $unitCost, 'tcost' => $totalCost, 'gp' => $gp, 'gpp' => $gpPct,
                'status' => $status, 'warning' => $warning, 'snap' => $snapshot, 'by' => $userId,
            ]);
        }

        $runStatus = $lineCount === 0 ? 'empty' : ($costedCount === $lineCount ? 'costed' : 'partial');
        $pdo->prepare('UPDATE hospitality_costing_runs SET status = :st, fiscal_year_id = :fy,
                generated_by = COALESCE(generated_by, :by), generated_at = COALESCE(generated_at, NOW()),
                recalculated_at = :recalc_at
            WHERE id = :id')
            ->execute([
                'st' => $runStatus, 'fy' => $fiscalYearId, 'by' => $userId,
                'recalc_at' => $recalc ? date('Y-m-d H:i:s') : null, 'id' => $runId,
            ]);

        if ($recalc) {
            $newTotals = hospitality_run_totals($runId);
            $pdo->prepare('INSERT INTO hospitality_recalc_history (company_id, run_id, costing_date, old_totals_json, new_totals_json, reason, recalculated_by)
                    VALUES (:cid, :run, :d, :old, :new, :reason, :by)')
                ->execute([
                    'cid' => $companyId, 'run' => $runId, 'd' => $date,
                    'old' => json_encode($oldTotals, JSON_UNESCAPED_UNICODE),
                    'new' => json_encode($newTotals, JSON_UNESCAPED_UNICODE),
                    'reason' => trim($reason), 'by' => $userId,
                ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $exception->getMessage()];
    }

    log_activity('hospitality_costing', $runId, $recalc ? 'recalculated' : 'generated',
        ($recalc ? 'Hospitality costing recalculated for ' : 'Hospitality costing generated for ') . $date
        . ' (' . $lineCount . ' sales lines, ' . $costedCount . ' costed)'
        . ($recalc ? ' — reason: ' . trim($reason) : '') . '. Reference estimate only; no accounting entry posted.', $userId);

    return ['ok' => true, 'run_id' => $runId, 'lines' => $lineCount, 'costed' => $costedCount, 'status' => $runStatus];
}

// ---------------------------------------------------------------------------
// Aggregation (single source of truth so all reports reconcile)
// ---------------------------------------------------------------------------

/**
 * Consolidated summary over stored costing lines for a period. Valid costed
 * sales are kept apart from uncosted ones — missing cost is NEVER treated as
 * zero cost, and completeness shows how much of the sales the estimate covers.
 */
function hospitality_summary(int $companyId, string $from, string $to): array
{
    $stmt = db()->prepare("SELECT
            COALESCE(SUM(CASE WHEN status <> 'ignored' THEN net_sales ELSE 0 END), 0) AS eligible_net_sales,
            COALESCE(SUM(CASE WHEN status = 'costed' THEN net_sales ELSE 0 END), 0) AS costed_net_sales,
            COALESCE(SUM(CASE WHEN status = 'costed' THEN total_cost ELSE 0 END), 0) AS est_cost,
            COALESCE(SUM(CASE WHEN status = 'costed' THEN gross_profit ELSE 0 END), 0) AS est_gp,
            COALESCE(SUM(CASE WHEN status = 'costed' THEN gross_sales ELSE 0 END), 0) AS costed_gross,
            COALESCE(SUM(CASE WHEN status = 'costed' THEN discount ELSE 0 END), 0) AS costed_discount,
            COALESCE(SUM(CASE WHEN status = 'costed' THEN vat ELSE 0 END), 0) AS costed_vat,
            COALESCE(SUM(CASE WHEN status = 'costed' THEN qty_sold ELSE 0 END), 0) AS qty_sold,
            COALESCE(SUM(CASE WHEN status = 'costed' THEN qty_returned ELSE 0 END), 0) AS qty_returned,
            COALESCE(SUM(CASE WHEN status = 'costed' THEN net_qty ELSE 0 END), 0) AS net_qty,
            COUNT(DISTINCT CASE WHEN status = 'costed' THEN invoice_id END) AS sales_txn_count,
            COUNT(DISTINCT CASE WHEN status = 'costed' THEN menu_item_id END) AS items_sold,
            SUM(CASE WHEN status = 'unmapped' THEN 1 ELSE 0 END) AS unmapped_count,
            COALESCE(SUM(CASE WHEN status = 'unmapped' THEN net_sales ELSE 0 END), 0) AS unmapped_value,
            SUM(CASE WHEN status IN ('missing_recipe', 'draft_recipe') THEN 1 ELSE 0 END) AS missing_recipe_count,
            SUM(CASE WHEN status IN ('missing_cost', 'invalid_conversion') THEN 1 ELSE 0 END) AS missing_cost_count,
            COALESCE(SUM(CASE WHEN status NOT IN ('costed', 'ignored') THEN net_sales ELSE 0 END), 0) AS uncosted_value
        FROM hospitality_costing_lines
        WHERE company_id = :cid AND sale_date BETWEEN :f AND :t");
    $stmt->execute(['cid' => $companyId, 'f' => $from, 't' => $to]);
    $row = (array) $stmt->fetch();

    $costedNet = (float) $row['costed_net_sales'];
    $estGp = (float) $row['est_gp'];
    $eligibleNet = (float) $row['eligible_net_sales'];

    // Simple average of valid item GP ratios (secondary analytical figure).
    $itemStmt = db()->prepare("SELECT menu_item_id, SUM(net_sales) AS s, SUM(gross_profit) AS g
        FROM hospitality_costing_lines
        WHERE company_id = :cid AND sale_date BETWEEN :f AND :t AND status = 'costed' AND menu_item_id IS NOT NULL
        GROUP BY menu_item_id HAVING SUM(net_sales) <> 0");
    $itemStmt->execute(['cid' => $companyId, 'f' => $from, 't' => $to]);
    $ratios = [];
    foreach ($itemStmt->fetchAll() as $itemRow) {
        $ratios[] = (float) $itemRow['g'] / (float) $itemRow['s'] * 100;
    }

    return [
        'eligible_net_sales' => round($eligibleNet, 2),
        'costed_net_sales' => round($costedNet, 2),
        'gross_sales' => round((float) $row['costed_gross'], 2),
        'discount' => round((float) $row['costed_discount'], 2),
        'vat' => round((float) $row['costed_vat'], 2),
        'est_cost' => round((float) $row['est_cost'], 2),
        'est_gp' => round($estGp, 2),
        'weighted_gp_pct' => $costedNet != 0.0 ? round($estGp / $costedNet * 100, 2) : null,
        'simple_avg_item_gp_pct' => $ratios !== [] ? round(array_sum($ratios) / count($ratios), 2) : null,
        'qty_sold' => round((float) $row['qty_sold'], 3),
        'qty_returned' => round((float) $row['qty_returned'], 3),
        'net_qty' => round((float) $row['net_qty'], 3),
        'sales_txn_count' => (int) $row['sales_txn_count'],
        'items_sold' => (int) $row['items_sold'],
        'gp_per_sale' => (int) $row['sales_txn_count'] > 0 ? round($estGp / (int) $row['sales_txn_count'], 2) : null,
        'gp_per_unit' => (float) $row['net_qty'] != 0.0 ? round($estGp / (float) $row['net_qty'], 2) : null,
        'unmapped_count' => (int) $row['unmapped_count'],
        'unmapped_value' => round((float) $row['unmapped_value'], 2),
        'missing_recipe_count' => (int) $row['missing_recipe_count'],
        'missing_cost_count' => (int) $row['missing_cost_count'],
        'uncosted_value' => round((float) $row['uncosted_value'], 2),
        'completeness_pct' => $eligibleNet != 0.0 ? round($costedNet / $eligibleNet * 100, 2) : null,
    ];
}

/**
 * Grouped rows over VALID COSTED lines. $groupBy: 'category', 'menu_item' or
 * 'period_day' | 'period_week' | 'period_month' | 'period_quarter'.
 * Contributions are computed against the same costed population so category,
 * item and consolidated totals always reconcile.
 */
function hospitality_grouped(int $companyId, string $from, string $to, string $groupBy): array
{
    $key = match ($groupBy) {
        'menu_item' => 'CONCAT(menu_item_code, \' — \', menu_item_name)',
        'period_day' => 'sale_date',
        'period_week' => "DATE_FORMAT(sale_date, '%x-W%v')",
        'period_month' => "DATE_FORMAT(sale_date, '%Y-%m')",
        'period_quarter' => "CONCAT(YEAR(sale_date), '-Q', QUARTER(sale_date))",
        default => 'category',
    };
    $stmt = db()->prepare("SELECT $key AS grp,
            MAX(category) AS category, MAX(menu_item_id) AS menu_item_id,
            MAX(recipe_version) AS recipe_version,
            COUNT(DISTINCT menu_item_id) AS item_count,
            SUM(qty_sold) AS qty_sold, SUM(qty_returned) AS qty_returned, SUM(net_qty) AS net_qty,
            SUM(gross_sales) AS gross_sales, SUM(discount) AS discount, SUM(vat) AS vat,
            SUM(net_sales) AS net_sales, SUM(total_cost) AS est_cost, SUM(gross_profit) AS est_gp,
            COUNT(DISTINCT invoice_id) AS txn_count, MAX(sale_date) AS last_sale
        FROM hospitality_costing_lines
        WHERE company_id = :cid AND sale_date BETWEEN :f AND :t AND status = 'costed'
        GROUP BY $key ORDER BY net_sales DESC");
    $stmt->execute(['cid' => $companyId, 'f' => $from, 't' => $to]);
    $rows = $stmt->fetchAll();

    $totalNet = 0.0;
    $totalGp = 0.0;
    foreach ($rows as $row) {
        $totalNet += (float) $row['net_sales'];
        $totalGp += (float) $row['est_gp'];
    }
    $result = [];
    foreach ($rows as $row) {
        $net = (float) $row['net_sales'];
        $gp = (float) $row['est_gp'];
        $result[] = [
            'group' => (string) $row['grp'],
            'category' => (string) ($row['category'] ?? ''),
            'menu_item_id' => (int) ($row['menu_item_id'] ?? 0),
            'recipe_version' => $row['recipe_version'] !== null ? (int) $row['recipe_version'] : null,
            'item_count' => (int) $row['item_count'],
            'qty_sold' => round((float) $row['qty_sold'], 3),
            'qty_returned' => round((float) $row['qty_returned'], 3),
            'net_qty' => round((float) $row['net_qty'], 3),
            'gross_sales' => round((float) $row['gross_sales'], 2),
            'discount' => round((float) $row['discount'], 2),
            'vat' => round((float) $row['vat'], 2),
            'net_sales' => round($net, 2),
            'est_cost' => round((float) $row['est_cost'], 2),
            'est_gp' => round($gp, 2),
            'gp_pct' => $net != 0.0 ? round($gp / $net * 100, 2) : null,
            'avg_price' => (float) $row['net_qty'] != 0.0 ? round($net / (float) $row['net_qty'], 2) : null,
            'avg_cost' => (float) $row['net_qty'] != 0.0 ? round((float) $row['est_cost'] / (float) $row['net_qty'], 4) : null,
            'sales_contribution_pct' => $totalNet != 0.0 ? round($net / $totalNet * 100, 2) : null,
            'gp_contribution_pct' => $totalGp != 0.0 ? round($gp / $totalGp * 100, 2) : null,
            'txn_count' => (int) $row['txn_count'],
            'last_sale' => (string) ($row['last_sale'] ?? ''),
        ];
    }
    return $result;
}

/** Exception rows (everything not cleanly costed) for a period. */
function hospitality_exceptions(int $companyId, string $from, string $to): array
{
    $stmt = db()->prepare("SELECT * FROM hospitality_costing_lines
        WHERE company_id = :cid AND sale_date BETWEEN :f AND :t AND status NOT IN ('costed')
        ORDER BY sale_date DESC, id DESC LIMIT 500");
    $stmt->execute(['cid' => $companyId, 'f' => $from, 't' => $to]);
    return $stmt->fetchAll();
}
