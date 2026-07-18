# Ask AI Semantic Conformance Design

## Problem

Ask AI currently validates that generated SQL is read-only, references permitted schema objects, and passes PostgreSQL preflight. Those checks establish that a query is safe and executable, but not that it faithfully answers the user's question.

The production call-number ROI example passed those checks while contradicting its displayed assumptions and request:

- it displayed `payment_date` but filtered `purchase_order.date_ordered`;
- it joined invoice spending to item rows before aggregation, allowing costs and purchase counts to multiply;
- it omitted descending purchase-count ordering for “purchased the most”;
- it added an unrequested `book` filter;
- it formatted numeric measures as text.

A normal reporting user cannot reasonably detect those defects. Results must not be presented as validated when the system can prove that they conflict with the request or displayed assumptions.

## Decision

Add a blocking semantic-conformance gate between generated-SQL validation and database preflight. The gate compares SQL with a machine-readable contract derived from the question, selected scope, and documented assumptions. Violations enter the existing two-repair loop. Results are shown only after every detected blocking requirement passes.

If two repairs cannot satisfy the contract, the request is preserved and the user receives plain-language recovery describing the unmet reporting requirement. The system does not show potentially misleading results with a warning and does not offer a normal-user bypass.

## Considered approaches

### Prompt-only guidance

Continue adding stronger instructions to the model. This is inexpensive but cannot prove compliance; the production SQL already ignored explicit prompt guidance. Rejected.

### Semantic contract and conformance gate — selected

Build deterministic requirements from the request and assumptions, then validate generated SQL against composable rules. This catches known analytical defects, integrates with existing repair, and can grow to additional reporting domains without converting every question into a verified report template.

### Deterministic compiler for all exploratory questions

Translate every question into a formal relational plan and compile SQL without freeform generation. This offers stronger guarantees but would greatly reduce the breadth that Ask AI can handle and duplicates the existing canonical query-family system. It remains appropriate for mature patterns promoted from exploratory use, not as the immediate exploratory fallback.

## User experience

### Successful request

The user asks a question exactly as they do now. The UI continues showing “Generating and validating…” while generation, conformance checking, repair, and database preflight happen synchronously.

The result includes a small “Validated against your request” section listing only requirements the system actually checked, for example:

- Purchases use payment date for the last five years.
- Circulation uses the same five-year period.
- Spending is aggregated before item-level circulation is joined.
- Results are grouped by primary call-number class and ranked by purchases.
- ROI includes checkouts per dollar and cost per checkout.
- No unrequested material-type filter was added.

This checklist does not claim that AI results are mathematically proven in every respect. It states the concrete request requirements that passed deterministic checks.

### Automatic repair

When generated SQL conflicts with the contract, no SQL or technical error is shown. The validator returns structured, safe feedback to the repair service. The user remains on “Generating and validating…” while the existing shared repair budget is used.

For the production example, internal feedback would identify:

- expected payment-date filtering but found order-date filtering;
- spending was not aggregated before item-grain joins;
- missing descending purchase ranking;
- unrequested material-type filter;
- required numeric measures were formatted as text.

### Unresolved request

After two failed repairs, no results or Run controls are shown. Recovery uses business language, such as:

> I couldn't produce a report that used payment date without multiplying spending across item records. Nothing ran or changed. Your request is preserved so you can retry or adjust the investment basis.

The panel offers Retry, correction examples from relevant assumptions, and deterministic refinement choices. It never exposes SQL, parser terminology, or database errors.

## Architecture

### 1. Semantic contract builder

Add `ExploratorySemanticContractService`. It consumes:

- the original question;
- separately selected campus or other request scope;
- resolved documented assumptions;
- the exploratory route reason.

It produces a versioned contract containing:

- detected reporting concepts;
- blocking requirements with stable keys and user-readable labels;
- required measures, grouping, ordering, and time windows;
- permitted filters with provenance: explicit prompt, selected scope, documented default, or reporting policy;
- applicable semantic rule keys;
- coverage metadata listing which detected requirements can be deterministically checked.

The contract is deterministic. The model may not add, remove, or downgrade requirements. Every detected blocking requirement must have a deterministic validator. A detected requirement without validator coverage produces a blocking `semantic_coverage_gap` and actionable recovery rather than unchecked results.

### 2. Composable semantic rules

Add `ExploratorySqlSemanticValidatorService`. It evaluates each applicable rule and returns:

- `passed`;
- safe violation category and rule key;
- user-readable requirement label;
- safe repair guidance;
- optional evidence codes that contain no raw SQL.

Rules are composable rather than bound to a full question. The initial rule set covers both generic request shape and the existing acquisition/circulation concepts:

#### Generic rules

- required output measures are present;
- requested grouping is present;
- “most,” “top,” or ranking requests include the correct descending order;
- explicit date windows are represented;
- filters on governed lookup dimensions are permitted by the contract;
- numeric analytical measures remain numeric rather than being formatted into text.

#### Purchase and circulation rules

- `purchase_date_basis = payment_date` requires the approved paid-invoice payment-date source and window; `date_ordered` alone cannot satisfy it;
- `investment_cost_basis = actual_paid_fund_distribution` requires the approved amount/percentage fund-distribution semantics;
- spending must be aggregated at its acquisition grain before any item-level or circulation-event join;
- `circulation_window = same_as_purchase_window` requires an approved checkout-event timestamp and equivalent window;
- circulation must aggregate at item grain before final call-number grouping;
- `call_number_grouping` must match the resolved grouping assumption;
- `roi_formula` must return the required numeric measures with division-by-zero protection.

Rules use stable semantic keys, not report-specific SQL strings. Explicit assumption corrections select alternate rules from the same registry.

### 3. SQL analysis

Extend the existing structural SQL analysis rather than adding a new parser dependency. A focused `ExploratorySqlAnalysisService` will provide:

- CTE definitions and dependency order;
- referenced physical tables and aliases;
- selected expressions and output aliases;
- predicates and filter provenance candidates;
- grouping and ordering expressions;
- aggregate locations and grain transitions;
- functions that convert numeric measures to display text.

The analyzer must ignore strings and comments, preserve quoted-identifier semantics, and fail closed when a rule cannot reliably inspect a required construct. It must not attempt to execute SQL.

### 4. Repair integration

After the existing parse, safety, policy, table-reference, and normalization checks, the exploratory attempt runs semantic conformance. A failed blocking rule raises the existing typed exploratory validation exception with:

- validator stage `semantic_conformance`;
- a safe category such as `assumption_mismatch`, `grain_mismatch`, `missing_ordering`, `unrequested_filter`, or `output_type_mismatch`;
- the stable rule keys and safe repair guidance.

The existing repair coordinator consumes those violations within the same maximum of two repairs. Every repaired candidate restarts static validation and semantic conformance. Database preflight occurs only after conformance passes.

Safety, policy, cancellation, provider, and connectivity failures retain their current hard-stop behavior and do not become semantic repairs.

### 5. Response contract and UI

Validated exploratory responses add `semanticValidation`:

```json
{
  "status": "validated",
  "contractVersion": 1,
  "checkedRequirements": [
    {"key": "purchase_date_basis", "label": "Purchases use payment date for the last five years."}
  ]
}
```

The browser renders these labels under “Validated against your request.” A validated result means every detected blocking requirement has validator coverage and passed. The browser does not render rule internals, SQL fragments, unchecked claims, or raw evidence.

Exhausted responses add safe `unmetRequirements` labels and deterministic suggestions. No rejected SQL appears in the response.

## Initial ROI contract

For the motivating request, the contract must require:

- five-year purchase window using paid-invoice payment date;
- paid fund-distribution investment basis;
- spend aggregation before holdings/items/circulation joins;
- matching five-year audit checkout window;
- item-grain circulation aggregation before call-number grouping;
- primary call-number-class grouping;
- numeric purchase count, spending, checkout count, checkouts per dollar, and cost per checkout;
- zero-safe division;
- descending purchase-count ordering before limit;
- campus scope from the selected campus;
- no material-type or acquisition-unit restriction unless explicitly present in the request, selected scope, documented default, or reporting policy.

The flawed production SQL must fail at least payment-date basis, spend grain, ordering, unrequested material filter, and numeric-output-type checks. A corrected fixture must pass all requirements.

## Error handling and privacy

- Browser and telemetry payloads contain stable rule keys, safe categories, counts, and user-readable labels only.
- Raw SQL remains available only inside the bounded repair context already used for candidate correction.
- Raw SQL, database messages, schema details, and exception messages never appear in recovery responses or semantic telemetry.
- If the analyzer cannot safely evaluate an applicable blocking rule, that rule fails closed and enters repair/recovery rather than silently passing.
- If the contract builder detects a blocking reporting requirement for which no rule exists, it returns a safe semantic-coverage recovery response and does not show results.
- Canonical verified report families retain their current compiler and shape validators; this gate applies to exploratory generation only.

## Testing strategy

### Contract tests

- Original ROI wording produces the exact required rule keys.
- Explicit corrections replace relevant requirements rather than adding contradictory ones.
- Selected campus is permitted and required even when omitted from prompt text.
- Material-type and acquisition-unit filters are permitted only when their provenance is explicit or policy-backed.

### Analyzer and rule tests

- The captured flawed production SQL fails with the expected safe violation keys.
- Corrected ROI SQL passes.
- Payment date versus order date, aggregation before item joins, matching windows, ranking, numeric output types, unrequested filters, zero-safe division, CTEs, strings, comments, and quoted identifiers receive focused coverage.
- Structurally ambiguous SQL fails closed for applicable blocking rules.

### Repair tests

- Semantic violations use the existing initial-plus-two-repair budget.
- Safe structured violations reach the repair prompt.
- A repaired candidate reruns every validation stage.
- Exhaustion preserves the question, assumptions, unmet requirement labels, and suggestions without SQL.

### Controller and UI tests

- Database preflight is never invoked before semantic conformance passes.
- Validated results render the checked-requirements checklist.
- Exhausted results render plain unmet requirements and no SQL/Run controls.
- Canonical routes bypass exploratory semantic conformance.

### Regression verification

Run the complete Ask AI, canonical-family, Builder/LDLite, frontend, production-build, lint, and artifact-isolation matrices. Do not modify existing schema caches, mapping caches, or canonical query-family contracts.

## Rollout and observability

Semantic telemetry records contract version, rule keys, pass/fail counts, repair number, and terminal outcome. It excludes prompts, SQL, values, and exception text.

Track:

- conformance failures by rule key;
- repairs that resolve each rule;
- exhaustion rate;
- validated requests by contract coverage;
- rules frequently encountered without deterministic coverage.

New rule sets can be added for additional reporting concepts. Repeated, stable patterns may later be promoted to deterministic canonical report families, but exploratory conformance does not require promotion before it can protect users.

## Scope constraints

- Do not weaken SELECT-only safety, reporting policy, PII protection, or cancellation behavior.
- Keep one initial generation plus at most two automatic repairs.
- Do not edit existing schema caches, table-mapping caches, or canonical query-family contracts.
- Do not present unchecked requirements as validated.
- Do not allow normal users to bypass a failed blocking semantic requirement.
