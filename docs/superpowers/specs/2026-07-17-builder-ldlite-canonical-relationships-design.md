# Query Builder Canonical LDLite Relationships Design

**Date:** 2026-07-17  
**Status:** Approved design  
**Scope:** Query Builder schema identity, relationship catalog, and direct alternate-join selection

## Summary

Query Builder currently mixes two table identities. Its table browser can display physical LDLite names such as `inventory.item__t`, but selected-table state, relationship discovery, graph nodes, join paths, and parts of the structured query definition still use legacy MetaDB/LDP aliases such as `inventory_items`.

Query Builder will instead use physical LDLite names as its canonical and user-facing contract. MetaDB-derived relationship metadata will remain a discovery input, but every relationship endpoint will be translated to a verified LDLite table and column. A reviewed, version-controlled overlay will supplement relationships that MetaDB metadata does not expose, including alternate direct links such as effective, permanent, and temporary item locations.

When two selected tables have several direct links, the relationship graph will show the active link. Clicking the edge will let the user temporarily select another direct relationship. The graph and Joins tab will stay synchronized. Temporary choices affect Build SQL and Run, but saved queries will always use curated default relationships.

## Problem Statement

LDLite is the database users can query, so its physical names are the names users recognize and can verify. The current mixed model causes several problems:

- Table browsing may show `inventory.item__t` while the selected table, graph node, or join panel shows `inventory_items`.
- Schema and path APIs expose legacy relationship endpoints even though generated SQL later substitutes physical names.
- A single pair of tables can have several meaningful direct joins, but the current graph and automatic path finder silently choose one.
- LDLite does not expose foreign-key metadata, and the existing MetaDB-derived schema omits some valid semantic links.
- A graph edge is not currently an identifiable relationship choice; it is only a visual consequence of static metadata.

## Goals

1. Use physical LDLite table names everywhere within Query Builder.
2. Keep legacy names available only as compatibility aliases and search terms.
3. Produce a canonical, validated relationship catalog whose endpoints are physical LDLite tables and columns.
4. Supplement MetaDB-derived relationships with reviewed direct relationships.
5. Choose one deterministic default relationship for every directly related table pair.
6. Let users select another direct relationship by clicking the graph edge.
7. Keep the relationship graph and Joins tab synchronized.
8. Preserve graph node positions when a relationship is changed.
9. Apply temporary relationship choices to the current Build SQL and Run operations.
10. Save queries using curated defaults, never temporary relationship choices.
11. Preserve existing schema API behavior for non-Builder consumers during rollout.

## Non-Goals

- Discovering arbitrary relationships from every column ending in `_id`.
- Letting users define unrestricted ad hoc column-to-column joins.
- Selecting alternate multi-hop routes through intermediate tables in this phase.
- Persisting alternate relationship choices in saved queries.
- Replacing MetaDB metadata as a relationship discovery input.
- Changing local supplementary table names.
- Changing existing INNER versus LEFT JOIN behavior except where necessary to synchronize the active relationship.
- Migrating or rewriting existing saved-query records in place.

## Terminology

- **Physical LDLite name:** The schema-qualified table users can query, such as `inventory.item__t`.
- **Legacy alias:** An existing LDP/MetaDB-derived application key, such as `inventory_items`.
- **Relationship:** One directed physical column link, such as `inventory.item__t.effective_location_id -> inventory.location__t.id`.
- **Table pair:** The two physical tables connected by one or more relationships, independent of traversal direction.
- **Default relationship:** The deterministic relationship used automatically for a table pair.
- **Alternate relationship:** Another reviewed direct relationship for the same table pair.
- **Active relationship:** The default or temporary alternate currently represented in Builder.
- **Relationship overlay:** A reviewed artifact that supplements or annotates generated relationship metadata.

## Current Architecture

`FolioSchemaService` loads a legacy static schema and discovers mappings to physical database tables. The schema response remains keyed by the legacy name and attaches the physical name as `sql_name`. Query Builder stores those keys in `selectedTables` and all related field references. `SqlBuilderService` translates legacy names only when emitting `FROM` and `JOIN` clauses.

Relationship discovery uses the static legacy relationship graph. For example, the catalog includes the item effective-location relationship, while the item table also contains `permanent_location_id` and `temporary_location_id` without equivalent relationship entries.

This is display and SQL-boundary translation, not a canonical LDLite model.

## Proposed Architecture

### 1. Query Builder-specific canonical schema view

The existing schema endpoints will accept an explicit identity mode:

```text
GET /api/schema?identity=ldlite
GET /api/schema/inventory.item__t?identity=ldlite
GET /api/path?from=inventory.item__t&to=inventory.location__t&identity=ldlite
```

Requests without `identity=ldlite` retain the current response contract. This isolates the initial migration to Query Builder and avoids silently changing Explorer or other consumers.

In the canonical view:

- The table map is keyed by the physical LDLite name.
- `name` is the physical LDLite name.
- `sql_name` equals `name` and may be retained temporarily for response-shape compatibility.
- `alias_name` contains the legacy alias when one exists.
- Relationship parent and child endpoints use physical LDLite names.
- Only tables verified in the current LDLite mapping are exposed, apart from supported local tables.
- Local supplementary tables retain their existing names and identity.

Query Builder will request this view explicitly and will use physical names in:

- selected tables;
- selected columns;
- filters;
- grouping and sorting;
- graph node IDs;
- path requests;
- join configuration;
- build requests; and
- newly saved query definitions.

### 2. Canonical relationship catalog

A dedicated catalog builder will create relationships in this order:

1. Load existing MetaDB-derived relationship metadata.
2. Resolve every table endpoint through the verified LDLite mapping.
3. Reject generated entries whose physical table or column cannot be verified.
4. Load the reviewed relationship overlay.
5. Validate overlay table and column endpoints against the physical schema snapshot.
6. Merge and deduplicate relationships by physical endpoints.
7. Apply curated default preferences.
8. Assign deterministic defaults where no explicit preference exists.
9. Produce stable relationship IDs and pair IDs.

The catalog is a multigraph: the same table pair may have several direct edges. Relationship traversal can be bidirectional, but the stored source and target preserve the actual foreign-key semantics.

### 3. Stable identity

Each relationship receives a stable ID derived from its complete physical endpoints:

```text
inventory.item__t.effective_location_id->inventory.location__t.id
```

Each table pair receives a direction-independent pair ID derived from the sorted physical table names. The pair ID groups alternatives; the relationship ID identifies the active column link.

Graph edges, path responses, Builder override state, and build requests use these identifiers. Client-provided IDs are always validated and expanded from the server-side catalog before SQL is generated.

## Relationship Overlay

### Artifact

The supplemental relationships will live at `backend/data/builder_relationship_overrides.json` as a version-controlled artifact rather than a generated cache. A representative shape is:

```json
{
  "version": 1,
  "relationships": [
    {
      "fromTable": "inventory.item__t",
      "fromColumn": "effective_location_id",
      "toTable": "inventory.location__t",
      "toColumn": "id",
      "label": "Effective location",
      "default": true
    },
    {
      "fromTable": "inventory.item__t",
      "fromColumn": "permanent_location_id",
      "toTable": "inventory.location__t",
      "toColumn": "id",
      "label": "Permanent location",
      "default": false
    },
    {
      "fromTable": "inventory.item__t",
      "fromColumn": "temporary_location_id",
      "toTable": "inventory.location__t",
      "toColumn": "id",
      "label": "Temporary location",
      "default": false
    }
  ]
}
```

The initial overlay will cover reviewed Inventory location relationships. Additional domains can be added through normal code review without changing the catalog implementation.

### Validation

Catalog validation will assert that:

- both physical tables exist;
- both physical columns exist;
- a relationship is a direct table-to-table link;
- duplicate endpoint definitions collapse to one relationship;
- overlay annotations can enrich a generated relationship without duplicating it;
- every table pair has exactly one resolved default; and
- stable IDs are unique.

An invalid entry is omitted and logged with its relationship ID and reason. One invalid overlay entry must not disable the schema or unrelated relationships.

If multiple entries for one pair claim to be default, the catalog logs the configuration error and selects the lexicographically first stable relationship ID. This preserves availability while making the defect visible. If no entry claims to be default, the catalog uses stable relationship-ID ordering.

### Curated precedence

An explicit reviewed default wins over generated ordering. For the initial item-to-location pair, `effective_location_id` is the default, followed by permanent and temporary as alternatives.

## API Contract

### Canonical table summary

```json
{
  "name": "inventory.item__t",
  "sql_name": "inventory.item__t",
  "alias_name": "inventory_items",
  "domain": "inventory"
}
```

### Canonical relationship

```json
{
  "relationship_id": "inventory.item__t.effective_location_id->inventory.location__t.id",
  "pair_id": "inventory.item__t<->inventory.location__t",
  "parent_table": "inventory.location__t",
  "parent_column": "id",
  "local_column": "effective_location_id",
  "label": "Effective location",
  "is_default": true,
  "source": "overlay"
}
```

All validated direct relationships appear in table detail responses. The client groups them by `pair_id` and selects the entry marked `is_default` unless a valid session override exists.

### Path response

Default path discovery returns the default relationship for each traversed pair. `all=1` may return direct variants, with the default first, but alternate multi-hop route selection remains outside this feature.

### Build request

Default Builder behavior continues to send automatic joins. When a temporary alternate is active, Builder sends an explicit relationship selection containing the stable relationship ID. The server resolves the relationship from the trusted catalog and ignores untrusted endpoint substitutions.

Legacy explicit join objects remain accepted for existing saved queries after table and column normalization and normal validation.

## Frontend State

Builder will separate deterministic defaults from temporary user intent:

```text
defaultRelationships: Map<pairId, relationshipId>
activeRelationshipOverrides: Map<pairId, relationshipId>
```

The active relationship for a pair is its override when valid, otherwise its default.

An override is cleared when:

- either endpoint table is removed;
- the user chooses Reset to auto;
- the Builder page is reloaded or reopened; or
- schema refresh invalidates the chosen relationship.

Overrides are not placed in newly saved query definitions.

## Relationship Graph Interaction

### Edge display

The graph draws one active edge for each selected table pair. The edge label shows the active source and target columns. When alternatives exist, the edge also shows an indicator such as `3 links`.

Ghost edges may show that multiple links exist, but alternate selection is enabled only after both endpoint tables are selected. Clicking a ghost node retains its existing add-table behavior.

### Edge selector

Clicking an edge between selected tables opens a keyboard-accessible selector containing every validated direct relationship for the pair. Each option shows:

- a human label;
- source column;
- target column;
- a Default badge when applicable; and
- an Active indicator for the current choice.

Example:

```text
Effective location   effective_location_id -> id   Default
Permanent location   permanent_location_id -> id
Temporary location   temporary_location_id -> id
```

Selecting an option:

1. records or clears the session override;
2. updates the graph edge label and active indicator;
3. synchronizes the Joins tab;
4. invalidates generated SQL; and
5. leaves graph node positions and viewport unchanged.

Relationship choice is a presentation-data change, not a topology change. It must not trigger ELK layout, fit-to-view, or loss of a user-arranged graph.

## Joins Tab

The Joins tab shows the same active relationship as the graph. A relationship selector is available for pairs with alternatives, but the graph edge remains the primary interaction requested for this feature.

Relationship selection and join type are separate concerns:

- relationship selection chooses the column endpoints;
- join type chooses `JOIN` or `LEFT JOIN`.

Changing either control moves Builder into a customized current-session state. Reset to auto restores default relationships and the existing automatic join-type behavior.

## Build, Run, and Save Semantics

### Build and Run

Build SQL and Run use the active session relationships. If the user selects permanent location, the generated SQL must join:

```sql
inventory.item__t.permanent_location_id = inventory.location__t.id
```

Changing an active relationship invalidates any previously generated SQL so stale SQL cannot be run accidentally.

### Save

Saved queries always use curated default relationships.

If a user opens Save while temporary relationship overrides are active:

1. the dialog explains that saved queries use default links;
2. the client requests a fresh default build from the server;
3. the saved query definition uses automatic/default relationships;
4. the saved generated SQL comes from that default build; and
5. the current unsaved Builder session retains its temporary choices after saving.

Only temporary relationship-column choices are discarded for saving. Existing supported save behavior for other query properties remains unchanged.

## Compatibility and Migration

### Existing consumers

Schema requests without `identity=ldlite` remain unchanged. Explorer and other consumers can migrate separately after the Builder rollout is proven.

### Existing saved queries

Existing query definitions may contain legacy table names. A normalization boundary translates legacy aliases across:

- tables;
- selected columns;
- filters;
- grouping;
- sorting; and
- explicit joins.

Normalized references are then validated against the canonical catalog and physical column metadata. Existing records are not rewritten automatically.

### New saved queries

New Builder saves use physical LDLite names and default relationships.

### Mapping failures

A legacy static table without a verified physical LDLite mapping is not included in the canonical Builder view. The service logs the omitted table and mapping reason. Query Builder must never present an inaccessible MetaDB-only name as though it were queryable.

## Error Handling

- Invalid overlay entries are isolated and logged.
- A missing physical mapping omits the affected table or relationship from the canonical view.
- A stale or unknown relationship ID in a build request returns a validation error; it is never converted into raw SQL.
- If an active override disappears after schema refresh, Builder falls back to the default, invalidates generated SQL, and displays a non-blocking notice.
- If no valid default relationship remains for a selected pair, automatic build fails with a clear join-path error rather than guessing.
- Failed canonical schema requests retain the existing Query Builder error boundary and retry behavior.

## Security and Trust Boundary

The browser never supplies executable SQL fragments for relationship selection. It supplies a stable relationship ID. The server resolves that ID from the validated catalog and emits identifiers through the existing SQL builder and policy checks.

The overlay is application-owned configuration, reviewed and deployed with code. It is not writable through the user interface.

## Testing Strategy

### Backend unit tests

- Translate legacy relationship endpoints to physical LDLite names.
- Merge MetaDB-derived relationships and overlay entries.
- Deduplicate identical physical endpoints.
- Validate missing tables and columns.
- Resolve exactly one default per pair.
- Apply curated default precedence.
- Produce stable relationship and pair IDs.
- Normalize legacy query definitions.
- Reject unknown relationship IDs.
- Generate exact SQL for effective, permanent, and temporary location joins.
- Rebuild saved SQL using defaults when a temporary override was active.

### Backend API tests

- Existing `/api/schema` response remains unchanged without the identity parameter.
- `identity=ldlite` returns a table map keyed by physical names.
- Canonical table detail returns physical relationship endpoints and alternatives.
- Canonical path discovery returns defaults first.
- Legacy aliases remain accepted as inputs.
- Local supplementary tables remain available under their existing names.

### Frontend component tests

- Query Builder state uses physical table names.
- Physical names appear consistently in Browse, selected tables, graph, relationships, columns, filters, joins, and generated definitions.
- Clicking a multi-link edge opens the relationship selector.
- Selecting an alternate updates the edge and Joins tab.
- Relinking does not move nodes, run ELK, or fit the viewport.
- A user-arranged graph remains user-arranged after relinking.
- Removing a table clears affected overrides.
- Reset to auto restores defaults.
- Schema refresh invalidation falls back with a notice.
- Save requests and stored SQL use defaults while the current session keeps its override.

### Regression tests

- Existing graph layout, drag preservation, re-layout, reduced-motion, and stale-async tests remain green.
- Existing Builder column, filter, sort, build, run, and save flows remain green.
- Existing Explorer/schema consumers remain unchanged.
- Full backend and frontend test suites pass.

### Manual verification

1. Select `inventory.item__t` and `inventory.location__t`.
2. Confirm the graph shows the effective-location default and a `3 links` indicator.
3. Click the edge and choose Permanent location.
4. Confirm graph nodes do not move.
5. Confirm the Joins tab shows `permanent_location_id -> id`.
6. Build SQL and confirm the permanent-location join.
7. Reset to auto and confirm effective location is restored.
8. Choose Temporary location, save the query, and confirm saved SQL uses effective location.
9. Reload Builder and confirm defaults are active.
10. Open Explorer and confirm its existing schema behavior is unchanged.

## Rollout

1. Add and validate the relationship overlay and canonical catalog behind the existing schema service.
2. Add the opt-in canonical API view and compatibility normalization.
3. Migrate Query Builder state and requests to physical LDLite names.
4. Add graph edge selection and synchronize the Joins tab.
5. Add default-only save behavior and user-facing disclosure.
6. Run automated and manual regression gates.
7. Consider migrating other schema consumers only in a separate approved change.

No database migration is required.

## Acceptance Criteria

- Query Builder shows physical LDLite table names everywhere.
- Query Builder sends and receives physical LDLite identifiers throughout its active workflow.
- Legacy aliases remain usable for compatibility but are not primary Builder identities.
- The canonical catalog contains validated effective, permanent, and temporary item-location relationships.
- Effective location is the deterministic default.
- Clicking a selected-table graph edge exposes direct alternatives.
- Selecting an alternate updates graph, Joins tab, Build SQL, and Run consistently.
- Relinking never rearranges graph nodes automatically.
- Reset to auto restores curated defaults.
- Saved definitions and generated SQL always use default relationships.
- Existing non-Builder schema consumers remain unchanged.
- Invalid mappings and overlay entries fail safely and visibly.
- Automated and manual regression gates pass.
