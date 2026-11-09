#!/usr/bin/env bash
#
# Sets up NGINX and PHP-FPM for the CSC 640 project, then checks that NGINX,
# PHP, and MySQL all work. Ubuntu/Debian, including WSL2.
#
# Right now it serves the test pages in code/nginx/public. As the project
# grows, change the settings below (for example PUBLIC_DIR) rather than
# moving files.
#
# Usage (from anywhere in the repository):
#   sudo ./code/nginx/setup-nginx.sh [--install] [--port 8180]
#
#   --install   install nginx, php-fpm, php-mysql, and curl with apt first
#   --port N    port to listen on (default 8180, localhost only)
#
# Database settings: the first run asks for the MySQL user and password and
# saves them in config.php (not committed to Git). To skip the questions,
# set DB_USER and DB_PASS, for example:
#   sudo DB_USER=infralog_api DB_PASS='secret' ./code/nginx/setup-nginx.sh
# Optional: DB_HOST (default 127.0.0.1), DB_PORT (3306), DB_NAME (InfrastructureLogging).
#
set -euo pipefail

# ---- Settings ---------------------------------------------------------------
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"   # code/nginx
PUBLIC_DIR="$HERE/public"        # folder NGINX serves
CONFIG_FILE="$HERE/config.php"   # database settings for PHP (not in Git)
SITE_NAME=csc640                 # file name under /etc/nginx/sites-available
WEB_USER=www-data                # user that NGINX and PHP-FPM run as
PORT=8180
INSTALL=false
# ------------------------------------------------------------------------------

while [ $# -gt 0 ]; do
  case "$1" in
    --install) INSTALL=true; shift ;;
    --port)    PORT="${2:?--port needs a number}"; shift 2 ;;
    -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1 (use --help)"; exit 1 ;;
  esac
done

info() { printf '\n==> %s\n' "$*"; }
ok()   { printf '    OK: %s\n' "$*"; }
fail() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "Run this script with sudo."
[[ "$PORT" =~ ^[0-9]+$ ]] || fail "--port must be a number."

SITE_FILE="/etc/nginx/sites-available/$SITE_NAME"
[ -f "$PUBLIC_DIR/index.html" ] && [ -f "$PUBLIC_DIR/test.php" ] || fail "Cannot find index.html and test.php in $PUBLIC_DIR."

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
info "3. Database settings (config.php)"
if [ -f "$CONFIG_FILE" ] && [ -z "${DB_USER:-}" ]; then
  ok "Using the existing $CONFIG_FILE. Delete it to enter new settings."
else
  if [ -z "${DB_USER:-}" ]; then
    read -r -p "    MySQL user for the test page: " DB_USER
  fi
  if [ -z "${DB_PASS:-}" ]; then
    read -r -s -p "    Password for $DB_USER: " DB_PASS; echo
  fi
  [ -n "$DB_USER" ] || fail "A MySQL user is required."
  # PHP writes the file, so quotes or backslashes in the password are escaped correctly.
  DB_HOST="${DB_HOST:-127.0.0.1}" DB_PORT="${DB_PORT:-3306}" \
  DB_NAME="${DB_NAME:-InfrastructureLogging}" DB_USER="$DB_USER" DB_PASS="$DB_PASS" \
  php -r '
    $config = [
        "host"     => getenv("DB_HOST"),
        "port"     => (int) getenv("DB_PORT"),
        "database" => getenv("DB_NAME"),
        "user"     => getenv("DB_USER"),
        "password" => getenv("DB_PASS"),
    ];
    file_put_contents($argv[1], "<?php\n// Created by code/nginx/setup-nginx.sh. Not committed to Git.\nreturn " . var_export($config, true) . ";\n");
  ' "$CONFIG_FILE"
  ok "Saved $CONFIG_FILE."
fi
# You (the user who ran sudo) own the file and PHP-FPM (www-data) can read
# it; no one else can, because it holds the database password.
chown "${SUDO_USER:-root}:$WEB_USER" "$CONFIG_FILE"
chmod 640 "$CONFIG_FILE"

# ---------------------------------------------------------------------------
info "4. NGINX site"
cat > "$SITE_FILE" <<EOF
# Created by code/nginx/setup-nginx.sh.
server {
    # Localhost only: the test page shows database details.
    listen 127.0.0.1:$PORT;
    server_name localhost;

    root $PUBLIC_DIR;
    index index.html;

    location / {
        try_files \$uri \$uri/ =404;
    }

    # Send .php files to PHP-FPM.
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$FPM_SOCKET;
    }

    # Never serve hidden files.
    location ~ /\. {
        deny all;
    }
}
EOF
ln -sf "$SITE_FILE" "/etc/nginx/sites-enabled/$SITE_NAME"
ok "Wrote $SITE_FILE (http://127.0.0.1:$PORT/, root $PUBLIC_DIR)."

# ---------------------------------------------------------------------------
info "5. File access for $WEB_USER"
can_read() {
  runuser -u "$WEB_USER" -- test -r "$PUBLIC_DIR/index.html" \
    && runuser -u "$WEB_USER" -- test -r "$PUBLIC_DIR/test.php" \
    && runuser -u "$WEB_USER" -- test -r "$CONFIG_FILE"
}
if can_read; then
  ok "$WEB_USER can read the files."
else
  # Usually a home directory with mode 750. Allow passing through (x only,
  # no listing) each parent directory, and reading the public files.
  dir="$PUBLIC_DIR"
  while [ "$dir" != "/" ]; do chmod o+x "$dir"; dir="$(dirname "$dir")"; done
  chmod -R o+rX "$PUBLIC_DIR"
  can_read || fail "$WEB_USER still cannot read $PUBLIC_DIR. Move the repository under /var/www, or fix its permissions."
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

page=$(curl -s "$BASE/") || fail "Could not connect to $BASE. Check /var/log/nginx/error.log."
echo "$page" | grep -q 'id="nginx-ok"' || fail "NGINX did not return index.html. Check /var/log/nginx/error.log."
ok "NGINX serves the static page."

page=$(curl -s "$BASE/test.php") || fail "Could not load $BASE/test.php."
if echo "$page" | grep -q '<?php'; then
  fail "NGINX returned the PHP source instead of running it. The PHP-FPM location block is not working."
fi
echo "$page" | grep -q 'id="php-ok"' || fail "PHP did not run. Check /var/log/nginx/error.log."
ok "PHP runs through PHP-FPM."

# Prints the text of the element with the given id from the test page.
# (The page escapes text as HTML, so turn entities like &#039; back into characters.)
marker_text() {
  echo "$page" | grep -o "id=\"$1\"[^>]*>[^<]*" | sed 's/.*>//' \
    | php -r 'echo html_entity_decode(stream_get_contents(STDIN), ENT_QUOTES | ENT_HTML5);'
}

if echo "$page" | grep -q 'id="mysql-error"'; then
  fail "MySQL: $(marker_text mysql-error)
       Fix the settings in $CONFIG_FILE, or delete it and run this script again to re-enter them."
fi
ok "MySQL connection works."

if echo "$page" | grep -q 'id="tables-missing"'; then
  fail "$(marker_text tables-missing)"
fi
ok "$(marker_text tables-ok)"

printf '\nAll checks passed. Open %s/ in your browser to see the pages.\n' "$BASE"
