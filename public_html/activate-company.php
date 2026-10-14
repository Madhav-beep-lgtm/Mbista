<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
header('Cache-Control: no-store');
$user = current_user();
$id = (int)($_SESSION['license_company_id'] ?? current_company_id());
if ($id <= 0 || !user_can_access_company($id,$user)) deny_access('Company access required.');
$company = company_by_id($id);
$license = company_license_read($id);
if (company_license_state($license) === 'Active') {
    unset($_SESSION['license_company_id']);
    activate_company_context($id);
    redirect(role_home_path($user));
}
$canActivate = company_license_owner($user,$id);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canActivate) deny_access('Company owner or administrator required.');
    if (login_is_throttled((string)$user['email'],'license_failed')) {
        flash('error','Too many attempts. Please try again later.');
        redirect('activate-company.php');
    }
    try {
        db()->beginTransaction();
        db()->prepare('SELECT id FROM companies WHERE id = ? FOR UPDATE')->execute([$id]);
        company_license_activate($id,$user,(string)($_POST['license_key'] ?? ''));
        db()->commit();
        log_activity('company',$id,'license_activated','Company owner activated license.',(int)$user['id']);
        unset($_SESSION['license_company_id']);
        activate_company_context($id);
        redirect(role_home_path($user));
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        security_event('license_failed','failure','Company activation failed.',$id,(int)$user['id'],(string)$user['email']);
        flash('error',$exception instanceof PDOException ? 'Activation failed. Try again.' : $exception->getMessage());
    }
}
$pageTitle = 'Activate Company';
include __DIR__ . '/../app/views/partials/header.php';
?>
<section class="container section"><div class="form-card"><h2><?= e($company['name']) ?></h2><p><?= e(company_license_state($license)) ?></p>
<?php if ($canActivate && company_license_state($license) === 'Awaiting activation'): ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>License key<input name="license_key" required maxlength="64" autocomplete="off" spellcheck="false"></label><button class="button" type="submit">Activate company</button></form>
<?php else: ?><p>Contact the platform administrator or company owner to activate or renew access.</p><?php endif; ?>
<a class="button secondary" href="<?= e(url('logout.php')) ?>">Sign out</a></div></section>
<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
