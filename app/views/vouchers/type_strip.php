<?php
declare(strict_types=1);

/**
 * Clickable voucher-type selector.
 *
 * Browser and operating-system shortcuts are intentionally not advertised or
 * intercepted here: several F-keys already have browser meanings and behaved
 * inconsistently across platforms.
 *
 * Expects: $voucherTypes (catalog), $type (current key).
 */
?>
<nav class="vch-typebar" aria-label="Voucher type" id="vch-typebar">
    <?php foreach (voucher_entry_type_catalog() as $typeKey => $typeSpec): ?>
        <?php $isCurrent = $typeKey === $type; ?>
        <a class="vch-type<?= $isCurrent ? ' is-active' : '' ?> tone-<?= e((string) $typeSpec['tone']) ?>"
           href="<?= e(url(voucher_type_url($typeKey))) ?>"
           title="<?= e((string) $typeSpec['label']) ?> — <?= e((string) $typeSpec['blurb']) ?>"
           <?= $isCurrent ? 'aria-current="page"' : '' ?>>
            <span class="vch-type-icon"><?= icon((string) $typeSpec['icon']) ?></span>
            <span class="vch-type-name"><?= e((string) $typeSpec['short']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
