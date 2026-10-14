<?php
declare(strict_types=1);

function is_client_mirror_voucher(array $voucher): bool
{
    return str_starts_with((string)($voucher['source_type'] ?? ''),'client_mirror_')
        || ($voucher['source_type'] ?? '') === 'task_advance_mirror';
}

/** The client, not the issuing firm's approval screen, decides mirrored entries. */
function client_mirror_admin_approval_blocker(array $voucher): ?string
{
    return is_client_mirror_voucher($voucher)
        ? 'This entry requires the client owner or approver to agree from their approval queue.'
        : null;
}

/** Owner/approver capability and company are checked again, even for direct calls. */
function client_mirror_decide(int $id,int $companyId,int $userId,string $decision): void
{
    if (!client_portal_capability('approve')) throw new RuntimeException('Client approval permission required.');
    $profile = client_profile_for_user($userId);
    if ($companyId <= 0 || (int)($profile['books_company_id'] ?? 0) !== $companyId) throw new RuntimeException('Company access denied.');
    if (!in_array($decision,['approve','reject'],true)) throw new RuntimeException('Invalid decision.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $companyLock = $pdo->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE');
        $companyLock->execute([$companyId]);
        $query = $pdo->prepare("SELECT * FROM vouchers WHERE id = ? AND company_id = ? AND requires_client_approval = 1 AND approval_state = 'pending_approval' AND status = 'draft' FOR UPDATE");
        $query->execute([$id,$companyId]);
        $voucher = $query->fetch();
        if (!$voucher) throw new RuntimeException('That entry is no longer awaiting approval.');
        if ($decision === 'approve') {
            $fy = fiscal_year_by_id((int)$voucher['fiscal_year_id']);
            if (!$fy || (int)$fy['company_id'] !== $companyId) throw new RuntimeException('Fiscal year unavailable.');
            $blocker = fiscal_year_posting_blocker($fy,(string)$voucher['voucher_date']);
            if ($blocker !== null) throw new RuntimeException($blocker);
            if (function_exists('is_period_locked') && is_period_locked($companyId,(int)$voucher['fiscal_year_id'],(string)$voucher['voucher_date'])) throw new RuntimeException('Accounting period is locked.');
            $lines = $pdo->prepare('SELECT e.entry_type,e.amount,l.company_id FROM voucher_entries e JOIN ledgers l ON l.id = e.ledger_id WHERE e.voucher_id = ?');
            $lines->execute([$id]);
            $balance = 0; $count = 0;
            foreach ($lines->fetchAll() as $line) {
                if ((int)$line['company_id'] !== $companyId || !in_array($line['entry_type'],['debit','credit'],true) || (float)$line['amount'] <= 0) throw new RuntimeException('Invalid journal line.');
                $balance += ($line['entry_type'] === 'debit' ? 1 : -1) * (int)round((float)$line['amount']*100);
                $count++;
            }
            if ($count < 2 || $balance !== 0) throw new RuntimeException('The journal must balance before posting.');
        }
        $pdo->prepare('UPDATE vouchers SET status = ?, approval_state = ?, client_approved_by = ?, client_approved_at = CURRENT_TIMESTAMP, approved_by = ?, approved_at = CURRENT_TIMESTAMP, posted_by = ?, posted_at = ? WHERE id = ? AND company_id = ?')
            ->execute([$decision === 'approve' ? 'posted' : 'cancelled',$decision === 'approve' ? 'approved' : 'rejected',$userId,$userId,$decision === 'approve' ? $userId : null,$decision === 'approve' ? date('Y-m-d H:i:s') : null,$id,$companyId]);
        if ($decision === 'approve' && function_exists('voucher_billing_finalize')) voucher_billing_finalize($id);
        if ($decision === 'approve' && function_exists('business_voucher_finalize')) business_voucher_finalize($id,$companyId,$userId);
        $pdo->commit();
        if ($decision === 'approve' && function_exists('voucher_billing_notify')) voucher_billing_notify($id);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
