# Deployment Dry-Run Checklist

Date: 2026-07-02
Environment: Local pre-cPanel packaging validation

## Files package
- [x] Backup SQL created
- [x] Deployment zip created
- [x] `public_html/` included in zip
- [x] `app/` included in zip
- [x] `database/` included in zip
- [x] `.env.example` included in zip

## Database package
- [x] SQL dump is non-empty
- [x] Core tables present in dump
: `users`, `plans`, `orders`, `contacts`, `settings`
- [x] Extended tables present in dump
: `activity_logs`, `password_resets`, `companies`, `fiscal_years`, `ledgers`, `vouchers`, `voucher_entries`

## Runtime checks before upload
- [x] PHP lint pass
- [x] Protected routes redirect when unauthenticated
- [x] Staff/admin portal checks pass
- [x] Accounting context checks pass

## cPanel execution readiness
- [x] README deployment steps updated
- [x] Migration list includes `005_add_company_fiscal_staff_accounting.sql`
- [x] Upload hardening files present (`public_html/.htaccess`, `public_html/uploads/.htaccess`)

## Generated deployment artifacts
- SQL backup: `backups/db_backup_20260702_141603.sql`
- Deployment bundle: `backups/cpanel_deploy_bundle_20260702_141603.zip`
