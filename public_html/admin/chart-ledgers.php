<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_or_admin();
require_company_context();

$pageTitle = 'Chart Ledgers';
$pageSubtitle = 'Manage ledger accounts, edit rows, and remove unused ledgers.';
$bodyClass = 'admin-layout accounting-module-page chart-accounts-page';
$company = current_company();
$companyId = (int) ($company['id'] ?? 0);
$masters = ledger_masters();
$groupsStmt = db()->prepare('SELECT * FROM ledger_groups WHERE company_id = :company_id AND is_active = 1 ORDER BY master_key ASC, name ASC');
$groupsStmt->execute(['company_id' => $companyId]);
$groups = $groupsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_ledger') {
        $ledgerId = (int) ($_POST['ledger_id'] ?? 0);
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $groupStmt = db()->prepare('SELECT id, master_key FROM ledger_groups WHERE id = :id AND company_id = :company_id AND is_active = 1 LIMIT 1');
        $groupStmt->execute(['id' => $groupId, 'company_id' => $companyId]);
        $group = $groupStmt->fetch();
        $ledgerType = $group ? ledger_master_nature((string) $group['master_key']) : null;
        if ($code === '' || $name === '' || !$group || $ledgerType === null) {
            flash('error', 'Code, name, and group are required.');
            redirect('admin/chart-ledgers.php');
        }
        if ($ledgerId > 0) {
            db()->prepare("UPDATE ledgers SET code = :code, name = :name, group_id = :group_id, type = :type WHERE id = :id AND company_id = :company_id")
                ->execute(['id' => $ledgerId, 'company_id' => $companyId, 'code' => $code, 'name' => $name, 'group_id' => $groupId, 'type' => $ledgerType]);
            flash('success', 'Ledger updated.');
        } else {
            db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, is_system, status) VALUES (:company_id, :group_id, :code, :name, :type, 0, 'active')")
                ->execute(['company_id' => $companyId, 'group_id' => $groupId, 'code' => $code, 'name' => $name, 'type' => $ledgerType]);
            flash('success', 'Ledger created.');
        }
        redirect('admin/chart-ledgers.php');
    }
    if ($action === 'delete_ledger') {
        $ledgerId = (int) ($_POST['ledger_id'] ?? 0);
        if ($ledgerId > 0) {
            try {
                db()->prepare('DELETE FROM ledgers WHERE id = :id AND company_id = :company_id')->execute(['id' => $ledgerId, 'company_id' => $companyId]);
                flash('success', 'Ledger deleted.');
            } catch (Throwable $e) {
                flash('error', 'This ledger cannot be deleted while transactions still reference it.');
            }
        }
        redirect('admin/chart-ledgers.php');
    }
}

$ledgerStmt = db()->prepare('SELECT l.*, g.name AS group_name, g.master_key FROM ledgers l LEFT JOIN ledger_groups g ON g.id = l.group_id WHERE l.company_id = :company_id ORDER BY l.code ASC');
$ledgerStmt->execute(['company_id' => $companyId]);
$ledgers = $ledgerStmt->fetchAll();
$editId = (int) ($_GET['edit_id'] ?? 0);
$editLedger = null;
if ($editId > 0) {
    $editStmt = db()->prepare('SELECT * FROM ledgers WHERE id = :id AND company_id = :company_id LIMIT 1');
    $editStmt->execute(['id' => $editId, 'company_id' => $companyId]);
    $editLedger = $editStmt->fetch() ?: null;
}

include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card">
    <div class="mbw-card-head">
        <h2><?= $editLedger ? 'Edit Ledger' : 'Create Ledger' ?></h2>
        <div class="mbw-card-tools">
            <a class="mbw-view-all" href="<?= e(url('admin/chart-of-accounts.php')) ?>">Overview</a>
            <a class="mbw-view-all" href="<?= e(url('admin/chart-groups.php')) ?>">Groups</a>
        </div>
    </div>
    <p style="margin:0 0 12px;color:var(--mbw-muted)">One place for the ledger record and its actions.</p>
    <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_ledger">
            <input type="hidden" name="ledger_id" value="<?= e((int) ($editLedger['id'] ?? 0)) ?>">
            <label>Code<input type="text" name="code" value="<?= e($editLedger['code'] ?? '') ?>" required></label>
            <label>Name<input type="text" name="name" value="<?= e($editLedger['name'] ?? '') ?>" required></label>
            <label>Group
                <select name="group_id" required>
                    <option value="">Select group</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= e((int) $group['id']) ?>" <?= (int) ($editLedger['group_id'] ?? 0) === (int) $group['id'] ? 'selected' : '' ?>><?= e($masters[$group['master_key']]['label'] ?? $group['master_key']) ?> / <?= e($group['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="workspace-span-2">
                <button type="submit"><?= icon('documents') ?>Save ledger</button>
                <?php if ($editLedger): ?><a class="button secondary" href="<?= e(url('admin/chart-ledgers.php')) ?>">Cancel</a><?php endif; ?>
            </div>
    </form>
</section>

<section class="mbw-card">
    <div class="mbw-card-head">
        <h2>Ledger table</h2>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Code</th><th>Name</th><th>Group</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if ($ledgers === []): ?><tr><td colspan="6">No ledgers yet.</td></tr><?php endif; ?>
            <?php foreach ($ledgers as $ledger): ?>
                <tr>
                    <td><?= e($ledger['code']) ?></td>
                    <td><?= e($ledger['name']) ?></td>
                    <td><?= e($ledger['group_name'] ?? '-') ?></td>
                    <td><?= e($ledger['type']) ?></td>
                    <td><span class="mbw-pill <?= ($ledger['status'] ?? '') === 'active' ? 'tone-green' : 'tone-red' ?>"><?= e($ledger['status']) ?></span></td>
                    <td>
                        <a class="button secondary" href="<?= e(url('admin/chart-ledgers.php?edit_id=' . (int) $ledger['id'])) ?>">Edit</a>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_ledger">
                            <input type="hidden" name="ledger_id" value="<?= e((int) $ledger['id']) ?>">
                            <button type="submit" class="button secondary" onclick="return confirm('Delete this ledger?')">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
