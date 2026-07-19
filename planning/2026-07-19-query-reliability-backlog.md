# Query Reliability and Job Lifecycle Backlog

**Created:** 2026-07-19  
**Status:** Approved for planning  
**Source:** Production testing of Ask AI, query history, and queue controls  
**Delivery approach:** Isolated, test-driven changes with one independently deployable workstream per branch

## Objective

Make complex Ask AI reports analytically trustworthy while restoring reliable query-history deletion and queue cancellation. Changes must not introduce blocking validation across unrelated Ask AI requests.

## Confirmed evidence

- The acquisitions/circulation ROI compiler originally referenced an item call-number column on the holdings table. That missing-column defect is fixed on `main`, and compiler SQL now receives a schema-backed physical-column audit.
- The current ROI output still mixes physical and electronic resources, treats arbitrary text prefixes as call-number classes, counts PO lines rather than physical copies, and compares paid spending with circulation at a different cohort grain.
- A purchased-but-unused prompt exhausted exploratory repair after an `unknown_table` failure. User-facing messaging correctly hid rejected SQL, but administrative telemetry did not retain the offending identifier needed for diagnosis or repair.
- A consortial-uniqueness prompt executed but silently narrowed the cohort to material type `book` and holdings type `Physical`. It returned only 19 distinct instances and did not implement the agreed physical-eligibility or dominant-class rules.
- The electronic-renewal prompt returned plausible detail rows, but emitted vendor and access-provider UUIDs, did not explicitly restrict the cohort to electronic PO lines, used invoice date instead of payment date, and did not produce the requested vendor/month summary. The two December sample rows for the same vendor total `$786.00`.
- `FolioQueryController::actionQueryCancel()` changes job status to `cancelled` but does not call PostgreSQL `pg_cancel_backend()`, even though `QueryWorkerController` records `pg_backend_pid`.
- A database cancellation exception can enter the worker's general failure path and overwrite `cancelled` with `failed`.
- History deletion is permitted for active jobs. Frontend history polling can also race mutation responses, so an older list response may restore a deleted row.

## Global constraints

- Preserve the current working production query path behind a rollback flag until its replacement passes production-like preflight.
- Do not enable new blocking semantic validation for unrelated Ask AI requests.
- Use documented defaults with concise user-facing assumptions that users can correct.
- Never represent absent electronic usage data as zero usage or electronic-resource ROI.
- Enforce Smith acquisition scope through the `SC` acquisitions unit for spending reports.
- Validate every deterministic physical table and column against checked-in schema caches.
- Aggregate measures at their native grain before joining one-to-many domains.
- Keep rejected SQL and database internals out of ordinary user messages.
- Implement each task with a failing regression test before production changes.

## Priority 0: Job lifecycle reliability

### APP-001 — Make queue cancellation stop work

**Problem:** Cancelling a running job currently changes only the MySQL job status. The PostgreSQL query continues consuming the single FOLIO execution slot, and the worker may later overwrite the job status.

**Scope:**

- Add owner-or-administrator authorization to cancellation.
- Atomically transition `pending` and `pending_export` jobs to `cancelled` so a worker cannot claim them afterward.
- For running FOLIO jobs, validate the stored backend PID and issue `pg_cancel_backend()` from a separate FOLIO connection.
- Preserve `cancelled` when PostgreSQL reports user-request cancellation.
- Clear the backend PID after every terminal transition.
- Add cooperative cancellation checkpoints for local, composite, and export work.
- Return an idempotent response when a job is already cancelled.
- Update the frontend row immediately to `Cancelling…`, then reconcile it to `Cancelled` through polling.

**Acceptance criteria:**

- A cancelled pending job is never claimed by a worker.
- A running PostgreSQL query releases the FOLIO execution slot promptly.
- A cancelled job cannot later become `completed` or `failed`.
- A non-owner who is not an administrator receives `403`.
- Repeating cancellation is safe and does not produce a misleading error.
- Concurrency tests cover cancellation before claim, during execution, and during terminal persistence.

### APP-002 — Make history deletion permanent

**Problem:** Deleted rows can remain or reappear, and active jobs can currently be deleted while workers still reference them.

**Scope:**

- Reproduce deletion through the API, database, frontend state, and automatic polling boundaries.
- Permit deletion only for `completed`, `failed`, or `cancelled` jobs; return `409` for active jobs with guidance to cancel first.
- Preserve owner-or-administrator authorization for single and batch deletion.
- Remove associated export files through a validated, narrowly scoped path.
- Prevent stale history requests from overwriting newer delete/cancel state, using request generations or abortable loads.
- Close a results modal when its job is deleted.
- Correct totals and pagination after deletion, including deletion of the final row on a page.
- Keep saved reports, dashboards, and unrelated history entries intact.

**Acceptance criteria:**

- A deleted job remains absent after manual refresh and multiple polling intervals.
- Single and batch deletion behave consistently.
- Active-job deletion is rejected without disrupting the worker.
- Export cleanup cannot delete outside the configured export directory.
- Partial batch failures identify how many jobs failed without restoring successful deletions.
- Frontend tests reproduce and prevent the stale-load race.

## Priority 0: Presentation report correctness

### NL2SQL-236 — Harden physical acquisitions/circulation ROI

**Documented interpretation:** When acquisitions are compared with circulation, `purchased most` means invoiced physical copies. Distinct titles are returned as a companion measure. Electronic resources are out of scope because COUNTER or comparable usage data is unavailable.

**Scope:**

- Infer a physical-item cohort and disclose that assumption.
- Exclude electronic-only resources; allow explicit physical formats such as DVD.
- Require the Smith `SC` acquisitions unit and current Smith physical-item eligibility.
- Use invoiced physical quantity for copies and distinct instances for titles.
- Prefer exact purchased-item linkage through receiving pieces or PO-line identifiers; disclose an instance-level proxy where linkage coverage is insufficient.
- Aggregate fund-distribution spending before item or circulation joins.
- Count distinct checkout loans in the same reporting window.
- Assign each instance to the most common valid class among current Smith physical items, with alphabetical tie-breaking.
- Normalize valid LC classes, Dewey hundreds, `Local/Other`, and `Unclassified`.
- Return numeric measures with presentation-friendly labels and formatting.
- Retain the existing compiler behind a rollback flag until production validation completes.

**Acceptance criteria:**

- The original five-year ROI question returns results without manual SQL correction.
- Electronic resources and other institutions' spending cannot enter the cohort.
- Copies, titles, spending, and circulation are not multiplied by one-to-many joins.
- LC, Dewey, local, missing, mixed-case, and multi-call-number fixtures pass.
- The SQL passes semantic rules, schema-cache column validation, and PostgreSQL preflight.
- Reworded prompts with equivalent meaning compile to equivalent semantics.
- Unrelated Ask AI routes remain unchanged.

## Priority 1: Verified analytical families

### NL2SQL-237 — Purchased-but-unused physical items

**Scope:**

- Add a deterministic report for Smith physical items paid for during a requested period with no checkout since receipt.
- Use the same physical eligibility, acquisitions-unit scope, call-number classification, and purchase-item linkage policy as NL2SQL-236.
- Evaluate non-use at item grain with an anti-join against distinct checkout loans.
- Return physical copies, distinct titles, paid spending, percentage unused, and cost per unused copy.
- Document the available circulation-history coverage and avoid interpreting missing historical data as proven lifetime non-use.

**Acceptance criteria:**

- A fixture with a checkout is excluded from the unused cohort.
- A fixture with no checkout is included exactly once.
- Spending is reconciled before the item anti-join and cannot be duplicated.
- Electronic resources and non-Smith acquisitions are excluded.
- Unknown physical tables or columns fail during tests rather than production generation.

### NL2SQL-238 — Consortial unique physical holdings

**Documented interpretation:** Uniqueness means a current eligible Smith physical item exists and no eligible physical item for the same shared instance exists at another Five Colleges campus. It does not claim ISBN-, OCLC-, or title-text-level uniqueness across duplicate instances.

**Scope:**

- Replace holdings-type and implicit-book filtering with approved physical-item eligibility.
- Exclude withdrawn items and document treatment of missing, lost, and suppressed items.
- Use `NOT EXISTS` against eligible physical items at non-Smith campuses.
- Include all eligible physical formats unless the user explicitly narrows material type.
- Assign one dominant Smith call-number class per instance.
- Count distinct loans and use checkout-time location when it is available.
- Return additive title and copy totals with deterministic ordering before limiting.

**Acceptance criteria:**

- A sampled instance with an eligible item at another campus is excluded.
- A sampled Smith-only shared instance is included.
- A physical DVD remains eligible when the prompt does not request books only.
- The previous artificially narrow `book` plus holdings-type `Physical` cohort cannot compile.
- Title totals do not repeat across call-number classes.
- User messaging states the shared-instance uniqueness basis.

### NL2SQL-239 — Electronic renewal review

**Scope:**

- Restrict the cohort to electronic or mixed PO lines with electronic quantity.
- Require the Smith `SC` acquisitions unit.
- Resolve vendor and access-provider organization identifiers to readable names.
- Base paid spending on payment date and paid invoice status.
- Do not assume invoice-line status uses a `Paid` value.
- Keep currencies separate unless a documented conversion is applied.
- Return one detail row per PO line with vendor/month paid subtotal and order count through window aggregates or a documented summary shape.
- Render missing review dates and user limits as `Not recorded` in presentation output.
- Ensure row limits do not truncate a vendor/month group without disclosure.

**Acceptance criteria:**

- Physical-only ongoing orders do not appear.
- Vendor and access provider are readable organization names rather than UUIDs.
- The two recorded December sample rows produce a `$786.00` vendor/month subtotal when they share currency.
- October and December renewals remain chronologically ordered.
- Detail spending reconciles to vendor/month subtotals.
- The report makes no claim about usage or ROI.

## Priority 1: Validation and repair diagnostics

### NL2SQL-240 — Give automatic repair actionable schema details

**Problem:** The user should not receive raw SQL internals, but `unknown_table` alone is insufficient for either automatic repair or administrator diagnosis.

**Scope:**

- Attach structured offending table/column identifiers to internal validation exceptions.
- Pass those identifiers to the repair model with the relevant schema alternatives.
- Record sanitized identifiers, validation stage, and candidate hash in administrator telemetry.
- Keep raw SQL, database errors, and internal identifiers out of ordinary user responses.
- Distinguish invented physical tables, CTE parsing mistakes, missing columns, invalid operators, and semantic mismatches.
- Detect when a repair returns the same invalid reference and change strategy rather than consuming another identical attempt.

**Acceptance criteria:**

- An `unknown_table` repair receives the exact rejected relation and known nearby candidates.
- Administrator telemetry identifies the failing stage and reference without storing raw prompts or SQL.
- User-facing responses remain concise and nontechnical.
- Regression fixtures cover each safe failure category.
- The same invalid candidate is not retried unchanged.

## Delivery order

1. APP-001 — queue cancellation
2. APP-002 — history deletion
3. NL2SQL-236 — physical acquisitions/circulation ROI
4. NL2SQL-239 — electronic renewal review
5. NL2SQL-238 — consortial unique physical holdings
6. NL2SQL-237 — purchased-but-unused physical items
7. NL2SQL-240 — repair diagnostics

APP-001 and APP-002 are independent of the analytical-family work and must ship in separate commits or branches. Each NL2SQL family must be independently deployable and feature-flagged where it changes routing.

## Release gates

Every task must satisfy the following before production deployment:

1. A failing regression reproduces the reported problem before implementation.
2. Focused unit and integration tests pass after implementation.
3. All backend tests pass; known deprecation warnings must be recorded separately from failures.
4. Frontend type checking and targeted interaction tests pass for UI changes.
5. Deterministic SQL passes cached schema validation and a production-like PostgreSQL preflight.
6. Existing Ask AI golden prompts retain their route and semantic shape.
7. A rollback mechanism is documented and exercised.
8. Production smoke tests use exact saved prompts and preserve generated SQL/results for review.

## Presentation readiness

The presentation recording may use a report only after:

- Its generated SQL survives validation and execution twice from the same prompt.
- At least three representative rows reconcile to FOLIO source records.
- Aggregated totals reconcile to an independent control query.
- User-facing assumptions accurately describe physical/electronic scope and metric grain.
- A saved result or screenshot is available as a recording fallback.

The electronic renewal report is the closest new candidate to readiness after NL2SQL-239. The current consortial-uniqueness output and purchased-but-unused prompt are not presentation-ready.
