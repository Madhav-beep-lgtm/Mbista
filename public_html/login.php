<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$pageTitle = 'Login';
$bodyClass = 'auth-page';
$currentUser = current_user();

if ($currentUser) {
    redirect(role_home_path($currentUser));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        flash('error', 'Enter your email and password.');
        redirect('login.php');
    }

    // Throttle brute force by email + IP over a rolling window.
    if (login_is_throttled($email)) {
        security_event('login_throttled', 'denied', 'Too many failed attempts.', null, null, $email);
        flash('error', 'Too many failed attempts. Please wait a few minutes and try again.');
        redirect('login.php');
    }

    if (!login_user($email, $password)) {
        flash('error', 'Invalid login credentials.');
        redirect('login.php');
    }

    if (!empty($_POST['remember'])) {
        // Keep the session cookie alive for 30 days instead of ending with the browser.
        setcookie(session_name(), session_id(), [
            'expires' => time() + 60 * 60 * 24 * 30,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $user = current_user();
    flash('success', 'Welcome back, ' . ($user['name'] ?? 'user') . '.');
    redirect(role_home_path($user));
}

$authSupportEmail = (string) setting('support_email', 'info@mbca.com.np');
include __DIR__ . '/../app/views/partials/header.php';
?>
<section class="auth2">
    <div class="auth2-left">
        <a class="auth2-wordmark" href="<?= e(url('index.php')) ?>">
            <span class="auth2-mark">MB</span>
            <span class="auth2-wordmark-text">
                <strong><?= e(strtoupper(setting('brand_name', 'M. Bista & Associates'))) ?></strong>
                <small>CHARTERED ACCOUNTANTS</small>
            </span>
        </a>
        <div class="auth2-copy">
            <h1>
                <span>Professional Clarity.</span>
                <span class="auth2-gold">Trusted Expertise.</span>
                <span>Stronger Financial Futures.</span>
            </h1>
            <div class="auth2-rule"></div>
            <p>Your trusted partner in assurance, advisory, taxation, and compliance. Secure access to your financial world&mdash;anytime, anywhere.</p>
        </div>
        <div class="auth2-features">
            <div class="auth2-feature">
                <span class="auth2-feature-icon"><?= icon('admin') ?></span>
                <strong>Secure Access</strong>
                <small>Bank-grade security to protect your data.</small>
            </div>
            <div class="auth2-feature">
                <span class="auth2-feature-icon"><?= icon('award') ?></span>
                <strong>Trusted by Clients</strong>
                <small>Reliable. Confidential. Professional.</small>
            </div>
            <div class="auth2-feature">
                <span class="auth2-feature-icon"><?= icon('reports') ?></span>
                <strong>Expert Advisory</strong>
                <small>Insights that drive better decisions.</small>
            </div>
            <div class="auth2-feature">
                <span class="auth2-feature-icon"><?= icon('attendance') ?></span>
                <strong>Always Available</strong>
                <small>Access your documents and reports 24/7.</small>
            </div>
        </div>
        <div class="auth2-encrypt">
            <span class="auth2-encrypt-icon"><?= icon('lock') ?></span>
            Your data is protected with enterprise-grade encryption and strict confidentiality.
        </div>
    </div>

    <div class="auth2-right">
        <div class="auth2-card">
            <span class="auth2-badge"><?= icon('admin') ?></span>
            <h2>Welcome back</h2>
            <p class="auth2-sub">Sign in to continue to your secure portal.</p>
            <form method="post" class="auth2-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Email address
                    <span class="auth2-input">
                        <?= icon('profile') ?>
                        <input type="email" name="email" required autocomplete="email" placeholder="you@example.com">
                    </span>
                </label>
                <label>Password
                    <span class="auth2-input">
                        <?= icon('lock') ?>
                        <input type="password" name="password" id="auth2Password" required autocomplete="current-password" placeholder="Your password">
                        <button type="button" class="auth2-eye" id="auth2Eye" aria-label="Show password" title="Show password"><?= icon('eye') ?></button>
                    </span>
                </label>
                <div class="auth2-row">
                    <label class="auth2-remember"><input type="checkbox" name="remember" value="1">Remember me</label>
                    <a href="<?= e(url('forgot-password.php')) ?>">Forgot password?</a>
                </div>
                <button type="submit" class="auth2-submit"><?= icon('login') ?>Sign in</button>
            </form>
            <div class="auth2-divider"><span>or access a different portal</span></div>
            <div class="auth2-portals">
                <a href="<?= e(url('admin/login.php')) ?>">
                    <?= icon('users') ?>
                    <strong>Admin Portal</strong>
                    <small>System and user management</small>
                </a>
                <a href="<?= e(url('login.php')) ?>">
                    <?= icon('teams') ?>
                    <strong>Staff Portal</strong>
                    <small>Tasks, clients, and engagement</small>
                </a>
                <a href="<?= e(url('login.php')) ?>">
                    <?= icon('staff') ?>
                    <strong>Client Portal</strong>
                    <small>Documents, invoices, and reports</small>
                </a>
            </div>
        </div>
    </div>

    <div class="auth2-foot">
        <span><?= icon('admin') ?>Secure. Compliant. Confidential.</span>
        <span>&copy; <?= e(date('Y')) ?> <?= e(setting('brand_name', 'M. Bista & Associates')) ?>. All rights reserved.</span>
        <span><?= icon('headset') ?>Need help? Contact <a href="mailto:<?= e($authSupportEmail) ?>"><?= e($authSupportEmail) ?></a></span>
        <span><?= icon('lock') ?>This is a secure login page.</span>
    </div>
</section>
<script>
(function () {
    var eye = document.getElementById('auth2Eye');
    var field = document.getElementById('auth2Password');
    if (eye && field) {
        eye.addEventListener('click', function () {
            var show = field.type === 'password';
            field.type = show ? 'text' : 'password';
            eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            eye.classList.toggle('is-on', show);
        });
    }
})();
</script>
<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
