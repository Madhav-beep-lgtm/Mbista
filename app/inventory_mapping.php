<?php
declare(strict_types=1);

/**
 * The inventory ledger mapping, as ONE definition every module shares.
 *
 * `inventory_ledger_mappings` is already the single store — core Inventory,
 * Jewellery and (now) Hospitality all read and write it through
 * inv_resolve_mapping()'s item → category → global ladder. What was NOT shared
 * was the catalogue of purposes and the screen: the list lived inside
 * accounting-inventory.php, so a restaurant user working only in Hospitality
 * could not reach it at all, and anything that wanted to show the same table
 * had to copy it.
 *
 * This file is that catalogue plus the read/save/auto-open routines behind it.
 * A ledger set on any of the three screens is the same row; there is no such
 * thing as "the hospitality mapping" or "the jewellery mapping" separate from
 * the inventory one.
 */

/**
 * purpose => ['label', 'expect'] for every ledger the inventory postings need.
 *
 * A vertical that writes into this table contributes its own purposes here
 * rather than keeping a private list — otherwise a ledger set on one screen
 * and a ledger set on another are two half-answers to the same question.
 */
function inventory_mapping_purposes(): array
{
    $purposes = [
        'inventory_asset'      => ['label' => 'Inventory Asset', 'expect' => 'asset'],
        'opening_equity'       => ['label' => 'Opening Balance Equity', 'expect' => 'equity'],
        'raw_material'         => ['label' => 'Raw Material Inventory', 'expect' => 'asset'],
        'wip'                  => ['label' => 'Work in Progress', 'expect' => 'asset'],
        'finished_goods'       => ['label' => 'Finished Goods Inventory', 'expect' => 'asset'],
        'cogs'                 => ['label' => 'Cost of Goods Sold', 'expect' => 'expense'],
        'purchase_clearing'    => ['label' => 'Purchase / GRNI Clearing', 'expect' => 'liability'],
        'sales_revenue'        => ['label' => 'Sales Revenue', 'expect' => 'revenue'],
        'inventory_gain'       => ['label' => 'Inventory Gain / Adjustment', 'expect' => 'revenue'],
        'inventory_loss'       => ['label' => 'Inventory Loss / Damage / Expiry', 'expect' => 'expense'],
        'write_down_expense'   => ['label' => 'Inventory Write-down Expense', 'expect' => 'expense'],
        'write_down_allowance' => ['label' => 'Allowance for Write-down', 'expect' => 'liability'],
        'write_down_reversal'  => ['label' => 'Reversal of Write-down', 'expect' => 'revenue'],
        'scrap_inventory'      => ['label' => 'Scrap / By-product Inventory', 'expect' => 'asset'],
        'labour_clearing'      => ['label' => 'Direct Labour Clearing / Wages Payable', 'expect' => 'liability'],
        'overhead_absorbed'    => ['label' => 'Production Overhead Absorbed', 'expect' => 'expense'],
        'tax_input'            => ['label' => 'Recoverable Input Tax', 'expect' => 'asset'],
        'tax_output'           => ['label' => 'Output Tax Payable', 'expect' => 'liability'],
    ];

    // Shown only where the vertical is actually in use, so a plain-inventory
    // company is not asked to map accounts it will never post to.
    if (function_exists('jewellery_extra_inventory_purposes')
        && function_exists('jewellery_enabled_for_company')
        && function_exists('current_company_id')
        && jewellery_enabled_for_company(current_company_id())) {
        $purposes += jewellery_extra_inventory_purposes();
    }

    return $purposes;
}

/** Where each purpose belongs in a chart of accounts, for the one-click setup. */
function inventory_mapping_plan(): array
{
    return [
        'inventory_asset'      => ['Inventory', 'current_asset'],
        'raw_material'         => ['Inventory', 'current_asset'],
        'wip'                  => ['Inventory', 'current_asset'],
        'finished_goods'       => ['Inventory', 'current_asset'],
        'scrap_inventory'      => ['Inventory', 'current_asset'],
        'sales_revenue'        => ['Sales', 'direct_income'],
        'inventory_gain'       => ['Indirect Income', 'indirect_income'],
        'write_down_reversal'  => ['Indirect Income', 'indirect_income'],
        'cogs'                 => ['Direct Expenses', 'direct_expense'],
        'inventory_loss'       => ['Indirect Expenses', 'indirect_expense'],
        'write_down_expense'   => ['Indirect Expenses', 'indirect_expense'],
        'overhead_absorbed'    => ['Direct Expenses', 'direct_expense'],
        'purchase_clearing'    => ['Current Liabilities', 'current_liability'],
        'labour_clearing'      => ['Current Liabilities', 'current_liability'],
        'write_down_allowance' => ['Current Liabilities', 'current_liability'],
        'tax_output'           => ['Duties & Taxes', 'current_liability'],
        'tax_input'            => ['Duties & Taxes', 'current_asset'],
    ];
}

/**
 * What kind of account a ledger is — asset, liability, equity, revenue or
 * expense — read from its own type, or from its group's master when the
 * ledger does not carry one. '' when the ledger cannot be found, which is
 * treated as "do not judge" rather than as a mismatch.
 */
function inv_ledger_nature(int $companyId, int $ledgerId): string
{
    static $cache = [];
    $key = $companyId . ':' . $ledgerId;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = db()->prepare('SELECT l.type, g.master_key
        FROM ledgers l LEFT JOIN ledger_groups g ON g.id = l.group_id
        WHERE l.id = :id AND l.company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $ledgerId, 'cid' => $companyId]);
    $row = $stmt->fetch();
    if (!$row) {
        return $cache[$key] = '';
    }
    $type = (string) ($row['type'] ?? '');
    if (in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
        return $cache[$key] = $type;
    }

    return $cache[$key] = (string) (ledger_master_nature((string) ($row['master_key'] ?? '')) ?? '');
}

/** "an asset" / "a liability" — so the refusal reads like a sentence. */
function inv_nature_article(string $nature): string
{
    return (in_array($nature, ['asset', 'equity', 'expense'], true) ? 'an ' : 'a ') . $nature;
}

/** Every company-default mapping, keyed by purpose, with the ledger's name and code. */
function inventory_mapping_rows(int $companyId): array
{
    if (!table_exists('inventory_ledger_mappings')) {
        return [];
    }
    $stmt = db()->prepare("SELECT m.purpose, m.ledger_id, l.name AS ledger_name, l.code AS ledger_code
        FROM inventory_ledger_mappings m
        INNER JOIN ledgers l ON l.id = m.ledger_id AND l.company_id = m.company_id
        WHERE m.company_id = :cid AND m.scope = 'global'");
    $stmt->execute(['cid' => $companyId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(string) $row['purpose']] = $row;
    }

    return $out;
}

/** Set (or with ledger 0 clear) one company-default mapping. */
function inventory_mapping_save(int $companyId, string $purpose, int $ledgerId, int $userId = 0): void
{
    if (!array_key_exists($purpose, inventory_mapping_purposes())) {
        throw new RuntimeException('Unknown posting purpose: ' . $purpose);
    }
    if ($ledgerId <= 0) {
        db()->prepare("DELETE FROM inventory_ledger_mappings WHERE company_id = :cid AND scope = 'global'
            AND purpose = :p AND item_id IS NULL AND category IS NULL")
            ->execute(['cid' => $companyId, 'p' => $purpose]);
        // These mappings just changed; forget what was read of them.
        inv_mapping_forget();

        return;
    }

    // A tampered id must never map another tenant's ledger into these books.
    $check = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :id AND company_id = :cid');
    $check->execute(['id' => $ledgerId, 'cid' => $companyId]);
    if ((int) $check->fetchColumn() === 0) {
        throw new RuntimeException('That ledger does not belong to this company.');
    }

    // AND IT MUST BE THE RIGHT KIND OF LEDGER. Every purpose above declares
    // what it expects, and until now that expectation was printed on the
    // screen as a grey pill and never once checked. Point Inventory Asset at
    // an expense ledger — "Kitchen Purchase", say — and nothing complains:
    // every purchase from then on debits an expense instead of stock, the
    // balance sheet carries no inventory at all, and the whole purchase lands
    // in the profit and loss on the day it is bought. Nothing in the books
    // says why. A stock account is an asset; that is not a preference.
    $expected = (string) (inventory_mapping_purposes()[$purpose]['expect'] ?? '');
    $actual = inv_ledger_nature($companyId, $ledgerId);
    if ($expected !== '' && $actual !== '' && $actual !== $expected) {
        $label = (string) (inventory_mapping_purposes()[$purpose]['label'] ?? $purpose);
        throw new RuntimeException($label . ' has to be ' . inv_nature_article($expected)
            . ' ledger, and that one is ' . inv_nature_article($actual) . ' ledger.'
            . ($expected === 'asset'
                ? ' Posting stock to an expense account would charge every purchase straight to the profit and loss and leave the balance sheet with no inventory on it.'
                : ''));
    }

    $existing = db()->prepare("SELECT id FROM inventory_ledger_mappings WHERE company_id = :cid AND scope = 'global'
        AND purpose = :p AND item_id IS NULL AND category IS NULL LIMIT 1");
    $existing->execute(['cid' => $companyId, 'p' => $purpose]);
    $id = (int) ($existing->fetchColumn() ?: 0);
    if ($id > 0) {
        db()->prepare('UPDATE inventory_ledger_mappings SET ledger_id = :lid WHERE id = :id')
            ->execute(['lid' => $ledgerId, 'id' => $id]);
        // These mappings just changed; forget what was read of them.
        inv_mapping_forget();

        return;
    }

    db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, purpose, ledger_id, created_by)
        VALUES (:cid, 'global', :p, :lid, :by)")
        ->execute(['cid' => $companyId, 'p' => $purpose, 'lid' => $ledgerId, 'by' => $userId ?: null]);
    // These mappings just changed; forget what was read of them.
    inv_mapping_forget();
}

/**
 * Open and map every standard inventory ledger still missing.
 *
 * Only fills GAPS — a purpose already mapped, to anything, is left alone. The
 * ladder still refuses to guess at posting time; this is a deliberate action a
 * user takes once, and every ledger it opens is an ordinary one they can
 * rename, move or re-point afterwards.
 *
 * @return array{created: string[], mapped: string[], skipped: string[], errors: string[]}
 */
function inventory_mapping_autocreate(int $companyId, int $userId = 0): array
{
    $result = ['created' => [], 'mapped' => [], 'skipped' => [], 'errors' => []];
    if (!table_exists('ledgers') || !table_exists('ledger_groups')) {
        $result['errors'][] = 'The chart of accounts is not set up for this company yet.';

        return $result;
    }
    require_once __DIR__ . '/jewellery_engine.php';

    $existing = inventory_mapping_rows($companyId);
    $labels = inventory_mapping_purposes();

    foreach (inventory_mapping_plan() as $purpose => [$groupName, $masterKey]) {
        $label = (string) ($labels[$purpose]['label'] ?? $purpose);
        if (!array_key_exists($purpose, $labels)) {
            continue;
        }
        if (isset($existing[$purpose])) {
            $result['skipped'][] = $label;
            continue;
        }
        try {
            $groupId = jw_ledger_group_id($companyId, $groupName, $masterKey);
            if ($groupId <= 0) {
                $result['errors'][] = $label . ': could not open the "' . $groupName . '" group.';
                continue;
            }
            $code = 'INV-' . strtoupper(str_replace('_', '-', $purpose));
            $byCode = db()->prepare('SELECT id FROM ledgers WHERE company_id = :cid AND code = :code LIMIT 1');
            $byCode->execute(['cid' => $companyId, 'code' => $code]);
            $ledgerId = (int) ($byCode->fetchColumn() ?: 0);

            if ($ledgerId <= 0) {
                $byName = db()->prepare("SELECT id FROM ledgers WHERE company_id = :cid AND group_id = :gid
                    AND name = :name AND status = 'active' LIMIT 1");
                $byName->execute(['cid' => $companyId, 'gid' => $groupId, 'name' => $label]);
                $ledgerId = (int) ($byName->fetchColumn() ?: 0);
            }
            if ($ledgerId <= 0) {
                db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status)
                    VALUES (:cid, :gid, :code, :name, :type, 'active')")
                    ->execute(['cid' => $companyId, 'gid' => $groupId, 'code' => $code, 'name' => $label,
                        'type' => jw_ledger_type_for_master($masterKey)]);
                $ledgerId = (int) db()->lastInsertId();
                $result['created'][] = $label;
            }

            inventory_mapping_save($companyId, $purpose, $ledgerId, $userId);
            $result['mapped'][] = $label;
        } catch (Throwable $planException) {
            $result['errors'][] = $label . ': ' . $planException->getMessage();
        }
    }

    return $result;
}

/** Purposes with no company-default ledger, as labels — what will refuse a posting. */
function inventory_mapping_gaps(int $companyId): array
{
    $mapped = inventory_mapping_rows($companyId);
    $gaps = [];
    foreach (inventory_mapping_purposes() as $purpose => $meta) {
        if (!isset($mapped[$purpose]) && array_key_exists($purpose, inventory_mapping_plan())) {
            $gaps[] = (string) $meta['label'];
        }
    }

    return $gaps;
}

/**
 * Mappings that point a posting purpose at the wrong KIND of account.
 *
 * Nothing checked this until now, so books already carrying the mistake will
 * not announce it — the ledgers balance, the vouchers are valid, and the only
 * visible symptom is a balance sheet with no inventory on it and a profit and
 * loss carrying stock nobody has sold. This lists them, with what has already
 * been posted to each, and changes nothing: correcting a posted voucher is a
 * decision about a period that may well be closed, and it is not this
 * function's to take.
 *
 * @return array<int, array{scope:string, purpose:string, label:string, expected:string,
 *     actual:string, ledger_id:int, ledger_code:string, ledger_name:string,
 *     item_sku:string, posted:float}>
 */
function inventory_mapping_nature_gaps(int $companyId): array
{
    if (!table_exists('ledgers') || !table_exists('ledger_groups')) {
        return [];
    }
    $purposes = inventory_mapping_purposes();
    $gaps = [];

    $add = static function (string $scope, string $purpose, int $ledgerId, string $itemSku)
        use ($companyId, $purposes, &$gaps): void {
        $expected = (string) ($purposes[$purpose]['expect'] ?? '');
        if ($expected === '' || $ledgerId <= 0) {
            return;
        }
        $actual = inv_ledger_nature($companyId, $ledgerId);
        if ($actual === '' || $actual === $expected) {
            return;
        }
        $stmt = db()->prepare('SELECT code, name FROM ledgers WHERE id = :id AND company_id = :cid');
        $stmt->execute(['id' => $ledgerId, 'cid' => $companyId]);
        $ledger = $stmt->fetch() ?: ['code' => '', 'name' => '#' . $ledgerId];
        $gaps[] = [
            'scope' => $scope,
            'purpose' => $purpose,
            'label' => (string) ($purposes[$purpose]['label'] ?? $purpose),
            'expected' => $expected,
            'actual' => $actual,
            'ledger_id' => $ledgerId,
            'ledger_code' => (string) $ledger['code'],
            'ledger_name' => (string) $ledger['name'],
            'item_sku' => $itemSku,
            'posted' => inv_ledger_posted_total($companyId, $ledgerId),
        ];
    };

    if (table_exists('inventory_ledger_mappings')) {
        $stmt = db()->prepare("SELECT m.scope, m.purpose, m.ledger_id, COALESCE(i.sku, '') AS sku
            FROM inventory_ledger_mappings m
            LEFT JOIN inventory_items i ON i.id = m.item_id AND i.company_id = m.company_id
            WHERE m.company_id = :cid");
        $stmt->execute(['cid' => $companyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $add((string) $row['scope'], (string) $row['purpose'], (int) $row['ledger_id'], (string) $row['sku']);
        }
    }

    // The legacy per-item column, which the resolver falls back to and which
    // no screen has ever policed.
    if (table_exists('inventory_items')) {
        $stmt = db()->prepare("SELECT id, sku, ledger_id FROM inventory_items
            WHERE company_id = :cid AND ledger_id IS NOT NULL AND item_type <> 'service'");
        $stmt->execute(['cid' => $companyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $add('item (linked ledger)', 'inventory_asset', (int) $row['ledger_id'], (string) $row['sku']);
        }
    }

    // One ledger reached by twenty items is one problem, not twenty lines.
    $unique = [];
    foreach ($gaps as $gap) {
        $key = $gap['scope'] . '|' . $gap['purpose'] . '|' . $gap['ledger_id'];
        if (isset($unique[$key])) {
            $unique[$key]['item_sku'] = $unique[$key]['item_sku'] === '' ? $gap['item_sku']
                : $unique[$key]['item_sku'] . ', ' . $gap['item_sku'];
            continue;
        }
        $unique[$key] = $gap;
    }
    foreach ($unique as &$gap) {
        if (substr_count($gap['item_sku'], ',') > 4) {
            $names = explode(', ', $gap['item_sku']);
            $gap['item_sku'] = implode(', ', array_slice($names, 0, 4)) . ' and ' . (count($names) - 4) . ' more';
        }
    }

    return array_values($unique);
}

/** What has already been posted to one ledger, net, across all time. */
function inv_ledger_posted_total(int $companyId, int $ledgerId): float
{
    if (!table_exists('voucher_entries') || !table_exists('vouchers')) {
        return 0.0;
    }
    $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN e.entry_type = 'debit' THEN e.amount ELSE -e.amount END), 0)
        FROM voucher_entries e
        INNER JOIN vouchers v ON v.id = e.voucher_id
        WHERE v.company_id = :cid AND v.status = 'posted' AND e.ledger_id = :lid");
    $stmt->execute(['cid' => $companyId, 'lid' => $ledgerId]);

    return round((float) $stmt->fetchColumn(), 2);
}
