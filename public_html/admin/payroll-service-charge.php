<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/payroll_engine.php';

// The employer DECLARES the total (no sales integration): admins, staff with
// payroll grants, and clients in their OWN books (the client IS the employer
// there). Approval posts pay — it needs the payroll post permission.
require_staff_admin_or_client_books();
require_company_context();
require_permission('payroll', 'view');
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
$settings = payroll_settings($companyId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $runId = (int) ($_POST['run_id'] ?? 0);
    $runRow = payroll_run($runId);
    if (!$runRow || (int) $runRow['company_id'] !== $companyId) {
        flash('error', 'Payroll run not found for this company.');
        redirect('admin/payroll-service-charge.php');
    }
    $back = 'admin/payroll-service-charge.php?run=' . $runId;

    if ($action === 'prepare') {
        require_permission('payroll', 'create');
        $result = payroll_sc_prepare(
            $runRow,
            (float) ($_POST['declared_total'] ?? 0),
            (string) ($_POST['allocation_method'] ?? 'equal'),
            (array) ($_POST['employee_ids'] ?? []),
            (array) ($_POST['manual_amount'] ?? []),
            $userId,
            trim((string) ($_POST['notes'] ?? ''))
        );
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Allocation prepared: ' . $sym . number_format((float) $result['pool'], 2) . ' to employees, '
                . $sym . number_format((float) $result['employer_share'], 2) . ' employer share (reporting only). Review and approve below.'
            : (string) ($result['error'] ?? 'Could not prepare the allocation.'));
        redirect($back);
    }

    if ($action === 'approve') {
        require_permission('payroll', 'post');
        $result = payroll_sc_approve($runId, $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Service charge approved and added to the salary sheet as taxable remuneration.'
            : (string) ($result['error'] ?? 'Could not approve the allocation.'));
        redirect($back);
    }

    if ($action === 'remove') {
        require_permission('payroll', 'create');
        $result = payroll_sc_remove($runId, $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Allocation removed and the salary sheet recalculated.'
            : (string) ($result['error'] ?? 'Could not remove the allocation.'));
        redirect($back);
    }
}

$runsStmt = db()->prepare("SELECT id, period_no, period_label, status FROM payroll_runs
    WHERE company_id = :cid AND fiscal_year_id = :fy AND status <> 'cancelled' ORDER BY period_no DESC, id DESC LIMIT 30");
$runsStmt->execute(['cid' => $companyId, 'fy' => $fiscalYearId]);
$runs = $runsStmt->fetchAll();
$selectedRunId = (int) ($_GET['run'] ?? 0);
$run = $selectedRunId > 0 ? payroll_run($selectedRunId) : null;
if ($run && (int) $run['company_id'] !== $companyId) {
    $run = null;
}
if (!$run && $runs !== []) {
    $run = payroll_run((int) $runs[0]['id']);
}

$sc = $run ? payroll_sc_get((int) $run['id']) : null;
$allocations = $sc ? payroll_sc_allocations((int) $sc['id']) : [];
$employeePct = round((float) ($settings['sc_employee_pct'] ?? 68), 2);
$employerPct = round((float) ($settings['sc_employer_pct'] ?? 32), 2);
$runEditable = $run && in_array((string) $run['status'], ['draft', 'calculated'], true);
$canPrepare = user_can_do('payroll', 'create');
$canApprove = user_can_do('payroll', 'post');

// Eligible roster (scoped runs restrict further) + attendance days for review.
$roster = $run ? payroll_company_employees($companyId) : [];
$scopeIds = $run ? payroll_run_scope_ids($run) : [];
if ($scopeIds !== []) {
    $roster = array_values(array_filter($roster, static fn (array $e): bool => in_array((int) $e['id'], $scopeIds, true)));
}
$eligibleRoster = array_values(array_filter($roster, static fn (array $e): bool => (int) ($e['sc_eligible'] ?? 1) === 1));
$daysByEmployee = $run && $eligibleRoster !== []
    ? payroll_sc_eligible_days($run, array_map(static fn (array $e): int => (int) $e['id'], $eligibleRoster))
    : [];
$allocationByEmployee = [];
foreach ($allocations as $allocationRow) {
    $allocationByEmployee[(int) $allocationRow['payroll_employee_id']] = $allocationRow;
}

$pageTitle = 'Service Charge Allocation';
$pageSubtitle = 'Employer-declared service charge: ' . number_format($employeePct, 0) . '% to eligible employees through payroll (taxable), ' . number_format($employerPct, 0) . '% employer share kept for reporting.';
$pageHero = ['icon' => 'wallet'];
$bodyClass = 'admin-layout accounting-module-page payroll-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card" aria-label="Service charge run">
    <div class="mbw-card-head">
        <h2>Payroll Period</h2>
        <div class="mbw-card-tools">
            <a class="mbw-view-all" href="<?= e(url('admin/payroll.php' . ($run ? '?run=' . (int) $run['id'] : ''))) ?>">Payroll Processing &#8594;</a>
            <a class="mbw-view-all" href="<?= e(url('admin/payroll-settings.php#otsc')) ?>">Service Charge Settings &#8594;</a>
        </div>
    </div>
    <form method="get">
        <label style="max-width:340px">Payroll run
            <select name="run" onchange="this.form.submit()">
                <?php if ($runs === []): ?><option value="">No runs yet — create one in Payroll Processing</option><?php endif; ?>
                <?php foreach ($runs as $runOption): ?>
                    <option value="<?= e((int) $runOption['id']) ?>" <?= $run && (int) $runOption['id'] === (int) $run['id'] ? 'selected' : '' ?>>
                        <?= e(payroll_bs_month_name((int) $runOption['period_no']) . ' — ' . ucfirst((string) $runOption['status'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
    <?php if ((int) ($settings['sc_component_id'] ?? 0) <= 0): ?>
        <div class="notice error" style="margin-top:12px">No Service Charge pay component is mapped — the allocation cannot reach the salary sheet.
            <a href="<?= e(url('admin/payroll-settings.php#otsc')) ?>">Map it in Payroll Settings</a>.</div>
    <?php endif; ?>
</section>

<?php if ($run): ?>
<?php if ($sc): ?>
<section class="mbw-kpi-grid" aria-label="Declared amounts">
    <?php foreach ([
        ['Declared total', $sym . number_format((float) $sc['declared_total'], 2), 'wallet', 'tone-blue'],
        ['Employee pool (' . number_format((float) $sc['employee_pct'], 0) . '%)', $sym . number_format((float) $sc['employee_pool'], 2), 'users', 'tone-green'],
        ['Employer share (' . number_format((float) $sc['employer_pct'], 0) . '%)', $sym . number_format((float) $sc['employer_share'], 2), 'bank', 'tone-amber'],
        ['Status', ucfirst((string) $sc['status']) . ' · ' . str_replace('_', ' ', (string) $sc['allocation_method']), 'compliance', (string) $sc['status'] === 'approved' ? 'tone-green' : 'tone-amber'],
    ] as [$kpiLabel, $kpiValue, $kpiIcon, $kpiTone]): ?>
        <article class="mbw-kpi">
            <div><span class="mbw-kpi-label"><?= e($kpiLabel) ?></span><div class="mbw-kpi-value" style="font-size:1.05rem"><?= e($kpiValue) ?></div></div>
            <span class="mbw-chip <?= e($kpiTone) ?>"><?= icon($kpiIcon) ?></span>
        </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($runEditable && $canPrepare): ?>
<section class="mbw-card" aria-label="Declare and allocate">
    <div class="mbw-card-head"><h2><?= $sc ? 'Re-prepare Allocation' : 'Declare Service Charge' ?> — <?= e(payroll_bs_month_name((int) $run['period_no'])) ?></h2></div>
    <p style="margin:0 0 12px;color:var(--mbw-muted);font-size:12.5px">
        Enter the DECLARED total for the period — the system never calculates it from sales. <?= e(number_format($employeePct, 0)) ?>%
        goes to the ticked eligible employees (taxable, through payroll); the <?= e(number_format($employerPct, 0)) ?>% employer share is
        stored for reporting only and never touches employee pay or Salary Payable. Rounding lands on the last ticked employee.
    </p>
    <form method="post" class="workspace-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="prepare">
        <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
        <label>Declared total service charge<input type="number" step="0.01" min="0.01" name="declared_total" value="<?= $sc ? e(number_format((float) $sc['declared_total'], 2, '.', '')) : '' ?>" required></label>
        <label>Allocation method
            <select name="allocation_method" id="sc-method">
                <option value="equal" <?= $sc && (string) $sc['allocation_method'] === 'equal' ? 'selected' : '' ?>>Equal — pool ÷ eligible employees</option>
                <option value="days_worked" <?= $sc && (string) $sc['allocation_method'] === 'days_worked' ? 'selected' : '' ?>>Days worked — pool × days ÷ total days</option>
                <option value="manual" <?= $sc && (string) $sc['allocation_method'] === 'manual' ? 'selected' : '' ?>>Manual — enter each amount (must equal the pool)</option>
            </select>
        </label>
        <label class="workspace-span-2">Notes<input type="text" name="notes" maxlength="255" value="<?= e($sc['notes'] ?? '') ?>" placeholder="Optional"></label>
        <div class="workspace-span-2">
            <strong style="font-size:13px;color:var(--mbw-heading)">Eligible employees</strong>
            <div style="overflow-x:auto;margin-top:6px">
            <table>
                <thead><tr><th style="width:44px"></th><th>Employee</th><th class="is-numeric">Days worked (period)</th><th class="is-numeric sc-manual-col">Manual amount</th></tr></thead>
                <tbody>
                    <?php if ($eligibleRoster === []): ?><tr><td colspan="4">No service-charge-eligible employees on this run. Tick "Eligible for service charge" on the employee profile first.</td></tr><?php endif; ?>
                    <?php foreach ($eligibleRoster as $rosterEmp): ?>
                        <?php $existingAllocation = $allocationByEmployee[(int) $rosterEmp['id']] ?? null; ?>
                        <tr>
                            <td><input type="checkbox" name="employee_ids[]" value="<?= (int) $rosterEmp['id'] ?>" <?= $existingAllocation !== null || $sc === null ? 'checked' : '' ?>></td>
                            <td><strong><?= e($rosterEmp['employee_code']) ?></strong> <?= e($rosterEmp['person_name']) ?></td>
                            <td class="is-numeric"><?= e(number_format((float) ($daysByEmployee[(int) $rosterEmp['id']] ?? 0), 0)) ?></td>
                            <td class="is-numeric sc-manual-col"><input type="number" step="0.01" min="0" name="manual_amount[<?= (int) $rosterEmp['id'] ?>]" style="max-width:130px" value="<?= $existingAllocation !== null ? e(number_format((float) $existingAllocation['amount'], 2, '.', '')) : '' ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <div class="workspace-span-2"><button type="submit"><?= icon('reconcile') ?><?= $sc ? 'Re-prepare Allocation' : 'Prepare Allocation' ?></button></div>
    </form>
    <script>
    (function () {
        var method = document.getElementById('sc-method');
        function sync() {
            var manual = method.value === 'manual';
            document.querySelectorAll('.sc-manual-col').forEach(function (el) { el.style.display = manual ? '' : 'none'; });
        }
        method.addEventListener('change', sync);
        sync();
    })();
    </script>
</section>
<?php endif; ?>

<?php if ($sc): ?>
<section class="mbw-card" aria-label="Allocation review">
    <div class="mbw-card-head">
        <h2>Allocation — <?= e(str_replace('_', ' ', (string) $sc['allocation_method'])) ?> (<?= count($allocations) ?> employees)</h2>
        <div class="mbw-card-tools">
            <?php if ($runEditable && (string) $sc['status'] === 'draft' && $canApprove): ?>
                <form method="post" style="display:inline" data-confirm="Approve the service-charge allocation? Each employee's share is added to the salary sheet as TAXABLE remuneration.">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
                    <button type="submit"><?= icon('badge-check') ?>Approve &amp; add to salary sheet</button>
                </form>
            <?php endif; ?>
            <?php if ($runEditable && $canPrepare): ?>
                <form method="post" style="display:inline" data-confirm="Remove the service-charge allocation from this run?">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
                    <button type="submit" class="button secondary" style="color:#a33">Remove allocation</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Employee</th><th class="is-numeric">Eligible days</th><th class="is-numeric">Allocated (taxable)</th><th class="is-numeric">Share of pool</th></tr></thead>
        <tbody>
            <?php $allocatedTotal = 0.0; ?>
            <?php foreach ($allocations as $allocationRow): ?>
                <?php $allocatedTotal = round($allocatedTotal + (float) $allocationRow['amount'], 2); ?>
                <tr>
                    <td><strong><?= e($allocationRow['employee_code']) ?></strong> <?= e($allocationRow['person_name']) ?></td>
                    <td class="is-numeric"><?= $allocationRow['eligible_days'] !== null ? e(number_format((float) $allocationRow['eligible_days'], 0)) : '–' ?></td>
                    <td class="is-numeric"><strong><?= e(number_format((float) $allocationRow['amount'], 2)) ?></strong></td>
                    <td class="is-numeric"><?= (float) $sc['employee_pool'] > 0 ? e(number_format((float) $allocationRow['amount'] / (float) $sc['employee_pool'] * 100, 2)) . '%' : '–' ?></td>
                </tr>
            <?php endforeach; ?>
            <tr style="font-weight:700">
                <td colspan="2">Total allocated (must equal the employee pool)</td>
                <td class="is-numeric"><?= e(number_format($allocatedTotal, 2)) ?></td>
                <td class="is-numeric"><?= abs($allocatedTotal - (float) $sc['employee_pool']) <= 0.004 ? '<span class="mbw-pill tone-green">= pool</span>' : '<span class="mbw-pill tone-red">≠ pool ' . e(number_format((float) $sc['employee_pool'], 2)) . '</span>' ?></td>
            </tr>
        </tbody>
    </table>
    </div>
    <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12px">
        Approved by: <?= $sc['approved_by'] ? e((string) $sc['approved_by']) . ' at ' . e((string) $sc['approved_at']) : '— (draft)' ?>.
        The employer share of <?= e($sym . number_format((float) $sc['employer_share'], 2)) ?> is stored for the Service Charge report only;
        book it separately if your policy requires (it is never added to employee pay or Salary Payable).
    </p>
</section>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
