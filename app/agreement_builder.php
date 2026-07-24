<?php
declare(strict_types=1);

/**
 * Structured agreement drafting engine (Working Procedures methodology).
 *
 * Agreements in structure_mode='builder' are section trees with stable IDs,
 * display numbers computed only at render time, immutable version snapshots,
 * a guarded maker-checker workflow, render-time placeholders, template
 * instantiation by snapshot, clause-level task links and client-particular
 * snapshots frozen from approval onward. Classic agreements are untouched.
 */

// ---------------------------------------------------------------------------
// Workflow
// ---------------------------------------------------------------------------

/**
 * State machine: each state lists the states it may move to and the
 * agreements.* action required to perform that move.
 */
function agreements_workflow_states(): array
{
    return [
        'draft'             => ['label' => 'Draft',             'next' => ['under_review' => 'edit', 'archived' => 'manage']],
        'under_review'      => ['label' => 'Under Review',      'next' => ['changes_requested' => 'review', 'reviewed' => 'review', 'draft' => 'edit']],
        'changes_requested' => ['label' => 'Changes Requested', 'next' => ['draft' => 'edit', 'under_review' => 'edit']],
        'reviewed'          => ['label' => 'Reviewed',          'next' => ['pending_approval' => 'review', 'draft' => 'edit']],
        'pending_approval'  => ['label' => 'Pending Approval',  'next' => ['approved' => 'approve', 'changes_requested' => 'approve']],
        'approved'          => ['label' => 'Approved',          'next' => ['issued' => 'issue', 'superseded' => 'manage', 'archived' => 'manage']],
        'issued'            => ['label' => 'Issued to Client',  'next' => ['accepted' => 'issue', 'signed' => 'issue', 'active' => 'issue', 'changes_requested' => 'manage', 'terminated' => 'manage']],
        'accepted'          => ['label' => 'Accepted',          'next' => ['signed' => 'issue', 'active' => 'issue', 'terminated' => 'manage']],
        'signed'            => ['label' => 'Signed',            'next' => ['active' => 'issue', 'terminated' => 'manage']],
        'active'            => ['label' => 'Active',            'next' => ['expired' => 'manage', 'terminated' => 'manage', 'superseded' => 'manage']],
        'expired'           => ['label' => 'Expired',           'next' => ['archived' => 'manage', 'superseded' => 'manage']],
        'terminated'        => ['label' => 'Terminated',        'next' => ['archived' => 'manage']],
        'superseded'        => ['label' => 'Superseded',        'next' => ['archived' => 'manage']],
        'archived'          => ['label' => 'Archived',          'next' => ['draft' => 'manage']],
    ];
}

function agreement_workflow_label(string $state): string
{
    return agreements_workflow_states()[$state]['label'] ?? ucfirst($state);
}

/** Content may only change while drafting. */
function agreement_is_editable(array $sa): bool
{
    return in_array((string) ($sa['workflow_status'] ?? 'draft'), ['draft', 'changes_requested'], true);
}

/** From approval onward the client snapshot and content are frozen. */
function agreement_is_frozen(array $sa): bool
{
    return in_array((string) ($sa['workflow_status'] ?? 'draft'),
        ['approved', 'issued', 'accepted', 'signed', 'active', 'expired', 'terminated', 'superseded', 'archived'], true);
}

// ---------------------------------------------------------------------------
// Fetch helpers (always company-scoped: tenant isolation at the query level)
// ---------------------------------------------------------------------------

function agreement_get(int $agreementId, int $companyId): ?array
{
    $stmt = db()->prepare('SELECT * FROM service_agreements WHERE id = :id AND company_id = :cid');
    $stmt->execute(['id' => $agreementId, 'cid' => $companyId]);
    return $stmt->fetch() ?: null;
}

function agreement_sections_flat(int $agreementId): array
{
    $stmt = db()->prepare('SELECT * FROM agreement_sections WHERE agreement_id = :aid ORDER BY sort_order ASC, id ASC');
    $stmt->execute(['aid' => $agreementId]);
    return $stmt->fetchAll();
}

/**
 * Nest the flat rows and stamp display numbers.
 *
 * Numbering rules (stable IDs internally, numbers are display-only):
 * - Schedules always number as their own series: "Schedule 1" / "अनुसूची–१".
 * - If the document uses chapters, chapters number sequentially and clauses
 *   number CONTINUOUSLY across chapters (the firm's दफा style); subclauses
 *   append .1, .2 under their clause.
 * - Without chapters, clauses use plain decimal hierarchy: 1, 1.1, 1.1.1.
 * Numbers recalculate on every render, so insert/move/delete can never leave
 * stale numbering, and moving a clause never breaks links keyed by ID.
 */
function agreement_sections_tree(array $flat): array
{
    $children = [];
    foreach ($flat as $row) {
        $children[(int) ($row['parent_id'] ?? 0)][] = $row;
    }
    $hasChapters = false;
    foreach ($children[0] ?? [] as $top) {
        if ($top['section_type'] === 'chapter') {
            $hasChapters = true;
        }
    }

    $clauseCounter = 0;
    $chapterCounter = 0;
    $scheduleCounter = 0;

    $build = function (int $parentId, string $parentNumber, int $depth) use (&$build, &$children, &$clauseCounter, &$chapterCounter, &$scheduleCounter, $hasChapters): array {
        $out = [];
        $localCounter = 0;
        foreach ($children[$parentId] ?? [] as $row) {
            $type = (string) $row['section_type'];
            if ($type === 'chapter' && $depth === 0) {
                $chapterCounter++;
                $row['number'] = (string) $chapterCounter;
                $row['number_np'] = agreement_np_digits(str_pad((string) $chapterCounter, 2, '0', STR_PAD_LEFT));
                $row['children'] = $build((int) $row['id'], '', $depth + 1);
            } elseif ($type === 'schedule' && $depth === 0) {
                $scheduleCounter++;
                $row['number'] = 'Schedule ' . $scheduleCounter;
                $row['number_np'] = 'अनुसूची–' . agreement_np_digits((string) $scheduleCounter);
                $row['children'] = $build((int) $row['id'], (string) $scheduleCounter, $depth + 1);
            } else {
                // Clause (or anything nested): continuous within chaptered
                // documents at clause level, decimal otherwise.
                if ($parentNumber === '' && ($depth === 0 || ($hasChapters && $depth === 1))) {
                    if ($hasChapters) {
                        $clauseCounter++;
                        $row['number'] = (string) $clauseCounter;
                    } else {
                        $localCounter++;
                        $row['number'] = (string) $localCounter;
                    }
                } else {
                    $localCounter++;
                    $row['number'] = ($parentNumber !== '' ? $parentNumber . '.' : '') . $localCounter;
                }
                $row['number_np'] = agreement_np_digits((string) $row['number']);
                $row['children'] = $build((int) $row['id'], (string) $row['number'], $depth + 1);
            }
            $out[] = $row;
        }
        return $out;
    };

    return $build(0, '', 0);
}

function agreement_np_digits(string $text): string
{
    return strtr($text, ['0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४', '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९']);
}

// ---------------------------------------------------------------------------
// Section CRUD (server-enforced: only while the agreement is editable)
// ---------------------------------------------------------------------------

function agreement_section_add(array $sa, array $data, int $userId): int
{
    $agreementId = (int) $sa['id'];
    $parentId = (int) ($data['parent_id'] ?? 0) ?: null;
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM agreement_sections WHERE agreement_id = :aid AND ' . ($parentId ? 'parent_id = :pid' : 'parent_id IS NULL'));
    $stmt->execute($parentId ? ['aid' => $agreementId, 'pid' => $parentId] : ['aid' => $agreementId]);
    $sort = (int) $stmt->fetchColumn();

    db()->prepare('INSERT INTO agreement_sections
        (agreement_id, parent_id, section_type, sort_order, title_en, title_np, body_en, body_np, drafting_note, client_note, is_mandatory, is_locked, source_template_section_id, created_by, updated_by)
        VALUES (:aid, :pid, :type, :sort, :title_en, :title_np, :body_en, :body_np, :note, :client_note, :mandatory, :locked, :source, :uid, :uid2)')->execute([
        'aid' => $agreementId,
        'pid' => $parentId,
        'type' => in_array((string) ($data['section_type'] ?? 'clause'), ['chapter', 'clause', 'schedule'], true) ? $data['section_type'] : 'clause',
        'sort' => $sort,
        'title_en' => trim((string) ($data['title_en'] ?? '')) ?: null,
        'title_np' => trim((string) ($data['title_np'] ?? '')) ?: null,
        'body_en' => trim((string) ($data['body_en'] ?? '')) ?: null,
        'body_np' => trim((string) ($data['body_np'] ?? '')) ?: null,
        'note' => trim((string) ($data['drafting_note'] ?? '')) ?: null,
        'client_note' => trim((string) ($data['client_note'] ?? '')) ?: null,
        'mandatory' => (int) !empty($data['is_mandatory']),
        'locked' => (int) !empty($data['is_locked']),
        'source' => (int) ($data['source_template_section_id'] ?? 0) ?: null,
        'uid' => $userId,
        'uid2' => $userId,
    ]);
    $sectionId = (int) db()->lastInsertId();
    log_activity('service_agreement', $agreementId, 'section_added', 'Section #' . $sectionId . ' added.', $userId);
    return $sectionId;
}

function agreement_section_owned(int $sectionId, int $agreementId): ?array
{
    $stmt = db()->prepare('SELECT * FROM agreement_sections WHERE id = :id AND agreement_id = :aid');
    $stmt->execute(['id' => $sectionId, 'aid' => $agreementId]);
    return $stmt->fetch() ?: null;
}

function agreement_section_update(array $sa, int $sectionId, array $data, int $userId, bool $canManage = false): bool
{
    $section = agreement_section_owned($sectionId, (int) $sa['id']);
    if (!$section) {
        return false;
    }
    if ((int) $section['is_locked'] === 1 && !$canManage) {
        return false;
    }
    $before = ['title_en' => $section['title_en'], 'title_np' => $section['title_np'], 'body_en' => $section['body_en'], 'body_np' => $section['body_np']];
    $after = [
        'title_en' => trim((string) ($data['title_en'] ?? '')) ?: null,
        'title_np' => trim((string) ($data['title_np'] ?? '')) ?: null,
        'body_en' => trim((string) ($data['body_en'] ?? '')) ?: null,
        'body_np' => trim((string) ($data['body_np'] ?? '')) ?: null,
    ];
    db()->prepare('UPDATE agreement_sections SET section_type = :type, title_en = :title_en, title_np = :title_np,
            body_en = :body_en, body_np = :body_np, drafting_note = :note, client_note = :client_note,
            is_mandatory = :mandatory, is_locked = :locked, status = :status, updated_by = :uid
        WHERE id = :id AND agreement_id = :aid')->execute([
        'type' => in_array((string) ($data['section_type'] ?? $section['section_type']), ['chapter', 'clause', 'schedule'], true) ? ($data['section_type'] ?? $section['section_type']) : 'clause',
        'title_en' => $after['title_en'],
        'title_np' => $after['title_np'],
        'body_en' => $after['body_en'],
        'body_np' => $after['body_np'],
        'note' => trim((string) ($data['drafting_note'] ?? '')) ?: null,
        'client_note' => trim((string) ($data['client_note'] ?? '')) ?: null,
        'mandatory' => (int) !empty($data['is_mandatory']),
        'locked' => $canManage ? (int) !empty($data['is_locked']) : (int) $section['is_locked'],
        'status' => (string) ($data['status'] ?? 'draft') === 'final' ? 'final' : 'draft',
        'uid' => $userId,
        'id' => $sectionId,
        'aid' => (int) $sa['id'],
    ]);
    log_field_changes('agreement_section', $sectionId, $before, $after, (int) $sa['company_id'], $userId);
    log_activity('service_agreement', (int) $sa['id'], 'section_updated', 'Section #' . $sectionId . ' edited.', $userId);
    if ((int) $section['is_locked'] === 1 && $canManage) {
        log_activity('service_agreement', (int) $sa['id'], 'locked_section_override', 'Locked section #' . $sectionId . ' edited under manage override.', $userId);
    }
    return true;
}

function agreement_section_delete(array $sa, int $sectionId, int $userId, bool $canManage = false): bool
{
    $section = agreement_section_owned($sectionId, (int) $sa['id']);
    if (!$section) {
        return false;
    }
    if (((int) $section['is_locked'] === 1 || (int) $section['is_mandatory'] === 1) && !$canManage) {
        return false;
    }
    // Re-parent children up one level so nothing silently disappears.
    db()->prepare('UPDATE agreement_sections SET parent_id = :new_parent WHERE parent_id = :id')
        ->execute(['new_parent' => $section['parent_id'], 'id' => $sectionId]);
    db()->prepare('DELETE FROM agreement_sections WHERE id = :id')->execute(['id' => $sectionId]);
    log_activity('service_agreement', (int) $sa['id'], 'section_deleted', 'Section #' . $sectionId . ' (' . ($section['title_en'] ?? $section['title_np'] ?? '') . ') deleted.', $userId);
    return true;
}

function agreement_section_move(array $sa, int $sectionId, string $direction, int $userId): bool
{
    $section = agreement_section_owned($sectionId, (int) $sa['id']);
    if (!$section) {
        return false;
    }
    $agreementId = (int) $sa['id'];
    $parentId = $section['parent_id'] !== null ? (int) $section['parent_id'] : null;

    if ($direction === 'up' || $direction === 'down') {
        $cmp = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';
        $stmt = db()->prepare("SELECT id, sort_order FROM agreement_sections
            WHERE agreement_id = :aid AND " . ($parentId ? 'parent_id = :pid' : 'parent_id IS NULL') . " AND (sort_order $cmp :sort OR (sort_order = :sort2 AND id $cmp :id))
            ORDER BY sort_order $order, id $order LIMIT 1");
        $params = ['aid' => $agreementId, 'sort' => $section['sort_order'], 'sort2' => $section['sort_order'], 'id' => $sectionId];
        if ($parentId) {
            $params['pid'] = $parentId;
        }
        $stmt->execute($params);
        $neighbour = $stmt->fetch();
        if (!$neighbour) {
            return false;
        }
        // Swap orders (make them distinct first if equal).
        $a = (int) $section['sort_order'];
        $b = (int) $neighbour['sort_order'];
        if ($a === $b) {
            $b = $direction === 'up' ? $a - 1 : $a + 1;
        }
        db()->prepare('UPDATE agreement_sections SET sort_order = :o WHERE id = :id')->execute(['o' => $b, 'id' => $sectionId]);
        db()->prepare('UPDATE agreement_sections SET sort_order = :o WHERE id = :id')->execute(['o' => $a, 'id' => (int) $neighbour['id']]);
    } elseif ($direction === 'indent') {
        // Become child of the previous sibling.
        $stmt = db()->prepare('SELECT id FROM agreement_sections
            WHERE agreement_id = :aid AND ' . ($parentId ? 'parent_id = :pid' : 'parent_id IS NULL') . ' AND (sort_order < :sort OR (sort_order = :sort2 AND id < :id))
            ORDER BY sort_order DESC, id DESC LIMIT 1');
        $params = ['aid' => $agreementId, 'sort' => $section['sort_order'], 'sort2' => $section['sort_order'], 'id' => $sectionId];
        if ($parentId) {
            $params['pid'] = $parentId;
        }
        $stmt->execute($params);
        $newParent = (int) $stmt->fetchColumn();
        if ($newParent <= 0) {
            return false;
        }
        $next = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM agreement_sections WHERE parent_id = :pid');
        $next->execute(['pid' => $newParent]);
        db()->prepare('UPDATE agreement_sections SET parent_id = :pid, sort_order = :o WHERE id = :id')
            ->execute(['pid' => $newParent, 'o' => (int) $next->fetchColumn(), 'id' => $sectionId]);
    } elseif ($direction === 'outdent') {
        if ($parentId === null) {
            return false;
        }
        $parent = agreement_section_owned($parentId, $agreementId);
        if (!$parent) {
            return false;
        }
        db()->prepare('UPDATE agreement_sections SET parent_id = :pid, sort_order = :o WHERE id = :id')
            ->execute(['pid' => $parent['parent_id'], 'o' => (int) $parent['sort_order'] + 1, 'id' => $sectionId]);
    } else {
        return false;
    }
    log_activity('service_agreement', $agreementId, 'section_moved', 'Section #' . $sectionId . ' moved ' . $direction . '.', $userId);
    return true;
}

function agreement_section_duplicate(array $sa, int $sectionId, int $userId): int
{
    $section = agreement_section_owned($sectionId, (int) $sa['id']);
    if (!$section) {
        return 0;
    }
    $copy = static function (array $row, ?int $parentId, ?string $titleSuffix) use (&$copy, $sa, $userId): int {
        $next = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM agreement_sections WHERE agreement_id = :aid AND ' . ($parentId ? 'parent_id = :pid' : 'parent_id IS NULL'));
        $next->execute($parentId ? ['aid' => (int) $sa['id'], 'pid' => $parentId] : ['aid' => (int) $sa['id']]);
        db()->prepare('INSERT INTO agreement_sections
            (agreement_id, parent_id, section_type, sort_order, title_en, title_np, body_en, body_np, drafting_note, client_note, is_mandatory, is_locked, source_template_section_id, created_by, updated_by)
            VALUES (:aid, :pid, :type, :sort, :title_en, :title_np, :body_en, :body_np, :note, :client_note, :mandatory, 0, :source, :uid, :uid2)')->execute([
            'aid' => (int) $sa['id'],
            'pid' => $parentId,
            'type' => $row['section_type'],
            'sort' => (int) $next->fetchColumn(),
            'title_en' => $row['title_en'] !== null ? $row['title_en'] . ($titleSuffix ?? '') : null,
            'title_np' => $row['title_np'],
            'body_en' => $row['body_en'],
            'body_np' => $row['body_np'],
            'note' => $row['drafting_note'],
            'client_note' => $row['client_note'],
            'mandatory' => 0,
            'source' => $row['source_template_section_id'],
            'uid' => $userId,
            'uid2' => $userId,
        ]);
        $newId = (int) db()->lastInsertId();
        $kids = db()->prepare('SELECT * FROM agreement_sections WHERE parent_id = :pid AND agreement_id = :aid ORDER BY sort_order ASC, id ASC');
        $kids->execute(['pid' => (int) $row['id'], 'aid' => (int) $sa['id']]);
        foreach ($kids->fetchAll() as $kid) {
            $copy($kid, $newId, null);
        }
        return $newId;
    };
    $newId = $copy($section, $section['parent_id'] !== null ? (int) $section['parent_id'] : null, ' (copy)');
    log_activity('service_agreement', (int) $sa['id'], 'section_duplicated', 'Section #' . $sectionId . ' duplicated as #' . $newId . '.', $userId);
    return $newId;
}

// ---------------------------------------------------------------------------
// Client snapshot, placeholders
// ---------------------------------------------------------------------------

function agreement_client_snapshot_build(int $clientId): array
{
    if ($clientId <= 0 || !table_exists('client_profiles')) {
        return [];
    }
    $stmt = db()->prepare('SELECT cp.id, cp.organization_name, cp.client_code, cp.registration_no, cp.pan_no, cp.address,
            cp.authorized_signatory_name, cp.authorized_person_position, cp.contact_number, cp.user_id,
            u.email AS contact_email, u.name AS portal_user_name
        FROM client_profiles cp LEFT JOIN users u ON u.id = cp.user_id WHERE cp.id = :id');
    $stmt->execute(['id' => $clientId]);
    $row = $stmt->fetch();
    if (!$row) {
        return [];
    }
    $row['captured_at'] = date('Y-m-d H:i:s');
    return $row;
}

/**
 * The client particulars the document renders from: frozen snapshot once the
 * agreement is approved/issued, live client master while drafting.
 */
function agreement_client_particulars(array $sa): array
{
    $snapshot = json_decode((string) ($sa['client_snapshot_json'] ?? ''), true) ?: [];
    if ($snapshot !== [] && agreement_is_frozen($sa)) {
        return $snapshot;
    }
    $live = agreement_client_snapshot_build((int) ($sa['client_id'] ?? 0));
    return $live !== [] ? $live : $snapshot;
}

function agreement_placeholder_map(array $sa): array
{
    $client = agreement_client_particulars($sa);
    $companyName = '';
    if ((int) ($sa['company_id'] ?? 0) > 0) {
        $stmt = db()->prepare('SELECT name FROM companies WHERE id = :id');
        $stmt->execute(['id' => (int) $sa['company_id']]);
        $companyName = (string) ($stmt->fetchColumn() ?: '');
    }
    $fmt = static fn (float $n): string => number_format($n, fmod($n, 1.0) > 0 ? 2 : 0);
    return [
        'agreement_number' => (string) ($sa['agreement_no'] ?? ''),
        'agreement_date' => (string) ($sa['agreement_date_bs'] ?? ''),
        'effective_date' => (string) ($sa['effective_date_bs'] ?? '') ?: (string) ($sa['effective_date'] ?? ''),
        'expiry_date' => (string) ($sa['expiry_date'] ?? ''),
        'client_legal_name_en' => (string) ($sa['first_party_name_en'] ?? ($client['organization_name'] ?? '')),
        'client_legal_name_ne' => (string) ($sa['first_party_name_np'] ?? '') ?: (string) ($sa['first_party_name_en'] ?? ''),
        'client_pan_vat' => (string) ($sa['first_party_reg_no'] ?? '') ?: (string) ($client['pan_no'] ?? ($client['registration_no'] ?? '')),
        'client_address' => (string) ($sa['first_party_address'] ?? '') ?: (string) ($client['address'] ?? ''),
        'client_signatory' => (string) ($sa['first_party_signatory'] ?? '') ?: (string) ($client['authorized_signatory_name'] ?? ''),
        'service_provider_name' => (string) ($sa['second_party_name_en'] ?? '') ?: $companyName,
        'service_provider_name_ne' => (string) ($sa['second_party_name_np'] ?? '') ?: ((string) ($sa['second_party_name_en'] ?? '') ?: $companyName),
        'service_provider_pan_vat' => (string) ($sa['second_party_reg_no'] ?? '') ?: (string) setting('company_pan', ''),
        'provider_signatory' => (string) ($sa['second_party_signatory'] ?? ''),
        'monthly_fee' => $fmt((float) ($sa['fee_monthly'] ?? 0)),
        'trial_fee' => $fmt((float) ($sa['fee_trial'] ?? 0)),
        'currency' => function_exists('site_currency_symbol') ? (string) site_currency_symbol() : 'Rs ',
        'vat_rate' => (string) setting('vat_rate', '13'),
        'payment_due_date' => (string) (int) ($sa['payment_days'] ?? 7),
        'notice_period' => (string) (int) ($sa['termination_notice_days'] ?? 3),
        'cure_period' => (string) (int) ($sa['cure_days'] ?? 7),
        'duration_months' => (string) (int) ($sa['duration_months'] ?? 24),
        'trial_months' => (string) (int) ($sa['trial_months'] ?? 1),
        'jurisdiction_en' => (string) ($sa['jurisdiction_en'] ?? ''),
        'jurisdiction_np' => (string) ($sa['jurisdiction_np'] ?? ''),
        'prevailing_language' => (string) ($sa['prevailing_language'] ?? 'np') === 'en' ? 'English (अंग्रेजी)' : 'नेपाली (Nepali)',
    ];
}

/** Resolve {{token}} markers for display/export; the stored text keeps them. */
function agreement_resolve_text(string $text, array $map): string
{
    return (string) preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', static function (array $m) use ($map): string {
        $value = $map[strtolower($m[1])] ?? null;
        return ($value !== null && $value !== '') ? (string) $value : $m[0];
    }, $text);
}

/** Tokens used anywhere in the document that currently resolve to nothing. */
function agreement_unresolved_tokens(array $flatSections, array $map): array
{
    $missing = [];
    foreach ($flatSections as $section) {
        foreach (['title_en', 'title_np', 'body_en', 'body_np'] as $field) {
            if (preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', (string) ($section[$field] ?? ''), $m)) {
                foreach ($m[1] as $token) {
                    $token = strtolower($token);
                    if ((string) ($map[$token] ?? '') === '') {
                        $missing[$token] = true;
                    }
                }
            }
        }
    }
    return array_keys($missing);
}

// ---------------------------------------------------------------------------
// Versions (immutable snapshots)
// ---------------------------------------------------------------------------

function agreement_snapshot_content(array $sa): array
{
    $master = $sa;
    unset($master['client_snapshot_json']);
    return [
        'master' => $master,
        'client' => agreement_client_particulars($sa),
        'sections' => agreement_sections_flat((int) $sa['id']),
        'task_links' => agreement_linked_tasks((int) $sa['id']),
        'snapshot_at' => date('Y-m-d H:i:s'),
    ];
}

function agreement_version_create(array $sa, ?string $summary, ?string $reason, int $userId): int
{
    $agreementId = (int) $sa['id'];
    db()->beginTransaction();
    try {
        db()->prepare('SELECT id FROM service_agreements WHERE id = :id FOR UPDATE')->execute(['id' => $agreementId]);
        $current = (int) db()->query('SELECT current_version FROM service_agreements WHERE id = ' . $agreementId)->fetchColumn();
        $versionNo = $current + 1;
        db()->prepare('INSERT INTO agreement_versions (agreement_id, version_no, workflow_status, content_json, change_summary, change_reason, created_by)
            VALUES (:aid, :v, :status, :json, :summary, :reason, :uid)')->execute([
            'aid' => $agreementId,
            'v' => $versionNo,
            'status' => (string) ($sa['workflow_status'] ?? 'draft'),
            'json' => json_encode(agreement_snapshot_content($sa), JSON_UNESCAPED_UNICODE),
            'summary' => $summary !== null && $summary !== '' ? mb_substr($summary, 0, 255) : null,
            'reason' => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'uid' => $userId,
        ]);
        db()->prepare('UPDATE service_agreements SET current_version = :v WHERE id = :id')->execute(['v' => $versionNo, 'id' => $agreementId]);
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
    log_activity('service_agreement', $agreementId, 'version_created', 'Version ' . $versionNo . ' snapshotted' . ($summary ? ': ' . $summary : '.'), $userId);
    return $versionNo;
}

function agreement_version_get(int $agreementId, int $versionNo): ?array
{
    $stmt = db()->prepare('SELECT * FROM agreement_versions WHERE agreement_id = :aid AND version_no = :v');
    $stmt->execute(['aid' => $agreementId, 'v' => $versionNo]);
    return $stmt->fetch() ?: null;
}

/** Compare two snapshots by stable section ID: added / removed / modified. */
function agreement_versions_compare(int $agreementId, int $versionA, int $versionB): array
{
    $a = agreement_version_get($agreementId, $versionA);
    $b = agreement_version_get($agreementId, $versionB);
    if (!$a || !$b) {
        return ['error' => 'Version not found.'];
    }
    $indexOf = static function (array $version): array {
        $content = json_decode((string) $version['content_json'], true) ?: [];
        $out = [];
        foreach ($content['sections'] ?? [] as $section) {
            $out[(int) $section['id']] = $section;
        }
        return $out;
    };
    $secA = $indexOf($a);
    $secB = $indexOf($b);
    $added = $removed = $modified = [];
    foreach ($secB as $id => $section) {
        if (!isset($secA[$id])) {
            $added[] = $section;
            continue;
        }
        foreach (['title_en', 'title_np', 'body_en', 'body_np', 'section_type', 'parent_id', 'sort_order'] as $field) {
            if ((string) ($secA[$id][$field] ?? '') !== (string) ($section[$field] ?? '')) {
                $modified[] = ['before' => $secA[$id], 'after' => $section];
                break;
            }
        }
    }
    foreach ($secA as $id => $section) {
        if (!isset($secB[$id])) {
            $removed[] = $section;
        }
    }
    return ['added' => $added, 'removed' => $removed, 'modified' => $modified];
}

// ---------------------------------------------------------------------------
// Numbering (concurrency-safe, assigned at issue, never changed after)
// ---------------------------------------------------------------------------

function agreement_number_assign(array $sa, int $userId): string
{
    $current = (string) ($sa['agreement_no'] ?? '');
    if ($current !== '' && !str_starts_with($current, 'DRAFT-')) {
        return $current; // Already final — numbers never silently change.
    }
    $companyId = (int) $sa['company_id'];
    db()->beginTransaction();
    try {
        db()->prepare('SELECT id FROM companies WHERE id = :cid FOR UPDATE')->execute(['cid' => $companyId]);
        $fyLabel = date('Y');
        if (table_exists('fiscal_years')) {
            $fy = db()->prepare('SELECT label FROM fiscal_years WHERE company_id = :cid AND start_date <= :d AND end_date >= :d2 ORDER BY id DESC LIMIT 1');
            $fy->execute(['cid' => $companyId, 'd' => date('Y-m-d'), 'd2' => date('Y-m-d')]);
            $fyLabel = str_replace(['/', ' '], '-', (string) ($fy->fetchColumn() ?: date('Y')));
        }
        $clientCode = 'GEN';
        if ((int) ($sa['client_id'] ?? 0) > 0) {
            $cc = db()->prepare('SELECT client_code FROM client_profiles WHERE id = :id');
            $cc->execute(['id' => (int) $sa['client_id']]);
            $clientCode = strtoupper((string) ($cc->fetchColumn() ?: 'GEN')) ?: 'GEN';
        }
        $prefix = 'SA-' . $fyLabel . '-';
        $seqStmt = db()->prepare("SELECT COUNT(*) FROM service_agreements WHERE company_id = :cid AND agreement_no LIKE :prefix");
        $seqStmt->execute(['cid' => $companyId, 'prefix' => $prefix . '%']);
        $sequence = (int) $seqStmt->fetchColumn() + 1;
        do {
            $number = $prefix . $clientCode . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
            $dupe = db()->prepare('SELECT id FROM service_agreements WHERE agreement_no = :no AND company_id = :cid AND id != :id');
            $dupe->execute(['no' => $number, 'cid' => $companyId, 'id' => (int) $sa['id']]);
            $sequence++;
        } while ($dupe->fetchColumn());
        db()->prepare('UPDATE service_agreements SET agreement_no = :no WHERE id = :id')->execute(['no' => $number, 'id' => (int) $sa['id']]);
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
    log_activity('service_agreement', (int) $sa['id'], 'number_assigned', 'Final agreement number ' . $number . ' assigned.', $userId);
    return $number;
}

// ---------------------------------------------------------------------------
// Stage validation
// ---------------------------------------------------------------------------

/**
 * Errors block the transition; warnings are shown but do not block.
 */
function agreement_validate(array $sa, string $targetState): array
{
    $errors = [];
    $warnings = [];
    $flat = (string) ($sa['structure_mode'] ?? 'classic') === 'builder' ? agreement_sections_flat((int) $sa['id']) : [];
    $map = agreement_placeholder_map($sa);

    if (in_array($targetState, ['under_review', 'pending_approval', 'approved', 'issued', 'active'], true)) {
        if ((int) ($sa['client_id'] ?? 0) <= 0) {
            $errors[] = 'A client must be selected.';
        }
        if (trim((string) ($sa['purpose_en'] ?? '')) === '') {
            $errors[] = 'The agreement title/purpose (English) is required.';
        }
        if (trim((string) ($sa['first_party_name_en'] ?? '')) === '' || trim((string) ($sa['second_party_name_en'] ?? '')) === '') {
            $errors[] = 'Both parties must be named.';
        }
        if ((string) ($sa['structure_mode'] ?? 'classic') === 'builder' && $flat === []) {
            $errors[] = 'The agreement has no content sections.';
        }
        $incomplete = 0;
        foreach ($flat as $section) {
            $hasEn = trim((string) ($section['title_en'] ?? '') . (string) ($section['body_en'] ?? '')) !== '';
            $hasNp = trim((string) ($section['title_np'] ?? '') . (string) ($section['body_np'] ?? '')) !== '';
            if ($hasEn !== $hasNp) {
                $incomplete++;
            }
        }
        if ($incomplete > 0) {
            $warnings[] = $incomplete . ' section(s) have content in only one language.';
        }
        $unresolved = agreement_unresolved_tokens($flat, $map);
        if ($unresolved !== []) {
            $warnings[] = 'Unresolved placeholders: ' . implode(', ', array_map(static fn ($t) => '{{' . $t . '}}', $unresolved));
        }
    }
    if (in_array($targetState, ['approved', 'issued', 'active'], true)) {
        foreach ($flat as $section) {
            if ((int) $section['is_mandatory'] === 1 && trim((string) ($section['body_en'] ?? '') . (string) ($section['body_np'] ?? '')) === '') {
                $errors[] = 'Mandatory section "' . ((string) ($section['title_en'] ?? $section['title_np'] ?? ('#' . $section['id']))) . '" is empty.';
            }
        }
        if ((string) ($sa['effective_date'] ?? '') === '' && (string) ($sa['effective_date_bs'] ?? '') === '') {
            $errors[] = 'An effective date is required.';
        }
    }
    if ($targetState === 'active') {
        $links = agreement_linked_tasks((int) $sa['id']);
        if ($links === []) {
            $warnings[] = 'No task is linked to this agreement yet.';
        }
        foreach ($links as $link) {
            if (empty($link['assigned_staff_user_id']) && empty($link['team_id'])) {
                $warnings[] = 'Task #' . $link['task_id'] . ' (' . $link['title'] . ') has no responsible staff or team.';
            }
        }
    }
    if ($targetState === 'terminated' ) {
        // Reason enforced in agreement_transition (comes via $opts).
    }
    return ['errors' => $errors, 'warnings' => $warnings];
}

// ---------------------------------------------------------------------------
// Workflow transitions
// ---------------------------------------------------------------------------

/**
 * Perform a guarded lifecycle transition. Returns ['ok' => bool, 'error' => ?,
 * 'warnings' => []]. All writes and side effects (snapshots, numbering,
 * contract sync, audit) happen here so every caller shares one rulebook.
 */
function agreement_transition(array $sa, string $to, array $user, array $opts = []): array
{
    // Always transition from the CURRENT database state, never from a stale
    // caller array — otherwise two callers could replay the same transition.
    $fresh = agreement_get((int) $sa['id'], (int) $sa['company_id']);
    if ($fresh !== null) {
        $sa = $fresh;
    }
    $states = agreements_workflow_states();
    $from = (string) ($sa['workflow_status'] ?? 'draft');
    $userId = (int) ($user['id'] ?? 0);
    if (!isset($states[$from]['next'][$to])) {
        return ['ok' => false, 'error' => 'Transition ' . $from . ' → ' . $to . ' is not allowed.'];
    }
    $requiredAction = $states[$from]['next'][$to];
    if (!user_can_do('agreements', $requiredAction, $user)) {
        return ['ok' => false, 'error' => 'Missing permission agreements.' . $requiredAction . '.'];
    }

    // Maker-checker: the submitter must not approve their own agreement unless
    // the manage override is explicitly invoked (and recorded).
    if ($to === 'approved') {
        $submittedBy = (int) ($sa['submitted_by'] ?? 0);
        if ($submittedBy > 0 && $submittedBy === $userId) {
            if (empty($opts['self_override']) || !user_can_do('agreements', 'manage', $user)) {
                return ['ok' => false, 'error' => 'Maker-checker: you submitted this agreement, so another authorized user must approve it (or use the recorded manage override).'];
            }
            log_activity('service_agreement', (int) $sa['id'], 'self_approval_override', 'Maker-checker override used by submitter for approval.', $userId);
        }
    }

    $validation = agreement_validate($sa, $to);
    if ($validation['errors'] !== []) {
        return ['ok' => false, 'error' => implode(' ', $validation['errors']), 'warnings' => $validation['warnings']];
    }
    if ($to === 'terminated' && trim((string) ($opts['reason'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'A termination reason is required.'];
    }

    $now = date('Y-m-d H:i:s');
    $set = ['workflow_status' => $to];
    $params = [];

    switch ($to) {
        case 'under_review':
            // Lock the submitted content as a version and refresh the client
            // snapshot (drafts may refresh; approved+ snapshots are frozen).
            $version = agreement_version_create($sa, $opts['summary'] ?? 'Submitted for review', $opts['reason'] ?? null, $userId);
            $set['submitted_by'] = $userId;
            $set['submitted_at'] = $now;
            $set['client_snapshot_json'] = json_encode(agreement_client_snapshot_build((int) ($sa['client_id'] ?? 0)), JSON_UNESCAPED_UNICODE);
            break;
        case 'reviewed':
            $set['reviewed_by'] = $userId;
            $set['reviewed_at'] = $now;
            break;
        case 'pending_approval':
            // Approval is against a specific immutable version.
            if ((int) ($sa['current_version'] ?? 0) === 0) {
                agreement_version_create($sa, 'Snapshot for approval', null, $userId);
            }
            break;
        case 'approved':
            $sa = agreement_get((int) $sa['id'], (int) $sa['company_id']) ?? $sa;
            $version = (int) ($sa['current_version'] ?? 0);
            if ($version === 0) {
                $version = agreement_version_create($sa, 'Snapshot at approval', null, $userId);
            }
            db()->prepare('UPDATE agreement_versions SET approved_by = :uid, approved_at = :at WHERE agreement_id = :aid AND version_no = :v')
                ->execute(['uid' => $userId, 'at' => $now, 'aid' => (int) $sa['id'], 'v' => $version]);
            $set['approved_by'] = $userId;
            $set['approved_at'] = $now;
            $set['approved_version'] = $version;
            $set['status'] = 'final';
            $set['client_snapshot_json'] = json_encode(agreement_client_snapshot_build((int) ($sa['client_id'] ?? 0)), JSON_UNESCAPED_UNICODE);
            if ((string) ($sa['expiry_date'] ?? '') === '' && (string) ($sa['effective_date'] ?? '') !== '') {
                $set['expiry_date'] = date('Y-m-d', strtotime((string) $sa['effective_date'] . ' +' . (int) ($sa['duration_months'] ?? 24) . ' months -1 day'));
            }
            break;
        case 'issued':
            agreement_number_assign($sa, $userId);
            $set['issued_by'] = $userId;
            $set['issued_at'] = $now;
            break;
        case 'accepted':
            $set['accepted_at'] = $now;
            $set['accepted_by_user_id'] = (int) ($opts['accepted_by_user_id'] ?? $userId);
            $set['acceptance_note'] = mb_substr(trim((string) ($opts['note'] ?? 'Electronic acceptance recorded.')), 0, 255);
            $set['acceptance_ip'] = mb_substr((string) ($opts['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45);
            break;
        case 'active':
            $set['activated_at'] = $now;
            break;
        case 'terminated':
            $set['terminated_at'] = $now;
            $set['termination_reason'] = mb_substr(trim((string) $opts['reason']), 0, 255);
            break;
        case 'superseded':
            $set['superseded_by_id'] = (int) ($opts['superseded_by_id'] ?? 0) ?: null;
            break;
        case 'archived':
            $set['archived_at'] = $now;
            break;
        case 'draft':
            if ($from === 'archived') {
                $set['archived_at'] = null;
                log_activity('service_agreement', (int) $sa['id'], 'restored', 'Agreement restored from archive.', $userId);
            }
            break;
    }

    $sql = 'UPDATE service_agreements SET ' . implode(', ', array_map(static fn ($k) => "`$k` = :$k", array_keys($set))) . ' WHERE id = :id AND company_id = :cid';
    $params = $set + ['id' => (int) $sa['id'], 'cid' => (int) $sa['company_id']];
    db()->prepare($sql)->execute($params);

    // Keep the linked Work Portal contract's status wired to the agreement.
    if ((int) ($sa['contract_id'] ?? 0) > 0 && table_exists('service_contracts')) {
        $contractStatus = null;
        if ($to === 'active') {
            $contractStatus = 'active';
        } elseif ($to === 'terminated') {
            $contractStatus = 'terminated';
        } elseif (in_array($to, ['expired', 'superseded'], true)) {
            $contractStatus = 'completed';
        }
        if ($contractStatus !== null) {
            db()->prepare('UPDATE service_contracts SET status = :s WHERE id = :id AND company_id = :cid')
                ->execute(['s' => $contractStatus, 'id' => (int) $sa['contract_id'], 'cid' => (int) $sa['company_id']]);
            log_activity('service_contract', (int) $sa['contract_id'], 'status_synced', 'Contract status set to ' . $contractStatus . ' by agreement workflow.', $userId);
        }
    }

    log_activity('service_agreement', (int) $sa['id'], 'workflow_' . $to, 'Workflow: ' . $from . ' → ' . $to . ((string) ($opts['reason'] ?? '') !== '' ? ' (' . $opts['reason'] . ')' : '') . '.', $userId);
    return ['ok' => true, 'warnings' => $validation['warnings']];
}

/**
 * Start a revision/amendment/renewal on a frozen agreement: the approved
 * snapshot stays immutable in agreement_versions; drafting reopens on the live
 * section tree as the NEXT version.
 */
function agreement_revision_start(array $sa, array $user, string $kind, string $reason): array
{
    if (!agreement_is_frozen($sa)) {
        return ['ok' => false, 'error' => 'Only approved or later agreements need a revision — drafts are directly editable.'];
    }
    if (!user_can_do('agreements', 'manage', $user) && !user_can_do('agreements', 'edit', $user)) {
        return ['ok' => false, 'error' => 'Missing permission agreements.edit.'];
    }
    if (trim($reason) === '') {
        return ['ok' => false, 'error' => 'A reason for the ' . $kind . ' is required.'];
    }
    // Snapshot the frozen state once more (belt and braces), then reopen.
    agreement_version_create($sa, ucfirst($kind) . ' started', $reason, (int) $user['id']);
    db()->prepare("UPDATE service_agreements SET workflow_status = 'draft', status = 'draft', submitted_by = NULL, submitted_at = NULL,
            reviewed_by = NULL, reviewed_at = NULL WHERE id = :id AND company_id = :cid")
        ->execute(['id' => (int) $sa['id'], 'cid' => (int) $sa['company_id']]);
    log_activity('service_agreement', (int) $sa['id'], $kind . '_started', ucfirst($kind) . ' drafting started: ' . $reason, (int) $user['id']);
    return ['ok' => true];
}

// ---------------------------------------------------------------------------
// Task links (reuses client_tasks — no parallel task system)
// ---------------------------------------------------------------------------

function agreement_linked_tasks(int $agreementId): array
{
    if (!table_exists('agreement_task_links')) {
        return [];
    }
    $stmt = db()->prepare('SELECT atl.id AS link_id, atl.task_id, atl.section_id, atl.note,
            t.title, t.status, t.priority, t.start_date, t.due_date, t.assigned_staff_user_id, t.team_id,
            u.name AS assigned_staff_name, s.title_en AS section_title_en, s.title_np AS section_title_np
        FROM agreement_task_links atl
        INNER JOIN client_tasks t ON t.id = atl.task_id
        LEFT JOIN users u ON u.id = t.assigned_staff_user_id
        LEFT JOIN agreement_sections s ON s.id = atl.section_id
        WHERE atl.agreement_id = :aid ORDER BY atl.id ASC');
    $stmt->execute(['aid' => $agreementId]);
    return $stmt->fetchAll();
}

function agreement_task_link(array $sa, int $taskId, ?int $sectionId, ?string $note, int $userId): array
{
    // The task must belong to the same company (and same client when set).
    $stmt = db()->prepare('SELECT id, client_id FROM client_tasks WHERE id = :id AND company_id = :cid');
    $stmt->execute(['id' => $taskId, 'cid' => (int) $sa['company_id']]);
    $task = $stmt->fetch();
    if (!$task) {
        return ['ok' => false, 'error' => 'Task not found in this company.'];
    }
    if ((int) ($sa['client_id'] ?? 0) > 0 && (int) $task['client_id'] !== (int) $sa['client_id']) {
        return ['ok' => false, 'error' => 'That task belongs to a different client.'];
    }
    if ($sectionId !== null && !agreement_section_owned($sectionId, (int) $sa['id'])) {
        return ['ok' => false, 'error' => 'Section not found on this agreement.'];
    }
    try {
        db()->prepare('INSERT INTO agreement_task_links (agreement_id, task_id, section_id, note, created_by) VALUES (:aid, :tid, :sid, :note, :uid)')
            ->execute(['aid' => (int) $sa['id'], 'tid' => $taskId, 'sid' => $sectionId, 'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null, 'uid' => $userId]);
    } catch (Throwable $exception) {
        return ['ok' => false, 'error' => 'That task is already linked to this agreement.'];
    }
    log_activity('service_agreement', (int) $sa['id'], 'task_linked', 'Task #' . $taskId . ' linked.', $userId);
    return ['ok' => true];
}

function agreement_task_unlink(array $sa, int $linkId, int $userId): bool
{
    if (agreement_is_frozen($sa)) {
        return false; // Task wiring is part of the frozen record.
    }
    $stmt = db()->prepare('SELECT task_id FROM agreement_task_links WHERE id = :id AND agreement_id = :aid');
    $stmt->execute(['id' => $linkId, 'aid' => (int) $sa['id']]);
    $taskId = $stmt->fetchColumn();
    if ($taskId === false) {
        return false;
    }
    db()->prepare('DELETE FROM agreement_task_links WHERE id = :id')->execute(['id' => $linkId]);
    log_activity('service_agreement', (int) $sa['id'], 'task_unlinked', 'Task #' . (int) $taskId . ' unlinked.', $userId);
    return true;
}

// ---------------------------------------------------------------------------
// Comments
// ---------------------------------------------------------------------------

function agreement_comments(int $agreementId): array
{
    $stmt = db()->prepare('SELECT c.*, u.name AS author_name, r.name AS resolver_name, s.title_en AS section_title
        FROM agreement_comments c
        LEFT JOIN users u ON u.id = c.created_by
        LEFT JOIN users r ON r.id = c.resolved_by
        LEFT JOIN agreement_sections s ON s.id = c.section_id
        WHERE c.agreement_id = :aid ORDER BY c.status ASC, c.id DESC');
    $stmt->execute(['aid' => $agreementId]);
    return $stmt->fetchAll();
}

function agreement_comment_add(array $sa, ?int $sectionId, string $comment, int $userId): bool
{
    if (trim($comment) === '') {
        return false;
    }
    db()->prepare('INSERT INTO agreement_comments (agreement_id, section_id, version_no, comment, created_by) VALUES (:aid, :sid, :v, :comment, :uid)')
        ->execute(['aid' => (int) $sa['id'], 'sid' => $sectionId ?: null, 'v' => (int) ($sa['current_version'] ?? 0) ?: null, 'comment' => trim($comment), 'uid' => $userId]);
    log_activity('service_agreement', (int) $sa['id'], 'comment_added', 'Review comment added.', $userId);
    return true;
}

function agreement_comment_resolve(array $sa, int $commentId, int $userId): bool
{
    $stmt = db()->prepare("UPDATE agreement_comments SET status = 'resolved', resolved_by = :uid, resolved_at = :at WHERE id = :id AND agreement_id = :aid AND status = 'open'");
    $stmt->execute(['uid' => $userId, 'at' => date('Y-m-d H:i:s'), 'id' => $commentId, 'aid' => (int) $sa['id']]);
    if ($stmt->rowCount() > 0) {
        log_activity('service_agreement', (int) $sa['id'], 'comment_resolved', 'Review comment #' . $commentId . ' resolved.', $userId);
        return true;
    }
    return false;
}

// ---------------------------------------------------------------------------
// Templates (instantiate by snapshot; edits never touch existing agreements)
// ---------------------------------------------------------------------------

function agreement_templates_list(int $companyId, bool $includeArchived = false): array
{
    $stmt = db()->prepare('SELECT * FROM agreement_templates WHERE company_id = :cid' . ($includeArchived ? '' : ' AND archived = 0') . ' ORDER BY is_default DESC, name ASC');
    $stmt->execute(['cid' => $companyId]);
    return $stmt->fetchAll();
}

function agreement_template_get(int $templateId, int $companyId): ?array
{
    $stmt = db()->prepare('SELECT * FROM agreement_templates WHERE id = :id AND company_id = :cid');
    $stmt->execute(['id' => $templateId, 'cid' => $companyId]);
    return $stmt->fetch() ?: null;
}

/**
 * The firm's standard bilingual service-agreement template as a section tree.
 * Placeholders keep it reusable for any purpose (bookkeeping, internal audit,
 * consulting…) — values resolve from the agreement at render time.
 * Structure: chapters → clauses (continuous दफा numbering) + 2 schedules.
 */
function agreement_default_template_sections(): array
{
    $ch = static fn (string $np, string $en, array $children) => ['type' => 'chapter', 'title_np' => $np, 'title_en' => $en, 'children' => $children];
    $cl = static fn (string $np, string $en, string $bodyNp, string $bodyEn, bool $mandatory = false, array $children = []) => ['type' => 'clause', 'title_np' => $np, 'title_en' => $en, 'body_np' => $bodyNp, 'body_en' => $bodyEn, 'mandatory' => $mandatory, 'children' => $children];

    return [
        $ch('प्रारम्भ तथा परिभाषा', 'Commencement and Definitions', [
            $cl('करारका पक्ष तथा प्रारम्भ', 'Parties and Commencement',
                "यो सम्झौता मिति {{agreement_date}} मा {{client_legal_name_ne}} (ठेगाना: {{client_address}}, दर्ता/स्थायी लेखा नं: {{client_pan_vat}}) (यसपछि «प्रथम पक्ष» भनिने) र {{service_provider_name_ne}} (यसपछि «दोस्रो पक्ष» भनिने) का बीच सम्पन्न भएको छ। सेवा प्रवाह मिति {{effective_date}} देखि सुरु हुनेछ।",
                'This Agreement is made on {{agreement_date}} between {{client_legal_name_en}} (Address: {{client_address}}, Registration/PAN No: {{client_pan_vat}}) (the "First Party") and {{service_provider_name}} (the "Second Party"). Delivery of services begins from {{effective_date}}.', true),
            $cl('परिभाषा', 'Definitions',
                "«सम्झौता» भन्नाले यो सम्झौता पत्र, यसका अनुसूचीहरू र लिखित संशोधनसमेत सम्झनु पर्छ। «सेवा» भन्नाले अनुसूची–१ मा उल्लिखित कार्यहरू सम्झनु पर्छ। «परीक्षण अवधि» भन्नाले कार्यान्वयन मितिदेखिको पहिलो {{trial_months}} महिना सम्झनु पर्छ। «व्यावसायिक शुल्क» भन्नाले अनुसूची–२ बमोजिमको शुल्क सम्झनु पर्छ।",
                '"Agreement" means this agreement, its schedules and written amendments. "Services" means the work described in Schedule 1. "Trial Period" means the first {{trial_months}} month(s) from the effective date. "Professional Fee" means the fee under Schedule 2.'),
        ]),
        $ch('उद्देश्य, अवधि तथा सेवा क्षेत्र', 'Objective, Term and Scope of Service', [
            $cl('उद्देश्य', 'Objective',
                'प्रथम पक्षले आफ्नो व्यवसायका लागि सेवा लिन चाहेको र दोस्रो पक्षले सो सेवा व्यावसायिक रूपमा उपलब्ध गराउन मञ्जुर गरेकोले अनुसूची–१ को कार्यक्षेत्रभित्र रही यो सम्झौता गरिएको छ।',
                'The First Party wishes to obtain the services and the Second Party agrees to provide them professionally; this Agreement is made for delivery within the scope of Schedule 1.'),
            $cl('अवधि', 'Term',
                'यस सम्झौताको अवधि कार्यान्वयन मितिदेखि {{duration_months}} महिनाको हुनेछ। पहिलो {{trial_months}} महिना परीक्षण अवधि हुनेछ। दुवै पक्षको लिखित सहमतिबाट नवीकरण गर्न सकिनेछ।',
                'The term is {{duration_months}} months from the effective date; the first {{trial_months}} month(s) is a Trial Period. It may be renewed by written consent of both parties.'),
        ]),
        $ch('दोस्रो पक्षको कार्य तथा दायित्व', 'Duties and Obligations of the Second Party', [
            $cl('सेवा प्रदान सम्बन्धी दायित्व', 'Service Delivery Obligations',
                "(क) अनुसूची–१ का सेवाहरू व्यावसायिक दक्षता र सावधानीका साथ उपलब्ध गराउने।\n(ख) प्रथम पक्षले उपलब्ध गराएका कागजातका आधारमा लेखा तथा प्रतिवेदन तयार गर्ने।\n(ग) जानकारी, कागजात र रकम समयमै प्राप्त भएमा वैधानिक विवरणहरू तोकिएको समयभित्र दाखिला गर्ने।\n(घ) आवश्यक दक्ष जनशक्ति व्यवस्था गर्ने।\n(ङ) महत्वपूर्ण जोखिम देखिएमा समयमै लिखित जानकारी दिने।\n(च) प्रचलित कानून र व्यावसायिक मापदण्डको अधीनमा रही कार्य गर्ने।",
                "(a) Provide the Schedule 1 services with professional competence and due care.\n(b) Prepare accounts and reports from documents provided by the First Party.\n(c) File statutory returns within deadlines when information, documents and funds arrive on time.\n(d) Arrange competent manpower.\n(e) Give timely written notice of material risks.\n(f) Work subject to prevailing law and professional standards."),
            $cl('नियामकीय जानकारी', 'Regulatory Information',
                'प्रथम पक्षको व्यवसायसँग सम्बन्धित कानूनी परिवर्तन तथा वैधानिक म्यादबारे समयमै जानकारी तथा सचेतना गराउने।',
                'Keep the First Party informed of relevant legal changes and alert it in time about statutory deadlines.'),
            $cl('जनशक्ति व्यवस्था', 'Manpower Arrangement',
                '(१) दैनिक कार्यका लागि एक जना समर्पित कर्मचारी खटाइनेछ। (२) समीक्षा तथा सुपरिवेक्षणका लागि अर्को कर्मचारी महिनामा दुई पटक उपलब्ध हुनेछ। (३) चार्टर्ड एकाउन्टेन्टले महिनामा कम्तीमा एक पटक समीक्षा गर्नेछ; भौतिक उपस्थिति सम्भव नभएमा भर्चुअल माध्यमबाट गरिनेछ।',
                '(1) One dedicated staff member will be assigned for daily work. (2) A second staff member will be available twice a month for review and supervision. (3) A Chartered Accountant will review at least monthly, virtually where physical presence is not possible.'),
        ]),
        $ch('प्रथम पक्षको कार्य तथा दायित्व', 'Duties and Obligations of the First Party', [
            $cl('प्रथम पक्षको दायित्व', 'Obligations of the First Party',
                "(क) आवश्यक सम्पूर्ण कागजात तथा जानकारी सही, पूर्ण र समयमै उपलब्ध गराउने।\n(ख) खटिएका कर्मचारीलाई कार्यस्थल, अभिलेख र प्रणालीमा पहुँच दिने।\n(ग) कर कार्यालय, बैंक तथा पोर्टलका लागि पहुँच, OTP र प्रमाणीकरण समयमै दिने।\n(घ) पेस भएका विवरणमा समयमै निर्णय तथा स्वीकृति दिने।\n(ङ) कर, शुल्क र जरिवानाको रकम समयमै व्यवस्था गर्ने।\n(च) दिइएका जानकारीको सत्यताको जिम्मेवारी लिने।\n(छ) व्यवस्थापकीय निर्णय आफैँ गर्ने।\n(ज) व्यावसायिक शुल्क समयमै भुक्तानी गर्ने।",
                "(a) Provide all documents and information accurately, completely and on time.\n(b) Give assigned staff workspace and access to records and systems.\n(c) Provide portal access, OTPs and authentication for tax offices and banks in time.\n(d) Decide on and approve submissions in time.\n(e) Arrange funds for taxes, fees and fines on time.\n(f) Remain responsible for the truth of information provided.\n(g) Make its own management decisions.\n(h) Pay the Professional Fee on time.", true),
        ]),
        $ch('व्यावसायिक शुल्क तथा भुक्तानी', 'Professional Fee and Payment', [
            $cl('व्यावसायिक शुल्क तथा भुक्तानी', 'Professional Fee and Payment',
                "(क) परीक्षण अवधिको शुल्क मासिक {{currency}}{{trial_fee}} मा थप मूल्य अभिवृद्धि कर हुनेछ।\n(ख) नियमित शुल्क मासिक {{currency}}{{monthly_fee}} मा थप मूल्य अभिवृद्धि कर हुनेछ।\n(ग) प्रत्येक महिनाको बीजक अर्को महिनाको {{payment_due_date}} दिनभित्र भुक्तानी गर्नुपर्नेछ।\n(घ) प्रचलित कानूनबमोजिम अग्रिम कर कट्टी गर्न सकिनेछ।\n(ङ) सरकारी दस्तुर तथा अनुसूची–१ बाहिरका कार्य शुल्कमा समावेश छैनन्।\n(च) भुक्तानी नभएमा लिखित सूचना दिई सेवा स्थगन गर्न सकिनेछ।",
                "(a) The Trial Period fee is {{currency}}{{trial_fee}} per month plus VAT.\n(b) The regular fee is {{currency}}{{monthly_fee}} per month plus VAT.\n(c) Each month's invoice is payable within {{payment_due_date}} days of the following month.\n(d) Advance/withholding tax may be deducted per prevailing law.\n(e) Government fees and work outside Schedule 1 are excluded.\n(f) On non-payment, services may be suspended after written notice.", true),
        ]),
        $ch('गोपनीयता, अभिलेख तथा बौद्धिक सम्पत्ति', 'Confidentiality, Records and Intellectual Property', [
            $cl('गोपनीयता', 'Confidentiality',
                'दुवै पक्षले एकअर्काको व्यावसायिक, वित्तीय तथा व्यक्तिगत जानकारी गोप्य राख्नेछन्; कानून वा नियामकको आदेश अपवाद हुनेछ। यो दायित्व सम्झौता अन्त्यपछि पनि कायम रहनेछ।',
                "Each party keeps the other's business, financial and personal information confidential, except disclosure required by law or a regulator. This survives termination.", true),
            $cl('अभिलेखको स्वामित्व', 'Ownership of Records',
                'प्रथम पक्षका लेखा पुस्तक तथा मूल कागजातको स्वामित्व प्रथम पक्षमै रहनेछ; अन्त्यमा बक्यौता फछ्र्योटपछि फिर्ता गरिनेछ। दोस्रो पक्षले कार्यपत्रको प्रति राख्न सक्नेछ।',
                'The First Party owns its books and original documents; they are returned on termination after settlement of dues. The Second Party may retain working-paper copies.'),
            $cl('बौद्धिक सम्पत्ति', 'Intellectual Property',
                'दोस्रो पक्षका कार्यविधि, ढाँचा, टेम्प्लेट तथा सफ्टवेयर उपकरणको स्वामित्व दोस्रो पक्षमै रहनेछ।',
                'The Second Party retains ownership of its methodologies, formats, templates and software tools.'),
        ]),
        $ch('कार्यसीमा तथा उत्तरदायित्व', 'Scope Limitation and Responsibility', [
            $cl('कार्यसीमा तथा उत्तरदायित्व', 'Scope Limitation and Responsibility',
                '(क) यस सेवा वैधानिक लेखापरीक्षण वा औपचारिक कानूनी राय होइन। (ख) दोस्रो पक्षको उत्तरदायित्व प्राप्त जानकारीका आधारमा व्यावसायिक सावधानी सम्ममा सीमित रहनेछ। (ग) गलत वा अपूर्ण जानकारीको परिणामप्रति दोस्रो पक्ष उत्तरदायी हुने छैन। (घ) कुल उत्तरदायित्व सम्बन्धित अवधिको शुल्कको सीमाभित्र रहनेछ।',
                '(a) The services are not a statutory audit or a formal legal opinion. (b) Responsibility is limited to professional care on information provided. (c) The Second Party is not responsible for consequences of wrong or incomplete information. (d) Total liability shall not exceed the fee received for the relevant period.', true),
        ]),
        $ch('संशोधन, स्थगन तथा अन्त्य', 'Amendment, Suspension and Termination', [
            $cl('संशोधन', 'Amendment', 'यस सम्झौतामा संशोधन दुवै पक्षको लिखित सहमतिबाट मात्र हुनेछ।', 'Amendments are valid only with the written consent of both parties.'),
            $cl('परीक्षण अवधिमा अन्त्य', 'Termination During Trial Period',
                'परीक्षण अवधिभित्र कुनै पक्षले लिखित सूचना दिई सम्झौता अन्त्य गर्न सक्नेछ; सेवा अवधिसम्मको शुल्क भुक्तानी गर्नुपर्नेछ।',
                'Either party may terminate during the Trial Period by written notice; fees for the period served remain payable.'),
            $cl('सामान्य अन्त्य', 'General Termination',
                'कुनै पक्षले {{notice_period}} दिनको अग्रिम लिखित सूचना दिई सम्झौता अन्त्य गर्न सक्नेछ।',
                'Either party may terminate by giving {{notice_period}} days\' prior written notice.'),
            $cl('उल्लङ्घनमा अन्त्य', 'Termination for Breach',
                'गम्भीर उल्लङ्घनमा {{cure_period}} दिनभित्र सच्याउन लिखित सूचना दिइनेछ; नसच्याएमा तत्काल अन्त्य गर्न सकिनेछ।',
                'On material breach, written notice to cure within {{cure_period}} days will be given; failing cure, immediate termination follows.'),
            $cl('हस्तान्तरण', 'Handover',
                'अन्त्यमा बक्यौता फछ्र्योटपछि अभिलेख, पहुँच तथा जिम्मेवारी व्यवस्थित रूपमा हस्तान्तरण गरिनेछ।',
                'On termination, records, access and responsibilities are handed over in an orderly manner after settlement of dues.'),
        ]),
        $ch('सूचना, विवाद समाधान तथा विविध', 'Notices, Dispute Resolution and Miscellaneous', [
            $cl('सूचना', 'Notices',
                'सूचना माथि उल्लिखित ठेगानामा लिखित रूपमा दिइनेछ; प्राप्ति स्वीकार भएमा इमेल पनि मान्य हुनेछ।',
                'Notices are given in writing to the stated addresses; e-mail is valid where receipt is acknowledged.'),
            $cl('विवाद समाधान', 'Dispute Resolution',
                'विवाद पहिले आपसी छलफलबाट समाधान गरिनेछ; नभएमा {{jurisdiction_np}}बाट नेपाल कानूनबमोजिम टुङ्गो लगाइनेछ। व्याख्यामा द्विविधा भएमा {{prevailing_language}} पाठ मान्य हुनेछ।',
                'Disputes are first resolved by mutual discussion, failing which they are settled under the laws of Nepal by {{jurisdiction_en}}. If interpretations conflict, the {{prevailing_language}} text prevails.', true),
            $cl('सम्पूर्ण सम्झौता', 'Entire Agreement',
                'यो सम्झौता र अनुसूचीहरू नै सम्पूर्ण समझदारी हुन्; यसअघिका समझदारी प्रतिस्थापित हुनेछन्।',
                'This Agreement and its schedules are the entire understanding and supersede prior understandings.'),
            $cl('आंशिक अमान्यता', 'Severability',
                'कुनै व्यवस्था अमान्य ठहरिए पनि बाँकी व्यवस्था कायम रहनेछन्।',
                'If any provision is held invalid, the remaining provisions continue in force.'),
            $cl('सम्झौताका प्रति', 'Counterparts',
                'समान मान्यताका दुई प्रति तयार गरी दुवै पक्षले एक–एक प्रति राख्नेछन्।',
                'Executed in two counterparts of equal validity, one retained by each party.'),
        ]),
        ['type' => 'schedule', 'title_np' => 'सेवाको विस्तृत कार्यक्षेत्र', 'title_en' => 'Detailed Scope of Services', 'children' => [
            $cl('बुक किपिङ', 'Bookkeeping', 'प्रथम पक्षका प्रमाणका आधारमा नगद, बैंक, बिक्री, खरिद, खर्च र आवश्यक जर्नल प्रविष्टि; खाता अद्यावधिक; बैंक मिलान; मासिक ट्रायल ब्यालेन्स।', 'Record cash, bank, sales, purchase, expense and journal entries from evidence provided; keep ledgers updated; reconcile banks; prepare a monthly trial balance.'),
            $cl('VAT विवरण दाखिला', 'VAT Return Filing', 'कर अवधिको बीजक सङ्कलन र मिलान; VAT विवरण मस्यौदा; स्वीकृति र कर दाखिलापछि विद्युतीय दाखिला; प्रमाण संरक्षण।', 'Collect and reconcile invoices for the tax period; draft the VAT return; file electronically after approval and tax payment; preserve evidence.'),
            $cl('e-TDS विवरण दाखिला', 'e-TDS Return Filing', 'तलब, सेवा, भाडा लगायतका भुक्तानीको TDS गणना र मिलान; e-TDS विवरण तयारी र दाखिला; प्रमाण संरक्षण।', 'Compute and reconcile TDS on salary, service, rent and other payments; prepare and file the e-TDS return; preserve evidence.'),
            $cl('मासिक प्रतिवेदन', 'Monthly Reporting', 'मासिक Profit or Loss प्रतिवेदन, KPI सारांश तथा मुख्य टिप्पणी।', 'Monthly Profit or Loss report, KPI summary and key notes.'),
            $cl('तलब विवरण तयारी', 'Salary Sheet Preparation', 'स्वीकृत हाजिरी तथा दरका आधारमा मासिक salary sheet र कट्टी सारांश।', 'Monthly salary sheet and deduction summary from approved attendance and rates.'),
        ]],
        ['type' => 'schedule', 'title_np' => 'शुल्क तथा भुक्तानी तालिका', 'title_en' => 'Fee and Payment Schedule', 'children' => [
            $cl('शुल्क तालिका', 'Fee Table',
                "परीक्षण अवधि (पहिलो {{trial_months}} महिना): मासिक {{currency}}{{trial_fee}} + VAT।\nनियमित अवधि: मासिक {{currency}}{{monthly_fee}} + VAT।\nभुक्तानी: अर्को महिनाको {{payment_due_date}} दिनभित्र। सरकारी दस्तुर, कर, जरिवाना तथा अनुसूची–१ बाहिरका कार्य छुट्टै हुनेछन्।",
                "Trial Period (first {{trial_months}} month(s)): {{currency}}{{trial_fee}} per month + VAT.\nRegular period: {{currency}}{{monthly_fee}} per month + VAT.\nPayment within {{payment_due_date}} days of the following month. Government fees, taxes, fines and out-of-scope work are charged separately.", true),
        ]],
    ];
}

/** Flatten the nested default-template definition into template JSON rows. */
function agreement_template_sections_json(array $nested): string
{
    $rows = [];
    $counter = 0;
    $walk = function (array $items, ?int $parentKey) use (&$walk, &$rows, &$counter): void {
        foreach ($items as $index => $item) {
            $counter++;
            $key = $counter;
            $rows[] = [
                'key' => $key,
                'parent_key' => $parentKey,
                'section_type' => $item['type'] ?? 'clause',
                'sort_order' => $index + 1,
                'title_en' => $item['title_en'] ?? null,
                'title_np' => $item['title_np'] ?? null,
                'body_en' => $item['body_en'] ?? null,
                'body_np' => $item['body_np'] ?? null,
                'is_mandatory' => (int) !empty($item['mandatory']),
            ];
            if (!empty($item['children'])) {
                $walk($item['children'], $key);
            }
        }
    };
    $walk($nested, null);
    return json_encode($rows, JSON_UNESCAPED_UNICODE);
}

/** Ensure the firm-standard default template exists for this company. */
function agreement_template_seed_default(int $companyId, int $userId): int
{
    $stmt = db()->prepare('SELECT id FROM agreement_templates WHERE company_id = :cid AND archived = 0 ORDER BY is_default DESC, id ASC LIMIT 1');
    $stmt->execute(['cid' => $companyId]);
    $existing = (int) $stmt->fetchColumn();
    if ($existing > 0) {
        return $existing;
    }
    db()->prepare('INSERT INTO agreement_templates (company_id, name, description, service_type, sections_json, is_default, created_by)
        VALUES (:cid, :name, :description, :service_type, :json, 1, :uid)')->execute([
        'cid' => $companyId,
        'name' => 'Standard Service Agreement (bilingual)',
        'description' => "The firm's standard bilingual agreement: 9 chapters, continuous clause numbering, scope and fee schedules.",
        'service_type' => 'general',
        'json' => agreement_template_sections_json(agreement_default_template_sections()),
        'uid' => $userId,
    ]);
    return (int) db()->lastInsertId();
}

/**
 * Instantiate a template as a new builder agreement: master row + section
 * snapshot. Later template edits never touch this agreement.
 */
function agreement_create_from_template(int $companyId, ?int $templateId, array $master, int $userId): int
{
    $template = $templateId !== null ? agreement_template_get($templateId, $companyId) : null;
    // A linked Work Portal contract contributes its number as the draft number.
    if ((string) ($master['agreement_no'] ?? '') === '' && (int) ($master['contract_id'] ?? 0) > 0 && table_exists('service_contracts')) {
        $contractNoStmt = db()->prepare('SELECT contract_no FROM service_contracts WHERE id = :id AND company_id = :cid');
        $contractNoStmt->execute(['id' => (int) $master['contract_id'], 'cid' => $companyId]);
        $master['agreement_no'] = (string) ($contractNoStmt->fetchColumn() ?: '');
    }
    db()->prepare("INSERT INTO service_agreements
            (company_id, client_id, contract_id, agreement_no, purpose_en, purpose_np, first_party_name_en, first_party_name_np,
             first_party_address, first_party_reg_no, first_party_signatory, first_party_position,
             second_party_name_en, second_party_name_np, effective_date, duration_months, trial_months,
             fee_trial, fee_monthly, structure_mode, workflow_status, language_mode, prevailing_language,
             template_id, owner_id, status, created_by)
        VALUES (:cid, :client_id, :contract_id, :agreement_no, :purpose_en, :purpose_np, :fp_en, :fp_np,
             :fp_address, :fp_reg, :fp_sig, :fp_pos, :sp_en, :sp_np, :effective_date, :duration, :trial,
             :fee_trial, :fee_monthly, 'builder', 'draft', :language_mode, :prevailing, :template_id, :owner, 'draft', :uid)")->execute([
        'cid' => $companyId,
        'client_id' => (int) ($master['client_id'] ?? 0) ?: null,
        'contract_id' => (int) ($master['contract_id'] ?? 0) ?: null,
        'agreement_no' => (string) ($master['agreement_no'] ?? '') ?: null, // NULL now; DRAFT- stamped below.
        'purpose_en' => trim((string) ($master['purpose_en'] ?? '')) ?: 'Accounting and Advisory Services',
        'purpose_np' => trim((string) ($master['purpose_np'] ?? '')) ?: 'लेखा तथा परामर्श सेवा',
        'fp_en' => trim((string) ($master['first_party_name_en'] ?? '')) ?: 'Client',
        'fp_np' => trim((string) ($master['first_party_name_np'] ?? '')) ?: null,
        'fp_address' => trim((string) ($master['first_party_address'] ?? '')) ?: null,
        'fp_reg' => trim((string) ($master['first_party_reg_no'] ?? '')) ?: null,
        'fp_sig' => trim((string) ($master['first_party_signatory'] ?? '')) ?: null,
        'fp_pos' => trim((string) ($master['first_party_position'] ?? '')) ?: null,
        'sp_en' => trim((string) ($master['second_party_name_en'] ?? '')) ?: 'Service Provider',
        'sp_np' => trim((string) ($master['second_party_name_np'] ?? '')) ?: null,
        'effective_date' => (string) ($master['effective_date'] ?? '') ?: null,
        'duration' => max(1, (int) ($master['duration_months'] ?? 24)),
        'trial' => max(0, (int) ($master['trial_months'] ?? 1)),
        'fee_trial' => max(0.0, (float) ($master['fee_trial'] ?? 0)),
        'fee_monthly' => max(0.0, (float) ($master['fee_monthly'] ?? 0)),
        'language_mode' => in_array((string) ($master['language_mode'] ?? ''), ['np', 'en', 'both', 'both_seq'], true) ? (string) $master['language_mode'] : 'both',
        'prevailing' => (string) ($master['prevailing_language'] ?? 'np') === 'en' ? 'en' : 'np',
        'template_id' => $template !== null ? (int) $template['id'] : null,
        'owner' => $userId,
        'uid' => $userId,
    ]);
    $agreementId = (int) db()->lastInsertId();
    if ((string) ($master['agreement_no'] ?? '') === '') {
        db()->prepare('UPDATE service_agreements SET agreement_no = :no WHERE id = :id')
            ->execute(['no' => 'DRAFT-' . $agreementId, 'id' => $agreementId]);
    }

    if ($template !== null) {
        $rows = json_decode((string) $template['sections_json'], true) ?: [];
        $idByKey = [];
        foreach ($rows as $row) {
            $parentKey = $row['parent_key'] ?? null;
            db()->prepare('INSERT INTO agreement_sections
                    (agreement_id, parent_id, section_type, sort_order, title_en, title_np, body_en, body_np, is_mandatory, source_template_section_id, created_by, updated_by)
                VALUES (:aid, :pid, :type, :sort, :title_en, :title_np, :body_en, :body_np, :mandatory, :source, :uid, :uid2)')->execute([
                'aid' => $agreementId,
                'pid' => $parentKey !== null ? ($idByKey[(int) $parentKey] ?? null) : null,
                'type' => in_array((string) ($row['section_type'] ?? 'clause'), ['chapter', 'clause', 'schedule'], true) ? $row['section_type'] : 'clause',
                'sort' => (int) ($row['sort_order'] ?? 0),
                'title_en' => $row['title_en'] ?? null,
                'title_np' => $row['title_np'] ?? null,
                'body_en' => $row['body_en'] ?? null,
                'body_np' => $row['body_np'] ?? null,
                'mandatory' => (int) !empty($row['is_mandatory']),
                'source' => (int) ($row['key'] ?? 0) ?: null,
                'uid' => $userId,
                'uid2' => $userId,
            ]);
            $idByKey[(int) $row['key']] = (int) db()->lastInsertId();
        }
    }
    log_activity('service_agreement', $agreementId, 'created', 'Structured agreement drafted' . ($template !== null ? ' from template "' . $template['name'] . '"' : '') . '.', $userId);
    return $agreementId;
}
