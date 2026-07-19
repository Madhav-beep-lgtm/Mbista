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
$sym = site_currency_symbol();

$employees = payroll_company_employees($companyId, false);
$employeeId = (int) ($_GET['employee'] ?? 0);
$employee = null;
foreach ($employees as $rosterRow) {
    if ((int) $rosterRow['id'] === $employeeId) {
        $employee = $rosterRow;
    }
}
if (!$employee && $employees !== []) {
    $employee = $employees[0];
    $employeeId = (int) $employee['id'];
}
$periodFilter = max(0, min(12, (int) ($_GET['period'] ?? 0))); // 0 = whole year

$lines = [];
if ($employeeId > 0) {
    $sql = "SELECT l.*, r.period_no, r.period_label, r.pay_date, r.status AS run_status, r.accrual_voucher_id
        FROM payroll_run_lines l
        INNER JOIN payroll_runs r ON r.id = l.run_id
        WHERE r.company_id = :cid AND r.fiscal_year_id = :fy AND r.status <> 'cancelled'
          AND l.payroll_employee_id = :pe" . ($periodFilter > 0 ? ' AND r.period_no = :p' : '') . '
        ORDER BY r.period_no ASC, r.id ASC';
    $stmt = db()->prepare($sql);
    $params = ['cid' => $companyId, 'fy' => $fiscalYearId, 'pe' => $employeeId];
    if ($periodFilter > 0) {
        $params['p'] = $periodFilter;
    }
    $stmt->execute($params);
    $lines = $stmt->fetchAll();
}
$tot = ['gross' => 0.0, 'sst' => 0.0, 'rem' => 0.0, 'ret' => 0.0, 'adv' => 0.0, 'other' => 0.0, 'net' => 0.0];
foreach ($lines as $l) {
    $tot['gross'] += (float) $l['gross'];
    $tot['sst'] += (float) ($l['sst_month'] ?? 0);
    $tot['rem'] += (float) $l['tax_month'] - (float) ($l['sst_month'] ?? 0);
    $tot['ret'] += (float) $l['retirement_employee_month'];
    $tot['adv'] += (float) $l['advance_deduction'];
    $tot['other'] += (float) $l['other_deduction'] + (float) ($l['adj_deduction'] ?? 0);
    $tot['net'] += (float) $l['net_pay'];
}

$pageTitle = 'Employee Salary Sheet';
$pageSubtitle = 'Month-by-month (or single-month) salary record of one employee for ' . ($fiscalYear['label'] ?? '') . '.';
$pageHero = ['icon' => 'wallet'];
$bodyClass = 'admin-layout accounting-module-page payroll-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card" aria-label="Employee salary sheet">
    <div class="mbw-card-head">
        <h2><?= icon('users') ?>Salary Sheet<?= $employee ? ' — ' . e($employee['employee_code'] . ' ' . ($employee['person_name'] ?? '')) : '' ?></h2>
        <div class="mbw-card-tools">
            <a class="mbw-view-all" href="<?= e(url('admin/payroll.php')) ?>">Payroll Processing &#8594;</a>
            <a class="mbw-view-all" href="<?= e(url('admin/payroll-employees.php')) ?>">Employees &#8594;</a>
        </div>
    </div>
    <form method="get" class="workspace-form-grid" style="margin-bottom:12px">
        <label>Employee
            <select name="employee" class="js-searchable" onchange="this.form.submit()">
                <?php if ($employees === []): ?><option value="">No payroll employees</option><?php endif; ?>
                <?php foreach ($employees as $rosterRow): ?>
                    <option value="<?= (int) $rosterRow['id'] ?>" <?= (int) $rosterRow['id'] === $employeeId ? 'selected' : '' ?>>
                        <?= e($rosterRow['employee_code'] . ' — ' . ($rosterRow['person_name'] ?? '') . ((string) $rosterRow['status'] !== 'active' ? ' (inactive)' : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Period
            <select name="period" onchange="this.form.submit()">
                <option value="0">Whole year (<?= e((string) ($fiscalYear['label'] ?? '')) ?>)</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $periodFilter === $m ? 'selected' : '' ?>><?= e(payroll_bs_month_name($m)) ?> only</option>
                <?php endfor; ?>
            </select>
        </label>
        <div><button type="submit" class="button secondary">Apply</button>
            <button type="button" class="button secondary" onclick="window.print()">Print</button></div>
    </form>
    <?php if ($employee): ?>
        <p style="margin:0 0 10px;color:var(--mbw-muted);font-size:12.5px">
            <?= e(trim(((string) ($employee['department'] ?? '')) . ' / ' . ((string) ($employee['designation'] ?? '')), ' /') ?: 'No department set') ?>
            · PAN <?= e((string) ($employee['pan_no'] ?? '-') ?: '-') ?>
            · <?= e(strtoupper((string) $employee['retirement_scheme'])) ?> scheme
            · Basic <?= e($sym . number_format((float) $employee['basic_salary'], 2)) ?>/month
        </p>
    <?php endif; ?>
    <div style="overflow-x:auto">
    <table>
        <thead><tr>
            <th>Month</th><th>Status</th><th class="is-numeric">Gross</th>
            <th class="is-numeric">SST (1%)</th><th class="is-numeric">Remuneration Tax</th>
            <th class="is-numeric">Retirement</th><th class="is-numeric">Advance Rec.</th>
            <th class="is-numeric">Other Ded.</th><th class="is-numeric">Net Pay</th><th></th>
        </tr></thead>
        <tbody>
            <?php if ($lines === []): ?><tr><td colspan="10">No salary lines for this employee in <?= e((string) ($fiscalYear['label'] ?? 'this fiscal year')) ?>.</td></tr><?php endif; ?>
            <?php foreach ($lines as $l): ?>
                <tr>
                    <td><strong><?= e(payroll_bs_month_name((int) $l['period_no'])) ?></strong>
                        <?php if (trim((string) $l['period_label']) !== payroll_bs_month_name((int) $l['period_no'])): ?><small style="display:block;color:var(--mbw-muted)"><?= e((string) $l['period_label']) ?></small><?php endif; ?></td>
                    <td><span class="mbw-pill <?= in_array((string) $l['run_status'], ['posted', 'paid'], true) ? 'tone-green' : 'tone-amber' ?>"><?= e(ucfirst(str_replace('_', ' ', (string) $l['run_status']))) ?></span></td>
                    <td class="is-numeric"><?= e(number_format((float) $l['gross'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) ($l['sst_month'] ?? 0), 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $l['tax_month'] - (float) ($l['sst_month'] ?? 0), 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $l['retirement_employee_month'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $l['advance_deduction'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $l['other_deduction'] + (float) ($l['adj_deduction'] ?? 0), 2)) ?></td>
                    <td class="is-numeric"><strong><?= e(number_format((float) $l['net_pay'], 2)) ?></strong></td>
                    <td><a class="button secondary" style="min-height:30px;padding:3px 10px" target="_blank" href="<?= e(url('admin/payroll-payslip.php?line=' . (int) $l['id'])) ?>">Payslip</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($lines !== []): ?>
            <tr style="font-weight:700;background:var(--mbw-soft,#f2f7f4)">
                <td colspan="2">Total (<?= $periodFilter > 0 ? e(payroll_bs_month_name($periodFilter)) : 'year' ?>)</td>
                <td class="is-numeric"><?= e(number_format($tot['gross'], 2)) ?></td>
                <td class="is-numeric"><?= e(number_format($tot['sst'], 2)) ?></td>
                <td class="is-numeric"><?= e(number_format($tot['rem'], 2)) ?></td>
                <td class="is-numeric"><?= e(number_format($tot['ret'], 2)) ?></td>
                <td class="is-numeric"><?= e(number_format($tot['adv'], 2)) ?></td>
                <td class="is-numeric"><?= e(number_format($tot['other'], 2)) ?></td>
                <td class="is-numeric"><?= e(number_format($tot['net'], 2)) ?></td>
                <td></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
    <p style="margin:10px 0 0;color:var(--mbw-muted);font-size:12px">SST = Social Security Tax (the 1% first slab); the remainder of the monthly withholding is Remuneration Tax. Cancelled runs are excluded.</p>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
