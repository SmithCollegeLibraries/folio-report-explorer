# Query Cancellation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Stop action actually interrupt active FOLIO queries, remain truthful while cancellation is in progress, and always settle as Cancelled instead of Failed.

**Architecture:** Add a focused `QueryJobCancellationService` that owns atomic state transitions and PostgreSQL backend cancellation. Workers consume a shared cancellation state contract (`cancelling`/`cancelled`), checkpoint between execution phases, and reconcile PostgreSQL cancellation exceptions into the terminal cancelled state. The React client displays the intermediate state and keeps polling until the worker confirms termination.

**Tech Stack:** PHP 7.2, Yii 2 ActiveRecord/DB commands, MySQL migrations, PostgreSQL `pg_cancel_backend`, React 18, TypeScript, Vitest.

## Global Constraints

- A user may cancel only their own job; an administrator may cancel any job.
- Pending jobs cancel immediately; running jobs remain active as `cancelling` until the worker confirms execution stopped.
- `cancelling` FOLIO jobs continue consuming a concurrency slot.
- Cancellation is idempotent for `cancelling` and `cancelled` jobs.
- Completed and failed jobs remain non-cancellable and return HTTP 409.
- Do not commit `backend/data/table_mapping_cache.json`, `backend/vendor`, or generated frontend build output.

---

### Task 1: Cancellation state and atomic service

**Files:**
- Create: `backend/services/QueryJobCancellationService.php`
- Modify: `backend/models/QueryJob.php`
- Create: `mysql/migrations/038_query_job_cancelling_status.sql`
- Modify: `mysql/init.sql`
- Test: `backend/tests/QueryJobCancellationServiceTest.php`

**Interfaces:**
- Produces: `QueryJobCancellationService::__construct(Connection $localDb, Connection $folioDb, callable $backendCanceller = null)`.
- Produces: `QueryJobCancellationService::cancel(QueryJob $job): QueryJob`, throwing `DomainException` for terminal non-cancellable states.
- Produces: `QueryJob::markCancelled(string $message = 'Cancelled by user'): void`.
- State contract: `pending|pending_export -> cancelled`, `running -> cancelling`, `cancelling|cancelled -> unchanged`.

- [ ] **Step 1: Write the failing service test**

Create an in-memory SQLite `query_jobs` table, insert pending/running/terminal jobs, and assert immediate pending cancellation, running-to-cancelling with the stored PID passed to an injected callback, idempotency, and `DomainException` for completed jobs. Assert `markCancelled()` sets `completed_at`, clears `pg_backend_pid`, and uses the friendly progress message.

- [ ] **Step 2: Run the service test to verify it fails**

Run: `php backend/tests/QueryJobCancellationServiceTest.php`

Expected: FAIL because `app\services\QueryJobCancellationService` and `QueryJob::markCancelled()` do not exist.

- [ ] **Step 3: Implement the minimal state service and model transition**

Implement atomic conditional updates with `yii\db\Connection::createCommand()->update()` so a worker claim cannot race a pending cancellation. For a running job, persist `cancelling` before invoking the backend callback. The default callback must execute:

```php
$this->folioDb->createCommand('SELECT pg_cancel_backend(:pid)', [':pid' => $pid])->queryScalar();
```

Implement the terminal model transition:

```php
public function markCancelled($message = 'Cancelled by user')
{
    $this->status = 'cancelled';
    $this->completed_at = date('Y-m-d H:i:s');
    $this->progress_message = $message;
    $this->error_message = null;
    if ($this->hasAttribute('pg_backend_pid')) {
        $this->pg_backend_pid = null;
    }
    $this->save(false);
}
```

Extend the model status rule and property documentation with `cancelling`.

- [ ] **Step 4: Add the schema migration and bootstrap schema**

Add migration 038 with an information-schema guard that changes the enum to:

```sql
ENUM('pending','running','cancelling','completed','failed','cancelled','pending_export')
```

Make the same enum change in `mysql/init.sql`.

- [ ] **Step 5: Run the focused tests**

Run: `php backend/tests/QueryJobCancellationServiceTest.php && php backend/tests/MigrationServiceTest.php`

Expected: both scripts print their pass messages and exit 0.

- [ ] **Step 6: Commit**

```bash
git add backend/services/QueryJobCancellationService.php backend/models/QueryJob.php backend/tests/QueryJobCancellationServiceTest.php mysql/init.sql mysql/migrations/038_query_job_cancelling_status.sql
git commit -m "feat: add atomic query cancellation state"
```

### Task 2: Authorized cancellation endpoint

**Files:**
- Modify: `backend/controllers/FolioQueryController.php:1484-1510`
- Test: `backend/tests/FolioQueryControllerCancellationTest.php`

**Interfaces:**
- Consumes: `QueryJobCancellationService::cancel(QueryJob $job): QueryJob`.
- Produces: `POST /api/query/cancel/<id>` returning the updated `QueryJob::toStatusArray()`.

- [ ] **Step 1: Write the failing controller test**

Test these outcomes against SQLite with authenticated test identities: owner can cancel; non-owner receives 403 without a state change; admin can cancel another user’s job; missing job returns 404; completed job returns 409; repeated cancellation returns 200.

- [ ] **Step 2: Run the controller test to verify it fails**

Run: `php backend/tests/FolioQueryControllerCancellationTest.php`

Expected: FAIL because the current action has no ownership check and does not use the cancellation service.

- [ ] **Step 3: Route the action through authorization and the service**

Use the same owner/admin check as `actionDeleteHistoryJob()`. Construct the service from `Yii::$app->db` and `Yii::$app->folioDb`, call `cancel()`, map `DomainException` to HTTP 409, and return the refreshed status object. Never expose database exception text to the client; log it and return HTTP 503 with `Unable to stop this query right now. It is still being monitored.`

- [ ] **Step 4: Run the focused tests**

Run: `php backend/tests/FolioQueryControllerCancellationTest.php && php backend/tests/QueryJobCancellationServiceTest.php`

Expected: both scripts pass.

- [ ] **Step 5: Commit**

```bash
git add backend/controllers/FolioQueryController.php backend/tests/FolioQueryControllerCancellationTest.php
git commit -m "fix: authorize and interrupt query cancellation"
```

### Task 3: Worker cancellation reconciliation

**Files:**
- Modify: `backend/commands/QueryWorkerController.php`
- Modify: `backend/commands/ExportWorkerController.php`
- Modify: `backend/tests/QueryWorkerConcurrencyTest.php`
- Test: `backend/tests/QueryWorkerCancellationTest.php`

**Interfaces:**
- Consumes: `QueryJob::markCancelled()` and the `cancelling|cancelled` state contract.
- Produces: worker-private `isCancellationRequested(QueryJob $job): bool` and `finishCancellation(QueryJob $job): void` helpers in both workers.

- [ ] **Step 1: Write failing worker tests**

Extend concurrency coverage so a `cancelling` FOLIO job counts toward the configured limit. Add cancellation tests proving that pre-execution cancellation skips SQL, cancellation after a stored PID discards results, a thrown PostgreSQL cancellation while the row is `cancelling` settles as `cancelled`, and export cancellation deletes a partial CSV instead of marking the job failed.

- [ ] **Step 2: Run the worker tests to verify they fail**

Run: `php backend/tests/QueryWorkerConcurrencyTest.php && php backend/tests/QueryWorkerCancellationTest.php`

Expected: at least one assertion fails because `cancelling` is not counted or reconciled.

- [ ] **Step 3: Implement query-worker checkpoints**

Count both active states:

```php
->where(['status' => ['running', 'cancelling']])
```

Refresh and check cancellation before execution, after storing `pg_backend_pid`, after FOLIO execution, between composite primary and secondary queries, and before completion. In every catch block, refresh first; if status is `cancelling` or `cancelled`, call `markCancelled()` and do not call `markFailed()` or log a failure.

Store the PostgreSQL PID for composite primary queries as well as ordinary FOLIO queries.

- [ ] **Step 4: Implement export-worker checkpoints**

Count `cancelling` jobs toward concurrency. Check before execution, after storing the PID, during row streaming, and before completion. On cancellation, close the handle, remove the partial file when present, call `markCancelled()`, and never mark the job failed.

- [ ] **Step 5: Run focused and regression tests**

Run: `php backend/tests/QueryWorkerConcurrencyTest.php && php backend/tests/QueryWorkerCancellationTest.php`

Expected: both scripts pass.

- [ ] **Step 6: Commit**

```bash
git add backend/commands/QueryWorkerController.php backend/commands/ExportWorkerController.php backend/tests/QueryWorkerConcurrencyTest.php backend/tests/QueryWorkerCancellationTest.php
git commit -m "fix: reconcile worker cancellation safely"
```

### Task 4: Truthful cancellation UI

**Files:**
- Modify: `frontend/src/types/schema.ts`
- Modify: `frontend/src/api/client.ts:489-491`
- Modify: `frontend/src/hooks/useJobPolling.ts`
- Modify: `frontend/src/hooks/useHistoryData.ts`
- Modify: `frontend/src/pages/History.tsx:163-177`
- Modify: `frontend/src/pages/history/HistoryTable.tsx:264-290`
- Modify: `frontend/src/components/StatusBadge.tsx`
- Test: `frontend/src/components/StatusBadge.test.tsx`
- Test: `frontend/src/hooks/useJobPolling.test.tsx`

**Interfaces:**
- Consumes: cancellation endpoint returning `JobStatusResponse` with `status: 'cancelling'|'cancelled'`.
- Produces: `cancelJob(jobId: string): Promise<JobStatusResponse>`.
- Produces: visible `Cancelling…` status while polling continues.

- [ ] **Step 1: Write failing frontend tests**

Assert `StatusBadge` renders `Cancelling…` with a spinner. In the hook test, mock cancellation returning `cancelling`, assert polling continues and no cancellation error is shown, then return `cancelled` from status polling and assert polling stops with the terminal message.

- [ ] **Step 2: Run the frontend tests to verify they fail**

Run: `npm test -- --run frontend/src/components/StatusBadge.test.tsx frontend/src/hooks/useJobPolling.test.tsx`

Expected: TypeScript/test failure because `cancelling` is not a `JobStatus` and cancel currently returns void.

- [ ] **Step 3: Implement the UI state contract**

Add `cancelling` to `JobStatus`. Return `response.data` from `cancelJob()`. Set the hook job to the returned response and keep polling until the server reports `cancelled`; include `cancelling` in `isRunning`. Include `cancelling` in history auto-refresh, optimistically replace the affected history item with the endpoint response fields, show the spinner/status badge, and hide the repeated cancel action while cancellation is underway.

- [ ] **Step 4: Run frontend tests and build**

Run: `npm test -- --run frontend/src/components/StatusBadge.test.tsx frontend/src/hooks/useJobPolling.test.tsx && npm run build`

Expected: focused tests pass and the production build exits 0.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/types/schema.ts frontend/src/api/client.ts frontend/src/hooks/useJobPolling.ts frontend/src/hooks/useHistoryData.ts frontend/src/pages/History.tsx frontend/src/pages/history/HistoryTable.tsx frontend/src/components/StatusBadge.tsx frontend/src/components/StatusBadge.test.tsx frontend/src/hooks/useJobPolling.test.tsx
git commit -m "fix: show query cancellation progress"
```

### Task 5: Full verification and operator test

**Files:**
- Modify: `planning/2026-07-19-query-reliability-backlog.md`

**Interfaces:**
- Consumes: all cancellation changes.
- Produces: completed APP-001 acceptance evidence in the backlog.

- [ ] **Step 1: Run all backend test scripts**

Run each tracked `backend/tests/*Test.php` script with PHP, stopping on the first non-zero exit.

Expected: every script exits 0; the live FOLIO test may report its documented credential skip.

- [ ] **Step 2: Run the frontend suite and build**

Run: `npm test && npm run build`

Expected: all Vitest suites pass and TypeScript/Vite build succeeds.

- [ ] **Step 3: Verify the migration and worktree**

Run: `php backend/yii migrate/status` only if the project exposes that command; otherwise verify migration discovery with `php backend/tests/MigrationServiceTest.php`. Run `git status --short` and confirm generated cache, vendor symlink, and build output are not staged.

- [ ] **Step 4: Record acceptance evidence**

Mark APP-001 complete in `planning/2026-07-19-query-reliability-backlog.md` and record the automated commands plus this production smoke test: start a deliberately slow owned FOLIO query, click Stop, observe `Cancelling…`, confirm it becomes `Cancelled`, confirm no result appears, and confirm another queued query starts after the backend terminates.

- [ ] **Step 5: Commit**

```bash
git add planning/2026-07-19-query-reliability-backlog.md
git commit -m "docs: record cancellation verification"
```
