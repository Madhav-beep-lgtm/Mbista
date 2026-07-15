<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

// Global fiscal-year switcher target. Selecting a year is a VIEWING choice:
// it is remembered per user + per company in the session and never touches
// the company-wide default (Settings > Fiscal & accounting controls does
// that, behind the admin capability).
require_staff_admin_or_client_books();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/index.php');
}
verify_csrf();

$companyId = current_company_id();
$fiscalYearId = (int) ($_POST['fiscal_year_id'] ?? 0);

// The browser's fiscal_year_id is never trusted on its own: the year must
// exist, be active for viewing, and belong to the company the user is
// already authorized to work in — a payload naming another tenant's year
// changes nothing.
$fiscalYear = $fiscalYearId > 0 ? fiscal_year_by_id($fiscalYearId) : null;
if ($companyId <= 0 || !$fiscalYear || (int) $fiscalYear['company_id'] !== $companyId || (int) ($fiscalYear['is_active'] ?? 0) !== 1) {
    flash('error', 'That fiscal year is not available for this company.');
} else {
    set_context($companyId, $fiscalYearId);
    $status = fiscal_year_status($fiscalYear);
    flash('success', 'Fiscal year switched to ' . $fiscalYear['label'] . ' (' . $fiscalYear['start_date'] . ' to ' . $fiscalYear['end_date'] . ', ' . $status . ').'
        . (in_array($status, ['closed', 'locked'], true) ? ' This year is ' . $status . ' — screens are read-only for accounting changes.' : ''));
}

// Return to the page the user was on so every fiscal-year-dependent screen
// re-renders in the new context; same-host paths only.
$backTo = (string) ($_POST['return_to'] ?? '');
$parsedPath = (string) (parse_url($backTo, PHP_URL_PATH) ?? '');
$parsedQuery = (string) (parse_url($backTo, PHP_URL_QUERY) ?? '');
if ($parsedPath !== '' && str_starts_with($parsedPath, '/') && !str_starts_with($parsedPath, '//')) {
    header('Location: ' . $parsedPath . ($parsedQuery !== '' ? '?' . $parsedQuery : ''));
    exit;
}
redirect('admin/accounting-dashboard.php');
