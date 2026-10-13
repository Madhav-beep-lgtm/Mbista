<?php
declare(strict_types=1);

/**
 * The Platform Admin's way in: every company, in one list.
 *
 * It replaces two detours. portal.php asked which "workspace" to enter before
 * showing anything, and the header carried a "Select client..." dropdown that
 * was a second, different way to answer the same question. Neither said what a
 * company IS — the firm, a holding company, a subsidiary, or a client whose
 * books the firm keeps — so the one screen that should answer "where am I
 * working today" answered it twice and explained neither.
 *
 * Two actions on every owning entity:
 *   Open books      — that company's own accounting
 *   Open companies  — the clients it serves, each openable in turn
 *
 * Nothing here decides who may open what. handle_company_switch() checks
 * membership and then the company PIN, exactly as it did for portal.php; this
 * page only decides what to LIST.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();

// The platform list is the Platform Admin's. Everyone else keeps the portal
// they already had rather than being shown a door they cannot open.
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

/** How many client books this company keeps, and which. */
$clientsOf = static function (int $companyId) use ($authorizedById): array {
    if (!table_exists('client_profiles')) {
        return [];
    }
    $stmt = db()->prepare('SELECT cp.books_company_id, cp.organization_name, c.code, c.name, c.is_active
        FROM client_profiles cp
        INNER JOIN companies c ON c.id = cp.books_company_id
        WHERE cp.company_id = :cid AND cp.is_active = 1 AND c.is_active = 1
        ORDER BY c.name ASC');
    $stmt->execute(['cid' => $companyId]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $client) {
        $booksId = (int) $client['books_company_id'];
        // A client whose books this admin cannot open is not listed: the list
        // must not offer a door that handle_company_switch() will refuse.
        if ($booksId === $companyId || !isset($authorizedById[$booksId])) {
            continue;
        }
        $out[$booksId] = $client;
    }

    return $out;
};

$servingId = (int) ($_GET['serving'] ?? 0);
$servingCompany = $servingId > 0 ? ($authorizedById[$servingId] ?? null) : null;

$pageTitle = $servingCompany ? 'Companies — ' . (string) $servingCompany['name'] : 'Companies';
$pageSubtitle = '';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>

<?php if ($servingCompany): ?>
    <?php $clients = $clientsOf($servingId); ?>
    <section class="mbw-card">
        <div class="mbw-card-head">
            <h2><?= e((string) $servingCompany['name']) ?></h2>
            <a class="mbw-view-all" href="<?= e(url('platform/index.php')) ?>">All companies</a>
        </div>
        <div class="mbw-tablewrap">
            <table>
                <thead><tr><th>Company</th><th>Code</th><th>Client</th><th>Action</th></tr></thead>
                <tbody>
                    <?php if ($clients === []): ?>
                        <tr><td colspan="4">No client books under this company.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($clients as $booksId => $client): ?>
                        <tr>
                            <td><?= e((string) $client['name']) ?></td>
                            <td><?= e((string) $client['code']) ?></td>
                            <td><?= e((string) $client['organization_name']) ?></td>
                            <td>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="company_id" value="<?= (int) $booksId ?>">
                                    <button type="submit" class="button secondary">Open books</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php else: ?>
    <?php
    // The firm first, then the holding company with its subsidiaries beneath
    // it, then anything else that keeps books. Order is deliberate: this is
    // the order the person reading it thinks in.
    $ordered = [];
    $seen = [];
    $addCompany = static function (?array $company, int $depth) use (&$ordered, &$seen): void {
        if ($company === null) { return; }
        $id = (int) $company['id'];
        if ($id <= 0 || isset($seen[$id])) { return; }
        $seen[$id] = true;
        $ordered[] = ['company' => $company, 'depth' => $depth];
    };

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
    ?>
    <section class="mbw-card">
        <div class="mbw-card-head"><h2>Companies</h2></div>
        <div class="mbw-tablewrap">
            <table>
                <thead><tr><th>Company</th><th>Code</th><th class="is-numeric">Clients</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($ordered as $entry): ?>
                        <?php
                        $company = $entry['company'];
                        $companyId = (int) $company['id'];
                        $clientCount = count($clientsOf($companyId));
                        ?>
                        <tr>
                            <td<?= $entry['depth'] > 0 ? ' style="padding-left:28px"' : '' ?>><?= e((string) $company['name']) ?></td>
                            <td><?= e((string) $company['code']) ?></td>
                            <td class="is-numeric"><?= $clientCount ?></td>
                            <td>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="company_id" value="<?= $companyId ?>">
                                    <button type="submit" class="button secondary">Open books</button>
                                </form>
                                <?php if ($clientCount > 0): ?>
                                    <a class="button secondary" href="<?= e(url('platform/index.php?serving=' . $companyId)) ?>">Open companies</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
