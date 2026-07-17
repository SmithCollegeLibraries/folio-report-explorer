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
