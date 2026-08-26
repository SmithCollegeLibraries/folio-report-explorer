# Task 1 Report: Rollout Switch and Stable Provenance Contract

## Implementation

- Added default-on `nl2sqlTwoLaneEnabled`, configured from `nl2sql_two_lane_enabled` / `NL2SQL_TWO_LANE_ENABLED` with a default of `true`.
- Extended runtime preflight with the effective rollout flag, the persisted setting allowlist entry, a default-on fallback for callers omitting the parameter, and the exact strict-blocker rollback warning.
- Added the explicit `verified_pattern` and `ai_built` response provenance enum values, public labels, and `withGenerationProvenance()`.
- Added trusted-evidence persistence of the allowlisted stable provenance enum only; the display label is not persisted.
- Added tests for labels, failures without SQL, default-on compatibility, rollback reporting, valid persisted provenance, and rejection of unknown provenance.

## TDD Evidence

### RED

Command:

```bash
php backend/tests/AskResponseContractServiceTest.php; php backend/tests/Nl2sqlRuntimePreflightServiceTest.php; php backend/tests/AskGenerationEvidenceServiceTest.php
```

Relevant output:

```text
PHP Fatal error:  Uncaught Error: Call to undefined method app\services\AskResponseContractService::withGenerationProvenance()
Two-lane routing should be visible in preflight.
Expected: true
Actual: NULL
Stable generation provenance must reach administrator and query-job metadata.
Expected: 'ai_built'
Actual: NULL
```

Additional RED command after adding the direct-caller and allowlist behavior checks:

```bash
php backend/tests/Nl2sqlRuntimePreflightServiceTest.php; php backend/tests/AskGenerationEvidenceServiceTest.php
```

Relevant output:

```text
Older direct callers must retain the default-on two-lane rollout.
Expected: true
Actual: NULL
Stable generation provenance must reach administrator and query-job metadata.
Expected: 'ai_built'
Actual: NULL
```

### GREEN and final verification

Command:

```bash
git diff --check -- backend/config/params.php backend/services/Nl2sqlRuntimePreflightService.php backend/services/AskResponseContractService.php backend/services/AskGenerationEvidenceService.php backend/tests/Nl2sqlRuntimePreflightServiceTest.php backend/tests/AskResponseContractServiceTest.php backend/tests/AskGenerationEvidenceServiceTest.php && php -l backend/config/params.php && php -l backend/services/Nl2sqlRuntimePreflightService.php && php -l backend/services/AskResponseContractService.php && php -l backend/services/AskGenerationEvidenceService.php && php backend/tests/AskResponseContractServiceTest.php && php backend/tests/Nl2sqlRuntimePreflightServiceTest.php && php backend/tests/AskGenerationEvidenceServiceTest.php
```

Output:

```text
No syntax errors detected in backend/config/params.php
No syntax errors detected in backend/services/Nl2sqlRuntimePreflightService.php
No syntax errors detected in backend/services/AskResponseContractService.php
No syntax errors detected in backend/services/AskGenerationEvidenceService.php
Ask response contract service test passed
Nl2sqlRuntimePreflightService test passed
Ask generation evidence service test passed
```

## Files Changed

- `backend/config/params.php`
- `backend/services/Nl2sqlRuntimePreflightService.php`
- `backend/services/AskResponseContractService.php`
- `backend/services/AskGenerationEvidenceService.php`
- `backend/tests/Nl2sqlRuntimePreflightServiceTest.php`
- `backend/tests/AskResponseContractServiceTest.php`
- `backend/tests/AskGenerationEvidenceServiceTest.php`
- `.superpowers/sdd/2026-08-26-two-lane-report-generation-phase-1/task-1-report.md`

## Self-Review

- The public label is derived solely from the stable enum in the response contract.
- `withGenerationProvenance()` does not infer a provenance from `mode`.
- Results without SQL remove both provenance fields before returning.
- Older preflight callers default to enabled, while `false` emits one explicit rollback warning.
- Persistence allowlists only the two stable enum values and never retains `provenanceLabel`.
- `git diff --check`, syntax checks, and the three relevant service suites passed.

## Concerns

None within Task 1 scope. The settings-display UI is intentionally untouched because the required Task 1 file contract limits the rollout switch change to application parameters and preflight reporting.

## Fix Round 1

### Change

`AskGenerationEvidenceService::build()` now derives `generatedSql` before provenance and persists `generationProvenance` only when that executable SQL boundary is non-null. This prevents a failed/no-SQL response with stale or caller-supplied `ai_built` / `verified_pattern` from reaching trusted evidence.

### TDD evidence

RED command:

```bash
php backend/tests/AskGenerationEvidenceServiceTest.php
```

RED output:

```text
No-SQL failures must not persist a stale generation provenance.
Expected: NULL
Actual: 'ai_built'
```

GREEN and focused Task 1 command:

```bash
php backend/tests/AskGenerationEvidenceServiceTest.php && php backend/tests/AskResponseContractServiceTest.php && php backend/tests/Nl2sqlRuntimePreflightServiceTest.php && php -l backend/services/AskGenerationEvidenceService.php && git diff --check -- backend/services/AskGenerationEvidenceService.php backend/tests/AskGenerationEvidenceServiceTest.php
```

Output:

```text
Ask generation evidence service test passed
Ask response contract service test passed
Nl2sqlRuntimePreflightService test passed
No syntax errors detected in backend/services/AskGenerationEvidenceService.php
```
