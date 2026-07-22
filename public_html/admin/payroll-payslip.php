<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/payroll_engine.php';

// Admins open any payslip of the company they have switched into; the person
// the payslip belongs to can open their own (finalized runs only). Staff have
// no blanket access - salary data stays between the admin and the employee.
$currentUser = current_user();
if (!$currentUser) {
    redirect('login.php');
}
$lineId = (int) ($_GET['line'] ?? 0);
$stmt = db()->prepare("SELECT l.*, r.period_label, r.pay_date, r.status AS run_status, r.company_id,
        pe.employee_code, pe.department, pe.designation, pe.pan_no, pe.bank_name, pe.bank_account,
        pe.marital_status, pe.retirement_scheme, pe.user_id AS employee_user_id,
        COALESCE(u.name, pe.full_name, CONCAT('Employee ', pe.employee_code)) AS person_name, c.name AS company_name
    FROM payroll_run_lines l
    INNER JOIN payroll_runs r ON r.id = l.run_id
    INNER JOIN payroll_employees pe ON pe.id = l.payroll_employee_id
    LEFT JOIN users u ON u.id = pe.user_id
    INNER JOIN companies c ON c.id = r.company_id
    WHERE l.id = :id LIMIT 1");
$stmt->execute(['id' => $lineId]);
$line = $stmt->fetch();

$activeCompany = function_exists('current_company') ? current_company() : null;
$isCompanyAdmin = (string) ($currentUser['role'] ?? '') === 'admin'
    && $line && $activeCompany && (int) $activeCompany['id'] === (int) $line['company_id'];
$isOwner = $line && (int) $line['employee_user_id'] === (int) ($currentUser['id'] ?? 0);
if (!$line || (!$isCompanyAdmin && !$isOwner)) {
    http_response_code(404);
    exit('Payslip not found.');
}
if (!$isCompanyAdmin && !in_array((string) $line['run_status'], ['approved', 'posted', 'paid'], true)) {
    exit('This payslip is not finalized yet.');
}

$sym = site_currency_symbol();
$trace = json_decode((string) $line['trace'], true) ?: [];
$fmt = static fn (float $n): string => number_format($n, 2);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Payslip <?= e($line['employee_code']) ?> — <?= e($line['period_label']) ?></title>
<style>
    body { font-family: "Inter", "Segoe UI", system-ui, sans-serif; margin: 28px; color: #16263e; font-size: 13px; }
    .ps-head { background: #16325d; color: #fff; padding: 12px 16px; border-radius: 6px 6px 0 0; display: flex; justify-content: space-between; align-items: center; }
    .ps-head h1 { margin: 0; font-size: 16px; }
    .ps-box { border: 1px solid #d7dfeb; border-top: 0; padding: 14px 16px; }
    .ps-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; }
    .ps-grid div { display: flex; justify-content: space-between; gap: 12px; border-bottom: 1px dashed #e6ecf5; padding: 3px 0; }
    .ps-grid span { color: #55657e; }
    table { width: 100%; border-collapse: collapse; margin-top: 4px; }
    th, td { border: 1px solid #e3e9f2; padding: 6px 9px; text-align: left; font-size: 12.5px; }
    th { background: #f1f5fb; color: #3f4c61; }
    td.num, th.num { text-align: right; }
    .total td { font-weight: 700; background: #eef3fa; }
    .net td { font-weight: 800; background: #16325d; color: #fff; font-size: 14px; }
    .ps-note { color: #55657e; font-size: 11px; margin-top: 10px; }
    .print-button { margin-top: 16px; padding: 10px 18px; background: #16325d; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
    @media print { .print-button { display: none; } body { margin: 8mm; } }
</style>
</head>
<body>
<div class="ps-head">
    <h1><?= e($line['company_name']) ?> — Payslip</h1>
    <div><?= e($line['period_label']) ?><?= $line['pay_date'] ? ' | Paid: ' . e(app_date((string) $line['pay_date'])) : '' ?></div>
</div>
<div class="ps-box ps-grid">
    <div><span>Employee</span><b><?= e($line['employee_code'] . ' — ' . $line['person_name']) ?></b></div>
    <div><span>Department / Designation</span><b><?= e(trim(($line['department'] ?? '') . ' / ' . ($line['designation'] ?? ''), ' /')) ?: '-' ?></b></div>
    <div><span>PAN</span><b><?= e($line['pan_no'] ?? '-') ?></b></div>
    <div><span>Bank</span><b><?= e(trim(($line['bank_name'] ?? '') . ' ' . ($line['bank_account'] ?? ''))) ?: '-' ?></b></div>
    <div><span>Tax category</span><b><?= e(ucfirst((string) $line['marital_status'])) ?></b></div>
    <div><span>Retirement scheme</span><b><?= e(strtoupper((string) $line['retirement_scheme'])) ?></b></div>
</div>
<div class="ps-box">
    <table>
        <thead><tr><th>Earnings</th><th class="num">Amount (<?= e($sym) ?>)</th><th>Deductions</th><th class="num">Amount (<?= e($sym) ?>)</th></tr></thead>
        <tbody>
            <?php
            $sstMonth = (float) ($line['sst_month'] ?? 0);
            $remunerationMonth = round((float) $line['tax_month'] - $sstMonth, 2);
            // Itemize every pay component from the line's snapshot trace — each
            // allowance on its own line, never one combined "Allowances" figure.
            $earnings = [];
            $deductions = [];
            $employerContributions = [];
            foreach ((array) ($trace['components'] ?? []) as $traceComponent) {
                $componentAmount = (float) ($traceComponent['amount'] ?? 0);
                if ($componentAmount == 0.0) {
                    continue;
                }
                $componentCategory = (string) ($traceComponent['category'] ?? 'allowance');
                $componentBehaviour = (string) ($traceComponent['behaviour'] ?? '');
                $componentLabel = (string) ($traceComponent['label'] ?? '');
                $componentSource = (string) ($traceComponent['source'] ?? '');
                if ($componentSource === 'overtime') {
                    $componentLabel .= ' (weekly overtime)';
                } elseif ($componentSource === 'service_charge') {
                    $componentLabel .= ' (service charge)';
                }
                if (!empty($traceComponent['taxable']) === false && $componentCategory !== 'basic'
                    && !in_array($componentCategory, ['deduction', 'tax', 'advance_recovery'], true)) {
                    $componentLabel .= ' (non-taxable)';
                }
                if ($componentBehaviour === 'employer_contribution' || $componentCategory === 'employer_contribution'
                    || (!empty($traceComponent['employer_paid']) && $componentCategory === 'deduction')) {
                    $employerContributions[] = [$componentLabel, $componentAmount];
                } elseif (in_array($componentCategory, ['deduction', 'tax', 'advance_recovery'], true)) {
                    $deductions[] = [$componentLabel, $componentAmount];
                } elseif ($componentBehaviour === 'non_posting' || $componentCategory === 'info') {
                    continue; // informational — shown nowhere on the money columns
                } else {
                    $earnings[] = [$componentLabel, $componentAmount];
                }
            }
            if ($earnings === []) { // very old lines without a component trace
                $earnings = [
                    ['Basic Salary', (float) $line['basic']],
                    ['Allowances', (float) $line['allowances']],
                    ['Overtime', (float) $line['overtime']],
                    ['Other Benefits', (float) $line['benefits']],
                ];
            }
            if ((float) ($line['adj_earning'] ?? 0) > 0) {
                $earnings[] = ['Extra Earning (adjustment)', (float) $line['adj_earning']];
            }
            $deductions = array_merge([
                ['Social Security Tax (1% slab)', $sstMonth],
                ['Remuneration Tax', $remunerationMonth],
                ['Retirement Contribution (employee)', (float) $line['retirement_employee_month']],
                ['Advance / Loan Recovery', (float) $line['advance_deduction']],
            ], $deductions);
            if ((float) ($line['adj_deduction'] ?? 0) > 0) {
                $deductions[] = ['Extra Deduction (adjustment)', (float) $line['adj_deduction']];
            }
            $rowCount = max(count($earnings), count($deductions));
            for ($i = 0; $i < $rowCount; $i++): ?>
                <tr>
                    <td><?= e($earnings[$i][0] ?? '') ?></td><td class="num"><?= isset($earnings[$i]) ? $fmt($earnings[$i][1]) : '' ?></td>
                    <td><?= e($deductions[$i][0] ?? '') ?></td><td class="num"><?= isset($deductions[$i]) ? $fmt($deductions[$i][1]) : '' ?></td>
                </tr>
            <?php endfor; ?>
            <tr class="total">
                <td>Gross Earnings</td><td class="num"><?= $fmt((float) $line['gross']) ?></td>
                <td>Total Deductions</td><td class="num"><?= $fmt((float) $line['tax_month'] + (float) $line['retirement_employee_month'] + (float) $line['advance_deduction'] + (float) $line['other_deduction'] + (float) ($line['adj_deduction'] ?? 0)) ?></td>
            </tr>
            <tr class="net"><td colspan="3">NET PAYABLE</td><td class="num"><?= $fmt((float) $line['net_pay']) ?></td></tr>
        </tbody>
    </table>
    <table style="margin-top:12px">
        <thead><tr><th colspan="2">Tax computation (annual)</th></tr></thead>
        <tbody>
            <tr><td>Assessable income</td><td class="num"><?= $fmt((float) $line['assessable_annual']) ?></td></tr>
            <tr><td>Less: eligible retirement deduction</td><td class="num">(<?= $fmt((float) $line['retirement_deduction_annual']) ?>)</td></tr>
            <tr class="total"><td>Taxable income</td><td class="num"><?= $fmt((float) $line['taxable_annual']) ?></td></tr>
            <?php foreach ((array) ($trace['slabs'] ?? []) as $slab): ?>
                <tr><td>Slab <?= e((string) $slab['band']) ?> @ <?= e((string) $slab['rate']) ?>%</td><td class="num"><?= $fmt((float) $slab['tax']) ?></td></tr>
            <?php endforeach; ?>
            <tr class="total"><td>Annual tax</td><td class="num"><?= $fmt((float) $line['annual_tax']) ?></td></tr>
            <tr><td>Tax withheld earlier this fiscal year</td><td class="num">(<?= $fmt((float) $line['tax_ytd_before']) ?>)</td></tr>
            <tr class="total"><td>Tax this month</td><td class="num"><?= $fmt((float) $line['tax_month']) ?></td></tr>
            <tr><td style="padding-left:20px">of which Social Security Tax (1% slab)</td><td class="num"><?= $fmt($sstMonth) ?></td></tr>
            <tr><td style="padding-left:20px">of which Remuneration Tax</td><td class="num"><?= $fmt($remunerationMonth) ?></td></tr>
        </tbody>
    </table>
    <?php $projection = (array) ($trace['projection'] ?? []); ?>
    <?php if ($projection !== []): ?>
        <table style="margin-top:12px">
            <thead><tr><th colspan="2">Projected tax summary (estimated figures)</th></tr></thead>
            <tbody>
                <tr><td>Taxable earnings this period (actual)</td><td class="num"><?= $fmt((float) ($projection['current_regular'] ?? 0) + (float) ($projection['current_irregular'] ?? 0)) ?></td></tr>
                <tr><td>Estimated annual taxable income <em>(projected)</em></td><td class="num"><?= $fmt((float) ($projection['estimated_annual_taxable'] ?? 0)) ?></td></tr>
                <tr><td>Estimated annual tax <em>(projected)</em></td><td class="num"><?= $fmt((float) $line['annual_tax']) ?></td></tr>
                <tr><td>Tax deducted in earlier periods</td><td class="num"><?= $fmt((float) $line['tax_ytd_before']) ?></td></tr>
                <tr><td>Tax this period</td><td class="num"><?= $fmt((float) $line['tax_month']) ?></td></tr>
                <tr><td>Estimated tax remaining after this period <em>(projected)</em></td><td class="num"><?= $fmt(max(0.0, (float) ($projection['remaining_tax'] ?? 0) - (float) $line['tax_month'])) ?></td></tr>
                <?php if ((float) ($projection['excess_tax'] ?? 0) > 0): ?>
                    <tr><td><strong>Excess tax already deducted</strong> (treatment: <?= e(str_replace('_', ' ', (string) ($projection['excess_treatment'] ?? 'offset'))) ?>)</td><td class="num"><strong><?= $fmt((float) $projection['excess_tax']) ?></strong></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p class="ps-note">Estimated figures use actual income earned to date plus predictable regular income for the remaining
            employment months only. Overtime, service charge, and one-time payments count only when actually earned.</p>
    <?php endif; ?>
    <?php if ($employerContributions !== [] || (float) $line['retirement_employer_month'] > 0): ?>
        <table style="margin-top:12px">
            <thead><tr><th colspan="2">Employer contributions (employer cost — never deducted from take-home pay)</th></tr></thead>
            <tbody>
                <?php if ((float) $line['retirement_employer_month'] > 0): ?>
                    <tr><td>Retirement contribution (employer)</td><td class="num"><?= $fmt((float) $line['retirement_employer_month']) ?></td></tr>
                <?php endif; ?>
                <?php foreach ($employerContributions as $employerContribution): ?>
                    <tr><td><?= e($employerContribution[0]) ?></td><td class="num"><?= $fmt($employerContribution[1]) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
    $taxableRemuneration = round((float) $line['assessable_annual'] / 12, 2);
    ?>
    <p class="ps-note">
        Taxable remuneration this month: <?= e($sym) ?><?= $fmt($taxableRemuneration) ?> (assessable annual ÷ 12).
        Run status: <?= e(ucfirst((string) $line['run_status'])) ?>.
        Calculated under rule version: <?= e((string) ($trace['tax_version_label'] ?? '-')) ?>. This is a system-generated payslip.
    </p>
</div>
<button class="print-button" onclick="window.print()">Print / Save as PDF</button>
</body>
</html>
