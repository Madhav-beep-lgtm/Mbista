<?php
declare(strict_types=1);
class LicenseTestDb extends PDO {
    public function prepare(string $query,array $options = []): PDOStatement|false {
        $query = str_replace('ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)','ON CONFLICT(company_id,setting_key) DO UPDATE SET setting_value = excluded.setting_value',$query);
        return parent::prepare($query,$options);
    }
}
$pdo = new LicenseTestDb('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE settings (company_id INTEGER,setting_key TEXT,setting_value TEXT, UNIQUE(company_id,setting_key))');
function db():PDO { global $pdo; return $pdo; }
$user = ['id'=>1,'role'=>'admin','super'=>true];
function current_user():array { global $user; return $user; }
function user_is_super_admin(?array $value = null):bool { return !empty(($value ?? current_user())['super']); }
function user_can_access_company(int $id,array $user):bool { return in_array($id,$user['companies'] ?? [],true); }
function client_profile_for_user(int $id):array { return ['is_active'=>1,'books_company_id'=>11,'portal_member_role'=>$id === 2 ? 'owner' : 'member']; }
class LicenseRedirect extends RuntimeException {}
function redirect(string $url):never { throw new LicenseRedirect($url); }
require __DIR__ . '/../app/company_license.php';
function check(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn,string $message):void { $rejected = false; try {$fn();} catch (RuntimeException $e) {$rejected = true;} check($rejected,$message); }
$future = (new DateTimeImmutable('now',new DateTimeZone('Asia/Kathmandu')))->modify('+30 days')->format('Y-m-d');
check(company_license_state([]) === 'Not issued','Unissued company blocked');
$key = company_license_issue(11,$future);
check(strlen($key) === 44,'Random 160-bit key');
$license = company_license_read(11);
check(substr($license['issued_at'],0,10) === company_license_today(),'Generation date recorded before redemption');
$license['issued_at'] = '2025-01-02 03:04:05';
company_license_write(11,$license);
check(!str_contains(json_encode($license),$key),'Raw key is not stored');
check(company_license_state($license) === 'Awaiting activation','Issued key does not open books');
$user = ['id'=>2,'role'=>'customer','companies'=>[11]];
rejects(fn()=>company_license_issue(11,$future),'Owner cannot issue own license');
rejects(fn()=>company_license_activate(11,$user,'wrong'),'Wrong key rejected');
rejects(fn()=>company_license_activate(12,$user,$key),'Key cannot activate another company');
rejects(fn()=>company_license_activate(11,['id'=>3,'role'=>'customer','companies'=>[11]],$key),'Member cannot activate company');
$_SESSION = [];
rejects(fn()=>company_license_require(11),'Pending blocks direct company access');
check($_SESSION['license_company_id'] === 11,'Blocked company target retained');
company_license_activate(11,$user,strtolower($key));
check(company_license_state(company_license_read(11)) === 'Active','Valid owner key activates');
company_license_require(11);
rejects(fn()=>company_license_activate(11,$user,$key),'Activation cannot be replayed');
$license = company_license_read(11); $license['enabled'] = false; company_license_write(11,$license);
rejects(fn()=>company_license_require(11),'Deactivation blocks existing session');
$license['enabled'] = true; $license['expires'] = '2000-01-01'; company_license_write(11,$license);
rejects(fn()=>company_license_require(11),'Expiry blocks existing session');
rejects(fn()=>company_license_activate(11,$user,$key),'Key cannot bypass expiry');
$license['expires'] = $future; company_license_write(11,$license);
company_license_require(11);
check(company_license_state($license,$future) === 'Active','Expiry day inclusive');
rejects(fn()=>company_license_expiry('2026-02-30'),'Invalid calendar date');
$user = ['id'=>1,'role'=>'admin','super'=>true];
company_license_require(999);
$newKey = company_license_issue(11,$future);
check(company_license_read(11)['issued_at'] === '2025-01-02 03:04:05','Replacement preserves first generation date');
check($newKey !== $key && company_license_state(company_license_read(11)) === 'Awaiting activation','Replacement resets activation');
rejects(fn()=>company_license_activate(11,['id'=>2,'role'=>'customer','companies'=>[11]],$key),'Old key revoked');
echo "Company licensing: issuance, hashing, ownership, cross-company rejection, activation, session blocking, expiry, renewal and key replacement passed.\n";
