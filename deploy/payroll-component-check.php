<?php
declare(strict_types=1);

/**
 * Shows how each pay component will be TREATED, and repairs one that is set to
 * contradict itself.
 *
 *   php deploy/payroll-component-check.php <company_id>
 *   php deploy/payroll-component-check.php <company_id> --fix=DA
 *
 * An allowance whose "In gross pay" box is unticked, or whose posting behaviour
 * says deduction, is paid nowhere near where its category says it should be: the
 * salary sheet prints the amount in the component's own column while gross does
 * not contain it. Nothing in the engine is wrong when that happens - it is doing
 * exactly what the component was set to do - which is precisely why it is hard to
 * see. This prints the treatment next to the setting so the contradiction is
 * obvious, and --fix puts one component back to its category's default.
 */

fwrite(STDOUT, "payroll-component-check: starting (PHP " . PHP_VERSION . ")\n");

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

$fail = static function (string $message, int $code = 1): never {
    fwrite(STDERR, 'payroll-component-check: ' . $message . "\n");
    exit($code);
};

// Same .env discovery as apply-migration: the repository has no .env of its own,
// and falling back to the development defaults connects to the wrong database.
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
require_once __DIR__ . '/../app/config.php';
fwrite(STDOUT, 'payroll-component-check: ' . DB_NAME . ' on ' . DB_HOST . ' (' . $envPath . ")\n\n");

$companyId = 0;
$fixCode = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--fix=')) {
        $fixCode = strtoupper(trim(substr($argument, 6)));
    } elseif (ctype_digit($argument)) {
        $companyId = (int) $argument;
    }
}
if ($companyId <= 0) {
    // Nobody knows their company id by heart, and guessing it edits the wrong
    // company's pay. Listing them is the answer to "what do I put here".
    try {
        $tmp = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET), DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        $fail('could not connect: ' . $e->getMessage(), 3);
    }
    fwrite(STDOUT, "Companies that have pay components:\n\n");
    $listed = 0;
    foreach ($tmp->query('SELECT c.id, c.name, COUNT(pc.id) AS components
                          FROM companies c INNER JOIN payroll_components pc ON pc.company_id = c.id
                          GROUP BY c.id, c.name ORDER BY c.id') as $companyRow) {
        printf("  id %-6s %-46s %s component(s)\n", $companyRow['id'], substr((string) $companyRow['name'], 0, 46), $companyRow['components']);
        $listed++;
    }
    if ($listed === 0) {
        fwrite(STDOUT, "  (none)\n");
    }
    fwrite(STDOUT, "\nThen run:  php deploy/payroll-component-check.php <id from above>\n");
    exit(0);
}

try {
    $pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET), DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    $fail('could not connect: ' . $e->getMessage(), 3);
}

// Mirrors payroll_component_behaviour() and the engine's bucketing, so what is
// printed here is what the engine will actually do.
$treatment = static function (array $c): string {
    $behaviour = (string) $c['posting_behaviour'];
    if ($behaviour === 'category_default') {
        $behaviour = match ((string) $c['category']) {
            'deduction' => (int) $c['employer_paid'] === 1 ? 'employer_contribution' : 'deduction_liability',
            'employer_contribution' => 'employer_contribution',
            'reimbursement' => 'reimbursement',
            'advance_recovery' => 'advance_recovery',
            'tax' => 'deduction_liability',
            'info' => 'non_posting',
            default => 'earning_expense',
        };
    }
    if ($behaviour === 'employer_contribution') {
        return 'employer cost only (never employee pay)';
    }
    if (in_array($behaviour, ['deduction_liability', 'advance_recovery'], true)
        || in_array((string) $c['category'], ['deduction', 'tax', 'advance_recovery'], true)) {
        return 'DEDUCTED from net';
    }
    if ($behaviour === 'non_posting' || (string) $c['category'] === 'info') {
        return 'shown only, posts nothing';
    }
    if ((string) $c['category'] === 'overtime') {
        return 'added to gross as overtime';
    }
    if ((int) $c['include_in_gross'] !== 1) {
        return 'NOT IN GROSS - paid outside gross';
    }

    return 'added to gross';
};

if ($fixCode !== '') {
    $find = $pdo->prepare('SELECT * FROM payroll_components WHERE company_id = :cid AND UPPER(code) = :code LIMIT 1');
    $find->execute(['cid' => $companyId, 'code' => $fixCode]);
    $component = $find->fetch();
    if (!$component) {
        $fail('company ' . $companyId . ' has no component with code ' . $fixCode, 2);
    }
    fwrite(STDOUT, 'Before: ' . $component['code'] . ' (' . $component['category'] . ') -> ' . $treatment($component) . "\n");
    $pdo->prepare("UPDATE payroll_components SET include_in_gross = 1, include_in_net = 1, posting_behaviour = 'category_default' WHERE id = :id")
        ->execute(['id' => (int) $component['id']]);
    $find->execute(['cid' => $companyId, 'code' => $fixCode]);
    $after = $find->fetch();
    fwrite(STDOUT, 'After:  ' . $after['code'] . ' (' . $after['category'] . ') -> ' . $treatment($after) . "\n\n");
    fwrite(STDOUT, "Now RECALCULATE the payroll run so the lines pick this up.\n");
    exit(0);
}

$stmt = $pdo->prepare('SELECT * FROM payroll_components WHERE company_id = :cid ORDER BY sort_order ASC, code ASC');
$stmt->execute(['cid' => $companyId]);
$rows = $stmt->fetchAll();
if ($rows === []) {
    fwrite(STDOUT, "Company $companyId has no pay components.\n");
    exit(0);
}
printf("  %-10s %-24s %-12s %-20s %-7s %-6s %s\n", 'CODE', 'NAME', 'CATEGORY', 'POSTING BEHAVIOUR', 'GROSS?', 'NET?', 'WHAT THE ENGINE DOES');
foreach ($rows as $row) {
    $what = $treatment($row);
    printf(
        "  %-10s %-24s %-12s %-20s %-7s %-6s %s\n",
        $row['code'],
        substr((string) $row['name'], 0, 24),
        $row['category'],
        $row['posting_behaviour'],
        (int) $row['include_in_gross'] === 1 ? 'yes' : 'NO',
        (int) $row['include_in_net'] === 1 ? 'yes' : 'NO',
        $what
    );
}
fwrite(STDOUT, "\nAn allowance that does not say \"added to gross\" is set to contradict its own category.\n");
fwrite(STDOUT, "Repair one with:  php deploy/payroll-component-check.php $companyId --fix=CODE\n");
