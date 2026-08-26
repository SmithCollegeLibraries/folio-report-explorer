# Two-Lane Report Generation Phase 1 Implementation Report

**Date:** 2026-08-26
**Plan:** `docs/superpowers/plans/2026-08-26-two-lane-report-generation-phase-1.md`
**Spec:** `docs/superpowers/specs/2026-08-26-two-lane-report-generation-design.md`

## Outcome

Phase 1 now has public-boundary regression coverage for the verified-pattern and AI-built lanes, stable server- and client-side provenance normalization, and terminal hard-failure responses that expose no SQL, success provenance, clarification, recovery, or correction payloads. Pre-result SQL-safety exceptions, the configured 500-identifier ceiling, enabled-mode unclassified generation failures, and recognized non-2xx typed API failures use the same Retry-only terminal contract instead of entering normal recovery UI.

Advisory semantic validation now remains review-required through evidence persistence and controller finalization. Provider truncation and structured PostgreSQL authorization, cancellation, and operator-intervention states stop before AI repair at both repair boundaries.

No test contacted production FOLIO or an external AI provider. Routing tests used deterministic fake provider responses, isolated reference fixtures, the real canonical compiler, and checked-in schema metadata. Database-preflight tests used deterministic controller fixtures.

## Commits

- `6899d20` — `feat: add two-lane provenance contract`
- `d4fab32` — `fix: gate provenance on executable SQL`
- `9c5c3e6` — `feat: use reference ambiguity as generation context`
- `7fb309e` — `feat: route canonical failures through ai generation`
- `de396b2` — `fix: preserve ai advisory metadata`
- `aa774ad` — `feat: repair canonical sql after database preflight`
- `81a6d18` — `fix: harden preflight infrastructure failures`
- `c74fb7d` — `fix: preserve preflight sqlstate classes`
- `b8296bf` — `feat: unify ask results around generation provenance`
- `bfd05d2` — `fix: preserve reuse provenance in ask`
- `dbf89a9` — `fix: downgrade edited reuse provenance`
- `a0bf0d9` — `fix: normalize ask success provenance`
- `ed46fde` — `test: verify two-lane report generation`
- `d5b375b` — `fix: route ask hard limits as terminal failures`
- Final cross-phase fix wave — advisory evidence and terminal-boundary hardening

## Required routing matrix

`backend/tests/GeminiServiceTwoLaneRoutingTest.php` exercises `GeminiService::generateSqlWithShadow()` with the exact public prompts.

| Prompt | Observed provenance | Public outcome |
|---|---|---|
| `Show me a list of VHS and DVDs at Hillyer Library.` | `verified_pattern` / **Verified pattern** | One structured-intent request; real canonical compiler emits safe SQL with `SC Hillyer Art Library`, `Videocassette`, and `DVD/Blu-ray`; no blocker fields. |
| `Show me the 20 most-circulated books at Neilson Library during the last five years. Include title, call number, publication year, checkout count, and most recent checkout date.` | `ai_built` / **AI-built** | Unsupported canonical outputs trigger one automatic AI-built request; unresolved Neilson candidates remain model-only context; safe SQL returns without clarification or recovery. |
| `Compare annual circulation with acquisition spending by material type for the last three completed fiscal years.` | `ai_built` / **AI-built** | No family match routes directly to safe AI-built SQL; no blocker fields. |

The Neilson case deliberately omits `nl2sqlTwoLaneEnabled` and therefore also proves the default-on contract. With `nl2sqlTwoLaneEnabled=false`, the same resolver fixture returns the retained rollback-only clarification response before any provider request and does not claim SQL or provenance.

## Provenance evidence

- Backend finalization now derives exactly one trusted pair for every executable SQL response.
- `verified_pattern` survives only when the server response is a `builder_intent` result with a `family_contract_supported:*` reason.
- Missing, invalid, exploratory, legacy, repaired, or otherwise untrusted executable responses normalize to `ai_built` / **AI-built**.
- No-SQL responses have both provenance fields removed.
- Frontend ingestion independently normalizes the same pair and `AskTrustNotice` renders it in the normal success layout.

## Hard-gate evidence

Public service/controller cases cover:

- destructive AI SQL and multiple statements: the real service retains its non-repairable safety exception / `InvalidArgumentException` paths and exact validator messages; `actionNl()` classifies both as `unsafe_generated_sql` with zero database preflight calls, concise Retry copy, and no repair;
- 501 explicit identifiers with two-lane mode enabled: `configured_resource_limit`, zero provider calls, concise Retry copy, and no SQL/provenance/clarification/recovery/correction fields; the false switch retains the legacy identifier clarification for rollback;
- restricted `users.users__t` patron data: `PolicyViolationException`, no repair request;
- database cancellation / SQLSTATE `57014`: `database_cancelled`, no AI repair;
- provider `MAX_TOKENS` during the real exploratory-repair transport: standard AI-provider hard stop before response parsing;
- provider timeout: `ai_timeout`, HTTP 504, no recovery payload;
- PostgreSQL SQLSTATE class `08`: typed `postgres_connectivity`, no AI repair;
- PostgreSQL SQLSTATE class `28`: typed `policy_blocked`, HTTP 403, no AI repair;
- exact PostgreSQL SQLSTATE `42501`: typed `policy_blocked`, HTTP 403, no AI repair even when the message is opaque;
- exact PostgreSQL SQLSTATE `57014`: cancellation, while remaining class `57` states such as `57P01` are database availability hard stops; neither path invokes AI repair;
- PostgreSQL SQLSTATE classes `53` and `54`: typed database resource limit, HTTP 503, no AI repair;
- shared repair-budget exhaustion: exactly two repair attempts, `sql_generation_failed`, `generation_failed`, `sql_repair_exhausted`, Retry-only copy, and no SQL/provenance/correction fields.

All hard-failure response-shape assertions reject `needsClarification`, clarification items, recovery context/items, attempted plans, correction instructions, suggestions, and the legacy “request is preserved” / resolver-blocker copy.

## Rollout and retained compatibility

- `backend/config/params.php` defaults `nl2sqlTwoLaneEnabled` on.
- Runtime preflight reports the effective switch and warns when strict rollback is active.
- The false-switch path retains clarification/recovery compatibility shapes for administrative rollback.
- An unclassified generation exception returns typed `sql_generation_failed` in enabled mode; only the false-switch path can return `exploratory_recovery`.
- `Ask.tsx` intentionally still contains the legacy clarification and `ExploratoryRecoveryPanel` implementation. The source audit found 11 rollback-related matches. This is intentional under the binding SDD ruling.
- Behavioral routing is the gate: any response with SQL selects `success`; typed no-SQL hard failures select `terminal_failure`; only untyped no-SQL compatibility responses can select `legacy_clarification` or `legacy_recovery`.

## Finalization and request-lifecycle evidence

- `AskGenerationEvidenceService` treats `semanticValidation.status=advisory` as limited semantic coverage even if no independent coverage-status field is present.
- Controller finalization persists the stable `limited_semantic_coverage` review reason and returns the public review notice without overwriting `reviewRequired=true`.
- Direct AI and no-family transitions report `direct_ai` and `no_matching_pattern` source lanes instead of claiming `verified_pattern`.
- Recognized Axios 403/503/504 typed response bodies replace any stale success in shared Ask state, reset prior execution results, render the assertive terminal alert, and expose Retry. Unknown rejected responses retain the existing generic error path.

## Verification

Backend standalone suite:

```bash
for test_file in backend/tests/*Test.php; do php "$test_file" || exit 1; done
```

Result: **139/139 standalone test files exited 0**. Environment-gated live FOLIO tests remained skipped by their existing fixtures.

Focused public-boundary verification:

```bash
php backend/tests/GeminiServiceTwoLaneRoutingTest.php
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php backend/tests/AskResponseContractServiceTest.php
```

Result: all three passed; the routing test reports **3 routing cases and 4 service hard gates**, while the controller test covers pre-result SQL-safety exceptions plus the database/provider/exhaustion public hard gates.

Frontend suite:

```bash
cd frontend && npm test
```

Result: **40/40 files, 222/222 tests passed**. Existing Browserslist staleness and Node localStorage experimental warnings were observed.

Focused blocker/provenance behavior:

```bash
cd frontend && npm test -- --run src/pages/Ask.requestLifecycle.test.tsx src/pages/Ask.errorFormatting.test.ts src/components/AskTrustNotice.test.tsx src/pages/Ask.followUp.test.ts
```

Result: **4/4 files, 43/43 tests passed**. This includes rendered request-lifecycle coverage for rejected 403/503/504 responses, stale-success removal, execution reset, assertive terminal alerts, and Retry, plus typed HTTP-200 PostgreSQL connectivity, unsafe SQL, database-resource failures, and the 501-identifier configured-resource failure selecting `terminal_failure` ahead of the retained rollback recovery component.

Production build:

```bash
cd frontend && npm run build
```

Result: exit 0; Vite transformed **2,513 modules**. Existing large-chunk and Browserslist warnings were observed.

Lint attempt:

```bash
cd frontend && npm run lint
```

Result: exit 127. Exact tooling failure:

```text
> eslint .
sh: eslint: command not found
```

ESLint is absent from project dependencies. No dependency was added to mask this known tooling gap.

Source audit:

```bash
rg -n "Clarification needed|The request is preserved|What still needs to be resolved|ExploratoryRecoveryPanel|handleRefineExploratory|handleRetryExploratory" frontend/src/pages/Ask.tsx
rg -n "generationProvenance|provenanceLabel" backend/services/AskResponseContractService.php backend/services/GeminiService.php backend/controllers/FolioQueryController.php frontend/src/pages/Ask.tsx frontend/src/components/AskTrustNotice.tsx
```

Result: the first command found the 11 intentionally retained rollback-only matches; the second found provenance decoration, normalization, transport, and visible rendering. The behavioral tests above prove enabled two-lane success and terminal-failure responses cannot select the retained blocker branches.

## Deferred work

Phase 2 report revisions remain explicitly unimplemented. This change does not add **Refine this report**, parent-generation revision routing, or new revision lineage behavior.

## Known concerns

- Connectivity remains an HTTP-200 typed no-SQL response for compatibility, but it is now a terminal `postgres_connectivity` shape with no recovery payload and cannot be mistaken for a successful report.
- Legacy blocker UI remains in the frontend bundle solely for false-switch rollback compatibility; behavioral coverage must remain in place to prevent normal enabled responses from selecting it.
- ESLint cannot run until the project intentionally adds it to dependencies/tooling.
