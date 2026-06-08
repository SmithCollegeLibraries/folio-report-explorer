# Ask Pause And Messaging Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce unnecessary Ask AI pauses by converting unsupported-but-unambiguous exploratory generation from a blocking approval flow into a non-blocking advisory notice, while preserving true clarification and policy stops.

**Architecture:** Keep the resolver and policy checks as gates. Change `GeminiService::generateSqlWithShadow()` so unsupported query families automatically run exploratory SQL and attach notice metadata, then update Ask UI rendering so advisory notices appear with results instead of as required clarification cards.

**Tech Stack:** PHP/Yii backend, React/TypeScript frontend, Vitest, standalone PHP test scripts.

---

## File Structure

- Modify `backend/services/GeminiService.php`: replace exploratory approval response semantics with advisory notice semantics and add helper metadata.
- Modify `backend/controllers/FolioQueryController.php`: convert soft Ask generation recovery from blocking approval to automatic exploratory retry where no policy block exists.
- Modify `backend/tests/GeminiServiceExploratoryGateTest.php`: assert unsupported prompts return generated exploratory SQL metadata, not approval clarification.
- Modify `backend/tests/FolioQueryControllerAskContinuationPolicyTest.php`: assert policy stops remain hard stops and soft failures become advisory recovery payloads.
- Modify `frontend/src/types/schema.ts`: add typed `exploratoryNotice` metadata.
- Modify `frontend/src/pages/Ask.tsx`: add staff-facing notice copy helpers and render advisory notices near SQL/results.
- Modify `frontend/src/pages/Ask.errorFormatting.test.ts`: add copy and response-shape tests for advisory notice behavior.

## Task 1: Backend Exploratory Notice Response

**Files:**
- Modify: `backend/services/GeminiService.php`
- Test: `backend/tests/GeminiServiceExploratoryGateTest.php`

- [ ] **Step 1: Replace the old approval expectations with failing advisory expectations**

Edit `backend/tests/GeminiServiceExploratoryGateTest.php`. Replace the assertions after `$unsupported = $builder->invoke(...)` with:

```php
assertSameValue(false, $unsupported['needsClarification'] ?? false, 'Unsupported prompts should not require clarification when no ambiguous term was found.');
assertSameValue(false, $unsupported['needsExploratoryApproval'] ?? false, 'Unsupported prompts should not require explicit exploratory approval before SQL generation.');
assertSameValue('exploratory_legacy_freeform', $unsupported['route'] ?? null, 'Unsupported prompts should continue through exploratory legacy SQL generation.');
assertSameValue('unsupported_query_family', $unsupported['routeReason'] ?? null, 'Unsupported prompts should preserve the route reason that forced exploratory generation.');
assertSameValue('exploratory', $unsupported['mode'] ?? null, 'Unsupported prompts should be labeled as exploratory.');
assertContainsText('SELECT', strtoupper($unsupported['sql'] ?? ''), 'Unsupported prompts should return generated exploratory SQL.');
assertSameValue(
    'AI-assisted query',
    $unsupported['exploratoryNotice']['title'] ?? null,
    'Exploratory results should include staff-facing notice metadata.'
);
assertContainsText(
    'verified report pattern',
    $unsupported['exploratoryNotice']['message'] ?? '',
    'Exploratory notice should explain the limitation without internal compiler terms.'
);
assertContainsText(
    'Review the results and SQL',
    $unsupported['exploratoryNotice']['message'] ?? '',
    'Exploratory notice should tell staff what action to take.'
);
assertSameValue(
    'unsupported_query_family',
    $unsupported['exploratoryNotice']['reason'] ?? null,
    'Exploratory notice should expose a stable reason for telemetry and review queues.'
);
```

Also replace the source-string assertions at the bottom with:

```php
$source = file_get_contents($geminiServicePath);
assertContainsText(
    'buildExploratoryNotice',
    $source,
    'GeminiService should centralize exploratory notice copy and metadata.'
);
assertContainsText(
    'unsupported_query_family',
    $source,
    'Unsupported family prompts should preserve a stable exploratory reason.'
);
assertContainsText(
    'exploratory_legacy_freeform',
    $source,
    'Unsupported family prompts should be labeled as exploratory SQL generation.'
);
```

- [ ] **Step 2: Run the backend exploratory test and verify it fails**

Run:

```bash
php backend/tests/GeminiServiceExploratoryGateTest.php
```

Expected: FAIL because `buildExploratoryApprovalResponse()` still returns `needsClarification`, `needsExploratoryApproval`, and `sql => null`.

- [ ] **Step 3: Implement exploratory notice generation**

In `backend/services/GeminiService.php`, replace `buildExploratoryApprovalResponse()` with these helpers:

```php
private static function generateExploratorySqlResponse(string $prompt, $campus = null, string $reason = 'unsupported_query_family'): array
{
    $primary = self::generateSql($prompt, $campus, true, false);
    $primary['mode'] = 'exploratory';
    $primary['exploratory'] = true;
    $primary['repeatabilityWarning'] = self::getExploratoryRepeatabilityWarning();
    $primary['route'] = 'exploratory_legacy_freeform';
    $primary['routeReason'] = $reason;
    $primary['needsClarification'] = false;
    $primary['needsExploratoryApproval'] = false;
    $primary['exploratoryNotice'] = self::buildExploratoryNotice($reason);

    self::logRouteSelection('exploratory_legacy_freeform', $reason, [
        'query' => [],
    ]);
    self::logNlTelemetry('nl2sql.generated', [
        'route' => 'exploratory_legacy_freeform',
        'routeReason' => $reason,
        'promptFingerprint' => self::fingerprintPrompt($prompt),
        'dataSource' => $primary['dataSource'] ?? 'folio',
        'mode' => 'exploratory',
    ]);

    return $primary;
}

private static function buildExploratoryNotice(string $reason): array
{
    return [
        'title' => 'AI-assisted query',
        'message' => 'I could not match this request to a verified report pattern, so I built a best-effort query. Review the results and SQL before using them.',
        'detail' => 'Similar wording may produce different SQL until this request type is reviewed and promoted to a verified report pattern.',
        'reason' => $reason,
    ];
}
```

In `generateSqlWithShadow()`, replace:

```php
return self::buildExploratoryApprovalResponse(
    (string)$effectivePrompt,
    $campus,
    self::promptRequiresLegacyFreeform($effectivePrompt)
        ? 'canonical_path_unavailable_for_marc_source_records'
        : 'unsupported_query_family'
);
```

with:

```php
$exploratoryReason = self::promptRequiresLegacyFreeform($effectivePrompt)
    ? 'canonical_path_unavailable_for_marc_source_records'
    : 'unsupported_query_family';
$primary = self::generateExploratorySqlResponse((string)$effectivePrompt, $campus, $exploratoryReason);

if (!empty($referenceResolution['guidanceLines'])) {
    $primary['referenceResolver'] = [
        'resolved' => true,
        'guidanceLines' => $referenceResolution['guidanceLines'],
    ];
}

return $primary;
```

In the existing `$allowExploratory` branch, replace the route reason and add notice metadata:

```php
$primary['routeReason'] = 'user_requested_exploratory_generation';
$primary['needsClarification'] = false;
$primary['needsExploratoryApproval'] = false;
$primary['exploratoryNotice'] = self::buildExploratoryNotice('user_requested_exploratory_generation');
```

- [ ] **Step 4: Run the exploratory test and verify it passes**

Run:

```bash
php backend/tests/GeminiServiceExploratoryGateTest.php
```

Expected: PASS and output includes `GeminiService exploratory gate test passed`.

- [ ] **Step 5: Commit Task 1**

Run:

```bash
git add backend/services/GeminiService.php backend/tests/GeminiServiceExploratoryGateTest.php
git commit -m "Convert exploratory approval to advisory generation"
```

## Task 2: Ask Recovery Policy

**Files:**
- Modify: `backend/controllers/FolioQueryController.php`
- Test: `backend/tests/FolioQueryControllerAskContinuationPolicyTest.php`

- [ ] **Step 1: Update the controller policy test for advisory recovery**

In `backend/tests/FolioQueryControllerAskContinuationPolicyTest.php`, replace the soft failure assertions with:

```php
assertSameValue(200, Yii::$app->response->statusCode, 'Soft Ask failures should not return an HTTP error status.');
assertSameValue(false, $softFailure['needsClarification'] ?? false, 'Soft Ask failures should not require an acknowledgment click.');
assertSameValue(false, $softFailure['needsExploratoryApproval'] ?? false, 'Soft Ask failures should not require exploratory approval.');
assertSameValue('ask_generation_recovery', $softFailure['routeReason'] ?? null, 'Soft Ask failures should expose a stable recovery route reason.');
assertSameValue('AI-assisted query', $softFailure['exploratoryNotice']['title'] ?? null, 'Soft Ask recovery should return advisory notice metadata.');
assertContainsText('best-effort query', $softFailure['exploratoryNotice']['message'] ?? '', 'Soft Ask recovery should use staff-facing advisory copy.');
assertSameValue('exploratory', $softFailure['mode'] ?? null, 'Soft Ask recovery should be labeled exploratory.');
```

- [ ] **Step 2: Run the controller policy test and verify it fails**

Run:

```bash
php backend/tests/FolioQueryControllerAskContinuationPolicyTest.php
```

Expected: FAIL because `buildAskContinuationFromFailure()` still returns `needsClarification => true` and approval options.

- [ ] **Step 3: Implement non-blocking recovery payload**

In `backend/controllers/FolioQueryController.php`, in `buildAskContinuationFromFailure()`, replace the non-policy return payload with:

```php
return [
    'needsClarification' => false,
    'needsExploratoryApproval' => false,
    'mode' => 'exploratory',
    'message' => 'I could not match this request to a verified report pattern, so I built a best-effort query. Review the results and SQL before using them.',
    'exploratoryNotice' => [
        'title' => 'AI-assisted query',
        'message' => 'I could not match this request to a verified report pattern, so I built a best-effort query. Review the results and SQL before using them.',
        'detail' => 'Similar wording may produce different SQL until this request type is reviewed and promoted to a verified report pattern.',
        'reason' => $routeReason,
    ],
    'warnings' => [
        'The first attempt could not produce fully validated SQL.',
    ],
    'suggestions' => [],
    'route' => 'exploratory_recovery',
    'routeReason' => $routeReason,
    'recoveryContext' => [
        'campus' => $campus,
        'promptFingerprint' => $this->fingerprintPrompt($prompt),
    ],
];
```

This task only changes the recovery response shape. It does not generate fallback SQL from this private helper because the existing test isolates policy shape. Full `/api/nl` exploratory generation is covered by Task 1.

- [ ] **Step 4: Run the controller policy test and verify it passes**

Run:

```bash
php backend/tests/FolioQueryControllerAskContinuationPolicyTest.php
```

Expected: PASS and output includes `FolioQueryController Ask continuation policy test passed`.

- [ ] **Step 5: Commit Task 2**

Run:

```bash
git add backend/controllers/FolioQueryController.php backend/tests/FolioQueryControllerAskContinuationPolicyTest.php
git commit -m "Make Ask recovery messaging non-blocking"
```

## Task 3: Frontend Types And Notice Copy Helpers

**Files:**
- Modify: `frontend/src/types/schema.ts`
- Modify: `frontend/src/pages/Ask.tsx`
- Test: `frontend/src/pages/Ask.errorFormatting.test.ts`

- [ ] **Step 1: Add failing frontend tests for notice copy**

Append these tests to `frontend/src/pages/Ask.errorFormatting.test.ts`:

```ts
  it('formats exploratory notices in staff-facing language', () => {
    const notice = AskPage.getExploratoryNoticeCopy?.({
      title: 'AI-assisted query',
      message: 'I could not match this request to a verified report pattern, so I built a best-effort query. Review the results and SQL before using them.',
      detail: 'Similar wording may produce different SQL until this request type is reviewed and promoted to a verified report pattern.',
      reason: 'unsupported_query_family',
    });

    expect(notice?.title).toBe('AI-assisted query');
    expect(notice?.message).toContain('best-effort query');
    expect(notice?.message).not.toContain('canonical compiler path');
    expect(notice?.detail).toContain('Similar wording');
  });

  it('falls back to user-facing exploratory notice copy when metadata is missing', () => {
    const notice = AskPage.getExploratoryNoticeCopy?.({
      mode: 'exploratory',
      repeatabilityWarning: 'This path uses model-assisted SQL generation without a checked-in canonical compiler, so it may vary between runs.',
    });

    expect(notice?.title).toBe('AI-assisted query');
    expect(notice?.message).toContain('verified report pattern');
    expect(notice?.message).not.toContain('canonical compiler');
  });
```

- [ ] **Step 2: Run the frontend test and verify it fails**

Run:

```bash
cd frontend && npm test -- Ask.errorFormatting.test.ts
```

Expected: FAIL because `getExploratoryNoticeCopy` is not exported.

- [ ] **Step 3: Add `ExploratoryNotice` type**

In `frontend/src/types/schema.ts`, add this interface above `NlResponse`:

```ts
export interface ExploratoryNotice {
  title?: string;
  message?: string;
  detail?: string;
  reason?: string;
}
```

Then add this property to `NlResponse`:

```ts
  exploratoryNotice?: ExploratoryNotice;
```

- [ ] **Step 4: Add notice copy helper**

In `frontend/src/pages/Ask.tsx`, below `formatQuerySubmitError()`, add:

```ts
type ExploratoryNoticeCopy = {
  title: string;
  message: string;
  detail?: string;
};

export function getExploratoryNoticeCopy(result: Pick<NlResponse, 'exploratoryNotice' | 'mode' | 'repeatabilityWarning'> | null | undefined): ExploratoryNoticeCopy | null {
  if (!result?.exploratoryNotice && result?.mode !== 'exploratory' && !result?.repeatabilityWarning) {
    return null;
  }

  return {
    title: result.exploratoryNotice?.title?.trim() || 'AI-assisted query',
    message: result.exploratoryNotice?.message?.trim()
      || 'I could not match this request to a verified report pattern, so I built a best-effort query. Review the results and SQL before using them.',
    detail: result.exploratoryNotice?.detail?.trim()
      || (result.mode === 'exploratory'
        ? 'Similar wording may produce different SQL until this request type is reviewed and promoted to a verified report pattern.'
        : undefined),
  };
}
```

- [ ] **Step 5: Run the frontend test and verify it passes**

Run:

```bash
cd frontend && npm test -- Ask.errorFormatting.test.ts
```

Expected: PASS for `Ask.errorFormatting.test.ts`.

- [ ] **Step 6: Commit Task 3**

Run:

```bash
git add frontend/src/types/schema.ts frontend/src/pages/Ask.tsx frontend/src/pages/Ask.errorFormatting.test.ts
git commit -m "Add exploratory notice copy helpers"
```

## Task 4: Frontend Non-Blocking Notice Rendering

**Files:**
- Modify: `frontend/src/pages/Ask.tsx`
- Test: `frontend/src/pages/Ask.errorFormatting.test.ts`

- [ ] **Step 1: Add a helper test for blocking clarification detection**

Append this test to `frontend/src/pages/Ask.errorFormatting.test.ts`:

```ts
  it('does not treat advisory exploratory results as blocking clarifications', () => {
    expect(AskPage.shouldShowBlockingClarification?.({
      needsClarification: false,
      needsExploratoryApproval: false,
      mode: 'exploratory',
      exploratoryNotice: {
        title: 'AI-assisted query',
        message: 'I could not match this request to a verified report pattern, so I built a best-effort query. Review the results and SQL before using them.',
      },
    })).toBe(false);

    expect(AskPage.shouldShowBlockingClarification?.({
      needsClarification: true,
      clarificationItems: [
        {
          term: 'Duplaix',
          clarificationKey: 'safe_probe.duplaix.collection',
          question: 'Where should I search?',
          options: [],
        },
      ],
    })).toBe(true);
  });
```

- [ ] **Step 2: Run the frontend test and verify it fails**

Run:

```bash
cd frontend && npm test -- Ask.errorFormatting.test.ts
```

Expected: FAIL because `shouldShowBlockingClarification` is not exported.

- [ ] **Step 3: Add blocking clarification helper**

In `frontend/src/pages/Ask.tsx`, below `getExploratoryNoticeCopy()`, add:

```ts
export function shouldShowBlockingClarification(result: NlResponse | null | undefined): boolean {
  return result?.needsClarification === true;
}
```

- [ ] **Step 4: Use the helper in clarification render conditions**

In `frontend/src/pages/Ask.tsx`, replace each condition shaped like:

```tsx
nlResult && !isLoading && nlResult.needsClarification
```

with:

```tsx
nlResult && !isLoading && shouldShowBlockingClarification(nlResult)
```

There are four render blocks to update: batch clarification in the main pane, single clarification in the main pane, batch clarification in the right pane, and single clarification in the right pane.

- [ ] **Step 5: Render exploratory notice near generated output**

In `frontend/src/pages/Ask.tsx`, inside the generated result area before the SQL/explanation blocks, add:

```tsx
                {getExploratoryNoticeCopy(nlResult) && (
                  <div className="mb-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                    <div className="font-semibold">{getExploratoryNoticeCopy(nlResult)?.title}</div>
                    <div className="mt-1">{getExploratoryNoticeCopy(nlResult)?.message}</div>
                    {getExploratoryNoticeCopy(nlResult)?.detail && (
                      <div className="mt-1 text-xs text-sky-800">{getExploratoryNoticeCopy(nlResult)?.detail}</div>
                    )}
                  </div>
                )}
```

If there are separate desktop/mobile generated-result render areas, add the same notice in both places or extract a small local `ExploratoryNoticePanel` component:

```tsx
function ExploratoryNoticePanel({ result }: { result: NlResponse | null }) {
  const notice = getExploratoryNoticeCopy(result);
  if (!notice) return null;

  return (
    <div className="mb-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
      <div className="font-semibold">{notice.title}</div>
      <div className="mt-1">{notice.message}</div>
      {notice.detail && <div className="mt-1 text-xs text-sky-800">{notice.detail}</div>}
    </div>
  );
}
```

- [ ] **Step 6: Run the frontend tests**

Run:

```bash
cd frontend && npm test -- Ask.errorFormatting.test.ts
```

Expected: PASS.

- [ ] **Step 7: Commit Task 4**

Run:

```bash
git add frontend/src/pages/Ask.tsx frontend/src/pages/Ask.errorFormatting.test.ts
git commit -m "Render exploratory notices without blocking Ask"
```

## Task 5: End-To-End Verification And Regression Checks

**Files:**
- Verify only unless a failure reveals a needed focused fix.

- [ ] **Step 1: Run backend targeted tests**

Run:

```bash
php backend/tests/GeminiServiceExploratoryGateTest.php
php backend/tests/FolioQueryControllerAskContinuationPolicyTest.php
php backend/tests/ReferenceResolverServiceTest.php
php backend/tests/ResolverClarificationServiceTest.php
```

Expected: all commands PASS. The resolver tests prove ambiguous local terms still stop.

- [ ] **Step 2: Run frontend targeted tests**

Run:

```bash
cd frontend && npm test -- Ask.errorFormatting.test.ts Ask.followUp.test.ts
```

Expected: all selected Vitest tests PASS.

- [ ] **Step 3: Run frontend build**

Run:

```bash
cd frontend && npm run build
```

Expected: TypeScript and Vite build complete without errors.

- [ ] **Step 4: Inspect changed files for forbidden UI copy**

Run:

```bash
rg -n "canonical compiler path|needs approval|Do you want me to try anyway|This request needs approval" frontend/src/pages/Ask.tsx backend/services/GeminiService.php backend/controllers/FolioQueryController.php
```

Expected: no matches in staff-facing UI copy. Matches inside deleted test text or comments should be removed unless they explicitly document old behavior.

- [ ] **Step 5: Commit verification fixes if needed**

If any focused fixes were required in Task 5, run:

```bash
git add backend frontend
git commit -m "Verify Ask exploratory notice flow"
```

If no fixes were required, do not create an empty commit.

## Self-Review

Spec coverage:

- Fewer unnecessary pauses: Task 1 removes backend exploratory approval; Task 4 prevents advisory exploratory results from rendering as blocking clarification cards.
- True stops remain: Task 1 keeps resolver clarification first; Task 5 runs resolver regression tests.
- Clearer staff messaging: Task 3 adds notice copy helper; Task 4 renders it; Task 5 scans for old approval/internal wording.
- Learning and drift path: Task 1 preserves `routeReason`; Task 3 types `exploratoryNotice.reason`; existing `ai_query_feedback`, correction, route, SQL hash, and clarification event capture remain available for review workflow.

Placeholder scan:

- No `TBD`, `TODO`, or "implement later" steps are present.
- Every code-changing task includes specific code or exact replacement guidance.

Type consistency:

- Frontend response metadata is consistently named `exploratoryNotice`.
- Helper names are consistently `getExploratoryNoticeCopy` and `shouldShowBlockingClarification`.
- Backend route is consistently `exploratory_legacy_freeform` for generated exploratory SQL.
