<?php
declare(strict_types=1);
require __DIR__ . '/../app/company_onboarding.php';
function check(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); }
$input = ['user_name'=>'Owner','user_email'=>'owner@example.test','temporary_password'=>'Temporary123!','company_pan'=>'001234567','banks'=>[['bank_name'=>'Test Bank','account_name'=>'Test Co','account_number'=>'000123','branch'=>'Branch']]];
$validated = company_onboarding_values($input);
check($validated['pan'] === '001234567' && $validated['banks'][0]['account_number'] === '000123','Leading zeros preserved');
foreach ([['user_name'=>''],['user_email'=>'bad'],['temporary_password'=>'short'],['banks'=>[['bank_name'=>'Bank']]]] as $bad) {
    $rejected = false;
    try { company_onboarding_values(array_replace($input,$bad)); } catch (RuntimeException $expected) { $rejected = true; }
    check($rejected,'Invalid onboarding accepted');
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function db():PDO { global $pdo; return $pdo; }
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT UNIQUE, password_hash TEXT, role TEXT, company_id INTEGER, must_change_password INTEGER)');
$pdo->exec('CREATE TABLE client_profiles (user_id INTEGER, books_company_id INTEGER, organization_name TEXT, address TEXT, pan_no TEXT, contact_number TEXT, client_status TEXT, is_active INTEGER)');
$pdo->exec('CREATE TABLE company_bank_accounts (company_id INTEGER, bank_name TEXT, account_name TEXT, account_number TEXT, branch TEXT, is_default INTEGER, show_on_invoice INTEGER, sort_order INTEGER, active INTEGER, created_by INTEGER)');
function create_user(array $user):int {
    db()->prepare('INSERT INTO users (name,email,password_hash,role,company_id,must_change_password) VALUES (?,?,?,?,?,?)')->execute([$user['name'],$user['email'],password_hash($user['password'],PASSWORD_DEFAULT),$user['role'],$user['company_id'],$user['must_change_password']]);
    return (int)db()->lastInsertId();
}
$company = ['name'=>'Test Co','office_address'=>'Office','support_phone'=>'123'];
$pdo->beginTransaction();
company_onboarding_save(42,$company,$validated,1);
$owner = $pdo->query('SELECT * FROM users')->fetch(PDO::FETCH_ASSOC);
check($owner['role'] === 'customer' && (int)$owner['must_change_password'] === 1,'Owner is not platform admin and must change password');
check(password_verify($input['temporary_password'],$owner['password_hash']),'Password hashed');
check((int)$pdo->query('SELECT books_company_id FROM client_profiles')->fetchColumn() === 42,'Owner linked to own books');
check($pdo->query('SELECT account_number FROM company_bank_accounts')->fetchColumn() === '000123','Bank saved as text');
$pdo->rollBack();
check((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0,'Owner rolls back with company');
$view = file_get_contents(__DIR__ . '/../app/views/partials/company_details_readonly.php');
check(!str_contains($view,'<form') && !str_contains($view,'password'),'Details contain no edit form or password');
$controller = file_get_contents(__DIR__ . '/../public_html/admin/company-settings.php');
check(str_contains($controller,"if (!\$isFiscalSettings) deny_access('Company details are read-only.');"),'Read-only enforced on POST');
echo "Onboarding validation, owner isolation, password hashing, rollback and read-only details passed.\n";
