<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_staff_admin_or_client_books();
require_company_context();

$currentCompany = current_company();
$fiscalYear = current_fiscal_year();
$format = $_GET['format'] ?? 'pdf'; // 'pdf' or 'excel'

if (!$currentCompany || !$fiscalYear) {
    http_response_code(400);
    echo 'Missing company or fiscal year context';
    exit;
}

if ($format === 'excel') {
    // Export as Excel/CSV
    require_permission('reports', 'export');
    $export = export_ledger_to_excel((int) $currentCompany['id'], (int) $fiscalYear['id']);
    $filename = $export['filename'] ?? 'ledger.csv';
    $data = $export['data'] ?? [];
    security_event('report_exported', 'success', 'Ledger export: ' . ($currentCompany['name'] ?? 'ledger') . '.', (int) $currentCompany['id'], (int) (current_user()['id'] ?? 0));
    export_csv($filename, $data);
} else {
    // Export as printable PDF (HTML that can be printed to PDF)
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="Ledger-' . $currentCompany['code'] . '.html"');
    
    echo export_ledger_html((int) $currentCompany['id'], (int) $fiscalYear['id']);
    exit;
}
