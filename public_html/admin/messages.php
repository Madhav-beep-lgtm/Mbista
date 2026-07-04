<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_or_admin();

$pageTitle = 'Messages';
$user = current_user();
$role = (string) ($user['role'] ?? '');
$userId = (int) $user['id'];

if ($role === 'admin') {
    require_company_context();
    $company = current_company();
    $companyId = (int) ($company['id'] ?? 0);
} else {
    $companyId = (int) ($user['company_id'] ?? 0);
}

$scopedClientIds = $role === 'staff' ? staff_scoped_client_ids($userId, $companyId) : null;

$clients = [];
if (table_exists('client_profiles')) {
    $clientSql = 'SELECT cp.id, cp.organization_name FROM client_profiles cp WHERE cp.company_id = ?';
    $clientParams = [$companyId];
    if ($scopedClientIds !== null) {
        if ($scopedClientIds === []) {
            $clientSql .= ' AND 1 = 0';
        } else {
            $placeholders = implode(',', array_fill(0, count($scopedClientIds), '?'));
            $clientSql .= " AND cp.id IN ($placeholders)";
            $clientParams = array_merge($clientParams, $scopedClientIds);
        }
    }
    $clientSql .= ' ORDER BY cp.organization_name ASC';
    $clientStmt = db()->prepare($clientSql);
    $clientStmt->execute($clientParams);
    $clients = $clientStmt->fetchAll();
}
$clientIdsInScope = array_map(static fn (array $c): int => (int) $c['id'], $clients);

$staffAndAdminUsers = [];
$staffStmt = db()->prepare("SELECT id, name, role FROM users WHERE role IN ('staff', 'admin') AND status = 'active' AND company_id = :company_id AND id <> :self ORDER BY name ASC");
$staffStmt->execute(['company_id' => $companyId, 'self' => $userId]);
$staffAndAdminUsers = $staffStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_thread') {
        $isInternal = isset($_POST['is_internal']);
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $participantIds = array_map('intval', (array) ($_POST['participant_ids'] ?? []));

        if ($subject === '' || $body === '') {
            flash('error', 'Subject and message are required.');
            redirect('admin/messages.php');
        }

        $uploadResult = [];
        if (isset($_FILES['attachment']) && (int) $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = handle_message_attachment_upload($_FILES['attachment'], 'new');
            if (!$uploadResult['ok']) {
                flash('error', $uploadResult['error']);
                redirect('admin/messages.php');
            }
        }

        if ($isInternal) {
            $threadId = create_message_thread($companyId, null, $subject, $userId, $body, $uploadResult, $participantIds);
        } else {
            if ($clientId <= 0 || !in_array($clientId, $clientIdsInScope, true)) {
                flash('error', 'Select a valid client.');
                redirect('admin/messages.php');
            }
            $threadId = create_message_thread($companyId, $clientId, $subject, $userId, $body, $uploadResult);
        }

        log_activity('message_thread', $threadId, 'created', 'Message thread created.', $userId);
        flash('success', 'Message sent.');
        redirect('admin/messages.php?thread_id=' . $threadId);
    }

    if ($action === 'send_message') {
        $threadId = (int) ($_POST['thread_id'] ?? 0);
        $body = trim((string) ($_POST['body'] ?? ''));

        $threadStmt = db()->prepare('SELECT * FROM message_threads WHERE id = :id AND company_id = :company_id LIMIT 1');
        $threadStmt->execute(['id' => $threadId, 'company_id' => $companyId]);
        $thread = $threadStmt->fetch();
        if (!$thread) {
            flash('error', 'Thread not found.');
            redirect('admin/messages.php');
        }

        $participantStmt = db()->prepare('SELECT id FROM message_thread_participants WHERE thread_id = :thread_id AND user_id = :user_id LIMIT 1');
        $participantStmt->execute(['thread_id' => $threadId, 'user_id' => $userId]);
        $isParticipant = (bool) $participantStmt->fetch();

        if ($role !== 'admin' && !$isParticipant) {
            flash('error', 'You do not have access to that thread.');
            redirect('admin/messages.php');
        }

        if (!$isParticipant) {
            db()->prepare('INSERT IGNORE INTO message_thread_participants (thread_id, user_id, last_read_at) VALUES (:thread_id, :user_id, NOW())')
                ->execute(['thread_id' => $threadId, 'user_id' => $userId]);
        }

        if ($body === '') {
            flash('error', 'Message cannot be empty.');
            redirect('admin/messages.php?thread_id=' . $threadId);
        }

        $uploadResult = [];
        if (isset($_FILES['attachment']) && (int) $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = handle_message_attachment_upload($_FILES['attachment'], (string) $threadId);
            if (!$uploadResult['ok']) {
                flash('error', $uploadResult['error']);
                redirect('admin/messages.php?thread_id=' . $threadId);
            }
        }

        $stmt = db()->prepare('INSERT INTO messages (thread_id, sender_id, body, attachment_path, attachment_name) VALUES (:thread_id, :sender_id, :body, :attachment_path, :attachment_name)');
        $stmt->execute([
            'thread_id' => $threadId,
            'sender_id' => $userId,
            'body' => $body,
            'attachment_path' => $uploadResult['file_path'] ?? null,
            'attachment_name' => $uploadResult['original_file_name'] ?? null,
        ]);
        db()->prepare('UPDATE message_threads SET updated_at = NOW() WHERE id = :id')->execute(['id' => $threadId]);
        db()->prepare('UPDATE message_thread_participants SET last_read_at = NOW() WHERE thread_id = :thread_id AND user_id = :user_id')
            ->execute(['thread_id' => $threadId, 'user_id' => $userId]);

        log_activity('message_thread', $threadId, 'replied', 'Message sent.', $userId);
        redirect('admin/messages.php?thread_id=' . $threadId);
    }

    flash('error', 'Unsupported message action.');
    redirect('admin/messages.php');
}

$threadId = (int) ($_GET['thread_id'] ?? 0);
$thread = null;
$threadMessages = [];
$threads = [];

if ($threadId > 0) {
    $threadStmt = db()->prepare('SELECT mt.*, cp.organization_name FROM message_threads mt
        LEFT JOIN client_profiles cp ON cp.id = mt.client_id
        WHERE mt.id = :id AND mt.company_id = :company_id LIMIT 1');
    $threadStmt->execute(['id' => $threadId, 'company_id' => $companyId]);
    $thread = $threadStmt->fetch();

    if ($thread) {
        $participantCheck = db()->prepare('SELECT id FROM message_thread_participants WHERE thread_id = :thread_id AND user_id = :user_id LIMIT 1');
        $participantCheck->execute(['thread_id' => $threadId, 'user_id' => $userId]);
        $isParticipant = (bool) $participantCheck->fetch();

        if ($role !== 'admin' && !$isParticipant) {
            flash('error', 'You do not have access to that thread.');
            redirect('admin/messages.php');
        }

        if (!$isParticipant) {
            db()->prepare('INSERT IGNORE INTO message_thread_participants (thread_id, user_id, last_read_at) VALUES (:thread_id, :user_id, NOW())')
                ->execute(['thread_id' => $threadId, 'user_id' => $userId]);
        } else {
            db()->prepare('UPDATE message_thread_participants SET last_read_at = NOW() WHERE thread_id = :thread_id AND user_id = :user_id')
                ->execute(['thread_id' => $threadId, 'user_id' => $userId]);
        }

        $msgStmt = db()->prepare('SELECT m.*, u.name AS sender_name FROM messages m INNER JOIN users u ON u.id = m.sender_id WHERE m.thread_id = :thread_id ORDER BY m.created_at ASC');
        $msgStmt->execute(['thread_id' => $threadId]);
        $threadMessages = $msgStmt->fetchAll();
    } else {
        $threadId = 0;
    }
}

if ($threadId === 0) {
    $inboxSql = "SELECT mt.*, cp.organization_name,
            (SELECT body FROM messages WHERE thread_id = mt.id ORDER BY created_at DESC LIMIT 1) AS last_message,
            (SELECT COUNT(*) FROM messages m2 WHERE m2.thread_id = mt.id AND m2.sender_id <> :self1
                AND (mtp.last_read_at IS NULL OR m2.created_at > mtp.last_read_at)) AS unread_count
        FROM message_threads mt
        INNER JOIN message_thread_participants mtp ON mtp.thread_id = mt.id AND mtp.user_id = :self2
        LEFT JOIN client_profiles cp ON cp.id = mt.client_id
        WHERE mt.company_id = :company_id";
    $inboxParams = ['self1' => $userId, 'self2' => $userId, 'company_id' => $companyId];

    if ($role === 'admin') {
        $inboxSql = "SELECT mt.*, cp.organization_name,
                (SELECT body FROM messages WHERE thread_id = mt.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                (SELECT COUNT(*) FROM messages m2 WHERE m2.thread_id = mt.id AND m2.sender_id <> :self1
                    AND (
                        (SELECT last_read_at FROM message_thread_participants WHERE thread_id = mt.id AND user_id = :self2 LIMIT 1) IS NULL
                        OR m2.created_at > (SELECT last_read_at FROM message_thread_participants WHERE thread_id = mt.id AND user_id = :self3 LIMIT 1)
                    )) AS unread_count
            FROM message_threads mt
            LEFT JOIN client_profiles cp ON cp.id = mt.client_id
            WHERE mt.company_id = :company_id";
        $inboxParams = ['self1' => $userId, 'self2' => $userId, 'self3' => $userId, 'company_id' => $companyId];
    }

    $inboxSql .= ' ORDER BY mt.updated_at DESC LIMIT 100';
    $inboxStmt = db()->prepare($inboxSql);
    $inboxStmt->execute($inboxParams);
    $threads = $inboxStmt->fetchAll();
}

$pageTitle = $thread ? $thread['subject'] : 'Messages';

include __DIR__ . '/../../app/views/partials/' . ($role === 'admin' ? 'admin_header' : 'staff_header') . '.php';
?>

<?php if ($threadId === 0): ?>
    <div class="table-card">
        <h2><?= icon('messages') ?>Inbox</h2>

        <details class="feature-disclosure">
            <summary>
                <span>
                    <strong><?= icon('messages') ?>New message</strong>
                    <small>Start a conversation with a client or an internal team member.</small>
                </span>
                <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
            </summary>
            <form method="post" enctype="multipart/form-data" class="workspace-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_thread">
                <label class="checkbox-line"><input type="checkbox" name="is_internal" value="1" id="msg_is_internal"> Internal thread (no client)</label>
                <label>Client
                    <select name="client_id">
                        <option value="">Select client</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= e((int) $client['id']) ?>"><?= e($client['organization_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Recipients (internal only)
                    <select name="participant_ids[]" multiple size="5">
                        <?php foreach ($staffAndAdminUsers as $staffUser): ?>
                            <option value="<?= e((int) $staffUser['id']) ?>"><?= e($staffUser['name']) ?> (<?= e($staffUser['role']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Subject<input type="text" name="subject" maxlength="190" required></label>
                <label class="workspace-span-2">Message<textarea name="body" required></textarea></label>
                <label class="workspace-span-2">Attachment (optional)<input type="file" name="attachment"></label>
                <div class="workspace-span-2">
                    <button type="submit"><?= icon('messages') ?>Send</button>
                </div>
            </form>
        </details>

        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Client</th>
                        <th>Last message</th>
                        <th>Updated</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($threads === []): ?>
                        <tr><td colspan="5">No message threads yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($threads as $t): ?>
                        <tr>
                            <td>
                                <a href="<?= e(url('admin/messages.php?thread_id=' . (int) $t['id'])) ?>"><?= e($t['subject']) ?></a>
                                <?php if ((int) ($t['unread_count'] ?? 0) > 0): ?>
                                    <span class="tag"><?= e((string) $t['unread_count']) ?> new</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($t['organization_name'] ?? 'Internal') ?></td>
                            <td><small><?= e(mb_strimwidth((string) ($t['last_message'] ?? ''), 0, 80, '...')) ?></small></td>
                            <td><?= e($t['updated_at']) ?></td>
                            <td><a class="button secondary" href="<?= e(url('admin/messages.php?thread_id=' . (int) $t['id'])) ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <div class="table-card">
        <h2><?= icon('messages') ?><?= e($thread['subject']) ?></h2>
        <p><a href="<?= e(url('admin/messages.php')) ?>">&larr; Back to inbox</a> | Client: <?= e($thread['organization_name'] ?? 'Internal') ?></p>

        <div class="table-card">
            <?php foreach ($threadMessages as $message): ?>
                <div style="padding:12px 0; border-bottom:1px solid var(--border);">
                    <strong><?= e($message['sender_name']) ?></strong> <small><?= e($message['created_at']) ?></small>
                    <p><?= nl2br(e($message['body'])) ?></p>
                    <?php if ($message['attachment_path']): ?>
                        <p><a href="<?= e(url('attachment-download.php?type=message&id=' . (int) $message['id'])) ?>">📎 <?= e((string) $message['attachment_name']) ?></a></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="post" enctype="multipart/form-data" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="send_message">
            <input type="hidden" name="thread_id" value="<?= e((int) $thread['id']) ?>">
            <label class="workspace-span-2">Reply<textarea name="body" required></textarea></label>
            <label class="workspace-span-2">Attachment (optional)<input type="file" name="attachment"></label>
            <div class="workspace-span-2">
                <button type="submit"><?= icon('messages') ?>Send reply</button>
            </div>
        </form>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/' . ($role === 'admin' ? 'admin_footer' : 'staff_footer') . '.php'; ?>
