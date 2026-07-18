<?php
declare(strict_types=1);

/**
 * ============================================================================
 * Central Opening-Balance service (single source of truth)
 * ----------------------------------------------------------------------------
 * The general ledger is PERPETUAL: a permanent (asset/liability/equity) account's
 * opening balance for a fiscal year already equals the cumulative net of every
 * posted voucher dated before that year's start date — i.e. the previous year's
 * closing balance — with no snapshot and no duplication. Income/expense accounts
 * reset to zero each year and their prior net rolls into "Retained Earnings b/f".
 * All of that is computed by rc_ledger_balances() in reports_engine.php, which is
 * exactly what the Trial Balance, Balance Sheet, Ledger and Group reports use.
 *
 * This service is a THIN wrapper over that same computation, so the Opening
 * Balance feature and every report share ONE calculation. It never re-posts the
 * base opening (that would double-count the perpetual GL); instead it:
 *   - snapshots the derived opening into an auditable batch (workflow + versions),
 *   - lets an authorised admin post a BALANCED opening-adjustment delta journal
 *     (dated on the fiscal-year start) through the normal posting engine, which
 *     the reports then pick up automatically,
 *   - reconciles sub-ledgers, inventory and fixed assets against the control GL.
 *
 * Money is handled as rounded 2-dp floats in PHP and stored as DECIMAL(18,2).
 * ============================================================================
 */

require_once __DIR__ . '/reports_engine.php';

const OB_EPSILON = 0.005;

/** Round money to 2dp. */
function ob_money(float $v): float
{
    return round($v, 2);
}

/** True when the two amounts are equal within the money epsilon. */
function ob_near(float $a, float $b): bool
{
    return abs($a - $b) < OB_EPSILON;
}

/** A permanent (balance-sheet) nature carries an opening balance; temporary does not. */
function ob_nature_is_permanent(string $nature): bool
{
    return in_array($nature, ['asset', 'liability', 'equity'], true);
}

/**
 * The fiscal year immediately preceding the given one (its end date is the day
 * before this year's start date). Returns the FY row or null when there is none
 * (i.e. this is the entity's first fiscal year).
 */
function ob_previous_fiscal_year(int $companyId, int $fiscalYearId): ?array
{
    $fy = fiscal_year_by_id($fiscalYearId);
    if (!$fy || (int) $fy['company_id'] !== $companyId) {
        return null;
    }
    $start = (string) $fy['start_date'];
    if ($start === '') {
        return null;
    }
    $priorDate = date('Y-m-d', strtotime($start . ' -1 day'));
    $prev = fiscal_year_for_date($companyId, $priorDate);
    // Guard against a self-match on overlapping/adjacent boundaries.
    if ($prev && (int) $prev['id'] === $fiscalYearId) {
        return null;
    }
    return $prev ?: null;
}

/**
 * THE opening-balance calculation for a fiscal year, derived from the perpetual
 * GL via rc_ledger_balances (same source as every report). Returns one row per
 * ledger plus the synthetic "Retained Earnings b/f" equity line. Each row:
 *   line_key, ledger_id, code, name, type, master_key, group_name, nature,
 *   is_applicable (false for income/expense), opening_dr, opening_cr, opening_net.
 * Income/expense ledgers are returned with opening 0 and is_applicable=false.
 */
function ob_computed_opening_rows(int $companyId, int $fiscalYearId): array
{
    $fy = fiscal_year_by_id($fiscalYearId);
    if (!$fy || (int) $fy['company_id'] !== $companyId) {
        return [];
    }
    $fyStart = (string) $fy['start_date'];
    // Opening of the year = balances brought forward as at the FY start date.
    // Passing $fyStart as both the window start and the fy-reset boundary makes
    // permanent accounts show their cumulative prior closing and temporary
    // accounts reset to zero, with prior P&L rolled into "Retained Earnings b/f".
    $rows = rc_ledger_balances($companyId, $fyStart, $fyStart, '', 0, 0, [], $fyStart);

    $out = [];
    foreach ($rows as $r) {
        $ledgerId = (int) ($r['id'] ?? 0);
        $nature = rc_ledger_nature($r);
        $isPermanent = ob_nature_is_permanent($nature);
        $opening = $isPermanent ? ob_money((float) ($r['opening_net'] ?? 0)) : 0.0;

        if ($ledgerId === 0 && (string) ($r['name'] ?? '') === 'Retained Earnings b/f') {
            $lineKey = 'RE_BF';
            $isApplicable = true;
        } else {
            $lineKey = 'L' . $ledgerId;
            $isApplicable = $isPermanent;
        }

        $out[] = [
            'line_key' => $lineKey,
            'ledger_id' => $ledgerId ?: null,
            'code' => (string) ($r['code'] ?? ''),
            'name' => (string) ($r['name'] ?? ''),
            'type' => (string) ($r['type'] ?? ''),
            'master_key' => (string) ($r['master_key'] ?? ''),
            'group_name' => (string) ($r['group_name'] ?? ''),
            'nature' => $nature,
            'is_applicable' => $isApplicable,
            'opening_net' => $opening,
            'opening_dr' => max(0.0, $opening),
            'opening_cr' => max(0.0, -$opening),
        ];
    }
    return $out;
}

/**
 * The prior-years' cumulative net result carried into this fiscal year, as a
 * CREDIT-positive figure (profit positive, loss negative). This is what moves to
 * Retained Earnings; it is derived from the same "Retained Earnings b/f" row the
 * statements use, so the Trial Balance and Balance Sheet always agree with it.
 */
function ob_retained_earnings_transfer(int $companyId, int $fiscalYearId): float
{
    foreach (ob_computed_opening_rows($companyId, $fiscalYearId) as $row) {
        if ($row['line_key'] === 'RE_BF') {
            return ob_money(-(float) $row['opening_net']); // opening_net is debit-positive
        }
    }
    return 0.0;
}

/** The fiscal-year opening batch row, or null. */
function ob_get_batch(int $companyId, int $fiscalYearId): ?array
{
    if (!table_exists('opening_balance_batches')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM opening_balance_batches WHERE company_id = :cid AND fiscal_year_id = :fy LIMIT 1');
    $stmt->execute(['cid' => $companyId, 'fy' => $fiscalYearId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Batch lines ordered by account code. */
function ob_get_lines(int $batchId): array
{
    if ($batchId <= 0 || !table_exists('opening_balance_lines')) {
        return [];
    }
    $stmt = db()->prepare("SELECT * FROM opening_balance_lines WHERE batch_id = :bid
        ORDER BY (account_type = 'income' OR account_type = 'expense' OR account_type='revenue') ASC,
                 FIELD(account_type,'asset','liability','equity'), line_label ASC");
    $stmt->execute(['bid' => $batchId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Audit log rows for a batch, newest first. */
function ob_audit_logs(int $batchId): array
{
    if ($batchId <= 0 || !table_exists('opening_balance_audit_logs')) {
        return [];
    }
    $stmt = db()->prepare('SELECT * FROM opening_balance_audit_logs WHERE batch_id = :bid ORDER BY id DESC LIMIT 200');
    $stmt->execute(['bid' => $batchId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Write one audit-trail row. Never deletes prior rows. */
function ob_write_audit(int $companyId, ?int $batchId, ?int $lineId, ?int $fiscalYearId, string $action, ?array $old, ?array $new, string $reason, ?int $actorId, ?int $reversalOfId = null): void
{
    if (!table_exists('opening_balance_audit_logs')) {
        return;
    }
    $actorName = '';
    if ($actorId) {
        $u = db()->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
        $u->execute(['id' => $actorId]);
        $actorName = (string) ($u->fetchColumn() ?: '');
    }
    db()->prepare('INSERT INTO opening_balance_audit_logs
        (company_id, batch_id, line_id, fiscal_year_id, action, old_values, new_values, reason, performed_by, performed_by_name, ip_address, reversal_of_id)
        VALUES (:cid, :bid, :lid, :fy, :action, :old, :new, :reason, :by, :byname, :ip, :rev)')
        ->execute([
            'cid' => $companyId, 'bid' => $batchId, 'lid' => $lineId, 'fy' => $fiscalYearId,
            'action' => $action,
            'old' => $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            'new' => $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
            'reason' => $reason !== '' ? $reason : null,
            'by' => $actorId ?: null, 'byname' => $actorName !== '' ? $actorName : null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'rev' => $reversalOfId,
        ]);
}

/**
 * Generate (or refresh) the opening-balance batch for a fiscal year from the
 * previous year's closing balances. Idempotent: re-running recomputes the system
 * opening but PRESERVES any admin adjustments already applied per line — it never
 * duplicates lines or journals. Refuses to touch a finalized/locked batch.
 * Returns ['ok'=>bool, 'batch_id'=>int, 'error'=>?string, 'warning'=>?string].
 */
function ob_generate_batch(int $companyId, int $fiscalYearId, ?int $actorId = null): array
{
    if (!table_exists('opening_balance_batches')) {
        return ['ok' => false, 'error' => 'Opening-balance tables are not installed.'];
    }
    $fy = fiscal_year_by_id($fiscalYearId);
    if (!$fy || (int) $fy['company_id'] !== $companyId) {
        return ['ok' => false, 'error' => 'Fiscal year not found for this company.'];
    }
    $existing = ob_get_batch($companyId, $fiscalYearId);
    if ($existing && in_array((string) $existing['status'], ['finalized', 'locked'], true)) {
        return ['ok' => false, 'error' => 'The opening balance is already ' . $existing['status'] . '. Make corrections through an approved adjustment instead of regenerating.'];
    }

    $prev = ob_previous_fiscal_year($companyId, $fiscalYearId);
    $basis = $prev ? 'carried_forward' : 'initial_manual';
    $rows = ob_computed_opening_rows($companyId, $fiscalYearId);
    $reTransfer = ob_retained_earnings_transfer($companyId, $fiscalYearId);

    // Warn (do not block) when current-year transactions already exist — the user
    // may want to reconcile rather than regenerate destructively.
    $warning = null;
    $txStmt = db()->prepare("SELECT COUNT(*) FROM vouchers WHERE company_id = :cid AND fiscal_year_id = :fy AND status = 'posted' AND source_type <> 'opening_balance_adj' AND COALESCE(voucher_date, DATE(created_at)) > :fystart");
    $txStmt->execute(['cid' => $companyId, 'fy' => $fiscalYearId, 'fystart' => (string) $fy['start_date']]);
    if ((int) $txStmt->fetchColumn() > 0) {
        $warning = 'Current-year transactions already exist. Opening balances were refreshed from the previous year; posted transactions were not touched — review the differences before finalizing.';
    }

    // Preserve existing per-line adjustments across a regenerate.
    $keptAdjustments = [];
    if ($existing) {
        foreach (ob_get_lines((int) $existing['id']) as $l) {
            if (abs((float) $l['adjustment_debit']) > OB_EPSILON || abs((float) $l['adjustment_credit']) > OB_EPSILON) {
                $keptAdjustments[(string) $l['line_key']] = $l;
            }
        }
    }

    $pdo = db();
    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }
    try {
        if ($existing) {
            $batchId = (int) $existing['id'];
            $pdo->prepare('UPDATE opening_balance_batches SET previous_fiscal_year_id = :pfy, basis = :basis, status = :status, retained_earnings_transfer = :re, version = version + 1, generated_by = :by, generated_at = NOW() WHERE id = :id')
                ->execute(['pfy' => $prev['id'] ?? null, 'basis' => $basis, 'status' => 'generated', 're' => ob_money($reTransfer), 'by' => $actorId ?: null, 'id' => $batchId]);
            $pdo->prepare('DELETE FROM opening_balance_lines WHERE batch_id = :bid')->execute(['bid' => $batchId]);
        } else {
            $pdo->prepare('INSERT INTO opening_balance_batches (company_id, fiscal_year_id, previous_fiscal_year_id, basis, status, retained_earnings_transfer, version, generated_by, generated_at) VALUES (:cid, :fy, :pfy, :basis, :status, :re, 1, :by, NOW())')
                ->execute(['cid' => $companyId, 'fy' => $fiscalYearId, 'pfy' => $prev['id'] ?? null, 'basis' => $basis, 'status' => 'generated', 're' => ob_money($reTransfer), 'by' => $actorId ?: null]);
            $batchId = (int) $pdo->lastInsertId();
        }

        $ins = $pdo->prepare('INSERT INTO opening_balance_lines
            (batch_id, line_key, ledger_id, line_label, account_type, master_key, is_applicable, subledger_type, subledger_id,
             previous_closing_debit, previous_closing_credit, system_opening_debit, system_opening_credit,
             adjustment_debit, adjustment_credit, final_opening_debit, final_opening_credit,
             adjustment_reason, adjustment_reference, adjustment_voucher_id)
            VALUES (:bid, :key, :lid, :label, :atype, :mkey, :appl, NULL, NULL,
             :pcd, :pcc, :sod, :soc, :adjd, :adjc, :fod, :foc, :areason, :aref, :avid)');

        $totalDr = 0.0;
        $totalCr = 0.0;
        foreach ($rows as $row) {
            $sysDr = (float) $row['opening_dr'];
            $sysCr = (float) $row['opening_cr'];
            $kept = $keptAdjustments[$row['line_key']] ?? null;
            $adjDr = $kept ? (float) $kept['adjustment_debit'] : 0.0;
            $adjCr = $kept ? (float) $kept['adjustment_credit'] : 0.0;
            $finalNet = ($sysDr - $sysCr) + ($adjDr - $adjCr);
            $finalDr = max(0.0, $finalNet);
            $finalCr = max(0.0, -$finalNet);
            $ins->execute([
                'bid' => $batchId, 'key' => $row['line_key'], 'lid' => $row['ledger_id'],
                'label' => $row['name'] !== '' ? $row['name'] : $row['line_key'],
                'atype' => $row['type'], 'mkey' => $row['master_key'], 'appl' => $row['is_applicable'] ? 1 : 0,
                'pcd' => ob_money($sysDr), 'pcc' => ob_money($sysCr),
                'sod' => ob_money($sysDr), 'soc' => ob_money($sysCr),
                'adjd' => ob_money($adjDr), 'adjc' => ob_money($adjCr),
                'fod' => ob_money($finalDr), 'foc' => ob_money($finalCr),
                'areason' => $kept['adjustment_reason'] ?? null,
                'aref' => $kept['adjustment_reference'] ?? null,
                'avid' => $kept['adjustment_voucher_id'] ?? null,
            ]);
            $totalDr += $finalDr;
            $totalCr += $finalCr;
        }
        $pdo->prepare('UPDATE opening_balance_batches SET total_debit = :dr, total_credit = :cr WHERE id = :id')
            ->execute(['dr' => ob_money($totalDr), 'cr' => ob_money($totalCr), 'id' => $batchId]);

        ob_write_audit($companyId, $batchId, null, $fiscalYearId, 'generate', null,
            ['basis' => $basis, 'lines' => count($rows), 'total_debit' => ob_money($totalDr), 'total_credit' => ob_money($totalCr)],
            'Generated from ' . ($prev ? ('previous year #' . $prev['id']) : 'initial setup (no previous year)'), $actorId);

        if ($ownsTx) {
            $pdo->commit();
        }
        return ['ok' => true, 'batch_id' => $batchId, 'warning' => $warning];
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** Recompute and persist a batch's total debit/credit. */
function ob_refresh_batch_totals(int $batchId): array
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(final_opening_debit),0) AS dr, COALESCE(SUM(final_opening_credit),0) AS cr FROM opening_balance_lines WHERE batch_id = :bid');
    $stmt->execute(['bid' => $batchId]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['dr' => 0, 'cr' => 0];
    $dr = ob_money((float) $t['dr']);
    $cr = ob_money((float) $t['cr']);
    db()->prepare('UPDATE opening_balance_batches SET total_debit = :dr, total_credit = :cr WHERE id = :id')
        ->execute(['dr' => $dr, 'cr' => $cr, 'id' => $batchId]);
    return ['total_debit' => $dr, 'total_credit' => $cr, 'difference' => ob_money($dr - $cr)];
}

/** Validate that the batch's final opening debits equal credits. */
function ob_validate_batch(int $batchId): array
{
    $t = ob_refresh_batch_totals($batchId);
    $t['balanced'] = ob_near($t['total_debit'], $t['total_credit']);
    return $t;
}

/**
 * Apply an authorised admin adjustment to one opening line. Requires a mandatory
 * reason (>= 10 non-blank chars). Posts a BALANCED delta journal (dated on the FY
 * start) through create_voucher_with_entries so the GL and every report reflect
 * it, and writes a full audit-trail row. Never overwrites the previous year's
 * closing — it records and posts the difference only.
 * Returns ['ok'=>bool, 'error'=>?string].
 */
function ob_apply_adjustment(int $batchId, int $lineId, float $revisedDebit, float $revisedCredit, string $reason, string $reference, ?int $actorId = null): array
{
    $reason = trim($reason);
    if (mb_strlen($reason) < 10) {
        return ['ok' => false, 'error' => 'A reason of at least 10 characters is required for an opening-balance adjustment.'];
    }
    $batchStmt = db()->prepare('SELECT * FROM opening_balance_batches WHERE id = :id LIMIT 1');
    $batchStmt->execute(['id' => $batchId]);
    $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Opening-balance batch not found.'];
    }
    if ((string) $batch['status'] === 'locked') {
        return ['ok' => false, 'error' => 'This opening balance is locked. Unlock it or raise a new fiscal-year adjustment.'];
    }
    $companyId = (int) $batch['company_id'];
    $fiscalYearId = (int) $batch['fiscal_year_id'];

    $lineStmt = db()->prepare('SELECT * FROM opening_balance_lines WHERE id = :id AND batch_id = :bid LIMIT 1');
    $lineStmt->execute(['id' => $lineId, 'bid' => $batchId]);
    $line = $lineStmt->fetch(PDO::FETCH_ASSOC);
    if (!$line) {
        return ['ok' => false, 'error' => 'Opening-balance line not found.'];
    }
    if ((int) $line['is_applicable'] !== 1) {
        return ['ok' => false, 'error' => 'Opening balance is not applicable to income and expense accounts.'];
    }
    $ledgerId = (int) ($line['ledger_id'] ?? 0);
    if ($ledgerId <= 0) {
        return ['ok' => false, 'error' => 'This computed line (e.g. Retained Earnings b/f) cannot be adjusted directly; adjust the underlying ledgers.'];
    }

    $revisedNet = ob_money($revisedDebit - $revisedCredit);
    $systemNet = ob_money((float) $line['system_opening_debit'] - (float) $line['system_opening_credit']);
    $adjustNet = ob_money($revisedNet - $systemNet);

    $fy = fiscal_year_by_id($fiscalYearId);
    $openingDate = (string) ($fy['start_date'] ?? date('Y-m-d'));
    $contraLedgerId = opening_balance_ledger_id($companyId);
    if ($contraLedgerId <= 0) {
        return ['ok' => false, 'error' => 'The Opening Balance Adjustments ledger could not be resolved.'];
    }

    $pdo = db();
    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }
    try {
        // Replace any prior adjustment voucher for this line (uniqueness is by the
        // (source_type, source_id) choke point, so re-adjusting must not duplicate).
        $priorVoucherId = (int) ($line['adjustment_voucher_id'] ?? 0);
        if ($priorVoucherId > 0) {
            $pdo->prepare("DELETE FROM vouchers WHERE id = :id AND source_type = 'opening_balance_adj'")->execute(['id' => $priorVoucherId]);
        }
        $newVoucherId = 0;
        if (abs($adjustNet) > OB_EPSILON) {
            $side = $adjustNet > 0 ? 'debit' : 'credit';
            $amount = abs($adjustNet);
            $newVoucherId = (int) create_voucher_with_entries([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId,
                'voucher_no' => 'OB-ADJ-' . $lineId . '-' . substr((string) time(), -6),
                'voucher_type' => 'journal',
                'source_type' => 'opening_balance_adj',
                'source_id' => $lineId,
                'voucher_date' => $openingDate,
                'narration' => 'Opening balance adjustment — ' . (string) $line['line_label'] . ' (' . $reason . ')',
                'total_amount' => $amount,
                'status' => 'posted',
                'posted_by' => $actorId,
                'posted_at' => date('Y-m-d H:i:s'),
            ], [
                ['ledger_id' => $ledgerId, 'entry_type' => $side, 'amount' => $amount, 'memo' => 'Opening balance adjustment'],
                ['ledger_id' => $contraLedgerId, 'entry_type' => $side === 'debit' ? 'credit' : 'debit', 'amount' => $amount, 'memo' => 'Opening balance adjustment contra — ' . (string) $line['line_label']],
            ]);
        }

        $adjDr = max(0.0, $adjustNet);
        $adjCr = max(0.0, -$adjustNet);
        $finalDr = max(0.0, $revisedNet);
        $finalCr = max(0.0, -$revisedNet);

        $old = ['system' => $systemNet, 'previous_adjustment' => (float) $line['adjustment_debit'] - (float) $line['adjustment_credit'], 'previous_final' => (float) $line['final_opening_debit'] - (float) $line['final_opening_credit']];
        $pdo->prepare('UPDATE opening_balance_lines SET adjustment_debit = :adjd, adjustment_credit = :adjc, final_opening_debit = :fod, final_opening_credit = :foc, adjustment_reason = :reason, adjustment_reference = :ref, adjustment_voucher_id = :vid WHERE id = :id')
            ->execute([
                'adjd' => ob_money($adjDr), 'adjc' => ob_money($adjCr),
                'fod' => ob_money($finalDr), 'foc' => ob_money($finalCr),
                'reason' => $reason, 'ref' => $reference !== '' ? $reference : null,
                'vid' => $newVoucherId ?: null, 'id' => $lineId,
            ]);

        ob_refresh_batch_totals($batchId);
        ob_write_audit($companyId, $batchId, $lineId, $fiscalYearId, 'adjust', $old,
            ['revised_net' => $revisedNet, 'adjustment_net' => $adjustNet, 'voucher_id' => $newVoucherId], $reason, $actorId);

        if ($ownsTx) {
            $pdo->commit();
        }
        return ['ok' => true, 'voucher_id' => $newVoucherId];
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** Move a batch to 'reviewed'. */
function ob_review_batch(int $batchId, ?int $actorId = null): array
{
    $batch = db()->query('SELECT * FROM opening_balance_batches WHERE id = ' . (int) $batchId)->fetch(PDO::FETCH_ASSOC);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Batch not found.'];
    }
    if (!in_array((string) $batch['status'], ['generated', 'reviewed'], true)) {
        return ['ok' => false, 'error' => 'Only a generated batch can be marked reviewed.'];
    }
    db()->prepare('UPDATE opening_balance_batches SET status = :s, reviewed_by = :by, reviewed_at = NOW() WHERE id = :id')
        ->execute(['s' => 'reviewed', 'by' => $actorId ?: null, 'id' => $batchId]);
    ob_write_audit((int) $batch['company_id'], $batchId, null, (int) $batch['fiscal_year_id'], 'review', null, null, 'Marked reviewed', $actorId);
    return ['ok' => true];
}

/** Finalize a batch (after a balanced validation). */
function ob_finalize_batch(int $batchId, ?int $actorId = null): array
{
    $batch = db()->query('SELECT * FROM opening_balance_batches WHERE id = ' . (int) $batchId)->fetch(PDO::FETCH_ASSOC);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Batch not found.'];
    }
    if (in_array((string) $batch['status'], ['finalized', 'locked'], true)) {
        return ['ok' => false, 'error' => 'This batch is already ' . $batch['status'] . '.'];
    }
    $v = ob_validate_batch($batchId);
    if (!$v['balanced']) {
        return ['ok' => false, 'error' => 'Opening balances are not balanced (difference ' . number_format($v['difference'], 2) . '). Resolve the difference before finalizing — it is not posted silently to any account.'];
    }
    db()->prepare('UPDATE opening_balance_batches SET status = :s, finalized_by = :by, finalized_at = NOW() WHERE id = :id')
        ->execute(['s' => 'finalized', 'by' => $actorId ?: null, 'id' => $batchId]);
    ob_write_audit((int) $batch['company_id'], $batchId, null, (int) $batch['fiscal_year_id'], 'finalize', null, ['total_debit' => $v['total_debit'], 'total_credit' => $v['total_credit']], 'Finalized (balanced)', $actorId);
    return ['ok' => true];
}

/** Lock a finalized batch. */
function ob_lock_batch(int $batchId, ?int $actorId = null): array
{
    $batch = db()->query('SELECT * FROM opening_balance_batches WHERE id = ' . (int) $batchId)->fetch(PDO::FETCH_ASSOC);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Batch not found.'];
    }
    if ((string) $batch['status'] !== 'finalized') {
        return ['ok' => false, 'error' => 'Only a finalized batch can be locked.'];
    }
    db()->prepare("UPDATE opening_balance_batches SET status = 'locked', locked_at = NOW() WHERE id = :id")->execute(['id' => $batchId]);
    ob_write_audit((int) $batch['company_id'], $batchId, null, (int) $batch['fiscal_year_id'], 'lock', null, null, 'Locked', $actorId);
    return ['ok' => true];
}

/**
 * Unlock a locked batch (locked -> finalized) so authorised corrections can be
 * made again through adjustments. Requires a mandatory reason (>= 10 chars),
 * mirroring the fiscal-year reopen control, and keeps the full audit trail — the
 * original lock record is never deleted.
 */
function ob_unlock_batch(int $batchId, string $reason, ?int $actorId = null): array
{
    $reason = trim($reason);
    if (mb_strlen($reason) < 10) {
        return ['ok' => false, 'error' => 'A reason of at least 10 characters is required to unlock finalized opening balances.'];
    }
    $batch = db()->query('SELECT * FROM opening_balance_batches WHERE id = ' . (int) $batchId)->fetch(PDO::FETCH_ASSOC);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Batch not found.'];
    }
    if ((string) $batch['status'] !== 'locked') {
        return ['ok' => false, 'error' => 'Only a locked batch can be unlocked.'];
    }
    db()->prepare("UPDATE opening_balance_batches SET status = 'finalized', locked_at = NULL WHERE id = :id")->execute(['id' => $batchId]);
    ob_write_audit((int) $batch['company_id'], $batchId, null, (int) $batch['fiscal_year_id'], 'unlock', ['status' => 'locked'], ['status' => 'finalized'], $reason, $actorId);
    return ['ok' => true];
}

/**
 * Sub-ledger reconciliation: the sum of each party's own opening (its dedicated
 * receivable / payable ledger) must equal the Trade Receivable / Payable control
 * opening. Because every party posts to its own ledger under those groups, they
 * reconcile by construction; this surfaces the figures and any mismatch.
 */
function ob_subledger_reconciliation(int $companyId, int $fiscalYearId): array
{
    $rows = ob_computed_opening_rows($companyId, $fiscalYearId);
    $openingByLedger = [];
    foreach ($rows as $r) {
        if ($r['ledger_id']) {
            $openingByLedger[(int) $r['ledger_id']] = (float) $r['opening_net'];
        }
    }
    $result = [
        'receivable' => ['control' => 0.0, 'subledger' => 0.0, 'parties' => [], 'control_ledgers' => [], 'excluded' => []],
        'payable' => ['control' => 0.0, 'subledger' => 0.0, 'parties' => [], 'control_ledgers' => [], 'excluded' => []],
    ];
    if (!table_exists('accounting_parties')) {
        return $result;
    }
    $partyByRecLedger = [];
    $partyByPayLedger = [];
    $parties = db()->prepare('SELECT id, code, name, party_type, ledger_id, payable_ledger_id FROM accounting_parties WHERE company_id = :cid');
    $parties->execute(['cid' => $companyId]);
    foreach ($parties->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $recLedger = (int) ($p['ledger_id'] ?? 0);
        $payLedger = (int) ($p['payable_ledger_id'] ?? 0);
        if ($recLedger > 0) {
            $partyByRecLedger[$recLedger] = (string) $p['name'];
        }
        if ($payLedger > 0) {
            $partyByPayLedger[$payLedger] = (string) $p['name'];
        }
        $recOpening = $recLedger && isset($openingByLedger[$recLedger]) ? $openingByLedger[$recLedger] : 0.0;
        $payOpening = $payLedger && isset($openingByLedger[$payLedger]) ? $openingByLedger[$payLedger] : 0.0;
        if (abs($recOpening) > OB_EPSILON) {
            $result['receivable']['subledger'] += $recOpening;
            $result['receivable']['parties'][] = ['code' => $p['code'], 'name' => $p['name'], 'opening' => ob_money($recOpening)];
        }
        if (abs($payOpening) > OB_EPSILON) {
            // Payables are credit balances (negative in the debit-positive
            // convention); express the subledger total credit-positive to match
            // the control total computed below.
            $result['payable']['subledger'] += -$payOpening;
            $result['payable']['parties'][] = ['code' => $p['code'], 'name' => $p['name'], 'opening' => ob_money(-$payOpening)];
        }
    }
    // Control = openings of ledgers under Trade Receivable / Payable groups.
    // A greedy substring match used to swallow OTHER "payable" groups —
    // Employee/Salary Payables and inventory utility ledgers (Purchase
    // Clearing, NRV write-down allowance) are not supplier balances, and
    // counting them made every reconciliation an unexplainable "Investigate".
    // They are now skipped (utility ledgers listed under 'excluded' so the
    // screen can show WHY), and each counted ledger is returned with its
    // linked party — an unlinked one is precisely the row to act on.
    $utilityLedgerIds = [];
    if (table_exists('inventory_ledger_mappings')) {
        $utilStmt = db()->prepare("SELECT DISTINCT ledger_id FROM inventory_ledger_mappings WHERE company_id = :cid AND purpose IN ('purchase_clearing', 'write_down_allowance')");
        $utilStmt->execute(['cid' => $companyId]);
        $utilityLedgerIds = array_map('intval', $utilStmt->fetchAll(PDO::FETCH_COLUMN));
    }
    foreach ($rows as $r) {
        $grp = strtolower((string) $r['group_name']);
        $ledgerId = (int) ($r['ledger_id'] ?? 0);
        $openingNet = (float) $r['opening_net'];
        if (str_contains($grp, 'employee') || str_contains($grp, 'salary') || str_contains($grp, 'staff') || str_contains($grp, 'payroll')) {
            continue; // employee payables are not trade balances
        }
        // Only *receivable/payable*-named groups are the trade control — the
        // generic Sundry Debtors/Creditors groups are deliberately outside the
        // per-party subledger design and must not be pulled in.
        $side = str_contains($grp, 'receivable') ? 'receivable' : (str_contains($grp, 'payable') ? 'payable' : '');
        if ($side === '') {
            continue;
        }
        $signed = $side === 'payable' ? -$openingNet : $openingNet;
        if (in_array($ledgerId, $utilityLedgerIds, true)) {
            if (abs($openingNet) > OB_EPSILON) {
                $result[$side]['excluded'][] = ['ledger_id' => $ledgerId, 'name' => (string) $r['name'], 'opening' => ob_money($signed)];
            }
            continue;
        }
        $result[$side]['control'] += $openingNet;
        if (abs($openingNet) > OB_EPSILON) {
            $linkMap = $side === 'receivable' ? $partyByRecLedger : $partyByPayLedger;
            $result[$side]['control_ledgers'][] = [
                'ledger_id' => $ledgerId,
                'name' => (string) $r['name'],
                'opening' => ob_money($signed),
                'party' => $linkMap[$ledgerId] ?? null,
            ];
        }
    }
    $result['receivable']['control'] = ob_money($result['receivable']['control']);
    $result['receivable']['subledger'] = ob_money($result['receivable']['subledger']);
    $result['payable']['control'] = ob_money(-$result['payable']['control']);
    $result['payable']['subledger'] = ob_money($result['payable']['subledger']);
    return $result;
}

/**
 * Inventory opening reconciliation for a fiscal year: each item's brought-forward
 * quantity as at the FY start (opening_qty + net movement before the start date),
 * with a value proxy, reconciled in total against the inventory-asset control GL
 * opening. Derived read-only from the perpetual store — nothing is re-posted.
 */
function ob_inventory_opening(int $companyId, int $fiscalYearId): array
{
    $out = ['items' => [], 'total_value' => 0.0, 'control_opening' => 0.0];
    if (!table_exists('inventory_items') || !table_exists('inventory_transactions')) {
        return $out;
    }
    $fy = fiscal_year_by_id($fiscalYearId);
    $fyStart = (string) ($fy['start_date'] ?? '');
    if ($fyStart === '') {
        return $out;
    }
    $stmt = db()->prepare("
        SELECT i.id, i.sku, i.name, i.unit, i.valuation_method, i.purchase_rate, i.opening_qty,
               i.opening_qty + COALESCE((SELECT SUM(t.qty_in - t.qty_out) FROM inventory_transactions t
                   WHERE t.item_id = i.id AND t.company_id = i.company_id
                     AND t.transaction_date < :fystart), 0) AS opening_balance_qty
        FROM inventory_items i WHERE i.company_id = :cid ORDER BY i.sku ASC");
    $stmt->execute(['cid' => $companyId, 'fystart' => $fyStart]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $qty = round((float) $it['opening_balance_qty'], 3);
        if (abs($qty) < 0.0005) {
            continue;
        }
        $unitCost = (float) $it['purchase_rate'];
        $value = ob_money($qty * $unitCost);
        $out['items'][] = [
            'sku' => $it['sku'], 'name' => $it['name'], 'unit' => $it['unit'],
            'valuation_method' => $it['valuation_method'], 'qty' => $qty,
            'unit_cost' => round($unitCost, 2), 'value' => $value,
        ];
        $out['total_value'] += $value;
    }
    $out['total_value'] = ob_money($out['total_value']);
    // Control = opening of ledgers mapped as inventory assets.
    if (function_exists('inv_company_valuation') || table_exists('inventory_ledger_mappings')) {
        $rows = ob_computed_opening_rows($companyId, $fiscalYearId);
        $invLedgerIds = [];
        if (table_exists('inventory_ledger_mappings')) {
            $mp = db()->prepare("SELECT DISTINCT ledger_id FROM inventory_ledger_mappings WHERE company_id = :cid AND scope='global' AND purpose IN ('inventory_asset','raw_material','wip','finished_goods','scrap_inventory')");
            $mp->execute(['cid' => $companyId]);
            foreach ($mp->fetchAll(PDO::FETCH_COLUMN) as $lid) {
                $invLedgerIds[(int) $lid] = true;
            }
        }
        foreach ($rows as $r) {
            if ($r['ledger_id'] && isset($invLedgerIds[(int) $r['ledger_id']])) {
                $out['control_opening'] += (float) $r['opening_net'];
            }
        }
        $out['control_opening'] = ob_money($out['control_opening']);
    }
    return $out;
}

/**
 * Fixed-asset opening reconciliation for a fiscal year: each asset's brought-
 * forward cost / accumulated depreciation / NBV as at the FY start (from the
 * depreciation schedule dated before the start date), reconciled in total against
 * the PPE cost + accumulated-depreciation control GL opening. Read-only.
 */
function ob_fixed_asset_opening(int $companyId, int $fiscalYearId): array
{
    $out = ['assets' => [], 'total_cost' => 0.0, 'total_accdep' => 0.0, 'total_nbv' => 0.0];
    if (!table_exists('fixed_assets')) {
        return $out;
    }
    $fy = fiscal_year_by_id($fiscalYearId);
    $fyStart = (string) ($fy['start_date'] ?? '');
    if ($fyStart === '') {
        return $out;
    }
    $hasSchedule = table_exists('asset_depreciation_schedule');
    $stmt = db()->prepare('SELECT * FROM fixed_assets WHERE company_id = :cid ORDER BY asset_class, asset_code');
    $stmt->execute(['cid' => $companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        // Cost is not per-year snapshotted; use current cost (additions/disposals
        // are dated events — a faithful as-at cost would stitch them, surfaced as
        // a reconciliation note). Accumulated depreciation as-at FY start comes
        // from the latest schedule row dated before the start.
        $cost = (float) $a['cost'];
        $accDep = (float) $a['accumulated_depreciation'];
        if ($hasSchedule) {
            $sch = db()->prepare('SELECT accumulated, carrying FROM asset_depreciation_schedule WHERE company_id = :cid AND asset_id = :aid AND period_date < :fystart ORDER BY period_date DESC, id DESC LIMIT 1');
            try {
                $sch->execute(['cid' => $companyId, 'aid' => (int) $a['id'], 'fystart' => $fyStart]);
                $r = $sch->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $accDep = (float) $r['accumulated'];
                }
            } catch (Throwable $e) {
                // schedule may not carry company_id/asset_id in older installs — fall back to master.
            }
        }
        $accImp = (float) ($a['accumulated_impairment'] ?? 0);
        $nbv = ob_money($cost - $accDep - $accImp);
        if (abs($cost) < OB_EPSILON && abs($nbv) < OB_EPSILON) {
            continue;
        }
        $out['assets'][] = [
            'code' => $a['asset_code'], 'name' => $a['name'], 'class' => $a['asset_class'],
            'location' => $a['location'] ?? '', 'department' => $a['department'] ?? '', 'custodian' => $a['custodian'] ?? '',
            'cost' => ob_money($cost), 'accumulated_depreciation' => ob_money($accDep),
            'accumulated_impairment' => ob_money($accImp), 'nbv' => $nbv,
        ];
        $out['total_cost'] += $cost;
        $out['total_accdep'] += $accDep;
        $out['total_nbv'] += $nbv;
    }
    $out['total_cost'] = ob_money($out['total_cost']);
    $out['total_accdep'] = ob_money($out['total_accdep']);
    $out['total_nbv'] = ob_money($out['total_nbv']);
    return $out;
}
