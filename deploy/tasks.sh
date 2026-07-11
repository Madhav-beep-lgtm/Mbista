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

# Never overwrite server-side user uploads; never ship local dev uploads.
rsync -a --exclude=uploads/ --exclude=assets/uploads/ public_html/ "$DOCROOT/"
mkdir -p "$DOCROOT/uploads" "$DOCROOT/assets/uploads"
cp -f public_html/uploads/.htaccess "$DOCROOT/uploads/.htaccess"
cp -f public_html/uploads/.htaccess "$DOCROOT/assets/uploads/.htaccess"

rsync -a app/ "$APP_BASE/app/"
rsync -a database/ "$APP_BASE/database/"
cp -f secure_uploads/kyc/.htaccess "$APP_BASE/secure_uploads/kyc/.htaccess"

# Provide the .env template; the real .env is created once by hand and never touched.
cp -f .env.example "$APP_BASE/.env.example"

echo "deploy: done"
