<?php
declare(strict_types=1);

/** Numeric chart codes are identifiers, never decimal numbers. */
const COA_NATURE_CODES = ['asset' => 1, 'equity' => 2, 'liability' => 3, 'revenue' => 4, 'expense' => 5];

function coa_code_format(int $master, int $group = 0, int $subgroup = 0, int $ledger = 0, int $subledger = 0): string
{
    foreach ([[$master, 1, 5], [$group, 0, 99], [$subgroup, 0, 99], [$ledger, 0, 999], [$subledger, 0, 99]] as [$value, $min, $max]) {
        if ($value < $min || $value > $max) throw new RuntimeException('Account code capacity exceeded.');
    }
    return sprintf('%d.%02d.%02d.%03d.%02d', $master, $group, $subgroup, $ledger, $subledger);
}

/**
 * Produce a deterministic, non-mutating migration plan. IDs and parents never
 * change. Already-valid slots are reserved before allocating legacy records.
 */
/**
 * Code every group and ledger of one company.
 *
 * $strict is for the CLI migration, which renumbers a whole installation
 * and should refuse to touch anything while the data is inconsistent. Day to
 * day this runs on every account creation, where a legacy group with no head
 * must not stop somebody opening an unrelated ledger: those groups and their
 * ledgers are skipped, and the trial balance names them "(Unclassified)".
 */
function coa_code_plan(array $groups, array $ledgers, bool $strict = false): array
{
    $result = ['ledger_groups' => [], 'ledgers' => []];
    $byGroup = array_column($groups, null, 'id');
    foreach ($ledgers as &$ledger) {
        // Match the reports engine's fallback; do not alter stored nature.
        if (!isset(COA_NATURE_CODES[$ledger['type']])) {
            $ledger['type'] = ledger_master_nature((string) ($byGroup[(int) ($ledger['group_id'] ?? 0)]['master_key'] ?? '')) ?? '';
        }
    }
    unset($ledger);
    $byLedger = array_column($ledgers, null, 'id');
    $used = [];
    $allocate = static function (array $rows, array $prefix, int $slot, string $table) use (&$result, &$used): void {
        usort($rows, static fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);
        $pending = [];
        foreach ($rows as $row) {
            $parts = preg_match('/^[1-5]\.\d{2}\.\d{2}\.\d{3}\.\d{2}$/D', (string) $row['code'])
                ? array_map('intval', explode('.', $row['code'])) : [];
            $valid = count($parts) === 5 && $parts[$slot] > 0;
            foreach ($prefix as $index => $value) {
                if ($index !== $slot && ($parts[$index] ?? -1) !== $value) $valid = false;
            }
            if ($valid && !isset($used[$row['code']])) {
                $result[$table][(int) $row['id']] = $row['code'];
                $used[$row['code']] = true;
            } else {
                $pending[] = $row;
            }
        }
        foreach ($pending as $row) {
            for ($sequence = 1; $sequence <= ($slot === 3 ? 999 : 99); $sequence++) {
                $parts = $prefix;
                $parts[$slot] = $sequence;
                $code = coa_code_format(...$parts);
                if (!isset($used[$code])) break;
            }
            if ($sequence > ($slot === 3 ? 999 : 99)) {
                // Naming the row says nothing: it is simply the one that found
                // the bucket full. What anybody needs is which bucket, and how
                // much is in it.
                throw new RuntimeException(sprintf(
                    'Code capacity exceeded: more than %d %s sit under %s, which is all the code format M.GG.SS.LLL.UU has room for there. Run --capacity to see every full bucket (%s ID %s was the row refused).',
                    $slot === 3 ? 999 : 99, $table === 'ledgers' ? 'ledgers' : 'groups',
                    coa_code_format(...$prefix), $table, $row['id']
                ));
            }
            $used[$code] = true;
            $result[$table][(int) $row['id']] = $code;
        }
    };
    $unclassifiedGroups = [];
    foreach ($groups as $group) {
        $parent = (int) ($group['parent_group_id'] ?? 0);
        if ($parent && (!isset($byGroup[$parent]) || !empty($byGroup[$parent]['parent_group_id']))) {
            throw new RuntimeException('Missing parent or excessive group depth at group ID ' . $group['id']);
        }
        if (!isset(COA_NATURE_CODES[ledger_master_nature((string) $group['master_key']) ?? ''])) {
            if (!$strict) {
                $unclassifiedGroups[(int) $group['id']] = true;
                continue;
            }
            throw new RuntimeException('Unclassified group ID ' . $group['id'] . ' (company ' . ($group['company_id'] ?? '?') . ', name ' . json_encode($group['name'] ?? '') . ', master_key ' . json_encode($group['master_key']) . '). No codes changed. Inspect with --inspect-group=' . $group['id'] . ' before assigning its accounting classification.');
        }
    }
    foreach (COA_NATURE_CODES as $nature => $master) {
        $roots = array_values(array_filter($groups, static fn ($g) => empty($g['parent_group_id']) && ledger_master_nature((string) $g['master_key']) === $nature));
        $allocate($roots, [$master, 0, 0, 0, 0], 1, 'ledger_groups');
        foreach ($roots as $root) {
            $children = array_values(array_filter($groups, static fn ($g) => (int) ($g['parent_group_id'] ?? 0) === (int) $root['id']));
            foreach ($children as $child) {
                if (ledger_master_nature((string) $child['master_key']) !== $nature) throw new RuntimeException('Group parent nature mismatch at ID ' . $child['id']);
            }
            $allocate($children, array_map('intval', explode('.', $result['ledger_groups'][(int) $root['id']])), 2, 'ledger_groups');
        }
    }
    $buckets = [];
    foreach ($ledgers as $ledger) {
        if (!isset(COA_NATURE_CODES[$ledger['type']])) {
            // No nature means no head and no number. Skipped like an
            // unclassified group, so one bad row does not stop the company
            // creating accounts; the migration still refuses outright.
            if (!$strict) { continue; }
            throw new RuntimeException('Unknown nature for ledger ID ' . $ledger['id']);
        }
        if (!empty($ledger['parent_ledger_id'])) continue;
        $gid = (int) ($ledger['group_id'] ?? 0);
        // Its group was skipped for having no head, so there is nothing to
        // hang a code off. Skipped too, rather than refusing the whole chart.
        if ($gid && isset($unclassifiedGroups[$gid])) { continue; }
        if ($gid && !isset($result['ledger_groups'][$gid])) throw new RuntimeException('Missing group for ledger ID ' . $ledger['id']);
        $base = $gid ? $result['ledger_groups'][$gid] : coa_code_format(COA_NATURE_CODES[$ledger['type']]);
        // A CONTRA account sits under the head it reduces, carrying the
        // opposite nature: Purchase Returns is a credit filed under Purchases,
        // Sales Returns a debit filed under Sales. That is how both read on a
        // trading account -- as deductions from the head above them, not as
        // income and expense of their own somewhere else in the chart.
        //
        // Demanding the two natures match outright refused those, and refused
        // them at code-allocation time, so the whole chart failed to number.
        // The code still comes from the GROUP, which is what puts the account
        // under the right head. Only revenue and expense pair this way; an
        // asset in an equity group is still a fault and still says so.
        $ledgerNature = COA_NATURE_CODES[$ledger['type']];
        $groupNature = (int) $base[0];
        $contraPair = [COA_NATURE_CODES['revenue'], COA_NATURE_CODES['expense']];
        if ($groupNature !== $ledgerNature
            && !(in_array($groupNature, $contraPair, true) && in_array($ledgerNature, $contraPair, true))) {
            throw new RuntimeException('Ledger/group nature mismatch at ledger ID ' . $ledger['id']);
        }
        $buckets[$base][] = $ledger;
    }
    foreach ($buckets as $base => $rows) $allocate($rows, array_map('intval', explode('.', $base)), 3, 'ledgers');
    foreach ($ledgers as $ledger) {
        $pid = (int) ($ledger['parent_ledger_id'] ?? 0);
        if (!$pid) continue;
        if (!isset($result['ledgers'][$pid]) || !empty($byLedger[$pid]['parent_ledger_id']) || $byLedger[$pid]['type'] !== $ledger['type'] || (int) $byLedger[$pid]['group_id'] !== (int) $ledger['group_id']) {
            throw new RuntimeException('Invalid subledger parent at ledger ID ' . $ledger['id']);
        }
    }
    foreach ($ledgers as $parent) {
        if (!empty($parent['parent_ledger_id'])) continue;
        // A parent that was skipped for sitting in an unclassified group has
        // no code, so its subledgers have nothing to extend. They are skipped
        // with it rather than exploding on a null.
        if (!isset($result['ledgers'][(int) $parent['id']])) { continue; }
        $children = array_values(array_filter($ledgers, static fn ($l) => (int) ($l['parent_ledger_id'] ?? 0) === (int) $parent['id']));
        $allocate($children, array_map('intval', explode('.', $result['ledgers'][(int) $parent['id']])), 4, 'ledgers');
    }
    return $result;
}

function coa_company_code_rows(int $companyId): array
{
    $out = [];
    foreach (['ledger_groups', 'ledgers'] as $table) {
        $stmt = db()->prepare("SELECT * FROM {$table} WHERE company_id = ? ORDER BY id");
        $stmt->execute([$companyId]);
        $out[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $out;
}

function coa_trial_balance_rows(array $balances, array $groups, bool $hideZero): array
{
    $byGroup = array_column($groups, null, 'id');
    $nodes = [];
    $totals = array_fill(0, 6, 0.0);
    $labels = ['asset' => 'ASSETS', 'equity' => 'EQUITY', 'liability' => 'LIABILITIES', 'revenue' => 'INCOME', 'expense' => 'EXPENSES'];
    foreach ($balances as $balance) {
        $values = array_map(static fn ($key) => (float) $balance[$key], ['op_side_dr','op_side_cr','tx_dr','tx_cr','cl_side_dr','cl_side_cr']);
        if ($hideZero && !array_filter($values, static fn ($v) => abs($v) >= 0.005)) continue;
        $nature = rc_ledger_nature($balance);
        $master = COA_NATURE_CODES[$nature];
        $path = [['key' => 'm' . $master, 'code' => coa_code_format($master), 'name' => $labels[$nature]]];
        $gid = (int) ($balance['group_id'] ?? 0);
        if (isset($byGroup[$gid])) {
            $group = $byGroup[$gid];
            $pid = (int) ($group['parent_group_id'] ?? 0);
            if (isset($byGroup[$pid])) $path[] = ['key' => 'g' . $pid, 'code' => $byGroup[$pid]['code'], 'name' => $byGroup[$pid]['name']];
            // A group with no head is broken data -- nothing refused the empty
            // value when it was written. The row is still filed under the
            // ledger's own nature so the account is never dropped from the
            // trial balance, but the heading says so, because an account filed
            // somewhere plausible by guesswork is one nobody will ever go and fix.
            $groupLabel = (string) $group['name'];
            if ((string) ($group['master_key'] ?? '') === '') {
                $groupLabel .= ' (Unclassified)';
            }
            $path[] = ['key' => 'g' . $gid, 'code' => $group['code'], 'name' => $groupLabel];
        }
        $parent = '';
        foreach ($path as $level => $item) {
            $key = $item['key'];
            $nodes[$key] ??= $item + ['parent' => $parent, 'level' => $level, 'sum' => array_fill(0,6,0.0), 'ledgers' => []];
            foreach ($values as $i => $value) $nodes[$key]['sum'][$i] += $value;
            $parent = $key;
        }
        $nodes[$parent]['ledgers'][] = ['balance' => $balance, 'values' => $values];
        foreach ($values as $i => $value) $totals[$i] += $value;
    }
    $rows = [];
    $emit = static function (string $parent) use (&$emit, &$rows, $nodes): void {
        $children = array_filter($nodes, static fn ($node) => $node['parent'] === $parent);
        uasort($children, static fn ($a,$b) => strnatcmp($a['code'], $b['code']));
        foreach ($children as $key => $node) {
            $rows[] = rc_row(array_merge([$node['code'], $node['name']], array_map('rc_fmt', $node['sum'])), 'bold', ['level' => $node['level'], 'node' => $key, 'parent' => $parent, 'label_cell' => 1]);
            $emit($key);
            usort($node['ledgers'], static fn ($a,$b) => strnatcmp($a['balance']['code'], $b['balance']['code']));
            foreach ($node['ledgers'] as $line) {
                $b = $line['balance'];
                $rows[] = rc_row(array_merge([$b['code'], $b['name']], array_map('rc_fmt', $line['values'])), '', ['level' => $node['level'] + 1, 'parent' => $key, 'ledger_id' => (int) $b['id'], 'label_cell' => 1]);
            }
        }
    };
    $emit('');
    return ['rows' => $rows, 'totals' => $totals];
}

function coa_allocate_code(int $companyId, string $table, array $newRow): string
{
    $rows = coa_company_code_rows($companyId);
    $newRow += ['id' => PHP_INT_MAX, 'code' => '', 'group_id' => null, 'parent_group_id' => null, 'parent_ledger_id' => null];
    $rows[$table][] = $newRow;
    $plan = coa_code_plan($rows['ledger_groups'], $rows['ledgers']);
    $code = $plan[$table][PHP_INT_MAX] ?? null;
    if ($code === null) {
        // The planner skipped this row: its group has no accounting head, so
        // there is no number to hang a code off. Name the group rather than
        // leaving the caller with a return-type error.
        $holder = (int) ($newRow['group_id'] ?: $newRow['parent_group_id']);
        throw new RuntimeException($holder
            ? 'Group ID ' . $holder . ' has no accounting head, so accounts under it cannot be numbered.'
            : 'This account has no accounting head, so it cannot be numbered.');
    }

    return $code;
}

/** Keep semantic system identifiers separate from the visible numeric code. */
function coa_create_system_ledger(int $companyId, int $groupId, string $alias, string $name, string $nature, bool $system = false): int
{
    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) db()->beginTransaction();
    try {
        db()->prepare('SELECT id FROM companies WHERE id = ? FOR UPDATE')->execute([$companyId]);
        $find = db()->prepare('SELECT id FROM ledgers WHERE company_id = ? AND ? IN (code, legacy_code) LIMIT 1');
        $find->execute([$companyId, $alias]);
        $id = (int) $find->fetchColumn();
        if (!$id) {
            $code = coa_next_ledger_code($companyId, $groupId, null, $nature);
            db()->prepare("INSERT INTO ledgers (company_id, group_id, code, legacy_code, name, type, is_system, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')")
                ->execute([$companyId, $groupId ?: null, $code, $alias, $name, $nature, (int) $system]);
            $id = (int) db()->lastInsertId();
        }
        if ($ownsTransaction) db()->commit();
        return $id;
    } catch (Throwable $e) {
        if ($ownsTransaction) db()->rollBack();
        throw $e;
    }
}

/** Explicit CLI migration only. Schema changes happen before the data transaction. */
function coa_migrate_codes(bool $apply = false, ?array $expectedChanges = null): array
{
    $plans = [];
    $changes = [];
    foreach (db()->query('SELECT id FROM companies ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $rows = coa_company_code_rows((int) $cid);
        $plan = coa_code_plan($rows['ledger_groups'], $rows['ledgers'], true);
        $plans[(int) $cid] = $plan;
        foreach ($rows as $table => $items) foreach ($items as $row) {
            $new = $plan[$table][(int) $row['id']];
            if ($new !== $row['code']) $changes[] = ['company_id' => (int) $cid, 'table' => $table, 'id' => (int) $row['id'], 'old_code' => $row['code'], 'new_code' => $new];
        }
    }
    if ($expectedChanges !== null && $expectedChanges !== $changes) throw new RuntimeException('Chart changed after preview; rerun migration.');
    if (!$apply || !$changes) return $changes;
    foreach (['ledger_groups', 'ledgers'] as $table) {
        // Do not use column_exists here: its request cache can predate ALTER.
        db()->query("SELECT legacy_code FROM {$table} LIMIT 0");
    }
    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) db()->beginTransaction();
    try {
        // Lock accounts as well as company allocation locks; reject a changed
        // plan instead of overwriting concurrent account creation or edits.
        db()->query('SELECT id FROM companies ORDER BY id FOR UPDATE')->fetchAll();
        foreach (['ledger_groups', 'ledgers'] as $table) db()->query("SELECT id FROM {$table} ORDER BY id FOR UPDATE")->fetchAll();
        foreach ($plans as $cid => $plan) {
            $rows = coa_company_code_rows($cid);
            if ($plan !== coa_code_plan($rows['ledger_groups'], $rows['ledgers'])) throw new RuntimeException('Chart changed during migration. Run again.');
        }
        foreach ($changes as $change) {
            $table = $change['table'];
            $stmt = db()->prepare("UPDATE {$table} SET legacy_code = COALESCE(legacy_code, code), code = ? WHERE company_id = ? AND id = ? AND code = ?");
            $stmt->execute(['~COA' . $change['id'], $change['company_id'], $change['id'], $change['old_code']]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Account changed during migration.');
        }
        foreach ($changes as $change) {
            db()->prepare('UPDATE ' . $change['table'] . ' SET code = ? WHERE company_id = ? AND id = ?')
                ->execute([$change['new_code'], $change['company_id'], $change['id']]);
        }
        if ($ownsTransaction) db()->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction) db()->rollBack();
        throw $e;
    }
    return $changes;
}
