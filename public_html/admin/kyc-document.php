<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_admin();
require_company_context();

$currentCompany = current_company();
$currentAdmin = current_user();
$documentId = (int) ($_GET['id'] ?? 0);

if ($documentId <= 0 || !table_exists('staff_kyc_documents')) {
    http_response_code(404);
    exit('Document not found.');
}

// Company scoping: the staff member must belong to the admin's current company portal.
$stmt = db()->prepare('SELECT d.*, u.company_id AS staff_company_id
    FROM staff_kyc_documents d
    INNER JOIN users u ON u.id = d.staff_user_id
    WHERE d.id = :id
    LIMIT 1');
$stmt->execute(['id' => $documentId]);
$document = $stmt->fetch();

if (!$document || (int) $document['staff_company_id'] !== (int) ($currentCompany['id'] ?? 0)) {
    http_response_code(404);
    exit('Document not found.');
}

$storedFilename = (string) $document['stored_filename'];
// Defence in depth: stored names are generated hex + extension only; reject anything else.
if (!preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png)$/', $storedFilename)) {
    http_response_code(404);
    exit('Document not found.');
}

$filePath = kyc_storage_dir() . '/' . $storedFilename;
if (!is_file($filePath)) {
    http_response_code(404);
    exit('Document not found.');
}

log_activity('user', (int) $document['staff_user_id'], 'kyc_viewed', 'KYC document #' . $documentId . ' viewed.', (int) ($currentAdmin['id'] ?? 0));

$mime = (string) ($document['mime_type'] ?? 'application/octet-stream');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($filePath));
header("Content-Disposition: inline; filename=\"kyc-document-{$documentId}\"");
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($filePath);
exit;
