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
 * The next number in a prefixed voucher series, for one company.
 *
 * Acquisition vouchers used to be numbered FA-ACQ-<asset code>, which is not a
 * number at all: it cannot be read in sequence, it says nothing about when the
 * voucher was posted, and it forced the asset code to be known before the
 * ledger would accept the entry. A draft that is waiting to be posted has no
 * business holding a number from that series either — the number belongs to
 * the act of posting, not to the act of typing.
 *
 * The series is per company because vouchers are unique on (company_id,
 * voucher_no), so two companies may each hold FA-ACQ-0001 without colliding.
 * The caller posts inside a transaction and retries on a duplicate key, which
 * is what actually settles a race between two people posting at once — this
 * only has to produce a sensible candidate.
 */
function fa_next_voucher_no(int $companyId, string $prefix, int $pad = 4): string
{
    $skip = strlen($prefix) + 1;
    $stmt = db()->prepare(
        'SELECT COALESCE(MAX(CAST(SUBSTRING(voucher_no, ' . (int) $skip . ') AS UNSIGNED)), 0)
         FROM vouchers WHERE company_id = :cid AND voucher_no LIKE :pattern'
    );
    $stmt->execute(['cid' => $companyId, 'pattern' => $prefix . '%']);

    return $prefix . str_pad((string) ((int) $stmt->fetchColumn() + 1), $pad, '0', STR_PAD_LEFT);
}

/**
 * The double entry an acquisition makes, with VAT and TDS kept out of the cost.
 *
 *   Dr  asset cost            cost
 *   Dr  VAT on purchase       vat            (recoverable, so never capitalised)
 *       Cr  funded from       cost + vat - tds
 *       Cr  TDS payable       tds            (withheld, so never paid to the supplier)
 *
 * VAT is a receivable from the tax office, not part of what the asset cost, so
 * capitalising it would overstate the asset and every depreciation charge taken
 * off it for the rest of its life. TDS is money withheld from the supplier and
 * owed to the tax office instead: the supplier's credit is reduced by exactly
 * what is withheld, so the two credits together still equal what was bought.
 *
 * Returns the lines and the debit total. Throws when a leg is missing the
 * ledger it needs, rather than posting a half-entry.
 */
function fa_acquisition_entries(
    float $cost,
    float $vat,
    float $tds,
    int $costLedgerId,
    int $creditLedgerId,
    int $vatLedgerId,
    int $tdsLedgerId
): array {
    $cost = fa_round(max(0.0, $cost));
    $vat = fa_round(max(0.0, $vat));
    $tds = fa_round(max(0.0, $tds));

    if ($costLedgerId <= 0 || $creditLedgerId <= 0) {
        throw new RuntimeException('An acquisition needs both the asset cost ledger and the "Funded from" ledger.');
    }
    if ($vat > 0 && $vatLedgerId <= 0) {
        throw new RuntimeException('Choose the VAT on purchase ledger, or clear the VAT amount.');
    }
    if ($tds > 0 && $tdsLedgerId <= 0) {
        throw new RuntimeException('Choose the TDS deducted ledger, or clear the TDS amount.');
    }
    // Withholding more than the whole bill would leave the supplier owing money
    // on a purchase, which is never what was meant.
    if ($tds > fa_round($cost + $vat)) {
        throw new RuntimeException('TDS cannot exceed the cost plus VAT.');
    }

    $lines = [['ledger_id' => $costLedgerId, 'entry_type' => 'debit', 'amount' => $cost]];
    if ($vat > 0) {
        $lines[] = ['ledger_id' => $vatLedgerId, 'entry_type' => 'debit', 'amount' => $vat, 'memo' => 'VAT on purchase — recoverable, not capitalised'];
    }
    $lines[] = ['ledger_id' => $creditLedgerId, 'entry_type' => 'credit', 'amount' => fa_round($cost + $vat - $tds)];
    if ($tds > 0) {
        $lines[] = ['ledger_id' => $tdsLedgerId, 'entry_type' => 'credit', 'amount' => $tds, 'memo' => 'TDS withheld on purchase'];
    }

    return ['lines' => $lines, 'total' => fa_round($cost + $vat)];
}

/**
 * TDS from a rate: the rate is applied to the cost alone, never to the VAT.
 *
 * Tax is not withheld on tax — VAT is collected on behalf of the tax office and
 * passed straight through, so taking a percentage of it would withhold against
 * money the supplier never earned.
 */
function fa_tds_from_rate(float $cost, float $ratePct): float
{
    if ($ratePct <= 0 || $cost <= 0) {
        return 0.0;
    }

    return fa_round($cost * $ratePct / 100);
}
