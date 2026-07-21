<?php
declare(strict_types=1);

/**
 * Service charge allocation (shared service).
 *
 * The employer DECLARES the total service charge for a payroll period — the
 * system never derives it from sales. From the declared total the employee
 * pool (68% by default, configurable) is distributed to eligible employees by
 * equal shares, days worked, or manual entry; the employer share (32%) is
 * stored for reporting only and never touches employee earnings or Salary
 * Payable. The allocation becomes a TAXABLE run component per employee.
 */

function payroll_sc_get(int $runId): ?array
{
    if (!table_exists('payroll_service_charge_runs')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM payroll_service_charge_runs WHERE run_id = :run LIMIT 1');
    $stmt->execute(['run' => $runId]);
    return $stmt->fetch() ?: null;
}

function payroll_sc_allocations(int $scRunId): array
{
    $stmt = db()->prepare("SELECT a.*, pe.employee_code,
            COALESCE(u.name, pe.full_name, CONCAT('Employee ', pe.employee_code)) AS person_name
        FROM payroll_service_charge_allocations a
        INNER JOIN payroll_employees pe ON pe.id = a.payroll_employee_id
        LEFT JOIN users u ON u.id = pe.user_id
        WHERE a.sc_run_id = :id ORDER BY pe.employee_code ASC");
    $stmt->execute(['id' => $scRunId]);
    return $stmt->fetchAll();
}

/** Attendance days worked per employee inside the run period (days source). */
function payroll_sc_eligible_days(array $run, array $employeeIds): array
{
    [$periodStart, $periodEnd] = payroll_period_range((int) $run['fiscal_year_id'], (int) $run['period_no']);
    $days = array_fill_keys($employeeIds, 0.0);
    if ($periodStart === '' || $employeeIds === []) {
        return $days;
    }
    $stmt = db()->prepare('SELECT pe.id AS employee_id, COUNT(a.id) AS worked
        FROM payroll_employees pe
        INNER JOIN attendance a ON a.staff_user_id = pe.user_id AND a.company_id = pe.company_id
        WHERE pe.id IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ')
          AND a.attendance_date BETWEEN ? AND ? AND a.check_in_time IS NOT NULL
        GROUP BY pe.id');
    $stmt->execute(array_merge($employeeIds, [$periodStart, $periodEnd]));
    foreach ($stmt->fetchAll() as $row) {
        $days[(int) $row['employee_id']] = (float) $row['worked'];
    }
    return $days;
}

/**
 * Prepare (or re-prepare) the service-charge allocation for a run: split the
 * declared total into the employee pool and employer share, allocate the pool
 * to the selected eligible employees, and store it all as a DRAFT. The last
 * eligible employee absorbs any rounding difference so the allocated total
 * always equals the pool exactly.
 */
function payroll_sc_prepare(array $run, float $declaredTotal, string $method, array $eligibleEmployeeIds, array $manualAmounts, int $userId, string $notes = ''): array
{
    $runId = (int) ($run['id'] ?? 0);
    if ($runId <= 0 || !in_array((string) $run['status'], ['draft', 'calculated'], true)) {
        return ['ok' => false, 'error' => 'Service charge can be prepared only while the payroll run is draft or calculated.'];
    }
    $declaredTotal = round($declaredTotal, 2);
    if ($declaredTotal <= 0) {
        return ['ok' => false, 'error' => 'Enter the declared total service charge for the period.'];
    }
    if (!in_array($method, ['equal', 'days_worked', 'manual'], true)) {
        return ['ok' => false, 'error' => 'Pick a valid allocation method.'];
    }
    $companyId = (int) $run['company_id'];
    $settings = payroll_settings($companyId);
    $employeePct = round((float) ($settings['sc_employee_pct'] ?? 68), 2);
    $employerPct = round((float) ($settings['sc_employer_pct'] ?? 32), 2);
    $pool = round($declaredTotal * $employeePct / 100, 2);
    $employerShare = round($declaredTotal - $pool, 2);

    // Only active, service-charge-eligible employees of THIS company may share
    // the pool; a scoped run further restricts to its own roster.
    $eligibleEmployeeIds = array_values(array_unique(array_filter(array_map('intval', $eligibleEmployeeIds), static fn (int $i): bool => $i > 0)));
    if ($eligibleEmployeeIds === []) {
        return ['ok' => false, 'error' => 'Select at least one eligible employee.'];
    }
    $check = db()->prepare('SELECT id FROM payroll_employees
        WHERE company_id = ? AND status = \'active\' AND sc_eligible = 1
          AND id IN (' . implode(',', array_fill(0, count($eligibleEmployeeIds), '?')) . ')');
    $check->execute(array_merge([$companyId], $eligibleEmployeeIds));
    $validIds = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN));
    if (count($validIds) !== count($eligibleEmployeeIds)) {
        return ['ok' => false, 'error' => 'Some selected employees are not active service-charge-eligible employees of this company.'];
    }
    $scopeIds = payroll_run_scope_ids($run);
    if ($scopeIds !== [] && array_diff($validIds, $scopeIds) !== []) {
        return ['ok' => false, 'error' => 'Some selected employees are not part of this (scoped) payroll run.'];
    }

    // Build the allocation.
    $allocations = [];
    $daysMap = [];
    if ($method === 'equal') {
        $n = count($validIds);
        $base = floor($pool / $n * 100) / 100;
        $running = 0.0;
        foreach ($validIds as $index => $employeeId) {
            $amount = $index === $n - 1 ? round($pool - $running, 2) : $base;
            $running = round($running + $amount, 2);
            $allocations[$employeeId] = $amount;
        }
    } elseif ($method === 'days_worked') {
        $daysMap = payroll_sc_eligible_days($run, $validIds);
        $totalDays = array_sum($daysMap);
        if ($totalDays <= 0) {
            return ['ok' => false, 'error' => 'No attendance days found in the period for the selected employees — record attendance or use another method.'];
        }
        $running = 0.0;
        $lastId = end($validIds);
        foreach ($validIds as $employeeId) {
            $amount = $employeeId === $lastId
                ? round($pool - $running, 2)
                : round($pool * ($daysMap[$employeeId] ?? 0) / $totalDays, 2);
            $running = round($running + $amount, 2);
            $allocations[$employeeId] = $amount;
        }
    } else { // manual
        $running = 0.0;
        foreach ($validIds as $employeeId) {
            $amount = round((float) ($manualAmounts[$employeeId] ?? 0), 2);
            if ($amount < 0) {
                return ['ok' => false, 'error' => 'Manual allocations cannot be negative.'];
            }
            $allocations[$employeeId] = $amount;
            $running = round($running + $amount, 2);
        }
        if (abs($running - $pool) > 0.004) {
            return ['ok' => false, 'error' => 'Manual allocations total ' . number_format($running, 2) . ' but must equal the employee pool ' . number_format($pool, 2) . ' (' . number_format($employeePct, 2) . '% of the declared total).'];
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $existing = payroll_sc_get($runId);
        if ($existing && (string) $existing['status'] === 'approved') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'The allocation is already approved. Remove it first to re-prepare.'];
        }
        if ($existing) {
            $pdo->prepare('DELETE FROM payroll_service_charge_runs WHERE id = :id')->execute(['id' => (int) $existing['id']]);
        }
        $pdo->prepare('INSERT INTO payroll_service_charge_runs
                (company_id, run_id, declared_total, employee_pct, employer_pct, employee_pool, employer_share,
                 allocation_method, component_id, status, notes, created_by, updated_by)
            VALUES (:cid, :run, :declared, :epct, :rpct, :pool, :share, :method, :component, \'draft\', :notes, :by, :by2)')
            ->execute([
                'cid' => $companyId, 'run' => $runId, 'declared' => $declaredTotal,
                'epct' => $employeePct, 'rpct' => $employerPct, 'pool' => $pool, 'share' => $employerShare,
                'method' => $method, 'component' => (int) ($settings['sc_component_id'] ?? 0) ?: null,
                'notes' => $notes !== '' ? $notes : null, 'by' => $userId, 'by2' => $userId,
            ]);
        $scRunId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO payroll_service_charge_allocations (sc_run_id, payroll_employee_id, eligible_days, amount)
            VALUES (:sc, :pe, :days, :amount)');
        foreach ($allocations as $employeeId => $amount) {
            $insert->execute([
                'sc' => $scRunId, 'pe' => $employeeId,
                'days' => $method === 'days_worked' ? ($daysMap[$employeeId] ?? 0) : null,
                'amount' => $amount,
            ]);
        }
        $pdo->commit();
        log_activity('payroll_run', $runId, 'service_charge_prepared',
            'Service charge declared ' . number_format($declaredTotal, 2) . ' — pool ' . number_format($pool, 2)
            . ' (' . number_format($employeePct, 2) . '%) over ' . count($allocations) . ' employees (' . $method . ').', $userId);
        return ['ok' => true, 'sc_run_id' => $scRunId, 'pool' => $pool, 'employer_share' => $employerShare];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $exception->getMessage()];
    }
}

/**
 * Approve the draft allocation: verify it still equals the pool, then write a
 * TAXABLE service-charge run component per employee and recalculate the run.
 */
function payroll_sc_approve(int $runId, int $userId): array
{
    $run = payroll_run($runId);
    if (!$run || !in_array((string) $run['status'], ['draft', 'calculated'], true)) {
        return ['ok' => false, 'error' => 'Service charge can be approved only while the payroll run is editable.'];
    }
    $sc = payroll_sc_get($runId);
    if (!$sc) {
        return ['ok' => false, 'error' => 'Prepare the allocation first.'];
    }
    if ((string) $sc['status'] === 'approved') {
        return ['ok' => false, 'error' => 'This allocation is already approved.'];
    }
    $settings = payroll_settings((int) $run['company_id']);
    $componentId = (int) ($settings['sc_component_id'] ?? 0);
    if ($componentId <= 0) {
        return ['ok' => false, 'error' => 'Map the Service Charge pay component in Payroll Settings first.'];
    }
    $compStmt = db()->prepare('SELECT * FROM payroll_components WHERE id = :id AND company_id = :cid AND active = 1 LIMIT 1');
    $compStmt->execute(['id' => $componentId, 'cid' => (int) $run['company_id']]);
    $component = $compStmt->fetch();
    if (!$component) {
        return ['ok' => false, 'error' => 'The mapped Service Charge component is missing or inactive.'];
    }
    $allocations = payroll_sc_allocations((int) $sc['id']);
    $total = round(array_sum(array_map(static fn (array $a): float => (float) $a['amount'], $allocations)), 2);
    if (abs($total - (float) $sc['employee_pool']) > 0.004) {
        return ['ok' => false, 'error' => 'Allocations total ' . number_format($total, 2) . ' but the employee pool is ' . number_format((float) $sc['employee_pool'], 2) . '. Re-prepare the allocation.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM payroll_run_components WHERE run_id = :run AND source = 'service_charge'")->execute(['run' => $runId]);
        foreach ($allocations as $allocation) {
            $amount = round((float) $allocation['amount'], 2);
            if ($amount <= 0) {
                continue;
            }
            $snapshot = payroll_component_snapshot_row($component, [
                'suggested_amount' => $amount,
                'amount' => $amount,
                'taxable' => true, // the employee service-charge allocation is always taxable remuneration
                'override_reason' => null,
                'source' => 'service_charge',
            ]);
            $pdo->prepare('INSERT INTO payroll_run_components (
                    run_id, payroll_employee_id, component_id, component_code, component_name, category,
                    posting_behaviour, taxable, include_in_gross, include_in_net, calc_method,
                    debit_ledger_id, credit_ledger_id, employer_expense_ledger_id, contribution_payable_ledger_id,
                    suggested_amount, amount, override_reason, source
                ) VALUES (:run_id, :pe, :component_id, :code, :name, :category, :behaviour, 1, :in_gross,
                    :in_net, :calc_method, :dr, :cr, :er_exp, :er_pay, :suggested, :amount, :reason, :source)
                ON DUPLICATE KEY UPDATE amount = VALUES(amount), suggested_amount = VALUES(suggested_amount), source = VALUES(source), taxable = 1')
                ->execute([
                    'run_id' => $runId, 'pe' => (int) $allocation['payroll_employee_id'],
                    'component_id' => (int) $component['id'], 'code' => $snapshot['component_code'],
                    'name' => $snapshot['component_name'], 'category' => $snapshot['category'],
                    'behaviour' => $snapshot['posting_behaviour'],
                    'in_gross' => $snapshot['include_in_gross'], 'in_net' => $snapshot['include_in_net'],
                    'calc_method' => 'service_charge',
                    'dr' => $snapshot['debit_ledger_id'], 'cr' => $snapshot['credit_ledger_id'],
                    'er_exp' => $snapshot['employer_expense_ledger_id'], 'er_pay' => $snapshot['contribution_payable_ledger_id'],
                    'suggested' => $amount, 'amount' => $amount,
                    'reason' => 'Service charge allocation (' . (string) $sc['allocation_method'] . ')',
                    'source' => 'service_charge',
                ]);
        }
        $pdo->prepare("UPDATE payroll_service_charge_runs SET status = 'approved', component_id = :component,
                approved_by = :by, approved_at = NOW(), updated_by = :by2 WHERE id = :id")
            ->execute(['component' => (int) $component['id'], 'by' => $userId, 'by2' => $userId, 'id' => (int) $sc['id']]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $exception->getMessage()];
    }

    $calc = payroll_calculate_run($runId);
    if (!$calc['ok']) {
        return ['ok' => false, 'error' => 'Allocation stored but the run did not recalculate: ' . (string) ($calc['error'] ?? '')];
    }
    log_activity('payroll_run', $runId, 'service_charge_approved',
        'Service charge allocation approved: pool ' . number_format((float) $sc['employee_pool'], 2)
        . ' to ' . count($allocations) . ' employees; employer share ' . number_format((float) $sc['employer_share'], 2) . ' (reporting only).', $userId);
    return ['ok' => true];
}

/** Remove a draft/approved allocation while the run is still editable. */
function payroll_sc_remove(int $runId, int $userId): array
{
    $run = payroll_run($runId);
    if (!$run || !in_array((string) $run['status'], ['draft', 'calculated'], true)) {
        return ['ok' => false, 'error' => 'The allocation can be removed only while the payroll run is editable.'];
    }
    $sc = payroll_sc_get($runId);
    if (!$sc) {
        return ['ok' => false, 'error' => 'No allocation exists for this run.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM payroll_service_charge_runs WHERE id = :id')->execute(['id' => (int) $sc['id']]);
        $pdo->prepare("DELETE FROM payroll_run_components WHERE run_id = :run AND source = 'service_charge'")->execute(['run' => $runId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $exception->getMessage()];
    }
    $calc = payroll_calculate_run($runId);
    log_activity('payroll_run', $runId, 'service_charge_removed', 'Service charge allocation removed.', $userId);
    return $calc['ok'] ? ['ok' => true] : ['ok' => false, 'error' => (string) ($calc['error'] ?? '')];
}
