<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();

$companyId = (int) ($_GET['company_id'] ?? selected_company_id());
if ($companyId <= 0) {
    redirect('portal.php');
}

$company = company_by_id($companyId);
if (!$company || (int) ($company['is_active'] ?? 0) !== 1) {
    flash('error', 'Selected company is not available.');
    redirect('portal.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $pin = trim((string) ($_POST['pin'] ?? ''));
    if (!preg_match('/^[0-9]{4}$/', $pin)) {
        flash('error', 'Enter the 4-digit PIN.');
        redirect('admin/company-pin.php?company_id=' . $companyId);
    }

    if (!verify_company_pin($companyId, $pin)) {
        flash('error', 'Invalid company PIN.');
        redirect('admin/company-pin.php?company_id=' . $companyId);
    }

    set_selected_company($companyId);
    mark_company_pin_verified($companyId);

    $fyStmt = db()->prepare('SELECT * FROM fiscal_years WHERE company_id = :company_id AND is_active = 1 ORDER BY is_default DESC, start_date DESC LIMIT 1');
    $fyStmt->execute(['company_id' => $companyId]);
    $fiscalYear = $fyStmt->fetch();
    if ($fiscalYear) {
        set_context($companyId, (int) $fiscalYear['id']);
    }

    flash('success', 'Company portal unlocked.');
    redirect('admin/index.php');
}

$pageTitle = 'Company PIN';
$bodyClass = 'company-pin-page';
include __DIR__ . '/../../app/views/partials/header.php';
?>
<section class="company-pin-shell">
    <div class="company-pin-grid">
        <div class="form-card company-pin-card">
            <div class="badge"><?= icon('admin') ?>Secure company access</div>
            <h2><?= e($company['name']) ?></h2>
            <p>Enter the 4-digit admin PIN to open this management portal.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>4-digit PIN<input type="password" name="pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" minlength="4" required autofocus></label>
                <button type="submit"><?= icon('login') ?>Unlock portal</button>
            </form>
            <div class="actions">
                <a class="button secondary" href="<?= e(url('admin/company-pin-reset.php?company_id=' . $companyId)) ?>"><?= icon('settings') ?>Reset PIN</a>
            </div>
        </div>
        <div class="company-pin-showcase">
            <div class="company-pin-orbit" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </div>
            <div class="company-pin-showcase-content">
                <span class="stat-icon"><?= icon('companies') ?></span>
                <h3>Private workspace</h3>
                <p>The portal opens only the records, teams, clients, tasks, and invoices linked with this company.</p>
                <div class="company-pin-feature-row">
                    <span><?= icon('admin') ?>Admin protected</span>
                    <span><?= icon('reports') ?>Company scoped</span>
                    <span><?= icon('tasks') ?>Work ready</span>
                </div>
            </div>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../../app/views/partials/footer.php'; ?>
