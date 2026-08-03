# Restoring from a backup

For the day something has gone wrong. Read the whole page before typing
anything — the destructive step is step 5 and it cannot be undone.

## What you need

| | |
|---|---|
| The **passphrase** | generated when off-site backups were set up, and kept by you. It is in `BACKUP_PASSPHRASE` in `~/.env` on the server — but if the server is what you have lost, the only copy is wherever you saved it. **There is no way to recover it.** |
| A **backup file** | on the server in `~/db-backups/`, or in Google Drive under `mbista-backups` |
| A **database to restore into** | see step 4 |

Backups come in pairs, both from the same run:

- `mbcacomn_mbista_TIMESTAMP.sql.gz.enc` — the database
- `mbcacomn_mbista_files_TIMESTAMP.tar.gz.enc` — uploaded documents (KYC scans,
  signed agreements, attachments)

**Restore both, from the same timestamp.** The database stores only the *names*
of those files. Restore the database alone and every KYC scan, agreement and
attachment points at something that is not there.

---

## Before a real restore: check the backup is good

```bash
bash ~/repositories/Mbista/deploy/verify-backup.sh --remote
```

Decrypts and reads the newest Google Drive copy without touching anything. It
proves the passphrase works and the dump is complete. **Run this occasionally
even when nothing is wrong** — that is the whole point of it.

---

## Step 1 — get the file

Already on the server:

```bash
ls -lt ~/db-backups/ | head
```

Or from Google Drive:

```bash
~/bin/rclone lsf gdrive:mbista-backups
~/bin/rclone copy gdrive:mbista-backups/mbcacomn_mbista_20260803_033558.sql.gz.enc ~/restore/
```

## Step 2 — decrypt

```bash
cd ~/restore
BACKUP_PASSPHRASE_ENV='your-passphrase-here' openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
  -in mbcacomn_mbista_20260803_033558.sql.gz.enc \
  -out backup.sql.gz -pass env:BACKUP_PASSPHRASE_ENV
```

`bad decrypt` means the passphrase is wrong. Nothing else produces that.

## Step 3 — decompress

```bash
gunzip backup.sql.gz          # leaves backup.sql
head -5 backup.sql            # should look like SQL
grep -c '^CREATE TABLE' backup.sql
```

## Step 4 — make a database to restore INTO

**Restore into a new, empty database first — never straight over the live one.**
cPanel → MySQL Databases → Create New Database, e.g. `mbcacomn_restore`. Add
your existing user to it with All Privileges.

The reason is simple: until the restore has finished and you have looked at it,
you do not know the backup is good. Importing over the live database destroys
the only other copy of your data at the exact moment you are least sure of
yourself.

## Step 5 — import

```bash
mysql -u mbcacomn_youruser -p mbcacomn_restore < backup.sql
```

It will ask for the database password (in `~/.env` as `DB_PASS`). A few minutes
for a database this size, and silence means success.

## Step 6 — look at it before trusting it

```bash
mysql -u mbcacomn_youruser -p mbcacomn_restore -e "
  SELECT COUNT(*) AS companies FROM companies;
  SELECT COUNT(*) AS vouchers  FROM vouchers;
  SELECT MAX(created_at) AS newest FROM vouchers;"
```

`newest` tells you how much you lost: everything after that timestamp is gone,
because it happened after the backup ran. With hourly backups that is at most
an hour of work.

## Step 7 — the uploaded documents

```bash
cd ~/restore
BACKUP_PASSPHRASE_ENV='your-passphrase-here' openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
  -in mbcacomn_mbista_files_20260803_033558.tar.gz.enc \
  -out files.tar.gz -pass env:BACKUP_PASSPHRASE_ENV
tar -tzf files.tar.gz | head          # LOOK first
tar -xzf files.tar.gz -C ~/restore/files
```

Then copy `uploads/` and `secure_uploads/` back where they belong — `uploads`
into the document root, `secure_uploads` one level above it, never inside.

## Step 8 — point the app at the restored database

Only once steps 6 and 7 look right. Edit `~/.env`:

```
DB_NAME=mbcacomn_restore
```

Load the site. If it is wrong, change that one line back — which is why we did
not import over the live database.

---

## If the whole server is gone

1. New hosting, cPanel, database, and a `git clone` of the repository
2. Install rclone (`~/bin/rclone`) and connect it to the same Google Drive —
   see `docs/API_DEPLOY.md` for the pattern, and note the server's `/bin/rclone`
   may be too old to authorise
3. Download the newest pair from `gdrive:mbista-backups`
4. Steps 2 to 7 above
5. Recreate `~/.env` — it is **not** in the repository and never was. `DB_*`,
   `BACKUP_REMOTE`, `BACKUP_PASSPHRASE`
6. `bash deploy/tasks.sh`

Step 5 is the one that catches people out. Everything else can be rebuilt from
the repository and the backups; `.env` cannot.
