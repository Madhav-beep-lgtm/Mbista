<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();
$user = current_user();
$userId = (int) ($user['id'] ?? 0);
$profile = table_exists('staff_profiles') ? (staff_profile($userId) ?: []) : [];
$passwordError = static function (string $password): ?string {
    return strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)
        ? 'Password must be at least 8 characters and include uppercase, lowercase, and a number.' : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $account = user_by_email((string) ($user['email'] ?? ''));

    if ($action === 'update_profile') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        if ($name === '') { flash('error', 'Full name is required.'); redirect('admin/profile.php'); }
        if ($phone !== '' && !preg_match('/^[0-9+\-\s().]{6,30}$/', $phone)) { flash('error', 'Phone number contains invalid characters.'); redirect('admin/profile.php'); }
        try {
            $photoPath = upload_image_file('profile_photo', 'assets/uploads/profile-photos', $profile['photo_path'] ?? null);
            db()->prepare('UPDATE users SET name=:name, phone=:phone WHERE id=:id')->execute(['name'=>$name,'phone'=>$phone !== '' ? $phone : null,'id'=>$userId]);
            if (table_exists('staff_profiles')) {
                db()->prepare('INSERT INTO staff_profiles (user_id, job_title, department, bio, photo_path) VALUES (:uid,:job,:department,:bio,:photo)
                    ON DUPLICATE KEY UPDATE job_title=VALUES(job_title),department=VALUES(department),bio=VALUES(bio),photo_path=VALUES(photo_path)')
                    ->execute(['uid'=>$userId,'job'=>trim((string)($_POST['job_title']??'')) ?: null,'department'=>trim((string)($_POST['department']??'')) ?: null,'bio'=>trim((string)($_POST['bio']??'')) ?: null,'photo'=>$photoPath]);
            }
            log_activity('user',$userId,'updated','Administrator updated own profile.',$userId);
            flash('success','Profile updated.');
        } catch (Throwable $e) { flash('error','Profile could not be updated: '.$e->getMessage()); }
        redirect('admin/profile.php');
    }

    if ($action === 'change_email') {
        $newEmail = strtolower(trim((string) ($_POST['new_email'] ?? '')));
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        if (!$account || !password_verify($currentPassword, (string)$account['password_hash'])) { security_event('login_email_change','failure','Wrong password.',null,$userId); flash('error','Current password is incorrect.'); redirect('admin/profile.php'); }
        if (!filter_var($newEmail,FILTER_VALIDATE_EMAIL)) { flash('error','Enter a valid login email.'); redirect('admin/profile.php'); }
        $duplicate=db()->prepare('SELECT COUNT(*) FROM users WHERE email=:email AND id<>:id'); $duplicate->execute(['email'=>$newEmail,'id'=>$userId]);
        if ((int)$duplicate->fetchColumn()>0) { flash('error','That login email is already used by another account.'); redirect('admin/profile.php'); }
        $oldEmail=(string)$user['email']; db()->prepare('UPDATE users SET email=:email WHERE id=:id')->execute(['email'=>$newEmail,'id'=>$userId]);
        log_activity('user',$userId,'login_email_changed','Login email changed from '.$oldEmail.' to '.$newEmail.'.',$userId);
        security_event('login_email_change','success','Administrator changed own login email.',null,$userId,$newEmail);
        flash('success','Login email changed. Use the new email next time you sign in.'); redirect('admin/profile.php');
    }

    if ($action === 'change_password') {
        $current=(string)($_POST['current_password']??''); $new=(string)($_POST['new_password']??''); $confirm=(string)($_POST['confirm_password']??'');
        if (!$account || !password_verify($current,(string)$account['password_hash'])) { security_event('password_change','failure','Wrong current password.',null,$userId); flash('error','Current password is incorrect.'); redirect('admin/profile.php'); }
        if (($error=$passwordError($new))!==null) { flash('error',$error); redirect('admin/profile.php'); }
        if ($new!==$confirm) { flash('error','New password and confirmation do not match.'); redirect('admin/profile.php'); }
        if (hash_equals($current,$new)) { flash('error','New password must be different.'); redirect('admin/profile.php'); }
        db()->prepare('UPDATE users SET password_hash=:hash,must_change_password=0 WHERE id=:id')->execute(['hash'=>password_hash($new,PASSWORD_DEFAULT),'id'=>$userId]);
        log_activity('user',$userId,'password_changed','Administrator changed own password.',$userId); security_event('password_change','success','Administrator self-service password change.',null,$userId);
        flash('success','Password changed successfully.'); redirect('admin/profile.php');
    }
    flash('error','Unknown profile action.'); redirect('admin/profile.php');
}

$pageTitle='My Profile'; $pageSubtitle='Personal details, login email and account security';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="admin-profile-grid">
 <section class="mbw-card"><div class="mbw-card-head"><h2>Profile details</h2></div>
  <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update_profile">
   <?php if(!empty($profile['photo_path'])):?><img class="admin-self-photo" src="<?=e(url((string)$profile['photo_path']))?>" alt="Profile photo"><?php endif;?>
   <label>Profile photo<input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG or WEBP; maximum 2 MB.</small></label>
   <label>Full name<input type="text" name="name" value="<?=e($user['name']??'')?>" required></label><label>Phone<input type="text" name="phone" value="<?=e($user['phone']??'')?>"></label>
   <label>Job title<input type="text" name="job_title" value="<?=e($profile['job_title']??'')?>"></label><label>Department<input type="text" name="department" value="<?=e($profile['department']??'')?>"></label>
   <label>About<textarea name="bio" rows="4"><?=e($profile['bio']??'')?></textarea></label><button type="submit"><?=icon('settings')?>Save profile</button>
  </form></section>
 <div class="admin-profile-security">
  <section class="mbw-card"><div class="mbw-card-head"><h2>Change login email</h2></div><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="change_email"><label>Current login email<input type="email" value="<?=e($user['email']??'')?>" readonly></label><label>New login email<input type="email" name="new_email" required></label><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label><button type="submit"><?=icon('messages')?>Change login email</button></form></section>
  <section class="mbw-card"><div class="mbw-card-head"><h2>Change password</h2></div><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="change_password"><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label><label>New password<input type="password" name="new_password" required minlength="8" autocomplete="new-password"></label><label>Confirm password<input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></label><button type="submit"><?=icon('lock')?>Update password</button></form></section>
 </div>
</div>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
