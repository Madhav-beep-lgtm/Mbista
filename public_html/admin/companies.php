<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();
$pageTitle = 'Companies & Fiscal Years';
$currentAdmin = current_user();
$parentColumnExists = false;
if (table_exists('companies')) {
    $columnCheck = db()->query("SHOW COLUMNS FROM companies LIKE 'parent_company_id'");
    $parentColumnExists = $columnCheck !== false && $columnCheck->fetch() !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_company') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $adminPin = trim((string) ($_POST['admin_pin'] ?? ''));
        $parentCompanyIdRaw = trim((string) ($_POST['parent_company_id'] ?? ''));
        $parentCompanyId = $parentCompanyIdRaw === '' ? null : (int) $parentCompanyIdRaw;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $code === '') {
            flash('error', 'Company name and code are required.');
            redirect('admin/companies.php');
        }

        if ($adminPin !== '' && !preg_match('/^[0-9]{4}$/', $adminPin)) {
            flash('error', 'Company PIN must be exactly 4 digits.');
            redirect('admin/companies.php');
        }

        if ($parentCompanyId !== null && $parentCompanyId <= 0) {
            flash('error', 'Invalid parent company selected.');
            redirect('admin/companies.php');
        }

        if ($parentColumnExists && $parentCompanyId !== null) {
            $parentCheck = db()->prepare('SELECT id FROM companies WHERE id = :id LIMIT 1');
            $parentCheck->execute(['id' => $parentCompanyId]);
            if (!$parentCheck->fetch()) {
                flash('error', 'Selected parent company does not exist.');
                redirect('admin/companies.php');
            }
        }

        if ($parentColumnExists) {
            $stmt = db()->prepare('INSERT INTO companies (name, code, parent_company_id, admin_pin_hash, is_active) VALUES (:name, :code, :parent_company_id, :admin_pin_hash, :is_active)');
            $stmt->execute([
                'name' => $name,
                'code' => $code,
                'parent_company_id' => $parentCompanyId,
                'admin_pin_hash' => $adminPin !== '' ? password_hash($adminPin, PASSWORD_DEFAULT) : null,
                'is_active' => $isActive,
            ]);
        } else {
            $stmt = db()->prepare('INSERT INTO companies (name, code, admin_pin_hash, is_active) VALUES (:name, :code, :admin_pin_hash, :is_active)');
            $stmt->execute([
                'name' => $name,
                'code' => $code,
                'admin_pin_hash' => $adminPin !== '' ? password_hash($adminPin, PASSWORD_DEFAULT) : null,
                'is_active' => $isActive,
            ]);
        }

        $companyId = (int) db()->lastInsertId();
        log_activity('company', $companyId, 'created', 'Company created from admin workflow.', (int) ($currentAdmin['id'] ?? 0));
        flash('success', 'Company created.');
        redirect('admin/companies.php');
    }

    if ($action === 'create_fiscal_year') {
        $companyId = (int) ($_POST['company_id'] ?? 0);
        $label = trim((string) ($_POST['label'] ?? ''));
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $isDefault = isset($_POST['is_default']) ? 1 : 0;

        if ($companyId <= 0 || $label === '' || $startDate === '' || $endDate === '') {
            flash('error', 'Company, label, start date, and end date are required.');
            redirect('admin/companies.php');
        }

        if ($startDate > $endDate) {
            flash('error', 'Fiscal year start date cannot be after end date.');
            redirect('admin/companies.php');
        }

        if ($isDefault === 1) {
            $reset = db()->prepare('UPDATE fiscal_years SET is_default = 0 WHERE company_id = :company_id');
            $reset->execute(['company_id' => $companyId]);
        }

        $stmt = db()->prepare('INSERT INTO fiscal_years (company_id, label, start_date, end_date, is_active, is_default) VALUES (:company_id, :label, :start_date, :end_date, :is_active, :is_default)');
        $stmt->execute([
            'company_id' => $companyId,
            'label' => $label,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_active' => $isActive,
            'is_default' => $isDefault,
        ]);

        $fiscalYearId = (int) db()->lastInsertId();
        log_activity('fiscal_year', $fiscalYearId, 'created', 'Fiscal year created from admin workflow.', (int) ($currentAdmin['id'] ?? 0));
        flash('success', 'Fiscal year created.');
        redirect('admin/companies.php');
    }

    flash('error', 'Unsupported action.');
    redirect('admin/companies.php');
}

$companies = $parentColumnExists
    ? db()->query('SELECT c.*, p.name AS parent_company_name FROM companies c LEFT JOIN companies p ON p.id = c.parent_company_id ORDER BY c.name ASC')->fetchAll()
    : companies_list(false);
$fiscalYears = db()->query('SELECT fy.*, c.name AS company_name FROM fiscal_years fy INNER JOIN companies c ON c.id = fy.company_id ORDER BY c.name ASC, fy.start_date DESC')->fetchAll();

include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="admin-grid">
    <div class="form-card">
        <h2>Create company</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_company">
            <label>Name<input type="text" name="name" required></label>
            <label>Code<input type="text" name="code" maxlength="20" required></label>
            <label>Company PIN (optional)<input type="password" name="admin_pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" minlength="4" placeholder="4 digits"></label>
            <?php if ($parentColumnExists): ?>
                <label>Parent company (optional)
                    <select name="parent_company_id">
                        <option value="">Independent / parent company</option>
                        <?php foreach ($companies as $company): ?>
                            <option value="<?= e((int) $company['id']) ?>"><?= e($company['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label><input type="checkbox" name="is_active" checked> Active</label>
            <button type="submit">Create company</button>
        </form>
    </div>

    <div class="form-card">
        <h2>Create fiscal year</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_fiscal_year">
            <label>Company
                <select name="company_id" required>
                    <option value="">Select company</option>
                    <?php foreach ($companies as $company): ?>
                        <option value="<?= e((int) $company['id']) ?>"><?= e($company['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Label<input type="text" name="label" placeholder="FY 2026-2027" required></label>
            <label>Start date<input type="date" name="start_date" required></label>
            <label>End date<input type="date" name="end_date" required></label>
            <label><input type="checkbox" name="is_active" checked> Active</label>
            <label><input type="checkbox" name="is_default"> Default for company</label>
            <button type="submit">Create fiscal year</button>
        </form>
    </div>
</div>

<div class="table-card">
    <h2>Companies</h2>
    <table>
        <thead>
            <tr><th>ID</th><th>Name</th><th>Code</th><?php if ($parentColumnExists): ?><th>Relationship</th><?php endif; ?><th>Status</th><th>PIN</th><th>Action</th></tr>
        </thead>
        <tbody>
            <?php if ($companies === []): ?>
                <tr><td colspan="<?= $parentColumnExists ? '7' : '6' ?>">No companies available.</td></tr>
            <?php endif; ?>
            <?php foreach ($companies as $company): ?>
                <tr>
                    <td>#<?= e((int) $company['id']) ?></td>
                    <td><?= e($company['name']) ?></td>
                    <td><?= e($company['code']) ?></td>
                    <?php if ($parentColumnExists): ?>
                        <td>
                            <?php if (!empty($company['parent_company_name'])): ?>
                                Subsidiary of <?= e($company['parent_company_name']) ?>
                            <?php else: ?>
                                Independent / parent
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td><?= (int) $company['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                    <td><?= company_pin_is_set((int) $company['id']) ? 'Configured' : 'Required' ?></td>
                    <td>
                        <?php if ((int) $company['is_active'] === 1): ?>
                            <form method="post" action="<?= e(url('admin/switch-company.php')) ?>" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="company_id" value="<?= e((int) $company['id']) ?>">
                                <input type="hidden" name="return_to" value="admin/companies.php">
                                <button type="submit">Open portal</button>
                            </form>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="table-card">
    <h2>Fiscal years</h2>
    <table>
        <thead>
            <tr><th>ID</th><th>Company</th><th>Label</th><th>Period</th><th>Default</th><th>Status</th></tr>
        </thead>
        <tbody>
            <?php if ($fiscalYears === []): ?>
                <tr><td colspan="6">No fiscal years available.</td></tr>
            <?php endif; ?>
            <?php foreach ($fiscalYears as $fy): ?>
                <tr>
                    <td>#<?= e((int) $fy['id']) ?></td>
                    <td><?= e($fy['company_name']) ?></td>
                    <td><?= e($fy['label']) ?></td>
                    <td><?= e($fy['start_date']) ?> to <?= e($fy['end_date']) ?></td>
                    <td><?= (int) $fy['is_default'] === 1 ? 'Yes' : 'No' ?></td>
                    <td><?= (int) $fy['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
