---
marp: true
size: 4:3
paginate: true
style: |
  section { font-size: 22px; }
---

# HW4 - Stage 2 Plan

**Submission Deadline:** Dec 10, 2026

---

## Your HW4 Part 3 Submission Date

1. **When (Plan):** Dec 10, 2026
2. **When (Actual):** TBD
3. **Comment:** Stage 2 rebuilds the same 13 endpoints in Laravel and must pass the same 73 acceptance tests as Stage 1, both through `run.sh` and in Docker through `setup.sh`.

---

## Stage 2 Milestones

### Milestone 1 Submission

1. **What:** Laravel foundation in `code/stage2`, started by `run.sh`:
   - `run.sh` is adapted from ASE 230 `run1-5.sh`, with an explanation and references.
   - Eloquent models and relationships for the five tables.
   - JWT bearer login, equivalent to Sanctum, using the Stage 1 token contract.
   - E00–E04 pass with `--stage 2`, and Stage 1 still passes 73 of 73 after the PHP 8.4 upgrade.
2. **When (Plan):** Nov 25, 2026
3. **When (Actual):** TBD
4. **Comment:** Keeping the Stage 1 JWT contract lets all 73 tests apply unchanged; Sanctum tokens would fail two of them.

---

### Milestone 2 Submission

1. **What:** All 13 endpoints implemented with Laravel routes, controllers, and Eloquent, with no raw SQL. All 73 tests pass against the app started by `run.sh`. Stage 1 and Stage 2 results are saved side by side in `code/results/` and recorded in `plan/acceptance.md`.
2. **When (Plan):** Dec 2, 2026
3. **When (Actual):** TBD
4. **Comment:** Allows for the Thanksgiving break (Nov 26–29).

---

### Milestone 3 Submission

1. **What:** Docker environment:
   - A Dockerfile and `docker-compose.yml` run the Laravel app with MySQL.
   - `setup.sh` builds and starts everything.
   - All 73 tests pass against the container.
2. **When (Plan):** Dec 5, 2026
3. **When (Actual):** TBD
4. **Comment:** Uses ASE 230 module 3. The test runner gets a target for the container.

---

### Milestone 4 Submission

1. **What:** Documentation and publication:
   - A Hugo tutorial in `presentation/`, adapted from the Stage 1 tutorial, with the API, setup, run, and test instructions, and examples.
   - GitHub Actions builds and publishes it to GitHub Pages on every push.
   - `comparison.md` in `presentation/`, linking to both stages' results.
   - README Stage 2 sections, `stage2_evaluation.md`, and a `stage2` tag.
2. **When (Plan):** Dec 8, 2026
3. **When (Actual):** TBD
4. **Comment:** `comparison.md` is reused in HW5 by linking to it, not copying it.
