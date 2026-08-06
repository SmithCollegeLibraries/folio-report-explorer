# MARC Field, Indicator, and Content Finder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a governed, location-scoped Cataloging report that finds present or absent MARC field rows using optional indicator, subfield, content, and capitalization criteria and exports either a 12-column worklist or deduplicated FOLIO Instance UUIDs.

**Architecture:** Extract the already-shipped MARC location validation and resolution into a focused shared service, then add a separate fail-closed `CatalogingMarcFieldFinderService` for the new report. Seed one static SQL template whose only structural tokens are the location fragment and one validated `marctab.mtNNN` table; all search choices remain bound parameters. Add a report-specific React parameter panel and pure interpretation/validation utility while leaving ordinary reports on the generic renderer.

**Tech Stack:** PHP 7.2-compatible Yii2, PostgreSQL reporting database, MySQL/MariaDB migrations, React 18, TypeScript, TanStack Query, Axios, Vitest, Testing Library, Docker Compose.

## Global Constraints

- The report name is **MARC Field, Indicator, and Content Finder** and its slug is `marc-field-indicator-content-finder`.
- Query exactly one validated `marctab.mtNNN` table per execution; never use `folio_source_record.marctab` or `records__t.parsed_record__content`.
- Materialize location-scoped `inventory.instance__t` rows before MARC access and require exact `instance.source = 'MARC'`.
- Accept one to 100 unique existing location UUIDs and only `effective_item` or `permanent_item` location basis values; the option list displays active locations.
- Use exactly these ten prefix-safe parameter names once each: `locationIds`, `locationBasis`, `marcTag`, `occurrenceCondition`, `firstIndicator`, `secondIndicator`, `subfieldCode`, `contentRule`, `searchValue`, `caseExact`.
- A blank indicator matches whitespace or the one-character MARC backslash encoding; normalize both to `#` in output.
- `Field Occurrence` comes from `marctab.mtNNN.ord`, never `line`.
- Search text is literal. Do not accept user-authored regular expressions or allow parameters to select SQL operators.
- The public cap is 100,000 worklist subfield rows with a static `LIMIT 100001` sentinel; all governed executions are file-only.
- Preserve the existing missing-tag report, ordinary reports, composite reports, and FOLIO UUID export behavior.
- Do not deploy the active seed until representative PostgreSQL evidence shows that a large multi-location `mt245` content search uses instance-index access and stays within the configured timeout.
- Do not modify or commit the unrelated dirty cache, review, deterministic-layer documentation, or SQL dump files already in the worktree.

---

## File Structure

### New files

- `backend/services/CatalogingMarcLocationScopeService.php` — validates location IDs and basis, resolves selected locations, and returns the reviewed SQL fragment and filename metadata.
- `backend/services/CatalogingMarcFieldFinderService.php` — recognizes the exact seeded finder and compiles validated parameters into governed SQL.
- `backend/services/CatalogingReportCompilerService.php` — dispatches supported cataloging reports to the correct compiler.
- `backend/exceptions/ReportParameterValidationException.php` — carries one or more field-specific 400 errors without exposing database details.
- `mysql/migrations/043_cataloging_marc_field_finder.sql` — idempotently seeds the active fixed report after performance approval.
- `backend/tests/CatalogingMarcLocationScopeServiceTest.php` — locks shared location behavior and the existing three-basis compatibility contract.
- `backend/tests/CatalogingMarcFieldFinderMigrationTest.php` — verifies the seed, parameters, tokens, help text, cap, and migration recognition.
- `backend/tests/CatalogingMarcFieldFinderServiceTest.php` — covers report recognition, validation, binding, and compiled SQL invariants.
- `backend/tests/CatalogingMarcFieldFinderSqlSemanticsTest.php` — executes controlled matching/missing fixtures and verifies row semantics.
- `backend/tests/CatalogingMarcFieldFinderPostgresTest.php` — opt-in live PostgreSQL plan and execution gate.
- `frontend/src/utils/marcFieldFinder.ts` — pure visibility, validation, and plain-language interpretation functions.
- `frontend/src/utils/marcFieldFinder.test.ts` — utility behavior and exact wording tests.
- `frontend/src/components/MarcIndicatorInput.tsx` — accessible Any/Blank/0–9/custom one-character indicator control.
- `frontend/src/components/MarcIndicatorInput.test.tsx` — indicator interaction and normalization tests.
- `frontend/src/components/MarcFieldFinderParameters.tsx` — conditional finder parameter panel and interpretation summary.
- `frontend/src/components/MarcFieldFinderParameters.test.tsx` — progressive disclosure and field-error tests.
- `docs/superpowers/implementation-reports/2026-08-06-marc-field-indicator-content-finder.md` — measured implementation and release evidence.

### Modified files

- `backend/services/CatalogingMarcMissingTagReportService.php` — delegates location validation/resolution to the shared service without changing its public result.
- `backend/services/MigrationService.php` — recognizes migration 043 only when the exact finder seed is present.
- `backend/controllers/FolioQueryController.php` — uses the cataloging compiler dispatcher and returns field-specific parameter errors.
- `backend/tests/CatalogingMarcMissingTagMultiLocationTest.php` — proves the location refactor preserves the current compiler.
- `backend/tests/FolioQueryControllerCatalogingReportTest.php` — covers both governed report slugs, field errors, file-only routing, and legacy fallthrough.
- `backend/tests/MigrationServiceTest.php` — audits migration 043's unique prefix and retry recognition.
- `frontend/src/types/schema.ts` — types optional finder metadata and API field errors.
- `frontend/src/api/client.ts` — exposes a safe field-error extractor for failed report submissions.
- `frontend/src/pages/ReportDetail.tsx` — selects the specialized finder panel, blocks invalid submissions, and places server errors by field.
- `frontend/src/pages/Reports.test.tsx` — verifies the finder listing, report submission, exports, and ordinary-report regression behavior.

---

### Task 1: Extract the shared MARC location scope

**Files:**
- Create: `backend/services/CatalogingMarcLocationScopeService.php`
- Create: `backend/tests/CatalogingMarcLocationScopeServiceTest.php`
- Modify: `backend/services/CatalogingMarcMissingTagReportService.php`
- Modify: `backend/tests/CatalogingMarcMissingTagMultiLocationTest.php`

**Interfaces:**
- Produces: `CatalogingMarcLocationScopeService::resolve(array $inputs, $folioDb, array $allowedBases): array`
- Returns: `locationIds`, `locationBasis`, `locationFragment`, `location`, and `locations`.
- Preserves: `CatalogingMarcMissingTagReportService::build()` return keys and messages.

- [ ] **Step 1: Write the failing shared-scope test**

Create a standalone PHP test with a fake database containing two locations. Assert:

```php
$scope = CatalogingMarcLocationScopeService::resolve(
    [
        'locationIds' => $mainId . ',' . $scienceId,
        'locationBasis' => 'effective_item',
    ],
    $db,
    ['effective_item', 'permanent_item']
);

scopeSame([$mainId, $scienceId], $scope['locationIds'], 'UUID order must be preserved.');
scopeSame('effective_item', $scope['locationBasis'], 'The selected basis must be returned.');
scopeContains('item.effective_location_id', $scope['locationFragment'], 'Effective scope must use item effective location.');
scopeSame(
    ['id' => $mainId . ',' . $scienceId, 'name' => '2 Locations', 'code' => 'MULTI'],
    $scope['location'],
    'Multiple locations must retain deterministic export metadata.'
);
```

Also assert duplicate, malformed, missing, and 101-location selections fail with the existing exact messages; `permanent_holdings` fails when not allowlisted and succeeds when included for the old report. An existing inactive location remains resolvable for backward compatibility with saved missing-tag URLs, even though it is absent from new option lists.

- [ ] **Step 2: Run the test and verify the missing class failure**

Run:

```bash
php backend/tests/CatalogingMarcLocationScopeServiceTest.php
```

Expected: FAIL because `CatalogingMarcLocationScopeService` does not exist.

- [ ] **Step 3: Implement the focused location service**

Use these basis fragments and cap:

```php
final class CatalogingMarcLocationScopeService
{
    public const MAX_LOCATION_SELECTIONS = 100;

    private const LOCATION_FRAGMENTS = [
        'effective_item' => "FROM inventory.item__t item\nJOIN inventory.holdings_record__t holdings ON holdings.id = item.holdings_record_id\nJOIN inventory.instance__t instance ON instance.id = holdings.instance_id\nJOIN inventory.location__t location ON location.id = item.effective_location_id",
        'permanent_item' => "FROM inventory.item__t item\nJOIN inventory.holdings_record__t holdings ON holdings.id = item.holdings_record_id\nJOIN inventory.instance__t instance ON instance.id = holdings.instance_id\nJOIN inventory.location__t location ON location.id = item.permanent_location_id",
        'permanent_holdings' => "FROM inventory.holdings_record__t holdings\nJOIN inventory.instance__t instance ON instance.id = holdings.instance_id\nJOIN inventory.location__t location ON location.id = holdings.permanent_location_id",
    ];

    public static function resolve(array $inputs, $folioDb, array $allowedBases): array
    {
        $rawIds = $inputs['locationIds'] ?? null;
        if (!is_string($rawIds) || trim($rawIds) === '') {
            throw new \InvalidArgumentException('At least one location is required.');
        }
        $locationIds = array_map('trim', explode(',', $rawIds));
        if (count($locationIds) > self::MAX_LOCATION_SELECTIONS) {
            throw new \InvalidArgumentException('No more than 100 locations may be selected.');
        }
        foreach ($locationIds as &$locationId) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $locationId) !== 1) {
                throw new \InvalidArgumentException('Every selected location must be a valid UUID.');
            }
            $locationId = strtolower($locationId);
        }
        unset($locationId);
        if (count(array_unique($locationIds)) !== count($locationIds)) {
            throw new \InvalidArgumentException('Selected locations must be unique.');
        }

        $basis = $inputs['locationBasis'] ?? null;
        if (!is_string($basis)
            || !in_array($basis, $allowedBases, true)
            || !array_key_exists($basis, self::LOCATION_FRAGMENTS)) {
            throw new \InvalidArgumentException('A supported location basis is required.');
        }

        $lookupParams = [];
        $placeholders = [];
        foreach ($locationIds as $index => $id) {
            $marker = ':location_lookup_' . $index;
            $placeholders[] = $marker;
            $lookupParams[$marker] = $id;
        }
        $rows = $folioDb->createCommand(
            'SELECT id::text AS id, name, code FROM inventory.location__t'
                . ' WHERE id IN (' . implode(', ', $placeholders) . ')'
                . ' ORDER BY name, code, id',
            $lookupParams
        )->queryAll();
        $byId = [];
        foreach ($rows as $row) {
            $byId[strtolower((string) $row['id'])] = $row;
        }
        $locations = [];
        foreach ($locationIds as $id) {
            if (!isset($byId[$id])) {
                throw new \InvalidArgumentException('A selected location no longer exists.');
            }
            $locations[] = $byId[$id];
        }

        $location = count($locations) === 1
            ? ['id' => $locationIds[0], 'name' => $locations[0]['name'] ?? null, 'code' => $locations[0]['code'] ?? null]
            : ['id' => implode(',', $locationIds), 'name' => count($locations) . ' Locations', 'code' => 'MULTI'];

        return [
            'locationIds' => $locationIds,
            'locationBasis' => $basis,
            'locationFragment' => self::LOCATION_FRAGMENTS[$basis],
            'location' => $location,
            'locations' => $locations,
        ];
    }
}
```

Keep server-owned `:location_lookup_N` placeholders for the existence query and retain `A selected location no longer exists.` for a missing selection. Do not add an active-status predicate to this existence lookup; active status belongs only in the option query.

- [ ] **Step 4: Refactor the missing-tag compiler to delegate without behavior changes**

Replace its private location validation/fragment/lookup block with:

```php
$scope = CatalogingMarcLocationScopeService::resolve(
    $inputs,
    $folioDb,
    ['effective_item', 'permanent_item', 'permanent_holdings']
);
$locationIds = $scope['locationIds'];
$locationBasis = $scope['locationBasis'];
$locationFragment = $scope['locationFragment'];
```

Return `$scope['location']` and `$scope['locations']` in the existing keys. Keep the current template and parameter fingerprints unchanged.

- [ ] **Step 5: Run focused and regression tests**

Run:

```bash
php backend/tests/CatalogingMarcLocationScopeServiceTest.php
php backend/tests/CatalogingMarcMissingTagMultiLocationTest.php
php backend/tests/CatalogingMarcMissingTagReportServiceTest.php
php backend/tests/CatalogingMarcMissingTagReportSqlSemanticsTest.php
```

Expected: all PASS with unchanged missing-tag SQL and metadata.

- [ ] **Step 6: Commit the shared scope**

```bash
git add backend/services/CatalogingMarcLocationScopeService.php backend/services/CatalogingMarcMissingTagReportService.php backend/tests/CatalogingMarcLocationScopeServiceTest.php backend/tests/CatalogingMarcMissingTagMultiLocationTest.php
git commit -m "refactor: share MARC report location scope"
```

---

### Task 2: Seed and recognize the exact finder contract

**Files:**
- Create: `mysql/migrations/043_cataloging_marc_field_finder.sql`
- Create: `backend/services/CatalogingMarcFieldFinderService.php`
- Create: `backend/tests/CatalogingMarcFieldFinderMigrationTest.php`
- Modify: `backend/services/MigrationService.php`
- Modify: `backend/tests/MigrationServiceTest.php`

**Interfaces:**
- Produces: `CatalogingMarcFieldFinderService::REPORT_SLUG`, `supports()`, and `isCanonicalSeedDefinition()`.
- Seed parameters: the exact ten names in Global Constraints.
- Seed execution config: the existing `100000/100001` identifier-export contract.

- [ ] **Step 1: Write the failing migration contract test**

Assert migration 043:

```php
finderMigrationSame(1, substr_count($migration, '{{location_from}}'), 'Finder SQL needs one location token.');
finderMigrationSame(1, substr_count($migration, '{{marc_table}}'), 'Finder SQL needs one MARC table token.');
finderMigrationContains("'marc-field-indicator-content-finder'", $migration, 'The approved slug must be seeded.');
finderMigrationContains("instance.source = ''MARC''", $migration, 'Only MARC Inventory instances qualify.');
finderMigrationContains('marc_row.instance_id = target_instances.instance_uuid', $migration, 'MARC access must join UUID to UUID.');
finderMigrationContains('marc_row.ord AS "Field Occurrence"', $migration, 'Occurrence must use ord.');
finderMigrationNotContains('marc_row.line AS "Field Occurrence"', $migration, 'Occurrence must not use line.');
finderMigrationContains('CHR(92)', $migration, 'Backslash indicators must normalize as blank.');
finderMigrationContains('LIMIT 100001', $migration, 'The sentinel must be static.');
finderMigrationNotContains('folio_source_record.marctab', $migration, 'The union view is forbidden.');
finderMigrationNotContains('parsed_record__content', $migration, 'Full SRS JSON is forbidden.');
```

Decode the seeded parameter JSON and assert the ordered names equal:

```php
[
    'locationIds', 'locationBasis', 'marcTag', 'occurrenceCondition',
    'firstIndicator', 'secondIndicator', 'subfieldCode', 'contentRule',
    'searchValue', 'caseExact',
]
```

Then perform the pairwise prefix check in the test.

- [ ] **Step 2: Run the migration test and verify it fails**

```bash
php backend/tests/CatalogingMarcFieldFinderMigrationTest.php
```

Expected: FAIL because migration 043 is absent.

- [ ] **Step 3: Write the idempotent seed migration**

Use `INSERT ... ON DUPLICATE KEY UPDATE`, category `cataloging`, source `folio`, limit `100000`, active `1`, and manual creation. The location option SQL must join campus through `lib.campus_id`, include active locations such as SC Internet, and provide only these location bases:

```sql
SELECT value, label
FROM (VALUES
  ('effective_item', 'Effective item'),
  ('permanent_item', 'Permanent item')
) AS basis(value, label)
```

The seeded help text must state that findings are factual rather than MARC,
RDA, or local-policy judgments; document the worklist and FOLIO UUID workflow;
and explain that the 100,000-row cap counts matching subfield rows, so a
truncated worklist can correspond to a much smaller deduplicated UUID file.

Use encoded indicator values `any`, `blank`, and `char:0` through `char:9`. The React component will support `char:<custom>` without expanding SQL structure. Seed content-rule values:

```text
any, contains, not_contains, equals, not_equals, begins, not_begins,
blank, not_blank, has_lowercase, has_non_alphanumeric
```

The SQL template must use this complete shape (escape single quotes once when
embedding it in the MySQL string literal):

```sql
WITH target_instances AS MATERIALIZED (
  SELECT
    instance.id AS instance_uuid,
    instance.hrid AS instance_hrid,
    instance.title,
    STRING_AGG(
      DISTINCT location.name || COALESCE(' [' || location.code || ']', ''),
      '; ' ORDER BY location.name || COALESCE(' [' || location.code || ']', '')
    ) AS selected_locations
  {{location_from}}
  WHERE location.id = ANY(string_to_array(:locationIds, ',')::uuid[])
    AND instance.source = 'MARC'
  GROUP BY instance.id, instance.hrid, instance.title
),
matching_rows AS MATERIALIZED (
  SELECT
    target_instances.instance_uuid,
    target_instances.instance_hrid,
    target_instances.title,
    target_instances.selected_locations,
    CASE
      WHEN TRIM(COALESCE(marc_row.ind1, '')) = '' OR marc_row.ind1 = CHR(92) THEN '#'
      ELSE marc_row.ind1
    END AS first_indicator,
    CASE
      WHEN TRIM(COALESCE(marc_row.ind2, '')) = '' OR marc_row.ind2 = CHR(92) THEN '#'
      ELSE marc_row.ind2
    END AS second_indicator,
    marc_row.ord AS field_occurrence,
    marc_row.sf AS subfield,
    marc_row.content
  FROM target_instances
  JOIN {{marc_table}} marc_row
    ON marc_row.instance_id = target_instances.instance_uuid
  WHERE (
    :firstIndicator = 'any'
    OR (:firstIndicator = 'blank' AND (
      TRIM(COALESCE(marc_row.ind1, '')) = '' OR marc_row.ind1 = CHR(92)
    ))
    OR (LEFT(:firstIndicator, 5) = 'char:' AND marc_row.ind1 = SUBSTRING(:firstIndicator FROM 6))
  )
  AND (
    :secondIndicator = 'any'
    OR (:secondIndicator = 'blank' AND (
      TRIM(COALESCE(marc_row.ind2, '')) = '' OR marc_row.ind2 = CHR(92)
    ))
    OR (LEFT(:secondIndicator, 5) = 'char:' AND marc_row.ind2 = SUBSTRING(:secondIndicator FROM 6))
  )
  AND (:subfieldCode = '' OR COALESCE(marc_row.sf, '') = :subfieldCode)
  AND CASE :contentRule
    WHEN 'any' THEN TRUE
    WHEN 'contains' THEN STRPOS(
      CASE WHEN :caseExact = 'true' THEN COALESCE(marc_row.content, '') ELSE LOWER(COALESCE(marc_row.content, '')) END,
      CASE WHEN :caseExact = 'true' THEN :searchValue ELSE LOWER(:searchValue) END
    ) > 0
    WHEN 'not_contains' THEN STRPOS(
      CASE WHEN :caseExact = 'true' THEN COALESCE(marc_row.content, '') ELSE LOWER(COALESCE(marc_row.content, '')) END,
      CASE WHEN :caseExact = 'true' THEN :searchValue ELSE LOWER(:searchValue) END
    ) = 0
    WHEN 'equals' THEN
      CASE WHEN :caseExact = 'true' THEN COALESCE(marc_row.content, '') ELSE LOWER(COALESCE(marc_row.content, '')) END
      = CASE WHEN :caseExact = 'true' THEN :searchValue ELSE LOWER(:searchValue) END
    WHEN 'not_equals' THEN
      CASE WHEN :caseExact = 'true' THEN COALESCE(marc_row.content, '') ELSE LOWER(COALESCE(marc_row.content, '')) END
      <> CASE WHEN :caseExact = 'true' THEN :searchValue ELSE LOWER(:searchValue) END
    WHEN 'begins' THEN LEFT(
      CASE WHEN :caseExact = 'true' THEN COALESCE(marc_row.content, '') ELSE LOWER(COALESCE(marc_row.content, '')) END,
      CHAR_LENGTH(:searchValue)
    ) = CASE WHEN :caseExact = 'true' THEN :searchValue ELSE LOWER(:searchValue) END
    WHEN 'not_begins' THEN LEFT(
      CASE WHEN :caseExact = 'true' THEN COALESCE(marc_row.content, '') ELSE LOWER(COALESCE(marc_row.content, '')) END,
      CHAR_LENGTH(:searchValue)
    ) <> CASE WHEN :caseExact = 'true' THEN :searchValue ELSE LOWER(:searchValue) END
    WHEN 'blank' THEN TRIM(COALESCE(marc_row.content, '')) = ''
    WHEN 'not_blank' THEN TRIM(COALESCE(marc_row.content, '')) <> ''
    WHEN 'has_lowercase' THEN COALESCE(marc_row.content, '') ~ '[a-z]'
    WHEN 'has_non_alphanumeric' THEN COALESCE(marc_row.content, '') ~ '[^A-Za-z0-9]'
    ELSE FALSE
  END
),
report_rows AS (
  SELECT
    matching_rows.instance_uuid AS "Instance UUID",
    matching_rows.instance_hrid AS "Instance HRID",
    matching_rows.title AS "Title",
    matching_rows.selected_locations AS "Selected Location(s)",
    CASE :locationBasis
      WHEN 'effective_item' THEN 'Effective item'
      WHEN 'permanent_item' THEN 'Permanent item'
    END AS "Location Basis",
    :marcTag AS "MARC Tag",
    matching_rows.first_indicator AS "First Indicator",
    matching_rows.second_indicator AS "Second Indicator",
    matching_rows.field_occurrence AS "Field Occurrence",
    matching_rows.subfield AS "Subfield",
    matching_rows.content AS "Content",
    CASE :contentRule
      WHEN 'any' THEN 'Present matching occurrence'
      WHEN 'contains' THEN 'Content contains search text'
      WHEN 'not_contains' THEN 'Content does not contain search text'
      WHEN 'equals' THEN 'Content equals search text'
      WHEN 'not_equals' THEN 'Content does not equal search text'
      WHEN 'begins' THEN 'Content begins with search text'
      WHEN 'not_begins' THEN 'Content does not begin with search text'
      WHEN 'blank' THEN 'Content is blank'
      WHEN 'not_blank' THEN 'Content is not blank'
      WHEN 'has_lowercase' THEN 'Content contains lowercase characters'
      WHEN 'has_non_alphanumeric' THEN 'Content contains non-alphanumeric characters'
    END AS "Finding"
  FROM matching_rows
  WHERE :occurrenceCondition = 'has'
  UNION ALL
  SELECT
    target_instances.instance_uuid,
    target_instances.instance_hrid,
    target_instances.title,
    target_instances.selected_locations,
    CASE :locationBasis
      WHEN 'effective_item' THEN 'Effective item'
      WHEN 'permanent_item' THEN 'Permanent item'
    END,
    :marcTag,
    NULL::text,
    NULL::text,
    NULL::integer,
    NULL::text,
    NULL::text,
    'Missing matching occurrence'
  FROM target_instances
  WHERE :occurrenceCondition = 'missing'
    AND NOT EXISTS (
      SELECT 1 FROM matching_rows
      WHERE matching_rows.instance_uuid = target_instances.instance_uuid
    )
)
SELECT * FROM report_rows
ORDER BY "Title" NULLS LAST, "Instance HRID" NULLS LAST,
         "Instance UUID", "Field Occurrence" NULLS LAST,
         "Subfield" NULLS LAST, "Content" NULLS LAST
LIMIT 100001
```

The `matching_rows` CTE is the single UUID-correlated probe of the selected per-tag table; every optional criterion belongs in that CTE so the missing branch means “no row matches all selected criteria.”

- [ ] **Step 4: Add canonical seed recognition**

Create the finder service shell with public constants and exact SHA-256 fingerprints for the final SQL and normalized parameter JSON. Derive the two hashes deterministically from the migration in the migration test, print them once, and place the printed 64-character values in named constants. `isCanonicalSeedDefinition()` must also require exact name, category, data source, limits, active state, creator, and execution config.

Add migration recognition:

```php
case '043_cataloging_marc_field_finder.sql':
    return self::catalogingMarcFieldFinderAppearsComplete($db);
```

The helper selects the exact definition by `CatalogingMarcFieldFinderService::REPORT_SLUG` and delegates to `isCanonicalSeedDefinition()`.

- [ ] **Step 5: Run migration and recognition tests**

```bash
php backend/tests/CatalogingMarcFieldFinderMigrationTest.php
php backend/tests/MigrationServiceTest.php
php backend/tests/DeployMigrationPolicyTest.php
```

Expected: all PASS; the repository audit reports no duplicate `043` prefix.

- [ ] **Step 6: Commit the seed contract**

```bash
git add mysql/migrations/043_cataloging_marc_field_finder.sql backend/services/CatalogingMarcFieldFinderService.php backend/services/MigrationService.php backend/tests/CatalogingMarcFieldFinderMigrationTest.php backend/tests/MigrationServiceTest.php
git commit -m "feat: seed flexible MARC field finder"
```

---

### Task 3: Implement fail-closed finder compilation and SQL semantics

**Files:**
- Create: `backend/exceptions/ReportParameterValidationException.php`
- Create: `backend/tests/CatalogingMarcFieldFinderServiceTest.php`
- Create: `backend/tests/CatalogingMarcFieldFinderSqlSemanticsTest.php`
- Modify: `backend/services/CatalogingMarcFieldFinderService.php`

**Interfaces:**
- Produces: `CatalogingMarcFieldFinderService::build(ReportTemplate $report, array $inputs, $folioDb): array`.
- Produces: `ReportParameterValidationException::getFieldErrors(): array<string,string>`.
- Returns compiler keys compatible with `ReportExecutionContractService`: `sql`, `params`, `location`, `locations`, `marcTag`.

- [ ] **Step 1: Write failing parameter-validation tests**

Build a canonical report fixture from migration 043. Use this valid input:

```php
$valid = [
    'locationIds' => $mainId . ',' . $internetId,
    'locationBasis' => 'effective_item',
    'marcTag' => '035',
    'occurrenceCondition' => 'has',
    'firstIndicator' => 'blank',
    'secondIndicator' => 'char:9',
    'subfieldCode' => 'a',
    'contentRule' => 'not_begins',
    'searchValue' => '(SCTFEBA)',
    'caseExact' => 'true',
];
```

Assert field errors for malformed tag; unsupported basis; invalid occurrence condition; indicator values longer than one character; invalid subfield; unknown content rule; missing search text for a text rule; non-empty search text for a non-text rule; and non-boolean capitalization. Assert `char:\` normalizes to `blank`.

Assert a drifted SQL template, parameter set, execution config, or report slug is rejected before binding. Add an explicit pairwise prefix-collision fixture and expect `MARC finder parameter names must not prefix-collide.`

- [ ] **Step 2: Run the service test and verify it fails**

```bash
php backend/tests/CatalogingMarcFieldFinderServiceTest.php
```

Expected: FAIL because `build()` and the field-error exception are incomplete.

- [ ] **Step 3: Implement the validation exception**

```php
final class ReportParameterValidationException extends \InvalidArgumentException
{
    private $fieldErrors;

    public function __construct(string $field, string $message)
    {
        parent::__construct($message);
        $this->fieldErrors = [$field => $message];
    }

    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
```

Keep it PHP 7.2-compatible; do not use typed properties.

- [ ] **Step 4: Implement exact input normalization**

In `build()`:

1. Require `supports()` and the exact seed fingerprints.
2. Require the exact ten parameter definitions once each and rerun the pairwise prefix assertion.
3. Resolve locations through `CatalogingMarcLocationScopeService` with only `effective_item` and `permanent_item`.
4. Validate tag `001–999` and verify `to_regclass('marctab.mtNNN')` exists.
5. Accept occurrence values only `has` and `missing`.
6. Accept indicator values `any`, `blank`, or `char:` plus exactly one character; normalize a backslash character to `blank`.
7. Accept blank subfield or one ASCII alphanumeric character.
8. Accept only the 11 content-rule values from Task 2.
9. Require non-empty search text only for the six text-consuming rules and require empty search text for the other five.
10. Accept `caseExact` only as `true` or `false`; normalize it to `false` for non-text rules.

Throw `ReportParameterValidationException` with the exact parameter name for user-input failures. Reserve ordinary `InvalidArgumentException` for template drift and schema-integrity failures.

- [ ] **Step 5: Bind values and enforce compiled SQL invariants**

Resolve `{{location_from}}` first and `{{marc_table}}` second, reject colons in replacements, and reject remaining `{{...}}` tokens. Pass normalized values to `ReportTemplate::bindParams()` and assert:

- one top-level `ORDER BY`;
- one top-level numeric `LIMIT 100001`;
- no unresolved structural token;
- no `folio_source_record.marctab` or `parsed_record__content` reference; and
- the resolved SQL contains only the selected `marctab.mtNNN` physical table.

- [ ] **Step 6: Run the service tests**

```bash
php backend/tests/CatalogingMarcFieldFinderServiceTest.php
```

Expected: PASS.

- [ ] **Step 7: Write the failing controlled SQL semantics test**

Use attached in-memory SQLite schemas or a controlled PostgreSQL fixture with equivalent functions. Seed cases for:

- whitespace and backslash blank indicators;
- custom alphabetic and punctuation indicators;
- numeric local subfield `9`;
- one 245 occurrence with `$a`, `$b`, `$c` sharing `ord = 1` but different `line` values;
- two 035 occurrences with `ord = 1` and `ord = 2`;
- repeated identical subfields;
- null, empty, whitespace, mixed-case, punctuation, `%`, `_`, quote, and backslash content;
- one MARC and one FOLIO-sourced instance;
- one instance held at two selected locations; and
- a null-HRID MARC instance joined successfully by UUID.

Assert all 11 content rules, both case modes where applicable, both occurrence conditions, same-row indicator/subfield/content semantics, `ord` output, preserved repeated rows, and one missing row per instance.

- [ ] **Step 8: Run semantics, safety, and export contract tests**

```bash
php backend/tests/CatalogingMarcFieldFinderSqlSemanticsTest.php
php backend/tests/SqlBuilderServicePolicyViolationTest.php
php backend/tests/ReportExecutionContractServiceTest.php
php backend/tests/ExportWorkerReportContractTest.php
```

Expected: all PASS. The controlled worklist has the exact 12-column header and the identifier projection deduplicates repeated field rows.

- [ ] **Step 9: Commit the compiler**

```bash
git add backend/exceptions/ReportParameterValidationException.php backend/services/CatalogingMarcFieldFinderService.php backend/tests/CatalogingMarcFieldFinderServiceTest.php backend/tests/CatalogingMarcFieldFinderSqlSemanticsTest.php
git commit -m "feat: compile flexible MARC field searches"
```

---

### Task 4: Route both governed cataloging reports through one dispatcher

**Files:**
- Create: `backend/services/CatalogingReportCompilerService.php`
- Modify: `backend/controllers/FolioQueryController.php`
- Modify: `backend/tests/FolioQueryControllerCatalogingReportTest.php`

**Interfaces:**
- Produces: `CatalogingReportCompilerService::supports(ReportTemplate $report): bool`.
- Produces: `CatalogingReportCompilerService::build(ReportTemplate $report, array $inputs, $folioDb): array`.
- API field-error shape: `{error: "Report parameters are invalid.", fieldErrors: {parameterName: message}}`.

- [ ] **Step 1: Extend the controller test with the new slug**

Add a dispatcher stub and assert both slugs bypass `ReportTemplate::bindParams()`, receive safety and table-policy validation, run preflight, force file output, and persist governed execution metadata. Assert ordinary and composite reports retain their current paths.

Add this field-error case:

```php
CatalogingReportCompilerService::$buildException =
    new ReportParameterValidationException('marcTag', 'MARC tag must be exactly three ASCII digits from 001 through 999.');

$response = runCatalogingReport(['params' => validFinderParams()], finderReport());
catalogingAssertSame(400, Yii::$app->response->statusCode, 'Parameter errors use HTTP 400.');
catalogingAssertSame(
    ['marcTag' => 'MARC tag must be exactly three ASCII digits from 001 through 999.'],
    $response['fieldErrors'] ?? null,
    'The API must identify the invalid field.'
);
catalogingAssertSame(0, count(QueryJob::$created), 'Invalid parameters must not create jobs.');
```

- [ ] **Step 2: Run the controller test and verify failure**

```bash
php backend/tests/FolioQueryControllerCatalogingReportTest.php
```

Expected: FAIL because the dispatcher and field-error response do not exist.

- [ ] **Step 3: Implement the compiler dispatcher**

```php
final class CatalogingReportCompilerService
{
    public static function supports(ReportTemplate $report): bool
    {
        return CatalogingMarcMissingTagReportService::supports($report)
            || CatalogingMarcFieldFinderService::supports($report);
    }

    public static function build(ReportTemplate $report, array $inputs, $folioDb): array
    {
        if (CatalogingMarcMissingTagReportService::supports($report)) {
            return CatalogingMarcMissingTagReportService::build($report, $inputs, $folioDb);
        }
        if (CatalogingMarcFieldFinderService::supports($report)) {
            return CatalogingMarcFieldFinderService::build($report, $inputs, $folioDb);
        }
        throw new \InvalidArgumentException('Unsupported cataloging report template.');
    }
}
```

- [ ] **Step 4: Update the controller**

Replace the direct missing-tag service branch with the dispatcher. Catch `ReportParameterValidationException` before `InvalidArgumentException` and return:

```php
Yii::$app->response->statusCode = 400;
return [
    'error' => 'Report parameters are invalid.',
    'fieldErrors' => $e->getFieldErrors(),
];
```

Keep the existing safe 422 messages for missing tag tables and locations, generic 403 policy response, preflight behavior, and forced file output. Continue passing compiler-derived `marcTag`, location name, and location code to `ReportExecutionContractService`.

- [ ] **Step 5: Run controller and worker regressions**

```bash
php backend/tests/FolioQueryControllerCatalogingReportTest.php
php backend/tests/QueryWorkerReportCapTest.php
php backend/tests/ExportWorkerReportContractTest.php
php backend/tests/QueryJobReportExecutionMetadataTest.php
```

Expected: all PASS.

- [ ] **Step 6: Commit backend routing**

```bash
git add backend/services/CatalogingReportCompilerService.php backend/controllers/FolioQueryController.php backend/tests/FolioQueryControllerCatalogingReportTest.php
git commit -m "feat: route governed cataloging report compilers"
```

---

### Task 5: Build the conditional cataloger parameter experience

**Files:**
- Create: `frontend/src/utils/marcFieldFinder.ts`
- Create: `frontend/src/utils/marcFieldFinder.test.ts`
- Create: `frontend/src/components/MarcIndicatorInput.tsx`
- Create: `frontend/src/components/MarcIndicatorInput.test.tsx`
- Create: `frontend/src/components/MarcFieldFinderParameters.tsx`
- Create: `frontend/src/components/MarcFieldFinderParameters.test.tsx`
- Modify: `frontend/src/types/schema.ts`

**Interfaces:**
- Produces: `MARC_FIELD_FINDER_SLUG`.
- Produces: `evaluateMarcFieldFinder(values): {fieldErrors, interpretation, textRule, valid}`.
- Produces: controlled `MarcFieldFinderParameters` with `values`, `parameters`, `selectOptions`, `serverFieldErrors`, and `onChange` props.

- [ ] **Step 1: Write failing pure utility tests**

Cover these interpretations exactly:

```ts
expect(evaluateMarcFieldFinder({
  ...validValues,
  marcTag: '035',
  occurrenceCondition: 'missing',
  firstIndicator: 'blank',
  secondIndicator: 'char:9',
  subfieldCode: 'a',
  contentRule: 'any',
}).interpretation).toContain(
  'no field row matches: tag 035, first indicator #, second indicator 9, subfield a',
);

expect(evaluateMarcFieldFinder({
  ...validValues,
  contentRule: 'not_begins',
  searchValue: '(SCTFEBA)',
  caseExact: 'true',
}).interpretation).toContain(
  'content does not begin with “(SCTFEBA)”, matching capitalization exactly',
);
```

Assert the same field rules as the backend, including literal `%`, `_`, quote, and backslash search text.

- [ ] **Step 2: Run the utility test and verify failure**

```bash
cd frontend && npm test -- --run src/utils/marcFieldFinder.test.ts
```

Expected: FAIL because the utility is absent.

- [ ] **Step 3: Implement the pure evaluator**

Use exact enum sets shared inside the module. Return field errors keyed by the ten parameter names. Do not build SQL. Format `blank` indicators as `#`, strip only the `char:` transport prefix for display, and state “any subfield” when `subfieldCode` is empty.

- [ ] **Step 4: Write and implement the indicator control test-first**

The control exposes an accessible select containing Any, Blank (`#`), and `0–9`, plus a Custom choice that reveals a one-character text input. Selecting custom `X` emits `char:X`; entering backslash emits `blank`; switching to Any emits `any`.

Run:

```bash
cd frontend && npm test -- --run src/components/MarcIndicatorInput.test.tsx
```

Expected after implementation: PASS.

- [ ] **Step 5: Write and implement the finder panel test-first**

Render the existing `ParamInput` for locations, basis, tag, occurrence condition, subfield, and content rule; render `MarcIndicatorInput` for the two indicators. Show search text and capitalization only for the six text-consuming rules. Show the interpretation immediately above the run area through a dedicated labelled summary element.

Display client and server field errors beside their matching controls. When a content rule no longer consumes text, explicitly call `onChange('searchValue', '')` and `onChange('caseExact', 'false')` so hidden stale values are not submitted.

Run:

```bash
cd frontend && npm test -- --run src/components/MarcFieldFinderParameters.test.tsx
```

Expected after implementation: PASS.

- [ ] **Step 6: Run the focused frontend suite**

```bash
cd frontend && npm test -- --run src/utils/marcFieldFinder.test.ts src/components/MarcIndicatorInput.test.tsx src/components/MarcFieldFinderParameters.test.tsx src/components/ParamInput.marcTag.test.tsx
```

Expected: all PASS.

- [ ] **Step 7: Commit the finder controls**

```bash
git add frontend/src/types/schema.ts frontend/src/utils/marcFieldFinder.ts frontend/src/utils/marcFieldFinder.test.ts frontend/src/components/MarcIndicatorInput.tsx frontend/src/components/MarcIndicatorInput.test.tsx frontend/src/components/MarcFieldFinderParameters.tsx frontend/src/components/MarcFieldFinderParameters.test.tsx
git commit -m "feat: add MARC finder parameter controls"
```

---

### Task 6: Integrate frontend validation, field errors, and exports

**Files:**
- Modify: `frontend/src/api/client.ts`
- Modify: `frontend/src/pages/ReportDetail.tsx`
- Modify: `frontend/src/pages/Reports.test.tsx`
- Modify: `frontend/src/types/schema.ts`

**Interfaces:**
- Produces: `extractReportFieldErrors(error: unknown): Record<string,string>`.
- `ReportDetail` selects `MarcFieldFinderParameters` only for `marc-field-indicator-content-finder`.
- Ordinary report rendering and URL parameter serialization remain unchanged.

- [ ] **Step 1: Write failing ReportDetail tests**

Mock the finder detail with the exact ten parameters and assert:

- it appears under Cataloging;
- invalid finder inputs disable all run/export buttons;
- the interpretation updates after indicator and content changes;
- clicking Run submits the exact ten normalized string values;
- the API-returned `fieldErrors.searchValue` appears beside Search text;
- Run, Export CSV, and Export FOLIO UUID list all remain available;
- the backend-returned `outputMode: 'file'` is accepted even when Run requested table mode; and
- an ordinary report still renders generic `ParamInput` controls.

- [ ] **Step 2: Run the report page test and verify failure**

```bash
cd frontend && npm test -- --run src/pages/Reports.test.tsx
```

Expected: FAIL because ReportDetail does not select the specialized panel or extract field errors.

- [ ] **Step 3: Add safe Axios field-error extraction**

```ts
export function extractReportFieldErrors(error: unknown): Record<string, string> {
  if (!axios.isAxiosError(error)) return {};
  const value = error.response?.data?.fieldErrors;
  if (!value || typeof value !== 'object' || Array.isArray(value)) return {};
  return Object.fromEntries(
    Object.entries(value).filter(
      (entry): entry is [string, string] =>
        typeof entry[0] === 'string' && typeof entry[1] === 'string',
    ),
  );
}
```

Do not trust server keys as markup; React renders messages as text.

- [ ] **Step 4: Integrate the specialized panel**

In `ReportDetail`:

- calculate finder evaluation with `useMemo`;
- include `evaluation.valid` in the button-disabled condition;
- clear previous server errors whenever a parameter changes;
- use `extractReportFieldErrors(runMut.error)` for inline placement;
- render `MarcFieldFinderParameters` for the exact slug and the existing generic grid otherwise; and
- keep URL hydration and serialization using the existing string boundary.

Replace `Submit error: [AxiosError text]` with the server `error` message when available, while retaining a safe generic fallback.

- [ ] **Step 5: Run frontend tests and build**

```bash
cd frontend && npm test -- --run
cd frontend && npm run build
```

Expected: all tests PASS; production build exits 0. Existing Browserslist and large-chunk warnings are non-blocking.

- [ ] **Step 6: Commit frontend integration**

```bash
git add frontend/src/api/client.ts frontend/src/pages/ReportDetail.tsx frontend/src/pages/Reports.test.tsx frontend/src/types/schema.ts
git commit -m "feat: integrate MARC finder report workflow"
```

---

### Task 7: Prove PostgreSQL performance and release readiness

**Files:**
- Create: `backend/tests/CatalogingMarcFieldFinderPostgresTest.php`
- Create: `docs/superpowers/implementation-reports/2026-08-06-marc-field-indicator-content-finder.md`

**Interfaces:**
- Opt-in environment flag: `RUN_FOLIO_DB_TESTS=1`.
- Optional location override: `FOLIO_DB_TEST_LOCATION_IDS` containing comma-separated active UUIDs.
- Evidence output prefix: `MARC_FINDER_PG_PLAN` followed by one JSON object per case.

- [ ] **Step 1: Write the opt-in PostgreSQL test**

Without opt-in, print exactly:

```text
SKIP: Set RUN_FOLIO_DB_TESTS=1 to run live FOLIO PostgreSQL contract checks.
```

With opt-in, use read-only transactions and the configured reporting statement timeout. Select the smallest and largest active locations unless override UUIDs are supplied. Exercise both location bases and these cases:

1. `mt245`, `has`, subfield `a`, case-insensitive contains;
2. `mt245`, `missing`, subfield `a`, any content;
3. `mt035`, blank first indicator, second indicator `9`, subfield `a`;
4. a smaller tag with `has_lowercase`; and
5. literal `%`, `_`, quote, and backslash search text.

Capture `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` and fail if:

- any plan touches `folio_source_record.marctab` or an unselected `mtNNN` table;
- the large `mt245` case contains `Seq Scan` on relation `mt245`;
- the target instance scope is not materialized before MARC access;
- execution exceeds the configured timeout;
- more than 100,001 rows are returned; or
- blank-indicator results omit either observed whitespace or backslash encoding when both exist in the scoped fixture.

- [ ] **Step 2: Run the offline skip path**

```bash
php backend/tests/CatalogingMarcFieldFinderPostgresTest.php
```

Expected: exit 0 with the exact SKIP message.

- [ ] **Step 3: Run the live performance gate**

```bash
RUN_FOLIO_DB_TESTS=1 php backend/tests/CatalogingMarcFieldFinderPostgresTest.php
```

Expected before deployment: PASS with `MARC_FINDER_PG_PLAN` evidence for the largest and smallest selected locations. If credentials are unavailable, record `Not run` and mark release blocked; do not represent estimated plans as measured.

- [ ] **Step 4: Run migration in Docker twice**

First audit for checksum drift:

```bash
docker compose exec php php yii migration/audit
```

Only when the audit has no changed applied checksums, run:

```bash
docker compose exec php php yii migration/run
docker compose exec php php yii migration/run
```

Expected: first run applies 043 exactly once; second run reports no pending work. If audit reports checksum drift, stop and document it rather than bypassing the gate.

- [ ] **Step 5: Run a Docker API smoke test**

Use the authenticated/local API to fetch the report, verify SC Internet appears in location options, and submit:

```json
{
  "outputMode": "table",
  "params": {
    "locationIds": "67204874-e4d7-495b-9247-62cd27d9ea31",
    "locationBasis": "effective_item",
    "marcTag": "035",
    "occurrenceCondition": "has",
    "firstIndicator": "blank",
    "secondIndicator": "char:9",
    "subfieldCode": "a",
    "contentRule": "not_begins",
    "searchValue": "(SCTFEBA)",
    "caseExact": "true"
  }
}
```

Expected: HTTP 202, `outputMode: "file"`, one selected `marctab.mt035` table in queued SQL, and no unbound parameters. Download the worklist and UUID export; verify 12 ordered worklist columns, `ord` values, `#` blank indicators, deduplicated UUIDs, and no warning rows inside either CSV.

- [ ] **Step 6: Record evidence and release decision**

The implementation report must record exact test counts, build result, migration audit/run results, query-plan node and index evidence, execution time, returned rows, truncation behavior, Docker API status, CSV headers, and whether a FOLIO Data Export upload was authorized and run.

Mark release **Blocked** if any of these remain unavailable or fail:

- representative large-location `mt245` plan;
- no-Seq-Scan assertion;
- configured timeout;
- migration checksum audit;
- Docker API worklist and identifier smoke test; or
- required FOLIO Data Export acceptance test under institutional authorization.

- [ ] **Step 7: Commit verification assets**

```bash
git add backend/tests/CatalogingMarcFieldFinderPostgresTest.php docs/superpowers/implementation-reports/2026-08-06-marc-field-indicator-content-finder.md
git commit -m "test: verify flexible MARC field finder"
```

---

### Task 8: Run full regression verification and hand off

**Files:**
- Verify all intended files from Tasks 1–7.
- Update only: `docs/superpowers/implementation-reports/2026-08-06-marc-field-indicator-content-finder.md` if measured totals differ.

**Interfaces:**
- Produces a clean feature diff relative to the starting commit, excluding the user's pre-existing unrelated changes.

- [ ] **Step 1: Run all standalone backend tests**

```bash
set -o pipefail
backend_count=0
for test_file in backend/tests/*Test.php; do
  php "$test_file" || exit 1
  backend_count=$((backend_count + 1))
done
echo "BACKEND_TEST_FILES=$backend_count"
```

Expected: exit 0; live PostgreSQL tests may only skip with their documented opt-in messages.

- [ ] **Step 2: Run the full frontend suite and production build**

```bash
cd frontend && npm test -- --run
cd frontend && npm run build
```

Expected: all tests PASS and build exits 0.

- [ ] **Step 3: Audit the final diff**

```bash
git diff --check
git status --short
git log --oneline --decorate -12
```

Expected: `git diff --check` is silent. Status contains only intentionally uncommitted user files; every finder implementation file is committed.

- [ ] **Step 4: Run static contract scans**

```bash
rg -n 'folio_source_record\.marctab|parsed_record__content' mysql/migrations/043_cataloging_marc_field_finder.sql backend/services/CatalogingMarcFieldFinderService.php
rg -n 'CHR\(92\)|\.ord AS|LIMIT 100001|instance_id = target_instances\.instance_uuid' mysql/migrations/043_cataloging_marc_field_finder.sql
```

Expected: the forbidden scan is silent; the required scan finds backslash normalization, `ord`, sentinel, and UUID join.

- [ ] **Step 5: Request final code review**

Use `superpowers:requesting-code-review` against the complete implementation diff. Resolve correctness findings and rerun affected focused tests plus Steps 1–4.

- [ ] **Step 6: Commit any evidence-only correction**

If measured totals changed after final verification:

```bash
git add docs/superpowers/implementation-reports/2026-08-06-marc-field-indicator-content-finder.md
git commit -m "docs: finalize MARC finder evidence"
```

Do not create an empty commit when the report already contains the measured values.
