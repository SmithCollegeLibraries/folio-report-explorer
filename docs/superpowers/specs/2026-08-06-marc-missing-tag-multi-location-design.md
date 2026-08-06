# MARC Missing-Tag Multi-Location Design

## Goal

Allow catalogers to search the MARC missing-tag report's FOLIO location list,
select multiple locations, and receive one worklist row and one exported UUID
per matching bibliographic instance.

## Location Source

The selector uses `inventory.location__t` as the authoritative location table.
Hierarchy labels are assembled through the canonical chain:

```text
inventory.location__t
  -> inventory.loclibrary__t
  -> inventory.loccampus__t
```

The campus join uses `loclibrary__t.campus_id`. The selector lists active
locations only and labels each option as campus, library, location, and code.
The active `SC Internet` row therefore appears as:

```text
Smith College — SC Neilson Library — SC Internet [SCINT]
```

## Searchable Multi-Select

The report parameter changes from singular `locationId` to plural
`locationIds` with type `multiselect`. This type is opt-in so existing report
dropdowns retain their current behavior.

The control provides:

- case-insensitive search across the complete option label;
- checkbox selection without closing the result list;
- removable selected-location chips;
- selected-count and clear-all actions;
- an explicit empty-result message;
- keyboard-accessible controls and an Escape-to-close interaction.

Selected UUIDs are serialized as a comma-separated string at the existing
report page/API boundary. The UI does not add a new runtime dependency.

## Validation and Binding

The cataloging report compiler accepts between 1 and 100 unique location
UUIDs. It rejects blank selections, malformed UUIDs, duplicate UUIDs, more
than 100 UUIDs, and UUIDs that are no longer present in
`inventory.location__t`.

After validation, the compiler normalizes the UUIDs into one comma-separated
value and binds it as `:locationIds`. The reviewed SQL uses:

```sql
location.id = ANY(string_to_array(:locationIds, ',')::uuid[])
```

No selected value is interpolated into SQL and the existing structural token
allowlists remain unchanged.

## Result Semantics

All selected locations form one inclusive scope. The target-instance CTE
groups by Instance UUID, Instance HRID, and title, and aggregates distinct
matching location names and codes into `Selected Locations` in deterministic
alphabetical order.

An instance associated with several selected locations therefore produces:

- one worklist row;
- one semicolon-delimited `Selected Locations` value;
- one UUID in the identifier export.

Single-location runs retain the existing result semantics. Multi-location
download filenames use `multi-N-locations`, where `N` is the selection count,
instead of embedding one arbitrary location.

## Migration

A new forward-only migration updates the seeded report definition. Applied
migrations 040 and 041 remain unchanged so their production checksums stay
valid. The migration updates the SQL template, parameter metadata, help text,
and canonical report fingerprints together.

## Testing

Frontend tests cover searching by name/code, selecting and removing multiple
locations, clearing selections, preserving the serialized value, the empty
state, and the 100-location cap.

Backend tests cover UUID-list validation, existence checks, one bound array
value, canonical migration recognition, hierarchy option SQL, deterministic
location aggregation, all three location bases, deduplication, and governed
export filenames. Docker verification applies the migration to the scratch
stack, confirms `SC Internet` is returned, submits a two-location report, and
checks the compiled SQL/job metadata.
