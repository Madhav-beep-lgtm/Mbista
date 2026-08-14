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

$featuredIds = array_filter([
    (int) ($mbistaCompany['id'] ?? 0),
    (int) ($altioraCompany['id'] ?? 0),
]);
$otherCompanies = array_values(array_filter(
    $companies,
    static fn (array $company): bool => !in_array((int) $company['id'], $featuredIds, true)
));

if (count($companies) === 1 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $only = $companies[0];
    if ((string) ($only['code'] ?? '') === 'MBAACA' || company_pin_is_set((int) $only['id'])) {
        // Fall through
    }
}

include __DIR__ . '/../app/views/partials/header.php';
?>

<section class="section portal-selector-page">
    <div class="container portal-directory">
        <header class="portal-hero">
            <div class="portal-hero-copy">
                <div class="portal-eyebrow">
                    <span class="portal-eyebrow-icon" aria-hidden="true"><?= icon('admin') ?></span>
                    Secure organization access
                </div>
                <h1>Choose the workspace you want to manage</h1>
                <p>Start with the M.Bista administration workspace or open an authorized company directly. Your access and company context remain protected at every step.</p>
            </div>
            <div class="portal-security-note">
                <span class="portal-security-icon" aria-hidden="true"><?= icon('lock') ?></span>
                <span>
                    <strong>Protected access</strong>
                    <small>4-digit admin PIN verification</small>
                </span>
            </div>
        </header>

        <?php if ($mbistaCompany || $altioraCompany): ?>
            <section class="portal-company-section portal-company-section-featured" aria-labelledby="primary-workspaces-title">
                <div class="portal-section-heading">
                    <div>
                        <span class="portal-section-label">Primary access</span>
                        <h2 id="primary-workspaces-title">Administration workspaces</h2>
                    </div>
                    <p>Select a workspace to continue securely.</p>
                </div>

                <div class="portal-company-grid portal-company-grid-featured">
                    <?php if ($mbistaCompany): ?>
                        <?php $mbistaPinIsSet = company_pin_is_set((int) $mbistaCompany['id']); ?>
                        <form method="post" class="card portal-company-card portal-company-card-gold">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="company_id" value="<?= e((int) $mbistaCompany['id']) ?>">

                            <div class="portal-card-topline">
                                <span class="portal-icon-shell portal-icon-shell-gold" aria-hidden="true"><?= icon('admin') ?></span>
                                <span class="portal-type-pill portal-type-pill-gold">Superadmin portal</span>
                            </div>

                            <div class="portal-card-content">
                                <p class="portal-company-code"><?= e($mbistaCompany['code']) ?></p>
                                <h3><?= e($mbistaCompany['name']) ?></h3>
                                <p class="portal-card-description">Manage the M.Bista &amp; Associates workspace and continue to Altiora Global Holdings from its dashboard.</p>
                            </div>

                            <div class="portal-card-footer">
                                <span class="portal-pin-status <?= $mbistaPinIsSet ? 'is-ready' : 'is-required' ?>">
                                    <span aria-hidden="true"><?= icon($mbistaPinIsSet ? 'admin' : 'lock') ?></span>
                                    <?= $mbistaPinIsSet ? 'PIN configured' : 'PIN required' ?>
                                </span>
                                <button type="submit" class="button portal-company-action">
                                    <span>Open M.Bista</span>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($altioraCompany): ?>
                        <?php $altioraPinIsSet = company_pin_is_set((int) $altioraCompany['id']); ?>
                        <form method="post" class="card portal-company-card portal-company-card-blue">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="company_id" value="<?= e((int) $altioraCompany['id']) ?>">

                            <div class="portal-card-topline">
                                <span class="portal-icon-shell portal-icon-shell-blue" aria-hidden="true"><?= icon('companies') ?></span>
                                <span class="portal-type-pill portal-type-pill-blue">Parent company</span>
                            </div>

                            <div class="portal-card-content">
                                <p class="portal-company-code"><?= e($altioraCompany['code']) ?></p>
                                <h3><?= e($altioraCompany['name']) ?></h3>
                                <p class="portal-card-description">Manage Altiora Global Holdings and its authorized subsidiaries, including EBCPL and MBTAS.</p>
                            </div>

                            <div class="portal-card-footer">
                                <span class="portal-pin-status <?= $altioraPinIsSet ? 'is-ready' : 'is-required' ?>">
                                    <span aria-hidden="true"><?= icon($altioraPinIsSet ? 'admin' : 'lock') ?></span>
                                    <?= $altioraPinIsSet ? 'PIN configured' : 'PIN required' ?>
                                </span>
                                <button type="submit" class="button portal-company-action">
                                    <span>Open Altiora</span>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($otherCompanies)): ?>
            <section class="portal-company-section" aria-labelledby="company-workspaces-title">
                <div class="portal-section-heading">
                    <div>
                        <span class="portal-section-label">Company directory</span>
                        <h2 id="company-workspaces-title">Other authorized workspaces</h2>
                    </div>
                    <p><?= e(count($otherCompanies)) ?> <?= count($otherCompanies) === 1 ? 'workspace' : 'workspaces' ?> available</p>
                </div>

                <div class="portal-company-grid portal-company-grid-directory">
                    <?php foreach ($otherCompanies as $company): ?>
                        <?php
                        $isSubsidiary = !empty($company['parent_company_name']);
                        $companyPinIsSet = company_pin_is_set((int) $company['id']);
                        ?>
                        <form method="post" class="card portal-company-card portal-company-card-neutral">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="company_id" value="<?= e((int) $company['id']) ?>">

                            <div class="portal-card-topline">
                                <span class="portal-icon-shell portal-icon-shell-neutral" aria-hidden="true"><?= icon($isSubsidiary ? 'companies' : 'staff') ?></span>
                                <span class="portal-type-pill"><?= $isSubsidiary ? 'Subsidiary' : 'Company' ?></span>
                            </div>

                            <div class="portal-card-content">
                                <p class="portal-company-code"><?= e($company['code']) ?></p>
                                <h3><?= e($company['name']) ?></h3>
                                <p class="portal-card-description">
                                    <?= $isSubsidiary
                                        ? 'Part of the ' . e($company['parent_company_name']) . ' group.'
                                        : 'Independent company administration workspace.' ?>
                                </p>
                            </div>

                            <div class="portal-card-footer">
                                <span class="portal-pin-status <?= $companyPinIsSet ? 'is-ready' : 'is-required' ?>">
                                    <span aria-hidden="true"><?= icon($companyPinIsSet ? 'admin' : 'lock') ?></span>
                                    <?= $companyPinIsSet ? 'PIN configured' : 'PIN required' ?>
                                </span>
                                <button type="submit" class="button portal-company-action portal-company-action-secondary">
                                    <span>Open workspace</span>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                                </button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!$mbistaCompany && !$altioraCompany && empty($companies)): ?>
            <div class="portal-empty-state">
                <span class="portal-icon-shell portal-icon-shell-neutral" aria-hidden="true"><?= icon('companies') ?></span>
                <h2>No organizations assigned yet</h2>
                <p>Your account is not linked to a company workspace. Ask your administrator to grant access.</p>
                <a class="button portal-company-action" href="<?= e(url('logout.php')) ?>">Log out</a>
            </div>
        <?php endif; ?>
    </div>
</section>

<style>
.portal-selector-page {
    --pd-bg: #f3f7f5;
    --pd-surface: #ffffff;
    --pd-surface-soft: #f8fbfa;
    --pd-text: #12231e;
    --pd-muted: #64756f;
    --pd-line: #dce7e2;
    --pd-green: #0b775b;
    --pd-green-deep: #075642;
    --pd-gold: #c99632;
    --pd-blue: #3276b1;
    --pd-shadow: 0 16px 40px rgba(17, 45, 36, 0.08);
    min-height: calc(100vh - 120px);
    padding: 28px 0 64px;
    overflow: hidden;
    background:
        radial-gradient(circle at 92% 2%, rgba(19, 139, 102, 0.08), transparent 28rem),
        var(--pd-bg) !important;
    color: var(--pd-text);
}

body.theme-dark .portal-selector-page {
    --pd-bg: #061d17;
    --pd-surface: #0d2922;
    --pd-surface-soft: #102f27;
    --pd-text: #f3f8f6;
    --pd-muted: #a7bbb4;
    --pd-line: rgba(191, 220, 208, 0.16);
    --pd-green: #2aa986;
    --pd-green-deep: #167b60;
    --pd-gold: #e0b354;
    --pd-blue: #64a8df;
    --pd-shadow: 0 18px 48px rgba(0, 0, 0, 0.24);
}

.portal-selector-page .portal-directory {
    position: relative;
    z-index: 1;
    width: min(100% - 48px, 1420px);
}

.portal-selector-page .portal-hero {
    position: relative;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 32px;
    min-height: 210px;
    padding: 36px 40px;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 24px;
    background:
        radial-gradient(circle at 83% 20%, rgba(224, 179, 84, 0.22), transparent 20rem),
        linear-gradient(135deg, #062c23 0%, #0a5c48 58%, #087558 100%);
    box-shadow: 0 24px 54px rgba(4, 50, 38, 0.2);
}

.portal-selector-page .portal-hero::after {
    content: "";
    position: absolute;
    right: -74px;
    bottom: -156px;
    width: 390px;
    height: 390px;
    border: 62px solid rgba(255, 255, 255, 0.045);
    border-radius: 50%;
    pointer-events: none;
}

.portal-hero-copy {
    position: relative;
    z-index: 1;
    max-width: 790px;
}

.portal-selector-page .portal-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
    color: #f2d58f;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.portal-eyebrow-icon {
    display: grid;
    width: 27px;
    height: 27px;
    place-items: center;
    border: 1px solid rgba(242, 213, 143, 0.3);
    border-radius: 8px;
    background: rgba(242, 213, 143, 0.1);
}

.portal-eyebrow-icon .ui-icon {
    width: 15px;
    height: 15px;
}

.portal-selector-page .portal-hero h1 {
    max-width: 700px;
    margin: 0;
    color: #ffffff;
    font-family: var(--f-ui);
    font-size: clamp(30px, 3vw, 44px);
    font-weight: 780;
    line-height: 1.08;
    letter-spacing: -0.035em;
}

.portal-selector-page .portal-hero p {
    max-width: 720px;
    margin: 14px 0 0;
    color: rgba(236, 249, 244, 0.78);
    font-size: 14px;
    line-height: 1.65;
}

.portal-security-note {
    position: relative;
    z-index: 1;
    display: flex;
    min-width: 245px;
    padding: 14px 16px;
    align-items: center;
    gap: 12px;
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 14px;
    background: rgba(3, 35, 27, 0.34);
    color: #fff;
    backdrop-filter: blur(12px);
}

.portal-security-icon {
    display: grid;
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    place-items: center;
    border-radius: 11px;
    background: rgba(242, 213, 143, 0.14);
    color: #f2d58f;
}

.portal-security-icon .ui-icon {
    width: 19px;
    height: 19px;
}

.portal-security-note strong,
.portal-security-note small {
    display: block;
}

.portal-security-note strong {
    margin-bottom: 3px;
    font-size: 12px;
    font-weight: 750;
}

.portal-security-note small {
    color: rgba(236, 249, 244, 0.68);
    font-size: 10.5px;
}

.portal-company-section {
    margin-top: 30px;
}

.portal-section-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 24px;
    margin: 0 2px 13px;
}

.portal-section-label {
    display: block;
    margin-bottom: 4px;
    color: var(--pd-green);
    font-size: 9.5px;
    font-weight: 800;
    letter-spacing: 0.11em;
    text-transform: uppercase;
}

.portal-section-heading h2 {
    margin: 0;
    color: var(--pd-text);
    font-family: var(--f-ui);
    font-size: 18px;
    font-weight: 760;
    letter-spacing: -0.015em;
}

.portal-section-heading p {
    margin: 0 0 2px;
    color: var(--pd-muted);
    font-size: 11.5px;
}

.portal-selector-page .portal-company-grid {
    display: grid !important;
    gap: 16px;
    margin: 0 !important;
}

.portal-company-grid-featured {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.portal-company-grid-directory {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.portal-selector-page form.card.portal-company-card {
    position: relative;
    display: flex;
    min-width: 0;
    min-height: 300px;
    padding: 23px !important;
    overflow: hidden;
    flex-direction: column;
    border: 1px solid var(--pd-line) !important;
    border-radius: 18px !important;
    background: var(--pd-surface) !important;
    box-shadow: var(--pd-shadow) !important;
    transform: none;
    transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
}

.portal-selector-page form.card.portal-company-card::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 4px;
    border-radius: 18px 0 0 18px;
    background: var(--pd-green);
    opacity: 0.9;
}

.portal-selector-page form.card.portal-company-card-gold::before {
    background: var(--pd-gold);
}

.portal-selector-page form.card.portal-company-card-blue::before {
    background: var(--pd-blue);
}

.portal-selector-page form.card.portal-company-card:hover {
    border-color: rgba(40, 142, 111, 0.42) !important;
    box-shadow: 0 22px 50px rgba(10, 49, 38, 0.13) !important;
    transform: translateY(-3px);
}

body.theme-dark .portal-selector-page form.card.portal-company-card:hover {
    box-shadow: 0 24px 54px rgba(0, 0, 0, 0.32) !important;
}

.portal-card-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 20px;
}

.portal-icon-shell {
    display: grid;
    width: 54px;
    height: 54px;
    flex: 0 0 54px;
    place-items: center;
    border: 1px solid rgba(49, 118, 177, 0.2);
    border-radius: 15px;
    background: linear-gradient(145deg, rgba(49, 118, 177, 0.14), rgba(49, 118, 177, 0.045));
    color: var(--pd-blue);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.24);
}

.portal-icon-shell .ui-icon {
    width: 29px;
    height: 29px;
    stroke-width: 1.65;
}

.portal-icon-shell-gold {
    border-color: rgba(201, 150, 50, 0.24);
    background: linear-gradient(145deg, rgba(201, 150, 50, 0.17), rgba(201, 150, 50, 0.05));
    color: var(--pd-gold);
}

.portal-icon-shell-neutral {
    border-color: rgba(42, 169, 134, 0.2);
    background: linear-gradient(145deg, rgba(42, 169, 134, 0.14), rgba(42, 169, 134, 0.04));
    color: var(--pd-green);
}

.portal-type-pill {
    display: inline-flex;
    padding: 6px 9px;
    border: 1px solid var(--pd-line);
    border-radius: 999px;
    background: var(--pd-surface-soft);
    color: var(--pd-muted);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}

.portal-type-pill-gold {
    border-color: rgba(201, 150, 50, 0.24);
    background: rgba(201, 150, 50, 0.08);
    color: var(--pd-gold);
}

.portal-type-pill-blue {
    border-color: rgba(49, 118, 177, 0.24);
    background: rgba(49, 118, 177, 0.08);
    color: var(--pd-blue);
}

.portal-card-content {
    flex: 1;
}

.portal-selector-page form.card .portal-company-code {
    margin: 0 0 6px;
    color: var(--pd-green);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.portal-selector-page form.card.portal-company-card h3 {
    margin: 0 0 10px;
    color: var(--pd-text);
    font-family: var(--f-ui);
    font-size: 19px;
    font-weight: 760;
    line-height: 1.3;
    letter-spacing: -0.018em;
}

.portal-selector-page form.card p.portal-card-description:last-of-type {
    display: block;
    align-self: auto;
    gap: 0;
    max-width: 580px;
    margin: 0;
    padding: 0;
    border-radius: 0;
    background: transparent;
    color: var(--pd-muted);
    font-size: 12.5px;
    font-weight: 400;
    line-height: 1.6;
}

.portal-selector-page form.card p.portal-card-description:last-of-type::before {
    content: none;
}

.portal-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-top: 22px;
    padding-top: 17px;
    border-top: 1px solid var(--pd-line);
}

.portal-pin-status {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: var(--pd-muted);
    font-size: 10.5px;
    font-weight: 700;
    white-space: nowrap;
}

.portal-pin-status > span {
    display: grid;
    width: 25px;
    height: 25px;
    place-items: center;
    border-radius: 8px;
}

.portal-pin-status .ui-icon {
    width: 14px;
    height: 14px;
}

.portal-pin-status.is-ready {
    color: #148460;
}

.portal-pin-status.is-ready > span {
    background: rgba(20, 132, 96, 0.11);
}

.portal-pin-status.is-required {
    color: #b67a13;
}

.portal-pin-status.is-required > span {
    background: rgba(182, 122, 19, 0.12);
}

.portal-selector-page form.card .portal-company-action,
.portal-selector-page .portal-company-action {
    display: inline-flex !important;
    width: auto !important;
    min-height: 40px;
    margin: 0;
    padding: 9px 13px 9px 15px;
    align-items: center;
    justify-content: center;
    gap: 10px;
    border: 1px solid var(--pd-green-deep) !important;
    border-radius: 10px !important;
    background: linear-gradient(135deg, var(--pd-green), var(--pd-green-deep)) !important;
    color: #ffffff !important;
    font-size: 11px;
    font-weight: 750;
    line-height: 1;
    box-shadow: 0 8px 18px rgba(8, 100, 76, 0.17) !important;
    transition: transform 160ms ease, box-shadow 160ms ease, filter 160ms ease;
}

.portal-selector-page form.card .portal-company-action::after {
    content: none !important;
}

.portal-company-action svg {
    width: 16px;
    height: 16px;
    fill: none;
    stroke: currentColor;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-width: 1.8;
    transition: transform 160ms ease;
}

.portal-selector-page form.card .portal-company-action:hover,
.portal-selector-page .portal-company-action:hover {
    filter: brightness(1.07);
    box-shadow: 0 10px 22px rgba(8, 100, 76, 0.22) !important;
    transform: translateY(-1px);
}

.portal-company-action:hover svg {
    transform: translateX(2px);
}

.portal-selector-page form.card .portal-company-action:focus-visible,
.portal-selector-page .portal-company-action:focus-visible {
    outline: 3px solid rgba(42, 169, 134, 0.25);
    outline-offset: 3px;
}

.portal-company-grid-directory .portal-company-card {
    min-height: 270px !important;
}

.portal-empty-state {
    max-width: 620px;
    margin: 30px auto 0;
    padding: 42px;
    border: 1px solid var(--pd-line);
    border-radius: 20px;
    background: var(--pd-surface);
    text-align: center;
    box-shadow: var(--pd-shadow);
}

.portal-empty-state .portal-icon-shell {
    margin: 0 auto 18px;
}

.portal-empty-state h2 {
    margin: 0 0 8px;
    color: var(--pd-text);
    font-size: 21px;
}

.portal-empty-state p {
    margin: 0 0 22px;
    color: var(--pd-muted);
    font-size: 13px;
}

@media (max-width: 1080px) {
    .portal-company-grid-directory {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 820px) {
    .portal-selector-page {
        padding-top: 20px;
    }

    .portal-selector-page .portal-directory {
        width: min(100% - 28px, 1420px);
    }

    .portal-selector-page .portal-hero {
        min-height: 0;
        padding: 30px;
        align-items: flex-start;
        flex-direction: column;
    }

    .portal-security-note {
        min-width: 0;
    }

    .portal-company-grid-featured,
    .portal-company-grid-directory {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 560px) {
    .portal-selector-page {
        padding: 12px 0 40px;
    }

    .portal-selector-page .portal-directory {
        width: min(100% - 18px, 1420px);
    }

    .portal-selector-page .portal-hero {
        padding: 24px 21px;
        border-radius: 17px;
    }

    .portal-selector-page .portal-hero h1 {
        font-size: 27px;
    }

    .portal-selector-page .portal-hero p {
        font-size: 12.5px;
    }

    .portal-security-note {
        width: 100%;
    }

    .portal-section-heading {
        align-items: flex-start;
        flex-direction: column;
        gap: 4px;
    }

    .portal-selector-page form.card.portal-company-card {
        min-height: 0 !important;
        padding: 20px !important;
        border-radius: 15px !important;
    }

    .portal-card-footer {
        align-items: stretch;
        flex-direction: column;
    }

    .portal-selector-page form.card .portal-company-action {
        width: 100% !important;
    }
}

@media (prefers-reduced-motion: reduce) {
    .portal-selector-page form.card.portal-company-card,
    .portal-selector-page .portal-company-action,
    .portal-company-action svg {
        transition: none;
    }
}
</style>

<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
