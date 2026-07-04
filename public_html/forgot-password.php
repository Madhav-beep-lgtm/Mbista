<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$pageTitle = 'Forgot Password';
$currentUser = current_user();

if ($currentUser) {
    redirect($currentUser['role'] === 'admin' ? 'admin/index.php' : 'dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim((string) ($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Please enter a valid email address.');
        redirect('forgot-password.php');
    }

    $token = request_password_reset($email, $_SERVER['REMOTE_ADDR'] ?? null);
    $message = 'If the account exists, a reset request has been created. Use the reset link process to set a new password.';
    if ($token !== null && !app_is_production()) {
        $message .= ' Dev reset token: ' . $token;
    }

    flash('success', $message);
    redirect('forgot-password.php');
}

include __DIR__ . '/../app/views/partials/header.php';
?>
<section class="section">
    <div class="container auth-grid">
        <div class="form-card">
            <h2>Forgot password</h2>
            <p>Enter the email address for your account. For production, send the generated token link through your support process.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Email<input type="email" name="email" required></label>
                <button type="submit">Request reset</button>
            </form>
            <div class="actions">
                <a class="button secondary" href="<?= e(url('login.php')) ?>">Back to login</a>
            </div>
        </div>
        <div class="card">
            <h3>Reset link format</h3>
            <p><?= e(url('reset-password.php')) ?>?token=YOUR_TOKEN</p>
            <ul class="checklist">
                <li>Token expires in 1 hour</li>
                <li>Token becomes invalid after use</li>
                <li>Password change is logged</li>
            </ul>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
