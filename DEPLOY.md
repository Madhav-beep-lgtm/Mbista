# Deploying MB World to cPanel

This app is plain PHP (no build step, no Composer dependency to install) with a
MySQL database. It deploys to standard cPanel shared hosting.

Verified: `database/schema.sql` imports cleanly into a fresh, empty database —
55 tables, no `CREATE DATABASE` / `USE` / `DEFINER` statements (which cPanel
database users usually cannot run). The three schema files
(`schema.sql`, `schema_cpanel.sql`, `schema_cpanel_fkfix.sql`) are identical and
current; import any one.

## Requirements

- PHP **8.1 or 8.2** (set in cPanel → MultiPHP Manager). The code uses 8.1+
  features and is tested on 8.2.
- MySQL / MariaDB with `utf8mb4`.
- PHP extensions: `pdo_mysql`, `mbstring`, `fileinfo` (all standard on cPanel).

## 1. Create the database

cPanel → **MySQL Databases**:

1. Create a database (e.g. `cpaneluser_mbworld`).
2. Create a database user with a strong password.
3. Add the user to the database with **All Privileges**.

## 2. Import the schema

cPanel → **phpMyAdmin** → select your new database → **Import** tab →
choose `database/schema.sql` → **Go**. You should see 55 tables created.

(Upgrading an existing install instead of a fresh import? The files in
`database/migrations/` add later columns, but the app's self-repair layer also
adds any missing accounting columns automatically on first page load.)

## 3. Upload the files

Place the project so that **`app/`, `database/`, and `.env` sit one level ABOVE
`public_html`** (in your cPanel home directory), and the **contents of the
repo's `public_html/` go into your cPanel `public_html/`**. Keeping `app/` and
`.env` outside the web root is deliberate — it keeps code and credentials
unreachable from the browser.

Resulting layout in your cPanel home directory:

```
/home/cpaneluser/
├── app/                 (from repo)
├── database/            (from repo)
├── secure_uploads/      (from repo — KYC files stay outside the web root)
├── .env                 (you create this — see step 4)
└── public_html/         (contents of the repo's public_html/)
    ├── admin/
    ├── assets/
    ├── index.php
    └── ...
```

Do **not** upload the `Mbista/` or `backups/` folders if they exist in your
local copy — they are local-only working directories (already gitignored) and
have no place on the server.

## 4. Configure `.env`

Copy `.env.example` to `.env` in the home directory (above `public_html`) and
fill in your values:

```
APP_ENV=production
APP_NAME="M.B Group"
APP_URL="https://your-domain.com"

DB_HOST=localhost
DB_NAME=cpaneluser_mbworld
DB_USER=cpaneluser_dbuser
DB_PASS=your_db_password
DB_CHARSET=utf8mb4
```

- `APP_URL` must be your real domain (used to build links). For an add-on or
  subdirectory install, include the subpath.
- On cPanel, `DB_NAME` and `DB_USER` almost always carry the `cpaneluser_`
  prefix — copy them exactly from the MySQL Databases page.

### Email (optional, for scheduled reports)

Leave `MAIL_HOST` empty and outgoing mail is written to `storage/mail/` instead
of being sent (safe default). To actually send: create an email account in
cPanel → Email Accounts and put its SMTP host/port/username/password in `.env`,
or set `MAIL_TRANSPORT=mail` to use the host's PHP `mail()`.

## 5. First login and verification

1. Visit `https://your-domain.com/` — the public site should load.
2. Log in at `/login.php` with your admin account.
3. Open an accounting page (e.g. Chart of Accounts). The self-repair layer runs
   here and ensures every accounting column exists.
4. Switch into a company, confirm the dashboard and a report render.

## Permissions

These directories must be writable by PHP (cPanel usually sets this correctly;
`755` on directories, `644` on files):

- `public_html/uploads/` and its subfolders (attachments, documents, messages)
- `public_html/assets/uploads/` (company logos, signatures, stamps)
- `secure_uploads/kyc/` (KYC documents)
- `storage/mail/` (only if using the file mail fallback)

## Automatic deployment (cron)

Once the repository is connected in cPanel → **Git Version Control**, you can
make every GitHub push go live automatically instead of clicking
"Update from Remote" / "Deploy HEAD Commit" by hand:

1. Find your repository path in cPanel → Git Version Control → **Manage**
   (usually `/home/YOUR_CPANEL_USER/repositories/Mbista`).
2. In cPanel → Advanced → **Cron Jobs**, add a new job:
   - **Schedule:** Once Per Five Minutes (`*/5 * * * *`)
   - **Command:**
     `/bin/bash /home/YOUR_CPANEL_USER/repositories/Mbista/deploy/auto-deploy.sh >/dev/null 2>&1`

The script (`deploy/auto-deploy.sh`) fetches `origin/main`, exits silently if
there is nothing new, and otherwise fast-forwards and re-runs the tasks from
`.cpanel.yml`. Activity is written to `~/auto-deploy.log` — check that file if
a push does not appear on the site within five minutes. It never overwrites
server-side uploads or the live `.env` (same guarantees as `.cpanel.yml`).

## Notes

- **Party ledgers (one-time, after deploying migration 029):** existing
  receivable/payable balances can be moved from the generic AR/AP ledgers
  onto each customer/supplier's own ledger with
  `php database/reclassify_party_balances.php` (preview) followed by
  `php database/reclassify_party_balances.php --apply`. It also posts
  opening-balance journals for parties that have one. Safe to re-run —
  each journal posts at most once per company.
- **HTTPS:** enable AutoSSL (cPanel → SSL/TLS Status) so the app runs over
  HTTPS. Sessions and the login flow assume a normal single-domain setup.
- **Scheduled reports:** if you use them, add a cPanel Cron Job that runs the
  schedule script (`database/run_report_schedules.php`) daily.
- **Dates:** the Nepali (Bikram Sambat) calendar is built in and self-contained —
  no external service or API key is needed. Users pick AD / BS / AD+BS from the
  topbar.
