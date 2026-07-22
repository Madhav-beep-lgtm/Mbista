<?php
declare(strict_types=1);

/**
 * Authoritative projected-annual-tax service (calculation version 2).
 *
 * Estimated annual taxable income =
 *     actual taxable income earned up to (and including) the current payroll
 *   + predictable REGULAR income for the remaining employment periods
 *   + approved prior-employment income and opening adjustments
 *   + explicitly approved manual projections.
 *
 * Irregular income (overtime, service charge, bonuses, one-time payments)
 * counts only when actually earned — it is never multiplied into future
 * months. Projection starts at the later of the fiscal-year start and the
 * employment start, and stops at the earliest of the fiscal-year end, the
 * termination date and the known contract end. The remaining-period divisor
 * includes the current period, so the final employment month settles the
 * whole remaining balance. Used ONLY by payroll_calculate_line — pages,
 * payslips and reports read the stored snapshots, never re-derive.
 */

/**
 * Projection treatment of a component/run-component row:
 * 'regular' | 'actual_only' | 'guaranteed' | 'manual' | 'excluded'.
 * Explicit master classification wins; otherwise overtime / service-charge /
 * manual-entry components are actual-only and fixed/percent suggestions are
 * regular. Non-taxable rows are excluded from tax entirely (by taxable flag).
 */
function payroll_tax_treatment(array $row): string
{
    $method = trim((string) ($row['tax_projection_method'] ?? ''));
    if (in_array($method, ['regular', 'actual_only', 'guaranteed', 'manual', 'excluded'], true)) {
        return $method;
    }
    $calc = (string) ($row['calc_method'] ?? $row['calc_type'] ?? 'manual');
    if (in_array($calc, ['overtime_hours', 'service_charge', 'manual'], true)) {
        return 'actual_only';
    }
    return 'regular';
}

/** The payroll period (1..12) a date falls in; 0 = before FY, 13 = after. */
function payroll_period_of_date(int $fiscalYearId, string $date): int
{
    for ($p = 1; $p <= 12; $p++) {
        [$start, $end] = payroll_period_range($fiscalYearId, $p);
        if ($start === '') {
            return 1;
        }
        if ($date < $start) {
            return $p === 1 ? 0 : $p; // between periods cannot happen (contiguous)
        }
        if ($date <= $end) {
            return $p;
        }
    }
    return 13;
}

/**
 * The employee's payroll-period span inside the run's fiscal year:
 * [startPeriod, endPeriod, startDateUsed, endDateUsed]. Never projects before
 * joining or beyond termination / contract end / fiscal-year end.
 */
function payroll_employment_span(array $employee, array $run): array
{
    $fyId = (int) $run['fiscal_year_id'];
    [$fyStart] = payroll_period_range($fyId, 1);
    [, $fyEnd] = payroll_period_range($fyId, 12);

    $joined = trim((string) ($employee['joined_on'] ?? ''));
    $startPeriod = 1;
    $startUsed = $fyStart;
    if ($joined !== '' && $joined > $fyStart) {
        $startPeriod = max(1, min(12, payroll_period_of_date($fyId, $joined)));
        $startUsed = $joined;
    }

    $endCandidates = array_filter([
        trim((string) ($employee['terminated_on'] ?? '')),
        trim((string) ($employee['contract_end_date'] ?? '')),
    ], static fn (string $d): bool => $d !== '');
    $endPeriod = 12;
    $endUsed = $fyEnd;
    if ($endCandidates !== []) {
        $earliest = min($endCandidates);
        if ($earliest !== '' && $earliest < $fyEnd) {
            $endPeriod = max(1, min(12, payroll_period_of_date($fyId, $earliest)));
            $endUsed = $earliest;
        }
    }
    if ($endPeriod < $startPeriod) {
        $endPeriod = $startPeriod;
    }
    return [$startPeriod, $endPeriod, $startUsed, $endUsed];
}

/**
 * The basic salary in force for a FUTURE period: the latest approved salary
 * revision effective on/before that period's start, else the current basic.
 */
function payroll_basic_for_period(array $employee, int $fiscalYearId, int $periodNo): float
{
    $basic = round((float) ($employee['basic_salary'] ?? 0), 2);
    if (!table_exists('payroll_salary_revisions')) {
        return $basic;
    }
    [$periodStart] = payroll_period_range($fiscalYearId, $periodNo);
    if ($periodStart === '') {
        return $basic;
    }
    $stmt = db()->prepare('SELECT effective_from, basic_salary FROM payroll_salary_revisions
        WHERE payroll_employee_id = :pe AND effective_from <= :ps ORDER BY effective_from DESC LIMIT 1');
    $stmt->execute(['pe' => (int) ($employee['id'] ?? 0), 'ps' => $periodStart]);
    $revision = $stmt->fetch();
    return $revision ? round((float) $revision['basic_salary'], 2) : $basic;
}

/** Approved tax profile (prior employment, opening adjustments) or null. */
function payroll_tax_profile(int $employeeId, int $fiscalYearId): ?array
{
    if (!table_exists('payroll_employee_tax_profiles')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM payroll_employee_tax_profiles
        WHERE payroll_employee_id = :pe AND fiscal_year_id = :fy AND approved_by IS NOT NULL LIMIT 1');
    $stmt->execute(['pe' => $employeeId, 'fy' => $fiscalYearId]);
    return $stmt->fetch() ?: null;
}

/**
 * Actual assessable income already EARNED in earlier periods of this fiscal
 * year (approved/posted/paid runs before the current period, excluding this
 * run). Returns [regular, irregular, retirement_employee, retirement_employer].
 * Legacy lines calculated before v2 fall back to assessable_annual / 12 as
 * regular income — the best available actual figure for those months.
 */
function payroll_actuals_to_date(int $employeeId, int $fiscalYearId, int $excludeRunId, int $beforePeriod): array
{
    $stmt = db()->prepare("SELECT l.assessable_month, l.regular_month, l.irregular_month, l.assessable_annual,
            l.gross, l.retirement_employee_month, l.retirement_employer_month
        FROM payroll_run_lines l INNER JOIN payroll_runs r ON r.id = l.run_id
        WHERE l.payroll_employee_id = :pe AND r.fiscal_year_id = :fy
          AND r.status IN ('approved', 'posted', 'paid') AND r.id <> :run AND r.period_no < :period");
    $stmt->execute(['pe' => $employeeId, 'fy' => $fiscalYearId, 'run' => $excludeRunId, 'period' => $beforePeriod]);
    $regular = 0.0;
    $irregular = 0.0;
    $retEmp = 0.0;
    $retEr = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $lineAssessable = round((float) $line['assessable_month'], 2);
        if ($lineAssessable == 0.0 && (float) $line['gross'] > 0) {
            // Legacy (v1) line: approximate its month from the stored annual.
            $regular += round((float) $line['assessable_annual'] / 12, 2);
        } else {
            $regular += round((float) $line['regular_month'], 2);
            $irregular += round((float) $line['irregular_month'], 2);
        }
        $retEmp += (float) $line['retirement_employee_month'];
        $retEr += (float) $line['retirement_employer_month'];
    }
    return [round($regular, 2), round($irregular, 2), round($retEmp, 2), round($retEr, 2)];
}

/**
 * Predictable REGULAR income for periods AFTER the current one, through the
 * employee's last projected period. Period-by-period: each future period uses
 * the basic salary effective THEN (approved revisions), the regular taxable
 * components effective THEN (component + assignment effective dates), and the
 * retirement contributions on that period's basic. Never includes actual-only
 * components. Returns
 * [assessableTotal, retEmployeeFuture, retEmployerFuture, perPeriod[]].
 */
function payroll_projected_future_regular(array $employee, array $run, int $currentPeriod, int $endPeriod): array
{
    $fyId = (int) $run['fiscal_year_id'];
    $retEmpRate = (float) ($employee['retirement_employee_rate'] ?? 0);
    $retErRate = (float) ($employee['retirement_employer_rate'] ?? 0);
    $total = 0.0;
    $retEmpFuture = 0.0;
    $retErFuture = 0.0;
    $perPeriod = [];
    for ($p = $currentPeriod + 1; $p <= $endPeriod; $p++) {
        [, $periodEnd] = payroll_period_range($fyId, $p);
        $basicP = payroll_basic_for_period($employee, $fyId, $p);
        $projected = $basicP; // basic salary is always regular and assessable
        $componentDetail = [];
        $employeeAtP = $employee;
        $employeeAtP['basic_salary'] = $basicP; // percent-of-basic follows the future basic
        foreach (payroll_employee_component_lines($employeeAtP, $periodEnd) as $line) {
            if (!$line['taxable']) {
                continue;
            }
            $treatment = payroll_tax_treatment($line['component']);
            if (!in_array($treatment, ['regular', 'guaranteed'], true)) {
                continue; // actual_only / manual / excluded: never auto-projected
            }
            $behaviour = payroll_component_behaviour($line['component']);
            if (in_array($behaviour, ['deduction_liability', 'advance_recovery', 'employer_contribution', 'non_posting'], true)) {
                continue; // deductions and employer costs are not employee income
            }
            $projected = round($projected + (float) $line['amount'], 2);
            $componentDetail[(string) $line['component']['code']] = (float) $line['amount'];
        }
        $retEmpP = round($basicP * $retEmpRate / 100, 2);
        $retErP = round($basicP * $retErRate / 100, 2);
        $projected = round($projected + $retErP, 2); // employer retirement is assessable
        $total = round($total + $projected, 2);
        $retEmpFuture = round($retEmpFuture + $retEmpP, 2);
        $retErFuture = round($retErFuture + $retErP, 2);
        $perPeriod[] = ['period' => $p, 'basic' => $basicP, 'assessable' => $projected] + ($componentDetail !== [] ? ['components' => $componentDetail] : []);
    }
    return [$total, $retEmpFuture, $retErFuture, $perPeriod];
}

/**
 * Approved MANUAL projections still (partly) in the future. Amounts are lump
 * sums for their period range; once the money is actually paid through
 * payroll the projection should be ended by the administrator.
 */
function payroll_manual_projected_income(int $employeeId, int $fiscalYearId, int $currentPeriod, int $endPeriod): array
{
    if (!table_exists('payroll_manual_projections')) {
        return [0.0, []];
    }
    $stmt = db()->prepare('SELECT * FROM payroll_manual_projections
        WHERE payroll_employee_id = :pe AND fiscal_year_id = :fy AND approved_by IS NOT NULL
          AND period_to > :current AND period_from <= :end');
    $stmt->execute(['pe' => $employeeId, 'fy' => $fiscalYearId, 'current' => $currentPeriod, 'end' => $endPeriod]);
    $total = 0.0;
    $detail = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $total = round($total + (float) $row['amount'], 2);
        $detail[] = ['label' => (string) $row['label'], 'amount' => (float) $row['amount'],
            'periods' => (int) $row['period_from'] . '-' . (int) $row['period_to']];
    }
    return [$total, $detail];
}

/** Persist the immutable per-line tax calculation snapshot. */
function payroll_store_tax_snapshot(array $run, array $employee, array $calc, int $userId = 0): void
{
    if (!table_exists('payroll_tax_calculations')) {
        return;
    }
    $projection = (array) ($calc['trace']['projection'] ?? []);
    db()->prepare('INSERT INTO payroll_tax_calculations (
            company_id, run_id, payroll_employee_id, fiscal_year_id, period_no,
            start_period, end_period, remaining_periods, employment_start_used, employment_end_used,
            actual_regular_to_date, actual_irregular_to_date, current_regular, current_irregular,
            projected_regular_income, manual_projected_income, prior_employment_income,
            estimated_annual_taxable_income, retirement_deduction, taxable_annual, estimated_annual_tax,
            tax_withheld_before_period, prior_employer_tax, remaining_tax,
            system_tax, tax_override, current_tax, excess_tax, calculation_version, snapshot_json
        ) VALUES (
            :cid, :run, :pe, :fy, :period,
            :sp, :ep, :rp, :esu, :eeu,
            :areg, :airr, :creg, :cirr,
            :proj, :manual, :prior_inc,
            :est, :ret_ded, :taxable, :annual_tax,
            :ytd, :prior_tax, :remaining,
            :sys, :ovr, :cur, :excess, 2, :snapshot
        ) ON DUPLICATE KEY UPDATE
            period_no = VALUES(period_no), start_period = VALUES(start_period), end_period = VALUES(end_period),
            remaining_periods = VALUES(remaining_periods), employment_start_used = VALUES(employment_start_used),
            employment_end_used = VALUES(employment_end_used),
            actual_regular_to_date = VALUES(actual_regular_to_date), actual_irregular_to_date = VALUES(actual_irregular_to_date),
            current_regular = VALUES(current_regular), current_irregular = VALUES(current_irregular),
            projected_regular_income = VALUES(projected_regular_income), manual_projected_income = VALUES(manual_projected_income),
            prior_employment_income = VALUES(prior_employment_income),
            estimated_annual_taxable_income = VALUES(estimated_annual_taxable_income),
            retirement_deduction = VALUES(retirement_deduction), taxable_annual = VALUES(taxable_annual),
            estimated_annual_tax = VALUES(estimated_annual_tax), tax_withheld_before_period = VALUES(tax_withheld_before_period),
            prior_employer_tax = VALUES(prior_employer_tax), remaining_tax = VALUES(remaining_tax),
            system_tax = VALUES(system_tax), tax_override = VALUES(tax_override), current_tax = VALUES(current_tax),
            excess_tax = VALUES(excess_tax), calculation_version = 2, snapshot_json = VALUES(snapshot_json)')
        ->execute([
            'cid' => (int) $run['company_id'], 'run' => (int) $run['id'], 'pe' => (int) $employee['id'],
            'fy' => (int) $run['fiscal_year_id'], 'period' => (int) $run['period_no'],
            'sp' => (int) ($projection['start_period'] ?? 1), 'ep' => (int) ($projection['end_period'] ?? 12),
            'rp' => (int) ($projection['remaining_periods'] ?? 1),
            'esu' => ($projection['employment_start_used'] ?? null) ?: null,
            'eeu' => ($projection['employment_end_used'] ?? null) ?: null,
            'areg' => (float) ($projection['actual_regular_to_date'] ?? 0),
            'airr' => (float) ($projection['actual_irregular_to_date'] ?? 0),
            'creg' => (float) ($calc['regular_month'] ?? 0),
            'cirr' => (float) ($calc['irregular_month'] ?? 0),
            'proj' => (float) ($projection['projected_future_regular'] ?? 0),
            'manual' => (float) ($projection['manual_projected'] ?? 0),
            'prior_inc' => (float) ($projection['prior_employment_income'] ?? 0),
            'est' => (float) ($calc['assessable_annual'] ?? 0),
            'ret_ded' => (float) ($calc['retirement_deduction_annual'] ?? 0),
            'taxable' => (float) ($calc['taxable_annual'] ?? 0),
            'annual_tax' => (float) ($calc['annual_tax'] ?? 0),
            'ytd' => (float) ($calc['tax_ytd_before'] ?? 0),
            'prior_tax' => (float) ($projection['prior_employer_tax'] ?? 0),
            'remaining' => (float) ($projection['remaining_tax'] ?? 0),
            'sys' => (float) ($calc['system_tax'] ?? $calc['tax_month'] ?? 0),
            'ovr' => $calc['tax_override'] !== null && $calc['tax_override'] !== '' ? (float) $calc['tax_override'] : null,
            'cur' => (float) ($calc['tax_month'] ?? 0),
            'excess' => (float) ($calc['excess_tax'] ?? 0),
            'snapshot' => json_encode($calc['trace'], JSON_UNESCAPED_UNICODE),
        ]);
}

/**
 * Approver-only override of the CURRENT period tax on one line. The system
 * amount stays stored beside it and the change is audited; recalculation
 * preserves the override until it is cleared (null amount).
 */
function payroll_set_tax_override(int $runId, int $employeeId, ?float $amount, string $reason, int $userId): array
{
    $run = payroll_run($runId);
    if (!$run || !in_array((string) $run['status'], ['draft', 'calculated'], true)) {
        return ['ok' => false, 'error' => 'Tax can be overridden only while the run is draft or calculated.'];
    }
    if ($amount !== null && $amount < 0) {
        return ['ok' => false, 'error' => 'The approved tax cannot be negative.'];
    }
    if ($amount !== null && trim($reason) === '') {
        return ['ok' => false, 'error' => 'A reason is required for a tax override — it is kept in the audit trail.'];
    }
    $lineStmt = db()->prepare('SELECT id, tax_month FROM payroll_run_lines WHERE run_id = :run AND payroll_employee_id = :pe LIMIT 1');
    $lineStmt->execute(['run' => $runId, 'pe' => $employeeId]);
    $line = $lineStmt->fetch();
    if (!$line) {
        return ['ok' => false, 'error' => 'No calculated line for this employee on the run.'];
    }
    db()->prepare('UPDATE payroll_run_lines SET tax_override = :ovr, tax_override_reason = :reason, tax_override_by = :by WHERE id = :id')
        ->execute([
            'ovr' => $amount, 'reason' => $amount !== null ? trim($reason) : null,
            'by' => $amount !== null ? $userId : null, 'id' => (int) $line['id'],
        ]);
    log_activity('payroll_run', $runId, 'tax_override', $amount !== null
        ? 'Tax for employee #' . $employeeId . ' overridden to ' . number_format($amount, 2) . ' (system ' . number_format((float) $line['tax_month'], 2) . '): ' . trim($reason)
        : 'Tax override cleared for employee #' . $employeeId . '.', $userId);
    return payroll_recalculate_employee_line($runId, $employeeId);
}
