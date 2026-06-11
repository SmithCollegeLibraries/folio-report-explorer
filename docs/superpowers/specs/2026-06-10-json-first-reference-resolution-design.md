# JSON-First Reference Resolution Design

## Purpose

Ask AI must resolve stable FOLIO reference terms locally before any AI prompt, intent extraction, or SQL generation. The most important guarantee is location correctness: a prompt such as "show items in josten treasure and treasure folio" must resolve against local `inventory.location__t` data first and must not let the model reinterpret those terms as `inventory.loclibrary__t` filters.

This design replaces weak prompt guidance with structured, JSON-backed reference resolution. The AI can still help produce SQL, but it must receive already-resolved reference scope when the user phrase maps to an approved local reference table.

## Approved JSON Tables

The local JSON reference bundle will include exactly these approved tables.

### Location Authority

- `inventory.location__t`
- `inventory.loclibrary__t`
- `inventory.loccampus__t`
- `inventory.locinstitution__t`
- `inventory.service_point__t`

### Inventory References

- `inventory.material_type__t`
- `inventory.loan_type__t`
- `inventory.holdings_type__t`
- `inventory.call_number_type__t`
- `inventory.instance_type__t`
- `inventory.instance_format__t`
- `inventory.instance_status__t`
- `inventory.instance_note_type__t`
- `inventory.holdings_note_type__t`
- `inventory.item_note_type__t`
- `inventory.item_damaged_status__t`
- `inventory.contributor_type__t`
- `inventory.contributor_name_type__t`
- `inventory.identifier_type__t`
- `inventory.classification_type__t`
- `inventory.electronic_access_relationship__t`
- `inventory.ill_policy__t`
- `inventory.statistical_code__t`
- `inventory.statistical_code_type__t`
- `inventory.subject_sources__t`
- `inventory.subject_types__t`
- `inventory.alternative_title_type__t`
- `inventory.mode_of_issuance__t`
- `inventory.nature_of_content_term__t`

### Finance And Acquisitions References

- `finance.fund__t`
- `finance.ledger__t`
- `finance.fiscal_year__t`
- `finance.fund_type__t`
- `finance.expense_class__t`
- `finance.groups__t`
- `orders.acquisitions_unit__t`
- `invoice.batch_groups__t`

### Circulation And Policy References

- `circulation.cancellation_reason__t`
- `circulation.loan_policy__t`
- `circulation.request_policy__t`
- `circulation.patron_notice_policy__t`
- `circulation.fixed_due_date_schedule__t`
- `circulation.staff_slips__t`

### Course And Fees/Fines Lookups

- `courses.coursereserves_copyrightstates__t`
- `courses.coursereserves_coursetypes__t`
- `courses.coursereserves_departments__t`
- `courses.coursereserves_processingstates__t`
- `courses.coursereserves_terms__t`
- `feesfines.lost_item_fee_policy__t`
- `feesfines.overdue_fine_policy__t`
- `feesfines.waives__t`

## Explicit Exclusions

The reference bundle must never include large operational tables, especially:

- `inventory.item__t`
- `inventory.instance__t`
- `inventory.holdings_record__t`

These tables are report data, not stable reference authority. Ask AI may query them in generated SQL, but the local pre-scan must not cache them as reference JSON.

## JSON Bundle Shape

The generator will produce one local JSON artifact containing:

- `generated_at`
- `source_database_fingerprint` when available
- `tables`, keyed by source table name
- per row:
  - `id`
  - `name` when present
  - `code` when present
  - `description` when present and useful
  - `normalized_name`
  - `normalized_code`
  - `search_tokens`
  - `metadata`

Location hierarchy rows require enriched metadata:

- `inventory.location__t`: location name/code plus parent library, campus, institution, and service point metadata where available.
- `inventory.loclibrary__t`: library name/code plus parent campus and institution metadata.
- `inventory.loccampus__t`: campus name/code plus parent institution metadata where available.
- `inventory.locinstitution__t`: institution name/code.
- `inventory.service_point__t`: service point name/code.

## Resolution Flow

Ask AI will run the JSON resolver before model calls.

1. Normalize the user prompt into searchable phrases and tokens.
2. Detect reference-intent hints such as location, library, campus, service point, material type, loan type, fund, fiscal year, policy, course term, or fee/fine wording.
3. Search the approved JSON bundle.
4. Return structured resolved references, not only text guidance.
5. Inject resolved references into deterministic family slots when a supported family exists.
6. Append human-readable guidance to model prompts only as secondary context.
7. If no confident resolution exists for a reference-like phrase, stop for clarification instead of guessing.

## Location Matching Rules

Location hierarchy matching is stricter than general lookup matching.

- Exact `name` and `code` matches win.
- Campus prefixes such as `SC` may be omitted by the user.
- Strong partial phrases may match when they uniquely identify a row after stopword removal.
- Multiple requested locations must be preserved as multiple resolved `inventory.location__t` rows.
- Parent library names are metadata for location rows; they must not replace the location scope.

Example:

User phrase: `josten treasure and treasure folio`

Expected resolved references:

- `inventory.location__t.name = 'SC Josten Treasure'`
- `inventory.location__t.name = 'SC Josten Treasure Folio'`

The SQL must filter `inventory.location__t`, not `inventory.loclibrary__t`, for those values.

## SQL Contract

The generated SQL layer must honor source-table semantics:

- Resolved `inventory.location__t` values produce predicates on `inventory.location__t.name` or `inventory.location__t.code`.
- Resolved `inventory.loclibrary__t` values produce predicates on `inventory.loclibrary__t.name` or `inventory.loclibrary__t.code`.
- Resolved `inventory.loccampus__t` values produce predicates on `inventory.loccampus__t.name` or `inventory.loccampus__t.code`.
- Resolved `inventory.locinstitution__t` values produce predicates on institution fields only.
- Resolved `inventory.service_point__t` values produce predicates through the service point relationship only when the query family supports it.

Validation must fail closed if a resolved value is applied to the wrong hierarchy level. For example, `SC Josten Treasure` on `inventory.loclibrary__t.name` is invalid.

## Error Handling

The resolver may produce three outcomes:

- `resolved`: one or more confident references found.
- `needs_clarification`: multiple plausible references or unresolved reference-like phrase.
- `unresolved_non_reference`: no reference-like phrase detected, normal Ask AI flow continues.

For `needs_clarification`, the response must show the matched candidates and the table/category they came from. The system must not generate SQL until the ambiguity is resolved.

## Testing

Required regression tests:

- `josten treasure and treasure folio` resolves to two `inventory.location__t` rows.
- A resolved `inventory.location__t` row cannot be rendered as a `loclibrary__t.name` predicate.
- Multiple locations in one prompt compile into an `OR` or `IN` location filter.
- `SC Josten Library` resolves as `inventory.loclibrary__t`, while `SC Josten Treasure` resolves as `inventory.location__t`.
- The JSON generator refuses `inventory.item__t`, `inventory.instance__t`, and `inventory.holdings_record__t`.
- General approved lookup tables, such as `inventory.material_type__t` and `finance.fund__t`, are present in the JSON bundle.

## Rollout

1. Add the approved table allowlist in one backend location.
2. Add a JSON generator command for the approved table set.
3. Update Ask AI reference resolution to read JSON first.
4. Convert resolved references into structured scope for supported query families.
5. Keep the existing MySQL reference cache as a fallback or admin review surface until JSON-first behavior is verified.
6. Add telemetry for reference matches, clarification stops, and wrong-level validation failures.
