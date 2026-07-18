# Final Whole-Branch Review Fix Report

## Scope

Addressed every finding from the final whole-branch review without changing canonical query-family contracts or schema artifacts:

- removed raw PostgreSQL, SQLSTATE, schema/table, driver, and exception detail from preflight/parser telemetry and permission/policy browser responses;
- limited PostgreSQL preflight repair to actual exploratory or unsupported-route results;
- preserved SQLSTATE `57014`, statement cancellation, and database timeout as a typed database cancellation hard stop through backend and frontend handling;
- added `validationSummary.status = rejected` to the frontend contract and routed rejected results through the no-SQL recovery UI;
- added safe exploratory attempt and terminal telemetry with route, route reason, and distinct validated, exhausted, policy-blocked, connectivity, provider, and cancelled outcomes.

The shared maximum of two repair calls, full validation restart, legacy canonical recovery behavior, and existing canonical/schema artifacts remain unchanged.

## RED evidence

Focused tests were written or strengthened before each production correction.

### Privacy and repair eligibility

```text
FolioQueryControllerExploratoryRepairTest.php
Verified-family preflight failures must retain legacy recovery without Gemini repair.
Expected: 0
Actual: 2

FolioQueryControllerPolicyViolationStatusTest.php
Policy responses must not echo internal exception detail.
Expected: false
Actual: true

FolioQueryControllerExecutePreflightTest.php
Preflight telemetry must not include raw PostgreSQL error text.
Expected: false
Actual: true

GeminiServiceExploratoryRepairTest.php
Parser telemetry must contain only a safe failure category.
Expected: false
Actual: true
```

The execute-policy regression separately failed with the raw value:

```text
Actual: SQLSTATE[42501]: permission denied for table users.users__t; PDO driver detail
```

### Database cancellation

`FolioQueryControllerExploratoryRepairTest.php` first required a typed database cancellation and zero repair calls. `SqlPreflightServiceTest.php` then proved the production preflight service still swallowed statement cancellation:

```text
Preflight statement cancellation must remain a typed hard stop.
```

The frontend regression showed that the typed backend response was still mislabeled:

```text
Expected: Database validation was cancelled before the query could run. Please retry the request.
Received: AI error: Database validation was cancelled before the query could run. Please retry the request.
```

### Rejected UI and telemetry taxonomy

The recovery component initially lacked rejected-specific hard-stop copy, Ask lacked a rejected/exhausted gate helper, and exploratory attempt telemetry lacked `route`/`routeReason`. The focused tests failed on those exact missing fields/behaviors before implementation.

## GREEN implementation

- Added `DatabaseQueryCancelledException` and made `SqlPreflightService` throw it for SQLSTATE `57014`, statement timeout, and cancellation messages.
- Handled the typed cancellation before AI timeout classification and returned stable `database_cancelled` browser copy.
- Added an explicit exploratory-repair eligibility check based on exploratory mode/flag/route or `unsupported_query_family`; verified-family failures now use the pre-feature continuation path and never invoke Gemini repair.
- Removed raw preflight error text from structured telemetry and replaced parser exception fields with safe failure categories.
- Replaced policy/permission response detail with fixed reporting-policy guidance in Ask, execute, and submit flows.
- Added safe attempt route metadata and `nl2sql.exploratory_terminal_outcome` events with normalized outcome/category fields only.
- Extended the TypeScript contract with `rejected` and routed both `exhausted` and `rejected` through `ExploratoryRecoveryPanel`, excluding generated SQL tabs and run controls.

## Final verification

### Backend matrix

Ran the exact 17-test Task 6 matrix plus the new preflight-service and execute-boundary tests:

```bash
for test_file in backend/tests/ExploratoryQueryDefaultsServiceTest.php backend/tests/ExploratorySqlRepairServiceTest.php backend/tests/GeminiServiceExploratoryRepairTest.php backend/tests/GeminiServiceExploratoryGateTest.php backend/tests/GeminiServiceFamilyCompilerFallbackTest.php backend/tests/GeminiServiceFamilyCompilerResultTest.php backend/tests/GeminiServiceFamilyIntentBranchTest.php backend/tests/GeminiServiceFamilyMatchPolicyTest.php backend/tests/GeminiServiceFamilyMismatchTelemetryTest.php backend/tests/GeminiServiceFamilyShapeValidationTest.php backend/tests/GeminiServiceQueryFamilySelectionTest.php backend/tests/GeminiServiceSqlNormalizationTest.php backend/tests/FolioQueryControllerExploratoryRepairTest.php backend/tests/FolioQueryControllerAskContinuationPolicyTest.php backend/tests/FolioQueryControllerNlFollowUpTest.php backend/tests/FolioQueryControllerPolicyViolationStatusTest.php backend/tests/AskAiCrossDomainRoiRegressionTest.php backend/tests/SqlPreflightServiceTest.php backend/tests/FolioQueryControllerExecutePreflightTest.php; do php "$test_file" || exit 1; done
```

Result: exit 0; all 19 tests passed. Output contained only the two previously approved PHP 8.5 `ReflectionMethod::setAccessible()` deprecations.

### Frontend

```bash
cd frontend
npm test
npm run build
```

Results:

- 21 test files passed;
- 101 tests passed;
- TypeScript and Vite production build passed;
- 2,503 modules transformed in 5.70 seconds.

The existing Vite advisory for chunks over 500 kB remains.

### Static and artifact checks

- PHP lint passed for all changed production PHP files and backend tests.
- `git diff --check` was silent.
- `git diff --name-only d39b62e -- backend/data backend/services/QueryFamilyContractService.php` was empty, confirming no canonical/schema artifact changes.

## Concerns

- The two existing PHP 8.5 Reflection deprecations remain in the approved tests.
- The existing Vite large-chunk advisory remains.
- No final whole-branch review was performed here; the root agent will re-dispatch the existing reviewer as requested.

## Final re-review follow-up

The re-dispatched whole-branch reviewer found one Important and one Minor issue after commit `fec9f6b`:

1. `actionExecute()` and `actionQuerySubmit()` did not catch the typed cancellation thrown directly by `SqlPreflightService`.
2. Gemini static/model validation emitted terminal `validated` before the controller completed database preflight, allowing contradictory terminal outcomes when preflight later failed.

### Follow-up RED

Focused regressions failed on the intended missing behavior:

```text
FolioQueryControllerExecutePreflightTest.php
Uncaught app\exceptions\DatabaseQueryCancelledException:
Database query validation was cancelled.

GeminiServiceExploratoryRepairTest.php
Static/model validation must not emit terminal validated before database preflight.
Expected: 0
Actual: 1

FolioQueryControllerExploratoryRepairTest.php
Exploratory controller handling should emit exactly one terminal outcome after re-preflight.
Expected: 1
Actual: 0
```

A related execution-safety assertion also proved the existing execution catch exposed raw database details and SQL instead of a stable category.

### Follow-up GREEN

- Both synchronous execute and background-submit preflight boundaries now catch `DatabaseQueryCancelledException` and return HTTP 503 with stable `database_cancelled` copy.
- The actual query-execution catch remains separate and now returns only `query_execution_failed` plus safe copy; it does not expose exception messages or SQL.
- Gemini generation and preflight-repair methods no longer emit terminal `validated` for candidates that still require controller preflight.
- `validateAndRepairNlResult()` emits terminal `validated` only after the final candidate passes preflight. Repaired candidates restart and complete preflight before this event.
- Terminal failure outcomes remain emitted at the point where no further validation can succeed, and canonical routes remain excluded from exploratory terminal telemetry.

### Follow-up verification

- Exact 17-test Task 6 matrix plus `SqlPreflightServiceTest.php` and `FolioQueryControllerExecutePreflightTest.php`: exit 0, all 19 passed.
- Frontend: 21 files and 101 tests passed.
- Production build: exit 0; 2,503 modules transformed in 5.64 seconds.
- PHP lint passed for all changed production/test PHP files.
- `git diff --check` was silent.
- Canonical query-family and schema artifacts remained unchanged.

The only advisories remain the two approved PHP 8.5 Reflection deprecations and the existing Vite large-chunk warning.

## Approval telemetry completion

The approval review recorded one nonblocking Minor: the controller emitted a safe terminal `cancelled` event when preflight returned an error array, but not when the production preflight callable directly threw `DatabaseQueryCancelledException`.

### RED

The focused controller regression invoked `validateAndRepairNlResult()` with a preflight callable that directly threw the typed exception. The exception propagated and the sanitized response path remained available, but telemetry failed as intended:

```text
Direct typed preflight cancellation should emit exactly one terminal outcome.
Expected: 1
Actual: 0
```

### GREEN

The controller preflight boundary now catches only `DatabaseQueryCancelledException`, emits one safe exploratory terminal event using the still-available route, reason, prompt fingerprint, repair count, and `database_cancelled` category, then rethrows to the existing sanitized response handler. The test verifies:

- exactly one terminal event;
- `outcome = cancelled`;
- `category = database_cancelled`;
- the safe exploratory route is retained;
- no exception/error detail is present;
- repair is never invoked;
- the browser response remains `database_cancelled` with safe copy.

### Final verification

- Exact 17-test Task 6 matrix plus the two final-fix tests: all 19 passed.
- Frontend: 21 files and 101 tests passed.
- Production build passed; 2,503 modules transformed in 6.89 seconds.
- PHP lint, `git diff --check`, and canonical/schema artifact isolation checks passed.

The only advisories remain the two approved PHP 8.5 Reflection deprecations and the existing Vite large-chunk warning.

## SELECT safety final-review fixes

The final review identified two remaining coverage and recovery-contract gaps:

- rejected responses without either suggestion source promised refinement but rendered no Refine action;
- the controller regression used a safety double, leaving the real `SqlBuilderService::validateSafety()` behavior for a harmless `DO` value unproved.

### RED

The rejected component fixture explicitly provided empty top-level and exploratory-plan suggestion sources. Its attempt to click `Refine with: Rephrase this as a read-only report.` failed because only Retry was rendered:

```text
TestingLibraryElementError: Unable to find an accessible element with the role "button"
and name "Refine with: Rephrase this as a read-only report."
```

The real-service regression was coverage-only and passed on first execution: `SELECT 'DO' AS action_word` was accepted, while `DELETE FROM inventory.instance__t` still raised `InvalidArgumentException`. No safety-policy production change was needed.

### GREEN

- Rejected responses now use two concise deterministic refinement choices only when both supplied suggestion sources are empty.
- Existing response or exploratory-plan suggestions retain priority.
- The fallback action preserves `onRefine(originalQuestion, suggestion)`; Retry remains present; classifier detail, Generated SQL, and Run controls remain absent.
- `SqlBuilderServicePolicyViolationTest.php` now exercises the real safety service for both the harmless SELECT value and destructive rejection.

### Focused verification

```text
Frontend Task 2 set: 3 files passed, 30 tests passed.
Backend Task 1 set: all 3 scripts passed.
```

The backend output contained only the existing PHP 8.5 `ReflectionMethod::setAccessible()` deprecation.

Final production verification also passed: TypeScript/Vite built 2,505 modules, PHP lint and `git diff --check` were clean, and no protected schema/cache/canonical artifacts changed. The existing Vite large-chunk advisory remains.
