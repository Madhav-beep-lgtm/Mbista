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
$classMeasurementModels = fa_class_measurement_models($companyId);

// Counterparties for acquisitions (suppliers), disposals (buyers) and leases
// (lessors): each transaction picks its own party.
$faParties = [];
if (table_exists('accounting_parties')) {
    $faPartiesStmt = db()->prepare("SELECT id, code, name, party_type FROM accounting_parties WHERE company_id = :cid AND status = 'active' ORDER BY name ASC");
    $faPartiesStmt->execute(['cid' => $companyId]);
    $faParties = $faPartiesStmt->fetchAll(PDO::FETCH_ASSOC);
}

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

/**
 * Sets (or with ledger id 0 clears) ONE asset-scope ledger mapping. Ledgers
 * are chosen per asset on the create forms and the asset page, so this is
 * the only scope the UI writes; fa_resolve_mapping still reads asset →
 * category → global, which keeps assets from before this arrangement working.
 */
function fa_set_asset_ledger(int $companyId, int $assetId, string $purpose, int $ledgerId, int $userId): void
{
    if ($assetId <= 0) {
        return;
    }
    if ($ledgerId > 0) {
        $own = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :id AND company_id = :cid');
        $own->execute(['id' => $ledgerId, 'cid' => $companyId]);
        if ((int) $own->fetchColumn() === 0) {
            return;
        }
    }
    db()->prepare("DELETE FROM asset_ledger_mappings WHERE company_id = :cid AND scope = 'asset' AND asset_id = :aid AND purpose = :p AND category_id IS NULL")
        ->execute(['cid' => $companyId, 'aid' => $assetId, 'p' => $purpose]);
    if ($ledgerId > 0) {
        db()->prepare("INSERT INTO asset_ledger_mappings (company_id, scope, category_id, asset_id, purpose, ledger_id, created_by) VALUES (:cid, 'asset', NULL, :aid, :p, :lid, :uid)")
            ->execute(['cid' => $companyId, 'aid' => $assetId, 'p' => $purpose, 'lid' => $ledgerId, 'uid' => $userId]);
    }
}

/**
 * Resolves the single "Funded from" select: "L<id>" credits that ledger
 * directly, "P<id>" credits the supplier's payable ledger (created on
 * demand) and tags the voucher with the party. Returns [ledgerRow|null,
 * partyId|null].
 */
function fa_funded_from_ledger(int $companyId, string $value): array
{
    $value = trim($value);
    if (preg_match('/^P(\d+)$/', $value, $m)) {
        $partyId = (int) $m[1];
        $chk = db()->prepare('SELECT COUNT(*) FROM accounting_parties WHERE id = :id AND company_id = :cid');
        $chk->execute(['id' => $partyId, 'cid' => $companyId]);
        if ((int) $chk->fetchColumn() === 0) {
            return [null, null];
        }
        $ledgerId = ensure_party_ledger($companyId, $partyId, 'payable');
        if ($ledgerId <= 0) {
            return [null, null];
        }
        $row = db()->prepare('SELECT * FROM ledgers WHERE id = :id LIMIT 1');
        $row->execute(['id' => $ledgerId]);
        $ledger = $row->fetch(PDO::FETCH_ASSOC) ?: null;
        return [$ledger, $ledger ? $partyId : null];
    }
    if (preg_match('/^L(\d+)$/', $value, $m)) {
        $row = db()->prepare('SELECT * FROM ledgers WHERE id = :id AND company_id = :cid LIMIT 1');
        $row->execute(['id' => (int) $m[1], 'cid' => $companyId]);
        return [$row->fetch(PDO::FETCH_ASSOC) ?: null, null];
    }
    return [null, null];
}

/** <select> of the company's ledgers; value = ledger id. */
function fa_ledger_select(string $name, array $ledgers, int $selectedId = 0, string $placeholder = '— choose ledger —'): string
{
    $html = '<select name="' . e($name) . '"><option value="0">' . e($placeholder) . '</option>';
    foreach ($ledgers as $l) {
        $html .= '<option value="' . (int) $l['id'] . '"' . ((int) $l['id'] === $selectedId ? ' selected' : '') . '>' . e($l['code'] . ' - ' . $l['name']) . '</option>';
    }
    return $html . '</select>';
}

/**
 * The single "Funded from" <select>: pick a ledger directly (cash/bank,
 * clearing, opening equity, …) or a supplier for on-credit purchases —
 * the supplier's payable ledger is created automatically. No separate
 * funding-mode + supplier pair.
 */
function fa_funded_from_select(string $name, array $ledgers, array $parties, int $preselectLedgerId = 0): string
{
    $html = '<select name="' . e($name) . '"><option value="">— choose where the credit goes —</option>';
    $html .= '<optgroup label="Ledgers (cash/bank, clearing, opening equity…)">';
    foreach ($ledgers as $l) {
        $html .= '<option value="L' . (int) $l['id'] . '"' . ((int) $l['id'] === $preselectLedgerId ? ' selected' : '') . '>' . e($l['code'] . ' - ' . $l['name']) . '</option>';
    }
    $html .= '</optgroup><optgroup label="Suppliers — on credit (payable ledger auto-created)">';
    foreach ($parties as $p) {
        $html .= '<option value="P' . (int) $p['id'] . '">' . e('Supplier: ' . $p['name'] . ' (' . $p['code'] . ')') . '</option>';
    }
    return $html . '</optgroup></select>';
}

/** The lifecycle ledger purposes that apply to ONE asset, by its class. */
function fa_purposes_for_asset(array $asset): array
{
    $eventPurposes = ['impairment_loss', 'impairment_reversal_income', 'asset_held_for_sale', 'gain_on_disposal', 'loss_on_disposal', 'disposal_clearing', 'revaluation_surplus', 'revaluation_loss'];
    return match ((string) ($asset['asset_class'] ?? 'ppe')) {
        'cwip' => array_merge(['cwip', 'ppe_cost', 'acquisition_clearing'], $eventPurposes),
        'rou' => array_merge(['rou_asset', 'lease_liability', 'lease_interest_expense', 'acquisition_clearing', 'depreciation_expense', 'accumulated_depreciation'], $eventPurposes),
        default => array_merge(['ppe_cost', 'acquisition_clearing', 'depreciation_expense', 'accumulated_depreciation'], $eventPurposes),
    };
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

            // The ledgers picked on the form belong to THIS asset only —
            // stored as asset-scope mappings so depreciation, additions and
            // every later event post to them without any separate mapping step.
            // Work under construction accumulates in the CWIP ledger, not PPE —
            // it only lands in PPE when capitalize_cwip reclassifies it.
            $costPurpose = $class === 'cwip' ? 'cwip' : 'ppe_cost';
            fa_set_asset_ledger($companyId, $assetId, $costPurpose, (int) ($_POST['cost_ledger_id'] ?? 0), $userId);
            fa_set_asset_ledger($companyId, $assetId, 'depreciation_expense', (int) ($_POST['dep_expense_ledger_id'] ?? 0), $userId);
            fa_set_asset_ledger($companyId, $assetId, 'accumulated_depreciation', (int) ($_POST['accum_dep_ledger_id'] ?? 0), $userId);
            $costLedger = fa_resolve_mapping($companyId, $costPurpose, $assetId);

            // One "Funded from" choice: a ledger (cash/bank, clearing, opening
            // equity, …) or a supplier bought on credit. The legacy
            // funding_mode/supplier fields still work as a fallback.
            [$creditLedger, $voucherPartyId] = fa_funded_from_ledger($companyId, (string) ($_POST['funded_from'] ?? ''));
            if (!$creditLedger) {
                $fundingModeRaw = (string) ($_POST['funding_mode'] ?? 'clearing');
                $fundingMode = in_array($fundingModeRaw, ['clearing', 'supplier', 'cash', 'opening'], true) ? $fundingModeRaw : 'clearing';
                [$creditLedger, $voucherPartyId] = fa_counterparty_ledger($companyId, $fundingMode, (int) ($_POST['supplier_party_id'] ?? 0), 'acquisition_clearing', $assetId);
            }
            if ($creditLedger) {
                // Future additions and retro-posts default to the same funding.
                fa_set_asset_ledger($companyId, $assetId, 'acquisition_clearing', (int) $creditLedger['id'], $userId);
            }

            if ($cost > 0 && $costLedger && $creditLedger) {
                $narrationBy = $voucherPartyId !== null
                    ? ' (on credit — ' . ($creditLedger['name'] ?? 'supplier') . ')'
                    : ' (funded from ' . ($creditLedger['name'] ?? 'ledger') . ')';
                create_voucher_with_entries([
                    'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                    'voucher_no' => 'FA-ACQ-' . $code, 'voucher_type' => 'journal',
                    'voucher_date' => $availableDate ?: date('Y-m-d'),
                    'source_type' => 'fixed_asset_acquisition', 'source_id' => $assetId,
                    'party_id' => $voucherPartyId,
                    'total_amount' => $cost, 'narration' => 'Acquisition of ' . $name . ' (' . $code . ')' . $narrationBy . '.',
                    'status' => 'posted', 'posted_by' => $userId,
                ], [
                    ['ledger_id' => (int) $costLedger['id'], 'entry_type' => 'debit', 'amount' => $cost],
                    ['ledger_id' => (int) $creditLedger['id'], 'entry_type' => 'credit', 'amount' => $cost],
                ]);
                flash('success', 'Asset ' . $code . ' registered and acquisition voucher FA-ACQ-' . $code . ' posted: Dr ' . ($costLedger['name'] ?? 'asset cost') . ' / Cr ' . ($creditLedger['name'] ?? 'funding') . '.');
            } elseif ($cost > 0) {
                // A skipped acquisition voucher must be loud: the register and
                // the ledger disagree until it is posted (see post_acquisition).
                flash('error', 'Asset ' . $code . ' was registered but NOT posted to the ledger — choose the asset cost ledger and the "Funded from" ledger on the form (they apply to this asset only). Open the asset and use "Post acquisition to ledger" to post it now.');
            } else {
                flash('success', 'Asset ' . $code . ' registered.');
            }
        } catch (Throwable $e) {
            flash('error', (string) $e->getCode() === '23000' ? 'Asset code ' . $code . ' already exists.' : 'Could not register asset: ' . $e->getMessage());
        }
        redirect('admin/fixed-assets.php');
    }

    if ($action === 'delete_asset') {
        // Permanently removes a mistakenly registered asset together with
        // every voucher it generated (acquisition, additions, depreciation,
        // impairments, held-for-sale, disposal, CWIP capitalization, lease
        // journals). Correct disposals of REAL assets go through
        // dispose_asset — this is the undo for wrong entries.
        require_permission('accounting', 'edit');
        if (!user_can('delete')) {
            flash('error', 'You do not have permission to delete assets. Ask an administrator to grant the Delete capability.');
            redirect('admin/fixed-assets.php');
        }
        $asset = fa_company_asset((int) ($_POST['asset_id'] ?? 0), $companyId);
        if (!$asset) {
            flash('error', 'Asset not found for this company.');
            redirect('admin/fixed-assets.php');
        }
        $assetId = (int) $asset['id'];

        // Revaluation journals belong to the batch workflow — the batch has
        // to be rejected/removed before its assets can be deleted.
        if (table_exists('asset_revaluation_lines')) {
            $revStmt = db()->prepare('SELECT COUNT(*) FROM asset_revaluation_lines l INNER JOIN asset_revaluation_batches b ON b.id = l.batch_id WHERE l.asset_id = :aid AND b.company_id = :cid');
            $revStmt->execute(['aid' => $assetId, 'cid' => $companyId]);
            if ((int) $revStmt->fetchColumn() > 0) {
                flash('error', 'This asset appears in a revaluation batch. Reject or remove it from the batch before deleting the asset.');
                redirect('admin/fixed-assets.php?view=' . $assetId);
            }
        }

        // Collect every voucher this asset generated.
        $voucherIds = [];
        $collect = static function (string $sql, array $params) use (&$voucherIds): void {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if ((int) $id > 0) {
                    $voucherIds[(int) $id] = true;
                }
            }
        };
        $collect("SELECT id FROM vouchers WHERE company_id = :cid AND source_id = :aid AND source_type IN ('fixed_asset_acquisition', 'asset_disposal', 'asset_held_for_sale', 'asset_cwip_capitalization')", ['cid' => $companyId, 'aid' => $assetId]);
        // Additions and capitalized borrowing costs post with source_id NULL;
        // their voucher_no embeds the asset code.
        $collect("SELECT id FROM vouchers WHERE company_id = :cid AND source_type = 'fixed_asset_addition' AND voucher_no LIKE :pattern", ['cid' => $companyId, 'pattern' => 'FA-ADD-' . $asset['asset_code'] . '-%']);
        $collect("SELECT id FROM vouchers WHERE company_id = :cid AND source_type = 'asset_borrowing_cost' AND voucher_no LIKE :pattern", ['cid' => $companyId, 'pattern' => 'FA-INT-' . $asset['asset_code'] . '-%']);
        $collect('SELECT voucher_id FROM asset_depreciation_schedule WHERE asset_id = :aid AND voucher_id IS NOT NULL', ['aid' => $assetId]);
        $collect('SELECT voucher_id FROM asset_impairments WHERE asset_id = :aid AND voucher_id IS NOT NULL', ['aid' => $assetId]);
        $collect("SELECT v.id FROM vouchers v INNER JOIN asset_impairments i ON i.id = v.source_id WHERE v.company_id = :cid AND v.source_type IN ('asset_impairment', 'asset_impairment_reversal') AND i.asset_id = :aid", ['cid' => $companyId, 'aid' => $assetId]);
        $leaseIds = [];
        if (table_exists('lease_liabilities')) {
            $leaseStmt = db()->prepare('SELECT id FROM lease_liabilities WHERE asset_id = :aid AND company_id = :cid');
            $leaseStmt->execute(['aid' => $assetId, 'cid' => $companyId]);
            $leaseIds = array_map('intval', $leaseStmt->fetchAll(PDO::FETCH_COLUMN));
            if ($leaseIds !== []) {
                $leasePlaceholders = implode(',', array_fill(0, count($leaseIds), '?'));
                $collect("SELECT id FROM vouchers WHERE company_id = ? AND source_type = 'lease_commencement' AND source_id IN ($leasePlaceholders)", array_merge([$companyId], $leaseIds));
                $collect("SELECT voucher_id FROM lease_schedule_lines WHERE voucher_id IS NOT NULL AND lease_id IN ($leasePlaceholders)", $leaseIds);
                foreach ($leaseIds as $modLeaseId) {
                    $collect("SELECT id FROM vouchers WHERE company_id = ? AND source_type = 'lease_modification' AND voucher_no LIKE ?", [$companyId, 'FA-MOD-' . $modLeaseId . '-%']);
                }
            }
        }
        $voucherIds = array_keys($voucherIds);

        // Same blockers as voucher delete: reconciled entries, locked periods.
        if ($voucherIds !== []) {
            $vPlaceholders = implode(',', array_fill(0, count($voucherIds), '?'));
            if (column_exists('voucher_entries', 'reconciled_at')) {
                $reconStmt = db()->prepare("SELECT COUNT(*) FROM voucher_entries WHERE voucher_id IN ($vPlaceholders) AND reconciled_at IS NOT NULL");
                $reconStmt->execute($voucherIds);
                if ((int) $reconStmt->fetchColumn() > 0) {
                    flash('error', 'A voucher of this asset has bank-reconciled entries. Undo the reconciliation before deleting the asset.');
                    redirect('admin/fixed-assets.php?view=' . $assetId);
                }
            }
            $lockProbe = db()->prepare("SELECT voucher_no, fiscal_year_id, voucher_date FROM vouchers WHERE id IN ($vPlaceholders)");
            $lockProbe->execute($voucherIds);
            foreach ($lockProbe->fetchAll(PDO::FETCH_ASSOC) as $lockRow) {
                if (is_period_locked($companyId, (int) ($lockRow['fiscal_year_id'] ?? 0), (string) ($lockRow['voucher_date'] ?? ''))) {
                    flash('error', 'Voucher ' . $lockRow['voucher_no'] . ' of this asset is inside a locked accounting period. Unlock the period first.');
                    redirect('admin/fixed-assets.php?view=' . $assetId);
                }
            }
        }

        try {
            db()->beginTransaction();
            if ($voucherIds !== []) {
                $vPlaceholders = implode(',', array_fill(0, count($voucherIds), '?'));
                db()->prepare("DELETE FROM vouchers WHERE company_id = ? AND id IN ($vPlaceholders)")->execute(array_merge([$companyId], $voucherIds));
            }
            if ($leaseIds !== []) {
                $leasePlaceholders = implode(',', array_fill(0, count($leaseIds), '?'));
                db()->prepare("DELETE FROM lease_schedule_lines WHERE lease_id IN ($leasePlaceholders)")->execute($leaseIds);
                db()->prepare("DELETE FROM lease_liabilities WHERE id IN ($leasePlaceholders)")->execute($leaseIds);
            }
            // Depreciation schedule, impairment events, and ledger mappings
            // cascade on the fixed_assets FK.
            db()->prepare('DELETE FROM fixed_assets WHERE id = :id AND company_id = :cid')->execute(['id' => $assetId, 'cid' => $companyId]);
            db()->commit();
            $summary = 'Asset ' . $asset['asset_code'] . ' (' . $asset['name'] . ') deleted with ' . count($voucherIds) . ' linked voucher(s).';
            security_event('asset_deleted', 'success', $summary, $companyId, $userId);
            log_activity('fixed_asset', $assetId, 'deleted', $summary, $userId);
            flash('success', $summary);
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not delete the asset: ' . $e->getMessage());
        }
        redirect('admin/fixed-assets.php');
    }

    if ($action === 'post_acquisition') {
        // Retroactively posts the acquisition voucher for an asset that was
        // registered while the ledger mappings were missing (the register
        // used to skip the voucher silently). Same posting as add_asset:
        // Dr PPE/CWIP cost, Cr chosen funding leg.
        require_permission('accounting', 'post');
        $asset = fa_company_asset((int) ($_POST['asset_id'] ?? 0), $companyId);
        if (!$asset) {
            flash('error', 'Asset not found for this company.');
            redirect('admin/fixed-assets.php');
        }
        $assetId = (int) $asset['id'];
        if ((string) $asset['asset_class'] === 'rou') {
            flash('error', 'Right-of-use assets are posted through lease commencement, not an acquisition voucher.');
            redirect('admin/fixed-assets.php?view=' . $assetId);
        }
        $existsStmt = db()->prepare("SELECT id FROM vouchers WHERE source_type = 'fixed_asset_acquisition' AND source_id = :aid LIMIT 1");
        $existsStmt->execute(['aid' => $assetId]);
        if ($existsStmt->fetch()) {
            flash('error', 'The acquisition voucher for this asset is already posted.');
            redirect('admin/fixed-assets.php?view=' . $assetId);
        }
        // Additions raise fixed_assets.cost and post their own vouchers, so
        // the acquisition amount is the recorded cost minus posted additions.
        $addedStmt = db()->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE company_id = :cid AND source_type = 'fixed_asset_addition' AND voucher_no LIKE :pattern");
        $addedStmt->execute(['cid' => $companyId, 'pattern' => 'FA-ADD-' . $asset['asset_code'] . '-%']);
        $amount = round((float) $asset['cost'] - (float) $addedStmt->fetchColumn(), 2);
        if ($amount <= 0) {
            flash('error', 'Nothing to post — the asset cost is already covered by posted vouchers.');
            redirect('admin/fixed-assets.php?view=' . $assetId);
        }
        $voucherDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['voucher_date'] ?? '')) ? (string) $_POST['voucher_date'] : ((string) ($asset['available_for_use_date'] ?? '') ?: date('Y-m-d'));
        if (is_period_locked($companyId, $fiscalYearId, $voucherDate)) {
            flash('error', 'The chosen voucher date is inside a locked accounting period.');
            redirect('admin/fixed-assets.php?view=' . $assetId);
        }
        $costPurpose = (string) $asset['asset_class'] === 'cwip' ? 'cwip' : 'ppe_cost';
        // A cost ledger picked here sticks to this asset for all later posts.
        fa_set_asset_ledger($companyId, $assetId, $costPurpose, (int) ($_POST['cost_ledger_id'] ?? 0), $userId);
        $costLedger = fa_resolve_mapping($companyId, $costPurpose, $assetId);
        [$creditLedger, $voucherPartyId] = fa_funded_from_ledger($companyId, (string) ($_POST['funded_from'] ?? ''));
        if (!$creditLedger) {
            $fundingModeRaw = (string) ($_POST['funding_mode'] ?? 'clearing');
            $fundingMode = in_array($fundingModeRaw, ['clearing', 'supplier', 'cash', 'opening'], true) ? $fundingModeRaw : 'clearing';
            [$creditLedger, $voucherPartyId] = fa_counterparty_ledger($companyId, $fundingMode, (int) ($_POST['supplier_party_id'] ?? 0), 'acquisition_clearing', $assetId);
        }
        if (!$costLedger || !$creditLedger) {
            $costLabel = fa_mapping_purposes()[$costPurpose]['label'] ?? $costPurpose;
            flash('error', 'Choose the ' . $costLabel . ' ledger and the "Funded from" ledger — both are needed to post the acquisition.');
            redirect('admin/fixed-assets.php?view=' . $assetId);
        }
        fa_set_asset_ledger($companyId, $assetId, 'acquisition_clearing', (int) $creditLedger['id'], $userId);
        try {
            $narrationBy = $voucherPartyId !== null
                ? ' (on credit — ' . ($creditLedger['name'] ?? 'supplier') . ')'
                : ' (funded from ' . ($creditLedger['name'] ?? 'ledger') . ')';
            create_voucher_with_entries([
                'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                'voucher_no' => 'FA-ACQ-' . $asset['asset_code'], 'voucher_type' => 'journal',
                'voucher_date' => $voucherDate,
                'source_type' => 'fixed_asset_acquisition', 'source_id' => $assetId,
                'party_id' => $voucherPartyId,
                'total_amount' => $amount, 'narration' => 'Acquisition of ' . $asset['name'] . ' (' . $asset['asset_code'] . ')' . $narrationBy . ' — posted retrospectively.',
                'status' => 'posted', 'posted_by' => $userId,
            ], [
                ['ledger_id' => (int) $costLedger['id'], 'entry_type' => 'debit', 'amount' => $amount],
                ['ledger_id' => (int) $creditLedger['id'], 'entry_type' => 'credit', 'amount' => $amount],
            ]);
            security_event('asset_acquisition_posted', 'success', 'Acquisition voucher posted retrospectively for asset #' . $assetId . '.', $companyId, $userId);
            log_activity('fixed_asset', $assetId, 'acquisition_posted', 'Acquisition voucher FA-ACQ-' . $asset['asset_code'] . ' (' . number_format($amount, 2) . ') posted retrospectively.', $userId);
            flash('success', 'Acquisition voucher FA-ACQ-' . $asset['asset_code'] . ' posted: Dr ' . ($costLedger['name'] ?? 'Asset cost') . ' / Cr ' . ($creditLedger['name'] ?? 'funding') . ' ' . site_currency_symbol() . number_format($amount, 2) . '.');
        } catch (Throwable $e) {
            flash('error', 'Could not post the acquisition: ' . $e->getMessage());
        }
        redirect('admin/fixed-assets.php?view=' . $assetId);
    }

    if ($action === 'add_asset_cost') {
        // Subsequent expenditure capitalized onto an existing asset (IAS 16.13):
        // Dr asset cost / Cr supplier payable | cash | clearing. Raises cost and
        // carrying amount; future depreciation charges pick the new base up
        // automatically because the engine derives them from the stored values.
        require_permission('accounting', 'post');
        $asset = fa_company_asset((int) ($_POST['asset_id'] ?? 0), $companyId);
        if (!$asset) { flash('error', 'Asset not found.'); redirect('admin/fixed-assets.php'); }
        if ((string) $asset['status'] === 'disposed') {
            flash('error', 'Cannot add cost to a disposed asset.');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $costDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['cost_date'] ?? '')) ? (string) $_POST['cost_date'] : date('Y-m-d');
        $memo = trim((string) ($_POST['memo'] ?? ''));
        if ($amount <= 0) {
            flash('error', 'Additional cost must be greater than zero.');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        $costPurpose = (string) $asset['asset_class'] === 'cwip' ? 'cwip' : 'ppe_cost';
        $costLedger = fa_resolve_mapping($companyId, $costPurpose, (int) $asset['id']);
        // "Funded from" works like at registration: one choice, ledger or
        // supplier; the asset's own funding ledger is the default fallback.
        [$creditLedger, $voucherPartyId] = fa_funded_from_ledger($companyId, (string) ($_POST['funded_from'] ?? ''));
        if (!$creditLedger) {
            $fundingModeRaw = (string) ($_POST['funding_mode'] ?? 'clearing');
            $fundingMode = in_array($fundingModeRaw, ['clearing', 'supplier', 'cash', 'opening'], true) ? $fundingModeRaw : 'clearing';
            [$creditLedger, $voucherPartyId] = fa_counterparty_ledger($companyId, $fundingMode, (int) ($_POST['supplier_party_id'] ?? 0), 'acquisition_clearing', (int) $asset['id']);
        }
        if (!$costLedger || !$creditLedger) {
            flash('error', 'Choose the "Funded from" ledger (and set the asset cost ledger on the asset page) before adding cost.');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        try {
            db()->beginTransaction();
            create_voucher_with_entries([
                'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                'voucher_no' => 'FA-ADD-' . $asset['asset_code'] . '-' . date('His'),
                'voucher_type' => 'journal', 'voucher_date' => $costDate,
                'source_type' => 'fixed_asset_addition', 'source_id' => null,
                'party_id' => $voucherPartyId,
                'total_amount' => $amount,
                'narration' => 'Additional cost on ' . $asset['name'] . ' (' . $asset['asset_code'] . ')' . ($memo !== '' ? ' — ' . $memo : '') . '.',
                'status' => 'posted', 'posted_by' => $userId,
            ], [
                ['ledger_id' => (int) $costLedger['id'], 'entry_type' => 'debit', 'amount' => $amount],
                ['ledger_id' => (int) $creditLedger['id'], 'entry_type' => 'credit', 'amount' => $amount],
            ]);
            db()->prepare('UPDATE fixed_assets SET cost = cost + :a, carrying_amount = carrying_amount + :a2 WHERE id = :id AND company_id = :cid')
                ->execute(['a' => $amount, 'a2' => $amount, 'id' => (int) $asset['id'], 'cid' => $companyId]);
            db()->commit();
            security_event('asset_cost_added', 'success', 'Additional cost ' . number_format($amount, 2) . ' capitalized on asset #' . (int) $asset['id'] . '.', $companyId, $userId);
            log_activity('fixed_asset', (int) $asset['id'], 'cost_added', 'Additional cost ' . number_format($amount, 2) . ' capitalized.', $userId);
            flash('success', 'Additional cost ' . site_currency_symbol() . number_format($amount, 2) . ' capitalized onto ' . $asset['asset_code'] . '.');
        } catch (Throwable $e) { if (db()->inTransaction()) { db()->rollBack(); } flash('error', 'Could not add cost: ' . $e->getMessage()); }
        redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
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
        // Part-period pro-ration (IAS 16.55: depreciation runs from the date the
        // asset is available for use). An asset acquired 15 days before the
        // fiscal year closes is charged 15/30 of the monthly amount into that
        // year instead of a full month; the depreciable total is unchanged —
        // the tail of the schedule simply extends by the unposted fraction.
        $periodDays = (int) ($_POST['period_days'] ?? 30);
        if ($periodDays < 1 || $periodDays > 31) {
            $periodDays = 30;
        }
        $periodFraction = min(1.0, $periodDays / 30);
        if ($periodFraction < 1.0) {
            $charge = round($charge * $periodFraction, 2);
            $remainingDepreciable = max(0.0, round((float) $asset['carrying_amount'] - (float) $asset['residual_value'], 2));
            $charge = min($charge, $remainingDepreciable);
        }
        if ($charge <= 0) {
            flash('error', 'The pro-rated charge is zero — nothing was posted.');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        // Posting is BLOCKED unless both ledgers resolve (mapping-before-post).
        $expLedger = fa_resolve_mapping($companyId, 'depreciation_expense', (int) $asset['id']);
        $accLedger = fa_resolve_mapping($companyId, 'accumulated_depreciation', (int) $asset['id']);
        if (!$expLedger || !$accLedger) {
            flash('error', 'Cannot post depreciation: set the Depreciation expense and Accumulated depreciation ledgers in "This asset posts to" on this asset\'s page first.');
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
                'narration' => 'Depreciation period ' . $periodNo . ($periodDays < 30 ? ' (' . $periodDays . '/30 days, pro-rata)' : '') . ' — ' . $asset['name'] . ' (' . $asset['asset_code'] . ').',
                'status' => 'posted', 'posted_by' => $userId,
            ], [
                ['ledger_id' => (int) $expLedger['id'], 'entry_type' => 'debit', 'amount' => $charge],
                ['ledger_id' => (int) $accLedger['id'], 'entry_type' => 'credit', 'amount' => $charge],
            ]);
            if ($voucherId <= 0) {
                // Never record a "posted" schedule row whose voucher does not exist.
                throw new RuntimeException('The depreciation voucher could not be posted — nothing was recorded.');
            }

            db()->prepare('INSERT INTO asset_depreciation_schedule (company_id, asset_id, period_no, period_date, depreciation, accumulated, carrying, voucher_id, posted)
                VALUES (:cid, :aid, :pno, :pdate, :dep, :acc, :carry, :vid, 1)')
                ->execute(['cid' => $companyId, 'aid' => (int) $asset['id'], 'pno' => $periodNo, 'pdate' => $today, 'dep' => $charge, 'acc' => $newAccum, 'carry' => $newCarrying, 'vid' => $voucherId]);

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
            if ($vid <= 0) {
                // Register-and-GL must move together: an unposted voucher with a
                // recorded event is exactly how registers drift from the books.
                throw new RuntimeException('The impairment voucher could not be posted — nothing was recorded.');
            }
            db()->prepare('UPDATE asset_impairments SET voucher_id = :vid WHERE id = :id')
                ->execute(['vid' => $vid, 'id' => $eventId]);
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
            if ($vid <= 0) {
                throw new RuntimeException('The reversal voucher could not be posted — nothing was recorded.');
            }
            db()->prepare('UPDATE asset_impairments SET voucher_id = :vid WHERE id = :id')
                ->execute(['vid' => $vid, 'id' => $eventId]);
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
        // The write-down must reach the ledger or nothing happens at all —
        // recording the event while skipping the voucher is how the register
        // and the books drift apart.
        if ($hfs['impairment'] > 0) {
            $lossL = fa_resolve_mapping($companyId, 'impairment_loss', (int) $asset['id']);
            $accL = fa_resolve_mapping($companyId, 'accumulated_impairment', (int) $asset['id']);
            if (!$lossL || !$accL) {
                flash('error', 'Set the Impairment Loss and Accumulated Impairment ledgers in "This asset posts to" on this asset\'s page before classifying — the write-down of ' . site_currency_symbol() . number_format($hfs['impairment'], 2) . ' cannot post without them.');
                redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
            }
        }
        try {
            db()->beginTransaction();
            $vid = 0;
            if ($hfs['impairment'] > 0) {
                $vid = create_voucher_with_entries([
                    'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                    'voucher_no' => 'FA-HFS-' . $asset['asset_code'], 'voucher_type' => 'journal', 'voucher_date' => date('Y-m-d'),
                    'source_type' => 'asset_held_for_sale', 'source_id' => (int) $asset['id'], 'total_amount' => $hfs['impairment'],
                    'narration' => 'Held-for-sale write-down of ' . $asset['name'] . ' (IFRS 5).', 'status' => 'posted', 'posted_by' => $userId,
                ], [
                    ['ledger_id' => (int) $lossL['id'], 'entry_type' => 'debit', 'amount' => $hfs['impairment']],
                    ['ledger_id' => (int) $accL['id'], 'entry_type' => 'credit', 'amount' => $hfs['impairment']],
                ]);
                if ($vid <= 0) {
                    throw new RuntimeException('The write-down voucher could not be posted — nothing was recorded.');
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
        // Never derecognize what was never recognized: a disposal voucher
        // against an asset whose acquisition/commencement never reached the
        // ledger leaves one-sided history on the cost and accumulated
        // ledgers (credits with no matching debits).
        if ((float) $asset['cost'] > 0) {
            $onBooksStmt = (string) $asset['asset_class'] === 'rou'
                ? db()->prepare("SELECT COUNT(*) FROM vouchers v INNER JOIN lease_liabilities ll ON ll.id = v.source_id WHERE v.source_type = 'lease_commencement' AND ll.asset_id = :aid AND v.company_id = :cid")
                : db()->prepare("SELECT COUNT(*) FROM vouchers WHERE source_type = 'fixed_asset_acquisition' AND source_id = :aid AND company_id = :cid");
            $onBooksStmt->execute(['aid' => (int) $asset['id'], 'cid' => $companyId]);
            if ((int) $onBooksStmt->fetchColumn() === 0) {
                flash('error', 'This asset\'s ' . ((string) $asset['asset_class'] === 'rou' ? 'lease commencement' : 'acquisition') . ' was never posted to the ledger — post it first (see the red panel on the asset page), then dispose. Disposing now would credit ledgers that were never debited.');
                redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
            }
        }
        $proceeds = max(0.0, round((float) ($_POST['proceeds'] ?? 0), 2));
        $cost = (float) $asset['cost'];
        $accumDep = (float) $asset['accumulated_depreciation'];
        $accumImp = (float) $asset['accumulated_impairment'];
        $carrying = round($cost - $accumDep - $accumImp, 2);
        $gainLoss = round($proceeds - $carrying, 2); // + gain, - loss
        // Derecognition: reverse cost + accumulated, recognise proceeds + gain/loss.
        $costL = fa_resolve_mapping($companyId, 'ppe_cost', (int) $asset['id']);
        $accDepL = fa_resolve_mapping($companyId, 'accumulated_depreciation', (int) $asset['id']);
        // Proceeds land per-transaction: sold on credit to a specific buyer
        // (their receivable ledger), cash sale, or the legacy clearing ledger.
        $proceedsModeRaw = (string) ($_POST['proceeds_mode'] ?? 'clearing');
        $proceedsMode = in_array($proceedsModeRaw, ['clearing', 'buyer', 'cash'], true) ? $proceedsModeRaw : 'clearing';
        [$procL, $disposalPartyId] = fa_counterparty_ledger($companyId, $proceedsMode, (int) ($_POST['buyer_party_id'] ?? 0), 'disposal_clearing', (int) $asset['id']);
        if (!$costL || !$accDepL || !$procL) {
            flash('error', $proceedsMode === 'buyer'
                ? 'Select a valid buyer (its receivable ledger is created automatically), and map PPE Cost + Accumulated Depreciation.'
                : 'Map PPE Cost, Accumulated Depreciation and Disposal Clearing before disposing.');
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
                'party_id' => $disposalPartyId,
                'narration' => 'Disposal of ' . $asset['name'] . ' — ' . ($gainLoss >= 0 ? 'gain ' : 'loss ') . number_format(abs($gainLoss), 2) . ($disposalPartyId ? ' (buyer: ' . ($procL['name'] ?? 'party') . ')' : '') . '.',
                'status' => 'posted', 'posted_by' => $userId,
            ], $entries);
            if ($vid <= 0) {
                throw new RuntimeException('The disposal voucher could not be posted — the asset was NOT derecognized.');
            }
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

    if ($action === 'save_measurement_model') {
        require_permission('accounting', 'edit');

        $assetClass = (string) ($_POST['asset_class'] ?? '');
        $measurementModel = (string) ($_POST['measurement_model'] ?? 'cost');
        $activeMarketConfirmed = isset($_POST['active_market_confirmed']);
        $effectiveDate = trim((string) ($_POST['effective_date'] ?? ''));
        $policyNote = trim((string) ($_POST['policy_note'] ?? ''));

        try {
            fa_save_class_measurement_model(
                $companyId,
                $assetClass,
                $measurementModel,
                $activeMarketConfirmed,
                $effectiveDate,
                $policyNote,
                $userId
            );
            security_event(
                'asset_measurement_model_saved',
                'success',
                'Saved ' . $measurementModel . ' model for asset class ' . $assetClass . '.',
                $companyId,
                $userId
            );
            log_activity(
                'asset_measurement_model',
                $companyId,
                'updated',
                'Asset class ' . $assetClass . ' changed to ' . $measurementModel . ' model.',
                $userId
            );
            flash('success', (fa_measurement_model_classes()[$assetClass] ?? $assetClass) . ' now uses the ' . fa_measurement_model_options()[$measurementModel] . '.');
        } catch (Throwable $e) {
            flash('error', 'Could not save measurement model: ' . $e->getMessage());
        }
        redirect('admin/fixed-assets.php?view=models');
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

        try {
            fa_assert_revaluation_model($companyId, $assetClass);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('admin/fixed-assets.php?view=models');
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
        try {
            fa_assert_revaluation_model($companyId, (string) $batch['asset_class']);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('admin/fixed-assets.php?view=models');
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
        try {
            fa_assert_revaluation_model($companyId, (string) $batch['asset_class']);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('admin/fixed-assets.php?view=models');
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
            flash('error', 'Set the PPE / Asset Cost and Capital Work-in-Progress ledgers in "This asset posts to" on this asset\'s page before capitalizing — the reclassification cannot be posted without both.');
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

    if ($action === 'capitalize_borrowing_cost') {
        // IAS 23: borrowing costs directly attributable to a QUALIFYING asset
        // under construction are capitalized onto the asset while the work is
        // in progress — expenditure × capitalization rate × time, or the
        // actual interest on a specific borrowing. Dr Capital WIP / Cr
        // interest payable, bank, or the lender. Capitalization stops when
        // the asset is ready for use (transfer it to PPE then).
        require_permission('accounting', 'post');
        $asset = fa_company_asset((int) ($_POST['asset_id'] ?? 0), $companyId);
        if (!$asset) {
            flash('error', 'Asset not found for this company.');
            redirect('admin/fixed-assets.php');
        }
        $assetId = (int) $asset['id'];
        if ((string) $asset['asset_class'] !== 'cwip') {
            flash('error', 'Borrowing costs are capitalized on Capital WIP (a qualifying asset under construction) only — this asset is already in service.');
            redirect('admin/fixed-assets.php?view=' . $assetId);
        }
        $borrowing = max(0.0, round((float) ($_POST['borrowing_amount'] ?? 0), 2));
        $ratePct = max(0.0, (float) ($_POST['interest_rate_annual'] ?? 0));
        $months = max(0, (int) ($_POST['months'] ?? 0));
        $interestOverride = max(0.0, round((float) ($_POST['interest_amount'] ?? 0), 2));
        $interest = $interestOverride > 0 ? $interestOverride : round($borrowing * ($ratePct / 100) * ($months / 12), 2);
        $intDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['interest_date'] ?? '')) ? (string) $_POST['interest_date'] : date('Y-m-d');
        if ($interest <= 0) {
            flash('error', 'Enter the borrowing amount, annual rate and months — or the interest amount directly.');
            redirect('admin/fixed-assets.php?view=' . $assetId);
        }
        if (is_period_locked($companyId, $fiscalYearId, $intDate)) {
            flash('error', 'The interest date is inside a locked accounting period.');
            redirect('admin/fixed-assets.php?view=' . $assetId);
        }
        $cwipL = fa_resolve_mapping($companyId, 'cwip', $assetId);
        [$creditLedger, $voucherPartyId] = fa_funded_from_ledger($companyId, (string) ($_POST['funded_from'] ?? ''));
        if (!$cwipL || !$creditLedger) {
            flash('error', 'Set the Capital WIP ledger on this asset\'s page and choose where the interest credit goes (interest payable, bank, or the lender).');
            redirect('admin/fixed-assets.php?view=' . $assetId);
        }
        try {
            db()->beginTransaction();
            create_voucher_with_entries([
                'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                'voucher_no' => 'FA-INT-' . $asset['asset_code'] . '-' . date('His'),
                'voucher_type' => 'journal', 'voucher_date' => $intDate,
                'source_type' => 'asset_borrowing_cost', 'source_id' => null,
                'party_id' => $voucherPartyId,
                'total_amount' => $interest,
                'narration' => 'Borrowing cost capitalized on ' . $asset['name'] . ' (' . $asset['asset_code'] . ') per IAS 23'
                    . ($interestOverride > 0 ? '' : ': ' . number_format($borrowing, 2) . ' × ' . number_format($ratePct, 2) . '% × ' . $months . '/12') . '.',
                'status' => 'posted', 'posted_by' => $userId,
            ], [
                ['ledger_id' => (int) $cwipL['id'], 'entry_type' => 'debit', 'amount' => $interest],
                ['ledger_id' => (int) $creditLedger['id'], 'entry_type' => 'credit', 'amount' => $interest],
            ]);
            db()->prepare('UPDATE fixed_assets SET cost = cost + :a, carrying_amount = carrying_amount + :a2 WHERE id = :id AND company_id = :cid')
                ->execute(['a' => $interest, 'a2' => $interest, 'id' => $assetId, 'cid' => $companyId]);
            db()->commit();
            security_event('asset_borrowing_cost', 'success', 'Borrowing cost ' . number_format($interest, 2) . ' capitalized on CWIP asset #' . $assetId . '.', $companyId, $userId);
            log_activity('fixed_asset', $assetId, 'borrowing_cost_capitalized', 'Borrowing cost ' . number_format($interest, 2) . ' capitalized (IAS 23).', $userId);
            flash('success', 'Borrowing cost ' . site_currency_symbol() . number_format($interest, 2) . ' capitalized onto ' . $asset['asset_code'] . ' (Dr Capital WIP / Cr ' . ($creditLedger['name'] ?? 'funding') . ').');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not capitalize the borrowing cost: ' . $e->getMessage());
        }
        redirect('admin/fixed-assets.php?view=' . $assetId);
    }

    if ($action === 'create_lease') {
        require_permission('accounting', 'post');
        // IFRS 16: create a ROU asset + lease liability with a full amortization schedule.
        $ref = strtoupper(trim((string) ($_POST['contract_ref'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $prepay = max(0.0, round((float) ($_POST['prepayments'] ?? 0), 2));
        $idc = max(0.0, round((float) ($_POST['initial_direct_costs'] ?? 0), 2));
        $incentive = max(0.0, round((float) ($_POST['incentives'] ?? 0), 2));
        $restoration = max(0.0, round((float) ($_POST['restoration'] ?? 0), 2));
        $term = max(1, (int) ($_POST['term_months'] ?? 12));
        $rateAnnual = max(0.0, (float) ($_POST['discount_rate_annual'] ?? 0));
        $payment = max(0.0, round((float) ($_POST['payment'] ?? 0), 2));
        $timing = (string) ($_POST['payment_timing'] ?? 'arrears') === 'advance' ? 'advance' : 'arrears';
        $commence = trim((string) ($_POST['commencement_date'] ?? '')) ?: date('Y-m-d');
        // The liability is DERIVED, never typed in: PV of the payment stream
        // at the incremental borrowing rate (IFRS 16.26). A posted
        // initial_liability is only honoured as a legacy fallback when no
        // payment is given.
        $liability = $payment > 0
            ? fa_annuity_present_value($payment, $rateAnnual / 1200.0, $term, $timing)
            : max(0.0, round((float) ($_POST['initial_liability'] ?? 0), 2));
        if ($ref === '' || $name === '' || $liability <= 0) {
            flash('error', 'Lease reference, ROU asset name, monthly payment, term and discount rate are required — the lease liability is calculated from them.');
            redirect('admin/fixed-assets.php?view=leases');
        }
        $rou = fa_rou_initial($liability, $prepay, $idc, $restoration, $incentive);
        // Optional lessor: period payments then credit that party's own
        // payable ledger instead of the shared clearing account.
        $lessorPartyId = (int) ($_POST['lessor_party_id'] ?? 0);
        if ($lessorPartyId > 0) {
            $lessorChk = db()->prepare('SELECT COUNT(*) FROM accounting_parties WHERE id = :id AND company_id = :cid');
            $lessorChk->execute(['id' => $lessorPartyId, 'cid' => $companyId]);
            if ((int) $lessorChk->fetchColumn() === 0) {
                $lessorPartyId = 0;
            }
        }
        $hasLessorColumn = column_exists('lease_liabilities', 'lessor_party_id');
        try {
            db()->beginTransaction();
            // Create the ROU asset record (depreciated straight-line over the term).
            db()->prepare('INSERT INTO fixed_assets (company_id, asset_code, name, asset_class, cost, residual_value, useful_life_months, depreciation_method, available_for_use_date, depreciation_start_date, carrying_amount, status, created_by)
                VALUES (:cid, :code, :name, \'rou\', :cost, 0, :life, \'straight_line\', :d, :dep_start, :cost2, \'active\', :uid)')
                ->execute(['cid' => $companyId, 'code' => 'ROU-' . $ref, 'name' => $name, 'cost' => $rou, 'life' => $term, 'd' => $commence, 'dep_start' => $commence, 'cost2' => $rou, 'uid' => $userId]);
            $rouAssetId = (int) db()->lastInsertId();

            // Ledgers chosen on the lease form belong to THIS lease's RoU
            // asset only: commencement, interest, payments and the RoU
            // depreciation all resolve from these asset-scope rows.
            fa_set_asset_ledger($companyId, $rouAssetId, 'rou_asset', (int) ($_POST['rou_ledger_id'] ?? 0), $userId);
            fa_set_asset_ledger($companyId, $rouAssetId, 'lease_liability', (int) ($_POST['liability_ledger_id'] ?? 0), $userId);
            fa_set_asset_ledger($companyId, $rouAssetId, 'lease_interest_expense', (int) ($_POST['interest_ledger_id'] ?? 0), $userId);
            fa_set_asset_ledger($companyId, $rouAssetId, 'acquisition_clearing', (int) ($_POST['payment_ledger_id'] ?? 0), $userId);
            fa_set_asset_ledger($companyId, $rouAssetId, 'depreciation_expense', (int) ($_POST['dep_expense_ledger_id'] ?? 0), $userId);
            fa_set_asset_ledger($companyId, $rouAssetId, 'accumulated_depreciation', (int) ($_POST['accum_dep_ledger_id'] ?? 0), $userId);
            $rouL = fa_resolve_mapping($companyId, 'rou_asset', $rouAssetId);
            $liabL = fa_resolve_mapping($companyId, 'lease_liability', $rouAssetId);
            if (!$rouL || !$liabL) {
                // No lease without its commencement voucher — a lease that
                // exists only in the register drifts from the books forever.
                throw new RuntimeException('Choose the RoU asset ledger and Lease liability ledger — the commencement voucher cannot post without them.');
            }
            db()->prepare('INSERT INTO lease_liabilities (company_id, asset_id, ' . ($hasLessorColumn ? 'lessor_party_id, ' : '') . 'contract_ref, commencement_date, term_months, payment, payment_timing, discount_rate_annual, initial_liability, initial_direct_costs, prepayments, incentives, restoration, rou_initial, status, created_by)
                VALUES (:cid,:aid,' . ($hasLessorColumn ? ':lessor,' : '') . ':ref,:d,:term,:pay,:timing,:rate,:liab,:idc,:prep,:inc,:rest,:rou,\'active\',:uid)')
                ->execute(array_merge(
                    ['cid' => $companyId, 'aid' => $rouAssetId, 'ref' => $ref, 'd' => $commence, 'term' => $term, 'pay' => $payment, 'timing' => $timing, 'rate' => $rateAnnual, 'liab' => $liability, 'idc' => $idc, 'prep' => $prepay, 'inc' => $incentive, 'rest' => $restoration, 'rou' => $rou, 'uid' => $userId],
                    $hasLessorColumn ? ['lessor' => $lessorPartyId > 0 ? $lessorPartyId : null] : []
                ));
            $leaseId = (int) db()->lastInsertId();
            // Generate the amortization schedule. Each line carries its real
            // payment date: arrears pay at period END (+p months), advance at
            // period START (+p−1) — not the commencement date on every row.
            $schedule = fa_lease_schedule($liability, $rateAnnual / 1200.0, $payment, $term, $timing);
            $lineStmt = db()->prepare('INSERT INTO lease_schedule_lines (lease_id, period_no, period_date, opening, interest, payment, principal, closing) VALUES (:lid,:pno,:pdate,:o,:i,:pay,:pr,:cl)');
            foreach ($schedule as $line) {
                $monthsOut = $timing === 'advance' ? ((int) $line['period'] - 1) : (int) $line['period'];
                $lineStmt->execute(['lid' => $leaseId, 'pno' => $line['period'], 'pdate' => date('Y-m-d', strtotime($commence . ' +' . $monthsOut . ' months')), 'o' => $line['opening'], 'i' => $line['interest'], 'pay' => $line['payment'], 'pr' => $line['principal'], 'cl' => $line['closing']]);
            }
            // Commencement voucher when both legs mapped. Dr ROU / Cr Lease
            // liability; the net of prepayments + IDC - incentives is the cash/
            // clearing leg that keeps the entry balanced (ROU = liability + net).
            $cashL = fa_resolve_mapping($companyId, 'acquisition_clearing', $rouAssetId)
                ?: (fa_resolve_mapping($companyId, 'disposal_clearing') ?: $liabL);
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
            flash('success', 'Lease ' . $ref . ' created. ROU asset ROU-' . $ref . ' = ' . site_currency_symbol() . number_format($rou, 2) . ' with a ' . $term . '-month liability schedule.' . (($rouL && $liabL) ? ' Commencement voucher posted (Dr ' . ($rouL['name'] ?? 'RoU') . ' / Cr ' . ($liabL['name'] ?? 'Lease liability') . ').' : ' NOT posted — set the RoU asset and Lease liability ledgers on the RoU asset\'s page, then re-create the lease.'));
        } catch (Throwable $e) { if (db()->inTransaction()) { db()->rollBack(); } flash('error', 'Could not create lease: ' . $e->getMessage()); }
        redirect('admin/fixed-assets.php?view=leases');
    }

    if ($action === 'post_lease_period') {
        require_permission('accounting', 'post');
        $lineId = (int) ($_POST['line_id'] ?? 0);
        $leaseLessorSelect = column_exists('lease_liabilities', 'lessor_party_id') ? ', ll.lessor_party_id' : ', NULL AS lessor_party_id';
        $lineStmt = db()->prepare('SELECT lsl.*, ll.contract_ref, ll.asset_id AS rou_asset_id' . $leaseLessorSelect . ' FROM lease_schedule_lines lsl JOIN lease_liabilities ll ON ll.id = lsl.lease_id WHERE lsl.id = :id AND ll.company_id = :cid LIMIT 1');
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
        // Resolve through the lease's own RoU asset so every lease can carry
        // its own interest / liability / clearing ledgers (per-asset scope).
        $rouAssetId = (int) ($line['rou_asset_id'] ?? 0) ?: null;
        $interestExpL = fa_resolve_mapping($companyId, $leaseInterestPurposes[0], $rouAssetId);
        $liabL = fa_resolve_mapping($companyId, $leaseInterestPurposes[1], $rouAssetId);
        // The payment credits the lease's OWN lessor when one is set — every
        // lease can owe a different party. Clearing is only the fallback.
        $leasePartyId = null;
        $clearingL = null;
        if ((int) ($line['lessor_party_id'] ?? 0) > 0) {
            [$clearingL, $leasePartyId] = fa_counterparty_ledger($companyId, 'supplier', (int) $line['lessor_party_id'], $leasePaymentPurposes[1], $rouAssetId);
        }
        if (!$clearingL) {
            $clearingL = fa_resolve_mapping($companyId, $leasePaymentPurposes[1], $rouAssetId);
        }
        if (!$interestExpL || !$liabL || !$clearingL) {
            flash('error', 'Set the Interest expense, Lease liability and Payment ledgers on this lease\'s RoU asset page (or set a lessor on the lease) before posting lease periods.');
            redirect('admin/fixed-assets.php?view=leases&lease_id=' . (int) $line['lease_id']);
        }
        $interest = (float) $line['interest'];
        $payment = (float) $line['payment'];
        // Interest belongs to its period: the voucher is dated on the line's
        // own period date (not "today"), and locked periods block posting.
        $periodDate = (string) ($line['period_date'] ?? '') ?: date('Y-m-d');
        if (is_period_locked($companyId, $fiscalYearId, $periodDate)) {
            flash('error', 'Period ' . $line['period_no'] . ' (' . $periodDate . ') is inside a locked accounting period. Unlock it first.');
            redirect('admin/fixed-assets.php?view=leases&lease_id=' . (int) $line['lease_id']);
        }
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
                'voucher_type' => 'journal', 'voucher_date' => $periodDate,
                // Keyed on the schedule LINE id, not the lease id: vouchers has
                // UNIQUE(source_type, source_id), so a lease-scoped key would let
                // only the first period of each lease ever post. Per-line keying
                // also makes re-posting a period idempotent at the DB level.
                'source_type' => 'lease_period', 'source_id' => $lineId, 'total_amount' => round($interest + $payment, 2),
                'party_id' => $leasePartyId,
                'narration' => 'Lease period ' . $line['period_no'] . ' — ' . $line['contract_ref'] . ' (interest + payment, IFRS 16).',
                'status' => 'posted', 'posted_by' => $userId,
            ], $entries);
            if ($vid <= 0) {
                throw new RuntimeException('The period voucher could not be posted — the schedule line stays unposted.');
            }
            db()->prepare('UPDATE lease_schedule_lines SET posted = 1, voucher_id = :vid WHERE id = :id')
                ->execute(['vid' => $vid, 'id' => $lineId]);
            db()->commit();
            security_event('lease_period_posted', 'success', 'Lease period ' . $line['period_no'] . ' posted for lease #' . (int) $line['lease_id'] . '.', $companyId, $userId);
            log_activity('lease', (int) $line['lease_id'], 'period_posted', 'Lease period ' . $line['period_no'] . ' posted (interest ' . number_format($interest, 2) . ', payment ' . number_format($payment, 2) . ').', $userId);
            flash('success', 'Lease period ' . $line['period_no'] . ' posted: interest ' . site_currency_symbol() . number_format($interest, 2) . ', payment ' . site_currency_symbol() . number_format($payment, 2) . '.');
        } catch (Throwable $e) { if (db()->inTransaction()) { db()->rollBack(); } flash('error', 'Could not post lease period: ' . $e->getMessage()); }
        redirect('admin/fixed-assets.php?view=leases&lease_id=' . (int) $line['lease_id']);
    }

    if ($action === 'modify_lease') {
        // IFRS 16.44–46 lease modification (no scope decrease in units): the
        // liability is REMEASURED to the PV of the revised payments over the
        // revised remaining term at the revised discount rate, and the RoU
        // asset is adjusted by the same amount. A decrease that exceeds the
        // RoU carrying amount goes to P&L. Unposted schedule lines are
        // regenerated; posted periods stay untouched.
        require_permission('accounting', 'post');
        $lease = fa_company_lease((int) ($_POST['lease_id'] ?? 0), $companyId);
        if (!$lease) {
            flash('error', 'Lease not found for this company.');
            redirect('admin/fixed-assets.php?view=leases');
        }
        $leaseId = (int) $lease['id'];
        $backUrl = 'admin/fixed-assets.php?view=leases&lease_id=' . $leaseId;
        if ((string) $lease['status'] !== 'active') {
            flash('error', 'Only active leases can be modified.');
            redirect($backUrl);
        }
        $newPayment = max(0.0, round((float) ($_POST['new_payment'] ?? 0), 2));
        $newRemainingTerm = max(1, (int) ($_POST['new_remaining_term'] ?? 0));
        $newRateAnnual = max(0.0, (float) ($_POST['new_discount_rate_annual'] ?? (float) $lease['discount_rate_annual']));
        $effectiveDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['effective_date'] ?? '')) ? (string) $_POST['effective_date'] : date('Y-m-d');
        $reason = trim((string) ($_POST['modification_reason'] ?? ''));
        if ($newPayment <= 0) {
            flash('error', 'Enter the revised payment, remaining term and discount rate — the remeasured liability is calculated from them.');
            redirect($backUrl);
        }
        if (is_period_locked($companyId, $fiscalYearId, $effectiveDate)) {
            flash('error', 'The modification date is inside a locked accounting period.');
            redirect($backUrl);
        }
        $timing = (string) ($lease['payment_timing'] ?? 'arrears') === 'advance' ? 'advance' : 'arrears';
        $rouAssetId = (int) ($lease['asset_id'] ?? 0);
        $rouAsset = fa_company_asset($rouAssetId, $companyId);

        $postedStmt = db()->prepare('SELECT COUNT(*) AS cnt, COALESCE(MAX(period_no), 0) AS last_no FROM lease_schedule_lines WHERE lease_id = :lid AND posted = 1');
        $postedStmt->execute(['lid' => $leaseId]);
        $postedInfo = $postedStmt->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'last_no' => 0];
        $postedCount = (int) $postedInfo['cnt'];
        $lastPostedNo = (int) $postedInfo['last_no'];
        if ($postedCount > 0) {
            $closingStmt = db()->prepare('SELECT closing FROM lease_schedule_lines WHERE lease_id = :lid AND posted = 1 ORDER BY period_no DESC LIMIT 1');
            $closingStmt->execute(['lid' => $leaseId]);
            $outstanding = round((float) $closingStmt->fetchColumn(), 2);
        } else {
            $outstanding = round((float) $lease['initial_liability'], 2);
        }

        $newPv = fa_annuity_present_value($newPayment, $newRateAnnual / 1200.0, $newRemainingTerm, $timing);
        $delta = round($newPv - $outstanding, 2);

        $liabL = fa_resolve_mapping($companyId, 'lease_liability', $rouAssetId ?: null);
        $rouL = fa_resolve_mapping($companyId, 'rou_asset', $rouAssetId ?: null);
        if (!$liabL || !$rouL) {
            flash('error', 'Set the RoU asset and Lease liability ledgers on this lease\'s RoU asset page first.');
            redirect($backUrl);
        }

        try {
            db()->beginTransaction();
            $rouAdjustment = 0.0;
            $modGain = 0.0;
            if (abs($delta) >= 0.005) {
                if ($delta > 0) {
                    $rouAdjustment = $delta;
                    $entries = [
                        ['ledger_id' => (int) $rouL['id'], 'entry_type' => 'debit', 'amount' => $delta, 'memo' => 'RoU adjustment on lease modification'],
                        ['ledger_id' => (int) $liabL['id'], 'entry_type' => 'credit', 'amount' => $delta, 'memo' => 'Lease liability remeasured'],
                    ];
                } else {
                    $reduction = -$delta;
                    $rouCarrying = max(0.0, (float) ($rouAsset['carrying_amount'] ?? 0));
                    $rouAdjustment = -min($reduction, $rouCarrying);
                    $modGain = round($reduction + $rouAdjustment, 2); // excess over RoU carrying → P&L
                    $entries = [
                        ['ledger_id' => (int) $liabL['id'], 'entry_type' => 'debit', 'amount' => $reduction, 'memo' => 'Lease liability remeasured'],
                    ];
                    if (-$rouAdjustment > 0) {
                        $entries[] = ['ledger_id' => (int) $rouL['id'], 'entry_type' => 'credit', 'amount' => -$rouAdjustment, 'memo' => 'RoU adjustment on lease modification'];
                    }
                    if ($modGain > 0.004) {
                        $gainL = fa_resolve_mapping($companyId, 'gain_on_disposal', $rouAssetId ?: null);
                        if (!$gainL) {
                            throw new RuntimeException('The liability decrease exceeds the RoU carrying amount — set the Gain on Disposal ledger on the RoU asset page to receive the P&L credit.');
                        }
                        $entries[] = ['ledger_id' => (int) $gainL['id'], 'entry_type' => 'credit', 'amount' => $modGain, 'memo' => 'Modification gain (IFRS 16.46(a))'];
                    }
                }
                create_voucher_with_entries([
                    'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                    'voucher_no' => 'FA-MOD-' . $leaseId . '-' . date('His'),
                    'voucher_type' => 'journal', 'voucher_date' => $effectiveDate,
                    'source_type' => 'lease_modification', 'source_id' => null,
                    'total_amount' => abs($delta),
                    'narration' => 'Lease modification ' . $lease['contract_ref'] . ': liability remeasured from ' . number_format($outstanding, 2) . ' to ' . number_format($newPv, 2) . ($reason !== '' ? ' — ' . $reason : '') . '.',
                    'status' => 'posted', 'posted_by' => $userId,
                ], $entries);
                if ($rouAsset && abs($rouAdjustment) >= 0.005) {
                    db()->prepare('UPDATE fixed_assets SET cost = cost + :d, carrying_amount = carrying_amount + :d2 WHERE id = :id AND company_id = :cid')
                        ->execute(['d' => $rouAdjustment, 'd2' => $rouAdjustment, 'id' => $rouAssetId, 'cid' => $companyId]);
                }
            }

            // Replace the UNPOSTED tail of the schedule with the revised terms.
            db()->prepare('DELETE FROM lease_schedule_lines WHERE lease_id = :lid AND posted = 0')->execute(['lid' => $leaseId]);
            $schedule = fa_lease_schedule($newPv, $newRateAnnual / 1200.0, $newPayment, $newRemainingTerm, $timing);
            $lineStmt = db()->prepare('INSERT INTO lease_schedule_lines (lease_id, period_no, period_date, opening, interest, payment, principal, closing) VALUES (:lid,:pno,:pdate,:o,:i,:pay,:pr,:cl)');
            foreach ($schedule as $line) {
                $monthsOut = $timing === 'advance' ? ((int) $line['period'] - 1) : (int) $line['period'];
                $lineStmt->execute(['lid' => $leaseId, 'pno' => $lastPostedNo + (int) $line['period'], 'pdate' => date('Y-m-d', strtotime($effectiveDate . ' +' . $monthsOut . ' months')), 'o' => $line['opening'], 'i' => $line['interest'], 'pay' => $line['payment'], 'pr' => $line['principal'], 'cl' => $line['closing']]);
            }
            db()->prepare('UPDATE lease_liabilities SET payment = :pay, term_months = :term, discount_rate_annual = :rate WHERE id = :id AND company_id = :cid')
                ->execute(['pay' => $newPayment, 'term' => $lastPostedNo + $newRemainingTerm, 'rate' => $newRateAnnual, 'id' => $leaseId, 'cid' => $companyId]);
            if ($rouAsset) {
                // Spread the adjusted RoU over the revised total term.
                db()->prepare('UPDATE fixed_assets SET useful_life_months = :life WHERE id = :id AND company_id = :cid')
                    ->execute(['life' => $lastPostedNo + $newRemainingTerm, 'id' => $rouAssetId, 'cid' => $companyId]);
            }
            db()->commit();
            security_event('lease_modified', 'success', 'Lease #' . $leaseId . ' modified (liability ' . number_format($outstanding, 2) . ' → ' . number_format($newPv, 2) . ').', $companyId, $userId);
            log_activity('lease', $leaseId, 'modified', 'Lease modified: payment ' . number_format($newPayment, 2) . ', remaining term ' . $newRemainingTerm . 'm, rate ' . number_format($newRateAnnual, 2) . '%. Liability ' . number_format($outstanding, 2) . ' → ' . number_format($newPv, 2) . '.' . ($modGain > 0 ? ' Modification gain ' . number_format($modGain, 2) . '.' : ''), $userId);
            flash('success', 'Lease modified. Liability remeasured from ' . site_currency_symbol() . number_format($outstanding, 2) . ' to ' . site_currency_symbol() . number_format($newPv, 2) . ($delta >= 0 ? ' (RoU increased ' : ' (RoU/P&L decreased ') . site_currency_symbol() . number_format(abs($delta), 2) . '). Remaining schedule regenerated over ' . $newRemainingTerm . ' months.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not modify the lease: ' . $e->getMessage());
        }
        redirect($backUrl);
    }

    if ($action === 'save_asset_ledgers') {
        // "This asset posts to" panel on the asset page: every ledger here
        // applies to this one asset only (asset-scope mappings). Replaces the
        // old global/category/asset Ledger Mapping tab.
        require_permission('accounting', 'edit');
        $asset = fa_company_asset((int) ($_POST['asset_id'] ?? 0), $companyId);
        if (!$asset) {
            flash('error', 'Asset not found for this company.');
            redirect('admin/fixed-assets.php');
        }
        $saved = 0;
        foreach (fa_purposes_for_asset($asset) as $purpose) {
            if (!array_key_exists($purpose, (array) ($_POST['map'] ?? []))) {
                continue;
            }
            fa_set_asset_ledger($companyId, (int) $asset['id'], $purpose, (int) $_POST['map'][$purpose], $userId);
            $saved++;
        }
        flash('success', 'Ledgers for ' . $asset['asset_code'] . ' saved (' . $saved . ' purpose' . ($saved === 1 ? '' : 's') . ' reviewed). They apply to this asset only.');
        redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
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

// Assets whose acquisition never reached the ledger (registered while the
// mappings were missing) — flagged in the register and fixable per asset.
$unpostedAcquisitionIds = [];
$unpostedStmt = db()->prepare("SELECT fa.id FROM fixed_assets fa
    WHERE fa.company_id = :cid AND fa.cost > 0 AND fa.asset_class <> 'rou'
      AND NOT EXISTS (SELECT 1 FROM vouchers v WHERE v.source_type = 'fixed_asset_acquisition' AND v.source_id = fa.id)");
$unpostedStmt->execute(['cid' => $companyId]);
$unpostedAcquisitionIds = array_map('intval', $unpostedStmt->fetchAll(PDO::FETCH_COLUMN));

// Register ↔ ledger integrity sweep: events and schedule rows that claim a
// posting the ledger does not have. Fix with database/repair_fixed_asset_gl_sync.php
// or the pointers in each message.
$faIntegrityIssues = [];
$impMissStmt = db()->prepare("SELECT i.id, fa.asset_code, i.kind, GREATEST(i.impairment_loss, i.reversal) AS amount
    FROM asset_impairments i INNER JOIN fixed_assets fa ON fa.id = i.asset_id
    WHERE i.company_id = :cid AND GREATEST(i.impairment_loss, i.reversal) > 0
      AND (i.voucher_id IS NULL OR NOT EXISTS (SELECT 1 FROM vouchers v WHERE v.id = i.voucher_id))");
$impMissStmt->execute(['cid' => $companyId]);
foreach ($impMissStmt->fetchAll(PDO::FETCH_ASSOC) as $issueRow) {
    $faIntegrityIssues[] = ucfirst(str_replace('_', ' ', (string) $issueRow['kind'])) . ' of ' . number_format((float) $issueRow['amount'], 2) . ' on ' . $issueRow['asset_code'] . ' is recorded in the register but was never posted to the ledger.';
}
$depMissStmt = db()->prepare("SELECT fa.asset_code, s.period_no FROM asset_depreciation_schedule s INNER JOIN fixed_assets fa ON fa.id = s.asset_id
    WHERE s.company_id = :cid AND s.posted = 1 AND (s.voucher_id IS NULL OR NOT EXISTS (SELECT 1 FROM vouchers v WHERE v.id = s.voucher_id))");
$depMissStmt->execute(['cid' => $companyId]);
foreach ($depMissStmt->fetchAll(PDO::FETCH_ASSOC) as $issueRow) {
    $faIntegrityIssues[] = 'Depreciation period ' . $issueRow['period_no'] . ' of ' . $issueRow['asset_code'] . ' claims to be posted but its voucher no longer exists.';
}
$lseMissStmt = db()->prepare("SELECT ll.contract_ref, lsl.period_no FROM lease_schedule_lines lsl INNER JOIN lease_liabilities ll ON ll.id = lsl.lease_id
    WHERE ll.company_id = :cid AND lsl.posted = 1 AND (lsl.voucher_id IS NULL OR NOT EXISTS (SELECT 1 FROM vouchers v WHERE v.id = lsl.voucher_id))");
$lseMissStmt->execute(['cid' => $companyId]);
foreach ($lseMissStmt->fetchAll(PDO::FETCH_ASSOC) as $issueRow) {
    $faIntegrityIssues[] = 'Lease ' . $issueRow['contract_ref'] . ' period ' . $issueRow['period_no'] . ' claims to be posted but its voucher no longer exists.';
}

$totalCost = array_sum(array_map(static fn (array $a): float => (float) $a['cost'], $assets));
$totalAccum = array_sum(array_map(static fn (array $a): float => (float) $a['accumulated_depreciation'], $assets));
$totalImpair = array_sum(array_map(static fn (array $a): float => (float) $a['accumulated_impairment'], $assets));
$totalCarrying = array_sum(array_map(static fn (array $a): float => (float) $a['carrying_amount'], $assets));

$faView = (string) ($_GET['view'] ?? 'register');
$detailAsset = ctype_digit($faView) ? fa_company_asset((int) $faView, $companyId) : null;
if ($detailAsset) {
    $faView = 'detail';
} elseif (!in_array($faView, ['register', 'models', 'leases', 'categories', 'revaluation'], true)) {
    // 'mapping' is gone on purpose: ledgers are chosen per asset on the
    // create forms and each asset's "This asset posts to" panel.
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
    <a class="mbw-tab <?= in_array($faView, ['register', 'detail'], true) ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php')) ?>"><?= icon('companies') ?>Register</a>
    <a class="mbw-tab <?= $faView === 'models' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php?view=models')) ?>"><?= icon('settings') ?>Measurement Models</a>
    <a class="mbw-tab <?= $faView === 'revaluation' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php?view=revaluation')) ?>"><?= icon('wallet') ?>Revaluation</a>
    <a class="mbw-tab <?= $faView === 'leases' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php?view=leases')) ?>"><?= icon('contracts') ?>Lease</a>
    <a class="mbw-tab <?= $faView === 'categories' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php?view=categories')) ?>"><?= icon('layers') ?>Categories</a>
</nav>

<?php if ($faIntegrityIssues !== [] && in_array($faView, ['register', 'detail', 'leases'], true)): ?>
    <div class="notice error" style="margin-bottom:14px">
        <strong>Register / ledger mismatch<?= count($faIntegrityIssues) > 1 ? 'es' : '' ?> found:</strong>
        <ul style="margin:6px 0 0 18px">
            <?php foreach ($faIntegrityIssues as $issueText): ?><li><?= e($issueText) ?></li><?php endforeach; ?>
        </ul>
        <span style="font-size:12.5px">Run <code>php database/repair_fixed_asset_gl_sync.php</code> (preview first, then --apply) to post the missing vouchers and reset phantom rows.</span>
    </div>
<?php endif; ?>

<?php if ($faView === 'models'): ?>
    <?php // FIXED ASSET MEASUREMENT MODEL PAGE START ?>
    <section class="mbw-card fa-model-hero">
        <div>
            <span class="fa-eyebrow">IAS 16 and IAS 38 policy control</span>
            <h2>Cost model or revaluation model</h2>
            <p>Select one model for the complete asset class. The selection applies equally in admin, staff and client accounting because all portals use this shared page.</p>
        </div>
        <div class="fa-policy-callout">
            <strong>Class-wide control</strong>
            <span>Individual assets cannot use a different model from their class.</span>
        </div>
    </section>

    <div class="fa-model-grid">
        <?php foreach ($assetClasses as $classKey => $classLabel): ?>
            <?php
            $modelRow = $classMeasurementModels[$classKey] ?? fa_class_measurement_model($companyId, $classKey);
            $currentModel = (string) ($modelRow['measurement_model'] ?? 'cost');
            $activeMarket = (int) ($modelRow['active_market_confirmed'] ?? 0) === 1;
            $revaluationAllowedForClass = in_array($classKey, ['ppe', 'intangible', 'investment_property'], true);
            $lockedToCost = !$revaluationAllowedForClass;
            ?>
            <section class="mbw-card fa-model-card <?= $currentModel === 'revaluation' ? 'is-revaluation' : 'is-cost' ?>">
                <div class="fa-model-card-head">
                    <div>
                        <span class="fa-eyebrow"><?= e($classKey) ?></span>
                        <h2><?= e($classLabel) ?></h2>
                    </div>
                    <span class="mbw-pill <?= $currentModel === 'revaluation' ? 'tone-blue' : 'tone-gray' ?>">
                        <?= e(fa_measurement_model_label($modelRow)) ?>
                    </span>
                </div>

                <form method="post" class="workspace-form-grid fa-class-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_measurement_model">
                    <input type="hidden" name="asset_class" value="<?= e($classKey) ?>">

                    <label>Measurement model
                        <select name="measurement_model" <?= $lockedToCost ? 'disabled' : '' ?>>
                            <option value="cost" <?= $currentModel === 'cost' ? 'selected' : '' ?>>Cost model</option>
                            <?php if (!$lockedToCost): ?>
                                <option value="revaluation" <?= $currentModel === 'revaluation' ? 'selected' : '' ?>>Revaluation model</option>
                            <?php endif; ?>
                        </select>
                        <?php if ($lockedToCost): ?>
                            <input type="hidden" name="measurement_model" value="cost">
                            <small>Revaluation is not available for this class in this workflow.</small>
                        <?php endif; ?>
                    </label>
                    <label>Effective date
                        <input type="date" name="effective_date" value="<?= e((string) ($modelRow['effective_date'] ?? date('Y-m-d'))) ?>" required>
                    </label>

                    <?php if ($classKey === 'intangible'): ?>
                        <label class="fa-checkbox-field workspace-span-2">
                            <input type="checkbox" name="active_market_confirmed" value="1" <?= $activeMarket ? 'checked' : '' ?>>
                            <span><strong>Active market confirmed</strong><small>Required before the revaluation model can be selected for intangible assets.</small></span>
                        </label>
                    <?php endif; ?>

                    <label class="workspace-span-2">Policy note or reason
                        <textarea name="policy_note" rows="3" required><?= e((string) ($modelRow['policy_note'] ?? '')) ?></textarea>
                    </label>

                    <div class="workspace-span-2 fa-model-meta">
                        <span>Last approved: <?= e((string) ($modelRow['approved_at'] ?? 'Not yet approved')) ?></span>
                        <button type="submit"><?= icon('settings') ?> Save class model</button>
                    </div>
                </form>
            </section>
        <?php endforeach; ?>
    </div>

    <section class="mbw-card fa-model-rules">
        <div class="mbw-card-head"><h2>Enforced rules</h2></div>
        <div class="fa-rule-grid">
            <div><strong>Cost model</strong><span>Cost less accumulated depreciation and impairment. Revaluation batches are blocked.</span></div>
            <div><strong>Revaluation model</strong><span>All eligible assets in the selected class enter the same class-wide batch.</span></div>
            <div><strong>Historical protection</strong><span>Approved revaluations are preserved. A simple switch back to cost is blocked.</span></div>
            <div><strong>Future depreciation</strong><span>Approved fair value, revised residual value and revised useful life apply prospectively.</span></div>
        </div>
    </section>
    <?php // FIXED ASSET MEASUREMENT MODEL PAGE END ?>

<?php elseif ($faView === 'revaluation'): ?>
    <?php
    $revaluationBatchId = (int) ($_GET['batch_id'] ?? 0);
    $revaluationBatch = $revaluationBatchId > 0
        ? fa_revaluation_batch($revaluationBatchId, $companyId)
        : null;
    $revaluationLines = $revaluationBatch
        ? fa_revaluation_lines($revaluationBatchId, $companyId)
        : [];
    $revaluationBatches = fa_revaluation_batches($companyId);
    $revaluationEnabledClasses = fa_revaluation_model_classes($companyId);

    $preselectedClass = $revaluationEnabledClasses[0] ?? 'ppe';
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
                <section class="mbw-card fa-revaluation-policy-summary">
                    <div class="mbw-card-head">
                        <div><span class="fa-eyebrow">Measurement model check</span><h2>Class policy status</h2></div>
                        <a class="button secondary" href="<?= e(url('admin/fixed-assets.php?view=models')) ?>">Manage models</a>
                    </div>
                    <div class="fa-policy-status-grid">
                        <?php foreach (['ppe', 'intangible', 'investment_property'] as $classKey): ?>
                            <?php $modelRow = $classMeasurementModels[$classKey]; ?>
                            <div class="fa-policy-status <?= in_array($classKey, $revaluationEnabledClasses, true) ? 'is-enabled' : 'is-blocked' ?>">
                                <strong><?= e($assetClasses[$classKey]) ?></strong>
                                <span><?= e(fa_measurement_model_label($modelRow)) ?></span>
                                <small><?= in_array($classKey, $revaluationEnabledClasses, true) ? 'Revaluation batches allowed' : 'Revaluation batches blocked' ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="mbw-card fa-new-batch-card">
                    <div class="mbw-card-head">
                        <div>
                            <span class="fa-eyebrow">New class-wide batch</span>
                            <h2>Create revaluation batch</h2>
                            <p>Select one asset class. Every active asset in that class is included and frozen in the batch.</p>
                        </div>
                    </div>

                    <?php if ($revaluationEnabledClasses === []): ?>
                        <div class="fa-empty-policy workspace-span-2">
                            <strong>No class currently uses the Revaluation model.</strong>
                            <span>Open Measurement Models and change the required complete asset class first.</span>
                            <a class="button secondary" href="<?= e(url('admin/fixed-assets.php?view=models')) ?>">Open Measurement Models</a>
                        </div>
                    <?php else: ?>
                    <form method="post" enctype="multipart/form-data" class="workspace-form-grid">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="create_revaluation_batch">

                        <label>Asset class
                            <select name="asset_class" required>
                                <?php foreach ($revaluationEnabledClasses as $classKey): ?>
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
                    <?php endif; ?>
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
            <?php if ((string) $activeLease['status'] === 'active'): ?>
            <details class="feature-disclosure" style="margin-bottom:12px">
                <summary><span><strong><?= icon('settings') ?>Modify lease (change payment / term / rate)</strong><small>IFRS 16: the liability is remeasured to the PV of the revised payments at the revised rate; the RoU asset is adjusted by the same amount and the remaining schedule is regenerated. Posted periods stay untouched.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
                <form method="post" class="workspace-form-grid" data-confirm="Remeasure this lease? Unposted schedule periods are replaced with the revised terms.">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="modify_lease">
                    <input type="hidden" name="lease_id" value="<?= (int) $activeLease['id'] ?>">
                    <label>Revised monthly payment<input type="number" step="0.01" name="new_payment" value="<?= e(number_format((float) $activeLease['payment'], 2, '.', '')) ?>" required></label>
                    <label>Remaining term from now (months)<input type="number" step="1" name="new_remaining_term" value="<?= e((string) max(1, count(array_filter($leaseLines, static fn (array $l): bool => (int) $l['posted'] === 0)))) ?>" required></label>
                    <label>Revised discount rate % (annual)<input type="number" step="0.0001" name="new_discount_rate_annual" value="<?= e(number_format((float) $activeLease['discount_rate_annual'], 4, '.', '')) ?>"></label>
                    <label>Effective date<input type="date" name="effective_date" value="<?= e(date('Y-m-d')) ?>"></label>
                    <label class="workspace-span-2">Reason<input type="text" name="modification_reason" maxlength="190" placeholder="e.g. Rent increased on renewal, term extended 12 months"></label>
                    <div class="workspace-span-2"><button type="submit"><?= icon('accounting') ?>Remeasure lease</button></div>
                </form>
            </details>
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
        <div class="mbw-card-head"><h2>New lease (IFRS 16)</h2></div>
        <?php
        $leaseDefRou = fa_resolve_mapping($companyId, 'rou_asset');
        $leaseDefLiab = fa_resolve_mapping($companyId, 'lease_liability');
        $leaseDefInterest = fa_resolve_mapping($companyId, 'lease_interest_expense');
        $leaseDefPayment = fa_resolve_mapping($companyId, 'acquisition_clearing');
        $leaseDefDepExp = fa_resolve_mapping($companyId, 'depreciation_expense');
        $leaseDefAccum = fa_resolve_mapping($companyId, 'accumulated_depreciation');
        ?>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_lease">
            <label>Contract ref<input type="text" name="contract_ref" required></label>
            <label>ROU asset name<input type="text" name="name" required></label>
            <label>Commencement date<input type="date" name="commencement_date"></label>
            <label>Monthly payment<input type="number" step="0.01" name="payment" value="0.00" required></label>
            <label>Term (months)<input type="number" name="term_months" value="48" required></label>
            <label>Discount rate % (annual)<input type="number" step="0.0001" name="discount_rate_annual" value="0.00" required></label>
            <label>Payment timing<select name="payment_timing"><option value="arrears">Arrears (period end)</option><option value="advance">Advance (period start)</option></select></label>
            <label>Prepayments<input type="number" step="0.01" name="prepayments" value="0.00"></label>
            <label>Initial direct costs<input type="number" step="0.01" name="initial_direct_costs" value="0.00"></label>
            <label>Lease incentives<input type="number" step="0.01" name="incentives" value="0.00"></label>
            <label>Restoration provision<input type="number" step="0.01" name="restoration" value="0.00"></label>
            <label>Lessor (party)
                <select name="lessor_party_id">
                    <option value="0">— none (payments credit the payment ledger) —</option>
                    <?php foreach ($faParties as $fp): ?>
                        <option value="<?= (int) $fp['id'] ?>"><?= e($fp['name'] . ' (' . $fp['code'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>RoU asset ledger (debit)
                <?= fa_ledger_select('rou_ledger_id', $ledgers, (int) ($leaseDefRou['id'] ?? 0)) ?>
            </label>
            <label>Lease liability ledger (credit)
                <?= fa_ledger_select('liability_ledger_id', $ledgers, (int) ($leaseDefLiab['id'] ?? 0)) ?>
            </label>
            <label>Interest expense ledger
                <?= fa_ledger_select('interest_ledger_id', $ledgers, (int) ($leaseDefInterest['id'] ?? 0)) ?>
            </label>
            <label>Payment ledger (cash/bank or clearing)
                <?= fa_ledger_select('payment_ledger_id', $ledgers, (int) ($leaseDefPayment['id'] ?? 0)) ?>
            </label>
            <label>RoU depreciation expense ledger
                <?= fa_ledger_select('dep_expense_ledger_id', $ledgers, (int) ($leaseDefDepExp['id'] ?? 0)) ?>
            </label>
            <label>Accumulated depreciation ledger
                <?= fa_ledger_select('accum_dep_ledger_id', $ledgers, (int) ($leaseDefAccum['id'] ?? 0)) ?>
            </label>
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

<?php elseif ($faView === 'detail' && $detailAsset): ?>
    <?php
    $charge = fa_asset_monthly_charge($detailAsset);
    $schedStmt = db()->prepare('SELECT * FROM asset_depreciation_schedule WHERE asset_id = :aid ORDER BY period_no ASC');
    $schedStmt->execute(['aid' => (int) $detailAsset['id']]);
    $schedule = $schedStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <?php $detailAcquisitionUnposted = in_array((int) $detailAsset['id'], $unpostedAcquisitionIds, true); ?>
    <?php if ($detailAcquisitionUnposted): ?>
        <section class="mbw-card" style="border-left:4px solid #c0392b">
            <div class="mbw-card-head"><h2>Acquisition not posted to the ledger</h2></div>
            <p style="margin:0 0 10px">This asset sits in the register, but its acquisition voucher was never posted, so the books do not yet show the asset cost. Choose the two ledgers and post it now (Dr asset cost / Cr funded-from). The choice sticks to this asset only.</p>
            <?php
            $panelCostPurpose = (string) $detailAsset['asset_class'] === 'cwip' ? 'cwip' : 'ppe_cost';
            $panelCost = fa_resolve_mapping($companyId, $panelCostPurpose, (int) $detailAsset['id']);
            $panelFunding = fa_resolve_mapping($companyId, 'acquisition_clearing', (int) $detailAsset['id']);
            ?>
            <form method="post" class="frm-grid frm-grid-4">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="post_acquisition">
                <input type="hidden" name="asset_id" value="<?= (int) $detailAsset['id'] ?>">
                <label>Asset cost ledger (debit)
                    <?= fa_ledger_select('cost_ledger_id', $ledgers, (int) ($panelCost['id'] ?? 0)) ?>
                </label>
                <label>Funded from (credit)
                    <?= fa_funded_from_select('funded_from', $ledgers, $faParties, (int) ($panelFunding['id'] ?? 0)) ?>
                </label>
                <label>Voucher date<input type="date" name="voucher_date" value="<?= e((string) ($detailAsset['available_for_use_date'] ?? date('Y-m-d'))) ?>"></label>
                <div style="align-self:end"><button type="submit"><?= icon('accounting') ?>Post acquisition to ledger</button></div>
            </form>
        </section>
    <?php endif; ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2><?= e($detailAsset['name']) ?> <span class="mbw-pill tone-gray"><?= e($detailAsset['asset_code']) ?></span><?php if ($detailAcquisitionUnposted): ?> <span class="mbw-pill tone-red">GL not posted</span><?php endif; ?></h2>
            <div class="mbw-card-tools"><a class="button secondary" href="<?= e(url('admin/fixed-assets.php')) ?>">Back to register</a></div></div>
        <div class="users-view-grid">
            <div class="card">
                <p><strong>Class:</strong> <?= e($assetClasses[$detailAsset['asset_class']] ?? $detailAsset['asset_class']) ?></p>
                <?php $detailModel = $classMeasurementModels[(string) $detailAsset['asset_class']] ?? fa_class_measurement_model($companyId, (string) $detailAsset['asset_class']); ?>
                <p><strong>Measurement model:</strong> <span class="mbw-pill <?= (string) $detailModel['measurement_model'] === 'revaluation' ? 'tone-blue' : 'tone-gray' ?>"><?= e(fa_measurement_model_label($detailModel)) ?></span></p>
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
                    <form method="post" style="margin-top:8px;display:grid;gap:8px">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="post_depreciation">
                        <input type="hidden" name="asset_id" value="<?= e((int) $detailAsset['id']) ?>">
                        <label style="font-size:12.5px">Days in service this period
                            <input type="number" name="period_days" value="30" min="1" max="31" class="field-compact" style="max-width:110px"
                                   title="Charge = monthly charge × days/30. Use this when the asset was available for only part of the period — e.g. bought 15 days before the fiscal year closes, enter 15.">
                        </label>
                        <div><button type="submit"><?= icon('accounting') ?>Post depreciation</button></div>
                        <small class="muted">A part period (IAS 16: depreciation starts when the asset is available for use) is charged pro-rata at days/30 — enter 15 for a half month.</small>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php
        // "This asset posts to" — the asset's own ledger per purpose. This
        // replaces the old Ledger Mapping tab: everything is per asset, set
        // here or on the create forms. Blank rows inherit the old category/
        // global rows so pre-existing assets keep posting unchanged.
        $assetScopeMap = [];
        $asmStmt = db()->prepare("SELECT purpose, ledger_id FROM asset_ledger_mappings WHERE company_id = :cid AND scope = 'asset' AND asset_id = :aid AND category_id IS NULL");
        $asmStmt->execute(['cid' => $companyId, 'aid' => (int) $detailAsset['id']]);
        foreach ($asmStmt->fetchAll(PDO::FETCH_ASSOC) as $asmRow) {
            $assetScopeMap[(string) $asmRow['purpose']] = (int) $asmRow['ledger_id'];
        }
        $purposeMeta = fa_mapping_purposes();
        ?>
        <details class="feature-disclosure" style="margin-top:16px">
            <summary><span><strong><?= icon('accounting') ?>This asset posts to (ledgers)</strong><small>Every ledger this asset uses — acquisition, depreciation, impairment, disposal. Applies to this asset only.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_asset_ledgers">
                <input type="hidden" name="asset_id" value="<?= (int) $detailAsset['id'] ?>">
                <div class="rc-table-scroll"><table class="rc-table">
                    <thead><tr><th>Used for</th><th>Ledger (this asset only)</th><th>Currently posting to</th></tr></thead>
                    <tbody>
                        <?php foreach (fa_purposes_for_asset($detailAsset) as $purpose): ?>
                            <?php
                            $ownLedgerId = $assetScopeMap[$purpose] ?? 0;
                            $effective = fa_resolve_mapping($companyId, $purpose, (int) $detailAsset['id']);
                            ?>
                            <tr>
                                <td><strong><?= e($purposeMeta[$purpose]['label'] ?? $purpose) ?></strong></td>
                                <td><select name="map[<?= e($purpose) ?>]" style="min-width:230px">
                                    <option value="0">— use inherited default —</option>
                                    <?php foreach ($ledgers as $l): ?>
                                        <option value="<?= (int) $l['id'] ?>" <?= $ownLedgerId === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['code'] . ' - ' . $l['name']) ?></option>
                                    <?php endforeach; ?>
                                </select></td>
                                <td><?php if ($effective): ?><span class="mbw-pill <?= $ownLedgerId > 0 ? 'tone-green' : 'tone-gray' ?>"><?= e($effective['name']) ?><?= $ownLedgerId > 0 ? '' : ' (inherited)' ?></span><?php else: ?><span class="mbw-pill tone-red">Not set — posting will be skipped or blocked</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <div style="margin-top:12px"><button type="submit"><?= icon('accounting') ?>Save this asset's ledgers</button></div>
            </form>
        </details>

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
                <summary><span><strong><?= icon('wallet') ?>Capitalize borrowing cost (IAS 23)</strong><small>Qualifying asset under construction: interest = borrowing × annual rate × months ÷ 12, added to the CWIP cost. Stops once the asset is ready for use.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="capitalize_borrowing_cost"><input type="hidden" name="asset_id" value="<?= e((int) $detailAsset['id']) ?>">
                    <label>Borrowing / expenditure amount<input type="number" step="0.01" name="borrowing_amount" value="<?= e(number_format((float) $detailAsset['cost'], 2, '.', '')) ?>"></label>
                    <label>Annual interest rate %<input type="number" step="0.0001" name="interest_rate_annual" value="0.00"></label>
                    <label>Months in the period<input type="number" step="1" name="months" value="12"></label>
                    <label>OR interest amount directly<input type="number" step="0.01" name="interest_amount" value="0.00" title="When set, this amount is capitalized as-is (actual interest on a specific borrowing)"></label>
                    <label>Date<input type="date" name="interest_date" value="<?= e(date('Y-m-d')) ?>"></label>
                    <?php $intFunding = fa_resolve_mapping($companyId, 'acquisition_clearing', (int) $detailAsset['id']); ?>
                    <label>Credit goes to (interest payable / bank / lender)
                        <?= fa_funded_from_select('funded_from', $ledgers, $faParties, (int) ($intFunding['id'] ?? 0)) ?>
                    </label>
                    <div class="workspace-span-2"><button type="submit"><?= icon('accounting') ?>Capitalize interest onto CWIP</button></div>
                </form>
            </details>
            <details class="feature-disclosure">
                <summary><span><strong><?= icon('upload') ?>Complete &amp; transfer to fixed asset</strong><small>Construction finished: posts Dr PPE / Cr Capital WIP for the accumulated cost, reclassifies the asset and starts depreciation.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
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
                    <div class="workspace-span-2"><button type="submit"><?= icon('upload') ?>Complete &amp; transfer to fixed asset</button></div>
                </form>
            </details>
            <?php endif; ?>
            <details class="feature-disclosure">
                <summary><span><strong><?= icon('download') ?>Dispose / derecognize</strong><small>Reverse cost + accumulated depreciation, recognise proceeds and gain/loss.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
                <form method="post" class="workspace-form-grid" data-confirm="Dispose of this asset? This derecognizes it and posts the disposal voucher.">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="dispose_asset"><input type="hidden" name="asset_id" value="<?= e((int) $detailAsset['id']) ?>">
                    <label>Sale proceeds<input type="number" step="0.01" name="proceeds" value="0.00"></label>
                    <label>Proceeds received as
                        <select name="proceeds_mode">
                            <option value="clearing">Disposal clearing (default)</option>
                            <option value="buyer">Buyer — on credit (party receivable)</option>
                            <option value="cash">Cash / bank</option>
                        </select>
                    </label>
                    <label>Buyer (when on credit)
                        <select name="buyer_party_id">
                            <option value="0">— select buyer —</option>
                            <?php foreach ($faParties as $fp): ?>
                                <option value="<?= (int) $fp['id'] ?>"><?= e($fp['name'] . ' (' . $fp['code'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="workspace-span-2"><button type="submit"><?= icon('accounting') ?>Dispose asset</button></div>
                </form>
            </details>
            <details class="feature-disclosure">
                <summary><span><strong><?= icon('upload') ?>Add cost (subsequent expenditure)</strong><small>Capitalize additional cost onto this asset — Dr asset cost / Cr supplier, cash or clearing.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_asset_cost"><input type="hidden" name="asset_id" value="<?= e((int) $detailAsset['id']) ?>">
                    <label>Additional cost<input type="number" step="0.01" name="amount" value="0.00" required></label>
                    <label>Date<input type="date" name="cost_date" value="<?= e(date('Y-m-d')) ?>"></label>
                    <?php $addCostFunding = fa_resolve_mapping($companyId, 'acquisition_clearing', (int) $detailAsset['id']); ?>
                    <label>Funded from (credit)
                        <?= fa_funded_from_select('funded_from', $ledgers, $faParties, (int) ($addCostFunding['id'] ?? 0)) ?>
                    </label>
                    <label class="workspace-span-2">Memo<input type="text" name="memo" maxlength="190" placeholder="e.g. Engine overhaul, extension wing"></label>
                    <div class="workspace-span-2"><button type="submit"><?= icon('accounting') ?>Capitalize additional cost</button></div>
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
            <thead><tr><th>Name</th><th>Class</th><th>Measurement model</th><th>Default method</th><th class="align-right">Default life</th><th class="align-right">Default rate</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($categories as $c): ?>
                    <tr>
                        <td><?= e($c['name']) ?></td>
                        <td><?= e($assetClasses[$c['asset_class']] ?? $c['asset_class']) ?></td>
                        <?php $categoryModel = $classMeasurementModels[(string) $c['asset_class']] ?? fa_class_measurement_model($companyId, (string) $c['asset_class']); ?>
                        <td><span class="mbw-pill <?= (string) $categoryModel['measurement_model'] === 'revaluation' ? 'tone-blue' : 'tone-gray' ?>"><?= e(fa_measurement_model_label($categoryModel)) ?></span></td>
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
                <?php if ($categories === []): ?><tr><td colspan="8" style="text-align:center;color:var(--mbw-muted)">No categories yet — add one above.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>

<?php else: ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Register a fixed asset</h2><span class="frm-optional">The ledgers you choose here belong to THIS asset only — registration posts Dr asset cost / Cr funded-from immediately</span></div>
        <?php
        $regDefCost = fa_resolve_mapping($companyId, 'ppe_cost');
        $regDefFunding = fa_resolve_mapping($companyId, 'acquisition_clearing');
        $regDefDepExp = fa_resolve_mapping($companyId, 'depreciation_expense');
        $regDefAccum = fa_resolve_mapping($companyId, 'accumulated_depreciation');
        ?>
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
            <label>Asset cost ledger (debit)
                <?= fa_ledger_select('cost_ledger_id', $ledgers, (int) ($regDefCost['id'] ?? 0)) ?>
            </label>
            <label>Funded from (credit) — ledger, supplier, or opening balance
                <?= fa_funded_from_select('funded_from', $ledgers, $faParties, (int) ($regDefFunding['id'] ?? 0)) ?>
            </label>
            <label>Depreciation expense ledger
                <?= fa_ledger_select('dep_expense_ledger_id', $ledgers, (int) ($regDefDepExp['id'] ?? 0)) ?>
            </label>
            <label>Accumulated depreciation ledger
                <?= fa_ledger_select('accum_dep_ledger_id', $ledgers, (int) ($regDefAccum['id'] ?? 0)) ?>
            </label>
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
            <thead><tr><th>Code</th><th>Name</th><th>Class</th><th>Model</th><th>Method</th><th class="align-right">Cost</th><th class="align-right">Accum. dep.</th><th class="align-right">Carrying</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($assets as $a): ?>
                    <tr>
                        <td><?= e($a['asset_code']) ?></td>
                        <td><?= e($a['name']) ?></td>
                        <td><?= e($assetClasses[$a['asset_class']] ?? $a['asset_class']) ?></td>
                        <?php $assetModel = $classMeasurementModels[(string) $a['asset_class']] ?? fa_class_measurement_model($companyId, (string) $a['asset_class']); ?>
                        <td><span class="mbw-pill <?= (string) $assetModel['measurement_model'] === 'revaluation' ? 'tone-blue' : 'tone-gray' ?>"><?= e(fa_measurement_model_label($assetModel)) ?></span></td>
                        <td><span class="mbw-pill tone-gray"><?= e(str_replace('_', ' ', (string) $a['depreciation_method'])) ?></span></td>
                        <td class="align-right"><?= e(number_format((float) $a['cost'], 2)) ?></td>
                        <td class="align-right"><?= e(number_format((float) $a['accumulated_depreciation'], 2)) ?></td>
                        <td class="align-right"><?= e(number_format((float) $a['carrying_amount'], 2)) ?></td>
                        <td><span class="mbw-pill <?= (string) $a['status'] === 'active' ? 'tone-green' : 'tone-gray' ?>"><?= e(str_replace('_', ' ', (string) $a['status'])) ?></span><?php if (in_array((int) $a['id'], $unpostedAcquisitionIds, true)): ?> <span class="mbw-pill tone-red" title="The acquisition voucher was never posted — open the asset and use Post acquisition to ledger">GL not posted</span><?php endif; ?></td>
                        <td><div class="fa-row-actions"><a class="button secondary" href="<?= e(url('admin/fixed-assets.php?view=' . (int) $a['id'])) ?>">Open</a><a class="button secondary" href="<?= e(url('admin/fixed-assets.php?view=revaluation&asset_id=' . (int) $a['id'])) ?>">Revalue</a><?php if (user_can('delete') && user_can_do('accounting', 'edit')): ?><form method="post" style="display:inline" onsubmit="return confirm('Permanently delete asset <?= e($a['asset_code']) ?> and every voucher it posted (acquisition, depreciation, impairment, disposal, lease)? Use Dispose for a real asset leaving the business — Delete is only for wrong entries and cannot be undone.')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_asset"><input type="hidden" name="asset_id" value="<?= (int) $a['id'] ?>"><button type="submit" class="button danger">Delete</button></form><?php endif; ?></div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($assets === []): ?><tr><td colspan="10" style="text-align:center;color:var(--mbw-muted)">No assets registered yet — add one above.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
