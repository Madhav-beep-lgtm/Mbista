<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();
require_company_context();

// Website content is published from the M.Bista superadmin portal only.
$company = current_company();
if ((string) ($company['code'] ?? '') !== 'MBAACA') {
    flash('error', 'Website insights are published from the M.Bista superadmin portal only.');
    redirect('admin/index.php');
}

// Self-repair: the page works before migration 032 has been run manually.
db()->exec("CREATE TABLE IF NOT EXISTS `insight_posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(40) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `summary` VARCHAR(500) DEFAULT NULL,
  `body` MEDIUMTEXT,
  `attachment_path` VARCHAR(255) DEFAULT NULL,
  `attachment_name` VARCHAR(200) DEFAULT NULL,
  `status` ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
  `published_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_insight_posts_list` (`category`, `status`, `published_at`),
  CONSTRAINT `fk_insight_posts_author` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$categories = insight_categories();

$findPost = static function (int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM insight_posts WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $postId = (int) ($_POST['post_id'] ?? 0);

    if ($action === 'save_post') {
        $category = (string) ($_POST['category'] ?? '');
        $title = trim((string) ($_POST['title'] ?? ''));
        $summary = trim((string) ($_POST['summary'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if (!isset($categories[$category]) || $title === '') {
            flash('error', 'A category and a title are required.');
            redirect('admin/insights.php');
        }

        // Optional attachment (mainly for Publications / Downloads).
        $attachmentPath = null;
        $attachmentName = null;
        $upload = $_FILES['attachment'] ?? null;
        if ($upload && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg', 'webp', 'txt', 'zip'];
            $extension = strtolower((string) pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
            $size = (int) ($upload['size'] ?? 0);
            if ((int) $upload['error'] !== UPLOAD_ERR_OK || $size <= 0 || $size > 10 * 1024 * 1024 || !in_array($extension, $allowedExtensions, true)) {
                flash('error', 'Attachment rejected: allowed types ' . implode(', ', $allowedExtensions) . ', up to 10 MB.');
                redirect('admin/insights.php' . ($postId > 0 ? '?edit=' . $postId : ''));
            }
            $uploadDir = __DIR__ . '/../assets/uploads/insights';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                flash('error', 'Could not create the insights upload directory.');
                redirect('admin/insights.php');
            }
            $storedName = 'insight-' . date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $extension;
            if (!move_uploaded_file((string) $upload['tmp_name'], $uploadDir . '/' . $storedName)) {
                flash('error', 'Could not store the attachment.');
                redirect('admin/insights.php');
            }
            $attachmentPath = 'assets/uploads/insights/' . $storedName;
            $attachmentName = (string) $upload['name'];
        }

        if ($postId > 0) {
            $existing = $findPost($postId);
            if (!$existing) {
                flash('error', 'Post not found.');
                redirect('admin/insights.php');
            }
            db()->prepare('UPDATE insight_posts SET category = :category, title = :title, summary = :summary, body = :body'
                . ($attachmentPath !== null ? ', attachment_path = :ap, attachment_name = :an' : '')
                . ' WHERE id = :id')
                ->execute(array_merge(
                    ['category' => $category, 'title' => $title, 'summary' => $summary ?: null, 'body' => $body ?: null, 'id' => $postId],
                    $attachmentPath !== null ? ['ap' => $attachmentPath, 'an' => $attachmentName] : []
                ));
            if ($attachmentPath !== null && !empty($existing['attachment_path'])) {
                @unlink(__DIR__ . '/../' . $existing['attachment_path']);
            }
            log_activity('insight_post', $postId, 'updated', 'Insight post updated: ' . $title, $userId);
            flash('success', 'Post updated.');
        } else {
            db()->prepare('INSERT INTO insight_posts (category, title, summary, body, attachment_path, attachment_name, status, created_by)
                    VALUES (:category, :title, :summary, :body, :ap, :an, \'draft\', :by)')
                ->execute(['category' => $category, 'title' => $title, 'summary' => $summary ?: null, 'body' => $body ?: null,
                    'ap' => $attachmentPath, 'an' => $attachmentName, 'by' => $userId ?: null]);
            $postId = (int) db()->lastInsertId();
            log_activity('insight_post', $postId, 'created', 'Insight post drafted: ' . $title, $userId);
            flash('success', 'Draft saved. Publish it when it is ready to go live.');
        }
        redirect('admin/insights.php');
    }

    if (in_array($action, ['publish_post', 'unpublish_post', 'delete_post'], true)) {
        $post = $findPost($postId);
        if (!$post) {
            flash('error', 'Post not found.');
            redirect('admin/insights.php');
        }
        if ($action === 'publish_post') {
            db()->prepare("UPDATE insight_posts SET status = 'published', published_at = COALESCE(published_at, NOW()) WHERE id = :id")
                ->execute(['id' => $postId]);
            log_activity('insight_post', $postId, 'published', 'Insight post published: ' . $post['title'], $userId);
            flash('success', 'Published — the post is now live on the public website.');
        } elseif ($action === 'unpublish_post') {
            db()->prepare("UPDATE insight_posts SET status = 'draft' WHERE id = :id")->execute(['id' => $postId]);
            log_activity('insight_post', $postId, 'unpublished', 'Insight post unpublished: ' . $post['title'], $userId);
            flash('success', 'Post moved back to draft and removed from the website.');
        } else {
            db()->prepare('DELETE FROM insight_posts WHERE id = :id')->execute(['id' => $postId]);
            if (!empty($post['attachment_path'])) {
                @unlink(__DIR__ . '/../' . $post['attachment_path']);
            }
            log_activity('insight_post', $postId, 'deleted', 'Insight post deleted: ' . $post['title'], $userId);
            flash('success', 'Post deleted.');
        }
        redirect('admin/insights.php');
    }

    flash('error', 'Unknown action.');
    redirect('admin/insights.php');
}

$editPost = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editPost = $findPost($editId);
}

$filterCategory = (string) ($_GET['category'] ?? '');
$filterStatus = (string) ($_GET['status'] ?? '');
$listSql = 'SELECT p.*, u.name AS author_name FROM insight_posts p LEFT JOIN users u ON u.id = p.created_by WHERE 1=1';
$listParams = [];
if (isset($categories[$filterCategory])) {
    $listSql .= ' AND p.category = :category';
    $listParams['category'] = $filterCategory;
}
if (in_array($filterStatus, ['draft', 'published', 'archived'], true)) {
    $listSql .= ' AND p.status = :status';
    $listParams['status'] = $filterStatus;
}
$listSql .= ' ORDER BY p.updated_at DESC, p.id DESC LIMIT 200';
$listStmt = db()->prepare($listSql);
$listStmt->execute($listParams);
$posts = $listStmt->fetchAll();

$countRow = db()->query("SELECT COUNT(*) AS total,
        SUM(status = 'published') AS published,
        SUM(status = 'draft') AS drafts,
        SUM(attachment_path IS NOT NULL) AS with_files
    FROM insight_posts")->fetch() ?: [];

$pageTitle = 'Website Insights';
$pageSubtitle = 'Write, publish, and retire the articles and updates shown on the public Insights pages.';
$pageHero = ['icon' => 'documents'];
$bodyClass = 'admin-layout insights-admin-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>

<section class="mbw-kpi-grid" aria-label="Insights status">
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Total posts</span><div class="mbw-kpi-value"><?= e((string) (int) ($countRow['total'] ?? 0)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">All categories</span></span></div><span class="mbw-chip tone-blue"><?= icon('documents') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Published</span><div class="mbw-kpi-value" style="color:var(--mbw-green)"><?= e((string) (int) ($countRow['published'] ?? 0)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Live on the website</span></span></div><span class="mbw-chip tone-green"><?= icon('reports') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Drafts</span><div class="mbw-kpi-value" style="color:var(--mbw-amber)"><?= e((string) (int) ($countRow['drafts'] ?? 0)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Not yet visible</span></span></div><span class="mbw-chip tone-amber"><?= icon('journal') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">With attachments</span><div class="mbw-kpi-value"><?= e((string) (int) ($countRow['with_files'] ?? 0)) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Publications &amp; downloads</span></span></div><span class="mbw-chip tone-purple"><?= icon('upload') ?></span></article>
</section>

<section class="mbw-card" aria-label="<?= $editPost ? 'Edit post' : 'New post' ?>">
    <div class="mbw-card-head">
        <h2><?= $editPost ? 'Edit Post' : 'New Post' ?></h2>
        <?php if ($editPost): ?>
            <div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('admin/insights.php')) ?>">Cancel editing &#8594;</a></div>
        <?php endif; ?>
    </div>
    <form method="post" class="workspace-form-grid" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_post">
        <input type="hidden" name="post_id" value="<?= e((int) ($editPost['id'] ?? 0)) ?>">
        <label>Category
            <select name="category" required>
                <?php foreach ($categories as $categoryKey => $categoryLabel): ?>
                    <option value="<?= e($categoryKey) ?>" <?= ($editPost['category'] ?? '') === $categoryKey ? 'selected' : '' ?>><?= e($categoryLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Title<input type="text" name="title" maxlength="200" required value="<?= e($editPost['title'] ?? '') ?>" placeholder="e.g. FY 2083-84 income tax slab changes"></label>
        <label class="workspace-span-2">Summary (shown in lists)<input type="text" name="summary" maxlength="500" value="<?= e($editPost['summary'] ?? '') ?>" placeholder="One or two sentences shown on the listing page"></label>
        <label class="workspace-span-2">Body<textarea name="body" rows="8" placeholder="Full text of the post. Blank lines start a new paragraph."><?= e($editPost['body'] ?? '') ?></textarea></label>
        <label>Attachment (optional)
            <input type="file" name="attachment" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.zip">
        </label>
        <?php if (!empty($editPost['attachment_path'])): ?>
            <div style="align-self:end"><span class="mbw-pill tone-blue" title="Uploading a new file replaces this one"><?= icon('upload') ?><?= e($editPost['attachment_name'] ?? 'Attachment') ?></span></div>
        <?php endif; ?>
        <div class="workspace-span-2"><button type="submit"><?= icon('documents') ?><?= $editPost ? 'Update Post' : 'Save Draft' ?></button></div>
    </form>
</section>

<section class="mbw-card" aria-label="All posts">
    <div class="mbw-card-head">
        <h2>Posts</h2>
        <div class="mbw-card-tools">
            <form method="get" action="<?= e(url('admin/insights.php')) ?>" style="display:inline-flex;gap:8px;margin:0">
                <select name="category" onchange="this.form.submit()">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $categoryKey => $categoryLabel): ?>
                        <option value="<?= e($categoryKey) ?>" <?= $filterCategory === $categoryKey ? 'selected' : '' ?>><?= e($categoryLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <?php foreach (['draft' => 'Draft', 'published' => 'Published'] as $statusKey => $statusLabel): ?>
                        <option value="<?= e($statusKey) ?>" <?= $filterStatus === $statusKey ? 'selected' : '' ?>><?= e($statusLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Title</th><th>Category</th><th>Status</th><th>Published</th><th>Author</th><th>File</th><th></th></tr></thead>
        <tbody>
            <?php if ($posts === []): ?><tr><td colspan="7">No posts yet — write the first one above.</td></tr><?php endif; ?>
            <?php foreach ($posts as $post): ?>
                <tr>
                    <td style="max-width:340px"><strong><?= e($post['title']) ?></strong><?= $post['summary'] ? '<br><small style="color:var(--mbw-muted)">' . e(mb_strimwidth((string) $post['summary'], 0, 90, '…')) . '</small>' : '' ?></td>
                    <td><?= e($categories[$post['category']] ?? $post['category']) ?></td>
                    <td><span class="mbw-pill <?= $post['status'] === 'published' ? 'tone-green' : ($post['status'] === 'draft' ? 'tone-amber' : 'tone-gray') ?>"><?= e(ucfirst((string) $post['status'])) ?></span></td>
                    <td><?= $post['published_at'] ? e(date('d M Y', strtotime((string) $post['published_at']))) : '—' ?></td>
                    <td><?= e($post['author_name'] ?? '—') ?></td>
                    <td><?= $post['attachment_path'] ? '<a href="' . e(url((string) $post['attachment_path'])) . '" target="_blank">' . icon('upload') . '</a>' : '—' ?></td>
                    <td style="white-space:nowrap">
                        <a class="button secondary" href="<?= e(url('admin/insights.php?edit=' . (int) $post['id'])) ?>">Edit</a>
                        <?php if ($post['status'] === 'published'): ?>
                            <a class="button secondary" target="_blank" href="<?= e(url('insights/' . $post['category'] . '.php')) ?>" title="View on the website">View</a>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="unpublish_post">
                                <input type="hidden" name="post_id" value="<?= e((int) $post['id']) ?>">
                                <button type="submit" class="secondary">Unpublish</button>
                            </form>
                        <?php else: ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="publish_post">
                                <input type="hidden" name="post_id" value="<?= e((int) $post['id']) ?>">
                                <button type="submit">Publish</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline" data-confirm="Delete this post permanently?">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_post">
                            <input type="hidden" name="post_id" value="<?= e((int) $post['id']) ?>">
                            <button type="submit" class="secondary">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
