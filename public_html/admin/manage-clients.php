<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_or_admin();
require_permission('clients', 'view');
$clientDirectoryPath = !empty($platformShell) ? 'platform/clients.php' : 'admin/manage-clients.php';
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
        redirect($clientDirectoryPath);
    }
    if ($action === 'provision_books' && $role === 'admin') {
        $booksId = provision_client_books($clientId);
        $booksId > 0
            ? flash('success', 'Accounting books provisioned for ' . $profile['organization_name'] . '.')
            : flash('error', 'Could not provision books.');
        redirect($clientDirectoryPath);
    }
    if ($action === 'toggle_hospitality') {
        if (!user_is_super_admin()) deny_access('Only the super admin can manage business types.');
        redirect('platform/company-modules.php?company_id=' . (int)$profile['books_company_id']);
    }

    if ($action === 'toggle_jewellery') {
        if (!user_is_super_admin()) deny_access('Only the super admin can manage business types.');
        redirect('platform/company-modules.php?company_id=' . (int)$profile['books_company_id']);
    }

    if ($action === 'assign_staff' && $role === 'admin') {
        $staffId = (int) ($_POST['staff_user_id'] ?? 0);
        if ($staffId > 0) {
            // The staff member must belong to THIS company. Otherwise an admin
            // could bind a foreign tenant's staffer to this client and silently
            // grant them access to its books — authorized_company_ids() grants a
            // staffer any client where assigned_staff_user_id = their id, with no
            // company scope of its own.
            // Group-wide, matching the list that offered them.
            $staffCheck = db()->prepare("SELECT id FROM users WHERE id = :id AND role = 'staff' AND status = 'active' LIMIT 1");
            $staffCheck->execute(['id' => $staffId]);
            if (!$staffCheck->fetch()) {
                flash('error', 'Select a staff member from your own company.');
                redirect($clientDirectoryPath);
            }
        }
        db()->prepare('UPDATE client_profiles SET assigned_staff_user_id = :sid WHERE id = :id')
            ->execute(['sid' => $staffId > 0 ? $staffId : null, 'id' => $clientId]);
        flash('success', 'Staff assignment updated.');
        redirect($clientDirectoryPath);
    }
    redirect($clientDirectoryPath);
}

$clients = client_books_clients_for_scope();
// Scope the assignable-staff roster to the active company. An unscoped query
// leaked every other tenant's staff names/ids into this admin's dropdown.
// Group-wide: any of the firm's staff may be given a client to serve.
$staffUsers = assignable_staff_users(current_company_id());

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
    <a class="mbw-tab is-active" href="<?= e(url($clientDirectoryPath)) ?>"><?= icon('clients') ?>Clients</a>
    <a class="mbw-tab" href="<?= e(url(!empty($platformShell) ? 'platform/work.php?view=clients' : 'admin/workspace.php?view=clients')) ?>"><?= icon('portal') ?>Client records</a>
    <a class="mbw-tab" href="<?= e(url(!empty($platformShell) ? 'platform/work.php?view=tasks' : 'admin/workspace.php?view=tasks')) ?>"><?= icon('tasks') ?>Service tasks</a>
</nav>

<section class="mbw-kpi-grid" aria-label="Client overview">
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Clients in Scope</span><div class="mbw-kpi-value"><?= count($clients) ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs"><?= $role === 'staff' ? 'Assigned to you' : 'Visible in this portal' ?></span></span></div><span class="mbw-chip tone-blue"><?= icon('clients') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Books Provisioned</span><div class="mbw-kpi-value"><?= $withBooks ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Active accounting spaces</span></span></div><span class="mbw-chip tone-green"><?= icon('journal') ?></span></article>
    <article class="mbw-kpi"><div><span class="mbw-kpi-label">Staff Assigned</span><div class="mbw-kpi-value"><?= $assigned ?></div><span class="mbw-kpi-delta"><span class="mbw-kpi-vs">Clients with a staff owner</span></span></div><span class="mbw-chip tone-purple"><?= icon('staff') ?></span></article>
</section>

<?php if ($otherPortalClients !== []): ?>
    <?php
        // GROUPED BY THE PORTAL THEY LIVE IN, because that is the thing the
        // reader has to act on: they switch portals, not clients. Inlined as
        // one sentence, nine clients ran to five lines of prose in which the
        // same portal name appeared four times and the actual instruction —
        // "switch the portal" — arrived last, after the reader had given up.
        $byPortal = [];
        foreach ($otherPortalClients as $otherClient) {
            $byPortal[(string) $otherClient['serving_company']][] = (string) $otherClient['organization_name'];
        }
        ksort($byPortal);
    ?>
    <details class="notice">
        <summary style="cursor:pointer">
            <strong><?= e((string) count($otherPortalClients)) ?> client<?= count($otherPortalClients) > 1 ? 's' : '' ?></strong>
            you manage <?= count($otherPortalClients) > 1 ? 'are' : 'is' ?> under
            <?= count($byPortal) > 1 ? e((string) count($byPortal)) . ' other portals' : 'another portal' ?>.
            Switch portal from the sidebar to manage <?= count($otherPortalClients) > 1 ? 'them' : 'it' ?>.
        </summary>
        <ul style="margin:8px 0 0;padding-left:18px">
            <?php foreach ($byPortal as $portalName => $clientNames): ?>
                <li style="margin-bottom:4px">
                    <strong><?= e($portalName) ?></strong>
                    <span style="color:var(--mbw-muted,#64748b)">— <?= e(implode(', ', $clientNames)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </details>
<?php endif; ?>

<section class="mbw-card" aria-label="Clients">
    <div class="mbw-card-head"><h2>Client Accounting Books</h2><span class="mbw-info"><?= icon('about') ?></span></div>
    <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Client</th><th>Serving Company</th><th>Assigned Staff</th><th>Books</th><th>Hospitality</th><th>Jewellery</th><th>Your Access</th><th>Actions</th></tr></thead>
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
                        <?php $hospOn=$hasBooks && company_module_enabled('hospitality',(int)$client['books_company_id']); ?>
                        <span class="mbw-pill <?= $hospOn ? 'tone-green' : 'tone-gray' ?>"><?= $hospOn ? 'Active' : 'Off' ?></span>
                    </td>
                    <td>
                        <?php $jewOn=$hasBooks && company_module_enabled('jewellery',(int)$client['books_company_id']); ?>
                        <span class="mbw-pill <?= $jewOn ? 'tone-green' : 'tone-gray' ?>"><?= $jewOn ? 'Active' : 'Off' ?></span>
                        <?php if ($hasBooks && user_is_super_admin()): ?><a class="button secondary" href="<?= e(url('platform/company-modules.php?company_id='.(int)$client['books_company_id'])) ?>">Business type</a><?php endif; ?>
                    </td>
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
