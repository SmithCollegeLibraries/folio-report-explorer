# Task 9 Report: Review advisories, deletion, user purge, and retention

## Status

Complete on `feature/ask-ai-confidence-review`, based on `cd65bb6a6188d1856dc4c9e5122771667308641e`.

## Delivered

- Added allowlisted history advisories for `cautioned` and `superseded` reviews. Ordinary history receives only stable user copy and, for supersession, the replacement job id; administrator notes and generation evidence are not selected or returned.
- Preserved `query_jobs.status = completed` for cautioned and superseded results.
- Extended history deletion to delete linked review rows followed by generation rows inside the same database transaction before export cleanup and query-job deletion.
- Kept existing export-path validation and failure behavior unchanged.
- Integrated `AdministratorReviewService::purgeUserContent()` before account deletion so raw questions, generated SQL, follow-up context, confidence evidence, and administrator notes do not survive user removal.
- Added `cleanup/reviews` and invoked it from `cleanup/run`; it uses `SettingsService::getAiReportReviewRetentionDays()` and the existing strict retention implementation.
- Kept dry-run cleanup non-mutating.

## TDD Evidence

Initial RED:

```text
Single deletion must remove linked review and generation rows.
UnknownMethodException: Calling unknown method: FixedReviewCleanupController::actionReviews()
```

GREEN focused verification:

```text
Query history deletion service test passed
Folio query controller history deletion test passed
Ask AI review retention test passed
```

Coverage includes single and repeated batch-path deletion, transactional rollback after a forced job-delete failure, safe advisory projection, unchanged completed status, user-content purge, configured cleanup integration, and strict 89/90/91-day behavior (the 90-day cutoff remains).

## Verification

Focused regressions:

```bash
php backend/tests/QueryHistoryDeletionServiceTest.php
php backend/tests/FolioQueryControllerHistoryDeletionTest.php
php backend/tests/AskAiReviewRetentionTest.php
```

Full backend suite:

```bash
for test_file in backend/tests/*.php; do php "$test_file" || exit 1; done
```

The full suite exited 0. One FOLIO PostgreSQL integration check skipped because its connection environment was unavailable. Existing PHP 8.5 reflection deprecations and `ReferenceResolverService` fixture warnings remain; Task 9 introduced no test failure.

Syntax and whitespace verification covered all modified PHP files with `php -l` and `git diff --check`.

## Self-review

- Database deletion order is reviews, generations, then job, and a database exception rolls linked-row deletions back.
- Export deletion remains restricted to the canonical owned CSV path. As before, filesystem deletion itself is not database-transactional.
- History SQL selects only advisory state and replacement id from review storage; notes and evidence remain excluded.
- Cleanup obtains the setting through the existing clamped settings API and uses UTC time.
- Production syntax remains compatible with PHP 7.2.
- Migrated deployments are expected to have the Task 4 `ai_report_generations` and `ai_report_reviews` tables before this integration is deployed.
- Unrelated dirty files were neither modified nor staged.

## Files

- `backend/services/QueryHistoryDeletionService.php`
- `backend/controllers/FolioQueryController.php`
- `backend/commands/CleanupController.php`
- `backend/tests/QueryHistoryDeletionServiceTest.php`
- `backend/tests/FolioQueryControllerHistoryDeletionTest.php`
- `backend/tests/AskAiReviewRetentionTest.php`
- `.superpowers/sdd/task-9-report.md`

## Important Findings Follow-up: Cardinality and Honest Supersession

Two review findings were addressed in a separate TDD cycle.

### RED

The one-to-many history join inflated totals and allowed one query job to occupy multiple pagination positions:

```text
History total must count query jobs rather than linked reviews.
```

The superseded mapper also claimed a correction remained available after its replacement job had been deleted and the foreign key had set `superseded_by_job_id` to null:

```text
Superseded history must not claim that a deleted replacement remains available.
```

### Fix

- Replaced direct generation/review fan-out joins with a correlated canonical-review join that returns at most one review per query job.
- Restricted canonical candidates to `cautioned` and `superseded`, choosing deterministically by `updated_at DESC, id DESC`.
- Preserved job-based totals, limits, and offsets without `DISTINCT` or post-pagination deduplication.
- Kept normal supersession copy and `supersededByJobId` when the replacement exists.
- When the replacement link is null, retained state `superseded`, omitted `supersededByJobId`, and returned: `A corrected version of this report was created, but it is no longer available in your history.`
- Continued selecting only advisory state and replacement id; notes, evidence, and execution status remain untouched.

### GREEN

Focused verification:

```text
Query history deletion service test passed
Folio query controller history deletion test passed
Ask AI review retention test passed
```

The history regression now creates three linked reviews for one job, including equal `updated_at` values, and proves one returned item, job-based total, distinct one-item pages, and stable descending-id tie-break behavior. It also deletes a replacement query job under SQLite foreign-key enforcement and verifies the resulting null-link advisory.

The full backend PHP suite exited 0 again. The FOLIO PostgreSQL integration check remained skipped because connection environment variables were unavailable. The same pre-existing PHP 8.5 reflection deprecations and `ReferenceResolverService` fixture warnings remain.

### Follow-up Self-review

- The correlated scalar subquery is valid in both the SQLite regression harness and the production MySQL query shape; it is bounded to one review id with an explicit stable order.
- `count()`, `LIMIT`, and `OFFSET` now operate on query-job cardinality because every remaining join is at most one row per job.
- A missing replacement cannot produce either availability copy or a null `supersededByJobId` key.
- Existing non-null supersession behavior, safe advisory allowlist, `completed` status, deletion authorization, and privacy assertions remain covered.
- The follow-up changes remain PHP 7.2-compatible and do not touch unrelated dirty files.
