# M.Bista Altiora Complete Hosting

A cPanel-friendly PHP and MySQL starter for a hosting-package business site. It includes a public storefront, package/order flow, contact form, customer dashboard, and a lightweight admin panel.

## What is included

- Public landing page with hosting packages
- Order request form saved to MySQL
- Contact form saved to MySQL
- Customer login and dashboard
- Admin login with package, order, contact, user, and settings management
- Ready-to-upload `public_html` folder for cPanel
- MySQL schema and starter seed data
- Clean shared-hosting deployment flow with first-run setup
- Payment-ready order tracking with manual or gateway-style status fields
- Printable invoice page with hosted checkout links for Stripe or PayPal
- Password reset request and secure reset token flow
- Company portal and fiscal-year context selection
- Staff portal and accounting auto-posting workflow

## Project layout

- `app/` application bootstrap, helpers, and shared views
- `database/schema.sql` complete database tables and starter rows for fresh installs
- `database/migrations/` incremental SQL updates for existing installs
- `docs/analytics-dashboard-structure.md` admin analytics dashboard architecture and extension notes
- `public_html/` web root for cPanel hosting

## Deploy on cPanel

1. Create a MySQL database and database user in cPanel.
2. Select that database in phpMyAdmin, then import `database/schema.sql`.
   - The schema file is cPanel-safe and local-safe: it does not run `CREATE DATABASE` or `USE`.
   - `database/schema.sql`, `database/schema_cpanel.sql`, and `database/schema_cpanel_fkfix.sql` are kept identical so any of them creates the same fresh database structure.
3. If you already imported the schema before this update, run all required migrations in order:
	- `database/migrations/002_add_payment_fields.sql`
	- `database/migrations/003_contacts_workflow_enhancements.sql`
	- `database/migrations/004_add_password_reset_tokens.sql`
	- `database/migrations/005_add_company_fiscal_staff_accounting.sql`
	- `database/migrations/006_add_admin_work_portal.sql`
	- `database/migrations/007_company_hierarchy_alignment.sql`
	- `database/migrations/008_company_portal_scoping.sql`
	- `database/migrations/009_scope_orders_contacts_to_company.sql`
	- `database/migrations/010_add_invoice_tax_support.sql`
	- `database/migrations/011_superadmin_altiora_workflow.sql`
	- `database/migrations/012_accounting_consolidation_support.sql`
	- `database/migrations/013_client_management_enhancement.sql`
	- `database/migrations/013_chart_of_accounts_hierarchy.sql`
	- `database/migrations/014_document_requests_and_library.sql`
	- `database/migrations/015_compliance_calendar.sql`
	- `database/migrations/016_messaging_and_support_tickets.sql`
	- `database/migrations/017_attendance_leave_timesheets.sql`
	- `database/migrations/018_invoice_request_discount_receipts.sql`
	- `database/migrations/019_client_payment_response_and_task_assignment.sql`
	- `database/migrations/020_staff_profiles_and_kyc.sql`
	- `database/migrations/021_accounting_parties_inventory.sql`
	- `database/migrations/022_hierarchical_groups_and_ledger_mappings.sql`
	- `database/migrations/023_accounting_dashboard_preferences.sql`
4. Upload the contents of `public_html/` to your hosting web root.
5. Keep the `app/` and `database/` folders outside the public web root if possible.
6. Copy `.env.example` to `.env` and update production values.
7. Update the database credentials in `.env` (or server environment variables) and set `APP_URL` to your live domain.
8. After import, log in with the default admin account below and immediately change the password from the admin Users screen.
9. Review Packages, Orders, Contacts, Users, and Settings.
10. Open an order invoice to confirm the manual payment block or hosted checkout link appears correctly.
11. Confirm `public_html/uploads/.htaccess` exists and upload directories are not executable.

## Default login accounts

Fresh imports of `database/schema.sql`, `database/schema_cpanel.sql`, or `database/schema_cpanel_fkfix.sql` create these starter accounts:

| Role | Login URL | Email | Password |
| --- | --- | --- | --- |
| Admin | `admin/login.php` | `admin@mbista.local` | `AdminPassword123!` |
| Client | `login.php` | `excelbusinessandtax@gmail.com` | `Temp@9472` |
| Test customer | `login.php` | `testcustomer@example.com` | `TestPassword123!` |

Change or remove these default accounts before using the app with real client data.

## Migration Instructions

Use migration files when the database already exists and you want to upgrade without rebuilding.

1. Open phpMyAdmin, the MySQL terminal, or your preferred database client.
2. Select the existing application database.
3. Run all migrations in sequence:
	- `database/migrations/002_add_payment_fields.sql`
	- `database/migrations/003_contacts_workflow_enhancements.sql`
	- `database/migrations/004_add_password_reset_tokens.sql`
	- `database/migrations/005_add_company_fiscal_staff_accounting.sql`
	- `database/migrations/006_add_admin_work_portal.sql`
	- `database/migrations/007_company_hierarchy_alignment.sql`
	- `database/migrations/008_company_portal_scoping.sql`
	- `database/migrations/009_scope_orders_contacts_to_company.sql`
	- `database/migrations/010_add_invoice_tax_support.sql`
	- `database/migrations/011_superadmin_altiora_workflow.sql`
	- `database/migrations/012_accounting_consolidation_support.sql`
	- `database/migrations/013_client_management_enhancement.sql`
	- `database/migrations/013_chart_of_accounts_hierarchy.sql`
	- `database/migrations/014_document_requests_and_library.sql`
	- `database/migrations/015_compliance_calendar.sql`
	- `database/migrations/016_messaging_and_support_tickets.sql`
	- `database/migrations/017_attendance_leave_timesheets.sql`
	- `database/migrations/018_invoice_request_discount_receipts.sql`
	- `database/migrations/019_client_payment_response_and_task_assignment.sql`
	- `database/migrations/020_staff_profiles_and_kyc.sql`
	- `database/migrations/021_accounting_parties_inventory.sql`
	- `database/migrations/022_hierarchical_groups_and_ledger_mappings.sql`
	- `database/migrations/023_accounting_dashboard_preferences.sql`
4. Refresh the admin Orders page and confirm payment fields appear.
5. Open admin Contacts and confirm assignment, priority, and activity logs load.
6. Open admin Users and confirm workflow actions + activity logs load.
7. Open `forgot-password.php` and confirm token request flow stores records in `password_resets`.
8. Open `portal.php` and select company + fiscal year context.
9. Open staff/accounting routes and verify vouchers are posted when orders are marked paid.

If you are starting from a blank database, importing `database/schema.sql` is enough because it includes the latest structures. Create/select the database first, then import the file.

## Run Locally

You can run the app locally in either of these ways:

### Option 1: Plain PHP

If PHP is installed and available on your PATH:

1. Open a terminal in the project root.
2. Start the built-in server from the public web root:

```bash
php -S localhost:8000 -t public_html
```

3. Open `http://localhost:8000` in your browser.

### Option 2: XAMPP

If you use XAMPP on Windows:

1. Install XAMPP and start Apache and MySQL from the control panel.
2. Copy the project into your XAMPP `htdocs` folder or set the virtual host document root to `public_html`.
3. Create/select a blank database, then import `database/schema.sql` into MySQL from phpMyAdmin.
4. Update the database credentials in `app/config.php` if needed.
5. Open the site in your browser, then log in with the default admin account and change the password.

## Validation

After deployment or migration, confirm the app with this checklist:

1. Load the homepage and make sure the package list appears.
2. Log in with the default admin account, then change its password.
3. Create a test order and confirm it shows in the dashboard and admin Orders screen.
4. Submit a contact form and confirm it appears in admin Contacts.
5. Open the admin Orders page and check that payment method and payment status fields are visible.
6. Open the admin Settings page and confirm the payment mode and bank details can be edited.
7. Open an invoice page and confirm the amount, payment details, and hosted checkout button or manual bank details render correctly.
8. If PHP is installed locally, run syntax checks before uploading:

```bash
php -l app/bootstrap.php
php -l app/config.php
php -l app/helpers.php
php -l public_html/index.php
```

9. If you changed the schema, re-run the migration or verify the new columns exist in MySQL.

## Default database tables

- `users`
- `plans`
- `orders`
- `contacts`
- `activity_logs`
- `password_resets`
- `companies`
- `fiscal_years`
- `ledgers`
- `vouchers`
- `voucher_entries`
- `settings`

## Core routes

- Public: `index.php`, `packages.php`, `order.php`, `invoice.php`, `checkout.php`, `contact.php`, `login.php`, `logout.php`, `dashboard.php`, `forgot-password.php`, `reset-password.php`, `portal.php`
- Staff: `staff/index.php`
- Admin: `admin/login.php`, `admin/logout.php`, `admin/index.php`, `admin/workspace.php`, `admin/invoice.php`, `admin/receipts.php`, `admin/export-invoice.php`, `admin/export-payment-receipt.php`, `admin/plans.php`, `admin/orders.php`, `admin/contacts.php`, `admin/users.php`, `admin/reports.php`, `admin/companies.php`, `admin/accounting.php`, `admin/chart-of-accounts.php`, `admin/compliance.php`, `admin/documents.php`, `admin/messages.php`, `admin/tickets.php`, `admin/hr.php`, `admin/settings.php`

## cPanel production settings

1. Document root: set to `public_html/`.
2. Keep `app/` and `database/` outside document root when possible.
3. Folder permissions:
	- Directories: `755`
	- Files: `644`
	- Upload folders (`public_html/uploads` and children): `755`
4. Ensure Apache `mod_rewrite` is enabled.
5. Keep `public_html/.htaccess` and `public_html/uploads/.htaccess` in place.

## Backup and restore

### Backup

1. Files backup from server root:
	- Include `public_html/`, `app/`, `.env`, and `database/`.
2. Database backup command (if SSH is available):

```bash
mysqldump -u DB_USER -p DB_NAME > backup.sql
```

### Restore

1. Restore files to the same paths.
2. Restore database:

```bash
mysql -u DB_USER -p DB_NAME < backup.sql
```

3. Re-apply any pending migration SQL files.
4. Confirm login, dashboard, order flow, contacts, and reports.

## Admin features

- Create, edit, and delete hosting plans
- Update order status and remove unwanted requests
- Manage contact requests as new, read, or replied
- Create, edit, and delete customer accounts
- Update global site settings from the browser
- Manage companies and fiscal years for portal context
- Review auto-posted accounting vouchers and ledger balances

## Notes

- The app is built to be extended, not locked to a framework.
- The admin area is intentionally simple so it works well on typical shared hosting.
- If you want, I can add invoice generation, payment integration, or a nicer admin UI next.

## Module completion checklist

- [x] Public site module (landing, packages, order, contact, login/dashboard)
- [x] Admin packages module (CRUD)
- [x] Admin orders workflow module (CRUD, filters, payment tracking, export)
- [x] Admin contacts workflow module (assignment, priority, attachment, activity logs)
- [x] Admin reports module (date filters, KPIs, CSV, print)
- [x] Admin users workflow module (CRUD, approvals, filters, pagination, activity logs)
- [x] Admin settings module (site and payment configuration)
- [x] Company and fiscal-year portal context module
- [x] Staff portal module
- [x] Accounting auto-posting and ledger voucher module
