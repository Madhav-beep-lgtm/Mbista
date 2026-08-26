<?php
declare(strict_types=1);

/**
 * Front-end hygiene: the things that rot quietly.
 *
 * None of these break a test suite or throw an error. They show up as a page
 * that is unreadable at night, an icon that renders as nothing, or source code
 * served to anyone who asks for it by name. So they are asserted here, where a
 * regression is caught the same way an arithmetic one would be.
 *
 *   php database/test_frontend_hygiene.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

/** Every .php under a directory, ignoring local backups. */
function hygiene_php_files(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && !str_contains($file->getPathname(), '.bak')) {
            $out[] = $file->getPathname();
        }
    }

    return $out;
}

echo "1. Nothing under the web root is served as source\n";
// Apache runs .php but SERVES foo.php.bak as plain text. One forgotten backup
// publishes a whole file to anyone who guesses the name.
$exposed = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public_html', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    if (preg_match('/\.(bak|old|orig|save|swp|swo|rej)$|~$/', $file->getFilename())) {
        $exposed[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
    }
}
ok($exposed === [], 'No backup or swap files under public_html'
    . ($exposed === [] ? '' : ': ' . implode(', ', array_slice($exposed, 0, 4))));

$htaccess = (string) file_get_contents($root . '/public_html/.htaccess');
ok(str_contains($htaccess, 'bak'), 'And .htaccess denies them even if one appears');
ok(stripos($htaccess, 'RewriteCond %{HTTPS} !=on') !== false, 'HTTPS is forced');
ok(stripos($htaccess, 'Strict-Transport-Security') !== false, 'HSTS is set');
ok(stripos($htaccess, 'Content-Security-Policy') !== false, 'A content security policy is set');

$deploy = (string) file_get_contents($root . '/deploy/tasks.sh');
ok(str_contains($deploy, "--exclude='*.bak'"), 'And the deploy cannot copy one up');

echo "\n2. Every icon the app asks for actually draws\n";
// icon() returns '' for a name it does not know, which renders as nothing at
// all — a button with no glyph, and no error anywhere.
$used = [];
foreach (array_merge(hygiene_php_files($root . '/app'), hygiene_php_files($root . '/public_html')) as $path) {
    if (preg_match_all("~icon\('([a-z0-9_-]+)'\)~", (string) file_get_contents($path), $matches)) {
        foreach ($matches[1] as $name) {
            $used[$name] = true;
        }
    }
}
$missing = [];
foreach (array_keys($used) as $name) {
    if (stripos(icon($name), '<svg') === false) {
        $missing[] = $name;
    }
}
ok($used !== [], 'Icons are in use (' . count($used) . ' distinct)');
ok($missing === [], 'Every one resolves to an SVG'
    . ($missing === [] ? '' : ' — missing: ' . implode(', ', $missing)));

echo "\n3. Colours follow the theme\n";
// The app has a dark mode driven by CSS custom properties. A fixed hex in a
// colour declaration keeps its light value on the dark canvas, which is how a
// red delete link ends up unreadable at night.
//
// Surfaces where a FIXED colour is the correct answer, not an oversight:
//
//   the printed documents — a bill, a payslip, a statement, an agreement. They
//   are ink on paper. Theming them would make what comes out of the printer
//   follow whether the clerk happened to be in dark mode.
//
//   the rich-text editor in agreement-builder, which is a white page showing
//   what the agreement will look like printed. A WYSIWYG canvas that changes
//   colour with the theme is no longer WYS.
//
// Each was read before being listed. This is not a way of silencing the check.
$printPages = [
    'jewellery-invoice.php',      // the shop's sales bill, A4 landscape
    'jewellery-print.php',        // generic document preview
    'export-agreement.php',       // Word/print export of an agreement
    'payroll-payslip.php',        // a payslip handed to an employee
    'reports-center.php',         // printable report sheets
    'stock-summary-report.php',   // @page A4 landscape
    'stock-ledger.php',           // printable ledger
    'accounting-parties.php',     // the party statement print sheet
    'agreement-builder.php',      // WYSIWYG editor canvas
];
$offenders = [];
foreach (array_merge(hygiene_php_files($root . '/public_html/admin'), hygiene_php_files($root . '/app/views')) as $path) {
    if (in_array(basename($path), $printPages, true)) {
        continue;
    }
    $src = (string) file_get_contents($path);
    // A colour DECLARATION with a literal hex and no var() fallback.
    if (preg_match_all('~(?:^|[;\s"\'{])(?:color|background|background-color|border-color|border-left-color|fill|stroke)\s*:\s*(#[0-9a-fA-F]{3,6})\b~', $src, $matches)) {
        foreach ($matches[1] as $hex) {
            $offenders[] = basename($path) . ' ' . $hex;
        }
    }
}
ok($offenders === [], 'No fixed hex in a colour declaration outside the print pages'
    . ($offenders === [] ? '' : ' — ' . implode(', ', array_slice(array_unique($offenders), 0, 6))));

$portal = (string) file_get_contents($root . '/public_html/assets/css/portal.css');
ok(substr_count($portal, '--mbw-red') >= 2, 'The red token is defined for both themes');
ok(substr_count($portal, '--mbw-amber') >= 2, 'So is amber');
ok(substr_count($portal, '--mbw-green') >= 2, 'And green');
ok(str_contains($portal, 'theme-dark'), 'And a dark theme block exists to flip them');

echo "\n4. The theme can actually be switched\n";
$mainJs = (string) file_get_contents($root . '/public_html/assets/js/main.js');
ok(str_contains($mainJs, 'data-theme-toggle'), 'The toggle is wired up');
ok(str_contains($mainJs, "localStorage.setItem(storageKey"), 'And the choice is remembered');
$headers = ['admin_header.php', 'client_header.php', 'staff_header.php', 'header.php'];
$without = [];
foreach ($headers as $header) {
    $path = $root . '/app/views/partials/' . $header;
    if (is_file($path) && !str_contains((string) file_get_contents($path), 'data-theme-toggle')) {
        $without[] = $header;
    }
}
ok($without === [], 'Every layout offers the toggle'
    . ($without === [] ? '' : ' — missing from ' . implode(', ', $without)));

echo "\n5. No stray PHP close tags reaching the page\n";
/*
 * A close tag followed by a second one does not error: the second is HTML, and
 * the page prints those two characters to the user. It happened once here, from
 * an automated edit, and nothing failed — the page just had rubbish at the top.
 *
 * This comment is a BLOCK comment on purpose. A close tag inside a // comment
 * ends PHP mode, so writing this note the obvious way turned the rest of this
 * very file into HTML and made the suite print its own source.
 */
$strays = [];
foreach (array_merge(hygiene_php_files($root . '/public_html'), hygiene_php_files($root . '/app/views')) as $path) {
    if (preg_match('~\?>\s*\?>~', (string) file_get_contents($path))) {
        $strays[] = basename($path);
    }
}
ok($strays === [], 'No file closes PHP twice in a row'
    . ($strays === [] ? '' : ': ' . implode(', ', $strays)));

echo "\n6. The jewellery skin is wired up\n";
ok(is_file($root . '/public_html/assets/css/jewellery.css'), 'The module stylesheet exists');
$skin = (string) file_get_contents($root . '/public_html/assets/css/jewellery.css');
ok(!preg_match('~:\s*#[0-9a-fA-F]{3,6}\s*[;}]~', str_replace('var(--', '', $skin)) || substr_count($skin, 'var(--') > 20,
    'And is written in tokens, so it follows the theme');
$trade = (string) file_get_contents($root . '/public_html/admin/jewellery-trade.php');
ok(str_contains($trade, 'jw_page_head('), 'The trade page uses the shared document header');
ok(substr_count($trade, 'jw_summary_rail(') === 2, 'Both the purchase and the sale carry a summary rail');
ok(str_contains($trade, 'jw_summary_rail_script();'), 'And the live-totals script is emitted');

echo "\n7. Long pages fold into sections instead of scrolling forever\n";
/*
 * Settings and masters screens stack a dozen cards each. Every card that opts
 * in with data-collapsible folds to its heading, and the choice is remembered
 * per page — so a shop that lives in Taxes finds Taxes open and the rest away.
 */
ok(str_contains($mainJs, '[data-collapsible]'), 'The script looks for opted-in cards');
ok(str_contains($mainJs, 'mbw-card-body'), 'And wraps the part it hides');
ok(str_contains($portal, '.is-collapsed .mbw-card-body'), 'The stylesheet hides it');
ok(str_contains($portal, '@media print'), 'But a printed page shows everything, whatever is folded on screen');

/*
 * The next two are not cosmetic.
 *
 * The body is re-homed into the HEADING'S parent, not the card's. On some
 * screens the heading sits INSIDE the form, and appending to the card would
 * lift those fields out of the form and stop them posting at all.
 */
ok(str_contains($mainJs, 'const host = head.parentNode;') && str_contains($mainJs, 'host.appendChild(body);'),
    'The hidden body stays inside whatever container the heading is in, so form fields keep posting');
/*
 * And a panel you can drag by its heading must not also collapse on a heading
 * click: dragging it and letting go IS a click, so the card would fold every
 * single time somebody moved it.
 */
ok(str_contains($mainJs, "if (!card.hasAttribute('data-draggable'))"),
    'A draggable panel folds only from its chevron, so dragging it does not collapse it');

$jewellery = (string) file_get_contents($root . '/public_html/admin/jewellery.php');
ok(substr_count($jewellery, 'data-collapsible') >= 15, 'The jewellery screens are segregated');
ok(str_contains($jewellery, 'data-collapsible data-collapsed'),
    'With the secondary sections folded to begin with');

$unmarked = [];
foreach (['hospitality', 'workspace', 'hr', 'fixed-assets', 'accounting-inventory', 'jewellery-workshop'] as $page) {
    $path = $root . '/public_html/admin/' . $page . '.php';
    if (is_file($path) && !str_contains((string) file_get_contents($path), 'data-collapsible')) {
        $unmarked[] = $page;
    }
}
ok($unmarked === [], 'And so is every other card-heavy module'
    . ($unmarked === [] ? '' : ' — not ' . implode(', ', $unmarked)));

echo "\n8. Every master dropdown offers \"+ Add new\"\n";
/*
 * A clerk halfway through a bill finds the customer is not on the list. Without
 * this the only way out is to abandon the document, go and create the record,
 * and start again.
 */
ok(str_contains($mainJs, '+ Add new'), 'The option exists');
ok(str_contains($mainJs, 'select.appendChild(option)'),
    'Appended LAST, so arrowing through a list never lands on it by accident');
ok(str_contains($mainJs, '_blank'), 'It opens in a new tab, so the half-filled document survives');
ok(str_contains($mainJs, 'data-last-value'), 'And the dropdown goes back to what was chosen before');

/*
 * The exclusions the user asked for, which are also just correct: a filter list
 * is for narrowing what you are looking at, not for creating anything.
 */
ok(str_contains($mainJs, "!== 'post'"), 'Only data-entry forms get it — a GET form is a filter');
ok(str_contains($mainJs, '.jw-filter'), 'The filter bar is skipped explicitly');
ok(str_contains($mainJs, "indexOf('report')"), 'And report pages get none of it');

/*
 * A dropdown that sends you to a page which does not exist is worse than one
 * that sends you nowhere, so every target is checked against the filesystem.
 */
// The optional #fragment matters: without it in the pattern, links that name
// a section slipped out of this check entirely and stopped being verified.
preg_match_all("~'(admin/[a-z0-9-]+\.php(?:\?view=[a-z0-9-]+)?(?:#[a-z0-9-]+)?)'~", $mainJs, $addNewTargets);
$targets = array_unique($addNewTargets[1]);
ok(count($targets) >= 15, 'Master screens are mapped across the app (' . count($targets) . ')');

/*
 * Checking the FILE exists is not enough — it let me ship links to
 * accounting.php?tab=ledgers, a tab that page never had. So when a target names
 * a view, that view has to be one the page actually knows about.
 */
$missingTargets = [];
foreach ($targets as $target) {
    $target = explode('#', $target, 2)[0];
    [$targetFile, $targetQuery] = array_pad(explode('?', $target, 2), 2, '');
    $targetPath = $root . '/public_html/' . $targetFile;
    if (!is_file($targetPath)) {
        $missingTargets[] = $target . ' (no such page)';
        continue;
    }
    if ($targetQuery === '') {
        continue;
    }
    $targetView = substr($targetQuery, strlen('view='));
    $targetSource = (string) file_get_contents($targetPath);
    // Either the page branches on that view, or it links to it itself.
    if (!str_contains($targetSource, "'" . $targetView . "'") && !str_contains($targetSource, 'view=' . $targetView)) {
        $missingTargets[] = $target . ' (no such view)';
    }
}
ok($missingTargets === [], 'And every one goes to a page and a view that exist'
    . ($missingTargets === [] ? '' : ' — ' . implode(', ', $missingTargets)));

// The module lookup must cover the modules the map defines, or a whole map
// entry sits there unreachable.
$mappedModules = [];
foreach (['jewellery', 'accounting', 'hospitality', 'assets', 'hr', 'payroll', 'workspace'] as $moduleKey) {
    if (str_contains($mainJs, $moduleKey . ': {') && str_contains($mainJs, "'" . $moduleKey . "'],")) {
        $mappedModules[] = $moduleKey;
    }
}
ok(count($mappedModules) >= 7,
    'Every module in the map is reachable from a path pattern (' . implode(', ', $mappedModules) . ')');

/*
 * Landing on the right PAGE is not enough when that page folds its sections.
 * Adding collapsible cards broke this feature two commits after shipping it: a
 * purity dropdown sent you to view=masters, where Purity Grades starts folded
 * shut, so the link looked like it did nothing.
 */
preg_match_all("~'admin/jewellery\.php\?view=masters([^']*)'~", $mainJs, $mastersRaw);
$mastersLinks = $mastersRaw[1];
ok($mastersLinks !== [] && !in_array('', $mastersLinks, true),
    'Every link into the shared masters page names the section it wants, not just the page');

$jewellerySource = (string) file_get_contents($root . '/public_html/admin/jewellery.php');
$headingSlugs = [];
if (preg_match_all('~<h2>(.*?)</h2>~', $jewellerySource, $headings)) {
    foreach ($headings[1] as $heading) {
        // The id is built in the browser by slugifying the heading, so this has
        // to slugify exactly the same way.
        $literal = preg_replace('~<\?.*?\?>~', '', $heading);
        $headingSlugs[] = trim(preg_replace('~[^a-z0-9]+~', '-',
            strtolower(html_entity_decode(preg_replace('~\(.*?\)~', ' ', $literal), ENT_QUOTES | ENT_HTML5))), '-');
    }
}
$badAnchors = [];
foreach (array_unique($mastersLinks) as $fragment) {
    if (!in_array(ltrim($fragment, '#'), $headingSlugs, true)) { $badAnchors[] = $fragment; }
}
ok($badAnchors === [], 'And each of those sections really exists on the page'
    . ($badAnchors === [] ? '' : ' — no heading slugifies to ' . implode(', ', $badAnchors)));
ok(str_contains($mainJs, 'window.location.hash.slice(1)'),
    'A card the URL points at opens itself, beating both a folded default and a fold the user set');

/*
 * And the round trip has to finish. Opening the master screen in a new tab kept
 * the half-filled bill, but the new record was not in the dropdown on return,
 * so the only way to use it was a reload — which threw the bill away.
 */
ok(str_contains($mainJs, "window.addEventListener('focus'"),
    'Returning to the tab refreshes the lists, so the record just added can be used');
ok(str_contains($mainJs, 'insertBefore(fresher, addNew)'),
    'New options land before "+ Add new", so it stays the last thing on the list');
ok(str_contains($mainJs, "['view', 'tab'].forEach"),
    'The refresh re-fetches only view/tab — never ?delete= or ?edit=, which would re-run on the server');

echo "\n9. A table too wide for its card scrolls instead of crushing\n";
/*
 * `table { width: 100% }` is right for a five-column list and quietly wrong for
 * a sixteen-column grid: inside a scroll wrapper it tells the table to FIT, so
 * the browser squeezes every column down to its minimum and wraps the words,
 * and the scrollbar never appears. Nothing errors. It just gets harder to read
 * every time a column is added — exactly the kind of rot this file is for.
 */
$portalCss = (string) file_get_contents($root . '/public_html/assets/css/portal.css');
ok(str_contains($portalCss, '.mbw-tablewrap {') && str_contains($portalCss, 'overflow-x: auto;'),
    'There is one wrapper class for wide tables to scroll inside');
$gridWidth = strstr($portalCss, 'body.admin-layout .vch-table,');
$gridWidth = $gridWidth === false ? '' : substr($gridWidth, 0, 160);
ok(str_contains($gridWidth, 'width: auto') && str_contains($gridWidth, 'min-width: 100%'),
    'Grid tables are as wide as their content and never narrower than the card');

// A sticky header over a transparent background has the rows sliding through
// the words, and table headers ARE transparent by default in this stylesheet.
$stickyBlock = strstr($portalCss, '.mbw-tablewrap thead th');
$stickyBlock = $stickyBlock === false ? '' : substr($stickyBlock, 0, 260);
ok(str_contains($stickyBlock, 'position: sticky') && str_contains($stickyBlock, 'background:'),
    'The sticky header carries a background, so rows cannot show through it');

/*
 * The scrollbar is the affordance, and it has to be VISIBLE. The first attempt
 * signalled the overflow with a background gradient on the wrapper — which sits
 * behind the table, where the opaque striped rows cover it completely. The
 * table scrolled correctly and looked exactly like a table that had been cut
 * off, which is worse than no attempt at all.
 */
// Scoped to the wrapper's own block. The first version of this looked for
// "scrollbar-width" anywhere in the file and passed on the sidebar's copy —
// an assertion that could never fail is not an assertion.
$wrapBlock = strstr($portalCss, 'body.admin-layout .mbw-tablewrap {');
$wrapBlock = $wrapBlock === false ? '' : substr($wrapBlock, 0, (int) strpos($wrapBlock, '}') + 1);
ok(str_contains($wrapBlock, 'scrollbar-color:'),
    'The wrapper colours its own scrollbar rather than relying on paint behind the rows');
ok(str_contains($portalCss, '.mbw-tablewrap::-webkit-scrollbar'),
    'With the webkit rules kept for engines that have no standard property');

// The reason the grid could still push the page sideways after the wrapper was
// added: a grid item's automatic minimum is its min-content, and the forms
// these grids sit in compute to display:grid.
ok(preg_match('~body\.admin-layout \.vch-grid \{\s*\R\s*min-width: 0;~', $portalCss) === 1,
    'And the grid itself agrees to shrink, or the wrapper is handed all the width it asks for');
ok(!preg_match('~\.mbw-tablewrap \{[^}]*background:\s*\R?\s*linear-gradient~', $portalCss),
    'And does not signal overflow with a background the table would hide');
ok(preg_match('~\.mbw-tablewrap[^{]*\{[^}]*#[0-9a-fA-F]{3,6}\b~', $portalCss) !== 1,
    'It paints itself from tokens, so it follows the theme');

// A grid item's automatic minimum is its min-content, so a wide table can push
// the whole main column past the viewport and set the PAGE scrolling sideways
// instead of the table. Both levels have to agree to shrink.
ok(preg_match('~body\.admin-layout \.admin-main \{\s*\R\s*min-width: 0;~', $portalCss) === 1,
    'The main column agrees to shrink, so the page never scrolls sideways instead of the table');

echo "\n10. A changed stylesheet actually reaches the browser\n";
/*
 * Assets carried a hand-typed stamp — portal.css?v=20260728 — which works
 * exactly as long as somebody remembers to change it. Nobody does. The CSS is
 * edited, the stamp stays, every browser that has been to the site keeps its
 * old copy, and the fix is live on the server and invisible in the room. It
 * cost an afternoon here: two CSS fixes shipped, neither reached the screen,
 * and both were re-diagnosed as CSS bugs they were not.
 *
 * filemtime cannot be forgotten, and rsync -a preserves it across the deploy.
 */
$stamped = [];
foreach (hygiene_php_files($root . '/app/views/partials') as $partial) {
    $body = (string) file_get_contents($partial);
    if (preg_match('~(href|src)="/assets/(?:css|js)/[^"]*\?v=[A-Za-z0-9]+"~', $body)) {
        $stamped[] = basename($partial);
    }
}
ok($stamped === [], 'No layout hand-types an asset version'
    . ($stamped === [] ? '' : ': ' . implode(', ', array_unique($stamped))));
ok(str_contains((string) file_get_contents($root . '/app/helpers.php'), 'function asset_url('),
    'They go through asset_url(), which stamps each file with its own modified time');

echo "\n11. The topbar ends where its column ends\n";
/*
 * The topbar reaches the edge of the main column by cancelling that column's
 * padding with an equal negative margin. Two numbers that must agree, written
 * 4000 lines apart — so of course they stopped agreeing: a later "compact
 * spacing" pass retuned .admin-main from 20/24 to 14/20 and left the topbar's
 * -20/-24 behind. Eight pixels wider than its column, every admin page got a
 * horizontal scrollbar, and nothing in the CSS looked wrong at either site.
 *
 * This reads the numbers the browser would end up with (last declaration wins,
 * same specificity) and does the subtraction, rather than pinning the values.
 * Retune the spacing freely; just retune both.
 */
$lastDecl = static function (string $selector, string $property) use ($portalCss): string {
    $pattern = '~' . preg_quote($selector, '~') . '\s*\{[^}]*?\b' . preg_quote($property, '~') . ':\s*([^;}]+)~';

    return preg_match_all($pattern, $portalCss, $m) ? trim(end($m[1])) : '';
};
// "14px 20px 22px" -> left/right 20px; "-14px -20px 12px" -> left/right -20px.
$sides = static function (string $shorthand): ?float {
    $parts = preg_split('~\s+~', trim($shorthand)) ?: [];
    if (count($parts) < 2) { return null; }

    return (float) $parts[1];
};
$mainPad = $sides($lastDecl('body.admin-layout .admin-main', 'padding'));
$barMargin = $sides($lastDecl('body.admin-layout .admin-topbar', 'margin'));
$barPad = $sides($lastDecl('body.admin-layout .admin-topbar', 'padding'));
ok($mainPad !== null && $barMargin !== null,
    'Both the column padding and the topbar margin are readable from the stylesheet');
ok($mainPad !== null && $barMargin !== null && abs($mainPad + $barMargin) < 0.01,
    "The topbar's negative margin cancels the column's padding exactly"
    . ' (column ' . $mainPad . 'px, bar ' . $barMargin . 'px)');
ok($barPad !== null && $mainPad !== null && abs($barPad - $mainPad) < 0.01,
    'And it puts that padding back, so its contents still line up with the page below');

echo "\n12. The sidebar collapses to a rail\n";
/*
 * Collapsed, it is a 62px icon rail rather than nothing: hiding it outright
 * buys ~174px and costs every one of the twenty-odd destinations in it.
 */
$mainJs = (string) file_get_contents($root . '/public_html/assets/js/main.js');
$boot = (string) file_get_contents($root . '/app/views/partials/sidebar_boot.php');
$toggle = (string) file_get_contents($root . '/app/views/partials/sidebar_toggle.php');

ok(str_contains($toggle, 'data-sidebar-toggle'), 'There is one shared control, not a copy per layout');
$missingToggle = [];
$missingBoot = [];
foreach (['admin_header.php', 'staff_header.php', 'client_header.php'] as $layout) {
    $source = (string) file_get_contents($root . '/app/views/partials/' . $layout);
    if (!str_contains($source, "require __DIR__ . '/sidebar_toggle.php'")) { $missingToggle[] = $layout; }
    if (!str_contains($source, "require __DIR__ . '/sidebar_boot.php'")) { $missingBoot[] = $layout; }
}
ok($missingToggle === [], 'Every shell that has a sidebar has the control'
    . ($missingToggle === [] ? '' : ': ' . implode(', ', $missingToggle)));

/*
 * The restore has to happen inline, inside <body>, before the footer script.
 * Left to main.js it would paint the full sidebar and then snap it shut on
 * every single navigation, and one flash per page is worse than not
 * remembering at all.
 */
ok($missingBoot === [], 'And restores the remembered state before first paint'
    . ($missingBoot === [] ? '' : ': ' . implode(', ', $missingBoot)));
foreach (['admin_header.php', 'staff_header.php', 'client_header.php'] as $layout) {
    $source = (string) file_get_contents($root . '/app/views/partials/' . $layout);
    $bodyAt = strpos($source, '<body class=');
    $bootAt = strpos($source, "require __DIR__ . '/sidebar_boot.php'");
    $shellAt = strpos($source, '<div class="admin-shell">');
    ok($bodyAt !== false && $bootAt !== false && $shellAt !== false
        && $bootAt > $bodyAt && $bootAt < $shellAt,
        "$layout restores it after <body> opens and before the shell is drawn");
}
ok(str_contains($boot, 'localStorage.getItem') && str_contains($boot, 'sidebar-collapsed'),
    'The restore reads the remembered choice and sets the class on <body>');
ok(str_contains($boot, 'catch'), 'And survives private browsing, where storage throws');

ok(preg_match('~body\.admin-layout\.sidebar-collapsed \.admin-shell \{[^}]*grid-template-columns:\s*62px~', $portalCss) === 1,
    'Collapsed, the shell column becomes a rail rather than closing to zero');
/*
 * Labels go away with font-size: 0 on the link, not by wrapping each one in a
 * span. There are around sixty of them across three partials, written as
 * `<?= icon('x') ?>Label` — a bare text node with nothing to hang a class on.
 * font-size: 0 collapses the text; the SVG is sized in px and does not move.
 */
ok(preg_match('~body\.admin-layout\.sidebar-collapsed \.admin-nav a \{[^}]*font-size:\s*0~', $portalCss) === 1,
    'The labels collapse while the icons, sized in px, keep their size');
ok(str_contains($mainJs, "link.setAttribute('title', label)"),
    'And move to tooltips, so a bare icon can still be identified');
ok(str_contains($mainJs, "localStorage.setItem(sidebarStorageKey"), 'The choice is remembered');
ok(str_contains($mainJs, "aria-expanded") && str_contains($mainJs, "'Expand sidebar' : 'Collapse sidebar'"),
    'The control says what pressing it will do, and says so to screen readers too');

/*
 * A submenu has nowhere to open on a 62px rail, so pressing a group there
 * would be a dead click. It opens the sidebar and the group together.
 */
$navToggleHandler = strstr($mainJs, "toggle.addEventListener('click', (event) => {");
$navToggleHandler = $navToggleHandler === false ? '' : substr($navToggleHandler, 0, 900);
ok(str_contains($navToggleHandler, "classList.contains('sidebar-collapsed')")
    && str_contains($navToggleHandler, 'setSidebarCollapsed(false)')
    && str_contains($navToggleHandler, 'openOnlyNav(parent)'),
    'Pressing a nav group on the rail opens the sidebar with it, instead of doing nothing visible');
ok(str_contains($mainJs, "event.target.closest('input, textarea, select, [contenteditable=\"true\"]')"),
    'And the keyboard shortcut never fires while somebody is typing');

// Below 1100px the shell is one column and the sidebar sits above the content,
// where a rail costs a full row of height and saves nothing.
ok(preg_match('~@media \(max-width: 1100px\) \{\s*\R\s*body\.admin-layout\.sidebar-collapsed[^@]*\.admin-sidebar \{\s*\R\s*display: none~', $portalCss) === 1,
    'On a narrow screen, where the sidebar stacks above the content, collapsed means gone');

// ---------------------------------------------------------------------------
// The admin shell on a phone
// ---------------------------------------------------------------------------
/*
 * Below 1100px the shell was one column with the sidebar static above the
 * content — right on a narrow laptop window, wrong on a 390px screen, where it
 * meant every visit opened on a full page of navigation and the figures started
 * somewhere below all of it. Nobody scrolls past their own menu to read a
 * balance. Under 900px it is a drawer instead, shut until asked for.
 */
$boot = (string) @file_get_contents($root . '/app/views/partials/sidebar_boot.php');
ok(str_contains($boot, 'max-width: 900px') && str_contains($boot, 'sidebar-collapsed'),
    'The sidebar starts SHUT on a phone, before first paint, whatever a desktop session remembered');

ok(preg_match('~@media \(max-width: 900px\)[^@]*?\.admin-sidebar\s*\{[^}]*position:\s*fixed~s', $portalCss) === 1,
    'Under 900px the sidebar is fixed over the page, not a block that pushes it down');
ok(preg_match('~@media \(max-width: 900px\)[^@]*?sidebar-collapsed\s+\.admin-sidebar\s*\{[^}]*transform:\s*translateX\(-100%\)~s', $portalCss) === 1,
    'Shut means off the left edge — not display:none, which cannot animate');
ok(preg_match('~@media \(max-width: 900px\)[^@]*?\.admin-shell::before~s', $portalCss) === 1,
    'There is a scrim, so the way out of the drawer is not the button underneath it');
ok(preg_match('~@media \(min-width: 901px\)[^}]*\.admin-shell::before\s*\{\s*content:\s*none~s', $portalCss) === 1,
    'And the scrim stops existing above the breakpoint — a fixed overlay on a desktop would cover the page');

$mainJs2 = (string) @file_get_contents($root . '/public_html/assets/js/main.js');
ok(str_contains($mainJs2, "matchMedia('(max-width: 900px)')") && str_contains($mainJs2, 'isDrawer'),
    'main.js knows when the sidebar is a drawer');
ok(preg_match('~closest\(\'\.admin-sidebar\'\)[^;]*closest\(\'\[data-sidebar-toggle\]\'\)~', $mainJs2) === 1,
    'A press outside the drawer shuts it, and a press on the toggle is not counted as outside');
ok(str_contains($mainJs2, "event.key !== 'Escape'"), 'Escape shuts it too');
/*
 * The one that would have leaked. setSidebarCollapsed persists to the same key
 * the desktop rail restores from, so every tap of the phone drawer was writing
 * the monitor's preference — an evening on the phone came back as a collapsed
 * rail next morning with nothing to explain it.
 */
ok(preg_match('~if \(!\(window\.matchMedia && window\.matchMedia\(\'\(max-width: 900px\)\'\)\.matches\)\) \{\s*\R\s*localStorage\.setItem\(sidebarStorageKey~', $mainJs2) === 1,
    'Opening the drawer on a phone is NOT remembered — it must not rewrite the desktop rail preference');

// ---------------------------------------------------------------------------
// Installable on a phone, and safe to be
// ---------------------------------------------------------------------------
$manifest = json_decode((string) @file_get_contents($root . '/public_html/manifest.webmanifest'), true);
ok(is_array($manifest), 'The web app manifest is valid JSON — a broken one is ignored silently by every browser');
ok(($manifest['display'] ?? '') === 'standalone',
    'It opens standalone, which is what makes it look like an app and not a browser tab');
$iconSizes = array_map(static fn (array $i): string => (string) ($i['sizes'] ?? ''), (array) ($manifest['icons'] ?? []));
ok(in_array('192x192', $iconSizes, true) && in_array('512x512', $iconSizes, true),
    'With the 192 and 512 icons a manifest is required to carry');
$purposes = array_map(static fn (array $i): string => (string) ($i['purpose'] ?? ''), (array) ($manifest['icons'] ?? []));
ok(in_array('maskable', $purposes, true),
    'And a MASKABLE one — Android crops every icon to its launcher shape, and a normal icon loses its border to that crop');
foreach (['icon-192.png', 'icon-512.png', 'icon-512-maskable.png', 'apple-touch-icon.png'] as $iconFile) {
    ok(is_file($root . '/public_html/assets/img/' . $iconFile),
        "$iconFile is committed as a file — a phone asks for it before PHP runs");
}

$pwaHead = (string) @file_get_contents($root . '/app/views/partials/pwa_head.php');
ok(str_contains($pwaHead, 'rel="apple-touch-icon"'),
    'apple-touch-icon is declared — iOS reads NONE of the manifest icons for the home screen, and falls back to a screenshot of the page');
foreach (['admin_header', 'client_header', 'header', 'staff_header'] as $layout) {
    ok(str_contains((string) @file_get_contents($root . "/app/views/partials/$layout.php"), 'pwa_head.php'),
        "$layout pulls the PWA head in — one layout missing it is one way into the app that cannot be installed");
}

/*
 * THE ONE THAT MATTERS. A service worker that caches pages hands the shop a
 * balance, a stock figure or an outstanding amount from an earlier visit with
 * nothing on screen to say it is old, and somebody takes payment against it. A
 * stale number is worse than an error, because an error is obvious and a wrong
 * number gets acted on.
 */
$sw = (string) @file_get_contents($root . '/public_html/sw.js');
ok($sw !== '', 'There is a service worker');
ok(str_contains($sw, "request.method !== 'GET'"),
    'It never touches anything but GET — a queued sale replayed later against a moved rate board is its own disaster');
ok(str_contains($sw, 'function isStaticAsset'),
    'Caching is limited to a named list of static file types');
ok(str_contains($sw, "if (request.mode === 'navigate')") && str_contains($sw, 'fetch(request).catch'),
    'Pages go to the NETWORK every time, and the offline page appears only when that fetch fails');
ok(!preg_match('~caches\.match\(request\)[^;]*navigate~s', $sw),
    'No path serves a cached PAGE back — the whole point of the design');
$offline = (string) @file_get_contents($root . '/public_html/offline.html');
ok($offline !== '' && stripos($offline, 'nothing at all') !== false,
    'And the offline page shows no figures at all, on purpose, and says why');

// The line grid: 21 columns on a 390px phone is four and a half screens of
// sideways dragging per row, with the header scrolled out of sight.
$grid = (string) @file_get_contents($root . '/app/views/partials/jewellery_line_grid.php');
ok(substr_count($grid, 'data-label="') >= 20,
    'Every grid cell carries its own caption, so a row can be read as a card when the header is gone');
ok(str_contains($grid, '@media (max-width: 720px)') && str_contains($grid, 'content: attr(data-label)'),
    'And below 720px the rows ARE cards, captions and all');
ok(preg_match('~@media \(max-width: 720px\).*?font-size: 16px~s', $grid) === 1,
    'Inputs hit 16px on a phone — Safari zooms the whole page for anything smaller and does not zoom back');

echo "\n15. The 2026 appearance layer has the last word\n";
// mbworld-2026.css restates tokens that style.css, portal.css, theme-brown
// and theme-sahakari-green each also declare. Equal specificity is settled by
// load order, so the ENTIRE retheme rests on this file being linked last. Add
// a stylesheet after it and half the palette silently reverts — the portal
// keeps the new canvas and goes back to the old cards, which reads as a
// half-finished design rather than as a mistake in a <link> order.
$layer = 'assets/css/mbworld-2026.css';
$shells = ['admin_header.php', 'client_header.php', 'staff_header.php', 'header.php'];
$missing = $notLast = [];
foreach ($shells as $shell) {
    $src = (string) @file_get_contents($root . '/app/views/partials/' . $shell);
    if (!preg_match_all("~asset_url\('(assets/css/[^']+)'\)~", $src, $m)) {
        $missing[] = $shell;
        continue;
    }
    if (!in_array($layer, $m[1], true)) {
        $missing[] = $shell;
    } elseif (end($m[1]) !== $layer) {
        $notLast[] = $shell . ' (last is ' . end($m[1]) . ')';
    }
}
ok($missing === [], 'Every shell loads the appearance layer'
    . ($missing === [] ? '' : ' — missing from ' . implode(', ', $missing)));
ok($notLast === [], 'And loads it LAST, or its tokens lose to the sheet that follows'
    . ($notLast === [] ? '' : ' — ' . implode(', ', $notLast)));

$layerCss = (string) @file_get_contents($root . '/public_html/' . $layer);
ok($layerCss !== '' && substr_count($layerCss, '@font-face') >= 6,
    'The typefaces are self-hosted, because the CSP refuses fonts.googleapis.com');
$fontMisses = [];
if (preg_match_all('~url\("\.\./fonts/([^"]+)"\)~', $layerCss, $m)) {
    foreach (array_unique($m[1]) as $file) {
        if (!is_file($root . '/public_html/assets/fonts/' . $file)) {
            $fontMisses[] = $file;
        }
    }
}
ok($m[1] !== [] && $fontMisses === [], 'And every face it names is committed as a file'
    . ($fontMisses === [] ? '' : ' — missing ' . implode(', ', $fontMisses)));
// Comments stripped first: the file explains at length WHY it does not use
// fonts.googleapis.com, and a check that cannot tell an explanation from a
// request would fail on the sentence describing the thing it wants.
$layerRules = (string) preg_replace('~/\*[\s\S]*?\*/~', '', $layerCss);
ok(!preg_match('~(?:url\(|@import)[^;]*fonts\.(?:googleapis|gstatic)\.com~', $layerRules),
    'Nothing is fetched from a third-party font host at page load');

echo "\n16. Every class in the markup has something behind it\n";
// An audit found 834 distinct classes and 26 with no rule in any stylesheet,
// no inline style, and no styled sibling class on the same element — a name
// somebody wrote and never gave meaning to. They are invisible to review,
// because a page that renders with browser defaults looks deliberate until
// you compare it with the one beside it.
//
// Three things are NOT failures here and are excluded on purpose:
//   - JS hooks. A name a querySelector looks for exists to find an element,
//     not to paint it, so styling it would be inventing a requirement.
//   - Elements carrying a styled sibling class: <article class="card foo">
//     is painted by .card.
//   - Elements with an inline style attribute. Untidy, but not unstyled.
$markup = array_merge(hygiene_php_files($root . '/app'), hygiene_php_files($root . '/public_html'));

$styleSrc = '';
foreach (glob($root . '/public_html/assets/css/*.css') as $f) {
    $styleSrc .= (string) file_get_contents($f);
}
$scriptSrc = '';
foreach (glob($root . '/public_html/assets/js/*.js') as $f) {
    $scriptSrc .= (string) file_get_contents($f);
}
$tags = [];
foreach ($markup as $path) {
    $src = (string) file_get_contents($path);
    if (preg_match_all('~<style[^>]*>([\s\S]*?)</style>~i', $src, $m)) {
        $styleSrc .= "\n" . implode("\n", $m[1]);
    }
    if (preg_match_all('~<script[^>]*>([\s\S]*?)</script>~i', $src, $m)) {
        $scriptSrc .= "\n" . implode("\n", $m[1]);
    }
    if (preg_match_all('~<[a-zA-Z][^>]*class=(["\'])([^"\']*)\1[^>]*>~', $src, $m)) {
        foreach ($m[0] as $i => $tag) {
            $tags[] = [$tag, (string) $m[2][$i], basename($path)];
        }
    }
}
$styleSrc = (string) preg_replace('~/\*[\s\S]*?\*/~', '', $styleSrc);
// `*` and not `+`: the first version of this required two characters, so the
// single-letter classes in the printed invoice — `table.items .c { text-align:
// center }` and its `.r` — were invisible to it. They matched in the markup
// and never in the stylesheet, and only escaped being reported as unstyled
// because some of them happened to carry an inline width as well.
preg_match_all('~\.([a-zA-Z][a-zA-Z0-9_-]*)~', $styleSrc, $m);
$hasRule = array_flip(array_unique($m[1]));

$seen = $bare = [];
foreach ($tags as [$tag, $attr, $file]) {
    $classes = array_values(array_filter(preg_split('~\s+~', $attr), static function ($c) {
        return $c !== '' && preg_match('~^[a-zA-Z][a-zA-Z0-9_-]*$~', $c) === 1;
    }));
    $hasInline = strpos($tag, 'style=') !== false;
    foreach ($classes as $c) {
        if (isset($hasRule[$c])) { continue; }
        if (strpos($c, 'js-') === 0
            || strpos($scriptSrc, "'" . $c . "'") !== false
            || strpos($scriptSrc, '"' . $c . '"') !== false
            || strpos($scriptSrc, '.' . $c) !== false) {
            continue;
        }
        $covered = $hasInline;
        if (!$covered) {
            foreach ($classes as $other) {
                if ($other !== $c && isset($hasRule[$other])) { $covered = true; break; }
            }
        }
        $seen[$c] = true;
        if (!$covered) { $bare[$c] = $file; }
    }
}
ok($tags !== [], 'The markup was scanned for classes (' . count($tags) . ' tagged elements)');
ok($bare === [], 'No class renders with nothing behind it'
    . ($bare === [] ? '' : ' — ' . implode(', ', array_map(
        static fn($c, $f) => $c . ' (' . $f . ')',
        array_keys(array_slice($bare, 0, 6, true)),
        array_values(array_slice($bare, 0, 6, true))
    ))));

echo "\n17. A page that builds its own document is styled inside it\n";
// Section 16 asks whether a rule exists ANYWHERE. For a page that emits its
// own <!doctype and links no stylesheet, that is the wrong question: a rule
// written for it in assets/css reaches nothing while reading as correct in
// review. Here the question is whether the rule is in the document itself.
// Its own <style> blocks and inline style attributes count; the shared
// stylesheets deliberately do not.
//
// SCOPE, honestly. This covers pages that are standalone documents and
// nothing else. It does NOT cover the hybrid ones — stock-summary-report.php
// echoes a complete PDF document and exits, or falls through to the admin
// header and loads everything, depending on a query parameter. Deciding
// which half of such a file a given class belongs to needs the branch it
// sits in, which is beyond a regex over the source. Those pages are excluded
// rather than guessed at, and the exclusion is the reason this check cannot
// be relied on alone.
$standalone = [];
foreach (hygiene_php_files($root . '/public_html') as $path) {
    $src = (string) file_get_contents($path);
    if (stripos($src, '<!doctype html') === false) { continue; }
    if (preg_match('~rel=["\']stylesheet~i', $src) === 1) { continue; }
    if (preg_match('~partials/(admin_|staff_|client_)?header\.php~', $src) === 1) { continue; }
    $standalone[$path] = $src;
}
$orphans = [];
foreach ($standalone as $path => $src) {
    $own = '';
    if (preg_match_all('~<style[^>]*>([\s\S]*?)</style>~i', $src, $m)) {
        $own = implode("\n", $m[1]);
    }
    $own = (string) preg_replace('~/\*[\s\S]*?\*/~', '', $own);
    preg_match_all('~\.([a-zA-Z][a-zA-Z0-9_-]*)~', $own, $m);
    $ownRules = array_flip(array_unique($m[1]));

    if (!preg_match_all('~<[a-zA-Z][^>]*class=(["\'])([^"\']*)\1[^>]*>~', $src, $tm)) { continue; }
    foreach ($tm[0] as $i => $tag) {
        if (strpos($tag, 'style=') !== false) { continue; }
        foreach (preg_split('~\s+~', (string) $tm[2][$i]) as $c) {
            $c = trim((string) $c);
            if ($c === '' || preg_match('~^[a-zA-Z][a-zA-Z0-9_-]*$~', $c) !== 1) { continue; }
            if (isset($ownRules[$c])) { continue; }
            if (strpos($c, 'js-') === 0 || strpos($scriptSrc, '.' . $c) !== false) { continue; }
            $orphans[$c] = basename($path);
        }
    }
}
ok($standalone !== [], 'Standalone documents were found and checked (' . count($standalone) . ')');
ok($orphans === [], 'None of them uses a class styled only in a stylesheet it never loads'
    . ($orphans === [] ? '' : ' — ' . implode(', ', array_map(
        static fn($c, $f) => $c . ' (' . $f . ')',
        array_keys(array_slice($orphans, 0, 8, true)),
        array_values(array_slice($orphans, 0, 8, true))
    ))));

echo "\n18. Action buttons can be hit, and can be named\n";
// 74 of the app's 500 buttons carry an inline height under 44px — 24px on the
// agreement builder, 30px on several delete controls. 44px is the size a
// fingertip actually hits and the figure enforced everywhere else here; the
// suite already checks it for inputs and simply never covered buttons.
//
// The floor is stated once in CSS rather than by editing 74 attributes,
// because an inline style beats a stylesheet and the seventy-fifth button
// would arrive unprotected. What is asserted is that the floor exists.
$layerCss = (string) @file_get_contents($root . '/public_html/assets/css/mbworld-2026.css');
ok(preg_match('~@media[^{]*pointer:\s*coarse[^{]*\{~', $layerCss) === 1,
    'There is a touch-only rule, so nothing about the desktop layout is changed by it');
ok(preg_match('~pointer:\s*coarse[\s\S]{0,600}?min-height:\s*44px\s*!important~', $layerCss) === 1,
    'And it holds every button to a 44px minimum on a finger, over any inline height');

// A button whose only content is an icon or a glyph needs a name, or a screen
// reader announces "button" and nothing else. Two delete controls in payroll
// were reading exactly that way.
$unnamed = [];
foreach ($markup as $path) {
    $src = (string) file_get_contents($path);
    if (!preg_match_all('~<button\b([^>]*)>([\s\S]*?)</button>~i', $src, $bm, PREG_SET_ORDER)) { continue; }
    foreach ($bm as $b) {
        [$whole, $attrs, $inner] = [$b[0], (string) $b[1], (string) $b[2]];
        if (preg_match('~\baria-label\s*=|\btitle\s*=~i', $attrs) === 1) { continue; }
        // A PHP block usually IS the label; only a button with no words at
        // all, in markup or in PHP, is nameless.
        $php = '';
        if (preg_match_all('~<\?(?:php|=)([\s\S]*?)\?' . '>~', $inner, $pm)) { $php = implode(' ', $pm[1]); }
        if (preg_match('~[\'"][^\'"]*[A-Za-z]{2}~', $php) === 1
            || preg_match('~\b(?:e|esc|htmlspecialchars)\s*\(~', $php) === 1) { continue; }
        $text = trim((string) preg_replace('~<\?php[\s\S]*?\?' . '>|<\?=[\s\S]*?\?' . '>|<[^>]*>|&[a-z]+;|\s~i', '', $inner));
        if ($text !== '') { continue; }
        $unnamed[] = basename($path);
    }
}
ok($unnamed === [], 'No button is left without a name a screen reader can read'
    . ($unnamed === [] ? '' : ' — ' . implode(', ', array_unique(array_slice($unnamed, 0, 6)))));

// ---------------------------------------------------------------------------
// A row added after the page loaded must get a WORKING dropdown.
// ---------------------------------------------------------------------------
// The searchable-select enhancer replaces a <select> with a wrapper holding a
// text box and a list, and remembers that it did with data-ss-ready. Every
// grid on this site adds rows by CLONING the row above, and cloneNode copies
// that attribute along with the text box while copying none of the event
// listeners that made it work. So an added row arrived looking perfect --
// right name, whole option list, a search box -- and filtered nothing, chose
// nothing, submitted nothing. From the counter that reads as "it will not let
// me add more items", and it only appeared at all on companies holding twelve
// or more of whatever the dropdown lists, which is where this enhancer
// switches itself on.
//
// Asserted here rather than in a browser because there is no browser in this
// suite; each check names the specific thing whose absence caused the fault.
echo "\n== Dropdowns on rows added after page load ==\n";
$enhancer = (string) @file_get_contents($root . '/public_html/assets/js/searchable-select.js');
ok($enhancer !== '', 'The searchable-select enhancer is where it is expected to be');
ok(str_contains($enhancer, 'MutationObserver'),
    'It watches for selects added after it booted, instead of sweeping once and stopping');
ok(str_contains($enhancer, 'childList: true') && str_contains($enhancer, 'subtree: true'),
    '  ...over the whole document, because every grid adds rows somewhere different');
ok(str_contains($enhancer, 'unwrapClone'),
    'It strips the dead widget off a CLONED select before enhancing it');
ok(str_contains($enhancer, 'WeakSet'),
    '  ...telling a clone from its original by object identity, which an attribute cannot do');
// The flag alone must never be the test for "this one is working": that is the
// exact mistake, because cloneNode copies it.
ok(str_contains($enhancer, 'wired.has(sel)'),
    '  ...and never treats the copied data-ss-ready flag as proof on its own');

// Every grid that clones a row is a customer of the above. Listed so a new one
// is noticed here rather than at a counter.
$cloners = [];
foreach (hygiene_php_files($root . '/public_html/admin') as $path) {
    $php = (string) file_get_contents($path);
    if (str_contains($php, 'cloneNode(true)')) {
        $cloners[] = basename($path);
    }
}
ok($cloners !== [], 'Grids that add rows by cloning: ' . implode(', ', $cloners));

// ---------------------------------------------------------------------------
// A form that lives in a popup must not leave a hole in the page waiting for it.
// ---------------------------------------------------------------------------
// The counter billing form is moved into a <dialog> by form-popup.js, and the
// stylesheet hides it until that happens. It used to hide it with
// `visibility: hidden`, which KEEPS THE BOX: a screen-tall form left a
// screen-tall blank under the card heading while the rest of the document
// arrived, which reads as a page that loads in two goes and stalls halfway.
// And if <dialog> was not available the card was never un-hidden at all, so
// the form was simply gone.
echo "\n== Forms that open in a popup ==\n";
$popupJs = (string) @file_get_contents($root . '/public_html/assets/js/form-popup.js');
$adminCss = (string) @file_get_contents($root . '/public_html/assets/css/mbworld-2026.css');
ok($popupJs !== '' && $adminCss !== '', 'The popup script and the admin stylesheet are both present');
ok(str_contains($adminCss, '[data-form-popup]:not([data-form-popup-ready]) { display: none; }'),
    'A popup source is taken OUT of the flow, not just made invisible in place');
ok(!preg_match('~\[data-form-popup\]\s*\{\s*visibility:\s*hidden~', $adminCss),
    '  ...so it never reserves a screen-height blank while the page finishes arriving');
// The rule must LET GO once the script owns the card. Hiding it unscoped and
// hoping an inline style wins is how the dialog came to open with nothing in
// it: clearing an inline style does not beat a stylesheet, and the card is a
// grid, so an inline display put back to fight it would have to guess which.
ok(!preg_match('~\[data-form-popup\]\s*\{\s*display:\s*none~', $adminCss),
    '  ...and the rule stops applying once the script has taken the card over, '
    . 'rather than being fought with an inline style');
ok(str_contains($popupJs, "!window.HTMLDialogElement") && str_contains($popupJs, "card.style.display = ''"),
    'A browser without <dialog> gets the form left in the page, never hidden for good');
ok(str_contains($popupJs, "document.readyState === 'loading'"),
    'And the script does not wait for DOMContentLoaded when the page is already parsed');

// Saving a draft is somebody FINISHING with the form. The document stays
// loaded so nothing is lost, but the popup stays shut.
$tradePhp = (string) @file_get_contents($root . '/public_html/admin/jewellery-trade.php');
ok(substr_count($tradePhp, "'&edit=' . \$id . '&saved=1'") === 2,
    'Both save handlers come back marked as just-saved');
ok(str_contains($tradePhp, '$justSaved = ($_GET[\'saved\'] ?? \'\') === \'1\';'),
    '  ...read in one place');
ok(substr_count($tradePhp, 'data-popup-open="<?= $justSaved') === 1
    && substr_count($tradePhp, 'data-popup-open="<?= (!$justSaved') === 1,
    '  ...and both popups honour it rather than springing open again');

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
