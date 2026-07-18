# Query Builder Canonical LDLite Relationships Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make physical LDLite table names canonical throughout Query Builder and let users temporarily select reviewed alternate direct relationships from graph edges while saved queries always use defaults.

**Architecture:** Add a pure backend relationship-catalog builder that translates MetaDB-derived endpoints through the verified LDLite mapping and merges a reviewed overlay. Expose the result through an opt-in `identity=ldlite` schema/path view, normalize trusted relationship IDs at the SQL-builder boundary, and centralize default and active relationship state in Builder so the graph and Joins tab remain synchronized.

**Tech Stack:** PHP 7.2+/Yii2, PostgreSQL schema discovery, JSON configuration, React 18, TypeScript, TanStack Query, React Flow, Vitest, Testing Library.

## Global Constraints

- Query Builder uses physical LDLite names such as `inventory.item__t` as its only primary table identity.
- Legacy names remain accepted as compatibility aliases and search terms but are not primary Builder identifiers.
- Existing schema/path behavior remains unchanged unless the caller sends `identity=ldlite`.
- The relationship overlay is `backend/data/builder_relationship_overrides.json` and is version-controlled, not generated.
- Only reviewed direct column links between the same two tables are selectable in this phase; alternate multi-hop route selection is out of scope.
- `inventory.item__t.effective_location_id -> inventory.location__t.id` is the curated default; permanent and temporary location links are alternatives.
- The client sends relationship IDs, never raw SQL fragments.
- The server resolves every relationship ID from the validated catalog before SQL generation.
- Alternate choices are session-only and affect Build SQL and Run.
- Saved query definitions and generated SQL always use default relationship links.
- Relinking an edge must not run ELK, fit the viewport, or move graph nodes.
- Local supplementary tables retain their current names.
- No database migration is required.
- Do not modify generated schema cache artifacts as part of implementation.

---

## Planned File Structure

### Backend

- `backend/data/builder_relationship_overrides.json` — reviewed supplemental relationships and curated defaults.
- `backend/services/BuilderRelationshipCatalogService.php` — pure mapping, validation, merge, stable-ID, grouping, and default-selection logic.
- `backend/services/BuilderSchemaService.php` — Query Builder-specific canonical table/detail/path projection backed by `FolioSchemaService`.
- `backend/services/BuilderQueryDefinitionNormalizerService.php` — resolves canonical relationship IDs and converts trusted Builder definitions into the existing SQL builder's validated internal shape.
- `backend/services/FolioSchemaService.php` — exposes one read-only schema-input snapshot for the canonical services; legacy public behavior stays unchanged.
- `backend/controllers/FolioQueryController.php` — routes `identity=ldlite` requests and canonical build definitions to the new services.
- `backend/config/params.php` — declares the overlay path.
- `backend/tests/BuilderRelationshipCatalogServiceTest.php` — pure catalog behavior.
- `backend/tests/BuilderSchemaServiceTest.php` — canonical projection, defaults, paths, aliases, and local tables.
- `backend/tests/FolioQueryControllerBuilderIdentityTest.php` — opt-in routing contract.
- `backend/tests/BuilderQueryDefinitionNormalizerServiceTest.php` — trusted relationship resolution and legacy compatibility.
- `backend/tests/SqlBuilderServiceLdliteRelationshipTest.php` — exact effective/permanent/temporary SQL assertions.

### Frontend

- `frontend/src/types/schema.ts` — canonical relationship, relationship-selection, and identity types.
- `frontend/src/api/client.ts` — opt-in schema/detail/path identity parameters.
- `frontend/src/api/client.builderIdentity.test.ts` — request-parameter contract.
- `frontend/src/components/TableDisplay.test.tsx` — canonical Builder identity and unchanged Explorer identity regression.
- `frontend/src/components/builderRelationships.ts` — pure grouping, active-selection, override-pruning, and join-substitution helpers.
- `frontend/src/components/builderRelationships.test.ts` — relationship-state unit tests.
- `frontend/src/pages/Builder.tsx` — canonical API requests, centralized defaults/overrides, SQL invalidation, and default-only save.
- `frontend/src/pages/Builder.test.tsx` — canonical state and save semantics.
- `frontend/src/components/JoinPanel.tsx` — canonical path discovery and synchronized relationship selector.
- `frontend/src/components/JoinPanel.test.tsx` — default, alternate, reset, and join-type behavior.
- `frontend/src/components/BuilderRelationshipEdge.tsx` — pair-stable React Flow edge with an accessible relationship trigger.
- `frontend/src/components/BuilderGraph.tsx` — pair-stable edges and keyboard-accessible edge selector.
- `frontend/src/components/BuilderGraph.test.tsx` — selector behavior and no-layout regression.

---

### Task 1: Build the validated canonical relationship catalog

**Files:**
- Create: `backend/data/builder_relationship_overrides.json`
- Create: `backend/services/BuilderRelationshipCatalogService.php`
- Create: `backend/tests/BuilderRelationshipCatalogServiceTest.php`
- Modify: `backend/config/params.php`

**Interfaces:**
- Consumes: legacy relationship arrays, `[legacyName => physicalName]` mapping, `[physicalName => column definitions]`, and decoded overlay JSON.
- Produces: `BuilderRelationshipCatalogService::build(array $legacyRelationships, array $mapping, array $columnsByTable, array $overlay): array`.
- Catalog keys: `relationships_by_id`, `relationships_by_pair`, `defaults_by_pair`, and `warnings`.
- Relationship keys: `relationship_id`, `pair_id`, `from_table`, `from_column`, `to_table`, `to_column`, `label`, `is_default`, and `source`.

- [ ] **Step 1: Write the failing pure-service test**

Create `backend/tests/BuilderRelationshipCatalogServiceTest.php` with fixed inputs that do not access a live database:

```php
<?php

require_once __DIR__ . '/../services/BuilderRelationshipCatalogService.php';

use app\services\BuilderRelationshipCatalogService;

function expectCatalog($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$legacy = [
    'inventory_items' => [
        'parents' => [[
            'parent_table' => 'inventory_locations',
            'parent_column' => 'id',
            'local_column' => 'effective_location_id',
            'foreign_key' => 'inventory_items_effective_location_id_fkey',
        ]],
        'children' => [],
    ],
];
$mapping = [
    'inventory_items' => 'inventory.item__t',
    'inventory_locations' => 'inventory.location__t',
];
$columns = [
    'inventory.item__t' => [
        ['name' => 'effective_location_id'],
        ['name' => 'permanent_location_id'],
        ['name' => 'temporary_location_id'],
    ],
    'inventory.location__t' => [['name' => 'id']],
];
$overlay = [
    'version' => 1,
    'relationships' => [
        [
            'fromTable' => 'inventory.item__t',
            'fromColumn' => 'effective_location_id',
            'toTable' => 'inventory.location__t',
            'toColumn' => 'id',
            'label' => 'Effective location',
            'default' => true,
        ],
        [
            'fromTable' => 'inventory.item__t',
            'fromColumn' => 'permanent_location_id',
            'toTable' => 'inventory.location__t',
            'toColumn' => 'id',
            'label' => 'Permanent location',
            'default' => false,
        ],
        [
            'fromTable' => 'inventory.item__t',
            'fromColumn' => 'missing_location_id',
            'toTable' => 'inventory.location__t',
            'toColumn' => 'id',
            'label' => 'Invalid location',
            'default' => false,
        ],
    ],
];

$catalog = BuilderRelationshipCatalogService::build($legacy, $mapping, $columns, $overlay);
$pairId = BuilderRelationshipCatalogService::pairId('inventory.item__t', 'inventory.location__t');
$defaultId = 'inventory.item__t.effective_location_id->inventory.location__t.id';
$permanentId = 'inventory.item__t.permanent_location_id->inventory.location__t.id';

expectCatalog(count($catalog['relationships_by_pair'][$pairId]) === 2, 'Expected two valid direct item-location relationships.');
expectCatalog($catalog['defaults_by_pair'][$pairId] === $defaultId, 'Effective location must be the curated default.');
expectCatalog(isset($catalog['relationships_by_id'][$permanentId]), 'Permanent location must be present.');
expectCatalog($catalog['relationships_by_id'][$defaultId]['source'] === 'overlay', 'Overlay must enrich the generated relationship.');
expectCatalog(count($catalog['warnings']) === 1, 'Invalid overlay columns must produce one isolated warning.');

fwrite(STDOUT, "Builder relationship catalog test passed\n");
```

- [ ] **Step 2: Run the test and confirm the red state**

Run:

```bash
php backend/tests/BuilderRelationshipCatalogServiceTest.php
```

Expected: FAIL because `BuilderRelationshipCatalogService.php` does not exist.

- [ ] **Step 3: Add the reviewed overlay and configuration path**

Create `backend/data/builder_relationship_overrides.json` with the three approved item-location relationships:

```json
{
  "version": 1,
  "relationships": [
    {
      "fromTable": "inventory.item__t",
      "fromColumn": "effective_location_id",
      "toTable": "inventory.location__t",
      "toColumn": "id",
      "label": "Effective location",
      "default": true
    },
    {
      "fromTable": "inventory.item__t",
      "fromColumn": "permanent_location_id",
      "toTable": "inventory.location__t",
      "toColumn": "id",
      "label": "Permanent location",
      "default": false
    },
    {
      "fromTable": "inventory.item__t",
      "fromColumn": "temporary_location_id",
      "toTable": "inventory.location__t",
      "toColumn": "id",
      "label": "Temporary location",
      "default": false
    }
  ]
}
```

Add this exact parameter to `backend/config/params.php`:

```php
'builderRelationshipOverlayPath' => dirname(__DIR__) . '/data/builder_relationship_overrides.json',
```

- [ ] **Step 4: Implement the pure catalog builder**

Create `backend/services/BuilderRelationshipCatalogService.php`. Implement these public signatures exactly:

```php
<?php

namespace app\services;

final class BuilderRelationshipCatalogService
{
    public static function relationshipId(string $fromTable, string $fromColumn, string $toTable, string $toColumn): string
    {
        return $fromTable . '.' . $fromColumn . '->' . $toTable . '.' . $toColumn;
    }

    public static function pairId(string $leftTable, string $rightTable): string
    {
        $tables = [$leftTable, $rightTable];
        sort($tables, SORT_STRING);
        return $tables[0] . '<->' . $tables[1];
    }

    public static function loadOverlay(string $path): array
    {
        if (!is_file($path)) {
            return ['version' => 1, 'relationships' => []];
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) ? $decoded : ['version' => 1, 'relationships' => []];
    }

    public static function build(array $legacyRelationships, array $mapping, array $columnsByTable, array $overlay): array
    {
        $relationshipsById = [];
        $warnings = [];

        foreach ($legacyRelationships as $legacyFrom => $relationshipSet) {
            $fromTable = $mapping[$legacyFrom] ?? (isset($columnsByTable[$legacyFrom]) ? $legacyFrom : null);
            foreach (($relationshipSet['parents'] ?? []) as $parent) {
                $legacyTo = $parent['parent_table'] ?? '';
                $toTable = $mapping[$legacyTo] ?? (isset($columnsByTable[$legacyTo]) ? $legacyTo : null);
                self::mergeCandidate($relationshipsById, $warnings, $columnsByTable, [
                    'from_table' => $fromTable,
                    'from_column' => $parent['local_column'] ?? null,
                    'to_table' => $toTable,
                    'to_column' => $parent['parent_column'] ?? null,
                    'label' => $parent['foreign_key'] ?? 'Generated relationship',
                    'default_requested' => false,
                    'source' => 'metadb',
                ]);
            }
        }

        foreach (($overlay['relationships'] ?? []) as $entry) {
            self::mergeCandidate($relationshipsById, $warnings, $columnsByTable, [
                'from_table' => $entry['fromTable'] ?? null,
                'from_column' => $entry['fromColumn'] ?? null,
                'to_table' => $entry['toTable'] ?? null,
                'to_column' => $entry['toColumn'] ?? null,
                'label' => $entry['label'] ?? 'Reviewed relationship',
                'default_requested' => !empty($entry['default']),
                'source' => 'overlay',
            ]);
        }

        return self::finalizeCatalog($relationshipsById, $warnings);
    }

    private static function mergeCandidate(array &$relationshipsById, array &$warnings, array $columnsByTable, array $candidate): void
    {
        $fromTable = $candidate['from_table'];
        $fromColumn = $candidate['from_column'];
        $toTable = $candidate['to_table'];
        $toColumn = $candidate['to_column'];

        if (!is_string($fromTable) || !is_string($fromColumn) || !is_string($toTable) || !is_string($toColumn)) {
            $warnings[] = 'Relationship is missing a physical table or column endpoint.';
            return;
        }
        if (!self::hasColumn($columnsByTable, $fromTable, $fromColumn)) {
            $warnings[] = $fromTable . '.' . $fromColumn . ' does not exist.';
            return;
        }
        if (!self::hasColumn($columnsByTable, $toTable, $toColumn)) {
            $warnings[] = $toTable . '.' . $toColumn . ' does not exist.';
            return;
        }

        $relationshipId = self::relationshipId($fromTable, $fromColumn, $toTable, $toColumn);
        $relationship = [
            'relationship_id' => $relationshipId,
            'pair_id' => self::pairId($fromTable, $toTable),
            'from_table' => $fromTable,
            'from_column' => $fromColumn,
            'to_table' => $toTable,
            'to_column' => $toColumn,
            'label' => (string)$candidate['label'],
            'default_requested' => !empty($candidate['default_requested']),
            'is_default' => false,
            'source' => (string)$candidate['source'],
        ];

        if (!isset($relationshipsById[$relationshipId]) || $relationship['source'] === 'overlay') {
            $relationshipsById[$relationshipId] = $relationship;
        }
    }

    private static function finalizeCatalog(array $relationshipsById, array $warnings): array
    {
        ksort($relationshipsById, SORT_STRING);
        $relationshipsByPair = [];
        foreach ($relationshipsById as $relationship) {
            $relationshipsByPair[$relationship['pair_id']][] = $relationship;
        }

        $defaultsByPair = [];
        foreach ($relationshipsByPair as $pairId => &$relationships) {
            usort($relationships, function (array $left, array $right): int {
                return strcmp($left['relationship_id'], $right['relationship_id']);
            });
            $requested = array_values(array_filter($relationships, function (array $relationship): bool {
                return $relationship['default_requested'] === true;
            }));
            if (count($requested) > 1) {
                $warnings[] = $pairId . ' declares more than one default relationship.';
            }
            $defaultId = !empty($requested)
                ? $requested[0]['relationship_id']
                : $relationships[0]['relationship_id'];
            $defaultsByPair[$pairId] = $defaultId;

            foreach ($relationships as &$relationship) {
                $relationship['is_default'] = $relationship['relationship_id'] === $defaultId;
                unset($relationship['default_requested']);
                $relationshipsById[$relationship['relationship_id']] = $relationship;
            }
            unset($relationship);

            usort($relationships, function (array $left, array $right): int {
                if ($left['is_default'] !== $right['is_default']) {
                    return $left['is_default'] ? -1 : 1;
                }
                return strcmp($left['relationship_id'], $right['relationship_id']);
            });
        }
        unset($relationships);

        ksort($relationshipsByPair, SORT_STRING);
        ksort($defaultsByPair, SORT_STRING);
        return [
            'relationships_by_id' => $relationshipsById,
            'relationships_by_pair' => $relationshipsByPair,
            'defaults_by_pair' => $defaultsByPair,
            'warnings' => $warnings,
        ];
    }

    private static function hasColumn(array $columnsByTable, string $table, string $column): bool
    {
        foreach (($columnsByTable[$table] ?? []) as $definition) {
            if (($definition['name'] ?? null) === $column) {
                return true;
            }
        }
        return false;
    }
}
```

Do not query Yii or a database from this class.

- [ ] **Step 5: Run focused backend verification**

Run:

```bash
php backend/tests/BuilderRelationshipCatalogServiceTest.php
php -l backend/services/BuilderRelationshipCatalogService.php
```

Expected: both commands exit 0; test prints `Builder relationship catalog test passed`.

- [ ] **Step 6: Commit Task 1**

```bash
git add backend/config/params.php backend/data/builder_relationship_overrides.json backend/services/BuilderRelationshipCatalogService.php backend/tests/BuilderRelationshipCatalogServiceTest.php
git commit -m "feat: add canonical builder relationship catalog"
```

---

### Task 2: Project the legacy schema into canonical LDLite tables and paths

**Files:**
- Create: `backend/services/BuilderSchemaService.php`
- Create: `backend/tests/BuilderSchemaServiceTest.php`
- Modify: `backend/services/FolioSchemaService.php`

**Interfaces:**
- Consumes: Task 1 catalog and a new `FolioSchemaService::getBuilderSchemaInputs(): array` snapshot.
- Produces: `BuilderSchemaService::getTables(?array $filter = null): array`, `getTable(string $physicalName): ?array`, `findShortestPath(string $from, string $to): ?array`, `findAllPaths(string $from, string $to, int $maxDepth = 6): array`, `findAllPathsInCatalog(array $catalog, string $from, string $to, int $maxDepth = 6): array`, `getRelationship(string $relationshipId): ?array`, `chooseDefaultRelationshipId(array $catalog, string $pairId): ?string`, `legacyNameFor(string $physicalName): ?string`, `physicalNameFor(string $input): ?string`, `physicalToLegacyMap(): array`, and `catalog(): array`.
- `FolioSchemaService::getBuilderSchemaInputs()` returns `legacy_relationships`, `mapping`, and `columns_by_physical_table` without changing existing methods.

- [ ] **Step 1: Write the failing canonical projection test**

Create `backend/tests/BuilderSchemaServiceTest.php` with a test seam that calls these pure projection helpers before exercising the production façade:

```php
<?php

require_once __DIR__ . '/../services/BuilderRelationshipCatalogService.php';
require_once __DIR__ . '/../services/BuilderSchemaService.php';

use app\services\BuilderRelationshipCatalogService;
use app\services\BuilderSchemaService;

function expectBuilderSchema($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$legacyTables = [
    'inventory_items' => [
        'name' => 'inventory_items',
        'sql_name' => 'inventory.item__t',
        'alias_name' => 'inventory_items',
        'domain' => 'inventory',
    ],
    'inventory_locations' => [
        'name' => 'inventory_locations',
        'sql_name' => 'inventory.location__t',
        'alias_name' => 'inventory_locations',
        'domain' => 'inventory',
    ],
    'query_jobs' => [
        'name' => 'query_jobs',
        'sql_name' => 'query_jobs',
        'alias_name' => null,
        'domain' => 'local',
    ],
];

$projected = BuilderSchemaService::projectTables($legacyTables, null);
expectBuilderSchema(isset($projected['inventory.item__t']), 'Canonical table map must be keyed by LDLite name.');
expectBuilderSchema($projected['inventory.item__t']['name'] === 'inventory.item__t', 'Canonical name must be physical.');
expectBuilderSchema($projected['inventory.item__t']['alias_name'] === 'inventory_items', 'Legacy alias must remain secondary metadata.');
expectBuilderSchema(isset($projected['query_jobs']), 'Local tables must retain their identity.');

$catalog = [
    'relationships_by_id' => [
        'inventory.item__t.effective_location_id->inventory.location__t.id' => [
            'relationship_id' => 'inventory.item__t.effective_location_id->inventory.location__t.id',
            'pair_id' => BuilderRelationshipCatalogService::pairId('inventory.item__t', 'inventory.location__t'),
            'from_table' => 'inventory.item__t',
            'from_column' => 'effective_location_id',
            'to_table' => 'inventory.location__t',
            'to_column' => 'id',
            'label' => 'Effective location',
            'is_default' => true,
            'source' => 'overlay',
        ],
    ],
    'defaults_by_pair' => [],
];
$detail = BuilderSchemaService::projectTable([
    'name' => 'inventory_items',
    'sql_name' => 'inventory.item__t',
    'alias_name' => 'inventory_items',
    'table' => ['columns' => [['name' => 'effective_location_id']]],
], $catalog);

expectBuilderSchema($detail['name'] === 'inventory.item__t', 'Canonical detail must use the physical name.');
expectBuilderSchema($detail['relationships']['parents'][0]['parent_table'] === 'inventory.location__t', 'Relationship endpoint must be physical.');

fwrite(STDOUT, "Builder schema service test passed\n");
```

- [ ] **Step 2: Run the test and confirm it fails**

Run:

```bash
php backend/tests/BuilderSchemaServiceTest.php
```

Expected: FAIL because `BuilderSchemaService.php` is missing.

- [ ] **Step 3: Expose the read-only Folio schema snapshot**

Add this method to `FolioSchemaService`:

```php
public static function getBuilderSchemaInputs(): array
{
    $schema = self::loadSchema();
    return [
        'legacy_relationships' => $schema['relationships'] ?? [],
        'mapping' => self::discoverTableMapping(),
        'columns_by_physical_table' => self::discoverAllColumns(),
    ];
}
```

Do not alter `getTables()`, `getTable()`, `findShortestPath()`, or their default contracts in this task.

- [ ] **Step 4: Implement `BuilderSchemaService`**

Create `backend/services/BuilderSchemaService.php` with the exact public methods from the Interfaces block. Use these projections:

```php
public static function projectTables(array $legacyTables, ?array $filter): array
{
    $result = [];
    foreach ($legacyTables as $legacyName => $summary) {
        $physicalName = (string)($summary['sql_name'] ?? $legacyName);
        $isLocal = ($summary['domain'] ?? null) === 'local' || ($summary['source'] ?? null) === 'local';
        $isPhysical = strpos($physicalName, '.') !== false;
        $isMapped = $physicalName !== $legacyName;
        if (!$isLocal && !$isPhysical && !$isMapped) {
            continue;
        }
        if ($filter !== null && !in_array($physicalName, $filter, true)) {
            continue;
        }
        $summary['name'] = $physicalName;
        $summary['sql_name'] = $physicalName;
        $summary['alias_name'] = $physicalName === $legacyName ? null : $legacyName;
        $result[$physicalName] = $summary;
    }
    ksort($result, SORT_STRING);
    return $result;
}
```

`projectTable()` must filter catalog relationships into `parents` when the table is `from_table` and `children` when it is `to_table`, translating keys to the existing `Relationship` response shape plus the new stable metadata.

Build a request-local static catalog from:

```php
$inputs = FolioSchemaService::getBuilderSchemaInputs();
$overlay = BuilderRelationshipCatalogService::loadOverlay(Yii::$app->params['builderRelationshipOverlayPath']);
$catalog = BuilderRelationshipCatalogService::build(
    $inputs['legacy_relationships'],
    $inputs['mapping'],
    $inputs['columns_by_physical_table'],
    $overlay
);
```

Cache that catalog for the request. On first construction, emit every catalog warning with `Yii::warning($warning, 'builder.relationship_catalog')`. In `getTables()`, omit and log a non-local legacy table that has no verified physical mapping; retain a same-name table only when it is a local table or an already physical schema-qualified table.

Path discovery must use only catalog relationships. Traverse relationships in both directions, order each adjacency list with the pair default first and then by relationship ID, and preserve stored relationship direction in the formatted join object.

- [ ] **Step 5: Extend the test for defaults, aliases, and parallel edges**

Add assertions that:

```php
expectBuilderSchema(
    BuilderSchemaService::chooseDefaultRelationshipId($catalog, $detail['relationships']['parents'][0]['pair_id'])
        === 'inventory.item__t.effective_location_id->inventory.location__t.id',
    'Default relationship lookup must be deterministic.'
);
```

Add a test catalog with effective, permanent, and temporary edges and assert `findAllPathsInCatalog()` returns all three one-hop variants with effective first.

- [ ] **Step 6: Run focused backend tests**

```bash
php backend/tests/BuilderRelationshipCatalogServiceTest.php
php backend/tests/BuilderSchemaServiceTest.php
php backend/tests/FolioSchemaServiceDisplayNamesTest.php
php -l backend/services/FolioSchemaService.php
php -l backend/services/BuilderSchemaService.php
```

Expected: every command exits 0; the existing display-name test remains green.

- [ ] **Step 7: Commit Task 2**

```bash
git add backend/services/FolioSchemaService.php backend/services/BuilderSchemaService.php backend/tests/BuilderSchemaServiceTest.php
git commit -m "feat: project canonical ldlite builder schema"
```

---

### Task 3: Add opt-in canonical schema and path API routing

**Files:**
- Create: `backend/tests/FolioQueryControllerBuilderIdentityTest.php`
- Modify: `backend/controllers/FolioQueryController.php`

**Interfaces:**
- Consumes: Task 2 `BuilderSchemaService` façade.
- Produces: `identity=ldlite` behavior on existing schema, detail, and path endpoints; requests without the parameter remain unchanged.

- [ ] **Step 1: Write the controller-routing test**

Create a standalone Yii request/controller harness in `backend/tests/FolioQueryControllerBuilderIdentityTest.php`, using the complete controller dependency stubs listed at the top of `FolioQueryControllerPolicyViolationStatusTest.php`. Give `FolioSchemaService` and `BuilderSchemaService` distinct marker payloads, then use this request fixture and test body:

```php
final class FakeRequest
{
    private $query;

    public function __construct(array $query)
    {
        $this->query = $query;
    }

    public function get($name, $default = null)
    {
        return array_key_exists($name, $this->query) ? $this->query[$name] : $default;
    }
}

function assertIdentityRoute($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$controller = new \app\controllers\FolioQueryController('folio-query', null);

Yii::$app->request = new FakeRequest([]);
$legacySchema = $controller->actionSchema();
assertIdentityRoute(
    $legacySchema['tables']['source'] === 'legacy',
    'Schema without identity must retain the legacy service.'
);

Yii::$app->request = new FakeRequest(['identity' => 'ldlite']);
$canonicalSchema = $controller->actionSchema();
assertIdentityRoute(
    $canonicalSchema['tables']['source'] === 'ldlite',
    'Schema with identity=ldlite must use BuilderSchemaService.'
);

Yii::$app->request = new FakeRequest([
    'identity' => 'ldlite',
    'from' => 'inventory.item__t',
    'to' => 'inventory.location__t',
]);
$canonicalPath = $controller->actionPath();
assertIdentityRoute(
    $canonicalPath['path']['joins'][0]['is_default'] === true,
    'Canonical path must return the default relationship.'
);
```

The `FolioSchemaService` stub returns `['source' => 'legacy', 'tables' => []]`; the `BuilderSchemaService` stub returns `['source' => 'ldlite', 'tables' => []]` and a one-join default path. No live database is used.

- [ ] **Step 2: Run the test and confirm it fails**

```bash
php backend/tests/FolioQueryControllerBuilderIdentityTest.php
```

Expected: FAIL because controller actions ignore `identity`.

- [ ] **Step 3: Route the opt-in identity**

Add one private controller helper:

```php
private function usesLdliteBuilderIdentity(): bool
{
    return strtolower(trim((string)Yii::$app->request->get('identity', ''))) === 'ldlite';
}
```

Update `actionSchema()`, `actionSchemaDetail()`, and `actionPath()` so only this exact value selects `BuilderSchemaService`. Preserve response status behavior and the existing legacy branches byte-for-byte where practical.

- [ ] **Step 4: Run routing and legacy regression tests**

```bash
php backend/tests/FolioQueryControllerBuilderIdentityTest.php
php backend/tests/FolioSchemaServiceDisplayNamesTest.php
php -l backend/controllers/FolioQueryController.php
```

Expected: all commands exit 0.

- [ ] **Step 5: Commit Task 3**

```bash
git add backend/controllers/FolioQueryController.php backend/tests/FolioQueryControllerBuilderIdentityTest.php
git commit -m "feat: expose opt-in ldlite builder schema"
```

---

### Task 4: Resolve canonical relationship IDs at the SQL-builder boundary

**Files:**
- Create: `backend/services/BuilderQueryDefinitionNormalizerService.php`
- Create: `backend/tests/BuilderQueryDefinitionNormalizerServiceTest.php`
- Create: `backend/tests/SqlBuilderServiceLdliteRelationshipTest.php`
- Modify: `backend/controllers/FolioQueryController.php`

**Interfaces:**
- Consumes: canonical query definitions with `schemaIdentity: "ldlite"` and joins expressed as `{relationship_id, join_type?}`.
- Produces: `BuilderQueryDefinitionNormalizerService::normalize(array $definition): array`, returning the existing legacy/internal `SqlBuilderService` shape with trusted endpoint joins.
- Legacy definitions without `schemaIdentity=ldlite` pass through unchanged.

- [ ] **Step 1: Write the failing normalizer test**

Create `backend/tests/BuilderQueryDefinitionNormalizerServiceTest.php` with injected mapping and catalog arguments:

```php
$definition = [
    'schemaIdentity' => 'ldlite',
    'tables' => ['inventory.item__t', 'inventory.location__t'],
    'columns' => [['table' => 'inventory.item__t', 'column' => 'barcode']],
    'filters' => [],
    'joins' => [[
        'relationship_id' => 'inventory.item__t.permanent_location_id->inventory.location__t.id',
        'join_type' => 'LEFT JOIN',
    ]],
    'orderBy' => [],
    'limit' => 100,
];

$normalized = BuilderQueryDefinitionNormalizerService::normalizeWithCatalog(
    $definition,
    [
        'inventory.item__t' => 'inventory_items',
        'inventory.location__t' => 'inventory_locations',
    ],
    $catalog
);

expectNormalizer($normalized['tables'] === ['inventory_items', 'inventory_locations'], 'Physical tables must normalize to legacy internal keys.');
expectNormalizer($normalized['columns'][0]['table'] === 'inventory_items', 'Column table must normalize.');
expectNormalizer($normalized['joins'][0]['from_column'] === 'permanent_location_id', 'Trusted relationship ID must expand to the reviewed endpoint.');
expectNormalizer($normalized['joins'][0]['join_type'] === 'LEFT JOIN', 'Join type must be preserved.');
expectNormalizer(!isset($normalized['schemaIdentity']), 'Internal definition must not retain schemaIdentity.');
```

Also assert an unknown relationship ID throws `InvalidArgumentException` and a definition without `schemaIdentity` is returned unchanged.

- [ ] **Step 2: Run the normalizer test and confirm it fails**

```bash
php backend/tests/BuilderQueryDefinitionNormalizerServiceTest.php
```

Expected: FAIL because the service is missing.

- [ ] **Step 3: Implement trusted normalization**

Create `BuilderQueryDefinitionNormalizerService` with:

```php
public static function normalize(array $definition): array
{
    if (($definition['schemaIdentity'] ?? null) !== 'ldlite') {
        return $definition;
    }
    return self::normalizeWithCatalog(
        $definition,
        BuilderSchemaService::physicalToLegacyMap(),
        BuilderSchemaService::catalog()
    );
}
```

`normalizeWithCatalog()` must normalize table properties in `tables`, `columns`, `filters`, `groupBy`, `having`, and `orderBy`. For relationship-ID joins, look up the server-owned catalog entry, verify both endpoint tables are in the canonical table list, convert endpoints to legacy internal keys, and copy only `JOIN` or `LEFT JOIN`.

- [ ] **Step 4: Normalize canonical builds in the controller**

In `actionBuild()`, normalize before calling `SqlBuilderService`:

```php
$body = Yii::$app->request->getBodyParams();
$definition = BuilderQueryDefinitionNormalizerService::normalize($body);
$result = SqlBuilderService::build($definition);
```

Keep the existing `InvalidArgumentException` response handling.

- [ ] **Step 5: Add exact SQL integration assertions**

Create `backend/tests/SqlBuilderServiceLdliteRelationshipTest.php`. Seed the same mapping/catalog test seam and build three canonical definitions. Assert the SQL contains exactly one of:

```php
expectSqlContains($effective['sql'], 'ON il.id = ii.effective_location_id');
expectSqlContains($permanent['sql'], 'ON il.id = ii.permanent_location_id');
expectSqlContains($temporary['sql'], 'ON il.id = ii.temporary_location_id');
```

Use the actual aliases emitted by `SqlBuilderService`; if collision suffixes differ, assert the full normalized `JOIN inventory.location__t` and `ON` fragments returned by the test run rather than weakening to a column-only assertion.

- [ ] **Step 6: Run backend normalization and SQL tests**

```bash
php backend/tests/BuilderQueryDefinitionNormalizerServiceTest.php
php backend/tests/SqlBuilderServiceLdliteRelationshipTest.php
php backend/tests/SqlBuilderServiceFilterValueShapeTest.php
php -l backend/services/BuilderQueryDefinitionNormalizerService.php
```

Expected: all commands exit 0.

- [ ] **Step 7: Commit Task 4**

```bash
git add backend/controllers/FolioQueryController.php backend/services/BuilderQueryDefinitionNormalizerService.php backend/tests/BuilderQueryDefinitionNormalizerServiceTest.php backend/tests/SqlBuilderServiceLdliteRelationshipTest.php
git commit -m "feat: resolve trusted builder relationship selections"
```

---

### Task 5: Add canonical frontend contracts and API requests

**Files:**
- Create: `frontend/src/api/client.builderIdentity.test.ts`
- Modify: `frontend/src/types/schema.ts`
- Modify: `frontend/src/api/client.ts`
- Modify: `frontend/src/pages/Builder.tsx`
- Modify: `frontend/src/pages/Builder.test.tsx`
- Modify: `frontend/src/components/TableDisplay.test.tsx`

**Interfaces:**
- Consumes: Tasks 3 and 4 APIs.
- Produces: `SchemaIdentity`, enriched `Relationship`, `RelationshipSelection`, and API methods that accept `identity?: SchemaIdentity`.
- Builder's schema query key includes `ldlite` and every Builder schema/detail/path request sends that identity.

- [ ] **Step 1: Write the failing API parameter test**

Create `frontend/src/api/client.builderIdentity.test.ts` and mock the Axios instance. Assert:

```ts
await fetchSchema(undefined, 'ldlite');
expect(apiGet).toHaveBeenCalledWith('/schema', { params: { identity: 'ldlite' } });

await fetchTableDetail('inventory.item__t', 'ldlite');
expect(apiGet).toHaveBeenCalledWith('/schema/inventory.item__t', { params: { identity: 'ldlite' } });

await findPath('inventory.item__t', 'inventory.location__t', false, 6, 'ldlite');
expect(apiGet).toHaveBeenCalledWith('/path', {
  params: {
    from: 'inventory.item__t',
    to: 'inventory.location__t',
    all: 0,
    maxDepth: 6,
    identity: 'ldlite',
  },
});
```

- [ ] **Step 2: Run the test and confirm it fails**

```bash
cd frontend
npm test -- src/api/client.builderIdentity.test.ts
```

Expected: FAIL because the API functions do not accept identity.

- [ ] **Step 3: Add the canonical TypeScript contract**

Add these types to `frontend/src/types/schema.ts`:

```ts
export type SchemaIdentity = 'ldlite';

export interface CanonicalRelationship extends Relationship {
  relationship_id: string;
  pair_id: string;
  label: string;
  is_default: boolean;
  source: 'metadb' | 'overlay';
}

export interface RelationshipSelection {
  relationship_id: string;
  join_type?: JoinType;
}

export interface CanonicalJoinEdge extends JoinEdge {
  relationship_id: string;
  pair_id: string;
}

export type BuilderJoin = JoinEdge | RelationshipSelection;
```

Add `relationship_id?: string` and `pair_id?: string` to `JoinEdge`, so legacy paths remain valid while `CanonicalJoinEdge` requires both fields. Add the canonical fields as optional properties on `Relationship`, allowing existing non-Builder payloads to retain their current shape. Change `QueryDefinition.joins` to `'auto' | BuilderJoin[]` and add `schemaIdentity?: SchemaIdentity`.

- [ ] **Step 4: Extend the API functions without changing legacy callers**

Implement these signatures:

```ts
export async function fetchSchema(tables?: string[], identity?: SchemaIdentity): Promise<SchemaResponse>
export async function fetchTableDetail(table: string, identity?: SchemaIdentity): Promise<TableDetail>
export async function findPath(from: string, to: string, all?: boolean, maxDepth?: number, identity?: SchemaIdentity): Promise<PathResponse>
```

Only include `identity` in `params` when provided.

- [ ] **Step 5: Make Builder opt in to the canonical view**

In `Builder.tsx`:

```ts
const schemaIdentity = 'ldlite' as const;

const { data: schemaData, isLoading: schemaLoading } = useQuery({
  queryKey: ['schema', schemaIdentity],
  queryFn: () => fetchSchema(undefined, schemaIdentity),
});
```

Call `fetchTableDetail(t, schemaIdentity)` for selected tables and include `schemaIdentity` in every `QueryDefinition` created by Builder.

- [ ] **Step 6: Add the Builder canonical-name regression**

Update `Builder.test.tsx` mocks to return `inventory.item__t`. Select a column and assert the build call contains:

```ts
expect(apiMocks.buildQuery).toHaveBeenCalledWith(expect.objectContaining({
  schemaIdentity: 'ldlite',
  tables: ['inventory.item__t'],
  columns: [expect.objectContaining({ table: 'inventory.item__t' })],
}));
```

Update `TableDisplay.test.tsx` so the Query Builder fixture is keyed by `inventory.item__t`, has `name: 'inventory.item__t'` and `alias_name: 'inventory_items'`, and asserts clicking the row calls `onAddTable('inventory.item__t')`. Keep a separate legacy-shaped Explorer fixture and its existing assertions to prove the non-Builder contract is unchanged.

- [ ] **Step 7: Run focused frontend tests and type checking**

```bash
cd frontend
npm test -- src/api/client.builderIdentity.test.ts src/pages/Builder.test.tsx
npm run build
```

Expected: focused tests pass and TypeScript/Vite build exits 0.

- [ ] **Step 8: Commit Task 5**

```bash
git add frontend/src/types/schema.ts frontend/src/api/client.ts frontend/src/api/client.builderIdentity.test.ts frontend/src/pages/Builder.tsx frontend/src/pages/Builder.test.tsx frontend/src/components/TableDisplay.test.tsx
git commit -m "feat: use canonical ldlite builder contracts"
```

---

### Task 6: Centralize default and active relationship state in Builder

**Files:**
- Create: `frontend/src/components/builderRelationships.ts`
- Create: `frontend/src/components/builderRelationships.test.ts`
- Modify: `frontend/src/pages/Builder.tsx`

**Interfaces:**
- Consumes: canonical table details from Task 5.
- Produces: `RelationshipGroup`, `RelationshipOverrides`, `groupDirectRelationships()`, `activeRelationship()`, `pruneRelationshipOverrides()`, `applyRelationshipOverrides()`, and `currentRelationshipSelections()`.
- Builder owns `activeRelationshipOverrides: Record<pairId, relationshipId>` and `defaultJoins: CanonicalJoinEdge[]`, then passes one relationship-change callback to graph and Joins tab.

- [ ] **Step 1: Write failing pure-state tests**

Create `frontend/src/components/builderRelationships.test.ts` with canonical effective/permanent/temporary fixtures and assert:

```ts
const groups = groupDirectRelationships(tableDetails, [
  'inventory.item__t',
  'inventory.location__t',
]);
const pair = groups['inventory.item__t<->inventory.location__t'];

expect(pair.defaultRelationshipId).toBe(
  'inventory.item__t.effective_location_id->inventory.location__t.id',
);
expect(activeRelationship(pair, {})).toMatchObject({ from_column: 'effective_location_id' });
expect(activeRelationship(pair, {
  [pair.pairId]: 'inventory.item__t.permanent_location_id->inventory.location__t.id',
})).toMatchObject({ from_column: 'permanent_location_id' });
expect(pruneRelationshipOverrides({ [pair.pairId]: pair.relationships[1].relationship_id }, ['inventory.item__t']))
  .toEqual({});
```

Assert `applyRelationshipOverrides()` substitutes only the matching direct join and preserves its `join_type`.

- [ ] **Step 2: Run tests and confirm the red state**

```bash
cd frontend
npm test -- src/components/builderRelationships.test.ts
```

Expected: FAIL because the helper module is missing.

- [ ] **Step 3: Implement the pure relationship helpers**

Create exact exported types:

```ts
export interface RelationshipGroup {
  pairId: string;
  leftTable: string;
  rightTable: string;
  defaultRelationshipId: string;
  relationships: CanonicalRelationship[];
}

export type RelationshipGroups = Record<string, RelationshipGroup>;
export type RelationshipOverrides = Record<string, string>;
```

Deduplicate relationship IDs while grouping parent/child copies. Sort relationships with `is_default` first, then `relationship_id`. `activeRelationship()` must ignore an override not present in the current group.

- [ ] **Step 4: Move override ownership into Builder**

Add:

```ts
const [activeRelationshipOverrides, setActiveRelationshipOverrides] = useState<RelationshipOverrides>({});
const [defaultJoins, setDefaultJoins] = useState<CanonicalJoinEdge[]>([]);
const relationshipGroups = useMemo(
  () => groupDirectRelationships(tableDetails, selectedTables),
  [selectedTables, tableDetails],
);
```

Implement one callback used by both child components:

```ts
const selectRelationship = useCallback((pairId: string, relationshipId: string) => {
  setActiveRelationshipOverrides((current) => {
    const group = relationshipGroups[pairId];
    if (!group || relationshipId === group.defaultRelationshipId) {
      const next = { ...current };
      delete next[pairId];
      return next;
    }
    return { ...current, [pairId]: relationshipId };
  });
  setBuilt(null);
  setEditedSql(null);
}, [relationshipGroups]);
```

Prune overrides whenever selected tables or relationship groups change. Display a non-blocking notice when an existing override is pruned because its relationship disappeared after refresh.

- [ ] **Step 5: Prepare active joins for the discovery callback added in Task 7**

Add and test `currentRelationshipSelections(defaultJoins, relationshipGroups, activeRelationshipOverrides, customJoins)`. It returns relationship-ID selections derived from the complete discovered default path, substitutes only overridden direct pairs, and preserves the current join type by `pair_id`. Keep Builder's existing build behavior until Task 7 supplies `defaultJoins`; when that callback is wired, use these selections whenever an override or manual join-type customization exists. When neither exists, retain `joins: 'auto'`.

- [ ] **Step 6: Run focused tests**

```bash
cd frontend
npm test -- src/components/builderRelationships.test.ts src/pages/Builder.test.tsx
npm run build
```

Expected: tests and build pass.

- [ ] **Step 7: Commit Task 6**

```bash
git add frontend/src/components/builderRelationships.ts frontend/src/components/builderRelationships.test.ts frontend/src/pages/Builder.tsx
git commit -m "feat: centralize builder relationship selections"
```

---

### Task 7: Synchronize alternate relationships in the Joins tab

**Files:**
- Create: `frontend/src/components/JoinPanel.test.tsx`
- Modify: `frontend/src/components/JoinPanel.tsx`
- Modify: `frontend/src/pages/Builder.tsx`

**Interfaces:**
- Consumes: Task 6 groups/overrides and `findPath(..., 'ldlite')` from Task 5.
- Produces: canonical default join discovery, relationship selector per direct pair, `onRelationshipChange(pairId, relationshipId)`, and `onDefaultJoinsChange(joins)`.

- [ ] **Step 1: Write the failing JoinPanel interaction test**

Create `JoinPanel.test.tsx` with mocked canonical path discovery and a group containing three links. Render with controlled overrides and assert:

```tsx
expect(await screen.findByText('effective_location_id')).toBeInTheDocument();
await user.selectOptions(
  screen.getByRole('combobox', { name: 'Relationship for inventory.item__t and inventory.location__t' }),
  'inventory.item__t.permanent_location_id->inventory.location__t.id',
);
expect(onRelationshipChange).toHaveBeenCalledWith(
  'inventory.item__t<->inventory.location__t',
  'inventory.item__t.permanent_location_id->inventory.location__t.id',
);
```

Rerender with the override and assert the displayed columns are `permanent_location_id -> id`. Click Reset to auto and assert the default relationship ID is selected.

- [ ] **Step 2: Run the test and confirm it fails**

```bash
cd frontend
npm test -- src/components/JoinPanel.test.tsx
```

Expected: FAIL because JoinPanel has no relationship selector or canonical identity argument.

- [ ] **Step 3: Extend JoinPanel props and discovery**

Use these props:

```ts
interface Props {
  selectedTables: string[];
  joinMode: 'auto' | 'manual';
  customJoins: JoinEdge[];
  relationshipGroups: RelationshipGroups;
  activeRelationshipOverrides: RelationshipOverrides;
  onRelationshipChange: (pairId: string, relationshipId: string) => void;
  onDefaultJoinsChange: (joins: CanonicalJoinEdge[]) => void;
  onJoinModeChange: (mode: 'auto' | 'manual') => void;
  onCustomJoinsChange: (joins: JoinEdge[]) => void;
}
```

Remove the unused `tableDetails` prop. Call `findPath(source, target, false, 6, 'ldlite')`. On successful discovery, attach `relationship_id` and `pair_id` from the canonical path response and report the complete default joins to Builder.

In Builder, store that callback result in `defaultJoins`. Current Build SQL uses `currentRelationshipSelections()` when overrides or manual join types exist; otherwise it sends `joins: 'auto'`. Clear `defaultJoins` when fewer than two tables remain.

- [ ] **Step 4: Render one synchronized selector per alternative pair**

For each displayed direct join whose pair has more than one relationship, render:

```tsx
<select
  aria-label={`Relationship for ${group.leftTable} and ${group.rightTable}`}
  value={activeRelationship(group, activeRelationshipOverrides).relationship_id}
  onChange={(event) => onRelationshipChange(group.pairId, event.target.value)}
>
  {group.relationships.map((relationship) => (
    <option key={relationship.relationship_id} value={relationship.relationship_id}>
      {relationship.label}{relationship.is_default ? ' — Default' : ''}
    </option>
  ))}
</select>
```

Keep join-type selection independent. Applying an alternate must preserve the pair's current `JOIN` or `LEFT JOIN` choice.

- [ ] **Step 5: Reset defaults coherently**

`resetToAuto()` must clear all relationship overrides through a parent callback, restore automatic join type behavior, and replace custom joins with the discovered defaults. Add an explicit `onResetRelationships` prop rather than calling `onRelationshipChange` repeatedly.

- [ ] **Step 6: Run JoinPanel and Builder tests**

```bash
cd frontend
npm test -- src/components/JoinPanel.test.tsx src/components/builderRelationships.test.ts src/pages/Builder.test.tsx
npm run build
```

Expected: all tests and build pass.

- [ ] **Step 7: Commit Task 7**

```bash
git add frontend/src/components/JoinPanel.tsx frontend/src/components/JoinPanel.test.tsx frontend/src/pages/Builder.tsx
git commit -m "feat: synchronize builder alternate joins"
```

---

### Task 8: Add graph-edge relationship selection without relayout

**Files:**
- Create: `frontend/src/components/BuilderRelationshipEdge.tsx`
- Modify: `frontend/src/components/BuilderGraph.tsx`
- Modify: `frontend/src/components/BuilderGraph.test.tsx`
- Modify: `frontend/src/pages/Builder.tsx`

**Interfaces:**
- Consumes: Task 6 groups/overrides and shared `onRelationshipChange` callback.
- Produces: one pair-stable edge per selected table pair, alternative-count indicator, accessible selector, and presentation-only edge updates.

- [ ] **Step 1: Write the failing graph selector regression**

Extend `BuilderGraph.test.tsx` with canonical item/location fixtures. Make the React Flow mock render the custom edge label trigger, click `Choose relationship between inventory.item__t and inventory.location__t`, choose Permanent location, and assert:

```ts
expect(screen.getByRole('dialog', { name: 'Choose relationship' })).toBeInTheDocument();
await user.click(screen.getByRole('button', { name: /Permanent location/ }));
expect(onRelationshipChange).toHaveBeenCalledWith(pairId, permanentRelationshipId);
expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1);
expect(flowHarness.fitView).toHaveBeenCalledTimes(1);
expect(currentNode('inventory.item__t')?.position).toEqual(positionBeforeSelection);
```

Rerender with the permanent override and assert the active edge label changes while edge `id`, `source`, and `target` remain unchanged.

- [ ] **Step 2: Run the focused test and confirm it fails**

```bash
cd frontend
npm test -- src/components/BuilderGraph.test.tsx
```

Expected: FAIL because graph edges are relationship-ID-based and not selectable.

- [ ] **Step 3: Make selected-table edges pair-stable**

Extend props:

```ts
relationshipGroups: RelationshipGroups;
activeRelationshipOverrides: RelationshipOverrides;
onRelationshipChange: (pairId: string, relationshipId: string) => void;
```

For selected-table relationships, create exactly one edge per pair:

```ts
{
  id: group.pairId,
  source: active.from_table,
  target: active.to_table,
  label: `${active.from_column} → ${active.to_column}${group.relationships.length > 1 ? ` · ${group.relationships.length} links` : ''}`,
  data: { pairId: group.pairId, relationshipId: active.relationship_id },
}
```

Do not place the active relationship ID in the edge ID or topology signature.

- [ ] **Step 4: Add an accessible relationship edge trigger**

Create `BuilderRelationshipEdge.tsx` with React Flow `BaseEdge`, `EdgeLabelRenderer`, and `getSmoothStepPath`. It renders the normal edge path plus a button at the label coordinates when `alternativeCount > 1`:

```tsx
<button
  type="button"
  aria-label={`Choose relationship between ${leftTable} and ${rightTable}`}
  onClick={() => onChoose(pairId)}
  className="nodrag nopan rounded border bg-blue-50 px-2 py-1 font-mono text-[10px] text-blue-800"
>
  {label} · {alternativeCount} links
</button>
```

Register it as `edgeTypes={{ builderRelationship: BuilderRelationshipEdge }}` and set selected-table pair edges to `type: 'builderRelationship'`. Keep `onEdgeClick` on the path as the mouse/touch shortcut; the label button is the keyboard-accessible equivalent.

- [ ] **Step 5: Add the accessible selector dialog**

Store `selectedPairId` and the triggering button element in component state/refs. `onEdgeClick` opens the selector only when the pair has alternatives. Render the selector in a React Flow `Panel` with `role="dialog"`, `aria-label="Choose relationship"`, one button per relationship, a Default badge, and a close button. When selection or close finishes, call `.focus()` on the saved trigger when it remains connected.

- [ ] **Step 6: Preserve topology and manual intent**

Update `graphTopologySignature()` to depend on node IDs and pair-stable edge IDs/endpoints. A changed label, relationship ID, or alternative count must reconcile edge presentation only. Confirm this path does not change `layoutModeRef`, increment `layoutSequence`, schedule `fitView`, or call ELK.

- [ ] **Step 7: Run graph and relationship tests**

```bash
cd frontend
npm test -- src/components/BuilderGraph.test.tsx src/components/builderGraphLayout.test.ts src/components/builderGraphPositions.test.ts src/components/builderRelationships.test.ts
npm run build
```

Expected: all tests and build pass.

- [ ] **Step 8: Commit Task 8**

```bash
git add frontend/src/components/BuilderRelationshipEdge.tsx frontend/src/components/BuilderGraph.tsx frontend/src/components/BuilderGraph.test.tsx frontend/src/pages/Builder.tsx
git commit -m "feat: select alternate joins from graph edges"
```

---

### Task 9: Save defaults, verify compatibility, and complete rollout gates

**Files:**
- Modify: `frontend/src/pages/Builder.tsx`
- Modify: `frontend/src/pages/Builder.test.tsx`
- Modify: `frontend/src/components/JoinPanel.test.tsx`
- Modify: `frontend/src/components/BuilderGraph.test.tsx`
- Test: all new backend tests from Tasks 1–4
- Test: all frontend tests

**Interfaces:**
- Consumes: all prior tasks.
- Produces: default-only saved definition and SQL, explicit disclosure, schema-refresh fallback notice, complete automated verification, and manual acceptance evidence.

- [ ] **Step 1: Write the failing default-only save test**

Extend `Builder.test.tsx` so the mocked graph selects Permanent location, current Build SQL returns permanent-location SQL, and Save is opened. Assert Builder performs a second default build and saves only that result:

```ts
expect(apiMocks.buildQuery).toHaveBeenLastCalledWith(expect.objectContaining({
  schemaIdentity: 'ldlite',
  joins: 'auto',
}));
expect(apiMocks.saveQuery).toHaveBeenCalledWith(expect.objectContaining({
  queryDefinition: expect.objectContaining({
    schemaIdentity: 'ldlite',
    joins: 'auto',
  }),
  generatedSql: expect.stringContaining('effective_location_id'),
}));
```

Assert the current SQL preview still contains `permanent_location_id` after saving.
Add a second test where the default rebuild rejects. Assert `saveQuery` is not called, the dialog remains open, and the user sees `Could not rebuild the default joins. The query was not saved.`

- [ ] **Step 2: Run the test and confirm it fails**

```bash
cd frontend
npm test -- src/pages/Builder.test.tsx
```

Expected: FAIL because Save currently stores the active definition and SQL directly.

- [ ] **Step 3: Implement a default save definition builder**

Add a pure local helper or export it to `builderRelationships.ts` if reused:

```ts
function buildDefaultSaveJoins(
  joinMode: 'auto' | 'manual',
  defaultJoins: CanonicalJoinEdge[],
  customJoins: JoinEdge[],
): 'auto' | RelationshipSelection[] {
  if (joinMode === 'auto') return 'auto';
  const typeByPair = new Map(
    customJoins
      .filter((join): join is CanonicalJoinEdge => Boolean(join.pair_id && join.relationship_id))
      .map((join) => [join.pair_id, join.join_type]),
  );
  return defaultJoins.map((join) => ({
    relationship_id: join.relationship_id,
    join_type: typeByPair.get(join.pair_id) ?? join.join_type,
  }));
}
```

Require canonical path joins to include `relationship_id` and `pair_id`; do not fall back to raw client endpoints in new Builder saves.

- [ ] **Step 4: Rebuild default SQL before saving**

Make `saveMut.mutationFn` asynchronous:

```ts
const defaultDefinition = createQueryDefinition({
  joins: buildDefaultSaveJoins(joinMode, defaultJoins, customJoins),
});
const defaultBuild = await buildQuery(defaultDefinition);
return saveQuery({
  name: saveName,
  description: saveDesc,
  queryDefinition: defaultDefinition,
  generatedSql: defaultBuild.sql,
});
```

Do not replace `built` or `editedSql` with the default save build.

Catch the default-build failure inside the save mutation, preserve the dialog state, and render this exact error in the dialog:

```text
Could not rebuild the default joins. The query was not saved.
```

- [ ] **Step 5: Add disclosure and fallback copy**

When overrides are active, show this exact Save dialog text:

```text
Alternate joins apply to this session only. Saved queries use the default table links.
```

When schema refresh prunes an invalid override, show:

```text
A selected table link is no longer available. Query Builder restored the default link.
```

Both notices must be keyboard-readable and must invalidate stale generated SQL.

- [ ] **Step 6: Run all focused backend tests**

From the repository root:

```bash
php backend/tests/BuilderRelationshipCatalogServiceTest.php
php backend/tests/BuilderSchemaServiceTest.php
php backend/tests/FolioQueryControllerBuilderIdentityTest.php
php backend/tests/BuilderQueryDefinitionNormalizerServiceTest.php
php backend/tests/SqlBuilderServiceLdliteRelationshipTest.php
php backend/tests/FolioSchemaServiceDisplayNamesTest.php
php backend/tests/SqlBuilderServiceFilterValueShapeTest.php
```

Expected: every command exits 0.

- [ ] **Step 7: Run the complete backend standalone suite**

```bash
for test in backend/tests/*Test.php; do php "$test" || exit 1; done
```

Expected: exit 0. If a test requires unavailable external credentials, record its exact name and reason; do not silently omit it.

- [ ] **Step 8: Run complete frontend verification**

```bash
cd frontend
npm test
npm run build
npm run lint
```

Expected: tests and build exit 0. The repository currently lacks an ESLint 9 flat configuration; if `npm run lint` still exits before examining source for that same reason, record it as the pre-existing lint-infrastructure blocker and do not add an unrelated ESLint migration to this feature.

- [ ] **Step 9: Run diff and scope checks**

From the repository root:

```bash
git diff --check
git status --short
git diff --stat main..HEAD
```

Expected: no whitespace errors; only files listed in this plan plus necessary focused test fixtures are changed.

- [ ] **Step 10: Perform the manual acceptance sequence**

Run the local application with a schema-enabled LDLite configuration and verify:

1. Query Builder Browse, selected tables, graph, Relationships, and Joins show `inventory.item__t` and `inventory.location__t`.
2. The selected-table graph edge shows effective location and `3 links`.
3. Clicking the edge opens Effective, Permanent, and Temporary choices.
4. Choosing Permanent updates the edge and Joins tab without moving nodes or fitting the viewport.
5. Build SQL uses `permanent_location_id`.
6. Run submits that generated SQL.
7. Reset to auto restores `effective_location_id`.
8. Choose Temporary, save, and confirm the saved SQL uses `effective_location_id` while the current preview remains temporary.
9. Remove and re-add a table; confirm the default relationship returns.
10. Open Explorer and confirm its existing schema identity remains unchanged.

- [ ] **Step 11: Commit Task 9**

```bash
git add frontend/src/pages/Builder.tsx frontend/src/pages/Builder.test.tsx frontend/src/components/JoinPanel.test.tsx frontend/src/components/BuilderGraph.test.tsx
git commit -m "test: verify canonical builder relationship workflow"
```

If Step 9 shows additional intentional files from final fixes, add them explicitly rather than using `git add -A`.

---

## Completion Checklist

- [ ] Query Builder's active state contains physical LDLite names only.
- [ ] Legacy schema consumers remain unchanged without `identity=ldlite`.
- [ ] Effective, permanent, and temporary item-location relationships are validated.
- [ ] Effective location is deterministic default.
- [ ] Graph and Joins selectors remain synchronized.
- [ ] Relinking does not move nodes or trigger layout.
- [ ] Current Build and Run honor temporary alternates.
- [ ] Reset and table removal restore defaults.
- [ ] Save rebuilds default SQL without replacing current session SQL.
- [ ] Unknown relationship IDs fail validation before SQL generation.
- [ ] Existing saved legacy definitions remain buildable.
- [ ] Backend focused and full suites pass or external-only blockers are documented.
- [ ] Frontend full suite and production build pass.
- [ ] Manual acceptance sequence passes.
