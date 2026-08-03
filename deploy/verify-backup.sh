#!/bin/bash
# Prove a backup can be restored — without restoring it.
#
# A backup nobody has opened is a hope. Every check the nightly job already
# does is a check on the file it just WROTE; none of them prove that the copy
# sitting in Google Drive can be decrypted with the passphrase you actually
# kept, months later, on the day the server is gone. Those are different
# questions, and the second one is the one that matters.
#
# This answers it and changes nothing: it fetches a backup, decrypts it,
# decompresses it, and reads it. It never connects to MySQL, never writes to
# the backup directory, and cleans up after itself. Running it is always safe.
#
#   bash deploy/verify-backup.sh              # newest LOCAL backup
#   bash deploy/verify-backup.sh --remote     # newest backup FROM Google Drive
#   bash deploy/verify-backup.sh /path/to/file.sql.gz.enc
#
# --remote is the one worth trusting. The local copy proves the dump was
# written; only the remote copy proves the thing you would actually reach for
# in a disaster survived the journey.

set -uo pipefail

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORK=""
cleanup() { [ -n "$WORK" ] && rm -rf "$WORK"; }
trap cleanup EXIT

say()  { printf '%s\n' "$1"; }
die()  { printf 'FAILED: %s\n' "$1" >&2; exit 1; }

# Same .env search order as backup-database.sh, so the two cannot disagree
# about where the passphrase lives.
ENV_FILE=""
for candidate in "$REPO_DIR/.env" "$HOME/.env" "$(dirname "$HOME/public_html")/.env"; do
    [ -f "$candidate" ] && { ENV_FILE="$candidate"; break; }
done
[ -n "$ENV_FILE" ] || die "no .env found — cannot read the passphrase"

env_value() {
    local line
    line="$(grep -E "^[[:space:]]*$1[[:space:]]*=" "$ENV_FILE" | tail -n 1)"
    [ -n "$line" ] || return 0
    line="${line#*=}"
    line="${line#"${line%%[![:space:]]*}"}"
    line="${line%"${line##*[![:space:]]}"}"
    line="${line%\"}"; line="${line#\"}"
    line="${line%\'}"; line="${line#\'}"
    printf '%s' "$line"
}

BACKUP_DIR="$(env_value BACKUP_DIR)"; BACKUP_DIR="${BACKUP_DIR:-$HOME/db-backups}"
BACKUP_PASSPHRASE="$(env_value BACKUP_PASSPHRASE)"
BACKUP_REMOTE="$(env_value BACKUP_REMOTE)"
DB_NAME="$(env_value DB_NAME)"

# The same rclone rule as backup-database.sh: only the build that holds the
# authorisation can use it.
rclone_bin() {
    if [ -x "$HOME/bin/rclone" ]; then printf '%s' "$HOME/bin/rclone"
    elif command -v rclone >/dev/null 2>&1; then command -v rclone
    fi
}

WORK="$(mktemp -d)" || die "cannot create a temporary directory"
SOURCE="${1:-}"

if [ "$SOURCE" = "--remote" ]; then
    [ -n "$BACKUP_REMOTE" ] || die "BACKUP_REMOTE is not set in $ENV_FILE"
    case "$BACKUP_REMOTE" in
        rclone:*) ;;
        *) die "--remote only supports rclone: targets (got '$BACKUP_REMOTE')" ;;
    esac
    TARGET="${BACKUP_REMOTE#rclone:}"
    RCLONE="$(rclone_bin)"
    [ -n "$RCLONE" ] || die "rclone is not installed"

    say "Looking in $TARGET ..."
    NEWEST="$("$RCLONE" lsf "$TARGET" --include "${DB_NAME}_2*.sql.gz*" 2>/dev/null | sort | tail -1)"
    [ -n "$NEWEST" ] || die "no database backup found in $TARGET"
    say "Newest there: $NEWEST"
    say "Downloading ..."
    "$RCLONE" copy "$TARGET/$NEWEST" "$WORK/" >/dev/null 2>&1 || die "could not download $NEWEST"
    FILE="$WORK/$NEWEST"
elif [ -n "$SOURCE" ]; then
    FILE="$SOURCE"
    [ -f "$FILE" ] || die "no such file: $FILE"
else
    FILE="$(ls -1t "$BACKUP_DIR"/${DB_NAME}_2*.sql.gz* 2>/dev/null | grep -v '\.FAILED' | head -1)"
    [ -n "$FILE" ] || die "no backup found in $BACKUP_DIR"
    say "Newest local backup: $(basename "$FILE")"
fi

say ""
say "1. Reading the file"
BYTES="$(wc -c < "$FILE" | tr -d ' ')"
[ "$BYTES" -gt 0 ] || die "the file is empty"
say "   $(basename "$FILE") — $BYTES bytes"

WORKING="$FILE"

case "$FILE" in
    *.enc)
        say ""
        say "2. Decrypting"
        # THE CHECK THIS SCRIPT EXISTS FOR. A wrong passphrase fails here, and
        # finding that out now costs a minute; finding it out during a restore
        # costs the business.
        [ -n "$BACKUP_PASSPHRASE" ] || die "the file is encrypted but BACKUP_PASSPHRASE is empty in $ENV_FILE"
        BACKUP_PASSPHRASE_ENV="$BACKUP_PASSPHRASE" openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
            -in "$FILE" -out "$WORK/decrypted.gz" -pass env:BACKUP_PASSPHRASE_ENV 2>"$WORK/openssl.err" \
            || die "decryption failed — the passphrase in $ENV_FILE does not open this file. $(head -1 "$WORK/openssl.err" 2>/dev/null)"
        say "   decrypted with the passphrase in $ENV_FILE"
        WORKING="$WORK/decrypted.gz"
        ;;
    *)
        say ""
        say "2. Decrypting — skipped, this backup is not encrypted"
        ;;
esac

say ""
say "3. Decompressing"
gzip -t "$WORKING" 2>/dev/null || die "the gzip archive is corrupt — this backup would not restore"
gzip -dc "$WORKING" > "$WORK/dump.sql" 2>/dev/null || die "could not decompress"
SQL_BYTES="$(wc -c < "$WORK/dump.sql" | tr -d ' ')"
say "   $SQL_BYTES bytes of SQL"

say ""
say "4. Checking the dump is complete"
# mysqldump writes this line last. Its absence means the dump was cut off — a
# truncated file that gzip is perfectly happy with, and that restores most of
# the business and then stops.
if grep -q '^-- Dump completed' "$WORK/dump.sql"; then
    say "   ends with mysqldump's completion marker"
else
    die "no completion marker — this dump is truncated and would restore only part of the data"
fi

TABLES="$(grep -c '^CREATE TABLE' "$WORK/dump.sql")"
ROWS="$(grep -c '^INSERT INTO' "$WORK/dump.sql")"
say "   $TABLES tables, $ROWS insert statements"
[ "$TABLES" -gt 0 ] || die "no tables in the dump"

say ""
say "5. Spot-checking the contents"
for t in users companies voucher_entries; do
    if grep -q "^CREATE TABLE \`$t\`" "$WORK/dump.sql"; then
        say "   found table: $t"
    else
        say "   NOTE: table '$t' not in this dump"
    fi
done

say ""
say "==================================================="
say "  RESTORABLE — decrypted, decompressed, complete."
say "  $TABLES tables from $(basename "$FILE")"
say "==================================================="
say ""
say "Nothing was written to your database. To restore for real, see"
say "docs/RESTORE.md — but only on the day you actually mean to."
