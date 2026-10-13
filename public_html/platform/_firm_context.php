<?php
declare(strict_types=1);

/**
 * Put the session in the FIRM's own company before a platform page runs.
 *
 * The Work Portal and Report Emails both need a company: a client task and a
 * scheduled report belong to the practice that owns them. Left to inherit
 * whatever was open last, clicking "Report Emails" from the platform sidebar
 * landed in a client's ledger and announced "Current portal: Bardia Resort" —
 * the platform admin had entered somebody's books by asking for a list of
 * scheduled emails.
 *
 * It does NOT switch the company silently. The company PIN is what makes
 * entering a set of books deliberate, and an earlier draft of this file set
 * the context behind that gate — require_company_context() refused it, and it
 * was right to. If the firm is not already unlocked the user is sent to the
 * PIN page and returned here afterwards.
 *
 * Define $platformReturnTo before requiring this.
 */

$firmCompany = company_by_code('MBAACA');
if (!$firmCompany) {
    flash('error', 'The firm company is not set up.');
    redirect('platform/index.php');
}

$firmCompanyId = (int) $firmCompany['id'];

if ((int) current_company_id() !== $firmCompanyId && !activate_company_context($firmCompanyId, true)) {
    flash('error', 'No active fiscal year is available for the firm company.');
    redirect('platform/index.php');
}
