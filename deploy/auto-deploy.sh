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

# Never let two runs overlap (a slow deploy must not collide with the next tick).
LOCK="$HOME/.auto-deploy.lock"
if ! mkdir "$LOCK" 2>/dev/null; then
    exit 0
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
if [ "$CURRENT_HASH" = "$LAST_DEPLOYED" ]; then
    exit 0
fi

echo "$(date '+%F %T') deploying $CURRENT_HASH" >> "$LOG"

# Run every task from .cpanel.yml (the lines starting with "- "), so the
# task list lives in exactly one file.
grep -E '^[[:space:]]+- ' .cpanel.yml | sed -E 's/^[[:space:]]+- //' | while IFS= read -r task; do
    if ! eval "$task" >>"$LOG" 2>&1; then
        echo "$(date '+%F %T') WARNING: task failed: $task" >> "$LOG"
    fi
done

echo "$CURRENT_HASH" > "$STATE"
echo "$(date '+%F %T') deployed $(git rev-parse --short HEAD)" >> "$LOG"

tail -n 400 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
