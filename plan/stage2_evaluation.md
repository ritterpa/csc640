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


# Stage 2 Evaluation

- **Student:** Paul Ritter
- **Project:** InfraLogging REST API (Option 2)
- **Repository:** https://github.com/ritterpa/csc640

This version contains the **SOLID review** of the Stage 2 (Laravel) code. The graded sections (schedule, implementation, tutorial) are added with Milestone 4, as planned in [`HW4_plan_stage2.md`](HW4_plan_stage2.md).

---

<!-- _class: small -->

## SOLID Review: Method

1. **Review.** The Stage 2 code (`code/stage2/src`) was checked against the five SOLID principles. The review found 15 items: 5 for SRP, 3 for OCP, 1 for LSP, 2 for ISP, and 4 for DIP.
2. **Decide.** Each item was either fixed, or left as is with a reason (next slides).
3. **Fix without changing behavior.**
   - **Every fix:** all acceptance tests passed. Results are saved in [`code/results/`](../code/results/) and the Results table of [`acceptance.md`](acceptance.md).
   - **#1 and #2** were tested on Stage 2 through `run.sh`, before the Docker stack existed.
   - **From #3 on:** exact API responses (status, headers, and body) were captured before and after each change and compared byte for byte. Values that change on every run (new IDs, "now" times, tokens) were checked for format instead. Both stages were tested in Docker (`./code/docker/test.sh`), and the saved results were run on the committed code.

---

<!-- _class: tiny -->

## SOLID Review: Fixed

| # | Principle | Problem | Fix | Commit |
|---:|---|---|---|---|
| 1 | SRP | `RunController::store` held the "start a run" business rule | `app/Actions/StartRun.php` | `761a26e` |
| 2 | SRP | `RunController::update` computed the run duration | `Run::finish()` | `d054115` |
| 3 | SRP | Controllers built response arrays by hand | `ScriptDetailResource`, `RunStartedResource`, `FailingScriptResource` | `b1ea76f` |
| 4 | SRP | Run statuses and log levels were constants in `ApiInput` | `RunStatus` and `LogLevel` enums | `02da3e2` |
| 5 | SRP | JSON encoding was repeated in two places | `Format::jsonText()` | `2e466ad` |
| 6 | OCP | Adding a status meant editing three places | Counts built from `RunStatus::cases()`; `Script::withRunCounts()` | `54792f9` |
| 7 | OCP | The from/to date filter was copied three times | `FiltersByDateRange` trait: `->withinDates()` | `60a868a` |
| 12 | DIP | `AuthController` called static `JwtTokens` and the `Hash` facade | `JwtTokens` instance from the container; `Hasher` injected | `e16168c` |
| 13 | DIP | Code read the system clock directly | `CarbonImmutable::now()`, which tests can freeze | `65195a6` |
| 15 | DIP | `ApiException` and `ApiError` depended on each other | Removed `ApiError`'s `ApiException` branch, which never ran | `d224c4e` |

Every fix passed all acceptance tests; from #3 on, responses were also shown to be unchanged byte for byte. Results for each are in the Results table of [`acceptance.md`](acceptance.md).

---

<!-- _class: small -->

## SOLID Review: Left As Is

| # | Principle | Finding | Why it was left |
|---:|---|---|---|
| 8 | OCP | `ApiError::fromThrowable()` is a type switch | It is the one place that maps errors to the Stage 1 error format, and its default branch handles any new type safely. Per-type handlers would have to be registered in exactly the right order, and an error there fails silently. |
| 9 | LSP | (no violation) | Every subclass keeps its parent's contract. For example, `RunResource` returns a superset of `RunSummaryResource`. |
| 10 | ISP | `ApiInput` is a set of static helpers | After #4 it is cohesive: every method parses or validates request input. Splitting it would give single-method classes. |
| 11 | ISP | Resources use `@mixin` access to the whole model | This is how Laravel Resources are designed to work. |
| 14 | DIP | Controllers query Eloquent models directly | This is idiomatic Laravel. The one place it mattered, the write logic, moved into `StartRun` (#1). |

`ApiInput`, `DateInput`, and `Format` stay static on purpose (#12): they are pure functions with no state or configuration.

---

<!-- _class: small -->

## SOLID Review: Bug Found Along the Way

While checking #5, the before/after comparison showed a bug in **both stages**:

- An empty JSON object `{}` sent in `parameters` or `jsonData` was stored and returned as `[]`, and `{"0":"x"}` as `["x"]`.
- **Cause:** request bodies and stored JSON text were decoded into PHP arrays, which cannot tell objects from lists.
- **Fix:** commit `4f3aefd`. Free-form JSON fields are read with a second decode that keeps objects, and stored JSON is returned as objects. This also fixed Stage 2 returning `{"5":"five","2":"two"}` as a list.
- **New acceptance test E08-S2** sends empty, nested, and number-keyed objects and checks they come back unchanged. That brought the suite from 73 to **74 tests**. Its check rejects the old output and passes against the fixed code in both stages.

To test both stages the same way, Stage 1 was also added to the Docker stack (commit `8e62c35`).

---

<!-- _class: small -->

## SOLID Review: Strengths Kept

These parts already followed SOLID, and the fixes built on them:

- **SRP:** formatting is in Resources, models are thin, and each `Support` class has one job. `DateInput` is plain PHP, so it can be unit tested alone.
- **OCP:** Laravel is extended through its hooks: a custom `jwt` guard, a parameterized `scope:` middleware, and route groups that apply auth to every endpoint in them.
- **LSP:** `RunResource extends RunSummaryResource` adds fields without removing any.
- **DIP:** routes depend on the `auth:jwt` guard, not on the token code, and settings are read through `config()`.

**Result:** all 74 acceptance tests pass in both stages on the final code (commit `e16168c`; results in [`code/results/`](../code/results/)).
