# Ask AI SELECT Safety Classification Fix

## Problem

A safe production request for five-year call-number purchases, circulation, and ROI reached exploratory generation and resolved all five documented assumptions, but the controller returned `unsafe_generated_sql` with the browser-visible category `non_select`. No result was produced.

The generated candidate had already passed the SQL safety check used by `GeminiService`. The controller then applied a second private SELECT-only regular expression with a different blocked-keyword list. This validator drift can reject SQL that the canonical safety boundary accepted, including otherwise valid SELECT text containing a standalone word such as `DO`. The response then presents an internal classifier that does not help a reporting user recover and can make the user believe they damaged the system.

## Goals

- Safe exploratory reporting SQL that passes the canonical safety boundary proceeds to database preflight and returns results.
- The controller uses the same safety authority as all other SQL execution boundaries.
- Genuine destructive or non-SELECT SQL remains a zero-repair hard stop and is never executed.
- Rejected-state UI uses plain reassurance and concrete Retry/Refine actions, not an internal `non_select` category.
- Existing repair limits, policy/PII controls, canonical routing, schema artifacts, and Builder behavior remain unchanged.

## Design

### Shared safety authority

Replace the controller's private keyword-based `isSelectOnlyNlSql()` decision with a small boundary method that calls `SqlBuilderService::validateSafety()` and returns a boolean. The controller will continue to build the existing safe rejected response when the shared validator throws.

This keeps defense in depth at the controller without maintaining a second SQL grammar or blocked-keyword list. A candidate accepted during exploratory generation receives the same decision immediately before database preflight.

### User experience

For `validationSummary.status = rejected`, the recovery panel will not render `failureCategory`. It will say that no query ran and that Ask AI could not safely turn the request into a report, then offer Retry and refinement choices. Exhausted validation responses may continue showing their safe diagnostic category because those categories help distinguish correctable schema or validation failures.

The primary production case should no longer enter rejected recovery: its valid SELECT should reach database preflight and return the normal result UI.

### Safety behavior

Statements rejected by `SqlBuilderService::validateSafety()` remain immediate hard stops. They make zero repair calls, expose no SQL, and show no Run control. This change does not weaken reporting policy or allow the system to execute destructive output.

## Tests

1. Add a controller regression proving an exploratory SELECT containing a harmless standalone `DO` value reaches preflight and returns validated SQL instead of `non_select` recovery.
2. Preserve a destructive-SQL regression proving DELETE is rejected before preflight with zero repairs and no SQL in the response.
3. Add a recovery-panel regression proving rejected responses hide `failureCategory`, use reassuring copy, and retain Retry/Refine controls.
4. Run the complete Ask AI backend matrix, Builder controller/policy regressions, frontend tests, production build, PHP lint, and `git diff --check`.

## Scope constraints

- Do not edit schema caches, schema mappings, canonical query-family contracts, or Builder relationship artifacts.
- Do not log or return rejected SQL, database details, or exception messages.
- Do not change the maximum two-repair budget or make destructive SQL repairable.
