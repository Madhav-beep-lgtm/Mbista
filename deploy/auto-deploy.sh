#!/bin/bash
# Auto-deploy for cPanel: pulls the latest main branch from GitHub and
# re-runs the deployment tasks listed in .cpanel.yml, so the cron deploy
# and cPanel's own "Deploy HEAD Commit" button always do the same thing.
#
# Set it up once in cPanel -> Advanced -> Cron Jobs:
#
#   Schedule: */5 * * * *   (once per five minutes)
#   Command:  /bin/bash /home/YOUR_CPANEL_USER/repositories/Mbista/deploy/auto-deploy.sh >/dev/null 2>&1
#
# Replace the path with your actual repository path, shown in
# cPanel -> Git Version Control -> Manage -> Repository Path.
#
# The script exits silently when there is nothing new. Activity is logged
# to ~/auto-deploy.log (kept to the last 400 lines).

set -u

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG="$HOME/auto-deploy.log"

cd "$REPO_DIR" || exit 1

# A manual run always deploys, whatever the state file says. Somebody typing
# this at a prompt has a reason, and "it exited without doing anything and
# without saying why" is the least useful answer a deploy script can give.
FORCE=0
case "${1:-}" in
    -f|--force) FORCE=1 ;;
esac

# Never let two runs overlap (a slow deploy must not collide with the next tick).
#
# The lock is released by the EXIT trap, which does NOT run if the process is
# killed outright — and a shared host kills things. A leftover lock directory
# then turns every future run into a silent exit 0, for good: the cron keeps
# firing every five minutes, the log stays empty, and the site quietly stops
# receiving deploys. So the lock expires. Thirty minutes is far longer than a
# deploy takes and short enough that one killed run costs at most one cycle.
LOCK="$HOME/.auto-deploy.lock"
if ! mkdir "$LOCK" 2>/dev/null; then
    if [ -z "$(find "$LOCK" -maxdepth 0 -mmin -30 2>/dev/null)" ]; then
        echo "$(date '+%F %T') NOTE: clearing a stale lock (previous run was killed)" >> "$LOG"
        rmdir "$LOCK" 2>/dev/null || true
        mkdir "$LOCK" 2>/dev/null || exit 0
    else
        [ -t 1 ] && echo "auto-deploy: another run is in progress; exiting."
        exit 0
    fi
fi
trap 'rmdir "$LOCK"' EXIT

if ! git fetch origin main --quiet 2>>"$LOG"; then
    echo "$(date '+%F %T') ERROR: git fetch failed" >> "$LOG"
    exit 1
fi

if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
    if ! git merge --ff-only origin/main >>"$LOG" 2>&1; then
        echo "$(date '+%F %T') ERROR: fast-forward failed - the server copy has local changes. Fix with: cd $REPO_DIR && git status" >> "$LOG"
        exit 1
    fi
fi

# Deploy whenever the checked-out commit differs from the last one this
# script deployed (tracked in a state file). This also covers the very
# first run and pulls done via the cPanel UI.
STATE="$HOME/.auto-deploy.last"
CURRENT_HASH="$(git rev-parse HEAD)"
LAST_DEPLOYED="$(cat "$STATE" 2>/dev/null || echo none)"
if [ "$CURRENT_HASH" = "$LAST_DEPLOYED" ] && [ "$FORCE" -eq 0 ]; then
    [ -t 1 ] && echo "auto-deploy: $(git rev-parse --short HEAD) is already deployed; nothing to do. Re-run with --force to copy the files again."
    exit 0
fi

echo "$(date '+%F %T') deploying $CURRENT_HASH" >> "$LOG"

# Run the shared deployment tasks (same script .cpanel.yml uses).
#
# THE STATE FILE IS ONLY WRITTEN WHEN THE TASKS SUCCEED.
#
# It used to be written either way, one line below the warning, and that is
# how the site sat on a fortnight-old stylesheet while every check said the
# deploy was fine: one failed run recorded its commit as deployed, so every
# run after it matched the state file and exited before doing any work. The
# repository was current, the docroot was not, and nothing anywhere said so.
# A deploy that did not happen must not be remembered as one that did — then
# the next tick simply tries again, which is the behaviour anyone would
# assume a five-minute cron already had.
if bash deploy/tasks.sh >>"$LOG" 2>&1; then
    echo "$CURRENT_HASH" > "$STATE"
    echo "$(date '+%F %T') deployed $(git rev-parse --short HEAD)" >> "$LOG"
    [ -t 1 ] && echo "auto-deploy: deployed $(git rev-parse --short HEAD)."
else
    echo "$(date '+%F %T') ERROR: deploy tasks failed for $CURRENT_HASH — NOT recorded as deployed; the next run will retry. Reason above." >> "$LOG"
    [ -t 1 ] && echo "auto-deploy: deploy FAILED — see $LOG. The next run will retry."
    exit 1
fi

tail -n 400 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
