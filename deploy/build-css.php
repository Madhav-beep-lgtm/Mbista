<?php
declare(strict_types=1);

/**
 * Build the CSS file served by the application.
 *
 * Keep component source files small enough to own and review, while avoiding
 * a long, order-sensitive list of stylesheets in every page shell.
 *
 * Usage:
 *   php deploy/build-css.php
 *   php deploy/build-css.php --check
 */

$root = dirname(__DIR__);
$cssDirectory = $root . '/public_html/assets/css';
$checkOnly = in_array('--check', $argv, true);
$quiet = in_array('--quiet', $argv, true);

$commonSources = [
    'tokens.css',
    'base.css',
    'components.css',
    'layout.css',
    'themes.css',
    'print.css',
];

$bundles = [
    'app.css' => $commonSources,
];

$failed = false;
foreach ($bundles as $bundle => $sources) {
    $sections = [
        "/* GENERATED FILE — edit the source stylesheets and run php deploy/build-css.php. */",
    ];

    foreach ($sources as $source) {
        $sourcePath = $cssDirectory . '/' . $source;
        if (!is_file($sourcePath)) {
            fwrite(STDERR, "CSS source is missing: {$sourcePath}\n");
            exit(1);
        }

        $content = file_get_contents($sourcePath);
        if ($content === false) {
            fwrite(STDERR, "Could not read CSS source: {$sourcePath}\n");
            exit(1);
        }

        $sections[] = "/* SOURCE: {$source} */\n" . rtrim($content);
    }

    $compiled = implode("\n\n", $sections) . "\n";
    $targetPath = $cssDirectory . '/' . $bundle;
    $current = is_file($targetPath) ? file_get_contents($targetPath) : false;

    if ($checkOnly) {
        if ($current !== $compiled) {
            fwrite(STDERR, "CSS bundle is out of date: {$bundle}\n");
            $failed = true;
        } elseif (!$quiet) {
            fwrite(STDOUT, "CSS bundle is current: {$bundle}\n");
        }
        continue;
    }

    if ($current === $compiled) {
        if (!$quiet) {
            fwrite(STDOUT, "CSS bundle unchanged: {$bundle}\n");
        }
        continue;
    }

    if (file_put_contents($targetPath, $compiled, LOCK_EX) === false) {
        fwrite(STDERR, "Could not write CSS bundle: {$targetPath}\n");
        exit(1);
    }

    if (!$quiet) {
        fwrite(STDOUT, "Built CSS bundle: {$bundle}\n");
    }
}

exit($failed ? 1 : 0);
