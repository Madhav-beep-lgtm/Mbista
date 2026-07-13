<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_admin();
require_company_context();

$currentCompany = current_company();
$contractId = (int) ($_GET['id'] ?? 0);
$format = (string) ($_GET['format'] ?? 'pdf');

if ($contractId <= 0) {
    http_response_code(404);
    echo 'Contract not found';
    exit;
}

$contract = get_service_contract($contractId);
if (!$contract || (int) ($contract['company_id'] ?? 0) !== (int) ($currentCompany['id'] ?? 0)) {
    http_response_code(403);
    echo 'Unauthorized access to this contract';
    exit;
}

if ($format !== 'pdf') {
    $format = 'pdf';
}

require_permission('reports', 'export');

$safeContractNo = preg_replace('/[^A-Za-z0-9\-_]/', '_', (string) ($contract['contract_no'] ?? $contractId));
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline; filename="Contract-' . ($safeContractNo ?: (string) $contractId) . '.html"');

security_event('report_exported', 'success', 'Contract #' . $contractId . ' exported.', (int) ($currentCompany['id'] ?? 0), (int) (current_user()['id'] ?? 0));
echo export_contract_to_pdf_html($contractId);
exit;
