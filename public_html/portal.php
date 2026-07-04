<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

require_admin();

if (company_pin_verified(current_company_id()) && current_company()) {
    redirect('admin/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Use the new centralized helper function to handle the switch.
    $companyId = (int) ($_POST['company_id'] ?? 0);
    handle_company_switch($companyId, 'portal.php');
}

$companies = table_exists('companies')
    ? db()->query('SELECT c.*, p.name AS parent_company_name FROM companies c LEFT JOIN companies p ON p.id = c.parent_company_id WHERE c.is_active = 1 ORDER BY c.parent_company_id IS NULL DESC, c.name ASC')->fetchAll()
    : [];
$mbistaCompany = company_by_code('MBAACA');
$altioraCompany = company_by_code('AGHPL');

include __DIR__ . '/../app/views/partials/header.php';
?>
<section class="section">
    <div class="container">
        <div class="hero-panel">
            <div class="hero-grid">
                <div>
                    <div class="kicker">Superadmin workflow</div>
                    <h1>Open the M.Bista superadmin portal, then manage Altiora and its subsidiaries.</h1>
                    <p>After selecting a company, you will enter a 4-digit admin PIN before opening that company’s management portal.</p>
                </div>
            </div>
        </div>

        <?php if ($mbistaCompany || $altioraCompany): ?>
            <div class="admin-grid" style="margin-top: 24px;">
                <?php if ($mbistaCompany): ?>
                    <form method="post" class="card">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="company_id" value="<?= e((int) $mbistaCompany['id']) ?>">
                        <div class="badge">Superadmin portal</div>
                        <h3><?= e($mbistaCompany['name']) ?></h3>
                        <p>Manage the M.Bista and Associates workspace, then open Altiora Global Holdings from its dashboard.</p>
                        <p><?= company_pin_is_set((int) $mbistaCompany['id']) ? 'PIN configured' : 'PIN required' ?></p>
                        <button type="submit">Open M.Bista superadmin portal</button>
                    </form>
                <?php endif; ?>
                <?php if ($altioraCompany): ?>
                    <form method="post" class="card">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="company_id" value="<?= e((int) $altioraCompany['id']) ?>">
                        <div class="badge">Parent company</div>
                        <h3><?= e($altioraCompany['name']) ?></h3>
                        <p>Open the Altiora parent-company admin page and switch into subsidiary company portals.</p>
                        <p><?= company_pin_is_set((int) $altioraCompany['id']) ? 'PIN configured' : 'PIN required' ?></p>
                        <button type="submit">Open Altiora Global Holdings</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="admin-grid" style="margin-top: 24px;">
            <?php foreach ($companies as $company): ?>
                <form method="post" class="card">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="company_id" value="<?= e((int) $company['id']) ?>">
                    <div class="badge"><?= e($company['code']) ?></div>
                    <h3><?= e($company['name']) ?></h3>
                    <p>
                        <?php if (!empty($company['parent_company_name'])): ?>
                            Subsidiary of <?= e($company['parent_company_name']) ?>
                        <?php else: ?>
                            Parent or independent company
                        <?php endif; ?>
                    </p>
                    <p><?= company_pin_is_set((int) $company['id']) ? 'PIN configured' : 'PIN required' ?></p>
                    <button type="submit">Open company portal</button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
