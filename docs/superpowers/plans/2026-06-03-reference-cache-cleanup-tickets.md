# Reference Cache Cleanup Tickets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Clean up and prepare the local FOLIO reference-cache feature for a reviewable commit or PR.

**Architecture:** Keep the reference-cache feature separated from unrelated dirty work. Preserve the current backend split: `ReferenceResolverService` resolves prompt terms, `ReferenceCacheRefreshService` refreshes one enabled table, `ReferenceCacheController` owns CLI discovery/status/review, and `FolioQueryController` exposes admin API endpoints. Frontend work stays limited to Settings visibility/actions and Ask clarification handling.

**Tech Stack:** Yii2/PHP backend, MySQL local cache, PostgreSQL FOLIO backend, React/TypeScript frontend, Vitest, shell/PHP lightweight tests.

---

## Ticket 1: Separate Reference-Cache Diff From Unrelated Dirty Work

**Problem:** The worktree contains reference-cache changes plus unrelated edits for query feedback, AI settings, family telemetry, and generated schema cache files. Review will be noisy unless the feature diff is separated.

**Files:**
- Review: `git status --short`
- Reference-cache backend/API: `backend/config/web.php`, `backend/controllers/FolioQueryController.php`, `backend/commands/ReferenceCacheController.php`, `backend/services/ReferenceResolverService.php`, `backend/services/ReferenceCacheRefreshService.php`, `backend/services/GeminiService.php`
- Reference-cache frontend: `frontend/src/pages/Ask.tsx`, `frontend/src/pages/Settings.tsx`, `frontend/src/api/client.ts`, `frontend/src/types/schema.ts`
- Reference-cache DB/docs/deploy: `mysql/init.sql`, `mysql/migrations/032_folio_reference_cache.sql`, `deploy.sh`, `docs/reference-cache-operations.md`
- Reference-cache tests: `backend/tests/ReferenceCache*Test.php`, `backend/tests/ReferenceResolverServiceTest.php`, `backend/tests/ClarificationEventSchemaTest.php`, `frontend/src/api/client.referenceCache.test.ts`, `frontend/src/api/client.referenceCacheCandidates.test.ts`, `frontend/src/pages/Settings.referenceCacheReview.test.ts`, `frontend/src/pages/Ask.errorFormatting.test.ts`
- Likely separate/unrelated: `mysql/migrations/031_ai_query_feedback.sql`, `backend/tests/QueryFeedbackSchemaTest.php`, generated cache JSON under `backend/data/`, existing AI config/family telemetry tests.

- [ ] **Step 1: Create a review file list**

Run:

```bash
git status --short
```

Expected: working tree includes both reference-cache and unrelated modified/untracked files.

- [ ] **Step 2: Produce a reference-cache-only diff summary**

Run:

```bash
git diff --stat -- \
  backend/config/web.php \
  backend/controllers/FolioQueryController.php \
  backend/commands/ReferenceCacheController.php \
  backend/services/ReferenceResolverService.php \
  backend/services/ReferenceCacheRefreshService.php \
  backend/services/GeminiService.php \
  frontend/src/pages/Ask.tsx \
  frontend/src/pages/Settings.tsx \
  frontend/src/api/client.ts \
  frontend/src/types/schema.ts \
  mysql/init.sql \
  mysql/migrations/032_folio_reference_cache.sql \
  deploy.sh \
  docs/reference-cache-operations.md \
  backend/tests/ReferenceCacheCandidatesEndpointTest.php \
  backend/tests/ReferenceCacheControllerDriftTest.php \
  backend/tests/ReferenceCacheInventoryAllowlistTest.php \
  backend/tests/ReferenceCacheRefreshEndpointTest.php \
  backend/tests/ReferenceCacheSchemaTest.php \
  backend/tests/ReferenceCacheStatusEndpointTest.php \
  backend/tests/ReferenceResolverServiceTest.php \
  backend/tests/ClarificationEventSchemaTest.php \
  frontend/src/api/client.referenceCache.test.ts \
  frontend/src/api/client.referenceCacheCandidates.test.ts \
  frontend/src/pages/Settings.referenceCacheReview.test.ts \
  frontend/src/pages/Ask.errorFormatting.test.ts
```

Expected: summary shows only reference-cache-related files.

- [ ] **Step 3: Decide commit boundary**

Create one of these commit groupings:

```text
Option A: one reference-cache commit
- all files listed in Step 2

Option B: three commits
- backend/db reference cache
- frontend reference cache UI/clarification
- docs/deploy/tests
```

Acceptance: unrelated query-feedback/generated-cache/AI-config work is not included in the same commit unless explicitly intended.

## Ticket 2: Finalize Migration Safety

**Problem:** `032_folio_reference_cache.sql` previously failed when `ai_clarification_events` was missing. It has been hardened, but this needs to remain explicitly verified before commit.

**Files:**
- Modify only if verification fails: `mysql/migrations/032_folio_reference_cache.sql`
- Test: `backend/tests/ClarificationEventSchemaTest.php`
- Test: `backend/tests/ReferenceCacheSchemaTest.php`

- [ ] **Step 1: Verify static migration checks**

Run:

```bash
php backend/tests/ClarificationEventSchemaTest.php
php backend/tests/ReferenceCacheSchemaTest.php
```

Expected:

```text
Clarification event schema test passed
Reference cache schema test passed
```

- [ ] **Step 2: Apply migration once to Docker MySQL**

Run:

```bash
docker compose exec -T mysql mysql -uroot -prootpass folio_reports < mysql/migrations/032_folio_reference_cache.sql
```

Expected: exit code `0`. The only acceptable output is the MySQL password warning.

- [ ] **Step 3: Apply migration a second time**

Run:

```bash
docker compose exec -T mysql mysql -uroot -prootpass folio_reports < mysql/migrations/032_folio_reference_cache.sql
```

Expected: exit code `0` again, proving idempotency.

- [ ] **Step 4: Verify required columns exist**

Run:

```bash
docker compose exec -T mysql mysql -uroot -prootpass folio_reports -e "SHOW COLUMNS FROM ai_clarification_events LIKE 'clarification_batch_id'; SHOW COLUMNS FROM ai_clarification_events LIKE 'selected_source_table'; SHOW TABLES LIKE 'folio_reference_tables';"
```

Expected: output contains `clarification_batch_id`, `selected_source_table`, and `folio_reference_tables`.

## Ticket 3: Verify Reference Cache Runtime Flow End To End

**Problem:** The implemented flow spans discovery, review, refresh, local values, resolver prompt guidance, and Settings visibility. It needs one documented end-to-end runtime pass before commit.

**Files:**
- Command: `backend/commands/ReferenceCacheController.php`
- Service: `backend/services/ReferenceCacheRefreshService.php`
- Service: `backend/services/ReferenceResolverService.php`
- Controller: `backend/controllers/FolioQueryController.php`

- [ ] **Step 1: Discover candidates**

Run:

```bash
docker compose exec php php yii reference-cache/discover-candidates
```

Expected: output like:

```text
Discovered <number> FOLIO table reference candidates.
```

- [ ] **Step 2: Refresh enabled tables**

Run:

```bash
docker compose exec php php yii reference-cache/refresh
```

Expected: enabled tables refresh without failed rows. Any failed table must show a concrete missing-column or mapping reason.

- [ ] **Step 3: Verify API status**

Run:

```bash
docker compose exec php curl -s http://nginx/api/reference-cache/status
```

Expected JSON:

```json
{
  "available": true,
  "failedTables": 0
}
```

`enabledTables` and `activeRows` should be non-zero.

- [ ] **Step 4: Verify one immediate refresh**

Run:

```bash
docker compose exec php curl -s -X POST -H 'Content-Type: application/json' -d '{"sourceTable":"circulation.cancellation_reason__t"}' http://nginx/api/reference-cache/refresh
```

Expected JSON:

```json
{
  "sourceTable": "circulation.cancellation_reason__t",
  "rowCount": 5,
  "lastRefreshStatus": "success"
}
```

- [ ] **Step 5: Verify unsafe candidate rejection**

Run:

```bash
docker compose exec php curl -s -i -X POST -H 'Content-Type: application/json' -d '{"sourceTable":"organizations.urls__t","decision":"enable"}' http://nginx/api/reference-cache/candidates/review
```

Expected: HTTP `422` with:

```json
{
  "error": "Cannot enable candidate because required id column was not found"
}
```

## Ticket 4: Reduce Duplicate Refreshability Logic

**Problem:** `FolioQueryController::assertReferenceCandidateCanRefresh()` and `ReferenceCacheRefreshService::inferRefreshMapping()` both know the safe label column list. That is acceptable short-term, but a cleanup pass should centralize that policy to prevent drift.

**Files:**
- Modify: `backend/services/ReferenceCacheRefreshService.php`
- Modify: `backend/controllers/FolioQueryController.php`
- Test: `backend/tests/ReferenceCacheCandidatesEndpointTest.php`
- Test: `backend/tests/ReferenceCacheControllerDriftTest.php`

- [ ] **Step 1: Add a public validation method to the service**

Modify `backend/services/ReferenceCacheRefreshService.php`:

```php
public function validateSourceTableCanRefresh(string $sourceTable)
{
    [$schema, $table] = $this->splitSourceTable($sourceTable);
    $columns = $this->loadExistingColumns($schema, $table);

    if (!isset($columns['id'])) {
        return 'Cannot enable candidate because required id column was not found';
    }

    try {
        $this->inferRefreshMapping($sourceTable, $columns);
    } catch (\RuntimeException $e) {
        return 'Cannot enable candidate because no safe refresh label column was found';
    }

    return null;
}
```

- [ ] **Step 2: Use the service in the controller**

Modify `FolioQueryController::assertReferenceCandidateCanRefresh()` to:

```php
private function assertReferenceCandidateCanRefresh(string $sourceTable)
{
    try {
        return (new ReferenceCacheRefreshService())->validateSourceTableCanRefresh($sourceTable);
    } catch (\Throwable $e) {
        return 'Cannot enable candidate because FOLIO columns could not be inspected: ' . $e->getMessage();
    }
}
```

- [ ] **Step 3: Remove duplicate split/column-probe logic**

Delete `FolioQueryController::splitSourceTableName()` if no other code uses it.

Run:

```bash
rg -n "splitSourceTableName|assertReferenceCandidateCanRefresh|validateSourceTableCanRefresh" backend/controllers/FolioQueryController.php backend/services/ReferenceCacheRefreshService.php
```

Expected: `splitSourceTableName` does not appear; validation policy appears in `ReferenceCacheRefreshService`.

- [ ] **Step 4: Verify tests**

Run:

```bash
php backend/tests/ReferenceCacheCandidatesEndpointTest.php
php backend/tests/ReferenceCacheControllerDriftTest.php
php -l backend/controllers/FolioQueryController.php
php -l backend/services/ReferenceCacheRefreshService.php
```

Expected: all pass.

## Ticket 5: Decide Whether Enable Should Offer Refresh-Now

**Problem:** The Settings page can refresh enabled tables, but after enabling a candidate the user must find it in the enabled table list. This is safe, but not maximally ergonomic.

**Files:**
- Modify if accepted: `frontend/src/pages/Settings.tsx`
- Test if accepted: `frontend/src/pages/Settings.referenceCacheReview.test.ts`

- [ ] **Step 1: Choose behavior**

Decision:

```text
Recommended: after enable succeeds, show "Review saved: <table>. Refresh from the enabled table list."
Alternative: after enable succeeds, show an inline "Refresh now" button for the just-enabled table.
Avoid: automatic refresh after enable, because refresh hits FOLIO and may take time.
```

- [ ] **Step 2: If using the recommended copy, update message**

Modify `Settings.tsx` in `reviewReferenceCandidateMut.onSuccess`:

```ts
setReferenceReviewMessage(
  result.enabled
    ? `Review saved: ${result.sourceTable}. Refresh from the enabled table list.`
    : `Review saved: ${result.sourceTable}`,
);
```

- [ ] **Step 3: Add guard test for copy**

Modify `Settings.referenceCacheReview.test.ts`:

```ts
it('directs users to refresh enabled candidates manually', () => {
  expect(source).toContain('Refresh from the enabled table list');
});
```

- [ ] **Step 4: Verify frontend**

Run:

```bash
npm test -- Settings.referenceCacheReview.test.ts
npm run build
```

Expected: test passes and build succeeds with only the existing Vite large-chunk warning.

## Ticket 6: Document Commit/PR Verification Checklist

**Problem:** The feature has several verification commands. Add a concise checklist so whoever commits or reviews can repeat the important checks.

**Files:**
- Modify: `docs/reference-cache-operations.md`

- [ ] **Step 1: Add verification section**

Append to `docs/reference-cache-operations.md`:

````markdown
## Pre-Commit Verification

Run before committing reference-cache changes:

```bash
for test in backend/tests/*Test.php; do php "$test" || exit 1; done
cd frontend && npm test -- --run
cd frontend && npm run build
```

Verify migration idempotency:

```bash
docker compose exec -T mysql mysql -uroot -prootpass folio_reports < mysql/migrations/032_folio_reference_cache.sql
docker compose exec -T mysql mysql -uroot -prootpass folio_reports < mysql/migrations/032_folio_reference_cache.sql
```
````

- [ ] **Step 2: Verify doc file is readable**

Run:

```bash
sed -n '1,220p' docs/reference-cache-operations.md
```

Expected: document includes Setup, Discover Candidates, Refresh, Nightly Cron, Review Rules, Status, and Pre-Commit Verification.

## Ticket 7: Final Full Verification Before Commit

**Problem:** After cleanup tickets are complete, run one final full verification pass.

**Files:** No code files should be modified in this ticket unless verification exposes a concrete bug.

- [ ] **Step 1: Backend tests**

Run:

```bash
for test in backend/tests/*Test.php; do php "$test" || exit 1; done
```

Expected: all backend tests print pass messages and command exits `0`.

- [ ] **Step 2: Frontend tests**

Run:

```bash
cd frontend && npm test -- --run
```

Expected: all frontend tests pass.

- [ ] **Step 3: Frontend build**

Run:

```bash
cd frontend && npm run build
```

Expected: build exits `0`; the existing Vite large-chunk warning is acceptable.

- [ ] **Step 4: Live reference-cache API smoke**

Run:

```bash
docker compose exec php curl -s http://nginx/api/reference-cache/status
docker compose exec php curl -s -X POST -H 'Content-Type: application/json' -d '{"sourceTable":"circulation.cancellation_reason__t"}' http://nginx/api/reference-cache/refresh
```

Expected: status returns `"available":true`; refresh returns `"lastRefreshStatus":"success"`.

- [ ] **Step 5: Review git status**

Run:

```bash
git status --short
```

Expected: files intended for the reference-cache commit are clear and unrelated dirty files are either excluded or intentionally included in a separate commit.

## Ticket 8: Add Resolver-First Clarification Loop

**Problem:** Prompts can contain a named local term that does not resolve as a cached location/library/collection. The system should visibly show what it checked and ask a follow-up before generating SQL, especially when the term appears in another approved context such as contributor, title, identifier, or notes.

**Files:**
- Modify: `backend/services/ReferenceResolverService.php`
- Create: `backend/services/ResolverClarificationService.php`
- Modify: `backend/services/GeminiService.php`
- Modify: `backend/tests/ReferenceResolverServiceTest.php`
- Create: `backend/tests/ResolverClarificationServiceTest.php`
- Modify: `frontend/src/pages/Ask.tsx`
- Modify: `frontend/src/pages/Ask.errorFormatting.test.ts`
- Modify: `frontend/src/types/schema.ts`

- [x] **Step 1: Add backend resolver tests**

Add tests proving:
- A named collection/location/library phrase extracts the named term as a safe-probe term.
- A contributor match returns a batched clarification instead of SQL generation.
- The response includes `resolverTrace` showing cached reference lookup and approved probe results.
- If probes find no candidate options, the resolver still asks the user and does not invent options.

- [x] **Step 2: Expand safe probe targets**

Add approved probes for:
- `inventory.contributor__t.name`
- `inventory.instance__t__contributors.contributors__name`
- `inventory.instance__t.title`
- `inventory.instance__t.index_title`
- `inventory.instance__t.hrid`
- existing `notes.note_data__t` safe fields

Probe options should use actual probe labels/previews and should not append hard-coded bibliographic meaning.

- [x] **Step 3: Add visible Ask resolver trace**

Add frontend type support and a compact "Resolver checks" block in clarification panels so users can see what was checked before the follow-up question.

Visible wording should describe library/reporting concepts first:
- locations, libraries, campuses, funds, material types, and other report filters
- contributor/author fields
- title fields
- instance number fields
- notes fields

Exact table/column names should be secondary detail, not the primary label.

- [x] **Step 4: Add model clarification service**

Create `ResolverClarificationService` so resolver evidence is the source of truth and the model is used only to phrase the follow-up question.

Required behavior:
- valid model clarification sets `routeReason` to `resolver_model_clarification`
- invalid model clarification falls back to `resolver_deterministic_fallback`
- fallback includes `modelClarificationFallbackReason`
- model options must map to resolver-provided option ids
- model output must not include SQL
- model output must cover every resolver clarification item

- [x] **Step 5: Add model provider method**

Add `GeminiService::generateResolverClarificationJson()` using the configured provider and existing retry/request plumbing. Prompt rules must explicitly say:
- do not generate SQL
- do not invent tables, columns, filters, meanings, or option ids
- use resolver evidence only

- [x] **Step 6: Add telemetry fields**

Clarification telemetry should include:
- `clarificationSource`
- `modelClarificationFallbackReason`

- [x] **Step 7: Verify targeted tests**

Run:

```bash
php backend/tests/ReferenceResolverServiceTest.php
php backend/tests/ResolverClarificationServiceTest.php
cd frontend && npm test -- --run src/pages/Ask.errorFormatting.test.ts
```

Expected: both pass.

---

## Self-Review

**Spec coverage:** Tickets cover diff separation, migration safety, runtime verification, duplicate logic cleanup, UI refresh ergonomics, resolver-first clarification, docs, and final verification.

**Placeholder scan:** No ticket uses placeholder language or unspecified tests. Every ticket has concrete commands and expected outcomes.

**Type consistency:** Referenced functions and types match the current implementation names: `ReferenceCacheRefreshService`, `refreshReferenceCacheTable`, `ReferenceCacheRefreshTableResponse`, `actionReferenceCacheRefresh`, and `validateSourceTableCanRefresh` for the proposed cleanup.
