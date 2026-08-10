<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

require_admin();

if (company_pin_verified(current_company_id()) && current_company()) {
    redirect('admin/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $companyId = (int) ($_POST['company_id'] ?? 0);
    handle_company_switch($companyId, 'portal.php');
}

$authorizedCompanies = authorized_companies();
$authorizedById = [];
foreach ($authorizedCompanies as $authorizedCompany) {
    $authorizedById[(int) $authorizedCompany['id']] = $authorizedCompany;
}
$canOpen = static fn (?array $company): bool => $company !== null && isset($authorizedById[(int) $company['id']]);

$companies = array_values(array_filter($authorizedCompanies, static fn (array $c): bool => (int) ($c['is_client_company'] ?? 0) === 0));

$mbistaCompany = company_by_code('MBAACA');
$altioraCompany = company_by_code('AGHPL');
if (!$canOpen($mbistaCompany)) {
    $mbistaCompany = null;
}
if (!$canOpen($altioraCompany)) {
    $altioraCompany = null;
}

if (count($companies) === 1 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $only = $companies[0];
    if ((string) ($only['code'] ?? '') === 'MBAACA' || company_pin_is_set((int) $only['id'])) {
        // Fall through
    }
}

include __DIR__ . '/../app/views/partials/header.php';
?>

<section class="section portal-selector-page">
    <div class="container">
        <div class="hero-panel">
            <div class="hero-grid">
                <div>
                    <?= brand_logo('dark', 'mbw-logo mbw-logo-portal') ?>
                    <div class="kicker">Superadmin workflow</div>
                    <h1>Open the M.Bista superadmin portal, then manage Altiora and its subsidiaries.</h1>
                    <p>After selecting a company, you will enter a 4-digit admin PIN before opening that company's management portal.</p>
                </div>
            </div>
        </div>

        <?php if ($mbistaCompany || $altioraCompany): ?>
            <div class="admin-grid portal-3d-grid" style="margin-top: 32px;">
                <?php if ($mbistaCompany): ?>
                    <form method="post" class="card portal-card-3d featured">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="company_id" value="<?= e((int) $mbistaCompany['id']) ?>">

                        <div class="portal-3d-header">
                            <svg class="portal-icon-3d" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <!-- Crown Icon (Superadmin) -->
                                <path d="M32 8L42 22H58C59.1 22 60 22.9 60 24C60 25.1 59.1 26 58 26H56L52 50C51.5 52.5 49.3 54 47 54H17C14.7 54 12.5 52.5 12 50L8 26H6C4.9 26 4 25.1 4 24C4 22.9 4.9 22 6 22H22L32 8Z" fill="#d4af37"/>
                                <circle cx="32" cy="12" r="3" fill="#27a88b"/>
                                <circle cx="22" cy="18" r="2.5" fill="#27a88b"/>
                                <circle cx="42" cy="18" r="2.5" fill="#27a88b"/>
                            </svg>
                            <div class="badge badge-gold">SUPERADMIN PORTAL</div>
                        </div>

                        <h3 class="portal-card-title"><?= e($mbistaCompany['name']) ?></h3>
                        <p class="portal-card-subtitle"><?= e($mbistaCompany['code']) ?></p>
                        <p class="portal-card-description">Manage the M.Bista and Associates workspace, then open Altiora Global Holdings from its dashboard.</p>

                        <div class="status-row">
                            <span class="status-badge <?= company_pin_is_set((int) $mbistaCompany['id']) ? 'active' : 'pending' ?>">
                                <span class="status-dot"></span>
                                <?= company_pin_is_set((int) $mbistaCompany['id']) ? 'PIN configured' : 'PIN required' ?>
                            </span>
                        </div>

                        <button type="submit" class="button button-primary" style="width: 100%;">
                            <span>Open M.Bista Portal</span>
                            <svg class="button-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5 12h14M12 5l7 7-7 7"/>
                            </svg>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($altioraCompany): ?>
                    <form method="post" class="card portal-card-3d featured">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="company_id" value="<?= e((int) $altioraCompany['id']) ?>">

                        <div class="portal-3d-header">
                            <svg class="portal-icon-3d" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <!-- Building Icon (Parent Company) -->
                                <rect x="12" y="14" width="40" height="42" fill="#2b7dd8" stroke="#1a4d96" stroke-width="2"/>
                                <rect x="18" y="20" width="6" height="8" fill="#e8f2f9" stroke="#2b7dd8" stroke-width="1"/>
                                <rect x="28" y="20" width="6" height="8" fill="#e8f2f9" stroke="#2b7dd8" stroke-width="1"/>
                                <rect x="38" y="20" width="6" height="8" fill="#e8f2f9" stroke="#2b7dd8" stroke-width="1"/>
                                <rect x="18" y="32" width="6" height="8" fill="#e8f2f9" stroke="#2b7dd8" stroke-width="1"/>
                                <rect x="28" y="32" width="6" height="8" fill="#e8f2f9" stroke="#2b7dd8" stroke-width="1"/>
                                <rect x="38" y="32" width="6" height="8" fill="#e8f2f9" stroke="#2b7dd8" stroke-width="1"/>
                                <path d="M32 8L22 14H42Z" fill="#2b7dd8" stroke="#1a4d96" stroke-width="2"/>
                            </svg>
                            <div class="badge badge-blue">PARENT COMPANY</div>
                        </div>

                        <h3 class="portal-card-title"><?= e($altioraCompany['name']) ?></h3>
                        <p class="portal-card-subtitle"><?= e($altioraCompany['code']) ?></p>
                        <p class="portal-card-description">Open the parent company admin page and manage subsidiary companies including EBCPL and MBTAS.</p>

                        <div class="status-row">
                            <span class="status-badge <?= company_pin_is_set((int) $altioraCompany['id']) ? 'active' : 'pending' ?>">
                                <span class="status-dot"></span>
                                <?= company_pin_is_set((int) $altioraCompany['id']) ? 'PIN configured' : 'PIN required' ?>
                            </span>
                        </div>

                        <button type="submit" class="button button-primary" style="width: 100%;">
                            <span>Open Altiora Global</span>
                            <svg class="button-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5 12h14M12 5l7 7-7 7"/>
                            </svg>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($companies)): ?>
            <div class="admin-grid portal-3d-grid" style="margin-top: 32px;">
                <?php
                $featuredIds = array_filter([(int) ($mbistaCompany['id'] ?? 0), (int) ($altioraCompany['id'] ?? 0)]);
                foreach ($companies as $company):
                    if (in_array((int) $company['id'], $featuredIds, true)) {
                        continue;
                    }
                    $isSubsidiary = !empty($company['parent_company_name']);
                    ?>
                    <form method="post" class="card portal-card-3d">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="company_id" value="<?= e((int) $company['id']) ?>">

                        <div class="portal-3d-header">
                            <svg class="portal-icon-3d" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <!-- Factory Icon (Subsidiary) -->
                                <rect x="10" y="30" width="44" height="26" fill="#8b5cf6" stroke="#6d28d9" stroke-width="2"/>
                                <rect x="16" y="18" width="8" height="12" fill="#8b5cf6" stroke="#6d28d9" stroke-width="2"/>
                                <rect x="28" y="12" width="8" height="18" fill="#8b5cf6" stroke="#6d28d9" stroke-width="2"/>
                                <rect x="40" y="20" width="8" height="10" fill="#8b5cf6" stroke="#6d28d9" stroke-width="2"/>
                                <rect x="18" y="36" width="4" height="14" fill="#f0e8f9" stroke="#8b5cf6" stroke-width="1"/>
                                <rect x="30" y="36" width="4" height="14" fill="#f0e8f9" stroke="#8b5cf6" stroke-width="1"/>
                                <rect x="42" y="36" width="4" height="14" fill="#f0e8f9" stroke="#8b5cf6" stroke-width="1"/>
                            </svg>
                            <div class="badge badge-purple"><?= $isSubsidiary ? 'SUBSIDIARY' : 'COMPANY' ?></div>
                        </div>

                        <h3 class="portal-card-title"><?= e($company['name']) ?></h3>
                        <p class="portal-card-subtitle"><?= e($company['code']) ?></p>
                        <p class="portal-card-description">
                            <?php if ($isSubsidiary): ?>
                                Part of <?= e($company['parent_company_name']) ?> group
                            <?php else: ?>
                                Independent or parent company
                            <?php endif; ?>
                        </p>

                        <div class="status-row">
                            <span class="status-badge <?= company_pin_is_set((int) $company['id']) ? 'active' : 'pending' ?>">
                                <span class="status-dot"></span>
                                <?= company_pin_is_set((int) $company['id']) ? 'PIN configured' : 'PIN required' ?>
                            </span>
                        </div>

                        <button type="submit" class="button button-secondary" style="width: 100%;">
                            <span>Open Company Portal</span>
                            <svg class="button-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5 12h14M12 5l7 7-7 7"/>
                            </svg>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$mbistaCompany && !$altioraCompany && empty($companies)): ?>
            <div class="card portal-card-3d" style="margin-top: 24px; text-align: center;">
                <h3>No organizations assigned yet</h3>
                <p>Your account is not linked to any company portal. Ask your administrator to grant you access.</p>
                <p><a class="button secondary" href="<?= e(url('logout.php')) ?>" style="margin-top: 16px;">Log out</a></p>
            </div>
        <?php endif; ?>
    </div>
</section>

<style>
.portal-selector-page {
    background: var(--c-canvas);
    color: var(--c-ink);
}

.portal-3d-grid {
    perspective: 1000px;
}

.portal-card-3d {
    position: relative;
    border: none !important;
    background: var(--c-surface) !important;
    border-top: none !important;
    padding: 28px !important;
    border-radius: 16px !important;
    box-shadow: var(--sh-2),
                inset 0 1px 0 rgba(255, 255, 255, 0.6) !important;
    transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    transform: translateZ(0);
    backdrop-filter: blur(10px);
}

.portal-card-3d:hover {
    transform: translateY(-8px) translateZ(20px);
    box-shadow: var(--sh-3),
                inset 0 1px 0 rgba(255, 255, 255, 0.8) !important;
}

.portal-card-3d.featured {
    border: 1px solid var(--c-gold-line) !important;
    background: var(--c-gold-tint) !important;
}

.portal-card-3d.featured:hover {
    box-shadow: var(--sh-gold),
                0 4px 12px rgba(0, 0, 0, 0.08),
                inset 0 1px 0 rgba(255, 255, 255, 0.8) !important;
}

.portal-3d-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
}

.portal-icon-3d {
    width: 56px;
    height: 56px;
    filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.1));
}

.badge {
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    padding: 8px 14px;
    border-radius: 8px;
    display: inline-block;
    white-space: nowrap;
}

.badge-gold {
    background: var(--c-gold-tint);
    color: var(--c-gold-ink);
    box-shadow: 0 2px 8px var(--sh-gold);
}

.badge-blue {
    background: var(--c-blue-tint);
    color: var(--c-blue);
    box-shadow: 0 2px 8px rgba(87, 180, 204, 0.2);
}

.badge-purple {
    background: var(--c-purple-tint);
    color: var(--c-purple);
    box-shadow: 0 2px 8px rgba(158, 140, 216, 0.2);
}

.portal-card-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--c-ink);
    margin: 0 0 6px 0;
    font-family: var(--f-ui);
}

.portal-card-subtitle {
    font-size: 12px;
    font-weight: 600;
    color: var(--c-muted);
    letter-spacing: 1px;
    text-transform: uppercase;
    margin: 0 0 12px 0;
}

.portal-card-description {
    font-size: 13px;
    color: var(--c-body);
    line-height: 1.6;
    margin: 0 0 16px 0;
}

.status-row {
    margin: 16px 0;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 600;
    padding: 8px 14px;
    border-radius: 8px;
    background: var(--c-surface-2);
    color: var(--c-body);
    transition: all 0.3s ease;
}

.status-badge.active {
    background: var(--c-green-tint);
    color: var(--c-green);
    box-shadow: 0 2px 8px rgba(57, 180, 120, 0.15);
}

.status-badge.pending {
    background: var(--c-amber-tint);
    color: var(--c-amber);
    box-shadow: 0 2px 8px rgba(217, 155, 60, 0.15);
}

.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.button-primary {
    background: linear-gradient(135deg, var(--c-primary) 0%, var(--c-primary-deep) 100%) !important;
    color: var(--c-on-primary) !important;
    border: none !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    font-weight: 600 !important;
    transition: all 0.3s ease !important;
    box-shadow: var(--sh-2) !important;
}

.button-primary:hover {
    box-shadow: var(--sh-3) !important;
    transform: translateY(-2px) !important;
}

.button-secondary {
    background: var(--c-primary-tint) !important;
    color: var(--c-primary) !important;
    border: 1px solid var(--c-primary-line) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    font-weight: 600 !important;
    transition: all 0.3s ease !important;
}

.button-secondary:hover {
    background: var(--c-primary-line) !important;
    box-shadow: var(--sh-2) !important;
}

.button-icon {
    width: 18px;
    height: 18px;
}

@media (max-width: 768px) {
    .portal-card-3d {
        padding: 20px !important;
    }

    .portal-3d-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .portal-icon-3d {
        width: 48px;
        height: 48px;
    }
}
</style>

<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
