# MARC Missing-Tag Multi-Location Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a searchable, multiple-location selector to the MARC missing-tag report while returning and exporting each matching instance once.

**Architecture:** Add an opt-in `multiselect` report parameter and a focused React control that serializes selected values through the existing string parameter boundary. Update the governed cataloging compiler and seed through a forward-only migration so validated UUIDs are bound as one PostgreSQL array input and matching locations are aggregated before the MARC anti-join.

**Tech Stack:** React 18, TypeScript, Testing Library/Vitest, Yii2/PHP 7.2, MySQL migrations, PostgreSQL reporting schema, Docker Compose.

## Global Constraints

- Use `inventory.location__t` as the authoritative location table.
- Derive campus through `inventory.loclibrary__t.campus_id`.
- Include active locations only.
- Accept 1 through 100 unique RFC-4122 location UUIDs.
- Never interpolate client-supplied identifiers into SQL.
- Return and export every matching Instance UUID once.
- Do not edit applied migrations 040 or 041.
- Add no frontend runtime dependency.
- Preserve the 100,000 public row cap and 100,001 sentinel.

---

### Task 1: Searchable Multi-Select Parameter Control

**Files:**
- Create: `frontend/src/components/SearchableMultiSelect.tsx`
- Create: `frontend/src/components/SearchableMultiSelect.test.tsx`
- Modify: `frontend/src/components/ParamInput.tsx`
- Modify: `frontend/src/types/schema.ts`

**Interfaces:**
- Consumes: `options: Array<{value: string; label: string}>`, a comma-separated `value`, and `onChange(value: string)`.
- Produces: `SearchableMultiSelect` and `ReportParam.type === 'multiselect'` support without changing ordinary selects.

- [ ] **Step 1: Write failing component tests**

Cover search, multi-selection, chip removal, clear-all, empty results, the 100-selection limit, and serialization:

```tsx
render(
  <SearchableMultiSelect
    id="report-param-locationIds"
    label="Location"
    value=""
    options={[
      { value: SCINT_UUID, label: 'Smith College — SC Neilson Library — SC Internet [SCINT]' },
      { value: SNDVD_UUID, label: 'Smith College — SC Neilson Library — SC Neilson DVD [SNDVD]' },
    ]}
    maxSelections={100}
    onChange={onChange}
  />,
);

await user.click(screen.getByRole('button', { name: /select locations/i }));
await user.type(screen.getByRole('searchbox', { name: /search locations/i }), 'scint');
await user.click(screen.getByRole('checkbox', { name: /sc internet/i }));
expect(onChange).toHaveBeenCalledWith(SCINT_UUID);
```

- [ ] **Step 2: Run the component test and verify RED**

Run:

```bash
cd frontend && npm test -- --run src/components/SearchableMultiSelect.test.tsx
```

Expected: FAIL because `SearchableMultiSelect` and `multiselect` rendering do not exist.

- [ ] **Step 3: Implement the minimal reusable control**

Implement a controlled component that parses and serializes values without changing their order:

```ts
const selectedValues = value.split(',').map((item) => item.trim()).filter(Boolean);
const emit = (next: string[]) => onChange(next.join(','));
const filtered = options.filter((option) =>
  option.label.toLocaleLowerCase().includes(search.toLocaleLowerCase()),
);
```

Render an accessible toggle button, search input, checkbox option list, selected chips, selected count, clear-all button, no-results message, and max-selection message. Close on Escape. Disable unselected checkboxes at 100 while leaving selected entries removable.

Extend `ReportParam.type`:

```ts
type: 'date' | 'text' | 'select' | 'multiselect' | 'number' | 'boolean' | 'list';
max_selections?: number;
```

Have `ParamInput` delegate `multiselect` parameters to the new component and preserve its existing label associations for all other types.

- [ ] **Step 4: Run focused frontend tests and verify GREEN**

Run:

```bash
cd frontend && npm test -- --run src/components/SearchableMultiSelect.test.tsx src/components/ParamInput.marcTag.test.tsx
```

Expected: all focused tests PASS without React warnings.

- [ ] **Step 5: Commit the UI unit**

```bash
git add frontend/src/components/SearchableMultiSelect.tsx frontend/src/components/SearchableMultiSelect.test.tsx frontend/src/components/ParamInput.tsx frontend/src/types/schema.ts
git commit -m "feat: add searchable report multi-select"
```

### Task 2: Governed Multi-Location SQL Contract

**Files:**
- Modify: `backend/services/CatalogingMarcMissingTagReportService.php`
- Modify: `backend/models/ReportTemplate.php`
- Modify: `backend/controllers/FolioQueryController.php`
- Modify: `backend/tests/CatalogingMarcMissingTagReportServiceTest.php`
- Modify: `backend/tests/FolioQueryControllerCatalogingReportTest.php`

**Interfaces:**
- Consumes: `locationIds` as a comma-separated string and the existing `locationBasis` and `marcTag` values.
- Produces: a compiled query with one bound `:locationIds` value plus deterministic `location` summary metadata for filenames.

- [ ] **Step 1: Write failing compiler tests**

Change the fixture input to:

```php
[
    'locationIds' => implode(',', [$mainUuid, $scienceUuid]),
    'locationBasis' => 'effective_item',
    'marcTag' => '856',
]
```

Assert that compilation:

```php
marcCompilerAssertSame(
    $mainUuid . ',' . $scienceUuid,
    $compiled['params'][':locationIds'],
    'Validated location UUIDs must remain one bound value.'
);
marcCompilerAssertContains(
    "location.id = ANY(string_to_array(:locationIds, ',')::uuid[])",
    $compiled['sql'],
    'Selected locations must use a bound PostgreSQL UUID array.'
);
marcCompilerAssertSame('MULTI', $compiled['location']['code'], 'Multiple locations need stable filename metadata.');
```

Add rejection cases for empty values, malformed UUIDs, duplicates, 101 UUIDs, and a valid UUID absent from the lookup result.

- [ ] **Step 2: Run compiler/controller tests and verify RED**

Run:

```bash
php backend/tests/CatalogingMarcMissingTagReportServiceTest.php
php backend/tests/FolioQueryControllerCatalogingReportTest.php
```

Expected: FAIL because the compiler still requires singular `locationId` and singular metadata.

- [ ] **Step 3: Implement list validation and canonical binding**

Change the required parameter names to:

```php
private const EXPECTED_PARAMETER_NAMES = ['locationIds', 'locationBasis', 'marcTag'];
private const MAX_LOCATION_SELECTIONS = 100;
```

Parse only comma-delimited input, trim each value, require 1–100 entries, validate each with the existing UUID regex, and reject duplicates. Query all selected locations using generated server-owned placeholders:

```sql
SELECT id::text AS id, name, code
FROM inventory.location__t
WHERE id IN (:location_lookup_0, :location_lookup_1)
ORDER BY name, code, id
```

Compare returned IDs with the validated selection and reject missing rows. Pass `implode(',', $locationIds)` into `ReportTemplate::bindParams`. Return the real location for one selection or this summary for several:

```php
[
    'id' => implode(',', $locationIds),
    'name' => count($locationIds) . ' Locations',
    'code' => 'MULTI',
]
```

Update `ReportTemplate::fetchSelectOptions()` so both `select` and `multiselect` parameters execute their fixed `options_sql`. Update the controller to keep using compiler-owned summary metadata, never client labels.

- [ ] **Step 4: Run focused backend tests and verify GREEN**

Run:

```bash
php backend/tests/CatalogingMarcMissingTagReportServiceTest.php
php backend/tests/FolioQueryControllerCatalogingReportTest.php
php backend/tests/ReportExecutionContractServiceTest.php
```

Expected: all focused tests PASS.

- [ ] **Step 5: Commit the backend contract unit**

```bash
git add backend/services/CatalogingMarcMissingTagReportService.php backend/models/ReportTemplate.php backend/controllers/FolioQueryController.php backend/tests/CatalogingMarcMissingTagReportServiceTest.php backend/tests/FolioQueryControllerCatalogingReportTest.php
git commit -m "feat: compile MARC reports for multiple locations"
```

### Task 3: Forward Migration and SQL Deduplication

**Files:**
- Create: `mysql/migrations/042_cataloging_marc_multi_location.sql`
- Modify: `backend/tests/CatalogingMarcMissingTagReportMigrationTest.php`
- Modify: `backend/tests/CatalogingMarcMissingTagReportSqlSemanticsTest.php`
- Modify: `backend/tests/CatalogingMarcMissingTagReportPostgresTest.php`
- Modify: `backend/services/CatalogingMarcMissingTagReportService.php`

**Interfaces:**
- Consumes: canonical migration 042 definition and `:locationIds` compiled by Task 2.
- Produces: a canonical grouped target-instance query and searchable active-location metadata.

- [ ] **Step 1: Write failing migration and SQL-semantic tests**

Assert migration 042 contains:

```sql
LEFT JOIN inventory.loclibrary__t lib ON lib.id = loc.library_id
LEFT JOIN inventory.loccampus__t campus ON campus.id = lib.campus_id
WHERE COALESCE(loc.is_active, true)
```

Assert it declares `locationIds` as `multiselect`, sets `max_selections` to 100, binds the UUID array predicate, aggregates selected labels, and retains the static sentinel:

```sql
STRING_AGG(
  DISTINCT location.name || COALESCE(' [' || location.code || ']', ''),
  '; ' ORDER BY location.name || COALESCE(' [' || location.code || ']', '')
) AS selected_locations
```

- [ ] **Step 2: Run migration/semantic tests and verify RED**

Run:

```bash
php backend/tests/CatalogingMarcMissingTagReportMigrationTest.php
php backend/tests/CatalogingMarcMissingTagReportSqlSemanticsTest.php
```

Expected: FAIL because migration 042 and the new canonical query do not exist.

- [ ] **Step 3: Add migration 042 and update fingerprints**

Create a forward-only `UPDATE report_templates` migration for slug
`marc-bibliographic-records-missing-tag`. Keep the two structural tokens exactly once. Update help text from singular to multiple locations, use the grouped target CTE, change the output heading to `Selected Locations`, and set parameter JSON to:

```json
{
  "name": "locationIds",
  "type": "multiselect",
  "label": "Locations",
  "required": true,
  "default": "",
  "placeholder": "Search locations",
  "max_selections": 100,
  "options_db": "folio"
}
```

Use the complete active-location `options_sql` from the design. Calculate and replace the canonical SQL and normalized-parameter SHA-256 constants in `CatalogingMarcMissingTagReportService`.

- [ ] **Step 4: Verify migration and live PostgreSQL semantics**

Run:

```bash
php backend/tests/CatalogingMarcMissingTagReportMigrationTest.php
php backend/tests/CatalogingMarcMissingTagReportSqlSemanticsTest.php
php backend/tests/CatalogingMarcMissingTagReportPostgresTest.php
```

Expected: all tests PASS; when live PostgreSQL credentials are configured, the live test confirms a multi-location query emits unique Instance UUIDs.

- [ ] **Step 5: Commit the migration unit**

```bash
git add mysql/migrations/042_cataloging_marc_multi_location.sql backend/services/CatalogingMarcMissingTagReportService.php backend/tests/CatalogingMarcMissingTagReportMigrationTest.php backend/tests/CatalogingMarcMissingTagReportSqlSemanticsTest.php backend/tests/CatalogingMarcMissingTagReportPostgresTest.php
git commit -m "feat: seed multi-location MARC report"
```

### Task 4: Full Verification and Docker Smoke Test

**Files:**
- Modify only if a verification failure exposes a scoped defect.

**Interfaces:**
- Consumes: the complete frontend, compiler, and migration changes.
- Produces: deployable evidence for the report UI and job path.

- [ ] **Step 1: Run the full backend suite**

Run the repository's backend standalone test runner used by this branch. Expected: all tests PASS with no new warnings.

- [ ] **Step 2: Run the full frontend suite and build**

Run:

```bash
cd frontend && npm test -- --run
cd frontend && npm run build
```

Expected: all tests PASS and TypeScript/Vite build succeeds.

- [ ] **Step 3: Apply migration 042 to the scratch Docker stack**

Run:

```bash
MYSQL_PORT=3308 NGINX_PORT=8081 docker compose -p folio-report-explorer-scratch exec -T php php yii migrate/run
```

Expected: migration 042 applies once and the report detail endpoint exposes `locationIds` as `multiselect`.

- [ ] **Step 4: Verify SC Internet and submit a two-location job**

Confirm `/api/reports/38` includes:

```json
{
  "value": "67204874-e4d7-495b-9247-62cd27d9ea31",
  "label": "Smith College — SC Neilson Library — SC Internet [SCINT]"
}
```

Submit two active location UUIDs with `locationBasis=effective_item` and `marcTag=245`. Expected: HTTP 202, forced file output, compiled SQL with one `:locationIds` bind, and `multi-2-locations` filename metadata.

- [ ] **Step 5: Review the final diff and commit any verification-only fix**

Confirm migrations 040/041 and unrelated dirty files are untouched. If verification required a scoped correction, commit only that correction with its regression test.
