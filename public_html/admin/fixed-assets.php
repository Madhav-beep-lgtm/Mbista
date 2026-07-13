<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_or_admin();
require_company_context();
require_permission('accounting', 'view');

accounting_module_repair_database();

$company = current_company();
$fiscalYear = current_fiscal_year();
$currentUser = current_user();
$companyId = (int) ($company['id'] ?? 0);
$fiscalYearId = (int) ($fiscalYear['id'] ?? 0);
$userId = (int) ($currentUser['id'] ?? 0);

$assetClasses = ['ppe' => 'Property, Plant & Equipment', 'intangible' => 'Intangible (IAS 38)', 'rou' => 'Right-of-Use (IFRS 16)', 'investment_property' => 'Investment Property', 'cwip' => 'Capital WIP'];
$methods = ['straight_line' => 'Straight-line', 'diminishing_balance' => 'Diminishing balance', 'units' => 'Units of production'];

$ledgerStmt = db()->prepare("SELECT id, code, name, type FROM ledgers WHERE company_id = :cid AND status = 'active' ORDER BY code ASC");
$ledgerStmt->execute(['cid' => $companyId]);
$ledgers = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

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
        if ($code === '' || $name === '' || !isset($assetClasses[$class]) || !isset($methods[$method])) {
            flash('error', 'Asset code, name, class and method are required.');
            redirect('admin/fixed-assets.php');
        }
        try {
            db()->prepare('
                INSERT INTO fixed_assets
                    (company_id, asset_code, name, asset_class, cost, residual_value, useful_life_months,
                     depreciation_method, available_for_use_date, depreciation_start_date, carrying_amount, status, created_by)
                VALUES (:cid, :code, :name, :class, :cost, :residual, :life, :method, :avail, :dep_start, :cost2, :status, :uid)
            ')->execute([
                'cid' => $companyId, 'code' => $code, 'name' => $name, 'class' => $class,
                'cost' => $cost, 'residual' => $residual, 'life' => $lifeMonths, 'method' => $method,
                'avail' => $availableDate, 'dep_start' => $availableDate, 'cost2' => $cost,
                'status' => $availableDate ? 'active' : 'draft', 'uid' => $userId,
            ]);
            $assetId = (int) db()->lastInsertId();
            log_activity('fixed_asset', $assetId, 'created', 'Fixed asset registered.', $userId);

            // Optional acquisition voucher when both legs are mapped.
            $costLedger = fa_resolve_mapping($companyId, 'ppe_cost', $assetId);
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
                flash('success', 'Asset ' . $code . ' registered.' . (($costLedger && $clearingLedger) ? '' : ' Map PPE Cost + Acquisition Clearing to auto-post the acquisition voucher.'));
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
            $newCarrying = round((float) $asset['cost'] - $newAccum - (float) $asset['accumulated_impairment'], 2);
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
            $vid = create_voucher_with_entries([
                'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null,
                'voucher_no' => 'FA-IMP-' . $asset['asset_code'], 'voucher_type' => 'journal', 'voucher_date' => date('Y-m-d'),
                'source_type' => 'asset_impairment', 'source_id' => (int) $asset['id'], 'total_amount' => $imp['impairment'],
                'narration' => 'Impairment of ' . $asset['name'] . ' (IAS 36).', 'status' => 'posted', 'posted_by' => $userId,
            ], [
                ['ledger_id' => (int) $lossL['id'], 'entry_type' => 'debit', 'amount' => $imp['impairment']],
                ['ledger_id' => (int) $accL['id'], 'entry_type' => 'credit', 'amount' => $imp['impairment']],
            ]);
            db()->prepare('INSERT INTO asset_impairments (company_id, asset_id, test_date, kind, carrying_amount, fair_value_less_costs, value_in_use, recoverable_amount, impairment_loss, voucher_id, created_by)
                VALUES (:cid,:aid,:d,\'impairment\',:carry,:fv,:viu,:rec,:imp,:vid,:uid)')
                ->execute(['cid' => $companyId, 'aid' => (int) $asset['id'], 'd' => date('Y-m-d'), 'carry' => $carrying, 'fv' => $fvlcd, 'viu' => $viu, 'rec' => $imp['recoverable'], 'imp' => $imp['impairment'], 'vid' => $vid ?: null, 'uid' => $userId]);
            db()->prepare('UPDATE fixed_assets SET accumulated_impairment = accumulated_impairment + :imp, carrying_amount = :carry WHERE id = :id AND company_id = :cid')
                ->execute(['imp' => $imp['impairment'], 'carry' => $imp['revised_carrying'], 'id' => (int) $asset['id'], 'cid' => $companyId]);
            db()->commit();
            security_event('asset_impairment_posted', 'success', 'Impairment posted for asset #' . (int) $asset['id'] . '.', $companyId, $userId);
            log_activity('fixed_asset', (int) $asset['id'], 'impairment', 'Impairment ' . number_format($imp['impairment'], 2) . ' posted.', $userId);
            flash('success', 'Impairment posted: ' . site_currency_symbol() . number_format($imp['impairment'], 2) . ' (Dr Impairment loss / Cr Accumulated impairment). Carrying amount now ' . site_currency_symbol() . number_format($imp['revised_carrying'], 2) . '.');
        } catch (Throwable $e) { if (db()->inTransaction()) { db()->rollBack(); } flash('error', 'Could not post impairment: ' . $e->getMessage()); }
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
        require_permission('accounting', 'post');
        $asset = fa_company_asset((int) ($_POST['asset_id'] ?? 0), $companyId);
        if (!$asset) { flash('error', 'Asset not found.'); redirect('admin/fixed-assets.php'); }
        $newValue = max(0.0, round((float) ($_POST['new_fair_value'] ?? 0), 2));
        $carrying = (float) $asset['carrying_amount'];
        $delta = round($newValue - $carrying, 2);
        if (abs($delta) < 0.005) {
            flash('error', 'New fair value equals carrying amount — nothing to revalue.');
            redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
        }
        $costL = fa_resolve_mapping($companyId, 'ppe_cost', (int) $asset['id']);
        try {
            db()->beginTransaction();
            if ($delta > 0) {
                $surplusL = fa_resolve_mapping($companyId, 'revaluation_surplus', (int) $asset['id']);
                if (!$costL || !$surplusL) { throw new RuntimeException('Map PPE Cost and Revaluation Surplus.'); }
                $vid = create_voucher_with_entries([
                    'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null, 'voucher_no' => 'FA-REV-' . $asset['asset_code'],
                    'voucher_type' => 'journal', 'voucher_date' => date('Y-m-d'), 'source_type' => 'asset_revaluation', 'source_id' => (int) $asset['id'],
                    'total_amount' => $delta, 'narration' => 'Revaluation surplus on ' . $asset['name'] . '.', 'status' => 'posted', 'posted_by' => $userId,
                ], [
                    ['ledger_id' => (int) $costL['id'], 'entry_type' => 'debit', 'amount' => $delta],
                    ['ledger_id' => (int) $surplusL['id'], 'entry_type' => 'credit', 'amount' => $delta],
                ]);
                db()->prepare('UPDATE fixed_assets SET carrying_amount = :v, revaluation_reserve = revaluation_reserve + :d WHERE id = :id AND company_id = :cid')
                    ->execute(['v' => $newValue, 'd' => $delta, 'id' => (int) $asset['id'], 'cid' => $companyId]);
            } else {
                $lossL = fa_resolve_mapping($companyId, 'revaluation_loss', (int) $asset['id']);
                if (!$costL || !$lossL) { throw new RuntimeException('Map PPE Cost and Revaluation Loss.'); }
                $vid = create_voucher_with_entries([
                    'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId ?: null, 'voucher_no' => 'FA-REV-' . $asset['asset_code'],
                    'voucher_type' => 'journal', 'voucher_date' => date('Y-m-d'), 'source_type' => 'asset_revaluation', 'source_id' => (int) $asset['id'],
                    'total_amount' => -$delta, 'narration' => 'Revaluation decrease on ' . $asset['name'] . '.', 'status' => 'posted', 'posted_by' => $userId,
                ], [
                    ['ledger_id' => (int) $lossL['id'], 'entry_type' => 'debit', 'amount' => -$delta],
                    ['ledger_id' => (int) $costL['id'], 'entry_type' => 'credit', 'amount' => -$delta],
                ]);
                db()->prepare('UPDATE fixed_assets SET carrying_amount = :v WHERE id = :id AND company_id = :cid')
                    ->execute(['v' => $newValue, 'id' => (int) $asset['id'], 'cid' => $companyId]);
            }
            db()->commit();
            security_event('asset_revalued', 'success', 'Asset #' . (int) $asset['id'] . ' revalued.', $companyId, $userId);
            log_activity('fixed_asset', (int) $asset['id'], 'revalued', 'Revalued to ' . number_format($newValue, 2) . '.', $userId);
            flash('success', 'Asset revalued to ' . site_currency_symbol() . number_format($newValue, 2) . ' (' . ($delta > 0 ? 'surplus ' : 'decrease ') . site_currency_symbol() . number_format(abs($delta), 2) . ').');
        } catch (Throwable $e) { if (db()->inTransaction()) { db()->rollBack(); } flash('error', 'Could not revalue: ' . $e->getMessage()); }
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
}

$assets = db()->prepare('SELECT * FROM fixed_assets WHERE company_id = :cid ORDER BY status ASC, name ASC');
$assets->execute(['cid' => $companyId]);
$assets = $assets->fetchAll(PDO::FETCH_ASSOC);

$totalCost = array_sum(array_map(static fn (array $a): float => (float) $a['cost'], $assets));
$totalAccum = array_sum(array_map(static fn (array $a): float => (float) $a['accumulated_depreciation'], $assets));
$totalImpair = array_sum(array_map(static fn (array $a): float => (float) $a['accumulated_impairment'], $assets));
$totalCarrying = array_sum(array_map(static fn (array $a): float => (float) $a['carrying_amount'], $assets));

$faView = (string) ($_GET['view'] ?? 'register');
$detailAsset = ctype_digit($faView) ? fa_company_asset((int) $faView, $companyId) : null;
if ($detailAsset) {
    $faView = 'detail';
} elseif (!in_array($faView, ['register', 'mapping', 'leases'], true)) {
    $faView = 'register';
}

$pageTitle = 'Fixed Assets';
$pageSubtitle = 'Asset register, depreciation, and IFRS accounting integration';
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<nav class="accounting-tabs" aria-label="Accounting sections">
    <a href="<?= e(url('admin/accounting-dashboard.php')) ?>">Dashboard</a>
    <a href="<?= e(url('admin/accounting.php')) ?>">Vouchers</a>
    <a href="<?= e(url('admin/accounting-inventory.php')) ?>">Inventory</a>
    <a class="is-active" href="<?= e(url('admin/fixed-assets.php')) ?>">Fixed Assets</a>
    <a href="<?= e(url('admin/chart-of-accounts.php')) ?>">Chart of Accounts</a>
    <a href="<?= e(url('admin/reports-center.php')) ?>">Reports</a>
</nav>

<section class="mbw-kpi-grid" aria-label="Fixed asset overview">
    <div class="mbw-kpi"><div><span class="mbw-kpi-label">Assets</span><div class="mbw-kpi-value"><?= e((string) count($assets)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Registered</span></span></div><span class="mbw-chip tone-blue"><?= icon('companies') ?></span></div>
    <div class="mbw-kpi"><div><span class="mbw-kpi-label">Gross Cost</span><div class="mbw-kpi-value"><?= e(site_currency_symbol()) ?><?= e(number_format($totalCost, 2)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Original cost</span></span></div><span class="mbw-chip tone-green"><?= icon('wallet') ?></span></div>
    <div class="mbw-kpi"><div><span class="mbw-kpi-label">Accumulated Dep.</span><div class="mbw-kpi-value"><?= e(site_currency_symbol()) ?><?= e(number_format($totalAccum + $totalImpair, 2)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Dep. + impairment</span></span></div><span class="mbw-chip tone-amber"><?= icon('download') ?></span></div>
    <div class="mbw-kpi"><div><span class="mbw-kpi-label">Carrying Amount</span><div class="mbw-kpi-value"><?= e(site_currency_symbol()) ?><?= e(number_format($totalCarrying, 2)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Net book value</span></span></div><span class="mbw-chip tone-teal"><?= icon('reports') ?></span></div>
</section>

<nav class="mbw-tabbar" aria-label="Fixed asset workspace" style="margin:6px 0 16px">
    <a class="<?= in_array($faView, ['register', 'detail'], true) ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php')) ?>"><?= icon('companies') ?>Register</a>
    <a class="<?= $faView === 'leases' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php?view=leases')) ?>"><?= icon('contracts') ?>Leases (IFRS 16)</a>
    <a class="<?= $faView === 'mapping' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php?view=mapping')) ?>"><?= icon('accounting') ?>Ledger Mapping</a>
</nav>

<?php if ($faView === 'leases'): ?>
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
            <thead><tr><th>Ref</th><th>ROU asset</th><th class="align-right">Initial liability</th><th class="align-right">ROU initial</th><th>Term</th><th>Rate</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($leases as $l): ?>
                    <tr><td><?= e($l['contract_ref']) ?></td><td><?= e($l['asset_code'] ?? '—') ?></td>
                        <td class="align-right"><?= e(number_format((float) $l['initial_liability'], 2)) ?></td>
                        <td class="align-right"><?= e(number_format((float) $l['rou_initial'], 2)) ?></td>
                        <td><?= e((string) (int) $l['term_months']) ?>m</td><td><?= e(number_format((float) $l['discount_rate_annual'], 2)) ?>%</td>
                        <td><span class="mbw-pill tone-blue"><?= e((string) $l['status']) ?></span></td></tr>
                <?php endforeach; ?>
                <?php if ($leases === []): ?><tr><td colspan="7" style="text-align:center;color:var(--mbw-muted)">No leases yet.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>

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
                <summary><span><strong><?= icon('tag') ?>Classify held for sale (IFRS 5)</strong><small>Measure at lower of carrying and fair value less costs to sell; depreciation stops.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="classify_held_for_sale"><input type="hidden" name="asset_id" value="<?= e((int) $detailAsset['id']) ?>">
                    <label>Fair value<input type="number" step="0.01" name="fair_value" value="0.00"></label>
                    <label>Costs to sell<input type="number" step="0.01" name="costs_to_sell" value="0.00"></label>
                    <div class="workspace-span-2"><button type="submit"><?= icon('accounting') ?>Classify held for sale</button></div>
                </form>
            </details>
            <details class="feature-disclosure">
                <summary><span><strong><?= icon('wallet') ?>Revalue (IAS 16 revaluation model)</strong><small>Restate to fair value; surplus to OCI, decrease to P&amp;L.</small></span><span class="feature-disclosure-action"><?= icon('login') ?>Open</span></summary>
                <form method="post" class="workspace-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="revalue_asset"><input type="hidden" name="asset_id" value="<?= e((int) $detailAsset['id']) ?>">
                    <label>New fair value<input type="number" step="0.01" name="new_fair_value" value="<?= e(number_format((float) $detailAsset['carrying_amount'], 2, '.', '')) ?>"></label>
                    <div class="workspace-span-2"><button type="submit"><?= icon('accounting') ?>Post revaluation</button></div>
                </form>
            </details>
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

<?php else: ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Register a fixed asset</h2></div>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_asset">
            <label>Asset code<input type="text" name="asset_code" maxlength="80" required></label>
            <label>Name<input type="text" name="name" maxlength="190" required></label>
            <label>Class<select name="asset_class"><?php foreach ($assetClasses as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
            <label>Depreciation method<select name="depreciation_method"><?php foreach ($methods as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
            <label>Cost<input type="number" step="0.01" name="cost" value="0.00" required></label>
            <label>Residual value<input type="number" step="0.01" name="residual_value" value="0.00"></label>
            <label>Useful life (months)<input type="number" step="1" name="useful_life_months" value="60"></label>
            <label>Available for use date<input type="date" name="available_for_use_date"></label>
            <div class="workspace-span-2"><button type="submit"><?= icon('companies') ?>Register asset</button></div>
        </form>
    </section>

    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Asset Register</h2></div>
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
                        <td><a class="button secondary" href="<?= e(url('admin/fixed-assets.php?view=' . (int) $a['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($assets === []): ?><tr><td colspan="9" style="text-align:center;color:var(--mbw-muted)">No assets registered yet — add one above.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
