<?php
declare(strict_types=1);
$configured = true; $calls = []; $sendOk = true; $throws = false;
function mail_is_configured(): bool { global $configured; return $configured; }
function e(string $value): string { return htmlspecialchars($value,ENT_QUOTES,'UTF-8'); }
function mail_public_url(string $path): string { return 'https://example.test/' . $path; }
function branded_email_html(string $title,string $html): string { return $html; }
function send_app_email(string $to,string $subject,string $body): array {
    global $calls,$sendOk,$throws;
    if (($GLOBALS['mail_settings_company_id'] ?? null) !== 0) throw new RuntimeException('Not using platform mail settings');
    if ($throws) throw new RuntimeException('Transport failure');
    $calls[] = [$to,$subject,$body];
    return ['ok'=>$sendOk,'transport'=>'smtp'];
}
require __DIR__ . '/../app/company_access_email.php';
function check(bool $value,string $message): void { if (!$value) throw new RuntimeException($message); }
$company = ['name'=>'Test & Co','code'=>'T001'];
check(company_access_email($company,'owner@example.test','TEST-KEY','Temporary<Pass123'),'Creation mail accepted');
check(!isset($GLOBALS['mail_settings_company_id']),'Mail scope restored');
check($calls[0][0] === 'owner@example.test','Email goes to new owner');
check(str_contains($calls[0][2],'T001') && str_contains($calls[0][2],'TEST-KEY') && str_contains($calls[0][2],'Temporary&lt;Pass123'),'Includes escaped credentials, company code and key');
check(company_access_email($company,'owner@example.test','REPLACEMENT'),'Replacement mail accepted');
check(!str_contains($calls[1][2],'Temporary password'),'Replacement never invents or resends old password');
$configured = false;
check(!company_access_email($company,'owner@example.test','SECRET','PASSWORD') && count($calls) === 2,'Log transport cannot persist credentials');
$configured = true; $sendOk = false;
check(!company_access_email($company,'owner@example.test','KEY'),'Mail failure reported');
$throws = true;
check(!company_access_email($company,'owner@example.test','KEY'),'Transport exception cannot undo committed creation');
$management = file_get_contents(__DIR__ . '/../public_html/platform/company-management.php');
check(substr_count($management,'name="expires"') === 1,'One expiry input only');
check(str_contains($management,'form="license-'),'Expiry belongs to row management form');
check(!str_contains($management,'<h2>Company Management</h2>'),'Duplicate management heading removed');
check(str_contains($management,"['issued_at']"),'Activation column uses generation date');
$workspace = file_get_contents(__DIR__ . '/../public_html/admin/workspace.php');
check(!str_contains($workspace,'<h3>New Client</h3>'),'Legacy creation form removed');
check(str_contains($workspace,"deny_access('Create companies from Platform Companies.')"),'Legacy creation POST blocked');
echo "Company access email, secret handling, single expiry and creation UI checks passed.\n";
