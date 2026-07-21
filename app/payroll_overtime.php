<?php
declare(strict_types=1);

/**
 * Weekly overtime engine (shared service — used by admin, staff and client
 * payroll surfaces; no page duplicates this logic).
 *
 * Business rule: overtime exists only after CUMULATIVE worked hours in a
 * Sunday-to-Saturday week exceed the configured threshold (default 40). A long
 * single day is NOT overtime by itself. Attendance is processed
 * chronologically; the day that crosses the threshold is split into regular
 * hours (up to the threshold) and overtime hours (above it). Weekly totals
 * never reset at a payroll-month boundary: the full week decides whether the
 * threshold was crossed, each overtime hour is dated on the day it was worked,
 * and a payroll run picks up only the overtime dated inside its own period.
 * UNIQUE (employee, ot_date) plus the run_id claim make double payment
 * impossible.
 */

/** The week-start date (default Sunday) for any calendar date. */
function payroll_ot_week_start(string $date, int $weekStartDow = 0): string
{
    $ts = strtotime($date);
    $dow = (int) date('w', $ts); // 0 = Sunday
    $offset = ($dow - $weekStartDow + 7) % 7;
    return date('Y-m-d', strtotime('-' . $offset . ' days', $ts));
}

/** Worked hours per date from attendance check-in/check-out, ordered by date. */
function payroll_ot_attendance_hours(int $companyId, int $staffUserId, string $weekStart, string $weekEnd): array
{
    $stmt = db()->prepare('SELECT attendance_date, check_in_time, check_out_time FROM attendance
        WHERE company_id = :cid AND staff_user_id = :uid
          AND attendance_date BETWEEN :ws AND :we
        ORDER BY attendance_date ASC');
    $stmt->execute(['cid' => $companyId, 'uid' => $staffUserId, 'ws' => $weekStart, 'we' => $weekEnd]);
    $hours = [];
    foreach ($stmt->fetchAll() as $row) {
        if (empty($row['check_in_time']) || empty($row['check_out_time'])) {
            continue; // an open (not checked out) day has no measurable hours yet
        }
        $seconds = strtotime((string) $row['check_out_time']) - strtotime((string) $row['check_in_time']);
        if ($seconds <= 0) {
            continue;
        }
        $hours[(string) $row['attendance_date']] = round($seconds / 3600, 2);
    }
    ksort($hours);
    return $hours;
}

/**
 * The employee's overtime hourly rate: the fixed per-employee rate when set
 * (and the settings allow it), otherwise derived from the basic salary over
 * the configured monthly hours — times the multiplier. Never hard-coded.
 */
function payroll_ot_employee_rate(array $employee, array $settings): float
{
    $multiplier = max(0.0, (float) ($settings['ot_multiplier'] ?? 1.5));
    $fixed = (float) ($employee['ot_hourly_rate'] ?? 0);
    if ($fixed > 0) {
        return round($fixed * $multiplier, 4);
    }
    if ((string) ($settings['ot_rate_source'] ?? 'salary_derived') === 'fixed_rate') {
        return 0.0; // fixed-rate mode without a rate on the employee -> no pay until set
    }
    $monthlyHours = max(1.0, (float) ($settings['ot_monthly_hours'] ?? 208));
    return round(((float) $employee['basic_salary'] / $monthlyHours) * $multiplier, 4);
}

/**
 * Split one week's daily hours into regular and overtime, chronologically.
 * Returns ['days' => [date => ['hours','regular','overtime']], 'total','regular','overtime'].
 */
function payroll_ot_split_week(array $dailyHours, float $threshold): array
{
    ksort($dailyHours);
    $cumulative = 0.0;
    $otSoFar = 0.0;
    $days = [];
    foreach ($dailyHours as $date => $hours) {
        $hours = round((float) $hours, 2);
        $cumulative = round($cumulative + $hours, 2);
        $otTarget = round(max(0.0, $cumulative - $threshold), 2);
        $otToday = round(min($hours, max(0.0, $otTarget - $otSoFar)), 2);
        $otSoFar = round($otSoFar + $otToday, 2);
        $days[$date] = [
            'hours' => $hours,
            'regular' => round($hours - $otToday, 2),
            'overtime' => $otToday,
            'cumulative' => $cumulative,
        ];
    }
    return [
        'days' => $days,
        'total' => $cumulative,
        'overtime' => $otSoFar,
        'regular' => round($cumulative - $otSoFar, 2),
    ];
}

/**
 * Recalculate one employee-week from attendance and store the week summary +
 * per-date overtime entries. Entries already claimed by a payroll run are
 * frozen — a week with ANY consumed day is left untouched (correct it via the
 * run's reopen flow instead). Returns the stored week row or null.
 */
function payroll_ot_recalc_week(array $employee, array $settings, string $weekStart): ?array
{
    $companyId = (int) $employee['company_id'];
    $employeeId = (int) $employee['id'];
    $staffUserId = (int) ($employee['user_id'] ?? 0);
    if ($staffUserId <= 0) {
        return null; // no attendance identity — overtime is entered manually
    }
    $weekStart = payroll_ot_week_start($weekStart, (int) ($settings['ot_week_start'] ?? 0));
    $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

    $consumedStmt = db()->prepare('SELECT COUNT(*) FROM payroll_overtime_entries
        WHERE payroll_employee_id = :pe AND week_start = :ws AND run_id IS NOT NULL');
    $consumedStmt->execute(['pe' => $employeeId, 'ws' => $weekStart]);
    if ((int) $consumedStmt->fetchColumn() > 0) {
        $existing = db()->prepare('SELECT * FROM payroll_overtime_weeks WHERE payroll_employee_id = :pe AND week_start = :ws LIMIT 1');
        $existing->execute(['pe' => $employeeId, 'ws' => $weekStart]);
        return $existing->fetch() ?: null;
    }

    $threshold = max(0.0, (float) ($settings['ot_weekly_threshold'] ?? 40));
    $daily = payroll_ot_attendance_hours($companyId, $staffUserId, $weekStart, $weekEnd);
    $split = payroll_ot_split_week($daily, $threshold);
    $rate = payroll_ot_employee_rate($employee, $settings);
    $multiplier = max(0.0, (float) ($settings['ot_multiplier'] ?? 1.5));

    $pdo = db();
    // Rebuild the (unconsumed) entries for this week.
    $pdo->prepare('DELETE FROM payroll_overtime_entries WHERE payroll_employee_id = :pe AND week_start = :ws AND run_id IS NULL')
        ->execute(['pe' => $employeeId, 'ws' => $weekStart]);

    $calculated = 0.0;
    $insert = $pdo->prepare('INSERT INTO payroll_overtime_entries (company_id, payroll_employee_id, week_start, ot_date, hours, amount)
        VALUES (:cid, :pe, :ws, :dt, :hours, :amount)');
    foreach ($split['days'] as $date => $day) {
        if ($day['overtime'] <= 0) {
            continue;
        }
        $amount = round($day['overtime'] * $rate, 2);
        $calculated = round($calculated + $amount, 2);
        $insert->execute(['cid' => $companyId, 'pe' => $employeeId, 'ws' => $weekStart, 'dt' => $date, 'hours' => $day['overtime'], 'amount' => $amount]);
    }
    $calculated = payroll_round($calculated, (string) ($settings['ot_rounding'] ?? 'none'));

    if ($split['overtime'] <= 0) {
        // Nothing over the threshold: drop any stale summary and report null.
        $pdo->prepare('DELETE FROM payroll_overtime_weeks WHERE payroll_employee_id = :pe AND week_start = :ws')
            ->execute(['pe' => $employeeId, 'ws' => $weekStart]);
        return null;
    }

    $pdo->prepare('INSERT INTO payroll_overtime_weeks (company_id, payroll_employee_id, week_start, week_end,
            total_hours, regular_hours, overtime_hours, hourly_rate, multiplier, calculated_amount, daily_json, status)
        VALUES (:cid, :pe, :ws, :we, :total, :regular, :overtime, :rate, :mult, :amount, :daily, \'calculated\')
        ON DUPLICATE KEY UPDATE week_end = VALUES(week_end), total_hours = VALUES(total_hours),
            regular_hours = VALUES(regular_hours), overtime_hours = VALUES(overtime_hours),
            hourly_rate = VALUES(hourly_rate), multiplier = VALUES(multiplier),
            calculated_amount = VALUES(calculated_amount), daily_json = VALUES(daily_json),
            status = \'calculated\', approved_amount = NULL, adjust_reason = NULL,
            approved_by = NULL, approved_at = NULL')
        ->execute([
            'cid' => $companyId, 'pe' => $employeeId, 'ws' => $weekStart, 'we' => $weekEnd,
            'total' => $split['total'], 'regular' => $split['regular'], 'overtime' => $split['overtime'],
            'rate' => $rate, 'mult' => $multiplier, 'amount' => $calculated,
            'daily' => json_encode($split['days'], JSON_UNESCAPED_UNICODE),
        ]);

    $row = $pdo->prepare('SELECT * FROM payroll_overtime_weeks WHERE payroll_employee_id = :pe AND week_start = :ws LIMIT 1');
    $row->execute(['pe' => $employeeId, 'ws' => $weekStart]);
    return $row->fetch() ?: null;
}

/**
 * Recalculate every week that OVERLAPS [$periodStart, $periodEnd] for all
 * active payroll employees of the company. A week crossing a month boundary is
 * always processed as a whole. Returns the number of weeks with overtime.
 */
function payroll_ot_sync_period(int $companyId, string $periodStart, string $periodEnd): int
{
    $settings = payroll_settings($companyId);
    $count = 0;
    foreach (payroll_company_employees($companyId) as $employee) {
        if ((int) ($employee['user_id'] ?? 0) <= 0) {
            continue;
        }
        $weekStart = payroll_ot_week_start($periodStart, (int) ($settings['ot_week_start'] ?? 0));
        while ($weekStart <= $periodEnd) {
            if (payroll_ot_recalc_week($employee, $settings, $weekStart) !== null) {
                $count++;
            }
            $weekStart = date('Y-m-d', strtotime($weekStart . ' +7 days'));
        }
    }
    return $count;
}

/**
 * Approve one overtime week, optionally adjusting the payable amount. An
 * adjusted amount needs a reason (audited); the per-date entry amounts are
 * rescaled so period allocation still matches, the last day absorbing the
 * rounding remainder. Consumed weeks cannot be re-approved.
 */
function payroll_ot_approve_week(int $weekId, ?float $approvedAmount, string $reason, int $userId): array
{
    $stmt = db()->prepare('SELECT * FROM payroll_overtime_weeks WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $weekId]);
    $week = $stmt->fetch();
    if (!$week) {
        return ['ok' => false, 'error' => 'Overtime week not found.'];
    }
    $consumed = db()->prepare('SELECT COUNT(*) FROM payroll_overtime_entries WHERE payroll_employee_id = :pe AND week_start = :ws AND run_id IS NOT NULL');
    $consumed->execute(['pe' => (int) $week['payroll_employee_id'], 'ws' => (string) $week['week_start']]);
    if ((int) $consumed->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'This week is already included in a payroll run. Reopen that run to change it.'];
    }
    $calculated = round((float) $week['calculated_amount'], 2);
    $final = $approvedAmount !== null ? round($approvedAmount, 2) : $calculated;
    if ($final < 0) {
        return ['ok' => false, 'error' => 'The approved amount cannot be negative.'];
    }
    if (abs($final - $calculated) > 0.004 && trim($reason) === '') {
        return ['ok' => false, 'error' => 'Give a reason when the approved amount differs from the calculated amount.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Rescale per-date amounts to the approved total (last date absorbs
        // the rounding remainder) so cross-month splits stay exact.
        $entriesStmt = $pdo->prepare('SELECT id, amount FROM payroll_overtime_entries WHERE payroll_employee_id = :pe AND week_start = :ws ORDER BY ot_date ASC');
        $entriesStmt->execute(['pe' => (int) $week['payroll_employee_id'], 'ws' => (string) $week['week_start']]);
        $entries = $entriesStmt->fetchAll();
        if ($entries !== [] && $calculated > 0 && abs($final - $calculated) > 0.004) {
            $running = 0.0;
            $lastId = (int) end($entries)['id'];
            foreach ($entries as $entry) {
                $share = (int) $entry['id'] === $lastId
                    ? round($final - $running, 2)
                    : round($final * ((float) $entry['amount'] / $calculated), 2);
                $running = round($running + $share, 2);
                $pdo->prepare('UPDATE payroll_overtime_entries SET amount = :amt WHERE id = :id')
                    ->execute(['amt' => $share, 'id' => (int) $entry['id']]);
            }
        }
        $pdo->prepare("UPDATE payroll_overtime_weeks SET status = 'approved', approved_amount = :amt,
                adjust_reason = :reason, approved_by = :by, approved_at = NOW() WHERE id = :id")
            ->execute(['amt' => $final, 'reason' => trim($reason) !== '' ? trim($reason) : null, 'by' => $userId, 'id' => $weekId]);
        $pdo->commit();
        log_activity('payroll_overtime', $weekId, 'approved', 'Overtime week ' . $week['week_start'] . ' approved at ' . number_format($final, 2)
            . (abs($final - $calculated) > 0.004 ? ' (calculated ' . number_format($calculated, 2) . '): ' . trim($reason) : ''), $userId);
        return ['ok' => true, 'amount' => $final];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $exception->getMessage()];
    }
}

/**
 * Claim this run's overtime and store it as a per-employee run component
 * (source 'overtime'). Only entries dated INSIDE the run period, from approved
 * weeks (or any calculated week when approval is not required), not consumed
 * by another run. Claiming stamps run_id, so a second run can never pay the
 * same hours. Safe to call repeatedly while the run is editable.
 */
function payroll_ot_inject_run(array $run, array $settings): array
{
    $runId = (int) ($run['id'] ?? 0);
    $componentId = (int) ($settings['ot_component_id'] ?? 0);
    if ($runId <= 0 || $componentId <= 0 || !table_exists('payroll_overtime_entries')) {
        return ['injected' => 0];
    }
    $compStmt = db()->prepare('SELECT * FROM payroll_components WHERE id = :id LIMIT 1');
    $compStmt->execute(['id' => $componentId]);
    $component = $compStmt->fetch();
    if (!$component) {
        return ['injected' => 0];
    }
    [$periodStart, $periodEnd] = payroll_period_range((int) $run['fiscal_year_id'], (int) $run['period_no']);
    if ($periodStart === '') {
        return ['injected' => 0];
    }
    $requireApproval = (int) ($settings['ot_require_approval'] ?? 1) === 1;
    $pdo = db();

    // Release entries this run previously claimed (recalculation refreshes the
    // claim set; entries of a shrunk scope or changed period come back free).
    $pdo->prepare('UPDATE payroll_overtime_entries SET run_id = NULL WHERE run_id = :run')->execute(['run' => $runId]);
    $pdo->prepare("DELETE FROM payroll_run_components WHERE run_id = :run AND source = 'overtime'")->execute(['run' => $runId]);

    $employees = payroll_company_employees((int) $run['company_id']);
    $scopeIds = payroll_run_scope_ids($run);
    $injected = 0;
    foreach ($employees as $employee) {
        $employeeId = (int) $employee['id'];
        if ($scopeIds !== [] && !in_array($employeeId, $scopeIds, true)) {
            continue;
        }
        $sql = "SELECT e.* FROM payroll_overtime_entries e
            INNER JOIN payroll_overtime_weeks w
                    ON w.payroll_employee_id = e.payroll_employee_id AND w.week_start = e.week_start
            WHERE e.payroll_employee_id = :pe AND e.run_id IS NULL
              AND e.ot_date BETWEEN :ps AND :pend"
            . ($requireApproval ? " AND w.status = 'approved'" : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['pe' => $employeeId, 'ps' => $periodStart, 'pend' => $periodEnd]);
        $entries = $stmt->fetchAll();
        if ($entries === []) {
            continue;
        }
        $amount = 0.0;
        $hours = 0.0;
        foreach ($entries as $entry) {
            $amount = round($amount + (float) $entry['amount'], 2);
            $hours = round($hours + (float) $entry['hours'], 2);
            $pdo->prepare('UPDATE payroll_overtime_entries SET run_id = :run WHERE id = :id AND run_id IS NULL')
                ->execute(['run' => $runId, 'id' => (int) $entry['id']]);
        }
        if ($amount <= 0) {
            continue;
        }
        $snapshot = payroll_component_snapshot_row($component, [
            'suggested_amount' => $amount,
            'amount' => $amount,
            'taxable' => (int) $component['taxable'] === 1,
            'override_reason' => null,
            'source' => 'overtime',
        ]);
        $pdo->prepare('INSERT INTO payroll_run_components (
                run_id, payroll_employee_id, component_id, component_code, component_name, category,
                posting_behaviour, taxable, include_in_gross, include_in_net, calc_method,
                debit_ledger_id, credit_ledger_id, employer_expense_ledger_id, contribution_payable_ledger_id,
                suggested_amount, amount, override_reason, source
            ) VALUES (:run_id, :pe, :component_id, :code, :name, :category, :behaviour, :taxable, :in_gross,
                :in_net, :calc_method, :dr, :cr, :er_exp, :er_pay, :suggested, :amount, :reason, :source)
            ON DUPLICATE KEY UPDATE amount = VALUES(amount), suggested_amount = VALUES(suggested_amount), source = VALUES(source)')
            ->execute([
                'run_id' => $runId, 'pe' => $employeeId,
                'component_id' => (int) $component['id'], 'code' => $snapshot['component_code'],
                'name' => $snapshot['component_name'], 'category' => 'overtime',
                'behaviour' => $snapshot['posting_behaviour'], 'taxable' => $snapshot['taxable'],
                'in_gross' => $snapshot['include_in_gross'], 'in_net' => $snapshot['include_in_net'],
                'calc_method' => 'overtime_hours',
                'dr' => $snapshot['debit_ledger_id'], 'cr' => $snapshot['credit_ledger_id'],
                'er_exp' => $snapshot['employer_expense_ledger_id'], 'er_pay' => $snapshot['contribution_payable_ledger_id'],
                'suggested' => $amount, 'amount' => $amount, 'reason' => 'Weekly overtime ' . number_format($hours, 2) . ' h',
                'source' => 'overtime',
            ]);
        $injected++;
    }
    return ['injected' => $injected];
}

/** Free every overtime claim of a run (cancel / delete flows). */
function payroll_ot_release_run(int $runId): void
{
    if (!table_exists('payroll_overtime_entries')) {
        return;
    }
    db()->prepare('UPDATE payroll_overtime_entries SET run_id = NULL WHERE run_id = :run')->execute(['run' => $runId]);
}
