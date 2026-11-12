#!/usr/bin/env bash
# Acceptance tests for the InfraLogging REST API.
#
# Usage (one command, from anywhere in the repository):
#   ./code/tests/acceptance.sh
#
# Everything it needs is found automatically. Override any of these when needed:
#   BASE        API address           (default http://localhost:8180)
#   JWT_SECRET  server's token secret (default: read from code/stage1/.env)
#   MYSQL       command that runs SQL (default: mysql if it can log in, else sudo mysql)
# Example for Stage 2:
#   BASE=http://localhost:8081 MYSQL="docker compose exec -T db mysql -uroot -psecret" ./code/tests/acceptance.sh
#
# Exit codes: 0 = all tests passed, 1 = some tests failed, 2 = setup problem.
set -u

DIR="$(cd "$(dirname "$0")" && pwd)"
BASE="${BASE:-http://localhost:8180}"
BODY="$(mktemp)"
trap 'rm -f "$BODY"' EXIT

setup_error() { echo "SETUP ERROR: $*" >&2; exit 2; }

# ---- Preflight: tools, settings, and a running API ----
for tool in curl jq openssl; do
  command -v "$tool" >/dev/null || setup_error "$tool is not installed (sudo apt install $tool)."
done

# The secret signs the expired-token test case; it must match the server's.
if [ -z "${JWT_SECRET:-}" ]; then
  [ -r "$ENV_FILE" ] || setup_error "Cannot read $ENV_FILE. Run code/nginx/setup-nginx.sh, or set JWT_SECRET."
  JWT_SECRET=$(sed -nE 's/^[[:space:]]*JWT_SECRET[[:space:]]*=[[:space:]]*//p' "$ENV_FILE" | tail -n 1 | tr -d "\"'\r")
  [ -n "$JWT_SECRET" ] || setup_error "JWT_SECRET is empty in $ENV_FILE."
fi

# Resetting the database needs MySQL admin rights. On Ubuntu/WSL, root logs in
# through sudo, so fall back to "sudo mysql" (it asks for your password once).
if [ -z "${MYSQL:-}" ]; then
  command -v mysql >/dev/null || setup_error "The mysql client is not installed (sudo apt install mysql-client)."
  if mysql_error=$(mysql -e 'SELECT 1' 2>&1 >/dev/null); then
    MYSQL="mysql"
  elif echo "$mysql_error" | grep -qE "Can't connect.*\((2|111)\)"; then
    # (2) = no socket file, (111) = connection refused: the server is down.
    setup_error "The MySQL server is not running (sudo service mysql start)."
  elif command -v sudo >/dev/null; then
    MYSQL="sudo mysql"
  else
    setup_error "mysql cannot log in ($mysql_error). Set MYSQL to a command that can, for example MYSQL=\"mysql -u root -p\"."
  fi
fi

status=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/v1/") || status=000
[ "$status" != "000" ] || setup_error "Cannot reach the API at $BASE. Is NGINX running? (sudo ./code/nginx/setup-nginx.sh)"

# ---- Data setup ----
$MYSQL < "$DIR/seed.sql" || setup_error "The database reset failed (command: $MYSQL)."

R1=11111111-1111-4111-8111-111111111111
R2=22222222-2222-4222-8222-222222222222
R3=33333333-3333-4333-8333-333333333333
RX=99999999-9999-4999-8999-999999999999
C=cccccccc-cccc-4ccc-8ccc-cccccccccccc

# Get real tokens by logging in as the seeded users
login() {
  curl -s -X POST "$BASE/api/v1/auth/login" -H "Content-Type: application/json" \
    --data "{\"username\":\"$1\",\"password\":\"$2\"}" | jq -r '.data.token // empty'
}
READ=$(login reader 'ReaderPass123!')
WRITE=$(login writer 'WriterPass123!')
[ -n "$READ" ] && [ -n "$WRITE" ] || setup_error "Logging in as reader/writer failed. Check that the login route is in public/index.php."

# Build a correctly signed token that expired an hour ago
b64url() { openssl base64 -A | tr '+/' '-_' | tr -d '='; }
now=$(date +%s)
jwt_head=$(printf '{"alg":"HS256","typ":"JWT"}' | b64url)
jwt_body=$(printf '{"sub":"reader","scope":"read","iat":%d,"exp":%d}' $((now - 7200)) $((now - 3600)) | b64url)
jwt_sig=$(printf '%s.%s' "$jwt_head" "$jwt_body" | openssl dgst -sha256 -hmac "$JWT_SECRET" -binary | b64url)
EXPIRED="$jwt_head.$jwt_body.$jwt_sig"

BAD="not.a.jwt"

pass=0; fail=0

# t ID METHOD PATH TOKEN EXPECTED_STATUS JQ_CHECK [JSON_BODY]
t() {
  local id=$1 method=$2 path=$3 token=$4 want=$5 check=$6 body=${7:-}
  local args=(-s -o "$BODY" -w '%{http_code}' -X "$method" "$BASE$path")
  [ -n "$token" ] && args+=(-H "Authorization: Bearer $token")
  [ -n "$body" ]  && args+=(-H "Content-Type: application/json" --data "$body")
  local got
  got=$(curl "${args[@]}")
  if [ "$got" = "$want" ] && jq -e "$check" "$BODY" >/dev/null 2>&1; then
    echo "PASS $id"; pass=$((pass + 1))
  else
    echo "FAIL $id: $method $path -> status $got (expected $want); body: $(head -c 300 "$BODY")"
    fail=$((fail + 1))
  fi
}

E400='.error.code == "BAD_REQUEST"'
E401='.error.code == "UNAUTHORIZED"'
E403='.error.code == "FORBIDDEN"'
E404='.error.code == "NOT_FOUND"'
E405='.error.code == "METHOD_NOT_ALLOWED"'

# ---- Token tests (rejected before validation, so no data changes) ----
ENDPOINTS=(
  "E01|GET|/api/v1/scripts"
  "E02|GET|/api/v1/scripts/1"
  "E03|GET|/api/v1/runs"
  "E04|GET|/api/v1/runs/$R1"
  "E05|POST|/api/v1/runs"
  "E06|PATCH|/api/v1/runs/$R3"
  "E07|GET|/api/v1/runs/$R1/logs"
  "E08|POST|/api/v1/runs/$R1/logs"
  "E09|GET|/api/v1/runs/$R2/exceptions"
  "E10|GET|/api/v1/runs/$R1/metrics"
  "E11|GET|/api/v1/reports/execution-summary"
  "E12|GET|/api/v1/reports/failing-scripts"
)
# For every protected endpoint (E01-E12):
#   <ID>-TM: no Authorization header at all -> 401 UNAUTHORIZED
#   <ID>-TI: a token that is not a valid JWT ("not.a.jwt") -> 401 UNAUTHORIZED
# Write endpoints get an empty JSON body, which proves authentication runs
# before input validation (otherwise they would return 400, not 401).
for e in "${ENDPOINTS[@]}"; do
  IFS='|' read -r id method path <<< "$e"
  body=""; [ "$method" != "GET" ] && body='{}'
  t "$id-TM" "$method" "$path" ""     401 "$E401" "$body"
  t "$id-TI" "$method" "$path" "$BAD" 401 "$E401" "$body"
done
# E01-TX: Expired token: a correctly signed JWT whose exp is in the past must be rejected (401), proving expiry is checked, not just the signature.
t E01-TX GET   /api/v1/scripts       "$EXPIRED" 401 "$E401"
# E05-TS: Scope check: a valid read-only token cannot start a run; expect 403 FORBIDDEN, and no run is created.
t E05-TS POST  /api/v1/runs          "$READ"    403 "$E403" '{"scriptName":"Test-BackupServers","startTimeUtc":"2026-11-07T12:00:00.000Z"}'
# E06-TS: Scope check: a valid read-only token cannot update a run; expect 403 FORBIDDEN, and R3 stays Running.
t E06-TS PATCH /api/v1/runs/$R3      "$READ"    403 "$E403" '{"status":"Success","endTimeUtc":"2026-11-03T08:10:00.000Z"}'
# E08-TS: Scope check: a valid read-only token cannot add a log entry; expect 403 FORBIDDEN, and nothing is inserted.
t E08-TS POST  /api/v1/runs/$R1/logs "$READ"    403 "$E403" '{"timestampUtc":"2026-11-01T08:06:00.000Z","level":"Information"}'

# ---- Login tests (public endpoint) ----
JWT_RE='^[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+$'
# E00-S1: Login success (read user): correct username/password returns 200 with a three-part JWT, tokenType Bearer, 1-hour expiry, and scope copied from ApiUser (read).
t E00-S1 POST /api/v1/auth/login "" 200 "(.data.token | test(\"$JWT_RE\")) and .data.tokenType == \"Bearer\" and .data.expiresIn == 3600 and .data.scope == \"read\"" '{"username":"reader","password":"ReaderPass123!"}'
# E00-S2: Login success (read-write user): the token's scope reflects the writer account's ApiUser.Scope (read write).
t E00-S2 POST /api/v1/auth/login "" 200 '.data.scope == "read write"' '{"username":"writer","password":"WriterPass123!"}'
# E00-F1: Login failure: a known user with the wrong password fails password_verify(); expect 401 UNAUTHORIZED.
t E00-F1 POST /api/v1/auth/login "" 401 "$E401" '{"username":"reader","password":"WrongPass!"}'
# E00-F2: Login failure: an unknown username returns the same 401 as a wrong password, so the API does not reveal which usernames exist.
t E00-F2 POST /api/v1/auth/login "" 401 "$E401" '{"username":"nobody","password":"Whatever1!"}'
# E00-F3: Login validation: a body missing the password field is rejected with 400 before any database lookup.
t E00-F3 POST /api/v1/auth/login "" 400 "$E400" '{"username":"reader"}'
# E00-F4: Login validation: a body that is not valid JSON is rejected with 400.
t E00-F4 POST /api/v1/auth/login "" 400 "$E400" '{not json'

# ---- Read tests ----
# E01-S1: List scripts: returns both seeded scripts, sorted by scriptId ascending (Test-BackupServers first).
t E01-S1 GET    /api/v1/scripts "$READ" 200 '(.data | length) == 2 and .data[0].scriptName == "Test-BackupServers"'
# E01-F1: Unsupported method: DELETE on the scripts collection is not part of the API; expect 405 METHOD_NOT_ALLOWED.
t E01-F1 DELETE /api/v1/scripts "$READ" 405 "$E405"

# E02-S1: Script details: script 1 returns its name plus run counts computed from ScriptRun (2 total, 1 success, 1 failed).
t E02-S1 GET /api/v1/scripts/1   "$READ" 200 '.data.scriptName == "Test-BackupServers" and .data.totalExecutions == 2 and .data.successfulExecutions == 1 and .data.failedExecutions == 1'
# E02-F1: Script not found: a numeric ID with no catalog row returns 404 NOT_FOUND.
t E02-F1 GET /api/v1/scripts/999 "$READ" 404 "$E404"
# E02-F2: Input validation: a non-numeric script ID is rejected with 400 instead of reaching the database.
t E02-F2 GET /api/v1/scripts/abc "$READ" 400 "$E400"

# E03-S1: List runs: returns all 3 seeded runs, newest first (R3 started latest, on 2026-11-03).
t E03-S1 GET "/api/v1/runs"                          "$READ" 200 "(.data | length) == 3 and .data[0].runId == \"$R3\""
# E03-S2: Combined filters: scriptId=1 AND status=Failed returns only R2, proving both filters are applied together.
t E03-S2 GET "/api/v1/runs?scriptId=1&status=Failed" "$READ" 200 "(.data | length) == 1 and .data[0].runId == \"$R2\""
# E03-S3: Pagination: limit=2 returns 2 rows, and pagination.total still reports all 3 matching runs.
t E03-S3 GET "/api/v1/runs?limit=2&page=1"           "$READ" 200 '(.data | length) == 2 and .pagination.total == 3'
# E03-F1: Input validation: a status outside Running/Success/Failed/Cancelled is rejected with 400.
t E03-F1 GET "/api/v1/runs?status=Bogus"             "$READ" 400 "$E400"
# E03-F2: Input validation: an unparseable 'from' date is rejected with 400.
t E03-F2 GET "/api/v1/runs?from=notadate"            "$READ" 400 "$E400"

# E04-S1: Run details: R1 returns its stored status, duration, and correlation ID exactly as seeded.
t E04-S1 GET /api/v1/runs/$R1        "$READ" 200 ".data.status == \"Success\" and .data.durationMs == 300000 and .data.correlationId == \"$C\""
# E04-F1: Run not found: a well-formed GUID with no ScriptRun row returns 404 NOT_FOUND.
t E04-F1 GET /api/v1/runs/$RX        "$READ" 404 "$E404"
# E04-F2: Input validation: a run ID that is not a GUID is rejected with 400.
t E04-F2 GET /api/v1/runs/not-a-guid "$READ" 400 "$E400"

# E07-S1: Run logs: R1 returns its 3 log entries in time order (Start is first).
t E07-S1 GET "/api/v1/runs/$R1/logs"               "$READ" 200 '(.data | length) == 3 and .data[0].eventName == "Start"'
# E07-S2: Level filter: level=Warning returns only R1's single Warning entry (SlowDisk).
t E07-S2 GET "/api/v1/runs/$R1/logs?level=Warning" "$READ" 200 '(.data | length) == 1 and .data[0].eventName == "SlowDisk"'
# E07-F1: Parent run not found: logs for a nonexistent run return 404, not an empty list.
t E07-F1 GET "/api/v1/runs/$RX/logs"               "$READ" 404 "$E404"
# E07-F2: Input validation: a level outside Trace..Critical is rejected with 400.
t E07-F2 GET "/api/v1/runs/$R1/logs?level=Loud"    "$READ" 400 "$E400"

# E09-S1: Run exceptions: failed run R2 returns its one System.IO.IOException record.
t E09-S1 GET /api/v1/runs/$R2/exceptions "$READ" 200 '(.data | length) == 1 and .data[0].exceptionType == "System.IO.IOException"'
# E09-S2: Empty result: a run that exists but has no exceptions returns 200 with an empty array, not 404.
t E09-S2 GET /api/v1/runs/$R1/exceptions "$READ" 200 '.data == []'
# E09-F1: Parent run not found: exceptions for a nonexistent run return 404.
t E09-F1 GET /api/v1/runs/$RX/exceptions "$READ" 404 "$E404"

# E10-S1: Run metrics: R1 returns both metrics, and FilesCopied has the numeric value 1200 (a JSON number, not a string).
t E10-S1 GET /api/v1/runs/$R1/metrics "$READ" 200 '(.data | length) == 2 and ([.data[] | select(.metricName == "FilesCopied") | .metricValue] == [1200])'
# E10-F1: Parent run not found: metrics for a nonexistent run return 404.
t E10-F1 GET /api/v1/runs/$RX/metrics "$READ" 404 "$E404"

# E11-S1: Summary report: November totals by status are correct, successRate = 1/(1+1) = 0.5, and averageDurationMs = (300000+60000)/2 = 180000 over completed runs only.
t E11-S1 GET "/api/v1/reports/execution-summary?from=2026-11-01&to=2026-11-30" "$READ" 200 '.data.totalExecutions == 3 and .data.successfulExecutions == 1 and .data.failedExecutions == 1 and .data.runningExecutions == 1 and .data.cancelledExecutions == 0 and .data.successRate == 0.5 and .data.averageDurationMs == 180000'
# E11-S2: Team filter: team=Desktop counts only runs of scripts owned by the Desktop team (1 run, R3), proving the ScriptCatalog join.
t E11-S2 GET "/api/v1/reports/execution-summary?team=Desktop"                  "$READ" 200 '.data.totalExecutions == 1'
# E11-F1: Input validation: a date range where 'from' is after 'to' is rejected with 400.
t E11-F1 GET "/api/v1/reports/execution-summary?from=2026-11-30&to=2026-11-01" "$READ" 400 "$E400"
# E11-F2: Input validation: a non-ISO date value ('yesterday') is rejected with 400.
t E11-F2 GET "/api/v1/reports/execution-summary?from=yesterday"                "$READ" 400 "$E400"

# E12-S1: Failing-scripts report: only scripts with at least one failure are listed; Test-BackupServers has 1 failure out of 2 runs (failureRate 0.5).
t E12-S1 GET "/api/v1/reports/failing-scripts?limit=5" "$READ" 200 '(.data | length) == 1 and .data[0].scriptName == "Test-BackupServers" and .data[0].failureCount == 1 and .data[0].totalExecutions == 2 and .data[0].failureRate == 0.5'
# E12-F1: Input validation: limit must be a positive integer; limit=0 is rejected with 400.
t E12-F1 GET "/api/v1/reports/failing-scripts?limit=0" "$READ" 400 "$E400"

# ---- Write tests (run last; they change data) ----
# E05-S1: Start run (existing script): returns 201 with a server-generated GUID runId, the existing scriptId 1, and status Running.
t E05-S1 POST /api/v1/runs "$WRITE" 201 '(.data.runId | test("^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")) and .data.scriptId == 1 and .data.status == "Running"' \
  "{\"scriptName\":\"Test-BackupServers\",\"scriptVersion\":\"1.1.0\",\"computerName\":\"TEST01\",\"userName\":\"tester\",\"startTimeUtc\":\"2026-11-07T12:00:00.000Z\",\"correlationId\":\"$C\"}"
# E05-S2: Start run (new script): an unknown scriptName is auto-registered in ScriptCatalog and gets a new scriptId (> 2).
t E05-S2 POST /api/v1/runs    "$WRITE" 201 '.data.scriptId > 2 and .data.status == "Running"' '{"scriptName":"Test-NewScript","startTimeUtc":"2026-11-07T12:30:00.000Z"}'
# E05-V1: Side-effect check: after E05-S2, the catalog lists 3 scripts including Test-NewScript, proving the insert was committed.
t E05-V1 GET  /api/v1/scripts "$READ"  200 '(.data | length) == 3 and any(.data[]; .scriptName == "Test-NewScript")'
# E05-F1: Input validation: a start-run body without the required scriptName is rejected with 400.
t E05-F1 POST /api/v1/runs    "$WRITE" 400 "$E400" '{"startTimeUtc":"2026-11-07T12:00:00.000Z"}'
# E05-F2: Input validation: a start-run body that is not valid JSON is rejected with 400.
t E05-F2 POST /api/v1/runs    "$WRITE" 400 "$E400" '{not json'

# E06-S1: Finish run: R3 changes from Running to Success, and the server computes durationMs = end - start = 10 minutes = 600000.
t E06-S1 PATCH /api/v1/runs/$R3 "$WRITE" 200 '.data.status == "Success" and .data.durationMs == 600000' '{"status":"Success","endTimeUtc":"2026-11-03T08:10:00.000Z","message":"Cleanup finished"}'
# E06-F1: Run not found: updating a nonexistent run returns 404 NOT_FOUND.
t E06-F1 PATCH /api/v1/runs/$RX "$WRITE" 404 "$E404" '{"status":"Success","endTimeUtc":"2026-11-03T08:10:00.000Z"}'
# E06-F2: Input validation: a status outside Success/Failed/Cancelled ('Done') is rejected with 400.
t E06-F2 PATCH /api/v1/runs/$R3 "$WRITE" 400 "$E400" '{"status":"Done","endTimeUtc":"2026-11-03T08:10:00.000Z"}'

# E08-S1: Add log entry: returns 201 with a new logId, attached to R1, with the submitted level; jsonData is accepted as an object.
t E08-S1 POST /api/v1/runs/$R1/logs "$WRITE" 201 ".data.logId > 0 and .data.runId == \"$R1\" and .data.level == \"Information\"" '{"timestampUtc":"2026-11-01T08:06:00.000Z","level":"Information","eventName":"Verify","message":"Verification passed","jsonData":{"files":1200}}'
# E08-F1: Input validation: a log level outside Trace..Critical is rejected with 400.
t E08-F1 POST /api/v1/runs/$R1/logs "$WRITE" 400 "$E400" '{"timestampUtc":"2026-11-01T08:06:00.000Z","level":"Loud","eventName":"Verify"}'
# E08-F2: Parent run not found: adding a log entry to a nonexistent run returns 404.
t E08-F2 POST /api/v1/runs/$RX/logs "$WRITE" 404 "$E404" '{"timestampUtc":"2026-11-01T08:06:00.000Z","level":"Information","eventName":"Verify"}'

echo "----"
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
