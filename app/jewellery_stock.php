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
        j.jewellery_type AS item_type, j.metal_id, j.purity_id, j.unit_id, j.track_mode,
        j.gross_weight, j.stone_weight, j.net_weight, j.wastage_pct,
        j.making_charge_basis, j.making_charge_rate, j.stone_value,
        j.vat_applicable, j.vat_base, j.hallmark, j.design_no, j.reorder_weight';

const JW_ITEM_FROM = ' FROM inventory_items i
        INNER JOIN jewellery_item_profiles j ON j.inventory_item_id = i.id
        INNER JOIN jewellery_metals m ON m.id = j.metal_id
        INNER JOIN jewellery_purities p ON p.id = j.purity_id
        INNER JOIN jewellery_units u ON u.id = j.unit_id';

/** Item rows joined to their metal/purity/unit. */
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
        $sql .= ' AND (i.sku LIKE :q OR i.name LIKE :q OR j.design_no LIKE :q)';
        $params['q'] = '%' . (string) $filters['search'] . '%';
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
                    unit_id = :unit_id, jewellery_type = :jewellery_type, track_mode = :track_mode,
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
                    jewellery_type, track_mode, gross_weight, stone_weight, net_weight, wastage_pct,
                    making_charge_basis, making_charge_rate, stone_value, vat_applicable, vat_base,
                    hallmark, design_no, reorder_weight)
                VALUES (:id, :cid, :metal_id, :purity_id, :unit_id, :jewellery_type, :track_mode, :gross_weight,
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

    db()->prepare('INSERT INTO jewellery_stock_txns
            (company_id, fiscal_year_id, item_id, txn_type, direction, txn_date, ref_no, holder_type, holder_id,
             metal_id, purity_id, unit_id, qty_pieces, gross_weight, stone_weight, diamond_weight, gross_grams, fine_weight, fine_grams, rate, amount,
             source_type, source_id, voucher_id, party_id, notes, created_by)
        VALUES (:cid, :fy, :iid, :type, :dir, :d, :ref, :ht, :hid,
             :mid, :pid, :uid, :pieces, :gross, :stone, :diamond, :ggrams, :fine, :fgrams, :rate, :amount,
             :stype, :sid, :vid, :party, :notes, :by)')
        ->execute([
            'cid' => $companyId,
            'fy' => $fiscalYearId,
            'iid' => $itemId,
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

    return (int) db()->lastInsertId();
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
    foreach (jewellery_items_list($companyId) as $item) {
        $balance = jw_item_balance($companyId, (int) $item['id'], $asOf, '');
        if (abs($balance['fine_weight']) < 0.00005 && abs($balance['qty_pieces']) < 0.0005) {
            continue;
        }
        $own = jw_item_balance($companyId, (int) $item['id'], $asOf, 'stock');
        $out[] = $item + [
            'balance' => $balance,
            'own_stock' => $own,
            'with_others_fine' => jw_round_weight($balance['fine_weight'] - $own['fine_weight']),
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

/** Opening rows for the jewellery items of a company, derived from the master. */
function jewellery_opening_rows(int $companyId, int $fiscalYearId): array
{
    $fiscalYear = fiscal_year_by_id($fiscalYearId);
    $asOn = (string) ($fiscalYear['start_date'] ?? date('Y-m-d'));

    $rows = [];
    foreach (jewellery_items_list($companyId) as $item) {
        $gross = jw_round_weight((float) ($item['opening_qty'] ?? 0));
        $amount = jw_round_money((float) ($item['opening_amount'] ?? 0));
        if (abs($gross) < 0.00005 && abs($amount) < 0.005) {
            continue;
        }
        $txn = db()->prepare("SELECT id, voucher_id, stone_weight, diamond_weight, fine_weight FROM jewellery_stock_txns
            WHERE company_id = :cid AND item_id = :iid AND txn_type = 'opening' LIMIT 1");
        $txn->execute(['cid' => $companyId, 'iid' => (int) $item['id']]);
        $txnRow = $txn->fetch(PDO::FETCH_ASSOC) ?: [];

        // The COMPUTED opening figures must win over the item's own spec
        // fields. `$item + [...]` keeps the LEFT operand's keys, and the item
        // row already carries a `gross_weight` — the profile's design weight,
        // normally zero — so written that way round the list showed every
        // opening as weightless while the books held the real figure.
        $rows[] = [
            'as_on' => $asOn,
            'gross_weight' => $gross,
            'stone_weight' => (float) ($txnRow['stone_weight'] ?? 0),
            'diamond_weight' => (float) ($txnRow['diamond_weight'] ?? 0),
            'fine_weight' => (float) ($txnRow['fine_weight'] ?? jw_fine_weight($gross, (float) $item['fineness'])),
            'amount' => $amount,
            'rate' => $gross > 0 ? jw_round_rate($amount / $gross) : 0.0,
            'item_code' => $item['code'],
            'item_name' => $item['name'],
            'stock_txn_id' => (int) ($txnRow['id'] ?? 0),
            'voucher_id' => (int) ($txnRow['voucher_id'] ?? 0),
            'posted' => (int) ($txnRow['id'] ?? 0) > 0,
        ] + $item;
    }

    return $rows;
}

/**
 * Set an item's opening stock and post it — both books at once.
 *
 * Idempotent: re-saving replaces the previous voucher and the previous metal
 * movement rather than stacking a second one, so correcting an opening is
 * simply saving it again.
 *
 * @return array{ok: bool, error: string, note: string, voucher_id: int, item_id: int}
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
    // Opening stock is the position on the FIRST day of the year; pinning it
    // there stops it drifting into the middle of the period.
    $asOn = (string) $fiscalYear['start_date'];

    $gross = jw_round_weight((float) ($input['gross_weight'] ?? 0));
    $stone = jw_round_weight((float) ($input['stone_weight'] ?? 0));
    $diamond = jw_round_weight((float) ($input['diamond_weight'] ?? 0));
    $pieces = round((float) ($input['qty_pieces'] ?? 0), 3);
    $amount = jw_round_money((float) ($input['amount'] ?? 0));
    $rate = jw_round_rate((float) ($input['rate'] ?? 0));
    if ($amount <= 0 && $rate > 0 && $gross > 0) {
        $amount = jw_round_money($rate * $gross);
    }
    if ($gross < 0 || $stone < 0 || $diamond < 0 || $pieces < 0 || $amount < 0) {
        return ['ok' => false, 'error' => 'Opening weight, pieces and value cannot be negative.', 'note' => '', 'voucher_id' => 0, 'item_id' => $itemId];
    }
    if (($stone + $diamond) > $gross + 0.00005) {
        return ['ok' => false, 'error' => 'Stone and diamond weight cannot exceed gross weight.', 'note' => '', 'voucher_id' => 0, 'item_id' => $itemId];
    }

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) {
        db()->beginTransaction();
    }
    try {
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
        if ($gross > 0 || $pieces > 0) {
            jw_record_stock_txn($companyId, [
                'item_id' => $itemId,
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
                'diamond_weight' => $diamond,
                'rate' => $gross > 0 ? jw_round_rate($amount / $gross) : $rate,
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
