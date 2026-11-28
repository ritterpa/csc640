#!/usr/bin/env bash
#
# run.sh: builds, configures, and starts the Stage 2 (Laravel) InfraLogging API.
#
# Usage (from anywhere in the repository):
#   ./code/stage2/run.sh              # set up (if needed) and start on http://127.0.0.1:8181
#   ./code/stage2/run.sh --setup-only # set up, check, and exit without starting the server
#
# Then, in another terminal:  ./code/tests/acceptance.sh --stage 2
#
# Prerequisites (see the README):
#   - PHP and the extensions Laravel needs, and Composer. The ASE 230 install
#     guide sets these up (module2/pdf/0_story_telling/laravel_install.pdf).
#   - MySQL running, with the schema deployed by code/database/setup-database.sh.
#   - sudo rights, used once to create the Stage 2 MySQL account.
#
# ---------------------------------------------------------------------------
# Adapted from the ASE 230 course script
#   module2/code/1_Laravel/5. Bearer Token APIs/run1-5.sh
#   https://github.com/nkuase/ase230 (slides: module2/pdf/1_Laravel/5-2. run1-5.sh.pdf)
# Same steps, in the same order: create the Laravel project if it is missing,
# create a MySQL account with sudo, write the database settings into .env,
# copy the project's own files over the new project, list the API routes,
# and start "php artisan serve".
#
# What changed, and why:
#   - Authentication: run1-5.sh runs "php artisan install:api" (Sanctum).
#     This project keeps Stage 1's HS256 JWT bearer tokens instead, using
#     firebase/php-jwt, so the same 74 acceptance tests apply to both stages.
#   - Database: run1-5.sh creates its own tables with migrations and seeders.
#     This project uses the existing InfrastructureLogging schema (deployed by
#     setup-database.sh), so no migrations run, and the acceptance tests load
#     the seed data themselves.
#   - Least privilege: run1-5.sh grants ALL PRIVILEGES with a fixed password.
#     Here the account gets only SELECT, INSERT, and UPDATE (no DELETE: run
#     logs are append-only) and a random password kept in .env.
#   - Repeatable: every step checks what already exists, so the script can be
#     run again at any time. It never overwrites an existing password or secret.
#   - Port 8181, so Stage 1 (8180) and Stage 2 can run side by side.
# ---------------------------------------------------------------------------
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"   # code/stage2
SOURCE_DIR="$HERE/src"          # this project's Laravel files (committed)
TARGET_DIR="$HERE/api"          # the generated Laravel project (not committed)
LARAVEL_VERSION="${LARAVEL_VERSION:-^13.0}"
JWT_PACKAGE="firebase/php-jwt:^7.1"
DB_NAME="InfrastructureLogging"
DB_USER="infralog_laravel"
HOST=127.0.0.1
PORT=8181

SETUP_ONLY=false
case "${1:-}" in
  --setup-only) SETUP_ONLY=true ;;
  -h|--help)    sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
  "")           ;;
  *)            echo "Unknown option: $1 (use --help)" >&2; exit 1 ;;
esac

step() { printf '\n==> %s\n' "$*"; }
ok()   { printf '    OK: %s\n' "$*"; }
fail() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
step "1. Prerequisites"
command -v php >/dev/null      || fail "PHP is not installed. Follow the ASE 230 Laravel install guide."
command -v composer >/dev/null || fail "Composer is not installed. Follow the ASE 230 Laravel install guide."
command -v mysql >/dev/null    || fail "The mysql client is not installed (sudo apt install mysql-server)."
(exec 3<>/dev/tcp/127.0.0.1/3306) 2>/dev/null || fail "MySQL is not running on 127.0.0.1:3306 (sudo service mysql start)."
ok "PHP $(php -r 'echo PHP_VERSION;'), $(composer --version 2>/dev/null | head -n 1)"

# The same sudo pattern as run1-5.sh: MySQL root uses socket authentication
# on WSL2/Linux. Set MYSQL_ROOT (e.g. "mysql -u root -p") to use another login.
read -r -a MYSQL_ROOT <<< "${MYSQL_ROOT:-sudo mysql -u root}"
tables=$("${MYSQL_ROOT[@]}" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DB_NAME'") \
  || fail "Cannot log in to MySQL as root. Check that 'sudo mysql' works, or set MYSQL_ROOT."
[ "$tables" -ge 6 ] || fail "The $DB_NAME schema is missing. Run: sudo ./code/database/setup-database.sh --test-logins"
ok "MySQL is running and the $DB_NAME schema is deployed ($tables tables)."

# ---------------------------------------------------------------------------
step "2. Laravel project ($TARGET_DIR)"
if [ ! -f "$TARGET_DIR/artisan" ]; then
  composer create-project laravel/laravel "$TARGET_DIR" "$LARAVEL_VERSION" --no-interaction
  ok "Created a new Laravel project."
else
  ok "Laravel project already exists."
fi
cd "$TARGET_DIR"
if ! grep -q '"firebase/php-jwt"' composer.json; then
  composer require "$JWT_PACKAGE" --no-interaction
fi
[ -d vendor ] || composer install --no-interaction
composer check-platform-reqs --no-dev >/dev/null \
  || fail "PHP or an extension Laravel needs is missing. Run 'composer check-platform-reqs' in $TARGET_DIR for details."
ok "Dependencies installed ($(composer show laravel/framework 2>/dev/null | sed -n 's/^versions *: *\* *//p'), firebase/php-jwt)."

# ---------------------------------------------------------------------------
step "3. MySQL account for Laravel ($DB_USER)"
[ -f .env ] || cp .env.example .env
existing_pass=$(sed -nE 's/^DB_PASSWORD=//p' .env | tail -n 1)
if [ -n "$existing_pass" ] && grep -q "^DB_USERNAME=$DB_USER$" .env; then
  DB_PASS="$existing_pass"            # keep the password from an earlier run
else
  DB_PASS="$(php -r 'echo bin2hex(random_bytes(16));')"
fi
"${MYSQL_ROOT[@]}" <<SQL || fail "Creating the MySQL account failed (see the error above)."
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT SELECT, INSERT, UPDATE ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
SQL
ok "$DB_USER has SELECT, INSERT, and UPDATE on $DB_NAME (no DELETE)."

# ---------------------------------------------------------------------------
step "4. Settings (.env)"
# PHP edits .env: it sets each value, uncomments Laravel's "# DB_HOST=" lines,
# and adds settings that are missing. Existing JWT_SECRET and APP_KEY are kept.
DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASS="$DB_PASS" APP_URL="http://$HOST:$PORT" php -r '
  $file = ".env";
  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $current = [];
  foreach ($lines as $line) {
      if (preg_match("/^\s*([A-Z_]+)\s*=(.*)$/", $line, $m)) $current[$m[1]] = trim($m[2]);
  }
  $set = [
      "APP_NAME"         => "InfraLogging",
      "APP_URL"          => getenv("APP_URL"),
      "APP_DEBUG"        => "false",   // 500 responses hide internal details, as in Stage 1
      "DB_CONNECTION"    => "mysql",
      "DB_HOST"          => "127.0.0.1",
      "DB_PORT"          => "3306",
      "DB_DATABASE"      => getenv("DB_NAME"),
      "DB_USERNAME"      => getenv("DB_USER"),
      "DB_PASSWORD"      => getenv("DB_PASS"),
      // The API keeps no sessions and needs no cache or queue tables.
      "SESSION_DRIVER"   => "file",
      "CACHE_STORE"      => "file",
      "QUEUE_CONNECTION" => "sync",
      "JWT_TTL"          => "3600",
  ];
  if (($current["JWT_SECRET"] ?? "") === "") {
      $set["JWT_SECRET"] = bin2hex(random_bytes(32));   // 64 characters; php-jwt 7 needs at least 32
  }
  foreach ($set as $key => $value) {
      $done = false;
      foreach ($lines as &$line) {
          if (preg_match("/^\s*#?\s*" . $key . "\s*=/", $line)) { $line = "$key=$value"; $done = true; break; }
      }
      unset($line);
      if (! $done) $lines[] = "$key=$value";
  }
  file_put_contents($file, implode("\n", $lines) . "\n");
'
chmod 600 .env
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --no-interaction >/dev/null
ok ".env is configured (database, JWT_SECRET, APP_KEY)."

# ---------------------------------------------------------------------------
step "5. Project files ($SOURCE_DIR -> $TARGET_DIR)"
cp -R "$SOURCE_DIR/." "$TARGET_DIR/"
php artisan config:clear >/dev/null
php artisan route:clear >/dev/null
ok "Copied $(cd "$SOURCE_DIR" && find . -type f | wc -l) files."
php artisan route:list --path=api

# ---------------------------------------------------------------------------
step "6. Check the database connection"
if ! check=$(MYSQL_PWD="$DB_PASS" mysql -h 127.0.0.1 -u "$DB_USER" -N "$DB_NAME" -e "SELECT COUNT(*) FROM ApiUser" 2>&1); then
  fail "$DB_USER cannot read $DB_NAME: $check"
fi
ok "$DB_USER can read $DB_NAME ($check login accounts)."

if $SETUP_ONLY; then
  printf '\nSetup complete. Start the server with: %s\n' "$0"
  exit 0
fi

# ---------------------------------------------------------------------------
step "7. Start the server"
printf '    Stage 2 API: http://%s:%s/api/v1  (Ctrl+C to stop)\n' "$HOST" "$PORT"
printf '    Test it from another terminal: ./code/tests/acceptance.sh --stage 2\n\n'
# Workers let the built-in server handle parallel requests (browsers open several).
PHP_CLI_SERVER_WORKERS=4 exec php artisan serve --host="$HOST" --port="$PORT"
