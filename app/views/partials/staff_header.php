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
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%230e2240'/%3E%3Ctext x='16' y='21.5' font-family='Georgia,serif' font-size='13' font-weight='700' fill='%23e3a13c' text-anchor='middle'%3EMB%3C/text%3E%3C/svg%3E">
    <link rel="stylesheet" href="/assets/css/style.css?v=20260711-portal">
    <link rel="stylesheet" href="/assets/css/portal.css?v=20260711b">
</head>
<body class="<?= e($bodyClass) ?>" data-date-mode="<?= e(date_mode()) ?>">
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="brand brand-admin" href="<?= e(url('staff/index.php')) ?>">
            <span class="brand-mark">MB</span>
            <span>
                <strong>MB World</strong>
                <small>Staff Portal</small>
            </span>
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
            <span class="admin-nav-group">Manage Clients</span>
            <a href="<?= e(url('admin/manage-clients.php')) ?>"><?= icon('clients') ?>Client Accounting</a>
            <span class="admin-nav-group">Documents &amp; Compliance</span>
            <a href="<?= e(url('admin/documents.php?view=requests')) ?>"><?= icon('documents') ?>Documents</a>
            <a href="<?= e(url('admin/compliance.php?view=deadlines')) ?>"><?= icon('compliance') ?>Compliance</a>
            <span class="admin-nav-group">Communication</span>
            <a href="<?= e(url('admin/messages.php')) ?>"><?= icon('messages') ?>Messages</a>
            <a href="<?= e(url('admin/tickets.php')) ?>"><?= icon('tickets') ?>Tickets</a>
            <span class="admin-nav-group">HR &amp; Time</span>
            <a href="<?= e(url('admin/hr.php?view=attendance')) ?>"><?= icon('attendance') ?>Attendance</a>
            <a href="<?= e(url('admin/hr.php?view=leave')) ?>"><?= icon('leave') ?>Leave</a>
            <a href="<?= e(url('admin/hr.php?view=timesheets')) ?>"><?= icon('timesheets') ?>Timesheets</a>
            <span class="admin-nav-group">System</span>
            <a href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?>Logout</a>
        </nav>
    </aside>
    <section class="admin-main">
        <header class="admin-topbar">
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
                <button type="button" class="theme-toggle-link admin-icon-button" data-theme-toggle aria-label="Switch to dark mode" title="Switch to dark mode">
                    <?= icon('theme') ?>
                    <span class="sr-only" data-theme-toggle-label>Dark mode</span>
                </button>
            </div>
        </header>
        <div class="admin-content">
            <?php if ($message = flash('success')): ?>
                <div class="notice success"><?= e($message) ?></div>
            <?php endif; ?>
            <?php if ($message = flash('error')): ?>
                <div class="notice error"><?= e($message) ?></div>
            <?php endif; ?>
