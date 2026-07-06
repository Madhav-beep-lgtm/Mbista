<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_or_admin();
require_company_context();
$repairErrors = accounting_module_repair_database();

$currentUser = current_user();
$role = (string) ($currentUser['role'] ?? '');
$portal = current_company();
$isSuperAdminPortal = (string) ($portal['code'] ?? '') === 'MBAACA' && $role === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $clientId = (int) ($_POST['client_id'] ?? 0);
    $profileStmt = db()->prepare('SELECT * FROM client_profiles WHERE id = :id LIMIT 1');
    $profileStmt->execute(['id' => $clientId]);
    $profile = $profileStmt->fetch();
    if (!$profile || client_books_access_level($profile) === '') {
        flash('error', 'You do not have access to that client.');
        redirect('admin/manage-clients.php');
    }
    if ($action === 'provision_books' && $role === 'admin') {
        $booksId = provision_client_books($clientId);
        $booksId > 0
            ? flash('success', 'Accounting books provisioned for ' . $profile['organization_name'] . '.')
            : flash('error', 'Could not provision books.');
        redirect('admin/manage-clients.php');
    }
    if ($action === 'assign_staff' && $role === 'admin') {
        $staffId = (int) ($_POST['staff_user_id'] ?? 0);
        db()->prepare('UPDATE client_profiles SET assigned_staff_user_id = :sid WHERE id = :id')
            ->execute(['sid' => $staffId > 0 ? $staffId : null, 'id' => $clientId]);
        flash('success', 'Staff assignment updated.');
        redirect('admin/manage-clients.php');
    }
    redirect('admin/manage-clients.php');
}

$clients = client_books_clients_for_scope();
$staffStmt = db()->query("SELECT id, name FROM users WHERE role = 'staff' AND status = 'active' ORDER BY name ASC");
$staffUsers = $staffStmt ? $staffStmt->fetchAll() : [];
$withBooks = count(array_filter($clients, static fn (array $c): bool => (int) ($c['books_company_id'] ?? 0) > 0));
$assigned = count(array_filter($clients, static fn (array $c): bool => (int) ($c['assigned_staff_user_id'] ?? 0) > 0));

$pageTitle = 'Manage Clients';
$pageSubtitle = $role === 'staff' ? 'Accounting books for clients assigned to you' : ($isSuperAdminPortal ? 'Client accounting across the whole group' : 'Client accounting for this company');
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<?php if ($repairErrors !== []): ?><div class="notice error">Repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div><?php endif; ?>

<nav class="mbw-tabbar" aria-label="Client management">
    <a class="mbw-tab is-active" href="<?= e(url('admin/manage-clients.php')) ?>"><?= icon('clients') ?>Client Accounting</a>
    <a class="mbw-tab" href="<?= e(url('admin/workspace.php?view=clients')) ?>"><?= icon('portal') ?>Client Records</a>
    <a class="mbw-tab" href="<?= e(url('admin/workspace.php?view=tasks')) ?>"><?= icon('tasks') ?>Service Tasks</a>
</nav>

<section class="mbw-kpi-grid" aria-label="Client overview">
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Clients in Scope</span><div class="mbw-kpi-value"><?= count($clients) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= $role === 'staff' ? 'Assigned to you' : 'Visible in this portal' ?></span></span></div><span class="mbw-chip tone-blue"><?= icon('clients') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Books Provisioned</span><div class="mbw-kpi-value"><?= $withBooks ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Active accounting spaces</span></span></div><span class="mbw-chip tone-green"><?= icon('journal') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Staff Assigned</span><div class="mbw-kpi-value"><?= $assigned ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Clients with a staff owner</span></span></div><span class="mbw-chip tone-purple"><?= icon('staff') ?></span></article>
</section>

<section class="mbw-card" aria-label="Clients">
    <div class="mbw-card-head"><h2>Client Accounting Books</h2><span class="mbw-info"><?= icon('about') ?></span></div>
    <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Client</th><th>Serving Company</th><th>Assigned Staff</th><th>Books</th><th>Your Access</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if ($clients === []): ?><tr><td colspan="6" style="color:var(--mbw-muted)"><?= $role === 'staff' ? 'No clients are assigned to you yet.' : 'No active clients in this portal.' ?></td></tr><?php endif; ?>
            <?php foreach ($clients as $client): ?>
                <?php $access = client_books_access_level($client); $hasBooks = (int) ($client['books_company_id'] ?? 0) > 0; ?>
                <tr>
                    <td><strong style="color:var(--mbw-heading)"><?= e($client['organization_name']) ?></strong> <span class="mbw-pill tone-gray"><?= e((string) ($client['client_code'] ?? '—')) ?></span></td>
                    <td><?= e((string) ($client['serving_company'] ?? '—')) ?></td>
                    <td>
                        <?php if ($role === 'admin'): ?>
                            <form method="post" style="display:flex;gap:6px;align-items:center">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="assign_staff">
                                <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
                                <select name="staff_user_id" class="mbw-select" style="min-height:32px">
                                    <option value="0">Unassigned</option>
                                    <?php foreach ($staffUsers as $staff): ?>
                                        <option value="<?= (int) $staff['id'] ?>" <?= (int) ($client['assigned_staff_user_id'] ?? 0) === (int) $staff['id'] ? 'selected' : '' ?>><?= e($staff['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="button secondary" style="min-height:32px;padding:4px 10px">Save</button>
                            </form>
                        <?php else: ?>
                            <?= e((string) ($client['staff_name'] ?? 'Unassigned')) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $hasBooks ? '<span class="mbw-pill tone-green">Provisioned</span>' : '<span class="mbw-pill tone-gray">Not set up</span>' ?></td>
                    <td><?= $access === 'direct' ? '<span class="mbw-pill tone-blue">Direct posting</span>' : '<span class="mbw-pill tone-amber">Client approval required</span>' ?></td>
                    <td>
                        <?php if ($hasBooks): ?>
                            <a class="button soft" style="min-height:32px;padding:4px 12px" href="<?= e(url('admin/client-books.php?client=' . (int) $client['id'])) ?>"><?= icon('journal') ?>Open Books</a>
                        <?php elseif ($role === 'admin'): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="provision_books">
                                <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
                                <button type="submit" class="button secondary" style="min-height:32px;padding:4px 12px"><?= icon('tree') ?>Provision Books</button>
                            </form>
                        <?php else: ?>
                            <span style="color:var(--mbw-muted);font-size:12px">Ask an admin to provision</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
