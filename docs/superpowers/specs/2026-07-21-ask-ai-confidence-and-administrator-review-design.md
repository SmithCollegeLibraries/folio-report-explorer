# Ask AI Confidence and Administrator Review Design

**Date:** 2026-07-21  
**Status:** Approved design

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
6. **Separate confidence from safety.** A low-confidence interpretation may execute; unsafe, invalid, destructive, or impossible queries may not.
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

## Confidence and Execution Outcomes

Confidence is determined from application evidence. The AI provider cannot declare its own output trustworthy.

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
- Create an administrator review item asynchronously.
- Do not show queue state, approval state, or technical diagnostics to the user.

Review-triggering evidence includes:

- multiple reasonable business definitions;
- cross-domain or multilayer aggregation;
- incomplete or proxy record linkage;
- inferred institutional, collection, date, or population scope;
- weak or partial semantic-rule coverage;
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

Low confidence by itself is never a reason to withhold execution. Destructive output, policy violations, invalid SQL after bounded repair, failed preflight, or unavailable required data remain hard execution boundaries.

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

Messages must not include table names, column names, PostgreSQL errors, SQL classifications, stack traces, validator categories, or repair internals.

The original request, follow-up context, and accepted assumptions remain available so the user does not have to start again.

### Explicit-values fast path

A request containing explicit identifiers and fields, such as “show these instance numbers with these fields,” takes a narrow explicit-values path:

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
9. **Flag review asynchronously.** Persist an administrator review item when confidence evidence crosses the review threshold or execution cannot proceed.

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

### User explanation service

- Converts internal evidence into concise domain-language assumptions, limitations, clarifications, and alternatives.
- Applies an allowlisted vocabulary suitable for nontechnical users.
- Prevents technical identifiers and diagnostic details from reaching normal Ask AI responses.

### Administrator review service

- Creates review items outside the user's execution-critical path.
- Stores technical evidence and links it to the query job.
- Supports classification and future remediation.
- Does not create an approval dependency for safe execution.

## Administrator Review Workflow

A review item contains:

- the original user question and follow-up context;
- plain-language assumptions displayed to the user;
- internal review reasons and confidence evidence;
- generated SQL and technical validation details;
- referenced relations, schema artifact version, and data source;
- repair attempts and sanitized failure evidence;
- result or query-job identifiers and execution timestamp;
- compiler, model, prompt, semantic-contract, and artifact provenance when available; and
- later refinements or reruns linked to the same conversational request.

Administrators classify review items as:

- acceptable exploratory result;
- needs a better documented assumption;
- candidate for a deterministic report family;
- generation or validation defect;
- data unavailable; or
- requires reporting-specialist interpretation.

Review outcomes improve future behavior through documented defaults, semantic rules, reusable verified patterns, deterministic-family candidates, or defect work. Review is not used to silently edit completed reports.

If review discovers a material problem, the original result retains the provenance of what ran and may be marked cautioned or superseded. A corrected result is generated separately.

The ordinary user sees no queue position, approval status, technical category, or administrator diagnostic. If the product exposes review status at all, it uses a nonblocking message such as:

> This report was created with AI assistance and was flagged for routine review.

## Data and Privacy Boundaries

- Ordinary responses exclude rejected SQL, database errors, internal identifiers, validator categories, and stack traces.
- Administrator evidence is access-controlled and follows existing prompt, SQL, and telemetry retention policies.
- Review records identify the user-visible assumptions exactly as displayed.
- Technical telemetry uses hashes or sanitized identifiers where raw content is unnecessary.
- Review creation must not extend normal query latency or cause a safe result to fail if the review queue is temporarily unavailable.

## Error Handling

- Provider and repair failures are handled internally before producing a user state.
- Repairable failures consume the bounded repair budget.
- Policy violations and destructive output receive no repair that could weaken the policy boundary.
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
8. Unsafe, destructive, invalid, policy-violating, or impossible queries never execute.
9. Repair is attempted internally before a repairable problem affects the user.
10. Exhausted failures are flagged for review and return plain-language alternatives when available.
11. Ordinary Ask AI users never see technical query diagnostics.
12. Valid zero-row results are distinguishable from unvalidated queries.
13. Administrator review does not silently mutate historical results.
14. Review queue unavailability does not block safe report execution.
15. Ask AI does not redirect nontechnical users into Builder or Schema Explorer as a required recovery step.

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

Integration tests must also prove that review creation is asynchronous, a review storage failure does not block a safe result, and an administrator action cannot silently overwrite a completed report.

## Out of Scope

- Replacing Builder or Schema Explorer.
- Requiring administrator approval before safe execution.
- Showing numerical confidence percentages to users.
- Asking users to troubleshoot SQL or schema failures.
- Guaranteeing that every possible reporting question can be answered.
- Treating absent data as a zero value.
- Silently converting an exploratory result into a verified canonical report.

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
