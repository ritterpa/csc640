# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

CSC 640 course project: a REST API (JSON, JWT bearer tokens) over an existing MySQL database of PowerShell script logs (`InfrastructureLogging`: script catalog, runs, log entries, exceptions, metrics). The same 13 endpoints under `/api/v1` are built twice, and both builds must pass the **same 73 acceptance tests unchanged**:

- **Stage 1** (`code/stage1`, complete, tag `stage1`): raw PHP + PDO behind NGINX/php-fpm, port 8180. Also serves the HTML/JS front end (`public/index.html`, `public/assets/`).
- **Stage 2** (`code/stage2`, in progress): Laravel 13 + Eloquent, run with `php artisan serve` on port 8181. The milestones and dates are in `plan/HW4_plan_stage2.md` (next up: Docker, then a Hugo docs site published by `.github/workflows/hugo.yml`).

The full API contract (fields, status codes, error format) is `plan/acceptance.md`; the README has the endpoint table.

## Environment

- **Development:** VS Code on Windows, in this checkout (`C:\Users\paul\Documents\GitHub\csc640`). PHP is not on the Windows PATH.
- **Execution:** the WSL instance named **`stage2`**, which is *not* the default WSL distro (`Ubuntu` is). Run commands there with `wsl -d stage2 -e sh -c 'cd ~/projects/csc640 && ...'`; a plain `wsl` lands in the wrong distro.
- The WSL copy (`/home/paul/projects/csc640`, which Windows sees as `\\wsl.localhost\stage2\home\paul\projects\csc640`) is a **separate git clone** with the same GitHub origin.
- **Workflow between the two clones:**
  1. Write or generate code in the Windows checkout.
  2. Copy the changed files into the WSL copy and test there (`run.sh`, then `acceptance.sh`).
  3. Once the acceptance tests pass, commit from the **Windows** side. Copy any results file produced in WSL (`code/results/*.txt`) back to Windows first so it goes in the same commit.
  4. Push. Then, in the WSL copy, discard the copied test files and pull. Otherwise `git pull` refuses, because the copies would be overwritten (even when they're identical to the commit):
     ```bash
     cd ~/projects/csc640
     git restore .      # undo changes to tracked files
     git clean -n       # dry run: list the untracked files that would be deleted; check the list
     git clean -f       # delete them
     git pull
     ```
     Run `git clean` without `-x`: then it leaves gitignored files alone, including the generated Laravel app `code/stage2/api/` and the `.env` files that hold the database password and JWT secret. Only clean up after the results file has been copied back to Windows (step 3).
- **Never commit (or push) from the WSL side.** It only receives copied files for testing, and `git pull`.
- **Confirm with the user before deleting anything from the Windows clone**: files, directories, branches, stashes, or uncommitted changes (including `git restore`, `git clean`, `git reset --hard` and `git checkout -- <file>`). The WSL cleanup above is the only routine deletion, and it applies to the WSL copy only.
- Setup and test scripts need `sudo` for MySQL root (socket auth), so the user usually has to run them.

## Commands

All run from the repo root in the `stage2` WSL instance.

```bash
# One-time: MySQL, schema, and the test logins reader/ReaderPass123! and writer/WriterPass123!
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
