#!/bin/bash
# The actual deployment tasks, shared by cPanel's "Deploy HEAD Commit"
# button (via .cpanel.yml) and the cron script deploy/auto-deploy.sh.
#
# Detects the web document root automatically so the same repo deploys
# correctly whether mbca.com.np is the account's main domain
# (docroot ~/public_html) or an addon domain (docroot ~/mbca.com.np or
# ~/public_html/mbca.com.np). app/, database/, secure_uploads/ and the
# .env template always land one level ABOVE the docroot, which is what
# app/bootstrap.php expects.

set -u
cd "$(dirname "$0")/.." || exit 1

# A step that fails must stop the deploy and say so.
#
# This script had `set -u` but no `set -e`, and it ends on an echo — so its
# exit status was "success" no matter what happened in between. A failed rsync
# printed its complaint into the log and the deploy still reported "done".
# Copy steps are checked explicitly rather than with `set -e`, because what
# follows them (the crontab install) is deliberately allowed to fail.
die() {
    echo "deploy: ERROR $*" >&2
    exit 1
}

# ---------------------------------------------------------------------------
# Which directory is the document root
# ---------------------------------------------------------------------------
# This used to take the FIRST candidate that existed, preferring the addon
# domain directories over ~/public_html. An empty ~/mbca.com.np — which cPanel
# will create just for adding a domain — was therefore enough to redirect
# every web file away from the site that was actually being served, while
# app/ and database/ carried on landing correctly one level above. The result
# is the most confusing shape a broken deploy can take: PHP changes go live,
# stylesheets and images do not, rsync reports success, and the site sits on
# whatever assets it had the last time the guess happened to be right.
#
# So existence is no longer the test. A directory that ALREADY holds the site
# wins over one that merely exists, ~/public_html is preferred when more than
# one qualifies, and the choice is printed with its reason. An account whose
# real root is somewhere else can say so outright and skip the guessing.
CANDIDATES="$HOME/public_html
$HOME/mbca.com.np
$HOME/public_html/mbca.com.np"

looks_deployed() { [ -f "$1/assets/css/style.css" ] || [ -f "$1/index.php" ]; }

DOCROOT=""
DOCROOT_WHY=""
if [ -n "${DEPLOY_DOCROOT:-}" ]; then
    DOCROOT="$DEPLOY_DOCROOT"
    DOCROOT_WHY="DEPLOY_DOCROOT is set"
elif [ -f "$HOME/.deploy-docroot" ]; then
    DOCROOT="$(head -1 "$HOME/.deploy-docroot" | tr -d '\r')"
    DOCROOT_WHY="named in ~/.deploy-docroot"
else
    while IFS= read -r CANDIDATE; do
        [ -n "$CANDIDATE" ] && [ -d "$CANDIDATE" ] || continue
        if looks_deployed "$CANDIDATE"; then
            DOCROOT="$CANDIDATE"
            DOCROOT_WHY="it already holds the site"
            break
        fi
    done <<CANDIDATE_LIST
$CANDIDATES
CANDIDATE_LIST
fi
if [ -z "$DOCROOT" ]; then
    while IFS= read -r CANDIDATE; do
        [ -n "$CANDIDATE" ] && [ -d "$CANDIDATE" ] || continue
        DOCROOT="$CANDIDATE"
        DOCROOT_WHY="first existing candidate; no directory holds a site yet"
        break
    done <<CANDIDATE_LIST
$CANDIDATES
CANDIDATE_LIST
fi
[ -n "$DOCROOT" ] || { DOCROOT="$HOME/public_html"; DOCROOT_WHY="default"; }

APP_BASE="$(dirname "$DOCROOT")"
echo "deploy: web files -> $DOCROOT ($DOCROOT_WHY)"
echo "deploy: app/database -> $APP_BASE"

# Any OTHER directory that also holds a site is reported. If the browser still
# shows old files after this deploy, one of these is the real document root
# and belongs in ~/.deploy-docroot.
while IFS= read -r CANDIDATE; do
    [ -n "$CANDIDATE" ] && [ "$CANDIDATE" != "$DOCROOT" ] || continue
    if looks_deployed "$CANDIDATE"; then
        echo "deploy: WARNING $CANDIDATE also holds a site and is NOT being written to."
        echo "deploy:   If the browser still shows old files: echo '$CANDIDATE' > ~/.deploy-docroot"
    fi
done <<CANDIDATE_LIST
$CANDIDATES
CANDIDATE_LIST

mkdir -p "$DOCROOT" "$APP_BASE/app" "$APP_BASE/database" "$APP_BASE/secure_uploads/kyc"

# What must never be copied into the web root.
#
# Apache runs .php but SERVES foo.php.bak as plain text, so one forgotten
# backup publishes the whole file to anyone who guesses the name. Thirteen of
# them had been deployed this way. The same goes for editor swap files and
# anything a merge left behind.
NEVER_PUBLISH=(
    --exclude='*.bak' --exclude='*.old' --exclude='*.orig' --exclude='*.save'
    --exclude='*.swp' --exclude='*.swo' --exclude='*~'
    --exclude='*.rej' --exclude='.DS_Store' --exclude='Thumbs.db'
)

# Never overwrite server-side user uploads; never ship local dev uploads.
rsync -a --exclude=uploads/ --exclude=assets/uploads/ "${NEVER_PUBLISH[@]}" public_html/ "$DOCROOT/" \
    || die "could not copy the web files into $DOCROOT (see the rsync message above)"
mkdir -p "$DOCROOT/uploads" "$DOCROOT/assets/uploads"
cp -f public_html/uploads/.htaccess "$DOCROOT/uploads/.htaccess" \
    || die "could not install $DOCROOT/uploads/.htaccess — uploads would be served unprotected"
cp -f public_html/uploads/.htaccess "$DOCROOT/assets/uploads/.htaccess" \
    || die "could not install $DOCROOT/assets/uploads/.htaccess — uploads would be served unprotected"

rsync -a "${NEVER_PUBLISH[@]}" app/ "$APP_BASE/app/" \
    || die "could not copy app/ into $APP_BASE/app/"
rsync -a "${NEVER_PUBLISH[@]}" database/ "$APP_BASE/database/" \
    || die "could not copy database/ into $APP_BASE/database/"

# Apply idempotent schema upgrades once per deployment. The repair function
# deliberately does no work during web requests, so ordinary page loads never
# pay for hundreds of information_schema checks. A failed upgrade stops the
# deployment before its commit is recorded as live, allowing the next cron run
# to retry safely.
if ! php -r '
    require $argv[1] . "/app/bootstrap.php";
    require $argv[1] . "/app/accounting_module_repair.php";
    $errors = accounting_module_repair_database();
    if ($errors !== []) {
        fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
        exit(1);
    }
' "$APP_BASE"; then
    die "database schema repair failed"
fi
echo "deploy: database schema is current"

# Prove the copy actually landed, rather than trusting that it did.
#
# Everything above can report success and still leave the served files
# untouched — a read-only target, a docroot that is not the one Apache uses, a
# stale NFS handle. Comparing one real file end to end is cheap and catches
# all three, and it is the check that would have caught a fortnight of deploys
# that changed nothing.
SENTINEL="assets/css/portal.css"
if [ -f "public_html/$SENTINEL" ]; then
    if ! cmp -s "public_html/$SENTINEL" "$DOCROOT/$SENTINEL"; then
        die "$DOCROOT/$SENTINEL does not match the repository copy after rsync — the deploy did not take effect"
    fi
    echo "deploy: verified $SENTINEL matches the repository ($(wc -c < "$DOCROOT/$SENTINEL") bytes)"
fi
cp -f secure_uploads/kyc/.htaccess "$APP_BASE/secure_uploads/kyc/.htaccess"

# Provide the .env template; the real .env is created once by hand and never touched.
cp -f .env.example "$APP_BASE/.env.example"

# ---------------------------------------------------------------------------
# The nightly backup installs ITSELF.
#
# backup-database.sh was written to be armed once by hand in cPanel -> Cron
# Jobs. It never was — the app's own banner reported "no database backup has
# ever reported in" for weeks, which is exactly the failure that banner exists
# to catch, and exactly the kind of one-time manual step that never gets done.
#
# A deploy already runs inside the account with the user's crontab in reach,
# so it arms the job itself. Idempotent by grep: the line goes in once, and
# re-deploying finds it and leaves it alone. A crontab that cannot be read or
# written (some hosts forbid it) is REPORTED, not fatal — a deploy must not
# fail over a scheduling nicety, and the banner keeps nagging until it works.
# ---------------------------------------------------------------------------
BACKUP_SCRIPT="$(pwd)/deploy/backup-database.sh"
CRON_LINE="15 2 * * * /bin/bash $BACKUP_SCRIPT >/dev/null 2>&1"

if command -v crontab >/dev/null 2>&1; then
    CURRENT_CRON="$(crontab -l 2>/dev/null || true)"
    if printf '%s\n' "$CURRENT_CRON" | grep -qF 'deploy/backup-database.sh'; then
        echo "deploy: nightly backup cron already installed"
    elif printf '%s\n%s\n' "$CURRENT_CRON" "$CRON_LINE" | grep -v '^[[:space:]]*$' | crontab - 2>/dev/null; then
        echo "deploy: nightly backup cron installed (02:15 daily)"
    else
        echo "deploy: WARNING could not write crontab — set the nightly backup up by hand:"
        echo "deploy:   $CRON_LINE"
    fi
else
    echo "deploy: WARNING no crontab command — set the nightly backup up by hand:"
    echo "deploy:   $CRON_LINE"
fi

# Where the backup writes, kept out of the web root and readable only by the
# account: a dump is every row in the business, and a world-readable one in a
# guessable place is the whole database published.
mkdir -p "$HOME/db-backups"
chmod 700 "$HOME/db-backups" 2>/dev/null || true

# ---------------------------------------------------------------------------
# The Node API, on accounts that run one
# ---------------------------------------------------------------------------
# Passenger — what cPanel's "Setup Node.js App" runs behind — starts the app
# once and keeps that process alive, so a git pull changes the FILES and nothing
# else. The running copy goes on serving the old code until it is told, and
# "the fix is deployed but the API still does the old thing" is a long
# afternoon with nothing to show for it. Touching tmp/restart.txt is how
# Passenger is told; it picks the change up on the next request.
#
# Guarded on node_modules actually being there, so an account with no Node app
# does nothing at all rather than litter the checkout with a tmp directory it
# will never read.
if [ -f "$(pwd)/index.js" ] && [ -d "$(pwd)/node_modules" ]; then
    mkdir -p "$(pwd)/tmp"
    touch "$(pwd)/tmp/restart.txt"
    echo "deploy: signalled the Node API to restart (tmp/restart.txt)"
else
    echo "deploy: no Node API installed here — skipping its restart"
fi

echo "deploy: done"
