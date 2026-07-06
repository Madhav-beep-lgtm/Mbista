<?php
$pageTitle = $pageTitle ?? app_name();
$bodyClass = $bodyClass ?? '';
$currentUser = current_user();
$headerSupportPhone = (string) setting('support_phone', '');
$headerSupportEmail = (string) setting('support_email', '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(app_name()) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=20260706">
</head>
<body class="<?= e($bodyClass) ?>">
<?php if ($headerSupportPhone !== '' || $headerSupportEmail !== ''): ?>
    <div class="utility-bar">
        <div class="container utility-bar-inner">
            <span><?= e(setting('site_tagline', '')) ?></span>
            <span class="utility-bar-contacts">
                <?php if ($headerSupportPhone !== ''): ?>
                    <a href="tel:<?= e(preg_replace('/[^+0-9]/', '', $headerSupportPhone)) ?>"><?= icon('phone') ?><?= e($headerSupportPhone) ?></a>
                <?php endif; ?>
                <?php if ($headerSupportEmail !== ''): ?>
                    <a href="mailto:<?= e($headerSupportEmail) ?>"><?= icon('contact') ?><?= e($headerSupportEmail) ?></a>
                <?php endif; ?>
            </span>
        </div>
    </div>
<?php endif; ?>
<header class="site-header" data-site-header>
    <div class="container nav-wrap">
        <a class="brand" href="<?= e(url('index.php')) ?>">
            <span class="brand-mark">MB</span>
            <span class="brand-text">
                <strong><?= e(setting('brand_name', 'M.Bista & Associates')) ?></strong>
                <span class="brand-designation"><?= e(setting('brand_designation', 'Chartered Accountants')) ?></span>
                <small class="brand-tagline"><?= e(setting('brand_tagline', 'Delivering Clarity. Enabling Confidence.')) ?></small>
            </span>
        </a>
        <button type="button" class="mobile-menu-button" data-mobile-menu-open aria-label="Open menu" aria-expanded="false">
            <?= icon('menu') ?>Menu
        </button>
        <form method="get" action="<?= e(url('search.php')) ?>" class="site-header-search" role="search">
            <label class="sr-only" for="site-global-search">Search</label>
            <input id="site-global-search" type="search" name="q" placeholder="Search the site" value="<?= e((string) ($_GET['q'] ?? '')) ?>">
            <button type="submit" aria-label="Search" title="Search"><?= icon('reports') ?></button>
        </form>
        <nav class="nav main-navigation" data-main-nav aria-label="Main navigation">
            <div class="mobile-nav-head">
                <strong><?= e(app_name()) ?></strong>
                <button type="button" class="mobile-menu-close" data-mobile-menu-close aria-label="Close menu">
                    <?= icon('close') ?>
                </button>
            </div>
            <?php if (!$currentUser): ?>
                <a href="<?= e(url('index.php')) ?>"><?= icon('home') ?>Home</a>
                <div class="nav-item has-dropdown">
                    <a class="nav-main-link" href="<?= e(url('about/index.php')) ?>"><?= icon('about') ?>About Us</a>
                    <button type="button" class="dropdown-toggle" aria-label="Open About menu" aria-expanded="false">
                        <span class="dropdown-arrow"><?= icon('chevron') ?></span>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a href="<?= e(url('about/group-overview.php')) ?>">Group Overview</a></li>
                        <li><a href="<?= e(url('about/mbista-associates.php')) ?>">M. Bista &amp; Associates</a></li>
                        <li><a href="<?= e(url('about/altiora-global-holding.php')) ?>">Altiora Global Holding</a></li>
                        <li><a href="<?= e(url('about/group-companies.php')) ?>">Group Companies</a></li>
                        <li><a href="<?= e(url('about/mission-vision.php')) ?>">Mission and Vision</a></li>
                        <li><a href="<?= e(url('about/corporate-values.php')) ?>">Corporate Values</a></li>
                    </ul>
                </div>
                <div class="nav-item has-dropdown">
                    <a class="nav-main-link" href="<?= e(url('team/index.php')) ?>"><?= icon('teams') ?>Our Team</a>
                    <button type="button" class="dropdown-toggle" aria-label="Open team menu" aria-expanded="false">
                        <span class="dropdown-arrow"><?= icon('chevron') ?></span>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a href="<?= e(url('team/index.php')) ?>">Team Overview</a></li>
                        <li><a href="<?= e(url('team/leadership.php')) ?>">Leadership</a></li>
                        <li><a href="<?= e(url('team/management.php')) ?>">Management Team</a></li>
                        <li><a href="<?= e(url('team/professional-team.php')) ?>">Professional Team</a></li>
                        <li><a href="<?= e(url('team/staff-directory.php')) ?>">Staff Directory</a></li>
                    </ul>
                </div>
                <div class="nav-item has-dropdown">
                    <a class="nav-main-link" href="<?= e(url('services/index.php')) ?>"><?= icon('services') ?>Our Services</a>
                    <button type="button" class="dropdown-toggle" aria-label="Open services menu" aria-expanded="false">
                        <span class="dropdown-arrow"><?= icon('chevron') ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-wide">
                        <li class="dropdown-heading">M. Bista &amp; Associates</li>
                        <li><a href="<?= e(url('services/audit-assurance.php')) ?>">Audit &amp; Assurance</a></li>
                        <li><a href="<?= e(url('services/taxation.php')) ?>">Taxation</a></li>
                        <li><a href="<?= e(url('services/accounting-advisory.php')) ?>">Accounting Advisory</a></li>
                        <li><a href="<?= e(url('services/internal-audit.php')) ?>">Internal Audit</a></li>
                        <li class="dropdown-heading">Consulting &amp; Training</li>
                        <li><a href="<?= e(url('services/business-consulting.php')) ?>">Business Consulting</a></li>
                        <li><a href="<?= e(url('services/accounting-outsourcing.php')) ?>">Accounting Outsourcing</a></li>
                        <li><a href="<?= e(url('services/training-advisory.php')) ?>">Training &amp; Advisory</a></li>
                        <li class="dropdown-heading">Education Services</li>
                        <li><a href="<?= e(url('services/education-consulting.php')) ?>">Education Consulting</a></li>
                    </ul>
                </div>
                <div class="nav-item has-dropdown">
                    <a class="nav-main-link" href="<?= e(url('insights/index.php')) ?>"><?= icon('insights') ?>Insights</a>
                    <button type="button" class="dropdown-toggle" aria-label="Open insights menu" aria-expanded="false">
                        <span class="dropdown-arrow"><?= icon('chevron') ?></span>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a href="<?= e(url('insights/articles.php')) ?>">Articles</a></li>
                        <li><a href="<?= e(url('insights/tax-updates.php')) ?>">Tax Updates</a></li>
                        <li><a href="<?= e(url('insights/audit-insights.php')) ?>">Audit Insights</a></li>
                        <li><a href="<?= e(url('insights/accounting-updates.php')) ?>">Accounting Updates</a></li>
                        <li><a href="<?= e(url('insights/publications.php')) ?>">Publications</a></li>
                        <li><a href="<?= e(url('insights/news-events.php')) ?>">News and Events</a></li>
                        <li><a href="<?= e(url('insights/downloads.php')) ?>">Downloads</a></li>
                    </ul>
                </div>
                <div class="nav-item has-dropdown">
                    <a class="nav-main-link" href="<?= e(url('contact/index.php')) ?>"><?= icon('contact') ?>Contact</a>
                    <button type="button" class="dropdown-toggle" aria-label="Open contact menu" aria-expanded="false">
                        <span class="dropdown-arrow"><?= icon('chevron') ?></span>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a href="<?= e(url('contact/index.php')) ?>">Contact Overview</a></li>
                        <li><a href="<?= e(url('contact/office-locations.php')) ?>">Office Locations</a></li>
                        <li><a href="<?= e(url('contact/general-enquiry.php')) ?>">General Enquiry</a></li>
                        <li><a href="<?= e(url('contact/request-consultation.php')) ?>">Request a Consultation</a></li>
                        <li><a href="<?= e(url('contact/careers.php')) ?>">Careers</a></li>
                    </ul>
                </div>
            <?php endif; ?>
            <button type="button" class="theme-toggle-link" data-theme-toggle aria-label="Switch to dark mode" title="Switch to dark mode">
                <?= icon('theme') ?>
                <span data-theme-toggle-label>Dark mode</span>
            </button>
            <?php if ($currentUser): ?>
                <?php if (($currentUser['role'] ?? 'customer') === 'admin'): ?>
                    <a href="<?= e(url('admin/workspace.php?view=home')) ?>"><?= icon('admin') ?>Admin workspace</a>
                <?php elseif (($currentUser['role'] ?? 'customer') === 'staff'): ?>
                    <a href="<?= e(url('staff/index.php')) ?>"><?= icon('staff') ?>Staff portal</a>
                <?php else: ?>
                    <a href="<?= e(url('dashboard.php')) ?>"><?= icon('dashboard') ?>Client dashboard</a>
                <?php endif; ?>
                <a class="nav-button" href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?>Logout</a>
            <?php else: ?>
                <a class="nav-button" href="<?= e(url('login.php')) ?>"><?= icon('login') ?>Login</a>
            <?php endif; ?>
        </nav>
        <div class="mobile-nav-overlay" data-mobile-menu-overlay hidden></div>
    </div>
</header>
<main>
    <?php if ($message = flash('success')): ?>
        <div class="container">
            <div class="notice success"><?= e($message) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($message = flash('error')): ?>
        <div class="container">
            <div class="notice error"><?= e($message) ?></div>
        </div>
    <?php endif; ?>
