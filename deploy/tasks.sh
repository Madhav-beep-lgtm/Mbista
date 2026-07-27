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

DOCROOT="$HOME/public_html"
if [ -d "$HOME/mbca.com.np" ]; then
    DOCROOT="$HOME/mbca.com.np"
elif [ -d "$HOME/public_html/mbca.com.np" ]; then
    DOCROOT="$HOME/public_html/mbca.com.np"
fi
APP_BASE="$(dirname "$DOCROOT")"
echo "deploy: web files -> $DOCROOT ; app/database -> $APP_BASE"

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
rsync -a --exclude=uploads/ --exclude=assets/uploads/ "${NEVER_PUBLISH[@]}" public_html/ "$DOCROOT/"
mkdir -p "$DOCROOT/uploads" "$DOCROOT/assets/uploads"
cp -f public_html/uploads/.htaccess "$DOCROOT/uploads/.htaccess"
cp -f public_html/uploads/.htaccess "$DOCROOT/assets/uploads/.htaccess"

rsync -a "${NEVER_PUBLISH[@]}" app/ "$APP_BASE/app/"
rsync -a "${NEVER_PUBLISH[@]}" database/ "$APP_BASE/database/"
cp -f secure_uploads/kyc/.htaccess "$APP_BASE/secure_uploads/kyc/.htaccess"

# Provide the .env template; the real .env is created once by hand and never touched.
cp -f .env.example "$APP_BASE/.env.example"

echo "deploy: done"
