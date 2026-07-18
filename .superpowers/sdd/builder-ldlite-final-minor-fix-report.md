# Builder LDLite Final Minor Fix Report

## Scope

Addressed the three Minor findings from the final whole-branch review without
changing relationship selection, trusted SQL resolution, save semantics, or
layout behavior.

## Changes

### Canonical endpoint display

- Canonical Builder relationship types now explicitly require
  `from_table`/`from_column` and `to_table`/`to_column`.
- Direct relationship grouping normalizes table-relative parent/child copies
  back to those canonical endpoints before deduplication. The result is stable
  when the item detail is loaded before the location detail and the child copy
  wins the relationship-ID map entry.
- The graph selector renders canonical endpoints. Effective, permanent, and
  temporary item-location alternatives therefore display their actual item
  column to `location.id`, rather than `id -> id`.
- Backend coverage records the real child projection contract: its
  table-relative fields remain child-shaped while its canonical endpoints are
  preserved.

### Visible overlay failures

- Missing/unreadable overlay files, malformed JSON, invalid top-level
  relationship collections, and invalid list entries now become catalog
  warnings.
- Overlay loading still returns a safe empty reviewed layer, so generated
  MetaDB-derived relationships remain available.
- Warnings contain generic diagnostics only; they do not expose the configured
  filesystem path or overlay contents. `BuilderSchemaService::catalog()` keeps
  routing catalog warnings through the existing
  `builder.relationship_catalog` log category.

### Alias-aware canonical filters

- `BuilderSchemaService::getTables($filter)` resolves legacy alias entries via
  the verified mapping before applying the physical-name projection.
- Physical canonical names and local names keep their existing behavior;
  unknown filter entries are omitted.

## TDD Evidence

The initial tests failed for the intended missing behaviors:

- missing overlay did not create a warning;
- a legacy `inventory_items` filter did not return `inventory.item__t`;
- child-projected graph alternatives displayed `id -> id`;
- an associative object in place of the overlay relationship list was accepted.

After the minimal production changes, all focused tests passed.

## Verification

- Focused backend catalog/schema tests: passed.
- Focused frontend relationship/graph/JoinPanel tests: 3 files, 30 tests passed.
- Complete backend standalone suite: all 89 test files exited 0; the PostgreSQL
  integration test reported its explicit missing-environment skip. Existing PHP
  8.5 reflection deprecations and ReferenceResolver test-double warnings remain
  non-fatal.
- Complete frontend suite: 24 files, 133 tests passed.
- Production frontend build passed (`tsc -b && vite build`, 2,503 modules); only
  the existing large-chunk advisory was printed.
- PHP syntax passed for every changed PHP production and test file.
- `git diff --check` passed.
- The test-mutated generated mapping cache was restored byte-for-byte and the
  temporary backend vendor symlink was removed.
