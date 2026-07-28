# Reference-First Library and Material-Type Resolution Design

**Date:** 2026-07-27
**Status:** Conversation design approved; written specification pending review

## Purpose

Ask AI must resolve stable FOLIO reference values before generating SQL. When a user asks for materials at a specific library, the library phrase must resolve against `inventory.loclibrary__t`. When the user names formats or material types, that vocabulary must resolve against `inventory.material_type__t`.

The JSON reference bundle remains the canonical source of names, identifiers, codes, and hierarchy metadata. A vocabulary layer may translate ordinary language into candidate reference rows, but it must not duplicate or replace canonical reference values.

This design fixes a demonstrated failure:

> Find all of the video formats at Hillyer library. This can be VHS or DVD. Review the material types table for accurate material types.

The current resolver incorrectly treats `DVD` as the campus-prefix-free name of the Hampshire location `HC DVD`, fails to resolve `Hillyer library`, and sends the wrong location constraint to SQL generation. The intended resolution is:

- library: `inventory.loclibrary__t.name = 'SC Hillyer Art Library'`
- material types: `inventory.material_type__t.name IN ('Videocassette', 'DVD/Blu-ray')`
- no location-name filter

## Product Rules

1. Reference resolution happens before query-family selection or exploratory SQL generation.
2. The reference bundle is authoritative. Generated SQL never invents a canonical library, location, campus, or material-type name.
3. User phrasing determines the reference dimension before matching begins.
4. A phrase identified as library scope searches `inventory.loclibrary__t`, not `inventory.location__t`.
5. A phrase identified as material or format vocabulary searches `inventory.material_type__t`, not the location hierarchy.
6. Location records remain available for explicit location, collection, stacks, room, shelving, or exact-location language.
7. An ordinary word inside a canonical location name does not make that location a global synonym.
8. Explicit format terms narrow a default group.
9. Generic `video` and `video formats` default to physical video formats without asking a clarification question.
10. Conflicting institutional scope fails closed before query execution.

## Selected Approach

Use a deterministic, context-aware resolver with three stages:

1. Extract typed reference intents from the raw user prompt.
2. Match each intent only within its authoritative cached table.
3. Preserve the resulting structured filters through generation and candidate validation.

This approach is preferred over:

- **Guard-and-alias patching:** inexpensive initially, but it leaves global cross-table matching intact and will repeat the same defect for other generic words.
- **AI terminology classification:** flexible, but nondeterministic and inappropriate for stable lookup values that already exist locally.

## Architecture

### Typed reference intent

A focused interpreter identifies reference-bearing prompt spans and assigns a dimension:

- `Hillyer library` → `library`
- `VHS`, `DVD`, `material types`, `formats`, `video formats` → `material_type`
- `HC DVD location`, `SC Art Video location`, `locked stacks collection` → `location`
- `Smith College campus` → `campus`

Typed spans are non-overlapping. A token consumed as part of an exact location phrase is not independently reinterpreted as material vocabulary. For example, `DVD` in `location HC DVD` remains part of the location name.

The interpreter produces a small internal structure containing:

- dimension;
- original prompt span;
- normalized terms;
- whether the term is explicit or comes from a documented default; and
- any enclosing context such as `library`, `location`, `material type`, or `format`.

### Table-scoped matching

Each typed intent is matched only against the appropriate table in `backend/data/reference_cache.json`:

| Intent dimension | Authoritative table |
|---|---|
| library | `inventory.loclibrary__t` |
| location | `inventory.location__t` |
| campus | `inventory.loccampus__t` |
| institution | `inventory.locinstitution__t` |
| service point | `inventory.service_point__t` |
| material type | `inventory.material_type__t` |

Library matching may omit a campus prefix and nonessential descriptive words when a distinctive named-library token is present. Thus `Hillyer library` resolves to `SC Hillyer Art Library`. It does not search locations whose metadata happens to name that library.

Campus-prefix-free location matching remains available only when the prompt supplies location context or an exact multi-token location phrase. A one-word remainder such as `dvd`, `reference`, `archives`, `display`, `stacks`, or `reserves` cannot activate a location record globally.

### Material vocabulary

Material vocabulary maps user language to canonical rows that must exist in the cached `inventory.material_type__t` table.

Initial mappings:

| User language | Canonical cached row |
|---|---|
| VHS, VHS tape, VHS tapes | `Videocassette` |
| DVD, DVDs, Blu-ray, Blu-rays, DVD/Blu-ray | `DVD/Blu-ray` |
| film, films | `Film` |

The approved `physical_video` group contains:

- `Videocassette`
- `DVD/Blu-ray`
- `Film`

Generic `video`, `videos`, `video material`, and `video formats` select `physical_video` without clarification. Explicit terms narrow the selection. Therefore `video formats ... VHS or DVD` selects only `Videocassette` and `DVD/Blu-ray`, while `video materials at Hillyer` selects all three physical-video rows.

Vocabulary entries are selectors, not reference data. At runtime each selector must resolve to a current cached row. No hard-coded UUID, SQL literal, or duplicate authoritative row is accepted as a substitute.

### Structured resolved filters

Resolution produces structured constraints in addition to model guidance. Conceptually:

```json
{
  "library": {
    "sourceTable": "inventory.loclibrary__t",
    "column": "name",
    "values": ["SC Hillyer Art Library"],
    "provenance": "explicit_prompt"
  },
  "materialType": {
    "sourceTable": "inventory.material_type__t",
    "column": "name",
    "values": ["Videocassette", "DVD/Blu-ray"],
    "provenance": "explicit_prompt",
    "vocabularyTerms": ["VHS", "DVD"]
  }
}
```

Guidance text for Gemini is rendered from this structure. Query generation does not independently reinterpret the terms after they have been resolved.

The same structure is retained in trusted server-side evidence and provenance so administrators can see which prompt term selected which cached reference row.

## Request Data Flow

1. Load the JSON reference bundle and its metadata.
2. Interpret the raw prompt into typed reference intents.
3. Resolve each intent inside its authoritative cached table.
4. Apply documented vocabulary defaults and explicit narrowing.
5. Check hierarchy metadata for contradictions.
6. Return clarification or an unavailable-reference outcome when required.
7. Render model guidance from the structured resolved filters.
8. Run query-family or exploratory generation.
9. Validate the candidate SQL against every structured resolved filter.
10. Continue through existing safety, policy, schema, semantic, and PostgreSQL preflight gates.

The raw user prompt remains separate from generated guidance. User recovery context must never be derived from augmented model prompts.

## Candidate Validation

Candidate validation is fail-closed and applies to deterministic and exploratory candidates.

For the demonstrated prompt, a valid candidate must:

- join or otherwise bind `inventory.loclibrary__t`;
- apply a positive library predicate for `SC Hillyer Art Library`;
- bind item material type through `inventory.material_type__t`;
- positively include both `Videocassette` and `DVD/Blu-ray`;
- introduce no unrelated location, library, or campus filter; and
- preserve the existing safety and semantic requirements.

A candidate is invalid when it:

- filters `inventory.location__t.name = 'HC DVD'`;
- omits either explicitly resolved material type;
- applies a material-type value to a location or library column;
- applies a library value to a location column;
- combines Hillyer/Smith scope with Hampshire metadata;
- changes a canonical reference name; or
- adds another material type after explicit vocabulary narrowed the group.

Repairable omissions enter the existing bounded repair coordinator. Policy violations, destructive SQL, conflicting institutional scope, and exhausted repair remain no-result outcomes.

## Error and Ambiguity Behavior

- Generic `video` uses `physical_video` without clarification.
- Explicit material terms narrow the group.
- A unique named library resolves directly.
- Multiple legitimate same-dimension library matches require domain-language clarification.
- A known vocabulary selector whose canonical row is absent from the current bundle produces an unavailable-reference outcome. The system does not substitute a similar row.
- An unknown explicit format is searched only in `inventory.material_type__t`. If no responsible match exists, Ask AI requests a domain-language clarification.
- Cross-campus or wrong-hierarchy contradictions fail before execution and are eligible for administrator review.
- Ordinary responses contain no schema names, table names, validator categories, or internal reference keys.

## Compatibility

This change must preserve:

- exact location resolution when the prompt explicitly names a location;
- Josten, Neilson, Hillyer, collection, stack, room, and location-code behavior;
- raw-prompt query-family routing;
- the existing two-repair maximum;
- JSON-first reference loading and MySQL fallback;
- learned reference aliases and accepted clarification behavior;
- PHP 7.2 compatibility; and
- all existing safety, policy, semantic, and database-preflight gates.

The design does not modify generated cache files. Material vocabulary selectors live in application-owned code or a small application-owned configuration artifact and resolve through the generated cache at runtime.

## Test Design

### Exact regression

For the reported prompt, assert:

- resolution does not request clarification;
- the resolved library is `SC Hillyer Art Library`;
- resolved material types are exactly `Videocassette` and `DVD/Blu-ray`, in stable order;
- `HC DVD` is absent from resolved references and guidance;
- the candidate SQL uses the library table and material-type table; and
- a candidate with the observed incorrect location predicate is rejected.

### Vocabulary matrix

Cover:

- `video materials at Hillyer library` → physical-video group;
- `DVDs at Hillyer library` → `DVD/Blu-ray`;
- `VHS at Hillyer library` → `Videocassette`;
- `films at Hillyer library` → `Film`;
- `VHS or DVD` → the two explicit rows only;
- `all materials at Hillyer library` → no material-type filter;
- pluralization and punctuation variants; and
- unknown format behavior.

### Dimension and hierarchy matrix

Cover:

- `Hillyer library` → `inventory.loclibrary__t`;
- `location HC DVD` → the legitimate Hampshire location, without a material-type inference;
- `SC Art Video location` → the legitimate Smith location;
- ambiguous library handling;
- campus-prefix-free named-library matching;
- generic one-word location names not activating without location context;
- cross-campus metadata conflict rejection; and
- wrong hierarchy predicates.

### Regression suites

Run the focused resolver, generated-JSON, guidance, Gemini routing, family-intent, exploratory-repair, semantic-validation, and reference-cache tests. Run the complete backend suite before completion.

## Success Criteria

The work is complete when:

1. The reported prompt deterministically resolves the correct library and material types before generation.
2. No generic format term can silently select an unrelated location.
3. Generic video uses the approved physical-video default without clarification.
4. Explicit formats narrow the default group.
5. Candidate validation preserves all resolved reference constraints.
6. Legitimate exact-location requests remain supported.
7. Existing resolver and query-generation tests pass.
8. Generated caches and unrelated user work remain untouched.
