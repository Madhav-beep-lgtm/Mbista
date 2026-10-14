<?php
declare(strict_types=1);

/**
 * The Platform Admin's way in: every company, in one list.
 *
 * It replaces two detours. portal.php asked which "workspace" to enter before
 * showing anything, and the sidebar carried a "Select client..." dropdown that
 * was a second route to the same place — offered from inside whatever ledger
 * you happened to be reading. Neither said what a company IS: the firm, the
 * holding company, a subsidiary, or a client whose books the firm keeps.
 *
 * Two actions on every owning entity:
 *   Open books      — that company's own accounting
 *   Open companies  — the clients it serves, each openable in turn
 *
 * Nothing here decides who may open what. handle_company_switch() checks
 * membership before opening the selected company; this page only decides
 * what to list.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();

if (!user_is_super_admin()) {
    redirect('admin/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    handle_company_switch((int) ($_POST['company_id'] ?? 0), 'platform/index.php');
}

$authorized = authorized_companies();
$authorizedById = [];
foreach ($authorized as $row) {
    $authorizedById[(int) $row['id']] = $row;
}

/** The client books this company keeps, keyed by books company id. */
$clientsOf = static function (int $companyId) use ($authorizedById): array {
    if (!table_exists('client_profiles')) {
        return [];
    }
    $stmt = db()->prepare('SELECT cp.books_company_id, cp.organization_name, c.code, c.name
        FROM client_profiles cp
        INNER JOIN companies c ON c.id = cp.books_company_id
        WHERE cp.company_id = :cid AND cp.is_active = 1 AND c.is_active = 1
        ORDER BY c.name ASC');
    $stmt->execute(['cid' => $companyId]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $client) {
        $booksId = (int) $client['books_company_id'];
        // A client this admin could not open is left off rather than offered
        // as a door handle_company_switch() would refuse.
        if ($booksId === $companyId || !isset($authorizedById[$booksId])) {
            continue;
        }
        $out[$booksId] = $client;
    }

    return $out;
};

/** How many users belong to a company. */
$userCountOf = static function (int $companyId): int {
    $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE company_id = :cid AND status = :st');
    $stmt->execute(['cid' => $companyId, 'st' => 'active']);

    return (int) $stmt->fetchColumn();
};

$servingId = (int) ($_GET['serving'] ?? 0);
$servingCompany = $servingId > 0 ? ($authorizedById[$servingId] ?? null) : null;

// The firm first, then the holding company with its subsidiaries beneath it,
// then anything else that keeps books. This is the order the reader thinks in.
$ordered = [];
$seen = [];
$addCompany = static function (?array $company, int $depth) use (&$ordered, &$seen): void {
    if ($company === null) { return; }
    $id = (int) $company['id'];
    if ($id <= 0 || isset($seen[$id])) { return; }
    $seen[$id] = true;
    $ordered[] = ['company' => $company, 'depth' => $depth];
};
if (!$servingCompany) {
    $addCompany($authorizedById[(int) (company_by_code('MBAACA')['id'] ?? 0)] ?? null, 0);
    $altiora = $authorizedById[(int) (company_by_code('AGHPL')['id'] ?? 0)] ?? null;
    $addCompany($altiora, 0);
    if ($altiora !== null) {
        foreach ($authorized as $candidate) {
            if ((int) ($candidate['parent_company_id'] ?? 0) === (int) $altiora['id']) {
                $addCompany($candidate, 1);
            }
        }
    }
    foreach ($authorized as $candidate) {
        if ((int) ($candidate['is_client_company'] ?? 0) === 1) { continue; }
        $addCompany($candidate, 0);
    }
}

$rows = $servingCompany ? $clientsOf($servingId) : $ordered;

$platformShell = true;
$pageTitle = $servingCompany ? (string) $servingCompany['name'] : 'Companies';
$pageBreadcrumb = $servingCompany
    ? [['Platform', 'platform/index.php'], ['Companies', 'platform/index.php']]
    : [['Platform', 'platform/index.php']];
$pageHeadActions = '<a class="button secondary" href="' . e(url('platform/companies.php')) . '">New company</a>';
$pageHero = false;
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="actions"><?= $pageHeadActions ?></div>

<section class="mbw-card">
    <?php if ($servingCompany): ?>
        <div class="mbw-card-head">
            <h2><?= e((string) $servingCompany['name']) ?></h2>
            <a class="mbw-view-all" href="<?= e(url('platform/index.php')) ?>">All companies</a>
        </div>
    <?php endif; ?>
    <div class="mbw-tablewrap">
        <table>
            <thead>
                <tr>
                    <th>Company</th>
                    <th>Company code</th>
                    <th><?= $servingCompany ? 'Client' : 'Type' ?></th>
                    <th class="is-numeric">Users</th>
                    <?php if (!$servingCompany): ?><th class="is-numeric">Clients</th><?php endif; ?>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7"><?= $servingCompany ? 'No client books under this company.' : 'No companies.' ?></td></tr>
                <?php endif; ?>

                <?php foreach ($rows as $key => $entry): ?>
                    <?php
                    if ($servingCompany) {
                        $companyId = (int) $key;
                        $company = $authorizedById[$companyId];
                        $depth = 0;
                        $thirdCell = (string) $entry['organization_name'];
                        $clientCount = 0;
                    } else {
                        $company = $entry['company'];
                        $companyId = (int) $company['id'];
                        $depth = (int) $entry['depth'];
                        $thirdCell = $depth > 0 ? 'Subsidiary' : ((int) ($company['is_client_company'] ?? 0) === 1 ? 'Client books' : 'Company');
                        $clientCount = count($clientsOf($companyId));
                    }
                    $isActive = (int) ($company['is_active'] ?? 0) === 1;
                    ?>
                    <tr>
                        <td<?= $depth > 0 ? ' style="padding-left:28px"' : '' ?>><?= e((string) $company['name']) ?></td>
                        <td><?= e((string) $company['code']) ?></td>
                        <td><?= e($thirdCell) ?></td>
                        <td class="is-numeric"><?= $userCountOf($companyId) ?></td>
                        <?php if (!$servingCompany): ?><td class="is-numeric"><?= $clientCount ?></td><?php endif; ?>
                        <td>
                            <span class="mbw-pill <?= $isActive ? 'tone-green' : 'tone-gray' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                        </td>
                        <td>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="company_id" value="<?= $companyId ?>">
                                <button type="submit" class="button secondary">Open books</button>
                            </form>
                            <a class="button secondary" href="<?= e(url('platform/company-modules.php?company_id=' . $companyId)) ?>">Modules</a>
                            <a class="button secondary" href="<?= e(url('platform/company-settings.php?company_id=' . $companyId)) ?>">Company details</a>
                            <?php if (!$servingCompany && $clientCount > 0): ?>
                                <a class="button secondary" href="<?= e(url('platform/index.php?serving=' . $companyId)) ?>">Open companies</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="frm-optional" style="margin:10px 0 0"><?= count($rows) ?> <?= count($rows) === 1 ? 'company' : 'companies' ?></p>
</section>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
