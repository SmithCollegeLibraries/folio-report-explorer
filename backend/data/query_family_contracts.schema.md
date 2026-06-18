# Query Family Contracts Artifact

## Purpose
`backend/data/query_family_contracts.json` defines deterministic query-family contracts on top of the canonical query graph.

The first slice covers the `contributor + campus + item/barcode` inventory family only. Contracts are inspection-friendly and selection is deterministic: unsupported families fail contract selection instead of silently widening scope.

## Top-Level Shape
- `metadata`: artifact version, generation timestamp, source graph artifact version, and contract count.
- `contracts`: keyed by family contract key.

## Contract Shape
Each contract includes:
- `familyKey`: stable contract key.
- `description`: compact operator-facing summary.
- `graph.requiredEntities`: canonical graph entities required for the family.
- `graph.canonicalPath`: ordered entity path the family expects compilation to follow.
- `graph.scopeAnchor`: entity that defines the family’s scoping boundary.
- `slots.required`: slots that must be present for the family to match.
- `slots.supported`: slots the family accepts.
- `outputs.allowed`: output fields the family allows.
- `outputs.holdingsScoped`: outputs that must remain scoped through qualifying holdings.
- `scopeRule`: stable scope rule identifier.
- `matchPolicy`: default and supported match policies.
- `operatorPolicy`: contract-level rules for joins and unsupported behavior.

## Selection Rules
- All `graph.requiredEntities` must be available.
- All requested slots must be in `slots.supported`.
- All `slots.required` must be present.
- All requested outputs must be in `outputs.allowed`.
- If no contract matches, selection returns `unsupported_family`.

## Initial Contract
- `inventory_contributor_campus_item_barcode`

This contract requires campus and contributor-name filters and preserves the rule that item/barcode outputs must flow through qualifying holdings rather than all instance items.