<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/voucher_types.php';
function e(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function asset_url(string $path): string { return '/' . $path; }
function site_currency_symbol(): string { return 'Rs.'; }
$company = ['name' => 'Test <Company>', 'address' => "Street 1\nKathmandu"];
$financialYear = '2026–2027';
$names = ['submitted_by' => 'Maker', 'approved_by' => 'Checker'];
$entries = [
    ['entry_type' => 'debit', 'amount' => 1234.5, 'ledger_name' => 'Bank', 'memo' => '<script>test</script>'],
    ['entry_type' => 'credit', 'amount' => 1234.5, 'ledger_name' => 'Cash', 'memo' => 'Transfer'],
];
foreach (voucher_entry_type_catalog() as $type => $spec) {
    $voucher = ['voucher_no' => 'TEST/001', 'voucher_date' => '2026-10-13', 'status' => 'draft', 'approval_state' => 'pending_approval', 'narration' => 'Test narration'];
    $voucherLabel = $spec['label'];
    $totalDebit = $totalCredit = 0;
    ob_start();
    include __DIR__ . '/../app/views/vouchers/print.php';
    $html = ob_get_clean();
    foreach (['Test &lt;Company&gt;', 'Kathmandu', '2026–2027', '2026-10-13', '1,234.50', 'Maker / Prepared by', 'Checker / Approved by', 'Print voucher', e($voucherLabel)] as $expected) {
        if (!str_contains($html, $expected)) { throw new RuntimeException($type . ': missing ' . $expected); }
    }
    if (str_contains($html, '<script>test</script>') || $totalDebit !== 1234.5 || $totalCredit !== 1234.5) {
        throw new RuntimeException('Escaping or totals failed');
    }
}
echo "All voucher print types: header, dates, totals, signatures and escaping passed.\n";
