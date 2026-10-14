<?php
declare(strict_types=1);

/**
 * Staff, and the client books each one may open.
 *
 * Staff belong to the platform, not to any one company: a staff member keeps
 * several clients' books and is assigned to them one at a time. This lists who
 * they are and which clients they reach, so the question "who can see this
 * client's ledger" has a screen that answers it.
 *
 * Assignment itself still happens where it always did — the client record and
 * staff_client_accounting_access — because that is what access_control reads.
 * This page reports it; it does not invent a second way to grant access.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();

if (!user_is_super_admin()) {
    redirect('admin/index.php');
}

$staff = db()->prepare("SELECT id, name, email, role, status, company_id
    FROM users WHERE role = 'staff' ORDER BY status ASC, name ASC");
$staff->execute();
$staffRows = $staff->fetchAll(PDO::FETCH_ASSOC);

/** The client books one staff member may open, by name. */
$clientsFor = static function (int $staffUserId): array {
    $names = [];
    if (table_exists('client_profiles')) {
        $direct = db()->prepare('SELECT organization_name FROM client_profiles
            WHERE assigned_staff_user_id = :uid AND is_active = 1 ORDER BY organization_name ASC');
        $direct->execute(['uid' => $staffUserId]);
        foreach ($direct->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $names[(string) $name] = true;
        }
    }
    if (table_exists('staff_client_accounting_access') && table_exists('client_profiles')) {
        $granted = db()->prepare('SELECT cp.organization_name
            FROM staff_client_accounting_access a
            INNER JOIN client_profiles cp ON cp.id = a.client_id
            WHERE a.staff_user_id = :uid AND cp.is_active = 1
            ORDER BY cp.organization_name ASC');
        $granted->execute(['uid' => $staffUserId]);
        foreach ($granted->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $names[(string) $name] = true;
        }
    }

    return array_keys($names);
};

$platformShell = true;
$pageTitle = 'Staff';
$pageBreadcrumb = [['Platform', 'platform/index.php']];
$pageHeadActions = '<a class="button secondary" href="' . e(url('platform/users.php')) . '">Manage users</a>';
$pageHero = false;
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="actions"><?= $pageHeadActions ?></div>

<section class="mbw-card">
    <div class="mbw-tablewrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th class="is-numeric">Clients</th>
                    <th>Client books they may open</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($staffRows === []): ?>
                    <tr><td colspan="6">No staff accounts.</td></tr>
                <?php endif; ?>
                <?php foreach ($staffRows as $member): ?>
                    <?php
                    $clients = $clientsFor((int) $member['id']);
                    $isActive = (string) $member['status'] === 'active';
                    ?>
                    <tr>
                        <td><?= e((string) $member['name']) ?></td>
                        <td><?= e((string) $member['email']) ?></td>
                        <td class="is-numeric"><?= count($clients) ?></td>
                        <td title="<?= e(implode(', ', $clients)) ?>" style="max-width:320px;overflow:hidden;text-overflow:ellipsis">
                            <?= $clients === [] ? '—' : e(implode(', ', array_slice($clients, 0, 3)) . (count($clients) > 3 ? ' +' . (count($clients) - 3) . ' more' : '')) ?>
                        </td>
                        <td><span class="mbw-pill <?= $isActive ? 'tone-green' : 'tone-gray' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span></td>
                        <td><a class="button secondary" href="<?= e(url('platform/users.php?view=' . (int) $member['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="frm-optional" style="margin:10px 0 0"><?= count($staffRows) ?> <?= count($staffRows) === 1 ? 'staff account' : 'staff accounts' ?></p>
</section>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
