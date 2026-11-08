# Acceptance Tests: InfraLogging REST API

These tests define when an API endpoint is "done." The same tests run, unchanged, against **Stage 1** (raw PHP + MySQL behind NGINX) and **Stage 2** (Laravel). Only the base URL changes between stages.

- **Scope:** the 13 APIs approved in the HW1 proposal (`E00`–`E12` below). `E00` is the login endpoint that issues the bearer tokens the other 12 APIs require.
- **Runner:** `tests/acceptance.sh` (full script in the appendix). It checks both the HTTP status and the JSON content of every response, prints `PASS`/`FAIL` per test, and exits nonzero if any test fails.
- **Rule:** a failing test is fixed in the code, never by weakening the test.

---

## 1. API Contract Assumed by the Tests

**Base URL.** Set with the `BASE` environment variable (for example `http://localhost:8080` for Stage 1).

**Authentication.** Every endpoint except `POST /api/v1/auth/login` requires `Authorization: Bearer <JWT>`. A client gets a token by posting a username and password to the login endpoint. The server looks the user up in the `ApiUser` table and verifies the password against the stored bcrypt hash with `password_verify()`.

Tokens are HS256 JWTs signed with the `JWT_SECRET` value from `.env`, valid for 1 hour, with these claims:
- `sub`: the username.
- `scope`: copied from `ApiUser.Scope`, either `read` or `read write`.
- `iat` and `exp`: issued-at and expiry times.

User accounts are created out of band by an administrator, so there is no registration endpoint. Stage 2 issues and validates tokens with the same table, secret, and algorithm, so the same tests and credentials work in both stages.

**Login response.** A successful login returns `200` with `{"data": {"token", "tokenType": "Bearer", "expiresIn": 3600, "scope"}}`. A wrong password and an unknown username return the same `401` response, so the API does not reveal which usernames exist.

**Check order.** On protected endpoints, the server checks authentication (401) first, then scope (403), then input validation (400), then existence (404). Because a rejected token never reaches validation or the database, token tests can run in any order without changing data.

**Success envelope.** Success responses use `{"data": ...}`. List endpoints add `"pagination": {"page", "limit", "total"}` where pagination applies.

**Error envelope.** Error responses use `{"error": {"code": "...", "message": "..."}}`:

| Status | `error.code` | When |
|---:|---|---|
| 400 | `BAD_REQUEST` | Malformed JSON, missing required field, invalid value, ID, or query parameter |
| 401 | `UNAUTHORIZED` | Missing, malformed, badly signed, or expired token; failed login |
| 403 | `FORBIDDEN` | Valid token without the required scope (read-only token on a write endpoint) |
| 404 | `NOT_FOUND` | Script or run does not exist |
| 405 | `METHOD_NOT_ALLOWED` | HTTP method not supported on that path |

**Field names.** JSON fields are camelCase (`scriptId`, `runId`, `startTimeUtc`, `durationMs`). Timestamps are ISO 8601 UTC, for example `2026-11-01T08:00:00.000Z`.

**Ordering.**
- Scripts are sorted by `scriptId` ascending.
- Runs are sorted by `startTimeUtc` descending.
- Logs, exceptions, and metrics are sorted by `timestampUtc` ascending.

**Report definitions.**
- `successRate` = successful ÷ (successful + failed + cancelled).
- `averageDurationMs` is the average over completed runs only.
- `failing-scripts` lists only scripts with at least one failed run.

---

## 2. Data Setup

The runner resets the database to this known state before every run, so the results are repeatable. Save the SQL below as `tests/seed.sql`. It assumes the `InfrastructureLogging` schema from `Deploy-InfraLogging.mysql.sql` is already deployed.

```sql
USE InfrastructureLogging;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE ApiUser;
TRUNCATE TABLE PerformanceMetric;
TRUNCATE TABLE ExceptionLog;
TRUNCATE TABLE LogEntry;
TRUNCATE TABLE ScriptRun;
TRUNCATE TABLE ScriptCatalog;
SET FOREIGN_KEY_CHECKS = 1;

-- Test-only credentials (bcrypt, PHP password_hash format):
--   reader / ReaderPass123!   scope: read
--   writer / WriterPass123!   scope: read write
INSERT INTO ApiUser (UserName, PasswordHash, Scope) VALUES
 ('reader', '$2y$10$N3QGp8tS6ikhaaS67Qntfu6fX52KviphMEr83TKny7Xgvp4q/AZda', 'read'),
 ('writer', '$2y$10$vETxkGXwDeTswbw5vcM22OHfPOCSHZtwyHDEH8pf.s73b80bN5VMu', 'read write');

INSERT INTO ScriptCatalog (ScriptID, ScriptName, Description, Team, Repository, Owner) VALUES
 (1, 'Test-BackupServers', 'Nightly server backup', 'Infrastructure', 'https://github.com/example/backup', 'ops-team'),
 (2, 'Test-CleanupTemp',   'Temp folder cleanup',   'Desktop',        NULL,                                'desk-team');

-- R1 = Success, R2 = Failed, R3 = Running (used by the PATCH test)
INSERT INTO ScriptRun
 (RunID, ScriptID, ScriptName, ScriptVersion, ComputerName, UserName,
  StartTimeUtc, EndTimeUtc, Status, DurationMS, CorrelationID, Message) VALUES
 ('11111111-1111-4111-8111-111111111111', 1, 'Test-BackupServers', '1.0.0', 'SRV01', 'svc-ops',
  '2026-11-01 08:00:00.000', '2026-11-01 08:05:00.000', 'Success', 300000,
  'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'Backup complete'),
 ('22222222-2222-4222-8222-222222222222', 1, 'Test-BackupServers', '1.0.0', 'SRV01', 'svc-ops',
  '2026-11-02 08:00:00.000', '2026-11-02 08:01:00.000', 'Failed', 60000,
  'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'Disk full'),
 ('33333333-3333-4333-8333-333333333333', 2, 'Test-CleanupTemp', '2.0.0', 'PC42', 'svc-desk',
  '2026-11-03 08:00:00.000', NULL, 'Running', NULL,
  'cccccccc-cccc-4ccc-8ccc-cccccccccccc', NULL);

INSERT INTO LogEntry (RunID, TimestampUtc, Level, EventName, Message) VALUES
 ('11111111-1111-4111-8111-111111111111', '2026-11-01 08:00:01.000', 'Information', 'Start',        'Backup started'),
 ('11111111-1111-4111-8111-111111111111', '2026-11-01 08:03:00.000', 'Warning',     'SlowDisk',     'Disk latency high'),
 ('11111111-1111-4111-8111-111111111111', '2026-11-01 08:04:59.000', 'Information', 'CopyComplete', 'All files copied'),
 ('22222222-2222-4222-8222-222222222222', '2026-11-02 08:00:01.000', 'Information', 'Start',        'Backup started'),
 ('22222222-2222-4222-8222-222222222222', '2026-11-02 08:00:59.000', 'Error',       'CopyFailed',   'Disk full');

INSERT INTO ExceptionLog (RunID, TimestampUtc, ExceptionType, Message) VALUES
 ('22222222-2222-4222-8222-222222222222', '2026-11-02 08:00:59.000', 'System.IO.IOException', 'There is not enough space on the disk.');

INSERT INTO PerformanceMetric (RunID, TimestampUtc, MetricName, MetricValue, Unit) VALUES
 ('11111111-1111-4111-8111-111111111111', '2026-11-01 08:05:00.000', 'FilesCopied', 1200,       'count'),
 ('11111111-1111-4111-8111-111111111111', '2026-11-01 08:05:00.000', 'BytesCopied', 5368709120, 'bytes');
```

**Identifiers used in the tests:**

| Name | Value | Meaning |
|---|---|---|
| `R1` | `11111111-1111-4111-8111-111111111111` | Successful run of script 1 (3 logs, 2 metrics, no exceptions) |
| `R2` | `22222222-2222-4222-8222-222222222222` | Failed run of script 1 (2 logs, 1 exception) |
| `R3` | `33333333-3333-4333-8333-333333333333` | Running run of script 2 |
| `RX` | `99999999-9999-4999-8999-999999999999` | Run ID that does not exist |
| `C`  | `cccccccc-cccc-4ccc-8ccc-cccccccccccc` | Correlation ID |
| `reader` | password `ReaderPass123!` | Login account with `read` scope |
| `writer` | password `WriterPass123!` | Login account with `read write` scope |

These passwords exist only in the test seed. Never reuse them on a real server.

**Test order.** Read tests run first. Write tests (`E05`, `E06`, `E08`) run last, so the read and report expectations always match the seed data.

**Requirements.** The test machine needs `bash`, `curl`, `jq`, `openssl`, and the `mysql` client. The runner also needs `JWT_SECRET` set to the same value the server uses, so it can build the expired-token test case.

---

## 3. Endpoint Tests

**Tokens used in these tables:**
- **READ**: token from logging in as `reader`.
- **WRITE**: token from logging in as `writer`.
- **none**: no `Authorization` header.
- **BAD**: the string `not.a.jwt`.
- **EXPIRED**: correctly signed token with `exp` in the past.

### E00 `POST /api/v1/auth/login` (public)

| ID | Case | Request | Token | Status | Content check |
|---|---|---|---|---:|---|
| E00-S1 | Success: read user logs in | body: `username` `reader`, `password` `ReaderPass123!` | none | 200 | `token` is a three-part JWT; `tokenType` = `Bearer`; `expiresIn` = 3600; `scope` = `read` |
| E00-S2 | Success: read-write user logs in | body: `username` `writer`, `password` `WriterPass123!` | none | 200 | `scope` = `read write` |
| E00-F1 | Failure: wrong password | `reader` with password `WrongPass!` | none | 401 | `UNAUTHORIZED` |
| E00-F2 | Failure: unknown user | `nobody` with any password | none | 401 | `UNAUTHORIZED` (same response as E00-F1) |
| E00-F3 | Failure: missing password | body with `username` only | none | 400 | `BAD_REQUEST` |
| E00-F4 | Failure: malformed JSON | body `{not json` | none | 400 | `BAD_REQUEST` |

### E01 `GET /api/v1/scripts`

| ID | Case | Request | Token | Status | Content check |
|---|---|---|---|---:|---|
| E01-S1 | Success: list catalog | `GET /api/v1/scripts` | READ | 200 | 2 scripts; first is `Test-BackupServers` |
| E01-F1 | Failure: unsupported method | `DELETE /api/v1/scripts` | READ | 405 | `error.code` = `METHOD_NOT_ALLOWED` |

### E02 `GET /api/v1/scripts/{scriptId}`

| ID | Case | Request | Token | Status | Content check |
|---|---|---|---|---:|---|
| E02-S1 | Success: script details and counts | `GET /api/v1/scripts/1` | READ | 200 | `scriptName` = `Test-BackupServers`; `totalExecutions` = 2; `successfulExecutions` = 1; `failedExecutions` = 1 |
| E02-F1 | Failure: unknown ID | `GET /api/v1/scripts/999` | READ | 404 | `NOT_FOUND` |
| E02-F2 | Failure: non-numeric ID | `GET /api/v1/scripts/abc` | READ | 400 | `BAD_REQUEST` |

### E03 `GET /api/v1/runs`

| ID | Case | Request | Token | Status | Content check |
|---|---|---|---|---:|---|
| E03-S1 | Success: all runs, newest first | `GET /api/v1/runs` | READ | 200 | 3 runs; first `runId` = R3 |
| E03-S2 | Success: filter by script and status | `GET /api/v1/runs?scriptId=1&status=Failed` | READ | 200 | 1 run; `runId` = R2 |
| E03-S3 | Success: pagination | `GET /api/v1/runs?limit=2&page=1` | READ | 200 | 2 runs; `pagination.total` = 3 |
| E03-F1 | Failure: invalid status filter | `GET /api/v1/runs?status=Bogus` | READ | 400 | `BAD_REQUEST` |
| E03-F2 | Failure: invalid date | `GET /api/v1/runs?from=notadate` | READ | 400 | `BAD_REQUEST` |

### E04 `GET /api/v1/runs/{runId}`

| ID | Case | Request | Token | Status | Content check |
|---|---|---|---|---:|---|
| E04-S1 | Success: run details | `GET /api/v1/runs/R1` | READ | 200 | `status` = `Success`; `durationMs` = 300000; `correlationId` = C |
| E04-F1 | Failure: unknown run | `GET /api/v1/runs/RX` | READ | 404 | `NOT_FOUND` |
| E04-F2 | Failure: malformed GUID | `GET /api/v1/runs/not-a-guid` | READ | 400 | `BAD_REQUEST` |

### E05 `POST /api/v1/runs` (write)

| ID | Case | Request | Token | Status | Content check |
|---|---|---|---|---:|---|
| E05-S1 | Success: start run of an existing script | body: `scriptName` `Test-BackupServers`, `scriptVersion`, `computerName`, `userName`, `startTimeUtc`, `correlationId` | WRITE | 201 | `runId` is a GUID; `scriptId` = 1; `status` = `Running` |
| E05-S2 | Success: first run of a new script registers it in the catalog | body: `scriptName` `Test-NewScript`, `startTimeUtc` | WRITE | 201 | `scriptId` > 2; `status` = `Running` |
| E05-V1 | Verify: catalog now has the new script | `GET /api/v1/scripts` | READ | 200 | 3 scripts, including `Test-NewScript` |
| E05-F1 | Failure: missing `scriptName` | body without `scriptName` | WRITE | 400 | `BAD_REQUEST` |
| E05-F2 | Failure: malformed JSON | body `{not json` | WRITE | 400 | `BAD_REQUEST` |

### E06 `PATCH /api/v1/runs/{runId}` (write)

| ID | Case | Request | Token | Status | Content check |
|---|---|---|---|---:|---|
| E06-S1 | Success: finish a running run | `PATCH /api/v1/runs/R3`, body: `status` `Success`, `endTimeUtc` `2026-11-03T08:10:00.000Z`, `message` | WRITE | 200 | `status` = `Success`; `durationMs` = 600000 (computed by the server) |
| E06-F1 | Failure: unknown run | `PATCH /api/v1/runs/RX` (valid body) | WRITE | 404 | `NOT_FOUND` |
| E06-F2 | Failure: invalid status | `PATCH /api/v1/runs/R3`, `status` `Done` | WRITE | 400 | `BAD_REQUEST` |

### E07 `GET /api/v1/runs/{runId}/logs`

| ID | Case | Request | Token | Status | Content check |
|---|---|---|---|---:|---|
| E07-S1 | Success: all logs for a run | `GET /api/v1/runs/R1/logs` | READ | 200 | 3 entries; first `eventName` = `Start` |
| E07-S2 | Success: filter by level | `GET /api/v1/runs/R1/logs?level=Warning` | READ | 200 | 1 entry; `eventName` = `SlowDisk` |
| E07-F1 | Failure: unknown run | `GET /api/v1/runs/RX/logs` | READ | 404 | `NOT_FOUND` |
| E07-F2 | Failure: invalid level | `GET /api/v1/runs/R1/logs?level=Loud` | READ | 400 | `BAD_REQUEST` |

### E08 `POST /api/v1/runs/{runId}/logs` (write)

| ID | Case | Request | Token | Status | Content check |
|---|---|---|---|---:|---|
| E08-S1 | Success: add a log entry | `POST /api/v1/runs/R1/logs`, body: `timestampUtc`, `level` `Information`, `eventName` `Verify`, `message`, `jsonData` object | WRITE | 201 | `logId` > 0; `runId` = R1; `level` = `Information` |
| E08-F1 | Failure: invalid level | same, `level` `Loud` | WRITE | 400 | `BAD_REQUEST` |
| E08-F2 | Failure: unknown run | `POST /api/v1/runs/RX/logs` (valid body) | WRITE | 404 | `NOT_FOUND` |

### E09 `GET /api/v1/runs/{runId}/exceptions`

| ID | Case | Request | Token | Status | Content check |
|---|---|---|---|---:|---|
| E09-S1 | Success: run with an exception | `GET /api/v1/runs/R2/exceptions` | READ | 200 | 1 record; `exceptionType` = `System.IO.IOException` |
| E09-S2 | Success: run without exceptions | `GET /api/v1/runs/R1/exceptions` | READ | 200 | empty array (not 404) |
| E09-F1 | Failure: unknown run | `GET /api/v1/runs/RX/exceptions` | READ | 404 | `NOT_FOUND` |

### E10 `GET /api/v1/runs/{runId}/metrics`

| ID | Case | Request | Token | Status | Content check |
|---|---|---|---|---:|---|
| E10-S1 | Success: run metrics | `GET /api/v1/runs/R1/metrics` | READ | 200 | 2 metrics; `FilesCopied` has `metricValue` 1200 |
| E10-F1 | Failure: unknown run | `GET /api/v1/runs/RX/metrics` | READ | 404 | `NOT_FOUND` |

### E11 `GET /api/v1/reports/execution-summary`

| ID | Case | Request | Token | Status | Content check |
|---|---|---|---|---:|---|
| E11-S1 | Success: summary for November | `?from=2026-11-01&to=2026-11-30` | READ | 200 | total 3; success 1; failed 1; running 1; cancelled 0; `successRate` 0.5; `averageDurationMs` 180000 |
| E11-S2 | Success: filter by team | `?team=Desktop` | READ | 200 | `totalExecutions` = 1 |
| E11-F1 | Failure: `from` after `to` | `?from=2026-11-30&to=2026-11-01` | READ | 400 | `BAD_REQUEST` |
| E11-F2 | Failure: invalid date | `?from=yesterday` | READ | 400 | `BAD_REQUEST` |

### E12 `GET /api/v1/reports/failing-scripts`

| ID | Case | Request | Token | Status | Content check |
|---|---|---|---|---:|---|
| E12-S1 | Success: ranked failures | `?limit=5` | READ | 200 | 1 script: `Test-BackupServers`; `failureCount` 1; `totalExecutions` 2; `failureRate` 0.5 |
| E12-F1 | Failure: invalid limit | `?limit=0` | READ | 400 | `BAD_REQUEST` |

---

## 4. Token Tests

Every endpoint except the login endpoint is protected. The success cases in Section 3 already cover the **valid token** case for every protected endpoint, and each valid token comes from a real login. The matrix below adds the missing-token and invalid-token cases for every endpoint, plus expired-token and wrong-scope cases. Every rejection must return the error envelope with the matching `error.code`.

| Endpoint | Missing token (`-TM`) | Invalid token (`-TI`) | Other |
|---|---:|---:|---|
| E01 `GET /scripts` | 401 | 401 | `E01-TX`: expired token → 401 |
| E02 `GET /scripts/1` | 401 | 401 | |
| E03 `GET /runs` | 401 | 401 | |
| E04 `GET /runs/R1` | 401 | 401 | |
| E05 `POST /runs` | 401 | 401 | `E05-TS`: READ token → 403 |
| E06 `PATCH /runs/R3` | 401 | 401 | `E06-TS`: READ token → 403 |
| E07 `GET /runs/R1/logs` | 401 | 401 | |
| E08 `POST /runs/R1/logs` | 401 | 401 | `E08-TS`: READ token → 403 |
| E09 `GET /runs/R2/exceptions` | 401 | 401 | |
| E10 `GET /runs/R1/metrics` | 401 | 401 | |
| E11 `GET /reports/execution-summary` | 401 | 401 | |
| E12 `GET /reports/failing-scripts` | 401 | 401 | |

**Total: 73 tests** (45 endpoint tests and 28 token tests).

---

## 5. How to Run

```bash
# Stage 1 (raw PHP behind NGINX)
export JWT_SECRET='<same value as the server .env>'
BASE=http://localhost:8080 ./tests/acceptance.sh | tee results/stage1-$(date +%F).txt

# Stage 2 (Laravel); MYSQL points at the Docker database if needed
BASE=http://localhost:8081 MYSQL="docker compose exec -T db mysql -uroot -p<password>" \
  ./tests/acceptance.sh | tee results/stage2-$(date +%F).txt
```

The exit code is 0 only when all 73 tests pass. If the database reset or the setup logins fail, the runner stops with exit code 2.

---

## 6. Results

Record every run here and keep the matching output file in `results/`.

| Date | Stage | Command | Expected | Actual | Output file / notes |
|---|---|---|---|---|---|
| | Stage 1 | `BASE=http://localhost:8080 ./tests/acceptance.sh` | 73 passed, 0 failed, exit 0 | | |
| | Stage 2 | `BASE=http://localhost:8081 ./tests/acceptance.sh` | 73 passed, 0 failed, exit 0 | | |

---

## Appendix: `tests/acceptance.sh`

```bash
#!/usr/bin/env bash
# Acceptance tests for the InfraLogging REST API.
# Usage: BASE=http://localhost:8080 ./tests/acceptance.sh
set -u

BASE="${BASE:-http://localhost:8080}"
MYSQL="${MYSQL:-mysql}"   # credentials come from ~/.my.cnf or the MYSQL variable
DIR="$(cd "$(dirname "$0")" && pwd)"
BODY="$(mktemp)"
trap 'rm -f "$BODY"' EXIT

# ---- Data setup ----
$MYSQL < "$DIR/seed.sql" || { echo "Database reset failed"; exit 2; }

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
[ -n "$READ" ] && [ -n "$WRITE" ] || { echo "Setup login failed"; exit 2; }

# Build a correctly signed token that expired an hour ago
: "${JWT_SECRET:?Set JWT_SECRET to the same secret the server uses}"
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
```
