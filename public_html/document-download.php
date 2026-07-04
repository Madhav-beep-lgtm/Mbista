<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

require_login();

$user = current_user();
$documentId = (int) ($_GET['id'] ?? 0);
$inlineView = (string) ($_GET['inline'] ?? '0') === '1';

if ($documentId <= 0) {
    flash('error', 'Invalid document.');
    redirect(role_home_path($user));
}

$stmt = db()->prepare('SELECT * FROM documents WHERE id = :id AND is_active = 1 LIMIT 1');
$stmt->execute(['id' => $documentId]);
$document = $stmt->fetch();

if (!$document) {
    flash('error', 'Document not found.');
    redirect(role_home_path($user));
}

$role = (string) ($user['role'] ?? '');
$authorized = false;

if ($role === 'admin') {
    $authorized = (int) $document['company_id'] === current_company_id();
} elseif ($role === 'staff') {
    $staffId = (int) $user['id'];
    $staffCompanyId = (int) ($user['company_id'] ?? 0);
    if ((int) $document['company_id'] === $staffCompanyId) {
        $scopedClientIds = staff_scoped_client_ids($staffId, $staffCompanyId);
        $authorized = in_array((int) $document['client_id'], $scopedClientIds, true);
    }
} elseif ($role === 'customer') {
    $profileStmt = db()->prepare('SELECT id FROM client_profiles WHERE user_id = :user_id LIMIT 1');
    $profileStmt->execute(['user_id' => (int) $user['id']]);
    $clientProfile = $profileStmt->fetch();
    if ($clientProfile && (int) $clientProfile['id'] === (int) $document['client_id']) {
        $authorized = $document['visibility'] === 'client';
    }
}

if (!$authorized) {
    flash('error', 'You do not have access to that document.');
    redirect(role_home_path($user));
}

$filePath = __DIR__ . '/' . $document['file_path'];
if (!is_file($filePath)) {
    flash('error', 'The file could not be found on the server.');
    redirect(role_home_path($user));
}

log_activity('document', $documentId, 'downloaded', 'Document downloaded.', (int) $user['id']);

$mime = (string) ($document['file_type'] ?: 'application/octet-stream');
$previewable = [
    'application/pdf',
    'image/png',
    'image/jpeg',
    'image/gif',
    'image/webp',
    'text/plain',
    'text/csv',
];
$allowInline = $inlineView && in_array(strtolower($mime), $previewable, true);

if ($allowInline) {
    log_activity('document', $documentId, 'previewed', 'Document preview opened.', (int) $user['id']);
}

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($allowInline ? 'inline' : 'attachment') . '; filename="' . basename((string) $document['original_file_name']) . '"');
header('Content-Length: ' . (string) filesize($filePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

readfile($filePath);
exit;
