# Permanent Query-History Deletion Design

**Date:** 2026-07-19
**Status:** Approved design
**Backlog item:** APP-002

## Goal

Make query-history deletion permanent, safe, and understandable. A successfully deleted job must remain absent through later polling and refreshes, while active jobs and files outside the configured export directory remain protected.

## Confirmed root causes

The backend delete action calls `QueryJob::findOne((int) $id)`. Query-job identifiers are UUID strings, so the cast converts a normal identifier to `0` and prevents the intended row from being found.

The frontend removes a deleted row from local state, but `useHistoryData()` does not distinguish an older in-flight response from a newer mutation. A response started before deletion can therefore replace the current list and restore the deleted row visually.

The backend currently permits deletion in every job state and does not clean up an associated CSV export.

## Architecture

### Backend deletion service

Create a focused `QueryHistoryDeletionService` responsible for deletion eligibility and export cleanup. The controller remains responsible for locating the job and enforcing owner-or-administrator authorization.

The service accepts a `QueryJob` and follows this sequence:

1. Refresh the job and allow only `completed`, `failed`, or `cancelled`.
2. Return a domain conflict for `pending`, `pending_export`, `running`, or `cancelling`, with guidance to stop the job first.
3. If the job has an export path, validate it against the canonical `@runtime/exports` directory.
4. Delete a normal export file only when its canonical parent is the export directory and its basename is exactly `<job UUID>.csv`.
5. Refuse to touch symlinks, traversal paths, directories, or files outside that directory.
6. If a valid in-scope export exists but cannot be removed, retain the database row and return a retryable server error.
7. Delete the `query_jobs` row only after required in-scope cleanup succeeds.

An unsafe or out-of-scope path is logged and never removed. The history row may still be deleted because retaining a user-visible history entry would not make the external path safe or recoverable. Cleanup tooling can report that orphan separately.

### Controller contract

`DELETE /api/query/history/<uuid>` must use the UUID string without numeric casting.

Outcomes:

- `200`: `{ "success": true, "jobId": "<uuid>" }`
- `403`: the job exists but the caller is neither its owner nor an administrator
- `404`: no job has that UUID
- `409`: the job is active, with `Stop this query before deleting it from history.`
- `500`: an eligible job or its validated export could not be removed

The authorization comparison must reject an unauthenticated/null user rather than treating null as an accidental owner.

The history list must set `canDelete` only when both conditions are true:

- the viewer is the owner or an administrator; and
- the status is `completed`, `failed`, or `cancelled`.

### Frontend request ordering

`useHistoryData()` will own a monotonically increasing request generation. Each `load()` captures its generation and applies results only if it is still current. Mutations call `invalidateLoads()` before changing local history state, which makes every response started before the mutation stale without cancelling future polling.

This is preferred over `AbortController` because the history client already has multiple automatic and manual load paths. A generation guard protects all of them without coupling React state to Axios cancellation objects.

### Frontend mutation behavior

Single and batch deletion continue using the existing endpoint. On successful deletion the page will:

- invalidate older history loads;
- remove successful IDs from `items` and selection;
- reduce `total` by exactly the number of successful deletions;
- close and clear the results modal if its job was deleted;
- navigate from `/history/<uuid>` to `/history` when closing that modal;
- move to the previous page when the successful deletions remove every displayed row from a non-first page;
- preserve successful deletions when another batch member fails; and
- report `Deleted <n>, failed to delete <m>.` for partial failures.

If deletion fails, the row remains visible and the API’s safe error copy is shown.

## State and pagination rules

`completed`, `failed`, and `cancelled` are terminal and deletable. `pending`, `pending_export`, `running`, and `cancelling` are active and non-deletable.

When all currently displayed rows are successfully deleted and `offset > 0`, the next offset is `max(0, offset - PAGE_LIMIT)`. Changing the offset triggers a fresh load for the previous page. Deleting only some rows keeps the current offset; automatic polling or a later manual load may fill the page normally.

Totals must never become negative: `max(0, total - successfulDeletionCount)`.

## Safety and error handling

- Never recursively delete paths.
- Never delete a path derived only from user input.
- Never delete a directory or symlink.
- Never delete a file outside the canonical runtime export directory.
- Never delete or mutate saved queries, reports, dashboards, query logs, or unrelated jobs.
- Do not expose filesystem paths or exception details in API responses.
- Log unsafe export paths and cleanup failures for administrators.
- Preserve the query row when an eligible, validated export exists but its deletion fails.

## Testing

Backend regression coverage will prove:

- UUID lookup succeeds without integer casting.
- Owners and administrators can delete eligible jobs.
- Non-owners receive `403` with no mutation.
- Active states receive `409` with no mutation.
- Normal export files are removed with their jobs.
- Files outside the export directory, traversal paths, symlinks, and directories are untouched.
- A failed in-scope unlink retains the database row.
- `canDelete` is false for active rows even for owners and administrators.

Frontend regression coverage will prove:

- A load started before deletion cannot restore the deleted row.
- A later load still applies normally.
- Single deletion removes the row, selection, and modal state.
- Batch partial failures retain failed rows and successful deletions.
- Deleting the final displayed row on a later page moves to the previous page.
- API error messages remain visible without removing the row.

The release gate is two consecutive backend suite runs, the full frontend test suite, a production frontend build, and a production smoke test that deletes a completed job and observes it remain absent for multiple polling intervals and a manual refresh.

## Rollback

The feature requires no database migration. Rollback consists of reverting the backend service/controller/list changes and the frontend generation/mutation changes. Existing query history data remains compatible throughout rollback.
