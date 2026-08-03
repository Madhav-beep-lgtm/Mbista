#!/bin/bash
# Proves the two deploy scripts against a throwaway HOME and a throwaway git
# repository, running the REAL deploy/tasks.sh and deploy/auto-deploy.sh — not
# copies of the interesting lines, which is the only way a test like this
# stays true after somebody edits the scripts.
#
# It exists because of a specific failure. The repository on the server sat at
# the right commit, `git log` agreed, the deploy script had been run by hand —
# and the website served a fortnight-old stylesheet. Two silences caused it:
# tasks.sh returned success no matter what happened inside it, and auto-deploy
# recorded a commit as deployed even when the tasks had failed, so every later
# run saw the state file, decided there was nothing to do, and exited 0.
#
#   bash database/test_deploy_tasks.sh
set -uo pipefail

REPO_SRC="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="$(mktemp -d)"
trap 'rm -rf "$ROOT"' EXIT

pass=0; fail=0
ok()   { pass=$((pass+1)); echo "  PASS  $1"; }
bad()  { fail=$((fail+1)); echo "  FAIL  $1"; }
check(){ if [ "$1" = "1" ]; then ok "$2"; else bad "$2"; fi; }

mkdir -p "$ROOT/bin"

# rsync, where there is no rsync.
#
# Windows has none, so without this the whole suite skipped on the machine the
# code is written on and only ever ran on the server — which is to say, never.
# The stand-in covers exactly the flags tasks.sh passes (-a and --exclude=) by
# handing the work to tar, and it fails when the destination cannot be written,
# which is the case the failure tests turn on. What it does NOT prove is
# rsync's own matching rules; run this on the server for that.
SHIM=0
if ! command -v rsync >/dev/null 2>&1; then
    SHIM=1
    cat > "$ROOT/bin/rsync" <<'SHIMEOF'
#!/bin/bash
# Minimal stand-in: rsync -a [--exclude=PAT]... SRC/ DEST/
set -u
excl=(); args=()
for a in "$@"; do
    case "$a" in
        --exclude=*) p="${a#--exclude=}"; excl+=( "--exclude=${p%/}" ) ;;
        -*)          ;;
        *)           args+=( "$a" ) ;;
    esac
done
n=${#args[@]}
[ "$n" -ge 2 ] || { echo "rsync-shim: need SRC and DEST" >&2; exit 2; }
SRC="${args[$((n-2))]}"; DEST="${args[$((n-1))]}"
mkdir -p "$DEST" 2>/dev/null || { echo "rsync-shim: cannot create $DEST" >&2; exit 11; }
[ -d "$DEST" ] || { echo "rsync-shim: $DEST is not a directory" >&2; exit 11; }
tar -C "${SRC%/}" "${excl[@]}" -cf - . 2>/dev/null | tar -C "$DEST" -xf - || exit 12
SHIMEOF
    chmod +x "$ROOT/bin/rsync"
    echo "NOTE: no rsync here — using a tar-based stand-in. Run this on the server for the real thing."
    echo
fi

# A fake crontab on PATH. tasks.sh installs the nightly backup job into the
# real user crontab, and a test suite must never touch that.
cat > "$ROOT/bin/crontab" <<'FAKE'
#!/bin/bash
if [ "${1:-}" = "-l" ]; then [ -f "$FAKE_CRONTAB" ] && cat "$FAKE_CRONTAB"; exit 0; fi
if [ "${1:-}" = "-" ]; then cat > "$FAKE_CRONTAB"; exit 0; fi
exit 0
FAKE
chmod +x "$ROOT/bin/crontab"
export PATH="$ROOT/bin:$PATH"
export FAKE_CRONTAB="$ROOT/crontab.txt"

# A minimal repository with the shape tasks.sh expects, and the real scripts.
make_repo() {
    local dir="$1"
    mkdir -p "$dir/deploy" "$dir/public_html/assets/css" "$dir/public_html/uploads" \
             "$dir/app" "$dir/database" "$dir/secure_uploads/kyc"
    cp "$REPO_SRC/deploy/tasks.sh" "$dir/deploy/tasks.sh"
    cp "$REPO_SRC/deploy/auto-deploy.sh" "$dir/deploy/auto-deploy.sh"
    cp "$REPO_SRC/deploy/backup-database.sh" "$dir/deploy/backup-database.sh" 2>/dev/null || true
    echo '<?php echo "site";' > "$dir/public_html/index.php"
    echo 'body { color: red }' > "$dir/public_html/assets/css/portal.css"
    echo 'Deny from all' > "$dir/public_html/uploads/.htaccess"
    echo 'Deny from all' > "$dir/secure_uploads/kyc/.htaccess"
    echo '<?php // app' > "$dir/app/bootstrap.php"
    echo '<?php // db' > "$dir/database/schema.php"
    echo 'APP_ENV=production' > "$dir/.env.example"
}

echo "== tasks.sh =="

# ---------------------------------------------------------------- happy path
H="$ROOT/h1"; mkdir -p "$H"
R="$ROOT/r1"; make_repo "$R"
out="$(HOME="$H" bash "$R/deploy/tasks.sh" 2>&1)"; rc=$?

check "$([ $rc -eq 0 ] && echo 1 || echo 0)" "A normal deploy exits 0"
check "$([ -f "$H/public_html/assets/css/portal.css" ] && echo 1 || echo 0)" "The stylesheet reaches the document root"
check "$(cmp -s "$R/public_html/assets/css/portal.css" "$H/public_html/assets/css/portal.css" && echo 1 || echo 0)" "and it is byte-for-byte the repository copy"
check "$([ -f "$H/app/bootstrap.php" ] && echo 1 || echo 0)" "app/ lands one level ABOVE the document root"
check "$([ ! -f "$H/public_html/app/bootstrap.php" ] && echo 1 || echo 0)" "and never inside it, where Apache would serve it"
check "$([ -f "$H/public_html/uploads/.htaccess" ] && echo 1 || echo 0)" "uploads/.htaccess is installed"
check "$(printf '%s' "$out" | grep -q 'verified assets/css/portal.css' && echo 1 || echo 0)" "The deploy states that it verified the copy"
check "$(grep -q 'backup-database.sh' "$FAKE_CRONTAB" 2>/dev/null && echo 1 || echo 0)" "The nightly backup cron is armed"

# Re-running must not duplicate the cron line.
HOME="$H" bash "$R/deploy/tasks.sh" >/dev/null 2>&1
n="$(grep -c 'backup-database.sh' "$FAKE_CRONTAB" 2>/dev/null || echo 0)"
check "$([ "$n" -eq 1 ] && echo 1 || echo 0)" "Deploying twice leaves exactly one cron line, not $n"

# ------------------------------------------------- a copy that cannot happen
# THE CENTRAL CASE. Before the fix this printed rsync's complaint and then
# "deploy: done", and exited 0.
H="$ROOT/h2"; mkdir -p "$H"
: > "$H/public_html"          # a FILE where the document root should be
R="$ROOT/r2"; make_repo "$R"
out="$(HOME="$H" bash "$R/deploy/tasks.sh" 2>&1)"; rc=$?
check "$([ $rc -ne 0 ] && echo 1 || echo 0)" "A deploy that cannot copy its files exits NON-zero"
check "$(printf '%s' "$out" | grep -q 'ERROR' && echo 1 || echo 0)" "and says ERROR"
check "$(printf '%s' "$out" | grep -q 'deploy: done' && echo 0 || echo 1)" "and does NOT go on to report 'done'"

# ------------------------------------------------ two candidate docroots
#
# THE ONE THAT BROKE THE SITE. An empty ~/mbca.com.np used to beat the
# ~/public_html that was actually being served, purely by existing, so every
# stylesheet went to a directory no request ever reached while app/ kept
# landing correctly — PHP live, assets frozen, rsync reporting success.
H="$ROOT/h3"; mkdir -p "$H/public_html" "$H/mbca.com.np"
echo '<?php // the site Apache actually serves' > "$H/public_html/index.php"
R="$ROOT/r3"; make_repo "$R"
out="$(HOME="$H" bash "$R/deploy/tasks.sh" 2>&1)"
check "$([ -f "$H/public_html/assets/css/portal.css" ] && echo 1 || echo 0)" "The directory that already HOLDS the site wins over one that merely exists"
check "$([ ! -f "$H/mbca.com.np/assets/css/portal.css" ] && echo 1 || echo 0)" "and the empty candidate is left alone"
check "$(printf '%s' "$out" | grep -q "already holds the site" && echo 1 || echo 0)" "The deploy says which root it chose and why"

# An addon domain that really is the site still wins — the fix must not
# hard-code ~/public_html.
H="$ROOT/h3b"; mkdir -p "$H/public_html" "$H/mbca.com.np"
echo '<?php // addon domain is the real site here' > "$H/mbca.com.np/index.php"
R="$ROOT/r3b"; make_repo "$R"
out="$(HOME="$H" bash "$R/deploy/tasks.sh" 2>&1)"
check "$([ -f "$H/mbca.com.np/assets/css/portal.css" ] && echo 1 || echo 0)" "An addon-domain root that holds the site is still chosen"

# Both hold a site: one is picked, the other is REPORTED rather than ignored.
H="$ROOT/h3c"; mkdir -p "$H/public_html" "$H/mbca.com.np"
echo '<?php' > "$H/public_html/index.php"; echo '<?php' > "$H/mbca.com.np/index.php"
R="$ROOT/r3c"; make_repo "$R"
out="$(HOME="$H" bash "$R/deploy/tasks.sh" 2>&1)"
check "$(printf '%s' "$out" | grep -q "WARNING $H/mbca.com.np also holds a site" && echo 1 || echo 0)" "A second live candidate is reported, with the command to switch to it"

# ~/.deploy-docroot overrides the guessing entirely.
H="$ROOT/h3d"; mkdir -p "$H/public_html" "$H/webroot"
echo '<?php' > "$H/public_html/index.php"
echo "$H/webroot" > "$H/.deploy-docroot"
R="$ROOT/r3d"; make_repo "$R"
out="$(HOME="$H" bash "$R/deploy/tasks.sh" 2>&1)"
check "$([ -f "$H/webroot/assets/css/portal.css" ] && echo 1 || echo 0)" "~/.deploy-docroot is obeyed over anything the detection would pick"
check "$([ ! -f "$H/public_html/assets/css/portal.css" ] && echo 1 || echo 0)" "and the guessed root is then left untouched"

echo
echo "== auto-deploy.sh =="

if ! command -v git >/dev/null 2>&1; then
    echo "  SKIPPED: no git."
else
    export GIT_CONFIG_GLOBAL="$ROOT/gitconfig"
    printf '[core]\n\tautocrlf = false\n' > "$GIT_CONFIG_GLOBAL"
    export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t

    git init --bare -q "$ROOT/origin.git"
    # The bare repo's HEAD defaults to master; we push main. Without this the
    # clone below checks nothing out, and an empty checkout makes half these
    # assertions pass for the wrong reason.
    git -C "$ROOT/origin.git" symbolic-ref HEAD refs/heads/main
    W="$ROOT/work"; make_repo "$W"
    git -C "$W" init -q -b main
    git -C "$W" add -A >/dev/null
    git -C "$W" commit -qm first
    git -C "$W" remote add origin "$ROOT/origin.git"
    git -C "$W" push -q origin main

    H="$ROOT/h4"; mkdir -p "$H"
    S="$ROOT/server"; git clone -q "$ROOT/origin.git" "$S" 2>/dev/null
    HASH="$(git -C "$S" rev-parse HEAD 2>/dev/null || echo none)"

    # Refuse to report on a checkout that is not there. Everything below reads
    # as a pass against an empty directory — a failed deploy "exits non-zero"
    # very convincingly when the script does not exist.
    if [ ! -f "$S/deploy/auto-deploy.sh" ] || [ "$HASH" = "none" ]; then
        bad "the test's own git fixture failed to build — nothing below was checked"
        echo "  $pass passed, $fail failed"
        exit 1
    fi

    out="$(HOME="$H" bash "$S/deploy/auto-deploy.sh" 2>&1)"; rc=$?
    check "$([ $rc -eq 0 ] && echo 1 || echo 0)" "The first run deploys and exits 0"
    check "$([ -f "$H/public_html/assets/css/portal.css" ] && echo 1 || echo 0)" "and the files reach the document root"
    check "$([ "$(cat "$H/.auto-deploy.last" 2>/dev/null)" = "$HASH" ] && echo 1 || echo 0)" "and the commit is recorded as deployed"

    # Quiet, and genuinely idle: the cron fires every five minutes and must not
    # narrate. "Quiet" is checked as no output AND no second deploy in the log,
    # because output alone would also be empty if it had silently redeployed.
    out="$(HOME="$H" bash "$S/deploy/auto-deploy.sh" 2>&1)"
    deploys="$(grep -c 'deploying' "$H/auto-deploy.log" 2>/dev/null || echo 0)"
    check "$([ -z "$out" ] && echo 1 || echo 0)" "A second run with nothing new prints nothing"
    check "$([ "$deploys" -eq 1 ] && echo 1 || echo 0)" "and does not deploy again (log shows $deploys, want 1)"

    # ---- THE BUG. A failing tasks.sh must not be recorded as deployed. ----
    printf '#!/bin/bash\necho "deploy: something broke" >&2\nexit 1\n' > "$S/deploy/tasks.sh"
    rm -f "$H/.auto-deploy.last"
    out="$(HOME="$H" bash "$S/deploy/auto-deploy.sh" 2>&1)"; rc=$?
    check "$([ $rc -ne 0 ] && echo 1 || echo 0)" "A failed deploy exits NON-zero"
    check "$([ ! -f "$H/.auto-deploy.last" ] && echo 1 || echo 0)" "and is NOT recorded as deployed"
    check "$(grep -q 'NOT recorded as deployed' "$H/auto-deploy.log" && echo 1 || echo 0)" "and the log says so in words"

    # The next tick therefore tries again, instead of exiting 0 for ever.
    before="$(grep -c 'deploying' "$H/auto-deploy.log" 2>/dev/null || echo 0)"
    out="$(HOME="$H" bash "$S/deploy/auto-deploy.sh" 2>&1)"
    after="$(grep -c 'deploying' "$H/auto-deploy.log" 2>/dev/null || echo 0)"
    check "$([ "$after" -gt "$before" ] && echo 1 || echo 0)" "The next run RETRIES rather than skipping ($before -> $after)"

    # ---- a lock left behind by a killed run must expire ----
    cp "$W/deploy/tasks.sh" "$S/deploy/tasks.sh"
    rm -f "$H/.auto-deploy.last"
    mkdir -p "$H/.auto-deploy.lock"
    touch -d '40 minutes ago' "$H/.auto-deploy.lock" 2>/dev/null \
        || touch -t "$(date -d '40 minutes ago' +%Y%m%d%H%M 2>/dev/null)" "$H/.auto-deploy.lock" 2>/dev/null
    out="$(HOME="$H" bash "$S/deploy/auto-deploy.sh" 2>&1)"
    check "$([ "$(cat "$H/.auto-deploy.last" 2>/dev/null)" = "$HASH" ] && echo 1 || echo 0)" "A stale lock is cleared and the deploy runs"
    check "$(grep -q 'stale lock' "$H/auto-deploy.log" && echo 1 || echo 0)" "and the log records that it was cleared"

    # A FRESH lock still blocks — two deploys must never overlap.
    rm -f "$H/.auto-deploy.last"
    mkdir -p "$H/.auto-deploy.lock"
    out="$(HOME="$H" bash "$S/deploy/auto-deploy.sh" 2>&1)"
    check "$([ ! -f "$H/.auto-deploy.last" ] && echo 1 || echo 0)" "A lock from a run still in progress is respected"
    rmdir "$H/.auto-deploy.lock"

    # ---- --force redeploys a commit already recorded ----
    HOME="$H" bash "$S/deploy/auto-deploy.sh" >/dev/null 2>&1
    rm -f "$H/public_html/assets/css/portal.css"
    HOME="$H" bash "$S/deploy/auto-deploy.sh" --force >/dev/null 2>&1
    check "$([ -f "$H/public_html/assets/css/portal.css" ] && echo 1 || echo 0)" "--force copies the files again even when the hash matches"
fi

echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
