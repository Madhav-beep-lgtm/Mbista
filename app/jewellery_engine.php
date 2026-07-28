<?php
declare(strict_types=1);

/**
 * Jewellery Accounting engine — Phase 1 foundation.
 *
 * A jewellery house keeps TWO books at once. The money book is ordinary
 * double-entry and posts through create_voucher_with_entries() like every
 * other module. The metal book answers a question no money ledger can:
 * "how many tola of 22K gold is with karigar Ram right now?" Later phases add
 * that weight ledger; this file establishes the masters and the arithmetic
 * both books depend on.
 *
 * THE FINE-WEIGHT PIVOT. Ornaments arrive in mixed purities, so gross weights
 * are not comparable — 1 tola of 22K is not 1 tola of 24K. Everything is
 * therefore reduced to FINE weight (pure metal content):
 *
 *     fine = gross x fineness / 1000
 *
 * and every rate is reduced to a rate per unit of PURE metal:
 *
 *     fine_rate = quoted_rate x 1000 / quoted_fineness
 *
 * Multiply the two and a 22K item values correctly off a 24K quote without a
 * single per-purity rate table. Fineness is parts per 1000 (999.9 = 24K,
 * 916 = 22K, 750 = 18K).
 *
 * THE GRAM PIVOT. Units (gram, tola, aana, laal, carat) each declare how many
 * grams they are worth, so any unit converts to any other through grams. No
 * hard-coded pair table, and a company can add its own unit.
 *
 * Tenant model: all data is scoped to the client's BOOKS company id, and the
 * module unlocks only when the books company belongs to a client whose
 * client_profiles.jewellery_accounting_enabled = 1 (Super Admin controlled).
 * The firm's own workspaces are never client books, so the module can never
 * appear there.
 */

// Weight is carried to 4 dp internally (a laal is ~0.0156 tola, so 4 dp keeps
// a single laal representable); money to 2; rates to 4.
const JW_WEIGHT_SCALE = 4;
const JW_MONEY_SCALE = 2;
const JW_RATE_SCALE = 4;

// ---------------------------------------------------------------------------
// Activation and gating
// ---------------------------------------------------------------------------

/** The client profile served by this books company, or null. */
function jewellery_client_profile_for_company(int $companyId): ?array
{
    if ($companyId <= 0 || !table_exists('client_profiles')) {
        return null;
    }
    $stmt = db()->prepare('SELECT cp.* FROM client_profiles cp
        INNER JOIN companies c ON c.id = cp.books_company_id
        WHERE cp.books_company_id = :cid AND c.is_client_company = 1
        LIMIT 1');
    $stmt->execute(['cid' => $companyId]);

    return $stmt->fetch() ?: null;
}

/**
 * Is Jewellery Accounting active for this books company? False for every
 * non-client company (including the firm's own workspaces) and for clients
 * whose flag is off — routes, menus and queries all key off this.
 */
function jewellery_enabled_for_company(int $companyId): bool
{
    if (!column_exists('client_profiles', 'jewellery_accounting_enabled')) {
        return false;
    }
    $profile = jewellery_client_profile_for_company($companyId);

    return $profile !== null
        && (int) ($profile['is_active'] ?? 0) === 1
        && (int) ($profile['jewellery_accounting_enabled'] ?? 0) === 1;
}

/**
 * Page gate: authenticated books access + company context + the client flag +
 * the jewellery.view permission. Direct URL access with the feature off is
 * denied server-side (never rely on the hidden menu alone).
 */
function require_jewellery(): void
{
    require_staff_admin_or_client_books();
    require_company_context();
    if (!jewellery_enabled_for_company(current_company_id())) {
        deny_access('Jewellery Accounting is not enabled for this company.', current_company_id());
    }
    require_permission('jewellery', 'view');
}

// ---------------------------------------------------------------------------
// Rounding
// ---------------------------------------------------------------------------

/**
 * Coerce a submitted value to one of an allowed set, falling back to $default.
 *
 * Written as a helper because the obvious inline form has a trap:
 *     in_array($input['x'] ?? 'cash', $allowed) ? $input['x'] : 'cash'
 * the ?? guards the TEST but not the branch, so a missing key passes the test
 * and then warns on the re-read. Every enum coming off a form goes through
 * here instead.
 */
function jw_enum(mixed $value, array $allowed, string $default): string
{
    $value = is_scalar($value) ? (string) $value : '';

    return in_array($value, $allowed, true) ? $value : $default;
}

function jw_round_weight(float $n): float { return round($n, JW_WEIGHT_SCALE); }
function jw_round_money(float $n): float { return round($n, JW_MONEY_SCALE); }
function jw_round_rate(float $n): float { return round($n, JW_RATE_SCALE); }

// ---------------------------------------------------------------------------
// Settings and master seeding
// ---------------------------------------------------------------------------

/**
 * Tenant settings row, auto-created with safe defaults. The first call for a
 * company also seeds the unit/metal/purity masters, so a freshly enabled
 * client can record a rate immediately instead of facing empty dropdowns.
 */
function jewellery_settings(int $companyId): array
{
    $stmt = db()->prepare('SELECT * FROM jewellery_settings WHERE company_id = :cid LIMIT 1');
    $stmt->execute(['cid' => $companyId]);
    $row = $stmt->fetch();
    if (!$row) {
        db()->prepare('INSERT INTO jewellery_settings (company_id) VALUES (:cid)')->execute(['cid' => $companyId]);
        $stmt->execute(['cid' => $companyId]);
        $row = $stmt->fetch();
    }

    if ((int) ($row['masters_seeded'] ?? 0) !== 1) {
        jewellery_seed_masters($companyId);
        $stmt->execute(['cid' => $companyId]);
        $row = $stmt->fetch();
        // Taxes are seeded after the masters, because the VAT row copies the
        // rate the settings were seeded with. It reads settings itself, so it
        // must not run until this row is committed.
        jewellery_seed_taxes($companyId);
    }

    return (array) $row;
}

/**
 * The unit/metal/purity masters every jewellery company starts from. Seeding
 * is idempotent — an existing code is left exactly as the company edited it,
 * so re-running never overwrites a tuned conversion factor or fineness.
 */
function jewellery_seed_masters(int $companyId): void
{
    if ($companyId <= 0) {
        return;
    }

    // Nepali jeweller weight system, pivoted on grams.
    //   1 tola = 11.6638 g,  1 tola = 16 aana,  1 aana = 4 laal
    //   1 carat = 0.2 g (stones)
    $units = [
        ['GM',   'Gram',  1.000000,   0, 10],
        ['TOLA', 'Tola',  11.663800,  1, 20],
        ['AANA', 'Aana',  0.728988,   0, 30],
        ['LAAL', 'Laal',  0.182247,   0, 40],
        ['CT',   'Carat', 0.200000,   0, 50],
    ];
    $unitIds = [];
    foreach ($units as [$code, $name, $grams, $isBase, $sort]) {
        $find = db()->prepare('SELECT id FROM jewellery_units WHERE company_id = :cid AND code = :code LIMIT 1');
        $find->execute(['cid' => $companyId, 'code' => $code]);
        $existing = (int) ($find->fetchColumn() ?: 0);
        if ($existing > 0) {
            $unitIds[$code] = $existing;
            continue;
        }
        db()->prepare('INSERT INTO jewellery_units (company_id, code, name, grams, is_base, sort_order) VALUES (:cid, :code, :name, :grams, :base, :sort)')
            ->execute(['cid' => $companyId, 'code' => $code, 'name' => $name, 'grams' => $grams, 'base' => $isBase, 'sort' => $sort]);
        $unitIds[$code] = (int) db()->lastInsertId();
    }

    // metal code => [name, kind, track_purity, default unit, sort, purities]
    // Purity rows are [code, name, fineness per 1000, is_default, sort].
    $metals = [
        'GOLD' => ['Gold', 'metal', 1, 'TOLA', 10, [
            ['24K', '24 Carat (999.9)', 999.9000, 1, 10],
            ['22K', '22 Carat (916)',   916.0000, 0, 20],
            ['18K', '18 Carat (750)',   750.0000, 0, 30],
            ['14K', '14 Carat (585)',   585.0000, 0, 40],
        ]],
        'SILVER' => ['Silver', 'metal', 1, 'TOLA', 20, [
            ['FINE', 'Fine Silver (999)',     999.0000, 1, 10],
            ['STER', 'Sterling Silver (925)', 925.0000, 0, 20],
        ]],
        'PLATINUM' => ['Platinum', 'metal', 1, 'TOLA', 30, [
            ['PT950', 'Platinum 950', 950.0000, 1, 10],
            ['PT900', 'Platinum 900', 900.0000, 0, 20],
        ]],
        // Stones carry a single 1000-fineness row so downstream tables can
        // always join on a NOT NULL purity_id and unique keys stay sound.
        'DIAMOND' => ['Diamond', 'stone', 0, 'CT', 40, [
            ['STD', 'Standard', 1000.0000, 1, 10],
        ]],
        'GEM' => ['Gemstone', 'stone', 0, 'CT', 50, [
            ['STD', 'Standard', 1000.0000, 1, 10],
        ]],
    ];

    foreach ($metals as $code => [$name, $kind, $trackPurity, $unitCode, $sort, $purities]) {
        $find = db()->prepare('SELECT id FROM jewellery_metals WHERE company_id = :cid AND code = :code LIMIT 1');
        $find->execute(['cid' => $companyId, 'code' => $code]);
        $metalId = (int) ($find->fetchColumn() ?: 0);
        if ($metalId === 0) {
            db()->prepare('INSERT INTO jewellery_metals (company_id, code, name, metal_kind, track_purity, default_unit_id, sort_order) VALUES (:cid, :code, :name, :kind, :tp, :unit, :sort)')
                ->execute([
                    'cid' => $companyId, 'code' => $code, 'name' => $name, 'kind' => $kind,
                    'tp' => $trackPurity, 'unit' => $unitIds[$unitCode] ?? null, 'sort' => $sort,
                ]);
            $metalId = (int) db()->lastInsertId();
        }

        foreach ($purities as [$pCode, $pName, $fineness, $isDefault, $pSort]) {
            $pFind = db()->prepare('SELECT id FROM jewellery_purities WHERE company_id = :cid AND metal_id = :mid AND code = :code LIMIT 1');
            $pFind->execute(['cid' => $companyId, 'mid' => $metalId, 'code' => $pCode]);
            if ((int) ($pFind->fetchColumn() ?: 0) > 0) {
                continue;
            }
            db()->prepare('INSERT INTO jewellery_purities (company_id, metal_id, code, name, fineness, is_default, sort_order) VALUES (:cid, :mid, :code, :name, :fine, :def, :sort)')
                ->execute([
                    'cid' => $companyId, 'mid' => $metalId, 'code' => $pCode, 'name' => $pName,
                    'fine' => $fineness, 'def' => $isDefault, 'sort' => $pSort,
                ]);
        }
    }

    // Point the settings row at the seeded defaults, then mark it done.
    $goldId = db()->prepare('SELECT id FROM jewellery_metals WHERE company_id = :cid AND code = :code LIMIT 1');
    $goldId->execute(['cid' => $companyId, 'code' => 'GOLD']);
    db()->prepare('UPDATE jewellery_settings SET base_unit_id = COALESCE(base_unit_id, :unit), default_metal_id = COALESCE(default_metal_id, :metal), masters_seeded = 1 WHERE company_id = :cid')
        ->execute([
            'unit' => $unitIds['TOLA'] ?? null,
            'metal' => (int) ($goldId->fetchColumn() ?: 0) ?: null,
            'cid' => $companyId,
        ]);
}

/** Persist edited settings. Only known keys are written. */
function jewellery_save_settings(int $companyId, array $input, int $userId = 0): void
{
    $allowed = [
        'base_unit_id', 'default_metal_id', 'weight_precision', 'rate_precision', 'amount_precision',
        'vat_rate', 'default_vat_base', 'making_charge_basis', 'default_wastage_pct', 'rate_source',
        'allow_negative_stock', 'auto_post', 'sale_no_prefix', 'purchase_no_prefix', 'order_no_prefix',
        'issue_no_prefix', 'refinery_no_prefix',
    ];
    $sets = [];
    $params = ['cid' => $companyId, 'uid' => $userId ?: null];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $sets[] = '`' . $key . '` = :' . $key;
        $params[$key] = $input[$key];
    }
    if ($sets === []) {
        return;
    }
    db()->prepare('UPDATE jewellery_settings SET ' . implode(', ', $sets) . ', updated_by = :uid WHERE company_id = :cid')
        ->execute($params);
}

// ---------------------------------------------------------------------------
// Masters: units, metals, purities
// ---------------------------------------------------------------------------

function jewellery_units_list(int $companyId, bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM jewellery_units WHERE company_id = :cid'
        . ($activeOnly ? ' AND active = 1' : '')
        . ' ORDER BY sort_order ASC, name ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute(['cid' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jewellery_unit(int $companyId, int $unitId): ?array
{
    if ($unitId <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM jewellery_units WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $unitId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The company's reporting unit — settings choice, else the flagged base, else gram. */
function jewellery_base_unit(int $companyId): ?array
{
    $settings = jewellery_settings($companyId);
    $unit = jewellery_unit($companyId, (int) ($settings['base_unit_id'] ?? 0));
    if ($unit) {
        return $unit;
    }
    $stmt = db()->prepare('SELECT * FROM jewellery_units WHERE company_id = :cid AND active = 1 ORDER BY is_base DESC, sort_order ASC LIMIT 1');
    $stmt->execute(['cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function jewellery_metals_list(int $companyId, bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM jewellery_metals WHERE company_id = :cid'
        . ($activeOnly ? ' AND active = 1' : '')
        . ' ORDER BY sort_order ASC, name ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute(['cid' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jewellery_metal(int $companyId, int $metalId): ?array
{
    if ($metalId <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM jewellery_metals WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $metalId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function jewellery_purities_list(int $companyId, int $metalId = 0, bool $activeOnly = true): array
{
    $sql = 'SELECT p.*, m.code AS metal_code, m.name AS metal_name
            FROM jewellery_purities p
            INNER JOIN jewellery_metals m ON m.id = p.metal_id
            WHERE p.company_id = :cid'
        . ($metalId > 0 ? ' AND p.metal_id = :mid' : '')
        . ($activeOnly ? ' AND p.active = 1' : '')
        . ' ORDER BY m.sort_order ASC, p.sort_order ASC, p.name ASC';
    $stmt = db()->prepare($sql);
    $params = ['cid' => $companyId];
    if ($metalId > 0) {
        $params['mid'] = $metalId;
    }
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jewellery_purity(int $companyId, int $purityId): ?array
{
    if ($purityId <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT p.*, m.code AS metal_code, m.name AS metal_name, m.metal_kind
        FROM jewellery_purities p
        INNER JOIN jewellery_metals m ON m.id = p.metal_id
        WHERE p.id = :id AND p.company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $purityId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The purity a metal defaults to when the user does not pick one. */
function jewellery_default_purity(int $companyId, int $metalId): ?array
{
    $stmt = db()->prepare('SELECT * FROM jewellery_purities WHERE company_id = :cid AND metal_id = :mid AND active = 1 ORDER BY is_default DESC, sort_order ASC LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'mid' => $metalId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ---------------------------------------------------------------------------
// Weight and purity arithmetic
//
// These are pure functions — no database, no globals — so the regression test
// can prove the maths independently of any tenant data.
// ---------------------------------------------------------------------------

/** Weight expressed in grams. */
function jw_to_grams(float $qty, array $unit): float
{
    return $qty * (float) ($unit['grams'] ?? 1.0);
}

/**
 * Convert a weight between two units through the gram pivot. A zero or
 * missing target factor would divide by zero, so it falls back to 1 g.
 */
function jw_convert_weight(float $qty, array $fromUnit, array $toUnit): float
{
    $toGrams = (float) ($toUnit['grams'] ?? 1.0);
    if ($toGrams <= 0.0) {
        $toGrams = 1.0;
    }

    return jw_round_weight(jw_to_grams($qty, $fromUnit) / $toGrams);
}

/**
 * Every weight unit of a company, keyed by id — one query instead of one per row.
 *
 * Weights are stored in whatever unit the document was written in, so a kaligad
 * issued in tola and received back in gram leaves rows that MUST NOT be added
 * up as they stand. Any total across documents converts through this map first.
 * Inactive units are included: a unit retired today still has history behind it.
 */
function jw_unit_map(int $companyId): array
{
    static $cache = [];
    if (!isset($cache[$companyId])) {
        $map = [];
        foreach (jewellery_units_list($companyId, false) as $unit) {
            $map[(int) $unit['id']] = $unit;
        }
        $cache[$companyId] = $map;
    }

    return $cache[$companyId];
}

/**
 * Restate a weight from its stored unit into the reporting unit.
 *
 * An unknown unit is passed through unchanged rather than zeroed: losing the
 * weight entirely is a worse answer than reporting it in the wrong unit, and
 * the totals stay reconcilable against the raw rows either way.
 */
function jw_weight_in_base(float $qty, int $fromUnitId, array $unitMap, ?array $baseUnit): float
{
    if ($qty === 0.0 || !$baseUnit) {
        return jw_round_weight($qty);
    }
    $from = $unitMap[$fromUnitId] ?? null;
    if (!$from || $fromUnitId === (int) $baseUnit['id']) {
        return jw_round_weight($qty);
    }

    return jw_round_weight(jw_convert_weight($qty, $from, $baseUnit));
}

/**
 * The fine rate used to VALUE a metal holding, as opposed to price a document.
 *
 * Ladder, most explicit first:
 *   entered  the rate typed in — you and the other side agreed it, so it wins
 *   quote    the rate board on the valuation date, restated per fine unit
 *   cost     the metal's own carrying value, so a statement still balances
 *            when no rate has ever been quoted
 *
 * Returned per unit of PURE metal in the reporting unit, which is the only
 * rate that can value a mixed-purity holding in one multiplication.
 */
function jw_statement_fine_rate(int $companyId, array $options, string $asOf, float $costFine, float $costValue): array
{
    $entered = jw_round_rate((float) ($options['fine_rate'] ?? 0));
    if ($entered > 0) {
        return ['fine_rate' => $entered, 'source' => 'entered', 'label' => 'Rate entered for this statement', 'rate_row' => null];
    }

    $settings = jewellery_settings($companyId);
    $baseUnit = jewellery_base_unit($companyId);
    $metalId = (int) ($options['metal_id'] ?? 0) ?: (int) ($settings['default_metal_id'] ?? 0);
    if ($metalId > 0 && $baseUnit) {
        $purityId = (int) ($options['purity_id'] ?? 0);
        if ($purityId <= 0) {
            $defaultPurity = jewellery_default_purity($companyId, $metalId);
            $purityId = (int) ($defaultPurity['id'] ?? 0);
        }
        if ($purityId > 0) {
            // Value one whole base unit of PURE metal: the amount that comes
            // back IS the fine rate, and it inherits the quote's own unit and
            // purity conversion instead of repeating that arithmetic here.
            $valued = jewellery_metal_value(
                $companyId,
                $metalId,
                $purityId,
                1.0,
                (int) $baseUnit['id'],
                $asOf,
                (string) ($options['rate_type'] ?? 'market'),
                $settings
            );
            if (($valued['ok'] ?? false) && (float) $valued['fine_rate'] > 0) {
                $rateRow = $valued['rate_row'] ?? null;

                return [
                    'fine_rate' => jw_round_rate((float) $valued['fine_rate']),
                    'source' => 'quote',
                    'label' => 'Rate board quote of ' . (string) ($rateRow['rate_date'] ?? $asOf),
                    'rate_row' => $rateRow,
                ];
            }
        }
    }

    // Last resort: the carrying value itself. Valued then equals cost, the gap
    // is zero, and no revaluation is invented that cannot be justified.
    if (abs($costFine) > 0.00005) {
        return [
            'fine_rate' => jw_round_rate($costValue / $costFine),
            'source' => 'cost',
            'label' => 'Carrying value — no rate quoted, enter one above',
            'rate_row' => null,
        ];
    }

    return ['fine_rate' => 0.0, 'source' => 'none', 'label' => 'No rate available', 'rate_row' => null];
}

/**
 * Pure-metal content of a gross weight. Fineness is parts per 1000, so 22K
 * (916) of 10 tola gross is 9.16 tola fine.
 */
function jw_fine_weight(float $grossQty, float $fineness): float
{
    return jw_round_weight($grossQty * $fineness / 1000.0);
}

/** The inverse: gross weight that yields a given fine content at a purity. */
function jw_gross_from_fine(float $fineQty, float $fineness): float
{
    if ($fineness <= 0.0) {
        return 0.0;
    }

    return jw_round_weight($fineQty * 1000.0 / $fineness);
}

/**
 * A quoted rate restated as money per unit of PURE metal. This is what makes
 * one daily quote value every purity: a 24K (999.9) quote of 150,000/tola is
 * 150,015.00 per tola of pure gold, and a 22K item is then valued off its own
 * fine weight.
 */
function jw_fine_rate(float $quotedRate, float $quotedFineness): float
{
    if ($quotedFineness <= 0.0) {
        return 0.0;
    }

    return $quotedRate * 1000.0 / $quotedFineness;
}

// ---------------------------------------------------------------------------
// Daily rates
// ---------------------------------------------------------------------------

/** The rate types a company may quote on a date. */
function jewellery_rate_types(): array
{
    return [
        'market' => 'Market',
        'sale' => 'Sale (to customer)',
        'purchase' => 'Purchase (from customer)',
    ];
}

/**
 * Create or update the quote for a date/metal/purity/type. One row per
 * combination (enforced by the unique key), so re-entering a rate corrects
 * the day rather than stacking duplicates.
 */
function jewellery_save_rate(int $companyId, array $input, int $userId = 0): int
{
    $metalId = (int) ($input['metal_id'] ?? 0);
    $purityId = (int) ($input['purity_id'] ?? 0);
    $unitId = (int) ($input['unit_id'] ?? 0);
    $rateDate = (string) ($input['rate_date'] ?? date('Y-m-d'));
    $rateType = (string) ($input['rate_type'] ?? 'market');
    if (!array_key_exists($rateType, jewellery_rate_types())) {
        $rateType = 'market';
    }

    // Tenant integrity: metal, purity and unit must all belong to this company,
    // and the purity must belong to the metal — a tampered id must not attach
    // another tenant's master to this quote.
    $purity = jewellery_purity($companyId, $purityId);
    if (!$purity || (int) $purity['metal_id'] !== $metalId) {
        throw new RuntimeException('Select a purity that belongs to the chosen metal.');
    }
    if (!jewellery_unit($companyId, $unitId)) {
        throw new RuntimeException('Select a valid weight unit for the rate.');
    }
    $rate = (float) ($input['rate'] ?? 0);
    if ($rate < 0) {
        throw new RuntimeException('A rate cannot be negative.');
    }

    $stmt = db()->prepare('INSERT INTO jewellery_daily_rates
            (company_id, rate_date, metal_id, purity_id, unit_id, rate_type, rate, note, created_by)
        VALUES (:cid, :d, :mid, :pid, :uid, :rt, :rate, :note, :by)
        ON DUPLICATE KEY UPDATE rate = VALUES(rate), unit_id = VALUES(unit_id), note = VALUES(note)');
    $stmt->execute([
        'cid' => $companyId, 'd' => $rateDate, 'mid' => $metalId, 'pid' => $purityId,
        'uid' => $unitId, 'rt' => $rateType, 'rate' => $rate,
        'note' => ($input['note'] ?? '') !== '' ? (string) $input['note'] : null,
        'by' => $userId ?: null,
    ]);

    $find = db()->prepare('SELECT id FROM jewellery_daily_rates WHERE company_id = :cid AND rate_date = :d AND metal_id = :mid AND purity_id = :pid AND rate_type = :rt LIMIT 1');
    $find->execute(['cid' => $companyId, 'd' => $rateDate, 'mid' => $metalId, 'pid' => $purityId, 'rt' => $rateType]);

    return (int) ($find->fetchColumn() ?: 0);
}

/**
 * The applicable quote for a date.
 *
 * With rate_source = 'last_known' (the default) a missing day falls back to
 * the most recent earlier quote — a shop that did not post Saturday's rate
 * still bills correctly on Saturday. With 'manual' only an exact-date quote
 * counts, so a missing rate is a visible error instead of a stale price.
 * Never looks FORWARD: a future quote must not price a past transaction.
 */
function jewellery_rate_on(int $companyId, int $metalId, int $purityId, string $date, string $rateType = 'market', ?array $settings = null): ?array
{
    $settings = $settings ?? jewellery_settings($companyId);
    $exact = db()->prepare('SELECT * FROM jewellery_daily_rates WHERE company_id = :cid AND metal_id = :mid AND purity_id = :pid AND rate_type = :rt AND rate_date = :d LIMIT 1');
    $exact->execute(['cid' => $companyId, 'mid' => $metalId, 'pid' => $purityId, 'rt' => $rateType, 'd' => $date]);
    $row = $exact->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $row['rate_is_carried'] = false;
        return $row;
    }

    if ((string) ($settings['rate_source'] ?? 'last_known') !== 'last_known') {
        return null;
    }

    $prior = db()->prepare('SELECT * FROM jewellery_daily_rates WHERE company_id = :cid AND metal_id = :mid AND purity_id = :pid AND rate_type = :rt AND rate_date < :d ORDER BY rate_date DESC LIMIT 1');
    $prior->execute(['cid' => $companyId, 'mid' => $metalId, 'pid' => $purityId, 'rt' => $rateType, 'd' => $date]);
    $row = $prior->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['rate_is_carried'] = true;

    return $row;
}

/**
 * The rate to price a transaction with, walking a fallback ladder so a shop
 * that only maintains one "market" line still gets sales and purchases
 * priced: the requested type first, then market.
 */
function jewellery_effective_rate(int $companyId, int $metalId, int $purityId, string $date, string $rateType = 'market', ?array $settings = null): ?array
{
    $settings = $settings ?? jewellery_settings($companyId);
    foreach (array_unique([$rateType, 'market']) as $type) {
        $row = jewellery_rate_on($companyId, $metalId, $purityId, $date, $type, $settings);
        if ($row) {
            $row['rate_type_used'] = $type;
            return $row;
        }
    }

    return null;
}

/** Every quote on a date, newest metals first, for the rate board. */
function jewellery_rates_for_date(int $companyId, string $date): array
{
    $stmt = db()->prepare('SELECT r.*, m.code AS metal_code, m.name AS metal_name, m.metal_kind,
                p.code AS purity_code, p.name AS purity_name, p.fineness,
                u.code AS unit_code, u.name AS unit_name, u.grams
            FROM jewellery_daily_rates r
            INNER JOIN jewellery_metals m ON m.id = r.metal_id
            INNER JOIN jewellery_purities p ON p.id = r.purity_id
            INNER JOIN jewellery_units u ON u.id = r.unit_id
            WHERE r.company_id = :cid AND r.rate_date = :d
            ORDER BY m.sort_order ASC, p.sort_order ASC, r.rate_type ASC');
    $stmt->execute(['cid' => $companyId, 'd' => $date]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Recent rate history for one metal/purity/type, for the trend panel. */
function jewellery_rate_history(int $companyId, int $metalId, int $purityId, string $rateType = 'market', int $limit = 30): array
{
    $limit = max(1, min(365, $limit));
    $stmt = db()->prepare('SELECT r.*, u.code AS unit_code FROM jewellery_daily_rates r
        INNER JOIN jewellery_units u ON u.id = r.unit_id
        WHERE r.company_id = :cid AND r.metal_id = :mid AND r.purity_id = :pid AND r.rate_type = :rt
        ORDER BY r.rate_date DESC LIMIT ' . $limit);
    $stmt->execute(['cid' => $companyId, 'mid' => $metalId, 'pid' => $purityId, 'rt' => $rateType]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jewellery_delete_rate(int $companyId, int $rateId): bool
{
    $stmt = db()->prepare('DELETE FROM jewellery_daily_rates WHERE id = :id AND company_id = :cid');
    $stmt->execute(['id' => $rateId, 'cid' => $companyId]);

    return $stmt->rowCount() > 0;
}

/**
 * Value a gross weight of metal on a date.
 *
 * Returns ['ok', 'error', 'fine_qty', 'fine_rate', 'amount', 'rate_row', ...]
 * with every intermediate exposed so the UI can show its working and a report
 * can reconcile it. `ok = false` (with amount 0) when no rate is available —
 * the caller surfaces the gap rather than pricing at zero silently.
 *
 * @param float $grossQty gross weight, expressed in $qtyUnit
 * @param int   $purityId the purity of the ITEM being valued (not the quote)
 */
function jewellery_metal_value(
    int $companyId,
    int $metalId,
    int $purityId,
    float $grossQty,
    int $qtyUnitId,
    string $date,
    string $rateType = 'market',
    ?array $settings = null
): array {
    $settings = $settings ?? jewellery_settings($companyId);
    $blank = [
        'ok' => false, 'error' => '', 'fine_qty' => 0.0, 'fine_rate' => 0.0,
        'amount' => 0.0, 'rate_row' => null, 'qty_in_rate_unit' => 0.0, 'rate_is_carried' => false,
    ];

    $itemPurity = jewellery_purity($companyId, $purityId);
    if (!$itemPurity) {
        $blank['error'] = 'Unknown purity.';
        return $blank;
    }
    $qtyUnit = jewellery_unit($companyId, $qtyUnitId);
    if (!$qtyUnit) {
        $blank['error'] = 'Unknown weight unit.';
        return $blank;
    }

    // The quote is looked up against the ITEM's purity first; if the shop only
    // maintains a 24K line, fall back to the metal's default purity quote and
    // let the fine-weight maths restate it.
    $rateRow = jewellery_effective_rate($companyId, $metalId, $purityId, $date, $rateType, $settings);
    $quotePurity = $itemPurity;
    if (!$rateRow) {
        $fallbackPurity = jewellery_default_purity($companyId, $metalId);
        if ($fallbackPurity && (int) $fallbackPurity['id'] !== $purityId) {
            $rateRow = jewellery_effective_rate($companyId, $metalId, (int) $fallbackPurity['id'], $date, $rateType, $settings);
            $quotePurity = $fallbackPurity;
        }
    }
    if (!$rateRow) {
        $blank['error'] = 'No ' . $rateType . ' rate is available on or before ' . $date . ' for this metal.';
        return $blank;
    }

    $rateUnit = jewellery_unit($companyId, (int) $rateRow['unit_id']);
    if (!$rateUnit) {
        $blank['error'] = 'The stored rate references a weight unit that no longer exists.';
        return $blank;
    }

    // 1. Restate the quantity in the unit the rate is quoted in.
    $qtyInRateUnit = jw_convert_weight($grossQty, $qtyUnit, $rateUnit);
    // 2. Pure-metal content, at the ITEM's fineness.
    $fineQty = jw_fine_weight($qtyInRateUnit, (float) $itemPurity['fineness']);
    // 3. The quote restated per unit of pure metal, at the QUOTE's fineness.
    $fineRate = jw_fine_rate((float) $rateRow['rate'], (float) $quotePurity['fineness']);

    return [
        'ok' => true,
        'error' => '',
        'fine_qty' => $fineQty,
        'fine_rate' => jw_round_rate($fineRate),
        'amount' => jw_round_money($fineQty * $fineRate),
        'rate_row' => $rateRow,
        'rate_unit' => $rateUnit,
        'quote_purity' => $quotePurity,
        'item_purity' => $itemPurity,
        'qty_in_rate_unit' => $qtyInRateUnit,
        'rate_is_carried' => (bool) ($rateRow['rate_is_carried'] ?? false),
    ];
}

// ---------------------------------------------------------------------------
// Ledger mappings
//
// Automated posting resolves every ledger through this ladder — item, then
// category, then the company default. Nothing is ever guessed: an unresolved
// purpose is reported as a gap so the books cannot silently go somewhere wrong.
// ---------------------------------------------------------------------------

/**
 * purpose => [label, group, canonical purpose, expected account type].
 *
 * THE MAPPING TABLE IS SHARED. Jewellery does not keep its own ledger-mapping
 * store: everything is written to and read from inventory_ledger_mappings, the
 * same table the core Inventory module uses. Roughly a third of these purposes
 * ARE core purposes under a jewellery name — "VAT payable" is the core's
 * tax_output, "Finished ornament stock" is its finished_goods — and mapping
 * one ledger twice on two screens is exactly the duplication worth removing.
 *
 * The third element is that canonical name. Where it differs from the key, the
 * jewellery label is a friendlier alias onto a shared row; where it matches,
 * the purpose is jewellery-specific and simply lives alongside the core ones.
 */
function jewellery_mapping_purposes(): array
{
    return [
        'stock_metal'       => ['Metal stock (raw / bullion)', 'Stock', 'inventory_asset', 'asset'],
        'stock_finished'    => ['Finished ornament stock', 'Stock', 'finished_goods', 'asset'],
        'stock_stone'       => ['Stone stock', 'Stock', 'stock_stone', 'asset'],
        'stock_karigar'     => ['Metal with karigar', 'Stock', 'stock_karigar', 'asset'],
        'stock_refinery'    => ['Metal with refinery', 'Stock', 'stock_refinery', 'asset'],
        'sales_metal'       => ['Sales — metal value', 'Sales', 'sales_revenue', 'revenue'],
        'sales_making'      => ['Sales — making charge', 'Sales', 'sales_making', 'revenue'],
        'sales_stone'       => ['Sales — stone value', 'Sales', 'sales_stone', 'revenue'],
        'sales_discount'    => ['Sales discount given', 'Sales', 'sales_discount', 'expense'],
        'other_charges'     => ['Other charges recovered', 'Sales', 'other_charges', 'revenue'],
        'sales_return'      => ['Sales return', 'Sales', 'sales_return', 'expense'],
        'purchase_clearing' => ['Purchase clearing', 'Purchases', 'purchase_clearing', 'liability'],
        'purchase_return'   => ['Purchase return', 'Purchases', 'purchase_return', 'revenue'],
        'cogs'              => ['Cost of goods sold', 'Purchases', 'cogs', 'expense'],
        'vat_output'        => ['VAT payable (output)', 'Tax', 'tax_output', 'liability'],
        'vat_input'         => ['VAT receivable (input)', 'Tax', 'tax_input', 'asset'],
        // Where every NON-VAT tax posts unless its own row names a different
        // purpose — so levying one more tax needs no new mapping to begin with.
        'spt_output'        => ['Other tax payable (Skills Promotion Tax)', 'Tax', 'spt_output', 'liability'],
        'spt_input'         => ['Other tax receivable', 'Tax', 'spt_input', 'asset'],
        // Money (or gold) taken before the piece is delivered is owed back
        // until it is handed over, so it is a liability — never a reduction of
        // a receivable that does not exist yet. This mapping supplies the GROUP
        // each customer's own advance ledger is opened in.
        'customer_advance'  => ['Customer advances (orders)', 'Sales', 'customer_advance', 'liability'],
        'karigar_payable'   => ['Karigar wages payable', 'Karigar', 'karigar_payable', 'liability'],
        'making_expense'    => ['Making / labour expense', 'Karigar', 'making_expense', 'expense'],
        'wastage_loss'      => ['Wastage loss', 'Karigar', 'wastage_loss', 'expense'],
        'refinery_loss'     => ['Refining loss', 'Refinery', 'refinery_loss', 'expense'],
        'refinery_charges'  => ['Refinery charges', 'Refinery', 'refinery_charges', 'expense'],
        'metal_exchange'    => ['Metal exchange clearing', 'Adjustments', 'metal_exchange', 'asset'],
        'stock_gain'        => ['Stock gain', 'Adjustments', 'inventory_gain', 'revenue'],
        'stock_loss'        => ['Stock loss', 'Adjustments', 'inventory_loss', 'expense'],
        'rounding'          => ['Rounding difference', 'Adjustments', 'rounding', 'expense'],
        'opening_equity'    => ['Opening balance equity', 'Adjustments', 'opening_equity', 'equity'],
        // A customer settling one bill with part cash, part card and the rest
        // by QR is ordinary. The bill has always PRINTED that breakdown, but
        // every rupee went to one settlement ledger — so the cash book said the
        // shop took the whole lot in cash, and it could never be counted
        // against the till at closing time.
        //
        // Map these and each part lands where the money actually went. They are
        // optional: a shop that leaves them unset carries on with a single
        // settlement ledger exactly as before.
        'tender_cash'       => ['Money taken — cash', 'Settlement', 'tender_cash', 'asset'],
        'tender_card'       => ['Money taken — card', 'Settlement', 'tender_card', 'asset'],
        'tender_cheque'     => ['Money taken — cheque', 'Settlement', 'tender_cheque', 'asset'],
        'tender_qr'         => ['Money taken — QR / wallet', 'Settlement', 'tender_qr', 'asset'],
    ];
}

/**
 * The name a jewellery purpose is actually STORED under in the shared mapping
 * table. Unknown purposes pass through so a caller's typo surfaces as an
 * unmapped gap rather than silently resolving to something else.
 */
function jw_canonical_purpose(string $purpose): string
{
    return jewellery_mapping_purposes()[$purpose][2] ?? $purpose;
}

/**
 * The jewellery-only purposes, for the core Inventory mapping screen to show
 * alongside its own — so both screens list the same set and edit the same rows.
 *
 * @return array<string, array{label: string, expect: string}>
 */
function jewellery_extra_inventory_purposes(): array
{
    $extra = [];
    foreach (jewellery_mapping_purposes() as $purpose => [$label, $group, $canonical, $expect]) {
        // Only the ones that are NOT an alias onto an existing core purpose.
        if ($canonical === $purpose) {
            $extra[$canonical] = ['label' => $label, 'expect' => $expect];
        }
    }
    // These four already exist in the core catalogue under the same key.
    unset($extra['cogs'], $extra['purchase_clearing'], $extra['opening_equity']);

    return $extra;
}

/**
 * Resolve a purpose to a ledger, most specific scope first (item, then
 * category, then the company default).
 *
 * Delegates to inv_resolve_mapping() against the SHARED table after
 * translating the jewellery name to its canonical one, so a ledger mapped on
 * the Inventory screen is already mapped here and vice versa.
 */
function jewellery_resolve_mapping(int $companyId, string $purpose, ?int $itemId = null, ?string $category = null): ?array
{
    if (!function_exists('inv_resolve_mapping')) {
        return null;
    }

    return inv_resolve_mapping($companyId, jw_canonical_purpose($purpose), $itemId, $category);
}

/** Set (or clear, with $ledgerId = 0) the company-default ledger for a purpose. */
function jewellery_save_mapping(int $companyId, string $purpose, int $ledgerId, int $userId = 0): void
{
    if (!array_key_exists($purpose, jewellery_mapping_purposes())) {
        throw new RuntimeException('Unknown posting purpose: ' . $purpose);
    }
    $canonical = jw_canonical_purpose($purpose);

    if ($ledgerId <= 0) {
        db()->prepare("DELETE FROM inventory_ledger_mappings WHERE company_id = :cid AND scope = 'global' AND purpose = :p AND item_id IS NULL AND category IS NULL")
            ->execute(['cid' => $companyId, 'p' => $canonical]);
        return;
    }

    // The ledger must belong to this company — a tampered id must never map
    // another tenant's ledger into these books.
    $check = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :id AND company_id = :cid');
    $check->execute(['id' => $ledgerId, 'cid' => $companyId]);
    if ((int) $check->fetchColumn() === 0) {
        throw new RuntimeException('That ledger does not belong to this company.');
    }

    $existing = db()->prepare("SELECT id FROM inventory_ledger_mappings WHERE company_id = :cid AND scope = 'global' AND purpose = :p AND item_id IS NULL AND category IS NULL LIMIT 1");
    $existing->execute(['cid' => $companyId, 'p' => $canonical]);
    $id = (int) ($existing->fetchColumn() ?: 0);
    if ($id > 0) {
        db()->prepare('UPDATE inventory_ledger_mappings SET ledger_id = :lid WHERE id = :id')
            ->execute(['lid' => $ledgerId, 'id' => $id]);
        return;
    }

    db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, purpose, ledger_id, created_by) VALUES (:cid, 'global', :p, :lid, :by)")
        ->execute(['cid' => $companyId, 'p' => $canonical, 'lid' => $ledgerId, 'by' => $userId ?: null]);
}

/**
 * Where each posting purpose belongs in a chart of accounts, so the whole set
 * can be opened in one action.
 *
 * purpose => [group name, group master_key, ledger name]
 *
 * Requiring twenty-six ledgers to be created and mapped by hand before the
 * first receipt will post is not a safety feature, it is an obstacle: the shop
 * meets it one blocking error at a time, mid-transaction. The ladder still
 * refuses to GUESS — nothing here is resolved implicitly at posting time — but
 * the whole standard set can be opened deliberately, in one click, and edited
 * afterwards like any other ledger.
 */
function jewellery_standard_ledger_plan(): array
{
    return [
        'stock_metal'       => ['Inventory', 'current_asset', 'Metal stock'],
        'stock_finished'    => ['Inventory', 'current_asset', 'Finished ornament stock'],
        'stock_stone'       => ['Inventory', 'current_asset', 'Stone stock'],
        'stock_karigar'     => ['Inventory', 'current_asset', 'Metal with karigar'],
        'stock_refinery'    => ['Inventory', 'current_asset', 'Metal with refinery'],
        'metal_exchange'    => ['Inventory', 'current_asset', 'Metal exchange clearing'],
        'sales_metal'       => ['Sales', 'direct_income', 'Sales — metal value'],
        'sales_making'      => ['Sales', 'direct_income', 'Sales — making charge'],
        'sales_stone'       => ['Sales', 'direct_income', 'Sales — stone value'],
        'other_charges'     => ['Sales', 'indirect_income', 'Other charges recovered'],
        'sales_discount'    => ['Sales', 'indirect_expense', 'Sales discount given'],
        'sales_return'      => ['Sales', 'direct_expense', 'Sales return'],
        'purchase_return'   => ['Purchases', 'direct_income', 'Purchase return'],
        'purchase_clearing' => ['Current Liabilities', 'current_liability', 'Purchase clearing'],
        'cogs'              => ['Direct Expenses', 'direct_expense', 'Cost of goods sold'],
        'making_expense'    => ['Direct Expenses', 'direct_expense', 'Making / labour expense'],
        'wastage_loss'      => ['Direct Expenses', 'direct_expense', 'Wastage loss'],
        'refinery_loss'     => ['Direct Expenses', 'direct_expense', 'Refining loss'],
        'refinery_charges'  => ['Direct Expenses', 'direct_expense', 'Refinery charges'],
        'customer_advance'  => ['Customer Advances', 'current_liability', 'Customer advances (orders)'],
        'karigar_payable'   => ['Current Liabilities', 'current_liability', 'Karigar wages payable'],
        'vat_output'        => ['Duties & Taxes', 'current_liability', 'VAT payable (output)'],
        'vat_input'         => ['Duties & Taxes', 'current_asset', 'VAT receivable (input)'],
        'spt_output'        => ['Duties & Taxes', 'current_liability', 'Skills Promotion Tax payable'],
        'spt_input'         => ['Duties & Taxes', 'current_asset', 'Skills Promotion Tax receivable'],
        'stock_gain'        => ['Indirect Income', 'indirect_income', 'Stock gain'],
        'stock_loss'        => ['Indirect Expenses', 'indirect_expense', 'Stock loss'],
        'rounding'          => ['Indirect Expenses', 'indirect_expense', 'Rounding difference'],
        // Only a fallback: the shared Opening Balance Adjustments account the
        // core opening-balances screen owns is used when it can be reached.
        'opening_equity'    => ['Capital', 'equity', 'Opening Balance Adjustments'],
    ];
}

/** A group's nature decides the ledger type — they can never disagree if derived. */
function jw_ledger_type_for_master(string $masterKey): string
{
    return match (true) {
        $masterKey === 'equity' => 'equity',
        str_ends_with($masterKey, '_liability') => 'liability',
        str_ends_with($masterKey, '_asset') => 'asset',
        str_ends_with($masterKey, '_income') => 'revenue',
        default => 'expense',
    };
}

/** Find or create a ledger group by name and nature. Matches how the seeded chart is built. */
function jw_ledger_group_id(int $companyId, string $name, string $masterKey): int
{
    if (!table_exists('ledger_groups')) {
        return 0;
    }
    $stmt = db()->prepare('SELECT id FROM ledger_groups WHERE company_id = :cid AND name = :name AND master_key = :mk
        ORDER BY id ASC LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'name' => $name, 'mk' => $masterKey]);
    $groupId = (int) ($stmt->fetchColumn() ?: 0);
    if ($groupId > 0) {
        return $groupId;
    }

    db()->prepare('INSERT INTO ledger_groups (company_id, code, name, master_key, is_cash_or_bank, is_system)
        VALUES (:cid, :code, :name, :mk, 0, 0)')
        ->execute(['cid' => $companyId, 'code' => coa_next_group_code($companyId, $masterKey),
            'name' => $name, 'mk' => $masterKey]);

    return (int) db()->lastInsertId();
}

/**
 * Open and map every standard jewellery posting ledger that is still missing.
 *
 * Only fills GAPS. A purpose already mapped — to anything, however unusual — is
 * left exactly as it is, so running this on a shop that has arranged its own
 * chart of accounts changes nothing it did not need.
 *
 * @return array{created: string[], mapped: string[], skipped: string[], errors: string[]}
 */
function jewellery_autocreate_mappings(int $companyId, int $userId = 0): array
{
    $result = ['created' => [], 'mapped' => [], 'skipped' => [], 'errors' => []];
    if (!table_exists('ledgers') || !table_exists('ledger_groups')) {
        $result['errors'][] = 'The chart of accounts is not set up for this company yet.';

        return $result;
    }

    $existing = jewellery_mappings_by_purpose($companyId);
    $labels = jewellery_mapping_purposes();

    foreach (jewellery_standard_ledger_plan() as $purpose => [$groupName, $masterKey, $ledgerName]) {
        $label = (string) ($labels[$purpose][0] ?? $purpose);
        if (isset($existing[$purpose])) {
            $result['skipped'][] = $label;
            continue;
        }

        try {
            // The opening contra is NOT a jewellery account. The core opening
            // balances screen already owns one — parking one difference in two
            // different places is exactly the duplication worth removing.
            if ($purpose === 'opening_equity' && function_exists('opening_balance_ledger_id')) {
                $sharedId = opening_balance_ledger_id($companyId);
                if ($sharedId > 0) {
                    jewellery_save_mapping($companyId, $purpose, $sharedId, $userId);
                    $result['mapped'][] = $label;
                    continue;
                }
            }

            $groupId = jw_ledger_group_id($companyId, $groupName, $masterKey);
            if ($groupId <= 0) {
                $result['errors'][] = $label . ': could not open the "' . $groupName . '" group.';
                continue;
            }

            // Identity is the stable code, so re-running never duplicates and a
            // later rename of the ledger cannot orphan the mapping.
            $code = 'JW-' . strtoupper(str_replace('_', '-', $purpose));
            $byCode = db()->prepare('SELECT id FROM ledgers WHERE company_id = :cid AND code = :code LIMIT 1');
            $byCode->execute(['cid' => $companyId, 'code' => $code]);
            $ledgerId = (int) ($byCode->fetchColumn() ?: 0);

            if ($ledgerId <= 0) {
                // Adopt a same-named ledger the shop already opened by hand
                // rather than creating a near-duplicate beside it.
                $byName = db()->prepare("SELECT id FROM ledgers WHERE company_id = :cid AND group_id = :gid
                    AND name = :name AND status = 'active' LIMIT 1");
                $byName->execute(['cid' => $companyId, 'gid' => $groupId, 'name' => $ledgerName]);
                $ledgerId = (int) ($byName->fetchColumn() ?: 0);
            }

            if ($ledgerId <= 0) {
                db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status)
                    VALUES (:cid, :gid, :code, :name, :type, 'active')")
                    ->execute(['cid' => $companyId, 'gid' => $groupId, 'code' => $code,
                        'name' => $ledgerName, 'type' => jw_ledger_type_for_master($masterKey)]);
                $ledgerId = (int) db()->lastInsertId();
                $result['created'][] = $ledgerName;
            }

            jewellery_save_mapping($companyId, $purpose, $ledgerId, $userId);
            $result['mapped'][] = $label;
        } catch (Throwable $planException) {
            $result['errors'][] = $label . ': ' . $planException->getMessage();
        }
    }

    return $result;
}

/**
 * Every company-default mapping, keyed by the JEWELLERY purpose name, for the
 * settings screen. Reads the shared table, so a ledger someone set on the
 * Inventory mapping screen shows up here already filled in.
 */
function jewellery_mappings_by_purpose(int $companyId): array
{
    $stmt = db()->prepare("SELECT m.purpose, m.ledger_id, l.name AS ledger_name, l.code AS ledger_code
        FROM inventory_ledger_mappings m
        INNER JOIN ledgers l ON l.id = m.ledger_id AND l.company_id = m.company_id
        WHERE m.company_id = :cid AND m.scope = 'global'");
    $stmt->execute(['cid' => $companyId]);
    $byCanonical = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byCanonical[(string) $row['purpose']] = $row;
    }

    // Present them back under the jewellery names the settings screen uses.
    $out = [];
    foreach (jewellery_mapping_purposes() as $purpose => [, , $canonical]) {
        if (isset($byCanonical[$canonical])) {
            $out[$purpose] = $byCanonical[$canonical];
        }
    }

    return $out;
}

/** Which of the given purposes have no ledger yet — the posting-readiness gap. */
function jewellery_missing_mappings(int $companyId, array $purposes): array
{
    $missing = [];
    foreach ($purposes as $purpose) {
        if (!jewellery_resolve_mapping($companyId, $purpose)) {
            $missing[] = $purpose;
        }
    }

    return $missing;
}

// ---------------------------------------------------------------------------
// Taxes
//
// A tax is a row, not a branch in the pricing code. That is the whole design:
// Nepal levies Skills Promotion Tax on the metal-plus-wastage-plus-making
// value, charges VAT on diamond only this year, and will change both again.
// Each of those is an INSERT or an UPDATE here, never a code release.
//
// Two rules give the ordering its meaning:
//   * taxes are charged in `sequence` order, lowest first;
//   * a tax based on `subtotal_with_taxes` sees every tax already charged.
// "VAT is the final tax applicable" is therefore expressed as: VAT has the
// highest sequence and that base. Nothing in the engine special-cases it.
// ---------------------------------------------------------------------------

/** Active taxes in force on a date, in the order they are charged. */
function jewellery_taxes_list(int $companyId, string $docType = '', string $onDate = '', bool $activeOnly = true): array
{
    if (!table_exists('jewellery_taxes')) {
        return [];
    }
    $sql = 'SELECT * FROM jewellery_taxes WHERE company_id = :cid';
    $params = ['cid' => $companyId];
    if ($activeOnly) {
        $sql .= ' AND active = 1';
    }
    if ($docType !== '') {
        $sql .= ' AND FIND_IN_SET(:dt, doc_types)';
        $params['dt'] = $docType;
    }
    if ($onDate !== '') {
        // A tax that has ended still prices last year's invoices, so the window
        // is checked against the DOCUMENT's date, never against today.
        $sql .= ' AND (effective_from IS NULL OR effective_from <= :d1)
                  AND (effective_to IS NULL OR effective_to >= :d2)';
        $params['d1'] = $onDate;
        $params['d2'] = $onDate;
    }
    $sql .= ' ORDER BY sequence ASC, id ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jewellery_tax(int $companyId, int $taxId): ?array
{
    if ($taxId <= 0 || !table_exists('jewellery_taxes')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM jewellery_taxes WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $taxId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The bases a tax may be charged on, with the plain-language description. */
function jewellery_tax_bases(): array
{
    return [
        'metal' => 'Metal value only',
        'making' => 'Making charge only',
        'stone' => 'Stone value only',
        'stone_diamond' => 'Stone + diamond + other diamond',
        'wastage' => 'Wastage value only',
        'metal_making' => 'Metal + making',
        'metal_wastage_making' => 'Metal + wastage + making',
        'subtotal' => 'Whole line before tax',
        'subtotal_with_taxes' => 'Whole line including taxes charged before this one',
    ];
}

/** Create or update a tax. */
function jewellery_save_tax(int $companyId, array $input, int $userId = 0): int
{
    $taxId = (int) ($input['id'] ?? 0);
    $code = strtoupper(trim((string) ($input['code'] ?? '')));
    $name = trim((string) ($input['name'] ?? ''));
    if ($code === '' || $name === '') {
        throw new RuntimeException('A tax needs a code and a name.');
    }
    $rate = round((float) ($input['rate'] ?? 0), 4);
    if ($rate < 0) {
        throw new RuntimeException('A tax rate cannot be negative.');
    }

    // doc_types comes back out of the database as the SET's comma string, so a
    // read-modify-write round trip has to survive that as well as a form's
    // array of checkboxes.
    $rawDocTypes = $input['doc_types'] ?? ['sale'];
    if (is_string($rawDocTypes)) {
        $rawDocTypes = explode(',', $rawDocTypes);
    }
    $docTypes = [];
    foreach ((array) $rawDocTypes as $docType) {
        $docType = trim((string) $docType);
        if (in_array($docType, ['sale', 'purchase'], true)) {
            $docTypes[] = $docType;
        }
    }
    if ($docTypes === []) {
        $docTypes = ['sale'];
    }

    $params = [
        'cid' => $companyId,
        'code' => $code,
        'name' => $name,
        'rate' => $rate,
        'base' => jw_enum($input['base'] ?? null, array_keys(jewellery_tax_bases()), 'subtotal'),
        'applies' => jw_enum($input['applies_to'] ?? null, ['all', 'tagged'], 'all'),
        'docs' => implode(',', array_unique($docTypes)),
        'seq' => (int) ($input['sequence'] ?? 100),
        'manual' => !empty($input['manual_entry']) ? 1 : 0,
        'outp' => trim((string) ($input['output_purpose'] ?? 'vat_output')) ?: 'vat_output',
        'inp' => trim((string) ($input['input_purpose'] ?? 'vat_input')) ?: 'vat_input',
        'from' => ($input['effective_from'] ?? '') !== '' ? (string) $input['effective_from'] : null,
        'to' => ($input['effective_to'] ?? '') !== '' ? (string) $input['effective_to'] : null,
        'active' => array_key_exists('active', $input) ? (!empty($input['active']) ? 1 : 0) : 1,
        'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
    ];

    if ($taxId > 0) {
        if (!jewellery_tax($companyId, $taxId)) {
            throw new RuntimeException('That tax does not belong to this company.');
        }
        $params['id'] = $taxId;
        db()->prepare('UPDATE jewellery_taxes SET code = :code, name = :name, rate = :rate, base = :base,
                applies_to = :applies, doc_types = :docs, sequence = :seq, manual_entry = :manual,
                output_purpose = :outp, input_purpose = :inp, effective_from = :from, effective_to = :to,
                active = :active, notes = :notes
            WHERE id = :id AND company_id = :cid')->execute($params);

        return $taxId;
    }

    db()->prepare('INSERT INTO jewellery_taxes (company_id, code, name, rate, base, applies_to, doc_types,
            sequence, manual_entry, output_purpose, input_purpose, effective_from, effective_to, active, notes)
        VALUES (:cid, :code, :name, :rate, :base, :applies, :docs, :seq, :manual, :outp, :inp, :from, :to, :active, :notes)')
        ->execute($params);

    return (int) db()->lastInsertId();
}

/** Retire a tax. Deleting one would restate every document it ever priced. */
function jewellery_delete_tax(int $companyId, int $taxId): array
{
    $tax = jewellery_tax($companyId, $taxId);
    if (!$tax) {
        return ['ok' => false, 'error' => 'That tax does not belong to this company.'];
    }
    $used = db()->prepare('SELECT COUNT(*) FROM jewellery_line_taxes WHERE company_id = :cid AND tax_id = :tid');
    $used->execute(['cid' => $companyId, 'tid' => $taxId]);
    if ((int) $used->fetchColumn() > 0) {
        db()->prepare('UPDATE jewellery_taxes SET active = 0 WHERE id = :id AND company_id = :cid')
            ->execute(['id' => $taxId, 'cid' => $companyId]);

        return ['ok' => true, 'error' => '',
            'note' => 'This tax has already been charged on documents, so it was switched off rather than deleted. '
                . 'Those documents keep the tax they were priced with.'];
    }

    db()->prepare('DELETE FROM jewellery_taxes WHERE id = :id AND company_id = :cid')
        ->execute(['id' => $taxId, 'cid' => $companyId]);

    return ['ok' => true, 'error' => '', 'note' => ''];
}

/**
 * Seed the taxes a Nepali jewellery house is charging right now.
 *
 * Both are ordinary editable rows. Seeded only into an empty register, so a
 * shop that has since retired or reworded one does not get it reinstated on
 * the next repair run.
 */
function jewellery_seed_taxes(int $companyId): void
{
    if (!table_exists('jewellery_taxes')) {
        return;
    }
    $existing = db()->prepare('SELECT COUNT(*) FROM jewellery_taxes WHERE company_id = :cid');
    $existing->execute(['cid' => $companyId]);
    if ((int) $existing->fetchColumn() > 0) {
        return;
    }

    $settings = jewellery_settings($companyId);

    // Skills Promotion Tax: 0.5% of metal + wastage + making. Charged first,
    // and punched by hand on the document because the shop totals it before
    // entering it.
    jewellery_save_tax($companyId, [
        'code' => 'SD',
        'name' => 'Skills Development Tax',
        'rate' => 0.5,
        // The bill's "SD Taxable Amt" is metal + making. The metal figure
        // already carries the wastage, because the total weight is priced as
        // one number.
        'base' => 'metal_making',
        'applies_to' => 'all',
        'doc_types' => ['sale'],
        'sequence' => 100,
        'manual_entry' => 0,
        'output_purpose' => 'spt_output',
        'input_purpose' => 'spt_input',
        'active' => 1,
        'notes' => 'SD Taxable Amt on the bill: metal (total weight x rate) plus making charge. Stones are excluded.',
    ]);

    // VAT last, on everything including the tax above, and only on items
    // tagged for it â€” diamond, for the current year.
    jewellery_save_tax($companyId, [
        'code' => 'VAT',
        'name' => 'VAT',
        'rate' => (float) ($settings['vat_rate'] ?? 13.0),
        // The bill's "Vatable Amt" is the stone side alone — never gold,
        // never making, and never on top of the SD tax. The two sit on
        // disjoint bases.
        'base' => 'stone_diamond',
        'applies_to' => 'all',
        'doc_types' => ['sale', 'purchase'],
        'sequence' => 900,
        'manual_entry' => 0,
        'output_purpose' => 'vat_output',
        'input_purpose' => 'vat_input',
        'active' => 1,
        'notes' => 'Vatable Amt on the bill: stone + diamond + other diamond. Gold and making are outside VAT.',
    ]);
}

/** The tax ids explicitly tagged onto one item. */
function jw_item_tax_ids(int $companyId, int $itemId): array
{
    if ($itemId <= 0 || !table_exists('jewellery_item_taxes')) {
        return [];
    }
    $stmt = db()->prepare('SELECT tax_id FROM jewellery_item_taxes WHERE company_id = :cid AND item_id = :iid');
    $stmt->execute(['cid' => $companyId, 'iid' => $itemId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Replace the tax tags on one item. */
function jw_save_item_taxes(int $companyId, int $itemId, array $taxIds): void
{
    if ($itemId <= 0 || !table_exists('jewellery_item_taxes')) {
        return;
    }
    db()->prepare('DELETE FROM jewellery_item_taxes WHERE company_id = :cid AND item_id = :iid')
        ->execute(['cid' => $companyId, 'iid' => $itemId]);
    $insert = db()->prepare('INSERT IGNORE INTO jewellery_item_taxes (company_id, item_id, tax_id) VALUES (:cid, :iid, :tid)');
    foreach (array_unique(array_map('intval', $taxIds)) as $taxId) {
        if ($taxId > 0 && jewellery_tax($companyId, $taxId)) {
            $insert->execute(['cid' => $companyId, 'iid' => $itemId, 'tid' => $taxId]);
        }
    }
}

/**
 * Charge every applicable tax on ONE line, in sequence.
 *
 * Pure: it reads the parts of an already-priced line plus the tax rows, and
 * returns the charges. Each tax is rounded as it is charged, and a later
 * `subtotal_with_taxes` base sees those rounded amounts â€” so the arithmetic
 * on screen is the arithmetic on the invoice, to the paisa.
 *
 * @param array $parts metal, wastage, making, stone â€” already priced
 * @return array{taxes: array, total: float, vat: float, other: float}
 */
function jw_charge_line_taxes(array $parts, array $taxes, array $itemTaxIds, bool $itemVatFlag, string $itemVatBase): array
{
    $metal = jw_round_money((float) ($parts['metal'] ?? 0));
    $wastage = jw_round_money((float) ($parts['wastage'] ?? 0));
    $making = jw_round_money((float) ($parts['making'] ?? 0));
    $stone = jw_round_money((float) ($parts['stone'] ?? 0));
    $diamond = jw_round_money((float) ($parts['diamond'] ?? 0));
    $otherDiamond = jw_round_money((float) ($parts['other_diamond'] ?? 0));
    $stoneSide = jw_round_money($stone + $diamond + $otherDiamond);
    // The metal figure already carries the wastage (the total weight is priced
    // as one number, the way a bill does it), so wastage is NOT added again.
    $subtotal = jw_round_money($metal + $making + $stoneSide);

    $charged = [];
    $runningTax = 0.0;
    $vat = 0.0;
    $other = 0.0;

    foreach ($taxes as $tax) {
        $isVatTax = (string) ($tax['output_purpose'] ?? '') === 'vat_output';

        // Applicability. A 'tagged' tax reaches an item explicitly linked to
        // it; for VAT the item's own vat_applicable flag counts as that link,
        // so books set up before taxes became data keep working unchanged.
        if ((string) ($tax['applies_to'] ?? 'all') === 'tagged') {
            $tagged = in_array((int) $tax['id'], $itemTaxIds, true) || ($isVatTax && $itemVatFlag);
            if (!$tagged) {
                continue;
            }
        }

        $base = (string) ($tax['base'] ?? 'subtotal');
        // The item's own VAT base still overrides, so an item marked
        // "making only" is taxed on the making charge and nothing else.
        if ($isVatTax && $itemVatBase === 'making_only') {
            $base = 'making';
        } elseif ($isVatTax && $itemVatBase === 'stone_only') {
            $base = 'stone_diamond';
        }

        $baseAmount = match ($base) {
            'metal' => $metal,
            'making' => $making,
            'stone' => $stone,
            'stone_diamond' => $stoneSide,
            'wastage' => $wastage,
            'metal_making' => jw_round_money($metal + $making),
            'metal_wastage_making' => jw_round_money($metal + $wastage + $making),
            'subtotal_with_taxes' => jw_round_money($subtotal + $runningTax),
            default => $subtotal,
        };

        $rate = (float) ($tax['rate'] ?? 0);
        $amount = jw_round_money($baseAmount * $rate / 100.0);
        if ($amount === 0.0 && $baseAmount === 0.0) {
            continue;
        }

        $charged[] = [
            'tax_id' => (int) ($tax['id'] ?? 0),
            'tax_code' => (string) ($tax['code'] ?? ''),
            'tax_name' => (string) ($tax['name'] ?? ''),
            'base' => $base,
            'base_amount' => $baseAmount,
            'rate' => $rate,
            'amount' => $amount,
            'sequence' => (int) ($tax['sequence'] ?? 100),
            'is_vat' => $isVatTax,
            'manual_entry' => (int) ($tax['manual_entry'] ?? 0) === 1,
            'output_purpose' => (string) ($tax['output_purpose'] ?? 'vat_output'),
            'input_purpose' => (string) ($tax['input_purpose'] ?? 'vat_input'),
        ];

        $runningTax = jw_round_money($runningTax + $amount);
        if ($isVatTax) {
            $vat = jw_round_money($vat + $amount);
        } else {
            $other = jw_round_money($other + $amount);
        }
    }

    return ['taxes' => $charged, 'total' => $runningTax, 'vat' => $vat, 'other' => $other];
}
