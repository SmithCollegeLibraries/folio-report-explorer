# Ask AI Exploratory SQL Repair Design

## Purpose

Ask AI must support novel, cross-domain reporting questions even when they do not match a deterministic report family. Verified report patterns remain the fastest and most repeatable route, but they are not a capability boundary.

When an exploratory SQL candidate fails validation, Ask AI will use the validation feedback to repair the candidate automatically. The user will receive either validated SQL or a guided continuation that preserves the question, assumptions, attempted query plan, and an actionable next step. Unsupported complexity must not produce the current generic dead end.

## Goals

- Automatically make one initial exploratory generation attempt and at most two repair attempts.
- Use documented business defaults when a request is underspecified, and display every applied assumption.
- Validate every candidate with the existing safety, table-policy, schema-reference, normalization, and database-preflight controls before execution.
- Feed repairable validation feedback back to the AI with the original question, applicable assumptions, relevant schema context, and previous candidate SQL.
- Preserve hard stops for safety and data-policy violations.
- Replace the generic "could not produce fully validated SQL" response with a structured, actionable continuation when repair is exhausted.
- Add telemetry that distinguishes generation, repair, validation, exhaustion, policy, connectivity, and provider failures.

## Non-goals

- Building a general autonomous database agent.
- Removing or weakening deterministic query-family compilation.
- Executing SQL that has not passed all existing safety and database validation stages.
- Automatically promoting exploratory SQL to a verified report pattern.
- Defining defaults for every possible library-reporting concept in the first implementation.
- Returning raw database errors, credentials, prompts, or sensitive data to the browser.

## Approaches Considered

### Bounded validator-driven repair loop

Generate exploratory SQL, validate it, and make at most two repairs using sanitized validator feedback. This is the selected approach because it fixes the current failure mode without weakening controls or requiring a new deterministic family for every novel request.

### More deterministic report families

A dedicated acquisitions-to-circulation ROI family could make this one request repeatable, but it would leave the general Ask AI limitation intact. Future verified families can still be promoted from successful exploratory usage.

### Fully agentic query planner

An agent that repeatedly inspects schema, plans joins, and executes tools would be more flexible but would substantially expand scope, latency, and security risk. It is not required for the bounded repair behavior.

## Architecture

### Exploratory generation coordinator

`GeminiService` will remain the owner of AI-provider calls and exploratory SQL parsing. A focused coordinator API will manage an `ExploratorySqlAttemptContext` containing:

- original user question;
- campus scope;
- documented assumptions;
- attempt number, from 1 through 3;
- previous candidate SQL, when one exists;
- sanitized validation feedback;
- failure category;
- relevant schema context.

The initial attempt uses the existing exploratory prompt. Repair attempts use a dedicated repair prompt that instructs the model to return one corrected `SELECT` statement and a concise explanation. The prompt will explicitly preserve the user's requested result shape and documented assumptions while correcting only the reported defects.

The API response will include:

- `mode: "exploratory"`;
- `assumptions: Assumption[]`;
- `repairAttempts: number` counting repair calls, excluding initial generation;
- `validationSummary` describing the successful validation path or final safe failure category;
- the existing route and exploratory notice metadata.

### Validation stages

Each candidate must pass these stages in order:

1. AI response parsing and single-statement extraction.
2. SQL normalization.
3. SELECT-only and destructive-operation safety checks.
4. table-policy checks.
5. known schema/table-reference checks.
6. existing generated-SQL semantic guards.
7. database preflight and complexity estimation in `FolioQueryController`.

The first six stages run within exploratory generation. Database preflight remains in the controller because it owns the FOLIO connection and execution policy. A preflight failure may invoke the same repair API with the candidate SQL and sanitized preflight feedback, using only the remaining attempt budget. The repaired candidate must restart validation from stage 1; a repair never bypasses an earlier check.

### Repair classifications

Repairable failures include:

- malformed model formatting when a candidate can be recovered or regenerated;
- PostgreSQL syntax errors;
- unknown or misspelled tables and columns;
- invalid aliases and ambiguous references;
- incompatible expressions or functions;
- invalid join predicates;
- repairable generated-SQL semantic guards;
- database `EXPLAIN`/preflight errors caused by candidate SQL;
- excessive query complexity when the model can preserve the requested result with safer pre-aggregation or join order.

Non-repairable failures include:

- PII or blocked-table policy violations;
- non-SELECT or destructive SQL;
- database connectivity, VPN, DNS, SSL, or credential failures;
- AI-provider timeout, quota, or cancellation;
- explicit user cancellation.

Policy failures remain HTTP 403 responses. Connectivity and provider failures retain their existing distinct response types. They must not be mislabeled as SQL-generation failures.

### Documented defaults

A versioned backend catalog will define defaults independently of prompt wording. Each entry contains a stable key, trigger concepts, value, user-facing label, explanation, and correction example. Defaults applied to a request are injected into every generation and repair prompt and returned to the frontend.

The initial cross-domain collection-ROI defaults are:

| Key | Default | User-facing explanation |
| --- | --- | --- |
| `purchase_date_basis` | invoice payment date | Purchases are assigned to the date the invoice was paid. |
| `investment_cost_basis` | actual paid invoice fund-distribution amount | Investment uses paid allocations rather than estimated PO-line price. |
| `circulation_window` | the same requested reporting window | Circulation is counted within the same period used for purchases. |
| `roi_formula` | checkouts per dollar, with cost per checkout also returned | ROI is usage divided by spend; cost per use is included as a companion measure. |
| `call_number_grouping` | primary call-number class | Detailed call numbers are grouped into their primary classification for comparison. |

If the user explicitly supplies a conflicting definition, explicit language wins and the corresponding default is not applied. Users can correct a displayed assumption in a natural-language follow-up, such as "Use invoice date instead of payment date." Follow-up context must retain the original question and replace the corrected assumption rather than stacking contradictory instructions.

## Cross-domain ROI query guidance

The default catalog supplies business meaning, while schema context supplies physical joins. For the motivating request, the repair prompt should guide the model toward these principles without hard-coding a final SQL statement:

- join paid invoice fund distributions to PO lines using the documented `po_line_id` relationship;
- join `orders.po_line__t.instance_id` to inventory instances;
- reach holdings and items before deriving the primary class from the effective call number;
- aggregate investment separately at PO-line or instance grain;
- aggregate circulation separately at item grain and within the selected period;
- combine the aggregates only after each side is reduced to a safe grain, avoiding multiplication of spend by holdings, copies, or loan rows;
- guard division by zero and return purchase count, spend, circulation, checkouts per dollar, and cost per checkout.

These instructions are grounding information, not a verified-family compiler. Successful exploratory results remain visibly labeled as AI-assisted until reviewed and promoted.

## User Experience

### Successful exploratory result

Ask AI runs the validated query using the existing execution flow. Above the results, an assumptions panel lists every documented default used. Each assumption includes a correction example. The existing exploratory notice remains, but its wording explains that validation passed and only repeatability—not immediate SQL validity—is unverified.

### Repair in progress

The existing generation status will indicate `Generating query`, then `Repairing query (1 of 2)` or `Repairing query (2 of 2)`. No user acknowledgment is required between automatic attempts.

### Repair exhausted

The response is a guided continuation, not a blank result or generic warning. It contains:

- the preserved original question;
- applied assumptions;
- a concise description of the attempted cross-domain plan;
- the final sanitized failure category;
- suggested corrections or narrower alternatives;
- a retry action that retains accumulated context.

The UI must not claim that an SQL query exists when none survived validation. It must not use "unverified report pattern" as the reason generation stopped. Hard policy blocks are the only intentional terminal roadblocks.

## Telemetry

Each attempt emits structured telemetry with:

- prompt fingerprint, never raw prompt text in the event payload;
- route and route reason;
- attempt number and maximum attempts;
- initial-generation or repair phase;
- validator stage;
- normalized failure category;
- whether a candidate SQL string existed and its length, not its full contents;
- elapsed time and provider;
- final outcome: validated, exhausted, policy-blocked, connectivity failure, provider failure, or cancelled;
- applied assumption keys.

The existing detailed server warning may retain the internal exception for operators. Browser responses receive only sanitized categories and guidance.

## Testing Strategy

Backend tests will use deterministic fake AI responses and validator outcomes. Required cases are:

- an unsupported request returns valid exploratory SQL on the first attempt;
- invalid initial SQL is repaired into valid SQL;
- two failed repairs exhaust the budget without a fourth AI call;
- repair prompts contain the original question, previous SQL, sanitized error, assumptions, and relevant schema context;
- a policy violation never triggers repair;
- non-SELECT SQL never triggers repair;
- connectivity and provider failures retain their existing classifications;
- a database-preflight failure consumes the remaining repair budget and revalidates the repaired candidate;
- explicit user language overrides a documented default;
- applied assumptions are returned through the API and rendered by the frontend;
- follow-up corrections replace assumptions without contradictory prompt stacking;
- exhausted repair returns a guided continuation with no SQL and no generic verified-pattern roadblock;
- the motivating acquisitions/call-number/circulation/ROI prompt enters exploratory generation with all five documented defaults.

Frontend tests will cover the assumptions panel, repair progress copy, successful exploratory notice, exhausted continuation, and retry/correction behavior.

## Acceptance Criteria

- Unsupported query families are attempted automatically without an approval gate.
- A repairable failure causes no more than two automatic AI repair calls.
- No candidate executes unless all safety, schema, semantic, and database-preflight checks pass.
- Policy violations never enter the repair loop.
- The motivating ROI request receives either validated exploratory SQL or a guided continuation containing assumptions, attempted plan, a specific safe failure category, and retry/refinement actions.
- The generic "could not produce fully validated SQL" message is no longer used for repair exhaustion.
- Users can see and correct documented assumptions.
- Existing deterministic query-family tests and exploratory-generation behavior remain green.

