<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_login();
$platformShell = defined('PLATFORM_COMPANY_SETTINGS');
if ($platformShell) {
    require_admin();
    if (!user_is_super_admin()) deny_access('Super admin access required.');
    $settingsCompanyId = (int)($_GET['company_id'] ?? 0);
} else {
    require_company_context();
    if (!company_can_manage_settings()) deny_access('Company owner or administrator access required.');
    $settingsCompanyId = current_company_id();
}
$companyProfile = company_by_id($settingsCompanyId);
if (!$companyProfile) deny_access('Company not found.');
$isFiscalSettings = defined('COMPANY_FISCAL_SETTINGS');
$detailsRoute = $platformShell ? 'platform/company-settings.php?company_id=' . $settingsCompanyId : 'admin/company-settings.php';
$settingsRoute = $platformShell ? 'platform/company-fiscal-years.php?company_id=' . $settingsCompanyId : 'admin/company-fiscal-years.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$isFiscalSettings) deny_access('Company details are read-only.');
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'fiscal_year') {
            $result = create_fiscal_year($settingsCompanyId, trim((string)($_POST['label'] ?? '')), (string)($_POST['start_date'] ?? ''), (string)($_POST['end_date'] ?? ''), isset($_POST['is_default']), (int)current_user()['id']);
        } elseif ($action === 'default_year') {
            $result = set_default_fiscal_year($settingsCompanyId, (int)($_POST['fiscal_year_id'] ?? 0), (int)current_user()['id']);
        } else {
            throw new RuntimeException('Unknown fiscal year action.');
        }
        if (!$result['ok']) throw new RuntimeException($result['error']);
        flash('success','Fiscal year settings saved.');
        redirect($settingsRoute);
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        flash('error', $exception instanceof PDOException ? 'Could not save fiscal year.' : $exception->getMessage());
    }
}
$pageTitle = $isFiscalSettings ? 'Fiscal Years' : 'Company Details';
$pageHero = false;
$bodyClass = 'admin-layout company-settings-page';
if (!$isFiscalSettings) {
    $bankQuery = db()->prepare('SELECT bank_name, account_name, account_number, branch FROM company_bank_accounts WHERE company_id = ? ORDER BY is_default DESC, sort_order, id');
    $bankQuery->execute([$settingsCompanyId]);
    $detailBanks = $bankQuery->fetchAll();
    $ownerQuery = db()->prepare('SELECT u.name, u.email FROM users u INNER JOIN client_profiles cp ON cp.user_id = u.id WHERE cp.books_company_id = ? ORDER BY u.id LIMIT 1');
    $ownerQuery->execute([$settingsCompanyId]);
    $detailOwner = $ownerQuery->fetch();
    include __DIR__ . '/../../app/views/partials/admin_header.php';
    include __DIR__ . '/../../app/views/partials/company_details_readonly.php';
    include __DIR__ . '/../../app/views/partials/admin_footer.php';
    return;
}
$yearsQuery = db()->prepare('SELECT * FROM fiscal_years WHERE company_id = ? ORDER BY start_date DESC');
$yearsQuery->execute([$settingsCompanyId]);
$companyYears = $yearsQuery->fetchAll();
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="actions" style="margin-bottom:16px"><a class="button secondary" href="<?= e(url($detailsRoute)) ?>">Company details</a></div>
<section class="mbw-card">
 <div class="mbw-card-head"><h2>Fiscal years</h2></div>
 <form method="post" class="workspace-form-grid company-year-form">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="fiscal_year">
  <label>Year label<input name="label" required maxlength="50"></label>
  <label>From date<input type="date" name="start_date" required></label>
  <label>To date<input type="date" name="end_date" required></label>
  <div class="company-form-actions"><label class="checkbox-line"><input type="checkbox" name="is_default" value="1"> Default fiscal year</label>
  <button class="button" type="submit">Add fiscal year</button></div>
 </form>
 <div class="table-responsive"><table><thead><tr><th>Fiscal year</th><th>From</th><th>To</th><th>Default</th></tr></thead><tbody>
 <?php foreach ($companyYears as $year): ?><tr><td><?= e($year['label']) ?></td><td><?= e(app_date($year['start_date'])) ?></td><td><?= e(app_date($year['end_date'])) ?></td><td><?php if ($year['is_default']): ?>Yes<?php else: ?><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="default_year"><input type="hidden" name="fiscal_year_id" value="<?= (int)$year['id'] ?>"><button class="button secondary" type="submit">Set default</button></form><?php endif; ?></td></tr><?php endforeach; ?>
 <?php if (!$companyYears): ?><tr><td colspan="4">No fiscal years created.</td></tr><?php endif; ?>
 </tbody></table></div>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
