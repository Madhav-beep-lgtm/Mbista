-- 107: Unify work-portal clients, sales parties and party ledgers.
--
-- accounting_parties is the canonical party master. A client profile may link
-- to only one party inside a company, and task invoices must retain that
-- canonical accounting_parties.id instead of treating client_profiles.id as a
-- party id.
--
-- Existing financial parties are never deleted or merged here. If historical
-- rows contain duplicate client links, the oldest party keeps the link and the
-- others become visibly unlinked for controlled review.

-- Link parties and client profiles only where each normalized name is unique
-- inside the company. Ambiguous names are deliberately left untouched.
UPDATE accounting_parties ap
INNER JOIN (
    SELECT up.company_id, up.party_id, uc.client_profile_id
    FROM (
        SELECT company_id, LOWER(TRIM(name)) AS normalized_name, MIN(id) AS party_id
        FROM accounting_parties
        WHERE TRIM(name) <> ''
        GROUP BY company_id, LOWER(TRIM(name))
        HAVING COUNT(*) = 1
    ) up
    INNER JOIN (
        SELECT company_id, LOWER(TRIM(organization_name)) AS normalized_name,
               MIN(id) AS client_profile_id
        FROM client_profiles
        WHERE TRIM(organization_name) <> ''
        GROUP BY company_id, LOWER(TRIM(organization_name))
        HAVING COUNT(*) = 1
    ) uc
      ON uc.company_id = up.company_id
     AND uc.normalized_name = up.normalized_name
) matched
  ON matched.company_id = ap.company_id
 AND matched.party_id = ap.id
LEFT JOIN accounting_parties already_linked
  ON already_linked.company_id = ap.company_id
 AND already_linked.client_profile_id = matched.client_profile_id
 AND already_linked.id <> ap.id
SET ap.client_profile_id = matched.client_profile_id
WHERE ap.client_profile_id IS NULL
  AND already_linked.id IS NULL;

-- Preserve every financial party, but keep only the oldest party linked when
-- legacy data has linked one portal client to several parties.
UPDATE accounting_parties ap
INNER JOIN (
    SELECT company_id, client_profile_id, MIN(id) AS keep_party_id
    FROM accounting_parties
    WHERE client_profile_id IS NOT NULL
    GROUP BY company_id, client_profile_id
    HAVING COUNT(*) > 1
) duplicate_link
  ON duplicate_link.company_id = ap.company_id
 AND duplicate_link.client_profile_id = ap.client_profile_id
SET ap.client_profile_id = NULL
WHERE ap.id <> duplicate_link.keep_party_id;

-- Make existing task invoices retain the canonical Party Master identifier.
UPDATE task_invoices ti
INNER JOIN client_tasks ct
        ON ct.id = ti.task_id
INNER JOIN accounting_parties ap
        ON ap.company_id = ti.company_id
       AND ap.client_profile_id = ct.client_id
SET ti.party_id = ap.id
WHERE ti.party_id IS NULL;

ALTER TABLE accounting_parties
    ADD UNIQUE INDEX IF NOT EXISTS uniq_accounting_parties_company_client
        (company_id, client_profile_id);
