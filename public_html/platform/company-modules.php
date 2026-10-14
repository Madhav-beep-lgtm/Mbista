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
        db()->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE')->execute([$targetCompanyId]);
        company_business_save($targetCompanyId, (string)($_POST['business_type'] ?? ''), (array)($_POST['modules'] ?? []));
        db()->commit();
        log_activity('company', $targetCompanyId, 'modules_updated', 'Super admin updated company package module access.', (int)current_user()['id']);
        flash('success', 'Company module access updated.');
        redirect($moduleRoute);
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        flash('error', $exception instanceof PDOException ? 'Could not update company module access.' : $exception->getMessage());
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
  <label class="workspace-span-2">Business type
   <select name="business_type" required>
    <option value="">Select business type</option>
    <?php foreach(company_business_types() as $key=>$label): ?>
    <option value="<?= e($key) ?>" <?= company_business_type($targetCompanyId)===$key ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
   </select>
  </label>
  <?php foreach (company_optional_modules() as $key=>$label): ?>
   <?php if (in_array($key,['hospitality','jewellery'],true)) continue; ?>
   <label class="company-module-option"><input type="checkbox" name="modules[]" value="<?= e($key) ?>" <?= company_module_enabled($key,$targetCompanyId) ? 'checked' : '' ?>> <?= e($label) ?></label>
  <?php endforeach; ?>
  <div class="company-form-actions"><button class="button" type="submit">Save module access</button></div>
 </form>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
