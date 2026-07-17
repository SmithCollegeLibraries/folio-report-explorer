# Ask AI Exploratory SQL Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make unsupported Ask AI requests automatically generate, validate, and repair exploratory SQL up to two times while displaying documented assumptions and returning guided continuation instead of a generic dead end.

**Architecture:** Keep deterministic report families unchanged. Add a documented-defaults resolver and bounded repair coordinator, integrate them into `GeminiService`, and give the controller a testable preflight-and-repair boundary so database validation consumes the same repair budget. Extend the synchronous API and React UI with assumptions, repair metadata, and recovery actions.

**Tech Stack:** PHP 8/Yii2, PostgreSQL preflight through `SqlPreflightService`, Gemini/OpenAI through `GeminiService`, React 18, TypeScript, TanStack Query, Vitest.

## Global Constraints

- Make one initial exploratory generation attempt and at most two repair attempts total, including database-preflight repairs.
- Never retry PII policy, blocked-table policy, non-SELECT/destructive SQL, connectivity, provider timeout/quota, cancellation, or user cancellation.
- Never execute a candidate until normalization, safety, table policy, semantic guards, and database preflight pass.
- Use documented defaults when the user is silent; explicit user language overrides a keyed default.
- Return sanitized failure categories to the browser and retain detailed exceptions only in server telemetry.
- Keep the endpoint synchronous: show generation-and-validation progress during the request and actual repair count after completion.
- Do not modify canonical query-family contracts or schema artifacts being changed by the other agent.

---

### Task 1: Resolve documented exploratory assumptions

**Files:**
- Create: `backend/data/exploratory_query_defaults.json`
- Create: `backend/services/ExploratoryQueryDefaultsService.php`
- Create: `backend/tests/ExploratoryQueryDefaultsServiceTest.php`

**Interfaces:**
- Produces: `ExploratoryQueryDefaultsService::resolve(string $prompt): array<int,array{key:string,label:string,value:string,explanation:string,correctionExample:string,source:string}>`
- Produces: `ExploratoryQueryDefaultsService::buildPromptGuidance(array $assumptions): string`

- [ ] **Step 1: Write the failing defaults test**

Use the motivating prompt and assert stable keys and values:

```php
$prompt = 'Show me which call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.';
$assumptions = ExploratoryQueryDefaultsService::resolve($prompt);
$byKey = array_column($assumptions, null, 'key');

assertSameValue(
    ['call_number_grouping', 'circulation_window', 'investment_cost_basis', 'purchase_date_basis', 'roi_formula'],
    array_keys($byKey),
    'Cross-domain ROI prompts should receive every documented default in stable order.'
);
assertSameValue('payment_date', $byKey['purchase_date_basis']['value'], 'Payment date should be the default.');
assertSameValue('checkouts_per_dollar_with_cost_per_use', $byKey['roi_formula']['value'], 'ROI should include both usage-per-dollar and cost-per-use.');
```

Add cases proving `Use invoice date instead of payment date` produces `purchase_date_basis=invoice_date` with `source=explicit`, `Use cost per checkout as ROI` replaces the ROI default, and a circulation-only prompt receives no cross-domain ROI assumptions.

- [ ] **Step 2: Run RED**

Run: `php backend/tests/ExploratoryQueryDefaultsServiceTest.php`

Expected: FAIL because the service does not exist.

- [ ] **Step 3: Add the versioned catalog**

Create artifact version 1 with these five entries:

```json
{
  "artifactVersion": 1,
  "defaults": [
    {"key":"purchase_date_basis","label":"Purchase date","defaultValue":"payment_date","defaultExplanation":"Purchases are assigned to the date the invoice was paid.","correctionExample":"Use invoice date instead of payment date."},
    {"key":"investment_cost_basis","label":"Investment","defaultValue":"actual_paid_fund_distribution","defaultExplanation":"Investment uses paid invoice fund-distribution amounts rather than estimated PO-line prices.","correctionExample":"Use estimated PO-line price as the investment amount."},
    {"key":"circulation_window","label":"Circulation period","defaultValue":"same_as_purchase_window","defaultExplanation":"Circulation is counted in the same reporting period used for purchases.","correctionExample":"Use lifetime circulation instead of the purchase window."},
    {"key":"roi_formula","label":"Return on investment","defaultValue":"checkouts_per_dollar_with_cost_per_use","defaultExplanation":"ROI is checkouts per dollar, with cost per checkout returned as a companion measure.","correctionExample":"Use cost per checkout as ROI."},
    {"key":"call_number_grouping","label":"Call-number grouping","defaultValue":"primary_call_number_class","defaultExplanation":"Call numbers are grouped into their primary classification for comparison.","correctionExample":"Group by the first two call-number letters instead."}
  ],
  "roiPlanGuidance": [
    "Join paid invoice fund distributions to PO lines using po_line_id.",
    "Join orders.po_line__t.instance_id to inventory instances, holdings, and items.",
    "Aggregate spend before joining item-level circulation so one-to-many rows do not multiply cost.",
    "Aggregate circulation at item grain before grouping by primary call-number class.",
    "Return purchase count, spend, circulation, checkouts per dollar, and cost per checkout, guarding division by zero."
  ]
}
```

- [ ] **Step 4: Implement the resolver**

Load and validate artifact version 1. Detect the combined concepts `purchase/acquisition`, `circulation/checkout`, `call number`, and `ROI/return on investment`. Build one associative entry per key, replace matching defaults with explicit interpretations, `ksort()` by key, and return values. `buildPromptGuidance()` emits `DOCUMENTED INTERPRETATIONS` and the ROI plan guidance, or an empty string when no assumptions apply.

- [ ] **Step 5: Run GREEN and commit**

Run: `php backend/tests/ExploratoryQueryDefaultsServiceTest.php`

Expected: `ExploratoryQueryDefaultsService test passed`.

```bash
git add backend/data/exploratory_query_defaults.json backend/services/ExploratoryQueryDefaultsService.php backend/tests/ExploratoryQueryDefaultsServiceTest.php
git commit -m "feat: resolve documented exploratory query defaults"
```

---

### Task 2: Enforce the shared repair budget

**Files:**
- Create: `backend/exceptions/ExploratorySqlValidationException.php`
- Create: `backend/services/ExploratorySqlRepairService.php`
- Create: `backend/tests/ExploratorySqlRepairServiceTest.php`

**Interfaces:**
- Produces: structured exception getters `getStage()`, `getSafeCategory()`, `getCandidateSql()`, `isRepairable()`.
- Produces: `ExploratorySqlRepairService::run(callable $attempt, array $context, int $repairAttemptsUsed = 0, ?ExploratorySqlValidationException $initialFailure = null): array`.
- Returns `status=validated` with `result` and `repairAttempts`, or `status=exhausted` with safe failure metadata.

- [ ] **Step 1: Write failing coordinator tests**

```php
$calls = 0;
$outcome = ExploratorySqlRepairService::run(
    function (array $context) use (&$calls): array {
        $calls++;
        if ($calls === 1) {
            throw new ExploratorySqlValidationException('schema_reference', 'unknown_column', 'SELECT bad_column FROM inventory.item__t', true, 'column does not exist');
        }
        return ['sql' => 'SELECT id FROM inventory.item__t'];
    },
    ['originalQuestion' => 'Show items', 'attemptedPlan' => 'Read inventory items.']
);
assertSameValue('validated', $outcome['status'], 'A repairable first failure should be repaired.');
assertSameValue(1, $outcome['repairAttempts'], 'One repair should be recorded.');
assertSameValue(2, $calls, 'Only initial generation and one repair should run.');
```

Also assert three repairable failures exhaust after exactly three total calls, `PolicyViolationException` is rethrown after one call, nonrepairable validation exceptions are rethrown, and an initial preflight failure with `repairAttemptsUsed=1` permits only one remaining repair.

- [ ] **Step 2: Run RED**

Run: `php backend/tests/ExploratorySqlRepairServiceTest.php`

Expected: FAIL because the repair types do not exist.

- [ ] **Step 3: Implement exception and coordinator**

The exception constructor is:

```php
public function __construct(
    string $stage,
    string $safeCategory,
    string $candidateSql,
    bool $repairable,
    string $internalMessage,
    ?\Throwable $previous = null
)
```

The coordinator uses `MAX_REPAIR_ATTEMPTS = 2`. Each attempt receives original question, campus, assumptions, attempted plan, repair number, previous candidate, validator stage, and safe category. Never pass the raw exception message. Rethrow policy and nonrepairable exceptions. Exhaustion suggestions are retry, correct an assumption, and narrow period/output.

- [ ] **Step 4: Run GREEN and commit**

Run: `php backend/tests/ExploratorySqlRepairServiceTest.php`

Expected: `ExploratorySqlRepairService test passed`.

```bash
git add backend/exceptions/ExploratorySqlValidationException.php backend/services/ExploratorySqlRepairService.php backend/tests/ExploratorySqlRepairServiceTest.php
git commit -m "feat: add bounded exploratory SQL repair coordinator"
```

---

### Task 3: Integrate repair prompts and validation into GeminiService

**Files:**
- Modify: `backend/services/GeminiService.php:1-300,800-1035,1725-1760,5120-5180,5776-5825`
- Create: `backend/tests/GeminiServiceExploratoryRepairTest.php`
- Modify: `backend/tests/GeminiServiceExploratoryGateTest.php`

**Interfaces:**
- Consumes Task 1 and Task 2 services.
- Produces: `GeminiService::repairExploratorySqlAfterPreflight(string $prompt, $campus, array $currentResult, string $preflightError): array`.
- Adds response fields `assumptions`, `repairAttempts`, `validationSummary`, and exhausted `recoveryContext`.

- [ ] **Step 1: Write failing bad-then-valid tests**

Use a fake HTTP client: response one references `inventory.missing_table__t`; response two uses `SELECT ii.id FROM inventory.item__t ii`. Assert repaired SQL, `repairAttempts=1`, `validationSummary.status=validated`, five assumptions, and a repair request containing previous SQL, `unknown_table`, `DOCUMENTED INTERPRETATIONS`, and scoped schema context. Add exhaustion, policy-no-retry, and telemetry-no-raw-SQL cases. Unknown-column repair is covered at the PostgreSQL preflight boundary in Task 4 because the existing static schema validator validates physical table names, not every selected expression.

- [ ] **Step 2: Run RED**

Run: `php backend/tests/GeminiServiceExploratoryRepairTest.php`

Expected: FAIL because GeminiService does not coordinate repairs.

- [ ] **Step 3: Make parsing failures structured**

Require the new service and exception files. Split SQL extraction from validation inside `parseResponse()`. Preserve `PolicyViolationException` and non-SELECT/destructive safety exceptions unchanged. Wrap formatting, alias/semantic guard, unknown physical table, and database-repairable failures in `ExploratorySqlValidationException` with stable safe categories.

Make `validateTableReferences()` strict and CTE-aware: collect CTE aliases from the leading `WITH` clause, ignore those aliases, and throw `unknown_table` for remaining unknown physical tables instead of only logging.

- [ ] **Step 4: Add a repair request builder**

Implement `generateExploratoryRepairCandidate(array $context)`. Its system prompt says:

```text
You are repairing one PostgreSQL SELECT query for a FOLIO reporting request.
Return exactly one corrected SELECT statement, a concise explanation, and DATA SOURCE.
Preserve requested outputs, campus scope, and documented interpretations.
Correct the reported validation failure without weakening filters or omitting requested domains.
Use only supplied schema tables and columns. Never access blocked data or produce non-SELECT SQL.
```

The user content labels original question, previous candidate, validator stage, safe category, assumptions, attempted plan, and fresh scoped schema context. It excludes raw internal error text.

- [ ] **Step 5: Route exploratory generation through repair**

Both unsupported-family and `allowExploratory=true` paths resolve defaults and call `ExploratorySqlRepairService::run()`. Initial generation uses existing legacy generation plus default guidance. Repair calls use the repair builder.

Successful decoration is:

```php
$result['assumptions'] = $assumptions;
$result['repairAttempts'] = $outcome['repairAttempts'];
$result['validationSummary'] = [
    'status' => 'validated',
    'repairAttempts' => $outcome['repairAttempts'],
    'message' => $outcome['repairAttempts'] > 0
        ? 'SQL passed validation after ' . $outcome['repairAttempts'] . ' automatic repair attempt(s).'
        : 'The initial SQL candidate passed validation.',
];
```

Exhaustion returns no SQL, `mode=exploratory`, `route=exploratory_recovery`, safe failure summary, attempted plan, suggestions, assumptions, and `recoveryContext.originalQuestion`.

- [ ] **Step 6: Add preflight repair and telemetry**

`repairExploratorySqlAfterPreflight()` constructs a repairable typed failure from current SQL and used repair count. Sanitize errors to `unknown_column`, `unknown_table`, `ambiguous_column`, `invalid_operator`, `grouping_error`, `syntax_error`, `query_too_complex`, or `database_validation`. Rethrow connectivity failures.

Emit attempt/outcome telemetry containing fingerprint, phase, repair number, maximum repairs, stage, category, candidate length, provider, elapsed time, assumption keys, and outcome. Do not include prompt or SQL.

- [ ] **Step 7: Run GREEN and commit**

```bash
php backend/tests/GeminiServiceExploratoryRepairTest.php
php backend/tests/GeminiServiceExploratoryGateTest.php
php backend/tests/GeminiServiceSqlNormalizationTest.php
php backend/tests/GeminiServiceFamilyCompilerFallbackTest.php
```

Expected: all four tests pass.

```bash
git add backend/services/GeminiService.php backend/tests/GeminiServiceExploratoryRepairTest.php backend/tests/GeminiServiceExploratoryGateTest.php
git commit -m "feat: repair exploratory SQL with validation feedback"
```

---

### Task 4: Repair database-preflight failures and guide continuation

**Files:**
- Modify: `backend/controllers/FolioQueryController.php:335-460,1190-1370`
- Create: `backend/tests/FolioQueryControllerExploratoryRepairTest.php`
- Modify: `backend/tests/FolioQueryControllerAskContinuationPolicyTest.php`
- Modify: `backend/tests/FolioQueryControllerNlFollowUpTest.php`

**Interfaces:**
- Produces private testable boundary: `validateAndRepairNlResult(array $result, string $prompt, $campus, ?callable $preflight = null, ?callable $repair = null): array`.
- Adds sanitized `buildExploratoryRepairExhaustedResponse()`.
- Extends follow-up context with `previousAssumptions`.

- [ ] **Step 1: Write failing controller tests**

Via reflection, inject preflight and repair callables. Case one: preflight fails, repair returns SQL with `repairAttempts=1`, second preflight succeeds. Case two: result already has two repairs, repair is never called, returned response has no SQL, exhausted status, original question, and no `verified report pattern` copy. Case three: connectivity remains the VPN response.

- [ ] **Step 2: Run RED**

Run: `php backend/tests/FolioQueryControllerExploratoryRepairTest.php`

Expected: FAIL because the boundary does not exist.

- [ ] **Step 3: Extract preflight repair loop**

Normalize and preflight each candidate. If valid, return it. If budget remains, call `GeminiService::repairExploratorySqlAfterPreflight()` and restart validation; any repaired canonical candidate is relabeled exploratory because AI has changed compiler output. If exhausted, return structured recovery. Log every failed preflight before repair. Call this boundary after `generateSqlWithShadow()` and before suggestions.

- [ ] **Step 4: Replace generic continuation copy**

Return:

```php
[
    'needsClarification' => false,
    'needsExploratoryApproval' => false,
    'mode' => 'exploratory',
    'errorType' => 'sql_repair_exhausted',
    'message' => 'I could not validate a safe executable query after the automatic repair attempts. Your request and assumptions are preserved below so you can retry or adjust them.',
    'route' => 'exploratory_recovery',
    'routeReason' => 'sql_repair_exhausted',
    'validationSummary' => ['status' => 'exhausted', 'failureCategory' => $safeCategory, 'repairAttempts' => $repairAttempts],
    'recoveryContext' => ['originalQuestion' => $prompt, 'campus' => $campus],
]
```

Derive `$repairAttempts` from the current result rather than claiming two repairs occurred. Merge assumptions, attempted plan, and suggestions when present. Never include SQL. For a nonrepairable non-SELECT/destructive candidate, return `errorType=unsafe_generated_sql`, `repairAttempts=0`, and state that no unsafe SQL ran; do not route it into repair.

- [ ] **Step 5: Preserve assumptions in follow-ups**

Whitelist `previousAssumptions` fields in `normalizeFollowUpContext()`. Add a `Previous documented interpretations:` block in `buildFollowUpPrompt()`, followed by `The follow-up request overrides any previous documented interpretation that addresses the same concept.` This ensures corrections replace keyed interpretations.

- [ ] **Step 6: Update tests, run GREEN, and commit**

```bash
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php backend/tests/FolioQueryControllerAskContinuationPolicyTest.php
php backend/tests/FolioQueryControllerNlFollowUpTest.php
php backend/tests/FolioQueryControllerPolicyViolationStatusTest.php
```

Expected: all four tests pass; policy remains HTTP 403 and connectivity remains distinct.

```bash
git add backend/controllers/FolioQueryController.php backend/tests/FolioQueryControllerExploratoryRepairTest.php backend/tests/FolioQueryControllerAskContinuationPolicyTest.php backend/tests/FolioQueryControllerNlFollowUpTest.php
git commit -m "feat: repair Ask SQL after database preflight"
```

---

### Task 5: Display assumptions and guided recovery

**Files:**
- Modify: `frontend/src/types/schema.ts:210-275`
- Create: `frontend/src/components/ExploratoryAssumptionsPanel.tsx`
- Create: `frontend/src/components/ExploratoryAssumptionsPanel.test.tsx`
- Create: `frontend/src/components/ExploratoryRecoveryPanel.tsx`
- Create: `frontend/src/components/ExploratoryRecoveryPanel.test.tsx`
- Modify: `frontend/src/pages/Ask.tsx:45-75,192-235,465-480,850-900,1380-1430,1980-2025`
- Modify: `frontend/src/pages/Ask.errorFormatting.test.ts`
- Modify: `frontend/src/pages/Ask.followUp.test.ts`

**Interfaces:**
- Adds `ExploratoryAssumption`, `ValidationSummary`, `ExploratoryPlan`, and `RecoveryContext` types.
- Assumptions panel consumes assumptions, repair count, and `onCorrect(example)`.
- Recovery panel consumes `NlResponse`, `onRetry(question)`, and `onRefine(question,suggestion)`.

- [ ] **Step 1: Write failing frontend tests**

Render assumptions and assert labels, explanations, source badges, repair summary, and correction callback. Render exhausted recovery and assert plan, safe category, assumptions, retry/refine callbacks, and absence of generated-SQL/run controls. Extend follow-up helper expectations with previous assumptions.

- [ ] **Step 2: Run RED**

```bash
cd frontend
npm test -- src/components/ExploratoryAssumptionsPanel.test.tsx src/components/ExploratoryRecoveryPanel.test.tsx src/pages/Ask.followUp.test.ts
```

Expected: FAIL because components and fields do not exist.

- [ ] **Step 3: Add TypeScript contracts**

```ts
export interface ExploratoryAssumption {
  key: string;
  label: string;
  value: string;
  explanation: string;
  correctionExample: string;
  source: 'default' | 'explicit';
}
export interface ValidationSummary {
  status: 'validated' | 'exhausted';
  repairAttempts: number;
  validatorStage?: string;
  failureCategory?: string;
  message?: string;
}
export interface ExploratoryPlan { summary?: string; suggestions?: string[]; }
export interface RecoveryContext { originalQuestion: string; campus?: string | null; }
```

Add optional assumptions, repair attempts, validation summary, and recovery context to `NlResponse`; add previous assumptions to `FollowUpContext`.

- [ ] **Step 4: Implement components**

The assumptions panel title is `Assumptions used`; it shows `Initial SQL passed validation` or `Validated after N automatic repairs`, source badge, explanation, and accessible Correct button. The recovery panel title is `The request is preserved`; it shows sanitized category, attempted plan, assumptions, suggestions, Retry, and Refine. It must never render Generated SQL or Run controls.

- [ ] **Step 5: Wire Ask.tsx**

Change loading title to `Generating and validating your query` and mention automatic repair. Include assumptions in current-result follow-up context. Render assumptions under the exploratory notice for success. A correction starts a follow-up using current SQL.

For `validationSummary.status === 'exhausted'`, render recovery instead of result tabs. Retry runs `recoveryContext.originalQuestion` without stale SQL. Refine submits:

```ts
`${originalQuestion.trim()}\n\nCorrection: ${suggestion.trim()}`
```

- [ ] **Step 6: Run GREEN, build, and commit**

```bash
cd frontend
npm test -- src/components/ExploratoryAssumptionsPanel.test.tsx src/components/ExploratoryRecoveryPanel.test.tsx src/pages/Ask.errorFormatting.test.ts src/pages/Ask.followUp.test.ts
npm run build
```

Expected: selected tests pass and production build completes.

```bash
git add frontend/src/types/schema.ts frontend/src/components/ExploratoryAssumptionsPanel.tsx frontend/src/components/ExploratoryAssumptionsPanel.test.tsx frontend/src/components/ExploratoryRecoveryPanel.tsx frontend/src/components/ExploratoryRecoveryPanel.test.tsx frontend/src/pages/Ask.tsx frontend/src/pages/Ask.errorFormatting.test.ts frontend/src/pages/Ask.followUp.test.ts
git commit -m "feat: guide users through exploratory SQL repair"
```

---

### Task 6: Add motivating regression and verify the branch

**Files:**
- Create: `backend/tests/AskAiCrossDomainRoiRegressionTest.php`
- Modify only if a regression assertion exposes a defect: files from Tasks 1-5.

**Interfaces:**
- End-to-end contract: motivating prompt receives five defaults, no more than two repairs, and either validated SQL or structured recovery.

- [ ] **Step 1: Write the regression**

Use deterministic invalid-then-valid model responses. Assert repair prompt contains `po_line_id`, `orders.po_line__t.instance_id`, spend pre-aggregation, item-grain circulation, and cost per checkout. Assert success after one repair. Add exhaustion fixture asserting original question, five assumptions, plan, safe category, suggestions, and no SQL.

- [ ] **Step 2: Run regression**

Run: `php backend/tests/AskAiCrossDomainRoiRegressionTest.php`

Expected: PASS; if it exposes missing behavior, first observe the failing assertion, then make the minimum production correction and rerun.

- [ ] **Step 3: Run backend verification**

```bash
for test_file in backend/tests/ExploratoryQueryDefaultsServiceTest.php backend/tests/ExploratorySqlRepairServiceTest.php backend/tests/GeminiServiceExploratoryRepairTest.php backend/tests/GeminiServiceExploratoryGateTest.php backend/tests/GeminiServiceFamilyCompilerFallbackTest.php backend/tests/GeminiServiceFamilyCompilerResultTest.php backend/tests/GeminiServiceFamilyIntentBranchTest.php backend/tests/GeminiServiceFamilyMatchPolicyTest.php backend/tests/GeminiServiceFamilyMismatchTelemetryTest.php backend/tests/GeminiServiceFamilyShapeValidationTest.php backend/tests/GeminiServiceQueryFamilySelectionTest.php backend/tests/GeminiServiceSqlNormalizationTest.php backend/tests/FolioQueryControllerExploratoryRepairTest.php backend/tests/FolioQueryControllerAskContinuationPolicyTest.php backend/tests/FolioQueryControllerNlFollowUpTest.php backend/tests/FolioQueryControllerPolicyViolationStatusTest.php backend/tests/AskAiCrossDomainRoiRegressionTest.php; do php "$test_file" || exit 1; done
```

Expected: all pass. Existing PHP 8.5 `ReflectionMethod::setAccessible()` deprecation may remain; no new warnings.

- [ ] **Step 4: Run frontend and static verification**

```bash
cd frontend
npm test
npm run build
cd ..
php -l backend/services/ExploratoryQueryDefaultsService.php
php -l backend/services/ExploratorySqlRepairService.php
php -l backend/services/GeminiService.php
php -l backend/controllers/FolioQueryController.php
git diff --check
```

Expected: all tests/builds pass, PHP reports no syntax errors, and diff check is silent.

- [ ] **Step 5: Commit regression and request review**

```bash
git add backend/tests/AskAiCrossDomainRoiRegressionTest.php
git commit -m "test: cover cross-domain collection ROI repair"
```

Use `superpowers:requesting-code-review` against the complete branch diff. Address correctness or safety findings with a new failing test, then repeat Steps 3-4.
