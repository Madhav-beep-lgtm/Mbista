# Security runbook

What to do on the live server, in order, and why each step matters. Everything
here is a thing the code cannot do for you — it needs a decision, a secret, or
access to the hosting account.

Run this first, on the server, to see which of it still applies:

```bash
php database/security_audit.php
```

It changes nothing. It reports what is actually true of that database and that
`.env`, which is more useful than any checklist written in advance.

---

## 1. The repository is public, and its history holds seeded password hashes

**This is the one that matters most.**

`database/schema.sql`, `schema_cpanel.sql` and `schema_cpanel_fkfix.sql` used to
seed three real bcrypt hashes — for `admin@mbista.local`, `support@example.com`
and `excelbusinessandtax@gmail.com`. They have been removed from those files,
but **git history is permanent**. Anyone who cloned the repository, or who
clones it now, can read the old commits and take those hashes away to attack
offline for as long as they like.

Making the repository private is worth doing, but understand what it does not
do: it does not retract copies already taken, and it does not un-index anything
a crawler has already seen. **Treat those three passwords as known.**

Do all three of these:

1. **Change the passwords.** This is the part that actually closes the hole.
   ```bash
   php database/change_admin_credentials.php
   ```
   Run it on the server. It asks on stdin with echo off, so the password never
   reaches the shell history or `ps`.

2. **Make the repository private.**
   GitHub → the repository → Settings → General → Danger Zone → Change
   visibility. It is at `github.com/Madhav-beep-lgtm/Mbista`.

3. **Decide about the history.** Rewriting it (`git filter-repo`) removes the
   hashes from the record. It also rewrites every commit id, so anyone with a
   clone has to re-clone. If you are the only one working on it, do it. If not,
   changing the passwords is what protects you, and the rewrite is tidying.

Also in the history: `.env` was committed in the first commit and removed in
`05b3680`. `DB_USER` and `DB_NAME` are therefore public and are still the ones
in use. `DB_PASS` was **blank** at the time — a local-development default — so no password
leaked. Lower severity, but rename the database user if it is convenient.

---

## 2. Test accounts with full access

The audit lists them. On this laptop it found nine, three of them admins. They
are created by test suites and probes; cleanup usually removes them, and an
interrupted run leaves them behind with a known password and full access.

```bash
php database/security_audit.php --fix
```

It sets them to `inactive` and asks first. **It never deletes** — an account can
be attached to vouchers, and an audit trail should not grow holes because of a
security clean-up.

---

## 3. Backups — and what "no data loss" actually means

Be clear about this before relying on it. **A nightly backup does not mean no
data loss. It means you can lose up to a day.** If the disk dies at 8pm and the
last dump ran at 2:15am, everything billed that day is gone.

What the current setup gives you:

| | |
|---|---|
| **Worst-case loss** | up to ~24 hours of work |
| **Covers** | the whole database, and the uploaded files it names |
| **Verified** | yes — end-of-dump marker and table count, per run |
| **Off-site** | only if `BACKUP_REMOTE` is set (it is not) |
| **Encrypted** | only if `BACKUP_PASSPHRASE` is set (it is not) |

If a day is too much to lose, the options in increasing order of effort are:

1. **Run it more than once a day.** A second cron at midday halves the exposure.
   Costs nothing but disk.
2. **Enable MySQL binary logging** for point-in-time recovery — restore last
   night's dump, then replay the log up to the moment before the failure. This
   is the only thing that gets close to no loss at all. Many shared cPanel plans
   do not allow it; ask the host before planning around it.
3. **A replica.** Real-time, and a different order of cost and complexity.

Whichever you choose, **the number to decide is how many hours of billing you
could re-enter from paper.** That is your real target, and everything else
follows from it.

### The part that is easy to miss

A dump restores rows. It does not restore the KYC scan, the signed agreement or
the attachment on a message — those are files on disk, and the database only
stores the *path* to them. Restore the database alone and every one of those
rows points at a file that is not there.

So the nightly job now archives `public_html/uploads/` and `secure_uploads/`
alongside the dump, and both age out together — half a backup restores to a shop
with broken documents.

### Knowing it is still running

Two keys in the server's `.env`, both currently unset:

```
BACKUP_PASSPHRASE=<a long random string, kept somewhere else>
BACKUP_REMOTE=<rclone remote or scp target>
```

Without the first, backups are written in clear: a copy of the dump is a copy of
every client's books. Without the second there is no off-site copy, so whatever
destroys the server destroys the backups with it — which is the case a backup
exists for.

Keep the passphrase somewhere that is **not** the server. A passphrase stored
next to the thing it encrypts is decoration.

A backup nobody checks is a belief, not a backup. Every run now records how it
went, and the audit reads it:

```bash
php database/security_audit.php
```

It reports the last run, whether it succeeded, how old it is, and whether both
halves are present. A job that quietly stopped six weeks ago shows up as
CRITICAL there instead of being discovered during a restore.

Failures are still kept as `.FAILED` files rather than rotated away — a bad dump
is evidence of a night that did not work, and it should be looked at rather than
tidied up.

`deploy/backup-database.sh` verifies the dump's end marker and table count, and
renames a bad run to `.FAILED` rather than leaving a truncated file that looks
fine. A `.FAILED` file means that night has no backup — it is not a warning to
be cleared, it is a night to be re-run.

---

## 4. Password-reset email

```
MAIL_HOST=<your SMTP host>
```

Unset, the mailer writes to `storage/mail/` instead of sending. That is a
sensible default for a laptop and useless on a live server: **the reset link is
generated, the user is told to check their email, and no email is ever sent.**
On cPanel, create an email account and use its SMTP settings.

While you are there, confirm `APP_URL` is the real address. Reset links are
built from it, so if it still says `127.0.0.1` the mail goes out pointing at the
recipient's own machine.

---

## 5. Things worth checking once

- **`APP_ENV=production`** on the server, so error detail is not shown to
  visitors.
- **`APP_TIMEZONE`** — defaults to `Asia/Kathmandu` in code. Set it explicitly
  so it is not a thing to rediscover later. PHP and MySQL are both told, which
  is what stops an 11pm voucher landing on the previous day.
- **Two-factor** (`app/two_factor.php`) is implemented. Turn it on for the admin
  accounts; it is the difference between a leaked password being a problem and
  being a breach.
- **`public_html/.htaccess`** already forces HTTPS, sets HSTS on HTTPS responses
  only, sets a CSP, and denies `.bak`/`.sql`/`.log` and friends. Worth a look
  after any hosting change, since a control panel can rewrite it.

---

## What the code now does on its own

For the record, so this list is not re-litigated later:

- The shipped schema seeds **no usable password**. The placeholder is not a hash
  and `password_verify()` rejects everything against it, so a fresh install has
  no login until `change_admin_credentials.php` is run. Deliberate: an install
  you cannot log into is a nuisance for five minutes, one that everybody shares
  a known password to is a problem for as long as it runs.
- Re-importing the schema over a live database **no longer overwrites**
  `password_hash`, `role` or `status`. It used to, which meant a re-import
  silently reset a working admin password back to the published one, and could
  re-activate an account somebody had disabled on purpose.
- `database/test_schema_credentials.php` proves both of those against a real
  database, and fails if any tracked file grows a bcrypt hash again.
