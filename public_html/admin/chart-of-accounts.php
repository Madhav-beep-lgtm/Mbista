<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_or_admin();
require_company_context();

$pageTitle = 'Chart of Accounts';
$bodyClass = 'admin-layout accounting-module-page chart-accounts-page';
$currentCompany = current_company();
$currentAdmin = current_user();
$companyId = (int) ($currentCompany['id'] ?? 0);
$adminId = (int) ($currentAdmin['id'] ?? 0);

$masters = ledger_masters();
$allowedMasterKeys = array_keys($masters);
$supportsGroupHierarchy = column_exists('ledger_groups', 'parent_group_id');
$mappingRoles = [
    'default_cash_bank' => ['label' => 'Cash / bank account', 'nature' => 'asset', 'cash_only' => true],
    'default_accounts_receivable' => ['label' => 'Accounts receivable', 'nature' => 'asset'],
    'default_accounts_payable' => ['label' => 'Accounts payable', 'nature' => 'liability'],
    'default_tax_payable' => ['label' => 'Tax payable', 'nature' => 'liability'],
    'default_service_revenue' => ['label' => 'Service revenue', 'nature' => 'revenue'],
    'default_hosting_revenue' => ['label' => 'Hosting revenue', 'nature' => 'revenue'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_group') {
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $masterKey = (string) ($_POST['master_key'] ?? '');
        $parentGroupId = $supportsGroupHierarchy ? (int) ($_POST['parent_group_id'] ?? 0) : 0;
        $isCashOrBank = isset($_POST['is_cash_or_bank']) ? 1 : 0;

        if ($parentGroupId > 0) {
            $parentStmt = db()->prepare('SELECT id, master_key FROM ledger_groups WHERE id = :id AND company_id = :company_id LIMIT 1');
            $parentStmt->execute(['id' => $parentGroupId, 'company_id' => $companyId]);
            $parentGroup = $parentStmt->fetch();
            if (!$parentGroup) {
                flash('error', 'Select a valid parent group from this company.');
                redirect('admin/chart-of-accounts.php');
            }
            $masterKey = (string) $parentGroup['master_key'];
        }

        if ($code === '' || $name === '' || !in_array($masterKey, $allowedMasterKeys, true)) {
            flash('error', 'Group code, name, and a valid master are required.');
            redirect('admin/chart-of-accounts.php');
        }

        try {
            $parentColumn = $supportsGroupHierarchy ? ', parent_group_id' : '';
            $parentValue = $supportsGroupHierarchy ? ', :parent_group_id' : '';
            $params = ['company_id' => $companyId, 'code' => $code, 'name' => $name, 'master_key' => $masterKey, 'is_cash_or_bank' => $isCashOrBank];
            if ($supportsGroupHierarchy) {
                $params['parent_group_id'] = $parentGroupId > 0 ? $parentGroupId : null;
            }
            $stmt = db()->prepare('INSERT INTO ledger_groups (company_id, code, name, master_key, is_cash_or_bank, is_system' . $parentColumn . ') VALUES (:company_id, :code, :name, :master_key, :is_cash_or_bank, 0' . $parentValue . ')');
            $stmt->execute($params);
            log_activity('ledger_group', (int) db()->lastInsertId(), 'created', 'Ledger group created.', $adminId);
            flash('success', 'Group created.');
        } catch (Throwable $exception) {
            flash('error', 'Could not create group. The code may already exist.');
        }
        redirect('admin/chart-of-accounts.php');
    }

    if ($action === 'create_ledger') {
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $groupId = (int) ($_POST['group_id'] ?? 0);

        $groupStmt = db()->prepare('SELECT id, master_key FROM ledger_groups WHERE id = :id AND company_id = :company_id AND is_active = 1 LIMIT 1');
        $groupStmt->execute(['id' => $groupId, 'company_id' => $companyId]);
        $group = $groupStmt->fetch();
        $ledgerType = $group ? ledger_master_nature((string) $group['master_key']) : null;

        if ($code === '' || $name === '' || !$group || $ledgerType === null) {
            flash('error', 'Ledger code, name, and a valid active group are required.');
            redirect('admin/chart-of-accounts.php');
        }

        try {
            $stmt = db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, is_system, status) VALUES (:company_id, :group_id, :code, :name, :type, 0, 'active')");
            $stmt->execute([
                'company_id' => $companyId,
                'group_id' => $groupId,
                'code' => $code,
                'name' => $name,
                'type' => $ledgerType,
            ]);
            log_activity('ledger', (int) db()->lastInsertId(), 'created', 'Ledger account created.', $adminId);
            flash('success', 'Ledger account created.');
        } catch (Throwable $exception) {
            flash('error', 'Could not create ledger account. The code may already exist.');
        }
        redirect('admin/chart-of-accounts.php');
    }

    if ($action === 'save_ledger_mappings') {
        if (!table_exists('company_ledger_mappings')) {
            flash('error', 'Run the ledger-mapping migration before configuring automated posting accounts.');
            redirect('admin/chart-of-accounts.php');
        }

        $ledgerStmt = db()->prepare('SELECT l.id, l.type, COALESCE(g.is_cash_or_bank, 0) AS is_cash_or_bank FROM ledgers l LEFT JOIN ledger_groups g ON g.id = l.group_id WHERE l.company_id = :company_id');
        $ledgerStmt->execute(['company_id' => $companyId]);
        $companyLedgers = [];
        foreach ($ledgerStmt->fetchAll() as $ledger) {
            $companyLedgers[(int) $ledger['id']] = $ledger;
        }

        $requestedMappings = [];
        foreach ($mappingRoles as $mapKey => $role) {
            $ledgerId = (int) ($_POST['mapping'][$mapKey] ?? 0);
            if ($ledgerId === 0) {
                $requestedMappings[$mapKey] = null;
                continue;
            }

            $ledger = $companyLedgers[$ledgerId] ?? null;
            $validNature = $ledger && (string) $ledger['type'] === (string) $role['nature'];
            $validCashAccount = empty($role['cash_only']) || (int) ($ledger['is_cash_or_bank'] ?? 0) === 1;
            if (!$validNature || !$validCashAccount) {
                flash('error', 'One or more automated posting accounts are not valid for their assigned role.');
                redirect('admin/chart-of-accounts.php');
            }

            $requestedMappings[$mapKey] = $ledgerId;
        }

        db()->beginTransaction();
        try {
            $deleteStmt = db()->prepare('DELETE FROM company_ledger_mappings WHERE company_id = :company_id AND map_key = :map_key');
            $upsertStmt = db()->prepare('INSERT INTO company_ledger_mappings (company_id, map_key, ledger_id) VALUES (:company_id, :map_key, :ledger_id) ON DUPLICATE KEY UPDATE ledger_id = VALUES(ledger_id)');
            foreach ($requestedMappings as $mapKey => $ledgerId) {
                if ($ledgerId === null) {
                    $deleteStmt->execute(['company_id' => $companyId, 'map_key' => $mapKey]);
                } else {
                    $upsertStmt->execute(['company_id' => $companyId, 'map_key' => $mapKey, 'ledger_id' => $ledgerId]);
                }
            }
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not save the automated posting accounts.');
            redirect('admin/chart-of-accounts.php');
        }

        log_activity('company', $companyId, 'ledger_mappings_updated', 'Automated posting ledger mappings updated.', $adminId);
        flash('success', 'Automated posting accounts saved.');
        redirect('admin/chart-of-accounts.php');
    }
}

$groupStmt = db()->prepare('SELECT * FROM ledger_groups WHERE company_id = :company_id AND is_active = 1 ORDER BY master_key ASC, name ASC');
$groupStmt->execute(['company_id' => $companyId]);
$flatGroups = $groupStmt->fetchAll();

$ledgerStmt = db()->prepare('SELECT l.*, COALESCE(g.is_cash_or_bank, 0) AS is_cash_or_bank FROM ledgers l LEFT JOIN ledger_groups g ON g.id = l.group_id WHERE l.company_id = :company_id AND l.status = \'active\' ORDER BY l.code ASC');
$ledgerStmt->execute(['company_id' => $companyId]);
$flatLedgers = $ledgerStmt->fetchAll();

$currentMappings = [];
if (table_exists('company_ledger_mappings')) {
    $mappingStmt = db()->prepare('SELECT map_key, ledger_id FROM company_ledger_mappings WHERE company_id = :company_id');
    $mappingStmt->execute(['company_id' => $companyId]);
    foreach ($mappingStmt->fetchAll() as $mapping) {
        $currentMappings[(string) $mapping['map_key']] = (int) $mapping['ledger_id'];
    }
}

$chartOfAccounts = get_chart_of_accounts_tree($companyId);
$ledgerCount = count($flatLedgers);
$groupCount = count($flatGroups);
$mappedCount = count(array_filter($currentMappings));

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
<div class="accounting-page-head">
    <div>
        <h2>Masters, groups, ledgers, and posting structure</h2>
        <p><?= e($currentCompany['name'] ?? 'Company') ?> / Foundation for vouchers, invoices, purchases, inventory, manufacturing, and reports.</p>
    </div>
    <div class="accounting-actions">
        <a class="button secondary" href="<?= e(url('admin/accounting-dashboard.php')) ?>"><?= icon('dashboard') ?>Dashboard</a>
        <a class="button secondary" href="<?= e(url('admin/accounting.php')) ?>"><?= icon('documents') ?>Vouchers</a>
    </div>
</div>

<nav class="accounting-tabs" aria-label="Chart of accounts sections">
    <a class="is-active" href="#hierarchy">Hierarchy View</a>
    <a href="<?= e(url('admin/chart-groups.php')) ?>">Groups</a>
    <a href="<?= e(url('admin/chart-ledgers.php')) ?>">Ledgers</a>
    <a href="<?= e(url('admin/chart-posting-accounts.php')) ?>">Posting Accounts</a>
    <a href="<?= e(url('admin/chart-audit-log.php')) ?>">Audit Log</a>
</nav>

<section class="coa-module-grid" aria-label="Chart of accounts module structure">
    <a class="coa-module-card" href="<?= e(url('admin/chart-groups.php')) ?>"><span><?= icon('accounting') ?></span><strong>Masters</strong><small>Chart framework</small></a>
    <a class="coa-module-card" href="<?= e(url('admin/chart-groups.php')) ?>"><span><?= icon('teams') ?></span><strong>Groups</strong><small>Classification</small></a>
    <a class="coa-module-card" href="<?= e(url('admin/chart-ledgers.php')) ?>"><span><?= icon('documents') ?></span><strong>Ledgers</strong><small>Accounts</small></a>
    <a class="coa-module-card" href="<?= e(url('admin/accounting.php')) ?>"><span><?= icon('services') ?></span><strong>Opening Balances</strong><small>OB controls</small></a>
    <a class="coa-module-card" href="<?= e(url('admin/chart-posting-accounts.php')) ?>"><span><?= icon('settings') ?></span><strong>Automated Posting</strong><small>Mapped accounts</small></a>
    <a class="coa-module-card" href="<?= e(url('admin/workspace.php?view=home')) ?>"><span><?= icon('reports') ?></span><strong>Dimensions</strong><small>Cost centers and projects</small></a>
</section>

<div class="accounting-stat-grid">
    <div class="accounting-stat-card accent-blue"><span class="stat-icon"><?= icon('accounting') ?></span><small>Masters</small><strong><?= e((string) count($masters)) ?></strong><em>Fixed framework</em></div>
    <div class="accounting-stat-card accent-green"><span class="stat-icon"><?= icon('teams') ?></span><small>Groups</small><strong><?= e((string) $groupCount) ?></strong><em>Active classifications</em></div>
    <div class="accounting-stat-card accent-purple"><span class="stat-icon"><?= icon('documents') ?></span><small>Ledgers</small><strong><?= e((string) $ledgerCount) ?></strong><em>Posting accounts</em></div>
    <div class="accounting-stat-card accent-orange"><span class="stat-icon"><?= icon('settings') ?></span><small>Mapped accounts</small><strong><?= e((string) $mappedCount) ?></strong><em>Automation ready</em></div>
</div>

<div class="workspace-feature-stack">
    <details class="feature-disclosure" id="create-group">
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
                <select id="group-master-key" name="master_key" required>
                    <?php foreach ($masters as $key => $master): ?>
                        <option value="<?= e($key) ?>"><?= e($master['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($supportsGroupHierarchy): ?>
                <label>Parent Group
                    <select id="parent-group-id" name="parent_group_id">
                        <option value="0">None (top-level group)</option>
                        <?php foreach ($flatGroups as $group): ?>
                            <option value="<?= (int) $group['id'] ?>" data-master-key="<?= e($group['master_key']) ?>">
                                <?= e($masters[$group['master_key']]['label'] ?? $group['master_key']) ?> / <?= e($group['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label class="checkbox-line"><input type="checkbox" name="is_cash_or_bank" value="1"> Holds Cash/Bank Ledgers</label>
            <button type="submit"><?= icon('accounting') ?>Create Group</button>
        </form>
    </details>

    <details class="feature-disclosure" id="create-ledger">
        <summary>
            <span>
                <strong><?= icon('accounting') ?>Create Ledger Account</strong>
                <small>Add an account to an active group.</small>
            </span>
            <span class="feature-disclosure-action"><?= icon('login') ?>Open form</span>
        </summary>
        <?php if ($flatGroups === []): ?>
            <p class="muted">Create a group before adding ledger accounts.</p>
        <?php else: ?>
            <form method="post" class="workspace-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_ledger">
                <label>Ledger Code<input type="text" name="code" maxlength="40" placeholder="e.g., OFFICE_RENT" required></label>
                <label>Ledger Name<input type="text" name="name" maxlength="150" placeholder="e.g., Office Rent" required></label>
                <label>Account Group
                    <select name="group_id" required>
                        <option value="">Select group</option>
                        <?php foreach ($flatGroups as $group): ?>
                            <option value="<?= (int) $group['id'] ?>">
                                <?= e($masters[$group['master_key']]['label'] ?? $group['master_key']) ?> / <?= e($group['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit"><?= icon('accounting') ?>Create Ledger</button>
            </form>
        <?php endif; ?>
    </details>

    <article class="feature-disclosure">
        <div>
            <strong><?= icon('settings') ?>Automated Posting Accounts</strong>
            <small>Choose the accounts used by invoice, receipt, refund, and tax postings.</small>
        </div>
        <a class="button secondary" href="<?= e(url('admin/chart-posting-accounts.php')) ?>"><?= icon('settings') ?>Open posting accounts</a>
    </article>
</div>

<div class="coa-workspace-grid" id="hierarchy">
<div class="card coa-tree-card">
    <div class="card-header">
        <h3><?= e($currentCompany['name']) ?> chart hierarchy</h3>
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

<aside class="coa-impact-card">
    <h3>Click flows and impact</h3>
    <div><strong>Create Group</strong><span>Vouchers, sales, purchases, inventory, manufacturing, and reports inherit classification.</span></div>
    <div><strong>Create Ledger Account</strong><span>New account becomes available for postings and mapped automation.</span></div>
    <div><strong>Automated Posting</strong><span>Invoices, receipts, payments, VAT, and cash/bank entries use approved ledgers.</span></div>
    <div><strong>Audit Enabled</strong><span>All account changes are tracked through activity logs and field history.</span></div>
</aside>
</div>

<section class="mbw-governance-note" id="audit-log">
    <span><?= icon('admin') ?></span>
    <p>All company-specific ledgers, groups, posting accounts, cost centers, and projects must be created under the assigned company node. <a href="<?= e(url('admin/chart-posting-accounts.php')) ?>">Posting accounts</a> and <a href="<?= e(url('admin/chart-audit-log.php')) ?>">audit log</a> open in their own screens now, while subsidiary users remain inside their company scope.</p>
</section>

<?php
include __DIR__ . '/../../app/views/partials/admin_footer.php';
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const parentSelect = document.getElementById('parent-group-id');
    const masterSelect = document.getElementById('group-master-key');
    if (!parentSelect || !masterSelect) {
        return;
    }

    const syncMasterToParent = function() {
        const option = parentSelect.options[parentSelect.selectedIndex];
        const parentMaster = option ? option.dataset.masterKey : '';
        if (parentMaster) {
            masterSelect.value = parentMaster;
            masterSelect.disabled = true;
        } else {
            masterSelect.disabled = false;
        }
    };

    parentSelect.addEventListener('change', syncMasterToParent);
    syncMasterToParent();
});
</script>
