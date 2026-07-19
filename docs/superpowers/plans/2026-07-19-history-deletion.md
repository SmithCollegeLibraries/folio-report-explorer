# Permanent Query-History Deletion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make terminal query-history deletion permanent across the API, export filesystem, frontend state, polling, modal routing, and pagination.

**Architecture:** A focused backend service enforces terminal-state eligibility and narrowly scoped export cleanup before deleting a UUID-keyed `QueryJob`. The controller owns authorization and safe HTTP responses. The frontend combines a request-generation guard with a pure deletion-state reducer so stale history loads cannot restore deleted rows and pagination/modal state updates are deterministic.

**Tech Stack:** PHP 7.2, Yii 2 ActiveRecord, SQLite test harnesses, React 18, TypeScript, Vitest, React Testing Library.

## Global Constraints

- Deletable states are exactly `completed`, `failed`, and `cancelled`.
- Active states `pending`, `pending_export`, `running`, and `cancelling` return HTTP 409 with `Stop this query before deleting it from history.`
- Owners may delete their own eligible jobs; administrators may delete any eligible job.
- Query-job UUIDs remain strings and must never be cast to integers.
- Never recursively delete, follow symlinks, remove directories, or touch paths outside `@runtime/exports`.
- Never expose filesystem paths or internal exception messages to ordinary users.
- Preserve unrelated query jobs, saved queries, reports, dashboards, and query logs.
- Do not commit `backend/vendor`, `frontend/node_modules`, frontend build output, or generated schema caches.

---

### Task 1: Terminal deletion and safe export cleanup service

**Files:**
- Create: `backend/services/QueryHistoryDeletionService.php`
- Test: `backend/tests/QueryHistoryDeletionServiceTest.php`

**Interfaces:**
- Produces: `QueryHistoryDeletionService::__construct(string $exportDirectory, ?callable $fileDeleter = null, ?callable $warningLogger = null)`.
- Produces: `QueryHistoryDeletionService::delete(QueryJob $job): void`.
- Throws: `DomainException` for active jobs and `RuntimeException` when an eligible in-scope export or database row cannot be removed.

- [ ] **Step 1: Write the failing service test**

Create a Yii console application with an in-memory SQLite `query_jobs` table and a temporary export directory. Add separate assertions for:

```php
$service->delete($completedJob); // row removed
$service->delete($failedJob);    // row removed
$service->delete($cancelledJob); // row removed
```

Assert every active status throws `DomainException` and retains its row. Create `<uuid>.csv` inside the export directory and assert both file and row are removed. Create an external file, a traversal path, a directory, and a symlink and assert each filesystem target remains untouched while the corresponding eligible history row is removed and a warning is recorded. Inject a file deleter returning `false` for a valid export and assert the export and row remain.

- [ ] **Step 2: Run the service test to verify it fails**

Run: `php backend/tests/QueryHistoryDeletionServiceTest.php`

Expected: FAIL because `app\services\QueryHistoryDeletionService` does not exist.

- [ ] **Step 3: Implement minimal deletion eligibility**

Implement:

```php
public function delete(QueryJob $job)
{
    $job->refresh();
    if (!in_array($job->status, ['completed', 'failed', 'cancelled'], true)) {
        throw new \DomainException('Stop this query before deleting it from history.');
    }
    $this->removeExport($job);
    if (!$job->delete()) {
        throw new \RuntimeException('History row could not be deleted.');
    }
}
```

The constructor stores the configured export directory. Export cleanup canonicalizes it with `realpath()` only when a job has an export path. A missing directory must not block deletion of a non-export job; an export reference whose directory or path cannot be canonicalized is logged, left untouched, and does not block deletion of the history row.

- [ ] **Step 4: Implement narrowly scoped export cleanup**

For a non-empty `export_file_path`, require all of the following before calling the injected/default `unlink` callback:

```php
!is_link($path)
&& is_file($path)
&& realpath(dirname($path)) === $this->exportDirectory
&& basename($path) === $job->id . '.csv'
&& realpath($path) === $this->exportDirectory . DIRECTORY_SEPARATOR . $job->id . '.csv'
```

Missing files need no cleanup. Unsafe existing paths call the warning logger and are not removed. A false return from the deleter for a valid file throws `RuntimeException` before database deletion.

- [ ] **Step 5: Run the focused test**

Run: `php backend/tests/QueryHistoryDeletionServiceTest.php`

Expected: `Query history deletion service test passed` and exit 0.

- [ ] **Step 6: Commit**

```bash
git add backend/services/QueryHistoryDeletionService.php backend/tests/QueryHistoryDeletionServiceTest.php
git commit -m "feat: safely delete terminal history jobs"
```

### Task 2: UUID controller endpoint and deletion eligibility metadata

**Files:**
- Modify: `backend/controllers/FolioQueryController.php:4010-4075,4334-4365`
- Test: `backend/tests/FolioQueryControllerHistoryDeletionTest.php`

**Interfaces:**
- Consumes: `QueryHistoryDeletionService::delete(QueryJob $job): void`.
- Produces: the documented `200|403|404|409|500` delete responses.
- Produces: `canDelete: bool` only for authorized viewers of terminal jobs.

- [ ] **Step 1: Write the failing controller test**

Use a Yii web application with SQLite `users` and `query_jobs` tables plus a temporary `runtimePath`. Insert UUID-keyed jobs and assert:

```php
$response = $controller->actionDeleteHistoryJob('8f4a4aa0-1111-4222-8333-123456789abc');
```

deletes an owned completed row and returns the same UUID. Assert non-owner `403`, administrator success, missing `404`, and each active status `409` with stable guidance. Call `actionQueryHistory()` as an owner and administrator and assert active rows have `canDelete === false` while authorized terminal rows have `canDelete === true`.

- [ ] **Step 2: Run the controller test to verify it fails**

Run: `php backend/tests/FolioQueryControllerHistoryDeletionTest.php`

Expected: FAIL because the UUID is cast to `0`, active deletion is allowed, or `canDelete` ignores status.

- [ ] **Step 3: Fix UUID lookup, authorization, and service routing**

Import `QueryHistoryDeletionService`, replace `QueryJob::findOne((int) $id)` with `QueryJob::findOne((string) $id)`, retain owner/admin authorization, then call:

```php
$service = new QueryHistoryDeletionService(Yii::getAlias('@runtime/exports'));
$service->delete($job);
```

Map `DomainException` to 409 with its stable guidance. Log `Throwable` details and return HTTP 500 with `Unable to delete this history item right now. Please try again.`

- [ ] **Step 4: Restrict history `canDelete` metadata**

Compute:

```php
$authorized = $isAdmin || ($userId !== null && (int) $job['user_id'] === (int) $userId);
$terminal = in_array($job['status'], ['completed', 'failed', 'cancelled'], true);
$canDelete = $authorized && $terminal;
```

Also include `cancelling` in the history endpoint’s `active` status filter.

- [ ] **Step 5: Run focused backend tests**

Run: `php backend/tests/FolioQueryControllerHistoryDeletionTest.php && php backend/tests/QueryHistoryDeletionServiceTest.php`

Expected: both scripts pass.

- [ ] **Step 6: Commit**

```bash
git add backend/controllers/FolioQueryController.php backend/tests/FolioQueryControllerHistoryDeletionTest.php
git commit -m "fix: delete UUID history jobs permanently"
```

### Task 3: Prevent stale history loads from restoring mutations

**Files:**
- Modify: `frontend/src/hooks/useHistoryData.ts`
- Test: `frontend/src/hooks/useHistoryData.test.tsx`

**Interfaces:**
- Produces: `UseHistoryDataReturn.invalidateLoads(): void`.
- Maintains: `loadGenerationRef`, incremented for every load and explicit mutation invalidation.

- [ ] **Step 1: Write the failing hook test**

Mock `fetchQueryHistory()` with deferred promises. Render the hook, let load A start, call `invalidateLoads()`, resolve load A with the deleted row, and assert the row is not installed. Then call `load()` for load B, resolve it with a current row, and assert load B applies. Verify a stale request cannot clear an error or loading state belonging to a newer request.

- [ ] **Step 2: Run the hook test to verify it fails**

Run: `npm test -- --run src/hooks/useHistoryData.test.tsx`

Expected: FAIL because `invalidateLoads` is missing and stale responses currently call `setItems()`.

- [ ] **Step 3: Add request generations**

Implement the load guard:

```ts
const loadGenerationRef = useRef(0);

const invalidateLoads = useCallback(() => {
  loadGenerationRef.current += 1;
}, []);

const load = useCallback(async () => {
  const generation = ++loadGenerationRef.current;
  setLoading(true);
  try {
    const data = await fetchQueryHistory(PAGE_LIMIT, offset, statusTab, mineOnly);
    if (generation !== loadGenerationRef.current) return;
    setItems(data.items);
    setTotal(data.total);
    setError(null);
  } catch (e: any) {
    if (generation !== loadGenerationRef.current) return;
    setError(e.response?.data?.error || 'Failed to load history');
  } finally {
    if (generation === loadGenerationRef.current) setLoading(false);
  }
}, [offset, statusTab, mineOnly]);
```

Return `invalidateLoads` from the hook.

- [ ] **Step 4: Run the focused test**

Run: `npm test -- --run src/hooks/useHistoryData.test.tsx`

Expected: the hook race tests pass.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/hooks/useHistoryData.ts frontend/src/hooks/useHistoryData.test.tsx
git commit -m "fix: ignore stale history responses"
```

### Task 4: Deterministic deletion state, modal cleanup, and pagination

**Files:**
- Create: `frontend/src/pages/history/historyDeletionState.ts`
- Test: `frontend/src/pages/history/historyDeletionState.test.ts`
- Modify: `frontend/src/pages/History.tsx`
- Modify: `frontend/src/pages/history/HistoryTable.tsx`

**Interfaces:**
- Produces: `deriveHistoryDeletionState(items: HistoryItem[], total: number, offset: number, limit: number, deletedIds: string[], modalJobId: string | null): { items: HistoryItem[]; total: number; offset: number; closeModal: boolean }`.
- Consumes: `useHistoryData().invalidateLoads()`.

- [ ] **Step 1: Write the failing pure state tests**

Assert the reducer removes only successful IDs, clamps totals at zero, keeps the current offset after partial deletion, moves from offset 50 to 0 when every displayed row is deleted, and returns `closeModal: true` only when the open modal’s ID was deleted.

- [ ] **Step 2: Run the reducer test to verify it fails**

Run: `npm test -- --run src/pages/history/historyDeletionState.test.ts`

Expected: FAIL because the reducer module does not exist.

- [ ] **Step 3: Implement the pure reducer**

Use a `Set` of successful IDs, filter items, calculate `pageEmptied` from the pre-deletion visible items, clamp total with `Math.max(0, total - deletedIds.length)`, and set the previous offset with `Math.max(0, offset - limit)` only when `pageEmptied && offset > 0`.

- [ ] **Step 4: Integrate successful single and batch deletion**

Add a shared `applySuccessfulDeletions(deletedIds)` callback in `History.tsx` that calls `invalidateLoads()`, derives the new state, updates items/total/offset, removes selection IDs, and calls `closeModal()` when required. Single deletion calls it after API success. Batch deletion passes only fulfilled IDs and preserves the existing partial-failure message.

Ensure active rows never show selection or delete controls by relying on backend `canDelete` and an explicit terminal-status check in `HistoryTable`.

- [ ] **Step 5: Run frontend deletion tests and build**

Run: `npm test -- --run src/hooks/useHistoryData.test.tsx src/pages/history/historyDeletionState.test.ts src/pages/history/HistoryTable.promptVisibility.test.tsx && npm run build`

Expected: all focused tests pass and the production build exits 0.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/history/historyDeletionState.ts frontend/src/pages/history/historyDeletionState.test.ts frontend/src/pages/History.tsx frontend/src/pages/history/HistoryTable.tsx
git commit -m "fix: keep deleted history absent"
```

### Task 5: Full verification and backlog evidence

**Files:**
- Modify: `planning/2026-07-19-query-reliability-backlog.md`

**Interfaces:**
- Consumes: all APP-002 changes.
- Produces: implementation evidence and production smoke instructions.

- [ ] **Step 1: Run backend tests twice consecutively**

Run every `backend/tests/*Test.php` script twice, stopping on the first failure.

Expected: 101 or more scripts pass in each run; the credential-dependent live PostgreSQL test may report its documented skip.

- [ ] **Step 2: Run the complete frontend suite and build**

Run: `npm test && npm run build`

Expected: all Vitest tests pass and TypeScript/Vite build exits 0.

- [ ] **Step 3: Audit the diff and generated files**

Run: `git diff --check`, `git status --short`, and `git diff --stat main...HEAD`. Confirm `backend/vendor`, `frontend/node_modules`, build output, and schema caches are not staged.

- [ ] **Step 4: Record APP-002 evidence**

Mark code complete with production smoke pending. Record test counts and this smoke test: delete a completed owned job, wait through at least two five-second polling intervals, manually refresh, and confirm it remains absent; separately confirm an active job instructs the user to stop it first.

- [ ] **Step 5: Commit**

```bash
git add planning/2026-07-19-query-reliability-backlog.md
git commit -m "docs: record history deletion verification"
```
