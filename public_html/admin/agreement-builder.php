<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/agreement_builder.php';

require_staff_or_admin();
require_permission('agreements', 'view');
accounting_module_repair_database();

$currentUser = current_user();
$role = (string) ($currentUser['role'] ?? '');
$userId = (int) ($currentUser['id'] ?? 0);
if ($role === 'admin') {
    require_company_context();
    $companyId = (int) (current_company()['id'] ?? 0);
} else {
    $companyId = (int) ($currentUser['company_id'] ?? 0);
}
if ($companyId <= 0) {
    flash('error', 'Select a company first.');
    redirect('portal.php');
}

$agreementId = (int) ($_GET['id'] ?? $_POST['agreement_id'] ?? 0);
$sa = $agreementId > 0 ? agreement_get($agreementId, $companyId) : null;
if (!$sa) {
    flash('error', 'Agreement not found for this company.');
    redirect('admin/service-agreements.php');
}
if ((string) $sa['structure_mode'] !== 'builder') {
    // Classic agreements keep their original editor.
    redirect('admin/service-agreements.php?edit=' . $agreementId);
}

$editable = agreement_is_editable($sa);
$canEdit = user_can_do('agreements', 'edit');
$canReview = user_can_do('agreements', 'review');
$canApprove = user_can_do('agreements', 'approve');
$canIssue = user_can_do('agreements', 'issue');
$canManage = user_can_do('agreements', 'manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $back = 'admin/agreement-builder.php?id=' . $agreementId;
    $sectionQ = static fn (int $id): string => $id > 0 ? '&section=' . $id : '';

    // ---- Content actions: only while editable, only with agreements.edit ----
    $contentActions = ['add_section', 'update_section', 'delete_section', 'move_section', 'duplicate_section', 'save_master', 'insert_clause'];
    if (in_array($action, $contentActions, true)) {
        require_permission('agreements', 'edit');
        if (!$editable) {
            flash('error', 'This agreement is ' . agreement_workflow_label((string) $sa['workflow_status']) . ' — content is locked. Start a revision or amendment to change it.');
            redirect($back);
        }
    }

    if ($action === 'add_section') {
        $sectionId = agreement_section_add($sa, $_POST, $userId);
        flash('success', 'Section added.');
        redirect($back . $sectionQ($sectionId));
    }

    if ($action === 'update_section') {
        $sectionId = (int) ($_POST['section_id'] ?? 0);
        if (agreement_section_update($sa, $sectionId, $_POST, $userId, $canManage)) {
            flash('success', 'Section saved.');
        } else {
            flash('error', 'Section not saved — it may be locked (manage permission can override).');
        }
        redirect($back . $sectionQ($sectionId));
    }

    if ($action === 'delete_section') {
        $sectionId = (int) ($_POST['section_id'] ?? 0);
        if (agreement_section_delete($sa, $sectionId, $userId, $canManage)) {
            flash('success', 'Section deleted (children were kept and moved up one level).');
        } else {
            flash('error', 'Section not deleted — locked or mandatory sections need the manage permission.');
        }
        redirect($back);
    }

    if ($action === 'move_section') {
        $sectionId = (int) ($_POST['section_id'] ?? 0);
        agreement_section_move($sa, $sectionId, (string) ($_POST['direction'] ?? ''), $userId);
        redirect($back . $sectionQ($sectionId));
    }

    if ($action === 'duplicate_section') {
        $newId = agreement_section_duplicate($sa, (int) ($_POST['section_id'] ?? 0), $userId);
        flash($newId > 0 ? 'success' : 'error', $newId > 0 ? 'Section duplicated.' : 'Section not found.');
        redirect($back . $sectionQ($newId));
    }

    if ($action === 'insert_clause') {
        // Clause library: copy one section out of a template snapshot.
        $template = agreement_template_get((int) ($_POST['template_id'] ?? 0), $companyId);
        $key = (int) ($_POST['clause_key'] ?? 0);
        $inserted = 0;
        foreach ($template !== null ? (json_decode((string) $template['sections_json'], true) ?: []) : [] as $row) {
            if ((int) ($row['key'] ?? 0) === $key) {
                $inserted = agreement_section_add($sa, [
                    'section_type' => 'clause',
                    'title_en' => $row['title_en'] ?? null, 'title_np' => $row['title_np'] ?? null,
                    'body_en' => $row['body_en'] ?? null, 'body_np' => $row['body_np'] ?? null,
                    'is_mandatory' => !empty($row['is_mandatory']),
                    'source_template_section_id' => $key,
                ], $userId);
                break;
            }
        }
        flash($inserted > 0 ? 'success' : 'error', $inserted > 0 ? 'Clause inserted from library.' : 'Clause not found in the template.');
        redirect($back . $sectionQ($inserted));
    }

    if ($action === 'save_master') {
        $before = $sa;
        $clientId = (int) ($_POST['client_id'] ?? 0) ?: null;
        db()->prepare('UPDATE service_agreements SET client_id = :client_id, purpose_en = :purpose_en, purpose_np = :purpose_np,
                first_party_name_en = :fp_en, first_party_name_np = :fp_np, first_party_address = :fp_address,
                first_party_reg_no = :fp_reg, first_party_signatory = :fp_sig, first_party_position = :fp_pos,
                second_party_name_en = :sp_en, second_party_name_np = :sp_np, second_party_reg_no = :sp_reg,
                second_party_signatory = :sp_sig, second_party_position = :sp_pos,
                agreement_date_bs = :date_bs, effective_date = :effective, effective_date_bs = :effective_bs,
                expiry_date = :expiry, duration_months = :duration, trial_months = :trial,
                fee_trial = :fee_trial, fee_monthly = :fee_monthly, payment_days = :payment_days,
                termination_notice_days = :notice_days, cure_days = :cure_days,
                jurisdiction_en = :jur_en, jurisdiction_np = :jur_np,
                language_mode = :language_mode, prevailing_language = :prevailing,
                reviewer_id = :reviewer_id, approver_id = :approver_id, updated_by = :uid
            WHERE id = :id AND company_id = :cid')->execute([
            'client_id' => $clientId,
            'purpose_en' => trim((string) ($_POST['purpose_en'] ?? '')) ?: 'Accounting and Advisory Services',
            'purpose_np' => trim((string) ($_POST['purpose_np'] ?? '')) ?: 'लेखा तथा परामर्श सेवा',
            'fp_en' => trim((string) ($_POST['first_party_name_en'] ?? '')) ?: (string) $sa['first_party_name_en'],
            'fp_np' => trim((string) ($_POST['first_party_name_np'] ?? '')) ?: null,
            'fp_address' => trim((string) ($_POST['first_party_address'] ?? '')) ?: null,
            'fp_reg' => trim((string) ($_POST['first_party_reg_no'] ?? '')) ?: null,
            'fp_sig' => trim((string) ($_POST['first_party_signatory'] ?? '')) ?: null,
            'fp_pos' => trim((string) ($_POST['first_party_position'] ?? '')) ?: null,
            'sp_en' => trim((string) ($_POST['second_party_name_en'] ?? '')) ?: (string) $sa['second_party_name_en'],
            'sp_np' => trim((string) ($_POST['second_party_name_np'] ?? '')) ?: null,
            'sp_reg' => trim((string) ($_POST['second_party_reg_no'] ?? '')) ?: null,
            'sp_sig' => trim((string) ($_POST['second_party_signatory'] ?? '')) ?: null,
            'sp_pos' => trim((string) ($_POST['second_party_position'] ?? '')) ?: null,
            'date_bs' => trim((string) ($_POST['agreement_date_bs'] ?? '')) ?: null,
            'effective' => trim((string) ($_POST['effective_date'] ?? '')) ?: null,
            'effective_bs' => trim((string) ($_POST['effective_date_bs'] ?? '')) ?: null,
            'expiry' => trim((string) ($_POST['expiry_date'] ?? '')) ?: null,
            'duration' => max(1, (int) ($_POST['duration_months'] ?? 24)),
            'trial' => max(0, (int) ($_POST['trial_months'] ?? 1)),
            'fee_trial' => max(0.0, round((float) ($_POST['fee_trial'] ?? 0), 2)),
            'fee_monthly' => max(0.0, round((float) ($_POST['fee_monthly'] ?? 0), 2)),
            'payment_days' => max(1, (int) ($_POST['payment_days'] ?? 7)),
            'notice_days' => max(1, (int) ($_POST['termination_notice_days'] ?? 3)),
            'cure_days' => max(1, (int) ($_POST['cure_days'] ?? 7)),
            'jur_en' => trim((string) ($_POST['jurisdiction_en'] ?? '')) ?: (string) $sa['jurisdiction_en'],
            'jur_np' => trim((string) ($_POST['jurisdiction_np'] ?? '')) ?: (string) $sa['jurisdiction_np'],
            'language_mode' => in_array((string) ($_POST['language_mode'] ?? 'both'), ['np', 'en', 'both', 'both_seq'], true) ? (string) $_POST['language_mode'] : 'both',
            'prevailing' => (string) ($_POST['prevailing_language'] ?? 'np') === 'en' ? 'en' : 'np',
            'reviewer_id' => (int) ($_POST['reviewer_id'] ?? 0) ?: null,
            'approver_id' => (int) ($_POST['approver_id'] ?? 0) ?: null,
            'uid' => $userId,
            'id' => $agreementId,
            'cid' => $companyId,
        ]);
        log_field_changes('service_agreement', $agreementId, $before, [
            'client_id' => $clientId, 'purpose_en' => $_POST['purpose_en'] ?? '', 'effective_date' => $_POST['effective_date'] ?? '',
            'fee_monthly' => $_POST['fee_monthly'] ?? '', 'language_mode' => $_POST['language_mode'] ?? '',
        ], $companyId, $userId);
        log_activity('service_agreement', $agreementId, 'master_updated', 'Agreement details updated.', $userId);
        flash('success', 'Agreement details saved.');
        redirect($back);
    }

    // ---- Task links (drafting wiring; frozen once approved) ----
    if ($action === 'link_task') {
        require_permission('agreements', 'edit');
        if (agreement_is_frozen($sa)) {
            flash('error', 'Task wiring is frozen once the agreement is approved. Start a revision first.');
            redirect($back);
        }
        $result = agreement_task_link($sa, (int) ($_POST['task_id'] ?? 0), (int) ($_POST['section_id'] ?? 0) ?: null, trim((string) ($_POST['note'] ?? '')), $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Task linked.' : (string) $result['error']);
        redirect($back);
    }

    if ($action === 'unlink_task') {
        require_permission('agreements', 'edit');
        flash(agreement_task_unlink($sa, (int) ($_POST['link_id'] ?? 0), $userId) ? 'success' : 'error',
            agreement_is_frozen($sa) ? 'Task wiring is frozen once the agreement is approved.' : 'Task link removed.');
        redirect($back);
    }

    // ---- Review comments ----
    if ($action === 'add_comment') {
        if (!$canReview && !$canEdit) {
            deny_access('Missing permission agreements.review.');
        }
        agreement_comment_add($sa, (int) ($_POST['section_id'] ?? 0) ?: null, (string) ($_POST['comment'] ?? ''), $userId);
        flash('success', 'Comment recorded.');
        redirect($back);
    }

    if ($action === 'resolve_comment') {
        if (!$canReview && !$canEdit) {
            deny_access('Missing permission agreements.review.');
        }
        agreement_comment_resolve($sa, (int) ($_POST['comment_id'] ?? 0), $userId);
        redirect($back);
    }

    // ---- Workflow ----
    if ($action === 'transition') {
        $result = agreement_transition($sa, (string) ($_POST['to'] ?? ''), $currentUser, [
            'reason' => trim((string) ($_POST['reason'] ?? '')),
            'summary' => trim((string) ($_POST['summary'] ?? '')),
            'self_override' => !empty($_POST['self_override']),
            'superseded_by_id' => (int) ($_POST['superseded_by_id'] ?? 0),
        ]);
        if ($result['ok']) {
            $note = 'Workflow updated to ' . agreement_workflow_label((string) $_POST['to']) . '.';
            if (!empty($result['warnings'])) {
                $note .= ' Warnings: ' . implode(' ', $result['warnings']);
            }
            flash('success', $note);
        } else {
            flash('error', (string) $result['error']);
        }
        redirect($back);
    }

    if ($action === 'start_revision') {
        $kind = in_array((string) ($_POST['kind'] ?? ''), ['revision', 'amendment', 'renewal'], true) ? (string) $_POST['kind'] : 'revision';
        $result = agreement_revision_start($sa, $currentUser, $kind, trim((string) ($_POST['reason'] ?? '')));
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? ucfirst($kind) . ' started — the agreement is editable again as version ' . ((int) $sa['current_version'] + 1) . ' in draft. The approved snapshot stays immutable in Version history.' : (string) $result['error']);
        redirect($back);
    }

    if ($action === 'save_as_template') {
        require_permission('agreements', 'manage');
        $flat = agreement_sections_flat($agreementId);
        $rows = [];
        foreach ($flat as $row) {
            $rows[] = [
                'key' => (int) $row['id'],
                'parent_key' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'section_type' => $row['section_type'],
                'sort_order' => (int) $row['sort_order'],
                'title_en' => $row['title_en'], 'title_np' => $row['title_np'],
                'body_en' => $row['body_en'], 'body_np' => $row['body_np'],
                'is_mandatory' => (int) $row['is_mandatory'],
            ];
        }
        db()->prepare('INSERT INTO agreement_templates (company_id, name, description, service_type, sections_json, created_by)
            VALUES (:cid, :name, :description, :service_type, :json, :uid)')->execute([
            'cid' => $companyId,
            'name' => trim((string) ($_POST['template_name'] ?? '')) ?: ('Template from ' . $sa['agreement_no']),
            'description' => 'Saved from agreement ' . $sa['agreement_no'] . ' on ' . date('Y-m-d') . '.',
            'service_type' => trim((string) ($_POST['service_type'] ?? '')) ?: null,
            'json' => json_encode($rows, JSON_UNESCAPED_UNICODE),
            'uid' => $userId,
        ]);
        log_activity('service_agreement', $agreementId, 'template_saved', 'Section tree saved as a reusable template.', $userId);
        flash('success', 'Template saved to the clause library.');
        redirect($back);
    }

    flash('error', 'Unknown action.');
    redirect($back);
}

// ---------------------------------------------------------------------------
// Read model for the workspace
// ---------------------------------------------------------------------------

$sa = agreement_get($agreementId, $companyId); // Re-read after any redirect chain.
$flat = agreement_sections_flat($agreementId);
$tree = agreement_sections_tree($flat);
$byId = [];
foreach ($flat as $row) {
    $byId[(int) $row['id']] = $row;
}
$selectedId = (int) ($_GET['section'] ?? 0);
$selected = $byId[$selectedId] ?? null;

$placeholderMap = agreement_placeholder_map($sa);
$unresolved = agreement_unresolved_tokens($flat, $placeholderMap);
$validation = agreement_validate($sa, 'approved');
$linkedTasks = agreement_linked_tasks($agreementId);
$comments = agreement_comments($agreementId);
$openComments = array_values(array_filter($comments, static fn (array $c): bool => $c['status'] === 'open'));

$versionsStmt = db()->prepare('SELECT v.version_no, v.workflow_status, v.change_summary, v.created_at, u.name AS created_by_name, v.approved_at, a.name AS approved_by_name
    FROM agreement_versions v LEFT JOIN users u ON u.id = v.created_by LEFT JOIN users a ON a.id = v.approved_by
    WHERE v.agreement_id = :aid ORDER BY v.version_no DESC');
$versionsStmt->execute(['aid' => $agreementId]);
$versions = $versionsStmt->fetchAll();

$clientsStmt = db()->prepare('SELECT id, organization_name FROM client_profiles WHERE is_active = 1 AND company_id = :cid ORDER BY organization_name');
$clientsStmt->execute(['cid' => $companyId]);
$clients = $clientsStmt->fetchAll();

$staffStmt = db()->prepare("SELECT id, name, role FROM users WHERE (role = 'admin' OR role = 'staff') AND status = 'active' AND (company_id = :cid OR company_id IS NULL) ORDER BY name");
$staffStmt->execute(['cid' => $companyId]);
$staffUsers = $staffStmt->fetchAll();

$clientTasks = [];
if ((int) ($sa['client_id'] ?? 0) > 0) {
    $tasksStmt = db()->prepare('SELECT id, title, status FROM client_tasks WHERE company_id = :cid AND client_id = :client ORDER BY id DESC LIMIT 200');
    $tasksStmt->execute(['cid' => $companyId, 'client' => (int) $sa['client_id']]);
    $clientTasks = $tasksStmt->fetchAll();
}

$templates = agreement_templates_list($companyId);
$libraryClauses = [];
foreach ($templates as $template) {
    foreach (json_decode((string) $template['sections_json'], true) ?: [] as $row) {
        if (($row['section_type'] ?? 'clause') === 'clause' && trim((string) (($row['title_en'] ?? '') . ($row['title_np'] ?? ''))) !== '') {
            $libraryClauses[] = ['template_id' => (int) $template['id'], 'template_name' => (string) $template['name'], 'key' => (int) $row['key'],
                'title_en' => (string) ($row['title_en'] ?? ''), 'title_np' => (string) ($row['title_np'] ?? '')];
        }
    }
}

$historyStmt = db()->prepare("SELECT al.action, al.details, al.created_at, u.name AS actor_name
    FROM activity_logs al LEFT JOIN users u ON u.id = al.actor_id
    WHERE al.entity_type = 'service_agreement' AND al.entity_id = :aid ORDER BY al.id DESC LIMIT 30");
$historyStmt->execute(['aid' => $agreementId]);
$history = $historyStmt->fetchAll();

$compare = null;
if (isset($_GET['compare_a'], $_GET['compare_b'])) {
    $compare = agreement_versions_compare($agreementId, (int) $_GET['compare_a'], (int) $_GET['compare_b']);
}

$workflowStates = agreements_workflow_states();
$currentState = (string) $sa['workflow_status'];
$nextStates = $workflowStates[$currentState]['next'] ?? [];

$statusTone = static function (string $state): string {
    return match ($state) {
        'draft', 'changes_requested' => 'tone-amber',
        'under_review', 'reviewed', 'pending_approval', 'issued' => 'tone-blue',
        'approved', 'accepted', 'signed', 'active' => 'tone-green',
        default => 'tone-grey',
    };
};

$pageTitle = 'Agreement Builder — ' . (string) $sa['agreement_no'];
$pageSubtitle = 'Structured bilingual drafting: outline, clause editor, workflow, versions and task wiring. Numbers recalculate automatically; approved versions are immutable.';
$pageHero = ['icon' => 'contracts'];
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';

$renderOutline = function (array $nodes, int $depth = 0) use (&$renderOutline, $selectedId, $editable, $canEdit, $agreementId): void {
    foreach ($nodes as $node) {
        $id = (int) $node['id'];
        $label = (string) (($node['title_en'] ?? '') !== '' ? $node['title_en'] : ($node['title_np'] ?? '(untitled)'));
        $numberLabel = (string) ($node['section_type'] === 'schedule' ? $node['number'] : ($node['section_type'] === 'chapter' ? 'Ch. ' . $node['number'] : $node['number']));
        echo '<div class="ab-node' . ($id === $selectedId ? ' is-selected' : '') . '" style="margin-left:' . ($depth * 14) . 'px">';
        echo '<a class="ab-node-label" href="?id=' . $agreementId . '&section=' . $id . '">';
        echo '<span class="ab-num">' . e($numberLabel) . '</span> ' . e(mb_strimwidth($label, 0, 46, '…'));
        if ((int) $node['is_locked'] === 1) {
            echo ' 🔒';
        }
        if ((int) $node['is_mandatory'] === 1) {
            echo ' <span title="Mandatory" style="color:#b45309">*</span>';
        }
        echo '</a>';
        if ($editable && $canEdit) {
            echo '<span class="ab-node-tools">';
            foreach ([['up', '▲', 'Move up'], ['down', '▼', 'Move down'], ['indent', '⇥', 'Make sub-clause'], ['outdent', '⇤', 'Promote']] as [$dir, $glyph, $title]) {
                echo '<form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="move_section"><input type="hidden" name="agreement_id" value="' . $agreementId . '"><input type="hidden" name="section_id" value="' . $id . '"><input type="hidden" name="direction" value="' . $dir . '"><button type="submit" title="' . $title . '">' . $glyph . '</button></form>';
            }
            echo '</span>';
        }
        echo '</div>';
        if (!empty($node['children'])) {
            $renderOutline($node['children'], $depth + 1);
        }
    }
};
?>
<style>
    .ab-grid { display: grid; grid-template-columns: minmax(240px, 2fr) minmax(320px, 5fr) minmax(260px, 3fr); gap: 14px; align-items: start; }
    @media (max-width: 1100px) { .ab-grid { grid-template-columns: 1fr; } }
    .ab-node { display: flex; justify-content: space-between; align-items: center; gap: 4px; padding: 3px 6px; border-radius: 6px; font-size: 13px; }
    .ab-node.is-selected { background: color-mix(in srgb, var(--mbw-accent, #3b82f6) 14%, transparent); }
    .ab-node-label { text-decoration: none; color: inherit; flex: 1; min-width: 0; }
    .ab-num { font-weight: 700; color: var(--mbw-accent, #2563eb); }
    .ab-node-tools { display: none; white-space: nowrap; }
    .ab-node:hover .ab-node-tools { display: inline; }
    .ab-node-tools button { background: transparent; border: 0; cursor: pointer; padding: 0 2px; font-size: 11px; color: var(--mbw-muted, #667); }
    .ab-pillrow { display: flex; flex-wrap: wrap; gap: 6px; margin: 6px 0; }
    .ab-kv { font-size: 12.5px; margin: 3px 0; }
    .ab-kv strong { display: inline-block; min-width: 110px; }
    .ab-warn { font-size: 12.5px; color: #b45309; margin: 3px 0; }
    .ab-err { font-size: 12.5px; color: #b91c1c; margin: 3px 0; }
    .ab-history { max-height: 220px; overflow-y: auto; font-size: 12px; }
    .ab-comment { border-left: 3px solid var(--mbw-accent, #3b82f6); padding: 4px 8px; margin: 6px 0; font-size: 12.5px; }
    .ab-comment.is-resolved { opacity: .55; border-left-color: #16a34a; }
    details.ab-block { margin: 10px 0; }
    details.ab-block > summary { cursor: pointer; font-weight: 600; font-size: 13px; }
    /* Word-style rich text editor */
    .ab-rt-wrap { border: 1px solid var(--mbw-border, #c8cddb); border-radius: 8px; overflow: hidden; background: #fff; }
    .ab-rt-toolbar { display: flex; flex-wrap: wrap; gap: 2px; align-items: center; padding: 4px 6px; border-bottom: 1px solid var(--mbw-border, #c8cddb); background: var(--mbw-card-bg, #f6f7fb); }
    .ab-rt-toolbar button, .ab-rt-toolbar select { border: 1px solid transparent; background: transparent; border-radius: 5px; min-width: 26px; height: 26px; font-size: 13px; cursor: pointer; color: var(--mbw-heading, #223); display: inline-flex; align-items: center; justify-content: center; padding: 0 4px; }
    .ab-rt-toolbar button:hover, .ab-rt-toolbar select:hover { background: rgba(59, 130, 246, .15); }
    .ab-rt-toolbar button.is-on { background: rgba(59, 130, 246, .25); border-color: rgba(59, 130, 246, .4); }
    .ab-rt-toolbar select { font-size: 12px; max-width: 160px; }
    .ab-rt-toolbar .ab-sep { width: 1px; background: var(--mbw-border, #c8cddb); margin: 2px 4px; align-self: stretch; }
    .ab-rt-toolbar .ab-color { display: inline-flex; flex-direction: column; align-items: center; justify-content: center; width: 28px; height: 26px; border-radius: 5px; cursor: pointer; font-size: 12px; line-height: 1; }
    .ab-rt-toolbar .ab-color:hover { background: rgba(59, 130, 246, .15); }
    .ab-rt-toolbar .ab-color input[type=color] { width: 18px; height: 8px; border: 0; padding: 0; background: transparent; cursor: pointer; }
    .ab-rt-editor { min-height: 170px; max-height: 440px; overflow-y: auto; padding: 10px 12px; font-family: 'Noto Sans Devanagari', 'Mangal', 'Kalimati', 'Times New Roman', serif; font-size: 13.5px; line-height: 1.7; color: #111; background: #fff; outline: none; }
    .ab-rt-editor:focus { box-shadow: inset 0 0 0 2px rgba(59, 130, 246, .3); }
    .ab-rt-editor[contenteditable="false"] { background: #f4f5f8; color: #333; }
    .ab-rt-editor table { border-collapse: collapse; }
    .ab-rt-editor td, .ab-rt-editor th { border: 1px solid #888; padding: 3px 8px; min-width: 40px; }
    .ab-rt-editor ul, .ab-rt-editor ol { margin: 4px 0 4px 22px; }
    .ab-rt-editor blockquote { margin: 6px 0 6px 14px; padding-left: 10px; border-left: 3px solid #bbb; }
</style>

<section class="mbw-card">
    <div class="mbw-card-head">
        <h2><?= e((string) $sa['agreement_no']) ?> — <?= e((string) $sa['purpose_en']) ?>
            <span class="mbw-pill <?= $statusTone($currentState) ?>"><?= e(agreement_workflow_label($currentState)) ?></span>
            <span class="mbw-pill tone-grey">v<?= (int) $sa['current_version'] ?><?= $sa['approved_version'] !== null ? ' · approved v' . (int) $sa['approved_version'] : '' ?></span>
        </h2>
        <div class="mbw-card-tools">
            <a class="button secondary" target="_blank" href="<?= e(url('admin/export-agreement.php?id=' . $agreementId . '&lang=np')) ?>">Print नेपाली</a>
            <a class="button secondary" target="_blank" href="<?= e(url('admin/export-agreement.php?id=' . $agreementId . '&lang=en')) ?>">Print English</a>
            <a class="button secondary" target="_blank" href="<?= e(url('admin/export-agreement.php?id=' . $agreementId . '&lang=both')) ?>">Bilingual (side by side)</a>
            <a class="button secondary" target="_blank" href="<?= e(url('admin/export-agreement.php?id=' . $agreementId . '&lang=seq')) ?>">Bilingual (sequential)</a>
            <a class="button secondary" target="_blank" href="<?= e(url('admin/export-agreement.php?id=' . $agreementId . '&lang=' . ((string) $sa['language_mode'] === 'both_seq' ? 'seq' : (string) $sa['language_mode']) . '&format=doc')) ?>">Word (.doc)</a>
            <a class="mbw-view-all" href="<?= e(url('admin/service-agreements.php')) ?>">← Agreements</a>
        </div>
    </div>
    <?php if (!$editable): ?>
        <p class="ab-warn">🔒 Content is locked in <?= e(agreement_workflow_label($currentState)) ?>. <?= agreement_is_frozen($sa) ? 'Use “Start revision / amendment” to draft the next version — the approved snapshot stays immutable.' : 'Return it to Draft to edit.' ?></p>
    <?php endif; ?>

    <div class="ab-grid">
        <!-- ================= LEFT: OUTLINE ================= -->
        <div>
            <div class="mbw-card" style="padding:12px">
                <h3 style="margin:0 0 8px">Outline</h3>
                <?php if ($tree === []): ?><p style="font-size:12.5px;color:var(--mbw-muted)">No sections yet — add the first one below or insert from the clause library.</p><?php endif; ?>
                <?php $renderOutline($tree); ?>
                <?php if ($editable && $canEdit): ?>
                    <details class="ab-block">
                        <summary>+ Add section</summary>
                        <form method="post" style="display:grid;gap:6px;margin-top:8px">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="add_section">
                            <input type="hidden" name="agreement_id" value="<?= $agreementId ?>">
                            <select name="section_type">
                                <option value="clause">Clause</option>
                                <option value="chapter">Chapter</option>
                                <option value="schedule">Schedule / Annexure</option>
                            </select>
                            <select name="parent_id">
                                <option value="0">— top level —</option>
                                <?php foreach ($flat as $row): ?>
                                    <option value="<?= (int) $row['id'] ?>">under: <?= e(mb_strimwidth((string) (($row['title_en'] ?? '') ?: ($row['title_np'] ?? '#' . $row['id'])), 0, 40, '…')) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="title_en" placeholder="Title (English)">
                            <input type="text" name="title_np" placeholder="शीर्षक (नेपाली)">
                            <button type="submit" class="button secondary">Add</button>
                        </form>
                    </details>
                    <details class="ab-block">
                        <summary>Insert from clause library (<?= count($libraryClauses) ?>)</summary>
                        <div style="max-height:220px;overflow-y:auto;margin-top:6px">
                            <?php foreach ($libraryClauses as $clause): ?>
                                <form method="post" style="display:flex;gap:6px;align-items:center;margin:4px 0;font-size:12.5px">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="insert_clause">
                                    <input type="hidden" name="agreement_id" value="<?= $agreementId ?>">
                                    <input type="hidden" name="template_id" value="<?= $clause['template_id'] ?>">
                                    <input type="hidden" name="clause_key" value="<?= $clause['key'] ?>">
                                    <span style="flex:1;min-width:0"><?= e(mb_strimwidth($clause['title_en'] !== '' ? $clause['title_en'] : $clause['title_np'], 0, 42, '…')) ?> <small style="color:var(--mbw-muted)">(<?= e($clause['template_name']) ?>)</small></span>
                                    <button type="submit" class="button secondary" style="min-height:26px;padding:2px 8px">Insert</button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        </div>

        <!-- ================= CENTRE: EDITOR ================= -->
        <div>
            <?php if ($compare !== null): ?>
                <div class="mbw-card" style="padding:12px">
                    <h3 style="margin:0 0 8px">Compare v<?= (int) $_GET['compare_a'] ?> → v<?= (int) $_GET['compare_b'] ?> <a class="mbw-view-all" href="?id=<?= $agreementId ?>">close</a></h3>
                    <?php if (isset($compare['error'])): ?><p class="ab-err"><?= e($compare['error']) ?></p>
                    <?php else: ?>
                        <p class="ab-kv"><strong style="color:#16a34a">Added:</strong> <?= count($compare['added']) ?> · <strong style="color:#b91c1c">Removed:</strong> <?= count($compare['removed']) ?> · <strong style="color:#b45309">Modified:</strong> <?= count($compare['modified']) ?></p>
                        <?php foreach ($compare['added'] as $section): ?><p class="ab-kv" style="color:#16a34a">+ <?= e((string) (($section['title_en'] ?? '') ?: ($section['title_np'] ?? '#' . $section['id']))) ?></p><?php endforeach; ?>
                        <?php foreach ($compare['removed'] as $section): ?><p class="ab-kv" style="color:#b91c1c">− <?= e((string) (($section['title_en'] ?? '') ?: ($section['title_np'] ?? '#' . $section['id']))) ?></p><?php endforeach; ?>
                        <?php foreach ($compare['modified'] as $pair): ?>
                            <details class="ab-block"><summary style="color:#b45309">± <?= e((string) (($pair['after']['title_en'] ?? '') ?: ($pair['after']['title_np'] ?? '#' . $pair['after']['id']))) ?></summary>
                                <p class="ab-kv"><strong>Before (EN):</strong> <?= e(mb_strimwidth((string) ($pair['before']['body_en'] ?? ''), 0, 400, '…')) ?></p>
                                <p class="ab-kv"><strong>After (EN):</strong> <?= e(mb_strimwidth((string) ($pair['after']['body_en'] ?? ''), 0, 400, '…')) ?></p>
                            </details>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php elseif ($selected !== null): ?>
                <div class="mbw-card" style="padding:12px">
                    <h3 style="margin:0 0 8px">Edit section <?= (int) $selected['is_locked'] === 1 ? '🔒' : '' ?>
                        <?php if ($editable && $canEdit): ?>
                            <span style="float:right;display:flex;gap:6px">
                                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="duplicate_section"><input type="hidden" name="agreement_id" value="<?= $agreementId ?>"><input type="hidden" name="section_id" value="<?= $selectedId ?>"><button type="submit" class="button secondary" style="min-height:28px;padding:3px 10px">Duplicate</button></form>
                                <form method="post" style="display:inline" data-confirm="Delete this section? Its children move up one level."><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_section"><input type="hidden" name="agreement_id" value="<?= $agreementId ?>"><input type="hidden" name="section_id" value="<?= $selectedId ?>"><button type="submit" class="button secondary" style="min-height:28px;padding:3px 10px;color:#a33">Delete</button></form>
                            </span>
                        <?php endif; ?>
                    </h3>
                    <form method="post" class="workspace-form-grid" id="ab-section-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_section">
                        <input type="hidden" name="agreement_id" value="<?= $agreementId ?>">
                        <input type="hidden" name="section_id" value="<?= $selectedId ?>">
                        <label>Type
                            <select name="section_type" <?= $editable && $canEdit ? '' : 'disabled' ?>>
                                <?php foreach (['clause' => 'Clause', 'chapter' => 'Chapter', 'schedule' => 'Schedule / Annexure'] as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= (string) $selected['section_type'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Clause status
                            <select name="status" <?= $editable && $canEdit ? '' : 'disabled' ?>>
                                <option value="draft" <?= (string) $selected['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="final" <?= (string) $selected['status'] === 'final' ? 'selected' : '' ?>>Final</option>
                            </select>
                        </label>
                        <label>Title (English)<input type="text" name="title_en" maxlength="255" value="<?= e((string) ($selected['title_en'] ?? '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                        <label>शीर्षक (नेपाली)<input type="text" name="title_np" maxlength="255" value="<?= e((string) ($selected['title_np'] ?? '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                        <label class="workspace-span-2">Content (English)<textarea name="body_en" rows="8" class="ab-rich" <?= $editable && $canEdit ? '' : 'readonly' ?>><?= e((string) ($selected['body_en'] ?? '')) ?></textarea></label>
                        <label class="workspace-span-2">सामग्री (नेपाली)<textarea name="body_np" rows="8" class="ab-rich" <?= $editable && $canEdit ? '' : 'readonly' ?>><?= e((string) ($selected['body_np'] ?? '')) ?></textarea></label>
                        <label class="workspace-span-2">Internal drafting note (never printed for the client)<textarea name="drafting_note" rows="2" <?= $editable && $canEdit ? '' : 'readonly' ?>><?= e((string) ($selected['drafting_note'] ?? '')) ?></textarea></label>
                        <label class="workspace-span-2">Client-visible note (optional)<textarea name="client_note" rows="2" <?= $editable && $canEdit ? '' : 'readonly' ?>><?= e((string) ($selected['client_note'] ?? '')) ?></textarea></label>
                        <label><input type="checkbox" name="is_mandatory" value="1" <?= (int) $selected['is_mandatory'] === 1 ? 'checked' : '' ?> <?= $editable && $canEdit ? '' : 'disabled' ?>> Mandatory clause</label>
                        <label><input type="checkbox" name="is_locked" value="1" <?= (int) $selected['is_locked'] === 1 ? 'checked' : '' ?> <?= $canManage && $editable ? '' : 'disabled' ?>> Locked (manage permission to edit)</label>
                        <div class="workspace-span-2" style="font-size:12px;color:var(--mbw-muted)">
                            Placeholders: <?php foreach (array_keys($placeholderMap) as $token): ?><code style="cursor:pointer" onclick="abInsertToken('<?= e($token) ?>')">{{<?= e($token) ?>}}</code> <?php endforeach; ?>
                        </div>
                        <?php if ($editable && $canEdit): ?><div class="workspace-span-2"><button type="submit"><?= icon('contracts') ?>Save section</button></div><?php endif; ?>
                    </form>
                </div>
            <?php else: ?>
                <div class="mbw-card" style="padding:12px">
                    <h3 style="margin:0 0 8px">Document preview (numbers recalculate automatically)</h3>
                    <?php
                    $preview = function (array $nodes, int $depth = 0) use (&$preview, $placeholderMap): void {
                        foreach ($nodes as $node) {
                            $title = (string) (($node['title_en'] ?? '') !== '' ? $node['title_en'] : ($node['title_np'] ?? ''));
                            echo '<p style="margin:4px 0 2px ' . ($depth * 16) . 'px;font-size:13px"><strong>' . e((string) $node['number']) . '.</strong> ' . e($title) . '</p>';
                            $body = (string) ($node['body_en'] ?? '') !== '' ? (string) $node['body_en'] : (string) ($node['body_np'] ?? '');
                            if ($body !== '') {
                                echo '<p style="margin:0 0 6px ' . (($depth + 1) * 16) . 'px;font-size:12px;color:var(--mbw-muted)">' . e(mb_strimwidth(trim(strip_tags(agreement_resolve_text($body, $placeholderMap))), 0, 220, '…')) . '</p>';
                            }
                            if (!empty($node['children'])) {
                                $preview($node['children'], $depth + 1);
                            }
                        }
                    };
                    $preview($tree);
                    ?>
                    <p style="font-size:12px;color:var(--mbw-muted)">Select a section in the outline to edit it. Use the Print buttons above for the full formatted document.</p>
                </div>
            <?php endif; ?>

            <!-- Agreement details -->
            <details class="mbw-card ab-block" style="padding:12px" <?= $selected === null && $compare === null ? 'open' : '' ?>>
                <summary>Agreement details, parties, dates &amp; fees</summary>
                <form method="post" class="workspace-form-grid" style="margin-top:10px">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_master">
                    <input type="hidden" name="agreement_id" value="<?= $agreementId ?>">
                    <label>Client
                        <select name="client_id" <?= $editable && $canEdit ? '' : 'disabled' ?>>
                            <option value="0">— select —</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= (int) $client['id'] ?>" <?= (int) ($sa['client_id'] ?? 0) === (int) $client['id'] ? 'selected' : '' ?>><?= e($client['organization_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Purpose / title (English)<input type="text" name="purpose_en" value="<?= e((string) $sa['purpose_en']) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>उद्देश्य (नेपाली)<input type="text" name="purpose_np" value="<?= e((string) $sa['purpose_np']) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Language layout
                        <select name="language_mode" <?= $editable && $canEdit ? '' : 'disabled' ?>>
                            <option value="np" <?= (string) $sa['language_mode'] === 'np' ? 'selected' : '' ?>>Nepali only</option>
                            <option value="en" <?= (string) $sa['language_mode'] === 'en' ? 'selected' : '' ?>>English only</option>
                            <option value="both" <?= (string) $sa['language_mode'] === 'both' ? 'selected' : '' ?>>Bilingual — side by side</option>
                            <option value="both_seq" <?= (string) $sa['language_mode'] === 'both_seq' ? 'selected' : '' ?>>Bilingual — sequential</option>
                        </select>
                    </label>
                    <label>Prevailing language
                        <select name="prevailing_language" <?= $editable && $canEdit ? '' : 'disabled' ?>>
                            <option value="np" <?= (string) $sa['prevailing_language'] === 'np' ? 'selected' : '' ?>>नेपाली</option>
                            <option value="en" <?= (string) $sa['prevailing_language'] === 'en' ? 'selected' : '' ?>>English</option>
                        </select>
                    </label>
                    <label>First party (EN)<input type="text" name="first_party_name_en" value="<?= e((string) $sa['first_party_name_en']) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>प्रथम पक्ष (NP)<input type="text" name="first_party_name_np" value="<?= e((string) ($sa['first_party_name_np'] ?? '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>First party address<input type="text" name="first_party_address" value="<?= e((string) ($sa['first_party_address'] ?? '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>First party PAN/Reg<input type="text" name="first_party_reg_no" value="<?= e((string) ($sa['first_party_reg_no'] ?? '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>First party signatory<input type="text" name="first_party_signatory" value="<?= e((string) ($sa['first_party_signatory'] ?? '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Signatory position<input type="text" name="first_party_position" value="<?= e((string) ($sa['first_party_position'] ?? '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Second party (EN)<input type="text" name="second_party_name_en" value="<?= e((string) $sa['second_party_name_en']) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>दोस्रो पक्ष (NP)<input type="text" name="second_party_name_np" value="<?= e((string) ($sa['second_party_name_np'] ?? '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Second party PAN/Reg<input type="text" name="second_party_reg_no" value="<?= e((string) ($sa['second_party_reg_no'] ?? '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Second party signatory<input type="text" name="second_party_signatory" value="<?= e((string) ($sa['second_party_signatory'] ?? '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Signatory position<input type="text" name="second_party_position" value="<?= e((string) ($sa['second_party_position'] ?? '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Agreement date (BS)<input type="text" name="agreement_date_bs" value="<?= e((string) ($sa['agreement_date_bs'] ?? '')) ?>" placeholder="२०८३।०४।०४" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Effective date (AD)<input type="date" name="effective_date" value="<?= e((string) ($sa['effective_date'] ?? '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Effective date (BS)<input type="text" name="effective_date_bs" value="<?= e((string) ($sa['effective_date_bs'] ?? '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Expiry date (AD)<input type="date" name="expiry_date" value="<?= e((string) ($sa['expiry_date'] ?? '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Duration (months)<input type="number" min="1" name="duration_months" value="<?= (int) $sa['duration_months'] ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Trial (months)<input type="number" min="0" name="trial_months" value="<?= (int) $sa['trial_months'] ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Trial fee (monthly)<input type="number" step="0.01" min="0" name="fee_trial" value="<?= e(number_format((float) $sa['fee_trial'], 2, '.', '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Regular fee (monthly)<input type="number" step="0.01" min="0" name="fee_monthly" value="<?= e(number_format((float) $sa['fee_monthly'], 2, '.', '')) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Payment within (days)<input type="number" min="1" name="payment_days" value="<?= (int) $sa['payment_days'] ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Notice period (days)<input type="number" min="1" name="termination_notice_days" value="<?= (int) $sa['termination_notice_days'] ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Cure period (days)<input type="number" min="1" name="cure_days" value="<?= (int) $sa['cure_days'] ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Jurisdiction (EN)<input type="text" name="jurisdiction_en" value="<?= e((string) $sa['jurisdiction_en']) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>क्षेत्राधिकार (NP)<input type="text" name="jurisdiction_np" value="<?= e((string) $sa['jurisdiction_np']) ?>" <?= $editable && $canEdit ? '' : 'readonly' ?>></label>
                    <label>Reviewer
                        <select name="reviewer_id" <?= $editable && $canEdit ? '' : 'disabled' ?>>
                            <option value="0">—</option>
                            <?php foreach ($staffUsers as $staff): ?><option value="<?= (int) $staff['id'] ?>" <?= (int) ($sa['reviewer_id'] ?? 0) === (int) $staff['id'] ? 'selected' : '' ?>><?= e($staff['name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Approver
                        <select name="approver_id" <?= $editable && $canEdit ? '' : 'disabled' ?>>
                            <option value="0">—</option>
                            <?php foreach ($staffUsers as $staff): ?><option value="<?= (int) $staff['id'] ?>" <?= (int) ($sa['approver_id'] ?? 0) === (int) $staff['id'] ? 'selected' : '' ?>><?= e($staff['name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <?php if ($editable && $canEdit): ?><div class="workspace-span-2"><button type="submit">Save details</button></div><?php endif; ?>
                </form>
            </details>
        </div>

        <!-- ================= RIGHT: WORKFLOW / TASKS / VERSIONS / COMMENTS ================= -->
        <div>
            <div class="mbw-card" style="padding:12px">
                <h3 style="margin:0 0 6px">Workflow</h3>
                <div class="ab-kv"><strong>Status</strong> <span class="mbw-pill <?= $statusTone($currentState) ?>"><?= e(agreement_workflow_label($currentState)) ?></span></div>
                <?php if ((int) ($sa['submitted_by'] ?? 0) > 0): ?><div class="ab-kv"><strong>Submitted</strong> <?= e((string) $sa['submitted_at']) ?></div><?php endif; ?>
                <?php if ((int) ($sa['approved_by'] ?? 0) > 0): ?><div class="ab-kv"><strong>Approved</strong> v<?= (int) $sa['approved_version'] ?> · <?= e((string) $sa['approved_at']) ?></div><?php endif; ?>
                <?php if ((string) ($sa['issued_at'] ?? '') !== ''): ?><div class="ab-kv"><strong>Issued</strong> <?= e((string) $sa['issued_at']) ?></div><?php endif; ?>
                <?php if ((string) ($sa['accepted_at'] ?? '') !== ''): ?><div class="ab-kv"><strong>Accepted</strong> <?= e((string) $sa['accepted_at']) ?> <small>(electronic acceptance)</small></div><?php endif; ?>
                <?php if ((string) ($sa['terminated_at'] ?? '') !== ''): ?><div class="ab-kv"><strong>Terminated</strong> <?= e((string) $sa['terminated_at']) ?> — <?= e((string) $sa['termination_reason']) ?></div><?php endif; ?>

                <?php foreach ($validation['errors'] as $error): ?><p class="ab-err">✕ <?= e($error) ?></p><?php endforeach; ?>
                <?php foreach ($validation['warnings'] as $warning): ?><p class="ab-warn">⚠ <?= e($warning) ?></p><?php endforeach; ?>
                <?php if ($unresolved !== []): ?><p class="ab-warn">⚠ Unresolved placeholders: <?= e(implode(', ', $unresolved)) ?></p><?php endif; ?>

                <div class="ab-pillrow">
                    <?php foreach ($nextStates as $to => $requiredAction): ?>
                        <?php if (!user_can_do('agreements', $requiredAction)) { continue; } ?>
                        <form method="post" style="display:inline" <?= in_array($to, ['terminated', 'archived'], true) ? 'data-confirm="Move this agreement to ' . e(agreement_workflow_label($to)) . '?"' : '' ?>>
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="transition">
                            <input type="hidden" name="agreement_id" value="<?= $agreementId ?>">
                            <input type="hidden" name="to" value="<?= e($to) ?>">
                            <?php if ($to === 'terminated'): ?><input type="text" name="reason" placeholder="Termination reason (required)" required style="min-height:30px;font-size:12px">
                            <?php elseif ($to === 'approved' && (int) ($sa['submitted_by'] ?? 0) === $userId && $canManage): ?>
                                <label style="font-size:11.5px"><input type="checkbox" name="self_override" value="1"> maker-checker override (recorded)</label>
                            <?php endif; ?>
                            <button type="submit" class="button secondary" style="min-height:30px;padding:3px 10px">→ <?= e(agreement_workflow_label($to)) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
                <?php if (agreement_is_frozen($sa) && ($canEdit || $canManage)): ?>
                    <details class="ab-block">
                        <summary>Start revision / amendment / renewal</summary>
                        <form method="post" style="display:grid;gap:6px;margin-top:6px">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="start_revision">
                            <input type="hidden" name="agreement_id" value="<?= $agreementId ?>">
                            <select name="kind"><option value="revision">Revision</option><option value="amendment">Amendment</option><option value="renewal">Renewal</option></select>
                            <input type="text" name="reason" placeholder="Reason for change (required)" required>
                            <button type="submit" class="button secondary">Reopen as next version</button>
                        </form>
                    </details>
                <?php endif; ?>
            </div>

            <div class="mbw-card" style="padding:12px">
                <h3 style="margin:0 0 6px">Linked tasks (<?= count($linkedTasks) ?>)</h3>
                <?php foreach ($linkedTasks as $link): ?>
                    <div class="ab-kv" style="display:flex;gap:6px;align-items:center">
                        <span style="flex:1;min-width:0">
                            <a href="<?= e(url('admin/workspace.php?view=tasks&task_id=' . (int) $link['task_id'])) ?>">#<?= (int) $link['task_id'] ?> <?= e(mb_strimwidth((string) $link['title'], 0, 34, '…')) ?></a>
                            <span class="mbw-pill tone-grey"><?= e((string) $link['status']) ?></span>
                            <?php if ($link['assigned_staff_name']): ?><small>· <?= e((string) $link['assigned_staff_name']) ?></small><?php endif; ?>
                            <?php if ($link['section_id']): ?><br><small style="color:var(--mbw-muted)">clause: <?= e((string) (($link['section_title_en'] ?? '') ?: ($link['section_title_np'] ?? '#' . $link['section_id']))) ?></small><?php endif; ?>
                        </span>
                        <?php if (!agreement_is_frozen($sa) && $canEdit): ?>
                            <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="unlink_task"><input type="hidden" name="agreement_id" value="<?= $agreementId ?>"><input type="hidden" name="link_id" value="<?= (int) $link['link_id'] ?>"><button type="submit" class="button secondary" style="min-height:26px;padding:2px 8px">✕</button></form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($linkedTasks === []): ?><p style="font-size:12.5px;color:var(--mbw-muted)">No tasks linked yet. An agreement can be drafted without tasks; you will be warned (not blocked) at activation.</p><?php endif; ?>
                <?php if (!agreement_is_frozen($sa) && $canEdit): ?>
                    <?php if ($clientTasks === []): ?>
                        <p style="font-size:12px;color:var(--mbw-muted)"><?= (int) ($sa['client_id'] ?? 0) > 0 ? 'This client has no tasks yet — create one in the ' : 'Select a client first, then link its tasks or create one in the ' ?><a href="<?= e(url('admin/workspace.php?view=tasks')) ?>">Work Portal → Tasks</a>.</p>
                    <?php else: ?>
                        <form method="post" style="display:grid;gap:6px;margin-top:6px">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="link_task">
                            <input type="hidden" name="agreement_id" value="<?= $agreementId ?>">
                            <select name="task_id" required>
                                <option value="">Link existing task…</option>
                                <?php foreach ($clientTasks as $task): ?><option value="<?= (int) $task['id'] ?>">#<?= (int) $task['id'] ?> <?= e(mb_strimwidth((string) $task['title'], 0, 40, '…')) ?> (<?= e((string) $task['status']) ?>)</option><?php endforeach; ?>
                            </select>
                            <select name="section_id">
                                <option value="0">Whole agreement</option>
                                <?php foreach ($flat as $row): ?><option value="<?= (int) $row['id'] ?>">clause: <?= e(mb_strimwidth((string) (($row['title_en'] ?? '') ?: ($row['title_np'] ?? '#' . $row['id'])), 0, 36, '…')) ?></option><?php endforeach; ?>
                            </select>
                            <input type="text" name="note" placeholder="Deliverable / note (optional)">
                            <button type="submit" class="button secondary">Link task</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="mbw-card" style="padding:12px">
                <h3 style="margin:0 0 6px">Versions (<?= count($versions) ?>)</h3>
                <?php foreach ($versions as $version): ?>
                    <div class="ab-kv">
                        <strong>v<?= (int) $version['version_no'] ?></strong>
                        <?= e((string) ($version['change_summary'] ?? '')) ?>
                        <small style="color:var(--mbw-muted)"><?= e((string) $version['created_at']) ?> · <?= e((string) ($version['created_by_name'] ?? '')) ?><?= $version['approved_at'] !== null ? ' · ✓ approved by ' . e((string) $version['approved_by_name']) : '' ?></small>
                        <a target="_blank" href="<?= e(url('admin/export-agreement.php?id=' . $agreementId . '&version=' . (int) $version['version_no'])) ?>">print</a>
                        <?php if ((int) $version['version_no'] > 1): ?>
                            · <a href="?id=<?= $agreementId ?>&compare_a=<?= (int) $version['version_no'] - 1 ?>&compare_b=<?= (int) $version['version_no'] ?>">diff v<?= (int) $version['version_no'] - 1 ?></a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($versions === []): ?><p style="font-size:12.5px;color:var(--mbw-muted)">No versions yet — a snapshot is taken automatically when the draft is submitted for review.</p><?php endif; ?>
            </div>

            <div class="mbw-card" style="padding:12px">
                <h3 style="margin:0 0 6px">Review comments (<?= count($openComments) ?> open)</h3>
                <?php foreach ($comments as $comment): ?>
                    <div class="ab-comment <?= $comment['status'] === 'resolved' ? 'is-resolved' : '' ?>">
                        <strong><?= e((string) ($comment['author_name'] ?? 'User')) ?></strong>
                        <small><?= e((string) $comment['created_at']) ?><?= $comment['section_title'] ? ' · ' . e((string) $comment['section_title']) : '' ?><?= $comment['version_no'] ? ' · v' . (int) $comment['version_no'] : '' ?></small><br>
                        <?= nl2br(e((string) $comment['comment'])) ?>
                        <?php if ($comment['status'] === 'open' && ($canReview || $canEdit)): ?>
                            <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="resolve_comment"><input type="hidden" name="agreement_id" value="<?= $agreementId ?>"><input type="hidden" name="comment_id" value="<?= (int) $comment['id'] ?>"><button type="submit" class="button secondary" style="min-height:24px;padding:1px 8px;font-size:11px">Resolve</button></form>
                        <?php elseif ($comment['status'] === 'resolved'): ?>
                            <small style="color:#16a34a">resolved by <?= e((string) ($comment['resolver_name'] ?? '')) ?></small>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($canReview || $canEdit): ?>
                    <form method="post" style="display:grid;gap:6px;margin-top:6px">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add_comment">
                        <input type="hidden" name="agreement_id" value="<?= $agreementId ?>">
                        <select name="section_id"><option value="0">Whole agreement</option><?php foreach ($flat as $row): ?><option value="<?= (int) $row['id'] ?>" <?= $selectedId === (int) $row['id'] ? 'selected' : '' ?>><?= e(mb_strimwidth((string) (($row['title_en'] ?? '') ?: ($row['title_np'] ?? '#' . $row['id'])), 0, 40, '…')) ?></option><?php endforeach; ?></select>
                        <textarea name="comment" rows="2" placeholder="Review comment…" required></textarea>
                        <button type="submit" class="button secondary">Add comment</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($canManage): ?>
                <div class="mbw-card" style="padding:12px">
                    <h3 style="margin:0 0 6px">Templates</h3>
                    <form method="post" style="display:grid;gap:6px">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_as_template">
                        <input type="hidden" name="agreement_id" value="<?= $agreementId ?>">
                        <input type="text" name="template_name" placeholder="Save current structure as template…">
                        <input type="text" name="service_type" placeholder="Service type (bookkeeping / audit / consulting…)">
                        <button type="submit" class="button secondary">Save as template</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="mbw-card" style="padding:12px">
                <h3 style="margin:0 0 6px">History</h3>
                <div class="ab-history">
                    <?php foreach ($history as $event): ?>
                        <p style="margin:3px 0"><strong><?= e((string) ($event['actor_name'] ?? 'System')) ?></strong> <?= e((string) $event['action']) ?> <small style="color:var(--mbw-muted)"><?= e((string) $event['created_at']) ?></small><br><small><?= e(mb_strimwidth((string) ($event['details'] ?? ''), 0, 120, '…')) ?></small></p>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
// ---------------------------------------------------------------------------
// Word-style rich text editor for section bodies (no external libraries).
// Formatting is stored as HTML and sanitized server-side on save and render.
// ---------------------------------------------------------------------------
var abLastEditor = null;

function abCmd(editor, command, value) {
    editor.focus();
    if (command === 'fontSize' || command === 'fontName' || command === 'foreColor' || command === 'hiliteColor') {
        try { document.execCommand('styleWithCSS', false, true); } catch (e) {}
    }
    if (command === 'hiliteColor') {
        // Older engines call it backColor.
        if (!document.execCommand('hiliteColor', false, value)) { document.execCommand('backColor', false, value); }
    } else {
        document.execCommand(command, false, value || null);
    }
    editor.dispatchEvent(new Event('input'));
}

function abInsertTable(editor) {
    var rows = parseInt(window.prompt('Rows?', '3'), 10);
    var cols = parseInt(window.prompt('Columns?', '3'), 10);
    if (!rows || !cols || rows < 1 || cols < 1 || rows > 50 || cols > 12) { return; }
    var html = '<table style="border-collapse: collapse; width: 100%">';
    for (var r = 0; r < rows; r++) {
        html += '<tr>';
        for (var c = 0; c < cols; c++) {
            html += '<' + (r === 0 ? 'th' : 'td') + ' style="border: 1px solid #333; padding: 3px 8px">' + (r === 0 ? 'Head' : '&nbsp;') + '</' + (r === 0 ? 'th' : 'td') + '>';
        }
        html += '</tr>';
    }
    abCmd(editor, 'insertHTML', html + '</table><p><br></p>');
}

function abBuildToolbar(editor) {
    var bar = document.createElement('div');
    bar.className = 'ab-rt-toolbar';
    var fonts = ['Noto Sans Devanagari', 'Mangal', 'Kalimati', 'Times New Roman', 'Arial', 'Calibri', 'Georgia', 'Verdana', 'Courier New'];
    var sizes = [['1', '8pt'], ['2', '10pt'], ['3', '12pt'], ['4', '14pt'], ['5', '18pt'], ['6', '24pt'], ['7', '36pt']];

    function btn(label, title, fn) {
        var b = document.createElement('button');
        b.type = 'button';
        b.innerHTML = label;
        b.title = title;
        b.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep the selection
        b.addEventListener('click', function () { fn(); });
        bar.appendChild(b);
        return b;
    }
    function sep() { var s = document.createElement('span'); s.className = 'ab-sep'; bar.appendChild(s); }
    function colorPicker(glyph, title, command) {
        var wrap = document.createElement('label');
        wrap.className = 'ab-color';
        wrap.title = title;
        wrap.innerHTML = '<span>' + glyph + '</span>';
        var input = document.createElement('input');
        input.type = 'color';
        input.value = command === 'foreColor' ? '#c0392b' : '#ffff00';
        input.addEventListener('input', function () { abCmd(editor, command, input.value); });
        wrap.appendChild(input);
        bar.appendChild(wrap);
    }

    var fontSel = document.createElement('select');
    fontSel.title = 'Font family';
    fontSel.innerHTML = '<option value="">Font</option>' + fonts.map(function (f) { return '<option style="font-family:\'' + f + '\'">' + f + '</option>'; }).join('');
    fontSel.addEventListener('change', function () { if (fontSel.value) { abCmd(editor, 'fontName', fontSel.value); fontSel.selectedIndex = 0; } });
    bar.appendChild(fontSel);

    var sizeSel = document.createElement('select');
    sizeSel.title = 'Font size';
    sizeSel.innerHTML = '<option value="">Size</option>' + sizes.map(function (s) { return '<option value="' + s[0] + '">' + s[1] + '</option>'; }).join('');
    sizeSel.addEventListener('change', function () { if (sizeSel.value) { abCmd(editor, 'fontSize', sizeSel.value); sizeSel.selectedIndex = 0; } });
    bar.appendChild(sizeSel);

    sep();
    btn('<b>B</b>', 'Bold (Ctrl+B)', function () { abCmd(editor, 'bold'); });
    btn('<i>I</i>', 'Italic (Ctrl+I)', function () { abCmd(editor, 'italic'); });
    btn('<u>U</u>', 'Underline (Ctrl+U)', function () { abCmd(editor, 'underline'); });
    btn('<s>S</s>', 'Strikethrough', function () { abCmd(editor, 'strikeThrough'); });
    btn('x²', 'Superscript', function () { abCmd(editor, 'superscript'); });
    btn('x₂', 'Subscript', function () { abCmd(editor, 'subscript'); });
    sep();
    colorPicker('A', 'Text colour', 'foreColor');
    colorPicker('🖍', 'Highlight colour', 'hiliteColor');
    sep();
    btn('⯇', 'Align left', function () { abCmd(editor, 'justifyLeft'); });
    btn('≡', 'Align centre', function () { abCmd(editor, 'justifyCenter'); });
    btn('⯈', 'Align right', function () { abCmd(editor, 'justifyRight'); });
    btn('☰', 'Justify', function () { abCmd(editor, 'justifyFull'); });
    sep();
    btn('•', 'Bulleted list', function () { abCmd(editor, 'insertUnorderedList'); });
    btn('1.', 'Numbered list', function () { abCmd(editor, 'insertOrderedList'); });
    btn('⇤', 'Decrease indent', function () { abCmd(editor, 'outdent'); });
    btn('⇥', 'Increase indent', function () { abCmd(editor, 'indent'); });
    sep();
    btn('▦', 'Insert table', function () { abInsertTable(editor); });
    btn('―', 'Horizontal rule', function () { abCmd(editor, 'insertHorizontalRule'); });
    sep();
    btn('⌫<small>fmt</small>', 'Clear formatting', function () { abCmd(editor, 'removeFormat'); });
    btn('↶', 'Undo (Ctrl+Z)', function () { abCmd(editor, 'undo'); });
    btn('↷', 'Redo (Ctrl+Y)', function () { abCmd(editor, 'redo'); });
    return bar;
}

function abEscapeHtml(text) {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

(function abInitRich() {
    document.querySelectorAll('textarea.ab-rich').forEach(function (textarea) {
        var wrap = document.createElement('div');
        wrap.className = 'ab-rt-wrap';
        var editor = document.createElement('div');
        editor.className = 'ab-rt-editor';
        editor.contentEditable = textarea.readOnly ? 'false' : 'true';
        var value = textarea.value;
        // Legacy plain-text bodies become editable HTML with line breaks kept.
        editor.innerHTML = /<[a-z][a-z0-9]*(\s|>|\/)/i.test(value) ? value : abEscapeHtml(value).replace(/\n/g, '<br>');
        if (!textarea.readOnly) {
            wrap.appendChild(abBuildToolbar(editor));
        }
        wrap.appendChild(editor);
        textarea.style.display = 'none';
        textarea.parentNode.insertBefore(wrap, textarea);
        editor.addEventListener('focusin', function () { abLastEditor = editor; });
        editor.addEventListener('input', function () { textarea.value = editor.innerHTML; });
        var form = textarea.closest('form');
        if (form) {
            form.addEventListener('submit', function () { textarea.value = editor.innerHTML; });
        }
    });
})();

function abInsertToken(token) {
    var text = '{{' + token + '}}';
    var active = document.activeElement;
    if (active && active.classList && active.classList.contains('ab-rt-editor') && active.contentEditable === 'true') {
        abCmd(active, 'insertText', text);
        return;
    }
    if (abLastEditor && abLastEditor.contentEditable === 'true') {
        abCmd(abLastEditor, 'insertText', text);
        return;
    }
    var form = document.getElementById('ab-section-form');
    if (!form) { return; }
    var target = (active && (active.name === 'title_en' || active.name === 'title_np')) ? active : form.querySelector('textarea.ab-rich');
    if (!target || target.readOnly) { return; }
    var start = target.selectionStart || target.value.length;
    target.value = target.value.slice(0, start) + text + target.value.slice(target.selectionEnd || start);
    target.dispatchEvent(new Event('input'));
    target.focus();
}

// Unsaved-change warning (covers the rich editors too).
(function () {
    var dirty = false;
    document.querySelectorAll('#ab-section-form input, #ab-section-form textarea, #ab-section-form select, #ab-section-form .ab-rt-editor').forEach(function (el) {
        el.addEventListener('input', function () { dirty = true; });
    });
    document.querySelectorAll('form').forEach(function (f) { f.addEventListener('submit', function () { dirty = false; }); });
    window.addEventListener('beforeunload', function (event) {
        if (dirty) { event.preventDefault(); event.returnValue = ''; }
    });
})();
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
