<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

require_login();

$user = current_user();
$role = (string) ($user['role'] ?? '');
$userId = (int) $user['id'];
$type = (string) ($_GET['type'] ?? '');
$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0 || !in_array($type, ['message', 'ticket_message', 'leave'], true)) {
    flash('error', 'Invalid attachment.');
    redirect(role_home_path($user));
}

function attachment_user_can_access_thread(string $role, int $userId, int $companyId, int $threadId): bool
{
    if ($role === 'admin') {
        $companyStmt = db()->prepare('SELECT id FROM message_threads WHERE id = :id AND company_id = :company_id LIMIT 1');
        $companyStmt->execute(['id' => $threadId, 'company_id' => $companyId]);
        return (bool) $companyStmt->fetch();
    }

    $stmt = db()->prepare('SELECT id FROM message_thread_participants WHERE thread_id = :thread_id AND user_id = :user_id LIMIT 1');
    $stmt->execute(['thread_id' => $threadId, 'user_id' => $userId]);

    return (bool) $stmt->fetch();
}

$filePath = null;
$originalName = null;

if ($type === 'message') {
    $stmt = db()->prepare('SELECT m.attachment_path, m.attachment_name, m.thread_id, mt.company_id
        FROM messages m
        INNER JOIN message_threads mt ON mt.id = m.thread_id
        WHERE m.id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if ($row && $row['attachment_path']) {
        $companyId = $role === 'admin' ? current_company_id() : (int) ($user['company_id'] ?? 0);
        $authorized = attachment_user_can_access_thread($role, $userId, (int) $row['company_id'], (int) $row['thread_id']);

        if ($authorized) {
            $filePath = (string) $row['attachment_path'];
            $originalName = (string) $row['attachment_name'];
        }
    }
} elseif ($type === 'ticket_message') {
    $stmt = db()->prepare('SELECT tm.attachment_path, tm.attachment_name, st.company_id, st.client_id
        FROM support_ticket_messages tm
        INNER JOIN support_tickets st ON st.id = tm.ticket_id
        WHERE tm.id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if ($row && $row['attachment_path']) {
        $authorized = false;
        if ($role === 'admin') {
            $authorized = (int) $row['company_id'] === current_company_id();
        } elseif ($role === 'staff') {
            $staffCompanyId = (int) ($user['company_id'] ?? 0);
            $authorized = (int) $row['company_id'] === $staffCompanyId
                && in_array((int) $row['client_id'], staff_scoped_client_ids($userId, $staffCompanyId), true);
        } elseif ($role === 'customer') {
            $clientProfile = client_profile_for_user($userId);
            $authorized = $clientProfile && (int) $clientProfile['id'] === (int) $row['client_id'];
        }

        if ($authorized) {
            $filePath = (string) $row['attachment_path'];
            $originalName = (string) $row['attachment_name'];
        }
    }
} else {
    $stmt = db()->prepare('SELECT attachment_path, attachment_name, company_id, staff_user_id FROM leave_requests WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if ($row && $row['attachment_path']) {
        $authorized = false;
        if ($role === 'admin') {
            $authorized = (int) $row['company_id'] === current_company_id();
        } elseif ($role === 'staff') {
            $authorized = (int) $row['staff_user_id'] === $userId;
        }

        if ($authorized) {
            $filePath = (string) $row['attachment_path'];
            $originalName = (string) $row['attachment_name'];
        }
    }
}

if ($filePath === null) {
    flash('error', 'You do not have access to that attachment.');
    redirect(role_home_path($user));
}

$fullPath = __DIR__ . '/' . $filePath;
if (!is_file($fullPath)) {
    flash('error', 'The file could not be found on the server.');
    redirect(role_home_path($user));
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($fullPath);

header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . basename((string) $originalName) . '"');
header('Content-Length: ' . (string) filesize($fullPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

readfile($fullPath);
exit;
