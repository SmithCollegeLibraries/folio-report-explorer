# MARC Missing-Tag Cataloging Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a fixed, location-scoped cataloging report that finds MARC bibliographic records missing a cataloger-selected tag and exports either a six-column worklist or a FOLIO-ready Instance UUID file.

**Architecture:** Seed one reviewed report template containing two server-owned structural tokens and a static `LIMIT 100001`. A dedicated compiler validates the three parameters, resolves only allowlisted location joins and one `marctab.mtNNN` table, then passes ordinary values to the existing binder. A reusable report-execution contract travels in query-job metadata so the table and export workers enforce the same 100,000-row public cap, truncation state, deterministic ordering, and optional identifier-only export without changing ordinary reports.

**Tech Stack:** PHP 7.2+, Yii2 ActiveRecord/controllers/console workers, MySQL 8 migrations, PostgreSQL LDLite/MetaDB reporting tables, React 18, TypeScript, TanStack Query, Vitest, Testing Library.

## Global Constraints

- The report name is **MARC Bibliographic Records Missing a Tag** and its slug is `marc-bibliographic-records-missing-tag`.
- Add `cataloging` as a first-class report category without changing existing category behavior.
- Required parameters are exactly `locationId`, `locationBasis`, and `marcTag`; none may be a prefix of another.
- `locationBasis` defaults to `effective_item` and accepts only `effective_item`, `permanent_item`, or `permanent_holdings`.
- `marcTag` accepts exactly ASCII `001` through `999`; reject `000`, whitespace, signs, Unicode digits, schema names, comments, and SQL fragments.
- A tag resolves only to `marctab.mtNNN`; never query `folio_source_record.marctab` or scan `records__t.parsed_record__content`.
- Eligible instances require exact `inventory.instance__t.source = 'MARC'`. The local deployment contains only `MARC` and `FOLIO`; `CONSORTIUM-MARC` remains out of scope.
- Tag presence is tested with the indexed UUID equality `marctab.mtNNN.instance_id = inventory.instance__t.id`, never Instance HRID.
- Return exactly six ordered columns: `Instance UUID`, `Instance HRID`, `Title`, `Selected Location`, `Location Basis`, `Missing MARC Tag`.
- The static SQL contains one top-level deterministic `ORDER BY` and one `LIMIT 100001`; `default_limit` is 100,000 for metadata/UI consistency only.
- Workers publish at most 100,000 source rows and persist `truncated = true` only when a 100,001st source row exists.
- Large report runs use the existing 10,000-row and large-cost preflight thresholds to select background file mode.
- The worklist CSV keeps all six columns. The identifier CSV keeps only `Instance UUID`, validates/deduplicates UUIDs, uses header `UUID`, UTF-8, and CRLF endings.
- The truncation warning appears beside both inline results and file downloads and is never inserted into either CSV.
- Do not change generic query/export limits or ordering for reports without the new server-owned execution contract.
- Preserve all unrelated working-tree changes.

---

## File and Responsibility Map

- `mysql/migrations/040_cataloging_marc_missing_tag_report.sql`: guarded schema additions, Cataloging enum, execution metadata, and idempotent fixed-report seed.
- `mysql/init.sql`: fresh-install parity for the new category and `execution_config` column.
- `backend/services/MigrationService.php`: deployment-current recognition for migration 040.
- `backend/models/ReportTemplate.php`: Cataloging category, optional SQL-template override for binding, decoded execution config, and safe API capability flags.
- `backend/services/GeminiService.php`: include Cataloging in both AI report-template response schemas.
- `backend/services/CatalogingMarcMissingTagReportService.php`: parameter validation, allowlisted structural resolution, location/table integrity checks, and final SQL construction.
- `backend/services/ReportExecutionContractService.php`: canonical report/job contract validation, sentinel trimming, export SQL policy, and truncation metadata.
- `backend/services/FolioIdentifierCsvService.php`: UUID projection/deduplication and PHP-7.2-compatible CRLF CSV encoding.
- `backend/controllers/FolioQueryController.php`: specialized report compilation, preflight routing, export-kind authorization, job metadata, and safe download filenames.
- `backend/models/QueryJob.php`: decoded metadata, persisted truncation state, and public status fields.
- `backend/commands/QueryWorkerController.php`: table-mode sentinel removal.
- `backend/commands/ExportWorkerController.php`: contract-aware SQL preservation, sentinel consumption, worklist/identifier streaming, and preview behavior.
- `frontend/src/types/schema.ts`: Cataloging, report-export capability, export-kind, and truncation types.
- `frontend/src/utils/reports.ts`: Cataloging label.
- `frontend/src/api/client.ts`: report export-kind submission.
- `frontend/src/components/ParamInput.tsx`: MARC tag input constraints.
- `frontend/src/pages/ReportDetail.tsx`: UUID export action and file/table truncation notice.
- `frontend/src/hooks/useJobPolling.ts`: propagate truncation and file metadata to results.
- `frontend/src/components/ResultsTable.tsx`: generic visible truncation warning.
- `docs/superpowers/implementation-reports/2026-08-06-marc-missing-tag-report.md`: measured plans, timings, result counts, and Data Export smoke-test evidence.

---

### Task 1: Add the Cataloging schema and seed the fixed report

**Files:**
- Create: `mysql/migrations/040_cataloging_marc_missing_tag_report.sql`
- Modify: `mysql/init.sql`
- Modify: `backend/services/MigrationService.php`
- Modify: `backend/models/ReportTemplate.php`
- Modify: `backend/services/GeminiService.php`
- Create: `backend/tests/CatalogingMarcMissingTagReportMigrationTest.php`
- Create: `backend/tests/CatalogingReportCategoryTest.php`
- Modify: `backend/tests/MigrationServiceTest.php`

**Interfaces:**
- Produces: nullable `report_templates.execution_config JSON` and category value `cataloging`.
- Produces: seeded report slug `marc-bibliographic-records-missing-tag` with the exact structural tokens consumed by Task 2.
- Consumes: the existing numbered SQL migration runner and `MigrationService::migrationAppearsApplied()` recognition pattern.

- [ ] **Step 1: Write the failing migration-contract test**

Create `backend/tests/CatalogingMarcMissingTagReportMigrationTest.php` and assert the checked-in migration and fresh-install schema contain the contract:

```php
$migration = file_get_contents(__DIR__ . '/../../mysql/migrations/040_cataloging_marc_missing_tag_report.sql');
$init = file_get_contents(__DIR__ . '/../../mysql/init.sql');

assertContains("'cataloging'", $migration, 'Migration must add the Cataloging enum value.');
assertContains('execution_config JSON NULL', $migration, 'Migration must add reusable execution metadata.');
assertContains("'marc-bibliographic-records-missing-tag'", $migration, 'Migration must seed the fixed report.');
assertSame(1, substr_count($migration, '{{location_from}}'), 'Seed SQL must contain one location token.');
assertSame(1, substr_count($migration, '{{marc_table}}'), 'Seed SQL must contain one MARC-table token.');
assertContains('marc_tag.instance_id = target_instances.instance_uuid', $migration, 'Presence must join UUID to UUID.');
assertNotContains('instance_hrid = target_instances', $migration, 'Presence must not join on HRID.');
assertNotContains('folio_source_record.marctab', $migration, 'Seed must not use the combined MARC view.');
assertContains('LIMIT 100001', $migration, 'Sentinel must be static and reviewable.');
assertContains('A missing tag is a factual finding', $migration, 'Help must not label every result an error.');
assertContains('Export FOLIO UUID list', $migration, 'Help must document the batch-export workflow.');
assertContains("ENUM('acquisitions', 'circulation', 'inventory', 'finance', 'users', 'cataloging', 'other')", $init, 'Fresh installs must accept Cataloging.');
assertContains('execution_config JSON NULL', $init, 'Fresh installs must include execution metadata.');
```

Also extend `backend/tests/MigrationServiceTest.php` with an audit assertion that `040_cataloging_marc_missing_tag_report.sql` has a unique numeric prefix.

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
php backend/tests/CatalogingMarcMissingTagReportMigrationTest.php
php backend/tests/MigrationServiceTest.php
```

Expected: the new test fails because migration 040 and the schema/category additions do not exist; the migration audit remains green until the file is introduced.

- [ ] **Step 3: Add guarded DDL and the exact report seed**

Create migration 040 using `information_schema.COLUMNS`, prepared guarded `ALTER TABLE` statements, and an idempotent `INSERT ... ON DUPLICATE KEY UPDATE`. The stored `execution_config` is:

```json
{
  "public_row_cap": 100000,
  "fetch_row_limit": 100001,
  "preserve_export_order": true,
  "identifier_export": {
    "source_column": "Instance UUID",
    "header": "UUID"
  }
}
```

Seed these parameter definitions:

```json
[
  {
    "name": "locationId",
    "type": "select",
    "label": "Location",
    "required": true,
    "default": "",
    "placeholder": "Select location",
    "description": "The FOLIO location used to scope candidate bibliographic records.",
    "options_db": "folio",
    "options_sql": "SELECT loc.id AS value, COALESCE(campus.name || ' — ', '') || COALESCE(lib.name || ' — ', '') || loc.name || COALESCE(' [' || loc.code || ']', '') AS label FROM inventory.location__t loc LEFT JOIN inventory.loclibrary__t lib ON lib.id = loc.library_id LEFT JOIN inventory.loccampus__t campus ON campus.id = loc.campus_id WHERE COALESCE(loc.is_active, true) ORDER BY campus.name, lib.name, loc.name, loc.code"
  },
  {
    "name": "locationBasis",
    "type": "select",
    "label": "Location basis",
    "required": true,
    "default": "effective_item",
    "placeholder": "Select location basis",
    "description": "Use item effective location, item permanent location, or holdings permanent location.",
    "options_db": "folio",
    "options_sql": "SELECT value, label FROM (VALUES ('effective_item', 'Effective item'), ('permanent_item', 'Permanent item'), ('permanent_holdings', 'Permanent holdings')) AS basis(value, label)"
  },
  {
    "name": "marcTag",
    "type": "text",
    "label": "MARC tag",
    "required": true,
    "default": "",
    "placeholder": "856",
    "description": "Enter exactly three digits from 001 through 999.",
    "input_mode": "numeric",
    "pattern": "[0-9]{3}",
    "max_length": 3
  }
]
```

Use this static SQL template; Task 2 supplies only the two token replacements:

```sql
WITH target_instances AS MATERIALIZED (
    SELECT DISTINCT
        instance.id AS instance_uuid,
        instance.hrid AS instance_hrid,
        instance.title,
        location.name AS selected_location
    {{location_from}}
    WHERE location.id = :locationId
      AND instance.source = 'MARC'
)
SELECT
    target_instances.instance_uuid AS "Instance UUID",
    target_instances.instance_hrid AS "Instance HRID",
    target_instances.title AS "Title",
    target_instances.selected_location AS "Selected Location",
    CASE :locationBasis
        WHEN 'effective_item' THEN 'Effective item'
        WHEN 'permanent_item' THEN 'Permanent item'
        WHEN 'permanent_holdings' THEN 'Permanent holdings'
    END AS "Location Basis",
    :marcTag AS "Missing MARC Tag"
FROM target_instances
WHERE NOT EXISTS (
    SELECT 1
    FROM {{marc_table}} AS marc_tag
    WHERE marc_tag.instance_id = target_instances.instance_uuid
)
ORDER BY target_instances.title NULLS LAST,
         target_instances.instance_hrid NULLS LAST,
         target_instances.instance_uuid
LIMIT 100001
```

Set `default_limit = 100000`, `category = 'cataloging'`, `data_source = 'folio'`, and `created_by = 'manual'`. Update `mysql/init.sql` with the same enum and nullable column.

Seed `help_text` with this user-facing content:

```text
This report finds MARC-sourced bibliographic instances associated with the selected location that do not contain the selected three-digit MARC tag. A missing tag is a factual finding, not automatically a MARC, RDA, or local-policy error; interpret the worklist in the context of record type, cataloging rules, legacy practice, and local policy.

Location basis controls the collection scope: Effective item uses item effective location; Permanent item uses item permanent location; Permanent holdings uses holdings permanent location and can include holdings without items.

Workflow: run the report, review the six-column worklist, and download it for assignment or annotations. Use Export FOLIO UUID list to create a one-column Instance UUID file, upload that file to the institution's approved FOLIO Data Export job profile, export the underlying MARC records, review or edit them in MarcEdit, and reimport corrections only through the institution's approved FOLIO Data Import profile.

The report publishes at most 100,000 records. If a truncation warning appears, narrow the location before treating the exported list as complete.
```

- [ ] **Step 4: Add deployment-current recognition**

Add migration 040 to `MigrationService::migrationAppearsApplied()` and make `databaseAppearsCurrent()` require the complete new schema/seed. Implement a private predicate equivalent to:

```php
private static function marcMissingTagReportAppearsComplete($db): bool
{
    return self::hasColumn($db, 'report_templates', 'execution_config')
        && self::columnTypeContains($db, 'report_templates', 'category', "'cataloging'")
        && self::rowExists(
            $db,
            'report_templates',
            'slug = :slug AND category = :category AND default_limit = :limit'
                . ' AND sql_template LIKE :location_token'
                . ' AND sql_template LIKE :marc_token'
                . ' AND sql_template LIKE :sentinel'
                . ' AND execution_config IS NOT NULL',
            [
                ':slug' => 'marc-bibliographic-records-missing-tag',
                ':category' => 'cataloging',
                ':limit' => 100000,
                ':location_token' => '%{{location_from}}%',
                ':marc_token' => '%{{marc_table}}%',
                ':sentinel' => '%LIMIT 100001%',
            ]
        );
}
```

- [ ] **Step 5: Add Cataloging to backend validation and AI report schemas**

Add `ReportTemplate::CAT_CATALOGING = 'cataloging'` and include it in the model category range. Update both report-generation response-format strings in `GeminiService.php` from:

```text
acquisitions|circulation|inventory|finance|users|other
```

to:

```text
acquisitions|circulation|inventory|finance|users|cataloging|other
```

In `backend/tests/CatalogingReportCategoryTest.php`, assert the model accepts `cataloging`, rejects an unknown category, and both prompt schemas advertise Cataloging exactly once.

- [ ] **Step 6: Run migration and category tests**

Run:

```bash
php backend/tests/CatalogingMarcMissingTagReportMigrationTest.php
php backend/tests/CatalogingReportCategoryTest.php
php backend/tests/MigrationServiceTest.php
php backend/tests/DeployMigrationPolicyTest.php
```

Expected: all three commands exit 0; the migration audit reports no duplicate number and no unsafe unguarded DDL.

- [ ] **Step 7: Commit the schema and seed**

```bash
git add mysql/migrations/040_cataloging_marc_missing_tag_report.sql mysql/init.sql backend/services/MigrationService.php backend/models/ReportTemplate.php backend/services/GeminiService.php backend/tests/CatalogingMarcMissingTagReportMigrationTest.php backend/tests/CatalogingReportCategoryTest.php backend/tests/MigrationServiceTest.php
git commit -m "feat: seed MARC missing-tag cataloging report"
```

---

### Task 2: Build the fail-closed MARC report compiler

**Files:**
- Create: `backend/services/CatalogingMarcMissingTagReportService.php`
- Modify: `backend/models/ReportTemplate.php`
- Create: `backend/tests/CatalogingMarcMissingTagReportServiceTest.php`
- Create: `backend/tests/CatalogingMarcMissingTagReportSqlSemanticsTest.php`
- Modify: `backend/tests/SqlBuilderServicePolicyViolationTest.php`

**Interfaces:**
- Produces: `CatalogingMarcMissingTagReportService::supports(ReportTemplate $report): bool`.
- Produces: `CatalogingMarcMissingTagReportService::build(ReportTemplate $report, array $inputs, $folioDb): array` returning `sql`, `params`, `location`, and `marcTag`.
- Produces: backwards-compatible `ReportTemplate::bindParams($userInputs, $sqlTemplate = null): array`.
- Consumes: the exact report template and parameter definitions from Task 1.

- [ ] **Step 1: Write compiler tests for all valid shapes and invalid inputs**

In `backend/tests/CatalogingMarcMissingTagReportServiceTest.php`, create a report fixture from the migration template and a fake FOLIO DB that answers the location lookup and `to_regclass`. Assert:

```php
$effective = CatalogingMarcMissingTagReportService::build($report, [
    'locationId' => '11111111-1111-4111-8111-111111111111',
    'locationBasis' => 'effective_item',
    'marcTag' => '856',
], $folioDb);

assertContains('FROM inventory.item__t item', $effective['sql']);
assertContains('location.id = item.effective_location_id', $effective['sql']);
assertContains('FROM marctab.mt856 AS marc_tag', $effective['sql']);
assertContains('marc_tag.instance_id = target_instances.instance_uuid', $effective['sql']);
assertSame(1, preg_match_all('/\bLIMIT\s+100001\b/i', $effective['sql']));
assertNotContains('{{', $effective['sql']);
assertSame('856', $effective['params'][':marcTag']);
```

Repeat for `permanent_item` and `permanent_holdings`, checking their exact join fragments. Add table-driven rejection cases for `000`, `1`, `12`, `1000`, ` 856`, `856 `, `+856`, Arabic-Indic digits, `marctab.mt856`, quotes, comments, and semicolons. Add failures for an invalid UUID, unknown basis, missing/repeated tokens, colon-bearing structural fragments, a missing `mtNNN` table, a missing location, extra/missing/duplicated parameter definitions, and prefix-colliding parameter names.

Create `CatalogingMarcMissingTagReportSqlSemanticsTest.php` with attached in-memory SQLite databases named `inventory` and `marctab`. Seed these controlled cases: a MARC instance containing 856, a MARC instance missing 856, a FOLIO instance missing 856, a null-HRID MARC instance containing 856, a missing-tag instance with two selected-location items, a shared instance held at selected and unselected locations, and an itemless selected-location holding. Execute the three compiled query shapes and assert:

```php
assertSame([], uuidsFor($effectiveRows, 'marc-with-856'));
assertSame(['marc-missing-856'], uuidsFor($effectiveRows, 'marc-missing-856'));
assertSame([], uuidsFor($effectiveRows, 'folio-missing-856'));
assertSame([], uuidsFor($effectiveRows, 'null-hrid-with-856'));
assertSame(1, count(uuidsFor($effectiveRows, 'duplicate-item-instance')));
assertSame(1, count(uuidsFor($effectiveRows, 'shared-selected-instance')));
assertSame([], uuidsFor($effectiveRows, 'itemless-holding-instance'));
assertSame(['itemless-holding-instance'], uuidsFor($permanentHoldingsRows, 'itemless-holding-instance'));
```

- [ ] **Step 2: Run the compiler test to verify it fails**

Run:

```bash
php backend/tests/CatalogingMarcMissingTagReportServiceTest.php
```

Expected: FAIL because the compiler class and optional binder template argument do not exist.

- [ ] **Step 3: Make the binder accept a reviewed SQL override**

Change only the SQL initialization in `ReportTemplate::bindParams()` while preserving every existing caller:

```php
public function bindParams($userInputs, $sqlTemplate = null)
{
    $paramDefs = $this->getDecodedParameters();
    $boundParams = [];
    $sql = $sqlTemplate === null ? $this->sql_template : (string)$sqlTemplate;
    // Existing scalar/list/wrap behavior remains unchanged.
}
```

Add a regression assertion that ordinary calls still append `default_limit`, while an override already containing `LIMIT 100001` keeps exactly that one limit.

- [ ] **Step 4: Implement exact validation and structural resolution**

Implement these constants and public methods in `CatalogingMarcMissingTagReportService`:

```php
final class CatalogingMarcMissingTagReportService
{
    public const REPORT_SLUG = 'marc-bibliographic-records-missing-tag';
    public const LOCATION_TOKEN = '{{location_from}}';
    public const MARC_TABLE_TOKEN = '{{marc_table}}';
    public const PUBLIC_ROW_CAP = 100000;
    public const FETCH_ROW_LIMIT = 100001;

    private const LOCATION_FRAGMENTS = [
        'effective_item' => "FROM inventory.item__t item\nJOIN inventory.holdings_record__t holdings ON holdings.id = item.holdings_record_id\nJOIN inventory.instance__t instance ON instance.id = holdings.instance_id\nJOIN inventory.location__t location ON location.id = item.effective_location_id",
        'permanent_item' => "FROM inventory.item__t item\nJOIN inventory.holdings_record__t holdings ON holdings.id = item.holdings_record_id\nJOIN inventory.instance__t instance ON instance.id = holdings.instance_id\nJOIN inventory.location__t location ON location.id = item.permanent_location_id",
        'permanent_holdings' => "FROM inventory.holdings_record__t holdings\nJOIN inventory.instance__t instance ON instance.id = holdings.instance_id\nJOIN inventory.location__t location ON location.id = holdings.permanent_location_id",
    ];

    public static function supports(ReportTemplate $report): bool;
    public static function build(ReportTemplate $report, array $inputs, $folioDb): array;
}
```

Inside `build()` perform operations in this exact order:

1. Assert the report declares exactly the three expected parameter names, each once, with no prefix collision.
2. Validate the UUID with `preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value)`.
3. Validate the basis with `array_key_exists()` against `LOCATION_FRAGMENTS`.
4. Validate the tag with `preg_match('/^(?:00[1-9]|0[1-9][0-9]|[1-9][0-9]{2})$/D', $tag)`.
5. Require exactly one occurrence of each structural token.
6. Reject a colon in either replacement, replace location first and MARC table second, and reject any remaining `{{...}}` token.
7. Resolve the location using `SELECT name, code FROM inventory.location__t WHERE id = :location_id`; fail if absent.
8. Resolve the table using `SELECT to_regclass(:table_name)` with `marctab.mtNNN`; fail with `Reporting schema is missing the expected MARC tag table marctab.mtNNN.` if null.
9. Call `$report->bindParams($inputs, $resolvedSql)`.
10. Require one top-level `ORDER BY`, one `LIMIT 100001`, no second numeric limit, and no unresolved structural token.

Return the normalized tag and canonical location name/code alongside SQL and PDO params so Task 4 can create a safe filename without trusting client text.

- [ ] **Step 5: Verify policy acceptance and forbidden-source rejection**

Extend `backend/tests/SqlBuilderServicePolicyViolationTest.php` with:

```php
SqlBuilderService::validateSafety($effective['sql']);
SqlBuilderService::validateTablePolicy($effective['sql']);
assertThrowsPolicy('SELECT * FROM folio_source_record.marctab');
assertThrowsPolicy("SELECT jsonb_array_elements(parsed_record__content) FROM folio_source_record.records__t");
```

- [ ] **Step 6: Run focused backend tests**

Run:

```bash
php backend/tests/CatalogingMarcMissingTagReportServiceTest.php
php backend/tests/CatalogingMarcMissingTagReportSqlSemanticsTest.php
php backend/tests/SqlBuilderServicePolicyViolationTest.php
php backend/tests/ReportTemplateHelpTextTest.php
```

Expected: all commands exit 0; the compiler generates exactly three reachable location shapes and one per-tag table.

- [ ] **Step 7: Commit the compiler**

```bash
git add backend/services/CatalogingMarcMissingTagReportService.php backend/models/ReportTemplate.php backend/tests/CatalogingMarcMissingTagReportServiceTest.php backend/tests/CatalogingMarcMissingTagReportSqlSemanticsTest.php backend/tests/SqlBuilderServicePolicyViolationTest.php
git commit -m "feat: compile location-scoped MARC tag reports"
```

---

### Task 3: Define reusable report execution metadata

**Files:**
- Create: `backend/services/ReportExecutionContractService.php`
- Modify: `backend/models/ReportTemplate.php`
- Modify: `backend/models/QueryJob.php`
- Create: `backend/tests/ReportExecutionContractServiceTest.php`
- Create: `backend/tests/QueryJobReportExecutionMetadataTest.php`

**Interfaces:**
- Produces: `ReportTemplate::getExecutionConfig(): ?array`.
- Produces: `ReportTemplate::hasIdentifierExport(): bool` and API field `identifierExportAvailable`.
- Produces: `ReportExecutionContractService::fromReport(ReportTemplate $report, array $context): ?array`.
- Produces: `ReportExecutionContractService::fromJob(QueryJob $job): ?array`.
- Produces: `ReportExecutionContractService::trimRows(array $rows, array $contract): array` returning `rows` and `truncated`.
- Produces: `ReportExecutionContractService::assertStaticExportSql(string $sql, array $contract): string`.
- Produces: `QueryJob::getDecodedMetadata(): array` and optional `$truncated` arguments on completion methods.

- [ ] **Step 1: Write failing contract and serialization tests**

Assert the canonical contract generated from Task 1 metadata is:

```php
[
    'reportTemplateId' => 38,
    'reportSlug' => 'marc-bibliographic-records-missing-tag',
    'publicRowCap' => 100000,
    'fetchRowLimit' => 100001,
    'preserveExportOrder' => true,
    'exportKind' => 'worklist',
    'identifierExport' => [
        'sourceColumn' => 'Instance UUID',
        'header' => 'UUID',
    ],
    'downloadFilename' => 'marc-bibliographic-records-missing-tag-856-sc-main-worklist.csv',
]
```

Test rejection of cap/fetch pairs other than `fetch = cap + 1`, caps above 100,000, missing identifier fields, client-selected source columns, unsafe filenames, and `preserveExportOrder !== true`. Test that 100,000 input rows yield `truncated = false`, while 100,001 yield 100,000 retained rows and `truncated = true`. Feed `assertStaticExportSql()` a valid ordered `LIMIT 100001` query, then verify it rejects a missing order, a second limit, `LIMIT :limit`, `LIMIT 100000`, and a clause after the sentinel.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php backend/tests/ReportExecutionContractServiceTest.php
php backend/tests/QueryJobReportExecutionMetadataTest.php
```

Expected: FAIL because report/job execution metadata is not decoded or exposed.

- [ ] **Step 3: Implement strict config-to-job canonicalization**

Create `ReportExecutionContractService` with these signatures:

```php
final class ReportExecutionContractService
{
    public const METADATA_KEY = 'reportExecution';

    public static function fromReport(ReportTemplate $report, array $context): ?array;
    public static function fromJob(QueryJob $job): ?array;
    public static function trimRows(array $rows, array $contract): array;
    public static function assertStaticExportSql(string $sql, array $contract): string;
    public static function updateMetadata(array $metadata, array $contract, bool $truncated): array;
}
```

`fromReport()` accepts only `exportKind` values `worklist` and `identifier`, derives the source column/header only from stored `execution_config`, and builds `downloadFilename` from the compiler's normalized slug, tag, location code/name, and export kind. Normalize filename components with lowercase ASCII letters/digits/hyphens and end worklists with `-worklist.csv` and identifier files with `-folio-uuids.csv` server-side.

`assertStaticExportSql()` uses `SqlSelectStructureService::tokenizeForAnalysis()` to require exactly one depth-zero `ORDER BY` pair and one depth-zero `LIMIT` followed by the configured numeric `fetchRowLimit`. It returns the SQL unchanged. It rejects extra top-level ordering/limits, a parameterized limit, trailing clauses after the sentinel, and any mismatch between SQL and contract; it never rewrites governed SQL.

- [ ] **Step 4: Add ReportTemplate and QueryJob helpers**

Retain the `CAT_CATALOGING` validation added in Task 1 and expose only capability—not the protected source column—to clients:

```php
'identifierExportAvailable' => $this->hasIdentifierExport(),
```

Add to `QueryJob`:

```php
public function getDecodedMetadata(): array;
public function markCompleted($columns, $rows, $executionTimeMs, $truncated = false);
public function markExportCompleted($filePath, $rowCount, $executionTimeMs, array $previewColumns = [], array $previewRows = [], $truncated = false);
```

Both completion methods update `metadata.reportExecution.truncated` in the same save. `toStatusArray()` returns `truncated` only as a boolean and never exposes the identifier source-column config.

- [ ] **Step 5: Run focused metadata tests**

```bash
php backend/tests/ReportExecutionContractServiceTest.php
php backend/tests/QueryJobReportExecutionMetadataTest.php
php backend/tests/QueryJobLongNameTest.php
```

Expected: all commands exit 0; ordinary jobs without `reportExecution` serialize exactly as before.

- [ ] **Step 6: Commit execution metadata**

```bash
git add backend/services/ReportExecutionContractService.php backend/models/ReportTemplate.php backend/models/QueryJob.php backend/tests/ReportExecutionContractServiceTest.php backend/tests/QueryJobReportExecutionMetadataTest.php
git commit -m "feat: add governed report execution contracts"
```

---

### Task 4: Compile and preflight the report-run request

**Files:**
- Modify: `backend/controllers/FolioQueryController.php`
- Create: `backend/tests/FolioQueryControllerCatalogingReportTest.php`

**Interfaces:**
- Consumes: `CatalogingMarcMissingTagReportService::build()` from Task 2.
- Consumes: `ReportExecutionContractService::fromReport()` from Task 3.
- Produces: `POST /api/reports/<id>/run` accepting optional `exportKind: 'worklist'|'identifier'` and returning the actual `outputMode`.
- Produces: report jobs with canonical `metadata.reportExecution` and preflight estimates.

- [ ] **Step 1: Write controller tests for compilation, authorization, and routing**

Following the stubbing pattern in `FolioQueryControllerExecutePreflightTest.php`, test these cases:

```php
$small = runCatalogingReport([
    'params' => validCatalogingParams(),
    'outputMode' => 'table',
]);
assertSame('table', $small['outputMode']);
assertSame('marctab.mt856', capturedMarcTable());

$large = runCatalogingReportWithEstimate(10001, 1000.0, [
    'params' => validCatalogingParams(),
    'outputMode' => 'table',
]);
assertSame('file', $large['outputMode']);

$identifier = runCatalogingReport([
    'params' => validCatalogingParams(),
    'outputMode' => 'file',
    'exportKind' => 'identifier',
]);
assertSame('identifier', savedReportExecution()['exportKind']);
```

Also assert: unknown export kind returns 400; identifier export on a report without capability returns 400; invalid tag/basis/location returns 400 without creating a job; missing `mtNNN` returns 422 with the safe integrity message; preflight failure returns 422; safety and table-policy validation both run; estimated rows/cost are saved; ordinary reports retain the existing binder path.

- [ ] **Step 2: Run the controller test to verify it fails**

```bash
php backend/tests/FolioQueryControllerCatalogingReportTest.php
```

Expected: FAIL because `actionReportRun()` neither compiles structural tokens nor preflights fixed reports.

- [ ] **Step 3: Add the specialized compiler branch without weakening ordinary reports**

In `actionReportRun()`:

```php
if (CatalogingMarcMissingTagReportService::supports($report)) {
    $compiled = CatalogingMarcMissingTagReportService::build($report, $userParams, Yii::$app->folioDb);
    $bound = ['sql' => $compiled['sql'], 'params' => $compiled['params']];
} else {
    $bound = $report->bindParams($userParams);
    $bound['sql'] = $this->normalizeLegacyReportSql($report, $bound['sql']);
}
```

Run both `SqlBuilderService::validateSafety()` and `SqlBuilderService::validateTablePolicy()` after compilation. Translate compiler input errors to HTTP 400, missing-table/location integrity errors to HTTP 422, and never return raw database exception text.

- [ ] **Step 4: Add fixed-report preflight routing and canonical job metadata**

For FOLIO reports with an execution contract, call the existing `estimateQueryComplexity($sql, 'folio', $params)`. If estimated rows exceed `exportRowThreshold` or estimated cost exceeds `exportCostThreshold`, force `outputMode = 'file'`. An explicit `exportKind = 'identifier'` also forces file mode. Save estimated values using the same overflow protection as `actionQuerySubmit()`.

Construct metadata only after compilation:

```php
$metadata = [
    ReportExecutionContractService::METADATA_KEY =>
        ReportExecutionContractService::fromReport($report, [
            'exportKind' => $exportKind,
            'marcTag' => $compiled['marcTag'],
            'locationName' => $compiled['location']['name'],
            'locationCode' => $compiled['location']['code'],
        ]),
];
```

Merge rather than overwrite `composite_config` metadata for unrelated report types.

- [ ] **Step 5: Run controller and preflight regressions**

```bash
php backend/tests/FolioQueryControllerCatalogingReportTest.php
php backend/tests/FolioQueryControllerExecutePreflightTest.php
php backend/tests/SqlPreflightServiceTest.php
```

Expected: all commands exit 0; only governed fixed reports gain automatic file routing.

- [ ] **Step 6: Commit the report-run integration**

```bash
git add backend/controllers/FolioQueryController.php backend/tests/FolioQueryControllerCatalogingReportTest.php
git commit -m "feat: preflight MARC cataloging report jobs"
```

---

### Task 5: Enforce the sentinel in table-mode jobs

**Files:**
- Modify: `backend/commands/QueryWorkerController.php`
- Modify: `backend/services/ReportExecutionContractService.php`
- Modify: `backend/tests/QueryWorkerConcurrencyTest.php`
- Create: `backend/tests/QueryWorkerReportCapTest.php`

**Interfaces:**
- Consumes: `ReportExecutionContractService::fromJob()` and `trimRows()` from Task 3.
- Produces: table job results containing at most 100,000 rows with exact `truncated` job metadata.

- [ ] **Step 1: Write a table-worker sentinel regression test**

Build an in-memory query job with `metadata.reportExecution.publicRowCap = 2` and `fetchRowLimit = 3` so the test stays small. Feed the worker three ordered rows and assert:

```php
assertSame(2, $savedJob->row_count);
assertSame(['instance-1', 'instance-2'], array_column($savedJob->getDecodedRows(), 'Instance UUID'));
assertTrue($savedJob->toStatusArray(true)['truncated']);
```

Repeat with two rows and assert `truncated === false`. Add an ordinary job with three rows and no contract and assert all three remain, proving generic behavior is unchanged.

- [ ] **Step 2: Run the table-worker test to verify it fails**

```bash
php backend/tests/QueryWorkerReportCapTest.php
```

Expected: FAIL because the worker currently persists every returned row and no truncation state.

- [ ] **Step 3: Trim only governed report results before persistence**

Immediately after `queryAll()` and cancellation checks:

```php
$contract = ReportExecutionContractService::fromJob($job);
$truncated = false;
if ($contract !== null) {
    $trimmed = ReportExecutionContractService::trimRows($rows, $contract);
    $rows = $trimmed['rows'];
    $truncated = $trimmed['truncated'];
}

$job->markCompleted($columns, $rows, $executionTime, $truncated);
```

Derive `$columns` after trimming but preserve the SQL column metadata for an empty result as the current worker does. Do not mutate SQL or generic limits in this worker.

- [ ] **Step 4: Run worker regressions**

```bash
php backend/tests/QueryWorkerReportCapTest.php
php backend/tests/QueryWorkerConcurrencyTest.php
php backend/tests/QueryWorkerCancellationTest.php
```

Expected: all commands exit 0; cancellation and concurrency behavior remain unchanged.

- [ ] **Step 5: Commit table-worker enforcement**

```bash
git add backend/commands/QueryWorkerController.php backend/services/ReportExecutionContractService.php backend/tests/QueryWorkerReportCapTest.php backend/tests/QueryWorkerConcurrencyTest.php
git commit -m "feat: enforce report row caps in table jobs"
```

---

### Task 6: Stream worklist and FOLIO UUID exports correctly

**Files:**
- Create: `backend/services/FolioIdentifierCsvService.php`
- Modify: `backend/commands/ExportWorkerController.php`
- Modify: `backend/controllers/FolioQueryController.php`
- Create: `backend/tests/FolioIdentifierCsvServiceTest.php`
- Create: `backend/tests/ExportWorkerReportContractTest.php`
- Modify: `backend/tests/QueryHistoryDeletionServiceTest.php`

**Interfaces:**
- Produces: `FolioIdentifierCsvService::project(array $row, array $config): ?string`.
- Produces: `FolioIdentifierCsvService::encodeRow(array $fields): string` with CRLF.
- Consumes: `metadata.reportExecution.exportKind`, `identifierExport`, caps, ordering, filename, and sentinel.
- Produces: `GET /api/query/export/<id>` using the server-owned download filename.

- [ ] **Step 1: Write identifier CSV tests**

Test valid UUID normalization, blank rejection, malformed UUID rejection, duplicate suppression at the worker layer, quoting, embedded quotes, and CRLF:

```php
$line = FolioIdentifierCsvService::encodeRow(['UUID']);
assertSame("UUID\r\n", $line);

$quoted = FolioIdentifierCsvService::encodeRow(['a,b"c']);
assertSame('"a,b""c"' . "\r\n", $quoted);

assertSame(
    '11111111-1111-4111-8111-111111111111',
    FolioIdentifierCsvService::project(
        ['Instance UUID' => '11111111-1111-4111-8111-111111111111'],
        ['sourceColumn' => 'Instance UUID', 'header' => 'UUID']
    )
);
```

- [ ] **Step 2: Write export-worker contract tests**

Using a cap of two/fetch limit of three, assert:

- worklist mode writes the six original headers and only two data rows;
- identifier mode writes `UUID` plus deduplicated valid identifiers only;
- the third source row sets `truncated = true` even when identifier deduplication produces fewer than two output identifiers;
- contract SQL retains top-level `ORDER BY ... LIMIT 3`;
- ordinary export SQL still strips top-level ordering and replaces/appends the generic export limit;
- cancellation removes partial files;
- previews match the exported shape; and
- CRLF appears on every identifier CSV row.

- [ ] **Step 3: Run export tests to verify they fail**

```bash
php backend/tests/FolioIdentifierCsvServiceTest.php
php backend/tests/ExportWorkerReportContractTest.php
```

Expected: FAIL because export jobs currently replace the report limit, remove ordering, write LF with all columns, and cannot persist truncation.

- [ ] **Step 4: Implement the identifier projection and PHP 7.2 CSV encoder**

Use an RFC-4180-compatible encoder instead of the PHP 8.1-only `fputcsv(..., $eol)` argument:

```php
public static function encodeRow(array $fields): string
{
    $encoded = array_map(function ($value) {
        $value = (string)$value;
        if (strpbrk($value, ",\"\r\n") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }, $fields);

    return implode(',', $encoded) . "\r\n";
}
```

`project()` reads only the configured server-owned source column and accepts an RFC-4122-shaped UUID. It does not accept a client column name.

- [ ] **Step 5: Make the export worker contract-aware**

Split the current SQL policy into:

```php
private function prepareExportSql(QueryJob $job, int $genericMaxRows): string
{
    $contract = ReportExecutionContractService::fromJob($job);
    if ($contract !== null) {
        return ReportExecutionContractService::assertStaticExportSql($job->sql_text, $contract);
    }
    return $this->applyExportLimit($job->sql_text, $genericMaxRows);
}
```

For governed jobs, stream source rows in SQL order, stop after consuming `fetchRowLimit`, never write the sentinel row, and set truncation from source-row count before identifier projection. Worklist mode retains current `fputcsv` behavior and six-column shape. Identifier mode writes `encodeRow(['UUID'])`, projects/deduplicates with an associative UUID set, writes `encodeRow([$uuid])`, and stores a UUID-only preview. Pass `$truncated` to `markExportCompleted()`.

- [ ] **Step 6: Use the protected download filename**

In `actionQueryExport()`, use `metadata.reportExecution.downloadFilename` only after `ReportExecutionContractService` has validated it; otherwise retain `basename($path)`. Never accept a filename query/body parameter.

- [ ] **Step 7: Run export, cancellation, and cleanup tests**

```bash
php backend/tests/FolioIdentifierCsvServiceTest.php
php backend/tests/ExportWorkerReportContractTest.php
php backend/tests/QueryWorkerCancellationTest.php
php backend/tests/QueryHistoryDeletionServiceTest.php
php backend/tests/FolioQueryControllerHistoryDeletionTest.php
```

Expected: all commands exit 0; governed files enforce the report contract and ordinary exports remain byte/limit compatible with current behavior.

- [ ] **Step 8: Commit export support**

```bash
git add backend/services/FolioIdentifierCsvService.php backend/commands/ExportWorkerController.php backend/controllers/FolioQueryController.php backend/tests/FolioIdentifierCsvServiceTest.php backend/tests/ExportWorkerReportContractTest.php backend/tests/QueryHistoryDeletionServiceTest.php
git commit -m "feat: export cataloging worklists and FOLIO UUIDs"
```

---

### Task 7: Add Cataloging and MARC parameter UX

**Files:**
- Modify: `frontend/src/types/schema.ts`
- Modify: `frontend/src/utils/reports.ts`
- Modify: `frontend/src/components/ParamInput.tsx`
- Modify: `frontend/src/pages/Reports.test.tsx`
- Create: `frontend/src/components/ParamInput.marcTag.test.tsx`

**Interfaces:**
- Produces: `ReportCategory` value `cataloging` and label `Cataloging`.
- Produces: optional `ReportParam.input_mode`, `pattern`, and `max_length` fields mapped to safe input attributes.
- Consumes: the parameter metadata and `identifierExportAvailable` capability returned by the backend.

- [ ] **Step 1: Write failing category and input tests**

Add a Cataloging report fixture to `Reports.test.tsx` and assert a `Cataloging` heading appears. In the new parameter test:

```tsx
render(
  <ParamInput
    param={{
      name: 'marcTag', type: 'text', label: 'MARC tag', required: true,
      default: '', resolvedDefault: '', input_mode: 'numeric',
      pattern: '[0-9]{3}', max_length: 3,
    }}
    value=""
    onChange={vi.fn()}
  />,
);

const input = screen.getByLabelText(/MARC tag/i);
expect(input).toHaveAttribute('inputmode', 'numeric');
expect(input).toHaveAttribute('pattern', '[0-9]{3}');
expect(input).toHaveAttribute('maxlength', '3');
```

- [ ] **Step 2: Run frontend tests to verify they fail**

```bash
cd frontend && npm test -- --run src/pages/Reports.test.tsx src/components/ParamInput.marcTag.test.tsx
```

Expected: FAIL because `cataloging` and the optional input metadata are not typed or rendered.

- [ ] **Step 3: Add types, label, and safe HTML attributes**

Extend `ReportCategory` with `cataloging`, insert `{ key: 'cataloging', label: 'Cataloging' }` before `other`, and add:

```ts
input_mode?: 'numeric';
pattern?: '[0-9]{3}';
max_length?: 3;
```

Map them in `ParamInput`:

```tsx
const inputId = `report-param-${param.name}`;

// Add to the existing label:
htmlFor={inputId}

// Add to the existing input:
id={inputId}
inputMode={param.input_mode}
pattern={param.pattern}
maxLength={param.max_length}
```

Apply the same `id={inputId}` to the select and textarea branches so every report parameter has an accessible label. These browser hints improve entry but do not replace Task 2 server validation.

- [ ] **Step 4: Run focused UI tests and typecheck**

```bash
cd frontend && npm test -- --run src/pages/Reports.test.tsx src/components/ParamInput.marcTag.test.tsx
cd frontend && npm run build
```

Expected: tests pass and TypeScript/Vite build exits 0.

- [ ] **Step 5: Commit Cataloging parameter UX**

```bash
git add frontend/src/types/schema.ts frontend/src/utils/reports.ts frontend/src/components/ParamInput.tsx frontend/src/pages/Reports.test.tsx frontend/src/components/ParamInput.marcTag.test.tsx
git commit -m "feat: add cataloging report parameter UX"
```

---

### Task 8: Add identifier-export controls and truncation feedback

**Files:**
- Modify: `frontend/src/types/schema.ts`
- Modify: `frontend/src/api/client.ts`
- Modify: `frontend/src/pages/ReportDetail.tsx`
- Modify: `frontend/src/hooks/useJobPolling.ts`
- Modify: `frontend/src/components/ResultsTable.tsx`
- Modify: `frontend/src/pages/Reports.test.tsx`
- Modify: `frontend/src/hooks/useJobPolling.test.tsx`
- Create: `frontend/src/components/ResultsTable.truncation.test.tsx`

**Interfaces:**
- Produces: `ReportExportKind = 'worklist'|'identifier'`.
- Produces: optional `identifierExportAvailable` on report detail.
- Produces: optional `truncated` on job status and execute results.
- Consumes: `POST /reports/:id/run` export-kind support and `GET /query/status/:id` truncation state.

- [ ] **Step 1: Write failing API/UI tests**

Test that a report with `identifierExportAvailable: true` shows all three actions and submits the protected kind:

```tsx
fireEvent.click(await screen.findByRole('button', { name: /Export FOLIO UUID list/i }));
expect(runReport).toHaveBeenCalledWith(reportId, expectedParams, {
  outputMode: 'file',
  exportKind: 'identifier',
});
```

Test that reports without the capability do not show that button. Extend `useJobPolling.test.tsx` so completed status with `truncated: true` produces `results.truncated === true`. In `ResultsTable.truncation.test.tsx`, assert both inline and file-mode results render:

```text
This report reached its 100,000-row cap. Narrow the location to retrieve the remaining records.
```

and that file mode keeps the Download Full CSV action visible beside the warning.

- [ ] **Step 2: Run UI tests to verify they fail**

```bash
cd frontend && npm test -- --run src/pages/Reports.test.tsx src/hooks/useJobPolling.test.tsx src/components/ResultsTable.truncation.test.tsx
```

Expected: FAIL because export kind/capability/truncation are not represented.

- [ ] **Step 3: Extend the API and polling types**

Add:

```ts
export type ReportExportKind = 'worklist' | 'identifier';

export interface ExecuteResponse {
  truncated?: boolean;
}

export interface JobStatusResponse {
  truncated?: boolean;
}
```

Change `runReport()` options and payload to include `exportKind?: ReportExportKind`. In `useJobPolling()`, copy `status.truncated` into both table and file `ExecuteResponse` objects.

- [ ] **Step 4: Add the governed export action and warning**

Change `handleRun` to accept an optional export kind. Keep **Export CSV** as `{ outputMode: 'file', exportKind: 'worklist' }`. Render **Export FOLIO UUID list** only when `report.identifierExportAvailable` is true, and submit `{ outputMode: 'file', exportKind: 'identifier' }`.

Render the truncation warning in `ResultsTable` above the table/file preview:

```tsx
{data.truncated && (
  <div role="alert" className="mb-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
    This report reached its 100,000-row cap. Narrow the location to retrieve the remaining records.
  </div>
)}
```

- [ ] **Step 5: Run focused UI tests, full frontend tests, and build**

```bash
cd frontend && npm test -- --run src/pages/Reports.test.tsx src/hooks/useJobPolling.test.tsx src/components/ResultsTable.truncation.test.tsx
cd frontend && npm test
cd frontend && npm run build
```

Expected: focused and full Vitest suites pass; TypeScript/Vite build exits 0.

- [ ] **Step 6: Commit report export UI**

```bash
git add frontend/src/types/schema.ts frontend/src/api/client.ts frontend/src/pages/ReportDetail.tsx frontend/src/hooks/useJobPolling.ts frontend/src/components/ResultsTable.tsx frontend/src/pages/Reports.test.tsx frontend/src/hooks/useJobPolling.test.tsx frontend/src/components/ResultsTable.truncation.test.tsx
git commit -m "feat: expose FOLIO UUID cataloging exports"
```

---

### Task 9: Verify migrations, query behavior, performance, and FOLIO workflow

**Files:**
- Create: `backend/tests/CatalogingMarcMissingTagReportPostgresTest.php`
- Create: `docs/superpowers/implementation-reports/2026-08-06-marc-missing-tag-report.md`
- Modify only if verification finds a defect: files from Tasks 1–8 and their focused tests.

**Interfaces:**
- Consumes: the complete report, compiler, worker, API, and UI contracts.
- Produces: live PostgreSQL evidence for all three location bases and tags 245/856, plus a FOLIO Data Export smoke-test record.

- [ ] **Step 1: Add an opt-in live PostgreSQL contract test**

Create a test that exits successfully with `SKIP` unless `RUN_FOLIO_DB_TESTS=1`. When enabled, it must:

1. Verify `to_regclass('marctab.mt245')` and `to_regclass('marctab.mt856')` are non-null.
2. Compile all six basis/tag combinations using a real active location UUID.
3. Run `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` inside a read-only transaction with the configured statement timeout.
4. Assert each plan touches exactly one `marctab.mtNNN`, contains no `folio_source_record.marctab`, and returns no more than 100,001 rows.
5. Execute tag-presence fixtures where available and verify the anti-join by Instance UUID.

The command is:

```bash
RUN_FOLIO_DB_TESTS=1 php backend/tests/CatalogingMarcMissingTagReportPostgresTest.php
```

- [ ] **Step 2: Run the complete offline verification suite**

Run every standalone backend test so hidden coupling in the large controller/model files is caught:

```bash
set -e
for test_file in backend/tests/*Test.php; do php "$test_file"; done
cd frontend && npm test
cd frontend && npm run lint
cd frontend && npm run build
```

Expected: every command exits 0 with no PHP assertion failures, Vitest failures, ESLint errors, or TypeScript/Vite build errors.

- [ ] **Step 3: Exercise migration idempotency in Docker**

Run:

```bash
docker compose exec -T php php yii migration/audit
docker compose exec -T php php yii migration/run
docker compose exec -T php php yii migration/run
```

Expected: the first run applies or recognizes migration 040; the second run reports it skipped with no checksum drift or duplicate migration number.

- [ ] **Step 4: Capture representative database evidence**

Run the opt-in PostgreSQL test against:

- the largest active location available locally and a small active location;
- each location basis;
- common tag 245 and sparse tag 856.

Record in the implementation report for every run: location UUID/name/code, basis, tag, planning time, execution time, returned rows, shared buffer hits/reads, whether the 100,001st row existed, statement timeout, and relevant index names. State explicitly whether the 100,000-row workload is acceptable; do not enable the report if any representative run exceeds the configured timeout.

- [ ] **Step 5: Verify both export shapes and truncation metadata**

Through the report page:

1. Run a small location in table mode and compare worklist results with direct SQL.
2. Download the six-column worklist and verify its header order.
3. Download the UUID export and verify a single `UUID` header, CRLF endings, valid/deduplicated UUIDs, and deterministic order.
4. Run a controlled test fixture with exactly 100,000 candidates and verify no warning.
5. Run a controlled fixture with 100,001 candidates and verify both file and table job status publish 100,000 rows with `truncated = true`.
6. Confirm the warning appears beside download actions and neither CSV contains a warning row.

- [ ] **Step 6: Smoke-test the UUID file in the deployed FOLIO Data Export release**

Upload a small generated UUID CSV to the institution's normal Data Export job profile. Record the FOLIO release/profile name, accepted row count, rejected row count, and whether the exported MARC record count matches valid input UUIDs. Do not enable the production report if the one-column file is rejected.

- [ ] **Step 7: Write the implementation report**

Create `docs/superpowers/implementation-reports/2026-08-06-marc-missing-tag-report.md` with these concrete headings:

```markdown
# MARC Missing-Tag Report Implementation Evidence

## Versions and Configuration
## Migration and Seed Verification
## Query Plans by Location Basis and Tag
## 100,000-Row Cap and Worker Behavior
## Worklist CSV Verification
## FOLIO UUID CSV Verification
## FOLIO Data Export Smoke Test
## Required Indexes or Deployment Actions
## Release Decision
```

Every table in the report must contain the measured values from Steps 3–6; use `Not run — production authorization required` only for a production-only smoke test, and keep the release decision blocked until that row is replaced by evidence.

- [ ] **Step 8: Review the complete diff against the design**

Run:

```bash
git diff --check
rg -n 'folio_source_record\.marctab|parsed_record__content' mysql/migrations/040_cataloging_marc_missing_tag_report.sql backend/services/CatalogingMarcMissingTagReportService.php
rg -n 'LIMIT 100001|instance_id = target_instances\.instance_uuid|instance\.source = .MARC.' mysql/migrations/040_cataloging_marc_missing_tag_report.sql
git status --short
```

Expected: `git diff --check` is silent; the forbidden-source scan finds nothing; the required-contract scan finds the static limit, UUID anti-join, and exact MARC source predicate; status shows only intended implementation/report files plus the user's pre-existing unrelated changes.

- [ ] **Step 9: Commit verification evidence**

```bash
git add backend/tests/CatalogingMarcMissingTagReportPostgresTest.php docs/superpowers/implementation-reports/2026-08-06-marc-missing-tag-report.md
git commit -m "test: verify MARC missing-tag report workflow"
```

---

## Final Acceptance Checklist

- [ ] Migration 040 applies twice safely and deployment-current recognition cannot baseline an incomplete schema.
- [ ] Cataloging appears in MySQL, backend validation, frontend types, report grouping, and generation controls.
- [ ] The compiler accepts only three location bases and tags 001–999.
- [ ] Every generated query uses one `marctab.mtNNN`, `instance.source = 'MARC'`, and UUID-to-UUID anti-join.
- [ ] Static SQL has exactly one `ORDER BY` and one `LIMIT 100001`; the binder adds no second limit.
- [ ] Small table jobs and large file jobs publish the same cap/truncation semantics.
- [ ] Ordinary queries and exports retain current 10,000/500,000 limit and order-removal behavior.
- [ ] Worklist exports retain six columns; identifier exports contain one CRLF `UUID` column.
- [ ] Identifier source column and filename remain server-owned.
- [ ] Truncation is visible for table and file jobs but absent from CSV rows.
- [ ] All offline backend tests, frontend tests, lint, and build pass.
- [ ] All six live basis/tag plans complete within the configured timeout.
- [ ] A generated UUID file is accepted by the deployed FOLIO Data Export profile.
- [ ] The implementation report contains measured evidence and an explicit release decision.
