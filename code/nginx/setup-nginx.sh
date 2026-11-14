#!/usr/bin/env bash
#
# Sets up NGINX and PHP-FPM to serve the CSC 640 Stage 1 API and web pages
# from code/stage1/public, then checks that NGINX, PHP, and MySQL all work.
# Ubuntu/Debian, including WSL2.
#
# Usage (from anywhere in the repository):
#   sudo ./code/nginx/setup-nginx.sh [--install] [--port 8180]
#
#   --install   install nginx, php-fpm, php-mysql, and curl with apt first
#   --port N    port to listen on (default 8180, localhost only)
#
# Database settings: if code/stage1/.env does not exist, the script creates
# it from .env.example with a new JWT_SECRET, and asks for the MySQL user and
# password. To skip the questions (or to change an existing .env), set
# DB_USER and DB_PASS, for example:
#   sudo DB_USER=infralog_api DB_PASS='secret' ./code/nginx/setup-nginx.sh
# Optional: DB_HOST, DB_PORT, DB_NAME (defaults come from .env.example).
#
set -euo pipefail

# ---- Settings ---------------------------------------------------------------
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"   # code/nginx
STAGE1_DIR="$(cd "$HERE/../stage1" 2>/dev/null && pwd || true)"
PUBLIC_DIR="$STAGE1_DIR/public"  # folder NGINX serves (index.html, index.php)
ENV_FILE="$STAGE1_DIR/.env"      # database settings and JWT secret (not in Git)
SITE_NAME=csc640                 # file name under /etc/nginx/sites-available
WEB_USER=www-data                # user that NGINX and PHP-FPM run as
PORT=8180
INSTALL=false
# ------------------------------------------------------------------------------

while [ $# -gt 0 ]; do
  case "$1" in
    --install) INSTALL=true; shift ;;
    --port)    PORT="${2:?--port needs a number}"; shift 2 ;;
    -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1 (use --help)"; exit 1 ;;
  esac
done

info() { printf '\n==> %s\n' "$*"; }
ok()   { printf '    OK: %s\n' "$*"; }
note() { printf '    NOTE: %s\n' "$*"; }
fail() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "Run this script with sudo."
[[ "$PORT" =~ ^[0-9]+$ ]] || fail "--port must be a number."
[ -n "$STAGE1_DIR" ] && [ -f "$PUBLIC_DIR/index.php" ] || fail "Cannot find code/stage1/public/index.php."
SITE_FILE="/etc/nginx/sites-available/$SITE_NAME"

# Restarts a service with systemd when it is available, otherwise with the
# service command (WSL2 without systemd).
restart_service() {
  if [ -d /run/systemd/system ]; then
    systemctl enable "$1" >/dev/null 2>&1 || true
    systemctl restart "$1"
  else
    service "$1" restart >/dev/null 2>&1 || service "$1" start >/dev/null
  fi
}

# ---------------------------------------------------------------------------
info "1. Packages"
if $INSTALL; then
  apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq nginx php-fpm php-mysql curl >/dev/null
  ok "Installed nginx, php-fpm, php-mysql, and curl."
fi
command -v nginx >/dev/null || fail "nginx is not installed. Run again with --install."
command -v php >/dev/null   || fail "PHP is not installed. Run again with --install."
command -v curl >/dev/null  || fail "curl is not installed. Run again with --install."
ok "nginx $(nginx -v 2>&1 | sed 's#.*/##'), PHP $(php -r 'echo PHP_VERSION;')"

# ---------------------------------------------------------------------------
info "2. PHP-FPM"
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
FPM_SOCKET="/run/php/php${PHP_VER}-fpm.sock"
command -v "php-fpm${PHP_VER}" >/dev/null || fail "php${PHP_VER}-fpm is not installed. Run again with --install."
restart_service "php${PHP_VER}-fpm"
for _ in 1 2 3 4 5; do [ -S "$FPM_SOCKET" ] && break; sleep 1; done
[ -S "$FPM_SOCKET" ] || fail "The PHP-FPM socket $FPM_SOCKET did not appear."
ok "php${PHP_VER}-fpm is running ($FPM_SOCKET)."

# ---------------------------------------------------------------------------
info "3. Database settings ($ENV_FILE)"
# Writes KEY=value lines into .env. PHP does the editing, so passwords with
# characters like / & # or quotes are written exactly as typed.
set_env_values() {   # usage: set_env_values SOURCE_FILE  (values come from the environment)
  php -r '
    $set = [];
    foreach (["DB_HOST", "DB_PORT", "DB_NAME", "DB_USER", "DB_PASS", "JWT_SECRET"] as $key) {
        $value = getenv($key);
        if ($value !== false && $value !== "") {
            $set[$key] = $value;
        }
    }
    $lines = file($argv[1], FILE_IGNORE_NEW_LINES);
    foreach ($lines as &$line) {
        foreach ($set as $key => $value) {
            if (preg_match("/^\s*" . $key . "\s*=/", $line)) {
                $line = "$key=$value";
            }
        }
    }
    file_put_contents($argv[2], implode("\n", $lines) . "\n");
  ' "$1" "$ENV_FILE"
}

if [ ! -f "$ENV_FILE" ]; then
  [ -f "$STAGE1_DIR/.env.example" ] || fail "Cannot find $STAGE1_DIR/.env.example to copy."
  [ -n "${DB_USER:-}" ] || read -r -p "    MySQL user for the API: " DB_USER
  [ -n "${DB_PASS:-}" ] || { read -r -s -p "    Password for $DB_USER: " DB_PASS; echo; }
  [ -n "$DB_USER" ] || fail "A MySQL user is required."
  DB_USER="$DB_USER" DB_PASS="$DB_PASS" \
  JWT_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')" \
    set_env_values "$STAGE1_DIR/.env.example"
  ok "Created $ENV_FILE with a new JWT_SECRET."
elif [ -n "${DB_USER:-}" ]; then
  [ -n "${DB_PASS:-}" ] || { read -r -s -p "    Password for $DB_USER: " DB_PASS; echo; }
  DB_USER="$DB_USER" DB_PASS="$DB_PASS" set_env_values "$ENV_FILE"
  ok "Updated the database settings in $ENV_FILE."
else
  ok "Using the existing $ENV_FILE. Edit it, or set DB_USER and DB_PASS, to change the settings."
fi
# You (the user who ran sudo) own .env and PHP-FPM (www-data) can read it;
# no one else can, because it holds the database password and JWT secret.
chown "${SUDO_USER:-root}:$WEB_USER" "$ENV_FILE"
chmod 640 "$ENV_FILE"

# ---------------------------------------------------------------------------
info "4. NGINX site"
cat > "$SITE_FILE" <<EOF
# Created by code/nginx/setup-nginx.sh.
server {
    # Localhost only, while the project is in development.
    listen 127.0.0.1:$PORT;
    server_name localhost;

    root $PUBLIC_DIR;
    index index.html;

    # Real files (index.html, assets/) are served directly. Everything else,
    # including every /api/ request, goes to the front controller index.php.
    location / {
        try_files \$uri \$uri/ /index.php\$is_args\$args;
    }

    # Send .php files to PHP-FPM.
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$FPM_SOCKET;
    }

    # Never serve hidden files such as .env.
    location ~ /\. {
        deny all;
    }
}
EOF
ln -sf "$SITE_FILE" "/etc/nginx/sites-enabled/$SITE_NAME"
ok "Wrote $SITE_FILE (http://127.0.0.1:$PORT/, root $PUBLIC_DIR)."

# ---------------------------------------------------------------------------
info "5. File access for $WEB_USER"
# PHP-FPM needs public/ (served files), src/ (PHP classes), and .env.
can_read() {
  for file in "$PUBLIC_DIR/index.php" "$PUBLIC_DIR/index.html" "$STAGE1_DIR/src" "$ENV_FILE"; do
    [ -e "$file" ] || continue          # files not committed yet are skipped
    runuser -u "$WEB_USER" -- test -r "$file" || return 1
  done
}
if can_read; then
  ok "$WEB_USER can read the files."
else
  # Usually a home directory with mode 750. Allow passing through (x only,
  # no listing) each parent directory, and reading public/ and src/.
  dir="$STAGE1_DIR"
  while [ "$dir" != "/" ]; do chmod o+x "$dir"; dir="$(dirname "$dir")"; done
  chmod -R o+rX "$PUBLIC_DIR"
  [ -d "$STAGE1_DIR/src" ] && chmod -R o+rX "$STAGE1_DIR/src"
  can_read || fail "$WEB_USER still cannot read $STAGE1_DIR. Move the repository under /var/www, or fix its permissions."
  ok "Added pass-through (o+x) permission on the parent folders so $WEB_USER can reach the files."
fi

# ---------------------------------------------------------------------------
info "6. Start NGINX"
if ! output=$(nginx -t 2>&1); then
  printf '%s\n' "$output" | sed 's/^/    /'
  fail "The NGINX configuration test failed (details above). If another site uses port $PORT, disable it in /etc/nginx/sites-enabled/ or use --port."
fi
restart_service nginx
sleep 1
ok "Configuration is valid and NGINX is running."

# ---------------------------------------------------------------------------
info "7. Tests"
BASE="http://127.0.0.1:$PORT"

# NGINX: the static page is served.
if [ -f "$PUBLIC_DIR/index.html" ]; then
  page=$(curl -s "$BASE/") || fail "Could not connect to $BASE. Check /var/log/nginx/error.log."
  echo "$page" | grep -q '<html' || fail "NGINX did not return index.html. Check /var/log/nginx/error.log."
  ok "NGINX serves index.html."
else
  note "public/index.html is not in the repository yet; skipped the static page check."
fi

# PHP: an unknown /api/ path reaches index.php, which answers with a JSON 404.
page=$(curl -s "$BASE/api/v1/setup-check") || fail "Could not connect to $BASE."
if echo "$page" | grep -q '<?php'; then
  fail "NGINX returned PHP source instead of running it. The PHP-FPM location block is not working."
fi
echo "$page" | grep -q '"NOT_FOUND"' \
  || fail "The front controller did not answer (got: ${page:0:200}). Check /var/log/nginx/error.log."
ok "Requests reach index.php through PHP-FPM."

# MySQL: connect as www-data with the settings in .env, like the API does,
# and count the rows in each table the schema should have.
mysql_report=$(runuser -u "$WEB_USER" -- php -r '
  $env = [];
  foreach (file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      $line = trim($line);
      if ($line === "" || $line[0] === "#" || !str_contains($line, "=")) continue;
      [$key, $value] = array_map("trim", explode("=", $line, 2));
      $env[$key] = trim($value, "\"'\''");
  }
  try {
      $pdo = new PDO(
          sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
              $env["DB_HOST"] ?? "127.0.0.1", (int) ($env["DB_PORT"] ?? 3306), $env["DB_NAME"] ?? "InfrastructureLogging"),
          $env["DB_USER"] ?? "", $env["DB_PASS"] ?? "",
          [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  } catch (PDOException $e) {
      echo "ERROR ", $e->getMessage(); exit(1);
  }
  foreach (["ApiUser", "ScriptCatalog", "ScriptRun", "LogEntry", "ExceptionLog", "PerformanceMetric"] as $table) {
      try {
          echo $table, "=", $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn(), "\n";
      } catch (PDOException $e) {
          echo $table, "=missing\n";
      }
  }
' "$ENV_FILE" 2>&1) || fail "MySQL: ${mysql_report#ERROR }
       Fix DB_USER, DB_PASS, or DB_HOST in $ENV_FILE (or rerun with DB_USER and DB_PASS set)."

missing=$(echo "$mysql_report" | grep '=missing' | cut -d= -f1 | paste -sd, - | sed 's/,/, /g' || true)
[ -z "$missing" ] || fail "Missing tables: $missing. Deploy the schema with code/database/Deploy-InfraLogging.mysql.sql."
ok "MySQL connection works (as $WEB_USER, with the settings in .env). Rows per table:"
echo "$mysql_report" | sed 's/=/: /; s/^/        /'
if echo "$mysql_report" | grep -q '^ApiUser=0$'; then
  note "ApiUser has no rows, so no one can log in yet. Create a login account (see the README)."
fi

# Login: only informational, because the endpoint may not be committed yet.
status=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/v1/auth/login" \
  -H 'Content-Type: application/json' -d '{"username":"setup-check","password":"x"}') || status=000
case "$status" in
  401) ok "The login endpoint answers (401 for an unknown user)." ;;
  404) note "The login route is not in public/index.php yet." ;;
  500) note "The login endpoint returned 500. If AuthController is committed, check the PHP-FPM log." ;;
  *)   note "The login endpoint returned $status." ;;
esac

printf '\nAll checks passed. Open %s/ in your browser.\n' "$BASE"