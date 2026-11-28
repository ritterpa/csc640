#!/bin/sh
# Starts the Stage 2 API in the container. Like steps 4, 5 and 7 of
# code/stage2/run.sh: write the settings into .env, copy the project's files
# over the Laravel project, clear cached config and routes, and serve on 8181.
#
# The settings come from environment variables set in docker-compose.yml, but
# they must be written to .env: "php artisan serve" starts its PHP server with
# most environment variables removed, so Laravel would otherwise fall back to
# the .env that "composer create-project" wrote at build time (SQLite).
set -e

rm -f .env          # a new file, so umask makes it owner-only (truncating keeps the old mode)
umask 077
for key in APP_NAME APP_ENV APP_KEY APP_DEBUG APP_URL \
           DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
           SESSION_DRIVER CACHE_STORE QUEUE_CONNECTION JWT_SECRET JWT_TTL; do
  eval "value=\${$key:?$key is not set (see docker-compose.yml)}"
  printf '%s=%s\n' "$key" "$value" >> .env
done
umask 022

cp -R /src/. /app/
php artisan config:clear >/dev/null
php artisan route:clear >/dev/null

# Workers let the built-in server handle parallel requests (browsers open several).
PHP_CLI_SERVER_WORKERS=4 exec php artisan serve --host=0.0.0.0 --port=8181
