<?php
require_once __DIR__ . '/public_section_data.php';

$sectionKey = $sectionKey ?? '';
$currentPage = $currentPage ?? 'overview';
$pageData = public_section_page($sectionKey, $currentPage);
$pageTitle = $pageTitle ?? $pageData['title'];
$pageDescription = $pageDescription ?? $pageData['description'];
$pageSectionLabel = $pageData['section_label'];
$sidebarTitle = $pageSectionLabel;
$sidebarItems = $pageData['items'];
$bodyClass = trim(($bodyClass ?? '') . ' internal-public-page');
$breadcrumbs = [
    ['label' => 'Home', 'url' => 'index.php'],
    ['label' => $pageSectionLabel, 'url' => ($sidebarItems['overview']['url'] ?? '')],
    ['label' => $pageTitle],
];

include __DIR__ . '/header.php';
include __DIR__ . '/page_header.php';
?>
<section class="internal-page-band">
    <div class="page-container internal-page-layout">
        <?php include __DIR__ . '/section_sidebar.php'; ?>
        <div class="section-content">
            <?php include __DIR__ . '/public_section_content.php'; ?>
        </div>
    </div>
</section>
<?php include __DIR__ . '/footer.php'; ?>
