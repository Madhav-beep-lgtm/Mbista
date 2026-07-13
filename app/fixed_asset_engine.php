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
    ];
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
 * The straight-line monthly charge for an asset row for one period, capped so
 * carrying never drops below residual.
 */
function fa_asset_monthly_charge(array $asset): float
{
    $cost = (float) ($asset['cost'] ?? 0) + (float) ($asset['directly_attributable_cost'] ?? 0) + (float) ($asset['restoration_provision'] ?? 0);
    $residual = (float) ($asset['residual_value'] ?? 0);
    $life = (int) ($asset['useful_life_months'] ?? 0);
    $accumulated = (float) ($asset['accumulated_depreciation'] ?? 0);
    if ($life <= 0) {
        return 0.0;
    }
    $depreciable = max(0.0, $cost - $residual);
    $perMonth = fa_round($depreciable / $life);
    $remaining = max(0.0, $depreciable - $accumulated);
    return fa_round(min($perMonth, $remaining));
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
