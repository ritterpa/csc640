---
marp: true
size: 4:3
paginate: true
title: InfraLogging REST API – Stage 1 Tutorial
style: |
  section { font-size: 22px; padding: 44px 52px; }
  h1 { font-size: 40px; }
  h2 { font-size: 30px; margin-bottom: 12px; }
  table { font-size: 17px; }
  pre { font-size: 13px; line-height: 1.32; white-space: pre-wrap; word-break: break-all; margin: 6px 0 10px; }
  p { margin: 6px 0; }
  section.example { font-size: 18px; padding-top: 28px; padding-bottom: 26px; }
  section.example h2 { font-size: 26px; margin-bottom: 6px; }
  section.example pre { font-size: 12px; line-height: 1.28; margin: 4px 0 6px; }
  section.reference td, section.reference th { padding: 4px 8px; }
  section.lead h1 { font-size: 44px; }
---

<!-- _class: lead -->
<!-- _paginate: skip -->

# InfraLogging REST API

## Stage 1 Tutorial: raw PHP + MySQL behind NGINX

Paul Ritter · CSC 640 · HW4 Stage 1

Repository: https://github.com/ritterpa/csc640 (tag `stage1`)

Every example in this tutorial is a real request and response from the Stage 1 server, captured with the seed data loaded.

---

## What the API does

IT Operations runs many PowerShell scripts that log their runs, log entries, exceptions, and metrics to MySQL. This API serves that data as JSON, so people and tools no longer need to query SQL directly.

- **13 endpoints:** 1 login, 9 reads, and 3 writes (start a run, finish a run, add a log entry).
- **Base URL:** `http://localhost:8180` (NGINX → PHP-FPM → MySQL).
- **Bearer tokens:** every endpoint except login requires one.
- **Data:** create, read, and update. There is no delete: run logs are an append-only audit trail.

---

## Conventions

- **Success responses** use `{"data": ...}`. Lists that page add `"pagination": {"page", "limit", "total"}`.
- **Errors** use `{"error": {"code": "...", "message": "..."}}`.
- **Field names** are camelCase (`runId`, `startTimeUtc`, `durationMs`).
- **Timestamps** are ISO 8601 UTC, for example `2026-11-01T08:00:00.000Z`.
- **IDs:** runs use GUIDs; scripts use integers.
- **Sorting:** scripts by ID, runs newest first, and logs, exceptions, and metrics oldest first.

---

## Authentication

1. **Log in** with `POST /api/v1/auth/login` to get a token (valid for 1 hour).
2. **Send it** on every other request as `Authorization: Bearer <token>`.

| Scope | Can use |
|---|---|
| `read` | All 9 read endpoints |
| `read write` | Reads, plus start a run, finish a run, and add a log entry |

Accounts are created by an administrator (no registration endpoint). The examples use two test accounts, saving their tokens in shell variables:

```bash
READ=$(curl -s -X POST http://localhost:8180/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"reader","password":"ReaderPass123!"}' | jq -r .data.token)
WRITE=$(curl -s -X POST http://localhost:8180/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"writer","password":"WriterPass123!"}' | jq -r .data.token)
```

---

## Errors

| Status | `error.code` | When |
|---:|---|---|
| 400 | `BAD_REQUEST` | Invalid JSON, a missing required field, or an invalid value, ID, or query parameter |
| 401 | `UNAUTHORIZED` | Missing, malformed, badly signed, or expired token; failed login |
| 403 | `FORBIDDEN` | Valid token without the needed scope (a read token on a write endpoint) |
| 404 | `NOT_FOUND` | The script or run does not exist |
| 405 | `METHOD_NOT_ALLOWED` | The method is not supported on that path |
| 500 | `INTERNAL_ERROR` | Unexpected server error (details are logged, not returned) |

The server checks in this order: token (401), then scope (403), then input (400), then existence (404). A request with a bad token is rejected before anything else happens.

---

<!-- _class: reference -->

## The 13 endpoints

| # | Method | Path | Auth |
|---:|---|---|---|
| E00 | POST | `/api/v1/auth/login` | Public |
| E01 | GET | `/api/v1/scripts` | read |
| E02 | GET | `/api/v1/scripts/{scriptId}` | read |
| E03 | GET | `/api/v1/runs` | read |
| E04 | GET | `/api/v1/runs/{runId}` | read |
| E05 | POST | `/api/v1/runs` | write |
| E06 | PATCH | `/api/v1/runs/{runId}` | write |
| E07 | GET | `/api/v1/runs/{runId}/logs` | read |
| E08 | POST | `/api/v1/runs/{runId}/logs` | write |
| E09 | GET | `/api/v1/runs/{runId}/exceptions` | read |
| E10 | GET | `/api/v1/runs/{runId}/metrics` | read |
| E11 | GET | `/api/v1/reports/execution-summary` | read |
| E12 | GET | `/api/v1/reports/failing-scripts` | read |

---

## The example data

The examples use the seed data from `code/tests/seed.sql` (the same data the acceptance tests use):

| Name | Run ID | What it is |
|---|---|---|
| R1 | `11111111-1111-4111-8111-111111111111` | Successful backup run: 3 log entries, 2 metrics |
| R2 | `22222222-2222-4222-8222-222222222222` | Failed backup run: 2 log entries, 1 exception |
| R3 | `33333333-3333-4333-8333-333333333333` | Cleanup run, still running |

Scripts: 1 = Test-BackupServers (team Infrastructure), 2 = Test-CleanupTemp (team Desktop).

The responses were captured right after loading this data, running the read examples first and the write examples (E05, E06, E08) last, as the acceptance tests do. Run the writes first and later counts, such as the E11 totals, will differ.

---

<!-- _class: reference -->

## E00 `POST /api/v1/auth/login`

Exchanges a username and password for a bearer token.

**Authentication:** Public (no token)

**Inputs:**

| Where | Name | Rules |
|---|---|---|
| Body | `username` | Required, up to 100 characters |
| Body | `password` | Required; checked against the stored bcrypt hash |

**Output:** `token` (JWT), `tokenType` ("Bearer"), `expiresIn` (seconds, 3600), `scope` ("read" or "read write").

**Status codes:** 200 · 400 (missing field or bad JSON) · 401 (wrong username or password)

---

<!-- _class: example -->

## E00 success example

**Success:** the reader account logs in (the token is shortened here).

```bash
curl -s -X POST \
  -H 'Content-Type: application/json' \
  -d '{"username": "reader", "password": "ReaderPass123!"}' \
  http://localhost:8180/api/v1/auth/login
```

**Response: 200 OK**

```json
{
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIsInR5…f6MtStuk",
    "tokenType": "Bearer",
    "expiresIn": 3600,
    "scope": "read"
  }
}
```

---

<!-- _class: example -->

## E00 failure example

**Failure:** a wrong password is rejected (an unknown username gets the same 401).

```bash
curl -s -X POST \
  -H 'Content-Type: application/json' \
  -d '{"username": "reader", "password": "WrongPass!"}' \
  http://localhost:8180/api/v1/auth/login
```

**Response: 401 Unauthorized**

```json
{
  "error": {
    "code": "UNAUTHORIZED",
    "message": "Invalid username or password."
  }
}
```

---

<!-- _class: reference -->

## E01 `GET /api/v1/scripts`

Lists every registered script, sorted by `scriptId`.

**Authentication:** Bearer token, `read` scope

**Inputs:**

None.

**Output:** Array of scripts: `scriptId`, `scriptName`, `description`, `team`, `owner`, `repository`, `createdDateUtc`, `modifiedDateUtc`.

**Status codes:** 200 · 401

---

<!-- _class: example -->

## E01 success example

**Success:** list the catalog with a reader token.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  http://localhost:8180/api/v1/scripts
```

**Response: 200 OK**

```json
{
  "data": [
    {
      "scriptId": 1,
      "scriptName": "Test-BackupServers",
      "description": "Nightly server backup",
      "team": "Infrastructure",
      "owner": "ops-team",
      "repository": "https://github.com/example/backup",
      "createdDateUtc": "2026-11-19T02:44:25.102Z",
      "modifiedDateUtc": "2026-11-19T02:44:25.102Z"
    },
    {
      "scriptId": 2,
      "scriptName": "Test-CleanupTemp",
      "description": "Temp folder cleanup",
      "team": "Desktop",
      "owner": "desk-team",
      "repository": null,
      "createdDateUtc": "2026-11-19T02:44:25.102Z",
      "modifiedDateUtc": "2026-11-19T02:44:25.102Z"
    }
  ]
}
```

---

<!-- _class: example -->

## E01 failure example

**Failure:** no Authorization header.

```bash
curl -s \
  http://localhost:8180/api/v1/scripts
```

**Response: 401 Unauthorized**

```json
{
  "error": {
    "code": "UNAUTHORIZED",
    "message": "A bearer token is required."
  }
}
```

---

<!-- _class: reference -->

## E02 `GET /api/v1/scripts/{scriptId}`

One script with its run counts and most recent run.

**Authentication:** Bearer token, `read` scope

**Inputs:**

| Where | Name | Rules |
|---|---|---|
| Path | `scriptId` | Positive integer |

**Output:** The script fields, plus `totalExecutions`, `successfulExecutions`, `failedExecutions`, `cancelledExecutions`, `runningExecutions`, and `latestRun` (or null).

**Status codes:** 200 · 400 (not an integer) · 401 · 404

---

<!-- _class: example -->

## E02 success example

**Success:** script 1, with its run counts.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  http://localhost:8180/api/v1/scripts/1
```

**Response: 200 OK**

```json
{
  "data": {
    "scriptId": 1,
    "scriptName": "Test-BackupServers",
    "description": "Nightly server backup",
    "team": "Infrastructure",
    "owner": "ops-team",
    "repository": "https://github.com/example/backup",
    "createdDateUtc": "2026-11-19T02:44:25.102Z",
    "modifiedDateUtc": "2026-11-19T02:44:25.102Z",
    "totalExecutions": 2,
    "successfulExecutions": 1,
    "failedExecutions": 1,
    "cancelledExecutions": 0,
    "runningExecutions": 0,
    "latestRun": {
      "runId": "22222222-2222-4222-8222-222222222222",
      "startTimeUtc": "2026-11-02T08:00:00.000Z",
      "endTimeUtc": "2026-11-02T08:01:00.000Z",
      "status": "Failed"
    }
  }
}
```

---

<!-- _class: example -->

## E02 failure example

**Failure:** a script ID that does not exist.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  http://localhost:8180/api/v1/scripts/999
```

**Response: 404 Not Found**

```json
{
  "error": {
    "code": "NOT_FOUND",
    "message": "Script 999 was not found."
  }
}
```

---

<!-- _class: reference -->

## E03 `GET /api/v1/runs`

Run history, newest first, with filters and paging.

**Authentication:** Bearer token, `read` scope

**Inputs:**

| Where | Name | Rules |
|---|---|---|
| Query | `scriptId` | Positive integer |
| Query | `status` | Running, Success, Failed, or Cancelled |
| Query | `from`, `to` | YYYY-MM-DD or ISO 8601 UTC; a date-only `to` includes that whole day |
| Query | `page`, `limit` | Page from 1 (default 1); limit 1–100 (default 50) |

**Output:** Array of runs (`runId`, `scriptId`, `scriptName`, `startTimeUtc`, `endTimeUtc`, `status`, `durationMs`, `computerName`, `userName`) and `pagination` (`page`, `limit`, `total`).

**Status codes:** 200 · 400 (invalid filter, or `from` after `to`) · 401

---

<!-- _class: example -->

## E03 success example

**Success:** failed runs of script 1.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  "http://localhost:8180/api/v1/runs?scriptId=1&status=Failed"
```

**Response: 200 OK**

```json
{
  "data": [
    {
      "runId": "22222222-2222-4222-8222-222222222222",
      "scriptId": 1,
      "scriptName": "Test-BackupServers",
      "startTimeUtc": "2026-11-02T08:00:00.000Z",
      "endTimeUtc": "2026-11-02T08:01:00.000Z",
      "status": "Failed",
      "durationMs": 60000,
      "computerName": "SRV01",
      "userName": "svc-ops"
    }
  ],
  "pagination": {
    "page": 1,
    "limit": 50,
    "total": 1
  }
}
```

---

<!-- _class: example -->

## E03 failure example

**Failure:** a status value that is not allowed.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  "http://localhost:8180/api/v1/runs?status=Bogus"
```

**Response: 400 Bad Request**

```json
{
  "error": {
    "code": "BAD_REQUEST",
    "message": "status must be one of: Running, Success, Failed, Cancelled."
  }
}
```

---

<!-- _class: reference -->

## E04 `GET /api/v1/runs/{runId}`

One run with all its details.

**Authentication:** Bearer token, `read` scope

**Inputs:**

| Where | Name | Rules |
|---|---|---|
| Path | `runId` | GUID |

**Output:** The run summary fields, plus `scriptVersion`, `description`, `powerShellVersion`, `psEdition`, `processId`, `correlationId`, `parentRunId`, `parameters`, `gitCommit`, `scriptPath`, `message`.

**Status codes:** 200 · 400 (not a GUID) · 401 · 404

---

<!-- _class: example -->

## E04 success example

**Success:** the successful run R1.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  http://localhost:8180/api/v1/runs/11111111-1111-4111-8111-111111111111
```

**Response: 200 OK**

```json
{
  "data": {
    "runId": "11111111-1111-4111-8111-111111111111",
    "scriptId": 1,
    "scriptName": "Test-BackupServers",
    "startTimeUtc": "2026-11-01T08:00:00.000Z",
    "endTimeUtc": "2026-11-01T08:05:00.000Z",
    "status": "Success",
    "durationMs": 300000,
    "computerName": "SRV01",
    "userName": "svc-ops",
    "scriptVersion": "1.0.0",
    "description": null,
    "powerShellVersion": null,
    "psEdition": null,
    "processId": null,
    "correlationId": "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
    "parentRunId": null,
    "parameters": null,
    "gitCommit": null,
    "scriptPath": null,
    "message": "Backup complete"
  }
}
```

---

<!-- _class: example -->

## E04 failure example

**Failure:** a run ID that is not a GUID.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  http://localhost:8180/api/v1/runs/not-a-guid
```

**Response: 400 Bad Request**

```json
{
  "error": {
    "code": "BAD_REQUEST",
    "message": "runId must be a GUID."
  }
}
```

---

<!-- _class: reference -->

## E05 `POST /api/v1/runs`

Records the start of a run. A new `scriptName` is added to the catalog automatically.

**Authentication:** Bearer token, `write` scope

**Inputs:**

| Where | Name | Rules |
|---|---|---|
| Body | `scriptName` | Required, up to 200 characters |
| Body | `startTimeUtc` | Required, ISO 8601 |
| Body | `correlationId`, `parentRunId` | Optional GUIDs; a parent run must exist |
| Body | others | `scriptVersion`, `description`, `team`, `owner`, `repository`, `computerName`, `userName`, `powerShellVersion`, `psEdition`, `processId`, `parameters` (any JSON), `gitCommit`, `scriptPath` |

**Output:** 201 with `runId` (generated by the server), `scriptId`, `scriptName`, `startTimeUtc`, `status` ("Running"), `correlationId`; `Location` header.

**Status codes:** 201 · 400 · 401 · 403 (read-only token)

---

<!-- _class: example -->

## E05 success example

**Success:** start a run with a writer token.

```bash
curl -s -X POST \
  -H "Authorization: Bearer $WRITE" \
  -H 'Content-Type: application/json' \
  -d '{"scriptName": "Test-BackupServers", "scriptVersion": "1.1.0",
      "computerName": "TEST01", "userName": "tester",
      "startTimeUtc": "2026-11-07T12:00:00.000Z",
      "correlationId": "cccccccc-cccc-4ccc-8ccc-cccccccccccc"}' \
  http://localhost:8180/api/v1/runs
```

**Response: 201 Created**

```json
{
  "data": {
    "runId": "633e829a-1097-41c2-ba2b-0d38ee00df18",
    "scriptId": 1,
    "scriptName": "Test-BackupServers",
    "startTimeUtc": "2026-11-07T12:00:00.000Z",
    "status": "Running",
    "correlationId": "cccccccc-cccc-4ccc-8ccc-cccccccccccc"
  }
}
```

---

<!-- _class: example -->

## E05 failure example

**Failure:** a reader token cannot write.

```bash
curl -s -X POST \
  -H "Authorization: Bearer $READ" \
  -H 'Content-Type: application/json' \
  -d '{"scriptName": "Test-BackupServers",
      "startTimeUtc": "2026-11-07T12:00:00.000Z"}' \
  http://localhost:8180/api/v1/runs
```

**Response: 403 Forbidden**

```json
{
  "error": {
    "code": "FORBIDDEN",
    "message": "This endpoint requires the 'write' scope."
  }
}
```

---

<!-- _class: reference -->

## E06 `PATCH /api/v1/runs/{runId}`

Finishes or updates a run. The server computes `durationMs` from the start and end times.

**Authentication:** Bearer token, `write` scope

**Inputs:**

| Where | Name | Rules |
|---|---|---|
| Path | `runId` | GUID |
| Body | `status` | Required: Success, Failed, or Cancelled |
| Body | `endTimeUtc` | Optional ISO 8601 (default: now); not before the start |
| Body | `message` | Optional text |

**Output:** The updated run, with all the fields of `GET /runs/{runId}`.

**Status codes:** 200 · 400 · 401 · 403 · 404

---

<!-- _class: example -->

## E06 success example

**Success:** finish the running run R3; the server computes the duration.

```bash
curl -s -X PATCH \
  -H "Authorization: Bearer $WRITE" \
  -H 'Content-Type: application/json' \
  -d '{"status": "Success",
      "endTimeUtc": "2026-11-03T08:10:00.000Z",
      "message": "Cleanup finished"}' \
  http://localhost:8180/api/v1/runs/33333333-3333-4333-8333-333333333333
```

**Response: 200 OK**

```json
{
  "data": {
    "runId": "33333333-3333-4333-8333-333333333333",
    "scriptId": 2,
    "scriptName": "Test-CleanupTemp",
    "startTimeUtc": "2026-11-03T08:00:00.000Z",
    "endTimeUtc": "2026-11-03T08:10:00.000Z",
    "status": "Success",
    "durationMs": 600000,
    "computerName": "PC42",
    "userName": "svc-desk",
    "scriptVersion": "2.0.0",
    "description": null,
    "powerShellVersion": null,
    "psEdition": null,
    "processId": null,
    "correlationId": "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
    "parentRunId": null,
    "parameters": null,
    "gitCommit": null,
    "scriptPath": null,
    "message": "Cleanup finished"
  }
}
```

---

<!-- _class: example -->

## E06 failure example

**Failure:** "Done" is not a valid final status.

```bash
curl -s -X PATCH \
  -H "Authorization: Bearer $WRITE" \
  -H 'Content-Type: application/json' \
  -d '{"status": "Done", "endTimeUtc": "2026-11-03T08:10:00.000Z"}' \
  http://localhost:8180/api/v1/runs/33333333-3333-4333-8333-333333333333
```

**Response: 400 Bad Request**

```json
{
  "error": {
    "code": "BAD_REQUEST",
    "message": "status must be one of: Success, Failed, Cancelled."
  }
}
```

---

<!-- _class: reference -->

## E07 `GET /api/v1/runs/{runId}/logs`

Log entries for a run, oldest first.

**Authentication:** Bearer token, `read` scope

**Inputs:**

| Where | Name | Rules |
|---|---|---|
| Path | `runId` | GUID |
| Query | `level` | Trace, Debug, Information, Warning, Error, or Critical |
| Query | `eventName` | Exact event name |
| Query | `from`, `to` | Same rules as `GET /runs` |

**Output:** Array of entries: `logId`, `runId`, `timestampUtc`, `level`, `eventName`, `message`, `category`, `functionName`, `lineNumber`, `durationMs`, `jsonData`.

**Status codes:** 200 · 400 · 401 · 404 (run not found)

---

<!-- _class: example -->

## E07 success example

**Success:** only the Warning entries of run R1.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  "http://localhost:8180/api/v1/runs/11111111-1111-4111-8111-111111111111/logs?level=Warning"
```

**Response: 200 OK**

```json
{
  "data": [
    {
      "logId": 2,
      "runId": "11111111-1111-4111-8111-111111111111",
      "timestampUtc": "2026-11-01T08:03:00.000Z",
      "level": "Warning",
      "eventName": "SlowDisk",
      "message": "Disk latency high",
      "category": null,
      "functionName": null,
      "lineNumber": null,
      "durationMs": null,
      "jsonData": null
    }
  ]
}
```

---

<!-- _class: example -->

## E07 failure example

**Failure:** logs for a run that does not exist.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  http://localhost:8180/api/v1/runs/99999999-9999-4999-8999-999999999999/logs
```

**Response: 404 Not Found**

```json
{
  "error": {
    "code": "NOT_FOUND",
    "message": "Run 99999999-9999-4999-8999-999999999999 was not found."
  }
}
```

---

<!-- _class: reference -->

## E08 `POST /api/v1/runs/{runId}/logs`

Adds a log entry to a run.

**Authentication:** Bearer token, `write` scope

**Inputs:**

| Where | Name | Rules |
|---|---|---|
| Path | `runId` | GUID of an existing run |
| Body | `level` | Required: one of the six levels |
| Body | `timestampUtc` | Optional ISO 8601 (default: now) |
| Body | others | `eventName`, `message`, `category`, `functionName`, `lineNumber`, `durationMs`, `jsonData` (any JSON) |

**Output:** 201 with the created entry, including its new `logId`.

**Status codes:** 201 · 400 · 401 · 403 · 404

---

<!-- _class: example -->

## E08 success example

**Success:** add a log entry to run R1.

```bash
curl -s -X POST \
  -H "Authorization: Bearer $WRITE" \
  -H 'Content-Type: application/json' \
  -d '{"timestampUtc": "2026-11-01T08:06:00.000Z",
      "level": "Information", "eventName": "Verify",
      "message": "Verification passed",
      "jsonData": {"files": 1200}}' \
  http://localhost:8180/api/v1/runs/11111111-1111-4111-8111-111111111111/logs
```

**Response: 201 Created**

```json
{
  "data": {
    "logId": 6,
    "runId": "11111111-1111-4111-8111-111111111111",
    "timestampUtc": "2026-11-01T08:06:00.000Z",
    "level": "Information",
    "eventName": "Verify",
    "message": "Verification passed",
    "category": null,
    "functionName": null,
    "lineNumber": null,
    "durationMs": null,
    "jsonData": {
      "files": 1200
    }
  }
}
```

---

<!-- _class: example -->

## E08 failure example

**Failure:** "Loud" is not a log level.

```bash
curl -s -X POST \
  -H "Authorization: Bearer $WRITE" \
  -H 'Content-Type: application/json' \
  -d '{"timestampUtc": "2026-11-01T08:06:00.000Z", "level": "Loud",
      "eventName": "Verify"}' \
  http://localhost:8180/api/v1/runs/11111111-1111-4111-8111-111111111111/logs
```

**Response: 400 Bad Request**

```json
{
  "error": {
    "code": "BAD_REQUEST",
    "message": "level must be one of: Trace, Debug, Information, Warning, Error, Critical."
  }
}
```

---

<!-- _class: reference -->

## E09 `GET /api/v1/runs/{runId}/exceptions`

Exceptions captured during a run. A run with none returns an empty array.

**Authentication:** Bearer token, `read` scope

**Inputs:**

| Where | Name | Rules |
|---|---|---|
| Path | `runId` | GUID |

**Output:** Array: `exceptionId`, `runId`, `timestampUtc`, `exceptionType`, `message`, `stackTrace`, `scriptStackTrace`, `fullyQualifiedErrorId`, `category`, `invocationInfo`, `jsonData`.

**Status codes:** 200 · 400 · 401 · 404

---

<!-- _class: example -->

## E09 success example

**Success:** the failed run R2 has one exception.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  http://localhost:8180/api/v1/runs/22222222-2222-4222-8222-222222222222/exceptions
```

**Response: 200 OK**

```json
{
  "data": [
    {
      "exceptionId": 1,
      "runId": "22222222-2222-4222-8222-222222222222",
      "timestampUtc": "2026-11-02T08:00:59.000Z",
      "exceptionType": "System.IO.IOException",
      "message": "There is not enough space on the disk.",
      "stackTrace": null,
      "scriptStackTrace": null,
      "fullyQualifiedErrorId": null,
      "category": null,
      "invocationInfo": null,
      "jsonData": null
    }
  ]
}
```

---

<!-- _class: example -->

## E09 failure example

**Failure:** a token that is not a valid JWT.

```bash
curl -s \
  -H "Authorization: Bearer not.a.jwt" \
  http://localhost:8180/api/v1/runs/22222222-2222-4222-8222-222222222222/exceptions
```

**Response: 401 Unauthorized**

```json
{
  "error": {
    "code": "UNAUTHORIZED",
    "message": "Token is malformed."
  }
}
```

---

<!-- _class: reference -->

## E10 `GET /api/v1/runs/{runId}/metrics`

Performance metrics recorded during a run.

**Authentication:** Bearer token, `read` scope

**Inputs:**

| Where | Name | Rules |
|---|---|---|
| Path | `runId` | GUID |

**Output:** Array: `metricId`, `runId`, `timestampUtc`, `metricName`, `metricValue` (a JSON number), `unit`, `category`, `jsonData`.

**Status codes:** 200 · 400 · 401 · 404

---

<!-- _class: example -->

## E10 success example

**Success:** run R1 recorded two metrics.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  http://localhost:8180/api/v1/runs/11111111-1111-4111-8111-111111111111/metrics
```

**Response: 200 OK**

```json
{
  "data": [
    {
      "metricId": 1,
      "runId": "11111111-1111-4111-8111-111111111111",
      "timestampUtc": "2026-11-01T08:05:00.000Z",
      "metricName": "FilesCopied",
      "metricValue": 1200,
      "unit": "count",
      "category": null,
      "jsonData": null
    },
    {
      "metricId": 2,
      "runId": "11111111-1111-4111-8111-111111111111",
      "timestampUtc": "2026-11-01T08:05:00.000Z",
      "metricName": "BytesCopied",
      "metricValue": 5368709120,
      "unit": "bytes",
      "category": null,
      "jsonData": null
    }
  ]
}
```

---

<!-- _class: example -->

## E10 failure example

**Failure:** metrics for a run that does not exist.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  http://localhost:8180/api/v1/runs/99999999-9999-4999-8999-999999999999/metrics
```

**Response: 404 Not Found**

```json
{
  "error": {
    "code": "NOT_FOUND",
    "message": "Run 99999999-9999-4999-8999-999999999999 was not found."
  }
}
```

---

<!-- _class: reference -->

## E11 `GET /api/v1/reports/execution-summary`

Totals by status, success rate, and average duration.

**Authentication:** Bearer token, `read` scope

**Inputs:**

| Where | Name | Rules |
|---|---|---|
| Query | `from`, `to` | Filter on run start time |
| Query | `team` | Team of the script |
| Query | `scriptId` | Positive integer |

**Output:** Counts by status, `averageDurationMs` (finished runs only), and `successRate` = successful ÷ (successful + failed + cancelled); null when nothing has finished.

**Status codes:** 200 · 400 · 401

---

<!-- _class: example -->

## E11 success example

**Success:** all runs that started in November 2026.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  "http://localhost:8180/api/v1/reports/execution-summary?from=2026-11-01&to=2026-11-30"
```

**Response: 200 OK**

```json
{
  "data": {
    "totalExecutions": 3,
    "successfulExecutions": 1,
    "failedExecutions": 1,
    "cancelledExecutions": 0,
    "runningExecutions": 1,
    "averageDurationMs": 180000,
    "successRate": 0.5
  }
}
```

---

<!-- _class: example -->

## E11 failure example

**Failure:** a date range that ends before it starts.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  "http://localhost:8180/api/v1/reports/execution-summary?from=2026-11-30&to=2026-11-01"
```

**Response: 400 Bad Request**

```json
{
  "error": {
    "code": "BAD_REQUEST",
    "message": "from must not be after to."
  }
}
```

---

<!-- _class: reference -->

## E12 `GET /api/v1/reports/failing-scripts`

Scripts ranked by number of failed runs; scripts with no failures are left out.

**Authentication:** Bearer token, `read` scope

**Inputs:**

| Where | Name | Rules |
|---|---|---|
| Query | `from`, `to` | Filter on run start time |
| Query | `team` | Team of the script |
| Query | `limit` | 1–100 (default 10) |

**Output:** Array: `scriptId`, `scriptName`, `team`, `failureCount`, `totalExecutions`, `failureRate`, `lastFailureUtc`.

**Status codes:** 200 · 400 · 401

---

<!-- _class: example -->

## E12 success example

**Success:** scripts with at least one failure.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  "http://localhost:8180/api/v1/reports/failing-scripts?limit=5"
```

**Response: 200 OK**

```json
{
  "data": [
    {
      "scriptId": 1,
      "scriptName": "Test-BackupServers",
      "team": "Infrastructure",
      "failureCount": 1,
      "totalExecutions": 2,
      "failureRate": 0.5,
      "lastFailureUtc": "2026-11-02T08:01:00.000Z"
    }
  ]
}
```

---

<!-- _class: example -->

## E12 failure example

**Failure:** limit must be at least 1.

```bash
curl -s \
  -H "Authorization: Bearer $READ" \
  "http://localhost:8180/api/v1/reports/failing-scripts?limit=0"
```

**Response: 400 Bad Request**

```json
{
  "error": {
    "code": "BAD_REQUEST",
    "message": "limit must be an integer from 1 to 100."
  }
}
```

---

## Try it yourself

From a clone of the repository (see the README for package installation):

```bash
sudo ./code/database/setup-database.sh --test-logins   # schema, API account, test logins
sudo ./code/nginx/setup-nginx.sh                       # NGINX + PHP-FPM on port 8180
./code/tests/acceptance.sh                             # 73 tests: 73 passed, 0 failed
```

- **Web viewer:** `http://localhost:8180/`. Sign in as `reader` or `writer`.
- **Full contract and tests:** `plan/acceptance.md`.
- **Saved results:** `code/results/`.

The acceptance tests reset the database to the seed data before running, so use a development database.
