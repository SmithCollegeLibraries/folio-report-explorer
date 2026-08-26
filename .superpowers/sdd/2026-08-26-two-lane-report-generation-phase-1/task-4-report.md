# Task 4 Report: Seed Canonical Database-Preflight Failures into AI Repair

## Status

Complete. Safe canonical and AI-built SQL candidates now share the bounded post-preflight repair path. AI-reviewed semantic advisories reach database preflight, raw semantic rejections seed AI review, ordinary repair exhaustion returns the concise `sql_generation_failed` contract, and database resource/program limits remain hard stops.

## Implementation

### Controller routing

- Replaced exploratory route-name inference with `isAiRepairEligible()`, which accepts executable `verified_pattern` and `ai_built` candidates plus the compatibility `canonical`/`exploratory` modes.
- Kept safety validation before every semantic or database repair decision.
- Allowed `semanticValidation.status=advisory` candidates to proceed to database preflight.
- Routed applicable semantic candidates without `validated` or `advisory` status through the seeded repair callback with the safe diagnostic `Semantic validation requires AI review.`
- Relabeled every successful controller-side repaired SQL candidate to `mode=exploratory`, `route=exploratory`, and `generationProvenance=ai_built` before re-preflight.
- Preserved connectivity, cancellation, authorization/policy, resource-limit, and unsafe-SQL branches ahead of repair.

### Resource hard stops

- Added controller and Gemini preflight classifiers for PostgreSQL insufficient-resource and program-limit families (`SQLSTATE 53xxx` and `54xxx`), including tested `53200`, `53400`, and `54001` cases.
- Covered text signals for resource exhaustion, memory/disk/connection exhaustion, configuration limits, query complexity, and configured query cost/row limits.
- Controller resource failures return a distinct `database_resource_limit` response and HTTP 503 without SQL or an AI call.
- `GeminiService::repairExploratorySqlAfterPreflight()` logs a safe `resource_limited/resource_limit` terminal outcome and throws before making a provider request.

### Exhaustion contract

- Replaced the legacy exploratory recovery payload with:
  - `errorType=sql_generation_failed`
  - `message=Report Explorer could not safely run this report. Please retry.`
  - `route=generation_failed`
  - `routeReason=sql_repair_exhausted`
  - `validationSummary.status=exhausted` and the bounded repair count
- Removed request-preserved text, recovery context/items, attempted plans, suggestions/correction guidance, unmet-requirement details, resolver output, and verified-pattern provenance from terminal response fields.
- Set trusted internal `finalSql` evidence to `null`; prior safe provenance metadata remains internal for review persistence and is stripped by finalization.
- Kept unsafe generated SQL distinct as `unsafe_generated_sql`.

## Strict TDD evidence

### Baseline

Command:

```bash
php backend/tests/FolioQueryControllerExploratoryRepairTest.php && php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Output before test changes:

```text
FolioQueryController exploratory repair test passed
[PHP 8.5 ReflectionMethod::setAccessible() deprecation output]
GeminiService exploratory repair test passed
```

### RED cycle 1: canonical repair and concise exhaustion

Command:

```bash
php backend/tests/FolioQueryControllerExploratoryRepairTest.php; php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Output:

```text
Canonical database failures must consume the shared two-repair budget before exhaustion.
Expected: 2
Actual: 0

[PHP 8.5 ReflectionMethod::setAccessible() deprecation output]
Exhaustion must expose a concise SQL-generation failure type.
Expected: 'sql_generation_failed'
Actual: NULL
```

These are the intended failures: the controller excluded the canonical seed, and Gemini returned the legacy recovery shape.

### GREEN cycle 1

Commands:

```bash
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
php backend/tests/SqlBuilderServicePolicyViolationTest.php
```

Output:

```text
FolioQueryController exploratory repair test passed
GeminiService exploratory repair test passed
SqlBuilderService policy violation test passed
```

### RED cycle 2: PostgreSQL program-limit family

After adding `SQLSTATE[54001]: Statement too complex` to both behavior suites, command:

```bash
php backend/tests/FolioQueryControllerExploratoryRepairTest.php; php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Output:

```text
Database resource-limit failures must never invoke AI repair.
Expected: 0
Actual: 2

[PHP 8.5 ReflectionMethod::setAccessible() deprecation output]
Database resource limits must be hard stops.
```

### GREEN cycle 2 and focused final

Command:

```bash
php backend/tests/FolioQueryControllerExploratoryRepairTest.php; php backend/tests/GeminiServiceExploratoryRepairTest.php; php backend/tests/SqlBuilderServicePolicyViolationTest.php
```

Output:

```text
FolioQueryController exploratory repair test passed
GeminiService exploratory repair test passed
SqlBuilderService policy violation test passed
```

## Broader verification

Command:

```bash
for test_file in backend/tests/FolioQueryController*Test.php backend/tests/GeminiService*Test.php; do php "$test_file" || exit 1; done
```

Output summary:

```text
40/40 controller and Gemini behavior scripts passed.
No failures or fatal errors.
The output included pre-existing PHP 8.5 deprecation warnings from ReflectionMethod::setAccessible() in unrelated broader test files.
```

Relevant explicitly observed passes included:

```text
FolioQueryController exploratory repair test passed
FolioQueryController Ask continuation policy test passed
Folio query controller cancellation test passed
FolioQueryController NL follow-up test passed
FolioQueryController policy violation status test passed
GeminiService exploratory gate test passed
GeminiService exploratory repair test passed
GeminiService family compiler fallback test passed
GeminiService intent request path test passed
GeminiService provider fallback telemetry test passed
GeminiService timeout classification test passed
```

Syntax and whitespace command:

```bash
php -l backend/controllers/FolioQueryController.php
php -l backend/services/GeminiService.php
php -l backend/tests/FolioQueryControllerExploratoryRepairTest.php
php -l backend/tests/GeminiServiceExploratoryRepairTest.php
git diff --check -- backend/controllers/FolioQueryController.php backend/services/GeminiService.php backend/tests/FolioQueryControllerExploratoryRepairTest.php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Output:

```text
No syntax errors detected in backend/controllers/FolioQueryController.php
No syntax errors detected in backend/services/GeminiService.php
No syntax errors detected in backend/tests/FolioQueryControllerExploratoryRepairTest.php
No syntax errors detected in backend/tests/GeminiServiceExploratoryRepairTest.php
```

`git diff --check` produced no output and exited successfully.

## Files changed

- `backend/controllers/FolioQueryController.php`
- `backend/services/GeminiService.php`
- `backend/tests/FolioQueryControllerExploratoryRepairTest.php`
- `backend/tests/GeminiServiceExploratoryRepairTest.php`
- `.superpowers/sdd/2026-08-26-two-lane-report-generation-phase-1/task-4-report.md`

Unrelated pre-existing dirty reports, cache JSON files, design/plan files, and the SQL dump were not modified or staged.

## Self-review

- Mutation check: restricting repair to exploratory routes fails canonical repair tests.
- Mutation check: removing explicit AI provenance decoration leaves `verified_pattern` on the repaired candidate and fails the provenance assertion.
- Mutation check: rejecting advisory semantics prevents the advisory preflight assertion.
- Mutation check: preflighting raw semantic rejection fails the closure assertion that only the repaired SQL reaches PostgreSQL.
- Mutation check: moving resource checks after repair or removing any tested resource family produces nonzero AI request counts.
- Mutation check: restoring the legacy recovery payload fails route, error type, copy, forbidden-field, and rejected-SQL assertions.
- Hard safety remains ordered before repair: unsafe SQL, connectivity, cancellation, policy/authorization, and resource limits cannot enter the AI seam.
- PHP 7.2 compatibility is preserved; focused test reflection setup is conditional below PHP 8.1 to avoid PHP 8.5 deprecation noise while retaining older runtime access behavior.

## Concerns

- No functional blocker found.
- The broad suite still emits pre-existing PHP 8.5 deprecation warnings in unrelated tests that call `ReflectionMethod::setAccessible()` unconditionally. They do not affect pass/fail status and were left outside Task 4 scope.

## Independent review fix round 1

### Findings and implementation

- Confirmed that `SqlPreflightService` can return only PostgreSQL `ERROR:` detail, so hard-stop routing cannot depend on the SQLSTATE surviving normalization.
- Expanded both controller and Gemini preflight classifiers for the full PostgreSQL connection-exception class (`08xxx`) and authorization class (`28xxx`) plus normalized `connection does not exist` and `password authentication failed` messages.
- Expanded both resource/program-limit classifiers with normalized `insufficient resources`, `program limit exceeded`, `stack depth limit exceeded`, `statement too complex`, and too-many-columns/arguments signals. These complement the existing `53xxx`/`54xxx`, memory, disk, connection, complexity, cost, row, and configured-limit signals.
- Preserved the existing hard behavior: connectivity remains `postgres_connectivity`, authorization remains an HTTP 403 policy block, and resource limits remain `database_resource_limit` with HTTP 503. Controller repair callbacks and Gemini transports are asserted to receive zero calls for every hard-stop case.
- Added `GeminiService::isAiProviderFailureMessage()` for the service's concrete provider exception formats and routed matching controller exceptions to a dedicated HTTP 503 `ai_provider_failure` response. The response omits SQL, correction instructions, recovery context/items, attempted plans, suggestions, and request-preserving legacy copy.
- Added an exact canonical regression assertion that the preflight receives precisely two candidates in order: the initial canonical SQL followed by the repaired SQL.

### RED evidence: normalized PostgreSQL hard stops

Commands:

```bash
php -l backend/tests/FolioQueryControllerExploratoryRepairTest.php && php -l backend/tests/GeminiServiceExploratoryRepairTest.php && php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Output:

```text
No syntax errors detected in backend/tests/FolioQueryControllerExploratoryRepairTest.php
No syntax errors detected in backend/tests/GeminiServiceExploratoryRepairTest.php
Connectivity failures must not trigger SQL repair.
Expected: 0
Actual: 1

Database resource limits must be hard stops.
```

The controller repaired normalized `connection does not exist`, while Gemini reached the repair transport for normalized `stack depth limit exceeded`.

### Intermediate GREEN and RED evidence: provider contract

After implementing only the PostgreSQL classifiers, commands:

```bash
php backend/tests/GeminiServiceExploratoryRepairTest.php
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
```

Output:

```text
GeminiService exploratory repair test passed
Provider repair failures must retain a distinct hard-failure type.
Expected: 'ai_provider_failure'
Actual: NULL
```

This isolated the second review finding: the public controller path still finalized the generic exploratory recovery response.

### GREEN evidence

Commands:

```bash
php -l backend/controllers/FolioQueryController.php && php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php -l backend/services/GeminiService.php && php backend/tests/GeminiServiceExploratoryRepairTest.php
php backend/tests/FolioQueryControllerExploratoryRepairTest.php && php backend/tests/SqlBuilderServicePolicyViolationTest.php && php backend/tests/SqlPreflightServiceTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php && php backend/tests/GeminiServiceTimeoutClassificationTest.php
```

Output:

```text
No syntax errors detected in backend/controllers/FolioQueryController.php
FolioQueryController exploratory repair test passed
No syntax errors detected in backend/services/GeminiService.php
GeminiService exploratory repair test passed
FolioQueryController exploratory repair test passed
SqlBuilderService policy violation test passed
SqlPreflightService test passed
GeminiService exploratory repair test passed
GeminiService timeout classification test passed
```

### Broad verification

Command:

```bash
for test_file in backend/tests/FolioQueryController*Test.php backend/tests/GeminiService*Test.php; do php "$test_file" || exit 1; done
```

Output summary:

```text
40/40 controller and Gemini behavior scripts passed.
No failures or fatal errors.
```

The output continues to include only the pre-existing PHP 8.5 `ReflectionMethod::setAccessible()` deprecations described above.

### Fix-round files changed

- `backend/controllers/FolioQueryController.php`
- `backend/services/GeminiService.php`
- `backend/tests/FolioQueryControllerExploratoryRepairTest.php`
- `backend/tests/GeminiServiceExploratoryRepairTest.php`
- `backend/tests/GeminiServiceTimeoutClassificationTest.php`
- `.superpowers/sdd/2026-08-26-two-lane-report-generation-phase-1/task-4-report.md`

### Fix-round self-review and concerns

- The hard-stop checks remain before creation of the repair failure/callback in `GeminiService::repairExploratorySqlAfterPreflight()` and before the controller invokes its repair callback.
- The provider classifier covers the service's missing-key, Gemini request/API, OpenAI fallback, quota/billing/rate-limit, resource-exhaustion, and provider HTTP authorization/throttling formats; a negative assertion prevents a normalized database connectivity message from being mislabeled as an AI provider failure.
- The dedicated provider response does not reuse `buildAskContinuationFromFailure()`, so legacy exploratory recovery fields cannot leak into this path.
- No functional blocker found. Unrelated dirty reports, cache JSON files, design/plan files, and the SQL dump remain untouched and unstaged.

## Independent review fix round 2

### Findings and implementation

- Replaced dependence on finite normalized-message matching with a structured preflight contract. `SqlPreflightService` now returns `sqlState` and `sqlStateClass` beside its normalized safe `error` text, sourcing the state from PDO error information when available and otherwise from the original exception message.
- The controller classifies structured SQLSTATE class `08` as connectivity, class `28` as authorization/policy, and classes `53`/`54` as resource/program limits before invoking any repair callback.
- The controller passes the full preflight result through an optional trailing argument to `GeminiService::repairExploratorySqlAfterPreflight()`. Gemini independently repeats the class checks before constructing or invoking its repair operation.
- Existing argument order remains unchanged and `preflightResult` is optional and last. Existing message-only test doubles and callers continue through the prior message classifiers.
- Added explicit zero-call behavior coverage for PostgreSQL `08P01` protocol violation, `28000` role-not-permitted login, `53100` no-space, `53300` reserved connection slots, `54011` maximum columns, and `54023` maximum arguments in both controller and Gemini behavior suites.
- Extended the provider-hard-failure classifier for `MAX_TOKENS` and Gemini's `The AI response was truncated...` exception. Both now finalize as the field-clean HTTP 503 `ai_provider_failure` response.

### RED evidence: structured SQLSTATE retention and routing

Commands:

```bash
php -l backend/tests/SqlPreflightServiceTest.php && php backend/tests/SqlPreflightServiceTest.php
php -l backend/tests/FolioQueryControllerExploratoryRepairTest.php && php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php -l backend/tests/GeminiServiceExploratoryRepairTest.php && php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Output:

```text
No syntax errors detected in backend/tests/SqlPreflightServiceTest.php
Preflight should preserve the PostgreSQL SQLSTATE alongside normalized error text.
Expected: '42883'
Actual: NULL

No syntax errors detected in backend/tests/FolioQueryControllerExploratoryRepairTest.php
Structured SQLSTATE class 08 must stop before controller AI repair.
Expected: 0
Actual: 2

No syntax errors detected in backend/tests/GeminiServiceExploratoryRepairTest.php
Structured SQLSTATE class 08 must not be repaired.
```

These failures prove the state was absent at the preflight boundary and ignored by both downstream layers.

### GREEN evidence: structured SQLSTATE retention and routing

Commands:

```bash
php -l backend/services/SqlPreflightService.php && php backend/tests/SqlPreflightServiceTest.php
php -l backend/controllers/FolioQueryController.php && php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php -l backend/services/GeminiService.php && php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Output:

```text
No syntax errors detected in backend/services/SqlPreflightService.php
SqlPreflightService test passed
No syntax errors detected in backend/controllers/FolioQueryController.php
FolioQueryController exploratory repair test passed
No syntax errors detected in backend/services/GeminiService.php
GeminiService exploratory repair test passed
```

### RED evidence: provider truncation contract

Commands:

```bash
php -l backend/tests/FolioQueryControllerExploratoryRepairTest.php && php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php -l backend/tests/GeminiServiceTimeoutClassificationTest.php && php backend/tests/GeminiServiceTimeoutClassificationTest.php
```

Output:

```text
No syntax errors detected in backend/tests/FolioQueryControllerExploratoryRepairTest.php
Provider repair failures must retain a distinct hard-failure type.
Expected: 'ai_provider_failure'
Actual: NULL

No syntax errors detected in backend/tests/GeminiServiceTimeoutClassificationTest.php
GeminiService should classify its provider exception messages for hard controller handling.
Expected: true
Actual: false
```

### GREEN evidence: provider truncation contract

Command:

```bash
php backend/tests/FolioQueryControllerExploratoryRepairTest.php && php backend/tests/SqlPreflightServiceTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php && php backend/tests/GeminiServiceTimeoutClassificationTest.php
```

Output:

```text
FolioQueryController exploratory repair test passed
SqlPreflightService test passed
GeminiService exploratory repair test passed
GeminiService timeout classification test passed
```

### Compatibility regression and correction

The first broad run correctly failed the existing reflection guard because it still asserted the old exact six-parameter list:

```text
Post-preflight repair must preserve the raw/generation prompt ordering and append resolved filters.
```

The guard now asserts that the original six parameters retain their exact order and that optional `preflightResult` is appended seventh. `GeminiServiceResolvedLocationGuardTest.php` then passed individually, followed by the complete suite.

### Focused and broad verification

Commands:

```bash
php backend/tests/SqlPreflightServiceTest.php && php backend/tests/SqlBuilderServicePolicyViolationTest.php && php backend/tests/FolioQueryControllerAskContinuationPolicyTest.php && php backend/tests/FolioQueryControllerExecutePreflightTest.php && php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php && php backend/tests/GeminiServiceFamilyCompilerFallbackTest.php && php backend/tests/GeminiServiceProviderFallbackTelemetryTest.php && php backend/tests/GeminiServiceTimeoutClassificationTest.php
for test_file in backend/tests/FolioQueryController*Test.php backend/tests/GeminiService*Test.php; do php "$test_file" || exit 1; done
```

Output summary:

```text
All nine focused safety/controller/Gemini scripts passed.
40/40 controller and Gemini behavior scripts passed.
No failures or fatal errors.
```

The broad output includes the same pre-existing PHP 8.5 reflection deprecations and no new warnings.

### Fix-round files changed

- `backend/services/SqlPreflightService.php`
- `backend/controllers/FolioQueryController.php`
- `backend/services/GeminiService.php`
- `backend/tests/SqlPreflightServiceTest.php`
- `backend/tests/FolioQueryControllerExploratoryRepairTest.php`
- `backend/tests/GeminiServiceExploratoryRepairTest.php`
- `backend/tests/GeminiServiceResolvedLocationGuardTest.php`
- `backend/tests/GeminiServiceTimeoutClassificationTest.php`
- `.superpowers/sdd/2026-08-26-two-lane-report-generation-phase-1/task-4-report.md`

### Fix-round self-review and concerns

- Mutation check: removing SQLSTATE extraction fails the `42883` and six hard-family structure assertions; ignoring the structured class in either downstream layer produces nonzero repair/provider calls.
- Mutation check: moving any structured class check after repair fails the zero-call assertions; changing the connectivity/policy/resource branch fails its type, route/status, or telemetry assertion.
- Mutation check: removing either truncation classifier alternative makes both the public controller response and direct provider classifier regressions fail.
- Message-only connectivity, authentication, resource, cancellation, and ordinary repair cases remain covered and passing, protecting backward compatibility.
- No functional blocker found. The optional trailing parameter is the only public signature extension and preserves all existing positional callers.
