<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$pageTitle = 'Initial Setup';
$adminExists = admin_count() > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if ($adminExists) {
        flash('error', 'Setup is already complete.');
        redirect('login.php');
    }

    $siteName = trim((string) ($_POST['site_name'] ?? APP_NAME));
    $siteTagline = trim((string) ($_POST['site_tagline'] ?? ''));
    $supportEmail = trim((string) ($_POST['support_email'] ?? ''));
    $supportPhone = trim((string) ($_POST['support_phone'] ?? ''));
    $officeAddress = trim((string) ($_POST['office_address'] ?? ''));
    $aboutText = trim((string) ($_POST['about_text'] ?? ''));
    $name = trim((string) ($_POST['admin_name'] ?? ''));
    $email = trim((string) ($_POST['admin_email'] ?? ''));
    $password = (string) ($_POST['admin_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($name === '' || $email === '' || $password === '' || $siteName === '') {
        flash('error', 'Complete the setup form before continuing.');
        redirect('setup.php');
    }

    if ($password !== $confirmPassword) {
        flash('error', 'Passwords do not match.');
        redirect('setup.php');
    }

    update_settings([
        'site_name' => $siteName,
        'site_tagline' => $siteTagline,
        'support_email' => $supportEmail,
        'support_phone' => $supportPhone,
        'office_address' => $officeAddress,
        'about_text' => $aboutText,
    ]);

    store_first_admin([
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'phone' => trim((string) ($_POST['admin_phone'] ?? '')),
        'company' => trim((string) ($_POST['admin_company'] ?? '')),
    ]);

    flash('success', 'Setup complete. Log in with your new admin account.');
    redirect('login.php');
}

include __DIR__ . '/../app/views/partials/header.php';
?>
<section class="section">
    <div class="container auth-grid">
        <div class="form-card">
            <h2>First-run setup</h2>
            <?php if ($adminExists): ?>
                <p>Setup is already complete. Use the login page to access the site.</p>
                <a class="button" href="<?= e(url('login.php')) ?>">Go to login</a>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <label>Site name<input type="text" name="site_name" value="<?= e(setting('site_name', APP_NAME)) ?>" required></label>
                    <label>Site tagline<input type="text" name="site_tagline" value="<?= e(setting('site_tagline', 'Hosting made simple')) ?>"></label>
                    <label>Support email<input type="email" name="support_email" value="<?= e(setting('support_email', 'support@example.com')) ?>"></label>
                    <label>Support phone<input type="text" name="support_phone" value="<?= e(setting('support_phone', '+977-9800000000')) ?>"></label>
                    <label>Office address<input type="text" name="office_address" value="<?= e(setting('office_address', 'Kathmandu, Nepal')) ?>"></label>
                    <label>About text<textarea name="about_text"><?= e(setting('about_text', 'M.Bista Altiora Complete Hosting is a lightweight platform for managing hosting products and customer requests.')) ?></textarea></label>
                    <hr>
                    <label>Admin name<input type="text" name="admin_name" required></label>
                    <label>Admin email<input type="email" name="admin_email" required></label>
                    <label>Admin phone<input type="text" name="admin_phone"></label>
                    <label>Company<input type="text" name="admin_company"></label>
                    <label>Password<input type="password" name="admin_password" required></label>
                    <label>Confirm password<input type="password" name="confirm_password" required></label>
                    <button type="submit">Create admin account</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3>Deployment checklist</h3>
            <ul class="checklist">
                <li>Import the SQL schema first</li>
                <li>Set your MySQL credentials in the .env file</li>
                <li>Use this page only if no admin account exists</li>
                <li>Remove or protect this file before production use</li>
            </ul>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
