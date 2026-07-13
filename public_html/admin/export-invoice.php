<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_admin();
require_company_context();

$currentCompany = current_company();
$invoiceId = (int) ($_GET['id'] ?? 0);
$format = $_GET['format'] ?? 'pdf'; // 'pdf' or 'excel'

if ($invoiceId <= 0) {
    http_response_code(404);
    echo 'Invoice not found';
    exit;
}

$invoice = get_invoice($invoiceId);

if (!$invoice || (int) ($invoice['company_id'] ?? 0) !== (int) $currentCompany['id']) {
    http_response_code(403);
    echo 'Unauthorized access to this invoice';
    exit;
}

if ($format === 'excel') {
    // Export as Excel/CSV
    require_permission('reports', 'export');
    $export = export_invoice_to_excel($invoiceId);
    $filename = $export['filename'] ?? 'invoice.csv';
    $data = $export['rows'] ?? ($export['data'] ?? []);
    security_event('report_exported', 'success', 'Invoice #' . $invoiceId . ' exported.', (int) $currentCompany['id'], (int) (current_user()['id'] ?? 0));
    export_csv($filename, $data);
} else {
    // Export as printable PDF (HTML that can be printed to PDF)
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="Invoice-' . $invoice['invoice_no'] . '.html"');
    
    echo export_invoice_to_pdf_html($invoiceId);
    exit;
}
