---
marp: true
html: true
size: 4:3
paginate: true
style: |
  section.small {
    font-size: 22px;
  }
  section.tiny {
    font-size: 19px;
  }
---


# Stage 1 Evaluation

**Total: 100 points.** Submit in HW4 Part 2.

- **Student:** Paul Ritter
- **Project:** InfraLogging REST API (Option 2)
- **Repository:** https://github.com/ritterpa/csc640
- **Submitted version:** tag [`stage1`](https://github.com/ritterpa/csc640/tree/stage1)

See `HW4.md` for common requirements and peer review. The criteria below describe Option 2; Option 1 uses the equivalent for its approved project.

---

## Self-Grading Rules

Self-score each row and link to evidence. Full credit requires the stated working behavior and evidence. Partial credit reflects the proportion correctly demonstrated. Round proportional scores to the nearest 0.5 point. Missing or nonworking evidence earns no credit for that part. The final score combines your three peers' reviews and the instructor's evaluation.

---

<!-- _class: small -->

## Schedule (20 points)

| Criterion | Points | Earned |
|---|---:|---|
| Milestones in `HW4_plan_stage1.md` were completed on or before their planned dates; score = 10 × milestones on time ÷ total milestones | 10 | **10** /10 |
| Plan vs Actual dates were recorded and kept up to date throughout, and changes are explained | 10 | **10** /10 |
| **Total** | **20** | **20** /20 |


---

<!-- _class: small -->

## Schedule: Evidence

- **Plan:** [`plan/HW4_plan_stage1.md`](https://github.com/ritterpa/csc640/blob/stage1/plan/HW4_plan_stage1.md)
- **Milestone 1** (planned Nov 14, done **Nov 10**): login, token checks, and endpoints E00–E04 in commit [`87b0c4b`](https://github.com/ritterpa/csc640/tree/87b0c4b7a7bee680657dd504a2809ac1eb9dfc4d). Re-run of that commit: **28 of 28** E00–E04 tests pass ([`stage1-milestone1-2026-11-10.txt`](https://github.com/ritterpa/csc640/blob/stage1/code/results/stage1-milestone1-2026-11-10.txt)).
- **Milestone 2** (planned Nov 18): all 13 endpoints in commit [`79f1ab8`](https://github.com/ritterpa/csc640/tree/79f1ab8f4f6cd43128d8621eed3d5591879cec11) (Nov 10); re-run of that commit: **73 of 73** pass ([`stage1-milestone2-2026-11-10.txt`](https://github.com/ritterpa/csc640/blob/stage1/code/results/stage1-milestone2-2026-11-10.txt)). Results recorded in `plan/acceptance.md` Nov 18.
- **Milestone 3** (planned Nov 21): README completed Nov 20 (commit `9a41729`); this evaluation submitted with Part 2.
- **Steady progress:** 37 commits from Nov 5 to Nov 20 ([commit history](https://github.com/ritterpa/csc640/commits/main)).

---

## Implementation and Quality (60 points)

Evaluate everything below on the API **running through NGINX**; a working program means NGINX works.

For Option 1, you earn each row's points by implementing the equivalent feature.

---

<!-- _class: small -->

| Criterion | Points | Earned |
|---|---:|---|
| HW1-approved PHP/MySQL endpoint set matches the documented contract; score = 20 × working endpoints ÷ approved endpoints | 20 | **20** /20 |
| HW1-approved protected endpoints support valid access and reject missing/invalid tokens; score = 10 × fully verified protected endpoints ÷ approved protected endpoints | 10 | **10** /10 |

- **13 of 13** endpoints work through NGINX (port 8180): the full run passes **73 of 73** tests ([`stage1-2026-11-17.txt`](https://github.com/ritterpa/csc640/blob/stage1/code/results/stage1-2026-11-17.txt)). The contract is Section 1 of [`plan/acceptance.md`](https://github.com/ritterpa/csc640/blob/stage1/plan/acceptance.md).
- **12 of 12** protected endpoints verified: each passes a valid-token success test, a missing-token test (`-TM`), and an invalid-token test (`-TI`), all 401 as expected. Also an expired token (`E01-TX`) and read-only tokens on the 3 write endpoints (`-TS`, 403).

---

## Implementation and Quality (cont.)

<!-- _class: small -->

| Criterion | Points | Earned |
|---|---:|---|
| Executable acceptance tests for every endpoint: success and relevant failure assertions, reproducible data, saved pass/fail results | 15 | **15** /15 |
| HTML/JavaScript test page calls every endpoint and displays success/error results | 5 | **5** /5 |
| MySQL CRUD works with documented schema and setup | 10 | **10** /10 |
| **Total** | **60** | **60** /60 |


---

<!-- _class: tiny -->

## Implementation and Quality: Evidence

- **Tests:** [`code/tests/acceptance.sh`](https://github.com/ritterpa/csc640/blob/stage1/code/tests/acceptance.sh) runs 73 tests with one command. Every endpoint has success and failure cases checking both status and JSON content, and the run exits nonzero on any failure.
- **Reproducible data:** [`code/tests/seed.sql`](https://github.com/ritterpa/csc640/blob/stage1/code/tests/seed.sql) resets the database before every run. Saved results are in [`code/results/`](https://github.com/ritterpa/csc640/tree/stage1/code/results).
- **Web pages:** [`index.html`](https://github.com/ritterpa/csc640/blob/stage1/code/stage1/public/index.html) and [`assets/app.js`](https://github.com/ritterpa/csc640/blob/stage1/code/stage1/public/assets/app.js) call all 13 endpoints:
  - Sign-in calls login.
  - Runs, Scripts, and Reports call the 6 list, detail, and report endpoints.
  - Run details call the run, its logs, exceptions, and metrics.
  - Start a run, Finish a run, and Add a log entry call the 3 write endpoints.

  Each page shows the API's error message when a call fails.

---

<!-- _class: small -->

## Implementation and Quality: Evidence (cont.)

- **MySQL:**
  - The schema is [`Deploy-InfraLogging.mysql.sql`](https://github.com/ritterpa/csc640/blob/stage1/code/database/Deploy-InfraLogging.mysql.sql), and setup is [`setup-database.sh`](https://github.com/ritterpa/csc640/blob/stage1/code/database/setup-database.sh).
  - **Create:** `POST /runs` and `POST /runs/{id}/logs`.
  - **Read:** 9 `GET` endpoints.
  - **Update:** `PATCH /runs/{id}`.
  - **No Delete:** run logs are an append-only audit trail, so the approved scope has no Delete endpoint.

---

<!-- _class: small -->

## Tutorial and Reproducibility (20 points)

| Criterion | Points | Earned |
|---|---:|---|
| Marp tutorial explains every endpoint's method/path, inputs, outputs, and authentication; submit Markdown and PDF (no minimum page count) | 10 | **10** /10 |
| Correct successful and failed request/response examples | 5 | **5** /5 |
| README in the GitHub repository identifies the submitted version and links setup, run, and test instructions that a reviewer can follow | 5 | **5** /5 |
| **Total** | **20** | **20** /20 |


---

<!-- _class: small -->

## Tutorial and Reproducibility: Evidence

- **Tutorial:** [`stage1-tutorial.md`](https://github.com/ritterpa/csc640/blob/stage1/presentation/stage1-tutorial.md) and [`stage1-tutorial.pdf`](https://github.com/ritterpa/csc640/blob/stage1/presentation/stage1-tutorial.pdf): 47 slides covering every endpoint's method/path, inputs, outputs, and authentication.
- **Examples:** a success and a failure example for all 13 endpoints (26 total), each a real `curl` request and response captured from the running API.
- **README:** [`README.md`](https://github.com/ritterpa/csc640/blob/stage1/README.md) takes a fresh WSL machine from package installation through [`setup-database.sh`](https://github.com/ritterpa/csc640/blob/stage1/code/database/setup-database.sh), [`setup-nginx.sh`](https://github.com/ritterpa/csc640/blob/stage1/code/nginx/setup-nginx.sh), and [`acceptance.sh`](https://github.com/ritterpa/csc640/blob/stage1/code/tests/acceptance.sh) to the browser. Following it on a fresh clone, as a regular user, passed **73 of 73** tests.
- **Submitted version:** tag [`stage1`](https://github.com/ritterpa/csc640/tree/stage1), named in the README's status table.
---

<!-- _class: small -->

## Final Checks

- [x] My HW1 GitHub repository has `code/`, `presentation/`, and `plan/`, with `acceptance.md`, tests/results, and README.
- [x] Published source and documentation on GitHub; the link is on my HW1 Canvas progress page. 
- [x] Cited reused work (lecture slides by file name and page) and AI assistance; I can explain my implementation and tests. 
- [x] I will submit `peer-review.md` (from `peer-review-example.md`) on Canvas (HW4 Part 2 Peer Review).

**Total: 100 /100** (Schedule 20 + Implementation 60 + Tutorial 20)

