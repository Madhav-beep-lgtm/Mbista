<?php
declare(strict_types=1);

/**
 * The jewellery module's stylesheet and its document header.
 *
 * Both live here so every page in the module gets the same treatment from one
 * place — a page that forgets to include this simply looks like the rest of the
 * admin, rather than half-styled.
 */

/** Link the module stylesheet. Safe to call more than once per request. */
function jw_page_styles(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    // Versioned so a deploy is not served yesterday's cached copy.
    echo '<link rel="stylesheet" href="/assets/css/jewellery.css?v=20260728d">' . "\n";
}

/**
 * The header a jewellery page opens with: an icon, what the page is, and what
 * it is for.
 *
 * $aside is optional markup for the right-hand side — a collapsed panel, a
 * status pill — and is emitted as given, so the caller escapes it.
 */
function jw_page_head(string $title, string $subtitle = '', string $iconName = 'coins', string $aside = ''): void
{
    jw_page_styles();
    ?>
    <div class="jw-page-head">
        <div class="jw-page-head-icon" aria-hidden="true"><?= icon($iconName) ?></div>
        <div class="jw-page-head-text">
            <h1><?= e($title) ?></h1>
            <?php if ($subtitle !== ''): ?><p><?= e($subtitle) ?></p><?php endif; ?>
        </div>
        <?php if ($aside !== ''): ?>
            <div class="jw-page-head-aside"><?= $aside ?></div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * The strip that closes a jewellery page.
 *
 * It says two things the shop actually wants to know on a document screen: that
 * what it does here is recorded, and when this page last changed.
 */
function jw_page_foot(string $lastUpdated = ''): void
{
    ?>
    <div class="jw-page-foot">
        <span class="jw-foot-secure"><?= icon('lock') ?>All transactions are recorded in the audit trail.</span>
        <?php if ($lastUpdated !== ''): ?><span><?= e($lastUpdated) ?></span><?php endif; ?>
    </div>
    <?php
}
