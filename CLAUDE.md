# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

CSC 640 course project: a REST API (JSON, JWT bearer tokens) over an existing MySQL database of PowerShell script logs (`InfrastructureLogging`: script catalog, runs, log entries, exceptions, metrics). The same 13 endpoints under `/api/v1` are built twice, and both builds must pass the **same 74 acceptance tests unchanged**:

- **Stage 1** (`code/stage1`, complete, tag `stage1`): raw PHP + PDO behind NGINX/php-fpm, port 8180. Also serves the HTML/JS front end (`public/index.html`, `public/assets/`).
- **Stage 2** (`code/stage2`, in progress): Laravel 13 + Eloquent, run with `php artisan serve` on port 8181. The milestones and dates are in `plan/HW4_plan_stage2.md` (next up: Docker, then a Hugo docs site published by `.github/workflows/hugo.yml`).

The full API contract (fields, status codes, error format) is `plan/acceptance.md`; the README has the endpoint table.

## Environment

- **Development:** VS Code on Windows, in this checkout (`C:\Users\paul\Documents\GitHub\csc640`). PHP is not on the Windows PATH.
- **Execution:** the WSL instance named **`stage2`**, which is *not* the default WSL distro (`Ubuntu` is); a plain `wsl` lands in the wrong one. `stage2` runs Docker (no `sudo` needed) and has the test tools (bash, curl, jq, openssl, git, MySQL 8.4 client).
- **Development loop (Docker, both stages):**
  1. Edit in the Windows checkout. Docker Compose runs in `stage2` directly against this checkout through `/mnt/c`, so nothing is copied anywhere.
  2. Test with this exact command; it is allowlisted in `.claude/settings.json`, so it runs without a prompt:
     ```bash
     MSYS_NO_PATHCONV=1 wsl -d stage2 --cd /mnt/c/Users/paul/Documents/GitHub/csc640 -e ./code/docker/test.sh
     ```
     `MSYS_NO_PATHCONV=1` stops Git Bash from rewriting the `/mnt/c/...` path (PowerShell doesn't need it). It tests both stages; add `--stage 1` or `--stage 2` for one. Add `--save` to also write `code/results/stage<N>-docker-<date>.txt` per stage (or `…-<date>-<HHMMSS>.txt` if a run from that day is already saved; it never overwrites). Expect 74 passed, 0 failed for each stage (exit 0).
  3. Show the user the diff and the test result, and **ask before committing**. `git commit` and `git push` are set to "ask" in `.claude/settings.json`; commit from the Windows checkout only.
- **The old WSL clone** (`~/projects/csc640` in `stage2`, a separate git clone of the same GitHub repo) is only for checking the non-Docker `run.sh` path. Never commit or push from it; update it with `git pull`. It needs `sudo`, so the user runs it. If files were copied into it for testing, `git pull` refuses until they are discarded: `git restore .`, then `git clean -n` (check the list), `git clean -f`, `git pull`. Never use `git clean -x`, which would delete the generated `code/stage2/api/` and its `.env`.
- The Docker stack and the non-Docker setup use the same ports (3306 for MySQL, 8180 for Stage 1, 8181 for Stage 2), so only one can run at a time. Stop the stack with `docker compose down`. The local services (`mysql`, `nginx`, `php8.5-fpm`) are disabled at boot in `stage2`, so Docker gets the ports. For the non-Docker setup, the user runs `docker compose down`, then `sudo systemctl start mysql php8.5-fpm nginx` (and stops them again before using Docker).
- **Confirm with the user before deleting anything from the Windows clone**: files, directories, branches, stashes, or uncommitted changes (including `git restore`, `git clean`, `git reset --hard` and `git checkout -- <file>`).

## Commands

All run from the repo root in the `stage2` WSL instance.

```bash
# Both stages in Docker (the normal way; no sudo). Stage 1 on :8180, Stage 2 on :8181
./code/docker/setup.sh                      # creates/completes .env (secrets), builds, starts, waits until healthy
./code/docker/test.sh [--stage 1|2] [--save]  # setup + restart Stage 2 with current src/ + 74 acceptance tests per stage
docker compose logs app                     # Stage 2 output; Laravel's log: docker compose exec app tail storage/logs/laravel.log
docker compose logs stage1                  # Stage 1 output (PHP built-in server)
docker compose down                         # stop (the database is in memory, so it starts empty next time)

# Without Docker. One-time: MySQL, schema, and the test logins reader/ReaderPass123! and writer/WriterPass123!
sudo service mysql start
sudo ./code/database/setup-database.sh --test-logins

# Stage 1
sudo ./code/nginx/setup-nginx.sh            # writes code/stage1/.env, serves on :8180
./code/tests/acceptance.sh                  # Stage 1 is the default

# Stage 2
./code/stage2/run.sh                        # build/configure, then serve on :8181 (foreground)
./code/stage2/run.sh --setup-only           # build/configure only
./code/tests/acceptance.sh --stage 2        # in a second terminal

# Save a results file (convention, see below)
./code/tests/acceptance.sh --stage 2 | tee code/results/stage2-$(date +%F).txt

# Syntax check (no linter or unit tests are configured)
php -l path/to/file.php
```

`acceptance.sh` has no option to run a single test. It runs everything in order (curl + jq), resets the database from `code/tests/seed.sql` first, and exits 0 (all pass), 1 (some fail) or 2 (setup problem). Test IDs follow `E<endpoint>-<case>`: `TM`/`TI` = missing or invalid token, `TS` = scope, `S` = success, `F` = failure, `V` = validation. `BASE`, `JWT_SECRET` and `MYSQL` can be overridden by environment variable. It reads `JWT_SECRET` from the stage's `.env` to sign an expired-token test case.

## Stage 2 architecture (Laravel)

Only the project's own files are committed, in `code/stage2/src/`. `run.sh` creates a fresh Laravel project in `code/stage2/api/` (gitignored, including `vendor/` and `.env`), installs `firebase/php-jwt`, creates a least-privilege MySQL user (SELECT/INSERT/UPDATE, no DELETE), writes `.env`, and then **copies `src/` over `api/`**. So:

- Edit files in `src/`, never in `api/`. After editing, rerun `run.sh` (or `--setup-only`) to copy the changes in.
- `src/bootstrap/app.php` and `src/app/Providers/AppServiceProvider.php` **replace** the Laravel skeleton's own copies; keep the skeleton parts they contain.
- There are no migrations. The schema comes from `code/database/Deploy-InfraLogging.mysql.sql`. Models map onto its PascalCase tables and columns (`Run` → `ScriptRun` with a GUID string key, `Script` → `ScriptCatalog`). Timestamp columns are `DATETIME(3)`, so models use `$dateFormat = 'Y-m-d H:i:s.v'` and `$timestamps = false`.

The Docker stack (`docker-compose.yml`, `code/docker/`) runs both stages against one database:
- **`db`** runs `mysql:8.4` with its data in memory (tmpfs). On first start it loads the schema file, then `db-init/02-app-user.sh` creates each stage's least-privilege account: `infralog_laravel` (SELECT, INSERT, UPDATE) and `infralog_api` (SELECT, EXECUTE; Stage 1 writes through the stored procedures).
- **`stage1`** is plain PHP with `pdo_mysql`, serving the read-only mounted `code/stage1` with PHP's built-in server (`public/index.php` routes like NGINX's `try_files`). It reads files per request, so Stage 1 code changes need no restart. Its settings are environment variables, which Stage 1's `Config` prefers over a `.env` file.
- **`app`** is an image with the Laravel skeleton and `vendor/` built in. `code/stage2/src` is mounted read-only, and `app/entrypoint.sh` copies it over the skeleton on every start. That's why `test.sh` recreates the container: a code change needs a restart, not a rebuild. Changing the Dockerfile or entrypoint does need a rebuild, which `setup.sh` handles with `--build`.
- **Settings:** the entrypoint writes them into the app's `.env` from the Compose environment variables. They can't stay as environment variables only, because `php artisan serve` strips most of them from the server it starts, and Laravel then silently falls back to SQLite.
- **Secrets** are in the root `.env`, which is git-ignored; `setup.sh` creates it and adds any missing setting. Both stages share its `JWT_SECRET`, and the tests read the MySQL root password and `JWT_SECRET` from it.
- `.gitattributes` keeps `*.sh`, `*.sql`, `*.yml` and the Dockerfile LF-only (this checkout uses `core.autocrlf=true`). A CRLF script fails inside Linux.

How a request flows:
- **Auth:** the routes in `routes/api.php` use `auth:jwt`, which is a custom guard registered with `Auth::viaRequest` in `AppServiceProvider` and backed by `Support/JwtTokens`. It is stateless HS256 with the same claims as Stage 1, deliberately not Sanctum, so the token tests pass unchanged. After it, `scope:read`/`scope:write` (`Http/Middleware/RequireScope`) checks the token's scope.
- **The order of checks is part of the contract:** 405 for a wrong method → 401 token → 403 scope → 400 input → 404 existence. Controllers validate input **before** looking up the run.
- **Errors:** every error under `/api/*` is rendered by `Support/ApiError::fromThrowable` (hooked in `bootstrap/app.php`) as `{"error":{"code","message"}}`. Expected errors are thrown as `Exceptions/ApiException::badRequest/unauthorized/forbidden/notFound`. Validation failures must be **400, not Laravel's 422**: use `Support/ApiInput::validate`, not `$request->validate()`. `ApiInput::jsonObject` parses the body itself, because Laravel silently treats invalid JSON as empty.
- **Output:** JSON keys are camelCase and are built in `Http/Resources/*`. Timestamps go through `Support/Format::timestamp` (ISO 8601 UTC with milliseconds, `Z`), and date inputs through `Support/DateInput` (strict ISO parsing; a date-only `to` includes the whole day).
- **Business logic:** `app/Actions/StartRun.php` holds the "start a run" rule, ported from Stage 1's stored procedure `usp_InfraLogging_StartScriptRun`. It runs in a transaction and registers or updates the script with `lockForUpdate`. Stage 2 uses Eloquent and the query builder only, with **no raw SQL**, whereas Stage 1 calls the stored procedures.

Stage 1 (`code/stage1/src`, namespace `InfraLogging`) has the same layering, written by hand: `public/index.php` (route table) → `Router` → `Controllers/*` → PDO. It reads `code/stage1/.env` through `Config`.

## Project conventions

- Don't add a DELETE endpoint or grant DELETE: run logs are an append-only audit trail by design.
- When a change affects the API, keep both stages identical in behavior; the acceptance tests are the shared contract.
- Test results: save each run's output to `code/results/` (for example `stage2-milestone2-2026-11-27.txt`) and record it in the Results table in `plan/acceptance.md`. Milestone dates (planned and actual) are kept in `plan/HW4_plan_stage*.md`.
- The README's "AI Assistance" section lists what AI generated. It says further AI help will be added there, so update it when AI writes new material.
- `plan/acceptance.md` contains an appendix copy of `acceptance.sh`, which can fall out of date with the real script.
- Code comments in this repo explain *why* and cite the ASE 230 course material or the Stage 1 equivalent. Match that style.
