# NL Follow-Up Query Design

## Goal

Users can ask explicit follow-up questions after a query has been generated, either from the Ask page's current result or from a completed History job. Follow-ups should preserve the previous query's result set and filters unless the user explicitly changes them.

Example:

- Original: "Please provide a list of titles with the location MRBC Reference Collection containing only records for which the MRBC Reference Collection is the only holding location in the 5 Colleges."
- Follow-up: "Provide the same list and include instance numbers and call numbers."

The follow-up should generate a revised SQL query that keeps the MRBC-only scope and adds instance number and call number columns.

## UX

Ask page:

- After an NL query has generated SQL, show an `Ask follow-up` action near the result controls.
- Clicking it puts the input into follow-up mode for the current result.
- The user enters a follow-up prompt and submits.
- The generated follow-up SQL runs through the same execution flow as a normal Ask query.

History:

- Completed history jobs expose an `Ask follow-up` action in the results modal.
- Clicking it routes to Ask in follow-up mode with the history job id as context.
- The user writes the follow-up prompt in Ask. The backend loads the historical SQL by job id rather than trusting SQL passed through the URL.

## API

Extend `POST /api/nl` with optional `followUpContext`.

Client-supplied current-result context:

```json
{
  "prompt": "include instance numbers and call numbers",
  "followUpContext": {
    "previousPrompt": "original user question",
    "previousSql": "SELECT ...",
    "previousColumns": ["title"],
    "source": "ask"
  }
}
```

History context:

```json
{
  "prompt": "include instance numbers and call numbers",
  "followUpContext": {
    "jobId": "abc123",
    "source": "history"
  }
}
```

When `jobId` is provided, the backend loads the completed job, validates access using the existing history permissions model, and uses its stored SQL/name as the previous context.

## Backend Behavior

The controller builds an expanded prompt for `GeminiService::generateSqlWithShadow()`:

- Previous request/name
- Previous SQL
- Previous result columns when available
- Follow-up request
- Instruction to preserve all previous filters, joins, CTEs, and result-set semantics unless the follow-up explicitly changes them
- Instruction to add or modify only what the follow-up asks for

The generated SQL continues through existing safety checks, table policy checks, generated-SQL repairs, Postgres preflight, suggestions, timeout handling, and execution submission.

## Frontend Behavior

Ask state tracks an optional follow-up context:

- current Ask result: previous prompt, previous SQL, prior columns if available
- history result: job id and optional display label

Submitting in follow-up mode sends the user's typed text as `prompt` and the context as `followUpContext`.

After a successful follow-up generation, the new result becomes the active result, so another follow-up can chain from it.

History's `Ask follow-up` action routes to Ask with a query parameter such as `followUpJobId=<id>`. Ask reads that parameter, enters follow-up mode, and sends the job id context on submit.

## Error Handling

- Missing previous SQL for current-result follow-up: show a local message and do not submit.
- Missing or inaccessible history job: backend returns 404 or 403.
- Non-completed history job: backend returns 409.
- AI timeout handling remains the existing friendly timeout response.
- SQL validation failures use the existing formatter.

## Tests

Backend:

- `/api/nl` passes expanded follow-up context to Gemini when `previousSql` is supplied.
- `/api/nl` loads a completed history job when `followUpContext.jobId` is supplied.
- History follow-up rejects inaccessible, missing, or non-completed jobs.

Frontend:

- Ask sends `followUpContext` when submitting from current-result follow-up mode.
- Ask enters follow-up mode from `followUpJobId` URL parameter.
- History modal's `Ask follow-up` action navigates to Ask with the job id.

## Out of Scope

- Automatic detection of follow-up wording without user action.
- Multi-turn conversation storage beyond the active Ask state and existing history jobs.
- SQL AST rewriting of previous queries. The model receives the prior SQL and generates a revised query, then existing validation catches unsafe or invalid output.
