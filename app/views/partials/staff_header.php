<?php
$pageTitle = $pageTitle ?? 'Staff Portal';
$bodyClass = 'admin-layout admin-workspace staff-portal';
$currentUser = current_user();
$headerStaffCompany = !empty($currentUser['company_id']) ? company_by_id((int) $currentUser['company_id']) : null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(app_name()) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=20260704">
</head>
<body class="<?= e($bodyClass) ?>">
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="brand brand-admin" href="<?= e(url('staff/index.php')) ?>">
            <span class="brand-mark">MB</span>
            <span>
                <strong>Staff Portal</strong>
                <small><?= e(app_name()) ?></small>
            </span>
        </a>
        <?php if ($headerStaffCompany): ?>
            <div class="admin-sidebar-context">
                <span>Current company</span>
                <strong><?= e($headerStaffCompany['name'] ?? 'Company') ?></strong>
            </div>
        <?php endif; ?>
        <nav class="admin-nav">
            <a href="<?= e(url('staff/index.php?view=home')) ?>"><?= icon('dashboard') ?>Dashboard</a>
            <a href="<?= e(url('staff/index.php?view=clients')) ?>"><?= icon('clients') ?>My clients</a>
            <a href="<?= e(url('staff/index.php?view=tasks')) ?>"><?= icon('tasks') ?>Client tasks</a>
            <a href="<?= e(url('admin/documents.php?view=requests')) ?>"><?= icon('documents') ?>Documents</a>
            <a href="<?= e(url('admin/compliance.php?view=deadlines')) ?>"><?= icon('compliance') ?>Compliance</a>
            <a href="<?= e(url('admin/messages.php')) ?>"><?= icon('messages') ?>Messages</a>
            <a href="<?= e(url('admin/tickets.php')) ?>"><?= icon('tickets') ?>Tickets</a>
            <a href="<?= e(url('admin/hr.php?view=attendance')) ?>"><?= icon('attendance') ?>Attendance</a>
            <a href="<?= e(url('admin/hr.php?view=leave')) ?>"><?= icon('leave') ?>Leave</a>
            <a href="<?= e(url('admin/hr.php?view=timesheets')) ?>"><?= icon('timesheets') ?>Timesheets</a>
            <a href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?>Logout</a>
        </nav>
    </aside>
    <section class="admin-main">
        <header class="admin-topbar">
            <div class="admin-topbar-title">
                <h1><?= e($pageTitle) ?></h1>
                <p>Signed in as <?= e($currentUser['name'] ?? 'Staff') ?></p>
            </div>
            <form method="get" action="<?= e(url('search.php')) ?>" class="admin-topbar-search" role="search">
                <label class="sr-only" for="staff-global-search">Search</label>
                <input id="staff-global-search" type="search" name="q" placeholder="Search tasks, clients, documents..." value="<?= e((string) ($_GET['q'] ?? '')) ?>">
                <button type="submit" class="admin-icon-button" aria-label="Search" title="Search"><?= icon('reports') ?></button>
            </form>
            <?php if ($headerStaffCompany): ?>
                <div class="admin-context-chip" aria-label="Current staff context">
                    <span class="admin-context-icon"><?= icon('staff') ?></span>
                    <span>
                        <strong>Staff portal</strong>
                        <em><?= e($headerStaffCompany['name'] ?? 'Company') ?></em>
                    </span>
                </div>
            <?php endif; ?>
            <div class="admin-topbar-actions">
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
