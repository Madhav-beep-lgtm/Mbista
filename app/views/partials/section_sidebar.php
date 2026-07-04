<aside class="section-sidebar">
    <h2><?= e($sidebarTitle ?? '') ?></h2>
    <nav aria-label="<?= e($sidebarTitle ?? 'Section') ?> navigation">
        <?php foreach (($sidebarItems ?? []) as $key => $item): ?>
            <a class="<?= $key === ($currentPage ?? '') ? 'active' : '' ?>" href="<?= e(url($item['url'])) ?>">
                <?= e($item['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
