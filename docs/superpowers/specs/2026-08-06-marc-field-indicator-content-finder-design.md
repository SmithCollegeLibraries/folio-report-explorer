# MARC Field, Indicator, and Content Finder Design

## Purpose

Add a governed Cataloging report that lets catalogers investigate the presence,
absence, indicators, subfields, and content of any selected MARC bibliographic
tag. The report is a generic finder: it reports matching data and does not
hard-code institutional policies or claim that a result is a cataloging error.

The report is named **MARC Field, Indicator, and Content Finder**.

## Goals

- Search one validated `marctab.mtNNN` table per execution.
- Scope records to one or more selected FOLIO locations before accessing MARC
  data.
- Support effective-item and permanent-item location bases.
- Find records that have at least one field row matching selected criteria.
- Find records that have no field row matching selected criteria.
- Support optional first-indicator, second-indicator, subfield, and content
  criteria.
- Preserve repeated fields and subfields in a cataloger-readable worklist.
- Export a descriptive worklist or a deduplicated FOLIO Instance UUID file.
- Keep local validation rules in cataloger-supplied parameters rather than
  application code.

## Non-goals

- Do not validate records against every MARC 21 rule.
- Do not embed rules for a particular institution, location, collection, tag,
  indicator, subfield, prefix, or container-code format.
- Do not accept user-authored regular expressions.
- Do not scan `folio_source_record.marctab` or full SRS MARC JSON.
- Do not edit MARC records, run FOLIO Data Export, or run MarcEdit.
- Do not add saved report presets in the initial version.
- Do not replace the simpler **MARC Bibliographic Records Missing a Tag**
  report.

## Cataloger Workflow

1. Select between one and 100 locations.
2. Select effective-item or permanent-item location.
3. Enter a three-digit MARC tag.
4. Choose whether at least one matching occurrence must exist or no matching
   occurrence may exist.
5. Optionally constrain the first indicator, second indicator, and subfield.
6. Optionally select a content test and its required search text.
7. Review a plain-language interpretation of the complete condition.
8. Run the report and download either the worklist or a FOLIO Instance UUID
   file.

The interpretation must describe the Boolean meaning of the request. Examples:

> Return MARC records at SC Internet where no field row matches: tag 035,
> first indicator blank, second indicator 9, subfield a.

> Return matching field rows where tag 035, second indicator 9, subfield a,
> and content does not begin with “(SCTFEBA)”, matching capitalization exactly.

This display is required because a missing-occurrence condition combined with
an inverse content comparison can otherwise be difficult to interpret.

## Parameters

### Locations

- Required searchable multiple-selection parameter.
- Accept between one and 100 unique location UUIDs.
- Populate options from `inventory.location__t` and display the campus,
  library, location name, and location code.
- Serialize values through the existing governed multi-location parameter
  contract.

### Location basis

- Required single-selection parameter.
- Allowed values are `effective_item` and `permanent_item`.
- The report intentionally does not use the holdings permanent location.

### MARC tag

- Required three-character ASCII value from `001` through `999`.
- Map the validated value to exactly one `marctab.mtNNN` table.
- Reject `000`; the Leader is not a MARC tag.

### Record condition

Use two user-facing choices:

- **Has at least one matching occurrence**
- **Does not have any matching occurrence**

Every supplied indicator, subfield, and content criterion describes the same
candidate field row. For example, a missing condition for tag `035`, second
indicator `9`, and subfield `a` means that no `035` row satisfies both the
indicator and subfield criteria. It does not mean that the record lacks every
035 field.

### Indicators

Provide independent optional filters for the first and second indicator. Each
filter supports:

- Any value;
- Blank;
- `0` through `9`; or
- another single typed character.

A typed backslash is normalized to the Blank selection rather than treated as
a distinct custom indicator.

When both indicators are constrained, both predicates must match the same MARC
field occurrence. Display a blank indicator as `#` in the form interpretation
and result file.

At query time, blank-indicator matching must use a trim-based predicate and
must also recognize a literal backslash (`\`) as the MARC blank-indicator
encoding. In the measured local `marctab.mt035` data, the ordinary blank is a
single ASCII space; null and empty values were not observed, while 1,161 rows
used a one-character backslash. The reviewed blank predicate must therefore be
equivalent to:

```sql
TRIM(COALESCE(indicator_column, '')) = '' OR indicator_column = CHR(92)
```

An `IS NULL OR indicator_column = ''` predicate is not acceptable because it
would omit both observed blank encodings. The result formatter normalizes both
the whitespace and backslash forms to `#`.

### Subfield

- Optional single alphanumeric character.
- Accept alphabetic and numeric local subfield codes.
- When omitted, the content criterion may match any subfield row for the
  selected tag.
- Do not infer a default subfield for any tag.

### Content test

Support these values:

- Any value;
- Contains;
- Does not contain;
- Equals;
- Does not equal;
- Begins with;
- Does not begin with;
- Is blank;
- Is not blank;
- Contains lowercase characters; and
- Contains non-alphanumeric characters.

`Contains`, `Does not contain`, `Equals`, `Does not equal`, `Begins with`, and
`Does not begin with` require non-empty search text. Other tests do not accept
search text.

Blank content means null, empty, or whitespace-only and must use a trim-based
predicate such as `TRIM(COALESCE(content, '')) = ''`; it must not rely only on
null or empty-string equality. “Contains
non-alphanumeric characters” means a character outside ASCII `A-Z`, `a-z`, and
`0-9`, including spaces and punctuation. It is a descriptive filter, not a
claim that punctuation is invalid.

### Capitalization

Text comparisons are case-insensitive by default. For comparisons that consume
search text, provide a **Match capitalization exactly** toggle. The
`Contains lowercase characters` test is inherently case-aware and does not
display the toggle.

Search text is always literal. Percent signs, underscores, quotes, and
backslashes must not become SQL syntax or pattern wildcards.

## Query Contract

### Governed compilation

Extend the governed cataloging compiler rather than sending this report through
free-form report SQL generation. The compiler owns parameter validation,
structural token resolution, value binding, and result-limit enforcement.

Only two reviewed structural substitutions are permitted:

1. the effective- or permanent-item location fragment; and
2. the validated `marctab.mtNNN` table identifier.

All location UUIDs, indicators, subfield values, search text, and comparison
values are bound parameters. Reject unresolved structural tokens and reject a
colon in either structural replacement.

The seeded parameter names are exactly:

- `locationIds`;
- `locationBasis`;
- `marcTag`;
- `occurrenceCondition`;
- `firstIndicator`;
- `secondIndicator`;
- `subfieldCode`;
- `contentRule`;
- `searchValue`; and
- `caseExact`.

No name in this set is a prefix of another. The governed compiler must require
this exact set once each and retain the existing mandatory pairwise
prefix-collision assertion. This is a correctness guard, not advisory
validation: the current `ReportTemplate::bindParams` list branch expands the
comma-serialized `locationIds` value by replacing the raw parameter token, so a
prefix collision could corrupt a different marker before it is bound.

Before execution, verify the resolved `marctab.mtNNN` relation exists. If it is
absent, return the existing administrator-facing cataloging data-integrity
message.

### Location-first instance scope

Build `target_instances AS MATERIALIZED` before touching MARC data. The CTE:

- starts from `inventory.instance__t`;
- includes only instances whose source is exactly `MARC`, consistent with the
  existing missing-tag report contract;
- scopes through holdings and items using the selected location basis;
- matches the validated UUID array of selected locations;
- aggregates distinct selected location names for display; and
- produces one row per instance before the MARC lookup.

The compiler must reuse the existing multi-location behavior so the same
instance held at multiple selected locations is scoped once.

### Matching-occurrence mode

For **Has at least one matching occurrence**, join the selected `mtNNN` table
to `target_instances` by Instance UUID and apply every selected predicate to
the same MARC row.

Return one row for each matching MARC subfield row. Do not aggregate repeated
fields or repeated subfields into a single value. If no subfield is selected,
all subfield rows satisfying the remaining criteria may appear.

`Field Occurrence` must come from the per-tag table's `ord` column. `ord`
identifies the repeated MARC field occurrence and is shared by all subfield rows
belonging to that occurrence. Do not use the `line` column: it is a
per-subfield line counter, so one 245 occurrence containing `$a`, `$b`, and `$c`
would otherwise appear to have three unrelated occurrence numbers.

### Missing-occurrence mode

For **Does not have any matching occurrence**, use a correlated UUID-based
`NOT EXISTS` probe against the selected `mtNNN` table. Place every selected
indicator, subfield, and content predicate inside that probe.

Return one row per target instance. Because no matching MARC row exists, leave
the indicator, occurrence, subfield, and content result columns blank.

### Text predicates

Implement literal text comparisons with explicitly escaped pattern characters.
Case-insensitive comparisons may use a normalized bound value and normalized
column expression; exact-capitalization comparisons use the original content.
No parameter may alter the SQL operator or inject a regular expression.

The two character-quality predicates use fixed application-authored SQL
expressions. They do not interpolate user patterns:

- lowercase detection tests for ASCII `a-z`;
- non-alphanumeric detection tests for characters outside ASCII letters and
  digits.

### Performance and cap

- Query exactly one per-tag `marctab.mtNNN` table.
- Never query the thousand-table union view.
- Never parse `records__t.parsed_record__content`.
- Materialize location-scoped instances before probing MARC rows.
- Preserve the existing internal `LIMIT 100001` sentinel and public 100,000-row
  cap.
- Force governed report execution to file output regardless of planner
  estimates.
- Carry accurate truncation metadata through the file-download path.

Performance evidence must cover a large common table such as `mt245` and a
smaller local or less common tag. The report must not be production-enabled
without representative PostgreSQL plan and execution evidence under the
configured reporting timeout.

Per-tag tables are indexed for instance lookup but not for arbitrary `content`
predicates. Content tests must remain filters over MARC rows reached through
the already-materialized target Instance UUIDs. For the large-table case, the
recorded plan must specifically demonstrate that the planner uses the
`instance_id` access path into `mt245` and does not switch to a sequential scan
of the complete per-tag table for a large multi-location selection.

## Result Contract

Return these columns in this order:

1. `Instance UUID`
2. `Instance HRID`
3. `Title`
4. `Selected Location(s)`
5. `Location Basis`
6. `MARC Tag`
7. `First Indicator`
8. `Second Indicator`
9. `Field Occurrence` (`marctab.mtNNN.ord`)
10. `Subfield`
11. `Content`
12. `Finding`

In matching-occurrence mode, `Finding` describes the selected positive or
content condition. In missing-occurrence mode, it is `Missing matching
occurrence`.

Repeated MARC rows are intentionally preserved in the worklist. Location joins
must not create additional duplicates.

## Export Contract

The worklist export contains the 12 result columns and preserves repeated MARC
rows. The FOLIO identifier export contains one header, `Instance UUID`, and one
deduplicated valid UUID per instance.

The identifier file may contain fewer rows than the worklist because one
instance can have multiple matching field rows. Continue reporting invalid
identifier omissions and truncation through the existing export metadata and
download UI. Warning information belongs in metadata, not in CSV data rows.

The 100,000-row public cap counts worklist subfield rows, not distinct
instances. Repeated fields and subfields can therefore cause the worklist to
truncate even when the deduplicated UUID export is comparatively small. The
download UI and help text must explain this apparent count difference.

## User Interface

Add the new report under the Cataloging category. Reveal controls
progressively:

1. location and location-basis controls are always visible;
2. indicator and subfield controls become usable after a valid tag is entered;
3. search text appears only for tests that require it; and
4. the capitalization toggle appears only for text-consuming comparisons.

Render the plain-language interpretation immediately above the run action and
update it as parameters change. The interpretation is explanatory only; the
backend remains authoritative for validation and SQL compilation.

Help text must state that the report identifies present or absent MARC data but
does not determine whether the data violates MARC, RDA, or local policy.

## Error Handling

- Reject malformed tags, indicators, subfields, location lists, and incompatible
  content parameters before creating a job.
- Return field-specific 400 responses that the report form can place beside the
  relevant control.
- Treat a missing per-tag relation as reporting-schema corruption, not as proof
  that every candidate record lacks the tag.
- A valid request with no matches returns a successful empty file.
- Database policy failures retain the generic user-facing reporting-policy
  message while logging the detailed author-facing reason.

## Testing

### Offline backend coverage

- Recognize only the exact seeded governed-report contract.
- Reject malformed, duplicate, or prefix-colliding parameters.
- Require the exact ten prefix-safe parameter names once each.
- Resolve exactly one location fragment and two occurrences of the same validated
  `mtNNN` token. Both occurrences must resolve to the identical physical table;
  no second user-selected table is permitted.
- Reject unresolved tokens and unsafe structural replacements.
- Bind all user values and treat SQL wildcard characters literally.
- Cover every content comparison in case-insensitive and exact-capitalization
  modes where applicable.
- Cover whitespace and backslash blank-indicator encodings plus numeric,
  alphabetic, and punctuation indicator values.
- Verify blank indicator and blank content predicates are trim-based.
- Cover alphabetic and numeric subfield codes.
- Confirm all criteria apply to the same MARC row.
- Verify matching and missing occurrence semantics.
- Preserve repeated MARC fields and repeated subfields.
- Map `Field Occurrence` to `ord`, never `line`.
- Deduplicate instances introduced through multiple selected locations.
- Exclude non-MARC Inventory instances.
- Enforce file-only routing and the result cap.
- Verify 12-column worklist and deduplicated identifier exports.

### Frontend coverage

- Render conditional controls and accessible labels.
- Validate each parameter without submitting a job.
- Render both whitespace and backslash blank indicators as `#`.
- Update the interpretation for every input class.
- Clearly describe double-negative combinations rather than silently changing
  them.
- Preserve searchable multi-location keyboard and pointer behavior.
- Display truncation and invalid-identifier warnings only when applicable.

### PostgreSQL integration coverage

- Compile and explain both occurrence modes for all location bases.
- Exercise a large common tag and a smaller tag.
- Confirm the plan accesses only the selected per-tag MARC table.
- For a large multi-location `mt245` content search, confirm instance-index
  probes and reject a plan that sequentially scans the complete table.
- Confirm the target instance set is materialized before MARC access.
- Verify UUID joins and `NOT EXISTS` correctness with null HRIDs.
- Measure execution against representative large and small locations under the
  configured timeout.

## Deferred Enhancements

- Saved and shareable parameter presets.
- Administrator-authored local-policy rule sets.
- Purpose-built format masks after real cataloging patterns are inventoried.
- MARC 21 standards validation with cited controlling rules.
- Leader analysis and broad multi-tag field-family analysis.
- Live URL checking and other network-dependent validation.

These enhancements must build on the governed parameter contract rather than
introducing institution-specific behavior into the generic finder.
