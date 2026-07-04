<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();
$pageTitle = 'Packages';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (isset($_POST['delete_id'])) {
        $deleteId = (int) $_POST['delete_id'];
        $stmt = db()->prepare('DELETE FROM plans WHERE id = :id');
        $stmt->execute(['id' => $deleteId]);
        flash('success', 'Package deleted.');
        redirect('admin/plans.php');
    }

    $planId = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $slug = trim((string) ($_POST['slug'] ?? ''));
    $billingCycle = ($_POST['billing_cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
    $price = (float) ($_POST['price'] ?? 0);
    $currency = trim((string) ($_POST['currency'] ?? 'USD')) ?: 'USD';
    $diskSpace = (int) ($_POST['disk_space_gb'] ?? 0);
    $bandwidth = (int) ($_POST['bandwidth_gb'] ?? 0);
    $emailAccounts = (int) ($_POST['email_accounts'] ?? 0);
    $databasesCount = (int) ($_POST['databases_count'] ?? 0);
    $domainsAllowed = (int) ($_POST['domains_allowed'] ?? 1);
    $features = trim((string) ($_POST['features'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);

    if ($name === '') {
        flash('error', 'Package name is required.');
        redirect('admin/plans.php');
    }

    if ($slug === '') {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim($slug, '-');
    }

    if ($planId > 0) {
        $stmt = db()->prepare('UPDATE plans SET name = :name, slug = :slug, billing_cycle = :billing_cycle, price = :price, currency = :currency, disk_space_gb = :disk_space_gb, bandwidth_gb = :bandwidth_gb, email_accounts = :email_accounts, databases_count = :databases_count, domains_allowed = :domains_allowed, features = :features, is_active = :is_active, sort_order = :sort_order WHERE id = :id');
        $stmt->execute([
            'id' => $planId,
            'name' => $name,
            'slug' => $slug,
            'billing_cycle' => $billingCycle,
            'price' => $price,
            'currency' => $currency,
            'disk_space_gb' => $diskSpace,
            'bandwidth_gb' => $bandwidth,
            'email_accounts' => $emailAccounts,
            'databases_count' => $databasesCount,
            'domains_allowed' => $domainsAllowed,
            'features' => $features,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
        ]);
        flash('success', 'Package updated.');
    } else {
        $stmt = db()->prepare('INSERT INTO plans (name, slug, billing_cycle, price, currency, disk_space_gb, bandwidth_gb, email_accounts, databases_count, domains_allowed, features, is_active, sort_order) VALUES (:name, :slug, :billing_cycle, :price, :currency, :disk_space_gb, :bandwidth_gb, :email_accounts, :databases_count, :domains_allowed, :features, :is_active, :sort_order)');
        $stmt->execute([
            'name' => $name,
            'slug' => $slug,
            'billing_cycle' => $billingCycle,
            'price' => $price,
            'currency' => $currency,
            'disk_space_gb' => $diskSpace,
            'bandwidth_gb' => $bandwidth,
            'email_accounts' => $emailAccounts,
            'databases_count' => $databasesCount,
            'domains_allowed' => $domainsAllowed,
            'features' => $features,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
        ]);
        flash('success', 'Package created.');
    }

    redirect('admin/plans.php');
}

$editPlan = null;
if (isset($_GET['edit'])) {
    $editPlan = plan_by_id((int) $_GET['edit']);
}

$plans = plans(false);
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="admin-grid">
    <div class="form-card">
        <h2><?= $editPlan ? 'Edit package' : 'Add package' ?></h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= e($editPlan['id'] ?? 0) ?>">
            <label>Name<input type="text" name="name" value="<?= e($editPlan['name'] ?? '') ?>" required></label>
            <label>Slug<input type="text" name="slug" value="<?= e($editPlan['slug'] ?? '') ?>"></label>
            <label>Billing cycle
                <select name="billing_cycle">
                    <option value="monthly" <?= ($editPlan['billing_cycle'] ?? 'monthly') === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    <option value="yearly" <?= ($editPlan['billing_cycle'] ?? '') === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                </select>
            </label>
            <label>Price<input type="number" step="0.01" name="price" value="<?= e($editPlan['price'] ?? '0.00') ?>" required></label>
            <label>Currency<input type="text" name="currency" value="<?= e($editPlan['currency'] ?? 'USD') ?>"></label>
            <label>Disk space (GB)<input type="number" name="disk_space_gb" value="<?= e($editPlan['disk_space_gb'] ?? 0) ?>"></label>
            <label>Bandwidth (GB)<input type="number" name="bandwidth_gb" value="<?= e($editPlan['bandwidth_gb'] ?? 0) ?>"></label>
            <label>Email accounts<input type="number" name="email_accounts" value="<?= e($editPlan['email_accounts'] ?? 0) ?>"></label>
            <label>Databases<input type="number" name="databases_count" value="<?= e($editPlan['databases_count'] ?? 0) ?>"></label>
            <label>Domains allowed<input type="number" name="domains_allowed" value="<?= e($editPlan['domains_allowed'] ?? 1) ?>"></label>
            <label>Sort order<input type="number" name="sort_order" value="<?= e($editPlan['sort_order'] ?? 0) ?>"></label>
            <label>Features<textarea name="features"><?= e($editPlan['features'] ?? '') ?></textarea></label>
            <label><input type="checkbox" name="is_active" <?= !isset($editPlan) || (int) ($editPlan['is_active'] ?? 1) === 1 ? 'checked' : '' ?>> Active</label>
            <button type="submit">Save package</button>
        </form>
    </div>

    <div class="table-card">
        <h2>All packages</h2>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Price</th>
                    <th>Active</th>
                    <th>Order</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plans as $plan): ?>
                    <tr>
                        <td><?= e($plan['name']) ?></td>
                        <td><?= e(site_currency_symbol()) ?><?= e(number_format((float) $plan['price'], 2)) ?></td>
                        <td><?= (int) $plan['is_active'] === 1 ? 'Yes' : 'No' ?></td>
                        <td><?= e($plan['sort_order']) ?></td>
                        <td>
                            <a class="button secondary" href="<?= e(url('admin/plans.php?edit=' . (int) $plan['id'])) ?>">Edit</a>
                            <form method="post" style="display:inline; margin-left:8px;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="delete_id" value="<?= e((int) $plan['id']) ?>">
                                <button type="submit" class="secondary">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
