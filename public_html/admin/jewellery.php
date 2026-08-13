<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/jewellery_engine.php';
require_once __DIR__ . '/../../app/jewellery_stock.php';
require_once __DIR__ . '/../../app/opening_stock_import.php';

// Server-side gate: books access + company context + client feature flag +
// jewellery.view. Direct URLs are denied when the flag is off — the hidden
// menu is never the only protection.
accounting_module_repair_database();
require_jewellery();

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

$settings = jewellery_settings($companyId);
$canEdit = user_can_do('jewellery', 'edit');
$canExport = user_can_do('jewellery', 'export');

$allowedViews = ['dashboard', 'rates', 'items', 'opening', 'stock', 'masters', 'settings'];
$view = (string) ($_GET['view'] ?? 'dashboard');
if (!in_array($view, $allowedViews, true)) {
    $view = 'dashboard';
}

// Rate work is dated, and a date outside the open fiscal year would post into
// a period this year cannot own. Clamp every date the page offers.
$clampDate = static function (string $date) use ($fyStart, $fyEnd): string {
    if ($date === '' || strtotime($date) === false) {
        $date = date('Y-m-d');
    }
    if ($date < $fyStart) {
        return $fyStart;
    }
    if ($date > $fyEnd) {
        return $fyEnd;
    }

    return $date;
};
$todayInFy = $clampDate(date('Y-m-d'));

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $back = 'admin/jewellery.php?view=' . urlencode((string) ($_POST['back_view'] ?? $view));

    if ($action === 'save_rate') {
        require_permission('jewellery', 'edit');
        try {
            jewellery_save_rate($companyId, [
                'rate_date' => $clampDate(trim((string) ($_POST['rate_date'] ?? ''))),
                'metal_id' => (int) ($_POST['metal_id'] ?? 0),
                'purity_id' => (int) ($_POST['purity_id'] ?? 0),
                'unit_id' => (int) ($_POST['unit_id'] ?? 0),
                'rate_type' => (string) ($_POST['rate_type'] ?? 'market'),
                'rate' => (float) ($_POST['rate'] ?? 0),
                'note' => trim((string) ($_POST['note'] ?? '')),
            ], $userId);
            flash('success', 'Daily rate saved.');
        } catch (Throwable $rateException) {
            flash('error', $rateException->getMessage());
        }
        redirect($back . '&date=' . urlencode($clampDate(trim((string) ($_POST['rate_date'] ?? '')))));
    }

    if ($action === 'delete_rate') {
        require_permission('jewellery', 'edit');
        $removed = jewellery_delete_rate($companyId, (int) ($_POST['rate_id'] ?? 0));
        flash($removed ? 'success' : 'error', $removed ? 'Rate removed.' : 'That rate was not found for this company.');
        redirect($back . '&date=' . urlencode($clampDate((string) ($_POST['rate_date'] ?? ''))));
    }

    if ($action === 'save_unit') {
        require_permission('jewellery', 'edit');
        $unitId = (int) ($_POST['unit_id'] ?? 0);
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $grams = round((float) ($_POST['grams'] ?? 0), 6);
        if ($code === '' || $name === '') {
            flash('error', 'Unit code and name are required.');
            redirect($back);
        }
        // A zero or negative gram factor would make every conversion through
        // this unit divide by zero or flip sign.
        if ($grams <= 0) {
            flash('error', 'Grams per unit must be greater than zero.');
            redirect($back);
        }
        try {
            if ($unitId > 0) {
                db()->prepare('UPDATE jewellery_units SET code = :code, name = :name, grams = :grams, active = :active WHERE id = :id AND company_id = :cid')
                    ->execute(['code' => $code, 'name' => $name, 'grams' => $grams, 'active' => isset($_POST['active']) ? 1 : 0, 'id' => $unitId, 'cid' => $companyId]);
            } else {
                db()->prepare('INSERT INTO jewellery_units (company_id, code, name, grams, sort_order) VALUES (:cid, :code, :name, :grams, 100)')
                    ->execute(['cid' => $companyId, 'code' => $code, 'name' => $name, 'grams' => $grams]);
            }
            flash('success', 'Weight unit saved.');
        } catch (Throwable $unitException) {
            flash('error', 'Could not save the unit — the code may already be in use.');
        }
        redirect($back);
    }

    if ($action === 'save_metal') {
        require_permission('jewellery', 'edit');
        $metalId = (int) ($_POST['metal_id'] ?? 0);
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $kind = jw_enum($_POST['metal_kind'] ?? null, ['metal', 'stone', 'other'], 'metal');
        $defaultUnitId = (int) ($_POST['default_unit_id'] ?? 0);
        if ($code === '' || $name === '') {
            flash('error', 'Metal code and name are required.');
            redirect($back);
        }
        if ($defaultUnitId > 0 && !jewellery_unit($companyId, $defaultUnitId)) {
            flash('error', 'Choose a weight unit that belongs to this company.');
            redirect($back);
        }
        try {
            $params = [
                'code' => $code, 'name' => $name, 'kind' => $kind,
                'tp' => isset($_POST['track_purity']) ? 1 : 0,
                'unit' => $defaultUnitId ?: null,
                'active' => isset($_POST['active']) ? 1 : 0,
                'cid' => $companyId,
            ];
            if ($metalId > 0) {
                db()->prepare('UPDATE jewellery_metals SET code = :code, name = :name, metal_kind = :kind, track_purity = :tp, default_unit_id = :unit, active = :active WHERE id = :id AND company_id = :cid')
                    ->execute($params + ['id' => $metalId]);
            } else {
                db()->prepare('INSERT INTO jewellery_metals (company_id, code, name, metal_kind, track_purity, default_unit_id, active, sort_order) VALUES (:cid, :code, :name, :kind, :tp, :unit, :active, 100)')
                    ->execute($params);
            }
            flash('success', 'Metal saved.');
        } catch (Throwable $metalException) {
            flash('error', 'Could not save the metal — the code may already be in use.');
        }
        redirect($back);
    }

    if ($action === 'save_purity') {
        require_permission('jewellery', 'edit');
        $purityId = (int) ($_POST['purity_id'] ?? 0);
        $metalId = (int) ($_POST['metal_id'] ?? 0);
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $fineness = round((float) ($_POST['fineness'] ?? 0), 4);
        if (!jewellery_metal($companyId, $metalId)) {
            flash('error', 'Choose a metal that belongs to this company.');
            redirect($back);
        }
        if ($code === '' || $name === '') {
            flash('error', 'Purity code and name are required.');
            redirect($back);
        }
        // Fineness is parts per 1000, so anything outside (0, 1000] would make
        // fine weight either zero or larger than the gross weight it came from.
        if ($fineness <= 0 || $fineness > 1000) {
            flash('error', 'Fineness must be above 0 and at most 1000 (parts per thousand).');
            redirect($back);
        }
        $isDefault = isset($_POST['is_default']) ? 1 : 0;
        try {
            db()->beginTransaction();
            if ($isDefault === 1) {
                db()->prepare('UPDATE jewellery_purities SET is_default = 0 WHERE company_id = :cid AND metal_id = :mid')
                    ->execute(['cid' => $companyId, 'mid' => $metalId]);
            }
            $params = [
                'code' => $code, 'name' => $name, 'fine' => $fineness, 'def' => $isDefault,
                'active' => isset($_POST['active']) ? 1 : 0, 'cid' => $companyId, 'mid' => $metalId,
            ];
            if ($purityId > 0) {
                db()->prepare('UPDATE jewellery_purities SET code = :code, name = :name, fineness = :fine, is_default = :def, active = :active, metal_id = :mid WHERE id = :id AND company_id = :cid')
                    ->execute($params + ['id' => $purityId]);
            } else {
                db()->prepare('INSERT INTO jewellery_purities (company_id, metal_id, code, name, fineness, is_default, active, sort_order) VALUES (:cid, :mid, :code, :name, :fine, :def, :active, 100)')
                    ->execute($params);
            }
            db()->commit();
            flash('success', 'Purity saved.');
        } catch (Throwable $purityException) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not save the purity — the code may already be in use for this metal.');
        }
        redirect($back);
    }

    if ($action === 'save_settings') {
        require_permission('jewellery', 'edit');
        $baseUnitId = (int) ($_POST['base_unit_id'] ?? 0);
        $defaultMetalId = (int) ($_POST['default_metal_id'] ?? 0);
        if ($baseUnitId > 0 && !jewellery_unit($companyId, $baseUnitId)) {
            flash('error', 'Choose a reporting unit that belongs to this company.');
            redirect($back);
        }
        if ($defaultMetalId > 0 && !jewellery_metal($companyId, $defaultMetalId)) {
            flash('error', 'Choose a default metal that belongs to this company.');
            redirect($back);
        }
        $vatRate = round((float) ($_POST['vat_rate'] ?? 13), 2);
        if ($vatRate < 0 || $vatRate > 100) {
            flash('error', 'The VAT rate must be between 0% and 100%.');
            redirect($back);
        }
        $wastage = round((float) ($_POST['default_wastage_pct'] ?? 0), 3);
        if ($wastage < 0 || $wastage >= 100) {
            flash('error', 'Default wastage must be between 0% and below 100%.');
            redirect($back);
        }
        jewellery_save_settings($companyId, [
            'base_unit_id' => $baseUnitId ?: null,
            'default_metal_id' => $defaultMetalId ?: null,
            'weight_precision' => max(0, min(6, (int) ($_POST['weight_precision'] ?? 4))),
            'rate_precision' => max(0, min(6, (int) ($_POST['rate_precision'] ?? 2))),
            'amount_precision' => max(0, min(4, (int) ($_POST['amount_precision'] ?? 2))),
            'vat_rate' => $vatRate,
            'default_vat_base' => jw_enum($_POST['default_vat_base'] ?? null, ['full_value', 'making_only', 'stone_only'], 'full_value'),
            'making_charge_basis' => jw_enum($_POST['making_charge_basis'] ?? null, ['per_unit_weight', 'percent_of_metal', 'flat'], 'per_unit_weight'),
            'default_wastage_pct' => $wastage,
            'rate_source' => (string) ($_POST['rate_source'] ?? '') === 'manual' ? 'manual' : 'last_known',
            'allow_negative_stock' => isset($_POST['allow_negative_stock']) ? 1 : 0,
            // auto_post is deliberately NOT here. The checkbox claimed to
            // control automatic ledger posting and controlled nothing — no
            // code ever read it. Posting is always explicit: a draft, then a
            // confirmation with the ledger legs on screen.
            'sale_no_prefix' => strtoupper(trim((string) ($_POST['sale_no_prefix'] ?? 'JS'))) ?: 'JS',
            'purchase_no_prefix' => strtoupper(trim((string) ($_POST['purchase_no_prefix'] ?? 'JP'))) ?: 'JP',
            'order_no_prefix' => strtoupper(trim((string) ($_POST['order_no_prefix'] ?? 'JO'))) ?: 'JO',
            'issue_no_prefix' => strtoupper(trim((string) ($_POST['issue_no_prefix'] ?? 'JI'))) ?: 'JI',
            'refinery_no_prefix' => strtoupper(trim((string) ($_POST['refinery_no_prefix'] ?? 'JR'))) ?: 'JR',
        ], $userId);
        log_activity('company', $companyId, 'jewellery_settings', 'Jewellery Accounting settings updated.', $userId);
        flash('success', 'Settings saved.');
        redirect($back);
    }

    if ($action === 'save_category') {
        require_permission('jewellery', 'edit');
        try {
            jewellery_save_category($companyId, [
                'id' => (int) ($_POST['category_id'] ?? 0),
                'name' => (string) ($_POST['name'] ?? ''),
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                'active' => isset($_POST['active']) ? 1 : 0,
            ]);
            flash('success', 'Category saved.');
        } catch (Throwable $categoryException) {
            flash('error', $categoryException->getMessage());
        }
        redirect($back);
    }

    if ($action === 'delete_category') {
        require_permission('jewellery', 'edit');
        $removed = jewellery_delete_category($companyId, (int) ($_POST['category_id'] ?? 0));
        flash($removed['ok'] ? 'success' : 'error', $removed['ok'] ? 'Category removed.' : $removed['error']);
        redirect($back);
    }

    if ($action === 'delete_item') {
        require_permission('jewellery', 'edit');
        $result = jewellery_delete_item($companyId, (int) ($_POST['item_id'] ?? 0));
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Item deleted.' : $result['error']);
        redirect('admin/jewellery.php?view=items');
    }

    if ($action === 'save_item') {
        require_permission('jewellery', 'edit');
        try {
            jewellery_save_item($companyId, [
                'id' => (int) ($_POST['item_id'] ?? 0),
                'code' => (string) ($_POST['code'] ?? ''),
                'name' => (string) ($_POST['name'] ?? ''),
                'category' => (string) ($_POST['category'] ?? ''),
                'item_type' => (string) ($_POST['item_type'] ?? 'ornament'),
                'metal_id' => (int) ($_POST['metal_id'] ?? 0),
                'purity_id' => (int) ($_POST['purity_id'] ?? 0),
                'unit_id' => (int) ($_POST['unit_id'] ?? 0),
                'track_mode' => (string) ($_POST['track_mode'] ?? 'weight'),
                'stock_kind' => (string) ($_POST['stock_kind'] ?? 'showroom'),
                'design_no' => (string) ($_POST['design_no'] ?? ''),
                'hallmark' => (string) ($_POST['hallmark'] ?? ''),
                'gross_weight' => (float) ($_POST['gross_weight'] ?? 0),
                'stone_weight' => (float) ($_POST['stone_weight'] ?? 0),
                'wastage_pct' => (float) ($_POST['wastage_pct'] ?? 0),
                'making_charge_basis' => (string) ($_POST['making_charge_basis'] ?? 'default'),
                'making_charge_rate' => (float) ($_POST['making_charge_rate'] ?? 0),
                'stone_value' => (float) ($_POST['stone_value'] ?? 0),
                'reorder_weight' => (float) ($_POST['reorder_weight'] ?? 0),
                'vat_applicable' => isset($_POST['vat_applicable']) ? 1 : 0,
                'vat_base' => (string) ($_POST['vat_base'] ?? 'default'),
                'hs_code' => (string) ($_POST['hs_code'] ?? ''),
                'status' => isset($_POST['active']) ? 'active' : 'inactive',
                'notes' => (string) ($_POST['notes'] ?? ''),
            ], $userId);
            flash('success', 'Item saved.');
        } catch (Throwable $itemException) {
            flash('error', $itemException->getMessage());
        }
        redirect($back);
    }

    // Saving an opening posts it: it now writes the item's own opening_qty /
    // opening_amount and goes through the SHARED opening poster, which replaces
    // any prior voucher rather than adding one. There is no separate draft step
    // to keep in sync any more — correcting an opening is saving it again.
    if ($action === 'save_opening') {
        require_permission('jewellery', 'post');
        $result = jewellery_save_opening($companyId, $fiscalYearId, [
            'item_id' => (int) ($_POST['item_id'] ?? 0),
            'stock_kind' => (string) ($_POST['stock_kind'] ?? 'showroom'),
            'stock_group' => (string) ($_POST['stock_group'] ?? ''),
            'qty_pieces' => (float) ($_POST['qty_pieces'] ?? 0),
            'gross_weight' => (float) ($_POST['gross_weight'] ?? 0),
            'stone_carat' => (float) ($_POST['stone_carat'] ?? 0),
            'diamond_carat' => (float) ($_POST['diamond_carat'] ?? 0),
            'stone_amount' => (float) ($_POST['stone_amount'] ?? 0),
            'diamond_amount' => (float) ($_POST['diamond_amount'] ?? 0),
            'making_amount' => (float) ($_POST['making_amount'] ?? 0),
            'rate' => (float) ($_POST['rate'] ?? 0),
            'amount' => (float) ($_POST['amount'] ?? 0),
            'customer_name' => (string) ($_POST['customer_name'] ?? ''),
            'customer_order_no' => (string) ($_POST['order_number'] ?? ''),
        ], $userId);
        if (!$result['ok']) {
            flash('error', $result['error']);
        } elseif (($result['note'] ?? '') !== '') {
            // Weight recorded, but a ledger gap stopped the money leg.
            flash('error', $result['note']);
        } else {
            flash('success', 'Opening stock saved' . ($result['voucher_id'] > 0
                ? ' and posted (voucher #' . $result['voucher_id'] . ').'
                : ' — weight only, no value to post.'));
        }
        redirect($back);
    }

    if ($action === 'clear_opening') {
        require_permission('jewellery', 'post');
        $result = jewellery_clear_opening($companyId, (int) ($_POST['item_id'] ?? 0), $userId, $fiscalYearId);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Opening stock cleared — its voucher and metal movement were removed.'
            : $result['error']);
        redirect($back);
    }

    if ($action === 'clear_opening_bulk') {
        require_permission('jewellery', 'post');
        $itemIds = array_values(array_filter(array_map('intval', (array) ($_POST['item_ids'] ?? []))));
        if ($itemIds === []) {
            flash('error', 'No opening stock item was selected.');
            redirect($back);
        }

        $cleared = 0;
        $errors = [];
        foreach (array_unique($itemIds) as $itemId) {
            if ($itemId <= 0) {
                continue;
            }
            $result = jewellery_clear_opening($companyId, $itemId, $userId, $fiscalYearId);
            if ($result['ok']) {
                $cleared++;
            } else {
                $errors[] = 'Item #' . $itemId . ': ' . $result['error'];
            }
        }

        if ($cleared > 0) {
            flash('success', 'Cleared opening stock for ' . $cleared . ' selected item' . ($cleared === 1 ? '' : 's') . '.');
        }
        if ($errors !== []) {
            flash('error', implode(' ', $errors));
        }
        redirect($back);
    }

    if ($action === 'save_mapping') {
        require_permission('jewellery', 'edit');
        try {
            jewellery_save_mapping($companyId, (string) ($_POST['purpose'] ?? ''), (int) ($_POST['ledger_id'] ?? 0), $userId);
            flash('success', 'Posting ledger saved.');
        } catch (Throwable $mappingException) {
            flash('error', $mappingException->getMessage());
        }
        redirect($back);
    }

    // --- Opening stock from a spreadsheet -------------------------------
    // An upload posts NOTHING. It stages rows to be looked at, corrected and
    // then committed deliberately — opening balances are the hardest thing in
    // the books to unpick afterwards.
    if ($action === 'upload_opening') {
        require_permission('jewellery', 'edit');
        try {
            $file = $_FILES['opening_file'] ?? null;
            if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a .xlsx or .csv file to upload.');
            }
            $originalName = (string) ($file['name'] ?? 'sheet');
            $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, ['xlsx', 'csv'], true)) {
                throw new RuntimeException('Upload a .xlsx or .csv file. Save an .xls as .xlsx first.');
            }
            $staged = opening_import_stage($companyId, $fiscalYearId, (string) $file['tmp_name'],
                $extension, $originalName, 'jewellery', $userId);
            flash($staged['valid_count'] === $staged['row_count'] ? 'success' : 'info',
                $staged['row_count'] . ' row' . ($staged['row_count'] === 1 ? '' : 's') . ' read, '
                . $staged['valid_count'] . ' ready to import. Nothing has reached the books yet — check the '
                . 'preview below, fix or remove any row you need to, then commit.');
            redirect('admin/jewellery.php?view=opening&import=' . $staged['import_id']);
        } catch (Throwable $uploadException) {
            flash('error', $uploadException->getMessage());
        }
        redirect($back);
    }

    if ($action === 'update_import_row') {
        require_permission('jewellery', 'edit');
        $result = opening_import_update_row($companyId, (int) ($_POST['row_id'] ?? 0), [
            'item_id' => (int) ($_POST['item_id'] ?? 0),
            'stock_kind' => (string) ($_POST['stock_kind'] ?? ''),
            'raw_group' => trim((string) ($_POST['raw_group'] ?? '')),
            'proposed_code' => trim((string) ($_POST['proposed_code'] ?? '')),
            'proposed_name' => trim((string) ($_POST['proposed_name'] ?? '')),
            'metal_id' => (int) ($_POST['metal_id'] ?? 0),
            'purity_id' => (int) ($_POST['purity_id'] ?? 0),
            'unit_id' => (int) ($_POST['unit_id'] ?? 0),
            'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
            'order_number' => trim((string) ($_POST['order_number'] ?? '')),
            'qty_pieces' => (float) ($_POST['qty_pieces'] ?? 0),
            'gross_weight' => (float) ($_POST['gross_weight'] ?? 0),
            'rate' => (float) ($_POST['rate'] ?? 0),
            'amount' => (float) ($_POST['amount'] ?? 0),
        ]);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ('Row updated' . (($result['status'] ?? '') === 'ready' ? ' and now ready to import.' : ' — it still has a problem.'))
            : $result['error']);
        redirect('admin/jewellery.php?view=opening&import=' . (int) ($_POST['import_id'] ?? 0));
    }

    if ($action === 'delete_import_row') {
        require_permission('jewellery', 'edit');
        $result = opening_import_delete_row($companyId, (int) ($_POST['row_id'] ?? 0));
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Row removed from the import.' : $result['error']);
        redirect('admin/jewellery.php?view=opening&import=' . (int) ($_POST['import_id'] ?? 0));
    }

    if ($action === 'discard_import') {
        require_permission('jewellery', 'edit');
        $result = opening_import_discard($companyId, (int) ($_POST['import_id'] ?? 0));
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Import discarded. Nothing had reached the books, so nothing was reversed.' : $result['error']);
        redirect('admin/jewellery.php?view=opening');
    }

    if ($action === 'commit_import') {
        require_permission('jewellery', 'edit');
        $importId = (int) ($_POST['import_id'] ?? 0);
        $result = opening_import_commit($companyId, $importId, $fiscalYearId, $userId);
        if ($result['ok']) {
            $message = $result['committed'] . ' opening row' . ($result['committed'] === 1 ? '' : 's') . ' committed.';
            if ($result['failures'] !== []) {
                $message .= ' ' . count($result['failures']) . ' could not be: '
                    . implode(' ', array_slice($result['failures'], 0, 3))
                    . (count($result['failures']) > 3 ? ' …' : '')
                    . ' They are still in the import — fix them and commit again.';
            }
            flash($result['failures'] === [] ? 'success' : 'info', $message);
        } else {
            flash('error', $result['error']);
        }
        redirect('admin/jewellery.php?view=opening&import=' . $importId);
    }

    if ($action === 'autocreate_mappings') {
        require_permission('jewellery', 'edit');
        $result = jewellery_autocreate_mappings($companyId, $userId);
        $parts = [];
        if ($result['created'] !== []) {
            $parts[] = count($result['created']) . ' ledger' . (count($result['created']) === 1 ? '' : 's') . ' opened';
        }
        if ($result['mapped'] !== []) {
            $parts[] = count($result['mapped']) . ' mapped';
        }
        if ($result['skipped'] !== []) {
            $parts[] = count($result['skipped']) . ' already set and left alone';
        }
        if ($result['errors'] !== []) {
            flash('error', 'Some purposes could not be set up: ' . implode(' ', $result['errors']));
        }
        flash($parts === [] ? 'info' : 'success', $parts === []
            ? 'Every posting purpose was already mapped — nothing to do.'
            : 'Posting ledgers: ' . implode(', ', $parts) . '.');
        redirect($back);
    }

    if ($action === 'save_tax') {
        require_permission('jewellery', 'edit');
        try {
            jewellery_save_tax($companyId, [
                'id' => (int) ($_POST['tax_id'] ?? 0),
                'code' => $_POST['code'] ?? '',
                'name' => $_POST['name'] ?? '',
                'rate' => $_POST['rate'] ?? 0,
                'base' => $_POST['base'] ?? 'subtotal',
                'applies_to' => $_POST['applies_to'] ?? 'all',
                'doc_types' => (array) ($_POST['doc_types'] ?? ['sale']),
                'sequence' => $_POST['sequence'] ?? 100,
                'manual_entry' => !empty($_POST['manual_entry']),
                'output_purpose' => $_POST['output_purpose'] ?? 'spt_output',
                'input_purpose' => $_POST['input_purpose'] ?? 'spt_input',
                'effective_from' => $_POST['effective_from'] ?? '',
                'effective_to' => $_POST['effective_to'] ?? '',
                'active' => !empty($_POST['active']),
                'notes' => $_POST['notes'] ?? '',
            ], $userId);
            flash('success', 'Tax saved.');
        } catch (Throwable $taxException) {
            flash('error', $taxException->getMessage());
        }
        redirect($back);
    }

    if ($action === 'delete_tax') {
        require_permission('jewellery', 'edit');
        $result = jewellery_delete_tax($companyId, (int) ($_POST['tax_id'] ?? 0));
        flash($result['ok'] ? 'success' : 'error',
            $result['ok'] ? ($result['note'] ?: 'Tax removed.') : $result['error']);
        redirect($back);
    }

    redirect($back);
}

// A template carrying the exact column names the reader looks for, so nobody
// has to reverse-engineer them from an error message.
if (($_GET['template'] ?? '') !== '' && $view === 'opening') {
    require_once __DIR__ . '/../../app/export_engine.php';
    $templateRows = opening_import_template_rows(true);
    if ((string) $_GET['template'] === 'csv') {
        export_csv('opening-stock-template.csv', $templateRows);
    }
        export_xlsx('opening-stock-template-v4.xlsx', $templateRows, 'Opening Stock',
        [0 => 7, 1 => 20, 2 => 18, 3 => 15, 4 => 22, 5 => 12, 6 => 10, 7 => 9,
            8 => 10, 9 => 15, 10 => 14, 11 => 16, 12 => 22, 13 => 18],
        ['styled_table' => true, 'freeze_header' => true, 'auto_filter' => true]);
}

// ---------------------------------------------------------------------------
// Page data
// ---------------------------------------------------------------------------
$units = jewellery_units_list($companyId, false);
$metals = jewellery_metals_list($companyId, false);
$purities = jewellery_purities_list($companyId, 0, false);
$baseUnit = jewellery_base_unit($companyId);
$rateDate = $clampDate((string) ($_GET['date'] ?? date('Y-m-d')));
$rateTypes = jewellery_rate_types();
$mappingPurposes = jewellery_mapping_purposes();
$mappings = jewellery_mappings_by_purpose($companyId);

// Purities grouped by metal, so the rate form can filter client-side without
// a round trip.
$puritiesByMetal = [];
foreach ($purities as $purityRow) {
    $puritiesByMetal[(int) $purityRow['metal_id']][] = $purityRow;
}

$editUnit = null;
$editMetal = null;
$editPurity = null;
$editCategory = null;
$categories = [];
if ($view === 'masters') {
    $editUnit = jewellery_unit($companyId, (int) ($_GET['edit_unit'] ?? 0));
    $editMetal = jewellery_metal($companyId, (int) ($_GET['edit_metal'] ?? 0));
    $editPurity = jewellery_purity($companyId, (int) ($_GET['edit_purity'] ?? 0));
    $editCategory = jewellery_category($companyId, (int) ($_GET['edit_category'] ?? 0));
    // Retired categories still show here — this is where they are switched back
    // on, so hiding them would make that impossible.
    $categories = jewellery_categories_list($companyId, false);
    // How many items each category actually holds. Counted from the database
    // rather than from $items, which this view never loads — a count taken from
    // an empty list would read zero and offer Delete on a category in use.
    $useStmt = db()->prepare("SELECT i.category, COUNT(*) AS n FROM inventory_items i
        INNER JOIN jewellery_item_profiles j ON j.inventory_item_id = i.id
        WHERE i.company_id = :cid AND i.category IS NOT NULL AND i.category <> ''
        GROUP BY i.category");
    $useStmt->execute(['cid' => $companyId]);
    $categoryUse = [];
    foreach ($useStmt->fetchAll(PDO::FETCH_ASSOC) as $useRow) {
        $categoryUse[(string) $useRow['category']] = (int) $useRow['n'];
    }
}

$items = [];
$editItem = null;
$openingRows = [];
$importBatch = null;
$importRows = [];
$stockRows = [];
$position = [];
$itemLedger = null;
$ledgerItem = null;
if (in_array($view, ['items', 'opening', 'stock'], true)) {
    $items = jewellery_items_list($companyId, ['search' => (string) ($_GET['q'] ?? '')]);
}
if ($view === 'items') {
    $editItem = jewellery_item($companyId, (int) ($_GET['edit'] ?? 0));
}
if ($view === 'opening') {
    $openingRows = jewellery_opening_rows($companyId, $fiscalYearId);
    // The batch being previewed: the one named in the URL, else whatever is
    // still waiting to be dealt with, so a half-finished import is never lost
    // behind a navigation.
    $importBatch = opening_import_batch($companyId, (int) ($_GET['import'] ?? 0))
        ?: opening_import_latest_staged($companyId, 'jewellery');
    $importRows = $importBatch ? opening_import_rows($companyId, (int) $importBatch['id']) : [];
}
if ($view === 'stock') {
    $position = jewellery_metal_position($companyId, $todayInFy);
    $stockRows = jewellery_stock_valuation($companyId, $todayInFy);
    $ledgerItem = jewellery_item($companyId, (int) ($_GET['item'] ?? 0));
    if ($ledgerItem) {
        $itemLedger = jewellery_stock_ledger($companyId, (int) $ledgerItem['id'], $fyStart, $todayInFy);
    }
}

// The dashboard checklist. Derived from the books every time it is drawn, so it
// cannot drift out of date the way a hand-written feature list does — and each
// unfinished step is one that will refuse a document later, named up front
// instead of surfacing as an error mid-transaction.
$setupChecklist = [];
if ($view === 'dashboard') {
    $countOf = static function (string $sql) use ($companyId): int {
        $stmt = db()->prepare($sql);
        $stmt->execute(['cid' => $companyId]);

        return (int) $stmt->fetchColumn();
    };

    $ledgerGaps = 0;
    foreach (jewellery_standard_ledger_plan() as $purpose => $plan) {
        if (!isset($mappings[$purpose])) {
            $ledgerGaps++;
        }
    }
    $itemCount = $countOf('SELECT COUNT(*) FROM jewellery_item_profiles p
        INNER JOIN inventory_items i ON i.id = p.inventory_item_id WHERE i.company_id = :cid');
    $rateCount = $countOf('SELECT COUNT(*) FROM jewellery_daily_rates WHERE company_id = :cid');
    $taxCount = table_exists('jewellery_taxes')
        ? $countOf('SELECT COUNT(*) FROM jewellery_taxes WHERE company_id = :cid AND active = 1') : 0;
    $openingCount = $countOf("SELECT COUNT(*) FROM jewellery_stock_txns
        WHERE company_id = :cid AND txn_type = 'opening'");
    $karigarCount = $countOf('SELECT COUNT(*) FROM jewellery_karigars WHERE company_id = :cid');
    // Two placeholders, two names: PDO without emulation binds by position, so
    // reusing :cid in one statement is an "Invalid parameter number", not a
    // convenience.
    $docStmt = db()->prepare('SELECT (SELECT COUNT(*) FROM jewellery_sales WHERE company_id = :cid1)
        + (SELECT COUNT(*) FROM jewellery_purchases WHERE company_id = :cid2)');
    $docStmt->execute(['cid1' => $companyId, 'cid2' => $companyId]);
    $docCount = (int) $docStmt->fetchColumn();

    $setupChecklist = [
        ['label' => 'Metals, purities and weight units', 'done' => true, 'blocking' => true, 'link' => '',
            'note' => ''],
        ['label' => 'Posting ledgers mapped', 'done' => $ledgerGaps === 0, 'blocking' => true,
            'link' => 'admin/jewellery.php?view=settings',
            'note' => $ledgerGaps === 0
                ? ''
                : $ledgerGaps . ' unmapped'],
        ['label' => 'Taxes', 'done' => $taxCount > 0, 'blocking' => true,
            'link' => 'admin/jewellery.php?view=settings',
            'note' => $taxCount > 0
                ? $taxCount . ' active'
                : 'No tax is being charged.'],
        ['label' => 'A daily rate quoted', 'done' => $rateCount > 0, 'blocking' => true,
            'link' => 'admin/jewellery.php?view=rates',
            'note' => $rateCount > 0
                ? $rateCount . ' quote' . ($rateCount === 1 ? '' : 's') . ' on the board'
                : 'A line left at rate 0 needs a quote to price against.'],
        ['label' => 'Items created', 'done' => $itemCount > 0, 'blocking' => true,
            'link' => 'admin/jewellery.php?view=items',
            'note' => $itemCount > 0 ? $itemCount . ' item' . ($itemCount === 1 ? '' : 's') . ' on the master' : 'Nothing can be bought, sold or issued yet.'],
        ['label' => 'Opening stock entered', 'done' => $openingCount > 0, 'blocking' => false,
            'link' => 'admin/jewellery.php?view=opening',
            'note' => $openingCount > 0
                ? $openingCount . ' opening line' . ($openingCount === 1 ? '' : 's') . ' recorded'
                : 'Only if carrying stock in from before this year.'],
        ['label' => 'Kaligads added', 'done' => $karigarCount > 0, 'blocking' => false,
            'link' => 'admin/jewellery-workshop.php?view=karigars',
            'note' => $karigarCount > 0
                ? $karigarCount . ' on the register'
                : 'Only if you issue metal out for making.'],
        ['label' => 'First document posted', 'done' => $docCount > 0, 'blocking' => false,
            'link' => 'admin/jewellery-trade.php?view=purchases',
            'note' => $docCount > 0
                ? $docCount . ' so far'
                : 'Start with a purchase.'],
    ];
}

$ledgerOptions = [];
$taxRows = [];
$editTax = null;
$taxBases = jewellery_tax_bases();
$mappingGaps = [];
if ($view === 'settings') {
    if (table_exists('ledgers')) {
        $ledgerStmt = db()->prepare('SELECT id, code, name FROM ledgers WHERE company_id = :cid ORDER BY code ASC, name ASC');
        $ledgerStmt->execute(['cid' => $companyId]);
        $ledgerOptions = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $taxRows = jewellery_taxes_list($companyId, '', '', false);
    $editTax = jewellery_tax($companyId, (int) ($_GET['edit_tax'] ?? 0));
    // Which purposes the one-click setup would still have to open a ledger for.
    foreach (jewellery_standard_ledger_plan() as $purpose => $plan) {
        if (!isset($mappings[$purpose])) {
            $mappingGaps[] = (string) ($mappingPurposes[$purpose][0] ?? $purpose);
        }
    }
}

$pageTitle = 'Jewellery Accounting';
$pageSubtitle = 'Stock by weight and value, daily metal rates, karigar orders, refinery jobs and automated posting.';
$pageHero = ['icon' => 'coins'];
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';

$fmt = static fn (?float $n, int $p = 2): string => $n === null ? 'N/A' : number_format($n, $p);
?>

<nav class="mbw-tabbar" aria-label="Jewellery sections" style="flex-wrap:wrap">
    <?php foreach ([
        'dashboard' => ['Dashboard', 'dashboard'],
        'rates' => ['Daily Rates', 'pie'],
        'items' => ['Items', 'box'],
        'opening' => ['Opening Stock', 'journal'],
        'stock' => ['Stock &amp; Metal Position', 'layers'],
        'masters' => ['Metals &amp; Units', 'scale'],
        'settings' => ['Settings', 'sliders'],
    ] as $tabView => [$tabLabel, $tabIcon]): ?>
        <a class="mbw-tab <?= $view === $tabView ? 'is-active' : '' ?>" href="<?= e(url('admin/jewellery.php?view=' . $tabView)) ?>"><?= icon($tabIcon) ?> <?= $tabLabel ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($view === 'dashboard'): ?>
    <?php
    $todayRates = jewellery_rates_for_date($companyId, $todayInFy);
    $activeMetals = array_values(array_filter($metals, static fn (array $m): bool => (int) $m['active'] === 1));
    $activePurities = array_values(array_filter($purities, static fn (array $p): bool => (int) $p['active'] === 1));
    // Posting readiness: the purposes Phase 2+ entries cannot post without.
    $corePurposes = ['stock_metal', 'stock_finished', 'sales_metal', 'sales_making', 'purchase_clearing', 'cogs', 'vat_output'];
    $missingCore = jewellery_missing_mappings($companyId, $corePurposes);
    ?>
    <section class="mbw-kpi-grid" aria-label="Jewellery summary">
        <?php foreach ([
            ['Metals tracked', (string) count($activeMetals), 'coins', 'tone-blue'],
            ['Purity grades', (string) count($activePurities), 'layers', 'tone-teal'],
            ['Weight units', (string) count(array_filter($units, static fn (array $u): bool => (int) $u['active'] === 1)), 'scale', 'tone-gray'],
            ['Rates quoted today', (string) count($todayRates), 'pie', count($todayRates) > 0 ? 'tone-green' : 'tone-amber'],
            ['Reporting unit', (string) ($baseUnit['name'] ?? 'Not set'), 'scale', 'tone-blue'],
            ['Posting ledgers set', (count($corePurposes) - count($missingCore)) . ' / ' . count($corePurposes), 'accounting', $missingCore === [] ? 'tone-green' : 'tone-amber'],
        ] as [$kpiLabel, $kpiValue, $kpiIcon, $kpiTone]): ?>
            <article class="mbw-kpi"><div><span class="mbw-kpi-label"><?= e($kpiLabel) ?></span><div class="mbw-kpi-value" style="font-size:1.02rem"><?= e($kpiValue) ?></div></div><span class="mbw-chip <?= e($kpiTone) ?>"><?= icon($kpiIcon) ?></span></article>
        <?php endforeach; ?>
    </section>

    <?php if ($missingCore !== []): ?>
        <div class="notice" style="margin:14px 0">
            <strong><?= count($missingCore) ?> posting ledger<?= count($missingCore) === 1 ? '' : 's' ?> unmapped.</strong>
            <a href="<?= e(url('admin/jewellery.php?view=settings')) ?>">Set posting ledgers →</a>
        </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px">
        <section class="mbw-card">
            <div class="mbw-card-head"><h2>Rate Board — <?= e(app_date($todayInFy)) ?></h2><a class="mbw-view-all" href="<?= e(url('admin/jewellery.php?view=rates')) ?>">Manage rates →</a></div>
            <div style="overflow-x:auto"><table>
                <thead><tr><th>Metal</th><th>Purity</th><th>Type</th><th class="is-numeric">Rate</th><th>Per</th></tr></thead>
                <tbody>
                    <?php if ($todayRates === []): ?>
                        <tr><td colspan="5">No rate quoted for today yet. Transactions will fall back to the most recent earlier rate.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($todayRates as $row): ?>
                        <tr>
                            <td><?= e($row['metal_name']) ?></td>
                            <td><?= e($row['purity_code']) ?> <small>(<?= $fmt((float) $row['fineness'], 1) ?>)</small></td>
                            <td><?= e($rateTypes[$row['rate_type']] ?? $row['rate_type']) ?></td>
                            <td class="is-numeric"><?= e($sym) ?><?= $fmt((float) $row['rate']) ?></td>
                            <td><?= e($row['unit_code']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>

        <section class="mbw-card">
            <div class="mbw-card-head"><h2>Setup Checklist</h2></div>
            <div style="overflow-x:auto"><table>
                <thead><tr><th>Step</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($setupChecklist as $step): ?>
                        <tr>
                            <td><?= e($step['label']) ?>
                                <?php if ($step['note'] !== ''): ?>
                                    <div class="frm-optional"><?= e($step['note']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="mbw-pill <?= $step['done'] ? 'tone-green' : ($step['blocking'] ? 'tone-amber' : 'tone-gray') ?>">
                                <?= $step['done'] ? 'Done' : ($step['blocking'] ? 'Needed' : 'Optional') ?>
                            </span></td>
                            <td><?php if (!$step['done'] && $step['link'] !== ''): ?>
                                <a class="button secondary" style="min-height:30px;padding:3px 10px" href="<?= e(url($step['link'])) ?>">Set up</a>
                            <?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
    </div>

<?php elseif ($view === 'rates'): ?>
    <?php
    $rows = jewellery_rates_for_date($companyId, $rateDate);
    $defaultMetalId = (int) ($settings['default_metal_id'] ?? 0);
    ?>
    <?php if ($canEdit): ?>
    <section class="mbw-card" data-collapsible data-draggable>
        <div class="mbw-card-head"><h2>Quote a Rate</h2></div>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_rate">
            <input type="hidden" name="back_view" value="rates">
            <label>Date<input type="date" name="rate_date" value="<?= e($rateDate) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>" required></label>
            <label>Metal
                <select name="metal_id" id="jw-metal" required>
                    <?php foreach ($metals as $m): ?>
                        <?php if ((int) $m['active'] !== 1) { continue; } ?>
                        <option value="<?= (int) $m['id'] ?>" <?= (int) $m['id'] === $defaultMetalId ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Purity
                <select name="purity_id" id="jw-purity" required>
                    <?php foreach ($purities as $p): ?>
                        <?php if ((int) $p['active'] !== 1) { continue; } ?>
                        <option value="<?= (int) $p['id'] ?>" data-metal="<?= (int) $p['metal_id'] ?>"><?= e($p['metal_code'] . ' · ' . $p['code'] . ' (' . rtrim(rtrim(number_format((float) $p['fineness'], 4, '.', ''), '0'), '.') . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Rate type
                <select name="rate_type">
                    <?php foreach ($rateTypes as $typeKey => $typeLabel): ?>
                        <option value="<?= e($typeKey) ?>"><?= e($typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Rate (<?= e($sym) ?>)<input type="number" name="rate" step="0.0001" min="0" value="0" required></label>
            <label>Per unit
                <select name="unit_id" required>
                    <?php foreach ($units as $u): ?>
                        <?php if ((int) $u['active'] !== 1) { continue; } ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) ($baseUnit['id'] ?? 0) ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="grid-column:1/-1">Note<input type="text" name="note" maxlength="190" placeholder="Optional — e.g. FENEGOSIDA published rate"></label>
            <div style="grid-column:1/-1"><button type="submit" class="button">Save Rate</button></div>
        </form>
    </section>
    <?php endif; ?>

    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head">
            <h2>Rates on <?= e(app_date($rateDate)) ?> (<?= count($rows) ?>)</h2>
            <form method="get" style="display:flex;gap:6px;align-items:center">
                <input type="hidden" name="view" value="rates">
                <input type="date" name="date" value="<?= e($rateDate) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>">
                <button type="submit" class="button secondary" style="min-height:32px;padding:4px 10px">Show</button>
            </form>
        </div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Metal</th><th>Purity</th><th>Type</th><th class="is-numeric">Rate</th><th>Per</th><th class="is-numeric">Per unit of pure</th><th>Note</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
                <?php if ($rows === []): ?><tr><td colspan="<?= $canEdit ? 8 : 7 ?>">No rates quoted on this date.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['metal_name']) ?></td>
                        <td><?= e($row['purity_code']) ?> <small>(<?= $fmt((float) $row['fineness'], 1) ?>)</small></td>
                        <td><?= e($rateTypes[$row['rate_type']] ?? $row['rate_type']) ?></td>
                        <td class="is-numeric"><?= e($sym) ?><?= $fmt((float) $row['rate']) ?></td>
                        <td><?= e($row['unit_code']) ?></td>
                        <td class="is-numeric"><?= e($sym) ?><?= $fmt(jw_fine_rate((float) $row['rate'], (float) $row['fineness'])) ?></td>
                        <td><?= e((string) ($row['note'] ?? '')) ?></td>
                        <?php if ($canEdit): ?>
                        <td>
                            <form method="post" data-confirm="Remove this rate?">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_rate">
                                <input type="hidden" name="back_view" value="rates">
                                <input type="hidden" name="rate_id" value="<?= (int) $row['id'] ?>">
                                <input type="hidden" name="rate_date" value="<?= e($rateDate) ?>">
                                <button type="submit" class="button soft" style="min-height:30px;padding:3px 10px">Remove</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <script>
        (function () {
            var table = document.querySelector('table');
            if (!table) { return; }
            function applyFilters() {
                var inputs = Array.from(table.querySelectorAll('thead .jw-filter-row input[type="search"]'));
                var rows = Array.from(table.querySelectorAll('tbody tr'));
                rows.forEach(function (row) {
                    var cells = Array.from(row.querySelectorAll('td'));
                    var show = true;
                    inputs.forEach(function (inp) {
                        var col = parseInt(inp.dataset.colIndex || inp.getAttribute('data-col') || inp.dataset.col, 10);
                        if (Number.isNaN(col)) { return; }
                        var cell = cells[col];
                        var text = cell ? cell.innerText.toLowerCase() : '';
                        if (inp.value && text.indexOf(inp.value.toLowerCase()) === -1) {
                            show = false;
                        }
                    });
                    row.style.display = show ? '' : 'none';
                });
            }
            window.jwApplyFilters = applyFilters;
            var inputs = Array.from(table.querySelectorAll('thead .jw-filter-row input[type="search"]'));
            inputs.forEach(function (inp) {
                if (!inp.dataset.colIndex) {
                    if (inp.dataset.col) inp.dataset.colIndex = inp.dataset.col;
                    else if (inp.getAttribute('data-col')) inp.dataset.colIndex = inp.getAttribute('data-col');
                }
                inp.addEventListener('input', applyFilters);
            });
        })();
        </script>
    </section>

    <script>
    // Simple client-side filters for the Opening Stock table
    (function () {
        function attachFilters() {
            // Find the Opening Stock section and its table specifically
            var openingSection = Array.from(document.querySelectorAll('.mbw-card-head h2')).
                map(function (h) { return {h:h, txt: (h.innerText||'').trim()}; }).
                find(function (o) { return o.txt && o.txt.indexOf('Opening Stock') === 0; });
            var table = null;
            if (openingSection && openingSection.h) {
                var section = openingSection.h.closest('section');
                if (section) { table = section.querySelector('table'); }
            }
            if (!table) { return; }
            var inputs = Array.from(table.querySelectorAll('thead .jw-filter-row input[type="search"]'));
            if (!inputs.length) { return; }
            function applyFilters() {
                var rows = Array.from(table.querySelectorAll('tbody tr'));
                rows.forEach(function (row) {
                    var cells = Array.from(row.querySelectorAll('td'));
                    var show = true;
                    inputs.forEach(function (inp) {
                        var col = parseInt(inp.dataset.colIndex || inp.getAttribute('data-col') || inp.dataset.col, 10);
                        if (Number.isNaN(col)) { return; }
                        var cell = cells[col];
                        var text = cell ? cell.innerText.toLowerCase() : '';
                        if (inp.value && text.indexOf(inp.value.toLowerCase()) === -1) { show = false; }
                    });
                    row.style.display = show ? '' : 'none';
                });
            }
            inputs.forEach(function (inp) {
                if (!inp.dataset.colIndex) {
                    if (inp.dataset.col) inp.dataset.colIndex = inp.dataset.col;
                    else if (inp.getAttribute('data-col')) inp.dataset.colIndex = inp.getAttribute('data-col');
                }
                inp.addEventListener('input', applyFilters);
            });
            window.jwApplyFilters = applyFilters;
        }
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', attachFilters); } else { attachFilters(); }
    })();

    </script>
    // Keep the purity list to the chosen metal — a purity from another metal
    // is rejected server-side, so filtering here just avoids a pointless trip.
    (function () {
        var metal = document.getElementById('jw-metal');
        var purity = document.getElementById('jw-purity');
        if (!metal || !purity) { return; }
        var all = Array.prototype.slice.call(purity.options);
        function sync() {
            var chosen = metal.value;
            purity.innerHTML = '';
            all.forEach(function (opt) {
                if (opt.getAttribute('data-metal') === chosen) { purity.appendChild(opt); }
            });
        }
        metal.addEventListener('change', sync);
        sync();
    })();
    </script>

<?php elseif ($view === 'items'): ?>
    <?php $itemCategories = jewellery_categories_list($companyId); ?>
    <?php if ($canEdit): ?>
    <section class="mbw-card" data-collapsible data-draggable>
        <div class="mbw-card-head">
            <h2><?= $editItem ? 'Edit Item — ' . e((string) $editItem['code']) : 'Add Item' ?></h2>
            <?php if ($editItem): ?><a class="mbw-view-all" href="<?= e(url('admin/jewellery.php?view=items')) ?>">Cancel</a><?php endif; ?>
        </div>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_item">
            <input type="hidden" name="back_view" value="items">
            <input type="hidden" name="item_id" value="<?= (int) ($editItem['id'] ?? 0) ?>">
            <label>Code<input type="text" name="code" maxlength="60" value="<?= e((string) ($editItem['code'] ?? '')) ?>" required></label>
            <label>Name<input type="text" name="name" maxlength="190" value="<?= e((string) ($editItem['name'] ?? '')) ?>" required></label>
            <label>Category
                <select name="category">
                    <option value="">— none —</option>
                    <?php
                        // The master, plus whatever this item is already filed
                        // under. A category retired from the master must still
                        // show on the items that carry it, or editing anything
                        // else about the item would silently unfile it.
                        $itemCategory = trim((string) ($editItem['category'] ?? ''));
                        $categoryChoices = array_column($itemCategories, 'name');
                        if ($itemCategory !== '' && !in_array($itemCategory, $categoryChoices, true)) {
                            $categoryChoices[] = $itemCategory;
                        }
                    ?>
                    <?php foreach ($categoryChoices as $cat): ?>
                        <option value="<?= e((string) $cat) ?>" <?= $itemCategory === (string) $cat ? 'selected' : '' ?>><?= e((string) $cat) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($categoryChoices === []): ?>
                <?php endif; ?>
            </label>
            <label>Type
                <select name="item_type">
                    <?php foreach (['ornament' => 'Ornament', 'bullion' => 'Bullion / raw metal', 'stone' => 'Stone', 'other' => 'Other'] as $typeKey => $typeLabel): ?>
                        <option value="<?= e($typeKey) ?>" <?= (string) ($editItem['item_type'] ?? 'ornament') === $typeKey ? 'selected' : '' ?>><?= e($typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Default stock type
                <select name="stock_kind" required>
                    <option value="showroom" <?= (string) ($editItem['stock_kind'] ?? 'showroom') === 'showroom' ? 'selected' : '' ?>>Showroom Stock</option>
                    <option value="customer_ordered" <?= (string) ($editItem['stock_kind'] ?? '') === 'customer_ordered' ? 'selected' : '' ?>>Customer Ordered Stock</option>
                </select>
            </label>
            <label>Metal
                <select name="metal_id" id="jw-item-metal" required>
                    <?php foreach ($metals as $m): ?>
                        <?php if ((int) $m['active'] !== 1) { continue; } ?>
                        <option value="<?= (int) $m['id'] ?>" <?= (int) ($editItem['metal_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Purity
                <select name="purity_id" id="jw-item-purity" required>
                    <?php foreach ($purities as $p): ?>
                        <?php if ((int) $p['active'] !== 1) { continue; } ?>
                        <option value="<?= (int) $p['id'] ?>" data-metal="<?= (int) $p['metal_id'] ?>" <?= (int) ($editItem['purity_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['metal_code'] . ' · ' . $p['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Weight unit
                <select name="unit_id" required>
                    <?php foreach ($units as $u): ?>
                        <?php if ((int) $u['active'] !== 1) { continue; } ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) ($editItem['unit_id'] ?? (int) ($baseUnit['id'] ?? 0)) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Track by
                <select name="track_mode">
                    <option value="weight" <?= (string) ($editItem['track_mode'] ?? 'weight') === 'weight' ? 'selected' : '' ?>>Weight</option>
                    <option value="piece" <?= (string) ($editItem['track_mode'] ?? '') === 'piece' ? 'selected' : '' ?>>Piece</option>
                </select>
            </label>
            <label>Design / reference no.<input type="text" name="design_no" maxlength="100" value="<?= e((string) ($editItem['design_no'] ?? '')) ?>"></label>
            <label>Hallmark<input type="text" name="hallmark" maxlength="100" value="<?= e((string) ($editItem['hallmark'] ?? '')) ?>"></label>
            <label>Reference gross weight<input type="number" name="gross_weight" min="0" step="0.0001" value="<?= e((string) ($editItem['gross_weight'] ?? '0')) ?>"></label>
            <label>Reference stone weight<input type="number" name="stone_weight" min="0" step="0.0001" value="<?= e((string) ($editItem['stone_weight'] ?? '0')) ?>"></label>
            <label>Default wastage %<input type="number" name="wastage_pct" min="0" max="99.999" step="0.001" value="<?= e((string) ($editItem['wastage_pct'] ?? '0')) ?>"></label>
            <label>Making basis
                <select name="making_charge_basis">
                    <?php foreach (['default' => 'Company default', 'per_unit_weight' => 'Per unit weight', 'percent_of_metal' => 'Percent of metal', 'flat' => 'Flat'] as $mkKey => $mkLabel): ?>
                        <option value="<?= e($mkKey) ?>" <?= (string) ($editItem['making_charge_basis'] ?? 'default') === $mkKey ? 'selected' : '' ?>><?= e($mkLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Default making rate<input type="number" name="making_charge_rate" min="0" step="0.0001" value="<?= e((string) ($editItem['making_charge_rate'] ?? '0')) ?>"></label>
            <label>Default stone value<input type="number" name="stone_value" min="0" step="0.01" value="<?= e((string) ($editItem['stone_value'] ?? '0')) ?>"></label>
            <label>Reorder weight<input type="number" name="reorder_weight" min="0" step="0.0001" value="<?= e((string) ($editItem['reorder_weight'] ?? '0')) ?>"></label>
            <label>VAT base
                <select name="vat_base">
                    <?php foreach (['default' => 'Use company default', 'full_value' => 'Full line value', 'making_only' => 'Making charge only', 'stone_only' => 'Stone value only'] as $vbKey => $vbLabel): ?>
                        <option value="<?= e($vbKey) ?>" <?= (string) ($editItem['vat_base'] ?? 'default') === $vbKey ? 'selected' : '' ?>><?= e($vbLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>HS code<input type="text" name="hs_code" maxlength="40" value="<?= e((string) ($editItem['hs_code'] ?? '')) ?>"></label>
            <label class="frm-check"><input type="checkbox" name="vat_applicable" <?= (int) ($editItem['vat_applicable'] ?? 0) === 1 ? 'checked' : '' ?>> VAT applicable</label>
            <label class="frm-check"><input type="checkbox" name="active" <?= $editItem === null || (string) $editItem['status'] === 'active' ? 'checked' : '' ?>> Active</label>
            <label style="grid-column:1/-1">Notes<input type="text" name="notes" maxlength="255" value="<?= e((string) ($editItem['notes'] ?? '')) ?>"></label>
            <p class="frm-optional" style="grid-column:1/-1;margin:0">This creates the item/style master. Physical trace IDs are created when opening stock is imported, a purchase is posted, or a stock/customer order is assigned.</p>
            <div style="grid-column:1/-1"><button type="submit" class="button"><?= $editItem ? 'Update Item' : 'Create New Item' ?></button></div>
        </form>
    </section>
    <?php endif; ?>

    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head">
            <h2>Items (<?= count($items) ?>)</h2>
            <form method="get" style="display:flex;gap:6px;align-items:center">
                <input type="hidden" name="view" value="items">
                <input type="search" name="q" value="<?= e((string) ($_GET['q'] ?? '')) ?>" placeholder="Code, name or design no.">
                <button type="submit" class="button secondary" style="min-height:32px;padding:4px 10px">Search</button>
            </form>
        </div>
        <div style="overflow-x:auto"><table>
            <?php
                // GROUPED BY ITEM GROUP, because that is how a shop thinks of
                // its stock: "chain" is the thing, and under it sit the 22K
                // chain and the 24K chain as separate items that must each be
                // traced on their own. The group was already being stored (the
                // category master, migration 086) and already steers the ledger
                // mapping — item first, then its group, then the company-wide
                // purpose — but the list only whispered it as a subtitle under
                // the name, so nobody could see the shape of their own stock.
                //
                // Sorted here rather than in SQL: the list arrives filtered and
                // searched, and re-sorting it in PHP keeps every one of those
                // paths working without a second query.
                $grouped = [];
                foreach ($items as $groupRow) {
                    $groupName = trim((string) ($groupRow['category'] ?? ''));
                    $grouped[$groupName === '' ? '\u{2014} Ungrouped' : $groupName][] = $groupRow;
                }
                ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
                $serial = 0;
            ?>
            <thead><tr><th class="is-numeric" style="width:44px">SN</th><th>Item group</th><th>Item name</th><th>Item code</th><th>Stock type</th><th>Type</th><th>Metal / Purity</th><th class="is-numeric">Gross</th><th class="is-numeric">Net</th><th>VAT</th><th class="is-numeric">In stock (fine)</th><th>Status</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
                <?php if ($items === []): ?><tr><td colspan="<?= $canEdit ? 13 : 12 ?>">No items yet.</td></tr><?php endif; ?>
                <?php foreach ($grouped as $groupName => $groupRows): ?>
                    <?php
                        // The group's own line: how many items it holds and what
                        // they come to in fine weight. A group is a thing a shop
                        // asks about — "how much chain have I got?" — and the
                        // answer is the sum of the items traced under it.
                        $groupFine = 0.0;
                        foreach ($groupRows as $groupItem) {
                            $groupFine += (float) jw_item_balance($companyId, (int) $groupItem['id'], null, '')['fine_weight'];
                        }
                    ?>
                    <tr style="background:var(--mbw-accent-soft,#eef7f1)">
                        <td></td>
                        <td colspan="<?= $canEdit ? 9 : 8 ?>"><strong><?= e((string) $groupName) ?></strong>
                            <small style="color:var(--mbw-muted,#64748b)">— <?= count($groupRows) ?> item<?= count($groupRows) > 1 ? 's' : '' ?></small></td>
                        <td class="is-numeric"><strong><?= $fmt($groupFine, 4) ?></strong></td>
                        <td colspan="2"></td>
                    </tr>
                <?php foreach ($groupRows as $row): ?>
                    <?php $rowBalance = jw_item_balance($companyId, (int) $row['id'], null, ''); ?>
                    <tr>
                        <td class="is-numeric"><?= ++$serial ?></td>
                        <td style="color:var(--mbw-muted,#64748b)"><?= e((string) $groupName) ?></td>
                        <td><?= e($row['name']) ?></td>
                        <td><?= e($row['code']) ?></td>
                        <td><span class="mbw-pill <?= (string) ($row['stock_kind'] ?? 'showroom') === 'customer_ordered' ? 'tone-blue' : 'tone-green' ?>"><?= (string) ($row['stock_kind'] ?? 'showroom') === 'customer_ordered' ? 'Customer Ordered' : 'Showroom' ?></span></td>
                        <td><?= e(ucfirst((string) $row['item_type'])) ?></td>
                        <td><?= e($row['metal_name'] . ' · ' . $row['purity_code']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['gross_weight'], 4) ?> <small><?= e($row['unit_code']) ?></small></td>
                        <td class="is-numeric"><?= $fmt((float) $row['net_weight'], 4) ?></td>
                        <td><?= (int) $row['vat_applicable'] === 1 ? '<span class="mbw-pill tone-amber">' . e(str_replace('_', ' ', jw_item_vat_base($row, $settings))) . '</span>' : '<span class="mbw-pill tone-gray">Exempt</span>' ?></td>
                        <td class="is-numeric"><?= $fmt($rowBalance['fine_weight'], 4) ?></td>
                        <td><span class="mbw-pill <?= (string) $row['status'] === 'active' ? 'tone-green' : 'tone-gray' ?>"><?= (string) $row['status'] === 'active' ? 'Active' : 'Off' ?></span></td>
                        <?php if ($canEdit): ?><td style="white-space:nowrap">
                            <a class="mbw-view-all" href="<?= e(url('admin/jewellery.php?view=items&edit=' . (int) $row['id'])) ?>">Edit</a>
                            <?php // Straight to the tag screen with this piece already
                                  // ticked, so re-tagging one item is one click and still
                                  // goes through the same renderer as a batch. ?>
                            <a class="mbw-view-all" style="margin-left:8px"
                               href="<?= e(url('admin/jewellery-tags.php?items=' . (int) $row['id'])) ?>">Tag</a>
                            <?php // Deletable only while untouched: one stock movement or
                                  // document line makes the item part of the record, and
                                  // the engine answers with "mark it inactive instead". ?>
                            <form method="post" style="display:inline;margin-left:8px" data-confirm="Delete this item? Only one with no movements and no document lines can go.">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="back_view" value="items">
                                <input type="hidden" name="item_id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="button soft" style="min-height:26px;padding:2px 8px">Delete</button>
                            </form>
                        </td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php if ($canEdit): ?>
    <script>
    (function () {
        var start = function () {
        var form = document.getElementById('opening-bulk-clear-form');
        if (!form) { return; }

        // Find the Opening Stock section and its table specifically so we don't
        // accidentally bind to an earlier preview table.
        var openingSection = Array.from(document.querySelectorAll('.mbw-card-head h2')).
            map(function (h) { return {h:h, txt: (h.innerText||'').trim()}; }).
            find(function (o) { return o.txt && o.txt.indexOf('Opening Stock') === 0; });
        var table = null;
        if (openingSection && openingSection.h) {
            var section = openingSection.h.closest('section');
            if (section) { table = section.querySelector('table'); }
        }
        if (!table) {
            // Fallback: first table on the page
            table = document.querySelector('table');
        }

        // Attach select-all behavior scoped to this table only.
        var selectAll = table ? table.querySelector('#opening-select-all') : null;
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                var checkboxes = table.querySelectorAll('.opening-select-checkbox');
                checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
            });
        }

        // Submit handler: collect checked rows from this table only.
        form.addEventListener('submit', function (event) {
            var checkboxes = (table ? Array.from(table.querySelectorAll('.opening-select-checkbox')) : Array.from(document.querySelectorAll('.opening-select-checkbox')));
            var selected = checkboxes.filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
            if (selected.length === 0) {
                alert('Select at least one opening stock item to clear.');
                event.preventDefault();
                return;
            }
            form.querySelectorAll('input[name="item_ids[]"]').forEach(function (existing) { existing.remove(); });
            selected.forEach(function (id) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'item_ids[]';
                input.value = id;
                form.appendChild(input);
            });
            return confirm('Clear opening stock for ' + selected.length + ' selected item(s)? This will remove their voucher and metal movement.');
        });

        // Add per-column simple client-side filters under the opening stock table
        if (table) {
            var thead = table.querySelector('thead');
            if (thead) {
                // Reuse an existing server-rendered filter row if present,
                // otherwise create one. Always attach listeners to the inputs.
                var filterRow = thead.querySelector('.jw-filter-row');
                if (!filterRow) {
                    var headerCells = Array.from(thead.querySelectorAll('th'));
                    filterRow = document.createElement('tr');
                    filterRow.className = 'jw-filter-row';
                    headerCells.forEach(function (th, idx) {
                        var cell = document.createElement('th');
                        // Skip filter for checkbox and action columns
                        if (th.querySelector('#opening-select-all') || idx === headerCells.length - 1) {
                            cell.innerHTML = '';
                        } else {
                            var input = document.createElement('input');
                            input.type = 'search';
                            input.placeholder = 'Filter';
                            input.style.width = '100%';
                            input.dataset.colIndex = idx;
                            cell.appendChild(input);
                        }
                        filterRow.appendChild(cell);
                    });
                    thead.appendChild(filterRow);
                }

                // Attach listeners to inputs whether server or client rendered.
                var inputs = Array.from(thead.querySelectorAll('.jw-filter-row input[type="search"]'));
                inputs.forEach(function (inp) {
                    // Accept server-provided `data-col` and normalise to `data-colIndex`.
                    if (!inp.dataset.colIndex) {
                        if (inp.dataset.col) {
                            inp.dataset.colIndex = inp.dataset.col;
                        } else if (inp.getAttribute('data-col')) {
                            inp.dataset.colIndex = inp.getAttribute('data-col');
                        }
                    }
                    inp.addEventListener('input', function () { applyFilters(); });
                });
            }

            function applyFilters() {
                var inputs = Array.from(table.querySelectorAll('thead .jw-filter-row input[type="search"]'));
                var rows = Array.from(table.querySelectorAll('tbody tr'));
                rows.forEach(function (row) {
                    var cells = Array.from(row.querySelectorAll('td'));
                    var show = true;
                    inputs.forEach(function (inp) {
                        var col = parseInt(inp.dataset.colIndex, 10);
                        if (Number.isNaN(col)) { return; }
                        var cell = cells[col];
                        var text = cell ? cell.innerText.toLowerCase() : '';
                        if (inp.value && text.indexOf(inp.value.toLowerCase()) === -1) {
                            show = false;
                        }
                    });
                    row.style.display = show ? '' : 'none';
                    });
                }
                // Expose for debugging and manual invocation
                window.jwApplyFilters = applyFilters;
            }

        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start);
        } else {
            start();
        }
    })();
    </script>
    <?php endif; ?>

    <script>
    (function () {
        var metal = document.getElementById('jw-item-metal');
        var purity = document.getElementById('jw-item-purity');
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

<?php elseif ($view === 'opening'): ?>
    <?php
    $openingValue = 0.0;
    $openingFine = 0.0;
    foreach ($openingRows as $row) {
        $openingValue += (float) $row['amount'];
        $openingFine += (float) $row['fine_weight'];
    }
    ?>
    <div class="notice" style="margin-bottom:14px">
        Opening stock is dated <strong><?= e(app_date($fyStart)) ?></strong>. It is the same figure the core
        <a href="<?= e(url('admin/accounting-inventory.php')) ?>">Inventory</a> item shows.
    </div>

    <section class="mbw-kpi-grid" aria-label="Opening stock summary">
        <?php foreach ([
            ['Items with opening stock', (string) count($openingRows), 'box', count($openingRows) > 0 ? 'tone-green' : 'tone-gray'],
            ['Opening fine weight', $fmt($openingFine, 4), 'scale', 'tone-teal'],
            ['Opening value', $sym . $fmt($openingValue), 'wallet', 'tone-blue'],
        ] as [$kpiLabel, $kpiValue, $kpiIcon, $kpiTone]): ?>
            <article class="mbw-kpi"><div><span class="mbw-kpi-label"><?= e($kpiLabel) ?></span><div class="mbw-kpi-value" style="font-size:1.02rem"><?= e($kpiValue) ?></div></div><span class="mbw-chip <?= e($kpiTone) ?>"><?= icon($kpiIcon) ?></span></article>
        <?php endforeach; ?>
    </section>

    <?php if ($canEdit): ?>
    <section class="mbw-card" data-collapsible style="margin-bottom:14px">
        <div class="mbw-card-head">
            <h2>Upload Opening Stock from a Spreadsheet</h2>
            <a class="button soft" style="min-height:32px" href="<?= e(url('admin/jewellery.php?view=opening&template=xlsx&v=4')) ?>">Download template v4</a>
        </div>
        <form method="post" enctype="multipart/form-data" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="upload_opening">
            <input type="hidden" name="back_view" value="opening">
            <label>Spreadsheet<input type="file" name="opening_file" accept=".xlsx,.csv" required></label>
            <div style="align-self:end">
                <button type="submit" class="button">Upload &amp; Preview</button>
            </div>
        </form>
    </section>

    <?php if ($importBatch): ?>
    <?php
        $readyCount = 0; $errorCount = 0; $committedCount = 0; $stagedValue = 0.0;
        foreach ($importRows as $ir) {
            if ((string) $ir['status'] === 'ready') { $readyCount++; $stagedValue += (float) $ir['amount']; }
            elseif ((string) $ir['status'] === 'committed') { $committedCount++; }
            else { $errorCount++; }
        }
    ?>
    <section class="mbw-card" data-collapsible style="margin-bottom:14px">
        <div class="mbw-card-head">
            <h2>Preview — <?= e((string) $importBatch['original_name']) ?></h2>
        </div>

        <div class="mbw-stat-row" style="margin-bottom:12px">
            <div class="mbw-stat"><span>Rows read</span><strong><?= count($importRows) ?></strong></div>
            <div class="mbw-stat"><span>Ready</span><strong><?= $readyCount ?></strong></div>
            <div class="mbw-stat <?= $errorCount > 0 ? 'tone-amber' : '' ?>"><span>Need attention</span><strong><?= $errorCount ?></strong></div>
            <div class="mbw-stat"><span>Already committed</span><strong><?= $committedCount ?></strong></div>
            <div class="mbw-stat"><span>Value ready to post</span><strong><?= e($sym) ?> <?= $fmt($stagedValue) ?></strong></div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <form method="post" style="display:inline" onsubmit="return confirm('Commit <?= $readyCount ?> row(s) as opening stock? Each one posts its own opening voucher, exactly as if it had been typed on this page.');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="commit_import">
                <input type="hidden" name="import_id" value="<?= (int) $importBatch['id'] ?>">
                <button type="submit" class="button" <?= $readyCount > 0 ? '' : 'disabled' ?>>Commit <?= $readyCount ?> row<?= $readyCount === 1 ? '' : 's' ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Discard this import? Nothing has reached the books, so nothing is reversed.');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="discard_import">
                <input type="hidden" name="import_id" value="<?= (int) $importBatch['id'] ?>">
                <button type="submit" class="button secondary">Discard import</button>
            </form>
            <?php if ($errorCount > 0): ?>
            <?php endif; ?>
        </div>

        <p class="frm-optional" style="margin:0 0 10px">The spreadsheet columns appear first, in the same order as the Opening Stock Import template. Existing item, validation status and actions are review controls — they are not spreadsheet columns. Every uncommitted row remains editable until you deliberately commit it.</p>
        <div class="mbw-tablewrap jw-opening-import-wrap"><table class="jw-opening-import-table">
            <thead><tr>
                <th>Source Excel Row</th><th>Stock Type *</th><th>Stock Group *</th><th>Item Code *</th><th>Item Name *</th>
                <th>Metal *</th><th>Purity Code</th><th>Unit *</th><th>Pieces *</th><th>Gross Weight (GM) *</th><th>Stone Weight (ct)</th><th>Stone Amount</th><th>Diamond Weight (ct)</th><th>Diamond Amount</th><th>Net Weight (auto)</th><th>Making Charge</th>
                <th>Derived Rate</th><th>Opening Amount *</th><th>Customer Name</th><th>Order Number</th>
                <th style="min-width:220px">Existing Item / Create</th><th style="min-width:220px">Validation Status</th><th>Actions</th>
            </tr></thead>
            <tbody>
                <?php foreach ($importRows as $ir): ?>
                    <?php $isCommitted = (string) $ir['status'] === 'committed'; ?>
                    <tr<?= (string) $ir['status'] === 'error' ? ' style="background:var(--mbw-red-soft)"' : '' ?>>
                        <?php if ($isCommitted): ?>
                            <td><?= (int) $ir['source_row_no'] ?></td>
                            <td><?= e((string) $ir['stock_kind'] === 'customer_ordered' ? 'Customer Ordered' : 'Showroom') ?></td>
                            <td><?= e((string) $ir['raw_group']) ?></td>
                            <td><?= e((string) $ir['proposed_code']) ?></td>
                            <td><?= e((string) $ir['proposed_name']) ?></td>
                            <td><?= e((string) ($ir['metal_code'] ?? '')) ?></td>
                            <td><?= e((string) ($ir['purity_code'] ?? '')) ?></td>
                            <td><?= e((string) ($ir['unit_code'] ?? '')) ?></td>
                            <td class="is-numeric"><?= $fmt((float) $ir['qty_pieces'], 3) ?></td>
                            <td class="is-numeric"><?= $fmt((float) $ir['gross_weight'], 4) ?></td>
                            <td class="is-numeric"><?= $fmt((float) ($ir['stone_weight'] ?? 0), 4) ?></td>
                            <td class="is-numeric"><?= $fmt((float) ($ir['stone_amount'] ?? 0)) ?></td>
                            <td class="is-numeric"><?= $fmt((float) ($ir['diamond_weight'] ?? 0), 4) ?></td>
                            <td class="is-numeric"><?= $fmt((float) ($ir['diamond_amount'] ?? 0)) ?></td>
                            <td class="is-numeric">Calculated when posted</td>
                            <td class="is-numeric"><?= $fmt((float) ($ir['making_amount'] ?? 0)) ?></td>
                            <td class="is-numeric"><?= $fmt((float) $ir['rate'], 4) ?></td>
                            <td class="is-numeric"><?= $fmt((float) $ir['amount']) ?></td>
                            <td><?= e((string) $ir['customer_name']) ?></td>
                            <td><?= e((string) $ir['order_number']) ?></td>
                            <td><?= e((string) ($ir['item_code'] ?? '')) ?> — <?= e((string) ($ir['item_name'] ?? '')) ?></td>
                            <td><span class="mbw-pill tone-green">Committed</span></td>
                            <td>—</td>
                        <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_import_row">
                            <input type="hidden" name="import_id" value="<?= (int) $importBatch['id'] ?>">
                            <input type="hidden" name="row_id" value="<?= (int) $ir['id'] ?>">
                            <td><?= (int) $ir['source_row_no'] ?></td>
                            <td><select name="stock_kind" required>
                                <option value="">Select</option>
                                <option value="showroom" <?= (string) $ir['stock_kind'] === 'showroom' ? 'selected' : '' ?>>Showroom Stock</option>
                                <option value="customer_ordered" <?= (string) $ir['stock_kind'] === 'customer_ordered' ? 'selected' : '' ?>>Customer Ordered Stock</option>
                            </select></td>
                            <td><input type="text" name="raw_group" value="<?= e((string) $ir['raw_group']) ?>" list="jw-import-groups" required></td>
                            <td><input type="text" name="proposed_code" value="<?= e((string) ($ir['proposed_code'] ?: $ir['raw_code'])) ?>" style="width:120px" required></td>
                            <td><input type="text" name="proposed_name" value="<?= e((string) ($ir['proposed_name'] ?: $ir['raw_name'])) ?>" style="width:180px" required></td>
                            <td><select name="metal_id" required>
                                <option value="0">Select metal</option>
                                <?php foreach ($metals as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) $ir['metal_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['code'] . ' — ' . $m['name']) ?></option><?php endforeach; ?>
                            </select></td>
                            <td>
                                <select name="purity_id">
                                    <option value="0">—</option>
                                    <?php foreach ($purities as $p): ?>
                                        <option value="<?= (int) $p['id'] ?>" <?= (int) $ir['purity_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['code']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="unit_id">
                                    <option value="0">—</option>
                                    <?php foreach ($units as $u): ?>
                                        <option value="<?= (int) $u['id'] ?>" <?= (int) $ir['unit_id'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['code']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="number" name="qty_pieces" step="0.001" min="0" style="width:80px" value="<?= e((string) $ir['qty_pieces']) ?>"></td>
                            <td><input type="number" name="gross_weight" step="0.0001" min="0" style="width:100px" value="<?= e((string) $ir['gross_weight']) ?>"></td>
                            <td><input type="number" name="stone_weight" step="0.0001" min="0" style="width:100px" value="<?= e((string) ($ir['stone_weight'] ?? 0)) ?>"></td>
                            <td><input type="number" name="stone_amount" step="0.01" min="0" style="width:110px" value="<?= e((string) ($ir['stone_amount'] ?? 0)) ?>"></td>
                            <td><input type="number" name="diamond_weight" step="0.0001" min="0" style="width:100px" value="<?= e((string) ($ir['diamond_weight'] ?? 0)) ?>"></td>
                            <td><input type="number" name="diamond_amount" step="0.01" min="0" style="width:110px" value="<?= e((string) ($ir['diamond_amount'] ?? 0)) ?>"></td>
                            <td class="is-numeric">Auto</td>
                            <td><input type="number" name="making_amount" step="0.01" min="0" style="width:110px" value="<?= e((string) ($ir['making_amount'] ?? 0)) ?>"></td>
                            <td><input type="number" name="rate" step="0.0001" min="0" style="width:110px" value="<?= e((string) $ir['rate']) ?>"></td>
                            <td><input type="number" name="amount" step="0.01" min="0" style="width:120px" value="<?= e((string) $ir['amount']) ?>"></td>
                            <td><input type="text" name="customer_name" style="width:150px" value="<?= e((string) $ir['customer_name']) ?>"></td>
                            <td><input type="text" name="order_number" style="width:120px" value="<?= e((string) $ir['order_number']) ?>"></td>
                            <td>
                                <select name="item_id">
                                    <option value="0"><?= (int) ($ir['create_item'] ?? 0) === 1 ? 'Create new item from code' : '— not matched —' ?></option>
                                    <?php foreach ($items as $it): ?>
                                        <option value="<?= (int) $it['id'] ?>" <?= (int) $ir['item_id'] === (int) $it['id'] ? 'selected' : '' ?>><?= e($it['code'] . ' — ' . $it['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <?php if ((string) $ir['error_text'] !== ''): ?>
                                    <span class="mbw-pill tone-amber"><?= e((string) $ir['error_text']) ?></span>
                                <?php else: ?>
                                    <span class="mbw-pill tone-green">Ready to commit</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap">
                                <button type="submit" class="button secondary jw-import-action"><?= icon('save') ?><span>Save</span></button>
                        </form>
                                <form method="post" style="display:inline" onsubmit="return confirm('Remove sheet row <?= (int) $ir['source_row_no'] ?> from this import?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_import_row">
                                    <input type="hidden" name="import_id" value="<?= (int) $importBatch['id'] ?>">
                                    <input type="hidden" name="row_id" value="<?= (int) $ir['id'] ?>">
                                    <button type="submit" class="button secondary jw-import-action jw-import-delete"><?= icon('trash') ?><span>Delete</span></button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($importRows === []): ?>
                    <tr><td colspan="23" class="frm-optional">Every row in this import has been dealt with.</td></tr>
                <?php endif; ?>
            </tbody>
        </table></div>
        <datalist id="jw-import-groups"><?php foreach (jewellery_categories_list($companyId, false) as $category): ?><option value="<?= e((string) $category['name']) ?>"><?php endforeach; ?></datalist>
    </section>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <section id="record-opening-stock" class="mbw-card" data-collapsible data-draggable>
        <div class="mbw-card-head"><h2>Record Opening Stock</h2></div>
        <form id="opening-stock-form" method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_opening">
            <input type="hidden" name="back_view" value="opening">
            <label>Item
                <select name="item_id" required>
                    <?php foreach ($items as $row): ?>
                        <option value="<?= (int) $row['id'] ?>"><?= e($row['code'] . ' — ' . $row['name'] . ' (' . $row['purity_code'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Pieces<input type="number" name="qty_pieces" step="0.001" min="0" value="0"></label>
            <label>Stock type<select name="stock_kind" required><option value="showroom">Showroom Stock</option><option value="customer_ordered">Customer Ordered Stock</option></select></label>
            <label>Stock group
                <select name="stock_group">
                    <option value="">Select stock group</option>
                    <?php foreach (jewellery_categories_list($companyId, false) as $category): ?>
                        <option value="<?= e((string) $category['name']) ?>"><?= e((string) $category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Gross weight                <input type="number" name="gross_weight" step="0.0001" min="0" value="0"></label>
            <label>Stone weight (ct)<input type="number" name="stone_carat" step="0.0001" min="0" value="0"></label>
            <label>Diamond weight (ct)<input type="number" name="diamond_carat" step="0.0001" min="0" value="0"></label>
            <label>Net weight (gm)<input type="number" id="opening-net-weight" step="0.0001" readonly value="0"></label>
            <label>Stone amount<input type="number" name="stone_amount" step="0.01" min="0" value="0"></label>
            <label>Diamond amount<input type="number" name="diamond_amount" step="0.01" min="0" value="0"></label>
            <label>Making charge<input type="number" name="making_amount" step="0.01" min="0" value="0"></label>
            <label>Rate<input type="number" name="rate" step="0.0001" min="0" value="0"></label>
            <label>Opening value (<?= e($sym) ?>)<input type="number" name="amount" step="0.01" min="0" value="0"></label>
            <label>Customer name<input type="text" name="customer_name" maxlength="190"></label>
            <label>Order number<input type="text" name="order_number" maxlength="120"></label>
            <div style="grid-column:1/-1;display:flex;gap:8px;align-items:center">
                <button id="opening-stock-submit" type="submit" class="button" <?= $items === [] ? 'disabled' : '' ?>>Save &amp; Post</button>
                <button id="opening-stock-cancel-edit" type="button" class="button soft" hidden>Cancel edit</button>
            </div>
        </form>
        <script>
        (function () {
            var form = document.currentScript.previousElementSibling;
            var gross = form.querySelector('[name="gross_weight"]');
            var stone = form.querySelector('[name="stone_carat"]');
            var diamond = form.querySelector('[name="diamond_carat"]');
            var net = form.querySelector('#opening-net-weight');
            function updateNet() {
                var lessInGrams = ((Number(stone.value) || 0) + (Number(diamond.value) || 0)) * 0.2;
                net.value = Math.max(0, (Number(gross.value) || 0) - lessInGrams).toFixed(4);
            }
            [gross, stone, diamond].forEach(function (field) { field.addEventListener('input', updateNet); field.addEventListener('change', updateNet); });
            updateNet();
        })();
        </script>
        <?php if ($items === []): ?>
        <?php else: ?>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($canEdit && $openingRows !== []): ?>
    <form id="opening-bulk-clear-form" method="post" style="margin-bottom:14px">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="clear_opening_bulk">
        <input type="hidden" name="back_view" value="opening">
        <button type="submit" class="button danger" aria-label="Clear selected opening stock" style="display:inline-flex;align-items:center;gap:8px;padding:10px 14px;background:var(--danger) !important;border:1px solid var(--danger) !important;color:var(--c-on-primary) !important;font-weight:600;box-shadow:var(--shadow-sm) !important;">
            <?= icon('trash') ?> <span>Clear selected opening stock</span>
        </button>
    </form>
    <?php endif; ?>

    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Opening Stock — <?= e((string) $fiscalYear['label']) ?> (<?= count($openingRows) ?>)</h2></div>
        <div class="jw-opening-filter" style="display:flex;flex-wrap:wrap;gap:10px 12px;align-items:end;padding:0 0 14px">
            <label style="display:grid;gap:5px;min-width:220px;flex:1 1 220px">Search
                <input type="search" placeholder="Item name or code" aria-label="Search by item or code" data-col="<?= $canEdit ? 1 : 0 ?>">
            </label>
            <label style="display:grid;gap:5px;min-width:160px;flex:1 1 160px">Stock group
                <input type="search" placeholder="All groups" aria-label="Filter by stock group" data-col="<?= $canEdit ? 2 : 1 ?>">
            </label>
            <label style="display:grid;gap:5px;min-width:150px;flex:0 1 170px">Stock type
                <select aria-label="Filter by stock type" data-col="<?= $canEdit ? 3 : 2 ?>"><option value="">All types</option><option value="Showroom">Showroom</option><option value="Customer Ordered">Customer ordered</option></select>
            </label>
            <label style="display:grid;gap:5px;min-width:130px;flex:0 1 150px">Purity
                <input type="search" placeholder="All purities" aria-label="Filter by purity" data-col="<?= $canEdit ? 4 : 3 ?>">
            </label>
            <label style="display:grid;gap:5px;min-width:150px;flex:0 1 170px">Status
                <select aria-label="Filter by posting status" data-col="<?= $canEdit ? 15 : 14 ?>"><option value="">All statuses</option><option value="Posted">Posted</option><option value="Weight only">Weight only</option><option value="Not in stock">Not in stock</option></select>
            </label>
            <div style="display:flex;gap:6px;align-items:end">
                <button type="button" class="button jw-filter-apply">Filter</button>
                <button type="button" class="button soft jw-filter-clear">Clear</button>
            </div>
        </div>
        <div style="overflow-x:auto"><table>
            <thead>
                <tr>
                    <?php if ($canEdit): ?><th style="width:34px"><input type="checkbox" id="opening-select-all" aria-label="Select all opening stock rows"></th><?php endif; ?>
                    <th>Item</th>
                    <th>Stock group</th>
                    <th>Stock type</th>
                    <th>Purity</th>
                    <th class="is-numeric">Gross</th>
                    <th class="is-numeric">Stone (ct)</th>
                    <th class="is-numeric">Diamond (ct)</th>
                    <th class="is-numeric">Net weight</th>
                    <th class="is-numeric">Stone amt</th>
                    <th class="is-numeric">Diamond amt</th>
                    <th class="is-numeric">Making</th>
                    <th class="is-numeric">Fine</th>
                    <th class="is-numeric">Rate</th>
                    <th class="is-numeric">Value</th>
                    <th>Posted</th>
                    <?php if ($canEdit): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($openingRows === []): ?><tr><td colspan="<?= $canEdit ? 16 : 15 ?>">No item carries opening stock for this fiscal year.</td></tr><?php endif; ?>
                <?php foreach ($openingRows as $row): ?>
                    <tr>
                        <?php if ($canEdit): ?><td><input type="checkbox" class="opening-select-checkbox" value="<?= (int) $row['id'] ?>" aria-label="Select opening stock for <?= e($row['item_code']) ?>"></td><?php endif; ?>
                        <td><?= e($row['item_code']) ?><br><small><?= e($row['item_name']) ?></small></td>
                        <td><?= e((string) ($row['category'] ?? 'Uncategorised')) ?></td>
                        <td><span class="mbw-pill <?= (string) ($row['stock_kind'] ?? 'showroom') === 'customer_ordered' ? 'tone-blue' : 'tone-green' ?>"><?= (string) ($row['stock_kind'] ?? 'showroom') === 'customer_ordered' ? 'Customer Ordered' : 'Showroom' ?></span></td>
                        <td><?= e($row['purity_code']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['gross_weight'], 4) ?> <small><?= e($row['unit_code']) ?></small></td>
                        <td class="is-numeric"><?= $fmt((float) ($row['stone_carat'] ?? 0), 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) ($row['diamond_carat'] ?? 0), 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['gross_weight'] - (float) ($row['stone_weight'] ?? 0), 4) ?> <small><?= e($row['unit_code']) ?></small></td>
                        <td class="is-numeric"><?= e($sym) ?><?= $fmt((float) ($row['stone_amount'] ?? 0)) ?></td>
                        <td class="is-numeric"><?= e($sym) ?><?= $fmt((float) ($row['diamond_amount'] ?? 0)) ?></td>
                        <td class="is-numeric"><?= e($sym) ?><?= $fmt((float) ($row['making_amount'] ?? 0)) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['fine_weight'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['rate']) ?></td>
                        <td class="is-numeric"><?= e($sym) ?><?= $fmt((float) $row['amount']) ?></td>
                        <td>
                            <?php if ((int) $row['voucher_id'] > 0): ?>
                                <span class="mbw-pill tone-green">Posted</span><br><small>Voucher #<?= (int) $row['voucher_id'] ?></small>
                            <?php elseif ($row['posted']): ?>
                                <span class="mbw-pill tone-amber">Weight only</span>
                            <?php else: ?>
                                <span class="mbw-pill tone-gray">Not in stock</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($canEdit): ?>
                        <td style="white-space:nowrap;display:flex;gap:6px;align-items:center">
                            <a class="button soft" href="#record-opening-stock" title="Add another opening stock item" aria-label="Add opening stock" style="min-height:30px;padding:3px 8px"><?= icon('plus') ?></a>
                            <button type="button" class="button soft jw-opening-edit" title="Edit opening stock" aria-label="Edit opening stock" style="min-height:30px;padding:3px 8px"
                                data-item-id="<?= (int) $row['id'] ?>"
                                data-pieces="<?= e((string) ($row['qty_pieces'] ?? 0)) ?>"
                                data-stock-kind="<?= e((string) ($row['stock_kind'] ?? 'showroom')) ?>"
                                data-stock-group="<?= e((string) ($row['category'] ?? '')) ?>"
                                data-gross="<?= e((string) $row['gross_weight']) ?>"
                                data-stone-carat="<?= e((string) ($row['stone_carat'] ?? 0)) ?>"
                                data-diamond-carat="<?= e((string) ($row['diamond_carat'] ?? 0)) ?>"
                                data-stone-amount="<?= e((string) ($row['stone_amount'] ?? 0)) ?>"
                                data-diamond-amount="<?= e((string) ($row['diamond_amount'] ?? 0)) ?>"
                                data-making-amount="<?= e((string) ($row['making_amount'] ?? 0)) ?>"
                                data-rate="<?= e((string) ($row['rate'] ?? 0)) ?>"
                                data-amount="<?= e((string) ($row['amount'] ?? 0)) ?>"
                                data-customer-name="<?= e((string) ($row['customer_name'] ?? '')) ?>"
                                data-order-number="<?= e((string) ($row['customer_order_no'] ?? '')) ?>"><?= icon('edit') ?></button>
                            <form method="post" data-confirm="Clear this opening stock? Its voucher and metal movement will be removed.">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="clear_opening">
                                <input type="hidden" name="back_view" value="opening">
                                <input type="hidden" name="item_id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="button soft" title="Delete opening stock" aria-label="Delete opening stock" style="min-height:30px;padding:3px 8px"><?= icon('trash') ?></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    
    <script>
    // Attach per-column filters specifically for the Opening Stock table
    (function () {
        function attachOpeningFilters() {
            var openingSection = Array.from(document.querySelectorAll('.mbw-card-head h2')).
                map(function (h) { return {h:h, txt: (h.innerText||'').trim()}; }).
                find(function (o) { return o.txt && o.txt.indexOf('Opening Stock') === 0; });
            if (!openingSection || !openingSection.h) { return; }
            var section = openingSection.h.closest('section'); if (!section) { return; }
            var table = section.querySelector('table'); if (!table) { return; }
            var filterBar = section.querySelector('.jw-opening-filter');
            var inputs = Array.from((filterBar || section).querySelectorAll('[data-col]'));
            if (!inputs.length) { return; }
            function apply() {
                var rows = Array.from(table.querySelectorAll('tbody tr'));
                rows.forEach(function (row) {
                    var cells = Array.from(row.querySelectorAll('td'));
                    var show = true;
                    inputs.forEach(function (inp) {
                        var col = parseInt(inp.dataset.colIndex || inp.getAttribute('data-col') || inp.dataset.col, 10);
                        if (Number.isNaN(col)) { return; }
                        var cell = cells[col];
                        var text = cell ? cell.innerText.toLowerCase() : '';
                        if (inp.value && text.indexOf(inp.value.toLowerCase()) === -1) { show = false; }
                    });
                    row.style.display = show ? '' : 'none';
                });
            }
            inputs.forEach(function (inp) {
                if (!inp.dataset.colIndex) {
                    if (inp.dataset.col) inp.dataset.colIndex = inp.dataset.col;
                    else if (inp.getAttribute('data-col')) inp.dataset.colIndex = inp.getAttribute('data-col');
                }
                inp.addEventListener('input', apply);
                inp.addEventListener('change', apply);
            });
            var clear = section.querySelector('.jw-filter-clear');
            if (clear) {
                clear.addEventListener('click', function () {
                    inputs.forEach(function (inp) { inp.value = ''; });
                    apply();
                });
            }
            var applyButton = section.querySelector('.jw-filter-apply');
            if (applyButton) { applyButton.addEventListener('click', apply); }
            window.jwApplyFilters = apply;
        }
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', attachOpeningFilters); } else { attachOpeningFilters(); }
    })();
    </script>

    <script>
    // Editing reuses the opening form: saving replaces this item's prior
    // opening voucher and metal movement, so it never duplicates stock.
    (function () {
        function attachOpeningEdit() {
            var form = document.getElementById('opening-stock-form');
            if (!form) { return; }
            var submit = document.getElementById('opening-stock-submit');
            var cancel = document.getElementById('opening-stock-cancel-edit');
            var original = new FormData(form);
            function setValue(name, value) {
                var input = form.querySelector('[name="' + name + '"]');
                if (input) { input.value = value == null ? '' : value; }
            }
            function updateNet() {
                ['gross_weight', 'stone_carat', 'diamond_carat'].forEach(function (name) {
                    var input = form.querySelector('[name="' + name + '"]');
                    if (input) { input.dispatchEvent(new Event('input', {bubbles:true})); }
                });
            }
            document.querySelectorAll('.jw-opening-edit').forEach(function (button) {
                button.addEventListener('click', function () {
                    var data = button.dataset;
                    setValue('item_id', data.itemId);
                    setValue('qty_pieces', data.pieces);
                    setValue('stock_kind', data.stockKind);
                    setValue('stock_group', data.stockGroup);
                    setValue('gross_weight', data.gross);
                    setValue('stone_carat', data.stoneCarat);
                    setValue('diamond_carat', data.diamondCarat);
                    setValue('stone_amount', data.stoneAmount);
                    setValue('diamond_amount', data.diamondAmount);
                    setValue('making_amount', data.makingAmount);
                    setValue('rate', data.rate);
                    setValue('amount', data.amount);
                    setValue('customer_name', data.customerName);
                    setValue('order_number', data.orderNumber);
                    updateNet();
                    if (submit) { submit.textContent = 'Update & Post'; }
                    if (cancel) { cancel.hidden = false; }
                    form.closest('section').scrollIntoView({behavior: 'smooth', block: 'start'});
                });
            });
            if (cancel) {
                cancel.addEventListener('click', function () {
                    original.forEach(function (value, name) { setValue(name, value); });
                    updateNet();
                    if (submit) { submit.textContent = 'Save & Post'; }
                    cancel.hidden = true;
                });
            }
        }
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', attachOpeningEdit); } else { attachOpeningEdit(); }
    })();
    </script>

    <script>
    // Robust fallback: ensure the Opening Stock master checkbox always toggles
    // visible `.opening-select-checkbox` inputs even if other scripts run.
    (function () {
        function attachMaster() {
            var master = document.getElementById('opening-select-all');
            if (!master) { return; }
            // avoid double-binding
            if (master.__jw_master_attached) { return; }
            master.__jw_master_attached = true;
            master.addEventListener('change', function () {
                var table = (master.closest('table')) || document.querySelector('table');
                var boxes = Array.from((table || document).querySelectorAll('.opening-select-checkbox'))
                    .filter(function (cb) { var tr = cb.closest('tr'); return !tr || tr.style.display !== 'none'; });
                boxes.forEach(function (cb) { cb.checked = master.checked; cb.dispatchEvent(new Event('change', { bubbles: true })); });
            });
        }
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', attachMaster); } else { attachMaster(); }
    })();
    </script>

    <script>
    // Ensure the form includes item_ids[] for selected rows before submission
    (function () {
        function attachSubmitFix() {
            var form = document.getElementById('opening-bulk-clear-form');
            if (!form) return;
            if (form.__jw_submit_fixed) return; form.__jw_submit_fixed = true;
            form.addEventListener('submit', function (event) {
                // If hidden inputs already present, do nothing.
                if (form.querySelectorAll('input[name="item_ids[]"]').length > 0) { return; }
                var openingSection = Array.from(document.querySelectorAll('.mbw-card-head h2')).
                    map(function (h) { return {h:h, txt: (h.innerText||'').trim()}; }).
                    find(function (o) { return o.txt && o.txt.indexOf('Opening Stock') === 0; });
                var table = openingSection && openingSection.h ? openingSection.h.closest('section').querySelector('table') : document.querySelector('table');
                var checkboxes = table ? Array.from(table.querySelectorAll('.opening-select-checkbox')) : Array.from(document.querySelectorAll('.opening-select-checkbox'));
                var selected = checkboxes.filter(function (cb) { var tr = cb.closest('tr'); if (tr && tr.style.display === 'none') return false; return cb.checked; }).map(function (cb) { return cb.value; });
                if (selected.length === 0) {
                    alert('Select at least one opening stock item to clear.');
                    event.preventDefault();
                    return;
                }
                selected.forEach(function (id) {
                    var input = document.createElement('input'); input.type = 'hidden'; input.name = 'item_ids[]'; input.value = id; form.appendChild(input);
                });
            }, true);
        }
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', attachSubmitFix); } else { attachSubmitFix(); }
    })();
    </script>

<?php elseif ($view === 'stock'): ?>
    <?php
    $holderLabels = ['stock' => 'Own stock', 'karigar' => 'With karigar', 'refinery' => 'With refinery', 'customer' => 'With customer'];
    $totalFineOwn = 0.0;
    $totalFineOut = 0.0;
    $totalValue = 0.0;
    foreach ($position as $p) {
        if ((string) $p['holder_type'] === 'stock') { $totalFineOwn += (float) $p['fine']; } else { $totalFineOut += (float) $p['fine']; }
        $totalValue += (float) $p['value'];
    }
    ?>
    <section class="mbw-kpi-grid" aria-label="Stock summary">
        <?php foreach ([
            ['Fine weight in own stock', $fmt($totalFineOwn, 4), 'scale', 'tone-green'],
            ['Fine weight with others', $fmt($totalFineOut, 4), 'handshake', $totalFineOut > 0 ? 'tone-amber' : 'tone-gray'],
            ['Stock value', $sym . $fmt($totalValue), 'wallet', 'tone-blue'],
            ['Items holding stock', (string) count($stockRows), 'box', 'tone-teal'],
        ] as [$kpiLabel, $kpiValue, $kpiIcon, $kpiTone]): ?>
            <article class="mbw-kpi"><div><span class="mbw-kpi-label"><?= e($kpiLabel) ?></span><div class="mbw-kpi-value" style="font-size:1.02rem"><?= e($kpiValue) ?></div></div><span class="mbw-chip <?= e($kpiTone) ?>"><?= icon($kpiIcon) ?></span></article>
        <?php endforeach; ?>
    </section>

    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Metal Position as at <?= e(app_date($todayInFy)) ?></h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Metal</th><th>Purity</th><th>Held by</th><th class="is-numeric">Pieces</th><th class="is-numeric">Fine weight</th><th class="is-numeric">Value</th></tr></thead>
            <tbody>
                <?php if ($position === []): ?><tr><td colspan="6">No metal on hand yet.</td></tr><?php endif; ?>
                <?php foreach ($position as $p): ?>
                    <tr>
                        <td><?= e($p['metal_name']) ?></td>
                        <td><?= e($p['purity_code']) ?> <small>(<?= $fmt((float) $p['fineness'], 1) ?>)</small></td>
                        <td>
                            <span class="mbw-pill <?= (string) $p['holder_type'] === 'stock' ? 'tone-green' : 'tone-amber' ?>"><?= e($holderLabels[$p['holder_type']] ?? $p['holder_type']) ?></span>
                            <?= (int) ($p['holder_id'] ?? 0) > 0 ? ' <small>#' . (int) $p['holder_id'] . '</small>' : '' ?>
                        </td>
                        <td class="is-numeric"><?= $fmt((float) $p['pieces'], 3) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $p['fine'], 4) ?></td>
                        <td class="is-numeric"><?= e($sym) ?><?= $fmt((float) $p['value']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Stock by Item (<?= count($stockRows) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Item</th><th>Purity</th><th class="is-numeric">Pieces</th><th class="is-numeric">Gross</th><th class="is-numeric">Stone (ct)</th><th class="is-numeric">Diamond (ct)</th><th class="is-numeric">Net</th><th class="is-numeric">Stone amt</th><th class="is-numeric">Diamond amt</th><th class="is-numeric">Making</th><th class="is-numeric">Fine (total)</th><th class="is-numeric">Fine (own)</th><th class="is-numeric">With others</th><th class="is-numeric">Value</th><th class="is-numeric">Avg cost / fine</th><th></th></tr></thead>
            <tbody>
                <?php if ($stockRows === []): ?><tr><td colspan="16">No item holds stock yet.</td></tr><?php endif; ?>
                <?php foreach ($stockRows as $row): ?>
                    <tr>
                        <td><?= e($row['code']) ?><br><small><?= e($row['name']) ?></small></td>
                        <td><?= e($row['purity_code']) ?></td>
                        <td class="is-numeric"><?= $fmt($row['balance']['qty_pieces'], 3) ?></td>
                        <td class="is-numeric"><?= $fmt($row['balance']['gross_weight'], 4) ?> <small><?= e($row['unit_code']) ?></small></td>
                        <td class="is-numeric"><?= $fmt((float) ($row['components']['stone_carat'] ?? 0), 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) ($row['components']['diamond_carat'] ?? 0), 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['balance']['gross_weight'] - (float) ($row['components']['stone_weight'] ?? 0), 4) ?> <small><?= e($row['unit_code']) ?></small></td>
                        <td class="is-numeric"><?= e($sym) ?><?= $fmt((float) ($row['components']['stone_amount'] ?? 0)) ?></td>
                        <td class="is-numeric"><?= e($sym) ?><?= $fmt((float) ($row['components']['diamond_amount'] ?? 0)) ?></td>
                        <td class="is-numeric"><?= e($sym) ?><?= $fmt((float) ($row['components']['making_amount'] ?? 0)) ?></td>
                        <td class="is-numeric"><?= $fmt($row['balance']['fine_weight'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt($row['own_stock']['fine_weight'], 4) ?></td>
                        <td class="is-numeric"><?= $row['with_others_fine'] > 0 ? '<span class="mbw-pill tone-amber">' . $fmt($row['with_others_fine'], 4) . '</span>' : '—' ?></td>
                        <td class="is-numeric"><?= e($sym) ?><?= $fmt($row['balance']['value']) ?></td>
                        <td class="is-numeric"><?= $fmt($row['balance']['avg_fine_rate']) ?></td>
                        <td><a class="mbw-view-all" href="<?= e(url('admin/jewellery.php?view=stock&item=' . (int) $row['id'])) ?>">Ledger →</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <?php if ($ledgerItem && $itemLedger): ?>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head">
            <h2>Stock Ledger — <?= e((string) $ledgerItem['code']) ?> <?= e((string) $ledgerItem['name']) ?></h2>
            <a class="mbw-view-all" href="<?= e(url('admin/jewellery.php?view=stock')) ?>">Close</a>
        </div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Date</th><th>Type</th><th>Ref</th><th>Held by</th><th class="is-numeric">In (fine)</th><th class="is-numeric">Out (fine)</th><th class="is-numeric">Amount</th><th class="is-numeric">Balance (fine)</th><th class="is-numeric">Balance value</th></tr></thead>
            <tbody>
                <tr>
                    <td colspan="7"><strong>Opening as at <?= e(app_date($fyStart)) ?></strong></td>
                    <td class="is-numeric"><strong><?= $fmt($itemLedger['opening']['fine_weight'], 4) ?></strong></td>
                    <td class="is-numeric"><strong><?= $fmt($itemLedger['opening']['value']) ?></strong></td>
                </tr>
                <?php foreach ($itemLedger['rows'] as $row): ?>
                    <tr>
                        <td><?= e(app_date((string) $row['txn_date'])) ?></td>
                        <td><?= e(jw_stock_txn_types()[$row['txn_type']] ?? $row['txn_type']) ?></td>
                        <td><?= e((string) ($row['ref_no'] ?? '')) ?></td>
                        <td><?= e($holderLabels[$row['holder_type']] ?? $row['holder_type']) ?><?= (int) ($row['holder_id'] ?? 0) > 0 ? ' #' . (int) $row['holder_id'] : '' ?></td>
                        <td class="is-numeric"><?= (string) $row['direction'] === 'in' ? $fmt((float) $row['fine_weight'], 4) : '' ?></td>
                        <td class="is-numeric"><?= (string) $row['direction'] === 'out' ? $fmt((float) $row['fine_weight'], 4) : '' ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['amount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['balance_fine'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['balance_value']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="7"><strong>Closing as at <?= e(app_date($todayInFy)) ?></strong></td>
                    <td class="is-numeric"><strong><?= $fmt($itemLedger['closing']['fine_weight'], 4) ?></strong></td>
                    <td class="is-numeric"><strong><?= $fmt($itemLedger['closing']['value']) ?></strong></td>
                </tr>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>

<?php elseif ($view === 'masters'): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:14px">
        <section class="mbw-card" data-collapsible>
            <div class="mbw-card-head"><h2>Item Categories (<?= count($categories) ?>)</h2></div>
            <div style="overflow-x:auto"><table>
                <thead><tr><th>Name</th><th class="is-numeric">Order</th><th class="is-numeric">Items</th><th>Status</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                    <?php if ($categories === []): ?>
                        <tr><td colspan="<?= $canEdit ? 5 : 4 ?>">No categories yet. Add the headings you want your stock and sales reports grouped under.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($categories as $c): ?>
                        <tr>
                            <td><?= e((string) $c['name']) ?></td>
                            <td class="is-numeric"><?= (int) $c['sort_order'] ?></td>
                            <td class="is-numeric"><?= (int) ($categoryUse[(string) $c['name']] ?? 0) ?></td>
                            <td><span class="mbw-pill <?= (int) $c['active'] === 1 ? 'tone-green' : 'tone-gray' ?>"><?= (int) $c['active'] === 1 ? 'Active' : 'Off' ?></span></td>
                            <?php if ($canEdit): ?>
                                <td style="white-space:nowrap">
                                    <a class="mbw-view-all" href="<?= e(url('admin/jewellery.php?view=masters&edit_category=' . (int) $c['id'])) ?>">Edit</a>
                                    <?php if ((int) ($categoryUse[(string) $c['name']] ?? 0) === 0): ?>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Remove this category?')">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="back_view" value="masters">
                                            <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
                                            <button type="submit" class="button soft" style="min-height:26px;padding:2px 8px">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ($canEdit): ?>
            <form method="post" class="workspace-form-grid" style="margin-top:12px">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_category">
                <input type="hidden" name="back_view" value="masters">
                <input type="hidden" name="category_id" value="<?= (int) ($editCategory['id'] ?? 0) ?>">
                <label>Name<input type="text" name="name" maxlength="120" value="<?= e((string) ($editCategory['name'] ?? '')) ?>" placeholder="Rings" required></label>
                <label>Sort order<input type="number" name="sort_order" step="1" value="<?= e((string) ($editCategory['sort_order'] ?? '0')) ?>"></label>
                <label class="frm-check"><input type="checkbox" name="active" <?= $editCategory === null || (int) $editCategory['active'] === 1 ? 'checked' : '' ?>> Active</label>
                <div style="grid-column:1/-1">
                    <button type="submit" class="button"><?= $editCategory ? 'Update Category' : 'Add Category' ?></button>
                    <?php if ($editCategory): ?><a class="button soft" href="<?= e(url('admin/jewellery.php?view=masters')) ?>">Cancel</a><?php endif; ?>
                </div>
            </form>
            <?php endif; ?>
        </section>

        <section class="mbw-card" data-collapsible data-collapsed>
            <div class="mbw-card-head"><h2>Weight Units (<?= count($units) ?>)</h2></div>
            <div style="overflow-x:auto"><table>
                <thead><tr><th>Code</th><th>Name</th><th class="is-numeric">Grams</th><th>Status</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach ($units as $u): ?>
                        <tr>
                            <td><?= e($u['code']) ?></td>
                            <td><?= e($u['name']) ?></td>
                            <td class="is-numeric"><?= $fmt((float) $u['grams'], 6) ?></td>
                            <td><span class="mbw-pill <?= (int) $u['active'] === 1 ? 'tone-green' : 'tone-gray' ?>"><?= (int) $u['active'] === 1 ? 'Active' : 'Off' ?></span></td>
                            <?php if ($canEdit): ?><td><a class="mbw-view-all" href="<?= e(url('admin/jewellery.php?view=masters&edit_unit=' . (int) $u['id'])) ?>">Edit</a></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ($canEdit): ?>
            <form method="post" class="workspace-form-grid" style="margin-top:12px">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_unit">
                <input type="hidden" name="back_view" value="masters">
                <input type="hidden" name="unit_id" value="<?= (int) ($editUnit['id'] ?? 0) ?>">
                <label>Code<input type="text" name="code" maxlength="20" value="<?= e((string) ($editUnit['code'] ?? '')) ?>" required></label>
                <label>Name<input type="text" name="name" maxlength="60" value="<?= e((string) ($editUnit['name'] ?? '')) ?>" required></label>
                <label>Grams per unit<input type="number" name="grams" step="0.000001" min="0.000001" value="<?= e((string) ($editUnit['grams'] ?? '1')) ?>" required></label>
                <label class="frm-check"><input type="checkbox" name="active" <?= $editUnit === null || (int) $editUnit['active'] === 1 ? 'checked' : '' ?>> Active</label>
                <div style="grid-column:1/-1">
                    <button type="submit" class="button"><?= $editUnit ? 'Update Unit' : 'Add Unit' ?></button>
                    <?php if ($editUnit): ?><a class="button soft" href="<?= e(url('admin/jewellery.php?view=masters')) ?>">Cancel</a><?php endif; ?>
                </div>
            </form>
            <?php endif; ?>
        </section>

        <section class="mbw-card" data-collapsible data-collapsed>
            <div class="mbw-card-head"><h2>Metals &amp; Stones (<?= count($metals) ?>)</h2></div>
            <div style="overflow-x:auto"><table>
                <thead><tr><th>Code</th><th>Name</th><th>Kind</th><th>Purity</th><th>Status</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach ($metals as $m): ?>
                        <tr>
                            <td><?= e($m['code']) ?></td>
                            <td><?= e($m['name']) ?></td>
                            <td><?= e(ucfirst((string) $m['metal_kind'])) ?></td>
                            <td><?= (int) $m['track_purity'] === 1 ? 'Tracked' : '—' ?></td>
                            <td><span class="mbw-pill <?= (int) $m['active'] === 1 ? 'tone-green' : 'tone-gray' ?>"><?= (int) $m['active'] === 1 ? 'Active' : 'Off' ?></span></td>
                            <?php if ($canEdit): ?><td><a class="mbw-view-all" href="<?= e(url('admin/jewellery.php?view=masters&edit_metal=' . (int) $m['id'])) ?>">Edit</a></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ($canEdit): ?>
            <form method="post" class="workspace-form-grid" style="margin-top:12px">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_metal">
                <input type="hidden" name="back_view" value="masters">
                <input type="hidden" name="metal_id" value="<?= (int) ($editMetal['id'] ?? 0) ?>">
                <label>Code<input type="text" name="code" maxlength="20" value="<?= e((string) ($editMetal['code'] ?? '')) ?>" required></label>
                <label>Name<input type="text" name="name" maxlength="80" value="<?= e((string) ($editMetal['name'] ?? '')) ?>" required></label>
                <label>Kind
                    <select name="metal_kind">
                        <?php foreach (['metal' => 'Metal', 'stone' => 'Stone', 'other' => 'Other'] as $kindKey => $kindLabel): ?>
                            <option value="<?= e($kindKey) ?>" <?= (string) ($editMetal['metal_kind'] ?? 'metal') === $kindKey ? 'selected' : '' ?>><?= e($kindLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Default unit
                    <select name="default_unit_id">
                        <option value="0">— none —</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= (int) ($editMetal['default_unit_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="frm-check"><input type="checkbox" name="track_purity" <?= $editMetal === null || (int) $editMetal['track_purity'] === 1 ? 'checked' : '' ?>> Track purity</label>
                <label class="frm-check"><input type="checkbox" name="active" <?= $editMetal === null || (int) $editMetal['active'] === 1 ? 'checked' : '' ?>> Active</label>
                <div style="grid-column:1/-1">
                    <button type="submit" class="button"><?= $editMetal ? 'Update Metal' : 'Add Metal' ?></button>
                    <?php if ($editMetal): ?><a class="button soft" href="<?= e(url('admin/jewellery.php?view=masters')) ?>">Cancel</a><?php endif; ?>
                </div>
            </form>
            <?php endif; ?>
        </section>
    </div>

    <section class="mbw-card" data-collapsible data-collapsed style="margin-top:14px">
        <div class="mbw-card-head"><h2>Purity Grades (<?= count($purities) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Metal</th><th>Code</th><th>Name</th><th class="is-numeric">Fineness /1000</th><th>Default</th><th>Status</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
                <?php foreach ($purities as $p): ?>
                    <tr>
                        <td><?= e($p['metal_name']) ?></td>
                        <td><?= e($p['code']) ?></td>
                        <td><?= e($p['name']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $p['fineness'], 4) ?></td>
                        <td><?= (int) $p['is_default'] === 1 ? '<span class="mbw-pill tone-blue">Default</span>' : '' ?></td>
                        <td><span class="mbw-pill <?= (int) $p['active'] === 1 ? 'tone-green' : 'tone-gray' ?>"><?= (int) $p['active'] === 1 ? 'Active' : 'Off' ?></span></td>
                        <?php if ($canEdit): ?><td><a class="mbw-view-all" href="<?= e(url('admin/jewellery.php?view=masters&edit_purity=' . (int) $p['id'])) ?>">Edit</a></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($canEdit): ?>
        <form method="post" class="workspace-form-grid" style="margin-top:12px">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_purity">
            <input type="hidden" name="back_view" value="masters">
            <input type="hidden" name="purity_id" value="<?= (int) ($editPurity['id'] ?? 0) ?>">
            <label>Metal
                <select name="metal_id" required>
                    <?php foreach ($metals as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" <?= (int) ($editPurity['metal_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Code<input type="text" name="code" maxlength="20" value="<?= e((string) ($editPurity['code'] ?? '')) ?>" required></label>
            <label>Name<input type="text" name="name" maxlength="80" value="<?= e((string) ($editPurity['name'] ?? '')) ?>" required></label>
            <label>Fineness (parts per 1000)<input type="number" name="fineness" step="0.0001" min="0.0001" max="1000" value="<?= e((string) ($editPurity['fineness'] ?? '1000')) ?>" required></label>
            <label class="frm-check"><input type="checkbox" name="is_default" <?= (int) ($editPurity['is_default'] ?? 0) === 1 ? 'checked' : '' ?>> Default for this metal</label>
            <label class="frm-check"><input type="checkbox" name="active" <?= $editPurity === null || (int) $editPurity['active'] === 1 ? 'checked' : '' ?>> Active</label>
            <div style="grid-column:1/-1">
                <button type="submit" class="button"><?= $editPurity ? 'Update Purity' : 'Add Purity' ?></button>
                <?php if ($editPurity): ?><a class="button soft" href="<?= e(url('admin/jewellery.php?view=masters')) ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </section>

<?php elseif ($view === 'settings'): ?>
    <section class="mbw-card" data-collapsible data-draggable>
        <div class="mbw-card-head"><h2>Module Settings</h2></div>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="back_view" value="settings">
            <label>Reporting unit
                <select name="base_unit_id">
                    <option value="0">— none —</option>
                    <?php foreach ($units as $u): ?>
                        <?php if ((int) $u['active'] !== 1) { continue; } ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) ($settings['base_unit_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Default metal
                <select name="default_metal_id">
                    <option value="0">— none —</option>
                    <?php foreach ($metals as $m): ?>
                        <?php if ((int) $m['active'] !== 1) { continue; } ?>
                        <option value="<?= (int) $m['id'] ?>" <?= (int) ($settings['default_metal_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Rate fallback
                <select name="rate_source">
                    <option value="last_known" <?= (string) ($settings['rate_source'] ?? '') === 'last_known' ? 'selected' : '' ?>>Carry the last known rate forward</option>
                    <option value="manual" <?= (string) ($settings['rate_source'] ?? '') === 'manual' ? 'selected' : '' ?>>Require a rate on the exact date</option>
                </select>
            </label>
            <label>VAT rate (%)<input type="number" name="vat_rate" step="0.01" min="0" max="100" value="<?= e((string) ($settings['vat_rate'] ?? '13.00')) ?>"></label>
            <label>Default VAT base
                <select name="default_vat_base">
                    <?php foreach (['full_value' => 'Full line value', 'making_only' => 'Making charge only', 'stone_only' => 'Stone value only'] as $baseKey => $baseLabel): ?>
                        <option value="<?= e($baseKey) ?>" <?= (string) ($settings['default_vat_base'] ?? '') === $baseKey ? 'selected' : '' ?>><?= e($baseLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Making charge basis
                <select name="making_charge_basis">
                    <?php foreach (['per_unit_weight' => 'Per unit of weight', 'percent_of_metal' => 'Percent of metal value', 'flat' => 'Flat amount'] as $basisKey => $basisLabel): ?>
                        <option value="<?= e($basisKey) ?>" <?= (string) ($settings['making_charge_basis'] ?? '') === $basisKey ? 'selected' : '' ?>><?= e($basisLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Default wastage (%)<input type="number" name="default_wastage_pct" step="0.001" min="0" max="99.999" value="<?= e((string) ($settings['default_wastage_pct'] ?? '0.000')) ?>"></label>
            <label>Weight decimals<input type="number" name="weight_precision" step="1" min="0" max="6" value="<?= (int) ($settings['weight_precision'] ?? 4) ?>"></label>
            <label>Rate decimals<input type="number" name="rate_precision" step="1" min="0" max="6" value="<?= (int) ($settings['rate_precision'] ?? 2) ?>"></label>
            <label>Amount decimals<input type="number" name="amount_precision" step="1" min="0" max="4" value="<?= (int) ($settings['amount_precision'] ?? 2) ?>"></label>
            <label>Sale prefix<input type="text" name="sale_no_prefix" maxlength="20" value="<?= e((string) ($settings['sale_no_prefix'] ?? 'JS')) ?>"></label>
            <label>Purchase prefix<input type="text" name="purchase_no_prefix" maxlength="20" value="<?= e((string) ($settings['purchase_no_prefix'] ?? 'JP')) ?>"></label>
            <label>Order prefix<input type="text" name="order_no_prefix" maxlength="20" value="<?= e((string) ($settings['order_no_prefix'] ?? 'JO')) ?>"></label>
            <label>Karigar issue prefix<input type="text" name="issue_no_prefix" maxlength="20" value="<?= e((string) ($settings['issue_no_prefix'] ?? 'JI')) ?>"></label>
            <label>Refinery prefix<input type="text" name="refinery_no_prefix" maxlength="20" value="<?= e((string) ($settings['refinery_no_prefix'] ?? 'JR')) ?>"></label>
            <?php // The "post automatically" checkbox is gone: nothing ever read it,
                  // and a setting that promises silent posting has no place in a
                  // workflow where every posting is confirmed with its mapping shown. ?>
            <label class="frm-check"><input type="checkbox" name="allow_negative_stock" <?= (int) ($settings['allow_negative_stock'] ?? 0) === 1 ? 'checked' : '' ?>> Allow negative stock</label>
            <div style="grid-column:1/-1"><button type="submit" class="button" <?= $canEdit ? '' : 'disabled' ?>>Save Settings</button></div>
        </form>
    </section>

    <section class="mbw-card" data-collapsible data-collapsed style="margin-top:14px">
        <div class="mbw-card-head"><h2>Taxes</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Seq</th><th>Code</th><th>Name</th><th>Rate</th><th>Charged on</th><th>Applies to</th><th>Documents</th><th>In force</th><th>Status</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
                <?php if ($taxRows === []): ?>
                    <tr><td colspan="10" class="frm-optional">No taxes yet. Add one below.</td></tr>
                <?php endif; ?>
                <?php foreach ($taxRows as $taxRow): ?>
                    <tr>
                        <td><?= (int) $taxRow['sequence'] ?></td>
                        <td><strong><?= e((string) $taxRow['code']) ?></strong></td>
                        <td><?= e((string) $taxRow['name']) ?>
                            <?php if ((int) $taxRow['manual_entry'] === 1): ?>
                                <span class="mbw-pill tone-amber" title="Computed for you, but the figure on the document can be punched over it">Punchable</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(rtrim(rtrim(number_format((float) $taxRow['rate'], 4), '0'), '.')) ?>%</td>
                        <td><?= e($taxBases[(string) $taxRow['base']] ?? (string) $taxRow['base']) ?></td>
                        <td><?= (string) $taxRow['applies_to'] === 'tagged' ? 'Tagged items only' : 'Every item' ?></td>
                        <td><?= e(str_replace(',', ', ', (string) $taxRow['doc_types'])) ?></td>
                        <td class="frm-optional">
                            <?= e(((string) ($taxRow['effective_from'] ?? '') ?: 'always') . ' → ' . ((string) ($taxRow['effective_to'] ?? '') ?: 'open')) ?>
                        </td>
                        <td><span class="mbw-pill <?= (int) $taxRow['active'] === 1 ? 'tone-green' : 'tone-gray' ?>"><?= (int) $taxRow['active'] === 1 ? 'Active' : 'Off' ?></span></td>
                        <?php if ($canEdit): ?>
                        <td style="white-space:nowrap">
                            <a class="button secondary" style="min-height:30px;padding:3px 10px" href="<?= e(url('admin/jewellery.php?view=settings&edit_tax=' . (int) $taxRow['id'])) ?>#tax-form">Edit</a>
                            <form method="post" style="display:inline" onsubmit="return confirm('Remove <?= e((string) $taxRow['code']) ?>? A tax already charged on documents is switched off instead, so those documents keep what they were priced with.');">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_tax">
                                <input type="hidden" name="back_view" value="settings">
                                <input type="hidden" name="tax_id" value="<?= (int) $taxRow['id'] ?>">
                                <button type="submit" class="button secondary" style="min-height:30px;padding:3px 10px">Remove</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>

        <?php if ($canEdit): ?>
        <h3 id="tax-form" style="margin:18px 0 8px"><?= $editTax ? 'Edit ' . e((string) $editTax['code']) : 'Add a tax' ?></h3>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_tax">
            <input type="hidden" name="back_view" value="settings">
            <input type="hidden" name="tax_id" value="<?= (int) ($editTax['id'] ?? 0) ?>">
            <label>Code<input type="text" name="code" maxlength="20" required value="<?= e((string) ($editTax['code'] ?? '')) ?>"></label>
            <label>Name<input type="text" name="name" maxlength="120" required value="<?= e((string) ($editTax['name'] ?? '')) ?>"></label>
            <label>Rate (%)<input type="number" name="rate" step="0.0001" min="0" value="<?= e((string) ($editTax['rate'] ?? '0')) ?>"></label>
            <label>Sequence<input type="number" name="sequence" step="1" value="<?= (int) ($editTax['sequence'] ?? 100) ?>">
            </label>
            <label>Charged on
                <select name="base">
                    <?php foreach ($taxBases as $baseKey => $baseLabel): ?>
                        <option value="<?= e($baseKey) ?>" <?= (string) ($editTax['base'] ?? 'subtotal') === $baseKey ? 'selected' : '' ?>><?= e($baseLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Applies to
                <select name="applies_to">
                    <option value="all" <?= (string) ($editTax['applies_to'] ?? 'all') === 'all' ? 'selected' : '' ?>>Every item</option>
                    <option value="tagged" <?= (string) ($editTax['applies_to'] ?? '') === 'tagged' ? 'selected' : '' ?>>Only items tagged for it</option>
                </select>
            </label>
            <?php $taxDocs = explode(',', (string) ($editTax['doc_types'] ?? 'sale')); ?>
            <label class="frm-check"><input type="checkbox" name="doc_types[]" value="sale" <?= in_array('sale', $taxDocs, true) ? 'checked' : '' ?>> Charge on sales</label>
            <label class="frm-check"><input type="checkbox" name="doc_types[]" value="purchase" <?= in_array('purchase', $taxDocs, true) ? 'checked' : '' ?>> Charge on purchases</label>
            <label>Payable ledger purpose
                <select name="output_purpose">
                    <?php foreach ($mappingPurposes as $purposeKey => [$purposeLabel]): ?>
                        <option value="<?= e($purposeKey) ?>" <?= (string) ($editTax['output_purpose'] ?? 'spt_output') === $purposeKey ? 'selected' : '' ?>><?= e($purposeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Receivable ledger purpose
                <select name="input_purpose">
                    <?php foreach ($mappingPurposes as $purposeKey => [$purposeLabel]): ?>
                        <option value="<?= e($purposeKey) ?>" <?= (string) ($editTax['input_purpose'] ?? 'spt_input') === $purposeKey ? 'selected' : '' ?>><?= e($purposeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>In force from<input type="date" name="effective_from" value="<?= e((string) ($editTax['effective_from'] ?? '')) ?>"></label>
            <label>In force to<input type="date" name="effective_to" value="<?= e((string) ($editTax['effective_to'] ?? '')) ?>">
            </label>
            <label style="grid-column:1/-1">Notes<input type="text" name="notes" maxlength="255" value="<?= e((string) ($editTax['notes'] ?? '')) ?>"></label>
            <label class="frm-check"><input type="checkbox" name="manual_entry" <?= (int) ($editTax['manual_entry'] ?? 0) === 1 ? 'checked' : '' ?>> Punched by hand on the document</label>
            <label class="frm-check"><input type="checkbox" name="active" <?= (int) ($editTax['active'] ?? 1) === 1 ? 'checked' : '' ?>> Active</label>
            <div style="grid-column:1/-1">
                <button type="submit" class="button"><?= $editTax ? 'Save Tax' : 'Add Tax' ?></button>
                <?php if ($editTax): ?>
                    <a class="button secondary" href="<?= e(url('admin/jewellery.php?view=settings')) ?>#tax-form">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </section>

    <section class="mbw-card" data-collapsible data-collapsed style="margin-top:14px">
        <div class="mbw-card-head"><h2>Posting Ledgers</h2></div>
        <?php if ($canEdit && $mappingGaps !== []): ?>
        <div class="mbw-note tone-amber" style="margin:0 0 12px">
            <p style="margin:0 0 8px"><strong><?= count($mappingGaps) ?> posting purpose<?= count($mappingGaps) === 1 ? ' is' : 's are' ?> still unmapped</strong>, so any entry needing <?= count($mappingGaps) === 1 ? 'it' : 'one of them' ?> will be refused mid-transaction: <?= e(implode(', ', array_slice($mappingGaps, 0, 6))) ?><?= count($mappingGaps) > 6 ? ' and ' . (count($mappingGaps) - 6) . ' more' : '' ?>.</p>
            <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="autocreate_mappings">
                <input type="hidden" name="back_view" value="settings">
                <button type="submit" class="button">Open and map the standard ledgers</button>
            </form>
        </div>
        <?php endif; ?>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Group</th><th>Purpose</th><th>Ledger</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
                <?php foreach ($mappingPurposes as $purposeKey => [$purposeLabel, $purposeGroup]): ?>
                    <?php $mapped = $mappings[$purposeKey] ?? null; ?>
                    <tr>
                        <td><?= e($purposeGroup) ?></td>
                        <td><?= e($purposeLabel) ?></td>
                        <td>
                            <?php if ($mapped): ?>
                                <span class="mbw-pill tone-green"><?= e(($mapped['ledger_code'] ? $mapped['ledger_code'] . ' — ' : '') . $mapped['ledger_name']) ?></span>
                            <?php else: ?>
                                <span class="mbw-pill tone-gray">Not set</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($canEdit): ?>
                        <td>
                            <form method="post" style="display:flex;gap:6px;align-items:center">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="save_mapping">
                                <input type="hidden" name="back_view" value="settings">
                                <input type="hidden" name="purpose" value="<?= e($purposeKey) ?>">
                                <select name="ledger_id">
                                    <option value="0">— not set —</option>
                                    <?php foreach ($ledgerOptions as $ledgerRow): ?>
                                        <option value="<?= (int) $ledgerRow['id'] ?>" <?= (int) ($mapped['ledger_id'] ?? 0) === (int) $ledgerRow['id'] ? 'selected' : '' ?>><?= e(($ledgerRow['code'] ? $ledgerRow['code'] . ' — ' : '') . $ledgerRow['name']) ?></option>
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
<?php endif; ?>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
