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
