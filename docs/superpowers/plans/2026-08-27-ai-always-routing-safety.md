# AI-Always Routing and Candidate Safety Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every ordinary read-only canonical or AI-candidate failure continue automatically until Report Explorer has safe executable SQL or an accurate generation/infrastructure failure.

**Architecture:** Add a single Ask generation coordinator around the existing Gemini canonical/AI generator and controller preflight. A conservative deterministic request-policy service blocks only explicit write intent; a token-aware validator rejects executable writes while ignoring literals, comments, and quoted aliases; unsafe generated candidates become repair/regeneration inputs rather than terminal responses.

**Tech Stack:** PHP 7+/Yii2 backend, PostgreSQL report SQL, existing `SqlSelectStructureService` tokenizer, Vitest/React frontend, standalone PHP regression tests.

**Spec:** `docs/superpowers/specs/2026-08-27-verified-first-ai-always-query-memory-design.md`

## Global Constraints

- Canonical compilation is an optimization; every non-policy canonical failure continues to AI.
- Model classification cannot block a request. Ambiguous write intent continues in enforced read-only mode.
- The backend must never emit `unsafe_generated_sql`; legacy frontend recognition remains temporarily for rolling deployments.
- `sql_generation_failed` copy is exactly `Report Explorer could not build a valid report after retrying. Please retry.`
- Explicit write intent returns `request_blocked` with `Report Explorer runs read-only reports and cannot modify database data.`
- Protected-data and authorization policy remain separate hard gates with their existing policy responses.
- Every replacement candidate repeats safety, table-policy, schema, and database-preflight checks.
- Provenance remains `verified_pattern` only for unchanged backend-compiled SQL; every AI-generated or AI-repaired candidate is `ai_built`.
- Do not stage or modify the user's unrelated cache, SDD report, SQL dump, or WOLFCON document files.

---

### Task 1: Conservative request-level write-intent policy

**Files:**
- Create: `backend/services/AskRequestPolicyService.php`
- Create: `backend/tests/AskRequestPolicyServiceTest.php`

**Interfaces:**
- Produces: `AskRequestPolicyService::classify(string $question): array{state:string,reason:string}`.
- State is exactly `read_only` or `request_blocked`.
- `request_blocked` is returned only for high-confidence imperative database mutation language.

- [ ] **Step 1: Write the failing policy test**

Create a table-driven standalone test containing these required cases:

```php
$cases = [
    ['Delete every row from query_jobs.', 'request_blocked'],
    ['Please update the inventory item records.', 'request_blocked'],
    ['Can you insert a new vendor into the database?', 'request_blocked'],
    ['Drop the users table.', 'request_blocked'],
    ['Update me on circulation trends.', 'read_only'],
    ['Count records updated last month.', 'read_only'],
    ['Show deleted inventory records.', 'read_only'],
    ['Include the purchase-order create date.', 'read_only'],
    ['Summarize update activity by month.', 'read_only'],
    ['Create a report of receipts by vendor.', 'read_only'],
    ['Create a database table for vendors.', 'request_blocked'],
];

foreach ($cases as [$question, $expected]) {
    $actual = AskRequestPolicyService::classify($question);
    assertSameValue($expected, $actual['state'] ?? null, $question);
}
```

- [ ] **Step 2: Run the policy test and verify RED**

Run: `php backend/tests/AskRequestPolicyServiceTest.php`

Expected: FAIL because `AskRequestPolicyService.php` does not exist.

- [ ] **Step 3: Implement the high-precision deterministic classifier**

Use explicit reporting-prefix exclusions followed by imperative mutation patterns. Do not call Gemini or any model:

```php
final class AskRequestPolicyService
{
    public const READ_ONLY = 'read_only';
    public const REQUEST_BLOCKED = 'request_blocked';

    public static function classify(string $question): array
    {
        $normalized = strtolower(trim((string)preg_replace('/\s+/', ' ', $question)));
        if ($normalized === '' || preg_match(
            '/^(?:show|list|find|count|summarize|compare|report|include|what|which|how|update me on)\b/',
            $normalized
        ) === 1) {
            return ['state' => self::READ_ONLY, 'reason' => 'reporting_or_uncertain'];
        }

        $prefix = '^(?:(?:please|kindly)\s+|can you\s+|could you\s+|would you\s+)?';
        $imperative = '/' . $prefix . '(?:'
            . 'insert\b.{0,80}\binto\b'
            . '|update\b.{0,80}\b(?:set|rows?|records?|tables?|database)\b'
            . '|delete\b.{0,40}\b(?:from|every|all|these|rows?|records?)\b'
            . '|(?:drop|alter|truncate|create)\b.{0,80}\b(?:tables?|schemas?|indexes?|views?|database)\b'
            . '|(?:grant|revoke)\b.{0,80}\b(?:on|to|from)\b'
            . '|copy\b.{0,80}\b(?:from|to)\b'
            . '|call\b.{0,80}\b(?:functions?|procedures?)\b'
            . ')/i';

        return preg_match($imperative, $normalized) === 1
            ? ['state' => self::REQUEST_BLOCKED, 'reason' => 'explicit_write_intent']
            : ['state' => self::READ_ONLY, 'reason' => 'reporting_or_uncertain'];
    }
}
```

Keep the heuristic biased toward `read_only`; SQL enforcement remains authoritative. Do not broaden it during implementation to catch indirect wording such as `I need you to delete all rows`. That false negative is intentionally safer than blocking analytical language: any generated write still fails the token-aware SQL gate and read-only database boundary.

- [ ] **Step 4: Run the policy test and verify GREEN**

Run: `php backend/tests/AskRequestPolicyServiceTest.php`

Expected: `AskRequestPolicyService test passed`.

- [ ] **Step 5: Commit the request policy**

```bash
git add backend/services/AskRequestPolicyService.php backend/tests/AskRequestPolicyServiceTest.php
git commit -m "feat: classify explicit Ask write intent"
```

---

### Task 2: Token-aware single-read-only-statement validation

**Files:**
- Modify: `backend/services/SqlSelectStructureService.php`
- Modify: `backend/services/SqlBuilderService.php`
- Modify: `backend/tests/SqlBuilderServicePolicyViolationTest.php`

**Interfaces:**
- Produces: `SqlSelectStructureService::assertSingleReadOnlyStatement(string $sql): void`.
- `SqlBuilderService::validateSafety()` delegates structural command detection to that method.
- Existing `validateTablePolicy()` behavior is unchanged.

- [ ] **Step 1: Add failing false-positive and write-CTE regressions**

Extend `SqlBuilderServicePolicyViolationTest.php` with:

```php
foreach ([
    "SELECT 'update' AS note",
    'SELECT 1 AS "Create"',
    "SELECT 1 /* DELETE FROM inventory.item__t */ AS value",
] as $safeSql) {
    SqlBuilderService::validateSafety($safeSql);
}

foreach ([
    'WITH changed AS (DELETE FROM inventory.item__t RETURNING id) SELECT id FROM changed',
    'SELECT 1; DELETE FROM inventory.item__t',
    'UPDATE inventory.item__t SET barcode = NULL',
    'EXECUTE prepared_report',
] as $unsafeSql) {
    assertThrowsSafety($unsafeSql);
}
```

Add an `assertThrowsSafety()` helper that fails when no `InvalidArgumentException` is thrown.

- [ ] **Step 2: Run the safety test and verify RED**

Run: `php backend/tests/SqlBuilderServicePolicyViolationTest.php`

Expected: FAIL because literals and quoted aliases containing blocked words are rejected.

- [ ] **Step 3: Add token-aware read-only analysis**

Implement `assertSingleReadOnlyStatement()` using `tokenizeForAnalysis()`. Comments are already omitted; strings use `kind=string`; quoted identifiers use `quoted=true`.

```php
public static function assertSingleReadOnlyStatement(string $sql): void
{
    $tokens = self::tokenizeForAnalysis($sql);
    if ($tokens === []) {
        throw new \InvalidArgumentException('SQL cannot be empty.');
    }

    $statementTerminators = [];
    foreach ($tokens as $index => $token) {
        if (($token['value'] ?? null) === ';') $statementTerminators[] = $index;
    }
    if (count($statementTerminators) > 1
        || ($statementTerminators !== [] && $statementTerminators[0] !== count($tokens) - 1)
    ) {
        throw new \InvalidArgumentException('Only a single SELECT statement is allowed.');
    }

    $first = strtoupper((string)($tokens[0]['value'] ?? ''));
    if (!in_array($first, ['SELECT', 'WITH'], true)) {
        throw new \InvalidArgumentException('Only SELECT queries are allowed.');
    }

    $blocked = ['INSERT','UPDATE','DELETE','DROP','ALTER','TRUNCATE','CREATE',
        'GRANT','REVOKE','EXECUTE','COPY','MERGE','CALL','DO','VACUUM','ANALYZE'];
    foreach ($tokens as $token) {
        if (($token['kind'] ?? '') === 'string' || !empty($token['quoted'])) continue;
        $keyword = strtoupper((string)($token['value'] ?? ''));
        if (in_array($keyword, $blocked, true)) {
            throw new \InvalidArgumentException(
                "Query contains blocked keyword: {$keyword}. Only SELECT queries are allowed."
            );
        }
    }
}
```

Do not duplicate tokenizer logic in `SqlBuilderService`.

- [ ] **Step 4: Delegate the existing safety entry point**

Replace raw uppercase keyword scanning in `SqlBuilderService::validateSafety()` with:

```php
public static function validateSafety($sql)
{
    SqlSelectStructureService::assertSingleReadOnlyStatement((string)$sql);
}
```

- [ ] **Step 5: Run safety and structure tests**

Run:

```bash
php backend/tests/SqlBuilderServicePolicyViolationTest.php
php backend/tests/SqlSelectStructureServiceTest.php
```

Expected: all pass.

- [ ] **Step 6: Commit token-aware safety**

```bash
git add backend/services/SqlSelectStructureService.php backend/services/SqlBuilderService.php backend/tests/SqlBuilderServicePolicyViolationTest.php
git commit -m "fix: validate executable SQL tokens"
```

---

### Task 3: Make unsafe AI candidates repairable

**Files:**
- Modify: `backend/services/GeminiService.php`
- Modify: `backend/services/ExploratorySqlRepairService.php`
- Modify: `backend/tests/GeminiServiceExploratoryRepairTest.php`
- Modify: `backend/tests/ExploratorySqlRepairServiceTest.php`

**Interfaces:**
- Candidate safety failures use `ExploratorySqlValidationException(stage='safety', safeCategory='non_select', repairable=true)` inside AI generation.
- Request-level write intent never reaches this service.
- Policy violations and infrastructure exceptions remain non-repairable.

- [ ] **Step 1: Replace the old destructive hard-stop expectations with a failing regeneration test**

In `GeminiServiceExploratoryRepairTest.php`, configure the test transport with an unsafe first response and a safe second response:

```php
TestTransport::$responses = [
    "```sql\nDELETE FROM inventory.item__t\n```",
    "```sql\nSELECT COUNT(*) AS item_count FROM inventory.item__t\n```",
];

$result = GeminiService::generateSql(/* existing test arguments */);
repairAssertSame(
    'SELECT COUNT(*) AS item_count FROM inventory.item__t',
    $result['sql'] ?? null,
    'An unsafe AI candidate for a reporting request must consume regeneration, not the request.'
);
repairAssertSame(2, count(TestTransport::$requests), 'The unsafe candidate should trigger one replacement request.');
```

Retain separate tests proving `PolicyViolationException`, provider failures, and cancellation are hard stops.

- [ ] **Step 2: Run both repair tests and verify RED**

Run:

```bash
php backend/tests/GeminiServiceExploratoryRepairTest.php
php backend/tests/ExploratorySqlRepairServiceTest.php
```

Expected: FAIL because safety/non-select exceptions are currently non-repairable.

- [ ] **Step 3: Classify generated non-SELECT candidates as repairable**

In `GeminiService::extractSqlResponseParts()` and the parse/attempt exception adapter, use:

```php
throw new ExploratorySqlValidationException(
    'safety',
    'non_select',
    trim($candidateText),
    true,
    'The generated candidate was not a single read-only SELECT statement.'
);
```

Do not include raw candidate SQL in ordinary log messages. Keep it in trusted `_askEvidence` only.

- [ ] **Step 4: Preserve the bounded repair plus fresh-generation budget**

Update `ExploratorySqlRepairService::assertRepairable()` only if required by the existing exception construction; do not make `PolicyViolationException` repairable. Confirm `GeminiService::generateAiBuiltLane()` still provides one fresh generation after repair exhaustion.

- [ ] **Step 5: Run both repair tests and verify GREEN**

Run the commands from Step 2.

Expected: both tests pass; the unsafe first candidate causes another AI request.

- [ ] **Step 6: Commit candidate regeneration**

```bash
git add backend/services/GeminiService.php backend/services/ExploratorySqlRepairService.php backend/tests/GeminiServiceExploratoryRepairTest.php backend/tests/ExploratorySqlRepairServiceTest.php
git commit -m "fix: regenerate unsafe AI candidates"
```

---

### Task 4: Add the single Ask generation coordinator

**Files:**
- Create: `backend/services/AskGenerationCoordinatorService.php`
- Create: `backend/tests/AskGenerationCoordinatorServiceTest.php`
- Modify: `backend/config/params.php`
- Modify: `backend/services/Nl2sqlRuntimePreflightService.php`
- Modify: `backend/tests/Nl2sqlRuntimePreflightServiceTest.php`

**Interfaces:**
- Produces: `AskGenerationCoordinatorService::run(string $question, callable $initialAttempt, callable $freshAiAttempt): array`.
- Attempt callbacks return one of the five typed outcome arrays; the coordinator alone unwraps `handled.result` into a public response.
- Internal result states are `handled`, `not_handled`, `candidate_rejected`, `infrastructure_failure`, and `request_blocked`.
- The returned public response never contains the internal `state` field.

- [ ] **Step 1: Write a failing coordinator transition matrix**

Cover:

```php
$blocked = AskGenerationCoordinatorService::run(
    'Delete every inventory item.',
    function (): array { throw new RuntimeException('must not run'); },
    function (): array { throw new RuntimeException('must not run'); }
);
assertSameValue('request_blocked', $blocked['errorType'] ?? null, 'Explicit writes stop before generation.');

$calls = [];
$recovered = AskGenerationCoordinatorService::run(
    'Summarize receipts by vendor.',
    function () use (&$calls): array {
        $calls[] = 'initial';
        return [
            'state' => 'candidate_rejected',
            'reason' => 'non_select',
            'candidateSqlHash' => hash('sha256', 'DELETE ...'),
        ];
    },
    function () use (&$calls): array {
        $calls[] = 'fresh';
        return [
            'state' => 'handled',
            'result' => [
                'sql' => 'SELECT vendor, COUNT(*) FROM orders.pieces__t GROUP BY vendor',
                'generationProvenance' => 'ai_built',
            ],
        ];
    }
);
assertSameValue(['initial', 'fresh'], $calls, 'Candidate rejection must trigger fresh AI.');
assertSameValue('ai_built', $recovered['generationProvenance'] ?? null, 'Fresh SQL is AI-built.');
```

Also test exhaustion copy and propagation of provider, connectivity, policy, cancellation, and resource failures.

- [ ] **Step 2: Run the coordinator test and verify RED**

Run: `php backend/tests/AskGenerationCoordinatorServiceTest.php`

Expected: FAIL because the coordinator does not exist.

- [ ] **Step 3: Implement the coordinator state machine**

Use one private constructor per typed outcome and reject unknown states. The coordinator first calls `AskRequestPolicyService::classify()`. For read-only requests it invokes the initial attempt. `handled` unwraps its result, `not_handled` and `candidate_rejected` invoke fresh AI, `request_blocked` maps to its policy response, and `infrastructure_failure` preserves its accurate typed response. A repairable validation exception is adapted to `candidate_rejected`; provider, connectivity, authorization, timeout, cancellation, and resource exceptions are adapted to `infrastructure_failure`.

If the bounded replacement budget is exhausted, return the existing generation-exhaustion response:

```php
private static function generationFailed(int $attempts): array
{
    return [
        'errorType' => 'sql_generation_failed',
        'message' => 'Report Explorer could not build a valid report after retrying. Please retry.',
        'route' => 'generation_failed',
        'routeReason' => 'sql_repair_exhausted',
        'validationSummary' => ['status' => 'exhausted', 'repairAttempts' => min(2, $attempts)],
    ];
}
```

`request_blocked` must be:

```php
[
    'errorType' => 'request_blocked',
    'message' => 'Report Explorer runs read-only reports and cannot modify database data.',
    'route' => 'request_blocked',
    'routeReason' => 'explicit_write_intent',
]
```

Emit structured `nl2sql.coordinator_transition` telemetry with prompt fingerprint, from-state, to-state, reason, and candidate SQL hash only.

- [ ] **Step 4: Add the rollout parameter**

In `backend/config/params.php` add `nl2sqlCoordinatorEnabled`, sourced from `nl2sql_coordinator_enabled` / `NL2SQL_COORDINATOR_ENABLED`, default `true`. The disabled path retains the previous orchestrator for rollback. Expose the effective value and readiness note through `Nl2sqlRuntimePreflightService`; the flag changes routing only and never rewrites stored provenance.

- [ ] **Step 5: Run the coordinator and configuration tests**

Run:

```bash
php backend/tests/AskGenerationCoordinatorServiceTest.php
php backend/tests/Nl2sqlRuntimePreflightServiceTest.php
```

Expected: both pass.

- [ ] **Step 6: Commit the coordinator**

```bash
git add backend/services/AskGenerationCoordinatorService.php backend/tests/AskGenerationCoordinatorServiceTest.php backend/config/params.php backend/services/Nl2sqlRuntimePreflightService.php backend/tests/Nl2sqlRuntimePreflightServiceTest.php
git commit -m "feat: coordinate Ask generation outcomes"
```

---

### Task 5: Integrate the coordinator and retire backend unsafe responses

**Files:**
- Modify: `backend/controllers/FolioQueryController.php`
- Modify: `backend/services/GeminiService.php`
- Modify: `backend/services/AskGenerationEvidenceService.php`
- Modify: `backend/tests/FolioQueryControllerExploratoryRepairTest.php`
- Modify: `backend/tests/AskGenerationEvidenceServiceTest.php`

**Interfaces:**
- `actionNl()` invokes `AskGenerationCoordinatorService::run()` when `nl2sqlCoordinatorEnabled=true`.
- The initial callback calls current `GeminiService::generateSqlWithShadow()` followed by `validateAndRepairNlResult()`.
- The fresh callback calls a new public `GeminiService::generateFreshAiBuiltSql(...)` followed by the same validation method.
- No backend path returns `unsafe_generated_sql`.

- [ ] **Step 1: Add failing controller tests for the new public contract**

Replace candidate-level expectations at the current unsafe cases with:

```php
repairAssertSame(
    'ai_built',
    $unsafeThenSafe['generationProvenance'] ?? null,
    'Unsafe first candidates must regenerate and return AI-built SQL.'
);
repairAssertSame(false, isset($unsafeThenSafe['errorType']), 'Recovered reports are normal successes.');

repairAssertSame(
    'request_blocked',
    $explicitWrite['errorType'] ?? null,
    'Explicit write intent owns the request-level block.'
);
```

Add a source assertion that `buildUnsafeGeneratedSqlResponse` and `isAskUnsafeGeneratedSqlFailure` are absent from backend source.

- [ ] **Step 2: Run controller/evidence tests and verify RED**

Run:

```bash
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php backend/tests/AskGenerationEvidenceServiceTest.php
```

Expected: FAIL on the existing `unsafe_generated_sql` response.

- [ ] **Step 3: Expose fresh AI generation without canonical retry**

Add to `GeminiService`:

```php
public static function generateFreshAiBuiltSql(
    string $rawQuestion,
    string $generationPrompt,
    ?string $campus,
    array $resolvedFilters = [],
    string $reason = 'candidate_rejected'
): array {
    return self::generateAiBuiltLane(
        $rawQuestion,
        $generationPrompt,
        $campus,
        $reason,
        $resolvedFilters
    );
}
```

This method must always assign AI-built provenance.

- [ ] **Step 4: Route enabled Ask generation through the coordinator**

Move the generate-plus-validate boundary inside coordinator callbacks. Add an adapter that maps any legacy canonical `needsClarification`, request-preserved, unsupported-family, unresolved-reference, semantic rejection, compiler exception, or canonical-preflight result to internal `not_handled`; it must not return that blocker to the enabled public path. Pass its resolver/reference diagnostics into the fresh AI context. Change `validateAndRepairNlResult()` so candidate safety and ordinary candidate-preflight failures return/throw `candidate_rejected` instead of a public response. Keep policy, provider, connectivity, cancellation, authorization, and resource handling accurate.

Delete `buildUnsafeGeneratedSqlResponse()`, `isAskUnsafeGeneratedSqlFailure()`, and every backend emission/mapping of `unsafe_generated_sql`, including the disabled rollback branch. The rollback orchestrator may return `sql_generation_failed` after its existing bounded attempts, but it may not revive the retired response type. Temporary compatibility exists only in the frontend for responses received from an older backend during a rolling deployment.

- [ ] **Step 5: Update trusted evidence classification**

Teach `AskGenerationEvidenceService` that `request_blocked` is a rejected non-executable request boundary and that `candidate_rejected` is internal evidence only. Preserve initial/final SQL hashes and regeneration count without publishing rejected SQL.

- [ ] **Step 6: Run focused backend tests**

Run:

```bash
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php backend/tests/AskGenerationEvidenceServiceTest.php
php backend/tests/GeminiServiceTwoLaneRoutingTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
php backend/tests/AskAiCrossDomainRoiRegressionTest.php
```

Expected: all pass.

- [ ] **Step 7: Commit controller integration**

```bash
git add backend/controllers/FolioQueryController.php backend/services/GeminiService.php backend/services/AskGenerationEvidenceService.php backend/tests/FolioQueryControllerExploratoryRepairTest.php backend/tests/AskGenerationEvidenceServiceTest.php
git commit -m "feat: make AI the Ask continuation"
```

---

### Task 6: Migrate terminal response types and copy

**Files:**
- Modify: `backend/services/AskResponseContractService.php`
- Modify: `backend/tests/AskResponseContractServiceTest.php`
- Modify: `frontend/src/pages/Ask.tsx`
- Modify: `frontend/src/pages/Ask.errorFormatting.test.ts`
- Modify: `frontend/src/types/schema.ts`

**Interfaces:**
- New backend responses use `request_blocked` or `sql_generation_failed`, never `unsafe_generated_sql`.
- Frontend recognizes legacy `unsafe_generated_sql` only for mixed-version compatibility.

- [ ] **Step 1: Add failing response/copy tests**

Assert:

```ts
expect(getAskTerminalFailureMessage({ errorType: 'request_blocked' }))
  .toBe('Report Explorer runs read-only reports and cannot modify database data.');
expect(getAskTerminalFailureMessage({ errorType: 'sql_generation_failed' }))
  .toBe('Report Explorer could not build a valid report after retrying. Please retry.');
expect(getAskTerminalFailureMessage({ errorType: 'unsafe_generated_sql' }))
  .toBe('Report Explorer could not safely run this report. Please retry.'); // legacy only
```

Backend contract tests must assert no normalizer converts `request_blocked` into generation failure.

- [ ] **Step 2: Run response tests and verify RED**

Run:

```bash
php backend/tests/AskResponseContractServiceTest.php
npm --prefix frontend test -- --run src/pages/Ask.errorFormatting.test.ts
```

Expected: FAIL because `request_blocked` is not yet a typed terminal response.

- [ ] **Step 3: Implement the response migration**

Add `request_blocked` to `isTypedAskTerminalFailure()`, return the exact read-only message, and leave the legacy unsafe branch annotated for rolling compatibility. Remove any default fallback that invents safety wording for unrelated errors; default to the server's message/error or the generation-exhaustion copy.

- [ ] **Step 4: Run response tests and verify GREEN**

Run the commands from Step 2.

- [ ] **Step 5: Commit response migration**

```bash
git add backend/services/AskResponseContractService.php backend/tests/AskResponseContractServiceTest.php frontend/src/pages/Ask.tsx frontend/src/pages/Ask.errorFormatting.test.ts frontend/src/types/schema.ts
git commit -m "fix: separate write blocks from generation failures"
```

---

### Task 7: Add the vendor receipt-time acceptance regression and telemetry checks

**Files:**
- Create: `backend/tests/AskAiAlwaysVendorReceiptRegressionTest.php`
- Create: `backend/tests/AskGenerationCoordinatorTelemetryTest.php`

**Interfaces:**
- Exact prompt under test is the approved vendor receipt-time question.
- First AI candidate is unsafe; second is a safe PostgreSQL SELECT.
- Final response is normal AI-built success.

- [ ] **Step 1: Write the exact end-to-end regression**

Use the repository's controller/Gemini test harness and this exact prompt:

```php
$question = 'For the last three completed fiscal years, summarize the time from purchase-order creation to receipt by vendor. Include vendor, fiscal year, received line count, average days to receipt, median days to receipt, and percentage received within 90 days. Include only vendors with at least 20 received lines.';
```

Configure the first model response as `DELETE FROM orders.pieces__t` and the replacement as a fenced `WITH ... SELECT` containing vendor, fiscal year, `COUNT(*)`, `AVG`, `PERCENTILE_CONT(0.5)`, a 90-day percentage expression, `HAVING COUNT(*) >= 20`, and no write command. Stub preflight as successful.

Assert:

```php
assertSameValue('ai_built', $result['generationProvenance'] ?? null, 'Vendor receipt report must be AI-built.');
assertSameValue(false, isset($result['errorType']), 'A rejected first candidate must not become a terminal response.');
assertSameValue(2, $transportRequestCount, 'One unsafe candidate must cost one fresh generation.');
assertContainsText('SELECT', $result['sql'] ?? '', 'The final report must contain executable read-only SQL.');
```

- [ ] **Step 2: Run the exact regression**

Run: `php backend/tests/AskAiAlwaysVendorReceiptRegressionTest.php`

Expected: PASS after Tasks 1–6.

- [ ] **Step 3: Assert safe coordinator telemetry**

Capture Yii log messages and require transition events for `candidate_rejected -> handled`, normalized reason `non_select`, prompt fingerprint, and SQL hash. Assert raw prompt, raw rejected SQL, and result rows are absent.

- [ ] **Step 4: Run telemetry and regression tests**

Run:

```bash
php backend/tests/AskAiAlwaysVendorReceiptRegressionTest.php
php backend/tests/AskGenerationCoordinatorTelemetryTest.php
```

Expected: both pass.

- [ ] **Step 5: Commit acceptance coverage**

```bash
git add backend/tests/AskAiAlwaysVendorReceiptRegressionTest.php backend/tests/AskGenerationCoordinatorTelemetryTest.php
git commit -m "test: cover AI candidate safety regeneration"
```

---

### Task 8: Full Phase 1 verification and rollout audit

**Files:**
- Modify only if verification exposes a regression.

**Interfaces:**
- Phase 1 is independently deployable with query-memory behavior unchanged.

- [ ] **Step 1: Run syntax and artifact validation**

```bash
php -l backend/services/AskRequestPolicyService.php
php -l backend/services/AskGenerationCoordinatorService.php
php -l backend/services/SqlSelectStructureService.php
php -l backend/services/SqlBuilderService.php
php -l backend/services/GeminiService.php
php -l backend/controllers/FolioQueryController.php
git diff --check
```

Expected: no syntax or whitespace errors.

- [ ] **Step 2: Run all backend test files**

```bash
set -e
for test_file in backend/tests/*.php; do php "$test_file"; done
bash backend/tests/ShadowMetricsSlotProvenanceReportTest.sh
bash backend/tests/ShadowMetricsProviderFallbackReportTest.sh
```

Expected: every backend and shell test passes.

- [ ] **Step 3: Run all frontend tests and production build**

```bash
npm --prefix frontend test
npm --prefix frontend run build
```

Expected: all frontend tests pass and Vite completes the production build.

- [ ] **Step 4: Audit public response reachability**

```bash
rg -n "unsafe_generated_sql|needsClarification|legacy_recovery" backend/controllers backend/services frontend/src/pages/Ask.tsx
```

Expected: `unsafe_generated_sql` is absent from backend controllers/services and appears only in explicitly marked frontend rolling-deployment compatibility code and its tests.

- [ ] **Step 5: Audit ordinary blocker reachability**

```bash
rg -n "needsClarification|request_preserved|unresolved_named_term|legacy_recovery" backend/controllers/FolioQueryController.php backend/services/GeminiService.php
```

Expected: every occurrence in the enabled coordinator path is either converted to AI context/`not_handled` or is an explicitly marked disabled rollback path. Protected-data, authorization, and configured resource failures remain separate typed policy/infrastructure responses.

- [ ] **Step 6: Record the deliberate rollback contract change**

In the implementation report, state that retiring backend `unsafe_generated_sql` also changes the disabled rollback orchestrator's exhausted terminal response to `sql_generation_failed`. List the rollback tests whose old unsafe-shape assertions were replaced so this is not misreported as incidental assertion weakening.

## Definition of Done

- The exact vendor receipt-time prompt survives an unsafe first candidate and executes the safe replacement as AI-built.
- Every non-policy canonical failure, including canonical preflight, continues to AI.
- Explicit write intent alone uses `request_blocked`; ambiguous mutation vocabulary remains read-only.
- Generation exhaustion uses the generation-failure copy and never calls the request unsafe.
- The enabled backend never emits `unsafe_generated_sql`, clarification, correction, or request-preserved responses for ordinary read-only requests.
- Every executed replacement is a single safety-, policy-, schema-, authorization-, and preflight-validated read-only statement.
