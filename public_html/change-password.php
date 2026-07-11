<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

require_login();

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'customer') {
    redirect(role_home_path($user));
}

if (empty($user['must_change_password'])) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $account = user_by_email((string) $user['email']);

    if (!$account || !password_verify($currentPassword, (string) $account['password_hash'])) {
        flash('error', 'Current temporary password is incorrect.');
        redirect('change-password.php');
    }

    if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        flash('error', 'New password must be at least 8 characters and include uppercase, lowercase, and a number.');
        redirect('change-password.php');
    }

    if ($newPassword !== $confirmPassword) {
        flash('error', 'New password and confirmation must match.');
        redirect('change-password.php');
    }

    if (hash_equals($currentPassword, $newPassword)) {
        flash('error', 'New password must be different from the temporary password.');
        redirect('change-password.php');
    }

    $stmt = db()->prepare('UPDATE users SET password_hash = :password_hash, must_change_password = 0 WHERE id = :id');
    $stmt->execute([
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id' => (int) $user['id'],
    ]);

    log_activity('user', (int) $user['id'], 'first_login_password_changed', 'Client changed temporary password on first login.', (int) $user['id']);
    flash('success', 'Password changed successfully.');
    redirect('dashboard.php');
}

$pageTitle = 'Change Password';
include __DIR__ . '/../app/views/partials/header.php';
?>
<section class="section">
    <div class="container auth-grid">
        <div class="form-card">
            <h2>Change temporary password</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Current or temporary password<input type="password" name="current_password" required></label>
                <label>New password<input type="password" name="new_password" minlength="8" required data-strength></label>
                <label>Confirm new password<input type="password" name="confirm_password" minlength="8" required></label>
                <button type="submit"><?= icon('settings') ?>Update password</button>
            </form>
        </div>
        <div class="card">
            <h3>Required before portal access</h3>
            <p>Your administrator created this login with a temporary password. Set your own password before opening the client portal.</p>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
