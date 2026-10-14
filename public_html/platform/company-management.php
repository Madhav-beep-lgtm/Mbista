<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_admin();
if (!user_is_super_admin()) deny_access('Super admin required.');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['company_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    try {
        db()->beginTransaction();
        $lock = db()->prepare('SELECT id FROM companies WHERE id = ? FOR UPDATE');
        $lock->execute([$id]);
        if (!$lock->fetch()) throw new RuntimeException('Company not found.');
        $license = company_license_read($id);
        if ($action === 'issue') {
            if (!empty($license['hash'])) throw new RuntimeException('A key already exists. Use Replace key to invalidate it.');
            $key = company_license_issue($id,(string)($_POST['expires'] ?? ''));
        } elseif ($action === 'replace') {
            $key = company_license_issue($id,(string)($_POST['expires'] ?? ''));
        } elseif (in_array($action,['activate','deactivate','renew'],true)) {
            if (!$license) throw new RuntimeException('Generate a license first.');
            if ($action === 'renew') {
                $expires = company_license_expiry((string)($_POST['expires'] ?? ''));
                if ($expires <= ($license['expires'] ?? '')) throw new RuntimeException('Renewal must extend the existing expiry date.');
                $license['expires'] = $expires;
            } else {
                $license['enabled'] = $action === 'activate';
                db()->prepare('UPDATE companies SET is_active = ? WHERE id = ?')->execute([$license['enabled'] ? 1 : 0,$id]);
            }
            company_license_write($id,$license);
        } else throw new RuntimeException('Unknown action.');
        db()->commit();
        if (isset($key)) $_SESSION['issued_company_license'] = ['company_id'=>$id,'key'=>$key];
        log_activity('company',$id,'license_'.$action,'Company license management updated.',(int)current_user()['id']);
        flash('success','Company license updated.');
        redirect('platform/company-management.php');
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        flash('error',$exception instanceof PDOException ? 'License update failed.' : $exception->getMessage());
    }
}
$issued = $_SESSION['issued_company_license'] ?? null;
unset($_SESSION['issued_company_license']);
$page = max(1,(int)($_GET['page'] ?? 1));
$total = (int)db()->query('SELECT COUNT(*) FROM companies')->fetchColumn();
$page = min($page,max(1,(int)ceil($total/50)));
$stmt = db()->prepare("SELECT c.id,c.name,c.code,c.is_active,s.setting_value AS license_json FROM companies c LEFT JOIN settings s ON s.company_id = c.id AND s.setting_key = 'company_license' ORDER BY c.name,c.id LIMIT 50 OFFSET " . (($page-1)*50));
$stmt->execute();
$companies = $stmt->fetchAll();
$platformShell = true;
$pageTitle = 'Company Management';
$pageHero = false;
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<?php if ($issued): ?><section class="mbw-card"><h2>License key · Company #<?= (int)$issued['company_id'] ?></h2><p><code><?= e($issued['key']) ?></code></p><p>Copy and securely share with the company owner. This key is shown once.</p></section><?php endif; ?>
<section class="mbw-card">
 <div class="mbw-card-head"><h2>Company Management</h2><a class="button secondary" href="<?= e(url('platform/companies.php')) ?>">New company</a></div>
 <div class="table-responsive"><table><thead><tr><th>Company</th><th>Code</th><th>License</th><th>Expiry date</th><th>Activation date</th><th>Manage</th></tr></thead><tbody>
 <?php foreach ($companies as $company): $license = json_decode((string)($company['license_json'] ?? ''),true) ?: []; ?>
 <tr><td><?= e($company['name']) ?><?= !$company['is_active'] ? ' (company inactive)' : '' ?></td><td><?= e($company['code']) ?></td><td><?= e(company_license_state($license)) ?></td><td><?= !empty($license['expires']) ? e(app_date($license['expires'])) : '—' ?></td><td><?= !empty($license['activated_at']) ? e(app_date(substr($license['activated_at'],0,10))) : '—' ?></td><td>
 <form method="post" class="inline-action-form">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="company_id" value="<?= (int)$company['id'] ?>">
  <label>Expiry date<input type="date" name="expires" value="<?= e($license['expires'] ?? '') ?>"></label>
  <?php if (!$license): ?><button class="button" name="action" value="issue">Generate key</button><?php else: ?>
  <button class="button secondary" name="action" value="<?= !empty($license['enabled']) && $company['is_active'] ? 'deactivate' : 'activate' ?>"><?= !empty($license['enabled']) && $company['is_active'] ? 'Deactivate' : 'Activate' ?></button>
  <button class="button secondary" name="action" value="renew">Renew</button>
  <button class="button secondary" name="action" value="replace" onclick="return confirm('Invalidate the current key and require activation again?')">Replace key</button>
  <?php endif; ?>
 </form></td></tr><?php endforeach; ?>
 </tbody></table></div>
 <div class="actions"><?php if ($page > 1): ?><a class="button secondary" href="?page=<?= $page-1 ?>">Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= max(1,(int)ceil($total/50)) ?></span><?php if ($page*50 < $total): ?><a class="button secondary" href="?page=<?= $page+1 ?>">Next</a><?php endif; ?></div>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
