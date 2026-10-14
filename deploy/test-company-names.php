<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/company_name_cleanup.php';
function check_name(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
foreach (['Acme (Books)'=>'Acme','Acme (Client Books)'=>'Acme','Acme (client_books)'=>'Acme','Acme (Nepal)'=>'Acme (Nepal)','Books Trading'=>'Books Trading'] as $input=>$expected) {
    check_name(statement_company_name($input) === $expected,'Print name: ' . $input);
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY,name TEXT,code TEXT)');
$pdo->exec('CREATE TABLE settings (company_id INTEGER,setting_key TEXT,setting_value TEXT,UNIQUE(company_id,setting_key))');
$pdo->exec("INSERT INTO companies VALUES (1,'Acme (Client Books)','A'),(2,'Shop (Books)','B'),(3,'Acme (Nepal)','C')");
$pdo->exec("INSERT INTO settings VALUES (1,'site_name','Acme (Client Books)'),(2,'site_name','Custom heading'),(3,'site_name','Acme (Nepal)')");
check_name(company_name_cleanup($pdo) === 2,'Clean both legacy names');
check_name($pdo->query('SELECT name FROM companies WHERE id=1')->fetchColumn() === 'Acme','Directory and raw print source cleaned');
check_name($pdo->query("SELECT setting_value FROM settings WHERE company_id=1 AND setting_key='site_name'")->fetchColumn() === 'Acme','Scoped print name cleaned');
check_name($pdo->query("SELECT setting_value FROM settings WHERE company_id=2 AND setting_key='site_name'")->fetchColumn() === 'Custom heading','Custom heading preserved');
$original = json_decode($pdo->query("SELECT setting_value FROM settings WHERE company_id=1 AND setting_key='company_name_before_cleanup'")->fetchColumn(),true);
check_name($original['name'] === 'Acme (Client Books)','Original saved for recovery');
check_name(company_name_cleanup($pdo) === 0,'Repeat deployment is idempotent');
check_name($pdo->query('SELECT code FROM companies WHERE id=1')->fetchColumn() === 'A','Company identifiers unchanged');
echo "Company names: suffixes, legal brackets, print settings, recovery, identifiers and idempotency passed.\n";
