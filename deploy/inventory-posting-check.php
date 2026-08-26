<?php
declare(strict_types=1);

/**
 * Why a stock movement never reached the ledger.
 *
 *   php deploy/inventory-posting-check.php <company_id>
 *
 * A purchase recorded on Inventory & Manufacturing tries to post a voucher --
 * Dr the item's stock ledger, Cr the supplier (or Purchase / GRNI Clearing when
 * no supplier is named). If either ledger cannot be resolved, the movement is
 * recorded STOCK ONLY: the quantity is right, the stock summary is right, and
 * the general ledger never hears about it. Nothing errors, and the only sign on
 * screen is a delete bin where a posted movement would offer "Reverse".
 *
 * This prints, for one company: how many movements posted and how many did
 * not, what the unposted ones are worth, and exactly which mapping is missing.
 * It reads and reports. It changes nothing.
 */

fwrite(STDOUT, "inventory-posting-check: starting (PHP " . PHP_VERSION . ")\n");

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

$fail = static function (string $message, int $code = 1): never {
    fwrite(STDERR, 'inventory-posting-check: ' . $message . "\n");
    exit($code);
};

// Same .env discovery as the other deploy scripts: the repository has no .env of
// its own, and falling back to development defaults reads the wrong database.
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
fwrite(STDOUT, 'inventory-posting-check: ' . DB_NAME . ' on ' . DB_HOST . ' (' . $envPath . ")\n\n");

try {
    $pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET), DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $exception) {
    $fail('could not connect: ' . $exception->getMessage(), 3);
}

$companyId = 0;
foreach (array_slice($argv, 1) as $argument) {
    if (ctype_digit($argument)) {
        $companyId = (int) $argument;
    }
}
// Nobody knows their company id by heart, and there is no sensible default.
if ($companyId <= 0) {
    fwrite(STDOUT, "Give a company id. The ones with stock movements are:\n\n");
    $list = $pdo->query("SELECT c.id, c.name, COUNT(t.id) AS movements,
            SUM(t.voucher_id IS NULL) AS stock_only
        FROM companies c
        INNER JOIN inventory_transactions t ON t.company_id = c.id
        GROUP BY c.id, c.name ORDER BY stock_only DESC, c.id ASC");
    foreach ($list->fetchAll() as $row) {
        fwrite(STDOUT, sprintf("  %-6s %-46s %4d movement(s), %d not posted\n",
            $row['id'], mb_substr((string) $row['name'], 0, 46), (int) $row['movements'], (int) $row['stock_only']));
    }
    fwrite(STDOUT, "\n  php deploy/inventory-posting-check.php <company_id>\n");
    exit(0);
}

$nameStmt = $pdo->prepare('SELECT name FROM companies WHERE id = :id');
$nameStmt->execute(['id' => $companyId]);
$companyName = (string) ($nameStmt->fetchColumn() ?: '');
if ($companyName === '') {
    $fail('no company with id ' . $companyId, 4);
}
fwrite(STDOUT, "Company #{$companyId} — {$companyName}\n" . str_repeat('=', 68) . "\n\n");

// --- 1. How much never reached the ledger -----------------------------------
$sum = $pdo->prepare("SELECT COUNT(*) AS movements,
        SUM(t.voucher_id IS NULL) AS stock_only,
        SUM(t.voucher_id IS NOT NULL) AS posted,
        COALESCE(SUM(CASE WHEN t.voucher_id IS NULL THEN t.amount ELSE 0 END), 0) AS stock_only_value
    FROM inventory_transactions t WHERE t.company_id = :cid");
$sum->execute(['cid' => $companyId]);
$totals = $sum->fetch() ?: [];
fwrite(STDOUT, sprintf("1. Movements\n   %d in total — %d posted a voucher, %d recorded STOCK ONLY (worth %s)\n\n",
    (int) ($totals['movements'] ?? 0), (int) ($totals['posted'] ?? 0),
    (int) ($totals['stock_only'] ?? 0), number_format((float) ($totals['stock_only_value'] ?? 0), 2)));

if ((int) ($totals['stock_only'] ?? 0) === 0) {
    fwrite(STDOUT, "   Every movement carries a voucher. There is nothing missing from the ledger.\n\n");
}

// --- 2. Which ones, so they can be recognised on screen ----------------------
$rows = $pdo->prepare("SELECT t.transaction_date, t.transaction_type, t.qty_in, t.qty_out, t.amount, t.ref_no,
        i.sku, i.name, i.ledger_id AS item_ledger_id, i.category
    FROM inventory_transactions t
    INNER JOIN inventory_items i ON i.id = t.item_id
    WHERE t.company_id = :cid AND t.voucher_id IS NULL
    ORDER BY t.transaction_date DESC, t.id DESC LIMIT 25");
$rows->execute(['cid' => $companyId]);
$unposted = $rows->fetchAll();
if ($unposted !== []) {
    fwrite(STDOUT, "2. The ones with no journal entry (most recent 25)\n");
    foreach ($unposted as $row) {
        fwrite(STDOUT, sprintf("   %-12s %-28s %-10s %10s  ref %s\n",
            (string) $row['transaction_date'],
            mb_substr((string) $row['sku'] . ' - ' . (string) $row['name'], 0, 28),
            (string) $row['transaction_type'],
            number_format((float) $row['amount'], 2),
            (string) ($row['ref_no'] ?? '-')));
    }
    fwrite(STDOUT, "\n");
}

// --- 3. The actual cause ------------------------------------------------------
// A purchase needs the stock ledger and the counterparty. Anything else the
// mappings are missing matters for other movement types, so all of them are
// listed rather than only the two this company happened to trip on.
$mapStmt = $pdo->prepare("SELECT purpose, scope, ledger_id FROM inventory_ledger_mappings WHERE company_id = :cid");
$mapStmt->execute(['cid' => $companyId]);
$mapped = [];
foreach ($mapStmt->fetchAll() as $row) {
    $mapped[(string) $row['purpose']][] = $row;
}

$ledgerName = static function (?int $ledgerId) use ($pdo, $companyId): string {
    if (!$ledgerId) {
        return '';
    }
    $stmt = $pdo->prepare('SELECT l.code, l.name, l.type, g.master_key
        FROM ledgers l LEFT JOIN ledger_groups g ON g.id = l.group_id
        WHERE l.id = :id AND l.company_id = :cid');
    $stmt->execute(['id' => $ledgerId, 'cid' => $companyId]);
    $row = $stmt->fetch();

    return $row ? ((string) $row['name'] . ' (' . (string) $row['code'] . ', ' . (string) $row['type'] . ')') : '#' . $ledgerId . ' — missing';
};

$needed = [
    'inventory_asset' => 'asset',
    'purchase_clearing' => 'liability',
    'cogs' => 'expense',
    'sales_revenue' => 'revenue',
    'opening_equity' => 'equity',
    'inventory_loss' => 'expense',
];
fwrite(STDOUT, "3. Ledger mapping\n");
$missing = [];
foreach ($needed as $purpose => $expected) {
    if (!isset($mapped[$purpose])) {
        $missing[] = $purpose;
        fwrite(STDOUT, sprintf("   %-20s NOT MAPPED   (needs %s ledger)\n", $purpose, $expected));
        continue;
    }
    foreach ($mapped[$purpose] as $row) {
        $resolved = $ledgerName((int) $row['ledger_id']);
        fwrite(STDOUT, sprintf("   %-20s %-9s %s\n", $purpose, (string) $row['scope'], $resolved));
    }
}
fwrite(STDOUT, "\n");

// The legacy per-item column the resolver falls back to. It must be an asset;
// pointed at an expense it turns every purchase into a Direct Cost, and since
// that was never checked, older books can be carrying it.
$legacy = $pdo->prepare("SELECT i.sku, i.name, l.name AS ledger_name, l.code, l.type, g.master_key
    FROM inventory_items i
    INNER JOIN ledgers l ON l.id = i.ledger_id AND l.company_id = i.company_id
    LEFT JOIN ledger_groups g ON g.id = l.group_id
    WHERE i.company_id = :cid AND i.ledger_id IS NOT NULL AND l.type <> 'asset'");
$legacy->execute(['cid' => $companyId]);
$wrongKind = $legacy->fetchAll();
if ($wrongKind !== []) {
    fwrite(STDOUT, "4. Items whose linked ledger is NOT an asset\n");
    foreach ($wrongKind as $row) {
        fwrite(STDOUT, sprintf("   %-14s -> %s (%s) — a %s account\n",
            (string) $row['sku'], (string) $row['ledger_name'], (string) $row['code'], (string) $row['type']));
    }
    fwrite(STDOUT, "\n   Stock posted to one of these charges every purchase straight to the\n"
        . "   profit and loss and leaves the balance sheet with no inventory on it.\n\n");
}

// --- What to do ---------------------------------------------------------------
if ($missing !== [] || (int) ($totals['stock_only'] ?? 0) > 0) {
    fwrite(STDOUT, "WHAT TO DO\n" . str_repeat('-', 68) . "\n");
    if ($missing !== []) {
        fwrite(STDOUT, "  1. Admin -> Inventory & Manufacturing -> Ledger mapping, and set:\n");
        foreach ($missing as $purpose) {
            fwrite(STDOUT, '       ' . $purpose . ' (' . $needed[$purpose] . " ledger)\n");
        }
        fwrite(STDOUT, "     Or press the one-click setup there, which opens them in the right groups.\n");
    }
    fwrite(STDOUT, "  2. Admin -> Stock Summary Report -> Reconcile Stock <-> General Ledger.\n"
        . "     It posts every movement above at its historical replayed cost, in date\n"
        . "     order, and leaves the ones already posted alone.\n\n");
    fwrite(STDOUT, "  Nothing here has been changed by running this.\n");
}
