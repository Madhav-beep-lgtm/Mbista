<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/agreement_builder.php';

$viewerUser = current_user();
if (!$viewerUser) {
    flash('error', 'Please log in first.');
    redirect('login.php');
}
accounting_module_repair_database();

$viewerRole = (string) ($viewerUser['role'] ?? '');
$agreementId = (int) ($_GET['id'] ?? 0);
$lang = (string) ($_GET['lang'] ?? '');
$format = (string) ($_GET['format'] ?? 'html');
$versionNo = (int) ($_GET['version'] ?? 0);
$isClientView = false;

if ($viewerRole === 'admin') {
    require_admin();
    require_company_context();
    $companyId = (int) (current_company()['id'] ?? 0);
    $sa = agreement_get($agreementId, $companyId);
} elseif ($viewerRole === 'staff') {
    require_permission('agreements', 'view');
    $companyId = (int) ($viewerUser['company_id'] ?? 0);
    $sa = agreement_get($agreementId, $companyId);
} else {
    // Client portal: only THIS client's own agreements, and only once issued.
    $isClientView = true;
    $stmt = db()->prepare("SELECT sa.* FROM service_agreements sa
        INNER JOIN client_profiles cp ON cp.id = sa.client_id
        WHERE sa.id = :id AND cp.user_id = :uid
          AND sa.workflow_status IN ('issued','accepted','signed','active','expired','terminated','superseded')");
    $stmt->execute(['id' => $agreementId, 'uid' => (int) $viewerUser['id']]);
    $sa = $stmt->fetch() ?: null;
    $companyId = $sa !== null ? (int) $sa['company_id'] : 0;
}
if (!$sa) {
    http_response_code(404);
    exit('Agreement not found.');
}
if ($lang === '') {
    $lang = (string) ($sa['language_mode'] ?? 'both') === 'both_seq' ? 'seq' : (string) ($sa['language_mode'] ?? 'both');
}
if (!in_array($lang, ['np', 'en', 'both', 'seq'], true)) {
    $lang = 'both';
}
log_activity('service_agreement', $agreementId, $format === 'doc' ? 'exported_doc' : 'printed', 'Agreement ' . ($sa['agreement_no'] ?? '') . ' rendered (' . $lang . ($versionNo > 0 ? ', v' . $versionNo : '') . ')' . ($isClientView ? ' by client portal user.' : '.'), (int) $viewerUser['id']);

// ---------------------------------------------------------------------------
// BUILDER agreements (and version snapshots) render from the section tree.
// Classic agreements continue to the fixed-format renderer further below.
// ---------------------------------------------------------------------------
$renderSa = $sa;
$builderSections = null;
if ($versionNo > 0) {
    $version = agreement_version_get($agreementId, $versionNo);
    if (!$version) {
        http_response_code(404);
        exit('Version not found.');
    }
    $snapshot = json_decode((string) $version['content_json'], true) ?: [];
    $renderSa = array_merge($sa, $snapshot['master'] ?? []);
    $renderSa['client_snapshot_json'] = json_encode($snapshot['client'] ?? [], JSON_UNESCAPED_UNICODE);
    $renderSa['workflow_status'] = 'approved'; // Render copy only: forces the frozen client snapshot path.
    $builderSections = $snapshot['sections'] ?? [];
} elseif ((string) $sa['structure_mode'] === 'builder') {
    $builderSections = agreement_sections_flat($agreementId);
}

if ($builderSections !== null) {
    agreement_builder_render($renderSa, $builderSections, $lang, $format, $isClientView, $versionNo);
    exit;
}
if ($lang === 'seq') {
    $lang = 'both'; // Classic layout has one bilingual style.
}

/**
 * Print-ready renderer for structured (builder) agreements: cover page, TOC,
 * numbered section tree, signature block, schedules — in Nepali, English,
 * bilingual side-by-side, or bilingual sequential layout. $format 'doc' sends
 * Word headers. Client view never includes internal drafting notes.
 */
function agreement_builder_render(array $sa, array $flat, string $lang, string $format, bool $isClientView, int $versionNo): void
{
    $tree = agreement_sections_tree($flat);
    $map = agreement_placeholder_map($sa);
    $showNp = $lang !== 'en';
    $showEn = $lang !== 'np';
    $sideBySide = $lang === 'both';

    $resolve = static fn (?string $text): string => agreement_resolve_text((string) $text, $map);
    $npd = static fn (string $t): string => agreement_np_digits($t);

    $fpNp = (string) ($sa['first_party_name_np'] ?? '') !== '' ? (string) $sa['first_party_name_np'] : (string) $sa['first_party_name_en'];
    $fpEn = (string) $sa['first_party_name_en'];
    $spNp = (string) ($sa['second_party_name_np'] ?? '') !== '' ? (string) $sa['second_party_name_np'] : (string) $sa['second_party_name_en'];
    $spEn = (string) $sa['second_party_name_en'];
    $dateBs = (string) ($sa['agreement_date_bs'] ?? '');
    $titleNp = (string) $sa['purpose_np'] . ' सेवा प्रवाह सम्बन्धी सम्झौता पत्र';
    $titleEn = 'Service Agreement for ' . (string) $sa['purpose_en'];
    $docTitle = ($showNp ? $titleNp : $titleEn) . ' — ' . (string) $sa['agreement_no'];
    $statusLabel = agreement_workflow_label((string) ($sa['workflow_status'] ?? 'draft'));
    $isDraftDoc = !agreement_is_frozen($sa) && $versionNo === 0;

    // Heading + body in the selected layout for one section node.
    $sectionHtml = function (array $node, int $depth) use (&$sectionHtml, $showNp, $showEn, $sideBySide, $resolve, $map, $isClientView): string {
        $type = (string) $node['section_type'];
        $titleN = trim((string) ($node['title_np'] ?? ''));
        $titleE = trim((string) ($node['title_en'] ?? ''));
        $bodyN = trim((string) ($node['body_np'] ?? ''));
        $bodyE = trim((string) ($node['body_en'] ?? ''));
        $clientNote = trim((string) ($node['client_note'] ?? ''));

        $headNp = $headEn = '';
        if ($type === 'chapter') {
            $headNp = 'परिच्छेद – ' . (string) $node['number_np'] . ' : ' . $titleN;
            $headEn = 'Chapter ' . (string) $node['number'] . ' : ' . $titleE;
        } elseif ($type === 'schedule') {
            $headNp = (string) $node['number_np'] . ' : ' . $titleN;
            $headEn = (string) $node['number'] . ' : ' . $titleE;
        } else {
            $headNp = 'दफा ' . (string) $node['number_np'] . ' : ' . $titleN;
            $headEn = 'Clause ' . (string) $node['number'] . ' : ' . $titleE;
        }

        $html = '<div class="sec sec-' . $type . ' depth-' . $depth . '">';
        $tag = $type === 'chapter' || $type === 'schedule' ? 'h2' : ($depth <= 1 ? 'h3' : 'h4');
        if ($showNp && $showEn) {
            $html .= "<$tag>" . e($headNp !== ' : ' && $titleN !== '' ? $headNp : $headEn) . ($titleE !== '' && $titleN !== '' ? ' <span class="en-inline">(' . e($headEn) . ')</span>' : '') . "</$tag>";
        } elseif ($showNp) {
            $html .= "<$tag>" . e($titleN !== '' ? $headNp : $headEn) . "</$tag>";
        } else {
            $html .= "<$tag>" . e($titleE !== '' ? $headEn : $headNp) . "</$tag>";
        }

        if ($sideBySide && $showNp && $showEn && ($bodyN !== '' || $bodyE !== '')) {
            $html .= '<div class="cols"><div class="col">' . ($bodyE !== '' ? agreement_render_body($bodyE, $map) : '') . '</div>'
                . '<div class="col">' . ($bodyN !== '' ? agreement_render_body($bodyN, $map) : '') . '</div></div>';
        } else {
            if ($showEn && $bodyE !== '') {
                $html .= '<div class="body-en">' . agreement_render_body($bodyE, $map) . '</div>';
            }
            if ($showNp && $bodyN !== '') {
                $html .= '<div class="body-np' . ($showEn ? ' alt' : '') . '">' . agreement_render_body($bodyN, $map) . '</div>';
            }
        }
        if ($clientNote !== '') {
            $html .= '<div class="client-note">' . agreement_render_body($clientNote, $map) . '</div>';
        }
        // Internal drafting notes are NEVER rendered, in any view.
        foreach ($node['children'] ?? [] as $child) {
            $html .= $sectionHtml($child, $depth + 1);
        }
        return $html . '</div>';
    };

    $mainSections = array_values(array_filter($tree, static fn (array $n): bool => $n['section_type'] !== 'schedule'));
    $schedules = array_values(array_filter($tree, static fn (array $n): bool => $n['section_type'] === 'schedule'));

    if ($format === 'doc') {
        header('Content-Type: application/msword; charset=UTF-8');
        header('Content-Disposition: attachment; filename="Agreement-' . preg_replace('/[^A-Za-z0-9\-]/', '_', (string) $sa['agreement_no']) . ($versionNo > 0 ? '-v' . $versionNo : '') . '.doc"');
    } else {
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<!DOCTYPE html><html lang="' . ($lang === 'en' ? 'en' : 'ne') . '"><head><meta charset="UTF-8"><title>' . e($docTitle) . '</title><style>
        body { font-family: "Noto Sans Devanagari", "Mangal", "Kalimati", "Times New Roman", serif; font-size: 12.5pt; line-height: 1.7; color: #111; margin: 0; background: #f1f1f4; }
        .sheet { max-width: 820px; margin: 0 auto; background: #fff; padding: 48px 58px; }
        .toolbar { position: sticky; top: 0; background: #1d1f2a; color: #fff; padding: 10px 16px; display: flex; gap: 10px; align-items: center; justify-content: center; z-index: 9; font-family: Segoe UI, sans-serif; font-size: 13px; }
        .toolbar a, .toolbar button { background: #3b82f6; border: 0; color: #fff; padding: 7px 14px; border-radius: 6px; cursor: pointer; text-decoration: none; font-size: 13px; }
        .toolbar a.ghost { background: transparent; border: 1px solid #666; }
        .cover { text-align: center; padding-top: 80px; min-height: 900px; }
        .cover .party { font-size: 17pt; font-weight: 700; margin: 4px 0; }
        .cover .doc-title { font-size: 19pt; font-weight: 700; margin: 26px 0 30px; text-decoration: underline; text-underline-offset: 6px; }
        .cover .meta { margin-top: 60px; font-size: 13pt; }
        .rule { border: 0; border-top: 2.5px solid #111; margin: 22px auto; width: 140px; }
        h2 { text-align: center; font-size: 14.5pt; margin: 26px 0 10px; page-break-after: avoid; }
        h3 { font-size: 12.5pt; margin: 16px 0 6px; page-break-after: avoid; }
        h4 { font-size: 12pt; margin: 12px 0 4px; }
        .en-inline { font-weight: 500; font-style: italic; color: #444; font-size: 10.5pt; }
        p { margin: 6px 0; text-align: justify; }
        .body-np, .body-en, .client-note { margin: 6px 0; text-align: justify; }
        .body-np.alt { font-size: 12.5pt; }
        .body-en { font-size: 11.5pt; }
        .sec table { border-collapse: collapse; max-width: 100%; }
        .sec table td, .sec table th { padding: 3px 8px; vertical-align: top; }
        .sec ul, .sec ol { margin: 4px 0 4px 22px; padding: 0; }
        .sec blockquote { margin: 6px 0 6px 18px; padding-left: 10px; border-left: 3px solid #bbb; }
        .cols { display: table; width: 100%; table-layout: fixed; border-collapse: collapse; }
        .cols .col { display: table-cell; width: 50%; vertical-align: top; padding: 2px 10px 2px 0; font-size: 11pt; text-align: justify; }
        .cols .col + .col { padding: 2px 0 2px 10px; border-left: 1px solid #ddd; font-size: 12pt; }
        .client-note { font-size: 10.5pt; font-style: italic; color: #444; }
        .sec { page-break-inside: avoid; }
        table.toc td { padding: 6px 4px; border-bottom: 1px dotted #999; font-size: 12pt; }
        table.toc { width: 100%; border-collapse: collapse; }
        .toc-no { width: 110px; font-weight: 600; }
        .sig-table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        .sig-table th, .sig-table td { border: 1px solid #333; padding: 8px 12px; width: 50%; vertical-align: top; text-align: left; }
        .sig-table th { background: #efefef; }
        .line { display: inline-block; min-width: 200px; border-bottom: 1px dotted #555; }
        .docfoot { margin-top: 26px; font-size: 9.5pt; color: #555; border-top: 1px solid #ccc; padding-top: 6px; display: flex; justify-content: space-between; }
        .draft-mark { text-align: center; color: #b91c1c; font-weight: 700; letter-spacing: 4px; }
        .page-break { page-break-before: always; }
        @media print { body { background: #fff; } .toolbar { display: none; } .sheet { max-width: none; padding: 0; } @page { size: A4; margin: 19mm 16mm; } }
    </style></head><body>';

    if ($format !== 'doc') {
        echo '<div class="toolbar"><span>' . e((string) $sa['agreement_no']) . ($versionNo > 0 ? ' · v' . $versionNo : '') . ' · ' . e($statusLabel) . '</span>'
            . '<button onclick="window.print()">🖨 Print / Save PDF</button>'
            . '<a class="ghost" href="?id=' . (int) $sa['id'] . '&lang=np' . ($versionNo > 0 ? '&version=' . $versionNo : '') . '">नेपाली</a>'
            . '<a class="ghost" href="?id=' . (int) $sa['id'] . '&lang=en' . ($versionNo > 0 ? '&version=' . $versionNo : '') . '">English</a>'
            . '<a class="ghost" href="?id=' . (int) $sa['id'] . '&lang=both' . ($versionNo > 0 ? '&version=' . $versionNo : '') . '">Side by side</a>'
            . '<a class="ghost" href="?id=' . (int) $sa['id'] . '&lang=seq' . ($versionNo > 0 ? '&version=' . $versionNo : '') . '">Sequential</a>'
            . (!$isClientView ? '<a class="ghost" href="?id=' . (int) $sa['id'] . '&lang=' . e($lang) . '&format=doc' . ($versionNo > 0 ? '&version=' . $versionNo : '') . '">Word</a>' : '')
            . '</div>';
    }

    echo '<div class="sheet">';
    if ($isDraftDoc) {
        echo '<p class="draft-mark">— DRAFT · ' . e(strtoupper($statusLabel)) . ' · NOT YET APPROVED —</p>';
    }

    // Cover page.
    echo '<div class="cover">'
        . '<div class="party">' . e($showNp ? $fpNp : $fpEn) . '</div>'
        . ((string) ($sa['first_party_address'] ?? '') !== '' ? '<div>' . e((string) $sa['first_party_address']) . '</div>' : '')
        . '<div style="margin:22px 0;font-size:14pt">' . ($showNp ? 'र' : 'and') . '</div>'
        . '<div class="party">' . e($showNp ? $spNp : $spEn) . '</div>'
        . '<hr class="rule">'
        . '<div class="doc-title">' . ($showNp ? 'बीच<br>' . e($titleNp) : e($titleEn)) . '</div>'
        . ($showNp && $showEn ? '<div style="font-style:italic;color:#333">(' . e($titleEn) . ')</div>' : '')
        . '<div class="meta">'
        . '<div><strong>' . ($showNp ? 'सम्झौता नं' : 'Agreement No') . ' :</strong> ' . e((string) $sa['agreement_no']) . '</div>'
        . '<div><strong>' . ($showNp ? 'सम्झौता मिति' : 'Agreement Date') . ' :</strong> ' . e($dateBs !== '' ? $dateBs : '................') . '</div>'
        . '<div><strong>Version :</strong> ' . ($versionNo > 0 ? 'v' . $versionNo : 'v' . (int) ($sa['current_version'] ?? 0) . ' (' . e($statusLabel) . ')') . '</div>'
        . '</div></div>';

    // Table of contents from the top level of the tree.
    echo '<div class="page-break"><h2>' . ($showNp ? 'विषय सूची' : 'Table of Contents') . ($showNp && $showEn ? ' <span class="en-inline">(Table of Contents)</span>' : '') . '</h2><table class="toc">';
    foreach ($tree as $top) {
        $label = $showNp && trim((string) ($top['title_np'] ?? '')) !== '' ? (string) $top['title_np'] : (string) ($top['title_en'] ?? '');
        $no = $top['section_type'] === 'schedule'
            ? ($showNp ? (string) $top['number_np'] : (string) $top['number'])
            : (($top['section_type'] === 'chapter' ? ($showNp ? 'परिच्छेद ' . (string) $top['number_np'] : 'Chapter ' . (string) $top['number']) : ($showNp ? 'दफा ' . (string) $top['number_np'] : 'Clause ' . (string) $top['number'])));
        echo '<tr><td class="toc-no">' . e($no) . '</td><td>' . e($label) . ($showNp && $showEn && trim((string) ($top['title_en'] ?? '')) !== '' ? ' <span class="en-inline">' . e((string) $top['title_en']) . '</span>' : '') . '</td></tr>';
    }
    echo '<tr><td class="toc-no">' . ($showNp ? 'हस्ताक्षर' : 'Signatures') . '</td><td>' . ($showNp ? 'स्वीकृति तथा हस्ताक्षर' : 'Acceptance and Signatures') . '</td></tr>';
    echo '</table></div>';

    // Main body.
    echo '<div class="page-break">';
    foreach ($mainSections as $node) {
        echo $sectionHtml($node, 0);
    }
    echo '</div>';

    // Signature + witness block (always from the master record).
    $sigLine = '<span class="line"></span>';
    echo '<div class="page-break"><h2>' . ($showNp ? 'स्वीकृति तथा हस्ताक्षर' : 'Acceptance and Signatures') . ($showNp && $showEn ? ' <span class="en-inline">(Acceptance and Signatures)</span>' : '') . '</h2>';
    echo '<p>' . ($showNp ? 'माथि उल्लिखित शर्तहरू पढी, बुझी, स्वीकार गरी दुवै पक्षका आधिकारिक प्रतिनिधिले साक्षीहरूको रोहबरमा तल हस्ताक्षर गरेका छौँ।' : 'Having read, understood and accepted the terms above, the authorised representatives of both parties sign below in the presence of the witnesses.') . '</p>';
    echo '<table class="sig-table"><tr><th>' . ($showNp ? 'प्रथम पक्षको तर्फबाट' : 'For the First Party') . '</th><th>' . ($showNp ? 'दोस्रो पक्षको तर्फबाट' : 'For the Second Party') . '</th></tr><tr>';
    foreach ([[$fpNp, $fpEn, (string) ($sa['first_party_signatory'] ?? ''), (string) ($sa['first_party_position'] ?? '')], [$spNp, $spEn, (string) ($sa['second_party_signatory'] ?? ''), (string) ($sa['second_party_position'] ?? '')]] as [$nameNp, $nameEn, $signatory, $position]) {
        echo '<td>'
            . '<p>' . ($showNp ? 'हस्ताक्षर' : 'Signature') . ' : ' . $sigLine . '</p>'
            . '<p>' . ($showNp ? 'नाम' : 'Name') . ' : ' . ($signatory !== '' ? e($signatory) : $sigLine) . '</p>'
            . '<p>' . ($showNp ? 'पद' : 'Position') . ' : ' . ($position !== '' ? e($position) : $sigLine) . '</p>'
            . '<p>' . ($showNp ? 'कम्पनी' : 'Company') . ' : ' . e($showNp ? $nameNp : $nameEn) . '</p>'
            . '<p>' . ($showNp ? 'मिति' : 'Date') . ' : ' . ($dateBs !== '' ? e($dateBs) : $sigLine) . '</p>'
            . '<p style="margin-top:24px">' . ($showNp ? 'कम्पनीको छाप :' : 'Company Seal :') . '</p>'
            . '</td>';
    }
    echo '</tr></table>';
    $witnesses = json_decode((string) ($sa['witnesses_json'] ?? ''), true) ?: [];
    echo '<h3 style="margin-top:22px">' . ($showNp ? 'साक्षीहरू' : 'Witnesses') . '</h3><table class="sig-table"><tr><th>' . ($showNp ? 'साक्षी – १' : 'Witness – 1') . '</th><th>' . ($showNp ? 'साक्षी – २' : 'Witness – 2') . '</th></tr><tr>';
    foreach (['w1', 'w2'] as $w) {
        echo '<td><p>' . ($showNp ? 'नाम' : 'Name') . ' : ' . (($witnesses[$w]['name'] ?? '') !== '' ? e((string) $witnesses[$w]['name']) : $sigLine) . '</p>'
            . '<p>' . ($showNp ? 'ठेगाना' : 'Address') . ' : ' . (($witnesses[$w]['address'] ?? '') !== '' ? e((string) $witnesses[$w]['address']) : $sigLine) . '</p>'
            . '<p>' . ($showNp ? 'हस्ताक्षर' : 'Signature') . ' : ' . $sigLine . '</p></td>';
    }
    echo '</tr></table></div>';

    // Schedules after the signature pages (the firm's standard order).
    foreach ($schedules as $schedule) {
        echo '<div class="page-break">' . $sectionHtml($schedule, 0) . '</div>';
    }

    echo '<div class="docfoot"><span>' . e((string) $sa['agreement_no']) . ' · ' . ($versionNo > 0 ? 'v' . $versionNo : 'v' . (int) ($sa['current_version'] ?? 0)) . ' · ' . e($statusLabel) . '</span><span>Generated ' . e(date('Y-m-d H:i')) . '</span></div>';
    echo '</div></body></html>';
}

/** Convert Western digits in a string to Devanagari digits. */
function sa_np_digits(string $text): string
{
    return strtr($text, ['0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४', '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९']);
}

function sa_en_digits(string $text): string
{
    return strtr($text, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
}

function sa_np_amount(float $amount): string
{
    $formatted = number_format($amount, fmod($amount, 1.0) > 0 ? 2 : 0);
    return 'रु ' . sa_np_digits($formatted);
}

function sa_en_amount(float $amount): string
{
    return 'Rs ' . number_format($amount, fmod($amount, 1.0) > 0 ? 2 : 0);
}

$services = json_decode((string) ($sa['services_json'] ?? ''), true) ?: [];
$witnesses = json_decode((string) ($sa['witnesses_json'] ?? ''), true) ?: [];

$fpNp = (string) ($sa['first_party_name_np'] ?? '') !== '' ? (string) $sa['first_party_name_np'] : (string) $sa['first_party_name_en'];
$fpEn = (string) $sa['first_party_name_en'];
$spNp = (string) ($sa['second_party_name_np'] ?? '') !== '' ? (string) $sa['second_party_name_np'] : (string) $sa['second_party_name_en'];
$spEn = (string) $sa['second_party_name_en'];
$dateBs = (string) ($sa['agreement_date_bs'] ?? '');
$effBs = (string) ($sa['effective_date_bs'] ?? '');
$effAd = (string) ($sa['effective_date'] ?? '');
$effNp = $effBs !== '' ? $effBs : sa_np_digits($effAd);
$effEn = $effAd !== '' ? $effAd : $effBs;
$durM = (int) $sa['duration_months'];
$durNp = sa_np_digits((string) $durM);
$trialM = (int) $sa['trial_months'];
$trialNp = sa_np_digits((string) $trialM);
$payD = (int) $sa['payment_days'];
$noticeD = (int) $sa['termination_notice_days'];
$cureD = (int) $sa['cure_days'];
$feeTrial = (float) $sa['fee_trial'];
$feeMonthly = (float) $sa['fee_monthly'];
$purposeNp = (string) $sa['purpose_np'];
$purposeEn = (string) $sa['purpose_en'];

$showNp = $lang !== 'en';
$showEn = $lang !== 'np';

// One clause paragraph in the selected language(s).
$para = static function (string $np, string $en) use ($showNp, $showEn): string {
    $html = '';
    if ($showNp && $np !== '') {
        $html .= '<p class="np">' . nl2br(e($np)) . '</p>';
    }
    if ($showEn && $en !== '') {
        $html .= '<p class="en' . ($showNp ? ' alt' : '') . '">' . nl2br(e($en)) . '</p>';
    }
    return $html;
};
// A दफा (clause): heading in the selected language(s) plus its body paragraphs.
$clause = static function (string $noNp, string $titleNp, string $titleEn, array $paras) use ($showNp, $showEn, $para): string {
    if ($showNp && $showEn) {
        $heading = 'दफा ' . $noNp . ' : ' . e($titleNp) . ' <span class="en-inline">(' . e($titleEn) . ')</span>';
    } elseif ($showNp) {
        $heading = 'दफा ' . $noNp . ' : ' . e($titleNp);
    } else {
        $heading = 'Clause ' . sa_en_digits($noNp) . ' : ' . e($titleEn);
    }
    $body = '';
    foreach ($paras as $p) {
        $body .= $para($p[0], $p[1]);
    }
    return '<div class="clause"><h3>' . $heading . '</h3>' . $body . '</div>';
};
$chapterHead = static function (string $noNp, string $titleNp, string $titleEn, string $anchor) use ($showNp, $showEn): string {
    $h = '<h2 class="chapter" id="' . $anchor . '">';
    if ($showNp) {
        $h .= 'परिच्छेद – ' . $noNp . '<br><span class="ch-title">' . e($titleNp) . '</span>';
        if ($showEn) {
            $h .= '<span class="ch-en">' . e($titleEn) . '</span>';
        }
    } else {
        $h .= 'Chapter ' . sa_en_digits($noNp) . '<br><span class="ch-title">' . e($titleEn) . '</span>';
    }
    return $h . '</h2>';
};

$tocRows = [
    ['०१', 'प्रारम्भ तथा परिभाषा', 'Commencement and Definitions', 'ch1'],
    ['०२', 'उद्देश्य, अवधि तथा सेवा क्षेत्र', 'Objective, Term and Scope of Service', 'ch2'],
    ['०३', 'दोस्रो पक्षको कार्य तथा दायित्व', 'Duties and Obligations of the Second Party', 'ch3'],
    ['०४', 'प्रथम पक्षको कार्य तथा दायित्व', 'Duties and Obligations of the First Party', 'ch4'],
    ['०५', 'व्यावसायिक शुल्क तथा भुक्तानी', 'Professional Fee and Payment', 'ch5'],
    ['०६', 'गोपनीयता, अभिलेख तथा बौद्धिक सम्पत्ति', 'Confidentiality, Records and Intellectual Property', 'ch6'],
    ['०७', 'कार्यसीमा तथा उत्तरदायित्व', 'Scope Limitation and Responsibility', 'ch7'],
    ['०८', 'संशोधन, स्थगन तथा अन्त्य', 'Amendment, Suspension and Termination', 'ch8'],
    ['०९', 'सूचना, विवाद समाधान तथा विविध', 'Notices, Dispute Resolution and Miscellaneous', 'ch9'],
    ['१०', 'स्वीकृति तथा हस्ताक्षर', 'Acceptance and Signatures', 'ch10'],
];

$titleNp = $purposeNp . ' सेवा प्रवाह सम्बन्धी सम्झौता पत्र';
$titleEn = 'Service Agreement for ' . $purposeEn;
$docTitle = $showNp ? $titleNp : $titleEn;

?><!DOCTYPE html>
<html lang="<?= $lang === 'en' ? 'en' : 'ne' ?>">
<head>
<meta charset="UTF-8">
<title><?= e($docTitle) ?> — <?= e((string) $sa['agreement_no']) ?></title>
<style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body { font-family: 'Noto Sans Devanagari', 'Noto Serif Devanagari', 'Mangal', 'Kalimati', 'Times New Roman', serif;
           font-size: 12.5pt; line-height: 1.75; color: #111; margin: 0; background: #f1f1f4; }
    .sheet { max-width: 820px; margin: 0 auto; background: #fff; padding: 52px 62px; }
    .toolbar { position: sticky; top: 0; background: #1d1f2a; color: #fff; padding: 10px 16px; display: flex; gap: 10px;
               align-items: center; justify-content: center; z-index: 9; font-family: Segoe UI, sans-serif; font-size: 13px; }
    .toolbar a, .toolbar button { background: #3b82f6; border: 0; color: #fff; padding: 7px 16px; border-radius: 6px;
               cursor: pointer; text-decoration: none; font-size: 13px; }
    .toolbar a.ghost { background: transparent; border: 1px solid #666; }
    h1, h2, h3 { line-height: 1.5; }
    .cover { text-align: center; padding-top: 90px; min-height: 950px; }
    .cover .party { font-size: 17pt; font-weight: 700; margin: 4px 0; }
    .cover .party-en { font-size: 12pt; font-weight: 400; color: #333; }
    .cover .between { margin: 26px 0; font-size: 14pt; }
    .cover .doc-title { font-size: 20pt; font-weight: 700; margin: 10px 0 40px; text-decoration: underline; text-underline-offset: 6px; }
    .cover .meta { margin-top: 70px; font-size: 13pt; }
    .cover .meta div { margin: 6px 0; }
    .rule { border: 0; border-top: 2.5px solid #111; margin: 24px auto; width: 140px; }
    h2.toc-title { text-align: center; font-size: 16pt; text-decoration: underline; text-underline-offset: 5px; margin: 0 0 24px; }
    table { width: 100%; border-collapse: collapse; }
    table.grid th, table.grid td { border: 1px solid #333; padding: 7px 10px; vertical-align: top; text-align: left; }
    table.grid th { background: #efefef; }
    .toc-table td { padding: 8px 6px; border-bottom: 1px dotted #999; }
    .toc-table .no { width: 110px; font-weight: 600; }
    .toc-table a { color: inherit; text-decoration: none; }
    .toc-en { font-size: 9.5pt; font-style: italic; color: #555; }
    h2.chapter { text-align: center; font-size: 15pt; margin: 34px 0 14px; }
    h2.chapter .ch-title { font-size: 14pt; }
    h2.chapter .ch-en { display: block; font-size: 10.5pt; font-weight: 500; color: #444; font-style: italic; }
    .clause h3 { font-size: 12.5pt; margin: 18px 0 6px; }
    .clause h3 .en-inline { font-weight: 500; font-style: italic; color: #444; font-size: 10.5pt; }
    p { margin: 6px 0; text-align: justify; }
    p.en.alt { font-style: italic; color: #3a3a3a; font-size: 10.5pt; margin-top: 2px; }
    .sig-table th { border: 1px solid #333; background: #efefef; padding: 8px; }
    .sig-table td { border: 1px solid #333; padding: 9px 12px; width: 50%; vertical-align: top; }
    .sig-table .line { display: inline-block; min-width: 210px; border-bottom: 1px dotted #555; }
    .annex-title { text-align: center; font-size: 15pt; margin: 34px 0 6px; }
    .annex-sub { text-align: center; font-style: italic; color: #444; margin: 0 0 16px; font-size: 10.5pt; }
    .cell-en { font-style: italic; font-size: 9.5pt; color: #444; margin-top: 4px; }
    .page-break { page-break-before: always; }
    @media print {
        body { background: #fff; }
        .toolbar { display: none; }
        .sheet { max-width: none; padding: 0; }
        @page { size: A4; margin: 20mm 17mm; }
        h2.chapter { page-break-after: avoid; }
        .clause { page-break-inside: avoid; }
        table.grid tr { page-break-inside: avoid; }
    }
</style>
</head>
<body>
<div class="toolbar">
    <span><?= e((string) $sa['agreement_no']) ?> · <?= strtoupper($lang) ?></span>
    <button onclick="window.print()">🖨 Print / Save PDF</button>
    <a class="ghost" href="?id=<?= $agreementId ?>&lang=np">नेपाली</a>
    <a class="ghost" href="?id=<?= $agreementId ?>&lang=en">English</a>
    <a class="ghost" href="?id=<?= $agreementId ?>&lang=both">Bilingual</a>
    <a class="ghost" href="<?= e(url('admin/service-agreements.php?edit=' . $agreementId)) ?>">← Back</a>
</div>
<div class="sheet">

<!-- ============ COVER PAGE ============ -->
<div class="cover">
    <div class="party"><?= e($showNp ? $fpNp : $fpEn) ?></div>
    <?php if ($showNp && $showEn && $fpNp !== $fpEn): ?><div class="party-en"><?= e($fpEn) ?></div><?php endif; ?>
    <?php if ((string) $sa['first_party_address'] !== ''): ?><div><?= e((string) $sa['first_party_address']) ?></div><?php endif; ?>
    <div class="between"><?= $showNp ? 'र' : 'and' ?></div>
    <div class="party"><?= e($showNp ? $spNp : $spEn) ?></div>
    <?php if ($showNp && $showEn && $spNp !== $spEn): ?><div class="party-en"><?= e($spEn) ?></div><?php endif; ?>
    <?php if ((string) $sa['second_party_address'] !== ''): ?><div><?= e((string) $sa['second_party_address']) ?></div><?php endif; ?>
    <hr class="rule">
    <?php if ($showNp): ?>
        <div class="doc-title">बीच<br><?= e($titleNp) ?></div>
        <?php if ($showEn): ?><div class="party-en" style="font-style:italic">(<?= e($titleEn) ?>)</div><?php endif; ?>
    <?php else: ?>
        <div class="doc-title"><?= e($titleEn) ?></div>
    <?php endif; ?>
    <div class="meta">
        <?php if ($showNp): ?>
            <div><strong>सम्झौता नं :</strong> <?= e((string) $sa['agreement_no']) ?></div>
            <div><strong>सम्झौता मिति :</strong> <?= e($dateBs !== '' ? $dateBs : '................') ?></div>
        <?php else: ?>
            <div><strong>Agreement No :</strong> <?= e((string) $sa['agreement_no']) ?></div>
            <div><strong>Agreement Date :</strong> <?= e($dateBs !== '' ? $dateBs . ' BS' : '................') ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- ============ TABLE OF CONTENTS ============ -->
<div class="page-break">
    <h2 class="toc-title"><?= $showNp ? 'विषय सूची' : 'Table of Contents' ?><?= $showNp && $showEn ? ' <span style="font-size:11pt;font-style:italic">(Table of Contents)</span>' : '' ?></h2>
    <table class="toc-table">
        <?php foreach ($tocRows as $row): ?>
            <tr>
                <td class="no"><?= $showNp ? 'परिच्छेद ' . $row[0] : 'Chapter ' . sa_en_digits($row[0]) ?></td>
                <td><a href="#<?= $row[3] ?>"><?= $showNp ? e($row[1]) : e($row[2]) ?><?= $showNp && $showEn ? ' <span class="toc-en">' . e($row[2]) . '</span>' : '' ?></a></td>
            </tr>
        <?php endforeach; ?>
        <tr><td class="no"><?= $showNp ? 'अनुसूची–१' : 'Annex-1' ?></td>
            <td><a href="#annex1"><?= $showNp ? 'सेवाको विस्तृत कार्यक्षेत्र' : 'Detailed Scope of Services' ?><?= $showNp && $showEn ? ' <span class="toc-en">Detailed Scope of Services</span>' : '' ?></a></td></tr>
        <tr><td class="no"><?= $showNp ? 'अनुसूची–२' : 'Annex-2' ?></td>
            <td><a href="#annex2"><?= $showNp ? 'शुल्क तथा भुक्तानी तालिका' : 'Fee and Payment Schedule' ?><?= $showNp && $showEn ? ' <span class="toc-en">Fee and Payment Schedule</span>' : '' ?></a></td></tr>
    </table>
</div>

<!-- ============ CHAPTER 1 ============ -->
<div class="page-break">
<?= $chapterHead('०१', 'प्रारम्भ तथा परिभाषा', 'Commencement and Definitions', 'ch1') ?>
<?= $clause('१', 'करारका पक्ष तथा प्रारम्भ', 'Parties and Commencement', [
    ["यो सम्झौता " . ($dateBs !== '' ? 'मिति ' . $dateBs . ' मा ' : '') . $fpNp . ($sa['first_party_address'] ? ' (ठेगाना: ' . $sa['first_party_address'] . ')' : '') . ($sa['first_party_reg_no'] ? ' (दर्ता/स्थायी लेखा नं: ' . sa_np_digits((string) $sa['first_party_reg_no']) . ')' : '') . ' (यसपछि «प्रथम पक्ष» भनिने) र ' . $spNp . ($sa['second_party_address'] ? ' (ठेगाना: ' . $sa['second_party_address'] . ')' : '') . ($sa['second_party_reg_no'] ? ' (दर्ता/स्थायी लेखा नं: ' . sa_np_digits((string) $sa['second_party_reg_no']) . ')' : '') . ' (यसपछि «दोस्रो पक्ष» भनिने) का बीच सम्पन्न भएको छ।',
     'This Agreement is made' . ($dateBs !== '' ? ' on ' . $dateBs . ' BS' : '') . ' between ' . $fpEn . ($sa['first_party_address'] ? ' (Address: ' . $sa['first_party_address'] . ')' : '') . ($sa['first_party_reg_no'] ? ' (Registration/PAN No: ' . $sa['first_party_reg_no'] . ')' : '') . ' (hereinafter the "First Party") and ' . $spEn . ($sa['second_party_address'] ? ' (Address: ' . $sa['second_party_address'] . ')' : '') . ($sa['second_party_reg_no'] ? ' (Registration/PAN No: ' . $sa['second_party_reg_no'] . ')' : '') . ' (hereinafter the "Second Party").'],
    ['यो सम्झौता दुवै पक्षले हस्ताक्षर गरेको मितिदेखि प्रारम्भ हुनेछ र सेवा प्रवाह ' . ($effNp !== '' ? 'मिति ' . $effNp . ' देखि' : 'दुवै पक्षले सहमत गरेको मितिदेखि') . ' सुरु हुनेछ।',
     'This Agreement commences on the date both parties sign it, and delivery of services begins ' . ($effEn !== '' ? 'from ' . $effEn : 'from the date agreed by both parties') . '.'],
]) ?>
<?= $clause('२', 'परिभाषा', 'Definitions', [
    ['«सम्झौता» भन्नाले यो सम्झौता पत्र, यसका अनुसूचीहरू र लिखित संशोधनसमेत सम्झनु पर्छ।',
     '"Agreement" means this agreement, its annexes and any written amendments.'],
    ['«सेवा» भन्नाले अनुसूची–१ मा उल्लिखित ' . $purposeNp . ' सम्बन्धी कार्यहरू सम्झनु पर्छ।',
     '"Services" means the ' . $purposeEn . ' related work described in Annex-1.'],
    ['«कार्यान्वयन मिति» भन्नाले सेवा प्रवाह प्रारम्भ हुने ' . ($effNp !== '' ? 'मिति ' . $effNp : 'दुवै पक्षले सहमत गरेको मिति') . ' सम्झनु पर्छ।',
     '"Effective Date" means the date service delivery begins' . ($effEn !== '' ? ', being ' . $effEn : ', as agreed by both parties') . '.'],
    ($trialM > 0
        ? ['«परीक्षण अवधि» भन्नाले कार्यान्वयन मितिदेखि सुरु हुने पहिलो ' . $trialNp . ' महिनाको अवधि सम्झनु पर्छ।',
           '"Trial Period" means the first ' . $trialM . ' month(s) from the Effective Date.']
        : ['यस सम्झौतामा परीक्षण अवधि रहने छैन।', 'This Agreement has no trial period.']),
    ['«व्यावसायिक शुल्क» भन्नाले दफा ९ तथा अनुसूची–२ बमोजिम प्रथम पक्षले दोस्रो पक्षलाई बुझाउने शुल्क सम्झनु पर्छ।',
     '"Professional Fee" means the fee payable by the First Party to the Second Party under Clause 9 and Annex-2.'],
]) ?>
</div>

<!-- ============ CHAPTER 2 ============ -->
<?= $chapterHead('०२', 'उद्देश्य, अवधि तथा सेवा क्षेत्र', 'Objective, Term and Scope of Service', 'ch2') ?>
<?= $clause('३', 'उद्देश्य', 'Objective', [
    ['प्रथम पक्षले आफ्नो व्यवसायका लागि ' . $purposeNp . ' सेवा लिन चाहेको र दोस्रो पक्षले सो सेवा व्यावसायिक रूपमा उपलब्ध गराउन मञ्जुर गरेकोले अनुसूची–१ मा उल्लिखित कार्यक्षेत्रभित्र रही सेवा प्रवाह गर्न यो सम्झौता गरिएको छ।',
     'The First Party wishes to obtain ' . $purposeEn . ' services for its business and the Second Party agrees to provide those services professionally; this Agreement is therefore made for delivery of services within the scope set out in Annex-1.'],
]) ?>
<?= $clause('४', 'अवधि', 'Term', [
    ['यस सम्झौताको अवधि कार्यान्वयन मितिदेखि ' . $durNp . ' महिनाको हुनेछ।',
     'The term of this Agreement is ' . $durM . ' months from the Effective Date.'],
    ($trialM > 0
        ? ['कार्यान्वयन मितिदेखिको पहिलो ' . $trialNp . ' महिना परीक्षण अवधि हुनेछ। परीक्षण अवधि सन्तोषजनक भएमा सम्झौता स्वतः नियमित अवधिमा प्रवेश गर्नेछ।',
           'The first ' . $trialM . ' month(s) from the Effective Date is a Trial Period. If the Trial Period is satisfactory, the Agreement automatically continues into the regular term.']
        : ['', '']),
    ['अवधि समाप्त हुनुअघि दुवै पक्षको लिखित सहमतिबाट यो सम्झौता नवीकरण वा विस्तार गर्न सकिनेछ।',
     'This Agreement may be renewed or extended by written consent of both parties before expiry.'],
]) ?>

<!-- ============ CHAPTER 3 ============ -->
<?= $chapterHead('०३', 'दोस्रो पक्षको कार्य तथा दायित्व', 'Duties and Obligations of the Second Party', 'ch3') ?>
<?= $clause('५', 'सेवा प्रदान सम्बन्धी दायित्व', 'Service Delivery Obligations', [
    ['(क) अनुसूची–१ मा उल्लिखित सेवाहरू व्यावसायिक दक्षता, इमानदारी र सावधानीका साथ उपलब्ध गराउने।',
     '(a) Provide the services listed in Annex-1 with professional competence, honesty and due care.'],
    ['(ख) प्रथम पक्षले उपलब्ध गराएका कागजात, विवरण तथा जानकारीका आधारमा लेखा तथा प्रतिवेदन तयार गर्ने।',
     '(b) Prepare accounts and reports based on documents, details and information provided by the First Party.'],
    ['(ग) प्रथम पक्षले आवश्यक जानकारी, कागजात र रकम समयमै उपलब्ध गराएको अवस्थामा कर तथा अन्य वैधानिक विवरणहरू तोकिएको समयभित्र दाखिला गर्ने।',
     '(c) File tax and other statutory returns within the prescribed deadlines, provided the First Party supplies the necessary information, documents and funds on time.'],
    ['(घ) सेवा प्रवाहका लागि आवश्यक दक्ष जनशक्तिको व्यवस्था गर्ने र निरन्तरता कायम राख्ने।',
     '(d) Arrange and maintain competent manpower required for service delivery.'],
    ['(ङ) लेखा तथा कर सम्बन्धी महत्वपूर्ण विषय, जोखिम वा अनियमितता देखिएमा प्रथम पक्षलाई समयमै लिखित जानकारी दिने।',
     '(e) Inform the First Party in writing and in time of material accounting or tax issues, risks or irregularities observed.'],
    ['(च) प्रचलित कानून तथा व्यावसायिक मापदण्डको अधीनमा रही कार्य सम्पादन गर्ने।',
     '(f) Perform the work subject to prevailing law and professional standards.'],
]) ?>
<?= $clause('६', 'नियामकीय जानकारी', 'Regulatory Information', [
    ['(क) प्रथम पक्षको व्यवसायसँग सम्बन्धित कर, लेखा तथा कम्पनी कानूनमा भएका मुख्य परिवर्तनबारे प्रथम पक्षलाई जानकारी गराउने।',
     '(a) Keep the First Party informed of major changes in tax, accounting and company law relevant to its business.'],
    ['(ख) वैधानिक दायित्वको म्याद तथा पालना गर्नुपर्ने विषयबारे समयमै सचेत गराउने।',
     '(b) Alert the First Party in time about statutory deadlines and compliance requirements.'],
]) ?>
<?= $clause('७', 'जनशक्ति व्यवस्था', 'Manpower Arrangement', [
    [(string) ($sa['staffing_np'] ?? ''), (string) ($sa['staffing_en'] ?? '')],
]) ?>

<!-- ============ CHAPTER 4 ============ -->
<?= $chapterHead('०४', 'प्रथम पक्षको कार्य तथा दायित्व', 'Duties and Obligations of the First Party', 'ch4') ?>
<?= $clause('८', 'प्रथम पक्षको दायित्व', 'Obligations of the First Party', [
    ['(क) सेवा प्रवाहका लागि आवश्यक सम्पूर्ण बिल, भर्पाई, बैंक विवरण, करार, निर्णय लगायतका कागजात तथा जानकारी सही, पूर्ण र समयमै उपलब्ध गराउने।',
     '(a) Provide all bills, receipts, bank statements, contracts, decisions and other documents and information — accurate, complete and on time — as required for service delivery.'],
    ['(ख) दोस्रो पक्षका खटिएका कर्मचारीलाई आवश्यक कार्यस्थल, अभिलेख तथा प्रणालीमा पहुँच र सहयोग उपलब्ध गराउने।',
     '(b) Give the Second Party\'s assigned staff necessary workspace, access to records and systems, and cooperation.'],
    ['(ग) आन्तरिक राजस्व कार्यालय, बैंक तथा अन्य निकायका पोर्टल प्रयोगका लागि आवश्यक पहुँच, OTP तथा प्रमाणीकरण समयमै उपलब्ध गराउने।',
     '(c) Provide timely access, OTPs and authentication needed for Inland Revenue, bank and other agency portals.'],
    ['(घ) दोस्रो पक्षले पेस गरेका विवरण, प्रतिवेदन तथा दाखिलायोग्य कागजातमा समयमै निर्णय तथा स्वीकृति दिने।',
     '(d) Decide on and approve statements, reports and filings submitted by the Second Party in time.'],
    ['(ङ) कर, शुल्क, जरिवाना लगायत सरकारी दायित्वको रकम समयमै व्यवस्था गर्ने; त्यस्तो रकम दोस्रो पक्षको दायित्वमा पर्ने छैन।',
     '(e) Arrange funds for taxes, fees, fines and other government dues on time; such amounts are not the Second Party\'s liability.'],
    ['(च) उपलब्ध गराइएका कागजात तथा जानकारीको सत्यता र पूर्णताको जिम्मेवारी प्रथम पक्षकै हुने।',
     '(f) Remain responsible for the truth and completeness of the documents and information provided.'],
    ['(छ) व्यवसाय सञ्चालन सम्बन्धी सबै व्यवस्थापकीय निर्णय प्रथम पक्ष आफैँले गर्ने।',
     '(g) Make all management decisions concerning the business itself.'],
    ['(ज) दफा ९ बमोजिमको व्यावसायिक शुल्क समयमै भुक्तानी गर्ने।',
     '(h) Pay the Professional Fee under Clause 9 on time.'],
]) ?>

<!-- ============ CHAPTER 5 ============ -->
<?= $chapterHead('०५', 'व्यावसायिक शुल्क तथा भुक्तानी', 'Professional Fee and Payment', 'ch5') ?>
<?= $clause('९', 'व्यावसायिक शुल्क तथा भुक्तानी', 'Professional Fee and Payment', [
    ($trialM > 0
        ? ['(क) परीक्षण अवधिको व्यावसायिक शुल्क मासिक ' . sa_np_amount($feeTrial) . ' (अक्षरेपी ' . npr_amount_in_words($feeTrial) . ') मा थप मूल्य अभिवृद्धि कर (VAT) हुनेछ।',
           '(a) The Professional Fee for the Trial Period is ' . sa_en_amount($feeTrial) . ' (' . npr_amount_in_words($feeTrial) . ') per month plus Value Added Tax (VAT).']
        : ['', '']),
    [($trialM > 0 ? '(ख) परीक्षण अवधिपछिको' : '(क)') . ' नियमित व्यावसायिक शुल्क मासिक ' . sa_np_amount($feeMonthly) . ' (अक्षरेपी ' . npr_amount_in_words($feeMonthly) . ') मा थप मूल्य अभिवृद्धि कर (VAT) हुनेछ।',
     ($trialM > 0 ? '(b) After the Trial Period, the' : '(a) The') . ' regular Professional Fee is ' . sa_en_amount($feeMonthly) . ' (' . npr_amount_in_words($feeMonthly) . ') per month plus VAT.'],
    [($trialM > 0 ? '(ग)' : '(ख)') . ' दोस्रो पक्षले प्रत्येक महिनाको सेवा वापत कर बीजक जारी गर्नेछ र प्रथम पक्षले अर्को महिनाको ' . sa_np_digits((string) $payD) . ' दिनभित्र भुक्तानी गर्नुपर्नेछ।',
     ($trialM > 0 ? '(c)' : '(b)') . ' The Second Party will issue a tax invoice for each month\'s service and the First Party shall pay within ' . $payD . ' days of the following month.'],
    [($trialM > 0 ? '(घ)' : '(ग)') . ' प्रचलित कानूनबमोजिम लाग्ने अग्रिम कर कट्टी गरी भुक्तानी गर्न सकिनेछ।',
     ($trialM > 0 ? '(d)' : '(c)') . ' Payment may be made after deducting advance/withholding tax as required by prevailing law.'],
    [($trialM > 0 ? '(ङ)' : '(घ)') . ' सरकारी दस्तुर, कर, जरिवाना, तेस्रो पक्षको शुल्क तथा अनुसूची–१ बाहिरका अतिरिक्त कार्य यस शुल्कमा समावेश हुने छैनन्; त्यस्ता कार्य दुवै पक्षको लिखित सहमतिमा छुट्टै शुल्कमा गरिनेछ।',
     ($trialM > 0 ? '(e)' : '(d)') . ' Government fees, taxes, fines, third-party charges and work outside Annex-1 are excluded; such work will be done at a separately agreed fee with written consent of both parties.'],
    [($trialM > 0 ? '(च)' : '(ङ)') . ' तोकिएको अवधिभित्र शुल्क भुक्तानी नभएमा दोस्रो पक्षले लिखित सूचना दिई भुक्तानी नभएसम्म सेवा स्थगन गर्न सक्नेछ।',
     ($trialM > 0 ? '(f)' : '(e)') . ' If the fee is not paid within the stated period, the Second Party may, after written notice, suspend services until payment is made.'],
]) ?>

<!-- ============ CHAPTER 6 ============ -->
<?= $chapterHead('०६', 'गोपनीयता, अभिलेख तथा बौद्धिक सम्पत्ति', 'Confidentiality, Records and Intellectual Property', 'ch6') ?>
<?= $clause('१०', 'गोपनीयता', 'Confidentiality', [
    ['दुवै पक्षले यस सम्झौताको क्रममा प्राप्त एकअर्काको व्यावसायिक, वित्तीय तथा व्यक्तिगत जानकारी गोप्य राख्नेछन्। कानून, अदालत वा नियामक निकायको आदेशबमोजिम खुलासा गर्नुपर्ने अवस्था यसको अपवाद हुनेछ। यो दायित्व सम्झौता अन्त्य भएपछि पनि कायम रहनेछ।',
     'Each party shall keep confidential the other\'s business, financial and personal information obtained under this Agreement, except disclosure required by law, court or regulator. This obligation survives termination.'],
]) ?>
<?= $clause('११', 'अभिलेखको स्वामित्व', 'Ownership of Records', [
    ['प्रथम पक्षका लेखा पुस्तक, बीजक तथा मूल कागजातको स्वामित्व प्रथम पक्षमै रहनेछ। सम्झौता अन्त्य भएमा दोस्रो पक्षले बक्यौता फछ्र्योटपछि त्यस्ता अभिलेख व्यवस्थित रूपमा फिर्ता गर्नेछ; आफ्नो व्यावसायिक प्रयोजनका लागि कार्यपत्रको प्रति राख्न सक्नेछ।',
     'The First Party owns its books, invoices and original documents. On termination the Second Party will hand them back in an orderly manner after settlement of dues, and may retain copies of its working papers for professional purposes.'],
]) ?>
<?= $clause('१२', 'बौद्धिक सम्पत्ति', 'Intellectual Property', [
    ['दोस्रो पक्षका कार्यविधि, ढाँचा, टेम्प्लेट तथा सफ्टवेयर उपकरणको स्वामित्व दोस्रो पक्षमै रहनेछ।',
     'The Second Party retains ownership of its methodologies, formats, templates and software tools.'],
]) ?>

<!-- ============ CHAPTER 7 ============ -->
<?= $chapterHead('०७', 'कार्यसीमा तथा उत्तरदायित्व', 'Scope Limitation and Responsibility', 'ch7') ?>
<?= $clause('१३', 'कार्यसीमा तथा उत्तरदायित्व', 'Scope Limitation and Responsibility', [
    ['(क) यस सम्झौताअन्तर्गतको सेवा वैधानिक लेखापरीक्षण (Statutory Audit) वा औपचारिक कानूनी राय होइन।',
     '(a) The services under this Agreement are not a statutory audit or a formal legal opinion.'],
    ['(ख) दोस्रो पक्षको उत्तरदायित्व प्रथम पक्षले उपलब्ध गराएका जानकारीका आधारमा व्यावसायिक सावधानीपूर्वक कार्य गर्ने सम्ममा सीमित रहनेछ।',
     '(b) The Second Party\'s responsibility is limited to performing the work with professional care based on the information provided by the First Party.'],
    ['(ग) प्रथम पक्षले गरेका व्यावसायिक निर्णय तथा गलत वा अपूर्ण जानकारीबाट सिर्जित परिणामप्रति दोस्रो पक्ष उत्तरदायी हुने छैन।',
     '(c) The Second Party is not responsible for the First Party\'s business decisions or consequences arising from wrong or incomplete information.'],
    ['(घ) कुनै दाबी भएमा दोस्रो पक्षको कुल उत्तरदायित्व सम्बन्धित अवधिमा प्राप्त व्यावसायिक शुल्कको सीमाभित्र रहनेछ।',
     '(d) In case of any claim, the Second Party\'s total liability shall not exceed the Professional Fee received for the relevant period.'],
]) ?>

<!-- ============ CHAPTER 8 ============ -->
<?= $chapterHead('०८', 'संशोधन, स्थगन तथा अन्त्य', 'Amendment, Suspension and Termination', 'ch8') ?>
<?= $clause('१४', 'संशोधन', 'Amendment', [
    ['यस सम्झौतामा कुनै संशोधन वा थपघट दुवै पक्षको लिखित सहमतिबाट मात्र हुनेछ।',
     'Any amendment to this Agreement is valid only with the written consent of both parties.'],
]) ?>
<?php if ($trialM > 0): ?>
<?= $clause('१५', 'परीक्षण अवधिमा अन्त्य', 'Termination During Trial Period', [
    ['परीक्षण अवधिभित्र कुनै पक्षले लिखित सूचना दिई यो सम्झौता अन्त्य गर्न सक्नेछ। सेवा प्रदान भएको अवधिसम्मको शुल्क भुक्तानी गर्नुपर्नेछ।',
     'Either party may terminate this Agreement during the Trial Period by written notice. Fees for the period served remain payable.'],
]) ?>
<?php endif; ?>
<?= $clause('१६', 'सामान्य अन्त्य', 'General Termination', [
    ['कुनै पक्षले ' . sa_np_digits((string) $noticeD) . ' दिनको अग्रिम लिखित सूचना दिई यो सम्झौता अन्त्य गर्न सक्नेछ।',
     'Either party may terminate this Agreement by giving ' . $noticeD . ' days\' prior written notice.'],
]) ?>
<?= $clause('१७', 'उल्लङ्घनमा अन्त्य', 'Termination for Breach', [
    ['कुनै पक्षले यस सम्झौताको गम्भीर उल्लङ्घन गरेमा अर्को पक्षले ' . sa_np_digits((string) $cureD) . ' दिनभित्र सच्याउन लिखित सूचना दिनेछ; सो अवधिभित्र नसच्याएमा तत्काल सम्झौता अन्त्य गर्न सक्नेछ।',
     'If a party materially breaches this Agreement, the other party will give written notice to cure within ' . $cureD . ' days; if not cured within that period, the Agreement may be terminated immediately.'],
]) ?>
<?= $clause('१८', 'हस्तान्तरण', 'Handover', [
    ['सम्झौता अन्त्य भएमा दोस्रो पक्षले बक्यौता फछ्र्योटपछि प्रथम पक्षका अभिलेख, पहुँच तथा जिम्मेवारी व्यवस्थित रूपमा हस्तान्तरण गर्नेछ।',
     'On termination, the Second Party will hand over the First Party\'s records, access and responsibilities in an orderly manner after settlement of dues.'],
]) ?>

<!-- ============ CHAPTER 9 ============ -->
<?= $chapterHead('०९', 'सूचना, विवाद समाधान तथा विविध', 'Notices, Dispute Resolution and Miscellaneous', 'ch9') ?>
<?= $clause('१९', 'सूचना', 'Notices', [
    ['यस सम्झौताअन्तर्गतका सूचना माथि उल्लिखित ठेगानामा लिखित रूपमा दिइनेछ। प्राप्ति स्वीकार भएको अवस्थामा इमेल सूचना पनि मान्य हुनेछ।',
     'Notices under this Agreement shall be given in writing to the addresses stated above. E-mail notice is valid where receipt is acknowledged.'],
]) ?>
<?= $clause('२०', 'विवाद समाधान', 'Dispute Resolution', [
    ['यस सम्झौताबाट उत्पन्न विवाद पहिले आपसी छलफलबाट समाधान गरिनेछ; समाधान हुन नसकेमा ' . (string) $sa['jurisdiction_np'] . 'बाट नेपाल कानूनबमोजिम टुङ्गो लगाइनेछ।',
     'Disputes arising from this Agreement will first be resolved by mutual discussion; failing that, they will be settled under the laws of Nepal by ' . (string) $sa['jurisdiction_en'] . '.'],
]) ?>
<?= $clause('२१', 'सम्पूर्ण सम्झौता', 'Entire Agreement', [
    ['यो सम्झौता र यसका अनुसूचीहरू नै दुवै पक्षबीचको सम्पूर्ण समझदारी हुन्; यसअघिका मौखिक वा लिखित समझदारी यसैद्वारा प्रतिस्थापित हुनेछन्।',
     'This Agreement and its annexes are the entire understanding between the parties and supersede all prior oral or written understandings.'],
]) ?>
<?= $clause('२२', 'आंशिक अमान्यता', 'Severability', [
    ['यस सम्झौताको कुनै व्यवस्था अमान्य ठहरिए पनि बाँकी व्यवस्थाहरू यथावत् कायम रहनेछन्।',
     'If any provision of this Agreement is held invalid, the remaining provisions continue in force.'],
]) ?>
<?= $clause('२३', 'सम्झौताका प्रति', 'Counterparts', [
    ['यो सम्झौता समान मान्यताका दुई प्रति तयार गरी दुवै पक्षले एक–एक प्रति राख्नेछन्।',
     'This Agreement is executed in two counterparts of equal validity, one retained by each party.'],
]) ?>
<?php if ((string) ($sa['custom_clauses_np'] ?? '') !== '' || (string) ($sa['custom_clauses_en'] ?? '') !== ''): ?>
<?= $clause('२४', 'थप शर्तहरू', 'Additional Terms', [
    [(string) ($sa['custom_clauses_np'] ?? ''), (string) ($sa['custom_clauses_en'] ?? '')],
]) ?>
<?php endif; ?>

<!-- ============ CHAPTER 10: SIGNATURES ============ -->
<div class="page-break">
<?= $chapterHead('१०', 'स्वीकृति तथा हस्ताक्षर', 'Acceptance and Signatures', 'ch10') ?>
<?= $para('माथि उल्लिखित शर्तहरू पढी, बुझी, स्वीकार गरी दुवै पक्षका आधिकारिक प्रतिनिधिले साक्षीहरूको रोहबरमा तल हस्ताक्षर गरेका छौँ।',
          'Having read, understood and accepted the terms above, the authorised representatives of both parties sign below in the presence of the witnesses.') ?>
<table class="sig-table" style="margin-top:18px">
    <tr>
        <th><?= $showNp ? 'प्रथम पक्षको तर्फबाट' : 'For the First Party' ?><?= $showNp && $showEn ? '<br><small style="font-weight:500;font-style:italic">For the First Party</small>' : '' ?></th>
        <th><?= $showNp ? 'दोस्रो पक्षको तर्फबाट' : 'For the Second Party' ?><?= $showNp && $showEn ? '<br><small style="font-weight:500;font-style:italic">For the Second Party</small>' : '' ?></th>
    </tr>
    <tr>
        <td>
            <p><?= $showNp ? 'हस्ताक्षर' : 'Signature' ?> : <span class="line"></span></p>
            <p><?= $showNp ? 'नाम' : 'Name' ?> : <?= e((string) ($sa['first_party_signatory'] ?? '')) ?: '<span class="line"></span>' ?></p>
            <p><?= $showNp ? 'पद' : 'Position' ?> : <?= e((string) ($sa['first_party_position'] ?? '')) ?: '<span class="line"></span>' ?></p>
            <p><?= $showNp ? 'कम्पनी' : 'Company' ?> : <?= e($showNp ? $fpNp : $fpEn) ?></p>
            <p><?= $showNp ? 'मिति' : 'Date' ?> : <?= e($dateBs) ?: '<span class="line"></span>' ?></p>
            <p style="margin-top:26px"><?= $showNp ? 'कम्पनीको छाप :' : 'Company Seal :' ?></p>
        </td>
        <td>
            <p><?= $showNp ? 'हस्ताक्षर' : 'Signature' ?> : <span class="line"></span></p>
            <p><?= $showNp ? 'नाम' : 'Name' ?> : <?= e((string) ($sa['second_party_signatory'] ?? '')) ?: '<span class="line"></span>' ?></p>
            <p><?= $showNp ? 'पद' : 'Position' ?> : <?= e((string) ($sa['second_party_position'] ?? '')) ?: '<span class="line"></span>' ?></p>
            <p><?= $showNp ? 'कम्पनी' : 'Company' ?> : <?= e($showNp ? $spNp : $spEn) ?></p>
            <p><?= $showNp ? 'मिति' : 'Date' ?> : <?= e($dateBs) ?: '<span class="line"></span>' ?></p>
            <p style="margin-top:26px"><?= $showNp ? 'कम्पनीको छाप :' : 'Company Seal :' ?></p>
        </td>
    </tr>
</table>
<h3 style="margin-top:26px"><?= $showNp ? 'साक्षीहरू' : 'Witnesses' ?><?= $showNp && $showEn ? ' <span style="font-weight:500;font-style:italic;font-size:10.5pt">(Witnesses)</span>' : '' ?></h3>
<table class="sig-table">
    <tr>
        <th><?= $showNp ? 'साक्षी – १' : 'Witness – 1' ?></th>
        <th><?= $showNp ? 'साक्षी – २' : 'Witness – 2' ?></th>
    </tr>
    <tr>
        <td>
            <p><?= $showNp ? 'नाम' : 'Name' ?> : <?= e((string) ($witnesses['w1']['name'] ?? '')) ?: '<span class="line"></span>' ?></p>
            <p><?= $showNp ? 'ठेगाना' : 'Address' ?> : <?= e((string) ($witnesses['w1']['address'] ?? '')) ?: '<span class="line"></span>' ?></p>
            <p><?= $showNp ? 'हस्ताक्षर' : 'Signature' ?> : <span class="line"></span></p>
        </td>
        <td>
            <p><?= $showNp ? 'नाम' : 'Name' ?> : <?= e((string) ($witnesses['w2']['name'] ?? '')) ?: '<span class="line"></span>' ?></p>
            <p><?= $showNp ? 'ठेगाना' : 'Address' ?> : <?= e((string) ($witnesses['w2']['address'] ?? '')) ?: '<span class="line"></span>' ?></p>
            <p><?= $showNp ? 'हस्ताक्षर' : 'Signature' ?> : <span class="line"></span></p>
        </td>
    </tr>
</table>
</div>

<!-- ============ ANNEX 1 ============ -->
<div class="page-break" id="annex1">
    <h2 class="annex-title"><?= $showNp ? 'अनुसूची – १ : सेवाको विस्तृत कार्यक्षेत्र' : 'Annex – 1 : Detailed Scope of Services' ?></h2>
    <?php if ($showNp && $showEn): ?><p class="annex-sub">Annex – 1 : Detailed Scope of Services</p><?php endif; ?>
    <table class="grid">
        <tr>
            <th style="width:46px"><?= $showNp ? 'क्र.सं.' : 'S.N.' ?></th>
            <th style="width:150px"><?= $showNp ? 'सेवा' : 'Service' ?><?= $showNp && $showEn ? '<br><small><i>Service</i></small>' : '' ?></th>
            <th><?= $showNp ? 'समावेश कार्य' : 'Included Work' ?><?= $showNp && $showEn ? '<br><small><i>Included Work</i></small>' : '' ?></th>
            <th style="width:190px"><?= $showNp ? 'मुख्य प्रतिफल' : 'Key Deliverable' ?><?= $showNp && $showEn ? '<br><small><i>Key Deliverable</i></small>' : '' ?></th>
        </tr>
        <?php $sn = 0; foreach ($services as $service): $sn++; ?>
            <tr>
                <td><?= $showNp ? sa_np_digits((string) $sn) : $sn ?></td>
                <td>
                    <?php if ($showNp): ?><strong><?= e((string) ($service['title_np'] ?? '')) ?></strong><?php endif; ?>
                    <?php if ($showEn): ?><div class="<?= $showNp ? 'cell-en' : '' ?>" style="<?= $showNp ? '' : 'font-weight:700' ?>"><?= e((string) ($service['title_en'] ?? '')) ?></div><?php endif; ?>
                </td>
                <td>
                    <?php if ($showNp): ?><div><?= nl2br(e((string) ($service['tasks_np'] ?? ''))) ?></div><?php endif; ?>
                    <?php if ($showEn): ?><div class="<?= $showNp ? 'cell-en' : '' ?>"><?= nl2br(e((string) ($service['tasks_en'] ?? ''))) ?></div><?php endif; ?>
                </td>
                <td>
                    <?php if ($showNp): ?><div><?= nl2br(e((string) ($service['deliverable_np'] ?? ''))) ?></div><?php endif; ?>
                    <?php if ($showEn): ?><div class="<?= $showNp ? 'cell-en' : '' ?>"><?= nl2br(e((string) ($service['deliverable_en'] ?? ''))) ?></div><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($services === []): ?><tr><td colspan="4"><?= $showNp ? 'सेवाहरू दुवै पक्षको लिखित सहमतिबमोजिम हुनेछन्।' : 'Services shall be as agreed in writing by both parties.' ?></td></tr><?php endif; ?>
    </table>
    <h3 style="margin-top:20px"><?= $showNp ? 'कार्य सञ्चालन सम्बन्धी आधार' : 'Operating Basis' ?><?= $showNp && $showEn ? ' <span style="font-weight:500;font-style:italic;font-size:10.5pt">(Operating Basis)</span>' : '' ?></h3>
    <?= $para('(१) प्रथम पक्षले मासिक कागजात तथा जानकारी दुवै पक्षले सहमत गरेको समयतालिका बमोजिम उपलब्ध गराउनेछ।',
              '(1) The First Party will provide monthly documents and information per the timetable agreed by both parties.') ?>
    <?= $para('(२) दुवै पक्षले सम्पर्क व्यक्ति तोकी नियमित सञ्चार माध्यम (इमेल/फोन) निर्धारण गर्नेछन्।',
              '(2) Both parties will designate contact persons and a regular communication channel (e-mail/phone).') ?>
    <?= $para('(३) कागजात आदानप्रदान अभिलेखसहित (हस्तान्तरण पुस्तिका वा डिजिटल माध्यम) गरिनेछ।',
              '(3) Documents will be exchanged with a record (handover register or digital log).') ?>
    <?= $para('(४) मासिक समीक्षा बैठकमा दुवै पक्ष सहभागी भई कार्यप्रगति तथा समस्याको समाधान गरिनेछ।',
              '(4) A monthly review meeting of both parties will address progress and issues.') ?>
</div>

<!-- ============ ANNEX 2 ============ -->
<div id="annex2" style="margin-top:34px">
    <h2 class="annex-title"><?= $showNp ? 'अनुसूची – २ : शुल्क तथा भुक्तानी तालिका' : 'Annex – 2 : Fee and Payment Schedule' ?></h2>
    <?php if ($showNp && $showEn): ?><p class="annex-sub">Annex – 2 : Fee and Payment Schedule</p><?php endif; ?>
    <table class="grid">
        <tr>
            <th><?= $showNp ? 'अवधि' : 'Period' ?></th>
            <th><?= $showNp ? 'लागू समय' : 'Applicable Time' ?></th>
            <th><?= $showNp ? 'व्यावसायिक शुल्क (मासिक)' : 'Professional Fee (Monthly)' ?></th>
            <th>VAT</th>
            <th><?= $showNp ? 'भुक्तानी समय' : 'Payment Time' ?></th>
        </tr>
        <?php if ($trialM > 0): ?>
        <tr>
            <td><?= $showNp ? 'परीक्षण अवधि' : 'Trial Period' ?><?= $showNp && $showEn ? '<br><small><i>Trial Period</i></small>' : '' ?></td>
            <td><?= $showNp ? 'कार्यान्वयन मितिदेखि पहिलो ' . $trialNp . ' महिना' : 'First ' . $trialM . ' month(s) from Effective Date' ?></td>
            <td><?= $showNp ? sa_np_amount($feeTrial) : sa_en_amount($feeTrial) ?></td>
            <td><?= $showNp ? 'प्रचलित दरमा थप' : 'Extra, at prevailing rate' ?></td>
            <td><?= $showNp ? 'अर्को महिनाको ' . sa_np_digits((string) $payD) . ' दिनभित्र' : 'Within ' . $payD . ' days of following month' ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <td><?= $showNp ? 'नियमित अवधि' : 'Regular Period' ?><?= $showNp && $showEn ? '<br><small><i>Regular Period</i></small>' : '' ?></td>
            <td><?= $showNp ? ($trialM > 0 ? 'परीक्षण अवधिपछि सम्झौता अवधिभर' : 'कार्यान्वयन मितिदेखि सम्झौता अवधिभर') : ($trialM > 0 ? 'After Trial Period, for the term' : 'From Effective Date, for the term') ?></td>
            <td><?= $showNp ? sa_np_amount($feeMonthly) : sa_en_amount($feeMonthly) ?></td>
            <td><?= $showNp ? 'प्रचलित दरमा थप' : 'Extra, at prevailing rate' ?></td>
            <td><?= $showNp ? 'अर्को महिनाको ' . sa_np_digits((string) $payD) . ' दिनभित्र' : 'Within ' . $payD . ' days of following month' ?></td>
        </tr>
    </table>
    <?= $para('नोट: माथिको शुल्कमा मूल्य अभिवृद्धि कर (VAT) समावेश छैन। सरकारी दस्तुर, कर, जरिवाना तथा अनुसूची–१ बाहिरका कार्यको शुल्क छुट्टै हुनेछ।',
              'Note: The above fees exclude VAT. Government fees, taxes, fines and work outside Annex-1 are charged separately.') ?>
</div>

</div>
</body>
</html>
