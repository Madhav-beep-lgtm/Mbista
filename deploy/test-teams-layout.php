<?php
declare(strict_types=1);
function e($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function csrf_token(): string { return 'test-csrf'; }
function icon(string $value): string { return ''; }
function team_check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$source = file_get_contents(__DIR__ . '/../public_html/admin/workspace.php');
$start = strpos($source,"    <?php if (\$view === 'teams'): ?>");
$end = strpos($source,"    <?php if (\$view === 'contracts'): ?>",$start);
team_check($start !== false && $end !== false,'Teams template exists');
$template = substr($source,$start,$end-$start);
$view = 'teams';
$staffUsers = [['id'=>4,'name'=>'Staff <One>','email'=>'one@example.test']];
$teams = [['name'=>'Finance','leader_name'=>'Staff One','member_count'=>2,'is_active'=>1]];
foreach ([false,true] as $empty) {
    if ($empty) { $staffUsers = []; $teams = []; }
    ob_start(); eval('?>' . $template); $html = ob_get_clean();
    $dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
    team_check($xpath->query('//form')->length === 1,'One creation form');
    team_check($xpath->query('//input[@name="csrf_token"]')->item(0)->getAttribute('value') === 'test-csrf','CSRF retained');
    team_check($xpath->query('//select[@name="leader_user_id"][@required]')->length === 1,'Required leader retained');
    team_check($xpath->query('//button[contains(@class,"button")]')->length === 1,'Shared styled button');
    team_check($xpath->query('//select[@multiple]')->length === 0,'Native multiselect replaced');
    team_check($xpath->query('//input[@name="member_ids[]"][@type="checkbox"]')->length === ($empty ? 0 : 1),'Member submission contract retained');
    if ($empty) team_check(str_contains($html,'No staff members available.') && str_contains($html,'No teams yet.'),'Empty states present');
    else team_check(str_contains($html,'Staff &lt;One&gt;') && str_contains($html,'Finance'),'Escaped names and team list retained');
}
echo "Teams layout: form fields, CSRF, checkbox membership, styled action, escaping and empty states passed.\n";
