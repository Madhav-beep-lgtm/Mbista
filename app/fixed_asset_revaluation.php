<?php
declare(strict_types=1);

/**
 * Database support for the shared fixed-asset revaluation workflow.
 * The page calls this on load so existing installations upgrade safely.
 */
function fa_revaluation_repair_database(): void
{
    $pdo = db();

    $columns = [
        'revaluation_loss_balance' => "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER revaluation_reserve",
        'depreciation_base' => "DECIMAL(18,2) DEFAULT NULL AFTER carrying_amount",
        'depreciation_base_accumulated' => "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER depreciation_base",
        'revaluation_life_months' => "INT UNSIGNED DEFAULT NULL AFTER depreciation_base_accumulated",
        'last_revaluation_date' => "DATE DEFAULT NULL AFTER revaluation_life_months",
    ];

    foreach ($columns as $name => $definition) {
        if (!column_exists('fixed_assets', $name)) {
            $pdo->exec("ALTER TABLE fixed_assets ADD COLUMN `$name` $definition");
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS asset_revaluation_batches (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL,
            batch_no VARCHAR(80) NOT NULL,
            asset_class VARCHAR(40) NOT NULL,
            revaluation_date DATE NOT NULL,
            valuer_name VARCHAR(190) NOT NULL,
            valuer_reference VARCHAR(190) DEFAULT NULL,
            valuation_method VARCHAR(80) NOT NULL,
            reason TEXT NOT NULL,
            report_path VARCHAR(255) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            submitted_by INT UNSIGNED DEFAULT NULL,
            submitted_at DATETIME DEFAULT NULL,
            approved_by INT UNSIGNED DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            rejected_by INT UNSIGNED DEFAULT NULL,
            rejected_at DATETIME DEFAULT NULL,
            rejection_reason TEXT DEFAULT NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_asset_revaluation_batch_no (company_id, batch_no),
            KEY idx_asset_revaluation_batch_status (company_id, status, revaluation_date),
            KEY idx_asset_revaluation_batch_class (company_id, asset_class, revaluation_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS asset_revaluation_lines (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id INT UNSIGNED NOT NULL,
            company_id INT UNSIGNED NOT NULL,
            asset_id INT UNSIGNED NOT NULL,
            previous_carrying_value DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            new_fair_value DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            increase_decrease DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            revised_useful_life_months INT UNSIGNED NOT NULL DEFAULT 1,
            revised_residual_value DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            remarks VARCHAR(500) DEFAULT NULL,
            pnl_effect DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            oci_effect DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            voucher_id INT UNSIGNED DEFAULT NULL,
            posted_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_asset_revaluation_batch_asset (batch_id, asset_id),
            KEY idx_asset_revaluation_line_company (company_id, batch_id),
            KEY idx_asset_revaluation_line_asset (asset_id, batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function fa_revaluation_methods(): array
{
    return [
        'market_approach' => 'Market approach',
        'income_approach' => 'Income approach',
        'depreciated_replacement_cost' => 'Depreciated replacement cost',
        'independent_valuation' => 'Independent valuation report',
    ];
}

function fa_revaluation_batch(int $batchId, int $companyId): ?array
{
    if ($batchId <= 0 || $companyId <= 0) {
        return null;
    }

    $stmt = db()->prepare('
        SELECT b.*
        FROM asset_revaluation_batches b
        WHERE b.id = :id AND b.company_id = :cid
        LIMIT 1
    ');
    $stmt->execute(['id' => $batchId, 'cid' => $companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fa_revaluation_batches(int $companyId): array
{
    $stmt = db()->prepare('
        SELECT b.*,
               COUNT(l.id) AS asset_count,
               COALESCE(SUM(l.previous_carrying_value), 0) AS previous_total,
               COALESCE(SUM(l.new_fair_value), 0) AS fair_value_total,
               COALESCE(SUM(l.increase_decrease), 0) AS net_change
        FROM asset_revaluation_batches b
        LEFT JOIN asset_revaluation_lines l ON l.batch_id = b.id
        WHERE b.company_id = :cid
        GROUP BY b.id
        ORDER BY b.revaluation_date DESC, b.id DESC
    ');
    $stmt->execute(['cid' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fa_revaluation_lines(int $batchId, int $companyId): array
{
    $stmt = db()->prepare('
        SELECT l.*, fa.asset_code, fa.name AS asset_name, fa.asset_class,
               fa.status AS asset_status, fa.carrying_amount AS current_carrying,
               fa.revaluation_reserve, fa.revaluation_loss_balance,
               fa.accumulated_depreciation
        FROM asset_revaluation_lines l
        JOIN fixed_assets fa ON fa.id = l.asset_id AND fa.company_id = l.company_id
        WHERE l.batch_id = :bid AND l.company_id = :cid
        ORDER BY fa.asset_code ASC, fa.name ASC
    ');
    $stmt->execute(['bid' => $batchId, 'cid' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fa_revaluation_eligible_assets(int $companyId, string $assetClass): array
{
    $allowed = ['ppe', 'intangible', 'investment_property'];
    if (!in_array($assetClass, $allowed, true)) {
        return [];
    }

    $stmt = db()->prepare("
        SELECT *
        FROM fixed_assets
        WHERE company_id = :cid
          AND asset_class = :class
          AND status IN ('active', 'fully_depreciated')
        ORDER BY asset_code ASC, name ASC
    ");
    $stmt->execute(['cid' => $companyId, 'class' => $assetClass]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fa_store_revaluation_report(array $file, int $companyId): ?string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The valuation report upload failed.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new RuntimeException('The valuation report must be between 1 byte and 10 MB.');
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Upload a PDF, JPG or PNG valuation report.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('The uploaded valuation report is not valid.');
    }

    $allowedMimes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if ($mime !== '' && !in_array($mime, $allowedMimes, true)) {
            throw new RuntimeException('The valuation report file type is not allowed.');
        }
    }

    $relativeDirectory = 'uploads/fixed-assets/revaluation/' . $companyId . '/' . date('Y/m');
    $absoluteDirectory = dirname(__DIR__) . '/public_html/' . $relativeDirectory;
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
        throw new RuntimeException('Could not create the valuation report folder.');
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
    $absolutePath = $absoluteDirectory . '/' . $storedName;
    if (!move_uploaded_file($tmpName, $absolutePath)) {
        throw new RuntimeException('Could not save the valuation report.');
    }

    return $relativeDirectory . '/' . $storedName;
}

/**
 * Approve and post one class-wide revaluation batch.
 *
 * P&L and OCI treatment:
 * - An increase first reverses any tracked prior P&L revaluation loss.
 * - The remaining increase is credited to revaluation surplus in OCI.
 * - A decrease first uses the available revaluation surplus.
 * - Any remaining decrease is charged to revaluation loss in P&L.
 */
function fa_approve_revaluation_batch(
    int $batchId,
    int $companyId,
    int $fiscalYearId,
    int $userId
): array {
    $pdo = db();

    try {
        $pdo->beginTransaction();

        $batchStmt = $pdo->prepare('
            SELECT *
            FROM asset_revaluation_batches
            WHERE id = :id AND company_id = :cid
            FOR UPDATE
        ');
        $batchStmt->execute(['id' => $batchId, 'cid' => $companyId]);
        $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch) {
            throw new RuntimeException('Revaluation batch not found.');
        }
        if ((string) $batch['status'] !== 'submitted') {
            throw new RuntimeException('Only a submitted batch can be approved.');
        }

        $lines = fa_revaluation_lines($batchId, $companyId);
        if ($lines === []) {
            throw new RuntimeException('The batch has no assets.');
        }

        $prepared = [];
        foreach ($lines as $line) {
            $currentCarrying = round((float) $line['current_carrying'], 2);
            $previousCarrying = round((float) $line['previous_carrying_value'], 2);
            if (abs($currentCarrying - $previousCarrying) >= 0.01) {
                throw new RuntimeException(
                    'Asset ' . $line['asset_code'] .
                    ' changed after the batch was created. Create a new batch.'
                );
            }

            $newValue = round((float) $line['new_fair_value'], 2);
            $residual = round((float) $line['revised_residual_value'], 2);
            $lifeMonths = (int) $line['revised_useful_life_months'];
            if ($newValue < 0 || $residual < 0 || $residual > $newValue || $lifeMonths <= 0) {
                throw new RuntimeException('Invalid revaluation values for asset ' . $line['asset_code'] . '.');
            }

            $delta = round($newValue - $currentCarrying, 2);
            $allocation = fa_revaluation_allocation(
                $delta,
                (float) $line['revaluation_reserve'],
                (float) $line['revaluation_loss_balance']
            );

            $costLedger = null;
            $surplusLedger = null;
            $lossLedger = null;

            if (abs($delta) >= 0.005) {
                $costLedger = fa_resolve_mapping($companyId, 'ppe_cost', (int) $line['asset_id']);
                if (!$costLedger) {
                    throw new RuntimeException('Map PPE / Asset Cost for ' . $line['asset_code'] . '.');
                }
            }
            if ($allocation['oci_increase'] > 0 || $allocation['oci_decrease'] > 0) {
                $surplusLedger = fa_resolve_mapping($companyId, 'revaluation_surplus', (int) $line['asset_id']);
                if (!$surplusLedger) {
                    throw new RuntimeException('Map Revaluation Surplus (OCI) for ' . $line['asset_code'] . '.');
                }
            }
            if ($allocation['pnl_reversal'] > 0 || $allocation['pnl_loss'] > 0) {
                $lossLedger = fa_resolve_mapping($companyId, 'revaluation_loss', (int) $line['asset_id']);
                if (!$lossLedger) {
                    throw new RuntimeException('Map Revaluation Loss for ' . $line['asset_code'] . '.');
                }
            }

            $prepared[] = [
                'line' => $line,
                'delta' => $delta,
                'allocation' => $allocation,
                'cost_ledger' => $costLedger,
                'surplus_ledger' => $surplusLedger,
                'loss_ledger' => $lossLedger,
            ];
        }

        $postedVouchers = 0;
        foreach ($prepared as $item) {
            $line = $item['line'];
            $delta = (float) $item['delta'];
            $allocation = $item['allocation'];
            $voucherId = 0;

            if (abs($delta) >= 0.005) {
                $entries = [];
                if ($delta > 0) {
                    $entries[] = [
                        'ledger_id' => (int) $item['cost_ledger']['id'],
                        'entry_type' => 'debit',
                        'amount' => $delta,
                    ];
                    if ($allocation['pnl_reversal'] > 0) {
                        $entries[] = [
                            'ledger_id' => (int) $item['loss_ledger']['id'],
                            'entry_type' => 'credit',
                            'amount' => $allocation['pnl_reversal'],
                        ];
                    }
                    if ($allocation['oci_increase'] > 0) {
                        $entries[] = [
                            'ledger_id' => (int) $item['surplus_ledger']['id'],
                            'entry_type' => 'credit',
                            'amount' => $allocation['oci_increase'],
                        ];
                    }
                } else {
                    if ($allocation['oci_decrease'] > 0) {
                        $entries[] = [
                            'ledger_id' => (int) $item['surplus_ledger']['id'],
                            'entry_type' => 'debit',
                            'amount' => $allocation['oci_decrease'],
                        ];
                    }
                    if ($allocation['pnl_loss'] > 0) {
                        $entries[] = [
                            'ledger_id' => (int) $item['loss_ledger']['id'],
                            'entry_type' => 'debit',
                            'amount' => $allocation['pnl_loss'],
                        ];
                    }
                    $entries[] = [
                        'ledger_id' => (int) $item['cost_ledger']['id'],
                        'entry_type' => 'credit',
                        'amount' => abs($delta),
                    ];
                }

                $eventStmt = $pdo->prepare('
                    INSERT INTO asset_impairments
                        (company_id, asset_id, test_date, kind, carrying_amount,
                         recoverable_amount, impairment_loss, evidence, approved_by, created_by)
                    VALUES
                        (:cid, :aid, :d, \'revaluation\', :carry,
                         :recoverable, :loss, :evidence, :approved_by, :created_by)
                ');
                $eventStmt->execute([
                    'cid' => $companyId,
                    'aid' => (int) $line['asset_id'],
                    'd' => (string) $batch['revaluation_date'],
                    'carry' => (float) $line['previous_carrying_value'],
                    'recoverable' => (float) $line['new_fair_value'],
                    'loss' => $delta < 0 ? abs($delta) : 0,
                    'evidence' => substr('Batch ' . $batch['batch_no'] . ': ' . (string) ($line['remarks'] ?? ''), 0, 250),
                    'approved_by' => $userId,
                    'created_by' => (int) $batch['created_by'],
                ]);
                $eventId = (int) $pdo->lastInsertId();

                $voucherId = create_voucher_with_entries([
                    'company_id' => $companyId,
                    'fiscal_year_id' => $fiscalYearId > 0 ? $fiscalYearId : null,
                    'voucher_no' => 'FA-REV-' . $line['asset_code'] . '-' . $eventId,
                    'voucher_type' => 'journal',
                    'voucher_date' => (string) $batch['revaluation_date'],
                    'source_type' => 'asset_revaluation_batch_line',
                    'source_id' => (int) $line['id'],
                    'total_amount' => abs($delta),
                    'narration' => 'Revaluation batch ' . $batch['batch_no'] .
                        ' for ' . $line['asset_name'] . ' (' . $line['asset_code'] . ').',
                    'status' => 'posted',
                    'posted_by' => $userId,
                ], $entries);

                $pdo->prepare('UPDATE asset_impairments SET voucher_id = :vid WHERE id = :id')
                    ->execute(['vid' => $voucherId ?: null, 'id' => $eventId]);
                $postedVouchers++;
            }

            $newReserve = max(
                0.0,
                round(
                    (float) $line['revaluation_reserve']
                    + $allocation['oci_increase']
                    - $allocation['oci_decrease'],
                    2
                )
            );
            $newLossBalance = max(
                0.0,
                round(
                    (float) $line['revaluation_loss_balance']
                    + $allocation['pnl_loss']
                    - $allocation['pnl_reversal'],
                    2
                )
            );

            $assetUpdate = $pdo->prepare('
                UPDATE fixed_assets
                SET carrying_amount = :carrying,
                    residual_value = :residual,
                    useful_life_months = :life,
                    depreciation_base = :dep_base,
                    depreciation_base_accumulated = accumulated_depreciation,
                    revaluation_life_months = :life2,
                    last_revaluation_date = :revaluation_date,
                    revaluation_reserve = :reserve,
                    revaluation_loss_balance = :loss_balance,
                    status = CASE
                        WHEN status = \'fully_depreciated\' AND :carrying2 > :residual2 THEN \'active\'
                        ELSE status
                    END
                WHERE id = :id AND company_id = :cid
            ');
            $assetUpdate->execute([
                'carrying' => (float) $line['new_fair_value'],
                'residual' => (float) $line['revised_residual_value'],
                'life' => (int) $line['revised_useful_life_months'],
                'dep_base' => (float) $line['new_fair_value'],
                'life2' => (int) $line['revised_useful_life_months'],
                'revaluation_date' => (string) $batch['revaluation_date'],
                'reserve' => $newReserve,
                'loss_balance' => $newLossBalance,
                'carrying2' => (float) $line['new_fair_value'],
                'residual2' => (float) $line['revised_residual_value'],
                'id' => (int) $line['asset_id'],
                'cid' => $companyId,
            ]);

            $pdo->prepare('
                UPDATE asset_revaluation_lines
                SET increase_decrease = :change,
                    pnl_effect = :pnl,
                    oci_effect = :oci,
                    voucher_id = :voucher_id,
                    posted_at = NOW()
                WHERE id = :id AND company_id = :cid
            ')->execute([
                'change' => $delta,
                'pnl' => round($allocation['pnl_reversal'] - $allocation['pnl_loss'], 2),
                'oci' => round($allocation['oci_increase'] - $allocation['oci_decrease'], 2),
                'voucher_id' => $voucherId ?: null,
                'id' => (int) $line['id'],
                'cid' => $companyId,
            ]);
        }

        $pdo->prepare('
            UPDATE asset_revaluation_batches
            SET status = \'approved\',
                approved_by = :uid,
                approved_at = NOW(),
                rejection_reason = NULL
            WHERE id = :id AND company_id = :cid
        ')->execute(['uid' => $userId, 'id' => $batchId, 'cid' => $companyId]);

        $pdo->commit();

        security_event(
            'asset_revaluation_batch_approved',
            'success',
            'Approved revaluation batch #' . $batchId . '.',
            $companyId,
            $userId
        );
        log_activity(
            'asset_revaluation_batch',
            $batchId,
            'approved',
            'Approved and posted ' . count($prepared) . ' asset revaluations.',
            $userId
        );

        return [
            'assets' => count($prepared),
            'vouchers' => $postedVouchers,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
