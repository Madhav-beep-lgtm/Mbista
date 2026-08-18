<?php
declare(strict_types=1);

/**
 * Access Control Hardening Layer
 *
 * Enforces tenant isolation across the application
 * All database queries MUST use these helpers or check authorized_company_ids()
 *
 * CRITICAL: Any query without company_id filtering is a security vulnerability
 */

/**
 * Verify user can access a company
 *
 * Usage: if (!can_access_company(123)) { deny_access(); }
 */
function can_access_company(int $companyId, ?array $user = null): bool
{
    if ($companyId <= 0) {
        return false;
    }

    $authorized = authorized_company_ids($user);

    return in_array($companyId, $authorized, true);
}

/**
 * Get current session's company with validation
 *
 * Usage: $cid = get_session_company_safe();
 */
function get_session_company_safe(): int
{
    $companyId = (int) get_session_company();

    if (!can_access_company($companyId)) {
        http_response_code(403);
        die(json_encode(['error' => 'Company access denied']));
    }

    return $companyId;
}

/**
 * Verify voucher belongs to authorized company
 *
 * Usage: if (!voucher_is_accessible(456)) { deny(); }
 */
function voucher_is_accessible(int $voucherId, ?int $companyId = null): bool
{
    $companyId = $companyId ?? (int) get_session_company();

    if (!can_access_company($companyId)) {
        return false;
    }

    $stmt = db()->prepare(
        "SELECT id FROM accounting_vouchers
         WHERE id = :vid AND company_id = :cid LIMIT 1"
    );

    return (bool) $stmt->execute(['vid' => $voucherId, 'cid' => $companyId])
                        ->fetch(PDO::FETCH_ASSOC);
}

/**
 * Verify party belongs to authorized company
 */
function party_is_accessible(int $partyId, ?int $companyId = null): bool
{
    $companyId = $companyId ?? (int) get_session_company();

    if (!can_access_company($companyId)) {
        return false;
    }

    $stmt = db()->prepare(
        "SELECT id FROM accounting_parties
         WHERE id = :pid AND company_id = :cid LIMIT 1"
    );

    return (bool) $stmt->execute(['pid' => $partyId, 'cid' => $companyId])
                        ->fetch(PDO::FETCH_ASSOC);
}

/**
 * Verify jewelry invoice/order belongs to authorized company
 */
function jewellery_order_is_accessible(int $orderId, ?int $companyId = null): bool
{
    $companyId = $companyId ?? (int) get_session_company();

    if (!can_access_company($companyId)) {
        return false;
    }

    $stmt = db()->prepare(
        "SELECT id FROM jewellery_orders
         WHERE id = :oid AND company_id = :cid LIMIT 1"
    );

    return (bool) $stmt->execute(['oid' => $orderId, 'cid' => $companyId])
                        ->fetch(PDO::FETCH_ASSOC);
}

/**
 * Verify invoice payment belongs to authorized company
 */
function invoice_payment_is_accessible(int $paymentId, ?int $companyId = null): bool
{
    $companyId = $companyId ?? (int) get_session_company();

    if (!can_access_company($companyId)) {
        return false;
    }

    $stmt = db()->prepare(
        "SELECT id FROM invoice_payments
         WHERE id = :pid AND company_id = :cid LIMIT 1"
    );

    return (bool) $stmt->execute(['pid' => $paymentId, 'cid' => $companyId])
                        ->fetch(PDO::FETCH_ASSOC);
}

/**
 * Enforce company_id in all query results
 *
 * CRITICAL: Call this on any user-requested data
 * Usage: $safe = enforce_company_id($data, 'company_id');
 */
function enforce_company_id(array $data, string $keyName = 'company_id', ?int $expectedCompanyId = null): array
{
    $expectedCompanyId = $expectedCompanyId ?? (int) get_session_company();

    if (!can_access_company($expectedCompanyId)) {
        throw new RuntimeException('Company access denied');
    }

    $safe = [];
    foreach ($data as $row) {
        if (!is_array($row)) {
            $safe[] = $row;
            continue;
        }

        $rowCompanyId = (int) ($row[$keyName] ?? 0);
        if ($rowCompanyId !== $expectedCompanyId) {
            // SECURITY: Silently filter cross-tenant data
            // Do NOT throw exception (would reveal data exists)
            continue;
        }

        $safe[] = $row;
    }

    return $safe;
}

/**
 * Audit: Log access attempts
 *
 * Usage: audit_access('view_order', $orderId, $companyId, true);
 */
function audit_access(
    string $action,
    int $resourceId,
    int $companyId,
    bool $success,
    ?string $reason = null
): void {
    $user = current_user();
    $userId = $user['id'] ?? null;
    $sessionCompanyId = (int) get_session_company();

    $allowed = can_access_company($companyId);

    // Only log if there's a mismatch (suspicious)
    if (!$allowed || $companyId !== $sessionCompanyId) {
        error_log(sprintf(
            "[SECURITY AUDIT] User %d attempted %s on resource %d (company %d, session company %d) - %s | Reason: %s",
            $userId,
            $action,
            $resourceId,
            $companyId,
            $sessionCompanyId,
            $success ? 'SUCCESS' : 'DENIED',
            $reason ?? 'N/A'
        ));
    }
}

/**
 * CRITICAL QUERIES THAT MUST CHECK AUTHORIZATION
 *
 * These are the 10 most dangerous queries in the system.
 * Each must be updated to check authorized_company_ids():
 *
 * 1. SELECT * FROM accounting_vouchers - used in exports, reports
 * 2. SELECT * FROM accounting_parties - used in exports, statements
 * 3. SELECT * FROM invoice_payments - payment processing
 * 4. SELECT * FROM jewellery_orders - order processing
 * 5. SELECT * FROM inventory_items - inventory reports
 * 6. SELECT * FROM accounting_entries - GL exports
 * 7. SELECT * FROM jewellery_order_assignments - workshop output
 * 8. SELECT * FROM company_memberships - permission checks
 * 9. SELECT * FROM cash_bank_transactions - banking reports
 * 10. SELECT * FROM stock_receipts - inventory movement
 *
 * HARDENING STEPS COMPLETED:
 * ✅ Created voucher_is_accessible()
 * ✅ Created party_is_accessible()
 * ✅ Created invoice_payment_is_accessible()
 * ✅ Created jewellery_order_is_accessible()
 * ✅ Created enforce_company_id() for result sets
 * ✅ Created audit_access() for suspicious access
 *
 * TODO HARDENING TASKS:
 * [ ] Add company_id check to all export endpoints
 * [ ] Add company_id check to all report queries
 * [ ] Add company_id check to all API endpoints
 * [ ] Add company_id check to all form saves
 * [ ] Create migration to add FK constraints with ON DELETE RESTRICT
 * [ ] Create migration to add UNIQUE(company_id, entity_id) where needed
 * [ ] Audit all DELETE queries for cascade risks
 * [ ] Test cross-tenant access prevention in test suite
 */

/**
 * Hard check: Fail fast if company access denied
 *
 * Usage at start of sensitive operations:
 * require_company_access(get_session_company());
 */
function require_company_access(int $companyId): void
{
    if (!can_access_company($companyId)) {
        http_response_code(403);
        die(json_encode([
            'error' => 'Access denied',
            'message' => 'You do not have permission to access this company'
        ]));
    }
}
