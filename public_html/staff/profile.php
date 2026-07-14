<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_staff_or_admin();
$user = current_user();
if (($user['role'] ?? '') !== 'staff') {
    // Admins manage their account from Admin → Users; this page is staff self-service.
    redirect(role_home_path($user));
}

$userId = (int) $user['id'];
$profile = staff_profile($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_profile') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $jobTitle = trim((string) ($_POST['job_title'] ?? ''));
        $department = trim((string) ($_POST['department'] ?? ''));
        $education = trim((string) ($_POST['education'] ?? ''));
        $qualifications = trim((string) ($_POST['qualifications'] ?? ''));
        $expertise = trim((string) ($_POST['expertise'] ?? ''));
        $bio = trim((string) ($_POST['bio'] ?? ''));

        if ($name === '') {
            flash('error', 'Your name is required.');
            redirect('staff/profile.php');
        }
        if ($phone !== '' && !preg_match('/^[0-9+\-\s().]{6,30}$/', $phone)) {
            flash('error', 'Phone number contains invalid characters.');
            redirect('staff/profile.php');
        }

        db()->prepare('UPDATE users SET name = :name, phone = :phone WHERE id = :id')
            ->execute(['name' => $name, 'phone' => $phone !== '' ? $phone : null, 'id' => $userId]);

        if (table_exists('staff_profiles')) {
            // Self-editable card only. Public-team placement (photo, category,
            // ordering, visibility) stays with the admin.
            db()->prepare('INSERT INTO staff_profiles (user_id, job_title, department, education, qualifications, expertise, bio)
                VALUES (:user_id, :job_title, :department, :education, :qualifications, :expertise, :bio)
                ON DUPLICATE KEY UPDATE job_title = VALUES(job_title), department = VALUES(department),
                    education = VALUES(education), qualifications = VALUES(qualifications),
                    expertise = VALUES(expertise), bio = VALUES(bio)')
                ->execute([
                    'user_id' => $userId,
                    'job_title' => $jobTitle !== '' ? $jobTitle : null,
                    'department' => $department !== '' ? $department : null,
                    'education' => $education !== '' ? $education : null,
                    'qualifications' => $qualifications !== '' ? $qualifications : null,
                    'expertise' => $expertise !== '' ? $expertise : null,
                    'bio' => $bio !== '' ? $bio : null,
                ]);
        }

        log_activity('user', $userId, 'updated', 'Staff updated own profile details.', $userId);
        flash('success', 'Profile updated successfully.');
        redirect('staff/profile.php');
    }

    if ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $account = user_by_email((string) $user['email']);
        if (!$account || !password_verify($currentPassword, (string) $account['password_hash'])) {
            security_event('password_change', 'failure', 'Wrong current password on self-service change.', null, $userId);
            flash('error', 'Current password is incorrect.');
            redirect('staff/profile.php');
        }
        if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            flash('error', 'New password must be at least 8 characters and include uppercase, lowercase, and a number.');
            redirect('staff/profile.php');
        }
        if ($newPassword !== $confirmPassword) {
            flash('error', 'New password and confirmation do not match.');
            redirect('staff/profile.php');
        }
        if (hash_equals($currentPassword, $newPassword)) {
            flash('error', 'New password must be different from the current password.');
            redirect('staff/profile.php');
        }

        db()->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id')
            ->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $userId]);

        log_activity('user', $userId, 'password_changed', 'Staff changed own password from the portal.', $userId);
        security_event('password_change', 'success', 'Self-service password change.', null, $userId);
        flash('success', 'Password changed successfully.');
        redirect('staff/profile.php');
    }

    flash('error', 'Unknown action.');
    redirect('staff/profile.php');
}

$pageTitle = 'My Profile';
$pageSubtitle = 'Your account details and password';
include __DIR__ . '/../../app/views/partials/staff_header.php';
?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:18px;align-items:start">
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>My details</h2></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_profile">
            <label>Full name<input type="text" name="name" value="<?= e($user['name'] ?? '') ?>" required></label>
            <label>Email (login)<input type="email" value="<?= e($user['email'] ?? '') ?>" readonly title="Contact your administrator to change your login email"></label>
            <label>Phone<input type="text" name="phone" value="<?= e($user['phone'] ?? '') ?>"></label>
            <label>Job title<input type="text" name="job_title" value="<?= e($profile['job_title'] ?? '') ?>"></label>
            <label>Department<input type="text" name="department" value="<?= e($profile['department'] ?? '') ?>"></label>
            <label>Education<input type="text" name="education" value="<?= e($profile['education'] ?? '') ?>"></label>
            <label>Qualifications<input type="text" name="qualifications" value="<?= e($profile['qualifications'] ?? '') ?>"></label>
            <label>Expertise<input type="text" name="expertise" value="<?= e($profile['expertise'] ?? '') ?>"></label>
            <label>Short bio<textarea name="bio" rows="3"><?= e($profile['bio'] ?? '') ?></textarea></label>
            <p style="color:var(--mbw-muted);font-size:12px">Photo and public-team display are managed by your administrator.</p>
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
            <p style="color:var(--mbw-muted);font-size:12px">At least 8 characters with uppercase, lowercase, and a number.</p>
            <div class="actions"><button type="submit"><?= icon('lock') ?>Update password</button></div>
        </form>
    </section>
</div>

<?php include __DIR__ . '/../../app/views/partials/staff_footer.php'; ?>
