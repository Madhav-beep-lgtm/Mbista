<?php
declare(strict_types=1);
// Exercise the real history query and rendering against isolated fixture data.
function db(): PDO { global $pdo; return $pdo; }
function require_permission(string $module, string $action): void {
    if ($module !== 'accounting' || $action !== 'view') throw new RuntimeException('Wrong permission');
}
function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES); }
function app_date(string $date): string { return 'display-' . $date; }
function url(string $path): string { return '/' . $path; }
function inv_back_url(string $query): string { return 'history.php?' . $query; }
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE vouchers (id INTEGER PRIMARY KEY, company_id INT, fiscal_year_id INT, voucher_date TEXT, voucher_no TEXT, voucher_type TEXT, reference_no TEXT, total_amount REAL, status TEXT)');
$insert = $pdo->prepare('INSERT INTO vouchers VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([[1,1,2,'P-1','purchase','posted'], [2,1,2,'PR-1','debit_note','posted'], [3,1,2,'S-1','sales','posted'], [4,1,2,'SR-1','credit_note','draft'], [5,9,2,'OTHER-COMPANY','purchase','posted'], [6,1,9,'OTHER-YEAR','purchase','posted'], [7,1,2,'J-1','journal','posted']] as [$id,$company,$year,$number,$type,$status]) {
    $insert->execute([$id,$company,$year,'2026-10-13',$number,$type,'<bill>',123.45,$status]);
}
$companyId = 1;
$fiscalYearId = 2;
foreach (['purchase'=>['P-1','PR-1'], 'sale'=>['S-1','SR-1']] as $invTask => $expected) {
    ob_start();
    require __DIR__ . '/../app/views/partials/inventory_voucher_history.php';
    $html = ob_get_clean();
    check(count($historyVouchers) === 2, 'Scope/type filter failed');
    foreach ($expected as $number) check(str_contains($html, $number), 'Missing voucher');
    check(str_contains($html, 'data-voucher-preview'), 'Missing journal preview');
    check(str_contains($html, '&lt;bill&gt;'), 'Reference must be escaped');
    check(str_contains($html, 'display-2026-10-13'), 'Date formatter missing');
}
$companyId = 99;
ob_start();
require __DIR__ . '/../app/views/partials/inventory_voucher_history.php';
$html = ob_get_clean();
check(str_contains($html, 'No vouchers'), 'Empty state missing');
$source = file_get_contents(__DIR__ . '/../public_html/admin/accounting-inventory.php');
foreach (['saleMovementForm', 'Record Sale / Sales Return', 'Purchase entries</strong>', 'inv_purchase_bill_list('] as $retired) check(!str_contains($source, $retired), 'Retired panel remains');
check(str_contains($source, "['sale', 'sales_return', 'purchase_return']"), 'Legacy posting guard missing');
echo "Voucher history scope, types, rendering and retired-panel checks passed.\n";
