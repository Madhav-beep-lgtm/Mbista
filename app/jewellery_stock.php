<?php
declare(strict_types=1);

/**
 * Jewellery Accounting — item master, dual-unit stock ledger, opening stock.
 *
 * jw_record_stock_txn() is the ONE way metal moves. Every purchase, sale,
 * karigar issue, refinery job and adjustment in later phases calls it, so the
 * tenant checks, the fine-weight derivation, the holder bookkeeping and the
 * negative-stock guard are written once and cannot be bypassed by a caller
 * that forgets them.
 *
 * Balances are DERIVED by aggregating movements rather than cached on the
 * item. A jewellery house corrects backdated entries constantly (a karigar
 * returns short, a rate is restated); a cached balance would drift silently,
 * an aggregate cannot. Valuation is weighted average over fine weight, which
 * is the only basis on which mixed-purity stock is comparable.
 */

require_once __DIR__ . '/jewellery_engine.php';

// ---------------------------------------------------------------------------
// Item master
// ---------------------------------------------------------------------------

/**
 * THE ITEM MASTER IS SHARED. A jewellery item is an inventory_items row plus a
 * jewellery_item_profiles row — there is no separate jewellery item table.
 * That is what puts a gold chain in the core Inventory list, in stock
 * valuation and in the Opening Balances reconciliation instead of hiding it
 * inside the jewellery module.
 *
 * These accessors return the shape the rest of the module already expects
 * (`code`, `item_type`, the weight fields) by aliasing across the join, so the
 * storage change stays invisible to callers.
 */
// opening_qty / opening_amount live on the shared inventory_items row and are
// what jewellery_opening_rows() reads. Leaving them out of this select made the
// Opening Stock list silently empty — the opening was saved and posted, but the
// screen that was supposed to show it back had nothing to read.
const JW_ITEM_SELECT = 'i.id, i.company_id, i.sku AS code, i.name, i.category, i.status, i.notes,
        i.hs_code, i.unit AS unit_label, i.ledger_id, i.opening_qty, i.opening_amount,
        j.jewellery_type AS item_type, j.metal_id, j.purity_id, j.unit_id, j.track_mode, j.stock_kind,
        j.gross_weight, j.stone_weight, j.net_weight, j.wastage_pct,
        j.making_charge_basis, j.making_charge_rate, j.stone_value,
        j.vat_applicable, j.vat_base, j.hallmark, j.design_no, j.reorder_weight';

const JW_ITEM_FROM = ' FROM inventory_items i
        INNER JOIN jewellery_item_profiles j ON j.inventory_item_id = i.id
        INNER JOIN jewellery_metals m ON m.id = j.metal_id
        INNER JOIN jewellery_purities p ON p.id = j.purity_id
        INNER JOIN jewellery_units u ON u.id = j.unit_id';

/** Item rows joined to their metal/purity/unit. */
/** The "no group" choice in the item-group filter, which no real name can collide with. */
const JW_ITEM_GROUP_NONE = '__ungrouped__';

/**
 * The distinct values each item filter can offer.
 *
 * Taken from every item the company has, never from the filtered result, or
 * choosing one value would empty the lists beside it and there would be no way
 * back except clearing everything.
 */
function jewellery_item_filter_options(int $companyId): array
{
    $stmt = db()->prepare('SELECT i.sku, i.name, i.category
        FROM inventory_items i
        INNER JOIN jewellery_item_profiles j ON j.inventory_item_id = i.id AND j.company_id = i.company_id
        WHERE i.company_id = :cid ORDER BY i.sku ASC');
    $stmt->execute(['cid' => $companyId]);
    $codes = [];
    $names = [];
    $groups = [];
    $hasUngrouped = false;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code = trim((string) $row['sku']);
        $name = trim((string) $row['name']);
        $group = trim((string) ($row['category'] ?? ''));
        if ($code !== '') { $codes[$code] = true; }
        if ($name !== '') { $names[$name] = true; }
        if ($group !== '') { $groups[$group] = true; } else { $hasUngrouped = true; }
    }
    $codes = array_keys($codes);
    $names = array_keys($names);
    $groups = array_keys($groups);
    sort($codes, SORT_NATURAL | SORT_FLAG_CASE);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    sort($groups, SORT_NATURAL | SORT_FLAG_CASE);

    return ['codes' => $codes, 'names' => $names, 'groups' => $groups, 'has_ungrouped' => $hasUngrouped];
}

function jewellery_items_list(int $companyId, array $filters = []): array
{
    $sql = 'SELECT ' . JW_ITEM_SELECT . ', m.code AS metal_code, m.name AS metal_name, m.metal_kind,
                p.code AS purity_code, p.fineness, u.code AS unit_code, u.grams'
        . JW_ITEM_FROM . ' WHERE i.company_id = :cid';
    $params = ['cid' => $companyId];

    if (!empty($filters['active_only'])) {
        $sql .= " AND i.status = 'active'";
    }
    if (!empty($filters['metal_id'])) {
        $sql .= ' AND j.metal_id = :mid';
        $params['mid'] = (int) $filters['metal_id'];
    }
    if (($filters['category'] ?? '') !== '') {
        $sql .= ' AND i.category = :cat';
        $params['cat'] = (string) $filters['category'];
    }
    if (($filters['search'] ?? '') !== '') {
        // One placeholder per occurrence. PDO runs with emulation off, where a
        // named placeholder stands for exactly one bound value — reusing :q for
        // all three columns is what made searching the item list fatal.
        $sql .= ' AND (i.sku LIKE :q1 OR i.name LIKE :q2 OR j.design_no LIKE :q3)';
        $like = '%' . (string) $filters['search'] . '%';
        $params['q1'] = $like;
        $params['q2'] = $like;
        $params['q3'] = $like;
    }

    // Column filters. Each narrows one heading, and they combine, so "22K gold
    // bangles that are off" is one question rather than a search followed by
    // reading down the page. Kept beside the free-text search rather than
    // replacing it: the search spans code, name and design number at once,
    // which no single column filter can do.
    //
    // Every value is bound, and the two that are not free text are checked
    // against their own vocabulary before they reach the query.
    // Exact, not partial: these are chosen from a list of the values that
    // actually exist, so a partial match would be wrong — picking the group
    // "Bangles" would drag in "Gold Bangles" as well.
    if (($filters['code'] ?? '') !== '') {
        $sql .= ' AND i.sku = :f_code';
        $params['f_code'] = (string) $filters['code'];
    }
    if (($filters['name'] ?? '') !== '') {
        $sql .= ' AND i.name = :f_name';
        $params['f_name'] = (string) $filters['name'];
    }
    if (($filters['group'] ?? '') !== '') {
        // Items filed under no group at all are a real answer to "which group?",
        // and there is no string that selects them, so they get their own token.
        if ((string) $filters['group'] === JW_ITEM_GROUP_NONE) {
            $sql .= " AND (i.category IS NULL OR i.category = '')";
        } else {
            $sql .= ' AND i.category = :f_group';
            $params['f_group'] = (string) $filters['group'];
        }
    }
    if (in_array((string) ($filters['stock_kind'] ?? ''), ['showroom', 'customer_ordered'], true)) {
        $sql .= ' AND j.stock_kind = :f_kind';
        $params['f_kind'] = (string) $filters['stock_kind'];
    }
    if (($filters['item_type'] ?? '') !== '') {
        $sql .= ' AND j.jewellery_type = :f_type';
        $params['f_type'] = (string) $filters['item_type'];
    }
    if ((int) ($filters['purity_id'] ?? 0) > 0) {
        $sql .= ' AND j.purity_id = :f_purity';
        $params['f_purity'] = (int) $filters['purity_id'];
    }
    if (in_array((string) ($filters['status'] ?? ''), ['active', 'inactive'], true)) {
        $sql .= ' AND i.status = :f_status';
        $params['f_status'] = (string) $filters['status'];
    }
    $sql .= ' ORDER BY i.sku ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jewellery_item(int $companyId, int $itemId): ?array
{
    if ($itemId <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT ' . JW_ITEM_SELECT . ', m.code AS metal_code, m.name AS metal_name, m.metal_kind,
            p.code AS purity_code, p.name AS purity_name, p.fineness,
            u.code AS unit_code, u.name AS unit_name, u.grams'
        . JW_ITEM_FROM . ' WHERE i.id = :id AND i.company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $itemId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Distinct categories in use, for filters and category-scoped ledger maps.
 *
 * Note this is what is ACTUALLY on the items, not what the master allows —
 * a filter must still offer a category that was retired from the master,
 * otherwise the items filed under it become unreachable.
 */
function jewellery_item_categories(int $companyId): array
{
    $stmt = db()->prepare("SELECT DISTINCT i.category FROM inventory_items i
        INNER JOIN jewellery_item_profiles j ON j.inventory_item_id = i.id
        WHERE i.company_id = :cid AND i.category IS NOT NULL AND i.category <> '' ORDER BY i.category ASC");
    $stmt->execute(['cid' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * The category master — the list a shop sets up once and files every item
 * under, rather than retyping the word on each item and ending up with "Ring",
 * "RING" and "Rings" as three headings in one report.
 */
function jewellery_categories_list(int $companyId, bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM jewellery_item_categories WHERE company_id = :cid';
    if ($activeOnly) {
        $sql .= ' AND active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, name ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute(['cid' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jewellery_category(int $companyId, int $categoryId): ?array
{
    $stmt = db()->prepare('SELECT * FROM jewellery_item_categories WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $categoryId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Create or rename a category. Renaming carries every item filed under the old
 * name across with it — the name IS the link, so leaving the items behind would
 * silently empty a heading the books are read by.
 */
function jewellery_save_category(int $companyId, array $data): int
{
    $categoryId = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('A category needs a name.');
    }

    $existing = $categoryId > 0 ? jewellery_category($companyId, $categoryId) : null;
    if ($categoryId > 0 && !$existing) {
        throw new RuntimeException('That category does not belong to this company.');
    }

    $clash = db()->prepare('SELECT id FROM jewellery_item_categories
        WHERE company_id = :cid AND name = :name AND id <> :id LIMIT 1');
    $clash->execute(['cid' => $companyId, 'name' => $name, 'id' => $categoryId]);
    if ($clash->fetchColumn() !== false) {
        throw new RuntimeException('There is already a category called "' . $name . '".');
    }

    $params = ['cid' => $companyId, 'name' => $name,
        'sort' => (int) ($data['sort_order'] ?? 0),
        'active' => !empty($data['active']) ? 1 : 0];

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        if ($existing) {
            db()->prepare('UPDATE jewellery_item_categories SET name = :name, sort_order = :sort, active = :active
                WHERE id = :id AND company_id = :cid')->execute($params + ['id' => $categoryId]);
            $oldName = (string) $existing['name'];
            if ($oldName !== $name) {
                db()->prepare('UPDATE inventory_items i
                    INNER JOIN jewellery_item_profiles j ON j.inventory_item_id = i.id
                    SET i.category = :new WHERE i.company_id = :cid AND i.category = :old')
                    ->execute(['new' => $name, 'cid' => $companyId, 'old' => $oldName]);
            }
        } else {
            db()->prepare('INSERT INTO jewellery_item_categories (company_id, name, sort_order, active)
                VALUES (:cid, :name, :sort, :active)')->execute($params);
            $categoryId = (int) db()->lastInsertId();
        }
        if ($ownsTransaction) {
            db()->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }

    return $categoryId;
}

/** A category can only go once nothing is filed under it. */
function jewellery_delete_category(int $companyId, int $categoryId): array
{
    $category = jewellery_category($companyId, $categoryId);
    if (!$category) {
        return ['ok' => false, 'error' => 'That category does not belong to this company.'];
    }
    $inUse = db()->prepare('SELECT COUNT(*) FROM inventory_items i
        INNER JOIN jewellery_item_profiles j ON j.inventory_item_id = i.id
        WHERE i.company_id = :cid AND i.category = :name');
    $inUse->execute(['cid' => $companyId, 'name' => (string) $category['name']]);
    $count = (int) $inUse->fetchColumn();
    if ($count > 0) {
        return ['ok' => false, 'error' => $count . ' item(s) are filed under "' . $category['name']
            . '". Move them first, or switch the category off instead of deleting it.'];
    }
    db()->prepare('DELETE FROM jewellery_item_categories WHERE id = :id AND company_id = :cid')
        ->execute(['id' => $categoryId, 'cid' => $companyId]);

    return ['ok' => true, 'error' => ''];
}

/**
 * Create or update an item. Validates that the metal, purity and unit all
 * belong to this company and that the purity belongs to the metal — a tampered
 * id must never bind another tenant's master (or a nonsensical pairing like
 * gold at a silver fineness) to these books.
 *
 * @return int the item id
 */
function jewellery_save_item(int $companyId, array $input, int $userId = 0): int
{
    $itemId = (int) ($input['id'] ?? 0);
    $code = strtoupper(trim((string) ($input['code'] ?? '')));
    $name = trim((string) ($input['name'] ?? ''));
    if ($code === '' || $name === '') {
        throw new RuntimeException('Item code and name are required.');
    }

    $metalId = (int) ($input['metal_id'] ?? 0);
    $purityId = (int) ($input['purity_id'] ?? 0);
    $unitId = (int) ($input['unit_id'] ?? 0);
    if (!jewellery_metal($companyId, $metalId)) {
        throw new RuntimeException('Choose a metal that belongs to this company.');
    }
    $purity = jewellery_purity($companyId, $purityId);
    if (!$purity || (int) $purity['metal_id'] !== $metalId) {
        throw new RuntimeException('Choose a purity that belongs to the selected metal.');
    }
    if (!jewellery_unit($companyId, $unitId)) {
        throw new RuntimeException('Choose a weight unit that belongs to this company.');
    }

    // A caller that does not mention a field leaves it as it was. Without this,
    // dropping a field from the item form would silently zero it on every item
    // the form touches — a screen deciding what the DATABASE forgets. Callers
    // that do send the field still overwrite it, including with zero.
    $existingItem = $itemId > 0 ? jewellery_item($companyId, $itemId) : null;
    $keep = static function (string $field, $fallback) use ($input, $existingItem) {
        if (array_key_exists($field, $input)) {
            return $input[$field];
        }

        return $existingItem !== null && $existingItem[$field] !== null ? $existingItem[$field] : $fallback;
    };

    $gross = jw_round_weight((float) $keep('gross_weight', 0));
    $stone = jw_round_weight((float) $keep('stone_weight', 0));
    if ($gross < 0 || $stone < 0) {
        throw new RuntimeException('Weights cannot be negative.');
    }
    if ($stone > $gross) {
        throw new RuntimeException('Stone weight cannot exceed the gross weight.');
    }
    // Net (metal-only) weight is always gross minus stones — it is derived, not
    // typed, so the two can never disagree on a saved item.
    $net = jw_round_weight($gross - $stone);

    $wastage = round((float) $keep('wastage_pct', 0), 3);
    if ($wastage < 0 || $wastage >= 100) {
        throw new RuntimeException('Wastage must be between 0% and below 100%.');
    }

    $jewelleryType = jw_enum($input['item_type'] ?? null, ['ornament', 'bullion', 'stone', 'other'], 'ornament');
    $unit = jewellery_unit($companyId, $unitId);

    // The generic half, on the shared master.
    $shared = [
        'cid' => $companyId,
        'sku' => $code,
        'name' => $name,
        'category' => trim((string) ($input['category'] ?? '')) ?: null,
        // The core module reasons in its own vocabulary; the precise jewellery
        // classification is kept on the profile.
        'item_type' => match ($jewelleryType) {
            'ornament' => 'finished_good',
            'bullion', 'stone' => 'raw_material',
            default => 'stock',
        },
        'unit' => (string) ($unit['code'] ?? 'pcs'),
        'hs_code' => trim((string) ($input['hs_code'] ?? '')) ?: null,
        'status' => (string) ($input['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
    ];

    // The jewellery half, on the profile.
    $profile = [
        'metal_id' => $metalId,
        'purity_id' => $purityId,
        'unit_id' => $unitId,
        'jewellery_type' => $jewelleryType,
        'track_mode' => (string) ($input['track_mode'] ?? '') === 'piece' ? 'piece' : 'weight',
        'stock_kind' => (string) ($input['stock_kind'] ?? ($existingItem['stock_kind'] ?? 'showroom')) === 'customer_ordered'
            ? 'customer_ordered' : 'showroom',
        'gross_weight' => $gross,
        'stone_weight' => $stone,
        'net_weight' => $net,
        'wastage_pct' => $wastage,
        'making_charge_basis' => jw_enum($keep('making_charge_basis', null), ['default', 'per_unit_weight', 'percent_of_metal', 'flat'], 'default'),
        'making_charge_rate' => max(0.0, jw_round_rate((float) $keep('making_charge_rate', 0))),
        'stone_value' => max(0.0, jw_round_money((float) $keep('stone_value', 0))),
        'vat_applicable' => !empty($keep('vat_applicable', 0)) ? 1 : 0,
        'vat_base' => jw_enum($keep('vat_base', null), ['default', 'full_value', 'making_only', 'stone_only'], 'default'),
        'hallmark' => trim((string) $keep('hallmark', '')) ?: null,
        'design_no' => trim((string) $keep('design_no', '')) ?: null,
        'reorder_weight' => max(0.0, jw_round_weight((float) $keep('reorder_weight', 0))),
    ];

    // The two halves commit together or not at all — a shared item with no
    // profile would be invisible to the jewellery module while occupying its
    // SKU, and a profile with no item would break every join.
    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        if ($itemId > 0) {
            $current = jewellery_item($companyId, $itemId);
            if (!$current) {
                throw new RuntimeException('Item not found for this company.');
            }
            // Changing the metal or purity of an item that already moved would
            // restate history the stock ledger has already valued and posted.
            $moved = db()->prepare('SELECT COUNT(*) FROM jewellery_stock_txns WHERE company_id = :cid AND item_id = :iid');
            $moved->execute(['cid' => $companyId, 'iid' => $itemId]);
            if ((int) $moved->fetchColumn() > 0
                && ((int) $current['metal_id'] !== $metalId || (int) $current['purity_id'] !== $purityId || (int) $current['unit_id'] !== $unitId)) {
                throw new RuntimeException('This item already has stock movements — its metal, purity and unit can no longer be changed. Create a new item instead.');
            }

            db()->prepare('UPDATE inventory_items SET sku = :sku, name = :name, category = :category,
                    item_type = :item_type, unit = :unit, hs_code = :hs_code, status = :status, notes = :notes
                WHERE id = :id AND company_id = :cid')
                ->execute($shared + ['id' => $itemId]);
            db()->prepare('UPDATE jewellery_item_profiles SET metal_id = :metal_id, purity_id = :purity_id,
                    unit_id = :unit_id, jewellery_type = :jewellery_type, track_mode = :track_mode, stock_kind = :stock_kind,
                    gross_weight = :gross_weight, stone_weight = :stone_weight, net_weight = :net_weight,
                    wastage_pct = :wastage_pct, making_charge_basis = :making_charge_basis,
                    making_charge_rate = :making_charge_rate, stone_value = :stone_value,
                    vat_applicable = :vat_applicable, vat_base = :vat_base, hallmark = :hallmark,
                    design_no = :design_no, reorder_weight = :reorder_weight
                WHERE inventory_item_id = :id AND company_id = :cid')
                ->execute($profile + ['id' => $itemId, 'cid' => $companyId]);
        } else {
            db()->prepare('INSERT INTO inventory_items (company_id, sku, name, category, item_type, unit, hs_code, status, notes)
                VALUES (:cid, :sku, :name, :category, :item_type, :unit, :hs_code, :status, :notes)')
                ->execute($shared);
            $itemId = (int) db()->lastInsertId();
            db()->prepare('INSERT INTO jewellery_item_profiles (inventory_item_id, company_id, metal_id, purity_id, unit_id,
                    jewellery_type, track_mode, stock_kind, gross_weight, stone_weight, net_weight, wastage_pct,
                    making_charge_basis, making_charge_rate, stone_value, vat_applicable, vat_base,
                    hallmark, design_no, reorder_weight)
                VALUES (:id, :cid, :metal_id, :purity_id, :unit_id, :jewellery_type, :track_mode, :stock_kind, :gross_weight,
                    :stone_weight, :net_weight, :wastage_pct, :making_charge_basis, :making_charge_rate,
                    :stone_value, :vat_applicable, :vat_base, :hallmark, :design_no, :reorder_weight)')
                ->execute($profile + ['id' => $itemId, 'cid' => $companyId]);
        }

        if ($ownsTransaction) {
            db()->commit();
        }
    } catch (Throwable $saveException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }
        // A duplicate SKU is the common case and deserves a real sentence
        // rather than a driver error — the master is shared now, so the clash
        // may well be with a plain inventory item.
        if ($saveException instanceof PDOException && str_contains($saveException->getMessage(), 'uniq_inventory_items_company_sku')) {
            throw new RuntimeException('The item code "' . $code . '" is already used by another item in this company.');
        }
        throw $saveException;
    }

    return $itemId;
}

/**
 * Attach, update or remove the jewellery half of a SHARED item.
 *
 * The core Inventory "Create item" form owns the generic half; because the item
 * master is now shared, that form must be able to complete the jewellery half
 * too — otherwise an item created there is invisible to the Jewellery module
 * and we are back to two creation paths producing different things.
 *
 * A blank metal means "this is a plain inventory item": any existing profile is
 * removed, unless the item already has metal movements, because those were
 * measured against its purity and unit.
 */
/**
 * Delete an item nothing has ever touched. An item with a single stock
 * movement or document line is part of the record and keeps its row — mark
 * it inactive instead; every register that names it must keep resolving.
 */
function jewellery_delete_item(int $companyId, int $itemId): array
{
    $item = jewellery_item($companyId, $itemId);
    if (!$item) {
        return ['ok' => false, 'error' => 'Item not found for this company.'];
    }
    $referees = [
        ['jewellery_stock_txns', 'item_id', 'stock movements'],
        ['jewellery_sale_lines', 'item_id', 'sale lines'],
        ['jewellery_purchase_lines', 'item_id', 'purchase lines'],
        ['jewellery_order_lines', 'item_id', 'order items'],
        ['jewellery_order_assignments', 'item_id', 'kaligad issues'],
        ['jewellery_refinery_jobs', 'item_id', 'refinery jobs'],
        ['jewellery_settlements', 'item_id', 'settlements'],
    ];
    foreach ($referees as [$table, $column, $label]) {
        if (!table_exists($table)) {
            continue;
        }
        $check = db()->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = :id AND company_id = :cid");
        $check->execute(['id' => $itemId, 'cid' => $companyId]);
        if ((int) $check->fetchColumn() > 0) {
            return ['ok' => false, 'error' => 'This item is on ' . $label
                . ' — it is part of the record. Mark it inactive instead.'];
        }
    }

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        db()->prepare('DELETE FROM jewellery_item_taxes WHERE item_id = :id AND company_id = :cid')
            ->execute(['id' => $itemId, 'cid' => $companyId]);
        db()->prepare('DELETE FROM inventory_ledger_mappings WHERE item_id = :id AND company_id = :cid')
            ->execute(['id' => $itemId, 'cid' => $companyId]);
        // These mappings just changed; forget what was read of them.
        inv_mapping_forget();
        db()->prepare('DELETE FROM jewellery_item_profiles WHERE inventory_item_id = :id AND company_id = :cid')
            ->execute(['id' => $itemId, 'cid' => $companyId]);
        db()->prepare('DELETE FROM inventory_items WHERE id = :id AND company_id = :cid')
            ->execute(['id' => $itemId, 'cid' => $companyId]);
        if ($ownsTransaction) {
            db()->commit();
        }
    } catch (Throwable $deleteException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }
        // A foreign key nobody listed above is still a record — same answer.
        return ['ok' => false, 'error' => 'Something still refers to this item — mark it inactive instead.'];
    }

    return ['ok' => true, 'error' => ''];
}

function jw_save_item_profile(int $companyId, int $inventoryItemId, array $input): void
{
    if (!table_exists('jewellery_item_profiles') || $inventoryItemId <= 0) {
        return;
    }

    $owns = db()->prepare('SELECT COUNT(*) FROM inventory_items WHERE id = :id AND company_id = :cid');
    $owns->execute(['id' => $inventoryItemId, 'cid' => $companyId]);
    if ((int) $owns->fetchColumn() === 0) {
        throw new RuntimeException('That item does not belong to this company.');
    }

    $profileStmt = db()->prepare('SELECT COUNT(*) FROM jewellery_item_profiles WHERE inventory_item_id = :id AND company_id = :cid');
    $profileStmt->execute(['id' => $inventoryItemId, 'cid' => $companyId]);
    $exists = (int) $profileStmt->fetchColumn() > 0;

    $movedStmt = db()->prepare('SELECT COUNT(*) FROM jewellery_stock_txns WHERE company_id = :cid AND item_id = :iid');
    $movedStmt->execute(['cid' => $companyId, 'iid' => $inventoryItemId]);
    $hasMoved = (int) $movedStmt->fetchColumn() > 0;

    $metalId = (int) ($input['metal_id'] ?? 0);
    if ($metalId <= 0) {
        if (!$exists) {
            return;
        }
        if ($hasMoved) {
            throw new RuntimeException('This item already has jewellery stock movements, so its metal cannot be cleared.');
        }
        db()->prepare('DELETE FROM jewellery_item_profiles WHERE inventory_item_id = :id AND company_id = :cid')
            ->execute(['id' => $inventoryItemId, 'cid' => $companyId]);

        return;
    }

    if (!jewellery_metal($companyId, $metalId)) {
        throw new RuntimeException('Choose a metal that belongs to this company.');
    }
    $purityId = (int) ($input['purity_id'] ?? 0);
    $purity = jewellery_purity($companyId, $purityId);
    if (!$purity || (int) $purity['metal_id'] !== $metalId) {
        throw new RuntimeException('Choose a purity that belongs to the selected metal.');
    }
    $unitId = (int) ($input['unit_id'] ?? 0);
    if (!jewellery_unit($companyId, $unitId)) {
        throw new RuntimeException('Choose a weight unit that belongs to this company.');
    }

    $gross = jw_round_weight((float) ($input['gross_weight'] ?? 0));
    $stone = jw_round_weight((float) ($input['stone_weight'] ?? 0));
    if ($gross < 0 || $stone < 0 || $stone > $gross) {
        throw new RuntimeException('Stone weight must be between zero and the gross weight.');
    }

    $fields = [
        'metal_id' => $metalId,
        'purity_id' => $purityId,
        'unit_id' => $unitId,
        'jewellery_type' => jw_enum($input['jewellery_type'] ?? null, ['ornament', 'bullion', 'stone', 'other'], 'ornament'),
        'gross_weight' => $gross,
        'stone_weight' => $stone,
        'net_weight' => jw_round_weight($gross - $stone),
        'making_charge_rate' => max(0.0, jw_round_rate((float) ($input['making_charge_rate'] ?? 0))),
        'vat_applicable' => !empty($input['vat_applicable']) ? 1 : 0,
        'vat_base' => jw_enum($input['vat_base'] ?? null, ['default', 'full_value', 'making_only', 'stone_only'], 'default'),
    ];

    if ($exists) {
        // A moved item's metal, purity and unit are what its history was
        // measured in — the same rule jewellery_save_item() enforces.
        if ($hasMoved) {
            $current = jewellery_item($companyId, $inventoryItemId);
            if ($current && ((int) $current['metal_id'] !== $metalId
                || (int) $current['purity_id'] !== $purityId
                || (int) $current['unit_id'] !== $unitId)) {
                throw new RuntimeException('This item already has stock movements — its metal, purity and unit can no longer be changed.');
            }
        }
        db()->prepare('UPDATE jewellery_item_profiles SET metal_id = :metal_id, purity_id = :purity_id, unit_id = :unit_id,
                jewellery_type = :jewellery_type, gross_weight = :gross_weight, stone_weight = :stone_weight,
                net_weight = :net_weight, making_charge_rate = :making_charge_rate,
                vat_applicable = :vat_applicable, vat_base = :vat_base
            WHERE inventory_item_id = :id AND company_id = :cid')
            ->execute($fields + ['id' => $inventoryItemId, 'cid' => $companyId]);

        return;
    }

    db()->prepare('INSERT INTO jewellery_item_profiles (inventory_item_id, company_id, metal_id, purity_id, unit_id,
            jewellery_type, gross_weight, stone_weight, net_weight, making_charge_rate, vat_applicable, vat_base)
        VALUES (:id, :cid, :metal_id, :purity_id, :unit_id, :jewellery_type, :gross_weight, :stone_weight,
            :net_weight, :making_charge_rate, :vat_applicable, :vat_base)')
        ->execute($fields + ['id' => $inventoryItemId, 'cid' => $companyId]);
}

/** The VAT base actually in force for an item ('default' defers to settings). */
function jw_item_vat_base(array $item, array $settings): string
{
    $base = (string) ($item['vat_base'] ?? 'default');
    if ($base === 'default' || $base === '') {
        $base = (string) ($settings['default_vat_base'] ?? 'full_value');
    }

    return in_array($base, ['full_value', 'making_only', 'stone_only'], true) ? $base : 'full_value';
}

/** The making-charge basis actually in force for an item. */
function jw_item_making_basis(array $item, array $settings): string
{
    $basis = (string) ($item['making_charge_basis'] ?? 'default');
    if ($basis === 'default' || $basis === '') {
        $basis = (string) ($settings['making_charge_basis'] ?? 'per_unit_weight');
    }

    return in_array($basis, ['per_unit_weight', 'percent_of_metal', 'flat'], true) ? $basis : 'per_unit_weight';
}

/**
 * The balance-sheet stock ledger an item's value posts to, walking the
 * purpose ladder by item type and then the generic metal-stock mapping.
 * Returns 0 when nothing resolves — the caller surfaces the gap and must
 * never guess a ledger.
 */
function jw_item_stock_ledger_id(int $companyId, array $item): int
{
    $typePurpose = match ((string) ($item['item_type'] ?? 'ornament')) {
        'ornament' => 'stock_finished',
        'stone' => 'stock_stone',
        default => 'stock_metal',
    };
    $itemId = (int) ($item['id'] ?? 0);
    $category = ($item['category'] ?? '') !== '' ? (string) $item['category'] : null;

    foreach ([$typePurpose, 'stock_metal'] as $purpose) {
        $resolved = jewellery_resolve_mapping($companyId, $purpose, $itemId ?: null, $category);
        if ($resolved) {
            return (int) $resolved['id'];
        }
    }

    // Last resort: the shared item master's own ledger, the same fallback
    // inv_item_stock_ledger_id() uses. Now that a jewellery item IS an
    // inventory item, an item already wired up in the core Inventory module
    // should not need mapping a second time here.
    if ((int) ($item['ledger_id'] ?? 0) > 0) {
        return (int) $item['ledger_id'];
    }

    return 0;
}

// ---------------------------------------------------------------------------
// The stock movement choke point
// ---------------------------------------------------------------------------

/** Movement types that leave own stock, for the negative-stock guard. */
function jw_stock_txn_types(): array
{
    return [
        'opening' => 'Opening stock',
        'purchase' => 'Purchase',
        'purchase_return' => 'Purchase return',
        'sale' => 'Sale',
        'sales_return' => 'Sales return',
        'issue_karigar' => 'Issued to karigar',
        'receive_karigar' => 'Received from karigar',
        'issue_refinery' => 'Issued to refinery',
        'receive_refinery' => 'Received from refinery',
        'wastage' => 'Wastage',
        'adjustment' => 'Adjustment',
        'transfer' => 'Transfer',
    ];
}

/**
 * True once migration 109 has made the shared inventory movement ledger able
 * to identify the Jewellery movement it mirrors.
 *
 * Jewellery stock remains the canonical metal/purity/holder ledger. The core
 * row exists so Stock Summary, stock valuation, warehouse stock and the other
 * generic inventory reports see the same own-stock movement without guessing
 * from a voucher or reference number.
 */
function jw_core_inventory_sync_ready(): bool
{
    return table_exists('inventory_transactions')
        && table_exists('jewellery_stock_txns')
        && column_exists('inventory_transactions', 'jewellery_stock_txn_id')
        && column_exists('jewellery_stock_txns', 'gross_grams');
}

/** Map the richer Jewellery vocabulary onto the core inventory vocabulary. */
function jw_core_inventory_transaction_type(string $type): string
{
    return match ($type) {
        'purchase' => 'purchase',
        'purchase_return' => 'purchase_return',
        'sale' => 'sale',
        'sales_return' => 'sales_return',
        'issue_karigar', 'issue_refinery' => 'consume',
        'receive_karigar', 'receive_refinery' => 'produce',
        'wastage' => 'write_off',
        default => 'adjustment',
    };
}

/**
 * Mirror one own-stock Jewellery movement into inventory_transactions.
 *
 * Holder rows for a kaligad/refinery/customer are deliberately excluded: they
 * describe where company-owned metal sits, while the core inventory ledger is
 * the quantity on the company's own shelf. Opening is excluded too because it
 * already lives on inventory_items.opening_qty; copying it would count it
 * twice in every core report.
 *
 * @return int the linked core movement id, or zero when no mirror is required
 */
function jw_sync_core_inventory_txn(int $companyId, int $jewelleryTxnId): int
{
    if ($companyId <= 0 || $jewelleryTxnId <= 0 || !jw_core_inventory_sync_ready()) {
        return 0;
    }

    $stmt = db()->prepare('SELECT t.*, i.default_warehouse_id, i.valuation_method,
            ju.grams AS item_unit_grams, tu.grams AS txn_unit_grams
        FROM jewellery_stock_txns t
        INNER JOIN inventory_items i ON i.id = t.item_id AND i.company_id = t.company_id
        INNER JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id AND jp.company_id = i.company_id
        INNER JOIN jewellery_units ju ON ju.id = jp.unit_id AND ju.company_id = t.company_id
        INNER JOIN jewellery_units tu ON tu.id = t.unit_id AND tu.company_id = t.company_id
        WHERE t.id = :id AND t.company_id = :cid
        LIMIT 1');
    $stmt->execute(['id' => $jewelleryTxnId, 'cid' => $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0;
    }

    $existingStmt = db()->prepare('SELECT id FROM inventory_transactions
        WHERE company_id = :cid AND jewellery_stock_txn_id = :jid LIMIT 1');
    $existingStmt->execute(['cid' => $companyId, 'jid' => $jewelleryTxnId]);
    $existingId = (int) ($existingStmt->fetchColumn() ?: 0);

    if ((string) $row['holder_type'] !== 'stock' || (string) $row['txn_type'] === 'opening') {
        if ($existingId > 0) {
            db()->prepare('DELETE FROM inventory_transactions WHERE id = :id AND company_id = :cid')
                ->execute(['id' => $existingId, 'cid' => $companyId]);
            inv_rebuild_item($companyId, (int) $row['item_id']);
        }
        return 0;
    }

    $itemUnitGrams = (float) ($row['item_unit_grams'] ?? 0);
    if ($itemUnitGrams <= 0) {
        $itemUnitGrams = 1.0;
    }
    $grossGrams = (float) ($row['gross_grams'] ?? 0);
    if ($grossGrams <= 0 && (float) $row['gross_weight'] > 0) {
        $grossGrams = (float) $row['gross_weight'] * max(0.000001, (float) ($row['txn_unit_grams'] ?? 1));
    }
    $quantity = round($grossGrams / $itemUnitGrams, 3);
    if ($quantity <= 0) {
        $quantity = round((float) ($row['qty_pieces'] ?? 0), 3);
    }
    if ($quantity <= 0) {
        if ($existingId > 0) {
            db()->prepare('DELETE FROM inventory_transactions WHERE id = :id AND company_id = :cid')
                ->execute(['id' => $existingId, 'cid' => $companyId]);
            inv_rebuild_item($companyId, (int) $row['item_id']);
        }
        return 0;
    }

    $direction = (string) $row['direction'];
    $qtyIn = $direction === 'in' ? $quantity : 0.0;
    $qtyOut = $direction === 'out' ? $quantity : 0.0;
    $amount = round((float) ($row['amount'] ?? 0), 2);
    $rate = $quantity > 0 ? round($amount / $quantity, 2) : 0.0;
    $notes = trim('Jewellery — ' . (jw_stock_txn_types()[(string) $row['txn_type']] ?? (string) $row['txn_type'])
        . (($row['notes'] ?? '') !== '' ? ': ' . (string) $row['notes'] : ''));
    $params = [
        'cid' => $companyId,
        'fy' => (int) ($row['fiscal_year_id'] ?? 0) ?: null,
        'iid' => (int) $row['item_id'],
        'warehouse' => (int) ($row['default_warehouse_id'] ?? 0) ?: null,
        'voucher' => (int) ($row['voucher_id'] ?? 0) ?: null,
        'type' => jw_core_inventory_transaction_type((string) $row['txn_type']),
        'ref' => ($row['ref_no'] ?? '') !== '' ? (string) $row['ref_no'] : null,
        'date' => (string) $row['txn_date'],
        'qin' => $qtyIn,
        'qout' => $qtyOut,
        'rate' => $rate,
        'amount' => $amount,
        'notes' => $notes,
        'jid' => $jewelleryTxnId,
    ];

    if ($existingId > 0) {
        db()->prepare('UPDATE inventory_transactions
            SET fiscal_year_id = :fy, item_id = :iid, warehouse_id = :warehouse,
                voucher_id = :voucher, transaction_type = :type, ref_no = :ref,
                transaction_date = :date, qty_in = :qin, qty_out = :qout,
                rate = :rate, amount = :amount, notes = :notes,
                jewellery_stock_txn_id = :jid
            WHERE id = :id AND company_id = :cid')
            ->execute($params + ['id' => $existingId]);
        $inventoryTxnId = $existingId;
    } else {
        db()->prepare('INSERT INTO inventory_transactions
                (company_id, fiscal_year_id, item_id, warehouse_id, voucher_id,
                 jewellery_stock_txn_id, transaction_type, ref_no, transaction_date,
                 qty_in, qty_out, rate, amount, notes)
            VALUES (:cid, :fy, :iid, :warehouse, :voucher,
                 :jid, :type, :ref, :date, :qin, :qout, :rate, :amount, :notes)')
            ->execute($params);
        $inventoryTxnId = (int) db()->lastInsertId();
    }

    // Jewellery accepts legitimate backdated corrections and, by company
    // setting, may allow temporary negative stock. Replaying is therefore
    // safer than applying only the newest row to the perpetual cost layers.
    inv_rebuild_item($companyId, (int) $row['item_id']);

    return $inventoryTxnId;
}

/** Keep a voucher link identical on both sides of the stock bridge. */
function jw_link_stock_txn_voucher(int $companyId, array $jewelleryTxnIds, ?int $voucherId): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $jewelleryTxnIds))));
    if ($companyId <= 0 || $ids === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$voucherId ?: null, $companyId], $ids);
    db()->prepare("UPDATE jewellery_stock_txns SET voucher_id = ? WHERE company_id = ? AND id IN ($placeholders)")
        ->execute($params);
    if (jw_core_inventory_sync_ready()) {
        db()->prepare("UPDATE inventory_transactions SET voucher_id = ?
            WHERE company_id = ? AND jewellery_stock_txn_id IN ($placeholders)")
            ->execute($params);
    }
}

/**
 * Delete Jewellery movements and their core mirrors as one operation, then
 * rebuild the affected item valuations. Callers normally use the source-based
 * wrapper below when unposting a document.
 */
function jw_delete_stock_txns(int $companyId, array $jewelleryTxnIds): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $jewelleryTxnIds))));
    if ($companyId <= 0 || $ids === []) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $select = db()->prepare("SELECT id, item_id, holder_type FROM jewellery_stock_txns
        WHERE company_id = ? AND id IN ($placeholders)");
    $select->execute(array_merge([$companyId], $ids));
    $rows = $select->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return 0;
    }

    $actualIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $actualPlaceholders = implode(',', array_fill(0, count($actualIds), '?'));
    $affectedItems = [];
    foreach ($rows as $row) {
        if ((string) $row['holder_type'] === 'stock') {
            $affectedItems[(int) $row['item_id']] = (int) $row['item_id'];
        }
    }

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        if (jw_core_inventory_sync_ready()) {
            db()->prepare("DELETE FROM inventory_transactions
                WHERE company_id = ? AND jewellery_stock_txn_id IN ($actualPlaceholders)")
                ->execute(array_merge([$companyId], $actualIds));
        }
        db()->prepare("DELETE FROM jewellery_stock_txns WHERE company_id = ? AND id IN ($actualPlaceholders)")
            ->execute(array_merge([$companyId], $actualIds));
        foreach ($affectedItems as $itemId) {
            inv_rebuild_item($companyId, $itemId);
        }
        if ($ownsTransaction) {
            db()->commit();
        }
        return count($actualIds);
    } catch (Throwable $exception) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $exception;
    }
}

/** Delete every stock row written by one source document. */
function jw_delete_stock_txns_by_source(int $companyId, array $sourceTypes, int $sourceId): int
{
    $sourceTypes = array_values(array_unique(array_filter(array_map('strval', $sourceTypes))));
    if ($companyId <= 0 || $sourceId <= 0 || $sourceTypes === []) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($sourceTypes), '?'));
    $stmt = db()->prepare("SELECT id FROM jewellery_stock_txns
        WHERE company_id = ? AND source_id = ? AND source_type IN ($placeholders)");
    $stmt->execute(array_merge([$companyId, $sourceId], $sourceTypes));

    return jw_delete_stock_txns($companyId, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

/**
 * Record one metal movement. THE single entry point — every module phase goes
 * through here.
 *
 * Required: item_id, txn_type, direction, txn_date. Everything else is derived
 * from the item unless overridden (old gold taken in exchange, for instance,
 * arrives at a purity that is not the item's, so purity_id may be passed).
 *
 * fine_weight is DERIVED from gross x fineness unless explicitly supplied —
 * a caller cannot accidentally record a fine weight that contradicts the
 * purity it claims.
 *
 * @return int the new movement id
 */
function jw_record_stock_txn(int $companyId, array $txn): int
{
    $itemId = (int) ($txn['item_id'] ?? 0);
    $item = jewellery_item($companyId, $itemId);
    if (!$item) {
        throw new RuntimeException('Stock movement refers to an item that does not belong to this company.');
    }

    $type = (string) ($txn['txn_type'] ?? 'adjustment');
    if (!array_key_exists($type, jw_stock_txn_types())) {
        throw new RuntimeException('Unknown stock movement type: ' . $type);
    }
    $direction = (string) ($txn['direction'] ?? '');
    if (!in_array($direction, ['in', 'out'], true)) {
        throw new RuntimeException('A stock movement must be in or out.');
    }

    $txnDate = (string) ($txn['txn_date'] ?? date('Y-m-d'));
    if (strtotime($txnDate) === false) {
        throw new RuntimeException('Invalid stock movement date.');
    }

    // Purity may differ from the item's (old gold in exchange), but it must
    // still belong to this company AND to the item's metal.
    $purityId = (int) ($txn['purity_id'] ?? $item['purity_id']);
    $purity = jewellery_purity($companyId, $purityId);
    if (!$purity || (int) $purity['metal_id'] !== (int) $item['metal_id']) {
        throw new RuntimeException('The movement purity must belong to the item\'s metal.');
    }
    $unitId = (int) ($txn['unit_id'] ?? $item['unit_id']);
    $unitRow = jewellery_unit($companyId, $unitId);
    if (!$unitRow) {
        throw new RuntimeException('The movement unit must belong to this company.');
    }

    $gross = jw_round_weight((float) ($txn['gross_weight'] ?? 0));
    $stone = jw_round_weight((float) ($txn['stone_weight'] ?? 0));
    $diamond = jw_round_weight((float) ($txn['diamond_weight'] ?? 0));
    $pieces = round((float) ($txn['qty_pieces'] ?? 0), 3);
    if ($gross < 0 || $stone < 0 || $diamond < 0 || $pieces < 0) {
        throw new RuntimeException('A stock movement cannot carry a negative weight or piece count.');
    }
    if (($stone + $diamond) > $gross + 0.00005) {
        throw new RuntimeException('Stone and diamond weight cannot exceed gross weight.');
    }
    if ($gross <= 0 && $pieces <= 0) {
        throw new RuntimeException('A stock movement must carry a weight or a piece count.');
    }
    $fine = array_key_exists('fine_weight', $txn)
        ? jw_round_weight((float) $txn['fine_weight'])
        : jw_fine_weight($gross - $stone - $diamond, (float) $purity['fineness']);

    $holderType = (string) ($txn['holder_type'] ?? 'stock');
    if (!in_array($holderType, ['stock', 'karigar', 'refinery', 'customer'], true)) {
        throw new RuntimeException('Unknown stock holder type: ' . $holderType);
    }

    $amount = jw_round_money((float) ($txn['amount'] ?? 0));
    $rate = jw_round_rate((float) ($txn['rate'] ?? 0));
    if ($amount < 0) {
        throw new RuntimeException('A stock movement cannot carry a negative amount.');
    }

    // Negative-stock guard: an OUT movement may not take more out of a holder
    // than that holder has ON THE MOVEMENT'S OWN DATE, unless the company opted
    // in. The date matters: a backdated sale must be judged against the stock
    // that existed then, not against a purchase that only arrives next week.
    //
    // Weight and pieces are checked INDEPENDENTLY. Gating the piece check on
    // "no fine weight" would let a movement carrying both drive the piece
    // balance negative unnoticed.
    $settings = jewellery_settings($companyId);
    if ($direction === 'out' && (int) ($settings['allow_negative_stock'] ?? 0) !== 1) {
        $held = jw_item_balance($companyId, $itemId, $txnDate, $holderType, (int) ($txn['holder_id'] ?? 0) ?: null);
        // Weight is compared in FINE terms: that is the only basis on which a
        // 22K issue against a 24K balance is a meaningful question at all.
        //
        // AND IN THE SAME UNIT. The balance comes back in the ITEM's unit while
        // this movement carries its own — a 5 g issue against a 0.9 tola
        // balance is not an overdraw, but comparing 5 with 0.9 says it is.
        $fineInItemUnit = jw_round_weight(jw_to_grams($fine, $unitRow) / jw_item_unit_grams($companyId, $itemId));
        if ($fine > 0 && jw_round_weight($held['fine_weight'] - $fineInItemUnit) < -0.00005) {
            throw new RuntimeException(sprintf(
                'Not enough stock on %s: %s holds %s fine of %s but the movement takes out %s fine.',
                $txnDate,
                $holderType,
                number_format($held['fine_weight'], 4),
                (string) $item['code'],
                number_format($fineInItemUnit, 4)
            ));
        }
        if ($pieces > 0 && ($held['qty_pieces'] - $pieces) < -0.0005) {
            throw new RuntimeException(sprintf(
                'Not enough stock on %s: %s holds %s pieces of %s but the movement takes out %s.',
                $txnDate,
                $holderType,
                number_format($held['qty_pieces'], 3),
                (string) $item['code'],
                number_format($pieces, 3)
            ));
        }
    }

    // The DATE decides the fiscal year, never a passed id — the same rule the
    // voucher choke point enforces, so stock and ledger always agree on period.
    $fiscalYearId = null;
    $dateFiscalYear = fiscal_year_for_date($companyId, $txnDate);
    if ($dateFiscalYear) {
        $fiscalYearId = (int) $dateFiscalYear['id'];
    }

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        db()->prepare('INSERT INTO jewellery_stock_txns
            (company_id, fiscal_year_id, item_id, stock_unit_id, txn_type, direction, txn_date, ref_no, holder_type, holder_id,
             metal_id, purity_id, unit_id, qty_pieces, gross_weight, stone_weight, diamond_weight, stone_carat, diamond_carat, stone_amount, diamond_amount, making_amount, gross_grams, fine_weight, fine_grams, rate, amount,
             source_type, source_id, voucher_id, party_id, notes, created_by)
        VALUES (:cid, :fy, :iid, :trace, :type, :dir, :d, :ref, :ht, :hid,
             :mid, :pid, :uid, :pieces, :gross, :stone, :diamond, :stone_carat, :diamond_carat, :stone_amount, :diamond_amount, :making_amount, :ggrams, :fine, :fgrams, :rate, :amount,
             :stype, :sid, :vid, :party, :notes, :by)')
        ->execute([
            'cid' => $companyId,
            'fy' => $fiscalYearId,
            'iid' => $itemId,
            'trace' => (int) ($txn['stock_unit_id'] ?? 0) ?: null,
            'type' => $type,
            'dir' => $direction,
            'd' => $txnDate,
            'ref' => ($txn['ref_no'] ?? '') !== '' ? (string) $txn['ref_no'] : null,
            'ht' => $holderType,
            'hid' => (int) ($txn['holder_id'] ?? 0) ?: null,
            'mid' => (int) $item['metal_id'],
            'pid' => $purityId,
            'uid' => $unitId,
            'pieces' => $pieces,
            'gross' => $gross,
            'stone' => $stone,
            'diamond' => $diamond,
            'stone_carat' => jw_round_weight((float) ($txn['stone_carat'] ?? 0)),
            'diamond_carat' => jw_round_weight((float) ($txn['diamond_carat'] ?? 0)),
            'stone_amount' => jw_round_money((float) ($txn['stone_amount'] ?? 0)),
            'diamond_amount' => jw_round_money((float) ($txn['diamond_amount'] ?? 0)),
            'making_amount' => jw_round_money((float) ($txn['making_amount'] ?? 0)),
            // The canonical figure, written once at the only choke point that
            // records a movement. Every balance sums THESE, so a tola in and a
            // gram out no longer cancel each other out. See migration 082.
            'ggrams' => jw_to_grams($gross, $unitRow),
            'fine' => $fine,
            'fgrams' => jw_to_grams($fine, $unitRow),
            'rate' => $rate,
            'amount' => $amount,
            'stype' => ($txn['source_type'] ?? '') !== '' ? (string) $txn['source_type'] : null,
            'sid' => (int) ($txn['source_id'] ?? 0) ?: null,
            'vid' => (int) ($txn['voucher_id'] ?? 0) ?: null,
            'party' => (int) ($txn['party_id'] ?? 0) ?: null,
            'notes' => ($txn['notes'] ?? '') !== '' ? mb_substr((string) $txn['notes'], 0, 255) : null,
            'by' => (int) ($txn['created_by'] ?? 0) ?: null,
        ]);
        $jewelleryTxnId = (int) db()->lastInsertId();
        jw_sync_core_inventory_txn($companyId, $jewelleryTxnId);
        if ($ownsTransaction) {
            db()->commit();
        }

        return $jewelleryTxnId;
    } catch (Throwable $exception) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $exception;
    }
}

/**
 * Balance of one item, optionally as at a date and for one holder.
 *
 * WEIGHTS ARE SUMMED IN GRAMS AND RESTATED IN THE ITEM'S OWN UNIT. The unit is
 * chosen per document LINE, not per item, so an item bought in tola and sold in
 * grams has movements in both — and adding those columns straight in SQL made
 * 1 tola in and 1 gram out cancel to nothing. Every movement now carries its
 * weight in grams as well (migration 082), which keeps the balance a single
 * aggregate and makes it a correct one.
 *
 * Value is a running total, and avg_fine_rate is the weighted average cost per
 * unit of PURE metal in the item's unit — the figure COGS is charged at.
 */
function jw_item_balance(int $companyId, int $itemId, ?string $asOf = null, string $holderType = 'stock', ?int $holderId = null): array
{
    // The item's own unit is the reporting unit for its balance. Cached per
    // call because this runs once per line and once per report row.
    static $unitGrams = [];
    $cacheKey = $companyId . ':' . $itemId;
    if (!isset($unitGrams[$cacheKey])) {
        $gramsStmt = db()->prepare('SELECT u.grams FROM inventory_items i
            INNER JOIN jewellery_item_profiles j ON j.inventory_item_id = i.id
            INNER JOIN jewellery_units u ON u.id = j.unit_id
            WHERE i.id = :iid AND i.company_id = :cid LIMIT 1');
        $gramsStmt->execute(['iid' => $itemId, 'cid' => $companyId]);
        $unitGrams[$cacheKey] = (float) ($gramsStmt->fetchColumn() ?: 1.0);
    }
    $item = ['grams' => $unitGrams[$cacheKey]];

    $sql = "SELECT
            COALESCE(SUM(CASE WHEN direction = 'in' THEN qty_pieces ELSE -qty_pieces END), 0) AS pieces,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN gross_grams ELSE -gross_grams END), 0) AS gross_g,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN fine_grams ELSE -fine_grams END), 0) AS fine_g,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS value,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN fine_grams ELSE 0 END), 0) AS fine_in_g,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) AS value_in,
            COUNT(*) AS movements
        FROM jewellery_stock_txns
        WHERE company_id = :cid AND item_id = :iid";
    $params = ['cid' => $companyId, 'iid' => $itemId];

    if ($holderType !== '') {
        $sql .= ' AND holder_type = :ht';
        $params['ht'] = $holderType;
        // A null holder id means "any holder of this type"; a given id pins it.
        if ($holderId !== null && $holderId > 0) {
            $sql .= ' AND holder_id = :hid';
            $params['hid'] = $holderId;
        }
    }
    if ($asOf !== null && $asOf !== '') {
        $sql .= ' AND txn_date <= :asof';
        $params['asof'] = $asOf;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Grams came out of SQL; the caller wants the ITEM's own unit, which is
    // what every rate, guard and report in the module reasons in.
    $perUnit = (float) ($item['grams'] ?? 0);
    if ($perUnit <= 0) {
        $perUnit = 1.0;
    }
    $fineIn = (float) ($row['fine_in_g'] ?? 0) / $perUnit;
    $valueIn = (float) ($row['value_in'] ?? 0);
    $fine = jw_round_weight((float) ($row['fine_g'] ?? 0) / $perUnit);
    $value = jw_round_money((float) ($row['value'] ?? 0));

    // MOVING weighted average: the value still on hand divided by the fine
    // weight still on hand. It must NOT be value_in / fine_in — that is the
    // average of everything ever bought, which drifts away from the real cost
    // of remaining stock as soon as one sale is followed by a purchase at a
    // different rate, and leaves un-relieved value stranded in the stock
    // ledger when the last unit is finally sold.
    //
    // With no stock left there is nothing to average, so fall back to the
    // all-inflow average purely so a permitted negative-stock issue still
    // books a sensible cost rather than zero.
    $avgFineRate = 0.0;
    if ($fine > 0.00005) {
        $avgFineRate = jw_round_rate($value / $fine);
    } elseif ($fineIn > 0.00005) {
        $avgFineRate = jw_round_rate($valueIn / $fineIn);
    }

    return [
        'qty_pieces' => round((float) ($row['pieces'] ?? 0), 3),
        'gross_weight' => jw_round_weight((float) ($row['gross_g'] ?? 0) / $perUnit),
        'fine_weight' => $fine,
        'value' => $value,
        'avg_fine_rate' => $avgFineRate,
        'movements' => (int) ($row['movements'] ?? 0),
    ];
}

/**
 * The same balance as jw_item_balance(), for a whole list of items at once.
 *
 * The order form labels every item in its dropdown with what is on the shelf,
 * which meant one aggregate query per item — a hundred items, a hundred round
 * trips, for a caption. This asks the same question once and groups it, and
 * returns the identical shape keyed by item id so callers are interchangeable.
 *
 * Items with no movements are still returned, at zero, so a caller can index
 * the result without checking whether the key is there.
 */
function jw_item_balances(int $companyId, array $itemIds, ?string $asOf = null, string $holderType = 'stock'): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
    if (!$ids) {
        return [];
    }
    $in = implode(',', $ids);

    // One trip for the per-item unit, so the grams SQL returns can be shown in
    // the unit the item is actually kept in.
    $perUnit = [];
    $unitStmt = db()->prepare("SELECT i.id, u.grams FROM inventory_items i
        INNER JOIN jewellery_item_profiles j ON j.inventory_item_id = i.id
        INNER JOIN jewellery_units u ON u.id = j.unit_id
        WHERE i.company_id = :cid AND i.id IN ($in)");
    $unitStmt->execute(['cid' => $companyId]);
    foreach ($unitStmt->fetchAll(PDO::FETCH_ASSOC) as $unitRow) {
        $grams = (float) $unitRow['grams'];
        $perUnit[(int) $unitRow['id']] = $grams > 0 ? $grams : 1.0;
    }

    $sql = "SELECT item_id,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN qty_pieces ELSE -qty_pieces END), 0) AS pieces,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN gross_grams ELSE -gross_grams END), 0) AS gross_g,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN fine_grams ELSE -fine_grams END), 0) AS fine_g,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS value,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN fine_grams ELSE 0 END), 0) AS fine_in_g,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) AS value_in,
            COUNT(*) AS movements
        FROM jewellery_stock_txns
        WHERE company_id = :cid AND item_id IN ($in)";
    $params = ['cid' => $companyId];
    if ($holderType !== '') {
        $sql .= ' AND holder_type = :ht';
        $params['ht'] = $holderType;
    }
    if ($asOf !== null && $asOf !== '') {
        $sql .= ' AND txn_date <= :asof';
        $params['asof'] = $asOf;
    }
    $sql .= ' GROUP BY item_id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[(int) $row['item_id']] = $row;
    }

    $out = [];
    foreach ($ids as $itemId) {
        $row = $rows[$itemId] ?? [];
        $per = $perUnit[$itemId] ?? 1.0;
        $fineIn = (float) ($row['fine_in_g'] ?? 0) / $per;
        $valueIn = (float) ($row['value_in'] ?? 0);
        $fine = jw_round_weight((float) ($row['fine_g'] ?? 0) / $per);
        $value = jw_round_money((float) ($row['value'] ?? 0));
        // Moving weighted average, on the same terms as jw_item_balance(): the
        // value still on hand over the fine weight still on hand, falling back
        // to the all-inflow average only when there is nothing left to divide.
        $avgFineRate = 0.0;
        if ($fine > 0.00005) {
            $avgFineRate = jw_round_rate($value / $fine);
        } elseif ($fineIn > 0.00005) {
            $avgFineRate = jw_round_rate($valueIn / $fineIn);
        }
        $out[$itemId] = [
            'qty_pieces' => round((float) ($row['pieces'] ?? 0), 3),
            'gross_weight' => jw_round_weight((float) ($row['gross_g'] ?? 0) / $per),
            'fine_weight' => $fine,
            'value' => $value,
            'avg_fine_rate' => $avgFineRate,
            'movements' => (int) ($row['movements'] ?? 0),
        ];
    }

    return $out;
}

/**
 * Every item's position at a date, split by WHO WAS HOLDING IT.
 *
 * At a year end metal is not all in one place: some sits in the showroom, some
 * is out with a kaligad, some is with a refiner. Folded into one figure per
 * item the boundary cannot be reconciled against the ledgers that hold those
 * same positions in money — "Metal with RAM" is its own asset account.
 *
 * One query grouped by (item, holder), never one per item: this runs over the
 * whole shop at a year end, where a per-item read would be thousands of round
 * trips. Weights come back in GRAMS, which is what the caller stores; the
 * screen converts to each item's own unit when it shows them.
 *
 * @return list<array{item_id:int, holder_type:string, holder_id:int, qty_pieces:float,
 *                    gross_grams:float, stone_grams:float, fine_grams:float, value:float}>
 */
function jw_item_holder_balances(int $companyId, ?string $asOf = null, array $itemIds = []): array
{
    $sql = "SELECT item_id, holder_type, COALESCE(holder_id, 0) AS holder_id,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN qty_pieces ELSE -qty_pieces END), 0) AS qty_pieces,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN gross_grams ELSE -gross_grams END), 0) AS gross_grams,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN stone_weight ELSE -stone_weight END), 0) AS stone_grams,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN fine_grams ELSE -fine_grams END), 0) AS fine_grams,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS value
        FROM jewellery_stock_txns
        WHERE company_id = :cid";
    $params = ['cid' => $companyId];

    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
    if ($ids !== []) {
        $sql .= ' AND item_id IN (' . implode(',', $ids) . ')';
    }
    if ($asOf !== null && $asOf !== '') {
        $sql .= ' AND txn_date <= :asof';
        $params['asof'] = $asOf;
    }
    // A holding that has netted back to nothing is not a position — it is a
    // kaligad who returned everything he was given.
    //
    // WEIGHT or VALUE, never a piece count on its own. Issuing metal for a job
    // moves the weight and the money but leaves the piece count where it was,
    // because the piece does not exist yet — the kaligad is still making it. A
    // shelf row of "one bangle, no weight, no value" is that arithmetic
    // showing through, not stock, and a year-end statement that repeated it
    // would claim goods the shop does not have.
    $sql .= ' GROUP BY item_id, holder_type, holder_id
        HAVING ABS(fine_grams) > 0.00005 OR ABS(gross_grams) > 0.00005 OR ABS(value) > 0.004
        ORDER BY item_id ASC, holder_type ASC, holder_id ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'item_id' => (int) $row['item_id'],
            'holder_type' => (string) $row['holder_type'],
            'holder_id' => (int) $row['holder_id'],
            'qty_pieces' => round((float) $row['qty_pieces'], 3),
            'gross_grams' => (float) $row['gross_grams'],
            'stone_grams' => (float) $row['stone_grams'],
            'fine_grams' => (float) $row['fine_grams'],
            'value' => jw_round_money((float) $row['value']),
        ];
    }

    return $rows;
}

/**
 * The traced pieces that were RESERVED against a customer order on a date.
 *
 * A piece made but not collected sits in the showroom — holder 'stock' — so the
 * movement ledger cannot tell it from stock that is free to sell. The trace log
 * can: each unit's last event on or before the date carries the status it was
 * in then. Without that, a year end would show a shelf of goods that are in
 * fact already spoken for.
 *
 * Returns item_id => reserved gross grams. Empty when the trace layer is not
 * installed, in which case the statement simply shows one showroom line.
 */
function jw_reserved_units_at(int $companyId, string $asOf): array
{
    if ($asOf === '' || !jewellery_trace_ready()) {
        return [];
    }
    // The last event per unit on or before the date, then the units whose
    // status at that moment was 'reserved'. Written as a join against a
    // grouped max rather than a window function so it runs on the older
    // MariaDB a shared host may still be on.
    $stmt = db()->prepare("SELECT su.item_id,
            COALESCE(SUM(su.gross_weight), 0) AS gross,
            COALESCE(SUM(su.qty_pieces), 0) AS pieces
        FROM jewellery_stock_units su
        INNER JOIN (
            SELECT e.stock_unit_id, MAX(e.id) AS last_id
            FROM jewellery_stock_unit_events e
            WHERE e.company_id = :cid1 AND e.event_date <= :asof1
            GROUP BY e.stock_unit_id
        ) last ON last.stock_unit_id = su.id
        INNER JOIN jewellery_stock_unit_events ev ON ev.id = last.last_id
        WHERE su.company_id = :cid2 AND ev.to_status = 'reserved'
        GROUP BY su.item_id");
    $stmt->execute(['cid1' => $companyId, 'asof1' => $asOf, 'cid2' => $companyId]);

    $reserved = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $reserved[(int) $row['item_id']] = [
            'gross_grams' => (float) $row['gross'],
            'qty_pieces' => round((float) $row['pieces'], 3),
        ];
    }

    return $reserved;
}

/** Grams per one unit of an item's own weight unit. Cached: this is a hot path. */
function jw_item_unit_grams(int $companyId, int $itemId): float
{
    static $cache = [];
    $key = $companyId . ':' . $itemId;
    if (!isset($cache[$key])) {
        $stmt = db()->prepare('SELECT u.grams FROM inventory_items i
            INNER JOIN jewellery_item_profiles j ON j.inventory_item_id = i.id
            INNER JOIN jewellery_units u ON u.id = j.unit_id
            WHERE i.id = :iid AND i.company_id = :cid LIMIT 1');
        $stmt->execute(['iid' => $itemId, 'cid' => $companyId]);
        $grams = (float) ($stmt->fetchColumn() ?: 0);
        $cache[$key] = $grams > 0 ? $grams : 1.0;
    }

    return $cache[$key];
}

/** Balances of one item split by holder — own stock, each karigar, refinery. */
function jewellery_item_holdings(int $companyId, int $itemId, ?string $asOf = null): array
{
    $sql = "SELECT holder_type, holder_id,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN qty_pieces ELSE -qty_pieces END), 0) AS pieces,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN gross_grams ELSE -gross_grams END), 0) AS gross_g,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN fine_grams ELSE -fine_grams END), 0) AS fine_g,
            COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS value
        FROM jewellery_stock_txns
        WHERE company_id = :cid AND item_id = :iid";
    $params = ['cid' => $companyId, 'iid' => $itemId];
    if ($asOf !== null && $asOf !== '') {
        $sql .= ' AND txn_date <= :asof';
        $params['asof'] = $asOf;
    }
    $sql .= ' GROUP BY holder_type, holder_id HAVING ABS(fine_g) > 0.00005 OR ABS(pieces) > 0.0005 ORDER BY holder_type ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Restate the gram totals in the item's own unit, and keep the historic
    // `gross` / `fine` keys so no caller has to change.
    $perUnit = jw_item_unit_grams($companyId, $itemId);
    foreach ($rows as $index => $row) {
        $rows[$index]['gross'] = jw_round_weight((float) $row['gross_g'] / $perUnit);
        $rows[$index]['fine'] = jw_round_weight((float) $row['fine_g'] / $perUnit);
    }

    return $rows;
}

/**
 * The metal position — fine weight and value by metal, purity and holder.
 * This is the report that answers "what metal do we actually have, and where".
 */
function jewellery_metal_position(int $companyId, ?string $asOf = null, array $filters = []): array
{
    $sql = "SELECT t.metal_id, t.purity_id, t.holder_type, t.holder_id,
            m.code AS metal_code, m.name AS metal_name,
            p.code AS purity_code, p.fineness,
            COALESCE(SUM(CASE WHEN t.direction = 'in' THEN t.qty_pieces ELSE -t.qty_pieces END), 0) AS pieces,
            COALESCE(SUM(CASE WHEN t.direction = 'in' THEN t.fine_grams ELSE -t.fine_grams END), 0) AS fine_g,
            COALESCE(SUM(CASE WHEN t.direction = 'in' THEN t.amount ELSE -t.amount END), 0) AS value
        FROM jewellery_stock_txns t
        INNER JOIN jewellery_metals m ON m.id = t.metal_id
        INNER JOIN jewellery_purities p ON p.id = t.purity_id
        WHERE t.company_id = :cid";
    $params = ['cid' => $companyId];

    if ($asOf !== null && $asOf !== '') {
        $sql .= ' AND t.txn_date <= :asof';
        $params['asof'] = $asOf;
    }
    if (($filters['holder_type'] ?? '') !== '') {
        $sql .= ' AND t.holder_type = :ht';
        $params['ht'] = (string) $filters['holder_type'];
    }
    if (!empty($filters['metal_id'])) {
        $sql .= ' AND t.metal_id = :mid';
        $params['mid'] = (int) $filters['metal_id'];
    }
    $sql .= ' GROUP BY t.metal_id, t.purity_id, t.holder_type, t.holder_id, m.code, m.name, p.code, p.fineness
              HAVING ABS(fine_g) > 0.00005 OR ABS(pieces) > 0.0005
              ORDER BY m.name ASC, p.fineness DESC, t.holder_type ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // This crosses items, so there is no single item unit to report in — the
    // company's reporting unit is the honest one.
    $baseUnit = jewellery_base_unit($companyId);
    $perUnit = (float) ($baseUnit['grams'] ?? 0) ?: 1.0;
    foreach ($rows as $index => $row) {
        $rows[$index]['fine'] = jw_round_weight((float) $row['fine_g'] / $perUnit);
    }

    return $rows;
}

/**
 * Movement history of one item with a running fine-weight and value balance,
 * opened by whatever the balance was the day before $from.
 */
function jewellery_stock_ledger(int $companyId, int $itemId, string $from, string $to): array
{
    $opening = jw_item_balance($companyId, $itemId, date('Y-m-d', strtotime($from . ' -1 day')), '');

    $stmt = db()->prepare('SELECT t.*, p.code AS purity_code, p.fineness, u.code AS unit_code
        FROM jewellery_stock_txns t
        INNER JOIN jewellery_purities p ON p.id = t.purity_id
        INNER JOIN jewellery_units u ON u.id = t.unit_id
        WHERE t.company_id = :cid AND t.item_id = :iid AND t.txn_date BETWEEN :from AND :to
        ORDER BY t.txn_date ASC, t.id ASC');
    $stmt->execute(['cid' => $companyId, 'iid' => $itemId, 'from' => $from, 'to' => $to]);

    $runFine = $opening['fine_weight'];
    $runValue = $opening['value'];
    $runPieces = $opening['qty_pieces'];
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sign = (string) $row['direction'] === 'in' ? 1 : -1;
        $runFine = jw_round_weight($runFine + $sign * (float) $row['fine_weight']);
        $runValue = jw_round_money($runValue + $sign * (float) $row['amount']);
        $runPieces = round($runPieces + $sign * (float) $row['qty_pieces'], 3);
        $row['balance_fine'] = $runFine;
        $row['balance_value'] = $runValue;
        $row['balance_pieces'] = $runPieces;
        $rows[] = $row;
    }

    return ['opening' => $opening, 'rows' => $rows, 'closing' => [
        'fine_weight' => $runFine, 'value' => $runValue, 'qty_pieces' => $runPieces,
    ]];
}

/** Company-wide stock valuation, item by item, as at a date. */
function jewellery_stock_valuation(int $companyId, ?string $asOf = null): array
{
    $out = [];
    // Both balances for every item in two queries rather than two per item.
    $valuationItems = jewellery_items_list($companyId);
    $valuationIds = array_column($valuationItems, 'id');
    $allHolders = jw_item_balances($companyId, $valuationIds, $asOf, '');
    $ownHolder = jw_item_balances($companyId, $valuationIds, $asOf, 'stock');
    // The stone, diamond and making components for the WHOLE shop, grouped in
    // one query. This was the last per-item read left in this loop — the two
    // balances above it had already been batched — and on a two-thousand-item
    // shop it was two thousand round trips to draw one page.
    $componentSql = "SELECT item_id,
        COALESCE(SUM(CASE WHEN direction = 'in' THEN stone_carat ELSE -stone_carat END), 0) AS stone_carat,
        COALESCE(SUM(CASE WHEN direction = 'in' THEN diamond_carat ELSE -diamond_carat END), 0) AS diamond_carat,
        COALESCE(SUM(CASE WHEN direction = 'in' THEN stone_weight ELSE -stone_weight END), 0) AS stone_weight,
        COALESCE(SUM(CASE WHEN direction = 'in' THEN stone_amount ELSE -stone_amount END), 0) AS stone_amount,
        COALESCE(SUM(CASE WHEN direction = 'in' THEN diamond_amount ELSE -diamond_amount END), 0) AS diamond_amount,
        COALESCE(SUM(CASE WHEN direction = 'in' THEN making_amount ELSE -making_amount END), 0) AS making_amount
        FROM jewellery_stock_txns WHERE company_id = :cid";
    $componentParams = ['cid' => $companyId];
    if ($asOf !== null) {
        $componentSql .= ' AND txn_date <= :asof';
        $componentParams['asof'] = $asOf;
    }
    $componentSql .= ' GROUP BY item_id';
    $componentStmt = db()->prepare($componentSql);
    $componentStmt->execute($componentParams);
    $componentsByItem = [];
    foreach ($componentStmt->fetchAll(PDO::FETCH_ASSOC) as $componentRow) {
        $componentItemId = (int) $componentRow['item_id'];
        unset($componentRow['item_id']);
        $componentsByItem[$componentItemId] = $componentRow;
    }
    // An item with no movement at all got a row of zeros from the per-item
    // query; a GROUP BY returns nothing for it, so the zeros are supplied here.
    $noComponents = ['stone_carat' => 0, 'diamond_carat' => 0, 'stone_weight' => 0,
        'stone_amount' => 0, 'diamond_amount' => 0, 'making_amount' => 0];

    foreach ($valuationItems as $item) {
        $balance = $allHolders[(int) $item['id']] ?? jw_item_balance($companyId, (int) $item['id'], $asOf, '');
        if (abs($balance['fine_weight']) < 0.00005 && abs($balance['qty_pieces']) < 0.0005) {
            continue;
        }
        $own = $ownHolder[(int) $item['id']] ?? jw_item_balance($companyId, (int) $item['id'], $asOf, 'stock');
        $components = $componentsByItem[(int) $item['id']] ?? $noComponents;
        $out[] = $item + [
            'balance' => $balance,
            'own_stock' => $own,
            'with_others_fine' => jw_round_weight($balance['fine_weight'] - $own['fine_weight']),
            'components' => $components,
        ];
    }

    return $out;
}

// ---------------------------------------------------------------------------
// Opening stock
//
// THE OPENING IS THE ITEM'S OWN, not a jewellery document. Because the item
// master is shared, a jewellery item's opening stock lives exactly where a
// plain stock item's does — inventory_items.opening_qty (the gross weight, in
// the item's own unit) and .opening_amount — and posts through the same
// inv_post_item_opening_voucher().
//
// That is not tidiness for its own sake. While jewellery kept its own opening
// table, an item edited on the core Inventory form posted an `inventory_opening`
// voucher while the jewellery screen posted a `jewellery_opening` one: two
// vouchers for one opening, and a balance sheet counting the metal twice.
//
// What jewellery adds on top is the METAL leg — the fine weight and the
// `opening` row in jewellery_stock_txns, so the metal position report opens
// with the right balance.
// ---------------------------------------------------------------------------

/**
 * Every opening movement a company has, keyed by the item it belongs to.
 *
 * One query, not one per item. Read per item this was two thousand round trips
 * on a two-thousand-item shop — the whole of the opening page's load time on a
 * shared host, and growing with the shop rather than staying put.
 *
 * An item has at most one opening movement per year (saving an opening replaces
 * the previous one rather than stacking a second), but the order is pinned
 * anyway so that a company which somehow carries two always reads back the
 * same one.
 *
 * $fiscalYearId narrows it to the year being looked at. Without that the screen
 * showed the same figures whatever year was selected, under a heading naming
 * the year — which is how a first year's opening could be read as a second
 * year's and then written over it.
 */
function jewellery_opening_txns(int $companyId, int $fiscalYearId = 0): array
{
    // Who the piece is being held for lives on the traced unit, not on the
    // movement — joined in here so the list can show it and the edit button can
    // put it back. Read per row it would be another query per item, which is
    // the thing this function exists to avoid.
    $traced = jewellery_trace_ready();
    $stmt = db()->prepare("SELECT t.item_id, t.id, t.voucher_id, t.qty_pieces, t.stone_weight, t.stone_carat,
            t.diamond_carat, t.stone_amount, t.diamond_amount, t.making_amount, t.fine_weight"
        . ($traced ? ",
            u.customer_party_id, u.customer_name, u.customer_order_no" : '') . "
        FROM jewellery_stock_txns t"
        . ($traced ? ' LEFT JOIN jewellery_stock_units u ON u.id = t.stock_unit_id' : '') . "
        WHERE t.company_id = :cid AND t.txn_type = 'opening'"
        // A movement written before this column was filled in has no year to
        // match on; it belongs to the first year, which is the only one those
        // databases had.
        . ($fiscalYearId > 0 ? ' AND (t.fiscal_year_id = :fy OR t.fiscal_year_id IS NULL)' : '') . "
        ORDER BY t.id ASC");
    $params = ['cid' => $companyId];
    if ($fiscalYearId > 0) {
        $params['fy'] = $fiscalYearId;
    }
    $stmt->execute($params);

    $byItem = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $itemId = (int) $row['item_id'];
        if (!isset($byItem[$itemId])) {
            $byItem[$itemId] = $row;
        }
    }

    return $byItem;
}

/**
 * Opening rows for the jewellery items of a company, derived from the master.
 *
 * This is the FIRST year's shape: an opening somebody keyed, held on the shared
 * item master. From the second year on an opening is not keyed at all — it is
 * the previous year's closing, carried, and jw_ob_rows() in jewellery_opening.php
 * is what reads it.
 *
 * $items lets a caller that has already listed the items hand them in rather
 * than have the whole master read a second time. Pass the UNFILTERED list: an
 * opening row exists per item that carries opening stock, not per item some
 * screen happens to be showing.
 */
function jewellery_opening_rows(int $companyId, int $fiscalYearId, ?array $items = null): array
{
    $fiscalYear = fiscal_year_by_id($fiscalYearId);
    $asOn = (string) ($fiscalYear['start_date'] ?? date('Y-m-d'));
    $openingTxns = jewellery_opening_txns($companyId, $fiscalYearId);

    $rows = [];
    foreach ($items ?? jewellery_items_list($companyId) as $item) {
        $gross = jw_round_weight((float) ($item['opening_qty'] ?? 0));
        $amount = jw_round_money((float) ($item['opening_amount'] ?? 0));
        if (abs($gross) < 0.00005 && abs($amount) < 0.005) {
            continue;
        }
        $txnRow = $openingTxns[(int) $item['id']] ?? [];

        // The COMPUTED opening figures must win over the item's own spec
        // fields. `$item + [...]` keeps the LEFT operand's keys, and the item
        // row already carries a `gross_weight` — the profile's design weight,
        // normally zero — so written that way round the list showed every
        // opening as weightless while the books held the real figure.
        $rows[] = [
            'as_on' => $asOn,
            'gross_weight' => $gross,
            'qty_pieces' => (float) ($txnRow['qty_pieces'] ?? 0),
            'stone_carat' => (float) ($txnRow['stone_carat'] ?? 0),
            'diamond_carat' => (float) ($txnRow['diamond_carat'] ?? 0),
            'stone_amount' => (float) ($txnRow['stone_amount'] ?? 0),
            'diamond_amount' => (float) ($txnRow['diamond_amount'] ?? 0),
            'making_amount' => (float) ($txnRow['making_amount'] ?? 0),
            'fine_weight' => (float) ($txnRow['fine_weight'] ?? jw_fine_weight($gross, (float) $item['fineness'])),
            'amount' => $amount,
            'rate' => $gross > 0 ? jw_round_rate($amount / $gross) : 0.0,
            'item_code' => $item['code'],
            'item_name' => $item['name'],
            'stock_txn_id' => (int) ($txnRow['id'] ?? 0),
            'voucher_id' => (int) ($txnRow['voucher_id'] ?? 0),
            'posted' => (int) ($txnRow['id'] ?? 0) > 0,
            // Carried so the row reopens holding what it was saved with. These
            // were always shown as blank before, which quietly wiped the
            // customer off any opening that was edited.
            'customer_party_id' => (int) ($txnRow['customer_party_id'] ?? 0),
            'customer_name' => (string) ($txnRow['customer_name'] ?? ''),
            'customer_order_no' => (string) ($txnRow['customer_order_no'] ?? ''),
        ] + $item;
    }

    return $rows;
}

/** What the opening list calls the group of an item filed under none. */
const JW_OPENING_NO_GROUP = 'Uncategorised';

/**
 * The posting state of one opening row, as the screen names it.
 *
 * Three states, not two: an opening can have moved metal and still never have
 * reached the ledger, and telling that apart from one the books have not seen
 * at all is the difference between "check the voucher" and "enter it".
 */
function jewellery_opening_status(array $row): string
{
    if ((int) ($row['voucher_id'] ?? 0) > 0) {
        return 'posted';
    }

    return !empty($row['posted']) ? 'weight' : 'none';
}

/**
 * The opening rows that answer a set of screen filters.
 *
 * Pure, and applied to rows already in memory rather than in SQL: the whole
 * list is read anyway to total it, so answering here costs nothing and keeps
 * one set of rules whether the question arrived from a URL or from a test.
 *
 * Text matches are "contains", case-insensitively, on what the SCREEN shows —
 * an item under no group answers to its printed name, because that is the word
 * the person is looking at when they type it.
 */
function jewellery_opening_filter(array $rows, array $filters): array
{
    $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
    $group = mb_strtolower(trim((string) ($filters['group'] ?? '')));
    $purity = mb_strtolower(trim((string) ($filters['purity'] ?? '')));
    $kind = (string) ($filters['kind'] ?? '');
    $status = (string) ($filters['status'] ?? '');
    if ($search === '' && $group === '' && $purity === '' && $kind === '' && $status === '') {
        return array_values($rows);
    }

    $has = static function (string $haystack, string $needle): bool {
        return $needle === '' || mb_strpos(mb_strtolower($haystack), $needle) !== false;
    };

    $kept = [];
    foreach ($rows as $row) {
        if ($search !== ''
            && !$has((string) ($row['item_code'] ?? ''), $search)
            && !$has((string) ($row['item_name'] ?? ''), $search)) {
            continue;
        }
        $rowGroup = trim((string) ($row['category'] ?? ''));
        if (!$has($rowGroup !== '' ? $rowGroup : JW_OPENING_NO_GROUP, $group)) {
            continue;
        }
        if (!$has((string) ($row['purity_code'] ?? ''), $purity)) {
            continue;
        }
        if ($kind !== '' && (string) ($row['stock_kind'] ?? 'showroom') !== $kind) {
            continue;
        }
        if ($status !== '' && jewellery_opening_status($row) !== $status) {
            continue;
        }
        $kept[] = $row;
    }

    return $kept;
}

/**
 * Set an item's opening stock and post it — both books at once.
 *
 * Idempotent: re-saving replaces the previous voucher and the previous metal
 * movement rather than stacking a second one, so correcting an opening is
 * simply saving it again.
 *
 * @return array{ok: bool, error: string, note: string, voucher_id: int, item_id: int, stock_unit_ids?: array}
 */
function jewellery_save_opening(int $companyId, int $fiscalYearId, array $input, int $userId = 0): array
{
    $itemId = (int) ($input['item_id'] ?? 0);
    $item = jewellery_item($companyId, $itemId);
    if (!$item) {
        return ['ok' => false, 'error' => 'Choose an item that belongs to this company.', 'note' => '', 'voucher_id' => 0, 'item_id' => 0];
    }
    $fiscalYear = fiscal_year_by_id($fiscalYearId);
    if (!$fiscalYear || (int) $fiscalYear['company_id'] !== $companyId) {
        return ['ok' => false, 'error' => 'Choose a fiscal year that belongs to this company.', 'note' => '', 'voucher_id' => 0, 'item_id' => 0];
    }
    // An opening is KEYED only in a company's first year. After that it is the
    // previous year's closing, carried — and there is nothing to type, because
    // the two have to be the same figure.
    //
    // This used to be allowed in any year, and because an opening is held on
    // the item master with no year on it, saving one in a later year did not
    // create a second opening: it moved the first one onto the later year's
    // start date, silently emptying the year it came from. Refusing is the
    // whole of the fix; the carry-forward is where the figure comes from now.
    if (function_exists('jw_ob_is_carried_year') && jw_ob_is_carried_year($companyId, $fiscalYearId)) {
        return ['ok' => false, 'error' => 'This is not the first year on these books, so its opening stock is carried from the previous year\'s closing rather than typed. '
            . 'Use "Bring forward from previous year" on the Opening Stock screen, then correct any line against a physical count.',
            'note' => '', 'voucher_id' => 0, 'item_id' => $itemId];
    }
    // Opening stock is the position on the FIRST day of the year; pinning it
    // there stops it drifting into the middle of the period.
    $asOn = (string) $fiscalYear['start_date'];

    $gross = jw_round_weight((float) ($input['gross_weight'] ?? 0));
    $stoneCarat = jw_round_weight((float) ($input['stone_carat'] ?? 0));
    $diamondCarat = jw_round_weight((float) ($input['diamond_carat'] ?? 0));
    $unitGrams = (float) ($item['grams'] ?? 1.0) ?: 1.0;
    $stone = jw_round_weight(($stoneCarat + $diamondCarat) * 0.2 / $unitGrams);
    $stoneAmount = jw_round_money((float) ($input['stone_amount'] ?? 0));
    $diamondAmount = jw_round_money((float) ($input['diamond_amount'] ?? 0));
    $makingAmount = jw_round_money((float) ($input['making_amount'] ?? 0));
    $pieces = round((float) ($input['qty_pieces'] ?? 0), 3);
    $amount = jw_round_money((float) ($input['amount'] ?? 0));
    $rate = jw_round_rate((float) ($input['rate'] ?? 0));
    if ($amount <= 0 && $rate > 0 && $gross > 0) { $amount = jw_round_money($rate * $gross); }
    if ($gross < 0 || $stoneCarat < 0 || $diamondCarat < 0 || $pieces < 0 || $amount < 0 || $stoneAmount < 0 || $diamondAmount < 0 || $makingAmount < 0) {
        return ['ok' => false, 'error' => 'Opening weight, pieces and value cannot be negative.', 'note' => '', 'voucher_id' => 0, 'item_id' => $itemId];
    }
    if ($stone > $gross + 0.00005) {
        return ['ok' => false, 'error' => 'Converted stone and diamond weight cannot exceed gross weight.', 'note' => '', 'voucher_id' => 0, 'item_id' => $itemId];
    }

    // Who the piece is being held for. Chosen off the party master when they
    // are on it, which is what ties the stock to a ledger rather than to a name
    // somebody may type two ways on two days. A walk-in who is not on the
    // master can still be named in words, as before.
    $customerPartyId = (int) ($input['customer_party_id'] ?? 0);
    $customerName = trim((string) ($input['customer_name'] ?? ''));
    if ($customerPartyId > 0) {
        $partyStmt = db()->prepare('SELECT id, name FROM accounting_parties WHERE id = :id AND company_id = :cid LIMIT 1');
        $partyStmt->execute(['id' => $customerPartyId, 'cid' => $companyId]);
        $customerParty = $partyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$customerParty) {
            return ['ok' => false, 'error' => 'Choose a customer that belongs to this company.',
                'note' => '', 'voucher_id' => 0, 'item_id' => $itemId];
        }
        // The name follows the party, so the reports that fall back to the
        // typed name read the same thing as the ones that join the master.
        $customerName = (string) $customerParty['name'];
    }

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
        $stockKind = (string) ($input['stock_kind'] ?? ($item['stock_kind'] ?? 'showroom')) === 'customer_ordered'
            ? 'customer_ordered' : 'showroom';
        $stockGroup = trim((string) ($input['stock_group'] ?? ''));
        if ($stockGroup !== '') {
            db()->prepare('UPDATE inventory_items SET category = :category WHERE id = :id AND company_id = :cid')
                ->execute(['category' => mb_substr($stockGroup, 0, 190), 'id' => $itemId, 'cid' => $companyId]);
        }
        db()->prepare('UPDATE jewellery_item_profiles SET stock_kind = :kind
            WHERE inventory_item_id = :id AND company_id = :cid')
            ->execute(['kind' => $stockKind, 'id' => $itemId, 'cid' => $companyId]);

        // 1. The shared master carries the numbers, so the core Inventory page
        //    and the Opening Balances reconciliation see the same opening.
        db()->prepare('UPDATE inventory_items SET opening_qty = :qty, opening_amount = :amount
            WHERE id = :id AND company_id = :cid')
            ->execute(['qty' => $gross, 'amount' => $amount, 'id' => $itemId, 'cid' => $companyId]);

        // 2. The money leg, through the SHARED opening poster — it replaces any
        //    prior opening voucher for this item rather than adding another.
        //
        //    It must be handed the row as the CORE module sees it.
        //    jewellery_item() aliases item_type to the jewellery classification
        //    ('ornament'), while inv_item_stock_ledger_id() reasons in the core
        //    vocabulary ('finished_good') — passing the jewellery one would miss
        //    every stock-ledger mapping and silently post nothing.
        $coreStmt = db()->prepare('SELECT * FROM inventory_items WHERE id = :id AND company_id = :cid LIMIT 1');
        $coreStmt->execute(['id' => $itemId, 'cid' => $companyId]);
        $posted = inv_post_item_opening_voucher($companyId, $coreStmt->fetch(PDO::FETCH_ASSOC) ?: [], $userId);

        // 3. The metal leg, replaced in step with it.
        db()->prepare("DELETE FROM jewellery_stock_txns
            WHERE company_id = :cid AND item_id = :iid AND txn_type = 'opening'")
            ->execute(['cid' => $companyId, 'iid' => $itemId]);
        $traceIds = jewellery_trace_replace_opening($companyId, $fiscalYearId, [
            'item_id' => $itemId,
            'purity_id' => (int) $item['purity_id'],
            'unit_id' => (int) $item['unit_id'],
            'stock_kind' => $stockKind,
            'qty_pieces' => $pieces,
            'gross_weight' => $gross,
            'stone_weight' => $stone,
            'cost_amount' => $amount,
            'origin_type' => (string) ($input['origin_type'] ?? 'manual_opening'),
            'origin_id' => (int) ($input['origin_id'] ?? $itemId),
            'origin_line_id' => (int) ($input['origin_line_id'] ?? 0),
            'customer_party_id' => $customerPartyId,
            'customer_name' => $customerName,
            'customer_order_no' => (string) ($input['customer_order_no'] ?? ''),
            'event_date' => $asOn,
            'reference_no' => (string) ($input['reference_no'] ?? 'OPENING'),
        ], $userId);
        if ($gross > 0 || $pieces > 0) {
            jw_record_stock_txn($companyId, [
                'item_id' => $itemId,
                'stock_unit_id' => (int) ($traceIds[0] ?? 0),
                'txn_type' => 'opening',
                'direction' => 'in',
                'txn_date' => $asOn,
                'ref_no' => 'OPENING',
                'holder_type' => 'stock',
                'purity_id' => (int) $item['purity_id'],
                'unit_id' => (int) $item['unit_id'],
                'qty_pieces' => $pieces,
                'gross_weight' => $gross,
                'stone_weight' => $stone,
                'stone_carat' => $stoneCarat,
                'diamond_carat' => $diamondCarat,
                'stone_amount' => $stoneAmount,
                'diamond_amount' => $diamondAmount,
                'making_amount' => $makingAmount,
                'rate' => $gross > 0 ? jw_round_rate($amount / $gross) : 0.0,
                'amount' => $amount,
                'source_type' => 'inventory_opening',
                'source_id' => $itemId,
                'voucher_id' => (int) ($posted['voucher_id'] ?? 0) ?: null,
                'created_by' => $userId,
            ]);
        }

        if ($ownsTransaction) {
            db()->commit();
        }

        return [
            'ok' => true, 'error' => '',
            'note' => (string) ($posted['note'] ?? ''),
            'voucher_id' => (int) ($posted['voucher_id'] ?? 0),
            'item_id' => $itemId,
            'stock_unit_ids' => $traceIds,
        ];
    } catch (Throwable $openingException) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'error' => $openingException->getMessage(), 'note' => '', 'voucher_id' => 0, 'item_id' => $itemId];
    }
}

/**
 * Clear an item's opening stock — zeroes both books together.
 *
 * The fiscal year may be passed explicitly; it falls back to the session
 * context only for callers that already have one, so this never silently
 * depends on a context a background caller might not have set.
 */
function jewellery_clear_opening(int $companyId, int $itemId, int $userId = 0, ?int $fiscalYearId = null): array
{
    if ($fiscalYearId === null) {
        $fiscalYear = current_fiscal_year();
        $fiscalYearId = (int) ($fiscalYear['id'] ?? 0);
    }

    // Zeroing is just an opening of nothing, so the same path handles removing
    // the voucher and removing the movement.
    return jewellery_save_opening($companyId, $fiscalYearId, [
        'item_id' => $itemId, 'gross_weight' => 0, 'qty_pieces' => 0, 'amount' => 0,
    ], $userId);
}
// ---------------------------------------------------------------------------
// Document numbering
// ---------------------------------------------------------------------------

/**
 * Next document number for a prefix, scoped to the company. Scans the existing
 * maximum rather than keeping a counter table, so a deleted document does not
 * strand its number and a restored backup cannot collide.
 */
function jewellery_next_document_no(int $companyId, string $prefix): string
{
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $prefix) ?: 'JW');
    $stmt = db()->prepare("SELECT voucher_no FROM vouchers
        WHERE company_id = :cid AND voucher_no LIKE :like
        ORDER BY LENGTH(voucher_no) DESC, voucher_no DESC LIMIT 1");
    $stmt->execute(['cid' => $companyId, 'like' => $prefix . '-%']);
    $last = (string) ($stmt->fetchColumn() ?: '');
    $next = 1;
    if ($last !== '' && preg_match('/-(\d+)$/', $last, $matches)) {
        $next = (int) $matches[1] + 1;
    }

    return $prefix . '-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
}

// Physical-piece identity sits above the aggregate metal ledger in this file.
// Requiring it here makes the same trace engine available to opening stock,
// purchases, workshop receipts, orders and sales without another copy of the
// rules in each module.
require_once __DIR__ . '/jewellery_trace.php';

// Year-to-year succession: what one year closed with, as what the next opened
// with. At the FOOT of this file, and require_once on both sides, because
// jewellery_opening.php needs the readers above — PHP resolves the cycle by
// returning immediately from the inner require, so both files load whichever
// one is asked for first.
require_once __DIR__ . '/jewellery_opening.php';
