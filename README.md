# InfraLogging REST API

CSC 640 High Quality Software Product, Option 2 (REST API)

IT Operations runs dozens of administrative PowerShell scripts that write structured logging events (runs, log entries, exceptions, and performance metrics) to a MySQL database. Today the only way to read those logs is to query SQL directly.

This project builds a REST API that serves the log data as JSON, protected by bearer tokens, plus a simple HTML/JavaScript page that calls the API and displays the results.

The project is built in two stages that pass the same acceptance tests:

| Stage | Technology | Status |
|---|---|---|
| Stage 1 | Raw PHP + MySQL, served through NGINX | In progress, due with HW4 Part 2 |
| Stage 2 | Laravel, automation scripts, Docker, Hugo, GitHub Actions | Planned, due with HW4 Part 3 |

## Project Documents

- [Acceptance tests](plan/acceptance.md): 73 executable tests for all 13 APIs, the seed data, and the test results.
- [Stage 1 plan](plan/HW4_plan_stage1.md): milestones with planned and actual dates.
- [Database schema](code/database/Deploy-InfraLogging.mysql.sql): tables, indexes, stored procedures, and the API login table.
- [Documentation site](https://ritterpa.github.io/csc640-docs/) (Hugo on GitHub Pages; [source repository](https://github.com/ritterpa/csc640-docs)).
- [Canvas progress page](https://nku.instructure.com/courses/92884/pages/individual-project-progress-page-of-paul-ritter-in-csc640) (requires NKU Canvas login).

## APIs

All endpoints except login require `Authorization: Bearer <token>`. Read endpoints need `read` scope; write endpoints need `read write` scope. The full request and response contract is in [acceptance.md](plan/acceptance.md).

| # | Method | Endpoint | Scope | Purpose |
|---:|---|---|---|---|
| 1 | GET | `/api/v1/scripts` | read | Script catalog |
| 2 | GET | `/api/v1/scripts/{scriptId}` | read | One script, with run counts |
| 3 | GET | `/api/v1/runs` | read | Run history (filters, pagination) |
| 4 | GET | `/api/v1/runs/{runId}` | read | One run |
| 5 | POST | `/api/v1/runs` | write | Start a run |
| 6 | PATCH | `/api/v1/runs/{runId}` | write | Finish or update a run |
| 7 | GET | `/api/v1/runs/{runId}/logs` | read | Log entries for a run |
| 8 | POST | `/api/v1/runs/{runId}/logs` | write | Add a log entry |
| 9 | GET | `/api/v1/runs/{runId}/exceptions` | read | Exceptions for a run |
| 10 | GET | `/api/v1/runs/{runId}/metrics` | read | Metrics for a run |
| 11 | GET | `/api/v1/reports/execution-summary` | read | Totals, success rate, average duration |
| 12 | GET | `/api/v1/reports/failing-scripts` | read | Scripts ranked by failures |
| 13 | POST | `/api/v1/auth/login` | public | Exchange username and password for a JWT |

## Repository Layout

```text
code/
  database/       Deploy-InfraLogging.mysql.sql (schema)
  stage1/         Raw PHP API (Stage 1)
  stage2/         Laravel API (Stage 2)
  tests/          acceptance.sh, seed.sql
  results/        Saved test-run output
plan/             acceptance.md, stage plans, stage evaluations
presentation/     Marp presentation
```

## Setup (Stage 1)

> These instructions are being written alongside Stage 1 and will be completed by Milestone 3.

**Prerequisites:**
- PHP 8.x with the PDO MySQL extension.
- NGINX.
- MySQL 8.0.19 or later.
- `curl`, `jq`, and `openssl`, for the acceptance tests.

**Steps:**

1. Deploy the database schema:

   ```bash
   mysql -u root -p < code/database/Deploy-InfraLogging.mysql.sql
   ```

2. Create a MySQL account for the API. It needs EXECUTE on the logging procedures and SELECT on the tables, including `ApiUser` for login.

3. Copy `.env.example` to `.env` and set the database connection values and `JWT_SECRET`.

4. Point NGINX at `code/stage1/public` and route `/api/` requests to PHP-FPM. The exact configuration file is still to come.

5. Create API user accounts out of band. There is no registration endpoint. Store passwords as hashes from PHP's `password_hash()`.

## Running the Acceptance Tests

The same tests run against both stages; only `BASE` changes. The runner resets the database to known seed data, logs in as the seeded test users, and exits nonzero if any test fails.

```bash
export JWT_SECRET='<same value as the server .env>'
BASE=http://localhost:8080 ./code/tests/acceptance.sh | tee code/results/stage1-$(date +%F).txt
```

The results of each run are recorded in the Results section of [acceptance.md](plan/acceptance.md).

## References

- ASE 230 [module 1](https://github.com/nkuase/ase230/tree/main/module1) (PHP and MySQL): Stage 1.
- ASE 230 [module 2](https://github.com/nkuase/ase230/tree/main/module2) (Laravel): Stage 2.
- ASE 230 [module 3](https://github.com/nkuase/ase230/tree/main/module3) (Docker, Hugo, GitHub): automation and documentation.

Specific lecture file and page references will be added here as each part of the code is built.

## AI Assistance

I used Claude (Anthropic) for the following:

- Converting the original SQL Server deployment script to MySQL.
- Adding the `ApiUser` login table to the schema.
- Drafting the acceptance tests, the seed data, and the `acceptance.sh` test runner.
- Reviewing homework submissions against the course requirements.

I reviewed and edited all AI-generated material and am responsible for it, and I can explain how the code and tests work. Further AI assistance will be listed here as the project continues.
