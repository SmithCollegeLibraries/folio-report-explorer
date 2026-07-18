# Ask AI SELECT Safety Classification Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure safe exploratory SELECT queries reach database preflight and results while rejected queries show reassuring, actionable copy instead of an internal `non_select` label.

**Architecture:** Replace the controller's divergent SQL keyword regex with a wrapper around the existing `SqlBuilderService::validateSafety()` authority. Keep genuine unsafe SQL as a zero-repair hard stop, but hide its machine-oriented failure category in the rejected recovery UI and provide plain-language Retry/Refine guidance.

**Tech Stack:** PHP 8/Yii controller services and standalone regression scripts; React 18, TypeScript, Vitest, Testing Library.

## Global Constraints

- Do not edit schema caches, schema mappings, canonical query-family contracts, or Builder relationship artifacts.
- Do not log or return rejected SQL, database details, or exception messages.
- Do not change the maximum two-repair budget or make destructive SQL repairable.
- Safe reporting requests must proceed toward validated results rather than exposing internal classifier labels.

---

### Task 1: Use the shared SQL safety authority at the Ask controller boundary

**Files:**
- Modify: `backend/tests/FolioQueryControllerExploratoryRepairTest.php`
- Modify: `backend/controllers/FolioQueryController.php`

**Interfaces:**
- Consumes: `SqlBuilderService::validateSafety(string $sql): void`, which returns normally for allowed SELECT/WITH SQL and throws `InvalidArgumentException` for unsafe SQL.
- Produces: `FolioQueryController::isSafeSelectNlSql(string $sql): bool`, used only before exploratory database preflight.

- [ ] **Step 1: Extend the standalone service double and write the failing valid-SELECT regression**

Add the shared safety method to the test double:

```php
public static function validateSafety($sql): void
{
    $trimmed = ltrim((string)$sql);
    if (preg_match('/^(?:SELECT|WITH)\b/i', $trimmed) !== 1
        || preg_match('/^DELETE\b/i', $trimmed) === 1
    ) {
        throw new \InvalidArgumentException('Only SELECT queries are allowed.');
    }
}
```

Before the existing destructive-SQL case, invoke `validateAndRepairNlResult()` with:

```php
$safePreflightCalls = 0;
$safeWithDoValue = $validateAndRepair->invoke(
    $controller,
    [
        'sql' => "SELECT 'DO' AS action_word FROM inventory.instance__t",
        'mode' => 'exploratory',
        'route' => 'exploratory',
        'routeReason' => 'unsupported_query_family',
        'repairAttempts' => 0,
    ],
    'Show reporting rows with their action label',
    'Smith College',
    function () use (&$safePreflightCalls): array {
        $safePreflightCalls++;
        return ['rows' => 1, 'cost' => 1.0];
    },
    function (): array {
        fwrite(STDERR, "A valid SELECT must not enter repair.\n");
        exit(1);
    }
);
repairAssertSame(1, $safePreflightCalls, 'The shared safety validator should allow the SELECT to reach preflight.');
repairAssertSame(
    "SELECT 'DO' AS action_word FROM inventory.instance__t",
    $safeWithDoValue['sql'] ?? null,
    'A valid SELECT containing a harmless standalone value should return results instead of unsafe recovery.'
);
```

- [ ] **Step 2: Run the focused backend test and verify RED**

Run:

```bash
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
```

Expected: FAIL because the private controller regex treats the standalone `DO` token as destructive and never calls preflight.

- [ ] **Step 3: Replace the divergent controller regex with the shared validator**

Change the preflight guard to call `isSafeSelectNlSql()` and replace the old method with:

```php
private function isSafeSelectNlSql(string $sql): bool
{
    try {
        SqlBuilderService::validateSafety($sql);
        return true;
    } catch (\InvalidArgumentException $exception) {
        return false;
    }
}
```

Update the rejected response copy to:

```php
$response['message'] = "I couldn't safely turn this request into a report. Nothing ran or changed. Retry the request or refine one part of it.";
```

Retain `errorType = unsafe_generated_sql`, `status = rejected`, zero repairs, SQL removal, and the internal safe category for machine handling.

- [ ] **Step 4: Run focused backend and policy regressions and verify GREEN**

Run:

```bash
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php backend/tests/FolioQueryControllerPolicyViolationStatusTest.php
php backend/tests/SqlBuilderServicePolicyViolationTest.php
```

Expected: all three scripts exit 0. The existing DELETE case still stops before preflight and makes zero repairs.

- [ ] **Step 5: Commit the backend fix**

```bash
git add backend/controllers/FolioQueryController.php backend/tests/FolioQueryControllerExploratoryRepairTest.php
git commit -m "fix: share Ask SQL safety validation"
```

---

### Task 2: Replace rejected-state classifier copy with actionable reassurance

**Files:**
- Modify: `frontend/src/components/ExploratoryRecoveryPanel.test.tsx`
- Modify: `frontend/src/components/ExploratoryRecoveryPanel.tsx`

**Interfaces:**
- Consumes: `NlResponse.validationSummary.status`, including `rejected` and `exhausted`.
- Produces: rejected recovery presentation with reassurance and Retry/Refine actions; exhausted presentation continues to show its safe failure category.

- [ ] **Step 1: Strengthen the rejected-state component test**

Replace the rejected-state assertions with:

```tsx
expect(screen.getByText(/nothing ran or changed/i)).toBeInTheDocument();
expect(screen.getByText(/could not safely turn this request into a report/i)).toBeInTheDocument();
expect(screen.queryByText(/safe failure category/i)).not.toBeInTheDocument();
expect(screen.queryByText(/^Non select$/i)).not.toBeInTheDocument();
expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
expect(screen.queryByText(/Generated SQL/i)).not.toBeInTheDocument();
expect(screen.queryByRole('button', { name: /Run/i })).not.toBeInTheDocument();
```

Keep the exhausted-state test asserting that `Unknown table` remains visible.

- [ ] **Step 2: Run the component test and verify RED**

Run:

```bash
cd frontend && npm test -- ExploratoryRecoveryPanel.test.tsx
```

Expected: FAIL because rejected responses still render “unsafe query” and “Safe failure category / Non select.”

- [ ] **Step 3: Implement rejected-specific copy and category suppression**

Use this rejected copy:

```tsx
{isRejected
  ? "Nothing ran or changed. Ask AI could not safely turn this request into a report. Retry the request or refine one part of it below."
  : 'No query survived validation. Retry the preserved request or refine one part of it below.'}
```

Render the failure-category card only when `!isRejected`:

```tsx
{!isRejected && (
  <div className="rounded-md border border-amber-200 bg-white p-3">
    <dt className="text-xs font-semibold uppercase tracking-wide text-amber-800">Safe failure category</dt>
    <dd className="mt-1 text-gray-800">{formatFailureCategory(response.validationSummary?.failureCategory)}</dd>
  </div>
)}
```

- [ ] **Step 4: Run focused frontend tests and verify GREEN**

Run:

```bash
cd frontend && npm test -- ExploratoryRecoveryPanel.test.tsx Ask.errorFormatting.test.ts Ask.followUp.test.ts
```

Expected: all selected test files pass.

- [ ] **Step 5: Commit the UX fix**

```bash
git add frontend/src/components/ExploratoryRecoveryPanel.tsx frontend/src/components/ExploratoryRecoveryPanel.test.tsx
git commit -m "fix: make Ask safety recovery actionable"
```

---

### Task 3: Verify the integrated fix and isolation constraints

**Files:**
- Verify only; no planned production changes.

**Interfaces:**
- Consumes: backend shared safety decision and frontend rejected-state contract from Tasks 1–2.
- Produces: evidence that Ask AI, canonical reporting, and canonical LDLite Builder behavior remain compatible.

- [ ] **Step 1: Run the Ask AI and Builder backend matrix**

Run:

```bash
for test_file in backend/tests/ExploratoryQueryDefaultsServiceTest.php backend/tests/ExploratorySqlRepairServiceTest.php backend/tests/GeminiServiceExploratoryRepairTest.php backend/tests/GeminiServiceExploratoryGateTest.php backend/tests/GeminiServiceFamilyCompilerFallbackTest.php backend/tests/GeminiServiceFamilyCompilerResultTest.php backend/tests/GeminiServiceFamilyIntentBranchTest.php backend/tests/GeminiServiceFamilyMatchPolicyTest.php backend/tests/GeminiServiceFamilyMismatchTelemetryTest.php backend/tests/GeminiServiceFamilyShapeValidationTest.php backend/tests/GeminiServiceQueryFamilySelectionTest.php backend/tests/GeminiServiceSqlNormalizationTest.php backend/tests/FolioQueryControllerExploratoryRepairTest.php backend/tests/FolioQueryControllerAskContinuationPolicyTest.php backend/tests/FolioQueryControllerNlFollowUpTest.php backend/tests/FolioQueryControllerPolicyViolationStatusTest.php backend/tests/AskAiCrossDomainRoiRegressionTest.php backend/tests/SqlPreflightServiceTest.php backend/tests/FolioQueryControllerExecutePreflightTest.php; do php "$test_file" || exit 1; done
php backend/tests/FolioQueryControllerBuilderIdentityTest.php
php backend/tests/FolioQueryControllerCanonicalSaveTest.php
php backend/tests/SqlBuilderServiceLdliteRelationshipTest.php
php backend/tests/SqlBuilderServicePolicyViolationTest.php
```

Expected: every script exits 0; only the two known PHP 8.5 Reflection deprecations may appear.

- [ ] **Step 2: Run the complete frontend suite and production build**

Run:

```bash
npm test
npm run build
```

Run both commands with working directory `frontend/`.

Expected: all tests pass and the build exits 0; the existing Vite large-chunk advisory is allowed.

- [ ] **Step 3: Run lint, diff, and artifact-isolation checks**

Run PHP lint on changed PHP files, then:

```bash
git diff --check main..HEAD
git diff --name-only main..HEAD -- backend/data backend/services/QueryFamilyContractService.php
```

Expected: lint and diff check exit 0. The artifact-isolation command has no output.

- [ ] **Step 4: Review the complete branch diff**

Review `main..HEAD` for duplicated safety logic, raw SQL/error disclosure, weakened hard stops, or unrelated edits. Resolve every Critical or Important finding and rerun affected tests.

- [ ] **Step 5: Record final verification**

Append exact test counts, build result, advisories, and artifact-isolation evidence to the implementation handoff. Do not merge until the whole-branch review is clean.
