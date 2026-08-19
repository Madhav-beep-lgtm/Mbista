<?php
declare(strict_types=1);

/**
 * What each kind of voucher actually is.
 *
 * A journal and a payment are not the same document with a different label on
 * it. An accountant filling a payment voucher names the bank once and then
 * lists what the money went to; filling a contra she names two banks and one
 * amount; filling a purchase she names a supplier, quotes their bill number,
 * and lets the VAT compute itself. Tally has known this for thirty years and
 * gives each type its own screen.
 *
 * This file is where that knowledge lives, so the screens and the posting rules
 * cannot drift apart:
 *
 *   - voucher_type_catalog()      what the eight types are and how they behave
 *   - voucher_ledger_directory()  every ledger, tagged with the roles it can play
 *   - voucher_next_number()       a per-type, per-year series (PMT/2082-83/0007)
 *   - voucher_compose()           turns one type's form fields into debits and
 *                                 credits — pure, so it can be tested without a
 *                                 database
 */

// ---------------------------------------------------------------------------
// The catalogue
// ---------------------------------------------------------------------------

/**
 * The eight voucher types, each with the shape of its own entry screen.
 *
 * layout:      which screen renders it (tender | transfer | journal | trade)
 * party_side:  which side the party/settlement line falls on, for trade types
 * bank_side:   which side the cash/bank line falls on, for tender types
 * hotkey:      the browser shortcut (Tally's own F-keys are taken by the
 *              browser — F5 reloads the page — so Alt+digit stands in and the
 *              Tally key is shown alongside it for people who know it by hand)
 */
function voucher_type_catalog(): array
{
    return [
        'contra' => [
            'label' => 'Contra Voucher',
            'short' => 'Contra',
            'prefix' => 'CTR',
            'layout' => 'transfer',
            'icon' => 'reconcile',
            'tone' => 'teal',
            'hotkey' => '4',
            'tally_key' => 'F4',
            'blurb' => 'Move money between your own cash and bank accounts.',
            'title_hint' => 'Cash deposited into bank',
            'instrument' => true,
        ],
        'payment' => [
            'label' => 'Payment Voucher',
            'short' => 'Payment',
            'prefix' => 'PMT',
            'layout' => 'tender',
            'bank_side' => 'credit',
            'icon' => 'trend-down',
            'tone' => 'amber',
            'hotkey' => '5',
            'tally_key' => 'F5',
            'blurb' => 'Money going out — name the account it left, then what it paid for.',
            'title_hint' => 'Office rent for Shrawan',
            'bank_label' => 'Paid from',
            'bank_hint' => 'The cash or bank account the money left. Several rows when one payment was settled in more than one way.',
            'lines_label' => 'Paid towards',
            'lines_hint' => 'Suppliers, expenses, advances — what the money was for.',
            'party_kind' => 'supplier',
            'party_label' => 'Paid to (party)',
            'instrument' => true,
        ],
        'receipt' => [
            'label' => 'Receipt Voucher',
            'short' => 'Receipt',
            'prefix' => 'RCP',
            'layout' => 'tender',
            'bank_side' => 'debit',
            'icon' => 'trend-up',
            'tone' => 'green',
            'hotkey' => '6',
            'tally_key' => 'F6',
            'blurb' => 'Money coming in — name the account it landed in, then who it came from.',
            'title_hint' => 'Collection against invoice INV-0142',
            'bank_label' => 'Received into',
            'bank_hint' => 'The cash or bank account that took the money in. Several rows when one receipt arrived part cash, part transfer.',
            'lines_label' => 'Received against',
            'lines_hint' => 'Customers, income, refunds — what the money was for.',
            'party_kind' => 'customer',
            'party_label' => 'Received from (party)',
            'instrument' => true,
        ],
        'journal' => [
            'label' => 'Journal Voucher',
            'short' => 'Journal',
            'prefix' => 'JNL',
            'layout' => 'journal',
            'icon' => 'journal',
            'tone' => 'blue',
            'hotkey' => '7',
            'tally_key' => 'F7',
            'blurb' => 'Adjustments between ledgers — depreciation, provisions, corrections.',
            'title_hint' => 'Depreciation for the quarter',
            'instrument' => false,
        ],
        'sales' => [
            'label' => 'Sales Voucher',
            'short' => 'Sales',
            'prefix' => 'SAL',
            'layout' => 'trade',
            'party_side' => 'debit',
            'party_kind' => 'customer',
            'party_ledger_side' => 'receivable',
            'value_role' => 'income',
            'icon' => 'cart',
            'tone' => 'purple',
            'hotkey' => '8',
            'tally_key' => 'F8',
            'blurb' => 'Goods or services billed out, with VAT worked out for you.',
            'title_hint' => 'Consultancy billed to Altiora',
            'party_label' => 'Customer',
            'value_label' => 'Sales / income ledgers',
            'reference_label' => 'Invoice no.',
            'reference_date_label' => 'Invoice date',
            'settlement_label' => 'Billed on credit / against cash',
            'instrument' => false,
        ],
        'purchase' => [
            'label' => 'Purchase Voucher',
            'short' => 'Purchase',
            'prefix' => 'PUR',
            'layout' => 'trade',
            'party_side' => 'credit',
            'party_kind' => 'supplier',
            'party_ledger_side' => 'payable',
            'value_role' => 'expense',
            'icon' => 'inventory',
            'tone' => 'orange',
            'hotkey' => '9',
            'tally_key' => 'F9',
            'blurb' => "A supplier's bill — quote their bill number and date, VAT is split out.",
            'title_hint' => 'Stationery purchased from Nepal Traders',
            'party_label' => 'Supplier',
            'value_label' => 'Purchase / expense ledgers',
            'reference_label' => 'Supplier bill no.',
            'reference_date_label' => 'Supplier bill date',
            'settlement_label' => 'Bought on credit / paid in cash',
            'instrument' => false,
        ],
        'debit_note' => [
            'label' => 'Debit Note',
            'short' => 'Debit Note',
            'prefix' => 'DRN',
            'layout' => 'trade',
            'party_side' => 'debit',
            'party_kind' => 'supplier',
            'party_ledger_side' => 'payable',
            'value_role' => 'expense',
            'icon' => 'trend-down',
            'tone' => 'red',
            'hotkey' => 'shift+9',
            'tally_key' => 'Ctrl+F9',
            'blurb' => 'Goods returned to a supplier, or their bill reduced.',
            'title_hint' => 'Damaged stock returned to Nepal Traders',
            'party_label' => 'Supplier',
            'value_label' => 'Purchase return / expense ledgers',
            'reference_label' => 'Against supplier bill no.',
            'reference_date_label' => 'Original bill date',
            'settlement_label' => 'Adjusted against the supplier / refunded in cash',
            'needs_reason' => true,
            'reason_label' => 'Reason for the debit note',
            'instrument' => false,
        ],
        'credit_note' => [
            'label' => 'Credit Note',
            'short' => 'Credit Note',
            'prefix' => 'CRN',
            'layout' => 'trade',
            'party_side' => 'credit',
            'party_kind' => 'customer',
            'party_ledger_side' => 'receivable',
            'value_role' => 'income',
            'icon' => 'trend-up',
            'tone' => 'cyan',
            'hotkey' => 'shift+8',
            'tally_key' => 'Ctrl+F8',
            'blurb' => 'Goods returned by a customer, or their invoice reduced.',
            'title_hint' => 'Sales return against INV-0142',
            'party_label' => 'Customer',
            'value_label' => 'Sales return / income ledgers',
            'reference_label' => 'Against invoice no.',
            'reference_date_label' => 'Original invoice date',
            'settlement_label' => 'Adjusted against the customer / refunded in cash',
            'needs_reason' => true,
            'reason_label' => 'Reason for the credit note',
            'instrument' => false,
        ],
    ];
}

/** True when $type names one of the eight voucher types. */
function voucher_type_exists(string $type): bool
{
    return isset(voucher_type_catalog()[$type]);
}

/** The full spec for a type, falling back to journal for anything unknown. */
function voucher_type_spec(string $type): array
{
    $catalog = voucher_type_catalog();
    $spec = $catalog[$type] ?? $catalog['journal'];
    $spec['key'] = isset($catalog[$type]) ? $type : 'journal';

    // Derived sides: the value and tax lines of a trade voucher always sit
    // opposite the party, so there is one fact to keep right, not three.
    if (($spec['layout'] ?? '') === 'trade') {
        $spec['value_side'] = ($spec['party_side'] ?? 'debit') === 'debit' ? 'credit' : 'debit';
        $spec['tax_side'] = $spec['value_side'];
    }

    return $spec;
}

/** Just the labels, for dropdowns and register columns. */
function voucher_type_labels(): array
{
    return array_map(static fn (array $spec): string => $spec['label'], voucher_type_catalog());
}

/** The display label for one type ('Payment Voucher'), or the raw key. */
function voucher_type_label(string $type): string
{
    return voucher_type_catalog()[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type));
}

/** Where a type's own entry screen lives. */
function voucher_type_url(string $type): string
{
    return 'admin/voucher-form.php?type=' . rawurlencode($type);
}

/** Voucher types intentionally entered from the compact voucher workspace. */
function voucher_entry_type_catalog(): array
{
    return array_intersect_key(voucher_type_catalog(), array_flip(['contra', 'payment', 'receipt', 'journal']));
}

// ---------------------------------------------------------------------------
// Ledgers, tagged with the roles they can play
// ---------------------------------------------------------------------------

/**
 * Tag one ledger row with the roles a voucher screen filters on.
 *
 * Pure: the caller supplies the receivable/payable group ids, so this can be
 * exercised without a database.
 */
function voucher_ledger_roles(array $ledger, int $receivableGroupId = 0, int $payableGroupId = 0): array
{
    $masterKey = (string) ($ledger['master_key'] ?? '');
    $groupId = (int) ($ledger['group_id'] ?? 0);
    $groupCode = strtoupper((string) ($ledger['group_code'] ?? ''));
    $groupName = (string) ($ledger['group_name'] ?? '');
    $isCashBank = (int) ($ledger['is_cash_or_bank'] ?? 0) === 1;

    $isIncome = in_array($masterKey, ['direct_income', 'indirect_income'], true);
    $isExpense = in_array($masterKey, ['direct_expense', 'indirect_expense'], true);
    $isAsset = in_array($masterKey, ['current_asset', 'non_current_asset'], true);
    $isLiability = in_array($masterKey, ['current_liability', 'non_current_liability'], true);
    // Duties and Taxes carries the seeded code on most companies; client-books
    // companies generate numeric codes, so the name is checked too.
    $isTax = $groupCode === 'DUTIES_TAXES'
        || preg_match('/\b(duties|tax|taxes|vat)\b/i', $groupName) === 1;

    return [
        'cash_bank' => $isCashBank,
        'receivable' => $receivableGroupId > 0 && $groupId === $receivableGroupId,
        'payable' => $payableGroupId > 0 && $groupId === $payableGroupId,
        'income' => $isIncome,
        'expense' => $isExpense,
        'asset' => $isAsset,
        'liability' => $isLiability,
        'equity' => $masterKey === 'equity',
        'tax' => $isTax,
    ];
}

/**
 * Every active ledger of a company, keyed by id, each tagged with its roles.
 * One query — voucher screens need the whole list to build their filtered
 * dropdowns client-side.
 */
function voucher_ledger_directory(int $companyId): array
{
    if ($companyId <= 0 || !table_exists('ledgers')) {
        return [];
    }
    $stmt = db()->prepare("SELECT l.id, l.code, l.name, l.group_id, l.bank_name, l.bank_account_no,
            COALESCE(g.is_cash_or_bank, 0) AS is_cash_or_bank,
            COALESCE(g.master_key, '') AS master_key,
            COALESCE(g.name, '') AS group_name,
            COALESCE(g.code, '') AS group_code
        FROM ledgers l
        LEFT JOIN ledger_groups g ON g.id = l.group_id
        WHERE l.company_id = :company_id AND l.status = 'active'
        ORDER BY l.name ASC");
    $stmt->execute(['company_id' => $companyId]);

    $receivableGroupId = function_exists('receivable_payable_group_id') ? receivable_payable_group_id($companyId, 'receivable') : 0;
    $payableGroupId = function_exists('receivable_payable_group_id') ? receivable_payable_group_id($companyId, 'payable') : 0;

    $directory = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['roles'] = voucher_ledger_roles($row, $receivableGroupId, $payableGroupId);
        $directory[(int) $row['id']] = $row;
    }

    return $directory;
}

/**
 * The ledgers a given screen slot may offer, in the order a person expects.
 *
 * $role is one of: cash_bank, income, expense, tax, party, any.
 */
function voucher_ledgers_for_role(array $directory, string $role): array
{
    if ($role === 'any') {
        return array_values($directory);
    }
    $picked = [];
    foreach ($directory as $ledger) {
        $roles = $ledger['roles'] ?? [];
        $keep = match ($role) {
            'cash_bank' => !empty($roles['cash_bank']),
            'income' => !empty($roles['income']),
            // A purchase can land in stock or a prepayment as easily as in an
            // expense head, so assets are offered alongside expenses.
            'expense' => !empty($roles['expense']) || (!empty($roles['asset']) && empty($roles['cash_bank']) && empty($roles['receivable'])),
            // VAT payable is a liability; VAT receivable on purchases is an
            // asset. Both are legitimate tax ledgers.
            'tax' => !empty($roles['tax']) || !empty($roles['liability']),
            'party' => !empty($roles['receivable']) || !empty($roles['payable']),
            // Anywhere money can actually come to rest: the tills and banks,
            // the party accounts a balance can be left standing on, and the
            // clearing or advance accounts a wallet or a gateway lands in
            // before the bank sweeps it. An income or expense head never
            // settles anything — that is the other side of the voucher.
            'settlement' => !empty($roles['cash_bank']) || !empty($roles['receivable']) || !empty($roles['payable'])
                || !empty($roles['asset']) || !empty($roles['liability']),
            default => true,
        };
        if ($keep) {
            $picked[] = $ledger;
        }
    }

    return $picked;
}

// ---------------------------------------------------------------------------
// Numbering: one series per type, per fiscal year
// ---------------------------------------------------------------------------

/**
 * The series code a fiscal year contributes to a voucher number, reduced to
 * something safe to put in an identifier ('2082/83' becomes '2082-83').
 */
function voucher_series_code(?array $fiscalYear): string
{
    $label = trim((string) ($fiscalYear['label'] ?? ''));
    if ($label === '') {
        $label = substr((string) ($fiscalYear['start_date'] ?? date('Y-m-d')), 0, 4);
    }
    $code = preg_replace('/[^A-Za-z0-9]+/', '-', $label) ?? '';
    $code = trim((string) $code, '-');

    return $code !== '' ? substr($code, 0, 20) : date('Y');
}

/**
 * The next number in a type's series, e.g. PMT/2082-83/0007.
 *
 * The tail is read back from the numbers already issued rather than from a
 * counter table, so an imported or hand-corrected voucher number never leaves
 * a gap the next entry falls into. voucher_no is UNIQUE per company, so a race
 * loses the INSERT rather than duplicating a number; callers retry.
 */
function voucher_next_number(int $companyId, int $fiscalYearId, string $type, int $attempt = 0): string
{
    $spec = voucher_type_spec($type);
    $prefix = (string) $spec['prefix'];
    $fiscalYear = $fiscalYearId > 0 && function_exists('fiscal_year_by_id') ? fiscal_year_by_id($fiscalYearId) : null;
    $series = voucher_series_code($fiscalYear);
    $stem = $prefix . '/' . $series . '/';

    $next = 1;
    if (table_exists('vouchers')) {
        $stmt = db()->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(voucher_no, '/', -1) AS UNSIGNED)), 0)
            FROM vouchers WHERE company_id = :company_id AND voucher_no LIKE :stem");
        $stmt->execute(['company_id' => $companyId, 'stem' => $stem . '%']);
        $next = ((int) $stmt->fetchColumn()) + 1;
    }
    $next += max(0, $attempt);

    return $stem . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

// ---------------------------------------------------------------------------
// Composition: form fields in, debits and credits out
// ---------------------------------------------------------------------------

/**
 * Read one numeric field out of a parallel-array form post.
 */
function voucher_input_row(array $input, string $field, int $index): string
{
    $column = $input[$field] ?? [];

    return is_array($column) ? trim((string) ($column[$index] ?? '')) : '';
}

/** How many rows the parallel arrays of one grid carry. */
function voucher_input_rows(array $input, string $anchorField): int
{
    $column = $input[$anchorField] ?? [];

    return is_array($column) ? count($column) : 0;
}

/**
 * Turn a type's own form fields into the debit and credit lines that will be
 * posted, and say plainly what is wrong when they cannot be.
 *
 * Pure — $ledgers is the tagged directory (id => row with 'roles'), so every
 * rule below is testable without a database.
 *
 * $draft relaxes the completeness rules and keeps whatever lines are already
 * good: half a voucher typed at four o'clock has to survive until morning. It
 * never relaxes what a line IS — a ledger that cannot play the slot it was put
 * in is dropped from a draft too, rather than saved wrong.
 *
 * Returns:
 *   entries  list of ['ledger_id','entry_type','amount','memo','cost_centre',
 *                     'tax_code','line_reference']
 *   errors   human-readable problems; entries are unusable when non-empty
 *   warnings advisory only — the voucher still posts
 *   total    the voucher's total (its debit side)
 *   header   type-specific header fields to store on the voucher row
 */
function voucher_compose(string $type, array $input, array $ledgers, bool $draft = false): array
{
    $spec = voucher_type_spec($type);
    $result = ['entries' => [], 'errors' => [], 'warnings' => [], 'total' => 0.0, 'header' => []];

    $layout = (string) $spec['layout'];
    if ($layout === 'transfer') {
        return voucher_compose_transfer($spec, $input, $ledgers, $result, $draft);
    }
    if ($layout === 'tender') {
        return voucher_compose_tender($spec, $input, $ledgers, $result, $draft);
    }
    if ($layout === 'trade') {
        return voucher_compose_trade($spec, $input, $ledgers, $result, $draft);
    }

    return voucher_compose_journal($spec, $input, $ledgers, $result, $draft);
}

/**
 * One side of a contra: the accounts money leaves, or the ones it lands in.
 *
 * $side is 'out' or 'in'. Falls back to the single-account shape the screen
 * submitted before it had grids, so a voucher half-typed on a page loaded
 * before this change still composes into the same entries.
 */
function voucher_transfer_legs(array $input, array $ledgers, string $side, array &$result): array
{
    $side = $side === 'out' ? 'out' : 'in';
    $field = 'contra_' . $side;
    $rows = [];
    $rowCount = voucher_input_rows($input, $field . '_ledger');
    if ($rowCount === 0) {
        $legacyId = (int) ($input[$side === 'out' ? 'contra_from_ledger' : 'contra_to_ledger'] ?? 0);
        $legacyAmount = round((float) ($input['contra_amount'] ?? 0), 2);
        if ($legacyId > 0 || $legacyAmount > 0) {
            $rows[] = ['ledger' => $legacyId, 'amount' => $legacyAmount];
        }
    }
    for ($index = 0; $index < $rowCount; $index++) {
        $rows[] = [
            'ledger' => (int) voucher_input_row($input, $field . '_ledger', $index),
            'amount' => round((float) voucher_input_row($input, $field . '_amount', $index), 2),
        ];
    }

    $legs = [];
    foreach ($rows as $index => $row) {
        $ledgerId = (int) $row['ledger'];
        $amount = (float) $row['amount'];
        if ($ledgerId <= 0 && $amount <= 0) {
            continue;
        }
        $ledger = $ledgers[$ledgerId] ?? null;
        if (!$ledger) {
            $result['errors'][] = 'Line ' . ($index + 1) . ' of the money '
                . ($side === 'out' ? 'going out' : 'coming in') . ' names no account.';
            continue;
        }
        // The whole point of a contra is that nothing enters or leaves the
        // business: both ends have to be somewhere the firm already keeps money.
        if (empty($ledger['roles']['cash_bank'])) {
            $result['errors'][] = 'A contra voucher only moves money between cash and bank accounts — "' . $ledger['name'] . '" is not one.';
            continue;
        }
        if ($amount <= 0) {
            $result['errors'][] = 'Enter how much ' . ($side === 'out' ? 'leaves' : 'arrives in') . ' "' . $ledger['name'] . '".';
            continue;
        }
        $legs[] = ['ledger_id' => $ledgerId, 'amount' => $amount];
    }
    if ($legs === []) {
        $result['errors'][] = $side === 'out'
            ? 'Choose the account the money moves out of.'
            : 'Choose the account the money moves into.';
    }

    return $legs;
}

/**
 * Contra: money out of the firm's own pockets and into others of its own.
 *
 * One account each side is the common case but not the only one. A day's
 * takings swept from the till into three bank accounts, or two accounts
 * emptied into one before a payment goes out, are single movements of money
 * and belong on a single voucher — split across several, the day book stops
 * showing the sweep as the one thing it was.
 *
 * So each side is a list, and the rule that makes it a transfer rather than a
 * receipt is that the two lists foot to the same figure.
 */
function voucher_compose_transfer(array $spec, array $input, array $ledgers, array $result, bool $draft = false): array
{
    $outLegs = voucher_transfer_legs($input, $ledgers, 'out', $result);
    $inLegs = voucher_transfer_legs($input, $ledgers, 'in', $result);

    $outTotal = 0.0;
    foreach ($outLegs as $leg) {
        $outTotal += (float) $leg['amount'];
    }
    $inTotal = 0.0;
    foreach ($inLegs as $leg) {
        $inTotal += (float) $leg['amount'];
    }
    $outTotal = round($outTotal, 2);
    $inTotal = round($inTotal, 2);

    // An account on both sides nets to nothing while the day book claims the
    // money moved. Money does not move to where it already is.
    $outIds = array_column($outLegs, 'ledger_id');
    foreach ($inLegs as $leg) {
        if (in_array((int) $leg['ledger_id'], $outIds, true)) {
            $result['errors'][] = '"' . (string) ($ledgers[(int) $leg['ledger_id']]['name'] ?? 'That account')
                . '" is on both sides of this transfer — the money has to move between different accounts.';
        }
    }
    if ($outLegs !== [] && $inLegs !== [] && abs($outTotal - $inTotal) >= 0.005) {
        $result['errors'][] = 'What leaves has to equal what arrives: ' . number_format($outTotal, 2)
            . ' is going out but ' . number_format($inTotal, 2) . ' is coming in.';
    }

    // The counterparty is named on the line when there is only one of it. A
    // sweep into three banks says so rather than naming one and implying the
    // other two had nothing to do with it.
    $sideName = static function (array $legs) use ($ledgers): string {
        if (count($legs) === 1) {
            return (string) ($ledgers[(int) $legs[0]['ledger_id']]['name'] ?? 'another account');
        }

        return $legs === [] ? 'another account' : count($legs) . ' accounts';
    };
    // Built before the gate so a draft keeps the side that is already named.
    $instrumentNo = trim((string) ($input['instrument_no'] ?? ''));
    $entries = [];
    foreach ($inLegs as $leg) {
        $entries[] = voucher_entry((int) $leg['ledger_id'], 'debit', (float) $leg['amount'],
            'Transferred in from ' . $sideName($outLegs), '', '', $instrumentNo);
    }
    foreach ($outLegs as $leg) {
        $entries[] = voucher_entry((int) $leg['ledger_id'], 'credit', (float) $leg['amount'],
            'Transferred out to ' . $sideName($inLegs), '', '', $instrumentNo);
    }

    if ($draft) {
        $result['errors'] = [];
    }
    if ($result['errors'] !== []) {
        return $result;
    }

    $result['entries'] = $entries;
    $result['total'] = max($outTotal, $inTotal);
    $result['header'] = voucher_instrument_header($input);

    return $result;
}

/**
 * Payment and receipt: cash/bank on one side, what it was for on the other.
 *
 * The bank side is a grid, not a single field, because one payment is often
 * settled several ways at once — part cash, part transfer, part adjustment —
 * and forcing it into one row would falsify the day book.
 */
function voucher_compose_tender(array $spec, array $input, array $ledgers, array $result, bool $draft = false): array
{
    $bankSide = (string) $spec['bank_side'];
    $otherSide = $bankSide === 'debit' ? 'credit' : 'debit';
    $bankLabel = strtolower((string) ($spec['bank_label'] ?? 'cash/bank'));

    $bankTotal = 0.0;
    $bankEntries = [];
    $rowCount = voucher_input_rows($input, 'tender_ledger');
    for ($index = 0; $index < $rowCount; $index++) {
        $ledgerId = (int) voucher_input_row($input, 'tender_ledger', $index);
        $amount = round((float) voucher_input_row($input, 'tender_amount', $index), 2);
        if ($ledgerId <= 0 && $amount <= 0) {
            continue;
        }
        $ledger = $ledgers[$ledgerId] ?? null;
        if (!$ledger) {
            $result['errors'][] = 'Row ' . ($index + 1) . ' of "' . $bankLabel . '" has no valid account.';
            continue;
        }
        if (empty($ledger['roles']['cash_bank'])) {
            $result['errors'][] = '"' . $ledger['name'] . '" is not a cash or bank account, so money cannot ' . ($bankSide === 'debit' ? 'arrive in' : 'leave') . ' it on this voucher.';
            continue;
        }
        if ($amount <= 0) {
            $result['errors'][] = 'Enter the amount ' . ($bankSide === 'debit' ? 'received into' : 'paid from') . ' "' . $ledger['name'] . '".';
            continue;
        }
        $instrumentNo = voucher_input_row($input, 'tender_instrument_no', $index);
        $mode = voucher_input_row($input, 'tender_mode', $index);
        $bankEntries[] = voucher_entry(
            $ledgerId,
            $bankSide,
            $amount,
            $mode !== '' ? voucher_instrument_label($mode) : '',
            '',
            '',
            $instrumentNo
        );
        $bankTotal += $amount;
    }
    if ($bankEntries === []) {
        $result['errors'][] = 'Name the cash or bank account and the amount under "' . ($spec['bank_label'] ?? 'Cash / bank') . '".';
    }

    $lineTotal = 0.0;
    $lineEntries = [];
    $rowCount = voucher_input_rows($input, 'line_ledger');
    for ($index = 0; $index < $rowCount; $index++) {
        $ledgerId = (int) voucher_input_row($input, 'line_ledger', $index);
        $amount = round((float) voucher_input_row($input, 'line_amount', $index), 2);
        if ($ledgerId <= 0 && $amount <= 0) {
            continue;
        }
        $ledger = $ledgers[$ledgerId] ?? null;
        if (!$ledger) {
            $result['errors'][] = 'Row ' . ($index + 1) . ' of "' . ($spec['lines_label'] ?? 'Particulars') . '" has no valid ledger.';
            continue;
        }
        if ($amount <= 0) {
            $result['errors'][] = 'Enter the amount against "' . $ledger['name'] . '".';
            continue;
        }
        $lineEntries[] = voucher_entry(
            $ledgerId,
            $otherSide,
            $amount,
            voucher_input_row($input, 'line_memo', $index),
            voucher_input_row($input, 'line_cost_centre', $index),
            '',
            voucher_input_row($input, 'line_reference', $index)
        );
        $lineTotal += $amount;
    }
    if ($lineEntries === []) {
        $result['errors'][] = 'List at least one ledger under "' . ($spec['lines_label'] ?? 'Particulars') . '".';
    }

    if ($result['errors'] === [] && round($bankTotal, 2) !== round($lineTotal, 2)) {
        $result['errors'][] = sprintf(
            '%s totals %s but %s totals %s. The two sides have to agree.',
            (string) ($spec['bank_label'] ?? 'Cash / bank'),
            number_format($bankTotal, 2),
            (string) ($spec['lines_label'] ?? 'Particulars'),
            number_format($lineTotal, 2)
        );
    }
    if ($draft) {
        $result['errors'] = [];
    }
    if ($result['errors'] !== []) {
        return $result;
    }

    $result['entries'] = $bankSide === 'debit'
        ? array_merge($bankEntries, $lineEntries)
        : array_merge($lineEntries, $bankEntries);
    $result['total'] = round($bankTotal, 2);
    $result['header'] = voucher_instrument_header($input);

    return $result;
}

/**
 * Sales, purchase, debit note, credit note.
 *
 * The person types the value lines and the tax rate; the party line and the tax
 * line are worked out. The party total is the sum of the amounts actually
 * posted, so the voucher balances to the paisa no matter how the rounding of
 * any one line fell.
 */
function voucher_compose_trade(array $spec, array $input, array $ledgers, array $result, bool $draft = false): array
{
    $partySide = (string) $spec['party_side'];
    $valueSide = (string) $spec['value_side'];

    // Settlement: against the party's own ledger, straight to cash/bank
    // (Tally's "party A/c name: Cash"), or split across however many ways the
    // money actually arrived.
    $settlement = (string) ($input['settlement_mode'] ?? 'party');
    if (!in_array($settlement, ['party', 'cash', 'split'], true)) {
        $settlement = 'party';
    }
    $settlementLedgerId = (int) ($input['settlement_ledger_id'] ?? 0);
    $settlementLedger = $ledgers[$settlementLedgerId] ?? null;
    $settlementRows = [];
    if ($settlement === 'split') {
        $settlementRows = voucher_settlement_rows($spec, $input, $ledgers, $result);
    } elseif (!$settlementLedger) {
        $result['errors'][] = $settlement === 'cash'
            ? 'Choose the cash or bank account this ' . strtolower((string) $spec['short']) . ' settles through.'
            : 'Choose the ' . strtolower((string) ($spec['party_label'] ?? 'party')) . ' — their ledger is the other side of this voucher.';
    } elseif ($settlement === 'cash' && empty($settlementLedger['roles']['cash_bank'])) {
        $result['errors'][] = '"' . $settlementLedger['name'] . '" is not a cash or bank account.';
    }

    $taxMode = in_array((string) ($input['tax_mode'] ?? 'none'), ['none', 'exclusive', 'inclusive'], true)
        ? (string) $input['tax_mode']
        : 'none';
    $taxRate = round((float) ($input['tax_rate'] ?? 0), 4);
    $taxLedgerId = (int) ($input['tax_ledger_id'] ?? 0);
    if ($taxMode !== 'none') {
        if ($taxRate <= 0) {
            $result['errors'][] = 'Enter the tax rate, or set the tax to "No tax".';
        }
        if (!isset($ledgers[$taxLedgerId])) {
            $result['errors'][] = 'Choose the tax ledger the VAT posts to.';
        }
    }

    $valueEntries = [];
    $taxableTotal = 0.0;
    $taxTotal = 0.0;
    $rowCount = voucher_input_rows($input, 'value_ledger');
    for ($index = 0; $index < $rowCount; $index++) {
        $ledgerId = (int) voucher_input_row($input, 'value_ledger', $index);
        $gross = round((float) voucher_input_row($input, 'value_amount', $index), 2);
        if ($ledgerId <= 0 && $gross <= 0) {
            continue;
        }
        $ledger = $ledgers[$ledgerId] ?? null;
        if (!$ledger) {
            $result['errors'][] = 'Row ' . ($index + 1) . ' of "' . ($spec['value_label'] ?? 'Value') . '" has no valid ledger.';
            continue;
        }
        if ($gross <= 0) {
            $result['errors'][] = 'Enter the amount against "' . $ledger['name'] . '".';
            continue;
        }
        // Inclusive: the amount typed already carries the tax, so it is peeled
        // back out. Exclusive: the tax is added on top.
        $taxable = $taxMode === 'inclusive' && $taxRate > 0
            ? round($gross / (1 + ($taxRate / 100)), 2)
            : $gross;
        $lineTax = $taxMode === 'none' ? 0.0 : round($taxable * ($taxRate / 100), 2);

        $quantity = (float) voucher_input_row($input, 'value_qty', $index);
        $rate = (float) voucher_input_row($input, 'value_rate', $index);
        $itemId = (int) voucher_input_row($input, 'value_item', $index);
        $description = voucher_input_row($input, 'value_description', $index);
        if ($quantity > 0 && $rate > 0) {
            $description = trim($description . ' — ' . rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.') . ' × ' . number_format($rate, 2), ' —');
        }
        // Stock only moves for a line that names both the goods and how many.
        // An item with no quantity is a description, not a movement.
        if ($itemId > 0 && $quantity <= 0) {
            $result['errors'][] = 'Line ' . ($index + 1) . ' names an item but no quantity. Give the quantity, or clear the item.';
        }

        $valueEntries[] = voucher_entry(
            $ledgerId,
            $valueSide,
            $taxable,
            $description,
            voucher_input_row($input, 'value_cost_centre', $index),
            $taxMode === 'none' ? '' : number_format($taxRate, 2) . '%',
            '',
            $itemId,
            $quantity
        );
        $taxableTotal += $taxable;
        $taxTotal += $lineTax;
    }
    if ($valueEntries === []) {
        $result['errors'][] = 'List at least one line under "' . ($spec['value_label'] ?? 'Value') . '".';
    }
    if (!empty($spec['needs_reason']) && trim((string) ($input['return_reason'] ?? '')) === '') {
        $result['errors'][] = 'Say why this note is being raised — the reason is part of the record.';
    }
    if ($draft) {
        $result['errors'] = [];
    }
    if ($result['errors'] !== []) {
        return $result;
    }

    $taxableTotal = round($taxableTotal, 2);
    $taxTotal = round($taxTotal, 2);
    $grandTotal = round($taxableTotal + $taxTotal, 2);

    $entries = $valueEntries;
    if ($taxTotal > 0 && isset($ledgers[$taxLedgerId])) {
        $entries[] = voucher_entry(
            $taxLedgerId,
            (string) $spec['tax_side'],
            $taxTotal,
            'VAT @ ' . rtrim(rtrim(number_format($taxRate, 2), '0'), '.') . '% on ' . number_format($taxableTotal, 2),
            '',
            number_format($taxRate, 2) . '%',
            (string) ($input['reference_no'] ?? '')
        );
    }
    if ($settlement === 'split') {
        $settledTotal = 0.0;
        foreach ($settlementRows as $settlementRow) {
            $settledTotal += (float) $settlementRow['amount'];
        }
        $settledTotal = round($settledTotal, 2);
        // The one rule a split cannot bend: what was taken equals what was
        // billed. A rupee adrift here is a rupee the trial balance never
        // balances by, and it surfaces months later as a suspense nobody can
        // explain. A draft may be half-typed; a posting may not.
        if (!$draft && $settlementRows !== [] && abs($settledTotal - $grandTotal) >= 0.005) {
            $shortfall = round($grandTotal - $settledTotal, 2);
            $result['errors'][] = 'The settlement lines come to ' . number_format($settledTotal, 2)
                . ' but this ' . strtolower((string) $spec['short']) . ' is ' . number_format($grandTotal, 2) . ' — '
                . ($shortfall > 0
                    ? number_format($shortfall, 2) . ' is still unallocated. Put the rest on credit, or on another mode.'
                    : number_format(-$shortfall, 2) . ' more has been allocated than was billed.');

            return $result;
        }
        $settlementEntries = [];
        foreach ($settlementRows as $settlementRow) {
            // How the money came in is written onto the line itself, because
            // the day book is where somebody looks to reconcile the drawer
            // against the wallet statement at close of business.
            $memoBits = [voucher_instrument_label((string) $settlementRow['mode'])];
            if ((string) $settlementRow['instrument_no'] !== '') {
                $memoBits[] = (string) $settlementRow['instrument_no'];
            }
            if ((string) ($input['reference_no'] ?? '') !== '') {
                $memoBits[] = 'against ' . (string) $input['reference_no'];
            }
            $settlementEntries[] = voucher_entry(
                (int) $settlementRow['ledger_id'],
                $partySide,
                (float) $settlementRow['amount'],
                implode(' — ', $memoBits),
                '',
                '',
                (string) $settlementRow['instrument_no'] !== ''
                    ? (string) $settlementRow['instrument_no']
                    : (string) ($input['reference_no'] ?? '')
            );
        }
        $entries = $partySide === 'debit'
            ? array_merge($settlementEntries, $entries)
            : array_merge($entries, $settlementEntries);
    } elseif ($settlementLedger !== null && $grandTotal > 0) {
        // A draft may not have named the party yet; the value lines still keep.
        $partyEntry = voucher_entry(
            $settlementLedgerId,
            $partySide,
            $grandTotal,
            (string) ($input['reference_no'] ?? '') !== '' ? 'Against ' . (string) $input['reference_no'] : '',
            '',
            '',
            (string) ($input['reference_no'] ?? '')
        );
        $entries = $partySide === 'debit'
            ? array_merge([$partyEntry], $entries)
            : array_merge($entries, [$partyEntry]);
    }

    $result['entries'] = $entries;
    $result['total'] = $grandTotal;
    $result['header'] = [
        'reference_date' => voucher_date_or_null((string) ($input['reference_date'] ?? '')),
        'return_reason' => !empty($spec['needs_reason']) ? substr(trim((string) ($input['return_reason'] ?? '')), 0, 255) : null,
    ];
    // The register has room for one instrument; a split hands it the first row
    // that actually moved money, the way a mixed payment already does.
    foreach ($settlementRows as $settlementRow) {
        if (isset(voucher_instrument_modes()[(string) $settlementRow['mode']])) {
            $result['header']['instrument_type'] = (string) $settlementRow['mode'];
            $result['header']['instrument_no'] = (string) $settlementRow['instrument_no'] !== ''
                ? (string) $settlementRow['instrument_no']
                : null;
            break;
        }
    }
    $result['taxable_total'] = $taxableTotal;
    $result['tax_total'] = $taxTotal;

    return $result;
}

/**
 * The settlement grid of a trade voucher: one row per way the money moved.
 *
 * A day's takings are not one thing. Part is cash, part is Fonepay, part a
 * card, and part is simply left on the customer's account. Posted as a single
 * line, the day book says something that did not happen and the drawer can
 * never be counted against it. Each row becomes its own entry on the party
 * side, so the till, the wallet and the receivable each carry what they took.
 *
 * Rows that name the party rather than a ledger carry the literal 'party'; the
 * caller resolves it to that party's own ledger, which is where the database
 * lives, so this stays pure and testable.
 */
function voucher_settlement_rows(array $spec, array $input, array $ledgers, array &$result): array
{
    $partyLedgerId = (int) ($input['settlement_party_ledger_id'] ?? 0);
    $partyLabel = strtolower((string) ($spec['party_label'] ?? 'party'));
    $rows = [];
    $rowCount = voucher_input_rows($input, 'settle_ledger');
    for ($index = 0; $index < $rowCount; $index++) {
        $choice = voucher_input_row($input, 'settle_ledger', $index);
        $amount = round((float) voucher_input_row($input, 'settle_amount', $index), 2);
        if ($choice === '' && $amount <= 0) {
            continue;
        }
        $isPartyRow = $choice === 'party';
        $ledgerId = $isPartyRow ? $partyLedgerId : (int) $choice;
        $ledger = $ledgers[$ledgerId] ?? null;
        if (!$ledger) {
            $result['errors'][] = $isPartyRow
                ? 'Line ' . ($index + 1) . ' of the settlement is on credit, so name the ' . $partyLabel . ' who owes it.'
                : 'Line ' . ($index + 1) . ' of the settlement does not name a valid account.';
            continue;
        }
        // Money settles somewhere it can sit. An income or expense head is the
        // other side of this voucher, never this side of it.
        if (!empty($ledger['roles']['income']) || !empty($ledger['roles']['expense'])) {
            $result['errors'][] = '"' . $ledger['name'] . '" is an income or expense head — a settlement cannot land in it.';
            continue;
        }
        if ($amount <= 0) {
            $result['errors'][] = 'Enter how much was settled through "' . $ledger['name'] . '".';
            continue;
        }
        $mode = voucher_input_row($input, 'settle_mode', $index);
        if (!isset(voucher_settlement_modes()[$mode])) {
            $mode = $isPartyRow || empty($ledger['roles']['cash_bank']) ? 'credit' : 'cash';
        }
        $rows[] = [
            'ledger_id' => $ledgerId,
            'amount' => $amount,
            'mode' => $mode,
            'instrument_no' => substr(voucher_input_row($input, 'settle_instrument_no', $index), 0, 80),
            'is_party' => $isPartyRow,
        ];
    }
    if ($rows === []) {
        $result['errors'][] = 'Say how this ' . strtolower((string) $spec['short'])
            . ' was settled — at least one line, whether that is cash, a wallet, or the whole of it on credit.';
    }

    return $rows;
}

/** Journal: the classic two-column grid, unchanged in spirit. */
function voucher_compose_journal(array $spec, array $input, array $ledgers, array $result, bool $draft = false): array
{
    $debitTotal = 0.0;
    $creditTotal = 0.0;
    $entries = [];
    $rowCount = voucher_input_rows($input, 'ledger_id');
    for ($index = 0; $index < $rowCount; $index++) {
        $ledgerId = (int) voucher_input_row($input, 'ledger_id', $index);
        $entryType = voucher_input_row($input, 'entry_type', $index);
        $amount = round((float) voucher_input_row($input, 'amount', $index), 2);
        if ($ledgerId <= 0 || $amount <= 0 || !in_array($entryType, ['debit', 'credit'], true)) {
            continue;
        }
        $ledger = $ledgers[$ledgerId] ?? null;
        if (!$ledger) {
            continue;
        }
        // Tally refuses cash and bank ledgers in a journal outright. Nepali
        // practice is not always so strict, so this warns and still posts —
        // the entry is unusual, not wrong.
        if (!empty($ledger['roles']['cash_bank'])) {
            $result['warnings'][] = '"' . $ledger['name'] . '" is a cash/bank account. Money actually moving is better recorded as a payment, receipt, or contra voucher.';
        }
        $entries[] = voucher_entry(
            $ledgerId,
            $entryType,
            $amount,
            voucher_input_row($input, 'memo', $index),
            voucher_input_row($input, 'line_cost_centre', $index),
            voucher_input_row($input, 'line_tax', $index),
            voucher_input_row($input, 'line_reference', $index)
        );
        if ($entryType === 'debit') {
            $debitTotal += $amount;
        } else {
            $creditTotal += $amount;
        }
    }

    if (count($entries) < 2) {
        $result['errors'][] = 'A journal needs at least one debit and one credit line.';
    } elseif (round($debitTotal, 2) !== round($creditTotal, 2)) {
        $result['errors'][] = sprintf('Debits total %s and credits total %s. A journal only posts when they agree.', number_format($debitTotal, 2), number_format($creditTotal, 2));
    }
    if ($draft) {
        $result['errors'] = [];
    }

    $result['entries'] = $entries;
    $result['total'] = round($debitTotal, 2);

    return $result;
}

/**
 * One posted line, in the shape create_voucher_with_entries expects.
 *
 * item_id and quantity ride along on trade lines: they are what makes a
 * purchase raise the stock as well as the payable.
 */
function voucher_entry(int $ledgerId, string $side, float $amount, string $memo = '', string $costCentre = '', string $taxCode = '', string $reference = '', int $itemId = 0, float $quantity = 0.0): array
{
    return [
        'ledger_id' => $ledgerId,
        'entry_type' => $side === 'credit' ? 'credit' : 'debit',
        'amount' => round($amount, 2),
        'memo' => substr(trim($memo), 0, 255),
        'cost_centre' => substr(trim($costCentre), 0, 80),
        'tax_code' => substr(trim($taxCode), 0, 40),
        'line_reference' => substr(trim($reference), 0, 120),
        'item_id' => max(0, $itemId),
        'quantity' => round(max(0.0, $quantity), 3),
    ];
}

/**
 * The payment modes a cash/bank line can carry.
 *
 * A counter here settles half a dozen ways in one afternoon, so the list names
 * the rails people actually use rather than filing Fonepay and a QR scan under
 * "online" and losing the distinction the moment it is posted.
 *
 * The LABEL is what gets written onto the line; voucher_instrument_key_for_label()
 * reads it back. Add keys freely — renaming one strands every voucher already
 * posted under the old label, which is why the legacy names are kept there.
 */
function voucher_instrument_modes(): array
{
    return [
        'cash' => 'Cash',
        'cheque' => 'Cheque',
        'bank_transfer' => 'Bank transfer',
        'card' => 'Card',
        'fonepay' => 'Fonepay',
        'qr' => 'QR scan',
        'esewa' => 'eSewa',
        'khalti' => 'Khalti',
        'wallet' => 'Wallet (other)',
        'online' => 'Online gateway',
        'adjustment' => 'Adjustment',
    ];
}

/**
 * The ways a trade voucher can be settled: every cash rail above, plus the one
 * that moves no money at all — the part left standing on the party's account.
 */
function voucher_settlement_modes(): array
{
    return ['credit' => 'On credit'] + voucher_instrument_modes();
}

/** 'bank_transfer' reads as 'Bank transfer' on the voucher line. */
function voucher_instrument_label(string $mode): string
{
    return voucher_settlement_modes()[$mode] ?? ucfirst(str_replace('_', ' ', $mode));
}

/**
 * The mode key behind a label already written onto a posted line.
 *
 * A label this version renamed still has to lead back to its key, or an old
 * voucher reopens with its mode reset to the first in the list and re-posting
 * quietly rewrites how the money came in.
 */
function voucher_instrument_key_for_label(string $label): string
{
    $label = trim($label);
    if ($label === '') {
        return '';
    }
    foreach (voucher_settlement_modes() as $key => $text) {
        if (strcasecmp($text, $label) === 0) {
            return $key;
        }
    }
    // Labels this file used to carry.
    $legacy = ['wallet / qr' => 'wallet'];

    return $legacy[strtolower($label)] ?? '';
}

/**
 * The instrument fields a tender or contra voucher stores on its header.
 *
 * A mixed payment carries an instrument per row; the header keeps the first
 * one, which is what a register column has room to show.
 */
function voucher_instrument_header(array $input): array
{
    $mode = trim((string) ($input['instrument_type'] ?? ''));
    if ($mode === '') {
        $mode = voucher_input_row($input, 'tender_mode', 0);
    }
    $number = trim((string) ($input['instrument_no'] ?? ''));
    if ($number === '') {
        $number = voucher_input_row($input, 'tender_instrument_no', 0);
    }

    return [
        'instrument_type' => isset(voucher_instrument_modes()[$mode]) ? $mode : null,
        'instrument_no' => substr($number, 0, 80) ?: null,
        'instrument_date' => voucher_date_or_null((string) ($input['instrument_date'] ?? '')),
    ];
}

/** A YYYY-MM-DD date, or null for anything else. */
function voucher_date_or_null(string $date): ?string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
}

// ---------------------------------------------------------------------------
// Reading a saved voucher back into its own screen
// ---------------------------------------------------------------------------

/**
 * Split a stored voucher's lines back into the shape its screen shows.
 *
 * Editing a payment must reopen as a payment — the bank rows in the bank grid
 * and the rest underneath — not as a flat list of debits and credits the person
 * has to re-read. Where a voucher cannot be split cleanly (an auto-posted one,
 * say, that predates these screens) the caller falls back to the journal grid.
 */
function voucher_decompose(string $type, array $voucher, array $entries, array $ledgers): array
{
    $spec = voucher_type_spec($type);
    $layout = (string) $spec['layout'];

    if ($layout === 'transfer') {
        $outLegs = [];
        $inLegs = [];
        // A contra whose ends are not both cash or bank was posted by
        // something other than this screen. It opens in the journal grid,
        // which can express anything, rather than in two lists that would
        // refuse to re-post it.
        $legsFit = $entries !== [];
        foreach ($entries as $entry) {
            $ledger = $ledgers[(int) $entry['ledger_id']] ?? null;
            if (empty($ledger['roles']['cash_bank'])) {
                $legsFit = false;
            }
            $leg = [
                'ledger_id' => (string) (int) $entry['ledger_id'],
                'amount' => (float) $entry['amount'],
            ];
            if ((string) $entry['entry_type'] === 'credit') {
                $outLegs[] = $leg;
            } else {
                $inLegs[] = $leg;
            }
        }
        $outTotal = 0.0;
        foreach ($outLegs as $leg) {
            $outTotal += (float) $leg['amount'];
        }

        return [
            'ok' => $outLegs !== [] && $inLegs !== [] && $legsFit,
            'contra_out' => $outLegs,
            'contra_in' => $inLegs,
            // The single-account shape this screen used before it had grids,
            // kept so anything still reading it sees the leading account.
            'contra_from_ledger' => (int) ($outLegs[0]['ledger_id'] ?? 0),
            'contra_to_ledger' => (int) ($inLegs[0]['ledger_id'] ?? 0),
            'contra_amount' => round($outTotal, 2),
        ];
    }

    if ($layout === 'tender') {
        $bankSide = (string) $spec['bank_side'];
        // The mode of each tender row was written onto its memo when it posted;
        // the header keeps the first one. Read both back so a cheque reopens as
        // a cheque rather than resetting to cash.
        $headerMode = (string) ($voucher['instrument_type'] ?? '');
        $tender = [];
        $lines = [];
        foreach ($entries as $entry) {
            $ledger = $ledgers[(int) $entry['ledger_id']] ?? null;
            $isBankRow = (string) $entry['entry_type'] === $bankSide && !empty($ledger['roles']['cash_bank']);
            if ($isBankRow) {
                $memo = trim((string) ($entry['memo'] ?? ''));
                $tender[] = [
                    'ledger_id' => (int) $entry['ledger_id'],
                    'mode' => voucher_instrument_key_for_label($memo) ?: ($tender === [] ? $headerMode : ''),
                    'amount' => (float) $entry['amount'],
                    'instrument_no' => (string) ($entry['line_reference'] ?? ''),
                ];
            } else {
                $lines[] = [
                    'ledger_id' => (int) $entry['ledger_id'],
                    'amount' => (float) $entry['amount'],
                    'memo' => (string) ($entry['memo'] ?? ''),
                    'cost_centre' => (string) ($entry['cost_centre'] ?? ''),
                    'reference' => (string) ($entry['line_reference'] ?? ''),
                ];
            }
        }

        return ['ok' => $tender !== [] && $lines !== [], 'tender' => $tender, 'lines' => $lines];
    }

    if ($layout === 'trade') {
        $partySide = (string) $spec['party_side'];
        // EVERY line on the party's side is a settlement line: the value and
        // the tax of a trade voucher always sit opposite the party, so what is
        // left over on this side is how the document was paid for — one line
        // when it went on account, several when the counter took it three ways.
        $settlements = [];
        $tax = null;
        $values = [];
        foreach ($entries as $entry) {
            $ledger = $ledgers[(int) $entry['ledger_id']] ?? null;
            if ((string) $entry['entry_type'] === $partySide) {
                $settlements[] = $entry;
                continue;
            }
            if ($tax === null && !empty($ledger['roles']['tax'])) {
                $tax = $entry;
                continue;
            }
            $quantity = (float) ($entry['quantity'] ?? 0);
            $amount = (float) $entry['amount'];
            // The memo carries "description — 4 × 500.00" only so the day book
            // reads well; the columns are what the screen reopens with.
            $description = (string) ($entry['memo'] ?? '');
            $splitAt = $quantity > 0 ? strrpos($description, ' — ') : false;
            $values[] = [
                'ledger_id' => (int) $entry['ledger_id'],
                'item_id' => (int) ($entry['item_id'] ?? 0),
                'qty' => $quantity > 0 ? rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.') : '',
                'rate' => $quantity > 0 ? number_format($amount / $quantity, 2, '.', '') : '',
                'amount' => $amount,
                'description' => $splitAt !== false ? substr($description, 0, $splitAt) : $description,
                'cost_centre' => (string) ($entry['cost_centre'] ?? ''),
            ];
        }
        $taxable = 0.0;
        foreach ($values as $value) {
            $taxable += $value['amount'];
        }
        $taxAmount = (float) ($tax['amount'] ?? 0);
        $settlement = $settlements[0] ?? null;
        $settlementLedger = $ledgers[(int) ($settlement['ledger_id'] ?? 0)] ?? null;

        // How each part of the money arrived was written onto its own line
        // when it posted; read it back so a sale taken half in cash and half on
        // Fonepay reopens saying exactly that, rather than as one lump.
        $settlementRows = [];
        $settlementsFit = true;
        foreach ($settlements as $entry) {
            $rowLedger = $ledgers[(int) $entry['ledger_id']] ?? null;
            // A line this screen could not offer back is a line it must not
            // pretend to own: an auto-posted voucher carrying a discount or a
            // write-off on the party's side belongs in the journal grid, which
            // can express anything, rather than in a settlement row that would
            // refuse to re-post.
            if (!empty($rowLedger['roles']['income']) || !empty($rowLedger['roles']['expense'])) {
                $settlementsFit = false;
            }
            $memo = trim((string) ($entry['memo'] ?? ''));
            $head = $memo;
            $splitMemoAt = strpos($memo, ' — ');
            if ($splitMemoAt !== false) {
                $head = substr($memo, 0, $splitMemoAt);
            }
            $mode = voucher_instrument_key_for_label($head);
            if ($mode === '') {
                $mode = !empty($rowLedger['roles']['cash_bank']) ? 'cash' : 'credit';
            }
            $settlementRows[] = [
                'ledger_id' => (string) (int) $entry['ledger_id'],
                'mode' => $mode,
                'instrument_no' => (string) ($entry['line_reference'] ?? ''),
                'amount' => (float) $entry['amount'],
            ];
        }

        return [
            'ok' => $settlement !== null && $values !== [] && $settlementsFit,
            'settlement_mode' => count($settlements) > 1
                ? 'split'
                : (!empty($settlementLedger['roles']['cash_bank']) ? 'cash' : 'party'),
            'settlement_ledger_id' => (int) ($settlement['ledger_id'] ?? 0),
            'settlements' => $settlementRows,
            'values' => $values,
            'tax_ledger_id' => (int) ($tax['ledger_id'] ?? 0),
            'tax_mode' => $taxAmount > 0 ? 'exclusive' : 'none',
            // Recovered from what was posted, so an edit that changes nothing
            // re-posts the same numbers.
            'tax_rate' => $taxable > 0 && $taxAmount > 0 ? round(($taxAmount / $taxable) * 100, 2) : 13.0,
        ];
    }

    $rows = [];
    foreach ($entries as $entry) {
        $isCredit = (string) $entry['entry_type'] === 'credit';
        $amount = number_format((float) $entry['amount'], 2, '.', '');
        $rows[] = [
            'ledger_id' => (int) $entry['ledger_id'],
            'memo' => (string) ($entry['memo'] ?? ''),
            'cost_centre' => (string) ($entry['cost_centre'] ?? ''),
            'tax_code' => (string) ($entry['tax_code'] ?? ''),
            'reference' => (string) ($entry['line_reference'] ?? ''),
            'debit' => $isCredit ? '' : $amount,
            'credit' => $isCredit ? $amount : '',
        ];
    }

    return ['ok' => true, 'journal' => $rows];
}

/**
 * Rebuild a screen's prefill from the fields that were just submitted.
 *
 * A ten-line payment voucher rejected for a two-rupee mismatch used to come
 * back empty, and the person typed it again. This puts it back as it was, with
 * the complaint above it.
 */
function voucher_prefill_from_input(string $type, array $input): array
{
    $spec = voucher_type_spec($type);
    $layout = (string) $spec['layout'];
    $prefill = [
        'party_id' => (int) ($input['party_id'] ?? 0),
        'reference_no' => (string) ($input['reference_no'] ?? ''),
        'reference_date' => (string) ($input['reference_date'] ?? ''),
        'instrument_type' => (string) ($input['instrument_type'] ?? ''),
        'instrument_no' => (string) ($input['instrument_no'] ?? ''),
        'instrument_date' => (string) ($input['instrument_date'] ?? ''),
        'return_reason' => (string) ($input['return_reason'] ?? ''),
        'title' => (string) ($input['title'] ?? ''),
        'narration' => (string) ($input['narration'] ?? ''),
        'notes' => (string) ($input['notes'] ?? ''),
        'voucher_date' => (string) ($input['voucher_date'] ?? ''),
        'posting_date' => (string) ($input['posting_date'] ?? ''),
        'due_date' => (string) ($input['due_date'] ?? ''),
        'priority' => (string) ($input['priority'] ?? 'medium'),
        'department' => (string) ($input['department'] ?? ''),
        'location' => (string) ($input['location'] ?? ''),
        'cost_centre' => (string) ($input['cost_centre'] ?? ''),
        'payment_terms' => (string) ($input['payment_terms'] ?? ''),
    ];

    if ($layout === 'transfer') {
        $prefill['contra_from_ledger'] = (int) ($input['contra_from_ledger'] ?? 0);
        $prefill['contra_to_ledger'] = (int) ($input['contra_to_ledger'] ?? 0);
        $prefill['contra_amount'] = (float) ($input['contra_amount'] ?? 0);
        foreach (['out', 'in'] as $legSide) {
            $prefill['contra_' . $legSide] = [];
            $legRowCount = voucher_input_rows($input, 'contra_' . $legSide . '_ledger');
            for ($index = 0; $index < $legRowCount; $index++) {
                $prefill['contra_' . $legSide][] = [
                    'ledger_id' => voucher_input_row($input, 'contra_' . $legSide . '_ledger', $index),
                    'amount' => voucher_input_row($input, 'contra_' . $legSide . '_amount', $index),
                ];
            }
        }

        return $prefill;
    }

    if ($layout === 'tender') {
        $prefill['tender'] = [];
        $rowCount = voucher_input_rows($input, 'tender_ledger');
        for ($index = 0; $index < $rowCount; $index++) {
            $prefill['tender'][] = [
                'ledger_id' => voucher_input_row($input, 'tender_ledger', $index),
                'mode' => voucher_input_row($input, 'tender_mode', $index),
                'instrument_no' => voucher_input_row($input, 'tender_instrument_no', $index),
                'amount' => voucher_input_row($input, 'tender_amount', $index),
            ];
        }
        $prefill['lines'] = [];
        $rowCount = voucher_input_rows($input, 'line_ledger');
        for ($index = 0; $index < $rowCount; $index++) {
            $prefill['lines'][] = [
                'ledger_id' => voucher_input_row($input, 'line_ledger', $index),
                'memo' => voucher_input_row($input, 'line_memo', $index),
                'cost_centre' => voucher_input_row($input, 'line_cost_centre', $index),
                'reference' => voucher_input_row($input, 'line_reference', $index),
                'amount' => voucher_input_row($input, 'line_amount', $index),
            ];
        }

        return $prefill;
    }

    if ($layout === 'trade') {
        $submittedMode = (string) ($input['settlement_mode'] ?? 'party');
        $prefill['settlement_mode'] = in_array($submittedMode, ['cash', 'split'], true) ? $submittedMode : 'party';
        $prefill['settlement_ledger_id'] = (int) ($input['settlement_ledger_id'] ?? 0);
        $prefill['settlements'] = [];
        $settleRowCount = voucher_input_rows($input, 'settle_ledger');
        for ($index = 0; $index < $settleRowCount; $index++) {
            $prefill['settlements'][] = [
                'ledger_id' => voucher_input_row($input, 'settle_ledger', $index),
                'mode' => voucher_input_row($input, 'settle_mode', $index),
                'instrument_no' => voucher_input_row($input, 'settle_instrument_no', $index),
                'amount' => voucher_input_row($input, 'settle_amount', $index),
            ];
        }
        $prefill['warehouse_id'] = (int) ($input['warehouse_id'] ?? 0);
        $prefill['tax_mode'] = (string) ($input['tax_mode'] ?? 'exclusive');
        $prefill['tax_rate'] = (float) ($input['tax_rate'] ?? 13);
        $prefill['tax_ledger_id'] = (int) ($input['tax_ledger_id'] ?? 0);
        $prefill['values'] = [];
        $rowCount = voucher_input_rows($input, 'value_ledger');
        for ($index = 0; $index < $rowCount; $index++) {
            $prefill['values'][] = [
                'ledger_id' => voucher_input_row($input, 'value_ledger', $index),
                'item_id' => voucher_input_row($input, 'value_item', $index),
                'description' => voucher_input_row($input, 'value_description', $index),
                'qty' => voucher_input_row($input, 'value_qty', $index),
                'rate' => voucher_input_row($input, 'value_rate', $index),
                'cost_centre' => voucher_input_row($input, 'value_cost_centre', $index),
                'amount' => voucher_input_row($input, 'value_amount', $index),
            ];
        }

        return $prefill;
    }

    $prefill['journal'] = [];
    $rowCount = voucher_input_rows($input, 'ledger_id');
    for ($index = 0; $index < $rowCount; $index++) {
        $side = voucher_input_row($input, 'entry_type', $index);
        $amount = voucher_input_row($input, 'amount', $index);
        $prefill['journal'][] = [
            'ledger_id' => voucher_input_row($input, 'ledger_id', $index),
            'memo' => voucher_input_row($input, 'memo', $index),
            'cost_centre' => voucher_input_row($input, 'line_cost_centre', $index),
            'tax_code' => voucher_input_row($input, 'line_tax', $index),
            'reference' => voucher_input_row($input, 'line_reference', $index),
            'debit' => $side === 'credit' ? '' : $amount,
            'credit' => $side === 'credit' ? $amount : '',
        ];
    }

    return $prefill;
}
