<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

require_login();

$user = current_user();
if (($user['role'] ?? '') !== 'customer') {
    redirect(role_home_path($user));
}

$userId = (int) $user['id'];
$clientProfile = client_profile_for_user($userId);
$isOwner = $clientProfile !== null && (string) ($clientProfile['portal_member_role'] ?? '') === 'owner';
$maxPortalUsers = 10; // owner + members; keeps a runaway org from filling the users table

// One strong-password rule for every self-service form on this page.
$passwordPolicyError = static function (string $password): ?string {
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password must be at least 8 characters and include uppercase, lowercase, and a number.';
    }

    return null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_profile') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));

        if ($name === '') {
            flash('error', 'Your name is required.');
            redirect('my-profile.php');
        }
        if ($phone !== '' && !preg_match('/^[0-9+\-\s().]{6,30}$/', $phone)) {
            flash('error', 'Phone number contains invalid characters.');
            redirect('my-profile.php');
        }

        db()->prepare('UPDATE users SET name = :name, phone = :phone WHERE id = :id')
            ->execute(['name' => $name, 'phone' => $phone !== '' ? $phone : null, 'id' => $userId]);

        // Only the owner login may edit the organization's contact card.
        if ($isOwner && $clientProfile) {
            $address = trim((string) ($_POST['address'] ?? ''));
            $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
            $website = trim((string) ($_POST['website'] ?? ''));
            $signatoryName = trim((string) ($_POST['authorized_signatory_name'] ?? ''));
            $signatoryPosition = trim((string) ($_POST['authorized_person_position'] ?? ''));

            if ($contactNumber !== '' && !preg_match('/^[0-9+\-\s().]{6,30}$/', $contactNumber)) {
                flash('error', 'Organization contact number contains invalid characters.');
                redirect('my-profile.php');
            }
            if ($website !== '' && filter_var($website, FILTER_VALIDATE_URL) === false) {
                flash('error', 'Website must be a valid URL.');
                redirect('my-profile.php');
            }

            db()->prepare('UPDATE client_profiles SET address = :address, contact_number = :contact_number, website = :website,
                    authorized_signatory_name = :signatory_name, authorized_person_position = :signatory_position
                WHERE id = :id')
                ->execute([
                    'address' => $address !== '' ? $address : null,
                    'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                    'website' => $website !== '' ? $website : null,
                    'signatory_name' => $signatoryName !== '' ? $signatoryName : null,
                    'signatory_position' => $signatoryPosition !== '' ? $signatoryPosition : null,
                    'id' => (int) $clientProfile['id'],
                ]);
            log_activity('client_profile', (int) $clientProfile['id'], 'updated', 'Client updated organization contact details from the portal.', $userId);
        }

        log_activity('user', $userId, 'updated', 'User updated own profile details.', $userId);
        flash('success', 'Profile updated successfully.');
        redirect('my-profile.php');
    }

    if ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $account = user_by_email((string) $user['email']);
        if (!$account || !password_verify($currentPassword, (string) $account['password_hash'])) {
            security_event('password_change', 'failure', 'Wrong current password on self-service change.', null, $userId);
            flash('error', 'Current password is incorrect.');
            redirect('my-profile.php');
        }
        if (($policyError = $passwordPolicyError($newPassword)) !== null) {
            flash('error', $policyError);
            redirect('my-profile.php');
        }
        if ($newPassword !== $confirmPassword) {
            flash('error', 'New password and confirmation do not match.');
            redirect('my-profile.php');
        }
        if (hash_equals($currentPassword, $newPassword)) {
            flash('error', 'New password must be different from the current password.');
            redirect('my-profile.php');
        }

        db()->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id')
            ->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $userId]);

        log_activity('user', $userId, 'password_changed', 'User changed own password from the portal.', $userId);
        security_event('password_change', 'success', 'Self-service password change.', null, $userId);
        flash('success', 'Password changed successfully.');
        redirect('my-profile.php');
    }

    if ($action === 'create_portal_user') {
        if (!$isOwner || !$clientProfile) {
            deny_access('Non-owner client login attempted to create a portal user.');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['password_confirm'] ?? '');
        $memberRole = (string) ($_POST['member_role'] ?? 'entry_maker');

        if (!in_array($memberRole, ['approver', 'entry_maker'], true)) {
            $memberRole = 'entry_maker';
        }
        if ($name === '' || $email === '' || $password === '') {
            flash('error', 'Name, email, and password are required for a new user.');
            redirect('my-profile.php');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please provide a valid email address.');
            redirect('my-profile.php');
        }
        if (($policyError = $passwordPolicyError($password)) !== null) {
            flash('error', $policyError);
            redirect('my-profile.php');
        }
        if ($password !== $confirmPassword) {
            flash('error', 'Password and confirmation do not match.');
            redirect('my-profile.php');
        }

        $countStmt = db()->prepare('SELECT COUNT(*) FROM client_portal_users WHERE client_id = :client_id AND is_active = 1');
        $countStmt->execute(['client_id' => (int) $clientProfile['id']]);
        if ((int) $countStmt->fetchColumn() + 1 >= $maxPortalUsers) {
            flash('error', 'User limit reached (' . $maxPortalUsers . ' logins per organization). Contact your accountant to raise it.');
            redirect('my-profile.php');
        }

        try {
            db()->beginTransaction();
            $newUserId = create_user([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => 'customer',
                'status' => 'active',
                'company_id' => $clientProfile['company_id'] ?? null,
                'phone' => $phone !== '' ? $phone : null,
                'company' => $clientProfile['organization_name'] ?? null,
                'must_change_password' => 1,
            ]);
            db()->prepare('INSERT INTO client_portal_users (client_id, user_id, member_role, is_active, created_by)
                VALUES (:client_id, :user_id, :member_role, 1, :created_by)')
                ->execute(['client_id' => (int) $clientProfile['id'], 'user_id' => $newUserId, 'member_role' => $memberRole, 'created_by' => $userId]);
            db()->commit();

            log_activity('user', $newUserId, 'created', 'Portal user (' . $memberRole . ') created by client owner for ' . ($clientProfile['organization_name'] ?? 'client') . '.', $userId);
            security_event('portal_user_created', 'success', 'Client owner added portal user #' . $newUserId . ' (' . $email . ', ' . $memberRole . ').', (int) ($clientProfile['company_id'] ?? 0) ?: null, $userId);
            flash('success', 'User created. They must change the password you set on first sign-in.');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not create the user. The email may already be in use.');
        }
        redirect('my-profile.php');
    }

    if ($action === 'set_portal_user_status') {
        if (!$isOwner || !$clientProfile) {
            deny_access('Non-owner client login attempted to change a portal user.');
        }

        $memberUserId = (int) ($_POST['user_id'] ?? 0);
        $enable = (string) ($_POST['status'] ?? '') === 'active';

        // The member must belong to THIS client; the owner row is not in the pivot,
        // so the owner can never deactivate itself here.
        $memberStmt = db()->prepare('SELECT id FROM client_portal_users WHERE client_id = :client_id AND user_id = :user_id LIMIT 1');
        $memberStmt->execute(['client_id' => (int) $clientProfile['id'], 'user_id' => $memberUserId]);
        if (!$memberStmt->fetch()) {
            deny_access('Attempted to change a portal user outside the caller\'s organization.');
        }

        db()->prepare('UPDATE client_portal_users SET is_active = :active WHERE client_id = :client_id AND user_id = :user_id')
            ->execute(['active' => $enable ? 1 : 0, 'client_id' => (int) $clientProfile['id'], 'user_id' => $memberUserId]);
        db()->prepare('UPDATE users SET status = :status WHERE id = :id')
            ->execute(['status' => $enable ? 'active' : 'inactive', 'id' => $memberUserId]);
        if (!$enable) {
            revoke_user_sessions($memberUserId);
        }

        log_activity('user', $memberUserId, 'status_changed', 'Portal user ' . ($enable ? 'reactivated' : 'deactivated') . ' by client owner.', $userId);
        security_event('portal_user_status', 'success', 'Client owner set portal user #' . $memberUserId . ' to ' . ($enable ? 'active' : 'inactive') . '.', (int) ($clientProfile['company_id'] ?? 0) ?: null, $userId);
        flash('success', $enable ? 'User reactivated.' : 'User deactivated and signed out everywhere.');
        redirect('my-profile.php');
    }

    flash('error', 'Unknown action.');
    redirect('my-profile.php');
}

$portalUsers = [];
if ($clientProfile && table_exists('client_portal_users')) {
    $stmt = db()->prepare("SELECT u.id, u.name, u.email, u.phone, u.status, 'owner' AS member_role, 1 AS member_active, 0 AS sort_rank
            FROM users u WHERE u.id = :owner_id
        UNION ALL
        SELECT u.id, u.name, u.email, u.phone, u.status, cpu.member_role, cpu.is_active AS member_active, 1 AS sort_rank
            FROM client_portal_users cpu INNER JOIN users u ON u.id = cpu.user_id
            WHERE cpu.client_id = :client_id
        ORDER BY sort_rank ASC, name ASC");
    $stmt->execute(['owner_id' => (int) $clientProfile['user_id'], 'client_id' => (int) $clientProfile['id']]);
    $portalUsers = $stmt->fetchAll();
}

$pageTitle = 'My Profile';
$pageSubtitle = 'Your account, password, and organization users';
$headerClientProfile = $clientProfile;
include __DIR__ . '/../app/views/partials/client_header.php';
?>

<div class="mbw-grid-2col" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:18px;align-items:start">
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>My details</h2></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_profile">
            <label>Full name<input type="text" name="name" value="<?= e($user['name'] ?? '') ?>" required></label>
            <label>Email (login)<input type="email" value="<?= e($user['email'] ?? '') ?>" readonly title="Contact your accountant to change your login email"></label>
            <label>Phone<input type="text" name="phone" value="<?= e($user['phone'] ?? '') ?>"></label>
            <?php if ($isOwner && $clientProfile): ?>
                <div class="mbw-card-head" style="margin-top:14px"><h2 style="font-size:1rem">Organization contact card</h2></div>
                <label>Address<textarea name="address" rows="2"><?= e($clientProfile['address'] ?? '') ?></textarea></label>
                <label>Contact number<input type="text" name="contact_number" value="<?= e($clientProfile['contact_number'] ?? '') ?>"></label>
                <label>Website<input type="url" name="website" value="<?= e($clientProfile['website'] ?? '') ?>" placeholder="https://example.com"></label>
                <label>Authorized signatory name<input type="text" name="authorized_signatory_name" value="<?= e($clientProfile['authorized_signatory_name'] ?? '') ?>"></label>
                <label>Signatory position<input type="text" name="authorized_person_position" value="<?= e($clientProfile['authorized_person_position'] ?? '') ?>"></label>
                <p class="mbw-form-hint" style="color:var(--mbw-muted);font-size:12px">Organization name, PAN, and registration number are managed by your accountant.</p>
            <?php endif; ?>
            <div class="actions"><button type="submit"><?= icon('settings') ?>Save profile</button></div>
        </form>
    </section>

    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Change password</h2></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="change_password">
            <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
            <label>New password<input type="password" name="new_password" minlength="8" required data-strength data-confirm-source autocomplete="new-password"></label>
            <label>Confirm new password<input type="password" name="confirm_password" minlength="8" required data-confirm-target autocomplete="new-password"></label>
            <p class="mbw-form-hint" style="color:var(--mbw-muted);font-size:12px">At least 8 characters with uppercase, lowercase, and a number.</p>
            <div class="actions"><button type="submit"><?= icon('lock') ?>Update password</button></div>
        </form>
    </section>
</div>

<?php if ($clientProfile): ?>
<section class="mbw-card" style="margin-top:18px">
    <div class="mbw-card-head"><h2>Portal users</h2></div>
    <?php if (!$isOwner): ?>
        <p style="color:var(--mbw-muted)">Only your organization's primary login can add or deactivate users.</p>
    <?php endif; ?>
    <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Status</th>
                    <?php if ($isOwner): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($portalUsers as $portalUser): ?>
                    <?php $memberActive = !empty($portalUser['member_active']) && ($portalUser['status'] ?? '') === 'active'; ?>
                    <tr>
                        <td><?= e($portalUser['name']) ?><?= (int) $portalUser['id'] === $userId ? ' <small>(you)</small>' : '' ?></td>
                        <td><?= e($portalUser['email']) ?></td>
                        <td><?= e($portalUser['phone'] ?? '—') ?></td>
                        <td><?= e(match ((string) $portalUser['member_role']) {
                            'owner' => 'Owner (admin)',
                            'approver' => 'Approver',
                            default => 'Entry maker',
                        }) ?></td>
                        <td><?= $memberActive ? 'Active' : 'Inactive' ?></td>
                        <?php if ($isOwner): ?>
                            <td>
                                <?php if ((string) $portalUser['member_role'] !== 'owner'): ?>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="set_portal_user_status">
                                        <input type="hidden" name="user_id" value="<?= e((int) $portalUser['id']) ?>">
                                        <input type="hidden" name="status" value="<?= $memberActive ? 'inactive' : 'active' ?>">
                                        <button type="submit" class="button secondary"><?= $memberActive ? 'Deactivate' : 'Reactivate' ?></button>
                                    </form>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($isOwner): ?>
        <div class="mbw-card-head" style="margin-top:16px"><h2 style="font-size:1rem">Add a user</h2></div>
        <p style="color:var(--mbw-muted);font-size:13px">New users sign in with the temporary password you set here and must replace it on first sign-in. <strong>Approvers</strong> can make accounting entries and approve them, like you. <strong>Entry makers</strong> can view, create and edit entries, but their vouchers wait for an approver. Only you can manage users.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_portal_user">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
                <label>Name<input type="text" name="name" required></label>
                <label>Email<input type="email" name="email" required></label>
                <label>Phone<input type="text" name="phone"></label>
                <label>Accounting role
                    <select name="member_role" required>
                        <option value="entry_maker" selected>Entry maker — create &amp; edit, no approval</option>
                        <option value="approver">Approver — create, edit &amp; approve</option>
                    </select>
                </label>
                <label>Temporary password<input type="password" name="password" minlength="8" required data-strength data-confirm-source autocomplete="new-password"></label>
                <label>Confirm password<input type="password" name="password_confirm" minlength="8" required data-confirm-target autocomplete="new-password"></label>
            </div>
            <div class="actions"><button type="submit"><?= icon('profile') ?>Create user</button></div>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php include __DIR__ . '/../app/views/partials/client_footer.php'; ?>
