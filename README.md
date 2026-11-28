# InfraLogging REST API

CSC 640 High Quality Software Product, Option 2 (REST API)

IT Operations runs dozens of administrative PowerShell scripts that write structured logging events (runs, log entries, exceptions, and performance metrics) to a MySQL database. Today the only way to read those logs is to query SQL directly.

This project builds a REST API that serves the log data as JSON, protected by bearer tokens, plus HTML/JavaScript pages that call the API to browse runs, logs, and reports.

The project is built in two stages that pass the same acceptance tests:

| Stage | Technology | Status |
|---|---|---|
| Stage 1 | Raw PHP + MySQL, served through NGINX | Complete: [stage1](https://github.com/ritterpa/csc640/tree/stage1) |
| Stage 2 | Laravel, automation scripts, Docker, Hugo, GitHub Actions | Planned, due with HW4 Part 3 |

**Submitted version (Stage 1):** tag [stage1](https://github.com/ritterpa/csc640/tree/stage1). To review exactly that version, run `git checkout stage1` after cloning.

## Project Documents

- [Acceptance tests](plan/acceptance.md): 74 executable tests for all 13 APIs, the seed data, and the test results.
- [Stage 1 plan](plan/HW4_plan_stage1.md): milestones with planned and actual dates.
- [Stage 1 tutorial](presentation/stage1-tutorial.md) ([PDF](presentation/stage1-tutorial.pdf)): Marp slides explaining every endpoint's method, path, inputs, outputs, and authentication, with real success and failure examples.
- [Saved test results](code/results/): output of each acceptance test run, including the milestone re-runs.
- [Database schema](code/database/Deploy-InfraLogging.mysql.sql): tables, indexes, stored procedures, and the API login table.
- [Documentation site](https://ritterpa.github.io/csc640-docs/) (moving to this repository in Stage 2; see [`.github/workflows/hugo.yml`](.github/workflows/hugo.yml)).
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

**Data operations:** Create (`POST /runs`, `POST /runs/{runId}/logs`), Read (the 9 `GET` endpoints), and Update (`PATCH /runs/{runId}`). There is no Delete endpoint by design: run logs are an append-only audit trail and deleting them is **not a valid business use case**, so no API call can remove them. 

## Repository Layout

```text
code/
  database/       Deploy-InfraLogging.mysql.sql (schema), setup-database.sh config script
  nginx/          setup-nginx.sh config script
  stage1/         Raw PHP API (Stage 1)
  stage2/         Laravel API (Stage 2)
  tests/          acceptance.sh, seed.sql
  results/        Saved test-run output
plan/             acceptance.md, stage plans, stage evaluations
presentation/     Stage 1 tutorial (Marp source and PDF)
```

## Setup (Stage 1)

**Prerequisites:**
- The setup instructions assume you have a fresh install of Ubuntu running in WSL. No other prerequisites are assumed.

**Steps:**

1. Install the basic packages needed for the project: Mysql, nginx, php-fpm, php-mysql, curl, jq, openssl, and git.

   ```bash
   sudo apt update
   sudo apt install mysql-server nginx php-fpm php-mysql curl jq openssl git -y
   sudo service mysql start
   ```

2. Make projects directory in user's home directory:

   ```bash
   cd ~
   mkdir projects
   cd projects
   ```

3. Clone the repo with git:
   ```bash
   git clone https://github.com/ritterpa/csc640
   cd csc640
   git checkout stage1
   ```

4. Setup the database:
   ```bash
   sudo ./code/database/setup-database.sh --test-logins
   ```

5. Setup nginx:
   ```bash
   sudo ./code/nginx/setup-nginx.sh
   ```

6. Run acceptance tests
   ```bash
   ./code/tests/acceptance.sh
   ```
    Verify that all 73 tests passed and that 0 failed.

 7. Open the site in a browser at http://localhost:8180/

## Web Pages

Open the server's address in a browser (for example `http://localhost:8180/`) and sign in with an API account. The pages call the same REST API as the tests:

- **Runs:** run history with filters by script, status, and date, plus paging.
- **Run details:** run information, then the run's logs (as a console transcript, filterable by level), exceptions, and metrics.
- **Scripts:** the script catalog, and each script's run counts and latest run.
- **Reports:** the execution summary and the scripts with the most failures, filterable by date and team.
- **Start a run, finish a run, and add a log entry:** shown only to accounts with write scope.

The pages are plain HTML, CSS, and JavaScript in `code/stage1/public` (`index.html` and `assets/`), with no build step. The sign-in token is kept in the browser tab's session storage and cleared when the tab closes.

## Running the Acceptance Tests

The same 74 tests run against both stages. The runner resets the database to known seed data, logs in as the seeded test users, and exits nonzero if any test fails.

```bash
./code/tests/acceptance.sh | tee code/results/stage1-$(date +%F).txt              # Stage 1 (default)
./code/tests/acceptance.sh --stage 2 | tee code/results/stage2-$(date +%F).txt    # Stage 2 (Laravel, Part 3)
```

Each stage's settings (API address, `.env` file, database command) are at the top of `code/tests/acceptance.sh`. The output starts by naming the stage and code version tested.

The results of each run are recorded in the Results section of [acceptance.md](plan/acceptance.md).

## References

- ASE 230 [module 1](https://github.com/nkuase/ase230/tree/main/module1) (PHP and MySQL): Stage 1.
- ASE 230 [module 2](https://github.com/nkuase/ase230/tree/main/module2) (Laravel): Stage 2.
- ASE 230 [module 3](https://github.com/nkuase/ase230/tree/main/module3) (Docker, Hugo, GitHub): automation and documentation.

Stage 1 is based on ASE 230 Module 1 (`module1/pdf/` in [nkuase/ase230](https://github.com/nkuase/ase230)):

| Part of Stage 1 | Slides (file, pages) |
|---|---|
| Database access with PDO and prepared statements (`App.php`, controllers) | [`2_Connect_PHP_with_MySQL/3. CRUD operation using PDO.pdf`](https://github.com/nkuase/ase230/blob/main/module1/pdf/2_Connect_PHP_with_MySQL/3.%20CRUD%20operation%20using%20PDO.pdf), pp. 5, 7–20; [`6_RestAPI/3. Connecting REST API Server to MySQL with PDO.pdf`](https://github.com/nkuase/ase230/blob/main/module1/pdf/6_RestAPI/3.%20Connecting%20REST%20API%20Server%20to%20MySQL%20with%20PDO.pdf), pp. 11, 15–23 |
| Reading JSON request bodies and setting status codes (`Request.php`, `Response.php`) | [`3_Request_and_Response_with_PHP/3. POST and PUT JSON Requests.pdf`](https://github.com/nkuase/ase230/blob/main/module1/pdf/3_Request_and_Response_with_PHP/3.%20POST%20and%20PUT%20JSON%20Requests.pdf), pp. 14–21 |
| Routing and REST structure (`Router.php`, `public/index.php`) | [`6_RestAPI/2. Building REST API Server with PHP and JSON.pdf`](https://github.com/nkuase/ase230/blob/main/module1/pdf/6_RestAPI/2.%20Building%20REST%20API%20Server%20with%20PHP%20and%20JSON.pdf), pp. 9–13, 18–46 |
| Bearer token authentication and password checking (`Auth.php`, `AuthController.php`) | [`7_Security/Basics/3. Bearer Token Authentication.pdf`](https://github.com/nkuase/ase230/blob/main/module1/pdf/7_Security/Basics/3.%20Bearer%20Token%20Authentication.pdf), pp. 4–19 |
| JWT signing and verification (`Jwt.php`) | [`7_Security/Advanced/3. JWT Token.pdf`](https://github.com/nkuase/ase230/blob/main/module1/pdf/7_Security/Advanced/3.%20JWT%20Token.pdf), pp. 13–14, 24–26 |
| Input validation (`Validator.php`) | [`7_Security/Advanced/2. Input Validation with Validator.pdf`](https://github.com/nkuase/ase230/blob/main/module1/pdf/7_Security/Advanced/2.%20Input%20Validation%20with%20Validator.pdf), pp. 25–26, 46–51 |
| NGINX with PHP-FPM (`setup-nginx.sh`) | [`5_Webserver_using_NGINX/2. Setting up NGINX for PHP.pdf`](https://github.com/nkuase/ase230/blob/main/module1/pdf/5_Webserver_using_NGINX/2.%20Setting%20up%20NGINX%20for%20PHP.pdf), pp. 12, 16, 24–26 |
| Testing the API with curl (`acceptance.sh`) | [`6_RestAPI/4. Testing REST API Server.pdf`](https://github.com/nkuase/ase230/blob/main/module1/pdf/6_RestAPI/4.%20Testing%20REST%20API%20Server.pdf), pp. 6–13 |

Stage 2 will use ASE 230 [module 2](https://github.com/nkuase/ase230/tree/main/module2) (Laravel) and [module 3](https://github.com/nkuase/ase230/tree/main/module3) (Docker, Hugo, GitHub).

## AI Assistance

I used Claude (Anthropic) for the following:

- Converting the original SQL Server deployment script to MySQL.
- Adding the `ApiUser` login table to the schema.
- Drafting the acceptance tests, the seed data, and the `acceptance.sh` test runner.
- Generating the Stage 1 PHP code in `code/stage1` (router, JWT authentication, validation, and controllers).
- Generating the web pages in `code/stage1/public` (`index.html`, `assets/app.js`, `assets/styles.css`).
- Writing the setup scripts `code/database/setup-database.sh` and `code/nginx/setup-nginx.sh`.
- Making the test runner work as one command (reading the JWT secret from `.env`, falling back to `sudo mysql`, and checking the setup before testing).
- Adding the `--stage` option to the test runner, so the testing config variables can be changed to work with both stages.
- Generating the Stage 1 tutorial in `presentation/`, with examples captured from the running API.
- Drafting `stage1_evaluation.md`.
- Reviewing homework submissions against the course requirements.

I reviewed and edited all AI-generated material and am responsible for it, and I can explain how the code and tests work. Further AI assistance will be listed here as the project continues.
