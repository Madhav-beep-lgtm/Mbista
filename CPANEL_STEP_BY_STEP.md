# cPanel Deployment: Step-by-Step Operator Runbook

This runbook is written for this repository structure.

## 1) Collect required values first

- Domain: example `https://yourdomain.com`
- cPanel DB host: usually `localhost`
- cPanel DB name: usually `cpaneluser_dbname`
- cPanel DB user: usually `cpaneluser_dbuser`
- cPanel DB password

## 2) Prepare files locally

1. Ensure these folders are present:
   - `public_html/`
   - `app/`
   - `database/`
2. Keep `.env.example` as template.
3. Optional preflight check:

```bash
php deploy_preflight.php
```

## 3) Create database in cPanel

1. Open cPanel -> MySQL Databases.
2. Create database.
3. Create database user.
4. Add user to database with all privileges.

## 4) Upload files to server

1. Open cPanel -> File Manager.
2. Upload content of local `public_html/` into server `public_html/`.
3. Upload `app/` and `database/` outside public web root (recommended), same level as server `public_html/`.
4. Verify these files exist on server:
   - `public_html/.htaccess`
   - `public_html/uploads/.htaccess`
5. If you want to force `https://www.mbca.com.np`, uncomment the canonical redirect block in `public_html/.htaccess` only after DNS and SSL are working.

## 5) Create production .env

Create `.env` in project root (same level as `app/` and `database/`) and set:

```env
APP_ENV=production
APP_NAME="M.B Group"
APP_URL="https://yourdomain.com"

DB_HOST=localhost
DB_NAME=cpaneluser_dbname
DB_USER=cpaneluser_dbuser
DB_PASS=strong_password
DB_CHARSET=utf8mb4
```

## 6) Import database

### Fresh install

1. Open phpMyAdmin.
2. Select your DB (the one you created in step 3; do not run `CREATE DATABASE`, cPanel DB users usually lack that privilege).
3. Import `database/schema.sql`.
   - `database/schema.sql`, `database/schema_cpanel.sql`, and `database/schema_cpanel_fkfix.sql` are identical complete fresh-install schemas.
   - The schema is cPanel-safe and local-safe because it does not include `CREATE DATABASE` or `USE`; create/select the database first, then import it.

### Existing DB upgrade

Run all migration files in order:

1. `database/migrations/002_add_payment_fields.sql`
2. `database/migrations/003_contacts_workflow_enhancements.sql`
3. `database/migrations/004_add_password_reset_tokens.sql`
4. `database/migrations/005_add_company_fiscal_staff_accounting.sql`
5. `database/migrations/006_add_admin_work_portal.sql`
6. `database/migrations/007_company_hierarchy_alignment.sql`
7. `database/migrations/008_company_portal_scoping.sql`
8. `database/migrations/009_scope_orders_contacts_to_company.sql`
9. `database/migrations/010_add_invoice_tax_support.sql`
10. `database/migrations/011_superadmin_altiora_workflow.sql`
11. `database/migrations/012_accounting_consolidation_support.sql`
12. `database/migrations/013_chart_of_accounts_hierarchy.sql`
13. `database/migrations/014_document_requests_and_library.sql`
14. `database/migrations/015_compliance_calendar.sql`
15. `database/migrations/016_messaging_and_support_tickets.sql`
16. `database/migrations/017_attendance_leave_timesheets.sql`
17. `database/migrations/018_invoice_request_discount_receipts.sql`
18. `database/migrations/019_client_payment_response_and_task_assignment.sql`
19. `database/migrations/020_staff_profiles_and_kyc.sql`

## 7) First login

Fresh imports create these starter accounts:

| Role | Login URL | Email | Password |
| --- | --- | --- | --- |
| Admin | `https://yourdomain.com/login.php` | `admin@mbista.local` | `AdminPassword123!` |
| Client | `https://yourdomain.com/login.php` | `excelbusinessandtax@gmail.com` | `Temp@9472` |
| Test customer | `https://yourdomain.com/login.php` | `testcustomer@example.com` | `TestPassword123!` |

Login with the admin account first, then immediately change or remove all default passwords before using the app with real client data.

## 8) Security hardening after setup

1. Change or remove all default starter account passwords.
2. Remove `public_html/setup.php` if it exists on your server, or protect it by IP/password.
3. File permissions:
   - Directories: `755`
   - Files: `644`
4. Ensure uploads remain non-executable through `public_html/uploads/.htaccess`.

## 9) Production smoke test

- Public pages load: `/`, `/login.php`, `/packages.php`
- Admin signs in at the single login page: `/login.php` (the old `/admin/login.php` must 301 here)
- Invoices module works: `/admin/invoice.php`
- Receipts module works: `/admin/receipts.php`
- Receipt print/PDF works from receipts page
- Ticket workflow pages load: `/admin/tickets.php`, `/dashboard.php`

## 10) Rollback readiness

- Keep full DB backup before migration.
- Keep previous file backup zip.
- If issue occurs, restore files and DB backup, then re-run only verified migrations.

## 10) Scheduled report emails (optional)

1. Create an email account in cPanel -> Email Accounts (for example reports@yourdomain.com).
2. Add its SMTP settings to `.env` (see the MAIL_* block in `.env.example`).
   Most cPanel hosts use `MAIL_HOST=mail.yourdomain.com`, `MAIL_PORT=587`, `MAIL_ENCRYPTION=tls`.
3. Add a cron job in cPanel -> Cron Jobs, running once daily:

```
0 7 * * * /usr/local/bin/php /home/USERNAME/database/run_report_schedules.php
```

4. Create schedules from Admin -> Reports -> Schedule Report.
   Until SMTP is configured, deliveries are written to `storage/mail/` so you can verify the output.
