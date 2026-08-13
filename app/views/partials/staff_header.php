<?php
$pageTitle = $pageTitle ?? 'Staff Portal';
$pageSubtitle = $pageSubtitle ?? '';
$bodyClass = trim('admin-layout admin-workspace staff-portal ' . ($bodyClass ?? ''));
$currentUser = current_user();
$headerStaffCompany = !empty($currentUser['company_id']) ? company_by_id((int) $currentUser['company_id']) : null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(app_name()) ?></title>
    <meta name="theme-color" content="#064e3b">
    <?php require __DIR__ . '/pwa_head.php'; ?>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="apple-touch-icon" href="/assets/img/favicon.svg">
    <link rel="mask-icon" href="/assets/img/favicon.svg" color="#064e3b">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/portal.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/theme-brown.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/theme-sahakari-green.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/text-contrast-only.css')) ?>">
    <?php /* Last on purpose: the 2026 appearance layer restates the tokens the
             four sheets above each define, so it must have the final word. */ ?>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/mbworld-2026.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/design-system.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>" data-date-mode="<?= e(date_mode()) ?>">
<?php require __DIR__ . '/sidebar_boot.php'; ?>
<div class="admin-shell">
    <aside class="admin-sidebar" id="adminSidebar">
        <a class="brand brand-admin" href="<?= e(url('staff/index.php')) ?>">
            <?= brand_logo('light', 'mbw-logo mbw-logo-sidebar') ?>
            <span class="brand-admin-sub">Staff Portal</span>
        </a>
        <?php if ($headerStaffCompany): ?>
            <div class="admin-sidebar-context">
                <span>Current company</span>
                <strong><?= e($headerStaffCompany['name'] ?? 'Company') ?></strong>
            </div>
        <?php endif; ?>
        <nav class="admin-nav">
            <span class="admin-nav-group">Workspace</span>
            <a href="<?= e(url('staff/index.php?view=home')) ?>"><?= icon('dashboard') ?>Dashboard</a>
            <a href="<?= e(url('staff/index.php?view=clients')) ?>"><?= icon('clients') ?>My Clients</a>
            <a href="<?= e(url('staff/index.php?view=tasks')) ?>"><?= icon('tasks') ?>Client Tasks</a>
            <?php if (user_can_do('clients', 'view')): ?>
                <span class="admin-nav-group">Manage Clients</span>
                <a href="<?= e(url('admin/manage-clients.php')) ?>"><?= icon('clients') ?>Client Accounting</a>
            <?php endif; ?>
            <?php if (user_can_do('documents', 'view') || user_can_do('compliance', 'view')): ?>
                <span class="admin-nav-group">Documents &amp; Compliance</span>
                <?php if (user_can_do('documents', 'view')): ?>
                    <a href="<?= e(url('admin/documents.php?view=requests')) ?>"><?= icon('documents') ?>Documents</a>
                <?php endif; ?>
                <?php if (user_can_do('compliance', 'view')): ?>
                    <a href="<?= e(url('admin/compliance.php?view=deadlines')) ?>"><?= icon('compliance') ?>Compliance</a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (user_can_do('messages', 'view') || user_can_do('tickets', 'view')): ?>
                <span class="admin-nav-group">Communication</span>
                <?php if (user_can_do('messages', 'view')): ?>
                    <a href="<?= e(url('admin/messages.php')) ?>"><?= icon('messages') ?>Messages</a>
                <?php endif; ?>
                <?php if (user_can_do('tickets', 'view')): ?>
                    <a href="<?= e(url('admin/tickets.php')) ?>"><?= icon('tickets') ?>Tickets</a>
                <?php endif; ?>
            <?php endif; ?>
            <span class="admin-nav-group">HR &amp; Time</span>
            <a href="<?= e(url('admin/hr.php?view=attendance')) ?>"><?= icon('attendance') ?>Attendance</a>
            <a href="<?= e(url('admin/hr.php?view=leave')) ?>"><?= icon('leave') ?>Leave</a>
            <a href="<?= e(url('admin/hr.php?view=timesheets')) ?>"><?= icon('timesheets') ?>Timesheets</a>
            <a href="<?= e(url('my-payslips.php')) ?>"><?= icon('wallet') ?>My Payslips</a>
            <span class="admin-nav-group">System</span>
            <a href="<?= e(url('staff/profile.php')) ?>"><?= icon('profile') ?>My Profile</a>
            <a href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?>Logout</a>
        </nav>
    </aside>
    <section class="admin-main">
        <header class="admin-topbar">
            <?php require __DIR__ . '/sidebar_toggle.php'; ?>
            <div class="admin-topbar-title">
                <h1><?= e($pageTitle) ?></h1>
                <p><?= $pageSubtitle !== '' ? e($pageSubtitle) : 'Signed in as ' . e($currentUser['name'] ?? 'Staff') ?></p>
            </div>
            <form method="get" action="<?= e(url('search.php')) ?>" class="admin-topbar-search" role="search">
                <span class="mbw-search-glyph"><?= icon('search') ?></span>
                <label class="sr-only" for="staff-global-search">Search</label>
                <input id="staff-global-search" type="search" name="q" placeholder="Search tasks, clients, documents..." value="<?= e((string) ($_GET['q'] ?? '')) ?>">
                <button type="submit" aria-label="Search" title="Search"><?= icon('search') ?></button>
            </form>
            <?php if ($headerStaffCompany): ?>
                <div class="admin-context-chip" aria-label="Current staff context">
                    <span class="admin-context-icon"><?= icon('staff') ?></span>
                    <span>
                        <strong><?= e($headerStaffCompany['name'] ?? 'Company') ?></strong>
                        <small>Staff portal</small>
                    </span>
                </div>
            <?php endif; ?>
            <span class="mbw-pill tone-amber" title="Bikram Sambat (today)" style="align-self:center"><?= e(bs_format(date('Y-m-d'))) ?> BS</span>
            <div class="admin-topbar-actions">
                <form method="post" action="<?= e(url('set-date-mode.php')) ?>" style="align-self:center;margin:0">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
                    <select name="date_mode" onchange="this.form.submit()" title="Date display: English / Nepali" style="min-height:40px;border:1px solid var(--mbw-border);border-radius:10px;background:var(--mbw-card);color:var(--mbw-heading);font-size:12px;font-weight:700;padding:4px 8px;cursor:pointer">
                        <option value="ad" <?= date_mode() === 'ad' ? 'selected' : '' ?>>AD</option>
                        <option value="bs" <?= date_mode() === 'bs' ? 'selected' : '' ?>>BS</option>
                        <option value="both" <?= date_mode() === 'both' ? 'selected' : '' ?>>AD+BS</option>
                    </select>
                </form>

                <a class="admin-icon-button" href="<?= e(url('admin/compliance.php?view=deadlines')) ?>" aria-label="Compliance calendar" title="Compliance calendar"><?= icon('calendar') ?></a>
                <?php include __DIR__ . '/attention_bell.php'; ?>
                <button type="button" class="theme-toggle-link admin-icon-button" data-theme-toggle aria-label="Switch to dark mode" title="Switch to dark mode">
                    <span data-theme-icon="dark"><?= icon('theme') ?></span>
                    <span data-theme-icon="light" hidden><?= icon('sun') ?></span>
                    <span class="sr-only" data-theme-toggle-label>Dark mode</span>
                </button>
            </div>
        </header>
        <div class="admin-content">
            <?php if ($message = flash('success')): ?>
                <div class="notice success flash-notice"><?= e($message) ?></div>
            <?php endif; ?>
            <?php if ($message = flash('error')): ?>
                <div class="notice error flash-notice"><?= e($message) ?></div>
            <?php endif; ?>
