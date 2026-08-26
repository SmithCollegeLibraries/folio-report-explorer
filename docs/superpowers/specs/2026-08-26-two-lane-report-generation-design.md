# Two-Lane Report Generation Design

**Date:** 2026-08-26
**Status:** Approved in conversation; pending written-spec review

## Summary

Report Explorer will generate reports through two user-visible lanes:

1. **Verified pattern** — AI produces structured intent and the backend compiles SQL from a supported canonical contract.
2. **AI-built** — when no canonical contract applies, or any non-safety step in the canonical lane fails, AI generates complete SQL using the application's schema, relationship, vocabulary, reference, and training context.

Both lanes lead to the same result experience. The application must not expose canonical matching failures, unresolved semantic proofs, repair instructions, or prompt-refinement requests as user-facing blockers. Every successful report must display its provenance as either **Verified pattern** or **AI-built**.

Only failures that prevent safe execution may stop a report: prohibited SQL, prohibited data access, invalid SQL that cannot be repaired, database connectivity or execution failure, cancellation, or configured resource limits.

## Background and regression history

The original structured-intent flow already implemented the core behavior required by this design. AI generated an intent, the backend built SQL when the intent was supported, and unsupported intent or builder conversion failures automatically used freeform AI SQL generation.

The request path became progressively more restrictive:

- `1bc3f36` introduced deterministic query families while retaining AI fallback.
- `8c72d67` guarded covered-family fallback and prevented some automatic AI recovery.
- `3ff5e51` introduced clarification-oriented user interaction.
- `e049beb` added reference-cache resolution and additional pre-generation clarification.
- `46186b0` added the exploratory recovery screen.
- `5178611` made semantic conformance a prerequisite for preflight.
- The July 27–28 reference-intent and resolved-filter changes expanded fail-closed behavior.

These changes supplied valuable schema knowledge, reference data, safety controls, preflight, telemetry, and deterministic compilers. They must not be reverted wholesale. This design restores the earlier routing contract while retaining those improvements.

## Goals

- Produce a report whenever safe executable SQL can be generated.
- Preserve canonical SQL compilation for requests fully supported by a verified pattern.
- Automatically use AI-built SQL for all other requests and for non-safety canonical failures.
- Make report provenance visible on every successful result.
- Use downloaded FOLIO reference data as generation context instead of a pre-generation conversation gate.
- Keep safety, privacy, table-policy, preflight, timeout, and cancellation controls.
- Make semantic analysis and reference validation inputs to automatic repair and visible assumptions, not user-facing blocker screens.
- Support conversational report revisions after results are visible.
- Retain generation lineage and telemetry so successful AI-built reports can inform future verified patterns.

## Non-goals

- Reverting the repository to a historical commit.
- Removing SQL safety or restricted-table enforcement.
- Claiming that AI-built SQL is verified.
- Automatically promoting AI-built SQL into a canonical pattern.
- Completing the report-revision interface in the first emergency routing release.
- Guaranteeing that every safe query returns correct business semantics; AI-built provenance and assumptions communicate that distinction.

## Product principles

### Internal uncertainty is not a user task

The user should not have to understand query families, resolver dimensions, compiler coverage, CTE restrictions, semantic validators, or repair prompts. Failures in those components are routing signals.

### Canonical patterns are accelerators, not gates

A canonical pattern provides repeatable interpretation and backend-generated SQL. It does not define the complete boundary of Report Explorer's capabilities.

### Provenance is always visible

Every successful report displays exactly one of these labels near the report title and results:

- **Verified pattern**
- **AI-built**

The label is not hidden in an advanced details panel. Technical routing reasons remain available in administrator telemetry and generation history.

### Corrections happen after results

The application builds and previews a report first. A user may then refine that report conversationally. The application does not stop before generation to ask the user to repair its parser or restate a prompt.

## Architecture

### Request flow

```text
Natural-language request
        |
        v
Build generation context
  - schema and column cache
  - canonical relationship graph
  - table descriptions and domain guidance
  - libraries, campuses, locations, material types, and other references
  - accepted training examples and corrections
  - campus and user-authorized scope
        |
        v
Attempt canonical interpretation and compilation
        |
   +----+-------------------------+
   |                              |
success                       no match or
   |                          non-safety failure
   v                              |
Verified pattern                  v
backend SQL compiler         AI-built SQL generator
   |                              |
   +--------------+---------------+
                  v
          hard safety validation
                  v
        database syntax/preflight
                  v
          bounded result preview
                  v
 results + provenance + assumptions + Refine this report
```

### Generation context

Reference and schema services produce a context bundle rather than a blocking response. The bundle includes:

- authoritative resolved values and their dimensions;
- high-confidence candidate values;
- unresolved raw terms;
- canonical table relationships;
- relevant schema tables, columns, types, and sample vocabulary;
- applicable domain and campus-scope rules;
- approved examples and prior corrections;
- the original request and, for revisions, the prior generation lineage.

Reference resolution may mark confidence and alternatives, but it must not return a user-facing `needsClarification` response during normal report generation.

### Lane 1: Verified pattern

The canonical lane performs these steps:

1. Classify the request against supported query families.
2. Ask AI for the structured slots or general structured intent required by the matched contract.
3. Normalize high-confidence reference values into the intent.
4. Validate the intent against the canonical contract and graph.
5. Compile SQL in the backend.
6. Apply hard safety validation and database preflight.
7. Return the result with provenance **Verified pattern**.

A report is verified only when the final executable SQL was produced by a supported backend compiler and all contract requirements passed.

The following conditions immediately route to Lane 2 instead of returning a clarification, exception, or recovery response:

- no query family matches;
- required canonical slots cannot be confidently extracted;
- AI returns the wrong family or invalid intent JSON;
- a requested filter, output, time window, grouping, or calculation is outside the family contract;
- canonical graph or schema-manifest validation fails;
- backend compilation fails;
- a semantic validator cannot prove the compiled SQL shape or reports a likely mismatch;
- canonically compiled SQL fails database syntax or preflight validation;
- a resolved reference cannot be represented by the compiler.

These conditions mean "not verified," not "unsafe."

When canonical compilation has already produced safe SQL, Lane 2 must reuse that SQL as its initial candidate rather than discard it and generate from scratch. AI receives the candidate, original request, generation context, and semantic or preflight diagnostic, then repairs it or explicitly returns it unchanged. The resulting report is labeled **AI-built** because AI owns the final executable candidate. A canonical preflight failure becomes a hard failure only if the AI lane also exhausts its repair budget without producing safe executable SQL.

### Lane 2: AI-built

The AI-built lane receives the complete generation context and creates one executable `SELECT` statement plus:

- a concise explanation;
- a structured list of assumptions;
- the reference values it interpreted;
- a data-source declaration.

AI generation may begin because no pattern matched or because Lane 1 failed. The user does not approve the transition and does not see an intermediate fallback screen.

The AI-built result is automatically checked, preflighted, and run as a bounded preview. It is labeled **AI-built** whether it succeeds on the first attempt or after automatic repair.

### Automatic repair

Parse, schema, preflight, and semantic diagnostics are repair inputs. They are not copied into the user interface.

The repair loop receives:

- the original request;
- generated SQL;
- generation context;
- database or validator diagnostic;
- resolved references;
- prior repair attempts.

Repairs continue within the configured attempt budget. If a safe executable query is produced, it runs as **AI-built**. If the budget is exhausted because the SQL remains invalid or unsafe, the request ends with a concise technical failure and retry action. It must not ask the user to rewrite, clarify, or correct the original request.

## Validation policy

### Hard execution gates

The following remain blocking:

- non-`SELECT` SQL;
- multiple SQL statements;
- blocked keywords or write operations;
- restricted schemas, tables, columns, or patron-sensitive data;
- invalid table references that cannot be repaired;
- database syntax/preflight failure after automatic repair;
- provider or database connectivity failure;
- cancellation, timeout, or configured resource limit;
- authorization failure.

Hard failures use plain messages such as "Report Explorer could not safely run this report" with **Retry** and administrator-visible diagnostics. They do not expose internal correction instructions.

### Advisory semantic checks

These checks may trigger automatic repair or an AI-built assumption but may not produce a blocker screen:

- canonical family mismatch;
- unsupported canonical output or filter;
- unresolved or ambiguous named reference;
- semantic validator unable to analyze a CTE or SQL construct;
- expected reference value not provable from the SQL parser;
- uncertain join, grouping, date, or material interpretation;
- repeatability warning for AI-built SQL.

If a semantic check positively identifies a likely error, the system routes the SQL through the AI lane's seeded-candidate repair process. The SQL is never merely relabeled without AI review. If AI returns safe executable SQL, it runs as **AI-built**, with the relevant interpretation shown as an assumption.

## Reference resolution behavior

The downloaded reference cache remains authoritative context.

- A unique high-confidence match is supplied as the canonical value.
- Multiple plausible matches are supplied to AI as ranked candidates.
- An unresolved term is supplied verbatim with any nearby candidates.
- Accepted historical aliases are supplied as context.
- Resolved values and AI interpretations are shown in result assumptions where they materially scope the report.

The resolver must not treat an entire analytical phrase as a named term and ask the user what it means. Parser failures route to AI with the raw request and reference candidates.

## User experience

### Removed normal-flow screens

The normal Ask flow no longer renders:

- **Clarification needed**;
- **The request is preserved**;
- **What still needs to be resolved**;
- resolver-check diagnostics;
- generated correction instructions;
- prompt-refinement suggestions required before generation;
- exploratory approval.

These response shapes may be retained temporarily for API compatibility during migration, but the new router must not produce them for ordinary report requests.

### Successful results

Every successful result shows:

- **Verified pattern** or **AI-built** prominently;
- the result preview;
- a concise explanation;
- material assumptions and resolved scope;
- SQL in the existing SQL/details view;
- **Refine this report** beneath the results.

The two lanes share the same results layout. AI-built reports are not presented as errors or degraded fallback screens.

## Report revisions

Report revisions are the second implementation phase.

The **Refine this report** input sends a revision instruction and parent generation identifier. It does not concatenate the original question and correction into the visible Ask input.

The server loads the prior:

- original request;
- structured intent, if present;
- SQL and output columns;
- provenance;
- resolved references;
- assumptions;
- generation and query-job identifiers.

If the prior report is verified and the revision remains representable by its contract, the semantic layer updates the structured intent and recompiles it as **Verified pattern**. Otherwise the revision automatically becomes **AI-built** and AI receives the prior report plus the revision instruction.

Each revision creates a new generation linked to its parent. The prior report remains available in history.

## Response contract

Successful generation responses expose a stable provenance field independent of internal route names:

```json
{
  "generationProvenance": "verified_pattern | ai_built",
  "provenanceLabel": "Verified pattern | AI-built",
  "sql": "SELECT ...",
  "explanation": "...",
  "assumptions": [],
  "generationId": "...",
  "parentGenerationId": null
}
```

Internal fields such as `route`, `routeReason`, compiler version, repair count, and query family remain available for telemetry and administrator review but do not control whether a successful result looks like an error.

## Telemetry and learning

Record, without logging sensitive prompt contents:

- canonical family attempted and selected;
- canonical success or fallback category;
- AI-built generation success;
- automatic repair count and categories;
- hard execution failure category;
- provenance shown to the user;
- user accuracy feedback;
- revision lineage;
- successful AI-built query fingerprints eligible for administrator review.

AI-built reports are not promoted automatically. Administrators may review repeated successful patterns and intentionally add or update canonical contracts.

## Implementation phases

### Phase 1: Immediate routing restoration

1. Introduce a single two-lane orchestration decision in the Ask backend.
2. Preserve canonical success as **Verified pattern**.
3. Convert all non-safety canonical failures into automatic AI generation.
4. Convert resolver clarification output into generation context.
5. Make semantic validation advisory for AI-built execution and automatic repair.
6. Retain all hard safety, authorization, preflight, timeout, cancellation, and resource controls.
7. Add stable provenance fields and always-visible labels.
8. Stop producing and rendering normal-flow blocker/recovery responses.
9. Add end-to-end regression coverage for canonical success, canonical-to-AI fallback, and direct AI generation.

### Phase 2: Report revisions

1. Add **Refine this report** beneath successful results.
2. Send a revision instruction plus parent generation ID.
3. Load prior context server-side and route the revision through the same two lanes.
4. Persist generation lineage and show revised results without rewriting the Ask input.
5. Retain optional user accuracy feedback and administrator promotion workflows.

## Testing strategy

### Backend routing tests

- A fully supported canonical request returns **Verified pattern**.
- An unsupported request automatically returns **AI-built** without clarification.
- A matched family whose compiler fails automatically returns **AI-built**.
- A family request with unsupported outputs or dates automatically returns **AI-built**.
- Ambiguous and unresolved references are passed into AI context and do not return `needsClarification`.
- AI-generated SQL receives cached reference and schema context.
- Semantic uncertainty triggers repair or assumptions rather than recovery.
- Unsafe SQL and prohibited table access remain blocked.
- Invalid SQL receives automatic repairs before a concise terminal failure.

### Required regression prompts

- `Show me the 20 most-circulated books at Neilson Library during the last five years. Include title, call number, publication year, checkout count, and most recent checkout date.`
  - This must produce an **AI-built** result until a canonical family supports every requested constraint and output.
  - It must not display clarification or recovery screens.
- `Show me a list of VHS and DVDs at Hillyer Library.`
  - This should produce **Verified pattern** when its canonical contract succeeds.
- A novel cross-domain request with no family match must produce **AI-built**.

### Frontend tests

- Every successful report renders exactly one provenance label.
- Clarification and exploratory-recovery components do not render in the normal Ask flow.
- AI-built results use the normal results layout.
- Hard failures provide Retry without prompt-correction instructions.
- Phase 2 revisions retain parent generation lineage and do not rewrite the Ask input.

## Rollout and compatibility

Phase 1 should ship before Phase 2 because it immediately restores report availability.

Use a server-side rollout switch for the new orchestrator so deployment can be reversed without reverting unrelated safety work. The two-lane route should be enabled by default after its routing and safety regression suites pass. Existing internal route fields and stored generation records remain readable during migration.

The legacy strict blocker path is a rollback mechanism only; it is not exposed as a user preference.

## Acceptance criteria

- Ordinary report requests never display clarification, exploratory approval, request-preserved, or correction-required screens.
- Every safe successful request returns a preview labeled **Verified pattern** or **AI-built**.
- Canonical failures automatically attempt AI generation.
- Reference-resolution uncertainty is supplied to AI and shown as an assumption when relevant.
- The Neilson five-year top-circulation regression prompt reaches an AI-built preview rather than a blocker.
- Existing SQL write protection, restricted-table policy, authorization, preflight, timeout, cancellation, and resource-limit tests continue to pass.
- Technical failure messages never instruct users to repair internal SQL or rewrite a request for canonical coverage.
- Phase 2 revisions preserve lineage and route through the same two-lane orchestrator.
