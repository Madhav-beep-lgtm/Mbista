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
    require_permission('accounting', 'edit');
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_asset') {
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
                VALUES (:cid, :code, :name, :class, :cost, :residual, :life, :method, :avail, :avail, :cost2, :status, :uid)
            ')->execute([
                'cid' => $companyId, 'code' => $code, 'name' => $name, 'class' => $class,
                'cost' => $cost, 'residual' => $residual, 'life' => $lifeMonths, 'method' => $method,
                'avail' => $availableDate, 'cost2' => $cost, 'status' => $availableDate ? 'active' : 'draft', 'uid' => $userId,
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
            log_activity('fixed_asset', (int) $asset['id'], 'depreciation_posted', 'Depreciation period ' . $periodNo . ' posted (' . number_format($charge, 2) . ').', $userId);
            flash('success', 'Depreciation posted for ' . $asset['asset_code'] . ': ' . site_currency_symbol() . number_format($charge, 2) . ' (Dr Depreciation expense / Cr Accumulated depreciation). Carrying amount now ' . site_currency_symbol() . number_format($newCarrying, 2) . '.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) { db()->rollBack(); }
            flash('error', 'Could not post depreciation: ' . $e->getMessage());
        }
        redirect('admin/fixed-assets.php?view=' . (int) $asset['id']);
    }

    if ($action === 'save_asset_mappings') {
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
} elseif (!in_array($faView, ['register', 'mapping'], true)) {
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
    <a class="<?= $faView === 'mapping' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php?view=mapping')) ?>"><?= icon('accounting') ?>Ledger Mapping</a>
</nav>

<?php if ($faView === 'mapping'): ?>
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
                <?php if ($charge > 0 && (string) $detailAsset['status'] !== 'held_for_sale'): ?>
                    <form method="post" style="margin-top:8px">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="post_depreciation">
                        <input type="hidden" name="asset_id" value="<?= e((int) $detailAsset['id']) ?>">
                        <button type="submit"><?= icon('accounting') ?>Post one month's depreciation</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
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
