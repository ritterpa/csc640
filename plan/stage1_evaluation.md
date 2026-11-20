---
marp: true
html: true
size: 4:3
paginate: true
style: |
  section.small {
    font-size: 22px;
  }
---

# Stage 1 Evaluation

**Total: 100 points.** Submit in HW4 Part 2.

See `HW4.md` for common requirements and peer review. The criteria below describe Option 2; Option 1 uses the equivalent for its approved project.

---

## Self-Grading Rules

Self-score each row and link to evidence. Full credit requires the stated working behavior and evidence. Partial credit reflects the proportion correctly demonstrated. Round proportional scores to the nearest 0.5 point. Missing or nonworking evidence earns no credit for that part. The final score combines your three peers' reviews and the instructor's evaluation.

---

## Schedule (20 points)

| Criterion | Points | Earned |
|---|---:|---|
| Milestones in `HW4_plan_stage1.md` were completed on or before their planned dates; score = 10 × milestones on time ÷ total milestones | 10 | [ /10] |
| Plan vs Actual dates were recorded and kept up to date throughout, and changes are explained | 10 | [ /10] |
| **Total** | **20** | **[ /20]** |

---

## Implementation and Quality (60 points)

Evaluate everything below on the API **running through NGINX**; a working program means NGINX works.

For Option 1, you earn each row's points by implementing the equivalent feature.

---

| Criterion | Points | Earned |
|---|---:|---|
| HW1-approved PHP/MySQL endpoint set matches the documented contract; score = 20 × working endpoints ÷ approved endpoints | 20 | [ /20] |
| HW1-approved protected endpoints support valid access and reject missing/invalid tokens; score = 10 × fully verified protected endpoints ÷ approved protected endpoints | 10 | [ /10] |

---

## Implementation and Quality (cont.)

| Criterion | Points | Earned |
|---|---:|---|
| Executable acceptance tests for every endpoint: success and relevant failure assertions, reproducible data, saved pass/fail results | 15 | [ /15] |
| HTML/JavaScript test page calls every endpoint and displays success/error results | 5 | [ /5] |
| MySQL CRUD works with documented schema and setup | 10 | [ /10] |
| **Total** | **60** | **[ /60]** |

---

## Tutorial and Reproducibility (20 points)

| Criterion | Points | Earned |
|---|---:|---|
| Marp tutorial explains every endpoint's method/path, inputs, outputs, and authentication; submit Markdown and PDF (no minimum page count) | 10 | [ /10] |
| Correct successful and failed request/response examples | 5 | [ /5] |
| README in the GitHub repository identifies the submitted version and links setup, run, and test instructions that a reviewer can follow | 5 | [ /5] |
| **Total** | **20** | **[ /20]** |

---

## Final Checks

- [x] My HW1 GitHub repository has `code/`, `presentation/`, and `plan/`, with `acceptance.md`, tests/results, and README.
- [x] Published source and documentation on GitHub; the link is on my HW1 Canvas progress page.
- [x] Cited reused work (lecture slides by file name and page) and AI assistance; I can explain my implementation and tests.
- [x] I will submit `peer-review.md` (from `peer-review-example.md`) on Canvas (HW4 Part 2 Peer Review).

**Total: [ 100/100]** (Schedule 20 + Implementation 60 + Tutorial 20)
