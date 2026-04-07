# Update: Blocked Query Message Improved

- Timestamp: 2026-04-06 10:39:08
- Ticket: NL2SQL-001
- Status: Completed (UX Follow-up)

## Summary
- Improved Ask AI error handling so blocked requests show a clear user-facing message.
- 403 NL errors now render as "Query blocked: <reason>" using backend payload details.
- Removed generic Axios error display for this flow.

## Changes Made
- Added Axios-aware error parsing helper in Ask page.
- Added NL-specific formatter that maps HTTP 403 to "Query blocked" wording.
- Kept non-403 failures as "AI error" with extracted API message.

## Files Changed
- [frontend/src/pages/Ask.tsx](../frontend/src/pages/Ask.tsx)
- [updates/2026-04-06_10-39-08_NL2SQL-001_blocked-query-message-improved.md](2026-04-06_10-39-08_NL2SQL-001_blocked-query-message-improved.md)

## Validation Evidence
- Type/lint diagnostics for Ask page: no errors.
- Verified diff now renders `formatNlError(askMut.error)` in the Ask error banner.
- Frontend full build currently fails due pre-existing unrelated TypeScript errors in other files.

## Open Risks or Follow-ups
- Console and other pages still use ad-hoc API error rendering; consider centralizing error formatting in API client.

## Next Ticket
- NL2SQL-002 - Builder Identifier Validation
