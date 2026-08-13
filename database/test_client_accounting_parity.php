<?php
declare(strict_types=1);

/** Source-level contract for the client-books navigation and route gates. */
$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$ok = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
    $condition ? $pass++ : $fail++;
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$access = $read('app/access_control.php');
$header = $read('app/views/partials/admin_header.php');
$dashboard = $read('public_html/dashboard.php');
$voucherTypes = $read('app/voucher_types.php');
$voucherStrip = $read('app/views/vouchers/type_strip.php');
$partyMaster = $read('public_html/admin/accounting-parties.php');
$helpers = $read('app/helpers.php');

echo "Client owner accounting parity\n";
$ok(str_contains($access, "'opening_balance' => ['view', 'generate', 'adjust', 'finalize']"),
    'Client books define the complete opening-balance lifecycle');
$ok(str_contains($access, "'payroll' => ['view', 'create', 'adjust', 'approve', 'post', 'export']"),
    'Client books define the complete payroll lifecycle');
foreach (['payment-gateways.php', 'budgets.php', 'payroll.php', 'payroll-settings.php'] as $page) {
    $source = $read('public_html/admin/' . $page);
    $ok(str_contains($source, 'require_staff_admin_or_client_books();') && !str_contains($source, 'require_admin();'),
        $page . ' uses the shared client-books gate');
}
foreach (['export-invoice.php', 'export-payment-receipt.php', 'payroll-employee-sheet.php'] as $page) {
    $source = $read('public_html/admin/' . $page);
    $ok(str_contains($source, 'require_staff_admin_or_client_books();') && !str_contains($source, 'require_admin();'),
        $page . ' secondary action stays inside the shared client-books gate');
}
$ok(str_contains($read('public_html/admin/audit-trail.php'), 'require_staff_admin_or_client_books();'),
    'The audit trail is available inside the client books scope');
$ok(str_contains($helpers, "column_exists('activity_logs', 'company_id')")
    && str_contains($read('public_html/admin/audit-trail.php'), 'al.company_id = :client_company_id'),
    'Activity history is tenant-scoped while retaining accountant actions');
$ok(str_contains($read('public_html/admin/report-schedules.php'), 'require_staff_admin_or_client_books();'),
    'Report scheduling is available inside the client books scope');
$ok(str_contains($dashboard, 'SELECT COUNT(*) FROM vouchers')
    && str_contains($dashboard, 'ORDER BY created_at ASC, id ASC LIMIT 100'),
    'Pending approvals use an independent count and bounded queue');

echo "\nUnified transaction and party workflow\n";
$ok(str_contains($header, 'Unified Transaction and Party Master')
    && !str_contains($header, "['Sales & Invoices', 'admin/accounting-parties.php?tab=sales'")
    && !str_contains($header, "['Purchases', 'admin/accounting-parties.php?tab=purchases'"),
    'Sales and purchases are represented by one sidebar destination');
$ok(str_contains($partyMaster, "\$pageTitle = 'Unified Transaction and Party Master';"),
    'The unified page carries the requested name');
$ok(str_contains($partyMaster, "url('admin/invoice.php')")
    && str_contains($partyMaster, "parties_page_url(['panel' => 'purchase', 'edit_id' => null])"),
    'Party Master retains its separate invoice and purchase workflows');
$ok(str_contains($voucherTypes, 'function voucher_entry_type_catalog()')
    && str_contains($voucherStrip, 'voucher_entry_type_catalog()'),
    'The generic voucher chooser is restricted to accounting-only vouchers');
foreach (['sales', 'purchase', 'debit_note', 'credit_note'] as $type) {
    $ok(str_contains($voucherTypes, "'" . $type . "'"), $type . ' remains a supported posted voucher type');
}
$ok(str_contains($partyMaster, "voucher-form.php?type=sales")
    && str_contains($partyMaster, "voucher-form.php?type=purchase"), 'Sales and purchase vouchers remain separate specialist workflows');
$ok(str_contains($partyMaster, "voucher-form.php?type=debit_note"), 'Debit Note is launched by the unified master');
$ok(str_contains($partyMaster, "voucher-form.php?type=credit_note"), 'Credit Note is launched by the unified master');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
