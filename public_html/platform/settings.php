<?php
declare(strict_types=1);

/**
 * Settings, under the platform's own URL and inside its own shell.
 *
 * The platform sidebar used to link straight at admin/settings.php. That page
 * renders the COMPANY shell, so one click left the platform entirely and the
 * sidebar came back as whichever client's books were last open — the screen
 * said "Current portal: Bardia Resort" when all you had asked for was
 * settings.
 *
 * The page itself is unchanged and still does its own permission check. All
 * this does is own the URL and ask for the platform navigation.
 */

$platformShell = true;
require __DIR__ . '/../admin/settings.php';
