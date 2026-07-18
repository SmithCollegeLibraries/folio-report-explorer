# Ask AI Semantic Conformance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent Ask AI from returning exploratory results when the generated SQL contradicts detected requirements, displayed assumptions, or safe analytical grain rules.

**Architecture:** Build a deterministic, versioned semantic contract from the question, campus scope, and documented assumptions. Analyze every exploratory SQL candidate without executing it, run composable conformance rules before PostgreSQL preflight, feed safe violations through the existing two-repair coordinator, and expose only checked requirement labels or plain-language recovery to the browser.

**Tech Stack:** PHP 7.2-compatible Yii services and standalone PHP regression scripts; PostgreSQL SQL structural analysis; React 18, TypeScript 5.6, Vitest, and Testing Library.

## Global Constraints

- Do not weaken SELECT-only safety, reporting policy, PII protection, or cancellation behavior.
- Keep one initial generation plus at most two automatic repairs across static validation, semantic validation, and database preflight.
- Do not edit existing schema caches, table-mapping caches, canonical query-family contracts, or Builder relationship artifacts.
- Do not add a SQL parser dependency.
- Do not return or log candidate SQL, database messages, schema details, prompt text, or exception messages in semantic recovery or telemetry.
- Canonical verified report families must bypass exploratory semantic conformance.
- Every detected blocking requirement must have deterministic validator coverage and pass before results can be shown.
- Normal users cannot bypass failed blocking semantic requirements.
- All new PHP must remain compatible with the repository's `php >=7.2.0` floor: no typed properties, arrow functions, union types, or PHP 8-only syntax.

---

### Task 1: Add structured, privacy-safe semantic violations to the repair contract

**Files:**
- Modify: `backend/exceptions/ExploratorySqlValidationException.php`
- Modify: `backend/services/ExploratorySqlRepairService.php`
- Modify: `backend/tests/ExploratorySqlRepairServiceTest.php`

**Interfaces:**
- Consumes: the existing exception constructor fields `stage`, `safeCategory`, `candidateSql`, `repairable`, and `internalMessage`.
- Produces: optional `safeViolations` entries shaped as `array{key:string,category:string,label:string,guidance:string}`; `ExploratorySqlValidationException::getSafeViolations(): array`; repair attempt context field `safeViolations`; exhausted outcome field `unmetRequirements`.

- [ ] **Step 1: Write failing exception and coordinator tests**

Add a semantic exception case to `ExploratorySqlRepairServiceTest.php`:

```php
$semanticViolations = [[
    'key' => 'purchase_date_basis',
    'category' => 'assumption_mismatch',
    'label' => 'Purchases use payment date for the last five years.',
    'guidance' => 'Filter the approved invoice payment-date column with the requested five-year window.',
]];
$semanticContexts = [];
$semanticCalls = 0;
$semanticOutcome = ExploratorySqlRepairService::run(
    function (array $context) use (&$semanticContexts, &$semanticCalls, $semanticViolations): array {
        $semanticContexts[] = $context;
        $semanticCalls++;
        throw new ExploratorySqlValidationException(
            'semantic_conformance',
            'assumption_mismatch',
            'SELECT private_candidate_' . $semanticCalls,
            true,
            'raw semantic evidence must stay private',
            null,
            $semanticViolations
        );
    },
    ['originalQuestion' => 'Compare purchases and circulation ROI']
);

assertSameValue(3, $semanticCalls, 'Semantic failures should share the initial-plus-two-repair budget.');
assertSameValue($semanticViolations, $semanticContexts[1]['safeViolations'], 'Repairs should receive only safe semantic violations.');
assertSameValue(
    [['key' => 'purchase_date_basis', 'label' => 'Purchases use payment date for the last five years.']],
    $semanticOutcome['unmetRequirements'],
    'Recovery should contain stable keys and user-readable labels only.'
);
assertFalseValue(
    strpos(json_encode($semanticOutcome), 'private_candidate_') !== false,
    'Recovery must not expose rejected SQL.'
);
assertFalseValue(
    strpos(json_encode($semanticOutcome), 'raw semantic evidence') !== false,
    'Recovery must not expose internal evidence.'
);
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
php backend/tests/ExploratorySqlRepairServiceTest.php
```

Expected: FAIL because the exception constructor does not accept safe violations and the repair coordinator does not propagate them.

- [ ] **Step 3: Extend the exception with normalized safe violations**

Add an untyped property, an optional final constructor parameter, normalization, and a getter:

```php
/** @var array */
private $safeViolations;

public function __construct(
    string $stage,
    string $safeCategory,
    string $candidateSql,
    bool $repairable,
    string $internalMessage,
    ?\Throwable $previous = null,
    array $safeViolations = []
) {
    parent::__construct($internalMessage, 0, $previous);
    $this->stage = $stage;
    $this->safeCategory = $safeCategory;
    $this->candidateSql = $candidateSql;
    $this->repairable = $repairable;
    $this->safeViolations = self::normalizeSafeViolations($safeViolations);
}

public function getSafeViolations(): array
{
    return $this->safeViolations;
}

private static function normalizeSafeViolations(array $violations): array
{
    $safe = [];
    foreach ($violations as $violation) {
        if (!is_array($violation)) continue;
        $key = trim((string)($violation['key'] ?? ''));
        $category = trim((string)($violation['category'] ?? ''));
        $label = trim((string)($violation['label'] ?? ''));
        $guidance = trim((string)($violation['guidance'] ?? ''));
        if (preg_match('/^[a-z0-9_]{1,80}$/', $key) !== 1
            || preg_match('/^[a-z0-9_]{1,80}$/', $category) !== 1
            || $label === '' || $guidance === '') {
            continue;
        }
        $safe[] = compact('key', 'category', 'label', 'guidance');
    }
    return $safe;
}
```

- [ ] **Step 4: Propagate safe violations without exposing internal details**

In `ExploratorySqlRepairService`, retain `safeViolations` in sanitized context, add the latest exception violations in `withFailureContext()`, and add deduplicated unmet labels to the exhausted outcome:

```php
'safeViolations' => is_array($context['safeViolations'] ?? null)
    ? $context['safeViolations']
    : [],
```

```php
'safeViolations' => $failure === null ? [] : $failure->getSafeViolations(),
```

```php
$unmetRequirements = [];
foreach ($failure->getSafeViolations() as $violation) {
    $unmetRequirements[$violation['key']] = [
        'key' => $violation['key'],
        'label' => $violation['label'],
    ];
}

return [
    'status' => 'exhausted',
    'repairAttempts' => $repairAttempts,
    'validatorStage' => $failure->getStage(),
    'failureCategory' => $failure->getSafeCategory(),
    'unmetRequirements' => array_values($unmetRequirements),
    'suggestions' => self::recoverySuggestions($failure->getSafeViolations()),
];
```

Implement `recoverySuggestions()` to return up to three unique violation guidance strings, falling back to the current Retry/Correct/Narrow suggestions when no semantic guidance exists.

- [ ] **Step 5: Run the test and verify GREEN**

Run:

```bash
php backend/tests/ExploratorySqlRepairServiceTest.php
```

Expected: PASS, including existing hard-stop and shared-budget cases.

- [ ] **Step 6: Commit the structured repair contract**

```bash
git add backend/exceptions/ExploratorySqlValidationException.php backend/services/ExploratorySqlRepairService.php backend/tests/ExploratorySqlRepairServiceTest.php
git commit -m "feat: carry safe semantic repair violations"
```

---

### Task 2: Build the deterministic exploratory semantic contract

**Files:**
- Create: `backend/services/ExploratorySemanticContractService.php`
- Create: `backend/tests/ExploratorySemanticContractServiceTest.php`
- Read only: `backend/data/exploratory_query_defaults.json`

**Interfaces:**
- Consumes: `build(string $question, ?string $campus, array $assumptions, string $routeReason): array` inputs from exploratory generation.
- Produces: `contractVersion`, `applicable`, `concept`, `requirements`, `permittedFilters`, and `coverageStatus`; each requirement is `array{key:string,rule:string,label:string,parameters:array}`.

- [ ] **Step 1: Write the failing contract tests**

Create a standalone test covering the motivating wording, explicit assumption correction, selected campus, unrequested filters, non-ROI prompts, and missing coverage:

```php
<?php
require_once __DIR__ . '/../services/ExploratorySemanticContractService.php';
require_once __DIR__ . '/../services/ExploratoryQueryDefaultsService.php';

use app\services\ExploratoryQueryDefaultsService;
use app\services\ExploratorySemanticContractService;

function contractAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$question = 'Show me which call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.';
$assumptions = ExploratoryQueryDefaultsService::resolve($question);
$contract = ExploratorySemanticContractService::build($question, 'Smith College', $assumptions, 'unsupported_query_family');

contractAssertSame(1, $contract['contractVersion'], 'The contract must be versioned.');
contractAssertSame(true, $contract['applicable'], 'Cross-domain call-number ROI must receive semantic protection.');
contractAssertSame('cross_domain_call_number_roi', $contract['concept'], 'The motivating request must use the ROI contract.');
contractAssertSame('complete', $contract['coverageStatus'], 'Every ROI requirement must have a registered rule.');
contractAssertSame([
    'purchase_date_basis', 'investment_cost_basis', 'spend_grain', 'circulation_window',
    'circulation_grain', 'call_number_grouping', 'required_measures', 'roi_formula',
    'purchase_ranking', 'campus_scope', 'governed_filters', 'numeric_output_types',
], array_column($contract['requirements'], 'key'), 'ROI requirements must be stable and ordered.');
contractAssertSame('Smith College', $contract['permittedFilters']['campus']['value'], 'Selected campus must be required and permitted.');
contractAssertSame(false, isset($contract['permittedFilters']['material_type']), 'Material type must not be silently permitted.');
contractAssertSame(false, isset($contract['permittedFilters']['acquisition_unit']), 'Acquisition unit must not be silently permitted.');

$invoiceQuestion = $question . ' Use invoice date.';
$invoiceContract = ExploratorySemanticContractService::build(
    $invoiceQuestion,
    'Smith College',
    ExploratoryQueryDefaultsService::resolve($invoiceQuestion),
    'unsupported_query_family'
);
contractAssertSame(
    'invoice_date',
    $invoiceContract['requirements'][0]['parameters']['value'],
    'An explicit correction must replace the default date basis.'
);

$simple = ExploratorySemanticContractService::build('List item barcodes', null, [], 'unsupported_query_family');
contractAssertSame(false, $simple['applicable'], 'An unrelated simple request must not receive an ROI checklist.');

fwrite(STDOUT, "Exploratory semantic contract service test passed\n");
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
php backend/tests/ExploratorySemanticContractServiceTest.php
```

Expected: FAIL because the service does not exist.

- [ ] **Step 3: Implement the versioned ROI contract and rule coverage guard**

Create `ExploratorySemanticContractService` with these public and private interfaces:

```php
class ExploratorySemanticContractService
{
    private const CONTRACT_VERSION = 1;
    private const ROI_RULES = [
        'purchase_date_basis' => 'purchase_date_basis',
        'investment_cost_basis' => 'investment_cost_basis',
        'spend_grain' => 'spend_before_item_join',
        'circulation_window' => 'circulation_window',
        'circulation_grain' => 'circulation_item_grain',
        'call_number_grouping' => 'call_number_grouping',
        'required_measures' => 'required_output_measures',
        'roi_formula' => 'roi_formula',
        'purchase_ranking' => 'descending_purchase_ranking',
        'campus_scope' => 'campus_scope',
        'governed_filters' => 'governed_filters',
        'numeric_output_types' => 'numeric_output_types',
    ];

    public static function build(
        string $question,
        ?string $campus,
        array $assumptions,
        string $routeReason
    ): array;

    private static function isCrossDomainCallNumberRoi(string $question): bool;
    private static function assumptionValues(array $assumptions): array;
    private static function requirement(string $key, string $label, array $parameters = []): array;
    private static function permittedFilters(string $question, ?string $campus): array;
}
```

Return `applicable=false`, an empty requirement list, and `coverageStatus=not_applicable` for unrelated prompts. For ROI prompts, require the exact stable key order in the test. Derive date basis, cost basis, circulation window, grouping, and formula from assumption values; require campus only when separately supplied. Recognize material-type or acquisition-unit filter permission only from explicit phrases in the question, never from SQL. Set each permission's provenance to `explicit_prompt` or `selected_scope`.

Before returning an applicable contract, compare every requirement's `rule` against `ExploratorySqlSemanticValidatorService::supportedRuleKeys()` once Task 4 supplies it. Until Task 4, keep the registry as a private constant matching `ROI_RULES`; Task 4 replaces the local comparison with the validator registry. When a rule is unregistered, retain the original blocking requirement, set `coverageStatus=gap`, and list its key in `uncoveredRequirementKeys`. Do not manufacture a replacement requirement or silently drop the uncovered one.

- [ ] **Step 4: Run contract and defaults tests and verify GREEN**

Run:

```bash
php backend/tests/ExploratorySemanticContractServiceTest.php
php backend/tests/ExploratoryQueryDefaultsServiceTest.php
```

Expected: both scripts pass; the existing five documented defaults remain unchanged.

- [ ] **Step 5: Commit the contract builder**

```bash
git add backend/services/ExploratorySemanticContractService.php backend/tests/ExploratorySemanticContractServiceTest.php
git commit -m "feat: build exploratory semantic contracts"
```

---

### Task 3: Add conservative structural analysis for exploratory SQL

**Files:**
- Modify: `backend/services/SqlSelectStructureService.php`
- Create: `backend/services/ExploratorySqlAnalysisService.php`
- Create: `backend/tests/ExploratorySqlAnalysisServiceTest.php`
- Modify: `backend/tests/SqlSelectStructureServiceTest.php`

**Interfaces:**
- Consumes: one already safety-approved SELECT/WITH SQL string.
- Produces: `ExploratorySqlAnalysisService::analyze(string $sql): array` with `ctes`, `tables`, `selectItems`, `predicates`, `groupBy`, `orderBy`, `limit`, `formattedAliases`, and `ambiguous`; it performs no database access.
- Produces: `SqlSelectStructureService::tokenizeForAnalysis(string $sql): array`, returning comment-free tokens with `kind`, `value`, and `depth`.

- [ ] **Step 1: Write tokenizer exposure and analyzer tests**

Create fixtures for the captured flawed SQL and corrected SQL. Assert the analyzer:

```php
$analysis = ExploratorySqlAnalysisService::analyze($correctedSql);
analysisAssertSame(
    ['spend_by_instance', 'circulation_by_item', 'circulation_by_instance', 'class_by_instance'],
    array_keys($analysis['ctes']),
    'CTEs must retain dependency order.'
);
analysisAssertSame(
    ['invoice.invoice_lines__t', 'invoice.invoice_lines__t__fund_distributions', 'invoice.invoices__t', 'orders.po_line__t'],
    $analysis['ctes']['spend_by_instance']['tables'],
    'Spend CTE table references must be inspectable.'
);
analysisAssertSame('purchase_count', $analysis['orderBy'][0]['expression'], 'Final ranking alias must be captured.');
analysisAssertSame('DESC', $analysis['orderBy'][0]['direction'], 'Final ranking direction must be captured.');
analysisAssertSame(false, $analysis['ambiguous'], 'Supported CTE SQL must analyze deterministically.');

$flawed = ExploratorySqlAnalysisService::analyze($capturedProductionSql);
analysisAssertSame(true, in_array('total_spent', $flawed['formattedAliases'], true), 'TO_CHAR spending must be marked text-formatted.');
analysisAssertSame(true, in_array('cost_per_checkout', $flawed['formattedAliases'], true), 'TO_CHAR cost per checkout must be marked text-formatted.');
analysisAssertSame('pot.date_ordered', $flawed['predicates']['dateColumns'][0], 'Order-date filtering must not be mistaken for payment date.');
```

Also add tokenizer assertions to `SqlSelectStructureServiceTest.php` proving that `SELECT`, `DO` inside a string, quoted identifiers, nested depths, line comments, and block comments are distinguished.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```bash
php backend/tests/SqlSelectStructureServiceTest.php
php backend/tests/ExploratorySqlAnalysisServiceTest.php
```

Expected: FAIL because neither public analysis tokenizer nor exploratory analyzer exists.

- [ ] **Step 3: Expose a read-only analysis token stream from the existing tokenizer**

Add this public method without changing canonical analysis behavior:

```php
public static function tokenizeForAnalysis(string $sql): array
{
    $tokens = self::tokenize($sql);
    $depths = self::depths($tokens);
    foreach ($tokens as $index => &$token) {
        $token['depth'] = $depths[$index] ?? 0;
    }
    unset($token);
    return $tokens;
}
```

Keep `tokenize()` private so callers cannot bypass the stable public shape.

- [ ] **Step 4: Implement focused clause and CTE analysis**

Implement `ExploratorySqlAnalysisService` with these interfaces:

```php
class ExploratorySqlAnalysisService
{
    public static function analyze(string $sql): array;
    private static function parseCtes(array $tokens): array;
    private static function analyzeSelectScope(array $tokens, array $knownCtes): array;
    private static function splitTopLevel(array $tokens, string $separator): array;
    private static function clauseSlice(array $tokens, string $start, array $ends): array;
    private static function expressionText(array $tokens): string;
    private static function outputAlias(array $tokens): ?string;
    private static function referencedAliases(array $tokens): array;
    private static function datePredicateColumns(array $tokens): array;
    private static function governedFilters(array $tokens): array;
}
```

The returned top-level shape must be:

```php
[
    'ctes' => $ctes,
    'tables' => $finalScope['tables'],
    'selectItems' => $finalScope['selectItems'],
    'predicates' => $finalScope['predicates'],
    'groupBy' => $finalScope['groupBy'],
    'orderBy' => $finalScope['orderBy'],
    'limit' => $finalScope['limit'],
    'formattedAliases' => $finalScope['formattedAliases'],
    'ambiguous' => $ambiguous,
]
```

Each CTE contains `tables`, `dependencies`, `selectItems`, `predicates`, `groupBy`, and `joins`. Use token kinds rather than raw regex when determining clauses, aliases, functions, and identifiers. Mark `ambiguous=true` for set operations, recursive CTEs, lateral/function table sources, unbalanced structures, duplicate output aliases, or clauses the analyzer cannot divide reliably. Strings and comments must never contribute keywords or table/filter evidence.

- [ ] **Step 5: Run analyzer, structure, and safety regressions and verify GREEN**

Run:

```bash
php backend/tests/ExploratorySqlAnalysisServiceTest.php
php backend/tests/SqlSelectStructureServiceTest.php
php backend/tests/SqlBuilderServicePolicyViolationTest.php
```

Expected: all scripts pass; canonical structural analysis behavior is unchanged.

- [ ] **Step 6: Commit the analyzer**

```bash
git add backend/services/SqlSelectStructureService.php backend/services/ExploratorySqlAnalysisService.php backend/tests/SqlSelectStructureServiceTest.php backend/tests/ExploratorySqlAnalysisServiceTest.php
git commit -m "feat: analyze exploratory SQL structure"
```

---

### Task 4: Enforce composable semantic conformance rules

**Files:**
- Create: `backend/services/ExploratorySqlSemanticValidatorService.php`
- Create: `backend/tests/ExploratorySqlSemanticValidatorServiceTest.php`
- Modify: `backend/services/ExploratorySemanticContractService.php`
- Modify: `backend/tests/ExploratorySemanticContractServiceTest.php`

**Interfaces:**
- Consumes: `validate(string $sql, array $contract): array` and analyzer output from Task 3.
- Produces: `array{status:string,contractVersion:int,checkedRequirements:array,violations:array}`; `supportedRuleKeys(): array` is the single rule-coverage registry.

- [ ] **Step 1: Write rule-by-rule failing tests using captured production SQL**

Create tests with one valid fixture and mutations for each defect. Assert:

```php
$valid = ExploratorySqlSemanticValidatorService::validate($correctedSql, $contract);
semanticAssertSame('validated', $valid['status'], 'Corrected ROI SQL must pass every rule.');
semanticAssertSame(
    array_column($contract['requirements'], 'key'),
    array_column($valid['checkedRequirements'], 'key'),
    'Every contract requirement must be checked before validation.'
);

$captured = ExploratorySqlSemanticValidatorService::validate($capturedProductionSql, $contract);
semanticAssertSame('rejected', $captured['status'], 'Captured flawed production SQL must be blocked.');
semanticAssertContainsAll([
    'purchase_date_basis', 'spend_grain', 'purchase_ranking',
    'governed_filters', 'numeric_output_types',
], array_column($captured['violations'], 'key'), 'Known production defects must be detected together.');
```

Add focused fixtures proving:

- invoice payment-date versus PO order-date and invoice-date assumption variants;
- paid fund-distribution amount multiplied by percentage exactly once;
- spend aggregation occurs before holdings/item/audit-loan dependencies;
- checkout action and matching date window occur in item-grain circulation CTE;
- call-number class is the final grouping dimension;
- required numeric aliases exist and `TO_CHAR`/concatenation/currency strings are rejected;
- checkouts-per-dollar and cost-per-checkout use `NULLIF(..., 0)` or an equivalent zero-safe `CASE`;
- `ORDER BY purchase_count DESC` occurs before `LIMIT`;
- selected campus is enforced when supplied;
- material type and acquisition unit are rejected unless permitted by contract provenance;
- `ambiguous=true` produces `semantic_coverage_gap`, not a pass.

- [ ] **Step 2: Run the validator test and verify RED**

Run:

```bash
php backend/tests/ExploratorySqlSemanticValidatorServiceTest.php
```

Expected: FAIL because the validator does not exist.

- [ ] **Step 3: Implement the stable rule registry and validation result**

Create the service with a registry whose keys exactly match contract `rule` values:

```php
private const RULE_METHODS = [
    'purchase_date_basis' => 'validatePurchaseDateBasis',
    'investment_cost_basis' => 'validateInvestmentCostBasis',
    'spend_before_item_join' => 'validateSpendBeforeItemJoin',
    'circulation_window' => 'validateCirculationWindow',
    'circulation_item_grain' => 'validateCirculationItemGrain',
    'call_number_grouping' => 'validateCallNumberGrouping',
    'required_output_measures' => 'validateRequiredOutputMeasures',
    'roi_formula' => 'validateRoiFormula',
    'descending_purchase_ranking' => 'validateDescendingPurchaseRanking',
    'campus_scope' => 'validateCampusScope',
    'governed_filters' => 'validateGovernedFilters',
    'numeric_output_types' => 'validateNumericOutputTypes',
];

public static function supportedRuleKeys(): array
{
    return array_keys(self::RULE_METHODS);
}

public static function validate(string $sql, array $contract): array
{
    if (empty($contract['applicable'])) {
        return ['status' => 'not_applicable', 'contractVersion' => (int)$contract['contractVersion'], 'checkedRequirements' => [], 'violations' => []];
    }
    $analysis = ExploratorySqlAnalysisService::analyze($sql);
    $checked = [];
    $violations = [];
    foreach ($contract['requirements'] as $requirement) {
        $rule = (string)$requirement['rule'];
        if (!isset(self::RULE_METHODS[$rule]) || !empty($analysis['ambiguous'])) {
            $violations[] = self::violation($requirement, 'semantic_coverage_gap', 'Use a simpler report shape so every requested requirement can be verified.');
            continue;
        }
        $method = self::RULE_METHODS[$rule];
        $guidance = call_user_func([self::class, $method], $analysis, $requirement, $contract);
        if ($guidance === null) {
            $checked[] = ['key' => $requirement['key'], 'label' => $requirement['label']];
        } else {
            $violations[] = self::violation($requirement, self::categoryFor($requirement['key']), $guidance);
        }
    }
    return [
        'status' => $violations === [] ? 'validated' : 'rejected',
        'contractVersion' => (int)$contract['contractVersion'],
        'checkedRequirements' => $violations === [] ? $checked : [],
        'violations' => $violations,
    ];
}
```

Each private rule returns `null` only on proven conformance and otherwise returns fixed safe guidance. `categoryFor()` maps to only `assumption_mismatch`, `grain_mismatch`, `missing_ordering`, `unrequested_filter`, `output_type_mismatch`, or `semantic_coverage_gap`. Do not include expressions, aliases, values, or raw evidence in violation payloads.

- [ ] **Step 4: Replace the contract builder's temporary registry with the validator registry**

Require the validator service and compare every rule against:

```php
$supported = array_flip(ExploratorySqlSemanticValidatorService::supportedRuleKeys());
```

If any applicable requirement lacks coverage, set `coverageStatus=gap`, retain it in `requirements`, and list it in `uncoveredRequirementKeys`; do not silently remove it. Extend the contract test by calling a public `auditCoverage(array $requirements, array $supportedRuleKeys): array` value helper with one deliberately unsupported rule and assert `coverageStatus=gap` plus the original requirement key. Extend the validator test to pass that audited contract and assert a `semantic_coverage_gap` violation before any rule method runs.

- [ ] **Step 5: Run contract, analyzer, and validator tests and verify GREEN**

Run:

```bash
php backend/tests/ExploratorySemanticContractServiceTest.php
php backend/tests/ExploratorySqlAnalysisServiceTest.php
php backend/tests/ExploratorySqlSemanticValidatorServiceTest.php
```

Expected: all scripts pass, including all five captured production defects and fail-closed ambiguity.

- [ ] **Step 6: Commit the rule engine**

```bash
git add backend/services/ExploratorySemanticContractService.php backend/services/ExploratorySqlSemanticValidatorService.php backend/tests/ExploratorySemanticContractServiceTest.php backend/tests/ExploratorySqlSemanticValidatorServiceTest.php
git commit -m "feat: enforce exploratory semantic rules"
```

---

### Task 5: Insert semantic conformance into every exploratory generation and repair attempt

**Files:**
- Modify: `backend/services/GeminiService.php`
- Modify: `backend/tests/GeminiServiceExploratoryRepairTest.php`
- Modify: `backend/tests/AskAiCrossDomainRoiRegressionTest.php`

**Interfaces:**
- Consumes: Task 2 contract builder, Task 4 validator, existing `runExploratorySqlAttempt()`, and existing two-repair coordinator.
- Produces: validated exploratory results with `semanticValidation`; semantic failures as repairable `ExploratorySqlValidationException(stage=semantic_conformance)`; safe semantic repair guidance in the model request.

- [ ] **Step 1: Write failing initial-generation and post-preflight-repair tests**

Update the ROI regression so the first model response is the exact captured flawed production SQL, the second is the corrected fixture, and assertions require:

```php
roiRegressionAssertSame(1, $repaired['repairAttempts'] ?? null, 'Five semantic defects should trigger one automatic repair.');
roiRegressionAssertSame('validated', $repaired['semanticValidation']['status'] ?? null, 'Returned exploratory SQL must pass semantic conformance.');
roiRegressionAssertSame(1, $repaired['semanticValidation']['contractVersion'] ?? null, 'The response must identify the checked contract version.');
roiRegressionAssertSame(12, count($repaired['semanticValidation']['checkedRequirements'] ?? []), 'Every ROI requirement must be checked.');
roiRegressionAssertContains('purchase_date_basis', json_encode(RoiTestTransport::$requests[1]), 'Repair feedback must identify the unmet date requirement.');
roiRegressionAssertContains('spend_grain', json_encode(RoiTestTransport::$requests[1]), 'Repair feedback must identify the unsafe grain.');
roiRegressionAssertContains('purchase_ranking', json_encode(RoiTestTransport::$requests[1]), 'Repair feedback must identify missing ranking.');
roiRegressionAssertContains('governed_filters', json_encode(RoiTestTransport::$requests[1]), 'Repair feedback must identify unrequested filters.');
roiRegressionAssertContains('numeric_output_types', json_encode(RoiTestTransport::$requests[1]), 'Repair feedback must identify formatted numeric outputs.');
```

Extend `GeminiServiceExploratoryRepairTest.php` so a candidate generated after a simulated preflight failure is also semantically rejected and consumes only the remaining repair budget.

- [ ] **Step 2: Run focused service tests and verify RED**

Run:

```bash
php backend/tests/AskAiCrossDomainRoiRegressionTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Expected: FAIL because flawed SQL currently passes static validation and no semantic response exists.

- [ ] **Step 3: Build the contract once and validate inside the shared attempt wrapper**

Add service includes and build the contract in both `generateExploratorySqlResponse()` and `repairExploratorySqlAfterPreflight()`:

```php
$semanticContract = ExploratorySemanticContractService::build(
    $prompt,
    is_string($campus) ? $campus : null,
    $assumptions,
    $reason
);
$context['semanticContract'] = $semanticContract;
```

Retain `semanticContract` in `ExploratorySqlRepairService::sanitizeContext()`. In `runExploratorySqlAttempt()`, validate immediately after `$attempt()` returns and before logging it as validated:

```php
$result = $attempt();
$contract = is_array($context['semanticContract'] ?? null) ? $context['semanticContract'] : [];
$semanticValidation = ExploratorySqlSemanticValidatorService::validate((string)($result['sql'] ?? ''), $contract);
if (($semanticValidation['status'] ?? null) === 'rejected') {
    $violations = $semanticValidation['violations'] ?? [];
    throw new ExploratorySqlValidationException(
        'semantic_conformance',
        (string)($violations[0]['category'] ?? 'semantic_coverage_gap'),
        (string)($result['sql'] ?? ''),
        true,
        'The exploratory SQL candidate did not satisfy its semantic contract.',
        null,
        $violations
    );
}
if (($semanticValidation['status'] ?? null) === 'validated') {
    $result['semanticValidation'] = $semanticValidation;
}
```

Unrelated exploratory prompts receive `not_applicable` and retain current behavior without displaying a false checklist. Canonical generation never calls this wrapper and remains unchanged.

- [ ] **Step 4: Add safe semantic feedback to repair prompts and telemetry**

Add a fixed-format repair section:

```php
$semanticGuidance = [];
foreach (($context['safeViolations'] ?? []) as $violation) {
    $semanticGuidance[] = sprintf(
        '- %s: %s',
        (string)$violation['key'],
        (string)$violation['guidance']
    );
}
```

Include `SEMANTIC REQUIREMENTS TO CORRECT` with those lines, or `None supplied.`. Telemetry may include sorted `ruleKeys`, contract version, failure count, repair number, and terminal outcome only. Remove or avoid prompt text, SQL fragments, values, evidence, and exception messages.

- [ ] **Step 5: Return checked and unmet requirements in response contracts**

Keep `semanticValidation` on validated results. In `buildExploratoryRecoveryResponse()`, add:

```php
'unmetRequirements' => is_array($outcome['unmetRequirements'] ?? null)
    ? $outcome['unmetRequirements']
    : [],
```

Use this message for semantic exhaustion:

```php
'message' => ($outcome['validatorStage'] ?? null) === 'semantic_conformance'
    ? "I couldn't produce a report that matched every checked requirement. Nothing ran or changed. Your request is preserved so you can retry or adjust an assumption."
    : 'I could not produce SQL that passed validation within the automatic repair limit.',
```

Never add rejected SQL to recovery output.

- [ ] **Step 6: Run focused generation and repair tests and verify GREEN**

Run:

```bash
php backend/tests/AskAiCrossDomainRoiRegressionTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
php backend/tests/GeminiServiceExploratoryGateTest.php
php backend/tests/ExploratorySqlRepairServiceTest.php
```

Expected: all scripts pass; the captured SQL is repaired, the corrected fixture is returned, and exhaustion makes exactly three model calls with no SQL leak.

- [ ] **Step 7: Commit pipeline integration**

```bash
git add backend/services/GeminiService.php backend/services/ExploratorySqlRepairService.php backend/tests/GeminiServiceExploratoryRepairTest.php backend/tests/AskAiCrossDomainRoiRegressionTest.php
git commit -m "feat: gate exploratory SQL on semantic conformance"
```

---

### Task 6: Guarantee semantic validation precedes controller database preflight

**Files:**
- Modify: `backend/controllers/FolioQueryController.php`
- Modify: `backend/tests/FolioQueryControllerExploratoryRepairTest.php`

**Interfaces:**
- Consumes: exploratory `semanticValidation` and existing preflight/repair callbacks.
- Produces: controller hard stop for applicable exploratory results lacking a validated semantic contract; canonical and non-applicable exploratory behavior remains compatible.

- [ ] **Step 1: Write failing preflight-order tests**

Add controller cases asserting:

```php
$semanticPreflightCalls = 0;
$missingSemantic = $validateAndRepair->invoke(
    $controller,
    [
        'sql' => 'SELECT purchase_count FROM purchase_data ORDER BY purchase_count DESC',
        'mode' => 'exploratory',
        'route' => 'exploratory_legacy_freeform',
        'semanticContractApplicable' => true,
        'repairAttempts' => 2,
    ],
    $roiQuestion,
    'Smith College',
    function () use (&$semanticPreflightCalls): array {
        $semanticPreflightCalls++;
        return ['rows' => 1];
    }
);
repairAssertSame(0, $semanticPreflightCalls, 'Applicable exploratory SQL without semantic validation must never reach preflight.');
repairAssertSame(false, isset($missingSemantic['sql']), 'Unverified SQL must not be returned.');

$validatedPreflightCalls = 0;
$validatedResult = $validateAndRepair->invoke(
    $controller,
    [
        'sql' => 'SELECT purchase_count FROM purchase_data ORDER BY purchase_count DESC',
        'mode' => 'exploratory',
        'route' => 'exploratory_legacy_freeform',
        'semanticContractApplicable' => true,
        'semanticValidation' => ['status' => 'validated', 'contractVersion' => 1, 'checkedRequirements' => [['key' => 'purchase_ranking', 'label' => 'Results are ranked by purchases.']]],
        'repairAttempts' => 0,
    ],
    $roiQuestion,
    'Smith College',
    function () use (&$validatedPreflightCalls): array {
        $validatedPreflightCalls++;
        return ['rows' => 1];
    }
);
repairAssertSame(1, $validatedPreflightCalls, 'Semantically validated SQL should reach database preflight.');
```

Retain existing valid simple SELECT, destructive SQL, canonical, connectivity, policy, cancellation, and shared repair-budget cases.

- [ ] **Step 2: Run the controller test and verify RED**

Run:

```bash
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
```

Expected: FAIL because the controller currently preflights any statically safe exploratory SQL.

- [ ] **Step 3: Mark applicability and enforce the boundary**

In Task 5, decorate exploratory responses with:

```php
$result['semanticContractApplicable'] = !empty($semanticContract['applicable']);
```

Before controller preflight, add:

```php
if (!empty($result['semanticContractApplicable'])
    && (($result['semanticValidation']['status'] ?? null) !== 'validated')
) {
    return $this->buildExploratoryRepairExhaustedResponse(
        $result,
        $prompt,
        $campus,
        'semantic_coverage_gap'
    );
}
```

Run this check after shared SELECT safety and before invoking the preflight callback. Ensure controller-side `array_replace()` after a preflight repair uses the repair result's fresh `semanticValidation`; never reuse an earlier candidate's checklist for changed SQL.

- [ ] **Step 4: Run controller and end-to-end Ask regressions and verify GREEN**

Run:

```bash
php backend/tests/FolioQueryControllerExploratoryRepairTest.php
php backend/tests/FolioQueryControllerPolicyViolationStatusTest.php
php backend/tests/AskAiCrossDomainRoiRegressionTest.php
```

Expected: all scripts pass; preflight is called only after semantic validation and unsafe SQL remains a zero-repair hard stop.

- [ ] **Step 5: Commit the controller boundary**

```bash
git add backend/controllers/FolioQueryController.php backend/services/GeminiService.php backend/tests/FolioQueryControllerExploratoryRepairTest.php
git commit -m "feat: require semantics before Ask preflight"
```

---

### Task 7: Show checked requirements and plain-language semantic recovery

**Files:**
- Create: `frontend/src/components/ExploratorySemanticValidationPanel.tsx`
- Create: `frontend/src/components/ExploratorySemanticValidationPanel.test.tsx`
- Modify: `frontend/src/components/ExploratoryRecoveryPanel.tsx`
- Modify: `frontend/src/components/ExploratoryRecoveryPanel.test.tsx`
- Modify: `frontend/src/pages/Ask.tsx`
- Modify: `frontend/src/types/schema.ts`

**Interfaces:**
- Consumes: `NlResponse.semanticValidation` and `NlResponse.unmetRequirements`.
- Produces: “Validated against your request” checklist for proven requirements and business-language recovery with no SQL/Run controls.

- [ ] **Step 1: Add failing component and recovery tests**

Define a validated payload and assert:

```tsx
render(<ExploratorySemanticValidationPanel validation={{
  status: 'validated',
  contractVersion: 1,
  checkedRequirements: [
    { key: 'purchase_date_basis', label: 'Purchases use payment date for the last five years.' },
    { key: 'spend_grain', label: 'Spending is aggregated before item-level circulation is joined.' },
  ],
}} />);

expect(screen.getByRole('heading', { name: 'Validated against your request' })).toBeInTheDocument();
expect(screen.getByText('Purchases use payment date for the last five years.')).toBeInTheDocument();
expect(screen.getByText('Spending is aggregated before item-level circulation is joined.')).toBeInTheDocument();
expect(screen.queryByText(/SQL fragment|validator stage|evidence/i)).not.toBeInTheDocument();
```

Extend recovery tests with `unmetRequirements` and assert the labels appear under “What still needs to be resolved,” the raw `failureCategory` is hidden for semantic conformance, Retry/Refine work, and no SQL or Run control is present.

- [ ] **Step 2: Run focused frontend tests and verify RED**

Run:

```bash
cd frontend && npm test -- ExploratorySemanticValidationPanel.test.tsx ExploratoryRecoveryPanel.test.tsx
```

Expected: FAIL because the types and component do not exist.

- [ ] **Step 3: Add exact TypeScript response types**

Add:

```ts
export interface SemanticRequirementLabel {
  key: string;
  label: string;
}

export interface SemanticValidation {
  status: 'validated';
  contractVersion: number;
  checkedRequirements: SemanticRequirementLabel[];
}
```

Extend `NlResponse`:

```ts
semanticContractApplicable?: boolean;
semanticValidation?: SemanticValidation;
unmetRequirements?: SemanticRequirementLabel[];
```

Do not type or render internal rule guidance, evidence, candidate SQL, or raw exceptions.

- [ ] **Step 4: Implement the validated checklist component**

Render nothing unless `status === 'validated'` and at least one checked requirement exists. Otherwise render a compact green-bordered section, heading, and accessible list:

```tsx
export function ExploratorySemanticValidationPanel({ validation }: Props) {
  if (validation.status !== 'validated' || validation.checkedRequirements.length === 0) return null;
  return (
    <section className="rounded-lg border border-green-200 bg-green-50 p-4" aria-labelledby="semantic-validation-title">
      <h3 id="semantic-validation-title" className="text-sm font-semibold text-green-950">
        Validated against your request
      </h3>
      <ul className="mt-2 space-y-1 text-sm text-green-900">
        {validation.checkedRequirements.map((requirement) => (
          <li key={requirement.key} className="flex gap-2">
            <span aria-hidden="true">✓</span><span>{requirement.label}</span>
          </li>
        ))}
      </ul>
    </section>
  );
}
```

- [ ] **Step 5: Integrate success and semantic exhaustion presentation**

In `Ask.tsx`, render the checklist after the assumptions panel and before result tabs:

```tsx
{nlResult.mode === 'exploratory' && nlResult.semanticValidation && (
  <ExploratorySemanticValidationPanel validation={nlResult.semanticValidation} />
)}
```

In `ExploratoryRecoveryPanel`, render `unmetRequirements` labels and suppress the machine category when `validatorStage === 'semantic_conformance'`. Use:

```tsx
<h3 className="text-sm font-semibold text-amber-950">What still needs to be resolved</h3>
```

Keep the preserved question, assumptions, deterministic refinement buttons, and Retry action. Do not render SQL or Run controls in the recovery branch.

- [ ] **Step 6: Run focused and adjacent frontend tests and verify GREEN**

Run:

```bash
cd frontend && npm test -- ExploratorySemanticValidationPanel.test.tsx ExploratoryRecoveryPanel.test.tsx Ask.errorFormatting.test.ts Ask.followUp.test.ts
```

Expected: all selected tests pass.

- [ ] **Step 7: Commit the user-facing validation state**

```bash
git add frontend/src/components/ExploratorySemanticValidationPanel.tsx frontend/src/components/ExploratorySemanticValidationPanel.test.tsx frontend/src/components/ExploratoryRecoveryPanel.tsx frontend/src/components/ExploratoryRecoveryPanel.test.tsx frontend/src/pages/Ask.tsx frontend/src/types/schema.ts
git commit -m "feat: show Ask semantic validation results"
```

---

### Task 8: Complete integrated verification and isolation review

**Files:**
- Verify only; production changes are allowed only to correct failures discovered by this task.

**Interfaces:**
- Consumes: all backend and frontend changes from Tasks 1–7.
- Produces: evidence that semantic protection, repair limits, privacy, canonical reporting, Builder behavior, and artifact isolation remain correct.

- [ ] **Step 1: Run PHP lint on every changed PHP file**

Run:

```bash
for php_file in backend/exceptions/ExploratorySqlValidationException.php backend/services/ExploratorySqlRepairService.php backend/services/ExploratorySemanticContractService.php backend/services/ExploratorySqlAnalysisService.php backend/services/ExploratorySqlSemanticValidatorService.php backend/services/SqlSelectStructureService.php backend/services/GeminiService.php backend/controllers/FolioQueryController.php backend/tests/ExploratorySqlRepairServiceTest.php backend/tests/ExploratorySemanticContractServiceTest.php backend/tests/ExploratorySqlAnalysisServiceTest.php backend/tests/ExploratorySqlSemanticValidatorServiceTest.php backend/tests/AskAiCrossDomainRoiRegressionTest.php backend/tests/GeminiServiceExploratoryRepairTest.php backend/tests/FolioQueryControllerExploratoryRepairTest.php; do php -l "$php_file" || exit 1; done
```

Expected: every file reports “No syntax errors detected.”

- [ ] **Step 2: Run the complete Ask AI backend matrix**

Run:

```bash
for test_file in backend/tests/ExploratoryQueryDefaultsServiceTest.php backend/tests/ExploratorySqlRepairServiceTest.php backend/tests/ExploratorySemanticContractServiceTest.php backend/tests/ExploratorySqlAnalysisServiceTest.php backend/tests/ExploratorySqlSemanticValidatorServiceTest.php backend/tests/GeminiServiceExploratoryRepairTest.php backend/tests/GeminiServiceExploratoryGateTest.php backend/tests/GeminiServiceFamilyCompilerFallbackTest.php backend/tests/GeminiServiceFamilyCompilerResultTest.php backend/tests/GeminiServiceFamilyIntentBranchTest.php backend/tests/GeminiServiceFamilyMatchPolicyTest.php backend/tests/GeminiServiceFamilyMismatchTelemetryTest.php backend/tests/GeminiServiceFamilyShapeValidationTest.php backend/tests/GeminiServiceQueryFamilySelectionTest.php backend/tests/GeminiServiceSqlNormalizationTest.php backend/tests/FolioQueryControllerExploratoryRepairTest.php backend/tests/FolioQueryControllerAskContinuationPolicyTest.php backend/tests/FolioQueryControllerNlFollowUpTest.php backend/tests/FolioQueryControllerPolicyViolationStatusTest.php backend/tests/AskAiCrossDomainRoiRegressionTest.php backend/tests/SqlPreflightServiceTest.php backend/tests/FolioQueryControllerExecutePreflightTest.php; do php "$test_file" || exit 1; done
```

Expected: every script exits 0; only already-known runtime deprecation notices may appear.

- [ ] **Step 3: Run canonical report and LDLite Builder regressions**

Run:

```bash
php backend/tests/QueryFamilyCompilerServiceTest.php
php backend/tests/QueryFamilyCompilerSchemaManifestGuardTest.php
php backend/tests/FolioQueryControllerBuilderIdentityTest.php
php backend/tests/FolioQueryControllerCanonicalSaveTest.php
php backend/tests/SqlBuilderServiceLdliteRelationshipTest.php
php backend/tests/SqlBuilderServicePolicyViolationTest.php
```

Expected: all scripts exit 0; canonical routes do not acquire `semanticValidation` and no schema artifacts change.

- [ ] **Step 4: Run the complete frontend suite, lint, and production build**

Run from `frontend/`:

```bash
npm test
npm run lint
npm run build
```

Expected: all tests pass, ESLint exits 0, TypeScript/Vite build exits 0, and only the existing large-chunk advisory is allowed.

- [ ] **Step 5: Verify privacy and repository isolation**

Run:

```bash
git diff --check main..HEAD
git diff --name-only main..HEAD -- backend/data backend/services/QueryFamilyContractService.php backend/services/QueryFamilySchemaManifestService.php
rg -n "candidateSql|previousCandidate|exception->getMessage|raw SQL|database message" frontend/src/components/ExploratorySemanticValidationPanel.tsx frontend/src/components/ExploratoryRecoveryPanel.tsx
```

Expected: diff check exits 0; artifact-isolation command has no output; frontend privacy scan has no matches.

- [ ] **Step 6: Review the complete branch diff against the approved design**

Review `git diff --stat main..HEAD` and `git diff main..HEAD`. Confirm every design requirement maps to a committed task, every detected requirement has rule coverage, repaired candidates rerun semantic validation, PostgreSQL preflight happens afterward, recovery has no SQL, and unrelated changes are absent. Correct every Critical or Important issue and rerun the affected matrix.

- [ ] **Step 7: Commit verification-only corrections if required**

If Step 6 required code corrections, stage only those files and commit:

```bash
git commit -m "fix: close semantic conformance review findings"
```

If no corrections were required, do not create an empty commit.
