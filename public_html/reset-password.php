<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$pageTitle = 'Reset Password';
$currentUser = current_user();

if ($currentUser) {
    redirect($currentUser['role'] === 'admin' ? 'admin/index.php' : 'dashboard.php');
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$resetUser = $token !== '' ? password_reset_user_by_token($token) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $errors = [];
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($token === '' || !$resetUser) {
        flash('error', 'Invalid or expired reset token.');
        redirect('forgot-password.php');
        exit; // Good practice to exit after a redirect
    }

    if ($password === '' || strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (!empty($errors)) {
        // If there are errors, flash them and show the form again
        flash('error', implode('<br>', $errors));
    } elseif (!reset_password_by_token($token, $password)) {
        flash('error', 'Could not reset password. The token may be invalid or expired.');
        redirect('forgot-password.php');
    }

    flash('success', 'Password reset complete. You can now login with your new password.');
    redirect('login.php');
}

include __DIR__ . '/../app/views/partials/header.php';
?>
<section class="section">
    <div class="container auth-grid">
        <div class="form-card">
            <h2>Reset password</h2>
            <?php if (!$resetUser): ?>
                <p>This reset link is invalid or expired.</p>
                <?php display_flash_messages(); ?>
                <div class="actions">
                    <a class="button secondary" href="<?= e(url('forgot-password.php')) ?>">Request new link</a>
                </div>
            <?php else: ?>
                <p>Resetting password for <?= e($resetUser['email']) ?></p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <?php display_flash_messages(); ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <label>New password<input type="password" name="password" minlength="8" required data-strength></label>
                    <label>Confirm password<input type="password" name="confirm_password" minlength="8" required></label>
                    <button type="submit">Save password</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3>Password rules</h3>
            <ul class="checklist">
                <li>At least 8 characters</li>
                <li>Use a unique password</li>
                <li>Store passwords securely</li>
            </ul>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
