<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_admin();
if (!user_is_super_admin()) deny_access('Only the super admin can manage company package access.');

// Platform-selected target only. Never enter or switch the company's books.
$targetCompanyId = (int)($_GET['company_id'] ?? 0);
$targetCompany = $targetCompanyId > 0 ? company_by_id($targetCompanyId) : null;
if (!$targetCompany) deny_access('Company not found.');
$moduleRoute = 'platform/company-modules.php?company_id=' . $targetCompanyId;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        db()->beginTransaction();
        company_modules_save($targetCompanyId, (array)($_POST['modules'] ?? []));
        db()->commit();
        log_activity('company', $targetCompanyId, 'modules_updated', 'Super admin updated company package module access.', (int)current_user()['id']);
        flash('success', 'Company module access updated.');
        redirect($moduleRoute);
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        flash('error', 'Could not update company module access.');
    }
}
$platformShell = true;
$bodyClass = 'admin-layout company-settings-page';
$pageTitle = 'Company Module Access';
$pageSubtitle = (string)$targetCompany['name'] . ' · ' . (string)$targetCompany['code'];
$pageBreadcrumb = [['Platform', 'platform/index.php'], ['Companies', 'platform/index.php']];
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card">
 <div class="mbw-card-head"><h2>Package module access</h2><a class="button secondary" href="<?= e(url('platform/index.php')) ?>">Back to companies</a></div>
 <form method="post" action="<?= e(url($moduleRoute)) ?>" class="workspace-form-grid">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
  <?php foreach (company_optional_modules() as $key=>$label): ?>
   <label class="company-module-option"><input type="checkbox" name="modules[]" value="<?= e($key) ?>" <?= company_module_enabled($key,$targetCompanyId) ? 'checked' : '' ?>> <?= e($label) ?></label>
  <?php endforeach; ?>
  <div class="company-form-actions"><button class="button" type="submit">Save module access</button></div>
 </form>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
