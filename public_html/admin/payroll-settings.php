<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/payroll_engine.php';

require_admin();
require_company_context();
accounting_module_repair_database();

$company = current_company();
$fiscalYear = current_fiscal_year();
if (!$company || !$fiscalYear) {
    flash('error', 'Company and fiscal year context required.');
    redirect('admin/accounting-dashboard.php');
}
$companyId = (int) $company['id'];
$fiscalYearId = (int) $fiscalYear['id'];
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$sym = site_currency_symbol();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_mappings') {
        db()->prepare('UPDATE payroll_settings SET
                salary_expense_ledger_id = :se, employer_contrib_expense_ledger_id = :ee,
                tds_payable_ledger_id = :tds, retirement_payable_ledger_id = :rp,
                salary_payable_ledger_id = :sp, advance_ledger_id = :adv, bank_ledger_id = :bank,
                enforce_sod = :sod, auto_post = :ap,
                standard_working_days = :wd, deduct_unpaid_leave = :dul
            WHERE company_id = :cid')
            ->execute([
                'se' => (int) ($_POST['salary_expense_ledger_id'] ?? 0) ?: null,
                'ee' => (int) ($_POST['employer_contrib_expense_ledger_id'] ?? 0) ?: null,
                'tds' => (int) ($_POST['tds_payable_ledger_id'] ?? 0) ?: null,
                'rp' => (int) ($_POST['retirement_payable_ledger_id'] ?? 0) ?: null,
                'sp' => (int) ($_POST['salary_payable_ledger_id'] ?? 0) ?: null,
                'adv' => (int) ($_POST['advance_ledger_id'] ?? 0) ?: null,
                'bank' => (int) ($_POST['bank_ledger_id'] ?? 0) ?: null,
                'sod' => isset($_POST['enforce_sod']) ? 1 : 0,
                'ap' => isset($_POST['auto_post']) ? 1 : 0,
                'wd' => max(1, min(31, (int) ($_POST['standard_working_days'] ?? 30))),
                'dul' => isset($_POST['deduct_unpaid_leave']) ? 1 : 0,
                'cid' => $companyId,
            ]);
        payroll_settings($companyId); // ensures the row exists even on a fresh company
        flash('success', 'Ledger mappings and workflow settings saved.');
        redirect('admin/payroll-settings.php');
    }

    if ($action === 'save_component') {
        $componentId = (int) ($_POST['component_id'] ?? 0);
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = in_array((string) ($_POST['category'] ?? ''), ['allowance', 'overtime', 'benefit', 'deduction'], true) ? (string) $_POST['category'] : 'allowance';
        $calcType = (string) ($_POST['calc_type'] ?? 'fixed') === 'percent_basic' ? 'percent_basic' : 'fixed';
        if ($code === '' || $name === '') {
            flash('error', 'Component code and name are required.');
            redirect('admin/payroll-settings.php');
        }
        $params = [
            'company_id' => $companyId, 'code' => $code, 'name' => $name,
            'name_np' => trim((string) ($_POST['name_np'] ?? '')) ?: null,
            'category' => $category, 'calc_type' => $calcType,
            'default_value' => max(0.0, round((float) ($_POST['default_value'] ?? 0), 2)),
            'taxable' => isset($_POST['taxable']) ? 1 : 0,
            'employer_paid' => isset($_POST['employer_paid']) ? 1 : 0,
            'active' => isset($_POST['active']) ? 1 : 0,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];
        try {
            if ($componentId > 0) {
                $params['id'] = $componentId;
                db()->prepare('UPDATE payroll_components SET code = :code, name = :name, name_np = :name_np,
                        category = :category, calc_type = :calc_type, default_value = :default_value,
                        taxable = :taxable, employer_paid = :employer_paid, active = :active, sort_order = :sort_order
                    WHERE id = :id AND company_id = :company_id')->execute($params);
            } else {
                db()->prepare('INSERT INTO payroll_components (company_id, code, name, name_np, category, calc_type, default_value, taxable, employer_paid, active, sort_order)
                    VALUES (:company_id, :code, :name, :name_np, :category, :calc_type, :default_value, :taxable, :employer_paid, :active, :sort_order)')->execute($params);
            }
            flash('success', 'Component saved.');
        } catch (Throwable $exception) {
            flash('error', (string) $exception->getCode() === '23000' ? 'Component code already exists.' : 'Could not save component.');
        }
        redirect('admin/payroll-settings.php');
    }

    if ($action === 'save_tax_version') {
        $versionId = (int) ($_POST['version_id'] ?? 0);
        $label = trim((string) ($_POST['label'] ?? ''));
        if ($label === '') {
            flash('error', 'Give the tax version a label (e.g. "Finance Act slabs ' . ($fiscalYear['label'] ?? '') . '").');
            redirect('admin/payroll-settings.php#tax');
        }
        if ($versionId > 0) {
            $lockCheck = db()->prepare("SELECT status FROM payroll_tax_versions WHERE id = :id AND company_id = :cid");
            $lockCheck->execute(['id' => $versionId, 'cid' => $companyId]);
            if ((string) $lockCheck->fetchColumn() === 'published') {
                flash('error', 'Published tax versions are immutable. Create a new version instead.');
                redirect('admin/payroll-settings.php#tax');
            }
        }
        $params = [
            'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId, 'label' => $label,
            'legal_reference' => trim((string) ($_POST['legal_reference'] ?? '')) ?: null,
            'effective_from' => trim((string) ($_POST['effective_from'] ?? '')) ?: ($fiscalYear['start_date'] ?? null),
            'effective_to' => trim((string) ($_POST['effective_to'] ?? '')) ?: null,
            'retirement_limit_pct' => max(0.0, round((float) ($_POST['retirement_limit_pct'] ?? 33.33), 2)),
            'retirement_limit_cap' => max(0.0, round((float) ($_POST['retirement_limit_cap'] ?? 500000), 2)),
            'ssf_first_slab_exempt' => isset($_POST['ssf_first_slab_exempt']) ? 1 : 0,
            'rounding' => in_array((string) ($_POST['rounding'] ?? ''), ['none', 'nearest', 'down'], true) ? (string) $_POST['rounding'] : 'nearest',
            'created_by' => $userId,
        ];
        if ($versionId > 0) {
            $params['id'] = $versionId;
            db()->prepare('UPDATE payroll_tax_versions SET label = :label, legal_reference = :legal_reference,
                    effective_from = :effective_from, effective_to = :effective_to,
                    retirement_limit_pct = :retirement_limit_pct, retirement_limit_cap = :retirement_limit_cap,
                    ssf_first_slab_exempt = :ssf_first_slab_exempt, rounding = :rounding
                WHERE id = :id AND company_id = :company_id AND fiscal_year_id = :fiscal_year_id')
                ->execute(array_diff_key($params, ['created_by' => 1]) + ['id' => $versionId, 'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);
        } else {
            db()->prepare('INSERT INTO payroll_tax_versions (company_id, fiscal_year_id, label, legal_reference, effective_from, effective_to,
                    retirement_limit_pct, retirement_limit_cap, ssf_first_slab_exempt, rounding, created_by)
                VALUES (:company_id, :fiscal_year_id, :label, :legal_reference, :effective_from, :effective_to,
                    :retirement_limit_pct, :retirement_limit_cap, :ssf_first_slab_exempt, :rounding, :created_by)')->execute($params);
            $versionId = (int) db()->lastInsertId();
        }
        // Replace slabs (drafts only reach here).
        db()->prepare('DELETE FROM payroll_tax_slabs WHERE version_id = :vid')->execute(['vid' => $versionId]);
        $slabInsert = db()->prepare('INSERT INTO payroll_tax_slabs (version_id, category, lower_bound, upper_bound, rate, sort_order)
            VALUES (:vid, :cat, :lo, :hi, :rate, :sort)');
        foreach (['individual', 'couple'] as $category) {
            $lowers = (array) ($_POST['slab_lower_' . $category] ?? []);
            $uppers = (array) ($_POST['slab_upper_' . $category] ?? []);
            $rates = (array) ($_POST['slab_rate_' . $category] ?? []);
            $sort = 0;
            foreach ($lowers as $index => $rawLower) {
                $lower = round((float) $rawLower, 2);
                $upperRaw = trim((string) ($uppers[$index] ?? ''));
                $rate = round((float) ($rates[$index] ?? 0), 2);
                if ($rawLower === '' && $upperRaw === '') {
                    continue;
                }
                $slabInsert->execute([
                    'vid' => $versionId, 'cat' => $category, 'lo' => $lower,
                    'hi' => $upperRaw === '' ? null : round((float) $upperRaw, 2),
                    'rate' => $rate, 'sort' => $sort++,
                ]);
            }
        }
        log_activity('payroll_tax_version', $versionId, 'saved', 'Tax version draft saved: ' . $label, $userId);
        flash('success', 'Tax version saved as draft. Publish it to make it live for payroll.');
        redirect('admin/payroll-settings.php#tax');
    }

    if ($action === 'publish_tax_version') {
        $versionId = (int) ($_POST['version_id'] ?? 0);
        $slabCount = db()->prepare('SELECT COUNT(*) FROM payroll_tax_slabs WHERE version_id = :vid');
        $slabCount->execute(['vid' => $versionId]);
        if ((int) $slabCount->fetchColumn() === 0) {
            flash('error', 'Add slabs before publishing.');
            redirect('admin/payroll-settings.php#tax');
        }
        // Archive currently published versions of this FY, publish this one.
        db()->prepare("UPDATE payroll_tax_versions SET status = 'archived' WHERE company_id = :cid AND fiscal_year_id = :fy AND status = 'published'")
            ->execute(['cid' => $companyId, 'fy' => $fiscalYearId]);
        db()->prepare("UPDATE payroll_tax_versions SET status = 'published', approved_by = :by WHERE id = :id AND company_id = :cid AND status = 'draft'")
            ->execute(['by' => $userId, 'id' => $versionId, 'cid' => $companyId]);
        log_activity('payroll_tax_version', $versionId, 'published', 'Tax version published.', $userId);
        flash('success', 'Tax version published. New payroll calculations will use it (existing calculated runs keep their snapshots).');
        redirect('admin/payroll-settings.php#tax');
    }
}

$settings = payroll_settings($companyId);
$ledgers = db()->prepare("SELECT id, code, name, type FROM ledgers WHERE company_id = :cid AND status = 'active' ORDER BY code ASC");
$ledgers->execute(['cid' => $companyId]);
$ledgers = $ledgers->fetchAll();

$components = db()->prepare('SELECT * FROM payroll_components WHERE company_id = :cid ORDER BY sort_order ASC, code ASC');
$components->execute(['cid' => $companyId]);
$components = $components->fetchAll();

$versions = db()->prepare('SELECT * FROM payroll_tax_versions WHERE company_id = :cid AND fiscal_year_id = :fy ORDER BY id DESC');
$versions->execute(['cid' => $companyId, 'fy' => $fiscalYearId]);
$versions = $versions->fetchAll();

// Slabs are national statute: with no own published version, payroll inherits
// a published version from another company for the same FY period. Name it so
// the tax tab can say what is in effect rather than looking unconfigured.
$hasOwnPublished = array_filter($versions, static fn (array $v): bool => (string) $v['status'] === 'published') !== [];
$inheritedVersion = null;
if (!$hasOwnPublished) {
    $activeVersion = payroll_active_tax_version($companyId, $fiscalYearId);
    if ($activeVersion && (int) $activeVersion['company_id'] !== $companyId) {
        $inheritedFrom = company_by_id((int) $activeVersion['company_id']);
        $inheritedVersion = $activeVersion + ['source_company_name' => (string) ($inheritedFrom['name'] ?? 'another company')];
    }
}

$editVersion = null;
$editVersionId = (int) ($_GET['tax_version'] ?? 0);
foreach ($versions as $versionRow) {
    if ((int) $versionRow['id'] === $editVersionId && (string) $versionRow['status'] === 'draft') {
        $editVersion = $versionRow;
    }
}
$editSlabs = ['individual' => [], 'couple' => []];
if ($editVersion) {
    $slabStmt = db()->prepare('SELECT * FROM payroll_tax_slabs WHERE version_id = :vid ORDER BY category, sort_order');
    $slabStmt->execute(['vid' => (int) $editVersion['id']]);
    foreach ($slabStmt->fetchAll() as $slabRow) {
        $editSlabs[(string) $slabRow['category']][] = $slabRow;
    }
}

$editComponent = null;
$editComponentId = (int) ($_GET['component'] ?? 0);
foreach ($components as $componentRow) {
    if ((int) $componentRow['id'] === $editComponentId) {
        $editComponent = $componentRow;
    }
}

$ledgerSelect = static function (string $name, int $selected) use ($ledgers): string {
    $html = '<select name="' . e($name) . '"><option value="0">Not mapped</option>';
    foreach ($ledgers as $ledger) {
        $html .= '<option value="' . (int) $ledger['id'] . '"' . ($selected === (int) $ledger['id'] ? ' selected' : '') . '>'
            . e($ledger['code'] . ' - ' . $ledger['name']) . '</option>';
    }
    return $html . '</select>';
};

$pageTitle = 'Payroll Settings';
$pageSubtitle = 'Ledger mappings, pay components, and effective-dated Nepal income tax configuration for ' . ($fiscalYear['label'] ?? '') . '.';
$pageHero = ['icon' => 'settings'];
$bodyClass = 'admin-layout accounting-module-page payroll-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card" aria-label="Ledger mappings">
    <div class="mbw-card-head"><h2>Ledger Mappings &amp; Workflow</h2></div>
    <form method="post" class="workspace-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_mappings">
        <label>Salary expense (Dr)<?= $ledgerSelect('salary_expense_ledger_id', (int) ($settings['salary_expense_ledger_id'] ?? 0)) ?></label>
        <label>Employer contribution expense (Dr)<?= $ledgerSelect('employer_contrib_expense_ledger_id', (int) ($settings['employer_contrib_expense_ledger_id'] ?? 0)) ?></label>
        <label>Income tax / TDS payable (Cr)<?= $ledgerSelect('tds_payable_ledger_id', (int) ($settings['tds_payable_ledger_id'] ?? 0)) ?></label>
        <label>Retirement fund payable (Cr)<?= $ledgerSelect('retirement_payable_ledger_id', (int) ($settings['retirement_payable_ledger_id'] ?? 0)) ?></label>
        <label>Salary payable (Cr)<?= $ledgerSelect('salary_payable_ledger_id', (int) ($settings['salary_payable_ledger_id'] ?? 0)) ?></label>
        <label>Employee advance ledger<?= $ledgerSelect('advance_ledger_id', (int) ($settings['advance_ledger_id'] ?? 0)) ?></label>
        <label>Bank / cash for salary payment<?= $ledgerSelect('bank_ledger_id', (int) ($settings['bank_ledger_id'] ?? 0)) ?></label>
        <label style="display:flex;align-items:center;gap:8px;flex-direction:row"><input type="checkbox" name="auto_post" <?= (int) ($settings['auto_post'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Auto-post accrual voucher on approval</label>
        <label style="display:flex;align-items:center;gap:8px;flex-direction:row"><input type="checkbox" name="enforce_sod" <?= (int) ($settings['enforce_sod'] ?? 0) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Segregation of duties (preparer cannot approve)</label>
        <label style="display:flex;align-items:center;gap:8px;flex-direction:row"><input type="checkbox" name="deduct_unpaid_leave" <?= (int) ($settings['deduct_unpaid_leave'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Deduct salary for approved unpaid leave (from Attendance/HR)</label>
        <label>Working days per month (for the leave day-rate)<input type="number" step="0.5" min="1" max="31" name="standard_working_days" value="<?= e((string) ($settings['standard_working_days'] ?? '30')) ?>"></label>
        <div class="workspace-span-2"><button type="submit"><?= icon('settings') ?>Save Settings</button></div>
    </form>
</section>

<section class="mbw-card" aria-label="Pay components">
    <div class="mbw-card-head"><h2>Pay Components</h2></div>
    <form method="post" class="workspace-form-grid" style="margin-bottom:14px">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_component">
        <input type="hidden" name="component_id" value="<?= e((int) ($editComponent['id'] ?? 0)) ?>">
        <label>Code<input type="text" name="code" maxlength="40" value="<?= e($editComponent['code'] ?? '') ?>" required placeholder="DA"></label>
        <label>Name<input type="text" name="name" maxlength="120" value="<?= e($editComponent['name'] ?? '') ?>" required placeholder="Dearness Allowance"></label>
        <label>Name (Nepali)<input type="text" name="name_np" maxlength="120" value="<?= e($editComponent['name_np'] ?? '') ?>"></label>
        <label>Category
            <select name="category">
                <?php foreach (['allowance' => 'Allowance (earning)', 'overtime' => 'Overtime', 'benefit' => 'Other benefit', 'deduction' => 'Deduction'] as $categoryValue => $categoryLabel): ?>
                    <option value="<?= e($categoryValue) ?>" <?= ($editComponent['category'] ?? '') === $categoryValue ? 'selected' : '' ?>><?= e($categoryLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Calculation
            <select name="calc_type">
                <option value="fixed" <?= ($editComponent['calc_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed monthly amount</option>
                <option value="percent_basic" <?= ($editComponent['calc_type'] ?? '') === 'percent_basic' ? 'selected' : '' ?>>% of basic salary</option>
            </select>
        </label>
        <label>Default value (amount or %)<input type="number" step="0.01" min="0" name="default_value" value="<?= e(number_format((float) ($editComponent['default_value'] ?? 0), 2, '.', '')) ?>"></label>
        <label>Sort order<input type="number" name="sort_order" value="<?= e((int) ($editComponent['sort_order'] ?? 0)) ?>"></label>
        <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
            <label style="display:flex;align-items:center;gap:6px;flex-direction:row"><input type="checkbox" name="taxable" <?= (int) ($editComponent['taxable'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Taxable</label>
            <label style="display:flex;align-items:center;gap:6px;flex-direction:row"><input type="checkbox" name="employer_paid" <?= (int) ($editComponent['employer_paid'] ?? 0) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Employer-paid</label>
            <label style="display:flex;align-items:center;gap:6px;flex-direction:row"><input type="checkbox" name="active" <?= (int) ($editComponent['active'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Active</label>
        </div>
        <div class="workspace-span-2"><button type="submit"><?= icon('plus') ?><?= $editComponent ? 'Update Component' : 'Add Component' ?></button>
            <?php if ($editComponent): ?> <a class="button secondary" href="<?= e(url('admin/payroll-settings.php')) ?>">Cancel</a><?php endif; ?></div>
    </form>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Calc</th><th class="is-numeric">Default</th><th>Taxable</th><th>Paid by</th><th>Status</th><th></th></tr></thead>
        <tbody>
            <?php if ($components === []): ?><tr><td colspan="9">No components yet. Add allowances, overtime, benefits, and deductions here.</td></tr><?php endif; ?>
            <?php foreach ($components as $component): ?>
                <tr>
                    <td><strong><?= e($component['code']) ?></strong></td>
                    <td><?= e($component['name']) ?><?= $component['name_np'] ? ' <small>(' . e($component['name_np']) . ')</small>' : '' ?></td>
                    <td><?= e(ucfirst((string) $component['category'])) ?></td>
                    <td><?= $component['calc_type'] === 'percent_basic' ? '% of basic' : 'Fixed' ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $component['default_value'], 2)) ?><?= $component['calc_type'] === 'percent_basic' ? '%' : '' ?></td>
                    <td><?= (int) $component['taxable'] ? 'Yes' : '<span class="mbw-pill tone-blue">No</span>' ?></td>
                    <td><?= (int) $component['employer_paid'] ? 'Employer' : 'Employee pay' ?></td>
                    <td><span class="mbw-pill <?= (int) $component['active'] ? 'tone-green' : 'tone-red' ?>"><?= (int) $component['active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td><a class="button secondary" href="<?= e(url('admin/payroll-settings.php?component=' . (int) $component['id'])) ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="mbw-card" aria-label="Income tax configuration" id="tax">
    <div class="mbw-card-head"><h2>Income Tax Configuration — <?= e($fiscalYear['label'] ?? '') ?></h2></div>
    <p style="margin:0 0 12px;color:var(--mbw-muted);font-size:12.5px">
        Slabs and limits are versioned and effective-dated. Enter values from the official IRD publication of the Income Tax Act, 2058 as amended by the applicable Finance Act — cite it in the legal reference. Published versions are immutable; calculated runs keep the version snapshot they used.
    </p>
    <form method="post" class="workspace-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_tax_version">
        <input type="hidden" name="version_id" value="<?= e((int) ($editVersion['id'] ?? 0)) ?>">
        <label>Version label<input type="text" name="label" maxlength="120" value="<?= e($editVersion['label'] ?? '') ?>" required placeholder="Finance Act slabs <?= e($fiscalYear['label'] ?? '') ?>"></label>
        <label>Legal reference<input type="text" name="legal_reference" maxlength="255" value="<?= e($editVersion['legal_reference'] ?? '') ?>" placeholder="Income Tax Act 2058, Finance Act ..., IRD notice ..."></label>
        <label>Effective from<input type="date" name="effective_from" value="<?= e($editVersion['effective_from'] ?? ($fiscalYear['start_date'] ?? '')) ?>"></label>
        <label>Effective to<input type="date" name="effective_to" value="<?= e($editVersion['effective_to'] ?? '') ?>"></label>
        <label>Retirement deduction limit (% of assessable)<input type="number" step="0.01" min="0" name="retirement_limit_pct" value="<?= e(number_format((float) ($editVersion['retirement_limit_pct'] ?? 33.33), 2, '.', '')) ?>"></label>
        <label>Retirement deduction cap (annual)<input type="number" step="0.01" min="0" name="retirement_limit_cap" value="<?= e(number_format((float) ($editVersion['retirement_limit_cap'] ?? 500000), 2, '.', '')) ?>"></label>
        <label>Tax rounding
            <select name="rounding">
                <?php foreach (['nearest' => 'Nearest rupee', 'down' => 'Round down', 'none' => 'Two decimals'] as $roundValue => $roundLabel): ?>
                    <option value="<?= e($roundValue) ?>" <?= ($editVersion['rounding'] ?? 'nearest') === $roundValue ? 'selected' : '' ?>><?= e($roundLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:flex;align-items:center;gap:8px;flex-direction:row"><input type="checkbox" name="ssf_first_slab_exempt" <?= (int) ($editVersion['ssf_first_slab_exempt'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> SSF contributors exempt from first-slab social security tax</label>
        <?php foreach (['individual' => 'Individual', 'couple' => 'Couple'] as $slabCategory => $slabCategoryLabel): ?>
            <div class="workspace-span-2">
                <strong style="font-size:13px;color:var(--mbw-heading)"><?= e($slabCategoryLabel) ?> slabs (annual <?= e($sym) ?>)</strong>
                <div class="pr-slab-grid" data-slab-rows="<?= e($slabCategory) ?>">
                    <?php $slabRows = $editSlabs[$slabCategory] !== [] ? $editSlabs[$slabCategory] : array_fill(0, 4, null); ?>
                    <?php foreach ($slabRows as $slabRow): ?>
                        <div class="pr-slab-row">
                            <input type="number" step="0.01" min="0" name="slab_lower_<?= e($slabCategory) ?>[]" placeholder="From" value="<?= $slabRow ? e(number_format((float) $slabRow['lower_bound'], 2, '.', '')) : '' ?>">
                            <input type="number" step="0.01" min="0" name="slab_upper_<?= e($slabCategory) ?>[]" placeholder="To (blank = above)" value="<?= $slabRow && $slabRow['upper_bound'] !== null ? e(number_format((float) $slabRow['upper_bound'], 2, '.', '')) : '' ?>">
                            <input type="number" step="0.01" min="0" name="slab_rate_<?= e($slabCategory) ?>[]" placeholder="Rate %" value="<?= $slabRow ? e(number_format((float) $slabRow['rate'], 2, '.', '')) : '' ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button secondary" data-add-slab="<?= e($slabCategory) ?>" style="margin-top:6px">+ Slab row</button>
            </div>
        <?php endforeach; ?>
        <div class="workspace-span-2"><button type="submit"><?= icon('compliance') ?><?= $editVersion ? 'Update Draft Version' : 'Save New Draft Version' ?></button></div>
    </form>

    <?php if ($inheritedVersion): ?>
        <div class="notice" style="margin-top:14px">Income tax slabs are national, so payroll here currently uses
            <strong><?= e($inheritedVersion['label']) ?></strong> published under <strong><?= e($inheritedVersion['source_company_name']) ?></strong> for the same fiscal-year period.
            Publish a version below only if this company needs different rules.</div>
    <?php endif; ?>
    <div style="overflow-x:auto;margin-top:14px">
    <table>
        <thead><tr><th>#</th><th>Label</th><th>Legal reference</th><th>Effective</th><th>Retirement limit</th><th>Status</th><th></th></tr></thead>
        <tbody>
            <?php if ($versions === []): ?><tr><td colspan="7">No tax versions of this company's own for this fiscal year<?= $inheritedVersion ? ' — the inherited national version above is in effect.' : ' — payroll cannot calculate until one is published here or in any company for the same period.' ?></td></tr><?php endif; ?>
            <?php foreach ($versions as $versionRow): ?>
                <tr>
                    <td>v<?= e((int) $versionRow['id']) ?></td>
                    <td><?= e($versionRow['label']) ?></td>
                    <td style="max-width:280px"><?= e($versionRow['legal_reference'] ?? '-') ?></td>
                    <td><?= e($versionRow['effective_from'] ?? '-') ?><?= $versionRow['effective_to'] ? ' &#8594; ' . e($versionRow['effective_to']) : '' ?></td>
                    <td><?= e(number_format((float) $versionRow['retirement_limit_pct'], 2)) ?>% / cap <?= e(number_format((float) $versionRow['retirement_limit_cap'])) ?></td>
                    <td><span class="mbw-pill <?= $versionRow['status'] === 'published' ? 'tone-green' : ($versionRow['status'] === 'draft' ? 'tone-amber' : 'tone-gray') ?>"><?= e(ucfirst((string) $versionRow['status'])) ?></span></td>
                    <td style="white-space:nowrap">
                        <?php if ((string) $versionRow['status'] === 'draft'): ?>
                            <a class="button secondary" href="<?= e(url('admin/payroll-settings.php?tax_version=' . (int) $versionRow['id'] . '#tax')) ?>">Edit</a>
                            <form method="post" style="display:inline" data-confirm="Publish this tax version? It becomes immutable and replaces any currently published version for <?= e($fiscalYear['label'] ?? '') ?>.">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="publish_tax_version">
                                <input type="hidden" name="version_id" value="<?= e((int) $versionRow['id']) ?>">
                                <button type="submit">Publish</button>
                            </form>
                        <?php else: ?>–<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<script>
document.querySelectorAll('[data-add-slab]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var cat = btn.getAttribute('data-add-slab');
        var host = document.querySelector('[data-slab-rows="' + cat + '"]');
        var row = host.querySelector('.pr-slab-row').cloneNode(true);
        row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        host.appendChild(row);
    });
});
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
