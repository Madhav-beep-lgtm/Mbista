# Final Application Review Checklist

Date: 2026-07-02
Review basis: implemented workspace code and database schema in this repository.

## 1) Every page and route

- [x] `public_html/index.php`
- [x] `public_html/packages.php`
- [x] `public_html/order.php`
- [x] `public_html/invoice.php`
- [x] `public_html/checkout.php`
- [x] `public_html/contact.php`
- [x] `public_html/login.php`
- [x] `public_html/logout.php`
- [x] `public_html/dashboard.php`
- [x] `public_html/forgot-password.php`
- [x] `public_html/reset-password.php`
- [x] `public_html/setup.php`
- [x] `public_html/admin/login.php`
- [x] `public_html/admin/logout.php`
- [x] `public_html/admin/index.php`
- [x] `public_html/admin/plans.php`
- [x] `public_html/admin/orders.php`
- [x] `public_html/admin/contacts.php`
- [x] `public_html/admin/users.php`
- [x] `public_html/admin/reports.php`
- [x] `public_html/admin/settings.php`

## 2) Public website sections

- [x] Home hero and sections
- [x] Packages listing
- [x] Order request workflow
- [x] Invoice and checkout flow
- [x] Contact form
- [x] Login/logout
- [x] Customer dashboard
- [x] Password reset flow

## 3) Admin, staff and client portals

- [x] Admin portal implemented
- [x] Staff-specific portal implemented (`public_html/staff/index.php`)
- [x] Client/customer portal implemented (`dashboard.php`)

## 4) Altiora company portals

- [x] Company + fiscal-year context portal implemented (`public_html/portal.php`)

## 5) Dashboard cards and statistics

- [x] Admin dashboard cards and stats
- [x] Customer dashboard cards and order table

## 6) Forms, fields and validations

- [x] Server-side validation for login/order/contact/users/admin workflows
- [x] CSRF token checks on protected forms
- [x] Client-side validation for contacts/users module forms

## 7) Tables, filters, sorting and pagination

- [x] Orders: search/filter/pagination/export
- [x] Contacts: search/filter/sort/pagination/export
- [x] Users: search/filter/sort/pagination/export
- [x] Reports: filterable result tables

## 8) Roles and permissions

- [x] Admin, staff, and customer roles
- [x] Admin-route protection via `require_admin()`
- [x] Customer route protection via `require_login()`
- [x] Staff-route protection via `require_staff_or_admin()`

## 9) MySQL tables and relationships

- [x] `users`, `plans`, `orders`, `contacts`, `settings`
- [x] `activity_logs` and `password_resets`
- [x] `companies`, `fiscal_years`, `ledgers`, `vouchers`, `voucher_entries`
- [x] Foreign keys between users/plans/orders/contacts/password_resets/activity_logs

## 10) Backend APIs

- [x] Route handlers implemented as PHP endpoints per page/module
- [x] CSV exports for orders/contacts/users/reports

## 11) Accounting workflows and automatic postings

- [x] Ledger/voucher accounting posting engine implemented
- [x] Automatic voucher posting for paid/refunded order transitions

## 12) Reports, printing and exports

- [x] Reports screen with KPI cards and table summaries
- [x] Print actions (browser print)
- [x] CSV exports

## 13) Documents and file uploads

- [x] Contact attachment upload and replacement workflow
- [x] Upload restrictions (file type and size)
- [x] Upload hardening via `public_html/uploads/.htaccess`

## 14) Notifications and activity logs

- [x] Flash success/error notifications
- [x] Activity log table and workflow logging for contacts/users/password reset

## 15) Colours, fonts, icons, images and templates

- [x] Existing design system preserved from `public_html/assets/css/style.css`
- [x] Shared partial templates preserved

## 16) Mobile, tablet and desktop layouts

- [x] Responsive breakpoints in main stylesheet
- [x] Users/contacts/orders/reports layouts include mobile fallbacks

## Gap summary for full parity

- No open parity gaps identified from the approved checklist scope.

## Executed tests (2026-07-02)

- [x] PHP syntax lint across all PHP files: PASS
- [x] Public route smoke checks by HTTP status: PASS (`/`, `/login.php`, `/forgot-password.php`, `/reset-password.php`, `/contact.php`, `/order.php` returned 200)
- [x] Protected route checks by HTTP status: PASS (`/admin/users.php`, `/dashboard.php`, `/portal.php`, `/staff/index.php`, `/admin/companies.php`, `/admin/accounting.php` returned 302 when unauthenticated)
- [x] Admin login route protection check: PASS (`/admin/login.php` returned 302 in current setup state)
- [x] Database connectivity (`SELECT 1` through app bootstrap): PASS
- [x] Workflow smoke test with real MySQL operations: PASS
	- create user
	- create order
	- create contact
	- request and complete password reset
	- create staff user
	- context resolution for company + fiscal year
	- auto-post order payment and refund vouchers
	- cleanup temporary records
- [x] Migration execution in active DB: PASS
	- `003_contacts_workflow_enhancements.sql`
	- `004_add_password_reset_tokens.sql`
	- `005_add_company_fiscal_staff_accounting.sql`

## Deployment preparation status

- [x] Production environment example added: `.env.example`
- [x] API/base URL configuration via `APP_URL` in env-backed config
- [x] Database connection configuration via env-backed `DB_*` values
- [x] Secure upload hardening added (`public_html/uploads/.htaccess`, `public_html/uploads/index.php`)
- [x] Public hardening headers and restrictions added (`public_html/.htaccess`)
- [x] Updated base schema to latest structures (`database/schema.sql`)
- [x] Added migration for password reset table (`database/migrations/004_add_password_reset_tokens.sql`)
- [x] README deployment guide updated with cPanel steps, permissions, migrations, backup and restore
