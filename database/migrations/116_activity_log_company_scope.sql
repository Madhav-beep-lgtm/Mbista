-- Tenant-safe activity history for client-owned accounting books.
ALTER TABLE activity_logs
    ADD COLUMN IF NOT EXISTS company_id INT UNSIGNED NULL AFTER id,
    ADD INDEX IF NOT EXISTS idx_activity_company_created (company_id, created_at);
