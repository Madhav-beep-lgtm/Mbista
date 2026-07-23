<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_or_admin();
require_permission('clients', 'view');
if ((string) (current_user()['role'] ?? '') === 'admin') {
    require_company_context();
}
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
    if ($action === 'toggle_hospitality') {
        // Hospitality Accounting activation is a SUPER ADMIN decision only —
        // client admins and staff can never flip it (server-side enforced).
        if (current_access_level() !== 'super_admin') {
            flash('error', 'Only the Super Admin can activate or deactivate Hospitality Accounting.');
            redirect('admin/manage-clients.php');
        }
        $newValue = (string) ($_POST['enabled'] ?? '0') === '1' ? 1 : 0;
        $oldValue = (int) ($profile['hospitality_accounting_enabled'] ?? 0);
        if ($oldValue !== $newValue) {
            db()->prepare('UPDATE client_profiles SET hospitality_accounting_enabled = :v WHERE id = :id')
                ->execute(['v' => $newValue, 'id' => $clientId]);
            log_activity('client', $clientId, 'hospitality_toggle',
                'Hospitality Accounting ' . ($newValue === 1 ? 'ACTIVATED' : 'DEACTIVATED')
                . ' for ' . $profile['organization_name'] . ' (was ' . ($oldValue === 1 ? 'on' : 'off') . '). Data is preserved either way.',
                (int) ($currentUser['id'] ?? 0));
            log_field_changes('client', $clientId,
                ['hospitality_accounting_enabled' => $oldValue],
                ['hospitality_accounting_enabled' => $newValue],
                current_company_id(), (int) ($currentUser['id'] ?? 0));
            security_event('hospitality_toggle', 'success',
                'Hospitality Accounting ' . ($newValue === 1 ? 'activated' : 'deactivated') . ' for client #' . $clientId . '.',
                (int) ($profile['books_company_id'] ?? 0) ?: null, (int) ($currentUser['id'] ?? 0));
        }
        flash('success', 'Hospitality Accounting is now ' . ($newValue === 1 ? 'ACTIVE' : 'inactive') . ' for ' . $profile['organization_name']
            . ($newValue === 0 ? '. All hospitality data is preserved and reappears on reactivation.' : '.'));
        redirect('admin/manage-clients.php');
    }
    if ($action === 'assign_staff' && $role === 'admin') {
        $staffId = (int) ($_POST['staff_user_id'] ?? 0);
        if ($staffId > 0) {
            // The staff member must belong to THIS company. Otherwise an admin
            // could bind a foreign tenant's staffer to this client and silently
            // grant them access to its books — authorized_company_ids() grants a
            // staffer any client where assigned_staff_user_id = their id, with no
            // company scope of its own.
            $staffCheck = db()->prepare("SELECT id FROM users WHERE id = :id AND role = 'staff' AND company_id = :company_id LIMIT 1");
            $staffCheck->execute(['id' => $staffId, 'company_id' => current_company_id()]);
            if (!$staffCheck->fetch()) {
                flash('error', 'Select a staff member from your own company.');
                redirect('admin/manage-clients.php');
            }
        }
        db()->prepare('UPDATE client_profiles SET assigned_staff_user_id = :sid WHERE id = :id')
            ->execute(['sid' => $staffId > 0 ? $staffId : null, 'id' => $clientId]);
        flash('success', 'Staff assignment updated.');
        redirect('admin/manage-clients.php');
    }
    redirect('admin/manage-clients.php');
}

$clients = client_books_clients_for_scope();
// Scope the assignable-staff roster to the active company. An unscoped query
// leaked every other tenant's staff names/ids into this admin's dropdown.
$staffStmt = db()->prepare("SELECT id, name FROM users WHERE role = 'staff' AND status = 'active' AND company_id = :company_id ORDER BY name ASC");
$staffStmt->execute(['company_id' => current_company_id()]);
$staffUsers = $staffStmt->fetchAll();

// Clients this admin manages that live under a DIFFERENT portal: created while
// another company context was active, so they are absent from the list below.
$otherPortalClients = [];
if ($role === 'admin') {
    $shownClientIds = array_map(static fn (array $c): int => (int) $c['id'], $clients);
    $authorizedIds = array_values(array_diff(authorized_company_ids(), [current_company_id()]));
    if ($authorizedIds !== []) {
        $otherPlaceholders = implode(',', array_fill(0, count($authorizedIds), '?'));
        $otherStmt = db()->prepare("SELECT cp.id, cp.organization_name, cp.client_code, co.name AS serving_company
            FROM client_profiles cp
            INNER JOIN companies co ON co.id = cp.company_id
            WHERE cp.is_active = 1 AND cp.company_id IN ($otherPlaceholders)
            ORDER BY cp.created_at DESC LIMIT 30");
        $otherStmt->execute($authorizedIds);
        $otherPortalClients = array_values(array_filter(
            $otherStmt->fetchAll(),
            static fn (array $c): bool => !in_array((int) $c['id'], $shownClientIds, true)
        ));
    }
}
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

<?php if ($otherPortalClients !== []): ?>
    <div class="notice">
        <strong><?= e((string) count($otherPortalClients)) ?> client<?= count($otherPortalClients) > 1 ? 's' : '' ?></strong>
        you manage <?= count($otherPortalClients) > 1 ? 'are' : 'is' ?> listed under a different portal:
        <?php foreach ($otherPortalClients as $i => $otherClient): ?><?= $i > 0 ? ', ' : ' ' ?><em><?= e($otherClient['organization_name']) ?></em> (<?= e($otherClient['serving_company']) ?>)<?php endforeach; ?>.
        Switch the portal from the sidebar to manage <?= count($otherPortalClients) > 1 ? 'them' : 'it' ?>.
    </div>
<?php endif; ?>

<section class="mbw-card" aria-label="Clients">
    <div class="mbw-card-head"><h2>Client Accounting Books</h2><span class="mbw-info"><?= icon('about') ?></span></div>
    <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Client</th><th>Serving Company</th><th>Assigned Staff</th><th>Books</th><th>Hospitality</th><th>Your Access</th><th>Actions</th></tr></thead>
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
                    <td>
                        <?php $hospOn = (int) ($client['hospitality_accounting_enabled'] ?? 0) === 1; ?>
                        <?php if (current_access_level() === 'super_admin'): ?>
                            <form method="post" style="display:flex;gap:6px;align-items:center" data-confirm="<?= $hospOn ? 'Deactivate Hospitality Accounting for this client? All recipes and costing data are PRESERVED and its menu/routes are blocked until reactivated.' : 'Activate Hospitality Accounting (recipe costing, reference-only) for this client?' ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle_hospitality">
                                <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
                                <input type="hidden" name="enabled" value="<?= $hospOn ? '0' : '1' ?>">
                                <span class="mbw-pill <?= $hospOn ? 'tone-green' : 'tone-gray' ?>"><?= $hospOn ? 'Active' : 'Off' ?></span>
                                <button type="submit" class="button secondary" style="min-height:32px;padding:4px 10px"><?= $hospOn ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        <?php else: ?>
                            <span class="mbw-pill <?= $hospOn ? 'tone-green' : 'tone-gray' ?>" title="Only the Super Admin can change this"><?= $hospOn ? 'Active' : 'Off' ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= $access === 'direct' ? '<span class="mbw-pill tone-blue">Direct posting</span>' : '<span class="mbw-pill tone-amber">Client approval required</span>' ?></td>
                    <td>
                        <?php if ($hasBooks): ?>
                            <a class="button soft" style="min-height:32px;padding:4px 12px" href="<?= e(url('admin/client-books.php?client=' . (int) $client['id'])) ?>"><?= icon('journal') ?>Open Books</a>
                            <?php if ($access === 'direct'): ?>
                                <form method="post" action="<?= e(url('admin/switch-company.php')) ?>" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="company_id" value="<?= (int) $client['books_company_id'] ?>">
                                    <button type="submit" class="button" style="min-height:32px;padding:4px 12px"><?= icon('accounting') ?>Full Accounting</button>
                                </form>
                            <?php endif; ?>
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
