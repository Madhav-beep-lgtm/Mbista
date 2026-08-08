<?php
declare(strict_types=1);

/**
 * Unified party identity acceptance suite (CLI, self-contained).
 *
 * Verifies that one client profile resolves to one accounting party and one
 * Trade Receivables ledger, task invoices use that same party id, renamed
 * clients retain their link, and ambiguous legacy names are never guessed.
 *
 *   php database/test_unified_party_identity.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';

$pass = 0;
$fail = 0;

function unified_ok(bool $condition, string $label): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  PASS  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL  {$label}\n";
    }
}

function unified_cleanup(): void
{
    $companyIds = db()->query("SELECT id FROM companies WHERE code = 'UPTY107'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($companyIds as $companyId) {
        $companyId = (int) $companyId;
        db()->exec("DELETE FROM task_invoices WHERE company_id = {$companyId}");
        db()->exec("DELETE FROM client_tasks WHERE company_id = {$companyId}");
        db()->exec("DELETE FROM accounting_parties WHERE company_id = {$companyId}");
        db()->exec("DELETE FROM ledgers WHERE company_id = {$companyId}");
        db()->exec("DELETE FROM ledger_groups WHERE company_id = {$companyId}");
        db()->exec("DELETE FROM client_profiles WHERE company_id = {$companyId}");
        db()->exec("DELETE FROM users WHERE company_id = {$companyId}");
        db()->exec("DELETE FROM companies WHERE id = {$companyId}");
    }
}

unified_cleanup();

try {
    echo "Repair and migration\n";
    $repairErrors = accounting_module_repair_database();
    unified_ok($repairErrors === [], 'Accounting repair, including migration 107, completes without error');
    if ($repairErrors !== []) {
        foreach ($repairErrors as $repairError) {
            echo "        {$repairError}\n";
        }
    }

    $indexStmt = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = :database_name
           AND table_name = :table_name
           AND index_name = :index_name'
    );
    $indexStmt->execute([
        'database_name' => DB_NAME,
        'table_name' => 'accounting_parties',
        'index_name' => 'uniq_accounting_parties_company_client',
    ]);
    unified_ok((int) $indexStmt->fetchColumn() > 0, 'One-client-per-company unique index exists');

    echo "\nFixture\n";
    db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Unified Party Test Co', 'UPTY107', 1)")->execute();
    $companyId = (int) db()->lastInsertId();
    $_SESSION['company_id'] = $companyId;

    $userInsert = db()->prepare(
        "INSERT INTO users (name, email, password_hash, role, status, company_id, phone)
         VALUES (:name, :email, :password_hash, 'customer', 'active', :company_id, :phone)"
    );
    $userInsert->execute([
        'name' => 'Unified Client One',
        'email' => 'unified-party-107-a@example.test',
        'password_hash' => password_hash('test-only', PASSWORD_DEFAULT),
        'company_id' => $companyId,
        'phone' => '9800000107',
    ]);
    $userOneId = (int) db()->lastInsertId();

    $userInsert->execute([
        'name' => 'Ambiguous Client',
        'email' => 'unified-party-107-b@example.test',
        'password_hash' => password_hash('test-only', PASSWORD_DEFAULT),
        'company_id' => $companyId,
        'phone' => '9800000108',
    ]);
    $userTwoId = (int) db()->lastInsertId();

    $profileInsert = db()->prepare(
        'INSERT INTO client_profiles
            (user_id, company_id, organization_name, client_code, address, pan_no)
         VALUES
            (:user_id, :company_id, :organization_name, :client_code, :address, :pan_no)'
    );
    $profileInsert->execute([
        'user_id' => $userOneId,
        'company_id' => $companyId,
        'organization_name' => 'Unified Party Test Client',
        'client_code' => 'UPTY-C1',
        'address' => 'Kathmandu',
        'pan_no' => 'UPTY107001',
    ]);
    $clientOneId = (int) db()->lastInsertId();

    $profileInsert->execute([
        'user_id' => $userTwoId,
        'company_id' => $companyId,
        'organization_name' => 'Ambiguous Unified Client',
        'client_code' => 'UPTY-C2',
        'address' => 'Lalitpur',
        'pan_no' => 'UPTY107002',
    ]);
    $clientTwoId = (int) db()->lastInsertId();

    echo "\nCanonical party and ledger\n";
    $partyId = ensure_party_for_client($companyId, $clientOneId);
    unified_ok($partyId > 0, 'Client creates a canonical Party Master record');

    $partyStmt = db()->prepare('SELECT * FROM accounting_parties WHERE id = :id AND company_id = :company_id');
    $partyStmt->execute(['id' => $partyId, 'company_id' => $companyId]);
    $party = $partyStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    unified_ok((int) ($party['client_profile_id'] ?? 0) === $clientOneId, 'Party stores the permanent client_profile_id link');
    unified_ok((string) ($party['party_type'] ?? '') === 'customer', 'New portal client is classified as a customer');
    unified_ok((string) ($party['pan_no'] ?? '') === 'UPTY107001', 'PAN is copied into the canonical Party Master');
    unified_ok((string) ($party['billing_address'] ?? '') === 'Kathmandu', 'Address is copied into the canonical Party Master');

    $ledgerId = (int) ($party['ledger_id'] ?? 0);
    $ledgerStmt = db()->prepare(
        'SELECT l.type, l.name, g.name AS group_name
         FROM ledgers l
         INNER JOIN ledger_groups g ON g.id = l.group_id
         WHERE l.id = :id AND l.company_id = :company_id'
    );
    $ledgerStmt->execute(['id' => $ledgerId, 'company_id' => $companyId]);
    $ledger = $ledgerStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    unified_ok(
        $ledgerId > 0
        && (string) ($ledger['type'] ?? '') === 'asset'
        && (string) ($ledger['group_name'] ?? '') === 'Trade Receivables',
        'Canonical party owns one asset ledger under Trade Receivables'
    );

    $samePartyId = ensure_party_for_client($companyId, $clientOneId);
    $partyCountStmt = db()->prepare('SELECT COUNT(*) FROM accounting_parties WHERE company_id = :company_id AND client_profile_id = :client_id');
    $partyCountStmt->execute(['company_id' => $companyId, 'client_id' => $clientOneId]);
    unified_ok($samePartyId === $partyId && (int) $partyCountStmt->fetchColumn() === 1, 'Repeated resolution reuses one party without duplication');

    db()->prepare('UPDATE client_profiles SET organization_name = :name WHERE id = :id')
        ->execute(['name' => 'Unified Party Client Renamed', 'id' => $clientOneId]);
    unified_ok(
        ensure_party_for_client($companyId, $clientOneId) === $partyId,
        'Renaming the client does not detach it from its financial history'
    );

    echo "\nTask invoice route\n";
    db()->prepare(
        "INSERT INTO client_tasks (company_id, client_id, title, quoted_fee, status)
         VALUES (:company_id, :client_id, 'Unified party test task', 1000, 'new')"
    )->execute(['company_id' => $companyId, 'client_id' => $clientOneId]);
    $taskId = (int) db()->lastInsertId();
    unified_ok(
        invoice_party_id(['task_id' => $taskId], $companyId) === $partyId,
        'Task invoice resolves to the same canonical Party Master id'
    );

    echo "\nAmbiguous legacy names\n";
    $legacyInsert = db()->prepare(
        "INSERT INTO accounting_parties (company_id, code, name, party_type, status)
         VALUES (:company_id, :code, 'Ambiguous Unified Client', 'customer', 'active')"
    );
    $legacyInsert->execute(['company_id' => $companyId, 'code' => 'UPTY-A1']);
    $legacyOneId = (int) db()->lastInsertId();
    $legacyInsert->execute(['company_id' => $companyId, 'code' => 'UPTY-A2']);

    unified_ok(
        ensure_party_for_client($companyId, $clientTwoId) === 0,
        'Two same-name legacy parties are not guessed or silently merged'
    );
    $partyCountStmt->execute(['company_id' => $companyId, 'client_id' => $clientTwoId]);
    unified_ok((int) $partyCountStmt->fetchColumn() === 0, 'Ambiguous client remains visibly unlinked for review');

    db()->prepare(
        'UPDATE accounting_parties
         SET client_profile_id = :client_id
         WHERE id = :id AND company_id = :company_id'
    )->execute(['client_id' => $clientTwoId, 'id' => $legacyOneId, 'company_id' => $companyId]);
    unified_ok(
        ensure_party_for_client($companyId, $clientTwoId) === $legacyOneId,
        'A controlled manual link becomes authoritative despite duplicate names'
    );

    echo "\nUI safeguards\n";
    $partyPage = (string) file_get_contents(__DIR__ . '/../public_html/admin/accounting-parties.php');
    unified_ok(
        str_contains($partyPage, "party_link_status")
        && !str_contains($partyPage, "accounting_party_id'] ?: (\$document['client_profile_id"),
        'Party screen never treats client_profiles.id as accounting_parties.id'
    );

    $invoicePage = (string) file_get_contents(__DIR__ . '/../public_html/admin/invoice.php');
    unified_ok(
        str_contains($invoicePage, 'could not be linked to one unambiguous Party Master record'),
        'Invoice creation blocks an unresolved task client instead of posting to a generic ledger'
    );
} catch (Throwable $exception) {
    $fail++;
    echo "  FAIL  Unhandled exception: {$exception->getMessage()}\n";
} finally {
    unified_cleanup();
}

echo "\n----------------------------------------\n";
echo "PASS: {$pass}   FAIL: {$fail}\n";
exit($fail === 0 ? 0 : 1);
