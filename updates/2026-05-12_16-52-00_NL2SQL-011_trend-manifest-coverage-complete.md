# Update: Trend Manifest Coverage Complete

- Timestamp: 2026-05-12 16:52:00
- Ticket: NL2SQL-011
- Status: Completed

## Summary
- Completed the schema-manifest rollout for the last uncovered deterministic family, `circulation_trends_matrix`.
- Expanded the canonical query graph artifact so the trend family now validates against explicit deterministic circulation-loan edges instead of relying on an unmodeled path.
- The checked-in manifest artifact now covers all five currently supported deterministic families before compilation.

## Changes Made
- Added `circulation_loans` to the canonical graph builder and checked-in graph artifact.
- Added deterministic graph edges from `circulation_loans.item_id` to `inventory_items.id` and from `circulation_loans.item_effective_location_id_at_check_out` to `inventory_locations.id`.
- Added `circulation_trends_matrix` to the schema-manifest artifact with required entities, columns, and join edges for its compiler path.
- Added a focused regression test that verifies the checked-in graph artifact, the graph builder output, and the checked-in schema manifest all cover the trend family consistently.

## Files Changed
- [backend/data/canonical_query_graph.json](../backend/data/canonical_query_graph.json)
- [backend/data/query_family_schema_manifests.json](../backend/data/query_family_schema_manifests.json)
- [backend/services/CanonicalQueryGraphArtifactBuilder.php](../backend/services/CanonicalQueryGraphArtifactBuilder.php)
- [backend/tests/QueryFamilyTrendManifestCoverageTest.php](../backend/tests/QueryFamilyTrendManifestCoverageTest.php)
- [planning/tickets.md](../planning/tickets.md)
- [updates/2026-05-12_16-52-00_NL2SQL-011_trend-manifest-coverage-complete.md](2026-05-12_16-52-00_NL2SQL-011_trend-manifest-coverage-complete.md)

## Validation Evidence
- `php backend/tests/QueryFamilyTrendManifestCoverageTest.php` passed.
- `php backend/tests/QueryFamilyCompilerServiceTest.php` passed.
- `php backend/tests/QueryFamilySchemaManifestServiceTest.php` passed.
- `php backend/tests/QueryFamilyCompilerSchemaManifestGuardTest.php` passed.
- `php -l backend/services/CanonicalQueryGraphArtifactBuilder.php` passed.
- `php -l backend/services/QueryFamilySchemaManifestService.php` passed.

## Open Risks Or Follow-ups
- The checked-in graph artifact still represents a curated deterministic slice, not a full schema graph; future compiler expansion needs matching artifact and manifest updates rather than assuming ambient coverage.
- `NL2SQL-008` shadow-mode cutover remains the active release gate even though the local hardening plan has advanced beyond the original release milestone.

## Next Ticket
- `NL2SQL-008 - Shadow Mode and Cutover`