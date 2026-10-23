#!/usr/bin/env bash
# Install the headquarters, build the desk, and serve it.
set -euo pipefail

cd "$(dirname "$0")/../hq"

composer install
npm install
npm run build

if [[ ! -f .env ]]; then
  cp .env.example .env
fi
if ! grep -qE '^APP_KEY=.+' .env; then
  php artisan key:generate --ansi
fi
touch database/database.sqlite
php artisan migrate --force

# 8000 is often already taken by another local site. The desk stays on 8001.
exec php artisan serve --port=8001
