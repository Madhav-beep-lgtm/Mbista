#!/bin/bash
# Nightly database backup for cPanel.
#
# Set it up once in cPanel -> Advanced -> Cron Jobs:
#
#   Schedule: 15 2 * * *     (02:15 every night)
#   Command:  /bin/bash /home/YOUR_CPANEL_USER/repositories/Mbista/deploy/backup-database.sh
#
# NIGHTLY MEANS A DAY OF ENTRIES AT RISK. Shared hosting offers no binary log,
# so the restore point is the last dump: a disk that dies at 18:00 takes the
# whole day's bills with it. The dump is cheap (--single-transaction, no
# locks), so run it every six hours if a day is too much to lose:
#
#   Schedule: 15 2,8,14,20 * * *
#
# Rotation already copes — BACKUP_KEEP_DAYS ages out by file date either way.
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

# A machine-readable record of how the last run went.
#
# Until now a failure wrote the word FAILED into a log file on the server and
# stopped. Nobody reads a log file on a good day, so backups could stop for
# weeks and the first anyone would know is the day they were needed. The app
# reads this file instead (php database/security_audit.php), which turns
# "backups silently stopped" into something that shows up while it still
# matters.
write_status() {
    # Resolved when it is CALLED, not when this function is defined. BACKUP_DIR
    # is read from .env further down, so computing the path up here put the
    # status file in $HOME while the app looked for it in the backup directory —
    # the two would never have met, and the health check would have reported "no
    # backup has ever run" on a server backing up perfectly well every night.
    status_path="${BACKUP_DIR:-$HOME}/backup-status.json"
    # Quotes and backslashes would break the JSON this is read back as — and so
    # would a newline, which is how mysqldump's own words arrive now that the
    # detail quotes them. A raw newline inside a JSON string is invalid, the app
    # would decode nothing at all, and "nothing decoded" reads to the health
    # check as "no backup has ever run": a broken message about a failure would
    # have been reported as a missing cron.
    clean_json_text() {
        printf '%s' "$1" | tr -d '\\"' | tr '\n\r\t' '   ' | tr -s ' ' | cut -c1-400
    }
    detail="$(clean_json_text "$2")"
    warning="$(clean_json_text "${WARNING_NOTE:-}")"
    printf '{"state":"%s","at":"%s","detail":"%s","warning":"%s","tables":%s,"artifact":"%s"}\n' \
        "$1" "$(date '+%Y-%m-%d %H:%M:%S')" "$detail" "$warning" \
        "${TABLES:-0}" "$(basename "${ARTIFACT:-none}")" > "$status_path" 2>/dev/null || true
    chmod 600 "$status_path" 2>/dev/null || true
}

fail() {
    log "FAILED: $1"
    write_status "failed" "$1"
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

# mysqldump's stderr goes to a file of its own rather than straight into the
# log. It still reaches the log, but it can also be READ: an exit status on its
# own ("mysqldump exited 3") names no cause, and the banner that reported it
# sent whoever read it looking for a log file on a server they may not have a
# shell on. The sentence mysqldump actually wrote is the whole diagnosis, so it
# is carried through to the status file and shown with the failure.
DUMP_ERR="$BACKUP_DIR/.mysqldump-stderr.$$"
trap 'rm -f "$DUMP_ERR"' EXIT

# The last thing mysqldump said that was not boilerplate. The useful line is at
# the end, and the noise at the front is the password-on-the-command-line
# warning every client prints whether or not anything is wrong.
dump_error() {
    grep -v -e '[Ww]arning: Using a password' -e '^[[:space:]]*$' "$DUMP_ERR" 2>/dev/null | tail -n 1
}

# Two flags a mysqldump NEWER than its server needs, and that an older
# mysqldump rejects as unknown options — so they are asked for by name instead
# of assumed, because guessing wrong kills the run at argument parsing:
#   --column-statistics=0  an 8.0 client asks a 5.7 or MariaDB server for
#                          histogram data it has never heard of, and the dump
#                          dies on information_schema.COLUMN_STATISTICS.
#   --set-gtid-purged=OFF  on a GTID server the client emits SET GTID_PURGED,
#                          which needs privileges shared hosting does not hand
#                          out — and which a single-database restore must not
#                          be carrying in the first place.
COMPAT_FLAGS=()
MYSQLDUMP_HELP="$(mysqldump --help 2>/dev/null || true)"
case "$MYSQLDUMP_HELP" in *column-statistics*) COMPAT_FLAGS+=(--column-statistics=0) ;; esac
case "$MYSQLDUMP_HELP" in *set-gtid-purged*) COMPAT_FLAGS+=(--set-gtid-purged=OFF) ;; esac

run_mysqldump() {
    mysqldump \
        --host="$DB_HOST" \
        --user="$DB_USER" \
        --single-transaction \
        --quick \
        --default-character-set=utf8mb4 \
        --no-tablespaces \
        ${COMPAT_FLAGS+"${COMPAT_FLAGS[@]}"} \
        "$@" \
        "$DB_NAME" > "$BASE" 2>"$DUMP_ERR"
}

# A dump gets three goes before the night is written off, because the two ways
# it usually dies are both survivable:
#
#   TRANSIENT. A dropped connection, max_user_connections reached because
#   somebody else on the shared box is busy, or a migration running ALTER while
#   --single-transaction holds its snapshot — that last one aborts the dump with
#   "table definition has changed" and is entirely a matter of WHEN the cron
#   fired. Thirty seconds later it works. One attempt turned an hour's bad
#   timing into a night with no backup.
#
#   THE EXTRAS. Routines, triggers and events are read through SHOW statements a
#   cPanel database user is not always granted, and a view whose DEFINER no
#   longer exists — which is what an import restored under a different user
#   leaves behind — fails the same way. Any of them aborts the dump and takes
#   THE DATA down with it, which is the wrong trade: the rows are the thing
#   being protected. So the last attempt drops the extras and keeps the rows.
#   That is a degraded backup and it says so, loudly, in the status the admin
#   header reads — but it is a backup, and it is taken tonight rather than
#   after somebody has worked out a privilege.
EXTRAS=(--routines --triggers --events)
DUMP_STATUS=1
ATTEMPT=0
# Held from the attempt that FAILED, because each attempt overwrites the stderr
# file and the attempt that succeeds leaves it empty. Read afterwards, it
# reported the reason for the fallback as "()" — a warning that says something
# was skipped without saying why is a warning nobody can act on.
LAST_ERROR=""
while [ "$ATTEMPT" -lt 3 ]; do
    ATTEMPT=$((ATTEMPT + 1))
    case "$ATTEMPT" in
        1) log "starting dump of $DB_NAME"
           run_mysqldump "${EXTRAS[@]}" ;;
        2) log "attempt 1 exited $DUMP_STATUS — retrying in 30s in case it was transient"
           sleep 30
           run_mysqldump "${EXTRAS[@]}" ;;
        3) log "attempt 2 exited $DUMP_STATUS — retrying without --routines/--triggers/--events"
           run_mysqldump ;;
    esac
    DUMP_STATUS=$?

    [ "$DUMP_STATUS" -eq 0 ] && break

    LAST_ERROR="$(dump_error)"
    log "attempt $ATTEMPT failed (exit $DUMP_STATUS): $LAST_ERROR"
    # The whole of stderr as well as the one line lifted out of it: the summary
    # is what gets reported, the full text is what gets diagnosed.
    cat "$DUMP_ERR" >> "$LOG" 2>/dev/null
done
unset MYSQL_PWD

# A FAILED dump is evidence and is never rotated away with the good ones. But
# an hourly job that has been failing for a week leaves a week of partial
# dumps, uncompressed, on a shared disk with a quota — and a full disk stops
# the INSERTs, which is a larger outage than the one being investigated. The
# newest few say everything the whole pile would.
prune_failed_artifacts() {
    ls -1t "$BACKUP_DIR"/*.FAILED 2>/dev/null | tail -n +6 | while IFS= read -r stale; do
        rm -f -- "$stale" && log "removed superseded evidence file $(basename "$stale")"
    done
}

if [ "$DUMP_STATUS" -ne 0 ]; then
    # Keep whatever was written. mysqldump stops at the first table it cannot
    # read, so the partial file names the point of failure — and leaving it
    # under its ordinary name would let the next run rotate it away as though
    # it were a good backup.
    [ -f "$BASE" ] && mv "$BASE" "$BASE.FAILED"
    prune_failed_artifacts
    log "all $ATTEMPT attempts failed; partial output kept as $BASE.FAILED"
    # The basename, not the path: this sentence is shown in the admin header,
    # and a full server path there pushes the part that matters — what
    # mysqldump said — off the end of the line.
    fail "mysqldump exited $DUMP_STATUS after $ATTEMPT attempts${LAST_ERROR:+ — $LAST_ERROR} (partial output kept as $(basename "$BASE").FAILED)"
fi

if [ "$ATTEMPT" -ge 3 ]; then
    WARNING_NOTE="stored routines, triggers and events were SKIPPED${LAST_ERROR:+ — mysqldump said: $LAST_ERROR}. The rows are safe; the schema is not complete."
    log "WARNING: $WARNING_NOTE"
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
ship_offsite() {
    ARTEFACT="$1"
    if [ -z "$BACKUP_REMOTE" ]; then
        log "NOTE: BACKUP_REMOTE is not set — $(basename "$ARTEFACT") exists only on this server"
        return 0
    fi
    case "$BACKUP_REMOTE" in
        rclone:*)
            TARGET="${BACKUP_REMOTE#rclone:}"
            if command -v rclone >/dev/null 2>&1; then
                rclone copy "$ARTEFACT" "$TARGET" >>"$LOG" 2>&1                     && log "copied $(basename "$ARTEFACT") off-server to $TARGET"                     || log "WARNING: rclone copy to $TARGET failed — $(basename "$ARTEFACT") is only on this server"
            else
                log "WARNING: BACKUP_REMOTE uses rclone but rclone is not installed"
            fi
            ;;
        scp:*)
            TARGET="${BACKUP_REMOTE#scp:}"
            scp -q -o BatchMode=yes "$ARTEFACT" "$TARGET" >>"$LOG" 2>&1                 && log "copied $(basename "$ARTEFACT") off-server to $TARGET"                 || log "WARNING: scp to $TARGET failed — $(basename "$ARTEFACT") is only on this server"
            ;;
        *)
            log "WARNING: BACKUP_REMOTE must start with rclone: or scp: — got '$BACKUP_REMOTE'"
            ;;
    esac
}

ship_offsite "$ARTIFACT"

# ---------------------------------------------------------------------------
# The files the database only holds the NAMES of
# ---------------------------------------------------------------------------
# A dump restores rows. It does not restore the client's KYC scan, the signed
# agreement or the attachment on a message — those are files on disk, and the
# database only stores the path to them. Restore the database alone and every
# one of those rows points at something that is not there any more.
#
# So they are backed up in the same run, and they go off-site with the dump: a
# pair of artefacts from the same night restores to a consistent shop.
#
# A full archive each night, because these are small. If it ever grows past a
# few hundred MB, switch to rsync against a remote that keeps its own history
# rather than making the tarball nightly.
FILE_ROOTS=()
[ -d "$REPO_DIR/public_html/uploads" ] && FILE_ROOTS+=("public_html/uploads")
[ -d "$REPO_DIR/secure_uploads" ] && FILE_ROOTS+=("secure_uploads")

if [ ${#FILE_ROOTS[@]} -eq 0 ]; then
    log "NOTE: no upload directories found to back up"
else
    FILES_BASE="$BACKUP_DIR/${DB_NAME}_files_$STAMP.tar.gz"
    if tar -czf "$FILES_BASE" -C "$REPO_DIR" "${FILE_ROOTS[@]}" 2>>"$LOG"; then
        # tar exits 0 on an empty archive too, so the contents are counted.
        FILE_COUNT="$(tar -tzf "$FILES_BASE" 2>/dev/null | wc -l | tr -d ' ')"
        if [ "${FILE_COUNT:-0}" -lt 1 ]; then
            mv "$FILES_BASE" "$FILES_BASE.FAILED" 2>/dev/null
            log "WARNING: the file archive came out empty — kept as $(basename "$FILES_BASE").FAILED"
        else
            FILES_ARTIFACT="$FILES_BASE"
            if [ -n "$BACKUP_PASSPHRASE" ] && command -v openssl >/dev/null 2>&1; then
                BACKUP_PASSPHRASE_ENV="$BACKUP_PASSPHRASE" openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt                     -in "$FILES_ARTIFACT" -out "$FILES_ARTIFACT.enc" -pass env:BACKUP_PASSPHRASE_ENV 2>>"$LOG"
                if [ -s "$FILES_ARTIFACT.enc" ]; then
                    rm -f "$FILES_ARTIFACT"
                    FILES_ARTIFACT="$FILES_ARTIFACT.enc"
                else
                    rm -f "$FILES_ARTIFACT.enc"
                    log "WARNING: file archive encryption produced nothing; keeping the unencrypted copy"
                fi
            fi
            chmod 600 "$FILES_ARTIFACT" 2>/dev/null || true
            log "wrote $(basename "$FILES_ARTIFACT") ($FILE_COUNT entries, $(du -h "$FILES_ARTIFACT" | cut -f1))"
            ship_offsite "$FILES_ARTIFACT"
        fi
    else
        log "WARNING: could not archive the upload directories"
    fi
fi

# ---------------------------------------------------------------------------
# Retention — dense recent history, thinned older history
# ---------------------------------------------------------------------------
# Backing up hourly answers the question "how much work can I lose?" with ONE
# HOUR instead of one day. Keeping every hourly copy for a month answers a
# question nobody asked, with 720 files and a full disk — and a full disk on
# shared hosting stops the INSERTs, which is its own way of losing data.
#
# So the copies thin out with age, the way a sensible archive does:
#
#   younger than BACKUP_DENSE_HOURS   every copy is kept
#   older than that                   only every Nth hour of the day survives
#   older than BACKUP_KEEP_DAYS       nothing survives
#
# BACKUP_KEEP_EVERY=12 keeps the 00:xx and 12:xx copies — two a day, which is
# what "keep every 12th backup" means when the job runs hourly. Set it to 1 to
# keep them all (the behaviour before this existed), or 24 for one a day.
#
# The HOUR IS READ FROM THE FILENAME, not from the file's timestamp: the name
# carries YYYYMMDD_HHMMSS at the moment the dump was taken, and that is the
# thing being reasoned about. A copy touched by a later rsync would otherwise
# masquerade as a different hour.
#
# Only GOOD backups are rotated away. A .FAILED file is evidence of a run that
# did not work and is left for a human to look at.
BACKUP_DENSE_HOURS="$(env_value BACKUP_DENSE_HOURS)"; BACKUP_DENSE_HOURS="${BACKUP_DENSE_HOURS:-48}"
BACKUP_KEEP_EVERY="$(env_value BACKUP_KEEP_EVERY)"; BACKUP_KEEP_EVERY="${BACKUP_KEEP_EVERY:-1}"

# Anything past the outer limit goes, whatever hour it was taken at.
find "$BACKUP_DIR" -maxdepth 1 -type f -name "${DB_NAME}_*.sql.gz*" -mtime "+$BACKUP_KEEP_DAYS" -print -delete >>"$LOG" 2>&1
# The file archives age out on the same schedule, so a dump and its files are
# never kept apart — half a backup restores to a shop with broken documents.
find "$BACKUP_DIR" -maxdepth 1 -type f -name "${DB_NAME}_files_*.tar.gz*" -mtime "+$BACKUP_KEEP_DAYS" -print -delete >>"$LOG" 2>&1
# Evidence from earlier failures is bounded here too, not only when a run
# fails: the pile that fills the disk is the one left behind by a run that has
# since started working again, and nobody comes back to sweep it up.
prune_failed_artifacts

if [ "$BACKUP_KEEP_EVERY" -gt 1 ] 2>/dev/null; then
    # The cutoff in seconds since the epoch. Everything newer is untouchable.
    dense_cutoff=$(( $(date +%s) - BACKUP_DENSE_HOURS * 3600 ))
    thinned=0
    for old in "$BACKUP_DIR/${DB_NAME}_"*.sql.gz* "$BACKUP_DIR/${DB_NAME}_files_"*.tar.gz*; do
        [ -f "$old" ] || continue
        case "$old" in *.FAILED*) continue ;; esac

        # ..._YYYYMMDD_HHMMSS.ext  ->  YYYYMMDD HH
        base="$(basename "$old")"
        stamp="$(printf '%s\n' "$base" | grep -oE '[0-9]{8}_[0-9]{6}' | head -1)"
        [ -n "$stamp" ] || continue
        day="${stamp%_*}"
        hour="${stamp#*_}"; hour="${hour:0:2}"

        # Its age, from the name. `date -d` is GNU; if it is not available the
        # file is left alone rather than deleted on a guess.
        taken="$(date -d "${day} ${hour}:00:00" +%s 2>/dev/null)" || continue
        [ -n "$taken" ] || continue
        [ "$taken" -lt "$dense_cutoff" ] || continue

        # 10#$hour so 08 and 09 are not read as invalid octal.
        if [ $(( 10#$hour % BACKUP_KEEP_EVERY )) -ne 0 ]; then
            rm -f -- "$old" && thinned=$((thinned + 1))
        fi
    done
    [ "$thinned" -gt 0 ] && log "thinned $thinned old copies (keeping every ${BACKUP_KEEP_EVERY}h beyond ${BACKUP_DENSE_HOURS}h)"
fi

# ---------------------------------------------------------------------------
# Prune the append-only log tables — ONLY after a verified backup
# ---------------------------------------------------------------------------
# security_events gains a row per login attempt and activity_logs a row per
# action, forever; on shared hosting they walk straight into the disk quota,
# at which point INSERTs fail and posting transactions fail with them. They
# are pruned HERE, after the dump has been verified, so every row deleted
# tonight exists in the backup written tonight — the audit trail moves into
# the archive, it does not vanish.
#
#   LOG_RETENTION_SECURITY_DAYS  in .env, default 180; 0 keeps forever
#   LOG_RETENTION_ACTIVITY_DAYS  in .env, default 400; 0 keeps forever
#
# Batched DELETEs so the table is never locked for long on a live shop, and
# every failure is a warning — housekeeping must never fail the backup.
RET_SECURITY="$(env_value LOG_RETENTION_SECURITY_DAYS)"; RET_SECURITY="${RET_SECURITY:-180}"
RET_ACTIVITY="$(env_value LOG_RETENTION_ACTIVITY_DAYS)"; RET_ACTIVITY="${RET_ACTIVITY:-400}"

prune_table() {
    TABLE="$1"; DAYS="$2"
    case "$DAYS" in (*[!0-9]*|'') log "WARNING: retention for $TABLE is not a number ('$DAYS') — skipped"; return 0;; esac
    [ "$DAYS" -eq 0 ] && { log "$TABLE: retention 0 — kept forever"; return 0; }
    if ! command -v mysql >/dev/null 2>&1; then
        log "WARNING: mysql client not found — $TABLE not pruned"
        return 0
    fi
    PRUNED_TOTAL=0
    while :; do
        ROWS="$(MYSQL_PWD="$DB_PASS" mysql --host="$DB_HOST" --user="$DB_USER" -N -e \
            "DELETE FROM \`$TABLE\` WHERE created_at < NOW() - INTERVAL $DAYS DAY LIMIT 5000; SELECT ROW_COUNT();" \
            "$DB_NAME" 2>>"$LOG")" || { log "WARNING: pruning $TABLE failed — see log"; return 0; }
        ROWS="$(printf '%s' "$ROWS" | tr -dc '0-9')"
        PRUNED_TOTAL=$((PRUNED_TOTAL + ${ROWS:-0}))
        [ "${ROWS:-0}" -lt 5000 ] && break
    done
    log "$TABLE: pruned $PRUNED_TOTAL row(s) older than $DAYS days (all inside tonight's verified dump)"
}

prune_table security_events "$RET_SECURITY"
prune_table activity_logs "$RET_ACTIVITY"

REMAINING="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name "${DB_NAME}_*.sql.gz*" | wc -l | tr -d ' ')"
log "done — $REMAINING backup(s) held, keeping $BACKUP_KEEP_DAYS days"
write_status "ok" "$REMAINING held, $TABLES tables"

tail -n 500 "$LOG" > "$LOG.tmp" 2>/dev/null && mv "$LOG.tmp" "$LOG"
exit 0
