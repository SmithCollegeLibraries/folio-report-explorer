# Feedback-Ranked Query Memory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reuse proven report SQL conservatively, use compatible prior reports as bounded AI context, immediately suppress SQL marked Inaccurate, and let a user request different SQL without returning to a clarification or correction screen.

**Architecture:** Extend the existing `ai_query_feedback`, `ai_report_generations`, query-job metadata, and reuse endpoint. A single `QueryMemoryService` owns trust eligibility and ranking. Verified patterns may be reused globally when compatible; AI-built SQL requires same-user Accurate feedback or administrator approval for direct reuse. All reused SQL still crosses the ordinary safety, table-policy, schema, authorization, and preflight gates, and any failed candidate silently continues into the Phase 1 canonical/AI coordinator.

**Tech Stack:** PHP 7+/Yii2 backend, MySQL application metadata, PostgreSQL report preflight, React/TypeScript, TanStack Query, Vitest, standalone PHP regression tests.

**Spec:** `docs/superpowers/specs/2026-08-27-verified-first-ai-always-query-memory-design.md`

**Prerequisite:** Complete `docs/superpowers/plans/2026-08-27-ai-always-routing-safety.md` first.

## Global Constraints

- Provenance is immutable: reuse, positive feedback, and administrator approval never convert `ai_built` into `verified_pattern`.
- Direct reuse trust is exactly: compatible Verified pattern globally; compatible same-user Accurate AI-built; compatible administrator-approved AI-built. Nothing else.
- Existing rows without explicit provenance/feedback remain neutral and cannot become direct AI-built reuse candidates.
- Inaccurate feedback suppresses the exact SQL fingerprint from direct reuse and AI-example selection immediately.
- Another user's Accurate AI-built SQL may inform AI generation, but may not execute directly without administrator approval.
- Direct reuse requires the strict prompt-scoped schema-context fingerprint, data source, and authorized scope to match.
- AI-example selection requires the global schema-version fingerprint, data source, and authorized scope to match; prompt-context hashes are deliberately not compared across different questions.
- Reused SQL repeats safety, table-policy, schema, authorization, and database-preflight checks. Failure is invisible to the user and continues to fresh generation.
- Feedback and replacement endpoints accept identifiers, not client-authored SQL or provenance. The server loads trusted SQL and metadata.
- Examples are server-owned context, bounded in count and bytes, and kept outside user instructions.
- Weak interactions affect example ranking only. They never assert accuracy or enable direct reuse.
- Do not stage or modify the user's unrelated cache, SDD report, SQL dump, or WOLFCON document files.

---

### Task 1: Extend feedback and generation storage without promoting legacy rows

**Files:**
- Create: `mysql/migrations/044_query_feedback_reuse_trust.sql`
- Modify: `mysql/init.sql`
- Modify: `backend/services/MigrationService.php`
- Modify: `backend/tests/QueryFeedbackSchemaTest.php`
- Modify: `backend/tests/MigrationServiceTest.php`

**Interfaces:**
- `ai_query_feedback` gains trusted linkage, compatibility, suppression, approval, and replacement fields.
- `ai_report_generations` gains weak-interaction counters.
- Existing feedback rows retain their content, default to neutral trust, and are not backfilled as Accurate reuse records.

- [ ] **Step 1: Write failing schema assertions**

Extend `QueryFeedbackSchemaTest.php` to inspect migration 044 and `mysql/init.sql` for:

```php
$feedbackColumns = [
    'generation_id', 'query_job_id', 'generation_provenance',
    'direct_reuse_schema_fingerprint', 'schema_version_fingerprint',
    'scope_fingerprint', 'reuse_suppressed',
    'admin_reuse_approved_at', 'admin_reuse_approved_by',
    'replacement_generation_id',
];
$generationColumns = [
    'saved_count', 'downloaded_count', 'rerun_count', 'follow_up_count',
];
```

Assert nullable trust fields for old rows and `reuse_suppressed DEFAULT 0`. Add a MigrationService assertion that 044 is complete only when all required columns exist.

- [ ] **Step 2: Run schema tests and verify RED**

Run:

```bash
php backend/tests/QueryFeedbackSchemaTest.php
php backend/tests/MigrationServiceTest.php
```

Expected: FAIL because migration 044 and the new fields do not exist.

- [ ] **Step 3: Add the migration and init schema**

Use the repository's `information_schema`/prepared-ALTER convention so a partially applied migration is recoverable. Add these nullable feedback fields:

```sql
generation_id CHAR(36) NULL,
query_job_id CHAR(36) NULL,
generation_provenance ENUM('verified_pattern','ai_built') NULL,
direct_reuse_schema_fingerprint CHAR(64) NULL,
schema_version_fingerprint CHAR(64) NULL,
scope_fingerprint CHAR(64) NULL,
reuse_suppressed TINYINT(1) NOT NULL DEFAULT 0,
admin_reuse_approved_at DATETIME NULL,
admin_reuse_approved_by INT NULL,
replacement_generation_id CHAR(36) NULL
```

Add indexes for `(prompt_fingerprint, data_source, result_accuracy)`, `generation_id`, `query_job_id`, `direct_reuse_schema_fingerprint`, `schema_version_fingerprint`, `scope_fingerprint`, and `reuse_suppressed`. Add nullable foreign keys to `ai_report_generations(id)`, `query_jobs(id)`, and `users(id)` for generation, job, replacement generation, approving administrator, and existing feedback user. Existing rows are compatible because all new references are nullable.

Add non-negative integer counters to `ai_report_generations`:

```sql
saved_count INT UNSIGNED NOT NULL DEFAULT 0,
downloaded_count INT UNSIGNED NOT NULL DEFAULT 0,
rerun_count INT UNSIGNED NOT NULL DEFAULT 0,
follow_up_count INT UNSIGNED NOT NULL DEFAULT 0
```

Do not infer provenance, scope, schema compatibility, or approval for old rows.

- [ ] **Step 4: Register migration completion**

Add `044_query_feedback_reuse_trust.sql` to `MigrationService::migrationAppearsComplete()` using `hasColumns()` for both tables.

- [ ] **Step 5: Run schema tests and verify GREEN**

Run the commands from Step 2.

- [ ] **Step 6: Commit the storage extension**

```bash
git add mysql/migrations/044_query_feedback_reuse_trust.sql mysql/init.sql backend/services/MigrationService.php backend/tests/QueryFeedbackSchemaTest.php backend/tests/MigrationServiceTest.php
git commit -m "feat: store query reuse trust evidence"
```

---

### Task 2: Centralize trust, compatibility, and ranking in QueryMemoryService

**Files:**
- Create: `backend/services/QueryMemoryService.php`
- Create: `backend/tests/QueryMemoryServiceTest.php`
- Modify: `backend/services/PreviousSuccessfulQueryReuseService.php`
- Modify: `backend/tests/PreviousSuccessfulQueryReuseServiceTest.php`

**Interfaces:**
- Produces `QueryMemoryService::directReuseSchemaFingerprint(array $schemaMetadata): string` from global version plus prompt-scoped context hash.
- Produces `QueryMemoryService::currentDirectReuseSchemaFingerprint(string $prompt): string` from `FolioSchemaService::buildSchemaContext($prompt)` plus `FolioSchemaService::getMetadata()`.
- Produces `QueryMemoryService::schemaVersionFingerprint(array $schemaMetadata): string` from the global schema version only.
- Produces `QueryMemoryService::scopeFingerprint(string $dataSource, array $authorizedScope): string`.
- Produces `QueryMemoryService::findDirectReuse(array $request, array $candidates): ?array`.
- Produces `QueryMemoryService::selectAiExamples(array $request, array $candidates, int $limit = 3, int $byteLimit = 12000): array`.
- `PreviousSuccessfulQueryReuseService` remains the prompt-similarity/job-shaping helper; it no longer decides trust.

- [ ] **Step 1: Write the failing direct-reuse trust matrix**

Create table-driven cases for the same normalized question, compatible schema, and compatible scope:

```php
$cases = [
    ['verified_pattern', null,       false, 17, 22, true,  'verified_global'],
    ['ai_built',        'accurate', false, 17, 17, true,  'same_user_accurate'],
    ['ai_built',        'accurate', false, 17, 22, false, null],
    ['ai_built',        'accurate', true,  17, 22, true,  'administrator_approved'],
    ['ai_built',        'inaccurate', true, 17, 17, false, null],
    ['ai_built',        'unsure',   false, 17, 17, false, null],
    ['ai_built',        null,       false, 17, 17, false, null],
    [null,              'accurate', false, 17, 17, false, null],
];
```

Add rejection cases for a changed strict prompt-scoped schema-context fingerprint, changed scope, changed data source, suppressed SQL hash, and nonmatching normalized question. Assert provenance remains unchanged in the returned candidate.

- [ ] **Step 2: Write the failing AI-example ranking matrix**

Assert this order among compatible nonsuppressed candidates:

1. Verified pattern;
2. administrator-approved AI-built;
3. same-user Accurate AI-built;
4. other-user Accurate AI-built;
5. neutral/weak-positive AI-built.

Use candidates from different questions with different prompt-context hashes but the same global schema-version fingerprint, and assert they remain eligible and rank correctly. Then change only the global schema version and assert those examples are excluded as stale. Also assert Inaccurate, suppressed, unauthorized-scope, and unsafe candidates are absent, with at most three examples and at most 12,000 UTF-8 bytes of example payload.

- [ ] **Step 3: Run service tests and verify RED**

Run:

```bash
php backend/tests/QueryMemoryServiceTest.php
php backend/tests/PreviousSuccessfulQueryReuseServiceTest.php
```

Expected: FAIL because trust is currently inferred from successful jobs and no query-memory service exists.

- [ ] **Step 4: Implement stable fingerprints**

Canonicalize associative keys recursively before JSON encoding. Hash only server-owned values:

```php
public static function directReuseSchemaFingerprint(array $metadata): string
{
    return hash('sha256', self::canonicalJson([
        'version' => $metadata['version'] ?? null,
        'contextHash' => $metadata['contextHash'] ?? null,
    ]));
}

public static function schemaVersionFingerprint(array $metadata): string
{
    return hash('sha256', self::canonicalJson([
        'version' => $metadata['version'] ?? null,
    ]));
}

public static function scopeFingerprint(string $dataSource, array $scope): string
{
    return hash('sha256', self::canonicalJson([
        'dataSource' => strtolower($dataSource),
        'scope' => self::normalizeAuthorizedScope($scope),
    ]));
}
```

Scope comes from resolved/authorized server context, never directly from a client fingerprint.

For persisted generations, read `provenance_json.schemaMetadata` and persist both fingerprints. For direct reuse, calculate and compare the strict prompt-scoped fingerprint using the same context-hash algorithm as Gemini evidence. For AI examples, compare only `schema_version_fingerprint`; never compare prompt-context hashes across different questions. A missing legacy fingerprint makes the record stale for that use. A Verified pattern can still succeed independently through ordinary current-schema canonical compilation after a reuse miss.

- [ ] **Step 5: Implement direct eligibility and example ranking**

Return a `reuseTrust` value of exactly `verified_global`, `same_user_accurate`, or `administrator_approved` for direct reuse. Treat missing trust data as neutral. For examples, require only the matching global schema-version fingerprint, then use explicit tier first, prompt similarity second, weak-signal count third, successful recency fourth, and stable ID as the final tie-breaker.

Run `SqlBuilderService::validateSafety()` and table policy before any candidate can be returned by either method.

- [ ] **Step 6: Narrow PreviousSuccessfulQueryReuseService**

Retain normalization, similarity scoring, resolved-context matching, and job/result shaping. Remove `queryReuse` acceptance/edit metadata as a trust promotion. Expose candidates to `QueryMemoryService`; do not directly return an AI-built candidate merely because its query job completed.

- [ ] **Step 7: Run service tests and verify GREEN**

Run the commands from Step 3.

- [ ] **Step 8: Commit centralized query memory**

```bash
git add backend/services/QueryMemoryService.php backend/services/PreviousSuccessfulQueryReuseService.php backend/tests/QueryMemoryServiceTest.php backend/tests/PreviousSuccessfulQueryReuseServiceTest.php
git commit -m "feat: rank query memory by explicit trust"
```

---

### Task 3: Enforce trusted direct reuse and silently fall through on rejection

**Files:**
- Modify: `backend/controllers/FolioQueryController.php`
- Modify: `backend/services/AdministratorReviewService.php`
- Modify: `backend/tests/FolioQueryControllerReuseCandidateEndpointTest.php`
- Create: `backend/tests/FolioQueryControllerQueryMemoryReuseTest.php`
- Modify: `frontend/src/pages/Ask.tsx`
- Modify: `frontend/src/pages/Ask.queryReuse.test.ts`
- Modify: `frontend/src/pages/Ask.requestLifecycle.test.tsx`
- Modify: `frontend/src/types/schema.ts`

**Interfaces:**
- `POST /api/query/reuse-candidate` returns a match only after QueryMemory trust and compatibility checks.
- A returned match includes immutable provenance and `reuseTrust`.
- Executing a reuse creates a child `ai_report_generations` record linked to the source generation and new query job; feedback never attaches directly to another user's source record.
- Any missing, stale, suppressed, unsafe, unauthorized, or preflight-failing candidate returns `{match:null}` and normal Ask submission continues without a toast or intermediate screen.

- [ ] **Step 1: Add failing endpoint cases**

Cover:

- compatible Verified cross-user reuse;
- same-user Accurate AI-built reuse;
- other-user Accurate AI-built rejection;
- administrator-approved cross-user AI-built reuse;
- neutral and Inaccurate rejection;
- changed strict prompt-scoped schema-context fingerprint/scope rejection;
- SQL safety, table-policy, schema-validation, and preflight failure returning `match:null`.

Assert every accepted response includes:

```php
[
    'generationProvenance' => 'verified_pattern' /* or ai_built */,
    'reuseTrust' => 'verified_global' /* same_user_accurate/admin */,
    'sourceGenerationId' => 'server-owned UUID',
]
```

- [ ] **Step 2: Run endpoint tests and verify RED**

Run:

```bash
php backend/tests/FolioQueryControllerReuseCandidateEndpointTest.php
php backend/tests/FolioQueryControllerQueryMemoryReuseTest.php
```

Expected: FAIL because the endpoint currently selects any strong completed NL job.

- [ ] **Step 3: Load trusted server-side evidence**

In `actionQueryReuseCandidate()`, obtain the current user, current strict direct-reuse schema fingerprint, normalized authorized scope, recent candidate jobs, linked `ai_report_generations`, and feedback rows by generation/job/SQL hash. Pass shaped records to `QueryMemoryService::findDirectReuse()`.

Do not accept `userId`, provenance, feedback status, approval, either schema fingerprint, or scope fingerprint from the request body.

- [ ] **Step 4: Repeat all executable gates**

Before returning a match, execute the same validation pipeline as a newly generated candidate: safety, table policy, live schema/column validation, authorization, and database preflight. Catch only candidate-level validation failures and return `{match:null}` while logging hashes/reasons. Let authentication, provider infrastructure, and database connectivity retain their correct typed failures.

- [ ] **Step 5: Remove frontend failure noise**

In `Ask.tsx`, change reuse-check failure handling from a toast to normal generation continuation. Preserve the existing inline `AskReuseNotice` only after a reused query has executed.

- [ ] **Step 6: Create immutable reuse lineage at execution**

When `actionQuerySubmit()` receives `queryReuse.candidateJobId`, reload and revalidate the candidate and trust server-side; do not trust client provenance, source generation, or trust labels. In the same transaction that creates the new query job, use `AdministratorReviewService` to create a child generation whose `parent_generation_id` is the source generation, whose question is the new request, and whose provenance is unchanged for unedited SQL (`verified_pattern` stays Verified; `ai_built` stays AI-built). Edited reuse creates `ai_built` lineage.

Return the child `generationId` with the query-submit response and store it in the active `NlResponse`, so feedback and follow-ups reference the executed child rather than the source user's generation.

- [ ] **Step 7: Run endpoint and frontend reuse tests**

Run:

```bash
php backend/tests/FolioQueryControllerReuseCandidateEndpointTest.php
php backend/tests/FolioQueryControllerQueryMemoryReuseTest.php
npm --prefix frontend test -- --run src/pages/Ask.queryReuse.test.ts src/pages/Ask.requestLifecycle.test.tsx
```

Expected: all pass.

- [ ] **Step 8: Commit trusted direct reuse**

```bash
git add backend/controllers/FolioQueryController.php backend/services/AdministratorReviewService.php backend/tests/FolioQueryControllerReuseCandidateEndpointTest.php backend/tests/FolioQueryControllerQueryMemoryReuseTest.php frontend/src/pages/Ask.tsx frontend/src/pages/Ask.queryReuse.test.ts frontend/src/pages/Ask.requestLifecycle.test.tsx frontend/src/types/schema.ts
git commit -m "feat: enforce query reuse trust ladder"
```

---

### Task 4: Supply bounded compatible examples to fresh AI generation

**Files:**
- Modify: `backend/services/GeminiService.php`
- Modify: `backend/services/AskGenerationEvidenceService.php`
- Create: `backend/tests/GeminiServiceQueryMemoryExamplesTest.php`
- Modify: `backend/tests/AskGenerationEvidenceServiceTest.php`

**Interfaces:**
- AI generation receives zero to three server-selected examples in a separate delimited context section.
- Example SQL never enters canonical compilation and never executes directly through this path.
- Evidence records selected example IDs/hashes/tier, not raw example SQL.

- [ ] **Step 1: Write failing prompt-context tests**

Assert that compatible examples appear under a server-owned delimiter such as:

```text
<trusted_query_examples>
Example 1 provenance=verified_pattern feedback=verified
Question: ...
SQL: ...
</trusted_query_examples>
```

Assert the actual user question remains in its existing user-input section. Test that an example question containing `</trusted_query_examples>` is escaped/JSON-encoded and cannot close the section. Assert no example context is emitted when all candidates are stale, suppressed, or Inaccurate.

- [ ] **Step 2: Run Gemini example tests and verify RED**

Run: `php backend/tests/GeminiServiceQueryMemoryExamplesTest.php`

Expected: FAIL because generation currently has no feedback-ranked examples.

- [ ] **Step 3: Load and append examples only in the AI lane**

Before `generateAiBuiltLane()` calls the provider, query `QueryMemoryService::selectAiExamples()` with current user, normalized question, data source, live global schema-version fingerprint, and authorized scope. Do not pass or compare the current prompt-context fingerprint in example selection. Serialize the bounded result separately from user input. Canonical compilation remains unaffected.

If example lookup fails, log `query_memory_examples_unavailable` and generate without examples; do not fail the report request.

- [ ] **Step 4: Preserve evidence without prompt leakage**

Add `queryMemoryExamples` evidence containing candidate ID, SQL hash, rank tier, global schema-version fingerprint, and scope fingerprint. Do not log/store the prompt-context fingerprint as an example-compatibility reason and do not log/store another copy of raw SQL through telemetry.

- [ ] **Step 5: Run focused tests and verify GREEN**

Run:

```bash
php backend/tests/GeminiServiceQueryMemoryExamplesTest.php
php backend/tests/AskGenerationEvidenceServiceTest.php
php backend/tests/GeminiServiceTwoLaneRoutingTest.php
```

- [ ] **Step 6: Commit AI example selection**

```bash
git add backend/services/GeminiService.php backend/services/AskGenerationEvidenceService.php backend/tests/GeminiServiceQueryMemoryExamplesTest.php backend/tests/AskGenerationEvidenceServiceTest.php
git commit -m "feat: guide AI with trusted query examples"
```

---

### Task 5: Persist feedback from trusted generation/job identifiers

**Files:**
- Modify: `backend/controllers/FolioQueryController.php`
- Modify: `backend/tests/QueryFeedbackSchemaTest.php`
- Create: `backend/tests/FolioQueryControllerQueryFeedbackTrustTest.php`
- Modify: `frontend/src/api/client.ts`
- Modify: `frontend/src/pages/Ask.tsx`
- Create: `frontend/src/pages/Ask.feedback.test.tsx`
- Modify: `frontend/src/types/schema.ts`

**Interfaces:**
- Feedback request sends `generationId`, `queryJobId`, accuracy, and optional note.
- Backend derives question, SQL, SQL hash, provenance, strict direct-reuse schema fingerprint, global schema-version fingerprint, scope fingerprint, user ownership, route, and data source from linked server records.
- Response returns `feedbackId`, `resultAccuracy`, and `reuseSuppressed`.

- [ ] **Step 1: Add failing ownership and derivation tests**

Assert:

- a user can rate only their own linked generation/job;
- client-authored `generatedSql`, provenance, route, user ID, either schema fingerprint, and scope fingerprint are ignored;
- Accurate AI-built becomes same-user eligible only after persistence;
- Inaccurate sets `reuse_suppressed=1` for every matching exact SQL hash in the same global schema-version fingerprint and authorized scope, regardless of prompt-context fingerprint, and clears any administrator approval on those rows;
- Unsure remains neutral;
- feedback for Verified SQL does not alter Verified provenance or create an AI trust tier.

- [ ] **Step 2: Run feedback tests and verify RED**

Run:

```bash
php backend/tests/FolioQueryControllerQueryFeedbackTrustTest.php
npm --prefix frontend test -- --run src/pages/Ask.feedback.test.tsx
```

Expected: FAIL because feedback currently trusts client-authored SQL/metadata and is not linked.

- [ ] **Step 3: Change the feedback contract**

Update `buildQueryFeedbackInput()` and `saveQueryFeedback()` to send:

```ts
{
  generationId: result.generationId,
  queryJobId: activeJobId,
  resultAccuracy,
  feedbackNote: feedbackNote.trim() || null,
}
```

Require a completed, user-owned job linked to the generation. Resolve all other persisted values server-side. Use a transaction for insert plus Inaccurate suppression.

- [ ] **Step 4: Preserve an explicit feedback response**

Return:

```json
{
  "feedbackId": 123,
  "resultAccuracy": "inaccurate",
  "reuseSuppressed": true,
  "message": "Feedback saved."
}
```

Store the response in `Ask.tsx` so later UI actions use only the feedback ID.

- [ ] **Step 5: Run feedback tests and verify GREEN**

Run the commands from Step 2 plus `php backend/tests/QueryMemoryServiceTest.php`.

- [ ] **Step 6: Commit trusted feedback persistence**

```bash
git add backend/controllers/FolioQueryController.php backend/tests/QueryFeedbackSchemaTest.php backend/tests/FolioQueryControllerQueryFeedbackTrustTest.php frontend/src/api/client.ts frontend/src/pages/Ask.tsx frontend/src/pages/Ask.feedback.test.tsx frontend/src/types/schema.ts
git commit -m "feat: bind feedback to executed generations"
```

---

### Task 6: Add Try different SQL as a fresh-generation path

**Files:**
- Modify: `backend/config/web.php`
- Modify: `backend/controllers/FolioQueryController.php`
- Create: `backend/tests/FolioQueryControllerQueryReplacementTest.php`
- Modify: `frontend/src/api/client.ts`
- Modify: `frontend/src/pages/Ask.tsx`
- Modify: `frontend/src/pages/Ask.feedback.test.tsx`
- Modify: `frontend/src/types/schema.ts`

**Interfaces:**
- Produces `POST /api/query-feedback/<id>/replacement`.
- Request body contains current authorized scope only; the feedback ID identifies the rejected question/SQL/note.
- Response uses the ordinary `NlResponse` contract with `ai_built` provenance and parent-generation linkage.

- [ ] **Step 1: Add failing replacement tests**

Cover owner-only access, inaccurate-only eligibility, suppressed SQL exclusion, and fresh AI generation. Assert the provider context contains the stored rejected SQL hash, stored feedback note, and instruction to produce a materially different query; assert it does not use clarification/correction endpoints or mutate the Ask input.

Assert the replacement SQL passes the full Phase 1 coordinator validation and receives `parentGenerationId` equal to the rejected generation.

- [ ] **Step 2: Run replacement tests and verify RED**

Run:

```bash
php backend/tests/FolioQueryControllerQueryReplacementTest.php
npm --prefix frontend test -- --run src/pages/Ask.feedback.test.tsx
```

- [ ] **Step 3: Implement the server-owned replacement endpoint**

Load the feedback row joined to generation/job, enforce current-user ownership and `result_accuracy='inaccurate'`, then call `GeminiService::generateFreshAiBuiltSql()` through the Phase 1 coordinator with a structured rejected-candidate context. Do not run reuse or canonical compilation for this explicit replacement action.

On success, update `replacement_generation_id` on the feedback row. On failure, preserve the suppression and return the coordinator's accurate typed failure.

- [ ] **Step 4: Add the inline results action**

After Inaccurate feedback succeeds, keep the current results visible and render **Try different SQL** beside the saved feedback message. Clicking it calls the replacement endpoint, shows ordinary generating progress, and replaces the result only when the new response arrives.

- [ ] **Step 5: Run replacement tests and verify GREEN**

Run the commands from Step 2 plus `php backend/tests/AskGenerationCoordinatorServiceTest.php`.

- [ ] **Step 6: Commit replacement generation**

```bash
git add backend/config/web.php backend/controllers/FolioQueryController.php backend/tests/FolioQueryControllerQueryReplacementTest.php frontend/src/api/client.ts frontend/src/pages/Ask.tsx frontend/src/pages/Ask.feedback.test.tsx frontend/src/types/schema.ts
git commit -m "feat: regenerate SQL from inaccurate feedback"
```

---

### Task 7: Record weak interactions without turning them into accuracy

**Files:**
- Modify: `backend/config/web.php`
- Modify: `backend/controllers/FolioQueryController.php`
- Create: `backend/tests/FolioQueryControllerQueryMemorySignalTest.php`
- Modify: `frontend/src/api/client.ts`
- Modify: `frontend/src/pages/Ask.tsx`
- Modify: `frontend/src/pages/Ask.queryReuse.test.ts`
- Modify: `frontend/src/pages/Ask.requestLifecycle.test.tsx`

**Interfaces:**
- Produces `POST /api/query/memory-signal` with `generationId`, `queryJobId`, and signal enum.
- Allowed signals are `saved`, `downloaded`, `rerun`, and `follow_up`.
- Counters change ranking only and never create direct-reuse eligibility.

- [ ] **Step 1: Add failing signal tests**

Assert owner-only updates, enum validation, atomic counter increments, and no changes to `result_accuracy`, `generation_provenance`, `reuse_suppressed`, or administrator approval. Assert duplicate browser retries may increment counters but cannot change trust.

- [ ] **Step 2: Run signal tests and verify RED**

Run: `php backend/tests/FolioQueryControllerQueryMemorySignalTest.php`

- [ ] **Step 3: Implement the signal endpoint**

Resolve the owned linked generation and atomically increment exactly one allowlisted column. Log generation/job IDs, signal, and new count; never log SQL or rows.

- [ ] **Step 4: Emit signals from existing actions**

Call the endpoint after successful save, full-CSV download, reused-query execution, and follow-up submission. Signal failures are telemetry-only and do not interrupt the user action.

- [ ] **Step 5: Prove weak signals cannot promote reuse**

Add a `QueryMemoryServiceTest` case with high weak counters and no explicit feedback. Assert it may rank as a low-tier AI example but `findDirectReuse()` returns null.

- [ ] **Step 6: Run signal and reuse tests**

Run:

```bash
php backend/tests/FolioQueryControllerQueryMemorySignalTest.php
php backend/tests/QueryMemoryServiceTest.php
npm --prefix frontend test -- --run src/pages/Ask.queryReuse.test.ts src/pages/Ask.requestLifecycle.test.tsx
```

- [ ] **Step 7: Commit weak interaction ranking**

```bash
git add backend/config/web.php backend/controllers/FolioQueryController.php backend/tests/FolioQueryControllerQueryMemorySignalTest.php backend/tests/QueryMemoryServiceTest.php frontend/src/api/client.ts frontend/src/pages/Ask.tsx frontend/src/pages/Ask.queryReuse.test.ts frontend/src/pages/Ask.requestLifecycle.test.tsx
git commit -m "feat: rank query examples by weak interactions"
```

---

### Task 8: Add administrator reuse approval without provenance promotion

**Files:**
- Modify: `backend/config/web.php`
- Modify: `backend/controllers/FolioQueryController.php`
- Modify: `backend/services/AdministratorReviewService.php`
- Modify: `backend/services/QueryMemoryService.php`
- Modify: `backend/tests/AdministratorReviewServiceTest.php`
- Modify: `backend/tests/FolioQueryControllerReportReviewTest.php`
- Modify: `frontend/src/api/client.ts`
- Modify: `frontend/src/pages/ReportReviews.tsx`
- Modify: `frontend/src/pages/ReportReviews.test.tsx`
- Modify: `frontend/src/types/schema.ts`

**Interfaces:**
- Produces `GET /api/admin/query-memory` for Accurate, suppressed, and approved AI-built candidates independent of the flagged-report queue.
- Produces `PATCH /api/admin/query-feedback/<id>/reuse-approval` with `{approved:boolean}`.
- Produces `PATCH /api/admin/query-feedback/<id>/suppression` with `{suppressed:false}`; suppression cannot be set to true through the administrator endpoint because Inaccurate feedback owns that transition.
- Only an administrator can approve, revoke, or clear suppression.
- Approval applies only to an Accurate, nonsuppressed, schema-compatible AI-built feedback record.
- Approval never changes `generation_provenance`.

- [ ] **Step 1: Add failing administrator policy tests**

Assert non-admin 403, list filtering/pagination, missing feedback 404, Inaccurate/Unsure/Verified approval 409, stale strict/global schema approval 409, successful approval storing reviewer/time, and revocation clearing both fields. After approval, assert cross-user direct reuse becomes eligible while provenance remains `ai_built`. Add an explicit audited administrator action to clear fingerprint suppression after review; clearing suppression alone does not approve or enable reuse.

- [ ] **Step 2: Run administrator tests and verify RED**

Run:

```bash
php backend/tests/AdministratorReviewServiceTest.php
php backend/tests/FolioQueryControllerReportReviewTest.php
npm --prefix frontend test -- --run src/pages/ReportReviews.test.tsx
```

- [ ] **Step 3: Implement approval in the existing review service**

Add list and atomic update methods. Approval locks the feedback/generation row, rechecks eligibility plus both the current strict prompt-scoped and global schema-version fingerprints, and writes `admin_reuse_approved_at`/`admin_reuse_approved_by`. Revoke is always allowed for an existing row. Clearing suppression requires a separate explicit boolean action, clears every matching global-schema-version/scope/SQL fingerprint under the lock, and leaves approval empty until a later eligible approval. Record an administrator audit log with IDs and SQL hash only.

- [ ] **Step 4: Add a Query memory view to the administrator workspace**

Add a Query memory tab/section to `ReportReviews.tsx` backed by `GET /api/admin/query-memory`; do not depend on an Accurate result having a flagged `ai_report_reviews` row. Show question, immutable provenance, accuracy, suppression, schema/scope compatibility, SQL hash, and approval state. Add **Approve for cross-user reuse**, **Revoke reuse approval**, and a separate **Clear suppression after review** action with explicit confirmation. Never relabel the SQL Verified.

- [ ] **Step 5: Run administrator tests and verify GREEN**

Run the commands from Step 2 plus `php backend/tests/QueryMemoryServiceTest.php`.

- [ ] **Step 6: Commit administrator approval**

```bash
git add backend/config/web.php backend/controllers/FolioQueryController.php backend/services/AdministratorReviewService.php backend/services/QueryMemoryService.php backend/tests/AdministratorReviewServiceTest.php backend/tests/FolioQueryControllerReportReviewTest.php frontend/src/api/client.ts frontend/src/pages/ReportReviews.tsx frontend/src/pages/ReportReviews.test.tsx frontend/src/types/schema.ts
git commit -m "feat: approve AI query reuse administratively"
```

---

### Task 9: Show truthful reuse trust on the results page

**Files:**
- Modify: `frontend/src/components/AskReuseNotice.tsx`
- Create: `frontend/src/components/AskReuseNotice.test.tsx`
- Modify: `frontend/src/pages/Ask.tsx`
- Modify: `frontend/src/pages/Ask.queryReuse.test.ts`
- Modify: `frontend/src/pages/Ask.requestLifecycle.test.tsx`
- Modify: `frontend/src/types/schema.ts`

**Interfaces:**
- Reused results identify both unchanged provenance and trust source.
- Existing **Edit SQL** and **Ask AI for new SQL** actions remain.

- [ ] **Step 1: Add failing notice-copy tests**

Require these mappings:

```ts
const trustCopy = {
  verified_global: 'Reused a compatible Verified pattern.',
  same_user_accurate: 'Reused AI-built SQL you previously marked Accurate.',
  administrator_approved: 'Reused administrator-approved AI-built SQL.',
};
```

Assert an administrator-approved result still displays `AI-built`, never `Verified pattern`.

- [ ] **Step 2: Run notice tests and verify RED**

Run:

```bash
npm --prefix frontend test -- --run src/components/AskReuseNotice.test.tsx
npm --prefix frontend test -- --run src/pages/Ask.queryReuse.test.ts src/pages/Ask.requestLifecycle.test.tsx
```

- [ ] **Step 3: Implement compact trust copy**

Pass `generationProvenance`, `provenanceLabel`, and `reuseTrust` into `AskReuseNotice`. Keep the notice inside the normal results view; do not add a confirmation or intermediate reuse page.

**Ask AI for new SQL** bypasses `/query/reuse-candidate` for that action and calls fresh AI generation directly. **Edit SQL** preserves current edited/AI-built provenance behavior.

- [ ] **Step 4: Run notice tests and verify GREEN**

Run the commands from Step 2.

- [ ] **Step 5: Commit truthful reuse presentation**

```bash
git add frontend/src/components/AskReuseNotice.tsx frontend/src/components/AskReuseNotice.test.tsx frontend/src/pages/Ask.tsx frontend/src/pages/Ask.queryReuse.test.ts frontend/src/pages/Ask.requestLifecycle.test.tsx frontend/src/types/schema.ts
git commit -m "feat: explain reused query trust"
```

---

### Task 10: Add telemetry and full Phase 2 acceptance coverage

**Files:**
- Create: `backend/tests/QueryMemoryTelemetryTest.php`
- Create: `backend/tests/QueryMemoryAcceptanceTest.php`
- Modify only if acceptance exposes a regression.

**Interfaces:**
- Telemetry covers selection, suppression, staleness, validation/preflight rejection, example ranking, feedback, weak signals, and administrator approval.
- Telemetry contains fingerprints and IDs but no raw prompts, SQL, result rows, or feedback notes.

- [ ] **Step 1: Add the trust-ladder acceptance test**

Exercise one compatible question through:

1. neutral AI-built completion: not directly reused;
2. same user marks Accurate: same-user direct reuse;
3. different user asks: example only, fresh AI result;
4. administrator approves: different-user direct reuse;
5. user marks exact SQL Inaccurate: immediate suppression for both reuse and examples;
6. prompt-context fingerprint changes while global schema version stays fixed: direct reuse misses but the record remains eligible as an AI example;
7. global schema version changes: the record is stale for both reuse and AI examples and normal generation continues.

Assert provenance remains `ai_built` at every AI-originated stage.

- [ ] **Step 2: Add telemetry redaction assertions**

Require structured events for `reuse_selected`, `reuse_suppressed`, `reuse_stale`, `reuse_candidate_rejected`, `example_selected`, `feedback_recorded`, `weak_signal_recorded`, and `reuse_approval_changed`. Assert direct-reuse events identify the strict context fingerprint, example events identify the global schema-version fingerprint, and only IDs, hashes, tiers, normalized reasons, and counts are present.

- [ ] **Step 3: Run acceptance and telemetry tests**

Run:

```bash
php backend/tests/QueryMemoryAcceptanceTest.php
php backend/tests/QueryMemoryTelemetryTest.php
```

Expected: both pass.

- [ ] **Step 4: Run every backend test and shell report**

```bash
set -e
for test_file in backend/tests/*.php; do php "$test_file"; done
bash backend/tests/ShadowMetricsSlotProvenanceReportTest.sh
bash backend/tests/ShadowMetricsProviderFallbackReportTest.sh
```

Expected: every backend test and shell report passes.

- [ ] **Step 5: Run every frontend test and production build**

```bash
npm --prefix frontend test
npm --prefix frontend run build
```

Expected: all frontend tests pass and Vite completes the production build.

- [ ] **Step 6: Validate migration and public contracts**

```bash
php -l backend/services/QueryMemoryService.php
php -l backend/controllers/FolioQueryController.php
php -l backend/services/GeminiService.php
git diff --check
rg -n "unsafe_generated_sql|generationProvenance.*verified_pattern|reuseTrust|reuse_suppressed|direct_reuse_schema_fingerprint|schema_version_fingerprint" backend frontend/src mysql
```

Expected: legacy unsafe handling is frontend-only; provenance assignments are explicit; every direct reuse has a trust source; strict prompt-context matching is confined to direct reuse; global schema-version matching gates examples; suppression checks precede reuse/example selection.

- [ ] **Step 7: Commit acceptance coverage**

```bash
git add backend/tests/QueryMemoryAcceptanceTest.php backend/tests/QueryMemoryTelemetryTest.php
git commit -m "test: verify feedback-ranked query memory"
```

---

## Rollout Order

1. Deploy Phase 1 coordinator/safety changes and verify the vendor receipt-time prompt no longer surfaces candidate safety failures.
2. Apply migration 044 before deploying Phase 2 application code.
3. Deploy Phase 2 with legacy feedback neutral and direct AI reuse disabled unless explicit trust exists.
4. Confirm telemetry shows ordinary reuse misses falling through to generation, not terminal responses.
5. Enable administrator approvals only after same-user Accurate and Inaccurate suppression acceptance tests pass in production-like Docker.

## Definition of Done

- Ordinary read-only requests reach canonical SQL, fresh AI SQL, or an accurate generation/infrastructure failure without clarification/correction/reuse blockers.
- Verified, AI-built, edited, reused, replacement, and administrator-approved provenance remains truthful.
- Accurate AI-built SQL directly reuses only for the same user and compatible scope unless an administrator approves it.
- Inaccurate feedback immediately suppresses the exact SQL from reuse and AI examples.
- Neutral and weak-positive records never become directly reusable.
- A stale strict prompt-context fingerprint silently bypasses direct reuse while a matching global schema version still permits AI examples.
- A stale global schema-version fingerprint excludes the record from both reuse and example selection.
- Users can request different SQL from the results without rewriting the question.
- Reused results show provenance, trust source, Edit SQL, and New AI SQL in the normal results view.
- Existing authentication, authorization, protected-data, read-only transaction, timeout, cancellation, and resource protections remain intact.
