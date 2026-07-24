<?php
declare(strict_types=1);

/**
 * Structured agreement drafting engine: schema, template instantiation and
 * independence, automatic renumbering (chaptered + flat), stable IDs across
 * moves, placeholders, client snapshot freezing, maker-checker workflow,
 * immutable versions, issue numbering, contract sync, task links, tenant
 * isolation and version comparison.
 *   php database/test_agreement_builder.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/agreement_builder.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

function agb_cleanup(): void
{
    if (table_exists('staff_permissions')) {
        db()->exec("DELETE sp FROM staff_permissions sp JOIN users u ON u.id = sp.user_id WHERE u.email LIKE 'agbtest-%@example.test'");
    }
    db()->exec("DELETE FROM users WHERE email LIKE 'agbtest-%@example.test'");
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('AGBTA','AGBTB')")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM agreement_templates WHERE company_id=$s");
        db()->exec("DELETE FROM service_agreements WHERE company_id=$s"); // cascades sections/versions/comments/links
        db()->exec("DELETE FROM client_tasks WHERE company_id=$s");
        db()->exec("DELETE FROM service_contracts WHERE company_id=$s");
        db()->exec("DELETE FROM client_profiles WHERE company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
}
agb_cleanup();

echo "== Schema ==\n";
foreach (['agreement_sections', 'agreement_versions', 'agreement_task_links', 'agreement_templates', 'agreement_comments'] as $t) {
    ok(table_exists($t), "repair created $t");
}
ok(column_exists('service_agreements', 'workflow_status') && column_exists('service_agreements', 'client_snapshot_json'), 'repair added workflow columns to service_agreements');

// Fixtures.
db()->exec("INSERT INTO companies (name, code, is_active) VALUES ('AGB Test A', 'AGBTA', 1)");
$coA = (int) db()->lastInsertId();
db()->exec("INSERT INTO companies (name, code, is_active) VALUES ('AGB Test B', 'AGBTB', 1)");
$coB = (int) db()->lastInsertId();
db()->exec("INSERT INTO fiscal_years (company_id, label, start_date, end_date) VALUES ($coA, '2083/84', '2026-07-17', '2027-07-16')");

$mkUser = static function (string $slug, string $role, int $companyId): array {
    db()->prepare("INSERT INTO users (name, email, password_hash, role, status, company_id) VALUES (?,?,?,?,?,?)")
        ->execute(['AGB ' . ucfirst($slug), "agbtest-$slug@example.test", 'x', $role, 'active', $companyId]);
    $id = (int) db()->lastInsertId();
    return ['id' => $id, 'name' => 'AGB ' . ucfirst($slug), 'role' => $role, 'company_id' => $companyId];
};
$maker = $mkUser('maker', 'admin', $coA);
$checker = $mkUser('checker', 'admin', $coA);
$plainStaff = $mkUser('staff', 'staff', $coA);
$clientUser = $mkUser('clientuser', 'customer', $coA);

db()->prepare('INSERT INTO client_profiles (user_id, company_id, organization_name, client_code, address, pan_no, authorized_signatory_name) VALUES (?,?,?,?,?,?,?)')
    ->execute([$clientUser['id'], $coA, 'AGB Client Pvt. Ltd.', 'AGBC', 'Kathmandu', 'PAN-777', 'Signatory One']);
$clientId = (int) db()->lastInsertId();
db()->prepare('INSERT INTO client_tasks (company_id, client_id, title, status) VALUES (?,?,?,?)')->execute([$coA, $clientId, 'Monthly bookkeeping', 'in_progress']);
$taskA = (int) db()->lastInsertId();
db()->prepare('INSERT INTO client_tasks (company_id, client_id, title, status) VALUES (?,?,?,?)')->execute([$coA, $clientId, 'VAT filing', 'new']);
$taskB = (int) db()->lastInsertId();
db()->prepare('INSERT INTO service_contracts (company_id, client_id, title, contract_no, total_value, status) VALUES (?,?,?,?,?,?)')
    ->execute([$coA, $clientId, 'Bookkeeping Services', 'SC-AGB-1', 0, 'draft']);
$contractId = (int) db()->lastInsertId();

echo "== Templates ==\n";
$templateId = agreement_template_seed_default($coA, $maker['id']);
ok($templateId > 0, 'default template seeded');
ok(agreement_template_seed_default($coA, $maker['id']) === $templateId, 'seeding again reuses the existing template');

$saId = agreement_create_from_template($coA, $templateId, [
    'client_id' => $clientId, 'contract_id' => $contractId,
    'purpose_en' => 'Bookkeeping and Advisory Services', 'purpose_np' => 'लेखा सेवा',
    'first_party_name_en' => 'AGB Client Pvt. Ltd.', 'second_party_name_en' => 'AGB Test A',
    'effective_date' => '2026-08-01', 'fee_trial' => 15000, 'fee_monthly' => 35000,
], $maker['id']);
$sa = agreement_get($saId, $coA);
ok($sa !== null && $sa['structure_mode'] === 'builder' && $sa['workflow_status'] === 'draft', 'agreement created in builder mode as draft');
ok(str_starts_with((string) $sa['agreement_no'], 'SC-AGB-1'), 'contract number carried as the draft agreement number');
$flat = agreement_sections_flat($saId);
ok(count($flat) > 25, 'template instantiated ' . count($flat) . ' sections');

// Template independence: mutate the template afterwards.
db()->prepare("UPDATE agreement_templates SET sections_json = '[]' WHERE id = :id")->execute(['id' => $templateId]);
ok(count(agreement_sections_flat($saId)) === count($flat), 'editing the template does not touch the existing agreement (snapshot copy)');
db()->prepare('UPDATE agreement_templates SET sections_json = :j WHERE id = :id')
    ->execute(['j' => agreement_template_sections_json(agreement_default_template_sections()), 'id' => $templateId]);

echo "== Numbering ==\n";
$tree = agreement_sections_tree($flat);
$chapters = array_values(array_filter($tree, static fn ($n) => $n['section_type'] === 'chapter'));
$schedules = array_values(array_filter($tree, static fn ($n) => $n['section_type'] === 'schedule'));
ok($chapters !== [] && $chapters[0]['number'] === '1' && $chapters[1]['number'] === '2', 'chapters number sequentially');
ok($schedules !== [] && $schedules[0]['number'] === 'Schedule 1' && $schedules[1]['number'] === 'Schedule 2', 'schedules number as their own series');
$c1 = $chapters[0]['children'];
$c2 = $chapters[1]['children'];
ok($c1[0]['number'] === '1' && $c1[1]['number'] === '2' && $c2[0]['number'] === '3', 'clauses number CONTINUOUSLY across chapters (firm दफा style)');

// Subclause numbering + renumbering on insert/move/delete with stable IDs.
$firstClauseId = (int) $c1[0]['id'];
$subId = agreement_section_add($sa, ['parent_id' => $firstClauseId, 'section_type' => 'clause', 'title_en' => 'Sub-scope', 'title_np' => 'उप-दफा'], $maker['id']);
$tree = agreement_sections_tree(agreement_sections_flat($saId));
$sub = $tree[0]['children'][0]['children'][0] ?? null;
ok($sub !== null && $sub['number'] === '1.1', 'subclause numbered under its parent (1.1)');
$link = agreement_task_link($sa, $taskA, $subId, 'anchored to subclause', $maker['id']);
ok($link['ok'], 'task linked to a specific clause');
agreement_section_move($sa, (int) $c1[1]['id'], 'up', $maker['id']);
$tree = agreement_sections_tree(agreement_sections_flat($saId));
ok($tree[0]['children'][0]['id'] === $c1[1]['id'] && $tree[0]['children'][0]['number'] === '1', 'moving a clause recalculates numbers automatically');
$links = agreement_linked_tasks($saId);
ok((int) $links[0]['section_id'] === $subId, 'task link survives the move (stable IDs, display numbers only)');
agreement_section_delete($sa, $subId, $maker['id']);
ok(agreement_section_owned($subId, $saId) === null, 'section deleted');
$linksAfterDelete = agreement_linked_tasks($saId);
ok($linksAfterDelete !== [] && $linksAfterDelete[0]['section_id'] === null, 'clause-anchored link degrades to whole-agreement (SET NULL), not lost');

// Flat (chapterless) documents use decimal hierarchy.
$flatSaId = agreement_create_from_template($coA, null, ['client_id' => $clientId, 'first_party_name_en' => 'AGB Client Pvt. Ltd.', 'second_party_name_en' => 'AGB Test A'], $maker['id']);
$flatSa = agreement_get($flatSaId, $coA);
$p1 = agreement_section_add($flatSa, ['section_type' => 'clause', 'title_en' => 'Introduction'], $maker['id']);
agreement_section_add($flatSa, ['parent_id' => $p1, 'section_type' => 'clause', 'title_en' => 'Parties'], $maker['id']);
agreement_section_add($flatSa, ['section_type' => 'clause', 'title_en' => 'Scope'], $maker['id']);
$flatTree = agreement_sections_tree(agreement_sections_flat($flatSaId));
ok($flatTree[0]['number'] === '1' && $flatTree[0]['children'][0]['number'] === '1.1' && $flatTree[1]['number'] === '2', 'chapterless documents use decimal hierarchy (1, 1.1, 2)');

echo "== Placeholders ==\n";
$sa = agreement_get($saId, $coA);
$map = agreement_placeholder_map($sa);
$resolved = agreement_resolve_text('Fee {{currency}}{{monthly_fee}} within {{payment_due_date}} days for {{client_legal_name_en}}.', $map);
ok(str_contains($resolved, '35,000') && str_contains($resolved, 'AGB Client Pvt. Ltd.') && !str_contains($resolved, '{{'), 'placeholders resolve at render time');
$missing = agreement_unresolved_tokens([['body_en' => 'sign: {{client_signatory}} & {{expiry_date}}']], $map);
ok(in_array('expiry_date', $missing, true) && !in_array('client_signatory', $missing, true), 'unresolved-placeholder detection flags only empty values');

echo "== Workflow (maker-checker) ==\n";
$deny = agreement_transition($sa, 'approved', $maker, []);
ok(!$deny['ok'], 'illegal transition draft → approved rejected');
set_staff_permissions($plainStaff['id'], ['reports.view']); // Configured, but no agreements.* grants.
$deny = agreement_transition($sa, 'under_review', $plainStaff, []);
ok(!$deny['ok'], 'configured staff without agreements.edit grant cannot submit');
$resultSubmit = agreement_transition($sa, 'under_review', $maker, ['summary' => 'First submission']);
ok($resultSubmit['ok'], 'draft submitted for review (version snapshot taken)');
$sa = agreement_get($saId, $coA);
ok((int) $sa['current_version'] === 1 && (int) $sa['submitted_by'] === $maker['id'], 'version 1 recorded with submitter');
ok(!agreement_is_editable($sa), 'content locked while under review');
ok(agreement_transition($sa, 'reviewed', $checker, [])['ok'], 'reviewer marks reviewed');
$sa = agreement_get($saId, $coA);
ok(agreement_transition($sa, 'pending_approval', $checker, [])['ok'], 'sent for approval');
$sa = agreement_get($saId, $coA);
$selfApprove = agreement_transition($sa, 'approved', $maker, []);
ok(!$selfApprove['ok'] && str_contains((string) $selfApprove['error'], 'Maker-checker'), 'submitter cannot approve their own agreement');
ok(agreement_transition($sa, 'approved', $checker, [])['ok'], 'a different user approves');
$sa = agreement_get($saId, $coA);
ok((int) $sa['approved_version'] === 1 && $sa['status'] === 'final', 'approval bound to immutable version 1; legacy status synced to final');
ok((string) $sa['expiry_date'] !== '', 'expiry date derived from effective date + duration at approval');
$v1 = agreement_version_get($saId, 1);
ok($v1 !== null && (int) $v1['approved_by'] === $checker['id'], 'version row carries the approver');

echo "== Client snapshot freezing ==\n";
$particulars = agreement_client_particulars($sa);
ok(($particulars['organization_name'] ?? '') === 'AGB Client Pvt. Ltd.', 'client snapshot captured');
db()->prepare("UPDATE client_profiles SET organization_name = 'RENAMED LTD' WHERE id = :id")->execute(['id' => $clientId]);
$sa = agreement_get($saId, $coA);
ok((agreement_client_particulars($sa)['organization_name'] ?? '') === 'AGB Client Pvt. Ltd.', 'approved agreement keeps its historical client snapshot after master changes');

echo "== Immutability of versions ==\n";
$jsonBefore = (string) $v1['content_json'];
db()->prepare("UPDATE agreement_sections SET body_en = 'TAMPERED LIVE COPY' WHERE agreement_id = :aid LIMIT 1")->execute(['aid' => $saId]);
ok((string) agreement_version_get($saId, 1)['content_json'] === $jsonBefore, 'editing live sections never rewrites the version 1 snapshot');

echo "== Issue, activate, contract sync ==\n";
$sa = agreement_get($saId, $coA);
ok(agreement_transition($sa, 'issued', $checker, [])['ok'], 'approved agreement issued');
$sa = agreement_get($saId, $coA);
ok((string) $sa['agreement_no'] === 'SC-AGB-1', 'contract-linked agreement keeps its contract number at issue (numbers never silently change)');
$numberAtIssue = (string) $sa['agreement_no'];
$assigned = agreement_number_assign(agreement_get($flatSaId, $coA), $maker['id']);
ok(str_starts_with($assigned, 'SA-') && str_contains($assigned, 'AGBC'), 'standalone agreement numbered SA-{FY}-{CLIENT}-{SEQ}: ' . $assigned);
ok(agreement_number_assign(agreement_get($flatSaId, $coA), $maker['id']) === $assigned, 'number assignment is idempotent once final');
ok(agreement_transition($sa, 'accepted', $checker, ['note' => 'Recorded manually'])['ok'], 'acceptance recorded');
$sa = agreement_get($saId, $coA);
ok(agreement_transition($sa, 'active', $checker, [])['ok'], 'agreement activated');
$sa = agreement_get($saId, $coA);
ok((string) $sa['agreement_no'] === $numberAtIssue, 'number never changes after issuance');
$contractStatus = db()->query("SELECT status FROM service_contracts WHERE id = $contractId")->fetchColumn();
ok($contractStatus === 'active', 'linked Work Portal contract activated by the agreement workflow');
$deny = agreement_transition($sa, 'terminated', $checker, []);
ok(!$deny['ok'], 'termination without a reason rejected');

echo "== Revision / amendment ==\n";
$revision = agreement_revision_start($sa, $checker, 'amendment', 'Fee revision from Shrawan');
ok($revision['ok'], 'amendment reopens drafting');
$sa = agreement_get($saId, $coA);
ok($sa['workflow_status'] === 'draft' && agreement_is_editable($sa), 'agreement editable again as the next version');
ok((int) agreement_version_get($saId, 1)['approved_by'] === $checker['id'], 'the approved v1 snapshot remains, with its approver');
db()->prepare("UPDATE agreement_sections SET body_en = 'Amended fee text' WHERE agreement_id = :aid ORDER BY id LIMIT 1")->execute(['aid' => $saId]);
agreement_version_create(agreement_get($saId, $coA), 'Amended', 'test', $maker['id']);
$diff = agreement_versions_compare($saId, 1, (int) agreement_get($saId, $coA)['current_version']);
ok(($diff['modified'] ?? []) !== [], 'version comparison detects modified clauses');

echo "== Task links: duplicates, cross-client, cross-tenant ==\n";
$sa = agreement_get($saId, $coA);
$dup = agreement_task_link($sa, $taskA, null, null, $maker['id']);
ok(!$dup['ok'], 'duplicate task link rejected (already linked)');
$okLink = agreement_task_link($sa, $taskB, null, 'second task', $maker['id']);
ok($okLink['ok'] && count(agreement_linked_tasks($saId)) === 2, 'multiple tasks link to one agreement');
db()->exec("INSERT INTO companies (name, code, is_active) VALUES ('AGB Tmp', 'AGBTB2', 0)");
$tmpCo = (int) db()->lastInsertId();
db()->prepare('INSERT INTO client_tasks (company_id, client_id, title, status) VALUES (?,?,?,?)')->execute([$coB, $clientId, 'Foreign task', 'new']);
$foreignTask = (int) db()->lastInsertId();
$deny = agreement_task_link($sa, $foreignTask, null, null, $maker['id']);
ok(!$deny['ok'], 'task from another company cannot be linked');
db()->exec("DELETE FROM client_tasks WHERE id=$foreignTask");
db()->exec("DELETE FROM companies WHERE id=$tmpCo");

echo "== Tenant isolation ==\n";
ok(agreement_get($saId, $coB) === null, 'company B cannot load company A\'s agreement');

echo "== Validation gates ==\n";
$emptySaId = agreement_create_from_template($coA, null, ['first_party_name_en' => 'X', 'second_party_name_en' => 'Y'], $maker['id']);
$emptySa = agreement_get($emptySaId, $coA);
$deny = agreement_transition($emptySa, 'under_review', $maker, []);
ok(!$deny['ok'] && str_contains((string) $deny['error'], 'client'), 'submission without a client blocked with a clear message');

agb_cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
