# MARC Missing-Tag Cataloging Report Design

**Status:** Approved for implementation planning
**Date:** 2026-08-06

## Purpose

Add a fixed cataloging report that lets a cataloger find MARC bibliographic
records associated with a selected FOLIO location that do not contain a
selected MARC tag. The report produces both a descriptive worklist and a
single-column Instance UUID file that can be uploaded to FOLIO Data Export.

This report is a cataloger-directed finder. A result means that the selected
tag is absent; it does not by itself mean that the record violates MARC,
RDA, or local cataloging policy.

## Goals

- Let a cataloger select a location, a location basis, and one MARC tag.
- Scope a shared bibliographic record into the result when the selected
  location has at least one qualifying item or holdings record.
- Query one `marctab.mtNNN` table per execution.
- Return one row per MARC bibliographic record missing the selected tag.
- Produce a descriptive worklist CSV.
- Produce a one-column Instance UUID CSV for FOLIO Data Export.
- Establish a Cataloging report category and reusable identifier-export
  metadata for future fixed reports.
- Document a prioritized roadmap of additional cataloging reports.

## Non-goals

- Do not decide whether every missing tag is a cataloging error.
- Do not validate all MARC rules in one execution.
- Do not query the combined `folio_source_record.marctab` view.
- Do not return or export the complete raw MARC JSON.
- Do not edit MARC records, invoke FOLIO Data Export, run MarcEdit, or import
  corrected records.
- Do not validate Leader values in the initial report.
- Do not implement live URL checking.
- Do not report on MARC holdings or MARC authority records in the initial
  version.

## Terminology

The report name is **MARC Bibliographic Records Missing a Tag**.

The selected location scopes the collection to inspect. It does not imply
that the selected MARC tag belongs to an item or holdings record. The tag is
tested on the bibliographic source record associated with the Inventory
instance.

Only Inventory instances whose source is `MARC` qualify. Inventory-native
instances with another source must not be reported as missing every MARC tag.
The initial report deliberately trusts the Inventory source designation rather
than independently joining the much larger SRS records table.

## Report Category

Add `cataloging` as a first-class report category in:

- the MySQL `report_templates.category` enum;
- `ReportTemplate` validation constants;
- frontend `ReportCategory` types;
- the report category label list; and
- report-list tests.

The category label is **Cataloging**. Existing categories and reports remain
unchanged.

## Parameters

### Location

- Required select parameter.
- Values come from `inventory.location__t.id`.
- Labels use the canonical location name and include enough hierarchy context
  to distinguish duplicate names when needed.
- Options are loaded from the FOLIO reporting database.

### Location basis

- Required select parameter.
- Default: `effective_item`.
- Allowed values:
  - `effective_item` — item effective location;
  - `permanent_item` — item permanent location;
  - `permanent_holdings` — holdings permanent location.

The server maps these values to three reviewed target-instance query shapes.
It does not interpolate a user-supplied column or SQL fragment.

- Effective and permanent item scope start from items and therefore require a
  qualifying item.
- Permanent holdings scope starts from holdings and includes qualifying
  holdings even when they have no item.

### MARC tag

- Required MARC-tag parameter.
- Accepted form: exactly three ASCII digits representing `001` through `999`.
- `000` is rejected because the MARC Leader is not a MARC tag.
- Whitespace, signs, decimals, schema names, SQL fragments, Unicode digits,
  and partial tags are rejected.
- A validated tag maps only to `marctab.mtNNN`, such as `856` to
  `marctab.mt856`.

## Safe SQL Construction

PDO parameters can bind values but cannot bind table or column identifiers.
The report therefore needs two narrow, fail-closed transformations before
ordinary value binding:

1. Map the validated MARC tag to the exact `marctab.mtNNN` table name.
2. Map the location-basis enum to one reviewed target-instance SQL shape.

These transformations are domain-specific. They must not become a general
facility for users or report authors to interpolate arbitrary identifiers.
After transformation, the resulting SQL passes through the existing SQL
safety validator and ordinary parameter binder.

The report template owns exactly one `{{location_from}}` token and exactly one
`{{marc_table}}` token. The location token covers only the reviewed `FROM` and
`JOIN` fragment; the bound `:locationId` predicate remains in the static SQL
template. No substituted fragment or identifier may contain a colon.

Construction order is fixed:

1. Verify that the report declares exactly `locationId`, `locationBasis`, and
   `marcTag`, that their names are unique, and that no name is a prefix of
   another.
2. Validate all three user values.
3. Replace `{{location_from}}` exactly once from the location-basis allowlist.
4. Replace `{{marc_table}}` exactly once with the validated `marctab.mtNNN`
   identifier.
5. Reject substituted text containing `:` and reject any unresolved or
   repeated structural token.
6. Bind the three ordinary value placeholders using the existing parameter
   binder. `marcTag` remains a bound output value as well as the validated
   source of the table identifier.
7. Add the internal result-limit sentinel.
8. Pass the final SQL through the existing SQL safety validator.

No list parameter is used by this report. A structural fragment can never add
a value placeholder after binding.

## Query Contract

The final query has two stages.

### 1. Target instances

Build `target_instances AS MATERIALIZED` before accessing MARC data. Each
location-basis branch:

- uses the repository's type-safe Inventory join conventions;
- filters by the bound location UUID;
- requires `inventory.instance__t.source = 'MARC'`;
- returns the Instance UUID as a UUID value, Instance HRID, title, and selected
  location name; and
- deduplicates by Instance UUID.

The target CTE does not join `folio_source_record.records__t`. The SRS record
UUID is not used by the cataloger's FOLIO Data Export workflow, and the live
worst-location plan measured approximately 23% lower total cost when Inventory
source was used instead of the SRS join. The semantic tradeoff is explicit:
eligibility means that Inventory designates the instance as MARC-sourced, not
that the report independently proves a current SRS row exists.

A shared bibliographic record qualifies when the selected location has at
least one qualifying item or holdings record, even if other campuses also hold
the record.

### 2. Missing-tag predicate

Apply one anti-join:

```sql
WHERE NOT EXISTS (
    SELECT 1
    FROM marctab.mtNNN AS marc_tag
    WHERE marc_tag.instance_id = target_instances.instance_uuid
)
```

The physical table already represents one tag, so the query does not add a
redundant `field = :tag` predicate. The query never uses
`folio_source_record.marctab` and never searches
`records__t.parsed_record__content` for tag presence.

The anti-join uses the indexed, non-text Instance UUID rather than the nullable
Instance HRID. This prevents a null or blank target HRID from being reported as
missing a tag that is present. The live reporting schema exposes UUID on both
`inventory.instance__t.id` and `marctab.mtNNN.instance_id`, so the comparison
must remain UUID-to-UUID and indexable.

## Result Contract

Return one row per Instance UUID with these ordered columns:

1. `Instance UUID`
2. `Instance HRID`
3. `Title`
4. `Selected Location`
5. `Location Basis`
6. `Missing MARC Tag`

Order results by title and then Instance HRID. The missing-tag column repeats
the normalized three-digit input so exported worklists remain self-describing.

The public report limit remains the platform maximum of 100,000 rows. The SQL
fetches at most 100,001 ordered rows internally. The execution layer removes
the sentinel row, returns or writes at most 100,000 rows, and records an exact
`truncated` flag when the sentinel existed. This distinguishes an exact
100,000-row population from a larger population. A truncated result tells the
cataloger to narrow the location or handle the remaining population
separately.

## Export Contracts

### Worklist CSV

The existing report CSV action exports every result column in the defined
order. It supports review, assignment, annotations in another tool, and audit
tracking.

### FOLIO UUID CSV

Add reusable nullable report metadata identifying the column eligible for an
identifier-only export. For this report the configured source column is
`Instance UUID`.

When the metadata is present, the report page shows **Export FOLIO UUID list**.
That action:

- always uses background file mode;
- projects only the configured identifier column from the complete report
  result within the 100,000-row report cap;
- removes null and blank values;
- deduplicates identifiers;
- preserves deterministic ordering;
- writes a single column with the header `UUID`;
- uses UTF-8 CSV with CRLF row endings; and
- names the file using the report slug, normalized tag, and selected location.

The identifier-export request is accepted only for a report with server-owned
identifier-export metadata. The client cannot nominate a SQL expression or
arbitrary column. Existing reports retain their current export behavior.

Identifier projection and defensive deduplication operate on the retained
100,000-row worklist after sentinel detection. A generic identifier export may
therefore contain fewer identifiers than its capped worklist if invalid or
duplicate identifiers are removed, while the original `truncated` warning
still applies. This report's one-row-per-Instance contract should make the two
counts equal unless data violates the result contract.

FOLIO Data Export accepts a CSV list of Instance UUIDs and produces MARC when
underlying SRS data exists. The generated UUID file must be smoke-tested against
the deployed FOLIO Data Export release before production enablement.

## User Workflow and Help

The report help explains this sequence:

1. Select a location, location basis, and MARC tag.
2. Run the report and review the candidate records.
3. Download the worklist when descriptive context is needed.
4. Download the FOLIO UUID list for batch export.
5. Upload the UUID list to FOLIO Data Export.
6. Export the underlying MARC source records.
7. Review or edit the records in MarcEdit.
8. Reimport corrected records only through the institution's approved FOLIO
   Data Import profile.

The help text states that tag absence is factual but its cataloging significance
depends on record type, cataloging code, legacy practice, and local policy.

## Errors and Empty Results

- Missing required parameters return the existing required-parameter error.
- Invalid tags return a specific validation message before SQL construction.
- Unknown location-basis values fail before SQL construction.
- Unresolved or repeated SQL tokens fail closed.
- An expected `marctab.mtNNN` table that is unavailable produces a reporting-
  schema integrity error; the service never treats schema damage as evidence
  that every candidate record lacks the tag and never falls back to the
  combined MARC view.
- No qualifying MARC records at the selected location returns a successful
  empty result.
- Qualifying MARC records with no missing tags also return a successful empty
  result.
- SQL timeout or connectivity failures use the existing report-job failure
  contract and never present partial rows as a complete worklist.

## Performance Requirements

- Touch exactly one `marctab.mtNNN` table per execution.
- Materialize the target instance set before the MARC anti-join.
- Keep the three location bases as separate reviewed query shapes; do not use a
  broad `OR` predicate across location columns.
- Use the existing background worker for both full CSV formats.
- Preserve the UUID-to-UUID anti-join so the per-tag Instance UUID index remains
  usable.
- Run `EXPLAIN` for all three location bases with a common tag such as 245 and a
  sparse tag such as 856.
- Exercise a location with a large item population and a location with a small
  population.
- Record actual planning/execution evidence in the implementation report.
- Document any required Inventory or marctab indexes. Do not create reporting-
  database indexes automatically from application deployment migrations.
- Do not enable the report until representative executions complete within the
  application's configured statement timeout.

## Verification Requirements

### Parameter and SQL safety

- Accept `001`, `245`, and `856`.
- Reject `000`, `1`, `12`, `1000`, whitespace-padded values, signed numbers,
  Unicode digits, schema-qualified names, comments, quotes, and SQL fragments.
- Prove every accepted tag produces exactly `marctab.mtNNN`.
- Prove generated SQL never contains `folio_source_record.marctab`.
- Prove only the three location-basis query shapes are reachable.
- Prove unresolved or duplicated template tokens fail closed.
- Prove structural resolution occurs before value binding and that substituted
  text cannot introduce a colon or value placeholder.
- Prove the three value-parameter names are unique and none is a prefix of
  another.

### Result behavior

- A MARC record containing the selected tag is absent from results.
- A qualifying MARC record missing the selected tag is returned.
- Inventory instances whose source is not `MARC` are excluded.
- Null or blank target HRIDs do not affect tag-presence results because the
  anti-join uses Instance UUID.
- Multiple qualifying items produce one row.
- A shared instance is included when the selected location qualifies.
- Itemless holdings are included only for permanent-holdings scope.
- Empty result sets remain successful.

### Export behavior

- Worklist CSV retains all six columns.
- Identifier export contains only a `UUID` column.
- Identifier export rejects client-selected columns.
- Blank and duplicate UUIDs are removed.
- Background export uses the complete capped result rather than the browser
  preview.
- Existing reports without identifier-export metadata are unchanged.
- The produced file is accepted by the deployed FOLIO Data Export application.
- Exactly 100,000 results do not show a truncation warning; 100,001 candidates
  return 100,000 rows and set the warning.
- Identifier projection or deduplication never clears a truncation warning.

### Deployment and UI

- The Cataloging category is valid in MySQL, backend validation, API types, and
  frontend rendering.
- The fixed-report migration is idempotent.
- Deployment-current recognition includes the complete report and export
  metadata contract.
- Existing report, parameter, job, and CSV suites remain green.

## Future Cataloging Report Roadmap

### Cataloger-directed finders

These reports state what is present or absent without asserting a policy
violation:

- records missing a selected MARC subfield;
- records with an empty selected subfield;
- records containing a selected tag;
- records containing a selected indicator value; and
- records containing a selected local field or value.

Each run should continue to use one per-tag table after location scoping.

### High-confidence structural validation

- missing or malformed 008;
- multiple occurrences of a non-repeatable field such as 245;
- missing or blank 245$a;
- invalid 245 indicators;
- 245 first indicator inconsistent with a 1XX main entry;
- empty fields or subfields; and
- invalid subfield codes for selected common tags.

Every standards-based rule must cite the controlling MARC 21 definition and
ship with valid counterexamples as well as invalid fixtures.

### Identifier quality

- duplicate 001 values across records;
- duplicate normalized OCLC identifiers in 035$a;
- malformed or invalid-checksum ISBNs in 020$a;
- malformed ISSNs in 022$a;
- duplicate identifiers within a record; and
- conflicting identifier forms across records.

Normalization and checksum rules must be approved before these reports label
records as defective.

### Electronic-access quality

- electronic-resource records missing 856$u;
- blank or malformed 856$u;
- repeated 856 URLs within a record;
- unexpected 856 indicators; and
- electronic inventory with no bibliographic electronic-access field.

Live URL checking belongs in a separate asynchronous service with explicit
network, retry, redirect, privacy, and rate-limit policy.

### Cross-field consistency

- 008 dates compared with 264$c;
- 008 language compared with 041;
- 336/337/338 compared with Inventory resource or material type;
- record format compared with Leader/06 and 008 configuration; and
- SRS suppression compared with Inventory discovery suppression.

These checks require format and legacy-record context and are not part of the
initial enforcement tier.

### Local policy

- required local 9XX fields;
- location-specific call-number requirements;
- consortium cataloging-source codes;
- required fields for selected collections or formats; and
- vendor- or migration-specific cleanup checks.

Local-policy reports must be visibly labeled as local rules and kept separate
from MARC structural validation.

### Deferred data sources

Leader validation and broad MARC field-family analysis are deferred until a
bounded, indexed reporting source is proven. They must not justify scanning the
full SRS JSON population or the thousand-table union view.

## Authoritative References

- FOLIO MARC transformation:
  <https://poppy.docs.folio.org/docs/reporting/marc-transformation/>
- FOLIO Data Export:
  <https://kiwi.docs.folio.org/docs/metadata/data-export/>
- FOLIO Data Export profiles:
  <https://kiwi.docs.folio.org/docs/settings/settings_data_export/settings_data_export/>
- FOLIO cataloging job-profile workflows:
  <https://docs.folio.org/docs/metadata/additional-topics/jobprofiles/>
- MARC 21 Bibliographic format:
  <https://www.loc.gov/marc/bibliographic/index.html>
- MARC 21 field 008:
  <https://www.loc.gov/marc/bibliographic/bd008.html>
