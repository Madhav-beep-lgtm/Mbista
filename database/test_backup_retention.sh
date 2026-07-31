#!/bin/bash
# Simulates the retention block against a directory of fake dumps, so the
# thinning rule is proved before a real archive is handed to it.
set -uo pipefail
DIR="$(mktemp -d)"
DB_NAME="testdb"
LOG="$DIR/log"
BACKUP_DIR="$DIR"
BACKUP_DENSE_HOURS=48
BACKUP_KEEP_EVERY=12
log() { printf '%s\n' "$1" >> "$LOG"; }

# Five days of hourly dumps, ending now.
now=$(date +%s)
for d in 0 1 2 3 4; do
    for h in $(seq -w 0 23); do
        t=$(( now - d * 86400 ))
        day="$(date -d "@$t" +%Y%m%d)"
        touch "$DIR/${DB_NAME}_${day}_${h}0015.sql.gz"
    done
done
# A failed one, inside the thin zone, at an hour that would be culled.
old_day="$(date -d "@$(( now - 4 * 86400 ))" +%Y%m%d)"
touch "$DIR/${DB_NAME}_${old_day}_030015.sql.gz.FAILED"
before=$(ls "$DIR" | grep -c 'sql.gz')

# ---- the block under test, copied verbatim from backup-database.sh ----
dense_cutoff=$(( $(date +%s) - BACKUP_DENSE_HOURS * 3600 ))
thinned=0
for old in "$BACKUP_DIR/${DB_NAME}_"*.sql.gz* "$BACKUP_DIR/${DB_NAME}_files_"*.tar.gz*; do
    [ -f "$old" ] || continue
    case "$old" in *.FAILED*) continue ;; esac
    base="$(basename "$old")"
    stamp="$(printf '%s\n' "$base" | grep -oE '[0-9]{8}_[0-9]{6}' | head -1)"
    [ -n "$stamp" ] || continue
    day="${stamp%_*}"
    hour="${stamp#*_}"; hour="${hour:0:2}"
    taken="$(date -d "${day} ${hour}:00:00" +%s 2>/dev/null)" || continue
    [ -n "$taken" ] || continue
    [ "$taken" -lt "$dense_cutoff" ] || continue
    if [ $(( 10#$hour % BACKUP_KEEP_EVERY )) -ne 0 ]; then
        rm -f -- "$old" && thinned=$((thinned + 1))
    fi
done
# ---- end block ----

pass=0; fail=0
ok() { if [ "$1" = "1" ]; then pass=$((pass+1)); echo "  PASS  $2"; else fail=$((fail+1)); echo "  FAIL  $2"; fi; }

recent=$(ls "$DIR" | grep -E "_(0[0-9]|1[0-9]|2[0-3])0015\.sql\.gz$" | wc -l)
kept_hours="$(ls "$DIR" | grep -oE '_[0-9]{6}\.sql\.gz$' | cut -c2-3 | sort -u | tr '\n' ' ')"

# Days 0 and 1 are inside 48h: all 24 hours survive on each.
d0="$(date -d "@$now" +%Y%m%d)"; d4="$(date -d "@$(( now - 4*86400 ))" +%Y%m%d)"
[ "$(ls "$DIR" | grep -c "_${d0}_")" -eq 24 ] && ok 1 "Today keeps all 24 hourly copies" || ok 0 "Today keeps all 24 hourly copies (got $(ls "$DIR" | grep -c "_${d0}_"))"
n4="$(ls "$DIR" | grep "_${d4}_" | grep -c 'sql.gz$')"
[ "$n4" -eq 2 ] && ok 1 "A four-day-old day keeps exactly 2 (00 and 12)" || ok 0 "A four-day-old day keeps exactly 2 — got $n4"
ls "$DIR" | grep -q "_${d4}_000015" && ok 1 "The 00:00 copy is one of them" || ok 0 "The 00:00 copy is one of them"
ls "$DIR" | grep -q "_${d4}_120015" && ok 1 "The 12:00 copy is the other" || ok 0 "The 12:00 copy is the other"
ls "$DIR" | grep -q "_${d4}_030015.sql.gz.FAILED" && ok 1 "A .FAILED copy is never thinned — it is evidence" || ok 0 "A .FAILED copy is never thinned"
[ "$thinned" -gt 0 ] && ok 1 "It reports what it removed ($thinned files)" || ok 0 "It reports what it removed"
echo "  kept hours: $kept_hours"
echo "  before: $before   after: $(ls "$DIR" | grep -c 'sql.gz')"
rm -rf "$DIR"
echo "  PASS: $pass   FAIL: $fail"
[ "$fail" -eq 0 ]
