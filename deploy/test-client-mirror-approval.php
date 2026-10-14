<?php
declare(strict_types=1);
class MirrorTestDb extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$query),$options); }
}
$pdo = new MirrorTestDb('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function db(): PDO { global $pdo; return $pdo; }
$capable = true; $locked = false;
function client_portal_capability(string $capability): bool { global $capable; return $capable; }
function client_profile_for_user(int $id): array { return ['books_company_id'=>$id === 1 ? 10 : 20]; }
function fiscal_year_by_id(int $id): array { return ['company_id'=>10]; }
function fiscal_year_posting_blocker(array $fy,string $date): ?string { global $locked; return $locked ? 'Period locked' : null; }
require __DIR__ . '/../app/client_mirror_approval.php';
function mirror_check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function mirror_reject(callable $fn): void { try { $fn(); } catch (RuntimeException $e) { return; } throw new RuntimeException('Expected rejection'); }
$pdo->exec('CREATE TABLE vouchers (id INTEGER PRIMARY KEY,company_id INTEGER,fiscal_year_id INTEGER,voucher_date TEXT,source_type TEXT,requires_client_approval INTEGER,approval_state TEXT,status TEXT,client_approved_by INTEGER,client_approved_at TEXT,approved_by INTEGER,approved_at TEXT,posted_by INTEGER,posted_at TEXT)');
$pdo->exec("INSERT INTO vouchers (id,company_id,fiscal_year_id,voucher_date,source_type,requires_client_approval,approval_state,status) VALUES (1,10,1,'2026-10-14','client_mirror_invoice',1,'pending_approval','draft'),(2,10,1,'2026-10-14','client_mirror_invoice',1,'pending_approval','draft')");
$pdo->exec('CREATE TABLE ledgers (id INTEGER,company_id INTEGER)');
$pdo->exec('INSERT INTO ledgers VALUES (1,10),(2,10)');
$pdo->exec('CREATE TABLE voucher_entries (voucher_id INTEGER,ledger_id INTEGER,entry_type TEXT,amount NUMERIC)');
$pdo->exec("INSERT INTO voucher_entries VALUES (1,1,'debit',100),(1,2,'credit',100),(2,1,'debit',99),(2,2,'credit',100)");
mirror_check(client_mirror_admin_approval_blocker(['source_type'=>'client_mirror_invoice']) !== null,'Admin cannot approve client mirror');
mirror_check(client_mirror_admin_approval_blocker(['source_type'=>'voucher_form']) === null,'Ordinary approvals unaffected');
$capable = false; mirror_reject(fn()=>client_mirror_decide(1,10,1,'approve')); $capable = true;
mirror_reject(fn()=>client_mirror_decide(1,10,2,'approve'));
$locked = true; mirror_reject(fn()=>client_mirror_decide(1,10,1,'approve')); $locked = false;
mirror_reject(fn()=>client_mirror_decide(2,10,1,'approve'));
mirror_check($pdo->query('SELECT status FROM vouchers WHERE id=2')->fetchColumn() === 'draft','Unbalanced approval rolls back');
client_mirror_decide(1,10,1,'approve');
mirror_check($pdo->query('SELECT status FROM vouchers WHERE id=1')->fetchColumn() === 'posted','Client approval posts');
mirror_reject(fn()=>client_mirror_decide(1,10,1,'approve'));
client_mirror_decide(2,10,1,'reject');
mirror_check($pdo->query('SELECT status FROM vouchers WHERE id=2')->fetchColumn() === 'cancelled','Client rejection never posts');
echo "Mirror approvals: consent, tenant scope, period lock, balance, replay and rejection passed.\n";
