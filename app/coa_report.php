<?php
declare(strict_types=1);

/** Presentation-only export. Keep the separate CSV/template format importable. */
function coa_report_data(array $company, array $fiscalYear, array $masters, array $groups, array $ledgers): array
{
    $rows = [
        [(string) ($company['name'] ?? '')],
        [trim((string) ($company['address'] ?? '')) ?: 'Address not recorded'],
        ['Chart of Accounts'],
        ['Fiscal year: ' . ((string) ($fiscalYear['label'] ?? '') ?: 'Not selected')],
        [],
        ['Code', 'Account name', 'Level', 'Nature', 'Status'],
    ];
    $kinds = ['company', 'meta', 'title', 'meta', 'blank', 'header'];
    $indents = [];
    $add = static function (array $row, string $kind, int $indent = 0) use (&$rows, &$kinds, &$indents): void {
        $indents[count($rows)] = [1, $indent];
        $rows[] = $row;
        $kinds[] = $kind;
    };
    $byGroup = [];
    foreach ($ledgers as $ledger) {
        $byGroup[(int) ($ledger['group_id'] ?? 0)][] = $ledger;
    }
    $seenGroups = [];
    $addGroup = static function (array $group) use ($add, $byGroup, $masters, &$seenGroups): void {
        $id = (int) $group['id'];
        $seenGroups[$id] = true;
        $nature = (string) ($masters[(string) $group['master_key']]['nature'] ?? '');
        $add([(string) $group['code'], (string) $group['name'], !empty($group['parent_group_id']) ? 'Subgroup' : 'Group', ucfirst($nature), (int) $group['is_active'] === 1 ? 'Active' : 'Inactive'], 'bold', 1);
        foreach ($byGroup[$id] ?? [] as $ledger) {
            $add([(string) $ledger['code'], (string) $ledger['name'], !empty($ledger['parent_ledger_id']) ? 'Subledger' : 'Ledger', ucfirst((string) $ledger['type']), ucfirst((string) $ledger['status'])], 'body', !empty($ledger['parent_ledger_id']) ? 3 : 2);
        }
    };
    foreach (COA_NATURE_CODES as $nature => $digit) {
        $add([coa_code_format($digit), $nature === 'revenue' ? 'Income' : ucfirst($nature), 'Master', ucfirst($nature), 'Active'], 'section');
        foreach ($groups as $group) {
            if (ledger_master_nature((string) $group['master_key']) === $nature) $addGroup($group);
        }
    }
    foreach ($groups as $group) {
        if (!isset($seenGroups[(int) $group['id']])) $addGroup($group);
    }
    foreach ($byGroup as $id => $items) {
        if (isset($seenGroups[$id])) continue;
        foreach ($items as $ledger) {
            $add([(string) $ledger['code'], (string) $ledger['name'], 'Ledger', ucfirst((string) $ledger['type']), ucfirst((string) $ledger['status'])], 'body');
        }
    }
    return [
        'rows' => $rows,
        'widths' => [0 => 28, 1 => 56, 2 => 13, 3 => 16, 4 => 13],
        'options' => [
            'styled_table' => true, 'freeze_header' => true, 'header_row' => 5,
            'row_kinds' => $kinds, 'row_indents' => $indents,
            'column_formats' => array_fill(0, 5, 'text'),
            'merges' => ['A1:E1', 'A2:E2', 'A3:E3', 'A4:E4'],
            'print' => ['landscape' => true, 'repeat_rows' => 6],
        ],
    ];
}
