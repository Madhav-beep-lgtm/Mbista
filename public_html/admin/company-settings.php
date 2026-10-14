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
$settingsRoute = $platformShell ? 'platform/company-settings.php?company_id=' . $settingsCompanyId : 'admin/company-settings.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'fiscal_year') {
            $result = create_fiscal_year($settingsCompanyId, trim((string)($_POST['label'] ?? '')), (string)($_POST['start_date'] ?? ''), (string)($_POST['end_date'] ?? ''), isset($_POST['is_default']), (int)current_user()['id']);
            if (!$result['ok']) throw new RuntimeException($result['error']);
        } elseif ($action === 'default_year') {
            $result = set_default_fiscal_year($settingsCompanyId, (int)($_POST['fiscal_year_id'] ?? 0), (int)current_user()['id']);
            if (!$result['ok']) throw new RuntimeException($result['error']);
        } elseif ($action === 'profile') {
            $name = trim((string)($_POST['name'] ?? ''));
            $code = trim((string)($_POST['code'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150 || $code === '' || mb_strlen($code) > 30) throw new RuntimeException('Enter a company name (up to 150 characters) and code (up to 30).');
            $email = trim((string)($_POST['support_email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
            $duplicate = db()->prepare('SELECT id FROM companies WHERE code = ? AND id <> ?');
            $duplicate->execute([$code, $settingsCompanyId]);
            if ($duplicate->fetch()) throw new RuntimeException('That company code is already in use.');
            $logo = (string)($companyProfile['logo_path'] ?? '');
            $upload = $_FILES['logo'] ?? null;
            if ($upload && (int)$upload['error'] !== UPLOAD_ERR_NO_FILE) {
                if ((int)$upload['error'] !== UPLOAD_ERR_OK || (int)$upload['size'] > 2097152 || !is_uploaded_file($upload['tmp_name'])) throw new RuntimeException('Upload an image smaller than 2 MB.');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
                $ext = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime] ?? null;
                $size = getimagesize($upload['tmp_name']);
                if (!$ext || !$size || $size[0] > 4096 || $size[1] > 4096) throw new RuntimeException('Use a PNG, JPEG or WebP image up to 4096 pixels.');
                $folder = __DIR__ . '/../assets/uploads/company-assets';
                if (!is_dir($folder) && !mkdir($folder, 0755, true)) throw new RuntimeException('Logo storage is unavailable.');
                $filename = 'company-' . $settingsCompanyId . '-' . bin2hex(random_bytes(16)) . '.' . $ext;
                if (!move_uploaded_file($upload['tmp_name'], $folder . '/' . $filename)) throw new RuntimeException('Could not save logo.');
                $logo = 'assets/uploads/company-assets/' . $filename;
            }
            db()->beginTransaction();
            db()->prepare('UPDATE companies SET name = ?, code = ?, logo_path = ? WHERE id = ?')->execute([$name,$code,$logo,$settingsCompanyId]);
            update_settings(['site_name'=>$name,'company_logo_path'=>$logo,'support_email'=>$email,'support_phone'=>trim((string)($_POST['support_phone'] ?? '')),'office_address'=>trim((string)($_POST['office_address'] ?? ''))], $settingsCompanyId);
            db()->commit();
        } else {
            throw new RuntimeException('Unknown settings action.');
        }
        log_activity('company', $settingsCompanyId, 'settings_updated', 'Updated company ' . $action . ' settings.', (int)current_user()['id']);
        flash('success', 'Company settings saved.');
        redirect($settingsRoute);
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        flash('error', $exception instanceof PDOException ? 'Could not save company settings.' : $exception->getMessage());
    }
}
$yearsQuery = db()->prepare('SELECT * FROM fiscal_years WHERE company_id = ? ORDER BY start_date DESC');
$yearsQuery->execute([$settingsCompanyId]);
$companyYears = $yearsQuery->fetchAll();
$pageTitle = 'Company Settings';
$bodyClass = 'admin-layout company-settings-page';
$pageSubtitle = (string)$companyProfile['name'];
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card company-details-card">
 <div class="mbw-card-head"><h2>Company details</h2><?php if ($platformShell): ?><a class="button secondary" href="<?= e(url('platform/index.php')) ?>">Back to companies</a><?php endif; ?></div>
 <form method="post" enctype="multipart/form-data" class="workspace-form-grid">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="profile">
  <label class="workspace-span-2">Company name<input name="name" maxlength="150" required autocomplete="organization" value="<?= e($companyProfile['name']) ?>"></label>
  <label>Company code<input name="code" maxlength="30" required value="<?= e($companyProfile['code']) ?>"></label>
  <label>Email<input type="email" name="support_email" value="<?= e(setting('support_email','',false,$settingsCompanyId)) ?>"></label>
  <label>Phone<input type="tel" name="support_phone" autocomplete="tel" value="<?= e(setting('support_phone','',false,$settingsCompanyId)) ?>"></label>
  <label>Logo (PNG, JPEG, WebP; max 2 MB)<input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
  <label class="company-full-width">Address<input name="office_address" autocomplete="street-address" value="<?= e(setting('office_address','',false,$settingsCompanyId)) ?>"></label>
  <div class="company-form-actions">
  <?php if (!empty($companyProfile['logo_path'])): ?><img class="company-logo-preview" src="<?= e(url($companyProfile['logo_path'])) ?>" alt="Company logo" width="96" height="64"><?php endif; ?>
  <button class="button" type="submit">Save details</button></div>
 </form>
</section>
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
