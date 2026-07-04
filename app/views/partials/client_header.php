<?php
$pageTitle = $pageTitle ?? 'Client Portal';
$bodyClass = 'admin-layout admin-workspace client-portal';
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
    <link rel="stylesheet" href="/assets/css/style.css?v=20260704">
</head>
<body class="<?= e($bodyClass) ?>">
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="brand brand-admin" href="<?= e(url('dashboard.php')) ?>">
            <span class="brand-mark">MB</span>
            <span>
                <strong>Client Portal</strong>
                <small><?= e(app_name()) ?></small>
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
            <a href="<?= e(url('dashboard.php?view=home')) ?>"><?= icon('dashboard') ?>Dashboard</a>
            <a href="<?= e(url('dashboard.php?view=tasks')) ?>"><?= icon('tasks') ?>My tasks</a>
            <a href="<?= e(url('dashboard.php?view=contracts')) ?>"><?= icon('contracts') ?>My contracts</a>
            <a href="<?= e(url('dashboard.php?view=invoices')) ?>"><?= icon('invoices') ?>My invoices</a>
            <a href="<?= e(url('dashboard.php?view=documents')) ?>"><?= icon('documents') ?>My documents</a>
            <a href="<?= e(url('dashboard.php?view=document-requests')) ?>"><?= icon('documents') ?>Document requests</a>
            <a href="<?= e(url('dashboard.php?view=compliance')) ?>"><?= icon('compliance') ?>Compliance calendar</a>
            <a href="<?= e(url('dashboard.php?view=messages')) ?>"><?= icon('messages') ?>Messages</a>
            <a href="<?= e(url('dashboard.php?view=tickets')) ?>"><?= icon('tickets') ?>Support tickets</a>
            <a href="<?= e(url('contact.php')) ?>"><?= icon('contact') ?>Contact support</a>
            <a href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?>Logout</a>
        </nav>
    </aside>
    <section class="admin-main">
        <header class="admin-topbar">
            <div class="admin-topbar-title">
                <h1><?= e($pageTitle) ?></h1>
                <p>Signed in as <?= e($currentUser['name'] ?? 'Client') ?></p>
            </div>
            <?php if ($headerClientProfile): ?>
                <div class="admin-context-chip" aria-label="Your client context">
                    <span class="admin-context-icon"><?= icon('clients') ?></span>
                    <span>
                        <strong>Client portal</strong>
                        <em><?= e($headerClientProfile['organization_name']) ?></em>
                        <small><?= e($headerCompany['name'] ?? 'Service provider') ?></small>
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
