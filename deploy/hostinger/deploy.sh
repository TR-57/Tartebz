#!/usr/bin/env bash
# Deploys Tartebz on Hostinger (Business / Cloud plans: SSH, Git and Composer included).
# Run over SSH from the app folder:   bash deploy/hostinger/deploy.sh
#
# Layout on the server:
#   ~/domains/<domain>/tartebz-app/      this repository (code, backend, .env)   – NOT web-visible
#   ~/domains/<domain>/public_html/      storefront pages, /admin, /uploads, /img, api.php, .htaccess
set -euo pipefail
APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
PUBLIC="${PUBLIC_HTML:-$(cd "$APP_DIR/.." && pwd)/public_html}"
PHP="${PHP_BIN:-php}"
echo "App:    $APP_DIR"
echo "Public: $PUBLIC"

cd "$APP_DIR"
PREV=$(git rev-parse --short HEAD); git pull --ff-only; echo "Updated $PREV -> $(git rev-parse --short HEAD) (roll back: git checkout $PREV && bash deploy/hostinger/deploy.sh)"

cd backend
[ -f .env ] || { echo "Create backend/.env first (copy .env.example and fill it in)."; exit 1; }
sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env
composer install --no-dev --optimize-autoloader --no-interaction
"$PHP" artisan down --retry=30 || true
"$PHP" artisan migrate --force
"$PHP" artisan up
"$PHP" artisan config:cache
"$PHP" artisan route:cache
cd "$APP_DIR"

# Public files: pages, assets, admin. uploads/ and img/ are never touched.
mkdir -p "$PUBLIC/uploads" "$PUBLIC/img"
for f in *.html; do cp -f "$f" "$PUBLIC/$f"; done
rsync -a --delete assets/ "$PUBLIC/assets/"
rsync -a --delete admin/ "$PUBLIC/admin/"
cp -f deploy/hostinger/htaccess.public_html "$PUBLIC/.htaccess"
cp -f deploy/hostinger/htaccess.uploads "$PUBLIC/uploads/.htaccess"
sed "s#__APP_DIR__#$APP_DIR#" deploy/hostinger/api.php.template > "$PUBLIC/api.php"
echo "Done. Cron (hPanel > Advanced > Cron Jobs, every minute):"
echo "  $PHP $APP_DIR/backend/artisan schedule:run >> /dev/null 2>&1"
