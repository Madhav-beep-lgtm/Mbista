<?php
declare(strict_types=1);

/**
 * Fixed-asset IFRS calculation engine (migration 037).
 *
 * Pure, deterministic calculators for:
 *   - IAS 16 depreciation (straight-line, diminishing balance, units of prod.)
 *   - IAS 38 amortization (straight-line for finite-life intangibles)
 *   - IFRS 16 right-of-use asset + lease liability amortization schedule
 *   - IFRS 5 held-for-sale measurement (lower of carrying and FV less costs)
 *   - IAS 36 impairment (recoverable = higher of FVLCD and VIU) + capped reversal
 *
 * Side-effect free so the worked examples in the spec assert to the exact rupee
 * (see database/test_fixed_assets.php). Schedules are generated in full rather
 * than relying on the simplified single-line examples.
 */

const FA_MONEY_SCALE = 2;

function fa_round(float $n): float { return round($n, FA_MONEY_SCALE); }

// ---------------------------------------------------------------------------
// IAS 16 / IAS 38 depreciation & amortization
// ---------------------------------------------------------------------------

/**
 * Straight-line annual / monthly charge.
 * @return array{depreciable: float, annual: float, monthly: float}
 */
function fa_straight_line(float $cost, float $residual, float $usefulLifeYears): array
{
    $depreciable = max(0.0, $cost - $residual);
    $annual = $usefulLifeYears > 0 ? $depreciable / $usefulLifeYears : 0.0;
    return [
        'depreciable' => fa_round($depreciable),
        'annual' => fa_round($annual),
        'monthly' => fa_round($annual / 12),
    ];
}

/**
 * Diminishing-balance charge for one period at $ratePct of opening carrying,
 * never taking carrying below residual.
 */
function fa_diminishing_balance(float $openingCarrying, float $residual, float $ratePct): float
{
    $charge = $openingCarrying * ($ratePct / 100.0);
    $floor = max(0.0, $openingCarrying - $residual);
    return fa_round(min($charge, $floor));
}

/**
 * Units-of-production charge for a period.
 */
function fa_units_of_production(float $cost, float $residual, float $totalUnits, float $unitsThisPeriod): float
{
    if ($totalUnits <= 0) {
        return 0.0;
    }
    $depreciable = max(0.0, $cost - $residual);
    return fa_round($depreciable * ($unitsThisPeriod / $totalUnits));
}

/**
 * Full monthly straight-line depreciation schedule from the available-for-use
 * date. Depreciation begins when the asset is available for use and the final
 * period absorbs rounding so accumulated depreciation exactly equals the
 * depreciable amount.
 *
 * @return array<int, array{period:int, depreciation:float, accumulated:float, carrying:float}>
 */
function fa_depreciation_schedule_sl(float $cost, float $residual, int $usefulLifeMonths): array
{
    $depreciable = max(0.0, $cost - $residual);
    if ($usefulLifeMonths <= 0) {
        return [];
    }
    $perMonth = fa_round($depreciable / $usefulLifeMonths);
    $rows = [];
    $accumulated = 0.0;
    for ($m = 1; $m <= $usefulLifeMonths; $m++) {
        $charge = $m === $usefulLifeMonths ? fa_round($depreciable - $accumulated) : $perMonth;
        $accumulated = fa_round($accumulated + $charge);
        $rows[] = [
            'period' => $m,
            'depreciation' => $charge,
            'accumulated' => $accumulated,
            'carrying' => fa_round($cost - $accumulated),
        ];
    }
    return $rows;
}

/** IAS 38 finite-life amortization mirrors straight-line depreciation. */
function fa_amortization(float $cost, float $residual, float $usefulLifeYears): array
{
    return fa_straight_line($cost, $residual, $usefulLifeYears);
}

// ---------------------------------------------------------------------------
// IFRS 16 right-of-use asset + lease liability
// ---------------------------------------------------------------------------

/**
 * Initial ROU asset = initial lease liability + payments at/before commencement
 * (prepayments) + initial direct costs + estimated restoration - lease incentives.
 */
function fa_rou_initial(float $initialLiability, float $prepayments, float $initialDirectCosts, float $restoration, float $leaseIncentives): float
{
    return fa_round($initialLiability + $prepayments + $initialDirectCosts + $restoration - $leaseIncentives);
}

/**
 * Lease-liability amortization schedule.
 *
 * @param float  $liability   initial lease liability (present value of payments)
 * @param float  $ratePerPeriod discount rate PER PERIOD as a fraction (e.g. 0.01 monthly)
 * @param float  $payment     level payment per period
 * @param int    $periods     number of payment periods
 * @param string $timing      'arrears' (end of period) or 'advance' (start)
 * @return array<int, array{period:int, opening:float, interest:float, payment:float, principal:float, closing:float}>
 */
function fa_lease_schedule(float $liability, float $ratePerPeriod, float $payment, int $periods, string $timing = 'arrears'): array
{
    $rows = [];
    $opening = $liability;
    for ($p = 1; $p <= $periods; $p++) {
        // Advance leases accrue interest on the balance AFTER the start-of-period
        // payment; arrears leases accrue on the full opening balance. In both
        // cases the principal reduction is payment minus interest. The final
        // period absorbs rounding so the liability closes exactly at zero.
        $interestBase = $timing === 'advance' ? ($opening - $payment) : $opening;
        $interest = fa_round(max(0.0, $interestBase) * $ratePerPeriod);
        $principal = fa_round($payment - $interest);
        $closing = ($p === $periods) ? 0.0 : fa_round($opening - $principal);
        if ($p === $periods) {
            $principal = fa_round($opening); // clear the residual balance exactly
            $payment = fa_round($principal + $interest);
        }
        $rows[] = [
            'period' => $p, 'opening' => fa_round($opening), 'interest' => $interest,
            'payment' => fa_round($payment), 'principal' => $principal, 'closing' => $closing,
        ];
        $opening = $closing;
    }
    return $rows;
}

/**
 * Present value of a level annuity — used to derive the initial lease liability
 * from a payment stream when it is not given directly.
 */
function fa_annuity_present_value(float $payment, float $ratePerPeriod, int $periods, string $timing = 'arrears'): float
{
    if ($ratePerPeriod <= 0) {
        $pv = $payment * $periods;
    } else {
        $pv = $payment * (1 - (1 / pow(1 + $ratePerPeriod, $periods))) / $ratePerPeriod;
        if ($timing === 'advance') {
            $pv *= (1 + $ratePerPeriod);
        }
    }
    return fa_round($pv);
}

// ---------------------------------------------------------------------------
// IFRS 5 held-for-sale
// ---------------------------------------------------------------------------

/**
 * On classification: measure at lower of carrying amount and fair value less
 * costs to sell; recognise the shortfall as impairment; depreciation stops.
 *
 * @return array{fvlcs: float, measured: float, impairment: float, stop_depreciation: bool}
 */
function fa_held_for_sale(float $carryingAmount, float $fairValue, float $costsToSell): array
{
    $fvlcs = fa_round($fairValue - $costsToSell);
    $measured = fa_round(min($carryingAmount, $fvlcs));
    $impairment = fa_round(max(0.0, $carryingAmount - $fvlcs));
    return [
        'fvlcs' => $fvlcs,
        'measured' => $measured,
        'impairment' => $impairment,
        'stop_depreciation' => true,
    ];
}

// ---------------------------------------------------------------------------
// IAS 36 impairment
// ---------------------------------------------------------------------------

/**
 * Recoverable amount = higher of fair value less costs of disposal and value in
 * use. Impairment loss = carrying - recoverable when positive.
 *
 * @return array{recoverable: float, impairment: float, revised_carrying: float}
 */
function fa_impairment(float $carryingAmount, float $fairValueLessCostsOfDisposal, float $valueInUse): array
{
    $recoverable = fa_round(max($fairValueLessCostsOfDisposal, $valueInUse));
    $impairment = fa_round(max(0.0, $carryingAmount - $recoverable));
    return [
        'recoverable' => $recoverable,
        'impairment' => $impairment,
        'revised_carrying' => fa_round($carryingAmount - $impairment),
    ];
}

/**
 * Impairment reversal, capped so the revised carrying amount does not exceed
 * what it would have been (net of normal depreciation) had no impairment been
 * recognised (IAS 36.117). Never applies to goodwill (caller must exclude).
 *
 * @return array{reversal: float, revised_carrying: float}
 */
function fa_impairment_reversal(float $currentCarrying, float $recoverableAmount, float $carryingHadNoImpairment): array
{
    $ceiling = min($recoverableAmount, $carryingHadNoImpairment);
    $reversal = fa_round(max(0.0, $ceiling - $currentCarrying));
    return [
        'reversal' => $reversal,
        'revised_carrying' => fa_round($currentCarrying + $reversal),
    ];
}

// ---------------------------------------------------------------------------
// Scoped ledger mapping (asset -> category -> global) + posting helpers
// ---------------------------------------------------------------------------

/**
 * The asset posting purposes shown on the mapping editor, with the account
 * type each should point at.
 */
function fa_mapping_purposes(): array
{
    return [
        'ppe_cost'                 => ['label' => 'PPE / Asset Cost', 'expect' => 'asset'],
        'cwip'                     => ['label' => 'Capital Work-in-Progress', 'expect' => 'asset'],
        'acquisition_clearing'     => ['label' => 'Acquisition Clearing / Payable', 'expect' => 'liability'],
        'depreciation_expense'     => ['label' => 'Depreciation Expense', 'expect' => 'expense'],
        'accumulated_depreciation' => ['label' => 'Accumulated Depreciation', 'expect' => 'asset'],
        'amortization_expense'     => ['label' => 'Amortization Expense', 'expect' => 'expense'],
        'accumulated_amortization' => ['label' => 'Accumulated Amortization', 'expect' => 'asset'],
        'impairment_loss'          => ['label' => 'Impairment Loss', 'expect' => 'expense'],
        'accumulated_impairment'   => ['label' => 'Accumulated Impairment', 'expect' => 'asset'],
        'impairment_reversal_income' => ['label' => 'Impairment Reversal Income', 'expect' => 'revenue'],
        'revaluation_surplus'      => ['label' => 'Revaluation Surplus (OCI)', 'expect' => 'equity'],
        'revaluation_loss'         => ['label' => 'Revaluation Loss', 'expect' => 'expense'],
        'disposal_clearing'        => ['label' => 'Disposal Clearing / Cash', 'expect' => 'asset'],
        'gain_on_disposal'         => ['label' => 'Gain on Disposal', 'expect' => 'revenue'],
        'loss_on_disposal'         => ['label' => 'Loss on Disposal', 'expect' => 'expense'],
        'asset_held_for_sale'      => ['label' => 'Asset Held for Sale', 'expect' => 'asset'],
        'rou_asset'                => ['label' => 'Right-of-Use Asset', 'expect' => 'asset'],
        'lease_liability'          => ['label' => 'Lease Liability', 'expect' => 'liability'],
        'lease_interest_expense'   => ['label' => 'Lease Interest Expense', 'expect' => 'expense'],
        'opening_balance_equity'   => ['label' => 'Opening Balance Equity', 'expect' => 'equity'],
    ];
}

/**
 * The counterparty (funding/proceeds) leg of an asset transaction. Clearing
 * ledgers are only a default: every acquisition can owe a DIFFERENT supplier,
 * every disposal can bill a DIFFERENT buyer, so the caller passes the mode
 * the user picked on the form:
 *   'supplier' — the chosen party's payable ledger (auto-created per party)
 *   'buyer'    — the chosen party's receivable ledger
 *   'cash'     — the mapped default cash/bank ledger
 *   'opening'  — Opening Balance Equity (migrated balances, no counterparty)
 *   'clearing' — the mapped clearing purpose (legacy default)
 * Returns [ledger row, party id or null], or [null, null] when unresolvable.
 */
function fa_counterparty_ledger(int $companyId, string $mode, int $partyId, string $clearingPurpose, ?int $assetId = null): array
{
    if ($mode === 'supplier' || $mode === 'buyer') {
        if ($partyId <= 0) {
            return [null, null];
        }
        $chk = db()->prepare('SELECT COUNT(*) FROM accounting_parties WHERE id = :id AND company_id = :cid');
        $chk->execute(['id' => $partyId, 'cid' => $companyId]);
        if ((int) $chk->fetchColumn() === 0) {
            return [null, null];
        }
        $ledgerId = ensure_party_ledger($companyId, $partyId, $mode === 'supplier' ? 'payable' : 'receivable');
        if ($ledgerId <= 0) {
            return [null, null];
        }
        $s = db()->prepare('SELECT * FROM ledgers WHERE id = :id AND company_id = :cid LIMIT 1');
        $s->execute(['id' => $ledgerId, 'cid' => $companyId]);

        return [$s->fetch(PDO::FETCH_ASSOC) ?: null, $partyId];
    }
    if ($mode === 'cash') {
        return [get_mapped_ledger($companyId, 'default_cash_bank'), null];
    }
    if ($mode === 'opening') {
        return [fa_resolve_mapping($companyId, 'opening_balance_equity', $assetId), null];
    }

    return [fa_resolve_mapping($companyId, $clearingPurpose, $assetId), null];
}

/**
 * Resolve the ledger mapped to a purpose for an asset: asset -> its category ->
 * global. Returns the ledgers row or null when unmapped.
 */
function fa_resolve_mapping(int $companyId, string $purpose, ?int $assetId = null, ?int $categoryId = null): ?array
{
    if (!table_exists('asset_ledger_mappings')) {
        return null;
    }
    $ledger = static function (int $ledgerId) use ($companyId): ?array {
        if ($ledgerId <= 0) {
            return null;
        }
        $s = db()->prepare('SELECT * FROM ledgers WHERE id = :id AND company_id = :cid LIMIT 1');
        $s->execute(['id' => $ledgerId, 'cid' => $companyId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    if ($assetId) {
        $s = db()->prepare('SELECT ledger_id FROM asset_ledger_mappings WHERE company_id = :cid AND scope = \'asset\' AND asset_id = :aid AND purpose = :p LIMIT 1');
        $s->execute(['cid' => $companyId, 'aid' => $assetId, 'p' => $purpose]);
        $row = $ledger((int) ($s->fetchColumn() ?: 0));
        if ($row) { return $row; }
        // Callers rarely know the category — derive it from the asset so
        // per-category overrides apply to every asset event automatically.
        if (!$categoryId && column_exists('fixed_assets', 'category_id')) {
            $c = db()->prepare('SELECT category_id FROM fixed_assets WHERE id = :id AND company_id = :cid LIMIT 1');
            $c->execute(['id' => $assetId, 'cid' => $companyId]);
            $categoryId = (int) ($c->fetchColumn() ?: 0) ?: null;
        }
    }
    if ($categoryId) {
        $s = db()->prepare('SELECT ledger_id FROM asset_ledger_mappings WHERE company_id = :cid AND scope = \'category\' AND category_id = :cat AND purpose = :p LIMIT 1');
        $s->execute(['cid' => $companyId, 'cat' => $categoryId, 'p' => $purpose]);
        $row = $ledger((int) ($s->fetchColumn() ?: 0));
        if ($row) { return $row; }
    }
    $s = db()->prepare('SELECT ledger_id FROM asset_ledger_mappings WHERE company_id = :cid AND scope = \'global\' AND purpose = :p LIMIT 1');
    $s->execute(['cid' => $companyId, 'p' => $purpose]);
    return $ledger((int) ($s->fetchColumn() ?: 0));
}

function fa_missing_mappings(int $companyId, array $purposes, ?int $assetId = null, ?int $categoryId = null): array
{
    $missing = [];
    foreach ($purposes as $p) {
        if (fa_resolve_mapping($companyId, $p, $assetId, $categoryId) === null) {
            $missing[] = $p;
        }
    }
    return $missing;
}


/**
 * Split a revaluation change between profit or loss and OCI.
 * Positive delta = increase; negative delta = decrease.
 */
function fa_revaluation_allocation(
    float $delta,
    float $existingReserve,
    float $priorLossBalance
): array {
    $delta = fa_round($delta);
    $existingReserve = max(0.0, fa_round($existingReserve));
    $priorLossBalance = max(0.0, fa_round($priorLossBalance));

    $result = [
        'pnl_reversal' => 0.0,
        'oci_increase' => 0.0,
        'oci_decrease' => 0.0,
        'pnl_loss' => 0.0,
    ];

    if ($delta > 0) {
        $result['pnl_reversal'] = fa_round(min($delta, $priorLossBalance));
        $result['oci_increase'] = fa_round($delta - $result['pnl_reversal']);
    } elseif ($delta < 0) {
        $decrease = abs($delta);
        $result['oci_decrease'] = fa_round(min($decrease, $existingReserve));
        $result['pnl_loss'] = fa_round($decrease - $result['oci_decrease']);
    }

    return $result;
}

/**
 * The straight-line monthly charge for an asset row for one period, capped so
 * carrying never drops below residual.
 */
function fa_asset_monthly_charge(array $asset): float
{
    $residual = (float) ($asset['residual_value'] ?? 0);
    $life = (int) ($asset['useful_life_months'] ?? 0);
    $accumulated = (float) ($asset['accumulated_depreciation'] ?? 0);
    $carryingCap = max(
        0.0,
        (float) ($asset['carrying_amount'] ?? 0) - $residual
    );

    $hasRevaluationBase = array_key_exists('depreciation_base', $asset)
        && $asset['depreciation_base'] !== null;

    if ($hasRevaluationBase) {
        $base = max(0.0, (float) $asset['depreciation_base']);
        $life = max(1, (int) ($asset['revaluation_life_months'] ?? $life));
        $resetAccumulated = (float) ($asset['depreciation_base_accumulated'] ?? 0);
        $usedSinceRevaluation = max(0.0, $accumulated - $resetAccumulated);
        $depreciable = max(0.0, $base - $residual);
        $perMonth = fa_round($depreciable / $life);
        $remaining = max(0.0, $depreciable - $usedSinceRevaluation);

        return fa_round(min($perMonth, $remaining, $carryingCap));
    }

    $cost = (float) ($asset['cost'] ?? 0)
        + (float) ($asset['directly_attributable_cost'] ?? 0)
        + (float) ($asset['restoration_provision'] ?? 0);

    if ($life <= 0) {
        return 0.0;
    }

    $depreciable = max(0.0, $cost - $residual);
    $perMonth = fa_round($depreciable / $life);
    $remaining = max(0.0, $depreciable - $accumulated);

    return fa_round(min($perMonth, $remaining, $carryingCap));
}

/**
 * The purposes each fixed-asset event needs mapped before it can post
 * (mirrors inv_transaction_purposes for the asset module).
 */
function fa_event_purposes(string $event): array
{
    return match ($event) {
        'acquisition'        => ['ppe_cost', 'acquisition_clearing'],
        'cwip_capitalize'    => ['ppe_cost', 'cwip'],
        'depreciation'       => ['depreciation_expense', 'accumulated_depreciation'],
        'amortization'       => ['amortization_expense', 'accumulated_amortization'],
        'impairment'         => ['impairment_loss', 'accumulated_impairment'],
        'impairment_reversal'=> ['accumulated_impairment', 'impairment_reversal_income'],
        'revaluation_up'     => ['ppe_cost', 'revaluation_surplus'],
        'revaluation_down'   => ['revaluation_loss', 'ppe_cost'],
        'held_for_sale'      => ['asset_held_for_sale', 'ppe_cost'],
        'lease_commence'     => ['rou_asset', 'lease_liability'],
        'lease_interest'     => ['lease_interest_expense', 'lease_liability'],
        'lease_payment'      => ['lease_liability', 'acquisition_clearing'],
        'disposal'           => ['disposal_clearing', 'ppe_cost'],
        default              => [],
    };
}


/**
 * The five Income Tax Act pools, with the rate each is written down at.
 *
 * Tax depreciates the POOL, accounting depreciates the ASSET over its useful
 * life, so the two figures never agree - and the difference between them is
 * exactly what deferred tax is computed on. Nothing can be reported by pool
 * until each asset says which pool it is in, which is why the register form
 * asks for one.
 */
function fa_tax_pools(): array
{
    return [
        'a' => 'Pool A - buildings and structures (5%)',
        'b' => 'Pool B - computers, fixtures, office equipment (25%)',
        'c' => 'Pool C - automobiles, buses, minibuses (20%)',
        'd' => 'Pool D - construction and earth-moving plant, and anything not in another pool (15%)',
        'e' => 'Pool E - intangibles other than depreciable assets (over useful life)',
    ];
}

/**
 * The asset register as a movement schedule for one window of time.
 *
 * Every figure is a movement between two dates rather than a balance as it
 * stands today, because "what did this cost" is a different question from
 * "what came in this year". Each block reads opening, what moved, and closing:
 *
 *   Cost                 opening | addition | disposal | closing
 *   Accumulated dep.     opening | current  | reversal | closing
 *   Accumulated imp.     opening | current  | reversal | closing
 *   Carrying amount      opening | addition | disposal | closing
 *
 * Opening is the position the instant before $from - the previous year's
 * closing, in other words - and current/addition/disposal are what happened
 * inside the window. Reversal is what left with a disposed asset: everything
 * accumulated against it is derecognised, so its closing falls to zero.
 *
 * Three queries whatever the company holds - the assets, their depreciation
 * bucketed by date, and their impairments bucketed by date. Nothing runs per
 * asset, so the register does not slow down as the register grows.
 *
 * An asset disposed before $from is not in the window at all, and one bought
 * after $to has not happened yet; both are left out rather than shown as
 * empty rows.
 */
function fa_register_schedule(int $companyId, string $from, string $to, array $filters = []): array
{
    // Acquisition date, in the order the facts are trustworthy: what the bill
    // says, else when it was ready to use, else when somebody typed it in.
    $acquired = 'COALESCE(a.purchase_date, a.available_for_use_date, DATE(a.created_at))';

    $where = ['a.company_id = :cid', $acquired . ' <= :to_a', '(a.disposed_on IS NULL OR a.disposed_on >= :from_a)'];
    $params = ['cid' => $companyId, 'to_a' => $to, 'from_a' => $from];
    foreach (['status' => 'a.status', 'class' => 'a.asset_class', 'tax_pool' => 'a.tax_pool'] as $key => $column) {
        if ((string) ($filters[$key] ?? '') !== '') {
            $where[] = $column . ' = :f_' . $key;
            $params['f_' . $key] = (string) $filters[$key];
        }
    }
    if ((int) ($filters['category_id'] ?? 0) > 0) {
        $where[] = 'a.category_id = :f_category';
        $params['f_category'] = (int) $filters['category_id'];
    }

    $stmt = db()->prepare(
        'SELECT a.id, a.asset_code, a.name, a.asset_class, a.tax_pool, a.depreciation_method, a.status,
                a.cost, a.disposed_on, a.disposal_proceeds, a.disposal_gain_loss, a.category_id,
                ' . $acquired . ' AS acquired_on, c.name AS category_name
         FROM fixed_assets a
         LEFT JOIN asset_categories c ON c.id = a.category_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY a.asset_code ASC'
    );
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($assets === []) {
        return [];
    }

    $ids = array_map(static fn (array $a): int => (int) $a['id'], $assets);
    $ph = implode(',', array_fill(0, count($ids), '?'));

    // Only POSTED depreciation counts. A schedule row that was computed but
    // never posted is a plan, not a charge, and must not show up in a register
    // that is meant to agree with the ledger.
    $depStmt = db()->prepare(
        'SELECT asset_id,
                SUM(CASE WHEN period_date < ? THEN depreciation ELSE 0 END) AS opening,
                SUM(CASE WHEN period_date >= ? AND period_date <= ? THEN depreciation ELSE 0 END) AS current
         FROM asset_depreciation_schedule
         WHERE company_id = ? AND posted = 1 AND period_date <= ? AND asset_id IN (' . $ph . ')
         GROUP BY asset_id'
    );
    $depStmt->execute(array_merge([$from, $from, $to, $companyId, $to], $ids));
    $dep = [];
    foreach ($depStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dep[(int) $row['asset_id']] = $row;
    }

    // held_for_sale and revaluation rows share this table but are not
    // impairment: only a loss and its reversal move accumulated impairment.
    $impStmt = db()->prepare(
        'SELECT asset_id,
                SUM(CASE WHEN test_date < ? THEN impairment_loss - reversal ELSE 0 END) AS opening,
                SUM(CASE WHEN test_date >= ? AND test_date <= ? THEN impairment_loss ELSE 0 END) AS current,
                SUM(CASE WHEN test_date >= ? AND test_date <= ? THEN reversal ELSE 0 END) AS reversed
         FROM asset_impairments
         WHERE company_id = ? AND test_date <= ? AND kind IN (?, ?) AND asset_id IN (' . $ph . ')
         GROUP BY asset_id'
    );
    $impStmt->execute(array_merge([$from, $from, $to, $from, $to, $companyId, $to, 'impairment', 'reversal'], $ids));
    $imp = [];
    foreach ($impStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $imp[(int) $row['asset_id']] = $row;
    }

    $rows = [];
    foreach ($assets as $a) {
        $id = (int) $a['id'];
        $cost = (float) $a['cost'];
        $acquiredOn = (string) $a['acquired_on'];
        $disposedOn = (string) ($a['disposed_on'] ?? '');
        $boughtInWindow = $acquiredOn >= $from && $acquiredOn <= $to;
        $soldInWindow = $disposedOn !== '' && $disposedOn >= $from && $disposedOn <= $to;

        $costOpening = $boughtInWindow ? 0.0 : $cost;
        $costAddition = $boughtInWindow ? $cost : 0.0;
        $costDisposal = $soldInWindow ? $cost : 0.0;

        $depOpening = (float) ($dep[$id]['opening'] ?? 0);
        $depCurrent = (float) ($dep[$id]['current'] ?? 0);
        // Everything accumulated against a sold asset goes out with it.
        $depReversal = $soldInWindow ? $depOpening + $depCurrent : 0.0;

        $impOpening = (float) ($imp[$id]['opening'] ?? 0);
        $impCurrent = (float) ($imp[$id]['current'] ?? 0);
        $impReversal = (float) ($imp[$id]['reversed'] ?? 0);
        if ($soldInWindow) {
            $impReversal = $impOpening + $impCurrent;
        }

        $costClosing = $costOpening + $costAddition - $costDisposal;
        $depClosing = $depOpening + $depCurrent - $depReversal;
        $impClosing = $impOpening + $impCurrent - $impReversal;

        $rows[] = [
            'id' => $id,
            'code' => (string) $a['asset_code'],
            'name' => (string) $a['name'],
            'category_id' => (int) ($a['category_id'] ?? 0),
            'category' => (string) ($a['category_name'] ?? ''),
            'class' => (string) $a['asset_class'],
            'tax_pool' => (string) ($a['tax_pool'] ?? ''),
            'method' => (string) $a['depreciation_method'],
            'status' => (string) $a['status'],
            'acquired_on' => $acquiredOn,
            'disposed_on' => $disposedOn,
            'sold_in_window' => $soldInWindow,
            'cost_opening' => fa_round($costOpening),
            'cost_addition' => fa_round($costAddition),
            'cost_disposal' => fa_round($costDisposal),
            'cost_closing' => fa_round($costClosing),
            'dep_opening' => fa_round($depOpening),
            'dep_current' => fa_round($depCurrent),
            'dep_reversal' => fa_round($depReversal),
            'dep_closing' => fa_round($depClosing),
            'imp_opening' => fa_round($impOpening),
            'imp_current' => fa_round($impCurrent),
            'imp_reversal' => fa_round($impReversal),
            'imp_closing' => fa_round($impClosing),
            'proceeds' => $a['disposal_proceeds'] === null ? null : (float) $a['disposal_proceeds'],
            'gain_loss' => $a['disposal_gain_loss'] === null ? null : (float) $a['disposal_gain_loss'],
            // Opening and addition carrying amounts are cost less accumulated
            // depreciation; closing also carries impairment off. That is the
            // register exactly as it was specified - see the note in the
            // register view about opening excluding impairment.
            'carry_opening' => fa_round($costOpening - $depOpening),
            'carry_addition' => fa_round($costAddition),
            'carry_disposal' => fa_round($costDisposal - $depReversal - $impReversal),
            'carry_closing' => fa_round($costClosing - $depClosing - $impClosing),
        ];
    }

    return $rows;
}

/**
 * Adds a set of register rows together, for a group subtotal or a grand total.
 */
function fa_register_totals(array $rows): array
{
    $keys = [
        'cost_opening', 'cost_addition', 'cost_disposal', 'cost_closing',
        'dep_opening', 'dep_current', 'dep_reversal', 'dep_closing',
        'imp_opening', 'imp_current', 'imp_reversal', 'imp_closing',
        'carry_opening', 'carry_addition', 'carry_disposal', 'carry_closing',
    ];
    $totals = array_fill_keys($keys, 0.0);
    $totals['gain_loss'] = 0.0;
    foreach ($rows as $row) {
        foreach ($keys as $key) {
            $totals[$key] += (float) ($row[$key] ?? 0);
        }
        $totals['gain_loss'] += (float) ($row['gain_loss'] ?? 0);
    }

    return array_map(static fn (float $n): float => fa_round($n), $totals);
}

/**
 * Groups register rows for the chosen report type, keeping a stable order.
 * "namewise" is the flat register: one line per asset, grouped by nothing.
 */
function fa_register_group(array $rows, string $reportType, array $classLabels = []): array
{
    if ($reportType === 'namewise') {
        return ['' => $rows];
    }
    $grouped = [];
    foreach ($rows as $row) {
        $key = $reportType === 'categorywise'
            ? ((string) $row['category'] !== '' ? (string) $row['category'] : 'Uncategorised')
            : ($classLabels[(string) $row['class']] ?? (string) $row['class']);
        $grouped[$key][] = $row;
    }
    ksort($grouped);

    return $grouped;
}
