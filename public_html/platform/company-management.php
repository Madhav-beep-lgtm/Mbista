<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/company_access_email.php';
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
        if (isset($key)) {
            $_SESSION['issued_company_license'] = ['company_id'=>$id,'key'=>$key];
            $recipient = db()->prepare('SELECT c.name,c.code,u.email FROM companies c JOIN client_profiles cp ON cp.books_company_id = c.id JOIN users u ON u.id = cp.user_id WHERE c.id = ? ORDER BY cp.id LIMIT 1');
            $recipient->execute([$id]);
            $owner = $recipient->fetch();
            $sent = $owner && company_access_email($owner,$owner['email'],$key);
            flash($sent ? 'success' : 'error',$sent ? 'License key sent to the company owner email.' : 'License saved, but email could not be sent. Check the owner email and mail settings; securely share the displayed key.');
        }
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
$profilesByCompany = [];
if ($companies) {
    $ids = array_column($companies,'id');
    $profiles = db()->prepare('SELECT cp.*,u.name AS contact_name,u.email FROM client_profiles cp JOIN users u ON u.id = cp.user_id WHERE cp.books_company_id IN (' . implode(',',array_fill(0,count($ids),'?')) . ') ORDER BY cp.id');
    $profiles->execute($ids);
    foreach ($profiles->fetchAll() as $profile) $profilesByCompany[(int)$profile['books_company_id']][] = $profile;
}
$clientPage = max(1,(int)($_GET['client_page'] ?? 1));
$unlinkedTotal = (int)db()->query('SELECT COUNT(*) FROM client_profiles cp WHERE NOT EXISTS (SELECT 1 FROM companies c WHERE c.id = cp.books_company_id)')->fetchColumn();
$clientPage = min($clientPage,max(1,(int)ceil($unlinkedTotal/50)));
$unlinked = db()->query('SELECT cp.*,u.name AS contact_name,u.email FROM client_profiles cp JOIN users u ON u.id = cp.user_id WHERE NOT EXISTS (SELECT 1 FROM companies c WHERE c.id = cp.books_company_id) ORDER BY cp.organization_name,cp.id LIMIT 50 OFFSET ' . (($clientPage-1)*50))->fetchAll();
$platformShell = true;
$pageTitle = 'Company Management';
$pageHero = false;
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<?php if ($issued): ?><section class="mbw-card"><h2>License key · Company #<?= (int)$issued['company_id'] ?></h2><p><code><?= e($issued['key']) ?></code></p><p>Copy and securely share with the company owner. This key is shown once.</p></section><?php endif; ?>
<section class="mbw-card">
 <div class="table-responsive"><table><thead><tr><th>Company</th><th>Code</th><th>Registration / PAN</th><th>License</th><th>Expiry date</th><th>Activation date</th><th>Manage</th></tr></thead><tbody>
 <?php foreach ($companies as $company): $license = json_decode((string)($company['license_json'] ?? ''),true) ?: []; ?>
 <tr><td><a href="<?= e(url('platform/company-settings.php?company_id=' . (int)$company['id'])) ?>"><?= e($company['name']) ?></a><?= !$company['is_active'] ? ' (company inactive)' : '' ?>
 <?php foreach ($profilesByCompany[(int)$company['id']] ?? [] as $profile): ?><div><small><?= e($profile['contact_name']) ?> · <?= e($profile['email']) ?></small></div><?php endforeach; ?>
 </td><td><?= e($company['code']) ?></td><td>
 <?php foreach ($profilesByCompany[(int)$company['id']] ?? [] as $profile): ?><div><?= e($profile['registration_no'] ?: '—') ?> / <?= e($profile['pan_no'] ?: '—') ?><?php include __DIR__ . '/../../app/views/partials/company_client_actions.php'; ?></div><?php endforeach; ?>
 </td><td><?= e(company_license_state($license)) ?></td>
 <td><input aria-label="Expiry date for <?= e($company['name']) ?>" type="date" name="expires" form="license-<?= (int)$company['id'] ?>" value="<?= e($license['expires'] ?? '') ?>"></td>
 <td><?= !empty($license['issued_at']) ? e(app_date(substr($license['issued_at'],0,10))) : '—' ?></td><td>
 <form id="license-<?= (int)$company['id'] ?>" method="post" class="inline-action-form">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="company_id" value="<?= (int)$company['id'] ?>">
  <?php if (!$license): ?><button class="button" name="action" value="issue">Generate key</button><?php else: ?>
  <button class="button secondary" name="action" value="<?= !empty($license['enabled']) && $company['is_active'] ? 'deactivate' : 'activate' ?>"><?= !empty($license['enabled']) && $company['is_active'] ? 'Deactivate' : 'Activate' ?></button>
  <button class="button secondary" name="action" value="renew">Renew</button>
  <button class="button secondary" name="action" value="replace" onclick="return confirm('Invalidate the current key and require activation again?')">Replace key</button>
  <?php endif; ?>
 </form></td></tr><?php endforeach; ?>
 <?php foreach ($unlinked as $profile): ?><tr><td><?= e($profile['organization_name']) ?><div><small><?= e($profile['contact_name']) ?> · <?= e($profile['email']) ?></small></div></td><td><?= e($profile['client_code'] ?? '—') ?></td><td><?= e($profile['registration_no'] ?: '—') ?> / <?= e($profile['pan_no'] ?: '—') ?></td><td>Not provisioned</td><td>—</td><td>—</td><td><?php include __DIR__ . '/../../app/views/partials/company_client_actions.php'; ?></td></tr><?php endforeach; ?>
 </tbody></table></div>
 <?php if ($unlinkedTotal > 50): ?><div class="actions"><?php if ($clientPage > 1): ?><a href="?page=<?= $page ?>&client_page=<?= $clientPage-1 ?>">Previous client records</a><?php endif; ?> <span>Client records page <?= $clientPage ?></span> <?php if ($clientPage*50 < $unlinkedTotal): ?><a href="?page=<?= $page ?>&client_page=<?= $clientPage+1 ?>">Next client records</a><?php endif; ?></div><?php endif; ?>
 <div class="actions"><?php if ($page > 1): ?><a class="button secondary" href="?page=<?= $page-1 ?>">Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= max(1,(int)ceil($total/50)) ?></span><?php if ($page*50 < $total): ?><a class="button secondary" href="?page=<?= $page+1 ?>">Next</a><?php endif; ?></div>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
