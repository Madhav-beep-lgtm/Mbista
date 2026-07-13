<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_admin();
require_company_context();

$currentCompany = current_company();
$requestId = (int) ($_GET['request_id'] ?? 0);

if ($requestId <= 0) {
    http_response_code(404);
    echo 'Payment receipt request not found';
    exit;
}

if (!table_exists('invoice_payment_receipts')) {
    http_response_code(400);
    echo 'Payment receipts feature not available. Apply latest migration.';
    exit;
}

$receipt = get_payment_receipt_by_request($requestId, (int) ($currentCompany['id'] ?? 0));
if (!$receipt) {
    http_response_code(404);
    echo 'Receipt not found for this request';
    exit;
}

require_permission('reports', 'export');

$safeNo = preg_replace('/[^A-Za-z0-9\-_]/', '_', (string) ($receipt['receipt_no'] ?? $requestId));
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline; filename="Receipt-' . ($safeNo ?: (string) $requestId) . '.html"');

security_event('report_exported', 'success', 'Payment receipt #' . ($receipt['receipt_no'] ?? $requestId) . ' exported.', (int) ($currentCompany['id'] ?? 0), (int) (current_user()['id'] ?? 0));
echo export_payment_receipt_html($receipt);
exit;
