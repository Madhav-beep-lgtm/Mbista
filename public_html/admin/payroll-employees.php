<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/payroll_engine.php';

// Admins, staff accountants working in an authorized company, and clients
// inside their OWN books may manage the payroll roster. Fine-grained rights
// come from the payroll RBAC module (user_can_do).
require_staff_admin_or_client_books();
require_company_context();
require_permission('payroll', 'view');
accounting_module_repair_database();

$company = current_company();
$fiscalYear = current_fiscal_year();
if (!$company || !$fiscalYear) {
    flash('error', 'Company and fiscal year context required.');
    redirect('admin/accounting-dashboard.php');
}
$companyId = (int) $company['id'];
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$isAdmin = (string) ($currentUser['role'] ?? '') === 'admin';
$sym = site_currency_symbol();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_employee') {
        require_permission('payroll', 'create');
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $identityMode = (string) ($_POST['identity_mode'] ?? 'existing') === 'new' ? 'new' : 'existing';
        $linkedUserId = (int) ($_POST['user_id'] ?? 0);
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $employeeEmail = trim((string) ($_POST['employee_email'] ?? ''));
        $employeePhone = trim((string) ($_POST['employee_phone'] ?? ''));
        $createLogin = !empty($_POST['create_login']);
        $loginPassword = (string) ($_POST['login_password'] ?? '');
        $code = strtoupper(trim((string) ($_POST['employee_code'] ?? '')));
        $basic = max(0.0, round((float) ($_POST['basic_salary'] ?? 0), 2));
        $marital = in_array((string) ($_POST['marital_status'] ?? ''), ['individual', 'couple'], true) ? (string) $_POST['marital_status'] : 'individual';
        $scheme = in_array((string) ($_POST['retirement_scheme'] ?? ''), ['none', 'ssf', 'pf', 'cit'], true) ? (string) $_POST['retirement_scheme'] : 'none';
        $status = (string) ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($code === '') {
            flash('error', 'Give the employee a code (e.g. E001).');
            redirect('admin/payroll-employees.php');
        }

        $existingEmployee = null;
        if ($employeeId > 0) {
            $existingStmt = db()->prepare('SELECT * FROM payroll_employees WHERE id = :id AND company_id = :cid');
            $existingStmt->execute(['id' => $employeeId, 'cid' => $companyId]);
            $existingEmployee = $existingStmt->fetch() ?: null;
            if (!$existingEmployee) {
                flash('error', 'Employee not found in this company.');
                redirect('admin/payroll-employees.php');
            }
        }

        if ($identityMode === 'existing') {
            // Salary is processed only for people who belong to THIS company.
            // The roster is company-scoped (independent tenants must not see or
            // enrol each other's people), so the linked user must be a member
            // of the active company too.
            $userCheck = db()->prepare("SELECT id FROM users WHERE id = :id AND company_id = :cid AND role IN ('admin', 'staff', 'customer') AND status = 'active'");
            $userCheck->execute(['id' => $linkedUserId, 'cid' => $companyId]);
            if (!$userCheck->fetchColumn()) {
                flash('error', 'Pick an existing user from this company.');
                redirect('admin/payroll-employees.php');
            }
        } else {
            if ($fullName === '') {
                flash('error', 'Give the employee a full name.');
                redirect('admin/payroll-employees.php');
            }
            if ($employeeEmail !== '' && !filter_var($employeeEmail, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'The employee email address is not valid.');
                redirect('admin/payroll-employees.php');
            }

            // Without "create user ID" the person exists purely for payroll in
            // this company. An update keeps any login the employee already has.
            $linkedUserId = (int) ($existingEmployee['user_id'] ?? 0);

            if ($createLogin) {
                // Creating a login is an account-provisioning act — admins only.
                if (!$isAdmin) {
                    flash('error', 'Only an administrator can create a user ID for an employee. Save without the login option, or ask your accountant.');
                    redirect('admin/payroll-employees.php');
                }
                if ($linkedUserId > 0) {
                    flash('error', 'This employee already has a user ID.');
                    redirect('admin/payroll-employees.php');
                }
                if ($employeeEmail === '' || !filter_var($employeeEmail, FILTER_VALIDATE_EMAIL)) {
                    flash('error', 'A valid email address is required to create the user ID.');
                    redirect('admin/payroll-employees.php');
                }
                if (strlen($loginPassword) < 8) {
                    flash('error', 'The login password must be at least 8 characters.');
                    redirect('admin/payroll-employees.php');
                }
                try {
                    $linkedUserId = create_user([
                        'name' => $fullName,
                        'email' => $employeeEmail,
                        'password' => $loginPassword,
                        'role' => 'staff',
                        'status' => 'active',
                        'company_id' => $companyId,
                        'phone' => $employeePhone !== '' ? $employeePhone : null,
                        'company' => null,
                        // Viewer level: the login is for payslips/self-service,
                        // not for keeping the books.
                        'access_level' => 'viewer',
                        'must_change_password' => 1,
                    ]);
                    log_activity('user', $linkedUserId, 'created', 'User ID created for payroll employee ' . $code . '.', $userId);
                } catch (Throwable $loginException) {
                    flash('error', 'Could not create the user ID — that email may already be in use.');
                    redirect('admin/payroll-employees.php');
                }
            }
        }

        $params = [
            'company_id' => $companyId,
            'user_id' => $linkedUserId > 0 ? $linkedUserId : null,
            'full_name' => $identityMode === 'new' ? $fullName : null,
            'email' => $identityMode === 'new' ? ($employeeEmail !== '' ? $employeeEmail : null) : null,
            'phone' => $identityMode === 'new' ? ($employeePhone !== '' ? $employeePhone : null) : null,
            'employee_code' => $code,
            'department' => trim((string) ($_POST['department'] ?? '')) ?: null,
            'designation' => trim((string) ($_POST['designation'] ?? '')) ?: null,
            'pan_no' => trim((string) ($_POST['pan_no'] ?? '')) ?: null,
            'bank_name' => trim((string) ($_POST['bank_name'] ?? '')) ?: null,
            'bank_account' => trim((string) ($_POST['bank_account'] ?? '')) ?: null,
            'marital_status' => $marital,
            'retirement_scheme' => $scheme,
            'retirement_id' => trim((string) ($_POST['retirement_id'] ?? '')) ?: null,
            'retirement_employee_rate' => max(0.0, round((float) ($_POST['retirement_employee_rate'] ?? 0), 2)),
            'retirement_employer_rate' => max(0.0, round((float) ($_POST['retirement_employer_rate'] ?? 0), 2)),
            'basic_salary' => $basic,
            'ot_hourly_rate' => (float) ($_POST['ot_hourly_rate'] ?? 0) > 0 ? round((float) $_POST['ot_hourly_rate'], 4) : null,
            'sc_eligible' => isset($_POST['sc_eligible']) ? 1 : 0,
            'status' => $status,
            'joined_on' => trim((string) ($_POST['joined_on'] ?? '')) ?: null,
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];
        try {
            if ($employeeId > 0) {
                $params['id'] = $employeeId;
                db()->prepare('UPDATE payroll_employees SET user_id = :user_id, full_name = :full_name, email = :email, phone = :phone,
                        employee_code = :employee_code,
                        department = :department, designation = :designation, pan_no = :pan_no,
                        bank_name = :bank_name, bank_account = :bank_account, marital_status = :marital_status,
                        retirement_scheme = :retirement_scheme, retirement_id = :retirement_id,
                        retirement_employee_rate = :retirement_employee_rate, retirement_employer_rate = :retirement_employer_rate,
                        basic_salary = :basic_salary, ot_hourly_rate = :ot_hourly_rate, sc_eligible = :sc_eligible,
                        status = :status, joined_on = :joined_on, notes = :notes
                    WHERE id = :id AND company_id = :company_id')->execute($params);
                log_activity('payroll_employee', $employeeId, 'updated', 'Payroll profile updated.', $userId);
            } else {
                db()->prepare('INSERT INTO payroll_employees (company_id, user_id, full_name, email, phone, employee_code, department, designation,
                        pan_no, bank_name, bank_account, marital_status, retirement_scheme, retirement_id,
                        retirement_employee_rate, retirement_employer_rate, basic_salary, ot_hourly_rate, sc_eligible, status, joined_on, notes)
                    VALUES (:company_id, :user_id, :full_name, :email, :phone, :employee_code, :department, :designation,
                        :pan_no, :bank_name, :bank_account, :marital_status, :retirement_scheme, :retirement_id,
                        :retirement_employee_rate, :retirement_employer_rate, :basic_salary, :ot_hourly_rate, :sc_eligible, :status, :joined_on, :notes)')->execute($params);
                $employeeId = (int) db()->lastInsertId();
                log_activity('payroll_employee', $employeeId, 'created', 'Payroll profile created.', $userId);
            }
            // Pay-structure assignments. The amount/percentage is only a payroll
            // SUGGESTION; blank everything to remove the assignment. Effective
            // dates and remarks ride along; each save is audited.
            $compAmounts = (array) ($_POST['comp_amount'] ?? ($_POST['component'] ?? []));
            $compPercents = (array) ($_POST['comp_percent'] ?? []);
            $compFrom = (array) ($_POST['comp_from'] ?? []);
            $compTo = (array) ($_POST['comp_to'] ?? []);
            $compRemarks = (array) ($_POST['comp_remarks'] ?? []);
            foreach ($compAmounts as $componentId => $rawAmount) {
                $componentId = (int) $componentId;
                if ($componentId <= 0) {
                    continue;
                }
                $amount = round((float) $rawAmount, 2);
                $percent = round((float) ($compPercents[$componentId] ?? 0), 4);
                $from = trim((string) ($compFrom[$componentId] ?? '')) ?: null;
                $to = trim((string) ($compTo[$componentId] ?? '')) ?: null;
                $remark = trim((string) ($compRemarks[$componentId] ?? '')) ?: null;
                if ($amount <= 0 && $percent <= 0 && $from === null && $to === null && $remark === null) {
                    db()->prepare('DELETE FROM payroll_employee_components WHERE payroll_employee_id = :pe AND component_id = :c')
                        ->execute(['pe' => $employeeId, 'c' => $componentId]);
                    continue;
                }
                db()->prepare('INSERT INTO payroll_employee_components
                        (payroll_employee_id, component_id, amount, percentage, effective_from, effective_to, remarks, active, created_by, updated_by)
                        VALUES (:pe, :c, :amt, :pct, :ef, :et, :rem, 1, :by, :by2)
                        ON DUPLICATE KEY UPDATE amount = VALUES(amount), percentage = VALUES(percentage),
                            effective_from = VALUES(effective_from), effective_to = VALUES(effective_to),
                            remarks = VALUES(remarks), active = 1, updated_by = VALUES(updated_by)')
                    ->execute([
                        'pe' => $employeeId, 'c' => $componentId, 'amt' => max(0.0, $amount),
                        'pct' => $percent > 0 ? $percent : null, 'ef' => $from, 'et' => $to,
                        'rem' => $remark, 'by' => $userId, 'by2' => $userId,
                    ]);
            }
            flash('success', 'Payroll profile saved.' . ($createLogin && $linkedUserId > 0
                ? ' A user ID was created for ' . $fullName . ' (' . $employeeEmail . ') — they must change the password on first sign-in.'
                : ''));
        } catch (Throwable $exception) {
            flash('error', (string) $exception->getCode() === '23000'
                ? 'That user or employee code is already enrolled in this company.'
                : 'Could not save: ' . $exception->getMessage());
        }
        redirect('admin/payroll-employees.php');
    }

    if ($action === 'save_loan') {
        // Disbursing an advance posts money out of the bank — approver right.
        require_permission('payroll', 'post');
        $employeeId = (int) ($_POST['payroll_employee_id'] ?? 0);
        $principal = max(0.0, round((float) ($_POST['principal'] ?? 0), 2));
        $installment = max(0.0, round((float) ($_POST['monthly_installment'] ?? 0), 2));
        $employeeCheck = db()->prepare('SELECT id FROM payroll_employees WHERE id = :id AND company_id = :cid');
        $employeeCheck->execute(['id' => $employeeId, 'cid' => $companyId]);
        if (!$employeeCheck->fetchColumn() || $principal <= 0 || $installment <= 0) {
            flash('error', 'Employee, principal, and monthly installment are required for an advance.');
            redirect('admin/payroll-employees.php');
        }
        $issuedOn = trim((string) ($_POST['issued_on'] ?? '')) ?: date('Y-m-d');
        $glNote = '';
        try {
            db()->beginTransaction();
            db()->prepare("INSERT INTO payroll_loans (company_id, payroll_employee_id, title, principal, balance, monthly_installment, status, issued_on, notes)
                    VALUES (:cid, :pe, :title, :principal, :principal2, :inst, 'active', :issued, :notes)")
                ->execute([
                    'cid' => $companyId, 'pe' => $employeeId,
                    'title' => trim((string) ($_POST['title'] ?? '')) ?: 'Staff advance',
                    'principal' => $principal, 'principal2' => $principal, 'inst' => $installment,
                    'issued' => $issuedOn,
                    'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                ]);
            $loanId = (int) db()->lastInsertId();
            db()->prepare("INSERT INTO payroll_loan_txns (loan_id, txn_type, amount, txn_date, notes) VALUES (:loan, 'disbursement', :amt, :dt, 'Advance disbursed')")
                ->execute(['loan' => $loanId, 'amt' => $principal, 'dt' => $issuedOn]);
            $disbTxnId = (int) db()->lastInsertId();
            // A simple advance is money OUT of the bank into the employee-advance
            // asset — post it the moment it is given (Dr Advance / Cr Bank).
            // Recovery comes back either through payroll (accrual credits the
            // advance ledger) or the manual repayment below.
            $advSettings = payroll_settings($companyId);
            $advLedger = (int) ($advSettings['advance_ledger_id'] ?? 0);
            $bankLedger = (int) ($advSettings['bank_ledger_id'] ?? 0);
            if ($advLedger > 0 && $bankLedger > 0) {
                $advVoucherId = create_voucher_with_entries([
                    'company_id' => $companyId,
                    'fiscal_year_id' => null,
                    'voucher_no' => 'ADV-' . $loanId,
                    'voucher_type' => 'payment',
                    'voucher_date' => $issuedOn,
                    'source_type' => 'payroll_advance',
                    'source_id' => $loanId,
                    'total_amount' => $principal,
                    'narration' => 'Staff advance disbursed — ' . number_format($principal, 2) . ' (recovered at ' . number_format($installment, 2) . '/month).',
                    'status' => 'posted',
                    'posted_by' => $userId,
                ], [
                    ['ledger_id' => $advLedger, 'entry_type' => 'debit', 'amount' => $principal, 'memo' => 'Employee advance given'],
                    ['ledger_id' => $bankLedger, 'entry_type' => 'credit', 'amount' => $principal, 'memo' => 'Advance paid from bank'],
                ]);
                db()->prepare('UPDATE payroll_loan_txns SET voucher_id = :vid WHERE id = :id')->execute(['vid' => $advVoucherId, 'id' => $disbTxnId]);
                $glNote = ' Voucher ADV-' . $loanId . ' posted (Dr Advance / Cr Bank).';
            } else {
                $glNote = ' NOT posted to the books — map the Employee advance and Bank ledgers in Payroll Settings first.';
            }
            db()->commit();
        } catch (Throwable $advException) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not record the advance: ' . $advException->getMessage());
            redirect('admin/payroll-employees.php');
        }
        log_activity('payroll_loan', $loanId, 'created', 'Advance of ' . number_format($principal, 2) . ' recorded.' . $glNote, $userId);
        flash('success', 'Advance recorded. It will be recovered at ' . $sym . number_format($installment, 2) . '/month through payroll.' . $glNote);
        redirect('admin/payroll-employees.php');
    }

    if ($action === 'repay_loan') {
        require_permission('payroll', 'post');
        // Manual recovery outside payroll: the employee returns cash/bank money
        // directly. Dr Bank / Cr Employee Advance, balance reduced, settled at 0.
        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $amount = max(0.0, round((float) ($_POST['amount'] ?? 0), 2));
        $repayDate = trim((string) ($_POST['repay_date'] ?? '')) ?: date('Y-m-d');
        try {
            db()->beginTransaction();
            $loanStmt = db()->prepare("SELECT * FROM payroll_loans WHERE id = :id AND company_id = :cid AND status IN ('active', 'paused') FOR UPDATE");
            $loanStmt->execute(['id' => $loanId, 'cid' => $companyId]);
            $loan = $loanStmt->fetch();
            if (!$loan || $amount <= 0) {
                throw new RuntimeException('Active advance and a positive amount are required.');
            }
            $balance = round((float) $loan['balance'], 2);
            $applied = min($amount, $balance);
            if ($applied <= 0) {
                throw new RuntimeException('Nothing left to recover on this advance.');
            }
            $newBalance = round($balance - $applied, 2);
            db()->prepare("UPDATE payroll_loans SET balance = :nb, status = IF(:nb2 <= 0.004, 'settled', status) WHERE id = :id")
                ->execute(['nb' => $newBalance, 'nb2' => $newBalance, 'id' => $loanId]);
            db()->prepare("INSERT INTO payroll_loan_txns (loan_id, txn_type, amount, txn_date, notes) VALUES (:loan, 'recovery', :amt, :dt, 'Manual repayment (outside payroll)')")
                ->execute(['loan' => $loanId, 'amt' => $applied, 'dt' => $repayDate]);
            $repayTxnId = (int) db()->lastInsertId();
            $advSettings = payroll_settings($companyId);
            $advLedger = (int) ($advSettings['advance_ledger_id'] ?? 0);
            $bankLedger = (int) ($advSettings['bank_ledger_id'] ?? 0);
            $glNote = ' NOT posted — map the Employee advance and Bank ledgers in Payroll Settings.';
            if ($advLedger > 0 && $bankLedger > 0) {
                $repVoucherId = create_voucher_with_entries([
                    'company_id' => $companyId,
                    'fiscal_year_id' => null,
                    'voucher_no' => 'ADV-REC-' . $repayTxnId,
                    'voucher_type' => 'receipt',
                    'voucher_date' => $repayDate,
                    'source_type' => 'payroll_advance_repay',
                    'source_id' => $repayTxnId,
                    'total_amount' => $applied,
                    'narration' => 'Advance repaid by employee — ' . (string) $loan['title'] . '.',
                    'status' => 'posted',
                    'posted_by' => $userId,
                ], [
                    ['ledger_id' => $bankLedger, 'entry_type' => 'debit', 'amount' => $applied, 'memo' => 'Advance repaid into bank'],
                    ['ledger_id' => $advLedger, 'entry_type' => 'credit', 'amount' => $applied, 'memo' => 'Employee advance recovered'],
                ]);
                db()->prepare('UPDATE payroll_loan_txns SET voucher_id = :vid WHERE id = :id')->execute(['vid' => $repVoucherId, 'id' => $repayTxnId]);
                $glNote = ' Voucher ADV-REC-' . $repayTxnId . ' posted (Dr Bank / Cr Advance).';
            }
            db()->commit();
            log_activity('payroll_loan', $loanId, 'repaid', 'Manual repayment ' . number_format($applied, 2) . '.' . $glNote, $userId);
            flash('success', 'Repayment of ' . $sym . number_format($applied, 2) . ' recorded. Remaining balance: ' . $sym . number_format($newBalance, 2) . '.' . $glNote);
        } catch (Throwable $repayException) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'Could not record the repayment: ' . $repayException->getMessage());
        }
        redirect('admin/payroll-employees.php');
    }

    if ($action === 'toggle_loan') {
        require_permission('payroll', 'post');
        $loanId = (int) ($_POST['loan_id'] ?? 0);
        db()->prepare("UPDATE payroll_loans SET status = IF(status = 'paused', 'active', 'paused')
                WHERE id = :id AND company_id = :cid AND status IN ('active', 'paused')")
            ->execute(['id' => $loanId, 'cid' => $companyId]);
        flash('success', 'Advance schedule updated.');
        redirect('admin/payroll-employees.php');
    }
}

$editEmployee = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM payroll_employees WHERE id = :id AND company_id = :cid');
    $stmt->execute(['id' => $editId, 'cid' => $companyId]);
    $editEmployee = $stmt->fetch() ?: null;
}
$editAssignments = [];
if ($editEmployee) {
    $ecStmt = db()->prepare('SELECT * FROM payroll_employee_components WHERE payroll_employee_id = :pe');
    $ecStmt->execute(['pe' => (int) $editEmployee['id']]);
    foreach ($ecStmt->fetchAll() as $assignmentRow) {
        $editAssignments[(int) $assignmentRow['component_id']] = $assignmentRow;
    }
}

// Enrollable people are scoped to the ACTIVE company only — independent tenants
// must never see or enrol each other's staff/clients.
$eligibleUsersStmt = db()->prepare("SELECT u.id, u.name, u.email, u.role, c.name AS company_name
    FROM users u LEFT JOIN companies c ON c.id = u.company_id
    WHERE u.company_id = :cid AND u.role IN ('admin', 'staff', 'customer') AND u.status = 'active'
    ORDER BY FIELD(u.role, 'staff', 'admin', 'customer'), u.name ASC");
$eligibleUsersStmt->execute(['cid' => $companyId]);
$eligibleUsers = $eligibleUsersStmt->fetchAll();

$employees = payroll_company_employees($companyId, false);
$components = db()->prepare('SELECT * FROM payroll_components WHERE company_id = :cid AND active = 1 ORDER BY sort_order ASC, code ASC');
$components->execute(['cid' => $companyId]);
$components = $components->fetchAll();

$loansStmt = db()->prepare("SELECT pl.*, pe.employee_code,
        COALESCE(u.name, pe.full_name, CONCAT('Employee ', pe.employee_code)) AS person_name
    FROM payroll_loans pl
    INNER JOIN payroll_employees pe ON pe.id = pl.payroll_employee_id
    LEFT JOIN users u ON u.id = pe.user_id
    WHERE pl.company_id = :cid ORDER BY pl.status ASC, pl.created_at DESC");
$loansStmt->execute(['cid' => $companyId]);
$loans = $loansStmt->fetchAll();

$pageTitle = 'Payroll Employees';
$pageSubtitle = 'Add employees for payroll — with or without a user ID — set salary, tax status, retirement scheme, and advances.';
$pageHero = ['icon' => 'users'];
$bodyClass = 'admin-layout accounting-module-page payroll-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card" aria-label="Enrol employee">
    <div class="mbw-card-head">
        <h2><?= $editEmployee ? 'Edit payroll profile' : 'Add employee to payroll' ?></h2>
        <div class="mbw-card-tools">
            <?php if ($editEmployee): ?><a class="mbw-view-all" href="<?= e(url('admin/payroll-employees.php')) ?>">Cancel edit</a><?php endif; ?>
            <a class="mbw-view-all" href="<?= e(url('admin/payroll.php')) ?>">Payroll Processing &#8594;</a>
        </div>
    </div>
    <form method="post" class="workspace-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_employee">
        <input type="hidden" name="employee_id" value="<?= e((int) ($editEmployee['id'] ?? 0)) ?>">
        <?php
        // Preselect the identity mode: linked employees edit in "existing"
        // mode, login-less ones in "new" mode. Fresh forms default to "new"
        // (add an employee for payroll, optionally give them a user ID).
        $peEditHasUser = (int) ($editEmployee['user_id'] ?? 0) > 0;
        $peIdentityMode = $editEmployee ? ($peEditHasUser ? 'existing' : 'new') : 'new';
        ?>
        <label>Employee identity
            <select name="identity_mode" id="pe-identity-mode">
                <option value="new" <?= $peIdentityMode === 'new' ? 'selected' : '' ?>>New employee (payroll only, no login needed)</option>
                <option value="existing" <?= $peIdentityMode === 'existing' ? 'selected' : '' ?>>Link an existing user of this company</option>
            </select>
        </label>
        <label class="pe-existing-only">Person (staff / client)
            <select name="user_id" id="pe-user-select">
                <option value="">Select existing user...</option>
                <?php foreach ($eligibleUsers as $eligibleUser): ?>
                    <option value="<?= e((int) $eligibleUser['id']) ?>" <?= (int) ($editEmployee['user_id'] ?? 0) === (int) $eligibleUser['id'] ? 'selected' : '' ?>>
                        <?= e($eligibleUser['name'] . ' — ' . ($eligibleUser['role'] === 'customer' ? 'Client' : ucfirst((string) $eligibleUser['role'])) . ($eligibleUser['company_name'] ? ' (' . $eligibleUser['company_name'] . ')' : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="pe-new-only">Full name<input type="text" name="full_name" id="pe-full-name" maxlength="160" value="<?= e($editEmployee['full_name'] ?? '') ?>" placeholder="Employee's full name"></label>
        <label class="pe-new-only">Email<?= $isAdmin ? ' (required for a user ID)' : '' ?><input type="email" name="employee_email" id="pe-employee-email" maxlength="190" value="<?= e($editEmployee['email'] ?? '') ?>" placeholder="Optional"></label>
        <label class="pe-new-only">Phone<input type="text" name="employee_phone" maxlength="60" value="<?= e($editEmployee['phone'] ?? '') ?>" placeholder="Optional"></label>
        <?php if ($isAdmin && !$peEditHasUser): ?>
            <label class="checkbox-line pe-new-only">
                <input type="checkbox" name="create_login" id="pe-create-login" value="1">
                Create user ID (portal login) for this employee
            </label>
            <label class="pe-login-only">Login password (min 8 characters)
                <input type="password" name="login_password" id="pe-login-password" minlength="8" autocomplete="new-password" placeholder="They must change it on first sign-in">
            </label>
        <?php elseif ($peEditHasUser && $peIdentityMode === 'new'): ?>
            <p class="pe-new-only muted workspace-span-2" style="margin:0;font-size:12px">This employee already has a user ID linked.</p>
        <?php endif; ?>
        <label>Employee code<input type="text" name="employee_code" maxlength="40" value="<?= e($editEmployee['employee_code'] ?? '') ?>" required placeholder="E001"></label>
        <label>Department<input type="text" name="department" maxlength="120" value="<?= e($editEmployee['department'] ?? '') ?>"></label>
        <label>Designation<input type="text" name="designation" maxlength="120" value="<?= e($editEmployee['designation'] ?? '') ?>"></label>
        <label>Basic salary (monthly)<input type="number" step="0.01" min="0" name="basic_salary" value="<?= e(number_format((float) ($editEmployee['basic_salary'] ?? 0), 2, '.', '')) ?>" required></label>
        <label>Tax category
            <select name="marital_status">
                <option value="individual" <?= ($editEmployee['marital_status'] ?? '') === 'individual' ? 'selected' : '' ?>>Individual</option>
                <option value="couple" <?= ($editEmployee['marital_status'] ?? '') === 'couple' ? 'selected' : '' ?>>Couple</option>
            </select>
        </label>
        <label>PAN<input type="text" name="pan_no" maxlength="40" value="<?= e($editEmployee['pan_no'] ?? '') ?>"></label>
        <label>Bank name<input type="text" name="bank_name" maxlength="120" value="<?= e($editEmployee['bank_name'] ?? '') ?>"></label>
        <label>Bank account<input type="text" name="bank_account" maxlength="60" value="<?= e($editEmployee['bank_account'] ?? '') ?>"></label>
        <label>Retirement scheme
            <select name="retirement_scheme">
                <?php foreach (['none' => 'None', 'ssf' => 'SSF (Social Security Fund)', 'pf' => 'Provident Fund', 'cit' => 'CIT'] as $schemeValue => $schemeLabel): ?>
                    <option value="<?= e($schemeValue) ?>" <?= ($editEmployee['retirement_scheme'] ?? 'none') === $schemeValue ? 'selected' : '' ?>><?= e($schemeLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Scheme ID<input type="text" name="retirement_id" maxlength="60" value="<?= e($editEmployee['retirement_id'] ?? '') ?>"></label>
        <label>Employee contribution % of basic<input type="number" step="0.01" min="0" name="retirement_employee_rate" value="<?= e(number_format((float) ($editEmployee['retirement_employee_rate'] ?? 0), 2, '.', '')) ?>" placeholder="e.g. 11"></label>
        <label>Employer contribution % of basic<input type="number" step="0.01" min="0" name="retirement_employer_rate" value="<?= e(number_format((float) ($editEmployee['retirement_employer_rate'] ?? 0), 2, '.', '')) ?>" placeholder="e.g. 20"></label>
        <label>Joined on<input type="date" name="joined_on" value="<?= e($editEmployee['joined_on'] ?? '') ?>"></label>
        <label>Status
            <select name="status">
                <option value="active" <?= ($editEmployee['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= ($editEmployee['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </label>
        <label>Overtime hourly rate <small>(optional — blank derives from basic salary)</small><input type="number" step="0.0001" min="0" name="ot_hourly_rate" value="<?= ($editEmployee['ot_hourly_rate'] ?? null) !== null ? e(number_format((float) $editEmployee['ot_hourly_rate'], 4, '.', '')) : '' ?>"></label>
        <label class="checkbox-line" style="align-self:end"><input type="checkbox" name="sc_eligible" <?= (int) ($editEmployee['sc_eligible'] ?? 1) === 1 ? 'checked' : '' ?>> Eligible for service charge</label>
        <?php if ($components !== []): ?>
            <div class="workspace-span-2">
                <strong style="font-size:13px;color:var(--mbw-heading)">Pay structure — component suggestions</strong>
                <p style="margin:4px 0 8px;color:var(--mbw-muted);font-size:12px">
                    Amounts here are payroll-preparation SUGGESTIONS, not fixed values — each month they can be accepted,
                    changed, zeroed, or removed on the salary sheet (with an audited reason). Blank every field to remove
                    the assignment. Overtime and service-charge components fill from their own workflows.
                </p>
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>Component</th><th class="is-numeric">Suggested amount</th><th class="is-numeric">Suggested % of basic</th><th>From</th><th>To</th><th>Remarks</th></tr></thead>
                    <tbody>
                        <?php foreach ($components as $component): ?>
                            <?php if (in_array((string) $component['calc_type'], ['overtime_hours', 'service_charge'], true)) { continue; } ?>
                            <?php $assignment = $editAssignments[(int) $component['id']] ?? null; ?>
                            <tr>
                                <td><?= e($component['name']) ?> <small>(<?= e(str_replace('_', ' ', (string) $component['category'])) ?><?= (int) $component['taxable'] ? '' : ', non-taxable' ?>)</small></td>
                                <td class="is-numeric"><input type="number" step="0.01" min="0" name="comp_amount[<?= e((int) $component['id']) ?>]" style="max-width:130px" value="<?= $assignment !== null && (float) $assignment['amount'] > 0 ? e(number_format((float) $assignment['amount'], 2, '.', '')) : '' ?>" placeholder="<?= (string) $component['calc_type'] === 'percent_basic' ? e((($component['percentage'] ?? null) !== null && (float) $component['percentage'] > 0 ? $component['percentage'] : $component['default_value']) . '% of basic') : e(number_format((float) $component['default_value'], 2)) ?>"></td>
                                <td class="is-numeric"><input type="number" step="0.0001" min="0" name="comp_percent[<?= e((int) $component['id']) ?>]" style="max-width:110px" value="<?= $assignment !== null && ($assignment['percentage'] ?? null) !== null ? e(number_format((float) $assignment['percentage'], 4, '.', '')) : '' ?>" <?= (int) ($component['allow_employee_override'] ?? 1) === 1 ? '' : 'readonly' ?>></td>
                                <td><input type="date" name="comp_from[<?= e((int) $component['id']) ?>]" style="max-width:150px" value="<?= e($assignment['effective_from'] ?? '') ?>"></td>
                                <td><input type="date" name="comp_to[<?= e((int) $component['id']) ?>]" style="max-width:150px" value="<?= e($assignment['effective_to'] ?? '') ?>"></td>
                                <td><input type="text" name="comp_remarks[<?= e((int) $component['id']) ?>]" maxlength="255" style="max-width:180px" value="<?= e($assignment['remarks'] ?? '') ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endif; ?>
        <label class="workspace-span-2">Notes<textarea name="notes"><?= e($editEmployee['notes'] ?? '') ?></textarea></label>
        <div class="workspace-span-2"><button type="submit"><?= icon('users') ?>Save Payroll Profile</button></div>
    </form>
    <script>
    (function () {
        var mode = document.getElementById('pe-identity-mode');
        if (!mode) { return; }
        var createLogin = document.getElementById('pe-create-login');
        function show(el, on) { el.style.display = on ? '' : 'none'; }
        function sync() {
            var isNew = mode.value === 'new';
            document.querySelectorAll('.pe-existing-only').forEach(function (el) { show(el, !isNew); });
            document.querySelectorAll('.pe-new-only').forEach(function (el) { show(el, isNew); });
            var wantLogin = isNew && createLogin && createLogin.checked;
            document.querySelectorAll('.pe-login-only').forEach(function (el) { show(el, wantLogin); });
            var userSelect = document.getElementById('pe-user-select');
            if (userSelect) { userSelect.required = !isNew; }
            var fullName = document.getElementById('pe-full-name');
            if (fullName) { fullName.required = isNew; }
            var email = document.getElementById('pe-employee-email');
            if (email) { email.required = wantLogin; }
            var password = document.getElementById('pe-login-password');
            if (password) { password.required = wantLogin; }
        }
        mode.addEventListener('change', sync);
        if (createLogin) { createLogin.addEventListener('change', sync); }
        sync();
    })();
    </script>
</section>

<section class="mbw-card" aria-label="Enrolled employees">
    <div class="mbw-card-head"><h2>Enrolled Employees (<?= count($employees) ?>)</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Code</th><th>Name</th><th>Role</th><th>Department</th><th>Tax cat.</th><th>Scheme</th><th class="is-numeric">Basic (<?= e($sym) ?>)</th><th>PAN</th><th>Status</th><th></th></tr></thead>
        <tbody>
            <?php if ($employees === []): ?><tr><td colspan="10">Nobody is enrolled yet. Salary can only be processed for people enrolled here.</td></tr><?php endif; ?>
            <?php foreach ($employees as $employee): ?>
                <tr>
                    <td><strong><?= e($employee['employee_code']) ?></strong></td>
                    <td><?= e($employee['person_name']) ?></td>
                    <?php
                    $peRoleTone = $employee['person_role'] === 'customer' ? 'tone-blue' : ($employee['person_role'] === 'employee' ? 'tone-gray' : 'tone-green');
                    $peRoleLabel = $employee['person_role'] === 'customer' ? 'Client' : ($employee['person_role'] === 'employee' ? 'Employee (no login)' : ucfirst((string) $employee['person_role']));
                    ?>
                    <td><span class="mbw-pill <?= $peRoleTone ?>"><?= e($peRoleLabel) ?></span></td>
                    <td><?= e($employee['department'] ?? '-') ?></td>
                    <td><?= e(ucfirst((string) $employee['marital_status'])) ?></td>
                    <td><?= e(strtoupper((string) $employee['retirement_scheme'])) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $employee['basic_salary'], 2)) ?></td>
                    <td><?= e($employee['pan_no'] ?? '') !== '' ? e($employee['pan_no']) : '<span class="mbw-pill tone-amber">Missing</span>' ?></td>
                    <td><span class="mbw-pill <?= $employee['status'] === 'active' ? 'tone-green' : 'tone-red' ?>"><?= e(ucfirst((string) $employee['status'])) ?></span></td>
                    <td style="white-space:nowrap"><a class="button secondary" href="<?= e(url('admin/payroll-employees.php?edit=' . (int) $employee['id'])) ?>">Edit</a>
                        <?php if ($isAdmin): ?>
                            <a class="button secondary" href="<?= e(url('admin/payroll-employee-sheet.php?employee=' . (int) $employee['id'])) ?>" title="Month-by-month salary record with payslips">Salary sheet</a>
                        <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<style>
.pr-adjust{position:relative}
.pr-adjust>summary{list-style:none;cursor:pointer}.pr-adjust>summary::-webkit-details-marker{display:none}
.pr-adjust-form{position:absolute;right:0;z-index:40;margin-top:6px;display:grid;gap:8px;padding:12px;text-align:left;
    background:var(--mbw-card,#fff);color:var(--mbw-ink,#12261f);border:1px solid var(--mbw-line,rgba(0,0,0,.16));border-radius:10px;box-shadow:0 14px 34px rgba(0,0,0,.22)}
.pr-adjust-form label{display:grid;gap:3px;font-size:12px;font-weight:600}
.pr-adjust-form input{min-height:34px}.pr-adjust-form small{color:var(--mbw-muted,#5b6b64);font-weight:400;font-size:11px}
</style>
<section class="mbw-card" aria-label="Advances and loans">
    <div class="mbw-card-head"><h2>Advances / Loans</h2></div>
    <form method="post" class="workspace-form-grid" style="margin-bottom:14px">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_loan">
        <label>Employee
            <select name="payroll_employee_id" required>
                <option value="">Select...</option>
                <?php foreach ($employees as $employee): ?>
                    <option value="<?= e((int) $employee['id']) ?>"><?= e($employee['employee_code'] . ' — ' . $employee['person_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Title<input type="text" name="title" maxlength="160" placeholder="Staff advance"></label>
        <label>Principal<input type="number" step="0.01" min="0.01" name="principal" required></label>
        <label>Monthly installment<input type="number" step="0.01" min="0.01" name="monthly_installment" required></label>
        <label>Issued on<input type="date" name="issued_on" value="<?= e(date('Y-m-d')) ?>"></label>
        <button type="submit"><?= icon('plus') ?>Record Advance</button>
    </form>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Employee</th><th>Title</th><th class="is-numeric">Principal</th><th class="is-numeric">Balance</th><th class="is-numeric">Installment</th><th>Status</th><th></th></tr></thead>
        <tbody>
            <?php if ($loans === []): ?><tr><td colspan="7">No advances recorded. Payroll automatically recovers active advances each run, capped at the outstanding balance.</td></tr><?php endif; ?>
            <?php foreach ($loans as $loan): ?>
                <tr>
                    <td><?= e($loan['employee_code'] . ' — ' . $loan['person_name']) ?></td>
                    <td><?= e($loan['title']) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $loan['principal'], 2)) ?></td>
                    <td class="is-numeric"><strong><?= e(number_format((float) $loan['balance'], 2)) ?></strong></td>
                    <td class="is-numeric"><?= e(number_format((float) $loan['monthly_installment'], 2)) ?></td>
                    <td><span class="mbw-pill <?= $loan['status'] === 'active' ? 'tone-green' : ($loan['status'] === 'settled' ? 'tone-blue' : 'tone-amber') ?>"><?= e(ucfirst((string) $loan['status'])) ?></span></td>
                    <td style="white-space:nowrap">
                        <?php if (in_array((string) $loan['status'], ['active', 'paused'], true)): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle_loan">
                                <input type="hidden" name="loan_id" value="<?= e((int) $loan['id']) ?>">
                                <button type="submit" class="button secondary"><?= $loan['status'] === 'paused' ? 'Resume' : 'Pause' ?></button>
                            </form>
                            <details class="pr-adjust" style="display:inline-block">
                                <summary class="button secondary" style="display:inline-flex;align-items:center">Repay…</summary>
                                <form method="post" class="pr-adjust-form" style="width:250px" data-confirm="Record a manual repayment against this advance? Dr Bank / Cr Employee Advance is posted.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="repay_loan">
                                    <input type="hidden" name="loan_id" value="<?= e((int) $loan['id']) ?>">
                                    <label>Amount (max <?= e(number_format((float) $loan['balance'], 2)) ?>)<input type="number" step="0.01" min="0.01" max="<?= e(number_format((float) $loan['balance'], 2, '.', '')) ?>" name="amount" required></label>
                                    <label>Date<input type="date" name="repay_date" value="<?= e(date('Y-m-d')) ?>"></label>
                                    <button type="submit">Record repayment</button>
                                    <small>For cash returned outside payroll. Payroll runs keep recovering the installment automatically.</small>
                                </form>
                            </details>
                        <?php else: ?>–<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
