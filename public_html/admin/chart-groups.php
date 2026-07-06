<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_or_admin();
require_company_context();

$pageTitle = 'Chart Groups';
$pageSubtitle = 'Manage account groups, hierarchy, and cash/bank flags for the chart of accounts.';
$bodyClass = 'admin-layout accounting-module-page chart-accounts-page';
$company = current_company();
$companyId = (int) ($company['id'] ?? 0);
$masters = ledger_masters();
$supportsGroupHierarchy = column_exists('ledger_groups', 'parent_group_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_group') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $masterKey = (string) ($_POST['master_key'] ?? '');
        $parentGroupId = $supportsGroupHierarchy ? (int) ($_POST['parent_group_id'] ?? 0) : 0;
        $isCashOrBank = isset($_POST['is_cash_or_bank']) ? 1 : 0;
        if ($parentGroupId > 0) {
            $parentStmt = db()->prepare('SELECT id, master_key FROM ledger_groups WHERE id = :id AND company_id = :company_id LIMIT 1');
            $parentStmt->execute(['id' => $parentGroupId, 'company_id' => $companyId]);
            $parentGroup = $parentStmt->fetch();
            if ($parentGroup) {
                $masterKey = (string) $parentGroup['master_key'];
            }
        }
        if ($code === '' || $name === '' || !array_key_exists($masterKey, $masters)) {
            flash('error', 'Code, name, and master are required.');
            redirect('admin/chart-groups.php');
        }
        if ($groupId > 0) {
            $sql = 'UPDATE ledger_groups SET code = :code, name = :name, master_key = :master_key, is_cash_or_bank = :is_cash_or_bank' . ($supportsGroupHierarchy ? ', parent_group_id = :parent_group_id' : '') . ' WHERE id = :id AND company_id = :company_id';
            $params = ['id' => $groupId, 'company_id' => $companyId, 'code' => $code, 'name' => $name, 'master_key' => $masterKey, 'is_cash_or_bank' => $isCashOrBank];
            if ($supportsGroupHierarchy) {
                $params['parent_group_id'] = $parentGroupId > 0 ? $parentGroupId : null;
            }
            db()->prepare($sql)->execute($params);
            flash('success', 'Group updated.');
        } else {
            $sql = 'INSERT INTO ledger_groups (company_id, code, name, master_key, is_cash_or_bank, is_system' . ($supportsGroupHierarchy ? ', parent_group_id' : '') . ') VALUES (:company_id, :code, :name, :master_key, :is_cash_or_bank, 0' . ($supportsGroupHierarchy ? ', :parent_group_id' : '') . ')';
            $params = ['company_id' => $companyId, 'code' => $code, 'name' => $name, 'master_key' => $masterKey, 'is_cash_or_bank' => $isCashOrBank];
            if ($supportsGroupHierarchy) {
                $params['parent_group_id'] = $parentGroupId > 0 ? $parentGroupId : null;
            }
            db()->prepare($sql)->execute($params);
            flash('success', 'Group created.');
        }
        redirect('admin/chart-groups.php');
    }
    if ($action === 'delete_group') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        if ($groupId > 0) {
            try {
                db()->prepare('DELETE FROM ledger_groups WHERE id = :id AND company_id = :company_id')->execute(['id' => $groupId, 'company_id' => $companyId]);
                flash('success', 'Group deleted.');
            } catch (Throwable $e) {
                flash('error', 'This group cannot be deleted while ledgers or child groups still use it.');
            }
        }
        redirect('admin/chart-groups.php');
    }
}

$groupsStmt = db()->prepare('SELECT g.*, COUNT(l.id) AS ledger_count FROM ledger_groups g LEFT JOIN ledgers l ON l.group_id = g.id WHERE g.company_id = :company_id GROUP BY g.id ORDER BY g.master_key ASC, g.name ASC');
$groupsStmt->execute(['company_id' => $companyId]);
$groups = $groupsStmt->fetchAll();
$editId = (int) ($_GET['edit_id'] ?? 0);
$editGroup = null;
if ($editId > 0) {
    $editStmt = db()->prepare('SELECT * FROM ledger_groups WHERE id = :id AND company_id = :company_id LIMIT 1');
    $editStmt->execute(['id' => $editId, 'company_id' => $companyId]);
    $editGroup = $editStmt->fetch() ?: null;
}

$bodyClass = 'admin-layout accounting-module-page chart-accounts-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card">
    <div class="mbw-card-head">
        <h2><?= $editGroup ? 'Edit Group' : 'Create Group' ?></h2>
        <div class="mbw-card-tools">
            <a class="mbw-view-all" href="<?= e(url('admin/chart-of-accounts.php')) ?>">Overview</a>
            <a class="mbw-view-all" href="<?= e(url('admin/chart-ledgers.php')) ?>">Ledgers</a>
        </div>
    </div>
    <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_group">
            <input type="hidden" name="group_id" value="<?= e((int) ($editGroup['id'] ?? 0)) ?>">
            <label>Code<input type="text" name="code" value="<?= e($editGroup['code'] ?? '') ?>" required></label>
            <label>Name<input type="text" name="name" value="<?= e($editGroup['name'] ?? '') ?>" required></label>
            <label>Master
                <select name="master_key" required>
                    <?php foreach ($masters as $key => $master): ?>
                        <option value="<?= e($key) ?>" <?= ($editGroup['master_key'] ?? '') === $key ? 'selected' : '' ?>><?= e($master['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($supportsGroupHierarchy): ?>
                <label>Parent Group
                    <select name="parent_group_id">
                        <option value="0">None</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?= e((int) $group['id']) ?>" <?= (int) ($editGroup['parent_group_id'] ?? 0) === (int) $group['id'] ? 'selected' : '' ?>><?= e($group['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label class="checkbox-line"><input type="checkbox" name="is_cash_or_bank" value="1" <?= (int) ($editGroup['is_cash_or_bank'] ?? 0) === 1 ? 'checked' : '' ?>> Cash / bank group</label>
            <div class="workspace-span-2">
                <button type="submit"><?= icon('accounting') ?>Save group</button>
                <?php if ($editGroup): ?><a class="button secondary" href="<?= e(url('admin/chart-groups.php')) ?>">Cancel</a><?php endif; ?>
            </div>
    </form>
</section>

<section class="mbw-card">
    <div class="mbw-card-head">
        <h2>Group Table</h2>
        <div class="mbw-card-tools"><span style="color:var(--mbw-muted);font-size:.85rem"><?= count($groups) ?> groups</span></div>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Code</th><th>Name</th><th>Master</th><th>Parent</th><th class="is-numeric">Ledgers</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if ($groups === []): ?><tr><td colspan="6">No groups yet.</td></tr><?php endif; ?>
            <?php foreach ($groups as $group): ?>
                <tr>
                    <td><?= e($group['code']) ?></td>
                    <td><?= e($group['name']) ?></td>
                    <td><?= e($masters[$group['master_key']]['label'] ?? $group['master_key']) ?></td>
                    <td><?= e($group['parent_group_id'] ? (string) $group['parent_group_id'] : '-') ?></td>
                    <td class="is-numeric"><?= e((string) $group['ledger_count']) ?></td>
                    <td>
                        <a class="button secondary" href="<?= e(url('admin/chart-groups.php?edit_id=' . (int) $group['id'])) ?>">Edit</a>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_group">
                            <input type="hidden" name="group_id" value="<?= e((int) $group['id']) ?>">
                            <button type="submit" class="button secondary" onclick="return confirm('Delete this group?')">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
