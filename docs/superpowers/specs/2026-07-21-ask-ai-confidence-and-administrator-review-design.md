# Ask AI Confidence and Administrator Review Design

**Date:** 2026-07-21  
**Status:** Approved after code review

## Purpose

Ask AI is the reporting assistant for people who do not know SQL, the FOLIO schema, or how database queries are built. It must help users make progress even when their questions are incomplete, exploratory, obscure, or analytically complex.

Schema Explorer and Builder remain the intentional technical workspaces for users who want to select tables, manage joins, choose columns, and build filters. Ask AI must not expose those implementation details as troubleshooting tasks or redirect a confused user into a technical workflow.

The system will use transparent execution with asynchronous administrator review. Technically safe reports run without an approval gate. Confidence affects explanation and review priority, not whether an otherwise safe report executes.

## Product Principles

1. **Run first when safe.** Analytical uncertainty alone does not block execution.
2. **No technical roadblocks.** Users are never asked to diagnose SQL, schemas, tables, columns, joins, validators, or database errors.
3. **Ask only answerable questions.** Clarification is limited to reporting meaning and is used only when no responsible documented default exists.
4. **Use AI for the long tail.** Novel, obscure, cross-domain, and multilayered questions remain legitimate Ask AI use cases.
5. **Be transparent without creating work.** AI-assisted results explain material assumptions and limitations without requiring acknowledgment or approval.
6. **Separate confidence from execution eligibility.** A low-confidence interpretation may execute; unsafe, invalid, destructive, semantically nonconforming, or impossible queries may not.
7. **Preserve explicit intent.** Concrete identifiers, requested fields, dates, scopes, and limits are retained exactly.
8. **Review asynchronously.** Low-confidence and unsuccessful requests are flagged for administrators without delaying ordinary user results.
9. **Do not rewrite history.** Administrator review never silently changes a completed result.

## Product Boundary

Ask AI and the technical tools serve different user intentions:

- **Ask AI:** conversational reporting for nontechnical users, including users who are still discovering what they need.
- **Builder:** intentional control over fields, filters, relationships, and query construction.
- **Schema Explorer:** technical investigation of available data and relationships.

Ask AI may offer an optional advanced action such as viewing generated SQL, but technical details are never required to understand a result, refine a request, or recover from a failure. The application must not present Builder as the default recovery path for an Ask AI problem.

## Selected Approach

The selected approach is **transparent execution with administrator review**.

Alternative approaches were rejected:

- Holding low-confidence reports for administrator approval creates delays and user roadblocks.
- Requiring users to approve every AI interpretation adds friction and asks newcomers to make decisions they may not understand.

The selected approach executes every technically safe and executable report. It varies user-facing explanation according to analytical confidence and automatically creates administrator review items when evidence indicates material uncertainty.

## Relationship to Existing Behavior and Specifications

This design extends the current Ask AI pipeline rather than describing behavior that already exists in full.

Existing foundations that remain authoritative are:

- the five query-family contracts in `backend/data/query_family_contracts.json` and their application-owned compilation through `QueryFamilyCompilerService`;
- exploratory SQL generation, the two-repair budget, safety and table-policy enforcement, schema-reference validation, semantic conformance, and PostgreSQL preflight;
- documented exploratory defaults and domain-language clarification;
- `ai_clarification_events` for clarification learning, `ai_query_feedback` for explicit user accuracy feedback, and reference-cache candidate review for reference-data governance; and
- query-job execution status and cancellation semantics.

The following capabilities are greenfield work required by this design:

- evidence-based confidence classification;
- system-generated administrator report-review items and their lifecycle;
- persisted generation and validation provenance for review;
- general explicit-identifier and requested-field preservation outside the narrow existing query-family slot policies;
- structural comparison of an initial AI candidate with a repaired candidate; and
- nontechnical recovery payloads that replace current validator-category presentation.

This design does not weaken the blocking semantic-conformance gate defined by the 2026-07-18 Ask AI semantic-conformance specification. Every applicable blocking requirement must have validator coverage and pass. A failed requirement or `semantic_coverage_gap` remains an unable-to-execute outcome after bounded repair; it is never converted into a warning-only result. “Weak or partial semantic coverage” in this design means only that all detected blocking requirements passed but the contract covers a limited portion of the broader exploratory analysis.

This design also supersedes earlier Ask AI copy instructing ordinary users to review SQL before using results. Generated SQL remains available through an intentional optional **View SQL** or advanced-details action, and it may continue to travel internally as follow-up context, but inspecting SQL is not a user responsibility or a recovery requirement.

The obsolete `needsExploratoryApproval` response flag must be retired from backend responses, frontend types, conditional rendering, and tests. There is no replacement approval gate.

## Compatibility Vocabulary

This design does not add another query-job execution status. It retains existing route and validation vocabulary, introduces a persisted generation-level `execution_mode`, normalizes deterministic backend responses to emit `mode: canonical`, and adds one administrator-review decision.

| User-facing outcome | Required response `mode` | Persisted `execution_mode` in `ai_report_generations` | Existing route/HTTP outcome | `validationSummary.status` | `reviewRequired` | Execute |
|---|---|---|---|---|---|---|
| Verified deterministic | `canonical` — new backend normalization | `deterministic` — new persisted field | `builder_intent` with `family_contract_supported:*` | `validated` | `false` | Yes |
| AI-assisted | `exploratory` — already emitted | `exploratory` — new persisted field | normally `exploratory_legacy_freeform` | `validated` | `false` | Yes |
| AI-assisted, review flagged | `exploratory` — already emitted | `exploratory` — new persisted field | normally `exploratory_legacy_freeform` | `validated` | `true` | Yes |
| Clarification required | absent/null | null | `clarification` with `needsClarification: true` | absent | `false` | No |
| Policy blocked | absent/null | null | HTTP 403; early policy responses currently have no route | absent | `false` | No |
| Unable to execute after generation/repair | applicable mode or null | `exploratory`, `deterministic`, or null according to the attempted path | existing recovery route | `exhausted` or `rejected` | `true` | No |

`route` and `routeReason` continue to explain how processing occurred when the response has entered routed generation. `validationSummary.status` continues to describe whether a generated candidate passed execution validation; it is absent when no candidate reached validation. The backend must begin emitting `mode: canonical` for successful deterministic query-family results because only `mode: exploratory` is emitted today. `execution_mode` is a greenfield column in `ai_report_generations`, aligned with but not dependent on the unimplemented 2026-07-14 semantic-layer design. `reviewRequired` is a new boolean decision, not a confidence enum and not an execution state. Review reasons are stable internal keys stored with the administrator review item.

The current hardened physical ROI compiler remains an exploratory compiled fallback with compiler version `physical_roi_v2`; it is not reclassified as a canonical query family by this design. Therefore the verified deterministic tier initially applies only to the existing five query families. Finance, ROI, and other cross-domain analysis normally remain AI-assisted or AI-assisted with review until separately promoted through approved canonical artifacts.

## Confidence and Execution Outcomes

Confidence is determined from application evidence. The AI provider cannot declare its own output trustworthy. Any present or future NL-to-SQL model confidence field must be discarded before classification. The current model-generated `confidence` field used by index recommendations is a separate feature and is outside this report-confidence pipeline.

### Verified deterministic

An approved report contract and deterministic compiler produced the query.

- Execute normally.
- Do not show a confidence banner.
- Do not create a confidence-based review item.
- Preserve normal provenance and operational telemetry.

### AI-assisted

AI interpretation was required, and the query passed safety, schema, policy, applicable semantic, and database-preflight checks without material unresolved analytical risk.

- Execute automatically.
- Show a small AI-assisted notice.
- Display only concise assumptions that affect interpretation.
- Do not require acknowledgment or confirmation.

### AI-assisted, review flagged

The query is safe and executable, but material analytical uncertainty remains.

- Execute automatically.
- Show a more visible plain-language limitation.
- Create an administrator review item for asynchronous administrator handling.
- Do not show queue state, approval state, or technical diagnostics to the user.

Review-triggering evidence includes:

- multiple reasonable business definitions;
- cross-domain or multilayer aggregation;
- incomplete or proxy record linkage;
- inferred institutional, collection, date, or population scope;
- limited but passing semantic-contract coverage after every detected blocking requirement has validator coverage and passes;
- obscure or unresolved collection terminology;
- automatic repair that materially changed the proposed query;
- known limitations in source data or historical coverage; and
- other deterministic evidence that the result may be analytically fragile.

### Unable to execute safely

The system cannot produce technically valid and safe SQL, or the calculation requires data the application does not possess.

- Do not execute.
- Create an administrator review item automatically.
- Explain the limitation in ordinary reporting language.
- Offer safe partial reports, alternative interpretations, or a conversational refinement when available.
- Do not expose rejected SQL or technical failure details.

Low confidence by itself is never a reason to withhold execution. Destructive output, policy violations, invalid SQL after bounded repair, failed blocking semantic requirements, semantic coverage gaps, failed preflight, or unavailable required data remain hard execution boundaries.

## User Experience

Ask AI uses a continuous, low-friction interaction model.

### Normal results

Verified deterministic results appear without additional confidence messaging. The interface should not add reassurance where no material uncertainty needs to be communicated.

### AI-assisted results

The result appears immediately after validation. A short notice explains only what affects use of the report. Examples include:

- “I interpreted ‘unused’ as no recorded checkout during the selected period.”
- “Purchase-to-item links are incomplete, so some totals use title-level matching.”
- “This report compares paid spending with recorded physical-item circulation; electronic usage is not included.”

The notice is informational. It has no confirmation control and does not block viewing, exporting, saving, refining, or rerunning the result.

### Clarification

The system applies an approved documented default whenever doing so is responsible and discloses that assumption with the result. It asks a clarification question only when no responsible default exists and the answer materially changes the report.

Clarifications use domain language. For example:

> “Unused” could mean no checkout during the selected period or no recorded checkout in the available history. Which do you mean?

Clarifications never ask the user to select a table, join, column, data type, SQL operator, or validator outcome.

### Unable-to-run states

The user receives a useful reporting explanation rather than an implementation failure. For example:

> I couldn't verify a source for electronic-resource usage, so I can't calculate a reliable return on investment. I can still show spending by vendor, renewal date, and fund.

Messages must not include table names, column names, PostgreSQL errors, SQL classifications, stack traces, validator categories, validator stages, internal requirement keys, or repair internals.

This is explicit remediation of current shipping behavior. Ordinary recovery responses must stop serializing `validationSummary.failureCategory` and `validationSummary.validatorStage`. User-readable unmet requirements may remain, but the response contains display text rather than internal key/label pairs. The frontend must remove the “Safe failure category” presentation and other technical error-formatting branches. Complete categories, stages, and stable requirement keys are retained only in administrator review evidence and protected telemetry.

The original request, follow-up context, and accepted assumptions remain available so the user does not have to start again.

### Explicit-values fast path

A request containing explicit identifiers and fields, such as “show these instance numbers with these fields,” takes a narrow explicit-values path. This is a new general capability; the current `explicit_prompt_only` query-family slot policy is only a limited precedent.

- preserve all supplied identifiers exactly;
- preserve the requested field list and limits;
- avoid adding inferred scope or filters unless required by policy;
- resolve field language to approved semantic identifiers;
- generate the narrowest report that satisfies the request; and
- explain only material transformations or unavailable fields.

## Request Processing Architecture

Ask AI processes every request through the following stages:

1. **Interpret the request.** Extract explicit identifiers, requested outputs, dates, scopes, and limits. Resolve approved local terminology and apply documented defaults.
2. **Try verified reporting.** Match the request to an approved deterministic contract and compiler.
3. **Use exploratory AI when needed.** Interpret novel, obscure, or multilayered questions and propose a report plan or query.
4. **Validate internally.** Enforce SQL safety, table policy, schema references, institutional scope, aggregation grain, applicable semantic rules, and database preflight.
5. **Repair internally.** Apply bounded automatic repair to repairable validation failures without exposing the process to the user.
6. **Classify analytical confidence.** Evaluate deterministic evidence independently from execution safety.
7. **Execute when technically permissible.** Analytical uncertainty does not block an otherwise valid query.
8. **Explain at the user's level.** Add only the assumptions and limitations needed to interpret an AI-assisted result.
9. **Flag review without blocking.** Persist an administrator review item when confidence evidence crosses the review threshold or execution cannot proceed.

## Component Responsibilities

### Request interpreter

- Extracts and preserves explicit facts.
- Resolves approved reference values.
- Applies versioned documented defaults.
- Identifies the minimum unresolved reporting meaning.
- Produces no user-facing technical diagnostics.

### Routing service

- Selects a verified deterministic contract when supported.
- Routes remaining legitimate reporting questions to exploratory processing.
- Records the selected mode and reason.
- Does not treat deterministic coverage as the product's capability boundary.

### Exploratory coordinator

- Requests AI interpretation or SQL generation for the long tail.
- Supplies relevant schema context and documented assumptions.
- Coordinates bounded repair using sanitized internal evidence.
- Preserves the original question and accumulated context across attempts.

### Validation boundary

- Is the sole authority on whether execution is technically permissible.
- Enforces safety, policy, schema, semantic, and preflight requirements.
- Distinguishes repairable failures from hard stops.
- Distinguishes a valid zero-row result from a query that was not validated.

### Confidence classifier

- Uses deterministic evidence rather than model self-assessment.
- Classifies verified, ordinary AI-assisted, and review-flagged AI-assisted results.
- Considers routing, defaults, unresolved ambiguity, semantic coverage, repair history, linkage quality, preflight outcome, and known data limitations.
- Does not block execution.
- Returns `reviewRequired` plus stable review-reason keys; it does not create a new execution-mode or validation-status enum.

### User explanation service

- Converts internal evidence into concise domain-language assumptions, limitations, clarifications, and alternatives.
- Applies an allowlisted vocabulary suitable for nontechnical users.
- Prevents technical identifiers and diagnostic details from reaching normal Ask AI responses.
- Produces separate user and administrator representations so sanitization is structural rather than dependent on frontend hiding.

### Administrator review service

- Creates best-effort review items without introducing an approval dependency or delaying query execution.
- Stores technical evidence and links it to the query job.
- Supports classification and future remediation.
- Does not create an approval dependency for safe execution.

The confidence classifier, user explanation service, administrator review service, review model, migration, controller/API endpoints, and administrator interface are all new components. Existing exploratory generation and validation services supply evidence to them but do not own review state.

## Confidence Evidence and Repair Comparison

The classifier consumes a versioned evidence record rather than a model opinion. At minimum it includes:

- response mode, route, route reason, and selected query family when any;
- documented assumptions and whether each was explicit or defaulted;
- clarification outcomes and unresolved domain ambiguity;
- applicable semantic-contract version, coverage state, checked requirements, and pass/fail outcome;
- validation stages completed and final preflight outcome;
- repair attempt count;
- initial and final normalized SQL hashes;
- initial and final structural signatures;
- compiler and compiler version when application compilation occurred;
- schema, reference, and semantic artifact versions or hashes when available; and
- known source-data or linkage limitations.

“Repair materially changed the query” requires a new deterministic structural comparison. It is not inferred from repair count or raw SQL inequality. The implementation extends the existing exploratory SQL analysis capability to produce a stable signature covering relation set, join graph, predicates and scope, grouping grain, measures, output fields, and ordering. A change limited to whitespace, formatting, harmless aliases, or equivalent normalization is not material. A change to any covered analytical component is a review reason.

## Administrator Review Workflow

### Persistence model

This design introduces two MySQL-backed records rather than overloading `query_jobs.status` or the existing learning tables.

`ai_report_generations` persists the trusted server-side outcome of each accepted Ask AI request, including clarification, blocked, recovery, and validated outcomes. Its schema includes:

- UUID primary key, stable `conversation_id`, nullable `parent_generation_id`, and nullable linked `query_job_id`;
- nullable `user_id`, prompt fingerprint, original question, and follow-up context;
- response mode, persisted execution mode, route, route reason, and validation status;
- generated SQL and SQL hash when a candidate survived generation;
- user-visible assumptions, limitations, and recovery text;
- versioned confidence evidence and structural signatures;
- compiler, model, prompt, semantic-contract, schema-artifact, reference-artifact, and other available provenance fields, with unavailable fields stored explicitly as null;
- `review_required` and stable review-reason keys; and
- created, linked, and updated timestamps.

`ai_report_reviews` is created only when `review_required=true`. Its schema includes:

- UUID primary key and a unique foreign key to `ai_report_generations`;
- lifecycle status `pending | in_review | resolved | dismissed`;
- nullable disposition `acceptable | assumption_change | deterministic_candidate | generation_defect | data_unavailable | specialist_interpretation`;
- administrator notes and reviewer identity;
- result advisory state `none | cautioned | superseded` and nullable `superseded_by_job_id`;
- claimed, resolved, created, and updated timestamps; and
- indexes supporting pending-review ordering, disposition reporting, user ownership, and query-job lookup.

Query execution status remains exclusively in `query_jobs.status`; `cautioned` and `superseded` are review/advisory states and never become query-job execution statuses.

The `/api/nl` boundary writes the generation record and, when required, its review record through `AdministratorReviewService`. The response includes an opaque generation identifier. When the frontend later submits the query for execution, it passes that identifier; the execution controller verifies ownership and SQL hash, links the resulting query job, and copies trusted provenance into `query_jobs.metadata`. Provenance is never reconstructed from client-supplied fields.

If an owned generation identifier is valid but the submitted SQL hash differs, the controller treats the request as an intentional user-edited derivative rather than falsely attaching the original provenance. It runs the normal safety, policy, and preflight checks, creates a child generation with route reason `user_edited_sql`, sets review reason `user_modified_sql`, and links the query job to that child. An unknown or unowned generation identifier is rejected for an NL-sourced execution and cannot be used to claim another generation's provenance.

Conversation linkage does not rely on prompt fingerprint similarity. The first Ask AI request receives a server-generated `conversation_id`. A follow-up or assumption correction creates a new generation with the same `conversation_id` and `parent_generation_id` set to the generation it refines. A fresh rerun may share the conversation id while referencing the immediately preceding generation as its parent. Prompt fingerprints remain search and telemetry aids, not relationship keys.

The local database writes are best effort and wrapped so review persistence cannot turn a safe report into a user-visible failure. A persistence failure emits protected operational telemetry. “Asynchronous review” means administrator work is decoupled from report approval and execution; this design does not require a separate worker merely to insert a review row. Administrator claim/update operations use the repository's existing atomic conditional-update pattern so two administrators cannot claim the same pending item.

The greenfield administrator API provides:

- a paginated, filterable pending/reviewed list;
- an authorized technical-detail endpoint for one review;
- an atomic claim action changing `pending` to `in_review` only when still pending;
- resolve and dismiss actions requiring a disposition; and
- caution and supersede actions that preserve the linked query job's execution status.

All endpoints require the existing administrator role. Ordinary Ask AI and history endpoints expose only the user-facing advisory text and never return review notes or technical evidence.

### Review content and lifecycle

A review item exposes its linked generation evidence to authorized administrators, including:

- the original user question and follow-up context;
- plain-language assumptions displayed to the user;
- internal review reasons and confidence evidence;
- generated SQL and technical validation details;
- referenced relations, schema artifact version, and data source;
- repair attempts and sanitized failure evidence;
- result or query-job identifiers and execution timestamp;
- compiler, model, prompt, semantic-contract, and artifact provenance, with missing provenance identified explicitly; and
- later refinements or reruns linked to the same conversational request.

Administrators classify review items as:

- acceptable exploratory result;
- needs a better documented assumption;
- candidate for a deterministic report family;
- generation or validation defect;
- data unavailable; or
- requires reporting-specialist interpretation.

Review outcomes improve future behavior through documented defaults, semantic rules, reusable verified patterns, deterministic-family candidates, or defect work. Review is not used to silently edit completed reports.

If review discovers a material problem, the original query job keeps its completed execution status and the linked review receives advisory state `cautioned` or `superseded`. A corrected result is generated as a separate query job and referenced by `superseded_by_job_id`. History APIs derive advisory presentation from the linked review rather than mutating execution history.

The ordinary user sees no queue position, approval status, technical category, or administrator diagnostic. If the product exposes review status at all, it uses a nonblocking message such as:

> This report was created with AI assistance and was flagged for routine review.

### Relationship to existing learning stores

The stores coexist with nonoverlapping ownership:

- `ai_report_generations` records system generation, validation, confidence, and provenance.
- `ai_report_reviews` records system-created administrator triage and disposition.
- `ai_query_feedback` remains explicit user feedback about result accuracy. A feedback record may link to a generation or cause a review to be created, but it is not the review queue.
- `ai_clarification_events` remains the source for clarification choices and promotion into training hints. A review disposition may nominate a new default or clarification rule, but promotion continues through the existing clarification/hint governance.
- reference-cache candidate review remains limited to approving reference data and does not absorb report review.

No review disposition automatically changes prompts, defaults, contracts, training hints, or reference artifacts. Those changes continue through their existing tested and reviewable workflows.

### Retention and deletion

- Add the snake-case runtime setting `ai_report_review_retention_days`, defaulting to 90 days and exposed through the existing settings/params configuration pattern.
- Deleting a query-history job also deletes its linked generation and review records through the existing history-deletion service, including single and batch deletion paths.
- Unlinked generation failures and their reviews are deleted 90 days after creation by the same retention policy.
- Resolved or dismissed reviews are deleted 90 days after resolution; aggregate metrics retain no prompt or SQL content.
- Deleting a user purges that user's raw questions, SQL, follow-up context, and administrator notes from these stores rather than merely orphaning them with a null user id.
- Access to generation SQL, technical evidence, and administrator notes requires administrator authorization and is excluded from ordinary history payloads.

## Data and Privacy Boundaries

- Ordinary responses exclude rejected SQL, database errors, internal identifiers, validator categories, and stack traces.
- Administrator evidence is access-controlled and follows the explicit review retention and deletion policy above.
- Review records identify the user-visible assumptions exactly as displayed.
- Technical telemetry uses hashes or sanitized identifiers where raw content is unnecessary.
- Review creation uses a small local-database write and must not cause a safe result to fail if review persistence is temporarily unavailable.

## Error Handling

- Provider and repair failures are handled internally before producing a user state.
- Repairable failures consume the bounded repair budget.
- Policy violations and destructive output receive no repair that could weaken the policy boundary.
- Failed blocking semantic requirements and semantic coverage gaps remain no-result outcomes after the repair budget; they cannot be downgraded into review-only warnings.
- A review-persistence failure does not block an otherwise safe report; it emits administrator-visible operational telemetry for later recovery.
- A valid zero-row result is returned as a completed report with guidance for refining scope, not as a generation failure.
- An unavailable-data limitation is explained explicitly and is not represented as zero.
- Safe partial reports are offered only when they do not imply the unavailable analysis was completed.

## Acceptance Criteria

1. Verified deterministic reports run without confidence messaging or confidence-based review flags.
2. Ordinary AI-assisted reports run automatically with concise interpretation notices.
3. Low-confidence reports run automatically, show a clear limitation, and create administrator review items.
4. No AI-assisted result requires user acknowledgment or approval before execution.
5. Explicit identifiers, requested fields, dates, scopes, and limits are preserved.
6. Reasonable documented defaults prevent unnecessary clarification roadblocks.
7. Clarification questions use reporting language and appear only when no responsible default exists.
8. Unsafe, destructive, invalid, policy-violating, blocking-semantically-nonconforming, semantic-coverage-gap, or impossible queries never execute.
9. Repair is attempted internally before a repairable problem affects the user.
10. Exhausted failures are flagged for review and return plain-language alternatives when available.
11. Ordinary Ask AI users never see technical query diagnostics; the currently shipped safe-category, validator-stage, internal-requirement-key, and technical error-formatting presentation is removed or replaced with domain-language text.
12. Valid zero-row results are distinguishable from unvalidated queries.
13. Administrator review does not silently mutate historical results.
14. Review persistence unavailability does not block safe report execution.
15. Ask AI does not redirect nontechnical users into Builder or Schema Explorer as a required recovery step.
16. Existing `route`, `routeReason`, exploratory `mode`, and `validationSummary.status` retain their defined meanings; canonical response mode and persisted `execution_mode` are introduced exactly as shown in the compatibility mapping rather than being treated as existing fields.
17. The hardened physical ROI compiler remains exploratory and cannot be presented as a canonical verified family.
18. Model self-ratings do not influence confidence classification.
19. A materially changed repair is detected from structural signatures rather than attempt count or raw SQL inequality.
20. Review and generation provenance is persisted server-side and linked to query jobs without trusting client-supplied provenance.
21. `needsExploratoryApproval` is absent from production responses, frontend types, rendering branches, and active tests.
22. Existing clarification, user-feedback, and reference-review records keep their current responsibilities and do not become disconnected duplicate review queues.
23. Clarification and policy-blocked outcomes map explicitly without fabricated validation statuses or execution modes.
24. Edited SQL cannot inherit mismatched generation provenance; it receives a linked derivative generation and administrator review.
25. Follow-ups, corrections, and reruns use server-issued conversation and parent-generation identifiers rather than prompt similarity as their relationship key.

## Testing Strategy

Maintain two complementary versioned prompt corpora.

### Novice corpus

Cover:

- incomplete or tentative wording;
- ambiguous reporting terminology;
- obscure collection language;
- multilayer analysis and ROI questions;
- missing dates or institutional scope;
- documented-default application;
- follow-up corrections;
- exhausted repair; and
- unavailable required data.

### Explicit-intent corpus

Cover:

- exact instance or item identifiers;
- explicit requested fields;
- narrow filters and user-provided limits;
- known reporting definitions;
- equivalent rephrasings; and
- attempted prompt injection or destructive requests.

Each case asserts:

- selected execution mode;
- whether execution occurred;
- explicit-value preservation;
- assumptions and limitations shown;
- confidence presentation;
- administrator review behavior;
- absence of technical leakage;
- validation and repair outcomes;
- zero-row versus unvalidated distinction; and
- stable treatment of materially equivalent prompts.

Compatibility and integration tests must also prove:

- each current query-family route maps to verified deterministic without changing route telemetry;
- ordinary exploratory, flagged exploratory, exhausted, rejected, clarification, and policy-blocked responses map according to the compatibility table;
- hardened ROI remains exploratory even when `physical_roi_v2` compiles the final SQL;
- a failed blocking semantic requirement and `semantic_coverage_gap` never return SQL or results;
- a limited but passing semantic contract may execute and may trigger review;
- current `failureCategory`, `validatorStage`, and internal requirement keys do not reach ordinary response payloads or rendered copy;
- SQL remains available through the intentional advanced action and follow-up context without copy requiring novice users to inspect it;
- no NL-to-SQL model confidence value can affect classifier output;
- repaired candidates with only formatting or alias normalization are not materially changed;
- repaired candidates with changed relations, joins, scope, grain, measures, outputs, or ordering are materially changed;
- generation and review records persist the required provenance, including explicit nulls for unavailable artifacts;
- the opaque generation identifier links only an owned matching-SQL execution job;
- an owned SQL-hash mismatch creates a validated `user_edited_sql` child generation and review rather than inheriting the original provenance;
- an unknown or unowned generation identifier cannot be used for an NL-sourced execution;
- follow-ups, assumption corrections, and reruns retain `conversation_id` and the correct `parent_generation_id`;
- administrator claim is atomic and review endpoints enforce administrator authorization;
- caution and supersession leave `query_jobs.status` unchanged;
- review persistence failure does not block a safe result;
- retention and history deletion remove linked sensitive generation and review data; and
- `needsExploratoryApproval` has no remaining production or type-level usage.

## Out of Scope

- Replacing Builder or Schema Explorer.
- Requiring administrator approval before safe execution.
- Showing numerical confidence percentages to users.
- Asking users to troubleshoot SQL or schema failures.
- Guaranteeing that every possible reporting question can be answered.
- Treating absent data as a zero value.
- Silently converting an exploratory result into a verified canonical report.
- Building a generic asynchronous task worker for administrator review; human review is asynchronous, while local persistence is a best-effort request-boundary write.

## Success Measures

Track:

- deterministic, ordinary AI-assisted, review-flagged, and unable-to-run rates;
- clarification rate and completion after clarification;
- internal repair success and exhaustion rates;
- administrator review reasons and dispositions;
- repeated-problem conversion into defaults, semantic rules, or deterministic families;
- technical-detail leakage regressions;
- user refinements after receiving an AI-assisted limitation; and
- abandoned Ask AI sessions following a clarification or unable-to-run state.

Success means users receive useful reports without technical troubleshooting, material uncertainty is visible without becoming a gate, and administrator review steadily improves the reliability of future requests.
