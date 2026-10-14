<?php
declare(strict_types=1);
$companies = [11=>['id'=>11,'code'=>'CLIENT11','is_active'=>1],12=>['id'=>12,'code'=>'OTHER','is_active'=>1],13=>['id'=>13,'code'=>'CLOSED','is_active'=>0]];
$profile = ['is_active'=>1,'books_company_id'=>11];
function company_by_code(string $code): ?array { global $companies; foreach ($companies as $company) if (strcasecmp($company['code'],$code) === 0) return $company; return null; }
function user_is_super_admin(?array $user = null): bool { return ($user['access_level'] ?? '') === 'super_admin'; }
function client_profile_for_user(int $id): ?array { global $profile; return $profile; }
function user_can_access_company(int $id, ?array $user = null): bool { return in_array($id,$user['companies'] ?? [],true); }
require __DIR__ . '/../app/login_company.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$client = ['id'=>4,'role'=>'customer','companies'=>[11,12,13]];
check(login_company_target($client,'') === false,'Client must enter code');
check(login_company_target($client,'OTHER') === false,'Provider/membership code is not own books');
check(login_company_target($client,'CLIENT11') === 11,'Own company accepted');
check(login_company_target($client,' client11 ') === 11,'Code whitespace/case');
check(login_company_target($client,'CLOSED') === false,'Inactive company blocked');
check(login_company_target($client,'unknown') === false,'Unknown code blocked');
check(login_company_target(array_replace($client,['companies'=>[]]),'CLIENT11') === false,'Membership still enforced');
$profile['is_active'] = 0;
check(login_company_target($client,'CLIENT11') === false,'Inactive profile blocked');
$profile['is_active'] = 1;
check(login_company_target(['role'=>'staff'],'') === 0,'Staff exempt');
check(login_company_target(['role'=>'admin','access_level'=>'super_admin'],'') === 0,'Platform admin exempt');
check(login_company_target(['id'=>8,'role'=>'admin','companies'=>[11]],'') === false,'Tenant admin needs code');
check(login_company_target(['id'=>8,'role'=>'admin','companies'=>[11]],'CLIENT11') === 11,'Tenant admin matched');

// Exercise the actual password-login function with controlled storage/session dependencies.
$loginUser = $client + ['password_hash'=>password_hash('correct-password',PASSWORD_DEFAULT),'status'=>'active'];
function user_by_email(string $email): ?array { global $loginUser; return $loginUser; }
function user_status_allows_login(array $user): bool { return $user['status'] === 'active'; }
function security_event(...$args): void {}
function clear_company_pin_verification(): void { unset($_SESSION['verified_company_id']); }
function clear_selected_company(): void { unset($_SESSION['selected_company_id']); }
$helpers = file_get_contents(__DIR__ . '/../app/helpers.php');
$start = strpos($helpers,'function login_user(');
$end = strpos($helpers,'function role_home_path(', $start);
eval(substr($helpers,$start,$end-$start));
$_SESSION = [];
check(!login_user('client@example.test','correct-password','OTHER'),'Wrong company rejected with correct password');
check(!isset($_SESSION['user_id']),'Rejected code creates no authenticated session');
check(!login_user('client@example.test','wrong-password','CLIENT11'),'Code does not replace password');
$_SESSION = ['company_id'=>12,'verified_company_id'=>12,'selected_company_id'=>12];
check(login_user('client@example.test','correct-password','CLIENT11'),'Valid company login');
check($_SESSION['login_company_id'] === 11 && !isset($_SESSION['company_id'],$_SESSION['verified_company_id']),'Target retained without activating before MFA');
$header = file_get_contents(__DIR__ . '/../app/views/partials/admin_header.php');
check(strpos($header,"url('admin/audit-trail.php')") < strpos($header,"url('admin/company-settings.php')"),'Bottom links ordered Audit Trail then Settings');
check(substr_count($header,"url('admin/audit-trail.php')") === 1,'No duplicate audit navigation');
$twoFactor = file_get_contents(__DIR__ . '/../public_html/two-factor.php');
check(strpos($twoFactor,'two_factor_complete_pending($pendingUserId)') < strpos($twoFactor,'finish_login_company_context($user)'),'Company activation follows MFA');
echo "Company login: code, password, company isolation, exemptions, pre-MFA session and sidebar checks passed.\n";
