<?php
declare(strict_types=1);
function e($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function url(string $value): string { return '/' . $value; }
function csrf_token(): string { return 'test-token'; }
function app_date(string $value): string { return $value; }
function company_license_state(array $value): string { return 'Not issued'; }
function check_column(bool $value,string $message): void { if (!$value) throw new RuntimeException($message); }
$source = file_get_contents(__DIR__ . '/../public_html/platform/company-management.php');
$start = strpos($source,"\n<section class=\"mbw-card\">");
$end = strpos($source,"<?php include __DIR__ . '/../../app/views/partials/admin_footer.php';");
check_column($start !== false && $end !== false,'Table template found');
$template = substr($source,$start,$end-$start);
$template = str_replace("__DIR__ . '/../../app/", "__DIR__ . '/../app/", $template);
$companies = [['id'=>1,'name'=>'Company','code'=>'CO','is_active'=>1,'license_json'=>'{}']];
$profile = ['id'=>3,'company_id'=>2,'contact_name'=>'Owner','email'=>'owner@example.test','registration_no'=>'00134','pan_no'=>'01234','client_status'=>'active','organization_name'=>'Legacy','client_code'=>'LEG'];
$profilesByCompany = [1=>[$profile]];
$unlinked = [$profile];
$unlinkedTotal = 1; $clientPage = 1; $page = 1; $total = 1;
ob_start();
eval('?>' . $template);
$html = ob_get_clean();
$dom = new DOMDocument();
@$dom->loadHTML($html);
$xpath = new DOMXPath($dom);
$headers = $xpath->query('//thead/tr/th');
check_column($headers->length === 9,'Nine separate columns');
check_column($headers->item(2)->textContent === 'Registration No.' && $headers->item(3)->textContent === 'PAN','Separate registration and PAN headings');
check_column($headers->item(7)->textContent === 'Manage' && $headers->item(8)->textContent === 'Action','Action follows Manage');
foreach ($xpath->query('//tbody/tr') as $row) {
    $cells = $xpath->query('./td',$row);
    check_column($cells->length === 9,'Linked and unlinked rows align');
    check_column(trim($cells->item(2)->textContent) === '00134','Registration contains only its value');
    check_column(trim($cells->item(3)->textContent) === '01234','PAN contains only its value');
    check_column($xpath->query('.//button | .//a',$cells->item(2))->length === 0 && $xpath->query('.//button | .//a',$cells->item(3))->length === 0,'No actions in number columns');
    check_column(str_contains($cells->item(8)->textContent,'View') && str_contains($cells->item(8)->textContent,'Delete'),'Client actions in Action column');
}
echo "Company Management rendered columns: separate values, leading zeros and linked/unlinked actions passed.\n";
