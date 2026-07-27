#!/bin/bash
# Nightly database backup for cPanel.
#
# Set it up once in cPanel -> Advanced -> Cron Jobs:
#
#   Schedule: 15 2 * * *     (02:15 every night)
#   Command:  /bin/bash /home/YOUR_CPANEL_USER/repositories/Mbista/deploy/backup-database.sh
#
# Replace the path with your repository path, shown in
# cPanel -> Git Version Control -> Manage -> Repository Path.
#
# WHAT IT DOES
#   1. Reads the database credentials from the SAME .env the app uses, so a
#      password change never leaves the backup authenticating with a stale one.
#   2. Dumps with --single-transaction, so InnoDB tables are captured at one
#      consistent instant WITHOUT locking the site while a customer is mid-sale.
#   3. Compresses, then encrypts if BACKUP_PASSPHRASE is set.
#   4. Verifies the dump is complete before it is allowed to count as a backup.
#   5. Copies it off the server if BACKUP_REMOTE is set.
#   6. Deletes local copies older than BACKUP_KEEP_DAYS.
#
# WHY IT VERIFIES
#   mysqldump exits 0 having written a truncated file more often than anyone
#   expects — a dropped connection, a disk that filled, a table it could not
#   read. A dump that ends without mysqldump's own end-of-dump marker is not a
#   backup, and finding that out during a restore is finding out too late. So
#   the marker is checked, and a dump that fails the check is kept under
#   .FAILED rather than rotated away, because a bad dump is evidence.

set -uo pipefail

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG="$HOME/backup-database.log"

log() {
    printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" >> "$LOG"
}

fail() {
    log "FAILED: $1"
    # Keep the log from growing without bound.
    tail -n 500 "$LOG" > "$LOG.tmp" 2>/dev/null && mv "$LOG.tmp" "$LOG"
    exit 1
}

# ---------------------------------------------------------------------------
# Configuration, read from the app's own .env
# ---------------------------------------------------------------------------
# The deploy puts app/ one level above the docroot and .env beside it. Look in
# both places so this works from a repo checkout and from a deployed tree.
ENV_FILE=""
for candidate in "$REPO_DIR/.env" "$HOME/.env" "$(dirname "$HOME/public_html")/.env"; do
    if [ -f "$candidate" ]; then
        ENV_FILE="$candidate"
        break
    fi
done
[ -n "$ENV_FILE" ] || fail "no .env found — cannot read the database credentials"

# Read KEY=VALUE without executing the file: sourcing it would run anything a
# stray backtick happened to contain.
env_value() {
    local key="$1"
    local line
    line="$(grep -E "^[[:space:]]*${key}[[:space:]]*=" "$ENV_FILE" | tail -n 1)"
    [ -n "$line" ] || return 0
    line="${line#*=}"
    line="${line#"${line%%[![:space:]]*}"}"          # trim leading space
    line="${line%"${line##*[![:space:]]}"}"          # trim trailing space
    line="${line%\"}"; line="${line#\"}"             # strip double quotes
    line="${line%\'}"; line="${line#\'}"             # strip single quotes
    printf '%s' "$line"
}

DB_HOST="$(env_value DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_NAME="$(env_value DB_NAME)"
DB_USER="$(env_value DB_USER)"
DB_PASS="$(env_value DB_PASS)"

BACKUP_DIR="$(env_value BACKUP_DIR)"; BACKUP_DIR="${BACKUP_DIR:-$HOME/db-backups}"
BACKUP_KEEP_DAYS="$(env_value BACKUP_KEEP_DAYS)"; BACKUP_KEEP_DAYS="${BACKUP_KEEP_DAYS:-30}"
BACKUP_PASSPHRASE="$(env_value BACKUP_PASSPHRASE)"
BACKUP_REMOTE="$(env_value BACKUP_REMOTE)"

[ -n "$DB_NAME" ] || fail "DB_NAME is empty in $ENV_FILE"
[ -n "$DB_USER" ] || fail "DB_USER is empty in $ENV_FILE"

mkdir -p "$BACKUP_DIR" || fail "cannot create $BACKUP_DIR"
# The dumps are readable plaintext unless a passphrase is set, so the directory
# is never world- or group-readable.
chmod 700 "$BACKUP_DIR" 2>/dev/null || true

STAMP="$(date '+%Y%m%d_%H%M%S')"
BASE="$BACKUP_DIR/${DB_NAME}_${STAMP}.sql"

# ---------------------------------------------------------------------------
# Dump
# ---------------------------------------------------------------------------
# The password goes in via an environment variable, not --password= on the
# command line, where every other user on the box can read it in `ps`.
export MYSQL_PWD="$DB_PASS"

log "starting dump of $DB_NAME"
mysqldump \
    --host="$DB_HOST" \
    --user="$DB_USER" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --events \
    --default-character-set=utf8mb4 \
    --no-tablespaces \
    "$DB_NAME" > "$BASE" 2>>"$LOG"
DUMP_STATUS=$?
unset MYSQL_PWD

if [ $DUMP_STATUS -ne 0 ]; then
    # Keep whatever was written. mysqldump stops at the first table it cannot
    # read, so the partial file names the point of failure — and leaving it
    # under its ordinary name would let the next run rotate it away as though
    # it were a good backup.
    [ -f "$BASE" ] && mv "$BASE" "$BASE.FAILED"
    fail "mysqldump exited $DUMP_STATUS (partial output kept as $BASE.FAILED)"
fi

# ---------------------------------------------------------------------------
# Verify BEFORE it is allowed to count as a backup
# ---------------------------------------------------------------------------
if [ ! -s "$BASE" ]; then
    mv "$BASE" "$BASE.FAILED" 2>/dev/null
    fail "the dump is empty"
fi
# mysqldump writes this line last. Its absence means the file was cut short.
if ! tail -c 4096 "$BASE" | grep -q "Dump completed"; then
    mv "$BASE" "$BASE.FAILED" 2>/dev/null
    fail "the dump is truncated — no end-of-dump marker (kept as $BASE.FAILED)"
fi
TABLES="$(grep -c '^CREATE TABLE' "$BASE" || true)"
if [ "${TABLES:-0}" -lt 5 ]; then
    mv "$BASE" "$BASE.FAILED" 2>/dev/null
    fail "only ${TABLES:-0} tables in the dump — that is not this database"
fi
log "dump verified: $TABLES tables"

# ---------------------------------------------------------------------------
# Compress, then encrypt if asked
# ---------------------------------------------------------------------------
gzip -9 "$BASE" || fail "gzip failed"
ARTIFACT="$BASE.gz"

if [ -n "$BACKUP_PASSPHRASE" ]; then
    if command -v openssl >/dev/null 2>&1; then
        # The passphrase is handed over through the environment, so it never
        # appears in the process list the way -pass pass:... would.
        BACKUP_PASSPHRASE_ENV="$BACKUP_PASSPHRASE" openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt \
            -in "$ARTIFACT" -out "$ARTIFACT.enc" -pass env:BACKUP_PASSPHRASE_ENV 2>>"$LOG"
        if [ -s "$ARTIFACT.enc" ]; then
            rm -f "$ARTIFACT"
            ARTIFACT="$ARTIFACT.enc"
            log "encrypted"
        else
            log "WARNING: encryption produced nothing; keeping the unencrypted copy"
            rm -f "$ARTIFACT.enc"
        fi
    else
        log "WARNING: BACKUP_PASSPHRASE is set but openssl is missing; the dump is NOT encrypted"
    fi
fi

chmod 600 "$ARTIFACT" 2>/dev/null || true
SIZE="$(du -h "$ARTIFACT" | cut -f1)"
log "wrote $ARTIFACT ($SIZE)"

# ---------------------------------------------------------------------------
# Off the server
# ---------------------------------------------------------------------------
# A backup on the same disk as the database is not a backup — it dies with the
# disk, and ransomware encrypts it alongside everything else. BACKUP_REMOTE is
# anything rclone or scp understands, e.g.
#     BACKUP_REMOTE="rclone:gdrive:mbista-backups"
#     BACKUP_REMOTE="scp:backupuser@203.0.113.9:/srv/mbista"
if [ -n "$BACKUP_REMOTE" ]; then
    case "$BACKUP_REMOTE" in
        rclone:*)
            TARGET="${BACKUP_REMOTE#rclone:}"
            if command -v rclone >/dev/null 2>&1; then
                rclone copy "$ARTIFACT" "$TARGET" >>"$LOG" 2>&1 \
                    && log "copied off-server to $TARGET" \
                    || log "WARNING: rclone copy to $TARGET failed — the backup is only on this server"
            else
                log "WARNING: BACKUP_REMOTE uses rclone but rclone is not installed"
            fi
            ;;
        scp:*)
            TARGET="${BACKUP_REMOTE#scp:}"
            scp -q -o BatchMode=yes "$ARTIFACT" "$TARGET" >>"$LOG" 2>&1 \
                && log "copied off-server to $TARGET" \
                || log "WARNING: scp to $TARGET failed — the backup is only on this server"
            ;;
        *)
            log "WARNING: BACKUP_REMOTE must start with rclone: or scp: — got '$BACKUP_REMOTE'"
            ;;
    esac
else
    log "NOTE: BACKUP_REMOTE is not set — this backup exists only on this server"
fi

# ---------------------------------------------------------------------------
# Retention
# ---------------------------------------------------------------------------
# Only rotate away GOOD backups. A .FAILED file is evidence of a night that did
# not work and is left for a human to look at.
find "$BACKUP_DIR" -maxdepth 1 -type f -name "${DB_NAME}_*.sql.gz*" -mtime "+$BACKUP_KEEP_DAYS" -print -delete >>"$LOG" 2>&1

REMAINING="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name "${DB_NAME}_*.sql.gz*" | wc -l | tr -d ' ')"
log "done — $REMAINING backup(s) held, keeping $BACKUP_KEEP_DAYS days"

tail -n 500 "$LOG" > "$LOG.tmp" 2>/dev/null && mv "$LOG.tmp" "$LOG"
exit 0
