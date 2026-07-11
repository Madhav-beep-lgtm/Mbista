<?php
/**
 * Topbar notification bell. Include inside .admin-topbar-actions.
 * Uses attention_summary() (helpers.php); renders nothing on failure.
 */
$attentionItems = function_exists('attention_summary') ? attention_summary() : [];
$attentionTotal = 0;
foreach ($attentionItems as $attentionRow) {
    $attentionTotal += (int) $attentionRow['count'];
}
?>
<details class="attn-bell">
    <summary class="admin-icon-button" aria-label="Notifications" title="Things that need your attention">
        <?= icon('bell') ?>
        <?php if ($attentionTotal > 0): ?>
            <span class="attn-count"><?= e($attentionTotal > 99 ? '99+' : (string) $attentionTotal) ?></span>
        <?php endif; ?>
    </summary>
    <div class="attn-menu">
        <strong class="attn-title">Needs your attention</strong>
        <?php if ($attentionItems === []): ?>
            <span class="attn-empty">You're all caught up.</span>
        <?php else: ?>
            <?php foreach ($attentionItems as $attentionRow): ?>
                <a href="<?= e($attentionRow['url']) ?>">
                    <span><?= e($attentionRow['label']) ?></span>
                    <span class="attn-pill"><?= e((string) $attentionRow['count']) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</details>
