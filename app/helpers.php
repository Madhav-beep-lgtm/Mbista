<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Enforce HttpOnly + SameSite on the session cookie regardless of php.ini,
    // and Secure whenever the request arrived over HTTPS.
    $cookieSecure = (
        (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    );
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => $cookieSecure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/nepali_date.php';

/**
 * Active date display mode for the current user: 'ad', 'bs', or 'both'.
 * Session overrides the company default (settings key 'date_mode').
 */
function date_mode(): string
{
    $mode = (string) ($_SESSION['app_date_mode'] ?? '');
    if (!in_array($mode, ['ad', 'bs', 'both'], true)) {
        $mode = (string) (function_exists('setting') ? setting('date_mode', 'both') : 'both');
    }
    return in_array($mode, ['ad', 'bs', 'both'], true) ? $mode : 'both';
}

/**
 * Format one AD date (Y-m-d) for display honoring the active date mode:
 * AD only, BS only, or "AD (BS BS)".
 */
function app_date(?string $adDate, string $adFormat = 'd M Y'): string
{
    $adDate = (string) $adDate;
    if ($adDate === '' || strtotime($adDate) === false) {
        return '';
    }
    $ad = date($adFormat, strtotime($adDate));
    $mode = date_mode();
    if ($mode === 'ad') {
        return $ad;
    }
    $bs = bs_format(substr($adDate, 0, 10));
    if ($bs === '') {
        return $ad;
    }
    if ($mode === 'bs') {
        return $bs . ' BS';
    }
    return $ad . ' (' . $bs . ' BS)';
}

/** Format an AD period (from-to) honoring the active date mode. */
function app_date_range(?string $fromAd, ?string $toAd): string
{
    $fromAd = (string) $fromAd;
    $toAd = (string) $toAd;
    $mode = date_mode();
    $adRange = date('d M Y', strtotime($fromAd)) . ' - ' . date('d M Y', strtotime($toAd));
    if ($mode === 'ad') {
        return $adRange;
    }
    $bsRange = function_exists('bs_format_range') ? bs_format_range($fromAd, $toAd) : '';
    if ($bsRange === '') {
        return $adRange;
    }
    if ($mode === 'bs') {
        return $bsRange . ' BS';
    }
    return $adRange . '  |  ' . $bsRange . ' BS';
}

const LEDGER_MASTERS = [
    'equity' => ['label' => 'Equity', 'nature' => 'equity', 'sort_order' => 1],
    'non_current_liability' => ['label' => 'Non-current Liabilities', 'nature' => 'liability', 'sort_order' => 2],
    'current_liability' => ['label' => 'Current Liabilities', 'nature' => 'liability', 'sort_order' => 3],
    'non_current_asset' => ['label' => 'Non-current Assets', 'nature' => 'asset', 'sort_order' => 4],
    'current_asset' => ['label' => 'Current Assets', 'nature' => 'asset', 'sort_order' => 5],
    'direct_income' => ['label' => 'Direct Income', 'nature' => 'revenue', 'sort_order' => 6],
    'indirect_income' => ['label' => 'Indirect Income', 'nature' => 'revenue', 'sort_order' => 7],
    'direct_expense' => ['label' => 'Direct Expenses', 'nature' => 'expense', 'sort_order' => 8],
    'indirect_expense' => [
        'label' => 'Indirect Expenses',
        'nature' => 'expense',
        'sort_order' => 9,
        'suggested_groups' => [
            'Administrative Expenses',
            'Operating/Employee Benefit Expenses',
            'Sales and Marketing Expenses',
            'Other Non-operating Expenses',
        ],
    ],
];

function ledger_masters(): array
{
    return LEDGER_MASTERS;
}

function ledger_master_nature(string $masterKey): ?string
{
    return LEDGER_MASTERS[$masterKey]['nature'] ?? null;
}

/**
 * Structured chart-of-accounts codes: master = 1 digit by nature,
 * group = master digit + sequence (2 digits), ledger = group code +
 * sequence (3 digits). Codes are always system-generated.
 */
function coa_master_digit(string $masterKey): string
{
    $digits = ['asset' => '1', 'liability' => '2', 'equity' => '3', 'revenue' => '4', 'expense' => '5'];
    return $digits[ledger_master_nature($masterKey) ?? 'asset'] ?? '1';
}

function coa_next_group_code(int $companyId, string $masterKey): string
{
    $digit = coa_master_digit($masterKey);
    $stmt = db()->prepare('SELECT code FROM ledger_groups WHERE company_id = :cid AND code REGEXP :pattern');
    $stmt->execute(['cid' => $companyId, 'pattern' => '^' . $digit . '[0-9]+$']);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existing) {
        $max = max($max, (int) substr((string) $existing, 1));
    }
    return $digit . (string) ($max + 1);
}

function coa_next_ledger_code(int $companyId, int $groupId): string
{
    $groupStmt = db()->prepare('SELECT code, master_key FROM ledger_groups WHERE id = :id AND company_id = :cid LIMIT 1');
    $groupStmt->execute(['id' => $groupId, 'cid' => $companyId]);
    $group = $groupStmt->fetch();
    $base = $group && preg_match('/^\d+$/', (string) $group['code'])
        ? (string) $group['code']
        : coa_master_digit((string) ($group['master_key'] ?? '')) . '0';
    $stmt = db()->prepare('SELECT code FROM ledgers WHERE company_id = :cid AND code REGEXP :pattern');
    $stmt->execute(['cid' => $companyId, 'pattern' => '^' . $base . '[0-9]+$']);
    $max = 0;
    $baseLength = strlen($base);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existing) {
        $max = max($max, (int) substr((string) $existing, $baseLength));
    }
    return $base . (string) ($max + 1);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function icon(string $name): string
{
    $icons = [
        'home' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z'],
        ],
        'dashboard' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M4 4h7v7H4z', 'M13 4h7v4h-7z', 'M13 10h7v10h-7z', 'M4 13h7v7H4z'],
        ],
        'portal' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M3 12h18', 'M12 3a9 9 0 0 1 0 18', 'M12 3a9 9 0 0 0 0 18', 'M7 5.5a15 15 0 0 0 0 13', 'M17 5.5a15 15 0 0 1 0 13'],
        ],
        'staff' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M3 7h18v12H3z', 'M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2', 'M3 12h18'],
        ],
        'admin' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M12 3l8 4v5c0 5.25-3.5 8.75-8 10-4.5-1.25-8-4.75-8-10V7z', 'M9 12l2 2 4-4'],
        ],
        'users' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'M9 11a4 4 0 1 0 0-8', 'M22 21v-2a4 4 0 0 0-3-3.87', 'M16 3.13a4 4 0 0 1 0 7.75'],
        ],
        'clients' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2', 'M12 11a4 4 0 1 0 0-8'],
        ],
        'teams' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M17 21v-2a4 4 0 0 0-3-3.87', 'M7 21v-2a4 4 0 0 1 3-3.87', 'M12 11a4 4 0 1 0 0-8', 'M5.5 12.5a3 3 0 1 0 0-6', 'M18.5 12.5a3 3 0 1 1 0-6'],
        ],
        'contracts' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z', 'M14 2v5h5', 'M9 12h6', 'M9 16h6'],
        ],
        'tasks' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M9 11l2 2 4-4', 'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2'],
        ],
        'documents' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z', 'M14 2v6h6', 'M8 12h8', 'M8 16h5'],
        ],
        'compliance' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M8 2v3', 'M16 2v3', 'M3 8h18', 'M4 5h16a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z', 'M9 13l2 2 4-4'],
        ],
        'messages' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'],
        ],
        'tickets' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z', 'M13 6v12'],
        ],
        'attendance' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M12 8v4l3 3', 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20'],
        ],
        'leave' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M8 2v3', 'M16 2v3', 'M3 8h18', 'M4 5h16a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z'],
        ],
        'timesheets' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20', 'M12 6v6l4 2'],
        ],
        'invoices' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z', 'M14 2v6h6', 'M8 13h8', 'M8 17h8'],
        ],
        'companies' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M3 21h18', 'M5 21V7l7-4 7 4v14', 'M9 10h.01', 'M15 10h.01', 'M9 14h.01', 'M15 14h.01', 'M11 21v-4h2v4'],
        ],
        'accounting' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z', 'M8 7h8', 'M8 11h3', 'M13 11h3', 'M8 15h3', 'M13 15h3'],
        ],
        'reports' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M4 19h16', 'M7 16V8', 'M12 16V5', 'M17 16v-3'],
        ],
        'settings' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M12 15a3 3 0 1 0 0-6', 'M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.65 1.65 0 0 0 15 19.4a1.65 1.65 0 0 0-1 .6 1.65 1.65 0 0 1-3 0 1.65 1.65 0 0 0-1-.6 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-.6-1 1.65 1.65 0 0 1 0-3 1.65 1.65 0 0 0 .6-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-.6 1.65 1.65 0 0 1 3 0 1.65 1.65 0 0 0 1 .6 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.24.31.52.55.84.72a1.65 1.65 0 0 1 0 3c-.32.17-.6.41-.84.72z'],
        ],
        'contact' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2', 'M22 7l-10 6L2 7'],
        ],
        'about' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20', 'M12 16v-4', 'M12 8h.01'],
        ],
        'services' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M14 7h7', 'M10 17H3', 'M17 7a2 2 0 1 1-4 0 2 2 0 0 1 4 0', 'M7 17a2 2 0 1 1-4 0 2 2 0 0 1 4 0', 'M3 7h7', 'M14 17h7'],
        ],
        'insights' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M3 3v18h18', 'M7 14l3-3 3 2 4-5'],
        ],
        'login' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4', 'M10 17l5-5-5-5', 'M15 12H3'],
        ],
        'logout' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4', 'M16 17l5-5-5-5', 'M21 12H9'],
        ],
        'theme' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79'],
        ],
        'profile' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2', 'M12 11a4 4 0 1 0 0-8'],
        ],
        'chevron' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M6 9l6 6 6-6'],
        ],
        'menu' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M3 6h18', 'M3 12h18', 'M3 18h18'],
        ],
        'close' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M18 6L6 18', 'M6 6l12 12'],
        ],
        'phone' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z'],
        ],
        'target' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20', 'M12 18a6 6 0 1 0 0-12 6 6 0 0 0 0 12', 'M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4'],
        ],
        'award' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M12 15a6 6 0 1 0 0-12 6 6 0 0 0 0 12', 'M8.2 13.9 7 21l5-3 5 3-1.2-7.1'],
        ],
        'handshake' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['m11 17 2 2a1 1 0 1 0 3-3', 'm14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4', 'm21 3 1 11h-2', 'M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3', 'M3 4h8'],
        ],
        'search' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16', 'm21 21-4.35-4.35'],
        ],
        'calendar' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M8 2v4', 'M16 2v4', 'M3 6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z', 'M3 10h18'],
        ],
        'bank' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M3 21h18', 'M4 18h16', 'M6 18v-7', 'M10 18v-7', 'M14 18v-7', 'M18 18v-7', 'M12 3 3 8h18z'],
        ],
        'card' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M3 6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z', 'M3 10h18', 'M7 15h4'],
        ],
        'wallet' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M20 7H5a2 2 0 0 1-2-2 2 2 0 0 1 2-2h13v4', 'M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1V8a1 1 0 0 0-1-1', 'M16 13.5a1 1 0 1 0 2 0 1 1 0 0 0-2 0'],
        ],
        'receipt-voucher' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M4 2v20l2-1.5L8 22l2-1.5L12 22l2-1.5L16 22l2-1.5L20 22V2l-2 1.5L16 2l-2 1.5L12 2l-2 1.5L8 2 6 3.5z', 'M8 8h8', 'M8 12h8', 'M8 16h5'],
        ],
        'journal' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M4 19.5A2.5 2.5 0 0 1 6.5 17H20', 'M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z', 'M9 7h7', 'M9 11h5'],
        ],
        'reconcile' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M3 12a9 9 0 0 1 15.5-6.2L21 8', 'M21 3v5h-5', 'M21 12a9 9 0 0 1-15.5 6.2L3 16', 'M3 21v-5h5', 'm9 12 2 2 4-4'],
        ],
        'cart' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M8 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2', 'M19 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2', 'M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57L22 6H5.12'],
        ],
        'tag' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z', 'M7.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1'],
        ],
        'pie' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M21 12A9 9 0 1 1 12 3v9z', 'M21 8.5A9 9 0 0 0 15.5 3V8.5z'],
        ],
        'trend-up' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M22 7l-8.5 8.5-5-5L2 17', 'M16 7h6v6'],
        ],
        'trend-down' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M22 17l-8.5-8.5-5 5L2 7', 'M16 17h6v-6'],
        ],
        'more-v' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M12 6.5a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1', 'M12 12.5a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1', 'M12 18.5a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1'],
        ],
        'chevron-right' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M9 6l6 6-6 6'],
        ],
        'analytics' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M3 3v16a2 2 0 0 0 2 2h16', 'M7 15v-4', 'M11 17V9', 'M15 14v-3', 'M19 16V7'],
        ],
        'layers' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['m12 2 8.5 4.5L12 11 3.5 6.5z', 'm3.5 12 8.5 4.5 8.5-4.5', 'm3.5 17 8.5 4.5 8.5-4.5'],
        ],
        'tree' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M8 5a2 2 0 1 1 4 0 2 2 0 0 1-4 0', 'M4 19a2 2 0 1 1 4 0 2 2 0 0 1-4 0', 'M16 19a2 2 0 1 1 4 0 2 2 0 0 1-4 0', 'M10 7v3a2 2 0 0 1-2 2H8a2 2 0 0 0-2 2v3', 'M12 7v3a2 2 0 0 0 2 2h0a2 2 0 0 1 2 2v3'],
        ],
        'upload' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'M17 8l-5-5-5 5', 'M12 3v12'],
        ],
        'badge-check' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76', 'm9 12 2 2 4-4'],
        ],
        'headset' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M3 11a9 9 0 0 1 18 0', 'M3 11h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z', 'M21 11h-3a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2z', 'M21 16v2a4 4 0 0 1-4 4h-5'],
        ],
        'facebook' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z'],
        ],
        'linkedin' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4V8h4v1a6 6 0 0 1 2-1z', 'M2 9h4v12H2z', 'M4 6a2 2 0 1 0 0-4 2 2 0 0 0 0 4'],
        ],
        'youtube' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-1.92 29 29 0 0 0 .46-5.33 29 29 0 0 0-.46-5.33z', 'm9.75 15.02 5.75-3.27-5.75-3.27z'],
        ],
        'filter' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M22 3H2l8 9.46V19l4 2v-8.54z'],
        ],
        'plus' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M12 5v14', 'M5 12h14'],
        ],
        'star' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z'],
        ],
        'download' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'M7 10l5 5 5-5', 'M12 15V3'],
        ],
        'maximize' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M15 3h6v6', 'M9 21H3v-6', 'M21 3l-7 7', 'M3 21l7-7'],
        ],
        'lock' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M5 11h14a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2z', 'M7 11V7a5 5 0 0 1 10 0v4'],
        ],
        'eye' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6'],
        ],
        'bell' => [
            'viewBox' => '0 0 24 24',
            'paths' => ['M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9', 'M13.73 21a2 2 0 0 1-3.46 0'],
        ],
    ];

    $icon = $icons[$name] ?? [
        'viewBox' => '0 0 24 24',
        'paths' => ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20'],
    ];

    $svg = '<svg class="ui-icon" aria-hidden="true" viewBox="' . e($icon['viewBox']) . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">';
    foreach ($icon['paths'] as $path) {
        $svg .= '<path d="' . e($path) . '"></path>';
    }
    $svg .= '</svg>';

    return $svg;
}

function app_name(): string
{
    return setting('site_name', (string) APP_NAME);
}

/**
 * The platform brand name shown in portals and "Powered by" chrome.
 */
function platform_brand_name(): string
{
    return setting('platform_brand', 'M.B. World');
}

/**
 * Renders the M.B. World logo lockup as an <img>. Single source of truth: to
 * drop in official artwork, replace public_html/assets/img/mbworld-logo.svg
 * (and the -light.svg inverse for dark surfaces) — every surface updates.
 *
 * @param string $variant 'dark' text on light surfaces, 'light' for dark ones.
 */
function brand_logo(string $variant = 'dark', string $class = 'mbw-logo', ?string $alt = null): string
{
    $alt = $alt ?? platform_brand_name();

    // Prefer an admin-uploaded platform logo (Settings → Branding); the light
    // variant falls back to the general upload, then to the built-in SVG.
    $uploaded = '';
    if (function_exists('setting')) {
        if ($variant === 'light') {
            $uploaded = (string) setting('platform_logo_light_path', '');
            if ($uploaded === '') {
                $uploaded = (string) setting('platform_logo_path', '');
            }
        } else {
            $uploaded = (string) setting('platform_logo_path', '');
        }
    }

    if ($uploaded !== '') {
        $file = $uploaded;
    } else {
        $file = $variant === 'light' ? 'assets/img/mbworld-logo-light.svg' : 'assets/img/mbworld-logo.svg';
    }

    return '<img src="' . e(url($file)) . '" alt="' . e($alt) . '" class="' . e($class) . '" loading="lazy">';
}

/**
 * Discreet "Powered by M.B. World" line for tenant portals.
 */
function powered_by_mbworld(string $class = 'mbw-poweredby'): string
{
    return '<span class="' . e($class) . '">Powered by <strong>' . e(platform_brand_name()) . '</strong></span>';
}

function app_env(): string
{
    return strtolower((string) APP_ENV);
}

function app_is_production(): bool
{
    return app_env() === 'production';
}

/**
 * Generic CSV export helper.
 *
 * @param string $filename The name of the file to be downloaded.
 * @param array $data The 2D array of data to write to the CSV.
 */
function export_csv(string $filename, array $data): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    foreach ($data as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

function site_currency_symbol(): string
{
    return setting('currency_symbol', '$');
}

function url(string $path = ''): string
{
    $path = ltrim($path, '/');

    if (APP_URL !== '') {
        return APP_URL . ($path !== '' ? '/' . $path : '');
    }

    return '/' . $path;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function flash(string $key, mixed $value = null): mixed
{
    if ($value !== null) {
        $_SESSION['flash'][$key] = $value;

        return null;
    }

    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);

    return $message;
}

/**
 * Renders flashed success and error messages.
 */
function display_flash_messages(): void
{
    $error = flash('error');
    if ($error) {
        echo '<div class="alert alert-danger">' . $error . '</div>';
    }

    $success = flash('success');
    if ($success) {
        echo '<div class="alert alert-success">' . $success . '</div>';
    }

    // You can add other types like 'info' or 'warning' here
    $info = flash('info');
    if ($info) {
        echo '<div class="alert alert-info">' . $info . '</div>';
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals(csrf_token(), (string) $token)) {
        http_response_code(419);
        exit('Invalid CSRF token.');
    }
}

function table_exists(string $tableName): bool
{
    static $cache = [];

    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db_name AND table_name = :table_name');
        $stmt->execute([
            'db_name' => DB_NAME,
            'table_name' => $tableName,
        ]);

        $cache[$tableName] = (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $exception) {
        $cache[$tableName] = false;
    }

    return $cache[$tableName];
}

function column_exists(string $tableName, string $columnName): bool
{
    static $cache = [];
    $key = $tableName . '.' . $columnName;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = :db_name AND table_name = :table_name AND column_name = :column_name');
        $stmt->execute([
            'db_name' => DB_NAME,
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);

        $cache[$key] = (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $exception) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function client_code_prefix(string $clientName): string
{
    $normalized = preg_replace('/[^A-Za-z0-9\s]/', ' ', $clientName) ?? '';
    $words = preg_split('/\s+/', trim($normalized)) ?: [];
    $ignored = ['and', 'of', 'the', 'for', 'in', 'on', 'at', 'by', 'a', 'an'];
    $prefix = '';

    foreach ($words as $word) {
        $word = trim($word);
        if ($word === '' || in_array(strtolower($word), $ignored, true)) {
            continue;
        }
        $prefix .= strtoupper($word[0]);
    }

    return $prefix !== '' ? substr($prefix, 0, 10) : 'CL';
}

function generate_client_code(string $clientName): string
{
    $prefix = client_code_prefix($clientName);

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $stmt = db()->prepare('
            SELECT client_code
            FROM client_profiles
            WHERE client_code LIKE :prefix
            ORDER BY client_code DESC
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->execute(['prefix' => $prefix . '-%']);
        $lastCode = (string) ($stmt->fetchColumn() ?: '');
        $nextNumber = 1;

        if (preg_match('/-(\d+)$/', $lastCode, $matches)) {
            $nextNumber = (int) $matches[1] + 1;
        }

        $candidate = $prefix . '-' . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
        $exists = db()->prepare('SELECT COUNT(*) FROM client_profiles WHERE client_code = :code');
        $exists->execute(['code' => $candidate]);

        if ((int) $exists->fetchColumn() === 0) {
            return $candidate;
        }
    }

    return $prefix . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function upload_image_file(string $field, string $directory, ?string $existingPath = null): ?string
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existingPath;
    }

    if ((int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }

    $tmpPath = (string) ($_FILES[$field]['tmp_name'] ?? '');
    $size = (int) ($_FILES[$field]['size'] ?? 0);

    if ($size <= 0 || $size > 2 * 1024 * 1024 || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Upload an image under 2 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpPath);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Only JPG, PNG, and WEBP images are allowed.');
    }

    $uploadDir = __DIR__ . '/../public_html/' . trim($directory, '/');
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $fileName = $field . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
    $targetPath = $uploadDir . '/' . $fileName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('Could not save uploaded image.');
    }

    return trim($directory, '/') . '/' . $fileName;
}

function active_industries(): array
{
    if (!table_exists('industries')) {
        return [];
    }

    return db()->query('SELECT * FROM industries WHERE is_active = 1 ORDER BY name ASC')->fetchAll() ?: [];
}

function active_service_provider_entities(): array
{
    if (!table_exists('service_provider_entities')) {
        return [];
    }

    return db()->query('SELECT * FROM service_provider_entities WHERE is_active = 1 ORDER BY name ASC')->fetchAll() ?: [];
}

function client_service_provider_ids(int $clientId): array
{
    if (!table_exists('client_service_provider_entities')) {
        return [];
    }

    $stmt = db()->prepare('SELECT service_provider_entity_id FROM client_service_provider_entities WHERE client_id = :client_id');
    $stmt->execute(['client_id' => $clientId]);

    return array_map('intval', array_column($stmt->fetchAll(), 'service_provider_entity_id'));
}

function sync_client_service_providers(int $clientId, array $entityIds): void
{
    if (!table_exists('client_service_provider_entities')) {
        return;
    }

    $entityIds = array_values(array_unique(array_filter(array_map('intval', $entityIds), static fn (int $id): bool => $id > 0)));
    $delete = db()->prepare('DELETE FROM client_service_provider_entities WHERE client_id = :client_id');
    $delete->execute(['client_id' => $clientId]);

    if ($entityIds === []) {
        return;
    }

    $check = db()->prepare('SELECT id FROM service_provider_entities WHERE id = :id AND is_active = 1 LIMIT 1');
    $insert = db()->prepare('INSERT IGNORE INTO client_service_provider_entities (client_id, service_provider_entity_id) VALUES (:client_id, :entity_id)');

    foreach ($entityIds as $entityId) {
        $check->execute(['id' => $entityId]);
        if (!$check->fetch()) {
            continue;
        }

        $insert->execute([
            'client_id' => $clientId,
            'entity_id' => $entityId,
        ]);
    }
}

function client_service_provider_names(int $clientId): string
{
    if (!table_exists('client_service_provider_entities')) {
        return '';
    }

    $stmt = db()->prepare('
        SELECT spe.name
        FROM client_service_provider_entities cspe
        INNER JOIN service_provider_entities spe ON spe.id = cspe.service_provider_entity_id
        WHERE cspe.client_id = :client_id
        ORDER BY spe.name ASC
    ');
    $stmt->execute(['client_id' => $clientId]);

    return implode(', ', array_column($stmt->fetchAll(), 'name'));
}

function setting(string $key, mixed $default = ''): mixed
{
    static $settings = null;

    if ($settings === null) {
        $settings = [];
        $rows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();

        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    return $settings[$key] ?? $default;
}

function all_settings(): array
{
    $rows = db()->query('SELECT setting_key, setting_value FROM settings ORDER BY setting_key')->fetchAll();
    $settings = [];

    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    return $settings;
}

function update_settings(array $settings): void
{
    $stmt = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (:setting_key, :setting_value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');

    foreach ($settings as $key => $value) {
        $stmt->execute([
            'setting_key' => (string) $key,
            'setting_value' => (string) $value,
        ]);
    }
}

function plans(bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM plans';
    if ($onlyActive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';

    return db()->query($sql)->fetchAll();
}

function plan_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM plans WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);

    $plan = $stmt->fetch();

    return $plan ?: null;
}

function plan_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM plans WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $slug]);

    $plan = $stmt->fetch();

    return $plan ?: null;
}

function order_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT o.*, p.name AS plan_name, p.slug AS plan_slug FROM orders o INNER JOIN plans p ON p.id = o.plan_id WHERE o.id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);

    $order = $stmt->fetch();

    return $order ?: null;
}

function order_invoice_number(int $orderId): string
{
    return 'INV-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
}

function order_can_be_viewed(array $order): bool
{
    $user = current_user();

    if ($user && $user['role'] === 'admin') {
        return true;
    }

    if ($user && (int) $order['user_id'] === (int) $user['id']) {
        return true;
    }

    $sessionOrderId = (int) ($_SESSION['last_order_id'] ?? 0);

    return $sessionOrderId > 0 && $sessionOrderId === (int) $order['id'];
}

function payment_settings(): array
{
    return [
        'mode' => (string) setting('payment_mode', 'manual'),
        'label' => (string) setting('payment_label', 'Manual payment / bank transfer'),
        'bank_name' => (string) setting('bank_name', ''),
        'bank_account_name' => (string) setting('bank_account_name', ''),
        'bank_account_number' => (string) setting('bank_account_number', ''),
        'payment_note' => (string) setting('payment_note', ''),
        'stripe_checkout_url' => (string) setting('stripe_checkout_url', ''),
        'paypal_checkout_url' => (string) setting('paypal_checkout_url', ''),
    ];
}

function companies_list(bool $onlyActive = true): array
{
    if (!table_exists('companies')) {
        return [];
    }

    $sql = 'SELECT * FROM companies';
    if ($onlyActive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY name ASC';

    return db()->query($sql)->fetchAll();
}

function company_by_id(int $companyId): ?array
{
    if (!table_exists('companies')) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM companies WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $companyId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function company_by_code(string $code): ?array
{
    if (!table_exists('companies')) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM companies WHERE code = :code LIMIT 1');
    $stmt->execute(['code' => strtoupper(trim($code))]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function child_companies_for_company(int $companyId, bool $onlyActive = true): array
{
    if ($companyId <= 0 || !table_exists('companies')) {
        return [];
    }

    $sql = 'SELECT * FROM companies WHERE parent_company_id = :company_id';
    if ($onlyActive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY name ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute(['company_id' => $companyId]);

    return $stmt->fetchAll();
}

function fiscal_years_for_company(int $companyId, bool $onlyActive = true): array
{
    if (!table_exists('fiscal_years')) {
        return [];
    }

    $sql = 'SELECT * FROM fiscal_years WHERE company_id = :company_id';
    if ($onlyActive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY start_date DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute(['company_id' => $companyId]);

    return $stmt->fetchAll();
}

function fiscal_year_by_id(int $fiscalYearId): ?array
{
    if (!table_exists('fiscal_years')) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM fiscal_years WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $fiscalYearId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function set_context(int $companyId, int $fiscalYearId): void
{
    $_SESSION['company_id'] = $companyId;
    $_SESSION['fiscal_year_id'] = $fiscalYearId;
    // Remember the user's viewing selection PER COMPANY so switching between
    // companies restores each one's last-used fiscal year independently.
    // This is the user's selection for viewing — it never changes the
    // company-wide default (that's set_default_fiscal_year()).
    if ($fiscalYearId > 0) {
        $_SESSION['fy_selection'][$companyId] = $fiscalYearId;
    }
}

/**
 * The fiscal year the user last selected for this company (validated), or 0.
 */
function remembered_fiscal_year_id(int $companyId): int
{
    $remembered = (int) ($_SESSION['fy_selection'][$companyId] ?? 0);
    if ($remembered <= 0) {
        return 0;
    }
    $fy = fiscal_year_by_id($remembered);
    return ($fy && (int) $fy['company_id'] === $companyId) ? $remembered : 0;
}

// ---------------------------------------------------------------------------
// Fiscal-year lifecycle service. One canonical AD date pair (start_date /
// end_date) drives every comparison; BS labels are display-only.
// ---------------------------------------------------------------------------

/** Lifecycle status with a safe default for pre-migration rows. */
function fiscal_year_status(array $fiscalYear): string
{
    $status = (string) ($fiscalYear['status'] ?? '');
    return in_array($status, ['upcoming', 'open', 'closed', 'locked'], true) ? $status : 'open';
}

/** The company's fiscal year whose range contains the date, or null. */
function fiscal_year_for_date(int $companyId, string $date): ?array
{
    if ($companyId <= 0 || !table_exists('fiscal_years')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM fiscal_years WHERE company_id = :cid AND :d BETWEEN start_date AND end_date ORDER BY is_default DESC, start_date DESC LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'd' => $date]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Why the proposed period is invalid for this company, or null when valid.
 * Overlap rule: new_start <= existing_end AND new_end >= existing_start,
 * scoped by company — different companies may use identical periods.
 */
function fiscal_year_overlap_error(int $companyId, string $startDate, string $endDate, int $excludeId = 0): ?string
{
    $valid = static fn (string $d): bool => DateTimeImmutable::createFromFormat('Y-m-d', $d) instanceof DateTimeImmutable
        && DateTimeImmutable::createFromFormat('Y-m-d', $d)->format('Y-m-d') === $d;
    if (!$valid($startDate) || !$valid($endDate)) {
        return 'Start and end dates must be valid calendar dates (YYYY-MM-DD).';
    }
    if ($startDate >= $endDate) {
        return 'The start date must be before the end date.';
    }
    $stmt = db()->prepare('SELECT label, start_date, end_date FROM fiscal_years WHERE company_id = :cid AND id <> :exclude AND :new_start <= end_date AND :new_end >= start_date LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'exclude' => $excludeId, 'new_start' => $startDate, 'new_end' => $endDate]);
    $clash = $stmt->fetch();
    if ($clash) {
        return 'The period ' . $startDate . ' to ' . $endDate . ' overlaps fiscal year "' . $clash['label'] . '" (' . $clash['start_date'] . ' to ' . $clash['end_date'] . '). Fiscal years of one company must not overlap.';
    }
    return null;
}

/**
 * Creates a fiscal year with overlap protection and race-safe single-default,
 * inside one transaction (the company row lock serializes concurrent creates).
 *
 * @return array{ok: bool, error: ?string, id: int}
 */
function create_fiscal_year(int $companyId, string $label, string $startDate, string $endDate, bool $isDefault, ?int $actorId = null): array
{
    if ($companyId <= 0 || trim($label) === '') {
        return ['ok' => false, 'error' => 'Company and fiscal year label are required.', 'id' => 0];
    }
    try {
        db()->beginTransaction();
        // Serialize per company so two simultaneous requests cannot create
        // overlapping periods or two defaults.
        db()->prepare('SELECT id FROM companies WHERE id = :cid FOR UPDATE')->execute(['cid' => $companyId]);

        $overlapError = fiscal_year_overlap_error($companyId, $startDate, $endDate);
        if ($overlapError !== null) {
            db()->rollBack();
            return ['ok' => false, 'error' => $overlapError, 'id' => 0];
        }
        // Continuity: fiscal years must form an unbroken chain — the previous
        // year's closing IS the next year's opening, so a new year has to
        // touch an existing one (start = an existing end + 1 day, or end = an
        // existing start − 1 day). Filling a legacy gap satisfies both sides.
        // The company's very first year is free to start anywhere.
        $neighborStmt = db()->prepare('SELECT start_date, end_date FROM fiscal_years WHERE company_id = :cid');
        $neighborStmt->execute(['cid' => $companyId]);
        $neighbors = $neighborStmt->fetchAll();
        if ($neighbors !== []) {
            $adjacent = false;
            $latestEnd = null;
            foreach ($neighbors as $neighbor) {
                $afterNeighbor = date('Y-m-d', strtotime((string) $neighbor['end_date'] . ' +1 day'));
                $beforeNeighbor = date('Y-m-d', strtotime((string) $neighbor['start_date'] . ' -1 day'));
                if ($startDate === $afterNeighbor || $endDate === $beforeNeighbor) {
                    $adjacent = true;
                }
                if ($latestEnd === null || (string) $neighbor['end_date'] > $latestEnd) {
                    $latestEnd = (string) $neighbor['end_date'];
                }
            }
            if (!$adjacent) {
                db()->rollBack();
                return ['ok' => false, 'error' => 'Fiscal years must be continuous — the new year must start the day after an existing year ends (e.g. ' . date('Y-m-d', strtotime($latestEnd . ' +1 day')) . ') or end the day before one starts. Gaps between years are not allowed.', 'id' => 0];
            }
        }
        $duplicateLabel = db()->prepare('SELECT COUNT(*) FROM fiscal_years WHERE company_id = :cid AND label = :label');
        $duplicateLabel->execute(['cid' => $companyId, 'label' => trim($label)]);
        if ((int) $duplicateLabel->fetchColumn() > 0) {
            db()->rollBack();
            return ['ok' => false, 'error' => 'A fiscal year named "' . trim($label) . '" already exists for this company.', 'id' => 0];
        }

        if ($isDefault) {
            db()->prepare('UPDATE fiscal_years SET is_default = 0 WHERE company_id = :cid')->execute(['cid' => $companyId]);
        }
        $hasLifecycle = column_exists('fiscal_years', 'status');
        $status = $startDate > date('Y-m-d') ? 'upcoming' : 'open';
        $sql = 'INSERT INTO fiscal_years (company_id, label, start_date, end_date, is_active, is_default'
            . ($hasLifecycle ? ', status, created_by' : '')
            . ') VALUES (:cid, :label, :start_date, :end_date, 1, :is_default'
            . ($hasLifecycle ? ', :status, :created_by' : '') . ')';
        $params = [
            'cid' => $companyId, 'label' => trim($label),
            'start_date' => $startDate, 'end_date' => $endDate,
            'is_default' => $isDefault ? 1 : 0,
        ];
        if ($hasLifecycle) {
            $params['status'] = $status;
            $params['created_by'] = $actorId ?: null;
        }
        db()->prepare($sql)->execute($params);
        $newId = (int) db()->lastInsertId();
        db()->commit();
        log_activity('fiscal_year', $newId, 'created', 'Fiscal year "' . trim($label) . '" (' . $startDate . ' to ' . $endDate . ', ' . $status . ($isDefault ? ', default' : '') . ') created.', $actorId);
        return ['ok' => true, 'error' => null, 'id' => $newId];
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not create the fiscal year: ' . $exception->getMessage(), 'id' => 0];
    }
}

/**
 * Makes one fiscal year the company-wide default — transactional, so exactly
 * one default can exist per company even under concurrent requests.
 *
 * @return array{ok: bool, error: ?string}
 */
function set_default_fiscal_year(int $companyId, int $fiscalYearId, ?int $actorId = null): array
{
    try {
        db()->beginTransaction();
        db()->prepare('SELECT id FROM companies WHERE id = :cid FOR UPDATE')->execute(['cid' => $companyId]);
        $fyStmt = db()->prepare('SELECT * FROM fiscal_years WHERE id = :id AND company_id = :cid LIMIT 1');
        $fyStmt->execute(['id' => $fiscalYearId, 'cid' => $companyId]);
        $fy = $fyStmt->fetch();
        if (!$fy) {
            db()->rollBack();
            return ['ok' => false, 'error' => 'Fiscal year not found for this company.'];
        }
        db()->prepare('UPDATE fiscal_years SET is_default = 0 WHERE company_id = :cid')->execute(['cid' => $companyId]);
        $updateSql = 'UPDATE fiscal_years SET is_default = 1' . (column_exists('fiscal_years', 'updated_by') ? ', updated_by = :uid' : '') . ' WHERE id = :id';
        $params = ['id' => $fiscalYearId] + (column_exists('fiscal_years', 'updated_by') ? ['uid' => $actorId ?: null] : []);
        db()->prepare($updateSql)->execute($params);
        db()->commit();
        log_activity('fiscal_year', $fiscalYearId, 'default_changed', 'Fiscal year "' . $fy['label'] . '" set as the company default.', $actorId);
        return ['ok' => true, 'error' => null];
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not change the default fiscal year: ' . $exception->getMessage()];
    }
}

/**
 * Why a transaction dated $date cannot post into this fiscal year, or null.
 * Covers: date outside the year's range, lifecycle status, and the cutoff
 * date (fiscal_period_locks). The error names the date, the year, and its
 * allowed range so the user can see exactly why posting was rejected.
 */
function fiscal_year_posting_blocker(array $fiscalYear, string $date): ?string
{
    $label = (string) ($fiscalYear['label'] ?? '#' . (int) ($fiscalYear['id'] ?? 0));
    $start = (string) ($fiscalYear['start_date'] ?? '');
    $end = (string) ($fiscalYear['end_date'] ?? '');
    $status = fiscal_year_status($fiscalYear);
    if ($status === 'closed' || $status === 'locked') {
        return 'Fiscal year ' . $label . ' (' . $start . ' to ' . $end . ') is ' . $status . ' — accounting changes are not allowed. Reports remain available.';
    }
    if ($status === 'upcoming') {
        return 'Fiscal year ' . $label . ' (' . $start . ' to ' . $end . ') is upcoming — open it before posting transactions.';
    }
    if ($start !== '' && $end !== '' && ($date < $start || $date > $end)) {
        return 'Transaction date ' . $date . ' is outside fiscal year ' . $label . ' (allowed: ' . $start . ' to ' . $end . ').';
    }
    if (is_period_locked((int) ($fiscalYear['company_id'] ?? 0), (int) ($fiscalYear['id'] ?? 0), $date)) {
        $cutoff = period_locked_through((int) ($fiscalYear['company_id'] ?? 0), (int) ($fiscalYear['id'] ?? 0));
        return 'Transaction date ' . $date . ' is on or before the cutoff date ' . (string) $cutoff . ' of fiscal year ' . $label . ' — the period is locked.';
    }
    return null;
}

function set_selected_company(int $companyId): void
{
    $_SESSION['selected_company_id'] = $companyId;
}

function selected_company_id(): int
{
    return (int) ($_SESSION['selected_company_id'] ?? 0);
}

function clear_selected_company(): void
{
    unset($_SESSION['selected_company_id']);
}

function mark_company_pin_verified(int $companyId): void
{
    $_SESSION['verified_company_id'] = $companyId;
}

function verified_company_id(): int
{
    return (int) ($_SESSION['verified_company_id'] ?? 0);
}

function clear_company_pin_verification(): void
{
    unset($_SESSION['verified_company_id']);
}

function company_pin_verified(int $companyId): bool
{
    return verified_company_id() === $companyId;
}

function current_company_id(): int
{
    return (int) ($_SESSION['company_id'] ?? 0);
}

function current_fiscal_year_id(): int
{
    return (int) ($_SESSION['fiscal_year_id'] ?? 0);
}

function current_company(): ?array
{
    $companyId = current_company_id();
    if ($companyId <= 0) {
        return null;
    }

    return company_by_id($companyId);
}

function current_fiscal_year(): ?array
{
    $fiscalYearId = current_fiscal_year_id();
    if ($fiscalYearId <= 0) {
        return null;
    }

    return fiscal_year_by_id($fiscalYearId);
}

function company_accounting_preferences_row(?int $companyId = null): ?array
{
    $companyId = $companyId ?? current_company_id();
    if ($companyId <= 0 || !table_exists('company_accounting_preferences')) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM company_accounting_preferences WHERE company_id = :company_id LIMIT 1');
    $stmt->execute(['company_id' => $companyId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function company_accounting_business_type(?int $companyId = null): string
{
    $preferences = company_accounting_preferences_row($companyId);
    $businessType = (string) ($preferences['business_type'] ?? 'service');

    return in_array($businessType, ['service', 'trading', 'manufacturing'], true) ? $businessType : 'service';
}

function company_accounting_default_excise_rate(?int $companyId = null): float
{
    $preferences = company_accounting_preferences_row($companyId);
    $rate = (float) ($preferences['default_excise_rate'] ?? 0);

    return max(0.0, min(100.0, $rate));
}

function accounting_business_profile(?string $businessType = null): array
{
    $businessType = in_array((string) $businessType, ['service', 'trading', 'manufacturing'], true)
        ? (string) $businessType
        : company_accounting_business_type();

    $isService = $businessType === 'service';
    $isTrading = $businessType === 'trading';
    $isManufacturing = $businessType === 'manufacturing';

    // Every entity gets the full accounting toolkit — Inventory, Manufacturing
    // and Fixed Assets are always available, so no one has to pick a "business
    // type" just to unlock features. The type is now only a reporting hint: it
    // sets the default invoice source and the friendly label; a company that
    // does not want to track inventory simply ignores the module.
    return [
        'business_type' => $businessType,
        'label' => $isManufacturing ? 'Manufacturing' : ($isTrading ? 'Trading' : 'Service'),
        'show_inventory' => true,
        'show_manufacturing' => true,
        'show_excise' => true,
        'show_quantity_rate' => true,
        'allowed_invoice_sources' => ['task', 'inventory', 'manufacturing', 'other'],
        'default_invoice_source' => $isService ? 'task' : ($isTrading ? 'inventory' : 'manufacturing'),
        'report_business_slices' => ['all', 'service', 'trading', 'manufacturing'],
    ];
}

function company_pin_hash_by_id(int $companyId): ?string
{
    if ($companyId <= 0 || !table_exists('companies')) {
        return null;
    }

    $stmt = db()->prepare('SELECT admin_pin_hash FROM companies WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $companyId]);
    $row = $stmt->fetch();

    $hash = (string) ($row['admin_pin_hash'] ?? '');
    return $hash !== '' ? $hash : null;
}

function company_pin_is_set(int $companyId): bool
{
    return company_pin_hash_by_id($companyId) !== null;
}

function handle_company_switch(int $companyId, string $failureUrl): never
{
    $failureUrl = $failureUrl !== '' ? $failureUrl : 'portal.php';

    if ($companyId <= 0) {
        flash('error', 'Select a company to continue.');
        redirect($failureUrl);
    }

    $company = company_by_id($companyId);
    if (!$company || (int) ($company['is_active'] ?? 0) !== 1) {
        flash('error', 'Selected company is not available.');
        redirect($failureUrl);
    }

    // Authorization gate: the only thing that used to stand between an admin
    // and an arbitrary company was the 4-digit PIN (and if none was set, they
    // were invited to set it). Now membership/hierarchy is checked first, so a
    // service-provider admin cannot open M.Bista's or a rival provider's books.
    if (!user_can_access_company($companyId)) {
        security_event('company_switch', 'denied', 'Attempted to open company #' . $companyId . ' without membership.', $companyId);
        flash('error', 'You are not authorized to open that organization.');
        redirect($failureUrl);
    }

    // Client books companies open PIN-free for admins with direct access, and
    // for staff accountants the admin assigned/granted to that client
    // ('approval' level) — every voucher a staff member enters there is forced
    // to pending_approval and flagged for the client, so opening the workspace
    // itself is safe.
    if ((int) ($company['is_client_company'] ?? 0) === 1) {
        $bookProfileStmt = db()->prepare('SELECT * FROM client_profiles WHERE books_company_id = :cid LIMIT 1');
        $bookProfileStmt->execute(['cid' => $companyId]);
        $bookProfile = $bookProfileStmt->fetch();
        $bookAccessLevel = $bookProfile ? client_books_access_level($bookProfile) : '';
        if (!in_array($bookAccessLevel, ['direct', 'approval'], true)) {
            flash('error', 'You do not have access to the books of that client.');
            redirect($failureUrl);
        }
        if (!activate_company_context($companyId, true)) {
            flash('error', 'No active fiscal year is available for the books of this client.');
            redirect($failureUrl);
        }
        flash('success', 'Opened full accounting for ' . ($bookProfile['organization_name'] ?? 'client') . '.'
            . ($bookAccessLevel === 'approval' ? ' Entries you make here are sent to the client and admin for approval.' : ''));
        redirect('admin/accounting-dashboard.php');
    }

    if ((string) ($company['code'] ?? '') === 'MBAACA') {
        if (!activate_company_context($companyId, true)) {
            flash('error', 'No active fiscal year is available for M.Bista and Associates.');
            redirect($failureUrl);
        }

        flash('success', 'M.Bista superadmin portal opened.');
        redirect('admin/index.php');
    }

    if (!company_pin_is_set($companyId)) {
        flash('error', 'Set a 4-digit PIN for this company before opening its portal.');
        redirect('admin/company-pin-reset.php?company_id=' . $companyId);
    }

    set_selected_company($companyId);
    clear_company_pin_verification();
    redirect('admin/company-pin.php?company_id=' . $companyId);
}

function verify_company_pin(int $companyId, string $pin): bool
{
    $hash = company_pin_hash_by_id($companyId);
    if ($hash === null) {
        return false;
    }

    return password_verify($pin, $hash);
}

function update_company_pin(int $companyId, string $pin): bool
{
    if ($companyId <= 0 || !table_exists('companies')) {
        return false;
    }

    $stmt = db()->prepare('UPDATE companies SET admin_pin_hash = :admin_pin_hash, admin_pin_reset_token_hash = NULL, admin_pin_reset_expires_at = NULL, admin_pin_reset_used_at = NULL WHERE id = :id');
    return $stmt->execute([
        'admin_pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
        'id' => $companyId,
    ]);
}

function activate_company_context(int $companyId, bool $markVerified = false): bool
{
    $company = company_by_id($companyId);
    if (!$company || (int) ($company['is_active'] ?? 0) !== 1 || !table_exists('fiscal_years')) {
        return false;
    }

    // The user's remembered per-company selection wins over the company
    // default, so switching companies restores each one's last-used year.
    $fiscalYearId = remembered_fiscal_year_id($companyId);
    if ($fiscalYearId <= 0) {
        $fyStmt = db()->prepare('SELECT * FROM fiscal_years WHERE company_id = :company_id AND is_active = 1 ORDER BY is_default DESC, start_date DESC LIMIT 1');
        $fyStmt->execute(['company_id' => $companyId]);
        $fiscalYear = $fyStmt->fetch();
        if (!$fiscalYear) {
            return false;
        }
        $fiscalYearId = (int) $fiscalYear['id'];
    }

    set_selected_company($companyId);
    set_context($companyId, $fiscalYearId);

    if ($markVerified) {
        mark_company_pin_verified($companyId);
    }

    return true;
}

function resolve_default_company_and_fiscal_year(): ?array
{
    if (!table_exists('companies') || !table_exists('fiscal_years')) {
        return null;
    }

    $companyStmt = db()->query('SELECT * FROM companies WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
    $company = $companyStmt->fetch();
    if (!$company) {
        return null;
    }

    $fyStmt = db()->prepare('SELECT * FROM fiscal_years WHERE company_id = :company_id AND is_active = 1 ORDER BY is_default DESC, start_date DESC LIMIT 1');
    $fyStmt->execute(['company_id' => (int) $company['id']]);
    $fiscalYear = $fyStmt->fetch();
    if (!$fiscalYear) {
        return null;
    }

    return [
        'company' => $company,
        'fiscal_year' => $fiscalYear,
    ];
}

function ensure_context_selected(): bool
{
    $company = current_company();
    $fiscalYear = current_fiscal_year();
    if ($company && $fiscalYear && (int) $fiscalYear['company_id'] === (int) $company['id']) {
        return true;
    }

    $default = resolve_default_company_and_fiscal_year();
    if (!$default) {
        return false;
    }

    set_context((int) $default['company']['id'], (int) $default['fiscal_year']['id']);

    return true;
}

function ensure_company_context_selected(): bool
{
    $company = current_company();
    if (!$company) {
        return false;
    }

    $fiscalYear = current_fiscal_year();
    if ($fiscalYear && (int) $fiscalYear['company_id'] === (int) $company['id']) {
        return true;
    }

    if (!table_exists('fiscal_years')) {
        return false;
    }

    $rememberedId = remembered_fiscal_year_id((int) $company['id']);
    if ($rememberedId > 0) {
        set_context((int) $company['id'], $rememberedId);
        return true;
    }

    $stmt = db()->prepare('SELECT * FROM fiscal_years WHERE company_id = :company_id AND is_active = 1 ORDER BY is_default DESC, start_date DESC LIMIT 1');
    $stmt->execute(['company_id' => (int) $company['id']]);
    $fiscalYear = $stmt->fetch();
    if (!$fiscalYear) {
        return false;
    }

    set_context((int) $company['id'], (int) $fiscalYear['id']);

    return true;
}

function require_company_context(): void
{
    if (!ensure_company_context_selected() || !company_pin_verified(current_company_id())) {
        flash('error', 'Select a company and enter the 4-digit PIN to continue.');
        redirect('portal.php');
    }
}

function admin_totals(): array
{
    return [
        'pending_orders' => (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn(),
        'paid_orders' => (int) db()->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'paid'")->fetchColumn(),
        'new_contacts' => (int) db()->query("SELECT COUNT(*) FROM contacts WHERE status = 'new'")->fetchColumn(),
        'active_customers' => (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'customer' AND status = 'active'")->fetchColumn(),
    ];
}

function stats_summary(): array
{
    return [
        'plans' => (int) db()->query('SELECT COUNT(*) FROM plans WHERE is_active = 1')->fetchColumn(),
        'orders' => (int) db()->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
        'contacts' => (int) db()->query('SELECT COUNT(*) FROM contacts')->fetchColumn(),
        'users' => (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    ];
}

function recent_orders(int $limit = 5): array
{
    $stmt = db()->prepare('SELECT o.*, p.name AS plan_name FROM orders o INNER JOIN plans p ON p.id = o.plan_id ORDER BY o.created_at DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function orders_for_user(int $userId): array
{
    $stmt = db()->prepare('SELECT o.*, p.name AS plan_name FROM orders o INNER JOIN plans p ON p.id = o.plan_id WHERE o.user_id = :user_id ORDER BY o.created_at DESC');
    $stmt->execute(['user_id' => $userId]);

    return $stmt->fetchAll();
}

function contacts_list(int $limit = 20): array
{
    $stmt = db()->prepare('SELECT * FROM contacts ORDER BY created_at DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function users_list(int $limit = 50): array
{
    $stmt = db()->prepare('SELECT id, name, email, role, status, phone, company, created_at FROM users ORDER BY created_at DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $passwordChangeSelect = column_exists('users', 'must_change_password') ? ', must_change_password' : '';
    $accessLevelSelect = column_exists('users', 'access_level') ? ', access_level' : '';
    $sessionsValidSelect = column_exists('users', 'sessions_valid_from') ? ', sessions_valid_from' : '';
    $stmt = db()->prepare('SELECT id, name, email, role, status, company_id, phone, company, created_at' . $passwordChangeSelect . $accessLevelSelect . $sessionsValidSelect . ' FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $_SESSION['user_id']]);

    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    // Enforce lifecycle + session revocation on EVERY request, not just at
    // login: a user suspended/deactivated mid-session, or whose sessions were
    // revoked after a permission change, loses access immediately.
    if (!user_status_allows_login($user) || session_is_revoked($user)) {
        $reason = !user_status_allows_login($user) ? 'status:' . ($user['status'] ?? '?') : 'session revoked';
        security_event('session_terminated', 'denied', 'Session ended mid-request (' . $reason . ').', null, (int) $user['id']);
        destroy_session();
        return null;
    }

    return $user;
}

function user_by_email(string $email): ?array
{
    $passwordChangeSelect = column_exists('users', 'must_change_password') ? ', must_change_password' : '';
    $stmt = db()->prepare('SELECT id, name, email, password_hash, role, status, company_id, phone, company, created_at' . $passwordChangeSelect . ' FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);

    $user = $stmt->fetch();

    return $user ?: null;
}

function login_user(string $email, string $password): bool
{
    $user = user_by_email($email);

    if (!$user || !user_status_allows_login($user) || !password_verify($password, $user['password_hash'])) {
        // Record the failure for throttling + the security log, but do not
        // reveal which of the two conditions failed to the caller.
        security_event('login_failed', 'failure', 'Invalid credentials or inactive account.', null, $user['id'] ?? null, $email);
        return false;
    }

    // Prevent session fixation: issue a fresh id the moment identity changes.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    // session_regenerate_id() rotates the id but keeps the session's data, so a
    // company context left behind by a previous identity would survive into this
    // one — enough to walk straight past portal.php's PIN gate into someone
    // else's organization. A new identity always starts with no tenant context.
    clear_company_pin_verification();
    clear_selected_company();
    unset($_SESSION['company_id'], $_SESSION['fiscal_year_id']);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['auth_issued_at'] = time();

    security_event('login', 'success', 'User authenticated.', (int) ($user['company_id'] ?? 0) ?: null, (int) $user['id'], $email);

    return true;
}

function role_home_path(?array $user = null): string
{
    $user = $user ?? current_user();
    if (!$user) {
        return 'login.php';
    }

    $role = (string) ($user['role'] ?? 'customer');
    if ($role === 'customer' && !empty($user['must_change_password'])) {
        return 'change-password.php';
    }
    if ($role === 'admin') {
        return 'portal.php';
    }
    if ($role === 'staff') {
        return 'staff/index.php';
    }

    return 'dashboard.php';
}

function create_user(array $data): int
{
    $mustChangeColumn = column_exists('users', 'must_change_password') ? ', must_change_password' : '';
    $mustChangeValue = column_exists('users', 'must_change_password') ? ', :must_change_password' : '';
    $accessLevelColumn = column_exists('users', 'access_level') ? ', access_level' : '';
    $accessLevelValue = column_exists('users', 'access_level') ? ', :access_level' : '';
    $stmt = db()->prepare('INSERT INTO users (name, email, password_hash, role, status, company_id, phone, company' . $mustChangeColumn . $accessLevelColumn . ') VALUES (:name, :email, :password_hash, :role, :status, :company_id, :phone, :company' . $mustChangeValue . $accessLevelValue . ')');
    $params = [
        'name' => $data['name'],
        'email' => $data['email'],
        'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
        'role' => $data['role'] ?? 'customer',
        'status' => $data['status'] ?? 'active',
        'company_id' => $data['company_id'] ?? null,
        'phone' => $data['phone'] ?? null,
        'company' => $data['company'] ?? null,
    ];
    if (column_exists('users', 'must_change_password')) {
        $params['must_change_password'] = !empty($data['must_change_password']) ? 1 : 0;
    }
    if (column_exists('users', 'access_level')) {
        $accessLevel = (string) ($data['access_level'] ?? '');
        $params['access_level'] = array_key_exists($accessLevel, ACCESS_LEVELS) ? $accessLevel : default_access_level_for_role((string) ($data['role'] ?? 'customer'));
    }
    $stmt->execute($params);

    return (int) db()->lastInsertId();
}

function logout_user(): void
{
    if (!empty($_SESSION['user_id'])) {
        security_event('logout', 'success', 'User logged out.', current_company_id() ?: null, (int) $_SESSION['user_id']);
    }
    destroy_session();
}

/**
 * Fully tears down the session: clears all context (company, PIN verification,
 * fiscal year, CSRF, flash), regenerates the id, and drops the cookie. Used by
 * logout and by mid-request termination of suspended/revoked sessions.
 */
function destroy_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies') && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function request_password_reset(string $email, ?string $requestIp = null): ?string
{
    $user = user_by_email($email);
    if (!$user || $user['status'] !== 'active') {
        return null;
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $cleanupStmt = db()->prepare('DELETE FROM password_resets WHERE user_id = :user_id AND used_at IS NULL');
    $cleanupStmt->execute(['user_id' => (int) $user['id']]);

    $stmt = db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip) VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR), :requested_ip)');
    $stmt->execute([
        'user_id' => (int) $user['id'],
        'token_hash' => $tokenHash,
        'requested_ip' => $requestIp,
    ]);

    log_activity('user', (int) $user['id'], 'password_reset_requested', 'Password reset requested.', null);

    return $token;
}

function password_reset_user_by_token(string $token): ?array
{
    if (strlen($token) < 32) {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $stmt = db()->prepare('SELECT pr.id, pr.user_id, pr.token_hash, pr.expires_at, pr.used_at, u.email, u.status FROM password_resets pr INNER JOIN users u ON u.id = pr.user_id WHERE pr.used_at IS NULL AND pr.expires_at >= NOW() ORDER BY pr.id DESC');
    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        if (hash_equals((string) $row['token_hash'], $tokenHash)) {
            if (($row['status'] ?? 'inactive') !== 'active') {
                return null;
            }

            return [
                'reset_id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'email' => (string) $row['email'],
            ];
        }
    }

    return null;
}

function reset_password_by_token(string $token, string $newPassword): bool
{
    $resetRow = password_reset_user_by_token($token);
    if (!$resetRow) {
        return false;
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $updateUser = db()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
    $updateUser->execute([
        'password_hash' => $passwordHash,
        'id' => (int) $resetRow['user_id'],
    ]);

    $markUsed = db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id');
    $markUsed->execute(['id' => (int) $resetRow['reset_id']]);

    log_activity('user', (int) $resetRow['user_id'], 'password_reset_completed', 'Password reset completed.', null);

    return true;
}

function require_login(): void
{
    $user = current_user();
    if (!$user) {
        flash('error', 'Please log in to continue.');
        redirect('login.php');
    }

    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (($user['role'] ?? '') === 'customer' && !empty($user['must_change_password']) && !in_array($script, ['change-password.php', 'logout.php'], true)) {
        flash('error', 'Change your temporary password to continue.');
        redirect('change-password.php');
    }
}

function require_admin(): void
{
    $user = current_user();

    if (!$user || $user['role'] !== 'admin') {
        flash('error', 'Admin access required.');
        redirect('login.php');
    }
}

function require_staff_or_admin(): void
{
    $user = current_user();
    if (!$user || !in_array((string) $user['role'], ['admin', 'staff'], true)) {
        flash('error', 'Staff or admin access required.');
        redirect('login.php');
    }
}

function staff_scoped_client_ids(int $staffId, int $companyId): array
{
    if (!table_exists('client_profiles')) {
        return [];
    }

    $myTeamIds = [];
    if (table_exists('team_members')) {
        $stmt = db()->prepare('SELECT team_id FROM team_members WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $staffId]);
        $myTeamIds = array_map('intval', array_column($stmt->fetchAll(), 'team_id'));
    }

    $where = 'cp.company_id = ? AND (0 = 1';
    $bindings = [$companyId];
    if (column_exists('client_tasks', 'assigned_staff_user_id')) {
        $where .= ' OR EXISTS (SELECT 1 FROM client_tasks ct WHERE ct.client_id = cp.id AND ct.assigned_staff_user_id = ?)';
        $bindings[] = $staffId;
        if (column_exists('task_stages', 'assigned_staff_user_id')) {
            $where .= ' OR EXISTS (SELECT 1 FROM task_stages ts INNER JOIN client_tasks ct2 ON ct2.id = ts.task_id WHERE ct2.client_id = cp.id AND ts.assigned_staff_user_id = ?)';
            $bindings[] = $staffId;
        }
    }
    if (table_exists('client_task_assignees')) {
        $where .= ' OR EXISTS (SELECT 1 FROM client_task_assignees cta INNER JOIN client_tasks ct4 ON ct4.id = cta.task_id WHERE ct4.client_id = cp.id AND cta.user_id = ?)';
        $bindings[] = $staffId;
    }
    if ($myTeamIds !== []) {
        $teamPlaceholders = implode(',', array_fill(0, count($myTeamIds), '?'));
        $where .= " OR EXISTS (SELECT 1 FROM client_tasks ct3 WHERE ct3.client_id = cp.id AND ct3.team_id IN ($teamPlaceholders))";
        $bindings = array_merge($bindings, $myTeamIds);
    }
    $where .= ')';

    $stmt = db()->prepare("SELECT cp.id FROM client_profiles cp WHERE $where");
    $stmt->execute($bindings);

    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}

function task_assignee_ids(int $taskId): array
{
    if (!table_exists('client_task_assignees')) {
        return [];
    }

    $stmt = db()->prepare('SELECT user_id FROM client_task_assignees WHERE task_id = :task_id ORDER BY is_lead DESC, id ASC');
    $stmt->execute(['task_id' => $taskId]);

    return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
}

/**
 * Map of task_id => list of assignee rows (user_id, name, is_lead) for the given tasks.
 */
function task_assignees_by_task(array $taskIds): array
{
    $taskIds = array_values(array_filter(array_map('intval', $taskIds), static fn (int $id): bool => $id > 0));
    if ($taskIds === [] || !table_exists('client_task_assignees')) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
    $stmt = db()->prepare("SELECT cta.task_id, cta.user_id, cta.is_lead, u.name
        FROM client_task_assignees cta
        INNER JOIN users u ON u.id = cta.user_id
        WHERE cta.task_id IN ($placeholders)
        ORDER BY cta.is_lead DESC, cta.id ASC");
    $stmt->execute($taskIds);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['task_id']][] = [
            'user_id' => (int) $row['user_id'],
            'name' => (string) $row['name'],
            'is_lead' => (int) $row['is_lead'] === 1,
        ];
    }

    return $map;
}

/**
 * Replace the assignee set of a task with $userIds (first entry becomes lead).
 * Keeps the legacy client_tasks.assigned_staff_user_id column in sync and
 * records assigned/removed events in task_assignment_events.
 */
function sync_task_assignees(int $taskId, int $companyId, array $userIds, ?int $actorId = null): void
{
    if (!table_exists('client_task_assignees')) {
        // Legacy fallback: single-staff column only.
        if (column_exists('client_tasks', 'assigned_staff_user_id')) {
            $firstId = 0;
            foreach ($userIds as $candidate) {
                if ((int) $candidate > 0) {
                    $firstId = (int) $candidate;
                    break;
                }
            }
            db()->prepare('UPDATE client_tasks SET assigned_staff_user_id = :staff_id WHERE id = :id')
                ->execute(['staff_id' => $firstId > 0 ? $firstId : null, 'id' => $taskId]);
        }
        return;
    }

    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
    $currentIds = task_assignee_ids($taskId);

    $toAdd = array_diff($userIds, $currentIds);
    $toRemove = array_diff($currentIds, $userIds);

    $insertStmt = db()->prepare('INSERT IGNORE INTO client_task_assignees (company_id, task_id, user_id, is_lead, assigned_by) VALUES (:company_id, :task_id, :user_id, :is_lead, :assigned_by)');
    foreach ($toAdd as $userId) {
        $insertStmt->execute([
            'company_id' => $companyId,
            'task_id' => $taskId,
            'user_id' => $userId,
            'is_lead' => 0,
            'assigned_by' => $actorId,
        ]);
    }
    if ($toRemove !== []) {
        $removePlaceholders = implode(',', array_fill(0, count($toRemove), '?'));
        $deleteStmt = db()->prepare("DELETE FROM client_task_assignees WHERE task_id = ? AND user_id IN ($removePlaceholders)");
        $deleteStmt->execute(array_merge([$taskId], array_values($toRemove)));
    }

    $leadId = $userIds[0] ?? 0;
    db()->prepare('UPDATE client_task_assignees SET is_lead = CASE WHEN user_id = :lead_id THEN 1 ELSE 0 END WHERE task_id = :task_id')
        ->execute(['lead_id' => $leadId, 'task_id' => $taskId]);
    if (column_exists('client_tasks', 'assigned_staff_user_id')) {
        db()->prepare('UPDATE client_tasks SET assigned_staff_user_id = :staff_id WHERE id = :id')
            ->execute(['staff_id' => $leadId > 0 ? $leadId : null, 'id' => $taskId]);
    }

    if (table_exists('task_assignment_events')) {
        $eventStmt = db()->prepare('INSERT INTO task_assignment_events (company_id, task_id, event_type, from_user_id, to_user_id, created_by) VALUES (:company_id, :task_id, :event_type, :from_user_id, :to_user_id, :created_by)');
        foreach ($toAdd as $userId) {
            $eventStmt->execute([
                'company_id' => $companyId,
                'task_id' => $taskId,
                'event_type' => 'assigned',
                'from_user_id' => null,
                'to_user_id' => $userId,
                'created_by' => $actorId,
            ]);
        }
        foreach ($toRemove as $userId) {
            $eventStmt->execute([
                'company_id' => $companyId,
                'task_id' => $taskId,
                'event_type' => 'removed',
                'from_user_id' => $userId,
                'to_user_id' => null,
                'created_by' => $actorId,
            ]);
        }
    }
}

/**
 * Plain-text progress snapshot of a task: status, stage completion, and
 * billing position. Used as the handoff note when staff are replaced.
 */
function task_progress_summary_text(int $taskId): string
{
    $taskStmt = db()->prepare('SELECT t.*, cp.organization_name FROM client_tasks t INNER JOIN client_profiles cp ON cp.id = t.client_id WHERE t.id = :id LIMIT 1');
    $taskStmt->execute(['id' => $taskId]);
    $task = $taskStmt->fetch();
    if (!$task) {
        return '';
    }

    $lines = [];
    $lines[] = 'Task #' . $taskId . ' "' . (string) $task['title'] . '" for ' . (string) $task['organization_name'];
    $lines[] = 'Status: ' . str_replace('_', ' ', (string) $task['status']) . ' | Priority: ' . (string) $task['priority']
        . ($task['due_date'] ? ' | Due: ' . (string) $task['due_date'] : '');

    if (table_exists('task_stages')) {
        $stageStmt = db()->prepare('SELECT * FROM task_stages WHERE task_id = :task_id ORDER BY sequence_no ASC, id ASC');
        $stageStmt->execute(['task_id' => $taskId]);
        $stages = $stageStmt->fetchAll();
        if ($stages !== []) {
            $completed = count(array_filter($stages, static fn (array $s): bool => ($s['status'] ?? '') === 'completed'));
            $percent = (int) round(($completed / count($stages)) * 100);
            $lines[] = 'Progress: ' . $completed . ' of ' . count($stages) . ' stages completed (' . $percent . '%).';
            foreach ($stages as $stage) {
                $stageLine = ' - Stage ' . (int) $stage['sequence_no'] . ': ' . (string) $stage['stage_name']
                    . ' [' . str_replace('_', ' ', (string) $stage['status']) . ']';
                if (!empty($stage['completed_at'])) {
                    $stageLine .= ' completed ' . (string) $stage['completed_at'];
                }
                $lines[] = $stageLine;
            }
        } else {
            $lines[] = 'Progress: no stages defined; task status is ' . str_replace('_', ' ', (string) $task['status']) . '.';
        }
    }

    if (table_exists('task_invoices')) {
        $invoicedStmt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM task_invoices WHERE task_id = :task_id AND status <> 'cancelled'");
        $invoicedStmt->execute(['task_id' => $taskId]);
        $invoiced = (float) $invoicedStmt->fetchColumn();
        $lines[] = 'Billing: ' . number_format($invoiced, 2) . ' invoiced of ' . number_format((float) $task['quoted_fee'], 2) . ' quoted.';
    }

    if (!empty($task['description'])) {
        $lines[] = 'Description: ' . (string) $task['description'];
    }

    return implode("\n", $lines);
}

/**
 * Deliver the work-progress handoff to a newly assigned staff member as a
 * message thread. Failures are swallowed so the reassignment itself never
 * rolls back because messaging is unavailable.
 */
function notify_task_handoff(int $companyId, int $taskId, string $taskTitle, int $newStaffId, int $actorId, string $summary, string $note = ''): void
{
    if (!table_exists('message_threads') || !table_exists('message_thread_participants') || !table_exists('messages')) {
        return;
    }

    $body = "You have been assigned to take over this task. Current work progress:\n\n" . $summary;
    if ($note !== '') {
        $body .= "\n\nHandoff note: " . $note;
    }

    try {
        create_message_thread($companyId, null, 'Task handoff: #' . $taskId . ' ' . $taskTitle, $actorId, $body, [], [$newStaffId]);
    } catch (Throwable $exception) {
        // Messaging is best-effort; the assignment event still records the summary.
    }
}

function admin_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
}

function create_order(array $data): int
{
    $stmt = db()->prepare('INSERT INTO orders (company_id, user_id, plan_id, full_name, email, phone, domain_name, billing_cycle, amount, payment_method, payment_status, transaction_id, status, notes) VALUES (:company_id, :user_id, :plan_id, :full_name, :email, :phone, :domain_name, :billing_cycle, :amount, :payment_method, :payment_status, :transaction_id, :status, :notes)');
    $stmt->execute([
        'company_id' => $data['company_id'] ?? null,
        'user_id' => $data['user_id'],
        'plan_id' => $data['plan_id'],
        'full_name' => $data['full_name'],
        'email' => $data['email'],
        'phone' => $data['phone'],
        'domain_name' => $data['domain_name'],
        'billing_cycle' => $data['billing_cycle'],
        'amount' => $data['amount'],
        'payment_method' => $data['payment_method'] ?? 'manual',
        'payment_status' => $data['payment_status'] ?? 'pending',
        'transaction_id' => $data['transaction_id'] ?? null,
        'status' => $data['status'] ?? 'pending',
        'notes' => $data['notes'] ?? null,
    ]);

    return (int) db()->lastInsertId();
}

function ledger_by_code(int $companyId, string $code): ?array
{
    if (!table_exists('ledgers')) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM ledgers WHERE company_id = :company_id AND code = :code LIMIT 1');
    $stmt->execute([
        'company_id' => $companyId,
        'code' => $code,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Resolve a company-specific accounting role to a ledger.
 *
 * Legacy ledger codes remain as a fallback so automated posting continues to
 * work before the mapping migration is applied or while a mapping is unset.
 */
function get_mapped_ledger(int $companyId, string $mapKey): ?array
{
    if ($companyId <= 0 || !table_exists('ledgers')) {
        return null;
    }

    if (table_exists('company_ledger_mappings')) {
        $stmt = db()->prepare('
            SELECT l.*
            FROM company_ledger_mappings clm
            INNER JOIN ledgers l
                ON l.id = clm.ledger_id
               AND l.company_id = clm.company_id
            WHERE clm.company_id = :company_id
              AND clm.map_key = :map_key
            LIMIT 1
        ');
        $stmt->execute([
            'company_id' => $companyId,
            'map_key' => $mapKey,
        ]);
        $mappedLedger = $stmt->fetch();
        if ($mappedLedger) {
            return $mappedLedger;
        }
    }

    $fallbackCodes = [
        'default_cash_bank' => ['CASH'],
        'default_accounts_receivable' => ['AR'],
        'default_accounts_payable' => ['AP'],
        'default_tax_payable' => ['TAX_PAYABLE'],
        'default_excise_payable' => ['EXCISE_PAYABLE', 'TAX_PAYABLE'],
        'default_service_revenue' => ['SERVICE_REVENUE'],
        'default_hosting_revenue' => ['HOSTING_REVENUE', 'SERVICE_REVENUE'],
    ];

    foreach ($fallbackCodes[$mapKey] ?? [] as $code) {
        $ledger = ledger_by_code($companyId, $code);
        if ($ledger) {
            return $ledger;
        }
    }

    return null;
}

/**
 * The Trade Receivables / Trade Payables ledger GROUPS. Receivable and
 * payable balances live in per-party ledgers under these groups; the old
 * generic AR/AP ledgers remain only as a fallback for postings that have
 * no party attached.
 */
function receivable_payable_group_id(int $companyId, string $side): int
{
    if ($companyId <= 0 || !table_exists('ledger_groups')) {
        return 0;
    }
    $code = $side === 'payable' ? 'PAYABLE' : 'RECEIVABLE';
    $name = $side === 'payable' ? 'Trade Payables' : 'Trade Receivables';
    $masterKey = $side === 'payable' ? 'current_liability' : 'current_asset';

    // Match by seed code first, then by name + nature: client-books
    // companies (and structured-code companies) carry the same groups
    // under generated numeric codes.
    $stmt = db()->prepare('SELECT id FROM ledger_groups
        WHERE company_id = :cid AND (code = :code OR (name = :name AND master_key = :master_key))
        ORDER BY (code = :code2) DESC, id ASC
        LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'code' => $code, 'name' => $name, 'master_key' => $masterKey, 'code2' => $code]);
    $groupId = (int) ($stmt->fetchColumn() ?: 0);
    if ($groupId > 0) {
        return $groupId;
    }
    // Older companies may predate the seeded group structure.
    db()->prepare('INSERT INTO ledger_groups (company_id, code, name, master_key, is_cash_or_bank, is_system) VALUES (:cid, :code, :name, :master_key, 0, 1)')
        ->execute([
            'cid' => $companyId,
            'code' => $code,
            'name' => $name,
            'master_key' => $masterKey,
        ]);
    return (int) db()->lastInsertId();
}

/**
 * Finds or creates the party's own ledger under Trade Receivables /
 * Trade Payables, so invoices, receipts, purchase bills, and supplier
 * payments post against the client or supplier by name instead of the
 * generic AR/AP ledger. Returns 0 when it cannot resolve one (callers
 * fall back to the mapped default ledger).
 */
function ensure_party_ledger(int $companyId, int $partyId, string $side = 'receivable'): int
{
    if ($companyId <= 0 || $partyId <= 0 || !table_exists('accounting_parties') || !table_exists('ledgers')) {
        return 0;
    }
    try {
        $partyStmt = db()->prepare('SELECT * FROM accounting_parties WHERE id = :id AND company_id = :cid LIMIT 1');
        $partyStmt->execute(['id' => $partyId, 'cid' => $companyId]);
        $party = $partyStmt->fetch();
        if (!$party) {
            return 0;
        }

        $hasPayableColumn = column_exists('accounting_parties', 'payable_ledger_id');
        $column = $side === 'payable' ? ($hasPayableColumn ? 'payable_ledger_id' : null) : 'ledger_id';

        $storedId = $column !== null ? (int) ($party[$column] ?? 0) : 0;
        if ($storedId > 0) {
            // Honour a stored/admin-linked ledger only when its nature fits
            // the side, so a mis-linked cash or revenue ledger can never
            // receive the receivable/payable leg.
            $expectedType = $side === 'payable' ? 'liability' : 'asset';
            $check = db()->prepare("SELECT id FROM ledgers WHERE id = :id AND company_id = :cid AND status = 'active' AND type = :type LIMIT 1");
            $check->execute(['id' => $storedId, 'cid' => $companyId, 'type' => $expectedType]);
            if ($check->fetchColumn()) {
                return $storedId;
            }
        }

        $groupId = receivable_payable_group_id($companyId, $side);
        if ($groupId <= 0) {
            return 0;
        }

        // Reuse a ledger already named after this party under the group.
        $existing = db()->prepare("SELECT id FROM ledgers WHERE company_id = :cid AND group_id = :gid AND name = :name AND status = 'active' LIMIT 1");
        $existing->execute(['cid' => $companyId, 'gid' => $groupId, 'name' => (string) $party['name']]);
        $ledgerId = (int) ($existing->fetchColumn() ?: 0);

        if ($ledgerId <= 0) {
            db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status) VALUES (:cid, :gid, :code, :name, :type, 'active')")
                ->execute([
                    'cid' => $companyId,
                    'gid' => $groupId,
                    'code' => coa_next_ledger_code($companyId, $groupId),
                    'name' => (string) $party['name'],
                    'type' => $side === 'payable' ? 'liability' : 'asset',
                ]);
            $ledgerId = (int) db()->lastInsertId();
        }

        if ($ledgerId > 0 && $column !== null) {
            db()->prepare("UPDATE accounting_parties SET {$column} = :lid WHERE id = :id")
                ->execute(['lid' => $ledgerId, 'id' => $partyId]);
        }

        return $ledgerId;
    } catch (Throwable $exception) {
        return 0;
    }
}

/**
 * Finds or creates the accounting party that represents a work-portal
 * client, so client invoices raised from tasks post to the client's own
 * receivable ledger even when the invoice has no party attached.
 */
function ensure_party_for_client(int $companyId, int $clientId): int
{
    if ($companyId <= 0 || $clientId <= 0 || !table_exists('accounting_parties') || !table_exists('client_profiles')) {
        return 0;
    }
    try {
        $clientStmt = db()->prepare('SELECT organization_name, client_code FROM client_profiles WHERE id = :id LIMIT 1');
        $clientStmt->execute(['id' => $clientId]);
        $client = $clientStmt->fetch();
        if (!$client || trim((string) $client['organization_name']) === '') {
            return 0;
        }
        $name = trim((string) $client['organization_name']);

        $existing = db()->prepare('SELECT id FROM accounting_parties WHERE company_id = :cid AND name = :name LIMIT 1');
        $existing->execute(['cid' => $companyId, 'name' => $name]);
        $partyId = (int) ($existing->fetchColumn() ?: 0);
        if ($partyId > 0) {
            return $partyId;
        }

        $code = strtoupper(trim((string) ($client['client_code'] ?? '')));
        if ($code === '') {
            $code = 'CL-' . $clientId;
        }
        $codeTaken = db()->prepare('SELECT id FROM accounting_parties WHERE company_id = :cid AND code = :code LIMIT 1');
        $codeTaken->execute(['cid' => $companyId, 'code' => $code]);
        if ($codeTaken->fetchColumn()) {
            $code .= '-' . $clientId;
        }

        db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:cid, :code, :name, 'customer', 'active')")
            ->execute(['cid' => $companyId, 'code' => $code, 'name' => $name]);
        return (int) db()->lastInsertId();
    } catch (Throwable $exception) {
        return 0;
    }
}

/**
 * The equity ledger that absorbs opening-balance journals. Found by code,
 * else created under the Reserve & Surplus (or any equity) group.
 */
function opening_balance_ledger_id(int $companyId): int
{
    if ($companyId <= 0 || !table_exists('ledgers')) {
        return 0;
    }
    $stmt = db()->prepare("SELECT id FROM ledgers WHERE company_id = :cid AND code = 'OPENING_BAL' LIMIT 1");
    $stmt->execute(['cid' => $companyId]);
    $ledgerId = (int) ($stmt->fetchColumn() ?: 0);
    if ($ledgerId > 0) {
        return $ledgerId;
    }

    $groupStmt = db()->prepare("SELECT id FROM ledger_groups
        WHERE company_id = :cid AND (code = 'RESERVE_SURPLUS' OR name LIKE 'Reserve%' OR master_key = 'equity')
        ORDER BY (code = 'RESERVE_SURPLUS') DESC, id ASC LIMIT 1");
    $groupStmt->execute(['cid' => $companyId]);
    $groupId = (int) ($groupStmt->fetchColumn() ?: 0);
    if ($groupId <= 0) {
        db()->prepare("INSERT INTO ledger_groups (company_id, code, name, master_key, is_cash_or_bank, is_system) VALUES (:cid, :code, 'Reserve & Surplus', 'equity', 0, 1)")
            ->execute(['cid' => $companyId, 'code' => coa_next_group_code($companyId, 'equity')]);
        $groupId = (int) db()->lastInsertId();
    }

    db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, is_system, status) VALUES (:cid, :gid, 'OPENING_BAL', 'Opening Balance Adjustments', 'equity', 1, 'active')")
        ->execute(['cid' => $companyId, 'gid' => $groupId]);
    return (int) db()->lastInsertId();
}

/**
 * Posts a party's opening balance as a journal voucher into its own
 * receivable/payable ledger (contra: Opening Balance Adjustments).
 * Posted at most ONCE per party (source party_opening/<party id>); later
 * edits to the opening-balance field intentionally do not repost.
 * Returns the voucher id, or 0 when nothing was posted.
 */
function post_party_opening_balance(int $companyId, int $partyId, ?int $actorId = null): int
{
    if ($companyId <= 0 || $partyId <= 0 || !table_exists('accounting_parties') || !table_exists('vouchers')) {
        return 0;
    }
    try {
        $existing = db()->prepare("SELECT id FROM vouchers WHERE source_type = 'party_opening' AND source_id = :pid LIMIT 1");
        $existing->execute(['pid' => $partyId]);
        if ($existing->fetchColumn()) {
            return 0;
        }

        $partyStmt = db()->prepare('SELECT * FROM accounting_parties WHERE id = :id AND company_id = :cid LIMIT 1');
        $partyStmt->execute(['id' => $partyId, 'cid' => $companyId]);
        $party = $partyStmt->fetch();
        $amount = $party ? round((float) ($party['opening_balance'] ?? 0), 2) : 0.0;
        if (!$party || $amount <= 0) {
            return 0;
        }
        $balanceType = (string) ($party['opening_balance_type'] ?? 'debit');

        // Suppliers open on the payable side; customers (and "both") on the
        // receivable side unless the balance is a credit we owe.
        $side = (string) $party['party_type'] === 'supplier' ? 'payable'
            : ((string) $party['party_type'] === 'both' && $balanceType === 'credit' ? 'payable' : 'receivable');
        $partyLedgerId = ensure_party_ledger($companyId, $partyId, $side);
        $contraLedgerId = opening_balance_ledger_id($companyId);
        if ($partyLedgerId <= 0 || $contraLedgerId <= 0) {
            return 0;
        }

        $fyStmt = db()->prepare('SELECT id, start_date FROM fiscal_years WHERE company_id = :cid ORDER BY is_default DESC, is_active DESC, id DESC LIMIT 1');
        $fyStmt->execute(['cid' => $companyId]);
        $fy = $fyStmt->fetch();
        if (!$fy) {
            return 0;
        }

        $partyEntryType = $balanceType === 'credit' ? 'credit' : 'debit';
        $contraEntryType = $partyEntryType === 'debit' ? 'credit' : 'debit';

        return create_voucher_with_entries([
            'company_id' => $companyId,
            'fiscal_year_id' => (int) $fy['id'],
            'voucher_no' => 'OB-P' . str_pad((string) $partyId, 6, '0', STR_PAD_LEFT),
            'voucher_type' => 'journal',
            'source_type' => 'party_opening',
            'source_id' => $partyId,
            'party_id' => $partyId,
            'voucher_date' => (string) ($fy['start_date'] ?? date('Y-m-d')),
            'narration' => 'Opening balance for ' . (string) $party['name'],
            'total_amount' => $amount,
            'status' => 'posted',
            'posted_by' => $actorId,
        ], [
            ['ledger_id' => $partyLedgerId, 'entry_type' => $partyEntryType, 'amount' => $amount, 'memo' => 'Opening balance'],
            ['ledger_id' => $contraLedgerId, 'entry_type' => $contraEntryType, 'amount' => $amount, 'memo' => 'Opening balance contra'],
        ]);
    } catch (Throwable $exception) {
        return 0;
    }
}

/**
 * Resolves the party an invoice belongs to: its own party_id when set,
 * otherwise the work-portal client behind its task (creating the party
 * on the fly). Returns 0 when no party can be determined.
 */
function invoice_party_id(array $invoice, int $companyId): int
{
    $partyId = (int) ($invoice['party_id'] ?? 0);
    if ($partyId > 0 && table_exists('accounting_parties')) {
        $check = db()->prepare('SELECT id FROM accounting_parties WHERE id = :id AND company_id = :cid LIMIT 1');
        $check->execute(['id' => $partyId, 'cid' => $companyId]);
        if ($check->fetchColumn()) {
            return $partyId;
        }
        $partyId = 0;
    }
    $taskId = (int) ($invoice['task_id'] ?? 0);
    if ($partyId <= 0 && $taskId > 0 && table_exists('client_tasks')) {
        $clientStmt = db()->prepare('SELECT client_id FROM client_tasks WHERE id = :id LIMIT 1');
        $clientStmt->execute(['id' => $taskId]);
        $clientId = (int) ($clientStmt->fetchColumn() ?: 0);
        if ($clientId > 0) {
            $partyId = ensure_party_for_client($companyId, $clientId);
        }
    }
    return $partyId;
}

function create_voucher_with_entries(array $voucher, array $entries): int
{
    if (!table_exists('vouchers') || !table_exists('voucher_entries')) {
        return 0;
    }

    // Tenant-integrity guard: every ledger posted to must belong to the same
    // company as the voucher, and the fiscal year must too. This is the single
    // choke point for all double-entry posting, so a caller bug (or a tampered
    // ledger_id) can never move money between companies' books.
    $voucherCompanyId = (int) ($voucher['company_id'] ?? 0);
    if ($voucherCompanyId <= 0) {
        throw new RuntimeException('Refusing to post a voucher without a company.');
    }
    $ledgerIds = [];
    foreach ($entries as $entry) {
        $ledgerId = (int) ($entry['ledger_id'] ?? 0);
        if ($ledgerId > 0) {
            $ledgerIds[$ledgerId] = true;
        }
    }
    if ($ledgerIds !== [] && table_exists('ledgers')) {
        $ids = array_keys($ledgerIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $check = db()->prepare('SELECT id FROM ledgers WHERE company_id = ? AND id IN (' . $placeholders . ')');
        $check->execute(array_merge([$voucherCompanyId], $ids));
        $valid = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN));
        $foreign = array_diff($ids, $valid);
        if ($foreign !== []) {
            throw new RuntimeException('Refusing to post voucher: ledger(s) ' . implode(',', $foreign) . ' do not belong to company #' . $voucherCompanyId . '.');
        }
    }
    if (!empty($voucher['fiscal_year_id']) && table_exists('fiscal_years')) {
        $fyCheck = db()->prepare('SELECT COUNT(*) FROM fiscal_years WHERE id = ? AND company_id = ?');
        $fyCheck->execute([(int) $voucher['fiscal_year_id'], $voucherCompanyId]);
        if ((int) $fyCheck->fetchColumn() === 0) {
            throw new RuntimeException('Refusing to post voucher: fiscal year #' . (int) $voucher['fiscal_year_id'] . ' does not belong to company #' . $voucherCompanyId . '.');
        }
    }
    // The transaction DATE decides the fiscal year — never a submitted
    // fiscal_year_id alone (which the browser, an import file, or a stale
    // session could get wrong). Recalculate the year from the date, then
    // enforce lifecycle status and the cutoff date HERE, at the single
    // choke point every posting module flows through (vouchers, invoices,
    // payroll, inventory, fixed assets, leases, imports, client books).
    if (table_exists('fiscal_years')) {
        $postingDate = (string) ($voucher['voucher_date'] ?? date('Y-m-d'));
        $dateFiscalYear = fiscal_year_for_date($voucherCompanyId, $postingDate);
        if (!$dateFiscalYear) {
            throw new RuntimeException('No fiscal year of company #' . $voucherCompanyId . ' covers the transaction date ' . $postingDate . '. Create or open a fiscal year for this period before posting.');
        }
        $postingBlocker = fiscal_year_posting_blocker($dateFiscalYear, $postingDate);
        if ($postingBlocker !== null) {
            throw new RuntimeException($postingBlocker);
        }
        // Recalculated year wins: a mismatched fiscal_year_id would misfile
        // the voucher in reports and period locks.
        $voucher['fiscal_year_id'] = (int) $dateFiscalYear['id'];
    }
    // A tampered party_id must not attach another tenant's party to this
    // voucher (its name would then surface via party joins). Drop it rather
    // than fail the whole posting when it is foreign.
    if (!empty($voucher['party_id']) && table_exists('accounting_parties')) {
        $partyCheck = db()->prepare('SELECT COUNT(*) FROM accounting_parties WHERE id = ? AND company_id = ?');
        $partyCheck->execute([(int) $voucher['party_id'], $voucherCompanyId]);
        if ((int) $partyCheck->fetchColumn() === 0) {
            $voucher['party_id'] = null;
        }
    }

    $extraColumns = '';
    $extraValues = '';
    $params = [
        'company_id' => $voucher['company_id'],
        'fiscal_year_id' => $voucher['fiscal_year_id'],
        'voucher_no' => $voucher['voucher_no'],
        'voucher_type' => $voucher['voucher_type'],
        'source_type' => $voucher['source_type'],
        'source_id' => $voucher['source_id'],
        'narration' => $voucher['narration'] ?? null,
        'total_amount' => $voucher['total_amount'],
        'status' => $voucher['status'] ?? 'posted',
        'posted_by' => $voucher['posted_by'] ?? null,
        'posted_at' => $voucher['posted_at'] ?? date('Y-m-d H:i:s'),
    ];
    if (column_exists('vouchers', 'voucher_date')) {
        $extraColumns .= ', voucher_date';
        $extraValues .= ', :voucher_date';
        $params['voucher_date'] = $voucher['voucher_date'] ?? date('Y-m-d');
    }
    if (column_exists('vouchers', 'party_id')) {
        $extraColumns .= ', party_id';
        $extraValues .= ', :party_id';
        $params['party_id'] = $voucher['party_id'] ?? null;
    }
    if (column_exists('vouchers', 'reference_no')) {
        $extraColumns .= ', reference_no';
        $extraValues .= ', :reference_no';
        $params['reference_no'] = $voucher['reference_no'] ?? null;
    }
    foreach (['approval_state', 'submitted_by', 'approved_by', 'approved_at', 'rejection_reason'] as $approvalColumn) {
        if (column_exists('vouchers', $approvalColumn)) {
            $extraColumns .= ', ' . $approvalColumn;
            $extraValues .= ', :' . $approvalColumn;
            $params[$approvalColumn] = $voucher[$approvalColumn] ?? null;
        }
    }
    // approval_state is NOT NULL: default it from the posting status when the
    // caller does not manage approvals explicitly (auto-posts, imports, seeds).
    if (array_key_exists('approval_state', $params) && $params['approval_state'] === null) {
        $params['approval_state'] = ($params['status'] === 'posted') ? 'approved' : 'draft';
    }

    $stmt = db()->prepare('INSERT INTO vouchers (company_id, fiscal_year_id, voucher_no, voucher_type, source_type, source_id, narration, total_amount, status, posted_by, posted_at' . $extraColumns . ') VALUES (:company_id, :fiscal_year_id, :voucher_no, :voucher_type, :source_type, :source_id, :narration, :total_amount, :status, :posted_by, :posted_at' . $extraValues . ')');
    $stmt->execute($params);

    $voucherId = (int) db()->lastInsertId();
    $entryStmt = db()->prepare('INSERT INTO voucher_entries (voucher_id, ledger_id, entry_type, amount, memo) VALUES (:voucher_id, :ledger_id, :entry_type, :amount, :memo)');
    foreach ($entries as $entry) {
        $entryStmt->execute([
            'voucher_id' => $voucherId,
            'ledger_id' => $entry['ledger_id'],
            'entry_type' => $entry['entry_type'],
            'amount' => $entry['amount'],
            'memo' => $entry['memo'] ?? null,
        ]);
    }

    return $voucherId;
}

/**
 * Why a voucher cannot be edited or deleted, or null when it can.
 * The two hard blockers: a bank-reconciled entry (removing it would desync
 * the reconciliation), and a voucher date inside a locked accounting period.
 */
function voucher_mutation_blocker(array $voucher): ?string
{
    $voucherId = (int) ($voucher['id'] ?? 0);
    if ($voucherId <= 0) {
        return 'Voucher not found.';
    }
    if (column_exists('voucher_entries', 'reconciled_at')) {
        $reconciledStmt = db()->prepare('SELECT COUNT(*) FROM voucher_entries WHERE voucher_id = :id AND reconciled_at IS NOT NULL');
        $reconciledStmt->execute(['id' => $voucherId]);
        if ((int) $reconciledStmt->fetchColumn() > 0) {
            return 'This voucher has bank-reconciled entries. Undo the reconciliation before editing or deleting it.';
        }
    }
    $companyId = (int) ($voucher['company_id'] ?? 0);
    $fiscalYearId = (int) ($voucher['fiscal_year_id'] ?? 0);
    $voucherDate = (string) ($voucher['voucher_date'] ?? '');
    if ($voucherDate !== '' && is_period_locked($companyId, $fiscalYearId, $voucherDate)) {
        return 'This voucher is inside a locked accounting period. Unlock the period first.';
    }
    // A closed or locked fiscal year rejects every accounting change while
    // staying fully available for viewing and export.
    if ($fiscalYearId > 0) {
        $voucherFy = fiscal_year_by_id($fiscalYearId);
        if ($voucherFy) {
            $fyStatus = fiscal_year_status($voucherFy);
            if ($fyStatus === 'closed' || $fyStatus === 'locked') {
                return 'Fiscal year ' . $voucherFy['label'] . ' is ' . $fyStatus . ' — vouchers in it cannot be edited or deleted. Reopen the year first (requires permission and is audited).';
            }
        }
    }
    // Vouchers created by the Fixed Assets module back register rows
    // (depreciation schedules, lease schedules, impairment events) that keep
    // claiming "posted" after the voucher is gone — the exact register/GL
    // drift this guard exists to prevent. Correct those from the asset or
    // lease page; deleting the ASSET removes its vouchers consistently.
    $faSourceTypes = [
        'fixed_asset_acquisition', 'fixed_asset_addition', 'asset_depreciation',
        'asset_impairment', 'asset_impairment_reversal', 'asset_held_for_sale',
        'asset_disposal', 'asset_cwip_capitalization', 'asset_borrowing_cost',
        'asset_revaluation_batch_line', 'lease_commencement', 'lease_period',
        'lease_modification',
    ];
    if (in_array((string) ($voucher['source_type'] ?? ''), $faSourceTypes, true)) {
        return 'This voucher was posted by the Fixed Assets module and backs its register. Correct it from the asset or lease page (or delete the asset, which removes its vouchers consistently) — editing or deleting it here would desync the asset register from the books.';
    }
    foreach ([
        'asset_depreciation_schedule' => 'a depreciation schedule row',
        'lease_schedule_lines' => 'a lease schedule period',
        'asset_impairments' => 'an impairment event',
        'asset_revaluation_lines' => 'a revaluation batch line',
    ] as $refTable => $refLabel) {
        if (!table_exists($refTable)) {
            continue;
        }
        $refStmt = db()->prepare('SELECT COUNT(*) FROM ' . $refTable . ' WHERE voucher_id = :id');
        $refStmt->execute(['id' => $voucherId]);
        if ((int) $refStmt->fetchColumn() > 0) {
            return 'This voucher backs ' . $refLabel . ' in the fixed-asset register. Correct it from the asset or lease page — removing it here would leave the register claiming a posting the ledger no longer has.';
        }
    }
    return null;
}

/**
 * Permanently removes a voucher with its entries and attachments (both
 * cascade on the FK). Callers gate WHO may delete; this enforces WHAT may
 * be deleted: company scope, no reconciled entries, no locked period.
 */
function delete_voucher_with_entries(int $voucherId, int $companyId, ?int $actorId = null): array
{
    $stmt = db()->prepare('SELECT * FROM vouchers WHERE id = :id AND company_id = :company_id LIMIT 1');
    $stmt->execute(['id' => $voucherId, 'company_id' => $companyId]);
    $voucher = $stmt->fetch();
    if (!$voucher) {
        return ['ok' => false, 'error' => 'Voucher not found for this company.', 'voucher_no' => ''];
    }
    $blocker = voucher_mutation_blocker($voucher);
    if ($blocker !== null) {
        return ['ok' => false, 'error' => $blocker, 'voucher_no' => (string) $voucher['voucher_no']];
    }

    // Remove attachment files from disk before the rows cascade away.
    if (table_exists('voucher_attachments')) {
        $fileStmt = db()->prepare('SELECT file_path FROM voucher_attachments WHERE voucher_id = :id');
        $fileStmt->execute(['id' => $voucherId]);
        foreach ($fileStmt->fetchAll(PDO::FETCH_COLUMN) as $filePath) {
            $absolute = __DIR__ . '/../public_html/' . ltrim((string) $filePath, '/');
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }
    }

    db()->prepare('DELETE FROM vouchers WHERE id = :id AND company_id = :company_id')
        ->execute(['id' => $voucherId, 'company_id' => $companyId]);

    $summary = 'Voucher ' . $voucher['voucher_no'] . ' (' . $voucher['voucher_type'] . ', ' . number_format((float) $voucher['total_amount'], 2) . ') deleted.';
    security_event('voucher_deleted', 'success', $summary, $companyId, $actorId);
    log_activity('voucher', $voucherId, 'deleted', $summary, $actorId);

    return ['ok' => true, 'error' => null, 'voucher_no' => (string) $voucher['voucher_no']];
}

function auto_post_order_payment_voucher(int $orderId, ?int $actorId = null): void
{
    if (!table_exists('vouchers') || !table_exists('voucher_entries') || !table_exists('ledgers')) {
        return;
    }

    $order = order_by_id($orderId);
    if (!$order || ($order['payment_status'] ?? 'pending') !== 'paid') {
        return;
    }

    $existingStmt = db()->prepare('SELECT id FROM vouchers WHERE source_type = :source_type AND source_id = :source_id LIMIT 1');
    $existingStmt->execute([
        'source_type' => 'order_payment',
        'source_id' => $orderId,
    ]);
    if ($existingStmt->fetch()) {
        return;
    }

    $companyId = current_company_id();
    $fiscalYearId = current_fiscal_year_id();
    if ($companyId <= 0 || $fiscalYearId <= 0) {
        $default = resolve_default_company_and_fiscal_year();
        if (!$default) {
            return;
        }
        $companyId = (int) $default['company']['id'];
        $fiscalYearId = (int) $default['fiscal_year']['id'];
    }

    $cashLedger = get_mapped_ledger($companyId, 'default_cash_bank');
    $revenueLedger = get_mapped_ledger($companyId, 'default_hosting_revenue');
    if (!$cashLedger || !$revenueLedger) {
        return;
    }

    $amount = round((float) $order['amount'], 2);
    if ($amount <= 0) {
        return;
    }

    $voucherNo = 'PV-' . date('Ymd') . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
    $voucherId = create_voucher_with_entries([
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId,
        'voucher_no' => $voucherNo,
        'voucher_type' => 'payment',
        'source_type' => 'order_payment',
        'source_id' => $orderId,
        'narration' => 'Auto posted order payment for order #' . $orderId,
        'total_amount' => $amount,
        'status' => 'posted',
        'posted_by' => $actorId,
        'posted_at' => date('Y-m-d H:i:s'),
    ], [
        [
            'ledger_id' => (int) $cashLedger['id'],
            'entry_type' => 'debit',
            'amount' => $amount,
            'memo' => 'Cash/bank received',
        ],
        [
            'ledger_id' => (int) $revenueLedger['id'],
            'entry_type' => 'credit',
            'amount' => $amount,
            'memo' => 'Hosting revenue recognized',
        ],
    ]);

    log_activity('order', $orderId, 'voucher_posted', 'Auto-posted payment voucher #' . $voucherId . '.', $actorId);
}

function auto_post_order_refund_voucher(int $orderId, ?int $actorId = null): void
{
    if (!table_exists('vouchers') || !table_exists('voucher_entries') || !table_exists('ledgers')) {
        return;
    }

    $order = order_by_id($orderId);
    if (!$order || ($order['payment_status'] ?? 'pending') !== 'refunded') {
        return;
    }

    $existingStmt = db()->prepare('SELECT id FROM vouchers WHERE source_type = :source_type AND source_id = :source_id LIMIT 1');
    $existingStmt->execute([
        'source_type' => 'order_refund',
        'source_id' => $orderId,
    ]);
    if ($existingStmt->fetch()) {
        return;
    }

    $companyId = current_company_id();
    $fiscalYearId = current_fiscal_year_id();
    if ($companyId <= 0 || $fiscalYearId <= 0) {
        $default = resolve_default_company_and_fiscal_year();
        if (!$default) {
            return;
        }
        $companyId = (int) $default['company']['id'];
        $fiscalYearId = (int) $default['fiscal_year']['id'];
    }

    $cashLedger = get_mapped_ledger($companyId, 'default_cash_bank');
    $revenueLedger = get_mapped_ledger($companyId, 'default_hosting_revenue');
    if (!$cashLedger || !$revenueLedger) {
        return;
    }

    $amount = round((float) $order['amount'], 2);
    if ($amount <= 0) {
        return;
    }

    $voucherNo = 'RV-' . date('Ymd') . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
    $voucherId = create_voucher_with_entries([
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId,
        'voucher_no' => $voucherNo,
        'voucher_type' => 'journal',
        'source_type' => 'order_refund',
        'source_id' => $orderId,
        'narration' => 'Auto-posted refund voucher for order #' . $orderId,
        'total_amount' => $amount,
        'status' => 'posted',
        'posted_by' => $actorId,
        'posted_at' => date('Y-m-d H:i:s'),
    ], [
        [
            'ledger_id' => (int) $revenueLedger['id'],
            'entry_type' => 'debit',
            'amount' => $amount,
            'memo' => 'Revenue reversal',
        ],
        [
            'ledger_id' => (int) $cashLedger['id'],
            'entry_type' => 'credit',
            'amount' => $amount,
            'memo' => 'Cash/bank paid out',
        ],
    ]);

    log_activity('order', $orderId, 'refund_voucher_posted', 'Auto-posted refund voucher #' . $voucherId . '.', $actorId);
}

function auto_post_task_invoice_voucher(int $invoiceId, ?int $actorId = null): void
{
    if (!table_exists('vouchers') || !table_exists('voucher_entries') || !table_exists('ledgers')) {
        return;
    }

    $existingStmt = db()->prepare('SELECT id FROM vouchers WHERE source_type = :source_type AND source_id = :source_id LIMIT 1');
    $existingStmt->execute([
        'source_type' => 'task_invoice',
        'source_id' => $invoiceId,
    ]);
    if ($existingStmt->fetch()) {
        return;
    }

    $invoiceStmt = db()->prepare('SELECT * FROM task_invoices WHERE id = :id LIMIT 1');
    $invoiceStmt->execute(['id' => $invoiceId]);
    $invoice = $invoiceStmt->fetch();
    if (!$invoice) {
        return;
    }

    $companyId = current_company_id();
    $fiscalYearId = current_fiscal_year_id();
    if ($companyId <= 0 || $fiscalYearId <= 0) {
        $default = resolve_default_company_and_fiscal_year();
        if (!$default) {
            return;
        }
        $companyId = (int) $default['company']['id'];
        $fiscalYearId = (int) $default['fiscal_year']['id'];
    }

    $revenueLedger = get_mapped_ledger($companyId, 'default_service_revenue');
    $taxPayableLedger = get_mapped_ledger($companyId, 'default_tax_payable');
    $excisePayableLedger = get_mapped_ledger($companyId, 'default_excise_payable');
    if (!$revenueLedger) {
        return;
    }

    // Receivable posts to the client's own ledger under Trade Receivables;
    // the mapped generic AR ledger is only the no-party fallback.
    $invoicePartyId = invoice_party_id($invoice, $companyId);
    $receivableLedgerId = $invoicePartyId > 0 ? ensure_party_ledger($companyId, $invoicePartyId, 'receivable') : 0;
    if ($receivableLedgerId <= 0) {
        $receivableLedger = get_mapped_ledger($companyId, 'default_accounts_receivable');
        $receivableLedgerId = (int) ($receivableLedger['id'] ?? 0);
    }
    if ($receivableLedgerId <= 0) {
        return;
    }

    // The VAT columns default to 0.00 (not NULL), so null-coalescing alone
    // never reaches `amount` on rows written by amount-only insert paths.
    // Rebuild the missing figures exactly like the printed invoice does, or
    // the voucher silently never posts (taxable 0) / posts without VAT.
    $taxableAmount = round((float) ($invoice['taxable_amount'] ?? 0), 2);
    if ($taxableAmount <= 0) {
        $taxableAmount = round((float) ($invoice['amount'] ?? 0), 2);
    }
    $exciseAmount = round((float) ($invoice['excise_amount'] ?? 0), 2);
    $vatAmount = round((float) ($invoice['vat_amount'] ?? 0), 2);
    if ($vatAmount <= 0 && ($taxableAmount + $exciseAmount) > 0) {
        $vatAmount = round(($taxableAmount + $exciseAmount) * (((float) ($invoice['vat_rate'] ?? 13.00)) / 100), 2);
    }
    $totalAmount = round((float) ($invoice['total_amount'] ?? 0), 2);
    if ($totalAmount <= 0) {
        $totalAmount = round($taxableAmount + $exciseAmount + $vatAmount, 2);
    }
    if ($totalAmount <= 0 || $taxableAmount <= 0) {
        return;
    }
    if ($exciseAmount > 0 && !$excisePayableLedger) {
        return;
    }
    if ($vatAmount > 0 && !$taxPayableLedger) {
        return;
    }

    $voucherNo = 'SV-' . date('Ymd') . '-' . str_pad((string) $invoiceId, 6, '0', STR_PAD_LEFT);
    $entries = [
        [
            'ledger_id' => $receivableLedgerId,
            'entry_type' => 'debit',
            'amount' => $totalAmount,
            'memo' => 'Amount receivable from client',
        ],
        [
            'ledger_id' => (int) $revenueLedger['id'],
            'entry_type' => 'credit',
            'amount' => $taxableAmount,
            'memo' => 'Service revenue recognized',
        ],
    ];
    if ($exciseAmount > 0 && $excisePayableLedger) {
        $entries[] = [
            'ledger_id' => (int) $excisePayableLedger['id'],
            'entry_type' => 'credit',
            'amount' => $exciseAmount,
            'memo' => 'Excise duty payable on invoice',
        ];
    }
    if ($vatAmount > 0 && $taxPayableLedger) {
        $entries[] = [
            'ledger_id' => (int) $taxPayableLedger['id'],
            'entry_type' => 'credit',
            'amount' => $vatAmount,
            'memo' => 'VAT payable on invoice',
        ];
    }

    $voucherId = create_voucher_with_entries([
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId,
        'voucher_no' => $voucherNo,
        'voucher_type' => 'sales',
        'source_type' => 'task_invoice',
        'source_id' => $invoiceId,
        'reference_no' => $invoice['invoice_no'] ?? null,
        'voucher_date' => $invoice['issued_on'] ?? date('Y-m-d'),
        'party_id' => $invoicePartyId > 0 ? $invoicePartyId : null,
        'narration' => 'Auto-posted sales voucher for invoice ' . ($invoice['invoice_no'] ?? '#' . $invoiceId),
        'total_amount' => $totalAmount,
        'status' => 'posted',
        'posted_by' => $actorId,
        'posted_at' => date('Y-m-d H:i:s'),
    ], $entries);

    log_activity('task_invoice', $invoiceId, 'voucher_posted', 'Auto-posted sales voucher #' . $voucherId . '.', $actorId);

    auto_post_client_mirror_invoice($invoiceId, $actorId);
}

function auto_post_invoice_payment_voucher(int $paymentRequestId, ?int $actorId = null): void
{
    if (!table_exists('vouchers') || !table_exists('voucher_entries') || !table_exists('ledgers') || !table_exists('invoice_payment_requests')) {
        return;
    }

    $existingStmt = db()->prepare('SELECT id FROM vouchers WHERE source_type = :source_type AND source_id = :source_id LIMIT 1');
    $existingStmt->execute([
        'source_type' => 'invoice_payment_request',
        'source_id' => $paymentRequestId,
    ]);
    if ($existingStmt->fetch()) {
        return;
    }

    $stmt = db()->prepare('
        SELECT pr.*, ti.invoice_no, ti.company_id AS invoice_company_id, ti.party_id AS invoice_party_id, ti.task_id AS invoice_task_id
        FROM invoice_payment_requests pr
        INNER JOIN task_invoices ti ON ti.id = pr.invoice_id
        WHERE pr.id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $paymentRequestId]);
    $payment = $stmt->fetch();
    if (!$payment || !in_array((string) ($payment['status'] ?? ''), ['paid', 'partial'], true)) {
        return;
    }

    $companyId = (int) ($payment['company_id'] ?? $payment['invoice_company_id'] ?? 0);
    $fiscalYearId = current_fiscal_year_id();
    if ($companyId <= 0 || $fiscalYearId <= 0) {
        $default = resolve_default_company_and_fiscal_year();
        if (!$default) {
            return;
        }
        $companyId = $companyId > 0 ? $companyId : (int) $default['company']['id'];
        $fiscalYearId = (int) $default['fiscal_year']['id'];
    }

    $cashLedger = get_mapped_ledger($companyId, 'default_cash_bank');
    if (!$cashLedger) {
        return;
    }

    // Settle against the exact receivable ledger the invoice's sales
    // voucher debited (keeps old generic-AR invoices and new party-ledger
    // invoices each internally consistent). Fall back to the party's own
    // ledger, then to the mapped generic AR ledger.
    $invoicePartyId = invoice_party_id([
        'party_id' => $payment['invoice_party_id'] ?? null,
        'task_id' => $payment['invoice_task_id'] ?? null,
    ], $companyId);
    $receivableLedgerId = 0;
    try {
        // A sales voucher has exactly one debit leg: the receivable. Reuse
        // it verbatim so settlements always land where the invoice posted,
        // whatever ledger or group layout the company uses.
        $salesLegStmt = db()->prepare("SELECT ve.ledger_id
            FROM vouchers v
            INNER JOIN voucher_entries ve ON ve.voucher_id = v.id
            WHERE v.source_type = 'task_invoice' AND v.source_id = :invoice_id
              AND v.status = 'posted' AND ve.entry_type = 'debit'
            LIMIT 1");
        $salesLegStmt->execute(['invoice_id' => (int) $payment['invoice_id']]);
        $receivableLedgerId = (int) ($salesLegStmt->fetchColumn() ?: 0);
    } catch (Throwable $exception) {
        $receivableLedgerId = 0;
    }
    if ($receivableLedgerId <= 0 && $invoicePartyId > 0) {
        $receivableLedgerId = ensure_party_ledger($companyId, $invoicePartyId, 'receivable');
    }
    if ($receivableLedgerId <= 0) {
        $receivableLedger = get_mapped_ledger($companyId, 'default_accounts_receivable');
        $receivableLedgerId = (int) ($receivableLedger['id'] ?? 0);
    }
    if ($receivableLedgerId <= 0) {
        return;
    }

    $amount = round((float) ($payment['payment_amount'] ?? 0), 2);
    if ($amount <= 0) {
        return;
    }

    $voucherNo = 'RV-' . date('Ymd') . '-' . str_pad((string) $paymentRequestId, 6, '0', STR_PAD_LEFT);
    $voucherId = create_voucher_with_entries([
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId,
        'voucher_no' => $voucherNo,
        'voucher_type' => 'receipt',
        'source_type' => 'invoice_payment_request',
        'source_id' => $paymentRequestId,
        'reference_no' => $payment['invoice_no'] ?? null,
        'voucher_date' => $payment['payment_received_on'] ?? date('Y-m-d'),
        'party_id' => $invoicePartyId > 0 ? $invoicePartyId : null,
        'narration' => 'Auto-posted receipt for invoice ' . ($payment['invoice_no'] ?? '#' . (int) $payment['invoice_id']),
        'total_amount' => $amount,
        'status' => 'posted',
        'posted_by' => $actorId,
        'posted_at' => date('Y-m-d H:i:s'),
    ], [
        [
            'ledger_id' => (int) $cashLedger['id'],
            'entry_type' => 'debit',
            'amount' => $amount,
            'memo' => 'Client payment received',
        ],
        [
            'ledger_id' => $receivableLedgerId,
            'entry_type' => 'credit',
            'amount' => $amount,
            'memo' => 'Accounts receivable settled',
        ],
    ]);

    log_activity('invoice_payment_request', $paymentRequestId, 'voucher_posted', 'Auto-posted receipt voucher #' . $voucherId . '.', $actorId);

    auto_post_client_mirror_payment($paymentRequestId, $actorId);
}

// ---------------------------------------------------------------------------
// Client-books mirror postings.
//
// Every firm-side invoice event has an equal-and-opposite meaning in the
// client's own books: the firm's income is the client's expense, the firm's
// receipt is the client's payment, the firm's discount given is the client's
// discount received. These helpers post that mirror voucher into the client's
// books company as DRAFT / PENDING APPROVAL flagged for the client, so the
// books never move without the client's owner/approver confirming. All are
// best-effort: a failure here must never break the firm-side posting.
// ---------------------------------------------------------------------------

/**
 * Resolves where a mirror voucher for this invoice should go: the client
 * profile (via the invoice's task, or its party's client_profile_id link),
 * that client's books company, and the books' active fiscal year.
 */
function client_mirror_target(array $invoice): ?array
{
    if (!table_exists('client_profiles') || !column_exists('client_profiles', 'books_company_id')) {
        return null;
    }

    $clientId = 0;
    $taskId = (int) ($invoice['task_id'] ?? 0);
    if ($taskId > 0 && table_exists('client_tasks')) {
        $stmt = db()->prepare('SELECT client_id FROM client_tasks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $taskId]);
        $clientId = (int) ($stmt->fetchColumn() ?: 0);
    }
    $partyId = (int) ($invoice['party_id'] ?? 0);
    if ($clientId <= 0 && $partyId > 0 && column_exists('accounting_parties', 'client_profile_id')) {
        $stmt = db()->prepare('SELECT client_profile_id FROM accounting_parties WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $partyId]);
        $clientId = (int) ($stmt->fetchColumn() ?: 0);
    }
    if ($clientId <= 0) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM client_profiles WHERE id = :id AND is_active = 1 LIMIT 1');
    $stmt->execute(['id' => $clientId]);
    $profile = $stmt->fetch();
    $booksCompanyId = (int) ($profile['books_company_id'] ?? 0);
    if (!$profile || $booksCompanyId <= 0) {
        return null;
    }

    $booksCompany = company_by_id($booksCompanyId);
    if (!$booksCompany || (int) ($booksCompany['is_active'] ?? 0) !== 1) {
        return null;
    }

    $fyStmt = db()->prepare('SELECT id FROM fiscal_years WHERE company_id = :cid AND is_active = 1 ORDER BY is_default DESC, start_date DESC LIMIT 1');
    $fyStmt->execute(['cid' => $booksCompanyId]);
    $fiscalYearId = (int) ($fyStmt->fetchColumn() ?: 0);
    if ($fiscalYearId <= 0) {
        return null;
    }

    $provider = company_by_id((int) ($invoice['company_id'] ?? 0));

    return [
        'client_id' => $clientId,
        'books_company_id' => $booksCompanyId,
        'fiscal_year_id' => $fiscalYearId,
        'provider_name' => (string) ($provider['name'] ?? 'Service provider'),
    ];
}

/**
 * Resolves (or creates) the ledger a mirror entry posts to in the client's
 * books. The mapping keys are editable on Chart of Accounts → Posting
 * Accounts — by the firm while inside the client's books, and by the client
 * in their own accounting workspace. When nothing is mapped a sensible
 * ledger is created once so the mirror works out of the box.
 *
 * $kind: expense_task | expense_inventory | expense_manufacturing |
 *        expense_other | payable | discount_received | cash | tax_receivable
 */
function client_mirror_ledger(int $booksCompanyId, string $kind, ?string $providerName = null): ?array
{
    if ($booksCompanyId <= 0 || !table_exists('ledgers')) {
        return null;
    }

    if ($kind === 'cash') {
        return get_mapped_ledger($booksCompanyId, 'default_cash_bank');
    }
    if ($kind === 'tax_receivable') {
        // Optional: when unmapped the VAT stays inside the expense figure.
        return get_mapped_ledger($booksCompanyId, 'mirror_tax_receivable');
    }

    if (str_starts_with($kind, 'expense_')) {
        $mapped = get_mapped_ledger($booksCompanyId, 'mirror_' . $kind)
            ?: get_mapped_ledger($booksCompanyId, 'mirror_expense_other');
        if ($mapped) {
            return $mapped;
        }
    } elseif ($kind === 'payable') {
        $mapped = get_mapped_ledger($booksCompanyId, 'mirror_provider_payable');
        if ($mapped) {
            return $mapped;
        }
    } elseif ($kind === 'discount_received') {
        $mapped = get_mapped_ledger($booksCompanyId, 'mirror_discount_received');
        if ($mapped) {
            return $mapped;
        }
    } else {
        return null;
    }

    // Nothing mapped — find or create the default ledger for this role.
    try {
        $findGroup = static function (string $masterKey, string $code, string $name) use ($booksCompanyId): int {
            $stmt = db()->prepare('SELECT id FROM ledger_groups
                WHERE company_id = :cid AND is_active = 1 AND is_cash_or_bank = 0
                  AND (code = :code OR (name = :name AND master_key = :master_key))
                ORDER BY (code = :code2) DESC, id ASC LIMIT 1');
            $stmt->execute(['cid' => $booksCompanyId, 'code' => $code, 'name' => $name, 'master_key' => $masterKey, 'code2' => $code]);
            $groupId = (int) ($stmt->fetchColumn() ?: 0);
            if ($groupId > 0) {
                return $groupId;
            }
            db()->prepare('INSERT INTO ledger_groups (company_id, code, name, master_key, is_cash_or_bank, is_system) VALUES (:cid, :code, :name, :master_key, 0, 0)')
                ->execute(['cid' => $booksCompanyId, 'code' => $code, 'name' => $name, 'master_key' => $masterKey]);

            return (int) db()->lastInsertId();
        };

        $ensureLedger = static function (int $groupId, string $name, string $type) use ($booksCompanyId): ?array {
            $stmt = db()->prepare("SELECT * FROM ledgers WHERE company_id = :cid AND name = :name AND type = :type AND status = 'active' LIMIT 1");
            $stmt->execute(['cid' => $booksCompanyId, 'name' => $name, 'type' => $type]);
            $ledger = $stmt->fetch();
            if ($ledger) {
                return $ledger;
            }
            if ($groupId <= 0) {
                return null;
            }
            db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status) VALUES (:cid, :gid, :code, :name, :type, 'active')")
                ->execute(['cid' => $booksCompanyId, 'gid' => $groupId, 'code' => coa_next_ledger_code($booksCompanyId, $groupId), 'name' => $name, 'type' => $type]);
            $stmt->execute(['cid' => $booksCompanyId, 'name' => $name, 'type' => $type]);

            return $stmt->fetch() ?: null;
        };

        if (str_starts_with($kind, 'expense_')) {
            $groupId = $findGroup('indirect_expense', 'ADMIN-EXP', 'Administrative Expenses');

            return $ensureLedger($groupId, 'Professional & Consultancy Fees', 'expense');
        }
        if ($kind === 'payable') {
            $groupId = receivable_payable_group_id($booksCompanyId, 'payable');

            return $ensureLedger($groupId, ($providerName ?: 'Service Provider') . ' (Payable)', 'liability');
        }
        if ($kind === 'discount_received') {
            $groupId = $findGroup('indirect_income', 'IND-INC', 'Indirect Incomes');

            return $ensureLedger($groupId, 'Discount Received', 'revenue');
        }
    } catch (Throwable $exception) {
        return null;
    }

    return null;
}

/**
 * Flags a pending voucher so the client's owner/approver sees it in their
 * approval queue and bell — used for client-mirror vouchers and for vouchers a
 * staff accountant enters directly in a client's books (migration 049).
 */
function mark_voucher_requires_client_approval(int $voucherId): void
{
    if ($voucherId > 0 && column_exists('vouchers', 'requires_client_approval')) {
        db()->prepare('UPDATE vouchers SET requires_client_approval = 1 WHERE id = :id')->execute(['id' => $voucherId]);
    }
}

/** True when a mirror voucher with this source signature already exists. */
function client_mirror_voucher_exists(string $sourceType, int $sourceId): bool
{
    $stmt = db()->prepare('SELECT id FROM vouchers WHERE source_type = :source_type AND source_id = :source_id LIMIT 1');
    $stmt->execute(['source_type' => $sourceType, 'source_id' => $sourceId]);

    return (bool) $stmt->fetch();
}

/** Inserts the mirror voucher as draft/pending, flagged for client approval. */
function client_mirror_create_voucher(array $target, string $sourceType, int $sourceId, string $voucherType, string $voucherNo, string $voucherDate, string $narration, float $totalAmount, array $entries, ?int $actorId): void
{
    $voucherId = create_voucher_with_entries([
        'company_id' => (int) $target['books_company_id'],
        'fiscal_year_id' => (int) $target['fiscal_year_id'],
        'voucher_no' => $voucherNo,
        'voucher_type' => $voucherType,
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'voucher_date' => $voucherDate,
        'narration' => $narration,
        'total_amount' => $totalAmount,
        'status' => 'draft',
        'approval_state' => 'pending_approval',
        'submitted_by' => $actorId,
    ], $entries);

    mark_voucher_requires_client_approval($voucherId);
    if ($voucherId > 0) {
        security_event('client_mirror_posted', 'success', ucfirst($voucherType) . ' mirror voucher #' . $voucherId . ' submitted to client books for approval (' . $sourceType . ' #' . $sourceId . ').', (int) $target['books_company_id'], $actorId);
    }
}

/**
 * Firm invoice issued → client books expense entry (pending client approval):
 *   Dr expense head (per invoice source type)   taxable (+ excise, + VAT when
 *                                               no tax-receivable mapping)
 *   Dr input VAT receivable (if mapped)         VAT
 *   Cr service provider payable                 total
 */
function auto_post_client_mirror_invoice(int $invoiceId, ?int $actorId = null): void
{
    try {
        if (!table_exists('vouchers') || !table_exists('voucher_entries') || !table_exists('task_invoices')) {
            return;
        }
        if (client_mirror_voucher_exists('client_mirror_invoice', $invoiceId)) {
            return;
        }
        $stmt = db()->prepare('SELECT * FROM task_invoices WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            return;
        }
        $target = client_mirror_target($invoice);
        if (!$target) {
            return;
        }

        $taxableAmount = round((float) ($invoice['taxable_amount'] ?? $invoice['amount'] ?? 0), 2);
        $exciseAmount = round((float) ($invoice['excise_amount'] ?? 0), 2);
        $vatAmount = round((float) ($invoice['vat_amount'] ?? 0), 2);
        $totalAmount = round((float) ($invoice['total_amount'] ?? ($taxableAmount + $exciseAmount + $vatAmount)), 2);
        if ($totalAmount <= 0 || $taxableAmount <= 0) {
            return;
        }

        $sourceType = in_array((string) ($invoice['invoice_source_type'] ?? ''), ['task', 'inventory', 'manufacturing'], true)
            ? (string) $invoice['invoice_source_type']
            : 'other';
        $expenseLedger = client_mirror_ledger((int) $target['books_company_id'], 'expense_' . $sourceType, $target['provider_name']);
        $payableLedger = client_mirror_ledger((int) $target['books_company_id'], 'payable', $target['provider_name']);
        if (!$expenseLedger || !$payableLedger) {
            return;
        }
        $taxLedger = $vatAmount > 0 ? client_mirror_ledger((int) $target['books_company_id'], 'tax_receivable') : null;

        $expenseAmount = $taxLedger ? round($taxableAmount + $exciseAmount, 2) : $totalAmount;
        $entries = [
            ['ledger_id' => (int) $expenseLedger['id'], 'entry_type' => 'debit', 'amount' => $expenseAmount, 'memo' => 'Invoice ' . ($invoice['invoice_no'] ?? '#' . $invoiceId) . ' from ' . $target['provider_name']],
        ];
        if ($taxLedger) {
            $entries[] = ['ledger_id' => (int) $taxLedger['id'], 'entry_type' => 'debit', 'amount' => $vatAmount, 'memo' => 'Input VAT on provider invoice'];
        }
        $entries[] = ['ledger_id' => (int) $payableLedger['id'], 'entry_type' => 'credit', 'amount' => $totalAmount, 'memo' => 'Payable to ' . $target['provider_name']];

        client_mirror_create_voucher(
            $target,
            'client_mirror_invoice',
            $invoiceId,
            'purchase',
            'PM-' . date('Ymd') . '-' . str_pad((string) $invoiceId, 6, '0', STR_PAD_LEFT),
            (string) ($invoice['issued_on'] ?? date('Y-m-d')),
            'Provider invoice ' . ($invoice['invoice_no'] ?? '#' . $invoiceId) . ' — awaiting your approval',
            $totalAmount,
            $entries,
            $actorId
        );
    } catch (Throwable $exception) {
        // Mirror is best-effort; the firm-side posting must never fail on it.
    }
}

/**
 * Firm receipt recorded → client books payment entry (pending approval):
 *   Dr service provider payable / Cr bank-cash.
 */
function auto_post_client_mirror_payment(int $paymentRequestId, ?int $actorId = null): void
{
    try {
        if (!table_exists('vouchers') || !table_exists('invoice_payment_requests')) {
            return;
        }
        if (client_mirror_voucher_exists('client_mirror_payment', $paymentRequestId)) {
            return;
        }
        $stmt = db()->prepare('SELECT pr.*, ti.invoice_no, ti.company_id AS invoice_company_id, ti.party_id, ti.task_id
            FROM invoice_payment_requests pr INNER JOIN task_invoices ti ON ti.id = pr.invoice_id
            WHERE pr.id = :id LIMIT 1');
        $stmt->execute(['id' => $paymentRequestId]);
        $payment = $stmt->fetch();
        if (!$payment || !in_array((string) ($payment['status'] ?? ''), ['paid', 'partial'], true)) {
            return;
        }
        $amount = round((float) ($payment['payment_amount'] ?? 0), 2);
        if ($amount <= 0) {
            return;
        }
        $target = client_mirror_target([
            'task_id' => $payment['task_id'],
            'party_id' => $payment['party_id'],
            'company_id' => $payment['invoice_company_id'],
        ]);
        if (!$target) {
            return;
        }

        $payableLedger = client_mirror_ledger((int) $target['books_company_id'], 'payable', $target['provider_name']);
        $cashLedger = client_mirror_ledger((int) $target['books_company_id'], 'cash');
        if (!$payableLedger || !$cashLedger) {
            return;
        }

        client_mirror_create_voucher(
            $target,
            'client_mirror_payment',
            $paymentRequestId,
            'payment',
            'PP-' . date('Ymd') . '-' . str_pad((string) $paymentRequestId, 6, '0', STR_PAD_LEFT),
            (string) ($payment['payment_received_on'] ?? date('Y-m-d')),
            'Payment for provider invoice ' . ($payment['invoice_no'] ?? '#' . (int) $payment['invoice_id']) . ' — awaiting your approval',
            $amount,
            [
                ['ledger_id' => (int) $payableLedger['id'], 'entry_type' => 'debit', 'amount' => $amount, 'memo' => 'Payable settled — ' . $target['provider_name']],
                ['ledger_id' => (int) $cashLedger['id'], 'entry_type' => 'credit', 'amount' => $amount, 'memo' => 'Payment to ' . $target['provider_name']],
            ],
            $actorId
        );
    } catch (Throwable $exception) {
        // best-effort
    }
}

/**
 * Firm discount approved → client books discount-received entry (pending
 * approval): Dr provider payable / Cr Discount Received, for the drop in the
 * invoice's total.
 */
function auto_post_client_mirror_discount(int $billingRequestId, int $invoiceId, float $totalDelta, ?int $actorId = null): void
{
    try {
        $totalDelta = round($totalDelta, 2);
        if ($totalDelta <= 0 || !table_exists('vouchers') || !table_exists('task_invoices')) {
            return;
        }
        if (client_mirror_voucher_exists('client_mirror_discount', $billingRequestId)) {
            return;
        }
        $stmt = db()->prepare('SELECT * FROM task_invoices WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            return;
        }
        $target = client_mirror_target($invoice);
        if (!$target) {
            return;
        }

        $payableLedger = client_mirror_ledger((int) $target['books_company_id'], 'payable', $target['provider_name']);
        $discountLedger = client_mirror_ledger((int) $target['books_company_id'], 'discount_received');
        if (!$payableLedger || !$discountLedger) {
            return;
        }

        client_mirror_create_voucher(
            $target,
            'client_mirror_discount',
            $billingRequestId,
            'journal',
            'PD-' . date('Ymd') . '-' . str_pad((string) $billingRequestId, 6, '0', STR_PAD_LEFT),
            date('Y-m-d'),
            'Discount received on provider invoice ' . ($invoice['invoice_no'] ?? '#' . $invoiceId) . ' — awaiting your approval',
            $totalDelta,
            [
                ['ledger_id' => (int) $payableLedger['id'], 'entry_type' => 'debit', 'amount' => $totalDelta, 'memo' => 'Payable reduced by discount'],
                ['ledger_id' => (int) $discountLedger['id'], 'entry_type' => 'credit', 'amount' => $totalDelta, 'memo' => 'Discount received from ' . $target['provider_name']],
            ],
            $actorId
        );
    } catch (Throwable $exception) {
        // best-effort
    }
}

function save_contact(array $data): int
{
    $stmt = db()->prepare('INSERT INTO contacts (company_id, name, email, subject, message, status, assigned_admin_id) VALUES (:company_id, :name, :email, :subject, :message, :status, :assigned_admin_id)');
    $stmt->execute([
        'company_id' => $data['company_id'] ?? null,
        'name' => $data['name'],
        'email' => $data['email'],
        'subject' => $data['subject'],
        'message' => $data['message'],
        'status' => $data['status'] ?? 'new',
        'assigned_admin_id' => $data['assigned_admin_id'] ?? null,
    ]);

    return (int) db()->lastInsertId();
}

function store_first_admin(array $data): int
{
    return create_user([
        'name' => $data['name'],
        'email' => $data['email'],
        'password' => $data['password'],
        'role' => 'admin',
        'status' => 'active',
        'phone' => $data['phone'] ?? null,
        'company' => $data['company'] ?? null,
    ]);
}

function log_activity(string $entityType, int $entityId, string $action, ?string $details = null, ?int $actorId = null): void
{
    $hasIp = column_exists('activity_logs', 'ip_address');
    $columns = 'entity_type, entity_id, action, details, actor_id' . ($hasIp ? ', ip_address' : '');
    $values = ':entity_type, :entity_id, :action, :details, :actor_id' . ($hasIp ? ', :ip_address' : '');
    $params = [
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'action' => $action,
        'details' => $details,
        'actor_id' => $actorId ?? (current_user()['id'] ?? null),
    ];
    if ($hasIp) {
        $params['ip_address'] = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
    $stmt = db()->prepare('INSERT INTO activity_logs (' . $columns . ') VALUES (' . $values . ')');
    $stmt->execute($params);
}

/**
 * Records a field-level before/after change for the Audit Trail page.
 */
function log_field_changes(string $entityType, int $entityId, array $before, array $after, ?int $companyId = null, ?int $actorId = null): void
{
    if (!table_exists('audit_change_history')) {
        return;
    }
    $actorId = $actorId ?? (current_user()['id'] ?? null);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $stmt = db()->prepare('INSERT INTO audit_change_history (company_id, entity_type, entity_id, field_name, old_value, new_value, actor_id, ip_address) VALUES (:company_id, :entity_type, :entity_id, :field_name, :old_value, :new_value, :actor_id, :ip_address)');
    foreach ($after as $field => $newValue) {
        $oldValue = $before[$field] ?? null;
        if ((string) $oldValue === (string) $newValue) {
            continue;
        }
        $stmt->execute([
            'company_id' => $companyId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'field_name' => $field,
            'old_value' => $oldValue === null ? null : (string) $oldValue,
            'new_value' => $newValue === null ? null : (string) $newValue,
            'actor_id' => $actorId,
            'ip_address' => $ip,
        ]);
    }
}

// ---------------------------------------------------------------------------
// Role matrix / capabilities (blueprint page 17).
// ---------------------------------------------------------------------------
const ACCESS_LEVELS = [
    'super_admin' => 'Super Admin',
    'parent_admin' => 'Parent Company Admin',
    'subsidiary_admin' => 'Subsidiary Company Admin',
    'accountant' => 'Accountant',
    'approver' => 'Approver',
    'viewer' => 'Viewer',
    'support' => 'Support User',
];

/**
 * Capability matrix: level => [capability => true]. Capabilities:
 * view, create, edit, approve, post, delete, report, admin.
 */
function ensure_role_capability_schema(): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    db()->exec("
        CREATE TABLE IF NOT EXISTS role_capability_overrides (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL,
            access_level VARCHAR(40) NOT NULL,
            capability VARCHAR(40) NOT NULL,
            is_allowed TINYINT(1) NOT NULL DEFAULT 0,
            updated_by INT UNSIGNED DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_role_capability_company_level_action
                (company_id, access_level, capability),
            KEY idx_role_capability_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");

    $ready = true;
}

function default_access_level_capabilities(): array
{
    return [
        'super_admin' => [
            'view' => true, 'create' => true, 'edit' => true,
            'approve' => true, 'post' => true, 'delete' => true,
            'report' => true, 'admin' => true,
        ],
        'parent_admin' => [
            'view' => true, 'create' => true, 'edit' => true,
            'approve' => true, 'post' => true, 'delete' => false,
            'report' => true, 'admin' => true,
        ],
        'subsidiary_admin' => [
            'view' => true, 'create' => true, 'edit' => true,
            'approve' => true, 'post' => true, 'delete' => false,
            'report' => true, 'admin' => false,
        ],
        'accountant' => [
            'view' => true, 'create' => true, 'edit' => true,
            'approve' => false, 'post' => false, 'delete' => false,
            'report' => true, 'admin' => false,
        ],
        'approver' => [
            'view' => true, 'create' => false, 'edit' => false,
            'approve' => true, 'post' => true, 'delete' => false,
            'report' => true, 'admin' => false,
        ],
        'viewer' => [
            'view' => true, 'create' => false, 'edit' => false,
            'approve' => false, 'post' => false, 'delete' => false,
            'report' => true, 'admin' => false,
        ],
        'support' => [
            'view' => true, 'create' => false, 'edit' => false,
            'approve' => false, 'post' => false, 'delete' => false,
            'report' => false, 'admin' => false,
        ],
    ];
}

function access_level_capabilities(?int $companyId = null): array
{
    $matrix = default_access_level_capabilities();
    $companyId = $companyId ?? current_company_id();

    if ($companyId <= 0) {
        return $matrix;
    }

    ensure_role_capability_schema();

    $stmt = db()->prepare(
        'SELECT access_level, capability, is_allowed
         FROM role_capability_overrides
         WHERE company_id = :company_id'
    );
    $stmt->execute(['company_id' => $companyId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $level = (string) ($row['access_level'] ?? '');
        $capability = (string) ($row['capability'] ?? '');

        if (
            isset($matrix[$level])
            && array_key_exists($capability, $matrix[$level])
            && $level !== 'super_admin'
        ) {
            $matrix[$level][$capability] = (int) $row['is_allowed'] === 1;
        }
    }

    return $matrix;
}

function save_access_level_capabilities(
    int $companyId,
    array $submitted,
    int $updatedBy
): void {
    if ($companyId <= 0) {
        throw new InvalidArgumentException('A valid company is required.');
    }

    ensure_role_capability_schema();

    $levels = array_keys(ACCESS_LEVELS);
    $capabilities = [
        'view', 'create', 'edit', 'approve',
        'post', 'delete', 'report', 'admin',
    ];

    db()->beginTransaction();

    try {
        $delete = db()->prepare(
            'DELETE FROM role_capability_overrides
             WHERE company_id = :company_id'
        );
        $delete->execute(['company_id' => $companyId]);

        $insert = db()->prepare(
            'INSERT INTO role_capability_overrides
                (company_id, access_level, capability, is_allowed, updated_by)
             VALUES
                (:company_id, :access_level, :capability, :is_allowed, :updated_by)'
        );

        foreach ($levels as $level) {
            foreach ($capabilities as $capability) {
                $allowed = $level === 'super_admin'
                    ? 1
                    : (!empty($submitted[$level][$capability]) ? 1 : 0);

                $insert->execute([
                    'company_id' => $companyId,
                    'access_level' => $level,
                    'capability' => $capability,
                    'is_allowed' => $allowed,
                    'updated_by' => $updatedBy > 0 ? $updatedBy : null,
                ]);
            }
        }

        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        throw $exception;
    }
}
function current_access_level(): string
{
    $user = current_user();
    if (!$user) {
        return 'viewer';
    }
    $level = (string) ($user['access_level'] ?? '');
    if (($user['role'] ?? '') === 'admin' && ($level === '' || $level === 'accountant')) {
        $companyId = (int) ($user['company_id'] ?? 0);
        return in_array($companyId, [0, 1], true) ? 'super_admin' : 'parent_admin';
    }
    return $level !== '' ? $level : 'accountant';
}

function user_can(string $capability, ?string $level = null): bool
{
    // Client portal logins are governed by the role their organization's owner
    // assigned (owner/approver/entry_maker), not by the staff access-level
    // matrix — see client_portal_capability().
    if ($level === null && (string) (current_user()['role'] ?? '') === 'customer') {
        return client_portal_capability($capability);
    }

    $level = $level ?? current_access_level();
    $matrix = access_level_capabilities();
    return (bool) ($matrix[$level][$capability] ?? false);
}

function default_access_level_for_role(string $role): string
{
    return match ($role) {
        'admin' => 'parent_admin',
        'staff' => 'accountant',
        default => 'viewer',
    };
}

// ---------------------------------------------------------------------------
// Fiscal period locks (blueprint page 18).
// ---------------------------------------------------------------------------
function period_locked_through(int $companyId, int $fiscalYearId): ?string
{
    if ($companyId <= 0 || $fiscalYearId <= 0 || !table_exists('fiscal_period_locks')) {
        return null;
    }
    $stmt = db()->prepare('SELECT locked_through FROM fiscal_period_locks WHERE company_id = :company_id AND fiscal_year_id = :fiscal_year_id LIMIT 1');
    $stmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);
    $value = $stmt->fetchColumn();
    return $value !== false && $value !== null ? (string) $value : null;
}

function is_period_locked(int $companyId, int $fiscalYearId, string $date): bool
{
    $lockedThrough = period_locked_through($companyId, $fiscalYearId);
    if ($lockedThrough === null) {
        return false;
    }
    return strtotime($date) <= strtotime($lockedThrough . ' 23:59:59');
}

// ---------------------------------------------------------------------------
// Voucher approval workflow (blueprint pages 5, 19).
// ---------------------------------------------------------------------------
function approvals_enabled(): bool
{
    return (string) setting('approvals_enabled', '0') === '1';
}

function default_vat_rate(): float
{
    return (float) setting('default_vat_rate', setting('tax_invoice_tax_rate', '13'));
}

function activity_logs_for(string $entityType, int $entityId, int $limit = 20): array
{
    $stmt = db()->prepare('SELECT al.*, u.name AS actor_name FROM activity_logs al LEFT JOIN users u ON u.id = al.actor_id WHERE al.entity_type = :entity_type AND al.entity_id = :entity_id ORDER BY al.created_at DESC LIMIT :limit');
    $stmt->bindValue(':entity_type', $entityType);
    $stmt->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function get_available_companies(?array $user = null, ?array $company = null): array
{
    $user = $user ?? current_user();
    $company = $company ?? current_company();
    
    if (!$user || !$company) {
        return [];
    }
    
    $userCompanyId = (int) ($user['company_id'] ?? 0);
    $userRole = (string) ($user['role'] ?? 'customer');
    $currentCompanyId = (int) ($company['id'] ?? 0);
    
    // Super admin (associated with Altiora - id 1 OR no company_id) can see all companies
    if (($userCompanyId === 1 || $userCompanyId === 0) && $userRole === 'admin') {
        $stmt = db()->query('SELECT id, name, code FROM companies WHERE is_active = 1 ORDER BY parent_company_id ASC, name ASC');
        return $stmt->fetchAll();
    }
    
    // Company admin sees their company and all subsidiaries
    if ($userRole === 'admin') {
        $stmt = db()->prepare('SELECT id, name, code FROM companies WHERE (id = :id OR parent_company_id = :parent_id) AND is_active = 1 ORDER BY parent_company_id ASC, name ASC');
        $stmt->execute(['id' => $currentCompanyId, 'parent_id' => $currentCompanyId]);
        return $stmt->fetchAll();
    }
    
    // Staff and customers see only their company
    $stmt = db()->prepare('SELECT id, name, code FROM companies WHERE id = :id AND is_active = 1');
    $stmt->execute(['id' => $currentCompanyId]);
    return $stmt->fetchAll();
}

/**
 * Get invoice details with related information
 */
function get_invoice(int $invoiceId): ?array
{
    $stmt = db()->prepare('
        SELECT ti.*,
               c.name as company_name,
               ct.title as task_title,
               u.email as issued_by_email,
             u.name as issued_by_name,
               cp.organization_name as client_name,
               cp.pan_no as client_pan,
               cu.email as client_email,
               cu.phone as client_phone
        FROM task_invoices ti
        LEFT JOIN companies c ON ti.company_id = c.id
        LEFT JOIN client_tasks ct ON ti.task_id = ct.id
        LEFT JOIN client_profiles cp ON cp.id = ct.client_id
        LEFT JOIN users cu ON cu.id = cp.user_id
        LEFT JOIN users u ON ti.issued_by = u.id
        WHERE ti.id = :id
    ');
    $stmt->execute(['id' => $invoiceId]);
    $invoice = $stmt->fetch();
    
    // Get payment requests if exists
    if ($invoice) {
        $invoice['payment_requests'] = [];
        if (table_exists('invoice_payment_requests')) {
            $stmt = db()->prepare('SELECT * FROM invoice_payment_requests WHERE invoice_id = :id ORDER BY requested_on DESC');
            $stmt->execute(['id' => $invoiceId]);
            $invoice['payment_requests'] = $stmt->fetchAll() ?: [];
        }
        $invoice['line_items'] = [];
        if (table_exists('invoice_line_items')) {
            $stmt = db()->prepare('
                SELECT li.*, ii.sku, ii.name AS item_name
                FROM invoice_line_items li
                LEFT JOIN inventory_items ii ON ii.id = li.item_id
                WHERE li.invoice_id = :id
                ORDER BY li.id ASC
            ');
            $stmt->execute(['id' => $invoiceId]);
            $invoice['line_items'] = $stmt->fetchAll() ?: [];
        }
    }
    
    return $invoice;
}

/**
 * Get invoices for a company with optional filtering
 */
function get_company_invoices(int $companyId, array $filters = []): array
{
    $where = 'ti.company_id = :company_id';
    $params = ['company_id' => $companyId];
    
    // Filter by status
    if (!empty($filters['status'])) {
        $where .= ' AND ti.status = :status';
        $params['status'] = $filters['status'];
    }
    
    // Filter by invoice category (proforma/tax)
    if (!empty($filters['category'])) {
        $where .= ' AND ti.invoice_category = :category';
        $params['category'] = $filters['category'];
    }
    
    // Search by invoice number
    if (!empty($filters['search'])) {
        $where .= ' AND ti.invoice_no LIKE :search';
        $params['search'] = '%' . $filters['search'] . '%';
    }
    
    // Date range
    if (!empty($filters['from_date'])) {
        $where .= ' AND ti.issued_on >= :from_date';
        $params['from_date'] = $filters['from_date'];
    }
    if (!empty($filters['to_date'])) {
        $where .= ' AND ti.issued_on <= :to_date';
        $params['to_date'] = $filters['to_date'];
    }
    
    $stmt = db()->prepare("
        SELECT ti.id, ti.invoice_no, ti.invoice_category, ti.status, ti.amount, 
               ti.total_amount, ti.issued_on, ti.due_on, ct.title as task_title
        FROM task_invoices ti
        LEFT JOIN client_tasks ct ON ti.task_id = ct.id
        WHERE $where
        ORDER BY ti.issued_on DESC, ti.id DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/**
 * Convert proforma invoice to tax invoice
 */
function convert_to_tax_invoice(int $invoiceId, int $userId): bool
{
    $invoice = get_invoice($invoiceId);
    
    if (!$invoice) {
        return false;
    }
    
    if ($invoice['invoice_category'] !== 'proforma') {
        return false;
    }
    
    try {
        $stmt = db()->prepare('
            UPDATE task_invoices 
            SET invoice_category = :category,
                tax_invoice_date = NOW(),
                converted_to_tax_on = NOW(),
                converted_by = :user_id,
                status = :status
            WHERE id = :id
        ');
        
        $stmt->execute([
            'category' => 'tax',
            'user_id' => $userId,
            'status' => 'issued',
            'id' => $invoiceId
        ]);
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Create payment request for invoice
 */
function create_payment_request(
    int $invoiceId, 
    int $companyId, 
    int $requestedBy, 
    float $amount, 
    ?string $paymentMethod = null, 
    ?string $notes = null
): int|false
{
    try {
        $stmt = db()->prepare('
            INSERT INTO invoice_payment_requests 
            (invoice_id, company_id, requested_by, amount_requested, payment_method, notes)
            VALUES (:invoice_id, :company_id, :requested_by, :amount, :method, :notes)
        ');
        
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'company_id' => $companyId,
            'requested_by' => $requestedBy,
            'amount' => $amount,
            'method' => $paymentMethod,
            'notes' => $notes
        ]);
        
        return (int) db()->lastInsertId();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Format VAT amount for display
 */
function format_vat_display(float $amount, float $rate = 13.00): string
{
    return 'NPR ' . number_format($amount, 2) . ' @ ' . $rate . '%';
}

/**
 * Whether an invoice may still be edited. Draft invoices are always editable;
 * proforma invoices stay editable until paid/cancelled. Tax invoices are locked
 * (Nepal VAT: no edits after conversion).
 */
function invoice_is_editable(array $invoice): bool
{
    $status = (string) ($invoice['status'] ?? 'draft');
    $category = (string) ($invoice['invoice_category'] ?? 'proforma');

    if (in_array($status, ['paid', 'cancelled'], true)) {
        return false;
    }

    return $status === 'draft' || $category === 'proforma';
}

/**
 * Get invoice status badge HTML
 */
function get_invoice_status_badge(array $invoice): string
{
    $category = $invoice['invoice_category'] ?? 'proforma';
    $status = $invoice['status'] ?? 'draft';
    
    $categoryBadge = match($category) {
        'proforma' => '<span class="badge badge-info">Proforma</span>',
        'tax' => '<span class="badge badge-success">Tax Invoice</span>',
        default => '<span class="badge badge-secondary">Unknown</span>'
    };
    
    $statusBadge = match($status) {
        'draft' => '<span class="badge badge-warning">Draft</span>',
        'issued' => '<span class="badge badge-primary">Issued</span>',
        'paid' => '<span class="badge badge-success">Paid</span>',
        'cancelled' => '<span class="badge badge-danger">Cancelled</span>',
        default => '<span class="badge badge-secondary">Unknown</span>'
    };
    
    return "$categoryBadge $statusBadge";
}

/**
 * ========================================
 * EXPORT FUNCTIONS (PDF, Excel, CSV)
 * ========================================
 */

/**
 * Format number as currency
 */
function format_currency(float $amount, string $currency = 'NPR'): string
{
    $formatted = number_format($amount, 2, '.', ',');
    return "$currency $formatted";
}

/**
 * Export invoice as printable HTML (can be printed to PDF)
 */
/**
 * Amount in words using the Nepali/Indian numbering system
 * (crore, lakh, thousand). E.g. 33900 -> "Thirty Three Thousand
 * Nine Hundred Rupees Only".
 */
function npr_amount_in_words(float $amount): string
{
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $twoDigits = static function (int $n) use ($ones, $tens): string {
        if ($n < 20) {
            return $ones[$n];
        }
        return trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
    };

    $rupees = (int) floor(round($amount, 2));
    $paisa = (int) round((round($amount, 2) - $rupees) * 100);

    if ($rupees === 0 && $paisa === 0) {
        return 'Zero Rupees Only';
    }

    $parts = [];
    $crore = intdiv($rupees, 10000000);
    $lakh = intdiv($rupees % 10000000, 100000);
    $thousand = intdiv($rupees % 100000, 1000);
    $hundred = intdiv($rupees % 1000, 100);
    $rest = $rupees % 100;

    if ($crore > 0) {
        $parts[] = ($crore >= 100 ? npr_amount_in_words((float) $crore) : $twoDigits($crore));
        $parts[count($parts) - 1] = str_replace(' Rupees Only', '', (string) $parts[count($parts) - 1]) . ' Crore';
    }
    if ($lakh > 0) {
        $parts[] = $twoDigits($lakh) . ' Lakh';
    }
    if ($thousand > 0) {
        $parts[] = $twoDigits($thousand) . ' Thousand';
    }
    if ($hundred > 0) {
        $parts[] = $ones[$hundred] . ' Hundred';
    }
    if ($rest > 0) {
        $parts[] = $twoDigits($rest);
    }

    $words = trim(implode(' ', $parts));
    $result = ($words !== '' ? $words . ' Rupees' : '');
    if ($paisa > 0) {
        $result .= ($result !== '' ? ' and ' : '') . $twoDigits($paisa) . ' Paisa';
    }
    return trim($result) . ' Only';
}

function export_invoice_html(array $invoice): string
{
    $companyId = (int) ($invoice['company_id'] ?? 0);
    $company = company_by_id($companyId);
    $companyName = (string) ($company['name'] ?? app_name());
    $companyNameNp = (string) setting('company_name_np', '');
    $companyAddress = (string) setting('office_address', 'Kathmandu, Nepal');
    $companyPan = (string) setting('company_pan', '');
    $companyVat = (string) setting('company_vat_no', $companyPan);
    $companyPhone = (string) setting('support_phone', '');
    $companyEmail = (string) setting('support_email', '');
    // Only reference brand images whose files actually exist, so a missing
    // upload never prints a broken-image icon on the invoice.
    $assetIfExists = static function (string $settingKey): string {
        $path = trim((string) setting($settingKey, ''), '/');
        if ($path === '') {
            return '';
        }
        return is_file(__DIR__ . '/../public_html/' . $path) ? $path : '';
    };
    $logoPath = $assetIfExists('company_logo_path');
    $signaturePath = $assetIfExists('company_signature_path');
    $stampPath = $assetIfExists('company_stamp_path');
    $qrPath = $assetIfExists('company_qr_path');

    $bankName = (string) setting('bank_name', '');
    $bankAccountName = (string) setting('bank_account_name', $companyName);
    $bankAccountNumber = (string) setting('bank_account_number', '');
    $bankBranch = (string) setting('bank_branch', '');

    $invoiceNo = (string) ($invoice['invoice_no'] ?? 'N/A');
    $category = (string) ($invoice['invoice_category'] ?? 'proforma');
    $isTax = $category === 'tax';
    $sourceType = (string) ($invoice['invoice_source_type'] ?? 'task');
    $issuedOn = (string) ($invoice['issued_on'] ?? date('Y-m-d'));
    $issuedOnDisplay = date('d/m/Y', strtotime($issuedOn));
    $issuedOnBs = function_exists('bs_format') ? bs_format($issuedOn) : '';

    $fiscalYearLabel = '';
    try {
        foreach (fiscal_years_for_company($companyId, false) as $fyRow) {
            $fyStart = (string) ($fyRow['start_date'] ?? '');
            $fyEnd = (string) ($fyRow['end_date'] ?? '');
            if ($fyStart !== '' && $fyEnd !== '' && $issuedOn >= $fyStart && $issuedOn <= $fyEnd) {
                $fiscalYearLabel = (string) ($fyRow['label'] ?? '');
                break;
            }
            if ((int) ($fyRow['is_default'] ?? 0) === 1 && $fiscalYearLabel === '') {
                $fiscalYearLabel = (string) ($fyRow['label'] ?? '');
            }
        }
    } catch (Throwable $exception) {
        $fiscalYearLabel = '';
    }

    $clientName = (string) ($invoice['client_name'] ?? 'Client');
    $clientEmail = (string) ($invoice['client_email'] ?? '');
    $clientPhone = (string) ($invoice['client_phone'] ?? '');
    $clientPan = (string) ($invoice['client_pan'] ?? '');

    $vatRate = (float) ($invoice['vat_rate'] ?? 13.00);
    // The VAT columns default to 0.00 (not NULL), so null-coalescing alone
    // never reaches `amount` on rows written by amount-only insert paths —
    // treat a zero taxable/total as "not computed" and rebuild from `amount`.
    $taxableAmount = (float) ($invoice['taxable_amount'] ?? 0);
    if ($taxableAmount <= 0) {
        $taxableAmount = (float) ($invoice['amount'] ?? 0);
    }
    $showExcise = $sourceType === 'manufacturing';
    $exciseRate = $showExcise
        ? (float) ($invoice['excise_rate'] ?? company_accounting_default_excise_rate($companyId))
        : 0.0;
    $exciseAmount = (float) ($invoice['excise_amount'] ?? 0);
    $vatAmount = (float) ($invoice['vat_amount'] ?? 0);
    $vatBase = $taxableAmount + $exciseAmount;
    if ($vatAmount <= 0 && $vatBase > 0) {
        $vatAmount = round($vatBase * ($vatRate / 100), 2);
    }
    $totalAmount = (float) ($invoice['total_amount'] ?? 0);
    if ($totalAmount <= 0) {
        $totalAmount = round($taxableAmount + $exciseAmount + $vatAmount, 2);
    }
    $discountAmount = (float) ($invoice['discount_amount'] ?? 0);
    $amountWords = npr_amount_in_words($totalAmount);

    $lineItems = $invoice['line_items'] ?? [];
    if ($lineItems === []) {
        $lineItems = [[
            'description' => $invoice['description'] ?? ($invoice['task_title'] ?? 'Professional services'),
            'quantity' => 1,
            'rate' => $taxableAmount + $discountAmount,
            'taxable_amount' => $taxableAmount + $discountAmount,
        ]];
    }

    $lineRows = '';
    foreach ($lineItems as $index => $line) {
        $qty = (float) ($line['quantity'] ?? 1);
        $rate = (float) ($line['rate'] ?? 0);
        $lineAmount = (float) ($line['taxable_amount'] ?? 0);
        if ($lineAmount <= 0) {
            $lineAmount = round($qty * $rate, 2);
        }
        if ($rate <= 0 && $qty > 0) {
            $rate = $lineAmount / $qty;
        }
        $titleSource = trim((string) ($line['item_name'] ?? ''));
        $descriptionText = trim((string) ($line['description'] ?? ''));
        if ($titleSource === '') {
            // Split "Title - detail" / first sentence into a bold title + detail line.
            $splitAt = strpos($descriptionText, ' - ');
            if ($splitAt === false) {
                $splitAt = strpos($descriptionText, '. ');
            }
            if ($splitAt !== false && $splitAt > 3) {
                $titleSource = substr($descriptionText, 0, $splitAt);
                $descriptionText = ltrim(substr($descriptionText, $splitAt), ' -.');
            } else {
                $titleSource = $descriptionText !== '' ? $descriptionText : 'Professional services';
                $descriptionText = '';
            }
        }
        $lineRows .= '<tr>'
            . '<td class="c">' . e((string) ($index + 1)) . '</td>'
            . '<td><strong>' . e($titleSource) . '</strong>'
            . ($descriptionText !== '' ? '<br><span class="line-sub">' . e($descriptionText) . '</span>' : '')
            . '</td>'
            . '<td class="c">' . e(rtrim(rtrim(number_format($qty, 2), '0'), '.')) . '</td>'
            . '<td class="r">' . e(number_format($rate, 2)) . '</td>'
            . '<td class="r">' . e(number_format($lineAmount, 2)) . '</td>'
            . '</tr>';
    }

    $docTitle = $isTax ? 'TAX<br>INVOICE' : 'PROFORMA<br>INVOICE';
    $docSubtitle = $isTax ? 'कर बिजक (Tax Invoice)' : 'प्रोफर्मा बिल (Estimate Only)';
    $docBadge = $isTax
        ? '<span class="doc-badge is-valid">VALID TAX DOCUMENT — VAT ACT 2052</span>'
        : '<span class="doc-badge">NOT A VALID TAX DOCUMENT</span>';
    $footNote = $isTax
        ? 'TAX INVOICE — Issued in accordance with the Nepal Value Added Tax Act, 2052 and VAT Rules, 2053. Please retain this document for your records.'
        : 'PROFORMA INVOICE — This is an estimate only and NOT a valid tax document. A formal Tax Invoice will be issued upon confirmation.';

    $logoHtml = $logoPath !== ''
        ? '<img class="logo" src="' . e(url($logoPath)) . '" alt="Logo">'
        : '<span class="logo logo-placeholder">' . e(strtoupper(substr($companyName, 0, 2))) . '</span>';
    $qrHtml = $qrPath !== ''
        ? '<img class="qr" src="' . e(url($qrPath)) . '" alt="Payment QR">'
        : '';
    $signatureHtml = '';
    if ($signaturePath !== '') {
        $signatureHtml .= '<img class="sign-img" src="' . e(url($signaturePath)) . '" alt="Signature">';
    }
    if ($stampPath !== '') {
        $signatureHtml .= '<img class="stamp-img" src="' . e(url($stampPath)) . '" alt="Stamp">';
    }

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice ' . e($invoiceNo) . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Segoe UI", Arial, sans-serif; color: #26313f; background: #f2f4f7; line-height: 1.5; font-size: 13px; }
        .sheet { max-width: 860px; margin: 18px auto; padding: 34px 38px; background: #fff; box-shadow: 0 4px 24px rgba(20,35,60,0.10); }
        .top { display: flex; justify-content: space-between; gap: 24px; }
        .identity { display: flex; gap: 14px; }
        .logo { width: 74px; height: 74px; object-fit: contain; border: 1px solid #d8dfe8; padding: 4px; }
        .logo-placeholder { display: grid; place-items: center; font: 700 24px Georgia, serif; color: #7a1f1f; }
        .identity h1 { color: #7a1f1f; font-size: 23px; line-height: 1.2; font-weight: 800; }
        .identity .np-name { color: #44515f; font-size: 13px; margin-top: 2px; }
        .identity .meta { color: #5c6a79; font-size: 11.5px; margin-top: 5px; line-height: 1.55; }
        .doc { text-align: right; min-width: 250px; }
        .doc h2 { color: #b05a10; font-size: 27px; font-weight: 800; line-height: 1.08; letter-spacing: 0.5px; }
        .doc .np { color: #5c6a79; font-size: 12px; margin-top: 3px; }
        .doc-badge { display: inline-block; margin-top: 6px; padding: 3px 10px; border: 1px solid #d9a33a; color: #a06508; font-size: 10.5px; font-weight: 700; letter-spacing: 0.04em; background: #fffaf0; }
        .doc-badge.is-valid { border-color: #1c7c3c; color: #14602d; background: #f0faf3; }
        .doc .nums { margin-top: 9px; font-size: 12px; color: #44515f; line-height: 1.6; }
        .doc .nums strong { color: #1a2635; }
        .rule { height: 4px; background: #16325d; margin: 18px 0 20px; }
        .boxes { display: flex; gap: 16px; }
        .box { flex: 1; border: 1px solid #d8dfe8; padding: 13px 16px; min-height: 108px; }
        .box h3 { color: #8a97a5; font-size: 10.5px; font-weight: 700; letter-spacing: 0.09em; margin-bottom: 7px; }
        .box .primary { color: #1a2635; font-weight: 700; font-size: 14px; }
        .box p { font-size: 12px; color: #44515f; margin-top: 2px; }
        .pay-flex { display: flex; justify-content: space-between; gap: 12px; }
        .qr { width: 76px; height: 76px; object-fit: contain; border: 1px solid #d8dfe8; padding: 3px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 22px; }
        table.items th { padding: 9px 10px; color: #8a97a5; font-size: 10.5px; letter-spacing: 0.08em; text-align: left; border-bottom: 1.5px solid #d8dfe8; }
        table.items td { padding: 11px 10px; font-size: 12.5px; border-bottom: 1px solid #e8edf3; vertical-align: top; }
        table.items .c { text-align: center; }
        table.items .r { text-align: right; }
        table.items th.r { text-align: right; }
        table.items th.c { text-align: center; }
        .line-sub { color: #5c6a79; font-size: 11.5px; }
        table.totals { width: 100%; border-collapse: collapse; }
        table.totals td { padding: 8px 10px; font-size: 12.5px; }
        table.totals .label { text-align: left; color: #44515f; }
        table.totals .value { text-align: right; color: #1a2635; }
        table.totals tr.sep td { border-bottom: 1px solid #e8edf3; }
        table.totals tr.grand td { border-top: 2.5px solid #16325d; padding-top: 11px; font-size: 15px; font-weight: 800; }
        table.totals tr.grand .label { color: #7a1f1f; }
        table.totals tr.grand .value { color: #7a1f1f; }
        .words { margin-top: 16px; border: 1px solid #d8dfe8; padding: 10px 14px; font-size: 12px; }
        .words b { color: #1a2635; }
        .words .cap { color: #8a97a5; font-size: 10.5px; letter-spacing: 0.09em; font-weight: 700; margin-right: 8px; }
        .signs { display: flex; justify-content: space-between; gap: 40px; margin-top: 52px; }
        .sign { flex: 1; max-width: 300px; text-align: center; font-size: 11.5px; color: #44515f; }
        .sign .line { border-top: 1px solid #9aa7b4; margin-bottom: 6px; padding-top: 6px; }
        .sign-img { max-height: 46px; display: block; margin: 0 auto 2px; }
        .stamp-img { max-height: 56px; display: block; margin: 4px auto 0; opacity: 0.9; }
        .foot { margin-top: 34px; padding-top: 12px; border-top: 1px solid #e8edf3; color: #8a97a5; font-size: 10.5px; text-align: center; }
        .print-button { display: block; margin: 16px auto 30px; padding: 10px 22px; background: #16325d; color: #fff; border: 0; border-radius: 6px; font-size: 13px; cursor: pointer; }
        @media print {
            body { background: #fff; }
            .sheet { margin: 0; padding: 8mm 6mm; box-shadow: none; max-width: none; }
            .print-button { display: none; }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="top">
            <div class="identity">
                ' . $logoHtml . '
                <div>
                    <h1>' . e($companyName) . '</h1>
                    ' . ($companyNameNp !== '' ? '<div class="np-name">' . e($companyNameNp) . '</div>' : '') . '
                    <div class="meta">
                        ' . e($companyAddress) . '<br>
                        ' . ($companyPan !== '' ? 'PAN: ' . e($companyPan) : '') . ($companyVat !== '' ? ' | VAT Reg: ' . e($companyVat) : '') . '<br>
                        ' . ($companyPhone !== '' ? 'Tel: ' . e($companyPhone) : '') . ($companyEmail !== '' ? ' | ' . e($companyEmail) : '') . '
                    </div>
                </div>
            </div>
            <div class="doc">
                <h2>' . $docTitle . '</h2>
                <div class="np">' . e($docSubtitle) . '</div>
                ' . $docBadge . '
                <div class="nums">
                    <strong>Invoice No:</strong> ' . e($invoiceNo) . '<br>
                    <strong>Date:</strong> ' . e($issuedOnDisplay) . ($issuedOnBs !== '' ? ' | <strong>Miti:</strong> ' . e($issuedOnBs) . ' BS' : '') . '<br>
                    ' . ($fiscalYearLabel !== '' ? '<strong>Fiscal Year:</strong> ' . e($fiscalYearLabel) : '') . '
                </div>
            </div>
        </div>

        <div class="rule"></div>

        <div class="boxes">
            <div class="box">
                <h3>BILL TO / खरिदकर्ता</h3>
                <div class="primary">' . e($clientName) . '</div>
                ' . ($clientPan !== '' ? '<p>PAN: ' . e($clientPan) . '</p>' : '') . '
                ' . ($clientEmail !== '' ? '<p>' . e($clientEmail) . '</p>' : '') . '
                ' . ($clientPhone !== '' ? '<p>' . e($clientPhone) . '</p>' : '') . '
            </div>
            <div class="box">
                <div class="pay-flex">
                    <div>
                        <h3>PAYMENT INFO</h3>
                        ' . ($bankName !== '' ? '<div class="primary">' . e($bankName) . '</div>' : '<p>Payment details available on request.</p>') . '
                        ' . ($bankAccountName !== '' && $bankName !== '' ? '<p>A/C Name: ' . e($bankAccountName) . '</p>' : '') . '
                        ' . ($bankAccountNumber !== '' ? '<p>A/C No: ' . e($bankAccountNumber) . '</p>' : '') . '
                        ' . ($bankBranch !== '' ? '<p>Branch: ' . e($bankBranch) . '</p>' : '') . '
                    </div>
                    ' . $qrHtml . '
                </div>
            </div>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th class="c" style="width:44px">S.N.</th>
                    <th>DESCRIPTION OF SERVICE</th>
                    <th class="c" style="width:60px">QTY</th>
                    <th class="r" style="width:110px">RATE (NPR)</th>
                    <th class="r" style="width:120px">AMOUNT (NPR)</th>
                </tr>
            </thead>
            <tbody>' . $lineRows . '</tbody>
        </table>

        <table class="totals">
            ' . ($discountAmount > 0 ? '<tr class="sep"><td class="label">Discount (छुट)</td><td class="value">- ' . e(number_format($discountAmount, 2)) . '</td></tr>' : '') . '
            <tr class="sep"><td class="label">Taxable Amount</td><td class="value">' . e(number_format($taxableAmount, 2)) . '</td></tr>
            ' . ($showExcise ? '<tr class="sep"><td class="label">Excise Duty @ ' . e(number_format($exciseRate, 2)) . '% (अन्तःशुल्क)</td><td class="value">' . e(number_format($exciseAmount, 2)) . '</td></tr>' : '') . '
            <tr class="sep"><td class="label">VAT @ ' . e(rtrim(rtrim(number_format($vatRate, 2), '0'), '.')) . '% (मूल्य अभिवृद्धि कर)</td><td class="value">' . e(number_format($vatAmount, 2)) . '</td></tr>
            <tr class="grand"><td class="label">Grand Total (जम्मा रकम)</td><td class="value">NPR ' . e(number_format($totalAmount, 2)) . '</td></tr>
        </table>

        <div class="words"><span class="cap">AMOUNT IN WORDS:</span><b>' . e($amountWords) . '</b></div>

        <div class="signs">
            <div class="sign">
                <div class="line">Client Signature / Stamp<br>' . e($clientName) . '</div>
            </div>
            <div class="sign">
                ' . $signatureHtml . '
                <div class="line">Authorized Signature / Stamp<br>For ' . e($companyName) . '</div>
            </div>
        </div>

        <div class="foot">' . e($footNote) . '</div>
    </div>
    <button class="print-button" onclick="window.print()">Print / Save as PDF</button>
</body>
</html>';
}

/**
 * Export invoice as printable PDF document
 * Note: This generates HTML that can be printed to PDF using browser print dialog
 * For server-side PDF generation, install mPDF or TCPDF library
 */
function export_invoice_to_pdf_html(int $invoiceId): string
{
    $invoice = get_invoice($invoiceId);
    if (!$invoice) {
        return '';
    }
    
    return export_invoice_html($invoice);
}

/**
 * Generate simple Excel/CSV export for invoice
 */
function export_invoice_to_excel(int $invoiceId): array
{
    $invoice = get_invoice($invoiceId);
    if (!$invoice) {
        return [];
    }
    
    // Prepare CSV data
    $rows = [];
    
    // Header
    $rows[] = ['Invoice Export - ' . date('Y-m-d H:i:s')];
    $rows[] = [];
    
    // Company and Invoice Info
    $company = company_by_id((int) $invoice['company_id']);
    $sourceType = (string) ($invoice['invoice_source_type'] ?? 'task');
    $templateLabel = match ($sourceType) {
        'inventory' => 'Trading Template',
        'manufacturing' => 'Manufacturing Template',
        default => 'Service Template',
    };
    $exciseRate = $sourceType === 'manufacturing'
        ? (float) ($invoice['excise_rate'] ?? company_accounting_default_excise_rate((int) $invoice['company_id']))
        : 0.0;
    $exciseAmount = (float) ($invoice['excise_amount'] ?? 0);
    $rows[] = ['Company', $company['name'] ?? 'N/A'];
    $rows[] = ['Invoice ID', $invoice['id']];
    $rows[] = ['Invoice Type', ucfirst($invoice['invoice_category'] ?? 'invoice')];
    $rows[] = ['Template', $templateLabel];
    $rows[] = ['Source Type', ucfirst($sourceType)];
    $rows[] = ['Status', ucfirst($invoice['status'] ?? 'draft')];
    $rows[] = [];
    
    // Details
    $rows[] = ['Client', $invoice['client_name'] ?? 'N/A'];
    $rows[] = ['Email', $invoice['client_email'] ?? ''];
    $rows[] = ['Phone', $invoice['client_phone'] ?? ''];
    $rows[] = [];
    
    // Invoice Amounts
    $totalCurrency = format_currency((float) $invoice['total_amount'] ?? 0);
    $rows[] = ['Subtotal', $invoice['subtotal'] ?? 0];
    $rows[] = ['Taxable Amount', $invoice['taxable_amount'] ?? 0];
    if ($sourceType === 'manufacturing') {
        $rows[] = ['Excise Rate (%)', $exciseRate];
        $rows[] = ['Excise Amount', $exciseAmount];
    }
    $rows[] = ['VAT Rate (%)', $invoice['vat_rate'] ?? 13];
    $rows[] = ['VAT Amount', $invoice['vat_amount'] ?? 0];
    $rows[] = ['Total Amount', $totalCurrency];
    $rows[] = [];
    $rows[] = ['Generated on', date('Y-m-d H:i:s')];
    
    return [
        'filename' => 'invoice_' . (int) $invoice['id'] . '.csv',
        'rows' => $rows,
        'data' => $rows,
    ];
}

/**
 * Get service contract with company/client metadata
 */
function get_service_contract(int $contractId): ?array
{
    if (!table_exists('service_contracts')) {
        return null;
    }

    $stmt = db()->prepare('SELECT sc.*, c.name AS company_name, c.code AS company_code, cp.organization_name, cp.pan_no, u.name AS created_by_name
        FROM service_contracts sc
        LEFT JOIN companies c ON c.id = sc.company_id
        LEFT JOIN client_profiles cp ON cp.id = sc.client_id
        LEFT JOIN users u ON u.id = sc.created_by
        WHERE sc.id = :id
        LIMIT 1');
    $stmt->execute(['id' => $contractId]);
    $contract = $stmt->fetch();

    return $contract ?: null;
}

/**
 * Export service contract as printable HTML template
 */
function export_contract_html(array $contract): string
{
    $companyName = $contract['company_name'] ?? app_name();
    $clientName = $contract['organization_name'] ?? 'Client';
    $contractNo = $contract['contract_no'] ?? 'N/A';
    $contractTitle = $contract['title'] ?? 'Service Agreement';
    $startDate = $contract['start_date'] ?? 'N/A';
    $endDate = $contract['end_date'] ?? 'N/A';
    $totalValue = format_currency((float) ($contract['total_value'] ?? 0));
    $billingCycle = $contract['billing_cycle'] ?: 'one_time';
    $status = ucfirst((string) ($contract['status'] ?? 'draft'));
    $panNo = $contract['pan_no'] ?? 'N/A';
    $createdBy = $contract['created_by_name'] ?? 'Authorized Representative';
    $terms = trim((string) ($contract['terms'] ?? ''));

    $customTerms = $terms !== ''
        ? '<p style="white-space:pre-wrap;">' . e($terms) . '</p>'
        : '<p>Custom commercial terms are not specified. Standard clauses below apply.</p>';

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contract ' . e($contractNo) . '</title>
    <style>
        body { font-family: "Segoe UI", Arial, sans-serif; color: #1f2937; margin: 0; background: #f3f4f6; }
        .sheet { max-width: 980px; margin: 24px auto; background: #fff; padding: 28px; border: 1px solid #e5e7eb; }
        .head { display: flex; justify-content: space-between; gap: 16px; border-bottom: 3px solid #1f2937; padding-bottom: 12px; }
        .head h1 { margin: 0; font-size: 24px; }
        .muted { color: #6b7280; font-size: 12px; }
        .meta { margin-top: 14px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 16px; }
        .meta div { font-size: 14px; }
        h2 { margin-top: 22px; margin-bottom: 8px; font-size: 17px; border-left: 4px solid #2563eb; padding-left: 8px; }
        p { font-size: 14px; line-height: 1.6; margin: 8px 0; }
        ul { margin: 6px 0 6px 20px; }
        li { margin: 4px 0; font-size: 14px; }
        .sign { margin-top: 28px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 28px; }
        .signbox { border-top: 1px solid #9ca3af; padding-top: 8px; font-size: 13px; }
        .legal { margin-top: 22px; background: #f9fafb; border: 1px solid #e5e7eb; padding: 12px; }
        @media print { body { background: #fff; } .sheet { margin: 0; border: 0; } }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="head">
            <div>
                <h1>Service Contract</h1>
                <div class="muted">Contract No: ' . e($contractNo) . '</div>
                <div class="muted">Status: ' . e($status) . '</div>
            </div>
            <div style="text-align:right;">
                <strong>' . e($companyName) . '</strong><br>
                <span class="muted">Client: ' . e($clientName) . '</span><br>
                <span class="muted">Client PAN/Tax ID: ' . e($panNo) . '</span>
            </div>
        </div>

        <div class="meta">
            <div><strong>Contract Title:</strong> ' . e($contractTitle) . '</div>
            <div><strong>Total Contract Value:</strong> ' . e($totalValue) . '</div>
            <div><strong>Start Date:</strong> ' . e($startDate) . '</div>
            <div><strong>End Date:</strong> ' . e($endDate) . '</div>
            <div><strong>Billing Cycle:</strong> ' . e($billingCycle) . '</div>
            <div><strong>Prepared By:</strong> ' . e($createdBy) . '</div>
        </div>

        <h2>1. Scope of Services</h2>
        <p>The Service Provider agrees to provide the services described under this agreement with due care, skill, and professional diligence.</p>

        <h2>2. Payment and Tax</h2>
        <p>Client shall pay fees as per billing cycle and milestones. Applicable taxes, including VAT where required by law, shall be borne and paid in accordance with prevailing tax law of Nepal.</p>

        <h2>3. Term and Termination</h2>
        <p>This contract remains in force for the stated term unless terminated earlier for material breach, insolvency, or mutual written agreement. Outstanding dues remain payable after termination.</p>

        <h2>4. Confidentiality and Data Handling</h2>
        <p>Both parties shall keep confidential all non-public business, financial, and technical information obtained during the contract period and thereafter as required by law.</p>

        <h2>5. Liability and Indemnity</h2>
        <p>Each party is liable for direct losses caused by its breach. Neither party is liable for indirect or consequential losses except where mandated by law.</p>

        <h2>6. Dispute Resolution and Governing Law</h2>
        <p>This agreement is governed by the laws of Nepal, including the Contract Act, 2056 (2000). Parties shall first attempt amicable settlement; unresolved disputes may be referred to competent courts/arbitration in Nepal, as agreed.</p>

        <h2>7. Custom Terms</h2>
        ' . $customTerms . '

        <div class="legal">
            <strong>Legal Notice:</strong>
            <ul>
                <li>This template is prepared to align with common Nepali commercial contracting practice and the Contract Act, 2056.</li>
                <li>For sector-specific or high-value engagements, independent legal review is recommended before execution.</li>
            </ul>
        </div>

        <div class="sign">
            <div class="signbox">
                <strong>For Service Provider</strong><br>
                Name: ________________________<br>
                Signature: _____________________<br>
                Date: _________________________
            </div>
            <div class="signbox">
                <strong>For Client</strong><br>
                Name: ________________________<br>
                Signature: _____________________<br>
                Date: _________________________
            </div>
        </div>
    </div>
</body>
</html>';
}

function export_contract_to_pdf_html(int $contractId): string
{
    $contract = get_service_contract($contractId);
    if (!$contract) {
        return '';
    }

    return export_contract_html($contract);
}

/**
 * Get financial summary for a company for given fiscal year
 * Returns total income, expenses, profit, and profit margin
 */
function get_financial_summary(int $companyId, int $fiscalYearId): array
{
    $db = db();
    
    // Get all ledger entries for the fiscal year
    $query = '
        SELECT 
            l.id, l.code, l.name, l.type,
            SUM(CASE WHEN ve.entry_type = "debit" THEN ve.amount ELSE 0 END) as total_debit,
            SUM(CASE WHEN ve.entry_type = "credit" THEN ve.amount ELSE 0 END) as total_credit
        FROM ledgers l
        LEFT JOIN voucher_entries ve ON l.id = ve.ledger_id
        LEFT JOIN vouchers v ON ve.voucher_id = v.id
            AND v.company_id = l.company_id
            AND v.fiscal_year_id = :fiscal_year_id
            AND v.status = "posted"
        WHERE l.company_id = :company_id
        GROUP BY l.id, l.code, l.name, l.type
        ORDER BY l.code
    ';
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':company_id' => $companyId,
        ':fiscal_year_id' => $fiscalYearId,
    ]);
    
    $ledgers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalIncome = 0;
    $totalExpenses = 0;
    $incomeBreakdown = [];
    $expenseBreakdown = [];
    
    foreach ($ledgers as $ledger) {
        $balance = (float) ($ledger['total_debit'] ?? 0) - (float) ($ledger['total_credit'] ?? 0);
        
        if (in_array($ledger['type'], ['revenue', 'income'])) {
            // Credit side is positive for income
            $balance = (float) ($ledger['total_credit'] ?? 0) - (float) ($ledger['total_debit'] ?? 0);
            $totalIncome += abs($balance);
            if ($balance != 0) {
                $incomeBreakdown[] = [
                    'code' => $ledger['code'],
                    'name' => $ledger['name'],
                    'amount' => abs($balance),
                ];
            }
        } elseif (in_array($ledger['type'], ['expense', 'cost'])) {
            // Debit side is positive for expenses
            $totalExpenses += abs($balance);
            if ($balance != 0) {
                $expenseBreakdown[] = [
                    'code' => $ledger['code'],
                    'name' => $ledger['name'],
                    'amount' => abs($balance),
                ];
            }
        }
    }
    
    $netProfit = $totalIncome - $totalExpenses;
    $profitMargin = $totalIncome > 0 ? (($netProfit / $totalIncome) * 100) : 0;
    
    return [
        'total_income' => $totalIncome,
        'total_expenses' => $totalExpenses,
        'net_profit' => $netProfit,
        'profit_margin' => $profitMargin,
        'income_breakdown' => $incomeBreakdown,
        'expense_breakdown' => $expenseBreakdown,
    ];
}

function get_balance_sheet_summary(int $companyId, int $fiscalYearId): array
{
    if (!table_exists('ledgers') || !table_exists('voucher_entries') || !table_exists('vouchers')) {
        return [
            'assets' => [],
            'liabilities' => [],
            'equity' => [],
            'total_assets' => 0.0,
            'total_liabilities' => 0.0,
            'total_equity' => 0.0,
        ];
    }

    $stmt = db()->prepare('
        SELECT
            l.id,
            l.code,
            l.name,
            l.type,
            COALESCE(SUM(CASE WHEN ve.entry_type = "debit" THEN ve.amount ELSE 0 END), 0) AS total_debit,
            COALESCE(SUM(CASE WHEN ve.entry_type = "credit" THEN ve.amount ELSE 0 END), 0) AS total_credit
        FROM ledgers l
        LEFT JOIN voucher_entries ve ON ve.ledger_id = l.id
        LEFT JOIN vouchers v ON v.id = ve.voucher_id
            AND v.company_id = l.company_id
            AND v.fiscal_year_id = :fiscal_year_id
            AND v.status = "posted"
        WHERE l.company_id = :company_id
          AND l.type IN ("asset", "liability", "equity")
        GROUP BY l.id, l.code, l.name, l.type
        ORDER BY l.code ASC
    ');
    $stmt->execute([
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId,
    ]);

    $summary = [
        'assets' => [],
        'liabilities' => [],
        'equity' => [],
        'total_assets' => 0.0,
        'total_liabilities' => 0.0,
        'total_equity' => 0.0,
    ];

    foreach ($stmt->fetchAll() as $ledger) {
        $debit = (float) ($ledger['total_debit'] ?? 0);
        $credit = (float) ($ledger['total_credit'] ?? 0);
        $amount = $ledger['type'] === 'asset' ? $debit - $credit : $credit - $debit;

        $row = [
            'code' => $ledger['code'],
            'name' => $ledger['name'],
            'amount' => $amount,
        ];

        if ($ledger['type'] === 'asset') {
            $summary['assets'][] = $row;
            $summary['total_assets'] += $amount;
        } elseif ($ledger['type'] === 'liability') {
            $summary['liabilities'][] = $row;
            $summary['total_liabilities'] += $amount;
        } else {
            $summary['equity'][] = $row;
            $summary['total_equity'] += $amount;
        }
    }

    return $summary;
}

function matching_fiscal_year_id_for_company(int $companyId, int $referenceFiscalYearId): int
{
    $reference = fiscal_year_by_id($referenceFiscalYearId);
    if (!$reference || (int) ($reference['company_id'] ?? 0) === $companyId) {
        return $referenceFiscalYearId;
    }

    $stmt = db()->prepare('
        SELECT id
        FROM fiscal_years
        WHERE company_id = :company_id
          AND (
              label = :label
              OR (start_date = :start_date AND end_date = :end_date)
          )
        ORDER BY is_default DESC, is_active DESC, id DESC
        LIMIT 1
    ');
    $stmt->execute([
        'company_id' => $companyId,
        'label' => $reference['label'],
        'start_date' => $reference['start_date'],
        'end_date' => $reference['end_date'],
    ]);

    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Get consolidated financial summary for parent company
 * Implements IFRS 10 (Consolidated Financial Statements)
 * Handles control, joint ventures, and associates per IFRS standards
 */
function get_consolidated_financial_summary(int $parentCompanyId, int $fiscalYearId, array $subsidiaries): array
{
    $consolidatedIncome = 0;
    $consolidatedExpenses = 0;
    $nciProfit = 0;
    $equityMethodIncome = 0;
    $incomeBreakdown = [];
    $expenseBreakdown = [];
    $entities = [];
    
    // Get parent company data
    $parentData = get_financial_summary($parentCompanyId, $fiscalYearId);
    
    foreach ($parentData['income_breakdown'] as $item) {
        $consolidatedIncome += $item['amount'];
        if (!isset($incomeBreakdown[$item['code']])) {
            $incomeBreakdown[$item['code']] = [
                'name' => $item['name'],
                'amount' => 0,
                'nci' => 0,
            ];
        }
        $incomeBreakdown[$item['code']]['amount'] += $item['amount'];
    }
    
    foreach ($parentData['expense_breakdown'] as $item) {
        $consolidatedExpenses += $item['amount'];
        if (!isset($expenseBreakdown[$item['code']])) {
            $expenseBreakdown[$item['code']] = [
                'name' => $item['name'],
                'amount' => 0,
                'nci' => 0,
            ];
        }
        $expenseBreakdown[$item['code']]['amount'] += $item['amount'];
    }

    $entities[] = [
        'company_id' => $parentCompanyId,
        'company_name' => company_by_id($parentCompanyId)['name'] ?? 'Parent company',
        'ownership_percent' => 100.0,
        'relationship_type' => 'parent',
        'consolidation_method' => 'full',
        'income' => $parentData['total_income'],
        'expenses' => $parentData['total_expenses'],
        'profit' => $parentData['net_profit'],
        'nci_profit' => 0.0,
        'equity_method_share' => 0.0,
    ];
    
    // Process subsidiaries
    foreach ($subsidiaries as $subsidiary) {
        $subId = (int) $subsidiary['id'];
        $subFiscalYearId = matching_fiscal_year_id_for_company($subId, $fiscalYearId);
        $subData = $subFiscalYearId > 0 ? get_financial_summary($subId, $subFiscalYearId) : [
            'total_income' => 0,
            'total_expenses' => 0,
            'net_profit' => 0,
            'profit_margin' => 0,
            'income_breakdown' => [],
            'expense_breakdown' => [],
        ];
        
        $shareholding = get_company_shareholding($parentCompanyId, $subId);
        $ownershipPercent = (float) ($shareholding['ownership_percent'] ?? 100);
        $method = (string) ($shareholding['consolidation_method'] ?? ($ownershipPercent > 50 ? 'full' : 'equity'));
        $relationshipType = (string) ($shareholding['relationship_type'] ?? ($ownershipPercent > 50 ? 'subsidiary' : 'associate'));
        $nciPercent = 100 - $ownershipPercent;

        if ($method === 'full') {
            foreach ($subData['income_breakdown'] as $item) {
                $consolidatedIncome += $item['amount'];
                if (!isset($incomeBreakdown[$item['code']])) {
                    $incomeBreakdown[$item['code']] = [
                        'name' => $item['name'],
                        'amount' => 0,
                        'nci' => 0,
                    ];
                }
                $nciAmount = ($item['amount'] * $nciPercent) / 100;
                $incomeBreakdown[$item['code']]['amount'] += $item['amount'];
                $incomeBreakdown[$item['code']]['nci'] += $nciAmount;
            }

            foreach ($subData['expense_breakdown'] as $item) {
                $consolidatedExpenses += $item['amount'];
                if (!isset($expenseBreakdown[$item['code']])) {
                    $expenseBreakdown[$item['code']] = [
                        'name' => $item['name'],
                        'amount' => 0,
                        'nci' => 0,
                    ];
                }
                $nciAmount = ($item['amount'] * $nciPercent) / 100;
                $expenseBreakdown[$item['code']]['amount'] += $item['amount'];
                $expenseBreakdown[$item['code']]['nci'] += $nciAmount;
            }
            $nciProfit += ((float) $subData['net_profit'] * $nciPercent) / 100;
        } elseif ($method === 'equity') {
            $equityShare = ((float) $subData['net_profit'] * $ownershipPercent) / 100;
            $equityMethodIncome += $equityShare;
            $consolidatedIncome += $equityShare;
            $incomeBreakdown['EQUITY_METHOD_' . $subId] = [
                'name' => 'Share of profit/loss of ' . ($subsidiary['name'] ?? 'associate'),
                'amount' => $equityShare,
                'nci' => 0,
            ];
        } elseif ($method === 'proportionate') {
            $share = $ownershipPercent / 100;
            foreach ($subData['income_breakdown'] as $item) {
                $amount = $item['amount'] * $share;
                $consolidatedIncome += $amount;
                $incomeBreakdown[$item['code']]['name'] = $item['name'];
                $incomeBreakdown[$item['code']]['amount'] = ($incomeBreakdown[$item['code']]['amount'] ?? 0) + $amount;
                $incomeBreakdown[$item['code']]['nci'] = $incomeBreakdown[$item['code']]['nci'] ?? 0;
            }
            foreach ($subData['expense_breakdown'] as $item) {
                $amount = $item['amount'] * $share;
                $consolidatedExpenses += $amount;
                $expenseBreakdown[$item['code']]['name'] = $item['name'];
                $expenseBreakdown[$item['code']]['amount'] = ($expenseBreakdown[$item['code']]['amount'] ?? 0) + $amount;
                $expenseBreakdown[$item['code']]['nci'] = $expenseBreakdown[$item['code']]['nci'] ?? 0;
            }
        }

        $entities[] = [
            'company_id' => $subId,
            'company_name' => $subsidiary['name'] ?? 'Investee',
            'ownership_percent' => $ownershipPercent,
            'relationship_type' => $relationshipType,
            'consolidation_method' => $method,
            'income' => $subData['total_income'],
            'expenses' => $subData['total_expenses'],
            'profit' => $subData['net_profit'],
            'nci_profit' => $method === 'full' ? ((float) $subData['net_profit'] * $nciPercent) / 100 : 0.0,
            'equity_method_share' => $method === 'equity' ? ((float) $subData['net_profit'] * $ownershipPercent) / 100 : 0.0,
        ];
    }
    
    $consolidatedProfit = $consolidatedIncome - $consolidatedExpenses;
    $consolidatedMargin = $consolidatedIncome > 0 ? (($consolidatedProfit / $consolidatedIncome) * 100) : 0;
    
    return [
        'total_income' => $consolidatedIncome,
        'total_expenses' => $consolidatedExpenses,
        'net_profit' => $consolidatedProfit,
        'profit_margin' => $consolidatedMargin,
        'nci_profit' => $nciProfit,
        'profit_attributable_to_parent' => $consolidatedProfit - $nciProfit,
        'equity_method_income' => $equityMethodIncome,
        'income_breakdown' => array_values($incomeBreakdown),
        'expense_breakdown' => array_values($expenseBreakdown),
        'entities' => $entities,
    ];
}

/**
 * One company's headline financials for a fiscal year: cash & bank,
 * mapped receivables/payables balances, income, expenses, net profit.
 * Used by the group dashboard and the consolidated tabular report.
 */
function company_financials_snapshot(int $companyId, int $fiscalYearId): array
{
    $snapshot = ['cash' => 0.0, 'receivables' => 0.0, 'payables' => 0.0, 'income' => 0.0, 'expenses' => 0.0, 'net' => 0.0];
    if ($companyId <= 0 || $fiscalYearId <= 0) {
        return $snapshot;
    }

    $stmt = db()->prepare('
        SELECT l.id, l.type, COALESCE(g.is_cash_or_bank, 0) AS is_cash_or_bank, g.code AS group_code, g.name AS group_name,
               COALESCE(SUM(CASE WHEN ve.entry_type = \'debit\' THEN ve.amount ELSE 0 END), 0) AS debit_total,
               COALESCE(SUM(CASE WHEN ve.entry_type = \'credit\' THEN ve.amount ELSE 0 END), 0) AS credit_total
        FROM ledgers l
        LEFT JOIN ledger_groups g ON g.id = l.group_id
        INNER JOIN voucher_entries ve ON ve.ledger_id = l.id
        INNER JOIN vouchers v ON v.id = ve.voucher_id
            AND v.company_id = l.company_id
            AND v.fiscal_year_id = :fiscal_year_id
            AND v.status = \'posted\'
        WHERE l.company_id = :company_id
        GROUP BY l.id, l.type, g.is_cash_or_bank, g.code, g.name
    ');
    $stmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);

    // Receivables/payables are the Trade Receivables / Trade Payables GROUPS
    // (every party ledger inside them), with the mapped generic ledgers kept
    // for companies whose ledgers are not grouped yet.
    $receivableLedgerId = (int) (get_mapped_ledger($companyId, 'default_accounts_receivable')['id'] ?? 0);
    $payableLedgerId = (int) (get_mapped_ledger($companyId, 'default_accounts_payable')['id'] ?? 0);
    $receivableSum = 0.0;
    $payableSum = 0.0;

    foreach ($stmt->fetchAll() as $row) {
        $debit = (float) $row['debit_total'];
        $credit = (float) $row['credit_total'];
        $type = (string) $row['type'];
        $groupCode = (string) ($row['group_code'] ?? '');
        $groupName = (string) ($row['group_name'] ?? '');
        $natural = in_array($type, ['liability', 'equity', 'revenue'], true) ? $credit - $debit : $debit - $credit;

        if ((int) $row['is_cash_or_bank'] === 1) {
            $snapshot['cash'] += $debit - $credit;
        }
        if ($type === 'revenue') {
            $snapshot['income'] += $natural;
        } elseif ($type === 'expense') {
            $snapshot['expenses'] += $natural;
        }
        // Client-books companies carry the same groups under generated
        // numeric codes, so match by code OR name.
        if ($groupCode === 'RECEIVABLE' || $groupName === 'Trade Receivables' || ($groupCode === '' && (int) $row['id'] === $receivableLedgerId)) {
            $receivableSum += $natural;
        } elseif ($groupCode === 'PAYABLE' || $groupName === 'Trade Payables' || ($groupCode === '' && (int) $row['id'] === $payableLedgerId)) {
            $payableSum += $natural;
        }
    }

    $snapshot['receivables'] = max(0, $receivableSum);
    $snapshot['payables'] = max(0, $payableSum);
    $snapshot['net'] = $snapshot['income'] - $snapshot['expenses'];

    return $snapshot;
}

/**
 * Companies visible on the superadmin group dashboard and consolidated
 * report: the accounting firm itself plus Altiora and every company in
 * its group, each mapped to its fiscal year matching the reference FY.
 * Returns [['company' => row, 'fiscal_year_id' => int], ...].
 */
function group_dashboard_companies(int $referenceFiscalYearId): array
{
    $result = [];
    $seen = [];
    $candidates = [];

    $mbista = company_by_code('MBAACA');
    if ($mbista) {
        $candidates[] = $mbista;
    }
    $altiora = company_by_code('AGHPL');
    if ($altiora) {
        foreach (accounting_companies_for_group((int) $altiora['id']) as $groupCompany) {
            $candidates[] = $groupCompany;
        }
    }

    foreach ($candidates as $candidate) {
        $companyId = (int) ($candidate['id'] ?? 0);
        if ($companyId <= 0 || isset($seen[$companyId]) || (int) ($candidate['is_active'] ?? 1) !== 1) {
            continue;
        }
        $seen[$companyId] = true;
        $fiscalYearId = matching_fiscal_year_id_for_company($companyId, $referenceFiscalYearId);
        $result[] = ['company' => $candidate, 'fiscal_year_id' => $fiscalYearId];
    }

    return $result;
}

/**
 * Provision a dedicated accounting books space for a client: a flagged
 * companies row with seeded ledger groups, starter ledgers, AR/AP
 * mappings, and a fiscal year mirroring the serving company's.
 * Returns the books company id (existing or newly created).
 */
function provision_client_books(int $clientProfileId): int
{
    $profileStmt = db()->prepare('SELECT * FROM client_profiles WHERE id = :id LIMIT 1');
    $profileStmt->execute(['id' => $clientProfileId]);
    $profile = $profileStmt->fetch();
    if (!$profile) {
        return 0;
    }
    if ((int) ($profile['books_company_id'] ?? 0) > 0) {
        return (int) $profile['books_company_id'];
    }

    $code = 'CLB' . str_pad((string) $clientProfileId, 4, '0', STR_PAD_LEFT);
    db()->prepare('INSERT INTO companies (name, code, is_active, is_client_company) VALUES (:name, :code, 1, 1)')
        ->execute(['name' => (string) $profile['organization_name'] . ' (Client Books)', 'code' => $code]);
    $booksCompanyId = (int) db()->lastInsertId();

    // Fiscal year mirrors the serving company's default/active year.
    $servingCompanyId = (int) ($profile['company_id'] ?? 0);
    $fyRow = null;
    if ($servingCompanyId > 0) {
        $fyStmt = db()->prepare('SELECT label, start_date, end_date FROM fiscal_years WHERE company_id = :cid ORDER BY is_default DESC, is_active DESC, id DESC LIMIT 1');
        $fyStmt->execute(['cid' => $servingCompanyId]);
        $fyRow = $fyStmt->fetch();
    }
    db()->prepare('INSERT INTO fiscal_years (company_id, label, start_date, end_date, is_active, is_default) VALUES (:cid, :label, :sd, :ed, 1, 1)')
        ->execute([
            'cid' => $booksCompanyId,
            'label' => (string) ($fyRow['label'] ?? ('FY ' . date('Y'))),
            'sd' => (string) ($fyRow['start_date'] ?? date('Y-01-01')),
            'ed' => (string) ($fyRow['end_date'] ?? date('Y-12-31')),
        ]);

    // Seed the standard ledger group set (mirrors schema.sql seeds).
    $groupSeeds = [
        ['BANK', 'Bank', 'current_asset', 1], ['CASH_GRP', 'Cash in Hand', 'current_asset', 1],
        ['RECEIVABLE', 'Trade Receivables', 'current_asset', 0], ['PREPAID_GRP', 'Prepaid Expenses', 'current_asset', 0],
        ['INVEST_GRP', 'Investments', 'non_current_asset', 0], ['PAYABLE', 'Trade Payables', 'current_liability', 0],
        ['DUTIES_TAXES', 'Duties and Taxes', 'current_liability', 0], ['SHARE_CAPITAL_GRP', 'Share Capital', 'equity', 0],
        ['RESERVE_SURPLUS', 'Reserve & Surplus', 'equity', 0], ['DIRECT_INCOME_GRP', 'Sales / Service Income', 'direct_income', 0],
        ['INDIRECT_INCOME_GRP', 'Other Income', 'indirect_income', 0], ['ADMIN_EXP', 'Administrative Expenses', 'indirect_expense', 0],
        ['EMP_BENEFIT_EXP', 'Employee Benefit Expenses', 'indirect_expense', 0], ['SALES_MKT_EXP', 'Sales and Marketing Expenses', 'indirect_expense', 0],
        ['OTHER_NONOP_EXP', 'Other Non-operating Expenses', 'indirect_expense', 0], ['DIRECT_EXP_GRP', 'Direct Expenses', 'direct_expense', 0],
        ['TAX_EXP_GRP', 'Tax Expenses', 'indirect_expense', 0],
    ];
    $groupInsert = db()->prepare('INSERT INTO ledger_groups (company_id, code, name, master_key, is_cash_or_bank, is_system) VALUES (:cid, :code, :name, :mk, :cb, 1)');
    $groupIds = [];
    foreach ($groupSeeds as [$gCode, $gName, $gMaster, $gCashBank]) {
        $groupInsert->execute(['cid' => $booksCompanyId, 'code' => coa_next_group_code($booksCompanyId, $gMaster), 'name' => $gName, 'mk' => $gMaster, 'cb' => $gCashBank]);
        $groupIds[$gCode] = (int) db()->lastInsertId();
    }

    // Starter ledgers + default AR/AP mappings.
    $ledgerSeeds = [
        ['CASH', 'Cash and bank', 'asset', 'BANK'], ['AR', 'Accounts receivable', 'asset', 'RECEIVABLE'],
        ['AP', 'Accounts payable', 'liability', 'PAYABLE'], ['CAPITAL', 'Owner capital', 'equity', 'SHARE_CAPITAL_GRP'],
        ['SALES', 'Sales / service revenue', 'revenue', 'DIRECT_INCOME_GRP'], ['EXPENSES', 'General expenses', 'expense', 'ADMIN_EXP'],
    ];
    $ledgerInsert = db()->prepare('INSERT INTO ledgers (company_id, group_id, code, name, type, is_system, status) VALUES (:cid, :gid, :code, :name, :type, 1, \'active\')');
    $ledgerIds = [];
    foreach ($ledgerSeeds as [$lCode, $lName, $lType, $lGroup]) {
        $seedGroupId = $groupIds[$lGroup] ?? 0;
        $ledgerInsert->execute(['cid' => $booksCompanyId, 'gid' => $seedGroupId ?: null, 'code' => coa_next_ledger_code($booksCompanyId, (int) $seedGroupId), 'name' => $lName, 'type' => $lType]);
        $ledgerIds[$lCode] = (int) db()->lastInsertId();
    }
    if (table_exists('company_ledger_mappings')) {
        $mapInsert = db()->prepare('INSERT INTO company_ledger_mappings (company_id, map_key, ledger_id) VALUES (:cid, :k, :lid)');
        $mapInsert->execute(['cid' => $booksCompanyId, 'k' => 'default_accounts_receivable', 'lid' => $ledgerIds['AR']]);
        $mapInsert->execute(['cid' => $booksCompanyId, 'k' => 'default_accounts_payable', 'lid' => $ledgerIds['AP']]);
    }

    db()->prepare('UPDATE client_profiles SET books_company_id = :bid WHERE id = :id')
        ->execute(['bid' => $booksCompanyId, 'id' => $clientProfileId]);

    return $booksCompanyId;
}

/**
 * Access level of the current user for a client's accounting books:
 * 'direct' (post without client approval), 'approval' (edits need the
 * client's approval), or '' (no access).
 * Rules: Super Admins in the M.Bista portal reach every client
 * directly; admins reach clients served by their current portal
 * company directly; staff reach only clients assigned to them, with
 * mandatory client approval.
 */
function client_books_access_level(array $clientProfile): string
{
    $user = current_user();
    if (!$user) {
        return '';
    }
    $role = (string) ($user['role'] ?? '');
    $portal = current_company() ?: (!empty($user['company_id']) ? company_by_id((int) $user['company_id']) : null);
    $portalCode = (string) ($portal['code'] ?? '');
    $servesFromPortal = (int) ($clientProfile['company_id'] ?? 0) === (int) ($portal['id'] ?? 0);
    $servingCompanyId = (int) ($clientProfile['company_id'] ?? 0);

    if ($role === 'admin') {
        // Super admins (M.Bista) reach every client's books directly.
        if (user_is_super_admin($user) || $portalCode === 'MBAACA') {
            return 'direct';
        }
        // Otherwise direct access only to clients SERVED BY a company this admin
        // controls (its own company tree + explicit memberships). The old rule
        // granted access to *any* client while inside *any* client-books portal,
        // which let one provider's admin hop into another provider's clients.
        if ($servesFromPortal) {
            return 'direct';
        }
        return ($servingCompanyId > 0 && user_can_access_company($servingCompanyId)) ? 'direct' : '';
    }
    if ($role === 'staff') {
        $assigned = (int) ($clientProfile['assigned_staff_user_id'] ?? 0) === (int) ($user['id'] ?? 0);
        // Admin-granted per-client accounting access (migration 049) opens the
        // same approval-gated door as a direct client assignment.
        $granted = function_exists('staff_accounting_client_ids')
            && in_array((int) ($clientProfile['id'] ?? 0), staff_accounting_client_ids((int) ($user['id'] ?? 0)), true);
        $reachable = $assigned || $granted;
        return ($reachable && $servesFromPortal) ? 'approval' : ($reachable && $portalCode === 'MBAACA' ? 'approval' : '');
    }
    if ($role === 'customer') {
        // A client login works directly in its own books — owner or a portal
        // member added by the owner (voucher rights are enforced separately by
        // the portal member role in user_can()/user_can_do()).
        $ownProfile = client_profile_for_user((int) ($user['id'] ?? 0));
        return $ownProfile && (int) $ownProfile['id'] === (int) ($clientProfile['id'] ?? 0) ? 'direct' : '';
    }
    return '';
}

/** Client profiles visible in the current portal for the current user. */
function client_books_clients_for_scope(): array
{
    if (!table_exists('client_profiles')) {
        return [];
    }
    $user = current_user();
    $role = (string) ($user['role'] ?? '');
    $portal = current_company() ?: (!empty($user['company_id']) ? company_by_id((int) $user['company_id']) : null);
    $portalCode = (string) ($portal['code'] ?? '');
    $portalId = (int) ($portal['id'] ?? 0);

    $sql = 'SELECT cp.*, u.name AS user_name, u.email AS user_email, s.name AS staff_name, c.name AS serving_company
            FROM client_profiles cp
            LEFT JOIN users u ON u.id = cp.user_id
            LEFT JOIN users s ON s.id = cp.assigned_staff_user_id
            LEFT JOIN companies c ON c.id = cp.company_id
            WHERE cp.is_active = 1';
    $params = [];
    if ($role === 'admin') {
        if ($portalCode !== 'MBAACA') {
            $sql .= ' AND cp.company_id = :portal_id';
            $params['portal_id'] = $portalId;
        }
    } elseif ($role === 'staff') {
        $grantedClientIds = function_exists('staff_accounting_client_ids') ? staff_accounting_client_ids((int) ($user['id'] ?? 0)) : [];
        if ($grantedClientIds !== []) {
            $sql .= ' AND (cp.assigned_staff_user_id = :staff_id OR cp.id IN (' . implode(',', array_map('intval', $grantedClientIds)) . '))';
        } else {
            $sql .= ' AND cp.assigned_staff_user_id = :staff_id';
        }
        $params['staff_id'] = (int) ($user['id'] ?? 0);
        if ($portalCode !== 'MBAACA') {
            $sql .= ' AND cp.company_id = :portal_id';
            $params['portal_id'] = $portalId;
        }
    } else {
        return [];
    }
    $sql .= ' ORDER BY cp.organization_name ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Prepare chart data for financial dashboard
 */
function prepare_chart_data(int $companyId, int $fiscalYearId, array $subsidiaries, bool $isAltiora): array
{
    $finData = get_financial_summary($companyId, $fiscalYearId);
    
    return [
        'income' => (float) ($finData['total_income'] ?? 0),
        'expenses' => (float) ($finData['total_expenses'] ?? 0),
        'profit' => (float) ($finData['net_profit'] ?? 0),
    ];
}

/**
 * Get shareholding information between parent and subsidiary
 */
function get_company_shareholding(int $parentId, int $subsidiaryId): array
{
    if (table_exists('company_shareholdings')) {
        $stmt = db()->prepare('
            SELECT *
            FROM company_shareholdings
            WHERE investor_company_id = :parent_id
              AND investee_company_id = :subsidiary_id
            LIMIT 1
        ');
        $stmt->execute([
            'parent_id' => $parentId,
            'subsidiary_id' => $subsidiaryId,
        ]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    }

    $query = 'SELECT id FROM companies WHERE id = :subsidiary_id AND parent_company_id = :parent_id';
    $stmt = db()->prepare($query);
    $stmt->execute([
        ':subsidiary_id' => $subsidiaryId,
        ':parent_id' => $parentId,
    ]);
    
    if ($stmt->rowCount() > 0) {
        return [
            'ownership_percent' => 100,
            'relationship_type' => 'subsidiary',
            'consolidation_method' => 'full',
        ];
    }

    return [
        'ownership_percent' => 0,
        'relationship_type' => 'investment',
        'consolidation_method' => 'cost',
    ];
}

function company_shareholdings_for_parent(int $parentCompanyId): array
{
    if (!table_exists('company_shareholdings')) {
        return [];
    }

    $stmt = db()->prepare('
        SELECT sh.*, c.name AS investee_name, c.code AS investee_code
        FROM company_shareholdings sh
        INNER JOIN companies c ON c.id = sh.investee_company_id
        WHERE sh.investor_company_id = :company_id
        ORDER BY c.name ASC
    ');
    $stmt->execute(['company_id' => $parentCompanyId]);

    return $stmt->fetchAll() ?: [];
}

function accounting_companies_for_group(int $companyId): array
{
    $company = company_by_id($companyId);
    if (!$company) {
        return [];
    }

    $companies = [$company];
    foreach (child_companies_for_company($companyId, false) as $child) {
        $companies[] = $child;
    }

    if (table_exists('company_shareholdings')) {
        $stmt = db()->prepare('
            SELECT c.*
            FROM company_shareholdings sh
            INNER JOIN companies c ON c.id = sh.investee_company_id
            WHERE sh.investor_company_id = :company_id
            ORDER BY c.name ASC
        ');
        $stmt->execute(['company_id' => $companyId]);
        foreach ($stmt->fetchAll() as $investee) {
            $exists = false;
            foreach ($companies as $existing) {
                if ((int) $existing['id'] === (int) $investee['id']) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $companies[] = $investee;
            }
        }
    }

    return $companies;
}

/**
 * Get c$ledgerRows .= <<<HTML
        <tr>
            <td>{$ledger['code']}</td>
            <td>{$ledger['name']}</td>
            <td>{$ledger['type']}</td>
            <td style="text-align: right;">" . format_currency($debit) . "</td>
            <td style="text-align: right;">" . format_currency($credit) . "</td>
        </tr>
        HTML;
    }
    
    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ledger Report - {$company['name']}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #333; padding: 20px; }
        .report { max-width: 1200px; margin: 0 auto; }
        .header { text-align: center; margin-bottom: 40px; border-bottom: 2px solid #2c3e50; padding-bottom: 20px; }
        .header h1 { color: #2c3e50; font-size: 28px; margin-bottom: 10px; }
        .header p { color: #7f8c8d; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        table thead { background: #ecf0f1; }
        table th { padding: 12px; text-align: left; font-weight: 600; color: #2c3e50; font-size: 12px; text-transform: uppercase; }
        table td { padding: 10px 12px; border-bottom: 1px solid #ecf0f1; }
        table tbody tr:hover { background: #f9f9f9; }
        .total-row { background: #2c3e50; color: white; font-weight: bold; }
        .total-row td { padding: 12px; }
        .footer { text-align: center; color: #7f8c8d; font-size: 12px; margin-top: 40px; }
        @media print { body { padding: 0; } }
    </style>
</head>
<body>
    <div class="report">
        <div class="header">
            <h1>General Ledger Report</h1>
            <p>{$company['name']} | Fiscal Year: {$fiscalYear['label']}</p>
            <p>Generated: " . date('Y-m-d H:i:s') . "</p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Account Name</th>
                    <th>Type</th>
                    <th style="text-align: right;">Debit</th>
                    <th style="text-align: right;">Credit</th>
                </tr>
            </thead>
            <tbody>
                $ledgerRows
                <tr class="total-row">
                    <td colspan="3">Total</td>
                    <td style="text-align: right;">" . format_currency($totalDebit) . "</td>
                    <td style="text-align: right;">" . format_currency($totalCredit) . "</td>
                </tr>
            </tbody>
        </table>

        <div class="footer">
            <p>This report is for internal accounting purposes only.</p>
        </div>
    </div>
</body>
</html>
HTML;

    return $html;
}

/**
 * Export accounting ledger data to Excel/CSV format
 */
function export_ledger_to_excel(int $companyId, int $fiscalYearId): array
{
    $company = company_by_id($companyId);
    $fiscalYear = fiscal_year_by_id($fiscalYearId);
    
    if (!$company || !$fiscalYear) {
        return [];
    }
    
    // Get ledger balances
    $balanceStmt = db()->prepare("
        SELECT l.id, l.code, l.name, l.type, 
               COALESCE(SUM(CASE WHEN ve.entry_type='debit' THEN ve.amount ELSE 0 END),0) AS debit_total,
               COALESCE(SUM(CASE WHEN ve.entry_type='credit' THEN ve.amount ELSE 0 END),0) AS credit_total
        FROM ledgers l
        LEFT JOIN voucher_entries ve ON ve.ledger_id = l.id
        LEFT JOIN vouchers v ON v.id = ve.voucher_id AND v.company_id = l.company_id
            AND v.fiscal_year_id = :fiscal_year_id AND v.status = 'posted'
        WHERE l.company_id = :company_id
        GROUP BY l.id, l.code, l.name, l.type
        ORDER BY l.code ASC
    ");
    $balanceStmt->execute(['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId]);
    $ledgers = $balanceStmt->fetchAll();
    
    $rows = [
        ['General Ledger Report'],
        ['Company', $company['name']],
        ['Fiscal Year', $fiscalYear['label']],
        ['Generated', date('Y-m-d H:i:s')],
        [],
        ['Code', 'Account Name', 'Type', 'Debit Total', 'Credit Total'],
    ];
    
    $totalDebit = 0;
    $totalCredit = 0;
    
    foreach ($ledgers as $ledger) {
        $debit = (float) ($ledger['debit_total'] ?? 0);
        $credit = (float) ($ledger['credit_total'] ?? 0);
        $totalDebit += $debit;
        $totalCredit += $credit;
        
        $rows[] = [
            $ledger['code'],
            $ledger['name'],
            $ledger['type'],
            number_format($debit, 2),
            number_format($credit, 2),
        ];
    }
    
    $rows[] = ['', '', 'TOTAL', number_format($totalDebit, 2), number_format($totalCredit, 2)];
    
    return [
        'filename' => 'Ledger-' . $company['code'] . '-' . date('YmdHis') . '.csv',
        'data' => $rows
    ];
}

/**
 * Export accounting ledger data as printable HTML.
 */
function export_ledger_html(int $companyId, int $fiscalYearId): string
{
    $company = company_by_id($companyId);
    $fiscalYear = fiscal_year_by_id($fiscalYearId);

    if (!$company || !$fiscalYear) {
        return '<!doctype html><html><body><p>Missing company or fiscal year context.</p></body></html>';
    }

    $stmt = db()->prepare("
        SELECT l.id, l.code, l.name, l.type,
               COALESCE(SUM(CASE WHEN ve.entry_type = 'debit' THEN ve.amount ELSE 0 END), 0) AS debit_total,
               COALESCE(SUM(CASE WHEN ve.entry_type = 'credit' THEN ve.amount ELSE 0 END), 0) AS credit_total
        FROM ledgers l
        LEFT JOIN voucher_entries ve ON ve.ledger_id = l.id
        LEFT JOIN vouchers v
            ON v.id = ve.voucher_id
           AND v.company_id = l.company_id
           AND v.fiscal_year_id = :fiscal_year_id
           AND v.status = 'posted'
        WHERE l.company_id = :company_id
        GROUP BY l.id, l.code, l.name, l.type
        ORDER BY l.code ASC
    ");
    $stmt->execute([
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId,
    ]);
    $ledgers = $stmt->fetchAll();

    $totalDebit = 0.0;
    $totalCredit = 0.0;
    $rows = '';

    foreach ($ledgers as $ledger) {
        $debit = (float) ($ledger['debit_total'] ?? 0);
        $credit = (float) ($ledger['credit_total'] ?? 0);
        $totalDebit += $debit;
        $totalCredit += $credit;

        $rows .= '<tr>'
            . '<td>' . e((string) $ledger['code']) . '</td>'
            . '<td>' . e((string) $ledger['name']) . '</td>'
            . '<td>' . e(ucfirst((string) $ledger['type'])) . '</td>'
            . '<td class="amount">' . e(format_currency($debit)) . '</td>'
            . '<td class="amount">' . e(format_currency($credit)) . '</td>'
            . '</tr>';
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="5" class="empty">No ledger rows found.</td></tr>';
    }

    $companyName = e((string) $company['name']);
    $companyCode = e((string) ($company['code'] ?? ''));
    $fiscalYearLabel = e((string) $fiscalYear['label']);
    $generatedAt = e(date('Y-m-d H:i:s'));
    $totalDebitText = e(format_currency($totalDebit));
    $totalCreditText = e(format_currency($totalCredit));

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>General Ledger - {$companyCode}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #1f2933; margin: 0; padding: 32px; background: #f5f7fa; }
        .report { max-width: 1080px; margin: 0 auto; background: #fff; padding: 32px; border: 1px solid #d9e2ec; }
        h1 { margin: 0 0 8px; font-size: 26px; }
        .meta { margin: 0 0 24px; color: #52606d; line-height: 1.6; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d9e2ec; padding: 10px 12px; font-size: 13px; }
        th { background: #eef2f7; text-align: left; }
        .amount { text-align: right; white-space: nowrap; }
        .total-row td { font-weight: 700; background: #f8fafc; }
        .empty { text-align: center; color: #6b7280; }
        .actions { margin-bottom: 18px; text-align: right; }
        button { border: 0; background: #0f3d5e; color: #fff; padding: 9px 14px; border-radius: 4px; cursor: pointer; }
        @media print {
            body { background: #fff; padding: 0; }
            .report { border: 0; max-width: none; padding: 0; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="report">
        <div class="actions"><button type="button" onclick="window.print()">Print / Save PDF</button></div>
        <h1>General Ledger Report</h1>
        <p class="meta">
            Company: {$companyName} ({$companyCode})<br>
            Fiscal year: {$fiscalYearLabel}<br>
            Generated: {$generatedAt}
        </p>
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Account</th>
                    <th>Type</th>
                    <th class="amount">Debit</th>
                    <th class="amount">Credit</th>
                </tr>
            </thead>
            <tbody>
                {$rows}
                <tr class="total-row">
                    <td colspan="3">Total</td>
                    <td class="amount">{$totalDebitText}</td>
                    <td class="amount">{$totalCreditText}</td>
                </tr>
            </tbody>
        </table>
    </div>
</body>
</html>
HTML;
}

/**
 * Helper function to get status badge class
 */
function get_status_badge_class(string $status): string
{
    return match($status) {
        'draft' => 'warning',
        'issued' => 'primary',
        'paid' => 'success',
        'cancelled' => 'danger',
        default => 'secondary'
    };
}

function compliance_effective_status(array $deadline): string
{
    $status = (string) ($deadline['status'] ?? 'not_started');
    $terminal = ['filed', 'completed', 'not_applicable', 'overdue'];

    if (in_array($status, $terminal, true)) {
        return $status;
    }

    $dueDate = (string) ($deadline['statutory_due_date'] ?? '');
    if ($dueDate === '') {
        return $status;
    }

    $today = new DateTimeImmutable('today');
    $due = DateTimeImmutable::createFromFormat('Y-m-d', $dueDate) ?: $today;

    if ($due < $today) {
        return 'overdue';
    }

    if ($due <= $today->modify('+14 days')) {
        return 'upcoming';
    }

    return $status;
}

function attendance_is_late(?string $checkInTime): bool
{
    if ($checkInTime === null || $checkInTime === '') {
        return false;
    }

    $workStart = (string) setting('work_start_time', '10:00');
    $checkIn = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $checkInTime) ?: DateTimeImmutable::createFromFormat('H:i:s', substr($checkInTime, -8));
    if (!$checkIn) {
        return false;
    }

    $threshold = DateTimeImmutable::createFromFormat('H:i', $workStart);
    if (!$threshold) {
        return false;
    }

    return $checkIn->format('H:i') > $threshold->format('H:i');
}

function attendance_is_early_departure(?string $checkOutTime): bool
{
    if ($checkOutTime === null || $checkOutTime === '') {
        return false;
    }

    $workEnd = (string) setting('work_end_time', '18:00');
    $checkOut = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $checkOutTime) ?: DateTimeImmutable::createFromFormat('H:i:s', substr($checkOutTime, -8));
    if (!$checkOut) {
        return false;
    }

    $threshold = DateTimeImmutable::createFromFormat('H:i', $workEnd);
    if (!$threshold) {
        return false;
    }

    return $checkOut->format('H:i') < $threshold->format('H:i');
}

function attendance_hours_worked(?string $checkInTime, ?string $checkOutTime): float
{
    if ($checkInTime === null || $checkOutTime === '' || $checkOutTime === null) {
        return 0.0;
    }

    $checkIn = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $checkInTime);
    $checkOut = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $checkOutTime);
    if (!$checkIn || !$checkOut || $checkOut < $checkIn) {
        return 0.0;
    }

    return round(($checkOut->getTimestamp() - $checkIn->getTimestamp()) / 3600, 2);
}

function leave_balance_remaining(int $staffUserId, int $leaveTypeId, int $defaultDaysPerYear): int
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(total_days), 0) FROM leave_requests
        WHERE staff_user_id = :staff_user_id AND leave_type_id = :leave_type_id AND status = 'approved'
          AND YEAR(start_date) = YEAR(CURDATE())");
    $stmt->execute(['staff_user_id' => $staffUserId, 'leave_type_id' => $leaveTypeId]);
    $usedDays = (int) $stmt->fetchColumn();

    return max(0, $defaultDaysPerYear - $usedDays);
}

function handle_document_upload(array $file, int $companyId, int $clientId): array
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Choose a file to upload.'];
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'File upload failed.'];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    $maxSize = 10 * 1024 * 1024;

    if ($size <= 0 || $size > $maxSize || !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'error' => 'File must be a valid upload under 10 MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpPath);
    $extensions = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
        'application/zip' => 'zip',
    ];

    if (!isset($extensions[$mime])) {
        return ['ok' => false, 'error' => 'Unsupported file type. Allowed: PDF, JPG, PNG, WEBP, DOC, DOCX, XLS, XLSX, TXT, ZIP.'];
    }

    $uploadDir = __DIR__ . '/../public_html/uploads/documents/' . $companyId . '/' . $clientId;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'Could not create upload directory.'];
    }

    $storedFileName = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    $targetPath = $uploadDir . '/' . $storedFileName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return ['ok' => false, 'error' => 'Could not save uploaded file.'];
    }

    $originalName = (string) ($file['name'] ?? $storedFileName);

    return [
        'ok' => true,
        'original_file_name' => $originalName,
        'stored_file_name' => $storedFileName,
        'file_path' => 'uploads/documents/' . $companyId . '/' . $clientId . '/' . $storedFileName,
        'file_type' => $mime,
        'file_size' => $size,
    ];
}

function handle_message_attachment_upload(array $file, string $subDir): array
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'file_path' => null, 'original_file_name' => null];
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'File upload failed.'];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    $maxSize = 10 * 1024 * 1024;

    if ($size <= 0 || $size > $maxSize || !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'error' => 'File must be a valid upload under 10 MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpPath);
    $extensions = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
        'application/zip' => 'zip',
    ];

    if (!isset($extensions[$mime])) {
        return ['ok' => false, 'error' => 'Unsupported file type. Allowed: PDF, JPG, PNG, WEBP, DOC, DOCX, XLS, XLSX, TXT, ZIP.'];
    }

    $safeSubDir = preg_replace('/[^a-zA-Z0-9_\/-]/', '_', $subDir) ?: 'misc';
    $uploadDir = __DIR__ . '/../public_html/uploads/messages/' . $safeSubDir;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'Could not create upload directory.'];
    }

    $storedFileName = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    $targetPath = $uploadDir . '/' . $storedFileName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return ['ok' => false, 'error' => 'Could not save uploaded file.'];
    }

    return [
        'ok' => true,
        'file_path' => 'uploads/messages/' . $safeSubDir . '/' . $storedFileName,
        'original_file_name' => (string) ($file['name'] ?? $storedFileName),
    ];
}

function create_message_thread(int $companyId, ?int $clientId, string $subject, int $createdBy, string $firstMessageBody, array $uploadResult = [], array $explicitParticipantIds = []): int
{
    db()->beginTransaction();
    try {
        $stmt = db()->prepare('INSERT INTO message_threads (company_id, client_id, subject, created_by) VALUES (:company_id, :client_id, :subject, :created_by)');
        $stmt->execute([
            'company_id' => $companyId,
            'client_id' => $clientId,
            'subject' => $subject,
            'created_by' => $createdBy,
        ]);
        $threadId = (int) db()->lastInsertId();

        $participantIds = [$createdBy];

        if ($clientId !== null) {
            $clientStmt = db()->prepare('SELECT user_id, assigned_staff_user_id FROM client_profiles WHERE id = :id LIMIT 1');
            $clientStmt->execute(['id' => $clientId]);
            $clientProfile = $clientStmt->fetch();
            if ($clientProfile) {
                $participantIds[] = (int) $clientProfile['user_id'];
                if (!empty($clientProfile['assigned_staff_user_id'])) {
                    $participantIds[] = (int) $clientProfile['assigned_staff_user_id'];
                }
            }
            $adminStmt = db()->prepare("SELECT id FROM users WHERE role = 'admin' AND company_id = :company_id");
            $adminStmt->execute(['company_id' => $companyId]);
            foreach ($adminStmt->fetchAll() as $admin) {
                $participantIds[] = (int) $admin['id'];
            }
        } else {
            $participantIds = array_merge($participantIds, $explicitParticipantIds);
        }

        $participantIds = array_unique(array_filter($participantIds, static fn ($id): bool => (int) $id > 0));

        $participantStmt = db()->prepare('INSERT IGNORE INTO message_thread_participants (thread_id, user_id) VALUES (:thread_id, :user_id)');
        foreach ($participantIds as $participantId) {
            $participantStmt->execute(['thread_id' => $threadId, 'user_id' => $participantId]);
        }

        $messageStmt = db()->prepare('INSERT INTO messages (thread_id, sender_id, body, attachment_path, attachment_name) VALUES (:thread_id, :sender_id, :body, :attachment_path, :attachment_name)');
        $messageStmt->execute([
            'thread_id' => $threadId,
            'sender_id' => $createdBy,
            'body' => $firstMessageBody,
            'attachment_path' => $uploadResult['file_path'] ?? null,
            'attachment_name' => $uploadResult['original_file_name'] ?? null,
        ]);

        $readStmt = db()->prepare('UPDATE message_thread_participants SET last_read_at = NOW() WHERE thread_id = :thread_id AND user_id = :user_id');
        $readStmt->execute(['thread_id' => $threadId, 'user_id' => $createdBy]);

        db()->commit();

        return $threadId;
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $exception;
    }
}

function unread_message_thread_count(int $userId): int
{
    if (!table_exists('message_thread_participants') || !table_exists('messages')) {
        return 0;
    }

    $stmt = db()->prepare('SELECT COUNT(DISTINCT mtp.thread_id)
        FROM message_thread_participants mtp
        INNER JOIN messages m ON m.thread_id = mtp.thread_id
        WHERE mtp.user_id = :user_id
          AND m.sender_id <> :user_id2
          AND (mtp.last_read_at IS NULL OR m.created_at > mtp.last_read_at)');
    $stmt->execute(['user_id' => $userId, 'user_id2' => $userId]);

    return (int) $stmt->fetchColumn();
}

function next_ticket_number(int $companyId): string
{
    return 'TKT-' . date('Ymd') . '-' . str_pad((string) $companyId, 3, '0', STR_PAD_LEFT) . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function ticket_request_type_labels(): array
{
    return [
        'none' => 'General support',
        'invoice_request' => 'Invoice request',
        'discount_request' => 'Discount request',
        'credit_period_request' => 'Credit period request',
        'advance_payment_request' => 'Advance payment request',
        'partial_payment_request' => 'Partial payment request',
    ];
}

function next_receipt_number(int $companyId): string
{
    return 'RCP-' . date('Ymd') . '-' . str_pad((string) $companyId, 3, '0', STR_PAD_LEFT) . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function get_payment_receipt_by_request(int $paymentRequestId, int $companyId): ?array
{
    if (!table_exists('invoice_payment_receipts')) {
        return null;
    }

    $stmt = db()->prepare('SELECT r.*, i.invoice_no, i.issued_on, c.name AS company_name, cp.organization_name AS client_name
        FROM invoice_payment_receipts r
        INNER JOIN task_invoices i ON i.id = r.invoice_id
        LEFT JOIN companies c ON c.id = r.company_id
        LEFT JOIN client_profiles cp ON cp.id = r.client_id
        WHERE r.payment_request_id = :payment_request_id
          AND r.company_id = :company_id
        LIMIT 1');
    $stmt->execute([
        'payment_request_id' => $paymentRequestId,
        'company_id' => $companyId,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function export_payment_receipt_html(array $receipt): string
{
    $companyName = $receipt['company_name'] ?? app_name();
    $receiptNo = $receipt['receipt_no'] ?? 'N/A';
    $invoiceNo = $receipt['invoice_no'] ?? 'N/A';
    $clientName = $receipt['client_name'] ?? 'Client';
    $amount = format_currency((float) ($receipt['amount_received'] ?? 0));
    $paymentMethod = $receipt['payment_method'] ?? 'N/A';
    $paymentReference = $receipt['payment_reference'] ?? 'N/A';
    $receivedOn = $receipt['received_on'] ?? date('Y-m-d');
    $notes = trim((string) ($receipt['notes'] ?? ''));

    $notesHtml = $notes !== '' ? '<p><strong>Notes:</strong> ' . nl2br(e($notes)) . '</p>' : '';

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt ' . e($receiptNo) . '</title>
    <style>
        body { font-family: "Segoe UI", Arial, sans-serif; color: #111827; margin: 0; background: #f3f4f6; }
        .sheet { max-width: 760px; margin: 24px auto; background: #fff; border: 1px solid #e5e7eb; padding: 24px; }
        .head { display: flex; justify-content: space-between; border-bottom: 2px solid #1f2937; padding-bottom: 10px; margin-bottom: 14px; }
        h1 { margin: 0; font-size: 22px; }
        .meta p { margin: 5px 0; font-size: 14px; }
        .amount { font-size: 28px; font-weight: 700; color: #0f766e; margin: 10px 0; }
        .legal { margin-top: 16px; font-size: 12px; color: #4b5563; background: #f9fafb; border: 1px solid #e5e7eb; padding: 10px; }
        @media print { body { background: #fff; } .sheet { margin: 0; border: 0; } }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="head">
            <div>
                <h1>Payment Receipt</h1>
                <p><strong>' . e($companyName) . '</strong></p>
            </div>
            <div style="text-align:right;">
                <p><strong>Receipt No:</strong> ' . e($receiptNo) . '</p>
                <p><strong>Date:</strong> ' . e($receivedOn) . '</p>
            </div>
        </div>

        <div class="meta">
            <p><strong>Received From:</strong> ' . e($clientName) . '</p>
            <p><strong>Invoice:</strong> ' . e($invoiceNo) . '</p>
            <p><strong>Payment Method:</strong> ' . e($paymentMethod) . '</p>
            <p><strong>Reference:</strong> ' . e($paymentReference) . '</p>
        </div>

        <div class="amount">' . e($amount) . '</div>

        ' . $notesHtml . '

        <div class="legal">
            This receipt confirms payment acknowledgment for accounting and audit records.
            Maintain this receipt for taxation and compliance documentation.
        </div>
    </div>
</body>
</html>';
}

function staff_profile(int $userId): ?array
{
    if (!table_exists('staff_profiles')) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM staff_profiles WHERE user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => $userId]);

    return $stmt->fetch() ?: null;
}

/**
 * Public-safe team listing. Selects ONLY fields approved for public display —
 * never email, phone, documents, or any authentication/identity data.
 */
function public_team_members(?string $category = null, ?int $limit = null): array
{
    if (!table_exists('staff_profiles')) {
        return [];
    }

    $sql = "SELECT u.name, sp.job_title, sp.department, sp.education, sp.qualifications,
                sp.expertise, sp.bio, sp.photo_path, sp.team_category, c.name AS company_name
        FROM staff_profiles sp
        INNER JOIN users u ON u.id = sp.user_id
        LEFT JOIN companies c ON c.id = u.company_id
        WHERE sp.show_on_public_team = 1
          AND sp.employment_status = 'active'
          AND u.status = 'active'";
    $params = [];

    if ($category !== null && in_array($category, ['leadership', 'management', 'professional'], true)) {
        $sql .= ' AND sp.team_category = :category';
        $params['category'] = $category;
    }

    $sql .= " ORDER BY FIELD(sp.team_category, 'leadership', 'management', 'professional'), sp.display_order ASC, u.name ASC";
    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

function team_member_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part !== '' && strlen($initials) < 2) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }

    return $initials !== '' ? $initials : '?';
}

/** Categories of the public Insights section (key = subpage slug). */
function insight_categories(): array
{
    return [
        'articles' => 'Articles',
        'tax-updates' => 'Tax Updates',
        'audit-insights' => 'Audit Insights',
        'accounting-updates' => 'Accounting Updates',
        'business-advisory' => 'Business Advisory',
        'publications' => 'Publications',
        'news-events' => 'News and Events',
        'downloads' => 'Downloads',
    ];
}

/** Published insight posts, newest first; all categories when null. */
function insight_posts_published(?string $category = null, ?int $limit = null): array
{
    if (!table_exists('insight_posts')) {
        return [];
    }
    $sql = "SELECT p.*, u.name AS author_name FROM insight_posts p
        LEFT JOIN users u ON u.id = p.created_by
        WHERE p.status = 'published'";
    $params = [];
    if ($category !== null && isset(insight_categories()[$category])) {
        $sql .= ' AND p.category = :category';
        $params['category'] = $category;
    }
    $sql .= ' ORDER BY p.published_at DESC, p.id DESC';
    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

function handle_staff_photo_upload(array $file): array
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'file_path' => null];
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Photo upload failed.'];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 3 * 1024 * 1024 || !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'error' => 'Photo must be a valid upload under 3 MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpPath);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        return ['ok' => false, 'error' => 'Photo must be a JPG, PNG, or WEBP image.'];
    }

    $uploadDir = __DIR__ . '/../public_html/uploads/staff-photos';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'Could not create photo directory.'];
    }

    $storedFileName = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($tmpPath, $uploadDir . '/' . $storedFileName)) {
        return ['ok' => false, 'error' => 'Could not save photo.'];
    }

    return ['ok' => true, 'file_path' => 'uploads/staff-photos/' . $storedFileName];
}

function kyc_storage_dir(): string
{
    return __DIR__ . '/../secure_uploads/kyc';
}

/**
 * Confidential KYC upload. Files are stored OUTSIDE the web root with random
 * names; MIME type is verified server-side, never trusted from the browser.
 */
function handle_kyc_upload(array $file): array
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Choose a document file to upload.'];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024 || !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'error' => 'Document must be a valid upload under 5 MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpPath);
    $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($extensions[$mime])) {
        return ['ok' => false, 'error' => 'KYC documents must be PDF, JPG, or PNG files.'];
    }

    $storageDir = kyc_storage_dir();
    if (!is_dir($storageDir) && !mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
        return ['ok' => false, 'error' => 'Could not access the secure document directory.'];
    }

    $storedFileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($tmpPath, $storageDir . '/' . $storedFileName)) {
        return ['ok' => false, 'error' => 'Could not store the document securely.'];
    }

    return [
        'ok' => true,
        'stored_filename' => $storedFileName,
        'original_filename' => (string) ($file['name'] ?? $storedFileName),
        'mime_type' => $mime,
        'file_size' => $size,
    ];
}

/**
 * Computed KYC state: 'verified' needs one verified identity document plus a
 * verified PAN; 'submitted' means required documents are uploaded but not yet
 * verified; anything less is 'pending'.
 */
function staff_kyc_status(int $userId): string
{
    if (!table_exists('staff_kyc_documents')) {
        return 'pending';
    }

    $stmt = db()->prepare("SELECT document_type, verification_status FROM staff_kyc_documents WHERE staff_user_id = :user_id AND verification_status <> 'rejected'");
    $stmt->execute(['user_id' => $userId]);
    $docs = $stmt->fetchAll();

    $identityUploaded = false;
    $identityVerified = false;
    $panUploaded = false;
    $panVerified = false;

    foreach ($docs as $doc) {
        $isIdentity = in_array($doc['document_type'], ['citizenship', 'national_id', 'passport'], true);
        $isVerified = $doc['verification_status'] === 'verified';
        if ($isIdentity) {
            $identityUploaded = true;
            $identityVerified = $identityVerified || $isVerified;
        } elseif ($doc['document_type'] === 'pan') {
            $panUploaded = true;
            $panVerified = $panVerified || $isVerified;
        }
    }

    if ($identityVerified && $panVerified) {
        return 'verified';
    }
    if ($identityUploaded && $panUploaded) {
        return 'submitted';
    }

    return 'pending';
}

/**
 * Fetches and builds a hierarchical tree of ledger groups and ledgers.
 *
 * @param int $companyId The ID of the company.
 * @return array The hierarchical chart of accounts.
 */
function get_chart_of_accounts_tree(int $companyId): array
{
    if (!table_exists('ledger_groups') || !table_exists('ledgers')) {
        return [];
    }

    // Fetch all groups for the company
    $groupStmt = db()->prepare('SELECT * FROM ledger_groups WHERE company_id = :company_id ORDER BY name ASC');
    $groupStmt->execute(['company_id' => $companyId]);
    $groups = $groupStmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE);

    // Fetch all ledgers for the company
    $ledgerStmt = db()->prepare('SELECT * FROM ledgers WHERE company_id = :company_id ORDER BY name ASC');
    $ledgerStmt->execute(['company_id' => $companyId]);
    $ledgers = $ledgerStmt->fetchAll();

    // Attach ledgers to their parent groups
    foreach ($ledgers as $ledger) {
        $groupId = (int) ($ledger['group_id'] ?? 0);
        if ($groupId > 0 && isset($groups[$groupId])) {
            if (!isset($groups[$groupId]['ledgers'])) {
                $groups[$groupId]['ledgers'] = [];
            }
            $groups[$groupId]['ledgers'][] = $ledger;
        }
    }

    // Build the hierarchy
    $tree = [];
    foreach ($groups as $id => &$group) {
        $parentId = (int) ($group['parent_group_id'] ?? 0);
        if ($parentId > 0 && isset($groups[$parentId])) {
            if (!isset($groups[$parentId]['children'])) {
                $groups[$parentId]['children'] = [];
            }
            // Add by reference to allow deep nesting
            $groups[$parentId]['children'][$id] = &$group;
        }
    }

    // Collect only the root-level groups (those without a parent in the set)
    foreach ($groups as $id => $group) {
        $parentId = (int) ($group['parent_group_id'] ?? 0);
        if ($parentId === 0 || !isset($groups[$parentId])) {
            $tree[$id] = $group;
        }
    }

    // Sort root groups by master key sort order
    uasort($tree, function ($a, $b) {
        return (LEDGER_MASTERS[$a['master_key']]['sort_order'] ?? 99) <=> (LEDGER_MASTERS[$b['master_key']]['sort_order'] ?? 99);
    });

    return $tree;
}

/**
 * Items that need the signed-in user's attention, for the topbar bell.
 * Returns rows of ['label' => string, 'count' => int, 'url' => string]
 * with count > 0 only. Every query is guarded so a missing table or a
 * missing company context simply omits that row.
 */
function attention_summary(): array
{
    $user = current_user();
    if (!$user) {
        return [];
    }
    $role = (string) ($user['role'] ?? '');
    $items = [];

    try {
        if ($role === 'admin' || $role === 'staff') {
            $companyId = current_company_id();
            if ($companyId > 0) {
                if (table_exists('support_ticket_requests')) {
                    $stmt = db()->prepare("SELECT COUNT(*) FROM support_ticket_requests tr INNER JOIN support_tickets st ON st.id = tr.ticket_id WHERE st.company_id = :cid AND tr.decision_status = 'pending'");
                    $stmt->execute(['cid' => $companyId]);
                    $items[] = ['label' => 'Billing requests to decide', 'count' => (int) $stmt->fetchColumn(), 'url' => url('admin/tickets.php')];
                }
                if (column_exists('invoice_payment_requests', 'client_declared_status')) {
                    $stmt = db()->prepare("SELECT COUNT(*) FROM invoice_payment_requests WHERE company_id = :cid AND client_declared_status <> 'none' AND status IN ('pending', 'partial')");
                    $stmt->execute(['cid' => $companyId]);
                    $items[] = ['label' => 'Client payments to verify', 'count' => (int) $stmt->fetchColumn(), 'url' => url('admin/invoice.php')];
                }
                if (column_exists('vouchers', 'approval_state')) {
                    $stmt = db()->prepare("SELECT COUNT(*) FROM vouchers WHERE company_id = :cid AND approval_state = 'pending_approval'");
                    $stmt->execute(['cid' => $companyId]);
                    $items[] = ['label' => 'Vouchers awaiting approval', 'count' => (int) $stmt->fetchColumn(), 'url' => url('admin/audit-trail.php')];
                }
                if (table_exists('document_requests')) {
                    $stmt = db()->prepare("SELECT COUNT(*) FROM document_requests WHERE company_id = :cid AND status = 'uploaded'");
                    $stmt->execute(['cid' => $companyId]);
                    $items[] = ['label' => 'Uploaded documents to review', 'count' => (int) $stmt->fetchColumn(), 'url' => url('admin/documents.php?view=requests')];
                }
            }
            // Staff-accountant / mirror vouchers waiting in the books of clients
            // this admin serves — visible from any company context so the admin
            // is notified without having to open each client's books first.
            if ($role === 'admin' && column_exists('vouchers', 'approval_state') && function_exists('client_books_company_ids_for_servers')) {
                $bookIds = array_values(array_unique(client_books_company_ids_for_servers(authorized_company_ids())));
                $bookIds = array_diff($bookIds, [$companyId]);
                if ($bookIds !== []) {
                    $placeholders = implode(',', array_fill(0, count($bookIds), '?'));
                    $stmt = db()->prepare("SELECT COUNT(*) FROM vouchers WHERE approval_state = 'pending_approval' AND company_id IN ($placeholders)");
                    $stmt->execute(array_values($bookIds));
                    $items[] = ['label' => 'Client-books vouchers awaiting approval', 'count' => (int) $stmt->fetchColumn(), 'url' => url('admin/client-books.php')];
                }
            }
        } elseif ($role === 'customer') {
            $profile = client_profile_for_user((int) $user['id']);
            if ($profile) {
                $clientId = (int) $profile['id'];
                $booksCompanyId = (int) ($profile['books_company_id'] ?? 0);
                if ($booksCompanyId > 0 && column_exists('vouchers', 'requires_client_approval')) {
                    $stmt = db()->prepare("SELECT COUNT(*) FROM vouchers WHERE company_id = :cid AND approval_state = 'pending_approval' AND requires_client_approval = 1");
                    $stmt->execute(['cid' => $booksCompanyId]);
                    $items[] = ['label' => 'Vouchers waiting for your approval', 'count' => (int) $stmt->fetchColumn(), 'url' => url('dashboard.php?view=accounting')];
                }
                if (table_exists('support_ticket_requests')) {
                    $stmt = db()->prepare("SELECT COUNT(*) FROM support_ticket_requests tr INNER JOIN support_tickets st ON st.id = tr.ticket_id WHERE st.client_id = :client_id AND tr.decision_status = 'negotiation'");
                    $stmt->execute(['client_id' => $clientId]);
                    $items[] = ['label' => 'Counter-offers to review', 'count' => (int) $stmt->fetchColumn(), 'url' => url('dashboard.php?view=tickets')];
                }
                $declaredGuard = column_exists('invoice_payment_requests', 'client_declared_status') ? " AND ipr.client_declared_status = 'none'" : '';
                // Task-linked invoices AND party-linked invoices (migration 046).
                $partyRoute = column_exists('accounting_parties', 'client_profile_id')
                    ? ' OR ti.party_id IN (SELECT ap.id FROM accounting_parties ap WHERE ap.client_profile_id = :party_client_id)'
                    : '';
                $stmt = db()->prepare("SELECT COUNT(*) FROM invoice_payment_requests ipr
                    INNER JOIN task_invoices ti ON ti.id = ipr.invoice_id
                    LEFT JOIN client_tasks ct ON ct.id = ti.task_id
                    WHERE (ct.client_id = :client_id{$partyRoute}) AND ipr.status = 'pending'" . $declaredGuard);
                $bellParams = ['client_id' => $clientId];
                if ($partyRoute !== '') {
                    $bellParams['party_client_id'] = $clientId;
                }
                $stmt->execute($bellParams);
                $items[] = ['label' => 'Payments requested from you', 'count' => (int) $stmt->fetchColumn(), 'url' => url('dashboard.php?view=invoices')];
            }
        }
    } catch (Throwable $exception) {
        return [];
    }

    return array_values(array_filter($items, static fn (array $row): bool => $row['count'] > 0));
}

/**
 * Adds a system/timeline note into a support ticket conversation.
 */
function ticket_request_note(int $ticketId, int $senderId, string $body): void
{
    if (!table_exists('support_ticket_messages') || $body === '') {
        return;
    }
    try {
        db()->prepare('INSERT INTO support_ticket_messages (ticket_id, sender_id, body) VALUES (:ticket_id, :sender_id, :body)')
            ->execute(['ticket_id' => $ticketId, 'sender_id' => $senderId, 'body' => $body]);
    } catch (Throwable $exception) {
        // The timeline note is best-effort; the decision itself must not fail.
    }
}

/**
 * Emails the ticket's client about a request update when the
 * "notify_client_email" setting is enabled. Failures are swallowed —
 * email is a convenience on top of the in-portal timeline.
 */
function notify_ticket_client_email(int $ticketId, string $subject, string $htmlBody): void
{
    $enabled = strtolower((string) setting('notify_client_email', '0'));
    if (!in_array($enabled, ['1', 'true', 'yes', 'on'], true)) {
        return;
    }
    try {
        if (!function_exists('send_app_email')) {
            require_once __DIR__ . '/mailer.php';
        }
        $stmt = db()->prepare('SELECT u.email FROM support_tickets st
            INNER JOIN client_profiles cp ON cp.id = st.client_id
            INNER JOIN users u ON u.id = cp.user_id
            WHERE st.id = :id LIMIT 1');
        $stmt->execute(['id' => $ticketId]);
        $email = (string) ($stmt->fetchColumn() ?: '');
        if ($email !== '') {
            $wrapped = function_exists('branded_email_html') ? branded_email_html($subject, $htmlBody) : $htmlBody;
            send_app_email($email, $subject, $wrapped);
        }
    } catch (Throwable $exception) {
        // Never let notification failures break the approval flow.
    }
}

/**
 * Applies an approved billing request (raised from a support ticket) to its
 * target: issues the invoice, writes the discount onto the invoice, extends
 * the due date, or creates the pending payment request. Shared by the admin
 * decision handler and the client's acceptance of a negotiated counter-offer.
 *
 * @return array{error: ?string, invoice_id: ?int}
 */
function apply_ticket_request_decision(array $request, int $companyId, ?float $approvedAmount, ?float $approvedPercent, ?string $approvedDueOn, int $actorId): array
{
    $requestType = (string) ($request['request_type'] ?? '');
    $appliedInvoiceId = null;

    if ($requestType === 'invoice_request') {
        if (!empty($request['target_invoice_id'])) {
            $appliedInvoiceId = (int) $request['target_invoice_id'];
        } else {
            $targetTaskId = (int) ($request['target_task_id'] ?? 0);
            $targetStageId = (int) ($request['target_stage_id'] ?? 0);
            $issueAmount = $approvedAmount ?? (is_numeric((string) ($request['requested_amount'] ?? '')) ? round((float) $request['requested_amount'], 2) : 0.0);

            if ($targetTaskId <= 0 || $issueAmount <= 0) {
                return ['error' => 'Invoice request needs target task and amount.', 'invoice_id' => null];
            }

            $taskStmt = db()->prepare('SELECT id, status FROM client_tasks WHERE id = :id AND company_id = :company_id LIMIT 1');
            $taskStmt->execute(['id' => $targetTaskId, 'company_id' => $companyId]);
            $task = $taskStmt->fetch();
            if (!$task || ($task['status'] ?? '') !== 'completed') {
                return ['error' => 'Invoice can be issued only for completed task.', 'invoice_id' => null];
            }

            if ($targetStageId > 0) {
                $stageStmt = db()->prepare('SELECT id, status FROM task_stages WHERE id = :id AND task_id = :task_id AND company_id = :company_id LIMIT 1');
                $stageStmt->execute(['id' => $targetStageId, 'task_id' => $targetTaskId, 'company_id' => $companyId]);
                $stage = $stageStmt->fetch();
                if (!$stage || ($stage['status'] ?? '') !== 'completed') {
                    return ['error' => 'Stage invoice can be issued only for completed stage.', 'invoice_id' => null];
                }
            }

            $invoiceNo = 'INV-REQ-' . date('Ymd') . '-' . str_pad((string) $targetTaskId, 5, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
            $insertInvoice = db()->prepare('INSERT INTO task_invoices (company_id, task_id, stage_id, invoice_no, invoice_type, amount, status, issued_on, due_on, notes, issued_by) VALUES (:company_id, :task_id, :stage_id, :invoice_no, :invoice_type, :amount, :status, :issued_on, :due_on, :notes, :issued_by)');
            $insertInvoice->execute([
                'company_id' => $companyId,
                'task_id' => $targetTaskId,
                'stage_id' => $targetStageId > 0 ? $targetStageId : null,
                'invoice_no' => $invoiceNo,
                'invoice_type' => $targetStageId > 0 ? 'stage' : 'task',
                'amount' => $issueAmount,
                'status' => 'issued',
                'issued_on' => date('Y-m-d'),
                'due_on' => $approvedDueOn,
                'notes' => 'Issued from support ticket request #' . (int) ($request['ticket_id'] ?? 0),
                'issued_by' => $actorId,
            ]);
            $appliedInvoiceId = (int) db()->lastInsertId();

            try {
                $vatRate = 13.00;
                $vatAmount = round($issueAmount * ($vatRate / 100), 2);
                $totalAmount = round($issueAmount + $vatAmount, 2);
                db()->prepare('UPDATE task_invoices SET invoice_category = :category, vat_rate = :vat_rate, vat_amount = :vat_amount, taxable_amount = :taxable_amount, total_amount = :total_amount WHERE id = :id AND company_id = :company_id')
                    ->execute([
                        'category' => 'proforma',
                        'vat_rate' => $vatRate,
                        'vat_amount' => $vatAmount,
                        'taxable_amount' => $issueAmount,
                        'total_amount' => $totalAmount,
                        'id' => $appliedInvoiceId,
                        'company_id' => $companyId,
                    ]);
            } catch (Throwable $ignored) {
            }

            auto_post_task_invoice_voucher($appliedInvoiceId, $actorId);
        }
    }

    if ($requestType === 'discount_request') {
        $targetInvoiceId = (int) ($request['target_invoice_id'] ?? 0);
        if ($targetInvoiceId <= 0) {
            return ['error' => 'Discount request needs a target invoice.', 'invoice_id' => null];
        }
        $invoiceStmt = db()->prepare('SELECT * FROM task_invoices WHERE id = :id AND company_id = :company_id LIMIT 1');
        $invoiceStmt->execute(['id' => $targetInvoiceId, 'company_id' => $companyId]);
        $invoice = $invoiceStmt->fetch();
        if (!$invoice) {
            return ['error' => 'Target invoice not found.', 'invoice_id' => null];
        }

        $taxable = (float) ($invoice['taxable_amount'] ?? $invoice['amount'] ?? 0);
        $discountPercent = $approvedPercent ?? (is_numeric((string) ($request['requested_percent'] ?? '')) ? (float) $request['requested_percent'] : 0.0);
        $discountFixed = $approvedAmount ?? (is_numeric((string) ($request['requested_amount'] ?? '')) ? (float) $request['requested_amount'] : 0.0);
        $discountAmount = $discountFixed > 0
            ? $discountFixed
            : ($discountPercent > 0 ? round($taxable * ($discountPercent / 100), 2) : 0.0);

        if ($discountAmount <= 0 || $discountAmount >= $taxable) {
            return ['error' => 'Discount amount is invalid for selected invoice.', 'invoice_id' => null];
        }

        $newTaxable = round($taxable - $discountAmount, 2);
        $vatRate = (float) ($invoice['vat_rate'] ?? 13.00);
        $newVat = round($newTaxable * ($vatRate / 100), 2);
        $newTotal = round($newTaxable + $newVat, 2);
        $oldTotal = round((float) ($invoice['total_amount'] ?? ($taxable + (float) ($invoice['vat_amount'] ?? 0))), 2);
        db()->prepare('UPDATE task_invoices SET taxable_amount = :taxable_amount, vat_amount = :vat_amount, total_amount = :total_amount, discount_type = :discount_type, discount_value = :discount_value, discount_amount = :discount_amount, adjusted_on = NOW(), adjusted_by = :adjusted_by WHERE id = :id AND company_id = :company_id')
            ->execute([
                'taxable_amount' => $newTaxable,
                'vat_amount' => $newVat,
                'total_amount' => $newTotal,
                'discount_type' => $discountFixed > 0 ? 'fixed' : 'percent',
                'discount_value' => $discountFixed > 0 ? $discountFixed : $discountPercent,
                'discount_amount' => $discountAmount,
                'adjusted_by' => $actorId,
                'id' => $targetInvoiceId,
                'company_id' => $companyId,
            ]);
        $appliedInvoiceId = $targetInvoiceId;

        // Mirror the benefit into the client's books as discount received.
        auto_post_client_mirror_discount((int) ($request['id'] ?? 0), $targetInvoiceId, $oldTotal - $newTotal, $actorId);
    }

    if ($requestType === 'credit_period_request') {
        $targetInvoiceId = (int) ($request['target_invoice_id'] ?? 0);
        $newDue = $approvedDueOn ?: ($request['requested_due_on'] ?? null);
        if ($targetInvoiceId <= 0 || !$newDue) {
            return ['error' => 'Credit period request needs target invoice and due date.', 'invoice_id' => null];
        }
        db()->prepare('UPDATE task_invoices SET due_on = :due_on, adjusted_on = NOW(), adjusted_by = :adjusted_by WHERE id = :id AND company_id = :company_id')
            ->execute([
                'due_on' => $newDue,
                'adjusted_by' => $actorId,
                'id' => $targetInvoiceId,
                'company_id' => $companyId,
            ]);
        $appliedInvoiceId = $targetInvoiceId;
    }

    if (in_array($requestType, ['advance_payment_request', 'partial_payment_request'], true)) {
        $targetInvoiceId = (int) ($request['target_invoice_id'] ?? 0);
        $requestAmount = $approvedAmount ?? (is_numeric((string) ($request['requested_amount'] ?? '')) ? round((float) $request['requested_amount'], 2) : 0.0);
        if ($targetInvoiceId <= 0 || $requestAmount <= 0) {
            return ['error' => 'Payment request needs target invoice and amount.', 'invoice_id' => null];
        }
        db()->prepare('INSERT INTO invoice_payment_requests (invoice_id, company_id, requested_by, amount_requested, payment_method, status, notes) VALUES (:invoice_id, :company_id, :requested_by, :amount_requested, :payment_method, :status, :notes)')
            ->execute([
                'invoice_id' => $targetInvoiceId,
                'company_id' => $companyId,
                'requested_by' => $actorId,
                'amount_requested' => $requestAmount,
                'payment_method' => 'Pending client payment',
                'status' => 'pending',
                'notes' => ucfirst(str_replace('_', ' ', $requestType)) . ' approved from support ticket #' . (int) ($request['ticket_id'] ?? 0),
            ]);
        $appliedInvoiceId = $targetInvoiceId;
    }

    return ['error' => null, 'invoice_id' => $appliedInvoiceId];
}
