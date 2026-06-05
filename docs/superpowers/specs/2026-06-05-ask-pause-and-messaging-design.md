# Ask Pause And Messaging Design

## Goal

Reduce unnecessary pauses in Ask AI while preserving the stops that protect query meaning, safety, and data quality.

The current app often turns uncertainty messaging into a required user decision. That is useful when the user must clarify intent, but it is frustrating when the app only needs to warn that it is using AI-assisted SQL outside a verified report pattern. Staff may interpret these pauses as mistakes in their request.

The target behavior is:

- Stop only when user input changes the query meaning or safety outcome.
- Continue automatically when the app can attempt a query and only needs to disclose reliability limits.
- Use staff-facing messaging that explains what is happening without system terms such as "canonical compiler path."
- Capture enough feedback and correction data to make repeated exploratory requests more consistent over time.

## User Experience

Ask AI should classify responses into three user-facing states.

### Hard Stop

The app cannot proceed because the request is unsafe, blocked by policy, or requires data this tool should not expose.

Examples:

- Patron personal information.
- Individual patron records.
- Disallowed schemas or protected data sources.

The UI should show a clear blocked message and no query should be generated.

### Clarification Stop

The app needs a meaningful decision from the user before SQL generation because multiple interpretations could produce materially different results.

Examples:

- "Show me all of the material in the Duplaix collection" when "Duplaix" is not an exact local reference match.
- Ambiguous local aliases.
- Collection, location, library, material type, contributor, title, identifier, or notes matches found by the local reference cache or safe-probe scans.
- Missing required scope for a supported query family.

The UI should ask one clear question and show concrete options from resolver evidence when available.

### Advisory Continue

The app can try to generate SQL, but cannot match the request to a verified report pattern or deterministic compiler.

The UI should not ask for approval. It should show an inline notice while generation continues:

> I could not match this request to a verified report pattern, so I built an AI-assisted query. Please review the SQL and results before using them; similar wording may produce different SQL.

This notice should appear with the generated SQL/results, not as a blocking clarification card.

## Backend Behavior

`POST /api/nl` should distinguish clarification from advisory continuation.

Current exploratory approval responses use `needsClarification` and require the frontend to ask the user before retrying with `allowExploratory: true`. That behavior should change for unsupported-but-unambiguous requests.

Recommended response model:

- `route: blocked`: hard stop.
- `needsClarification: true`: clarification stop; requires user input.
- `exploratoryNotice`: advisory continue; no user input required.
- normal result: supported deterministic or canonical route.

When no canonical or checked report pattern exists, and no ambiguity or policy block exists, the backend should automatically run exploratory generation and attach `exploratoryNotice` metadata to the result.

The existing local reference resolver should remain the first gate. If it returns `needsClarification`, the backend should not continue to exploratory generation until the user resolves the ambiguity.

## Frontend Behavior

The Ask page should reserve blocking cards for true clarification or policy states.

Exploratory/advisory messages should be rendered as a non-blocking notice near generated output. The title should avoid approval language. Suggested title:

> AI-assisted query

Suggested body:

> I could not match this request to a verified report pattern, so I built a best-effort query. Review the results and SQL before using them.

The existing "This request needs approval" card should no longer be used for exploratory generation that can proceed automatically.

Clarification cards should keep their current role but use clearer language:

- "I need one detail before building this query."
- "I found more than one possible meaning for this term."
- "Confirm where I should search so I do not guess."

## Learning And Drift Reduction

Exploratory queries should feed an improvement workflow instead of remaining one-off model guesses.

The system should capture:

- Original prompt and prompt fingerprint.
- Route and route reason.
- Whether the query was canonical, exploratory, or clarification-resolved.
- Generated SQL hash.
- Result accuracy feedback.
- Staff corrections and corrected SQL.
- Resolver terms and selected clarification options when applicable.

Review workflow:

- Group repeated exploratory requests by fingerprint, normalized wording, route reason, and SQL hash.
- Flag repeated prompts that produce different SQL hashes.
- Promote reviewed successful requests into a more reliable path:
  - canonical query family,
  - report template,
  - resolver alias,
  - local reference cache improvement,
  - or training hint.

The priority metric is not only whether a single answer was accurate. It is whether repeated staff requests become more consistent and require fewer future pauses.

## Error Handling

- Policy and safety failures remain hard stops.
- Resolver ambiguity remains a clarification stop.
- SQL validation or execution preflight failure can still return a recovery path, but should prefer automatic exploratory retry only when no user decision is needed.
- AI timeouts remain non-user-fault messages and should not imply the prompt was wrong.

## Tests

Backend:

- Unsupported-but-unambiguous NL request proceeds to exploratory generation without returning `needsClarification`.
- Exploratory result includes non-blocking notice metadata.
- Reference resolver ambiguity still returns `needsClarification`.
- Safe-probe terms such as unknown named collections still stop for clarification.
- Policy-blocked prompts still return a hard stop.

Frontend:

- Exploratory notice renders without option buttons and without blocking the result view.
- Clarification responses still render choice controls.
- Batch resolver clarification still requires all terms to be resolved before continuing.
- Staff-facing copy avoids "canonical compiler path" and approval framing.

## Out Of Scope

- Removing clarification for genuinely ambiguous local terms.
- Weakening patron PII or schema policy protections.
- Building every exploratory query family immediately.
- Replacing the existing reference cache, safe-probe, or clarification event tables.
