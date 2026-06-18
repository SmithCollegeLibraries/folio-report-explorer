# Canonical Query Graph Artifact

## Purpose
`backend/data/canonical_query_graph.json` is the inspectable, versioned schema graph for upcoming NL2SQL family-contract work.

The current slice covers the inventory contributor + campus + holdings + item + barcode path only. It now includes schema-backed edges, explicit override edges for high-risk joins, and a small number of medium-confidence inferred candidates for inspection.

## Generate
Run from `backend/`:

```bash
php yii canonical-query-graph/generate
```

## Top-Level Shape
- `metadata`: artifact version, generation timestamp, focus-slice label, and source counts.
- `contractKeyToSqlTable`: canonical contract key -> SQL table lookup.
- `sqlTableToContractKey`: SQL table -> canonical contract key lookup.
- `entities`: keyed by canonical contract key.
- `edges`: directed joinable edges from child/local table to parent/referenced table.

## Entity Shape
Each entity includes:
- `contractKey`: runtime-safe canonical key.
- `sqlTable`: schema-qualified SQL table name.
- `ldp1Table`: LDP1/builder table name when one exists.
- `entityKind`: one of `base`, `subtable`, `lookup`, `bridge`, `local`.
- `grain`: coarse row-grain label for the entity.
- `canonicalLabel`: inspection-friendly display label.
- `columns`: ordered `name` / `type` list.
- `semanticHints`: semantic-context description, vocabulary terms, preferred approach hints, and column-level semantic details when available.
- `parentContractKey`: present for subtables.

## Edge Shape
Each edge includes:
- `key`: deterministic edge identifier.
- `from`: child/local contract key.
- `to`: parent/referenced contract key.
- `localColumn`: child/local join column.
- `targetColumn`: parent/referenced join column.
- `edgeKind`: `foreign_key`, `subtable_parent`, `explicit_override`, or `inferred_convention`.
- `joinCardinality`: directional relationship for the emitted edge.
- `semanticRole`: inspection-friendly semantic purpose for the join.
- `confidence`: `high`, `medium`, or `low`.
- `supportsDeterministicCompilation`: `true` only for high-confidence edges.
- `source`: `folio_schema`, `subtable_cache`, `override_map`, or `naming_convention`.
- `typeCompatibility`: `exact`, `assumed_compatible`, `cast_required`, or `unknown`.
- `localType` / `targetType`: artifact-visible column types used for inspection.
- `castRule`: included when the join requires an explicit cast.
- `foreignKey`: included for schema-backed foreign keys when available.
- `inferenceBasis`: included for inferred edges.

## Initial Focus Entities
- `inventory_instances`
- `inventory_instance__t__contributors`
- `inventory_contributor_name_types`
- `inventory_holdings`
- `inventory_holdings_record__t__holdings_items`
- `inventory_items`
- `inventory_locations`
- `inventory_libraries`
- `inventory_campuses`

## Notes
- The artifact prefers one canonical contract key and one canonical SQL table per included entity.
- The checked-in snapshot is intentionally deterministic so later tickets can diff graph changes directly.
- High-confidence overrides now cover holdings to items, contributor subtable to contributor-name-type, item effective location, location to library, and library to campus.
- Medium-confidence inferred edges are inspection-only and are not yet safe for deterministic compilation.