#!/usr/bin/env bash
#
# Sets up the InfrastructureLogging database for the CSC 640 API:
#   1. deploys the schema (Deploy-InfraLogging.mysql.sql; safe to rerun),
#   2. creates or updates the MySQL account the PHP API connects as, with only
#      SELECT and EXECUTE rights,
#   3. saves that account's credentials in code/stage1/.env, and
#   4. optionally creates login accounts (rows in ApiUser).
# Ubuntu/Debian, including WSL2. Run it before code/nginx/setup-nginx.sh.
#
# Usage (from anywhere in the repository):
#   sudo ./code/database/setup-database.sh [options]
#
#   --test-logins          create the test logins reader/ReaderPass123! (read)
#                          and writer/WriterPass123! (read write)
#   --add-login NAME       create or update a login; asks for its password
#   --scope read|write     scope for --add-login (default read; write = read write)
#
# The API's MySQL password: taken from DB_PASS if set, otherwise from
# code/stage1/.env if it already has one, otherwise generated at random.
# Optional settings: API_USER (default infralog_api), DB_NAME (default
# InfrastructureLogging), MYSQL (admin command, default: mysql as root).
#
set -euo pipefail

# ---- Settings ---------------------------------------------------------------
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"            # code/database
SCHEMA="$HERE/Deploy-InfraLogging.mysql.sql"
STAGE1_DIR="$(cd "$HERE/../stage1" 2>/dev/null && pwd || true)"
ENV_FILE="$STAGE1_DIR/.env"
API_HOST=localhost              # the API connects from this machine
WEB_USER=www-data               # PHP-FPM user that must read .env
# ------------------------------------------------------------------------------

TEST_LOGINS=false
ADD_LOGIN=""
SCOPE=read
while [ $# -gt 0 ]; do
  case "$1" in
    --test-logins) TEST_LOGINS=true; shift ;;
    --add-login)   ADD_LOGIN="${2:?--add-login needs a name}"; shift 2 ;;
    --scope)       SCOPE="${2:?--scope needs read or write}"; shift 2 ;;
    -h|--help)     sed -n '2,22p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1 (use --help)"; exit 1 ;;
  esac
done

info() { printf '\n==> %s\n' "$*"; }
ok()   { printf '    OK: %s\n' "$*"; }
fail() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "Run this script with sudo."
[ -f "$SCHEMA" ] || fail "Cannot find $SCHEMA."
[ -n "$STAGE1_DIR" ] && [ -f "$STAGE1_DIR/.env.example" ] || fail "Cannot find code/stage1/.env.example."
case "$SCOPE" in
  read)  SCOPE_VALUE="read" ;;
  write) SCOPE_VALUE="read write" ;;
  *) fail "--scope must be read or write." ;;
esac

# Reads one KEY=value from .env (empty if the file or key is missing).
env_value() {
  [ -f "$ENV_FILE" ] || return 0
  # Same rules as src/Config.php: trim spaces, then one pair of surrounding quotes.
  sed -nE "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" "$ENV_FILE" | tail -n 1 \
    | tr -d '\r' | sed -E "s/[[:space:]]+\$//; s/^[\"'](.*)[\"']\$/\1/"
}

# Escapes text for use inside a single-quoted MySQL string.
sql_quote() {
  local text=${1//\\/\\\\}
  printf '%s' "${text//\'/\'\'}"
}

# ---------------------------------------------------------------------------
info "1. MySQL server"
MYSQL="${MYSQL:-mysql}"
command -v mysql >/dev/null || fail "The mysql client is not installed (sudo apt install mysql-server)."
if ! version=$($MYSQL -N -e 'SELECT VERSION()' 2>&1); then
  if echo "$version" | grep -qE "Can't connect.*\((2|111)\)"; then
    fail "The MySQL server is not running (sudo service mysql start)."
  fi
  fail "Cannot log in to MySQL as an administrator: $version
       Set MYSQL to a command that can, for example: sudo MYSQL=\"mysql -u root -p\" $0"
fi
# The schema needs MySQL 8.0.19 or later (CHECK constraints, expression defaults).
mysql_version=$(echo "$version" | grep -oE '^[0-9]+\.[0-9]+\.[0-9]+')
if [ "$(printf '%s\n8.0.19\n' "$mysql_version" | sort -V | head -n 1)" != "8.0.19" ]; then
  fail "MySQL $version is too old; the schema needs 8.0.19 or later."
fi
ok "Connected as an administrator (MySQL $version)."

# ---------------------------------------------------------------------------
info "2. Schema"
$MYSQL < "$SCHEMA" || fail "Deploying $SCHEMA failed (see the MySQL error above)."
DB_NAME="${DB_NAME:-$(env_value DB_NAME)}"
DB_NAME="${DB_NAME:-InfrastructureLogging}"
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || fail "DB_NAME may only contain letters, digits, and underscores."
tables=$($MYSQL -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DB_NAME'")
ok "Deployed (database $DB_NAME, $tables tables). Existing data is kept."

# ---------------------------------------------------------------------------
info "3. API account (the MySQL user PHP connects as)"
API_USER="${API_USER:-$(env_value DB_USER)}"
API_USER="${API_USER:-infralog_api}"
[[ "$API_USER" =~ ^[A-Za-z0-9_]{1,32}$ ]] || fail "The API user name may only contain letters, digits, and underscores."

existing_pass=$(env_value DB_PASS)
if [ -n "${DB_PASS:-}" ]; then
  API_PASS="$DB_PASS"; source_note="from DB_PASS"
elif [ -n "$existing_pass" ] && [ "$existing_pass" != "change-me" ]; then
  API_PASS="$existing_pass"; source_note="from $ENV_FILE"
else
  API_PASS="$(head -c 24 /dev/urandom | base64 | tr -d '/+=' | head -c 32)"; source_note="newly generated"
fi

user_sql="'$API_USER'@'$API_HOST'"
pass_sql="'$(sql_quote "$API_PASS")'"
$MYSQL <<SQL || fail "Creating the API account failed (see the MySQL error above)."
CREATE USER IF NOT EXISTS $user_sql IDENTIFIED BY $pass_sql;
ALTER USER $user_sql IDENTIFIED BY $pass_sql;
GRANT SELECT, EXECUTE ON \`$DB_NAME\`.* TO $user_sql;
SQL
ok "$API_USER@$API_HOST has SELECT and EXECUTE on $DB_NAME (password $source_note)."

# ---------------------------------------------------------------------------
info "4. Credentials in $ENV_FILE"
# PHP edits the file, so any characters in the password are written exactly.
write_env() {   # usage: write_env SOURCE_FILE ; values come from the environment
  php -r '
    $set = [];
    foreach (["DB_NAME", "DB_USER", "DB_PASS", "JWT_SECRET"] as $key) {
        $value = getenv($key);
        if ($value !== false && $value !== "") $set[$key] = $value;
    }
    $lines = file($argv[1], FILE_IGNORE_NEW_LINES);
    foreach ($lines as &$line) {
        foreach ($set as $key => $value) {
            if (preg_match("/^\s*" . $key . "\s*=/", $line)) $line = "$key=$value";
        }
    }
    file_put_contents($argv[2], implode("\n", $lines) . "\n");
  ' "$1" "$ENV_FILE"
}
command -v php >/dev/null || fail "PHP is needed to write .env and hash passwords (sudo apt install php-cli)."
if [ -f "$ENV_FILE" ]; then
  DB_NAME="$DB_NAME" DB_USER="$API_USER" DB_PASS="$API_PASS" write_env "$ENV_FILE"
  ok "Updated DB_NAME, DB_USER, and DB_PASS."
else
  DB_NAME="$DB_NAME" DB_USER="$API_USER" DB_PASS="$API_PASS" \
  JWT_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')" \
    write_env "$STAGE1_DIR/.env.example"
  ok "Created it from .env.example with these credentials and a new JWT_SECRET."
fi
# You (the user who ran sudo) own .env and PHP-FPM can read it; no one else can.
chown "${SUDO_USER:-root}:$WEB_USER" "$ENV_FILE"
chmod 640 "$ENV_FILE"

# Prove the API account works the way PHP will use it: over TCP to 127.0.0.1.
# MYSQL_PWD keeps the password out of the process list.
if ! check=$(MYSQL_PWD="$API_PASS" mysql -h 127.0.0.1 -u "$API_USER" -N "$DB_NAME" \
              -e "SELECT COUNT(*) FROM ApiUser" 2>&1); then
  fail "The API account cannot log in over TCP: $check"
fi
ok "$API_USER can log in and read the tables."

# ---------------------------------------------------------------------------
info "5. Login accounts (ApiUser)"
# Creates or updates one login. The password is hashed with PHP's
# password_hash() (bcrypt), the format the login endpoint checks.
upsert_login() {   # usage: upsert_login NAME PASSWORD SCOPE
  local name=$1 password=$2 scope=$3 hash
  [[ "$name" =~ ^[A-Za-z0-9._@-]{1,100}$ ]] || fail "Login names may only contain letters, digits, and . _ @ -"
  hash=$(LOGIN_PASSWORD="$password" php -r 'echo password_hash(getenv("LOGIN_PASSWORD"), PASSWORD_DEFAULT);')
  $MYSQL "$DB_NAME" -e "
    INSERT INTO ApiUser (UserName, PasswordHash, Scope) VALUES ('$name', '$hash', '$scope') AS new
    ON DUPLICATE KEY UPDATE PasswordHash = new.PasswordHash, Scope = new.Scope, IsActive = 1;" \
    || fail "Saving login $name failed."
  ok "Login $name ($scope) is ready."
}

if $TEST_LOGINS; then
  upsert_login reader 'ReaderPass123!' read
  upsert_login writer 'WriterPass123!' 'read write'
fi
if [ -n "$ADD_LOGIN" ]; then
  read -r -s -p "    Password for $ADD_LOGIN: " login_pass; echo
  read -r -s -p "    Repeat the password: " login_pass2; echo
  [ -n "$login_pass" ] || fail "The password cannot be empty."
  [ "$login_pass" = "$login_pass2" ] || fail "The passwords do not match."
  upsert_login "$ADD_LOGIN" "$login_pass" "$SCOPE_VALUE"
fi
logins=$($MYSQL -N "$DB_NAME" -e "SELECT COUNT(*) FROM ApiUser WHERE IsActive = 1")
if [ "$logins" -eq 0 ]; then
  printf '    NOTE: There are no login accounts yet. Add one with --add-login NAME, or --test-logins.\n'
else
  ok "$logins active login account(s)."
fi

printf '\nDatabase ready. Next: sudo ./code/nginx/setup-nginx.sh\n'
