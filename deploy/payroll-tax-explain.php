<?php
declare(strict_types=1);

/**
 * Prints one employee's monthly tax derivation, line for line, in the order a
 * tax working paper is written:
 *
 *   php deploy/payroll-tax-explain.php <run_id> [EMPLOYEE_CODE]
 *   php deploy/payroll-tax-explain.php            (lists the runs to choose from)
 *
 * "Why is the tax 95?" is not answerable from a salary sheet: the figure is the
 * end of a chain that runs from the contract, through the days actually worked,
 * out to an estimate of the whole year, through the slabs, and back to one
 * month. Every step is stored on the line already - this reads them out instead
 * of recomputing, so what it prints is what was actually charged, and a step
 * that looks wrong can be pointed at.
 *
 * It also names any component the projection LEFT OUT and why, which is the
 * usual reason an annual estimate comes out lower than the person expects.
 */

fwrite(STDOUT, "payroll-tax-explain: starting (PHP " . PHP_VERSION . ")\n");

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

$fail = static function (string $message, int $code = 1): never {
    fwrite(STDERR, 'payroll-tax-explain: ' . $message . "\n");
    exit($code);
};

// Same .env discovery as apply-migration: the repository carries no .env, and
// the development defaults point at a different database entirely.
$home = (string) (getenv('HOME') ?: '');
$envCandidates = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $envCandidates[] = substr($argument, 6);
    }
}
if ($home !== '') {
    foreach ([$home . '/public_html', $home . '/mbca.com.np', $home . '/public_html/mbca.com.np'] as $docroot) {
        $envCandidates[] = dirname($docroot) . '/.env';
    }
}
$envCandidates[] = __DIR__ . '/../.env';
$envPath = '';
foreach ($envCandidates as $candidate) {
    if (is_file($candidate) && is_readable($candidate) && str_contains((string) @file_get_contents($candidate), 'DB_NAME')) {
        $envPath = $candidate;
        break;
    }
}
if ($envPath === '') {
    $fail('no .env naming a DB_NAME was found; pass --env=/full/path/to/.env', 2);
}
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    $value = trim($value);
    if (strlen($value) > 1 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
        $value = substr($value, 1, -1);
    }
    if ($key !== '') {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/payroll_engine.php';
fwrite(STDOUT, 'payroll-tax-explain: ' . DB_NAME . ' on ' . DB_HOST . "\n\n");

$runId = 0;
$employeeCode = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--')) {
        continue;
    }
    if (ctype_digit($argument)) {
        $runId = (int) $argument;
    } else {
        $employeeCode = strtoupper(trim($argument));
    }
}

$money = static fn ($v): string => number_format((float) $v, 2);
$trim = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$row = static function (string $label, $value, string $note = ''): void {
    global $money;
    printf("  %-52s %14s  %s\n", $label, $value === null ? '' : $money($value), $note);
};
$rule = static function (string $title = ''): void {
    fwrite(STDOUT, ($title === '' ? '' : "\n" . $title . "\n") . '  ' . str_repeat('-', 70) . "\n");
};

if ($runId <= 0) {
    fwrite(STDOUT, "Payroll runs that have calculated lines:\n\n");
    $listed = 0;
    foreach (db()->query("SELECT r.id, r.period_no, r.period_label, r.status, c.name AS company, COUNT(l.id) AS employees
            FROM payroll_runs r
            INNER JOIN companies c ON c.id = r.company_id
            INNER JOIN payroll_run_lines l ON l.run_id = r.id
            GROUP BY r.id, r.period_no, r.period_label, r.status, c.name
            ORDER BY r.id DESC LIMIT 20") as $runRow) {
        printf("  run %-6s %-12s %-10s %-44s %s employee(s)\n", $runRow['id'],
            substr((string) $runRow['period_label'], 0, 12), $runRow['status'],
            substr((string) $runRow['company'], 0, 44), $runRow['employees']);
        $listed++;
    }
    if ($listed === 0) {
        fwrite(STDOUT, "  (none)\n");
    }
    fwrite(STDOUT, "\nThen run:  php deploy/payroll-tax-explain.php <run id> [EMPLOYEE CODE]\n");
    exit(0);
}

$run = payroll_run($runId);
if (!$run) {
    $fail('run ' . $runId . ' does not exist. Run with no arguments to list them.', 2);
}
$settings = payroll_settings((int) $run['company_id']);
$lines = payroll_run_lines($runId);
if ($lines === []) {
    $fail('run ' . $runId . ' has no calculated lines.', 2);
}
if ($employeeCode !== '') {
    $lines = array_values(array_filter($lines, static fn (array $l): bool => strtoupper((string) $l['employee_code']) === $employeeCode));
    if ($lines === []) {
        $fail('run ' . $runId . ' has no employee ' . $employeeCode . '.', 2);
    }
} else {
    // One employee is the point of this tool; without a code, show the first and
    // say how to pick another rather than printing forty working papers.
    $lines = [$lines[0]];
}

foreach ($lines as $line) {
    $employeeId = (int) $line['payroll_employee_id'];
    $trace = json_decode((string) $line['trace'], true) ?: [];
    $projection = (array) ($trace['projection'] ?? []);
    $withholding = (array) ($trace['withholding'] ?? []);
    $retirement = (array) ($trace['retirement'] ?? []);
    $workedDays = $line['worked_days'] ?? null;
    $periodDays = (float) ($line['period_days'] ?? ($settings['standard_working_days'] ?? 30));

    fwrite(STDOUT, "Employment Tax Calculation\n");
    fwrite(STDOUT, '  ' . $line['employee_code'] . ' ' . $line['person_name']
        . '  |  run ' . $runId . ', period ' . $run['period_no'] . ' (' . $run['period_label'] . '), status ' . $run['status'] . "\n");

    // ---------------------------------------------------------- the contract
    $rule('CONTRACT - what a FULL month pays (the basis for estimating the year)');
    $employee = db()->prepare('SELECT * FROM payroll_employees WHERE id = ?');
    $employee->execute([$employeeId]);
    $employee = $employee->fetch(PDO::FETCH_ASSOC) ?: [];
    $fullMonth = round((float) ($employee['basic_salary'] ?? 0), 2);
    $row('Basic Salary Monthly', $fullMonth, 'projected');
    $excluded = [];
    foreach (payroll_run_component_rows($runId, $employeeId) as $component) {
        $full = round((float) ($component['suggested_amount'] ?? $component['amount']), 2);
        $behaviour = (string) $component['posting_behaviour'];
        $method = (string) ($component['tax_projection_method'] ?? 'regular');
        $taxable = (int) ($component['taxable'] ?? 1) === 1;
        $isIncome = !in_array($behaviour, ['deduction_liability', 'advance_recovery', 'employer_contribution', 'non_posting'], true)
            && !in_array((string) $component['category'], ['deduction', 'tax', 'advance_recovery', 'info'], true);
        $projected = $isIncome && $taxable && in_array($method, ['regular', 'guaranteed'], true);
        $why = $projected ? 'projected'
            : (!$isIncome ? 'not income - ' . $behaviour
                : (!$taxable ? 'NOT projected: marked non-taxable'
                    : 'NOT projected: tax projection method is ' . $method));
        $row((string) $component['component_name'], $full, $why);
        if ($projected) {
            $fullMonth = round($fullMonth + $full, 2);
        } elseif ($isIncome && $full > 0) {
            $excluded[] = (string) $component['component_name'] . ' (' . $why . ')';
        }
    }
    $row('FULL MONTH regular income', $fullMonth, 'x remaining months = the estimate');

    // ---------------------------------------------------------- this month
    $rule('THIS MONTH - what was actually earned');
    if ($workedDays !== null) {
        $row('Days worked', null, $trim($workedDays) . ' of ' . $trim($periodDays)
            . '  (regular pay x ' . $trim($workedDays) . '/' . $trim($periodDays) . ')');
    } else {
        $row('Days worked', null, 'not recorded - the full month is paid');
    }
    $row('Basic salary paid', (float) $line['basic']);
    foreach ((array) ($trace['components'] ?? []) as $component) {
        // Basic has its own line above and typed overtime has its own below;
        // printing either again would read as a second payment.
        if ((string) ($component['category'] ?? '') === 'basic'
            || (string) ($component['source'] ?? '') === 'overtime_hours'
            || (float) ($component['amount'] ?? 0) == 0.0) {
            continue;
        }
        $isDeduction = in_array((string) ($component['behaviour'] ?? ''), ['deduction_liability', 'advance_recovery'], true)
            || in_array((string) ($component['category'] ?? ''), ['deduction', 'tax', 'advance_recovery'], true);
        $note = $isDeduction ? 'DEDUCTED from net, not part of gross'
            : ((string) ($component['projection'] ?? '') === 'actual_only' ? 'earned only - never projected' : '');
        $row('  ' . (string) $component['label'], (float) $component['amount'], $note);
    }
    if ($line['overtime_hours'] !== null) {
        $row('Overtime', (float) $line['overtime'],
            $trim($line['overtime_hours']) . ' h x ' . $money($line['overtime_rate'] ?? 0)
            . '  (basic / ' . $trim($settings['standard_working_days'] ?? 30) . ' days / '
            . $trim($settings['standard_hours_per_day'] ?? 8) . ' hours x ' . $trim($settings['ot_multiplier'] ?? 1.5) . ')');
    }
    $row('Gross pay for the month', (float) $line['gross']);
    $row('Assessable income for the month',
        round((float) ($projection['current_regular'] ?? 0) + (float) ($projection['current_irregular'] ?? 0), 2),
        'employer retirement included, deductions excluded');

    // ---------------------------------------------------------- the year
    $rule('ANNUAL ESTIMATE');
    $row('Actual assessable income earned earlier this year',
        round((float) ($projection['actual_regular_to_date'] ?? 0) + (float) ($projection['actual_irregular_to_date'] ?? 0), 2));
    $row('This period - regular', (float) ($projection['current_regular'] ?? 0));
    $row('This period - earned only (OT, service charge, one-off)', (float) ($projection['current_irregular'] ?? 0), 'never projected forward');
    $futurePeriods = (array) ($projection['projected_periods'] ?? []);
    $row('Estimated for the remaining ' . count($futurePeriods) . ' month(s)', (float) ($projection['projected_future_regular'] ?? 0),
        count($futurePeriods) > 0
            ? 'at ' . $money((float) ($futurePeriods[0]['assessable'] ?? 0)) . ' a month (FULL month, not pro-rated)'
            : 'final period of employment');
    if ((float) ($projection['manual_projected'] ?? 0) > 0) {
        $row('Approved manual projections', (float) $projection['manual_projected']);
    }
    if ((float) ($projection['prior_employment_income'] ?? 0) > 0) {
        $row('Prior employment income (approved)', (float) $projection['prior_employment_income']);
    }
    $row('ESTIMATED ANNUAL ASSESSABLE', (float) $line['assessable_annual']);
    $row('Less: retirement contribution allowed', -(float) $line['retirement_deduction_annual'],
        (string) ($retirement['scheme'] ?? 'none') === 'none' ? 'no scheme on this employee' : (string) $retirement['scheme']);
    $row('TAXABLE INCOME FOR THE YEAR', (float) $line['taxable_annual']);

    // ---------------------------------------------------------- the tax
    $rule('ANNUAL TAX (' . (string) ($trace['tax_version_label'] ?? '?') . ', ' . (string) ($trace['category'] ?? '?') . ')');
    foreach ((array) ($trace['slabs'] ?? []) as $slab) {
        $row((string) $slab['band'] . ' @ ' . $slab['rate'] . '%', (float) $slab['tax']);
    }
    $sstAnnual = (float) ($withholding['sst_annual'] ?? 0);
    $annualTax = (float) ($trace['annual_tax'] ?? 0);
    if ($annualTax <= 0) {
        $annualTax = 0.0;
        foreach ((array) ($trace['slabs'] ?? []) as $slab) {
            $annualTax = round($annualTax + (float) $slab['tax'], 2);
        }
    }
    $row('Social Security Tax (first slab, own payable ledger)', $sstAnnual);
    $row('Remuneration Tax (the rest)', round($annualTax - $sstAnnual, 2));
    $row('TOTAL ANNUAL TAX', $annualTax);

    // ---------------------------------------------------------- this month's cut
    $remaining = (int) ($withholding['months_remaining'] ?? 1);
    $rule('WITHHELD THIS MONTH  -  (annual - already withheld) / months remaining');
    $row('Months remaining, including this one', null, (string) $remaining);
    $sstYtd = (float) ($withholding['sst_ytd_withheld'] ?? 0);
    $totalYtd = (float) ($withholding['ytd_withheld'] ?? 0);
    $row('Social Security Tax', (float) ($withholding['sst_month'] ?? $line['sst_month']),
        '(' . $money($sstAnnual) . ' - ' . $money($sstYtd) . ') / ' . $remaining);
    $row('Remuneration Tax', (float) ($withholding['remuneration_month'] ?? ((float) $line['tax_month'] - (float) $line['sst_month'])),
        '(' . $money($annualTax - $sstAnnual) . ' - ' . $money($totalYtd - $sstYtd) . ') / ' . $remaining);
    if (($withholding['tax_override'] ?? null) !== null) {
        $row('Approved override', (float) $withholding['tax_override'], 'system figure was ' . $money((float) ($withholding['system_tax'] ?? 0)));
    }
    $row('TAX FOR THIS MONTH', (float) $line['tax_month']);
    $row('Net payable', (float) $line['net_pay']);

    if ($excluded !== []) {
        fwrite(STDOUT, "\n  If the annual estimate looks LOW, this is why - these are paid but not projected:\n");
        foreach ($excluded as $note) {
            fwrite(STDOUT, '    - ' . $note . "\n");
        }
        fwrite(STDOUT, "  A recurring allowance should have tax projection method 'regular' and be taxable.\n");
    }
    fwrite(STDOUT, "\n");
}

if ($employeeCode === '') {
    fwrite(STDOUT, "Showing the first employee only. Add a code for another, e.g.:\n");
    fwrite(STDOUT, '  php deploy/payroll-tax-explain.php ' . $runId . " E-01\n");
}
