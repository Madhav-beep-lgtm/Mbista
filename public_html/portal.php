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


<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
