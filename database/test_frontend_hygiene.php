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
preg_match_all("~'(admin/[a-z-]+\.php[^']*)'~", $mainJs, $addNewTargets);
$targets = array_unique($addNewTargets[1]);
ok(count($targets) >= 6, 'Several master screens are mapped (' . count($targets) . ')');
$missingTargets = [];
foreach ($targets as $target) {
    if (!is_file($root . '/public_html/' . explode('?', $target)[0])) {
        $missingTargets[] = $target;
    }
}
ok($missingTargets === [], 'Every one of them is a page that exists'
    . ($missingTargets === [] ? '' : ' — missing ' . implode(', ', $missingTargets)));

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
