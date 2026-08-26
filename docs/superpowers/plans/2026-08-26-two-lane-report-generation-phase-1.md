# Two-Lane Report Generation Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore report availability by routing every ordinary Ask request through a verified canonical lane first and then automatically through an AI-built lane for every non-safety canonical failure, while showing a stable provenance label on every successful result and removing clarification/recovery blockers from the normal flow.

**Architecture:** `GeminiService::generateSqlWithShadow()` becomes the single two-lane orchestrator. Reference resolution and legacy ambiguity detection contribute model-only context but never terminate ordinary generation. Canonical success is marked `verified_pattern`; no match, missing slots, family mismatch, compiler failure, semantic uncertainty, or canonical database-preflight failure enters the AI-built lane. If canonical SQL already exists, the AI lane repairs that candidate rather than discarding it. `FolioQueryController` keeps SQL safety, authorization, cancellation, connectivity, resource, and database-preflight gates, but treats every safe generated candidate as repair-eligible. `AskResponseContractService` owns the stable provenance contract, and Ask renders both provenance values in the same result layout.

**Tech Stack:** PHP 7.2+, Yii2 services/controllers, PostgreSQL SQL validation and preflight, React 18, TypeScript, TanStack Query, Vitest, Testing Library.

**Spec:** `docs/superpowers/specs/2026-08-26-two-lane-report-generation-design.md`

## Global Constraints

- This plan implements **Phase 1 only**. Do not add the Phase 2 `Refine this report` workflow or change revision lineage behavior here.
- Preserve all hard gates: single `SELECT`, blocked keywords and writes, restricted schemas/tables/columns, authorization, database connectivity, cancellation, timeout, and configured resource limits.
- Treat canonical matching, slot extraction, compiler coverage, reference confidence, semantic conformance, and parser limitations as routing/repair signals rather than user tasks.
- A successful response contains exactly one stable provenance pair:
  - `generationProvenance: "verified_pattern"`, `provenanceLabel: "Verified pattern"`; or
  - `generationProvenance: "ai_built"`, `provenanceLabel: "AI-built"`.
- Only SQL produced unchanged by a supported backend compiler after all canonical checks and database preflight is `verified_pattern`.
- If canonical SQL reaches semantic or database-preflight failure, preserve it as the AI lane's seeded candidate. AI must review, repair, or return it unchanged; the final result is `ai_built`.
- A canonical database-preflight failure is not terminal until the shared AI repair budget is exhausted.
- Semantic/reference checks may consume the AI repair budget, but they never become terminal execution gates. If the final AI candidate is a safe single `SELECT` and passes database preflight, return it as `ai_built` with a bounded assumption/advisory even when a parser still cannot prove its meaning.
- Do not expose raw resolver guidance, schema predicates, database errors, validator stages, repair prompts, or candidate SQL rejected by safety checks.
- With the two-lane switch enabled, ordinary report requests must not return `needsClarification`, `route: clarification`, `route: exploratory_recovery`, correction instructions, or “request preserved” copy.
- Keep existing internal `route`, `routeReason`, compiler, repair, and evidence fields for compatibility, telemetry, and administrator review. They must not drive the user-facing provenance label.
- The rollout switch defaults to the new two-lane path. Its disabled value retains the current strict blocker behavior only as an administrative rollback.
- Preserve all unrelated working-tree changes.

---

## File and Responsibility Map

- `backend/config/params.php`: default-on `nl2sqlTwoLaneEnabled` rollout switch.
- `backend/services/Nl2sqlRuntimePreflightService.php`: expose switch state and warn only when the rollback path is active.
- `backend/exceptions/CanonicalLaneFallbackException.php`: internal, sanitized signal for a non-safety canonical failure that must enter Lane 2.
- `backend/services/AskResponseContractService.php`: stable success provenance constants, labels, and response decoration.
- `backend/services/AskGenerationEvidenceService.php`: persist stable provenance in server-trusted generation evidence and linked query-job metadata.
- `backend/services/ReferenceResolverService.php`: convert resolved values, unresolved terms, and ranked clarification candidates into bounded model-only generation context.
- `backend/services/GeminiService.php`: one two-lane orchestration path, canonical fallback classification, seeded-candidate AI repair, terminal AI failure shape, and route telemetry.
- `backend/controllers/FolioQueryController.php`: make canonical preflight failures AI-repair eligible, preserve provenance through repair, and return concise technical failures after exhaustion.
- `frontend/src/types/schema.ts`: stable provenance types.
- `frontend/src/components/AskTrustNotice.tsx`: always-visible provenance badge and nonblocking assumptions/review notice.
- `frontend/src/pages/Ask.tsx`: one successful-results branch; remove normal clarification and exploratory-recovery rendering and obsolete correction handlers.
- Backend and frontend tests named in each task: TDD coverage for routing, safety, response shape, UI, and required regression prompts.

---

### Task 1: Add the rollout switch and stable provenance contract

**Files:**
- Modify: `backend/config/params.php`
- Modify: `backend/services/Nl2sqlRuntimePreflightService.php`
- Modify: `backend/services/AskResponseContractService.php`
- Modify: `backend/services/AskGenerationEvidenceService.php`
- Modify: `backend/tests/Nl2sqlRuntimePreflightServiceTest.php`
- Modify: `backend/tests/AskResponseContractServiceTest.php`
- Modify: `backend/tests/AskGenerationEvidenceServiceTest.php`

**Interfaces:**
- Produces: `Yii::$app->params['nl2sqlTwoLaneEnabled']`.
- Produces: `AskResponseContractService::PROVENANCE_VERIFIED_PATTERN` and `AskResponseContractService::PROVENANCE_AI_BUILT`.
- Produces: `AskResponseContractService::withGenerationProvenance(array $result, string $provenance): array`.
- Contract: provenance is added only to successful responses containing SQL.

- [ ] **Step 1: Write failing provenance and rollout tests**

Extend `backend/tests/AskResponseContractServiceTest.php`:

```php
$verified = AskResponseContractService::withGenerationProvenance(
    ['sql' => 'SELECT 1', 'mode' => 'canonical'],
    AskResponseContractService::PROVENANCE_VERIFIED_PATTERN
);
askResponseAssertSame('verified_pattern', $verified['generationProvenance'], 'Canonical success needs stable provenance.');
askResponseAssertSame('Verified pattern', $verified['provenanceLabel'], 'Canonical success needs the public label.');

$aiBuilt = AskResponseContractService::withGenerationProvenance(
    ['sql' => 'SELECT 1', 'mode' => 'exploratory'],
    AskResponseContractService::PROVENANCE_AI_BUILT
);
askResponseAssertSame('ai_built', $aiBuilt['generationProvenance'], 'AI success needs stable provenance.');
askResponseAssertSame('AI-built', $aiBuilt['provenanceLabel'], 'AI success needs the public label.');

$failure = AskResponseContractService::withGenerationProvenance(
    ['errorType' => 'sql_generation_failed'],
    AskResponseContractService::PROVENANCE_AI_BUILT
);
askResponseAssertSame(false, isset($failure['generationProvenance']), 'No-SQL failures must not claim successful provenance.');
```

Extend `backend/tests/AskGenerationEvidenceServiceTest.php` to prove the stable value survives trusted persistence evidence:

```php
$twoLaneEvidence = AskGenerationEvidenceService::build([
    'sql' => 'SELECT 1',
    'mode' => 'exploratory',
    'generationProvenance' => 'ai_built',
    'provenanceLabel' => 'AI-built',
], ['prompt' => 'Show one row']);
evidenceAssertSame(
    'ai_built',
    $twoLaneEvidence['provenance']['generationProvenance'] ?? null,
    'Stable generation provenance must reach administrator and query-job metadata.'
);
```

Add `'nl2sqlTwoLaneEnabled' => true` to the existing `$intentReadyReport` parameter array and `'nl2sql_two_lane_enabled' => true` to its persisted settings array. Then extend `backend/tests/Nl2sqlRuntimePreflightServiceTest.php` with these assertions and a rollback case:

```php
assertSameValue(true, $intentReadyReport['effective']['nl2sqlTwoLaneEnabled'], 'Two-lane routing should be visible in preflight.');

$rollback = $serviceClass::buildReport(
    ['nl2sqlTwoLaneEnabled' => false],
    ['nl2sql_two_lane_enabled' => false],
    $artifactPaths
);
assertTrueValue(
    count(array_filter($rollback['warnings'], function ($warning) {
        return strpos($warning, 'strict blocker rollback') !== false;
    })) === 1,
    'Preflight should identify the rollback path.'
);
```

- [ ] **Step 2: Run the focused tests and verify they fail**

Run:

```bash
php backend/tests/AskResponseContractServiceTest.php
php backend/tests/Nl2sqlRuntimePreflightServiceTest.php
php backend/tests/AskGenerationEvidenceServiceTest.php
```

Expected: undefined provenance constants/method and missing `nl2sqlTwoLaneEnabled` report field.

- [ ] **Step 3: Implement the contract and configuration**

Add to `backend/config/params.php`:

```php
'nl2sqlTwoLaneEnabled' => filter_var(
    SettingsService::get('nl2sql_two_lane_enabled', 'NL2SQL_TWO_LANE_ENABLED', 'true'),
    FILTER_VALIDATE_BOOLEAN
),
```

Include `nl2sqlTwoLaneEnabled` and persisted key `nl2sql_two_lane_enabled` in `Nl2sqlRuntimePreflightService::buildReport()`. Preserve the default-on contract even for older direct callers that omit the new key:

```php
'nl2sqlTwoLaneEnabled' => !array_key_exists('nl2sqlTwoLaneEnabled', $params)
    || (bool)$params['nl2sqlTwoLaneEnabled'],
```

Add this warning only when false:

```php
if (!$effective['nl2sqlTwoLaneEnabled']) {
    $warnings[] = 'The strict blocker rollback path is active (`nl2sql_two_lane_enabled=false`).';
}
```

Add the contract to `AskResponseContractService`:

```php
const PROVENANCE_VERIFIED_PATTERN = 'verified_pattern';
const PROVENANCE_AI_BUILT = 'ai_built';

private static $provenanceLabels = [
    self::PROVENANCE_VERIFIED_PATTERN => 'Verified pattern',
    self::PROVENANCE_AI_BUILT => 'AI-built',
];

public static function withGenerationProvenance(array $result, string $provenance): array
{
    if (!isset($result['sql'])) {
        unset($result['generationProvenance'], $result['provenanceLabel']);
        return $result;
    }
    if (!isset(self::$provenanceLabels[$provenance])) {
        throw new \InvalidArgumentException('Unknown report generation provenance.');
    }
    $result['generationProvenance'] = $provenance;
    $result['provenanceLabel'] = self::$provenanceLabels[$provenance];
    return $result;
}
```

Do not infer `verified_pattern` from `mode` in this method; the orchestrator must make the provenance decision explicitly.

In `AskGenerationEvidenceService::build()`, add the allowlisted stable value to `$provenance`:

```php
$generationProvenance = (string)($result['generationProvenance'] ?? '');
if (!in_array($generationProvenance, ['verified_pattern', 'ai_built'], true)) {
    $generationProvenance = null;
}

$provenance['generationProvenance'] = $generationProvenance;
```

Do not persist the display label as a second source of truth; derive it from the stable enum when needed.

- [ ] **Step 4: Run focused tests and commit**

Run:

```bash
php backend/tests/AskResponseContractServiceTest.php
php backend/tests/Nl2sqlRuntimePreflightServiceTest.php
php backend/tests/AskGenerationEvidenceServiceTest.php
```

Expected: both print their existing pass messages.

Commit:

```bash
git add backend/config/params.php backend/services/Nl2sqlRuntimePreflightService.php backend/services/AskResponseContractService.php backend/services/AskGenerationEvidenceService.php backend/tests/Nl2sqlRuntimePreflightServiceTest.php backend/tests/AskResponseContractServiceTest.php backend/tests/AskGenerationEvidenceServiceTest.php
git commit -m "feat: add two-lane provenance contract"
```

---

### Task 2: Turn reference ambiguity into generation context

**Files:**
- Modify: `backend/services/ReferenceResolverService.php`
- Modify: `backend/services/GeminiService.php`
- Modify: `backend/tests/GeminiServiceResolverGuidanceRoutingTest.php`
- Modify: `backend/tests/GeminiServiceReferenceResolverTelemetryTest.php`

**Interfaces:**
- Produces: `ReferenceResolverService::appendGenerationContextToPrompt(string $prompt, array $resolution, ?array $ambiguity = null): string`.
- Consumes: `resolvedFilters`, `guidanceLines`, `unresolvedNamedIntents`, and `clarificationItems` from the resolver.
- Contract: `needsClarification` remains available internally for rollback compatibility but is never serialized by the enabled two-lane generation path.

- [ ] **Step 1: Add failing pure context tests**

In `backend/tests/GeminiServiceResolverGuidanceRoutingTest.php`, construct a resolver result like the Neilson failure and assert the context retains raw terms and bounded candidates without instruction to ask the user:

```php
$context = ReferenceResolverService::appendGenerationContextToPrompt(
    'Show the 20 most-circulated books at Neilson Library during the last five years.',
    [
        'needsClarification' => true,
        'resolvedFilters' => [],
        'unresolvedNamedIntents' => [[
            'dimension' => 'library',
            'span' => 'Neilson Library',
        ]],
        'clarificationItems' => [[
            'term' => 'Neilson Library',
            'confidence' => 'ambiguous',
            'options' => [
                ['label' => 'SC Neilson Library'],
                ['label' => 'Neilson Library Annex'],
            ],
        ]],
    ]
);
assertContainsText('Unresolved local term: Neilson Library (library)', $context, 'Raw named terms must reach generation.');
assertContainsText('Neilson Library candidate values: SC Neilson Library; Neilson Library Annex', $context, 'Ranked candidates must reach generation.');
assertNotContainsText('ask the user', strtolower($context), 'Context must not direct a blocker.');
```

Add a `generateSqlWithShadow()` regression using the existing `TestTransport` seam: a `needsClarification` resolver response must still make an AI request and return SQL with no `needsClarification` field.

The test file already defines a lightweight `ReferenceResolverService` double. Give it a public static `$resolution` fixture, have `resolvePrompt()` return that fixture, and add `appendGenerationContextToPrompt()` with the production signature. Set `$resolution` to the Neilson unresolved/candidate array for the new case, then restore the existing E-Book guidance fixture before the older routing assertions. This keeps the production signature and isolated test double aligned without live caches.

- [ ] **Step 2: Run the resolver tests and verify the blocker behavior fails**

Run:

```bash
php backend/tests/GeminiServiceResolverGuidanceRoutingTest.php
php backend/tests/GeminiServiceReferenceResolverTelemetryTest.php
```

Expected: the new helper is missing and/or `generateSqlWithShadow()` returns `route: clarification` without consuming the model response.

- [ ] **Step 3: Build bounded model-only reference context**

Implement `appendGenerationContextToPrompt()` in `ReferenceResolverService` by starting with existing exact `appendGuidanceToPrompt()` output and appending only sanitized advisory data:

```php
public static function appendGenerationContextToPrompt(
    string $prompt,
    array $resolution,
    ?array $ambiguity = null
): string {
    $prompt = self::appendGuidanceToPrompt($prompt, $resolution);
    $lines = [];

    foreach (array_slice($resolution['unresolvedNamedIntents'] ?? [], 0, 8) as $intent) {
        $span = trim((string)($intent['span'] ?? ''));
        $dimension = trim((string)($intent['dimension'] ?? 'unknown'));
        if ($span !== '') {
            $lines[] = 'Unresolved local term: ' . $span . ' (' . $dimension . ')';
        }
    }

    foreach (array_slice($resolution['clarificationItems'] ?? [], 0, 8) as $item) {
        $term = trim((string)($item['term'] ?? ''));
        $labels = [];
        foreach (array_slice($item['options'] ?? [], 0, 5) as $option) {
            $label = trim((string)($option['label'] ?? ''));
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        if ($term !== '' && $labels !== []) {
            $lines[] = $term . ' candidate values: ' . implode('; ', array_values(array_unique($labels)));
        }
    }

    if ($ambiguity !== null && trim((string)($ambiguity['question'] ?? '')) !== '') {
        $lines[] = 'Advisory interpretation note: ' . trim((string)$ambiguity['question']);
    }

    return $lines === []
        ? $prompt
        : $prompt . "\n\nLocal reference generation context:\n- " . implode("\n- ", $lines);
}
```

Keep exact resolved filters authoritative. Candidate labels and unresolved terms are context, never automatic exact predicates.

- [ ] **Step 4: Remove enabled-path clarification early returns**

In `GeminiService::generateSqlWithShadow()`:

1. Keep resolver telemetry.
2. If `nl2sqlTwoLaneEnabled` is false, retain the current early-return behavior unchanged.
3. When enabled, do not return `referenceResolution` or `ClarificationService::detectPromptAmbiguity()` output.
4. Build `$effectivePrompt` with `appendGenerationContextToPrompt()` and pass the ambiguity result as advisory context.
5. Keep the explicit 500-identifier cap as a concise configured-resource failure; it is not a canonical clarification.

The enabled-path shape should be:

```php
$twoLaneEnabled = !array_key_exists('nl2sqlTwoLaneEnabled', Yii::$app->params)
    || (bool)Yii::$app->params['nl2sqlTwoLaneEnabled'];
$ambiguity = ClarificationService::detectPromptAmbiguity(
    $rawQuestion,
    self::loadAcceptedClarificationKeys($userId)
);

if (!$twoLaneEnabled && !empty($referenceResolution['needsClarification'])) {
    return AskResponseContractService::normalizeMode(
        self::withAskEvidence($referenceResolution, $askEvidence)
    );
}

$effectivePrompt = $twoLaneEnabled
    ? ReferenceResolverService::appendGenerationContextToPrompt($generationPrompt, $referenceResolution, $ambiguity)
    : ReferenceResolverService::appendGuidanceToPrompt($generationPrompt, $referenceResolution);
```

Log ambiguity as `reference_context_advisory` when enabled, not `route: clarification`. Ensure `$generationTransport['generationPrompt']` retains this context for database-preflight repair and `AskResponseContractService::toUserResponse()` still strips it.

- [ ] **Step 5: Run tests and commit**

Run:

```bash
php backend/tests/GeminiServiceResolverGuidanceRoutingTest.php
php backend/tests/GeminiServiceReferenceResolverTelemetryTest.php
php backend/tests/AskResponseContractServiceTest.php
```

Expected: unresolved/candidate reference data reaches the model; ordinary responses do not expose resolver internals or clarification fields.

Commit:

```bash
git add backend/services/ReferenceResolverService.php backend/services/GeminiService.php backend/tests/GeminiServiceResolverGuidanceRoutingTest.php backend/tests/GeminiServiceReferenceResolverTelemetryTest.php
git commit -m "feat: use reference ambiguity as generation context"
```

---

### Task 3: Implement the canonical-to-AI orchestration boundary

**Files:**
- Create: `backend/exceptions/CanonicalLaneFallbackException.php`
- Modify: `backend/services/GeminiService.php`
- Modify: `backend/tests/GeminiServiceFamilyCompilerFallbackTest.php`
- Modify: `backend/tests/GeminiServiceFamilyIntentBranchTest.php`
- Modify: `backend/tests/GeminiServiceFamilyMismatchTelemetryTest.php`
- Modify: `backend/tests/GeminiServiceExploratoryRepairTest.php`

**Interfaces:**
- Produces: internal `CanonicalLaneFallbackException` with sanitized family/reason and optional candidate result.
- Produces: one `GeminiService::generateAiBuiltLane(...)` helper used for direct AI generation, fresh canonical fallback, and seeded-candidate repair.
- Contract: policy, authorization, destructive SQL, cancellation, connectivity, provider failure, timeout, and resource-limit exceptions bypass this fallback.

- [ ] **Step 1: Rewrite the compiler-guard test to emit an internal Lane 2 signal**

In `GeminiServiceFamilyCompilerFallbackTest.php`, replace the assertion that fallback is disabled. The deep compiler helper must emit a typed internal signal; only the top-level orchestrator is allowed to invoke AI:

```php
Yii::$app->params['nl2sqlTwoLaneEnabled'] = true;
$telemetryContext = [
    'model' => 'test-model',
    'promptVersion' => 'family_slot_prompt.v1',
    'promptFingerprint' => 'test-fingerprint',
    'finishReason' => 'STOP',
    'attempts' => 1,
    'elapsedMs' => 5,
];
try {
    $helper->invoke(
        null,
        $validation['normalizedPayload'],
        'family_contract_supported:inventory_contributor_campus_item_barcode',
        'Show me Smith College theses by this contributor with barcodes',
        'Smith College',
        $telemetryContext,
        function () use (&$compilerInvocations) {
            $compilerInvocations++;
            throw new InvalidArgumentException('missing_holdings_item_branch');
        },
        function () use (&$fallbackInvocations) {
            $fallbackInvocations++;
            return ['sql' => 'SELECT legacy_fallback_stub'];
        }
    );
    fwrite(STDERR, "Expected canonical Lane 2 signal.\n");
    exit(1);
} catch (\app\exceptions\CanonicalLaneFallbackException $exception) {
    assertSameValue('canonical_compiler_failed', $exception->getSafeReason(), 'Compiler failure needs a safe routing reason.');
}
assertSameValue(1, $compilerInvocations, 'Canonical compilation should run once.');
assertSameValue(0, $fallbackInvocations, 'Deep compiler code must not invoke AI or legacy fallback itself.');
```

Then add a public `generateSqlWithShadow()` case using the existing test transport. That case must catch the typed signal internally, make exactly one AI-built generation call, return safe SQL, and set `generationProvenance: ai_built`.

Add cases to `GeminiServiceFamilyIntentBranchTest.php` and `GeminiServiceFamilyMismatchTelemetryTest.php` for:

- missing required family slots;
- wrong AI family/invalid structured intent;
- unsupported output or time window;
- inventory compiler's current clarification response.

Every case must consume the AI transport response, return SQL labeled `ai_built`, and omit `needsClarification`.

- [ ] **Step 2: Add the seeded semantic-failure regression**

In `GeminiServiceExploratoryRepairTest.php`, make a canonical compiler result fail `resolved_reference_filter_mismatch` or semantic conformance with a candidate SQL. Assert the first repair payload contains that exact SQL and the final response is AI-built:

```php
repairAssertContains(
    'SELECT',
    json_encode(TestTransport::$requests[0] ?? []),
    'Lane 2 must receive the compiled SQL as its seeded candidate.'
);
repairAssertSame('ai_built', $result['generationProvenance'] ?? null, 'AI-owned repaired SQL must not remain verified.');
repairAssertSame(false, ($result['generationProvenance'] ?? null) === 'verified_pattern', 'Semantic fallback must downgrade provenance.');
```

Also cover AI returning the candidate unchanged: it may succeed only after all hard safety/static checks pass, and it remains `ai_built`.

- [ ] **Step 3: Run focused tests and verify current fail-closed assertions fail**

Run:

```bash
php backend/tests/GeminiServiceFamilyCompilerFallbackTest.php
php backend/tests/GeminiServiceFamilyIntentBranchTest.php
php backend/tests/GeminiServiceFamilyMismatchTelemetryTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Expected: current guard exceptions, clarification responses, or missing provenance cause the new assertions to fail.

- [ ] **Step 4: Add the internal canonical-fallback signal**

Create `backend/exceptions/CanonicalLaneFallbackException.php` with PHP 7.2-compatible untyped properties:

```php
<?php

namespace app\exceptions;

final class CanonicalLaneFallbackException extends \RuntimeException
{
    private $familyKey;
    private $safeReason;
    private $candidateResult;

    public function __construct(
        string $familyKey,
        string $safeReason,
        array $candidateResult = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct('Canonical generation requires AI fallback.', 0, $previous);
        $this->familyKey = $familyKey;
        $this->safeReason = $safeReason;
        $this->candidateResult = $candidateResult;
    }

    public function getFamilyKey(): string { return $this->familyKey; }
    public function getSafeReason(): string { return $this->safeReason; }
    public function getCandidateResult(): array { return $this->candidateResult; }
}
```

Require/import it in `GeminiService.php`. Change `guardCoveredFamilyFallback()` to throw this exception when the two-lane switch is enabled. Preserve its current `RuntimeException` behavior when the switch is disabled.

- [ ] **Step 5: Add one AI-built lane helper and route every non-safety canonical outcome through it**

Add a private helper with this responsibility:

```php
private static function generateAiBuiltLane(
    string $rawQuestion,
    string $generationPrompt,
    $campus,
    string $reason,
    array $resolvedFilters,
    array $seededCandidate = [],
    string $diagnostic = ''
): array {
    if (isset($seededCandidate['sql'])) {
        $result = self::repairExploratorySqlAfterPreflight(
            $rawQuestion,
            $campus,
            $seededCandidate,
            $diagnostic === '' ? 'Canonical semantic validation requires AI review.' : $diagnostic,
            $generationPrompt,
            $resolvedFilters
        );
    } else {
        $result = self::generateExploratorySqlResponse(
            $generationPrompt,
            $campus,
            $reason,
            $rawQuestion,
            $resolvedFilters
        );
    }

    if (isset($result['sql'])) {
        $result['mode'] = 'exploratory';
        $result = AskResponseContractService::withGenerationProvenance(
            $result,
            AskResponseContractService::PROVENANCE_AI_BUILT
        );
    }
    return $result;
}
```

Refactor `generateSqlWithShadow()` so:

- `allowExploratory` and no-family paths call `generateAiBuiltLane()` directly;
- canonical `needsClarification`/missing-slot response arrays become a fresh AI-built call;
- `CanonicalLaneFallbackException` becomes a fresh or seeded AI-built call;
- repairable `ExploratorySqlValidationException` with candidate SQL becomes seeded AI repair regardless of whether the safe category is `resolved_reference_filter_mismatch` or a semantic advisory category;
- a canonical result containing SQL receives `verified_pattern` only after canonical generation returns cleanly;
- hard exceptions are rethrown.

Use an explicit hard-failure predicate rather than `catch (\Throwable)` fallback. At minimum, never convert these into Lane 2 retries:

```php
$exception instanceof \app\exceptions\PolicyViolationException
    || $exception instanceof \app\exceptions\DatabaseQueryCancelledException
    || self::isAiTimeoutMessage($exception->getMessage());
```

Provider/connectivity failures from the AI transport continue through existing controller error handling.

- [ ] **Step 6: Remove deep clarification returns from enabled canonical compilation**

For `buildInventoryListingCompilerClarificationResponse()` and `buildFamilySlotClarificationResponse()`, keep rollback behavior when the switch is disabled. When enabled, return an internal fallback marker or throw `CanonicalLaneFallbackException` with safe reasons such as:

- `canonical_missing_required_slot`;
- `canonical_family_contract_mismatch`;
- `canonical_compiler_failed`;
- `canonical_reference_not_representable`.

Do not create user-facing questions, correction suffixes, or `route: clarification` in the enabled path.

- [ ] **Step 7: Make semantic and reference validators advisory after bounded AI review**

Keep semantic conformance, exact resolved-reference preservation, and explicit-output preservation as repair triggers on the initial candidate and first repair. On the final allowed AI repair, they may no longer erase an otherwise safe candidate. Add a helper in `GeminiService` equivalent to:

```php
private static function mayAcceptAdvisoryFailure(
    array $context,
    ExploratorySqlValidationException $exception
): bool {
    if ((int)($context['repairNumber'] ?? 0) < ExploratorySqlRepairService::MAX_REPAIR_ATTEMPTS) {
        return false;
    }
    return in_array($exception->getStage(), [
        'semantic_conformance',
        'semantic_validation',
        'explicit_values',
    ], true);
}
```

Restructure `runExploratorySqlAttempt()` so a final advisory failure returns the current candidate with:

```php
$result['semanticValidation'] = [
    'status' => 'advisory',
    'contractVersion' => (int)($semanticValidation['contractVersion'] ?? 0),
    'checkedRequirements' => $semanticValidation['checkedRequirements'] ?? [],
];
$result['reviewRequired'] = true;
$result['reportDisclosures'][] = 'AI reviewed this interpretation, but the semantic checker could not verify every requested detail.';
$result['assumptions'] = self::mergeAdvisoryAssumptions(
    is_array($result['assumptions'] ?? null) ? $result['assumptions'] : [],
    $exception->getSafeViolations()
);
```

`mergeAdvisoryAssumptions()` must use only safe violation labels/guidance, stable generated keys, and `source: default`; it must not expose SQL predicates or validator internals. Safety/table-policy/response-format exceptions and actual database-preflight errors are not advisory and keep their existing hard/repair behavior.

Add tests for a CTE the semantic analyzer cannot understand and for an exact-reference parser mismatch. Both should make the bounded AI repair attempts, then return the final safe candidate with `semanticValidation.status: advisory`, `reviewRequired: true`, and `generationProvenance: ai_built`; neither may return recovery or clarification.

- [ ] **Step 8: Add route telemetry and run tests**

Emit one sanitized transition event, for example:

```php
self::logNlTelemetry('nl2sql.lane_transition', [
    'from' => 'verified_pattern',
    'to' => 'ai_built',
    'reason' => $safeReason,
    'familyKey' => $familyKey === '' ? null : $familyKey,
    'seededCandidate' => isset($seededCandidate['sql']),
    'promptFingerprint' => self::fingerprintPrompt($rawQuestion),
]);
```

Never log prompt text or SQL. Then run:

```bash
php backend/tests/GeminiServiceFamilyCompilerFallbackTest.php
php backend/tests/GeminiServiceFamilyIntentBranchTest.php
php backend/tests/GeminiServiceFamilyMismatchTelemetryTest.php
php backend/tests/GeminiServiceFamilyCompilerResultTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Expected: canonical success remains deterministic; all listed non-safety failures automatically return AI-built SQL; unresolved semantic/reference proof becomes an advisory AI-built success; hard policy tests remain blocked.

Commit:

```bash
git add backend/exceptions/CanonicalLaneFallbackException.php backend/services/GeminiService.php backend/tests/GeminiServiceFamilyCompilerFallbackTest.php backend/tests/GeminiServiceFamilyIntentBranchTest.php backend/tests/GeminiServiceFamilyMismatchTelemetryTest.php backend/tests/GeminiServiceExploratoryRepairTest.php
git commit -m "feat: route canonical failures through ai generation"
```

---

### Task 4: Route canonical database-preflight failures through seeded AI repair

**Files:**
- Modify: `backend/controllers/FolioQueryController.php`
- Modify: `backend/services/GeminiService.php`
- Modify: `backend/tests/FolioQueryControllerExploratoryRepairTest.php`
- Modify: `backend/tests/GeminiServiceExploratoryRepairTest.php`

**Interfaces:**
- Consumes: any safe generated result with SQL, including `verified_pattern`.
- Produces: a re-preflighted `ai_built` result after any canonical preflight repair.
- Produces: concise `sql_generation_failed` response only after the shared repair budget is exhausted for an actual SQL/preflight failure, never for semantic uncertainty alone.

- [ ] **Step 1: Replace the existing canonical no-repair regression**

In `backend/tests/FolioQueryControllerExploratoryRepairTest.php`, replace the assertions around the current `canonicalRepairCalls === 0` case with:

```php
$canonicalRepairCalls = 0;
$canonicalFailure = $validateAndRepair->invoke(
    $controller,
    [
        'sql' => 'SELECT broken_column FROM inventory.instance__t',
        'mode' => 'canonical',
        'route' => 'builder_intent',
        'routeReason' => 'family_contract_supported:inventory_listing',
        'generationProvenance' => 'verified_pattern',
        'provenanceLabel' => 'Verified pattern',
    ],
    'Show titles',
    'Smith College',
    function (string $sql): array {
        return strpos($sql, 'broken_column') !== false
            ? ['error' => 'column "broken_column" does not exist']
            : [];
    },
    function () use (&$canonicalRepairCalls): array {
        $canonicalRepairCalls++;
        return [
            'sql' => 'SELECT title FROM inventory.instance__t',
            'mode' => 'exploratory',
            'route' => 'exploratory',
            'generationProvenance' => 'ai_built',
            'provenanceLabel' => 'AI-built',
        ];
    }
);
repairAssertSame(1, $canonicalRepairCalls, 'Canonical preflight failure must enter seeded AI repair.');
repairAssertSame('ai_built', $canonicalFailure['generationProvenance'] ?? null, 'Repaired canonical SQL is AI-built.');
repairAssertSame('SELECT title FROM inventory.instance__t', $canonicalFailure['sql'] ?? null, 'Repaired SQL must pass a second preflight.');
```

Add exhaustion assertions: two failed AI repairs produce no SQL, do not return `exploratory_recovery`, do not contain `recoveryItems`, `attemptedPlan`, correction guidance, or “request is preserved,” and retain no verified provenance.

- [ ] **Step 2: Run controller and repair tests and verify failure**

Run:

```bash
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Expected: the controller's `isExploratoryRepairEligible()` rejects canonical results and returns the legacy recovery response.

- [ ] **Step 3: Make every safe generated candidate repair-eligible**

Replace route-name inference in `isExploratoryRepairEligible()` with success-candidate/provenance logic:

```php
private function isAiRepairEligible(array $result): bool
{
    if (!isset($result['sql'])) {
        return false;
    }
    return in_array(
        (string)($result['generationProvenance'] ?? ''),
        [
            AskResponseContractService::PROVENANCE_VERIFIED_PATTERN,
            AskResponseContractService::PROVENANCE_AI_BUILT,
        ],
        true
    ) || in_array((string)($result['mode'] ?? ''), ['canonical', 'exploratory'], true);
}
```

Use it in `validateAndRepairNlResult()` after safety, cancellation, connectivity, and policy checks. On the first successful repair, explicitly set AI provenance:

```php
$result['mode'] = 'exploratory';
$result['route'] = 'exploratory';
$result = AskResponseContractService::withGenerationProvenance(
    $result,
    AskResponseContractService::PROVENANCE_AI_BUILT
);
```

This is the explicit resolution of canonical preflight routing: the original compiled SQL is the repair candidate; hard failure occurs only after the AI repair budget is exhausted.

- [ ] **Step 4: Replace user-facing recovery responses with concise terminal failures**

Rename or replace `buildExploratoryRepairExhaustedResponse()` with `buildAiSqlGenerationFailedResponse()` and return only safe failure data:

```php
$resultEvidence = is_array($result['_askEvidence'] ?? null)
    ? $result['_askEvidence']
    : [];
return [
    'errorType' => 'sql_generation_failed',
    'message' => 'Report Explorer could not safely run this report. Please retry.',
    'route' => 'generation_failed',
    'routeReason' => 'sql_repair_exhausted',
    'validationSummary' => [
        'status' => 'exhausted',
        'repairAttempts' => $repairAttempts,
    ],
    '_askEvidence' => array_merge($resultEvidence, [
        'finalSql' => null,
        'repairAttempts' => $repairAttempts,
        'validationStatus' => 'exhausted',
    ]),
];
```

Do not include `recoveryContext`, `recoveryItems`, `attemptedPlan`, `suggestions` that instruct prompt changes, or semantic-validator details. Apply the same terminal shape when `GeminiService::repairExploratorySqlAfterPreflight()` exhausts its budget. Keep unsafe SQL distinct (`errorType: unsafe_generated_sql`) and never expose its candidate.

- [ ] **Step 5: Let reviewed semantic advisories proceed to preflight**

The current controller immediately terminal-fails when `semanticContractApplicable` is true but semantic validation is not `validated`. Change that branch so `semanticValidation.status: advisory` proceeds to database preflight. A raw canonical `rejected` status still enters the same AI repair seam with a safe diagnostic such as `Semantic validation requires AI review.`. The AI lane will either return `validated` or the bounded final `advisory` result described in Task 3.

Do not let a known unsafe or policy-blocked candidate reach AI repair.

- [ ] **Step 6: Run tests and commit**

Run:

```bash
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
php backend/tests/SqlBuilderServicePolicyViolationTest.php
```

Expected: canonical and AI-built candidates share repair/preflight behavior; destructive and restricted SQL remain blocked; exhaustion exposes only retry-oriented technical copy.

Commit:

```bash
git add backend/controllers/FolioQueryController.php backend/services/GeminiService.php backend/tests/FolioQueryControllerExploratoryRepairTest.php backend/tests/GeminiServiceExploratoryRepairTest.php
git commit -m "feat: repair canonical sql after database preflight"
```

---

### Task 5: Render one successful result experience with visible provenance

**Files:**
- Modify: `frontend/src/types/schema.ts`
- Modify: `frontend/src/components/AskTrustNotice.tsx`
- Modify: `frontend/src/components/AskTrustNotice.test.tsx`
- Modify: `frontend/src/pages/Ask.tsx`
- Modify: `frontend/src/pages/Ask.errorFormatting.test.ts`
- Modify: `frontend/src/pages/Ask.followUp.test.ts`
- Stop importing in Ask: `frontend/src/components/ExploratoryRecoveryPanel.tsx`

**Interfaces:**
- Produces: `GenerationProvenance = 'verified_pattern' | 'ai_built'`.
- Contract: every successful Ask result renders exactly one `Verified pattern` or `AI-built` label near the result summary.
- Contract: AI-built results retain the same Results / Related / SQL tabs and execution flow as verified results.

- [ ] **Step 1: Write failing provenance component tests**

Update `AskTrustNotice.test.tsx` so canonical results are no longer invisible:

```tsx
it('shows verified provenance for a canonical report', () => {
  render(
    <AskTrustNotice
      generationProvenance="verified_pattern"
      provenanceLabel="Verified pattern"
      reviewRequired={false}
      assumptions={[]}
    />,
  );
  expect(screen.getByText('Verified pattern')).toBeInTheDocument();
  expect(screen.queryByText('AI-built')).not.toBeInTheDocument();
});

it('shows AI-built provenance without making the result an error', () => {
  render(
    <AskTrustNotice
      generationProvenance="ai_built"
      provenanceLabel="AI-built"
      reviewRequired={false}
      assumptions={assumptions}
    />,
  );
  expect(screen.getByText('AI-built')).toBeInTheDocument();
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  expect(screen.getByText(/^Campus scope:/)).toBeInTheDocument();
});
```

Add Ask-page helper assertions in `Ask.errorFormatting.test.ts`:

- successful AI-built response is not a validation hard stop;
- `needsClarification` compatibility fields do not create a rendered blocker in the normal results path;
- `sql_generation_failed` formats as `Report Explorer could not safely run this report. Please retry.` and contains no correction instruction.

- [ ] **Step 2: Run frontend tests and verify current UI behavior fails**

Run:

```bash
npm test -- src/components/AskTrustNotice.test.tsx src/pages/Ask.errorFormatting.test.ts
```

Working directory: `frontend`.

Expected: canonical notice test fails because `AskTrustNotice` currently returns `null`; hard-stop tests still expect `ExploratoryRecoveryPanel` behavior.

- [ ] **Step 3: Add provenance types and update the notice**

In `frontend/src/types/schema.ts`:

```ts
export type GenerationProvenance = 'verified_pattern' | 'ai_built';

export interface SemanticValidation {
  status: 'validated' | 'advisory';
  contractVersion: number;
  checkedRequirements: SemanticRequirementLabel[];
}
```

Then add these fields to the existing `NlResponse` interface without removing its compatibility fields:

```ts
generationProvenance?: GenerationProvenance;
provenanceLabel?: 'Verified pattern' | 'AI-built';
```

Change `AskTrustNotice` props to use provenance rather than `mode`:

```tsx
interface AskTrustNoticeProps {
  generationProvenance?: NlResponse['generationProvenance'];
  provenanceLabel?: NlResponse['provenanceLabel'];
  reviewRequired?: boolean;
  reviewNotice?: NlResponse['reviewNotice'];
  assumptions?: ExploratoryAssumption[];
}
```

Render a visible neutral/green `Verified pattern` badge and blue `AI-built` badge. Keep assumptions and review advisory nonblocking. Use `role="note"`, not `role="alert"`, and do not render internal `route`, validator, schema, or repair details.

- [ ] **Step 4: Remove blocker rendering and obsolete correction handlers from Ask**

In `Ask.tsx`:

- remove the `ExploratoryRecoveryPanel` import and render branch;
- remove both left/middle and right-pane `Clarification needed` branches;
- remove `showRightPaneClarifications`, clarification-choice state, prompt-rewriting helpers, and `saveClarificationResolution` calls that are now unreachable in normal Ask;
- remove `handleRetryExploratory`, `handleRefineExploratory`, and correction-instruction prompt concatenation used by the recovery panel;
- replace clarification-oriented loading strings and `building_sql_after_clarification` progress state with neutral generation/automatic-repair copy;
- keep existing accuracy feedback and ordinary Ask follow-up behavior;
- render the successful result section whenever `nlResult.sql` exists, regardless of legacy `mode` or `validationSummary` fields;
- pass `generationProvenance` and `provenanceLabel` to `AskTrustNotice`;
- replace the result-summary text `exploratory SQL` with the stable public provenance label, or omit it there if the badge is immediately adjacent.

The result gate should become success-oriented:

```tsx
const hasGeneratedSql = Boolean(nlResult?.sql);

{nlResult && !isLoading && hasGeneratedSql && (
  <div className="mx-auto w-full max-w-6xl p-3 space-y-3">
    <AskTrustNotice
      generationProvenance={nlResult.generationProvenance}
      provenanceLabel={nlResult.provenanceLabel}
      reviewRequired={nlResult.reviewRequired}
      reviewNotice={nlResult.reviewNotice}
      assumptions={nlResult.assumptions}
    />
    {/* existing tabs and result execution UI */}
  </div>
)}
```

For a no-SQL terminal response, show one compact technical error with Retry. Do not render `recoveryItems`, `attemptedPlan`, resolver traces, clarification options, or generated corrections.

- [ ] **Step 5: Update tests for removed blocker behavior**

Delete assertions that teach the UI to build clarified/correction prompts. Replace them with assertions that:

- exactly one provenance label is present for successful results;
- both provenance values use the normal results layout;
- a terminal technical failure offers Retry only;
- no button or heading contains `Clarification needed`, `Continue`, `What still needs to be resolved`, `The request is preserved`, or `Refine request`;
- existing follow-up context remains unchanged for successful results.

Do not delete `ExploratoryRecoveryPanel.tsx` in this emergency phase; leave it available for rollback/other consumers but ensure Ask no longer imports or renders it.

- [ ] **Step 6: Run frontend verification and commit**

Run from `frontend`:

```bash
npm test -- src/components/AskTrustNotice.test.tsx src/pages/Ask.errorFormatting.test.ts src/pages/Ask.followUp.test.ts
npm run build
npm run lint
```

Expected: tests pass, TypeScript builds, lint reports no unused clarification/recovery state or imports.

Commit:

```bash
git add frontend/src/types/schema.ts frontend/src/components/AskTrustNotice.tsx frontend/src/components/AskTrustNotice.test.tsx frontend/src/pages/Ask.tsx frontend/src/pages/Ask.errorFormatting.test.ts frontend/src/pages/Ask.followUp.test.ts
git commit -m "feat: unify ask results around generation provenance"
```

---

### Task 6: Add end-to-end routing regressions and validate rollout safety

**Files:**
- Create: `backend/tests/GeminiServiceTwoLaneRoutingTest.php`
- Modify: `backend/tests/FolioQueryControllerExploratoryRepairTest.php`
- Modify: `backend/tests/AskResponseContractServiceTest.php`
- Modify: `backend/services/GeminiService.php` only if regression instrumentation exposes a missing seam
- Create: `docs/superpowers/implementation-reports/2026-08-26-two-lane-report-generation-phase-1.md`

**Interfaces:**
- Tests the public `GeminiService::generateSqlWithShadow()` boundary with the existing fake AI transport and deterministic fixtures.
- Records measured verification evidence without contacting production FOLIO or an external AI provider.

- [ ] **Step 1: Create the required two-lane regression matrix**

Create `backend/tests/GeminiServiceTwoLaneRoutingTest.php` using the Yii/TestTransport setup pattern from `GeminiServiceExploratoryRepairTest.php`. Cover these exact cases:

```php
$cases = [
    [
        'name' => 'verified inventory pattern',
        'prompt' => 'Show me a list of VHS and DVDs at Hillyer Library.',
        'expectedProvenance' => 'verified_pattern',
    ],
    [
        'name' => 'Neilson unsupported canonical shape',
        'prompt' => 'Show me the 20 most-circulated books at Neilson Library during the last five years. Include title, call number, publication year, checkout count, and most recent checkout date.',
        'expectedProvenance' => 'ai_built',
    ],
    [
        'name' => 'novel cross-domain request',
        'prompt' => 'Compare annual circulation with acquisition spending by material type for the last three completed fiscal years.',
        'expectedProvenance' => 'ai_built',
    ],
];
```

For each successful response assert:

```php
assertSameValue($case['expectedProvenance'], $result['generationProvenance'] ?? null, $case['name'] . ' provenance mismatch.');
assertSameValue(true, isset($result['sql']), $case['name'] . ' must produce SQL.');
assertSameValue(false, !empty($result['needsClarification']), $case['name'] . ' must not block for clarification.');
assertSameValue(false, ($result['route'] ?? null) === 'exploratory_recovery', $case['name'] . ' must not return recovery UI.');
```

The verified case fixture must provide valid family intent and compiler inputs. The AI-built fixtures must return safe SQL compatible with the checked-in schema caches and any required semantic response format.

- [ ] **Step 2: Add hard-gate regression cases**

In the same test, verify:

- AI returns `DELETE` or multiple statements: no SQL is exposed, no provenance is claimed, and no database preflight is attempted;
- restricted patron table: `PolicyViolationException` remains terminal and is not retried as a canonical fallback;
- database cancellation remains typed;
- provider timeout remains `ai_timeout` at the controller boundary;
- two failed repair attempts produce the concise `sql_generation_failed` result and no clarification/correction fields.

- [ ] **Step 3: Run the new test and fix only missing orchestration seams**

Run:

```bash
php backend/tests/GeminiServiceTwoLaneRoutingTest.php
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php backend/tests/AskResponseContractServiceTest.php
```

Expected: all routing and hard-gate assertions pass. If the exact verified fixture is too coupled to live reference caches, inject the same pure resolver/compiler closures already used by the family tests rather than weakening the assertion or contacting external services.

- [ ] **Step 4: Run the full relevant backend suite**

Run all standalone PHP tests and stop on the first failure:

```bash
for test_file in backend/tests/*Test.php; do
  php "$test_file" || exit 1
done
```

Expected: every test exits 0. Update obsolete tests that explicitly require normal-flow clarification or recovery only when the new approved spec supersedes that assertion. Do not weaken safety, policy, authorization, cancellation, timeout, or resource-limit expectations.

- [ ] **Step 5: Run full frontend verification**

Run from `frontend`:

```bash
npm test
npm run build
npm run lint
```

Expected: all Vitest suites pass, production build succeeds, and ESLint succeeds.

- [ ] **Step 6: Perform a source-level blocker/provenance audit**

Run:

```bash
rg -n "Clarification needed|The request is preserved|What still needs to be resolved|ExploratoryRecoveryPanel|handleRefineExploratory|handleRetryExploratory" frontend/src/pages/Ask.tsx
rg -n "generationProvenance|provenanceLabel" backend/services/AskResponseContractService.php backend/services/GeminiService.php backend/controllers/FolioQueryController.php frontend/src/pages/Ask.tsx frontend/src/components/AskTrustNotice.tsx
```

Expected: the first command returns no matches in Ask; the second shows both backend decoration and frontend rendering.

- [ ] **Step 7: Record implementation evidence**

Create `docs/superpowers/implementation-reports/2026-08-26-two-lane-report-generation-phase-1.md` containing:

- commits implemented;
- exact backend and frontend verification commands with pass counts;
- the three regression prompts and observed provenance;
- confirmation that unsafe/restricted SQL remained blocked;
- confirmation that the two-lane switch defaults on and rollback defaults off;
- any compatibility response fields intentionally retained;
- explicit note that Phase 2 report revisions remain unimplemented.

- [ ] **Step 8: Commit final regressions and report**

```bash
git add backend/tests/GeminiServiceTwoLaneRoutingTest.php backend/tests/FolioQueryControllerExploratoryRepairTest.php backend/tests/AskResponseContractServiceTest.php backend/services/GeminiService.php docs/superpowers/implementation-reports/2026-08-26-two-lane-report-generation-phase-1.md
git commit -m "test: verify two-lane report generation"
```

---

## Completion Checklist

- [ ] A supported canonical report returns safe, preflighted SQL labeled **Verified pattern**.
- [ ] No family match routes directly to safe AI generation labeled **AI-built**.
- [ ] Missing slots, family mismatch, unsupported fields/windows, compiler failure, reference mismatch, and semantic uncertainty route automatically to **AI-built**.
- [ ] Canonical semantic failure preserves compiled SQL as the AI lane's seeded candidate.
- [ ] Canonical database-preflight failure preserves compiled SQL, consumes the shared AI repair budget, and becomes terminal only after exhaustion.
- [ ] Resolver ambiguity and candidate values appear only in model context/assumptions, never in a clarification screen.
- [ ] Neilson five-year circulation prompt reaches an AI-built result fixture with no blocker response.
- [ ] Every successful Ask response and result view shows exactly one stable provenance label.
- [ ] Ask no longer renders clarification or exploratory-recovery components in the normal flow.
- [ ] Exhaustion uses concise Retry-oriented technical copy with no prompt-correction instructions.
- [ ] SQL safety, restricted-data policy, authorization, preflight, cancellation, timeout, connectivity, and resource limits remain enforced.
- [ ] Default-on rollout switch and administrative rollback behavior are tested.
- [ ] Full backend and frontend verification results are recorded.
- [ ] Phase 2 report revision work remains separate.
