# Verified-First, AI-Always Report Generation and Query Memory Design

**Date:** 2026-08-27  
**Status:** Approved in conversation; pending written-spec review  
**Supersedes:** The routing, unsafe-candidate, and learning behavior in `2026-08-26-two-lane-report-generation-design.md`

## Summary

Report Explorer will use verified canonical compilation whenever it can, but canonical coverage will never define what users are allowed to ask. Every ordinary read-only request follows one continuous generation workflow:

1. reuse an eligible successful query when one exists;
2. otherwise attempt canonical compilation;
3. on any canonical miss or failure, generate SQL with AI;
4. reject and regenerate unsafe AI candidates internally;
5. repair invalid candidates using database diagnostics;
6. run the first safe, executable candidate and label it truthfully as **Verified pattern** or **AI-built**.

Users judge AI-built report quality after seeing results. Their feedback suppresses bad SQL, improves AI examples, and enables limited same-user reuse. AI-built SQL never becomes Verified merely because it ran successfully or received positive feedback.

## Problem

The current application contains the intended two lanes, but routing and terminal-response decisions remain distributed across the Gemini service, semantic validators, the controller, and the frontend. This allows internal candidate failures to become request-level failures.

For example, this harmless aggregate question currently produces a safety screen:

> For the last three completed fiscal years, summarize the time from purchase-order creation to receipt by vendor. Include vendor, fiscal year, received line count, average days to receipt, median days to receipt, and percentage received within 90 days. Include only vendors with at least 20 received lines.

The question does not request a write. It should be handled by AI when no verified compiler supports it. Today, one unsafe or falsely classified AI candidate can terminate the request without regeneration. The raw-text safety validator can also mistake blocked words inside string literals, comments, or quoted aliases for executable commands.

Adding a dedicated canonical family for this prompt would fix one symptom while preserving the architectural defect.

## Goals

- Preserve and progressively expand verified canonical report compilation.
- Make AI generation the unconditional continuation for ordinary read-only requests that canonical compilation cannot complete.
- Separate user intent, SQL candidate validation, and request termination.
- Never label a harmless analytical request unsafe because one generated candidate was unsafe.
- Run safe, database-valid AI SQL and let users assess its analytical correctness.
- Reuse verified and user-approved SQL within explicit trust boundaries.
- Use successful SQL as ranked AI examples and rejected-candidate diagnostics as repair context without silently promoting either to Verified.
- Remove clarification, correction, resolver, and canonical-coverage blockers from the normal Ask path.
- Preserve database read-only enforcement, authorization, configured protected-data policy, timeouts, cancellation, and resource limits.
- Record enough structured telemetry to diagnose failures without exposing sensitive prompts or SQL in ordinary logs.

## Non-goals

- Building a canonical family for every analytical question.
- Requiring a new semantic plan before AI may generate SQL.
- Treating semantic validation as proof that an AI-built report is correct.
- Automatically promoting AI-built SQL into a canonical compiler.
- Allowing SQL writes from Ask.
- Removing administrator-configured data-access policy.
- Guaranteeing that provider or database infrastructure can always return a report.

## Core invariants

### Canonical is an optimization

A canonical attempt has only two public outcomes:

- success: execute backend-compiled SQL as **Verified pattern**;
- not handled: continue internally to AI.

Every canonical exception, unsupported slot, unresolved reference, semantic warning, manifest drift, compile failure, and preflight failure is normalized to the internal `not_handled` outcome. None is a terminal Ask response.

### Candidate safety is not request safety

The application evaluates two different things:

1. **Request intent:** Did the user explicitly ask Report Explorer to modify database state?
2. **Candidate safety:** Is this particular generated SQL candidate a single read-only query?

An explicitly destructive request is blocked. An unsafe candidate generated for an analytical request is discarded and regenerated. Candidate failure is never presented as evidence that the user's request was unsafe.

If request intent is uncertain, the system continues in read-only reporting mode. SQL enforcement—not a semantic guess—guarantees that no write executes.

### Semantic uncertainty is advisory

Semantic, reference, grouping, grain, and requested-output checks provide repair instructions and result assumptions. They do not block safe executable AI SQL. Even when a validator believes an explicit value or requested output may be missing, the system first attempts regeneration and repair; if safe SQL executes, the result remains available as **AI-built** for user assessment.

### Provenance is immutable and truthful

- Backend-compiled SQL from a fully supported contract is **Verified pattern**.
- AI-generated, AI-repaired, user-edited, or feedback-reused AI SQL is **AI-built**.
- Positive feedback, repeated execution, and administrator approval for reuse do not change AI-built provenance.
- Promotion to Verified requires an implemented backend compiler and its tests.

## Architecture

### Single generation coordinator

Introduce one backend coordinator as the only component allowed to choose a successful lane or produce a terminal generation response. Existing canonical, Gemini, resolver, safety, preflight, and repair services remain focused collaborators.

The controller supplies the request and authorized context to the coordinator. It does not reinterpret service exceptions into competing response shapes. The frontend renders the stable response contract and does not infer safety from missing SQL.

```text
Natural-language request
        |
        v
Read-only intent policy
        |
        v
Eligible query-memory lookup
   | direct match                 | no direct match
   v                              v
Execute reused SQL          Attempt canonical compilation
                                   | success
                                   v
                              Execute Verified
                                   |
                     any miss/failure/preflight issue
                                   v
                         AI generation coordinator
                                   |
                         candidate safety check
                         | unsafe       | safe
                         v              v
                    regenerate     database preflight
                                         | invalid
                                         v
                                  repair or regenerate
                                         |
                                         v
                                  Execute AI-built
                                         |
                                         v
                                feedback and query memory
```

### Coordinator result states

Internal collaborators return typed states rather than public UI responses:

- `handled`: safe executable candidate plus provenance;
- `not_handled`: canonical lane could not represent or validate the request;
- `candidate_rejected`: generated SQL was unsafe, malformed, or invalid and may be regenerated;
- `infrastructure_failure`: provider, authorization, database connection, timeout, cancellation, or resource failure;
- `request_blocked`: explicit write intent or configured data-access policy violation.

Only the coordinator maps these states to the public Ask response contract.

## Detailed request flow

### 1. Request policy

Detect explicit imperative requests to change database state, including insert, update, delete, create, alter, drop, truncate, grant, revoke, copy, call, and equivalent operations.

The hard-gate detector is a deterministic, high-precision heuristic, not model classification. It blocks only when an imperative write verb is coupled to a database object or mutation target, including polite command forms such as “please delete these records” and “can you update this table.” Reporting language is explicitly excluded. A model may annotate intent for telemetry, but its classification cannot block a request.

Analytical uses of the same words do not count as write intent. Examples that remain read-only include:

- “Count records updated last month.”
- “Show deleted inventory records.”
- “Include the purchase-order create date.”

Uncertain intent continues through report generation in enforced read-only mode. The detector is biased toward `uncertain/read_only`; token-aware SQL enforcement and the database read-only transaction provide the actual write protection.

Existing authentication, authorization, and protected-data policy remain independent hard gates. They must return their own policy response, never a canonical or SQL-safety response.

### 2. Query-memory lookup

Direct reuse eligibility is deliberately narrow:

- Verified canonical SQL may be reused across authorized users when scope, schema fingerprint, and parameters are compatible.
- AI-built SQL explicitly marked **Accurate** may be reused directly by the same user for the same normalized question and authorized scope.
- AI-built SQL is not directly reused for another user unless an administrator approves that exact reusable record.
- Administrator approval permits direct reuse but does not change provenance from **AI-built**.
- AI-built SQL with no feedback is never directly reused merely because it executed.
- Any **Inaccurate** feedback immediately suppresses that SQL fingerprint from direct reuse and AI-example selection pending administrator review.

Reused SQL goes through current safety, policy, schema-fingerprint, and database-preflight checks before execution. A failed reuse candidate continues to canonical and AI generation rather than terminating the request.

### 3. Canonical attempt

The coordinator calls the existing canonical router and compiler. A fully compiled, safety-validated, preflighted candidate executes as Verified.

All other outcomes become `not_handled`, including:

- no family match;
- low-confidence classification;
- slot extraction or normalization failure;
- unsupported dimension, measure, filter, date window, grouping, calculation, or output;
- unresolved or ambiguous reference;
- contract, schema-manifest, graph, or semantic-validation failure;
- compiler exception;
- canonical database-preflight failure.

When canonical compilation produced useful SQL before failing, that candidate and its diagnostics may be included in AI context. AI still owns the final SQL, so a successful result is AI-built.

### 4. AI context and examples

AI receives:

- the original question and authorized campus/user scope;
- the live schema and column cache;
- canonical relationship graph;
- domain and vocabulary guidance;
- resolved reference values and unresolved candidates;
- relevant verified examples;
- relevant AI-built examples ranked by feedback and similarity;
- rejected candidate diagnostics when repairing;
- the required PostgreSQL dialect and one-read-only-statement contract.

Example ranking is:

1. verified canonical examples;
2. administrator-approved AI-built examples;
3. same-user Accurate AI-built examples;
4. other explicit Accurate examples as generation context only;
5. successfully executed, unreviewed examples as low-weight context.

Inaccurate and stale-schema examples are excluded.

### 5. Token-aware SQL safety

Replace raw whole-word scanning with token-aware statement validation using the repository's SQL tokenizer/structure service.

The validator must:

- allow one top-level `SELECT` or read-only `WITH ... SELECT` statement;
- reject additional statements outside literals and comments;
- inspect executable tokens in CTE bodies so data-modifying CTEs cannot bypass the gate;
- reject write, DDL, privilege, procedure, and bulk-copy commands;
- ignore blocked words inside string literals, comments, quoted aliases, and quoted output labels;
- retain table-policy and authorization checks as separate validators.

The database connection continues to execute previews inside the existing read-only transaction and bounded-resource controls. Token validation is defense in depth, not the only write protection.

### 6. Candidate regeneration and repair

Unsafe, malformed, unknown-table, unknown-column, syntax, grouping, function, and ordinary preflight failures are candidate-level outcomes.

The AI coordinator uses a bounded, configurable budget consisting of:

- repairs of the current candidate using precise validator or database diagnostics;
- at least one fresh generation that does not preserve a repeatedly broken SQL shape.

An unsafe candidate is never repaired by editing it locally and executing without another complete safety check. Every replacement repeats safety, table policy, schema validation, and preflight.

If the budget is exhausted, return `sql_generation_failed` with Retry and the message `Report Explorer could not build a valid report after retrying. Please retry.` The response must not ask the user to clarify, correct, or rewrite the request.

Retire `unsafe_generated_sql` from the backend response contract. Explicit destructive intent returns `request_blocked` before generation with a read-only reporting message. Unsafe generated candidates remain internal `candidate_rejected` states. During a rolling deployment, the frontend may continue recognizing legacy `unsafe_generated_sql` responses from an older backend, but the new coordinator never emits that type.

Provider, connectivity, cancellation, authorization, timeout, and configured resource failures retain their accurate typed responses.

### 7. Execute and label

The first safe, policy-allowed, preflighted candidate runs as a bounded preview.

The response contains:

```json
{
  "generationProvenance": "verified_pattern | ai_built",
  "provenanceLabel": "Verified pattern | AI-built",
  "sql": "SELECT ...",
  "explanation": "...",
  "assumptions": [],
  "queryMemory": {
    "reused": false,
    "sourceJobId": null,
    "reuseTrust": null
  }
}
```

Internal route, failure, repair, and example identifiers remain administrator evidence and do not determine the results layout.

## User feedback and correction

Successful AI-built results display **Accurate**, **Inaccurate**, and **Unsure** actions.

### Accurate

- Records an explicit positive assessment tied to user, job, question fingerprint, SQL fingerprint, schema fingerprint, scope, and provenance.
- Makes the record eligible for same-user direct reuse.
- Makes it a stronger AI example for other users.
- Does not relabel it Verified.

### Inaccurate

- Records a strong negative assessment and optional note.
- Immediately suppresses the SQL fingerprint from reuse and AI examples.
- Leaves the current results visible for comparison.
- Displays a prominent **Try different SQL** action.

**Try different SQL** sends the original question, rejected SQL, result metadata, and feedback note through fresh AI generation. It does not route through clarification and does not place correction instructions into the Ask input.

### Unsure and no feedback

- `Unsure` and no response are neutral.
- Neutral AI-built SQL is not directly reused.
- Saving, downloading, rerunning, or asking a follow-up is a weak positive ranking signal for AI examples, not an accuracy assertion.

## Reuse user experience

Eligible reusable SQL runs automatically with no intermediate confirmation screen.

The results page shows a compact note stating that an existing query was reused, its unchanged provenance, and its trust source. The user can:

- edit and run the SQL, which produces AI-built provenance;
- request **New AI SQL**, which bypasses the reuse candidate and starts fresh AI generation;
- provide accuracy feedback.

A failed or stale reuse candidate silently continues through canonical and AI generation.

## Telemetry and administrator evidence

Record structured events at each coordinator boundary:

- request intent outcome;
- reuse candidate selected, suppressed, stale, or failed;
- canonical attempted, handled, or not-handled category;
- AI generation and fresh-generation counts;
- candidate rejection stage and normalized reason;
- blocked executable command, when present;
- SQL hash and schema fingerprint;
- preflight and repair category;
- final provenance;
- feedback and example-ranking changes.

Ordinary logs do not contain raw prompts, raw SQL, patron data, or result rows. Rejected SQL may be retained only in the existing access-controlled generation/review evidence store according to its retention rules.

Every terminal response has a logged coordinator outcome. The current silent `unsafe_generated_sql` path is removed.

## Public error behavior

Normal read-only requests never show clarification, correction, request-preserved, canonical-coverage, or semantic-recovery screens.

Public terminal categories are limited to accurate conditions:

- explicit write request (`request_blocked`);
- configured protected-data or authorization policy;
- provider unavailable or timed out;
- database unavailable, cancelled, or resource-limited;
- no safe executable SQL after the complete generation budget.

Explicit request-level write intent uses a direct read-only-policy message such as `Report Explorer runs read-only reports and cannot modify database data.` Protected-data and authorization failures use their own policy messages. Candidate-level safety failures are invisible to users unless all generation attempts fail, in which case the `sql_generation_failed` message describes generation exhaustion rather than calling the request unsafe. `Report Explorer could not safely run this report` is legacy copy and is not emitted by the new coordinator.

## Data changes

Extend the existing query-feedback and reuse records rather than creating an independent learning system. Persist or derive:

- user identifier;
- normalized question fingerprint;
- SQL fingerprint;
- schema fingerprint/version;
- authorized campus and scope fingerprint;
- provenance;
- feedback status and note;
- suppression status and reason;
- same-user reuse eligibility;
- administrator reuse approval and reviewer;
- weak interaction counters or events;
- source and replacement job lineage.

Database migrations must preserve existing feedback and reuse history. Existing rows without explicit feedback default to neutral and are not directly reusable as AI-built SQL.

## Rollout

Implement behind one server-side coordinator flag, enabled by default after tests pass. The rollback path selects the previous orchestrator but does not change stored provenance or feedback.

Roll out in two implementation phases:

### Phase 1: routing and safety reliability

1. Add the single generation coordinator boundary.
2. Add conservative deterministic request-level write-intent detection.
3. Normalize every canonical failure to internal `not_handled`.
4. Regenerate unsafe AI candidates for read-only requests.
5. Replace raw-text safety scanning with token-aware validation.
6. Retire backend `unsafe_generated_sql` emission while retaining temporary frontend compatibility.
7. Preserve the distinct `sql_generation_failed` exhaustion copy and update obsolete safety assertions explicitly.
8. Add coordinator telemetry and stable public failure mapping.
9. Remove remaining normal-flow blocker rendering.

### Phase 2: feedback-ranked query memory

1. Add feedback/reuse trust fields and migration.
2. enforce same-user Accurate and administrator-approved direct-reuse rules;
3. rank eligible prior SQL as AI context;
4. suppress Inaccurate and stale-schema candidates;
5. add **Try different SQL** with rejection lineage;
6. show reuse trust details on results;
7. add administrator reuse approval without changing provenance.

Phase 1 ships independently and fixes the immediate false safety failures. Phase 2 builds the learning loop on the stable coordinator.

## Testing strategy

### Routing matrix

Tests cover every coordinator state transition:

- reuse success, stale reuse, unsafe reuse, and reuse preflight failure;
- canonical success;
- no family, unsupported capability, unresolved reference, semantic rejection, compiler exception, and canonical preflight failure;
- direct AI success;
- unsafe first AI candidate followed by safe fresh generation;
- invalid candidate followed by database-guided repair;
- exhausted generation budget;
- explicit destructive intent;
- analytical phrases containing mutation vocabulary, including “update me on circulation,” “records updated last month,” and “deleted inventory records”;
- provider, authorization, connectivity, cancellation, timeout, and resource failure.

### Safety regressions

- `SELECT 'update' AS note` is accepted.
- `SELECT 1 AS "Create"` is accepted.
- blocked words in comments are ignored.
- reporting prompts containing update, created, or deleted vocabulary are not classified as destructive intent.
- imperative requests to insert, update, delete, or alter database state return `request_blocked` before generation.
- `WITH changed AS (DELETE ... RETURNING ...) SELECT ...` is rejected.
- multiple executable statements are rejected.
- executable INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE, CREATE, GRANT, REVOKE, CALL, DO, COPY, VACUUM, and ANALYZE commands are rejected.
- every regenerated candidate is validated again.

### Required prompt regressions

The following ordinary read-only prompts must reach a result or accurate infrastructure/generation failure, never clarification or `unsafe_generated_sql`:

- `For the last three completed fiscal years, summarize the time from purchase-order creation to receipt by vendor. Include vendor, fiscal year, received line count, average days to receipt, median days to receipt, and percentage received within 90 days. Include only vendors with at least 20 received lines.`
- `Show annual checkout counts at Neilson Library for each of the last five completed calendar years.`
- `Show the age of the Book collection at Neilson Library by primary LC call-number class. Include title count, average publication year, oldest publication year, and newest publication year.`
- a novel cross-domain request unsupported by every canonical family.

The vendor receipt-time regression specifically supplies an unsafe first AI candidate and a safe second candidate, proving that candidate safety does not terminate a harmless request.

### Feedback and memory regressions

- Accurate AI-built SQL becomes directly reusable only by the same user and compatible scope.
- Another user receives Accurate AI-built SQL only as generation context.
- Administrator approval enables cross-user direct reuse without changing AI-built provenance.
- Inaccurate immediately suppresses exact SQL reuse and example selection.
- Neutral and weak-positive records are not directly reused.
- stale schema fingerprints are not reused or supplied as examples.
- **Try different SQL** creates replacement lineage and excludes the rejected candidate.

### End-to-end UI regressions

- no intermediate reuse, clarification, correction, or exploratory recovery screen;
- successful results always show exactly one provenance label;
- reused results show a compact reuse note and **New AI SQL**;
- Inaccurate preserves results and displays **Try different SQL**;
- generated replacements remain AI-built;
- request-level policy and infrastructure messages remain accurate.

## Acceptance criteria

- Canonical coverage can increase over time without changing what users are allowed to ask.
- Every non-policy canonical failure automatically reaches AI.
- An unsafe AI candidate for a read-only request triggers regeneration, not a safety screen.
- The SQL validator ignores blocked words in literals, comments, and quoted aliases while rejecting executable writes, including data-modifying CTEs.
- Safe, policy-allowed, preflighted AI SQL runs as AI-built even when semantic analysis is uncertain.
- The new backend never emits `unsafe_generated_sql`; the frontend accepts it only as temporary rolling-deployment compatibility.
- Ordinary read-only requests never produce clarification, correction, request-preserved, `request_blocked`, or legacy `unsafe_generated_sql` responses.
- Verified, AI-built, edited, reused, and administrator-approved provenance remains truthful.
- Positive, negative, neutral, and weak feedback signals affect reuse and AI-example ranking according to this design.
- No existing authentication, authorization, protected-data, read-only transaction, timeout, cancellation, or resource-limit protection is weakened.
- The required vendor receipt-time prompt proves the end-to-end candidate-regeneration path.
