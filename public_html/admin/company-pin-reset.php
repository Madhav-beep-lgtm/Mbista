<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();

$companyId = (int) ($_GET['company_id'] ?? selected_company_id());
if ($companyId <= 0) {
    redirect('portal.php');
}

$company = company_by_id($companyId);
if (!$company) {
    flash('error', 'Selected company is not available.');
    redirect('portal.php');
}

// A PIN reset is a portal-opening credential: only an admin authorized for the
// target company (its own tree or an explicit membership) may reset it. Without
// this, any admin could reset any company's PIN with their own password.
require_company_access($companyId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $adminPassword = (string) ($_POST['admin_password'] ?? '');
    $newPin = trim((string) ($_POST['new_pin'] ?? ''));
    $confirmPin = trim((string) ($_POST['confirm_pin'] ?? ''));

    $currentUser = current_user();
    $userRecord = $currentUser ? user_by_email((string) $currentUser['email']) : null;
    if (!$currentUser || !$userRecord || !password_verify($adminPassword, (string) ($userRecord['password_hash'] ?? ''))) {
        flash('error', 'Super admin password is required to reset the PIN.');
        redirect('admin/company-pin-reset.php?company_id=' . $companyId);
    }

    if (!preg_match('/^[0-9]{4}$/', $newPin) || $newPin !== $confirmPin) {
        flash('error', 'Enter and confirm a 4-digit PIN.');
        redirect('admin/company-pin-reset.php?company_id=' . $companyId);
    }

    update_company_pin($companyId, $newPin);
    flash('success', 'Company PIN reset successfully.');
    redirect('admin/company-pin.php?company_id=' . $companyId);
}

include __DIR__ . '/../../app/views/partials/header.php';
?>
<section class="section">
    <div class="container auth-grid">
        <div class="form-card">
            <div class="badge">Reset company PIN</div>
            <h2><?= e($company['name']) ?></h2>
            <p>Use your super admin password to reset this company’s 4-digit PIN.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Super admin password<input type="password" name="admin_password" required></label>
                <label>New 4-digit PIN<input type="password" name="new_pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" minlength="4" required></label>
                <label>Confirm PIN<input type="password" name="confirm_pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" minlength="4" required></label>
                <button type="submit">Reset PIN</button>
            </form>
            <div class="actions">
                <a class="button secondary" href="<?= e(url('admin/company-pin.php?company_id=' . $companyId)) ?>">Back</a>
            </div>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../../app/views/partials/footer.php'; ?>