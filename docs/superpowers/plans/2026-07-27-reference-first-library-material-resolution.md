# Reference-First Library and Material-Type Resolution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve library scope and physical-video terminology deterministically from the local reference bundle, then reject generated SQL that does not preserve those resolved filters.

**Architecture:** Add a pure typed-intent service that recognizes location-hierarchy context and maps ordinary physical-video language to canonical material-type selectors. Keep canonical values in `reference_cache.json`: `ReferenceResolverService` will match each typed intent only against its authoritative table and return structured `resolvedFilters`. Add a separate SQL validator and pass those filters through deterministic and exploratory generation so every final candidate is checked before database preflight.

**Tech Stack:** PHP 7.2+, Yii2, PostgreSQL/MetaDB SQL, generated JSON reference bundle, standalone PHP regression tests

## Global Constraints

- Generic `video` and `video formats` default to physical video formats without asking a clarification question.
- The `physical_video` group contains exactly `Videocassette`, `DVD/Blu-ray`, and `Film`.
- Explicit format terms narrow the default group.
- Library phrases resolve only against `inventory.loclibrary__t`.
- Material and format phrases resolve only against `inventory.material_type__t`.
- Explicit location phrases retain `inventory.location__t` semantics and consume overlapping material words.
- Canonical names, IDs, codes, and hierarchy metadata come only from the active reference bundle.
- A missing canonical material row produces an unavailable-reference response; no similar row is substituted.
- Cross-campus and wrong-hierarchy candidates fail closed before execution.
- Raw-prompt query-family routing remains separate from resolver-generated guidance.
- Existing clarification memory, safe probes, learned aliases, two-repair maximum, JSON-first loading, and database fallback remain operational.
- PHP 7.2 compatibility is required.
- Generated cache files and unrelated working-tree changes must not be edited or staged.

---

## File Structure

- Create `backend/services/ReferenceIntentService.php`: pure typed-span extraction, dimension selection, and physical-video vocabulary selectors.
- Create `backend/tests/ReferenceIntentServiceTest.php`: vocabulary, overlap, and location-context matrix.
- Modify `backend/services/ReferenceResolverService.php`: table-scoped matching, canonical-row lookup, structured filters, unavailable and ambiguous outcomes.
- Modify `backend/tests/ReferenceResolverServiceTest.php`: pure resolver contract and compatibility cases.
- Modify `backend/tests/ReferenceResolverJsonFirstTest.php`: fixture-backed cache authority tests.
- Modify `backend/tests/ReferenceResolverGeneratedJsonTest.php`: exact production-bundle regression.
- Create `backend/services/ResolvedReferenceSqlValidatorService.php`: fail-closed SQL/reference-filter validation.
- Create `backend/tests/ResolvedReferenceSqlValidatorServiceTest.php`: valid and invalid SQL matrix.
- Modify `backend/services/GeminiService.php`: carry `resolvedFilters` through generation, repair, validation, telemetry, and evidence.
- Modify `backend/tests/GeminiServiceResolverGuidanceRoutingTest.php`: raw/effective prompt isolation with structured filters.
- Modify `backend/tests/GeminiServiceReferenceResolverTelemetryTest.php`: safe filter-level telemetry.
- Modify `backend/tests/GeminiServiceExploratoryRepairTest.php`: resolved-filter failures participate in bounded repair.
- Modify `backend/tests/GeminiServiceResolvedLocationGuardTest.php`: retain legacy location guard behavior beside the generalized validator.

---

### Task 1: Extract Typed Reference Intents and Physical-Video Vocabulary

**Files:**
- Create: `backend/services/ReferenceIntentService.php`
- Create: `backend/tests/ReferenceIntentServiceTest.php`

**Interfaces:**
- Produces: `ReferenceIntentService::extract(string $prompt): array`.
- Produces intent rows with `dimension`, `span`, `terms`, `selector`, `provenance`, and `explicit`.
- Consumes only raw prompt text; it does not load cache rows or emit canonical names.

- [ ] **Step 1: Write the failing vocabulary and overlap tests**

Create `backend/tests/ReferenceIntentServiceTest.php` with a small `assertIntentSame()` helper and these exact cases:

```php
$reported = ReferenceIntentService::extract(
    'Find all of the video formats at Hillyer library. This can be VHS or DVD.'
);
assertIntentSame('library', $reported[0]['dimension'] ?? null, 'Hillyer must be typed as a library.');
assertIntentSame('Hillyer library', $reported[0]['span'] ?? null, 'The original library span must be retained.');
assertIntentSame('material_type', $reported[1]['dimension'] ?? null, 'Formats must be typed as material types.');
assertIntentSame(['vhs', 'dvd'], $reported[1]['terms'] ?? null, 'Explicit formats must narrow the video group.');
assertIntentSame(null, $reported[1]['selector'] ?? null, 'Explicit terms must not retain the default group selector.');
assertIntentSame(true, $reported[1]['explicit'] ?? null, 'Explicit formats must retain provenance.');

$generic = ReferenceIntentService::extract('Show video materials at Hillyer library.');
assertIntentSame('physical_video', $generic[1]['selector'] ?? null, 'Generic video must select physical video.');
assertIntentSame(false, $generic[1]['explicit'] ?? null, 'The generic video group is a documented default.');

$location = ReferenceIntentService::extract('Show items in location HC DVD.');
assertIntentSame(1, count($location), 'An explicit location span must consume DVD.');
assertIntentSame('location', $location[0]['dimension'] ?? null, 'HC DVD must retain location semantics.');
assertIntentSame('HC DVD', $location[0]['span'] ?? null, 'The exact location phrase must be retained.');

$allMaterials = ReferenceIntentService::extract('Show all materials at Hillyer library.');
assertIntentSame(1, count($allMaterials), 'All materials must not add a material-type filter.');
```

Also assert:

```php
assertIntentSame(['dvd'], materialTerms('DVDs at Hillyer library.'), 'DVD plural must normalize.');
assertIntentSame(['vhs'], materialTerms('VHS tapes at Hillyer library.'), 'VHS tape must normalize.');
assertIntentSame(['film'], materialTerms('Films at Hillyer library.'), 'Film plural must normalize.');
assertIntentSame('location', firstDimension('SC Art Video location'), 'Explicit location suffix must win.');
assertIntentSame(['betamax'], materialTerms('Show Betamax format at Hillyer library.'), 'Unknown explicit formats must remain material-type intents.');
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```bash
php backend/tests/ReferenceIntentServiceTest.php
```

Expected: FAIL because `ReferenceIntentService.php` does not exist.

- [ ] **Step 3: Implement the pure intent contract**

Create `ReferenceIntentService` with this public shape:

```php
final class ReferenceIntentService
{
    const DIMENSION_TABLES = [
        'library' => 'inventory.loclibrary__t',
        'location' => 'inventory.location__t',
        'campus' => 'inventory.loccampus__t',
        'institution' => 'inventory.locinstitution__t',
        'service_point' => 'inventory.service_point__t',
        'material_type' => 'inventory.material_type__t',
    ];

    const MATERIAL_SELECTORS = [
        'vhs' => ['Videocassette'],
        'dvd' => ['DVD/Blu-ray'],
        'film' => ['Film'],
        'physical_video' => ['Videocassette', 'DVD/Blu-ray', 'Film'],
    ];

    public static function extract(string $prompt): array;
    public static function tableForDimension(string $dimension): ?string;
    public static function canonicalNamesForMaterialIntent(array $intent): array;
}
```

Implement extraction in this order:

1. Normalize punctuation for matching while retaining offsets into the raw prompt.
2. Extract explicit `location`, `locations`, `collection`, `collections`, `stacks`, `room`, and `shelving` phrases.
3. Extract `library`, `libraries`, `campus`, `institution`, and `service point` phrases outside consumed spans.
4. Extract explicit VHS, DVD/Blu-ray, and Film terms outside consumed spans.
5. Extract an otherwise unknown term immediately qualified by `format`, `formats`, `material type`, or `material types` as an explicit `material_type` term; do not map it to a canonical selector.
6. If no explicit format was found and unconsumed text contains `video`, `videos`, `video material`, or `video formats`, add the `physical_video` selector.
7. Suppress material intent for `all materials` when no explicit format appears.

Return intents in stable dimension order: institution, campus, library, location, service point, then material type. Preserve prompt order within a dimension. Merge all explicit material terms into one intent in canonical selector order (`vhs`, `dvd`, `film`), and retain the first matching raw material span for provenance.

- [ ] **Step 4: Run the test and verify GREEN**

Run:

```bash
php backend/tests/ReferenceIntentServiceTest.php
```

Expected: `ReferenceIntentService test passed`.

- [ ] **Step 5: Commit the typed-intent slice**

```bash
git add backend/services/ReferenceIntentService.php backend/tests/ReferenceIntentServiceTest.php
git commit -m "feat: classify typed reference intents"
```

---

### Task 2: Resolve Typed Intents Against Authoritative Cache Tables

**Files:**
- Modify: `backend/services/ReferenceResolverService.php`
- Modify: `backend/tests/ReferenceResolverServiceTest.php`

**Interfaces:**
- Consumes: `ReferenceIntentService::extract()` and `ReferenceIntentService::canonicalNamesForMaterialIntent()`.
- Produces: existing `guidanceLines` and `resolvedReferences` plus `resolvedFilters`.
- `resolvedFilters` rows have `dimension`, `source_table`, `column`, `values`, `value_metadata`, `provenance`, and `vocabulary_terms`.

- [ ] **Step 1: Add failing pure-resolver tests for the reported prompt**

Add synthetic Hillyer, HC DVD, and material rows:

```php
$videoReferences = [
    [
        'source_table' => 'inventory.location__t',
        'source_id' => 'hc-dvd',
        'name' => 'HC DVD',
        'code' => 'HCDVD',
        'metadata' => ['library_name' => 'HC Harold F. Johnson Library', 'campus_name' => 'Hampshire College'],
    ],
    [
        'source_table' => 'inventory.loclibrary__t',
        'source_id' => 'sc-hillyer',
        'name' => 'SC Hillyer Art Library',
        'code' => 'SCHIL',
        'metadata' => ['campus_name' => 'Smith College'],
    ],
    ['source_table' => 'inventory.material_type__t', 'source_id' => 'mt-vhs', 'name' => 'Videocassette', 'code' => ''],
    ['source_table' => 'inventory.material_type__t', 'source_id' => 'mt-dvd', 'name' => 'DVD/Blu-ray', 'code' => ''],
    ['source_table' => 'inventory.material_type__t', 'source_id' => 'mt-film', 'name' => 'Film', 'code' => ''],
];

$video = ReferenceResolverService::resolvePromptAgainstReferences(
    'Find all of the video formats at Hillyer library. This can be VHS or DVD.',
    $videoReferences
);
assertResolverSame(false, $video['needsClarification'] ?? null, 'Known library and formats must resolve directly.');
assertResolverSame([
    [
        'dimension' => 'library',
        'source_table' => 'inventory.loclibrary__t',
        'column' => 'name',
        'values' => ['SC Hillyer Art Library'],
        'value_metadata' => [
            'SC Hillyer Art Library' => ['campus_name' => 'Smith College'],
        ],
        'provenance' => 'explicit_prompt',
        'vocabulary_terms' => [],
    ],
    [
        'dimension' => 'material_type',
        'source_table' => 'inventory.material_type__t',
        'column' => 'name',
        'values' => ['Videocassette', 'DVD/Blu-ray'],
        'value_metadata' => [
            'Videocassette' => [],
            'DVD/Blu-ray' => [],
        ],
        'provenance' => 'explicit_prompt',
        'vocabulary_terms' => ['vhs', 'dvd'],
    ],
], $video['resolvedFilters'] ?? null, 'Resolver must return table-scoped structured filters.');
assertResolverSame(
    false,
    in_array('HC DVD', array_column($video['resolvedReferences'] ?? [], 'name'), true),
    'DVD vocabulary must not activate a location row.'
);
```

Add cases for generic video (three rows), DVD only, VHS only, Film only, all materials (library filter only), explicit `location HC DVD`, and `SC Art Video location`.

- [ ] **Step 2: Run the focused resolver test and verify RED**

Run:

```bash
php backend/tests/ReferenceResolverServiceTest.php
```

Expected: FAIL because the resolver still performs global matching and does not return `resolvedFilters`.

- [ ] **Step 3: Add table-scoped resolution before legacy global matching**

Require `ReferenceIntentService.php` and add these private helpers:

```php
private static function resolveTypedIntents(array $intents, array $references): array;
private static function referencesForTable(array $references, string $sourceTable): array;
private static function matchNamedIntent(array $intent, array $references): array;
private static function resolveMaterialIntent(array $intent, array $references): array;
private static function buildResolvedFilter(array $intent, array $matches): array;
private static function buildUnavailableReferenceOutcome(array $intent, array $missingNames): array;
```

`resolveTypedIntents()` must:

- match each intent only inside `ReferenceIntentService::tableForDimension($dimension)`;
- resolve material selectors by exact normalized canonical name;
- resolve unknown explicit format terms only against normalized names in `inventory.material_type__t`, clarifying when zero or multiple responsible matches remain;
- allow `Hillyer library` to match `SC Hillyer Art Library` using the distinctive token `hillyer`;
- require all canonical names selected by material vocabulary to exist;
- deduplicate rows by `source_table|source_id`;
- preserve material value order from `MATERIAL_SELECTORS`;
- return consumed prompt spans so legacy matching skips those spans; and
- return an unavailable-reference outcome immediately when a vocabulary target is absent.

Update `resolvePromptAgainstReferences()` so typed resolution runs first, legacy reference behavior applies only to unconsumed text, and `emptyResolution()` includes:

```php
'resolvedFilters' => [],
```

Do not delete learned-alias processing, accepted clarification keys, safe probes, generic-reference suppression, or existing ambiguity handling.

- [ ] **Step 4: Restrict campus-prefix-free location matching**

Change `canMatchNameWithoutPrefix()` to accept the raw typed context:

```php
private static function canMatchNameWithoutPrefix(
    string $sourceTable,
    string $normalizedName,
    ?string $intentDimension = null
): bool {
    if ($sourceTable === 'inventory.location__t') {
        return $intentDimension === 'location'
            && strpos($normalizedName, ' ') !== false;
    }

    if ($sourceTable === 'inventory.loclibrary__t') {
        return $intentDimension === 'library';
    }

    return !self::isLocationHierarchyTable($sourceTable)
        && strpos($normalizedName, ' ') !== false;
}
```

Keep exact names and case-sensitive explicit codes working. A one-word prefix-free location such as `dvd`, `reference`, or `archives` must never match without an explicit exact location/code intent.

- [ ] **Step 5: Run resolver and normalizer regression tests**

Run:

```bash
php backend/tests/ReferenceIntentServiceTest.php
php backend/tests/ReferenceResolverServiceTest.php
php backend/tests/ReferenceResolverCodeMatchTest.php
php backend/tests/ReferenceTextNormalizerServiceTest.php
```

Expected: all four tests pass.

- [ ] **Step 6: Commit the scoped resolver slice**

```bash
git add backend/services/ReferenceResolverService.php backend/tests/ReferenceResolverServiceTest.php
git commit -m "feat: resolve reference intents by dimension"
```

---

### Task 3: Prove JSON-First Authority and User-Facing Failure Behavior

**Files:**
- Modify: `backend/tests/ReferenceResolverJsonFirstTest.php`
- Modify: `backend/tests/ReferenceResolverGeneratedJsonTest.php`
- Modify: `backend/services/ReferenceResolverService.php`

**Interfaces:**
- Consumes: the active JSON bundle through existing `ReferenceJsonBundleService`.
- Produces: domain-language ambiguity and unavailable-reference outcomes without schema details in `question`.

- [ ] **Step 1: Extend the temporary JSON fixture**

Add these fixture rows to `ReferenceResolverJsonFirstTest.php`:

```php
'inventory.location__t' => [
    ['id' => 'hc-dvd', 'name' => 'HC DVD', 'code' => 'HCDVD',
        'metadata' => ['library_name' => 'HC Harold F. Johnson Library', 'campus_name' => 'Hampshire College']],
    ['id' => 'sc-art-video', 'name' => 'SC Art Video', 'code' => 'SCARTV',
        'metadata' => ['library_name' => 'SC Hillyer Art Library', 'campus_name' => 'Smith College']],
],
'inventory.loclibrary__t' => [
    ['id' => 'sc-hillyer', 'name' => 'SC Hillyer Art Library', 'code' => 'SCHIL',
        'metadata' => ['campus_name' => 'Smith College']],
],
'inventory.material_type__t' => [
    ['id' => 'mt-vhs', 'name' => 'Videocassette', 'code' => ''],
    ['id' => 'mt-dvd', 'name' => 'DVD/Blu-ray', 'code' => ''],
    ['id' => 'mt-film', 'name' => 'Film', 'code' => ''],
],
```

Assert the reported prompt resolves the library and two material types, never `HC DVD`; generic video resolves all three types; and explicit `location HC DVD` resolves only the location.

- [ ] **Step 2: Add missing-row and ambiguous-library tests**

Remove `Videocassette` from a copy of the fixture and assert:

```php
assertJsonFirstTrue(!empty($missing['needsClarification']), 'Missing canonical material rows must stop generation.');
assertJsonFirstSame('reference_value_unavailable', $missing['routeReason'] ?? null, 'Missing vocabulary targets need a stable reason.');
assertJsonFirstContains('video format', strtolower($missing['question'] ?? ''), 'The response must use domain language.');
assertJsonFirstNotContains('inventory.', $missing['question'] ?? '', 'Ordinary responses must hide schema names.');
```

Add two distinct library rows whose normalized names both responsibly match the supplied library phrase, and assert a same-dimension clarification with library-only options.

- [ ] **Step 3: Run the JSON-first test and verify RED**

Run:

```bash
php backend/tests/ReferenceResolverJsonFirstTest.php
```

Expected: FAIL until unavailable and same-dimension ambiguity outcomes are implemented.

- [ ] **Step 4: Implement stable failure outcomes**

Use these shapes:

```php
[
    'needsClarification' => true,
    'clarificationType' => 'reference_value_unavailable',
    'question' => 'I could not find the required video format in the current library reference data.',
    'options' => [],
    'route' => 'clarification',
    'routeReason' => 'reference_value_unavailable',
    'dataSource' => null,
]
```

and:

```php
[
    'needsClarification' => true,
    'clarificationType' => self::CLARIFICATION_TYPE_BATCH,
    'clarificationItems' => [[
        'term' => $intent['span'],
        'clarificationKey' => 'reference_library_ambiguous.' . self::normalizeKey($intent['span']),
        'question' => 'Which library should "' . $intent['span'] . '" mean?',
        'confidence' => 'ambiguous_library_reference',
        'reason' => 'multiple_library_matches',
        'inputType' => 'single_choice',
        'freeTextAllowed' => true,
        'options' => $libraryOptions,
    ]],
    'route' => 'clarification',
    'routeReason' => 'reference_resolver_ambiguous_library',
    'dataSource' => null,
]
```

Each option may retain technical `resolvedFilter` data for submission, but its user-visible `label` and `description` must contain library names and campus context only.

- [ ] **Step 5: Add the production generated-bundle regression**

In `ReferenceResolverGeneratedJsonTest.php`, run the exact reported prompt against `backend/data/reference_cache.json` and assert:

```php
assertGeneratedJsonContains("inventory.loclibrary__t.name = 'SC Hillyer Art Library'", $guidance, 'Hillyer must resolve as a library.');
assertGeneratedJsonContains("'Videocassette'", $guidance, 'VHS must resolve through the material-type cache.');
assertGeneratedJsonContains("'DVD/Blu-ray'", $guidance, 'DVD must resolve through the material-type cache.');
assertGeneratedJsonNotContains('HC DVD', $guidance, 'Material vocabulary must not drift to the Hampshire location.');
```

Also assert `resolvedFilters` contains exact canonical values and no generated cache file changes are required.

- [ ] **Step 6: Run JSON resolver tests and commit**

Run:

```bash
php backend/tests/ReferenceResolverJsonFirstTest.php
php backend/tests/ReferenceResolverGeneratedJsonTest.php
php backend/tests/ReferenceJsonBundleServiceTest.php
```

Expected: all three tests pass.

```bash
git add backend/services/ReferenceResolverService.php backend/tests/ReferenceResolverJsonFirstTest.php backend/tests/ReferenceResolverGeneratedJsonTest.php
git commit -m "test: enforce JSON-first video resolution"
```

---

### Task 4: Validate SQL Against Structured Reference Filters

**Files:**
- Create: `backend/services/ResolvedReferenceSqlValidatorService.php`
- Create: `backend/tests/ResolvedReferenceSqlValidatorServiceTest.php`

**Interfaces:**
- Produces: `ResolvedReferenceSqlValidatorService::validate(string $sql, array $resolvedFilters): void`.
- Throws: `InvalidArgumentException` with stable category text `resolved_reference_filter_mismatch`.
- Consumes: the `resolvedFilters` contract from Task 2.

- [ ] **Step 1: Write the failing valid-candidate test**

Use this accepted SQL:

```php
$validSql = <<<'SQL'
SELECT item.id, instance.title, material_type.name
FROM inventory.item__t item
JOIN inventory.holdings_record__t holdings ON holdings.id = item.holdings_record_id
JOIN inventory.instance__t instance ON instance.id = holdings.instance_id
JOIN inventory.material_type__t material_type ON material_type.id = item.material_type_id
JOIN inventory.location__t location ON location.id = item.effective_location_id
JOIN inventory.loclibrary__t library ON library.id = location.library_id
WHERE library.name = 'SC Hillyer Art Library'
  AND material_type.name IN ('Videocassette', 'DVD/Blu-ray')
LIMIT 100
SQL;

ResolvedReferenceSqlValidatorService::validate($validSql, $resolvedFilters);
```

Also accept equivalent `LOWER(alias.name) = LOWER('value')` and positive `ILIKE` predicates when they preserve the full canonical value.

- [ ] **Step 2: Write the failing rejection matrix**

Assert `validate()` throws for:

```php
$invalidCases = [
    'wrong location' => "WHERE loc.name = 'HC DVD'",
    'library on location' => "WHERE loc.name = 'SC Hillyer Art Library'",
    'material on location' => "WHERE loc.name IN ('Videocassette', 'DVD/Blu-ray')",
    'missing VHS' => "WHERE lib.name = 'SC Hillyer Art Library' AND mt.name = 'DVD/Blu-ray'",
    'extra Film after narrowing' => "WHERE lib.name = 'SC Hillyer Art Library' AND mt.name IN ('Videocassette', 'DVD/Blu-ray', 'Film')",
    'cross-campus' => "WHERE lib.name = 'SC Hillyer Art Library' AND camp.name = 'Hampshire College'",
];
```

The test must build complete SQL around each predicate so table aliases and joins are realistic.

- [ ] **Step 3: Run the test and verify RED**

Run:

```bash
php backend/tests/ResolvedReferenceSqlValidatorServiceTest.php
```

Expected: FAIL because the validator does not exist.

- [ ] **Step 4: Implement alias-aware positive predicate validation**

Create:

```php
final class ResolvedReferenceSqlValidatorService
{
    public static function validate(string $sql, array $resolvedFilters): void;

    private static function tableAliases(string $sql): array;
    private static function positiveNameValues(string $sql, array $aliases): array;
    private static function normalizedValueSet(array $values): array;
    private static function assertNoWrongHierarchyValues(string $sql, array $resolvedFilters, array $aliases): void;
    private static function assertNoInstitutionConflict(array $resolvedFilters, array $positivePredicates): void;
}
```

`tableAliases()` must recognize `FROM` and all `JOIN` variants with optional `AS`. `positiveNameValues()` must recognize quoted values in `=`, `ILIKE`, `LIKE`, `LOWER(...) = LOWER(...)`, and `IN (...)`, unescape doubled apostrophes, and ignore negated predicates.

For every filter:

- require the authoritative table alias;
- require every expected value on that table's `name` column;
- reject unexpected positive values on a narrowed material-type filter;
- reject any expected value found on a different reference dimension;
- reject an explicitly resolved location value used on a library column and vice versa; and
- compare `value_metadata` carried by location-hierarchy filters with positive campus, library, and location predicates so Smith and Hampshire scope cannot coexist.

Do not rewrite SQL in this service. Return normally only when all constraints are preserved.

- [ ] **Step 5: Run the validator test and verify GREEN**

Run:

```bash
php backend/tests/ResolvedReferenceSqlValidatorServiceTest.php
```

Expected: `ResolvedReferenceSqlValidatorService test passed`.

- [ ] **Step 6: Commit the validator slice**

```bash
git add backend/services/ResolvedReferenceSqlValidatorService.php backend/tests/ResolvedReferenceSqlValidatorServiceTest.php
git commit -m "feat: validate resolved reference SQL filters"
```

---

### Task 5: Carry Resolved Filters Through Guidance, Generation, Repair, and Evidence

**Files:**
- Modify: `backend/services/ReferenceResolverService.php`
- Modify: `backend/services/GeminiService.php`
- Modify: `backend/tests/GeminiServiceResolverGuidanceRoutingTest.php`
- Modify: `backend/tests/GeminiServiceReferenceResolverTelemetryTest.php`
- Modify: `backend/tests/GeminiServiceExploratoryRepairTest.php`
- Modify: `backend/tests/GeminiServiceResolvedLocationGuardTest.php`

**Interfaces:**
- Consumes: `referenceResolution['resolvedFilters']`.
- Produces: guidance rendered from structured filters and final `_askEvidence.resolvedReferenceFilters`.
- Calls: `ResolvedReferenceSqlValidatorService::validate()` before database preflight for deterministic, legacy, exploratory, repaired, and compiled-fallback candidates.

- [ ] **Step 1: Add failing structured-guidance tests**

Extend the resolver guidance test to assert one multi-value line:

```php
$guidance = ReferenceResolverService::appendGuidanceToPrompt($prompt, $resolution);
assertContainsText(
    "inventory.loclibrary__t.name = 'SC Hillyer Art Library'",
    $guidance,
    'Guidance must preserve library scope.'
);
assertContainsText(
    "inventory.material_type__t.name IN ('Videocassette', 'DVD/Blu-ray')",
    $guidance,
    'Guidance must render explicit narrowed material values.'
);
assertNotContainsText('HC DVD', $guidance, 'Guidance must not contain unrelated location matches.');
```

Keep the existing assertion that family routing sees the raw prompt, not appended guidance.

- [ ] **Step 2: Render guidance from `resolvedFilters`**

Add:

```php
private static function buildResolvedFilterGuidanceLine(array $filter): string
{
    $table = (string)$filter['source_table'];
    $values = array_values(array_map([self::class, 'quoteLiteral'], $filter['values']));
    $predicate = count($values) === 1
        ? $table . '.name = ' . $values[0]
        : $table . '.name IN (' . implode(', ', $values) . ')';

    return '- Resolved local reference filter: use exactly ' . $predicate
        . '. Apply each value only to this reference dimension.';
}
```

Use structured-filter guidance for typed intents. Retain the existing single-reference guidance path for legacy reference matches and learned aliases.

- [ ] **Step 3: Thread filters into every candidate path**

Require `ResolvedReferenceSqlValidatorService.php`. Extend these internal signatures:

```php
private static function generateExploratorySqlResponse(
    string $prompt,
    $campus = null,
    string $reason = 'unsupported_query_family',
    ?string $originalPrompt = null,
    array $resolvedFilters = []
): array;

public static function generateSql(
    $prompt,
    $campus = null,
    $forceLegacy = false,
    $forceIntent = false,
    array $resolvedFilters = []
);
```

Pass `$referenceResolution['resolvedFilters'] ?? []` from `generateSqlWithShadow()` into:

- primary deterministic or legacy generation;
- exploratory first attempts;
- exploratory repair context;
- exploratory repaired candidates;
- hardened and legacy compiled fallbacks; and
- shadow generation.

Immediately before each existing database-preflight call, run:

```php
ResolvedReferenceSqlValidatorService::validate($sql, $resolvedFilters);
```

Wrap mismatch exceptions in the existing repairable semantic-validation exception for exploratory candidates:

```php
throw new ExploratorySqlValidationException(
    'semantic_validation',
    'resolved_reference_filter_mismatch',
    $sql,
    true,
    'The SQL candidate did not preserve the resolved library or material filters.',
    $exception
);
```

Safety and policy violations remain non-repairable. Do not increase the two-repair maximum.

- [ ] **Step 4: Add bounded-repair and final-rejection tests**

In `GeminiServiceExploratoryRepairTest.php`, make attempt 1 return the `HC DVD` candidate and attempt 2 return the valid Hillyer/material SQL. Assert:

```php
assertSameValue(1, $result['repairAttempts'] ?? null, 'Resolved-filter mismatch should use one bounded repair.');
assertContainsText("library.name = 'SC Hillyer Art Library'", $result['sql'] ?? '', 'Repair must restore library scope.');
assertNotContainsText('HC DVD', $result['sql'] ?? '', 'Repair must remove the wrong location.');
```

Add a case where all attempts omit `Videocassette`; assert a no-result repair-exhausted outcome and no database-preflight invocation.

- [ ] **Step 5: Preserve telemetry and evidence without prompt leakage**

Extend resolver telemetry with dimensions and value counts, not prompt text:

```php
'resolvedDimensions' => ['library', 'material_type'],
'resolvedValueCount' => 3,
```

Attach the structured filters to trusted evidence:

```php
$referenceEvidence = [
    'resolvedReferenceFilters' => $referenceResolution['resolvedFilters'] ?? [],
];
```

Merge `$referenceEvidence` into every `withAskEvidence()` call in `generateSqlWithShadow()`. Do not place raw prompts, effective prompts, or generated guidance into `resolvedReferenceFilters`.

- [ ] **Step 6: Run the focused integration tests**

Run:

```bash
php backend/tests/GeminiServiceResolverGuidanceRoutingTest.php
php backend/tests/GeminiServiceReferenceResolverTelemetryTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
php backend/tests/GeminiServiceResolvedLocationGuardTest.php
php backend/tests/GeminiServiceFamilyIntentBranchTest.php
php backend/tests/GeminiServiceFamilyShapeValidationTest.php
```

Expected: all six tests pass.

- [ ] **Step 7: Commit the generation integration**

```bash
git add backend/services/ReferenceResolverService.php backend/services/GeminiService.php backend/tests/GeminiServiceResolverGuidanceRoutingTest.php backend/tests/GeminiServiceReferenceResolverTelemetryTest.php backend/tests/GeminiServiceExploratoryRepairTest.php backend/tests/GeminiServiceResolvedLocationGuardTest.php
git commit -m "feat: enforce resolved filters during SQL generation"
```

---

### Task 6: Run the Full Compatibility Matrix and Final Verification

**Files:**
- Test: `backend/tests/ReferenceIntentServiceTest.php`
- Test: `backend/tests/ReferenceResolverServiceTest.php`
- Test: `backend/tests/ReferenceResolverJsonFirstTest.php`
- Test: `backend/tests/ReferenceResolverGeneratedJsonTest.php`
- Test: `backend/tests/ResolvedReferenceSqlValidatorServiceTest.php`
- Test: all `backend/tests/*Test.php`

**Interfaces:**
- Consumes the complete implementation from Tasks 1–5.
- Produces no application behavior beyond any narrowly required regression-test corrections.

- [ ] **Step 1: Run the exact defect-focused suite**

Run:

```bash
php backend/tests/ReferenceIntentServiceTest.php
php backend/tests/ReferenceResolverServiceTest.php
php backend/tests/ReferenceResolverJsonFirstTest.php
php backend/tests/ReferenceResolverGeneratedJsonTest.php
php backend/tests/ResolvedReferenceSqlValidatorServiceTest.php
```

Expected: all five tests pass, including the exact Hillyer/VHS/DVD prompt.

- [ ] **Step 2: Run reference, guidance, routing, repair, and semantic suites**

Run:

```bash
php backend/tests/ReferenceJsonBundleServiceTest.php
php backend/tests/ReferenceResolverCodeMatchTest.php
php backend/tests/ReferenceResolverGuidanceLineTest.php
php backend/tests/ReferenceTextNormalizerServiceTest.php
php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php
php backend/tests/GeminiServiceResolverGuidanceRoutingTest.php
php backend/tests/GeminiServiceFamilyIntentBranchTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
php backend/tests/ExploratorySqlSemanticValidatorServiceTest.php
```

Expected: all nine tests pass.

- [ ] **Step 3: Run the complete backend suite**

Run:

```bash
for test_file in backend/tests/*Test.php; do
    php "$test_file" || exit 1
done
```

Expected: every test passes; database-dependent tests may emit only their existing explicit skip messages.

- [ ] **Step 4: Verify PHP syntax and working-tree scope**

Run:

```bash
php -l backend/services/ReferenceIntentService.php
php -l backend/services/ReferenceResolverService.php
php -l backend/services/ResolvedReferenceSqlValidatorService.php
php -l backend/services/GeminiService.php
git diff --check
git status --short
```

Expected: every PHP file reports no syntax errors, `git diff --check` is silent, generated cache files have no new task-owned changes, and unrelated pre-existing files remain unstaged.

- [ ] **Step 5: Commit any final test-only compatibility corrections**

If Task 6 required a narrowly scoped test correction, stage only the exact files changed by that correction:

```bash
git add backend/tests/ReferenceIntentServiceTest.php backend/tests/ReferenceResolverServiceTest.php backend/tests/ReferenceResolverJsonFirstTest.php backend/tests/ReferenceResolverGeneratedJsonTest.php backend/tests/ResolvedReferenceSqlValidatorServiceTest.php
git commit -m "test: complete reference resolution regressions"
```

If no Task 6 correction was necessary, do not create an empty commit.

- [ ] **Step 6: Perform completion review**

Confirm from test output and the final diff that:

- the reported prompt resolves `SC Hillyer Art Library`, `Videocassette`, and `DVD/Blu-ray`;
- generic video resolves all three approved physical formats without clarification;
- `location HC DVD` and `SC Art Video location` remain legitimate location requests;
- unknown or missing material rows stop safely;
- wrong hierarchy and cross-campus SQL are rejected before execution;
- raw prompt routing is unchanged; and
- no generated cache or unrelated user file was included in task commits.
