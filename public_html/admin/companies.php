<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/company_onboarding.php';
require_admin();
if (!user_is_super_admin()) deny_access('Super admin access required.');
if (!defined('PLATFORM_COMPANIES_PAGE')) redirect('platform/companies.php');
accounting_module_repair_database();
$platformShell = true;
$pageTitle = 'New Company';
$pageHero = false;
$bodyClass = 'admin-layout company-settings-page';
$pageBreadcrumb = [['Platform', 'platform/index.php'], ['Companies', 'platform/index.php']];
$parentColumnExists = column_exists('companies', 'parent_company_id');
$values = ['name'=>'','code'=>'','parent_company_id'=>'','support_email'=>'','support_phone'=>'','office_address'=>'','registered_address'=>'','company_pan'=>'','user_name'=>'','user_email'=>'','is_active'=>'1'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (array_keys($values) as $key) $values[$key] = trim((string)($_POST[$key] ?? ''));
    $values['code'] = strtoupper($values['code']);
    try {
        if (($_POST['action'] ?? '') !== 'create_company') throw new RuntimeException('Only company creation is available here.');
        $onboarding = company_onboarding_values($_POST);
        $licenseExpiry = company_license_expiry((string)($_POST['license_expires'] ?? ''));
        if (!column_exists('users','must_change_password')) throw new RuntimeException('Deploy the database updates before creating a company user.');
        if (user_by_email($onboarding['email'])) throw new RuntimeException('That user email is already registered.');
        if (mb_strlen($values['office_address']) > 255 || mb_strlen($values['registered_address']) > 255 || mb_strlen($values['support_phone']) > 60) throw new RuntimeException('Address or phone is too long.');
        if ($values['name'] === '' || mb_strlen($values['name']) > 150 || $values['code'] === '' || mb_strlen($values['code']) > 30) throw new RuntimeException('Enter a company name (up to 150 characters) and code (up to 30).');
        if ($values['support_email'] !== '' && !filter_var($values['support_email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
        $duplicate = db()->prepare('SELECT id FROM companies WHERE code = ? LIMIT 1');
        $duplicate->execute([$values['code']]);
        if ($duplicate->fetch()) throw new RuntimeException('That company code is already in use.');
        $parentId = $values['parent_company_id'] === '' ? null : (int)$values['parent_company_id'];
        if ($parentId !== null) {
            if (!$parentColumnExists || $parentId <= 0) throw new RuntimeException('Select a valid company group.');
            $parent = db()->prepare('SELECT id FROM companies WHERE id = ? AND is_active = 1 AND is_client_company = 0');
            $parent->execute([$parentId]);
            if (!$parent->fetch()) throw new RuntimeException('Selected company group is unavailable.');
        }
        db()->beginTransaction();
        $sql = 'INSERT INTO companies (name, code, is_active, inventory_accounting' . ($parentColumnExists ? ', parent_company_id' : '') . ') VALUES (?, ?, ?, ?' . ($parentColumnExists ? ', ?' : '') . ')';
        $params = [$values['name'],$values['code'],isset($_POST['is_active']) ? 1 : 0,'periodic'];
        if ($parentColumnExists) $params[] = $parentId;
        db()->prepare($sql)->execute($params);
        $companyId = (int)db()->lastInsertId();
        update_settings(['site_name'=>$values['name'],'support_email'=>$values['support_email'],'support_phone'=>$values['support_phone'],'office_address'=>$values['office_address']],$companyId);
        update_settings(['company_pan'=>$onboarding['pan'],'registered_address'=>$values['registered_address']],$companyId);
        company_onboarding_save($companyId,$values,$onboarding,(int)current_user()['id']);
        $licenseKey = company_license_issue($companyId,$licenseExpiry);
        db()->commit();
        $_SESSION['issued_company_license'] = ['company_id'=>$companyId,'key'=>$licenseKey];
        log_activity('company',$companyId,'created','Company created from platform workflow.',(int)current_user()['id']);
        flash('success','Company and first user created. The user must change their temporary password at first login.');
        redirect('platform/company-management.php');
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        flash('error',$exception instanceof PDOException ? 'Could not create company and user. No changes were saved. Check the company code and user email are unique.' : $exception->getMessage());
    }
}
$groupCompanies = $parentColumnExists ? db()->query('SELECT id, name FROM companies WHERE is_active = 1 AND is_client_company = 0 ORDER BY name')->fetchAll() : [];
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card company-details-card">
 <div class="mbw-card-head"><h2>New company</h2><a class="button secondary" href="<?= e(url('platform/index.php')) ?>">Back to companies</a></div>
 <form method="post" class="workspace-form-grid">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_company">
  <label class="workspace-span-2">Company name<input name="name" maxlength="150" required autocomplete="organization" value="<?= e($values['name']) ?>"></label>
  <label>Company code<input name="code" maxlength="30" required spellcheck="false" value="<?= e($values['code']) ?>"></label>
  <label>Email<input type="email" name="support_email" autocomplete="email" value="<?= e($values['support_email']) ?>"></label>
  <label>Phone<input type="tel" name="support_phone" autocomplete="tel" value="<?= e($values['support_phone']) ?>"></label>
  <?php if ($parentColumnExists): ?><label>Company group<select name="parent_company_id"><option value="">Independent company</option><?php foreach ($groupCompanies as $parent): ?><option value="<?= (int)$parent['id'] ?>" <?= (string)$parent['id'] === $values['parent_company_id'] ? 'selected' : '' ?>><?= e($parent['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
  <label class="company-full-width">Address<input name="office_address" autocomplete="street-address" value="<?= e($values['office_address']) ?>"></label>
  <label>PAN<input name="company_pan" maxlength="60" value="<?= e($values['company_pan']) ?>"></label>
  <label>License expiry date<input type="date" name="license_expires" required value="<?= e($_POST['license_expires'] ?? '') ?>"></label>
  <label class="workspace-span-2">Registered address<input name="registered_address" maxlength="255" value="<?= e($values['registered_address']) ?>"></label>
  <fieldset class="company-full-width"><legend>Bank accounts</legend><div id="company-bank-rows">
   <?php $bankRows = isset($_POST['banks']) && is_array($_POST['banks']) ? array_values(array_filter(array_slice($_POST['banks'],0,20),'is_array')) : []; if (!$bankRows) $bankRows = [[]]; foreach ($bankRows as $index=>$bank): ?>
   <div class="workspace-form-grid company-bank-row">
    <?php foreach (['bank_name'=>'Bank name','account_name'=>'Account name','account_number'=>'Account number','branch'=>'Branch'] as $field=>$label): ?><label><?= e($label) ?><input name="banks[<?= (int)$index ?>][<?= e($field) ?>]" value="<?= e($bank[$field] ?? '') ?>"></label><?php endforeach; ?>
    <div class="company-form-actions"><button type="button" class="button secondary" data-remove-bank>Remove account</button></div>
   </div><?php endforeach; ?>
  </div><button type="button" class="button secondary" id="add-company-bank">Add bank account</button></fieldset>
  <fieldset class="company-full-width"><legend>First user</legend><div class="workspace-form-grid">
   <label>User name<input name="user_name" maxlength="150" required value="<?= e($values['user_name']) ?>"></label>
   <label>Login email<input type="email" name="user_email" maxlength="190" required value="<?= e($values['user_email']) ?>"></label>
   <label>Temporary password<input type="password" name="temporary_password" minlength="12" maxlength="72" required autocomplete="new-password"><small>12–72 characters: uppercase, lowercase and a number.</small></label>
  </div></fieldset>
  <div class="company-form-actions"><label class="checkbox-line"><input type="checkbox" name="is_active" value="1" <?= $values['is_active'] ? 'checked' : '' ?>>Active</label></div>
  <div class="company-form-actions"><button class="button" type="submit">Create company</button><a class="button secondary" href="<?= e(url('platform/index.php')) ?>">Cancel</a></div>
 </form>
</section>
<script>
(() => {
 const rows = document.getElementById('company-bank-rows');
 const template = rows.querySelector('.company-bank-row').cloneNode(true);
 let next = <?= count($bankRows) ?>;
 document.getElementById('add-company-bank').addEventListener('click', () => {
  if (rows.children.length >= 20) return;
  const row = template.cloneNode(true);
  row.querySelectorAll('input').forEach(input => { input.value = ''; input.name = input.name.replace(/banks\[\d+\]/, 'banks[' + next + ']'); });
  next++; rows.appendChild(row);
 });
 rows.addEventListener('click', event => { if (event.target.closest('[data-remove-bank]')) event.target.closest('.company-bank-row').remove(); });
})();
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
