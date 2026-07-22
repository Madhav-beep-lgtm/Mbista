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
                tds_payable_ledger_id = :tds, sst_payable_ledger_id = :sst, retirement_payable_ledger_id = :rp,
                salary_payable_ledger_id = :sp, advance_ledger_id = :adv, bank_ledger_id = :bank,
                enforce_sod = :sod, auto_post = :ap,
                standard_working_days = :wd, deduct_unpaid_leave = :dul,
                excess_tax_treatment = :ext
            WHERE company_id = :cid')
            ->execute([
                'ext' => in_array((string) ($_POST['excess_tax_treatment'] ?? ''), ['offset', 'refund', 'carry_forward', 'manual'], true)
                    ? (string) $_POST['excess_tax_treatment'] : 'offset',
                'se' => (int) ($_POST['salary_expense_ledger_id'] ?? 0) ?: null,
                'ee' => (int) ($_POST['employer_contrib_expense_ledger_id'] ?? 0) ?: null,
                'tds' => (int) ($_POST['tds_payable_ledger_id'] ?? 0) ?: null,
                'sst' => (int) ($_POST['sst_payable_ledger_id'] ?? 0) ?: null,
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
        $categories = ['allowance', 'overtime', 'benefit', 'deduction', 'employer_contribution', 'reimbursement', 'advance_recovery', 'tax', 'info'];
        $category = in_array((string) ($_POST['category'] ?? ''), $categories, true) ? (string) $_POST['category'] : 'allowance';
        $behaviours = ['category_default', 'earning_expense', 'deduction_liability', 'employer_contribution', 'reimbursement', 'advance_recovery', 'non_posting', 'custom'];
        $behaviour = in_array((string) ($_POST['posting_behaviour'] ?? ''), $behaviours, true) ? (string) $_POST['posting_behaviour'] : 'category_default';
        $calcTypes = ['manual', 'fixed', 'percent_basic', 'overtime_hours', 'service_charge'];
        $calcType = in_array((string) ($_POST['calc_type'] ?? ''), $calcTypes, true) ? (string) $_POST['calc_type'] : 'manual';
        if ($code === '' || $name === '') {
            flash('error', 'Component code and name are required.');
            redirect('admin/payroll-settings.php');
        }
        // Required ledger mappings depend on the behaviour — validated here so
        // a custom component can never save half-mapped.
        $debitLedgerId = (int) ($_POST['debit_ledger_id'] ?? 0) ?: null;
        $creditLedgerId = (int) ($_POST['credit_ledger_id'] ?? 0) ?: null;
        $erExpenseLedgerId = (int) ($_POST['employer_expense_ledger_id'] ?? 0) ?: null;
        $erPayableLedgerId = (int) ($_POST['contribution_payable_ledger_id'] ?? 0) ?: null;
        if ($behaviour === 'custom' && ($debitLedgerId === null || $creditLedgerId === null)) {
            flash('error', 'Custom posting needs BOTH a debit and a credit ledger.');
            redirect('admin/payroll-settings.php');
        }
        $effectiveFrom = trim((string) ($_POST['effective_from'] ?? '')) ?: null;
        $effectiveTo = trim((string) ($_POST['effective_to'] ?? '')) ?: null;
        if ($effectiveFrom !== null && $effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            flash('error', 'The component effective-to date cannot be before effective-from.');
            redirect('admin/payroll-settings.php');
        }
        $projectionMethods = ['regular', 'actual_only', 'guaranteed', 'manual', 'excluded'];
        $projectionMethod = in_array((string) ($_POST['tax_projection_method'] ?? ''), $projectionMethods, true)
            ? (string) $_POST['tax_projection_method']
            : (in_array($calcType, ['overtime_hours', 'service_charge', 'manual'], true) ? 'actual_only' : 'regular');
        $params = [
            'company_id' => $companyId, 'code' => $code, 'name' => $name,
            'name_np' => trim((string) ($_POST['name_np'] ?? '')) ?: null,
            'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
            'category' => $category, 'posting_behaviour' => $behaviour, 'calc_type' => $calcType,
            'tax_projection_method' => $projectionMethod,
            'default_value' => max(0.0, round((float) ($_POST['default_value'] ?? 0), 2)),
            'percentage' => (float) ($_POST['percentage'] ?? 0) > 0 ? round((float) $_POST['percentage'], 4) : null,
            'calc_basis' => trim((string) ($_POST['calc_basis'] ?? '')) ?: null,
            'debit_ledger_id' => $debitLedgerId,
            'credit_ledger_id' => $creditLedgerId,
            'employer_expense_ledger_id' => $erExpenseLedgerId,
            'contribution_payable_ledger_id' => $erPayableLedgerId,
            'taxable' => isset($_POST['taxable']) ? 1 : 0,
            'include_in_gross' => isset($_POST['include_in_gross']) ? 1 : 0,
            'include_in_net' => isset($_POST['include_in_net']) ? 1 : 0,
            'retirement_basis' => isset($_POST['retirement_basis']) ? 1 : 0,
            'overtime_basis' => isset($_POST['overtime_basis']) ? 1 : 0,
            'service_charge_basis' => isset($_POST['service_charge_basis']) ? 1 : 0,
            'allow_employee_override' => isset($_POST['allow_employee_override']) ? 1 : 0,
            'allow_period_override' => isset($_POST['allow_period_override']) ? 1 : 0,
            'allow_zero' => isset($_POST['allow_zero']) ? 1 : 0,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'employer_paid' => isset($_POST['employer_paid']) ? 1 : 0,
            'active' => isset($_POST['active']) ? 1 : 0,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];
        try {
            if ($componentId > 0) {
                $params['id'] = $componentId;
                $params['updated_by'] = $userId;
                db()->prepare('UPDATE payroll_components SET code = :code, name = :name, name_np = :name_np,
                        description = :description, category = :category, posting_behaviour = :posting_behaviour,
                        calc_type = :calc_type, tax_projection_method = :tax_projection_method,
                        default_value = :default_value, percentage = :percentage, calc_basis = :calc_basis,
                        debit_ledger_id = :debit_ledger_id, credit_ledger_id = :credit_ledger_id,
                        employer_expense_ledger_id = :employer_expense_ledger_id, contribution_payable_ledger_id = :contribution_payable_ledger_id,
                        taxable = :taxable, include_in_gross = :include_in_gross, include_in_net = :include_in_net,
                        retirement_basis = :retirement_basis, overtime_basis = :overtime_basis, service_charge_basis = :service_charge_basis,
                        allow_employee_override = :allow_employee_override, allow_period_override = :allow_period_override, allow_zero = :allow_zero,
                        effective_from = :effective_from, effective_to = :effective_to,
                        employer_paid = :employer_paid, active = :active, sort_order = :sort_order, updated_by = :updated_by
                    WHERE id = :id AND company_id = :company_id')->execute($params);
                log_activity('payroll_component', $componentId, 'updated', 'Pay component ' . $code . ' updated.', $userId);
            } else {
                $params['created_by'] = $userId;
                db()->prepare('INSERT INTO payroll_components (company_id, code, name, name_np, description, category, posting_behaviour,
                        calc_type, tax_projection_method, default_value, percentage, calc_basis, debit_ledger_id, credit_ledger_id,
                        employer_expense_ledger_id, contribution_payable_ledger_id, taxable, include_in_gross, include_in_net,
                        retirement_basis, overtime_basis, service_charge_basis, allow_employee_override, allow_period_override, allow_zero,
                        effective_from, effective_to, employer_paid, active, sort_order, created_by)
                    VALUES (:company_id, :code, :name, :name_np, :description, :category, :posting_behaviour,
                        :calc_type, :tax_projection_method, :default_value, :percentage, :calc_basis, :debit_ledger_id, :credit_ledger_id,
                        :employer_expense_ledger_id, :contribution_payable_ledger_id, :taxable, :include_in_gross, :include_in_net,
                        :retirement_basis, :overtime_basis, :service_charge_basis, :allow_employee_override, :allow_period_override, :allow_zero,
                        :effective_from, :effective_to, :employer_paid, :active, :sort_order, :created_by)')->execute($params);
                log_activity('payroll_component', (int) db()->lastInsertId(), 'created', 'Pay component ' . $code . ' created.', $userId);
            }
            flash('success', 'Component saved.');
        } catch (Throwable $exception) {
            flash('error', (string) $exception->getCode() === '23000' ? 'Component code already exists.' : 'Could not save component: ' . $exception->getMessage());
        }
        redirect('admin/payroll-settings.php');
    }

    if ($action === 'save_ot_sc_settings') {
        $weekStart = max(0, min(6, (int) ($_POST['ot_week_start'] ?? 0)));
        db()->prepare('UPDATE payroll_settings SET
                ot_weekly_threshold = :thr, ot_week_start = :ws, ot_component_id = :otc,
                ot_rate_source = :src, ot_monthly_hours = :mh, ot_multiplier = :mult,
                ot_rounding = :rnd, ot_require_approval = :appr,
                sc_component_id = :scc, sc_employee_pct = :sce, sc_employer_pct = :scr
            WHERE company_id = :cid')
            ->execute([
                'thr' => max(0.0, round((float) ($_POST['ot_weekly_threshold'] ?? 40), 2)),
                'ws' => $weekStart,
                'otc' => (int) ($_POST['ot_component_id'] ?? 0) ?: null,
                'src' => (string) ($_POST['ot_rate_source'] ?? '') === 'fixed_rate' ? 'fixed_rate' : 'salary_derived',
                'mh' => max(1.0, round((float) ($_POST['ot_monthly_hours'] ?? 208), 2)),
                'mult' => max(0.0, round((float) ($_POST['ot_multiplier'] ?? 1.5), 3)),
                'rnd' => in_array((string) ($_POST['ot_rounding'] ?? ''), ['none', 'nearest', 'down'], true) ? (string) $_POST['ot_rounding'] : 'nearest',
                'appr' => isset($_POST['ot_require_approval']) ? 1 : 0,
                'scc' => (int) ($_POST['sc_component_id'] ?? 0) ?: null,
                'sce' => max(0.0, min(100.0, round((float) ($_POST['sc_employee_pct'] ?? 68), 2))),
                'scr' => max(0.0, min(100.0, round((float) ($_POST['sc_employer_pct'] ?? 32), 2))),
                'cid' => $companyId,
            ]);
        flash('success', 'Overtime and service-charge settings saved.');
        redirect('admin/payroll-settings.php#otsc');
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
$ledgers = db()->prepare("SELECT l.id, l.code, l.name, l.type, COALESCE(g.is_cash_or_bank, 0) AS is_cash_or_bank
    FROM ledgers l LEFT JOIN ledger_groups g ON g.id = l.group_id
    WHERE l.company_id = :cid AND l.status = 'active' ORDER BY l.code ASC");
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

// Each mapping shows only the ledgers that can legally sit there (expense
// mappings list expense ledgers, payables list liabilities, the payment
// account lists cash/bank-group ledgers) — the currently-mapped ledger is
// always kept even if misfiled, so an old mapping stays visible/fixable.
$ledgerSelect = static function (string $name, int $selected, array $types = [], bool $cashBankOnly = false) use ($ledgers): string {
    $html = '<select name="' . e($name) . '" class="js-searchable"><option value="0">Not mapped</option>';
    foreach ($ledgers as $ledger) {
        $isSelected = $selected === (int) $ledger['id'];
        if (!$isSelected) {
            if ($cashBankOnly && (int) ($ledger['is_cash_or_bank'] ?? 0) !== 1) {
                continue;
            }
            if (!$cashBankOnly && $types !== [] && !in_array((string) $ledger['type'], $types, true)) {
                continue;
            }
        }
        $html .= '<option value="' . (int) $ledger['id'] . '"' . ($isSelected ? ' selected' : '') . '>'
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
        <label>Salary expense (Dr)<?= $ledgerSelect('salary_expense_ledger_id', (int) ($settings['salary_expense_ledger_id'] ?? 0), ['expense']) ?></label>
        <label>Employer contribution expense (Dr)<?= $ledgerSelect('employer_contrib_expense_ledger_id', (int) ($settings['employer_contrib_expense_ledger_id'] ?? 0), ['expense']) ?></label>
        <label>Remuneration tax (TDS) payable (Cr)<?= $ledgerSelect('tds_payable_ledger_id', (int) ($settings['tds_payable_ledger_id'] ?? 0), ['liability']) ?></label>
        <label>Social Security Tax payable (Cr) <span class="frm-optional">1% first slab — falls back to the TDS ledger</span><?= $ledgerSelect('sst_payable_ledger_id', (int) ($settings['sst_payable_ledger_id'] ?? 0), ['liability']) ?></label>
        <label>Retirement fund payable (Cr)<?= $ledgerSelect('retirement_payable_ledger_id', (int) ($settings['retirement_payable_ledger_id'] ?? 0), ['liability']) ?></label>
        <label>Salary payable (Cr)<?= $ledgerSelect('salary_payable_ledger_id', (int) ($settings['salary_payable_ledger_id'] ?? 0), ['liability']) ?></label>
        <label>Employee advance ledger<?= $ledgerSelect('advance_ledger_id', (int) ($settings['advance_ledger_id'] ?? 0), ['asset']) ?></label>
        <label>Bank / cash for salary payment<?= $ledgerSelect('bank_ledger_id', (int) ($settings['bank_ledger_id'] ?? 0), [], true) ?></label>
        <label style="display:flex;align-items:center;gap:8px;flex-direction:row"><input type="checkbox" name="auto_post" <?= (int) ($settings['auto_post'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Auto-post accrual voucher on approval</label>
        <label style="display:flex;align-items:center;gap:8px;flex-direction:row"><input type="checkbox" name="enforce_sod" <?= (int) ($settings['enforce_sod'] ?? 0) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Segregation of duties (preparer cannot approve)</label>
        <label style="display:flex;align-items:center;gap:8px;flex-direction:row"><input type="checkbox" name="deduct_unpaid_leave" <?= (int) ($settings['deduct_unpaid_leave'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Deduct salary for approved unpaid leave (from Attendance/HR)</label>
        <label>Working days per month (for the leave day-rate)<input type="number" step="0.5" min="1" max="31" name="standard_working_days" value="<?= e((string) ($settings['standard_working_days'] ?? '30')) ?>"></label>
        <label>Excess tax withheld — treatment
            <select name="excess_tax_treatment" title="What happens when the revised annual tax estimate is below the tax already deducted">
                <?php foreach (['offset' => 'Offset against future payroll tax', 'refund' => 'Refund through payroll (approved)', 'carry_forward' => 'Carry forward to final settlement', 'manual' => 'Manual settlement after approval'] as $extValue => $extLabel): ?>
                    <option value="<?= e($extValue) ?>" <?= (string) ($settings['excess_tax_treatment'] ?? 'offset') === $extValue ? 'selected' : '' ?>><?= e($extLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="workspace-span-2"><button type="submit"><?= icon('settings') ?>Save Settings</button></div>
    </form>
</section>

<section class="mbw-card" aria-label="Pay components" id="components">
    <div class="mbw-card-head"><h2>Pay Components</h2></div>
    <p style="margin:0 0 12px;color:var(--mbw-muted);font-size:12.5px">
        Every pay head is a component with its OWN accounting treatment. Amounts are suggestions only — payroll
        preparation may accept, change, zero, add or remove them per employee and month (with an audited reason).
        Ledger mappings here take priority; the general control ledgers above are the documented fallback.
    </p>
    <form method="post" class="workspace-form-grid" style="margin-bottom:14px">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_component">
        <input type="hidden" name="component_id" value="<?= e((int) ($editComponent['id'] ?? 0)) ?>">
        <label>Code<input type="text" name="code" maxlength="40" value="<?= e($editComponent['code'] ?? '') ?>" required placeholder="HOUSING"></label>
        <label>Name<input type="text" name="name" maxlength="120" value="<?= e($editComponent['name'] ?? '') ?>" required placeholder="Housing Allowance"></label>
        <label>Name (Nepali)<input type="text" name="name_np" maxlength="120" value="<?= e($editComponent['name_np'] ?? '') ?>"></label>
        <label>Description<input type="text" name="description" maxlength="255" value="<?= e($editComponent['description'] ?? '') ?>" placeholder="Optional"></label>
        <label>Category
            <select name="category">
                <?php foreach (['allowance' => 'Allowance (earning)', 'overtime' => 'Overtime', 'benefit' => 'Other benefit / earning', 'reimbursement' => 'Reimbursement', 'deduction' => 'Deduction', 'tax' => 'Tax deduction', 'advance_recovery' => 'Advance recovery', 'employer_contribution' => 'Employer contribution', 'info' => 'Informational (non-posting)'] as $categoryValue => $categoryLabel): ?>
                    <option value="<?= e($categoryValue) ?>" <?= ($editComponent['category'] ?? '') === $categoryValue ? 'selected' : '' ?>><?= e($categoryLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Posting behaviour
            <select name="posting_behaviour">
                <?php foreach (['category_default' => 'Follow category (default)', 'earning_expense' => 'Earning expense (Dr expense)', 'deduction_liability' => 'Deduction liability (Cr liability)', 'employer_contribution' => 'Employer contribution (Dr expense / Cr payable)', 'reimbursement' => 'Reimbursement (Dr expense, paid with salary)', 'advance_recovery' => 'Advance recovery (Cr advance asset)', 'non_posting' => 'Non-posting (display only)', 'custom' => 'Custom debit and credit'] as $behaviourValue => $behaviourLabel): ?>
                    <option value="<?= e($behaviourValue) ?>" <?= ($editComponent['posting_behaviour'] ?? 'category_default') === $behaviourValue ? 'selected' : '' ?>><?= e($behaviourLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Debit ledger <span class="frm-optional">earnings / reimbursement / custom</span><?= $ledgerSelect('debit_ledger_id', (int) ($editComponent['debit_ledger_id'] ?? 0)) ?></label>
        <label>Credit ledger <span class="frm-optional">deductions / recovery / custom</span><?= $ledgerSelect('credit_ledger_id', (int) ($editComponent['credit_ledger_id'] ?? 0)) ?></label>
        <label>Employer contribution expense <span class="frm-optional">employer contributions</span><?= $ledgerSelect('employer_expense_ledger_id', (int) ($editComponent['employer_expense_ledger_id'] ?? 0), ['expense']) ?></label>
        <label>Contribution payable <span class="frm-optional">employer contributions</span><?= $ledgerSelect('contribution_payable_ledger_id', (int) ($editComponent['contribution_payable_ledger_id'] ?? 0), ['liability']) ?></label>
        <label>Tax projection
            <select name="tax_projection_method" title="How the projected annual tax treats this component">
                <?php foreach (['regular' => 'Regular — project for future months', 'actual_only' => 'Actual only — count when earned (OT, service charge, bonus)', 'guaranteed' => 'Guaranteed annual amount', 'manual' => 'Manual projection only', 'excluded' => 'Excluded from tax estimation'] as $projValue => $projLabel): ?>
                    <option value="<?= e($projValue) ?>" <?= (string) ($editComponent['tax_projection_method'] ?? 'regular') === $projValue ? 'selected' : '' ?>><?= e($projLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Suggested calculation
            <select name="calc_type">
                <option value="manual" <?= ($editComponent['calc_type'] ?? 'manual') === 'manual' ? 'selected' : '' ?>>Manual amount (no suggestion)</option>
                <option value="fixed" <?= ($editComponent['calc_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed default suggestion</option>
                <option value="percent_basic" <?= ($editComponent['calc_type'] ?? '') === 'percent_basic' ? 'selected' : '' ?>>% of basic salary suggestion</option>
                <option value="overtime_hours" <?= ($editComponent['calc_type'] ?? '') === 'overtime_hours' ? 'selected' : '' ?>>Overtime (from weekly attendance)</option>
                <option value="service_charge" <?= ($editComponent['calc_type'] ?? '') === 'service_charge' ? 'selected' : '' ?>>Service charge (from allocation)</option>
            </select>
        </label>
        <label>Default amount (or legacy %)<input type="number" step="0.01" min="0" name="default_value" value="<?= e(number_format((float) ($editComponent['default_value'] ?? 0), 2, '.', '')) ?>"></label>
        <label>Percentage <span class="frm-optional">for % suggestions</span><input type="number" step="0.0001" min="0" name="percentage" value="<?= ($editComponent['percentage'] ?? null) !== null ? e(number_format((float) $editComponent['percentage'], 4, '.', '')) : '' ?>"></label>
        <label>Calculation basis <span class="frm-optional">note, e.g. "basic"</span><input type="text" name="calc_basis" maxlength="40" value="<?= e($editComponent['calc_basis'] ?? '') ?>"></label>
        <label>Effective from<input type="date" name="effective_from" value="<?= e($editComponent['effective_from'] ?? '') ?>"></label>
        <label>Effective to<input type="date" name="effective_to" value="<?= e($editComponent['effective_to'] ?? '') ?>"></label>
        <label>Sort order<input type="number" name="sort_order" value="<?= e((int) ($editComponent['sort_order'] ?? 0)) ?>"></label>
        <div class="workspace-span-2" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
            <label style="display:flex;align-items:center;gap:6px;flex-direction:row"><input type="checkbox" name="taxable" <?= (int) ($editComponent['taxable'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Taxable</label>
            <label style="display:flex;align-items:center;gap:6px;flex-direction:row"><input type="checkbox" name="include_in_gross" <?= (int) ($editComponent['include_in_gross'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> In gross pay</label>
            <label style="display:flex;align-items:center;gap:6px;flex-direction:row"><input type="checkbox" name="include_in_net" <?= (int) ($editComponent['include_in_net'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> In net pay</label>
            <label style="display:flex;align-items:center;gap:6px;flex-direction:row"><input type="checkbox" name="retirement_basis" <?= (int) ($editComponent['retirement_basis'] ?? 0) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Retirement basis</label>
            <label style="display:flex;align-items:center;gap:6px;flex-direction:row"><input type="checkbox" name="overtime_basis" <?= (int) ($editComponent['overtime_basis'] ?? 0) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Overtime-rate basis</label>
            <label style="display:flex;align-items:center;gap:6px;flex-direction:row"><input type="checkbox" name="service_charge_basis" <?= (int) ($editComponent['service_charge_basis'] ?? 0) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Service-charge basis</label>
            <label style="display:flex;align-items:center;gap:6px;flex-direction:row"><input type="checkbox" name="allow_employee_override" <?= (int) ($editComponent['allow_employee_override'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Employee override</label>
            <label style="display:flex;align-items:center;gap:6px;flex-direction:row"><input type="checkbox" name="allow_period_override" <?= (int) ($editComponent['allow_period_override'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Period override</label>
            <label style="display:flex;align-items:center;gap:6px;flex-direction:row"><input type="checkbox" name="allow_zero" <?= (int) ($editComponent['allow_zero'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Zero allowed</label>
            <label style="display:flex;align-items:center;gap:6px;flex-direction:row"><input type="checkbox" name="employer_paid" <?= (int) ($editComponent['employer_paid'] ?? 0) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Employer-paid (legacy)</label>
            <label style="display:flex;align-items:center;gap:6px;flex-direction:row"><input type="checkbox" name="active" <?= (int) ($editComponent['active'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Active</label>
        </div>
        <div class="workspace-span-2"><button type="submit"><?= icon('plus') ?><?= $editComponent ? 'Update Component' : 'Add Component' ?></button>
            <?php if ($editComponent): ?> <a class="button secondary" href="<?= e(url('admin/payroll-settings.php')) ?>">Cancel</a><?php endif; ?></div>
    </form>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Posting</th><th>Suggestion</th><th>Dr / Cr ledger</th><th>Taxable</th><th>Effective</th><th>Status</th><th></th></tr></thead>
        <tbody>
            <?php if ($components === []): ?><tr><td colspan="10">No components yet. Add allowances, overtime, service charge, reimbursements, and deductions here.</td></tr><?php endif; ?>
            <?php $ledgerNameById = []; foreach ($ledgers as $ledgerRow) { $ledgerNameById[(int) $ledgerRow['id']] = (string) $ledgerRow['code']; } ?>
            <?php foreach ($components as $component): ?>
                <tr>
                    <td><strong><?= e($component['code']) ?></strong></td>
                    <td><?= e($component['name']) ?><?= $component['name_np'] ? ' <small>(' . e($component['name_np']) . ')</small>' : '' ?></td>
                    <td><?= e(ucwords(str_replace('_', ' ', (string) $component['category']))) ?></td>
                    <td><small><?= e(str_replace('_', ' ', (string) ($component['posting_behaviour'] ?? 'category_default'))) ?></small></td>
                    <td class="is-numeric"><?php
                        $calcType = (string) $component['calc_type'];
                        if ($calcType === 'percent_basic') {
                            echo e(number_format((float) (($component['percentage'] ?? null) !== null && (float) $component['percentage'] > 0 ? $component['percentage'] : $component['default_value']), 2)) . '% of basic';
                        } elseif ($calcType === 'fixed') {
                            echo e(number_format((float) $component['default_value'], 2));
                        } else {
                            echo e(ucwords(str_replace('_', ' ', $calcType)));
                        }
                    ?></td>
                    <td><small><?= e(($ledgerNameById[(int) ($component['debit_ledger_id'] ?? 0)] ?? '—') . ' / ' . ($ledgerNameById[(int) ($component['credit_ledger_id'] ?? 0)] ?? '—')) ?></small></td>
                    <td><?= (int) $component['taxable'] ? 'Yes' : '<span class="mbw-pill tone-blue">No</span>' ?></td>
                    <td><small><?= e(($component['effective_from'] ?? '') !== '' && $component['effective_from'] !== null ? $component['effective_from'] : 'always') ?><?= ($component['effective_to'] ?? null) ? ' → ' . e($component['effective_to']) : '' ?></small></td>
                    <td><span class="mbw-pill <?= (int) $component['active'] ? 'tone-green' : 'tone-red' ?>"><?= (int) $component['active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td><a class="button secondary" href="<?= e(url('admin/payroll-settings.php?component=' . (int) $component['id'] . '#components')) ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="mbw-card" aria-label="Overtime and service charge" id="otsc">
    <div class="mbw-card-head"><h2>Overtime &amp; Service Charge</h2></div>
    <form method="post" class="workspace-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_ot_sc_settings">
        <?php $componentSelect = static function (string $name, int $selected, array $componentsList): string {
            $html = '<select name="' . e($name) . '"><option value="0">Not mapped</option>';
            foreach ($componentsList as $componentOption) {
                $html .= '<option value="' . (int) $componentOption['id'] . '"' . ($selected === (int) $componentOption['id'] ? ' selected' : '') . '>'
                    . e($componentOption['code'] . ' — ' . $componentOption['name']) . '</option>';
            }
            return $html . '</select>';
        }; ?>
        <label>Weekly overtime threshold (hours)<input type="number" step="0.25" min="0" name="ot_weekly_threshold" value="<?= e(number_format((float) ($settings['ot_weekly_threshold'] ?? 40), 2, '.', '')) ?>"></label>
        <label>Week starts on
            <select name="ot_week_start">
                <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $dowIndex => $dowLabel): ?>
                    <option value="<?= $dowIndex ?>" <?= (int) ($settings['ot_week_start'] ?? 0) === $dowIndex ? 'selected' : '' ?>><?= e($dowLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Overtime pay component<?= $componentSelect('ot_component_id', (int) ($settings['ot_component_id'] ?? 0), $components) ?></label>
        <label>Overtime rate source
            <select name="ot_rate_source">
                <option value="salary_derived" <?= (string) ($settings['ot_rate_source'] ?? 'salary_derived') === 'salary_derived' ? 'selected' : '' ?>>Derived from basic salary / monthly hours</option>
                <option value="fixed_rate" <?= (string) ($settings['ot_rate_source'] ?? '') === 'fixed_rate' ? 'selected' : '' ?>>Fixed per-employee hourly rate only</option>
            </select>
        </label>
        <label>Monthly hours for the derived rate<input type="number" step="0.5" min="1" name="ot_monthly_hours" value="<?= e(number_format((float) ($settings['ot_monthly_hours'] ?? 208), 2, '.', '')) ?>"></label>
        <label>Overtime multiplier<input type="number" step="0.001" min="0" name="ot_multiplier" value="<?= e(number_format((float) ($settings['ot_multiplier'] ?? 1.5), 3, '.', '')) ?>"></label>
        <label>Overtime rounding
            <select name="ot_rounding">
                <?php foreach (['nearest' => 'Nearest rupee', 'down' => 'Round down', 'none' => 'Two decimals'] as $roundValue => $roundLabel): ?>
                    <option value="<?= e($roundValue) ?>" <?= (string) ($settings['ot_rounding'] ?? 'nearest') === $roundValue ? 'selected' : '' ?>><?= e($roundLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:flex;align-items:center;gap:8px;flex-direction:row"><input type="checkbox" name="ot_require_approval" <?= (int) ($settings['ot_require_approval'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;min-height:auto"> Overtime needs approval before payroll picks it up</label>
        <label>Service charge pay component<?= $componentSelect('sc_component_id', (int) ($settings['sc_component_id'] ?? 0), $components) ?></label>
        <label>Employee share % of declared total<input type="number" step="0.01" min="0" max="100" name="sc_employee_pct" value="<?= e(number_format((float) ($settings['sc_employee_pct'] ?? 68), 2, '.', '')) ?>"></label>
        <label>Employer share %<input type="number" step="0.01" min="0" max="100" name="sc_employer_pct" value="<?= e(number_format((float) ($settings['sc_employer_pct'] ?? 32), 2, '.', '')) ?>"></label>
        <div class="workspace-span-2"><button type="submit"><?= icon('settings') ?>Save Overtime &amp; Service Charge Settings</button></div>
    </form>
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
