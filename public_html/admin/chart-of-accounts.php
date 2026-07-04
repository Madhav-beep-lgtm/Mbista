<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_or_admin();
require_company_context();

$pageTitle = 'Chart of Accounts';
$currentCompany = current_company();
$currentAdmin = current_user();
$companyId = (int) ($currentCompany['id'] ?? 0);
$adminId = (int) ($currentAdmin['id'] ?? 0);

$masters = ledger_masters();
$allowedMasterKeys = array_keys($masters);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_group') {
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $masterKey = (string) ($_POST['master_key'] ?? '');
        $isCashOrBank = isset($_POST['is_cash_or_bank']) ? 1 : 0;

        if ($code === '' || $name === '' || !in_array($masterKey, $allowedMasterKeys, true)) {
            flash('error', 'Group code, name, and a valid master are required.');
            redirect('admin/chart-of-accounts.php');
        }

        try {
            $stmt = db()->prepare('INSERT INTO ledger_groups (company_id, code, name, master_key, is_cash_or_bank, is_system) VALUES (:company_id, :code, :name, :master_key, :is_cash_or_bank, 0)');
            $stmt->execute(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'master_key' => $masterKey, 'is_cash_or_bank' => $isCashOrBank]);
            log_activity('ledger_group', (int) db()->lastInsertId(), 'created', 'Ledger group created.', $adminId);
            flash('success', 'Group created.');
        } catch (Throwable $exception) {
            flash('error', 'Could not create group. The code may already exist.');
        }
        redirect('admin/chart-of-accounts.php');
    }
}

$chartOfAccounts = get_chart_of_accounts_tree((int) $currentCompany['id']);

/**
 * Recursive function to render the account tree.
 *
 * @param array $nodes The current nodes (groups) to render.
 * @param int $level The current depth for indentation.
 */
function render_account_tree(array $nodes, int $level = 0): void
{
    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);

    foreach ($nodes as $group) {
        $hasChildren = !empty($group['children']) || !empty($group['ledgers']);
        $isRoot = $level === 0;

        echo '<div class="tree-node">';
        if ($hasChildren) {
            echo '<details ' . ($isRoot ? 'open' : '') . '>';
            echo '<summary class="tree-group">';
            echo $indent . e($group['name']) . ' <span class="muted">(' . e($group['code']) . ')</span>';
            echo '</summary>';
            echo '<div class="tree-content">';

            // Render child groups recursively
            if (!empty($group['children'])) {
                render_account_tree($group['children'], $level + 1);
            }

            // Render ledgers within this group
            if (!empty($group['ledgers'])) {
                foreach ($group['ledgers'] as $ledger) {
                    echo '<div class="tree-ledger" style="padding-left: ' . (($level + 1) * 20) . 'px;">';
                    echo e($ledger['name']) . ' <span class="muted">(' . e($ledger['code']) . ')</span>';
                    echo '</div>';
                }
            }

            echo '</div>'; // .tree-content
            echo '</details>';
        } else {
            // Group with no children or ledgers
            echo '<div class="tree-group" style="padding-left: ' . ($level * 20) . 'px;">';
            echo $indent . e($group['name']) . ' <span class="muted">(' . e($group['code']) . ')</span>';
            echo '</div>';
        }
        echo '</div>'; // .tree-node
    }
}

include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<style>
    .tree-node { border-left: 2px solid #e5e7eb; margin-bottom: 4px; }
    .tree-node details { margin-bottom: 0; }
    .tree-group { cursor: pointer; padding: 8px 12px; font-weight: 600; background-color: #f9fafb; }
    .tree-group:hover { background-color: #f3f4f6; }
    .tree-group .muted { font-weight: 400; color: #6b7280; font-size: 0.9em; }
    .tree-content { padding-left: 20px; }
    .tree-ledger { padding: 8px 12px; border-top: 1px solid #f3f4f6; }
    .tree-ledger .muted { color: #6b7280; font-size: 0.9em; }
    summary { display: list-item; } /* Fix for some browsers */
</style>

<div class="page-header">
    <h1><?= e($pageTitle) ?></h1>
</div>

<div class="workspace-feature-stack">
    <details class="feature-disclosure">
        <summary>
            <span>
                <strong><?= icon('accounting') ?>Create New Group</strong>
                <small>Add a new group under one of the main accounting masters.</small>
            </span>
            <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
        </summary>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_group">
            <label>Group Code<input type="text" name="code" maxlength="40" placeholder="e.g., ADMIN_EXP" required></label>
            <label>Group Name<input type="text" name="name" maxlength="150" placeholder="e.g., Administrative Expenses" required></label>
            <label>Master Category
                <select name="master_key" required>
                    <?php foreach ($masters as $key => $master): ?>
                        <option value="<?= e($key) ?>"><?= e($master['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="checkbox-line"><input type="checkbox" name="is_cash_or_bank" value="1"> Holds Cash/Bank Ledgers</label>
            <button type="submit"><?= icon('accounting') ?>Create Group</button>
        </form>
    </details>
    <div class="actions" style="margin-top: 1rem;">
        <a href="#" class="button">New Ledger (coming soon)</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><?= e($currentCompany['name']) ?></h3>
    </div>
    <div class="card-body">
        <?php if (empty($chartOfAccounts)): ?>
            <p>No chart of accounts has been configured for this company.</p>
        <?php else: ?>
            <div class="account-tree">
                <?php render_account_tree($chartOfAccounts); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
include __DIR__ . '/../../app/views/partials/admin_footer.php';
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Optional: Add JS for more advanced features later, like search or drag-and-drop.
});
</script>