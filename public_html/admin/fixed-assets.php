<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/fixed_asset_revaluation.php';

require_login();
require_company_context();
require_permission('accounting', 'view');

accounting_module_repair_database();
fa_revaluation_repair_database();

$company = current_company();
$fiscalYear = current_fiscal_year();
$currentUser = current_user();
$companyId = (int) ($company['id'] ?? 0);
$fiscalYearId = (int) ($fiscalYear['id'] ?? 0);
$userId = (int) ($currentUser['id'] ?? 0);

$inventoryBusinessType = company_accounting_business_type($companyId);
$inventoryProfile = accounting_business_profile($inventoryBusinessType);

$assetClasses = ['ppe' => 'Property, Plant & Equipment', 'intangible' => 'Intangible (IAS 38)', 'rou' => 'Right-of-Use (IFRS 16)', 'investment_property' => 'Investment Property', 'cwip' => 'Capital WIP'];
$methods = ['straight_line' => 'Straight-line', 'diminishing_balance' => 'Diminishing balance', 'units' => 'Units of production'];

$ledgerStmt = db()->prepare("SELECT id, code, name, type FROM ledgers WHERE company_id = :cid AND status = 'active' ORDER BY code ASC");
$ledgerStmt->execute(['cid' => $companyId]);
$ledgers = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

$activeCategoriesStmt = db()->prepare('SELECT * FROM asset_categories WHERE company_id = :cid AND is_active = 1 ORDER BY name ASC');
$activeCategoriesStmt->execute(['cid' => $companyId]);
$activeCategories = $activeCategoriesStmt->fetchAll(PDO::FETCH_ASSOC);

/** Asset scoped to the current company, or null. */
function fa_company_asset(int $assetId, int $companyId): ?array
{
    if ($assetId <= 0) {
        return null;
    }
    $s = db()->prepare('SELECT * FROM fixed_assets WHERE id = :id AND company_id = :cid LIMIT 1');
    $s->execute(['id' => $assetId, 'cid' => $companyId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Lease liability scoped to the current company, or null. */
function fa_company_lease(int $leaseId, int $companyId): ?array
{
    if ($leaseId <= 0) {
        return null;
    }
    $s = db()->prepare('SELECT * FROM lease_liabilities WHERE id = :id AND company_id = :cid LIMIT 1');
    $s->execute(['id' => $leaseId, 'cid' => $companyId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_asset') {
        require_permission('accounting', 'create');
        $code = strtoupper(trim((string) ($_POST['asset_code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $class = (string) ($_POST['asset_class'] ?? 'ppe');
        $method = (string) ($_POST['depreciation_method'] ?? 'straight_line');
        $cost = max(0.0, round((float) ($_POST['cost'] ?? 0), 2));
        $residual = max(0.0, round((float) ($_POST['residual_value'] ?? 0), 2));
        $lifeMonths = max(0, (int) ($_POST['useful_life_months'] ?? 0));
        $availableDate = trim((string) ($_POST['available_for_use_date'] ?? '')) ?: null;
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        if ($categoryId > 0) {
            $catCheck = db()->prepare('SELECT COUNT(*) FROM asset_categories WHERE id = :id AND company_id = :cid');
            $catCheck->execute(['id' => $categoryId, 'cid' => $companyId]);
            if ((int) $catCheck->fetchColumn() === 0) {
                $categoryId = 0;
            }
        }
        if ($code === '' || $name === '' || !isset($assetClasses[$class]) || !isset($methods[$method])) {
            flash('error', 'Asset code, name, class and method are required.');
            redirect('admin/fixed-assets.php');
        }
        try {
            db()->prepare('
                INSERT INTO fixed_assets
                    (company_id, category_id, asset_code, name, asset_class, cost, residual_value, useful_life_months,
                     depreciation_method, available_for_use_date, depreciation_start_date, carrying_amount, status, created_by)
                VALUES (:cid, :category_id, :code, :name, :class, :cost, :residual, :life, :method, :avail, :dep_start, :cost2, :status, :uid)
            ')->execute([
                'cid' => $companyId, 'category_id' => $categoryId > 0 ? $categoryId : null, 'code' => $code, 'name' => $name, 'class' => $class,
                'cost' => $cost, 'residual' => $residual, 'life' => $lifeMonths, 'method' => $method,
                'avail' => $availableDate, 'dep_start' => $availableDate, 'cost2' => $cost,
                'status' => $availableDate ? 'active' : 'draft', 'uid' => $userId,
            ]);
            $assetId = (int) db()->lastInsertId();
            log_activity('fixed_asset', $assetId, 'created', 'Fixed asset registered.', $userId);

            // Optional acquisition voucher when both legs are mapped.
            // Work under construction accumulates in the CWIP ledger, not PPE —
            // it only lands in PPE when capitalize_cwip reclassifies it. Debiting
            // ppe_cost here would double-debit PPE at capitalization and drive
            // the CWIP ledger negative (it would be credited having never been
            // debited).
            $costPurpose = $class === 'cwip' ? 'cwip' : 'ppe_cost';
            $costLedger = fa_resolve_mapping($companyId, $costPurpose, $assetId);
            $clearingLedger = fa_resolve_mapping($companyId, 'acquisition_clearing', $assetId);
            if ($cost > 0 && $costLedger && $clearingLedger) {
                create_voucher_with_entries([
                    'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                    'voucher_no' => 'FA-ACQ-' . $code, 'voucher_type' => 'journal',
                    'voucher_date' => $availableDate ?: date('Y-m-d'),
                    'source_type' => 'fixed_asset_acquisition', 'source_id' => $assetId,
                    'total_amount' => $cost, 'narration' => 'Acquisition of ' . $name . ' (' . $code . ').',
                    'status' => 'posted', 'posted_by' => $userId,
                ], [
                    ['ledger_id' => (int) $costLedger['id'], 'entry_type' => 'debit', 'amount' => $cost],
                    ['ledger_id' => (int) $clearingLedger['id'], 'entry_type' => 'credit', 'amount' => $cost],
                ]);
                flash('success', 'Asset ' . $code . ' registered and acquisition voucher FA-ACQ-' . $code . ' posted.');
            } else {
                $costLabel = fa_mapping_purposes()[$costPurpose]['label'] ?? $costPurpose;
                flash('success', 'Asset ' . $code . ' registered.' . (($costLedger && $clearingLedger) ? '' : ' Map ' . $costLabel . ' + Acquisition Clearing to auto-post the acquisition voucher.'));
            }
        } catch (Throwable $e) {
            flash('error', (string) $e->getCode() === '23000' ? 'Asset code ' . $code . ' already exists.' : 'Could not register asset: ' . $e->getMessage());
        }
        redirect('admin/fixed-assets.php');
    }

    if ($action === 'post_depreciation') {
        require_permission('accounting', 'post');
        $asset = fa_company_asset((int) ($_POST['asset_id'] ?? 0), $companyId);
        if (!$asset) {
            flash('error', 'Asset not found for this company.');
            redirect('admin/fixed-assets.php');
        }
        if ((string) $asset['status'] === 'held_for_sale') {
            flash('error', 'Depreciation stops while an asset is classified as held for sale (IFRS 5).');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        $charge = fa_asset_monthly_charge($asset);
        if ($charge <= 0) {
            flash('error', 'Nothing to depreciate — the asset is fully depreciated or has no useful life set.');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        // Posting is BLOCKED unless both ledgers resolve (mapping-before-post).
        $expLedger = fa_resolve_mapping($companyId, 'depreciation_expense', (int) $asset['id']);
        $accLedger = fa_resolve_mapping($companyId, 'accumulated_depreciation', (int) $asset['id']);
        if (!$expLedger || !$accLedger) {
            flash('error', 'Cannot post depreciation: map Depreciation Expense and Accumulated Depreciation on the Ledger Mapping tab first.');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        try {
            db()->beginTransaction();
            $periodStmt = db()->prepare('SELECT COALESCE(MAX(period_no), 0) + 1 FROM asset_depreciation_schedule WHERE asset_id = :aid');
            $periodStmt->execute(['aid' => (int) $asset['id']]);
            $periodNo = (int) $periodStmt->fetchColumn();
            $newAccum = round((float) $asset['accumulated_depreciation'] + $charge, 2);
            $newCarrying = max((float) $asset['residual_value'], round((float) $asset['carrying_amount'] - $charge, 2));
            $today = date('Y-m-d');

            $voucherId = create_voucher_with_entries([
                'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                'voucher_no' => 'FA-DEP-' . $asset['asset_code'] . '-' . str_pad((string) $periodNo, 3, '0', STR_PAD_LEFT),
                'voucher_type' => 'journal', 'voucher_date' => $today,
                'source_type' => 'asset_depreciation', 'source_id' => null,
                'total_amount' => $charge,
                'narration' => 'Depreciation period ' . $periodNo . ' — ' . $asset['name'] . ' (' . $asset['asset_code'] . ').',
                'status' => 'posted', 'posted_by' => $userId,
            ], [
                ['ledger_id' => (int) $expLedger['id'], 'entry_type' => 'debit', 'amount' => $charge],
                ['ledger_id' => (int) $accLedger['id'], 'entry_type' => 'credit', 'amount' => $charge],
            ]);

            db()->prepare('INSERT INTO asset_depreciation_schedule (company_id, asset_id, period_no, period_date, depreciation, accumulated, carrying, voucher_id, posted)
                VALUES (:cid, :aid, :pno, :pdate, :dep, :acc, :carry, :vid, 1)')
                ->execute(['cid' => $companyId, 'aid' => (int) $asset['id'], 'pno' => $periodNo, 'pdate' => $today, 'dep' => $charge, 'acc' => $newAccum, 'carry' => $newCarrying, 'vid' => $voucherId ?: null]);

            $status = $newCarrying <= (float) $asset['residual_value'] + 0.005 ? 'fully_depreciated' : 'active';
            db()->prepare('UPDATE fixed_assets SET accumulated_depreciation = :acc, carrying_amount = :carry, status = :st WHERE id = :id AND company_id = :cid')
                ->execute(['acc' => $newAccum, 'carry' => $newCarrying, 'st' => $status, 'id' => (int) $asset['id'], 'cid' => $companyId]);
            db()->commit();
            security_event('asset_depreciation_posted', 'success', 'Depreciation posted for asset #' . (int) $asset['id'] . '.', $companyId, $userId);
            log_activity('fixed_asset', (int) $asset['id'], 'depreciation_posted', 'Depreciation period ' . $periodNo . ' posted (' . number_format($charge, 2) . ').', $userId);
            flash('success', 'Depreciation posted for ' . $asset['asset_code'] . ': ' . site_currency_symbol() . number_format($charge, 2) . ' (Dr Depreciation expense / Cr Accumulated depreciation). Carrying amount now ' . site_currency_symbol() . number_format($newCarrying, 2) . '.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) { db()->rollBack(); }
            flash('error', 'Could not post depreciation: ' . $e->getMessage());
        }
        redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
    }

    if ($action === 'post_impairment') {
        require_permission('accounting', 'post');
        $asset = fa_company_asset((int) ($_POST['asset_id'] ?? 0), $companyId);
        if (!$asset) { flash('error', 'Asset not found.'); redirect('admin/fixed-assets.php'); }
        $fvlcd = max(0.0, round((float) ($_POST['fvlcd'] ?? 0), 2));
        $viu = max(0.0, round((float) ($_POST['value_in_use'] ?? 0), 2));
        $carrying = (float) $asset['carrying_amount'];
        $imp = fa_impairment($carrying, $fvlcd, $viu);
        if ($imp['impairment'] <= 0) {
            flash('error', 'No impairment: recoverable amount (' . site_currency_symbol() . number_format($imp['recoverable'], 2) . ') is at or above the carrying amount.');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        $lossL = fa_resolve_mapping($companyId, 'impairment_loss', (int) $asset['id']);
        $accL = fa_resolve_mapping($companyId, 'accumulated_impairment', (int) $asset['id']);
        if (!$lossL || !$accL) {
            flash('error', 'Map Impairment Loss and Accumulated Impairment before posting.');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        try {
            db()->beginTransaction();
            // Record the impairment EVENT first, then key its voucher on that row.
            // vouchers has UNIQUE(source_type, source_id): keying on the asset id
            // would cap an asset at one impairment for its whole life, but IAS 36
            // requires testing every reporting date. The event id is the correct
            // per-posting key (same pattern inventory uses with its movement id).
            db()->prepare('INSERT INTO asset_impairments (company_id, asset_id, test_date, kind, carrying_amount, fair_value_less_costs, value_in_use, recoverable_amount, impairment_loss, created_by)
                VALUES (:cid,:aid,:d,\'impairment\',:carry,:fv,:viu,:rec,:imp,:uid)')
                ->execute(['cid' => $companyId, 'aid' => (int) $asset['id'], 'd' => date('Y-m-d'), 'carry' => $carrying, 'fv' => $fvlcd, 'viu' => $viu, 'rec' => $imp['recoverable'], 'imp' => $imp['impairment'], 'uid' => $userId]);
            $eventId = (int) db()->lastInsertId();
            $vid = create_voucher_with_entries([
                'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                'voucher_no' => 'FA-IMP-' . $asset['asset_code'] . '-' . $eventId, 'voucher_type' => 'journal', 'voucher_date' => date('Y-m-d'),
                'source_type' => 'asset_impairment', 'source_id' => $eventId, 'total_amount' => $imp['impairment'],
                'narration' => 'Impairment of ' . $asset['name'] . ' (IAS 36).', 'status' => 'posted', 'posted_by' => $userId,
            ], [
                ['ledger_id' => (int) $lossL['id'], 'entry_type' => 'debit', 'amount' => $imp['impairment']],
                ['ledger_id' => (int) $accL['id'], 'entry_type' => 'credit', 'amount' => $imp['impairment']],
            ]);
            db()->prepare('UPDATE asset_impairments SET voucher_id = :vid WHERE id = :id')
                ->execute(['vid' => $vid ?: null, 'id' => $eventId]);
            db()->prepare('UPDATE fixed_assets SET accumulated_impairment = accumulated_impairment + :imp, carrying_amount = :carry WHERE id = :id AND company_id = :cid')
                ->execute(['imp' => $imp['impairment'], 'carry' => $imp['revised_carrying'], 'id' => (int) $asset['id'], 'cid' => $companyId]);
            db()->commit();
            security_event('asset_impairment_posted', 'success', 'Impairment posted for asset #' . (int) $asset['id'] . '.', $companyId, $userId);
            log_activity('fixed_asset', (int) $asset['id'], 'impairment', 'Impairment ' . number_format($imp['impairment'], 2) . ' posted.', $userId);
            flash('success', 'Impairment posted: ' . site_currency_symbol() . number_format($imp['impairment'], 2) . ' (Dr Impairment loss / Cr Accumulated impairment). Carrying amount now ' . site_currency_symbol() . number_format($imp['revised_carrying'], 2) . '.');
        } catch (Throwable $e) { if (db()->inTransaction()) { db()->rollBack(); } flash('error', 'Could not post impairment: ' . $e->getMessage()); }
        redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
    }

    if ($action === 'reverse_impairment') {
        require_permission('accounting', 'post');
        $asset = fa_company_asset((int) ($_POST['asset_id'] ?? 0), $companyId);
        if (!$asset) { flash('error', 'Asset not found.'); redirect('admin/fixed-assets.php'); }
        $fvlcd = max(0.0, round((float) ($_POST['fvlcd'] ?? 0), 2));
        $viu = max(0.0, round((float) ($_POST['value_in_use'] ?? 0), 2));
        $recoverable = max($fvlcd, $viu);
        $carrying = (float) $asset['carrying_amount'];
        $accumulatedImpairment = (float) $asset['accumulated_impairment'];
        // You can only reverse an impairment that was actually recognised. Without
        // this guard any asset whose carrying sits below its depreciated cost for
        // some OTHER reason — a revaluation decrease, for instance, which lowers
        // carrying_amount but never touches accumulated_impairment — would produce
        // a positive "reversal", crediting reversal income out of thin air and
        // driving the accumulated-impairment contra-asset into a debit balance.
        if ($accumulatedImpairment <= 0) {
            flash('error', 'This asset carries no recognised impairment, so there is nothing to reverse (IAS 36.114).');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        $carryingHadNoImpairment = (float) $asset['cost'] + (float) $asset['directly_attributable_cost'] + (float) $asset['restoration_provision'] - (float) $asset['accumulated_depreciation'];
        $rev = fa_impairment_reversal($carrying, $recoverable, $carryingHadNoImpairment);
        // A reversal can never exceed the impairment actually recognised, on top of
        // the IAS 36.117 depreciated-cost ceiling the engine already applies.
        $reversalAmount = round(min($rev['reversal'], $accumulatedImpairment), 2);
        if ($reversalAmount <= 0) {
            flash('error', 'No reversal available — the recoverable amount does not exceed the current carrying amount.');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        $revisedCarrying = round($carrying + $reversalAmount, 2);
        $accL = fa_resolve_mapping($companyId, 'accumulated_impairment', (int) $asset['id']);
        $incomeL = fa_resolve_mapping($companyId, 'impairment_reversal_income', (int) $asset['id']);
        if (!$accL || !$incomeL) {
            flash('error', 'Map Accumulated Impairment and Impairment Reversal Income before posting a reversal.');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        try {
            db()->beginTransaction();
            // Event row first, voucher keyed on it — see post_impairment above:
            // an asset may be reversed more than once over its life, so the
            // voucher cannot be keyed on the asset id.
            db()->prepare('INSERT INTO asset_impairments (company_id, asset_id, test_date, kind, carrying_amount, fair_value_less_costs, value_in_use, recoverable_amount, reversal, created_by)
                VALUES (:cid,:aid,:d,\'reversal\',:carry,:fv,:viu,:rec,:rev,:uid)')
                ->execute(['cid' => $companyId, 'aid' => (int) $asset['id'], 'd' => date('Y-m-d'), 'carry' => $carrying, 'fv' => $fvlcd, 'viu' => $viu, 'rec' => $recoverable, 'rev' => $reversalAmount, 'uid' => $userId]);
            $eventId = (int) db()->lastInsertId();
            $vid = create_voucher_with_entries([
                'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                'voucher_no' => 'FA-IMPR-' . $asset['asset_code'] . '-' . $eventId, 'voucher_type' => 'journal', 'voucher_date' => date('Y-m-d'),
                'source_type' => 'asset_impairment_reversal', 'source_id' => $eventId, 'total_amount' => $reversalAmount,
                'narration' => 'Impairment reversal on ' . $asset['name'] . ' (IAS 36.117).', 'status' => 'posted', 'posted_by' => $userId,
            ], [
                ['ledger_id' => (int) $accL['id'], 'entry_type' => 'debit', 'amount' => $reversalAmount],
                ['ledger_id' => (int) $incomeL['id'], 'entry_type' => 'credit', 'amount' => $reversalAmount],
            ]);
            db()->prepare('UPDATE asset_impairments SET voucher_id = :vid WHERE id = :id')
                ->execute(['vid' => $vid ?: null, 'id' => $eventId]);
            db()->prepare('UPDATE fixed_assets SET accumulated_impairment = accumulated_impairment - :rev, carrying_amount = :carry WHERE id = :id AND company_id = :cid')
                ->execute(['rev' => $reversalAmount, 'carry' => $revisedCarrying, 'id' => (int) $asset['id'], 'cid' => $companyId]);
            db()->commit();
            security_event('asset_impairment_reversed', 'success', 'Impairment reversal ' . number_format($reversalAmount, 2) . ' posted for asset #' . (int) $asset['id'] . '.', $companyId, $userId);
            log_activity('fixed_asset', (int) $asset['id'], 'impairment_reversed', 'Impairment reversal ' . number_format($reversalAmount, 2) . ' posted.', $userId);
            flash('success', 'Impairment reversal posted: ' . site_currency_symbol() . number_format($reversalAmount, 2) . ' (Dr Accumulated impairment / Cr Impairment reversal income). Carrying amount now ' . site_currency_symbol() . number_format($revisedCarrying, 2) . '.');
        } catch (Throwable $e) { if (db()->inTransaction()) { db()->rollBack(); } flash('error', 'Could not reverse impairment: ' . $e->getMessage()); }
        redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
    }

    if ($action === 'classify_held_for_sale') {
        require_permission('accounting', 'edit');
        $asset = fa_company_asset((int) ($_POST['asset_id'] ?? 0), $companyId);
        if (!$asset) { flash('error', 'Asset not found.'); redirect('admin/fixed-assets.php'); }
        $fv = max(0.0, round((float) ($_POST['fair_value'] ?? 0), 2));
        $costs = max(0.0, round((float) ($_POST['costs_to_sell'] ?? 0), 2));
        $hfs = fa_held_for_sale((float) $asset['carrying_amount'], $fv, $costs);
        try {
            db()->beginTransaction();
            $vid = 0;
            if ($hfs['impairment'] > 0) {
                $lossL = fa_resolve_mapping($companyId, 'impairment_loss', (int) $asset['id']);
                $accL = fa_resolve_mapping($companyId, 'accumulated_impairment', (int) $asset['id']);
                if ($lossL && $accL) {
                    $vid = create_voucher_with_entries([
                        'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                        'voucher_no' => 'FA-HFS-' . $asset['asset_code'], 'voucher_type' => 'journal', 'voucher_date' => date('Y-m-d'),
                        'source_type' => 'asset_held_for_sale', 'source_id' => (int) $asset['id'], 'total_amount' => $hfs['impairment'],
                        'narration' => 'Held-for-sale write-down of ' . $asset['name'] . ' (IFRS 5).', 'status' => 'posted', 'posted_by' => $userId,
                    ], [
                        ['ledger_id' => (int) $lossL['id'], 'entry_type' => 'debit', 'amount' => $hfs['impairment']],
                        ['ledger_id' => (int) $accL['id'], 'entry_type' => 'credit', 'amount' => $hfs['impairment']],
                    ]);
                }
            }
            db()->prepare('INSERT INTO asset_impairments (company_id, asset_id, test_date, kind, carrying_amount, fair_value_less_costs, recoverable_amount, impairment_loss, voucher_id, created_by)
                VALUES (:cid,:aid,:d,\'held_for_sale\',:carry,:fvlcs,:rec,:imp,:vid,:uid)')
                ->execute(['cid' => $companyId, 'aid' => (int) $asset['id'], 'd' => date('Y-m-d'), 'carry' => (float) $asset['carrying_amount'], 'fvlcs' => $hfs['fvlcs'], 'rec' => $hfs['measured'], 'imp' => $hfs['impairment'], 'vid' => $vid ?: null, 'uid' => $userId]);
            db()->prepare('UPDATE fixed_assets SET status = \'held_for_sale\', carrying_amount = :carry, accumulated_impairment = accumulated_impairment + :imp WHERE id = :id AND company_id = :cid')
                ->execute(['carry' => $hfs['measured'], 'imp' => $hfs['impairment'], 'id' => (int) $asset['id'], 'cid' => $companyId]);
            db()->commit();
            security_event('asset_held_for_sale', 'success', 'Asset #' . (int) $asset['id'] . ' classified held-for-sale.', $companyId, $userId);
            log_activity('fixed_asset', (int) $asset['id'], 'held_for_sale', 'Classified held for sale.', $userId);
            flash('success', 'Classified as held for sale at ' . site_currency_symbol() . number_format($hfs['measured'], 2) . ' (lower of carrying and FV less costs to sell). Depreciation stops (IFRS 5).' . ($hfs['impairment'] > 0 ? ' Impairment ' . site_currency_symbol() . number_format($hfs['impairment'], 2) . ' posted.' : ''));
        } catch (Throwable $e) { if (db()->inTransaction()) { db()->rollBack(); } flash('error', 'Could not classify: ' . $e->getMessage()); }
        redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
    }

    if ($action === 'dispose_asset') {
        require_permission('accounting', 'post');
        $asset = fa_company_asset((int) ($_POST['asset_id'] ?? 0), $companyId);
        if (!$asset) { flash('error', 'Asset not found.'); redirect('admin/fixed-assets.php'); }
        $proceeds = max(0.0, round((float) ($_POST['proceeds'] ?? 0), 2));
        $cost = (float) $asset['cost'];
        $accumDep = (float) $asset['accumulated_depreciation'];
        $accumImp = (float) $asset['accumulated_impairment'];
        $carrying = round($cost - $accumDep - $accumImp, 2);
        $gainLoss = round($proceeds - $carrying, 2); // + gain, - loss
        // Derecognition: reverse cost + accumulated, recognise proceeds + gain/loss.
        $costL = fa_resolve_mapping($companyId, 'ppe_cost', (int) $asset['id']);
        $accDepL = fa_resolve_mapping($companyId, 'accumulated_depreciation', (int) $asset['id']);
        $procL = fa_resolve_mapping($companyId, 'disposal_clearing', (int) $asset['id']);
        if (!$costL || !$accDepL || !$procL) {
            flash('error', 'Map PPE Cost, Accumulated Depreciation and Disposal Clearing before disposing.');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        try {
            db()->beginTransaction();
            $entries = [];
            if ($accumDep + $accumImp > 0) { $entries[] = ['ledger_id' => (int) $accDepL['id'], 'entry_type' => 'debit', 'amount' => round($accumDep + $accumImp, 2)]; }
            if ($proceeds > 0) { $entries[] = ['ledger_id' => (int) $procL['id'], 'entry_type' => 'debit', 'amount' => $proceeds]; }
            $entries[] = ['ledger_id' => (int) $costL['id'], 'entry_type' => 'credit', 'amount' => $cost];
            if ($gainLoss > 0) {
                $gl = fa_resolve_mapping($companyId, 'gain_on_disposal', (int) $asset['id']);
                if (!$gl) { throw new RuntimeException('Map Gain on Disposal to record this gain.'); }
                $entries[] = ['ledger_id' => (int) $gl['id'], 'entry_type' => 'credit', 'amount' => $gainLoss];
            } elseif ($gainLoss < 0) {
                $ll = fa_resolve_mapping($companyId, 'loss_on_disposal', (int) $asset['id']);
                if (!$ll) { throw new RuntimeException('Map Loss on Disposal to record this loss.'); }
                $entries[] = ['ledger_id' => (int) $ll['id'], 'entry_type' => 'debit', 'amount' => -$gainLoss];
            }
            $vid = create_voucher_with_entries([
                'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                'voucher_no' => 'FA-DISP-' . $asset['asset_code'], 'voucher_type' => 'journal', 'voucher_date' => date('Y-m-d'),
                'source_type' => 'asset_disposal', 'source_id' => (int) $asset['id'], 'total_amount' => $cost,
                'narration' => 'Disposal of ' . $asset['name'] . ' — ' . ($gainLoss >= 0 ? 'gain ' : 'loss ') . number_format(abs($gainLoss), 2) . '.',
                'status' => 'posted', 'posted_by' => $userId,
            ], $entries);
            db()->prepare('UPDATE fixed_assets SET status = \'disposed\', disposed_on = :d, carrying_amount = 0 WHERE id = :id AND company_id = :cid')
                ->execute(['d' => date('Y-m-d'), 'id' => (int) $asset['id'], 'cid' => $companyId]);
            db()->commit();
            security_event('asset_disposed', 'success', 'Asset #' . (int) $asset['id'] . ' disposed.', $companyId, $userId);
            log_activity('fixed_asset', (int) $asset['id'], 'disposed', 'Asset disposed (' . ($gainLoss >= 0 ? 'gain' : 'loss') . ' ' . number_format(abs($gainLoss), 2) . ').', $userId);
            flash('success', 'Asset disposed. Proceeds ' . site_currency_symbol() . number_format($proceeds, 2) . ', carrying ' . site_currency_symbol() . number_format($carrying, 2) . ', ' . ($gainLoss >= 0 ? 'gain ' : 'loss ') . site_currency_symbol() . number_format(abs($gainLoss), 2) . '.');
        } catch (Throwable $e) { if (db()->inTransaction()) { db()->rollBack(); } flash('error', 'Could not dispose: ' . $e->getMessage()); }
        redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
    }

    if ($action === 'revalue_asset') {
        flash('error', 'Direct revaluation is disabled. Use a class-wide Revaluation Batch so the selected asset class is treated consistently.');
        redirect('admin/fixed-assets.php?view=revaluation&asset_id=' . (int) ($_POST['asset_id'] ?? 0));
    }

    if ($action === 'create_revaluation_batch') {
        require_permission('accounting', 'create');

        $assetClass = (string) ($_POST['asset_class'] ?? '');
        $allowedRevaluationClasses = ['ppe', 'intangible', 'investment_property'];
        $revaluationDate = trim((string) ($_POST['revaluation_date'] ?? ''));
        $valuerName = trim((string) ($_POST['valuer_name'] ?? ''));
        $valuerReference = trim((string) ($_POST['valuer_reference'] ?? ''));
        $valuationMethod = (string) ($_POST['valuation_method'] ?? '');
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if (
            !in_array($assetClass, $allowedRevaluationClasses, true)
            || $revaluationDate === ''
            || $valuerName === ''
            || !isset(fa_revaluation_methods()[$valuationMethod])
            || $reason === ''
        ) {
            flash('error', 'Asset class, date, valuer, valuation method and reason are required.');
            redirect('admin/fixed-assets.php?view=revaluation');
        }

        $eligibleAssets = fa_revaluation_eligible_assets($companyId, $assetClass);
        if ($eligibleAssets === []) {
            flash('error', 'No active assets were found in the selected class.');
            redirect('admin/fixed-assets.php?view=revaluation');
        }

        try {
            $reportPath = fa_store_revaluation_report($_FILES['valuation_report'] ?? [], $companyId);
            if (!$reportPath) {
                throw new RuntimeException('A valuation report is required.');
            }

            db()->beginTransaction();

            $temporaryBatchNo = 'TMP-' . bin2hex(random_bytes(8));
            db()->prepare('
                INSERT INTO asset_revaluation_batches
                    (company_id, batch_no, asset_class, revaluation_date,
                     valuer_name, valuer_reference, valuation_method, reason,
                     report_path, status, created_by)
                VALUES
                    (:cid, :batch_no, :asset_class, :revaluation_date,
                     :valuer_name, :valuer_reference, :valuation_method, :reason,
                     :report_path, \'draft\', :created_by)
            ')->execute([
                'cid' => $companyId,
                'batch_no' => $temporaryBatchNo,
                'asset_class' => $assetClass,
                'revaluation_date' => $revaluationDate,
                'valuer_name' => $valuerName,
                'valuer_reference' => $valuerReference !== '' ? $valuerReference : null,
                'valuation_method' => $valuationMethod,
                'reason' => $reason,
                'report_path' => $reportPath,
                'created_by' => $userId,
            ]);

            $batchId = (int) db()->lastInsertId();
            $batchNo = 'REV-' . date('Y', strtotime($revaluationDate)) .
                '-C' . $companyId . '-' . str_pad((string) $batchId, 5, '0', STR_PAD_LEFT);

            db()->prepare('
                UPDATE asset_revaluation_batches
                SET batch_no = :batch_no
                WHERE id = :id AND company_id = :cid
            ')->execute([
                'batch_no' => $batchNo,
                'id' => $batchId,
                'cid' => $companyId,
            ]);

            $lineStmt = db()->prepare('
                INSERT INTO asset_revaluation_lines
                    (batch_id, company_id, asset_id, previous_carrying_value,
                     new_fair_value, revised_useful_life_months,
                     revised_residual_value, remarks)
                VALUES
                    (:batch_id, :company_id, :asset_id, :previous_carrying_value,
                     :new_fair_value, :revised_useful_life_months,
                     :revised_residual_value, NULL)
            ');

            foreach ($eligibleAssets as $assetRow) {
                $lineStmt->execute([
                    'batch_id' => $batchId,
                    'company_id' => $companyId,
                    'asset_id' => (int) $assetRow['id'],
                    'previous_carrying_value' => (float) $assetRow['carrying_amount'],
                    'new_fair_value' => (float) $assetRow['carrying_amount'],
                    'revised_useful_life_months' => max(1, (int) $assetRow['useful_life_months']),
                    'revised_residual_value' => max(0.0, (float) $assetRow['residual_value']),
                ]);
            }

            db()->commit();

            security_event(
                'asset_revaluation_batch_created',
                'success',
                'Created revaluation batch ' . $batchNo . '.',
                $companyId,
                $userId
            );
            log_activity(
                'asset_revaluation_batch',
                $batchId,
                'created',
                'Created class-wide revaluation batch ' . $batchNo . '.',
                $userId
            );
            flash('success', 'Revaluation batch ' . $batchNo . ' created with ' . count($eligibleAssets) . ' assets.');
            redirect('admin/fixed-assets.php?view=revaluation&batch_id=' . $batchId);
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not create revaluation batch: ' . $e->getMessage());
            redirect('admin/fixed-assets.php?view=revaluation');
        }
    }

    if ($action === 'save_revaluation_batch') {
        require_permission('accounting', 'edit');

        $batchId = (int) ($_POST['batch_id'] ?? 0);
        $batch = fa_revaluation_batch($batchId, $companyId);
        if (!$batch || (string) $batch['status'] !== 'draft') {
            flash('error', 'Only a draft revaluation batch can be edited.');
            redirect('admin/fixed-assets.php?view=revaluation');
        }

        $revaluationDate = trim((string) ($_POST['revaluation_date'] ?? ''));
        $valuerName = trim((string) ($_POST['valuer_name'] ?? ''));
        $valuerReference = trim((string) ($_POST['valuer_reference'] ?? ''));
        $valuationMethod = (string) ($_POST['valuation_method'] ?? '');
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if (
            $revaluationDate === ''
            || $valuerName === ''
            || !isset(fa_revaluation_methods()[$valuationMethod])
            || $reason === ''
        ) {
            flash('error', 'Date, valuer, valuation method and reason are required.');
            redirect('admin/fixed-assets.php?view=revaluation&batch_id=' . $batchId);
        }

        try {
            db()->beginTransaction();

            $reportPath = (string) $batch['report_path'];
            if ((int) (($_FILES['valuation_report']['error'] ?? UPLOAD_ERR_NO_FILE)) !== UPLOAD_ERR_NO_FILE) {
                $newReportPath = fa_store_revaluation_report($_FILES['valuation_report'], $companyId);
                if ($newReportPath) {
                    $reportPath = $newReportPath;
                }
            }

            db()->prepare('
                UPDATE asset_revaluation_batches
                SET revaluation_date = :revaluation_date,
                    valuer_name = :valuer_name,
                    valuer_reference = :valuer_reference,
                    valuation_method = :valuation_method,
                    reason = :reason,
                    report_path = :report_path
                WHERE id = :id AND company_id = :cid AND status = \'draft\'
            ')->execute([
                'revaluation_date' => $revaluationDate,
                'valuer_name' => $valuerName,
                'valuer_reference' => $valuerReference !== '' ? $valuerReference : null,
                'valuation_method' => $valuationMethod,
                'reason' => $reason,
                'report_path' => $reportPath,
                'id' => $batchId,
                'cid' => $companyId,
            ]);

            $lineInput = $_POST['line'] ?? [];
            if (!is_array($lineInput)) {
                $lineInput = [];
            }

            $lineStmt = db()->prepare('
                UPDATE asset_revaluation_lines
                SET new_fair_value = :new_fair_value,
                    increase_decrease = :increase_decrease,
                    revised_useful_life_months = :revised_useful_life_months,
                    revised_residual_value = :revised_residual_value,
                    remarks = :remarks
                WHERE id = :id AND batch_id = :batch_id AND company_id = :cid
            ');

            foreach ($lineInput as $lineIdRaw => $values) {
                $lineId = (int) $lineIdRaw;
                if ($lineId <= 0 || !is_array($values)) {
                    continue;
                }

                $newValue = max(0.0, round((float) ($values['new_fair_value'] ?? 0), 2));
                $previousValue = round((float) ($values['previous_carrying_value'] ?? 0), 2);
                $revisedLife = max(1, (int) ($values['revised_useful_life_months'] ?? 1));
                $revisedResidual = max(0.0, round((float) ($values['revised_residual_value'] ?? 0), 2));
                $remarks = trim((string) ($values['remarks'] ?? ''));

                if ($revisedResidual > $newValue) {
                    throw new RuntimeException('Residual value cannot exceed fair value.');
                }

                $lineStmt->execute([
                    'new_fair_value' => $newValue,
                    'increase_decrease' => round($newValue - $previousValue, 2),
                    'revised_useful_life_months' => $revisedLife,
                    'revised_residual_value' => $revisedResidual,
                    'remarks' => $remarks !== '' ? $remarks : null,
                    'id' => $lineId,
                    'batch_id' => $batchId,
                    'cid' => $companyId,
                ]);
            }

            db()->commit();
            log_activity('asset_revaluation_batch', $batchId, 'updated', 'Draft revaluation batch updated.', $userId);
            flash('success', 'Revaluation batch saved.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not save revaluation batch: ' . $e->getMessage());
        }

        redirect('admin/fixed-assets.php?view=revaluation&batch_id=' . $batchId);
    }

    if ($action === 'submit_revaluation_batch') {
        require_permission('accounting', 'edit');

        $batchId = (int) ($_POST['batch_id'] ?? 0);
        $batch = fa_revaluation_batch($batchId, $companyId);
        $lines = $batch ? fa_revaluation_lines($batchId, $companyId) : [];

        if (!$batch || (string) $batch['status'] !== 'draft' || $lines === []) {
            flash('error', 'Only a complete draft batch can be submitted.');
            redirect('admin/fixed-assets.php?view=revaluation');
        }

        foreach ($lines as $line) {
            if (
                (float) $line['new_fair_value'] < 0
                || (int) $line['revised_useful_life_months'] <= 0
                || (float) $line['revised_residual_value'] > (float) $line['new_fair_value']
            ) {
                flash('error', 'Correct invalid asset values before submitting.');
                redirect('admin/fixed-assets.php?view=revaluation&batch_id=' . $batchId);
            }
        }

        db()->prepare('
            UPDATE asset_revaluation_batches
            SET status = \'submitted\',
                submitted_by = :uid,
                submitted_at = NOW()
            WHERE id = :id AND company_id = :cid AND status = \'draft\'
        ')->execute(['uid' => $userId, 'id' => $batchId, 'cid' => $companyId]);

        security_event(
            'asset_revaluation_batch_submitted',
            'success',
            'Submitted revaluation batch #' . $batchId . '.',
            $companyId,
            $userId
        );
        log_activity('asset_revaluation_batch', $batchId, 'submitted', 'Submitted for approval.', $userId);
        flash('success', 'Revaluation batch submitted for approval.');
        redirect('admin/fixed-assets.php?view=revaluation&batch_id=' . $batchId);
    }

    if ($action === 'approve_revaluation_batch') {
        require_permission('accounting', 'approve');

        $batchId = (int) ($_POST['batch_id'] ?? 0);
        try {
            $result = fa_approve_revaluation_batch(
                $batchId,
                $companyId,
                $fiscalYearId,
                $userId
            );
            flash(
                'success',
                'Revaluation approved for ' . $result['assets'] .
                ' assets. ' . $result['vouchers'] . ' journal vouchers were posted.'
            );
        } catch (Throwable $e) {
            flash('error', 'Could not approve revaluation: ' . $e->getMessage());
        }

        redirect('admin/fixed-assets.php?view=revaluation&batch_id=' . $batchId);
    }

    if ($action === 'reject_revaluation_batch') {
        require_permission('accounting', 'approve');

        $batchId = (int) ($_POST['batch_id'] ?? 0);
        $reason = trim((string) ($_POST['rejection_reason'] ?? ''));
        if ($reason === '') {
            flash('error', 'A rejection reason is required.');
            redirect('admin/fixed-assets.php?view=revaluation&batch_id=' . $batchId);
        }

        db()->prepare('
            UPDATE asset_revaluation_batches
            SET status = \'rejected\',
                rejected_by = :uid,
                rejected_at = NOW(),
                rejection_reason = :reason
            WHERE id = :id AND company_id = :cid AND status = \'submitted\'
        ')->execute([
            'uid' => $userId,
            'reason' => $reason,
            'id' => $batchId,
            'cid' => $companyId,
        ]);

        security_event(
            'asset_revaluation_batch_rejected',
            'failure',
            'Rejected revaluation batch #' . $batchId . '.',
            $companyId,
            $userId
        );
        log_activity('asset_revaluation_batch', $batchId, 'rejected', $reason, $userId);
        flash('success', 'Revaluation batch rejected.');
        redirect('admin/fixed-assets.php?view=revaluation&batch_id=' . $batchId);
    }

    if ($action === 'capitalize_cwip') {
        require_permission('accounting', 'post');
        $asset = fa_company_asset((int) ($_POST['asset_id'] ?? 0), $companyId);
        if (!$asset) { flash('error', 'Asset not found.'); redirect('admin/fixed-assets.php'); }
        if ((string) $asset['asset_class'] !== 'cwip') {
            flash('error', 'Only assets currently classified as Capital WIP can be capitalized.');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        $newClass = (string) ($_POST['new_asset_class'] ?? '');
        $allowedTargets = ['ppe', 'intangible', 'investment_property'];
        $method = (string) ($_POST['depreciation_method'] ?? 'straight_line');
        $lifeMonths = max(0, (int) ($_POST['useful_life_months'] ?? 0));
        $residual = max(0.0, round((float) ($_POST['residual_value'] ?? 0), 2));
        if (!in_array($newClass, $allowedTargets, true) || !isset($methods[$method])) {
            flash('error', 'Choose a valid target class (PPE, Intangible or Investment Property) and depreciation method.');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        $cost = (float) $asset['cost'];
        $cwipPurposes = fa_event_purposes('cwip_capitalize'); // ['ppe_cost', 'cwip']
        $ppeL = fa_resolve_mapping($companyId, $cwipPurposes[0], (int) $asset['id']);
        $cwipL = fa_resolve_mapping($companyId, $cwipPurposes[1], (int) $asset['id']);
        // Block rather than degrade: capitalization is a one-way reclassification
        // guarded by `asset_class = 'cwip'`, so if we flipped the class without
        // posting, the asset would no longer be CWIP and the voucher could never
        // be posted afterwards — the cost would be stranded in the CWIP ledger
        // forever. Other actions may record-only, but this one must not.
        if ($cost > 0 && (!$ppeL || !$cwipL)) {
            flash('error', 'Map PPE / Asset Cost and Capital Work-in-Progress before capitalizing — the reclassification cannot be posted without both.');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        try {
            db()->beginTransaction();
            $stmt = db()->prepare("UPDATE fixed_assets SET asset_class = :new_class, depreciation_method = :method, useful_life_months = :life, residual_value = :residual, status = 'active', depreciation_start_date = COALESCE(depreciation_start_date, CURDATE()), available_for_use_date = COALESCE(available_for_use_date, CURDATE()) WHERE id = :id AND company_id = :cid AND asset_class = 'cwip'");
            $stmt->execute(['new_class' => $newClass, 'method' => $method, 'life' => $lifeMonths, 'residual' => $residual, 'id' => (int) $asset['id'], 'cid' => $companyId]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Asset is no longer CWIP — nothing to capitalize.');
            }
            $vid = 0;
            if ($cost > 0 && $ppeL && $cwipL) {
                $vid = create_voucher_with_entries([
                    'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                    'voucher_no' => 'FA-CWIP-' . $asset['asset_code'], 'voucher_type' => 'journal', 'voucher_date' => date('Y-m-d'),
                    'source_type' => 'asset_cwip_capitalization', 'source_id' => (int) $asset['id'], 'total_amount' => $cost,
                    'narration' => 'Capitalization of ' . $asset['name'] . ' from CWIP.', 'status' => 'posted', 'posted_by' => $userId,
                ], [
                    ['ledger_id' => (int) $ppeL['id'], 'entry_type' => 'debit', 'amount' => $cost],
                    ['ledger_id' => (int) $cwipL['id'], 'entry_type' => 'credit', 'amount' => $cost],
                ]);
            }
            db()->commit();
            security_event('asset_cwip_capitalized', 'success', 'Asset #' . (int) $asset['id'] . ' capitalized from CWIP to ' . $newClass . '.', $companyId, $userId);
            log_activity('fixed_asset', (int) $asset['id'], 'cwip_capitalized', 'Capitalized from CWIP to ' . $newClass . '.', $userId);
            flash('success', 'Asset capitalized from CWIP to ' . ($assetClasses[$newClass] ?? $newClass) . '.' . (($cost > 0 && $ppeL && $cwipL) ? ' Voucher posted (Dr PPE Cost / Cr CWIP).' : ' Map PPE / Asset Cost + Capital Work-in-Progress to auto-post the reclassification voucher.'));
        } catch (Throwable $e) { if (db()->inTransaction()) { db()->rollBack(); } flash('error', 'Could not capitalize: ' . $e->getMessage()); }
        redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
    }

    if ($action === 'create_lease') {
        require_permission('accounting', 'post');
        // IFRS 16: create a ROU asset + lease liability with a full amortization schedule.
        $ref = strtoupper(trim((string) ($_POST['contract_ref'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $liability = max(0.0, round((float) ($_POST['initial_liability'] ?? 0), 2));
        $prepay = max(0.0, round((float) ($_POST['prepayments'] ?? 0), 2));
        $idc = max(0.0, round((float) ($_POST['initial_direct_costs'] ?? 0), 2));
        $incentive = max(0.0, round((float) ($_POST['incentives'] ?? 0), 2));
        $restoration = max(0.0, round((float) ($_POST['restoration'] ?? 0), 2));
        $term = max(1, (int) ($_POST['term_months'] ?? 12));
        $rateAnnual = max(0.0, (float) ($_POST['discount_rate_annual'] ?? 0));
        $payment = max(0.0, round((float) ($_POST['payment'] ?? 0), 2));
        $timing = (string) ($_POST['payment_timing'] ?? 'arrears') === 'advance' ? 'advance' : 'arrears';
        $commence = trim((string) ($_POST['commencement_date'] ?? '')) ?: date('Y-m-d');
        if ($ref === '' || $name === '' || $liability <= 0) {
            flash('error', 'Lease reference, ROU asset name and initial liability are required.');
            redirect('admin/fixed-assets.php?view=leases');
        }
        $rou = fa_rou_initial($liability, $prepay, $idc, $restoration, $incentive);
        $rouL = fa_resolve_mapping($companyId, 'rou_asset');
        $liabL = fa_resolve_mapping($companyId, 'lease_liability');
        try {
            db()->beginTransaction();
            // Create the ROU asset record (depreciated straight-line over the term).
            db()->prepare('INSERT INTO fixed_assets (company_id, asset_code, name, asset_class, cost, residual_value, useful_life_months, depreciation_method, available_for_use_date, depreciation_start_date, carrying_amount, status, created_by)
                VALUES (:cid, :code, :name, \'rou\', :cost, 0, :life, \'straight_line\', :d, :dep_start, :cost2, \'active\', :uid)')
                ->execute(['cid' => $companyId, 'code' => 'ROU-' . $ref, 'name' => $name, 'cost' => $rou, 'life' => $term, 'd' => $commence, 'dep_start' => $commence, 'cost2' => $rou, 'uid' => $userId]);
            $rouAssetId = (int) db()->lastInsertId();
            db()->prepare('INSERT INTO lease_liabilities (company_id, asset_id, contract_ref, commencement_date, term_months, payment, payment_timing, discount_rate_annual, initial_liability, initial_direct_costs, prepayments, incentives, restoration, rou_initial, status, created_by)
                VALUES (:cid,:aid,:ref,:d,:term,:pay,:timing,:rate,:liab,:idc,:prep,:inc,:rest,:rou,\'active\',:uid)')
                ->execute(['cid' => $companyId, 'aid' => $rouAssetId, 'ref' => $ref, 'd' => $commence, 'term' => $term, 'pay' => $payment, 'timing' => $timing, 'rate' => $rateAnnual, 'liab' => $liability, 'idc' => $idc, 'prep' => $prepay, 'inc' => $incentive, 'rest' => $restoration, 'rou' => $rou, 'uid' => $userId]);
            $leaseId = (int) db()->lastInsertId();
            // Generate the amortization schedule.
            $schedule = fa_lease_schedule($liability, $rateAnnual / 1200.0, $payment, $term, $timing);
            $lineStmt = db()->prepare('INSERT INTO lease_schedule_lines (lease_id, period_no, period_date, opening, interest, payment, principal, closing) VALUES (:lid,:pno,:pdate,:o,:i,:pay,:pr,:cl)');
            foreach ($schedule as $line) {
                $lineStmt->execute(['lid' => $leaseId, 'pno' => $line['period'], 'pdate' => $commence, 'o' => $line['opening'], 'i' => $line['interest'], 'pay' => $line['payment'], 'pr' => $line['principal'], 'cl' => $line['closing']]);
            }
            // Commencement voucher when both legs mapped. Dr ROU / Cr Lease
            // liability; the net of prepayments + IDC - incentives is the cash/
            // clearing leg that keeps the entry balanced (ROU = liability + net).
            $cashL = fa_resolve_mapping($companyId, 'disposal_clearing') ?: $liabL;
            if ($rouL && $liabL) {
                $net = round($rou - $liability, 2); // = prepay + idc - incentive
                $entries = [
                    ['ledger_id' => (int) $rouL['id'], 'entry_type' => 'debit', 'amount' => $rou],
                    ['ledger_id' => (int) $liabL['id'], 'entry_type' => 'credit', 'amount' => $liability],
                ];
                if (abs($net) >= 0.005) {
                    $entries[] = $net > 0
                        ? ['ledger_id' => (int) $cashL['id'], 'entry_type' => 'credit', 'amount' => $net]
                        : ['ledger_id' => (int) $cashL['id'], 'entry_type' => 'debit', 'amount' => -$net];
                }
                create_voucher_with_entries([
                    'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null, 'voucher_no' => 'FA-LEASE-' . $ref,
                    'voucher_type' => 'journal', 'voucher_date' => $commence, 'source_type' => 'lease_commencement', 'source_id' => $leaseId,
                    'total_amount' => $rou, 'narration' => 'Lease commencement ' . $ref . ' (IFRS 16).', 'status' => 'posted', 'posted_by' => $userId,
                ], $entries);
            }
            db()->commit();
            security_event('lease_created', 'success', 'Lease created for asset/ROU #' . $rouAssetId . '.', $companyId, $userId);
            log_activity('lease', $leaseId, 'created', 'Lease ' . $ref . ' created (ROU ' . number_format($rou, 2) . ').', $userId);
            flash('success', 'Lease ' . $ref . ' created. ROU asset ROU-' . $ref . ' = ' . site_currency_symbol() . number_format($rou, 2) . ' with a ' . $term . '-month liability schedule.' . (($rouL && $liabL) ? ' Commencement voucher posted.' : ' Map ROU Asset + Lease Liability to auto-post.'));
        } catch (Throwable $e) { if (db()->inTransaction()) { db()->rollBack(); } flash('error', 'Could not create lease: ' . $e->getMessage()); }
        redirect('admin/fixed-assets.php?view=leases');
    }

    if ($action === 'post_lease_period') {
        require_permission('accounting', 'post');
        $lineId = (int) ($_POST['line_id'] ?? 0);
        $lineStmt = db()->prepare('SELECT lsl.*, ll.contract_ref FROM lease_schedule_lines lsl JOIN lease_liabilities ll ON ll.id = lsl.lease_id WHERE lsl.id = :id AND ll.company_id = :cid LIMIT 1');
        $lineStmt->execute(['id' => $lineId, 'cid' => $companyId]);
        $line = $lineStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$line) {
            flash('error', 'Lease schedule line not found for this company.');
            redirect('admin/fixed-assets.php?view=leases');
        }
        if ((int) $line['posted'] === 1) {
            flash('error', 'This lease period is already posted.');
            redirect('admin/fixed-assets.php?view=leases&lease_id=' . (int) $line['lease_id']);
        }
        $leaseInterestPurposes = fa_event_purposes('lease_interest'); // ['lease_interest_expense', 'lease_liability']
        $leasePaymentPurposes = fa_event_purposes('lease_payment');   // ['lease_liability', 'acquisition_clearing']
        $interestExpL = fa_resolve_mapping($companyId, $leaseInterestPurposes[0]);
        $liabL = fa_resolve_mapping($companyId, $leaseInterestPurposes[1]);
        $clearingL = fa_resolve_mapping($companyId, $leasePaymentPurposes[1]);
        if (!$interestExpL || !$liabL || !$clearingL) {
            flash('error', 'Map Lease Interest Expense, Lease Liability and Acquisition Clearing / Payable before posting lease periods.');
            redirect('admin/fixed-assets.php?view=leases&lease_id=' . (int) $line['lease_id']);
        }
        $interest = (float) $line['interest'];
        $payment = (float) $line['payment'];
        try {
            db()->beginTransaction();
            $entries = [];
            if ($interest > 0) {
                $entries[] = ['ledger_id' => (int) $interestExpL['id'], 'entry_type' => 'debit', 'amount' => $interest];
                $entries[] = ['ledger_id' => (int) $liabL['id'], 'entry_type' => 'credit', 'amount' => $interest];
            }
            if ($payment > 0) {
                $entries[] = ['ledger_id' => (int) $liabL['id'], 'entry_type' => 'debit', 'amount' => $payment];
                $entries[] = ['ledger_id' => (int) $clearingL['id'], 'entry_type' => 'credit', 'amount' => $payment];
            }
            if ($entries === []) {
                throw new RuntimeException('Nothing to post for this period.');
            }
            $vid = create_voucher_with_entries([
                'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                // contract_ref is VARCHAR(120) but voucher_no is VARCHAR(80) and
                // UNIQUE per company — key on the lease id + period, which is
                // both short and unique, instead of the free-text contract ref.
                'voucher_no' => 'FA-LSE-' . (int) $line['lease_id'] . '-' . str_pad((string) $line['period_no'], 3, '0', STR_PAD_LEFT),
                'voucher_type' => 'journal', 'voucher_date' => date('Y-m-d'),
                // Keyed on the schedule LINE id, not the lease id: vouchers has
                // UNIQUE(source_type, source_id), so a lease-scoped key would let
                // only the first period of each lease ever post. Per-line keying
                // also makes re-posting a period idempotent at the DB level.
                'source_type' => 'lease_period', 'source_id' => $lineId, 'total_amount' => round($interest + $payment, 2),
                'narration' => 'Lease period ' . $line['period_no'] . ' — ' . $line['contract_ref'] . ' (interest + payment, IFRS 16).',
                'status' => 'posted', 'posted_by' => $userId,
            ], $entries);
            db()->prepare('UPDATE lease_schedule_lines SET posted = 1, voucher_id = :vid WHERE id = :id')
                ->execute(['vid' => $vid ?: null, 'id' => $lineId]);
            db()->commit();
            security_event('lease_period_posted', 'success', 'Lease period ' . $line['period_no'] . ' posted for lease #' . (int) $line['lease_id'] . '.', $companyId, $userId);
            log_activity('lease', (int) $line['lease_id'], 'period_posted', 'Lease period ' . $line['period_no'] . ' posted (interest ' . number_format($interest, 2) . ', payment ' . number_format($payment, 2) . ').', $userId);
            flash('success', 'Lease period ' . $line['period_no'] . ' posted: interest ' . site_currency_symbol() . number_format($interest, 2) . ', payment ' . site_currency_symbol() . number_format($payment, 2) . '.');
        } catch (Throwable $e) { if (db()->inTransaction()) { db()->rollBack(); } flash('error', 'Could not post lease period: ' . $e->getMessage()); }
        redirect('admin/fixed-assets.php?view=leases&lease_id=' . (int) $line['lease_id']);
    }

    if ($action === 'save_asset_mappings') {
        require_permission('accounting', 'edit');
        $saved = 0;
        foreach (array_keys(fa_mapping_purposes()) as $purpose) {
            $ledgerId = (int) ($_POST['map'][$purpose] ?? 0);
            if ($ledgerId <= 0) {
                db()->prepare('DELETE FROM asset_ledger_mappings WHERE company_id = :cid AND scope = \'global\' AND category_id IS NULL AND asset_id IS NULL AND purpose = :p')
                    ->execute(['cid' => $companyId, 'p' => $purpose]);
                continue;
            }
            $own = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :id AND company_id = :cid');
            $own->execute(['id' => $ledgerId, 'cid' => $companyId]);
            if ((int) $own->fetchColumn() === 0) {
                continue;
            }
            db()->prepare('INSERT INTO asset_ledger_mappings (company_id, scope, category_id, asset_id, purpose, ledger_id, created_by)
                VALUES (:cid, \'global\', NULL, NULL, :p, :lid, :uid)
                ON DUPLICATE KEY UPDATE ledger_id = VALUES(ledger_id)')
                ->execute(['cid' => $companyId, 'p' => $purpose, 'lid' => $ledgerId, 'uid' => $userId]);
            $saved++;
        }
        flash('success', 'Asset ledger mappings saved (' . $saved . ' mapped).');
        redirect('admin/fixed-assets.php?view=mapping');
    }

    if ($action === 'save_category') {
        require_permission('accounting', 'create');
        $name = trim((string) ($_POST['name'] ?? ''));
        $class = (string) ($_POST['asset_class'] ?? 'ppe');
        $method = (string) ($_POST['default_method'] ?? 'straight_line');
        $lifeMonths = max(0, (int) ($_POST['default_life_months'] ?? 60));
        $rate = max(0.0, round((float) ($_POST['default_rate_pct'] ?? 0), 3));
        if ($name === '' || !isset($assetClasses[$class]) || !isset($methods[$method])) {
            flash('error', 'Category name, class and default method are required.');
            redirect('admin/fixed-assets.php?view=categories');
        }
        try {
            db()->prepare('INSERT INTO asset_categories (company_id, name, asset_class, default_method, default_life_months, default_rate_pct, is_active)
                VALUES (:cid, :name, :class, :method, :life, :rate, 1)')
                ->execute(['cid' => $companyId, 'name' => $name, 'class' => $class, 'method' => $method, 'life' => $lifeMonths, 'rate' => $rate]);
            $categoryId = (int) db()->lastInsertId();
            security_event('asset_category_created', 'success', 'Asset category #' . $categoryId . ' (' . $name . ') created.', $companyId, $userId);
            log_activity('asset_category', $categoryId, 'created', 'Category ' . $name . ' created.', $userId);
            flash('success', 'Category ' . $name . ' created.');
        } catch (Throwable $e) {
            flash('error', (string) $e->getCode() === '23000' ? 'Category ' . $name . ' already exists.' : 'Could not create category: ' . $e->getMessage());
        }
        redirect('admin/fixed-assets.php?view=categories');
    }

    if ($action === 'toggle_category') {
        require_permission('accounting', 'edit');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        try {
            db()->prepare('UPDATE asset_categories SET is_active = NOT is_active WHERE id = :id AND company_id = :cid')
                ->execute(['id' => $categoryId, 'cid' => $companyId]);
            security_event('asset_category_toggled', 'success', 'Asset category #' . $categoryId . ' active flag toggled.', $companyId, $userId);
            log_activity('asset_category', $categoryId, 'toggled', 'Category active flag toggled.', $userId);
            flash('success', 'Category updated.');
        } catch (Throwable $e) {
            flash('error', 'Could not update category: ' . $e->getMessage());
        }
        redirect('admin/fixed-assets.php?view=categories');
    }
}

$assetStatuses = ['active' => 'Active', 'held_for_sale' => 'Held for sale', 'disposed' => 'Disposed', 'fully_depreciated' => 'Fully depreciated', 'draft' => 'Draft'];
$statusFilter = (string) ($_GET['status'] ?? '');
$classFilter = (string) ($_GET['class'] ?? '');
$assetsSql = 'SELECT * FROM fixed_assets WHERE company_id = :cid';
$assetsParams = ['cid' => $companyId];
if (isset($assetStatuses[$statusFilter])) {
    $assetsSql .= ' AND status = :status';
    $assetsParams['status'] = $statusFilter;
}
if (isset($assetClasses[$classFilter])) {
    $assetsSql .= ' AND asset_class = :class';
    $assetsParams['class'] = $classFilter;
}
$assetsSql .= ' ORDER BY status ASC, name ASC';
$assets = db()->prepare($assetsSql);
$assets->execute($assetsParams);
$assets = $assets->fetchAll(PDO::FETCH_ASSOC);

$totalCost = array_sum(array_map(static fn (array $a): float => (float) $a['cost'], $assets));
$totalAccum = array_sum(array_map(static fn (array $a): float => (float) $a['accumulated_depreciation'], $assets));
$totalImpair = array_sum(array_map(static fn (array $a): float => (float) $a['accumulated_impairment'], $assets));
$totalCarrying = array_sum(array_map(static fn (array $a): float => (float) $a['carrying_amount'], $assets));

$faView = (string) ($_GET['view'] ?? 'register');
$detailAsset = ctype_digit($faView) ? fa_company_asset((int) $faView, $companyId) : null;
if ($detailAsset) {
    $faView = 'detail';
} elseif (!in_array($faView, ['register', 'mapping', 'leases', 'categories', 'revaluation'], true)) {
    $faView = 'register';
}

$pageTitle = 'Fixed Assets';
$pageSubtitle = 'Asset register, depreciation, and IFRS accounting integration';
$bodyClass = 'admin-layout accounting-module-page fixed-assets-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<nav class="mbw-tabbar inventory-module-tabs" aria-label="Inventory and asset modules">
    <a class="mbw-tab" href="<?= e(url('admin/accounting-inventory.php')) ?>"><?= icon('layers') ?> Inventory</a>
    <?php if (($inventoryProfile['show_manufacturing'] ?? false)): ?>
        <a class="mbw-tab" href="<?= e(url('admin/accounting-inventory.php?view=manufacturing')) ?>"><?= icon('services') ?> Manufacturing</a>
    <?php endif; ?>
    <a class="mbw-tab is-active" href="<?= e(url('admin/fixed-assets.php')) ?>"><?= icon('companies') ?> Fixed Assets</a>
</nav>

<section class="mbw-kpi-grid" aria-label="Fixed asset overview">
    <div class="mbw-kpi"><div><span class="mbw-kpi-label">Assets</span><div class="mbw-kpi-value"><?= e((string) count($assets)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Registered</span></span></div><span class="mbw-chip tone-blue"><?= icon('companies') ?></span></div>
    <div class="mbw-kpi"><div><span class="mbw-kpi-label">Gross Cost</span><div class="mbw-kpi-value"><?= e(site_currency_symbol()) ?><?= e(number_format($totalCost, 2)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Original cost</span></span></div><span class="mbw-chip tone-green"><?= icon('wallet') ?></span></div>
    <div class="mbw-kpi"><div><span class="mbw-kpi-label">Accumulated Dep.</span><div class="mbw-kpi-value"><?= e(site_currency_symbol()) ?><?= e(number_format($totalAccum + $totalImpair, 2)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Dep. + impairment</span></span></div><span class="mbw-chip tone-amber"><?= icon('download') ?></span></div>
    <div class="mbw-kpi"><div><span class="mbw-kpi-label">Carrying Amount</span><div class="mbw-kpi-value"><?= e(site_currency_symbol()) ?><?= e(number_format($totalCarrying, 2)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Net book value</span></span></div><span class="mbw-chip tone-teal"><?= icon('reports') ?></span></div>
</section>

<nav class="mbw-tabbar" aria-label="Fixed asset workspace" style="margin:6px 0 16px">
    <a class="<?= in_array($faView, ['register', 'detail'], true) ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php')) ?>"><?= icon('companies') ?>Register</a>
    <a class="<?= $faView === 'revaluation' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php?view=revaluation')) ?>"><?= icon('wallet') ?>Revaluation</a>
    <a class="<?= $faView === 'leases' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php?view=leases')) ?>"><?= icon('contracts') ?>Leases (IFRS 16)</a>
    <a class="<?= $faView === 'mapping' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php?view=mapping')) ?>"><?= icon('accounting') ?>Ledger Mapping</a>
    <a class="<?= $faView === 'categories' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php?view=categories')) ?>"><?= icon('layers') ?>Categories</a>
</nav>

<?php if ($faView === 'revaluation'): ?>
    <?php
    $revaluationBatchId = (int) ($_GET['batch_id'] ?? 0);
    $revaluationBatch = $revaluationBatchId > 0
        ? fa_revaluation_batch($revaluationBatchId, $companyId)
        : null;
    $revaluationLines = $revaluationBatch
        ? fa_revaluation_lines($revaluationBatchId, $companyId)
        : [];
    $revaluationBatches = fa_revaluation_batches($companyId);

    $preselectedClass = 'ppe';
    $preselectedAssetId = (int) ($_GET['asset_id'] ?? 0);
    if ($preselectedAssetId > 0) {
        $preselectedAsset = fa_company_asset($preselectedAssetId, $companyId);
        if (
            $preselectedAsset
            && in_array(
                (string) $preselectedAsset['asset_class'],
                ['ppe', 'intangible', 'investment_property'],
                true
            )
        ) {
            $preselectedClass = (string) $preselectedAsset['asset_class'];
        }
    }

    $statusTone = static function (string $status): string {
        return match ($status) {
            'approved' => 'tone-green',
            'submitted' => 'tone-amber',
            'rejected' => 'tone-red',
            default => 'tone-gray',
        };
    };
    ?>

    <div class="fa-revaluation-layout">
        <aside class="mbw-card fa-revaluation-side">
            <div class="mbw-card-head">
                <div>
                    <span class="fa-eyebrow">Valuation and closure</span>
                    <h2>Revaluation</h2>
                </div>
            </div>

            <nav class="fa-side-nav" aria-label="Fixed asset valuation navigation">
                <a class="is-active" href="<?= e(url('admin/fixed-assets.php?view=revaluation')) ?>">
                    <?= icon('wallet') ?><span><strong>Revaluation batches</strong><small>IAS 16 and IAS 38</small></span>
                </a>
                <a href="<?= e(url('admin/fixed-assets.php')) ?>">
                    <?= icon('companies') ?><span><strong>Fixed asset register</strong><small>Assets and carrying values</small></span>
                </a>
                <a href="<?= e(url('admin/fixed-assets.php?view=mapping')) ?>">
                    <?= icon('accounting') ?><span><strong>Ledger mapping</strong><small>OCI and P&amp;L accounts</small></span>
                </a>
                <a href="<?= e(url('admin/fixed-assets.php?view=categories')) ?>">
                    <?= icon('layers') ?><span><strong>Asset categories</strong><small>Class and defaults</small></span>
                </a>
            </nav>

            <div class="fa-side-note">
                <strong>Class-wide control</strong>
                <p>Each batch freezes every active asset in the selected class. Earlier values remain in history.</p>
            </div>
        </aside>

        <main class="fa-revaluation-main">
            <?php if ($revaluationBatch): ?>
                <?php
                $batchEditable = (string) $revaluationBatch['status'] === 'draft';
                $batchSubmitted = (string) $revaluationBatch['status'] === 'submitted';
                $batchApproved = (string) $revaluationBatch['status'] === 'approved';
                ?>
                <section class="mbw-card fa-batch-hero">
                    <div>
                        <a class="fa-back-link" href="<?= e(url('admin/fixed-assets.php?view=revaluation')) ?>">
                            <?= icon('chevron') ?> All batches
                        </a>
                        <span class="fa-eyebrow"><?= e($revaluationBatch['batch_no']) ?></span>
                        <h2><?= e($assetClasses[$revaluationBatch['asset_class']] ?? $revaluationBatch['asset_class']) ?></h2>
                        <p>
                            Revaluation date <?= e($revaluationBatch['revaluation_date']) ?>.
                            Valuer <?= e($revaluationBatch['valuer_name']) ?>.
                        </p>
                    </div>
                    <div class="fa-batch-status">
                        <span class="mbw-pill <?= e($statusTone((string) $revaluationBatch['status'])) ?>">
                            <?= e(ucfirst((string) $revaluationBatch['status'])) ?>
                        </span>
                        <a class="button secondary" target="_blank" rel="noopener"
                           href="<?= e(url((string) $revaluationBatch['report_path'])) ?>">
                            <?= icon('documents') ?> Valuation report
                        </a>
                    </div>
                </section>

                <?php if ((string) $revaluationBatch['status'] === 'rejected'): ?>
                    <section class="mbw-card fa-alert-card is-rejected">
                        <strong>Rejected</strong>
                        <p><?= e((string) ($revaluationBatch['rejection_reason'] ?? 'No reason recorded.')) ?></p>
                    </section>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="fa-batch-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_revaluation_batch">
                    <input type="hidden" name="batch_id" value="<?= e((int) $revaluationBatch['id']) ?>">

                    <section class="mbw-card">
                        <div class="mbw-card-head">
                            <div>
                                <span class="fa-eyebrow">Batch details</span>
                                <h2>Valuation information</h2>
                            </div>
                        </div>

                        <div class="workspace-form-grid fa-batch-fields">
                            <label>Revaluation date
                                <input type="date" name="revaluation_date"
                                       value="<?= e((string) $revaluationBatch['revaluation_date']) ?>"
                                       <?= $batchEditable ? '' : 'disabled' ?> required>
                            </label>
                            <label>Valuer name
                                <input type="text" name="valuer_name" maxlength="190"
                                       value="<?= e((string) $revaluationBatch['valuer_name']) ?>"
                                       <?= $batchEditable ? '' : 'disabled' ?> required>
                            </label>
                            <label>Valuer registration / reference
                                <input type="text" name="valuer_reference" maxlength="190"
                                       value="<?= e((string) ($revaluationBatch['valuer_reference'] ?? '')) ?>"
                                       <?= $batchEditable ? '' : 'disabled' ?>>
                            </label>
                            <label>Valuation method
                                <select name="valuation_method" <?= $batchEditable ? '' : 'disabled' ?> required>
                                    <?php foreach (fa_revaluation_methods() as $methodKey => $methodLabel): ?>
                                        <option value="<?= e($methodKey) ?>"
                                            <?= (string) $revaluationBatch['valuation_method'] === $methodKey ? 'selected' : '' ?>>
                                            <?= e($methodLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="workspace-span-2">Reason
                                <textarea name="reason" rows="3" <?= $batchEditable ? '' : 'disabled' ?> required><?= e((string) $revaluationBatch['reason']) ?></textarea>
                            </label>
                            <?php if ($batchEditable): ?>
                                <label class="workspace-span-2">Replace valuation report
                                    <input type="file" name="valuation_report" accept=".pdf,.jpg,.jpeg,.png">
                                    <small>Optional. PDF, JPG or PNG, maximum 10 MB.</small>
                                </label>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="mbw-card">
                        <div class="mbw-card-head">
                            <div>
                                <span class="fa-eyebrow">Class-wide asset list</span>
                                <h2><?= e((string) count($revaluationLines)) ?> assets in this batch</h2>
                            </div>
                            <div class="mbw-card-tools">
                                <span class="mbw-pill tone-blue">Complete class frozen at creation</span>
                            </div>
                        </div>

                        <div class="rc-table-scroll">
                            <table class="rc-table fa-revaluation-table">
                                <thead>
                                    <tr>
                                        <th>Asset</th>
                                        <th class="align-right">Previous carrying</th>
                                        <th class="align-right">New fair value</th>
                                        <th class="align-right">Increase / decrease</th>
                                        <th class="align-right">Revised life</th>
                                        <th class="align-right">Residual value</th>
                                        <th>Remarks</th>
                                        <?php if ($batchApproved): ?>
                                            <th class="align-right">P&amp;L</th>
                                            <th class="align-right">OCI</th>
                                            <th>Voucher</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($revaluationLines as $line): ?>
                                        <?php
                                        $lineChange = round(
                                            (float) $line['new_fair_value']
                                            - (float) $line['previous_carrying_value'],
                                            2
                                        );
                                        ?>
                                        <tr data-revaluation-line>
                                            <td>
                                                <strong><?= e($line['asset_code']) ?></strong>
                                                <small><?= e($line['asset_name']) ?></small>
                                            </td>
                                            <td class="align-right">
                                                <?= e(number_format((float) $line['previous_carrying_value'], 2)) ?>
                                                <?php if ($batchEditable): ?>
                                                    <input type="hidden"
                                                        name="line[<?= e((int) $line['id']) ?>][previous_carrying_value]"
                                                        value="<?= e(number_format((float) $line['previous_carrying_value'], 2, '.', '')) ?>">
                                                <?php endif; ?>
                                            </td>
                                            <td class="align-right">
                                                <?php if ($batchEditable): ?>
                                                    <input class="fa-money-input" type="number" min="0" step="0.01"
                                                        data-new-value
                                                        name="line[<?= e((int) $line['id']) ?>][new_fair_value]"
                                                        value="<?= e(number_format((float) $line['new_fair_value'], 2, '.', '')) ?>"
                                                        required>
                                                <?php else: ?>
                                                    <?= e(number_format((float) $line['new_fair_value'], 2)) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="align-right">
                                                <span data-change
                                                    class="fa-change <?= $lineChange >= 0 ? 'is-up' : 'is-down' ?>">
                                                    <?= e(($lineChange >= 0 ? '+' : '') . number_format($lineChange, 2)) ?>
                                                </span>
                                            </td>
                                            <td class="align-right">
                                                <?php if ($batchEditable): ?>
                                                    <input class="fa-small-input" type="number" min="1" step="1"
                                                        name="line[<?= e((int) $line['id']) ?>][revised_useful_life_months]"
                                                        value="<?= e((int) $line['revised_useful_life_months']) ?>" required>
                                                <?php else: ?>
                                                    <?= e((int) $line['revised_useful_life_months']) ?> mo
                                                <?php endif; ?>
                                            </td>
                                            <td class="align-right">
                                                <?php if ($batchEditable): ?>
                                                    <input class="fa-money-input" type="number" min="0" step="0.01"
                                                        name="line[<?= e((int) $line['id']) ?>][revised_residual_value]"
                                                        value="<?= e(number_format((float) $line['revised_residual_value'], 2, '.', '')) ?>"
                                                        required>
                                                <?php else: ?>
                                                    <?= e(number_format((float) $line['revised_residual_value'], 2)) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($batchEditable): ?>
                                                    <input type="text" maxlength="500"
                                                        name="line[<?= e((int) $line['id']) ?>][remarks]"
                                                        value="<?= e((string) ($line['remarks'] ?? '')) ?>">
                                                <?php else: ?>
                                                    <?= e((string) ($line['remarks'] ?? '—')) ?>
                                                <?php endif; ?>
                                            </td>
                                            <?php if ($batchApproved): ?>
                                                <td class="align-right"><?= e(number_format((float) $line['pnl_effect'], 2)) ?></td>
                                                <td class="align-right"><?= e(number_format((float) $line['oci_effect'], 2)) ?></td>
                                                <td><?= $line['voucher_id'] ? '#' . e((int) $line['voucher_id']) : '—' ?></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($batchEditable): ?>
                            <div class="fa-form-actions">
                                <button type="submit" class="button secondary">
                                    <?= icon('accounting') ?> Save draft
                                </button>
                            </div>
                        <?php endif; ?>
                    </section>
                </form>

                <?php if ($batchEditable): ?>
                    <section class="mbw-card fa-approval-card">
                        <div>
                            <span class="fa-eyebrow">Approval workflow</span>
                            <h2>Submit completed batch</h2>
                            <p>After submission, asset values are locked until the batch is approved or rejected.</p>
                        </div>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="submit_revaluation_batch">
                            <input type="hidden" name="batch_id" value="<?= e((int) $revaluationBatch['id']) ?>">
                            <button type="submit"><?= icon('upload') ?> Submit for approval</button>
                        </form>
                    </section>
                <?php elseif ($batchSubmitted && user_can_do('accounting', 'approve')): ?>
                    <section class="mbw-card fa-approval-card">
                        <div>
                            <span class="fa-eyebrow">Approval required</span>
                            <h2>Review accounting impact</h2>
                            <p>Approval posts the journal entries, updates OCI and P&amp;L, and resets future depreciation prospectively.</p>
                        </div>
                        <div class="fa-approval-actions">
                            <form method="post" data-confirm="Approve and post this class-wide revaluation batch?">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="approve_revaluation_batch">
                                <input type="hidden" name="batch_id" value="<?= e((int) $revaluationBatch['id']) ?>">
                                <button type="submit"><?= icon('accounting') ?> Approve and post</button>
                            </form>
                            <form method="post" class="fa-reject-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="reject_revaluation_batch">
                                <input type="hidden" name="batch_id" value="<?= e((int) $revaluationBatch['id']) ?>">
                                <input type="text" name="rejection_reason" placeholder="Reason for rejection" required>
                                <button type="submit" class="button secondary">Reject</button>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>

            <?php else: ?>
                <section class="mbw-card fa-new-batch-card">
                    <div class="mbw-card-head">
                        <div>
                            <span class="fa-eyebrow">New class-wide batch</span>
                            <h2>Create revaluation batch</h2>
                            <p>Select one asset class. Every active asset in that class is included and frozen in the batch.</p>
                        </div>
                    </div>

                    <form method="post" enctype="multipart/form-data" class="workspace-form-grid">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="create_revaluation_batch">

                        <label>Asset class
                            <select name="asset_class" required>
                                <?php foreach (['ppe', 'intangible', 'investment_property'] as $classKey): ?>
                                    <option value="<?= e($classKey) ?>" <?= $preselectedClass === $classKey ? 'selected' : '' ?>>
                                        <?= e($assetClasses[$classKey]) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Revaluation date
                            <input type="date" name="revaluation_date" value="<?= e(date('Y-m-d')) ?>" required>
                        </label>
                        <label>Valuer name
                            <input type="text" name="valuer_name" maxlength="190" required>
                        </label>
                        <label>Valuer registration / reference
                            <input type="text" name="valuer_reference" maxlength="190">
                        </label>
                        <label>Valuation method
                            <select name="valuation_method" required>
                                <?php foreach (fa_revaluation_methods() as $methodKey => $methodLabel): ?>
                                    <option value="<?= e($methodKey) ?>"><?= e($methodLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Valuation report
                            <input type="file" name="valuation_report" accept=".pdf,.jpg,.jpeg,.png" required>
                            <small>PDF, JPG or PNG, maximum 10 MB.</small>
                        </label>
                        <label class="workspace-span-2">Reason
                            <textarea name="reason" rows="3" required></textarea>
                        </label>
                        <div class="workspace-span-2">
                            <button type="submit"><?= icon('wallet') ?> Create batch and load class assets</button>
                        </div>
                    </form>
                </section>

                <section class="mbw-card">
                    <div class="mbw-card-head">
                        <div>
                            <span class="fa-eyebrow">Complete history</span>
                            <h2>Revaluation batches</h2>
                        </div>
                    </div>

                    <div class="rc-table-scroll">
                        <table class="rc-table">
                            <thead>
                                <tr>
                                    <th>Batch</th>
                                    <th>Date</th>
                                    <th>Asset class</th>
                                    <th class="align-right">Assets</th>
                                    <th class="align-right">Previous value</th>
                                    <th class="align-right">New value</th>
                                    <th class="align-right">Net change</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($revaluationBatches as $batchRow): ?>
                                    <tr>
                                        <td><strong><?= e($batchRow['batch_no']) ?></strong></td>
                                        <td><?= e($batchRow['revaluation_date']) ?></td>
                                        <td><?= e($assetClasses[$batchRow['asset_class']] ?? $batchRow['asset_class']) ?></td>
                                        <td class="align-right"><?= e((int) $batchRow['asset_count']) ?></td>
                                        <td class="align-right"><?= e(number_format((float) $batchRow['previous_total'], 2)) ?></td>
                                        <td class="align-right"><?= e(number_format((float) $batchRow['fair_value_total'], 2)) ?></td>
                                        <td class="align-right">
                                            <?php $netChange = (float) $batchRow['fair_value_total'] - (float) $batchRow['previous_total']; ?>
                                            <span class="fa-change <?= $netChange >= 0 ? 'is-up' : 'is-down' ?>">
                                                <?= e(($netChange >= 0 ? '+' : '') . number_format($netChange, 2)) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mbw-pill <?= e($statusTone((string) $batchRow['status'])) ?>">
                                                <?= e(ucfirst((string) $batchRow['status'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a class="button secondary"
                                               href="<?= e(url('admin/fixed-assets.php?view=revaluation&batch_id=' . (int) $batchRow['id'])) ?>">
                                                Open
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($revaluationBatches === []): ?>
                                    <tr>
                                        <td colspan="9" style="text-align:center;color:var(--mbw-muted)">
                                            No revaluation batches yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <script>
    document.querySelectorAll('[data-revaluation-line]').forEach(function (row) {
        var previousInput = row.querySelector('input[name*="[previous_carrying_value]"]');
        var newInput = row.querySelector('[data-new-value]');
        var changeOutput = row.querySelector('[data-change]');
        if (!previousInput || !newInput || !changeOutput) {
            return;
        }

        function updateChange() {
            var previousValue = Number(previousInput.value || 0);
            var newValue = Number(newInput.value || 0);
            var change = newValue - previousValue;
            changeOutput.textContent = (change >= 0 ? '+' : '') + change.toFixed(2);
            changeOutput.classList.toggle('is-up', change >= 0);
            changeOutput.classList.toggle('is-down', change < 0);
        }

        newInput.addEventListener('input', updateChange);
        updateChange();
    });
    </script>

<?php elseif ($faView === 'leases'): ?>
    <?php
    $activeLeaseId = (int) ($_GET['lease_id'] ?? 0);
    $activeLease = $activeLeaseId > 0 ? fa_company_lease($activeLeaseId, $companyId) : null;
    ?>
    <?php if ($activeLease): ?>
        <?php
        $lineStmt = db()->prepare('SELECT * FROM lease_schedule_lines WHERE lease_id = :lid ORDER BY period_no ASC');
        $lineStmt->execute(['lid' => (int) $activeLease['id']]);
        $leaseLines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
        $nextUnposted = null;
        foreach ($leaseLines as $ln) {
            if ((int) $ln['posted'] === 0) { $nextUnposted = $ln; break; }
        }
        ?>
        <section class="mbw-card">
            <div class="mbw-card-head"><h2>Lease <?= e($activeLease['contract_ref']) ?> — schedule</h2>
                <div class="mbw-card-tools"><a class="button secondary" href="<?= e(url('admin/fixed-assets.php?view=leases')) ?>">Back to leases</a></div></div>
            <?php if ($nextUnposted): ?>
                <form method="post" style="margin-bottom:12px">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="post_lease_period">
                    <input type="hidden" name="line_id" value="<?= e((int) $nextUnposted['id']) ?>">
                    <button type="submit"><?= icon('accounting') ?>Post period <?= e((string) $nextUnposted['period_no']) ?> (interest <?= e(site_currency_symbol()) ?><?= e(number_format((float) $nextUnposted['interest'], 2)) ?> + principal <?= e(site_currency_symbol()) ?><?= e(number_format((float) $nextUnposted['principal'], 2)) ?>)</button>
                </form>
            <?php else: ?>
                <p style="color:var(--mbw-muted);font-size:12.5px;margin:0 0 12px">All periods for this lease are posted.</p>
            <?php endif; ?>
            <div class="rc-table-scroll"><table class="rc-table">
                <thead><tr><th>Period</th><th>Date</th><th class="align-right">Opening</th><th class="align-right">Interest</th><th class="align-right">Payment</th><th class="align-right">Principal</th><th class="align-right">Closing</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($leaseLines as $ln): ?>
                        <tr>
                            <td><?= e((string) $ln['period_no']) ?></td>
                            <td><?= e($ln['period_date']) ?></td>
                            <td class="align-right"><?= e(number_format((float) $ln['opening'], 2)) ?></td>
                            <td class="align-right"><?= e(number_format((float) $ln['interest'], 2)) ?></td>
                            <td class="align-right"><?= e(number_format((float) $ln['payment'], 2)) ?></td>
                            <td class="align-right"><?= e(number_format((float) $ln['principal'], 2)) ?></td>
                            <td class="align-right"><?= e(number_format((float) $ln['closing'], 2)) ?></td>
                            <td><?php if ((int) $ln['posted'] === 1): ?><span class="mbw-pill tone-green">Posted<?= $ln['voucher_id'] ? ' #' . (int) $ln['voucher_id'] : '' ?></span><?php else: ?><span class="mbw-pill tone-amber">Unposted</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($leaseLines === []): ?><tr><td colspan="8" style="text-align:center;color:var(--mbw-muted)">No schedule lines.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>
    <?php else: ?>
    <?php
    $leases = db()->prepare('SELECT ll.*, fa.asset_code, fa.carrying_amount AS rou_carrying FROM lease_liabilities ll LEFT JOIN fixed_assets fa ON fa.id = ll.asset_id WHERE ll.company_id = :cid ORDER BY ll.status ASC, ll.id DESC');
    $leases->execute(['cid' => $companyId]);
    $leases = $leases->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>New lease (IFRS 16)</h2><div class="mbw-card-tools"><span style="color:var(--mbw-muted);font-size:12.5px">ROU = liability + prepayments + initial direct costs + restoration − incentives.</span></div></div>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_lease">
            <label>Contract ref<input type="text" name="contract_ref" required></label>
            <label>ROU asset name<input type="text" name="name" required></label>
            <label>Commencement date<input type="date" name="commencement_date"></label>
            <label>Term (months)<input type="number" name="term_months" value="48"></label>
            <label>Initial lease liability<input type="number" step="0.01" name="initial_liability" value="0.00" required></label>
            <label>Level payment / period<input type="number" step="0.01" name="payment" value="0.00"></label>
            <label>Discount rate % (annual)<input type="number" step="0.0001" name="discount_rate_annual" value="0.00"></label>
            <label>Payment timing<select name="payment_timing"><option value="arrears">Arrears (period end)</option><option value="advance">Advance (period start)</option></select></label>
            <label>Prepayments<input type="number" step="0.01" name="prepayments" value="0.00"></label>
            <label>Initial direct costs<input type="number" step="0.01" name="initial_direct_costs" value="0.00"></label>
            <label>Lease incentives<input type="number" step="0.01" name="incentives" value="0.00"></label>
            <label>Restoration provision<input type="number" step="0.01" name="restoration" value="0.00"></label>
            <div class="workspace-span-2"><button type="submit"><?= icon('contracts') ?>Create lease + ROU asset</button></div>
        </form>
    </section>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Leases</h2></div>
        <div class="rc-table-scroll"><table class="rc-table">
            <thead><tr><th>Ref</th><th>ROU asset</th><th class="align-right">Initial liability</th><th class="align-right">ROU initial</th><th>Term</th><th>Rate</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($leases as $l): ?>
                    <tr><td><?= e($l['contract_ref']) ?></td><td><?= e($l['asset_code'] ?? '—') ?></td>
                        <td class="align-right"><?= e(number_format((float) $l['initial_liability'], 2)) ?></td>
                        <td class="align-right"><?= e(number_format((float) $l['rou_initial'], 2)) ?></td>
                        <td><?= e((string) (int) $l['term_months']) ?>m</td><td><?= e(number_format((float) $l['discount_rate_annual'], 2)) ?>%</td>
                        <td><span class="mbw-pill tone-blue"><?= e((string) $l['status']) ?></span></td>
                        <td><a class="button secondary" href="<?= e(url('admin/fixed-assets.php?view=leases&lease_id=' . (int) $l['id'])) ?>">Open</a></td></tr>
                <?php endforeach; ?>
                <?php if ($leases === []): ?><tr><td colspan="8" style="text-align:center;color:var(--mbw-muted)">No leases yet.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>

<?php elseif ($faView === 'mapping'): ?>
    <?php
    $cur = [];
    $ms = db()->prepare('SELECT m.purpose, m.ledger_id, l.type AS ledger_type FROM asset_ledger_mappings m JOIN ledgers l ON l.id = m.ledger_id WHERE m.company_id = :cid AND m.scope = \'global\'');
    $ms->execute(['cid' => $companyId]);
    foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $r) { $cur[$r['purpose']] = $r; }
    ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Fixed Asset Ledger Mapping</h2><div class="mbw-card-tools"><span style="color:var(--mbw-muted);font-size:12.5px">Depreciation and acquisition postings are blocked until the required purposes resolve.</span></div></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_asset_mappings">
            <div class="rc-table-scroll"><table class="rc-table">
                <thead><tr><th>Purpose</th><th>Expected type</th><th>Mapped ledger</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach (fa_mapping_purposes() as $key => $meta): $c = $cur[$key] ?? null; $cid2 = (int) ($c['ledger_id'] ?? 0); $mismatch = $c && (string) $c['ledger_type'] !== $meta['expect']; ?>
                        <tr>
                            <td><strong><?= e($meta['label']) ?></strong></td>
                            <td><span class="mbw-pill tone-gray"><?= e(ucfirst($meta['expect'])) ?></span></td>
                            <td><select name="map[<?= e($key) ?>]" style="min-width:230px"><option value="0">— not mapped —</option><?php foreach ($ledgers as $l): ?><option value="<?= e((int) $l['id']) ?>" <?= $cid2 === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['code'] . ' - ' . $l['name']) ?></option><?php endforeach; ?></select></td>
                            <td><?php if (!$c): ?><span class="mbw-pill tone-amber">Incomplete</span><?php elseif ($mismatch): ?><span class="mbw-pill tone-amber">Type warning</span><?php else: ?><span class="mbw-pill tone-green">Complete</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <div style="margin-top:12px"><button type="submit"><?= icon('accounting') ?>Save mappings</button></div>
        </form>
    </section>

<?php elseif ($faView === 'detail' && $detailAsset): ?>
    <?php
    $charge = fa_asset_monthly_charge($detailAsset);
    $schedStmt = db()->prepare('SELECT * FROM asset_depreciation_schedule WHERE asset_id = :aid ORDER BY period_no ASC');
    $schedStmt->execute(['aid' => (int) $detailAsset['id']]);
    $schedule = $schedStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2><?= e($detailAsset['name']) ?> <span class="mbw-pill tone-gray"><?= e($detailAsset['asset_code']) ?></span></h2>
            <div class="mbw-card-tools"><a class="button secondary" href="<?= e(url('admin/fixed-assets.php')) ?>">Back to register</a></div></div>
        <div class="users-view-grid">
            <div class="card">
                <p><strong>Class:</strong> <?= e($assetClasses[$detailAsset['asset_class']] ?? $detailAsset['asset_class']) ?></p>
                <p><strong>Method:</strong> <?= e($methods[$detailAsset['depreciation_method']] ?? $detailAsset['depreciation_method']) ?></p>
                <p><strong>Cost:</strong> <?= e(site_currency_symbol()) ?><?= e(number_format((float) $detailAsset['cost'], 2)) ?></p>
                <p><strong>Residual:</strong> <?= e(site_currency_symbol()) ?><?= e(number_format((float) $detailAsset['residual_value'], 2)) ?></p>
                <p><strong>Useful life:</strong> <?= e((string) (int) $detailAsset['useful_life_months']) ?> months</p>
                <p><strong>Available for use:</strong> <?= e($detailAsset['available_for_use_date'] ?? '—') ?></p>
            </div>
            <div class="card">
                <p><strong>Accumulated depreciation:</strong> <?= e(site_currency_symbol()) ?><?= e(number_format((float) $detailAsset['accumulated_depreciation'], 2)) ?></p>
                <p><strong>Accumulated impairment:</strong> <?= e(site_currency_symbol()) ?><?= e(number_format((float) $detailAsset['accumulated_impairment'], 2)) ?></p>
                <p><strong>Carrying amount:</strong> <?= e(site_currency_symbol()) ?><?= e(number_format((float) $detailAsset['carrying_amount'], 2)) ?></p>
                <p><strong>Status:</strong> <span class="mbw-pill tone-blue"><?= e(str_replace('_', ' ', (string) $detailAsset['status'])) ?></span></p>
                <p><strong>Next monthly charge:</strong> <?= e(site_currency_symbol()) ?><?= e(number_format($charge, 2)) ?></p>
                <?php if ($charge > 0 && (string) $detailAsset['status'] !== 'held_for_sale' && (string) $detailAsset['status'] !== 'disposed'): ?>
                    <form method="post" style="margin-top:8px">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="post_depreciation">
                        <input type="hidden" name="asset_id" value="<?= e((int) $detailAsset['id']) ?>">
                        <button type="submit"><?= icon('accounting') ?>Post one month's depreciation</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!in_array((string) $detailAsset['status'], ['disposed'], true)): ?>
        <div class="workspace-feature-stack" style="margin-top:16px">
            <details class="feature-disclosure">
                <summary><span><strong><?= icon('reports') ?>Impairment (IAS 36)</strong><small>Recoverable = higher of fair value less costs of disposal and value in use.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="post_impairment"><input type="hidden" name="asset_id" value="<?= e((int) $detailAsset['id']) ?>">
                    <label>Fair value less costs of disposal<input type="number" step="0.01" name="fvlcd" value="0.00"></label>
                    <label>Value in use<input type="number" step="0.01" name="value_in_use" value="0.00"></label>
                    <div class="workspace-span-2"><button type="submit"><?= icon('accounting') ?>Test &amp; post impairment</button></div>
                </form>
            </details>
            <details class="feature-disclosure">
                <summary><span><strong><?= icon('reconcile') ?>Reverse impairment (IAS 36.117)</strong><small>Reversal is capped at the carrying amount (net of normal depreciation) that would have applied had no impairment ever been recognised.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
                <?php if ((float) $detailAsset['accumulated_impairment'] <= 0): ?>
                    <p style="color:var(--mbw-muted);font-size:12.5px;margin:0 0 10px">No accumulated impairment on this asset — nothing to reverse yet.</p>
                <?php endif; ?>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reverse_impairment"><input type="hidden" name="asset_id" value="<?= e((int) $detailAsset['id']) ?>">
                    <label>Fair value less costs of disposal<input type="number" step="0.01" name="fvlcd" value="0.00"></label>
                    <label>Value in use<input type="number" step="0.01" name="value_in_use" value="0.00"></label>
                    <div class="workspace-span-2"><button type="submit"><?= icon('accounting') ?>Test &amp; post reversal</button></div>
                </form>
            </details>
            <details class="feature-disclosure">
                <summary><span><strong><?= icon('tag') ?>Classify held for sale (IFRS 5)</strong><small>Measure at lower of carrying and fair value less costs to sell; depreciation stops.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="classify_held_for_sale"><input type="hidden" name="asset_id" value="<?= e((int) $detailAsset['id']) ?>">
                    <label>Fair value<input type="number" step="0.01" name="fair_value" value="0.00"></label>
                    <label>Costs to sell<input type="number" step="0.01" name="costs_to_sell" value="0.00"></label>
                    <div class="workspace-span-2"><button type="submit"><?= icon('accounting') ?>Classify held for sale</button></div>
                </form>
            </details>
            <div class="feature-disclosure fa-revaluation-callout">
                <div>
                    <strong><?= icon('wallet') ?>Revaluation batch</strong>
                    <small>Open the class-wide IAS 16 / IAS 38 workflow. Direct single-asset posting is disabled.</small>
                </div>
                <a class="button secondary"
                   href="<?= e(url('admin/fixed-assets.php?view=revaluation&asset_id=' . (int) $detailAsset['id'])) ?>">
                    Open revaluation
                </a>
            </div>
            <?php if ((string) $detailAsset['asset_class'] === 'cwip'): ?>
            <details class="feature-disclosure">
                <summary><span><strong><?= icon('upload') ?>Capitalize from CWIP</strong><small>Reclassify Capital Work-in-Progress to an in-service asset and start depreciation.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="capitalize_cwip"><input type="hidden" name="asset_id" value="<?= e((int) $detailAsset['id']) ?>">
                    <label>Target class<select name="new_asset_class">
                        <option value="ppe">Property, Plant &amp; Equipment</option>
                        <option value="intangible">Intangible (IAS 38)</option>
                        <option value="investment_property">Investment Property</option>
                    </select></label>
                    <label>Depreciation method<select name="depreciation_method"><?php foreach ($methods as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
                    <label>Useful life (months)<input type="number" step="1" name="useful_life_months" value="60"></label>
                    <label>Residual value<input type="number" step="0.01" name="residual_value" value="0.00"></label>
                    <div class="workspace-span-2"><button type="submit"><?= icon('upload') ?>Capitalize asset</button></div>
                </form>
            </details>
            <?php endif; ?>
            <details class="feature-disclosure">
                <summary><span><strong><?= icon('download') ?>Dispose / derecognize</strong><small>Reverse cost + accumulated depreciation, recognise proceeds and gain/loss.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
                <form method="post" class="workspace-form-grid" data-confirm="Dispose of this asset? This derecognizes it and posts the disposal voucher.">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="dispose_asset"><input type="hidden" name="asset_id" value="<?= e((int) $detailAsset['id']) ?>">
                    <label>Sale proceeds<input type="number" step="0.01" name="proceeds" value="0.00"></label>
                    <div class="workspace-span-2"><button type="submit"><?= icon('accounting') ?>Dispose asset</button></div>
                </form>
            </details>
        </div>
        <?php endif; ?>
        <h3 style="margin-top:16px">Depreciation schedule (posted)</h3>
        <div class="rc-table-scroll"><table class="rc-table">
            <thead><tr><th>Period</th><th>Date</th><th class="align-right">Depreciation</th><th class="align-right">Accumulated</th><th class="align-right">Carrying</th><th>Voucher</th></tr></thead>
            <tbody>
                <?php foreach ($schedule as $s): ?>
                    <tr><td><?= e((string) $s['period_no']) ?></td><td><?= e($s['period_date']) ?></td>
                        <td class="align-right"><?= e(number_format((float) $s['depreciation'], 2)) ?></td>
                        <td class="align-right"><?= e(number_format((float) $s['accumulated'], 2)) ?></td>
                        <td class="align-right"><?= e(number_format((float) $s['carrying'], 2)) ?></td>
                        <td><?= $s['voucher_id'] ? '#' . (int) $s['voucher_id'] : '—' ?></td></tr>
                <?php endforeach; ?>
                <?php if ($schedule === []): ?><tr><td colspan="6" style="text-align:center;color:var(--mbw-muted)">No depreciation posted yet.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($faView === 'categories'): ?>
    <?php
    $catStmt = db()->prepare('SELECT * FROM asset_categories WHERE company_id = :cid ORDER BY name ASC');
    $catStmt->execute(['cid' => $companyId]);
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Add asset category</h2></div>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_category">
            <label>Name<input type="text" name="name" maxlength="150" required></label>
            <label>Class<select name="asset_class"><?php foreach ($assetClasses as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
            <label>Default method<select name="default_method"><?php foreach ($methods as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
            <label>Default life (months)<input type="number" step="1" name="default_life_months" value="60"></label>
            <label>Default rate %<input type="number" step="0.001" name="default_rate_pct" value="0.000"></label>
            <div class="workspace-span-2"><button type="submit"><?= icon('layers') ?>Add category</button></div>
        </form>
    </section>

    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Asset Categories</h2></div>
        <div class="rc-table-scroll"><table class="rc-table">
            <thead><tr><th>Name</th><th>Class</th><th>Default method</th><th class="align-right">Default life</th><th class="align-right">Default rate</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($categories as $c): ?>
                    <tr>
                        <td><?= e($c['name']) ?></td>
                        <td><?= e($assetClasses[$c['asset_class']] ?? $c['asset_class']) ?></td>
                        <td><span class="mbw-pill tone-gray"><?= e(str_replace('_', ' ', (string) $c['default_method'])) ?></span></td>
                        <td class="align-right"><?= e((string) (int) $c['default_life_months']) ?> mo</td>
                        <td class="align-right"><?= e(number_format((float) $c['default_rate_pct'], 3)) ?>%</td>
                        <td><span class="mbw-pill <?= (int) $c['is_active'] === 1 ? 'tone-green' : 'tone-gray' ?>"><?= (int) $c['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                        <td>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle_category">
                                <input type="hidden" name="category_id" value="<?= e((int) $c['id']) ?>">
                                <button type="submit" class="button secondary"><?= (int) $c['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($categories === []): ?><tr><td colspan="7" style="text-align:center;color:var(--mbw-muted)">No categories yet — add one above.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>

<?php else: ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Register a fixed asset</h2></div>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_asset">
            <label>Asset code<input type="text" name="asset_code" maxlength="80" required></label>
            <label>Name<input type="text" name="name" maxlength="190" required></label>
            <label>Class<select name="asset_class"><?php foreach ($assetClasses as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
            <label>Category<select name="category_id">
                <option value="0">— none —</option>
                <?php foreach ($activeCategories as $c): ?>
                    <option value="<?= e((int) $c['id']) ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label>Depreciation method<select name="depreciation_method"><?php foreach ($methods as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
            <label>Cost<input type="number" step="0.01" name="cost" value="0.00" required></label>
            <label>Residual value<input type="number" step="0.01" name="residual_value" value="0.00"></label>
            <label>Useful life (months)<input type="number" step="1" name="useful_life_months" value="60"></label>
            <label>Available for use date<input type="date" name="available_for_use_date"></label>
            <div class="workspace-span-2"><button type="submit"><?= icon('companies') ?>Register asset</button></div>
        </form>
    </section>

    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Asset Register</h2>
            <div class="mbw-card-tools">
                <form method="get" style="display:flex;gap:8px;align-items:center">
                    <input type="hidden" name="view" value="register">
                    <select name="status" onchange="this.form.submit()">
                        <option value="">All statuses</option>
                        <?php foreach ($assetStatuses as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="class" onchange="this.form.submit()">
                        <option value="">All classes</option>
                        <?php foreach ($assetClasses as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= $classFilter === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>
        <div class="rc-table-scroll"><table class="rc-table">
            <thead><tr><th>Code</th><th>Name</th><th>Class</th><th>Method</th><th class="align-right">Cost</th><th class="align-right">Accum. dep.</th><th class="align-right">Carrying</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($assets as $a): ?>
                    <tr>
                        <td><?= e($a['asset_code']) ?></td>
                        <td><?= e($a['name']) ?></td>
                        <td><?= e($assetClasses[$a['asset_class']] ?? $a['asset_class']) ?></td>
                        <td><span class="mbw-pill tone-gray"><?= e(str_replace('_', ' ', (string) $a['depreciation_method'])) ?></span></td>
                        <td class="align-right"><?= e(number_format((float) $a['cost'], 2)) ?></td>
                        <td class="align-right"><?= e(number_format((float) $a['accumulated_depreciation'], 2)) ?></td>
                        <td class="align-right"><?= e(number_format((float) $a['carrying_amount'], 2)) ?></td>
                        <td><span class="mbw-pill <?= (string) $a['status'] === 'active' ? 'tone-green' : 'tone-gray' ?>"><?= e(str_replace('_', ' ', (string) $a['status'])) ?></span></td>
                        <td><div class="fa-row-actions"><a class="button secondary" href="<?= e(url('admin/fixed-assets.php?view=' . (int) $a['id'])) ?>">Open</a><a class="button secondary" href="<?= e(url('admin/fixed-assets.php?view=revaluation&asset_id=' . (int) $a['id'])) ?>">Revalue</a></div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($assets === []): ?><tr><td colspan="9" style="text-align:center;color:var(--mbw-muted)">No assets registered yet — add one above.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
