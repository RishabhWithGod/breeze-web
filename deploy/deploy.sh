#!/usr/bin/env bash
#
# Production deployment — see docs/production-deployment.md for the full
# explanation of every step. This script is intentionally conservative:
# it never runs a destructive command (no DROP/TRUNCATE, no
# `migrate:fresh`, no seeding) and stops on the first failure (`set -e`)
# rather than pressing on into an inconsistent state.
#
# Usage: deploy/deploy.sh [git-ref]
#   git-ref defaults to the current branch's remote tip if omitted.

set -euo pipefail

APP_DIR="${BREEZE_APP_DIR:-/var/www/breeze}"
REF="${1:-}"
HEALTH_URL="${BREEZE_HEALTH_URL:-http://127.0.0.1/health/ready}"

cd "$APP_DIR"

echo "==> Enabling maintenance mode"
php artisan down --retry=15 || true

echo "==> Fetching code"
git fetch origin
if [ -n "$REF" ]; then
    git checkout "$REF"
else
    git merge --ff-only "@{upstream}"
fi

echo "==> Installing PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Installing and building frontend"
npm ci
npm run build

echo "==> Storage permissions"
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "==> Linking public storage (no-op if already linked)"
php artisan storage:link || true

echo "==> Clearing stale caches before re-caching"
php artisan optimize:clear

echo "==> Running migrations"
# --force is required non-interactively; this is still additive-only —
# see docs/production-deployment.md's migration rollback section before
# ever running anything destructive by hand.
php artisan migrate --force

echo "==> Caching configuration, routes, views"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Restarting queue worker and Reverb"
php artisan queue:restart
php artisan reverb:restart

echo "==> Disabling maintenance mode"
php artisan up

echo "==> Verifying health"
sleep 2
if curl -fsS "$HEALTH_URL" > /dev/null; then
    echo "==> Deploy succeeded — $HEALTH_URL is healthy"
else
    echo "==> WARNING: $HEALTH_URL did not report healthy after deploy."
    echo "    The app is live (maintenance mode is off) — investigate immediately."
    echo "    Do not assume the deploy is safe until this is resolved."
    exit 1
fi
