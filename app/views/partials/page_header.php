<?php
$breadcrumbs = $breadcrumbs ?? [];
?>
<section class="page-header-band">
    <div class="page-container">
        <?php if ($breadcrumbs !== []): ?>
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <?php foreach ($breadcrumbs as $index => $crumb): ?>
                    <?php if ($index > 0): ?><span>/</span><?php endif; ?>
                    <?php if (!empty($crumb['url'])): ?>
                        <a href="<?= e(url($crumb['url'])) ?>"><?= e($crumb['label']) ?></a>
                    <?php else: ?>
                        <strong><?= e($crumb['label']) ?></strong>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <?php if (!empty($pageSectionLabel)): ?>
            <div class="section-label"><?= e($pageSectionLabel) ?></div>
        <?php endif; ?>
        <h1><?= e($pageTitle) ?></h1>
        <?php if (!empty($pageDescription)): ?>
            <p><?= e($pageDescription) ?></p>
        <?php endif; ?>
    </div>
</section>
