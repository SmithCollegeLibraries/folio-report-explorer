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
