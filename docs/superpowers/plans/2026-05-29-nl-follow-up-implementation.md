# NL Follow-Up Query Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add explicit follow-up query generation from the Ask page and completed History jobs.

**Architecture:** Extend the existing `/api/nl` request with optional `followUpContext`. The controller resolves trusted history context by job id, expands follow-up wording into a complete prompt, and sends it through the existing Gemini, validation, preflight, suggestion, and execution flow. The frontend keeps a small follow-up context state in Ask and routes History follow-ups to Ask with `followUpJobId`.

**Tech Stack:** Yii/PHP backend, React/TypeScript frontend, Axios API client, Vitest frontend tests, existing PHP script tests.

---

### Task 1: Backend Follow-Up Prompt Expansion

**Files:**
- Modify: `backend/controllers/FolioQueryController.php`
- Test: `backend/tests/FolioQueryControllerNlFollowUpTest.php`

- [ ] **Step 1: Write the failing backend test**

Create `backend/tests/FolioQueryControllerNlFollowUpTest.php` with a Yii/controller harness that stubs `GeminiService::generateSqlWithShadow()` behind a new controller helper seam. The test should call a helper that builds a follow-up prompt from:

```php
[
    'prompt' => 'Provide the same list and include instance numbers and call numbers.',
    'followUpContext' => [
        'previousPrompt' => 'Please provide a list of titles with the location MRBC Reference Collection containing only records for which the MRBC Reference Collection is the only holding location in the 5 Colleges.',
        'previousSql' => 'SELECT inst.title FROM inventory.instance__t inst',
        'previousColumns' => ['title'],
        'source' => 'ask',
    ],
]
```

Assert the expanded prompt contains the previous prompt, previous SQL, previous columns, the follow-up request, and the instruction to preserve previous filters/joins/CTEs unless explicitly changed.

- [ ] **Step 2: Run backend test and confirm RED**

Run:

```bash
php backend/tests/FolioQueryControllerNlFollowUpTest.php
```

Expected: failure because the follow-up helper does not exist.

- [ ] **Step 3: Implement follow-up helper**

In `FolioQueryController`, add private helpers:

```php
private function normalizeFollowUpContext($rawContext)
private function resolveHistoryFollowUpContext(array $context)
private function buildFollowUpPrompt($prompt, array $context)
```

`buildFollowUpPrompt()` returns a plain text prompt with sections:

- Previous request
- Previous SQL
- Previous result columns
- Follow-up request
- Instructions to preserve prior query semantics and only add/modify requested outputs

- [ ] **Step 4: Wire helper into `actionNl()`**

In `actionNl()`, after campus resolution and before `generateSqlWithShadow()`, normalize `followUpContext`. If present, build `$effectivePrompt` and pass it to Gemini. Continue using the original submitted prompt for response suggestions only if no follow-up context is present; otherwise use the effective prompt for suggestions.

- [ ] **Step 5: Run backend test and confirm GREEN**

Run:

```bash
php backend/tests/FolioQueryControllerNlFollowUpTest.php
```

Expected: pass.

### Task 2: History Job Follow-Up Context

**Files:**
- Modify: `backend/controllers/FolioQueryController.php`
- Test: `backend/tests/FolioQueryControllerNlFollowUpTest.php`

- [ ] **Step 1: Extend failing backend test**

Add cases for `followUpContext.jobId`:

- completed job resolves to previous SQL/name
- missing job returns 404
- running job returns 409

Use a stub `QueryJob::findOne()` harness if possible; otherwise test the context resolver method through reflection with injectable job lookup.

- [ ] **Step 2: Run backend test and confirm RED**

Run:

```bash
php backend/tests/FolioQueryControllerNlFollowUpTest.php
```

Expected: fail until job id resolution exists.

- [ ] **Step 3: Implement history resolution**

`resolveHistoryFollowUpContext()` should:

- Load `QueryJob::findOne($jobId)`
- Return 404 if absent
- Return 409 if status is not `completed`
- Enforce existing owner/admin history permission
- Return previous prompt from job name or a fallback label
- Return previous SQL from `sql_text`
- Return `source => history`

- [ ] **Step 4: Run backend test and confirm GREEN**

Run:

```bash
php backend/tests/FolioQueryControllerNlFollowUpTest.php
```

Expected: pass.

### Task 3: API Types And Client

**Files:**
- Modify: `frontend/src/types/schema.ts`
- Modify: `frontend/src/api/client.ts`
- Test: `frontend/src/api/client.followUp.test.ts`

- [ ] **Step 1: Write failing frontend API test**

Create a test that mocks Axios and calls:

```ts
askNl('include call numbers', 'Smith College', true, {
  source: 'ask',
  previousPrompt: 'original',
  previousSql: 'SELECT inst.title FROM inventory.instance__t inst',
  previousColumns: ['title'],
});
```

Assert the POST body includes `followUpContext`.

- [ ] **Step 2: Run frontend test and confirm RED**

Run:

```bash
npm test -- api/client.followUp.test.ts
```

Expected: fail because `askNl` accepts only three arguments.

- [ ] **Step 3: Implement types and client parameter**

Add `FollowUpContext` type with:

```ts
source: 'ask' | 'history';
previousPrompt?: string;
previousSql?: string;
previousColumns?: string[];
jobId?: string;
```

Update `askNl(prompt, campus, includeSuggestions, followUpContext?)` to include the context when supplied.

- [ ] **Step 4: Run frontend API test and confirm GREEN**

Run:

```bash
npm test -- api/client.followUp.test.ts
```

Expected: pass.

### Task 4: Ask Page Follow-Up Mode

**Files:**
- Modify: `frontend/src/pages/Ask.tsx`
- Test: `frontend/src/pages/Ask.followUp.test.tsx`

- [ ] **Step 1: Write failing Ask UI test**

Render Ask with a mocked successful NL result and completed result columns. Click `Ask follow-up`, type `include call numbers`, submit, and assert `askNl` receives `followUpContext` with prior prompt, prior SQL, and columns.

- [ ] **Step 2: Run Ask UI test and confirm RED**

Run:

```bash
npm test -- Ask.followUp.test.tsx
```

Expected: fail because there is no follow-up button or state.

- [ ] **Step 3: Implement Ask follow-up state**

Add state:

```ts
const [followUpContext, setFollowUpContext] = useState<FollowUpContext | null>(null);
```

Add `handleStartCurrentFollowUp()` that captures `history[0]?.prompt`, `nlResult.sql`, and `results?.columns`.

Update `askMut` to pass `request.followUpContext` to `askNl`.

Update `handleSubmit()` to include `followUpContext` and clear it after successful generation, replacing it with the new active result context.

- [ ] **Step 4: Add UI affordance**

Show an `Ask follow-up` button when `nlResult?.sql` exists. In follow-up mode, show a small context banner and a cancel button.

- [ ] **Step 5: Run Ask UI test and confirm GREEN**

Run:

```bash
npm test -- Ask.followUp.test.tsx
```

Expected: pass.

### Task 5: History Follow-Up Route

**Files:**
- Modify: `frontend/src/pages/History.tsx`
- Modify: `frontend/src/pages/history/HistoryResultsModal.tsx`
- Modify: `frontend/src/pages/Ask.tsx`
- Test: `frontend/src/pages/history/HistoryResultsModal.followUp.test.tsx`

- [ ] **Step 1: Write failing history modal test**

Render `HistoryResultsModal` with a completed item. Click `Ask follow-up` and assert callback receives the job id.

- [ ] **Step 2: Run history test and confirm RED**

Run:

```bash
npm test -- HistoryResultsModal.followUp.test.tsx
```

Expected: fail because no callback/button exists.

- [ ] **Step 3: Implement modal callback**

Add `onAskFollowUp(jobId: string)` prop to `HistoryResultsModal` and render an `Ask follow-up` button.

In `History.tsx`, implement:

```ts
const handleAskHistoryFollowUp = (jobId: string) => {
  navigate(`/ask?followUpJobId=${encodeURIComponent(jobId)}`);
};
```

- [ ] **Step 4: Implement Ask URL mode**

In `Ask.tsx`, read `followUpJobId` from search params. If present, set follow-up context:

```ts
{ source: 'history', jobId }
```

Show the follow-up banner and submit the typed prompt with that context.

- [ ] **Step 5: Run history test and confirm GREEN**

Run:

```bash
npm test -- HistoryResultsModal.followUp.test.tsx
```

Expected: pass.

### Task 6: Full Verification

**Files:**
- No new files

- [ ] **Step 1: Run focused backend tests**

Run:

```bash
php backend/tests/FolioQueryControllerNlFollowUpTest.php
php backend/tests/GeminiServiceInventoryTitleRepairTest.php
```

Expected: pass.

- [ ] **Step 2: Run focused frontend tests**

Run:

```bash
npm test -- api/client.followUp.test.ts Ask.followUp.test.tsx HistoryResultsModal.followUp.test.tsx Ask.errorFormatting.test.ts
```

Expected: pass.

- [ ] **Step 3: Build frontend**

Run:

```bash
npm run build
```

Expected: pass, allowing the existing Vite chunk-size warning.

- [ ] **Step 4: Check whitespace and status**

Run:

```bash
git diff --check
git status --short
```

Expected: no whitespace errors; only intentional changed files.

- [ ] **Step 5: Commit and push**

Run:

```bash
git add backend/controllers/FolioQueryController.php backend/tests/FolioQueryControllerNlFollowUpTest.php frontend/src/types/schema.ts frontend/src/api/client.ts frontend/src/api/client.followUp.test.ts frontend/src/pages/Ask.tsx frontend/src/pages/Ask.followUp.test.tsx frontend/src/pages/History.tsx frontend/src/pages/history/HistoryResultsModal.tsx frontend/src/pages/history/HistoryResultsModal.followUp.test.tsx docs/superpowers/plans/2026-05-29-nl-follow-up-implementation.md
git commit -m "Add NL follow-up query flow"
git push origin main
```

Expected: commit and push succeed.
