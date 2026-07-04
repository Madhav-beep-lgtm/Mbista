<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

$pageTitle = 'Admin Login';
$bodyClass = 'auth-page';
$currentUser = current_user();

if (admin_count() === 0) {
    redirect('setup.php');
}

if ($currentUser) {
    if (($currentUser['role'] ?? '') === 'admin') {
        // For super admin (company_id 0 or 1), set context to Altiora
        $userCompanyId = (int) ($currentUser['company_id'] ?? 0);
        if ($userCompanyId === 0 || $userCompanyId === 1) {
            // Super admin - set to Altiora
            $companyToUse = 1;
        } else {
            // Company admin - use their company
            $companyToUse = $userCompanyId;
        }
        
        if (activate_company_context($companyToUse, true)) {
            redirect('admin/index.php');
        }
    }

    redirect(role_home_path($currentUser));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!login_user($email, $password)) {
        flash('error', 'Invalid admin credentials.');
        redirect('admin/login.php');
    }

    $user = current_user();
    flash('success', 'Welcome back, ' . ($user['name'] ?? 'user') . '.');
    if (($user['role'] ?? '') === 'admin') {
        // For super admin (company_id 0 or 1), set context to Altiora
        $userCompanyId = (int) ($user['company_id'] ?? 0);
        if ($userCompanyId === 0 || $userCompanyId === 1) {
            // Super admin - set to Altiora
            $companyToUse = 1;
        } else {
            // Company admin - use their company
            $companyToUse = $userCompanyId;
        }
        
        if (activate_company_context($companyToUse, true)) {
            redirect('admin/index.php');
        }
    }

    redirect(role_home_path($user));
}

include __DIR__ . '/../../app/views/partials/header.php';
?>
<section class="auth-shell">
    <div class="auth-visual">
        <a class="brand auth-brand" href="<?= e(url('index.php')) ?>">
            <span class="brand-mark">MB</span>
            <span>
                <strong><?= e(app_name()) ?></strong>
                <small>Chartered Accountants</small>
            </span>
        </a>
        <div class="auth-visual-copy">
            <h1>Trusted Expertise.<br>Clearer <span>Financial Futures.</span></h1>
            <p>Secure access to administration, company context, reports, and operational controls.</p>
        </div>
        <div class="auth-security-note"><?= icon('admin') ?>Protected admin access for authorized users only.</div>
    </div>
    <div class="auth-panel-wrap">
        <div class="form-card auth-card">
            <h2>Admin sign in</h2>
            <p>Sign in to continue to the admin workspace.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Email<input type="email" name="email" required></label>
                <label>Password<input type="password" name="password" required></label>
                <button type="submit"><?= icon('login') ?>Sign In</button>
            </form>
            <div class="actions">
                <a class="button secondary" href="<?= e(url('forgot-password.php')) ?>">Forgot password</a>
            </div>
            <div class="auth-divider"><span>Access a different portal</span></div>
            <div class="auth-portal-grid">
                <a href="<?= e(url('admin/login.php')) ?>"><?= icon('admin') ?><strong>Admin Portal</strong><small>System and user management</small></a>
                <a href="<?= e(url('login.php')) ?>"><?= icon('staff') ?><strong>Staff Portal</strong><small>Tasks, clients, and engagements</small></a>
                <a href="<?= e(url('login.php')) ?>"><?= icon('documents') ?><strong>Client Portal</strong><small>Documents, invoices, and reports</small></a>
            </div>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../../app/views/partials/footer.php'; ?>
