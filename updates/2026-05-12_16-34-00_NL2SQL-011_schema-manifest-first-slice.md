# Update: Query Family Schema Manifests First Slice

- Timestamp: 2026-05-12 16:34:00
- Ticket: NL2SQL-011
- Status: In Progress

## Summary
- Added the first checked-in query-family schema manifest artifact and enforced it before deterministic family compilation.
- Covered the highest-risk graph-backed families in the current compiler surface: collection age, contributor campus barcode, library/location listing, and top items.
- Left `circulation_trends_matrix` out of this first rollout because the current canonical graph artifact still does not describe that circulation-side path well enough for the same deterministic edge validation.

## Changes Made
- Added `QueryFamilySchemaManifestService` to load the new manifest artifact, inspect the live column and subtable caches, and fail closed on missing entities, drifted column types, or missing deterministic graph edges.
- Added `backend/data/query_family_schema_manifests.json` with required entities, columns, edges, and conditional requirements for slot-driven or output-driven joins.
- Wired `QueryFamilyCompilerService::compileToQueryDefinition()` to run schema-manifest validation before deterministic join construction.
- Added focused standalone regression tests for the validator itself and for compiler-level fail-closed behavior when a manifested family drifts.
- Updated the existing standalone compiler regression harness so it explicitly loads the new manifest service and artifact path.

## Files Changed
- [backend/data/query_family_schema_manifests.json](../backend/data/query_family_schema_manifests.json)
- [backend/services/QueryFamilyCompilerService.php](../backend/services/QueryFamilyCompilerService.php)
- [backend/services/QueryFamilySchemaManifestService.php](../backend/services/QueryFamilySchemaManifestService.php)
- [backend/tests/QueryFamilyCompilerSchemaManifestGuardTest.php](../backend/tests/QueryFamilyCompilerSchemaManifestGuardTest.php)
- [backend/tests/QueryFamilyCompilerServiceTest.php](../backend/tests/QueryFamilyCompilerServiceTest.php)
- [backend/tests/QueryFamilySchemaManifestServiceTest.php](../backend/tests/QueryFamilySchemaManifestServiceTest.php)
- [planning/tickets.md](../planning/tickets.md)
- [updates/2026-05-12_16-34-00_NL2SQL-011_schema-manifest-first-slice.md](2026-05-12_16-34-00_NL2SQL-011_schema-manifest-first-slice.md)

## Validation Evidence
- `php backend/tests/QueryFamilySchemaManifestServiceTest.php` passed.
- `php backend/tests/QueryFamilyCompilerSchemaManifestGuardTest.php` passed.
- `php backend/tests/QueryFamilyCompilerServiceTest.php` passed.
- `php -l backend/services/QueryFamilySchemaManifestService.php` passed.
- `php -l backend/services/QueryFamilyCompilerService.php` passed.

## Open Risks Or Follow-ups
- `circulation_trends_matrix` still needs either graph-artifact expansion or an explicitly approved manifest strategy for circulation-only joins before it can join this fail-closed rollout.
- `circulation_top_items` now validates its deterministic path and critical supporting tables, but any future compiler broadening should keep the manifest in sync with new subtable or audit-table dependencies.

## Next Ticket
- `NL2SQL-011 - Complete circulation_trends_matrix schema-manifest coverage`