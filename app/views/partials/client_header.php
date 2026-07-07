<?php
$pageTitle = $pageTitle ?? 'Client Portal';
$pageSubtitle = $pageSubtitle ?? '';
$bodyClass = trim('admin-layout admin-workspace client-portal ' . ($bodyClass ?? ''));
$currentUser = current_user();
$headerClientProfile = $headerClientProfile ?? null;
$headerCompany = $headerClientProfile && !empty($headerClientProfile['company_id'])
    ? company_by_id((int) $headerClientProfile['company_id'])
    : null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(app_name()) ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%230e2240'/%3E%3Ctext x='16' y='21.5' font-family='Georgia,serif' font-size='13' font-weight='700' fill='%23e3a13c' text-anchor='middle'%3EMB%3C/text%3E%3C/svg%3E">
    <link rel="stylesheet" href="/assets/css/style.css?v=20260706-portal">
    <link rel="stylesheet" href="/assets/css/portal.css?v=20260707c">
</head>
<body class="<?= e($bodyClass) ?>">
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="brand brand-admin" href="<?= e(url('dashboard.php')) ?>">
            <span class="brand-mark">MB</span>
            <span>
                <strong>MB World</strong>
                <small>Client Portal</small>
            </span>
        </a>
        <?php if ($headerClientProfile): ?>
            <div class="admin-sidebar-context">
                <span>Your organization</span>
                <strong><?= e($headerClientProfile['organization_name']) ?></strong>
                <small><?= e($headerClientProfile['client_code'] ?? 'No client code') ?></small>
            </div>
        <?php endif; ?>
        <nav class="admin-nav">
            <span class="admin-nav-group">Overview</span>
            <a href="<?= e(url('dashboard.php?view=home')) ?>"><?= icon('dashboard') ?>Dashboard</a>
            <a href="<?= e(url('dashboard.php?view=tasks')) ?>"><?= icon('tasks') ?>My Tasks</a>
            <span class="admin-nav-group">My Accounting</span>
            <a href="<?= e(url('dashboard.php?view=accounting')) ?>"><?= icon('accounting') ?>Accounting &amp; Approvals</a>
            <span class="admin-nav-group">Engagements</span>
            <a href="<?= e(url('dashboard.php?view=contracts')) ?>"><?= icon('contracts') ?>My Contracts</a>
            <a href="<?= e(url('dashboard.php?view=invoices')) ?>"><?= icon('invoices') ?>My Invoices</a>
            <span class="admin-nav-group">Documents &amp; Compliance</span>
            <a href="<?= e(url('dashboard.php?view=documents')) ?>"><?= icon('documents') ?>My Documents</a>
            <a href="<?= e(url('dashboard.php?view=document-requests')) ?>"><?= icon('contracts') ?>Document Requests</a>
            <a href="<?= e(url('dashboard.php?view=compliance')) ?>"><?= icon('compliance') ?>Compliance Calendar</a>
            <span class="admin-nav-group">Support</span>
            <a href="<?= e(url('dashboard.php?view=messages')) ?>"><?= icon('messages') ?>Messages</a>
            <a href="<?= e(url('dashboard.php?view=tickets')) ?>"><?= icon('tickets') ?>Support Tickets</a>
            <a href="<?= e(url('contact.php')) ?>"><?= icon('contact') ?>Contact Support</a>
            <a href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?>Logout</a>
        </nav>
    </aside>
    <section class="admin-main">
        <header class="admin-topbar">
            <div class="admin-topbar-title">
                <h1><?= e($pageTitle) ?></h1>
                <p><?= $pageSubtitle !== '' ? e($pageSubtitle) : 'Signed in as ' . e($currentUser['name'] ?? 'Client') ?></p>
            </div>
            <?php if ($headerClientProfile): ?>
                <div class="admin-context-chip" aria-label="Your client context">
                    <span class="admin-context-icon"><?= icon('clients') ?></span>
                    <span>
                        <strong><?= e($headerClientProfile['organization_name']) ?></strong>
                        <small><?= e($headerCompany['name'] ?? 'Service provider') ?></small>
                    </span>
                </div>
            <?php endif; ?>
            <span class="mbw-pill tone-amber" title="Bikram Sambat (today)" style="align-self:center"><?= e(bs_format(date('Y-m-d'))) ?> BS</span>
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
