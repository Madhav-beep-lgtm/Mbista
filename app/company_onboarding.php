<?php
declare(strict_types=1);

function company_onboarding_values(array $input): array
{
    $name = trim((string)($input['user_name'] ?? ''));
    $email = trim((string)($input['user_email'] ?? ''));
    $password = (string)($input['temporary_password'] ?? '');
    if ($name === '' || mb_strlen($name) > 150 || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter the first user’s name and valid email.');
    if (strlen($password) < 12 || strlen($password) > 72 || !preg_match('/[A-Z]/',$password) || !preg_match('/[a-z]/',$password) || !preg_match('/[0-9]/',$password)) throw new RuntimeException('Temporary password must be 12–72 characters with uppercase, lowercase and a number.');
    $pan = trim((string)($input['company_pan'] ?? ''));
    if (mb_strlen($pan) > 60) throw new RuntimeException('PAN must not exceed 60 characters.');
    $banks = [];
    $rows = $input['banks'] ?? [];
    if (!is_array($rows) || count($rows) > 20) throw new RuntimeException('Enter up to 20 bank accounts.');
    foreach ($rows as $row) {
        if (!is_array($row)) throw new RuntimeException('Invalid bank account.');
        $bank = [];
        foreach (['bank_name'=>160,'account_name'=>190,'account_number'=>80,'branch'=>160] as $field=>$max) {
            $bank[$field] = trim((string)($row[$field] ?? ''));
            if (mb_strlen($bank[$field]) > $max) throw new RuntimeException('Bank account details are too long.');
        }
        if (implode('',$bank) === '') continue;
        if ($bank['bank_name'] === '' || $bank['account_name'] === '' || $bank['account_number'] === '') throw new RuntimeException('Each bank account needs bank name, account name and account number.');
        $banks[] = $bank;
    }
    return ['name'=>$name,'email'=>$email,'password'=>$password,'pan'=>$pan,'banks'=>$banks];
}

/** Caller owns the transaction: a company must not be left without its owner. */
function company_onboarding_save(int $companyId, array $company, array $onboarding, int $actorId): void
{
    $ownerId = create_user(['name'=>$onboarding['name'],'email'=>$onboarding['email'],'password'=>$onboarding['password'],'role'=>'customer','status'=>'active','company_id'=>$companyId,'company'=>$company['name'],'phone'=>$company['support_phone'],'must_change_password'=>1]);
    db()->prepare('INSERT INTO client_profiles (user_id, books_company_id, organization_name, address, pan_no, contact_number, client_status, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)')
        ->execute([$ownerId,$companyId,$company['name'],$company['office_address'],$onboarding['pan'],$company['support_phone'],'active']);
    foreach ($onboarding['banks'] as $index=>$bank) {
        db()->prepare('INSERT INTO company_bank_accounts (company_id, bank_name, account_name, account_number, branch, is_default, show_on_invoice, sort_order, active, created_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?, 1, ?)')
            ->execute([$companyId,$bank['bank_name'],$bank['account_name'],$bank['account_number'],$bank['branch'],$index === 0 ? 1 : 0,$index,$actorId]);
    }
}
