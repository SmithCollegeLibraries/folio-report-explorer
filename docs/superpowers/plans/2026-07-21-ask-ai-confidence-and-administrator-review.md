# Ask AI Confidence and Administrator Review Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let every technically eligible Ask AI report run without a user approval gate, explain analytical uncertainty in ordinary language, and persist low-confidence or unsuccessful requests for asynchronous administrator review.

**Architecture:** Normalize the existing Ask response contract first, then build deterministic structural evidence and confidence classification around the current generation/repair pipeline. Persist every Ask generation in new MySQL records, link it to later query execution through an opaque generation ID, and expose a separate administrator review workflow without changing `query_jobs.status`. Add general explicit-value preservation last, after provenance and review signals can measure it.

**Tech Stack:** PHP 7.2-compatible Yii2 services/controllers/ActiveRecord, MySQL migrations, PostgreSQL preflight, standalone PHP regression scripts, React 18, TypeScript, TanStack Query, Vitest, and React Testing Library.

## Global Constraints

- Ask AI is for nontechnical users; ordinary responses must not expose SQL diagnostics, schema terms, validator categories, validator stages, or internal requirement keys.
- Builder, SQL Console, and Schema Explorer remain optional technical tools and are never required recovery paths.
- Low analytical confidence never blocks an otherwise safe and valid report.
- Destructive SQL, policy violations, invalid SQL after two repairs, failed blocking semantic requirements, `semantic_coverage_gap`, failed PostgreSQL preflight, and unavailable required data remain no-result outcomes.
- Existing query-family contracts and `QueryFamilyCompilerService` remain the only initial verified deterministic families.
- Hardened physical ROI remains exploratory with compiler version `physical_roi_v2`.
- Do not add an exploratory approval gate or a numerical user-facing confidence score.
- Do not change `query_jobs.status`; review advisory state belongs to `ai_report_reviews`.
- Do not trust client-supplied provenance; verify generation ownership and normalized SQL hashes server-side.
- Preserve the current two-repair maximum.
- Use PHP 7.2-compatible syntax; do not introduce arrow functions, typed properties, union types, or constructor property promotion.
- Do not edit generated schema caches, mapping caches, canonical query-family artifacts, dependency directories, or frontend build output.

---

### Task 1: Normalize Ask response modes and remove novice-facing diagnostics

**Files:**
- Create: `backend/services/AskResponseContractService.php`
- Create: `backend/tests/AskResponseContractServiceTest.php`
- Modify: `backend/services/GeminiService.php:420-475,4795-4845`
- Modify: `backend/controllers/FolioQueryController.php:590-690,1580-1815`
- Modify: `backend/tests/FolioQueryControllerExploratoryRepairTest.php`
- Modify: `frontend/src/components/ExploratoryRecoveryPanel.tsx`
- Modify: `frontend/src/components/ExploratoryRecoveryPanel.test.tsx`
- Modify: `frontend/src/pages/Ask.errorFormatting.test.ts`
- Modify: `frontend/src/types/schema.ts:310-375`

**Interfaces:**
- Produces: `AskResponseContractService::normalizeMode(array $result): array`.
- Produces: `AskResponseContractService::toUserResponse(array $result): array`.
- Preserves internally: `failureCategory`, `validatorStage`, and keyed unmet requirements until review evidence is captured in Task 6.
- Produces user recovery field: `recoveryItems: string[]`.

- [ ] **Step 1: Write the failing backend response-contract test**

Cover these exact cases:

```php
$canonical = AskResponseContractService::normalizeMode([
    'route' => 'builder_intent',
    'routeReason' => 'family_contract_supported:inventory_collection_age',
    'sql' => 'SELECT 1',
]);
assertSame('canonical', $canonical['mode']);

$exploratory = AskResponseContractService::normalizeMode([
    'route' => 'exploratory_legacy_freeform',
    'mode' => 'exploratory',
]);
assertSame('exploratory', $exploratory['mode']);

$user = AskResponseContractService::toUserResponse([
    'mode' => 'exploratory',
    'validationSummary' => [
        'status' => 'exhausted',
        'failureCategory' => 'missing_table',
        'validatorStage' => 'semantic_conformance',
    ],
    'unmetRequirements' => [
        ['key' => 'campus_scope', 'label' => 'The report uses the requested campus scope.'],
    ],
]);
assertFalse(isset($user['validationSummary']['failureCategory']));
assertFalse(isset($user['validationSummary']['validatorStage']));
assertFalse(isset($user['unmetRequirements']));
assertSame(['The report uses the requested campus scope.'], $user['recoveryItems']);
assertFalse(isset($user['needsExploratoryApproval']));
```

- [ ] **Step 2: Run the backend test and verify RED**

Run: `php backend/tests/AskResponseContractServiceTest.php`

Expected: FAIL because `AskResponseContractService` does not exist.

- [ ] **Step 3: Implement the response contract service**

Implement:

```php
final class AskResponseContractService
{
    public static function normalizeMode(array $result): array
    {
        $route = (string)($result['route'] ?? '');
        $reason = (string)($result['routeReason'] ?? '');
        if ($route === 'builder_intent' && strpos($reason, 'family_contract_supported:') === 0) {
            $result['mode'] = 'canonical';
        }
        unset($result['needsExploratoryApproval']);
        return $result;
    }

    public static function toUserResponse(array $result): array
    {
        $result = self::normalizeMode($result);
        $items = [];
        foreach (($result['unmetRequirements'] ?? []) as $requirement) {
            $label = trim((string)($requirement['label'] ?? ''));
            if ($label !== '') {
                $items[$label] = $label;
            }
        }
        if ($items !== []) {
            $result['recoveryItems'] = array_values($items);
        }
        unset($result['unmetRequirements']);
        if (isset($result['validationSummary']) && is_array($result['validationSummary'])) {
            unset($result['validationSummary']['failureCategory']);
            unset($result['validationSummary']['validatorStage']);
        }
        return $result;
    }
}
```

Call `normalizeMode()` on every Gemini result. Do not call `toUserResponse()` until Task 6 has recorded the complete internal evidence.

- [ ] **Step 4: Remove backend approval fields and technical recovery copy**

Remove every production assignment of `needsExploratoryApproval`. Replace generic failure copy with:

```php
'message' => 'I could not build a report I could safely run. Your request is preserved, and you can retry it or adjust one part of the question.',
```

Keep blocking semantic failures as no-result outcomes. Do not remove their internal evidence before `AskResponseContractService::toUserResponse()`.

- [ ] **Step 5: Write the failing frontend recovery tests**

Assert:

```tsx
expect(screen.queryByText(/safe failure category/i)).not.toBeInTheDocument();
expect(screen.queryByText(/missing table/i)).not.toBeInTheDocument();
expect(screen.queryByText(/semantic conformance/i)).not.toBeInTheDocument();
expect(screen.getByText('The report uses the requested campus scope.')).toBeInTheDocument();
expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
```

Delete `formatFailureCategory()` and render `response.recoveryItems` as ordinary sentences. Remove `needsExploratoryApproval` from `NlResponse` and all conditional rendering.

- [ ] **Step 6: Run focused response tests and verify GREEN**

Run:

```bash
php backend/tests/AskResponseContractServiceTest.php
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
cd frontend && npm test -- --run src/components/ExploratoryRecoveryPanel.test.tsx src/pages/Ask.errorFormatting.test.ts
```

Expected: all selected tests pass; no ordinary component renders a validator category.

- [ ] **Step 7: Commit**

```bash
git add backend/services/AskResponseContractService.php backend/services/GeminiService.php backend/controllers/FolioQueryController.php backend/tests/AskResponseContractServiceTest.php backend/tests/FolioQueryControllerExploratoryRepairTest.php frontend/src/components/ExploratoryRecoveryPanel.tsx frontend/src/components/ExploratoryRecoveryPanel.test.tsx frontend/src/pages/Ask.errorFormatting.test.ts frontend/src/types/schema.ts
git commit -m "fix: normalize Ask response trust states"
```

---

### Task 2: Produce deterministic SQL structural signatures

**Files:**
- Modify: `backend/services/ExploratorySqlAnalysisService.php`
- Modify: `backend/tests/ExploratorySqlAnalysisServiceTest.php`

**Interfaces:**
- Produces: `ExploratorySqlAnalysisService::structuralSignature(string $sql): array`.
- Produces: `ExploratorySqlAnalysisService::materiallyDifferent(string $initialSql, string $finalSql): bool`.
- Signature keys: `tables`, `joins`, `predicates`, `groupBy`, `measures`, `outputs`, `orderBy`, and `ambiguous`.

- [ ] **Step 1: Write failing signature tests**

Use pairs proving formatting and alias-only changes are equivalent while analytical changes are material:

```php
$base = 'SELECT i.title, COUNT(*) AS loans FROM inventory.instance__t i GROUP BY i.title ORDER BY loans DESC';
$aliasOnly = 'select inst.title, count(*) as total_loans from inventory.instance__t inst group by inst.title order by total_loans desc';
$newScope = $base . " LIMIT 10";
$newJoin = 'SELECT i.title, COUNT(l.id) FROM inventory.instance__t i JOIN circulation.loan__t l ON l.item_id = i.id GROUP BY i.title';

assertFalse(ExploratorySqlAnalysisService::materiallyDifferent($base, $aliasOnly));
assertTrue(ExploratorySqlAnalysisService::materiallyDifferent($base, $newScope));
assertTrue(ExploratorySqlAnalysisService::materiallyDifferent($base, $newJoin));
```

Add separate cases for changed predicate, grouping grain, measure, output, and ordering.

- [ ] **Step 2: Run the analysis test and verify RED**

Run: `php backend/tests/ExploratorySqlAnalysisServiceTest.php`

Expected: FAIL because the two public methods are missing.

- [ ] **Step 3: Implement canonical signature extraction**

Build from `analyze()` and normalize aliases out of expressions:

```php
public static function structuralSignature(string $sql): array
{
    $analysis = self::analyze($sql);
    return [
        'tables' => self::sortedUnique($analysis['tables'] ?? []),
        'joins' => self::canonicalJoins($analysis['joins'] ?? []),
        'predicates' => self::canonicalExpressions($analysis['predicates'] ?? []),
        'groupBy' => self::canonicalExpressions($analysis['groupBy'] ?? []),
        'measures' => self::measureExpressions($analysis['selectItems'] ?? []),
        'outputs' => self::outputExpressions($analysis['selectItems'] ?? []),
        'orderBy' => self::canonicalExpressions($analysis['orderBy'] ?? []),
        'limit' => $analysis['limit'] ?? null,
        'ambiguous' => !empty($analysis['ambiguous']),
    ];
}

public static function materiallyDifferent(string $initialSql, string $finalSql): bool
{
    return self::structuralSignature($initialSql) !== self::structuralSignature($finalSql);
}
```

Canonical helpers must sort unordered sets, preserve ordered output/order-by lists, replace source aliases with physical relation names, and mark unsupported analysis ambiguous rather than guessing.

- [ ] **Step 4: Run focused semantic-analysis regressions**

Run:

```bash
php backend/tests/ExploratorySqlAnalysisServiceTest.php
php backend/tests/ExploratorySqlSemanticValidatorServiceTest.php
```

Expected: both scripts pass.

- [ ] **Step 5: Commit**

```bash
git add backend/services/ExploratorySqlAnalysisService.php backend/tests/ExploratorySqlAnalysisServiceTest.php
git commit -m "feat: compare exploratory SQL structure"
```

---

### Task 3: Classify confidence from deterministic evidence

**Files:**
- Create: `backend/services/AskConfidenceClassificationService.php`
- Create: `backend/services/AskUserExplanationService.php`
- Create: `backend/tests/AskConfidenceClassificationServiceTest.php`
- Create: `backend/tests/AskUserExplanationServiceTest.php`

**Interfaces:**
- Consumes evidence array with `mode`, `route`, `routeReason`, `validationStatus`, `repairAttempts`, `materialRepair`, `defaultedAssumptionKeys`, `limitedSemanticCoverage`, `crossDomain`, `proxyLinkage`, `knownDataLimitations`, and `policyBlocked`.
- Produces: `AskConfidenceClassificationService::classify(array $evidence): array{reviewRequired:bool,reviewReasons:string[]}`.
- Produces: `AskUserExplanationService::notice(string $mode, bool $reviewRequired, array $reviewReasons, array $assumptions): ?array`.

- [ ] **Step 1: Write the failing classifier matrix**

Assert:

```php
assertSame(
    ['reviewRequired' => false, 'reviewReasons' => []],
    AskConfidenceClassificationService::classify([
        'mode' => 'canonical', 'validationStatus' => 'validated',
    ])
);

$flagged = AskConfidenceClassificationService::classify([
    'mode' => 'exploratory',
    'validationStatus' => 'validated',
    'crossDomain' => true,
    'materialRepair' => true,
]);
assertTrue($flagged['reviewRequired']);
assertSame(['cross_domain_analysis', 'material_repair'], $flagged['reviewReasons']);

$policy = AskConfidenceClassificationService::classify([
    'policyBlocked' => true, 'validationStatus' => null,
]);
assertFalse($policy['reviewRequired']);

$exhausted = AskConfidenceClassificationService::classify([
    'mode' => 'exploratory', 'validationStatus' => 'exhausted',
]);
assertTrue($exhausted['reviewRequired']);
assertSame(['unable_to_validate'], $exhausted['reviewReasons']);
```

Also assert that an arbitrary `modelConfidence => high` input has no effect.

In `AskUserExplanationServiceTest.php`, assert canonical mode returns null, ordinary exploratory mode uses “AI-assisted report,” flagged mode uses “AI-assisted report — review flagged,” and internal reason keys are replaced by allowlisted domain-language sentences.

- [ ] **Step 2: Run the test and verify RED**

Run: `php backend/tests/AskConfidenceClassificationServiceTest.php && php backend/tests/AskUserExplanationServiceTest.php`

Expected: FAIL because the classifier and explanation service do not exist.

- [ ] **Step 3: Implement stable rule ordering**

Implement one fixed rule map:

```php
private const REVIEW_RULES = [
    'crossDomain' => 'cross_domain_analysis',
    'materialRepair' => 'material_repair',
    'limitedSemanticCoverage' => 'limited_semantic_coverage',
    'proxyLinkage' => 'proxy_linkage',
    'knownDataLimitations' => 'known_data_limitation',
    'unresolvedDomainAmbiguity' => 'unresolved_domain_ambiguity',
];
```

Return no review for policy blocks and clarifications. Return no review for validated canonical query families. Add `unable_to_validate` for `exhausted` or `rejected`. Add `documented_default` when `defaultedAssumptionKeys` is nonempty and at least one key is marked material by the evidence builder. Never read a model confidence field.

- [ ] **Step 4: Implement allowlisted user explanations**

Use this fixed map and never interpolate internal identifiers:

```php
private const REASON_MESSAGES = [
    'cross_domain_analysis' => 'This report combines information from more than one reporting area.',
    'material_repair' => 'The report needed a substantial automatic correction before it could run.',
    'limited_semantic_coverage' => 'The checked requirements passed, but this is still an exploratory analysis.',
    'proxy_linkage' => 'Some records are connected through a broader matching method rather than an exact item link.',
    'known_data_limitation' => 'The available reporting data has an important limitation for this question.',
    'unresolved_domain_ambiguity' => 'I used a reasonable interpretation for wording that can have more than one meaning.',
    'documented_default' => 'I used a documented reporting assumption where the question did not specify one.',
];
```

Return at most three unique sentences. Canonical mode returns null. Flagged exploratory title includes “review flagged”; ordinary exploratory title does not.

- [ ] **Step 5: Run the classifier and explanation tests**

Run: `php backend/tests/AskConfidenceClassificationServiceTest.php && php backend/tests/AskUserExplanationServiceTest.php`

Expected: both service tests pass and exit 0.

- [ ] **Step 6: Commit**

```bash
git add backend/services/AskConfidenceClassificationService.php backend/services/AskUserExplanationService.php backend/tests/AskConfidenceClassificationServiceTest.php backend/tests/AskUserExplanationServiceTest.php
git commit -m "feat: classify Ask review evidence"
```

---

### Task 4: Add generation and review persistence schema

**Files:**
- Create: `mysql/migrations/039_ask_ai_report_review.sql`
- Modify: `mysql/init.sql`
- Create: `backend/models/AiReportGeneration.php`
- Create: `backend/models/AiReportReview.php`
- Modify: `backend/services/MigrationService.php:245-335`
- Modify: `backend/tests/MigrationServiceTest.php`
- Create: `backend/tests/AskAiReportReviewSchemaTest.php`
- Modify: `backend/config/params.php`
- Modify: `backend/services/SettingsService.php`

**Interfaces:**
- Produces MySQL tables: `ai_report_generations`, `ai_report_reviews`.
- Produces setting: `ai_report_review_retention_days`, integer default `90`.
- Produces ActiveRecord models with string UUID primary keys.

- [ ] **Step 1: Write failing migration/schema tests**

Assert migration text contains the required columns, foreign keys, lifecycle values, and indexes. Assert `MigrationService::migrationLooksApplied()` requires both tables. Assert the setting resolves to 90 when unset and clamps configured values to `1..3650`.

- [ ] **Step 2: Run migration tests and verify RED**

Run:

```bash
php backend/tests/AskAiReportReviewSchemaTest.php
php backend/tests/MigrationServiceTest.php
```

Expected: FAIL because migration 039 and both models are absent.

- [ ] **Step 3: Create the idempotent migration**

Create `ai_report_generations` with these exact logical fields:

```sql
id CHAR(36) PRIMARY KEY,
conversation_id CHAR(36) NOT NULL,
parent_generation_id CHAR(36) NULL,
query_job_id CHAR(36) NULL,
user_id INT NULL,
prompt_fingerprint CHAR(16) NOT NULL,
original_question TEXT NOT NULL,
follow_up_context JSON NULL,
response_mode VARCHAR(32) NULL,
execution_mode ENUM('deterministic','exploratory') NULL,
route VARCHAR(128) NULL,
route_reason VARCHAR(255) NULL,
validation_status ENUM('validated','exhausted','rejected') NULL,
generated_sql MEDIUMTEXT NULL,
sql_hash CHAR(64) NULL,
assumptions_json JSON NULL,
user_notice_json JSON NULL,
confidence_evidence_json JSON NOT NULL,
initial_structure_json JSON NULL,
final_structure_json JSON NULL,
provenance_json JSON NOT NULL,
review_required TINYINT(1) NOT NULL DEFAULT 0,
review_reasons_json JSON NOT NULL,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
linked_at DATETIME NULL,
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

Create `ai_report_reviews` with:

```sql
id CHAR(36) PRIMARY KEY,
generation_id CHAR(36) NOT NULL UNIQUE,
status ENUM('pending','in_review','resolved','dismissed') NOT NULL DEFAULT 'pending',
disposition ENUM('acceptable','assumption_change','deterministic_candidate','generation_defect','data_unavailable','specialist_interpretation') NULL,
advisory_state ENUM('none','cautioned','superseded') NOT NULL DEFAULT 'none',
superseded_by_job_id CHAR(36) NULL,
administrator_notes TEXT NULL,
reviewed_by INT NULL,
claimed_at DATETIME NULL,
resolved_at DATETIME NULL,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

Add foreign keys with deletion behavior matching the design: generation-to-parent `SET NULL`, generation-to-query-job `SET NULL` as a database safety net, review-to-generation `CASCADE`, reviewer `SET NULL`, and superseding-job `SET NULL`. Task 9's history-deletion service explicitly deletes linked generation/review rows before deleting the job. Add indexes for conversation, parent, job, user, created time, review-required, review status/created time, disposition, and advisory state.

- [ ] **Step 4: Add PHP 7.2-compatible models and migration recognition**

Each model returns its table name, marks UUID and JSON/text fields safe, validates enum values, and provides a UUID v4 helper without typed properties. Add migration 039 to `MigrationService` and both tables to required schema checks.

- [ ] **Step 5: Run schema tests**

Run:

```bash
php backend/tests/AskAiReportReviewSchemaTest.php
php backend/tests/MigrationServiceTest.php
php backend/tests/MySqlDashboardBootstrapSchemaTest.php
```

Expected: all selected scripts pass.

- [ ] **Step 6: Commit**

```bash
git add mysql/migrations/039_ask_ai_report_review.sql mysql/init.sql backend/models/AiReportGeneration.php backend/models/AiReportReview.php backend/services/MigrationService.php backend/services/SettingsService.php backend/config/params.php backend/tests/AskAiReportReviewSchemaTest.php backend/tests/MigrationServiceTest.php
git commit -m "feat: persist Ask generation reviews"
```

---

### Task 5: Persist trusted generation evidence and review lifecycle

**Files:**
- Create: `backend/services/AdministratorReviewService.php`
- Create: `backend/tests/AdministratorReviewServiceTest.php`

**Interfaces:**
- Produces: `recordGeneration(array $context): array{generationId:string,conversationId:string,reviewId:?string}`.
- Produces: `claim(string $reviewId, int $administratorId): array`.
- Produces: `resolve(string $reviewId, int $administratorId, string $disposition, string $notes, string $advisoryState = 'none', ?string $supersededByJobId = null): array`.
- Produces: `purgeExpired(int $days, DateTimeImmutable $now): int`.
- Produces: `purgeUserContent(int $userId): int`.

- [ ] **Step 1: Write failing SQLite service tests**

Create compatible in-memory tables and assert one transaction inserts a generation plus review only when `reviewRequired=true`. Assert explicit null provenance survives JSON encoding, duplicate review creation is idempotent, and a forced review insert failure rolls back the generation transaction.

For claim concurrency, invoke two claims and assert exactly one changes `pending` to `in_review`. Resolve must require `in_review`, a valid disposition, and `supersededByJobId` only for `advisoryState=superseded`.

- [ ] **Step 2: Run the service test and verify RED**

Run: `php backend/tests/AdministratorReviewServiceTest.php`

Expected: FAIL because the service is absent.

- [ ] **Step 3: Implement transactional recording**

Use one local-DB transaction:

```php
public function recordGeneration(array $context): array
{
    return $this->db->transaction(function () use ($context) {
        $generation = $this->insertGeneration($context);
        $reviewId = null;
        if (!empty($context['reviewRequired'])) {
            $reviewId = $this->insertReview($generation->id);
        }
        return [
            'generationId' => $generation->id,
            'conversationId' => $generation->conversation_id,
            'reviewId' => $reviewId,
        ];
    });
}
```

Generate conversation IDs server-side for root requests. Validate owned parent generations before inheriting their conversation ID. Store all evidence/provenance JSON with stable key ordering before hashing or encoding.

- [ ] **Step 4: Implement atomic claim, resolution, and retention**

Claim with:

```php
$affected = $this->db->createCommand()->update('ai_report_reviews', [
    'status' => 'in_review',
    'reviewed_by' => $administratorId,
    'claimed_at' => gmdate('Y-m-d H:i:s'),
], ['id' => $reviewId, 'status' => 'pending'])->execute();
```

Resolve only an item claimed by the same administrator or an administrator explicitly taking it over. Purge unlinked generations older than the cutoff and resolved/dismissed reviews older than the cutoff; delete aggregate-free raw records rather than retaining prompt or SQL.

- [ ] **Step 5: Run the service test**

Run: `php backend/tests/AdministratorReviewServiceTest.php`

Expected: the service test passes and exits 0.

- [ ] **Step 6: Commit**

```bash
git add backend/services/AdministratorReviewService.php backend/tests/AdministratorReviewServiceTest.php
git commit -m "feat: manage Ask administrator reviews"
```

---

### Task 6: Record every Ask outcome before user sanitization

**Files:**
- Create: `backend/services/AskGenerationEvidenceService.php`
- Create: `backend/tests/AskGenerationEvidenceServiceTest.php`
- Modify: `backend/controllers/FolioQueryController.php:1580-1815`
- Modify: `backend/tests/FolioQueryControllerAskContinuationPolicyTest.php`
- Modify: `backend/tests/FolioQueryControllerExploratoryRepairTest.php`

**Interfaces:**
- Produces: `AskGenerationEvidenceService::build(array $result, array $requestContext): array`.
- Consumes: `AskConfidenceClassificationService::classify()` and structural signatures.
- Consumes: `AskUserExplanationService::notice()` for allowlisted ordinary copy.
- Adds ordinary response fields: `generationId`, `conversationId`, `reviewRequired`, and user-safe `reviewNotice`.

- [ ] **Step 1: Write failing evidence and controller tests**

Assert canonical family, ordinary exploratory, flagged exploratory, clarification, policy 403, exhausted, and rejected outcomes map exactly to the design table. Assert `physical_roi_v2` remains exploratory. Assert the persistence callback receives internal categories/stages/requirement keys while the returned response does not.

- [ ] **Step 2: Run focused tests and verify RED**

Run:

```bash
php backend/tests/AskGenerationEvidenceServiceTest.php
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
```

Expected: FAIL because generation evidence and identifiers are not produced.

- [ ] **Step 3: Implement evidence construction**

Build evidence from route, assumptions, semantic validation, repair count, initial/final candidates, compiler version, schema metadata, reference bundle metadata, prompt version, and model name. Use explicit nulls for unavailable provenance. Compute:

```php
'materialRepair' => $initialSql !== null && $finalSql !== null
    ? ExploratorySqlAnalysisService::materiallyDifferent($initialSql, $finalSql)
    : false,
```

Never read a model confidence value.

- [ ] **Step 4: Integrate one controller finalization boundary**

Add a private controller method that records before sanitizing:

```php
private function finalizeAskResponse(array $result, string $prompt, $userId, array $context): array
{
    $result = AskResponseContractService::normalizeMode($result);
    $evidence = AskGenerationEvidenceService::build($result, $context + ['prompt' => $prompt]);
    $classification = AskConfidenceClassificationService::classify($evidence);
    try {
        $record = $this->administratorReviewService()->recordGeneration(
            $evidence + $classification + ['userId' => $userId]
        );
        $result['generationId'] = $record['generationId'];
        $result['conversationId'] = $record['conversationId'];
    } catch (\Throwable $exception) {
        Yii::warning('Ask review persistence failed: ' . get_class($exception), 'nl2sql.review');
    }
    $result['reviewRequired'] = $classification['reviewRequired'];
    $result['reviewNotice'] = AskUserExplanationService::notice(
        (string)($result['mode'] ?? ''),
        (bool)$classification['reviewRequired'],
        $classification['reviewReasons'],
        is_array($result['assumptions'] ?? null) ? $result['assumptions'] : []
    );
    return AskResponseContractService::toUserResponse($result);
}
```

Route successful, clarification, recovery, and policy responses through this boundary. Persistence failure must not change a safe response status or remove SQL.

- [ ] **Step 5: Run controller and semantic hard-stop regressions**

Run:

```bash
php backend/tests/AskGenerationEvidenceServiceTest.php
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php backend/tests/FolioQueryControllerAskContinuationPolicyTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
php backend/tests/ExploratorySqlSemanticValidatorServiceTest.php
```

Expected: all pass; semantic gaps still return no SQL.

- [ ] **Step 6: Commit**

```bash
git add backend/services/AskGenerationEvidenceService.php backend/controllers/FolioQueryController.php backend/tests/AskGenerationEvidenceServiceTest.php backend/tests/FolioQueryControllerAskContinuationPolicyTest.php backend/tests/FolioQueryControllerExploratoryRepairTest.php
git commit -m "feat: record Ask confidence evidence"
```

---

### Task 7: Link generated SQL to execution without trusting the client

**Files:**
- Modify: `backend/services/AdministratorReviewService.php`
- Modify: `backend/controllers/FolioQueryController.php:1120-1265,1350-1410`
- Create: `backend/tests/FolioQueryControllerGenerationLinkTest.php`
- Modify: `frontend/src/api/client.ts:370-415`
- Modify: `frontend/src/pages/Ask.tsx:710-820,920-950,2380-2400`
- Modify: `frontend/src/types/schema.ts`

**Interfaces:**
- Produces: `AdministratorReviewService::resolveExecutionGeneration(string $generationId, int $userId, string $normalizedSql): array`.
- Extends `submitQuery()` options with `generationId?: string`.
- Links exact SQL to original generation; creates `user_edited_sql` child generation on owned hash mismatch.

- [ ] **Step 1: Write failing generation-link controller tests**

Assert exact owned hash links job metadata and sets `query_job_id`. Assert mismatch creates a child with the same `conversation_id`, `parent_generation_id` set, `route_reason=user_edited_sql`, and `review_required=1`. Assert unknown and other-user IDs return 403 for `source=nl`. Assert manual and builder submissions remain unchanged.

- [ ] **Step 2: Run the controller test and verify RED**

Run: `php backend/tests/FolioQueryControllerGenerationLinkTest.php`

Expected: FAIL because `generationId` is ignored.

- [ ] **Step 3: Implement trusted linkage**

Normalize SQL exactly as `actionQuerySubmit()` does before hashing. In `resolveExecutionGeneration()`:

```php
if (!$generation || (int)$generation->user_id !== $userId) {
    throw new \DomainException('generation_not_owned');
}
if (hash_equals((string)$generation->sql_hash, hash('sha256', $normalizedSql))) {
    return ['generation' => $generation, 'edited' => false];
}
return ['generation' => $this->createEditedChild($generation, $normalizedSql), 'edited' => true];
```

After job save, update generation `query_job_id`, `linked_at`, and trusted `query_jobs.metadata.askAiProvenance`. Never copy provenance from request JSON.

- [ ] **Step 4: Pass generation IDs through Ask execution**

Add to types:

```ts
generationId?: string;
conversationId?: string;
reviewRequired?: boolean;
reviewNotice?: { title: string; message: string };
```

Pass `result.generationId` in `runGeneratedQuery()` and every re-run of the same generated SQL. Edited SQL uses the same parent generation ID so the backend creates the derivative record.

- [ ] **Step 5: Run backend and frontend linkage tests**

Run:

```bash
php backend/tests/FolioQueryControllerGenerationLinkTest.php
cd frontend && npm test -- --run src/pages/Ask.followUp.test.ts src/api/client.followUp.test.ts
```

Expected: all selected tests pass.

- [ ] **Step 6: Commit**

```bash
git add backend/services/AdministratorReviewService.php backend/controllers/FolioQueryController.php backend/tests/FolioQueryControllerGenerationLinkTest.php frontend/src/api/client.ts frontend/src/pages/Ask.tsx frontend/src/types/schema.ts frontend/src/pages/Ask.followUp.test.ts frontend/src/api/client.followUp.test.ts
git commit -m "feat: link Ask generations to query jobs"
```

---

### Task 8: Add administrator review APIs with atomic claim semantics

**Files:**
- Modify: `backend/config/web.php:130-170`
- Modify: `backend/controllers/FolioQueryController.php:90-110`
- Modify: `backend/controllers/FolioQueryController.php`
- Create: `backend/tests/FolioQueryControllerReportReviewTest.php`

**Interfaces:**
- Produces: `GET /api/admin/report-reviews`.
- Produces: `GET /api/admin/report-reviews/<id>`.
- Produces: `POST /api/admin/report-reviews/<id>/claim`.
- Produces: `PATCH /api/admin/report-reviews/<id>`.

- [ ] **Step 1: Write failing endpoint tests**

Cover regular-user 403 for every endpoint, paginated pending ordering, detail including technical evidence only for administrators, atomic claim conflict 409, resolve/dismiss validation, caution, and supersession requiring a completed replacement job.

- [ ] **Step 2: Run the endpoint test and verify RED**

Run: `php backend/tests/FolioQueryControllerReportReviewTest.php`

Expected: FAIL because routes/actions are missing.

- [ ] **Step 3: Add routes and controller authorization**

Add:

```php
'GET admin/report-reviews' => 'folio-query/report-review-list',
'GET admin/report-reviews/<id:[\w-]+>' => 'folio-query/report-review-detail',
'POST admin/report-reviews/<id:[\w-]+>/claim' => 'folio-query/report-review-claim',
'PATCH admin/report-reviews/<id:[\w-]+>' => 'folio-query/report-review-update',
```

Use the existing authenticated user role and a shared private `requireAdministrator(): ?array` helper returning the stable 403 response. Do not include review technical details in any non-admin endpoint.

- [ ] **Step 4: Implement list/detail/claim/update actions**

Clamp list `limit` to 1..100, filter allowlisted status/disposition values, and sort pending by `created_at ASC`. Use `AdministratorReviewService` for claim and resolution. Return 409 when the conditional claim updates zero rows.

- [ ] **Step 5: Run endpoint and authorization tests**

Run:

```bash
php backend/tests/FolioQueryControllerReportReviewTest.php
php backend/tests/FolioQueryControllerPolicyViolationStatusTest.php
php backend/tests/FolioQueryControllerHistoryDeletionTest.php
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add backend/config/web.php backend/controllers/FolioQueryController.php backend/tests/FolioQueryControllerReportReviewTest.php
git commit -m "feat: expose administrator report reviews"
```

---

### Task 9: Integrate review advisories, deletion, user purge, and retention

**Files:**
- Modify: `backend/services/QueryHistoryDeletionService.php`
- Modify: `backend/tests/QueryHistoryDeletionServiceTest.php`
- Modify: `backend/controllers/FolioQueryController.php:3950-4085`
- Modify: `backend/tests/FolioQueryControllerHistoryDeletionTest.php`
- Modify: `backend/commands/CleanupController.php`
- Create: `backend/tests/AskAiReviewRetentionTest.php`

**Interfaces:**
- History rows gain user-safe `reviewAdvisory?: {state:'cautioned'|'superseded',message:string,supersededByJobId?:string}`.
- Query deletion removes linked review/generation data in the same transaction.
- Cleanup uses `ai_report_review_retention_days`.

- [ ] **Step 1: Write failing deletion/advisory/retention tests**

Assert completed query status remains `completed` when cautioned or superseded. Assert history returns only advisory copy, never notes/evidence. Assert single and batch history deletion remove linked generation/review rows. Assert user deletion purges raw question, SQL, follow-up context, and admin notes. Assert cutoff behavior at 89/90/91 days.

- [ ] **Step 2: Run focused tests and verify RED**

Run:

```bash
php backend/tests/QueryHistoryDeletionServiceTest.php
php backend/tests/AskAiReviewRetentionTest.php
```

Expected: FAIL because linked review data remains and no advisory is returned.

- [ ] **Step 3: Extend history deletion transactionally**

Before deleting an eligible job, delete `ai_report_reviews` through linked generations, then delete `ai_report_generations`, export file, and job. Any database failure rolls back database changes; existing safe export behavior remains unchanged.

- [ ] **Step 4: Add safe history advisory projection, user purge, and cleanup command**

Join review advisory state only. Map:

```php
'cautioned' => ['state' => 'cautioned', 'message' => 'A reporting specialist identified an important limitation in this result.'],
'superseded' => ['state' => 'superseded', 'message' => 'A corrected version of this report is available.', 'supersededByJobId' => $row['superseded_by_job_id']],
```

Call `AdministratorReviewService::purgeExpired()` from the existing cleanup command using the configured days.

Before `actionUserDelete()` removes a user, call `AdministratorReviewService::purgeUserContent((int)$id)`. That method deletes the user's review rows followed by generation rows in one transaction, ensuring raw prompts, SQL, follow-up context, and administrator notes do not survive the account deletion.

- [ ] **Step 5: Run deletion and retention regressions**

Run:

```bash
php backend/tests/QueryHistoryDeletionServiceTest.php
php backend/tests/FolioQueryControllerHistoryDeletionTest.php
php backend/tests/AskAiReviewRetentionTest.php
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add backend/services/QueryHistoryDeletionService.php backend/controllers/FolioQueryController.php backend/commands/CleanupController.php backend/tests/QueryHistoryDeletionServiceTest.php backend/tests/FolioQueryControllerHistoryDeletionTest.php backend/tests/AskAiReviewRetentionTest.php
git commit -m "feat: retain Ask review provenance safely"
```

---

### Task 10: Show nonblocking confidence notices in Ask and History

**Files:**
- Create: `frontend/src/components/AskTrustNotice.tsx`
- Create: `frontend/src/components/AskTrustNotice.test.tsx`
- Modify: `frontend/src/pages/Ask.tsx`
- Modify: `frontend/src/pages/Ask.errorFormatting.test.ts`
- Modify: `frontend/src/pages/history/HistoryResultsModal.tsx`
- Modify: `frontend/src/types/schema.ts`

**Interfaces:**
- Consumes `mode`, `reviewRequired`, `reviewNotice`, `assumptions`, and `reviewAdvisory`.
- Deterministic reports render no trust notice.
- AI-assisted notices contain no acknowledgment control and never block Run, Save, Export, Follow-up, or Re-run.

- [ ] **Step 1: Write failing trust-notice tests**

Assert canonical validated response renders no notice. Ordinary exploratory renders “AI-assisted report” and assumptions. Flagged exploratory renders the stronger limitation and “flagged for routine review” without buttons labeled Approve, Continue, or Confirm. Ensure View SQL remains optional and no copy tells the user to review SQL.

- [ ] **Step 2: Run component tests and verify RED**

Run: `cd frontend && npm test -- --run src/components/AskTrustNotice.test.tsx src/pages/Ask.errorFormatting.test.ts`

Expected: FAIL because the component is absent and current copy instructs SQL review.

- [ ] **Step 3: Implement the presentational component**

Use this branch contract:

```tsx
if (mode === 'canonical') return null;
const title = reviewRequired ? 'AI-assisted report — review flagged' : 'AI-assisted report';
return (
  <aside role="note" className={reviewRequired ? flaggedClass : assistedClass}>
    <h3>{title}</h3>
    <p>{reviewNotice?.message}</p>
  </aside>
);
```

Do not render confidence percentages, route names, validator data, table names, or an approval action.

- [ ] **Step 4: Integrate Ask and safe history advisories**

Replace existing “review SQL before using” copy. Keep the View SQL control behind its current deliberate action. Render cautioned/superseded history messages without administrator notes.

- [ ] **Step 5: Run focused frontend tests**

Run:

```bash
cd frontend && npm test -- --run src/components/AskTrustNotice.test.tsx src/pages/Ask.errorFormatting.test.ts src/pages/Ask.followUp.test.ts src/pages/history/HistoryResultsModal.followUp.test.tsx
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/components/AskTrustNotice.tsx frontend/src/components/AskTrustNotice.test.tsx frontend/src/pages/Ask.tsx frontend/src/pages/Ask.errorFormatting.test.ts frontend/src/pages/history/HistoryResultsModal.tsx frontend/src/types/schema.ts
git commit -m "feat: explain AI-assisted report confidence"
```

---

### Task 11: Build the administrator review page

**Files:**
- Create: `frontend/src/pages/ReportReviews.tsx`
- Create: `frontend/src/pages/ReportReviews.test.tsx`
- Modify: `frontend/src/api/client.ts`
- Modify: `frontend/src/types/schema.ts`
- Modify: `frontend/src/App.tsx`

**Interfaces:**
- Produces admin route: `/report-reviews`.
- Consumes the four review API endpoints from Task 8.
- Supports filters, detail, atomic claim, disposition, notes, caution, and supersede job ID.

- [ ] **Step 1: Write failing admin-page tests**

Mock pending and resolved API responses. Assert oldest pending first, technical details hidden until detail open, claim conflict refreshes the list, resolve requires disposition, supersede requires replacement job ID, and ordinary users cannot reach the protected route.

- [ ] **Step 2: Run the page test and verify RED**

Run: `cd frontend && npm test -- --run src/pages/ReportReviews.test.tsx`

Expected: FAIL because types, client methods, route, and page are absent.

- [ ] **Step 3: Add typed client methods**

Implement:

```ts
export const fetchReportReviews = async (params: ReportReviewFilters): Promise<ReportReviewListResponse> =>
  (await api.get('/admin/report-reviews', { params })).data;
export const fetchReportReview = async (id: string): Promise<ReportReviewDetail> =>
  (await api.get(`/admin/report-reviews/${id}`)).data;
export const claimReportReview = async (id: string): Promise<ReportReviewDetail> =>
  (await api.post(`/admin/report-reviews/${id}/claim`)).data;
export const updateReportReview = async (id: string, input: ReportReviewUpdate): Promise<ReportReviewDetail> =>
  (await api.patch(`/admin/report-reviews/${id}`, input)).data;
```

- [ ] **Step 4: Implement the admin page and navigation**

Add an Admin dropdown item labeled “AI Report Review” with description “Review uncertain Ask AI reports,” and a `<ProtectedRoute adminOnly>` route. Use TanStack Query keys `['report-reviews', filters]` and `['report-review', id]`. Invalidate both after claim/update. Show SQL and technical evidence only inside the admin detail panel.

- [ ] **Step 5: Run frontend admin tests and build**

Run:

```bash
cd frontend && npm test -- --run src/pages/ReportReviews.test.tsx
npm run build
```

Expected: test passes; TypeScript and Vite build exit 0.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/ReportReviews.tsx frontend/src/pages/ReportReviews.test.tsx frontend/src/api/client.ts frontend/src/types/schema.ts frontend/src/App.tsx
git commit -m "feat: add AI report review workspace"
```

---

### Task 12: Add a general explicit-values interpretation path

**Files:**
- Create: `backend/services/ExplicitReportRequestService.php`
- Create: `backend/tests/ExplicitReportRequestServiceTest.php`
- Modify: `backend/services/GeminiService.php:138-220,7030-7530`
- Modify: `backend/tests/GeminiServiceQueryFamilySelectionTest.php`

**Interfaces:**
- Produces: `ExplicitReportRequestService::extract(string $prompt): array{applicable:bool,identifiers:array,requestedFields:string[],limit:?int}`.
- Produces: `ExplicitReportRequestService::validateCandidate(string $sql, array $request): array{valid:bool,missingIdentifiers:string[],missingFields:string[]}`.
- Supports initial identifier kinds: `instance_hrid`, `item_barcode`, and UUID-shaped `instance_id`/`item_id` only when the prompt names the entity.
- Produces exact-value guidance and evidence consumed by generation; it does not guess unlabeled numeric tokens.

- [ ] **Step 1: Write failing explicit-request tests**

Cover comma/newline-separated instance numbers, quoted barcodes, UUIDs with explicit entity labels, requested fields, duplicates, order preservation, and explicit limits. Assert ordinary years, currency, and unlabeled numbers do not become identifiers.

```php
$request = ExplicitReportRequestService::extract(
    'For instance numbers in0001, in0002, show title, barcode, and publication date. Limit 20.'
);
assertTrue($request['applicable']);
assertSame(['in0001', 'in0002'], $request['identifiers']['instance_hrid']);
assertSame(['title', 'barcode', 'publication_date'], $request['requestedFields']);
assertSame(20, $request['limit']);

$candidate = ExplicitReportRequestService::validateCandidate(
    "SELECT inst.hrid AS instance_hrid, inst.title, item.barcode FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001','in0002') LIMIT 20",
    $request
);
assertTrue($candidate['valid']);
```

Add failing candidate cases missing `in0002` and missing the requested `publication_date` output.

- [ ] **Step 2: Run the service test and verify RED**

Run: `php backend/tests/ExplicitReportRequestServiceTest.php`

Expected: FAIL because the service is missing.

- [ ] **Step 3: Implement conservative extraction**

Use named anchors (`instance number`, `instance HRID`, `barcode`, `instance ID`, `item ID`) and allowlisted output phrases. Preserve first-seen order, deduplicate exact normalized identifiers, cap identifiers at 500, and mark overflow as a domain-language clarification rather than truncating silently.

`validateCandidate()` normalizes SQL string literals, derives output aliases through `ExploratorySqlAnalysisService`, and returns every missing explicit value or requested supported field. It never repairs or silently drops explicit values.

- [ ] **Step 4: Integrate explicit evidence before AI generation**

Append a structured, server-created section to the effective prompt:

```text
EXPLICIT REPORT VALUES — preserve exactly:
instance_hrid: ["in0001","in0002"]
requested_fields: ["title","barcode","publication_date"]
limit: 20
Do not broaden, replace, or infer additional identifiers.
```

Pass the same evidence into confidence/provenance. A candidate that omits or changes an explicit identifier or requested supported field fails validation and enters repair; it is not merely review-flagged.

- [ ] **Step 5: Run explicit and family-routing regressions**

Run:

```bash
php backend/tests/ExplicitReportRequestServiceTest.php
php backend/tests/GeminiServiceQueryFamilySelectionTest.php
php backend/tests/GeminiServiceInventoryListingExtractionTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Expected: all pass; existing query-family routing remains unchanged.

- [ ] **Step 6: Commit**

```bash
git add backend/services/ExplicitReportRequestService.php backend/services/GeminiService.php backend/tests/ExplicitReportRequestServiceTest.php backend/tests/GeminiServiceQueryFamilySelectionTest.php
git commit -m "feat: preserve explicit Ask report values"
```

---

### Task 13: Add versioned prompt corpora and run release verification

**Files:**
- Create: `backend/tests/fixtures/ask_novice_confidence_corpus.json`
- Create: `backend/tests/fixtures/ask_explicit_intent_corpus.json`
- Create: `backend/tests/AskConfidenceCorpusTest.php`
- Modify: `README.md`
- Modify: `docs/reference-cache-operations.md`

**Interfaces:**
- Corpus case fields: `id`, `prompt`, `context`, `expectedMode`, `expectedExecution`, `expectedReviewRequired`, `expectedReviewReasons`, `expectedUserPhrases`, `forbiddenUserPhrases`, and `explicitValues`.

- [ ] **Step 1: Create the corpus harness and fixtures**

Add novice cases for tentative wording, obscure collections, ROI, missing dates, defaults, clarification, semantic gap, unavailable data, and zero rows. Add explicit cases for instance HRIDs, item barcodes, UUIDs, fields, limits, equivalent rephrasing, policy blocks, and destructive prompt injection.

The harness uses deterministic fake generation/preflight outcomes and asserts mode, execution eligibility, review classification, explicit-value preservation, user copy, and forbidden technical phrases.

- [ ] **Step 2: Run the integrated corpus**

Run: `php backend/tests/AskConfidenceCorpusTest.php`

Expected: `Ask confidence corpus test passed` and exit 0. A failure blocks release and must be reduced to a focused regression in the owning service test before production code changes.

- [ ] **Step 3: Run complete backend verification twice**

Run:

```bash
for pass in 1 2; do
  for test in backend/tests/*Test.php; do php "$test" || exit 1; done
done
```

Expected: every backend script passes in both runs; credential-dependent live PostgreSQL tests may report their documented skip but must not fail.

- [ ] **Step 4: Run complete frontend verification and production build**

Run:

```bash
cd frontend
npm test -- --run
npm run build
```

Expected: all frontend tests pass; `tsc -b && vite build` exits 0. Existing chunk-size advisories may remain warnings.

- [ ] **Step 5: Run release audits**

Run:

```bash
git diff --check main...HEAD
git status --short
git diff --name-only main...HEAD | rg '(^backend/data/(column_cache|subtable_cache|table_mapping_cache)\.json$|node_modules|backend/vendor|frontend/dist)' && exit 1 || true
```

Expected: diff check exits 0; the feature diff contains no generated caches, dependencies, or build output. Pre-existing unrelated worktree changes remain untouched and documented.

- [ ] **Step 6: Update operating documentation**

Document the four visible outcomes, the hard semantic/safety boundary, optional View SQL behavior, administrator review retention setting, review API authorization, cleanup behavior, and production smoke cases. Remove README wording that characterizes all Ask AI behavior as unqualified “natural language → SQL via Gemini.”

- [ ] **Step 7: Commit verification artifacts and documentation**

```bash
git add backend/tests/fixtures/ask_novice_confidence_corpus.json backend/tests/fixtures/ask_explicit_intent_corpus.json backend/tests/AskConfidenceCorpusTest.php README.md docs/reference-cache-operations.md
git commit -m "test: verify Ask confidence review workflow"
```

## Production Smoke Checklist

- Run one existing deterministic collection-age query and verify no confidence notice or review item.
- Run one ordinary exploratory listing and verify it executes with a small AI-assisted notice.
- Run the physical ROI prompt and verify `mode=exploratory`, compiler `physical_roi_v2`, a plain-language limitation, and a review item whenever the classifier emits at least one review reason.
- Trigger a material repair and verify the final report runs, `material_repair` is recorded, and the administrator sees both structural signatures.
- Trigger a blocking semantic gap and verify no SQL/results reach the user, the request is preserved, and the administrator receives technical evidence.
- Submit exact instance HRIDs and requested fields and verify every value is preserved.
- Edit generated SQL before execution and verify a `user_edited_sql` child generation is linked and reviewed.
- Claim the same review from two administrator sessions and verify only one succeeds.
- Caution and supersede completed reports and verify `query_jobs.status` remains `completed`.
- Delete a history job and verify linked generation/review data is removed.
